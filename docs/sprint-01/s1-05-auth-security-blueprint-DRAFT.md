# S1-05 Authentication Security Blueprint (Approved Architecture Design Baseline)

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
- `docs/sprint-01/api-contract.md` (§11 Dashboard session blueprint C06, §12 Error catalog, §13 Decision log)
- `docs/sprint-01/task-register.md` (S1-05, S1-12, AUTH-01..03, RX-03)
- `Modules/Admin/routes/api.php` (Admin Auth route definitions)
- `Modules/Admin/app/Http/Controllers/Auth/AuthController.php`
- `Modules/Admin/app/Services/AuthService.php`
- `Modules/Admin/app/Models/AsabUser.php` (`HasUuids`, UUID primary key)
- `Modules/Admin/app/Models/AsabCompany.php` (`HasUuids`, UUID primary key)
- `database/migrations/2025_10_09_121446_create_personal_access_tokens_table.php` (`uuidMorphs('tokenable')`)
- `config/sanctum.php` (`expiration => null`)

---

## 1. Executive Summary & Architecture Model (Option B)

This is the **S1-05 architecture baseline** for Dashboard (Admin) authentication, token lifecycle and session-family security. Mahmoud explicitly approved the **Option B architecture on 2026-10-12**, subject to recording the execution clarifications now incorporated here. Architecture approval and completion of this document **are not** permission to edit Authentication code. S1-12 implementation authorization, S1-11 financial acceptance, S1-12 technical acceptance and strict deployment authorization remain **separate gates**. HOST-01 is an outstanding browser/integration release decision, not a blocker to authorized Backend S1-12 development.

### 1.1 Evaluated Architecture Options
- **Option A (Stateful Cookie Sessions):** Standard Laravel web session cookies.  
  *Disposition:* Rejected. Requires strict origin matching and complex cross-domain cookie/CORS/CSRF topology before deployment infrastructure is finalized; incompatible with mobile and stateless API consumers.
- **Option B (Laravel Sanctum with Minimal Admin Session Family — ARCHITECTURE APPROVED):**  
  *Disposition:* Approved architecture baseline. Extends the checked-in Laravel Sanctum token infrastructure with a minimal Admin session tracking table (`admin_sessions`), strict database transactions, and InnoDB row locking (`FOR UPDATE`). Strict activation requires the transition, S1-13 and HOST-01 gates in section 8.
- **Option C (Full OAuth2 / Passport / JWT):** Heavyweight authorization server.  
  *Disposition:* Rejected. Excessive complexity, key management overhead, and unnecessary package dependencies for an internal ERP application.

### 1.2 Core Architectural Principles
1. **Session Family Grouping:** Sanctum personal access tokens are grouped under a persistent, UUID-identified session family.
2. **Dual-Token Model:** Short-lived Access Token paired with an atomically rotating Refresh Token.
3. **Pessimistic Concurrency:** All token rotations and revocations execute inside database transactions under row-level locking (`FOR UPDATE`).
4. **Strict Credential Separation:** Access tokens carry only API execution abilities; refresh tokens carry only rotation ability. Inspect the stored ability set exactly; Sanctum's `tokenCan()` wildcard behavior is insufficient.
5. **Absolute Session Bounding:** Rotation extends access token validity within an active session, but **never extends the session absolute lifetime**.
6. **Flutter / Mobile Isolation:** Mobile token lifecycles (Cashier 30-day/remember-me, BranchManager indefinite) remain completely untouched. Global Sanctum expiration remains unconfigured (`config/sanctum.php: 'expiration' => null`).

---

## 2. Core Invariants & Security Lifetimes

