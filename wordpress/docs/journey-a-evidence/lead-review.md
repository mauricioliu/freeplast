# Lead review — #41–#48 local implementation

Worker: **pi / zai-coding-cn/glm-5.3 high**, same live impl-1 pane throughout.
Lead personally reviewed and corrected each batch after worker DONE.
No commit/push, issue writes, deployment, server/bootstrap/HTTP/browser,
real mail or device actions. Main still based on aaba7e4; source A785e65b
and its separate worktree remain preserved. Implementation is uncommitted.

## Corrected at native boundaries

- #41: dialog focus/opener races, idempotence, narrow chrome specificity,
  fixture shop/Cart-block binding and checksummed catalog inputs.
- #42–#44: duplicate native add hooks; real catalog ordering/search/query
  state; complete AJAX projections including zero; actual positive-integer
  quantity constraints; disabled/rejected add behavior; native color select
  enhancement; password boundary; image-pending semantics; Woo CSS conflicts.
- #45–#47: six native checkout failures reproduced beyond helper mocks.
  Actual POST lifecycle owns busy state; live native errors preserve causes,
  radio targets and uncertainty; Editar waits for native draft/review save;
  native fields and hooks remain authoritative. Late Cart DOM, line-identity
  focus, conditional fields/errors, stored confirmation and missing-order
  refusal corrected. Quantity/recovery/notification engines preserved.
- #48: actual ClassicTemplate produced TWO breadcrumbs; scoped placement
  now produces one, with A's catalog/category presentation. Native extensions
  and ordinary trails remain intact. WordPress ALREADY supplies the skip
  link; native HTML-parser evidence replaces the incorrect absence claim.
  Narrow cart/form/confirmation gutters corrected against A.
- Evidence generator corrected: omitted input files and an empty version
  could previously look successful. Complete runtime/fixture/test families,
  selected actual native-source hashes and fail-closed behavior now tested.
- Runbook corrected: the HTTP gate stops its server; no imaginary live
  browser fixture or tailnet accessibility. No guessing product permalinks,
  accepting different images, equating throttling with body control, or
  treating HTTP500 alone as proof of pre-save failure.

## Executed result

`FREEPLAST_SKIP_STACK=1 npm test`: **504 adapter assertions and421 aggregate
checks**, all reported PHP/native/DOM/regression suites green. Added native
chrome13 and fingerprint6 checks. Exact output: `offline-gate.txt`.
Fingerprint: `fingerprints/integrated-48.txt` (stable across reruns).
Fixture generation/lint:17 products,15 checksummed photos,2 pending,
2 five-color products; no WordPress execution. CSS parsed528+86 rules;
`git diff --check` clean. Neither parsing nor DOM tests prove visual fidelity.

## NOT finished / NOT accepted

#48 remains partial. Native HTTP/database execution, enlarged pagination
fixture, hydrated public journey, matched-state browser matrix, real
screen-reader and owner PC/phone review remain UNRUN. A separately authorized
contained browser-fixture lifecycle is still needed. Known presentation gaps
(toast channel, extra copy/chrome) remain in `differences.md`; fix them or get
an explicit owner exception before acceptance. No exception or visual parity
has been claimed. Release is separately unauthorized.

Earlier worker reports in /tmp are historical handoffs, not authority over
this review, the frozen source, the final code or the explicit open gaps.
