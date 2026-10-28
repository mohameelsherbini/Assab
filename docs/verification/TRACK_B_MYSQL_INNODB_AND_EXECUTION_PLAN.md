# Closure Sprint Track B — MySQL/InnoDB Authorization Update

**Candidate base:** Backend `54cb948c6519734faae1171a13e4ead6ddeb4df8`
**Dashboard:** `f15702cb619748860f3755ddbb1d2ad13bc69bba`
**Branch:** `task/closure-track-b-mysql-proof` (isolated worktree)
**Status:** Test path prepared; all MySQL, MariaDB, staging, Browser E2E, and CI runtime tests remain **NOT RUN** in this update.

Mahmoud’s authorization, as relayed by the user, authorizes preparation of the isolated BE-12 staging runbook and a MySQL test path. It does not identify a staging target, maintenance window, or operator, so the flag was not changed. It also does not provide a MySQL test service/schema; the destructive migration/concurrency suites remain gated.

## 1. The 22 PKG-67 scenarios

`Pkg67MySqlInnoDbCompositionTest` inherits all 22 test methods from the existing MariaDB composition suite. This retains their assertions and real application/service/HTTP paths while emitting a distinct JUnit test class and output file for Oracle MySQL. The old MariaDB class remains an independently named opt-in run; historical MariaDB evidence must not be merged with or relabelled as MySQL evidence.

The scenarios retained are:

1. Real route submits the control-day fixture.
2. A stale transaction cannot manager-correct a marked no-work shift.
3. No-work versus manager correction of a returned report avoids deadlock and preserves history.
4. Marking a shift with an existing report is rejected while manager correction proceeds.
5. A sibling branch manager cannot correct via the early-return path.
6. No-work versus recount rejects marking a counted shift and preserves report history.
7. No-work versus recount on an untouched shift has one winner and no evidence.
8. A stale transaction cannot recount a shift after a no-work mark.
9. A committed mark is excluded from the real daily submit.
10. Submit locks first; competing mark waits and is rejected on the submitted day.
11. Mark locks first; real submit waits and excludes the marked shift.
12. Free race between mark and submit has only the two consistent outcomes.
13. A stale-snapshot submit excludes a mark committed after its snapshot.
14. Corrupt no-work evidence on a reported shift fails the submit closed.
15. Idempotent submit replay creates no second effect.
16. Mark versus accountant correction on one cashier avoids deadlock and keeps financial effects stable.
17. Mark versus head return on one cashier avoids deadlock.
18. Mark versus correction on an untouched shift has one winner and no report evidence.
19. Two identical marks commit exactly one audit fact.
20. The report-mutation fence holds when a transaction snapshot predates the committed mark.
21. Daily-close scope excludes a mark committed after its transaction snapshot.
22. HTTP authorization, idempotency, isolation, and transaction rollback remain enforced.

Source review found the MariaDB-specific pieces in this suite were its server-version assertion, schema prefix/worker guard, opt-in name, and two test names. The scenario code uses the Laravel/PDO MySQL driver, independent sessions, InnoDB transactions/locking, and a Repeatable Read stale-snapshot probe; no MariaDB-only SQL construct was identified in the 22 scenario bodies. The new MySQL harness therefore does **not** merely remove the MariaDB check: it adds a distinct MySQL-only opt-in, exact expected-version match, Oracle MySQL distribution check, test-environment/loopback/disposable-schema guards, Repeatable Read assertion, post-migration InnoDB checks, and equivalent checks in every spawned worker. Runtime equivalence remains **unproven until the authorized MySQL execution passes**.

## 2. MySQL execution preconditions and command

### Required environment

