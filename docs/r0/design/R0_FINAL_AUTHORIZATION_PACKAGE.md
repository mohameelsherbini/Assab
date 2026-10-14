# ASSAB ERP — R0-IMPL01 Final Authorization Package

> **PUBLICATION STATUS: DRAFT / PENDING MAHMOUD APPROVAL.** R0-A is approved in principle and the ten-file candidate scope is conditionally accepted. Every Final DDL choice and R0-IMPL01 implementation action remains pending explicit Mahmoud approval. Publication on a TEST branch is evidence distribution only and is not technical acceptance.

**Mode:** READ-ONLY / SPECIFICATION FINALIZATION
**Decision:** **R0-A DESIGN APPROVED IN PRINCIPLE — R0-IMPL01 SCOPE CONDITIONALLY ACCEPTED — IMPLEMENTATION AUTHORIZATION PENDING FINAL DDL REVIEW.**
**Repository:** `mohameelsherbini/Assab`
**R0 reference baseline (unchanged):** `task/s1-12-cloud-restart` at `84405955eeb1b72d7fab5e96c371c263a830a212`
**Updated evidence branch:** GitHub `test/s1-12` at `73798289d6244d303914f7da6b48208821983165`
**Local:** `/workspace/Assab`, `work` at `18ec7e35f3c6d37e5b199a1b2812b5086b43e1b3`, clean and different from both GitHub refs.

This document contains a reviewable DDL proposal, integrity matrices, preimage plan, AT conflict status, MariaDB test matrix, rollback design and future isolation/publication plan. It does not grant implementation permission.

## 1. Reference and evidence status

Git independently reports `task/s1-12-cloud-restart` at the proposed R0 baseline `84405955…` and `test/s1-12` at `73798289…`. The latter is four commits ahead, zero behind, with `84405955…` as merge base. The local workspace remains `/workspace/Assab`, branch `work`, HEAD `18ec7e35…`, clean, and is not either reference. No application code, migration, database or test was changed or run for this design package.

The actual AT01–AT03 commit SHAs, their per-commit paths, and the later review-candidate publication commit `73798289…` are verified through GitHub. The complete range still has the same eight-path aggregate and an empty intersection with the fixed ten-file R0 manifest; `AdminServiceProvider.php` is not modified. With no common paths, there is no same-file hunk conflict within this manifest. There is no direct runtime dependency in the R0 design boundary. See the updated [conflict matrix](R0_AT01_AT02_AT03_CONFLICT_MATRIX.md). This establishes path-level evidence, not independent execution/pass status for AT tests.

## 2. Proposed three-table DDL — documentation only

The SQL below is a concrete candidate for review, not approved production DDL. It uses UUID `CHAR(36)` consistently with the existing `asab_users` and `asab_companies` migrations. UUID columns inherit the database's default character set/collation to match existing UUID columns; the authority partition key has a fixed ASCII binary collation so partition equality is exact. No nullable column participates in a unique key.

