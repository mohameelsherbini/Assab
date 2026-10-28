#!/usr/bin/env bash
set -uo pipefail

# GitHub-hosted, ephemeral MySQL 8.4 runner for Closure Track B.
# Deliberately refuses local, remote, staging, and production database targets.
repo="$(git rev-parse --show-toplevel)"
cd "$repo"
artifact_dir="${1:-artifacts/closure-track-b-mysql84}"
mkdir -p "$artifact_dir"

fail() {
  echo "CLOSURE_MYSQL_PRECHECK=FAIL $*" | tee -a "$artifact_dir/run-status.txt" >&2
  exit 2
}

[[ "${GITHUB_ACTIONS:-}" == 'true' && "${RUNNER_ENVIRONMENT:-}" == 'github-hosted' ]] \
  || fail 'This runner is restricted to an ephemeral GitHub-hosted Actions service.'
[[ "${DB_HOST:-}" == '127.0.0.1' && "${DB_PORT:-}" == '3306' ]] \
  || fail 'Database host and port must target the local MySQL service.'
[[ "${APP_ENV:-}" == 'testing' && "${DB_CONNECTION:-}" == 'mysql' ]] \
  || fail 'APP_ENV=testing and DB_CONNECTION=mysql are required.'
[[ -z "${DB_URL:-}" && -z "${DATABASE_URL:-}" && -z "${DB_SOCKET:-}" ]] \
  || fail 'URL/socket database overrides must be empty.'
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ && "${GITHUB_RUN_ATTEMPT:-}" =~ ^[0-9]+$ ]] \
  || fail 'Expected numeric GitHub run id and attempt.'

run_key="${GITHUB_RUN_ID}_${GITHUB_RUN_ATTEMPT}"
test_user="assab_ci_${run_key}"
test_password="$(openssl rand -hex 24)"
echo "::add-mask::${test_password}"
export DB_USERNAME="$test_user" DB_PASSWORD="$test_password"
export MYSQL_ROOT_USERNAME=root MYSQL_ROOT_PASSWORD=''
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array
export APP_DEBUG=false APP_KEY='base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=' APP_URL='http://127.0.0.1'
export DB_URL='' DATABASE_URL='' DB_SOCKET=''

printf 'tested_sha=%s\nrun_id=%s\nrun_attempt=%s\nclassification=PRELIMINARY_ORACLE_MYSQL_8_4_NOT_PRODUCTION_MATCHED\n' \
  "${GITHUB_SHA:-$(git rev-parse HEAD)}" "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT" \
  > "$artifact_dir/run-metadata.txt"

