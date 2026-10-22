# Lighthouse DEV — isolated collection

Final ticket-5 collection protocol and provenance: [HANDOFF.md](HANDOFF.md). Historical ticket statuses below are retained; current real outcome lives in the private report linked there.

**Synthetic ticket-2 gates passed; not authorized for DEV.** Owner-authorized sanitizer continuation passed 28 offline tests and one complete isolated 21-case matrix, including persisted mobile/desktop report validation. Await lead verification/integration; no active gate receipt exists. Earlier invalid reports/candidates remain invalid and preserved (history below). Ticket 3 adds offline calibration/comparison; ticket 4 adds fixture-only manual cycles below. DEV remains a later ticket. CLI/src changes invalidate the historical instrument gate: ticket 5 must refresh it, never bypass receipts. No application files, root dependencies or shared browser are used.

## Pins

- Node 26.8.2; npm 11.19.1.
- Lighthouse 13.5.0; Puppeteer Core 25.9.0 (explicit CDP client).
- Chromium 152.0.7977.82, existing executable `/usr/lib/chromium/chromium`.
- SHA-256: `27c398e1738a5bf578e4414654b959b8fdb63446dfb309c114a95e97c5d243ff`.
- Exact direct dependencies plus transitive integrities: `package-lock.json`.

`toolchain.json` records this host's pins, not a portable installation guarantee. Smoke checks Node, package versions and binary SHA before launch. No browser autodetection, global installation or sandbox bypass. System browser updates invalidate the pin; do not silently refresh it.

## Commands (from repository root)

```sh
npm ci --prefix tools/lighthouse-dev --ignore-scripts --no-audit --no-fund
node tools/lighthouse-dev/bin/freeplast-lh.mjs
node tools/lighthouse-dev/bin/freeplast-lh.mjs --help
node tools/lighthouse-dev/bin/freeplast-lh.mjs --version
node tools/lighthouse-dev/bin/freeplast-lh.mjs --json
npm test --prefix tools/lighthouse-dev
# Historical ticket-1 compatibility probe; requires separate authorization, do not replay:
# npm run smoke --prefix tools/lighthouse-dev
# Ticket-2 synthetic security matrix, only when authorized:
# npm run test:security --prefix tools/lighthouse-dev
```

CLI default (`list`), help and version never import browser dependencies, start processes, contact services or write files. Default/list/show/compare read private evidence; missing state lists zero runs without creating it. `-v`/`-V` alias `--version`; `-h` aliases `--help`. Default output uses flat TOON strings; `--json` selects JSON for status/help/errors. Version is a bare string. Unknown commands/arguments return 2, successful probes return 0. Smoke errors return 1. No interactive prompts.

Help/version/usage tests restrict Node filesystem permissions to CLI/package manifest; no subprocess or write permissions. Default/list need read access to source/private state and fail closed when that is denied. Both profile configurations are checked without launching a browser.

## Fixture smoke

Creates one HTTP fixture on `127.0.0.1` with an OS-assigned port; starts a separate headless Chromium process with a new private temporary profile and sandbox intact. No existing CDP endpoint/profile is used. Uses Puppeteer's page/CDP API with Lighthouse's fourth `page` argument. One mobile audit only; no DEV requests, redirects, forms or external fixture resources. Chromium DNS is disabled except loopback; this is **not** the future DEV security boundary.

`src/config.mjs` defines mobile and desktop settings, explicit viewport/DPR, simulated network/CPU, Spanish locale/UA, categories and storage reset. Smoke sets `America/Santiago` timezone and Spanish Accept-Language. Desktop configuration is unit-tested, not browser-audited in this minimum probe.

Artifacts go beneath `~/.local/state/freeplast-lighthouse-dev/ticket-1-evidence/smoke-*` (0700 directories, 0600 files). Success would produce JSON/HTML, resolved settings, versions and request inventory. Failure retains an incomplete result. Fixture/profile cleanup targets only this invocation. No automatic retry. This script is a compatibility probe, not the durable collection runner; later tickets must implement full boundary enforcement, storage validation, failure handling and concurrency controls before any DEV access.

## Observed gate — 2026-10-22

- Offline tests: 2 passed.
- Original fixture smoke: **blocked**. Puppeteer timed out after 30,000 ms waiting for Chromium's websocket endpoint. Lighthouse never ran; no JSON/HTML audit exists.
- Retained private evidence: `~/.local/state/freeplast-lighthouse-dev/ticket-1-evidence/smoke-wLa1Y4/result.json`.
- Post-failure process inspection found no `freeplast-lh-smoke-*` or smoke-script process. Fixture recorded one `GET /`; its source was not established. Launch outcome remains unverified; no success inferred.
- No retry, alternate browser, download, shared-browser attachment or sandbox relaxation attempted.

