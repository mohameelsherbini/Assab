# S12 — AT01 Freeze & AT02 Start Gate

**Authoritative decision:** Mahmoud conditionally authorized AT02, but **no AuthService edits may begin until Gemini's AT01 work is frozen and handed off**. This document coordinates the handoff; it is NOT authorization to merge patches or change scope.

## AT01: Gemini is the only writer until handoff

1. Freeze and name the exact **source SHA** and **isolated worktree** used for AT01; make no further unsynchronized edits after handoff.
2. Produce the **complete AT01 unified patch**, an **SHA256** digest, changed-file list, Git preimage hashes, postimage hashes, and exact local worktree cleanliness/change status.
3. Identify AT01's `AuthService.php` method/hunks — Numeric Alias correction and `User → Session → Token` lock ordering must remain intact; never hand off a full-file replacement as an integration shortcut.
4. Attach actual RED/GREEN, focused/adjacent regressions, disposable MariaDB connection/isolation/concurrency evidence with commands, version, connection IDs, barriers; label missing tests **NOT_RUN**.
5. Provide a precise acceptance status: `LOCAL PATCH FROZEN / INDEPENDENT AUDIT PENDING / ACCEPTED` as appropriate. **Do not infer acceptance from Gemini's own report.**

## AT02 start — Codex owner, separate worktree

**Pin base:** `mohameelsherbini/Assab`, `task/s1-12-cloud-restart`, commit `84405955eeb1b72d7fab5e96c371c263a830a212`. AT02 is NOT allowed to edit AuthService until the AT01 freeze has a reproducible patch + hashes and a method/hunk coexistence check.

**Exact allowed paths (three only):**
- `Modules/Admin/app/Services/AuthService.php` — genuinely familyless branch of `refresh()` only
- `tests/Feature/AdminSessionRefreshTest.php`
- `tests/Feature/AdminLegacyRefreshConcurrencyTest.php` — new

**Mandatory preflight:** isolated clean worktree at exact SHA; unchanged preimages, no parallel AuthService writers; document AT01/AT02 hunk separation and patch compatibility; inspect actual lock graph for Refresh/Logout/RevokeSession/Credential Changes/Company Suspension before choosing lock order. A proposed `User → Company → Token` order is NOT proof of freedom from deadlock.

**Required behavioral tests:** one-successor atomic legacy familyless rotation, recheck ID/hash/user/abilities/expiry while locked, inactive user and suspended company within current R1 limits, no credential-revoked-token revival, rollback, compatibility response. Independent-connection MariaDB concurrency: Refresh/Refresh, Refresh/Revocation, Refresh/Credential Change, transaction barriers and connection IDs. Keep AT01 changes untouched; do not migrate legacy tokens to Families. All tests not executed must be **NOT_RUN**.

**HARD STOP:** unknown AT01 patch state, preimage conflict, reverse lock order/deadlock hazard, need to touch logout() or any other method/file, or unavailable isolated infrastructure for a test claimed as passed. Escalate exact conflict and obtain scope decision.

**Deliverables:** `S12_AT02.patch`, SHA256 manifest, isolated preflight, tests and concurrency logs, single-writer evidence, remaining gates, audit-ready handoff.

**STRICT:** no Commit / Push / PR / Merge / Rebase / Deploy. **No combined integration** until a separate approval identifying base SHA + frozen input patches + disposable environment + test matrix. Later proposed order: Baseline → AT01 → AT02 → AT03.