| Parameter | Specification | Enforcement Mechanism |
|---|---|---|
| **Access Token TTL** | **Min(15 minutes, remaining session lifetime)** | `ttl = min(900, max(0, session.expires_at - now()))`. Enforced per-token via `expires_at` column in DB. |
| **Standard Session Lifetime** | **24 hours** (absolute) | Stored on `admin_sessions.expires_at`; refresh cannot push beyond this timestamp. |
| **Remember Me Lifetime** | **7 days** (absolute) | Set only if explicitly requested at login (`rememberMe: true`); non-extendable. |
| **Refresh Rotation** | Atomic 1-for-1 token exchange | Token consumed and exactly ONE successor pair issued in a single DB transaction with `FOR UPDATE`. |
| **Token Abilities** | Strict privilege separation | Access token: exactly `['admin:access']`; Refresh token: exactly `['admin:refresh']`. Reject `['*']` and mixed/unrecognized abilities under strict enforcement. |
| **Account State Verification** | Live active check | User status and relevant company membership/eligibility are checked at admission, under the refresh locks, and on protected Admin requests; current resource scope remains live. |
| **2FA Admission** | Server-bound choice and single use | Store `rememberMe` with the server-side challenge; reject a changed choice unless credentials and factor are reverified. Atomically consume the challenge and recheck active state before issuing a family. |
| **Multi-Device Support** | Independent concurrent sessions | Each login creates a distinct session family; individual revocation supported. |

---

## 3. S1-05 Architecture Decision Register (D01–D16)

> [!IMPORTANT]
> **Approval provenance:** Mahmoud approved Option B as **S1-05 ARCHITECTURE APPROVED** on 2026-10-12. He expressly confirmed the historical **D12 = SSO Admission**, **D13 = Invitation**, **D14 = Impersonation** numbering. Other D-IDs are carried forward from the project's working matrix; the full original register was not independently supplied. Browser Credential Storage & Hosting is the independent **HOST-01** decision. Approval of architecture is **not** approval to implement or deploy code.

| ID | Topic | Baseline / Release Status | Approved invariant or gate |
|---|---|---|---|
| **D01** | Credential Separation | **ARCHITECTURE APPROVED** | Exact stored `['admin:access']` or `['admin:refresh']` for the corresponding use; reject wildcard/mixed abilities in strict Admin mode. |
| **D02** | TTL & Session Bounding | **ARCHITECTURE APPROVED** | Access ≤15m and never after absolute family end: 24h standard / explicit 7d Remember Me; no sliding. |
| **D03** | Multi-Device | **ARCHITECTURE APPROVED** | Independent device families, single-family and all-account revocation. |
| **D04** | Atomic Rotation / Admission | **ARCHITECTURE APPROVED** | One successor pair; consistent **User → Session → Token** locking with post-lock validation. New Login/2FA family creation also synchronizes on User. |
| **D05** | Competing Refresh / Revocation | **ARCHITECTURE APPROVED** | Serialized across refresh, logout, password change/reset, deactivation and membership-eligibility writers; no successor survives committed revocation. |
| **D06** | Fail-Closed Recovery v1 | **ARCHITECTURE APPROVED** | Invalid/consumed refresh returns `401 INVALID_TOKEN`; no encrypted replay storage. |
| **D07** | 60-Second Replay Recovery | **DEFERRED v1** | No replay grace window; transport/5xx outcome is ambiguous and not an automatic logout. `409` requires proven live contention, and need not be implemented. |
| **D08** | Expired-Access Logout | **ARCHITECTURE APPROVED** | Valid current refresh secret may authorize logout after access expiry; atomic session revocation; FCM cleanup failure never blocks revoke. |
| **D09** | Session ID Compatibility | **ARCHITECTURE APPROVED** | Stable UUID `sessionId` and immutable numeric alias in `admin_sessions`; preserve `{data:[...]}`, support both forms after rotations. |
| **D10** | Migration / Cutover | **ARCHITECTURE APPROVED** | Additive Admin-only schema, compatibility stage, announced strict cutover, safe rollback; unchanged global Sanctum and Flutter. |
| **D11** | 2FA Admission | **ARCHITECTURE APPROVED** | Remember Me stored server-side with challenge; atomic single-use; direct password+code path subject to same controls. |
| **D12** | SSO Admission Boundary | **SEPARATE SECURITY RELEASE GATE** | Existing SSO admission remediation remains separately authorized; no unreviewed SSO issuer bypass at strict cutover. |
| **D13** | Invitation Token Boundary | **SEPARATE SECURITY RELEASE GATE** | Invitation hardening separate; issuer must be compatible with strict mode or route explicitly held. |
| **D14** | Impersonation Boundary | **ARCHITECTURE APPROVED / CUTOVER CONDITIONAL** | No wildcard bypass; narrow actor/company/audit/30m/no-refresh correction requires distinct acceptance; otherwise explicitly disable at strict cutover. |
| **D15** | Rate Limiting & Secret Logging | **ARCHITECTURE APPROVED** | Auth endpoint limits and no bearer/refresh/2FA secret leakage in logs/errors. |
| **D16** | SQLite / MariaDB Evidence | **MANDATORY BEFORE TECHNICAL ACCEPTANCE** | Functional SQLite + independent-connection, real MariaDB/InnoDB concurrency evidence. |