### Owner-authorized additional attempt — passed

Exactly one additional smoke was authorized and executed at 01:48 UTC. No exploratory browser launches. Root defect: Puppeteer 25.9.0 `ChromeLauncher.computeLaunchArguments` does not add its automatic debugging port if *any* `--remote-debugging-*` flag exists. Our address-only flag therefore disabled CDP startup. Explicit `--remote-debugging-port=0` fixes that while retaining loopback binding and sandbox. New offline regression test uses the installed launch-argument computation without launching or creating a profile; failed before fix, passed afterward.

Additional smoke completed in 5,653 ms; CDP reported Chrome/152.0.7977.82, protocol 1.3. One mobile Lighthouse audit returned all five required metrics and four category scores. `noindex` remains: `is-crawlable` score 0 is intentional. Private JSON/HTML/settings/request evidence: `~/.local/state/freeplast-lighthouse-dev/ticket-1-evidence/smoke-ay6AHP/`. Browser stderr: sibling `authorized-attempt-2.stderr.log`, containing its loopback endpoint. No owned browser/profile process remained afterward. Offline tests now 3/3 pass.

Instrumentation limitation: successful attempt's `launch.json.args` is `{}` because Puppeteer's asynchronous `defaultArgs` was not awaited. Source corrected afterward; this logging-only fix was checked offline, not by a third browser launch. Browser stderr, CDP version and audit reports remain valid evidence. Original failed evidence is retained; its single request source remains unknown.

Toolchain compatibility smoke now passes; this proves neither DEV safety nor full runtime isolation. Later-ticket security gates remain mandatory. Dependency engine declarations are Lighthouse Node >=22.19 and Puppeteer >=22.12.0.

