<?php
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/cache_headers.php';

ctr_session_start();

date_default_timezone_set('Asia/Manila');

// Page-level cache: short, because the kiosk scans need to land fast on
// the user's device after a successful POST.
ctr_cache_headers('short', 15);

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

$loggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$isAjax = isset($_GET['ajax']) && (string)($_GET['ajax'] ?? '') === '1';
if (!$loggedIn) {
    if (!$isAjax) {
        header('Location: ' . ctr_url('login'));
        exit;
    }
}

$attendanceRole = (string)($_SESSION['role'] ?? '');
if ($loggedIn && $attendanceRole !== 'admin' && $attendanceRole !== 'superadmin') {
    if (!$isAjax) {
        header('Location: ' . ctr_url('login'));
        exit;
    }
    json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
}

if ($loggedIn && $attendanceRole === 'admin') {
    $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
    $attendanceAdminNeedsPlan = false;
    if ($sessionUserId > 0) {
        try {
            $stmt = $pdo->prepare("SELECT is_active, plan_expires_at FROM users WHERE id = :id AND role = 'admin' LIMIT 1");
            $stmt->execute([':id' => $sessionUserId]);
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
                $attendanceAdminNeedsPlan = !$isActive || !$planActive;
            }
        } catch (Throwable $e) {
            $attendanceAdminNeedsPlan = true;
        }
    }
    $_SESSION['admin_needs_plan_request'] = $attendanceAdminNeedsPlan;
}

function ensure_attendance_schema_safe(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attendance_logs (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            id_number VARCHAR(64) NOT NULL,
            full_name VARCHAR(255) NOT NULL,
            position VARCHAR(255) NULL,
            attend_date DATE NOT NULL,
            session VARCHAR(16) NOT NULL,
            scan_type VARCHAR(8) NOT NULL,
            time_in TIME NULL,
            time_out TIME NULL,
            status VARCHAR(16) NOT NULL,
            image_path VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_attend_date (attend_date),
            KEY idx_user_date (user_id, attend_date),
            UNIQUE KEY uniq_user_date_session_type (user_id, attend_date, session, scan_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $cols = $pdo->query("SHOW COLUMNS FROM attendance_logs")->fetchAll(PDO::FETCH_ASSOC);
    $existing = [];
    foreach ($cols as $col) {
        $existing[strtolower((string)($col['Field'] ?? ''))] = true;
    }
    if (!isset($existing['image_path'])) {
        $pdo->exec("ALTER TABLE attendance_logs ADD COLUMN image_path VARCHAR(255) NULL");
    }
    if (!isset($existing['scanned_via'])) {
        $pdo->exec("ALTER TABLE attendance_logs ADD COLUMN scanned_via INT NULL");
    }
}

function ensure_admin_schedule_schema_safe(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_schedule_settings (
            admin_id INT NOT NULL,
            am_in TIME NULL,
            am_out TIME NULL,
            pm_in TIME NULL,
            pm_out TIME NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_user_schedule_schema_safe(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_schedule_settings (
            user_id INT NOT NULL,
            am_in TIME NULL,
            am_out TIME NULL,
            pm_in TIME NULL,
            pm_out TIME NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
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

function ensure_face_diag_schema_safe(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS face_diag_samples (
            id INT NOT NULL AUTO_INCREMENT,
            kind VARCHAR(16) NOT NULL,
            subject_user_id INT NOT NULL,
            target_user_id INT NOT NULL,
            subject_owner INT NOT NULL DEFAULT 0,
            dist DOUBLE NOT NULL,
            dims INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_diag_kind_owner (kind, subject_owner),
            KEY idx_diag_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
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

function b64url_decode(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad > 0) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $bin = base64_decode($s, true);
    return $bin === false ? '' : $bin;
}

function normalize_person_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    return mb_strtolower($name, 'UTF-8');
}

function parse_signed_qr(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '')
        return null;
    if (!str_starts_with($raw, 'CTR1.'))
        return null;
    $parts = explode('.', $raw);
    if (count($parts) !== 6)
        return null;
    [$ver, $uid, $idn, $nameB64, $token, $sig] = $parts;
    if ($ver !== 'CTR1')
        return null;
    if (!preg_match('/^\d+$/', $uid))
        return null;
    if ($idn === '' || strlen($idn) > 64)
        return null;
    if (!preg_match('/^[a-z0-9]{32}$/i', $token))
        return null;
    if ($sig === '' || strlen($sig) > 128)
        return null;
    $nameNorm = b64url_decode($nameB64);
    if ($nameNorm === '')
        return null;
    return [
        'uid' => (int)$uid,
        'id_number' => $idn,
        'name_norm' => $nameNorm,
        'name_b64' => $nameB64,
        'token' => $token,
        'sig' => $sig,
        'raw' => $raw,
    ];
}

function extract_id_number(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    if (str_starts_with($raw, '{') && str_contains($raw, 'id_number')) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['id_number'])) {
            $idn = trim((string)$decoded['id_number']);
            if ($idn !== '') {
                return $idn;
            }
        }
    }

    if (preg_match('/\bID\s*:\s*([^\r\n]+)/i', $raw, $m)) {
        $idn = trim((string)($m[1] ?? ''));
        if ($idn !== '') {
            return $idn;
        }
    }

    $lines = preg_split("/[\r\n]+/", $raw) ?: [];
    $lines = array_values(array_filter(array_map('trim', $lines), static fn($v) => $v !== ''));
    if (count($lines) === 1) {
        $v = $lines[0];
        if (!str_contains($v, ':') && strlen($v) <= 64) {
            return $v;
        }
    }

    foreach ($lines as $line) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{2,63}$/', $line) && preg_match('/\d/', $line)) {
            return $line;
        }
    }

    $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;
    $raw = trim($raw);
    if ($raw !== '' && strlen($raw) <= 64) {
        return $raw;
    }
    return '';
}

/**
 * Get admin's custom schedule settings or system defaults.
 * Each admin has independent times. If not set, returns the original defaults.
 */
