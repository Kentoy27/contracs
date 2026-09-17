<?php
/**
 * Test harness: extracts the real paymongo_* function definitions straight
 * out of notifications.php (no copies, no drift) and smoke-tests them
 * against PayMongo test mode. Run: php tools/paymongo_smoke_test.php
 */
require __DIR__ . '/../config/paymongo.php';

$src = file_get_contents(__DIR__ . '/../notifications.php');
if ($src === false) {
    fwrite(STDERR, "Cannot read notifications.php\n");
    exit(1);
}

$code = '';
foreach (['paymongo_create_checkout_session', 'paymongo_get_checkout_session', 'paymongo_finalize_payment'] as $fn) {
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

// paymongo_finalize_payment calls these; provide no-op stubs for the harness.
$code .= 'function ensure_request_for_paid_payment($pdo, $paymentId, $adminId): int { return 0; }' . "\n";
eval($code);

echo 'CAINFO: ' . (paymongo_cainfo() ?: '(none)') . "\n";
try {
    $cs = paymongo_create_checkout_session(
        100,
        'SMOKE-TEST-' . time(),
        'http://localhost/contracs/notifications.php?action=paymongo_return&payment_id=0',
        'http://localhost/contracs/index.php',
        'ConTracS plan extension - smoke test'
    );
    echo 'SESSION ID: ' . $cs['id'] . "\n";
    echo 'CHECKOUT URL: ' . $cs['checkout_url'] . "\n";

    $attrs = paymongo_get_checkout_session($cs['id']);
    echo 'RETRIEVE STATUS: ' . ($attrs['status'] ?? '?')
        . ' | payments: ' . count((array)($attrs['payments'] ?? []))
        . ' | reference: ' . ($attrs['reference_number'] ?? '?') . "\n";
    echo "SMOKE TEST OK\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