### 3A. HOST-01 — Browser Credential Storage & Existing Hosting (OPEN)

> **HOST-01 is NOT D12.** It remains **OPEN**, pending factual verification of existing Dashboard/API origins, reverse-proxy headers, cookies, CORS and CSRF configuration. No hosting redesign is presumed; keep current hosting if suitable.

- Browser storage decision must avoid long-lived Refresh Tokens in production `localStorage`; assess HttpOnly cookie versus other defensible storage against verified origins.
- HOST-01 **does not block authorized Backend S1-12 implementation**, but **blocks final S1-13 integration approval and production activation/release** until resolved.
- No global Sanctum expiration or premature 15-minute production enforcement before S1-13 compatibility.

---

## 4. Threat Model & Failure Mitigation

### 4.1 Token Theft Mitigation
- **Short Access TTL (15 min):** Limits the exposure window if an access token is intercepted from memory or transport.
- **Refresh Credential Isolation:** Refresh tokens are restricted exclusively to `POST /api/v1/auth/refresh` and authenticated `POST /api/v1/auth/logout`.

### 4.2 Refresh Concurrency, Locking Order & Post-Lock Validation
**One lock protocol for all conflicting paths:** A preliminary hash lookup of the presented token may obtain candidate user/session IDs but grants no authority and acquires no token row lock. Inside one InnoDB transaction, acquire locks in the order **User → Session → Token**. For all-user revocation, lock that user's session rows in a deterministic UUID order, then their token rows in deterministic ID order. Never start by locking a Token or Session and then wait for User. Avoid a second lock path through ORM model hooks.

After the locks are held, re-read and validate the actual user, live account/company eligibility, family ownership, revoked/absolute expiry state, token hash, tokenable type, exact stored ability set, token expiry, current token IDs and **current generation**. The preliminary token object is never trusted after waiting. A missing/consumed/replaced token or generation returns 401 `INVALID_TOKEN`. A revoked/expired family returns 401 `SESSION_EXPIRED`; inactive account eligibility returns 403 `USER_INACTIVE`. If no positive time remains, issue no tokens. Only then delete the old pair, insert one successor pair, advance generation once, and commit atomically.

**All invalidating writers use the same protocol:** Password change/reset, account deactivation and relevant company membership/eligibility changes must acquire the affected **User lock before changing eligibility**, followed by affected Sessions (deterministic UUID order) and Tokens (deterministic ID order). Account-wide revocation updates credential/eligibility state and revokes families atomically. Logout and session deletion lock User → target Session → Tokens, set `revoked_at` and delete current credentials in one transaction. A refresh committed before revoke is erased by the succeeding revoke; one arriving after revoke emits nothing. No valid successor may survive completed revocation.

**Admission uses the same serialization boundary (new clarification):** Password Login and both 2FA completion paths acquire **User `FOR UPDATE` before creating a Session or Tokens**. Any pre-lock credential or eligibility check is **non-authoritative**. Under that lock, re-read active account and applicable membership eligibility **and recheck the submitted password against the current stored credential, or prove an approved credential-version match bound to the admission attempt**. For 2FA, the interim challenge must be bound to the admission credential version and revalidated under protection. Session and token issuance happen before commit. If reset/deactivation/membership invalidation wins the User lock first, admission fails; if admission wins, the later invalidation revokes the newly created family. Writers that can make a login ineligible must participate; there can be no uncovered eligibility update path.

**Contention result:** The normal path waits, then performs post-lock revalidation. Return 409 `REFRESH_IN_PROGRESS` only when an implementation can positively prove a concurrent rotation is still holding the relevant generation. A stale generation after waiting is 401, never 409. An unclassified database timeout/deadlock is a transient server failure, not proof of rotation or logout.