function get_admin_schedule(PDO $pdo, int $adminId): array
{
    $defaults = [
        'am_in_start' => '05:00:00',
        'am_in_end'   => '10:30:00',
        'am_out_end'  => '12:30:00',
        'pm_in_end'   => '15:00:00',
        'pm_out_end'  => '19:00:00',
    ];
    if ($adminId <= 0) {
        return $defaults;
    }
    try {
        $stmt = $pdo->prepare("SELECT am_in, am_out, pm_in, pm_out FROM admin_schedule_settings WHERE admin_id = :aid LIMIT 1");
        $stmt->execute([':aid' => $adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $amIn = (string)($row['am_in'] ?? '');
            $amOut = (string)($row['am_out'] ?? '');
            $pmIn = (string)($row['pm_in'] ?? '');
            $pmOut = (string)($row['pm_out'] ?? '');
            if ($amIn !== '') $defaults['am_in_start'] = $amIn;   // AM In Start
            if ($amOut !== '') $defaults['am_in_end'] = $amOut;     // AM Out Start
            if ($pmIn !== '')  $defaults['am_out_end'] = $pmIn;     // PM In Start
            if ($pmOut !== '') $defaults['pm_in_end'] = $pmOut;     // PM Out Start
            // pm_out_end stays hardcoded at 19:00 — always end of day
        }
    } catch (Throwable $e) {}
    return $defaults;
}

/**
 * Get a user's schedule. Falls back to their admin's schedule if the user has no custom schedule.
 */
function get_user_schedule(PDO $pdo, int $userId): array
{
    // Try user's custom schedule first
    if ($userId > 0) {
        try {
            ensure_user_schedule_schema_safe($pdo);
            $stmt = $pdo->prepare("SELECT am_in, am_out, pm_in, pm_out FROM user_schedule_settings WHERE user_id = :uid LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $defaults = [
                    'am_in_start' => '05:00:00',
                    'am_in_end'   => '10:30:00',
                    'am_out_end'  => '12:30:00',
                    'pm_in_end'   => '15:00:00',
                    'pm_out_end'  => '19:00:00',
                ];
                $amIn = (string)($row['am_in'] ?? '');
                $amOut = (string)($row['am_out'] ?? '');
                $pmIn = (string)($row['pm_in'] ?? '');
                $pmOut = (string)($row['pm_out'] ?? '');
                if ($amIn !== '') $defaults['am_in_start'] = $amIn;
                if ($amOut !== '') $defaults['am_in_end'] = $amOut;
                if ($pmIn !== '')  $defaults['am_out_end'] = $pmIn;
                if ($pmOut !== '') $defaults['pm_in_end'] = $pmOut;
                return $defaults;
            }
        } catch (Throwable $e) {}
        // Fall back to the user's admin schedule
        try {
            $stmt = $pdo->prepare("SELECT created_by FROM users WHERE id = :uid LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            $adminId = (int)$stmt->fetchColumn();
            if ($adminId > 0) {
                return get_admin_schedule($pdo, $adminId);
            }
        } catch (Throwable $e) {}
    }
    // Final fallback: system defaults
    return [
        'am_in_start' => '05:00:00',
        'am_in_end'   => '10:30:00',
        'am_out_end'  => '12:30:00',
        'pm_in_end'   => '15:00:00',
        'pm_out_end'  => '19:00:00',
    ];
}

function schedule_seconds(string $time): int
{
    $parts = explode(':', $time);
    $h = (int)($parts[0] ?? 0);
    $i = (int)($parts[1] ?? 0);
    $s = (int)($parts[2] ?? 0);
    return ($h * 3600) + ($i * 60) + $s;
}

function classify_attendance(DateTimeImmutable $now, array $schedule = []): ?array
{
    $h = (int)$now->format('H');
    $i = (int)$now->format('i');
    $s = (int)$now->format('s');
    $sec = ($h * 3600) + ($i * 60) + $s;

    $morningInStart = schedule_seconds($schedule['am_in_start'] ?? '05:00:00');
    $morningInEnd   = schedule_seconds($schedule['am_in_end']   ?? '10:30:00');
    $morningOutEnd  = schedule_seconds($schedule['am_out_end']  ?? '12:30:00');

    $afternoonInStart = $morningOutEnd;
    $afternoonInEnd   = schedule_seconds($schedule['pm_in_end']   ?? '15:00:00');
    $afternoonOutEnd   = schedule_seconds($schedule['pm_out_end']  ?? '19:00:00');

    if ($sec >= $morningInStart && $sec < $morningInEnd) {
        return ['session' => 'morning', 'scan_type' => 'in', 'status' => 'Morning'];
    }
    if ($sec >= $morningInEnd && $sec < $morningOutEnd) {
        return ['session' => 'morning', 'scan_type' => 'out', 'status' => 'Midday'];
    }
    if ($sec >= $afternoonInStart && $sec < $afternoonInEnd) {
        return ['session' => 'afternoon', 'scan_type' => 'in', 'status' => 'Afternoon'];
    }
    if ($sec >= $afternoonInEnd && $sec < $afternoonOutEnd) {
        return ['session' => 'afternoon', 'scan_type' => 'out', 'status' => 'Evening'];
    }
    return null;
}

/**
 * Face descriptor payload helpers.
 *
 * v1 (legacy): a bare numeric array — the old 42-dim geometric landmark
 *   descriptor, compared with Euclidean distance.
 * v2 (embed):  {"v":2,"emb":[...128 floats...],"desc":[...42 floats...]} —
 *   a 128-dim learned face descriptor (face-api FaceRecognitionNet, computed
 *   in the browser) compared with Euclidean distance, with the legacy
 *   geometric descriptor kept alongside so older clients and stored legacy
 *   prints stay comparable during migration.
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
 * Compare two decoded face payloads (see decode_face_payload). Same-kind
 * payloads use their native metric; legacy-vs-embed pairs are not comparable
 * and return PHP_FLOAT_MAX. This is what lets a store migrate gradually:
 * stored legacy prints keep matching via the captured 42-dim descriptor while
 * new enrollments match via their 128-dim descriptors.
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
 * Per-kind accept thresholds. v2 descriptors (128-dim face-api
 * FaceRecognitionNet) use Euclidean distance: same-person measures ~0.1-0.5,
 * different-person ~0.5-1.0+, so start at accept < 0.45 with a soft 0.45-0.50
 * band. Legacy geometric stays at its real-scan-tuned 0.55/0.60. Both can be
 * re-tuned from the Face Diagnostics distributions.
 */
function face_match_thresholds(string $kind): array
{
    if ($kind === 'embed') {
        return ['accept' => 0.45, 'band' => 0.50];
    }
    return ['accept' => 0.55, 'band' => 0.60];
}

/**
 * Compare a captured face payload against every enrolled user. Distances are
 * kind-aware: each stored print is compared with the Euclidean distance of
 * the descriptor type it was enrolled with (128-dim v2 or 42-dim legacy), so
 * legacy and v2 users coexist during migration.
 *
 * Returns ['user_id','name','kind','dist','threshold','soft_band'] rows.
 */
function face_match_candidates(PDO $pdo, array $captured, int $ownerAdminId = 0): array
{
    if ($ownerAdminId > 0) {
        $stmt = $pdo->prepare("SELECT id, name, face_data FROM users WHERE face_data IS NOT NULL AND face_data != '' AND (id = :self OR created_by = :cb)");
        $stmt->execute([':self' => $ownerAdminId, ':cb' => $ownerAdminId]);
    } else {
        $stmt = $pdo->query("SELECT id, name, face_data FROM users WHERE face_data IS NOT NULL AND face_data != ''");
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $stored = decode_face_payload((string)$row['face_data']);
        if (!$stored) continue;
        $thr = face_match_thresholds($stored['kind']);
        $dist = PHP_FLOAT_MAX;
        if ($stored['kind'] === 'embed') {
            if (isset($captured['emb']) && count($captured['emb']) > 0) {
                $dist = face_euclidean_distance($captured['emb'], $stored['emb']);
            } elseif (isset($captured['desc']) && count($captured['desc']) > 0) {
                // Fallback for desc-only captures (face-api unavailable on a
                // kiosk, offline, or a cached old client): v2 enrollments keep
                // the legacy 42-dim descriptor precisely so legacy-format
                // captures can still match them during migration.
                $dist = face_euclidean_distance($captured['desc'], $stored['desc']);
            }
        } else {
            if (isset($captured['desc']) && count($captured['desc']) > 0) {
                $dist = face_euclidean_distance($captured['desc'], $stored['desc']);
            }
        }
        $out[] = [
            'user_id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'kind' => $stored['kind'],
            'dist' => $dist,
            'threshold' => $thr['accept'],
            'soft_band' => $thr['band'],
        ];
    }
    return $out;
}

/**
 * Bucket a list of distances into a fixed-width histogram for display.
 */
function diag_histogram(array $dists, float $width, float $from, float $to): array
{
    $buckets = [];
    $nBuckets = (int)ceil(($to - $from) / $width);
    for ($i = 0; $i < $nBuckets; $i++) {
        $buckets[] = ['lo' => round($from + $i * $width, 4), 'hi' => round($from + ($i + 1) * $width, 4), 'count' => 0];
    }
    foreach ($dists as $d) {
        if (!is_numeric($d)) continue;
        $d = (float)$d;
        if ($d < $from || $d > $to) continue;
        $idx = (int)floor(($d - $from) / $width);
        if ($idx < 0) $idx = 0;
        if ($idx >= $nBuckets) $idx = $nBuckets - 1;
        $buckets[$idx]['count']++;
    }
    return $buckets;
}

function diag_stats(array $dists): array
{
    $n = count($dists);
    if ($n === 0) {
        return ['n' => 0, 'min' => null, 'max' => null, 'mean' => null, 'stdev' => null];
    }
    $sum = array_sum($dists);
    $mean = $sum / $n;
    $sq = 0.0;
    foreach ($dists as $d) {
        $sq += ($d - $mean) * ($d - $mean);
    }
    return [
        'n' => $n,
        'min' => round(min($dists), 4),
        'max' => round(max($dists), 4),
        'mean' => round($mean, 4),
        'stdev' => round(sqrt($sq / $n), 4),
    ];
}

/**
 * Estimate the threshold that best separates genuine from impostor distances
 * by minimizing |FAR - FRR| across candidate thresholds (an EER proxy).
 * FAR = fraction of impostor distances below t (accepted wrongly)
 * FRR = fraction of genuine distances at or above t (rejected wrongly)
 */
function diag_suggest_threshold(array $genuine, array $impostor): array
{
    $best = ['threshold' => null, 'far' => null, 'frr' => null, 'gap' => null];
    $g = count($genuine);
    $im = count($impostor);
    if ($g === 0 || $im === 0) {
        return $best;
    }
    for ($t = 0.05; $t <= 3.0; $t += 0.01) {
        $far = 0;
        foreach ($impostor as $d) { if ($d < $t) $far++; }
        $frr = 0;
        foreach ($genuine as $d) { if ($d >= $t) $frr++; }
        $farR = $far / $im;
        $frrR = $frr / $g;
        $gap = abs($farR - $frrR);
        if ($best['threshold'] === null || $gap < $best['gap']) {
            $best = ['threshold' => round($t, 2), 'far' => round($farR, 4), 'frr' => round($frrR, 4), 'gap' => round($gap, 4)];
        }
    }
    return $best;
}

try {
    ensure_attendance_schema_safe($pdo);
    ensure_admin_schedule_schema_safe($pdo);
    ensure_user_schedule_schema_safe($pdo);
    ensure_face_diag_schema_safe($pdo);
}
catch (Throwable $e) {
}

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1') {
    // AJAX responses must never be browser-cached — stale list_logs
    // responses prevent the attendance list from updating after scans.
    ctr_cache_headers('private', 0);

    $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $tz);
    $sessionRole = (string)($_SESSION['role'] ?? '');
    $sessionUserId = (int)($_SESSION['user_id'] ?? 0);

    $kioskMode = false;
    $allowed = ['scan', 'face_scan', 'list_logs', 'server_time'];
    $provided = (string)($_GET['kiosk_token'] ?? $_POST['kiosk_token'] ?? '');
    $provided = trim($provided);
    if ($provided !== '' && in_array($action, $allowed, true)) {
        $expected = '';
        try { $expected = get_kiosk_token($pdo); } catch (Throwable $e) { $expected = ''; }
        if ($expected === '' || !hash_equals($expected, $provided)) {
            json_response(['ok' => false, 'message' => 'Unauthorized.'], 401);
        }
        $kioskMode = true;
        $sessionRole = 'superadmin';
        $sessionUserId = 0;
    }
    if (!$loggedIn && !$kioskMode) {
        if (!in_array($action, $allowed, true)) {
            json_response(['ok' => false, 'message' => 'Unauthorized.'], 401);
        }
        json_response(['ok' => false, 'message' => 'Unauthorized.'], 401);
    }

    // CSRF: session-authenticated state changes must carry a valid token.
    // Kiosk-mode requests are already authorized by the kiosk token secret.
    if (!$kioskMode && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        ctr_csrf_check();
    }

    $isAdmin = (!$kioskMode) && $sessionRole !== 'superadmin';
    $attUserFilter = '';
    $attUserParams = [];
    $adminSchedule = $isAdmin ? get_admin_schedule($pdo, $sessionUserId) : [];
    if ($isAdmin) {
        $attUserFilter = ' AND user_id IN (SELECT id FROM users WHERE created_by = :created_by OR id = :self_id)';
        $attUserParams[':created_by'] = $sessionUserId;
        $attUserParams[':self_id'] = $sessionUserId;
    }

    try {
        if ($action === 'server_time') {
            json_response(['ok' => true, 'server_time' => $now->format('Y-m-d H:i:s')]);
        }

        if ($action === 'list_logs') {
            $date = trim((string)($_GET['date'] ?? ''));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 50)));
            $offset = ($page - 1) * $perPage;

            // Superadmin overview: one row per admin showing their latest
            // attendance, so the table lists admins only (each row has a
            // "User Logins" action to drill into that admin's users).
            if (!$kioskMode && $sessionRole === 'superadmin') {
                $countStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
                $totalRows = (int)$countStmt->fetchColumn();

                $sql = "SELECT
                            u.id AS user_id,
                            u.id_number,
                            u.name AS full_name,
                            u.position,
                            u.department AS department,
                            u.created_by AS created_by,
                            u.role AS user_role,
                            u.id AS owner_admin_id,
                            g.attend_date,
                            g.am_in,
                            g.am_out,
                            g.pm_in,
                            g.pm_out,
                            l.status AS status,
                            l.image_path AS image_path,
                            l.scanned_via AS scanned_via,
                            l.created_at AS created_at
                        FROM users u
                        LEFT JOIN (
                            SELECT
                                a.user_id,
                                a.attend_date,
                                MAX(CASE WHEN a.session = 'morning' AND a.scan_type = 'in' THEN a.time_in END) AS am_in,
                                MAX(CASE WHEN a.session = 'morning' AND a.scan_type = 'out' THEN a.time_out END) AS am_out,
                                MAX(CASE WHEN a.session = 'afternoon' AND a.scan_type = 'in' THEN a.time_in END) AS pm_in,
                                MAX(CASE WHEN a.session = 'afternoon' AND a.scan_type = 'out' THEN a.time_out END) AS pm_out,
                                MAX(a.id) AS last_id
                            FROM attendance_logs a
                            INNER JOIN (
                                SELECT user_id, MAX(attend_date) AS md
                                FROM attendance_logs
                                GROUP BY user_id
                            ) latest ON latest.user_id = a.user_id AND latest.md = a.attend_date
                            GROUP BY a.user_id, a.attend_date
                        ) g ON g.user_id = u.id
                        LEFT JOIN attendance_logs l ON l.id = g.last_id
                        WHERE u.role = 'admin'
                        ORDER BY u.id DESC
                        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $totalPages = max(1, (int)ceil($totalRows / $perPage));
                json_response(['ok' => true, 'logs' => $rows, 'total' => $totalRows, 'page' => $page, 'per_page' => $perPage, 'total_pages' => $totalPages, 'server_time' => $now->format('Y-m-d H:i:s')]);
            }

            if ($date !== '') {
                $countSql = "SELECT COUNT(*) FROM (
                    SELECT user_id, id_number, full_name, position, attend_date
                    FROM attendance_logs
                    WHERE attend_date = :d{$attUserFilter}
                    GROUP BY user_id, id_number, full_name, position, attend_date
                ) sub";
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute(array_merge([':d' => $date], $attUserParams));
                $totalRows = (int)$countStmt->fetchColumn();

                $sql = "SELECT
                            g.user_id,
                            g.id_number,
                            g.full_name,
                            g.position,
                            u.department AS department,
                            u.created_by AS created_by,
                            u.role AS user_role,
                            (CASE WHEN u.role = 'admin' THEN u.id ELSE u.created_by END) AS owner_admin_id,
                            g.attend_date,
                            g.am_in,
                            g.am_out,
                            g.pm_in,
                            g.pm_out,
                            l.status AS status,
                            l.image_path AS image_path,
                            l.scanned_via AS scanned_via,
                            l.created_at AS created_at
                        FROM (
                            SELECT
                                user_id,
                                id_number,
                                full_name,
                                position,
                                attend_date,
                                MAX(CASE WHEN session = 'morning' AND scan_type = 'in' THEN time_in END) AS am_in,
                                MAX(CASE WHEN session = 'morning' AND scan_type = 'out' THEN time_out END) AS am_out,
                                MAX(CASE WHEN session = 'afternoon' AND scan_type = 'in' THEN time_in END) AS pm_in,
                                MAX(CASE WHEN session = 'afternoon' AND scan_type = 'out' THEN time_out END) AS pm_out,
                                MAX(id) AS last_id
                            FROM attendance_logs
                            WHERE attend_date = :d{$attUserFilter}
                            GROUP BY user_id, id_number, full_name, position, attend_date
                        ) g
                        LEFT JOIN attendance_logs l ON l.id = g.last_id
                        LEFT JOIN users u ON u.id = g.user_id
                        ORDER BY g.attend_date DESC, g.last_id DESC
                        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([':d' => $date], $attUserParams));
            }
            else {
                $countSql = "SELECT COUNT(*) FROM (
                    SELECT user_id, id_number, full_name, position, attend_date
                    FROM attendance_logs
                    WHERE 1=1{$attUserFilter}
                    GROUP BY user_id, id_number, full_name, position, attend_date
                ) sub";
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute($attUserParams);
                $totalRows = (int)$countStmt->fetchColumn();

                $sql = "SELECT
                            g.user_id,
                            g.id_number,
                            g.full_name,
                            g.position,
                            u.department AS department,
                            u.created_by AS created_by,
                            u.role AS user_role,
                            (CASE WHEN u.role = 'admin' THEN u.id ELSE u.created_by END) AS owner_admin_id,
                            g.attend_date,
                            g.am_in,
                            g.am_out,
                            g.pm_in,
                            g.pm_out,
                            l.status AS status,
                            l.image_path AS image_path,
                            l.scanned_via AS scanned_via,
                            l.created_at AS created_at
                        FROM (
                            SELECT
                                user_id,
                                id_number,
                                full_name,
                                position,
                                attend_date,
                                MAX(CASE WHEN session = 'morning' AND scan_type = 'in' THEN time_in END) AS am_in,
                                MAX(CASE WHEN session = 'morning' AND scan_type = 'out' THEN time_out END) AS am_out,
                                MAX(CASE WHEN session = 'afternoon' AND scan_type = 'in' THEN time_in END) AS pm_in,
                                MAX(CASE WHEN session = 'afternoon' AND scan_type = 'out' THEN time_out END) AS pm_out,
                                MAX(id) AS last_id
                            FROM attendance_logs
                            WHERE 1=1{$attUserFilter}
                            GROUP BY user_id, id_number, full_name, position, attend_date
                        ) g
                        LEFT JOIN attendance_logs l ON l.id = g.last_id
                        LEFT JOIN users u ON u.id = g.user_id
                        ORDER BY g.attend_date DESC, g.last_id DESC
                        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($attUserParams);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totalPages = max(1, (int)ceil($totalRows / $perPage));
            json_response(['ok' => true, 'logs' => $rows, 'total' => $totalRows, 'page' => $page, 'per_page' => $perPage, 'total_pages' => $totalPages, 'server_time' => $now->format('Y-m-d H:i:s')]);
        }

        // Superadmin drill-down: every user under one admin, each with their
        // latest attendance (photo + times) for the "User Logins" modal.
        if ($action === 'list_admin_users') {
            if ($kioskMode || $sessionRole !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $adminId = (int)($_GET['admin_id'] ?? 0);
            if ($adminId <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid admin.'], 400);
            }
            $adminStmt = $pdo->prepare("SELECT id, username, name, id_number FROM users WHERE id = :id AND role = 'admin' LIMIT 1");
            $adminStmt->execute([':id' => $adminId]);
            $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
            if (!$admin) {
                json_response(['ok' => false, 'message' => 'Admin not found.'], 404);
            }

            $sql = "SELECT
                        u.id,
                        u.id_number,
                        u.name AS full_name,
                        u.position,
                        u.department,
                        g.attend_date,
                        g.am_in,
                        g.am_out,
                        g.pm_in,
                        g.pm_out,
                        l.status AS status,
                        l.image_path AS image_path
                    FROM users u
                    LEFT JOIN (
                        SELECT
                            a.user_id,
                            a.attend_date,
                            MAX(CASE WHEN a.session = 'morning' AND a.scan_type = 'in' THEN a.time_in END) AS am_in,
                            MAX(CASE WHEN a.session = 'morning' AND a.scan_type = 'out' THEN a.time_out END) AS am_out,
                            MAX(CASE WHEN a.session = 'afternoon' AND a.scan_type = 'in' THEN a.time_in END) AS pm_in,
                            MAX(CASE WHEN a.session = 'afternoon' AND a.scan_type = 'out' THEN a.time_out END) AS pm_out,
                            MAX(a.id) AS last_id
                        FROM attendance_logs a
                        INNER JOIN (
                            SELECT user_id, MAX(attend_date) AS md
                            FROM attendance_logs
                            GROUP BY user_id
                        ) latest ON latest.user_id = a.user_id AND latest.md = a.attend_date
                        GROUP BY a.user_id, a.attend_date
                    ) g ON g.user_id = u.id
                    LEFT JOIN attendance_logs l ON l.id = g.last_id
                    WHERE u.created_by = :aid AND u.role = 'user'
                    ORDER BY u.name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':aid' => $adminId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_response(['ok' => true, 'admin' => $admin, 'users' => $rows]);
        }

        if ($action === 'monthly_records') {
            $month = trim((string)($_GET['month'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                json_response(['ok' => false, 'message' => 'Invalid month format. Use YYYY-MM.'], 400);
            }
            $monthStart = $month . '-01';
            $monthEnd = date('Y-m-t', strtotime($monthStart));

            $sql = "SELECT
                        g.user_id,
                        g.id_number,
                        g.full_name,
                        g.position,
                        u.department AS department,
                        u.created_by AS created_by,
                        u.role AS user_role,
                        g.attend_date,
                        g.am_in,
                        g.am_out,
                        g.pm_in,
                        g.pm_out,
                        l.status AS status,
                        l.image_path AS image_path,
                        l.scanned_via AS scanned_via,
                        l.created_at AS created_at
                    FROM (
                        SELECT
                            user_id,
                            id_number,
                            full_name,
                            position,
                            attend_date,
                            MAX(CASE WHEN session = 'morning' AND scan_type = 'in' THEN time_in END) AS am_in,
                            MAX(CASE WHEN session = 'morning' AND scan_type = 'out' THEN time_out END) AS am_out,
                            MAX(CASE WHEN session = 'afternoon' AND scan_type = 'in' THEN time_in END) AS pm_in,
                            MAX(CASE WHEN session = 'afternoon' AND scan_type = 'out' THEN time_out END) AS pm_out,
                            MAX(id) AS last_id
                        FROM attendance_logs
                        WHERE attend_date BETWEEN :ms AND :me{$attUserFilter}
                        GROUP BY user_id, id_number, full_name, position, attend_date
                    ) g
                    LEFT JOIN attendance_logs l ON l.id = g.last_id
                    LEFT JOIN users u ON u.id = g.user_id
                    ORDER BY g.full_name ASC, g.attend_date ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([':ms' => $monthStart, ':me' => $monthEnd], $attUserParams));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            json_response(['ok' => true, 'month' => $month, 'records' => $rows]);
        }

        if ($action === 'scan') {
            $raw = (string)($_POST['qr_data'] ?? $_POST['id_number'] ?? '');
            $scannedVia = (int)($_POST['scanned_via'] ?? 0);
            $parsed = parse_signed_qr($raw);
            if (!$parsed) {
                json_response(['ok' => false, 'message' => 'Invalid QR code.'], 400);
            }

            $secret = get_qr_secret($pdo);
            $base = 'CTR1|' . $parsed['uid'] . '|' . $parsed['id_number'] . '|' . $parsed['name_b64'] . '|' . $parsed['token'];
            $expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', $base, $secret, true)), '+/', '-_'), '=');
            if (!hash_equals((string)$expectedSig, (string)$parsed['sig'])) {
                json_response(['ok' => false, 'message' => 'Invalid QR code.'], 400);
            }

            $stmt = $pdo->prepare("SELECT id, id_number, name, position, department, qr_token, (face_data IS NOT NULL AND face_data != '') AS has_face_data FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $parsed['uid']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                json_response(['ok' => false, 'message' => 'User not found.'], 404);
            }
            if ((string)($user['id_number'] ?? '') !== (string)$parsed['id_number']) {
                json_response(['ok' => false, 'message' => 'Invalid QR code.'], 400);
            }
            if ((string)($user['qr_token'] ?? '') !== (string)$parsed['token']) {
                json_response(['ok' => false, 'message' => 'Invalid QR code.'], 400);
            }
            $dbNameNorm = normalize_person_name((string)($user['name'] ?? ''));
            $qrNameNorm = normalize_person_name((string)($parsed['name_norm'] ?? ''));
            if ($dbNameNorm === '' || $dbNameNorm !== $qrNameNorm) {
                json_response(['ok' => false, 'message' => 'Invalid QR code.'], 400);
            }

            if ($isAdmin) {
                $ownerCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :uid AND (created_by = :cb OR id = :self)");
                $ownerCheck->execute([':uid' => (int)$user['id'], ':cb' => $sessionUserId, ':self' => $sessionUserId]);
                if ((int)$ownerCheck->fetchColumn() === 0) {
                    json_response(['ok' => false, 'message' => 'You do not have access to this user.'], 403);
                }
            }

            $scanAt = $now;
            $clientTsRaw = trim((string)($_POST['client_ts'] ?? $_POST['client_time'] ?? ''));
            if ($clientTsRaw !== '') {
                if (!preg_match('/^\d{9,16}$/', $clientTsRaw)) {
                    json_response(['ok' => false, 'message' => 'Invalid scan time.'], 400);
                }
                $n = (int)$clientTsRaw;
                $sec = $n > 20000000000 ? (int)floor($n / 1000) : $n;
                if ($sec <= 0) {
                    json_response(['ok' => false, 'message' => 'Invalid scan time.'], 400);
                }
                $clientAt = (new DateTimeImmutable('@' . $sec))->setTimezone($tz);
                $delta = $now->getTimestamp() - $clientAt->getTimestamp();
                if ($delta < -(5 * 60)) {
                    json_response(['ok' => false, 'message' => 'Invalid scan time.'], 400);
                }
                if ($delta > (24 * 60 * 60)) {
                    json_response(['ok' => false, 'message' => 'Offline scan expired.'], 400);
                }
                $scanAt = $clientAt;
            }

            $userId = (int)$user['id'];

            // Use per-user schedule (which falls back to admin schedule if user has no custom schedule)
            $scanSchedule = get_user_schedule($pdo, $userId);
            $classification = classify_attendance($scanAt, $scanSchedule);
            if (!$classification) {
                json_response(['ok' => false, 'message' => 'Outside Attendance Hours', 'server_time' => $now->format('Y-m-d H:i:s')], 400);
            }

            $attendDate = $scanAt->format('Y-m-d');
            $timeStr = $scanAt->format('H:i:s');
            $session = (string)$classification['session'];
            $scanType = (string)$classification['scan_type'];
            $status = (string)$classification['status'];

            // Defer photo saving until AFTER the INSERT succeeds so duplicate
            // scans don't leave orphan image files on disk.
            $rawSnapshot = (string)($_POST['snapshot'] ?? '');

            $imagePath = null;

            $pdo->beginTransaction();

            $insert = $pdo->prepare("INSERT INTO attendance_logs (user_id, id_number, full_name, position, attend_date, session, scan_type, time_in, time_out, status, image_path, scanned_via, created_at) VALUES (:user_id, :id_number, :full_name, :position, :attend_date, :session, :scan_type, :time_in, :time_out, :status, :image_path, :scanned_via, :created_at)");
            $timeIn = $scanType === 'in' ? $timeStr : null;
            $timeOut = $scanType === 'out' ? $timeStr : null;

            try {
                $insert->execute([
                    ':user_id' => $userId,
                    ':id_number' => (string)$user['id_number'],
                    ':full_name' => (string)$user['name'],
                    ':position' => (string)($user['position'] ?? ''),
                    ':attend_date' => $attendDate,
                    ':session' => $session,
                    ':scan_type' => $scanType,
                    ':time_in' => $timeIn,
                    ':time_out' => $timeOut,
                    ':status' => $status,
                    ':image_path' => $imagePath,
                    ':scanned_via' => $scannedVia > 0 ? $scannedVia : null,
                    ':created_at' => $scanAt->format('Y-m-d H:i:s'),
                ]);
            }
            catch (PDOException $e) {
                $pdo->rollBack();
                $msg = str_contains((string)$e->getMessage(), 'Duplicate') ? ($scanType === 'in' ? 'Duplicate Time In.' : 'Duplicate Time Out.') : 'Failed to save attendance.';
                json_response(['ok' => false, 'message' => $msg, 'server_time' => $now->format('Y-m-d H:i:s')], 409);
            }

            $id = (int)$pdo->lastInsertId();
            $pdo->commit();

            // Save photo now that the INSERT succeeded (no orphan on duplicate).
            $imagePath = null;
            if ($rawSnapshot !== '' && preg_match('#^data:image/(png|jpeg|jpg);base64,#', $rawSnapshot)) {
                $parts = explode(',', $rawSnapshot, 2);
                $b64 = $parts[1] ?? '';
                $bin = base64_decode($b64, true);
                if ($bin !== false) {
                    $subdir = 'uploads/attendance/' . $scanAt->format('Ymd');
                    $absDir = __DIR__ . '/' . $subdir;
                    if (!is_dir($absDir)) {
                        @mkdir($absDir, 0777, true);
                    }
                    if (is_dir($absDir) && is_writable($absDir)) {
                        $isPng = str_contains($rawSnapshot, 'image/png');
                        $isJpeg = !$isPng;
                        $outBin = $bin;
                        $ext = 'png';
                        if ($isJpeg && function_exists('imagecreatefromstring') && function_exists('imagepng')) {
                            try {
                                $im = @imagecreatefromstring($bin);
                                if ($im !== false) {
                                    ob_start();
                                    imagepng($im);
                                    $out = ob_get_clean();
                                    imagedestroy($im);
                                    if (is_string($out) && $out !== '') {
                                        $outBin = $out;
                                    } else {
                                        $ext = 'jpg';
                                    }
                                } else {
                                    $ext = 'jpg';
                                }
                            } catch (Throwable $e) {
                                $ext = 'jpg';
                            }
                        } else if ($isPng) {
                            $outBin = $bin;
                        }
                        $name = 'u' . $userId . '_' . $scanAt->format('His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $absPath = $absDir . '/' . $name;
                        if (@file_put_contents($absPath, $outBin) !== false) {
                            $imagePath = $subdir . '/' . $name;
                            try {
                                $upd = $pdo->prepare("UPDATE attendance_logs SET image_path = :ip WHERE id = :id");
                                $upd->execute([':ip' => $imagePath, ':id' => $id]);
                            } catch (Throwable $e) {}
                        }
                    }
                }
            }

            json_response([
                'ok' => true,
                'message' => ($scanType === 'in' ? 'Time In recorded.' : 'Time Out recorded.'),
                'log' => [
                    'id' => $id,
                    'user_id' => $userId,
                    'id_number' => (string)$user['id_number'],
                    'full_name' => (string)$user['name'],
                    'position' => (string)($user['position'] ?? ''),
                    'department' => (string)($user['department'] ?? ''),
                    'attend_date' => $attendDate,
                    'session' => $session,
                    'scan_type' => $scanType,
                    'time_in' => $timeIn,
                    'time_out' => $timeOut,
                    'status' => $status,
                    'image_path' => $imagePath,
                    'created_at' => $scanAt->format('Y-m-d H:i:s'),
                ],
                'has_face_data' => (int)($user['has_face_data'] ?? 0),
                'user_id' => $userId,
                'server_time' => $now->format('Y-m-d H:i:s'),
            ]);
        }

        if ($action === 'face_scan') {
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            $faceDataRaw = (string)($_POST['face_data'] ?? '');
            if ($faceDataRaw === '') {
                json_response(['ok' => false, 'message' => 'No face data provided.'], 400);
            }
            $cap = decode_face_payload($faceDataRaw);
            if (!$cap) {
                json_response(['ok' => false, 'message' => 'Invalid face data format.'], 400);
            }
            $inDims = count($cap['emb'] ?? $cap['desc'] ?? []);

            // Kind-aware comparison against every enrolled user. Stored legacy
            // prints (42-dim geometric) and v2 (128-dim learned) descriptors
            // are both compared with Euclidean distance, each against the
            // descriptor type it was enrolled with. Thresholds come from
            // face_match_thresholds() and can be re-tuned with the Face
            // Diagnostics distributions.
            $cands = face_match_candidates($pdo, $cap, $isAdmin ? $sessionUserId : 0);

            // TEMP DEBUG: log face scan attempts with distances for diagnosis.
            try {
                $debugDir = __DIR__ . '/logs';
                if (!is_dir($debugDir)) { @mkdir($debugDir, 0777, true); }
                $debugFile = $debugDir . '/face_scan_debug.log';
                $dbgLines = [];
                $dbgLines[] = '[' . date('Y-m-d H:i:s') . '] face_scan attempt: in_dims=' . $inDims . ' kind=' . $cap['kind'];
                foreach ($cands as $c) {
                    $dbgLines[] = '  user_id=' . $c['user_id'] . ' name=' . $c['name'] . ' kind=' . $c['kind'] . ' dist=' . round($c['dist'], 4) . ' thr=' . $c['threshold'];
                }
                @file_put_contents($debugFile, implode("\n", $dbgLines) . "\n\n", FILE_APPEND);
            } catch (Throwable $e) {}

            $best = null;
            foreach ($cands as $c) {
                if ($c['dist'] >= PHP_FLOAT_MAX) continue;
                if ($best === null || $c['dist'] < $best['dist']) $best = $c;
            }
            $match = $best !== null && $best['dist'] < $best['threshold'] ? $best : null;
            $bestDist = $best !== null ? $best['dist'] : null;

            if (!$match) {
                // Face is close but above threshold — check if the closest user
                // already has attendance for this session so we can show a
                // "duplicate" message instead of the generic "not recognized".
                $softBand = $best !== null ? $best['soft_band'] : 0.50;
                if ($best !== null && $bestDist < $softBand) {
                    $dupStmt = $pdo->prepare(
                        "SELECT scan_type, time_in, time_out FROM attendance_logs
                         WHERE user_id = :uid AND attend_date = :d AND session = :s LIMIT 1"
                    );
                    $dupScanAt = $now;
                    $clientTsRaw2 = trim((string)($_POST['client_ts'] ?? ''));
                    if ($clientTsRaw2 !== '' && preg_match('/^\d{9,16}$/', $clientTsRaw2)) {
                        $n2 = (int)$clientTsRaw2;
                        $sec2 = $n2 > 20000000000 ? (int)floor($n2 / 1000) : $n2;
                        if ($sec2 > 0) {
                            try { $dupScanAt = (new DateTimeImmutable('@' . $sec2))->setTimezone($tz); } catch (Throwable $e) {}
                        }
                    }
                    $dupClass = classify_attendance($dupScanAt, get_user_schedule($pdo, (int)$best['user_id']));
                    if ($dupClass) {
                        $dupStmt->execute([
                            ':uid' => $best['user_id'],
                            ':d'   => $dupScanAt->format('Y-m-d'),
                            ':s'   => $dupClass['session'],
                        ]);
                        $dupRow = $dupStmt->fetch(PDO::FETCH_ASSOC);
                        if ($dupRow) {
                            $dupType = (string)($dupRow['scan_type'] ?? '');
                            if ($dupType === $dupClass['scan_type']) {
                                $dupLabel = $dupClass['scan_type'] === 'in' ? 'Time In' : 'Time Out';
                                json_response([
                                    'ok'    => false,
                                    'match' => true,
                                    'message' => 'Duplicate ' . $dupLabel . ' already recorded for this session.',
                                    'best_distance' => $bestDist,
                                    'threshold'     => $best['threshold'],
                                    'server_time'   => $now->format('Y-m-d H:i:s'),
                                ], 409);
                            }
                        }
                    }
                }
                $embedFailed = (string)($_POST['embed_failed'] ?? '') === '1';
                json_response([
                    'ok' => false,
                    // If the 128-dim embedding could not be computed, the face
                    // was seen (blinks passed) but the capture was not clear
                    // enough to recognize — guide the user instead of a
                    // misleading "not recognized".
                    'message' => $embedFailed
                        ? 'Your face was detected but not clearly enough to recognize. Look straight at the camera and try again.'
                        : 'Face not recognized. Please try again or use your QR code.',
                    'match' => false,
                    'best_distance' => $bestDist,
                    'threshold' => $best !== null ? $best['threshold'] : 0.45,
                    'in_dimensions' => $inDims,
                    'server_time' => $now->format('Y-m-d H:i:s'),
                ], 404);
            }

            $stmt = $pdo->prepare("SELECT id, id_number, name, position, department FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $match['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                json_response(['ok' => false, 'message' => 'User not found.'], 404);
            }

            $userId = (int)$user['id'];
            if ($isAdmin) {
                $ownerCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :uid AND (created_by = :cb OR id = :self)");
                $ownerCheck->execute([':uid' => $userId, ':cb' => $sessionUserId, ':self' => $sessionUserId]);
                if ((int)$ownerCheck->fetchColumn() === 0) {
                    json_response(['ok' => false, 'message' => 'You do not have access to this user.'], 403);
                }
            }

            $scanAt = $now;
            $clientTsRaw = trim((string)($_POST['client_ts'] ?? $_POST['client_time'] ?? ''));
            if ($clientTsRaw !== '') {
                if (!preg_match('/^\d{9,16}$/', $clientTsRaw)) {
                    json_response(['ok' => false, 'message' => 'Invalid scan time.'], 400);
                }
                $n = (int)$clientTsRaw;
                $sec = $n > 20000000000 ? (int)floor($n / 1000) : $n;
                if ($sec <= 0) {
                    json_response(['ok' => false, 'message' => 'Invalid scan time.'], 400);
                }
                $clientAt = (new DateTimeImmutable('@' . $sec))->setTimezone($tz);
                $delta = $now->getTimestamp() - $clientAt->getTimestamp();
                if ($delta < -(5 * 60)) {
                    json_response(['ok' => false, 'message' => 'Invalid scan time.'], 400);
                }
                if ($delta > (24 * 60 * 60)) {
                    json_response(['ok' => false, 'message' => 'Offline scan expired.'], 400);
                }
                $scanAt = $clientAt;
            }

            // Use per-user schedule (which falls back to admin schedule if user has no custom schedule)
            $faceScanSchedule = get_user_schedule($pdo, $userId);
            $classification = classify_attendance($scanAt, $faceScanSchedule);
            if (!$classification) {
                json_response(['ok' => false, 'message' => 'Outside Attendance Hours', 'server_time' => $now->format('Y-m-d H:i:s')], 400);
            }

            $attendDate = $scanAt->format('Y-m-d');
            $timeStr = $scanAt->format('H:i:s');
            $session = (string)$classification['session'];
            $scanType = (string)$classification['scan_type'];
            $status = (string)$classification['status'];

            $scannedVia = (int)($_POST['scanned_via'] ?? 0);

            // Defer photo saving until AFTER the INSERT succeeds so duplicate
            // scans don't leave orphan image files on disk.
            $rawSnapshot = (string)($_POST['snapshot'] ?? '');

            $imagePath = null;

            $pdo->beginTransaction();

            $insert = $pdo->prepare("INSERT INTO attendance_logs (user_id, id_number, full_name, position, attend_date, session, scan_type, time_in, time_out, status, image_path, scanned_via, created_at) VALUES (:user_id, :id_number, :full_name, :position, :attend_date, :session, :scan_type, :time_in, :time_out, :status, :image_path, :scanned_via, :created_at)");
            $timeIn = $scanType === 'in' ? $timeStr : null;
            $timeOut = $scanType === 'out' ? $timeStr : null;

            try {
                $insert->execute([
                    ':user_id' => $userId,
                    ':id_number' => (string)$user['id_number'],
                    ':full_name' => (string)$user['name'],
                    ':position' => (string)($user['position'] ?? ''),
                    ':attend_date' => $attendDate,
                    ':session' => $session,
                    ':scan_type' => $scanType,
                    ':time_in' => $timeIn,
                    ':time_out' => $timeOut,
                    ':status' => $status,
                    ':image_path' => $imagePath,
                    ':scanned_via' => $scannedVia > 0 ? $scannedVia : null,
                    ':created_at' => $scanAt->format('Y-m-d H:i:s'),
                ]);
            }
            catch (PDOException $e) {
                $pdo->rollBack();
                $msg = str_contains((string)$e->getMessage(), 'Duplicate') ? ($scanType === 'in' ? 'Duplicate Time In.' : 'Duplicate Time Out.') : 'Failed to save attendance.';
                json_response(['ok' => false, 'message' => $msg, 'server_time' => $now->format('Y-m-d H:i:s')], 409);
            }

            $id = (int)$pdo->lastInsertId();
            $pdo->commit();

            // Save photo now that the INSERT succeeded (no orphan on duplicate).
            $imagePath = null;
            if ($rawSnapshot !== '' && preg_match('#^data:image/(png|jpeg|jpg);base64,#', $rawSnapshot)) {
                $parts = explode(',', $rawSnapshot, 2);
                $b64 = $parts[1] ?? '';
                $bin = base64_decode($b64, true);
                if ($bin !== false) {
                    $subdir = 'uploads/attendance/' . $scanAt->format('Ymd');
                    $absDir = __DIR__ . '/' . $subdir;
                    if (!is_dir($absDir)) {
                        @mkdir($absDir, 0777, true);
                    }
                    if (is_dir($absDir) && is_writable($absDir)) {
                        $isPng = str_contains($rawSnapshot, 'image/png');
                        $isJpeg = !$isPng;
                        $outBin = $bin;
                        $ext = 'png';
                        if ($isJpeg && function_exists('imagecreatefromstring') && function_exists('imagepng')) {
                            try {
                                $im = @imagecreatefromstring($bin);
                                if ($im !== false) {
                                    ob_start();
                                    imagepng($im);
                                    $out = ob_get_clean();
                                    imagedestroy($im);
                                    if (is_string($out) && $out !== '') {
                                        $outBin = $out;
                                    } else {
                                        $ext = 'jpg';
                                    }
                                } else {
                                    $ext = 'jpg';
                                }
                            } catch (Throwable $e) {
                                $ext = 'jpg';
                            }
                        } else if ($isPng) {
                            $outBin = $bin;
                        }
                        $name = 'u' . $userId . '_' . $scanAt->format('His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $absPath = $absDir . '/' . $name;
                        if (@file_put_contents($absPath, $outBin) !== false) {
                            $imagePath = $subdir . '/' . $name;
                            try {
                                $upd = $pdo->prepare("UPDATE attendance_logs SET image_path = :ip WHERE id = :id");
                                $upd->execute([':ip' => $imagePath, ':id' => $id]);
                            } catch (Throwable $e) {}
                        }
                    }
                }
            }

            json_response([
                'ok' => true,
                'match' => true,
                'distance' => round((float)$match['dist'], 6),
                'kind' => $match['kind'],
                'threshold' => $match['threshold'],
                'user_id' => $userId,
                'message' => 'Face recognized: ' . (string)$user['name'] . '. ' . ($scanType === 'in' ? 'Time In recorded.' : 'Time Out recorded.'),
                'log' => [
                    'id' => $id,
                    'user_id' => $userId,
                    'id_number' => (string)$user['id_number'],
                    'full_name' => (string)$user['name'],
                    'position' => (string)($user['position'] ?? ''),
                    'department' => (string)($user['department'] ?? ''),
                    'attend_date' => $attendDate,
                    'session' => $session,
                    'scan_type' => $scanType,
                    'time_in' => $timeIn,
                    'time_out' => $timeOut,
                    'status' => $status,
                    'image_path' => $imagePath,
                    'created_at' => $scanAt->format('Y-m-d H:i:s'),
                ],
                'server_time' => $now->format('Y-m-d H:i:s'),
            ]);
        }

        if ($action === 'face_diag_capture') {
            // Diagnostic capture: records a genuine + impostor distance sample for
            // the named subject WITHOUT creating any attendance record. This is the
            // data used to pick an accept threshold from real distributions.
            // Admin-session only (kiosk token requests 401 before reaching here).
            if (!$loggedIn) {
                json_response(['ok' => false, 'message' => 'Unauthorized.'], 401);
            }
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            $subjectId = (int)($_POST['subject_id'] ?? 0);
            if ($subjectId <= 0) {
                json_response(['ok' => false, 'message' => 'No subject selected.'], 400);
            }
            $faceDataRaw = (string)($_POST['face_data'] ?? '');
            if ($faceDataRaw === '') {
                json_response(['ok' => false, 'message' => 'No face data provided.'], 400);
            }
            $cap = decode_face_payload($faceDataRaw);
            if (!$cap || $cap['kind'] !== 'embed') {
                json_response(['ok' => false, 'message' => 'Invalid face data format. Re-enroll with the new face model first.'], 400);
            }

            // Subject must exist, have an enrolled faceprint, and (for admins) belong to them.
            $subjectParams = [':sid' => $subjectId];
            if ($isAdmin) {
                $subjectParams[':self'] = $sessionUserId;
                $subjectParams[':cb'] = $sessionUserId;
            }
            $subjStmt = $pdo->prepare("SELECT id, name, username, created_by, face_data FROM users WHERE id = :sid" . ($isAdmin ? " AND (id = :self OR created_by = :cb)" : "") . " LIMIT 1");
            $subjStmt->execute($subjectParams);
            $subject = $subjStmt->fetch(PDO::FETCH_ASSOC);
            if (!$subject) {
                json_response(['ok' => false, 'message' => 'Subject not found.'], 404);
            }
            $subjectPayload = decode_face_payload((string)$subject['face_data']);
            if (!$subjectPayload) {
                json_response(['ok' => false, 'message' => 'The selected user has no enrolled faceprint. Enroll their face first.'], 400);
            }
            if ($subjectPayload['kind'] !== 'embed') {
                json_response(['ok' => false, 'message' => 'This user has a legacy faceprint. Re-enroll their face with the new model to collect diagnostic data.'], 400);
            }

            // All diagnostic distances live in v2 descriptor space so the
            // genuine and impostor distributions are directly comparable.
            // Legacy prints are skipped — they get re-enrolled during
            // migration anyway.
            $genuineDist = face_euclidean_distance($cap['emb'], $subjectPayload['emb']);
            $impostorRows = [];
            $minImpostor = null;
            $maxImpostor = null;
            $allStmt = $pdo->query("SELECT id, face_data FROM users WHERE face_data IS NOT NULL AND face_data != '' AND id != " . (int)$subjectId);
            $allRows = $allStmt ? $allStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($allRows as $row) {
                $existing = decode_face_payload((string)$row['face_data']);
                if (!$existing || $existing['kind'] !== 'embed') continue;
                $d = face_euclidean_distance($cap['emb'], $existing['emb']);
                $impostorRows[] = ['target_user_id' => (int)$row['id'], 'dist' => $d];
                if ($minImpostor === null || $d < $minImpostor) $minImpostor = $d;
                if ($maxImpostor === null || $d > $maxImpostor) $maxImpostor = $d;
            }

            $dims = count($cap['emb']);
            try {
                ensure_face_diag_schema_safe($pdo);
                $owner = (int)($subject['created_by'] ?? 0);
                $ins = $pdo->prepare("INSERT INTO face_diag_samples (kind, subject_user_id, target_user_id, dist, dims, subject_owner) VALUES ('genuine', :su, :tu, :dist, :dims, :owner)");
                $ins->execute([':su' => $subjectId, ':tu' => $subjectId, ':dist' => $genuineDist, ':dims' => $dims, ':owner' => $owner]);
                if (count($impostorRows) > 0) {
                    $ins = $pdo->prepare("INSERT INTO face_diag_samples (kind, subject_user_id, target_user_id, dist, dims, subject_owner) VALUES ('impostor', :su, :tu, :dist, :dims, :owner)");
                    foreach ($impostorRows as $ir) {
                        $ins->execute([':su' => $subjectId, ':tu' => $ir['target_user_id'], ':dist' => $ir['dist'], ':dims' => $dims, ':owner' => $owner]);
                    }
                }
                // Keep calibration data bounded: retain only the newest 2000 rows per kind.
                $keep = 2000;
                foreach (['genuine', 'impostor'] as $kind) {
                    $pdo->exec(
                        "DELETE FROM face_diag_samples WHERE kind = '" . $kind . "' AND id NOT IN (" .
                        "SELECT id FROM (SELECT id FROM face_diag_samples WHERE kind = '" . $kind . "' ORDER BY id DESC LIMIT " . $keep . ") keep_rows)"
                    );
                }
            } catch (Throwable $e) {
                json_response(['ok' => false, 'message' => 'Could not store diagnostic sample.'], 500);
            }

            json_response([
                'ok' => true,
                'message' => 'Diagnostic sample recorded for ' . (string)$subject['name'] . '.',
                'subject_id' => $subjectId,
                'subject_name' => (string)$subject['name'],
                'genuine_dist' => round($genuineDist, 6),
                'min_impostor_dist' => $minImpostor === null ? null : round($minImpostor, 6),
                'max_impostor_dist' => $maxImpostor === null ? null : round($maxImpostor, 6),
                'n_impostors' => count($impostorRows),
                'current_threshold' => 0.45,
                'dims' => $dims,
            ]);
        }

        if ($action === 'face_diag_stats') {
            // Aggregate genuine/impostor distance distributions + EER-style
            // suggested threshold. Admins only see samples for their own subjects.
            if (!$loggedIn) {
                json_response(['ok' => false, 'message' => 'Unauthorized.'], 401);
            }
            $ownerClause = $isAdmin ? ' AND subject_owner = :owner' : '';
            $params = $isAdmin ? [':owner' => $sessionUserId] : [];
            $genuine = [];
            $impostor = [];
            try {
                ensure_face_diag_schema_safe($pdo);
                $gStmt = $pdo->prepare("SELECT dist FROM face_diag_samples WHERE kind = 'genuine'" . $ownerClause);
                $iStmt = $pdo->prepare("SELECT dist FROM face_diag_samples WHERE kind = 'impostor'" . $ownerClause);
                $gStmt->execute($params);
                $iStmt->execute($params);
                $genuine = array_values(array_filter(array_map('floatval', $gStmt->fetchAll(PDO::FETCH_COLUMN)), 'is_finite'));
                $impostor = array_values(array_filter(array_map('floatval', $iStmt->fetchAll(PDO::FETCH_COLUMN)), 'is_finite'));
            } catch (Throwable $e) {
                json_response(['ok' => false, 'message' => 'Could not read diagnostic samples.'], 500);
            }

            json_response([
                'ok' => true,
                'counts' => ['genuine' => count($genuine), 'impostor' => count($impostor)],
                'genuine' => diag_stats($genuine),
                'impostor' => diag_stats($impostor),
                'histogram' => [
                    'genuine' => diag_histogram($genuine, 0.02, 0.0, 3.0),
                    'impostor' => diag_histogram($impostor, 0.02, 0.0, 3.0),
                ],
                'suggestion' => diag_suggest_threshold($genuine, $impostor),
                'current_threshold' => 0.45,
                'server_time' => $now->format('Y-m-d H:i:s'),
            ]);
        }

        if ($action === 'face_diag_clear') {
            // Reset the calibration data. Admins clear only their own subjects' samples.
            if (!$loggedIn) {
                json_response(['ok' => false, 'message' => 'Unauthorized.'], 401);
            }
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            try {
                ensure_face_diag_schema_safe($pdo);
                if ($isAdmin) {
                    $del = $pdo->prepare("DELETE FROM face_diag_samples WHERE subject_owner = :owner");
                    $del->execute([':owner' => $sessionUserId]);
                } else {
                    $pdo->exec("DELETE FROM face_diag_samples");
                }
            } catch (Throwable $e) {
                json_response(['ok' => false, 'message' => 'Could not clear samples.'], 500);
            }
            json_response(['ok' => true, 'message' => 'Diagnostic samples cleared.']);
        }

        if ($action === 'get_attendance_record') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid attendance record.'], 400);
            }
            $date = trim((string)($_GET['date'] ?? $_GET['attend_date'] ?? ''));
            if ($date === '') {
                $date = $now->format('Y-m-d');
            }
            $sql = "SELECT
                        g.user_id,
                        g.id_number,
                        g.full_name,
                        g.position,
                        g.attend_date,
                        g.am_in,
                        g.am_out,
                        g.pm_in,
                        g.pm_out
                    FROM (
                        SELECT
                            user_id,
                            id_number,
                            full_name,
                            position,
                            attend_date,
                            MAX(CASE WHEN session = 'morning' AND scan_type = 'in' THEN time_in END) AS am_in,
                            MAX(CASE WHEN session = 'morning' AND scan_type = 'out' THEN time_out END) AS am_out,
                            MAX(CASE WHEN session = 'afternoon' AND scan_type = 'in' THEN time_in END) AS pm_in,
                            MAX(CASE WHEN session = 'afternoon' AND scan_type = 'out' THEN time_out END) AS pm_out
                        FROM attendance_logs
                        WHERE user_id = :uid AND attend_date = :d
                        GROUP BY user_id, id_number, full_name, position, attend_date
                    ) g
                    LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':uid' => $id, ':d' => $date]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                json_response(['ok' => false, 'message' => 'Record not found.'], 404);
            }
            json_response(['ok' => true, 'record' => $row]);
        }

        if ($action === 'edit_attendance') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $attend_date = trim((string)($_POST['attend_date'] ?? ''));
            $am_in = trim((string)($_POST['am_in'] ?? ''));
            $am_out = trim((string)($_POST['am_out'] ?? ''));
            $pm_in = trim((string)($_POST['pm_in'] ?? ''));
            $pm_out = trim((string)($_POST['pm_out'] ?? ''));

            if ($user_id <= 0 || $attend_date === '') {
                json_response(['ok' => false, 'message' => 'Invalid data.'], 400);
            }

            $pdo->beginTransaction();
            try {
                // Collect image paths before deleting the rows
                $imgStmt = $pdo->prepare("SELECT image_path FROM attendance_logs WHERE user_id = :uid AND attend_date = :d AND image_path IS NOT NULL AND image_path != ''");
                $imgStmt->execute([':uid' => $user_id, ':d' => $attend_date]);
                $imagePaths = $imgStmt->fetchAll(PDO::FETCH_COLUMN);

                // Delete existing records for this user/date
                $stmt = $pdo->prepare("DELETE FROM attendance_logs WHERE user_id = :uid AND attend_date = :d");
                $stmt->execute([':uid' => $user_id, ':d' => $attend_date]);

                // Get user info
                $stmt = $pdo->prepare("SELECT id, id_number, name, position FROM users WHERE id = :uid LIMIT 1");
                $stmt->execute([':uid' => $user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    $pdo->rollBack();
                    json_response(['ok' => false, 'message' => 'User not found.'], 404);
                }

                // Insert AM In
                if ($am_in !== '') {
                    $stmt = $pdo->prepare("INSERT INTO attendance_logs (user_id, id_number, full_name, position, attend_date, session, scan_type, time_in, time_out, status) VALUES (:uid, :idn, :name, :pos, :d, 'morning', 'in', :time, NULL, 'Morning')");
                    $stmt->execute([':uid' => $user_id, ':idn' => $user['id_number'], ':name' => $user['name'], ':pos' => $user['position'] ?? '', ':d' => $attend_date, ':time' => $am_in]);
                }

                // Insert AM Out
                if ($am_out !== '') {
                    $stmt = $pdo->prepare("INSERT INTO attendance_logs (user_id, id_number, full_name, position, attend_date, session, scan_type, time_in, time_out, status) VALUES (:uid, :idn, :name, :pos, :d, 'morning', 'out', NULL, :time, 'Midday')");
                    $stmt->execute([':uid' => $user_id, ':idn' => $user['id_number'], ':name' => $user['name'], ':pos' => $user['position'] ?? '', ':d' => $attend_date, ':time' => $am_out]);
                }

                // Insert PM In
                if ($pm_in !== '') {
                    $stmt = $pdo->prepare("INSERT INTO attendance_logs (user_id, id_number, full_name, position, attend_date, session, scan_type, time_in, time_out, status) VALUES (:uid, :idn, :name, :pos, :d, 'afternoon', 'in', :time, NULL, 'Afternoon')");
                    $stmt->execute([':uid' => $user_id, ':idn' => $user['id_number'], ':name' => $user['name'], ':pos' => $user['position'] ?? '', ':d' => $attend_date, ':time' => $pm_in]);
                }

                // Insert PM Out
                if ($pm_out !== '') {
                    $stmt = $pdo->prepare("INSERT INTO attendance_logs (user_id, id_number, full_name, position, attend_date, session, scan_type, time_in, time_out, status) VALUES (:uid, :idn, :name, :pos, :d, 'afternoon', 'out', NULL, :time, 'Evening')");
                    $stmt->execute([':uid' => $user_id, ':idn' => $user['id_number'], ':name' => $user['name'], ':pos' => $user['position'] ?? '', ':d' => $attend_date, ':time' => $pm_out]);
                }

                $pdo->commit();

                foreach ($imagePaths as $relPath) {
                    $abs = __DIR__ . '/' . ltrim($relPath, '/');
                    if ($abs !== '' && is_file($abs)) {
                        @unlink($abs);
                    }
                }

                json_response(['ok' => true, 'message' => 'Attendance updated successfully.']);
            }
            catch (PDOException $e) {
                $pdo->rollBack();
                json_response(['ok' => false, 'message' => 'Failed to update attendance.'], 500);
            }
        }

        if ($action === 'delete_attendance') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $attend_date = trim((string)($_POST['attend_date'] ?? ''));

            if ($user_id <= 0 || $attend_date === '') {
                json_response(['ok' => false, 'message' => 'Invalid data.'], 400);
            }

            // Collect image paths before deleting the rows
            $imgStmt = $pdo->prepare("SELECT image_path FROM attendance_logs WHERE user_id = :uid AND attend_date = :d AND image_path IS NOT NULL AND image_path != ''");
            $imgStmt->execute([':uid' => $user_id, ':d' => $attend_date]);
            $imagePaths = $imgStmt->fetchAll(PDO::FETCH_COLUMN);

            $stmt = $pdo->prepare("DELETE FROM attendance_logs WHERE user_id = :uid AND attend_date = :d");
            $stmt->execute([':uid' => $user_id, ':d' => $attend_date]);

            foreach ($imagePaths as $relPath) {
                $abs = __DIR__ . '/' . ltrim($relPath, '/');
                if ($abs !== '' && is_file($abs)) {
                    @unlink($abs);
                }
            }

            json_response(['ok' => true, 'message' => 'Attendance record deleted.']);
        }

        if ($action === 'get_dtr') {
            $user_id = (int)($_GET['user_id'] ?? 0);
            $date_from = trim((string)($_GET['date_from'] ?? ''));
            $date_to = trim((string)($_GET['date_to'] ?? ''));

            if ($user_id <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid user.'], 400);
            }

            // Get user info
            $stmt = $pdo->prepare("SELECT id, id_number, name, position, department FROM users WHERE id = :uid LIMIT 1");
            $stmt->execute([':uid' => $user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                json_response(['ok' => false, 'message' => 'User not found.'], 404);
            }

            // Build date filter
            $dateFilter = '';
            $params = [':uid' => $user_id];
            if ($date_from !== '' && $date_to !== '') {
                $dateFilter = ' AND attend_date BETWEEN :df AND :dt';
                $params[':df'] = $date_from;
                $params[':dt'] = $date_to;
            }
            elseif ($date_from !== '') {
                $dateFilter = ' AND attend_date >= :df';
                $params[':df'] = $date_from;
            }
            elseif ($date_to !== '') {
                $dateFilter = ' AND attend_date <= :dt';
                $params[':dt'] = $date_to;
            }

            $sql = "SELECT
                        attend_date,
                        MAX(CASE WHEN session = 'morning' AND scan_type = 'in' THEN time_in END) AS am_in,
                        MAX(CASE WHEN session = 'morning' AND scan_type = 'out' THEN time_out END) AS am_out,
                        MAX(CASE WHEN session = 'afternoon' AND scan_type = 'in' THEN time_in END) AS pm_in,
                        MAX(CASE WHEN session = 'afternoon' AND scan_type = 'out' THEN time_out END) AS pm_out
                    FROM attendance_logs
                    WHERE user_id = :uid $dateFilter
                    GROUP BY attend_date
                    ORDER BY attend_date ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_response([
                'ok' => true,
                'user' => $user,
                'records' => $records,
                'date_from' => $date_from,
                'date_to' => $date_to
            ]);
        }

        if ($action === 'get_schedule_settings') {
            $adminId = $kioskMode ? 0 : $sessionUserId;
            if ($adminId <= 0) {
                json_response(['ok' => false, 'message' => 'Not available.'], 400);
            }
            $schedule = get_admin_schedule($pdo, $adminId);
            // Check if custom settings exist in DB
            $isCustom = false;
            try {
                $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_schedule_settings WHERE admin_id = :aid");
                $chkStmt->execute([':aid' => $adminId]);
                $isCustom = (int)$chkStmt->fetchColumn() > 0;
            } catch (Throwable $e) {}
            json_response([
                'ok' => true,
                'am_in' => $schedule['am_in_start'],   // AM In period starts
                'am_out' => $schedule['am_in_end'],     // AM Out period starts (AM In ends)
                'pm_in' => $schedule['am_out_end'],     // PM In period starts (AM Out ends)
                'pm_out' => $schedule['pm_in_end'],     // PM Out period starts (PM In ends)
                'pm_out_end' => $schedule['pm_out_end'],// PM Out period ends (hardcoded)
                'is_custom' => $isCustom,
            ]);
        }

        if ($action === 'save_schedule_settings') {
            if ($kioskMode || $sessionUserId <= 0) {
                json_response(['ok' => false, 'message' => 'Not available.'], 400);
            }
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            $amIn = trim((string)($_POST['am_in'] ?? ''));
            $amOut = trim((string)($_POST['am_out'] ?? ''));
            $pmIn = trim((string)($_POST['pm_in'] ?? ''));
            $pmOut = trim((string)($_POST['pm_out'] ?? ''));

            if ($amIn === '' && $amOut === '' && $pmIn === '' && $pmOut === '') {
                // Clear custom settings (revert to defaults)
                $stmt = $pdo->prepare("DELETE FROM admin_schedule_settings WHERE admin_id = :aid");
                $stmt->execute([':aid' => $sessionUserId]);
                json_response(['ok' => true, 'message' => 'Schedule reset to defaults.']);
                return;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO admin_schedule_settings (admin_id, am_in, am_out, pm_in, pm_out)
                 VALUES (:aid, :am_in, :am_out, :pm_in, :pm_out)
                 ON DUPLICATE KEY UPDATE am_in = :am_in2, am_out = :am_out2, pm_in = :pm_in2, pm_out = :pm_out2"
            );
            $stmt->execute([
                ':aid' => $sessionUserId,
                ':am_in' => $amIn !== '' ? $amIn : null,
                ':am_out' => $amOut !== '' ? $amOut : null,
                ':pm_in' => $pmIn !== '' ? $pmIn : null,
                ':pm_out' => $pmOut !== '' ? $pmOut : null,
                ':am_in2' => $amIn !== '' ? $amIn : null,
                ':am_out2' => $amOut !== '' ? $amOut : null,
                ':pm_in2' => $pmIn !== '' ? $pmIn : null,
                ':pm_out2' => $pmOut !== '' ? $pmOut : null,
            ]);
            json_response(['ok' => true, 'message' => 'Schedule saved successfully.']);
        }

        if ($action === 'reset_schedule_settings') {
            if ($kioskMode || $sessionUserId <= 0) {
                json_response(['ok' => false, 'message' => 'Not available.'], 400);
            }
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            $stmt = $pdo->prepare("DELETE FROM admin_schedule_settings WHERE admin_id = :aid");
            $stmt->execute([':aid' => $sessionUserId]);
            json_response(['ok' => true, 'message' => 'Schedule reset to default times.']);
        }

        if ($action === 'get_user_schedule_settings') {
            if ($kioskMode || ($sessionUserId <= 0 && $sessionRole !== 'superadmin')) {
                json_response(['ok' => false, 'message' => 'Not available.'], 400);
            }
            $targetUserId = (int)($_GET['user_id'] ?? 0);
            if ($targetUserId <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid user.'], 400);
            }
            // Verify admin owns this user, is viewing their own schedule, or is superadmin
            if ($sessionRole !== 'superadmin') {
                $ownCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :uid AND (created_by = :aid OR id = :aid)");
                $ownCheck->execute([':uid' => $targetUserId, ':aid' => $sessionUserId]);
                if ((int)$ownCheck->fetchColumn() === 0) {
                    json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
                }
            }
            $schedule = get_user_schedule($pdo, $targetUserId);
            // Check if user has custom settings
            $hasCustom = false;
            try {
                ensure_user_schedule_schema_safe($pdo);
                $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM user_schedule_settings WHERE user_id = :uid");
                $chkStmt->execute([':uid' => $targetUserId]);
                $hasCustom = (int)$chkStmt->fetchColumn() > 0;
            } catch (Throwable $e) {}
            // Get admin schedule for comparison
            $adminSchedule = get_admin_schedule($pdo, $sessionUserId);
            json_response([
                'ok' => true,
                'has_custom' => $hasCustom,
                'am_in' => $schedule['am_in_start'],
                'am_out' => $schedule['am_in_end'],
                'pm_in' => $schedule['am_out_end'],
                'pm_out' => $schedule['pm_in_end'],
                'pm_out_end' => $schedule['pm_out_end'],
                'admin_defaults' => [
                    'am_in' => $adminSchedule['am_in_start'],
                    'am_out' => $adminSchedule['am_in_end'],
                    'pm_in' => $adminSchedule['am_out_end'],
                    'pm_out' => $adminSchedule['pm_in_end'],
                ],
            ]);
        }

        if ($action === 'save_user_schedule_settings') {
            if ($kioskMode || ($sessionUserId <= 0 && $sessionRole !== 'superadmin')) {
                json_response(['ok' => false, 'message' => 'Not available.'], 400);
            }
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            if ($targetUserId <= 0) {
                json_response(['ok' => false, 'message' => 'Invalid user.'], 400);
            }
            // Verify admin owns this user or is updating their own schedule
            $ownCheck2 = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :uid AND (created_by = :aid OR id = :aid)");
            $ownCheck2->execute([':uid' => $targetUserId, ':aid' => $sessionUserId]);
            if ((int)$ownCheck2->fetchColumn() === 0) {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }
            $amIn = trim((string)($_POST['am_in'] ?? ''));
            $amOut = trim((string)($_POST['am_out'] ?? ''));
            $pmIn = trim((string)($_POST['pm_in'] ?? ''));
            $pmOut = trim((string)($_POST['pm_out'] ?? ''));
            ensure_user_schedule_schema_safe($pdo);
            if ($amIn === '' && $amOut === '' && $pmIn === '' && $pmOut === '') {
                // Clear custom settings (revert to admin defaults)
                $stmt = $pdo->prepare("DELETE FROM user_schedule_settings WHERE user_id = :uid");
                $stmt->execute([':uid' => $targetUserId]);
                json_response(['ok' => true, 'message' => 'User schedule reset to admin defaults.']);
                return;
            }
            $stmt = $pdo->prepare(
                "INSERT INTO user_schedule_settings (user_id, am_in, am_out, pm_in, pm_out)
                 VALUES (:uid, :am_in, :am_out, :pm_in, :pm_out)
                 ON DUPLICATE KEY UPDATE am_in = :am_in2, am_out = :am_out2, pm_in = :pm_in2, pm_out = :pm_out2"
            );
            $stmt->execute([
                ':uid' => $targetUserId,
                ':am_in' => $amIn !== '' ? $amIn : null,
                ':am_out' => $amOut !== '' ? $amOut : null,
                ':pm_in' => $pmIn !== '' ? $pmIn : null,
                ':pm_out' => $pmOut !== '' ? $pmOut : null,
                ':am_in2' => $amIn !== '' ? $amIn : null,
                ':am_out2' => $amOut !== '' ? $amOut : null,
                ':pm_in2' => $pmIn !== '' ? $pmIn : null,
                ':pm_out2' => $pmOut !== '' ? $pmOut : null,
            ]);
            json_response(['ok' => true, 'message' => 'User schedule saved.']);
        }

        if ($action === 'list_users') {
            $search = trim((string)($_GET['search'] ?? ''));
            if ($kioskMode || ($sessionUserId <= 0 && $sessionRole !== 'superadmin')) {
                json_response(['ok' => false, 'message' => 'Not available.'], 400);
            }
            try {
                if ($sessionRole === 'superadmin') {
                    $sql = "SELECT id, username, name, id_number, role, position, department FROM users WHERE role != 'superadmin'";
                } else {
                    $sql = "SELECT id, username, name, id_number, role, position, department FROM users WHERE (created_by = :aid OR id = :aid)";
                }
                if ($search !== '') {
                    $sql .= " AND (name LIKE :q OR username LIKE :q OR id_number LIKE :q)";
                }
                $sql .= " ORDER BY name ASC";
                $stmt = $pdo->prepare($sql);
                if ($sessionRole !== 'superadmin') {
                    $stmt->bindValue(':aid', $sessionUserId, PDO::PARAM_INT);
                }
                if ($search !== '') {
                    $stmt->bindValue(':q', '%' . $search . '%');
                }
                $stmt->execute();
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                json_response(['ok' => true, 'users' => $users]);
            } catch (Throwable $e) {
                json_response(['ok' => false, 'message' => 'Failed to load users.'], 500);
            }
        }

        if ($action === 'list_all_schedules') {
            if ($kioskMode || ($sessionUserId <= 0 && $sessionRole !== 'superadmin')) {
                json_response(['ok' => false, 'message' => 'Not available.'], 400);
            }
            try {
                ensure_user_schedule_schema_safe($pdo);
                if ($sessionRole === 'superadmin') {
                    $sql = "SELECT u.id, u.username, u.name, u.id_number, u.role, u.position, u.department,
                                   (us.am_in IS NOT NULL OR us.am_out IS NOT NULL OR us.pm_in IS NOT NULL OR us.pm_out IS NOT NULL) AS has_custom
                            FROM users u
                            LEFT JOIN user_schedule_settings us ON us.user_id = u.id
                            WHERE u.role != 'superadmin'";
                    $stmt = $pdo->prepare($sql);
                } else {
                    $sql = "SELECT u.id, u.username, u.name, u.id_number, u.role, u.position, u.department,
                                   (us.am_in IS NOT NULL OR us.am_out IS NOT NULL OR us.pm_in IS NOT NULL OR us.pm_out IS NOT NULL) AS has_custom
                            FROM users u
                            LEFT JOIN user_schedule_settings us ON us.user_id = u.id
                            WHERE (u.created_by = :aid OR u.id = :aid)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':aid', $sessionUserId, PDO::PARAM_INT);
                }
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $adminSchedule = get_admin_schedule($pdo, $sessionUserId);
                $schedules = [];
                foreach ($rows as $r) {
                    $eff = get_user_schedule($pdo, (int)$r['id']);
                    $schedules[] = [
                        'id' => (int)$r['id'],
                        'name' => (string)($r['name'] ?? ''),
                        'username' => (string)($r['username'] ?? ''),
                        'id_number' => (string)($r['id_number'] ?? ''),
                        'role' => (string)($r['role'] ?? ''),
                        'has_custom' => ((int)($r['has_custom'] ?? 0)) === 1,
                        'am_in' => $eff['am_in_start'],
                        'am_out' => $eff['am_in_end'],
                        'pm_in' => $eff['am_out_end'],
                        'pm_out' => $eff['pm_in_end'],
                    ];
                }
                json_response([
                    'ok' => true,
                    'users' => $schedules,
                    'admin_defaults' => [
                        'am_in' => $adminSchedule['am_in_start'],
                        'am_out' => $adminSchedule['am_in_end'],
                        'pm_in' => $adminSchedule['am_out_end'],
                        'pm_out' => $adminSchedule['pm_in_end'],
                    ],
                ]);
            } catch (Throwable $e) {
                json_response(['ok' => false, 'message' => 'Failed to load schedules.'], 500);
            }
        }

        json_response(['ok' => false, 'message' => 'Unknown action.'], 400);
    }
    catch (Throwable $e) {
        json_response(['ok' => false, 'message' => 'Server error.'], 500);
    }
}

