#!/usr/bin/env bash
# ==============================================================================
# ASSAB ERP — FUND-04 & PKG-67 Concurrency Proof Harness (Oracle MySQL 8.4 / InnoDB)
#
# Executes targeted concurrency tests against isolated, disposable Oracle MySQL 8.4
# schemas within an ephemeral GitHub Actions CI runner.
#
# Safety & Governance Guards:
# 1. Zero hardcoded credentials (strictly reads from CI environment vault).
# 2. Strict physical environment barriers (GitHub Actions runner only).
# 3. Fail-closed rejection of any staging, production, or shared environments.
# 4. Fail-closed Allowlist: Asserts full git diff vs dabb1722 is strictly the 4 CI files.
# 5. Programmatic verification of Oracle MySQL 8.4.x (rejects MariaDB and other versions).
# 6. Programmatic verification of InnoDB (default engine + 100% of tables) and REPEATABLE-READ.
# 7. Two separate disposable schemas (assab_proof_fund04_* and assab_proof_pkg67_*) to prevent state pollution.
# 8. Guaranteed cleanup trap on EXIT, INT, TERM dropping only the proof schemas.
# 9. Strict Zero-Skip policy: all test methods must pass, 0 skips, 0 failures.
# ==============================================================================
set -euo pipefail

# 1. Physical Environment Barriers
if [[ "${GITHUB_ACTIONS:-false}" != "true" && "${CI:-false}" != "true" && "${CONCURRENCY_LOCAL_SANDBOX:-false}" != "true" ]]; then
    echo "::error title=ENVIRONMENT BARRIER::Execution blocked. Must run within GitHub Actions CI or explicit CONCURRENCY_LOCAL_SANDBOX=true." >&2
    exit 1
fi

# Fail-closed check: Reject any environment contaminated with external production/staging vars
if env | grep -Eiq 'coolify|staging|production|hostinger|railway|live|prod_db'; then
    echo "::error title=SAFETY GUARD BLOCKED::Forbidden staging or production environment variable detected in runner." >&2
    exit 1
fi

# 2. Fail-Closed Candidate Allowlist Verification against Frozen Base dabb1722
FROZEN_BASE_SHA="dabb172236f89f806e95273d2f0ce33abeec6c91"
echo "==> [CI Safety Guard] Verifying Full Git Diff Candidate Allowlist against base ${FROZEN_BASE_SHA}..."

DIFF_FILES=$(git diff --name-only "${FROZEN_BASE_SHA}" HEAD | sort -u || true)

echo "    Files changed against frozen base ${FROZEN_BASE_SHA}:"
echo "${DIFF_FILES}"

AUTHORIZED_ALLOWLIST=(
    ".github/workflows/assab-wave12-concurrency-ci.yml"
    "scripts/ci/run_mysql_fund04_pkg67_proof.sh"
    "tests/Feature/Concurrency/Fund04OracleConcurrencyProofTest.php"
    "tests/Feature/Concurrency/Pkg67OracleConcurrencyProofTest.php"
)

while IFS= read -r changed_file; do
    [[ -z "${changed_file}" ]] && continue
    is_allowed=false
    for allowed in "${AUTHORIZED_ALLOWLIST[@]}"; do
        if [[ "${changed_file}" == "${allowed}" ]]; then
            is_allowed=true
            break
        fi
    done
    if [[ "${is_allowed}" != "true" ]]; then
        echo "::error title=ALLOWLIST VIOLATION::Unauthorized file modified or added: '${changed_file}'! Must only contain the 4 approved CI files." >&2
        exit 1
    fi
done <<< "${DIFF_FILES}"

APP_DIFF=$(git diff --name-only "${FROZEN_BASE_SHA}" HEAD -- app/ Modules/ config/ routes/ database/ || true)
if [[ -n "${APP_DIFF}" ]]; then
    echo "::error title=APPLICATION CODE TAINTED::Found modifications in protected application directories: ${APP_DIFF}" >&2
    exit 1
fi
echo "    Verified: Candidate diff is strictly confined to the 4 authorized CI files."

