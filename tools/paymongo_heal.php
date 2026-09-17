<?php
/**
 * One-off healer: run the (now-fixed) paymongo_return finalization + auto-
 * extend logic for a payment whose hosted checkout was completed while the
 * return handler was still broken. Usage: php tools/paymongo_heal.php <payment_id>
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/paymongo.php';

$pid = (int)($argv[1] ?? 0);
if ($pid <= 0) {
    fwrite(STDERR, "Usage: php tools/paymongo_heal.php <payment_id>\n");
    exit(1);
}

// Extract the real functions (and their dependencies) out of
// notifications.php — no copies, no drift.
$src = file_get_contents(__DIR__ . '/../notifications.php');
if ($src === false) {
    fwrite(STDERR, "Cannot read notifications.php\n");
    exit(1);
}

$functions = [
    'table_exists_safe',
    'ensure_notifications_schema_safe',
    'ensure_admin_plan_payments_schema_safe',
    'ensure_admin_plan_extension_requests_schema_safe',
    'ensure_admin_home_links_schema_safe',
    'issue_admin_home_link',
    'ensure_request_for_paid_payment',
    'paymongo_get_checkout_session',
    'paymongo_finalize_payment',
    'approve_plan_extension_request_txn',
    'paymongo_auto_extend_after_payment',
];

$code = '';
foreach ($functions as $fn) {
    $pos = strpos($src, 'function ' . $fn . '(');
    if ($pos === false) {
        fwrite(STDERR, "Function not found in notifications.php: $fn\n");
        exit(1);
    }
    $open = strpos($src, '{', $pos);
    $depth = 0;
    $end = -1;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        $c = $src[$i];
        if ($c === '{') $depth++;
        elseif ($c === '}') {
            $depth--;
            if ($depth === 0) { $end = $i; break; }
        }
    }
    if ($end < 0) {
        fwrite(STDERR, "Could not find end of function: $fn\n");
        exit(1);
    }
    $code .= substr($src, $pos, $end - $pos + 1) . "\n";
}
eval($code);

$stmt = $pdo->prepare("SELECT id, admin_id, provider, provider_ref, status FROM admin_plan_payments WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $pid]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) {
    fwrite(STDERR, "Payment $pid not found.\n");
    exit(1);
}

$ref = trim((string)($p['provider_ref'] ?? ''));
if ((string)($p['provider'] ?? '') !== 'paymongo' || $ref === '') {
    fwrite(STDERR, "Payment $pid is not a PayMongo payment.\n");
    exit(1);
}

$attrs = paymongo_get_checkout_session($ref);
$paid = false;
foreach ((array)($attrs['payments'] ?? []) as $pay) {
    if (strtolower((string)($pay['attributes']['status'] ?? '')) === 'paid') {
        $paid = true;
        break;
    }
}

echo 'SESSION STATUS: ' . ($attrs['status'] ?? '?') . ' | PAID: ' . ($paid ? 'yes' : 'no') . "\n";

$adminId = (int)($p['admin_id'] ?? 0);
if ($paid) {
    paymongo_finalize_payment($pdo, $pid, $adminId);
    paymongo_auto_extend_after_payment($pdo, $pid, $adminId);
    echo "Payment $pid finalized and plan auto-extend attempted for admin $adminId.\n";

    $q = $pdo->prepare("SELECT plan_months, plan_expires_at, is_active FROM users WHERE id = :id");
    $q->execute([':id' => $adminId]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    echo 'ADMIN PLAN NOW: months=' . ($u['plan_months'] ?? '?')
        . ' expires=' . ($u['plan_expires_at'] ?? '?')
        . ' active=' . ($u['is_active'] ?? '?') . "\n";
} else {
    echo "Not paid yet; nothing to heal.\n";
}