### 4.3 Lost Refresh Response & Transport Uncertainty (D06 / D07)
- **Uncertain Transport Outcome:** A network timeout, dropped TCP connection, or HTTP 502/503/504 gateway timeout does **not** prove the refresh token was not consumed. The server may have committed the transaction before the response was lost, or the request may never have reached the server.
- **Client Handling:** The client must recognize transport uncertainty. If the client retries with the same refresh token:
  - If the server previously aborted/did not execute: The token is still valid; rotation succeeds.
  - If the server previously committed: The token was consumed; server rejects with 401 `INVALID_TOKEN` (fail-closed).
- **No Retrying Consumed Tokens:** Once an explicit 401 `INVALID_TOKEN` proves that the presented refresh token is invalid or consumed, the client must not reuse that token. If no newer credential is available through authorized S1-13 coordination, request a fresh login.
- **Ambiguous Transport:** A network timeout or 5xx alone does not prove consumption, revocation or logout. S1-13 must coordinate the unresolved result across tabs, guard against late responses after logout, and avoid concurrent reuse of a known consumed token.
- **No Undesigned Grace Window:** Fail-closed v1 explicitly requires re-authentication upon confirmed consumed token presentation, without storing encrypted tokens or promising an unverified grace window.

### 4.4 Expired-Access Logout & Ownership Proof (D08)
- When an access token has expired (15m), the client cannot authenticate via Bearer token.
- The client calls `POST /api/v1/auth/logout` supplying `{ "refreshToken": "<valid-plain-token>" }` in the request body.
- The server validates the refresh token secret, exact stored `['admin:refresh']` ability, current generation, expiry and family under User → Session → Token locks. **Session state and token revocation commit atomically even if external device cleanup fails.**
- **FCM device cleanup is a separate reliable/best-effort post-commit action**, with retry/failure recording as appropriate. Never call FCM before the revocation transaction in a way that can block or roll back access revocation. Preserve device-registration cleanup behavior without leaving active credentials on external-service failure.
- **Security Invariant:** The refresh token possesses 40+ characters of cryptographic entropy. Possessing it proves ownership of the session family. The endpoint **never** accepts a bare `sessionId` without the cryptographic refresh credential, preventing unauthenticated denial-of-service against arbitrary sessions.

### 4.5 D09 Stable Session Alias and Legacy Tokens
The family UUID `sessionId` is the new stable identity. The existing numeric-string `id` in `{data:[...]}` is a compatibility identifier with different semantics. For each newly issued family, persist an immutable, unique `legacy_access_token_id` alias on `admin_sessions`, assigned from its first access token ID. Rotation may delete that token row but never deletes or changes this alias. A numeric DELETE resolves this alias from the session table, checks ownership, and revokes the family. A UUID DELETE resolves `sessionId` directly. Looking up only `PersonalAccessToken::find(oldId)` is insufficient.

Legacy Admin tokens with no family remain distinct token entries in compatibility mode: list `id` as their actual token ID and `sessionId: null`; numeric DELETE revokes only that owned token, with no invented access/refresh pairing. Do not auto-attach old tokens to a family. At strict activation, explicitly expire/revoke those familyless credentials and require a compatible re-login, subject to the announced transition. The alternate staged S1-13 ID migration would require removing the old-ID guarantee; this draft recommends the persistent alias as the smaller compatibility change.

### 4.6 D11 Remember Me and 2FA Challenge
For password sign-in without 2FA, `rememberMe` determines the immutable family end. After preliminary validation, **lock User and revalidate the current password or bound credential version, active status and company eligibility before issuing the family inside the same transaction**. When 2FA is required, bind user ID, Remember Me choice, challenge expiry and credential version server-side at challenge creation; completion cannot increase that choice. Under the User lock, revalidate eligibility and credential/challenge version, then claim/consume the challenge with a **proven atomic operation in the configured shared backing store** (e.g., a DB compare-and-set or verified Redis atomic primitive; a `Cache::get` followed by `Cache::forget` is *not* atomic). Concurrent completion must yield **at most one session family**; failed issuance may require re-login rather than replay. The existing direct **Password + Code** path must use equivalent guarded admission and cannot bypass the one-use/session-creation control. Preserve the bare login success object and `{requires2fa,twoFactorToken}` response shape.

---

## 5. Admin Route Surface Enforcement Contract

At strict activation, all protected Admin routes in `Modules/Admin` (`/api/v1/...`) must enforce:

