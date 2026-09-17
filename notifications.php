<?php
require __DIR__ . '/includes/cache_headers.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/config/paymongo.php';

ctr_session_start();

function table_exists_safe(PDO $pdo, string $tableName): bool
{
    $t = trim($tableName);
    if ($t === '') return true;
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1");
        $stmt->execute([':t' => $t]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return true;
    }
}

function ensure_notifications_schema_safe(PDO $pdo): void
{
    try {
        if (!table_exists_safe($pdo, 'notifications')) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS notifications (
                    id INT NOT NULL AUTO_INCREMENT,
                    user_id INT NULL,
                    title VARCHAR(255) NOT NULL,
                    body TEXT NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    INDEX idx_notifications_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            return;
        }
        // Broadcast notifications are stored with user_id = NULL so every user
        // sees them. Older schemas created user_id as NOT NULL, which makes the
        // superadmin send (INSERT ... VALUES (NULL, ...)) fatal. Migrate it to
        // nullable so the intended broadcast behavior works everywhere.
        $cols = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'user_id'")->fetch(PDO::FETCH_ASSOC);
        $nullable = isset($cols['Null']) ? strtoupper((string)$cols['Null']) : '';
        if ($cols && $nullable === 'NO') {
            $pdo->exec("ALTER TABLE notifications MODIFY user_id INT NULL");
        }
    } catch (Throwable $e) {
    }
}

