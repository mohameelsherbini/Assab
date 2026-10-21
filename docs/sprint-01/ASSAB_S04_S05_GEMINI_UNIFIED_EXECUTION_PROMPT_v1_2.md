# ASSAB ERP — SLICES 04 + 05 — SINGLE CONSOLIDATED EXECUTION ORDER v1.2
## Atomic Refresh + Concurrent Revocation + Credential Invalidation

**To:** Gemini Pro High, Antigravity (local Windows)
**Backend repo:** `mohameelsherbini/Assab`
**Working tree:** `D:\claude\ASSAB ERP GITHUB BACKEND ANTIGRAVITY\Assab`
**Expected local branch:** `task/s1-12-dashboard-auth`
**Foundation reference:** `41f03a31929d29fdbb494ef906f8cbe80916c9ad`
**Architecture baseline:** `ASSAB-S1-05-S1-12-ARCH-2026-10-12-v1`
**Authorization:** Mahmoud's separate `ASSAB_S105_S112_IMPLEMENTATION_AUTHORIZATION_2026-10-12.md`, plus the current instruction to implement local S1-12 Slices 04 and 05 as one package.
**Reference documents:** `docs/sprint-01/s1-05-auth-security-blueprint-DRAFT.md`, `docs/sprint-01/s1-12-integration-contract-DRAFT.md` and the approved baseline/authorization. Draft text is design source material, not a new approval; when historical draft text conflicts with an explicit later approved decision, follow the later decision and document it.

**IMPORTANT — THIS IS THE ENTIRE TASK.** This one message supersedes the previous separate combined implementation prompt and its addendum. Read it fully before acting. Do not assume another prompt will follow. Apply every applicable requirement below; do not simply implement the items easiest to code.

## 0. Authorization, boundaries, and decision hierarchy

**Authorized now:** local Backend implementation and local isolated tests for Slice 04 (atomic Admin family Refresh) and Slice 05 (Logout, revocation, password/account invalidation), including narrowly necessary coordination of existing Admin credential writers explicitly covered by the approved S1-12 authorization. Preserve Slices 01–03; do not recreate their work.

**Do NOT:** stage, commit, push, open PR, merge, rebase, force-push, reset/clean, publish the local branch, deploy, modify production or uncertain external databases, change Dashboard or Flutter code, alter Foundation/main/upstream, change financial/idempotency logic, adjust global Sanctum expiry, activate production Strict Mode, silently repair SSO/Invitation/Impersonation, or implement Slices 06/07 as a hidden expansion. Keep SSO/Invitation/Impersonation and HOST-01 release blockers separate. Mahmoud retains final technical acceptance; independent AUDIT remains separate.

**Scope/approval stop rule:** inspect all related routes/writers, but do not silently modify Mahmoud's separate work or an unapproved cross-module/shared file. If correct security behavior requires an out-of-scope edit, infrastructure change, schema/design expansion, or disputed approval, STOP, identify the exact file/invariant and a minimal proposed change, and request explicit authorization. A requirement blocked by approval cannot be declared complete. Do not interpret the request for completeness as permission to override this rule.

**Transition non-negotiables:** `admin.session_families_enabled` remains false by default; legacy Admin Compatibility Mode continues; Flutter/Cashier/BranchManager tokens and their lifetimes remain unchanged; no global Sanctum token expiry. Do not turn off the feature flag and reinterpret a family-linked token as legacy. Family state is authoritative even during rollback.

**Recent audited starting point:** Slice 03 independent verdict was `PASS WITH CONDITIONS`, with two P3 package/comment items subsequently corrected by Gemini (not yet independently re-accepted in the supplied history). Gemini reported the post-P3 `AuthService.php` SHA-256 `DCEECDE25AA0D89C54F406F108CB764F1BCB5D070757AFE47E4B7818B46B2C5F`; earlier independently checked SHA-256 was `77940AFB2DB5C7C0661A9DBFA468997D305FDAF8F60EA1EA3163B50956A07A72`. The change must be characterized and the actual current hash measured; do not mistake the historic SHA for current truth. Last independent Slice 03 tests were 95 tests/462 assertions per engine; a later targeted family suite was developer-reported 34 tests/213 assertions. Never present those historical counts as Slice 04/05 results.

## 1. Phase 0 — required preflight BEFORE editing any Slice 04/05 code

