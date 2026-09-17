<?php

require __DIR__ . '/includes/cache_headers.php';
// Login pages must never be cached; the form contains CSRF state and
// must always re-evaluate the redirect-if-logged-in branch.
ctr_cache_headers('private');

require __DIR__ . '/config/db.php';

ctr_session_start();

ctr_canonicalize('login');

function ensure_users_plan_schema_safe(PDO $pdo): void
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
        if (!isset($existing['is_active'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_active tinyint(1) NOT NULL DEFAULT 1");
        }
        if (!isset($existing['plan_months'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN plan_months INT NULL");
        }
        if (!isset($existing['plan_expires_at'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN plan_expires_at DATETIME NULL");
        }
        if (!isset($existing['admin_home_title'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN admin_home_title VARCHAR(255) NULL");
        }
    } catch (Throwable $e) {
    }
}

ensure_users_plan_schema_safe($pdo);

/**
 * Brute-force protection: track failed login attempts (hashed client IP +
 * username) in a small table and lock out after too many failures.
 */
function ensure_login_attempts_schema_safe(PDO $pdo): void
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id INT NOT NULL AUTO_INCREMENT,
                ip_hash CHAR(64) NOT NULL,
                username VARCHAR(191) NOT NULL,
                attempted_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_login_attempts_ip (ip_hash, attempted_at),
                KEY idx_login_attempts_user (username, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
    }
}

function ctr_login_ip_hash(): string
{
    return hash('sha256', 'ctr_login_throttle_v1|' . (string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function ctr_login_is_throttled(PDO $pdo, string $username): bool
{
    ensure_login_attempts_schema_safe($pdo);
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE (ip_hash = :ip OR username = :u)
               AND attempted_at > (NOW() - INTERVAL 15 MINUTE)"
        );
        $stmt->execute([':ip' => ctr_login_ip_hash(), ':u' => $username]);
        return ((int)$stmt->fetchColumn()) >= 5;
    } catch (Throwable $e) {
        return false;
    }
}

function ctr_login_record_failure(PDO $pdo, string $username): void
{
    ensure_login_attempts_schema_safe($pdo);
    try {
        $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_hash, username, attempted_at) VALUES (:ip, :u, NOW())");
        $stmt->execute([':ip' => ctr_login_ip_hash(), ':u' => $username]);
    } catch (Throwable $e) {
    }
}

function ctr_login_clear_failures(PDO $pdo, string $username): void
{
    ensure_login_attempts_schema_safe($pdo);
    try {
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_hash = :ip OR username = :u");
        $stmt->execute([':ip' => ctr_login_ip_hash(), ':u' => $username]);
    } catch (Throwable $e) {
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // CSRF: reject forged cross-site login posts.
    ctr_csrf_check();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($username) || empty($password)) {
        $error = 'Username and password are required.';
    } elseif (ctr_login_is_throttled($pdo, $username)) {
        $error = 'Too many failed login attempts. Please wait 15 minutes and try again.';
        usleep(300000);
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id, username, password, role, name, id_number, position, department, is_active, plan_expires_at FROM users WHERE username = :username LIMIT 1');
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                // Only allow admin and superadmin to login
                if ($user['role'] !== 'admin' && $user['role'] !== 'superadmin') {
                    $error = 'You do not have permission to login.';
                } else {
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['id_number'] = $user['id_number'];
                    $_SESSION['position'] = $user['position'];
                    $_SESSION['department'] = $user['department'] ?? '';
                    $_SESSION['logged_in'] = true;

                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        $expires = time() + (30 * 24 * 60 * 60);
                        $cookiePath = ctr_url('');
                        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

                        if ($cookiePath !== '/') {
                            setcookie('remember_token', '', time() - 42000, '/');
                            setcookie('remember_user', '', time() - 42000, '/');
                        }
                        setcookie('remember_token', $token, [
                            'expires' => $expires,
                            'path' => $cookiePath,
                            'secure' => $secure,
                            'httponly' => true,
                            'samesite' => 'Lax',
                        ]);
                        setcookie('remember_user', $username, [
                            'expires' => $expires,
                            'path' => $cookiePath,
                            'secure' => $secure,
                            'httponly' => true,
                            'samesite' => 'Lax',
                        ]);
                    }

                    ctr_login_clear_failures($pdo, $username);
                    header('Location: ' . ctr_url('index'));
                    exit;
                }
            } else {
                ctr_login_record_failure($pdo, $username);
                $error = 'Invalid username or password.';
                usleep(250000);
            }
        } catch (PDOException $e) {
            $error = 'Login failed. Please try again.';
        }
    }
}

// Check if user is already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: ' . ctr_url('index'));
    exit;
}

$prefillUsername = '';
if (isset($_POST['username'])) {
    $prefillUsername = (string)($_POST['username'] ?? '');
} elseif (isset($_COOKIE['remember_user'])) {
    $prefillUsername = (string)($_COOKIE['remember_user'] ?? '');
}
$rememberChecked = isset($_POST['remember']) || (isset($_COOKIE['remember_user']) && trim((string)$_COOKIE['remember_user']) !== '');
$logoutNotice = (isset($_GET['logout']) && (string)($_GET['logout'] ?? '') === '1');

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="User Management System Login">
    <meta name="keyword" content="login, user management, authentication">
    <meta name="author" content="User Management System">
    <title>ConTracs || Sign In</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png?v=<?php echo @filemtime(__DIR__ . '/assets/images/favicon.png'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="assets/css/inter.css" rel="stylesheet" />
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/custom.css">
    <style>
        :root {
            --ctr-bg: #0b1220;
            --ctr-card: rgba(255, 255, 255, 0.92);
            --ctr-text: #0b1220;
            --ctr-muted: rgba(11, 18, 32, 0.70);
            --ctr-border: rgba(11, 18, 32, 0.14);
            --ctr-primary: #1F65B8;
            --ctr-accent: #2F7D28;
            --ctr-danger: #DC2626;
            --ctr-warning: #F59E0B;
            --ctr-shadow: 0 22px 80px rgba(0, 0, 0, 0.35);
            --ctr-shadow-sm: 0 6px 20px rgba(0, 0, 0, 0.15);
            --ctr-input-bg: rgba(255, 255, 255, 0.86);
            --ctr-input-color: #0b1220;
            --ctr-input-placeholder: rgba(11, 18, 32, 0.45);
            --ctr-toggle-bg: rgba(255, 255, 255, 0.92);
            --ctr-toggle-color: rgba(11, 18, 32, 0.86);
            --ctr-meter-bg: rgba(11, 18, 32, 0.10);
            --ctr-social-bg: rgba(255, 255, 255, 0.80);
            --ctr-divider: rgba(11, 18, 32, 0.14);
            --ctr-divider-text: rgba(11, 18, 32, 0.60);
            --ctr-footer-text: rgba(255, 255, 255, 0.92);
            --ctr-radius: 14px;
        }

        html[data-bs-theme="dark"],
        html.app-skin-dark {
            --ctr-card: #1e293b;
            --ctr-text: rgba(248, 250, 252, 0.92);
            --ctr-muted: rgba(226, 232, 240, 0.70);
            --ctr-border: rgba(148, 163, 184, 0.22);
            --ctr-input-bg: #0f172a;
            --ctr-input-color: rgba(248, 250, 252, 0.92);
            --ctr-input-placeholder: rgba(148, 163, 184, 0.50);
            --ctr-toggle-bg: rgba(148, 163, 184, 0.14);
            --ctr-toggle-color: rgba(248, 250, 252, 0.86);
            --ctr-meter-bg: rgba(148, 163, 184, 0.20);
            --ctr-social-bg: #0f172a;
            --ctr-divider: rgba(148, 163, 184, 0.22);
            --ctr-divider-text: rgba(226, 232, 240, 0.60);
            --ctr-footer-text: rgba(248, 250, 252, 0.92);
            --ctr-shadow: 0 22px 80px rgba(0, 0, 0, 0.55);
            --ctr-shadow-sm: 0 6px 20px rgba(0, 0, 0, 0.25);
        }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            background:
                radial-gradient(1000px 600px at 12% 18%, rgba(31, 101, 184, 0.45), transparent 60%),
                radial-gradient(880px 580px at 78% 72%, rgba(47, 125, 40, 0.40), transparent 62%),
                radial-gradient(1200px 900px at 50% 50%, rgba(255, 255, 255, 0.08), transparent 70%),
                linear-gradient(180deg, #0b1220 0%, #0a1020 100%);
            color: #fff;
        }

        .ctr-auth {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 22px 14px;
        }

        .ctr-card {
            width: min(980px, 100%);
            display: grid;
            grid-template-columns: 1.05fr 1fr;
            border-radius: 22px;
            overflow: hidden;
            box-shadow: var(--ctr-shadow);
            border: 1px solid rgba(255, 255, 255, 0.10);
            backdrop-filter: blur(10px);
            transform: translateY(10px);
            opacity: 0;
            animation: ctrIn 420ms ease forwards;
        }

        /* Custom scrollbar */
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

        html[data-bs-theme="dark"] ::-webkit-scrollbar-thumb,
        html.app-skin-dark ::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.24);
        }

        html[data-bs-theme="light"] ::-webkit-scrollbar-thumb {
            background: rgba(15, 23, 42, 0.14);
        }

        * {
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.24) transparent;
        }

        @keyframes ctrIn {
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes ctrAlertSlide {
            from { opacity: 0; transform: translateY(-8px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes ctrSpin {
            to { transform: rotate(360deg); }
        }

        @media (prefers-reduced-motion: reduce) {
            .ctr-card { animation: none; transform: none; opacity: 1; }
            .ctr-alert { animation: none; }
        }

        .ctr-side {
            background:
                radial-gradient(900px 520px at 32% 25%, rgba(255, 255, 255, 0.18), transparent 62%),
                radial-gradient(820px 520px at 75% 78%, rgba(255, 255, 255, 0.10), transparent 60%),
                linear-gradient(135deg, rgba(31, 101, 184, 0.92) 0%, rgba(47, 125, 40, 0.92) 100%);
            padding: 28px 26px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 18px;
        }

        .ctr-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .ctr-brand img {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.9);
            padding: 6px;
        }

        .ctr-brand-title {
            font-weight: 900;
            letter-spacing: 0.2px;
            line-height: 1.1;
            font-size: 18px;
        }

        .ctr-brand-sub {
            font-weight: 800;
            opacity: 0.9;
            font-size: 12px;
        }

        .ctr-side-hero {
            display: grid;
            gap: 10px;
        }

        .ctr-side-hero h1 {
            margin: 0;
            font-weight: 900;
            font-size: clamp(22px, 3.2vw, 34px);
            letter-spacing: 0.2px;
        }

        .ctr-side-hero p {
            margin: 0;
            font-weight: 700;
            opacity: 0.92;
            line-height: 1.35;
            font-size: 14px;
        }

        .ctr-side-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .ctr-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.22);
            font-weight: 800;
            font-size: 12px;
            white-space: nowrap;
        }

        .ctr-badge svg { width: 14px; height: 14px; }

        .ctr-form {
            background: var(--ctr-card);
            color: var(--ctr-text);
            padding: 28px 26px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 14px;
        }

        .ctr-form h2 {
            margin: 0;
            font-weight: 900;
            letter-spacing: 0.2px;
            font-size: 22px;
        }

        .ctr-form p {
            margin: 0;
            color: var(--ctr-muted);
            font-weight: 700;
            font-size: 13px;
            line-height: 1.35;
        }

        .ctr-alert {
            border-radius: 14px;
            padding: 12px 12px;
            border: 1px solid var(--ctr-border);
            background: var(--ctr-card);
            color: var(--ctr-text);
            display: flex;
            align-items: flex-start;
            gap: 10px;
            animation: ctrAlertSlide 350ms ease both;
        }

        .ctr-alert.ctr-danger {
            border-color: rgba(220, 38, 38, 0.30);
            background: rgba(220, 38, 38, 0.10);
        }

        .ctr-alert.ctr-success {
            border-color: rgba(22, 163, 74, 0.30);
            background: rgba(22, 163, 74, 0.10);
        }

        .ctr-alert .ctr-alert-title {
            font-weight: 900;
            color: var(--ctr-text);
        }

        .ctr-alert .ctr-alert-text {
            font-weight: 800;
            color: var(--ctr-text);
            font-size: 13px;
            line-height: 1.35;
        }

        .ctr-fields {
            display: grid;
            gap: 12px;
            margin-top: 8px;
        }

        .ctr-field label {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 10px;
            font-weight: 900;
            font-size: 13px;
            margin-bottom: 6px;
        }

        .ctr-field .ctr-help {
            font-weight: 800;
            color: var(--ctr-muted);
            font-size: 12px;
        }

        .ctr-input {
            width: 100%;
            height: 48px;
            border-radius: var(--ctr-radius);
            border: 1.5px solid var(--ctr-border);
            padding: 0 14px;
            outline: none;
            font-weight: 800;
            font-size: 15px;
            color: var(--ctr-input-color);
            background: var(--ctr-input-bg);
            transition: border-color 200ms ease, box-shadow 200ms ease, transform 120ms ease, background 200ms ease;
            box-sizing: border-box;
        }

        .ctr-input::placeholder {
            color: var(--ctr-input-placeholder);
            font-weight: 600;
        }

        .ctr-input:hover {
            border-color: rgba(31, 101, 184, 0.35);
        }

        .ctr-input:focus-visible {
            border-color: rgba(31, 101, 184, 0.62);
            box-shadow: 0 0 0 4px rgba(31, 101, 184, 0.14), 0 2px 8px rgba(31, 101, 184, 0.06);
            transform: translateY(-1px);
            background: var(--ctr-card);
        }

        .ctr-input.is-invalid {
            border-color: rgba(220, 38, 38, 0.64);
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.12);
        }

        .ctr-input.is-invalid:focus-visible {
            border-color: rgba(220, 38, 38, 0.80);
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.16);
        }

        .ctr-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 2px;
        }

        .ctr-check {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 800;
            color: var(--ctr-text);
            font-size: 13px;
            user-select: none;
            cursor: pointer;
            padding: 4px 0;
        }

        .ctr-check input {
            width: 18px;
            height: 18px;
            accent-color: var(--ctr-primary);
            cursor: pointer;
            transition: transform 120ms ease;
        }

        .ctr-check input:checked {
            transform: scale(1.1);
        }

        .ctr-check:hover input:not(:checked) {
            transform: scale(1.08);
        }

        .ctr-link {
            color: var(--ctr-primary);
            font-weight: 900;
            text-decoration: none;
        }

        .ctr-link:focus-visible {
            outline: none;
            border-radius: 8px;
            box-shadow: 0 0 0 4px rgba(31, 101, 184, 0.18);
        }

        .ctr-error {
            margin-top: 6px;
            min-height: 18px;
            font-weight: 800;
            font-size: 12px;
            color: rgba(220, 38, 38, 0.92);
        }

        .ctr-password-wrap {
            position: relative;
        }

        .ctr-toggle {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            height: 36px;
            padding: 0 12px;
            border-radius: 12px;
            border: 1px solid var(--ctr-border);
            background: var(--ctr-toggle-bg);
            color: var(--ctr-toggle-color);
            font-weight: 800;
            font-size: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: transform 180ms ease, box-shadow 200ms ease, border-color 200ms ease, background 200ms ease;
        }

        .ctr-toggle:hover {
            transform: translateY(-50%) translateY(-1px);
            border-color: rgba(31, 101, 184, 0.45);
        }

        .ctr-toggle:focus-visible {
            outline: none;
            border-color: rgba(31, 101, 184, 0.60);
            box-shadow: 0 0 0 4px rgba(31, 101, 184, 0.14);
        }

        .ctr-toggle .ctr-toggle-icon {
            transition: transform 200ms ease;
        }

        .ctr-toggle.is-visible .ctr-toggle-icon {
            transform: rotate(180deg);
        }

        .ctr-strength {
            display: grid;
            gap: 6px;
            margin-top: 8px;
        }

        .ctr-meter {
            height: 8px;
            width: 100%;
            border-radius: 999px;
            background: var(--ctr-meter-bg);
            overflow: hidden;
        }

        .ctr-meter > div {
            height: 100%;
            width: 0%;
            border-radius: 999px;
            transition: width 220ms ease, background-color 220ms ease;
            background: rgba(220, 38, 38, 0.92);
        }

        .ctr-strength-text {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            color: var(--ctr-muted);
            font-weight: 800;
            font-size: 12px;
        }

        .ctr-btn {
            height: 48px;
            border-radius: var(--ctr-radius);
            border: 0;
            font-weight: 900;
            letter-spacing: 0.2px;
            cursor: pointer;
            font-size: 15px;
            transition: transform 180ms ease, box-shadow 200ms ease, background-color 200ms ease, opacity 200ms ease;
            position: relative;
            overflow: hidden;
        }

        .ctr-btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none !important;
        }

        .ctr-btn-primary {
            background: linear-gradient(135deg, rgba(31, 101, 184, 0.96) 0%, rgba(47, 125, 40, 0.94) 100%);
            color: #fff;
        }

        .ctr-btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 14px 30px rgba(31, 101, 184, 0.28);
        }

        .ctr-btn-primary:active:not(:disabled) {
            transform: translateY(0);
            box-shadow: 0 4px 12px rgba(31, 101, 184, 0.20);
        }

        .ctr-loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 300ms ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .ctr-loading-spinner {
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }

        .ctr-loading-ring {
            width: 56px;
            height: 56px;
            border: 5px solid rgba(255, 255, 255, 0.12);
            border-top-color: #22c55e;
            border-right-color: rgba(34, 197, 94, 0.30);
            border-radius: 50%;
            animation: ctrSpin 800ms linear infinite;
        }

        .ctr-loading-pulse {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .ctr-loading-pulse .p {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #22c55e;
            animation: ctrPulse 1.4s ease-in-out infinite;
        }

        .ctr-loading-pulse .p:nth-child(2) { animation-delay: 0.2s; }
        .ctr-loading-pulse .p:nth-child(3) { animation-delay: 0.4s; }

        @keyframes ctrPulse {
            0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
            40% { transform: scale(1); opacity: 1; }
        }

        .ctr-loading-text {
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.5px;
            animation: ctrTextPulse 2s ease-in-out infinite;
        }

        @keyframes ctrTextPulse {
            0%, 100% { opacity: 0.7; }
            50% { opacity: 1; }
        }

        .ctr-loading-sub {
            color: rgba(255, 255, 255, 0.60);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        /* Dark mode */
        html[data-bs-theme="dark"] body,
        html.app-skin-dark body {
            background: #0f172a;
        }

        html[data-bs-theme="dark"] .ctr-card,
        html.app-skin-dark .ctr-card {
            background: #1e293b;
            border-color: rgba(148, 163, 184, 0.16);
        }

        html[data-bs-theme="dark"] .ctr-side,
        html.app-skin-dark .ctr-side {
            background:
                radial-gradient(900px 520px at 32% 25%, rgba(255, 255, 255, 0.10), transparent 62%),
                radial-gradient(820px 520px at 75% 78%, rgba(255, 255, 255, 0.06), transparent 60%),
                linear-gradient(135deg, rgba(15, 23, 42, 0.92) 0%, rgba(30, 41, 59, 0.92) 100%);
        }

        html[data-bs-theme="dark"] .ctr-brand-title,
        html.app-skin-dark .ctr-brand-title,
        html[data-bs-theme="dark"] .ctr-brand-sub,
        html.app-skin-dark .ctr-brand-sub,
        html[data-bs-theme="dark"] .ctr-badge,
        html.app-skin-dark .ctr-badge {
            color: rgba(248, 250, 252, 0.92);
        }

        html[data-bs-theme="dark"] .ctr-form h2,
        html.app-skin-dark .ctr-form h2,
        html[data-bs-theme="dark"] .ctr-field label,
        html.app-skin-dark .ctr-field label,
        html[data-bs-theme="dark"] .ctr-alert-title,
        html.app-skin-dark .ctr-alert-title,
        html[data-bs-theme="dark"] .ctr-alert-text,
        html.app-skin-dark .ctr-alert-text {
            color: rgba(248, 250, 252, 0.92);
        }

        html[data-bs-theme="dark"] .ctr-form p,
        html.app-skin-dark .ctr-form p,
        html[data-bs-theme="dark"] .ctr-help,
        html.app-skin-dark .ctr-help {
            color: rgba(226, 232, 240, 0.70);
        }

        html[data-bs-theme="dark"] .ctr-input,
        html.app-skin-dark .ctr-input {
            background: #0f172a;
            border-color: rgba(148, 163, 184, 0.20);
            color: rgba(248, 250, 252, 0.92);
        }

        html[data-bs-theme="dark"] .ctr-input::placeholder,
        html.app-skin-dark .ctr-input::placeholder {
            color: rgba(148, 163, 184, 0.50);
        }

        html[data-bs-theme="dark"] .ctr-input:focus-visible,
        html.app-skin-dark .ctr-input:focus-visible {
            border-color: rgba(34, 197, 94, 0.62);
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.18);
        }

        html[data-bs-theme="dark"] .ctr-input.is-invalid,
        html.app-skin-dark .ctr-input.is-invalid {
            border-color: rgba(239, 68, 68, 0.70);
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.18);
        }

        html[data-bs-theme="dark"] .ctr-toggle,
        html.app-skin-dark .ctr-toggle {
            background: rgba(148, 163, 184, 0.14);
            color: rgba(248, 250, 252, 0.86);
            border-color: rgba(148, 163, 184, 0.22);
        }

        html[data-bs-theme="dark"] .ctr-link,
        html.app-skin-dark .ctr-link {
            color: #22c55e;
        }

        html[data-bs-theme="dark"] .ctr-link:hover,
        html.app-skin-dark .ctr-link:hover {
            color: #4ade80;
        }

        html[data-bs-theme="dark"] .ctr-check,
        html.app-skin-dark .ctr-check {
            color: rgba(248, 250, 252, 0.85);
        }

        html[data-bs-theme="dark"] .ctr-meter,
        html.app-skin-dark .ctr-meter {
            background: rgba(148, 163, 184, 0.20);
        }

        html[data-bs-theme="dark"] .ctr-strength-text,
        html.app-skin-dark .ctr-strength-text {
            color: rgba(226, 232, 240, 0.60);
        }

        html[data-bs-theme="dark"] #capsLockHint,
        html.app-skin-dark #capsLockHint {
            color: #ef4444;
        }

        html[data-bs-theme="dark"] .ctr-error,
        html.app-skin-dark .ctr-error {
            color: #ef4444;
        }

        html[data-bs-theme="dark"] .ctr-live,
        html.app-skin-dark .ctr-live {
            color: rgba(226, 232, 240, 0.60);
        }

        html[data-bs-theme="dark"] .ctr-divider,
        html.app-skin-dark .ctr-divider {
            color: rgba(226, 232, 240, 0.60);
        }

        html[data-bs-theme="dark"] .ctr-divider:before,
        html[data-bs-theme="dark"] .ctr-divider:after,
        html.app-skin-dark .ctr-divider:before,
        html.app-skin-dark .ctr-divider:after {
            background: rgba(148, 163, 184, 0.22);
        }

        html[data-bs-theme="dark"] .ctr-social button,
        html.app-skin-dark .ctr-social button {
            background: #0f172a;
            border-color: rgba(148, 163, 184, 0.22);
            color: rgba(248, 250, 252, 0.86);
        }

        html[data-bs-theme="dark"] .ctr-social button:hover,
        html.app-skin-dark .ctr-social button:hover {
            border-color: rgba(34, 197, 94, 0.45);
        }

        html[data-bs-theme="dark"] .ctr-theme-toggle,
        html.app-skin-dark .ctr-theme-toggle {
            background: rgba(148, 163, 184, 0.12);
            color: rgba(248, 250, 252, 0.86);
            border-color: rgba(148, 163, 184, 0.22);
        }

        html[data-bs-theme="dark"] .ctr-theme-toggle:hover,
        html.app-skin-dark .ctr-theme-toggle:hover {
            background: rgba(148, 163, 184, 0.18);
            border-color: rgba(34, 197, 94, 0.45);
        }

        html[data-bs-theme="dark"] .modal-content,
        html.app-skin-dark .modal-content {
            background: #1e293b;
            color: rgba(248, 250, 252, 0.92);
        }

        html[data-bs-theme="dark"] .modal-header,
        html.app-skin-dark .modal-header,
        html[data-bs-theme="dark"] .modal-footer,
        html.app-skin-dark .modal-footer {
            border-color: rgba(148, 163, 184, 0.22);
        }

        html[data-bs-theme="dark"] .alert-info,
        html.app-skin-dark .alert-info {
            background: rgba(31, 101, 184, 0.15);
            border-color: rgba(31, 101, 184, 0.30);
            color: #93c5fd;
        }

        html[data-bs-theme="dark"] .alert-success,
        html.app-skin-dark .alert-success {
            background: rgba(22, 163, 74, 0.15);
            border-color: rgba(22, 163, 74, 0.30);
            color: #4ade80;
        }

        html[data-bs-theme="dark"] .btn-close,
        html.app-skin-dark .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        html[data-bs-theme="dark"] .bg-light,
        html.app-skin-dark .bg-light {
            background-color: rgba(148, 163, 184, 0.14) !important;
        }

        html[data-bs-theme="dark"] .modal-body hr,
        html.app-skin-dark .modal-body hr {
            border-color: rgba(148, 163, 184, 0.22);
        }

        /* Credits Modal Styles */
        .ctr-credits-modal {
            border-radius: 16px;
            overflow: hidden;
        }

        .ctr-credits-header {
            background: linear-gradient(135deg, rgba(31, 101, 184, 0.96) 0%, rgba(47, 125, 40, 0.94) 100%);
            color: #fff;
            border: none;
        }

        .ctr-credits-header .modal-title {
            color: #fff;
        }

        .ctr-credits-body {
            background: #fff;
            color: #1e293b;
        }

        .ctr-credits-title {
            color: #1e293b;
        }

        .ctr-credits-subtitle {
            color: #64748b;
        }

        .ctr-credits-divider {
            border-color: #e2e8f0;
        }

        .ctr-credits-info-box {
            background: #f1f5f9;
        }

        .ctr-credits-name {
            color: #1e293b;
        }

        .ctr-credits-role {
            color: #64748b;
        }

        .ctr-credits-footer {
            background: #fff;
            border-top: 1px solid #e2e8f0;
        }

        /* Credits Modal Dark Mode */
        html[data-bs-theme="dark"] .ctr-credits-body,
        html.app-skin-dark .ctr-credits-body {
            background: #1e293b;
            color: rgba(248, 250, 252, 0.92);
        }

        html[data-bs-theme="dark"] .ctr-credits-title,
        html.app-skin-dark .ctr-credits-title {
            color: rgba(248, 250, 252, 0.92) !important;
        }

        html[data-bs-theme="dark"] .ctr-credits-subtitle,
        html.app-skin-dark .ctr-credits-subtitle {
            color: rgba(226, 232, 240, 0.70) !important;
        }

        html[data-bs-theme="dark"] .ctr-credits-divider,
        html.app-skin-dark .ctr-credits-divider {
            border-color: rgba(148, 163, 184, 0.22);
        }

        html[data-bs-theme="dark"] .ctr-credits-info-box,
        html.app-skin-dark .ctr-credits-info-box {
            background: rgba(148, 163, 184, 0.14) !important;
        }

        html[data-bs-theme="dark"] .ctr-credits-name,
        html.app-skin-dark .ctr-credits-name {
            color: rgba(248, 250, 252, 0.92) !important;
        }

        html[data-bs-theme="dark"] .ctr-credits-role,
        html.app-skin-dark .ctr-credits-role {
            color: rgba(226, 232, 240, 0.70) !important;
        }

        html[data-bs-theme="dark"] .ctr-credits-footer,
        html.app-skin-dark .ctr-credits-footer {
            background: #1e293b;
            border-top: 1px solid rgba(148, 163, 184, 0.22);
        }

        /* Theme toggle button */
        .ctr-theme-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            border: 1.5px solid var(--ctr-border);
            background: var(--ctr-card);
            color: var(--ctr-text);
            cursor: pointer;
            transition: transform 200ms ease, box-shadow 200ms ease, background-color 200ms ease, border-color 200ms ease;
            position: relative;
        }

        .ctr-theme-toggle:hover {
            transform: translateY(-2px);
            box-shadow: var(--ctr-shadow-sm);
            border-color: rgba(31, 101, 184, 0.45);
        }

        .ctr-theme-toggle:active {
            transform: translateY(0);
        }

        .ctr-theme-toggle:focus-visible {
            outline: none;
            box-shadow: 0 0 0 4px rgba(31, 101, 184, 0.14);
        }

        .ctr-theme-toggle i {
            width: 18px;
            height: 18px;
            transition: transform 300ms ease;
        }

        .ctr-theme-toggle:hover i {
            transform: rotate(15deg) scale(1.1);
        }

        .ctr-theme-toggle .ctr-toggle-tooltip {
            position: absolute;
            top: -28px;
            bottom: auto;
            left: 50%;
            transform: translateX(-50%) translateY(4px);
            background: rgba(0, 0, 0, 0.80);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 200ms ease, transform 200ms ease;
        }

        .ctr-theme-toggle:hover .ctr-toggle-tooltip {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .ctr-btn:focus-visible {
            outline: none;
            box-shadow: 0 0 0 4px rgba(31, 101, 184, 0.18);
        }

        .ctr-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 12px 0 4px;
            color: var(--ctr-divider-text);
            font-weight: 900;
            font-size: 12px;
        }

        .ctr-divider:before,
        .ctr-divider:after {
            content: "";
            height: 1px;
            flex: 1;
            background: var(--ctr-divider);
        }

        .ctr-social {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .ctr-social button {
            height: 44px;
            border-radius: 14px;
            border: 1px solid var(--ctr-border);
            background: var(--ctr-social-bg);
            color: var(--ctr-text);
            font-weight: 900;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: transform 120ms ease, box-shadow 160ms ease, border-color 160ms ease;
        }

        .ctr-social button:hover { transform: translateY(-1px); box-shadow: 0 12px 26px rgba(0, 0, 0, 0.10); }
        .ctr-social button:active { transform: translateY(0); }

        .ctr-social button:focus-visible {
            outline: none;
            border-color: rgba(31, 101, 184, 0.55);
            box-shadow: 0 0 0 4px rgba(31, 101, 184, 0.18);
        }

        .ctr-social button[aria-disabled="true"] {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .ctr-social button svg { width: 16px; height: 16px; }

        .ctr-live {
            min-height: 18px;
            color: var(--ctr-muted);
            font-weight: 800;
            font-size: 12px;
            margin-top: 8px;
        }

        @media (max-width: 1023px) {
            .ctr-card {
                grid-template-columns: 1fr;
                max-width: 520px;
            }
            .ctr-side { display: none; }
            .ctr-form { border-radius: 22px; }
        }

        @media (max-width: 575px) {
            .ctr-card {
                border-radius: 18px;
            }

            .ctr-form {
                padding: 20px 18px;
                gap: 12px;
                border-radius: 18px;
            }

            .ctr-form h2 {
                font-size: 20px;
            }

            .ctr-form p {
                font-size: 12px;
            }

            .ctr-input {
                height: 44px;
                font-size: 14px;
                padding: 0 12px;
            }

            .ctr-btn {
                height: 44px;
                font-size: 14px;
            }

            .ctr-toggle {
                height: 32px;
                padding: 0 10px;
                font-size: 11px;
            }

            .ctr-auth {
                padding: 12px 8px;
            }

            .ctr-alert {
                padding: 10px;
            }

            .ctr-alert-title {
                font-size: 14px;
            }

            .ctr-alert-text {
                font-size: 12px;
            }

            .ctr-theme-toggle {
                width: 36px;
                height: 36px;
            }

            .ctr-theme-toggle .ctr-toggle-tooltip {
                display: none;
            }

            .ctr-check {
                font-size: 12px;
            }

            .ctr-check input {
                width: 16px;
                height: 16px;
            }

            .ctr-link {
                font-size: 12px;
            }

            .ctr-fields {
                gap: 10px;
            }

            .ctr-loading-ring {
                width: 44px;
                height: 44px;
                border-width: 4px;
            }

            .ctr-loading-text {
                font-size: 14px;
            }

            .ctr-strength-text {
                font-size: 11px;
            }
        }

        @media (max-width: 380px) {
            .ctr-form {
                padding: 16px 12px;
            }

            .ctr-form h2 {
                font-size: 18px;
            }

            .ctr-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
            }

            .ctr-auth {
                padding: 8px 4px;
            }

            .ctr-card {
                border-radius: 14px;
            }
        }
    </style>

</head>

<body>
<?php ctr_cookie_consent_banner(); ?>
    <main class="ctr-auth">
        <div class="ctr-card" role="region" aria-label="Sign in">
            <section class="ctr-side" aria-label="ConTracs information">
                <div class="ctr-brand">
                    <img src="assets/images/contracs.png" alt="ConTracs">
                    <div>
                        <div class="ctr-brand-title">ConTracs</div>
                        <div class="ctr-brand-sub">Contracts Tracking System</div>
                    </div>
                </div>
                <div class="ctr-side-hero">
                    <h1>Sign in securely</h1>
                    <p>Access dashboards, attendance, and records with fast, accessible sign-in optimized for desktop and mobile.</p>
                    <div class="ctr-side-badges" aria-label="Highlights">
                        <span class="ctr-badge">
                            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M8.5 11.5 6.8 9.8M13.2 6.8 8.5 11.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 18c4.418 0 8-3.582 8-8s-3.582-8-8-8-8 3.582-8 8 3.582 8 8 8Z" stroke="currentColor" stroke-width="1.6"/></svg>
                            Fast validation
                        </span>
                        <span class="ctr-badge">
                            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2.5 16 5.3v6.2c0 3.5-2.5 5.9-6 6.9-3.5-1-6-3.4-6-6.9V5.3L10 2.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8.2 10.2 9.6 11.6 12.6 8.6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            Security first
                        </span>
                        <span class="ctr-badge">
                            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M6 7.5a4 4 0 0 1 8 0v2.2H6V7.5Z" stroke="currentColor" stroke-width="1.8"/><path d="M5.2 9.7h9.6c.7 0 1.2.5 1.2 1.2v5.1c0 .7-.5 1.2-1.2 1.2H5.2c-.7 0-1.2-.5-1.2-1.2v-5.1c0-.7.5-1.2 1.2-1.2Z" stroke="currentColor" stroke-width="1.8"/></svg>
                            Accessible UI
                        </span>
                    </div>
                </div>
                <div class="ctr-brand-sub">Schools Division Office of Kabankalan City</div>
                <div class="mt-auto pt-3">
                    <a href="#" class="text-white text-decoration-none" data-bs-toggle="modal" data-bs-target="#creditsModal" style="font-size: 12px; opacity: 0.8;">
                        <i class="feather-info me-1"></i>Credits & Version
                    </a>
                </div>
            </section>

            <section class="ctr-form" aria-label="Sign in form">
                <div class="d-flex justify-content-end mb-1">
                    <button type="button" class="ctr-theme-toggle" id="themeToggle" aria-label="Toggle dark mode">
                        <i class="feather-moon" id="themeToggleIcon" aria-hidden="true"></i>
                        <span class="ctr-toggle-tooltip" id="themeToggleTooltip" aria-hidden="true">Dark mode</span>
                    </button>
                </div>
                <div>
                    <h2>Welcome back</h2>
                    <p>Enter your credentials to continue. Use Tab to navigate and Enter to sign in.</p>
                </div>

                <?php if ($logoutNotice): ?>
                    <div class="ctr-alert ctr-success" role="status" aria-live="polite" aria-atomic="true">
                        <div>
                            <div class="ctr-alert-title">Signed out</div>
                            <div class="ctr-alert-text">You have been logged out successfully.</div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="ctr-alert ctr-danger" role="alert" aria-live="assertive" aria-atomic="true">
                        <div>
                            <div class="ctr-alert-title">Sign in failed</div>
                            <div class="ctr-alert-text"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <div id="ctrLiveRegion" class="ctr-live" role="status" aria-live="polite" aria-atomic="true"></div>

                <form id="loginForm" method="POST" novalidate>
                    <input type="hidden" name="login" value="1">
                    <?php ctr_csrf_field(); ?>

                    <div class="ctr-fields">
                        <div class="ctr-field">
                            <label for="username">
                                <span>Username</span>
                                <span class="ctr-help" id="usernameHelp">Required</span>
                            </label>
                            <input
                                class="ctr-input"
                                id="username"
                                name="username"
                                type="text"
                                placeholder="Enter your username"
                                value="<?php echo htmlspecialchars($prefillUsername, ENT_QUOTES, 'UTF-8'); ?>"
                                autocomplete="username"
                                required
                                aria-describedby="usernameHelp usernameError"
                            >
                            <div class="ctr-error" id="usernameError" role="alert" aria-live="polite"></div>
                        </div>

                        <div class="ctr-field">
                            <label for="password">
                                <span>Password</span>
                                <a class="ctr-link" href="#" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">Forgot?</a>
                            </label>
                            <div class="ctr-password-wrap">
                                <input
                                    class="ctr-input"
                                    id="password"
                                    name="password"
                                    type="password"
                                    placeholder="Enter your password"
                                    autocomplete="current-password"
                                    required
                                    aria-describedby="passwordError passwordStrengthText"
                                >
                                <button class="ctr-toggle" id="togglePassword" type="button" aria-label="Show password" aria-pressed="false">
                                    <span class="ctr-toggle-icon">👁</span>
                                    <span id="togglePasswordLabel">Show</span>
                                </button>
                            </div>
                            <div class="ctr-strength" aria-label="Password strength">
                                <div class="ctr-meter" aria-hidden="true"><div id="passwordStrengthBar"></div></div>
                                <div class="ctr-strength-text" id="passwordStrengthText">
                                    <span id="passwordStrengthLabel">Strength: —</span>
                                    <span id="capsLockHint" aria-hidden="true" style="display:none;color:rgba(220,38,38,0.92);">Caps Lock</span>
                                </div>
                            </div>
                            <div class="ctr-error" id="passwordError" role="alert" aria-live="polite"></div>
                        </div>
                    </div>

                    <div class="ctr-row">
                        <label class="ctr-check" for="rememberMe">
                            <input type="checkbox" id="rememberMe" name="remember" <?php echo $rememberChecked ? 'checked' : ''; ?>>
                            Remember me
                        </label>
                        <a class="ctr-link" href="#" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">Need help?</a>
                    </div>

                    <div style="margin-top:14px;">
                        <button class="ctr-btn ctr-btn-primary w-100" id="loginSubmit" type="submit" aria-label="Sign in">Sign in</button>
                    </div>
                    <div class="ctr-live" id="socialLive" role="status" aria-live="polite" aria-atomic="true"></div>
                </form>
            </section>
        </div>
    </main>

    <div class="ctr-loading-overlay" id="loadingOverlay" style="display:none;">
        <div class="ctr-loading-spinner">
            <div class="ctr-loading-ring"></div>
            <div class="ctr-loading-pulse" aria-hidden="true">
                <span class="p"></span>
                <span class="p"></span>
                <span class="p"></span>
            </div>
            <div class="ctr-loading-text">Signing in</div>
            <div class="ctr-loading-sub">Please wait while we verify your credentials</div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Forgot Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Please contact your system administrator to reset your password.</p>
                    <div class="alert alert-info">
                        <strong>Note:</strong> For security reasons, password resets must be handled by administrators.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Credits Modal -->
    <div class="modal fade" id="creditsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content ctr-credits-modal" style="border-radius: 16px; overflow: hidden;">
                <div class="modal-header ctr-credits-header">
                    <h5 class="modal-title fw-bold">Credits</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4 ctr-credits-body">
                    <div class="mb-3">
                        <img src="assets/images/contracs.png" alt="ConTracs" style="width: 64px; height: 64px; border-radius: 16px; background: #fff; padding: 8px;">
                    </div>
                    <h5 class="fw-bold mb-1 ctr-credits-title">ConTracs</h5>
                    <div class="small mb-3 ctr-credits-subtitle">Contract Tracking & Attendance System</div>
                    <div class="badge bg-primary mb-3">Version 4.0.5</div>
                    <hr class="ctr-credits-divider">
                    <div class="text-start">
                        <div class="d-flex align-items-center gap-3 mb-3 p-2 rounded-3 ctr-credits-info-box">
                            <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white" style="width: 48px; height: 48px; flex-shrink: 0;">
                                <i class="feather-user"></i>
                            </div>
                            <div>
                                <div class="fw-bold ctr-credits-name">JOHN KENNETH ANG</div>
                                <div class="small ctr-credits-role">Developer & Creator</div>
                            </div>
                        </div>
                    </div>
                    <hr class="ctr-credits-divider">
                    <div class="small ctr-credits-subtitle">
                        Built with PHP, MySQL, Bootstrap 5, and modern web technologies.
                    </div>
                    <div class="small mt-2 ctr-credits-subtitle">
                        &copy; <?php echo date('Y'); ?> ConTracs. All rights reserved.
                    </div>
                </div>
                <div class="modal-footer justify-content-center ctr-credits-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/common-init.min.js"></script>
    <script src="assets/js/theme-customizer-init.min.js"></script>
    <script src="assets/js/source-guard.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/source-guard.js'); ?>"></script>
    
    <script>
        (function () {
            function el(id) { return document.getElementById(id); }
            function setLive(msg) {
                var n = el('ctrLiveRegion');
                if (n) n.textContent = String(msg || '');
            }
            function setSocialLive(msg) {
                var n = el('socialLive');
                if (n) n.textContent = String(msg || '');
            }
            function setError(inputEl, errorEl, msg) {
                if (inputEl) inputEl.classList.toggle('is-invalid', !!msg);
                if (errorEl) errorEl.textContent = String(msg || '');
            }

            (function initTheme() {
                var htmlNode = document.documentElement;
                function applyTheme(isDark) {
                    if (isDark) {
                        htmlNode.setAttribute('data-bs-theme', 'dark');
                        htmlNode.classList.add('app-skin-dark');
                    } else {
                        htmlNode.setAttribute('data-bs-theme', 'light');
                        htmlNode.classList.remove('app-skin-dark');
                    }
                    var btn = el('themeToggle');
                    var icon = el('themeToggleIcon');
                    var tooltip = el('themeToggleTooltip');
                    var label = isDark ? 'Switch to light mode' : 'Switch to dark mode';
                    var tooltipText = isDark ? 'Light mode' : 'Dark mode';
                    if (btn) {
                        btn.setAttribute('aria-label', label);
                    }
                    if (icon) {
                        icon.className = isDark ? 'feather-sun' : 'feather-moon';
                    }
                    if (tooltip) {
                        tooltip.textContent = tooltipText;
                    }
                }
                try {
                    var saved = localStorage.getItem('app-skin-dark');
                    applyTheme(saved === 'app-skin-dark');
                } catch (e) {
                    applyTheme(false);
                }
                document.addEventListener('click', function (e) {
                    var btn = e.target && e.target.closest ? e.target.closest('#themeToggle') : null;
                    if (!btn) return;
                    e.preventDefault();
                    var currentlyDark = htmlNode.classList.contains('app-skin-dark');
                    var nextDark = !currentlyDark;
                    try { localStorage.setItem('app-skin-dark', nextDark ? 'app-skin-dark' : 'app-skin-light'); } catch (e2) {}
                    applyTheme(nextDark);
                });
            })();

            function validateUsername() {
                var input = el('username');
                var err = el('usernameError');
                var value = String(input && input.value ? input.value : '').trim();
                if (!value) {
                    setError(input, err, 'Username is required.');
                    return false;
                }
                setError(input, err, '');
                return true;
            }

            function scorePassword(pw) {
                var s = String(pw || '');
                if (!s) return { score: 0, label: '—' };
                var score = 0;
                if (s.length >= 8) score++;
                if (/[a-z]/.test(s) && /[A-Z]/.test(s)) score++;
                if (/\d/.test(s)) score++;
                if (/[^a-zA-Z0-9]/.test(s)) score++;
                var label = score <= 1 ? 'Weak' : score === 2 ? 'Fair' : score === 3 ? 'Good' : 'Strong';
                return { score: score, label: label };
            }

            function updateStrength() {
                var pw = el('password');
                var bar = el('passwordStrengthBar');
                var label = el('passwordStrengthLabel');
                var res = scorePassword(pw && pw.value ? pw.value : '');
                var pct = res.score === 0 ? 0 : (res.score / 4) * 100;
                if (bar) {
                    bar.style.width = String(pct) + '%';
                    bar.style.backgroundColor = res.score <= 1 ? 'rgba(220,38,38,0.92)' : res.score === 2 ? 'rgba(245,158,11,0.95)' : res.score === 3 ? 'rgba(31,101,184,0.92)' : 'rgba(22,163,74,0.92)';
                }
                if (label) label.textContent = 'Strength: ' + res.label;
            }

            function validatePassword() {
                var input = el('password');
                var err = el('passwordError');
                var value = String(input && input.value ? input.value : '');
                if (!value) {
                    setError(input, err, 'Password is required.');
                    return false;
                }
                setError(input, err, '');
                return true;
            }

            function init() {
                var form = el('loginForm');
                var username = el('username');
                var password = el('password');
                var toggle = el('togglePassword');
                var remember = el('rememberMe');

                if (password) password.value = '';
                updateStrength();

                if (username) {
                    username.addEventListener('input', function () {
                        validateUsername();
                        if (remember && remember.checked) {
                            try { localStorage.setItem('ctrRememberUser', String(username.value || '')); } catch (e) {}
                        }
                    });
                    username.addEventListener('blur', validateUsername);
                }

                if (password) {
                    password.addEventListener('input', function () {
                        validatePassword();
                        updateStrength();
                    });
                    password.addEventListener('blur', function () {
                        validatePassword();
                        updateStrength();
                    });
                    password.addEventListener('keydown', function (e) {
                        var caps = el('capsLockHint');
                        if (!caps || !e || typeof e.getModifierState !== 'function') return;
                        caps.style.display = e.getModifierState('CapsLock') ? 'inline' : 'none';
                    });
                }

                if (toggle && password) {
                    var toggleLabel = el('togglePasswordLabel');
                    toggle.addEventListener('click', function () {
                        var isHidden = password.type === 'password';
                        password.type = isHidden ? 'text' : 'password';
                        toggle.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
                        toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
                        toggle.classList.toggle('is-visible', isHidden);
                        if (toggleLabel) toggleLabel.textContent = isHidden ? 'Hide' : 'Show';
                        try { password.focus(); } catch (e) {}
                    });
                }

                if (remember && username) {
                    remember.addEventListener('change', function () {
                        if (remember.checked) {
                            try { localStorage.setItem('ctrRememberUser', String(username.value || '')); } catch (e) {}
                        } else {
                            try { localStorage.removeItem('ctrRememberUser'); } catch (e) {}
                        }
                    });
                }

                if (username && (!username.value || !String(username.value).trim())) {
                    var remembered = '';
                    try { remembered = String(localStorage.getItem('ctrRememberUser') || ''); } catch (e) {}
                    if (remembered) username.value = remembered;
                }

                if (form) {
                    form.addEventListener('submit', function (e) {
                        setLive('');
                        var okU = validateUsername();
                        var okP = validatePassword();
                        updateStrength();
                        if (!okU || !okP) {
                            e.preventDefault();
                            setLive('Please fix the highlighted fields.');
                            var first = !okU ? username : password;
                            if (first && first.focus) {
                                try { first.focus(); } catch (err) {}
                            }
                            return;
                        }
                        var btn = el('loginSubmit');
                        if (btn) {
                            btn.disabled = true;
                            btn.textContent = 'Signing in...';
                        }
                    });
                }

                document.body.addEventListener('click', function (e) {
                    var t = e.target;
                    if (!t || !t.closest) return;
                    var b = t.closest('.ctr-social-btn');
                    if (!b) return;
                    var disabled = b.getAttribute('aria-disabled') === 'true';
                    if (disabled) {
                        e.preventDefault();
                        setSocialLive('Social login is not configured on this server.');
                        return;
                    }
                    setSocialLive('');
                });
            }

            var loginForm = document.getElementById('loginForm');
            var loadingOverlay = document.getElementById('loadingOverlay');
            if (loginForm) {
                loginForm.addEventListener('submit', function() {
                    if (loadingOverlay) loadingOverlay.style.display = 'flex';
                });
            }

            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
            else init();

            var loadingOverlay = document.getElementById('loadingOverlay');
            if (loadingOverlay) loadingOverlay.style.display = 'none';
        })();
    </script>
</body>

</html>
