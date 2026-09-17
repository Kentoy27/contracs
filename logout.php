<?php
require __DIR__ . '/includes/cache_headers.php';
ctr_cache_headers('private');

ctr_session_start();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (bool)($params['secure'] ?? false),
        'httponly' => (bool)($params['httponly'] ?? false),
        'samesite' => 'Lax',
    ]);
}

$cookiePath = ctr_url('');
setcookie('remember_token', '', time() - 42000, $cookiePath);
setcookie('remember_user', '', time() - 42000, $cookiePath);
if ($cookiePath !== '/') {
    setcookie('remember_token', '', time() - 42000, '/');
    setcookie('remember_user', '', time() - 42000, '/');
}

session_destroy();

header('Location: ' . ctr_url('login') . '?logout=1');
exit;
?>
