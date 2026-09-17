<?php
if (!defined('SLIMS_LAYOUT_BOOTSTRAPPED')) {
    define('SLIMS_LAYOUT_BOOTSTRAPPED', true);
    register_shutdown_function(static function () {
        $loginSuccess = $GLOBALS['loginSuccess'] ?? null;
        require __DIR__ . '/footer.php';
    });
}
$is_pjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'fetch');
?>
<?php if (!$is_pjax) { ?>
<!DOCTYPE html>
<html lang="zxx">

<head>
    <meta charset="utf-8" />
    <meta http-equiv="x-ua-compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="description" content="" />
    <meta name="keyword" content="" />
    <meta name="author" content="flexilecode" />
    <!--! The above 6 meta tags *must* come first in the head; any other head content must come *after* these tags !-->
    <!--! BEGIN: Apps Title-->
    <title>ContracS || System </title>
    <!--! END:  Apps Title-->
    <!--! CSRF token for the AJAX layer (see includes/scripts.php) !-->
    <?php echo ctr_csrf_meta(); ?>
    <script>window.CTR_CONFIG = { phpUrls: <?php echo CTR_PHP_URLS ? 'true' : 'false'; ?> };</script>
    <!--! BEGIN: Favicon-->
    <link rel="icon" type="image/png" href="assets/images/favicon.png?v=<?php echo @filemtime(__DIR__ . '/../assets/images/favicon.png'); ?>" />
    <!--! END: Favicon-->
    <!--! BEGIN: Bootstrap CSS-->
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/bootstrap.min.css'); ?>" />
    <!--! END: Bootstrap CSS-->
    <!--! BEGIN: Vendors CSS-->
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css?v=<?php echo @filemtime(__DIR__ . '/../assets/vendors/css/vendors.min.css'); ?>" />
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/daterangepicker.min.css?v=<?php echo @filemtime(__DIR__ . '/../assets/vendors/css/daterangepicker.min.css'); ?>" />
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/dataTables.bs5.min.css?v=<?php echo @filemtime(__DIR__ . '/../assets/vendors/css/dataTables.bs5.min.css'); ?>" />
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/sweetalert2.min.css?v=<?php echo @filemtime(__DIR__ . '/../assets/vendors/css/sweetalert2.min.css'); ?>" />
    <!--! END: Vendors CSS-->
    <!--! BEGIN: Custom CSS-->
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/theme.min.css'); ?>" />
    <link rel="stylesheet" type="text/css" href="assets/css/custom.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/custom.css'); ?>" />
    <!--! END: Custom CSS-->
    <style>
        .ctr-lock-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.85);
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            backdrop-filter: blur(2px);
        }
        html[data-bs-theme="dark"] .ctr-lock-overlay,
        html.app-skin-dark .ctr-lock-overlay {
            background: rgba(15, 23, 42, 0.85);
        }
        .ctr-lock-overlay .fw-bold,
        .ctr-lock-overlay .text-muted {
            color: rgba(11, 18, 32, 0.65) !important;
        }
        html[data-bs-theme="dark"] .ctr-lock-overlay .fw-bold,
        html.app-skin-dark .ctr-lock-overlay .fw-bold,
        html[data-bs-theme="dark"] .ctr-lock-overlay .text-muted,
        html.app-skin-dark .ctr-lock-overlay .text-muted {
            color: rgba(226, 232, 240, 0.85) !important;
        }
        .ctr-lock-overlay-sm {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.85);
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            backdrop-filter: blur(2px);
        }
        html[data-bs-theme="dark"] .ctr-lock-overlay-sm,
        html.app-skin-dark .ctr-lock-overlay-sm {
            background: rgba(15, 23, 42, 0.85);
        }
        .ctr-lock-overlay-sm .fw-bold,
        .ctr-lock-overlay-sm .text-muted {
            color: rgba(11, 18, 32, 0.65) !important;
        }
        html[data-bs-theme="dark"] .ctr-lock-overlay-sm .fw-bold,
        html.app-skin-dark .ctr-lock-overlay-sm .fw-bold,
        html[data-bs-theme="dark"] .ctr-lock-overlay-sm .text-muted,
        html.app-skin-dark .ctr-lock-overlay-sm .text-muted {
            color: rgba(226, 232, 240, 0.85) !important;
        }
    </style>
    <!--! HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries !-->
    <!--! WARNING: Respond.js doesn"t work if you view the page via file: !-->
    <!--[if lt IE 9]>
			<script src="https:oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
			<script src="https:oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
		<![endif]-->
</head>
<body>
<?php } ?>
<?php

if (!$is_pjax) {
    require __DIR__ . '/sidebar.php';
    require __DIR__ . '/topbar.php';
}
?>

<?php if (!$is_pjax) { ?>
<main class="nxl-container d-flex flex-column">
    <div class="nxl-content flex-grow-1 d-flex flex-column">
<?php } ?>
        <div class="main-content flex-grow-1 pb-0">