1. Verify local branch name, HEAD, ancestry, `git status --porcelain=v1` and full status; distinguish modified, untracked, staged and external evidence. Confirm working tree already contains legitimate uncommitted Slice 01–03 work. Never stash, delete, overwrite or stage it to manufacture a clean tree. Record pre-task file inventory and hashes, and preserve the initial diff as the baseline for this task.
2. Verify GitHub Foundation reference and compare actual local HEAD. Do not assume published task branch exists; remote branch absence is not evidence of local branch absence. Note any unexpected SHA drift before coding.
3. Read the immutable approved architecture, implementation authorization and relevant integration contract sections fully; identify approved decisions D01–D16 and any historical draft ambiguity. Identify what belongs to Slices 06/07 or S1-13 and remains deferred.
4. Confirm Slice 03 P3 housekeeping state without restarting a full Slice 03 audit. Record independent verdict versus Gemini-only follow-up and current `AuthService.php` fingerprint.
5. Inspect the **actual local** `AuthService.php`, `AuthController.php`, `AdminSession.php`, Sanctum token model, configuration and routes, `TwoFactorService`, session delete, device cleanup, `CredentialSyncService.php`, password reset/forgot/resend, account/company/membership invalidation writers, migrations and their callers. Map all direct and indirect writes before design choices.
6. Verify isolated disposable MariaDB 11.4.12 / InnoDB at `127.0.0.1:3307`, effective database identity, non-production credentials, isolation level, transaction behavior, shared Laravel Database Cache, PHP/PHPUnit, and two or more independent PHP processes and database connections. Confirm test fixtures and current logs do not cross into production. If environment unavailable, resolve only authorized setup or STOP; never claim SQLite proves MariaDB lock behavior.
7. Verify no tests write to the unknown Hostinger endpoint or other external/production services. Do not use real employee/company/finance data for destructive tests.
8. Inventory which current writers/services use which DB connection. Before relocating `pushToMobile()` or any other side effect, determine whether it is transactional local DB work, cross-connection DB work, or truly external I/O. Do not assume an outer transaction spans multiple connections.
9. Confirm precise API compatibility with Backend Dashboard callers and Flutter reference behavior, using read-only inspection only; do not broaden client changes.
10. Produce `S04-S05_REQUIREMENTS_MATRIX` and a writer/lock inventory **before implementing**. Freeze requirement identifiers; append discoveries, never drop or silently reclassify a row.

If an essential approval/environment/schema blocker is found here, **report PARTIAL/BLOCKED and wait** rather than coding past it.

## 2. Requirements matrix and architecture invariants

Create a machine-readable requirements matrix stored outside the working source tree where appropriate. For **each** S04/S05 row capture:

`Requirement_ID, Approved_Reference, Slice, Writer_ID, Caller/Endpoint, Implementation_Path, Transaction_Boundary, Database_Connection(s), Lock_Order, Expected_Behavior, Positive_Test, Negative_Test, Concurrency_Test, Race_Order_A_Expected, Race_Order_B_Expected, Code_Status, Functional_Test_Status, Concurrency_Status, Evidence_Status, Run_ID, Start_SHA256, End_SHA256, Evidence_Path, Open_Blocker/Approval`.

Statuses must distinguish `NOT_STARTED`, `IMPLEMENTED`, `PASS`, `FAIL`, `BLOCKED`, `NOT_VERIFIED` and `DEFERRED_TO_SLICE_06/07`; a deferred row needs its approved ownership and release impact. Rows marked applicable to 04/05 require **implemented code + passing functional tests + relevant genuine concurrency evidence + matching source hashes**. Do not replace missing proof with claimed completion.

Security invariants across all applicable endpoints and writers:

- Exact stored abilities: Admin Access `['admin:access']`, Admin Refresh `['admin:refresh']`; reject `['*']`, mixed/wrong abilities, wrong tokenable types and unauthorized legacy fallback on **family-specific operations**.
- Session family ID remains a stable UUID; immutable numeric legacy alias and absolute `expires_at` survive rotation. Standard family duration max 24 hours; explicitly selected Remember Me max 7 days. Access expiry `min(now + 15 minutes, family.expires_at)`; Refresh never outlives family; no sliding absolute lifetime.
- Acquire locks consistently: **User → Session(s) → Token(s)**. Where many sessions, order session UUIDs; where many tokens, order numeric token IDs. Add other locks (reset-record, peer users, memberships) only after documenting a globally acyclic ordering across **all** affected routes, including Forgot/Resend and Mobile sync. Preliminary lookups are never security decisions.
- Credential changes, account/company/membership eligibility invalidation, Login/2FA admission, Refresh and family revocation must serialize correctly. **No unauthorized credential may be issued or survive after committed invalidation**.
- No test-only production security override, policy bypass, unapproved credential-version column or new schema merely to simplify tests. If an approved invariant cannot be enforced without design change, STOP for a decision.
- Production code and tests must preserve existing response-envelope conventions, device identities, legacy Admin flow, SSO/Invitation boundaries, Mobile behavior, and feature-flag rollback safety.