include __DIR__ . '/includes/header.php';
ob_start();
ctr_cookie_consent_banner();
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1">Attendance Monitoring</h4>
            <div class="text-muted" id="attendanceUpdatedAt"></div>
        </div>
        <?php if ($loggedIn && ($attendanceRole === 'admin' || $attendanceRole === 'superadmin')): ?>
        <div class="d-flex gap-2 align-items-center">
            <button type="button" class="btn btn-outline-primary btn-sm" id="openScheduleSettingsBtn">
                <i class="feather-clock me-1"></i>My Schedule
            </button>
            <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" id="userScheduleDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="feather-user me-1"></i>User Schedule
                </button>
                <div class="dropdown-menu dropdown-menu-end" id="userScheduleUserList" style="min-width: 220px;">
                    <div class="px-2 py-2 border-bottom" style="position: sticky; top: 0; background: #fff; z-index: 2;">
                        <input type="search" class="form-control form-control-sm" id="userScheduleSearch" placeholder="Search users..." autocomplete="off">
                    </div>
                    <div id="userScheduleUsers" style="max-height: 260px; overflow-y: auto;">
                        <div class="dropdown-item text-muted small" id="userScheduleLoading">Loading users...</div>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-outline-success btn-sm" id="openAllSchedulesBtn">
                <i class="feather-list me-1"></i>All Schedules
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm" id="openAttendanceRecordsBtn">
                <i class="feather-file-text me-1"></i>Attendance Records
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['admin_needs_plan_request'])): ?>
    <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
        <i class="feather-alert-triangle me-2 fs-4"></i>
        <div class="flex-grow-1">
            <strong>Plan Required</strong><br>
            <span>Your account needs an active plan to access all features. Please contact the Superadmin to request a plan activation.</span>
        </div>
        <button type="button" id="adminPlanExtendBtn" class="btn btn-warning btn-sm ms-3">
            <i class="feather-mail me-1"></i>Request Plan
        </button>
    </div>
    <?php endif; ?>

    <?php if ($attendanceRole !== 'superadmin'): ?>
    <div class="d-flex justify-content-between align-items-center mb-2 gap-3 flex-wrap" id="attendanceControls">
        <div id="attendanceLengthContainer" class="ctr-ext-length">
            <label class="form-label mb-0 small text-muted">
                Show
                <select id="attendanceLengthSelect" class="form-select form-select-sm d-inline-block w-auto mx-1">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                entries
            </label>
        </div>
        <div id="attendanceSearchContainer" class="ctr-ext-search">
            <label class="form-label mb-0 small text-muted">
                Search:
                <input type="search" id="attendanceSearchInput" class="form-control form-control-sm d-inline-block w-auto ms-1" placeholder="">
            </label>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <div class="card" style="position:relative;">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table align-middle" id="attendanceTable" data-session-role="<?php echo htmlspecialchars((string)$attendanceRole, ENT_QUOTES, 'UTF-8'); ?>">
                            <thead>
                                <tr>
                                    <th style="width: 160px;">QR Code ID</th>
                                    <th>Full Name</th>
                                    <th style="width: 90px;">Photo</th>
                                    <th>Job Title</th>
                                    <th style="width: 130px;">Date</th>
                                    <th style="width: 120px;">Time In (AM)</th>
                                    <th style="width: 120px;">Time Out (AM)</th>
                                    <th style="width: 120px;">Time In (PM)</th>
                                    <th style="width: 120px;">Time Out (PM)</th>
                                    <th style="width: 120px;">Status</th>
                                    <th style="width: 140px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="11" class="text-center text-muted py-4">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                        <div class="text-muted small" id="attendancePageInfo">Loading...</div>
                        <nav aria-label="Attendance pagination" id="attendancePaginationNav">
                            <ul class="pagination pagination-sm mb-0" id="attendancePagination"></ul>
                        </nav>
                    </div>
                    <?php if (!empty($_SESSION['admin_needs_plan_request'])): ?>
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
    </div>
