<?php
require __DIR__ . '/includes/cache_headers.php';
ctr_session_start();
ctr_cache_headers('short', 300);
require __DIR__ . '/config/db.php';
include __DIR__ . '/includes/header.php';
ob_start();
ctr_cookie_consent_banner();
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1">Credits</h4>
            <div class="text-muted small">System information and acknowledgments</div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="feather-info me-2"></i>System Information</h6>
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2"><strong>System:</strong> ContracS - Contract Tracking & Attendance System</li>
                        <li class="mb-2"><strong>Version:</strong> 4.0.5</li>
                        <li class="mb-2"><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></li>
                        <li class="mb-2"><strong>Database:</strong> MySQL / MariaDB</li>
                        <li class="mb-2"><strong>Framework:</strong> Custom PHP with Bootstrap 5</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="feather-check-circle me-2"></i>Key Features</h6>
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2">✓ QR Code Attendance Tracking</li>
                        <li class="mb-2">✓ Role-based Access Control (Superadmin, Admin)</li>
                        <li class="mb-2">✓ User Management & Creation</li>
                        <li class="mb-2">✓ Daily Time Record (DTR) Generation</li>
                        <li class="mb-2">✓ Plan & Subscription Management</li>
                        <li class="mb-2">✓ Real-time Notifications</li>
                        <li class="mb-2">✓ Dark Mode Theme Support</li>
                        <li class="mb-2">✓ Responsive Mobile Design</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="feather-code me-2"></i>Technologies Used</h6>
                    <div class="row">
                        <div class="col-6">
                            <ul class="list-unstyled mb-0">
                                <li class="mb-2">• PHP 8.x</li>
                                <li class="mb-2">• MySQL / MariaDB</li>
                                <li class="mb-2">• Bootstrap 5</li>
                                <li class="mb-2">• Feather Icons</li>
                                <li class="mb-2">• SweetAlert2</li>
                            </ul>
                        </div>
                        <div class="col-6">
                            <ul class="list-unstyled mb-0">
                                <li class="mb-2">• DataTables</li>
                                <li class="mb-2">• ApexCharts</li>
                                <li class="mb-2">• TUI Calendar</li>
                                <li class="mb-2">• Select2</li>
                                <li class="mb-2">• Custom JavaScript</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="feather-heart me-2"></i>Acknowledgments</h6>
                    <p class="text-muted mb-3">
                        This system is designed to streamline contract tracking and attendance management for organizations. 
                        Built with security and usability in mind.
                    </p>
                    <p class="text-muted mb-0">
                        Special thanks to the open-source community for the amazing libraries and frameworks that made this project possible.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="text-center mt-4">
        <div class="d-inline-flex align-items-center gap-2 px-4 py-3 bg-light rounded-3">
            <i class="feather-code text-primary"></i>
            <span class="text-muted">
                &copy; <?php echo date('Y'); ?> CntracS. Developed by <strong>JOHN KENNETH ANG</strong>.
            </span>
        </div>
    </div>
</div>
<?php
$body = ob_get_clean();
$etag = ctr_etag_from($body);
ctr_handle_conditional($etag, 300);
echo $body;
?>
