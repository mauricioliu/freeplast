# Lighthouse DEV — isolated collection

**Synthetic ticket-2 gates passed; not authorized for DEV.** Owner-authorized sanitizer continuation passed 28 offline tests and one complete isolated 21-case matrix, including persisted mobile/desktop report validation. Await lead verification/integration; no active gate receipt exists. Earlier invalid reports/candidates remain invalid and preserved (history below). Comparison/cycles and DEV remain later tickets. No application files, root dependencies or shared browser are used.

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

CLI default/help/version never import browser dependencies, start processes, contact services or write files. `-v`/`-V` alias `--version`; `-h` aliases `--help`. Default output uses flat TOON strings; `--json` selects JSON for status/help/errors. Version is a bare string. Unknown commands/arguments return 2, successful probes return 0. Smoke errors return 1. No interactive prompts.

Tests execute probes with Node filesystem permissions limited to the CLI and package manifest, in an empty temporary HOME/cwd. Process creation and writes are disallowed. Both profile configurations are checked without launching a browser.

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

`doctor` acquires the same kernel lock as measurement; validates pins, instrument gate, operator runtime receipt, local DNS, three public LOCAL/noindex/nofollow documents, no-store headers, and served theme CSS against checkout bytes. It does not inspect credentials, Docker, databases or start DEV. It records private evidence, not a database-isolation certification. `measure` requires a label (1–40 ASCII letters/digits/underscore/hyphen, starting alphanumeric). Unknown input fails before imports/effects. No public URL, browser, state-directory, fixture, output-directory or configuration override exists. Exit 0 means complete collection/doctor, not improvement; 1 means technical/security/incomplete; 2 usage; 6 busy. Future comparison exit codes are not implemented here.

### Two independent network controls

1. Browser-level CDP connection, separate from Lighthouse: recursive auto-attach with debugger pause; Fetch interception installed before resume; documents restricted to exact DEV routes, popup/worker targets closed, methods only GET/HEAD, assets/read auxiliaries explicitly checked, service-worker bypass, final document/history/status verification. No shared browser or profile.
2. Owned ephemeral loopback HTTP forwarding proxy. Chromium explicitly uses it, including loopback (`--proxy-bypass-list=<-loopback>`); no system proxy changes. CONNECT and WebSocket Upgrade are rejected before any upstream connection. Only approved HTTP-origin requests are forwarded, using a pinned local address. Startup stays network-offline until the guard authorizes the first document; startup blocks are recorded. Proxy v2 records denied CONNECT without a hostname exemption, but accepts such denials only with guard proof: every renderer target covered by Fetch/Network, zero HTTPS continuation, zero WS/WSS creation, guard healthy through owned browser shutdown. Every renderer HTTPS request is explicitly unsupported before continuation; any renderer WS/WSS event is fatal. Missing target provenance, unexpected CDP disconnect, Upgrade or other forbidden proxy request invalidates. DNS wildcard denial, QUIC disabled and non-proxied WebRTC UDP disabled provide additional containment. Sandbox remains enabled; extensions remain disabled.

**Approved limitation:** HTTPS external resources cannot be measured by this version. CONNECT denial is intentional, including required third-party assets: such a page is INVALID, never a faster valid audit. No TLS interception/certificate changes, external-resource parity or internet-browser isolation guarantee is claimed. Proxy version/overhead/flags are recorded and hashed into the comparable instrument. Private profile disables network prediction and browser sign-in; browser service traffic is still observed (blocker below).

Policy rejects staging/production `freeplast.cl` and `freeplast.mliu.site` (including subdomains), alternate origins/ports, userinfo, fragments, ambiguous encodings, unapproved queries, commercial APIs/GET actions, login/admin/cron, WebSockets and non-GET/HEAD methods. Known commerce/integration hosts are also denied. Redirects are checked before each hop in preflight and during browser requests. Approved same-origin public routes and `/robots.txt` can be read by Lighthouse helpers. Local assets permit only a bounded `ver` query.

### State, exclusion, evidence

Fixed root comes from the OS user's home, **not HOME/XDG/output overrides**: `~/.local/state/freeplast-lighthouse-dev/`. Directories 0700; files 0600; symlinks/unsafe permissions rejected. `runner.lock` is a permanent inode; `/usr/bin/flock` locks its shared open-file description nonblockingly. Audit workers inherit that descriptor. Supervisor/worker crashes cannot release it merely by deleting a PID file; no PID-only lock recovery. Supervisor kills only its newly spawned worker process group; browser is a non-detached child within that group. Worker disconnect/signal/deadline attempts owned-browser cleanup. Uncatchable simultaneous supervisor+worker termination remains an OS limitation, not transaction isolation.

Each series is new and non-resumable. Its first durable manifest is `incomplete`; first failure stops and preserves reports/events/summary. Three rounds: mobile `/`, `/tienda/`, product route; desktop same routes. Eighteen sequential audits, five seconds between audits; 45-minute series ceiling, per-audit 120-second envelope (117 seconds work plus up to 3 seconds shutdown). No automatic retry. Every audit uses a fresh private sandboxed profile, cold browser cache/storage, unchanged server caches. A successful report must contain five finite metrics, four category scores and no required audit/runtime errors. noindex crawlability debt is retained. TBT is not INP.

Manifest contains Git HEAD/branch/status, index fingerprint, source bytes/modes/hashes, pins/config/instrument hashes, runtime receipt, public-content hashes, resource/document inventory, hardware/load, timings and artifact SHA-256. Public HTML uses conservative byte hashing: nonces/timestamps can invalidate a series; there is **no permissive normalizer**. Sources covered: tool, theme, plugins, LOCAL config and root package locks; no global diff or secret-file bodies. CSP is hashed, not dumped.

JSON, HTML and summary derive from the same sanitized LHR, validated both before and after sanitization, before report generation/persistence. Sanitizer v2 preserves the static `third-party-cookies` audit and its score/category reference, but removes its entire details table: Lighthouse can place raw cookie lines in the generic `name` column. This narrow audit-record exception does not exempt its metadata from sensitive-string checks. Typed DOM nodes are replaced with an omission marker; headers/cookies, DOM snippets, form values and screenshot pixels are omitted; recognized secret-bearing strings/URLs abort persistence. Exceptions produce fixed reason codes rather than raw dependency logs. Synthetic diagnostic logs remain private. Secret-pattern checks are conservative, not a proof that arbitrary unexpected text contains no sensitive information; never audit private/account pages. Two-GiB artifact cap checked before writes, 32-MiB audit headroom, no automatic deletion/GC. Tool package size checked against 2 GiB; cumulative multi-worktree installation budget remains operator-accounted. Persistent overall A/A/cycle budget is a later-ticket responsibility.

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