function ensure_admin_plan_extension_requests_schema_safe(PDO $pdo): void
{
    try {
        if (table_exists_safe($pdo, 'admin_plan_extension_requests')) {
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM admin_plan_extension_requests LIKE 'payment_proof'")->fetch();
                if (!$cols) {
                    $pdo->exec("ALTER TABLE admin_plan_extension_requests ADD COLUMN payment_proof VARCHAR(500) NULL AFTER note");
                }
            } catch (Throwable $e) {}
            return;
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS admin_plan_extension_requests (
                id INT NOT NULL AUTO_INCREMENT,
                admin_id INT NOT NULL,
                requested_months INT NOT NULL,
                note VARCHAR(255) NULL,
                payment_proof VARCHAR(500) NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP NULL DEFAULT NULL,
                resolved_by INT NULL DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_aper_status_updated (status, updated_at),
                INDEX idx_aper_admin (admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
    }
}

function ensure_admin_home_links_schema_safe(PDO $pdo): void
{
    try {
        if (table_exists_safe($pdo, 'admin_home_links')) return;
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
    } catch (Throwable $e) {
    }
}

function revoke_admin_home_links(PDO $pdo, int $adminId): void
{
    ensure_admin_home_links_schema_safe($pdo);
    $stmt = $pdo->prepare("UPDATE admin_home_links SET revoked_at = NOW() WHERE admin_id = :id AND revoked_at IS NULL");
    $stmt->execute([':id' => $adminId]);
}

function issue_admin_home_link(PDO $pdo, int $adminId, string $planExpiresAt): array
{
    ensure_admin_home_links_schema_safe($pdo);

    // Enforce maximum 5 active links per admin.
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_home_links WHERE admin_id = :aid AND revoked_at IS NULL");
    $countStmt->execute([':aid' => $adminId]);
    $activeCount = (int)$countStmt->fetchColumn();
    if ($activeCount >= 5) {
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

function ensure_admin_plan_payments_schema_safe(PDO $pdo): void
{
    try {
        if (table_exists_safe($pdo, 'admin_plan_payments')) return;
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS admin_plan_payments (
                id INT NOT NULL AUTO_INCREMENT,
                admin_id INT NOT NULL,
                months INT NOT NULL,
                amount_php INT NOT NULL,
                note VARCHAR(255) NULL,
                status ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
                provider VARCHAR(32) NOT NULL DEFAULT 'mock',
                provider_ref VARCHAR(128) NULL,
                request_id INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                paid_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_app_admin_status_created (admin_id, status, created_at),
                INDEX idx_app_request (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
    }
    // Older installs may predate the provider_ref column (PayMongo session id).
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM admin_plan_payments")->fetchAll(PDO::FETCH_ASSOC);
        $existing = [];
        foreach ($cols as $col) {
            $existing[strtolower((string)($col['Field'] ?? ''))] = true;
        }
        if (!isset($existing['provider_ref'])) {
            $pdo->exec("ALTER TABLE admin_plan_payments ADD COLUMN provider_ref VARCHAR(128) NULL AFTER provider");
        }
    } catch (Throwable $e) {
    }
}

function plan_extension_amount_php(int $months): int
{
    $m = (int)$months;
    if ($m < 1) $m = 1;
    if ($m > 60) $m = 60;
    $amount = 149 + (($m - 1) * 150);
    if ($amount < 0) $amount = 0;
    return (int)$amount;
}

function plan_extension_fee_breakdown(int $months): array
{
    $m = (int)$months;
    if ($m < 1) $m = 1;
    if ($m > 60) $m = 60;

    $amount = plan_extension_amount_php($m);
    $rent = $m * 100;
    $maintenance = $amount - $rent;
    if ($maintenance < 0) $maintenance = 0;

    return [
        'months' => $m,
        'amount_php' => $amount,
        'rent_php' => $rent,
        'maintenance_php' => $maintenance,
        'breakdown_text' => 'System hosting: P' . number_format($rent) . ' | System maintenance: P' . number_format($maintenance),
        'fee_text' => 'This fee covers the system hosting and system maintenance for ' . $m . ' month' . ($m === 1 ? '' : 's') . '.',
    ];
}

function maybe_enqueue_admin_plan_expiry_notification(PDO $pdo, int $adminId): void
{
    if ($adminId <= 0) return;
    try {
        $stmt = $pdo->prepare("SELECT is_active, plan_expires_at FROM users WHERE id = :id AND role = 'admin' LIMIT 1");
        $stmt->execute([':id' => $adminId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) return;
        if ((int)($u['is_active'] ?? 0) !== 1) return;
        $expRaw = $u['plan_expires_at'] ?? null;
        $expStr = is_string($expRaw) ? trim($expRaw) : '';
        if ($expStr === '') return;

        $tz = new DateTimeZone('Asia/Manila');
        $now = new DateTimeImmutable('now', $tz);
        $exp = new DateTimeImmutable($expStr, $tz);
        if ($exp <= $now) return;
        if ($exp > $now->modify('+3 day')) return;

        ensure_notifications_schema_safe($pdo);
        $title = 'Plan Expiring Soon';
        $stmt = $pdo->prepare("SELECT id FROM notifications WHERE user_id = :uid AND title = :t AND created_at >= (NOW() - INTERVAL 3 DAY) ORDER BY id DESC LIMIT 1");
        $stmt->execute([':uid' => $adminId, ':t' => $title]);
        if ($stmt->fetchColumn()) return;

        $expDisplay = $exp->format('F j, Y \a\t g:i A');
        $body = 'Your plan will expire within 3 days (expires on ' . $expDisplay . '). Please request a plan extension.';
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, body, is_read) VALUES (:uid, :title, :body, 0)");
        $stmt->execute([':uid' => $adminId, ':title' => $title, ':body' => $body]);
    } catch (Throwable $e) {
    }
}

function html_response(string $html, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

/**
 * Create a PayMongo Hosted Checkout session for a plan-extension payment.
 * Amounts are in PHP; PayMongo expects centavos (x100).
 * Returns ['id' => cs_..., 'checkout_url' => https://checkout.paymongo.com/...].
 */
function paymongo_create_checkout_session(int $amountPhp, string $referenceNumber, string $successUrl, string $cancelUrl, string $description): array
{
    $secret = paymongo_secret_key();
    if ($secret === '' || strpos($secret, 'sk_') !== 0) {
        throw new RuntimeException('Payment gateway is not configured. Missing PayMongo secret key.');
    }

    $payload = [
        'data' => [
            'attributes' => [
                'line_items' => [[
                    'name' => mb_substr($description, 0, 120),
                    'amount' => $amountPhp * 100,
                    'currency' => 'PHP',
                    'quantity' => 1,
                ]],
                'payment_method_types' => ['gcash', 'qrph'],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'reference_number' => $referenceNumber,
                'description' => mb_substr($description, 0, 255),
                'metadata' => ['reference_number' => $referenceNumber],
            ],
        ],
    ];

    $res = paymongo_api_request('POST', 'https://api.paymongo.com/v2/checkout_sessions', $payload);
    if ($res['code'] < 200 || $res['code'] >= 300 || !is_array($res['body'])) {
        $detail = '';
        if (isset($res['body']['errors'][0]['detail'])) {
            $detail = ' ' . (string)$res['body']['errors'][0]['detail'];
        }
        throw new RuntimeException('Payment gateway rejected the request.' . $detail);
    }
    $id = (string)($res['body']['data']['id'] ?? '');
    $url = (string)($res['body']['data']['attributes']['checkout_url'] ?? '');
    if ($id === '' || $url === '') {
        throw new RuntimeException('Payment gateway returned an unexpected response.');
    }
    return ['id' => $id, 'checkout_url' => $url];
}

/**
 * Retrieve a PayMongo Checkout Session (secret-key scope). Returns the raw
 * attributes array, including payments[] when the session has payments.
 */
function paymongo_get_checkout_session(string $sessionId): array
{
    $secret = paymongo_secret_key();
    if ($secret === '' || strpos($secret, 'sk_') !== 0) {
        throw new RuntimeException('Payment gateway is not configured.');
    }
    // NOTE: creation lives on /v2 but session retrieval is served on /v1.
    $res = paymongo_api_request('GET', 'https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($sessionId));
    if ($res['code'] < 200 || $res['code'] >= 300 || !is_array($res['body'])) {
        throw new RuntimeException('Could not verify the payment. (HTTP ' . $res['code'] . ')');
    }
    if (!isset($res['body']['data']['attributes']) || !is_array($res['body']['data']['attributes'])) {
        throw new RuntimeException('Payment gateway returned an unexpected response.');
    }
    return $res['body']['data']['attributes'];
}

/**
 * Mark an admin_plan_payments row as paid (idempotent) and make sure the
 * corresponding plan-extension request row exists.
 */
function paymongo_finalize_payment(PDO $pdo, int $paymentId, int $adminId): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, admin_id, status FROM admin_plan_payments WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $paymentId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            $pdo->rollBack();
            throw new RuntimeException('Payment not found.');
        }
        if ((int)($p['admin_id'] ?? 0) !== $adminId) {
            $pdo->rollBack();
            throw new RuntimeException('Forbidden.');
        }
        if ((string)($p['status'] ?? '') !== 'paid') {
            $u = $pdo->prepare("UPDATE admin_plan_payments SET status = 'paid', paid_at = NOW() WHERE id = :id");
            $u->execute([':id' => $paymentId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    try {
        ensure_request_for_paid_payment($pdo, $paymentId, $adminId);
    } catch (Throwable $e) {
    }
    // Verified PayMongo payment => extend the plan automatically.
    try {
        paymongo_auto_extend_after_payment($pdo, $paymentId, $adminId);
    } catch (Throwable $e) {
    }
}

function ensure_request_for_paid_payment(PDO $pdo, int $paymentId, int $adminId): int
{
    ensure_admin_plan_extension_requests_schema_safe($pdo);
    ensure_admin_plan_payments_schema_safe($pdo);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, admin_id, months, note, status, request_id FROM admin_plan_payments WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $paymentId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            $pdo->rollBack();
            throw new RuntimeException('Payment not found.');
        }
        if ((int)($p['admin_id'] ?? 0) !== $adminId) {
            $pdo->rollBack();
            throw new RuntimeException('Forbidden.');
        }
        if ((string)($p['status'] ?? '') !== 'paid') {
            $pdo->rollBack();
            throw new RuntimeException('Payment is not paid yet.');
        }
        $existingRequestId = (int)($p['request_id'] ?? 0);
        if ($existingRequestId > 0) {
            $pdo->commit();
            return $existingRequestId;
        }

        $months = (int)($p['months'] ?? 1);
        if ($months < 1) $months = 1;
        if ($months > 60) $months = 60;
        $note = trim((string)($p['note'] ?? ''));
        if (strlen($note) > 255) $note = substr($note, 0, 255);

        // Always create a dedicated request for THIS payment. Reusing a
        // pending request caused two paid months to collapse into one when
        // payments landed before the earlier request was resolved.
        $i = $pdo->prepare("INSERT INTO admin_plan_extension_requests (admin_id, requested_months, note, status) VALUES (:aid, :m, :note, 'pending')");
        $i->execute([':aid' => $adminId, ':m' => $months, ':note' => ($note !== '' ? $note : null)]);
        $requestId = (int)$pdo->lastInsertId();

        $u2 = $pdo->prepare("UPDATE admin_plan_payments SET request_id = :rid WHERE id = :pid");
        $u2->execute([':rid' => $requestId, ':pid' => $paymentId]);

        $pdo->commit();
        return $requestId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Core plan-extension approval, shared by the Superadmin action and the
 * automatic post-payment path. Extends the admin's plan from the request's
 * requested_months (from the current expiry when still active, otherwise
 * from now), issues a fresh home link, marks the request approved and
 * notifies the admin. Idempotent: already-resolved requests are a no-op.
 * Returns ['outcome' => 'ok'|'not_found'|'already_resolved'|'admin_not_found', ...].
 */
function approve_plan_extension_request_txn(PDO $pdo, int $requestId, ?int $resolvedBy, bool $isAuto = false): array
{
    ensure_admin_plan_extension_requests_schema_safe($pdo);
    ensure_admin_home_links_schema_safe($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, admin_id, requested_months, status FROM admin_plan_extension_requests WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $requestId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            $pdo->rollBack();
            return ['outcome' => 'not_found'];
        }
        if ((string)($r['status'] ?? '') !== 'pending') {
            $pdo->rollBack();
            return ['outcome' => 'already_resolved'];
        }

        $adminId = (int)($r['admin_id'] ?? 0);
        $reqMonths = (int)($r['requested_months'] ?? 0);
        if ($reqMonths < 1) $reqMonths = 1;
        if ($reqMonths > 60) $reqMonths = 60;

        $stmt = $pdo->prepare("SELECT id, role, is_active, plan_months, plan_expires_at FROM users WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $adminId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u || (string)($u['role'] ?? '') !== 'admin') {
            $pdo->rollBack();
            return ['outcome' => 'admin_not_found'];
        }

        $tz = new DateTimeZone('Asia/Manila');
        $now = new DateTimeImmutable('now', $tz);
        $oldMonths = (int)($u['plan_months'] ?? 0);
        if ($oldMonths < 0) $oldMonths = 0;
        if ($oldMonths > 60) $oldMonths = 60;
        $oldExpStr = is_string($u['plan_expires_at'] ?? null) ? trim((string)$u['plan_expires_at']) : '';

        $hasFuturePlan = false;
        $oldExp = null;
        if ($oldExpStr !== '' && $oldMonths > 0) {
            try {
                $oldExp = new DateTimeImmutable($oldExpStr, $tz);
                $hasFuturePlan = $oldExp > $now;
            } catch (Throwable $e) {
                $hasFuturePlan = false;
                $oldExp = null;
            }
        }

        if ($hasFuturePlan && $oldExp) {
            $newMonths = $oldMonths + $reqMonths;
            if ($newMonths > 60) $newMonths = 60;
            $newExp = $oldExp->modify('+' . $reqMonths . ' month');
        } else {
            $newMonths = $reqMonths;
            $newExp = $now->modify('+' . $reqMonths . ' month');
        }

        $newExpStr = $newExp->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("UPDATE users SET is_active = 1, plan_months = :m, plan_expires_at = :e, is_free_trial = 0 WHERE id = :id");
        $stmt->execute([':m' => $newMonths, ':e' => $newExpStr, ':id' => $adminId]);
        issue_admin_home_link($pdo, $adminId, $newExpStr);
        $stmt = $pdo->prepare("UPDATE admin_plan_extension_requests SET status = 'approved', resolved_at = NOW(), resolved_by = :by WHERE id = :id");
        $stmt->execute([':by' => $resolvedBy, ':id' => $requestId]);
        ensure_notifications_schema_safe($pdo);
        $newExpDisplay = date('F j, Y \\a\\t g:i A', strtotime($newExpStr));
        if ($isAuto) {
            $notifBody = 'Your payment was confirmed and your plan has been automatically extended for ' . $reqMonths . ' month' . ($reqMonths === 1 ? '' : 's') . '. Expires on ' . $newExpDisplay . '.';
        } else {
            $notifBody = 'Your plan extension request for ' . $reqMonths . ' month' . ($reqMonths === 1 ? '' : 's') . ' has been approved. Expires on ' . $newExpDisplay . '.';
        }
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, body, is_read) VALUES (:uid, :title, :body, 0)");
        $stmt->execute([':uid' => $adminId, ':title' => 'Plan Extension Approved', ':body' => $notifBody]);
        $pdo->commit();
        return ['outcome' => 'ok', 'plan_months' => $newMonths, 'plan_expires_at' => $newExpStr];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Auto-extend: immediately approve the plan-extension request linked to a
 * verified PayMongo payment (no manual Superadmin step). Failures are
 * non-fatal; the request simply stays pending for manual approval.
 */
function paymongo_auto_extend_after_payment(PDO $pdo, int $paymentId, int $adminId): void
{
    $stmt = $pdo->prepare("SELECT request_id FROM admin_plan_payments WHERE id = :id AND admin_id = :aid LIMIT 1");
    $stmt->execute([':id' => $paymentId, ':aid' => $adminId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) return;
    $rid = (int)($p['request_id'] ?? 0);
    if ($rid <= 0) return;
    approve_plan_extension_request_txn($pdo, $rid, null, true);
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    json_response(['ok' => false, 'message' => 'Unauthorized'], 401);
}

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');
$sessionRole = (string)($_SESSION['role'] ?? '');
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');
$reqMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Read-only endpoints can ride a short public cache so the polling
// client (topbar refresh every ~2.5s) avoids hammering the server.
$readOnlyActions = [
    'list', 'list_plan_extension_requests', 'get_plan_extension_request',
    'get_plan_extension_price', 'get_plan_extension_payment_status',
    'list_plan_payment_records', 'get_plan_payment_record',
];
if ($reqMethod === 'GET' && in_array($action, $readOnlyActions, true)) {
    ctr_cache_headers('api', 10);
}

$isAdmin = $sessionRole === 'admin';
if ($isAdmin) {
    $ok = false;
    $allowedWithoutActivePlan = [
        'request_plan_extension',
        'get_plan_extension_price',
        'create_plan_extension_payment',
        'get_plan_extension_payment_status',
        'mock_gcash_checkout',
        'mock_gcash_pay',
        'paymongo_return',
    ];
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
                $ok = $isActive && $planActive;
                $_SESSION['admin_needs_plan_request'] = !$ok;
            }
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    if (!$ok && !in_array($action, $allowedWithoutActivePlan, true)) {
        json_response(['ok' => false, 'message' => 'Your plan is not active. Please contact the administrator.'], 403);
    }
}

// CSRF: this endpoint is session-authenticated only (no kiosk mode), so
// every state-changing POST must carry a valid token.
if ($reqMethod === 'POST') {
    ctr_csrf_check();
}

if ($action === 'get_plan_extension_request') {
    if ($sessionRole !== 'superadmin') {
        json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
    }
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'message' => 'Invalid request.'], 400);
    }
    ensure_admin_plan_extension_requests_schema_safe($pdo);
    try {
        $stmt = $pdo->prepare(
            "SELECT r.id, r.admin_id, r.requested_months, r.note, r.payment_proof, r.status, r.created_at, r.updated_at,
                    u.name, u.id_number, u.plan_expires_at, u.plan_months, u.is_active
             FROM admin_plan_extension_requests r
             JOIN users u ON u.id = r.admin_id
             WHERE r.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Not found.'], 404);
        }

        $tz = new DateTimeZone('Asia/Manila');
        $now = new DateTimeImmutable('now', $tz);
        $reqMonths = (int)($row['requested_months'] ?? 0);
        if ($reqMonths < 1) $reqMonths = 1;
        if ($reqMonths > 60) $reqMonths = 60;
        $oldMonths = (int)($row['plan_months'] ?? 0);
        if ($oldMonths < 0) $oldMonths = 0;
        if ($oldMonths > 60) $oldMonths = 60;
        $oldExpStr = is_string($row['plan_expires_at'] ?? null) ? trim((string)$row['plan_expires_at']) : '';
        $hasFuturePlan = false;
        $oldExp = null;
        if ($oldExpStr !== '' && $oldMonths > 0) {
            try {
                $oldExp = new DateTimeImmutable($oldExpStr, $tz);
                $hasFuturePlan = $oldExp > $now;
            } catch (Throwable $e) {
                $hasFuturePlan = false;
                $oldExp = null;
            }
        }
        if ($hasFuturePlan && $oldExp) {
            $suggestedMonths = $oldMonths + $reqMonths;
            if ($suggestedMonths > 60) $suggestedMonths = 60;
            $suggestedExp = $oldExp->modify('+' . $reqMonths . ' month');
        } else {
            $suggestedMonths = $reqMonths;
            $suggestedExp = $now->modify('+' . $reqMonths . ' month');
        }
        $suggestedExpStr = $suggestedExp->format('Y-m-d H:i:s');

        $request = [
            'id' => (int)($row['id'] ?? 0),
            'admin_id' => (int)($row['admin_id'] ?? 0),
            'requested_months' => (int)($row['requested_months'] ?? 0),
            'note' => (string)($row['note'] ?? ''),
            'payment_proof' => (string)($row['payment_proof'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'admin_name' => (string)($row['name'] ?? ''),
            'admin_id_number' => (string)($row['id_number'] ?? ''),
            'current_plan_expires_at' => is_string($row['plan_expires_at'] ?? null) ? trim((string)$row['plan_expires_at']) : '',
            'current_plan_months' => (int)($row['plan_months'] ?? 0),
            'admin_is_active' => (int)($row['is_active'] ?? 0) === 1,
            'suggested_plan_months' => $suggestedMonths,
            'suggested_new_expires_at' => $suggestedExpStr,
        ];
        $etag = ctr_etag_from(['b' => floor(time() / 30), 'id' => $id, 'st' => (string)($row['status'] ?? ''), 'upd' => (string)($row['updated_at'] ?? '')]);
        ctr_handle_conditional($etag, 10);
        json_response(['ok' => true, 'request' => $request]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => 'Failed to load request.'], 500);
    }
}

if ($action === 'approve_plan_extension_request') {
    if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
    if ($sessionRole !== 'superadmin') {
        json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
    }
    $rid = (int)($_POST['request_id'] ?? 0);
    if ($rid <= 0) {
        json_response(['ok' => false, 'message' => 'Invalid request.'], 400);
    }
    try {
        $res = approve_plan_extension_request_txn($pdo, $rid, $sessionUserId > 0 ? $sessionUserId : null);
        if (($res['outcome'] ?? '') === 'not_found') {
            json_response(['ok' => false, 'message' => 'Not found.'], 404);
        }
        if (($res['outcome'] ?? '') === 'already_resolved') {
            json_response(['ok' => false, 'message' => 'Request is already resolved.'], 400);
        }
        if (($res['outcome'] ?? '') === 'admin_not_found') {
            json_response(['ok' => false, 'message' => 'Target admin not found.'], 404);
        }
        json_response([
            'ok' => true,
            'plan_months' => (int)($res['plan_months'] ?? 0),
            'plan_expires_at' => (string)($res['plan_expires_at'] ?? ''),
        ]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => 'Failed to approve request.'], 500);
    }
}

if ($action === 'reject_plan_extension_request') {
    if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
    if ($sessionRole !== 'superadmin') {
        json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
    }
    $rid = (int)($_POST['request_id'] ?? 0);
    if ($rid <= 0) {
        json_response(['ok' => false, 'message' => 'Invalid request.'], 400);
    }
    ensure_admin_plan_extension_requests_schema_safe($pdo);
    try {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id, admin_id, requested_months, status FROM admin_plan_extension_requests WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $rid]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                $pdo->rollBack();
                json_response(['ok' => false, 'message' => 'Not found.'], 404);
            }
            if ((string)($r['status'] ?? '') !== 'pending') {
                $pdo->rollBack();
                json_response(['ok' => false, 'message' => 'Request is already resolved.'], 400);
            }
            $rejectAdminId = (int)($r['admin_id'] ?? 0);
            $rejectMonths = (int)($r['requested_months'] ?? 0);
            if ($rejectMonths < 1) $rejectMonths = 1;
            $stmt = $pdo->prepare("UPDATE admin_plan_extension_requests SET status = 'rejected', resolved_at = NOW(), resolved_by = :by WHERE id = :id");
            $stmt->execute([':by' => $sessionUserId > 0 ? $sessionUserId : null, ':id' => $rid]);
            if ($rejectAdminId > 0) {
                ensure_notifications_schema_safe($pdo);
                $notifTitle = 'Plan Extension Declined';
                $notifBody = 'Your plan extension request for ' . $rejectMonths . ' month' . ($rejectMonths === 1 ? '' : 's') . ' has been declined.';
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, body, is_read) VALUES (:uid, :title, :body, 0)");
                $stmt->execute([':uid' => $rejectAdminId, ':title' => $notifTitle, ':body' => $notifBody]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_response(['ok' => false, 'message' => 'Failed to reject request.'], 500);
        }
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['ok' => false, 'message' => 'Failed to reject request.'], 500);
    }
}

if ($action === 'get_plan_extension_price') {
    if ($sessionRole !== 'admin') {
        json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
    }
    if ($sessionUserId <= 0) {
        json_response(['ok' => false, 'message' => 'Invalid session.'], 400);
    }
    $months = (int)($_GET['months'] ?? 1);
    if ($months < 1) $months = 1;
    if ($months > 60) $months = 60;
    $pricing = plan_extension_fee_breakdown($months);
    json_response(['ok' => true] + $pricing);
}

if ($action === 'create_plan_extension_payment') {
    if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
    if ($sessionRole !== 'admin') {
        json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
    }
    if ($sessionUserId <= 0) {
        json_response(['ok' => false, 'message' => 'Invalid session.'], 400);
    }
    $months = (int)($_POST['months'] ?? 1);
    if ($months < 1) $months = 1;
    if ($months > 60) $months = 60;
    $note = trim((string)($_POST['note'] ?? ''));
    if (strlen($note) > 255) $note = substr($note, 0, 255);
    $pricing = plan_extension_fee_breakdown($months);
    $amount = (int)$pricing['amount_php'];
    $provider = strtolower(trim((string)(getenv('CONTRACS_GCASH_PROVIDER') ?: 'paymongo')));
    if ($provider === '') $provider = 'paymongo';

    ensure_admin_plan_payments_schema_safe($pdo);
    try {
        $stmt = $pdo->prepare("INSERT INTO admin_plan_payments (admin_id, months, amount_php, note, status, provider) VALUES (:aid, :m, :a, :n, 'pending', :p)");
        $stmt->execute([
            ':aid' => $sessionUserId,
            ':m' => $months,
            ':a' => $amount,
            ':n' => ($note !== '' ? $note : null),
            ':p' => $provider,
        ]);
        $pid = (int)$pdo->lastInsertId();

        $checkoutUrl = '';
        if ($provider === 'paymongo') {
            $base = paymongo_app_base_url() . (CTR_BASE_PATH === '' ? '' : CTR_BASE_PATH);
            $successUrl = $base . '/notifications.php?action=paymongo_return&payment_id=' . $pid;
            $cancelUrl = $base . '/index.php';
            $description = 'ConTracS plan extension - ' . $months . ' month' . ($months === 1 ? '' : 's');
            try {
                $cs = paymongo_create_checkout_session($amount, 'PLAN-' . $pid, $successUrl, $cancelUrl, $description);
                $u = $pdo->prepare("UPDATE admin_plan_payments SET provider_ref = :ref WHERE id = :id");
                $u->execute([':ref' => $cs['id'], ':id' => $pid]);
                $checkoutUrl = $cs['checkout_url'];
            } catch (Throwable $e) {
                json_response(['ok' => false, 'message' => $e->getMessage()], 502);
            }
        }
        if ($checkoutUrl === '') {
            $checkoutUrl = 'notifications.php?action=mock_gcash_checkout&payment_id=' . urlencode((string)$pid);
        }
        json_response([
            'ok' => true,
            'payment' => [
                'id' => $pid,
                'months' => $months,
                'amount_php' => $amount,
                'rent_php' => (int)$pricing['rent_php'],
                'maintenance_php' => (int)$pricing['maintenance_php'],
                'breakdown_text' => (string)$pricing['breakdown_text'],
                'fee_text' => (string)$pricing['fee_text'],
                'provider' => $provider,
                'checkout_url' => $checkoutUrl,
            ],
        ]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => 'Failed to create payment.'], 500);
    }
}

/**
 * PayMongo redirects the customer's browser here after the hosted checkout
 * (success_url). Verify the session server-side, finalize when paid, then
 * bounce back to the dashboard.
 */
if ($action === 'paymongo_return') {
    if ($sessionRole !== 'admin') {
        html_response('<h3>Forbidden</h3>', 403);
    }
    $pid = (int)($_GET['payment_id'] ?? 0);
    if ($pid <= 0) {
        html_response('<h3>Invalid payment</h3>', 400);
    }
    ensure_admin_plan_payments_schema_safe($pdo);
    try {
        $stmt = $pdo->prepare("SELECT id, admin_id, provider, provider_ref, status FROM admin_plan_payments WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $pid]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p || (int)($p['admin_id'] ?? 0) !== $sessionUserId) {
            html_response('<h3>Payment not found</h3>', 404);
        }
        $ref = trim((string)($p['provider_ref'] ?? ''));
        $status = (string)($p['status'] ?? '');
        if ($status !== 'paid' && (string)($p['provider'] ?? '') === 'paymongo' && $ref !== '') {
            $attrs = paymongo_get_checkout_session($ref);
            $paid = false;
            $payments = (array)($attrs['payments'] ?? []);
            foreach ($payments as $pay) {
                $payStatus = strtolower((string)(is_array($pay) ? ($pay['attributes']['status'] ?? '') : ''));
                if ($payStatus === 'paid') {
                    $paid = true;
                    break;
                }
            }
            if ($paid) {
                paymongo_finalize_payment($pdo, $pid, $sessionUserId);
                $status = 'paid';
            }
        }
        $isPaid = ($status === 'paid');
        $title = $isPaid ? 'Payment Successful' : 'Payment Processing';
        $msg = $isPaid
            ? 'Payment confirmed! Your plan has been extended automatically. You can return to the dashboard.'
            : 'We could not confirm your payment yet. If you completed the payment, it will be confirmed shortly - you can close this window.';
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head>';
        $html .= '<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:18px;text-align:center;">';
        $html .= '<div style="font-weight:800;font-size:18px;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
        $html .= '<div style="color:#667085;margin-top:6px;">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
        $html .= '<script>(function(){try{if(window.opener){window.opener.postMessage({type:"ctr_plan_payment_paid",payment_id:' . (int)$pid . ',paid:' . ($isPaid ? 'true' : 'false') . '},"*");}}catch(e){};setTimeout(function(){try{if(window.opener){window.close();}else{window.location.href=' . json_encode(paymongo_app_base_url() . (CTR_BASE_PATH === '' ? '' : CTR_BASE_PATH) . '/index.php') . ';}}catch(e2){};},1500);})();</script>';
        $html .= '</body></html>';
        html_response($html, 200);
    } catch (Throwable $e) {
        html_response('<h3>Payment verification error</h3>', 500);
    }
}

if ($action === 'get_plan_extension_payment_status') {
    if ($sessionRole !== 'admin' && $sessionRole !== 'superadmin') {
        json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
    }
    $pid = (int)($_GET['payment_id'] ?? 0);
    if ($pid <= 0) {
        json_response(['ok' => false, 'message' => 'Invalid payment.'], 400);
    }
    ensure_admin_plan_payments_schema_safe($pdo);
    try {
        $stmt = $pdo->prepare("SELECT id, admin_id, months, amount_php, status, provider, request_id, created_at, paid_at FROM admin_plan_payments WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $pid]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            json_response(['ok' => false, 'message' => 'Not found.'], 404);
        }
        $adminId = (int)($p['admin_id'] ?? 0);
        if ($sessionRole === 'admin' && $adminId !== $sessionUserId) {
            json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
        }
        json_response([
            'ok' => true,
            'payment' => [
                'id' => (int)($p['id'] ?? 0),
                'months' => (int)($p['months'] ?? 0),
                'amount_php' => (int)($p['amount_php'] ?? 0),
                'rent_php' => ((int)($p['months'] ?? 0) > 0 ? ((int)$p['months'] * 100) : 0),
                'maintenance_php' => max(0, (int)($p['amount_php'] ?? 0) - (((int)($p['months'] ?? 0) > 0 ? (int)$p['months'] : 0) * 100)),
                'status' => (string)($p['status'] ?? ''),
                'provider' => (string)($p['provider'] ?? ''),
                'request_id' => (int)($p['request_id'] ?? 0),
                'created_at' => (string)($p['created_at'] ?? ''),
                'paid_at' => (string)($p['paid_at'] ?? ''),
            ],
        ]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => 'Failed to load payment.'], 500);
    }
}

