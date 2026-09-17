<?php
require __DIR__ . '/includes/cache_headers.php';

$loggedIn = false;
$profileName = '';
$profileRole = '';
$profileId = '';
$kioskToken = '';
ctr_session_start();
// Home page is public-facing kiosk; allow a short edge cache to keep
// repeat scans snappy while still letting the server update quickly.
ctr_cache_headers('short', 30);
$loggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$profileName = (string)($_SESSION['name'] ?? ($_SESSION['username'] ?? ''));
$profileRole = (string)($_SESSION['role'] ?? '');
$profileId = (string)($_SESSION['id_number'] ?? '');
$homeAdminId = 0;
$homePlanExpiresAt = '';

require __DIR__ . '/config/db.php';
function ensure_admin_home_title_schema_safe(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        $exists = $stmt ? $stmt->fetchColumn() : false;
        if (!$exists) {
            return;
        }
        $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
        $existing = [];
        foreach ($cols as $col) {
            $existing[strtolower((string)($col['Field'] ?? ''))] = true;
        }
        if (!isset($existing['admin_home_title'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN admin_home_title VARCHAR(255) NULL");
        }
    } catch (Throwable $e) {
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
try { $kioskToken = get_kiosk_token($pdo); } catch (Throwable $e) { $kioskToken = ''; }
ensure_admin_home_title_schema_safe($pdo);

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

function deny_home_key(string $message, int $status = 403): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
    $baseHref = $basePath === '' ? '/' : $basePath . '/';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access denied</title>
    <base href="<?php echo htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="icon" type="image/png" href="/contracs/assets/images/favicon.png?v=<?php echo @filemtime(__DIR__ . '/assets/images/favicon.png'); ?>">
    <link rel="stylesheet" type="text/css" href="/contracs/assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="/contracs/assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="/contracs/assets/css/theme.min.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center min-vh-100">
    <main class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6">
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center p-5">
                        <img src="/contracs/assets/images/contracs.png" alt="Logo" class="img-fluid mb-3" style="max-width: 96px;">
                        <h1 class="display-4 fw-bold mb-2"><?php echo (int)$status; ?></h1>
                        <h4 class="fw-semibold mb-3">Access denied</h4>
                        <p class="text-muted mb-4"><?php echo $m; ?></p>
                        <a href="index" class="btn btn-primary">Back to Index</a>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
<?php
    exit;
}

$homeKey = trim((string)($_GET['home_key'] ?? ''));
$isSuperadmin = (string)($_SESSION['role'] ?? '') === 'superadmin' && $loggedIn;

if ($homeKey === '' && !$isSuperadmin) {
    deny_home_key('Direct access to this page is not allowed. Please use the unique link provided in your plan.', 403);
}

if ($homeKey !== '' && !$isSuperadmin) {
    try {
        ensure_admin_home_links_schema_safe($pdo);
        $stmt = $pdo->prepare(
            "SELECT l.id, l.admin_id, l.plan_expires_at, l.revoked_at, l.device_id,
                    u.role, u.name, u.id_number, u.is_active, u.plan_expires_at AS user_plan_expires_at
             FROM admin_home_links l
             JOIN users u ON u.id = l.admin_id
             WHERE l.token = :t
             LIMIT 1"
        );
        $stmt->execute([':t' => $homeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            deny_home_key('Invalid link.');
        }
        if ($row['revoked_at'] !== null && trim((string)$row['revoked_at']) !== '') {
            deny_home_key('This link is no longer valid.');
        }
        if ((string)($row['role'] ?? '') !== 'admin') {
            deny_home_key('This link is not valid for this account.');
        }
        // Track inactive status but allow access
        $homeAdminIsActive = (int)($row['is_active'] ?? 0) === 1;

        $linkExp = trim((string)($row['plan_expires_at'] ?? ''));
        $userExp = trim((string)($row['user_plan_expires_at'] ?? ''));
        if ($linkExp === '' || $userExp === '' || $linkExp !== $userExp) {
            deny_home_key('This link does not match the current plan.');
        }
        $homePlanExpiresAt = $linkExp;

        $tz = new DateTimeZone('Asia/Manila');
        $now = new DateTimeImmutable('now', $tz);
        try {
            $exp = new DateTimeImmutable($linkExp, $tz);
            if ($exp <= $now) {
                deny_home_key('Your plan has expired.');
            }
        } catch (Throwable $e) {
            deny_home_key('Invalid plan expiry date.');
        }

        $cookieName = 'ctr_home_device';
        $deviceCookie = trim((string)($_COOKIE[$cookieName] ?? ''));
        if ($deviceCookie !== '' && !preg_match('/^[a-f0-9]{32,128}$/i', $deviceCookie)) {
            $deviceCookie = '';
        }

        $dbDevice = trim((string)($row['device_id'] ?? ''));
        if ($dbDevice === '') {
            if ($deviceCookie === '') {
                $deviceCookie = bin2hex(random_bytes(16));
            }
            $expTs = 0;
            try { $expTs = (new DateTimeImmutable($linkExp, $tz))->getTimestamp(); } catch (Throwable $e) { $expTs = time() + (30 * 24 * 60 * 60); }
            setcookie($cookieName, $deviceCookie, [
                'expires' => $expTs,
                'path' => '/',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            $stmt = $pdo->prepare("UPDATE admin_home_links SET device_id = :d, device_bound_at = NOW() WHERE id = :id AND (device_id IS NULL OR device_id = '')");
            $stmt->execute([':d' => $deviceCookie, ':id' => (int)($row['id'] ?? 0)]);
            $dbDevice = $deviceCookie;
        } else {
            if ($deviceCookie === '' || !hash_equals($dbDevice, $deviceCookie)) {
                deny_home_key('This link is already locked to another device.');
            }
        }

        $stmt = $pdo->prepare("UPDATE admin_home_links SET last_seen_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => (int)($row['id'] ?? 0)]);

        $homeAdminId = (int)($row['admin_id'] ?? 0);
        $loggedIn = true;
        $profileName = (string)($row['name'] ?? '');
        $profileRole = 'admin';
        $profileId = (string)($row['id_number'] ?? '');
    } catch (Throwable $e) {
        deny_home_key('Server error.');
    }
}

$themeBase = (string)($_COOKIE['ctr_theme_base'] ?? '');
$themeBase = trim($themeBase);
if ($themeBase !== '' && $themeBase[0] !== '#') {
    $themeBase = '#' . $themeBase;
}
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $themeBase)) {
    $themeBase = '#22C55E';
}
$themeBase = strtoupper($themeBase);
$homeHeaderTitle = 'SCHOOLS DIVISION OFFICE OF KABANKALAN CITY';
$homeTitleAdminId = 0;
if ($profileRole === 'admin') {
    $homeTitleAdminId = $homeAdminId > 0 ? $homeAdminId : $sessionUserId;
}
if ($homeTitleAdminId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT admin_home_title FROM users WHERE id = :id AND role = 'admin' LIMIT 1");
        $stmt->execute([':id' => $homeTitleAdminId]);
        $savedHomeTitle = trim((string)($stmt->fetchColumn() ?: ''));
        if ($savedHomeTitle !== '') {
            $homeHeaderTitle = $savedHomeTitle;
        }
    } catch (Throwable $e) {
    }
}
ob_start();
ctr_cookie_consent_banner();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="theme-color" content="<?php echo htmlspecialchars($themeBase, ENT_QUOTES, 'UTF-8'); ?>" />
    <title>ConTracS | Home</title>
    <link rel="icon" type="image/png" href="/contracs/assets/images/favicon.png?v=<?php echo @filemtime(__DIR__ . '/assets/images/favicon.png'); ?>" />
    <link rel="manifest" href="/contracs/assets/manifest.json" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="/contracs/assets/css/inter.css" rel="stylesheet" />
    <link rel="stylesheet" type="text/css" href="/contracs/assets/css/bootstrap.min.css" />
    <link rel="stylesheet" type="text/css" href="/contracs/assets/vendors/css/vendors.min.css" />
    <style>
        :root {
            color-scheme: dark;
            font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            --ctr-theme-base: <?php echo htmlspecialchars($themeBase, ENT_QUOTES, 'UTF-8'); ?>;
            --ctr-theme-base-light: <?php echo htmlspecialchars($themeBase, ENT_QUOTES, 'UTF-8'); ?>;
            --ctr-theme-base-rgb: <?php
                $hex = ltrim(strtoupper($themeBase), '#');
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                echo "$r, $g, $b";
            ?>;
            --ctr-bg: #0F172A;
            --ctr-card: #111827;
            --ctr-card-2: #1E293B;
            --ctr-border: rgba(148, 163, 184, 0.16);
            --ctr-text: rgba(248, 250, 252, 0.92);
            --ctr-muted: rgba(226, 232, 240, 0.70);
            --ctr-shadow: 0 14px 40px rgba(2, 6, 23, 0.50);
            --ctr-shadow-soft: 0 10px 28px rgba(2, 6, 23, 0.28);
            --ctr-danger: #DC2626;
            --ctr-warning: #F59E0B;
            --ctr-success: #16A34A;
            --ctr-radius: 16px;
            --ctr-gap: clamp(10px, 1.15vw, 14px);
            --ctr-gap-sm: clamp(8px, 0.9vw, 12px);
            --ctr-pad: clamp(10px, 1.2vw, 16px);
        }

        :root[data-theme="light"] {
            color-scheme: light;
            --ctr-bg: #F8FAFC;
            --ctr-card: #FFFFFF;
            --ctr-card-2: rgba(255, 255, 255, 0.92);
            --ctr-border: rgba(15, 23, 42, 0.10);
            --ctr-text: rgba(15, 23, 42, 0.92);
            --ctr-muted: rgba(15, 23, 42, 0.64);
            --ctr-shadow: 0 16px 44px rgba(15, 23, 42, 0.10);
            --ctr-shadow-soft: 0 10px 28px rgba(15, 23, 42, 0.08);
        }

        :root[data-theme="dark"] {
            color-scheme: dark;
        }

        :root[data-theme="light"] body::before {
            background:
                radial-gradient(ellipse 1200px 680px at 12% -8%, rgba(34, 197, 94, 0.10), transparent 60%),
                radial-gradient(ellipse 900px 520px at 92% 8%, rgba(56, 189, 248, 0.07), transparent 55%),
                radial-gradient(ellipse 700px 400px at 50% 50%, rgba(168, 85, 247, 0.04), transparent 50%);
        }

        html,
        body {
            width: 100%;
            height: 100%;
        }

        body {
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            overflow: hidden;
            line-height: 1.3;
            background: var(--ctr-bg);
            color: var(--ctr-text);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        body::before {
            content: "";
            position: fixed;
            inset: -50%;
            width: 200%;
            height: 200%;
            z-index: -1;
            background:
                radial-gradient(ellipse 800px 600px at 15% 15%, rgba(34, 197, 94, 0.18), transparent 55%),
                radial-gradient(ellipse 600px 500px at 85% 20%, rgba(56, 189, 248, 0.12), transparent 50%),
                radial-gradient(ellipse 700px 550px at 50% 80%, rgba(168, 85, 247, 0.08), transparent 50%);
            animation: ctrBgDrift 20s ease-in-out infinite alternate;
        }

        @keyframes ctrBgDrift {
            0%   { transform: translate(0%, 0%); }
            100% { transform: translate(-2%, -3%); }
        }

        /* Custom scrollbar for dark/light mode */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.24);
            border-radius: 999px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.40);
        }

        :root[data-theme="light"] ::-webkit-scrollbar-thumb {
            background: rgba(15, 23, 42, 0.16);
        }

        :root[data-theme="light"] ::-webkit-scrollbar-thumb:hover {
            background: rgba(15, 23, 42, 0.30);
        }

        /* Scrollbar Firefox */
        * {
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.24) transparent;
        }

        :root[data-theme="light"] * {
            scrollbar-color: rgba(15, 23, 42, 0.16) transparent;
        }

        /* Skeleton loading animation */
        @keyframes ctrSkeletonPulse {
            0% { opacity: 0.6; }
            50% { opacity: 1; }
            100% { opacity: 0.6; }
        }

        .home-skeleton {
            background: rgba(148, 163, 184, 0.10);
            border-radius: 8px;
            animation: ctrSkeletonPulse 1.8s ease-in-out infinite;
        }

        :root[data-theme="light"] .home-skeleton {
            background: rgba(15, 23, 42, 0.06);
        }

        .home-skeleton-item {
            display: grid;
            grid-template-columns: 60px 1fr;
            gap: 12px;
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 12px;
            border: 1px solid var(--ctr-border);
        }

        .home-skeleton-item .s-thumb {
            width: 60px;
            height: 60px;
            border-radius: 8px;
        }

        .home-skeleton-item .s-lines {
            display: flex;
            flex-direction: column;
            gap: 8px;
            justify-content: center;
        }

        .home-skeleton-item .s-line {
            height: 14px;
            border-radius: 6px;
        }

        .home-skeleton-item .s-line:first-child {
            width: 75%;
        }

        .home-skeleton-item .s-line:nth-child(2) {
            width: 50%;
        }

        .home-skeleton-item .s-line:nth-child(3) {
            width: 60%;
            height: 10px;
        }

        .home-wrap {
            background: transparent;
            padding: 0;
            height: 100vh;
            width: 100vw;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .home-header {
            background: linear-gradient(90deg, rgba(2, 6, 23, 0.55), rgba(2, 6, 23, 0.32));
            backdrop-filter: blur(24px) saturate(1.6);
            -webkit-backdrop-filter: blur(24px) saturate(1.6);
            color: var(--ctr-text);
            padding: var(--ctr-pad) clamp(12px, 1.5vw, 20px);
            border-radius: 0;
            border-bottom: 1px solid transparent;
            background-clip: padding-box;
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.15);
            transition: background 300ms ease, box-shadow 300ms ease;
            position: relative;
        }



        .home-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--ctr-gap);
            margin-bottom: 0;
        }

        .home-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .home-header-title {
            font-weight: 900;
            font-size: clamp(14px, 1.75vw, 19px);
            letter-spacing: 0.6px;
            background: linear-gradient(135deg, var(--ctr-theme-base, #22C55E), rgba(255, 255, 255, 0.95) 50%, var(--ctr-theme-base, #22C55E));
            background-size: 200% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: ctrTitleShimmer 4s ease-in-out infinite alternate;
        }

        @keyframes ctrTitleShimmer {
            0%   { background-position: 0% center; }
            100% { background-position: 200% center; }
        }

        .home-header-icons {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .home-tab {
            height: 34px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.10);
            color: inherit;
            font-weight: 900;
            cursor: pointer;
            transition: transform 300ms ease, background-color 300ms ease, border-color 300ms ease, box-shadow 300ms ease;
        }

        :root[data-theme="light"] .home-tab {
            border-color: rgba(15, 23, 42, 0.14);
            background: rgba(255, 255, 255, 0.70);
        }

        .home-tab:hover {
            transform: translateY(-1px);
            background: rgba(255, 255, 255, 0.16);
        }

        .home-tab:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.24);
        }

        .home-theme-switch {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.10);
            user-select: none;
        }

        :root[data-theme="light"] .home-theme-switch {
            border-color: rgba(15, 23, 42, 0.14);
            background: rgba(255, 255, 255, 0.70);
        }

        .home-theme-switch input {
            width: 44px;
            height: 24px;
            -webkit-appearance: none;
            appearance: none;
            background: rgba(148, 163, 184, 0.34);
            border-radius: 999px;
            position: relative;
            outline: none;
            cursor: pointer;
            border: 1px solid rgba(148, 163, 184, 0.34);
            transition: background-color 300ms ease, border-color 300ms ease;
        }

        :root[data-theme="light"] .home-theme-switch input {
            background: rgba(15, 23, 42, 0.10);
            border-color: rgba(15, 23, 42, 0.10);
        }

        .home-theme-switch input::after {
            content: "";
            position: absolute;
            top: 2px;
            left: 2px;
            width: 18px;
            height: 18px;
            border-radius: 999px;
            background: rgba(248, 250, 252, 0.92);
            box-shadow: 0 8px 20px rgba(2, 6, 23, 0.45);
            transition: transform 300ms ease, background-color 300ms ease;
        }

        :root[data-theme="light"] .home-theme-switch input::after {
            background: rgba(15, 23, 42, 0.84);
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.18);
        }

        .home-theme-switch input:checked {
            background: rgba(34, 197, 94, 0.56);
            border-color: rgba(34, 197, 94, 0.56);
        }

        .home-theme-switch input:checked::after {
            transform: translateX(20px) rotate(180deg);
        }

        .home-theme-label {
            font-weight: 900;
            font-size: 12px;
            letter-spacing: 0.3px;
            color: var(--ctr-text);
            opacity: 0.92;
        }

        .home-skip-link {
            position: absolute;
            left: 8px;
            top: 8px;
            z-index: 10000;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.92);
            color: var(--ctr-text);
            font-weight: 900;
            text-decoration: none;
            transform: translateY(-140%);
            transition: transform 180ms ease;
        }

        .home-skip-link:focus {
            transform: translateY(0);
            outline: none;
            box-shadow: 0 0 0 4px rgba(31, 101, 184, 0.18);
        }

        .home-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.16);
            display: grid;
            place-items: center;
            font-weight: 900;
        }

        :root[data-theme="light"] .home-icon {
            background: rgba(15, 23, 42, 0.06);
            color: rgba(15, 23, 42, 0.86);
        }

        button.home-icon {
            border: 0;
            color: inherit;
            cursor: pointer;
            padding: 0;
            font: inherit;
            transition: transform 300ms ease, background-color 300ms ease, box-shadow 300ms ease;
        }

        button.home-icon:hover {
            background: rgba(255, 255, 255, 0.24);
            transform: translateY(-2px);
            box-shadow: 0 0 0 2px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.35), 0 0 16px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.15);
        }

        :root[data-theme="light"] button.home-icon:hover {
            background: rgba(15, 23, 42, 0.10);
        }

        button.home-icon:active {
            transform: translateY(0);
            background: rgba(255, 255, 255, 0.20);
        }

        :root[data-theme="light"] button.home-icon:active {
            background: rgba(15, 23, 42, 0.12);
        }

        button.home-icon:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.40), 0 0 0 6px rgba(11, 61, 92, 0.20);
        }

        :root[data-theme="light"] .home-header {
            background: rgba(248, 250, 252, 0.78);
            backdrop-filter: blur(24px) saturate(1.5);
            -webkit-backdrop-filter: blur(24px) saturate(1.5);
            color: rgba(15, 23, 42, 0.92);
            border-bottom-color: rgba(15, 23, 42, 0.06);
            box-shadow: 0 2px 16px rgba(15, 23, 42, 0.06), 0 1px 0 rgba(56, 189, 248, 0.08);
        }

        :root[data-theme="dark"] .home-recent-item {
            border-color: rgba(148, 163, 184, 0.12);
        }

        :root[data-theme="dark"] .home-recent-item:hover {
            border-color: rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.35);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.10);
        }

        :root[data-theme="light"] .home-recent-item {
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }

        :root[data-theme="light"] .home-recent-item:hover {
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.10), 0 0 0 1px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.08);
        }

        :root[data-theme="light"] .home-video {
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08), 0 0 1px rgba(15, 23, 42, 0.12);
        }

        :root[data-theme="light"] .home-panel {
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.90), rgba(255, 255, 255, 0.98) 48%), var(--ctr-card);
        }

        :root[data-theme="light"] .home-recent-item {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.95));
        }

        :root[data-theme="light"] .home-right-top {
            background: linear-gradient(180deg, rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.05), rgba(255, 255, 255, 0.98) 46%), var(--ctr-card);
        }

        :root[data-theme="light"] .home-modal-card::before {
            background: linear-gradient(135deg, rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.18), transparent 40%, transparent 60%, rgba(56, 189, 248, 0.12));
        }

        :root[data-theme="light"] .home-scanner-controls {
            background: rgba(255, 255, 255, 0.60);
        }

        :root[data-theme="light"] .home-photo {
            border-color: rgba(15, 23, 42, 0.10);
            background: rgba(255, 255, 255, 0.92);
        }

        :root[data-theme="light"] .home-photo img {
            background: #fff;
        }

        :root[data-theme="light"] .home-modal-last {
            background: rgba(255, 255, 255, 0.85);
            border-color: rgba(15, 23, 42, 0.10);
        }

        :root[data-theme="light"] .home-modal-last-title,
        :root[data-theme="light"] .home-modal-last-name {
            color: rgba(15, 23, 42, 0.92);
        }

        :root[data-theme="light"] .home-modal-last-dept {
            color: rgba(15, 23, 42, 0.64);
        }

        :root[data-theme="light"] .home-modal-actions .ghost {
            background: rgba(15, 23, 42, 0.06);
            color: rgba(15, 23, 42, 0.88);
        }

        :root[data-theme="light"] .home-time-value:not(:empty) {
            text-shadow: 0 0 6px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.10);
        }

        :root[data-theme="light"] .home-time {
            text-shadow: 0 0 8px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.12);
        }

        .home-modal-body.home-modal-body-single {
            grid-template-columns: 1fr;
        }

        .home-profile-grid {
            display: grid;
            gap: 10px;
            padding: 8px;
        }

        .home-profile-card {
            background: linear-gradient(180deg, rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.08), transparent 46%), var(--ctr-card-2);
            border: 1px solid var(--ctr-border);
            border-radius: 14px;
            padding: 24px;
            display: grid;
            gap: 14px;
            transition: box-shadow 300ms ease, transform 300ms ease;
            text-align: center;
        }

        .home-profile-card:hover {
            box-shadow: var(--ctr-shadow), 0 0 16px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.06);
        }

        .home-profile-name {
            font-weight: 800;
            color: var(--ctr-text);
            font-size: 22px;
            line-height: 1.1;
            letter-spacing: 0.3px;
        }

        .home-profile-sub {
            color: var(--ctr-muted);
            font-weight: 700;
            font-size: 14px;
        }

        .home-profile-actions {
            display: grid;
            gap: 10px;
            margin-top: 6px;
        }

        .home-profile-actions a,
        .home-profile-actions button {
            height: 46px;
            border-radius: 12px;
            border: 0;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 15px;
            letter-spacing: 0.4px;
            transition: transform 300ms ease, box-shadow 300ms ease, background 300ms ease;
        }

        .home-profile-actions a:hover,
        .home-profile-actions button:hover {
            transform: translateY(-1px);
        }

        .home-profile-actions a:active,
        .home-profile-actions button:active {
            transform: translateY(0);
        }

        .home-profile-actions .primary {
            background: var(--ctr-theme-base, #22C55E);
            color: #fff;
        }

        :root[data-theme="light"] .home-profile-actions .primary {
            background: var(--ctr-theme-base-light, #16A34A);
        }

        .home-profile-actions .danger {
            background: rgba(220, 38, 38, 0.95);
            color: #fff;
        }

        .home-settings-grid {
            display: grid;
            gap: 12px;
            padding: 8px;
        }

        .home-settings-row {
            display: grid;
            gap: 10px;
            grid-template-columns: 1fr;
        }

        .home-settings-label {
            font-weight: 900;
            color: var(--ctr-text);
        }

        .home-check {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid var(--ctr-border);
            background: rgba(148, 163, 184, 0.06);
            font-weight: 900;
            color: var(--ctr-text);
            user-select: none;
        }

        .home-check input {
            width: 18px;
            height: 18px;
            margin: 0;
        }

        .home-settings-metrics {
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid var(--ctr-border);
            background: rgba(148, 163, 184, 0.06);
            color: var(--ctr-muted);
            font-weight: 900;
            font-size: 12px;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }

        .home-settings-swatches {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .home-swatch {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: 2px solid var(--ctr-border);
            cursor: pointer;
            background: rgba(148, 163, 184, 0.08);
            padding: 0;
            transition: transform 300ms ease, box-shadow 300ms ease, border-color 300ms ease;
        }

        .home-swatch:hover {
            transform: scale(1.15);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .home-swatch:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(11, 61, 92, 0.22);
        }

        .home-settings-controls {
            display: grid;
            gap: 10px;
            grid-template-columns: 1fr 1fr;
            align-items: center;
        }

        .home-color-input {
            width: 100%;
            height: 42px;
            border-radius: 12px;
            border: 1px solid var(--ctr-border);
            padding: 0 12px;
            font-weight: 900;
            background: rgba(148, 163, 184, 0.06);
            color: var(--ctr-text);
            outline: none;
        }

        .home-color-input:focus-visible {
            border-color: rgba(34, 197, 94, 0.55);
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.22);
        }

        .home-settings-actions {
            display: flex;
            gap: 10px;
        }

        .home-settings-actions button {
            flex: 1;
            height: 42px;
            border-radius: 12px;
            border: 0;
            font-weight: 900;
            cursor: pointer;
            transition: transform 300ms ease, box-shadow 300ms ease, background 300ms ease;
        }

        .home-settings-actions button:hover {
            transform: translateY(-1px);
        }

        .home-settings-actions button:active {
            transform: translateY(0);
        }

        .home-settings-actions .primary {
            background: var(--ctr-theme-base, #22C55E);
            color: #fff;
        }

        .home-settings-actions .ghost {
            background: rgba(148, 163, 184, 0.14);
            color: var(--ctr-text);
        }

        :root[data-theme="light"] .home-settings-actions .primary {
            background: var(--ctr-theme-base-light, #16A34A);
        }

        :root[data-theme="light"] .home-settings-actions .ghost {
            background: rgba(15, 23, 42, 0.06);
            color: rgba(15, 23, 42, 0.88);
        }

        .home-sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .home-panel {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), transparent 48%), var(--ctr-card);
            border-radius: var(--ctr-radius, 16px);
            overflow: hidden;
            border: 1px solid var(--ctr-border);
            box-shadow: var(--ctr-shadow);
            display: flex;
            flex-direction: column;
            min-height: 0;
            transition: box-shadow 300ms ease, border-color 300ms ease, transform 300ms ease;
        }

        .home-panel:hover {
            box-shadow: var(--ctr-shadow), 0 0 12px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.04);
        }

        .home-panel .panel-head {
            background: rgba(148, 163, 184, 0.06);
            padding: 12px 14px;
            border-bottom: 1px solid var(--ctr-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-height: 52px;
        }

        .home-panel-title {
            font-weight: 800;
            letter-spacing: 0.2px;
            color: var(--ctr-text);
            font-size: 14px;
            text-transform: uppercase;
            opacity: 0.95;
        }

        .home-recent {
            flex: 1;
            min-height: 0;
            overflow: auto;
            padding: var(--ctr-gap-sm);
            scrollbar-gutter: stable;
            overscroll-behavior: contain;
        }

        .home-recent-item {
            position: relative;
            background: var(--ctr-card-2);
            border: 1px solid var(--ctr-border);
            border-left: 3px solid var(--ctr-theme-base, #22C55E);
            border-radius: 14px;
            padding: clamp(10px, 1.05vw, 14px);
            margin-bottom: var(--ctr-gap-sm);
            display: grid;
            grid-template-columns: 96px 1fr;
            gap: var(--ctr-gap);
            align-items: stretch;
            transition: transform 300ms ease, box-shadow 300ms ease, border-color 300ms ease;
            animation: ctrCardFadeIn 400ms ease both;
            overflow: hidden;
        }

        @keyframes ctrCardFadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .home-recent-item:hover {
            transform: translateY(-3px);
            border-color: rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.45);
            border-left-color: var(--ctr-theme-base, #22C55E);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18), 0 0 0 1px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.12), 0 0 20px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.06);
        }

        .home-recent-thumb {
            width: 100%;
            height: 100%;
            border-radius: 10px;
            object-fit: cover;
            background: var(--ctr-card-2);
            border: 2px solid var(--ctr-border);
            box-sizing: border-box;
            cursor: pointer;
            outline: none;
            transition: transform 300ms ease, box-shadow 300ms ease, border-color 300ms ease;
        }

        .home-recent-thumb:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 22px rgba(0, 0, 0, 0.12);
        }

        .home-recent-thumb:focus-visible {
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.55), 0 0 0 6px rgba(31, 101, 184, 0.22);
        }

        .home-recent-main {
            min-width: 0;
        }

        .home-recent-name {
            font-weight: 900;
            color: var(--ctr-text);
            font-size: clamp(18px, 2.0vw, 28px);
            line-height: 1.05;
            letter-spacing: 0.2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .home-recent-sub {
            font-size: clamp(14px, 1.4vw, 20px);
            color: var(--ctr-muted);
            font-style: italic;
            font-weight: 800;
            opacity: 0.95;
            margin-top: 4px;
            margin-bottom: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .home-times {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid var(--ctr-border);
        }

        .home-time-cell {
            min-width: 0;
        }

        .home-time-label {
            background: rgba(148, 163, 184, 0.12);
            color: var(--ctr-muted);
            font-weight: 900;
            padding: 6px 8px 4px;
            font-size: 13px;
            text-align: center;
            border-right: 1px solid var(--ctr-border);
            white-space: nowrap;
        }

        .home-time-value {
            background: rgba(2, 6, 23, 0.30);
            color: var(--ctr-text);
            font-weight: 900;
            padding: 8px 8px;
            font-size: clamp(18px, 2.1vw, 26px);
            text-align: center;
            border-right: 1px solid var(--ctr-border);
            letter-spacing: 0.3px;
            transition: background 300ms ease, color 300ms ease, text-shadow 300ms ease;
        }

        .home-time-value:not(:empty):not(:has(+ .home-time-value:empty)) {
            text-shadow: 0 0 12px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.30);
        }

        :root[data-theme="light"] .home-time-value {
            background: rgba(15, 23, 42, 0.04);
            color: rgba(15, 23, 42, 0.92);
        }

        :root[data-theme="light"] .home-time-value:not(:empty) {
            text-shadow: 0 0 8px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.15);
        }

        .home-times .home-time-cell:last-child .home-time-label,
        .home-times .home-time-cell:last-child .home-time-value {
            border-right: 0;
        }

        .home-recent-ribbon {
            position: absolute;
            top: 10px;
            right: 10px;
            background: linear-gradient(135deg, #DC2626, #EF4444);
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1px;
            padding: 3px 8px;
            border-radius: 6px;
            filter: drop-shadow(0 2px 6px rgba(220, 38, 38, 0.45));
            animation: ctrLivePulse 2s ease-in-out infinite;
            z-index: 2;
            text-transform: uppercase;
        }

        @keyframes ctrLivePulse {
            0%, 100% { box-shadow: 0 0 8px rgba(220, 38, 38, 0.30); }
            50%      { box-shadow: 0 0 18px rgba(220, 38, 38, 0.55); }
        }

        .home-clock-bar {
            background: linear-gradient(90deg, rgba(2, 6, 23, 0.60), rgba(2, 6, 23, 0.40));
            backdrop-filter: blur(20px) saturate(1.5);
            -webkit-backdrop-filter: blur(20px) saturate(1.5);
            border-bottom: 1px solid var(--ctr-border);
            padding: clamp(8px, 1vw, 14px) clamp(12px, 1.5vw, 20px);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: clamp(16px, 3vw, 40px);
            flex-shrink: 0;
        }

        :root[data-theme="light"] .home-clock-bar {
            background: rgba(248, 250, 252, 0.80);
            backdrop-filter: blur(20px) saturate(1.4);
            -webkit-backdrop-filter: blur(20px) saturate(1.4);
        }

        .home-clock-time {
            font-weight: 800;
            font-size: clamp(28px, 4vw, 52px);
            letter-spacing: 2px;
            color: var(--ctr-text);
            text-shadow: 0 0 20px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.20);
            line-height: 1;
        }

        :root[data-theme="light"] .home-clock-time {
            text-shadow: 0 0 12px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.12);
        }

        .home-clock-divider {
            width: 1px;
            height: 40px;
            background: var(--ctr-border);
            flex-shrink: 0;
        }

        .home-clock-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .home-clock-date {
            font-weight: 900;
            font-size: clamp(13px, 1.4vw, 17px);
            color: var(--ctr-text);
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .home-clock-tz {
            font-weight: 800;
            font-size: clamp(11px, 1.1vw, 13px);
            color: var(--ctr-muted);
        }

        .home-video {
            background: rgba(2, 6, 23, 0.66);
            border-radius: 14px;
            overflow: hidden;
            border: 2px solid rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.20);
            box-shadow: var(--ctr-shadow-soft), 0 0 20px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.08);
            transition: border-color 300ms ease, box-shadow 300ms ease;
            position: relative;
        }

        .home-video.is-scanning {
            border-color: transparent;
            background: rgba(2, 6, 23, 0.66);
            box-shadow: var(--ctr-shadow-soft), 0 0 30px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.20), 0 0 60px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.08);
            animation: ctrGlowPulse 2s ease-in-out infinite;
        }

        @keyframes ctrGlowPulse {
            0%, 100% { box-shadow: var(--ctr-shadow-soft), 0 0 20px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.15), 0 0 40px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.05); }
            50%      { box-shadow: var(--ctr-shadow-soft), 0 0 35px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.30), 0 0 70px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.10); }
        }

        .home-video .video-box {
            width: 100%;
            height: 260px;
            display: grid;
            place-items: stretch;
            color: rgba(255, 255, 255, 0.88);
            font-weight: 900;
            letter-spacing: 0.3px;
            position: relative;
            overflow: hidden;
        }

        .home-video .video-box::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, transparent 0%, rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.08) 50%, transparent 100%);
            height: 40%;
            animation: ctrScanLine 2.5s ease-in-out infinite;
            pointer-events: none;
            z-index: 2;
            opacity: 0;
            transition: opacity 300ms ease;
        }

        .home-video.is-scanning .video-box::after {
            opacity: 1;
        }

        @keyframes ctrScanLine {
            0%   { top: -40%; }
            100% { top: 100%; }
        }

        .home-video video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            background: #111;
        }

        .home-loading {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            background: rgba(0, 0, 0, 0.28);
            opacity: 0;
            pointer-events: none;
            z-index: 5;
            transition: opacity 300ms ease;
        }

        .home-loading.is-on {
            opacity: 1;
        }

        .home-spinner {
            width: 44px;
            height: 44px;
            border-radius: 999px;
            border: 4px solid rgba(255, 255, 255, 0.15);
            border-top-color: var(--ctr-theme-base, #22C55E);
            animation: homeSpin 800ms linear infinite;
        }

        @keyframes homeSpin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .home-btn.scanning {
            background: transparent !important;
            border: 2px solid var(--ctr-danger, #DC2626) !important;
            color: var(--ctr-danger, #DC2626) !important;
            box-shadow: 0 0 16px rgba(220, 38, 38, 0.15);
            animation: ctrDangerPulse 2s ease-in-out infinite;
        }

        .home-btn.scanning:hover {
            background: rgba(220, 38, 38, 0.08) !important;
            box-shadow: 0 0 24px rgba(220, 38, 38, 0.35);
        }

        @keyframes ctrDangerPulse {
            0%, 100% { box-shadow: 0 0 16px rgba(220, 38, 38, 0.15); }
            50%      { box-shadow: 0 0 28px rgba(220, 38, 38, 0.30); }
        }

        .home-scan-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding: 10px;
            box-sizing: border-box;
            pointer-events: none;
            z-index: 3;
        }

        .home-face-tracker {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 2;
        }

        .home-face-tracker canvas {
            width: 100%;
            height: 100%;
        }

        .home-scan-notify-wrap {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            padding: 14px;
            box-sizing: border-box;
            pointer-events: none;
            z-index: 4;
        }

        .home-scan-notify {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            max-width: min(520px, 88%);
            padding: 14px 16px;
            border-radius: 14px;
            color: #fff;
            font-weight: 900;
            box-shadow: 0 16px 34px rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.22);
            opacity: 0;
            transform: translateY(10px) scale(0.96);
            transition: opacity 300ms ease, transform 400ms ease;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .home-scan-notify.is-show {
            opacity: 1;
            transform: translateY(0) scale(1);
        }

        .home-scan-notify.is-success { background: rgba(22, 163, 74, 0.95); }
        .home-scan-notify.is-error { background: rgba(220, 38, 38, 0.95); }
        .home-scan-notify.is-warning { background: rgba(245, 158, 11, 0.95); }

        .home-scan-notify-icon {
            width: 18px;
            height: 18px;
            flex: 0 0 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .home-scan-notify-icon svg {
            display: none;
        }

        .home-scan-notify.is-success .home-scan-notify-icon .icon-success,
        .home-scan-notify.is-error .home-scan-notify-icon .icon-error,
        .home-scan-notify.is-warning .home-scan-notify-icon .icon-warning {
            display: block;
        }

        .home-scan-notify-text {
            min-width: 0;
            overflow-wrap: anywhere;
            line-height: 1.25;
            font-size: 14px;
        }

        .home-scan-pill {
            background: rgba(2, 6, 23, 0.70);
            color: rgba(248, 250, 252, 0.92);
            border: 1px solid rgba(148, 163, 184, 0.22);
            padding: 10px 12px;
            border-radius: 12px;
            font-weight: 900;
            font-size: 13px;
            letter-spacing: 0.2px;
            text-align: center;
            max-width: 92%;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        :root[data-theme="light"] .home-scan-pill {
            background: rgba(255, 255, 255, 0.82);
            color: rgba(15, 23, 42, 0.86);
            border-color: rgba(15, 23, 42, 0.10);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        .home-muted {
            color: var(--ctr-muted);
            font-weight: 800;
        }

        .home-select {
            width: 100%;
            height: 44px;
            border-radius: 12px;
            border: 1px solid var(--ctr-border);
            padding: 0 12px;
            background: rgba(148, 163, 184, 0.06);
            color: var(--ctr-text);
            outline: none;
            transition: border-color 300ms ease, box-shadow 300ms ease;
        }

        .home-select:focus {
            border-color: var(--ctr-theme-base, #22C55E);
            box-shadow: 0 0 0 3px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.15);
        }

        :root[data-theme="light"] .home-select {
            background: #fff;
            color: rgba(15, 23, 42, 0.92);
        }

        :root[data-theme="dark"] .home-select option,
        :root[data-theme="dark"] .home-select optgroup {
            background-color: #0f172a;
            color: #ffffff;
        }

        .home-photo {
            border-radius: 0;
            overflow: hidden;
            border: 1px solid var(--ctr-border);
            background: var(--ctr-card-2);
            flex: 1;
            min-height: 0;
        }

        .home-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
            background: var(--ctr-card);
            cursor: pointer;
            outline: none;
            transition: transform 300ms ease, box-shadow 300ms ease;
        }

        .home-photo img:hover {
            transform: scale(1.01);
        }

        .home-photo img:focus-visible {
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.55), 0 0 0 6px rgba(31, 101, 184, 0.22);
        }

        .home-right-top {
            background: linear-gradient(180deg, rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.08), transparent 46%), var(--ctr-card);
            border-radius: var(--ctr-radius, 16px);
            border: 1px solid var(--ctr-border);
            box-shadow: var(--ctr-shadow);
            padding: 14px;
            min-height: 290px;
            margin-bottom: 8px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            transition: box-shadow 300ms ease, transform 300ms ease;
            position: relative;
            overflow: hidden;
        }

        .home-right-top::before {
            content: "";
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 50% 0%, rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.04), transparent 60%);
            pointer-events: none;
        }

        .home-right-top:hover {
            box-shadow: var(--ctr-shadow), 0 0 20px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.06);
            transform: translateY(-1px);
        }

        .home-right-top > * {
            position: relative;
        }

        .home-header-logo {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: #fff;
            padding: 2px;
        }

        .home-plan-banner {
            grid-column: 1 / -1;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(245, 158, 11, 0.05));
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: var(--ctr-radius);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .home-plan-banner-icon {
            font-size: 32px;
        }

        .home-plan-banner-text {
            flex-grow: 1;
        }

        .home-plan-banner-title {
            font-weight: 700;
            font-size: 16px;
            color: var(--ctr-warning);
            margin-bottom: 4px;
        }

        .home-plan-banner-desc {
            color: var(--ctr-muted);
            font-size: 14px;
        }

        .home-btn.warning {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px;
            background: var(--ctr-warning);
            color: #000;
            font-weight: 600;
            font-size: 13px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .home-logo-text {
            font-weight: 800;
            letter-spacing: 0.4px;
            font-size: 30px;
            line-height: 1;
            color: var(--ctr-theme-base, #22C55E);
            margin-bottom: 10px;
        }

        :root[data-theme="light"] .home-logo-text {
            color: var(--ctr-theme-base-light, #16A34A);
        }

        .home-brand {
            width: 140px;
            height: auto;
            margin-bottom: 8px;
        }

        .home-subtitle {
            font-weight: 700;
            color: var(--ctr-text);
            font-size: 16px;
            opacity: 0.92;
        }

        .home-version {
            font-weight: 700;
            color: var(--ctr-text);
            font-size: 18px;
            opacity: 0.85;
            margin-top: 4px;
            margin-bottom: 2px;
        }

        .home-date,
        .home-time {
            color: var(--ctr-muted);
            font-weight: 700;
            margin-top: 10px;
        }

        .home-time {
            margin-top: 4px;
            font-weight: 800;
            color: var(--ctr-text);
            font-size: 18px;
            text-shadow: 0 0 10px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.25);
            letter-spacing: 0.5px;
        }

        .home-status {
            color: var(--ctr-muted);
            font-weight: 800;
            margin-top: 10px;
        }

        .home-right-bottom {
            background: var(--ctr-card);
            border-radius: var(--ctr-radius, 16px);
            border: 1px solid var(--ctr-border);
            box-shadow: var(--ctr-shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 0;
            flex: 1;
        }

        .home-right-bottom .title {
            color: var(--ctr-text);
            font-weight: 900;
            padding: 10px 14px;
            border-bottom: 1px solid var(--ctr-border);
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: 0.3px;
        }

        .home-right-bottom .title::before {
            content: "⏱";
            font-size: 14px;
            opacity: 0.8;
        }

        .home-right-bottom .body {
            background: var(--ctr-card-2);
            padding: 14px;
            flex: 1;
            min-height: 0;
            overflow: auto;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            gap: 18px;
        }

        .home-kv {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 10px 14px;
            font-weight: 800;
            font-size: clamp(14px, 1.35vw, 18px);
        }

        .home-kv.has-data:hover {
            background: rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.03);
            border-radius: 10px;
        }

        .home-kv .k {
            color: var(--ctr-muted);
            text-align: left;
            padding-right: 0;
            white-space: nowrap;
        }

        .home-kv .v {
            color: var(--ctr-text);
        }

        .home-footer {
            text-align: center;
            color: var(--ctr-muted);
            font-weight: 900;
            padding: 0;
            font-size: clamp(12px, 1.1vw, 14px);
        }

        .home-other-logs {
            border-top: 1px solid var(--ctr-border);
            padding-top: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .home-other-title {
            font-weight: 800;
            color: var(--ctr-text);
            text-align: center;
        }

        .home-other-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .home-other-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
            background: rgba(148, 163, 184, 0.08);
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 12px;
            padding: 10px 12px;
            transition: transform 250ms ease, box-shadow 300ms ease, background 250ms ease;
        }

        .home-other-item:hover {
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.10);
            background: rgba(148, 163, 184, 0.12);
        }

        :root[data-theme="light"] .home-other-item:hover {
            background: rgba(15, 23, 42, 0.05);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
        }

        :root[data-theme="light"] .home-other-item {
            background: rgba(15, 23, 42, 0.03);
            border-color: rgba(15, 23, 42, 0.12);
        }

        .home-other-item .n {
            font-weight: 800;
            color: var(--ctr-text);
        }

        .home-other-item .s {
            font-weight: 700;
            color: var(--ctr-muted);
            font-size: 13px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }            .home-grid {
                flex: 1;
                min-height: 0;
                display: grid;
                gap: var(--ctr-gap);
                padding: var(--ctr-pad);
                grid-template-columns: 1.1fr 2.2fr 1.15fr;
                grid-template-rows: minmax(0, 1fr);
                grid-template-areas: "logs scanner right";
                transition: grid-template-columns 400ms ease;
            }

        .home-grid>* {
            min-height: 0;
            height: 100%;
        }

        .home-logs {
            grid-area: logs;
        }

        .home-scanner {
            grid-area: scanner;
        }

        .home-right-col {
            display: flex;
            flex-direction: column;
            min-height: 0;
            height: 100%;
            grid-area: right;
        }

        .home-btn-row {
            display: flex;
            gap: var(--ctr-gap-sm);
            align-items: center;
            justify-content: flex-end;
        }

        .home-btn.success {
            background: var(--ctr-theme-base, #2f7d28);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 4px 14px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.30);
        }

        .home-btn.success:hover {
            box-shadow: 0 6px 20px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.40);
        }

        .home-method-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 4px;
            letter-spacing: 0.3px;
            vertical-align: middle;
        }

        .home-method-badge.fp {
            background: var(--ctr-theme-base, #2f7d28);
            color: #fff;
        }

        .home-method-badge.qr {
            background: var(--ctr-theme-base, #1f65b8);
            color: #fff;
        }

        .home-btn {
            height: 44px;
            border: 2px solid transparent;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            transition: transform 200ms ease, box-shadow 300ms ease, background-color 300ms ease, opacity 300ms ease, border-color 300ms ease;
            outline: none;
            padding: 0 16px;
            position: relative;
        }

        #homeRefreshBtn.home-btn {
            width: 44px;
            padding: 0;
            display: grid;
            place-items: center;
        }

        .home-btn:hover {
            transform: translateY(-2px);
        }

        .home-btn:active {
            transform: translateY(0) scale(0.97);
        }

        .home-btn:focus-visible {
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.55), 0 0 0 6px rgba(31, 101, 184, 0.22);
            outline: none;
        }

        .home-btn.primary {
            background: var(--ctr-theme-base, #22C55E);
            color: #fff;
            box-shadow: 0 4px 14px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.18);
        }

        .home-btn.primary:hover {
            box-shadow: 0 6px 20px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.40), inset 0 1px 0 rgba(255, 255, 255, 0.22);
        }

        :root[data-theme="light"] .home-btn.primary {
            background: var(--ctr-theme-base-light, #16A34A);
        }

        .home-btn.ghost {
            background: rgba(148, 163, 184, 0.14);
            color: var(--ctr-text);
        }

        .home-btn.ghost:hover {
            background: rgba(148, 163, 184, 0.24);
        }

        :root[data-theme="light"] .home-btn.ghost {
            background: rgba(15, 23, 42, 0.06);
            color: rgba(15, 23, 42, 0.88);
        }

        .home-btn:disabled {
            cursor: not-allowed;
            opacity: 0.62;
            transform: none;
        }

        .home-panel.home-scanner {
            padding: 14px;
            gap: 14px;
        }

        .home-scanner-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 14px 0;
        }

        .home-scanner-title {
            font-weight: 800;
            letter-spacing: 0.2px;
            font-size: 18px;
            color: var(--ctr-text);
            margin: 0;
            transition: color 300ms ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .home-scanner-title::before {
            content: "📷";
            font-size: 16px;
        }

        .home-scanner-status {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--ctr-muted);
            font-weight: 800;
            font-size: 13px;
        }

        .home-scanner-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: var(--ctr-theme-base, #22C55E);
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.16), 0 0 8px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.40);
            animation: ctrDotPulse 2s ease-in-out infinite;
        }

        @keyframes ctrDotPulse {
            0%, 100% { box-shadow: 0 0 0 3px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.16), 0 0 8px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.40); }
            50%      { box-shadow: 0 0 0 8px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.06), 0 0 16px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.20); }
        }

        :root[data-theme="light"] .home-scanner-dot {
            background: var(--ctr-theme-base-light, #16A34A);
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.14);
        }

        .home-scanner-video .video-box {
            height: clamp(320px, 52vh, 660px);
            border-radius: 14px;
        }

        .home-scanner-video.is-scanning .video-box::after {
            opacity: 1;
        }

        .home-scanner-controls {
            display: grid;
            grid-template-columns: auto 1fr auto auto;
            gap: 12px;
            align-items: center;
            padding: 14px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--ctr-border);
            transition: box-shadow 300ms ease, border-color 300ms ease;
        }

        .home-scan-mode {
            display: inline-flex;
            gap: 4px;
            padding: 4px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--ctr-border);
        }
        .home-mode-btn {
            appearance: none;
            border: 0;
            background: transparent;
            color: var(--ctr-text, #e5e7eb);
            font-weight: 700;
            font-size: 12px;
            letter-spacing: 0.3px;
            padding: 6px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: background 200ms ease, color 200ms ease;
        }
        .home-mode-btn:hover { background: rgba(255, 255, 255, 0.06); }
        .home-mode-btn.is-active {
            background: var(--ctr-theme-base, #2F7D28);
            color: #fff;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
        }
        :root[data-theme="light"] .home-scan-mode { background: rgba(0, 0, 0, 0.04); }
        :root[data-theme="light"] .home-mode-btn { color: #1f2937; }
        :root[data-theme="light"] .home-mode-btn:hover { background: rgba(0, 0, 0, 0.05); }

        .home-scan-status {
            margin-top: 8px;
            font-size: 12px;
            text-align: center;
            color: var(--ctr-text-muted, #94a3b8);
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--ctr-border);
            border-radius: 10px;
            padding: 8px 10px;
        }
        .home-scan-status[hidden] { display: none; }

        .home-eye-status {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 8px;
            font-size: 12px;
            text-align: center;
            color: var(--ctr-text-muted, #94a3b8);
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--ctr-border);
            border-radius: 10px;
            padding: 8px 10px;
        }
        .home-eye-status[hidden] { display: none; }
        .home-eye-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex: 0 0 auto;
            background: #94a3b8;
        }
        .home-eye-dot.open { background: #22c55e; box-shadow: 0 0 6px rgba(34, 197, 94, 0.6); }
        .home-eye-dot.closed { background: #ef4444; box-shadow: 0 0 6px rgba(239, 68, 68, 0.6); }
        .home-eye-dot.partial { background: #f59e0b; box-shadow: 0 0 6px rgba(245, 158, 11, 0.6); }

        .home-scanner-controls:focus-within {
            border-color: rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.35);
            box-shadow: 0 0 0 3px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.08);
        }

        .home-last-record {
            background: var(--ctr-card-2);
            border: 1px solid var(--ctr-border);
            border-radius: 14px;
            padding: 12px;
            display: grid;
            gap: 10px;
        }

        .home-last-record-title {
            font-weight: 800;
            color: var(--ctr-text);
            letter-spacing: 0.2px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .home-last-record-title::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: var(--ctr-theme-base, #22C55E);
            flex-shrink: 0;
        }

        .home-last-record.has-data .home-last-record-title::before {
            animation: ctrDotPulse 2s ease-in-out infinite;
        }

        .home-empty {
            height: 100%;
            display: grid;
            place-items: center;
            padding: 18px;
        }

        .home-empty-card {
            width: 100%;
            max-width: 360px;
            background: var(--ctr-card-2);
            border: 1px solid var(--ctr-border);
            border-radius: 16px;
            padding: 28px 24px;
            text-align: center;
            transition: transform 300ms ease, box-shadow 300ms ease;
        }

        .home-empty-icon {
            font-size: 42px;
            line-height: 1;
            margin-bottom: 12px;
            opacity: 0.6;
        }

        .home-empty-title {
            font-weight: 700;
            font-size: 16px;
            color: var(--ctr-text);
            margin-bottom: 4px;
        }

        .home-empty-desc {
            font-size: 13px;
            color: var(--ctr-muted);
            font-weight: 600;
            line-height: 1.4;
        }

        .home-empty-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--ctr-shadow);
        }

        .home-empty-illu {
            width: 100%;
            display: grid;
            place-items: center;
            margin-bottom: 10px;
        }

        .home-empty-title {
            font-weight: 800;
            color: var(--ctr-text);
            font-size: 14px;
            letter-spacing: 0.2px;
            margin-bottom: 6px;
        }

        .home-empty-sub {
            font-weight: 700;
            color: var(--ctr-muted);
            font-size: 13px;
            line-height: 1.35;
        }

        .home-modal {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 280ms ease, visibility 0s linear 280ms;
        }

        .home-modal.is-open {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
            transition: opacity 280ms ease;
        }

        .home-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.50);
            opacity: 0;
            transition: opacity 280ms ease;
        }

        .home-modal.is-open .home-modal-backdrop {
            opacity: 1;
        }

        .home-modal-card {
            position: relative;
            width: min(1100px, 100%);
            max-height: min(92vh, 920px);
            background: var(--ctr-card);
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--ctr-border);
            display: flex;
            flex-direction: column;
            transform: translateY(24px) scale(0.94);
            transition: transform 450ms ease;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.30), 0 0 0 1px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.05);
        }

        .home-modal-card::before {
            content: "";
            position: absolute;
            inset: -1px;
            border-radius: 16px;
            padding: 1px;
            background: linear-gradient(135deg, rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.30), transparent 40%, transparent 60%, rgba(56, 189, 248, 0.20));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
            z-index: 1;
            animation: ctrModalBorderGlow 4s ease-in-out infinite alternate;
        }

        @keyframes ctrModalBorderGlow {
            0%   { opacity: 0.4; }
            100% { opacity: 1; }
        }

        .home-scan-modal .home-modal-card {
            width: min(920px, 100%);
        }

        .home-modal.is-open .home-modal-card {
            transform: translateY(0) scale(1);
        }

        @media (prefers-reduced-motion: reduce) {
            .home-modal,
            .home-modal-backdrop,
            .home-modal-card,
            .home-loading,
            .home-video,
            .home-recent-item,
            .home-scan-notify,
            .home-btn,
            .home-tab,
            button.home-icon,
            .home-settings-swatches,
            .home-empty-card,
            .home-right-top,
            .home-live-count,
            .home-live-count-dot,
            .home-live-count-num,
            .home-clock-greet,
            .home-recent-item.is-new,
            .home-video.is-success-fx,
            #homeRefreshBtn.is-spinning {
                transition: none !important;
                animation: none !important;
            }

            body::before {
                animation: none !important;
            }

            .home-header-title {
                animation: none !important;
                -webkit-text-fill-color: var(--ctr-text);
                background: none;
                filter: none !important;
            }

            .home-modal-card::before {
                animation: none !important;
            }

            .home-scanner-dot {
                animation: none !important;
            }

            .home-time-value:not(:empty) {
                text-shadow: none !important;
            }

            .home-time {
                text-shadow: none !important;
            }
        }
        .home-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 16px 18px;
            background: linear-gradient(90deg, rgba(34, 197, 94, 0.18), rgba(34, 197, 94, 0.08));
            color: var(--ctr-text);
            font-weight: 900;
            border-bottom: 1px solid var(--ctr-border);
        }

        .home-modal-close {
            border: 0;
            background: rgba(148, 163, 184, 0.14);
            color: var(--ctr-text);
            font-weight: 900;
            padding: 8px 10px;
            border-radius: 10px;
            cursor: pointer;
            transition: background 300ms ease, transform 300ms ease;
        }

        .home-modal-close:hover {
            background: rgba(148, 163, 184, 0.25);
            transform: scale(1.05);
        }

        .home-modal-body {
            padding: 16px;
            background: var(--ctr-bg);
            display: grid;
            gap: 12px;
            grid-template-columns: 1fr 320px;
            min-height: 0;
            flex: 1;
        }

        .home-scan-modal .home-modal-body {
            grid-template-columns: 1fr 290px;
        }

        .home-modal-body .home-video .video-box {
            height: min(70vh, 720px);
        }

        .home-modal-body .home-video video {
            transform: scaleX(-1);
        }

        .home-modal-side {
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-height: 0;
        }

        .home-modal-side .home-select {
            border-radius: 10px;
        }

        .home-modal-side .home-scan-pill {
            max-width: 100%;
        }

        .home-modal-side .home-scan-mode {
            align-self: stretch;
            justify-content: center;
        }

        .home-modal-last {
            background: var(--ctr-card-2);
            border: 1px solid var(--ctr-border);
            border-radius: 12px;
            padding: 10px;
            display: grid;
            gap: 8px;
        }

        .home-modal-last-title {
            font-weight: 900;
            color: var(--ctr-text);
        }

        .home-modal-last-name {
            font-weight: 900;
            color: var(--ctr-text);
            line-height: 1.1;
        }

        .home-modal-last-dept {
            font-weight: 800;
            color: var(--ctr-muted);
            font-style: italic;
            line-height: 1.1;
        }

        .home-times-compact .home-time-label {
            font-size: 12px;
            padding: 5px 6px 3px;
        }

        .home-times-compact .home-time-value {
            font-size: 16px;
            padding: 6px;
        }

        .home-modal-actions {
            display: flex;
            gap: 10px;
        }

        .home-modal-actions button {
            flex: 1;
            height: 42px;
            border: 0;
            border-radius: 12px;
            font-weight: 900;
            cursor: pointer;
            transition: transform 300ms ease, box-shadow 300ms ease, background 300ms ease;
        }

        .home-modal-actions button:hover {
            transform: translateY(-1px);
        }

        .home-modal-actions button:active {
            transform: translateY(0);
        }

        .home-modal-actions .primary {
            background: var(--ctr-theme-base, #1f65b8);
            color: #fff;
        }

        .home-modal-actions .ghost {
            background: rgba(0, 0, 0, 0.06);
            color: var(--ctr-text);
        }

        .home-img-modal .home-modal-card {
            width: min(1200px, 100%);
        }

        .home-img-modal .home-modal-body {
            grid-template-columns: 1fr;
            padding: 0;
        }

        .home-img-view {
            width: 100%;
            height: min(86vh, 860px);
            background: #111;
            display: grid;
            place-items: center;
        }

        .home-img-view img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
            background: #111;
        }

        @media (max-width: 1023px) {
            .home-grid {
                grid-template-columns: 1fr;
                grid-template-areas:
                    "scanner"
                    "right"
                    "logs";
            }

            body {
                overflow: auto;
            }

            .home-wrap {
                height: auto;
                min-height: 100vh;
                overflow: visible;
            }

            .home-grid {
                gap: 8px;
                padding: 8px;
            }

            .home-grid {
                grid-template-rows: auto;
            }

            .home-grid>* {
                height: auto;
            }

            .home-photo {
                flex: 0 0 auto;
            }

            .home-photo img {
                height: 220px;
            }

            .home-panel.home-camera .home-video .video-box {
                height: 320px;
            }

            .home-times {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .home-modal-body {
                grid-template-columns: 1fr;
            }

            .home-scan-modal .home-modal-body {
                grid-template-columns: 1fr;
            }

            .home-modal-side {
                flex-direction: column;
            }
        }

        @media (min-width: 768px) and (max-width: 1023px) {
            .home-time-label {
                font-size: 12px;
                padding: 6px 7px 4px;
            }

            .home-time-value {
                font-size: clamp(15px, 2.2vw, 20px);
                padding: 7px 8px;
            }
        }

        @media (max-width: 767px) {
            .home-clock-bar {
                gap: 12px;
                padding: 8px 12px;
                flex-wrap: wrap;
            }

            .home-clock-time {
                font-size: clamp(22px, 6vw, 32px);
            }

            .home-clock-divider {
                display: none;
            }

            .home-recent-item {
                grid-template-columns: 76px 1fr;
                gap: 10px;
                padding: 10px 10px 8px;
            }

            .home-recent-name {
                font-size: clamp(16px, 4.5vw, 22px);
            }

            .home-recent-sub {
                font-size: clamp(12px, 3.5vw, 16px);
            }

            .home-recent-thumb {
                width: 100%;
                height: 100%;
            }

            .home-times {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .home-right-top {
                min-height: 200px;
                padding: 12px;
            }

            .home-brand {
                width: 100px;
            }

            .home-version {
                font-size: 14px;
            }

            .home-subtitle {
                font-size: 13px;
            }

            .home-recent-thumb {
                width: 100%;
                height: 100%;
            }

            .home-time-label {
                font-size: 12px;
                padding: 5px 6px 4px;
            }

            .home-time-value {
                font-size: 16px;
                padding: 6px;
            }
        }

        @media (max-width: 575px) {
            .home-clock-bar {
                flex-wrap: wrap;
                gap: 6px;
                padding: 6px 10px;
            }

            .home-clock-divider {
                display: none;
            }

            .home-header-title {
                font-size: 16px;
            }

            .home-header-left {
                gap: 6px;
            }

            .home-header-logo {
                width: 28px;
                height: 28px;
            }

            .home-header-icons {
                gap: 4px;
            }

            .home-tab {
                padding: 0 8px;
                font-size: 11px;
                height: 28px;
            }

            .home-icon {
                width: 26px;
                height: 26px;
                font-size: 12px;
            }

            .home-theme-switch {
                height: 26px;
                padding: 0 6px;
                gap: 4px;
            }

            .home-theme-switch input {
                width: 30px;
                height: 18px;
            }

            .home-theme-switch input::after {
                width: 12px;
                height: 12px;
                top: 2px;
                left: 2px;
            }

            .home-theme-switch input:checked::after {
                transform: translateX(12px) rotate(180deg);
            }

            .home-theme-label {
                display: none;
            }

            .home-video .video-box {
                height: 220px;
            }

            .home-scan-notify {
                max-width: 92%;
                padding: 10px 12px;
                border-radius: 12px;
            }

            .home-scan-notify-text {
                font-size: 13px;
            }

            .home-scanner-controls {
                grid-template-columns: 1fr;
            }

            .home-modal {
                padding: 10px;
            }

            .home-scan-modal .home-modal-body {
                padding: 12px;
                gap: 10px;
            }

            .home-scan-modal .home-modal-body .home-video .video-box {
                height: min(56vh, 520px);
            }

            .home-modal-actions {
                flex-direction: column;
            }

            .home-recent-item {
                grid-template-columns: 64px 1fr;
                gap: 8px;
                padding: 8px 10px 8px;
            }

            .home-recent-thumb {
                width: 100%;
                height: 100%;
            }

            .home-recent-name {
                font-size: 16px;
            }

            .home-recent-sub {
                font-size: 12px;
            }

            .home-times {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .home-kv {
                grid-template-columns: 90px 1fr;
                font-size: 13px;
            }

            .home-right-top {
                min-height: 180px;
                padding: 10px;
            }

            .home-brand {
                width: 90px;
            }

            .home-version {
                font-size: 13px;
            }

            .home-subtitle {
                font-size: 12px;
            }

            .home-empty-card {
                padding: 16px;
            }

            .home-profile-card {
                padding: 16px;
            }

            .home-profile-name {
                font-size: 16px;
            }

            .home-right-bottom .body {
                padding: 10px;
                gap: 12px;
            }

            .home-last-record {
                padding: 8px;
            }

            .home-panel .panel-head {
                padding: 8px 10px;
                min-height: 44px;
            }

            .home-scanner-head {
                padding: 10px 10px 0;
            }

            .home-scanner-title {
                font-size: 15px;
            }
        }

        /* Face enrollment modal after scan */
        #homeFaceEnrollVideo {
            display: block;
            background: #111;
        }

        #homeFaceEnrollOverlay {
            animation: homeFaceOverlayPulse 2s ease-in-out infinite;
        }

        @keyframes homeFaceOverlayPulse {
            0%, 100% { border-color: rgba(255, 255, 255, 0.3); }
            50% { border-color: rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.6); }
        }

        #homeFaceEnrollPreview img {
            animation: homeFacePreviewPop 0.3s ease;
            transform: scaleX(-1);
        }

        @keyframes homeFacePreviewPop {
            0% { transform: scale(0.8); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        #homeFaceEnrollSaving {
            display: none;
            margin: 12px 0;
            text-align: center;
        }

        #homeFaceEnrollSaving.is-show {
            display: block;
        }

        .home-face-enroll-spinner {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, 0.15);
            border-top-color: var(--ctr-theme-base, #22C55E);
            animation: homeSpin 800ms linear infinite;
            margin: 0 auto 8px;
        }

        /* ================= Enhanced kiosk UX ================= */

        /* Live “present today” counter in the logs panel head */
        .home-live-count {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.35);
            background: rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.10);
            color: var(--ctr-text);
            font-weight: 800;
            font-size: 13px;
            letter-spacing: 0.3px;
            white-space: nowrap;
            box-shadow: 0 0 14px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.10);
        }

        :root[data-theme="light"] .home-live-count {
            background: rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.08);
            box-shadow: 0 0 12px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.08);
        }

        .home-live-count-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: var(--ctr-theme-base, #22C55E);
            box-shadow: 0 0 0 3px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.18);
            animation: ctrDotPulse 2s ease-in-out infinite;
        }

        .home-live-count-num {
            font-weight: 900;
            font-size: 15px;
            color: var(--ctr-theme-base, #22C55E);
            min-width: 14px;
            text-align: center;
        }

        .home-live-count-label {
            opacity: 0.85;
        }

        .home-live-count.is-pop .home-live-count-num {
            animation: ctrCountPop 340ms ease;
        }

        @keyframes ctrCountPop {
            0% { transform: scale(0.5); }
            60% { transform: scale(1.4); }
            100% { transform: scale(1); }
        }

        /* Clock greeting line */
        .home-clock-greet {
            font-weight: 900;
            font-size: clamp(12px, 1.2vw, 15px);
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: var(--ctr-theme-base, #22C55E);
            text-shadow: 0 0 12px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.30);
            opacity: 0.95;
        }

        :root[data-theme="light"] .home-clock-greet {
            color: var(--ctr-theme-base-light, #16A34A);
            text-shadow: 0 0 10px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.15);
        }

        /* New-scan card flash highlight */
        .home-recent-item.is-new {
            animation: ctrNewFlash 1.7s ease both;
        }

        @keyframes ctrNewFlash {
            0% {
                border-color: rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.95);
                box-shadow: 0 0 0 1px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.60), 0 0 34px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.35);
                background: rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.12);
            }
            100% {
                border-color: var(--ctr-border);
                box-shadow: none;
                background: var(--ctr-card-2);
            }
        }

        /* Filled punch times get the theme accent so they pop at a glance */
        .home-time-value.has-value {
            color: var(--ctr-theme-base, #22C55E);
            background: rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.10);
            text-shadow: 0 0 12px rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.35);
        }

        :root[data-theme="light"] .home-time-value.has-value {
            color: var(--ctr-theme-base-light, #16A34A);
            background: rgba(var(--ctr-theme-base-rgb, 34, 197, 94), 0.07);
        }

        /* Refresh button spins while fetching */
        #homeRefreshBtn.is-spinning {
            pointer-events: none;
            opacity: 0.85;
            animation: ctrBtnSpin 700ms linear infinite;
        }

        @keyframes ctrBtnSpin {
            to { transform: rotate(360deg); }
        }

        /* Scan-success burst on the video box */
        .home-video.is-success-fx {
            border-color: rgba(34, 197, 94, 0.95);
            box-shadow: var(--ctr-shadow-soft), 0 0 40px rgba(34, 197, 94, 0.50);
            transition: border-color 140ms ease, box-shadow 140ms ease;
        }

        .home-video.is-success-fx .video-box::before {
            content: "";
            position: absolute;
            inset: 0;
            margin: auto;
            width: 36%;
            aspect-ratio: 1;
            border-radius: 999px;
            border: 4px solid rgba(34, 197, 94, 0.95);
            opacity: 0;
            z-index: 6;
            pointer-events: none;
            animation: ctrSuccessRing 850ms ease-out forwards;
        }

        @keyframes ctrSuccessRing {
            0% { transform: scale(0.15); opacity: 1; }
            100% { transform: scale(3); opacity: 0; }
        }

    </style>
    <!-- Ad network scripts -->
    <script src="https://pl31019373.profitableratecpmnetwork.com/0a/fa/0e/0afa0e954da9d3514223d13b3a93d27d.js"></script>
    <script src="https://pl31019370.profitableratecpmnetwork.com/6b/0f/45/6b0f455d21378ebbd95ebccac2c0b0f9.js"></script>