```sql
CREATE TABLE asab_authority_grants (
  id CHAR(36) NOT NULL,
  subject_user_id CHAR(36) NOT NULL,
  authority_plane VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  authority_company_id CHAR(36) NULL,
  authority_partition_key VARCHAR(44) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_kind VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_identity VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_generation BIGINT UNSIGNED NOT NULL,
  source_origin VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  role_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ag_source_generation (source_kind, source_identity, source_generation),
  UNIQUE KEY uq_ag_id_generation (id, source_generation),
  UNIQUE KEY uq_ag_id_partition (id, authority_partition_key),
  KEY ix_ag_subject_plane (subject_user_id, authority_plane),
  KEY ix_ag_company_subject (authority_company_id, subject_user_id),
  CONSTRAINT ck_ag_generation CHECK (source_generation > 0),
  CONSTRAINT ck_ag_source_identity CHECK (CHAR_LENGTH(source_identity) > 0),
  CONSTRAINT ck_ag_plane_partition CHECK (
    (authority_plane = 'platform' AND authority_company_id IS NULL
      AND authority_partition_key = 'PLATFORM') OR
    (authority_plane = 'company' AND authority_company_id IS NOT NULL
      AND authority_partition_key = CONCAT('COMPANY:', authority_company_id))
  )
) ENGINE=InnoDB;

CREATE TABLE asab_authority_grant_scopes (
  id CHAR(36) NOT NULL,
  grant_id CHAR(36) NOT NULL,
  source_generation BIGINT UNSIGNED NOT NULL,
  scope_plane VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  scope_company_id CHAR(36) NULL,
  authority_partition_key VARCHAR(44) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  resource_type VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  resource_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ags_id_grant (id, grant_id),
  UNIQUE KEY uq_ags_scope (grant_id, authority_partition_key, scope_plane, resource_type, resource_id),
  KEY ix_ags_resource (scope_plane, authority_partition_key, resource_type, resource_id),
  CONSTRAINT ck_ags_plane_partition CHECK (
    (scope_plane = 'platform' AND scope_company_id IS NULL
      AND authority_partition_key = 'PLATFORM') OR
    (scope_plane = 'company' AND scope_company_id IS NOT NULL
      AND authority_partition_key = CONCAT('COMPANY:', scope_company_id))
  ),
  CONSTRAINT fk_ags_grant_generation FOREIGN KEY (grant_id, source_generation)
    REFERENCES asab_authority_grants (id, source_generation) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_ags_grant_partition FOREIGN KEY (grant_id, authority_partition_key)
    REFERENCES asab_authority_grants (id, authority_partition_key) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE asab_authority_grant_events (
  id CHAR(36) NOT NULL,
  grant_id CHAR(36) NOT NULL,
  source_generation BIGINT UNSIGNED NOT NULL,
  scope_id CHAR(36) NULL,
  event_sequence INT UNSIGNED NOT NULL,
  event_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_kind VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_user_id CHAR(36) NULL,
  event_payload JSON NULL,
  occurred_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_age_sequence (grant_id, event_sequence),
  UNIQUE KEY uq_age_event_key (grant_id, event_key),
  KEY ix_age_timeline (grant_id, occurred_at),
  KEY ix_age_type_time (event_type, occurred_at),
  KEY ix_age_actor (actor_user_id, occurred_at),
  CONSTRAINT ck_age_sequence CHECK (event_sequence > 0),
  CONSTRAINT ck_age_type CHECK (event_type IN
    ('recorded','activated','revoked','source_observed','scope_added','scope_revoked')),
  CONSTRAINT ck_age_actor CHECK (
    (actor_kind = 'user' AND actor_user_id IS NOT NULL) OR
    (actor_kind IN ('system','service') AND actor_user_id IS NULL)
  ),
  CONSTRAINT fk_age_grant_generation FOREIGN KEY (grant_id, source_generation)
    REFERENCES asab_authority_grants (id, source_generation) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_age_scope_grant FOREIGN KEY (scope_id, grant_id)
    REFERENCES asab_authority_grant_scopes (id, grant_id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB;
```

### Proposed integrity triggers

The migration candidate includes the following triggers in the same migration file. Six reject UPDATE/DELETE on grants, scopes and events. The seventh requires each source generation's first event to be `recorded`, contiguous sequence numbers, and no event after a `revoked` tombstone. A legitimate later grant must be a separately evidenced new source generation, never a rewrite of a revoked one. Trigger creation requires MariaDB support/privileges and must be verified on the disposable database before authorization. These definitions are part of the proposed DDL, not executed here.

```sql
CREATE TRIGGER trg_ag_no_update BEFORE UPDATE ON asab_authority_grants
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'authority grant rows are immutable';
CREATE TRIGGER trg_ag_no_delete BEFORE DELETE ON asab_authority_grants
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'authority grant rows are immutable';
CREATE TRIGGER trg_ags_no_update BEFORE UPDATE ON asab_authority_grant_scopes
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'authority scope rows are immutable';
CREATE TRIGGER trg_ags_no_delete BEFORE DELETE ON asab_authority_grant_scopes
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'authority scope rows are immutable';
CREATE TRIGGER trg_age_no_update BEFORE UPDATE ON asab_authority_grant_events
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'authority events are append-only';
CREATE TRIGGER trg_age_no_delete BEFORE DELETE ON asab_authority_grant_events
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'authority events are append-only';

CREATE TRIGGER trg_age_before_insert
BEFORE INSERT ON asab_authority_grant_events
FOR EACH ROW
BEGIN
  DECLARE v_last_sequence INT UNSIGNED DEFAULT 0;
  DECLARE v_has_revocation TINYINT DEFAULT 0;
  SELECT COALESCE(MAX(event_sequence), 0), COALESCE(MAX(event_type = 'revoked'), 0)
    INTO v_last_sequence, v_has_revocation
    FROM asab_authority_grant_events WHERE grant_id = NEW.grant_id;
  IF NEW.event_sequence <> v_last_sequence + 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'authority event sequence must be contiguous';
  END IF;
  IF v_last_sequence = 0 AND NEW.event_type <> 'recorded' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'first authority event must be recorded';
  END IF;
  IF v_has_revocation = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'revoked source generation is terminal';
  END IF;
END;
```