1. Read the production DB identity through an authorized read-only path; record the exact value of `VERSION()` and verify it identifies Oracle MySQL. The candidate’s historical guard accepts MySQL 8.4 or MariaDB, but it does not identify the live production patch release. No production identity was available to this task, so the exact required value is currently **BLOCKED — ENVIRONMENT**.
2. Provision a dedicated local/loopback MySQL instance at that exact release and a fresh schema named `pkg67_mysql_disposable_<unique_run_id>`. It must not be shared with MariaDB, another test job, staging, or production.
3. Use a dedicated test account scoped only to that schema, with the minimum permissions required by migrations and test fixtures. Supply its password through the approved local secret mechanism; do not put it in the command line, report, logs, or source.
4. Confirm the test operator owns this schema and explicitly approves `migrate:fresh`: the composition suite invokes that destructive command internally once per test-process run.
5. Confirm the Laravel connection and child worker resolve to the same loopback host, port, schema, and account. The test requires `APP_ENV=testing` and `@@transaction_isolation = REPEATABLE-READ` because several cases depend on stale transaction snapshots.
6. Run the read-only preflight queries in the suite and preserve server identity and `information_schema.tables` engine output. Every created base table must report `InnoDB`.
7. Provide the repository’s locked Composer dependencies and PHPUnit executable through an approved environment setup. This new worktree has no `vendor/` directory; no dependency installation or copy was performed here.

### Preparation-only PowerShell template — NOT EXECUTED

Set these values only after the preconditions above pass. The `PKG67_MYSQL_DISPOSABLE_SCHEMA` value must equal the connection’s actual database name; it is an additional guard, not proof that the schema is disposable.

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '<approved-local-port>'
$env:DB_DATABASE = 'pkg67_mysql_disposable_<unique_run_id>'
$env:DB_USERNAME = '<schema-scoped-test-user>'
$env:DB_PASSWORD = '<inject-from-approved-local-secret-store>'
$env:PKG67_MYSQL_TESTS = '1'
$env:PKG67_MYSQL_DISPOSABLE_SCHEMA = $env:DB_DATABASE
$env:PKG67_EXPECTED_MYSQL_VERSION = '<exact-production-VERSION()-output>'

# First review the preflight SQL output and verify the target remains disposable.
# The test itself calls migrate:fresh after its guards; do not run it on an
# unverified, shared, staging, or production database.
New-Item -ItemType Directory -Force artifacts\mysql-innodb | Out-Null
php vendor/bin/phpunit --configuration phpunit.xml `
  tests/Integration/Pkg67MySqlInnoDbCompositionTest.php `
  --log-junit=artifacts/mysql-innodb/pkg67-composition.junit.xml 2>&1 |
  Tee-Object -FilePath artifacts/mysql-innodb/pkg67-composition.log
