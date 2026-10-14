# ASSAB ERP — R0-IMPL01 Proposed Execution Plan (Final)

> **PUBLICATION STATUS: DRAFT / PENDING MAHMOUD APPROVAL.** This plan is not implementation authority. Final DDL acceptance and explicit R0-IMPL01 local implementation authorization are still required.

**Authorization status:** Scope conditionally accepted; implementation authorization pending final DDL review.
**Repository/base:** `mohameelsherbini/Assab`, `task/s1-12-cloud-restart`, `84405955eeb1b72d7fab5e96c371c263a830a212` (unchanged).
**AT evidence branch:** `test/s1-12` at `73798289d6244d303914f7da6b48208821983165`; the three AT commits plus their review-candidate publication commit are verified at path level and do not overlap the ten-file candidate manifest.

## Fixed boundaries

R0-IMPL01 may establish the approved additive ledger schema and run a sanitized, strictly read-only legacy census. Census output is evidence only: it must not insert a grant, activate a grant, backfill roles, alter existing tables, or change request-time authorization. `asab_user_roles` remains a projection, not proof. Source liveness must be checked independently by any future authorized consumer.

Excluded: all AT01–03 edits; AuthService/AuthController/AdminSession/CredentialSyncService; refresh, revocation, tokens, routes, permission middleware/security gate, company status/suspension writers; SSO and invitations; existing authority writers/readers; PKG integrations; M05; data migration/backfill; cutover; Git publication.

## Exact ten-file candidate manifest

This is the previously specified candidate list. It is exact and closed; no extra file is authorized. The migration and DDL are still subject to review. `AdminServiceProvider.php` may be modified only to register the census command, if required.

1. `Modules/Admin/database/migrations/2026_10_13_000003_create_asab_authority_grant_registry.php`
2. `Modules/Admin/app/Models/AuthorityGrant.php`
3. `Modules/Admin/app/Models/AuthorityGrantScope.php`
4. `Modules/Admin/app/Models/AuthorityGrantEvent.php`
5. `Modules/Admin/app/Services/AuthorityGrantCensusService.php`
6. `Modules/Admin/app/Console/Commands/AuthorityGrantCensusCommand.php`
7. `Modules/Admin/app/Providers/AdminServiceProvider.php` — command registration only, conditional.
8. `tests/Feature/AuthorityGrantSchemaTest.php`
9. `tests/Feature/AuthorityGrantCensusReadOnlyTest.php`
10. `tests/Unit/AuthorityGrantCensusClassificationTest.php`

The three models are included because they were part of the accepted candidate list; the implementation must keep them passive and must not introduce grant writers or authorization readers. Documentation artifacts are outside the repository and are not implementation files.

## Baseline and preimage verification plan

Before any separately authorized implementation:

- Read GitHub `refs/heads/task/s1-12-cloud-restart` and confirm it still equals `84405955…`; do not substitute PKG branches or AT patch heads.
- Use a newly isolated worktree detached at that SHA, confirm `HEAD`, parent, clean status, and that no existing active worktree is reused.
- Verify the existing preimage for `Modules/Admin/app/Providers/AdminServiceProvider.php` from `git rev-parse 84405955…:Modules/Admin/app/Providers/AdminServiceProvider.php` and byte-level hash before editing it.
- Confirm the other nine candidate paths are absent at baseline. Record every baseline blob SHA for any preexisting path; compare the resulting diff against the closed manifest.
- Reconfirm the published AT01–03 branch/path evidence against the then-current GitHub refs, and use a uniquely named disposable MariaDB database separate from AT tests. Their commits are currently verified, with no path overlap; test execution and DB identifiers remain unverified. If the reference moves or a target file exists unexpectedly, stop and request refreshed authorization.

This task verified the GitHub branch head via the API. The local checkout is `work` at `18ec7e35…`, clean, and does not contain the expected commit object; it was not used as the baseline.

## Ordered work after authorization

1. Revalidate baseline and patch evidence; stop on drift or overlap.
2. Implement only the Mahmoud-approved DDL in the named migration. Do not alter existing tables.
3. Add passive Eloquent models and the read-only census service/command. Do not provide a grant creation, activation, projection or runtime decision API.
4. Register the command in the provider only if registration is required; do not schedule it.
5. Run the specified tests only on a disposable MariaDB/InnoDB database and preserve the evidence report. No schema or live-data testing.
6. Deliver a local diff and test results for independent review. No commit or publication is implied by this authorization package.

## Acceptance boundary

Acceptance requires MariaDB schema/constraint tests, read-only census proof, legacy-only rows remaining ungranted, source uniqueness by generation, independent revocation/tombstone behavior, rollback refusal after population, and zero authority activation. Exact cases are in the Final Authorization Package. A passing test suite does not approve activation or integration.

**Current disposition:** preparation complete; wait for Mahmoud's explicit `R0-IMPL01 Local Implementation Authorization` after DDL review.
