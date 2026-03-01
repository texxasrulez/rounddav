#!/usr/bin/env bash
set -euo pipefail

TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROUND_DAV_ROOT="$(cd "${TEST_DIR}/.." && pwd)"

TMP_DIR="$(mktemp -d)"
SERVER_PID=""
SERVER_LOG="${TMP_DIR}/php-server.log"
COOKIE_JAR="${TMP_DIR}/cookies.txt"
ADMIN_COOKIE_JAR="${TMP_DIR}/admin-cookies.txt"
PORT="$(php -r 'echo random_int(21000, 39000);')"
BASE_URL="http://127.0.0.1:${PORT}"

cleanup() {
  if [[ -n "${SERVER_PID}" ]]; then
    kill "${SERVER_PID}" >/dev/null 2>&1 || true
    wait "${SERVER_PID}" >/dev/null 2>&1 || true
  fi
  rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

fail() {
  echo "FAIL: $1" >&2
  if [[ -f "${SERVER_LOG}" ]]; then
    echo "--- php server log (tail) ---" >&2
    tail -n 60 "${SERVER_LOG}" >&2 || true
  fi
  exit 1
}

require_php_extensions() {
  local missing=()
  for ext in "$@"; do
    if ! php -r "exit(extension_loaded('${ext}') ? 0 : 1);"; then
      missing+=("${ext}")
    fi
  done

  if [[ "${#missing[@]}" -gt 0 ]]; then
    echo "Missing required PHP extension(s): ${missing[*]}" >&2
    echo "Install/enable them for the PHP CLI used by this script, then retry." >&2
    echo "Example (Debian/Ubuntu): sudo apt install php-sqlite3" >&2
    exit 2
  fi
}

require_pdo_driver() {
  local driver="$1"
  if ! php -r '
    $driver = $argv[1] ?? "";
    if (!class_exists("PDO")) {
      exit(2);
    }
    $drivers = PDO::getAvailableDrivers();
    exit(in_array($driver, $drivers, true) ? 0 : 1);
  ' "${driver}"; then
    echo "Missing required PDO driver: ${driver}" >&2
    echo "PHP binary: $(command -v php)" >&2
    echo "Loaded php.ini: $(php --ini | awk -F': ' '/Loaded Configuration File/{print $2}' | head -n1)" >&2
    echo "Available PDO drivers: $(php -r 'echo implode(\",\", PDO::getAvailableDrivers());')" >&2
    echo "Tip: ensure pdo_${driver} is enabled for CLI PHP, not only FPM/Apache." >&2
    exit 2
  fi
}

require_php_extensions pdo pdo_sqlite
require_pdo_driver sqlite

assert_status() {
  local got="$1"
  local expected="$2"
  local label="$3"
  if [[ "${got}" != "${expected}" ]]; then
    fail "${label}: expected HTTP ${expected}, got ${got}"
  fi
}

extract_status() {
  local header_file="$1"
  awk '/^HTTP\//{code=$2} END{print code}' "${header_file}"
}

extract_csrf_token() {
  local body_file="$1"
  sed -n 's/.*name="_rdv_csrf"[[:space:]]\+value="\([^"]\+\)".*/\1/p' "${body_file}" | head -n1
}

echo "Preparing temporary RoundDAV sandbox in ${TMP_DIR}"
cp -a "${ROUND_DAV_ROOT}" "${TMP_DIR}/rounddav"
SANDBOX_ROOT="${TMP_DIR}/rounddav"
DB_PATH="${TMP_DIR}/rounddav-test.sqlite"
FILES_ROOT="${TMP_DIR}/files-root"
mkdir -p "${FILES_ROOT}" "${SANDBOX_ROOT}/var/logs"

cat > "${SANDBOX_ROOT}/config/config.php" <<PHP
<?php
return [
    'debug' => false,
    'database' => [
        'dsn' => 'sqlite:${DB_PATH}',
        'user' => '',
        'password' => '',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ],
    ],
    'base_uri' => '/',
    'log' => [
        'enabled' => true,
        'file' => __DIR__ . '/../var/logs/rounddav.log',
    ],
    'security' => [
        'trust_proxy_headers' => false,
        'rate_limit' => [
            'enabled' => false,
            'max_requests' => 1000,
            'window_sec' => 300,
            'block_minutes' => 30,
            'ip_allowlist' => [],
            'scope_overrides' => [],
        ],
    ],
    'admin' => [
        'username' => 'admin',
        'email' => 'admin@example.com',
        'password_hash' => '${TMP_DIR}',
    ],
    'provision' => [
        'shared_secret' => 'test_provision_secret',
        'principal_prefix' => 'principals',
    ],
    'sso' => [
        'enabled' => true,
        'secret' => 'test_sso_secret',
        'ttl' => 120,
    ],
    'files' => [
        'enabled' => true,
        'root' => '${FILES_ROOT}',
        'public_url' => './files/',
        'shared_write_policy' => 'admins_only',
    ],
    'bookmarks' => [
        'enabled' => false,
    ],
    'globals' => [
        'addressbooks' => [],
        'calendars' => [],
    ],
    'endpoints' => [
        'enable_testdav' => false,
    ],
    'locale' => [
        'default' => 'en_US',
    ],
];
PHP

