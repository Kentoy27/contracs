<?php
/**
 * Router for PHP's built-in development server:
 *
 *   php -S localhost:8000 router.php
 *
 * Mirrors the production .htaccess rules closely enough for local use:
 *   - Serves real files (php, css, js, images, uploads) directly.
 *   - Maps extensionless URLs (e.g. /login, /attendance) to *.php.
 *   - Blocks config/includes/tmp/logs and sensitive uploads folders.
 *   - Requires the AJAX header for /ajax/*.php, like the .htaccess rule.
 *   - Falls back to 404.php for anything that doesn't match a page.
 */

$uri = urldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$path = rtrim($uri, '/');
if ($path === '') {
    $path = '/';
}

$root = __DIR__;

// Fully blocked folders (mirrors "RewriteRule ^(config|includes|tmp|logs)/ - [F,L]").
$blockedPrefixes = ['/config', '/includes', '/tmp', '/logs', '/uploads/access', '/uploads/faces'];
foreach ($blockedPrefixes as $prefix) {
    if ($path === $prefix || strpos($path . '/', $prefix . '/') === 0) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
}

// Never expose dotfiles.
if (preg_match('#/\.ht|/\.env|/\.git#', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Directory browsing forbidden (mirrors the "-d" rule): bare dir paths 403.
if ($path !== '/' && is_dir($root . $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// /ajax/*.php needs the AJAX header, same as the .htaccess guard.
if (preg_match('#^/ajax/.+\.php$#', $path)
    && strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') !== 0) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

$candidate = $root . $path;

// Backward compatibility: when served as the web root there is no /contracs
// subfolder, so redirect old bookmarks (/contracs/login, ...) to the root.
// (When the app itself lives in a /contracs folder, that directory exists
// and this branch is skipped.)
if (preg_match('#^/contracs(/.*)?$#', $path, $m) && !is_dir($root . '/contracs')) {
    $rest = $m[1] ?? '';
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    $to = $rest === '' || $rest === '/' ? '/index' : $rest;
    if ($qs !== '') $to .= '?' . $qs;
    header('Location: ' . $to, true, 301);
    return true;
}

// Allow the service worker to control the root scope (mirrors the
// .htaccess FilesMatch on sw.js). Serve it here so the extra header
// survives — the built-in server drops headers set before `return false`.
if ($path === '/assets/sw.js' && is_file($candidate)) {
    header('Content-Type: application/javascript');
    header('Service-Worker-Allowed: /');
    readfile($candidate);
    return true;
}

// Exact file hit: let the built-in server serve/run it natively.
if ($path !== '/' && is_file($candidate)) {
    return false;
}

// Missing file with an extension -> custom 404 page.
if (pathinfo($candidate, PATHINFO_EXTENSION) !== '') {
    http_response_code(404);
    require $root . '/404.php';
    return true;
}

// Extensionless request (e.g. /login, /index): map to the .php page.
$phpPage = $path === '/' ? $root . '/index.php' : $candidate . '.php';
if (is_file($phpPage)) {
    // Make SCRIPT_NAME/SCRIPT_FILENAME look like Apache after an internal
    // rewrite (docroot-relative path to the real .php file) so helpers such
    // as ctr_canonicalize() and home.php's <base> behave identically.
    $docRoot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? $root)), '/');
    $scriptName = $docRoot !== '' && strpos(str_replace('\\', '/', $phpPage), $docRoot . '/') === 0
        ? substr(str_replace('\\', '/', $phpPage), strlen($docRoot))
        : '/' . basename($phpPage);
    $_SERVER['SCRIPT_NAME'] = $scriptName;
    $_SERVER['SCRIPT_FILENAME'] = $phpPage;
    chdir(dirname($phpPage));
    require $phpPage;
    return true;
}

http_response_code(404);
require $root . '/404.php';
return true;
