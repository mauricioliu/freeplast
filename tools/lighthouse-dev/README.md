# Lighthouse DEV — toolchain scaffold

Ticket 1 only. **Not ready for DEV.** No `doctor`, `measure`, comparison or cycle commands yet. No application files, root dependencies or shared browser are used.

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
# Explicit fixture launch; no arguments or external URLs accepted:
npm run smoke --prefix tools/lighthouse-dev
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
