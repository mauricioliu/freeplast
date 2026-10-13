# Mandatory dispatch selection: completion attempt, 2026-10-12

Source tested: `044605fdfe296886da8b44145b3d4cefab950d16` (includes `e458075`). **Update 2026-10-13 UTC:** release `20261013T010349Z-272948` completed only on staging, installing adapter 1.12.9 / theme 1.0.23. Gate, paired-backup restoration, trial, installed hashes/state/fingerprint and HTTP smoke passed; original cron, mail blocker, noindex and protected records were preserved. Restricted server credential and a real submission-free lookup were already verified. [Completed release](../../wordpress/docs/releases/2026-10-13-logic-272948.md) · [Recovery history](../../wordpress/docs/releases/2026-10-13-mandatory-dispatch-held.md). Limited browser checks confirmed readonly/separate-reference/no-dispatch and unconfirmed-selection blocking, with no real submission. Actual Google selection/replacement remains unverified because the public queries tested did not yield selectable suggestions. The rest of this document preserves the original attempt's historical evidence and blockers.

## Authorization

Owner requested completion of the interrupted task shown in the screenshot. In this session, owner explicitly selected “Sí, configurar la clave y publicar en staging” after being asked to authorize a separate Places server key restricted to staging egress IP and a logic-tier release with backup and restoration rehearsal. Existing quotas must remain unchanged. Production is excluded.

## Completed verification

Ran `node wordpress/scripts/staging-release-gate.mjs` from an isolated clean clone of the committed source, with existing local dependencies. Clone-local Git excludes accounted for dependency symlinks; no tracked source, test, gate or release rule was changed. The dirty main working tree and other tasks' changes were not included, committed, reset or stashed.

Gate passed:

- 2671 aggregate syntax, dependency and deployment checks.
- 752 real disposable WordPress/Woo stack checks, including dispatch address provenance, request persistence, restricted sales boundary, quotation approval and issuance.
- 92 offline Places PHP checks; 43 Places JS checks; 630 pinned Woo/jQuery lifecycle checks.
- 99 native photo checks, 7 sealed migration checks and 5 CLI checks.
- Native quotation fault and independent-worker tests passed.
- Real mail suppressed; Google transport synthetic in native fixtures. No real provider operation or visual/device acceptance inferred.

Full local output: `/tmp/freeplast-dispatch-finish-SkwFNb/gate.log`. The gate removed its own temporary native stack. Outer source clone remains at `/tmp/freeplast-dispatch-finish-SkwFNb/repo` for follow-up.

## Original staging inventory and blocker (before configuration/release)

Read-only SSH/WP-CLI checks confirmed adapter **1.12.8**, browser `FREEPLAST_GOOGLE_API_KEY` configured, private `FREEPLAST_GOOGLE_PLACES_SERVER_KEY` missing. Pass inventory contains only `freeplast/google-places-browser`, not a separate server key.

Chrome Google Cloud credentials page redirects to Google's sign-in screen. No credentials were collected, key created, provider restrictions changed, quota changed, configuration written or release installed. The initial WP-CLI inventory incorrectly referenced a nonexistent version constant; the command failed without mutations, and `wp plugin get freeplast-woo --field=version` provided the correct version.

## Original next steps (configuration and release subsequently completed)

1. Owner signs into Google Cloud in the local Chrome (do not send passwords in chat).
2. Create a distinct server key restricted to Places API (New) and confirmed staging egress IP. Preserve quotas. Store through pass and private server configuration, never expose key in logs or browser.
3. Verify authorized real Places lookup from staging without creating a commercial request.
4. Use the canonical native release ceremony for logic tier: clean reviewed source, paired backup, isolated restore/trial, maintenance install, hashes/state/fingerprint/smoke, cron restoration and record. Do not include unrelated dirty work.
5. Verify actual staging browser selection, readonly address, replacement, references, no-dispatch and failure behavior. Submission/real mail require separate authorization. Report human/device limits explicitly.

**Initial handoff was incomplete:** publication was deliberately held rather than deploy without the required server key. Configuration and staging publication subsequently completed as recorded above; successful Google suggestion selection/replacement and keyboard/mobile/human acceptance remain pending. Production `freeplast.cl` was not contacted.
