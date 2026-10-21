# ASSAB S1-05 + S1-12 — Implementation Slice 01 (Gemini Pro High)

**Mode:** AUTHORIZED LOCAL CODE AND TEST CHANGES ONLY. No Commit/Push/PR/Merge/Rebase/Force Push. Do not stage files. No production deploy.

**Workspace:** `D:\claude\ASSAB ERP GITHUB BACKEND ANTIGRAVITY\Assab`
**Expected local branch:** `task/s1-12-dashboard-auth`
**Required base SHA:** `41f03a31929d29fdbb494ef906f8cbe80916c9ad`
**Design reference:** `ASSAB-S1-05-S1-12-ARCH-2026-10-12-v1` (`docs/sprint-01/s1-05-auth-security-blueprint-DRAFT.md` and `docs/sprint-01/s1-12-integration-contract-DRAFT.md`). Mahmoud has explicitly authorized **local implementation of S1-05 + S1-12 together**. Design approval and implementation permission are recorded separately.

## Gate 0 — local preflight (MUST precede any code change)

1. Inspect `git branch --show-current`, `git rev-parse HEAD`, `git merge-base --is-ancestor 41f03a31929d29fdbb494ef906f8cbe80916c9ad HEAD`, `git status --short --untracked-files=all`, and `git diff --name-only`. STOP if branch or ancestry differs, if another tracked change exists, or if the base has unexpectedly diverged. Do not checkout/reset/merge/rebase.
2. Previously reported local tree has **exactly two untracked baseline documents** under `docs/sprint-01`. Untracked files are **not a literally clean working tree**. Preserve their exact content. Before editing code, make a verified byte-identical backup OUTSIDE the Git checkout. To satisfy the literal clean-start requirement, temporarily move only these two backed-up documents outside the checkout, verify their SHA-256 hashes and that `git status --short` is empty. Do not delete, stash, stage, commit or overwrite. If a safe copy/move is impossible, STOP and report the blocker rather than treating the tree as clean.
3. Read the baseline from the safe backup while working. Restore the two files byte-for-byte to `docs/sprint-01/` after this slice and confirm hashes and their expected untracked status. Do not modify the approved baseline documents.
4. Note that the absence of the task branch on GitHub is expected because this is a *local* branch. Do not publish it.

## Slice 01 — additive Admin session persistence only

**Goal:** Introduce a non-operational, additive Admin session table and lightweight Eloquent model with SQLite tests, with **zero changes to live login/refresh/logout/routes or production behavior**. Do not attempt the entire auth lifecycle in one slice.

Allowed new files (verify existing layout and filename uniqueness before creating):
- `Modules/Admin/database/migrations/2026_10_12_000001_create_admin_sessions_table.php`
- `Modules/Admin/app/Models/AdminSession.php`
- `tests/Feature/AdminSessionPersistenceTest.php`

Do not modify existing tracked files in Slice 01. In particular, do not modify the existing shared `personal_access_tokens` migration or add `session_id` to the shared token table **yet**: the next slice will need explicit notice about shared storage and must be reviewed first. If the module's migration discovery differs, STOP and report instead of rewriting shared service providers or config.

Requirements for `admin_sessions` based on approved illustrative DDL:
- `id` UUID primary key, `user_id` UUID, nullable `company_id` UUID (platform admins have no company).
- Nullable device name/IP/user agent; `is_remember_me` boolean; integer unsigned generation default zero.
- Nullable unsigned `current_access_token_id`, `current_refresh_token_id`, and **immutable numeric** `legacy_access_token_id` (unique nullable index) for D09; do not tie alias integrity to an access token row that can be deleted during rotation.
- Timestamps for activity, absolute expiration, revocation, creation and update; indexes for user/revoked, company and expiration, with SQLite + MariaDB compatibility.
- Use Laravel schema builder and existing module conventions. No destructive migration or alteration of pre-existing schema. Do not hard-code company as non-nullable; do not mistake UUID user IDs for BIGINT. Avoid a premature foreign key to shared token storage; if a user FK is judged safe, explain and test it instead of adding silently.
- Model: explicit table, UUID semantics, fillable/guarded policy, appropriate datetime/boolean casts; no token issuance, observers, global guards, new service architecture or environment toggles in this slice.

Tests (SQLite `RefreshDatabase` as existing project standard):
- Module migration is actually discovered during test migrations and table exists.
- UUID user and nullable company permit platform Admin session persistence; linked company UUID works.
- Generation starts at zero; expiry/revocation dates round-trip, and alias can be null or uniquely assigned.
- `legacy_access_token_id` stays unchanged when simulated current token pointers/generation change (this is **schema-level** stability only, not a claim that refresh is implemented).
- Duplicate non-null numeric aliases fail the unique constraint; no impact to existing `personal_access_tokens` or Flutter data shape.
- Run `php artisan test tests/Feature/AdminSessionPersistenceTest.php` and a small relevant existing Admin smoke suite if dependencies are available. Do not report PASS unless tests actually ran. If PHP/vendor/database unavailable, record exact blocker and stop instead of modifying dependencies without permission.

## Exit report — STOP after Slice 01

Provide: preflight commands/results; exact three paths created or any deviations; `git status --short`, `git diff --name-only` and readable contents/diffs of each new file (untracked files do not show in plain `git diff`); test commands and PASS/FAIL/SKIPPED with actual output; unexpected shared-file dependencies; security/compatibility risks; recommended Slice 02 scope and **explicit pre-notice** before the shared `personal_access_tokens` additive migration. Do not proceed to Slice 02 autonomously. No Commit/Push/PR/Merge/Rebase/Force Push.