Sources checked: installed Lighthouse 13.5.0 `core/index.js` and `core/config/constants.js`, exact npm package metadata, and [TOON specification index](https://toonformat.dev/reference/spec.html).

## Ticket 2: implemented surface, awaiting lead handoff

```sh
node tools/lighthouse-dev/bin/freeplast-lh.mjs doctor --help
node tools/lighthouse-dev/bin/freeplast-lh.mjs measure --help
# Real network commands below belong to the separately authorized DEV gate, not implementation:
# node tools/lighthouse-dev/bin/freeplast-lh.mjs doctor --json
# node tools/lighthouse-dev/bin/freeplast-lh.mjs measure --label aa-1 --json
```

`doctor` acquires the same kernel lock as measurement; validates pins, instrument gate, operator runtime receipt, local DNS, three public LOCAL/noindex/nofollow documents, no-store headers, and served theme CSS against checkout bytes. It does not inspect credentials, Docker, databases or start DEV. It records private evidence, not a database-isolation certification. `measure` requires a label (1–40 ASCII letters/digits/underscore/hyphen, starting alphanumeric). Unknown input fails before imports/effects. No public URL, browser, state-directory, fixture, output-directory or configuration override exists. Exit 0 means complete collection/doctor, not improvement; 1 means technical/security/incomplete; 2 usage; 6 busy. Comparison codes are documented in Ticket 3 below.

### Two independent network controls

1. Browser-level CDP connection, separate from Lighthouse: recursive auto-attach with debugger pause; Fetch interception installed before resume; documents restricted to exact DEV routes, popup/worker targets closed, methods only GET/HEAD, assets/read auxiliaries explicitly checked, service-worker bypass, final document/history/status verification. No shared browser or profile.
2. Owned ephemeral loopback HTTP forwarding proxy. Chromium explicitly uses it, including loopback (`--proxy-bypass-list=<-loopback>`); no system proxy changes. CONNECT and WebSocket Upgrade are rejected before any upstream connection. Only approved HTTP-origin requests are forwarded, using a pinned local address. Startup stays network-offline until the guard authorizes the first document; startup blocks are recorded. Proxy v2 records denied CONNECT without a hostname exemption, but accepts such denials only with guard proof: every renderer target covered by Fetch/Network, zero HTTPS continuation, zero WS/WSS creation, guard healthy through owned browser shutdown. Every renderer HTTPS request is explicitly unsupported before continuation; any renderer WS/WSS event is fatal. Missing target provenance, unexpected CDP disconnect, Upgrade or other forbidden proxy request invalidates. DNS wildcard denial, QUIC disabled and non-proxied WebRTC UDP disabled provide additional containment. Sandbox remains enabled; extensions remain disabled.

**Approved limitation:** HTTPS external resources cannot be measured by this version. CONNECT denial is intentional, including required third-party assets: such a page is INVALID, never a faster valid audit. No TLS interception/certificate changes, external-resource parity or internet-browser isolation guarantee is claimed. Proxy version/overhead/flags are recorded and hashed into the comparable instrument. Private profile disables network prediction and browser sign-in; browser service traffic is still observed (blocker below).

Policy rejects staging/production `freeplast.cl` and `freeplast.mliu.site` (including subdomains), alternate origins/ports, userinfo, fragments, ambiguous encodings, unapproved queries, commercial APIs/GET actions, login/admin/cron, WebSockets and non-GET/HEAD methods. Known commerce/integration hosts are also denied. Redirects are checked before each hop in preflight and during browser requests. Approved same-origin public routes and `/robots.txt` can be read by Lighthouse helpers. Local assets permit only a bounded `ver` query.

### State, exclusion, evidence

Fixed root comes from the OS user's home, **not HOME/XDG/output overrides**: `~/.local/state/freeplast-lighthouse-dev/`. Directories 0700; files 0600; symlinks/unsafe permissions rejected. `runner.lock` is a permanent inode; `/usr/bin/flock` locks its shared open-file description nonblockingly. Audit workers inherit that descriptor. Supervisor/worker crashes cannot release it merely by deleting a PID file; no PID-only lock recovery. Supervisor kills only its newly spawned worker process group; browser is a non-detached child within that group. Worker disconnect/signal/deadline attempts owned-browser cleanup. Uncatchable simultaneous supervisor+worker termination remains an OS limitation, not transaction isolation.

Each series is new and non-resumable. Its first durable manifest is `incomplete`; first failure stops and preserves reports/events/summary. Three rounds: mobile `/`, `/tienda/`, product route; desktop same routes. Eighteen sequential audits, five seconds between audits; 45-minute series ceiling, per-audit 120-second envelope (117 seconds work plus up to 3 seconds shutdown). No automatic retry. Every audit uses a fresh private sandboxed profile, cold browser cache/storage, unchanged server caches. A successful report must contain five finite metrics, four category scores and no required audit/runtime errors. noindex crawlability debt is retained. TBT is not INP.

Manifest contains Git HEAD/branch/status, index fingerprint, source bytes/modes/hashes, pins/config/instrument hashes, runtime receipt, public-content hashes, resource/document inventory, hardware/load, timings and artifact SHA-256. Public HTML uses conservative byte hashing: nonces/timestamps can invalidate a series; there is **no permissive normalizer**. Sources covered: tool, theme, plugins, LOCAL config and root package locks; no global diff or secret-file bodies. CSP is hashed, not dumped.

JSON, HTML and summary derive from the same sanitized LHR, validated both before and after sanitization, before report generation/persistence. Sanitizer v2 preserves the static `third-party-cookies` audit and its score/category reference, but removes its entire details table: Lighthouse can place raw cookie lines in the generic `name` column. This narrow audit-record exception does not exempt its metadata from sensitive-string checks. Typed DOM nodes are replaced with an omission marker; headers/cookies, DOM snippets, form values and screenshot pixels are omitted; recognized secret-bearing strings/URLs abort persistence. Exceptions produce fixed reason codes rather than raw dependency logs. Synthetic diagnostic logs remain private. Secret-pattern checks are conservative, not a proof that arbitrary unexpected text contains no sensitive information; never audit private/account pages. Two-GiB artifact cap checked before writes, 32-MiB audit headroom, no automatic deletion/GC. Tool package size checked against 2 GiB; cumulative multi-worktree installation budget remains operator-accounted. Persistent overall A/A budget remains a later-ticket responsibility; the manual-cycle budget is documented below.

### Required receipts (not available yet)

A successful **full** `npm run test:security` matrix writes a private `gate-candidate.json` containing the current instrument hash and zero denied contacts. Partial selections (`LH_FIXTURE_CASE=<fixed fixture names>`) never produce a gate. Operator/lead must verify the evidence before installing the candidate as `security-gate.json` in the fixed root. Code/lock/config changes invalidate it. The historical `security-sCWIqG` candidate failed post-pass report validation and must not be installed. The new owner-authorized `security-9o8DSp` candidate also requires lead verification; its clean persisted JSON is revalidated and compared byte-for-byte with regenerated HTML inside the matrix before any candidate is written. No candidate is self-activated.

`identity.json` must also exist there, 0600, with fields: `schema: 1`, exact `origin: "http://mliu:8131"`, absolute `checkout` (including trailing slash as exported by `src/fingerprint.mjs`), `sourceHash`, ISO `verifiedAt` within 24 hours, `operator`, `runtimeId`, `wordpress`, and `plugins` mapping slugs to version strings. Operator supplies it from authorized non-sensitive runtime metadata. No credentials. This is an attributed attestation plus public asset verification, not independent plugin/DB introspection. Obtain the local source hash without network using:

```sh
node --input-type=module -e 'import {checkout,fingerprint} from "./tools/lighthouse-dev/src/fingerprint.mjs"; console.log(JSON.stringify({checkout,sourceHash:(await fingerprint()).sourceHash}))'
```

### Initial ticket-2 blocker, 2026-10-22 (history; follow-up below)

Original CDP-only synthetic tests prevented first requests for redirects, meta/JS/late-JS navigation, popups, frames, images and mutations. **WebSocket fixture escaped one handshake** despite CDP URL blocking; preserved evidence: `ticket-2-evidence/security-AIbkWA/results.json`. Never use CDP-only collection.

Lead authorized the filtering proxy above. Proxy unit fixtures prove WS/WSS/HTTP mutation rejection before upstream TCP contact. However a clean real Lighthouse fixture with the proxy remains INVALID: Chromium attempts background CONNECT (`www.google.com:443`, then `accounts.google.com:443`) after the audited document begins. Proxy blocks these with no upstream contact. Do not silently whitelist them or report the partial collection as valid. Latest failed browser evidence: `ticket-2-evidence/security-RUyPQg/results.json`; fixture `/` was reached, denied server connections=0, error=`proxy-connect`, browser service=`accounts.google.com:443`. Browser validation stopped; full proxy-backed Lighthouse matrix/clean JSON+HTML gate unproven. Several earlier test rows labelled passed due to an overbroad expected error were **not coverage**; case-specific expectations and fixture-reached assertions now reject that false positive. Original files remain untouched.

No DEV requests, 18-run real collection, A/A, comparison/cycles, app tests, review or commits performed for ticket 2. Offline scheduler/lifecycle/state tests use synthetic workers/data, not fabricated Lighthouse measurements.

### Bounded follow-up: renderer invariants improved; gate still BLOCKED

Lead authorized 30 minutes (02:32:45–03:02:45 UTC) to distinguish harmless denied tunnels from required renderer resources without trusting hostnames. Proxy v2 plus renderer provenance above now reject explicit renderer HTTPS fetch/image and WS/WSS, including `accounts.google.com` (the same name seen in browser service traffic). Real fixture subset `security-Zp6aJp` proved these denials with zero own HTTP/TLS sink contacts and case-specific CDP provenance. Clean guarded mobile Lighthouse JSON/HTML and popup/worker/frame-HTTPS subset passed in `security-5cXZT7`, with healthy target inventory and zero HTTPS continuation/WS creation. Browser-owned shutdown was distinguished from unexpected CDP disconnect only after diagnostic evidence showed the socket error occurred during our explicit successful shutdown.

No full matrix passed. First matrix `security-qhHNAj` recorded four denied HTTP GETs and one TLS TCP contact in its 307 case, despite that fixture never referencing its TLS sink. Lead confirmed no concurrent lead/worker probes. One source-classified matrix was then authorized, only after checking previous owned browser/process groups inactive. It stopped at `security-ncqETn`: commercial-GET case saw **two unmarked GETs to the denied HTTP sink plus one TLS TCP contact**. Timestamp 02:46:17.557–.559 UTC, before the later marked Chromium document GET at 02:46:18.810. Classification is not attribution: missing marker/non-Chromium classification does not prove an unrelated caller or excuse forbidden contact. Counters remain failures, never filtered.

Browser/fixture runs stopped at 02:46:18 UTC. No further retry, counter exclusion, other-session inspection/control or DEV request. Own adapter uses `listen` callbacks, not readiness HTTP/TCP probes; own proxy tags synthetic forwarded HTTP; Puppeteer is supplied an exact browser websocket endpoint. Those source observations cannot identify the recorded TLS client's PID. **Caller remains unproven; no security gate receipt, no DONE:2.** A quiet/exclusive authorized fixture window or evidence-backed attribution decision is required before another matrix.

A lead-authorized rootless namespace availability probe succeeded with UID/GID1000 unchanged and only loopback DOWN. The subsequently authorized exact one-time isolated matrix command failed at `ip link set lo up` with `RTNETLINK answers: Operation not permitted` (exit2), **before Node or browser started**. No retry, sudo/root mapping, capability workaround, sandbox relaxation or host-interface change. Namespace read availability did not establish permission to configure its loopback. Evidence: `rootless-netns-probe.log`, `netns-host-context.log`, `netns-security-once.log`.

A further **separately authorized one-time** preparation retained capabilities only in the new user/network namespace for lo-up, then used setpriv to drop inheritable/ambient/bounding sets and set no-new-privs. It asserted UID1000 plus all five CapInh/CapPrm/CapEff/CapBnd/CapAmb masks zero before Node. Setup succeeded; sandbox remained on. Isolated matrix `security-jvH0ce` passed its first 12 cases, then stopped at `https-fetch`: expected `https-resource-unsupported`, observed `method` with known renderer Fetch session and httpsDenied=1. All 13 cases had zero forbidden HTTP/TLS contacts. The actual non-GET/HEAD verb was not recorded; marker-induced CORS preflight is a hypothesis, not a proven verb/source. No assertion relaxation or further attempt. Remaining cases, including clean/desktop, were not reached. Isolation does not identify the earlier shared-host anomaly source.

At that handoff, the offline suite was **23/23**; the isolated matrix remained incomplete. All failed and partial-success evidence remains preserved in `ticket-2-evidence/`.

### Second/final corrective window: network matrix passed; persisted reports failed

Within the final window ending 03:33 UTC, denied Fetch diagnostics gained exact fixture-only target/method, resource type/stage, continuation-attempt status and failRequest acknowledgement. Offline tests prove GET and OPTIONS cannot continue; oracle accepts OPTIONS only when it was actually captured for the exact intended HTTPS fetch target, XHR, Request stage, known session, no continuation and acknowledged denial. It does not accept a generic `method` reason, POST, another target, missing provenance or an image preflight. Independent own-TLS image and accounts-image cases still require direct GET denial; WS/WSS retain independent exact-target checks. No hostname trust or policy relaxation.

One authorized corrected complete matrix ran under the same scoped-capability setup, with UID1000 and all five capability masks zero before Node, sandbox on. `security-sCWIqG/results.json`: **21/21 network cases passed**, every forbidden HTTP/WS/TCP/TLS counter zero. HTTPS fetch, accounts fetch and child-frame fetch now explicitly captured **OPTIONS**, XHR, the exact expected HTTPS target, `continuationAttempted=false`, `failRequestAcknowledged=true`. Both image cases captured GET/Image rejected as unsupported HTTPS. Clean mobile/desktop returned JSON/HTML. Final offline suite **26/26** before and after; syntax, diff and runtime freeze checks passed. Security runtime/test source frozen after that matrix; no additional browser run.

**Final blocker found after the pass:** validating the persisted sanitized LHR raises `audit-error` for both clean profiles. `src/report.mjs` broadly removes keys matching `/cookie|authorization|password|secret|token/i`, including the static audit ID `third-party-cookies`; `best-practices.auditRefs` still references it. JSON and HTML agree but are both incomplete. Intentional noindex score0 remains. Evidence: `final-correction-artifact-failure.json`; detailed per-case/namespace/artifact hashes in `final-correction-evidence-summary.json`. No source fix or replay attempted after discovery; final corrective allowance exhausted. The generated `gate-candidate.json` proves only the matrix assertions, is **not active and must not be installed** while artifact completeness fails.

At that handoff: no DONE:2 or DEV readiness claim. Prior shared-host anomaly source remains UNKNOWN; isolation success does not retrospectively attribute it or certify real DEV. No DEV, later-ticket work, commits or review.

### Owner-authorized sanitizer continuation — 11:05–11:14 UTC

One new bounded pass was explicitly authorized after the two historical corrective windows. Sanitizer v2 above fixes the static audit-ID deletion without retaining cookie-table payloads. Regression fixtures include a cookie name, raw cookie line, cookie value/header, secret-bearing URL, typed DOM node and category references; sanitized missing references still fail closed. Offline **28/28** and syntax/diff checks passed before source freeze.

Exactly one new complete matrix: `ticket-2-evidence/security-9o8DSp/`, 11:11:31–11:12:27 UTC. **21/21 passed**, zero forbidden HTTP/WS/TCP/TLS contacts; UID/GID1000, private loopback only, all five capability masks zero and no-new-privs=1 asserted before Node, sandbox retained. Independent HTTPS images/accounts images and WS/WSS cases unchanged. Both clean profiles retain `third-party-cookies`, every category reference resolves, intentional noindex score0 remains, and persisted JSON passes `validateLhr`; generated HTML matches persisted HTML byte-for-byte. Independent read-only artifact verification repeated those checks and verified 0600 files/0700 directory.

Instrument: `6cb4c7c14bf61567051de89daae33803ee18f057b8473169057b3e35dd872747`. Evidence: `sanitizer-continuation-offline-before.log`, `sanitizer-continuation-matrix-once.log`, `sanitizer-continuation-artifact-verification.json`. Source freeze and checksum verification of all historical evidence passed; no old report was backfilled or relabelled. No owned processes/profiles remained. Await lead verification/integration; no active gate, DEV request, later-ticket work, commit or review. Prior shared-host anomaly attribution remains unknown.

## Ticket 3: offline queries, frozen calibration and deterministic comparison

Implemented against **synthetic reports only**; no DEV/browser/network fixture run in this ticket. New source/CLI changes invalidate the historical security candidate. Final ticket-5 security gate refresh and identity receipts remain mandatory; no receipt is activated here.

```sh
node tools/lighthouse-dev/bin/freeplast-lh.mjs list --json
node tools/lighthouse-dev/bin/freeplast-lh.mjs show --run <a1-id> --json
node tools/lighthouse-dev/bin/freeplast-lh.mjs calibrate --a <a1-id> --b <a2-id> --json
node tools/lighthouse-dev/bin/freeplast-lh.mjs baseline-set --run <a1-id> --confirm --json
# Equivalent spelling: baseline set --run <a1-id> --confirm
node tools/lighthouse-dev/bin/freeplast-lh.mjs compare --baseline <a1-id> --candidate <a2-id> --calibration <cal-id> --json
# Later authorized intervention: choose focal BEFORE candidate collection, not after seeing results:
node tools/lighthouse-dev/bin/freeplast-lh.mjs calibrate --a <a1-id> --b <a2-id> --focal mobile:/ --json
# Synthetic-only regression suite, no browser or network listeners:
node --test tools/lighthouse-dev/test/compare.test.mjs tools/lighthouse-dev/test/cli.test.mjs tools/lighthouse-dev/test/report.test.mjs
```

`list` (also no arguments) and `show` are read-only; neither creates state nor takes a writing lock. List reports complete versus invalid/incomplete, count and manifest hash. Show validates all reports then returns six case distributions. Missing root lists zero runs; corrupt/private-path failures fail closed. `compare` is also read-only. `calibrate` and explicit `baseline-set --confirm` use the existing permanent kernel lock, private storage, capacity checks and exclusive writes. Baseline pointer replacement is atomic/fsynced; comparison, failed calibration and regression never promote or alter it. No automatic calibration discovery or baseline selection: comparison takes explicit IDs, including optional `--calibration`.

Validation requires schema-1 complete manifests, exact 18-row schedule/identity, four scores/five finite mandatory metrics, every category audit reference, matching derived summary, source hash and pre/post identity, sandbox/renderer/proxy/shutdown evidence, no-store and intentional `is-crawlable` score 0. Every JSON/HTML/summary/event artifact must be present, correctly referenced, private, non-symlink, with matching size and SHA-256. Unsupported versions/settings/source/cache/origin/hardware are incompatible; malformed/missing records are technical errors, never gains. Known schema fields are enforced for manifests/rows/environment/source/identity/calibration; Lighthouse's own extensible report is validated at consumed fields, not treated as a closed arbitrary JSON schema. Hashes detect corruption, not malicious forgery by the state-owning OS user. Queries do not lock out an uncooperative external writer; immutable reports and hash checks remain required.

Calibration requires two distinct complete compatible series. Each metric noise floor is `abs(median_A1 - median_A2) + 2 * max(MAD_A1, MAD_A2)`. A/A is rejected as inconclusive if LCP/FCP/SI range exceeds `max(2 * absolute_floor, .25 * median)` in either series, or median drift exceeds `max(absolute_floor, relative_floor * A1_median)`. Audit-state instability or A11y/BP/SEO score instability also blocks calibration. Stable records are immutable content-addressed `cal-<sha256>.json` files, binding both manifest hashes, the current instrument/evaluator, optional focal case, creation time and noise. Loading revalidates source runs and recomputes all frozen floors. Non-A/A candidates must start after calibration creation; no recalibration using B. Default calibration has no focal: ordinary A/A remains **inconclusive/no-gain**, not an improvement.

Comparison operates independently on all six fixed route/profile cases, retaining the three raw values, n, median, min/max, range and MAD for each metric/score. Full precision decides; threshold equality is inconclusive, never gain/regression. Threshold is `max(absolute, relative * baseline_median, frozen_AA_noise)`:

| Signal | Absolute | Relative | Rule |
| --- | ---: | ---: | --- |
| LCP | 200 ms | 10% | Focal reduction strictly over threshold enables numerical gain |
| FCP / Speed Index | 150 ms | 10% | Increase strictly over threshold regresses |
| TBT | 50 ms | 15% | Increase strictly over threshold regresses; **not INP** |
| CLS | .01 | 0 | Increase over threshold or new median > .1 regresses |

Any metric regression in any case wins over another case's improvement. Baseline zero returns `relativeDelta: null`, never divides by zero. Candidate LCP/FCP/SI excessive range prevents improvement. Four scores remain visible: Performance informational; A11y/BP/SEO median drops regress, intermittent drops require review. Audit IDs/modes/scores are compared independently of rounded category scores: stable new/worsened failures regress, intermittent failures or changed coverage/modes are inconclusive. Intentional noindex debt is never removed for SEO gain.

Optional TTFB is raw per sample with its audit ID; when present in every sample, it also gets distributions and a warning above `max(100 ms, 15%, frozen TTFB noise)`. Missing/unusable optional diagnostics never become zero. LCP DOM/selector/resource/breakdown details are explicitly unavailable in evaluator v1: sanitizer removes DOM and no stable detailed diagnostic schema is claimed. A hypothesis relying on unavailable diagnostics cannot be confirmed.

Exit codes: **0** successful query/calibration/baseline-set or numerical improvement; **1** technical/security/invalid record; **2** usage; **3** regression; **4** inconclusive; **5** incompatible; **6** busy. Collection exit 0 still means only complete collection. Without valid calibration or a preselected focal case, comparison cannot improve. Standalone comparisons keep source differences incompatible. The manual-cycle API below supplies a narrowly bound DECLARED intervention; no CLI source/config bypass exists.

An `improvement` is **numerical laboratory evidence only**, not KEEP, functional correctness, visual acceptance, real-user performance, full accessibility or privacy certification. Offline functionality tests and human content/design approval remain independent mandatory cycle guardrails. No commercial flows, source mutation, baseline auto-promotion, receipts bypass, real A/A certification or review performed here.

## Ticket 4: fixture-only manual cycle and guarded recovery

No agent, proposal generator, arbitrary hook, test-command executor, automatic patch or baseline promotion. Commands **record** one manually supplied hypothesis, owned paths, acceptance criterion, pre/post states, test evidence, numerical verdict, explicit KEEP/DISCARD decision and learning. They do not claim to run functional tests or obtain human approval. Only synthetic temporary Git trees were exercised; real application restoration is neither authorized nor enabled in this version.

```sh
node tools/lighthouse-dev/bin/freeplast-lh.mjs cycle --help
node tools/lighthouse-dev/bin/freeplast-lh.mjs cycle --action begin --input /absolute/private/begin.json --json
node tools/lighthouse-dev/bin/freeplast-lh.mjs cycle --action post --input /absolute/private/post.json --json
node tools/lighthouse-dev/bin/freeplast-lh.mjs cycle --action decide --input /absolute/private/decision.json --json
node tools/lighthouse-dev/bin/freeplast-lh.mjs cycle --action show --json
# Only after explicit DISCARD, in the same untouched synthetic fixture:
node tools/lighthouse-dev/bin/freeplast-lh.mjs cycle --action restore --json
```

Input files must be private, regular, singly linked JSON files. No state-root CLI override. API root parameters exist solely for isolated tests. Begin accepts exactly:

```json
{
  "repo": "/tmp/lh-cycle-UNIQUE",
  "hypothesis": "One concrete, falsifiable intervention",
  "paths": ["wordpress/wp-content/themes/freeplast/style.css"],
  "baseline": "complete-baseline-id",
  "calibration": "cal-content-hash",
  "acceptance": "Predeclared functional and visual/content criteria plus focal LCP gain"
}
```

Repository must be an existing **direct child of the platform temporary directory**, named `lh-cycle-*`, with its own real `.git` directory, existing HEAD/index, no symlinks/hardlinked files/conflicts/merge/rebase in progress. Entire fixture inventory (including ignored, dirty and untracked files) is capped at 2 MiB; declared paths have existing parent directories. The validated baseline source must exactly match the pre-state, with a valid existing frozen calibration. There is no application checkout default. Supplying a path is not permission to use real data: these are synthetic fixtures only.

`post.json`: `{"tests":"evidence/tests.txt"}`. Evidence paths are relative to the fixed private state root, non-symlink private regular files, at most 2 MiB. Post records evidence byte length/SHA-256 and source/worktree snapshots; unrelated changes or index drift cannot be adopted. Operator runs tests separately, saves their output, and explicitly records the result. No shell command from JSON is executed.

`decision.json` example (replace every placeholder with actual evidence; never reuse this as approval):

```json
{
  "candidate": "complete-candidate-id",
  "acceptance": {
    "decision": "KEEP",
    "learning": "What the evidence established, including limits",
    "functional": {"passed": true, "evidence": "evidence/tests.txt"},
    "visual": {
      "status": "approved",
      "reviewer": "Actual human reviewer attribution",
      "evidence": "evidence/human-content-design-review.txt"
    }
  }
}
```

KEEP requires all three independently: comparator `improvement`, explicit functional pass with linked evidence, and attributed human `approved` or honest `no-visual-change` declaration with linked evidence. `not-reviewed`/`rejected`, absent reviewer, missing evidence, regression/incompatibility/inconclusive cannot KEEP. No numerical score implies visual approval. The software validates attribution/evidence presence and hashes, **not human identity or truthfulness**. Candidate must start after post-state capture and finish before decision. Baseline/candidate manifest hashes and full numerical verdict persist; test evidence must still match post capture. `DISCARD` requires explicit learning/evidence too. `candidate: null` permits honest inconclusive `candidate-not-collected` DISCARD after failed/manual tests; it never permits KEEP.

DECLARED comparison is restricted to predeclared theme files, binding exact pre/post source fingerprints. HEAD, branch, index, unrelated files/status, evaluator/toolchain/config, runtime identity, public content, hardware, cache and audit settings remain strict. Only source/source-identity hashes attributable to those files may differ. The served `styleHash` may differ **only** for declared `wordpress/wp-content/themes/freeplast/style.css` when each served hash equals its respective captured file SHA-256. Public HTML drift, arbitrary config changes and other served-asset changes remain incompatible; there is no generic content/source exemption. This conservative version cannot confirm interventions that change public HTML hashes.

### Persistent budget and recovery custody

`manual-cycle/budget.json` reserves **one iteration / 90 minutes wall clock** before capture. Every new process reads that same durable record; no reset/resume/new-cycle command. Failed initialization consumes the attempt. Completed or uncertain operations cannot reopen it. Expired cycles cannot post or KEEP; an already captured post-state may still receive explicit DISCARD and guarded recovery for safety. Restoring does not replenish budget. External deletion/forgery by the OS state owner is outside the integrity model.

Begin captures exact bytes/modes, HEAD/branch, raw index hash/index entries and full file inventory. Post is immutable. Recovery never checks out HEAD, resets, stashes, cleans, edits the index or touches undeclared files. It requires DISCARD and exact post-state equality, then restores owned pre-images (including dirty/untracked content), recreates owned deletions and removes owned additions. All pre-image hashes are checked before writes. Symlinks, conflicts, changed index, later edits or untracked drift block. Index and unrelated dirty/untracked files remain untouched.

`recovery-start.json` is durable before the first mutation; each file has immutable pending/done receipts. Verification runs before each file and after each mutation; completion requires exact pre-state equality. Any failure records `uncertain` where possible. A crash can leave only a pending receipt: **that is uncertainty, never success**. A partial recovery or restart cannot replay automatically. Preserve evidence and obtain separate manual intervention authorization; there is no force flag. Exit 0 means recorded operation/restoration only, not global acceptance; technical/uncertain failures exit 1, usage 2, busy 6.

`ponytail:` filesystem snapshots and checks are not atomic transactions against an uncooperative external writer. Pause all other writers; upgrade to OS-level exclusive custody before allowing real application restoration. An identical-content rewrite is not distinguishable from the captured bytes. File contents/modes and index are preserved, not original timestamps, empty directories, ACLs or xattrs. Process-kill tests demonstrate retained pending receipts, not power-loss durability across every filesystem. Full pre-images remain private evidence; no automatic cleanup.

Validation: `npm test --prefix tools/lighthouse-dev` includes pure/HTTP synthetic fixtures only, no browser/native matrix. Cycle coverage includes preexisting dirty/index/untracked preservation, additions/deletions/modes, later/index/untracked/symlink drift, real unmerged index conflicts, partial recovery, abrupt child-process exit, restart budget, damaged pre-image/evidence, and DECLARED unrelated-source/config/content rejection. Synthetic reviewer assertions in tests are not human review of any application. Security receipt remains stale after instrument changes; no smoke/security matrix/DEV run or receipt activation occurred for ticket 4.