The final migration must send each `CREATE TRIGGER` statement separately; client `DELIMITER` commands are not SQL and are not part of the migration. It must fail closed if trigger creation is unavailable. No grant/event rows are created by R0 census. `recorded` and `activated` are lifecycle event vocabulary, not permission to auto-activate: a future separately approved writer must bind activation to explicit approval and live-source validation. Event payloads must be minimized and must never hold tokens, passwords or secrets.

### FK and referential-action matrix

| Child columns | Parent columns | Action | Purpose / boundary |
|---|---|---|---|
| `grant_scopes(grant_id, source_generation)` | `grants(id, source_generation)` | UPDATE/DELETE RESTRICT | Scope cannot attach to another source generation. |
| `grant_scopes(grant_id, authority_partition_key)` | `grants(id, authority_partition_key)` | UPDATE/DELETE RESTRICT | Enforces same Platform/Company partition; combined with scope CHECK, prevents cross-company scope. |
| `grant_events(grant_id, source_generation)` | `grants(id, source_generation)` | UPDATE/DELETE RESTRICT | Event remains bound to its exact source generation. |
| `grant_events(scope_id, grant_id)` | `grant_scopes(id, grant_id)` | UPDATE/DELETE RESTRICT | Optional scoped event cannot refer to another grant's scope. `scope_id=NULL` means grant-level event. |
| `grants.subject_user_id` | No FK by design | — | Preserve immutable historical subject ID and avoid changing existing physical-user-delete behavior. It is not evidence that the user remains active. |
| `grants.authority_company_id`, `scopes.scope_company_id` | No FK by design | — | Retain historical company IDs and avoid changing existing company-delete behavior. Company existence/status must be independently validated by any later consumer. |
| `events.actor_user_id` | No FK by design | — | Preserve historical actor ID without blocking deletion; actor kind and reference do not prove current account status. |

There are no cascading deletes. The absence of external FKs is deliberate and must be approved: foreign keys to existing user/company tables could alter current deletion behavior, while the ledger must not be treated as a liveness oracle.

### Unique, index, generation, scope and event matrix

