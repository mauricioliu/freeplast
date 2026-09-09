# 0002 — Release ceremony is a fixed contract, harden-only

**Status:** accepted (2026-09-09)
**Context:** [ADR-0001](0001-woocommerce-quote-only.md) · operating record in [wordpress/WOO-MIGRATION.md](../../wordpress/WOO-MIGRATION.md) · skill `~/.agents/skills/wp-release/`

## Context

Between 2026-09-07 and 2026-09-08 the staging store received eight releases, each assembled ad hoc: backup + restore rehearsal + trial upgrade + install + verification, with hand-written scripts per release under `/tmp/freeplast-publish-<stamp>/` and a hand-written record. Each release took 30–60 minutes of agent time, and each discovered new friction (tar ownership, bundle-path hashing) because the pipeline was regenerated rather than reused.

The ceremony itself is not overhead to remove. The site holds real catalog, orders and request history; the server policy (OPENCLAW.md) is that a backup which has not completed a restore rehearsal is not release evidence, and releases happen only on explicit owner instruction. The ceremony is what makes it safe to release to *any* WordPress site with real data — freeplast today, other projects (including shared-hosting targets like Hostinger) tomorrow via the `wp-release` skill.

## Decision

The release ceremony is a **fixed contract** owned by the `wp-release` skill, parameterized per project by `wp-release.json`:

- Fixed phases: `gate → package → transfer → backup → rehearse → trial → install → verify → smoke → record`, plus a generated rollback. Every release, of every tier, pays backup + restore rehearsal; *logic*/*data* tiers additionally pay a trial upgrade against the restored copy.
- Three tiers — **surface / logic / data** — classify the change set; the agent proposes from the diff, the owner confirms; a mixed diff takes the highest tier. Tiers are never silently downgraded.
- Projects may **harden** the contract (add checks, add a trial where the minimum doesn't require one) but never **weaken** it (no skipping the rehearsal, no bypassing owner authorization for mutating phases, no blind database restores).

## Consequences

- "Why can't I skip the rehearsal for a CSS change?" — because the minimum is what makes the skill safely reusable across projects whose data we cannot inspect. A surface release keeps the ceremony cheap (~minutes) rather than making it optional.
- Per-release agent work becomes: classify the tier, quote the owner's authorization, fill the record's narrative. The mechanics live in the skill and are self-tested against a fixture.
- Freeplast retires ad-hoc `/tmp/freeplast-publish-*` script generation; its existing hooks (`npm test`, `package-woo.py`, `verify-woo-state.php`, `record-state.php`) are declared in `wp-release.json` and keep their authority.
- If a future project genuinely needs a lighter process, it gets its own process — it does not get a weakened `wp-release`.
