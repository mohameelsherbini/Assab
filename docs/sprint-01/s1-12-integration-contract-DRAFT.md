# S1-12 Backend Integration Contract (Approved Architecture Design Baseline)

**Document Status:** DESIGN BASELINE — S1-05 ARCHITECTURE APPROVED (Mahmoud, 2026-10-12); documentation aligned to final clarifications  
**Architecture Direction Status:** APPROVED — Option B (Laravel Sanctum + Admin Session Families + InnoDB Row Locking)  
**Implementation Gate:** S1-12 BACKEND IMPLEMENTATION AUTHORIZATION PENDING — THIS APPROVAL DOES NOT AUTHORIZE CODE  
**Activation Gate:** STRICT MODE / PRODUCTION DEPLOYMENT NOT AUTHORIZED; S1-13, HOST-01 AND RELEASE GATES OPEN  
**Financial Acceptance Gate:** S1-11 CORE ACCEPTANCE IS INDEPENDENT  
**Design Baseline ID:** `ASSAB-S1-05-S1-12-ARCH-2026-10-12-v1`  
**Baseline Basis:** Mahmoud's written S1-05 architecture approval and nine execution clarifications (2026-10-12); source code anchored to the Foundation SHA below  
**Workspace:** `d:\claude\ASSAB ERP GITHUB BACKEND ANTIGRAVITY\Assab`  
**Repository:** `mohameelsherbini/Assab` (fork of `mahmoudmagdy200000-code/Assab`)  
**Base HEAD:** `41f03a31929d29fdbb494ef906f8cbe80916c9ad`  
**Target Branch:** `task/s1-12-dashboard-auth`  
**Mode:** DOCUMENTATION ONLY — NO APPLICATION CODE OR DATABASE CHANGES  
**References:**
- `docs/sprint-01/s1-05-auth-security-blueprint-DRAFT.md` (Security Blueprint & Prior Working Decision Register)
- `docs/sprint-01/api-contract.md` (§11 Dashboard session blueprint C06, §12 Error catalog)
- `Modules/Admin/routes/api.php:78-108` (Source route declarations)
- `Modules/Admin/app/Http/Controllers/Auth/AuthController.php`
- `Modules/Admin/app/Services/AuthService.php`
- `Modules/Admin/app/Support/AsabResponse.php` (Bare success resource, error envelope, listResponse)
- `Modules/Admin/app/Models/AsabUser.php` (`HasUuids`, UUID primary key)
- `Modules/Admin/app/Models/AsabCompany.php` (`HasUuids`, UUID primary key)
- `database/migrations/2025_10_09_121446_create_personal_access_tokens_table.php` (`uuidMorphs('tokenable')`)

---

## 1. Scope & System Role

This document is the **approved-architecture design baseline** of the proposed S1-12 Backend implementation contract. It translates Mahmoud's **S1-05 ARCHITECTURE APPROVED** Option B decision into HTTP contracts, Admin route enforcement, additive schema, User → Session → Token locking, secure admission, D09 compatibility and S1-13 obligations. **It does not grant S1-12 implementation authorization.** S1-11 acceptance, S1-12 code authorization, technical acceptance and strict deployment are distinct gates. HOST-01 is open for S1-13 integration/release, but does not block separately authorized backend coding.

> [!IMPORTANT]
> **Decision Register Status Notice:**  
> Mahmoud explicitly **approved the S1-05 architecture on 2026-10-12** and fixed **D12 = SSO Admission**, **D13 = Invitation**, **D14 = Impersonation**. The other IDs reflect the prior working matrix (the complete canonical register itself was not independently provided). **HOST-01**, not D12, covers browser credential storage and actual hosting. Architecture approval does **not** authorize S1-12 code, a Commit/Push, or strict deployment.

---

## 2. API Endpoint Contracts

All routes belong to `Modules/Admin` and are registered under the `/api/v1` prefix. Envelopes adhere to `BACKEND_API_SPEC.md` and `AsabResponse`:
- **Success (single resource):** Bare resource object or array (HTTP 200/201).
- **Success (list resource):** `{ "data": [ ... ] }` with optional `meta` object (HTTP 200 via `AsabResponse::listResponse`).
- **Success (empty):** HTTP 204 No Content.
- **Error envelope:** `{"error": {"code": string, "message": string, "messageAr": string, "details": object|array}, "requestId": string}` (HTTP 4xx/5xx).

---

### 2.1 `POST /api/v1/auth/login`

Authenticates an Admin/Company user, applies optional 2FA gating, and issues a bound session family.