# 3. Verify required environment variables from secure CI environment
: "${MYSQL_TEST_HOST:?MYSQL_TEST_HOST must be provided by secure CI environment}"
: "${MYSQL_TEST_PORT:=3306}"
: "${MYSQL_TEST_USER:?MYSQL_TEST_USER must be provided by secure CI environment}"
: "${MYSQL_TEST_PASSWORD:?MYSQL_TEST_PASSWORD must be provided by secure CI environment}"

# Network Barrier: Ensure host is strictly a local container or loopback address
if [[ "${MYSQL_TEST_HOST}" != "127.0.0.1" && "${MYSQL_TEST_HOST}" != "localhost" && "${MYSQL_TEST_HOST}" != "mysql" ]]; then
    echo "::error title=NETWORK BARRIER::Target host '${MYSQL_TEST_HOST}' is not a permitted loopback or local container address." >&2
    exit 1
fi

for blocked in "prod" "production" "live" "staging" "shared"; do
    if [[ "${MYSQL_TEST_HOST,,}" == *"${blocked}"* ]]; then
        echo "::error title=SAFETY GUARD BLOCKED::Target host '${MYSQL_TEST_HOST}' contains blocked keyword '${blocked}'." >&2
        exit 1
    fi
done

# 4. Generate unique disposable schema names (isolated per suite)
RUN_ID="$(date +%s)_${RANDOM}"
DB_FUND04="assab_proof_fund04_${RUN_ID}"
DB_PKG67="assab_proof_pkg67_${RUN_ID}"

for db in "${DB_FUND04}" "${DB_PKG67}"; do
    if [[ ! "${db}" =~ ^assab_proof_[a-z0-9]+_[0-9]+_[0-9]+$ ]]; then
        echo "::error title=SAFETY GUARD BLOCKED::Target database name '${db}' violates safety prefix guard." >&2
        exit 1
    fi
    for protected_name in "assab" "production" "staging" "main" "master" "app" "forge" "default" "mysql" "sys"; do
        if [[ "${db,,}" == "${protected_name}" ]]; then
            echo "::error title=SAFETY GUARD BLOCKED::Attempted to target protected database name '${protected_name}'." >&2
            exit 1
        fi
    done
done

echo "==> [CI Harness] Host: ${MYSQL_TEST_HOST}:${MYSQL_TEST_PORT}"
echo "==> [CI Harness] Test User: ${MYSQL_TEST_USER}"
echo "==> [CI Harness] Schema FUND-04: ${DB_FUND04}"
echo "==> [CI Harness] Schema PKG-67:  ${DB_PKG67}"

# 5. Robust cleanup trap (drops ONLY the task's disposable schemas)
cleanup() {
    local exit_code=$?
    echo "==> [CI Harness] Executing cleanup trap..."
    MYSQL_PWD="${MYSQL_TEST_PASSWORD}" mysql -h "${MYSQL_TEST_HOST}" -P "${MYSQL_TEST_PORT}" -u "${MYSQL_TEST_USER}" \
        -e "DROP DATABASE IF EXISTS \`${DB_FUND04}\`; DROP DATABASE IF EXISTS \`${DB_PKG67}\`;" 2>/dev/null || true
    echo "==> [CI Harness] Cleanup finished with original exit code: ${exit_code}"
    exit "${exit_code}"
}
trap cleanup EXIT INT TERM

# 6. Programmatic Pre-flight Verification: Oracle MySQL 8.4 Server Identity & Isolation
echo "==> [CI Harness] Verifying Oracle MySQL 8.4 server identity, version, and transaction isolation..."
DB_INSPECTION=$(MYSQL_PWD="${MYSQL_TEST_PASSWORD}" mysql -h "${MYSQL_TEST_HOST}" -P "${MYSQL_TEST_PORT}" -u "${MYSQL_TEST_USER}" -N -B \
    -e "SELECT @@version, @@default_storage_engine, @@transaction_isolation;")

read -r DB_VERSION DB_ENGINE DB_ISOLATION <<< "${DB_INSPECTION}"

echo "    Database Version:   ${DB_VERSION}"
echo "    Storage Engine:     ${DB_ENGINE}"
echo "    Isolation Level:    ${DB_ISOLATION}"

