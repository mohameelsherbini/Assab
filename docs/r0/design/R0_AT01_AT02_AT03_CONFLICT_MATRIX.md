# ASSAB ERP — R0 / AT01–AT03 Conflict Matrix (Evidence Update)

> **PUBLICATION STATUS: DRAFT / PENDING MAHMOUD APPROVAL.** This matrix supports review only. It does not approve the Final DDL, R0 implementation, integration, migration, or deployment.

**Repository:** `mohameelsherbini/Assab`
**GitHub branch checked:** `test/s1-12`
**Verified GitHub branch SHA:** `73798289d6244d303914f7da6b48208821983165`
**R0 proposed baseline (unchanged):** `task/s1-12-cloud-restart` at `84405955eeb1b72d7fab5e96c371c263a830a212`
**Evidence cutoff:** 2026-10-14. Read-only GitHub branch/ref and commit/compare API inspection; no test, database operation, patch application, or local checkout was performed.

## Verified branch ancestry and commits

GitHub reports `test/s1-12` at `73798289d6244d303914f7da6b48208821983165`. Comparing it with the R0 baseline gives four commits ahead and zero behind, with the baseline SHA as the merge base. The exact commits and per-commit files are:

| Slice | Commit | Changed paths from GitHub commit API |
|---|---|---|
| AT01 | [`df4a31f1fdd5dd8666343df8ca1f36f183f860a3`](https://github.com/mohameelsherbini/Assab/commit/df4a31f1fdd5dd8666343df8ca1f36f183f860a3) | `Modules/Admin/app/Services/AuthService.php`; `tests/Feature/AdminSessionRevocationTest.php` |
| AT02 | [`e380b33a1f115cc6565f1d39e003d654d50165c9`](https://github.com/mohameelsherbini/Assab/commit/e380b33a1f115cc6565f1d39e003d654d50165c9) | `Modules/Admin/app/Services/AuthService.php`; `tests/Feature/AdminLegacyRefreshConcurrencyTest.php`; `tests/Feature/AdminSessionRefreshTest.php` |
| AT03 | [`b7bc3f7bd938cd4734310913b4efccd3e7798f11`](https://github.com/mohameelsherbini/Assab/commit/b7bc3f7bd938cd4734310913b4efccd3e7798f11) | `Modules/Admin/app/Http/Middleware/EnsureAdminAccessToken.php`; `Modules/Admin/routes/api.php`; `bootstrap/app.php`; `tests/Feature/AdminAccessTokenBoundaryTest.php` |
| Post-AT review candidate publication | [`73798289d6244d303914f7da6b48208821983165`](https://github.com/mohameelsherbini/Assab/commit/73798289d6244d303914f7da6b48208821983165) | `Modules/Admin/app/Http/Middleware/EnsureAdminAccessToken.php`; `Modules/Admin/app/Services/AuthService.php`; `tests/Feature/AdminAccessTokenBoundaryTest.php`; `tests/Feature/AdminSessionRefreshTest.php`; `tests/Feature/AdminSessionRevocationTest.php` |

The aggregate compare reports exactly these **eight unique paths**:

1. `Modules/Admin/app/Http/Middleware/EnsureAdminAccessToken.php`
2. `Modules/Admin/app/Services/AuthService.php`
3. `Modules/Admin/routes/api.php`
4. `bootstrap/app.php`
5. `tests/Feature/AdminAccessTokenBoundaryTest.php`
6. `tests/Feature/AdminLegacyRefreshConcurrencyTest.php`
7. `tests/Feature/AdminSessionRefreshTest.php`
8. `tests/Feature/AdminSessionRevocationTest.php`

## R0 manifest intersection and conflict analysis

The unchanged ten-file R0 candidate manifest is listed in `R0_IMPL01_PROPOSED_EXECUTION_PLAN.md`. Its intersection with the eight AT paths is **empty**. The only existing/shared R0 candidate path, `Modules/Admin/app/Providers/AdminServiceProvider.php`, is not modified by AT01, AT02, or AT03. The other nine R0 paths are outside the AT changed-path set.

| Workstream | File overlap with fixed R0 manifest | Hunk conflict | Runtime/data dependency | Evidence and disposition |
|---|---|---|---|---|
| AT01 | None. AT01 changes `AuthService.php` and `AdminSessionRevocationTest.php`; neither is in the R0 manifest. | None within the fixed file set: there is no shared path. | No direct dependency: R0 is additive ledger/census work and excludes auth/session/token code. | Verified from commit `df4a31f…` and compare API. |
| AT02 | None. AT02 changes `AuthService.php`, `AdminLegacyRefreshConcurrencyTest.php`, and `AdminSessionRefreshTest.php`; none is in the R0 manifest. | None within the fixed file set: there is no shared path. | No direct source/runtime dependency in the proposed R0 boundary. AT02's MariaDB test environment must remain separate from R0's disposable schema. | Verified from commit `e380b33…` and compare API. Test execution itself was not verified. |
| AT03 | None. AT03 adds middleware and its test, and changes `routes/api.php` and `bootstrap/app.php`; none is in the R0 manifest. | None within the fixed file set: there is no shared path. | No direct dependency: R0 does not modify auth gates, middleware, routes, or request-time authority decisions. | Verified from commit `b7bc3f7…` and compare API. Test execution itself was not verified. |
| Post-AT review candidate publication | None. It changes five paths already inside the eight-path AT aggregate; none is in the R0 manifest. | None within the fixed file set: there is no shared path. | No new R0 dependency identified; the commit explicitly remains a review candidate rather than accepted work. | Verified from commit `73798289…` and the baseline-to-head diff. Its stated test results were not independently rerun for this package. |

**Conclusion:** No documented file or hunk conflict blocks the current ten-file R0 scope. This is a changed-path conflict assessment, not an integration test or full security review. No cross-branch merge/patch operation has been performed. Do not expand R0 to auth/session/token/routes/middleware to address unrelated future findings.

## MariaDB and isolation evidence

AT02's committed concurrency test is written to use independent worker connections, inspect InnoDB lock waits, and emit a `SELECT VERSION()` result and table engines. The GitHub commit proves that test code exists; it does **not** prove that tests passed or establish the currently deployed MariaDB version. No MariaDB query was run for this update. R0 must use its own disposable MariaDB/InnoDB database/schema, with a distinct name and no shared AT test state.

The proposed R0 DDL relies on enforced `CHECK` constraints and triggers. Target MariaDB version compatibility, `CHECK` enforcement, trigger creation privileges/semantics, and migration rollback behavior remain **NOT VERIFIED** and are still final DDL approval gates. See the final package's DDL/test/rollback sections. No SQL, migration, census, or test was run here.