- **Access Scope:** Public.
- **Request Headers:** `Content-Type: application/json`.
- **Request Body:**
  ```json
  {
    "email": "user@example.com",
    "password": "SecretPassword123",
    "code": "123456",
    "twoFactorToken": null,
    "rememberMe": false,
    "deviceToken": "optional-fcm-token",
    "deviceType": "web"
  }
  ```
  *(Rules: `email` + `password` required unless `twoFactorToken` is supplied; `rememberMe` optional boolean; optional FCM device fields). An omitted `rememberMe` means false. A 2FA completion uses the server-stored choice from challenge creation, not a newly supplied value.*

- **Success Response (No 2FA or 2FA Completed — HTTP 200):**
  ```json
  {
    "accessToken": "1|abcdef123456...",
    "refreshToken": "2|fedcba654321...",
    "expiresIn": 900,
    "sessionId": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
    "expiresAt": "2026-10-12T09:30:00+03:00",
    "sessionExpiresAt": "2026-10-13T09:15:00+03:00",
    "user": {
      "id": "e9b014d5-55c3-4b67-a006-25816999a801",
      "name": "Admin User",
      "email": "user@example.com",
      "companyId": "a1234567-89ab-cdef-0123-456789abcdef",
      "roles": [ ... ]
    }
  }
  ```
  *Note on Identifiers & Expiration:*
  - `user.id` is a UUID string (`asab_users` uses `HasUuids`).
  - `user.companyId` is a UUID string (nullable for platform/super-admin users).
  - `sessionId` is the stable UUID identifier of the session family.
  - `sessionExpiresAt` is immutable absolute session expiration (+24h, or +7d only if `rememberMe: true` was explicitly selected and, when applicable, bound to the 2FA challenge).
  - `expiresIn` is integer seconds, strictly computed as `min(900, max(0, sessionRemainingSeconds))`. If remaining time is zero, issue no tokens.
  - `expiresAt` is the ISO-8601 timestamp exactly matching `now() + expiresIn`.

- **Success Response (2FA Challenge Required — HTTP 200):**
  ```json
  {
    "requires2fa": true,
    "twoFactorToken": "48charRandomTokenString..."
  }
  ```
  **Login/admission race contract:** A preliminary password check is not authority to issue a session. Under the **User row `FOR UPDATE` lock**, re-read active status, relevant company membership eligibility and **verify the current stored password or an approved admission credential-version match** before creating Session and Tokens in the same transaction. All account/password/membership invalidation writers must lock this same User row before changing eligibility. Whoever commits first determines the outcome: invalidation-first rejects login; login-first is revoked by the subsequent invalidation.

**2FA contract:** The short-lived server-side challenge binds user ID, explicit Remember Me choice, expiry and admission credential version. Complete 2FA under the User lock with renewed eligibility/version verification. Consume or claim the challenge through **one proven atomic operation against the actual shared store**, not `Cache::get` followed by `Cache::forget`. Parallel completions can issue **at most one family**; on unsuccessful issuance the consumed challenge may require fresh sign-in. The existing Password + Code direct path cannot bypass the same guarded admission and one-use protections. Any choice alteration requires fresh credential/factor verification; keep existing bare login and challenge response shapes.

- **Error Responses:**
  - `401 INVALID_CREDENTIALS` (`بيانات الدخول غير صحيحة`)
  - `403 USER_INACTIVE` (`الحساب غير مُفعّل`)
  - `422 TWO_FACTOR_INVALID_CODE` (`رمز التحقق غير صحيح`)
  - `422 VALIDATION_ERROR` (`فشل التحقق من البيانات`)

---

### 2.2 `POST /api/v1/auth/refresh`

Rotates an active refresh token into a new access + refresh pair within the same session family.

- **Access Scope:** Public (authenticated by refresh token secret).
- **Request Headers:** `Content-Type: application/json`.
- **Request Body:**
  ```json
  {
    "refreshToken": "2|fedcba654321..."
  }
  ```

- **Success Response (HTTP 200):**
  ```json
  {
    "accessToken": "3|newaccess123456...",
    "refreshToken": "4|newrefresh654321...",
    "expiresIn": 900,
    "sessionId": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
    "expiresAt": "2026-10-12T09:45:00+03:00",
    "sessionExpiresAt": "2026-10-13T09:15:00+03:00"
  }
  ```
  *Note on Invariants & Bounding:*
  - `sessionExpiresAt` is immutable and matches the session absolute lifetime. Refresh extends access validity but **never extends session absolute lifetime**.
  - `expiresIn` is integer seconds bounded by `min(900, max(0, sessionExpiresAt - now()))`.
  - `expiresAt` matches `now() + expiresIn`.