if ($action === 'mock_gcash_checkout') {
    if ($sessionRole !== 'admin') {
        html_response('<h3>Forbidden</h3>', 403);
    }
    $pid = (int)($_GET['payment_id'] ?? 0);
    if ($pid <= 0) {
        html_response('<h3>Invalid payment</h3>', 400);
    }
    ensure_admin_plan_payments_schema_safe($pdo);
    try {
        $stmt = $pdo->prepare("SELECT id, admin_id, months, amount_php, status FROM admin_plan_payments WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $pid]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            html_response('<h3>Payment not found</h3>', 404);
        }
        if ((int)($p['admin_id'] ?? 0) !== $sessionUserId) {
            html_response('<h3>Forbidden</h3>', 403);
        }
        $months = (int)($p['months'] ?? 1);
        $amount = (int)($p['amount_php'] ?? 0);
        $status = (string)($p['status'] ?? 'pending');
        $title = 'GCash Payment';
        $body = '';
        $body .= '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $body .= '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        $body .= '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f6f7fb}';
        $body .= '.wrap{max-width:520px;margin:24px auto;padding:0 16px}.card{background:#fff;border-radius:12px;box-shadow:0 6px 24px rgba(16,24,40,.08);padding:18px}';
        $body .= '.row{display:flex;justify-content:space-between;gap:12px;margin:10px 0}.btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #0d6efd;background:#0d6efd;color:#fff;text-decoration:none;font-weight:600;cursor:pointer}';
        $body .= '.btn2{background:#fff;color:#0d6efd}.muted{color:#667085;font-size:13px}.amt{font-size:26px;font-weight:800}';
        $body .= '</style></head><body><div class="wrap"><div class="card">';
        $body .= '<div style="font-weight:800;font-size:18px;">GCash Payment</div>';
        $body .= '<div class="muted" style="margin-top:6px;">Mock checkout page (replace with real GCash gateway later).</div>';
        $body .= '<div class="row"><div>Plan extension</div><div>' . htmlspecialchars((string)$months, ENT_QUOTES, 'UTF-8') . ' month' . ($months === 1 ? '' : 's') . '</div></div>';
        $body .= '<div class="row"><div>Amount</div><div class="amt">₱' . htmlspecialchars(number_format($amount), ENT_QUOTES, 'UTF-8') . '</div></div>';
        if ($status === 'paid') {
            $body .= '<div class="muted" style="margin-top:12px;">Payment already marked as paid. You can close this window.</div>';
            $body .= '<div style="margin-top:14px;"><button class="btn btn2" onclick="try{window.close();}catch(e){}">Close</button></div>';
        } else {
            $body .= '<form method="post" action="notifications" style="margin-top:14px;">';
            $body .= '<input type="hidden" name="action" value="mock_gcash_pay">';
            $body .= '<input type="hidden" name="payment_id" value="' . htmlspecialchars((string)$pid, ENT_QUOTES, 'UTF-8') . '">';
            $body .= '<button type="submit" class="btn">Pay Now</button> ';
            $body .= '<button type="button" class="btn btn2" onclick="try{window.close();}catch(e){}">Cancel</button>';
            $body .= '</form>';
        }
        $body .= '</div></div></body></html>';
        html_response($body, 200);
    } catch (Throwable $e) {
        html_response('<h3>Checkout error</h3>', 500);
    }
}