## 3. SLICE 04 — atomic Refresh, all mandatory requirements

### S04-01: Exact classification and authorization

Keep `POST /api/v1/auth/refresh` with request `{ "refreshToken": "..." }`. A presented family Refresh Token must be validated for: correct opaque Sanctum secret/hash; `AsabUser` tokenable type and ownership; **exact** stored ability array `['admin:refresh']`; correct linked `session_id`; correct family's current refresh pointer and generation; positive not-expired token and family lifetime; unrevoked family; active User and applicable company/membership access. Token ID, client generation or preliminary lookup alone never establishes authority. Reject malformed, Access-only, wildcard, mixed, superseded/consumed, foreign-model, cross-user and orphan/mismatched credentials.

Use explicit classification: family-linked credentials follow approved family handling **even when feature flag is disabled**, or are safely rejected per documented rollback; never fall back to legacy after failed family validation. Only genuinely familyless eligible **legacy Admin** Refresh credentials may reach the preserved compatibility path. Do not classify every missing/invalid family pointer as legacy. Never use `tokenCan()` as proof of exact stored abilities.

### S04-02: Transaction and lock ordering

A preliminary token lookup may identify candidate User/Family, but is non-authoritative. Enter one transaction and lock User first, Session second, tokens in deterministic ascending ID order. Re-read and revalidate all relevant properties **after waiting for the locks**. Ensure any other service, callbacks, observers and invalidating writers use a compatible lock order. Keep transactions tight; no external network I/O inside locks; no accidental nested/cross-connection atomicity assumptions.

### S04-03: One successor, exact state

On one successful rotation, atomically:

1. Verify family and generation under locks.
2. Invalidate/delete **both** former Access and Refresh tokens.
3. Issue exactly **one** new Access and **one** new Refresh with exact separate abilities and family linkage.
4. Update the current token pointers and advance generation **once**.
5. Preserve family UUID, immutable numeric legacy alias, expiry and device identity.
6. Bound new Access expiry and `expiresIn` to remaining absolute lifetime (integer seconds, no tokens at zero); bound Refresh expiry to family expiry.
7. Commit a coherent state with no orphan tokens or partial generation changes.

Do not add `generation` or other mandatory fields to Dashboard's request. Derive current generation from locked family state/pointers.

### S04-04: HTTP contract

Keep current response envelope and approved additive successful fields: `accessToken`, `refreshToken`, `expiresIn`, `sessionId`, `expiresAt`, `sessionExpiresAt`; preserve compatible user payload if present. Existing legacy clients must not break. Do not invent new required request fields. Actual status/error must be tested via production route/service.

### S04-05: Refresh collision

Two concurrent refresh requests with **the same** credential must produce at most one committed successor pair. Loser after waiting/revalidation returns `401 INVALID_TOKEN`. `409 REFRESH_IN_PROGRESS` is allowed **only** with concrete proof that the same generation is currently being rotated, never simply because lock timeout occurred or old token was consumed. Prefer the minimal wait-and-revalidate design over ad hoc coordination.

### S04-06: Fail-closed and uncertain outcomes

- Confirmed invalid/consumed/superseded refresh: `401 INVALID_TOKEN`; never replay it.
- Confirmed expired/revoked family: `401 SESSION_EXPIRED`.
- Confirmed inactive User: `403 USER_INACTIVE` (apply approved eligibility handling to other ineligibility).
- No 60-second grace, replay result cache or encrypted recoverable secret store in v1.
- A network timeout, 5xx, transaction deadlock or lock timeout is **ambiguous**, not proof of token consumption or successful logout. Do not mislabel as confirmed `401` or live `409` without proof.
- Test lost response **after commit**: server issues only one successor; replaying the consumed credential returns `401 INVALID_TOKEN`; no second issuance.
- Dashboard cross-tab coordination, late-response handling and browser storage choices remain S1-13/D12 release dependencies; do not implement them in this Backend slice.

### S04-07: Compatibility and rollback

Preserve existing familyless Admin legacy Refresh logic in Compatibility Mode and its response shape; no global token expiry change; no disruption to Cashier, BranchManager, Flutter, SSO/Invitation token issuers or shared `issueTokens()`. Flag false **does not** convert an already linked family token to legacy/wildcard or restore any consumed token. Test flag-on/flag-off classification and safe rollback. Strict Admin Guard belongs to Slice 07 and is not silently added here.

## 4. SLICE 05 — Logout, revocation, credential invalidation, all mandatory requirements