$runExit = $LASTEXITCODE
Set-Content -LiteralPath artifacts/mysql-innodb/pkg67-composition.exit -Value $runExit
if ($runExit -ne 0) { throw "MySQL composition run failed with exit code $runExit" }
```

Accept a result only if the suite’s server identity/version, isolation, schema, and InnoDB guards all passed; the JUnit reports 22 executed cases with zero failures/errors/skips; and the process exit code is zero. Record the full SHA, schema, server identity, JUnit, redacted raw log, and exit-code file. Do not run both MariaDB and MySQL modes in one process or against one schema. No such evidence exists yet.

### Separate B-01 migration gate (five tests)

`Pkg67MigrationB01Test` now accepts `PKG67_MYSQL_TESTS=1` for Oracle MySQL and requires its own `pkg67_mysql_disposable_<unique_run_id>` schema, exact `PKG67_MYSQL_DISPOSABLE_SCHEMA` match, exact expected production version, loopback host, and `APP_ENV=testing`. It invokes `migrate:fresh` internally on each test setup and checks the migrated base tables use InnoDB. Give it a different dedicated schema and separate output files from the 22-case composition suite. Run only after explicit disposable-schema destruction approval. The legacy MariaDB path remains `PKG67_MARIADB_TESTS=1` and `pkg67_disposable_*`; it is not MySQL proof.

## 3. Matrix for the other 46 concurrency gates

These gates are not part of the 22-case suite and must have separate run records and schemas. Use only actual source guards; a MySQL result is valid only when the approved production-matched Oracle MySQL identity and applicable InnoDB requirements are captured.

| Suite | Count | Actual gate / schema guard | Prepared execution decision |
|---|---:|---|---|
| `AdminLegacyRefreshConcurrencyTest` | 4 | No opt-in env flag; `s12_at02_*`, loopback; source version guard accepts 8.4 or MariaDB; checks InnoDB | Run scoped class on its own pre-migrated disposable schema; keep JUnit and version/engine evidence separate |
| `AdminSessionRevocationTest` | 3 | No opt-in env flag; `s12_fs12_*`, loopback; source version guard accepts 8.4 or MariaDB; checks InnoDB | Run only the concurrency filter on its own pre-migrated disposable schema; capture identity/engines |
| `Pkg6ReviewMySqlConcurrencyTest` | 4 | `PKG6_MYSQL_TESTS=1`; `pkg6_disposable_*`; guard accepts 8.4 or MariaDB | Use MySQL gate and separate schema; do not count MariaDB execution as MySQL |
| `ShiftNotWorkedMySqlConcurrencyTest` | 3 | `PKG7_MYSQL_TESTS=1`; `pkg7_test_*`, loopback | BE-12 MySQL concurrency gate; separate from Staging rollout proof |
| `ShiftPhases234MySqlConcurrencyTest` | 10 | No opt-in env flag; `s111_phase234_audit_*` (or exact accepted phase variant), loopback | Pre-migrated isolated schema; add operator-side version/engine preflight because the class guard itself checks driver/schema/host only |
| `ShiftPhases567MySqlConcurrencyTest` | 12 | `S111_PHASE567_MYSQL_TESTS=1`; `s111_phase567_test_*`, loopback | Pre-migrated isolated schema; exact MySQL identity and InnoDB evidence required |
| `ShiftPhases8910MySqlConcurrencyTest` | 10 | `S111_PHASE8910_MYSQL_TESTS=1`; `s111_phase8910_test_*` or accepted phase variant, loopback | Pre-migrated isolated schema; exact MySQL identity and InnoDB evidence required |
| **Total** | **46** | — | No suite executed in this update |

`Pkg6ReviewMySqlConcurrencyTest` calls `migrate:fresh` during every test setup; it needs a fresh dedicated `pkg6_disposable_*` schema and explicit destruction approval. `AdminSessionRevocationTest` suppresses the `RefreshDatabase` migration hook for the three `legacy_logout_concurrency` cases, so use a pre-migrated `s12_fs12_*` schema. The other suites use pre-migrated schemas in their current source. Never run a migration against an uncertain target.

Preparation-only invocation template; make each schema unique and run each suite separately. `Invoke-PhpUnitEvidence` writes JUnit, raw log and process exit code under `artifacts/mysql-innodb/`. Confirm Oracle MySQL identity and table engines before every suite. The code below has not been executed:

```powershell
function Invoke-PhpUnitEvidence([string]$Label, [string[]]$PhpUnitArgs) {
  $junit = "artifacts/mysql-innodb/$Label.junit.xml"
  $log = "artifacts/mysql-innodb/$Label.log"
  $exitFile = "artifacts/mysql-innodb/$Label.exit"
  & php vendor/bin/phpunit --configuration phpunit.xml @PhpUnitArgs "--log-junit=$junit" 2>&1 |
    Tee-Object -FilePath $log
  $code = $LASTEXITCODE
  Set-Content -LiteralPath $exitFile -Value $code
  if ($code -ne 0) { throw "$Label failed with exit code $code" }
}

$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '<approved-local-port>'
$env:DB_USERNAME = '<schema-scoped-test-user>'
$env:DB_PASSWORD = '<inject-from-approved-local-secret-store>'

$env:DB_DATABASE = 's12_at02_<unique_run_id>'
Invoke-PhpUnitEvidence 'admin-legacy-refresh' @('tests/Feature/AdminLegacyRefreshConcurrencyTest.php')