if ($action === 'mock_gcash_pay') {
    if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        html_response('<h3>Method not allowed</h3>', 405);
    }
    if ($sessionRole !== 'admin') {
        html_response('<h3>Forbidden</h3>', 403);
    }
    $pid = (int)($_POST['payment_id'] ?? 0);
    if ($pid <= 0) {
        html_response('<h3>Invalid payment</h3>', 400);
    }
    ensure_admin_plan_payments_schema_safe($pdo);
    try {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id, admin_id, status FROM admin_plan_payments WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $pid]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) {
                $pdo->rollBack();
                html_response('<h3>Payment not found</h3>', 404);
            }
            if ((int)($p['admin_id'] ?? 0) !== $sessionUserId) {
                $pdo->rollBack();
                html_response('<h3>Forbidden</h3>', 403);
            }
            if ((string)($p['status'] ?? '') !== 'paid') {
                $u = $pdo->prepare("UPDATE admin_plan_payments SET status = 'paid', paid_at = NOW() WHERE id = :id");
                $u->execute([':id' => $pid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            html_response('<h3>Payment update failed</h3>', 500);
        }
        try {
            ensure_request_for_paid_payment($pdo, $pid, $sessionUserId);
        } catch (Throwable $e) {
        }
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Paid</title></head><body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:18px;">';
        $html .= '<div style="font-weight:800;font-size:18px;">Payment Successful</div>';
        $html .= '<div style="color:#667085;margin-top:6px;">You can close this window.</div>';
        $html .= '<script>(function(){try{if(window.opener){window.opener.postMessage({type:\"ctr_plan_payment_paid\",payment_id:' . (int)$pid . '},\"*\");}}catch(e){};try{window.close();}catch(e2){};})();</script>';
        $html .= '</body></html>';
        html_response($html, 200);
    } catch (Throwable $e) {
        html_response('<h3>Payment failed</h3>', 500);
    }
}

