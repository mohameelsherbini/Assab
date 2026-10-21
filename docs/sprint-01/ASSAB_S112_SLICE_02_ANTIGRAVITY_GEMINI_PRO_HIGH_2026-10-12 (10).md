# ASSAB ERP — S1-05 + S1-12 Implementation Slice 02 (Gemini Pro High)

**Task:** Additive Sanctum Token-to-Admin-Session Linkage — **local code and SQLite tests only**.

**Authorization:** Mahmoud explicitly authorized joint S1-05 + S1-12 implementation on 2026-10-12 (`S1-05 + S1-12 IMPLEMENTATION AUTHORIZED`). Shared-token-table change was separately **notified to Mahmoud before this slice**. This notification does not authorize work outside the approved baseline.

**Repository:** `mohameelsherbini/Assab`  
**Windows workspace:** `D:\claude\ASSAB ERP GITHUB BACKEND ANTIGRAVITY\Assab`  
**Required local branch:** `task/s1-12-dashboard-auth`  
**Approved base SHA:** `41f03a31929d29fdbb494ef906f8cbe80916c9ad`  
**Design baseline:** `ASSAB-S1-05-S1-12-ARCH-2026-10-12-v1`, especially S1-12 §4.2 and S1-05 D09/D10  
**Baseline files:** `docs/sprint-01/s1-05-auth-security-blueprint-DRAFT.md`, `docs/sprint-01/s1-12-integration-contract-DRAFT.md`  
**Previous slice:** Slice 01 created `admin_sessions`, `Modules/Admin/app/Models/AdminSession.php`, and `tests/Feature/AdminSessionPersistenceTest.php`; Gemini reported **8 passing feature tests** and **4 passing Admin smoke tests**. These are reported results, not authorization to change unrelated code.

## 0 — Mandatory preflight: do this BEFORE editing

1. Run and report the **actual** results of:
   ```powershell
   git branch --show-current
   git rev-parse HEAD
   git merge-base --is-ancestor 41f03a31929d29fdbb494ef906f8cbe80916c9ad HEAD; echo "ancestor_exit=$LASTEXITCODE"
   git status --short --untracked-files=all
   git diff --name-only
   git diff --cached --name-only
   git status --short composer.json composer.lock
   ```
2. **STOP** if the branch is wrong, HEAD/ancestry deviates unexpectedly, any tracked/staged code change is present, or any unexpected untracked file is present. Do not reset, checkout, stash, stage, commit, or otherwise try to manufacture a clean state.
3. The seven **known, expected untracked files** carried from Slice 01 are:
   - `Modules/Admin/app/Models/AdminSession.php`
   - `Modules/Admin/database/migrations/2026_10_12_000001_create_admin_sessions_table.php`
   - `tests/Feature/AdminSessionPersistenceTest.php`
   - `docs/sprint-01/s1-05-auth-security-blueprint-DRAFT.md`
   - `docs/sprint-01/s1-12-integration-contract-DRAFT.md`
   - `docs/sprint-01/ASSAB_S105_S112_IMPLEMENTATION_AUTHORIZATION_2026-10-12.md`
   - `docs/sprint-01/ASSAB_S112_SLICE_01_ANTIGRAVITY_GEMINI_PRO_HIGH_2026-10-12.md`
   If the user also placed **this Slice 02 task file** at `docs/sprint-01/ASSAB_S112_SLICE_02_ANTIGRAVITY_GEMINI_PRO_HIGH_2026-10-12.md`, report it as one additional, explicitly user-provided untracked instruction document; do not treat unrelated new files as approved. This is **not** a literally clean working tree. Report the distinction explicitly. The code cannot be committed/staged merely to hide this state. Confirm all four earlier document SHA-256 hashes against the Slice 01 exit report; preserve every known file. STOP if untracked contents differ unexpectedly or prior Slice 01 artifacts are missing.
4. Read the **actual local** S1-05/S1-12 baseline documents and inspect current `database/migrations/2025_10_09_121446_create_personal_access_tokens_table.php`, Sanctum token model/registration, `AdminSession.php`, module migration conventions, and relevant tests. If the requirements differ from this prompt, STOP and explain rather than silently changing architecture. Note: the baseline header saying *implementation authorization pending* is historical; Mahmoud's later explicit authorization supersedes **only that gate**.
5. Do not install/update Composer packages, change `.env`, or run migrations against a live/shared database. `composer install` has already completed successfully. Use the test SQLite database configured for Laravel tests.

## 1 — Strictly limited implementation scope

**Goal:** Introduce optional, indexed token-family linkage in shared Sanctum storage, without activating it on production auth flows. **Do not implement live issuance, login, 2FA, refresh, logout, session revocation, strict Admin middleware, or family cleanup in this slice.** Those belong to later slices.

Expected work:

**A. New additive migration** (verify name uniqueness first):
`database/migrations/2026_10_12_000002_add_session_id_to_personal_access_tokens.php`