# Verify service identity before creating schemas. This records the ephemeral
# service's actual patch version; it does not claim production version parity.
if ! php -r '
$pdo = new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("MYSQL_ROOT_USERNAME"), getenv("MYSQL_ROOT_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$row = $pdo->query("SELECT VERSION() AS version, @@version_comment AS version_comment, @@default_storage_engine AS engine, @@transaction_isolation AS isolation")->fetch(PDO::FETCH_ASSOC);
echo "mysql_version={$row["version"]}\nmysql_version_comment={$row["version_comment"]}\ndefault_engine={$row["engine"]}\ntransaction_isolation={$row["isolation"]}\n";
if (!preg_match("/\\A8\\.4\\.[0-9]+(?:[-+].*)?\\z/", $row["version"])) { throw new RuntimeException("Expected Oracle MySQL 8.4.x"); }
if (stripos($row["version_comment"], "mysql") === false || stripos($row["version_comment"], "mariadb") !== false) { throw new RuntimeException("Server is not Oracle MySQL"); }
if (strcasecmp($row["engine"], "InnoDB") !== 0) { throw new RuntimeException("Default engine is not InnoDB"); }
if (strtoupper(str_replace(["_", " "], "-", $row["isolation"])) !== "REPEATABLE-READ") { throw new RuntimeException("Expected REPEATABLE READ"); }
' > "$artifact_dir/mysql-runtime.txt" 2>&1; then
  cat "$artifact_dir/mysql-runtime.txt" >&2
  fail 'Oracle MySQL 8.4/InnoDB service preflight failed.'
fi

mysql_version="$(sed -n 's/^mysql_version=//p' "$artifact_dir/mysql-runtime.txt" | head -n 1)"
[[ -n "$mysql_version" ]] || fail 'Could not capture MySQL VERSION().'
export PKG67_EXPECTED_MYSQL_VERSION="$mysql_version"
printf 'PKG67_EXPECTED_MYSQL_VERSION=%s\n' "$mysql_version" >> "${GITHUB_ENV:?GITHUB_ENV is required}"
echo 'CLOSURE_MYSQL_PRECHECK=PASS' | tee -a "$artifact_dir/run-status.txt"
docker image inspect mysql:8.4 --format '{{json .RepoDigests}}' > "$artifact_dir/mysql-image-digests.json" 2>&1 \
  || printf 'image_digest=NOT_AVAILABLE\n' > "$artifact_dir/mysql-image-digests.json"

# Create one fresh CI-only account. It has no global privileges; each schema is
# granted separately after the exact run-scoped name and nonexistence are checked.
php -r '
$user = getenv("DB_USERNAME");
$password = getenv("DB_PASSWORD");
if (!preg_match("/\\Aassab_ci_[0-9]+_[0-9]+\\z/", $user) || !preg_match("/\\A[0-9a-f]{48}\\z/", $password)) { throw new RuntimeException("Invalid ephemeral test account identity"); }
$pdo = new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("MYSQL_ROOT_USERNAME"), getenv("MYSQL_ROOT_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE USER ".$pdo->quote($user)."@\x27%\x27 IDENTIFIED BY ".$pdo->quote($password));
' || fail 'Could not create the run-scoped, least-privilege test account.'

# Create only run-scoped schemas and grant a random test account access only to
# each schema. The privileged account exists solely inside this disposable job.
create_schema() {
  local database="$1"
  case "$database" in
    "s111_phase234_audit_${run_key}"|"s111_phase567_test_${run_key}"|"s111_phase8910_test_${run_key}"|\
    "pkg7_test_${run_key}"|"pkg6_disposable_${run_key}"|"s12_at02_${run_key}"|"s12_fs12_${run_key}"|\
    "pkg67_mysql_disposable_composition_${run_key}"|"pkg67_mysql_disposable_b01_${run_key}") ;;
    *) echo "Refusing schema outside this run's allowlist: $database" >&2; return 1 ;;
  esac

  DB_DATABASE="$database" php -r '
$db = getenv("DB_DATABASE");
$allowed = "/\\A(?:(?:s111_phase234_audit|s111_phase567_test|s111_phase8910_test|pkg7_test|pkg6_disposable|s12_at02|s12_fs12)_[0-9]+_[0-9]+|pkg67_mysql_disposable_(?:composition|b01)_[0-9]+_[0-9]+)\\z/";
if (!preg_match($allowed, $db)) { throw new RuntimeException("Rejected disposable schema name"); }
$pdo = new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("MYSQL_ROOT_USERNAME"), getenv("MYSQL_ROOT_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
$exists->execute([$db]);
if ((int) $exists->fetchColumn() !== 0) { throw new RuntimeException("Run-scoped schema already exists; refusing reuse"); }
$pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("GRANT ALL PRIVILEGES ON `{$db}`.* TO ".$pdo->quote(getenv("DB_USERNAME"))."@\x27%\x27");
' || return 1
  printf '%s\n' "$database" >> "$artifact_dir/disposable-schemas.txt"
}

record_exit() {
  printf '%s\n' "$2" > "$artifact_dir/$1.exit"
  printf '%s\t%s\n' "$1" "$2" >> "$artifact_dir/suite-exit-codes.tsv"
}

overall=0