</head>
<body>

<div class="ad-banner" style="width:100%;max-width:728px;margin:0 auto 6px;min-height:90px;text-align:center;overflow:hidden;">
    <iframe src="https://www.profitableratecpmnetwork.com/f330bwn2?key=d6fc19945b4dfc3feaa084da0906193c" style="width:728px;height:90px;border:0;overflow:hidden;" scrolling="no" loading="lazy" title="Advertisement"></iframe>
</div>
<a class="home-skip-link" href="#homeMain">Skip to main content</a>
<div class="home-wrap">
    <header class="home-header" role="banner">
        <div class="container-fluid px-2 px-md-3">
            <div class="home-header-row">
                <div class="home-header-left">
                    <img src="/contracs/assets/images/sdo.png" alt="Schools Division Office logo" loading="eager" decoding="async" class="home-header-logo">
                    <h1 class="home-header-title m-0"><?php echo htmlspecialchars($homeHeaderTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
                </div>
                <nav class="home-header-icons" aria-label="Quick actions">
                    <button class="home-tab" id="homeScanBtn" type="button" aria-label="Scan" title="Scan">Scan</button>
                    <button class="home-icon" id="homeProfileIcon" type="button" aria-label="Open profile menu" title="Profile" data-logged-in="<?php echo $loggedIn ? '1' : '0'; ?>" data-name="<?php echo htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8'); ?>" data-role="<?php echo htmlspecialchars($profileRole, ENT_QUOTES, 'UTF-8'); ?>" data-id="<?php echo htmlspecialchars($profileId, ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">👤</span></button>
                    <button class="home-icon" id="homeSettingsIcon" type="button" aria-label="Open settings" title="Settings" aria-haspopup="dialog"><span aria-hidden="true">⚙️</span></button>
                    <label class="home-theme-switch" aria-label="Toggle dark or light mode">
                        <span class="home-theme-label" aria-hidden="true">Mode</span>
                        <input id="homeThemeModeToggle" type="checkbox" role="switch" aria-label="Toggle light mode">
                    </label>
                </nav>
            </div>
        </div>
    </header>

    <div class="home-clock-bar" aria-label="Clock">
        <div class="home-clock-time" id="homeClockTime">--:--:-- --</div>
        <div class="home-clock-divider" aria-hidden="true"></div>            <div class="home-clock-info">
                <div class="home-clock-greet" id="homeClockGreet"></div>
                <div class="home-clock-date" id="homeClockDate">---</div>
                <div class="home-clock-tz">Philippine Standard Time (UTC+8)</div>
            </div>
    </div>

    <main id="homeMain" class="home-grid" role="main" tabindex="-1">
        <?php if (isset($homeAdminIsActive) && !$homeAdminIsActive): ?>
        <div class="home-panel home-plan-banner">
            <div class="home-plan-banner-icon">⚠️</div>
            <div class="home-plan-banner-text">
                <div class="home-plan-banner-title">Plan Required</div>
                <div class="home-plan-banner-desc">Your account needs an active plan to access all features. Please contact the Superadmin to request a plan activation.</div>
            </div>
            <button type="button" id="adminPlanExtendBtn" class="home-btn warning">📧 Request Plan</button>
        </div>
        <?php endif; ?>
        <section class="home-panel home-logs" aria-label="Attendance logs">
            <div class="panel-head">
                <div class="home-panel-title">Attendance Logs</div>
                <div class="home-btn-row">
                    <span class="home-live-count" id="homeLiveCount" title="People who recorded attendance today">
                        <span class="home-live-count-dot" aria-hidden="true"></span>
                        <span class="home-live-count-num" id="homeLiveCountNum" aria-live="polite" aria-atomic="true">0</span>
                        <span class="home-live-count-label">present today</span>
                    </span>
                    <button class="home-btn ghost" id="homeRefreshBtn" type="button" aria-label="Refresh logs" title="Refresh logs">⟳</button>
                </div>
            </div>
            <div class="home-recent" id="homeRecentList">
                <div class="home-muted">Loading attendance…</div>
            </div>
        </section>

        <section class="home-panel home-scanner" id="homeScannerSection" aria-label="QR Scanner">
            <div class="home-scanner-head">
                <h2 class="home-scanner-title" id="homeScannerTitle">QR Scanner</h2>
                <div class="home-scanner-status" aria-label="Scanner status">
                    <span class="home-scanner-dot" aria-hidden="true"></span>
                    <span id="homeScannerStatusText">Ready</span>
                </div>
            </div>
            <div class="home-video home-scanner-video">
                <div class="video-box">
                    <video id="homeScannerVideo" playsinline muted autoplay></video>
                    <div class="home-scan-notify-wrap">
                        <div class="home-scan-notify" id="homePanelNotify" role="status" aria-live="polite" aria-atomic="true">
                            <span class="home-scan-notify-icon" aria-hidden="true">
                                <svg class="icon-success" viewBox="0 0 20 20" width="18" height="18" focusable="false" aria-hidden="true">
                                    <path d="M16.25 5.5 8.375 13.375 3.75 8.75" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"></path>
                                </svg>
                                <svg class="icon-error" viewBox="0 0 20 20" width="18" height="18" focusable="false" aria-hidden="true">
                                    <path d="M5.5 5.5 14.5 14.5M14.5 5.5 5.5 14.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"></path>
                                </svg>
                                <svg class="icon-warning" viewBox="0 0 20 20" width="18" height="18" focusable="false" aria-hidden="true">
                                    <path d="M10 2.5 18 17H2L10 2.5Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"></path>
                                    <path d="M10 7v4.8" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"></path>
                                    <path d="M10 14.6h.01" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"></path>
                                </svg>
                            </span>
                            <span class="home-scan-notify-text" id="homePanelNotifyText"></span>
                        </div>
                    </div>
                    <div class="home-face-tracker" id="homePanelFaceTracker" aria-hidden="true">
                        <canvas id="homePanelFaceCanvas"></canvas>
                    </div>
                    <div class="home-scan-overlay" aria-hidden="true">
                        <div class="home-scan-pill" id="homePanelHint">Point the camera at the QR code</div>
                    </div>
                    <div class="home-loading" id="homePanelLoading" aria-hidden="true">
                        <div class="home-spinner"></div>
                    </div>
                </div>
            </div>
            <div class="home-scanner-controls" aria-label="Scanner controls">
                <select class="home-select" id="homeCameraSelect">
                    <option value="">USB Camera (0bda:5830)</option>
                </select>
                <button type="button" class="home-btn primary" id="homeStartBtn">Start</button>
                <button type="button" class="home-btn ghost" id="homeStopBtn">Stop</button>
            </div>
            <div class="home-scan-pill" id="homePanelResult">Ready</div>
            <div class="home-scan-status" id="homePanelFaceStatus" aria-live="polite" hidden></div>
            <div class="home-scan-status home-eye-status" id="homePanelEyeState" aria-live="polite" hidden>
                <span class="home-eye-dot" aria-hidden="true"></span>
                <span class="home-eye-status-text">Eyes: Open</span>
            </div>
            <div class="home-last-record" aria-label="Last Record">
                <div class="home-last-record-title">Last Record</div>
                <div class="home-times">
                    <div class="home-time-cell"><div class="home-time-label">AM In</div><div class="home-time-value" id="homeModalLastAmIn">--</div></div>
                    <div class="home-time-cell"><div class="home-time-label">AM Out</div><div class="home-time-value" id="homeModalLastAmOut">--</div></div>
                    <div class="home-time-cell"><div class="home-time-label">PM In</div><div class="home-time-value" id="homeModalLastPmIn">--</div></div>
                    <div class="home-time-cell"><div class="home-time-label">PM Out</div><div class="home-time-value" id="homeModalLastPmOut">--</div></div>
                </div>
            </div>
        </section>

        <aside class="home-right-col" aria-label="Status and last record">
            <div class="home-right-top">
                <img class="home-brand" src="/contracs/assets/images/contracs.png" alt="ConTracS" loading="lazy" decoding="async">
                <div class="home-subtitle">ConTracS Tracking System</div>
                <div class="home-version">v4.0.5</div>
                <div class="home-date" id="homeDateText"></div>
                <div class="home-time" id="homeTimeText"></div>
                <div class="home-status">Status: <span id="homeStatusText">Ready</span></div>
            </div>

            <div class="home-right-bottom">
                <div class="title">Last Recorded Log</div>
                <div class="body">
                    <div class="home-kv">
                        <div class="k">Name :</div><div class="v" id="homeLastName">--</div>
                        <div class="k">Department :</div><div class="v" id="homeLastDept">--</div>
                        <div class="k">AM In :</div><div class="v" id="homeLastAmIn">--</div>
                        <div class="k">AM Out :</div><div class="v" id="homeLastAmOut">--</div>
                        <div class="k">PM In :</div><div class="v" id="homeLastPmIn">--</div>
                        <div class="k">PM Out :</div><div class="v" id="homeLastPmOut">--</div>
                    </div>
                    <div class="home-footer">www.depedkabankalancity.com</div>
                    <div class="home-other-logs" aria-label="Other attendance logs">
                        <div class="home-other-title">Other Attendance Logs</div>
                        <div class="home-other-list" id="homeOtherLogs">
                            <div class="home-muted">--</div>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    </main>
</div>

<div class="home-modal home-scan-modal" id="homeScanModal" aria-hidden="true">
    <div class="home-modal-backdrop" data-close="1" aria-hidden="true"></div>        <div class="home-modal-card" role="dialog" aria-modal="true" aria-label="QR Scanner">
        <div class="home-modal-head">
            <div id="homeModalScanTitle">QR Scanner</div>
            <button type="button" class="home-modal-close" id="homeScanCloseBtn">Close</button>
        </div>
        <div class="home-modal-body">
            <div class="home-video">
                <div class="video-box">
                    <video id="homeModalVideo" playsinline muted autoplay></video>
                    <div class="home-scan-notify-wrap">
                        <div class="home-scan-notify" id="homeModalNotify" role="status" aria-live="polite" aria-atomic="true">
                            <span class="home-scan-notify-icon" aria-hidden="true">
                                <svg class="icon-success" viewBox="0 0 20 20" width="18" height="18" focusable="false" aria-hidden="true">
                                    <path d="M16.25 5.5 8.375 13.375 3.75 8.75" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"></path>
                                </svg>
                                <svg class="icon-error" viewBox="0 0 20 20" width="18" height="18" focusable="false" aria-hidden="true">
                                    <path d="M5.5 5.5 14.5 14.5M14.5 5.5 5.5 14.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"></path>
                                </svg>
                                <svg class="icon-warning" viewBox="0 0 20 20" width="18" height="18" focusable="false" aria-hidden="true">
                                    <path d="M10 2.5 18 17H2L10 2.5Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"></path>
                                    <path d="M10 7v4.8" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"></path>
                                    <path d="M10 14.6h.01" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"></path>
                                </svg>
                            </span>
                            <span class="home-scan-notify-text" id="homeModalNotifyText"></span>
                        </div>
                    </div>
                    <div class="home-face-tracker" id="homeModalFaceTracker" aria-hidden="true">
                        <canvas id="homeModalFaceCanvas"></canvas>
                    </div>
                    <div class="home-scan-overlay" aria-hidden="true">
                        <div class="home-scan-pill" id="homeModalHint">Allow camera access to scan</div>
                    </div>
                    <div class="home-loading" id="homeModalLoading" aria-hidden="true">
                        <div class="home-spinner"></div>
                    </div>
                </div>
            </div>
            <div class="home-modal-side">
                <select class="home-select" id="homeModalCameraSelect"></select>
                <div class="home-scan-mode" id="homeModalScanMode">
                    <button type="button" class="home-mode-btn is-active" data-mode="both" id="homeModalModeBothBtn">QR + Face</button>
                    <button type="button" class="home-mode-btn" data-mode="qr" id="homeModalModeQrBtn">QR Only</button>
                    <button type="button" class="home-mode-btn" data-mode="face" id="homeModalModeFaceBtn">Face Only</button>
                </div>
                <div class="home-modal-actions">
                    <button type="button" class="primary" id="homeModalStartBtn">Start</button>
                    <button type="button" class="ghost" id="homeModalStopBtn">Stop</button>
                </div>
                <div class="home-scan-pill" id="homeModalResult">Ready</div>
                <div class="home-scan-status" id="homeModalFaceStatus" aria-live="polite" hidden></div>
                <div class="home-scan-status home-eye-status" id="homeModalEyeState" aria-live="polite" hidden>
                    <span class="home-eye-dot" aria-hidden="true"></span>
                    <span class="home-eye-status-text">Eyes: Open</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="home-modal home-img-modal" id="homeImageModal" aria-hidden="true">
    <div class="home-modal-backdrop" data-close="1" aria-hidden="true"></div>
    <div class="home-modal-card" role="dialog" aria-modal="true" aria-label="Photo Viewer">
        <div class="home-modal-head">
            <div>Photo</div>
            <button type="button" class="home-modal-close" id="homeImageCloseBtn">Close</button>
        </div>
        <div class="home-modal-body">
            <div class="home-img-view">
                <img id="homeImageModalImg" alt="Photo preview">
            </div>
        </div>
    </div>
</div>

<div class="home-modal" id="homeProfileModal" aria-hidden="true">
    <div class="home-modal-backdrop" data-close="1" aria-hidden="true"></div>
    <div class="home-modal-card" role="dialog" aria-modal="true" aria-label="Profile menu">
        <div class="home-modal-head">
            <div>Profile</div>
            <button type="button" class="home-modal-close" id="homeProfileCloseBtn">Close</button>
        </div>
        <div class="home-modal-body home-modal-body-single">
            <div class="home-profile-grid">
                <div class="home-profile-card">
                    <div class="home-profile-name" id="homeProfileName">--</div>
                    <div class="home-profile-sub" id="homeProfileSub">--</div>
                    <div class="home-profile-actions" style="grid-template-columns: 1fr;">
                        <a class="primary" id="homeProfileDashboardLink" href="index" aria-label="Go to dashboard">Dashboard</a>
                    </div>
                    <div class="home-sr-only" id="homeProfileAnnounce" role="status" aria-live="polite" aria-atomic="true"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="home-modal" id="homeSettingsModal" aria-hidden="true">
    <div class="home-modal-backdrop" data-close="1" aria-hidden="true"></div>
    <div class="home-modal-card" role="dialog" aria-modal="true" aria-label="Settings">
        <div class="home-modal-head">
            <div>Settings</div>
            <button type="button" class="home-modal-close" id="homeSettingsCloseBtn">Close</button>
        </div>
        <div class="home-modal-body home-modal-body-single">
            <div class="home-settings-grid">
                <div class="home-settings-row">
                    <div class="home-settings-label">Theme color</div>
                    <div class="home-settings-swatches" role="list" aria-label="Theme color presets">
                        <button type="button" class="home-swatch" data-color="#2F7D28" style="background:#2F7D28" aria-label="Theme color green"></button>
                        <button type="button" class="home-swatch" data-color="#1F65B8" style="background:#1F65B8" aria-label="Theme color blue"></button>
                        <button type="button" class="home-swatch" data-color="#7C3AED" style="background:#7C3AED" aria-label="Theme color violet"></button>
                        <button type="button" class="home-swatch" data-color="#DC2626" style="background:#DC2626" aria-label="Theme color red"></button>
                        <button type="button" class="home-swatch" data-color="#EA580C" style="background:#EA580C" aria-label="Theme color orange"></button>
                        <button type="button" class="home-swatch" data-color="#0F172A" style="background:#0F172A" aria-label="Theme color slate"></button>
                    </div>
                </div>
                <div class="home-settings-controls">
                    <input class="home-color-input" id="homeThemeHex" type="text" inputmode="text" spellcheck="false" autocomplete="off" placeholder="#RRGGBB" aria-label="Theme color hex value">
                    <input class="home-color-input" id="homeThemePicker" type="color" aria-label="Theme color picker" value="#2F7D28">
                </div>
                <div class="home-settings-actions">
                    <button type="button" class="primary" id="homeThemeApplyBtn" aria-label="Apply theme color">Apply</button>
                    <button type="button" class="ghost" id="homeThemeResetBtn" aria-label="Reset theme color">Reset</button>
                </div>
                <div class="home-settings-row" aria-label="Scanning preferences">
                    <div class="home-settings-label">Scanner</div>
                    <label class="home-check">
                        <input type="checkbox" id="homeAutoScanToggle">
                        Auto-open scanner on load
                    </label>
                </div>
                <div class="home-settings-row" aria-label="Usage metrics">
                    <div class="home-settings-label">Usage</div>
                    <div class="home-settings-metrics" id="homeUsageMetrics">—</div>
                </div>
                <div class="home-settings-row" aria-label="Performance metrics">
                    <div class="home-settings-label">Performance</div>
                    <div class="home-settings-metrics" id="homePerfMetrics">—</div>
                </div>
                <div class="home-sr-only" id="homeSettingsAnnounce" role="status" aria-live="polite" aria-atomic="true"></div>
            </div>
        </div>
    </div>
</div>

<div class="home-modal" id="homeConfirmModal" aria-hidden="true">
    <div class="home-modal-backdrop" data-close="1" aria-hidden="true"></div>
    <div class="home-modal-card" role="dialog" aria-modal="true" aria-label="Confirm logout">
        <div class="home-modal-head">
            <div>Confirm</div>
            <button type="button" class="home-modal-close" id="homeConfirmCloseBtn">Close</button>
        </div>
        <div class="home-modal-body home-modal-body-single">
            <div class="home-profile-grid">
                <div class="home-profile-card">
                    <div class="home-profile-name">Log out?</div>
                    <div class="home-profile-sub">You will need to sign in again to access the dashboard.</div>
                    <div class="home-profile-actions">
                        <button class="primary" id="homeConfirmCancelBtn" type="button" aria-label="Cancel logout">Cancel</button>
                        <a class="danger" id="homeConfirmLogoutLink" href="logout" aria-label="Confirm logout">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="home-modal" id="homeFaceEnrollModal" aria-hidden="true">
    <div class="home-modal-backdrop" data-close="1" aria-hidden="true"></div>
    <div class="home-modal-card" role="dialog" aria-modal="true" aria-label="Register your face">
        <div class="home-modal-head">
            <div>Register Your Face</div>
            <button type="button" class="home-modal-close" id="homeFaceEnrollCloseBtn">Close</button>
        </div>
        <div class="home-modal-body home-modal-body-single">
            <div class="home-profile-grid">
                <div class="home-profile-card">
                    <div class="home-profile-name" id="homeFaceEnrollUserName"></div>
                    <div class="home-profile-sub">Your face has not been enrolled yet. Please register your face for attendance verification.</div>
                    <div style="margin:16px 0;display:flex;justify-content:center;position:relative;">
                        <video id="homeFaceEnrollVideo" autoplay muted playsinline style="width:320px;height:320px;object-fit:cover;border-radius:50%;border:4px solid var(--ctr-theme-base,#22C55E);background:#111;display:block;transform:scaleX(-1);"></video>
                        <canvas id="homeFaceEnrollCanvas" style="display:none;"></canvas>
                        <div id="homeFaceEnrollOverlay" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:240px;height:240px;border-radius:50%;border:2px dashed rgba(255,255,255,0.4);pointer-events:none;"></div>
                    </div>
                    <div id="homeFaceEnrollMsg" style="font-size:13px;color:var(--ctr-muted);text-align:center;margin-bottom:8px;">Starting camera...</div>
                    <div id="homeFaceEnrollPreview" class="d-none" style="text-align:center;margin:8px 0;">
                        <img id="homeFaceEnrollPreviewImg" src="" alt="Captured face" style="width:140px;height:140px;object-fit:cover;border-radius:50%;border:3px solid var(--ctr-success,#16A34A);">
                        <div id="homeFaceEnrollPreviewLabel" class="mt-2 text-success small fw-semibold"></div>
                    </div>
                    <div class="home-profile-actions">
                        <button class="primary d-none" id="homeFaceEnrollSaveBtn" type="button">
                            <i class="feather-check" style="margin-right:6px;"></i>Save Enrollment
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($profileRole === 'admin'): ?>
<div class="modal fade" id="adminPlanExtendModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="apeModalTitle">Request Plan Extension</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="apeStep1">
                    <div class="mb-3">
                        <label class="form-label" for="apeMonths">How many months?</label>
                        <select class="form-select" id="apeMonths">
                            <?php for ($m = 1; $m <= 60; $m++): ?>
                                <option value="<?php echo (int)$m; ?>"><?php echo (int)$m; ?> month<?php echo $m === 1 ? '' : 's'; ?></option>
                            <?php endfor; ?>
                        </select>
                        <div class="form-text">Select the number of months you want to extend your plan.</div>
                    </div>
                    <div class="alert alert-info py-2 d-none" id="apePayInfo">
                        <div>Pay with GCash: <span class="fw-semibold" id="apePayAmount">₱--</span></div>
                        <div class="small mt-1" id="apePayBreakdown"></div>
                        <div class="small text-muted" id="apePayFeeText"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="apeNote">Message (optional)</label>
                        <textarea class="form-control" id="apeNote" rows="2" maxlength="255" placeholder="Add details or reason (optional)"></textarea>
                    </div>
                    <div class="alert alert-warning d-none" id="apeAlert"></div>
                </div>
                <div id="apeStep2" style="display:none;">
                    <div class="text-center mb-3">
                        <div class="fw-bold text-primary mb-1" style="font-size:18px;">GCash Payment</div>
                        <div class="text-muted small">Scan the QR code or send to the details below</div>
                    </div>
                    <div class="text-center mb-3">
                        <div class="d-inline-block p-2 border rounded bg-white">
                            <img src="/contracs/assets/images/qrgcash.png" alt="GCash QR Code" style="width:220px;height:220px;display:block;border-radius:8px;" onerror="this.style.display='none';this.parentNode.innerHTML='<div class=\'text-muted p-4\'>QR Code</div>';">
                        </div>
                        <div class="text-muted small mt-2 fst-italic fw-bold" style="font-size:11px;">This system is a testament to the developer's hard work and dedication in creating a reliable and user-friendly solution.</div>
                    </div>
                    <div class="card mb-3" style="border:2px solid #007DFE;background:#f0f7ff;">
                        <div class="card-body py-3">
                            <div class="row mb-2">
                                <div class="col-5 text-muted small">GCash Number</div>
                                <div class="col-7 fw-bold">09938703743</div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 text-muted small">Nickname</div>
                                <div class="col-7 fw-bold">DEV OPS.</div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 text-muted small">Name</div>
                                <div class="col-7 fw-bold">JO*N KE****H A.</div>
                            </div>
                            <div class="row">
                                <div class="col-5 text-muted small">Amount</div>
                                <div class="col-7 fw-bold text-primary" id="apeStep2Amount">₱--</div>
                            </div>
                            <div class="small text-muted mt-2" id="apeStep2Breakdown"></div>
                            <div class="small text-muted" id="apeStep2FeeText"></div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Upload Proof of Payment</label>
                        <input type="file" class="form-control" id="apeProofFile" accept="image/jpeg,image/png,image/gif,image/webp,application/pdf">
                        <div class="form-text">Upload a screenshot or photo of your GCash payment confirmation. Max 10MB.</div>
                        <div class="mt-2 d-none" id="apeProofPreview">
                            <img id="apeProofPreviewImg" src="" alt="Proof preview" style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #ddd;">
                        </div>
                    </div>
                    <div class="alert alert-warning d-none" id="apeAlert2"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-secondary d-none" id="apeBackBtn"><i class="feather-arrow-left"></i> Back</button>
                <button type="button" class="btn btn-primary" id="apeSubmitBtn">Send Request</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    // MediaPipe's wasm prints its internal glog/TFLite chatter straight to
    // the console (it appears even in Google's official examples,
    // github.com/google-ai-edge/mediapipe #5639) and is cosmetic. Silence
    // that noise — but only messages that look like MediaPipe internals
    // (glog W/I lines, "INFO:" prefixes, or known benign strings), so real
    // app errors are never hidden.
    (function () {
        var benign = [
            'OpenGL error checking is disabled',
            'Using NORM_RECT without IMAGE_DIMENSIONS',
            'Sets FaceBlendshapesGraph acceleration to xnnpack by default'
        ];
        var glogRe = /^[WI]\d{4} \d{2}:\d{2}:\d{2}\.\d+ \d+ /;
        function matches(msg) {
            msg = String(msg == null ? '' : msg);
            if (glogRe.test(msg)) return true;
            if (/^(INFO|WARNING|WARN): /.test(msg)) return true;
            for (var i = 0; i < benign.length; i++) {
                if (msg.indexOf(benign[i]) !== -1) return true;
            }
            return false;
        }
        try {
            var origWarn = window.console.warn.bind(window.console);
            var origLog = window.console.log.bind(window.console);
            var origError = window.console.error.bind(window.console);
            window.console.warn = function () {
                if (matches(arguments.length ? arguments[0] : '')) return;
                origWarn.apply(window.console, arguments);
            };
            window.console.log = function () {
                if (matches(arguments.length ? arguments[0] : '')) return;
                origLog.apply(window.console, arguments);
            };
            // MediaPipe's glog output can land on stderr (console.error) too —
            // e.g. the XNNPACK delegate INFO line. Filter it the same way;
            // real errors never match the patterns above.
            window.console.error = function () {
                if (matches(arguments.length ? arguments[0] : '')) return;
                origError.apply(window.console, arguments);
            };
        } catch (e) {}
    })();
    (function () {
        function normalizeHex(raw) {
            var s = String(raw == null ? '' : raw).trim();
            if (!s) return '';
            if (s[0] !== '#') s = '#' + s;
            if (!/^#[0-9a-fA-F]{6}$/.test(s)) return '';
            return s.toUpperCase();
        }

        function clamp(v, min, max) {
            v = Number(v);
            if (v < min) return min;
            if (v > max) return max;
            return v;
        }

        function rgbToHex(r, g, b) {
            function p(n) {
                var s = clamp(Math.round(n), 0, 255).toString(16).toUpperCase();
                return s.length === 1 ? '0' + s : s;
            }
            return '#' + p(r) + p(g) + p(b);
        }

        function hexToRgb(hex) {
            var h = normalizeHex(hex);
            if (!h) return null;
            return {
                r: parseInt(h.slice(1, 3), 16),
                g: parseInt(h.slice(3, 5), 16),
                b: parseInt(h.slice(5, 7), 16)
            };
        }

        function mix(c1, c2, t) {
            return rgbToHex(
                c1.r + (c2.r - c1.r) * t,
                c1.g + (c2.g - c1.g) * t,
                c1.b + (c2.b - c1.b) * t
            );
        }

        function applyTheme(baseHex) {
            var base = normalizeHex(baseHex);
            if (!base) return;
            var rgb = hexToRgb(base);
            if (!rgb) return;
            var soft = mix(rgb, { r: 255, g: 255, b: 255 }, 0.65);
            var soft2 = mix(rgb, { r: 255, g: 255, b: 255 }, 0.55);
            var baseLight = mix(rgb, { r: 0, g: 0, b: 0 }, 0.12);
            var root = document.documentElement;
            root.style.setProperty('--ctr-theme-base', base);
            root.style.setProperty('--ctr-theme-soft', soft);
            root.style.setProperty('--ctr-theme-soft2', soft2);
            root.style.setProperty('--ctr-theme-base-light', baseLight);
        }

        function readCookie(name) {
            var n = String(name == null ? '' : name);
            if (!n) return '';
            var all = String(document.cookie || '');
            if (!all) return '';
            var parts = all.split(';');
            for (var i = 0; i < parts.length; i++) {
                var p = String(parts[i] || '').trim();
                if (!p) continue;
                var idx = p.indexOf('=');
                if (idx < 0) continue;
                var k = p.slice(0, idx).trim();
                if (k !== n) continue;
                var v = p.slice(idx + 1);
                try { return decodeURIComponent(v); } catch (e) { return v; }
            }
            return '';
        }

        function initThemeSync() {
            var base = '';
            try { base = normalizeHex(localStorage.getItem('ctr-theme-base') || ''); } catch (e) {}
            if (!base) base = normalizeHex(readCookie('ctr_theme_base') || '');
            if (base) applyTheme(base);

            window.addEventListener('storage', function (e) {
                if (!e || e.key !== 'ctr-theme-base') return;
                var c = normalizeHex(e.newValue || '');
                if (!c) return;
                applyTheme(c);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initThemeSync);
        } else {
            initThemeSync();
        }
    })();

    (function () {
        function el(id) { return document.getElementById(id); }
        function getSavedMode() {
            try {
                var v = String(localStorage.getItem('ctr-theme-mode') || '').trim();
                if (v === 'light' || v === 'dark') return v;
            } catch (e) {}
            return '';
        }
        function saveMode(mode) {
            try { localStorage.setItem('ctr-theme-mode', mode); } catch (e) {}
        }
        function applyMode(mode) {
            var m = mode === 'light' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', m);
            var toggle = el('homeThemeModeToggle');
            if (toggle) toggle.checked = m === 'light';
        }
        function initMode() {
            var saved = getSavedMode();
            var mode = saved;
            if (!mode) {
                try {
                    mode = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
                } catch (e) {
                    mode = 'dark';
                }
            }
            applyMode(mode);
            var toggle = el('homeThemeModeToggle');
            if (toggle) {
                toggle.addEventListener('change', function () {
                    var next = toggle.checked ? 'light' : 'dark';
                    applyMode(next);
                    saveMode(next);
                });
            }
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initMode);
        else initMode();
    })();

    (function () {
        function el(id) { return document.getElementById(id); }
        function pad2(n) { return String(n).padStart(2, '0'); }
        function normalizeHex(raw) {
            var s = String(raw == null ? '' : raw).trim();
            if (!s) return '';
            if (s[0] !== '#') s = '#' + s;
            if (!/^#[0-9a-fA-F]{6}$/.test(s)) return '';
            return s.toUpperCase();
        }
        function clamp(v, min, max) {
            v = Number(v);
            if (v < min) return min;
            if (v > max) return max;
            return v;
        }
        function hexToRgb(hex) {
            var h = normalizeHex(hex);
            if (!h) return null;
            return {
                r: parseInt(h.slice(1, 3), 16),
                g: parseInt(h.slice(3, 5), 16),
                b: parseInt(h.slice(5, 7), 16)
            };
        }
        function rgbToHex(r, g, b) {
            function p(n) {
                var s = clamp(Math.round(n), 0, 255).toString(16).toUpperCase();
                return s.length === 1 ? '0' + s : s;
            }
            return '#' + p(r) + p(g) + p(b);
        }
        function mix(c1, c2, t) {
            return rgbToHex(
                c1.r + (c2.r - c1.r) * t,
                c1.g + (c2.g - c1.g) * t,
                c1.b + (c2.b - c1.b) * t
            );
        }
        function applyThemeColor(baseHex) {
            var base = normalizeHex(baseHex);
            if (!base) return '';
            var rgb = hexToRgb(base);
            if (!rgb) return '';
            var soft = mix(rgb, { r: 255, g: 255, b: 255 }, 0.65);
            var soft2 = mix(rgb, { r: 255, g: 255, b: 255 }, 0.55);
            var root = document.documentElement;
            root.style.setProperty('--ctr-theme-base', base);
            root.style.setProperty('--ctr-theme-soft', soft);
            root.style.setProperty('--ctr-theme-soft2', soft2);
            try { localStorage.setItem('ctr-theme-base', base); } catch (e) {}
            try {
                fetch('theme.php?ajax=1&action=set', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8', 'Accept': 'application/json' },
                    body: 'color=' + encodeURIComponent(base)
                }).catch(function () {});
            } catch (e) {}
            return base;
        }
        function announce(id, msg) {
            var n = el(id);
            if (n) n.textContent = String(msg || '');
        }
        var focusState = { lastActive: null };
        function openModalWithFocus(id, focusId) {
            focusState.lastActive = document.activeElement || null;
            openModal(id);
            setTimeout(function () {
                var f = focusId ? el(focusId) : null;
                if (f && f.focus) {
                    try { f.focus(); } catch (e) {}
                    return;
                }
                var m = el(id);
                if (!m) return;
                var first = m.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (first && first.focus) {
                    try { first.focus(); } catch (e) {}
                }
            }, 0);
        }
        function closeModalRestoreFocus(id) {
            closeModal(id);
            var prev = focusState.lastActive;
            focusState.lastActive = null;
            if (prev && prev.focus) {
                try { prev.focus(); } catch (e) {}
            }
        }

        var trapState = { activeId: '' };
        function getFocusableIn(container) {
            if (!container || !container.querySelectorAll) return [];
            var nodes = container.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            var out = [];
            for (var i = 0; i < nodes.length; i++) {
                var n = nodes[i];
                if (!n) continue;
                if (n.hasAttribute && n.hasAttribute('disabled')) continue;
                if (n.getAttribute && n.getAttribute('aria-disabled') === 'true') continue;
                out.push(n);
            }
            return out;
        }

        function setTrapActive(modalId) {
            trapState.activeId = String(modalId || '');
        }

        function getTrapModalEl() {
            var id = trapState.activeId;
            if (!id) return null;
            var m = el(id);
            if (!m || !m.classList || !m.classList.contains('is-open')) return null;
            return m;
        }

        document.addEventListener('keydown', function (e) {
            if (!e || e.key !== 'Tab') return;
            var modal = getTrapModalEl();
            if (!modal) return;
            var focusable = getFocusableIn(modal);
            if (!focusable.length) return;
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            var active = document.activeElement;
            if (e.shiftKey) {
                if (active === first || active === modal) {
                    e.preventDefault();
                    try { last.focus(); } catch (err) {}
                }
                return;
            }
            if (active === last) {
                e.preventDefault();
                try { first.focus(); } catch (err2) {}
            }
        });

        var usageKey = 'ctr-home-usage-v1';
        function readJson(key, fallback) {
            try {
                var raw = localStorage.getItem(key);
                if (!raw) return fallback;
                var parsed = JSON.parse(raw);
                return parsed && typeof parsed === 'object' ? parsed : fallback;
            } catch (e) {
                return fallback;
            }
        }
        function writeJson(key, obj) {
            try { localStorage.setItem(key, JSON.stringify(obj || {})); } catch (e) {}
        }
        function bumpUsage(name) {
            var k = String(name || '');
            if (!k) return;
            var data = readJson(usageKey, {});
            data[k] = (Number(data[k] || 0) + 1);
            data.last_interaction_at = new Date().toISOString();
            writeJson(usageKey, data);
        }
        function formatUsage(data) {
            var d = data || {};
            var parts = [];
            function add(label, key) {
                var v = Number(d[key] || 0);
                parts.push(label + ': ' + v);
            }
            add('Scans opened', 'scan_open');
            add('Refresh clicks', 'refresh_click');
            add('Settings opened', 'settings_open');
            add('Profile opened', 'profile_open');
            return parts.join(' • ');
        }
        function updateUsageUi() {
            var n = el('homeUsageMetrics');
            if (!n) return;
            var data = readJson(usageKey, {});
            n.textContent = formatUsage(data) || '—';
        }

        var autoScanKey = 'ctr-home-autoscan';
        function getAutoScanPref() {
            try {
                var v = localStorage.getItem(autoScanKey);
                if (v === '0') return false;
                if (v === '1') return true;
                return true;
            } catch (e) {
                return true;
            }
        }
        function setAutoScanPref(on) {
            try { localStorage.setItem(autoScanKey, on ? '1' : '0'); } catch (e) {}
        }

        var perfKey = 'ctr-home-perf-last';
        function capturePerf() {
            try {
                if (!window.performance || !performance.getEntriesByType) return null;
                var nav = performance.getEntriesByType('navigation');
                if (!nav || !nav.length) return null;
                var n = nav[0];
                var ttfb = Math.max(0, Math.round((n.responseStart || 0) - (n.requestStart || 0)));
                var dcl = Math.max(0, Math.round(n.domContentLoadedEventEnd || 0));
                var load = Math.max(0, Math.round(n.loadEventEnd || 0));
                var out = { ttfb_ms: ttfb, dcl_ms: dcl, load_ms: load, at: new Date().toISOString() };
                writeJson(perfKey, out);
                return out;
            } catch (e) {
                return null;
            }
        }
        function formatPerf(p) {
            if (!p) return '—';
            return 'TTFB: ' + p.ttfb_ms + 'ms • DCL: ' + p.dcl_ms + 'ms • Load: ' + p.load_ms + 'ms';
        }
        function updatePerfUi() {
            var n = el('homePerfMetrics');
            if (!n) return;
            var data = readJson(perfKey, null);
            n.textContent = formatPerf(data);
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
        function formatDate(d) {
            return d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: '2-digit' });
        }
        function formatTime(d) {
            var h = d.getHours();
            var ap = h >= 12 ? 'PM' : 'AM';
            var h12 = h % 12;
            if (h12 === 0) h12 = 12;
            return pad2(h12) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds()) + ' ' + ap;
        }
        function tick() {
            var now = new Date();
            var d = el('homeDateText');
            var t = el('homeTimeText');
            if (d) d.textContent = formatDate(now);
            if (t) t.textContent = formatTime(now);
            var ct = el('homeClockTime');
            var cd = el('homeClockDate');
            if (ct) ct.textContent = formatTime(now);
            if (cd) cd.textContent = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'short', day: '2-digit' }).toUpperCase();
            var greet = el('homeClockGreet');
            if (greet) {
                var gh = now.getHours();
                var g = gh >= 5 && gh < 12 ? 'Good morning' : gh >= 12 && gh < 17 ? 'Good afternoon' : gh >= 17 && gh < 21 ? 'Good evening' : 'Good night';
                if (greet.textContent !== g) greet.textContent = g;
            }
        }

        function toYmd(d) {
            return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
        }

        function toYmdManila() {
            var offset = 8 * 60 * 60 * 1000;
            var d = new Date(Date.now() + offset);
            return d.getUTCFullYear() + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate());
        }

        function parseTimeParts(value) {
            var t = String(value == null ? '' : value).trim();
            if (!t) return null;
            var m = t.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
            if (!m) return null;
            return { hh: Number(m[1] || 0), mm: String(m[2] || '00'), ss: String(m[3] || '00') };
        }

        function formatTime12(value) {
            var p = parseTimeParts(value);
            if (!p) return '-';
            var ap = p.hh >= 12 ? 'PM' : 'AM';
            var h12 = p.hh % 12;
            if (h12 === 0) h12 = 12;
            return pad2(h12) + ':' + p.mm + ' ' + ap;
        }

        function formatTimeHM(value) {
            var p = parseTimeParts(value);
            if (!p) return '—';
            var h12 = p.hh % 12;
            if (h12 === 0) h12 = 12;
            return pad2(h12) + ':' + p.mm;
        }

        function setStatus(text) {
            var s = el('homeStatusText');
            if (s) s.textContent = String(text || '');
            var p = el('homeScannerStatusText');
            if (p) p.textContent = String(text || '');
        }

        function setHint(text) {
            var t = String(text || '');
            var panel = el('homePanelHint');
            if (panel) {
                panel.textContent = t;
                if (t.trim() === '') panel.style.display = 'none';
                else panel.style.display = '';
            }
            var modal = el('homeModalHint');
            if (modal) {
                modal.textContent = t;
                if (t.trim() === '') modal.style.display = 'none';
                else modal.style.display = '';
            }
        }

        function setResult(text) {
            var t = String(text || '');
            var panel = el('homePanelResult');
            if (panel) panel.textContent = t;
            var modal = el('homeModalResult');
            if (modal) modal.textContent = t;
        }

        var notifyState = {
            hideTimer: null,
            clearTimer: null,
            lastTarget: null
        };

        function getActiveNotifyTarget() {
            var m = el('homeScanModal');
            if (m && m.classList.contains('is-open')) return 'modal';
            return 'panel';
        }

        function getNotifyNodes(target) {
            if (target === 'modal') return { box: el('homeModalNotify'), text: el('homeModalNotifyText') };
            return { box: el('homePanelNotify'), text: el('homePanelNotifyText') };
        }

        function hideNotify(target, immediate) {
            var t = target || notifyState.lastTarget || getActiveNotifyTarget();
            var nodes = getNotifyNodes(t);
            if (notifyState.hideTimer) {
                clearTimeout(notifyState.hideTimer);
                notifyState.hideTimer = null;
            }
            if (notifyState.clearTimer) {
                clearTimeout(notifyState.clearTimer);
                notifyState.clearTimer = null;
            }
            if (!nodes.box || !nodes.text) return;
            nodes.box.classList.remove('is-show', 'is-success', 'is-error', 'is-warning');
            if (immediate) {
                nodes.text.textContent = '';
                return;
            }
            notifyState.clearTimer = setTimeout(function () {
                nodes.text.textContent = '';
            }, 220);
        }

        function showNotify(kind, text, opts) {
            var k = kind === 'error' ? 'error' : kind === 'warning' ? 'warning' : 'success';
            var target = (opts && opts.target) ? String(opts.target) : getActiveNotifyTarget();
            if (target !== 'modal' && target !== 'panel') target = getActiveNotifyTarget();
            notifyState.lastTarget = target;
            var nodes = getNotifyNodes(target);
            if (!nodes.box || !nodes.text) return;
            if (notifyState.hideTimer) clearTimeout(notifyState.hideTimer);
            if (notifyState.clearTimer) clearTimeout(notifyState.clearTimer);
            notifyState.hideTimer = null;
            notifyState.clearTimer = null;
            nodes.text.textContent = String(text || '');
            nodes.box.classList.remove('is-success', 'is-error', 'is-warning');
            nodes.box.classList.add('is-' + k);
            nodes.box.classList.add('is-show');
            var ms = opts && typeof opts.duration === 'number' ? opts.duration : (k === 'error' ? 1800 : 1400);
            notifyState.hideTimer = setTimeout(function () {
                hideNotify(target, false);
            }, ms);
        }

        function openModal(id) {
            var m = el(id);
            if (!m) return;
            m.classList.add('is-open');
            m.setAttribute('aria-hidden', 'false');
            setTrapActive(id);
        }

        function closeModal(id) {
            var m = el(id);
            if (!m) return;
            m.classList.remove('is-open');
            m.setAttribute('aria-hidden', 'true');
            if (String(trapState.activeId || '') === String(id || '')) setTrapActive('');
        }

        function fetchJson(url, opts) {
            return fetch(url, Object.assign({ headers: { 'Accept': 'application/json' } }, opts || {}))
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, data: d }; }); });
        }

        var kioskToken = <?php echo json_encode((string)$kioskToken, JSON_UNESCAPED_SLASHES); ?>;
        var homeOwnerAdminId = <?php echo (int)$homeAdminId; ?>;
        var homePlanExpiresAt = <?php echo json_encode((string)$homePlanExpiresAt, JSON_UNESCAPED_SLASHES); ?>;
        function withKiosk(url) {
            var t = String(kioskToken || '').trim();
            if (!t) return url;
            return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'kiosk_token=' + encodeURIComponent(t);
        }

        var offlineTtlMs = 24 * 60 * 60 * 1000;
        var offlinePrefix = 'ctr_home_offline:' + String(homeOwnerAdminId || 0) + ':';
        function offlineKey(k) { return offlinePrefix + String(k || ''); }
        function safeLsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
        function safeLsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
        function safeLsRemove(k) { try { localStorage.removeItem(k); } catch (e) {} }
        function nowMs() { return Date.now ? Date.now() : (new Date()).getTime(); }

        function parseManilaDateTimeToUtcMs(value) {
            var s = String(value == null ? '' : value).trim();
            if (!s) return 0;
            var m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
            if (!m) return 0;
            var y = Number(m[1] || 0);
            var mo = Number(m[2] || 1) - 1;
            var d = Number(m[3] || 1);
            var hh = Number(m[4] || 0);
            var mm = Number(m[5] || 0);
            var ss = Number(m[6] || 0);
            var utc = Date.UTC(y, mo, d, hh - 8, mm, ss, 0);
            return isFinite(utc) ? utc : 0;
        }

        function isPlanExpiredClient() {
            var exp = String(homePlanExpiresAt || '').trim();
            if (!exp) return false;
            var t = parseManilaDateTimeToUtcMs(exp);
            if (!t) return false;
            return nowMs() >= t;
        }

        function setLastOnline(ms) {
            safeLsSet(offlineKey('last_online_ms'), String(typeof ms === 'number' ? ms : nowMs()));
        }
        function getLastOnline() {
            var v = safeLsGet(offlineKey('last_online_ms'));
            var n = v ? Number(v) : 0;
            return isFinite(n) && n > 0 ? n : 0;
        }
        function canOperateOffline() {
            if (isPlanExpiredClient()) return false;
            var last = getLastOnline();
            if (!last) return false;
            return (nowMs() - last) <= offlineTtlMs;
        }

        function genId() {
            try {
                if (window.crypto && window.crypto.getRandomValues) {
                    var b = new Uint8Array(16);
                    window.crypto.getRandomValues(b);
                    var s = '';
                    for (var i = 0; i < b.length; i++) s += ('0' + b[i].toString(16)).slice(-2);
                    return s;
                }
            } catch (e) {}
            return String(nowMs()) + '_' + Math.random().toString(16).slice(2);
        }

        function openOfflineDb() {
            return new Promise(function (resolve, reject) {
                if (!('indexedDB' in window)) return reject(new Error('no-indexeddb'));
                var req = indexedDB.open('ctr_home_offline', 1);
                req.onupgradeneeded = function (e) {
                    var db = e.target.result;
                    if (!db.objectStoreNames.contains('qr_scans')) {
                        var s = db.createObjectStore('qr_scans', { keyPath: 'id' });
                        s.createIndex('created_at_ms', 'created_at_ms', { unique: false });
                    }
                };
                req.onsuccess = function () { resolve(req.result); };
                req.onerror = function () { reject(req.error || new Error('db-open-failed')); };
            });
        }

        function offlineLsReadQueue() {
            try {
                var raw = safeLsGet(offlineKey('queue')) || '[]';
                var arr = JSON.parse(raw);
                return Array.isArray(arr) ? arr : [];
            } catch (e) {
                return [];
            }
        }
        function offlineLsWriteQueue(arr) {
            try { safeLsSet(offlineKey('queue'), JSON.stringify(Array.isArray(arr) ? arr : [])); } catch (e) {}
        }

        function offlinePruneList(list) {
            var cut = nowMs() - offlineTtlMs;
            var arr = Array.isArray(list) ? list : [];
            arr = arr.filter(function (r) { return r && typeof r.created_at_ms === 'number' && r.created_at_ms >= cut; });
            if (arr.length > 400) arr = arr.slice(arr.length - 400);
            return arr;
        }

        function offlineCount() {
            return openOfflineDb().then(function (db) {
                return new Promise(function (resolve) {
                    var tx = db.transaction(['qr_scans'], 'readonly');
                    var store = tx.objectStore('qr_scans');
                    var req = store.getAll();
                    req.onsuccess = function () {
                        var arr = Array.isArray(req.result) ? req.result : [];
                        resolve(offlinePruneList(arr).length);
                    };
                    req.onerror = function () { resolve(offlinePruneList(offlineLsReadQueue()).length); };
                });
            }).catch(function () {
                return offlinePruneList(offlineLsReadQueue()).length;
            });
        }

        /**
         * Downscale/compress an oversized snapshot (a 720px PNG data URL is
         * ~1.5MB+ of characters) to a small JPEG so it fits inside the offline
         * queue's storage cap instead of being dropped. Returns a data URL
         * under the cap, or '' if it cannot be compressed.
         */
        function compressSnapshotForQueue(dataUrl) {
            var src = String(dataUrl || '');
            if (!src) return Promise.resolve('');
            return new Promise(function (resolve) {
                var img = new Image();
                img.onload = function () {
                    try {
                        var w = Number(img.width) || 0;
                        var h = Number(img.height) || 0;
                        if (!w || !h) { resolve(''); return; }
                        var targetW = 640;
                        var scale = Math.min(1, targetW / w);
                        var cw = Math.max(1, Math.floor(w * scale));
                        var ch = Math.max(1, Math.floor(h * scale));
                        var canvas = document.createElement('canvas');
                        canvas.width = cw;
                        canvas.height = ch;
                        var ctx = canvas.getContext('2d');
                        if (!ctx) { resolve(''); return; }
                        ctx.drawImage(img, 0, 0, cw, ch);
                        var out = canvas.toDataURL('image/jpeg', 0.7);
                        if (!out || out.length > 220000) out = canvas.toDataURL('image/jpeg', 0.5);
                        resolve(out && out.length <= 220000 ? out : '');
                    } catch (e) {
                        resolve('');
                    }
                };
                img.onerror = function () { resolve(''); };
                img.src = src;
            });
        }

        function offlineAddScan(rec) {
            var r = rec || {};
            var item = {
                id: String(r.id || genId()),
                qr_data: String(r.qr_data || ''),
                created_at_ms: typeof r.created_at_ms === 'number' ? r.created_at_ms : nowMs(),
                client_ts: typeof r.client_ts === 'number' ? r.client_ts : (typeof r.created_at_ms === 'number' ? r.created_at_ms : nowMs()),
                snapshot: (typeof r.snapshot === 'string' ? r.snapshot : ''),
            };
            if (!item.qr_data) return Promise.resolve(false);

            // Keep the photo for offline-queued scans: a 720px PNG snapshot is
            // ~1.5MB+ as a data URL and would exceed the queue's 220k-char cap
            // (dropping the picture). Compress oversized snapshots to a small
            // JPEG instead of discarding them.
            var snapReady = Promise.resolve(item.snapshot);
            if (item.snapshot && item.snapshot.length > 220000) {
                snapReady = compressSnapshotForQueue(item.snapshot);
            }
            return snapReady.then(function (snap) {
                item.snapshot = (typeof snap === 'string' && snap && snap.length <= 220000) ? snap : '';
                return offlineStoreScan(item);
            });
        }

        function offlineStoreScan(item) {
            return openOfflineDb().then(function (db) {
                return new Promise(function (resolve) {
                    var tx = db.transaction(['qr_scans'], 'readwrite');
                    var store = tx.objectStore('qr_scans');
                    var getAll = store.getAll();
                    getAll.onsuccess = function () {
                        var arr = offlinePruneList(Array.isArray(getAll.result) ? getAll.result : []);
                        var last = arr.length ? arr[arr.length - 1] : null;
                        if (last && String(last.qr_data || '') === item.qr_data && Math.abs(item.created_at_ms - (Number(last.created_at_ms || 0) || 0)) <= 2000) {
                            resolve(true);
                            return;
                        }
                        store.put(item);
                        resolve(true);
                    };
                    getAll.onerror = function () {
                        var arr2 = offlinePruneList(offlineLsReadQueue());
                        var last2 = arr2.length ? arr2[arr2.length - 1] : null;
                        if (last2 && String(last2.qr_data || '') === item.qr_data && Math.abs(item.created_at_ms - (Number(last2.created_at_ms || 0) || 0)) <= 2000) {
                            resolve(true);
                            return;
                        }
                        arr2.push(item);
                        offlineLsWriteQueue(offlinePruneList(arr2));
                        resolve(true);
                    };
                });
            }).catch(function () {
                var arr = offlinePruneList(offlineLsReadQueue());
                var last = arr.length ? arr[arr.length - 1] : null;
                if (last && String(last.qr_data || '') === item.qr_data && Math.abs(item.created_at_ms - (Number(last.created_at_ms || 0) || 0)) <= 2000) {
                    return true;
                }
                arr.push(item);
                offlineLsWriteQueue(offlinePruneList(arr));
                return true;
            });
        }

        function offlineListScans() {
            return openOfflineDb().then(function (db) {
                return new Promise(function (resolve) {
                    var tx = db.transaction(['qr_scans'], 'readonly');
                    var store = tx.objectStore('qr_scans');
                    var req = store.getAll();
                    req.onsuccess = function () { resolve(offlinePruneList(Array.isArray(req.result) ? req.result : [])); };
                    req.onerror = function () { resolve(offlinePruneList(offlineLsReadQueue())); };
                });
            }).catch(function () {
                return offlinePruneList(offlineLsReadQueue());
            });
        }

        function offlineRemoveScan(id) {
            var rid = String(id || '');
            if (!rid) return Promise.resolve();
            return openOfflineDb().then(function (db) {
                return new Promise(function (resolve) {
                    var tx = db.transaction(['qr_scans'], 'readwrite');
                    tx.objectStore('qr_scans').delete(rid);
                    tx.oncomplete = function () { resolve(); };
                    tx.onerror = function () { resolve(); };
                });
            }).catch(function () {
                var arr = offlinePruneList(offlineLsReadQueue()).filter(function (r) { return String(r && r.id ? r.id : '') !== rid; });
                offlineLsWriteQueue(arr);
            });
        }

        var offlineSyncState = { syncing: false };
        function syncOfflineScans() {
            if (offlineSyncState.syncing) return Promise.resolve(false);
            if (!navigator.onLine) return Promise.resolve(false);
            offlineSyncState.syncing = true;
            return offlineListScans().then(function (items) {
                var arr = Array.isArray(items) ? items : [];
                if (!arr.length) return false;
                var cut = nowMs() - offlineTtlMs;
                var active = arr.filter(function (r) { return r && typeof r.created_at_ms === 'number' && r.created_at_ms >= cut; });
                if (!active.length) {
                    return Promise.all(arr.map(function (r) { return offlineRemoveScan(r.id); })).then(function () { return false; });
                }
                active.sort(function (a, b) { return (a.created_at_ms || 0) - (b.created_at_ms || 0); });
                var synced = 0;
                return active.reduce(function (p, r) {
                    return p.then(function () {
                        var fd = new FormData();
                        fd.append('qr_data', String(r.qr_data || ''));
                        if (r.snapshot) fd.append('snapshot', String(r.snapshot));
                        fd.append('client_ts', String(Math.floor((Number(r.client_ts || r.created_at_ms || 0) || 0))));
                        var ownAdminId = Number(homeOwnerAdminId || 0);
                        if (ownAdminId > 0) fd.append('scanned_via', String(ownAdminId));
                        var t = String(kioskToken || '').trim();
                        if (t) fd.append('kiosk_token', t);
                        return fetch(withKiosk('attendance.php?ajax=1&action=scan'), { method: 'POST', body: fd })
                            .then(function (resp) {
                                return resp.json().then(function (d) { return { ok: resp.ok, status: resp.status, data: d }; });
                            })
                            .then(function (res) {
                                var d = res.data || {};
                                if ((res.ok && d && d.ok === true) || res.status === 409 || res.status === 400) {
                                    synced += 1;
                                    return offlineRemoveScan(r.id);
                                }
                            })
                            .catch(function () {});
                    });
                }, Promise.resolve()).then(function () {
                    if (synced > 0) {
                        showNotify('success', 'Synced ' + synced + ' offline scan' + (synced === 1 ? '' : 's') + '.', { duration: 1400 });
                        refreshLogs();
                    }
                    return synced > 0;
                });
            }).finally(function () {
                offlineSyncState.syncing = false;
            });
        }

        var state = {
            stream: null,
            scanning: false,
            detector: null,
            scanTimer: null,
            cooldown: false,
            jsQrPromise: null,
            lastDeviceId: null
        };

        function setLoading(id, on) {
            var n = el(id);
            if (!n) return;
            if (on) n.classList.add('is-on');
            else n.classList.remove('is-on');
        }

        function stopScanning() {
            state.scanning = false;
            state.cooldown = false;
            if (state.scanTimer) {
                clearTimeout(state.scanTimer);
                state.scanTimer = null;
            }
            state.detector = null;
            stopFaceLoop();
        }

        // =================== Face scanner (MediaPipe + 2-blink liveness) ===================
        var faceState = {
            mode: 'both',
            landmarker: null,
            landmarkerLoading: null,
            faceLoopActive: false,
            faceLoopTimer: null,
            faceLoopVideoTimeMs: -1,
            blinkCount: 0,
            blinkState: 'scanning',
            closedSince: 0,
            closedFrames: 0,
            openFrames: 0,
            lastDescriptor: null,
            recentEARs: [],
            cooldown: false,
            faceCooldown: false,
            lastSnapshotDataUrl: '',
            lastResetAt: 0,
        };

        // Label shown on the scanner title / headings for the current mode.
        function homeScanModeLabel() {
            if (faceState.mode === 'face') return 'Face Scanner';
            if (faceState.mode === 'both') return 'QR + Face';
            return 'QR Scanner';
        }

        // Update the "QR Scanner" title (panel + modal) to match the mode:
        // QR Scanner / Face Scanner / QR + Face.
        function setScanTitle() {
            var label = homeScanModeLabel();
            var t = el('homeScannerTitle');
            if (t) t.textContent = label;
            var mt = el('homeModalScanTitle');
            if (mt) mt.textContent = label;
            var sec = el('homeScannerSection');
            if (sec) sec.setAttribute('aria-label', label);
            var modal = el('homeScanModal');
            if (modal) modal.setAttribute('aria-label', label);
        }

        // Live eye-open indicator on the scanner: 'open' / 'closed' / 'partial'
        // (blinking), or null to hide it entirely.
        function setEyeState(state) {
            var ids = ['homePanelEyeState', 'homeModalEyeState'];
            for (var i = 0; i < ids.length; i++) {
                var box = el(ids[i]);
                if (!box) continue;
                if (!state) { box.hidden = true; continue; }
                box.hidden = false;
                var dot = box.querySelector('.home-eye-dot');
                if (dot) dot.className = 'home-eye-dot ' + state;
                var txt = box.querySelector('.home-eye-status-text');
                if (txt) txt.textContent = state === 'open' ? 'Eyes: Open' : (state === 'closed' ? 'Eyes: Closed' : 'Eyes: Blinking');
            }
        }

        function setFaceMode(mode) {
            if (mode !== 'qr' && mode !== 'face' && mode !== 'both') mode = 'qr';
            faceState.mode = mode;
            // Update active class on both sets of mode buttons
            ['homeModeQrBtn', 'homeModeFaceBtn', 'homeModeBothBtn',
             'homeModalModeQrBtn', 'homeModalModeFaceBtn', 'homeModalModeBothBtn'].forEach(function (id) {
                var b = el(id);
                if (!b) return;
                var isActive = b.getAttribute('data-mode') === mode;
                b.classList.toggle('is-active', isActive);
                b.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            // Show/hide face status pill
            var showFace = (mode === 'face' || mode === 'both');
            var pStatus = el('homePanelFaceStatus');
            var mStatus = el('homeModalFaceStatus');
            if (pStatus) pStatus.hidden = !showFace;
            if (mStatus) mStatus.hidden = !showFace;
            if (!showFace) {
                stopFaceLoop();
                if (pStatus) pStatus.textContent = '';
                if (mStatus) mStatus.textContent = '';
            } else {
                setFaceStatus('Face scanner ready. Look at the camera and blink twice.');
            }
            setScanTitle();
            // Update hint text
            var hint = el('homePanelHint');
            var mhint = el('homeModalHint');
            if (mode === 'qr') {
                if (hint) hint.textContent = 'Point the camera at the QR code';
                if (mhint) mhint.textContent = 'Point the camera at the QR code';
            } else if (mode === 'face') {
                if (hint) hint.textContent = 'Look at the camera and blink twice for face check-in';
                if (mhint) mhint.textContent = 'Look at the camera and blink twice for face check-in';
            } else {
                if (hint) hint.textContent = 'Show QR code or look at the camera and blink twice';
                if (mhint) mhint.textContent = 'Show QR code or look at the camera and blink twice';
            }
        }

        function setFaceStatus(text) {
            var pStatus = el('homePanelFaceStatus');
            var mStatus = el('homeModalFaceStatus');
            if (pStatus) pStatus.textContent = text || '';
            if (mStatus) mStatus.textContent = text || '';
        }

        // MediaPipe model sources, in priority order. MediaPipe's
        // FilesetResolver caches its wasm assets with the Cache API
        // (Cache.addAll), which rejects with "Request failed" whenever a
        // single CDN fetch fails — taking the whole scanner down with an
        // uncaught error. Trying the next mirror automatically makes the
        // loader self-heal through transient CDN failures.
        var homeMpSources = [
            {
                mp: 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.18/vision_bundle.mjs',
                wasm: 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.18/wasm',
                model: 'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task'
            },
            {
                mp: 'https://unpkg.com/@mediapipe/tasks-vision@0.10.18/vision_bundle.mjs',
                wasm: 'https://unpkg.com/@mediapipe/tasks-vision@0.10.18/wasm',
                model: 'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task'
            },
            {
                mp: 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.18/vision_bundle.mjs?cb=' + nowMs(),
                wasm: 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.18/wasm',
                model: 'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task'
            }
        ];

        function homeBuildLandmarkerFromSource(src) {
            return import(src.mp).then(function (mod) {
                var FilesetResolver = mod.FilesetResolver || (mod.default && mod.default.FilesetResolver);
                var FaceLandmarker = mod.FaceLandmarker || (mod.default && mod.default.FaceLandmarker);
                if (!FilesetResolver || !FaceLandmarker) {
                    throw new Error('MediaPipe module loaded but exports are missing');
                }
                return FilesetResolver.forVisionTasks(src.wasm).then(function (filesetResolver) {
                    return FaceLandmarker.createFromOptions(filesetResolver, {
                        baseOptions: { modelAssetPath: src.model, delegate: 'GPU' },
                        outputFaceBlendshapes: false,
                        outputFacialTransformationMatrixes: false,
                        runningMode: 'VIDEO',
                        numFaces: 1,
                        refineFaceLandmarks: true
                    });
                });
            });
        }

        function homeLoadLandmarkerWithFallback(store) {
            var idx = 0;
            function attempt() {
                if (idx >= homeMpSources.length) {
                    throw new Error('Face detection model could not be downloaded from any source. Check your internet connection and try again.');
                }
                var src = homeMpSources[idx++];
                return homeBuildLandmarkerFromSource(src).catch(function (err) {
                    if (idx >= homeMpSources.length) throw err;
                    return attempt();
                });
            }
            return attempt().then(function (lm) {
                if (store) {
                    store.landmarker = lm;
                    if ('modelsLoaded' in store) store.modelsLoaded = true;
                }
                return lm;
            });
        }

        function loadFaceLandmarker() {
            if (faceState.landmarker) return Promise.resolve(faceState.landmarker);
            if (faceState.landmarkerLoading) return faceState.landmarkerLoading;
            faceState.landmarkerLoading = homeLoadLandmarkerWithFallback(faceState).then(function (lm) {
                faceState.landmarkerLoading = null;
                return lm;
            }).catch(function (err) {
                faceState.landmarkerLoading = null;
                throw err;
            });
            return faceState.landmarkerLoading;
        }

        // face-api (@vladmandic/face-api) supplies the 128-dim learned face
        // descriptor (FaceRecognitionNet) that replaces the old 42-dim
        // geometric faceprint. Detection, 68-point alignment and recognition
        // are handled internally; MediaPipe FaceLandmarker remains responsible
        // for the blink-twice liveness gate. Loaded alongside the landmarker
        // and preloaded before the first capture so scans and enrollments send
        // v2 payloads.
        var homeFaceApiReady = false;
        var homeFaceApiLoading = null;
        function homeLoadFaceApi() {
            if (homeFaceApiReady) return Promise.resolve();
            if (homeFaceApiLoading) return homeFaceApiLoading;
            var MODEL = 'https://cdn.jsdelivr.net/gh/vladmandic/face-api/model';
            homeFaceApiLoading = new Promise(function (resolve, reject) {
                if (window.faceapi && window.faceapi.nets) { resolve(window.faceapi); return; }
                var s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/dist/face-api.js';
                s.onload = function () { resolve(window.faceapi); };
                s.onerror = function () { reject(new Error('Failed to load face-api')); };
                document.head.appendChild(s);
            }).then(function (api) {
                // Force the CPU backend. The default WebGL backend fails on many
                // kiosk/remote-desktop devices, and the fallback WASM backend
                // 404s from the jsDelivr CDN — both made every enrollment/scan
                // silently send a legacy print with no 128-dim embedding, so
                // "Re-enroll Needed" never cleared (and embed users could not
                // be matched). CPU needs no GPU/WASM and works everywhere;
                // inference is one-shot per capture/scan.
                var tf = api && api.tf;
                if (tf && typeof tf.setBackend === 'function') {
                    try {
                        return tf.setBackend('cpu').then(function () { return tf.ready(); }).then(function () { return api; });
                    } catch (e) { /* fall through to default backend */ }
                }
                return api;
            }).then(function (api) {
                return Promise.all([
                    api.nets.tinyFaceDetector.loadFromUri(MODEL),
                    api.nets.faceLandmark68Net.loadFromUri(MODEL),
                    api.nets.faceRecognitionNet.loadFromUri(MODEL)
                ]);
            }).then(function () {
                homeFaceApiReady = true;
                homeFaceApiLoading = null;
            }).catch(function (err) {
                homeFaceApiLoading = null;
                throw err;
            });
            return homeFaceApiLoading;
        }

        // Run face detection + recognition on a canvas: resolves with a
        // 128-dim FaceRecognitionNet descriptor array (or null if no face was
        // found or the model is unavailable).
        function homeEmbedCanvas(canvas) {
            if (!canvas) return Promise.resolve(null);
            return homeLoadFaceApi().then(function () {
                var opts = new window.faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.4 });
                return window.faceapi.detectSingleFace(canvas, opts).withFaceLandmarks().withFaceDescriptor();
            }).then(function (det) {
                return det && det.descriptor ? Array.prototype.slice.call(det.descriptor) : null;
            }).catch(function () { return null; });
        }

        // Build the v2 face_data payload:
        // {"v":2,"emb":[...128 floats...],"desc":[...42 floats...]}.
        // Falls back to the bare legacy descriptor array without an embedding.
        function homeBuildFacePayload(desc, emb) {
            if (emb && emb.length >= 128) {
                var p = { v: 2, emb: emb };
                if (desc && desc.length > 0) p.desc = desc;
                return p;
            }
            return desc;
        }

        // Embed the current camera frame (used by the kiosk scan path).
        function homeEmbedActiveVideo() {
            var v = _getActiveVideo();
            if (!v || !v.videoWidth || !v.videoHeight) return Promise.resolve(null);
            var canvas = document.createElement('canvas');
            canvas.width = v.videoWidth;
            canvas.height = v.videoHeight;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(v, 0, 0);
            return homeLoadFaceApi().then(function () {
                return homeEmbedCanvas(canvas);
            }).catch(function () { return null; });
        }

        // Compute the 128-dim embedding for a scan, retrying on fresh video
        // frames when a frame yields no usable face. `embedFrame` (the frame
        // captured at the moment the 2nd blink completed) is tried first;
        // subsequent attempts use the live video so a bad single frame (third
        // blink, head movement, blur) no longer fails the whole scan.
        function homeEmbedWithRetry(embedFrame, attemptsLeft) {
            var attempt = function () {
                if (embedFrame) {
                    var frame = embedFrame;
                    embedFrame = null;
                    return homeLoadFaceApi().then(function () {
                        return homeEmbedCanvas(frame);
                    }).catch(function () { return null; });
                }
                return homeEmbedActiveVideo();
            };
            return attempt().then(function (emb) {
                if (emb && emb.length >= 128) return emb;
                if (attemptsLeft > 0) {
                    return new Promise(function (resolve) {
                        setTimeout(function () {
                            resolve(homeEmbedWithRetry(null, attemptsLeft - 1));
                        }, 120);
                    });
                }
                return null;
            });
        }

        var MP_LEFT_EYE = [33, 160, 158, 133, 153, 144];
        var MP_RIGHT_EYE = [263, 387, 385, 362, 373, 380];
        var MP_FACEPRINT_IDX = [
            10, 152, 1, 234, 454, 61, 291,
            33, 133, 362, 263,
            70, 107, 336, 276,
            468, 473,
            168, 6, 351, 152,
            127, 356, 130, 359,
            162, 389, 21, 251, 284, 372,
            0, 17, 54, 287,
            37, 267, 82, 312,
            199, 419, 132, 361
        ];

        function _faceDist(a, b) {
            return Math.sqrt(Math.pow(a.x - b.x, 2) + Math.pow(a.y - b.y, 2));
        }
        function _earMP(landmarks, idx) {
            var p0 = landmarks[idx[0]], p1 = landmarks[idx[1]], p2 = landmarks[idx[2]];
            var p3 = landmarks[idx[3]], p4 = landmarks[idx[4]], p5 = landmarks[idx[5]];
            var v1 = _faceDist(p1, p5);
            var v2 = _faceDist(p2, p4);
            var h = _faceDist(p0, p3);
            if (h <= 0.0001) return 0;
            return (v1 + v2) / (2 * h);
        }
        function _buildFaceprint(landmarks) {
            var nose = landmarks[1], chin = landmarks[152];
            var faceH = _faceDist(nose, chin);
            if (faceH <= 0.0001) return null;
            var lo = landmarks[33], ro = landmarks[263];
            var ox = (lo.x + ro.x) / 2, oy = (lo.y + ro.y) / 2;
            var fp = [];
            for (var i = 0; i < MP_FACEPRINT_IDX.length; i++) {
                var p = landmarks[MP_FACEPRINT_IDX[i]];
                if (!p) continue;
                fp.push((p.x - ox) / faceH);
                fp.push((p.y - oy) / faceH);
            }
            // Angular features for better discrimination
            if (lo && nose && ro) {
                var a1 = Math.atan2(nose.y - lo.y, nose.x - lo.x);
                var a2 = Math.atan2(nose.y - ro.y, nose.x - ro.x);
                fp.push(a1 - a2);
            }
            var leftCheek = landmarks[234];
            var rightCheek = landmarks[454];
            if (leftCheek && nose && rightCheek) {
                var a3 = Math.atan2(nose.y - leftCheek.y, nose.x - leftCheek.x);
                var a4 = Math.atan2(nose.y - rightCheek.y, nose.x - rightCheek.x);
                fp.push(a3 - a4);
            }
            // Eye aspect ratio
            var li = landmarks[133], ri = landmarks[362];
            var el159 = landmarks[159], el145 = landmarks[145], er386 = landmarks[386], er374 = landmarks[374];
            if (lo && li && ro && ri && el159 && el145 && er386 && er374) {
                var eyeW = _faceDist(lo, ro);
                var eyeH = (_faceDist(el159, el145) + _faceDist(er386, er374)) / 2;
                if (eyeW > 0.0001) fp.push(eyeH / eyeW);
            }
            // Mouth-to-face ratio
            var ml = landmarks[61], mr = landmarks[291];
            if (ml && mr && leftCheek && rightCheek) {
                var mW = _faceDist(ml, mr);
                var fW = _faceDist(leftCheek, rightCheek);
                if (fW > 0.0001) fp.push(mW / fW);
            }
            return fp;
        }

        function startFaceLoop() {
            if (faceState.faceLoopActive) return;
            if (faceState.mode === 'qr') return;
            if (!faceState.landmarker) {
                setFaceStatus('Loading face detection model…');
                loadFaceLandmarker().then(function () {
                    if (faceState.mode !== 'qr') startFaceLoop();
                    // Preload face-api so the first capture has it ready.
                    homeLoadFaceApi().catch(function () {});
                }).catch(function (err) {
                    setFaceStatus('Failed to load face detection: ' + ((err && err.message) || err));
                });
                return;
            }
            faceState.faceLoopActive = true;
            faceState.blinkCount = 0;
            faceState.blinkState = 'scanning';
            faceState.closedSince = 0;
            faceState.closedFrames = 0;
            faceState.openFrames = 0;
            faceState.cooldown = false;
            faceState.faceCooldown = false;
            faceState.recentEARs = [];
            faceState.lastDescriptor = null;
            faceState.faceLoopVideoTimeMs = -1;
            setFaceStatus('Looking for face…');
            faceTick();
        }
        function stopFaceLoop() {
            faceState.faceLoopActive = false;
            if (faceState.faceLoopTimer) {
                clearTimeout(faceState.faceLoopTimer);
                faceState.faceLoopTimer = null;
            }
            faceState.blinkCount = 0;
            faceState.blinkState = 'scanning';
            faceState.recentEARs = [];
            faceState.faceCooldown = false;
            setEyeState(null);
            clearFaceTracker();
        }
        function resetFaceBlinkState() {
            faceState.blinkCount = 0;
            faceState.blinkState = 'scanning';
            faceState.closedSince = 0;
            faceState.closedFrames = 0;
            faceState.openFrames = 0;
            faceState.recentEARs = [];
            faceState.lastDescriptor = null;
        }
        function _getActiveVideo() {
            var m = el('homeScanModal');
            var modalOpen = !!(m && m.classList && m.classList.contains('is-open'));
            return modalOpen ? el('homeModalVideo') : el('homeScannerVideo');
        }
        function _getActiveFaceCanvas() {
            var m = el('homeScanModal');
            var modalOpen = !!(m && m.classList && m.classList.contains('is-open'));
            return modalOpen ? el('homeModalFaceCanvas') : el('homePanelFaceCanvas');
        }

        function drawFaceTracker(landmarks) {
            var canvas = _getActiveFaceCanvas();
            if (!canvas) return;
            var v = _getActiveVideo();
            if (!v) return;
            // Match canvas resolution to video
            var vw = v.videoWidth || 640;
            var vh = v.videoHeight || 480;
            if (canvas.width !== vw || canvas.height !== vh) {
                canvas.width = vw;
                canvas.height = vh;
            }
            var ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            if (!landmarks || landmarks.length === 0) return;
            // Compute bounding box from all landmarks
            var minX = 1, minY = 1, maxX = 0, maxY = 0;
            for (var i = 0; i < landmarks.length; i++) {
                var p = landmarks[i];
                if (p.x < minX) minX = p.x;
                if (p.y < minY) minY = p.y;
                if (p.x > maxX) maxX = p.x;
                if (p.y > maxY) maxY = p.y;
            }
            // Mirror the X coordinates (video is mirrored)
            var bx = (1 - maxX) * canvas.width;
            var by = minY * canvas.height;
            var bw = (maxX - minX) * canvas.width;
            var bh = (maxY - minY) * canvas.height;
            // Make it a square box using the larger dimension
            var side = Math.max(bw, bh);
            // Center the square on the face
            var cx = bx + bw / 2;
            var cy = by + bh / 2;
            // Add padding
            side += side * 0.3;
            bx = cx - side / 2;
            by = cy - side / 2;
            bw = side;
            bh = side;
            // Clamp to canvas bounds
            if (bx < 0) bx = 0;
            if (by < 0) by = 0;
            if (bx + bw > canvas.width) bx = canvas.width - bw;
            if (by + bh > canvas.height) by = canvas.height - bh;
            // Draw rounded square border
            var r = Math.min(16, side * 0.06);
            ctx.beginPath();
            ctx.moveTo(bx + r, by);
            ctx.lineTo(bx + bw - r, by);
            ctx.quadraticCurveTo(bx + bw, by, bx + bw, by + r);
            ctx.lineTo(bx + bw, by + bh - r);
            ctx.quadraticCurveTo(bx + bw, by + bh, bx + bw - r, by + bh);
            ctx.lineTo(bx + r, by + bh);
            ctx.quadraticCurveTo(bx, by + bh, bx, by + bh - r);
            ctx.lineTo(bx, by + r);
            ctx.quadraticCurveTo(bx, by, bx + r, by);
            ctx.closePath();
            ctx.strokeStyle = 'rgba(34, 197, 94, 0.8)';
            ctx.lineWidth = 3;
            ctx.stroke();
            // Draw corner brackets for a modern look
            var cLen = Math.min(24, side * 0.15);
            ctx.strokeStyle = 'rgba(34, 197, 94, 1)';
            ctx.lineWidth = 4;
            ctx.lineCap = 'round';
            // Top-left
            ctx.beginPath();
            ctx.moveTo(bx, by + cLen); ctx.lineTo(bx, by); ctx.lineTo(bx + cLen, by);
            ctx.stroke();
            // Top-right
            ctx.beginPath();
            ctx.moveTo(bx + bw - cLen, by); ctx.lineTo(bx + bw, by); ctx.lineTo(bx + bw, by + cLen);
            ctx.stroke();
            // Bottom-left
            ctx.beginPath();
            ctx.moveTo(bx, by + bh - cLen); ctx.lineTo(bx, by + bh); ctx.lineTo(bx + cLen, by + bh);
            ctx.stroke();
            // Bottom-right
            ctx.beginPath();
            ctx.moveTo(bx + bw - cLen, by + bh); ctx.lineTo(bx + bw, by + bh); ctx.lineTo(bx + bw, by + bh - cLen);
            ctx.stroke();
        }

        function clearFaceTracker() {
            var canvas = _getActiveFaceCanvas();
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }

        function faceTick() {
            if (!faceState.faceLoopActive) return;
            var v = _getActiveVideo();
            if (!v || !v.srcObject || v.readyState < 2) {
                faceState.faceLoopTimer = setTimeout(faceTick, 120);
                return;
            }
            if (faceState.faceCooldown) {
                faceState.faceLoopTimer = setTimeout(faceTick, 250);
                return;
            }
            var nowMs = performance.now();
            if (nowMs === faceState.faceLoopVideoTimeMs) nowMs += 1;
            faceState.faceLoopVideoTimeMs = nowMs;
            var mp = null;
            try { mp = faceState.landmarker.detectForVideo(v, nowMs); }
            catch (e) { faceState.faceLoopTimer = setTimeout(faceTick, 80); return; }
            if (!mp || !mp.faceLandmarks || mp.faceLandmarks.length === 0) {
                clearFaceTracker();
                setEyeState(null);
                // Don't override the "Look at the camera and blink twice." prompt
                // with "Looking for face…" during the short grace period after a reset,
                // otherwise the user sees a flicker right after a successful scan.
                var sinceReset = Date.now() - (faceState.lastResetAt || 0);
                if (sinceReset > 2500) {
                    setFaceStatus('Looking for face… Please face the camera.');
                }
                faceState.faceLoopTimer = setTimeout(faceTick, 100);
                return;
            }
            var lm = mp.faceLandmarks[0];
            drawFaceTracker(lm);
            var leftEAR = _earMP(lm, MP_LEFT_EYE);
            var rightEAR = _earMP(lm, MP_RIGHT_EYE);
            var ear = (leftEAR + rightEAR) / 2;
            var fp = _buildFaceprint(lm);
            if (fp) faceState.lastDescriptor = fp;

            // Rolling peak EAR (mirrors the enrollment algorithm)
            if (faceState.recentEARs.length >= 30) faceState.recentEARs.shift();
            faceState.recentEARs.push(ear);
            var peakEAR = 0;
            for (var pi = 0; pi < faceState.recentEARs.length; pi++) {
                if (faceState.recentEARs[pi] > peakEAR) peakEAR = faceState.recentEARs[pi];
            }
            var useRatio = faceState.recentEARs.length >= 8;
            var closeT = useRatio ? (peakEAR * 0.55) : 0.15;
            var openT  = useRatio ? (peakEAR * 0.78) : 0.20;
            setEyeState(ear <= closeT ? 'closed' : (ear >= openT ? 'open' : 'partial'));
            var now = Date.now();
            if (ear <= closeT) {
                faceState.closedFrames++;
                if (faceState.blinkState === 'scanning' && faceState.closedFrames >= 1) {
                    faceState.blinkState = 'eyes_closed';
                    faceState.closedSince = now;
                    faceState.openFrames = 0;
                }
            } else if (ear >= openT) {
                if (faceState.blinkState === 'eyes_closed') {
                    faceState.openFrames++;
                    if (faceState.openFrames >= 2) {
                        var closedFor = now - faceState.closedSince;
                        if (closedFor >= 35 && closedFor <= 1500 && !faceState.cooldown) {
                            faceState.blinkCount++;
                            faceState.cooldown = true;
                            if (faceState.blinkCount >= 2) {
                                setFaceStatus('2 blinks detected! Recognizing face…');
                                faceState.faceCooldown = true;
                                var desc = faceState.lastDescriptor ? faceState.lastDescriptor.slice() : null;
                                // Grab the frame right now (face detected, eyes open) so the
                                // embedding uses the same moment as the descriptor instead of
                                // a frame captured after an 80ms delay, which can catch a
                                // third blink or head movement and make the embedding fail.
                                var embedFrame = null;
                                var vTmp = _getActiveVideo();
                                if (vTmp && vTmp.videoWidth && vTmp.videoHeight) {
                                    var cvTmp = document.createElement('canvas');
                                    cvTmp.width = vTmp.videoWidth;
                                    cvTmp.height = vTmp.videoHeight;
                                    var cxTmp = cvTmp.getContext('2d');
                                    if (cxTmp) {
                                        try { cxTmp.drawImage(vTmp, 0, 0); embedFrame = cvTmp; } catch (e2) { embedFrame = null; }
                                    }
                                }
                                setTimeout(function () { captureAndProcessFace(desc, embedFrame); }, 80);
                            } else {
                                setFaceStatus('Blink 1 detected! Blink once more…');
                            }
                            setTimeout(function () { faceState.cooldown = false; }, 450);
                        }
                        faceState.blinkState = 'scanning';
                        faceState.closedFrames = 0;
                        faceState.openFrames = 0;
                    }
                } else {
                    faceState.closedFrames = 0;
                    faceState.openFrames = 0;
                }
            } else {
                if (faceState.blinkState === 'eyes_closed' && (now - faceState.closedSince) > 1500) {
                    faceState.blinkState = 'scanning';
                    faceState.closedFrames = 0;
                    faceState.openFrames = 0;
                }
            }
            faceState.faceLoopTimer = setTimeout(faceTick, 50);
        }

        function captureAndProcessFace(descriptor, embedFrame) {
            if (!descriptor || descriptor.length < 8) {
                setFaceStatus('No face captured, try again.');
                faceState.faceCooldown = false;
                return;
            }
            // Build a v2 payload: 128-dim face-api descriptor (Euclidean
            // matching) plus the legacy 42-dim descriptor so stored legacy
            // prints still match. The embedding is retried on fresh frames if
            // the first attempt fails: a null embedding silently degrades the
            // scan to a legacy-only capture, which cannot match v2 enrollments
            // (the intermittent "Face not recognized" on enrolled faces).
            var embedFailed = false;
            var payloadPromise = homeEmbedWithRetry(embedFrame, 3)
                .then(function (emb) {
                    embedFailed = !(emb && emb.length >= 128);
                    return homeBuildFacePayload(descriptor, emb);
                }).catch(function () { embedFailed = true; return descriptor; });
            return Promise.all([payloadPromise, captureSnapshotAny()]).then(function (parts) {
                var payload = parts[0];
                var snap = parts[1];
                var fd = new FormData();
                fd.append('face_data', JSON.stringify(payload));
                if (snap) fd.append('snapshot', snap);
                if (embedFailed) fd.append('embed_failed', '1');
                fd.append('client_ts', String(Date.now()));
                var ownAdminId = Number(homeOwnerAdminId || 0);
                if (ownAdminId > 0) fd.append('scanned_via', String(ownAdminId));
                var t = String(kioskToken || '').trim();
                if (t) fd.append('kiosk_token', t);
                setFaceStatus('Sending to server…');
                return fetch(withKiosk('attendance.php?ajax=1&action=face_scan'), { method: 'POST', body: fd })
                    .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, data: d }; }); })
                    .then(function (res) {
                        var data = res.data || {};
                        if (!res.ok || !data || data.ok !== true) {
                            var msg = (data && data.message) ? String(data.message) : 'Face not recognized.';
                            // Duplicate attendance (409) — show as warning, not error
                            var isDuplicate = res.status === 409 && data && data.match === true;
                            if (isDuplicate) {
                                setFaceStatus(msg);
                                setStatus('Duplicate');
                                setHint(msg);
                                setResult(msg);
                                showNotify('warning', msg, { target: 'modal', duration: 2200 });
                                setTimeout(function () { refreshLogs(); }, 400);
                                return false;
                            }
                            if (data && data.best_distance !== undefined && data.best_distance !== null) {
                                msg += ' (dist: ' + Number(data.best_distance).toFixed(3) + ' / thr: ' + Number(data.threshold || 0.45).toFixed(2) + ', dim: ' + (data.in_dimensions || '?') + ')';
                            }
                            setFaceStatus(msg);
                            setStatus('Error');
                            setHint(msg);
                            setResult(msg);
                            showNotify('error', msg, { target: 'modal', duration: 1800 });
                            return false;
                        }
                        setLastOnline(nowMs());
                        setStatus('Recorded');
                        var okMsg = data.message ? String(data.message) : 'Face recorded.';
                        setFaceStatus(okMsg);
                        setHint(okMsg);
                        setResult(okMsg);
                        showNotify('success', okMsg, { target: 'modal', duration: 1500 });
                        setTimeout(function () {
                            if (state.scanning) setStatus('Ready');
                            setFaceStatus('Look at the camera and blink twice.');
                        }, 1400);
                        // Update last-record panel from response
                        var scanLog = data.log || null;
                        if (scanLog) {
                            if (el('homeLastName')) el('homeLastName').textContent = scanLog.full_name || '--';
                            if (el('homeLastDept')) el('homeLastDept').textContent = scanLog.position || scanLog.department || '--';
                            if (scanLog.time_in) {
                                if (el('homeLastAmIn') && scanLog.session === 'morning') el('homeLastAmIn').textContent = formatTime12(scanLog.time_in);
                                if (el('homeLastPmIn') && scanLog.session === 'afternoon') el('homeLastPmIn').textContent = formatTime12(scanLog.time_in);
                            }
                            if (scanLog.time_out) {
                                if (el('homeLastAmOut') && scanLog.session === 'morning') el('homeLastAmOut').textContent = formatTime12(scanLog.time_out);
                                if (el('homeLastPmOut') && scanLog.session === 'afternoon') el('homeLastPmOut').textContent = formatTime12(scanLog.time_out);
                            }
                            if (el('homeModalLastAmIn') && scanLog.session === 'morning' && scanLog.time_in) el('homeModalLastAmIn').textContent = formatTime12(scanLog.time_in);
                            if (el('homeModalLastAmOut') && scanLog.session === 'morning' && scanLog.time_out) el('homeModalLastAmOut').textContent = formatTime12(scanLog.time_out);
                            if (el('homeModalLastPmIn') && scanLog.session === 'afternoon' && scanLog.time_in) el('homeModalLastPmIn').textContent = formatTime12(scanLog.time_in);
                            if (el('homeModalLastPmOut') && scanLog.session === 'afternoon' && scanLog.time_out) el('homeModalLastPmOut').textContent = formatTime12(scanLog.time_out);
                        }
                        setTimeout(function () { refreshLogs(); }, 400);
                        try { syncOfflineScans(); } catch (e2) {}
                        return true;
                    })
                    .catch(function () {
                        setFaceStatus('Network error.');
                        setStatus('Error');
                        setHint('Network error');
                        setResult('Network error');
                        showNotify('error', 'Network error', { target: 'modal', duration: 1800 });
                        return false;
                    })
                    .then(function () {
                        // Reset for next face
                        setTimeout(function () {
                            faceState.faceCooldown = false;
                            resetFaceBlinkState();
                            faceState.lastResetAt = Date.now();
                            if (faceState.faceLoopActive) {
                                setFaceStatus('Look at the camera and blink twice.');
                            }
                        }, 1300);
                    });
            });
        }

        // =================== End face scanner ===================

        function stopAllCamera() {
            stopScanning();
            var v1 = el('homeModalVideo');
            var v2 = el('homeScannerVideo');
            [v1, v2].forEach(function (v) {
                if (!v) return;
                try { v.pause(); } catch (e) {}
                try { v.srcObject = null; } catch (e) {}
            });
            if (state.stream && state.stream.getTracks) {
                state.stream.getTracks().forEach(function (t) {
                    try { t.stop(); } catch (e) {}
                });
            }
            state.stream = null;
        }

        function bindStreamToVideos() {
            var s = state.stream;
            if (!s) return;
            // Only bind to the active video element to prevent camera conflicts
            var modal = el('homeScanModal');
            var modalOpen = !!(modal && modal.classList && modal.classList.contains('is-open'));
            if (modalOpen) {
                // Modal is open - bind only to modal video
                var mv = el('homeModalVideo');
                if (mv && mv.srcObject !== s) mv.srcObject = s;
                // Clear panel video
                var pv = el('homeScannerVideo');
                if (pv && pv.srcObject) pv.srcObject = null;
            } else {
                // Panel mode - bind only to panel video
                var pv2 = el('homeScannerVideo');
                if (pv2 && pv2.srcObject !== s) pv2.srcObject = s;
                // Clear modal video
                var mv2 = el('homeModalVideo');
                if (mv2 && mv2.srcObject) mv2.srcObject = null;
            }
        }

        function applyTrackAutoSettings(track) {
            if (!track || !track.applyConstraints || !track.getCapabilities) return Promise.resolve();
            var caps = null;
            try { caps = track.getCapabilities(); } catch (e) { caps = null; }
            var adv = [];
            function addMode(key, preferred, fallback) {
                if (!caps || !caps[key] || !Array.isArray(caps[key])) return;
                if (preferred && caps[key].indexOf(preferred) >= 0) {
                    var o1 = {}; o1[key] = preferred; adv.push(o1); return;
                }
                if (fallback && caps[key].indexOf(fallback) >= 0) {
                    var o2 = {}; o2[key] = fallback; adv.push(o2); return;
                }
            }
            addMode('focusMode', 'continuous', 'auto');
            addMode('exposureMode', 'continuous', 'auto');
            addMode('whiteBalanceMode', 'continuous', 'auto');
            if (!adv.length) return Promise.resolve();
            try {
                return Promise.resolve(track.applyConstraints({ advanced: adv })).catch(function () { return; });
            } catch (e2) {
                return Promise.resolve();
            }
        }

        function waitForVideoReady(video, timeoutMs) {
            var ms = typeof timeoutMs === 'number' ? timeoutMs : 900;
            return new Promise(function (resolve) {
                if (!video) return resolve(false);
                if (video.readyState >= 2 && (video.videoWidth || 0) > 0 && (video.videoHeight || 0) > 0) return resolve(true);
                var done = false;
                var t = null;
                function finish(ok) {
                    if (done) return;
                    done = true;
                    try { video.removeEventListener('loadedmetadata', onEvent); } catch (e) {}
                    try { video.removeEventListener('playing', onEvent); } catch (e2) {}
                    try { video.removeEventListener('resize', onEvent); } catch (e3) {}
                    if (t) clearTimeout(t);
                    resolve(!!ok);
                }
                function onEvent() {
                    if ((video.videoWidth || 0) > 0 && (video.videoHeight || 0) > 0) finish(true);
                }
                try { video.addEventListener('loadedmetadata', onEvent, { once: false }); } catch (e4) {}
                try { video.addEventListener('playing', onEvent, { once: false }); } catch (e5) {}
                try { video.addEventListener('resize', onEvent, { once: false }); } catch (e6) {}
                t = setTimeout(function () { finish(video.readyState >= 2 && (video.videoWidth || 0) > 0 && (video.videoHeight || 0) > 0); }, ms);
                Promise.resolve().then(function () { return tryPlay(video); }).then(onEvent).catch(function () {});
            });
        }

        function tryPlay(video) {
            if (!video) return Promise.resolve();
            try {
                return Promise.resolve(video.play()).catch(function () { return; });
            } catch (e) {
                return Promise.resolve();
            }
        }

        function loadJsQr() {
            if (state.jsQrPromise) return state.jsQrPromise;
            if (window.jsQR) {
                state.jsQrPromise = Promise.resolve(true);
                return state.jsQrPromise;
            }
            state.jsQrPromise = new Promise(function (resolve) {
                var s = document.createElement('script');
                s.src = 'assets/js/cdn-qrcode.js';
                s.async = true;
                s.onload = function () { resolve(!!window.jsQR); };
                s.onerror = function () { resolve(false); };
                document.head.appendChild(s);
            });
            return state.jsQrPromise;
        }

        function ensureCameraStream() {
            var modal = el('homeScanModal');
            var modalOpen = !!(modal && modal.classList && modal.classList.contains('is-open'));
            var sel = modalOpen ? el('homeModalCameraSelect') : el('homeCameraSelect');
            if (!sel) sel = el('homeCameraSelect') || el('homeModalCameraSelect');
            if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
                setHint('Camera API not available in this browser.');
                showNotify('error', 'Camera API not available in this browser.', { duration: 2000 });
                return Promise.resolve(false);
            }
            var deviceId = sel && sel.value ? String(sel.value) : '';
            if (!deviceId && state.lastDeviceId) deviceId = state.lastDeviceId;

            var panelSel = el('homeCameraSelect');
            var modalSel = el('homeModalCameraSelect');
            if (deviceId) {
                if (panelSel && String(panelSel.value || '') !== deviceId) panelSel.value = deviceId;
                if (modalSel && String(modalSel.value || '') !== deviceId) modalSel.value = deviceId;
            }

            // Always restart camera to ensure proper initialization
            // (prevents black screen when modal reopens)

            setHint('Starting camera...');
            setLoading('homePanelLoading', true);
            setLoading('homeModalLoading', true);
            stopAllCamera();

            state.lastDeviceId = deviceId || null;
            var constraints = {
                video: deviceId
                    ? { deviceId: { exact: deviceId }, width: { ideal: 1280 }, height: { ideal: 720 }, aspectRatio: { ideal: 1.7777778 } }
                    : { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 }, aspectRatio: { ideal: 1.7777778 } },
                audio: false
            };

            return navigator.mediaDevices.getUserMedia(constraints).catch(function () {
                return navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            }).then(function (s) {
                state.stream = s;
                bindStreamToVideos();
                var track = null;
                try { track = s.getVideoTracks ? (s.getVideoTracks()[0] || null) : null; } catch (e) { track = null; }
                // Determine which video element has the stream
                var modal = el('homeScanModal');
                var modalOpen = !!(modal && modal.classList && modal.classList.contains('is-open'));
                var activeVideo = modalOpen ? el('homeModalVideo') : el('homeScannerVideo');
                return applyTrackAutoSettings(track).then(function () {
                    return tryPlay(activeVideo);
                }).then(function () {
                    return waitForVideoReady(activeVideo, 1500).then(function () { return true; });
                }).then(function () {
                    setLoading('homePanelLoading', false);
                    setLoading('homeModalLoading', false);
                    setHint('Point the camera at the QR code');
                    return true;
                });
            }).catch(function (err) {
                setLoading('homePanelLoading', false);
                setLoading('homeModalLoading', false);
                stopAllCamera();
                var name = err && err.name ? String(err.name) : '';
                var msg = 'Unable to start camera.';
                if (name === 'NotAllowedError' || name === 'SecurityError') msg = 'Camera permission denied.';
                else if (name === 'NotFoundError' || name === 'OverconstrainedError') msg = 'No suitable camera found.';
                else if (name === 'NotReadableError') msg = 'Camera is in use by another application.';
                setStatus('Error');
                setHint(msg);
                setResult(msg);
                showNotify('error', msg, { duration: 2400 });
                return false;
            });
        }

        function captureSnapshot(video) {
            var vw = video.videoWidth || 0;
            var vh = video.videoHeight || 0;
            if (!vw || !vh) return '';
            var canvas = document.createElement('canvas');
            var targetW = 720;
            var scale = Math.min(1, targetW / vw);
            var w = Math.max(1, Math.floor(vw * scale));
            var h = Math.max(1, Math.floor(vh * scale));
            canvas.width = w;
            canvas.height = h;
            var ctx = canvas.getContext('2d');
            if (!ctx) return '';
            try {
                ctx.drawImage(video, 0, 0, w, h);
                return canvas.toDataURL('image/png');
            } catch (e) {
                return '';
            }
        }

        function blobToPngDataUrl(blob) {
            return new Promise(function (resolve) {
                function done(v) { resolve(typeof v === 'string' ? v : ''); }
                try {
                    if (!blob) return done('');
                    var targetW = 720;
                    var toCanvas = function (img) {
                        try {
                            var w0 = Number(img && img.width ? img.width : 0) || 0;
                            var h0 = Number(img && img.height ? img.height : 0) || 0;
                            if (!w0 || !h0) return done('');
                            var scale = Math.min(1, targetW / w0);
                            var w = Math.max(1, Math.floor(w0 * scale));
                            var h = Math.max(1, Math.floor(h0 * scale));
                            var canvas = document.createElement('canvas');
                            canvas.width = w;
                            canvas.height = h;
                            var ctx = canvas.getContext('2d');
                            if (!ctx) return done('');
                            ctx.drawImage(img, 0, 0, w, h);
                            return done(canvas.toDataURL('image/png'));
                        } catch (e2) {
                            return done('');
                        }
                    };
                    if (window.createImageBitmap) {
                        return Promise.resolve(window.createImageBitmap(blob)).then(function (bmp) {
                            toCanvas(bmp);
                        }).catch(function () {
                            done('');
                        });
                    }
                    var url = '';
                    try { url = URL.createObjectURL(blob); } catch (e3) { url = ''; }
                    if (!url) return done('');
                    var img = new Image();
                    img.onload = function () {
                        try { URL.revokeObjectURL(url); } catch (e4) {}
                        toCanvas(img);
                    };
                    img.onerror = function () {
                        try { URL.revokeObjectURL(url); } catch (e5) {}
                        done('');
                    };
                    img.src = url;
                } catch (e) {
                    done('');
                }
            });
        }

        function captureSnapshotAny() {
            var modal = el('homeScanModal');
            var modalOpen = !!(modal && modal.classList && modal.classList.contains('is-open'));
            var v = modalOpen ? el('homeModalVideo') : el('homeScannerVideo');
            return waitForVideoReady(v, 900).then(function () {
                var snap2 = v && (v.videoWidth || 0) > 0 ? captureSnapshot(v) : '';
                if (snap2) return snap2;
                var track = null;
                try { track = state.stream && state.stream.getVideoTracks ? (state.stream.getVideoTracks()[0] || null) : null; } catch (e) { track = null; }
                if (!track || !('ImageCapture' in window)) return '';
                try {
                    var ic = new window.ImageCapture(track);
                    if (ic && ic.takePhoto) {
                        return Promise.resolve(ic.takePhoto()).then(function (blob) { return blobToPngDataUrl(blob); }).catch(function () { return ''; });
                    }
                } catch (e2) {}
                return '';
            });
        }

        function refreshLogs() {
            var rBtn = el('homeRefreshBtn');
            if (rBtn) rBtn.classList.add('is-spinning');
            var today = toYmdManila();
            return fetchJson(withKiosk('attendance.php?ajax=1&action=list_logs&date=' + encodeURIComponent(today)))
                .then(function (res) {
                    if (!res.ok || !res.data || res.data.ok !== true) return;
                    var logs = Array.isArray(res.data.logs) ? res.data.logs : [];
                    var sorted = sortLogsOldToNew(logs);
                    var ownerId = Number(homeOwnerAdminId || 0);
                    var primary = sorted;
                    var other = [];
                    if (ownerId > 0) {
                        primary = sorted.filter(function (r) { return Number(r && r.owner_admin_id != null ? r.owner_admin_id : 0) === ownerId; });
                        other = sorted.filter(function (r) {
                            var oid = Number(r && r.owner_admin_id != null ? r.owner_admin_id : 0);
                            var sv = Number(r && r.scanned_via != null ? r.scanned_via : 0);
                            return oid !== ownerId && sv === ownerId;
                        });
                    }

                    renderRecent(primary);
                    if (primary.length) renderLast(primary[primary.length - 1]);
                    else renderLast({});
                    renderOther(other);
                    setLastOnline(nowMs());
                    try {
                        safeLsSet(offlineKey('cached_logs_date'), String(today));
                        safeLsSet(offlineKey('cached_logs_at_ms'), String(nowMs()));
                        safeLsSet(offlineKey('cached_logs'), JSON.stringify(sorted));
                    } catch (e) {}
                    try { syncOfflineScans(); } catch (e2) {}
                })
                .catch(function () {
                    if (navigator.onLine) return;
                    try {
                        var raw = safeLsGet(offlineKey('cached_logs')) || '';
                        var arr = raw ? JSON.parse(raw) : [];
                        if (!Array.isArray(arr)) arr = [];
                        if (arr.length) {
                            var sorted = sortLogsOldToNew(arr);
                            var ownerId = Number(homeOwnerAdminId || 0);
                            var primary = sorted;
                            var other = [];
                            if (ownerId > 0) {
                                primary = sorted.filter(function (r) { return Number(r && r.owner_admin_id != null ? r.owner_admin_id : 0) === ownerId; });
                                other = sorted.filter(function (r) {
                                    var oid = Number(r && r.owner_admin_id != null ? r.owner_admin_id : 0);
                                    var sv = Number(r && r.scanned_via != null ? r.scanned_via : 0);
                                    return oid !== ownerId && sv === ownerId;
                                });
                            }
                            renderRecent(primary);
                            if (primary.length) renderLast(primary[primary.length - 1]);
                            else renderLast({});
                            renderOther(other);
                            setStatus('Offline');
                            setHint('Offline • showing cached logs');
                            return;
                        }
                    } catch (e3) {}
                })
                .finally(function () {
                    var rBtn2 = el('homeRefreshBtn');
                    if (rBtn2) rBtn2.classList.remove('is-spinning');
                });
        }

        var autoRefreshState = { timerId: null, intervalMs: 7000 };
        function stopLogsAutoRefresh() {
            if (autoRefreshState.timerId) {
                clearTimeout(autoRefreshState.timerId);
                autoRefreshState.timerId = null;
            }
        }
        function startLogsAutoRefresh() {
            stopLogsAutoRefresh();
            function step() {
                stopLogsAutoRefresh();
                if (document.visibilityState && document.visibilityState !== 'visible') {
                    autoRefreshState.timerId = setTimeout(step, autoRefreshState.intervalMs);
                    return;
                }
                Promise.resolve().then(function () { return refreshLogs(); }).finally(function () {
                    autoRefreshState.timerId = setTimeout(step, autoRefreshState.intervalMs);
                });
            }
            autoRefreshState.timerId = setTimeout(step, 0);
        }

        // When attendance is deleted/edited from attendance.php (or another tab),
        // immediately refresh the home page logs instead of waiting for the next
        // auto-refresh cycle.
        window.addEventListener('storage', function (e) {
            if (!e || !e.key) return;
            if (e.key === 'ctr_attendance_changed') {
                refreshLogs();
            }
        });

        var recentRenderState = {
            lastKey: '',
            didInitialScroll: false
        };

        function parseMySqlDateTime(value) {
            var s = String(value == null ? '' : value).trim();
            if (!s) return 0;
            var m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
            if (m) {
                var y = Number(m[1] || 0);
                var mo = Number(m[2] || 1) - 1;
                var d = Number(m[3] || 1);
                var hh = Number(m[4] || 0);
                var mm = Number(m[5] || 0);
                var ss = Number(m[6] || 0);
                var dt = new Date(y, mo, d, hh, mm, ss);
                var t = dt.getTime();
                return isFinite(t) ? t : 0;
            }
            var p = Date.parse(s);
            return isFinite(p) ? p : 0;
        }

        function logKey(r) {
            var a = r && (r.id != null ? r.id : '');
            var b = r && (r.created_at != null ? r.created_at : '');
            var c = r && (r.image_path != null ? r.image_path : '');
            var d = r && (r.am_in != null ? r.am_in : '');
            var e = r && (r.pm_in != null ? r.pm_in : '');
            return String(a) + '|' + String(b) + '|' + String(c) + '|' + String(d) + '|' + String(e);
        }

        function sortLogsOldToNew(logs) {
            var arr = Array.isArray(logs) ? logs.slice() : [];
            arr.sort(function (a, b) {
                var ta = parseMySqlDateTime(a && a.created_at ? a.created_at : '');
                var tb = parseMySqlDateTime(b && b.created_at ? b.created_at : '');
                if (ta !== tb) return ta - tb;
                var ka = logKey(a);
                var kb = logKey(b);
                if (ka === kb) return 0;
                return ka < kb ? -1 : 1;
            });
            return arr;
        }

        function setLiveCount(n) {
            var elN = el('homeLiveCountNum');
            if (!elN) return;
            var val = Number(n) || 0;
            if (elN.textContent === String(val)) return;
            elN.textContent = String(val);
            var pill = el('homeLiveCount');
            if (pill) {
                pill.classList.remove('is-pop');
                void pill.offsetWidth;
                pill.classList.add('is-pop');
            }
        }

        function renderRecent(logs) {
            var box = el('homeRecentList');
            if (!box) return;
            setLiveCount(logs ? logs.length : 0);
            if (!logs || !logs.length) {
                box.innerHTML = ''
                    + '<div class="home-empty">'
                    +   '<div class="home-empty-card">'
                    +     '<div class="home-empty-illu" aria-hidden="true">'
                    +       '<svg width="160" height="110" viewBox="0 0 160 110" fill="none" xmlns="http://www.w3.org/2000/svg">'
                    +         '<path d="M20 84c0-22 18-40 40-40h64c9.94 0 18 8.06 18 18v22c0 9.94-8.06 18-18 18H44c-13.26 0-24-10.74-24-24z" fill="rgba(34,197,94,0.14)"/>'
                    +         '<path d="M46 28h70c6.63 0 12 5.37 12 12v44c0 6.63-5.37 12-12 12H46c-6.63 0-12-5.37-12-12V40c0-6.63 5.37-12 12-12z" stroke="rgba(148,163,184,0.55)" stroke-width="2"/>'
                    +         '<path d="M52 42h56M52 54h44M52 66h28" stroke="rgba(148,163,184,0.55)" stroke-width="2" stroke-linecap="round"/>'
                    +         '<circle cx="118" cy="66" r="10" stroke="rgba(34,197,94,0.78)" stroke-width="2"/>'
                    +         '<path d="M114 66l3 3 6-7" stroke="rgba(34,197,94,0.86)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
                    +       '</svg>'
                    +     '</div>'
                    +     '<div class="home-empty-title">No attendance logs yet.</div>'
                    +     '<div class="home-empty-sub">Scanned QR records will appear here as they come in.</div>'
                    +   '</div>'
                    + '</div>';
                return;
            }
            var view = logs.slice(-30).reverse();
            var last = view.length ? logs[logs.length - 1] : null;
            var nextKey = last ? logKey(last) : '';
            var shouldSmoothScroll = recentRenderState.lastKey && nextKey && recentRenderState.lastKey !== nextKey;
            recentRenderState.lastKey = nextKey;
            var html = view.map(function (r, idx) {
                // Fall back to the app logo (logo.png doesn't exist) so scans
                // recorded without a photo don't show a broken image.
                var photo = r.image_path ? String(r.image_path) : 'assets/images/contracs.png';
                if (r.created_at && r.image_path) photo += '?v=' + encodeURIComponent(String(r.created_at));
                var name = r.full_name ? String(r.full_name) : '-';
                var dept = r.position ? String(r.position) : '-';
                var amIn = formatTimeHM(r.am_in || '');
                var amOut = formatTimeHM(r.am_out || '');
                var pmIn = formatTimeHM(r.pm_in || '');
                var pmOut = formatTimeHM(r.pm_out || '');
                var hasIn = amIn !== '—';
                var hasOut = amOut !== '—';
                var hasPmIn = pmIn !== '—';
                var hasPmOut = pmOut !== '—';
                return ''
                    + '<div class="home-recent-item' + (idx === 0 && shouldSmoothScroll ? ' is-new' : '') + '">'
                    +   '<img class="home-recent-thumb" src="' + escapeHtml(photo) + '" alt="' + escapeHtml('Photo: ' + name) + '" loading="lazy" decoding="async" tabindex="0" role="button" aria-label="' + escapeHtml('Open photo for ' + name) + '" data-full="' + escapeHtml(photo) + '">'
                    +   '<div class="home-recent-main">'
                    +     '<div class="home-recent-name">' + escapeHtml(name) + '</div>'
                    +     '<div class="home-recent-sub">' + escapeHtml(dept) + '</div>'
                    +     '<div class="home-times">'
                    +       '<div class="home-time-cell"><div class="home-time-label">AM In</div><div class="home-time-value' + (hasIn ? ' has-value' : '') + '">' + escapeHtml(amIn) + '</div></div>'
                    +       '<div class="home-time-cell"><div class="home-time-label">AM Out</div><div class="home-time-value' + (hasOut ? ' has-value' : '') + '">' + escapeHtml(amOut) + '</div></div>'
                    +       '<div class="home-time-cell"><div class="home-time-label">PM In</div><div class="home-time-value' + (hasPmIn ? ' has-value' : '') + '">' + escapeHtml(pmIn) + '</div></div>'
                    +       '<div class="home-time-cell"><div class="home-time-label">PM Out</div><div class="home-time-value' + (hasPmOut ? ' has-value' : '') + '">' + escapeHtml(pmOut) + '</div></div>'
                    +     '</div>'
                    +   '</div>'
+ (idx === 0 ? '<div class="home-recent-ribbon" aria-label="Live">LIVE</div>' : '')
                    + '</div>';
            }).join('');
            box.innerHTML = html;
            if (!recentRenderState.didInitialScroll) {
                recentRenderState.didInitialScroll = true;
                box.scrollTop = 0;
                return;
            }
            if (shouldSmoothScroll) {
                requestAnimationFrame(function () {
                    try {
                        if (box.scrollTo) box.scrollTo({ top: 0, behavior: 'smooth' });
                        else box.scrollTop = 0;
                    } catch (e) {
                        box.scrollTop = 0;
                    }
                });
            }
        }

        function renderLast(r) {
            if (el('homeLastName')) el('homeLastName').textContent = r.full_name ? String(r.full_name) : '-';
            if (el('homeLastDept')) el('homeLastDept').textContent = r.position ? String(r.position) : '-';
            if (el('homeLastAmIn')) el('homeLastAmIn').textContent = r.am_in ? formatTime12(r.am_in) : '-';
            if (el('homeLastAmOut')) el('homeLastAmOut').textContent = r.am_out ? formatTime12(r.am_out) : '-';
            if (el('homeLastPmIn')) el('homeLastPmIn').textContent = r.pm_in ? formatTime12(r.pm_in) : '-';
            if (el('homeLastPmOut')) el('homeLastPmOut').textContent = r.pm_out ? formatTime12(r.pm_out) : '-';

            if (el('homeModalLastName')) el('homeModalLastName').textContent = r.full_name ? String(r.full_name) : '-';
            if (el('homeModalLastDept')) el('homeModalLastDept').textContent = r.position ? String(r.position) : '-';
            if (el('homeModalLastAmIn')) el('homeModalLastAmIn').textContent = r.am_in ? formatTime12(r.am_in) : '-';
            if (el('homeModalLastAmOut')) el('homeModalLastAmOut').textContent = r.am_out ? formatTime12(r.am_out) : '-';
            if (el('homeModalLastPmIn')) el('homeModalLastPmIn').textContent = r.pm_in ? formatTime12(r.pm_in) : '-';
            if (el('homeModalLastPmOut')) el('homeModalLastPmOut').textContent = r.pm_out ? formatTime12(r.pm_out) : '-';
        }

        function renderOther(logs) {
            var box = el('homeOtherLogs');
            if (!box) return;
            var arr = Array.isArray(logs) ? logs.slice(0) : [];
            var ownerId = Number(homeOwnerAdminId || 0);
            if (ownerId <= 0 && arr.length > 0) arr.pop();
            if (!arr.length) {
                box.innerHTML = '<div class="home-muted">No other logs yet.</div>';
                return;
            }
            var view = arr.slice(-6).reverse();
            box.innerHTML = view.map(function (r) {
                var name = r.full_name ? String(r.full_name) : '-';
                var dept = r.position ? String(r.position) : '-';
                var amIn = r.am_in ? formatTime12(r.am_in) : '-';
                var amOut = r.am_out ? formatTime12(r.am_out) : '-';
                var pmIn = r.pm_in ? formatTime12(r.pm_in) : '-';
                var pmOut = r.pm_out ? formatTime12(r.pm_out) : '-';
                return ''
                    + '<div class="home-other-item">'
                    +   '<div class="n">' + escapeHtml(name) + '</div>'
                    +   '<div class="s">'
                    +     '<span>' + escapeHtml(dept) + '</span>'
                    +     '<span>AM In: ' + escapeHtml(amIn) + '</span>'
                    +     '<span>AM Out: ' + escapeHtml(amOut) + '</span>'
                    +     '<span>PM In: ' + escapeHtml(pmIn) + '</span>'
                    +     '<span>PM Out: ' + escapeHtml(pmOut) + '</span>'
                    +   '</div>'
                    + '</div>';
            }).join('');
        }

        function homeScanSuccessFx() {
            var modal = el('homeScanModal');
            var modalOpen = !!(modal && modal.classList && modal.classList.contains('is-open'));
            var vbox = null;
            try {
                if (modalOpen) {
                    var v = el('homeModalVideo');
                    vbox = v && v.closest ? v.closest('.home-video') : null;
                } else {
                    var sec = el('homeScannerSection');
                    vbox = sec ? sec.querySelector('.home-video') : null;
                }
            } catch (e) {
                vbox = null;
            }
            if (!vbox) return;
            vbox.classList.remove('is-success-fx');
            void vbox.offsetWidth;
            vbox.classList.add('is-success-fx');
            setTimeout(function () { vbox.classList.remove('is-success-fx'); }, 1300);
        }

        function processScan(rawValue) {
            var raw = String(rawValue == null ? '' : rawValue).trim();
            if (!raw) {
                setHint('Unreadable QR code');
                setResult('Unreadable');
                showNotify('warning', 'Unreadable QR code', { target: 'modal', duration: 1400 });
                return Promise.resolve(false);
            }
            setStatus('Processing');
            setHint('Capturing...');
            setResult('Processing…');
            var scanAtMs = nowMs();

            return captureSnapshotAny().then(function (snap) {
                setHint('Processing QR...');

                function queueOffline() {
                    if (!canOperateOffline()) {
                        setStatus('Offline');
                        var msg = isPlanExpiredClient() ? 'Plan expired. Connect to the internet.' : 'Offline access expired. Connect to the internet to refresh.';
                        setHint(msg);
                        setResult(msg);
                        showNotify('error', msg, { target: 'modal', duration: 2200 });
                        return Promise.resolve(false);
                    }
                    return offlineAddScan({ qr_data: raw, created_at_ms: scanAtMs, client_ts: scanAtMs, snapshot: snap }).then(function () {
                        setStatus('Offline');
                        setHint('Queued offline');
                        setResult('Queued');
                        return offlineCount().then(function (n) {
                            showNotify('success', 'Queued offline (' + n + ' pending). Will sync when online.', { target: 'modal', duration: 1600 });
                            homeScanSuccessFx();
                            return true;
                        });
                    });
                }

                if (!navigator.onLine) {
                    return queueOffline();
                }

                var fd = new FormData();
                fd.append('qr_data', raw);
                if (snap) fd.append('snapshot', snap);
                var ownAdminId = Number(homeOwnerAdminId || 0);
                if (ownAdminId > 0) fd.append('scanned_via', String(ownAdminId));
                var t = String(kioskToken || '').trim();
                if (t) fd.append('kiosk_token', t);
                return fetch(withKiosk('attendance.php?ajax=1&action=scan'), { method: 'POST', body: fd })
                    .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, data: d }; }); })
                    .then(function (res) {
                        var data = res.data || {};
                        if (!res.ok || !data || data.ok !== true) {
                            var msg = data && data.message ? String(data.message) : 'Scan failed';
                            setStatus('Error');
                            setHint(msg);
                            setResult(msg);
                            showNotify('error', msg, { target: 'modal', duration: 1800 });
                            return false;
                        }
                        setLastOnline(nowMs());
                        setStatus('Recorded');
                        setHint(data.message ? String(data.message) : 'Recorded');
                        setResult(data.message ? String(data.message) : 'Recorded');
                        showNotify('success', data.message ? String(data.message) : 'Recorded', { target: 'modal', duration: 1400 });
                        homeScanSuccessFx();
                        setTimeout(function () {
                            if (state.scanning) setStatus('Ready');
                        }, 1200);
                        // Directly update last-record panel from scan result for instant feedback
                        var scanLog = data && data.log ? data.log : null;
                        if (scanLog) {
                            var scanTime = scanLog.time_in || scanLog.time_out || '';
                            var scanLabel = scanLog.time_in ? 'Time In' : 'Time Out';
                            if (el('homeLastName')) el('homeLastName').textContent = scanLog.full_name || '--';
                            if (el('homeLastDept')) el('homeLastDept').textContent = scanLog.position || scanLog.department || '--';
                            if (scanLog.time_in) {
                                if (el('homeLastAmIn') && scanLog.session === 'morning') el('homeLastAmIn').textContent = formatTime12(scanLog.time_in);
                                if (el('homeLastPmIn') && scanLog.session === 'afternoon') el('homeLastPmIn').textContent = formatTime12(scanLog.time_in);
                            }
                            if (scanLog.time_out) {
                                if (el('homeLastAmOut') && scanLog.session === 'morning') el('homeLastAmOut').textContent = formatTime12(scanLog.time_out);
                                if (el('homeLastPmOut') && scanLog.session === 'afternoon') el('homeLastPmOut').textContent = formatTime12(scanLog.time_out);
                            }
                            if (el('homeModalLastAmIn') && scanLog.session === 'morning' && scanLog.time_in) el('homeModalLastAmIn').textContent = formatTime12(scanLog.time_in);
                            if (el('homeModalLastAmOut') && scanLog.session === 'morning' && scanLog.time_out) el('homeModalLastAmOut').textContent = formatTime12(scanLog.time_out);
                            if (el('homeModalLastPmIn') && scanLog.session === 'afternoon' && scanLog.time_in) el('homeModalLastPmIn').textContent = formatTime12(scanLog.time_in);
                            if (el('homeModalLastPmOut') && scanLog.session === 'afternoon' && scanLog.time_out) el('homeModalLastPmOut').textContent = formatTime12(scanLog.time_out);
                        }
                        // Refresh full logs with a small delay to ensure DB consistency
                        setTimeout(function() { refreshLogs(); }, 400);
                        try { syncOfflineScans(); } catch (e2) {}
                        // Check if face enrollment is needed (only when face mode is active)
                        var hasFace = Number(data.has_face_data || 0);
                        var scannedUserId = Number(data.user_id || 0);
                        var faceModeActive = (faceState.mode === 'both' || faceState.mode === 'face');
                        if (!hasFace && scannedUserId > 0 && faceModeActive) {
                            setTimeout(function () { showFaceEnrollModal(scannedUserId, scanLog ? scanLog.full_name : ''); }, 1500);
                        }
                        return true;
                    })
                    .catch(function () {
                        return queueOffline();
                    });
            }).catch(function () {
                if (!navigator.onLine) {
                    if (!canOperateOffline()) {
                        setStatus('Offline');
                        var msg = isPlanExpiredClient() ? 'Plan expired. Connect to the internet.' : 'Offline access expired. Connect to the internet to refresh.';
                        setHint(msg);
                        setResult(msg);
                        showNotify('error', msg, { target: 'modal', duration: 2200 });
                        return false;
                    }
                    return offlineAddScan({ qr_data: raw, created_at_ms: scanAtMs, client_ts: scanAtMs, snapshot: '' }).then(function () {
                        setStatus('Offline');
                        setHint('Queued offline');
                        setResult('Queued');
                        return offlineCount().then(function (n) {
                            showNotify('success', 'Queued offline (' + n + ' pending). Will sync when online.', { target: 'modal', duration: 1600 });
                            homeScanSuccessFx();
                            return true;
                        });
                    });
                }
                setStatus('Error');
                setHint('Network error');
                setResult('Network error');
                showNotify('error', 'Network error', { target: 'modal', duration: 1800 });
                return false;
            });
        }

        // Face enrollment modal after scan
        var homeFaceEnroll = { stream: null, userId: 0, landmarker: null, modelsLoaded: false, captured: false, descriptor: null, embedding: null, image: null, skipUsers: {}, samples: [], sampleTarget: 5 };
        var MP_LEFT_EYE = [33, 160, 158, 133, 153, 144];
        var MP_RIGHT_EYE = [263, 387, 385, 362, 373, 380];
        var MP_FACEPRINT_IDX = [10, 152, 1, 234, 454, 61, 291, 33, 133, 362, 263, 70, 107, 336, 276, 468, 473, 168, 6, 351, 152, 127, 356, 130, 359, 162, 389, 21, 251, 284, 372, 0, 17, 54, 287, 37, 267, 82, 312, 199, 419, 132, 361];

        function homeFaceDist(a, b) { return Math.sqrt(Math.pow(a.x - b.x, 2) + Math.pow(a.y - b.y, 2)); }

        function homeEyeAspectRatioMP(landmarks, eyeIndices) {
            var pts = eyeIndices.map(function (i) { return landmarks[i]; });
            var v1 = homeFaceDist(pts[1], pts[5]);
            var v2 = homeFaceDist(pts[2], pts[4]);
            var h = homeFaceDist(pts[0], pts[3]);
            if (h <= 0.0001) return 0;
            return (v1 + v2) / (2 * h);
        }

        function homeBuildFaceprintMP(landmarks) {
            var nose = landmarks[1];
            var chin = landmarks[152];
            var faceH = homeFaceDist(nose, chin);
            if (faceH <= 0.0001) return null;
            var leftEyeOuter = landmarks[33];
            var rightEyeOuter = landmarks[263];
            var ox = (leftEyeOuter.x + rightEyeOuter.x) / 2;
            var oy = (leftEyeOuter.y + rightEyeOuter.y) / 2;
            var fp = [];
            for (var i = 0; i < MP_FACEPRINT_IDX.length; i++) {
                var p = landmarks[MP_FACEPRINT_IDX[i]];
                if (!p) continue;
                fp.push((p.x - ox) / faceH);
                fp.push((p.y - oy) / faceH);
            }
            // Angular features for better discrimination
            if (leftEyeOuter && nose && rightEyeOuter) {
                var a1 = Math.atan2(nose.y - leftEyeOuter.y, nose.x - leftEyeOuter.x);
                var a2 = Math.atan2(nose.y - rightEyeOuter.y, nose.x - rightEyeOuter.x);
                fp.push(a1 - a2);
            }
            var leftCheek = landmarks[234];
            var rightCheek = landmarks[454];
            if (leftCheek && nose && rightCheek) {
                var a3 = Math.atan2(nose.y - leftCheek.y, nose.x - leftCheek.x);
                var a4 = Math.atan2(nose.y - rightCheek.y, nose.x - rightCheek.x);
                fp.push(a3 - a4);
            }
            // Eye aspect ratio
            var leftEyeInner = landmarks[133];
            var rightEyeInner = landmarks[362];
            var el159 = landmarks[159], el145 = landmarks[145], er386 = landmarks[386], er374 = landmarks[374];
            if (leftEyeOuter && leftEyeInner && rightEyeOuter && rightEyeInner && el159 && el145 && er386 && er374) {
                var eyeWidth = homeFaceDist(leftEyeOuter, rightEyeOuter);
                var eyeHeight = (homeFaceDist(el159, el145) + homeFaceDist(er386, er374)) / 2;
                if (eyeWidth > 0.0001) fp.push(eyeHeight / eyeWidth);
            }
            // Mouth-to-face ratio
            var mouthLeft = landmarks[61];
            var mouthRight = landmarks[291];
            if (mouthLeft && mouthRight && leftCheek && rightCheek) {
                var mouthW = homeFaceDist(mouthLeft, mouthRight);
                var faceW = homeFaceDist(leftCheek, rightCheek);
                if (faceW > 0.0001) fp.push(mouthW / faceW);
            }
            return fp;
        }

        function homeLoadMediaPipeFaceLandmarker() {
            if (homeFaceEnroll.modelsLoaded && homeFaceEnroll.landmarker) return Promise.resolve(homeFaceEnroll.landmarker);
            return homeLoadLandmarkerWithFallback(homeFaceEnroll);
        }

        function showFaceEnrollModal(userId, userName) {
            homeFaceEnroll.userId = userId;
            homeFaceEnroll.captured = false;
            homeFaceEnroll.descriptor = null;
            homeFaceEnroll.embedding = null;
            homeFaceEnroll.image = null;
            homeFaceEnroll.embedRetries = 0;
            // Close the QR/Face scanner modal first
            closeScanModal();
            if (el('homeFaceEnrollUserName')) el('homeFaceEnrollUserName').textContent = userName || '';
            if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Loading face detection...';
            if (el('homeFaceEnrollPreview')) el('homeFaceEnrollPreview').classList.add('d-none');
            if (el('homeFaceEnrollSaveBtn')) el('homeFaceEnrollSaveBtn').classList.add('d-none');
            var modal = el('homeFaceEnrollModal');
            if (modal) { modal.classList.add('is-open'); modal.setAttribute('aria-hidden', 'false'); }
            homeLoadMediaPipeFaceLandmarker().then(function () {
                if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Starting camera...';
                return homeStartFaceEnrollCamera();
            }).then(function () {
                homeStartBlinkCapture();
            }).catch(function (err) {
                console.error('Face enrollment init error:', err);
                var detail = (err && err.message) ? err.message : String(err || 'unknown');
                if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Failed to load face detection: ' + detail;
            });
        }

        function homeStartFaceEnrollCamera() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return Promise.reject(new Error('Camera not supported'));
            var video = el('homeFaceEnrollVideo');
            if (video) { video.srcObject = null; video.load(); }
            return navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' } }).then(function (stream) {
                homeFaceEnroll.stream = stream;
                var video = el('homeFaceEnrollVideo');
                if (!video) return;
                video.srcObject = stream;
                return new Promise(function (resolve) {
                    if (video.readyState >= 1) { video.play().then(resolve).catch(resolve); return; }
                    video.onloadedmetadata = function () { video.play().then(resolve).catch(resolve); };
                });
            });
        }

        function homeStopFaceEnrollCamera() {
            if (homeFaceEnroll.stream) { homeFaceEnroll.stream.getTracks().forEach(function (t) { t.stop(); }); homeFaceEnroll.stream = null; }
            var video = el('homeFaceEnrollVideo');
            if (video) video.srcObject = null;
        }

        function homeAverageDescriptors(samples) {
            if (!samples || samples.length === 0) return null;
            if (samples.length === 1) return samples[0];
            var len = samples[0].length;
            var result = [];
            for (var i = 0; i < len; i++) {
                var sum = 0;
                for (var j = 0; j < samples.length; j++) {
                    sum += samples[j][i] || 0;
                }
                result.push(sum / samples.length);
            }
            return result;
        }

        function homeCheckFaceQuality(landmarks) {
            var nose = landmarks[1];
            var chin = landmarks[152];
            var leftEar = landmarks[234];
            var rightEar = landmarks[454];
            if (!nose || !chin || !leftEar || !rightEar) return { ok: false, reason: 'Missing landmarks' };
            var faceH = Math.sqrt(Math.pow(nose.x - chin.x, 2) + Math.pow(nose.y - chin.y, 2));
            var faceW = Math.sqrt(Math.pow(leftEar.x - rightEar.x, 2) + Math.pow(leftEar.y - rightEar.y, 2));
            if (faceH < 0.08) return { ok: false, reason: 'Face too small. Move closer.' };
            if (faceW < 0.10) return { ok: false, reason: 'Face too small. Move closer.' };
            var centerX = (nose.x + chin.x) / 2;
            if (centerX < 0.2 || centerX > 0.8) return { ok: false, reason: 'Center your face.' };
            var centerY = (nose.y + chin.y) / 2;
            if (centerY < 0.15 || centerY > 0.85) return { ok: false, reason: 'Center your face vertically.' };
            return { ok: true };
        }

        function homeStartBlinkCapture() {
            var video = el('homeFaceEnrollVideo');
            var canvas = el('homeFaceEnrollCanvas');
            if (!video || !canvas) return;
            canvas.width = video.videoWidth || 640;
            canvas.height = video.videoHeight || 480;
            var ctx = canvas.getContext('2d');
            homeFaceEnroll.samples = [];
            homeFaceEnroll.sampleTarget = 5;
            if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Look at the camera and blink twice...';

            var blinkCount = 0;
            var blinkState = 'scanning';
            var closedSince = 0;
            var closedFrames = 0;
            var openFrames = 0;
            var lastDescriptor = null;
            var lastCanvas = null;
            var finished = false;
            var cooldown = false;
            var lastVideoTimeMs = -1;
            var recentEARs = [];
            var PEAK_WINDOW = 30;
            var CLOSE_RATIO = 0.55;
            var OPEN_RATIO = 0.78;
            var ABS_OPEN = 0.20;
            var ABS_CLOSE = 0.15;
            var CLOSED_FRAMES_NEEDED = 1;
            var OPEN_FRAMES_NEEDED = 2;
            var CLOSED_MIN_MS = 35;
            var CLOSED_MAX_MS = 1500;
            var collectingAfterBlink = false;
            var collectTimer = null;

            function tick() {
                if (finished || !video.srcObject) return;
                if (!homeFaceEnroll.landmarker) { setTimeout(tick, 50); return; }
                ctx.drawImage(video, 0, 0);
                lastCanvas = canvas;
                var nowMs = performance.now();
                if (nowMs === lastVideoTimeMs) nowMs += 1;
                lastVideoTimeMs = nowMs;
                var mpResult;
                try { mpResult = homeFaceEnroll.landmarker.detectForVideo(video, nowMs); } catch (e) { setTimeout(tick, 50); return; }
                if (!mpResult || !mpResult.faceLandmarks || mpResult.faceLandmarks.length === 0) {
                    if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Looking for face... Please face the camera.';
                    setTimeout(tick, 20);
                    return;
                }
                var landmarks = mpResult.faceLandmarks[0];
                var quality = homeCheckFaceQuality(landmarks);
                if (!quality.ok) {
                    if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = quality.reason;
                    setTimeout(tick, 20);
                    return;
                }
                var leftEAR = homeEyeAspectRatioMP(landmarks, MP_LEFT_EYE);
                var rightEAR = homeEyeAspectRatioMP(landmarks, MP_RIGHT_EYE);
                var ear = (leftEAR + rightEAR) / 2;
                var fp = homeBuildFaceprintMP(landmarks);
                if (fp) lastDescriptor = fp;
                // Collect face samples during and after blink detection
                if (fp && !collectingAfterBlink && blinkCount < 2) {
                    // Collect 2 samples before blinks for variety
                    if (homeFaceEnroll.samples.length < 2 && !cooldown) {
                        homeFaceEnroll.samples.push(fp.slice());
                    }
                }
                if (recentEARs.length >= PEAK_WINDOW) recentEARs.shift();
                recentEARs.push(ear);
                var peakEAR = 0;
                for (var pi = 0; pi < recentEARs.length; pi++) { if (recentEARs[pi] > peakEAR) peakEAR = recentEARs[pi]; }
                var useRatio = recentEARs.length >= 8;
                var closeT = useRatio ? (peakEAR * CLOSE_RATIO) : ABS_CLOSE;
                var openT = useRatio ? (peakEAR * OPEN_RATIO) : ABS_OPEN;
                var now = Date.now();
                var earPct = Math.round(ear * 100);
                var stateLabel = blinkState === 'eyes_closed' ? 'Eyes closed' : 'Eyes open';
                if (el('homeFaceEnrollMsg')) {
                    el('homeFaceEnrollMsg').innerHTML = 'EAR: <strong>' + ear.toFixed(3) + '</strong> (' + earPct + '%) &middot; ' + stateLabel + ' &middot; Blinks: <strong>' + blinkCount + '/2</strong>';
                }
                if (ear <= closeT) {
                    closedFrames++;
                    if (blinkState === 'scanning' && closedFrames >= CLOSED_FRAMES_NEEDED) {
                        blinkState = 'eyes_closed';
                        closedSince = now;
                        openFrames = 0;
                    }
                } else if (ear >= openT) {
                    if (blinkState === 'eyes_closed') {
                        openFrames++;
                        if (openFrames >= OPEN_FRAMES_NEEDED) {
                            var closedFor = now - closedSince;
                            if (closedFor >= CLOSED_MIN_MS && closedFor <= CLOSED_MAX_MS && !cooldown) {
                                blinkCount++;
                                cooldown = true;
                                if (el('homeFaceEnrollMsg')) {
                                    el('homeFaceEnrollMsg').innerHTML = '<span class="text-success fw-semibold">Blink ' + blinkCount + ' detected!</span> ' + (blinkCount < 2 ? 'Blink once more...' : '');
                                }
                                if (blinkCount >= 2) {
                                // Add current descriptor as a post-blink sample
                                if (fp) homeFaceEnroll.samples.push(fp.slice());
                                collectingAfterBlink = true;
                                // Collect more samples over 2 seconds after 2nd blink
                                var localInterval = null;
                                var localTimer = null;
                                var localFinished = false;
                                localInterval = setInterval(function () {
                                    if (!video.srcObject || finished || localFinished) { clearInterval(localInterval); return; }
                                    if (fp && homeFaceEnroll.samples.length < homeFaceEnroll.sampleTarget) {
                                        // Only add if quality is good
                                        var last = homeFaceEnroll.samples[homeFaceEnroll.samples.length - 1];
                                        var dist = 0;
                                        if (last) {
                                            for (var k = 0; k < Math.min(fp.length, last.length); k++) {
                                                var d = fp[k] - last[k];
                                                dist += d * d;
                                            }
                                            dist = Math.sqrt(dist);
                                        }
                                        // Only add if distance from last sample is reasonable (not a jitter spike)
                                        if (dist < 0.15) {
                                            homeFaceEnroll.samples.push(fp.slice());
                                        }
                                    }
                                    if (el('homeFaceEnrollMsg')) {
                                        el('homeFaceEnrollMsg').textContent = 'Collecting samples... (' + homeFaceEnroll.samples.length + '/' + homeFaceEnroll.sampleTarget + ')';
                                    }                                        if (homeFaceEnroll.samples.length >= homeFaceEnroll.sampleTarget) {
                                            localFinished = true;
                                            clearInterval(localInterval);
                                            clearTimeout(localTimer);
                                            var avgDescriptor = homeAverageDescriptors(homeFaceEnroll.samples);
                                            homeCaptureAndShowSave(lastCanvas, avgDescriptor);
                                        }
                                }, 350);
                                // Hard timeout: use whatever samples we have
                                homeFaceEnroll._collectTimer = setTimeout(function () {
                                    if (localFinished) return;
                                    localFinished = true;
                                    clearInterval(localInterval);
                                    if (!finished) {
                                        var avgDescriptor = homeAverageDescriptors(homeFaceEnroll.samples);
                                        homeCaptureAndShowSave(lastCanvas, avgDescriptor);
                                    }
                                }, 3000);
                                return;
                            }
                                setTimeout(function () { cooldown = false; }, 450);
                            }
                            blinkState = 'scanning';
                            closedFrames = 0;
                            openFrames = 0;
                        }
                    } else {
                        closedFrames = 0;
                        openFrames = 0;
                    }
                } else {
                    if (blinkState === 'eyes_closed' && (now - closedSince) > CLOSED_MAX_MS) {
                        blinkState = 'scanning';
                        closedFrames = 0;
                        openFrames = 0;
                    }
                }
                setTimeout(tick, 20);
            }
            tick();
        }

        function homeCaptureAndShowSave(sourceCanvas, descriptor) {
            homeFaceEnroll.captured = true;
            homeFaceEnroll.descriptor = descriptor ? descriptor.slice() : null;
            homeFaceEnroll.embedding = null;
            var previewCanvas = document.createElement('canvas');
            previewCanvas.width = 240;
            previewCanvas.height = 180;
            var pCtx = previewCanvas.getContext('2d');
            // Mirror the canvas horizontally to match the mirrored video
            pCtx.translate(240, 0);
            pCtx.scale(-1, 1);
            pCtx.drawImage(sourceCanvas, 0, 0, sourceCanvas.width, sourceCanvas.height, 0, 0, 240, 180);
            homeFaceEnroll.image = previewCanvas.toDataURL('image/jpeg', 0.85);
            if (el('homeFaceEnrollPreviewImg')) el('homeFaceEnrollPreviewImg').src = homeFaceEnroll.image;
            if (el('homeFaceEnrollPreview')) el('homeFaceEnrollPreview').classList.remove('d-none');
            if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Building face embedding...';
            function finishEnrollCapture() {
                if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Face captured! Enrolling...';
                if (el('homeFaceEnrollPreviewLabel')) {
                    var sampleCount = homeFaceEnroll.samples ? homeFaceEnroll.samples.length : 1;
                    el('homeFaceEnrollPreviewLabel').textContent = sampleCount + ' face samples averaged for accuracy.';
                    el('homeFaceEnrollPreviewLabel').className = 'mt-2 text-success small fw-semibold';
                }
                homeStopFaceEnrollCamera();
                // Auto-save after capture
                homeAutoSaveFaceEnroll();
            }
            // Run face-api on the full-res frame so the saved payload carries
            // a v2 128-dim descriptor when the model is available.
            homeEmbedCanvas(sourceCanvas).then(function (emb) {
                homeFaceEnroll.embedding = emb;
                finishEnrollCapture();
            }).catch(function () {
                homeFaceEnroll.embedding = null;
                finishEnrollCapture();
            });
        }

        function homeAutoSaveFaceEnroll() {
            if (!homeFaceEnroll.captured || !homeFaceEnroll.image || !homeFaceEnroll.userId) return;
            // Never silently save a legacy-only print: without the 128-dim
            // embedding the "Re-enroll Needed" badge stays stuck even though
            // the save reports success. Reset and let the user retry instead.
            if (!homeFaceEnroll.embedding || homeFaceEnroll.embedding.length < 128) {
                homeFaceEnroll.captured = false;
                homeFaceEnroll.descriptor = null;
                homeFaceEnroll.image = null;
                if (el('homeFaceEnrollPreview')) el('homeFaceEnrollPreview').classList.add('d-none');
                homeFaceEnroll.embedRetries = (homeFaceEnroll.embedRetries || 0) + 1;
                if (homeFaceEnroll.embedRetries >= 3) {
                    if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').innerHTML = '<span style="color:var(--ctr-warning);">Could not build the 128-dim face embedding (recognition model unavailable). Please check your internet connection, then close and reopen this window to try again.</span>';
                    return;
                }
                if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').innerHTML = '<span style="color:var(--ctr-warning);">Face captured, but the recognition model could not build the 128-dim embedding. Retrying (' + (homeFaceEnroll.embedRetries || 1) + '/3)...</span>';
                setTimeout(function () {
                    // Only retry if the enrollment modal is still open
                    if (!homeFaceEnroll.userId) return;
                    var em = el('homeFaceEnrollModal');
                    if (!em || !em.classList || !em.classList.contains('is-open')) return;
                    if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Retrying capture...';
                    homeStartFaceEnrollCamera().then(function () {
                        homeStartBlinkCapture();
                    }).catch(function () {
                        if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Failed to restart camera.';
                    });
                }, 2500);
                return;
            }
            if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Saving face data...';
            var fd = new FormData();
            fd.append('id', String(homeFaceEnroll.userId));
            fd.append('face_data', JSON.stringify(homeBuildFacePayload(homeFaceEnroll.descriptor || null, homeFaceEnroll.embedding)));
            fd.append('face_image', homeFaceEnroll.image);
            fetch(withKiosk('users.php?ajax=1&action=face_enroll'), { method: 'POST', body: fd })
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, data: d }; }); })
                .then(function (res) {
                    var data = res.data || {};
                    if (res.ok && data && data.ok) {
                        if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').innerHTML = '<span style="color:var(--ctr-success);">Face enrolled successfully!</span>';
                        setTimeout(function () { closeFaceEnrollModal(true); }, 1500);
                    } else if (res.status === 409) {
                        // Duplicate face detected - show message and restart camera for retry
                        var dupName = data.duplicate_user_name || 'another user';
                        var dupUser = data.duplicate_username || '';
                        var dupMsg = 'Duplicate! Already enrolled for ' + escapeHtml(dupName);
                        if (dupUser) dupMsg += ' (' + escapeHtml(dupUser) + ')';
                        if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').innerHTML = '<span style="color:var(--ctr-warning);">' + dupMsg + '</span>';
                        // Reset capture state and restart camera after delay
                        homeFaceEnroll.captured = false;
                        homeFaceEnroll.descriptor = null;
                        homeFaceEnroll.image = null;
                        if (el('homeFaceEnrollPreview')) el('homeFaceEnrollPreview').classList.add('d-none');
                        setTimeout(function () {
                            if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Restarting camera...';
                            homeStartFaceEnrollCamera().then(function () {
                                homeStartBlinkCapture();
                            }).catch(function () {
                                if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Failed to restart camera.';
                            });
                        }, 2000);
                    } else {
                        var msg = (data && data.message) ? data.message : 'Failed to save.';
                        if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = msg;
                    }
                })
                .catch(function () {
                    if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Network error.';
                });
        }

        function homeSaveFaceEnroll() {
            if (!homeFaceEnroll.captured || !homeFaceEnroll.image || !homeFaceEnroll.userId) {
                if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Please capture your face first.';
                return;
            }
            if (!homeFaceEnroll.embedding || homeFaceEnroll.embedding.length < 128) {
                if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').innerHTML = '<span style="color:var(--ctr-warning);">Could not build the 128-dim face embedding (recognition model unavailable). Please capture again.</span>';
                return;
            }
            if (el('homeFaceEnrollSaveBtn')) el('homeFaceEnrollSaveBtn').disabled = true;
            if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Saving face data...';
            var fd = new FormData();
            fd.append('id', String(homeFaceEnroll.userId));
            fd.append('face_data', JSON.stringify(homeBuildFacePayload(homeFaceEnroll.descriptor || null, homeFaceEnroll.embedding)));
            fd.append('face_image', homeFaceEnroll.image);
            fetch(withKiosk('users.php?ajax=1&action=face_enroll'), { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) {
                        if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').innerHTML = '<span style="color:var(--ctr-success);">Face enrolled successfully!</span>';
                        setTimeout(function () { closeFaceEnrollModal(); }, 1500);
                    } else {
                        var msg = (res && res.message) ? res.message : 'Failed to save.';
                        if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = msg;
                        if (el('homeFaceEnrollSaveBtn')) el('homeFaceEnrollSaveBtn').disabled = false;
                    }
                })
                .catch(function () {
                    if (el('homeFaceEnrollMsg')) el('homeFaceEnrollMsg').textContent = 'Network error.';
                    if (el('homeFaceEnrollSaveBtn')) el('homeFaceEnrollSaveBtn').disabled = false;
                });
        }

        function closeFaceEnrollModal(reopenScanner) {
            homeStopFaceEnrollCamera();
            homeFaceEnroll.samples = [];
            if (homeFaceEnroll._collectTimer) { clearTimeout(homeFaceEnroll._collectTimer); homeFaceEnroll._collectTimer = null; }
            homeFaceEnroll.captured = false;
            homeFaceEnroll.descriptor = null;
            homeFaceEnroll.embedding = null;
            homeFaceEnroll.image = null;
            var modal = el('homeFaceEnrollModal');
            if (modal) { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); }
            // Reopen the scanner after successful enrollment
            if (reopenScanner) {
                setTimeout(function () { openScanModal(); }, 500);
            }
        }

        function startScanningLoop() {
            state.scanning = true;
            state.cooldown = false;

            // Start face loop when mode includes face
            if (faceState.mode === 'face' || faceState.mode === 'both') {
                startFaceLoop();
            } else {
                stopFaceLoop();
            }
            // Skip QR loop when mode is face-only
            if (faceState.mode === 'face') {
                return;
            }

            var modal = el('homeScanModal');
            var modalOpen = !!(modal && modal.classList && modal.classList.contains('is-open'));
            var v = modalOpen ? el('homeModalVideo') : el('homeScannerVideo');
            var canvas = document.createElement('canvas');
            var ctx = canvas.getContext('2d', { willReadFrequently: true });

            function cooldownAfterScan() {
                setTimeout(function () { state.cooldown = false; }, 1100);
            }

            function stepBarcodeDetector() {
                if (!state.scanning) return;
                if (!v || v.readyState < 2) {
                    state.scanTimer = setTimeout(stepBarcodeDetector, 250);
                    return;
                }
                if (state.cooldown) {
                    state.scanTimer = setTimeout(stepBarcodeDetector, 180);
                    return;
                }
                Promise.resolve().then(function () { return state.detector.detect(v); })
                    .then(function (codes) {
                        if (!state.scanning) return;
                        if (codes && codes.length && codes[0] && codes[0].rawValue) {
                            state.cooldown = true;
                            return processScan(codes[0].rawValue).finally(cooldownAfterScan);
                        }
                    })
                    .catch(function () {})
                    .finally(function () {
                        state.scanTimer = setTimeout(stepBarcodeDetector, 150);
                    });
            }

            function stepJsQr() {
                if (!state.scanning) return;
                if (!v || v.readyState < 2) {
                    state.scanTimer = setTimeout(stepJsQr, 250);
                    return;
                }
                if (state.cooldown) {
                    state.scanTimer = setTimeout(stepJsQr, 180);
                    return;
                }
                var vw = v.videoWidth || 0;
                var vh = v.videoHeight || 0;
                if (!vw || !vh || !ctx) {
                    state.scanTimer = setTimeout(stepJsQr, 200);
                    return;
                }
                var targetW = 640;
                var scale = Math.min(1, targetW / vw);
                var w = Math.max(1, Math.floor(vw * scale));
                var h = Math.max(1, Math.floor(vh * scale));
                canvas.width = w;
                canvas.height = h;
                try {
                    ctx.drawImage(v, 0, 0, w, h);
                    var img = ctx.getImageData(0, 0, w, h);
                    var code = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' });
                    if (code && code.data) {
                        state.cooldown = true;
                        return processScan(code.data).finally(function () {
                            cooldownAfterScan();
                            state.scanTimer = setTimeout(stepJsQr, 180);
                        });
                    }
                } catch (e) {}
                state.scanTimer = setTimeout(stepJsQr, 180);
            }

            if ('BarcodeDetector' in window) {
                try {
                    state.detector = new window.BarcodeDetector({ formats: ['qr_code'] });
                    setHint('Point the camera at the QR code');
                    stepBarcodeDetector();
                    return;
                } catch (e) {}
            }

            setHint('Loading ' + homeScanModeLabel() + '...');
            loadJsQr().then(function (ok) {
                if (!state.scanning) return;
                if (!ok) {
                    setHint('QR scanning not supported on this device');
                    showNotify('warning', 'QR scanning not supported on this device', { duration: 2200 });
                    return;
                }
                setHint('Point the camera at the QR code');
                stepJsQr();
            });
        }

        function refreshCameras() {
            var sel = el('homeCameraSelect');
            var modalSel = el('homeModalCameraSelect');
            if (!(navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) || (!sel && !modalSel)) return Promise.resolve();
            return navigator.mediaDevices.enumerateDevices().then(function (devices) {
                var cams = devices.filter(function (d) { return d && d.kind === 'videoinput'; });
                if (!cams.length) return;
                var current = '';
                if (sel && sel.value) current = String(sel.value);
                else if (modalSel && modalSel.value) current = String(modalSel.value);
                // If no camera is currently selected, prefer the back/environment camera
                if (!current) {
                    for (var i = 0; i < cams.length; i++) {
                        var label = (cams[i].label || '').toLowerCase();
                        if (label.indexOf('back') !== -1 || label.indexOf('rear') !== -1 || label.indexOf('environment') !== -1) {
                            current = cams[i].deviceId;
                            break;
                        }
                    }
                    // If still no match, use the last camera (often the back camera on mobile)
                    if (!current && cams.length > 1) {
                        current = cams[cams.length - 1].deviceId;
                    }
                }
                var html = cams.map(function (d, idx) {
                    var label = d.label ? d.label : ('Camera ' + (idx + 1));
                    var selected = current && d.deviceId === current ? ' selected' : '';
                    return '<option value="' + escapeHtml(d.deviceId) + '"' + selected + '>' + escapeHtml(label) + '</option>';
                }).join('');
                if (sel) sel.innerHTML = html;
                if (modalSel) modalSel.innerHTML = html;
            }).catch(function () {});
        }

        function openScanModal() {
            if (!navigator.onLine && !canOperateOffline()) {
                var msg = isPlanExpiredClient() ? 'Plan expired. Connect to the internet.' : 'Offline access expired. Connect to the internet to refresh.';
                setStatus('Offline');
                setHint(msg);
                setResult(msg);
                showNotify('error', msg, { duration: 2200 });
                return;
            }
            // Stop face enrollment camera if it's running
            homeStopFaceEnrollCamera();
            // Stop any existing camera stream first
            stopAllCamera();
            openModal('homeScanModal');
            hideNotify('modal', true);
            setHint(' ');
            setResult('Ready');
            setLoading('homeModalLoading', true);
            // Wait for camera list to refresh, then start camera
            refreshCameras().then(function () {
                return ensureCameraStream();
            }).then(function (ok) {
                setLoading('homeModalLoading', false);
                if (!ok) return;
                if (!state.scanning) startScanningLoop();
            }).catch(function () {
                setLoading('homeModalLoading', false);
            });
        }

        function closeScanModal() {
            state.scanning = false;
            stopFaceLoop();
            stopAllCamera();
            hideNotify('modal', true);
            closeModal('homeScanModal');
            setHint('Camera stopped');
            setResult('Stopped');
            setStatus('Ready');
        }

        function openImageModal(src) {
            var img = el('homeImageModalImg');
            if (img) img.src = String(src || '');
            openModal('homeImageModal');
        }

        function closeImageModal() {
            closeModal('homeImageModal');
        }

        var autoOpenState = {
            opened: false
        };

        function autoOpenScanModal() {
            if (autoOpenState.opened) return;
            if (!getAutoScanPref()) return;
            autoOpenState.opened = true;
            try {
                bumpUsage('scan_open');
                openScanModal();
            } catch (e) {}
        }

        var reloadState = {
            timerId: null,
            intervalId: null,
            targetUtcMs: 0
        };

        function computeNextManilaThreeHourReloadUtc(nowUtcMs) {
            var offset = 8 * 60 * 60 * 1000;
            var manilaMs = nowUtcMs + offset;
            var d = new Date(manilaMs);
            var y = d.getUTCFullYear();
            var m = d.getUTCMonth();
            var day = d.getUTCDate();
            var hh = d.getUTCHours();
            var mm = d.getUTCMinutes();
            var ss = d.getUTCSeconds();
            var ms = d.getUTCMilliseconds();
            var mod = hh % 3;
            var add = (3 - mod) % 3;
            if (add === 0 && (mm !== 0 || ss !== 0 || ms !== 0)) add = 3;
            if (add === 0 && mm === 0 && ss === 0 && ms === 0) add = 3;
            var targetManila = Date.UTC(y, m, day, hh + add, 0, 0, 0);
            return targetManila - offset;
        }

        function stopAutoReload() {
            if (reloadState.timerId) {
                clearTimeout(reloadState.timerId);
                reloadState.timerId = null;
            }
            if (reloadState.intervalId) {
                clearInterval(reloadState.intervalId);
                reloadState.intervalId = null;
            }
            reloadState.targetUtcMs = 0;
        }

        function triggerReloadIfDue() {
            if (!reloadState.targetUtcMs) return;
            if (Date.now() + 800 >= reloadState.targetUtcMs) {
                try { location.reload(); } catch (e) {}
            }
        }

        function planAutoReload() {
            stopAutoReload();
            var now = Date.now();
            var target = computeNextManilaThreeHourReloadUtc(now);
            reloadState.targetUtcMs = target;
            var delay = Math.max(0, target - now);
            reloadState.timerId = setTimeout(triggerReloadIfDue, delay);
            reloadState.intervalId = setInterval(triggerReloadIfDue, 60000);
        }

        function startAutoReloadManila() {
            try {
                planAutoReload();
                document.addEventListener('visibilitychange', function () {
                    if (document.visibilityState === 'visible') triggerReloadIfDue();
                });
                window.addEventListener('focus', triggerReloadIfDue);
                window.addEventListener('pageshow', function () {
                    triggerReloadIfDue();
                    planAutoReload();
                });
            } catch (e) {}
        }

        function init() {
            if ('serviceWorker' in navigator) {
                try { navigator.serviceWorker.register('assets/sw.js'); } catch (e) {}
            }

            function updateOfflineUi() {
                if (navigator.onLine) {
                    if (!state.scanning) setStatus('Ready');
                    return;
                }
                if (canOperateOffline()) {
                    setStatus('Offline');
                    setHint('Offline • scans will queue and sync when online');
                    return;
                }
                var msg = isPlanExpiredClient() ? 'Plan expired. Connect to the internet.' : 'Offline access expired. Connect to the internet to refresh.';
                setStatus('Offline');
                setHint(msg);
            }

            window.addEventListener('online', function () {
                updateOfflineUi();
                try { syncOfflineScans(); } catch (e) {}
                refreshLogs();
            });
            window.addEventListener('offline', function () {
                updateOfflineUi();
            });

            tick();
            setInterval(tick, 1000);

            refreshCameras();
            if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
                navigator.mediaDevices.addEventListener('devicechange', refreshCameras);
            }

            var scanBtn = el('homeScanBtn');
            if (scanBtn) {
                scanBtn.addEventListener('click', function () {
                    bumpUsage('scan_open');
                    openScanModal();
                });
            }

            var refreshBtn = el('homeRefreshBtn');
            if (refreshBtn) refreshBtn.addEventListener('click', function () { bumpUsage('refresh_click'); refreshLogs(); });

            var profileIcon = el('homeProfileIcon');
            if (profileIcon) {
                profileIcon.addEventListener('click', function () {
                    var loggedIn = profileIcon.getAttribute('data-logged-in') === '1';
                    if (!loggedIn) {
                        try { location.href = 'login'; } catch (e) {}
                        return;
                    }
                    bumpUsage('profile_open');
                    var name = profileIcon.getAttribute('data-name') || '';
                    var role = profileIcon.getAttribute('data-role') || '';
                    var idn = profileIcon.getAttribute('data-id') || '';
                    var sub = String(role || '').trim() + (idn ? (' • ' + idn) : '');
                    if (el('homeProfileName')) el('homeProfileName').textContent = name || 'User';
                    if (el('homeProfileSub')) el('homeProfileSub').textContent = sub || 'Signed in';
                    announce('homeProfileAnnounce', 'Profile menu opened');
                    openModalWithFocus('homeProfileModal', 'homeProfileCloseBtn');
                });
            }

            var settingsIcon = el('homeSettingsIcon');
            if (settingsIcon) {
                settingsIcon.addEventListener('click', function () {
                    bumpUsage('settings_open');
                    var current = '';
                    try { current = normalizeHex(localStorage.getItem('ctr-theme-base') || ''); } catch (e) {}
                    if (!current) current = normalizeHex(getComputedStyle(document.documentElement).getPropertyValue('--ctr-theme-base'));
                    if (!current) current = '#22C55E';
                    var hex = el('homeThemeHex');
                    var picker = el('homeThemePicker');
                    if (hex) hex.value = current;
                    if (picker) picker.value = current;
                    var autoScanToggle = el('homeAutoScanToggle');
                    if (autoScanToggle) autoScanToggle.checked = !!getAutoScanPref();
                    updateUsageUi();
                    updatePerfUi();
                    announce('homeSettingsAnnounce', 'Settings opened');
                    openModalWithFocus('homeSettingsModal', 'homeThemeHex');
                    if (hex) {
                        setTimeout(function () {
                            try { hex.select(); } catch (e) {}
                        }, 0);
                    }
                });
            }

            var profileCloseBtn = el('homeProfileCloseBtn');
            if (profileCloseBtn) profileCloseBtn.addEventListener('click', function () { closeModalRestoreFocus('homeProfileModal'); });
            var profileLogoutBtn = el('homeProfileLogoutBtn');
            if (profileLogoutBtn) profileLogoutBtn.addEventListener('click', function () { closeModal('homeProfileModal'); openModalWithFocus('homeConfirmModal', 'homeConfirmCancelBtn'); });

            var settingsCloseBtn = el('homeSettingsCloseBtn');
            if (settingsCloseBtn) settingsCloseBtn.addEventListener('click', function () { closeModalRestoreFocus('homeSettingsModal'); });
            var autoScanToggle = el('homeAutoScanToggle');
            if (autoScanToggle) {
                autoScanToggle.checked = !!getAutoScanPref();
                autoScanToggle.addEventListener('change', function () {
                    setAutoScanPref(!!autoScanToggle.checked);
                    announce('homeSettingsAnnounce', autoScanToggle.checked ? 'Auto-open scanner enabled' : 'Auto-open scanner disabled');
                    showNotify('success', autoScanToggle.checked ? 'Auto-open enabled' : 'Auto-open disabled', { duration: 1100 });
                });
            }
            var themeHex = el('homeThemeHex');
            var themePicker = el('homeThemePicker');
            if (themeHex && themePicker) {
                themeHex.addEventListener('input', function () {
                    var c = normalizeHex(themeHex.value || '');
                    if (c) themePicker.value = c;
                });
                themePicker.addEventListener('input', function () {
                    themeHex.value = String(themePicker.value || '');
                });
            }
            var themeApplyBtn = el('homeThemeApplyBtn');
            if (themeApplyBtn) {
                themeApplyBtn.addEventListener('click', function () {
                    var c = normalizeHex((themeHex && themeHex.value) ? themeHex.value : (themePicker ? themePicker.value : ''));
                    if (!c) {
                        announce('homeSettingsAnnounce', 'Invalid theme color');
                        showNotify('error', 'Invalid theme color', { duration: 1600 });
                        return;
                    }
                    var applied = applyThemeColor(c);
                    if (!applied) {
                        announce('homeSettingsAnnounce', 'Theme not applied');
                        showNotify('error', 'Theme not applied', { duration: 1600 });
                        return;
                    }
                    if (themeHex) themeHex.value = applied;
                    if (themePicker) themePicker.value = applied;
                    announce('homeSettingsAnnounce', 'Theme updated');
                    showNotify('success', 'Theme updated', { duration: 1100 });
                });
            }
            var themeResetBtn = el('homeThemeResetBtn');
            if (themeResetBtn) {
                themeResetBtn.addEventListener('click', function () {
                    var applied = applyThemeColor('#22C55E');
                    if (themeHex) themeHex.value = applied || '#22C55E';
                    if (themePicker) themePicker.value = applied || '#22C55E';
                    announce('homeSettingsAnnounce', 'Theme reset');
                    showNotify('success', 'Theme reset', { duration: 1100 });
                });
            }
            document.body.addEventListener('click', function (e) {
                var t = e.target;
                if (!t || !t.closest) return;
                var swatch = t.closest('.home-swatch');
                if (swatch) {
                    var c = normalizeHex(swatch.getAttribute('data-color') || '');
                    if (!c) return;
                    if (themeHex) themeHex.value = c;
                    if (themePicker) themePicker.value = c;
                    var applied = applyThemeColor(c);
                    if (applied) {
                        announce('homeSettingsAnnounce', 'Theme updated');
                        showNotify('success', 'Theme updated', { duration: 1100 });
                    }
                }
            });

            window.addEventListener('load', function () {
                capturePerf();
                updatePerfUi();
            });

            var confirmCloseBtn = el('homeConfirmCloseBtn');
            if (confirmCloseBtn) confirmCloseBtn.addEventListener('click', function () { closeModalRestoreFocus('homeConfirmModal'); });
            var confirmCancelBtn = el('homeConfirmCancelBtn');
            if (confirmCancelBtn) confirmCancelBtn.addEventListener('click', function () { closeModalRestoreFocus('homeConfirmModal'); });

            function onCameraSelectChange() {
                setLoading('homePanelLoading', true);
                setLoading('homeModalLoading', true);
                ensureCameraStream().then(function (ok) {
                    setLoading('homePanelLoading', false);
                    setLoading('homeModalLoading', false);
                    if (!ok) return;
                    if (!state.scanning) return;
                    stopScanning();
                    startScanningLoop();
                });
            }

            var sel = el('homeCameraSelect');
            if (sel) sel.addEventListener('change', onCameraSelectChange);
            var modalSel = el('homeModalCameraSelect');
            if (modalSel) modalSel.addEventListener('change', onCameraSelectChange);

            var startBtn = el('homeStartBtn');
            if (startBtn) startBtn.addEventListener('click', function () {
                bumpUsage('scan_start');
                openScanModal();
            });
            var stopBtn = el('homeStopBtn');
            if (stopBtn) stopBtn.addEventListener('click', function () { bumpUsage('scan_stop'); closeScanModal(); setLoading('homePanelLoading', false); });
            var modalStartBtn = el('homeModalStartBtn');
            if (modalStartBtn) modalStartBtn.addEventListener('click', function () {
                bumpUsage('scan_start');
                setResult('Ready');
                setLoading('homeModalLoading', true);
                ensureCameraStream().then(function (ok) {
                    setLoading('homeModalLoading', false);
                    if (!ok) return;
                    if (!state.scanning) startScanningLoop();
                });
            });
            var modalStopBtn = el('homeModalStopBtn');
            if (modalStopBtn) modalStopBtn.addEventListener('click', function () { bumpUsage('scan_stop'); closeScanModal(); });
            var scanCloseBtn = el('homeScanCloseBtn');
            if (scanCloseBtn) scanCloseBtn.addEventListener('click', function () { closeScanModal(); });
            var imgCloseBtn = el('homeImageCloseBtn');
            if (imgCloseBtn) imgCloseBtn.addEventListener('click', function () { closeImageModal(); });
            var faceEnrollCloseBtn = el('homeFaceEnrollCloseBtn');
            if (faceEnrollCloseBtn) faceEnrollCloseBtn.addEventListener('click', function () { closeFaceEnrollModal(); });

            // Modal scan mode buttons
            var modalModeBothBtn = el('homeModalModeBothBtn');
            var modalModeQrBtn = el('homeModalModeQrBtn');
            var modalModeFaceBtn = el('homeModalModeFaceBtn');
            function restartScanForModeChange(mode) {
                setFaceMode(mode);
                if (state.scanning) {
                    stopScanning();
                    startScanningLoop();
                }
            }
            if (modalModeBothBtn) modalModeBothBtn.addEventListener('click', function () { restartScanForModeChange('both'); });
            if (modalModeQrBtn) modalModeQrBtn.addEventListener('click', function () { restartScanForModeChange('qr'); });
            if (modalModeFaceBtn) modalModeFaceBtn.addEventListener('click', function () { restartScanForModeChange('face'); });

            // Initialize both mode (QR + Face)
            setFaceMode('both');

            document.body.addEventListener('click', function (e) {
                var t = e.target;
                if (!t || !t.getAttribute) return;
                if (t.getAttribute('data-close') !== '1') return;
                var modal = t.closest ? t.closest('.home-modal') : null;
                if (!modal || !modal.id) return;
                if (modal.id === 'homeScanModal') closeScanModal();
                else if (modal.id === 'homeImageModal') closeImageModal();
                else if (modal.id === 'homeFaceEnrollModal') closeFaceEnrollModal();
                else closeModalRestoreFocus(modal.id);
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    closeScanModal();
                    closeImageModal();
                    closeFaceEnrollModal();
                    closeModal('homeProfileModal');
                    closeModal('homeSettingsModal');
                    closeModal('homeConfirmModal');
                }
            });

            var recent = el('homeRecentList');
            if (recent) {
                recent.addEventListener('click', function (e) {
                    var t = e.target;
                    if (t && t.tagName === 'IMG' && t.classList && t.classList.contains('home-recent-thumb')) {
                        var full = t.getAttribute('data-full') || t.src;
                        if (full) openImageModal(full);
                    }
                });
                recent.addEventListener('keydown', function (e) {
                    if (!e) return;
                    var k = e.key;
                    if (k !== 'Enter' && k !== ' ') return;
                    var t = e.target;
                    if (t && t.tagName === 'IMG' && t.classList && t.classList.contains('home-recent-thumb')) {
                        e.preventDefault();
                        var full = t.getAttribute('data-full') || t.src;
                        if (full) openImageModal(full);
                    }
                });
            }

            refreshLogs();
            updateOfflineUi();
            offlineCount().then(function (n) {
                if (n > 0) {
                    if (!navigator.onLine && canOperateOffline()) {
                        showNotify('success', 'Offline scans pending: ' + n + '.', { duration: 1600 });
                    }
                }
            }).catch(function () {});

            setLoading('homePanelLoading', false);

            startAutoReloadManila();
            setTimeout(function () { autoOpenScanModal(); }, 80);
        }

        window.addEventListener('pageshow', function (e) {
            if (e && e.persisted) autoOpenState.opened = false;
            setTimeout(function () { autoOpenScanModal(); }, 0);
        });

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    })();
</script>