ADMIN_HASH="$(php -r 'echo password_hash("adminpass", PASSWORD_DEFAULT);')"
php -r '
$file = $argv[1];
$hash = $argv[2];
$cfg = require $file;
$cfg["admin"]["password_hash"] = $hash;
file_put_contents($file, "<?php\nreturn " . var_export($cfg, true) . ";\n");
' "${SANDBOX_ROOT}/config/config.php" "${ADMIN_HASH}"

DB_PATH="${DB_PATH}" php <<'PHP'
<?php
declare(strict_types=1);
$dbPath = getenv('DB_PATH') ?: '';
if ($dbPath === '') {
    fwrite(STDERR, "Missing sqlite database path argument\n");
    exit(2);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE principals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  uri TEXT NOT NULL UNIQUE,
  email TEXT NULL,
  displayname TEXT NULL
)');

$pdo->exec('CREATE TABLE calendars (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  synctoken INTEGER NOT NULL DEFAULT 1,
  components TEXT NULL
)');

$pdo->exec('CREATE TABLE calendarinstances (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  calendarid INTEGER NOT NULL,
  principaluri TEXT NULL,
  access INTEGER NOT NULL DEFAULT 1,
  displayname TEXT NULL,
  uri TEXT NULL,
  description TEXT NULL,
  calendarorder INTEGER NOT NULL DEFAULT 0,
  calendarcolor TEXT NULL,
  timezone TEXT NULL,
  transparent INTEGER NOT NULL DEFAULT 0,
  share_href TEXT NULL,
  share_displayname TEXT NULL,
  share_invitestatus INTEGER NOT NULL DEFAULT 2,
  UNIQUE(principaluri, uri)
)');

$pdo->exec('CREATE TABLE addressbooks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  principaluri TEXT NULL,
  displayname TEXT NULL,
  uri TEXT NULL,
  description TEXT NULL,
  synctoken INTEGER NOT NULL DEFAULT 1,
  UNIQUE(principaluri, uri)
)');

