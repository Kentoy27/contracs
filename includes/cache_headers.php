<?php
/**
 * Cache and cookie helpers for the ConTracS app.
 *
 * Provides:
 *  - ctr_cache_headers(): emit Cache-Control / Expires headers by profile
 *  - ctr_etag_from():   compute a strong ETag for an arbitrary payload
 *  - ctr_handle_conditional(): respond 304 if the client already has a fresh copy
 *  - ctr_remember_cookie(): persist a small UI preference in a cookie for snappier reloads
 */

if (!defined('CTR_CACHE_HEADERS_LOADED')) {
    define('CTR_CACHE_HEADERS_LOADED', true);

    if (!defined('CTR_BASE_PATH')) {
        $bp = trim((string)(getenv('CONTRACS_BASE_PATH') ?: ''));
        if ($bp === '') {
            // Auto-detect the install subfolder from this file's location
            // (…/includes/cache_headers.php) so the app works when served as
            // the web root (e.g. php -S localhost:8000) or from a subfolder
            // (e.g. http://localhost/contracs).
            $bp = str_replace('\\', '/', dirname(__DIR__));
            $docRoot = str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
            if ($docRoot !== '' && strpos($bp . '/', $docRoot . '/') === 0) {
                $bp = substr($bp, strlen($docRoot));
            } else {
                $bp = '';
            }
        }
        $bp = '/' . trim($bp, '/');
        if ($bp === '/') $bp = '';
        define('CTR_BASE_PATH', $bp);
    }

    function ctr_url(string $path = ''): string
    {
        $p = ltrim($path, '/');
        if (CTR_BASE_PATH === '') {
            return '/' . $p;
        }
        return CTR_BASE_PATH . '/' . $p;
    }

    function ctr_canonicalize(string $page = 'index'): void
    {
        if (headers_sent()) return;
        if (CTR_BASE_PATH === '') return;
        $reqPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        if ($reqPath === '') return;
        if ($reqPath === CTR_BASE_PATH || strpos($reqPath, CTR_BASE_PATH . '/') === 0) return;
        $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
        $target = ctr_url($page);
        if ($qs !== '') $target .= '?' . $qs;
        header('Location: ' . $target);
        exit;
    }

    /**
     * Emit HTTP cache headers.
     *
     * $profile options:
     *   - 'static'  : public, 1 year, immutable         (versioned assets)
     *   - 'short'   : public, $maxAge seconds + must-revalidate
     *   - 'private' : private, no-store                  (authenticated / dynamic HTML)
     *   - 'api'     : public, $maxAge seconds + must-revalidate (JSON / AJAX)
     *   - 'dynamic' : alias for 'api' with a sensible default of 30s
     */
    function ctr_cache_headers(string $profile = 'dynamic', int $maxAge = 0): void
    {
        if (headers_sent()) {
            return;
        }

        // Drop conflicting headers that some shared hosts send by default.
        header_remove('Pragma');
        header_remove('Expires');

        // Baseline security headers — safe to send on every response.
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: frame-ancestors 'self'");
        header('X-Permitted-Cross-Domain-Policies: none');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        switch ($profile) {
            case 'static':
                $seconds = max(60, $maxAge > 0 ? $maxAge : 31536000); // default 1 year
                header('Cache-Control: public, max-age=' . $seconds . ', immutable');
                header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
                break;

            case 'short':
            case 'api':
            case 'dynamic':
            default:
                $seconds = $maxAge > 0 ? $maxAge : 30;
                header('Cache-Control: public, max-age=' . $seconds . ', must-revalidate');
                header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
                break;

            case 'private':
            case 'no-store':
                header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
                header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
                header('Pragma: no-cache');
                break;
        }
    }

    /**
     * Build a strong ETag from a payload (array, string, or scalar).
     */
    function ctr_etag_from(mixed $payload): string
    {
        if (is_array($payload) || is_object($payload)) {
            $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $payload = (string)$payload;
        }
        return '"' . substr(hash('sha256', $payload), 0, 32) . '"';
    }

    /**
     * Compare the supplied ETag with the one expected for $payload and
     * short-circuit the response with HTTP 304 when they match.
     */
    function ctr_handle_conditional(string $etag, int $maxAge = 30): bool
    {
        if (headers_sent()) {
            return false;
        }
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=' . max(1, $maxAge) . ', must-revalidate');

        $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($inm !== '' && trim($inm) === $etag) {
            http_response_code(304);
            exit;
        }
        return true;
    }

    /**
     * Persist a small UI preference cookie for 1 year so reloading the
     * page does not re-trigger the same first-paint work.
     */
    function ctr_remember_cookie(string $name, string $value, int $days = 365): void
    {
        if (headers_sent()) {
            return;
        }
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name) ?? '';
        if ($name === '') {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('ctr_' . $name, $value, [
            'expires'  => time() + ($days * 24 * 60 * 60),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Read a previously stored preference cookie (returns '' if missing).
     */
    function ctr_recall_cookie(string $name): string
    {
        $name = 'ctr_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $name);
        return (string)($_COOKIE[$name] ?? '');
    }

    /**
     * Emit a "We use cookies" consent banner.
     *
     * The banner is rendered as part of the response body and the
     * accept/dismiss logic lives in the inline JS so the same response
     * works for first-time and returning visitors without invalidating
     * any ETag the page might have computed.
     */
    function ctr_cookie_consent_banner(): void
    {
        static $emitted = false;
        if ($emitted) {
            return;
        }
        $emitted = true;
        ?>
<div id="ctrCookieConsent" class="ctr-cookie-consent" role="dialog" aria-live="polite" aria-label="Cookie consent">
    <div class="ctr-cookie-consent__inner">
        <div class="ctr-cookie-consent__text">
            <i class="feather-cookie me-2" aria-hidden="true"></i>
            We use cookies to ensure you get the best experience on our site. By continuing, you agree to our use of cookies.
        </div>
        <div class="ctr-cookie-consent__actions">
            <button type="button" class="btn btn-sm btn-primary" id="ctrCookieAcceptBtn">Accept</button>
        </div>
    </div>
</div>
<style>
.ctr-cookie-consent {
    position: fixed;
    left: 50%;
    transform: translateX(-50%);
    bottom: 16px;
    z-index: 1080;
    background: #ffffff;
    color: #0f172a;
    border: 1px solid rgba(15, 23, 42, 0.1);
    border-radius: 12px;
    box-shadow: 0 14px 40px rgba(2, 6, 23, 0.18);
    width: calc(100% - 32px);
    max-width: 560px;
    padding: 10px 12px;
    display: none;
}
.ctr-cookie-consent.is-visible { display: block; }
html.app-skin-dark .ctr-cookie-consent,
:root[data-bs-theme="dark"] .ctr-cookie-consent {
    background: #111827;
    color: rgba(248, 250, 252, 0.92);
    border-color: rgba(148, 163, 184, 0.16);
}
.ctr-cookie-consent__inner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    justify-content: space-between;
}
.ctr-cookie-consent__text { font-size: 13px; line-height: 1.35; flex: 1 1 280px; }
.ctr-cookie-consent__actions { display: flex; gap: 8px; }
@media (max-width: 480px) {
    .ctr-cookie-consent { bottom: 8px; width: calc(100% - 16px); padding: 9px 10px; }
}
</style>
<script>
(function () {
    var COOKIE_NAME = 'ctr_accept_cookies';
    function hasConsent() {
        return document.cookie.split('; ').some(function (c) {
            return c.indexOf(COOKIE_NAME + '=1') === 0;
        });
    }
    function setConsent() {
        var d = new Date();
        d.setFullYear(d.getFullYear() + 1);
        document.cookie = COOKIE_NAME + '=1; expires=' + d.toUTCString() + '; path=/; samesite=Lax';
    }
    function show() {
        var el = document.getElementById('ctrCookieConsent');
        if (el) el.classList.add('is-visible');
    }
    function hide() {
        var el = document.getElementById('ctrCookieConsent');
        if (el) el.classList.remove('is-visible');
    }
    document.addEventListener('DOMContentLoaded', function () {
        if (!hasConsent()) {
            show();
        }
        var btn = document.getElementById('ctrCookieAcceptBtn');
        if (btn) {
            btn.addEventListener('click', function () {
                setConsent();
                hide();
            });
        }
    });
})();
</script>
        <?php
    }

    /**
     * Start a hardened PHP session.
     *
     *  - Cookie flags: HttpOnly, SameSite=Lax, Secure when served over HTTPS
     *  - Idle timeout (30 min) and absolute lifetime (12 h) for authenticated sessions
     *  - Periodic session-id regeneration to blunt session fixation
     *  - Initializes the per-session CSRF token
     */
    function ctr_session_start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $cookiePath = CTR_BASE_PATH === '' ? '/' : CTR_BASE_PATH . '/';

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $cookiePath,
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $authenticated = !empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

        if ($authenticated) {
            $idleSince = (int)($_SESSION['ctr_last_activity'] ?? 0);
            $startedAt = (int)($_SESSION['ctr_started_at'] ?? $now);
            $idleTimeout = 30 * 60;           // 30 minutes without activity
            $absoluteTimeout = 12 * 60 * 60;  // 12 hours total

            if (($idleSince > 0 && ($now - $idleSince) > $idleTimeout) || ($now - $startedAt) > $absoluteTimeout) {
                // Session expired: wipe it and start a fresh one for the login flow.
                $_SESSION = [];
                @session_destroy();
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_start();
                }
                $_SESSION['ctr_started_at'] = time();
            } else {
                $_SESSION['ctr_last_activity'] = $now;
                if (!isset($_SESSION['ctr_started_at'])) {
                    $_SESSION['ctr_started_at'] = $startedAt;
                }
                $lastRegen = (int)($_SESSION['ctr_regenerated_at'] ?? 0);
                if (($now - $lastRegen) > 30 * 60) {
                    session_regenerate_id(true);
                    $_SESSION['ctr_regenerated_at'] = $now;
                }
            }
        }

        if (empty($_SESSION['ctr_csrf_token']) || !is_string($_SESSION['ctr_csrf_token'])) {
            $_SESSION['ctr_csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    /**
     * Return (and lazily create) the per-session CSRF token.
     */
    function ctr_csrf_token(): string
    {
        if (empty($_SESSION['ctr_csrf_token']) || !is_string($_SESSION['ctr_csrf_token'])) {
            $_SESSION['ctr_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['ctr_csrf_token'];
    }

    /**
     * Timing-safe check of a CSRF token against the session token.
     * Accepts the X-CSRF-Token header, the ctr_csrf_token POST field,
     * or an explicit value.
     */
    function ctr_csrf_verify(?string $provided = null): bool
    {
        if ($provided === null) {
            $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['ctr_csrf_token'] ?? ''));
        }
        $expected = (string)($_SESSION['ctr_csrf_token'] ?? '');
        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }

    /**
     * Require a valid CSRF token; respond 403 (JSON for AJAX) and stop otherwise.
     */
    function ctr_csrf_check(): void
    {
        if (ctr_csrf_verify()) {
            return;
        }
        $isAjax = (isset($_GET['ajax']) && (string)($_GET['ajax'] ?? '') === '1')
               || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'fetch');
        http_response_code(403);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Invalid security token. Please refresh the page and try again.']);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo 'Invalid security token. Please go back, refresh the page, and try again.';
        }
        exit;
    }

    /**
     * Echo a hidden CSRF input for plain HTML forms.
     */
    function ctr_csrf_field(): void
    {
        echo '<input type="hidden" name="ctr_csrf_token" value="' . htmlspecialchars(ctr_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Return a <meta> tag carrying the CSRF token for the JS layer.
     */
    function ctr_csrf_meta(): string
    {
        return '<meta name="csrf-token" content="' . htmlspecialchars(ctr_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}