</div>

<!-- Edit Attendance Modal -->
<div class="modal fade" id="editAttendanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="editAttendanceForm">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Attendance Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editUserId">
                    <input type="hidden" id="editAttendDateOrig">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Employee Name</label>
                            <input type="text" class="form-control" id="editFullName" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" id="editAttendDate" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">&nbsp;</label>
                            <div class="form-text">All times in 24-hour format (HH:MM)</div>
                        </div>
                        <div class="col-12">
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label">Time In (AM)</label>
                                    <input type="time" class="form-control" id="editAmIn">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Time Out (AM)</label>
                                    <input type="time" class="form-control" id="editAmOut">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Time In (PM)</label>
                                    <input type="time" class="form-control" id="editPmIn">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Time Out (PM)</label>
                                    <input type="time" class="form-control" id="editPmOut">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteAttendanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Attendance Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Are you sure you want to delete this attendance record?</p>
                <p class="text-muted mb-0" id="deleteAttendanceLabel"></p>
                <input type="hidden" id="deleteUserId">
                <input type="hidden" id="deleteAttendDate">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteAttendanceBtn">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Print DTR Modal -->
<div class="modal fade" id="printDtrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Print Daily Time Record (DTR)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="dtrUserId">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Employee</label>
                        <input type="text" class="form-control" id="dtrEmployeeName" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control" id="dtrDateFrom">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control" id="dtrDateTo">
                    </div>
                </div>
                <div id="dtrPreviewContainer" style="display:none;">
                    <iframe id="dtrPreviewFrame" style="width:100%;height:400px;border:1px solid #ddd;"></iframe>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="generateDtrBtn">Generate DTR</button>
                <button type="button" class="btn btn-success" id="printDtrBtn" style="display:none;">Print DTR</button>
            </div>
        </div>
    </div>