$env:DB_DATABASE = 's12_fs12_<unique_run_id>'
Invoke-PhpUnitEvidence 'admin-session-revocation' @('tests/Feature/AdminSessionRevocationTest.php', '--filter=legacy_logout_concurrency')

$env:PKG6_MYSQL_TESTS = '1'
$env:DB_DATABASE = 'pkg6_disposable_<unique_run_id>' # fresh disposable schema: this suite runs migrate:fresh
Invoke-PhpUnitEvidence 'pkg6-review' @('tests/Feature/Pkg6ReviewMySqlConcurrencyTest.php')

$env:PKG7_MYSQL_TESTS = '1'
$env:DB_DATABASE = 'pkg7_test_<unique_run_id>'
Invoke-PhpUnitEvidence 'be12-shift-concurrency' @('tests/Feature/ShiftNotWorkedMySqlConcurrencyTest.php')

$env:DB_DATABASE = 's111_phase234_audit_<unique_run_id>'
Invoke-PhpUnitEvidence 'shift-phases234' @('tests/Feature/ShiftPhases234MySqlConcurrencyTest.php')

$env:S111_PHASE567_MYSQL_TESTS = '1'
$env:DB_DATABASE = 's111_phase567_test_<unique_run_id>'
Invoke-PhpUnitEvidence 'shift-phases567' @('tests/Feature/ShiftPhases567MySqlConcurrencyTest.php')