<script>
(function() {
    function el(id) { return document.getElementById(id); }

    function fetchJson(url, opts) {
        return fetch(url, Object.assign({
            headers: Object.assign({ 'Accept': 'application/json' }, (opts && opts.headers) || {})
        }, opts || {})).then(function(r) {
            return r.json().then(function(data) { return { ok: r.ok, status: r.status, data: data }; });
        });
    }

    function setAlert(id, msg) {
        var a = el(id);
        if (!a) return;
        a.textContent = String(msg || '');
        a.classList.toggle('d-none', !msg);
    }

    function showStep1() {
        var s1 = el('apeStep1');
        var s2 = el('apeStep2');
        var backBtn = el('apeBackBtn');
        var submitBtn = el('apeSubmitBtn');
        var title = el('apeModalTitle');
        if (s1) s1.style.display = '';
        if (s2) s2.style.display = 'none';
        if (backBtn) backBtn.classList.add('d-none');
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Send Request'; }
        if (title) title.textContent = 'Request Plan Extension';
        setAlert('apeAlert', '');
        setAlert('apeAlert2', '');
    }

    function showStep2(amount) {
        var s1 = el('apeStep1');
        var s2 = el('apeStep2');
        var backBtn = el('apeBackBtn');
        var submitBtn = el('apeSubmitBtn');
        var title = el('apeModalTitle');
        var step2Amount = el('apeStep2Amount');
        var payBreakdown = el('apePayBreakdown');
        var payFeeText = el('apePayFeeText');
        var step2Breakdown = el('apeStep2Breakdown');
        var step2FeeText = el('apeStep2FeeText');
        var proofFile = el('apeProofFile');
        var proofPreview = el('apeProofPreview');
        if (s1) s1.style.display = 'none';
        if (s2) s2.style.display = '';
        if (backBtn) backBtn.classList.remove('d-none');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Submit Payment Proof'; }
        if (title) title.textContent = 'GCash Payment';
        if (step2Amount) step2Amount.textContent = amount || '₱--';
        if (step2Breakdown) step2Breakdown.textContent = payBreakdown ? String(payBreakdown.textContent || '').trim() : '';
        if (step2FeeText) step2FeeText.textContent = payFeeText ? String(payFeeText.textContent || '').trim() : '';
        if (proofFile) proofFile.value = '';
        if (proofPreview) proofPreview.classList.add('d-none');
        setAlert('apeAlert', '');
        setAlert('apeAlert2', '');
    }

    function loadPrice(months) {
        var info = el('apePayInfo');
        var amt = el('apePayAmount');
        var breakdown = el('apePayBreakdown');
        var feeText = el('apePayFeeText');
        if (!info || !amt) return;
        fetchJson('notifications.php?action=get_plan_extension_price&months=' + encodeURIComponent(months))
            .then(function(res) {
                if (res && res.data && res.data.amount_php != null) {
                    amt.textContent = '₱' + Number(res.data.amount_php).toLocaleString();
                    if (breakdown) breakdown.textContent = String(res.data.breakdown_text || '').trim();
                    if (feeText) feeText.textContent = String(res.data.fee_text || '').trim();
                    info.classList.remove('d-none');
                } else {
                    if (breakdown) breakdown.textContent = '';
                    if (feeText) feeText.textContent = '';
                    info.classList.add('d-none');
                }
            }).catch(function() {
                if (breakdown) breakdown.textContent = '';
                if (feeText) feeText.textContent = '';
                info.classList.add('d-none');
            });
    }

    function openModal() {
        var modal = el('adminPlanExtendModal');
        if (!modal) return;
        showStep1();
        var m = el('apeMonths');
        if (m) m.value = '1';
        var note = el('apeNote');
        if (note) note.value = '';
        loadPrice(1);
        if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    function goBack() { showStep1(); }

    function goToCheckout() {
        var monthsEl = el('apeMonths');
        var noteEl = el('apeNote');
        var submitBtn = el('apeSubmitBtn');
        var months = monthsEl ? parseInt(String(monthsEl.value || '0'), 10) : 0;
        if (!isFinite(months) || months <= 0) months = 1;
        var note = noteEl ? String(noteEl.value || '').trim() : '';
        setAlert('apeAlert', '');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Opening GCash checkout...'; }
        var fd = new FormData();
        fd.append('action', 'create_plan_extension_payment');
        fd.append('months', String(months));
        fd.append('note', note);
        fetchJson('notifications.php', { method: 'POST', body: fd }).then(function(res) {
            if (!res.data || res.data.ok !== true) {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Send Request'; }
                setAlert('apeAlert', (res.data && res.data.message) ? res.data.message : 'Failed to start the payment. Please try again.');
                return;
            }
            var url = (res.data.payment && res.data.payment.checkout_url) ? String(res.data.payment.checkout_url) : '';
            if (!url) {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Send Request'; }
                setAlert('apeAlert', 'Payment gateway did not return a checkout URL.');
                return;
            }
            window.location.href = url;
        }).catch(function() {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Send Request'; }
            setAlert('apeAlert', 'Network error. Please try again.');
        });
    }

    function submit() {
        var step2 = el('apeStep2');
        if (step2 && step2.style.display !== 'none') {
            submitWithProof();
            return;
        }
        var monthsEl = el('apeMonths');
        var months = monthsEl ? parseInt(String(monthsEl.value || '0'), 10) : 0;
        if (!isFinite(months) || months <= 0) months = 1;
        var amountText = el('apePayAmount');
        var amountStr = amountText ? amountText.textContent.replace('₱', '').replace(/,/g, '').trim() : '0';
        var amount = parseInt(amountStr, 10);
        if (!isFinite(amount) || amount <= 0) amount = 0;
        goToCheckout();
    }

    function submitWithProof() {
        var monthsEl = el('apeMonths');
        var noteEl = el('apeNote');
        var proofFile = el('apeProofFile');
        var submitBtn = el('apeSubmitBtn');
        var months = monthsEl ? parseInt(String(monthsEl.value || '0'), 10) : 0;
        if (!isFinite(months) || months <= 0) months = 1;
        var note = noteEl ? String(noteEl.value || '').trim() : '';
        setAlert('apeAlert2', '');
        if (!proofFile || !proofFile.files || !proofFile.files[0]) {
            setAlert('apeAlert2', 'Please upload your proof of payment before submitting.');
            return;
        }
        var file = proofFile.files[0];
        if (file.size > 10 * 1024 * 1024) { setAlert('apeAlert2', 'File too large. Maximum size is 10MB.'); return; }
        var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        if (allowedTypes.indexOf(file.type) === -1) { setAlert('apeAlert2', 'Invalid file type.'); return; }
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Submitting...'; }
        var fd = new FormData();
        fd.append('action', 'request_plan_extension');
        fd.append('months', String(months));
        fd.append('note', note);
        fd.append('payment_proof', proofFile.files[0]);
        fetchJson('notifications.php', { method: 'POST', body: fd }).then(function(res) {
            if (!res.data || res.data.ok !== true) {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Submit Payment Proof'; }
                setAlert('apeAlert2', (res.data && res.data.message) ? res.data.message : 'Failed to submit request.');
                return;
            }
            var modal = el('adminPlanExtendModal');
            if (modal && window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).hide();
            if (window.Swal) Swal.fire({ icon: 'success', title: 'Request Submitted', text: 'Your plan extension request has been sent to Superadmin.', timer: 2500, showConfirmButton: false });
            showStep1();
        }).catch(function() {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Submit Payment Proof'; }
            setAlert('apeAlert2', 'Network error. Please try again.');
        });
    }

    document.addEventListener('click', function(e) {
        var btn = e.target && e.target.closest ? e.target.closest('#adminPlanExtendBtn') : null;
        if (!btn) return;
        e.preventDefault();
        openModal();
    });
    document.addEventListener('click', function(e) {
        var btn = e.target && e.target.closest ? e.target.closest('#apeSubmitBtn') : null;
        if (!btn) return;
        e.preventDefault();
        submit();
    });
    document.addEventListener('click', function(e) {
        var btn = e.target && e.target.closest ? e.target.closest('#apeBackBtn') : null;
        if (!btn) return;
        e.preventDefault();
        goBack();
    });
    document.addEventListener('change', function(e) {
        var m = e.target && e.target.closest ? e.target.closest('#apeMonths') : null;
        if (!m) return;
        var months = parseInt(String(m.value || '0'), 10);
        if (!isFinite(months) || months <= 0) months = 1;
        loadPrice(months);
    });
    document.addEventListener('change', function(e) {
        var f = e.target && e.target.closest ? e.target.closest('#apeProofFile') : null;
        if (!f) return;
        var preview = el('apeProofPreview');
        var previewImg = el('apeProofPreviewImg');
        var submitBtn = el('apeSubmitBtn');
        if (!f.files || !f.files[0]) {
            if (preview) preview.classList.add('d-none');
            if (submitBtn) submitBtn.disabled = true;
            return;
        }
        var file = f.files[0];
        if (file.type.indexOf('image/') === 0) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                if (previewImg) previewImg.src = ev.target.result;
                if (preview) preview.classList.remove('d-none');
            };
            reader.readAsDataURL(file);
        } else {
            if (previewImg) previewImg.src = '';
            if (preview) preview.classList.add('d-none');
        }
        if (submitBtn) submitBtn.disabled = false;
    });

    window.addEventListener('message', function(e) {
        if (!e || !e.data || e.data.type !== 'ctr_plan_payment_paid') return;
        var modal = el('adminPlanExtendModal');
        if (modal && window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).hide();
        if (window.Swal) {
            if (e.data.paid === true) {
                Swal.fire({ icon: 'success', title: 'Payment Successful', text: 'Your plan has been extended automatically.', timer: 2500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'info', title: 'Payment Processing', text: 'We will confirm your payment shortly.', timer: 2500, showConfirmButton: false });
            }
        }
        showStep1();
    });
})();
</script>