### S05-01: Atomic Family Logout

For authenticated family Access, identify true owner, lock User → Session → Tokens, verify current/authorized credential and family ownership/generation, mark family `revoked_at`, invalidate **both** active tokens, and commit atomically; prevent any later Refresh from reviving the family. Return approved `204 No Content` on successful logout. Revoking Family A must not revoke unrelated Family B. Avoid trusting IDs alone or stale preliminary reads.

### S05-02: Expired Access logout (D08)

Preserve `POST /api/v1/auth/logout`, with minimal secure adaptation for two proof modes:

- Valid Access Bearer for normal logout.
- Valid **current** Refresh Token secret in body for expired/missing Access logout, checked with exact hash, `AsabUser`, exact `['admin:refresh']`, owner, non-expiry and current family/generation under locks.

A bare `sessionId`, alias, guessed ID, stale Refresh, consumed/revoked/foreign token cannot authorize family deletion. Do not create a generic public revocation endpoint; preserve legacy logout in Compatibility Mode and existing error envelope. Keep feature disabled by default; no global Sanctum guard change.

**Order-sensitive result:** if Refresh commits first and consumes the Refresh secret provided to expired-access Logout, the stale Logout may correctly return `401`; never call it successful revocation. If Logout returns `204`, verify no usable successor credential survives **for that family**. If Logout authenticates with a still-current valid credential after Refresh, it must revoke the then-current pair according to approved behavior.

### S05-03: Device / FCM cleanup

Atomic family revocation is authoritative even when device cleanup fails. Inventory existing device registration/unregistration behavior and determine if cleanup is transactional DB-only or fallible external I/O. Honor existing device ownership and cleanup semantics; do not unregister another User or device family. For truly external cleanup, use an approved safe post-commit approach; for database writes required inside a transaction, evaluate consistency rather than blindly moving calls out. Record failure safely, identify an **actually existing** retry mechanism (or explicitly say manual recovery required); do not silently swallow errors and claim recovery. Do not add a new queue/worker/infrastructure without approval. Never log FCM secrets/tokens.

### S05-04: Password Change, including concurrent changes

- Recheck the presented current password against the **locked** current User record, not a preliminary/stale object.
- Update credential and revoke **all affected Admin families** atomically while following User → ordered Sessions → ordered Tokens.
- Coordinate Login/2FA issuance and Refresh under the same User admission lock so no credential created before completed invalidation remains valid.
- Two simultaneous requests with the same old password: at most one may pass credential revalidation after serialization. Record both responses and final password state.
- Preserve existing Mobile credential synchronization, peer/account rules and Mobile token policies.
- Inspect `pushToMobile()` and `CredentialSyncService` before deciding transaction boundaries: local peer writes may need shared transaction, but cross-connection writes are not magically atomic. Record actual DB connections, peer lock order and possible partial-failure hazards; STOP if safe design needs broader approval.

### S05-05: Password Reset + Forgot/Resend

Keep reset API contract, no account enumeration, existing mailer and credential-sync behavior. Implement exact anti-replay and expiration:

1. Preliminary lookup of presented reset secret is non-authoritative.
2. Identify candidate User, acquire User lock first and establish a safe, documented global position for reset-record locks relative to Sessions, Tokens, peer Users and Forgot/Resend paths.
3. Re-read the **actual** reset record under protection and revalidate exact presented secret, ownership, and time.
4. Treat reset secret as expired when `now >= created_at + 60 minutes` (test just before, exactly at, and after 60m). Do **not** retain the old signed `diffInMinutes(...) > 60` calculation without proving equivalent boundary behavior.
5. Consume **only the exact validated** reset record/version, without deleting a newer secret issued by concurrent Forgot/Resend. Do not allow two uses of the same secret.
6. Change password, invalidate affected Admin families and consume the correct reset credential transactionally according to the approved lock plan. Avoid cross-connection false atomicity and rollback inconsistencies.
7. Test Reset vs Reset, Reset vs Forgot, Reset vs Resend, Reset vs Refresh and Reset vs Login, including both relevant commit orders. No secret/token disclosure in logs.

If current schema cannot distinguish an older replaced reset credential or enforce the invariant without unapproved changes, present the smallest design decision and STOP rather than inventing unsafe behavior.

### S05-06: All invalidating writers and eligibility

Inventory and appropriately coordinate every live writer that may invalidate Admin authentication or eligibility, including:

- Admin password change/reset and related credential-version/security-state transitions.
- `CredentialSyncService.php` **Mobile → Admin** writes (including any token deletion).
- User deactivation/reactivation where relevant.
- Company suspension/deactivation and membership/role eligibility writers affecting Admin admission.
- Any additional writer discovered in the actual code, including its indirect callers.

