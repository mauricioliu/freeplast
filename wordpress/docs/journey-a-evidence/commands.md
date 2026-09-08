# Command record and remaining execution gates

## Executed — offline only

- `FREEPLAST_SKIP_STACK=1 npm test`: adapter/render/projection tests, actual
  pinned hooks/field renderer/Cart store/checkout handlers under intercepted
  I/O, DOM behavior, operator-flow selftests and syntax/dependency checks.
  Exact final output: `offline-gate.txt`. This does NOT bootstrap WordPress,
  exercise live HTTP/database persistence or render a browser.
- `node --check …`, `wordpress/.tools/php/php -l …`, `git diff --check`:
  syntax/whitespace only.
- Offline fixture-payload generation and PHP lint:17 reference products,
  15 checksummed photographs,2 pending and2 five-color products. Generated
  code was not executed in WordPress.
- `bash wordpress/docs/journey-a-evidence/fingerprint.sh`: immutable A blobs,
  complete runtime/fixture/test input families, actual cached native probe
  sources and nonempty versions. Missing inputs fail; no fetching occurs.
  Snapshot: `fingerprints/integrated-48.txt`. Generator `--help` is read-only.

No server/bootstrap/HTTP/browser/device/adb, real mail, deployment,
commit/push or GitHub write was performed during these implementation batches.

## A — full native HTTP gate (NOT EXECUTED)

Requires explicit owner authorization for the particular temporary run.
A lead may relay that permission, not invent it. Verify fixture provenance,
mail containment and the existing `verification-safety.md` preconditions.
This command is forbidden under the CURRENT offline-only authorization:

```bash
# ONLY AFTER the owner separately authorizes this bounded native-stack run:
cd ~/Projects/freeplast
bash wordpress/docs/journey-a-evidence/fingerprint.sh > /tmp/journey-before.txt
npm test
```

Without `FREEPLAST_SKIP_STACK=1`, this starts the disposable loopback stack,
seeds its fixture, executes the implemented HTTP/race/permission scenarios,
then stops its server and checks that the port is free. It does **not** drive
a hydrated browser or establish the entire visual/keyboard journey.
Inspect its own logs/event counts (never infer mail delivery), record its
results and a post-run fingerprint. Do not weaken its origin/port guards.

## B — browser and phone-accessible fixture (BLOCKED, not a runnable setup)

A completed procedure A leaves **no live server**. Its loopback-only listener
would not become tailnet-accessible merely by substituting `mliu` in a URL.
There is currently no checked-in keep-alive browser-fixture launcher in this
package. Do not treat the prepared matrix as an executable setup recipe.

Before authorizing/running that matrix, prepare and review a separate bounded
fixture lifecycle:

1. Fresh disposable data with verified provenance; unchanged dependency pins.
2. Independent mail containment verified before mutations; own-record-only
   failure injection/verification, event counts and cleanup. Never activate
   real email/contact links as a test.
3. An explicitly authorized temporary listener/forwarder, known port owner,
   consistent fixture home/site/asset/AJAX origins and teardown. A tailnet
   preview must use `http://mliu:<port>/…`; no production redirect or mixed
   origin. Never repoint or bypass the guarded HTTP harness.
4. A separately authorized reference preview from the preserved A worktree;
   its `npm run preview` command exists and binds to the Tailscale interface,
   but was NOT run here. Check port ownership and stop only owned processes.
5. Real fixture links determine product/category/checkout URLs; do not guess
   permalink bases. Freeze equal products, photos, quantities and states.
6. Controlled response-body/failure instrumentation where required. Generic
   DevTools throttling can show loading but does not prove a held incomplete
   body or a pre-save rejection. Verify persistence independently.

Only then execute `visual-matrix-procedure.md`. Capture actual results;
never fabricate screenshots, claims of pixel equality or hardware evidence.

Final PC/phone and real screen-reader observations, exceptions and acceptance
belong to the owner. No deployment is part of either future procedure.