$env:S111_PHASE8910_MYSQL_TESTS = '1'
$env:DB_DATABASE = 's111_phase8910_test_<unique_run_id>'
Invoke-PhpUnitEvidence 'shift-phases8910' @('tests/Feature/ShiftPhases8910MySqlConcurrencyTest.php')
```

For each invocation use its own schema, JUnit, redacted raw log, exit-code file, full SHA, schema name, server identity and engine query output. Pre-migrate only newly provisioned schemas after a human verifies the actual connection. Do not set opt-in flags together and run mixed suites under one database configuration.

## 4. Five migration gates and one SQLite/MySQL behavior case

| Suite | Count | Suitability and planned evidence |
|---|---:|---|
| `Pkg67MigrationB01Test` | 5 | MySQL path prepared above; destructive internal `migrate:fresh`; strict isolated schema and engine guard. MariaDB history stays separately identified. |
| `BranchFixedAssetsUploadPersistenceTest` | 1 | SQLite skips because its driver does not enforce the production column length. The test currently has no MySQL opt-in/disposable guard; do not run it on MySQL until a separate guarded MySQL fixture path is reviewed. The intended MySQL assertion is the real column-length rejection and unchanged persisted data, but that behavior is not yet executed or claimed. |

Do not force all 74 skipped tests onto MySQL. The 22 composition cases and 46 other concurrency gates are separate suites; five migration cases and the driver-behavior test each have distinct execution suitability and guards.

## 5. BE-12 staging runbook — prepared, activation blocked

Mahmoud authorized an isolated staging experiment, but there is not yet an identified staging environment, execution window, or operator. **Do not set the Staging flag until every required field below is completed and the operator confirms the target. Production remains OFF.**

### Required change record before scheduling

| Field | Required value |
|---|---|
| Staging environment / project / region | `<exact target identity>` |
| Staging API base URL and tenant/branch fixture | `<approved isolated target>` |
| Deployed backend SHA | Must equal `54cb948c6519734faae1171a13e4ead6ddeb4df8`; otherwise stop/re-pin and re-review |
| Dashboard SHA, if browser flows are included | `f15702cb619748860f3755ddbb1d2ad13bc69bba` |
| Change/authorization reference | `<Mahmoud authorization record or ticket ID>` |
| Window start/end + timezone | `<approved date/time range>` |
| Executing operator and rollback authority | `<named operator/on-call owner>` |
| Staging DB identity and disposable fixture IDs | `<verified non-production database and three disposable shifts>` |
| Credential method | `<approved secret manager/injection; no secret in transcript/log>` |
| Rollback trigger and communication channel | `<named stop conditions and on-call route>` |

### OFF → ON → OFF procedure (do not execute yet)

1. **Preflight:** verify target identity, deployed SHA, change window, operator and rollback owner. Confirm target is Staging and database is non-production. Capture effective `FEATURE_SHIFT_DID_NOT_WORK=false`, config-cache/restart state, DB identity and before-state for three disposable fixture shifts. Stop if any field is missing or flag is already ON unexpectedly.
2. **OFF proof:** with the flag OFF, make one authenticated `POST /api/v1/branch-manager/shifts/{off_fixture_id}/mark-not-worked` request using a unique `Idempotency-Key` and valid reason. Capture HTTP 404 and code `SHIFT_NOT_WORKED_FEATURE_DISABLED`; verify shift status, history and idempotency tables are unchanged.
3. **ON proof:** only within the authorized window, change this Staging target to ON using the approved config mechanism and its documented cache/restart step. Verify effective configuration before the request. POST to a separate disposable shift fixture with a fresh idempotency key; capture HTTP 200, response, audit/history row and expected status change.
4. **Rollback immediately:** set Staging back to OFF, perform required cache/restart action, and prove the effective value is false. POST to a third untouched disposable shift with a fresh key; capture HTTP 404 and the stable disabled code, then prove no mutation on that fixture.
5. **Close:** remove only the disposable test fixtures by the approved cleanup path. Capture state before/after, correlation IDs, redacted HTTP records, effective flag at each phase, config/cache action, timestamps, operator and rollback confirmation. Stop and roll back immediately on unexpected status/body, DB mutation, latency/lock error, or uncertainty about the target.

This is a preparation-only sequence. No Staging flag was changed, and no Staging HTTP call was made. `.env.example` or PHPUnit configuration is not runtime evidence. The deployable release default and Production must remain OFF.

## 6. Browser E2E and CI/OOM evidence matrix

| Gate | Current evidence | State |
|---|---|---|
| 74 SQLite skip taxonomy | Corrected totals 46 + 22 + 5 + 1; classes and source guards reviewed | **PASS / EXECUTED (classification only)** |
| Sharding and fail-closed | Previously executed at the candidate; user accepted those results | **PRIOR ACCEPTED EVIDENCE; NOT RERUN** |
| Full SQLite candidate | Earlier evidence: 2,463 tests, 13,941 assertions, 74 skips, no failures/errors | **PRIOR EVIDENCE; NOT RERUN** |
| MySQL 22-case composition | Separate Integration class, exact scenario-count assertion, ephemeral Oracle MySQL service and run-scoped schema prepared | **PREPARED; NOT RUN — branch push/Actions execution pending separate authorization** |
| Other 46 concurrency cases | Seven existing suites, individual run-scoped schemas, target guard and exact JUnit count assertions wired into the dedicated runner | **PREPARED; NOT RUN — branch push/Actions execution pending separate authorization** |
| Five B-01 migrations | MySQL strict identity guard, its own schema and five-case assertion prepared; internal `migrate:fresh` is limited to the new ephemeral service | **PREPARED; NOT RUN — branch push/Actions execution pending separate authorization** |
| SQLite/MySQL length behavior | SQLite skip cause classified; no safe guarded MySQL case yet | **BLOCKED — TEST SAFETY / ENVIRONMENT; NOT RUN** |
| BE-12 staging OFF → ON → OFF | Runbook prepared; target/window/operator not identified; no flag or request used | **BLOCKED — AUTHORIZATION / ENVIRONMENT; NOT RUN** |
| Authenticated Browser E2E | SHA pair retained; Playwright binary, services, v3.5 fixture and approved account still required. Do not install/start/access secrets without authorization. | **BLOCKED — ENVIRONMENT / AUTHORIZATION; NOT RUN** |
| CI shard/OOM at candidate SHA | Prior sharding/fail-closed evidence accepted; no candidate Actions run record proves OOM. Dedicated MySQL workflow is prepared, not run. | **PRIOR ACCEPTED EVIDENCE + PREPARED; runtime BLOCKED pending authorized branch push** |
| Production flag state | No production access/change requested or made | **Production remains OFF** |

## 7. Change and verification record

Expected changed files on this branch:

- `tests/Feature/Pkg67MariaDbCompositionTest.php` — shared setup helper and stricter child-worker MySQL identity/schema/engine/isolation checks; original scenario method names remain stable for the existing PHPUnit shard manifest, and MariaDB stays separately gated.
- `tests/Integration/Pkg67MySqlInnoDbCompositionTest.php` — new explicit-only test class inheriting the same 22 scenarios.
- `tests/Feature/Pkg67MigrationB01Test.php` — isolated Oracle MySQL execution path with exact identity/version/schema and InnoDB guards; MariaDB gate remains separate.
- `.github/workflows/assab-closure-track-b-mysql-proof.yml` — branch-only push workflow using the hosted ephemeral Oracle MySQL 8.4 service; no secrets, staging, production or deploy steps.
- `scripts/harness/run_closure_mysql84.sh` — creates only run-scoped schemas and a per-run schema-limited account, runs the 22 + 46 + 5 cases separately, and captures runtime identity, engines, JUnit, logs and exit codes.
- This report and `BE12_STAGING_RUNBOOK.md` — no runtime settings or secrets.

No test suite, migration, Browser E2E, database service, staging endpoint, or CI workflow was run. The dedicated workflow and helper are now prepared. The project’s TDD red/green execution was intentionally not performed because destructive and concurrency execution is gated on a hosted, ephemeral MySQL run. `php -l` passed for the three modified PHP files. Discovery used same-SHA dependencies for non-executing PHPUnit discovery (see §8), and all 22 methods were listed. No Composer install/copy was attempted. `git diff --check` passed. Commit, push, PR, merge, and deployment: **none**.

## 8. GitHub Actions feasibility follow-up (read-only)

### Existing runner assessment

- `.github/workflows/assab-backend-ci.yml` remains the historical S1-12 acceptance workflow: its branch/path filters are restricted, its scope assertion permits only that workflow file, and its DB job uses MariaDB 11.4. It is not a Closure Sprint runner and was not modified.
- `.github/workflows/assab-test-harness.yml` already defines a GitHub-hosted `ubuntu-24.04` runner with an ephemeral `mysql:8.4` service, opt-in MySQL job, locked Composer install, no repository secret dependency, loopback/database target guards, JUnit/log/exit artifacts, and 46 relevant MySQL concurrency cases through `scripts/harness/run_mysql84.sh`.
- That MySQL helper does not run the new 22-case `Pkg67MySqlInnoDbCompositionTest` or the five B-01 MySQL migration cases; its schema allowlist lacks the `pkg67_mysql_disposable_*` prefix. It verifies generic MySQL 8.4.x rather than an exact production patch release, and its evidence summarizer/coverage registry is BRC34-era. Its green output alone would not be complete v3.5 Closure evidence.
- The existing harness workflow is not present on `origin/main`, and its current push/PR branch filters do not match `task/closure-track-b-mysql-proof`. The current candidate is descended from its configured source-guard anchor and a local comparison found no denied source-scope path; this is not a hosted workflow run. A targeted branch-push workflow can run without modifying the S1-12 workflow, but the workflow/test changes must first be committed and pushed with separate authorization. A `workflow_dispatch` trigger is not currently available from the default branch until that workflow exists there.

### Safe PHPUnit discovery

PHPUnit 12.4 discovery was executed, not the tests:

```text
php ..\worktree_wave2_candidate\vendor\bin\phpunit --configuration phpunit.xml --bootstrap <temporary bootstrap using same-SHA vendor autoload and current changed parent class> --list-tests tests/Integration/Pkg67MySqlInnoDbCompositionTest.php
```

Result: exit code 0 and exactly **22** `Tests\Integration\Pkg67MySqlInnoDbCompositionTest::*` scenarios listed. The temporary bootstrap and dependencies came from the existing same-SHA candidate worktree; nothing was installed or copied. The Integration directory is intentionally outside the default PHPUnit suites, so future CI must pass the file path explicitly. `--list-tests` does not run `setUp`, migrations, or scenario bodies. Source inspection confirms the subclass calls `setUpCompositionDatabase('mysql')`; runtime guard behavior remains unexecuted.

### Feasibility verdict and actual blockers

**Yes, the existing GitHub-hosted Actions capability can host the proof; no new runner service or repository secret is needed. The existing workflows cannot produce the complete proof unchanged.** Minimum design: a dedicated branch-scoped MySQL proof workflow (do not edit `assab-backend-ci.yml`) using an ephemeral, immutable MySQL image matching the verified production version; exact server/version/distribution and InnoDB guards; run-scoped disposable schemas and separated JUnit/log/exit artifacts; explicit commands for the 22-case Integration file and B-01 migration suite plus the existing 46-case matrix; and no staging endpoint or deployment step. The BRC34 summary must not be labeled v3.5 acceptance.

Actual blockers: (1) the exact production `VERSION()`/distribution identity is not available to pin/verify the service image; (2) this branch’s changes are uncommitted and unpushed, so Actions cannot see them, and the user has not authorized commit/push; (3) there is no branch-matching dispatch/push event in the existing workflow set; and (4) no hosted run artifact exists. No MySQL test or CI workflow was run in this follow-up. BE-12 remains preparation-only pending staging identity, approved window, executor and rollback owner; its fixtures must use eligible shifts dated today or earlier, not future-date cases governed by P-01. Browser E2E remains blocked as previously recorded.

## 9. Closure Track B workflow preparation (not executed)

- Added `.github/workflows/assab-closure-track-b-mysql-proof.yml`, triggered only by pushes to `task/closure-track-b-mysql-proof` and relevant paths. It does not modify the historical S1-12 workflow or its source guards. It has no `workflow_dispatch`, secrets, staging/production endpoints, flag steps, or deployment steps.
- The job uses GitHub-hosted Ubuntu and the existing harness pattern of an ephemeral Oracle `mysql:8.4` service. Because exact production server identity is still unknown, every run is explicitly **preliminary 8.4 evidence**, never production-matched evidence.
- `scripts/harness/run_closure_mysql84.sh` reuses the existing `assert_mysql84_target.php` for the 46 concurrency cases, but does not call `run_mysql84.sh` wholesale: that helper also runs BRC34-specific harness checks and emits a BRC34-era summary. The new runner provisions a random CI-only account with grants limited to newly created per-suite schemas, and separately records server identity, per-schema InnoDB engines, JUnit, logs, SHA and exit codes.
- Suites are invoked explicitly: 22 PKG-67 cases, the seven concurrency files (10 + 12 + 10 + 3 + 4 + 4 + 3 = 46), and the five B-01 migration cases. Each suite has its own schema and expected JUnit count. The PKG-67 and B-01 test guards continue to control their internal `migrate:fresh`; the legacy MariaDB mode remains separately gated and is not included in the MySQL workflow.
- The workflow is not available to GitHub Actions until the branch changes are committed/pushed. No such action was taken here. No service, database, migration, test, Browser E2E, staging request, flag update, or workflow run was executed.
- Static review: PHP lint passed for all three changed PHP files; the run-scoped schema regex was exercised against allowed and rejected sample names; changed paths passed the existing deny-pattern review; old workflow/source-guard files are unchanged; and `git diff --check` passed. A full YAML parser and Bash syntax checker were unavailable in this environment (no installed YAML parser; Windows Bash startup was blocked by the sandbox), so those two checks are **not claimed as executed**. No dependency was installed to obtain them.
- **Ready for review, not ready as runtime evidence.** Required next authorization is the user’s separate approval to commit/push this branch so the branch-scoped Actions run can execute. Exact production MySQL version remains an owner/environment fact required before any production-matched classification.