| Rule | DDL element | Integrity property | Remaining enforcement/approval |
|---|---|---|---|
| One source instance and generation maps to at most one grant row | `uq_ag_source_generation(source_kind, source_identity, source_generation)`; all columns NOT NULL | No nullable-composite duplicate bypass; generation is source-owned and positive. | Approve source-kind vocabulary, identity canonicalization and generation semantics. Never infer a generation from `updated_at` or a role row. |
| Composite FK targets are indexable | `uq_ag_id_generation`, `uq_ag_id_partition` | Scope/event FKs bind to exact generation and exact authority partition. | Validate MariaDB FK/index behavior in target version. |
| Scope is unique inside its grant and partition | `uq_ags_scope(grant_id, authority_partition_key, scope_plane, resource_type, resource_id)`; all NOT NULL | Duplicate scope cannot hide behind NULL company IDs. | Approve resource vocabulary and whether wildcard scopes are forbidden; default proposal is no implicit wildcard. |
| Platform/company separation | Plane/partition CHECKs plus composite scope-to-grant FK | Platform scope requires null company and `PLATFORM`; company scope requires a company UUID and `COMPANY:<uuid>` equal to its grant. | CHECK constraints must be enforced by target MariaDB; test both valid and invalid cases. |
| Event sequence and idempotency | `uq_age_sequence`, `uq_age_event_key`; trigger requires first `recorded`, contiguous sequence, and terminal revoke | Duplicate/reordered/replayed event writes fail; revoked generation cannot receive later lifecycle events. | Future event writers need a transaction/serialization rule for sequence allocation. No writer is in R0. |
| Immutable ledger/evidence | BEFORE UPDATE/DELETE rejection triggers on all tables; no FK cascade | History and tombstones cannot be edited/deleted by ordinary DML. | Confirm trigger privilege, DB admin bypass policy, and emergency recovery. Table DROP remains privileged; migration guard prevents accidental rollback. |
| Query support | `ix_ag_subject_plane`, `ix_ag_company_subject`, `ix_ags_resource`, `ix_age_timeline`, `ix_age_type_time`, `ix_age_actor` | Supports census/review reads by subject, scope and timeline. | Performance is unmeasured; revisit only with census workload evidence. |
| Source liveness | No `is_live` grant flag; `source_observed` is an event only | Ledger presence/last observation does not assert current source liveness. | Future enforcement must independently prove live source state; no fallback to legacy roles. |
| Revocation | Immutable `revoked` event terminal for that generation | Revocation is independent and cannot be reversed by updating a row or changing a feature gate within this ledger. | Runtime consumers and feature-gate precedence are out of R0; must never fall back to role projection after activation. |

## 3. Disposable MariaDB/InnoDB test matrix

All tests below are proposed for a uniquely named disposable database, with recorded server version and InnoDB confirmation. No test was run in this task.

| Test | Setup/action | Expected result |
|---|---|---|
| DDL compatibility | Apply migration on disposable MariaDB matching intended deployment version; inspect tables, CHECKs, FKs and triggers. | All three tables and trigger definitions are active; no existing table changed. Unsupported checks/triggers fail the gate. |
| Platform/company partition | Insert valid platform grant/scope and valid company grant/scope; attempt company ID under platform key, wrong-company scope, or platform scope on company grant. | Valid rows accepted; every mismatch rejected by CHECK/FK. |
| No cross-company scope | Grant partition for Company A; attempt scope partition for Company B. | FK rejects it. A grant can never carry a different company's scope. |
| Safe uniqueness | Duplicate source identity+generation; duplicate scope; duplicate event key; duplicate sequence. | Every duplicate rejected regardless of nullable company fields. Same source with explicitly new generation is structurally distinct. |
| Source generation integrity | Attach scope/event with wrong or absent generation; use generation 0. | FK/CHECK rejects it. Census may classify unknown generation but must not insert a grant. |
| Legacy-only data | Seed only legacy role, identity-map, invitation/membership and assignment rows in disposable fixtures; run the census. | Classified/reported only. Ledger remains empty; no activated/recorded grant is inferred from those rows. |
| Census read-only | Capture row counts and DML/DDL query events before/after census. Include credential-like fixture values. | No INSERT/UPDATE/DELETE/DDL; output redacts secrets; results do not imply authority activation. |
| Independent revocation | In test fixtures only, record a generation and revoke it; try a later activation/event for same generation; create a separate new generation without activating it. | Post-revoke event on same generation rejected; new generation is distinct and not auto-activated. |
| Append-only history | Attempt UPDATE/DELETE on each ledger table and event row. | Triggers reject changes. |
| Rollback empty ledger | Apply migration with empty tables; invoke guarded down in disposable DB. | Guard permits teardown after trigger removal and dependency-ordered table removal. |
| Rollback populated ledger | Add fixture rows, then invoke down. | Guard refuses before any trigger/table/data drop; rows and events remain intact. |
| No authority activation | Run all census fixtures, toggle no feature gate (none is part of R0), inspect ledger. | No grants/events are produced by census; no legacy role becomes effective authority. |
| Partial DDL failure | In disposable DB, inject failure at each creation boundary and inspect remaining objects. | Recovery is documented and non-destructive; no automatic cleanup drops populated tables. |

The test plan does not establish concurrent grant-writer safety: R0 contains no writer. A future writer requires separate MariaDB concurrency design/tests and approval.

## 4. Data-safe rollback strategy