run_legacy_suite() {
  local label="$1" database="$2" expected_count="$3" test_file="$4"
  shift 4
  mkdir -p "$artifact_dir/$label"
  if ! create_schema "$database"; then
    record_exit "$label.schema" 2
    record_exit "$label.suite" NOT_RUN
    overall=1
    return
  fi
  export DB_DATABASE="$database"

  php scripts/harness/assert_mysql84_target.php > "$artifact_dir/$label/target-guard.log" 2>&1
  local rc=$?
  record_exit "$label.target-guard" "$rc"
  if [[ "$rc" -ne 0 ]]; then
    record_exit "$label.suite" NOT_RUN
    overall=1
    return
  fi

  php artisan migrate:fresh --force 2>&1 | tee "$artifact_dir/$label/migrate.log"
  rc=${PIPESTATUS[0]}
  record_exit "$label.migrate" "$rc"
  if [[ "$rc" -ne 0 ]]; then
    record_exit "$label.suite" NOT_RUN
    overall=1
    return
  fi

  capture_innodb "$label"
  rc=$?
  record_exit "$label.innodb" "$rc"
  if [[ "$rc" -ne 0 ]]; then
    record_exit "$label.suite" NOT_RUN
    overall=1
    return
  fi
  run_phpunit "$label" "$expected_count" "$test_file" "$@"
}

assert_pkg67_target() {
  php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
$database = (string) getenv('DB_DATABASE');
$expected = (string) getenv('PKG67_EXPECTED_MYSQL_VERSION');
$checks = [
    getenv('APP_ENV') === 'testing',
    getenv('PKG67_MYSQL_TESTS') === '1',
    getenv('PKG67_MYSQL_DISPOSABLE_SCHEMA') === $database,
    (bool) preg_match('/\Apkg67_mysql_disposable_(?:composition|b01)_[0-9]+_[0-9]+\z/', $database),
    $db->getDriverName() === 'mysql',
    $db->getDatabaseName() === $database,
    in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true),
    $expected !== '',
];
$server = $db->selectOne('SELECT VERSION() AS version, @@version_comment AS version_comment');
$checks[] = $server->version === $expected;
$checks[] = stripos($server->version_comment, 'mysql') !== false && stripos($server->version_comment, 'mariadb') === false;
if (in_array(false, $checks, true)) { fwrite(STDERR, "PKG67 target guard failed\n"); exit(1); }
echo "PKG67 target guard passed; schema={$database}; mysql_version={$server->version}; distribution=Oracle MySQL\n";
PHP
}

capture_innodb() {
  local label="$1"
  php <<'PHP' > "$artifact_dir/$label/innodb-tables.txt" 2>&1
<?php
$pdo = new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
if ($tables === []) { throw new RuntimeException('No base tables found after migration'); }
foreach ($tables as $table => $engine) { echo $table, "\t", $engine, "\n"; }
if (count(array_filter($tables, fn ($engine) => $engine === 'InnoDB')) !== count($tables)) { throw new RuntimeException('At least one table is not InnoDB'); }
PHP
  return $?
}

run_phpunit() {
  local label="$1" expected_count="$2" test_file="$3"
  shift 3
  set +e
  php vendor/bin/phpunit "$test_file" "$@" --colors=never --log-junit "$artifact_dir/$label/$label.junit.xml" 2>&1 \
    | tee "$artifact_dir/$label/$label.log"
  local rc=${PIPESTATUS[0]}
  set -u
  record_exit "$label.suite" "$rc"
  if [[ "$rc" -eq 0 ]]; then
    python3 - "$artifact_dir/$label/$label.junit.xml" "$expected_count" <<'PY'
import sys
import xml.etree.ElementTree as ET

root = ET.parse(sys.argv[1]).getroot()
cases = root.findall('.//testcase')
skipped = sum(len(case.findall('skipped')) for case in cases)
failures = sum(len(case.findall('failure')) for case in cases)
errors = sum(len(case.findall('error')) for case in cases)
expected = int(sys.argv[2])
print(f'junit_cases={len(cases)} expected={expected} skipped={skipped} failures={failures} errors={errors}')
if len(cases) != expected or skipped or failures or errors:
    raise SystemExit(1)
PY
    local junit_rc=$?
    printf '%s\n' "$junit_rc" > "$artifact_dir/$label.junit-validation.exit"
    [[ "$junit_rc" -eq 0 ]] || overall=1
  else
    record_exit "$label.junit-validation" NOT_RUN
  fi
  [[ "$rc" -eq 0 ]] || overall=1
}

