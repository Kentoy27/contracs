<?php
require __DIR__ . '/includes/cache_headers.php';

ctr_session_start();

function theme_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function theme_normalize_hex(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if ($raw[0] !== '#') {
        $raw = '#' . $raw;
    }
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $raw) !== 1) {
        return '';
    }
    return strtoupper($raw);
}

function theme_set_cookie(string $color): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('ctr_theme_base', $color, [
        'expires' => time() + (365 * 24 * 60 * 60),
        'path' => '/',
        'secure' => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

if (isset($_GET['ajax']) && (string)($_GET['ajax'] ?? '') === '1') {
    ctr_cache_headers('api', 60);
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

    if ($action === 'get') {
        $color = (string)($_COOKIE['ctr_theme_base'] ?? ($_SESSION['ctr_theme_base'] ?? ''));
        $color = theme_normalize_hex($color);
        $payload = ['ok' => true, 'color' => $color !== '' ? $color : null];
        $etag = ctr_etag_from(['c' => $color, 's' => (int)($_SESSION['user_id'] ?? 0)]);
        ctr_handle_conditional($etag, 60);
        theme_json($payload);
    }

    if ($action === 'set') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            theme_json(['ok' => false, 'message' => 'Invalid request.'], 405);
        }
        $raw = (string)($_POST['color'] ?? '');
        $color = theme_normalize_hex($raw);
        if ($color === '') {
            theme_json(['ok' => false, 'message' => 'Invalid color.'], 422);
        }
        $_SESSION['ctr_theme_base'] = $color;
        theme_set_cookie($color);
        theme_json(['ok' => true, 'color' => $color]);
    }

    theme_json(['ok' => false, 'message' => 'Unknown action.'], 400);
}

http_response_code(404);
echo 'Not found';