</div>

<!-- Schedule Settings Modal -->
<div class="modal fade" id="scheduleSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="feather-clock me-1"></i>Attendance Schedule
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">Set the time windows that determine how scans are classified. These apply only to your admin account.</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            <i class="feather-sunrise text-warning me-1"></i>AM In Start
                        </label>
                        <input type="time" class="form-control" id="schedAmIn" step="60">
                        <div class="form-text">Default: 05:00 — Morning IN begins</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            <i class="feather-sunset text-danger me-1"></i>AM Out Start
                        </label>
                        <input type="time" class="form-control" id="schedAmOut" step="60">
                        <div class="form-text">Default: 10:30 — Morning OUT begins (Midday)</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            <i class="feather-sunset text-primary me-1"></i>PM In Start
                        </label>
                        <input type="time" class="form-control" id="schedPmIn" step="60">
                        <div class="form-text">Default: 12:30 — Afternoon IN begins</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            <i class="feather-moon text-info me-1"></i>PM Out Start
                        </label>
                        <input type="time" class="form-control" id="schedPmOut" step="60">
                        <div class="form-text">Default: 15:00 — Afternoon OUT begins (Evening)</div>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-0 small" role="alert">
                            <i class="feather-info"></i>
                            <span>Leave empty to use system default. <strong>Clear all fields and save to revert to defaults.</strong></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary me-2" id="resetScheduleBtn">
                    <i class="feather-rotate-ccw me-1"></i>Reset to Defaults
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveScheduleBtn">
                        <i class="feather-save me-1"></i>Save Schedule
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- User Schedule Settings Modal -->
<div class="modal fade" id="userScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="feather-clock me-1"></i><span id="userScheduleModalTitle">User Schedule</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border mb-3 py-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="feather-user text-primary"></i>
                        <div>
                            <span class="fw-semibold" id="userScheduleUserName"></span>
                            <small class="text-muted ms-2" id="userScheduleUserIdNum"></small>
                        </div>
                    </div>
                </div>
                <p class="text-muted small mb-3">Set custom attendance times for this user. Leave fields empty to use your admin schedule defaults.</p>
                <input type="hidden" id="userSchedUserId" value="">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            <i class="feather-sunrise text-warning me-1"></i>AM In Start
                        </label>
                        <input type="time" class="form-control" id="userSchedAmIn" step="60">
                        <div class="form-text">Admin default: 05:00</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            <i class="feather-sunset text-danger me-1"></i>AM Out Start
                        </label>
                        <input type="time" class="form-control" id="userSchedAmOut" step="60">
                        <div class="form-text">Admin default: 10:30</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            <i class="feather-sunset text-primary me-1"></i>PM In Start
                        </label>
                        <input type="time" class="form-control" id="userSchedPmIn" step="60">
                        <div class="form-text">Admin default: 12:30</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            <i class="feather-moon text-info me-1"></i>PM Out Start
                        </label>
                        <input type="time" class="form-control" id="userSchedPmOut" step="60">
                        <div class="form-text">Admin default: 15:00</div>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-0 small" role="alert">
                            <i class="feather-info"></i>
                            <span>Leave empty to use admin schedule. <strong>Clear all fields and save to revert to admin defaults.</strong></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary me-2" id="resetUserScheduleBtn">
                    <i class="feather-rotate-ccw me-1"></i>Reset to Admin Defaults
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveUserScheduleBtn">
                        <i class="feather-save me-1"></i>Save Schedule
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- All Users Schedule Modal -->
<div class="modal fade" id="allSchedulesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="feather-list me-1"></i>All Users Schedule
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">Effective attendance times for each user. Users without a custom schedule automatically use the admin default times.</p>
                <div class="d-flex justify-content-between align-items-center mb-2 gap-3 flex-wrap">
                    <div>
                        <label class="form-label mb-0 small text-muted">
                            Show
                            <select id="allSchedLengthSelect" class="form-select form-select-sm d-inline-block w-auto mx-1">
                                <option value="5">5</option>
                                <option value="10" selected>10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                            entries
                        </label>
                    </div>
                    <div>
                        <label class="form-label mb-0 small text-muted">
                            Search:
                            <input type="search" id="allSchedSearchInput" class="form-control form-control-sm d-inline-block w-auto ms-1" placeholder="">
                        </label>
                    </div>
                </div>
                <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
                    <table class="table table-sm align-middle mb-0" id="allSchedulesTable">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>AM In</th>
                                <th>AM Out</th>
                                <th>PM In</th>
                                <th>PM Out</th>
                                <th>Source</th>
                            </tr>
                        </thead>
                        <tbody id="allSchedulesTbody">
                            <tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                    <div class="text-muted small" id="allSchedPageInfo">Loading...</div>
                    <nav aria-label="All schedules pagination">
                        <ul class="pagination pagination-sm mb-0" id="allSchedPagination"></ul>
                    </nav>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Attendance Records Modal -->