For each, record `Writer_ID`, entry point, affected rows, connection, locks, transaction and tests. **If invalidation commits, no unauthorized Login/2FA/Refresh successor may survive.** Verify both serialization orders. Recheck active/eligibility and relevant credential security state under User lock. Avoid changing unrelated company/business/financial logic. If a writer is in separately owned scope, STOP for explicit authorization with precise path and impact; do not silently omit it or mark PASS.

**Mobile → Admin specific:** test Mobile-origin password change against Admin Refresh, Admin Login and 2FA completion, without modifying Mobile token policies. Map actual peer locks, connections, local database writes and external side effects before changing `pushToMobile()` placement.

### S05-07: Device/family isolation and ownership

Test Family A logout leaves independent Family B intact (except deliberate account-wide invalidation), different users cannot revoke each other, expired/revoked families never reactivate, reset affects the correct families, familyless legacy Admin stays compatible, and foreign tokenable types cannot revoke Admin families. Verify exact token pointers and abilities in DB.

### S05-08: Existing `DELETE /api/v1/auth/sessions/{id}` — narrow protection NOW

**The endpoint already exists.** Its current `revokeSession()` removes an individual token. Do **not** call the entire endpoint future/nonexistent. Inventory it in lock/writer map and prevent partial family corruption now:

- An existing request must not delete only one credential of a new family and leave a bypassable or internally inconsistent pair.
- Keep current legacy familyless Admin DELETE semantics and ownership checks.
- For new families, either implement the **narrow approved-safe** family-aware revocation with shared locks, or explicitly reject unsupported family operations until Slice 06. Choose based on approved scope and actual code; if scope uncertain, STOP and request permission.
- Protect against cross-user guessing and unsafe token-ID/UUID interpretation.
- Test Refresh vs the **existing** DELETE behavior, with production route, real independent connections and a final DB check where applicable.

The full D09 list/UUID/numeric-alias resolution and session enumeration remain **DEFERRED TO SLICE 06** unless separately approved. Do not use this protection as permission to implement all of Slice 06.

## 5. Admission-versus-invalidation: mandatory coverage beyond Refresh

All Admin family issuance methods must revalidate security state **under User lock**, including standard Login, direct Password+Code login, 2FA challenge completion, and any approved family issuer. Reuse Slice 03 correctness; do not rewrite its 2FA design without evidence.

**C14** Login vs Password Change: invalidation first → old credentials fail; Login first → subsequent invalidation revokes its family.

**C15** Login vs Password Reset: same result, plus reset credential consumption and correct new password state.

**C16** 2FA Completion vs Password Reset: old pre-reset challenge cannot issue family after committed credential invalidation; verify stored challenge's credential/security binding or approved equivalent under lock. Never trust a stale preliminary read.

**C17** 2FA Completion vs User/Eligibility Deactivation: old challenge cannot issue a family after deactivation commits.

**C18** Direct Password+Code vs Password Change/Reset/Deactivation: obey identical locking, current password and eligibility checks.

**C19** Password Change vs Password Change: concurrent requests using old password do not both succeed based on the old state.

**C20** Reset vs Reset with identical secret: at most one password change; reject second use even if request observed same record before lock.

Also test **Login/2FA vs Mobile-origin credential update**. Where a security version or challenge invalidation marker is required but absent, do not invent an unapproved column or pretend a password/hash comparison at the wrong moment suffices; document proof or escalate.

## 6. Genuine MariaDB/InnoDB concurrency test catalogue

Use isolated MariaDB, real production entry points (HTTP route/service as appropriate), multiple PHP processes, separate DB connection IDs and **deterministic synchronization barriers**. For every real race, record `barrier → lock holder → commit order → response A → response B → post-commit SQL state`. Any worker that manually simulates just part of a production flow must be labeled **PARTIAL SIMULATION / NOT FULL PATH VERIFIED**, never passed off as full production concurrency evidence.