- **Error Responses Aligned with Locking Algorithm:**
  - `401 INVALID_TOKEN` (`رمز التحديث غير صالح`): Malformed, non-existent, consumed/superseded generation, or wrong exact stored ability set (D06 fail-closed). **Never reuse a proven consumed token.**
  - `401 SESSION_EXPIRED` (`انتهت الجلسة`): Session passed `sessionExpiresAt` or marked `revoked_at`.
  - `403 USER_INACTIVE` (`الحساب غير مُفعّل`): User status is no longer `'active'`.
  - `409 REFRESH_IN_PROGRESS` (`عملية التحديث قيد التنفيذ`): Only when the server positively proves that this generation has a rotation still in progress. A request that waited and then observes consumption receives `401 INVALID_TOKEN`. A generic lock timeout/deadlock does not qualify as 409. The client must not assume the same token remains retryable after 409.
  - **Network timeout / 5xx:** Outcome ambiguous; neither proves logout nor permits treating the token as definitely unconsumed. S1-13 coordinates the result across tabs and requires a fresh sign-in if no valid successor can be established safely.

---

### 2.3 `POST /api/v1/auth/logout` (Expired-Access Routing Adaptation)

Atomically terminates the active device session and revokes both access and refresh tokens.

- **Access Scope:** Dual Authentication Gateway:
  1. **Standard Active Logout:** Authenticated via `Authorization: Bearer <accessToken>`.
  2. **Expired-Access Logout (D08):** Authenticated via request body `{ "refreshToken": "<valid-plain-token>" }`.
- **Routing & Middleware Adaptation:**
  - Existing `Modules/Admin/routes/api.php` mounts `auth/logout` inside standard `Route::middleware('auth:sanctum')`.
  - Under strict Sanctum middleware, an expired access token produces 401 before the controller executes.
  - **Required Adaptation:** Adapt the route middleware to a dual-authenticator guard (`auth:sanctum_or_refresh`) or handle the expired access fallback:
    - If Bearer token is valid and unexpired, resolve user from Bearer token.
    - If Bearer token is expired (or omitted) and `refreshToken` is provided in the body, use it only as a candidate lookup. Under User → Session → Token locks, verify hash, exact stored `['admin:refresh']` ability, expiry, tokenable type, ownership and current generation before revoking. A bare ID never authorizes logout.
- **Device-Registration Cleanup Preservation (non-blocking):**
  - Resolve the device/registration identity while the session is authenticated; **commit family revocation and token deletion atomically first** under User → Session → Token locks. FCM cleanup runs **after commit** as a retryable/best-effort step with failure visibility (e.g., after-commit dispatch), not as a prerequisite. A temporary FCM failure can never abort or undo revocation or leave access/refresh credentials valid.
  - Preserve existing intended `revokeLoginDevice` semantics, adapting its timing/dispatch if needed; do not assume a successful external FCM call as proof of logout.
- **Bare `sessionId` Rejection:**
  - Supplying a bare `sessionId` without the cryptographic refresh credential secret is strictly rejected with 401 `UNAUTHENTICATED`. Bare IDs alone cannot authorize revocation.
- **Success Response:** `204 No Content`.
- **Atomicity:** Mark the family revoked and delete its current tokens in one InnoDB transaction using the common lock order. A concurrent refresh cannot leave a usable successor after logout commits. FCM cleanup is after-commit/best-effort and its failure cannot invalidate the revocation.
- **Error Responses:**
  - `401 UNAUTHENTICATED` (`غير مصرح`): Missing or invalid credentials.

---

### 2.4 `GET /api/v1/auth/me`

Returns the authenticated user's profile and permissions.

- **Access Scope:** Authenticated (`auth:sanctum`, requires `admin:access` ability).
- **Request Headers:** `Authorization: Bearer <accessToken>`.
- **Success Response (HTTP 200):** Bare user payload with permissions (as returned by `AuthService::userPayload(..., withPermissions: true)`).
- **Error Responses:**
  - `401 UNAUTHENTICATED` (`غير مصرح`): Expired or invalid access token.
  - `403 FORBIDDEN_SCOPE` (`غير مصرح بالوصول`): Token lacks `admin:access` ability.

---

### 2.5 `GET /api/v1/auth/sessions`

Returns active device sessions for the authenticated user.

- **Access Scope:** Authenticated (`auth:sanctum`, requires `admin:access` ability).
- **Request Headers:** `Authorization: Bearer <accessToken>`.
- **Success Response (HTTP 200 — Envelope Preserved via `AsabResponse::listResponse`):**
  ```json
  {
    "data": [
      {
        "id": "1",
        "sessionId": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
        "name": "Dashboard Session",
        "deviceName": "Chrome on Windows",
        "ipAddress": "192.168.1.10",
        "lastUsedAt": "2026-10-12T09:15:00+03:00",
        "createdAt": "2026-10-12T09:00:00+03:00",
        "expiresAt": "2026-10-13T09:00:00+03:00",
        "isCurrent": true
      }
    ]
  }
  ```
  *Backward Compatibility & ID Mapping (D09):*  
  - Preserves the `{ "data": [ ... ] }` list envelope.
  - For a new family, `id` is a stable numeric-string compatibility alias stored as `admin_sessions.legacy_access_token_id`. Assign it from the first access-token ID and retain it after rotation deletes that row. `sessionId` is the distinct stable family UUID; rotation changes neither identifier.
  - A familyless legacy Admin token remains one token entry with its actual numeric-string token `id` and `sessionId: null`. Its unknown pairing and expiry must not be invented. Additive fields such as `expiresAt`, `deviceName` and `isCurrent` may be null where the source has no value.
  - The `{ "data": [ ... ] }` envelope is unchanged.

