# Why the 2026-09-06 ralph run was slow: the #33 implementer's timeout churn

**Verdict:** the run was not slow end to end. The iteration-2 implementer (issue #33) took
**3 h 25 min**, and roughly **2 h of that was the disposable test stack stalling
mid-suite**, each failed attempt costing the harness's 5-minute timeout before returning
data the agent could act on. Once the stack stabilized, the identical pipeline handled #32
(implement → review → merge → close) in **~55 min**. The bottleneck was stack
availability inside the sandbox, not the model, the sandbox itself, or the issue.

**Corrected 2026-09-06, post-run troubleshooting.** An earlier revision called the stall
"a genuine infinite loop in `users.php`" with "root cause unattributed." That was wrong,
on two counts. The sustained stall was a **spawnSync pipe-buffer deadlock** (root cause
below), and it *is* attributed — in the `535a0e7` commit message, which landed at 23:22
UTC after the transcript snapshot was cut. The final surviving snapshot event (405,
22:56:11) already contradicted the spin theory: during one hang every worker was
SLEEPING, not burning CPU.

Times below are local (UTC−3); container/session evidence is UTC.

## Timeline

| Time | Event |
|---|---|
| 15:59 | `ralph main.mts` starts. Iteration 1 plans #31 + #34. |
| ~16:58 | Iteration 1 complete: both merged (`8883b7b`). |
| 16:59 | Iteration 2 plans **#33 only**; implementer starts on `ralph/issue-33`. |
| 16:59 → 20:24 | Implementer #33 runs **3 h 25 min**, still on pi iteration 1/100 at the end. ~2 h of it is the timeout churn documented below. |
| 20:24 → 20:28 | Reviewer #33 (+ refinement commit `dc2d813`). |
| ~20:28 → 21:0x | Merger merges `ralph/issue-33`, closes #33. |
| ~21:0x → 21:12 | Iteration 3: planner picks **#32**; implementer finishes in ~25 min. |
| 21:19 / 21:21 | Reviewer #32; merger merges and closes #32 (`433c6a3`). |
| 21:22 | Final planner returns `{"issues": []}` → run complete. |

## Anatomy of the wait

The disposable WordPress stack behind `npm test` never became healthy, repeatedly, for
two stacked reasons:

1. **Self-inflicted contention (early only).** The implementer ran overlapping `npm test`
   sessions; stray `php -S 127.0.0.1:8091` debug servers from earlier runs kept the port,
   so later stacks could not bind (snapshot events ~331, 21:59 UTC: the harness's own
   server started but a foreign server already owned the port and served earlier
   requests). At 22:10 the agent disproved this as the sustained cause: *"Still hanging
   at users.php even with a clean server per run. So it's NOT stale servers."*
2. **The real cause: spawnSync pipe-buffer deadlock.** The pre-`535a0e7` harness spawned
   php -S with `stdio: ['ignore','pipe','pipe']` and collected the access log through
   async `'data'` handlers. `spawnSync` then blocks node's event loop for the entire
   race/matrix scenario — nobody drains the pipes, the 64 KB kernel pipe buffer fills,
   every php -S worker blocks on its access-log write, and the whole stack stalls with
   the in-flight request (always `users.php`, the matrix's last probe) hung until the
   client timeout and then the 300 s spawnSync budget fire. This explains every
   observation the earlier revision misread: workers SLEEPING during a hang (blocked on
   the pipe, not looping); worker 3384's "16 s CPU" was cumulative access-log duty from
   ~90 earlier requests, not a spin; manual repros never hung because the agent's manual
   server logged to `/tmp/stack.log`, a file that never fills. Root cause and fix are
   recorded in the `535a0e7` commit message: *"php -S server log now goes to a file
   (piped logs stalled the suite when node blocked in spawnSync and the 64KB pipe buffer
   filled)"* — implemented as `stdio: ['ignore', serverLogFd, serverLogFd]`
   (`woo-stack-harness.mjs:142`), with the reasoning in the harness comment at lines
   135–141.

Each failed attempt was expensive because of the harness's wait budgets
(`wordpress/scripts/woo-stack-harness.mjs`, current worktree): every health/guard fetch
aborts at **60 s** (`AbortSignal.timeout(60_000)`, line 42) and each scenario runs under a
**300 s** `spawnSync` budget (lines 157, 263, 281; recheck 120 s at 284). Inter-event gaps
in the session cluster at exactly ~5 min — matching the 300 s budgets.
`max_execution_time=10` was a red herring: the stalled workers were blocked in a kernel
pipe write, which it does not interrupt.

## Snapshot numbers (session copy ends 22:56:11 UTC; run continued to 23:24)

From the pi session transcript (`evidence/ralph-issue-33-implementer-session-snapshot.jsonl`,
406 events, span 177 min):

- **130 of 177 min** sit in gaps ≥ 1 min between events.
- **16 gaps ≥ 4.5 min** — one per failed stack attempt.
- **10 `TimeoutError`s** in the transcript; **46 tool calls** touched `npm test`/the harness.
- Zombie `php` processes (defunct, unreaped) accumulated from the overlapping runs; the
  container's process table held 6+ by 22:36 UTC.

## Resolution and leftover costs

The implementer converged by instrumenting the disposable server (trace prepend
`fpw-trace-prepend.php`, `max_execution_time=10`, per-request logging in `router.php`),
ruling out its own first two hypotheses (stray servers, then a CPU loop) from live
process polls, and landing the real fix (server log → file) plus three deterministic
stack fixes in `535a0e7` (coming-soon pinned off, the pipe fix, port-freed assertion,
and the router docroot fix). Final verification ran the full suite **twice
cleanly** (256 local + 47 real-stack + 133 checks) before signaling COMPLETE. Two costs
outlived the episode:

- **Diagnosis scaffolding leaked into the deliverable.** A TEMP `request.log` line added
  to `scripts/router.php` during debugging shipped in the implementer's commit; review
  caught and removed it (`dc2d813`: *"nothing reads it and it grows unboundedly per
  request"*). One avoidable review round-trip.
- **Root cause: attributed and fixed** (this used to say "unattributed"). The
  `535a0e7` commit message names the pipe-buffer deadlock and the fix; the surviving
  snapshot's last event (405, 22:56:11) independently rules out a CPU loop. If a stall
  ever recurs, do what that event planned next: poll `ss -tnp` and `/proc/<worker>/wchan`
  *during* the hang — a worker parked in `pipe_write` means the same deadlock; a file-backed
  server log now makes that impossible for this harness.

## Evidence

- `.ralph/logs/ralph-issue-33-implementer.log` — agent-visible narrative incl. the
  contention admissions and the interim infinite-loop hypothesis (later disproven).
- `evidence/ralph-issue-33-implementer-session-snapshot.jsonl` — full pi session (406
  events) to 22:56:11 UTC. **Snapshot only:** the sandbox container
  `ralph-21634328-…` was deleted at run end; the last 28 min of session live only in this
  file's absence. Extract with:
  `docker cp <container>:/home/agent/.pi/agent/sessions/--home-agent-workspace--/<session>.jsonl /tmp/…`
  **while the container exists.**
- Commits: `535a0e7` (implement), `dc2d813` (review refinements), `ceb43ef` (merge).
- Harness budgets: `wordpress/scripts/woo-stack-harness.mjs:42,157,263,281,284`.
- **Root-cause record:** the `535a0e7` commit message ("Deterministic stack fixes found
  by the matrix" bullet) and the pipe-deadlock comment in `woo-stack-harness.mjs:135–141`.

## If a run is slow again

Check, in order, each until answered:

1. **Is the agent waiting or thinking?** `docker exec <container> sh -c "ps aux | sort -k3 -rn | head"` — a slow model thinks quietly; a stalled stack shows in the process table. Distinguish the two failure shapes: workers **burning CPU** = compute loop in the request; workers **sleeping** while requests time out = blocked on I/O (this run's pipe deadlock had exactly that shape). Done when you can name the top consumer *and* its state.
2. **How much of the run is timeout churn?** `grep -c "Agent idle" .ralph/logs/<agent>.log` and count `TimeoutError` in the session transcript. Done when wait minutes ≈ known timeout budgets × failure count.
3. **What is the agent's own diagnosis?** `tail -f .ralph/logs/<agent>.log` — this implementer named both the contention and the loop in its stream. Done when you have its last stated hypothesis.

Structural fixes (updated — the first is no longer open):

- **Port preflight in the harness — APPLIED in `535a0e7`.** Lines 65–68 abort immediately
  if a foreign server answers on the port, and the detached spawn (line 139) plus
  process-group kill with a port-freed assertion (lines ~119–121) prevents a zombie
  master from poisoning later runs. A 5-min contention failure is now a 5-s one.
- **Shorter scenario budget — still open.** 300 s → ~60 s for the race/guarded/off
  scenarios; a healthy stack answers in seconds, so the budget only pays out on hangs
  (and the deadlock that made it pay out is fixed).
- **Implementer prompt note — still open.** Revert diagnosis scaffolding (TEMP log
  lines, trace prepends) before signaling COMPLETE; would have saved the `dc2d813`
  round-trip. Candidate home: `.ralph/implement-prompt.md`.

Host footnote for the next troubleshooter: the *host* also holds an orphan
`python3 -m http.server 8091` (pid 2024798, started Aug 27, ppid 1). It is irrelevant to
the sandboxed harness (the container's stack binds its own netns — proven by the stack
answering 200 while this listener holds host 8091), but kill it before ever running the
harness with host networking.