1. **Access-Token Type Verification:**
   - The token presented in `Authorization: Bearer <token>` must have the exact stored ability set `['admin:access']`, correct Admin tokenable type and an active family. Do not use `tokenCan()` alone: Sanctum accepts wildcard ability tokens.
   - Authenticated tokens carrying only `admin:refresh` are rejected with 403 `FORBIDDEN_SCOPE`.
2. **Explicit Expiry Enforcement:**
   - Every request checks the `expires_at` column in `personal_access_tokens`. If `now() >= expires_at`, the token is rejected with 401 `TOKEN_EXPIRED`.
3. **Session-Family Active State Verification:**
   - The token must link to an active `admin_sessions` record where `revoked_at IS NULL` and `now() < expires_at`.
   - If the parent session has been revoked or expired, the access token is invalid (401 `SESSION_EXPIRED`).
4. **Live Account Authorization:**
   - Re-verifies `user->status === 'active'`. Deactivated accounts immediately fail with 403 `USER_INACTIVE`.
5. **Wildcard & Foreign Token Isolation:**
   - Sanctum default wildcard abilities (`['*']`) from legacy seeds or foreign models must **not** bypass `admin:access` ability checks on Admin routes.
   - Tokens issued for Cashier or BranchManager (where `session_id IS NULL` and tokenable is not `AsabUser`) are strictly denied on Admin routes.
   - Impersonation tokens: `['*']` never bypasses Admin enforcement. The backend inventory at the reference SHA found one route, `POST /admin/companies/{id}/impersonate`, and one issuer, `Admin/CompanyController::impersonate`; it mints a 30-minute target-user token with `['*']` and records an issuance audit entry. Dashboard consumers were not verified in this backend-only inspection. A minimally repaired path needs explicit impersonation-only ability, durable actor/target/company binding, actor authorization and live company scope checks, a 30-minute maximum, audit correlation, and no refresh. If this cannot be proved and accepted, disable the path at strict cutover with an announced transition; do not silently alter pre-cutover behavior. No broad impersonation rebuild is part of S1-12.

---

## 6. Client Integration Constraints (Dashboard S1-13)

1. **Single-Flight Coalescing:** The Dashboard HTTP client must coalesce concurrent 401 responses into a single refresh promise, avoiding duplicate rotation requests to the backend.
2. **Financial Idempotency Preservation:** When a financial mutation encounters a 401 and triggers a token refresh, the client **must replay the exact original request** with the **identical `Idempotency-Key` header and identical request payload**. The client must never generate a new idempotency key for an auth retry.
3. **Session Generation Guard:** The client must track a local session generation counter. If an explicit logout occurs while a refresh request is in flight, the completed refresh response must be discarded and must not resurrect the authenticated state.
4. **Cross-Tab Coordination:** S1-13 must coordinate one refresh owner across tabs, publish the accepted successor to peer tabs without exposing it to unrelated origins, and handle an ambiguous network/5xx outcome without automatic logout.
5. **Activation Staging:** Premature production enforcement of the 15-minute access TTL before Dashboard S1-13 client compatibility is deployed and verified is **strictly prohibited**.

---

## 7. Verification and Acceptance Gates

Implementation of this blueprint in S1-12 requires the following evidence before technical acceptance. **No test was run while editing this draft.**
1. **Functional/contract suite:** Token issuance and guarded **new family admission**, exact stored abilities, bounded TTL, no sliding, 24h/7d choice, expired-token denial, user and membership checks, Server-side Remember Me, challenge credential-version binding, truly atomic single-use completion, direct password+code parity, existing login shape, immutable numeric alias through **multiple rotations**, and UUID/alias DELETE of the **same family** plus familyless legacy behavior.
2. **Real MariaDB/InnoDB Concurrency Race Suite (Mandatory Gate):** Multi-connection parallel test cases using independent database connections verifying:
   - *Refresh vs refresh:* One successor pair. The loser after lock sees 401 `INVALID_TOKEN`; 409 requires separate proof of a live in-flight rotation.
   - *Refresh vs password change/reset, account deactivation, logout and session deletion:* Test both transaction orders; after completed revocation, no valid successor survives.
   - *Login or 2FA new-family issuance vs password reset/change and account deactivation:* Test **both lock orders** with independent connections; no new usable family appears after a completed invalidation. Repeat for company membership/eligibility invalidation.
   - *2FA vs simultaneous 2FA completion:* Using the actual configured shared challenge store and independent workers, one challenge produces at most one family; verify direct Password + Code also obeys the admission barrier.
   - *Failure paths:* Rollback, lock wait, deadlock and transport ambiguity do not create duplicate or resurrected credentials.