<script>
(function () {
    var STORAGE_KEY = 'ctr-home-title-sync';
    var CHANNEL_NAME = 'contracs-home-title';
    var DEFAULT_TITLE = 'SCHOOLS DIVISION OFFICE OF KABANKALAN CITY';
    var channel = null;

    function getTitleEl() {
        return document.querySelector('.home-header-title');
    }

    function applyTitle(title) {
        var el = getTitleEl();
        if (!el) return;
        var next = String(title == null ? '' : title).trim();
        el.textContent = next !== '' ? next : DEFAULT_TITLE;
    }

    function readStoredTitle() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);
            if (!raw) return '';
            var data = JSON.parse(raw);
            return data && typeof data === 'object' ? String(data.title || '').trim() : '';
        } catch (e) {
            return '';
        }
    }

    function init() {
        applyTitle(readStoredTitle());
    }

    try {
        if ('BroadcastChannel' in window) {
            channel = new BroadcastChannel(CHANNEL_NAME);
            channel.addEventListener('message', function (ev) {
                if (!ev || !ev.data) return;
                applyTitle(ev.data.title);
            });
        }
    } catch (e) { channel = null; }

    window.addEventListener('storage', function (e) {
        if (!e || e.key !== STORAGE_KEY) return;
        try {
            var data = e.newValue ? JSON.parse(e.newValue) : null;
            applyTitle(data && data.title ? data.title : '');
        } catch (e2) { applyTitle(''); }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
</body>
</html>
<?php
$body = ob_get_clean();
$etag = ctr_etag_from(['b' => floor(time() / 30), 'k' => $homeKey, 'uid' => $sessionUserId, 'title' => $homeHeaderTitle]);
ctr_handle_conditional($etag, 30);
echo $body;
?>