if ($action === 'list_plan_payment_records') {
    if ($sessionRole !== 'superadmin') {
        json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
    }
    ensure_admin_plan_payments_schema_safe($pdo);
    try {
        // Latest 30 payment records (any status) with the paying admin's info.
        $stmt = $pdo->query(
            "SELECT p.id, p.admin_id, p.months, p.amount_php, p.note, p.status, p.provider, p.provider_ref,
                    p.request_id, p.created_at, p.paid_at, u.name, u.id_number
             FROM admin_plan_payments p
             JOIN users u ON u.id = p.admin_id
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT 30"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $records = [];
        foreach ($rows as $r) {
            $when = (string)($r['paid_at'] ?? '');
            if ($when === '') {
                $when = (string)($r['created_at'] ?? '');
            }
            $records[] = [
                'id' => (int)($r['id'] ?? 0),
                'admin_id' => (int)($r['admin_id'] ?? 0),
                'admin_name' => (string)($r['name'] ?? ''),
                'admin_id_number' => (string)($r['id_number'] ?? ''),
                'months' => (int)($r['months'] ?? 0),
                'amount_php' => (int)($r['amount_php'] ?? 0),
                'note' => (string)($r['note'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'provider' => (string)($r['provider'] ?? ''),
                'provider_ref' => (string)($r['provider_ref'] ?? ''),
                'request_id' => (int)($r['request_id'] ?? 0),
                'created_at' => (string)($r['created_at'] ?? ''),
                'paid_at' => (string)($r['paid_at'] ?? ''),
                'time' => $when !== '' ? date('M j, h:i A', strtotime($when)) : '--',
                'created_ts' => (int)(strtotime((string)($r['created_at'] ?? '')) ?: 0) * 1000,
            ];
        }
        $etag = ctr_etag_from(['b' => floor(time() / 30), 'uid' => $sessionUserId, 'r' => $records]);
        ctr_handle_conditional($etag, 10);
        json_response(['ok' => true, 'records' => $records, 'count' => count($records)]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => 'Failed to load payment records.'], 500);
    }
}

if ($action === 'get_plan_payment_record') {
    if ($sessionRole !== 'superadmin') {
        json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
    }
    $pid = (int)($_GET['payment_id'] ?? 0);
    if ($pid <= 0) {
        json_response(['ok' => false, 'message' => 'Invalid payment.'], 400);
    }
    ensure_admin_plan_payments_schema_safe($pdo);
    ensure_admin_plan_extension_requests_schema_safe($pdo);
    try {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.admin_id, p.months, p.amount_php, p.note, p.status, p.provider, p.provider_ref,
                    p.request_id, p.created_at, p.paid_at, u.name, u.id_number,
                    u.plan_months, u.plan_expires_at, u.is_active,
                    r.status AS request_status, r.resolved_at AS request_resolved_at
             FROM admin_plan_payments p
             JOIN users u ON u.id = p.admin_id
             LEFT JOIN admin_plan_extension_requests r ON r.id = p.request_id
             WHERE p.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['ok' => false, 'message' => 'Not found.'], 404);
        }
        $record = [
            'id' => (int)($row['id'] ?? 0),
            'admin_id' => (int)($row['admin_id'] ?? 0),
            'admin_name' => (string)($row['name'] ?? ''),
            'admin_id_number' => (string)($row['id_number'] ?? ''),
            'months' => (int)($row['months'] ?? 0),
            'amount_php' => (int)($row['amount_php'] ?? 0),
            'note' => (string)($row['note'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'provider' => (string)($row['provider'] ?? ''),
            'provider_ref' => (string)($row['provider_ref'] ?? ''),
            'request_id' => (int)($row['request_id'] ?? 0),
            'request_status' => (string)($row['request_status'] ?? ''),
            'request_resolved_at' => (string)($row['request_resolved_at'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'paid_at' => (string)($row['paid_at'] ?? ''),
            'plan_months' => (int)($row['plan_months'] ?? 0),
            'plan_expires_at' => (string)($row['plan_expires_at'] ?? ''),
            'is_active' => (int)($row['is_active'] ?? 0),
        ];
        json_response(['ok' => true, 'record' => $record]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => 'Failed to load payment record.'], 500);
    }
}

if ($action === 'list_plan_extension_requests') {
    if ($sessionRole !== 'superadmin') {
        json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
    }
    ensure_admin_plan_extension_requests_schema_safe($pdo);
    try {
        $sql = "SELECT r.id, r.admin_id, r.requested_months, r.note, r.payment_proof, r.updated_at, r.created_at, u.name, u.id_number
                FROM admin_plan_extension_requests r
                JOIN users u ON u.id = r.admin_id
                WHERE r.status = 'pending'
                ORDER BY r.updated_at DESC
                LIMIT 20";
        $stmt = $pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $requests = [];
        foreach ($rows as $r) {
            $when = (string)($r['updated_at'] ?? ($r['created_at'] ?? ''));
            $requests[] = [
                'id' => (int)($r['id'] ?? 0),
                'admin_id' => (int)($r['admin_id'] ?? 0),
                'admin_name' => (string)($r['name'] ?? ''),
                'admin_id_number' => (string)($r['id_number'] ?? ''),
                'requested_months' => (int)($r['requested_months'] ?? 0),
                'note' => (string)($r['note'] ?? ''),
                'has_proof' => !empty($r['payment_proof']),
                'updated_at' => $when,
                'time' => $when !== '' ? date('h:i A', strtotime($when)) : '--',
                'created_ts' => (int)(strtotime($when ?: '') ?: 0) * 1000,
            ];
        }
        $etag = ctr_etag_from(['b' => floor(time() / 30), 'uid' => $sessionUserId, 'r' => $requests]);
        ctr_handle_conditional($etag, 10);
        json_response(['ok' => true, 'requests' => $requests, 'count' => count($requests)]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => 'Failed to load requests.'], 500);
    }
}

if ($action === 'request_plan_extension') {
    if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
    if ($sessionRole !== 'admin') {
        json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
    }
    if ($sessionUserId <= 0) {
        json_response(['ok' => false, 'message' => 'Invalid session.'], 400);
    }

    $months = (int)($_POST['months'] ?? 1);
    if ($months < 1) $months = 1;
    if ($months > 60) $months = 60;
    $note = trim((string)($_POST['note'] ?? ''));
    if (strlen($note) > 255) {
        $note = substr($note, 0, 255);
    }

    $proofPath = null;
    if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['payment_proof'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mimeType, $allowedTypes, true)) {
            json_response(['ok' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, WebP, and PDF are allowed.'], 400);
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            json_response(['ok' => false, 'message' => 'File too large. Max 10MB.'], 400);
        }
        $ext = 'jpg';
        if ($mimeType === 'image/png') $ext = 'png';
        elseif ($mimeType === 'image/gif') $ext = 'gif';
        elseif ($mimeType === 'image/webp') $ext = 'webp';
        elseif ($mimeType === 'application/pdf') $ext = 'pdf';
        $dir = __DIR__ . '/uploads/plan_proofs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $filename = 'proof_' . $sessionUserId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            json_response(['ok' => false, 'message' => 'Failed to save proof file.'], 500);
        }
        $proofPath = 'uploads/plan_proofs/' . $filename;
    }

    ensure_admin_plan_extension_requests_schema_safe($pdo);

    try {
        $stmt = $pdo->prepare("SELECT id FROM admin_plan_extension_requests WHERE admin_id = :aid AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $stmt->execute([':aid' => $sessionUserId]);
        $existingId = (int)($stmt->fetchColumn() ?: 0);
        if ($existingId > 0) {
            if ($proofPath) {
                $u = $pdo->prepare("UPDATE admin_plan_extension_requests SET requested_months = :m, note = :note, payment_proof = :pp WHERE id = :id");
                $u->execute([':m' => $months, ':note' => ($note !== '' ? $note : null), ':pp' => $proofPath, ':id' => $existingId]);
            } else {
                $u = $pdo->prepare("UPDATE admin_plan_extension_requests SET requested_months = :m, note = :note WHERE id = :id");
                $u->execute([':m' => $months, ':note' => ($note !== '' ? $note : null), ':id' => $existingId]);
            }
        } else {
            if ($proofPath) {
                $i = $pdo->prepare("INSERT INTO admin_plan_extension_requests (admin_id, requested_months, note, payment_proof, status) VALUES (:aid, :m, :note, :pp, 'pending')");
                $i->execute([':aid' => $sessionUserId, ':m' => $months, ':note' => ($note !== '' ? $note : null), ':pp' => $proofPath]);
            } else {
                $i = $pdo->prepare("INSERT INTO admin_plan_extension_requests (admin_id, requested_months, note, status) VALUES (:aid, :m, :note, 'pending')");
                $i->execute([':aid' => $sessionUserId, ':m' => $months, ':note' => ($note !== '' ? $note : null)]);
            }
        }
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => 'Failed to submit request.'], 500);
    }

    json_response(['ok' => true, 'message' => 'Request submitted.']);
}