---

### 2.6 `DELETE /api/v1/auth/sessions/{id}` (Explicit ID Mapping to Family)

Revokes an identified session belonging to the authenticated user.

- **Access Scope:** Authenticated (`auth:sanctum`, requires `admin:access` ability).
- **Request Headers:** `Authorization: Bearer <accessToken>`.
- **URL Parameter `{id}` Resolution:**
  - Old token ID (`personal_access_tokens.id` integer) and new session UUID (`admin_sessions.id`) are **distinct entities** with non-interchangeable meanings.
  - Resolution Algorithm:
    1. If `{id}` is a valid UUID: Query `AdminSession::where('id', $id)`.
    2. If `{id}` is a numeric string: first query the persistent unique `admin_sessions.legacy_access_token_id` alias. This resolves a new family even when the original access-token row is gone. If no alias exists, resolve an actual familyless legacy Admin token row by ID and revoke **only that owned token** in compatibility mode. Never infer its partner.
    3. Never treat a numeric token ID as a UUID or fall through from an unauthorized family alias to a token row.
  - Confirm user ownership before mutation. For a family, lock User → Session → Tokens, set `revoked_at` and delete all current family credentials atomically. For a familyless legacy token, enforce current compatibility policy and ownership without fabricating a family.
- **Success Response:** `204 No Content`.
- **Error Responses:**
  - `403 FORBIDDEN_SCOPE` / `404 NOT_FOUND`: Session does not belong to user or does not exist.

---

## 3. Admin Protected Route Surface Enforcement Contract

At strict activation, protected Admin routes under `Route::middleware('auth:sanctum')` in `Modules/Admin/routes/api.php` must be guarded by the Admin token verification layer enforcing:

1. **Token Ability Enforcement:**
   - Every strict Admin request must present an `AsabUser` token with the exact stored ability array `['admin:access']` and an active family. Checking `tokenCan('admin:access')` alone is insufficient because Sanctum wildcard tokens pass it.
   - Tokens carrying only `admin:refresh` are strictly rejected with 403 `FORBIDDEN_SCOPE`.
2. **Explicit Per-Token Expiry Check:**
   - Evaluates `personal_access_tokens.expires_at`. If `now() >= expires_at`, rejects immediately with 401 `TOKEN_EXPIRED`.
3. **Session Family State Validation:**
   - Looks up `admin_sessions` via `personal_access_tokens.session_id`.
   - Asserts `admin_sessions.revoked_at IS NULL` and `now() < admin_sessions.expires_at`.
   - Rejects with 401 `SESSION_EXPIRED` if the session was revoked or reached its absolute lifetime.
4. **Live Account Authorization:**
   - Re-verifies `user->status === 'active'`. Deactivated users are rejected with 403 `USER_INACTIVE`.
5. **Wildcard & Special Issuer Token Isolation:**
   - Wildcard abilities (`['*']`) from legacy seeds or foreign guards must **not** bypass `admin:access` ability checks.
   - Mobile tokens (Cashier, BranchManager) have `session_id IS NULL` and belong to separate user models; they are strictly rejected on Admin routes.
   - Impersonation has no general exception. The backend inventory at the reference SHA found one route, `POST /admin/companies/{id}/impersonate`, and one issuer, `Admin/CompanyController::impersonate`, which creates a 30-minute target-user token with `['*']` and records an issuance audit entry. Dashboard consumers were not verified in this backend-only inspection. A narrow accepted repair must use impersonation-only ability, durable actor/target/company context, actor authorization and live company scope, 30-minute maximum, audit correlation and no refresh. If unavailable, explicitly disable this path at strict cutover after notice; preserve its known compatibility behavior before activation.

---

## 4. Database Schema Specification

> [!NOTE]
> **Schema Design Status:** *PROPOSED / ILLUSTRATIVE DDL PENDING MIGRATION ADR & PREFLIGHT.*  
> This schema defines required column types and constraints, pending formalization into a migration ADR.

### 4.1 Proposed Table: `admin_sessions`
Additive migration target: `database/migrations/YYYY_MM_DD_XXXXXX_create_admin_sessions_table.php`.