if [[ ! "${DB_VERSION}" =~ ^8\.4\. ]]; then
    echo "::error title=INCORRECT MYSQL VERSION::Expected Oracle MySQL 8.4.x, got '${DB_VERSION}'." >&2
    exit 1
fi

if [[ "${DB_VERSION,,}" =~ mariadb ]]; then
    echo "::error title=MARIADB DETECTED::Expected Oracle MySQL 8.4, detected MariaDB '${DB_VERSION}'." >&2
    exit 1
fi

if [[ "${DB_ENGINE,,}" != "innodb" ]]; then
    echo "::error title=INCORRECT STORAGE ENGINE::Default engine is '${DB_ENGINE}', must be InnoDB." >&2
    exit 1
fi

if [[ "${DB_ISOLATION,,}" != "repeatable-read" && "${DB_ISOLATION,,}" != "repeatable_read" ]]; then
    echo "::error title=INCORRECT ISOLATION::Transaction isolation is '${DB_ISOLATION}', must be REPEATABLE-READ." >&2
    exit 1
fi

mkdir -p storage/logs artifacts
FUND04_JUNIT="artifacts/fund04_concurrency_junit.xml"
FUND04_LOG="artifacts/fund04_concurrency_raw.log"
PKG67_JUNIT="artifacts/pkg67_concurrency_junit.xml"
PKG67_LOG="artifacts/pkg67_concurrency_raw.log"

# 7. Suite 1: FUND-04 Execution on Isolated Schema
echo "==> [CI Harness] Preparing Schema 1 for FUND-04: ${DB_FUND04}..."
MYSQL_PWD="${MYSQL_TEST_PASSWORD}" mysql -h "${MYSQL_TEST_HOST}" -P "${MYSQL_TEST_PORT}" -u "${MYSQL_TEST_USER}" \
    -e "CREATE DATABASE \`${DB_FUND04}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan config:clear || true
php artisan cache:clear || true

echo "==> [CI Harness] Migrating ${DB_FUND04}..."
DB_CONNECTION=mysql DB_HOST="${MYSQL_TEST_HOST}" DB_PORT="${MYSQL_TEST_PORT}" \
DB_DATABASE="${DB_FUND04}" DB_USERNAME="${MYSQL_TEST_USER}" DB_PASSWORD="${MYSQL_TEST_PASSWORD}" \
php artisan migrate --force

NON_INNODB_FUND04=$(MYSQL_PWD="${MYSQL_TEST_PASSWORD}" mysql -h "${MYSQL_TEST_HOST}" -P "${MYSQL_TEST_PORT}" -u "${MYSQL_TEST_USER}" -D "${DB_FUND04}" -N -B \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_FUND04}' AND engine != 'InnoDB';")
if [[ "${NON_INNODB_FUND04}" -gt 0 ]]; then
    echo "::error title=NON-INNODB TABLES DETECTED::Found ${NON_INNODB_FUND04} table(s) in schema '${DB_FUND04}' not using InnoDB." >&2
    exit 1
fi

echo "==> [CI Harness] Executing Suite 1: FUND-04 Concurrency Proof (3 cases)..."
set +e
DB_CONNECTION=mysql DB_HOST="${MYSQL_TEST_HOST}" DB_PORT="${MYSQL_TEST_PORT}" \
DB_DATABASE="${DB_FUND04}" DB_USERNAME="${MYSQL_TEST_USER}" DB_PASSWORD="${MYSQL_TEST_PASSWORD}" \
php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Concurrency/Fund04OracleConcurrencyProofTest.php \
    --log-junit="${FUND04_JUNIT}" 2>&1 | tee "${FUND04_LOG}"
FUND04_EXIT=${PIPESTATUS[0]}
set -e

if [[ "${FUND04_EXIT}" -ne 0 ]]; then
    echo "::error title=FUND-04 EXECUTION FAILED::PHPUnit exited with code ${FUND04_EXIT}." >&2
    exit "${FUND04_EXIT}"
fi