1. The migration's `down()` first checks all three tables. If any row exists in grants, scopes or events, it throws and makes no schema change.
2. Empty-schema teardown removes the event insertion/immutability triggers first, then drops events, scopes and grants in FK dependency order. No cascade is used.
3. Once populated, the supported rollback is to disable future readers/writers only under a separately approved deployment plan; retain all three tables and their revocation history. Do not drop, truncate, restore an older DB snapshot over them, or turn on a legacy-role fallback.
4. A revoked generation is terminal in event triggers. Any future authority must be represented by a separately proven source generation and explicit authorization; feature-gate changes cannot transform a revoked event into a grant.
5. MariaDB DDL can commit implicitly. Migration failures require a documented manual inspection of created tables/triggers; automatic cleanup must not delete data. Administrative DROP remains technically possible and is outside application-level protection, so production privileges/backup policy remain an unresolved operational control.

## 5. Ten-file manifest and baseline preimage

The closed implementation candidate is exactly the ten paths in [R0_IMPL01_PROPOSED_EXECUTION_PLAN.md](R0_IMPL01_PROPOSED_EXECUTION_PLAN.md). In summary: one migration; three models; census service and command; provider command registration only; two feature tests and one unit test. No additional path is in scope.

Before authorized work, verify current remote ref SHA, detached worktree HEAD, parent, clean state, all preexisting path blob SHAs, and absence of the other nine paths at baseline. Compare the final diff against the manifest. If any path or preimage differs, stop for a refreshed approval. The local workspace SHA is not the baseline.

## 6. Future isolated worktree and Git publication plan

| Item | Proposed value (not created/approved as a Git action) |
|---|---|
| Repository | `mohameelsherbini/Assab` |
| Base | `task/s1-12-cloud-restart` at exact SHA `84405955eeb1b72d7fab5e96c371c263a830a212` |
| Worktree | Separate directory such as `/workspace/Assab-r0-impl01`, detached at baseline before any authorized branch is created |
| Candidate branch | `task/s1-12-r0-impl01` |
| Implementer | Codex Cloud executor, only after explicit Mahmoud local implementation authorization |
| Acceptance owner | Mahmoud; no acceptance implied by passing tests |
| Publication | Local diff/evidence first. Commit/push/PR/merge only under a later, separate explicit Git authorization. Never publish to or integrate with `test/s1-12` or an AT branch in this scope. |
| Database | Unique disposable MariaDB/InnoDB instance/schema; never shared with AT01–03 or development data. |

Separate worktrees isolate files and indexes, not shared databases. AT01–03 commits and changed paths are now verified; their aggregate has no path overlap with the fixed R0 manifest. AT02 test code specifies separate worker connections and checks InnoDB lock waits, but its execution result and database identifier are not verified here. Before future implementation, use a uniquely named disposable MariaDB/InnoDB schema and record its version; do not share it with AT tests or development data. If baseline drift or an unexpected preimage appears, stop and request a refreshed decision.

## 7. DDL decisions still requiring Mahmoud's approval

- Confirm the exact columns, source-kind/origin vocabulary, identity normalization, and source generation ownership/meaning.
- Confirm scope resource vocabulary, wildcard prohibition/semantics, and the explicit `PLATFORM` / `COMPANY:<UUID>` partition representation.
- Confirm whether the deliberate lack of external user/company/actor FKs is acceptable to preserve historical identity and existing delete behavior.
- Confirm MariaDB minimum version and privileges for enforced CHECK constraints and triggers; decide whether terminal revoke and append-only enforcement belong in these DDL triggers.
- Confirm event type/sequence semantics, payload retention/redaction, actor identity retention, and transactional writer rules for future work.
- Confirm migration down guard and operational policy for populated registries, backups, and privileged manual DDL.
- The AT01–AT03 commit SHAs and changed paths are verified. AT test execution/pass evidence and their actual test database identifiers remain NOT VERIFIED; preserve separate disposable databases during any later authorized validation.
- Confirm whether `AdminServiceProvider.php` command registration is required; if not, leave it untouched. No substitute file may be added without new authorization.

**Stop condition:** This package is ready for DDL review only. Wait for Mahmoud's explicit **R0-IMPL01 Local Implementation Authorization** before creating the worktree, writing code, running migrations, census or tests, or changing Git refs.