```sql
CREATE TABLE `admin_sessions` (
    `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID session family identifier',
    `user_id` CHAR(36) NOT NULL COMMENT 'FK to asab_users.id (HasUuids)',
    `company_id` CHAR(36) NULL COMMENT 'FK to asab_companies.id (HasUuids); NULL for platform/super-admin users',
    `device_name` VARCHAR(255) NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `is_remember_me` TINYINT(1) NOT NULL DEFAULT 0,
    `generation` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Current successful rotation generation',
    `current_access_token_id` BIGINT UNSIGNED NULL,
    `current_refresh_token_id` BIGINT UNSIGNED NULL COMMENT 'Pointer to active refresh token for generation check',
    `legacy_access_token_id` BIGINT UNSIGNED NULL COMMENT 'Immutable numeric compatibility alias from first access token',
    `last_active_at` TIMESTAMP NULL,
    `expires_at` TIMESTAMP NOT NULL COMMENT 'Absolute session expiration timestamp',
    `revoked_at` TIMESTAMP NULL COMMENT 'Timestamp when explicitly revoked',
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    INDEX `idx_admin_sessions_user` (`user_id`, `revoked_at`),
    UNIQUE KEY `uq_admin_sessions_legacy_alias` (`legacy_access_token_id`),
    INDEX `idx_admin_sessions_company` (`company_id`),
    INDEX `idx_admin_sessions_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
`legacy_access_token_id` is written once during first-family issuance after the first access token has an ID, then remains immutable. A unique constraint prevents alias ambiguity. `company_id` records admission context only; Admin resource authorization and company membership must be checked live. This is illustrative DDL, not an executed migration.

### 4.2 Additive Column on `personal_access_tokens`
Additive migration target: `database/migrations/YYYY_MM_DD_XXXXXX_add_session_id_to_personal_access_tokens.php`.

```sql
ALTER TABLE `personal_access_tokens`
    ADD COLUMN `session_id` CHAR(36) NULL AFTER `tokenable_id`,
    ADD INDEX `idx_pat_session_id` (`session_id`);