- Add `session_id` as nullable UUID / `CHAR(36)` compatible field on the existing `personal_access_tokens` table; use Laravel schema builder and index named `idx_pat_session_id`. Target design: `session_id CHAR(36) NULL` and its index; physical column ordering after `tokenable_id` only where portable/appropriate.
- This is **an additive migration**. Do not edit the existing Sanctum migration, alter `tokenable_type`, `tokenable_id`, abilities, `expires_at`, token hashes, primary keys, or existing data.
- Existing rows must remain `NULL` for `session_id`; new **legacy/mobile** token creation must also keep `NULL` by default. No global custom Sanctum model/provider override.
- The `down()` method should remove only this slice's new index and column. No destructive rebuild of existing token rows.
- Do not add a foreign key not present in approved §4.2. Do not write a backfill or auto-attach legacy token rows to session families.

**B. Minimal Admin-only query support, if useful and verified safe:**
- Prefer a single **read-only** `tokens(): HasMany` relationship on existing **new** `Modules/Admin/app/Models/AdminSession.php`, pointing to the existing Sanctum personal access token model through `session_id`; or omit it if verification shows it would require customization of global Sanctum behavior. Do not introduce new architecture or service classes just for this slice.
- Allow tests to explicitly set the `session_id` field on a created test token **inside tests only** to validate persistence and lookup. **Do not** wire session association into production `createToken()` or change default abilities here.
- Treat `session_id` only as storage linkage. It does **not** by itself prove token owner, active session, type/ability, expiry, or authorization. Those checks are mandatory for future auth lifecycle/strict guard slices.

**C. New targeted tests** (prefer one new file):
`tests/Feature/AdminSessionTokenLinkageTest.php`

Prove at minimum:
1. SQLite migration adds `session_id` and named index to the shared table; existing token schema remains compatible.
2. Standard/legacy `AsabUser` token created using existing `createToken()` still has `session_id = NULL` without extra required fields or authentication behavior changes.
3. A test token can be explicitly linked to a persisted `AdminSession` UUID and looked up by that UUID (and through the relationship, if one was added).
4. Multiple tokens can be associated with the same family as needed for access + refresh; unrelated tokens remain unbound. No auto-backfill.
5. Existing Slice 01 tests stay green, including the forward-compatible standard Sanctum token assertion.
6. Where practical, verify migration rollback/reapply **against an isolated SQLite test database only**, without risking unrelated schema, data or changing the project's main migrations. If unsafe, explicitly mark it not run and explain.

Keep the tests schema-focused; **do not fake concurrency security or claim token ability enforcement** before the respective slices. Use existing test conventions and minimal fixtures.

## 2 — Tests and preservation gates

Run at least:
```powershell
php artisan test tests/Feature/AdminSessionTokenLinkageTest.php
php artisan test tests/Feature/AdminSessionPersistenceTest.php
php artisan test tests/Feature/AdminSubscriptionDateTest.php
```

Run `php -l` on every new/modified PHP file. Prefer a combined targeted run if helpful. If tests fail, make the **minimum in-scope corrections** and re-run. Never claim PASS from a command that was not actually executed.

Verify no changes to `composer.json`, `composer.lock`, `config/sanctum.php`, the existing `personal_access_tokens` migration, Flutter, Dashboard, Financial Logic, Shared Idempotency, current Login/Refresh/Logout, SSO, Invitations, or Impersonation; and confirm design/authorization document SHA-256 hashes remain unchanged.

## 3 — Known follow-up risk (do NOT misreport as solved)

Slice 01's `legacy_access_token_id` uniqueness and rotation-simulation test do **not** enforce complete one-time immutability against an explicit write after assignment. Carry this as an explicit open D09 implementation/verification item for the real family issuance/rotation slices; do not claim it is resolved by the `session_id` column alone. If implementing this safely now would require additional scope, report the recommendation and stop rather than expanding Slice 02.

## 4 — Exit report: STOP before any further slice

Report concisely but with verifiable evidence:
- Branch, HEAD, ancestor check, full `git status --short`, all known untracked artifacts, and unexpected changes (if any).
- Exact files created/modified, and **full readable contents/diff** of every new or untracked code file involved. Plain `git diff` will **not** show untracked files; include them explicitly so an independent reviewer can inspect the actual implementation.
- Actual test commands, exit codes and pass/fail counts; PHP lint; migration rollback test status.
- Explicit mobile/legacy token `session_id=NULL` evidence, absence of global Sanctum/Auth behavior changes, and any compatibility risks.
- D09 alias-immutability remaining risk; any MariaDB/InnoDB behavior not proven by SQLite. This slice is **not** the MariaDB concurrency audit.
- Decisions needed, if any. **Do not begin Slice 03 automatically.** Send results back to the user for ChatGPT WORK review, followed by the planned independent **Codex Sol Medium read-only audit after Slice 02** using the actual files/patches and pinned base SHA.

**Hard prohibitions:** NO Commit, Push, PR, Merge, Rebase, Force Push, staging (`git add`), strict production activation, global Sanctum TTL change, edits to Flutter/Dashboard, financial/shared-idempotency code, or standalone fixes to SSO/Invitations/Impersonation. If additional shared files or wider changes are needed beyond the notified `personal_access_tokens` column migration, **STOP and request coordination before touching them**.