3. **Negative cross-repository compatibility:** Verify Admin guard coverage and that Cashier/BranchManager Flutter tokens keep existing behavior. No global Sanctum expiration change.
4. **Execution integrity:** Record DB version, isolation level, separate connection IDs, barriers, actual commands, results and SHA. SQLite evidence never substitutes for MariaDB locking evidence.

---

## 8. Transition Matrix and Staged Activation

| Path at `41f03a31929d29fdbb494ef906f8cbe80916c9ad` | Compatibility mode | Strict cutover condition |
|---|---|---|
| Legacy Admin access/refresh without family | Keep existing behavior and list each actual token ID; numeric DELETE removes that owned token only. Do not invent family linkage or impose 15m yet. | Announce re-login; revoke/reject familyless Admin credentials at the agreed cutover. No implicit upgrade or wildcard exception. |
| New password/2FA Admin login | Issue an Admin family under the approved design only after its implementation gate; preserve bare response and challenge shape. | Enforce exact token type, expiry, generation and active family on every protected Admin route. |
| SSO callback | Existing issuer currently calls `AuthService::issueTokens`. Record use; admission security remains a separate remediation. | Either issue a compliant family after the separately approved SSO admission fix, or explicitly hold this route closed. No familyless token may pass strict Admin enforcement. |
| Invitation acceptance | Existing issuer also calls `AuthService::issueTokens`. Preserve current route before activation while separate invitation hardening is tracked. | Either issue a compliant family after admission gates are met, or explicitly hold the route closed; no silent partial sign-in. |
| Impersonation | Current 30m `['*']` token is inventoried as a known incompatibility. Do not silently change its pre-cutover behavior. | Enable only after Mahmoud accepts the narrow actor/company/audit/no-refresh repair; otherwise disable the path explicitly. Never exempt `['*']`. |

**Activation sequence:** (1) land only approved additive Admin schema behind compatibility mode; (2) implement and verify backend family lifecycle and rollback using a disposable MariaDB environment; (3) finish S1-13 cross-tab refresh, ambiguous result, logout-generation and financial retry compatibility; (4) verify origins and HOST-01 storage plan, inventory all token issuers and notify affected users; (5) retain the recorded S1-05 architecture approval and obtain **separate** S1-12 implementation authorization, S1-11 acceptance at its pinned SHA, S1-12 technical acceptance and strict deployment authorization as applicable; (6) activate strict Admin enforcement with monitoring. **No production 15-minute enforcement before S1-13 readiness.**

**Rollback:** Turn off strict enforcement for the scoped Admin cohort via the reviewed compatibility switch while retaining additive schema and revocation records. Do not restore consumed tokens, un-revoke families, recreate deleted secrets, or downgrade global Sanctum/Flutter behavior. If compatibility rollback would re-admit a known unsafe credential, prefer disabling the affected route and requiring re-login; document the operational decision. Hosting is verified in place; this work does not design a new hosting platform.

## 9. Design Baseline Acceptance, Implementation and Release Gates

**S1-05 ARCHITECTURE APPROVED** by Mahmoud on 2026-10-12, with the clarifications in this baseline. **D12 = SSO**, **D13 = Invitations**, **D14 = Impersonation**; **HOST-01** independently covers browser credential storage and existing hosting. The S1-12 Task Branch is permitted for preparation only until Mahmoud issues **S1-12 BACKEND IMPLEMENTATION AUTHORIZED** explicitly. This baseline is **documentation, not implemented or tested code**.

Still open *for cutover/release*, not for the separate S1-12 backend coding permission: HOST-01 after origin verification; S1-13 client compatibility; SSO/invitation admission remediation or expressly held routes; narrow, independently accepted impersonation repair or announced disabled route; announced legacy Admin re-login; MariaDB/InnoDB test evidence and independent audit. **S1-11 acceptance, S1-12 technical acceptance, Commit, Push, PR, Merge and strict deployment are separate actions/permissions.** Do not edit Foundation/Main, rebase/merge/force-push, change Flutter, global Sanctum expiration, financial logic or shared idempotency. Recommended later tools: **Gemini Pro High** for authorized implementation and **Codex Sol Medium** for independent audit.