| ID | Mandatory case and acceptance invariant |
| --- | --- |
| C01 | Refresh vs Refresh on identical secret: exactly one successor; loser `401 INVALID_TOKEN`; correct generation/pointers. |
| C02 | Refresh vs standard Logout: both orders, with accurate stale-proof `401` distinction; any confirmed `204` leaves revoked family with no successor. |
| C03 | Refresh vs Password Change: both orders, no successor survives committed password invalidation. |
| C04 | Refresh vs Password Reset: both orders, reset consumed once, no surviving unauthorized pair. |
| C05 | Refresh vs User Deactivation: both orders; refresh rechecks locked eligibility; reject inactive state. |
| C06 | Refresh vs relevant Company/Membership writer: both orders where in approved scope; no unauthorized successor. |
| C07 | Refresh at/after family absolute expiry: no issuance; correct expiry response. |
| C08 | Refresh rollback/partial failure: coherent old/new token and generation state, no orphan pair. |
| C09 | Two independent device families refresh: no cross-family corruption; each can rotate correctly. |
| C10 | Expired-Access Logout using Refresh proof vs Refresh: consumed-proof `401` is distinct from successful `204`; no surviving successor after successful revoke. |
| C11 | Lock timeout/deadlock: rollback is sound; do not invent `409` or falsely assert token consumed. |
| C12 | Legacy Admin, Mobile/Cashier/BranchManager, SSO/Invitation issuer boundaries: unchanged; no new global expiration. |
| C13 | Refresh vs **existing** Session DELETE: protect the live endpoint now (supported family revocation or explicit safe rejection); defer only full D09 semantics. |
| C14 | Login vs Password Change: both orders; locked revalidation. |
| C15 | Login vs Password Reset: both orders; locked revalidation. |
| C16 | Old 2FA challenge completion vs Password Reset: cannot mint a fresh family after invalidation. |
| C17 | Old 2FA completion vs Deactivation/Eligibility change: cannot mint after invalidation. |
| C18 | Direct Password+Code Login vs invalidation: current locked state governs. |
| C19 | Two Password Change requests: second cannot pass with stale old password. |
| C20 | Two Password Resets on same secret: exactly one consumption/update. |

**Additional required cases:**

- Mobile → Admin credential change vs Refresh, Login and 2FA completion.
- Password Reset vs Forgot and vs Resend, including older-secret vs newly issued-secret race.
- Feature flag true→false while a linked family token exists: never downgrade to legacy.
- Lost Refresh response after committed rotation: old credential 401, no second pair.
- Regular Login/2FA vs company/membership invalidation as applicable.
- FCM cleanup failure following committed Logout: revocation remains effective, failure recorded, no fake retry claims.
- Owner mismatch, forged/foreign token, wildcard/mixed ability, stale token pointer, consumed challenge/token, account inactive, zero remaining lifetime and 24h/7d bound.

**Rollback injection points within the real transaction:**

1. Immediately after deletion/invalidation of old token pair.
2. After creating replacement Access Token.
3. After updating family token pointers.
4. Immediately before commit.

For each, assert exact database coherence and that an exception **rolls back uncommitted writes**. Do not use public production bypass hooks. If a test's own fixture intercepts a production call, describe the intrusion and its limits. Also verify committed-then-response-lost is **not** treated as rollback.

## 7. Exact expected race outcomes and lock-order evidence

Before executing each Cxx race, write down:

- Starting token, account, security/version, session generation and family state (redacted in review package).
- Barrier placement, first lock holder and exact event signaling.
- Expected response for each actor in **Order A** and **Order B**.
- Expected number of active families and Access/Refresh rows, expected generation and pointer targets.
- Expected expired/revoked outcome, role/company and other-device preservation.
- Whether the test exercises a **whole production path** and how independent DB connections are proven.

Test opposite transaction orders, not merely opposite command-launch orders. A 401 for a stale Logout proof after successful Refresh can be correct; a reported 204 with a valid successor is **not** acceptable. If evidence cannot force a critical order deterministically, report NOT VERIFIED rather than PASS.

## 8. Stepwise implementation checkpoints and time discipline

**Checkpoint A — PREPARE/LOCK REQUIREMENTS:** complete Phase 0, approvals, full requirement matrix, writer/connection/lock inventory, feature-flag classification policy, reset-record lock ordering, isolated MariaDB smoke and race harness validation. Capture baseline status/diff/hashes. If essential unknowns exist, STOP before code.

**Checkpoint B — SLICE 04:** implement Refresh classification, transactional rotation, failure and rollback outcomes. Run **targeted** positive/negative unit/feature tests and genuine Refresh-vs-Refresh MariaDB test. Fix failures before C.

**Checkpoint C — SLICE 05:** implement Logout/expired-access proof, FCM handling, password change/reset, invalidating writers and existing DELETE protection; add locked Login/2FA coordination and focused tests. Stop for unapproved shared-file edits or unsafe credential-sync architecture.

**Checkpoint D — COMBINED INTEGRATION:** run actual C01–C20 applicable races, both orders and additional scenarios; verify exact DB outcomes, API compatibility and cross-feature locking; no false concurrency claims.

