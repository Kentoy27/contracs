<?php
$pdo = null;
if (!defined('CONTRACS_SKIP_DB') || CONTRACS_SKIP_DB !== true) {
    require __DIR__ . '/config/db.php';
}
require __DIR__ . '/includes/cache_headers.php';

ctr_session_start();

// Heavy page with a long list; allow a short edge cache so reloads
// return instantly when nothing has changed.
ctr_cache_headers('short', 20);

/**
 * Create a unique writable subdirectory for temporary work.
 *
 * Tries sys_get_temp_dir() first, then falls back to a project-local
 * tmp/ folder so uploads keep working on hosts where the system temp
 * directory is read-only (e.g. Windows / XAMPP with locked %TEMP%).
 */
function ctr_temp_subdir(string $prefix = 'ctr_xlsx_'): string
{
    $candidates = [
        sys_get_temp_dir(),
        __DIR__ . DIRECTORY_SEPARATOR . 'tmp',
    ];
    foreach ($candidates as $base) {
        if ($base === '' || $base === null) {
            continue;
        }
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        if (!is_dir($base) || !is_writable($base)) {
            continue;
        }
        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(4));
        if (@mkdir($dir, 0700, true) || is_dir($dir)) {
            return $dir;
        }
    }
    return '';
}

/**
 * Create a unique writable temp FILE, with a fallback when the system
 * temp directory is not writable (e.g. locked %TEMP% on Windows/XAMPP).
 */
function ctr_temp_file(string $prefix = 'ctr_'): string
{
    $candidates = [
        sys_get_temp_dir(),
        __DIR__ . DIRECTORY_SEPARATOR . 'tmp',
    ];
    foreach ($candidates as $base) {
        if ($base === '' || $base === null) {
            continue;
        }
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        if (!is_dir($base) || !is_writable($base)) {
            continue;
        }
        $f = @tempnam($base, $prefix);
        if (is_string($f) && $f !== '') {
            return $f;
        }
    }
    return '';
}