# 8. Suite 2: PKG-67 Execution on Isolated Schema
echo "==> [CI Harness] Preparing Schema 2 for PKG-67: ${DB_PKG67}..."
MYSQL_PWD="${MYSQL_TEST_PASSWORD}" mysql -h "${MYSQL_TEST_HOST}" -P "${MYSQL_TEST_PORT}" -u "${MYSQL_TEST_USER}" \
    -e "CREATE DATABASE \`${DB_PKG67}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan config:clear || true
php artisan cache:clear || true

echo "==> [CI Harness] Migrating ${DB_PKG67}..."
DB_CONNECTION=mysql DB_HOST="${MYSQL_TEST_HOST}" DB_PORT="${MYSQL_TEST_PORT}" \
DB_DATABASE="${DB_PKG67}" DB_USERNAME="${MYSQL_TEST_USER}" DB_PASSWORD="${MYSQL_TEST_PASSWORD}" \
php artisan migrate --force

NON_INNODB_PKG67=$(MYSQL_PWD="${MYSQL_TEST_PASSWORD}" mysql -h "${MYSQL_TEST_HOST}" -P "${MYSQL_TEST_PORT}" -u "${MYSQL_TEST_USER}" -D "${DB_PKG67}" -N -B \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_PKG67}' AND engine != 'InnoDB';")
if [[ "${NON_INNODB_PKG67}" -gt 0 ]]; then
    echo "::error title=NON-INNODB TABLES DETECTED::Found ${NON_INNODB_PKG67} table(s) in schema '${DB_PKG67}' not using InnoDB." >&2
    exit 1
fi

echo "==> [CI Harness] Executing Suite 2: PKG-67 Concurrency Proof (6 cases)..."
set +e
DB_CONNECTION=mysql DB_HOST="${MYSQL_TEST_HOST}" DB_PORT="${MYSQL_TEST_PORT}" \
DB_DATABASE="${DB_PKG67}" DB_USERNAME="${MYSQL_TEST_USER}" DB_PASSWORD="${MYSQL_TEST_PASSWORD}" \
FEATURE_SHIFT_DID_NOT_WORK=true \
php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Concurrency/Pkg67OracleConcurrencyProofTest.php \
    --log-junit="${PKG67_JUNIT}" 2>&1 | tee "${PKG67_LOG}"
PKG67_EXIT=${PIPESTATUS[0]}
set -e

if [[ "${PKG67_EXIT}" -ne 0 ]]; then
    echo "::error title=PKG-67 EXECUTION FAILED::PHPUnit exited with code ${PKG67_EXIT}." >&2
    exit "${PKG67_EXIT}"
fi

# 9. Strict JUnit Zero-Skip and Zero-Failure Verification
python3 - << 'PY'
import sys
import xml.etree.ElementTree as ET

for name, path, expected_count in [
    ("FUND-04", "artifacts/fund04_concurrency_junit.xml", 3),
    ("PKG-67", "artifacts/pkg67_concurrency_junit.xml", 6),
]:
    tree = ET.parse(path)
    root = tree.getroot()
    cases = root.findall(".//testcase")
    failures = root.findall(".//failure")
    errors = root.findall(".//error")
    skipped = root.findall(".//skipped")
    
    print(f"[{name} Report] Tests: {len(cases)}, Failures: {len(failures)}, Errors: {len(errors)}, Skipped: {len(skipped)}")
    
    if len(cases) != expected_count:
        print(f"::error title={name} COUNT MISMATCH::Expected {expected_count} tests, got {len(cases)}", file=sys.stderr)
        sys.exit(1)
    if len(skipped) > 0:
        print(f"::error title={name} ZERO SKIP VIOLATION::Found {len(skipped)} skipped tests. Strict zero-skip policy enforced.", file=sys.stderr)
        sys.exit(1)
    if len(failures) > 0 or len(errors) > 0:
        print(f"::error title={name} FAILURES DETECTED::Suite contains failures or errors.", file=sys.stderr)
        sys.exit(1)

print("SUCCESS: Both FUND-04 and PKG-67 suites passed with ZERO skips and ZERO failures.")
PY

echo "==> [CI Harness] Concurrency proof completed successfully."