$pdo->exec('CREATE TABLE rounddav_users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  principal_uri TEXT NOT NULL UNIQUE,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$stmt = $pdo->prepare('INSERT INTO rounddav_users (username, password_hash, principal_uri, active)
VALUES (:u, :h, :p, 1)');
$stmt->execute([
  ':u' => 'user@example.com',
  ':h' => password_hash('secret123', PASSWORD_DEFAULT),
  ':p' => 'principals/user@example.com',
]);
PHP

echo "Starting php built-in server on ${BASE_URL}"
php -S "127.0.0.1:${PORT}" -t "${SANDBOX_ROOT}/public" > "${SERVER_LOG}" 2>&1 &
SERVER_PID="$!"

for _ in $(seq 1 40); do
  if curl -sS -o /dev/null "${BASE_URL}/index.php"; then
    break
  fi
  sleep 0.1
done

curl -sS -o /dev/null "${BASE_URL}/index.php" || fail "PHP server did not start"

echo "Running API checks"
API_HEADERS="${TMP_DIR}/api.headers"
API_BODY="${TMP_DIR}/api.body"
curl -sS -D "${API_HEADERS}" -o "${API_BODY}" \
  -H "Content-Type: application/json" \
  -H "X-RoundDAV-Token: test_provision_secret" \
  -X POST \
  --data '{"username":"provision@example.com","password":"provision-pass"}' \
  "${BASE_URL}/api.php?r=provision/user"
assert_status "$(extract_status "${API_HEADERS}")" "200" "Provision API valid token"
grep -q '"status":"ok"' "${API_BODY}" || fail "Provision API valid token should return status ok"

curl -sS -D "${API_HEADERS}" -o "${API_BODY}" \
  -H "Content-Type: application/json" \
  -H "X-RoundDAV-Token: wrong_token" \
  -X POST \
  --data '{"username":"badtoken@example.com","password":"x"}' \
  "${BASE_URL}/api.php?r=provision/user"
assert_status "$(extract_status "${API_HEADERS}")" "403" "Provision API invalid token"
grep -q 'Invalid API token' "${API_BODY}" || fail "Provision API invalid token should mention invalid token"

curl -sS -D "${API_HEADERS}" -o "${API_BODY}" \
  -H "Content-Type: application/json" \
  -H "X-RoundDAV-Token: test_provision_secret" \
  -X POST \
  --data '{"username":"missing-password@example.com"}' \
  "${BASE_URL}/api.php?r=provision/user"
assert_status "$(extract_status "${API_HEADERS}")" "400" "Provision API missing fields"
grep -q 'Missing username or password' "${API_BODY}" || fail "Provision API missing fields should mention missing credentials"

echo "Running SSO checks"
now_ts="$(date +%s)"
valid_sig="$(php -r 'echo hash_hmac("sha256", $argv[1] . "|" . $argv[2], "test_sso_secret");' "user@example.com" "${now_ts}")"
curl -sS -D "${API_HEADERS}" -o "${API_BODY}" \
  "${BASE_URL}/sso_login.php?user=user@example.com&ts=${now_ts}&sig=${valid_sig}"
assert_status "$(extract_status "${API_HEADERS}")" "302" "SSO login valid signature"
grep -qi '^Location: ./files/' "${API_HEADERS}" || fail "SSO login should redirect to files UI"

expired_ts="$((now_ts - 9999))"
expired_sig="$(php -r 'echo hash_hmac("sha256", $argv[1] . "|" . $argv[2], "test_sso_secret");' "user@example.com" "${expired_ts}")"
curl -sS -D "${API_HEADERS}" -o "${API_BODY}" \
  "${BASE_URL}/sso_login.php?user=user@example.com&ts=${expired_ts}&sig=${expired_sig}"
assert_status "$(extract_status "${API_HEADERS}")" "403" "SSO login expired token"
grep -q 'SSO token expired' "${API_BODY}" || fail "SSO login expired token should return expiry message"

curl -sS -D "${API_HEADERS}" -o "${API_BODY}" \
  "${BASE_URL}/sso_login.php?user=user@example.com&ts=${now_ts}&sig=deadbeef"
assert_status "$(extract_status "${API_HEADERS}")" "403" "SSO login bad signature"
grep -q 'Invalid SSO signature' "${API_BODY}" || fail "SSO login bad signature should return invalid signature message"

logout_sig="$(php -r 'echo hash_hmac("sha256", $argv[1] . "|" . $argv[2] . "|logout", "test_sso_secret");' "user@example.com" "${now_ts}")"
curl -sS -D "${API_HEADERS}" -o "${API_BODY}" \
  "${BASE_URL}/sso_logout.php?user=user@example.com&ts=${now_ts}&sig=${logout_sig}"
assert_status "$(extract_status "${API_HEADERS}")" "200" "SSO logout valid signature"

echo "Running Admin backup ZIP checks"
ADMIN_LOGIN_HEADERS="${TMP_DIR}/admin-login.headers"
ADMIN_LOGIN_BODY="${TMP_DIR}/admin-login.body"
curl -sS -c "${ADMIN_COOKIE_JAR}" -b "${ADMIN_COOKIE_JAR}" -D "${ADMIN_LOGIN_HEADERS}" -o "${ADMIN_LOGIN_BODY}" \
  "${BASE_URL}/admin/index.php?action=login"
assert_status "$(extract_status "${ADMIN_LOGIN_HEADERS}")" "200" "Admin login GET"
admin_csrf="$(extract_csrf_token "${ADMIN_LOGIN_BODY}")"
[[ -n "${admin_csrf}" ]] || fail "Could not extract CSRF token from Admin login form"

curl -sS -c "${ADMIN_COOKIE_JAR}" -b "${ADMIN_COOKIE_JAR}" -D "${ADMIN_LOGIN_HEADERS}" -o "${ADMIN_LOGIN_BODY}" \
  -X POST \
  --data-urlencode "_rdv_csrf=${admin_csrf}" \
  --data-urlencode "username=admin" \
  --data-urlencode "password=adminpass" \
  "${BASE_URL}/admin/index.php?action=do_login"
assert_status "$(extract_status "${ADMIN_LOGIN_HEADERS}")" "302" "Admin login POST"

ADMIN_CONFIG_HEADERS="${TMP_DIR}/admin-config.headers"
ADMIN_CONFIG_BODY="${TMP_DIR}/admin-config.body"
curl -sS -c "${ADMIN_COOKIE_JAR}" -b "${ADMIN_COOKIE_JAR}" -D "${ADMIN_CONFIG_HEADERS}" -o "${ADMIN_CONFIG_BODY}" \
  "${BASE_URL}/admin/index.php?action=config"
assert_status "$(extract_status "${ADMIN_CONFIG_HEADERS}")" "200" "Admin config GET"
admin_config_csrf="$(extract_csrf_token "${ADMIN_CONFIG_BODY}")"
[[ -n "${admin_config_csrf}" ]] || fail "Could not extract CSRF token from Admin config page"

curl -sS -c "${ADMIN_COOKIE_JAR}" -b "${ADMIN_COOKIE_JAR}" -D "${ADMIN_CONFIG_HEADERS}" -o "${ADMIN_CONFIG_BODY}" \
  -X POST \
  --data-urlencode "_rdv_csrf=${admin_config_csrf}" \
  "${BASE_URL}/admin/index.php?action=create_config_backup"
assert_status "$(extract_status "${ADMIN_CONFIG_HEADERS}")" "302" "Admin create config backup"

ADMIN_ZIP_HEADERS="${TMP_DIR}/admin-backups-zip.headers"
ADMIN_ZIP_BODY="${TMP_DIR}/admin-backups-zip.body"
curl -sS -c "${ADMIN_COOKIE_JAR}" -b "${ADMIN_COOKIE_JAR}" -D "${ADMIN_ZIP_HEADERS}" -o "${ADMIN_ZIP_BODY}" \
  -X POST \
  --data-urlencode "_rdv_csrf=${admin_config_csrf}" \
  "${BASE_URL}/admin/index.php?action=download_all_config_backups"
assert_status "$(extract_status "${ADMIN_ZIP_HEADERS}")" "200" "Admin download all backups ZIP"
grep -qi '^Content-Type: application/zip' "${ADMIN_ZIP_HEADERS}" || fail "Admin ZIP download should return Content-Type application/zip"
if [[ ! -s "${ADMIN_ZIP_BODY}" ]]; then
  fail "Admin ZIP download returned an empty body"
fi
zip_sig="$(head -c 4 "${ADMIN_ZIP_BODY}" | od -An -tx1 | tr -d ' \n')"
if [[ "${zip_sig}" != "504b0304" ]]; then
  fail "Admin ZIP download does not have a valid ZIP local-file signature (got: ${zip_sig})"
fi

echo "Running Files CSRF checks"
FILES_LOGIN_HEADERS="${TMP_DIR}/files-login.headers"
FILES_LOGIN_BODY="${TMP_DIR}/files-login.body"
curl -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" -D "${FILES_LOGIN_HEADERS}" -o "${FILES_LOGIN_BODY}" \
  "${BASE_URL}/files/index.php?action=login"
assert_status "$(extract_status "${FILES_LOGIN_HEADERS}")" "200" "Files login GET"
login_csrf="$(extract_csrf_token "${FILES_LOGIN_BODY}")"
[[ -n "${login_csrf}" ]] || fail "Could not extract CSRF token from Files login form"

curl -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" -D "${FILES_LOGIN_HEADERS}" -o "${FILES_LOGIN_BODY}" \
  -X POST \
  --data-urlencode "_rdv_csrf=${login_csrf}" \
  --data-urlencode "username=user@example.com" \
  --data-urlencode "password=secret123" \
  "${BASE_URL}/files/index.php?action=login"
assert_status "$(extract_status "${FILES_LOGIN_HEADERS}")" "302" "Files login POST"

FILES_BROWSE_HEADERS="${TMP_DIR}/files-browse.headers"
FILES_BROWSE_BODY="${TMP_DIR}/files-browse.body"
curl -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" -D "${FILES_BROWSE_HEADERS}" -o "${FILES_BROWSE_BODY}" \
  "${BASE_URL}/files/index.php?action=browse&area=user"
assert_status "$(extract_status "${FILES_BROWSE_HEADERS}")" "200" "Files browse GET"
browse_csrf="$(extract_csrf_token "${FILES_BROWSE_BODY}")"
[[ -n "${browse_csrf}" ]] || fail "Could not extract CSRF token from Files browse form"

curl -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" -D "${FILES_BROWSE_HEADERS}" -o "${FILES_BROWSE_BODY}" \
  -X POST \
  --data-urlencode "do=mkdir" \
  --data-urlencode "dirname=NoTokenDir" \
  "${BASE_URL}/files/index.php?action=browse&area=user"
assert_status "$(extract_status "${FILES_BROWSE_HEADERS}")" "403" "Files mkdir without CSRF token"
grep -q 'Invalid CSRF token' "${FILES_BROWSE_BODY}" || fail "Files mkdir without CSRF should return invalid CSRF message"

curl -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" -D "${FILES_BROWSE_HEADERS}" -o "${FILES_BROWSE_BODY}" \
  -X POST \
  --data-urlencode "_rdv_csrf=${browse_csrf}" \
  --data-urlencode "do=mkdir" \
  --data-urlencode "dirname=WithTokenDir" \
  "${BASE_URL}/files/index.php?action=browse&area=user"
assert_status "$(extract_status "${FILES_BROWSE_HEADERS}")" "302" "Files mkdir with CSRF token"

if [[ ! -d "${FILES_ROOT}/users/user@example.com/WithTokenDir" ]]; then
  fail "Expected created directory not found: ${FILES_ROOT}/users/user@example.com/WithTokenDir"
fi

echo "Integration HTTP smoke checks passed."