run_pkg67_suite() {
  local label="$1" database="$2" expected_count="$3" test_file="$4"
  mkdir -p "$artifact_dir/$label"
  if ! create_schema "$database"; then
    record_exit "$label.schema" 2
    record_exit "$label.suite" NOT_RUN
    overall=1
    return
  fi
  export DB_DATABASE="$database" PKG67_MYSQL_TESTS=1 PKG67_MYSQL_DISPOSABLE_SCHEMA="$database"
  unset PKG67_MARIADB_TESTS
  assert_pkg67_target > "$artifact_dir/$label/target-guard.log" 2>&1
  local rc=$?
  record_exit "$label.target-guard" "$rc"
  if [[ "$rc" -ne 0 ]]; then
    cat "$artifact_dir/$label/target-guard.log" >&2
    record_exit "$label.suite" NOT_RUN
    overall=1
    return
  fi
  run_phpunit "$label" "$expected_count" "$test_file"
  capture_innodb "$label"
  rc=$?
  record_exit "$label.innodb" "$rc"
  [[ "$rc" -eq 0 ]] || overall=1
}

# The following seven invocations are the previously classified 46 concurrency cases.
run_legacy_suite shift-234 "s111_phase234_audit_${run_key}" 10 tests/Feature/ShiftPhases234MySqlConcurrencyTest.php
export S111_PHASE567_MYSQL_TESTS=1
run_legacy_suite shift-567 "s111_phase567_test_${run_key}" 12 tests/Feature/ShiftPhases567MySqlConcurrencyTest.php
unset S111_PHASE567_MYSQL_TESTS
export S111_PHASE8910_MYSQL_TESTS=1
run_legacy_suite shift-8910 "s111_phase8910_test_${run_key}" 10 tests/Feature/ShiftPhases8910MySqlConcurrencyTest.php
unset S111_PHASE8910_MYSQL_TESTS
export PKG7_MYSQL_TESTS=1
run_legacy_suite be12-shift "pkg7_test_${run_key}" 3 tests/Feature/ShiftNotWorkedMySqlConcurrencyTest.php
unset PKG7_MYSQL_TESTS
export PKG6_MYSQL_TESTS=1
run_legacy_suite pkg6-review "pkg6_disposable_${run_key}" 4 tests/Feature/Pkg6ReviewMySqlConcurrencyTest.php
unset PKG6_MYSQL_TESTS
run_legacy_suite admin-refresh "s12_at02_${run_key}" 4 tests/Feature/AdminLegacyRefreshConcurrencyTest.php
run_legacy_suite admin-logout "s12_fs12_${run_key}" 3 tests/Feature/AdminSessionRevocationTest.php --filter=legacy_logout_concurrency

# PKG-67 22 scenarios and the five B-01 migrations have separate schemas.
# Each test class checks its disposal guard before its own migrate:fresh.
run_pkg67_suite pkg67-composition "pkg67_mysql_disposable_composition_${run_key}" 22 tests/Integration/Pkg67MySqlInnoDbCompositionTest.php
run_pkg67_suite pkg67-b01 "pkg67_mysql_disposable_b01_${run_key}" 5 tests/Feature/Pkg67MigrationB01Test.php

printf 'overall_exit=%s\n' "$overall" | tee -a "$artifact_dir/run-status.txt"
exit "$overall"