```
*Isolation Invariant:* Mobile tokens (Cashier, BranchManager) leave `session_id` as `NULL`. No legacy mobile queries or operations are impacted.

---

## 5. Concurrency & Row-Locking Algorithm

### 5.1 Consistent Lock Order and Post-Lock Generation Check
To eliminate races where a valid token is observed before a lock, preliminary lookup is **candidate discovery only** and cannot authorize a refresh or a new login. All overlapping operations—including password Login, 2FA family admission, credential reset and membership/deactivation—must serialize on the **User** lock before any session or token changes. Every conflicting writer uses one InnoDB transaction and the relevant portion of the following sequence:

1. Hash/parse the presented refresh secret and read candidate token ID, user ID and session UUID without a row lock. Treat this result as stale.
2. Lock **User** `FOR UPDATE`, then existing **Session** rows `FOR UPDATE` (in ascending UUID order for account-wide revocation), and finally **Token** rows `FOR UPDATE` (ascending ID order). New-session Login/2FA holds User first, then creates the family and tokens in that same transaction. Password/membership invalidation must acquire the same User first. No writer acquires Session or Token before User.
3. Re-read and verify under those locks: user existence/status and relevant company eligibility; session ownership, revocation and absolute expiry; refresh token hash/name/tokenable, exact stored ability array `['admin:refresh']` and expiry; current refresh/access IDs and generation. Do not use `tokenCan()` alone because wildcard can satisfy it. A missing/consumed token or changed generation is `401 INVALID_TOKEN`.
4. If no family time remains, issue no tokens. Otherwise set access expiry to `min(now + 15 minutes, session.expires_at)` and refresh expiry no later than `session.expires_at`; delete the old pair, insert exactly one successor pair with exact abilities, update current IDs and increment generation once. Commit together. Never extend `session.expires_at`.
5. If revocation won the lock first, no successor is issued. If refresh committed first, the following revoke removes that successor before revoke commits. No valid successor survives a completed revoke.

The normal path may wait for the lock. `409 REFRESH_IN_PROGRESS` is allowed only when a non-blocking mechanism positively proves a live competing rotation for the same generation; a completed rotation yields `401 INVALID_TOKEN` for the loser. A generic lock timeout/deadlock is an ambiguous transient failure, not 409.

### 5.2 Transactional Rollback Invariant
Inside `DB::transaction(...)`, throwing an `AsabException` automatically **rolls back** all uncommitted changes. Database modifications do not persist on exception rollback. Never claim that database updates persist when an exception is thrown in the transaction.

### 5.3 Safe Behavior for Competing Events
- **Refresh vs refresh:** Both begin with non-authoritative lookup. Only one commits a successor pair; the other rechecks after locks and receives 401 `INVALID_TOKEN`. Never replay the consumed credential.
- **Logout or session DELETE:** Resolve the owner, then lock User → target Session → Tokens; mark family revoked and remove its tokens in one transaction. A stale refresh cannot revive it.
- **Password change/reset, account deactivation and membership eligibility changes:** Acquire the affected User lock first; then existing Sessions in UUID order and Tokens in ID order. Change the credential/eligibility state and revoke applicable families as one coherent transaction. Audit every eligibility-changing writer so no uncoordinated path can issue or retain an ineligible family.
- **New Login or 2FA vs invalidation:** Under User lock, recheck the live stored password or approved credential version, challenge state, active status and company eligibility before family creation. If invalidation won, issue nothing; if new login won, subsequent invalidation revokes its family. Require a MariaDB concurrency test for each side of this race.
- **Failure/rollback:** An exception rolls back all uncommitted token and family writes. A lock wait/deadlock or network/5xx outcome does not establish logout or prove a consumed generation; S1-13 handles uncertainty.

---

## 6. Financial Idempotency & Retry Safety

1. **Idempotency Scope:** Admin financial mutations (`/api/v1/company/me/branch/shifts/...`) operate under `asab.idempotency` middleware.
2. **Replay Invariant (S1-13 Client Rule):** If a shift mutation returns 401 due to access token expiry:
   - The frontend refreshes its tokens via `POST /api/v1/auth/refresh`.
   - The frontend retries the failed financial command with the **IDENTICAL `Idempotency-Key` header and IDENTICAL payload**.
   - The backend `asab.idempotency` middleware validates the newly authenticated user/tenant, verifies the idempotency cache, and returns either the saved execution result or continues the transaction without duplicate effect.

---

## 7. S1-05 Architecture Decision Register (D01–D16)

> [!IMPORTANT]
> **Confirmed by Mahmoud:** S1-05 architecture approved on 2026-10-12. **D12 = SSO**, **D13 = Invitation**, **D14 = Impersonation**; browser hosting/storage is **HOST-01**. This approved architecture is a design baseline, **not** S1-12 implementation authorization. The remaining IDs retain the earlier working matrix, pending access to the complete canonical register.

| Decision | Topic | Baseline / Gate | Invariant |
|---|---|---|---|
| **D01** | Credential Separation | **APPROVED** | Exact `['admin:access']` and `['admin:refresh']`; reject wildcard/mixed. |
| **D02** | TTL / Session Bounding | **APPROVED** | Access ≤15m, bounded by immutable 24h/7d session. |
| **D03** | Multi-Device | **APPROVED** | Distinct, individually revocable families. |
| **D04** | Atomic Rotation / Admission | **APPROVED** | User → Session → Token with post-lock checks; **new Login/2FA issuance also locks User**. |
| **D05** | Competing Refresh / Revocation | **APPROVED** | One successor pair, no survivor of completed revocation. |
| **D06** | Fail-Closed Recovery | **APPROVED** | 401 on invalid/consumed; no encrypted replay in v1. |
| **D07** | 60-Second Replay | **DEFERRED v1** | No grace window; 409 only proven live collision, optional. |
| **D08** | Expired-Access Logout | **APPROVED** | Refresh proof allowed, atomic revocation, **FCM cleanup non-blocking post-commit**. |
| **D09** | Session ID Compatibility | **APPROVED** | UUID plus immutable numeric alias; works after multiple rotations. |
| **D10** | Migration / Cutover | **APPROVED** | Additive schema, compatibility stage, notice, controlled strict activation and safe rollback. |
| **D11** | 2FA Admission | **APPROVED** | Remember Me/server-side credential-version binding; atomic one-use challenge; guarded direct password+code. |
| **D12** | SSO Admission Boundary | **SEPARATE RELEASE GATE** | Admission remediation separate; no insecure strict-mode SSO bypass. |
| **D13** | Invitation Token Boundary | **SEPARATE RELEASE GATE** | Invitation hardening separate; compliant admission or route held. |
| **D14** | Impersonation | **CUTOVER CONDITIONAL** | No `['*']` exception; narrow approved actor/company/audit/30m/no-refresh repair or disable. |
| **D15** | Rate Limits / Secret Logs | **APPROVED** | Scoped limits and no credential leakage. |
| **D16** | SQLite / MariaDB Evidence | **MANDATORY FOR ACCEPTANCE** | MariaDB/InnoDB independent-connections race proof. |

## 7A. HOST-01 — Browser Storage & Existing Hosting (OPEN)

- **HOST-01 is not D12.** Verify actual current Dashboard/API origins, reverse proxy, Cookie/CORS/CSRF and feasible secure browser credential storage. Reuse existing hosting if suitable; no hosting redesign is implied.
- Long-lived refresh credentials in production browser `localStorage` remain unapproved.
- **HOST-01 is not a blocker to separately authorized Backend S1-12 coding**, but **does block final S1-13 integration approval and production rollout** until resolved.

---

## 8. Verification & Concurrency Test Plan

Prior to acceptance by Mahmoud, S1-12 requires the following future evidence. **No tests, migrations or application code were run while editing this draft.**
1. **SQLite Unit/Functional Suite:**
   - Login with/without `rememberMe` produces correct `expiresIn` and `sessionExpiresAt`.
   - Access token cannot be used to rotate (`401 INVALID_TOKEN`).
   - Refresh token cannot access `/auth/me` (`403 FORBIDDEN_SCOPE`).
   - Deactivated user (`status = 'inactive'`) rejected on refresh (`403 USER_INACTIVE`).
   - Logout revokes both access and refresh tokens.
   - Expired-access logout with valid refresh token succeeds; invalid refresh token fails.
   - Exact stored abilities reject wildcard and mixed tokens; a 2FA challenge binds Remember Me and an admission credential version server-side, is claimed with an **atomic shared-store operation**, cannot be changed without re-verification, and issues at most one family. Direct Password + Code uses the same protections.
   - Plain Login and 2FA completion under User lock reject a stale password/credential version, inactive account or revoked company membership after a simultaneous invalidation.
   - D09 stable numeric alias resolves DELETE after multiple rotations; UUID DELETE targets the same family; familyless legacy Admin token ID deletes only that token in compatibility mode.
2. **Real MariaDB/InnoDB Concurrency Race Suite (Mandatory Gate):**
   - Multi-connection parallel test cases using independent database connections verifying:
     - *Case 1: Refresh vs Concurrent Refresh:* Exactly one 200 successor; the loser after serialization receives 401 `INVALID_TOKEN`. Test a 409 only if live contention can be proven. Zero duplicate active pairs (`AUTH-01`).
     - *Case 2: Refresh vs Password Change / Reset:* Parallel password change marks sessions revoked; in-flight refresh waiting for lock detects revocation and fails closed.
     - *Case 3: Refresh vs Logout:* Parallel logout marks session revoked and deletes tokens; in-flight refresh waiting for lock fails closed.
     - *Case 4: Refresh vs Session Deletion (`DELETE /auth/sessions/{id}`):* Parallel session deletion revokes family; in-flight refresh waiting for lock fails closed.
     - *Case 5: Refresh vs Account Deactivation:* Deactivation updates the relevant user or company-membership eligibility state under the common lock protocol; an in-flight refresh waiting for the lock fails with 403 `USER_INACTIVE`.
     - *Case 6: Admin Route Surface Enforcement:* Requests with wildcard abilities (`*`), mobile tokens (`session_id IS NULL`), or refresh tokens (`admin:refresh`) on protected Admin routes are rejected.
     - *Case 7: Opposite transaction order and rollback:* If refresh commits first, revocation removes its successor; if revoke commits first, refresh issues none. Failed transactions leave no partial family, token or generation update.
     - *Case 8: New Login vs password change/reset or account deactivation:* Both independent-connection transaction orders; invalidation-first yields no family, login-first is fully revoked. Also verify company-membership eligibility changes.
     - *Case 9: 2FA family creation vs password reset/deactivation and concurrent 2FA completions:* Both transaction orders plus duplicate-completion barrier using the configured shared challenge store; **at most one family** and none surviving a completed invalidation.
     - *Case 10: FCM device cleanup unavailable:* Logout still commits family + token revocation, returns the established logout success contract and schedules/records failed cleanup for later retry; stale credentials are unusable.
3. **Negative Cross-Repository Compatibility Suite:**
   - Verify Cashier and BranchManager login/shift compatibility at pinned client SHA without changing Flutter or imposing a global Sanctum TTL.
4. **Execution Integrity:**
   - Record SHA, MariaDB version, InnoDB isolation level, independent connection IDs, barriers, actual commands and results. Never report unrun tests as passed or use SQLite as locking proof.

---

## 9. Error, Retry and Transition Contract

| Result | Server meaning | Dashboard S1-13 response |
|---|---|---|
| 200 refresh | One new pair committed for the same family and next generation | Publish the accepted pair across coordinated tabs; ignore a late result after explicit logout. |
| 401 `INVALID_TOKEN` on refresh | Presented secret is malformed, absent, wrong exact ability, or confirmed consumed/superseded | Never submit that secret again. Use an already accepted successor only if securely coordinated; otherwise require fresh sign-in. |
| 401 `SESSION_EXPIRED` / 403 `USER_INACTIVE` | Family expiry/revocation or inactive account | Stop refresh; require fresh eligible sign-in as appropriate. |
| 409 `REFRESH_IN_PROGRESS` | Positive proof that rotation of this generation is still in flight | Coordinate with the in-flight owner; do not assume the presented token will be valid for retry. |
| Network timeout / 5xx / unclassified DB contention | Outcome unknown; a commit may or may not have happened | Do not auto-logout. Coordinate across tabs, await an accepted result if available, and use a new sign-in when state cannot be resolved safely. |

The financial retry contract belongs to S1-13: after a valid new access token, retry the exact original method, URL, body and `Idempotency-Key`. This document does not change financial logic or shared idempotency behavior.

### 9.1 Transition Matrix at the Reference SHA

| Issuer / credential | Compatibility mode | Strict activation |
|---|---|---|
| Legacy Admin tokens without a Session Family | Retain current behavior until announced cutoff. Session list uses actual numeric token `id` and `sessionId: null`; DELETE removes that owned token only. No assumed pairing. | Revoke/reject familyless credentials and require re-login. No wildcard or absent-family bypass. |
| New password/2FA Admin login | New family issuance may be enabled after code authorization and verification while legacy traffic remains compatible. | Enforce exact token type, expiry, family and active state on every protected Admin route. |
| SSO callback | At SHA `41f03a31929d29fdbb494ef906f8cbe80916c9ad`, `SsoService::handleOidcCallback` calls `AuthService::issueTokens`. Existing admission remains tracked separately; OIDC signature validation is an independent release gate. | After separately approved admission remediation, route issuance through compliant family creation; otherwise explicitly hold SSO callback closed. No familyless token admission. |
| Invitation acceptance | At the reference SHA, `OnboardController::acceptInvitation` calls `AuthService::issueTokens`. Invitation hardening remains separate. | Route issuance through compliant family creation after its admission gate, or explicitly hold acceptance closed. Communicate the cutover; do not silently return unusable tokens. |
| Impersonation | At the reference SHA, `Admin/CompanyController::impersonate` mints a 30m `['*']` target token and logs issuance; client consumers remain to be inventoried. Preserve known pre-cutover behavior until a deliberate transition. | Only enable after accepted narrow repair proves actor authorization, target company scope, 30m maximum, audit linkage and no refresh. Otherwise disable explicitly. No `['*']` exemption. |

### 9.2 Staged Activation and Rollback

1. Apply approved additive Admin schema in compatibility mode. Keep `config/sanctum.php` global expiration unchanged, Flutter Cashier/BranchManager lifetimes unchanged and legacy Admin behavior available during the announced transition.
2. Implement and verify User → Session → Token locking, every invalidating writer, 2FA single-use, D09 alias and strict guard behind a scoped switch on a disposable MariaDB/InnoDB environment. No tests or migrations are executed by this document edit.
3. Complete S1-13 cross-tab coordination, ambiguous refresh handling, logout-generation protection and exact financial retry contract. Verify actual Dashboard/API origins and obtain the **HOST-01** storage/cookie/CORS/CSRF decision.
4. Inventory SSO, invitation and impersonation issuers/consumers and agree explicit route behavior and user notice. Record Mahmoud's **S1-05 architecture approval**; obtain **separate S1-12 implementation authorization**, S1-11 acceptance at an identified SHA, S1-12 technical acceptance and strict deployment authorization. **Do not enforce production 15-minute Admin access TTL before S1-13 is ready and verified.**
5. Activate strict Admin enforcement only for the approved deployment cohort. Monitor auth errors, login availability and legacy cutoff without exposing token secrets.

Rollback turns off the scoped strict switch while keeping additive schema and revocation history. It never restores consumed tokens, un-revokes a family, recreates deleted secrets or changes global Sanctum/Flutter settings. If rollback would re-admit a credential known unsafe, hold the affected route closed and require re-login. Hosting verification uses the current environment if suitable; this work does not redesign hosting.

## 10. Design Baseline and Separate Work Authorization

**S1-05 ARCHITECTURE APPROVED** by Mahmoud on 2026-10-12. **D12 = SSO Admission Boundary; D13 = Invitations; D14 = Impersonation; HOST-01 = Browser Credential Storage & Hosting**, independently OPEN. This pair of documents is the **design baseline**, not proof of implementation or successful tests.

**Next required gate: explicit `S1-12 BACKEND IMPLEMENTATION AUTHORIZED`** against the pinned Foundation and the reviewed Task Branch; no Authentication code or migration may be edited under architecture approval alone. Later technical acceptance and **separate strict deployment permission** require the MariaDB/InnoDB tests, independent review, S1-13 compatibility, HOST-01 and approved treatment of legacy Admin, SSO, invitations and impersonation. **S1-11 acceptance is independent.** No Global Sanctum change, Flutter, financial logic or shared idempotency changes; no Foundation/Main edits, Merge, Rebase, Force Push, or implicit Commit/Push/PR authorization. Recommended authorized coding tool: **Gemini Pro High**; independent audit: **Codex Sol Medium**.