<div class="modal fade" id="attendanceRecordsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="feather-file-text me-1"></i>Attendance Records
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label mb-1">Month</label>
                        <input type="month" class="form-control" id="attRecordsMonth">
                    </div>
                    <div class="col-12 col-md-auto d-flex gap-2">
                        <button type="button" class="btn btn-primary" id="attRecordsLoadBtn">
                            <i class="feather-refresh-cw me-1"></i>Load Records
                        </button>
                        <button type="button" class="btn btn-success" id="attRecordsPrintBtn">
                            <i class="feather-printer me-1"></i>Print
                        </button>
                        <div class="btn-group">
                            <button type="button" class="btn btn-info dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" id="attRecordsExportBtn">
                                <i class="feather-download me-1"></i>Export
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" id="attRecordsExportCsv"><i class="feather-file me-1"></i>Export to CSV</a></li>
                                <li><a class="dropdown-item" href="#" id="attRecordsExportXls"><i class="feather-file-text me-1"></i>Export to Excel (.xls)</a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-12 col-md text-md-end">
                        <div class="text-muted small" id="attRecordsCount"></div>
                    </div>
                </div>
                <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
                    <table class="table table-sm align-middle mb-0" id="attRecordsTable">
                        <thead>
                            <tr>
                                <th style="width: 44px;">#</th>
                                <th>Full Name</th>
                                <th>ID Number</th>
                                <th>Date</th>
                                <th>AM In</th>
                                <th>AM Out</th>
                                <th>PM In</th>
                                <th>PM Out</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="attRecordsTbody">
                            <tr><td colspan="9" class="text-center text-muted py-4">Choose a month then click Load Records.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Admin Users Logins Modal (superadmin drill-down) -->
<div class="modal fade" id="adminUsersModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="feather-users me-1"></i> User Logins — <span id="adminUsersTitle"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-muted small mb-2" id="adminUsersMeta">Loading user logins...</div>
                <div class="table-responsive" style="max-height: 60vh;">
                    <table class="table table-sm align-middle mb-0" id="adminUsersTable">
                        <thead>
                            <tr>
                                <th style="width: 60px;">Photo</th>
                                <th>User</th>
                                <th>ID Number</th>
                                <th>Date</th>
                                <th>AM In</th>
                                <th>AM Out</th>
                                <th>PM In</th>
                                <th>PM Out</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="adminUsersTbody">
                            <tr><td colspan="9" class="text-center text-muted py-4">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Image View Modal -->
<div class="modal fade" id="imageViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-transparent border-0">
            <div class="modal-body p-0 text-center position-relative">
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close" style="z-index: 1060;"></button>
                <img id="viewImageTarget" src="" class="img-fluid rounded shadow-lg" alt="Attendance Photo" style="max-height: 90vh;">
            </div>
        </div>
    </div>
</div>


<?php
$body = ob_get_clean();
$etag = ctr_etag_from($body);
ctr_handle_conditional($etag, 15);
echo $body;
?>