if ($action === 'list') {
    ensure_notifications_schema_safe($pdo);
    try {
        $pdo->exec("DELETE FROM notifications WHERE created_at < (NOW() - INTERVAL 3 DAY)");
    } catch (Throwable $e) {
    }
    try {
        ensure_admin_plan_extension_requests_schema_safe($pdo);
        $pdo->exec("DELETE FROM admin_plan_extension_requests WHERE status <> 'pending' AND resolved_at IS NOT NULL AND resolved_at < (NOW() - INTERVAL 3 DAY)");
    } catch (Throwable $e) {
    }
    $notifFilter = '';
    $params = [':today' => $today];
    if ($sessionRole !== 'superadmin') {
        $notifFilter = ' AND al.user_id IN (SELECT id FROM users WHERE created_by = :created_by OR id = :self_id)';
        $params[':created_by'] = $sessionUserId;
        $params[':self_id'] = $sessionUserId;
    }
    $sinceTs = (int)($_GET['since_ts'] ?? 0);
    $sinceRaw = trim((string)($_GET['since'] ?? ''));
    $sinceFilter = '';
    if ($sinceTs > 0) {
        $sinceFilter = ' AND al.created_at > FROM_UNIXTIME(:since_s)';
        $params[':since_s'] = (int)floor($sinceTs / 1000);
    } elseif ($sinceRaw !== '') {
        $t = strtotime($sinceRaw);
        if ($t !== false) {
            $sinceFilter = ' AND al.created_at > FROM_UNIXTIME(:since_s)';
            $params[':since_s'] = (int)$t;
        }
    }

    if ($sessionRole === 'admin' && $sessionUserId > 0) {
        maybe_enqueue_admin_plan_expiry_notification($pdo, $sessionUserId);
    }

    $sql = "SELECT al.id, al.user_id, al.id_number, al.full_name, al.attend_date, al.time_in, al.time_out, al.status, al.session, al.scan_type, al.created_at
            FROM attendance_logs al
            WHERE al.attend_date = :today{$notifFilter}{$sinceFilter}
            ORDER BY al.created_at DESC
            LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = [];
    foreach ($logs as $log) {
        $scanType = (string)($log['scan_type'] ?? '');
        $session = (string)($log['session'] ?? '');
        $time = $scanType === 'in' ? $log['time_in'] : $log['time_out'];
        $label = $scanType === 'in' ? 'Time In' : 'Time Out';
        $sessionLabel = $session === 'morning' ? 'AM' : 'PM';

        $notifications[] = [
            'id' => (int)($log['id'] ?? 0),
            'user_id' => (int)($log['user_id'] ?? 0),
            'name' => (string)($log['full_name'] ?? ''),
            'id_number' => (string)($log['id_number'] ?? ''),
            'message' => (string)($log['full_name'] ?? '') . ' - ' . $label . ' (' . $sessionLabel . ')',
            'time' => is_string($time) ? date('h:i A', strtotime($time)) : '--',
            'status' => (string)($log['status'] ?? ''),
            'created_at' => (string)($log['created_at'] ?? ''),
            'created_ts' => (int)(strtotime((string)($log['created_at'] ?? '')) ?: 0) * 1000,
        ];
    }

    $nParams = [];
    $nSinceFilter = '';
    if ($sinceTs > 0) {
        $nSinceFilter = ' AND created_at > FROM_UNIXTIME(:nsince_s)';
        $nParams[':nsince_s'] = (int)floor($sinceTs / 1000);
    } elseif ($sinceRaw !== '') {
        $t = strtotime($sinceRaw);
        if ($t !== false) {
            $nSinceFilter = ' AND created_at > FROM_UNIXTIME(:nsince_s)';
            $nParams[':nsince_s'] = (int)$t;
        }
    }
    $nUserFilter = '';
    if ($sessionUserId > 0) {
        $nUserFilter = ' AND (user_id IS NULL OR user_id = :nuid)';
        $nParams[':nuid'] = $sessionUserId;
    }
    $nSql = "SELECT id, user_id, title, body, is_read, created_at
             FROM notifications
             WHERE created_at >= (NOW() - INTERVAL 3 DAY){$nUserFilter}{$nSinceFilter}
             ORDER BY created_at DESC
             LIMIT 20";
    $nStmt = $pdo->prepare($nSql);
    $nStmt->execute($nParams);
    $customNotifs = $nStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($customNotifs as $cn) {
        $notifications[] = [
            'id' => (int)$cn['id'],
            'user_id' => (int)$cn['user_id'],
            'name' => 'System',
            'id_number' => '',
            'message' => (string)($cn['title'] ?? '') . ($cn['body'] ? (' — ' . $cn['body']) : ''),
            'time' => is_string($cn['created_at']) ? date('h:i A', strtotime($cn['created_at'])) : '--',
            'status' => 'info',
            'created_at' => (string)($cn['created_at'] ?? ''),
            'created_ts' => (int)(strtotime((string)($cn['created_at'] ?? '')) ?: 0) * 1000,
        ];
    }

    usort($notifications, function ($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    $notifications = array_slice($notifications, 0, 20);

    $etag = ctr_etag_from(['b' => floor(time() / 15), 'uid' => $sessionUserId, 'n' => $notifications]);
    ctr_handle_conditional($etag, 10);
    json_response(['ok' => true, 'notifications' => $notifications, 'count' => count($notifications)]);
}

if ($action === 'create') {
    if ($sessionRole !== 'superadmin') {
        json_response(['ok' => false, 'message' => 'Only super admin can create notifications'], 403);
    }
    ensure_notifications_schema_safe($pdo);
    $title = trim((string)($_POST['title'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    if ($title === '') {
        json_response(['ok' => false, 'message' => 'Title is required'], 400);
    }
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, body, is_read) VALUES (NULL, :title, :body, 0)");
    $stmt->execute([':title' => $title, ':body' => $body]);
    json_response(['ok' => true, 'message' => 'Notification created']);
}

json_response(['ok' => false, 'message' => 'Unknown action'], 400);
