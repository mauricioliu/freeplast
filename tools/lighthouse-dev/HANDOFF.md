# Ticket 5: final DEV validation protocol

This document freezes **before** real collection. Actual outcome, run IDs, distributions and blockers are recorded only in the private report:

`/home/mauricio-liu/.herd/plan-mode/plan-mviv2aqv-osyi.impl-5.report.md`

No real result is claimed here. Do not modify tracked files after collecting A1: the fingerprint includes documentation. Lead integrates this file before generating the MAIN identity receipt and collecting. Writer worktree is not the served checkout.

## Provenance and final synthetic gate

- Actual checkout: `/home/mauricio-liu/Projects/freeplast/`.
- Canonical allowed origin: `http://mliu:8131`; routes `/`, `/tienda/`, `/producto/caja-cosechera-3-4/` only.
- At 2026-10-22 11:59 UTC, all three returned HTTP 200 and passed LOCAL/noindex/nofollow/no-store preflight. DNS resolved to this host's Tailscale address `100.102.106.66`. Served theme style SHA-256 matched MAIN: `3e5e9bf6e692f1aceaea4a0aec0755072fd757a8408b79276effca58dd11ed16`.
- Filtered Docker metadata identifies `freeplast-local-wordpress-1`, ID `99208940eccf187ceaaa498b2ef365016d4745b25e8a082e49e788de05983b7c`, image digest `sha256:f32ffa85064d9c951b7b69c929fa2a9f682aa4443e0c0a56ac2000499cb64383`. Theme, freeplast-woo and LOCAL safety configuration bind to MAIN, not writer worktree. Nothing was started/reconfigured.
- Read-only source version declarations: WordPress 7.1.3; installed plugin declarations recorded privately. These are installed-code versions, **not proof of active plugin state or database isolation**. Receipt operator is the verifying agent, not a human attestation.
- Final instrument `2a230ea2efc66906266722d2da93b1b48c6be9c632caae33616218b5c7cb3e98` passed one new full 21-case matrix. UID/GID1000, new private user/net namespaces, lo only, all five capability masks zero and no-new-privs=1 before Node; Chromium sandbox retained. Zero forbidden HTTP/WS/TCP/TLS contacts. Persisted clean mobile/desktop JSON passed `validateLhr`; regenerated HTML byte-equal.
- Native evidence retained at `~/.local/state/freeplast-lighthouse-dev/ticket-2-evidence/security-epAMAZ/` (the existing matrix uses that historical parent). Candidate is not self-activated. New provenance/check logs: `~/.local/state/freeplast-lighthouse-dev/ticket-5-evidence/`.

## Lead-only receipt and collection sequence

After scope/hash verification and integration, run from **MAIN**; save stdout/stderr privately, never overwrite historical artifacts. Verify instrument still equals the hash above. Verify candidate context, all 21 results, clean JSON/HTML, private modes and runtime metadata before installing receipts. The private readiness report contains exact receipt construction commands. Generate `identity.json` using MAIN's current `fingerprint().sourceHash` and exported trailing-slash `checkout`, with current time, agent attribution and the observed container/code versions. Never copy writer sourceHash or claim active-plugin/DB introspection.

```sh
cd /home/mauricio-liu/Projects/freeplast
node --input-type=module -e 'import {instrumentHash} from "./tools/lighthouse-dev/src/measure.mjs"; console.log(await instrumentHash())'
node --input-type=module -e 'import {checkout,fingerprint} from "./tools/lighthouse-dev/src/fingerprint.mjs"; console.log(JSON.stringify({checkout,source:await fingerprint()}))'
node tools/lighthouse-dev/bin/freeplast-lh.mjs doctor --json
# Only if doctor passed, no source/runtime drift, and budgets permit:
node tools/lighthouse-dev/bin/freeplast-lh.mjs measure --label aa-1 --json
# Only if all 18 A1 audits passed; no retry after failure:
node tools/lighthouse-dev/bin/freeplast-lh.mjs measure --label aa-2 --json
```

Substitute actual returned IDs below; never invent IDs or fabricate incomplete samples:

```sh
node tools/lighthouse-dev/bin/freeplast-lh.mjs list --json
node tools/lighthouse-dev/bin/freeplast-lh.mjs show --run "$A1" --json
node tools/lighthouse-dev/bin/freeplast-lh.mjs show --run "$A2" --json
node tools/lighthouse-dev/bin/freeplast-lh.mjs calibrate --a "$A1" --b "$A2" --json
node tools/lighthouse-dev/bin/freeplast-lh.mjs compare --baseline "$A1" --candidate "$A2" --calibration "$CAL" --json
# Explicit baseline promotion only after complete comparable stable A/A:
node tools/lighthouse-dev/bin/freeplast-lh.mjs baseline-set --run "$A1" --confirm --json
```

A/A no-gain/inconclusive is expected, not a performance improvement. Excessive noise, missing artifacts, noindex loss or incompatibility blocks certification. Report each of six route/profile cases, all three samples and median/min/max/range/MAD; validate manifests, report hashes, final URLs, settings, versions, source and hardware before interpreting differences. Calibration failure means no valid `$CAL`: do not run a fabricated comparison or promote baseline. An incomplete run may be listed; `show`/comparison correctly reject it.

## Stop rules and limits

One operator; no concurrent audit. Maximum 36 real audits total, 90 minutes combined, 45 minutes/series, 120 seconds/audit, five-second pauses, zero real retries. Absolute plan deadline 2026-10-22 13:28 UTC, with time reserved for global review. Browser cache/profile cold; server caches unchanged. Stop first failure; preserve incomplete manifests and all evidence. Do not fix DEV or rerun failed audit. A blocker with exact evidence is a valid ticket handoff, not a successful baseline.

Strict HTTPS-resource policy may invalidate real pages. Public HTML byte drift is conservative; dynamic nonces can block collection. Proxy overhead is part of instrument. TBT is not INP. JSON/HTML equality does not prove visual rendering. Installed-code versions and public assets do not prove plugin activation, DB identity or business correctness. No credentials, DB/mail/commercial contents, real mutation flows, production, theme optimization, global changes, commits or publication are authorized by this protocol.

Only `tools/lighthouse-dev/HANDOFF.md` and its README link are ticket-5 tracked changes. Final real outcome belongs exclusively to private report/evidence, preserving measured source snapshot. Full offline tests and source inventories accompany the private handoff; historical failures remain untouched.
