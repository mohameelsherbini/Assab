# ASSAB ERP — R0 Readiness Assessment (Final)

> **PUBLICATION STATUS: DRAFT / PENDING MAHMOUD APPROVAL.** R0-A is approved in principle and the ten-file candidate scope is conditionally accepted. The Final DDL and R0-IMPL01 implementation remain unapproved and must not be implemented from this document.

**Mode:** Read-only / specification finalization
**Repository:** `mohameelsherbini/Assab`
**Reference branch/SHA:** `task/s1-12-cloud-restart` / `84405955eeb1b72d7fab5e96c371c263a830a212`
**Updated GitHub evidence branch:** `test/s1-12` / `73798289d6244d303914f7da6b48208821983165`.
**Local checkout:** `/workspace/Assab`, branch `work`, HEAD `18ec7e35f3c6d37e5b199a1b2812b5086b43e1b3`, clean; distinct from both GitHub refs.

## Decision state

**R0-A design approved in principle; R0-IMPL01 scope conditionally accepted; implementation authorization pending final DDL review.** The proposed three-table direction is fixed for this review: `asab_authority_grants`, `asab_authority_grant_scopes`, and `asab_authority_grant_events`. The DDL below remains a proposal and does not authorize a migration.

GitHub confirms the R0 reference remains `84405955…` and now confirms `test/s1-12` at `73798289…`. The test branch is four commits ahead and shares the R0 reference as merge base. Local `/workspace/Assab` remains a separate clean checkout at `18ec7e35…`; it was not changed or used as a baseline.

## Confirmed GitHub facts

- `84405955…` has parent `41f03a31929d29fdbb494ef906f8cbe80916c9ad` and is the current head of `task/s1-12-cloud-restart`.
- Its S1-12 transfer commit contains 15 paths, including the session-family code/tests and the Admin Session and token-link migrations.
- The effective role projection is `asab_user_roles`; the source migration and model identify this as role assignment/projection data. It is not reliable grant evidence by itself.
- `asab_users.id` and `asab_companies.id` are UUIDs (`CHAR(36)` under the existing migration convention). The proposed ledger uses matching UUID representation for internal links. Existing company/user tables use soft deletion in their models; some controller paths perform physical deletion, so new restrictive FKs to those domain tables could change existing behavior.
- `AdminServiceProvider` loads module migrations and explicitly registers Admin commands. If needed, its only allowed R0 edit is registration of the census command; no schedule/config/auth binding change.
- Local `/workspace/Assab` is clean at `work` / `18ec7e35…`; its remote-tracking refs do not include the expected task branch and its local Git object database does not contain `84405955…`.


## AT01–AT03 evidence update

The three AT commits are `df4a31f1fdd5dd8666343df8ca1f36f183f860a3` (AT01), `e380b33a1f115cc6565f1d39e003d654d50165c9` (AT02), and `b7bc3f7bd938cd4734310913b4efccd3e7798f11` (AT03). A fourth publication commit, `73798289d6244d303914f7da6b48208821983165`, updates five of the same paths. GitHub reports eight unique changed paths for the complete baseline-to-head range; none overlaps the fixed ten-file R0 manifest. `AdminServiceProvider.php` is untouched. Thus no same-file hunk conflict exists within the proposed manifest. This is path evidence only; AT test results and MariaDB test database identifiers remain unverified. The exact path matrix is in `R0_AT01_AT02_AT03_CONFLICT_MATRIX.md`.

## Carried project decisions (relayed by the user)

A/B/C, DEC-01/DEC-02, the Authority Writers inventory, and the R0-A architectural direction are not reopened. `asab_user_roles` remains Effective Role Projection only. No automatic grant may be inferred from legacy roles, identity maps, invitations, SSO or a registry row. Grant and revocation are separate lifecycle events. Every grant has explicit source identity, source generation, origin, authority plane, scope and event history. Platform and Company authority are disjoint; one grant cannot carry scopes across companies. Ledger presence does not establish that its source is live. A revoked generation must not be revived by migration rollback or feature-gate changes.

## Readiness and gate

R0-IMPL01 is suitable for a bounded local implementation only after Mahmoud reviews and accepts the proposed DDL and the unresolved constraints listed in `R0_FINAL_AUTHORIZATION_PACKAGE.md`. The census remains SELECT-only and must classify legacy rows without inserting or activating grants. Permission middleware, session/token code, company status writers, SSO, invitations, M05 and existing grant writers/readers remain outside this scope.

**Disposition: NOT AUTHORIZED TO IMPLEMENT YET.** No code, migration, database query/census, test, Git ref, or publication action was performed in this task.