function ensure_users_schema_safe(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT NOT NULL AUTO_INCREMENT,
            username VARCHAR(64) NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(32) NOT NULL DEFAULT 'user',
            name VARCHAR(255) NOT NULL,
            id_number VARCHAR(64) NOT NULL,
            position VARCHAR(255) NULL,
            department VARCHAR(255) NULL,
            admin_home_title VARCHAR(255) NULL,
            admin_department_unlocked tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            plan_months INT NULL,
            plan_expires_at DATETIME NULL,
            qr_token varchar(64) NULL,
            created_by INT NULL,
            is_free_trial tinyint(1) NOT NULL DEFAULT 0,
            face_data LONGTEXT NULL,
            face_image LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_users_username (username),
            UNIQUE KEY uniq_users_id_number (id_number),
            UNIQUE KEY uniq_users_qr_token (qr_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $existing = [];
    foreach ($cols as $col) {
        $existing[strtolower((string)($col['Field'] ?? ''))] = true;
    }
    if (!isset($existing['department'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN department varchar(255) NULL");
    }
    if (!isset($existing['admin_home_title'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN admin_home_title varchar(255) NULL");
    }
    if (!isset($existing['admin_department_unlocked'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN admin_department_unlocked tinyint(1) NOT NULL DEFAULT 0");
    }
    if (!isset($existing['is_active'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_active tinyint(1) NOT NULL DEFAULT 1");
    }
    if (!isset($existing['plan_months'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN plan_months INT NULL");
    }
    if (!isset($existing['plan_expires_at'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN plan_expires_at DATETIME NULL");
    }
    if (!isset($existing['updated_at'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }
    if (!isset($existing['qr_token'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN qr_token varchar(64) NULL");
    }
    if (!isset($existing['created_by'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN created_by INT NULL");
    }
    if (!isset($existing['is_free_trial'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_free_trial tinyint(1) NOT NULL DEFAULT 0");
    }
    if (!isset($existing['face_data'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN face_data LONGTEXT NULL");
    }
    if (!isset($existing['face_image'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN face_image LONGTEXT NULL");
    }
    try {
        $pdo->exec("CREATE UNIQUE INDEX uniq_users_qr_token ON users (qr_token)");
    }
    catch (Throwable $e) {
    }
    try {
        $pdo->exec("CREATE UNIQUE INDEX uniq_users_username ON users (username)");
    }
    catch (Throwable $e) {
    }
    try {
        $pdo->exec("CREATE UNIQUE INDEX uniq_users_id_number ON users (id_number)");
    }
    catch (Throwable $e) {
    }
}

function ensure_app_settings_schema_safe(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS app_settings (
            k VARCHAR(64) NOT NULL,
            v TEXT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_admin_home_links_schema_safe(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_home_links (
            id INT NOT NULL AUTO_INCREMENT,
            admin_id INT NOT NULL,
            token VARCHAR(64) NOT NULL,
            plan_expires_at DATETIME NOT NULL,
            issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at DATETIME NULL,
            device_id VARCHAR(64) NULL,
            device_bound_at DATETIME NULL,
            last_seen_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_admin_home_links_token (token),
            KEY idx_admin_home_links_admin (admin_id),
            KEY idx_admin_home_links_admin_active (admin_id, revoked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function revoke_admin_home_links(PDO $pdo, int $adminId): void
{
    ensure_admin_home_links_schema_safe($pdo);
    $stmt = $pdo->prepare("UPDATE admin_home_links SET revoked_at = NOW() WHERE admin_id = :id AND revoked_at IS NULL");
    $stmt->execute([':id' => $adminId]);
}

function revoke_admin_home_link_by_id(PDO $pdo, int $adminId, int $linkId): bool
{
    ensure_admin_home_links_schema_safe($pdo);
    $stmt = $pdo->prepare("UPDATE admin_home_links SET revoked_at = NOW() WHERE id = :id AND admin_id = :aid AND revoked_at IS NULL");
    $stmt->execute([':id' => $linkId, ':aid' => $adminId]);
    return $stmt->rowCount() > 0;
}

function count_active_admin_home_links(PDO $pdo, int $adminId): int
{
    ensure_admin_home_links_schema_safe($pdo);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_home_links WHERE admin_id = :aid AND revoked_at IS NULL");
    $stmt->execute([':aid' => $adminId]);
    return (int)$stmt->fetchColumn();
}

function issue_admin_home_link(PDO $pdo, int $adminId, string $planExpiresAt): array
{
    ensure_admin_home_links_schema_safe($pdo);

    // Enforce maximum 5 active links per admin.
    $activeCount = count_active_admin_home_links($pdo, $adminId);
    if ($activeCount >= 5) {
        // Revoke the oldest active link to make room.
        $oldest = $pdo->prepare("SELECT id FROM admin_home_links WHERE admin_id = :aid AND revoked_at IS NULL ORDER BY id ASC LIMIT 1");
        $oldest->execute([':aid' => $adminId]);
        $oldestId = (int)$oldest->fetchColumn();
        if ($oldestId > 0) {
            $pdo->prepare("UPDATE admin_home_links SET revoked_at = NOW() WHERE id = :id")->execute([':id' => $oldestId]);
        }
    }

    for ($i = 0; $i < 5; $i++) {
        $token = bin2hex(random_bytes(12));
        try {
            $stmt = $pdo->prepare("INSERT INTO admin_home_links (admin_id, token, plan_expires_at) VALUES (:aid, :t, :e)");
            $stmt->execute([':aid' => $adminId, ':t' => $token, ':e' => $planExpiresAt]);
            return ['token' => $token, 'row_id' => (int)$pdo->lastInsertId()];
        } catch (Throwable $e) {
        }
    }
    throw new RuntimeException('Failed to issue link.');
}

function get_or_issue_active_admin_home_link(PDO $pdo, int $adminId, string $planExpiresAt): array
{
    ensure_admin_home_links_schema_safe($pdo);
    $stmt = $pdo->prepare("SELECT id, token, plan_expires_at, device_id, device_bound_at FROM admin_home_links WHERE admin_id = :aid AND revoked_at IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([':aid' => $adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $rowExp = trim((string)($row['plan_expires_at'] ?? ''));
        if ($rowExp !== '' && $rowExp === $planExpiresAt) {
            return [
                'id' => (int)($row['id'] ?? 0),
                'token' => (string)($row['token'] ?? ''),
                'device_id' => (string)($row['device_id'] ?? ''),
                'device_bound_at' => $row['device_bound_at'] ?? null,
            ];
        }
    }
    $issued = issue_admin_home_link($pdo, $adminId, $planExpiresAt);
    return [
        'id' => (int)($issued['row_id'] ?? 0),
        'token' => (string)($issued['token'] ?? ''),
        'device_id' => '',
        'device_bound_at' => null,
    ];
}

function list_active_admin_home_links(PDO $pdo, int $adminId): array
{
    ensure_admin_home_links_schema_safe($pdo);
    $stmt = $pdo->prepare("SELECT id, token, plan_expires_at, device_id, device_bound_at, last_seen_at FROM admin_home_links WHERE admin_id = :aid AND revoked_at IS NULL ORDER BY id DESC");
    $stmt->execute([':aid' => $adminId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function current_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function current_app_base_path(): string
{
    $dir = (string)dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = str_replace('\\', '/', $dir);
    $dir = rtrim($dir, '/');
    return $dir === '' ? '' : $dir;
}

function build_admin_home_link_url(string $token): string
{
    return current_origin() . current_app_base_path() . '/home?home_key=' . rawurlencode($token);
}

function get_qr_secret(PDO $pdo): string
{
    ensure_app_settings_schema_safe($pdo);
    $stmt = $pdo->prepare("SELECT v FROM app_settings WHERE k = 'qr_secret' LIMIT 1");
    $stmt->execute();
    $v = $stmt->fetchColumn();
    $secret = is_string($v) ? $v : '';
    if ($secret !== '') {
        return $secret;
    }
    $secret = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("INSERT INTO app_settings (k, v) VALUES ('qr_secret', :v) ON DUPLICATE KEY UPDATE v = v");
    $stmt->execute([':v' => $secret]);
    return $secret;
}

function get_kiosk_token(PDO $pdo): string
{
    $env = getenv('CONTRACS_KIOSK_TOKEN');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    ensure_app_settings_schema_safe($pdo);
    $stmt = $pdo->prepare("SELECT v FROM app_settings WHERE k = 'kiosk_token' LIMIT 1");
    $stmt->execute();
    $v = $stmt->fetchColumn();
    $token = is_string($v) ? trim($v) : '';
    if ($token !== '') {
        return $token;
    }
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("INSERT INTO app_settings (k, v) VALUES ('kiosk_token', :v) ON DUPLICATE KEY UPDATE v = v");
    $stmt->execute([':v' => $token]);
    return $token;
}

function get_file_audit_secret(PDO $pdo): string
{
    $env = getenv('CONTRACS_FILE_AUDIT_SECRET');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    ensure_app_settings_schema_safe($pdo);
    $stmt = $pdo->prepare("SELECT v FROM app_settings WHERE k = 'file_audit_secret' LIMIT 1");
    $stmt->execute();
    $v = $stmt->fetchColumn();
    $secret = is_string($v) ? $v : '';
    if ($secret !== '') {
        return $secret;
    }
    $secret = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("INSERT INTO app_settings (k, v) VALUES ('file_audit_secret', :v) ON DUPLICATE KEY UPDATE v = v");
    $stmt->execute([':v' => $secret]);
    return $secret;
}

function b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function build_file_audit_cookie_value(PDO $pdo, string $fileId, int $uploadTs, int $userId): string
{
    $payload = $fileId . '|' . $uploadTs . '|' . $userId;
    $sig = b64url_encode(hash_hmac('sha256', $payload, get_file_audit_secret($pdo), true));
    return base64_encode($payload . '|' . $sig);
}

function set_file_audit_cookie(PDO $pdo, string $fileId, int $uploadTs, int $userId): void
{
    $value = build_file_audit_cookie_value($pdo, $fileId, $uploadTs, $userId);
    $expires = time() + (7 * 24 * 60 * 60);
    setcookie('file_audit', $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function normalize_person_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    return mb_strtolower($name, 'UTF-8');
}

/**
 * Face descriptor payload helpers — mirror attendance.php so both entry
 * scripts agree on v1 (legacy 42-dim geometric array, Euclidean distance)
 * and v2 (128-dim learned face descriptor from face-api FaceRecognitionNet,
 * Euclidean distance) face_data formats.
 */
define('FACE_EMBED_MIN_DIMS', 128);

function decode_face_payload($raw): ?array
{
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = @json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    if (isset($decoded['emb']) && is_array($decoded['emb'])) {
        $emb = array_values(array_map('floatval', $decoded['emb']));
        if (count($emb) < FACE_EMBED_MIN_DIMS) {
            return null;
        }
        $desc = null;
        if (isset($decoded['desc']) && is_array($decoded['desc'])) {
            $d2 = array_values(array_map('floatval', $decoded['desc']));
            if (count($d2) >= 8) {
                $desc = $d2;
            }
        }
        return ['v' => 2, 'kind' => 'embed', 'emb' => $emb, 'desc' => $desc];
    }

    if (isset($decoded['desc']) && is_array($decoded['desc'])) {
        $d3 = array_values(array_map('floatval', $decoded['desc']));
        if (count($d3) >= 8) {
            return ['v' => 1, 'kind' => 'legacy', 'emb' => null, 'desc' => $d3];
        }
    }

    // Legacy v1: a bare list of numbers (42-dim geometric descriptor).
    // Reject assoc objects that carry no recognized key — only a true list
    // (sequential integer keys) may be treated as a legacy descriptor.
    $keys = array_keys($decoded);
    if ($keys !== range(0, count($decoded) - 1)) {
        return null;
    }
    $vals = array_values(array_map('floatval', $decoded));
    if (count($vals) < 8) {
        return null;
    }
    return ['v' => 1, 'kind' => 'legacy', 'emb' => null, 'desc' => $vals];
}

function face_euclidean_distance(array $a, array $b): float
{
    $len = min(count($a), count($b));
    if ($len === 0) return PHP_FLOAT_MAX;
    $sum = 0.0;
    for ($i = 0; $i < $len; $i++) {
        $diff = (float)$a[$i] - (float)$b[$i];
        $sum += $diff * $diff;
    }
    return sqrt($sum);
}

/**
 * Compare two decoded face payloads. Same-kind payloads use their native
 * metric; legacy-vs-embed pairs are not comparable (PHP_FLOAT_MAX).
 */
function face_descriptor_distance(array $a, array $b): float
{
    $ka = (string)($a['kind'] ?? 'legacy');
    $kb = (string)($b['kind'] ?? 'legacy');
    if ($ka !== $kb) {
        return PHP_FLOAT_MAX;
    }
    if ($ka === 'embed') {
        $ea = $a['emb'] ?? null;
        $eb = $b['emb'] ?? null;
        if (!is_array($ea) || !is_array($eb) || count($ea) === 0 || count($eb) === 0) {
            return PHP_FLOAT_MAX;
        }
        return face_euclidean_distance($ea, $eb);
    }
    $da = $a['desc'] ?? null;
    $db = $b['desc'] ?? null;
    if (!is_array($da) || !is_array($db) || count($da) === 0 || count($db) === 0) {
        return PHP_FLOAT_MAX;
    }
    return face_euclidean_distance($da, $db);
}

/**
 * Check if a decoded face payload matches any existing enrolled face
 * (excluding a given user id). Comparisons are kind-aware: a new 128-dim
 * descriptor is only checked against stored v2 descriptors (legacy prints
 * are skipped — they are being re-enrolled during migration anyway).
 * Returns ['match' => false] or
 * ['match' => true, 'user_id' => ..., 'user_name' => ..., 'distance' => ...].
 */
function check_face_duplicate(PDO $pdo, array $newPayload, int $excludeUserId = 0, float $threshold = 0.45): array
{
    $stmt = $pdo->query("SELECT id, name, username, face_data FROM users WHERE face_data IS NOT NULL AND face_data != ''");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $uid = (int)$row['id'];
        if ($uid === $excludeUserId) continue;
        $existing = decode_face_payload((string)$row['face_data']);
        if (!$existing) continue;
        $dist = face_descriptor_distance($newPayload, $existing);
        if ($dist < $threshold) {
            return [
                'match' => true,
                'user_id' => $uid,
                'user_name' => (string)($row['name'] ?? ''),
                'username' => (string)($row['username'] ?? ''),
                'distance' => round($dist, 6),
            ];
        }
    }
    return ['match' => false];
}

function ensure_user_qr_token(PDO $pdo, int $userId, ?string $token): string
{
    $token = trim((string)($token ?? ''));
    if ($token !== '') {
        return $token;
    }
    $token = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("UPDATE users SET qr_token = :t WHERE id = :id");
    $stmt->execute([':t' => $token, ':id' => $userId]);
    return $token;
}

function compute_qr_payload(PDO $pdo, array $userRow): string
{
    $uid = (int)($userRow['id'] ?? 0);
    $idn = trim((string)($userRow['id_number'] ?? ''));
    $nameRaw = (string)($userRow['name'] ?? '');
    $nameNorm = normalize_person_name($nameRaw);
    $nameB64 = b64url_encode($nameNorm);
    $token = ensure_user_qr_token($pdo, $uid, $userRow['qr_token'] ?? null);
    $secret = get_qr_secret($pdo);
    $base = 'CTR1|' . $uid . '|' . $idn . '|' . $nameB64 . '|' . $token;
    $sig = b64url_encode(hash_hmac('sha256', $base, $secret, true));
    return 'CTR1.' . $uid . '.' . $idn . '.' . $nameB64 . '.' . $token . '.' . $sig;
}

function build_qr_png_bytes(PDO $pdo, array $userRow, int $size = 512): string
{
    if ((string)getenv('CONTRACS_DISABLE_QR_FETCH') === '1') {
        return '';
    }
    $payload = compute_qr_payload($pdo, $userRow);
    if ($payload === '') {
        return '';
    }

    // Cache QR PNGs on disk to avoid repeated external API calls.
    $cacheDir = __DIR__ . '/tmp/qr_cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheKey = hash('sha256', $payload . '_' . $size);
    $cacheFile = $cacheDir . '/' . $cacheKey . '.png';
    if (is_file($cacheFile) && is_readable($cacheFile)) {
        $cached = @file_get_contents($cacheFile);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
    }

    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&qzone=4&data=' . rawurlencode($payload);
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'header' => "User-Agent: Contracs\r\n",
            'follow_location' => 1,
            'max_redirects' => 2,
        ],
    ]);
    $bytes = @file_get_contents($url, false, $ctx);
    if (!is_string($bytes) || $bytes === '') {
        return '';
    }

    // Write to cache (best-effort).
    if ($cacheDir !== '' && is_dir($cacheDir)) {
        @file_put_contents($cacheFile, $bytes);
    }

    return $bytes;
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

$loggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// Kiosk token authentication — allows specific actions from home.php without login
$kioskAllowed = ['face_enroll', 'face_enroll_delete', 'check_face_duplicate', 'list_enrolled_faces', 'serve_face_image', 'get_face_image'];
$kioskAction = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$kioskMode = false;
// $kioskTokenValid is true whenever a correct kiosk token is presented for a
// kiosk-allowed action, even if a session is also logged in (e.g. an admin
// opening the kiosk page in the same browser). Such requests are already
// authorized by the kiosk token secret, so they are exempt from the CSRF check.
$kioskTokenValid = false;
if ($kioskAction !== '' && in_array($kioskAction, $kioskAllowed, true)) {
    $provided = (string)($_GET['kiosk_token'] ?? $_POST['kiosk_token'] ?? '');
    $provided = trim($provided);
    if ($provided !== '') {
        $expected = '';
        try { $expected = get_kiosk_token($pdo); } catch (Throwable $e) { $expected = ''; }
        $kioskTokenValid = ($expected !== '' && hash_equals($expected, $provided));
        if (!$loggedIn && $kioskTokenValid) {
            $kioskMode = true;
        }
    }
}

if (!$loggedIn && !$kioskMode) {
    if (isset($_GET['ajax']) && (string)($_GET['ajax'] ?? '') === '1') {
        json_response(['ok' => false, 'message' => 'Unauthorized.'], 401);
    }
    header('Location: ' . ctr_url('login'));
    exit;
}

// Default to an empty role so kiosk-mode requests (which skip the role check
// above) still have a defined $role for any downstream code that reads it.
$role = '';
$planExpired = false;

// In kiosk mode, skip role check entirely — the kiosk token provides authorization
if (!$kioskMode) {
    $role = (string)($_SESSION['role'] ?? '');
    if ($role !== 'admin' && $role !== 'superadmin') {
        if (isset($_GET['ajax']) && (string)($_GET['ajax'] ?? '') === '1') {
            json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
        }
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

try {
    ensure_users_schema_safe($pdo);
}
catch (Throwable $e) {
}

if ($role === 'admin') {
    $id = (int)($_SESSION['user_id'] ?? 0);
    $isAjax = isset($_GET['ajax']) && (string)($_GET['ajax'] ?? '') === '1';
    $ok = false;
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT is_active, plan_expires_at FROM users WHERE id = :id AND role = 'admin' LIMIT 1");
            $stmt->execute([':id' => $id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $isActive = (int)($u['is_active'] ?? 0) === 1;
                $expRaw = $u['plan_expires_at'] ?? null;
                $expStr = is_string($expRaw) ? trim($expRaw) : '';
                $planActive = false;
                if ($expStr !== '') {
                    $tz = new DateTimeZone('Asia/Manila');
                    $now = new DateTimeImmutable('now', $tz);
                    $exp = new DateTimeImmutable($expStr, $tz);
                    $planActive = $exp > $now;
                }
                $ok = $isActive && $planActive;
                $_SESSION['admin_needs_plan_request'] = !$ok;
            }
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    if (!$ok) {
        $_SESSION['plan_expired'] = true;
        $planExpired = true;
    } else {
        $_SESSION['plan_expired'] = false;
        $planExpired = false;
    }
}

if (isset($_GET['download_uploaded']) && (string)($_GET['download_uploaded'] ?? '') === '1') {
    @set_time_limit(0);
    $rel = (string)($_GET['path'] ?? '');
    $uploadsAccessDir = __DIR__ . '/uploads/access';
    $resolved = resolve_allowed_file_path($rel, [$uploadsAccessDir]);
    if (!$resolved['ok']) {
        json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
    }
    if ((int)$resolved['status'] === 404 || !is_file($resolved['path']) || !is_readable($resolved['path'])) {
        json_response(['ok' => false, 'message' => 'Not found.'], 404);
    }

    $filename = basename($resolved['path']);
    $fileId = pathinfo($filename, PATHINFO_FILENAME);
    $uploadTs = (int)@filemtime($resolved['path']);
    if (preg_match('/^([a-f0-9]{8,})_(\d{10})_/', $filename, $m)) {
        $fileId = (string)$m[1];
        $uploadTs = (int)$m[2];
    }
    if ($uploadTs <= 0) {
        $uploadTs = time();
    }

    set_file_audit_cookie($pdo, $fileId, $uploadTs, (int)($_SESSION['user_id'] ?? 0));
    http_download_file($resolved['path'], $filename);
}

if (isset($_GET['download_qr']) && (string)($_GET['download_qr'] ?? '') === '1') {
    @set_time_limit(0);
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'message' => 'Invalid id.'], 400);
    }
    try {
        $stmt = $pdo->prepare("SELECT id, username, name, id_number, qr_token FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            json_response(['ok' => false, 'message' => 'Not found.'], 404);
        }
        $bytes = build_qr_png_bytes($pdo, $u, 512);
        if (!is_string($bytes) || $bytes === '') {
            json_response(['ok' => false, 'message' => 'Failed to generate QR.'], 502);
        }
        $username = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', (string)($u['username'] ?? 'user')) ?? 'user';
        $idn = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', (string)($u['id_number'] ?? '')) ?? '';
        $filename = 'qr_' . $username . ($idn !== '' ? ('_' . $idn) : '') . '.png';

        set_file_audit_cookie($pdo, 'qr:' . (string)((int)($u['id'] ?? $id)), time(), (int)($_SESSION['user_id'] ?? 0));
        http_download_bytes('image/png', $filename, $bytes);
    }
    catch (Throwable $e) {
        json_response(['ok' => false, 'message' => 'Server error.'], 500);
    }
}

function mime_type_from_extension(string $filename): string
{
    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'png') return 'image/png';
    if ($ext === 'zip') return 'application/zip';
    if ($ext === 'docx') return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    if ($ext === 'mdb' || $ext === 'accdb') return 'application/vnd.ms-access';
    return 'application/octet-stream';
}

function detect_mime_type_for_path(string $path): string
{
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $m = @finfo_file($finfo, $path);
            @finfo_close($finfo);
            if (is_string($m) && $m !== '' && $m !== 'application/octet-stream') {
                return $m;
            }
        }
    }
    return mime_type_from_extension($path);
}

function resolve_allowed_file_path(string $relativePath, array $allowedBaseDirs): array
{
    $relativePath = str_replace(["\0", "\r", "\n"], '', $relativePath);
    $relativePath = str_replace('\\', '/', $relativePath);
    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '' || preg_match('/^[a-zA-Z]:/', $relativePath)) {
        return ['ok' => false, 'status' => 403, 'path' => ''];
    }
    if (preg_match('#(^|/)\.\.(?:/|$)#', $relativePath)) {
        return ['ok' => false, 'status' => 403, 'path' => ''];
    }

    $base0 = $allowedBaseDirs[0] ?? '';
    $base0Real = $base0 !== '' ? (realpath($base0) ?: $base0) : '';
    $candidate0 = $base0Real !== '' ? ($base0Real . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)) : '';

    foreach ($allowedBaseDirs as $base) {
        $baseReal = realpath($base) ?: $base;
        if (!is_string($baseReal) || $baseReal === '') {
            continue;
        }
        $candidate = $baseReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!file_exists($candidate)) {
            continue;
        }
        $real = realpath($candidate);
        if ($real === false) {
            return ['ok' => false, 'status' => 403, 'path' => ''];
        }
        $basePrefix = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($real, $basePrefix) && rtrim($real, DIRECTORY_SEPARATOR) !== rtrim($baseReal, DIRECTORY_SEPARATOR)) {
            return ['ok' => false, 'status' => 403, 'path' => ''];
        }
        return ['ok' => true, 'status' => 200, 'path' => $real];
    }

    if ($candidate0 !== '' && str_starts_with($candidate0, rtrim($base0Real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return ['ok' => true, 'status' => 404, 'path' => $candidate0];
    }
    return ['ok' => false, 'status' => 403, 'path' => ''];
}

function http_download_bytes(string $contentType, string $filename, string $bytes): void
{
    $filename = preg_replace('/[^\w.\- ]+/', '_', $filename) ?: 'download.bin';
    $len = strlen($bytes);

    // Close session first so other requests aren't blocked.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Clear ALL output buffers so bytes go straight to the client.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Disable output compression so flush() actually pushes data to the client.
    @ini_set('zlib.output_compression', '0');

    // Prevent the script from being killed mid-transfer.
    @set_time_limit(0);
    ignore_user_abort(true);

    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $len);
    header('Content-Transfer-Encoding: binary');
    header('Pragma: public');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('X-Content-Type-Options: nosniff');

    // Stream in 64 KB chunks so memory stays bounded.
    $chunkSize = 65536;
    for ($i = 0; $i < $len; $i += $chunkSize) {
        echo substr($bytes, $i, $chunkSize);
        flush();
    }
    exit;
}

function http_download_file(string $path, string $downloadFilename, ?string $contentType = null): void
{
    $ct = $contentType ?? detect_mime_type_for_path($path);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @ini_set('zlib.output_compression', '0');
    @set_time_limit(0);
    ignore_user_abort(true);

    $fileSize = @filesize($path);
    if ($fileSize === false || $fileSize <= 0) {
        json_response(['ok' => false, 'message' => 'Failed to read file.'], 500);
    }

    $filename = preg_replace('/[^\w.\- ]+/', '_', $downloadFilename) ?: 'download.bin';
    header('Content-Type: ' . $ct);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $fileSize);
    header('Content-Transfer-Encoding: binary');
    header('Pragma: public');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('X-Content-Type-Options: nosniff');

    $fh = @fopen($path, 'rb');
    if (!$fh) {
        json_response(['ok' => false, 'message' => 'Failed to read file.'], 500);
    }
    $chunkSize = 65536;
    while (!feof($fh)) {
        echo fread($fh, $chunkSize);
        flush();
    }
    fclose($fh);
    exit;
}

function split_name_last_first(string $fullName): array
{
    $fullName = trim((string)$fullName);
    if ($fullName === '') {
        return ['', ''];
    }
    if (strpos($fullName, ',') !== false) {
        [$last, $rest] = array_pad(explode(',', $fullName, 2), 2, '');
        $last = trim($last);
        $rest = trim($rest);
        return [$last, $rest];
    }
    $parts = preg_split('/\s+/', $fullName) ?: [$fullName];
    if (count($parts) === 1) {
        return [$fullName, ''];
    }
    $last = array_pop($parts);
    $first = trim(implode(' ', $parts));
    return [$last, $first];
}

function patch_docx_document_xml(string $xml, array $replacements, string $markerLine1, string $markerLine2): string
{
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    if (@$dom->loadXML($xml, LIBXML_NONET) !== true) {
        return $xml;
    }

    $xpath = new DOMXPath($dom);
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $xpath->registerNamespace('w', $wNs);

    $clearText = [
        'click to insert' => true,
        'Click to insert' => true,
        'ΓåæClick to insert' => true,
        'image' => true,
        'of' => true,
        'QR Code' => true,
        'Profile Picture' => true,
    ];

    foreach ($xpath->query('//w:t') as $node) {
        $v = (string)$node->nodeValue;
        if (isset($replacements[$v])) {
            $node->nodeValue = (string)$replacements[$v];
            continue;
        }
        if (isset($clearText[$v])) {
            $node->nodeValue = '';
        }
    }

    foreach ($xpath->query('//w:p') as $p) {
        if (!($p instanceof DOMElement)) continue;
        $tNodes = [];
        foreach ($p->getElementsByTagNameNS($wNs, 't') as $tn) {
            if ($tn instanceof DOMElement) $tNodes[] = $tn;
        }
        if (count($tNodes) < 2) continue;
        $full = '';
        foreach ($tNodes as $tn) { $full .= (string)$tn->nodeValue; }
        if (!isset($replacements[$full])) continue;
        $replacement = (string)$replacements[$full];
        $tNodes[0]->nodeValue = $replacement;
        for ($i = 1; $i < count($tNodes); $i++) { $tNodes[$i]->nodeValue = ''; }
    }

    $body = $xpath->query('//w:body')->item(0);
    if ($body instanceof DOMElement) {
        $sectPr = null;
        foreach ($body->childNodes as $ch) {
            if ($ch instanceof DOMElement && $ch->namespaceURI === $wNs && $ch->localName === 'sectPr') {
                $sectPr = $ch;
                break;
            }
        }
        if ($sectPr instanceof DOMElement) {
            $p = $dom->createElementNS($wNs, 'w:p');
            $pPr = $dom->createElementNS($wNs, 'w:pPr');
            $spacing = $dom->createElementNS($wNs, 'w:spacing');
            $spacing->setAttributeNS($wNs, 'w:before', '0');
            $spacing->setAttributeNS($wNs, 'w:after', '0');
            $pPr->appendChild($spacing);
            $p->appendChild($pPr);

            $r = $dom->createElementNS($wNs, 'w:r');
            $rPr = $dom->createElementNS($wNs, 'w:rPr');
            $sz = $dom->createElementNS($wNs, 'w:sz');
            $sz->setAttributeNS($wNs, 'w:val', '16');
            $color = $dom->createElementNS($wNs, 'w:color');
            $color->setAttributeNS($wNs, 'w:val', '808080');
            $rPr->appendChild($sz);
            $rPr->appendChild($color);
            $r->appendChild($rPr);

            $t1 = $dom->createElementNS($wNs, 'w:t');
            $t1->appendChild($dom->createTextNode($markerLine1));
            $r->appendChild($t1);
            $r->appendChild($dom->createElementNS($wNs, 'w:br'));
            $t2 = $dom->createElementNS($wNs, 'w:t');
            $t2->appendChild($dom->createTextNode($markerLine2));
            $r->appendChild($t2);

            $p->appendChild($r);
            $body->insertBefore($p, $sectPr);
        }
    }

    $out = $dom->saveXML();
    return is_string($out) && $out !== '' ? $out : $xml;
}

function zip_u16(int $v): string
{
    return pack('v', $v & 0xFFFF);
}

function zip_u32(int $v): string
{
    return pack('V', $v & 0xFFFFFFFF);
}

function zip_crc32u(string $data): int
{
    return (int)hexdec(hash('crc32b', $data));
}

function zip_dos_time_date(?int $ts = null): array
{
    $ts = $ts ?? time();
    $dt = getdate($ts);
    $year = (int)($dt['year'] ?? 1980);
    if ($year < 1980) $year = 1980;
    if ($year > 2107) $year = 2107;
    $month = (int)($dt['mon'] ?? 1);
    $day = (int)($dt['mday'] ?? 1);
    $hour = (int)($dt['hours'] ?? 0);
    $min = (int)($dt['minutes'] ?? 0);
    $sec = (int)($dt['seconds'] ?? 0);

    $dosTime = (($hour & 0x1F) << 11) | (($min & 0x3F) << 5) | ((int)($sec / 2) & 0x1F);
    $dosDate = ((($year - 1980) & 0x7F) << 9) | (($month & 0x0F) << 5) | ($day & 0x1F);
    return [$dosTime, $dosDate];
}

function zip_find_eocd(string $zipBytes): int
{
    $len = strlen($zipBytes);
    $maxBack = min($len, 22 + 0xFFFF);
    $start = $len - $maxBack;
    for ($i = $len - 22; $i >= $start; $i--) {
        if (substr($zipBytes, $i, 4) === "PK\x05\x06") {
            return $i;
        }
    }
    return -1;
}

function zip_read_entries(string $zipBytes): array
{
    $eocdPos = zip_find_eocd($zipBytes);
    if ($eocdPos < 0) {
        return [];
    }
    $eocd = substr($zipBytes, $eocdPos, 22);
    if (strlen($eocd) < 22) {
        return [];
    }
    $cdSize = unpack('V', substr($eocd, 12, 4))[1] ?? 0;
    $cdOffset = unpack('V', substr($eocd, 16, 4))[1] ?? 0;
    if ($cdOffset <= 0 || $cdSize <= 0 || ($cdOffset + $cdSize) > strlen($zipBytes)) {
        return [];
    }
    $cd = substr($zipBytes, $cdOffset, $cdSize);
    $pos = 0;
    $out = [];
    while ($pos + 46 <= strlen($cd)) {
        if (substr($cd, $pos, 4) !== "PK\x01\x02") {
            break;
        }
        $gpFlags = unpack('v', substr($cd, $pos + 8, 2))[1] ?? 0;
        $method = unpack('v', substr($cd, $pos + 10, 2))[1] ?? 0;
        $modTime = unpack('v', substr($cd, $pos + 12, 2))[1] ?? 0;
        $modDate = unpack('v', substr($cd, $pos + 14, 2))[1] ?? 0;
        $crc = unpack('V', substr($cd, $pos + 16, 4))[1] ?? 0;
        $cSize = unpack('V', substr($cd, $pos + 20, 4))[1] ?? 0;
        $uSize = unpack('V', substr($cd, $pos + 24, 4))[1] ?? 0;
        $nameLen = unpack('v', substr($cd, $pos + 28, 2))[1] ?? 0;
        $extraLen = unpack('v', substr($cd, $pos + 30, 2))[1] ?? 0;
        $commentLen = unpack('v', substr($cd, $pos + 32, 2))[1] ?? 0;
        $localOffset = unpack('V', substr($cd, $pos + 42, 4))[1] ?? 0;
        $name = substr($cd, $pos + 46, $nameLen);
        $pos += 46 + $nameLen + $extraLen + $commentLen;
        if ($name === '') {
            continue;
        }
        $out[$name] = [
            'name' => $name,
            'gp_flags' => $gpFlags,
            'method' => $method,
            'mod_time' => $modTime,
            'mod_date' => $modDate,
            'crc' => $crc,
            'comp_size' => $cSize,
            'uncomp_size' => $uSize,
            'local_offset' => $localOffset,
        ];
    }
    return $out;
}

function zip_entry_compressed_slice(string $zipBytes, array $entry): string
{
    $off = (int)($entry['local_offset'] ?? 0);
    if ($off < 0 || $off + 30 > strlen($zipBytes)) {
        return '';
    }
    if (substr($zipBytes, $off, 4) !== "PK\x03\x04") {
        return '';
    }
    $nameLen = unpack('v', substr($zipBytes, $off + 26, 2))[1] ?? 0;
    $extraLen = unpack('v', substr($zipBytes, $off + 28, 2))[1] ?? 0;
    $dataOff = $off + 30 + $nameLen + $extraLen;
    $cSize = (int)($entry['comp_size'] ?? 0);
    if ($cSize < 0 || $dataOff + $cSize > strlen($zipBytes)) {
        return '';
    }
    return substr($zipBytes, $dataOff, $cSize);
}

function zip_entry_uncompressed(string $zipBytes, array $entry): string
{
    $method = (int)($entry['method'] ?? 0);
    $comp = zip_entry_compressed_slice($zipBytes, $entry);
    if ($comp === '') {
        return '';
    }
    if ($method === 0) {
        return $comp;
    }
    if ($method === 8) {
        $out = @gzinflate($comp);
        return is_string($out) ? $out : '';
    }
    return '';
}

function zip_build_archive(array $entries): string
{
    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;

    foreach ($entries as $e) {
        $name = (string)($e['name'] ?? '');
        if ($name === '') continue;
        $method = (int)($e['method'] ?? 8);
        $modTime = (int)($e['mod_time'] ?? 0);
        $modDate = (int)($e['mod_date'] ?? 0);
        if ($modTime === 0 || $modDate === 0) {
            [$modTime, $modDate] = zip_dos_time_date();
        }

        $data = (string)($e['data'] ?? '');
        $dataComp = array_key_exists('data_comp', $e) ? (string)$e['data_comp'] : null;
        $crc = array_key_exists('crc', $e) ? (int)$e['crc'] : zip_crc32u($data);
        $uSize = array_key_exists('uncomp_size', $e) ? (int)$e['uncomp_size'] : strlen($data);

        if ($dataComp === null) {
            if ($method === 0) {
                $dataComp = $data;
            } else {
                $dataComp = gzdeflate($data);
                $method = 8;
            }
        }

        $cSize = array_key_exists('comp_size', $e) ? (int)$e['comp_size'] : strlen($dataComp);

        $lfh = "PK\x03\x04"
            . zip_u16(20)
            . zip_u16(0)
            . zip_u16($method)
            . zip_u16($modTime)
            . zip_u16($modDate)
            . zip_u32($crc)
            . zip_u32($cSize)
            . zip_u32($uSize)
            . zip_u16(strlen($name))
            . zip_u16(0)
            . $name;

        $local .= $lfh . $dataComp;

        $cdir = "PK\x01\x02"
            . zip_u16(20)
            . zip_u16(20)
            . zip_u16(0)
            . zip_u16($method)
            . zip_u16($modTime)
            . zip_u16($modDate)
            . zip_u32($crc)
            . zip_u32($cSize)
            . zip_u32($uSize)
            . zip_u16(strlen($name))
            . zip_u16(0)
            . zip_u16(0)
            . zip_u16(0)
            . zip_u16(0)
            . zip_u32(0)
            . zip_u32($offset)
            . $name;

        $central .= $cdir;
        $offset += strlen($lfh) + strlen($dataComp);
        $count++;
    }

    $cdOffset = strlen($local);
    $cdSize = strlen($central);
    $eocd = "PK\x05\x06"
        . zip_u16(0)
        . zip_u16(0)
        . zip_u16($count)
        . zip_u16($count)
        . zip_u32($cdSize)
        . zip_u32($cdOffset)
        . zip_u16(0);

    return $local . $central . $eocd;
}

function xlsx_cell_col_letter(int $colIndex): string
{
    $colIndex = max(0, $colIndex);
    $letters = '';
    do {
        $letters = chr(65 + ($colIndex % 26)) . $letters;
        $colIndex = (int)floor($colIndex / 26) - 1;
    } while ($colIndex >= 0);
    return $letters;
}

function xlsx_inline_str_cell(int $colIndex, int $rowIndex, string $value, ?string $styleId = null): string
{
    $ref = xlsx_cell_col_letter($colIndex) . (string)$rowIndex;
    $styleAttr = $styleId ? ' s="' . htmlspecialchars($styleId, ENT_QUOTES, 'UTF-8') . '"' : '';
    $safe = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    return '<c r="' . $ref . '"' . $styleAttr . ' t="inlineStr"><is><t xml:space="preserve">' . $safe . '</t></is></c>';
}

function xlsx_number_cell(int $colIndex, int $rowIndex, int|float|string $value, ?string $styleId = null): string
{
    $ref = xlsx_cell_col_letter($colIndex) . (string)$rowIndex;
    $styleAttr = $styleId ? ' s="' . htmlspecialchars($styleId, ENT_QUOTES, 'UTF-8') . '"' : '';
    $val = is_numeric($value) ? (0 + $value) : 0;
    return '<c r="' . $ref . '"' . $styleAttr . '><v>' . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . '</v></c>';
}

function xlsx_empty_cell(int $colIndex, int $rowIndex, ?string $styleId = null): string
{
    $ref = xlsx_cell_col_letter($colIndex) . (string)$rowIndex;
    $styleAttr = $styleId ? ' s="' . htmlspecialchars($styleId, ENT_QUOTES, 'UTF-8') . '"' : '';
    return '<c r="' . $ref . '"' . $styleAttr . '/>';
}

function build_user_template_xlsx(string $lockedDepartment): string
{
    $headers = ['Username', 'ID Number', 'Real Name', 'Job Title', 'Department'];
    $sampleDept = $lockedDepartment !== '' ? $lockedDepartment : '';
    $sample = ['juan.delacruz', 'DEPED-0001', 'Juan Dela Cruz', 'Teacher I', $sampleDept];
    $rowNote = $lockedDepartment !== ''
        ? 'NOTE: Department is locked to your department. Username and ID Number must be unique. Default password will be auto-generated (changable later).'
        : 'NOTE: Fill in the Department column for each row. Username and ID Number must be unique. Default password will be auto-generated (changable later).';

    $rowsXml = '';
    $rowsXml .= '<row r="1" customFormat="false" ht="22" hidden="false" customHeight="false" outlineLevel="0" collapsed="false">';
    foreach ($headers as $i => $h) {
        $rowsXml .= xlsx_inline_str_cell($i, 1, $h, '1');
    }
    $rowsXml .= '</row>';

    $rowsXml .= '<row r="2" customFormat="false" ht="18" hidden="false" customHeight="false" outlineLevel="0" collapsed="false">';
    foreach ($sample as $i => $v) {
        $rowsXml .= xlsx_inline_str_cell($i, 2, (string)$v, '2');
    }
    $rowsXml .= '</row>';

    $noteRow = 4;
    $rowsXml .= '<row r="' . $noteRow . '">';
    $rowsXml .= xlsx_inline_str_cell(0, $noteRow, $rowNote, '3');
    $rowsXml .= '</row>';

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<cols>'
        . '<col min="1" max="1" width="22" customWidth="true"/>'
        . '<col min="2" max="2" width="18" customWidth="true"/>'
        . '<col min="3" max="3" width="28" customWidth="true"/>'
        . '<col min="4" max="4" width="22" customWidth="true"/>'
        . '<col min="5" max="5" width="18" customWidth="true"/>'
        . '</cols>'
        . '<sheetData>' . $rowsXml . '</sheetData>'
        . '</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>'
        . '<sheet name="Users" sheetId="1" r:id="rId1"/>'
        . '</sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'
        . '<font><sz val="11"/><name val="Calibri"/><color rgb="FF777777"/></font>'
        . '<font><i/><sz val="10"/><name val="Calibri"/><color rgb="FF888888"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF2F7D28"/><bgColor rgb="FF2F7D28"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="1">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    if (class_exists('ZipArchive')) {
        $tmp = ctr_temp_file('ctr_xlsx_');
        if (!is_string($tmp) || $tmp === '') {
            return zip_build_archive([
                ['name' => '[Content_Types].xml', 'data' => $contentTypes, 'method' => 0],
                ['name' => '_rels/.rels', 'data' => $rootRels, 'method' => 0],
                ['name' => 'xl/workbook.xml', 'data' => $workbookXml, 'method' => 0],
                ['name' => 'xl/_rels/workbook.xml.rels', 'data' => $workbookRels, 'method' => 0],
                ['name' => 'xl/worksheets/sheet1.xml', 'data' => $sheetXml, 'method' => 0],
                ['name' => 'xl/styles.xml', 'data' => $stylesXml, 'method' => 0],
            ]);
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return zip_build_archive([
                ['name' => '[Content_Types].xml', 'data' => $contentTypes, 'method' => 0],
                ['name' => '_rels/.rels', 'data' => $rootRels, 'method' => 0],
                ['name' => 'xl/workbook.xml', 'data' => $workbookXml, 'method' => 0],
                ['name' => 'xl/_rels/workbook.xml.rels', 'data' => $workbookRels, 'method' => 0],
                ['name' => 'xl/worksheets/sheet1.xml', 'data' => $sheetXml, 'method' => 0],
                ['name' => 'xl/styles.xml', 'data' => $stylesXml, 'method' => 0],
            ]);
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/styles.xml', $stylesXml);
        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        return is_string($bytes) ? $bytes : '';
    }

    return zip_build_archive([
        ['name' => '[Content_Types].xml', 'data' => $contentTypes, 'method' => 0],
        ['name' => '_rels/.rels', 'data' => $rootRels, 'method' => 0],
        ['name' => 'xl/workbook.xml', 'data' => $workbookXml, 'method' => 0],
        ['name' => 'xl/_rels/workbook.xml.rels', 'data' => $workbookRels, 'method' => 0],
        ['name' => 'xl/worksheets/sheet1.xml', 'data' => $sheetXml, 'method' => 0],
        ['name' => 'xl/styles.xml', 'data' => $stylesXml, 'method' => 0],
    ]);
}

function parse_user_template_upload(string $filePath, string $ext): array
{
    $rows = [];
    $lowerExt = strtolower($ext);
    $tempDir = null;
    $sourceForCleanup = $filePath;

    try {
        if ($lowerExt === 'xlsx') {
            if (!class_exists('ZipArchive')) {
                return ['ok' => false, 'message' => 'ZipArchive is required for .xlsx files.', 'rows' => []];
            }
            $tempDir = ctr_temp_subdir('ctr_xlsx_');
            if ($tempDir === '') {
                return ['ok' => false, 'message' => 'Failed to create temp dir. No writable temp directory is available.', 'rows' => []];
            }
            $zip = new ZipArchive();
            if ($zip->open($filePath) !== true) {
                return ['ok' => false, 'message' => 'Failed to open .xlsx file.', 'rows' => []];
            }
            $sharedStrings = [];
            $ssPath = 'xl/sharedStrings.xml';
            if ($zip->locateName($ssPath) !== false) {
                $ssXml = $zip->getFromName($ssPath);
                if (is_string($ssXml) && $ssXml !== '') {
                    $ssDom = @simplexml_load_string($ssXml);
                    if ($ssDom) {
                        foreach ($ssDom->si as $si) {
                            $t = '';
                            if (isset($si->t)) {
                                $t = (string)$si->t;
                            } else if (isset($si->r)) {
                                $parts = [];
                                foreach ($si->r as $r) {
                                    $parts[] = (string)$r->t;
                                }
                                $t = implode('', $parts);
                            }
                            $sharedStrings[] = $t;
                        }
                    }
                }
            }
            $sheetPath = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    $sheetPath = $name;
                    break;
                }
            }
            if (!is_string($sheetPath)) {
                $zip->close();
                return ['ok' => false, 'message' => 'No worksheet found in .xlsx.', 'rows' => []];
            }
            $sheetXml = $zip->getFromName($sheetPath);
            $zip->close();
            if (!is_string($sheetXml) || $sheetXml === '') {
                return ['ok' => false, 'message' => 'Empty worksheet.', 'rows' => []];
            }
            $dom = @simplexml_load_string($sheetXml);
            if (!$dom) {
                return ['ok' => false, 'message' => 'Failed to parse worksheet XML.', 'rows' => []];
            }
            $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            foreach ($dom->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $ref = (string)$c['r'];
                    $type = (string)$c['t'];
                    $styleId = (string)$c['s'];
                    $colLetters = preg_replace('/\d+/', '', $ref);
                    $colIndex = 0;
                    $len = strlen($colLetters);
                    for ($k = 0; $k < $len; $k++) {
                        $colIndex = $colIndex * 26 + (ord($colLetters[$k]) - 64);
                    }
                    $colIndex = $colIndex - 1;
                    $value = '';
                    if ($type === 'inlineStr' && isset($c->is->t)) {
                        $value = (string)$c->is->t;
                    } else if ($type === 's' && isset($c->v)) {
                        $idx = (int)(string)$c->v;
                        $value = isset($sharedStrings[$idx]) ? $sharedStrings[$idx] : '';
                    } else if (isset($c->v)) {
                        $value = (string)$c->v;
                    }
                    $cells[$colIndex] = $value;
                }
                if (!empty($cells)) {
                    ksort($cells);
                    $rows[] = array_values($cells);
                }
            }
        } else if ($lowerExt === 'xls') {
            $content = @file_get_contents($filePath);
            if (!is_string($content) || $content === '') {
                return ['ok' => false, 'message' => 'Empty file.', 'rows' => []];
            }
            $dom = @simplexml_load_string($content);
            if (!$dom) {
                return ['ok' => false, 'message' => 'Failed to parse .xls (SpreadsheetML).', 'rows' => []];
            }
            $ns = 'urn:schemas-microsoft-com:office:spreadsheet';
            $worksheets = $dom->Worksheet ?? null;
            if (!$worksheets) {
                return ['ok' => false, 'message' => 'No worksheet in .xls.', 'rows' => []];
            }
            $ws = isset($worksheets[0]) ? $worksheets[0] : $worksheets;
            if (!isset($ws->Table)) {
                return ['ok' => false, 'message' => 'No table in worksheet.', 'rows' => []];
            }
            foreach ($ws->Table->Row as $row) {
                $cells = [];
                foreach ($row->Cell as $cell) {
                    $ref = (string)$cell['ss:Index'];
                    if ($ref === '') {
                        $ref = (string)$cell['Index'];
                    }
                    $colIndex = 0;
                    if ($ref !== '' && preg_match('/^([A-Z]+)/', $ref, $m)) {
                        $letters = $m[1];
                        $len = strlen($letters);
                        for ($k = 0; $k < $len; $k++) {
                            $colIndex = $colIndex * 26 + (ord($letters[$k]) - 64);
                        }
                        $colIndex = $colIndex - 1;
                    } else {
                        $colIndex = count($cells);
                    }
                    $data = isset($cell->Data) ? (string)$cell->Data : '';
                    $cells[$colIndex] = $data;
                }
                if (!empty($cells)) {
                    ksort($cells);
                    $rows[] = array_values($cells);
                }
            }
        } else if ($lowerExt === 'csv') {
            $content = @file_get_contents($filePath);
            if (!is_string($content) || $content === '') {
                return ['ok' => false, 'message' => 'Empty file.', 'rows' => []];
            }
            if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
                $content = substr($content, 3);
            }
            $lines = preg_split('/\r\n|\r|\n/', $content);
            foreach ($lines as $line) {
                if ($line === '' && count($rows) === 0) continue;
                $row = str_getcsv($line, ',', '"', '\\');
                if ($row === false) continue;
                $row = array_map(function ($v) { return is_string($v) ? $v : (string)$v; }, $row);
                $rows[] = $row;
            }
        } else {
            return ['ok' => false, 'message' => 'Unsupported file type. Use .xlsx, .xls, or .csv.', 'rows' => []];
        }
    } finally {
        if (is_string($tempDir) && is_dir($tempDir)) {
            @rmdir($tempDir);
        }
    }

    return ['ok' => true, 'rows' => $rows];
}

function build_id_template_docx(PDO $pdo, array $userRow, string $templateKey, bool &$qrBulkFailed = false): string
{
    $templateKey = $templateKey === 'permanent' ? 'permanent' : 'cos_jo';
    $templatePath = __DIR__ . '/forms/' . ($templateKey === 'permanent'
        ? 'permanent_id_division_personnel_2025.docx'
        : 'cos_jo_id_division_personnel_2025.docx');

    if (!is_file($templatePath)) {
        return '';
    }

    $templateBytes = @file_get_contents($templatePath);
    if (!is_string($templateBytes) || $templateBytes === '') {
        return '';
    }

    $idNumber = trim((string)($userRow['id_number'] ?? ''));
    $fullName = trim((string)($userRow['name'] ?? ''));
    $position = trim((string)($userRow['position'] ?? ''));
    $department = trim((string)($userRow['department'] ?? ''));

    [$lastName, $firstName] = split_name_last_first($fullName);
    $lastName = mb_strtoupper($lastName, 'UTF-8');
    $firstName = mb_strtoupper($firstName, 'UTF-8');
    $position = mb_strtoupper($position, 'UTF-8');
    $department = mb_strtoupper($department, 'UTF-8');

    $replacements = [
        'EMPLOYEE NO. 1234567' => 'EMPLOYEE NO. ' . ($idNumber !== '' ? $idNumber : ''),
        'LASTNAME,' => ($lastName !== '' ? ($lastName . ',') : ''),
        'FIRSTNAME MI' => $firstName,
        'POSITION' => $position,
        'STATION/OFFICE' => $department !== '' ? $department : $position,
    ];

    // Skip QR generation after the first API failure during bulk downloads
    // to avoid cascading timeouts against the external QR API.
    if ($qrBulkFailed) {
        $qrBytes = '';
    } else {
        $qrBytes = build_qr_png_bytes($pdo, $userRow, 512);
        if ($qrBytes === '') {
            $qrBulkFailed = true;
        }
    }
    $hasZipArchive = class_exists('ZipArchive');
    if ($hasZipArchive) {
        $tmp = ctr_temp_file('ctr_id_');
        if (!is_string($tmp) || $tmp === '') {
            return '';
        }
        copy($templatePath, $tmp);

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            return '';
        }
        $docXml = $zip->getFromName('word/document.xml');
        if (!is_string($docXml) || $docXml === '') {
            $zip->close();
            @unlink($tmp);
            return '';
        }

        $docXml = patch_docx_document_xml($docXml, $replacements, 'Downloaded', 'ID Template');
        $zip->addFromString('word/document.xml', $docXml);

        if ($qrBytes !== '') {
            if ($zip->locateName('word/media/image3.png') !== false) {
                $zip->addFromString('word/media/image3.png', $qrBytes);
            }
            if ($zip->locateName('word/media/image7.png') !== false) {
                $zip->addFromString('word/media/image7.png', $qrBytes);
            }
        }

        $zip->close();
        $bytes = @file_get_contents($tmp);
        @unlink($tmp);
        return is_string($bytes) ? $bytes : '';
    }

    $entries = zip_read_entries($templateBytes);
    if (!$entries || !isset($entries['word/document.xml'])) {
        return '';
    }
    $docXml = zip_entry_uncompressed($templateBytes, $entries['word/document.xml']);
    if ($docXml === '') {
        return '';
    }

    $docXml = patch_docx_document_xml($docXml, $replacements, 'Downloaded', 'ID Template');

    $newEntries = [];
    foreach ($entries as $name => $ent) {
        $method = (int)($ent['method'] ?? 8);
        $modTime = (int)($ent['mod_time'] ?? 0);
        $modDate = (int)($ent['mod_date'] ?? 0);
        if ($name === 'word/document.xml') {
            $data = $docXml;
            $dataComp = gzdeflate($data);
            $newEntries[] = [
                'name' => $name,
                'method' => 8,
                'mod_time' => $modTime,
                'mod_date' => $modDate,
                'crc' => zip_crc32u($data),
                'uncomp_size' => strlen($data),
                'comp_size' => strlen($dataComp),
                'data_comp' => $dataComp,
            ];
            continue;
        }

        if (($name === 'word/media/image3.png' || $name === 'word/media/image7.png') && $qrBytes !== '') {
            $data = $qrBytes;
            $newEntries[] = [
                'name' => $name,
                'method' => 0,
                'mod_time' => $modTime,
                'mod_date' => $modDate,
                'crc' => zip_crc32u($data),
                'uncomp_size' => strlen($data),
                'comp_size' => strlen($data),
                'data_comp' => $data,
            ];
            continue;
        }

        $dataComp = zip_entry_compressed_slice($templateBytes, $ent);
        if ($dataComp === '') {
            continue;
        }
        $newEntries[] = [
            'name' => $name,
            'method' => $method,
            'mod_time' => $modTime,
            'mod_date' => $modDate,
            'crc' => (int)($ent['crc'] ?? 0),
            'uncomp_size' => (int)($ent['uncomp_size'] ?? 0),
            'comp_size' => (int)($ent['comp_size'] ?? 0),
            'data_comp' => $dataComp,
        ];
    }

    return zip_build_archive($newEntries);
}

if (isset($_GET['download_id_template']) && (string)($_GET['download_id_template'] ?? '') === '1') {
    // Prevent PHP from killing the script during large bulk downloads.
    @set_time_limit(0);
    ignore_user_abort(true);

    // Close session and clear buffers EARLY so the script doesn't run out
    // of output-buffer memory during heavy batch processing (QR codes, docx,
    // zip building) and so other requests from the same user aren't blocked.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @ini_set('zlib.output_compression', '0');

    $templateKey = (string)($_GET['template'] ?? 'cos_jo');
    $templateKey = $templateKey === 'permanent' ? 'permanent' : 'cos_jo';
    $idsRaw = (string)($_GET['ids'] ?? '');
    $ids = [];
    foreach (preg_split('/[,\s]+/', $idsRaw) ?: [] as $p) {
        $id = (int)$p;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $ids = array_keys($ids);
    if (!$ids) {
        json_response(['ok' => false, 'message' => 'No ids provided.'], 400);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, username, name, id_number, position, department, qr_token FROM users WHERE id IN ($placeholders) ORDER BY id ASC");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        json_response(['ok' => false, 'message' => 'Not found.'], 404);
    }

    if (count($rows) === 1) {
        $u = $rows[0];
        $bytes = build_id_template_docx($pdo, $u, $templateKey);
        if ($bytes === '') {
            json_response(['ok' => false, 'message' => 'Failed to build template.'], 500);
        }
        $fullName = trim((string)($u['name'] ?? ''));
        [$lastName, $firstName] = split_name_last_first($fullName);
        $nameForFile = trim($firstName . ' ' . $lastName);
        if ($nameForFile === '') {
            $nameForFile = (string)($u['id_number'] ?? '');
        }
        if ($nameForFile === '') {
            $nameForFile = 'user_' . (string)((int)($u['id'] ?? 0));
        }
        $safe = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $nameForFile);
        $fn = 'id_template_' . $safe . '.docx';
        set_file_audit_cookie($pdo, 'id_template:' . $templateKey . ':' . (string)((int)($u['id'] ?? 0)), time(), (int)($_SESSION['user_id'] ?? 0));
        http_download_bytes('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $fn, $bytes);
    }

    if (class_exists('ZipArchive')) {
        $zipPath = ctr_temp_file('ctr_idzip_');
        if (!is_string($zipPath) || $zipPath === '') {
            json_response(['ok' => false, 'message' => 'Server error.'], 500);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            json_response(['ok' => false, 'message' => 'Server error.'], 500);
        }

        $qrBulkFailed = false;
        foreach ($rows as $u) {
            $bytes = build_id_template_docx($pdo, $u, $templateKey, $qrBulkFailed);
            if ($bytes === '') {
                continue;
            }
            $fullName = trim((string)($u['name'] ?? ''));
            [$lastName, $firstName] = split_name_last_first($fullName);
            $nameForFile = trim($firstName . ' ' . $lastName);
            if ($nameForFile === '') {
                $nameForFile = (string)($u['id_number'] ?? '');
            }
            if ($nameForFile === '') {
                $nameForFile = 'user_' . (string)((int)($u['id'] ?? 0));
            }
            $safe = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $nameForFile);
            $entryName = 'id_template_' . $safe . '.docx';
            $zip->addFromString($entryName, $bytes);
        }
        $zip->close();

        $fn = 'id_templates_' . date('Ymd_His') . '.zip';
        $idParts = [];
        foreach ($rows as $r) {
            $idParts[] = (string)((int)($r['id'] ?? 0));
        }
        set_file_audit_cookie($pdo, 'id_templates:' . $templateKey . ':' . implode(',', $idParts), time(), (int)($_SESSION['user_id'] ?? 0));
        http_download_file($zipPath, $fn, 'application/zip');
    }

    $qrBulkFailed = false;
    $bulkEntries = [];
    foreach ($rows as $u) {
        $bytes = build_id_template_docx($pdo, $u, $templateKey, $qrBulkFailed);
        if ($bytes === '') {
            continue;
        }
        $fullName = trim((string)($u['name'] ?? ''));
        [$lastName, $firstName] = split_name_last_first($fullName);
        $nameForFile = trim($firstName . ' ' . $lastName);
        if ($nameForFile === '') {
            $nameForFile = (string)($u['id_number'] ?? '');
        }
        if ($nameForFile === '') {
            $nameForFile = 'user_' . (string)((int)($u['id'] ?? 0));
        }
        $safe = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $nameForFile);
        $entryName = 'id_template_' . $safe . '.docx';
        $bulkEntries[] = [
            'name' => $entryName,
            'method' => 8,
            'data' => $bytes,
        ];
    }
    $outZip = zip_build_archive($bulkEntries);
    if ($outZip === '') {
        json_response(['ok' => false, 'message' => 'Failed to build archive.'], 500);
    }
    $fn = 'id_templates_' . date('Ymd_His') . '.zip';
    $idParts = [];
    foreach ($rows as $r) {
        $idParts[] = (string)((int)($r['id'] ?? 0));
    }
    set_file_audit_cookie($pdo, 'id_templates:' . $templateKey . ':' . implode(',', $idParts), time(), (int)($_SESSION['user_id'] ?? 0));

    // Write to temp file and stream from disk to avoid loading entire zip in memory.
    $zipTmpFile = ctr_temp_file('ctr_idzip_fb_');
    if (is_string($zipTmpFile) && $zipTmpFile !== '') {
        @file_put_contents($zipTmpFile, $outZip);
        http_download_file($zipTmpFile, $fn, 'application/zip');
    }
    http_download_bytes('application/zip', $fn, $outZip);
}

if (isset($_GET['download_user_template']) && (string)($_GET['download_user_template'] ?? '') === '1') {
    @set_time_limit(0);
    ignore_user_abort(true);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @ini_set('zlib.output_compression', '0');

    $sessionRole = (string)($_SESSION['role'] ?? '');
    $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($sessionRole !== 'admin' && $sessionRole !== 'superadmin') {
        if (!headers_sent()) { http_response_code(403); }
        echo 'Forbidden.';
        exit;
    }

    $lockedDepartment = '';
    $deptUnlocked = false;
    if ($sessionRole === 'admin' && $sessionUserId > 0) {
        try {
            $stDept = $pdo->prepare("SELECT department, admin_department_unlocked FROM users WHERE id = :id LIMIT 1");
            $stDept->execute([':id' => $sessionUserId]);
            $row = $stDept->fetch(PDO::FETCH_ASSOC) ?: null;
            $lockedDepartment = trim((string)($row['department'] ?? ''));
            $deptUnlocked = (int)($row['admin_department_unlocked'] ?? 0) === 1;
        } catch (Throwable $e) {
            $lockedDepartment = '';
            $deptUnlocked = false;
        }
        if (!$deptUnlocked && $lockedDepartment === '') {
            if (!headers_sent()) { http_response_code(400); }
            echo 'Your account has no department assigned.';
            exit;
        }
    }

    $xlsxBytes = build_user_template_xlsx($deptUnlocked ? '' : $lockedDepartment);
    if (!is_string($xlsxBytes) || $xlsxBytes === '') {
        if (!headers_sent()) { http_response_code(500); }
        echo 'Failed to build template.';
        exit;
    }
    $fn = 'user_template_' . date('Ymd_His') . '.xlsx';
    set_file_audit_cookie($pdo, 'user_template:' . $fn, time(), (int)($_SESSION['user_id'] ?? 0));
    http_download_bytes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $fn, $xlsxBytes);
}

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1') {
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
    $reqMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    // Cache profile & ETag headers for read-only AJAX endpoints.
    $readOnlyAjax = [
        'list_users', 'list_admins', 'get_user', 'get_admin', 'get_identity',
        'get_my_plan_usage', 'get_my_home_link', 'get_my_identity', 'list_user_options',
    ];
    if ($reqMethod === 'GET' && in_array($action, $readOnlyAjax, true)) {
        ctr_cache_headers('api', 20);
    }

    $isPlanExpired = !empty($_SESSION['plan_expired']);
    $isPlanRelated = in_array($action, ['get_my_plan_usage', 'get_my_home_link', 'get_my_identity'], true);

    if ($isPlanExpired && $role === 'admin' && !$isPlanRelated) {
        json_response(['ok' => true, 'users' => []]);
    }

    // CSRF: every session-authenticated state change must carry a valid
    // token. Kiosk-token-authorized requests are already protected by the
    // kiosk token secret, which the attacker cannot read cross-origin.
    if (!$kioskMode && $reqMethod === 'POST' && !$kioskTokenValid) {
        ctr_csrf_check();
    }

    try {
        if ($action === 'upload_access_file') {
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            $f = $_FILES['access_file'] ?? $_FILES['file'] ?? null;
            if (!is_array($f)) {
                json_response(['ok' => false, 'message' => 'Missing file.'], 400);
            }
            $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                json_response(['ok' => false, 'message' => 'Upload failed.'], 400);
            }

            $orig = (string)($f['name'] ?? '');
            $origBase = basename($orig);
            $ext = strtolower((string)pathinfo($origBase, PATHINFO_EXTENSION));
            if ($ext !== 'mdb' && $ext !== 'accdb') {
                json_response(['ok' => false, 'message' => 'Invalid file type.'], 400);
            }

            $uploadTs = time();
            $fileId = bin2hex(random_bytes(8));
            $safeOrig = preg_replace('/[^\w.\-]+/', '_', $origBase) ?: ('access.' . $ext);
            $subdir = date('Ymd');
            $targetDir = __DIR__ . '/uploads/access/' . $subdir;
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true)) {
                json_response(['ok' => false, 'message' => 'Server error.'], 500);
            }
            $destName = $fileId . '_' . $uploadTs . '_' . $safeOrig;
            $destPath = $targetDir . DIRECTORY_SEPARATOR . $destName;

            $tmp = (string)($f['tmp_name'] ?? '');
            $moved = $tmp !== '' ? @move_uploaded_file($tmp, $destPath) : false;
            if (!$moved && defined('CTR_TESTING_ALLOW_MOVE') && PHP_SAPI === 'cli' && $tmp !== '' && is_file($tmp)) {
                $moved = @rename($tmp, $destPath);
                if (!$moved) {
                    $moved = @copy($tmp, $destPath);
                    if ($moved) {
                        @unlink($tmp);
                    }
                }
            }
            if (!$moved || !is_file($destPath)) {
                json_response(['ok' => false, 'message' => 'Upload failed.'], 500);
            }

            set_file_audit_cookie($pdo, $fileId, $uploadTs, (int)($_SESSION['user_id'] ?? 0));
            json_response([
                'ok' => true,
                'file_id' => $fileId,
                'upload_ts' => $uploadTs,
                'rel_path' => $subdir . '/' . $destName,
            ]);
        }

        if ($action === 'get_my_profile') {
            if ($role !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $id = (int)($_SESSION['user_id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid session.'], 401);
            }
            $stmt = $pdo->prepare("SELECT id, username, name, id_number, role, qr_token, created_at, updated_at FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                json_response(['ok' => false, 'message' => 'Not found.'], 404);
            }
            $u['qr_payload'] = compute_qr_payload($pdo, $u);
            json_response(['ok' => true, 'user' => $u]);
        }

        if ($action === 'change_my_password') {
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            if ($role !== 'admin' && $role !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $id = (int)($_SESSION['user_id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid session.'], 401);
            }
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            if (trim($currentPassword) === '' || trim($newPassword) === '' || trim($confirmPassword) === '') {
                json_response(['ok' => false, 'message' => 'All password fields are required.'], 400);
            }
            if ($newPassword !== $confirmPassword) {
                json_response(['ok' => false, 'message' => 'New password and confirm password do not match.'], 400);
            }
            if (mb_strlen($newPassword, 'UTF-8') < 8) {
                json_response(['ok' => false, 'message' => 'New password must be at least 8 characters.'], 400);
            }
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $hash = (string)($stmt->fetchColumn() ?? '');
            if ($hash === '' || !password_verify($currentPassword, $hash)) {
                json_response(['ok' => false, 'message' => 'Current password is incorrect.'], 400);
            }
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = :p WHERE id = :id");
            $stmt->execute([':p' => $newHash, ':id' => $id]);
            json_response(['ok' => true]);
        }

        if ($action === 'get_my_plan_usage') {
            if ($role !== 'admin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $id = (int)($_SESSION['user_id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid session.'], 401);
            }
            $stmt = $pdo->prepare("SELECT id, role, is_active, plan_months, plan_expires_at, is_free_trial FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                json_response(['ok' => false, 'message' => 'Not found.'], 404);
            }
            if ((string)($u['role'] ?? '') !== 'admin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE created_by = :id");
            $stmt->execute([':id' => $id]);
            $createdCount = (int)($stmt->fetchColumn() ?? 0);

            $tz = new DateTimeZone('Asia/Manila');
            $now = new DateTimeImmutable('now', $tz);

            $planExpiresRaw = $u['plan_expires_at'] ?? null;
            $planExpiresAt = is_string($planExpiresRaw) ? trim($planExpiresRaw) : '';
            $hasPlan = $planExpiresAt !== '';

            $planMonths = (int)($u['plan_months'] ?? 0);
            if ($planMonths < 0) $planMonths = 0;
            if ($planMonths > 60) $planMonths = 60;
            if ($hasPlan && $planMonths <= 0) $planMonths = 1;

            $expired = false;
            $startedAt = null;
            $daysTotal = 0;
            $daysUsed = 0;
            $daysRemaining = 0;
            $percentUsed = 0;

            if ($hasPlan) {
                try {
                    $exp = new DateTimeImmutable($planExpiresAt, $tz);
                    $startedAt = $exp->modify('-' . $planMonths . ' month');
                    $expired = $exp <= $now;

                    $startTs = $startedAt->getTimestamp();
                    $expTs = $exp->getTimestamp();
                    $nowTs = $now->getTimestamp();

                    $totalSec = max(1, $expTs - $startTs);
                    $usedSec = max(0, min($totalSec, $nowTs - $startTs));
                    $remSec = max(0, $expTs - $nowTs);

                    $daysTotal = (int)ceil($totalSec / 86400);
                    $daysUsed = (int)floor($usedSec / 86400);
                    $daysRemaining = (int)ceil($remSec / 86400);

                    $percentUsed = (int)round(($usedSec / $totalSec) * 100);
                    if ($percentUsed < 0) $percentUsed = 0;
                    if ($percentUsed > 100) $percentUsed = 100;
                } catch (Throwable $e) {
                    $hasPlan = false;
                    $planExpiresAt = '';
                    $startedAt = null;
                    $expired = false;
                    $daysTotal = 0;
                    $daysUsed = 0;
                    $daysRemaining = 0;
                    $percentUsed = 0;
                }
            }

            $isFreeTrial = ((int)($u['is_free_trial'] ?? 0) === 1) && $hasPlan && !$expired;

            json_response([
                'ok' => true,
                'usage' => [
                    'is_active' => (int)($u['is_active'] ?? 0) === 1,
                    'has_plan' => $hasPlan,
                    'plan_months' => $planMonths,
                    'plan_started_at' => $startedAt ? $startedAt->format('Y-m-d H:i:s') : null,
                    'plan_expires_at' => $hasPlan ? $planExpiresAt : null,
                    'expired' => $expired,
                    'is_free_trial' => $isFreeTrial,
                    'days_total' => $daysTotal,
                    'days_used' => $daysUsed,
                    'days_remaining' => $daysRemaining,
                    'percent_used' => $percentUsed,
                    'created_users_count' => $createdCount,
                ],
            ]);
        }

        if ($action === 'get_my_home_link') {
            if ($role !== 'admin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $id = (int)($_SESSION['user_id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid session.'], 401);
            }

            $stmt = $pdo->prepare("SELECT id, role, is_active, plan_expires_at, is_free_trial FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                json_response(['ok' => false, 'message' => 'Not found.'], 404);
            }
            if ((string)($u['role'] ?? '') !== 'admin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            if ((int)($u['is_active'] ?? 0) !== 1) {
                json_response(['ok' => false, 'message' => 'Account is inactive.'], 403);
            }

            $planExpiresAt = trim((string)($u['plan_expires_at'] ?? ''));
            if ($planExpiresAt === '') {
                json_response(['ok' => false, 'message' => 'No plan is assigned yet.'], 400);
            }
            $tz = new DateTimeZone('Asia/Manila');
            $now = new DateTimeImmutable('now', $tz);
            try {
                $exp = new DateTimeImmutable($planExpiresAt, $tz);
                if ($exp <= $now) {
                    json_response(['ok' => false, 'message' => 'Your plan has expired.'], 403);
                }
            } catch (Throwable $e) {
                json_response(['ok' => false, 'message' => 'Invalid plan expiry date.'], 500);
            }

            $row = get_or_issue_active_admin_home_link($pdo, $id, $planExpiresAt);
            $url = build_admin_home_link_url((string)($row['token'] ?? ''));
            $deviceId = trim((string)($row['device_id'] ?? ''));

            json_response([
                'ok' => true,
                'link' => [
                    'url' => $url,
                    'expires_at' => $planExpiresAt,
                    'is_free_trial' => (int)($u['is_free_trial'] ?? 0) === 1,
                    'device_bound' => $deviceId !== '',
                    'device_bound_at' => $row['device_bound_at'] ?? null,
                ],
            ]);
        }

        if ($action === 'get_my_home_links') {
            if ($role !== 'admin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $id = (int)($_SESSION['user_id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid session.'], 401);
            }

            $stmt = $pdo->prepare("SELECT id, role, is_active, plan_expires_at, is_free_trial FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                json_response(['ok' => false, 'message' => 'Not found.'], 404);
            }
            if ((string)($u['role'] ?? '') !== 'admin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            if ((int)($u['is_active'] ?? 0) !== 1) {
                json_response(['ok' => false, 'message' => 'Account is inactive.'], 403);
            }

            $planExpiresAt = trim((string)($u['plan_expires_at'] ?? ''));
            if ($planExpiresAt === '') {
                json_response(['ok' => false, 'message' => 'No plan is assigned yet.'], 400);
            }
            $tz = new DateTimeZone('Asia/Manila');
            $now = new DateTimeImmutable('now', $tz);
            try {
                $exp = new DateTimeImmutable($planExpiresAt, $tz);
                if ($exp <= $now) {
                    json_response(['ok' => false, 'message' => 'Your plan has expired.'], 403);
                }
            } catch (Throwable $e) {
                json_response(['ok' => false, 'message' => 'Invalid plan expiry date.'], 500);
            }

            $links = list_active_admin_home_links($pdo, $id);
            $out = [];
            foreach ($links as $lnk) {
                $tok = (string)($lnk['token'] ?? '');
                $out[] = [
                    'id' => (int)($lnk['id'] ?? 0),
                    'url' => build_admin_home_link_url($tok),
                    'device_bound' => trim((string)($lnk['device_id'] ?? '')) !== '',
                    'device_bound_at' => $lnk['device_bound_at'] ?? null,
                    'last_seen_at' => $lnk['last_seen_at'] ?? null,
                ];
            }

            json_response([
                'ok' => true,
                'links' => $out,
                'count' => count($out),
                'max' => 5,
                'is_free_trial' => (int)($u['is_free_trial'] ?? 0) === 1,
                'expires_at' => $planExpiresAt,
            ]);
        }

        if ($action === 'revoke_my_home_link') {
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            if ($role !== 'admin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $id = (int)($_SESSION['user_id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid session.'], 401);
            }
            $linkId = (int)($_POST['link_id'] ?? 0);
            if ($linkId <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid link.'], 400);
            }
            $ok = revoke_admin_home_link_by_id($pdo, $id, $linkId);
            json_response(['ok' => $ok, 'message' => $ok ? 'Link revoked.' : 'Link not found.']);
        }

        if ($action === 'issue_my_home_link') {
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            if ($role !== 'admin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $id = (int)($_SESSION['user_id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid session.'], 401);
            }

            $stmt = $pdo->prepare("SELECT id, role, is_active, plan_expires_at FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u || (string)($u['role'] ?? '') !== 'admin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            if ((int)($u['is_active'] ?? 0) !== 1) {
                json_response(['ok' => false, 'message' => 'Account is inactive.'], 403);
            }
            $planExpiresAt = trim((string)($u['plan_expires_at'] ?? ''));
            if ($planExpiresAt === '') {
                json_response(['ok' => false, 'message' => 'No plan assigned.'], 400);
            }
            $tz = new DateTimeZone('Asia/Manila');
            $now = new DateTimeImmutable('now', $tz);
            try {
                $exp = new DateTimeImmutable($planExpiresAt, $tz);
                if ($exp <= $now) {
                    json_response(['ok' => false, 'message' => 'Your plan has expired.'], 403);
                }
            } catch (Throwable $e) {
                json_response(['ok' => false, 'message' => 'Invalid plan expiry date.'], 500);
            }

            $activeCount = count_active_admin_home_links($pdo, $id);
            if ($activeCount >= 5) {
                json_response(['ok' => false, 'message' => 'Maximum 5 device links reached. Revoke one first.'], 400);
            }

            $issued = issue_admin_home_link($pdo, $id, $planExpiresAt);
            json_response([
                'ok' => true,
                'link' => [
                    'id' => (int)($issued['row_id'] ?? 0),
                    'url' => build_admin_home_link_url((string)($issued['token'] ?? '')),
                ],
            ]);
        }

        // Superadmin-only home-link management: view, revoke, or reset the
        // home links that admins use on their topbar "My home link" modal.
        if ($action === 'list_admin_home_links') {
            if ($role !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $adminId = (int)($_GET['admin_id'] ?? 0);
            if ($adminId <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid admin id.'], 400);
            }
            $stmt = $pdo->prepare("SELECT id, username, name FROM users WHERE id = :id AND role = 'admin' LIMIT 1");
            $stmt->execute([':id' => $adminId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                json_response(['ok' => false, 'message' => 'Admin not found.'], 404);
            }

            $links = list_active_admin_home_links($pdo, $adminId);
            $out = [];
            foreach ($links as $lnk) {
                $tok = (string)($lnk['token'] ?? '');
                $out[] = [
                    'id' => (int)($lnk['id'] ?? 0),
                    'url' => build_admin_home_link_url($tok),
                    'device_bound' => trim((string)($lnk['device_id'] ?? '')) !== '',
                    'device_bound_at' => $lnk['device_bound_at'] ?? null,
                    'last_seen_at' => $lnk['last_seen_at'] ?? null,
                ];
            }
            json_response([
                'ok' => true,
                'admin' => [
                    'id' => (int)$u['id'],
                    'username' => (string)($u['username'] ?? ''),
                    'name' => (string)($u['name'] ?? ''),
                ],
                'links' => $out,
                'count' => count($out),
                'max' => 5,
            ]);
        }

        if ($action === 'revoke_admin_home_link') {
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            if ($role !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $adminId = (int)($_POST['admin_id'] ?? 0);
            $linkId = (int)($_POST['link_id'] ?? 0);
            if ($adminId <= 0 || $linkId <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid parameters.'], 400);
            }
            $ok = revoke_admin_home_link_by_id($pdo, $adminId, $linkId);
            json_response(['ok' => $ok, 'message' => $ok ? 'Link revoked.' : 'Link not found.']);
        }

        if ($action === 'reset_admin_home_links') {
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            if ($role !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $adminId = (int)($_POST['admin_id'] ?? 0);
            if ($adminId <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid admin id.'], 400);
            }
            revoke_admin_home_links($pdo, $adminId);
            json_response(['ok' => true, 'message' => 'All home links for this admin have been reset.']);
        }

        if ($action === 'get_my_identity') {
            $id = (int)($_SESSION['user_id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid session.'], 401);
            }
            $stmt = $pdo->prepare("SELECT id, username, name, id_number, role, position, department, admin_department_unlocked FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                json_response(['ok' => false, 'message' => 'Not found.'], 404);
            }
            json_response(['ok' => true, 'user' => $u]);
        }

        if ($action === 'list_users') {
            if ($role !== 'superadmin') {
                $currentUserId = (int)($_SESSION['user_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT id, username, name, id_number, role, position, department, admin_department_unlocked, is_active, plan_months, plan_expires_at, qr_token, is_free_trial, (face_data IS NOT NULL AND face_data != '') AS has_face_data, (CASE WHEN face_data IS NULL OR face_data = '' THEN '' WHEN face_data LIKE '%\"emb\"%' THEN 'embed' ELSE 'legacy' END) AS face_kind, created_at, updated_at FROM users WHERE created_by = :created_by OR id = :self_id ORDER BY id DESC");
                $stmt->execute([':created_by' => $currentUserId, ':self_id' => $currentUserId]);
            } else {
                try { ensure_admin_home_links_schema_safe($pdo); } catch (Throwable $e) {}
                $stmt = $pdo->query("SELECT u.id, u.username, u.name, u.id_number, u.role, u.position, u.department, u.admin_department_unlocked, u.is_active, u.plan_months, u.plan_expires_at, u.qr_token, u.is_free_trial, (u.face_data IS NOT NULL AND u.face_data != '') AS has_face_data, (CASE WHEN u.face_data IS NULL OR u.face_data = '' THEN '' WHEN u.face_data LIKE '%\"emb\"%' THEN 'embed' ELSE 'legacy' END) AS face_kind, u.created_at, u.updated_at, (SELECT COUNT(*) FROM users x WHERE x.created_by = u.id) AS created_users_count, (SELECT COUNT(*) FROM admin_home_links l WHERE l.admin_id = u.id AND l.revoked_at IS NULL) AS home_links_count FROM users u WHERE u.role = 'admin' ORDER BY u.id DESC");
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['qr_payload'] = compute_qr_payload($pdo, $r);
            }
            $etag = ctr_etag_from(['b' => floor(time() / 30), 'uid' => (int)($_SESSION['user_id'] ?? 0), 'role' => $role, 'rows' => $rows]);
            ctr_handle_conditional($etag, 20);
            json_response(['ok' => true, 'users' => $rows]);
        }

        if ($action === 'list_users_for_admin') {
            if ($role !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $adminId = (int)($_GET['admin_id'] ?? 0);
            if ($adminId <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid admin id.'], 400);
            }
            $stmt = $pdo->prepare("SELECT id, username, name, id_number, role, position, department, is_active, plan_months, plan_expires_at, qr_token, is_free_trial, (face_data IS NOT NULL AND face_data != '') AS has_face_data, (CASE WHEN face_data IS NULL OR face_data = '' THEN '' WHEN face_data LIKE '%\"emb\"%' THEN 'embed' ELSE 'legacy' END) AS face_kind, created_by, created_at, updated_at FROM users WHERE created_by = :aid ORDER BY id DESC");
            $stmt->execute([':aid' => $adminId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['qr_payload'] = compute_qr_payload($pdo, $r);
            }
            $etag = ctr_etag_from(['b' => floor(time() / 30), 'aid' => $adminId, 'rows' => $rows]);
            ctr_handle_conditional($etag, 20);
            json_response(['ok' => true, 'users' => $rows]);
        }

        if ($action === 'bulk_create_users') {
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            $sessionRole = (string)($_SESSION['role'] ?? '');
            $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
            if ($sessionRole !== 'admin' && $sessionRole !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }

            $f = $_FILES['file'] ?? null;
            if (!is_array($f)) {
                json_response(['ok' => false, 'message' => 'No file uploaded.'], 400);
            }
            $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) {
                $errMsg = 'Upload failed.';
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                    $errMsg = 'File too large.';
                } else if ($err === UPLOAD_ERR_PARTIAL) {
                    $errMsg = 'File upload was interrupted.';
                } else if ($err === UPLOAD_ERR_NO_FILE) {
                    $errMsg = 'No file was uploaded.';
                }
                json_response(['ok' => false, 'message' => $errMsg], 400);
            }
            $tmpPath = (string)($f['tmp_name'] ?? '');
            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                json_response(['ok' => false, 'message' => 'Invalid upload.'], 400);
            }
            $origName = (string)($f['name'] ?? '');
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if ($ext === '') {
                $tmpName = (string)($f['tmp_name'] ?? '');
                $ext = strtolower(pathinfo($tmpName, PATHINFO_EXTENSION));
            }
            if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                json_response(['ok' => false, 'message' => 'Unsupported file type. Use .xlsx, .xls, or .csv.'], 400);
            }

            $parsed = parse_user_template_upload($tmpPath, $ext);
            if (!is_array($parsed) || empty($parsed['ok'])) {
                $msg = is_array($parsed) ? (string)($parsed['message'] ?? 'Failed to parse file.') : 'Failed to parse file.';
                json_response(['ok' => false, 'message' => $msg], 400);
            }
            $allRows = is_array($parsed['rows'] ?? null) ? $parsed['rows'] : [];
            if (count($allRows) === 0) {
                json_response(['ok' => false, 'message' => 'The file is empty.'], 400);
            }

            $lockedDepartment = '';
            $deptUnlocked = false;
            if ($sessionRole === 'admin' && $sessionUserId > 0) {
                try {
                    $stDept = $pdo->prepare("SELECT department, admin_department_unlocked FROM users WHERE id = :id LIMIT 1");
                    $stDept->execute([':id' => $sessionUserId]);
                    $row = $stDept->fetch(PDO::FETCH_ASSOC) ?: null;
                    $lockedDepartment = trim((string)($row['department'] ?? ''));
                    $deptUnlocked = (int)($row['admin_department_unlocked'] ?? 0) === 1;
                } catch (Throwable $e) {
                    $lockedDepartment = '';
                    $deptUnlocked = false;
                }
                if (!$deptUnlocked && $lockedDepartment === '') {
                    json_response(['ok' => false, 'message' => 'Your account has no department assigned. Please contact the administrator.'], 400);
                }
            }

            $headerAliases = [
                'username' => 'username', 'user name' => 'username', 'user_name' => 'username',
                'id number' => 'id_number', 'id_number' => 'id_number', 'idnumber' => 'id_number', 'id' => 'id_number', 'employee id' => 'id_number',
                'real name' => 'name', 'real_name' => 'name', 'name' => 'name', 'full name' => 'name', 'fullname' => 'name',
                'job title' => 'position', 'job_title' => 'position', 'jobtitle' => 'position', 'position' => 'position', 'job' => 'position',
                'department' => 'department', 'dept' => 'department',
            ];

            $firstRow = $allRows[0];
            $firstRowLower = array_map(function ($v) { return strtolower(trim((string)$v)); }, $firstRow);
            $looksLikeHeader = false;
            foreach ($firstRowLower as $cell) {
                if (isset($headerAliases[$cell])) { $looksLikeHeader = true; break; }
            }
            $dataStart = 0;
            $colMap = ['username' => 0, 'id_number' => 1, 'name' => 2, 'position' => 3, 'department' => 4];
            if ($looksLikeHeader) {
                $dataStart = 1;
                foreach ($firstRowLower as $idx => $label) {
                    if (isset($headerAliases[$label])) {
                        $colMap[$headerAliases[$label]] = (int)$idx;
                    }
                }
            }

            $created = 0;
            $failed = 0;
            $errors = [];
            $createdAccounts = [];

            $pdo->beginTransaction();
            try {
                for ($i = $dataStart; $i < count($allRows); $i++) {
                    $row = $allRows[$i];
                    $rowNum = $i + 1;

                    $usernameIdx = $colMap['username'];
                    $idNumberIdx = $colMap['id_number'];
                    $nameIdx = $colMap['name'];
                    $positionIdx = $colMap['position'];
                    $deptIdx = $colMap['department'];

                    $username = (isset($row[$usernameIdx]) && $row[$usernameIdx] !== null) ? trim((string)$row[$usernameIdx]) : '';
                    $idNumber = (isset($row[$idNumberIdx]) && $row[$idNumberIdx] !== null) ? trim((string)$row[$idNumberIdx]) : '';
                    $name = (isset($row[$nameIdx]) && $row[$nameIdx] !== null) ? trim((string)$row[$nameIdx]) : '';
                    $position = (isset($row[$positionIdx]) && $row[$positionIdx] !== null) ? trim((string)$row[$positionIdx]) : '';
                    $deptRaw = (isset($row[$deptIdx]) && $row[$deptIdx] !== null) ? trim((string)$row[$deptIdx]) : '';

                    if ($username === '' && $idNumber === '' && $name === '' && $position === '' && $deptRaw === '') {
                        continue;
                    }

                    $department = ($sessionRole === 'admin' && !$deptUnlocked) ? $lockedDepartment : $deptRaw;

                    $missing = [];
                    if ($username === '') $missing[] = 'Username';
                    if ($name === '') $missing[] = 'Real Name';
                    if ($idNumber === '') $missing[] = 'ID Number';
                    if ($position === '') $missing[] = 'Job title';
                    if ($department === '') $missing[] = 'Department';
                    if ($missing) {
                        $failed++;
                        $errors[] = ['row' => $rowNum, 'message' => 'Missing: ' . implode(', ', $missing)];
                        continue;
                    }

                    $stmtU = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
                    $stmtU->execute([':u' => $username]);
                    if (((int)$stmtU->fetchColumn()) > 0) {
                        $failed++;
                        $errors[] = ['row' => $rowNum, 'message' => 'Username already taken: ' . $username];
                        continue;
                    }
                    $stmtN = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id_number = :n");
                    $stmtN->execute([':n' => $idNumber]);
                    if (((int)$stmtN->fetchColumn()) > 0) {
                        $failed++;
                        $errors[] = ['row' => $rowNum, 'message' => 'ID Number already taken: ' . $idNumber];
                        continue;
                    }

                    $generatedPassword = bin2hex(random_bytes(4));
                    $hash = password_hash($generatedPassword, PASSWORD_DEFAULT);
                    $token = bin2hex(random_bytes(16));
                    $createdBy = $sessionUserId;
                    $roleDb = 'user';
                    $isActiveDb = 1;

                    $ins = $pdo->prepare("INSERT INTO users (id_number, name, position, department, username, password, role, is_active, plan_months, plan_expires_at, qr_token, created_by, is_free_trial) VALUES (:id_number, :name, :position, :department, :username, :password, :role, :is_active, :plan_months, :plan_expires_at, :qr_token, :created_by, 0)");
                    $ins->execute([
                        ':id_number' => $idNumber,
                        ':name' => $name,
                        ':position' => $position,
                        ':department' => $department,
                        ':username' => $username,
                        ':password' => $hash,
                        ':role' => $roleDb,
                        ':is_active' => $isActiveDb,
                        ':plan_months' => null,
                        ':plan_expires_at' => null,
                        ':qr_token' => $token,
                        ':created_by' => $createdBy,
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    $created++;
                    $createdAccounts[] = [
                        'id' => $newId,
                        'username' => $username,
                        'name' => $name,
                        'id_number' => $idNumber,
                        'department' => $department,
                        'position' => $position,
                        'default_password' => $generatedPassword,
                    ];
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                json_response(['ok' => false, 'message' => 'Database error while creating users.'], 500);
            }

            set_file_audit_cookie($pdo, 'bulk_create_users:' . $origName . ':created=' . $created . ':failed=' . $failed, time(), (int)($_SESSION['user_id'] ?? 0));

            json_response([
                'ok' => true,
                'created' => $created,
                'failed' => $failed,
                'errors' => $errors,
                'accounts' => $createdAccounts,
                'locked_department' => ($sessionRole === 'admin' && !$deptUnlocked) ? $lockedDepartment : '',
                'admin_department_unlocked' => $deptUnlocked,
            ]);
        }

        if ($action === 'validate') {
            $username = trim((string)($_GET['username'] ?? ''));
            $idNumber = trim((string)($_GET['id_number'] ?? ''));
            $excludeId = (int)($_GET['exclude_id'] ?? 0);

            $usernameAvailable = null;
            $idNumberAvailable = null;

            if ($username !== '') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u AND id <> :id");
                $stmt->execute([':u' => $username, ':id' => $excludeId]);
                $usernameAvailable = ((int)$stmt->fetchColumn()) === 0;
            }

            if ($idNumber !== '') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id_number = :n AND id <> :id");
                $stmt->execute([':n' => $idNumber, ':id' => $excludeId]);
                $idNumberAvailable = ((int)$stmt->fetchColumn()) === 0;
            }

            json_response([
                'ok' => true,
                'username_available' => $usernameAvailable,
                'id_number_available' => $idNumberAvailable,
            ]);
        }

        if ($action === 'create_user') {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            $idNumber = trim((string)($_POST['id_number'] ?? ''));
            $role = trim((string)($_POST['role'] ?? 'user'));
            $sessionRole = (string)($_SESSION['role'] ?? '');
            if ($sessionRole !== 'superadmin') $role = 'user';
            if ($role === 'superadmin') $role = 'user';
            if ($sessionRole === 'superadmin' && $role !== 'admin') {
                json_response(['ok' => false, 'message' => 'Superadmin can only create admin accounts.'], 400);
            }
            $assignedAdminId = (int)($_POST['assigned_admin_id'] ?? 0);
            $position = trim((string)($_POST['position'] ?? ''));
            $department = trim((string)($_POST['department'] ?? ''));
            $isActive = isset($_POST['is_active']) ? (int)($_POST['is_active']) : 1;
            $planMonths = (int)($_POST['plan_months'] ?? 1);
            if ($planMonths < 0) $planMonths = 0;
            if ($planMonths > 60) $planMonths = 60;

            $sessionRoleLocal = (string)($_SESSION['role'] ?? '');
            $sessionUserIdLocal = (int)($_SESSION['user_id'] ?? 0);
            $lockedDepartment = '';
            if ($sessionRoleLocal === 'admin' && $sessionUserIdLocal > 0) {
                $deptUnlocked = false;
                try {
                    $stDept = $pdo->prepare("SELECT department, admin_department_unlocked FROM users WHERE id = :id LIMIT 1");
                    $stDept->execute([':id' => $sessionUserIdLocal]);
                    $row = $stDept->fetch(PDO::FETCH_ASSOC) ?: null;
                    $lockedDepartment = trim((string)($row['department'] ?? ''));
                    $deptUnlocked = (int)($row['admin_department_unlocked'] ?? 0) === 1;
                } catch (Throwable $e) {
                    $lockedDepartment = '';
                    $deptUnlocked = false;
                }
                if (!$deptUnlocked && $lockedDepartment === '') {
                    json_response(['ok' => false, 'message' => 'Your account has no department assigned. Please contact the administrator.'], 400);
                }
                if (!$deptUnlocked) {
                    $department = $lockedDepartment;
                }
            }

            $missing = [];
            if ($username === '') $missing[] = 'Username';
            if ($name === '') $missing[] = 'Real Name';
            if ($idNumber === '') $missing[] = 'ID Number';
            if ($position === '') $missing[] = 'Job title';
            if ($department === '') $missing[] = 'Department';
            if ($missing) {
                json_response(['ok' => false, 'message' => 'Missing: ' . implode(', ', $missing)], 400);
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
            $stmt->execute([':u' => $username]);
            if (((int)$stmt->fetchColumn()) > 0) {
                json_response(['ok' => false, 'message' => 'Username is already taken.'], 409);
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id_number = :n");
            $stmt->execute([':n' => $idNumber]);
            if (((int)$stmt->fetchColumn()) > 0) {
                json_response(['ok' => false, 'message' => 'ID Number is already taken.'], 409);
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(16));
            $createdBy = (int)($_SESSION['user_id'] ?? 0);
            if ((string)($_SESSION['role'] ?? '') === 'superadmin' && $role === 'user') {
                if ($assignedAdminId <= 0) {
                    json_response(['ok' => false, 'message' => 'Please select an admin.'], 400);
                }
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :id AND role = 'admin'");
                $stmt->execute([':id' => $assignedAdminId]);
                if (((int)$stmt->fetchColumn()) <= 0) {
                    json_response(['ok' => false, 'message' => 'Invalid admin.'], 400);
                }
                $createdBy = $assignedAdminId;
            }
            $planMonthsDb = null;
            $planExpiresAt = null;
            $isFreeTrial = 0;
            if ($role === 'admin') {
                if ($planMonths <= 0) {
                    $planMonthsDb = null;
                    $planExpiresAt = null;
                    $isActive = 0;
                } else {
                    $planMonthsDb = $planMonths;
                    if ((int)$isActive === 1 && (int)$planMonthsDb === 1) {
                        $isFreeTrial = 1;
                    }
                }
                if ($isActive && $planMonthsDb !== null) {
                    $tz = new DateTimeZone('Asia/Manila');
                    $now = new DateTimeImmutable('now', $tz);
                    $planExpiresAt = $now->modify('+' . $planMonthsDb . ' month')->format('Y-m-d H:i:s');
                }
            }
            $stmt = $pdo->prepare("INSERT INTO users (id_number, name, position, department, username, password, role, is_active, plan_months, plan_expires_at, qr_token, created_by, is_free_trial) VALUES (:id_number, :name, :position, :department, :username, :password, :role, :is_active, :plan_months, :plan_expires_at, :qr_token, :created_by, :is_free_trial)");
            $stmt->execute([
                ':id_number' => $idNumber,
                ':name' => $name,
                ':position' => $position,
                ':department' => $department,
                ':username' => $username,
                ':password' => $hash,
                ':role' => $role !== '' ? $role : 'user',
                ':is_active' => $isActive ? 1 : 0,
                ':plan_months' => $planMonthsDb,
                ':plan_expires_at' => $planExpiresAt,
                ':qr_token' => $token,
                ':created_by' => $createdBy,
                ':is_free_trial' => $isFreeTrial,
            ]);

            $newId = (int)$pdo->lastInsertId();
            if ($role === 'admin' && (int)$isActive === 1 && is_string($planExpiresAt) && trim($planExpiresAt) !== '') {
                try { issue_admin_home_link($pdo, $newId, (string)$planExpiresAt); } catch (Throwable $e) {}
            }
            json_response(['ok' => true, 'id' => $newId]);
        }

        if ($action === 'update_user') {
            $id = (int)($_POST['id'] ?? 0);
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            $idNumber = trim((string)($_POST['id_number'] ?? ''));
            $role = trim((string)($_POST['role'] ?? 'user'));
            if ((string)($_SESSION['role'] ?? '') !== 'superadmin') {
                if ($id !== (int)($_SESSION['user_id'] ?? 0)) {
                    $role = 'user';
                } else {
                    $role = (string)($_SESSION['role'] ?? 'user');
                }
            }
            if ($role === 'superadmin') $role = 'user';
            $assignedAdminId = (int)($_POST['assigned_admin_id'] ?? 0);
            $position = trim((string)($_POST['position'] ?? ''));
            $department = trim((string)($_POST['department'] ?? ''));
            $isActive = isset($_POST['is_active']) ? (int)($_POST['is_active']) : 1;
            $planMonths = (int)($_POST['plan_months'] ?? 1);
            if ($planMonths < 0) $planMonths = 0;
            if ($planMonths > 60) $planMonths = 60;

            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid user.'], 400);
            }
            $stmt = $pdo->prepare("SELECT is_active, role, plan_months, plan_expires_at, created_by FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$current) {
                json_response(['ok' => false, 'message' => 'Not found.'], 404);
            }
            $sessionRole = (string)($_SESSION['role'] ?? '');
            $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
            $createdBy = (int)($current['created_by'] ?? 0);
            if ($sessionRole !== 'superadmin') {
                if ($id !== $sessionUserId && $createdBy !== $sessionUserId) {
                    json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
                }
            }
            if ((string)($current['role'] ?? '') === 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }

            if ($sessionRole === 'admin' && $sessionUserId > 0 && $id !== $sessionUserId) {
                $lockedDept = '';
                $deptUnlocked = false;
                try {
                    $stDept = $pdo->prepare("SELECT department, admin_department_unlocked FROM users WHERE id = :aid LIMIT 1");
                    $stDept->execute([':aid' => $sessionUserId]);
                    $row = $stDept->fetch(PDO::FETCH_ASSOC) ?: null;
                    $lockedDept = trim((string)($row['department'] ?? ''));
                    $deptUnlocked = (int)($row['admin_department_unlocked'] ?? 0) === 1;
                } catch (Throwable $e) {
                    $lockedDept = '';
                    $deptUnlocked = false;
                }
                if (!$deptUnlocked && $lockedDept === '') {
                    json_response(['ok' => false, 'message' => 'Your account has no department assigned. Please contact the administrator.'], 400);
                }
                if (!$deptUnlocked) {
                    $department = $lockedDept;
                }
            }

            $wasActive = (int)($current['is_active'] ?? 0);
            $existingPlanExpires = $current['plan_expires_at'] ?? null;
            $existingPlanMonths = $current['plan_months'] ?? null;
            $missing = [];
            if ($username === '') $missing[] = 'Username';
            if ($name === '') $missing[] = 'Real Name';
            if ($idNumber === '') $missing[] = 'ID Number';
            if ($position === '') $missing[] = 'Job title';
            if ($position === '') $missing[] = 'Job title';
            if ($department === '') $missing[] = 'Department';
            if ($missing) {
                json_response(['ok' => false, 'message' => 'Missing: ' . implode(', ', $missing)], 400);
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u AND id <> :id");
            $stmt->execute([':u' => $username, ':id' => $id]);
            if (((int)$stmt->fetchColumn()) > 0) {
                json_response(['ok' => false, 'message' => 'Username is already taken.'], 409);
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id_number = :n AND id <> :id");
            $stmt->execute([':n' => $idNumber, ':id' => $id]);
            if (((int)$stmt->fetchColumn()) > 0) {
                json_response(['ok' => false, 'message' => 'ID Number is already taken.'], 409);
            }

            $planMonthsDb = null;
            $planExpiresAt = null;
            if ($role === 'admin') {
                if ($planMonths <= 0) {
                    $planMonthsDb = null;
                    $planExpiresAt = null;
                    $isActive = 0;
                } else {
                    $planMonthsDb = $planMonths;
                    $planExpiresAt = is_string($existingPlanExpires) ? $existingPlanExpires : null;
                    $needsPlan = false;
                    if ((int)$isActive === 1) {
                        if ($wasActive === 0) {
                            $needsPlan = true;
                        } else {
                            if ($planExpiresAt === null || trim((string)$planExpiresAt) === '') {
                                $needsPlan = true;
                            } else {
                                $tz = new DateTimeZone('Asia/Manila');
                                $now = new DateTimeImmutable('now', $tz);
                                try {
                                    $exp = new DateTimeImmutable((string)$planExpiresAt, $tz);
                                    if ($exp <= $now) $needsPlan = true;
                                } catch (Throwable $e) {
                                    $needsPlan = true;
                                }
                            }
                        }
                        if ($needsPlan) {
                            $tz = new DateTimeZone('Asia/Manila');
                            $now = new DateTimeImmutable('now', $tz);
                            $planExpiresAt = $now->modify('+' . $planMonthsDb . ' month')->format('Y-m-d H:i:s');
                        }
                    }
                }
            }
            if ($sessionRole !== 'superadmin') {
                $isActive = $wasActive;
                $planMonthsDb = $existingPlanMonths;
                $planExpiresAt = $existingPlanExpires;
            }

            $newCreatedBy = $createdBy;
            if ($sessionRole === 'superadmin') {
                if ($role === 'admin') {
                    $newCreatedBy = $sessionUserId;
                } else {
                    if ($assignedAdminId <= 0) {
                        json_response(['ok' => false, 'message' => 'Please select an admin.'], 400);
                    }
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :id AND role = 'admin'");
                    $stmt->execute([':id' => $assignedAdminId]);
                    if (((int)$stmt->fetchColumn()) <= 0) {
                        json_response(['ok' => false, 'message' => 'Invalid admin.'], 400);
                    }
                    $newCreatedBy = $assignedAdminId;
                }
            }

            if ($password !== '') {
            $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
                $isFreeTrialDb = ($role === 'admin' && (int)$planMonthsDb === 1 && (int)$isActive === 1) ? 1 : 0;
                $stmt = $pdo->prepare("UPDATE users SET id_number = :id_number, name = :name, position = :position, department = :department, username = :username, password = :password, role = :role, is_active = :is_active, plan_months = :plan_months, plan_expires_at = :plan_expires_at, created_by = :created_by, is_free_trial = :is_free_trial WHERE id = :id");
                $stmt->execute([
                    ':id_number' => $idNumber,
                    ':name' => $name,
                    ':position' => $position,
                    ':department' => $department,
                    ':username' => $username,
                    ':password' => $hash,
                    ':role' => $role !== '' ? $role : 'user',
                    ':is_active' => $isActive ? 1 : 0,
                    ':plan_months' => $planMonthsDb,
                    ':plan_expires_at' => $planExpiresAt,
                    ':created_by' => $newCreatedBy > 0 ? $newCreatedBy : null,
                    ':is_free_trial' => $isFreeTrialDb,
                    ':id' => $id,
                ]);
            }
            else {
                $isFreeTrialDb = ($role === 'admin' && (int)$planMonthsDb === 1 && (int)$isActive === 1) ? 1 : 0;
                $stmt = $pdo->prepare("UPDATE users SET id_number = :id_number, name = :name, position = :position, department = :department, username = :username, role = :role, is_active = :is_active, plan_months = :plan_months, plan_expires_at = :plan_expires_at, created_by = :created_by, is_free_trial = :is_free_trial WHERE id = :id");
                $stmt->execute([
                    ':id_number' => $idNumber,
                    ':name' => $name,
                    ':position' => $position,
                    ':department' => $department,
                    ':username' => $username,
                    ':role' => $role !== '' ? $role : 'user',
                    ':is_active' => $isActive ? 1 : 0,
                    ':plan_months' => $planMonthsDb,
                    ':plan_expires_at' => $planExpiresAt,
                    ':created_by' => $newCreatedBy > 0 ? $newCreatedBy : null,
                    ':is_free_trial' => $isFreeTrialDb,
                    ':id' => $id,
                ]);
            }

            if ($id === $sessionUserId) {
                $_SESSION['username'] = $username;
                $_SESSION['name'] = $name;
                $_SESSION['id_number'] = $idNumber;
                $_SESSION['role'] = $role !== '' ? $role : (string)($_SESSION['role'] ?? 'user');
                $_SESSION['position'] = $position;
                $_SESSION['department'] = $department;
            }

            if ($sessionRole === 'superadmin') {
                $prevRole = (string)($current['role'] ?? '');
                $prevExp = is_string($existingPlanExpires) ? trim($existingPlanExpires) : '';
                $newRole = (string)$role;
                $newExp = is_string($planExpiresAt) ? trim($planExpiresAt) : '';
                if ($prevRole === 'admin' && $newRole !== 'admin') {
                    try { revoke_admin_home_links($pdo, $id); } catch (Throwable $e) {}
                } else if ($newRole === 'admin') {
                    $becameActive = ((int)$wasActive === 0) && ((int)$isActive === 1);
                    $expChanged = $newExp !== '' && $newExp !== $prevExp;
                    if (($becameActive || $expChanged) && ($newExp !== '' || $prevExp !== '')) {
                        $targetExp = $newExp !== '' ? $newExp : $prevExp;
                        try { issue_admin_home_link($pdo, $id, $targetExp); } catch (Throwable $e) {}
                    }
                }
            }

            json_response(['ok' => true]);
        }

        if ($action === 'set_admin_plan') {
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            if ((string)($_SESSION['role'] ?? '') !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid user.'], 400);
            }

            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                json_response(['ok' => false, 'message' => 'Not found.'], 404);
            }
            if ((string)($u['role'] ?? '') !== 'admin') {
                json_response(['ok' => false, 'message' => 'Plan can only be set for admin accounts.'], 400);
            }

            $tz = new DateTimeZone('Asia/Manila');
            $now = new DateTimeImmutable('now', $tz);

            // Custom plan: exact start and expiration dates chosen by the superadmin
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $endDate = trim((string)($_POST['end_date'] ?? ''));
            if ($startDate !== '' || $endDate !== '') {
                if ($startDate === '' || $endDate === '') {
                    json_response(['ok' => false, 'message' => 'Both start and expiration dates are required for a custom plan.'], 400);
                }
                try {
                    $start = new DateTimeImmutable($startDate . ' 00:00:00', $tz);
                    $end = new DateTimeImmutable($endDate . ' 23:59:59', $tz);
                } catch (Throwable $e) {
                    json_response(['ok' => false, 'message' => 'Invalid date format.'], 400);
                }
                if ($end < $start) {
                    json_response(['ok' => false, 'message' => 'Expiration date must be on or after the start date.'], 400);
                }
                $months = ((int)$end->format('Y') - (int)$start->format('Y')) * 12
                    + ((int)$end->format('n') - (int)$start->format('n'));
                if ($months < 1) $months = 1;
                if ($months > 120) $months = 120;
                $planExpiresAt = $end->format('Y-m-d H:i:s');
                $stmt = $pdo->prepare("UPDATE users SET is_active = 1, plan_months = :m, plan_expires_at = :e, is_free_trial = 0 WHERE id = :id");
                $stmt->execute([':m' => $months, ':e' => $planExpiresAt, ':id' => $id]);
                try { issue_admin_home_link($pdo, $id, $planExpiresAt); } catch (Throwable $e) {}
                json_response(['ok' => true, 'plan_months' => $months, 'plan_expires_at' => $planExpiresAt]);
            }

            $planMonths = (int)($_POST['plan_months'] ?? 1);
            if ($planMonths < 0) $planMonths = 0;
            if ($planMonths > 60) $planMonths = 60;
            if ($planMonths <= 0) {
                $stmt = $pdo->prepare("UPDATE users SET is_active = 0, plan_months = NULL, plan_expires_at = NULL, is_free_trial = 0 WHERE id = :id");
                $stmt->execute([':id' => $id]);
                try { revoke_admin_home_links($pdo, $id); } catch (Throwable $e) {}
                json_response(['ok' => true, 'plan_months' => null, 'plan_expires_at' => null]);
            }

            $planExpiresAt = $now->modify('+' . $planMonths . ' month')->format('Y-m-d H:i:s');
            $stmt = $pdo->prepare("UPDATE users SET is_active = 1, plan_months = :m, plan_expires_at = :e, is_free_trial = 0 WHERE id = :id");
            $stmt->execute([':m' => $planMonths, ':e' => $planExpiresAt, ':id' => $id]);
            try { issue_admin_home_link($pdo, $id, $planExpiresAt); } catch (Throwable $e) {}
            json_response(['ok' => true, 'plan_months' => $planMonths, 'plan_expires_at' => $planExpiresAt]);
        }

        if ($action === 'toggle_active') {
            if ((string)($_SESSION['role'] ?? '') !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $id = (int)($_POST['id'] ?? 0);
            $isActive = (int)($_POST['is_active'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid user.'], 400);
            }
            $stmt = $pdo->prepare("SELECT role, plan_months, plan_expires_at FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                json_response(['ok' => false, 'message' => 'Not found.'], 404);
            }
            $planExpiresAt = $u['plan_expires_at'] ?? null;
            $planMonthsDb = $u['plan_months'] ?? null;
            if ($isActive && (string)($u['role'] ?? '') === 'admin') {
                $m = (int)($planMonthsDb ?? 0);
                if ($m <= 0) {
                    json_response(['ok' => false, 'message' => 'Please set a plan first.'], 400);
                }
                if ($m > 60) $m = 60;
                $shouldStart = false;
                if (!is_string($planExpiresAt) || trim($planExpiresAt) === '') {
                    $shouldStart = true;
                } else {
                    $tz = new DateTimeZone('Asia/Manila');
                    $now = new DateTimeImmutable('now', $tz);
                    try {
                        $exp = new DateTimeImmutable((string)$planExpiresAt, $tz);
                        if ($exp <= $now) $shouldStart = true;
                    } catch (Throwable $e) {
                        $shouldStart = true;
                    }
                }
                if ($shouldStart) {
                    $tz = new DateTimeZone('Asia/Manila');
                    $now = new DateTimeImmutable('now', $tz);
                    $planExpiresAt = $now->modify('+' . $m . ' month')->format('Y-m-d H:i:s');
                    $planMonthsDb = $m;
                }
            }

            $stmt = $pdo->prepare("UPDATE users SET is_active = :a, plan_months = :plan_months, plan_expires_at = :plan_expires_at WHERE id = :id");
            $stmt->execute([
                ':a' => $isActive ? 1 : 0,
                ':plan_months' => $planMonthsDb,
                ':plan_expires_at' => $planExpiresAt,
                ':id' => $id
            ]);
            if ((string)($u['role'] ?? '') === 'admin') {
                if ($isActive) {
                    $expStr = is_string($planExpiresAt) ? trim($planExpiresAt) : '';
                    if ($expStr !== '') {
                        try { issue_admin_home_link($pdo, $id, $expStr); } catch (Throwable $e) {}
                    }
                } else {
                    try { revoke_admin_home_links($pdo, $id); } catch (Throwable $e) {}
                }
            }
            json_response(['ok' => true]);
        }

        if ($action === 'set_admin_department_unlocked') {
            if ((string)($_SESSION['role'] ?? '') !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $id = (int)($_POST['id'] ?? 0);
            $unlocked = (int)($_POST['unlocked'] ?? 0) === 1 ? 1 : 0;
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid user.'], 400);
            }
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                json_response(['ok' => false, 'message' => 'Not found.'], 404);
            }
            if ((string)($u['role'] ?? '') !== 'admin') {
                json_response(['ok' => false, 'message' => 'Only admin accounts can be unlocked.'], 400);
            }
            $stmt = $pdo->prepare("UPDATE users SET admin_department_unlocked = :v WHERE id = :id");
            $stmt->execute([':v' => $unlocked, ':id' => $id]);
            json_response(['ok' => true, 'admin_department_unlocked' => $unlocked]);
        }

        if ($action === 'check_face_duplicate') {
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid user.'], 400);
            }
            $faceData = $_POST['face_data'] ?? '';
            if (!is_string($faceData) || $faceData === '') {
                json_response(['ok' => false, 'message' => 'No face data provided.'], 400);
            }
            $cap = decode_face_payload($faceData);
            if (!$cap) {
                json_response(['ok' => false, 'message' => 'Invalid face data format.'], 400);
            }
            $duplicate = check_face_duplicate($pdo, $cap, $id);
            json_response([
                'ok' => true,
                'is_duplicate' => $duplicate['match'],
                'duplicate_user_id' => $duplicate['user_id'] ?? null,
                'duplicate_user_name' => $duplicate['user_name'] ?? null,
                'duplicate_username' => $duplicate['username'] ?? null,
                'distance' => $duplicate['distance'] ?? null,
            ]);
        }

        if ($action === 'face_enroll') {
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid user.'], 400);
            }
            $sessionRole = (string)($_SESSION['role'] ?? '');
            $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
            if ($sessionRole === 'admin') {
                // Allow admin to enroll face for users they created OR for themselves
                $stmt = $pdo->prepare("SELECT id FROM users WHERE (id = :id AND created_by = :uid) OR (id = :id AND id = :self) LIMIT 1");
                $stmt->execute([':id' => $id, ':uid' => $sessionUserId, ':self' => $sessionUserId]);
                if (!$stmt->fetch()) {
                    json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
                }
            }
            $faceData = $_POST['face_data'] ?? '';
            if (!is_string($faceData) || $faceData === '') {
                json_response(['ok' => false, 'message' => 'No face data provided.'], 400);
            }
            $faceImage = $_POST['face_image'] ?? '';
            if (!is_string($faceImage) || $faceImage === '') {
                json_response(['ok' => false, 'message' => 'No face image provided.'], 400);
            }
            $cap = decode_face_payload($faceData);
            if (!$cap) {
                json_response(['ok' => false, 'message' => 'Invalid face data format.'], 400);
            }
            $duplicate = check_face_duplicate($pdo, $cap, $id);
            if ($duplicate['match']) {
                json_response([
                    'ok' => false,
                    'message' => 'Duplicate face detected. This face is already enrolled for ' . (string)($duplicate['user_name'] ?? 'another user') . ' (' . (string)($duplicate['username'] ?? '') . '). Distance: ' . (string)($duplicate['distance'] ?? ''),
                    'duplicate_user_id' => $duplicate['user_id'],
                    'duplicate_user_name' => $duplicate['user_name'],
                    'duplicate_username' => $duplicate['username'],
                    'distance' => $duplicate['distance'],
                ], 409);
            }
            // Save face image to disk in uploads/faces/ directory. The photo is
            // REQUIRED: if it cannot be written, the enrollment fails cleanly
            // instead of silently saving a half-enrolled user that later shows
            // as "Re-enroll Needed" because there is no stored picture.
            $facesDir = __DIR__ . '/uploads/faces';
            if (!is_dir($facesDir)) {
                @mkdir($facesDir, 0775, true);
            }
            if (!is_dir($facesDir) || !is_writable($facesDir)) {
                json_response(['ok' => false, 'message' => 'The captured face photo could not be stored: the uploads/faces folder is missing or not writable. Ask your administrator to check folder permissions, then capture again.'], 500);
            }
            if (!preg_match('#^data:image/(jpeg|png|jpg|webp);base64,#', $faceImage)) {
                json_response(['ok' => false, 'message' => 'The captured face photo has an invalid format. Please capture again.'], 400);
            }
            $parts = explode(',', $faceImage, 2);
            $b64 = $parts[1] ?? '';
            $bin = @base64_decode($b64, true);
            if ($bin === false || $bin === '') {
                json_response(['ok' => false, 'message' => 'The captured face photo could not be decoded. Please capture again.'], 400);
            }
            if (function_exists('imagecreatefromstring')) {
                $checkIm = @imagecreatefromstring($bin);
                if ($checkIm === false) {
                    json_response(['ok' => false, 'message' => 'The captured face photo is not a valid image. Please capture again.'], 400);
                }
                imagedestroy($checkIm);
            }
            // Convert JPEG to PNG for consistency
            $finalBin = $bin;
            if (str_contains($faceImage, 'image/jpeg') && function_exists('imagecreatefromstring') && function_exists('imagepng')) {
                try {
                    $im = @imagecreatefromstring($bin);
                    if ($im !== false) {
                        ob_start();
                        imagepng($im);
                        $finalBin = ob_get_clean();
                        imagedestroy($im);
                    }
                } catch (Throwable $e) {}
            }
            $filename = 'face_u' . $id . '_' . bin2hex(random_bytes(6)) . '.png';
            $absPath = $facesDir . '/' . $filename;
            if (@file_put_contents($absPath, $finalBin) === false) {
                json_response(['ok' => false, 'message' => 'The captured face photo could not be saved to the server. Please try again.'], 500);
            }
            $faceImagePath = 'uploads/faces/' . $filename;

            // The new photo is safely on disk — now remove the old photo file
            // so no orphaned files are left behind.
            $stmt = $pdo->prepare("SELECT face_image FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $oldImage = $stmt->fetchColumn();
            if ($oldImage && is_string($oldImage) && str_starts_with($oldImage, 'uploads/faces/')) {
                $oldPath = __DIR__ . '/' . $oldImage;
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            // Store the file path (the photo is guaranteed present on disk)
            $stmt = $pdo->prepare("UPDATE users SET face_data = :fd, face_image = :fi WHERE id = :id");
            $stmt->execute([':fd' => $faceData, ':fi' => $faceImagePath, ':id' => $id]);
            json_response([
                'ok' => true,
                'message' => 'Face data saved.',
                'face_image_path' => $faceImagePath,
                // Legacy-only payloads (no 128-dim "emb") keep the user flagged
                // "Re-enroll Needed". Newer clients block such saves, but flag it
                // here too so old cached clients can show a warning.
                'embed_missing' => $cap['kind'] !== 'embed',
            ]);
        }

        if ($action === 'list_enrolled_faces') {
            if ($role !== 'superadmin') {
                $currentUserId = (int)($_SESSION['user_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT id, name, username, department, position, face_image, (face_data IS NOT NULL AND face_data != '') AS has_face_data, (CASE WHEN face_data LIKE '%\"emb\"%' THEN 'embed' ELSE 'legacy' END) AS face_kind FROM users WHERE face_data IS NOT NULL AND face_data != '' AND (created_by = :created_by OR id = :self_id) ORDER BY name ASC");
                $stmt->execute([':created_by' => $currentUserId, ':self_id' => $currentUserId]);
            } else {
                $stmt = $pdo->query("SELECT id, name, username, department, position, face_image, (face_data IS NOT NULL AND face_data != '') AS has_face_data, (CASE WHEN face_data LIKE '%\"emb\"%' THEN 'embed' ELSE 'legacy' END) AS face_kind FROM users WHERE face_data IS NOT NULL AND face_data != '' ORDER BY name ASC");
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            $baseUrl = current_origin() . current_app_base_path() . '/users?ajax=1&action=serve_face_image&id=';
            foreach ($rows as $r) {
                $rawImg = $r['face_image'] ?? null;
                $imgUrl = null;
                // If face_image is a file path (starts with uploads/faces/), serve via the endpoint
                if ($rawImg !== null && str_starts_with($rawImg, 'uploads/faces/')) {
                    $imgUrl = $baseUrl . (int)$r['id'];
                } else if ($rawImg !== null && $rawImg !== '') {
                    // Legacy base64 data URL
                    $imgUrl = $rawImg;
                }
                $out[] = [
                    'id' => (int)$r['id'],
                    'name' => $r['name'] ?? '',
                    'username' => $r['username'] ?? '',
                    'department' => $r['department'] ?? '',
                    'position' => $r['position'] ?? '',
                    'face_image' => $imgUrl,
                    'face_kind' => (string)($r['face_kind'] ?? ''),
                ];
            }
            json_response(['ok' => true, 'users' => $out]);
        }

        if ($action === 'get_face_image') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid user.'], 400);
            }
            if ($role !== 'superadmin') {
                $currentUserId = (int)($_SESSION['user_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT name, username, face_image, (face_data IS NOT NULL AND face_data != '') AS has_face_data, (CASE WHEN face_data LIKE '%\"emb\"%' THEN 'embed' ELSE 'legacy' END) AS face_kind FROM users WHERE id = :id AND (created_by = :created_by OR id = :self_id) LIMIT 1");
                $stmt->execute([':id' => $id, ':created_by' => $currentUserId, ':self_id' => $currentUserId]);
            } else {
                $stmt = $pdo->prepare("SELECT name, username, face_image, (face_data IS NOT NULL AND face_data != '') AS has_face_data, (CASE WHEN face_data LIKE '%\"emb\"%' THEN 'embed' ELSE 'legacy' END) AS face_kind FROM users WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $id]);
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                json_response(['ok' => false, 'message' => 'User not found.'], 404);
            }
            $rawImg = $row['face_image'] ?? null;
            $imgUrl = null;
            if ($rawImg !== null && str_starts_with($rawImg, 'uploads/faces/')) {
                $baseUrl = current_origin() . current_app_base_path() . '/users?ajax=1&action=serve_face_image&id=' . $id;
                $imgUrl = $baseUrl;
            } else if ($rawImg !== null && $rawImg !== '') {
                $imgUrl = $rawImg;
            }
            json_response([
                'ok' => true,
                'name' => $row['name'] ?? '',
                'username' => $row['username'] ?? '',
                'has_face_data' => (int)($row['has_face_data'] ?? 0),
                'face_kind' => (string)($row['face_kind'] ?? ''),
                'face_image' => $imgUrl,
            ]);
        }

        if ($action === 'face_enroll_delete') {
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid user.'], 400);
            }
            $sessionRole = (string)($_SESSION['role'] ?? '');
            $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
            if ($sessionRole === 'admin') {
                // Allow admin to delete face for users they created OR for themselves
                $stmt = $pdo->prepare("SELECT id FROM users WHERE (id = :id AND created_by = :uid) OR (id = :id AND id = :self) LIMIT 1");
                $stmt->execute([':id' => $id, ':uid' => $sessionUserId, ':self' => $sessionUserId]);
                if (!$stmt->fetch()) {
                    json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
                }
            }

            // Fetch current face image to clean up the file from disk
            $stmt = $pdo->prepare("SELECT face_image FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $oldFaceImage = $stmt->fetchColumn();

            // Delete face image file from disk if stored as file path
            if ($oldFaceImage !== false && $oldFaceImage !== null && $oldFaceImage !== '' && is_string($oldFaceImage)) {
                if (str_starts_with($oldFaceImage, 'uploads/faces/')) {
                    $filePath = __DIR__ . '/' . $oldFaceImage;
                    if (is_file($filePath)) {
                        @unlink($filePath);
                    }
                }
            }

            // Delete ALL face image files for this user in uploads/faces/ directory
            // This catches orphaned files from previous enrollments
            $facesDir = __DIR__ . '/uploads/faces';
            if (is_dir($facesDir)) {
                $facePrefix = 'face_u' . $id . '_';
                $files = @glob($facesDir . '/' . $facePrefix . '*');
                if (is_array($files)) {
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            @unlink($file);
                        }
                    }
                }
            }

            // Completely clear all face data from database
            $stmt = $pdo->prepare("UPDATE users SET face_data = NULL, face_image = NULL WHERE id = :id");
            $stmt->execute([':id' => $id]);
            json_response(['ok' => true, 'message' => 'Face data completely removed.']);
        }

        if ($action === 'serve_face_image') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                header('Content-Type: image/png');
                exit;
            }
            if ($role !== 'superadmin') {
                $currentUserId = (int)($_SESSION['user_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND (created_by = :created_by OR id = :self_id) LIMIT 1");
                $stmt->execute([':id' => $id, ':created_by' => $currentUserId, ':self_id' => $currentUserId]);
                if (!$stmt->fetch()) {
                    http_response_code(403);
                    header('Content-Type: image/png');
                    exit;
                }
            }
            // Close session so other requests aren't blocked during file read
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $stmt = $pdo->prepare("SELECT face_image FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $rawFaceImage = $stmt->fetchColumn();
            if ($rawFaceImage === false || $rawFaceImage === null || $rawFaceImage === '') {
                http_response_code(404);
                header('Content-Type: image/png');
                exit;
            }
            if (is_string($rawFaceImage) && str_starts_with($rawFaceImage, 'uploads/faces/')) {
                $filePath = __DIR__ . '/' . $rawFaceImage;
                if (is_file($filePath) && is_readable($filePath)) {
                    $mime = detect_mime_type_for_path($filePath);
                    header('Content-Type: ' . $mime);
                    header('Content-Length: ' . filesize($filePath));
                    header('Cache-Control: max-age=86400, public');
                    readfile($filePath);
                    exit;
                }
            }
            if (is_string($rawFaceImage) && preg_match('#^data:image/(jpeg|png|jpg|webp);base64,#', $rawFaceImage, $mimeMatch)) {
                $parts = explode(',', $rawFaceImage, 2);
                $b64 = $parts[1] ?? '';
                $bin = @base64_decode($b64, true);
                if ($bin !== false && $bin !== '') {
                    $ext = strtolower($mimeMatch[1] ?? 'png');
                    if ($ext === 'jpg') $ext = 'jpeg';
                    header('Content-Type: image/' . $ext);
                    header('Content-Length: ' . strlen($bin));
                    header('Cache-Control: max-age=86400, public');
                    echo $bin;
                    exit;
                }
            }
            http_response_code(404);
            header('Content-Type: image/png');
            exit;
        }

        if ($action === 'delete_user') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid user.'], 400);
            }
            if ((int)($_SESSION['user_id'] ?? 0) === $id) {
                json_response(['ok' => false, 'message' => 'You cannot delete your own account.'], 400);
            }
            $sessionRole = (string)($_SESSION['role'] ?? '');
            $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT role, created_by FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                json_response(['ok' => false, 'message' => 'Not found.'], 404);
            }
            $targetRole = (string)($target['role'] ?? '');
            $targetCreatedBy = (int)($target['created_by'] ?? 0);

            if ($targetRole === 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }

            if ($sessionRole !== 'superadmin') {
                if ($targetCreatedBy !== $sessionUserId) {
                    json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
                }
            }

            $deletedDependents = 0;
            $deletedDependentAttendance = 0;

            $pdo->beginTransaction();
            try {
                if ($sessionRole === 'superadmin' && $targetRole === 'admin') {
                    try {
                        $stmt = $pdo->prepare("DELETE FROM attendance_logs WHERE user_id IN (SELECT id FROM users WHERE created_by = :aid)");
                        $stmt->execute([':aid' => $id]);
                        $deletedDependentAttendance = $stmt->rowCount();
                    }
                    catch (Throwable $e) {
                        $deletedDependentAttendance = 0;
                    }

                    $stmt = $pdo->prepare("DELETE FROM users WHERE created_by = :aid");
                    $stmt->execute([':aid' => $id]);
                    $deletedDependents = $stmt->rowCount();
                }

                $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
                $stmt->execute([':id' => $id]);

                $pdo->commit();
            }
            catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                json_response(['ok' => false, 'message' => 'Failed to delete user.'], 500);
            }

            json_response([
                'ok' => true,
                'deleted_dependents' => $deletedDependents,
                'deleted_dependent_attendance' => $deletedDependentAttendance,
            ]);
        }

        if ($action === 'regenerate_qr') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid user.'], 400);
            }
            $token = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("UPDATE users SET qr_token = :t WHERE id = :id");
            $stmt->execute([':t' => $token, ':id' => $id]);
            $stmt = $pdo->prepare("SELECT id, username, name, id_number, qr_token FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id, 'name' => '', 'id_number' => '', 'qr_token' => $token];
            json_response(['ok' => true, 'qr_token' => $token, 'qr_payload' => compute_qr_payload($pdo, $u)]);
        }

        json_response(['ok' => false, 'message' => 'Unknown action.'], 404);
    }
    catch (PDOException $e) {
        json_response(['ok' => false, 'message' => 'Database error.'], 500);
    }
}

include __DIR__ . '/includes/header.php';
ob_start();
ctr_cookie_consent_banner();
?>

<?php if (!empty($_SESSION['admin_needs_plan_request'])): ?>
<div class="container-fluid px-4 pt-4">
    <div class="alert alert-warning d-flex align-items-center" role="alert">
        <i class="feather-alert-triangle me-2 fs-4"></i>
        <div class="flex-grow-1">
            <strong>Plan Required</strong><br>
            <span>Your account needs an active plan to access all features. Please contact the Superadmin to request a plan activation.</span>
        </div>
        <button type="button" id="adminPlanExtendBtn" class="btn btn-warning btn-sm ms-3">
            <i class="feather-mail me-1"></i>Request Plan
        </button>
    </div>
</div>
<?php endif; ?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1">User Management</h4>
            <div class="text-muted small">Live user creation, validation, status control, and QR generator</div>
        </div>
        <div class="d-flex flex-wrap gap-2" style="position:relative;">
            <?php if ($role === 'admin'): ?>
            <div class="btn-group">
                <button type="button" class="btn btn-outline-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="feather-file-text me-1"></i> Excel Template
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <button type="button" class="dropdown-item" id="downloadUserTemplateBtn">
                            <i class="feather-download me-2"></i> Download
                        </button>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item" id="uploadUserTemplateBtn">
                            <i class="feather-upload me-2"></i> Upload
                        </button>
                    </li>
                </ul>
            </div>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-primary" id="bulkIdTemplateBtn" disabled>
                <i class="feather-download me-1"></i> Download ID Template <span class="badge bg-primary ms-1" id="idTemplateSelectedCount">0</span>
            </button>
            <button type="button" class="btn btn-outline-success" id="viewEnrolledFacesBtn">
                <i class="feather-eye me-1"></i> Enrolled Faces
            </button>
            <button type="button" class="btn btn-outline-info" id="faceDiagBtn">
                <i class="feather-activity me-1"></i> Face Diagnostics
            </button>
            <button type="button" class="btn btn-primary" id="addUserBtn">
                <i class="feather-plus me-1"></i> Add User
            </button>
            <div class="text-muted small align-self-center" id="usersUpdatedAt"></div>
            <?php if ($role === 'admin' && !empty($planExpired)): ?>
            <div class="ctr-lock-overlay-sm">
                <div class="d-flex align-items-center gap-2">
                    <span style="font-size:20px;">&#128274;</span>
                    <span class="fw-bold text-muted" style="font-size:13px;">Actions locked — plan required</span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2 gap-3 flex-wrap" id="usersControls">
        <div id="usersLengthContainer" class="ctr-ext-length">
            <label class="form-label mb-0 small text-muted">
                Show
                <select id="usersLengthSelect" class="form-select form-select-sm d-inline-block w-auto mx-1">
                    <option value="10">10</option>
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="200">200</option>
                </select>
                entries
            </label>
        </div>
        <div id="usersSearchContainer" class="ctr-ext-search">
            <label class="form-label mb-0 small text-muted">
                Search:
                <input type="search" id="usersSearchInput" class="form-control form-control-sm d-inline-block w-auto ms-1" placeholder="">
            </label>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="position:relative;">
        <div class="card-body">
            <div class="table-responsive">
                <table id="usersTable" class="table table-striped table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 46px;"><input class="form-check-input" type="checkbox" id="usersSelectAll"></th>
                            <th>Username</th>
                            <th>Real Name</th>
                            <th>ID Number</th>
                            <th>Role</th>
                            <th>Job</th>
                            <th>Department</th>
                            <th style="width: 140px;">Status</th>
                            <th style="width: 90px;">QR</th>
                            <th style="width: 200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="11" class="text-center text-muted py-4">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
            <!-- Footer kept OUTSIDE the scrollable table area so horizontal
                 scrolling never moves the entries info or Previous/Next. -->
            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <div class="text-muted small" id="usersPageInfo"></div>
                <nav aria-label="Users pagination">
                    <ul class="pagination pagination-sm mb-0" id="usersPagination"></ul>
                </nav>
            </div>
            <?php if ($role === 'admin' && !empty($planExpired)): ?>
            <div class="ctr-lock-overlay">
                <div class="text-center p-4">
                    <div style="font-size:42px;margin-bottom:8px;">&#128274;</div>
                    <div class="fw-bold text-muted" style="font-size:14px;">Table locked — plan required</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true" data-session-role="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" data-session-user-id="<?php echo (int)($_SESSION['user_id'] ?? 0); ?>">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form id="userForm" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalTitle">Add User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="userId">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="usernameInput">Username</label>
                            <input class="form-control" id="usernameInput" name="username" required>
                            <div class="invalid-feedback">Username is not available.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="passwordInput">Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="passwordInput" name="password">
                                <button class="btn btn-outline-secondary" type="button" id="togglePasswordBtn" tabindex="-1" title="Show/Hide password"><i class="feather-eye"></i></button>
                            </div>
                            <div class="form-text" id="passwordHelp">Leave blank to keep current password.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="idNumberInput">ID Number</label>
                            <input class="form-control" id="idNumberInput" name="id_number" required>
                            <div class="invalid-feedback">ID Number is not available.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="realNameInput">Real Name</label>
                            <input class="form-control" id="realNameInput" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="roleInput">Role</label>
                            <select class="form-select" id="roleInput" name="role" required>
                                <option value="admin">admin</option>
                                <option value="user" selected>user</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="assignedAdminCol">
                            <label class="form-label" for="assignedAdminInput">Assigned Admin</label>
                            <select class="form-select" id="assignedAdminInput" name="assigned_admin_id"></select>
                        </div>
                        <div class="col-md-6" id="planMonthsCol">
                            <label class="form-label" for="planMonthsInput">Plan <span class="badge bg-success-subtle text-success ms-1 d-none" id="planFreeTrialBadge">Free Trial</span></label>
                            <select class="form-select" id="planMonthsInput" name="plan_months">
                                <option value="0">No plan</option>
                                <option value="1" selected>1 month (Free Trial)</option>
                                <option value="2">2 months</option>
                                <option value="3">3 months</option>
                                <option value="4">4 months</option>
                                <option value="5">5 months</option>
                                <option value="6">6 months</option>
                                <option value="7">7 months</option>
                                <option value="8">8 months</option>
                                <option value="9">9 months</option>
                                <option value="10">10 months</option>
                                <option value="11">11 months</option>
                                <option value="12">1 year (12 months)</option>
                                <option value="24">2 years (24 months)</option>
                                <option value="36">3 years (36 months)</option>
                                <option value="48">4 years (48 months)</option>
                                <option value="60">5 years (60 months)</option>
                            </select>
                            <div class="form-text text-success d-none" id="planFreeTrialNote"><i class="feather-gift me-1"></i>A 1 month free trial will be activated for this admin.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="positionInput">Job title</label>
                            <input class="form-control" id="positionInput" name="position" list="jobList" required>
                            <datalist id="jobList">
                                <option value="Administrator"></option>
                                <option value="HR"></option>
                                <option value="Security"></option>
                                <option value="IT Support"></option>
                                <option value="Operations"></option>
                            </datalist>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="departmentInput">Department</label>
                            <input class="form-control" id="departmentInput" name="department" list="departmentList" required>
                            <datalist id="departmentList">
                                <option value="SGOD"></option>
                                <option value="CID"></option>
                                <option value="Admin"></option>
                                <option value="DRRM"></option>
                                <option value="Planning"></option>
                            </datalist>
                            <div class="form-text text-muted d-none" id="departmentLockedNote"><i class="feather-lock me-1"></i>Department locked by assigned Department.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="isActiveInput" name="is_active" checked>
                                <label class="form-check-label" for="isActiveInput">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveUserBtn">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="planModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Plan Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="planUserId">
                <div class="text-muted small mb-3" id="planUserLabel"></div>
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link active" id="setPlanTabBtn" data-bs-toggle="tab" data-bs-target="#setPlanPane" role="tab" aria-controls="setPlanPane" aria-selected="true">
                            <i class="feather-zap me-1"></i>Set Plan
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link" id="customPlanTabBtn" data-bs-toggle="tab" data-bs-target="#customPlanPane" role="tab" aria-controls="customPlanPane" aria-selected="false">
                            <i class="feather-calendar me-1"></i>Custom Plan
                        </button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="setPlanPane" role="tabpanel" aria-labelledby="setPlanTabBtn">
                        <label class="form-label" for="planMonthsModalInput">Plan duration</label>
                        <select class="form-select" id="planMonthsModalInput">
                            <option value="0">No plan</option>
                            <option value="1">1 month</option>
                            <option value="2">2 months</option>
                            <option value="3">3 months</option>
                            <option value="4">4 months</option>
                            <option value="5">5 months</option>
                            <option value="6">6 months</option>
                            <option value="7">7 months</option>
                            <option value="8">8 months</option>
                            <option value="9">9 months</option>
                            <option value="10">10 months</option>
                            <option value="11">11 months</option>
                            <option value="12">1 year (12 months)</option>
                            <option value="24">2 years (24 months)</option>
                            <option value="36">3 years (36 months)</option>
                            <option value="48">4 years (48 months)</option>
                            <option value="60">5 years (60 months)</option>
                        </select>
                        <div class="form-text">Starts immediately and expires after the selected number of months.</div>
                    </div>
                    <div class="tab-pane fade" id="customPlanPane" role="tabpanel" aria-labelledby="customPlanTabBtn">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="planStartDateInput">Start date</label>
                                <input type="date" class="form-control" id="planStartDateInput">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="planEndDateInput">Expiration date</label>
                                <input type="date" class="form-control" id="planEndDateInput">
                            </div>
                        </div>
                        <div class="form-text mt-2" id="customPlanRangeHint">Pick the exact month and day your plan starts and expires.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmPlanBtn">Save Plan</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="adminUsersModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Users Added</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-muted small mb-3" id="adminUsersTitle"></div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0" id="adminUsersTable">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Real Name</th>
                                <th>ID Number</th>
                                <th>Role</th>
                                <th>Job</th>
                                <th>Department</th>
                                <th style="width: 120px;">Status</th>
                                <th style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="8" class="text-center text-muted py-4">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="qrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">QR</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center">
                    <img id="qrImg" src="" alt="QR" style="width:256px;height:256px;max-width:100%;height:auto;">
                    <div class="mt-3 text-muted small" id="qrCaption"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="downloadQrBtn">Download</button>
                <button type="button" class="btn btn-outline-secondary" id="printQrBtn">Print</button>
                <button type="button" class="btn btn-primary" id="regenQrBtn">Regenerate</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="idTemplateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Download ID Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-muted small mb-3" id="idTemplateSelectionLabel"></div>
                <div class="fw-semibold mb-2">Template</div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="id_template_type" id="idTemplateCosJo" value="cos_jo" checked>
                    <label class="form-check-label" for="idTemplateCosJo">COS / JO ID Template (2025)</label>
                </div>
                <div class="form-check mt-1">
                    <input class="form-check-input" type="radio" name="id_template_type" id="idTemplatePermanent" value="permanent">
                    <label class="form-check-label" for="idTemplatePermanent">Permanent ID Template (2025)</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmIdTemplateBtn">Download</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="uploadUserTemplateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Upload User Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small mb-3" id="uploadUserTemplateLockedDeptNote">Department will be automatically set to your assigned department for every created user.</div>
                <div class="mb-3">
                    <label class="form-label" for="uploadUserTemplateFile">Template file</label>
                    <input class="form-control" type="file" id="uploadUserTemplateFile" accept=".xlsx,.xls,.csv">
                    <div class="form-text">Accepted formats: <code>.xlsx</code>, <code>.xls</code>, <code>.csv</code>. The first row is treated as a header if it matches a known column name.</div>
                </div>
                <div class="alert alert-warning py-2 small d-none" id="uploadUserTemplateWarn"></div>
                <div class="alert alert-success py-2 small d-none" id="uploadUserTemplateSuccess"></div>
                <div class="d-none" id="uploadUserTemplatePreviewWrap">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="fw-semibold">Preview</div>
                        <span class="badge bg-soft-secondary text-secondary" id="uploadUserTemplatePreviewCount">0 row(s)</span>
                    </div>
                    <div class="table-responsive" style="max-height: 240px;">
                        <table class="table table-sm table-bordered align-middle mb-0" id="uploadUserTemplatePreviewTable">
                            <thead></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="mt-3 d-none" id="uploadUserTemplateResultsWrap">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="fw-semibold">Result</div>
                        <div>
                            <span class="badge bg-soft-success text-success me-1" id="uploadUserTemplateCreatedBadge">Created: 0</span>
                            <span class="badge bg-soft-danger text-danger" id="uploadUserTemplateFailedBadge">Failed: 0</span>
                        </div>
                    </div>
                    <div class="table-responsive" style="max-height: 200px;">
                        <table class="table table-sm table-striped align-middle mb-0" id="uploadUserTemplateResultsTable">
                            <thead><tr><th>Username</th><th>Name</th><th>Default Password</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="text-muted small mt-2 d-none" id="uploadUserTemplateErrorsWrap">
                        <div class="fw-semibold">Errors</div>
                        <ul class="mb-0" id="uploadUserTemplateErrorsList"></ul>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="confirmUploadUserTemplateBtn" disabled>
                    <i class="feather-upload me-1"></i> Upload & Create Users
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="enrolledFacesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="feather-eye me-2"></i>Enrolled Faces <span class="badge bg-success ms-2" id="enrolledFacesCount"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row" id="enrolledFacesGrid">
                    <div class="text-center text-muted py-4">Loading enrolled faces...</div>
                </div>
            </div>
            <div class="modal-footer">
                <span class="text-muted small me-auto d-flex align-items-center gap-1" id="enrolledFacesUpdatedAt"><i class="feather-refresh-cw" style="font-size:12px;"></i>Auto-refreshes every 10s</span>
                <button type="button" class="btn btn-outline-primary" id="enrolledFacesRefreshBtn"><i class="feather-refresh-cw me-1"></i> Refresh</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="faceEnrollModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="faceEnrollModalTitle">Face Enrollment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-muted small mb-3" id="faceEnrollUserLabel"></div>
                <div id="faceEnrollCameraWrap" class="text-center" style="position:relative;display:inline-block;width:100%;">
                    <video id="faceEnrollVideo" autoplay muted playsinline style="width:100%;max-width:480px;border-radius:8px;background:#000;transform:scaleX(-1);"></video>
                    <canvas id="faceEnrollCanvas" style="display:none;"></canvas>
                    <div id="faceEnrollOverlay" style="position:absolute;top:0;left:50%;transform:translateX(-50%) scaleX(-1);width:480px;max-width:100%;height:100%;pointer-events:none;">
                        <svg viewBox="0 0 480 360" style="width:100%;height:100%;">
                            <rect x="120" y="40" width="240" height="280" rx="20" ry="20" fill="none" stroke="#17c666" stroke-width="3" stroke-dasharray="12,6" opacity="0.8"/>
                        </svg>
                    </div>
                </div>
                <div id="faceEnrollStatus" class="text-center mt-3">
                    <div class="text-muted small" id="faceEnrollMsg">Position your face inside the frame, keep your eyes open, and blink twice.</div>
                </div>
                <div id="faceEnrollPreview" class="text-center mt-3 d-none">
                    <img id="faceEnrollPreviewImg" src="" alt="Captured" style="max-width:240px;border-radius:8px;border:2px solid #17c666;">
                    <div class="mt-2 text-success small fw-semibold" id="faceEnrollPreviewLabel">Face detected successfully.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="faceEnrollCancelBtn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-outline-danger d-none" id="faceEnrollDeleteBtn">Remove Face Data</button>
                <button type="button" class="btn btn-primary d-none" id="faceEnrollCaptureBtn"><i class="feather-camera me-1"></i> Retry</button>
                <button type="button" class="btn btn-success d-none" id="faceEnrollSaveBtn"><i class="feather-check me-1"></i> Save</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="faceDiagModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="faceDiagModalTitle">Face Scan Diagnostics</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small py-2 mb-3">
                    Capture your own face (or another enrolled user's) a few times. Each capture logs the distance to that
                    user's enrolled faceprint (<span class="fw-semibold text-success">genuine</span>) and to every other
                    enrolled faceprint (<span class="fw-semibold text-danger">impostor</span>). The blink-twice liveness
                    check stays on. Use the distribution below to pick an accept threshold from real data.
                </div>
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label" for="faceDiagSubject">Who is scanning?</label>
                        <select id="faceDiagSubject" class="form-select">
                            <option value="">Select an enrolled user…</option>
                        </select>
                        <div id="faceDiagCameraWrap" class="text-center mt-3" style="position:relative;display:inline-block;width:100%;">
                            <video id="faceDiagVideo" autoplay muted playsinline style="width:100%;max-width:400px;border-radius:8px;background:#000;transform:scaleX(-1);"></video>
                            <canvas id="faceDiagCanvas" style="display:none;"></canvas>
                            <div id="faceDiagOverlay" style="position:absolute;top:0;left:50%;transform:translateX(-50%) scaleX(-1);width:400px;max-width:100%;height:100%;pointer-events:none;">
                                <svg viewBox="0 0 400 300" style="width:100%;height:100%;">
                                    <rect x="100" y="30" width="200" height="240" rx="16" ry="16" fill="none" stroke="#0dcaf0" stroke-width="3" stroke-dasharray="12,6" opacity="0.8"/>
                                </svg>
                            </div>
                        </div>
                        <div class="text-muted small text-center mt-2" id="faceDiagMsg">Select a user, then click Capture Sample.</div>
                        <button type="button" class="btn btn-outline-primary w-100 mt-2" id="faceDiagCaptureBtn"><i class="feather-camera me-1"></i> Capture Sample</button>
                        <div class="small mt-2 p-2 rounded bg-light border d-none" id="faceDiagResult"></div>
                    </div>
                    <div class="col-md-7">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <div class="fw-semibold small">Genuine vs Impostor distance distribution</div>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="faceDiagClearBtn">Clear Data</button>
                        </div>
                        <div id="faceDiagStats" class="text-muted small">No samples yet. Capture a few samples per enrolled user.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php if ($role === 'superadmin'): ?>
<div class="modal fade" id="adminHomeLinksModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="feather-link me-2"></i>Admin Home Links</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ahlSaAdminId">
                <div class="text-muted small mb-3" id="ahlSaAdminLabel"></div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="text-muted" style="font-size:12px;">Active links: <span id="ahlSaCount">0</span> / 5</div>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="ahlSaResetAllBtn"><i class="feather-rotate-ccw me-1"></i> Reset All Links</button>
                </div>
                <div id="ahlSaLinksList">
                    <div class="text-muted small text-center py-3">Loading...</div>
                </div>
                <div class="alert alert-warning mt-3 d-none" id="ahlSaAlert"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-muted">This cannot be undone.</div>
                <div class="mt-2 fw-semibold" id="deleteLabel"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">DELETE</button>
            </div>
        </div>
    </div>
</div>
<?php
$body = ob_get_clean();
$etag = ctr_etag_from(['b' => floor(time() / 20), 'uid' => (int)($_SESSION['user_id'] ?? 0), 'role' => (string)($_SESSION['role'] ?? '')]);
ctr_handle_conditional($etag, 20);
echo $body;
?>