**Checkpoint E — FINAL REGRESSION:** after the last relevant production code change, run the full required functional/regression matrix **once per final state** on SQLite and MariaDB; run the actual multi-process races as separate jobs, capture the final evidence set, and compare source hashes. If code changes after evidence, rerun the affected checks and any full regression whose validity is materially changed. Do **not** rerun every suite after each comment/test-evidence change.

Never skip mandatory failed tests or proceed across a failed checkpoint as if it passed. Keep updates concise and avoid long open-ended waits; when commands run asynchronously, poll actual process states, file timestamps and exit codes instead of simply claiming to wait.

## 9. Regression and client compatibility

At a minimum run, on **both SQLite and MariaDB** where applicable, all nine existing Slice 01–03 regression suites:

1. `tests/Feature/AdminSessionPersistenceTest.php`
2. `tests/Feature/AdminSessionTokenLinkageTest.php`
3. `tests/Feature/AdminAuthRegressionTest.php`
4. `tests/Feature/AdminSessionFamilyLoginTest.php`
5. `tests/Feature/CashierFirstLoginTest.php`
6. `tests/Feature/BranchManagerLoginIdentifierAliasTest.php`
7. `tests/Feature/MobileLoginIdentifierAliasTest.php`
8. `tests/Feature/MobileForcedFirstLoginResetTest.php`
9. `tests/Feature/LoginDeviceRegistrationTest.php`

Add focused Slice 04/05 suites for: exact abilities and negative route contracts, flags/rollback, token rotation, 24h/7d/15m bounds, Logout modes, credential writers, reset single-use and TTL boundaries, GET/DELETE existing session compatibility boundary, DB rollback injections, unauthorized ownership, Mobile/Legacy regression and all applicable races.

Do not delete, weaken, bypass or mark skipped a failing assertion to obtain green status. Preserve financial idempotency behavior and Mobile token lifetime. Verify Dashboard endpoint method, field names and response shapes without modifying Dashboard; note S1-13 dependencies. Verify SSO/Invitation/Impersonation issuer compatibility only in scope; do **not** silently remediate separate security work. **All-Admin-route Refresh-token rejection is Slice 07 Strict Guard, not a claim that 04/05 may make.** Mark general protected-route enforcement `DEFERRED TO SLICE 07 — RELEASE BLOCKER`.

## 10. Evidence, sanitization and independent reproducibility

Use a dedicated Slice-04-05 evidence directory **outside** the repo, keeping raw audit originals private and a distinct sanitized reviewer copy.

For every test/suite/race record **actual** command, working directory, environment and DB/cache identity, MariaDB version and isolation level, test start/end timestamp, elapsed time, exit code, stdout/stderr, JUnit XML where applicable, actual tests/assertions/errors/failures/skips, process PIDs, independent DB connection IDs, barrier events and final SQL verification. Tag everything with a **Run ID** and source hashes at start/end. The final manifest must be generated from actual outputs, not hard-coded PASS values or old JUnit.

Independently validate aggregate test counts from JUnit files; verify all exit codes and that artifacts were produced **after the final source version**. A printed `PASS` line alone is insufficient. Snapshot SQL must check users, session UUIDs, token pointers, exact token abilities, immutable alias, expiry, generations, revocations, tokenable ownership, and effects on other devices where relevant. Preserve real read-only verification queries and their results.

Sanitize review copy **dynamically**: passwords, TOTP seeds/codes, challenge secrets, reset secrets, Bearer/Refresh/Access tokens, APP_KEY/DB_PASSWORD, FCM tokens, and secrets embedded in cache-key names, logs or JSON fixtures. Avoid raw secrets in shareable code snippets/command lines/manifests. Review packet integrity requires per-file SHA-256 checksums validated **after** redaction. State that database provenance/privacy inspection covers only actually inspected tables; do not claim production-wide privacy clearance from a few fixtures.

No test-only public production params, no fabricated independent worker connection IDs or canned race results. If a verification cannot be done, mark `NOT_RUN/NOT_VERIFIED` with the reason; do not claim all issues closed based on logs supplied by the same author.

## 11. Mandatory author self-review before handoff

Challenge each of the following and link to test/evidence or open limitation:

1. Refresh credential used as an Access credential on currently guarded paths; broader Admin Guard stays deferred to Slice 07.
2. Access/wildcard/mixed/foreign token accepted as Family Refresh.
3. Consumed credential replay and flag-off legacy downgrade.
4. Duplicate Refresh successors or incorrect generation.
5. Refresh vs Logout (including `204` followed by valid successor).
6. Refresh vs Password Change/Reset or different invalidating writer.
7. Login vs Password Change/Reset/Deactivation and Mobile → Admin sync.
8. 2FA challenge completion/direct password+code vs credential/eligibility invalidation.
9. Two concurrent Password Changes; concurrent same-secret Resets.
10. Reset vs Forgot/Resend and exact 60-minute expiry boundary.
11. Reversed lock ordering, cross-connection side effects, nested transactions or deadlocks.
12. Exception after token deletion, after token issuance or after pointer update.
13. Lifetime extended, current token pointer broken or immutable alias changed.
14. Revocation of an unrelated device or another user's family.
15. FCM failure blocks revocation, gets lost silently or is falsely advertised as retried.
16. Expired-access logout accepting ID instead of valid secret, or stale proof accepted.
17. Existing Session DELETE leaves half a new family valid or bypasses ownership.
18. Source hash differs from the JUnit/log evidence used to claim completion.
19. Production security behavior changed via test-only override.
20. Raw secrets/challenge/token values leak into reviewer package.
21. Shared `issueTokens()` or credential sync breaks SSO/Invitations/Mobile.
22. Compatibility Mode, disabled feature flag or future Strict Guard assumptions cause unsafe fallback.
23. A mandatory writer, contract field or later-slice dependency was omitted or falsely marked passed.
24. Loss of Refresh HTTP response incorrectly interpreted as rollback or guaranteed token consumption.

Review the **actual final diff**, including unintended changes in shared models, routes, middleware, migrations and config, not just the newly added tests.

## 12. Final completion gate and delivery report

Revisit every approved and discovered row in `S04-S05_REQUIREMENTS_MATRIX`; classify clearly, with `Writer_ID` and evidence. Final success is permitted only if **every applicable** Slice 04 and 05 requirement has implementation, passing functional tests, required real MariaDB concurrency proof and matching end-source fingerprints. Known blocked requirements are **BLOCKED, not passed**. Defer full Session D09 to Slice 06, general Strict Guard to Slice 07, Dashboard coordination/storage to S1-13/D12, and independent production release gates; do not defer protections of live paths without explicit scope resolution.

Produce a single final report containing:

1. Branch/HEAD/ancestry, exact Git status, beginning and final source-hash inventory; no unstated staging.
2. Slice 03 handoff status and post-P3 delta, clearly distinguishing independent vs developer-only proof.
3. Machine-readable complete requirement matrix with all `S04`, `S05`, `C01–C20`, Mobile, Reset/Resend, DELETE, flag rollback and FCM rows.
4. Writer inventory: exact paths, callers, DB connections, transactions, lock order and approval constraints.
5. Readable new/modified file list, `git diff` and untracked new-file contents; clear Slice 04 vs Slice 05 summary.
6. Final HTTP request/response/error contracts, exact token abilities, TTLs, compatibility behavior and generation/pointer semantics.
7. SQLite functional/regression results and genuine JUnit/exit-code evidence.
8. MariaDB functional/regression results and genuine JUnit/exit-code evidence.
9. Each applicable independent-process race: both ordering barriers, PIDs/connection IDs, responses, SQL post-state and evidence paths.
10. Each rollback injection point and explicit outcome, including committed-but-lost-response.
11. Actual FCM/device cleanup strategy, failure logging and any proven existing retry/manual fallback.
12. Verified Legacy Admin, Mobile/Cashier/BranchManager and SSO/Invitation boundary impact, with deferred client/guard dependencies.
13. Final SHA-256 provenance tying implementation to test Run IDs, timestamps, logs, JUnit and SQL snapshots.
14. Sanitized review-package path, complete file checksum manifest and post-redaction verification; raw evidence preserved privately.
15. Open blockers, risks, unapproved shared writers, Slices 06/07 and S1-13 release gates, remaining decisions for Mahmoud.
16. Independent audit handoff checklist and exact files/commands an auditor can inspect read-only without rediscovery.

**Allowed conclusion only if all required proof exists:**
`SLICE 04 + SLICE 05 COMPLETE — SELF-VERIFIED / READY FOR INDEPENDENT AUDIT`.

**Otherwise say:**
`SLICE 04 + SLICE 05 PARTIAL / BLOCKED — NOT READY FOR AUDIT` and list blockers precisely.

Do not call author-run tests an independent audit. Independent AUDIT WORK (Codex Sol Medium) separately examines the local code/evidence read-only. Mahmoud alone decides technical acceptance. Implementation completion does **not** authorize production feature flag activation, Git publication, deployment, or Slices 06/07.

**BEGIN NOW at Phase 0 / Checkpoint A. After satisfying it, proceed with authorized local Slice 04 then 05 checkpoints, unless a specified approval or safety blocker requires STOP. STOP after the final combined delivery report.**
