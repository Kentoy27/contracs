<?php
$profileName = (string)($_SESSION['name'] ?? ($_SESSION['username'] ?? ''));
$profileRole = (string)($_SESSION['role'] ?? '');
$profileId = (string)($_SESSION['id_number'] ?? '');
$profileSub = trim($profileRole . ($profileId !== '' ? (' • ' . $profileId) : ''));
?>
    <header class="nxl-header">
        <div class="header-wrapper">
            <!--! [Start] Header Left !-->
            <div class="header-left d-flex align-items-center gap-4">
                <!--! [Start] nxl-head-mobile-toggler !-->
                <a href="javascript:void(0);" class="nxl-head-mobile-toggler" id="mobile-collapse">
                    <div class="hamburger hamburger--arrowturn">
                        <div class="hamburger-box">
                            <div class="hamburger-inner"></div>
                        </div>
                    </div>
                </a>
                <!--! [Start] nxl-head-mobile-toggler !-->
                <!--! [Start] nxl-navigation-toggle !-->
                <div class="nxl-navigation-toggle">
                    <a href="javascript:void(0);" id="menu-mini-button">
                        <i class="feather-align-left"></i>
                    </a>
                    <a href="javascript:void(0);" id="menu-expend-button" style="display: none">
                        <i class="feather-arrow-right"></i>
                    </a>
                </div>
            </div>
            <!--! [End] Header Left !-->
            <!--! [Start] Header Right !-->
            <div class="header-right ms-auto">
                <div class="d-flex align-items-center header-icons-group">
                    <div class="dropdown nxl-h-item">
                        <a class="nxl-head-link" data-bs-toggle="dropdown" href="#" role="button" data-bs-auto-close="outside" aria-label="Theme color">
                            <i class="feather-droplet"></i>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown p-3" style="min-width: 260px;">
                            <div class="fw-semibold text-dark mb-2">Theme Color</div>
                            <div class="d-flex flex-wrap gap-2 mb-3" id="themeSwatches">
                                <button type="button" class="btn p-0 border rounded-circle theme-swatch" data-color="#2F7D28" style="width: 28px; height: 28px; background: #2F7D28;"></button>
                                <button type="button" class="btn p-0 border rounded-circle theme-swatch" data-color="#1F65B8" style="width: 28px; height: 28px; background: #1F65B8;"></button>
                                <button type="button" class="btn p-0 border rounded-circle theme-swatch" data-color="#7C3AED" style="width: 28px; height: 28px; background: #7C3AED;"></button>
                                <button type="button" class="btn p-0 border rounded-circle theme-swatch" data-color="#DC2626" style="width: 28px; height: 28px; background: #DC2626;"></button>
                                <button type="button" class="btn p-0 border rounded-circle theme-swatch" data-color="#EA580C" style="width: 28px; height: 28px; background: #EA580C;"></button>
                                <button type="button" class="btn p-0 border rounded-circle theme-swatch" data-color="#0F172A" style="width: 28px; height: 28px; background: #0F172A;"></button>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" id="themeColorPicker" class="form-control form-control-color" value="#2F7D28" title="Choose color">
                                <input type="text" id="themeColorText" class="form-control" value="#2F7D28" placeholder="#RRGGBB">
                            </div>
                        </div>
                    </div>
                    <div class="nxl-h-item dark-light-theme">
                        <a href="javascript:void(0);" class="nxl-head-link dark-button">
                            <i class="feather-moon"></i>
                        </a>
                        <a href="javascript:void(0);" class="nxl-head-link light-button" style="display: none">
                            <i class="feather-sun"></i>
                        </a>
                    </div>
                    <?php if ($profileRole === 'admin'): ?>
                    <div class="nxl-h-item">
                        <a href="javascript:void(0);" class="nxl-head-link" id="adminPlanUsageBtn" aria-label="Plan usage">
                            <i class="feather-pie-chart"></i>
                        </a>
                    </div>
                    <div class="nxl-h-item">
                        <a href="javascript:void(0);" class="nxl-head-link" id="adminPlanExtendBtn" aria-label="Request plan extension">
                            <i class="feather-calendar"></i>
                        </a>
                    </div>
                    <div class="nxl-h-item">
                        <a href="javascript:void(0);" class="nxl-head-link" id="adminHomeLinkBtn" aria-label="My home link">
                            <i class="feather-link"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if ($profileRole === 'superadmin'): ?>
                    <div class="nxl-h-item">
                        <a href="javascript:void(0);" class="nxl-head-link" id="saDashboardChartsBtn" aria-label="Dashboard charts">
                            <i class="feather-bar-chart-2"></i>
                        </a>
                    </div>
                    <div class="dropdown nxl-h-item">
                        <a class="nxl-head-link position-relative" id="planExtToggle" data-bs-toggle="dropdown" href="#" role="button" data-bs-auto-close="outside" aria-label="Plan payment records">
                            <i class="feather-calendar"></i>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" id="planExtBadge">0</span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown nxl-notifications-menu">
                            <div class="d-flex justify-content-between align-items-center notifications-head">
                                <h6 class="fw-bold text-dark mb-0">Plan Payment Records</h6>
                            </div>
                            <div class="px-3 py-2" id="planExtList" style="max-height: 360px; overflow: auto;">
                                <div class="text-muted small">Loading...</div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="dropdown nxl-h-item">
                        <a class="nxl-head-link position-relative" id="liveNotiToggle" data-bs-toggle="dropdown" href="#" role="button" data-bs-auto-close="outside" aria-label="Notifications">
                            <i class="feather-bell"></i>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" id="liveNotiBadge">0</span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown nxl-notifications-menu">
                            <div class="d-flex justify-content-between align-items-center notifications-head">
                                <h6 class="fw-bold text-dark mb-0">Notifications</h6>
                                <?php if ($profileRole === 'superadmin'): ?>
                                <a href="javascript:void(0);" class="btn btn-sm btn-outline-primary" id="addNotiBtn"><i class="feather-plus"></i> Add</a>
                                <?php endif; ?>
                            </div>
                            <?php if ($profileRole === 'superadmin'): ?>
                            <div id="addNotiForm" class="px-3 py-2 border-bottom" style="display:none;">
                                <input type="text" id="notiTitle" class="form-control form-control-sm mb-2" placeholder="Title (e.g. System Update)">
                                <textarea id="notiBody" class="form-control form-control-sm mb-2" rows="2" placeholder="Details (optional)"></textarea>
                                <button type="button" class="btn btn-sm btn-primary w-100" id="sendNotiBtn">Send Notification</button>
                            </div>
                            <?php endif; ?>
                            <div class="px-3 py-2" id="liveNotiList" style="max-height: 360px; overflow: auto;">
                                <div class="text-muted small">Loading...</div>
                            </div>
                            <div class="text-center notifications-footer">
                                <a href="javascript:void(0);" class="fs-13 fw-semibold text-dark" id="liveNotiClear">Mark as read</a>
                            </div>
                        </div>
                    </div>
                    <div class="dropdown nxl-h-item">
                        <a href="javascript:void(0);" class="nxl-head-link" data-bs-toggle="dropdown" role="button" data-bs-auto-close="outside">
                            <i class="feather-user"></i>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown nxl-user-dropdown">
                            <div class="dropdown-header">
                                <div class="d-flex align-items-center">
                                    <div class="user-avtar d-flex align-items-center justify-content-center rounded-circle bg-primary text-white" style="width: 40px; height: 40px;">
                                        <i class="feather-user"></i>
                                    </div>
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($profileSub, ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <div class="dropdown-divider"></div>
                            <?php if ($profileRole === 'superadmin'): ?>
                            <a href="javascript:void(0);" class="dropdown-item" id="superAdminProfileBtn">
                                <i class="feather-user"></i>
                                <span>View Profile</span>
                            </a>
                            <?php endif; ?>
                            <a href="javascript:void(0);" class="dropdown-item" id="resetPasswordBtn">
                                <i class="feather-key"></i>
                                <span>Reset Password</span>
                            </a>
                            <a href="<?php echo ctr_url('logout'); ?>" class="dropdown-item">
                                <i class="feather-log-out"></i>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <!--! [End] Header Right !-->
        </div>
    </header>

    <?php if ($profileRole === 'superadmin'): ?>
    <div class="modal fade" id="saDashboardChartsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="feather-bar-chart-2 me-2"></i>Dashboard Overview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-muted small mb-3" id="saChartsUpdatedAt"></div>
                    <!-- KPI Summary Row -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="card border-0 shadow-sm h-100 text-center">
                                <div class="card-body py-3">
                                    <div class="text-muted small text-uppercase mb-1">Total Users</div>
                                    <div class="fs-4 fw-bold text-primary" id="saChartTotalUsers">-</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="card border-0 shadow-sm h-100 text-center">
                                <div class="card-body py-3">
                                    <div class="text-muted small text-uppercase mb-1">Present Today</div>
                                    <div class="fs-4 fw-bold text-success" id="saChartPresentToday">-</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="card border-0 shadow-sm h-100 text-center">
                                <div class="card-body py-3">
                                    <div class="text-muted small text-uppercase mb-1">Absent Today</div>
                                    <div class="fs-4 fw-bold text-danger" id="saChartAbsentToday">-</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="card border-0 shadow-sm h-100 text-center">
                                <div class="card-body py-3">
                                    <div class="text-muted small text-uppercase mb-1">AM Check-ins</div>
                                    <div class="fs-4 fw-bold text-info" id="saChartAm">-</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="card border-0 shadow-sm h-100 text-center">
                                <div class="card-body py-3">
                                    <div class="text-muted small text-uppercase mb-1">PM Check-ins</div>
                                    <div class="fs-4 fw-bold text-warning" id="saChartPm">-</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="card border-0 shadow-sm h-100 text-center">
                                <div class="card-body py-3">
                                    <div class="text-muted small text-uppercase mb-1">Monthly Attendance</div>
                                    <div class="fs-4 fw-bold text-secondary" id="saChartMonthAtt">-</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row 1 -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-transparent border-0 py-2">
                                    <h6 class="mb-0 fw-semibold">Today's Attendance Breakdown</h6>
                                </div>
                                <div class="card-body">
                                    <div id="saChartDonutToday" style="min-height:280px;"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-transparent border-0 py-2">
                                    <h6 class="mb-0 fw-semibold">AM vs PM Check-ins</h6>
                                </div>
                                <div class="card-body">
                                    <div id="saChartBarAmPm" style="min-height:280px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row 2 -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-transparent border-0 py-2">
                                    <h6 class="mb-0 fw-semibold">Most Active Users (This Month)</h6>
                                </div>
                                <div class="card-body">
                                    <div id="saChartBarActive" style="min-height:280px;"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-transparent border-0 py-2">
                                    <h6 class="mb-0 fw-semibold">Most Absences (This Month)</h6>
                                </div>
                                <div class="card-body">
                                    <div id="saChartBarAbsences" style="min-height:280px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row 3 - Attendance Trend -->
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-transparent border-0 py-2">
                                    <h6 class="mb-0 fw-semibold">Attendance Overview</h6>
                                </div>
                                <div class="card-body">
                                    <div id="saChartAreaOverview" style="min-height:300px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="saChartsRefreshBtn"><i class="feather-refresh-cw me-1"></i>Refresh</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($profileRole === 'superadmin'): ?>
    <div class="modal fade" id="superAdminProfileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Superadmin Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="saProfileName">Name</label>
                            <input class="form-control" id="saProfileName" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="saProfileUsername">Username</label>
                            <input class="form-control" id="saProfileUsername" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="saProfileRole">Role</label>
                            <input class="form-control" id="saProfileRole" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="saProfileIdNumber">ID Number</label>
                            <input class="form-control" id="saProfileIdNumber" readonly>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="d-flex flex-column align-items-center text-center">
                        <div class="fw-semibold mb-2">My QR Code</div>
                        <div class="p-2 border rounded bg-white" id="saProfileQrWrap" style="display:none;">
                            <img id="saProfileQrImg" alt="My QR Code" style="width:180px;height:180px;display:block;">
                        </div>
                        <div class="text-muted small mt-2" id="saProfileQrCaption"></div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="saDownloadQrBtn" style="display:none;">
                                <i class="feather-download"></i> Download QR
                            </button>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="fw-semibold mb-2">Reset Password</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="saCurrentPassword">Current password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="saCurrentPassword" autocomplete="current-password">
                                <button class="btn btn-outline-secondary" type="button" id="saToggleCurrentPassword" tabindex="-1" title="Show/Hide password"><i class="feather-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6"></div>
                        <div class="col-md-6">
                            <label class="form-label" for="saNewPassword">New password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="saNewPassword" autocomplete="new-password">
                                <button class="btn btn-outline-secondary" type="button" id="saToggleNewPassword" tabindex="-1" title="Show/Hide password"><i class="feather-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="saConfirmPassword">Confirm new password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="saConfirmPassword" autocomplete="new-password">
                                <button class="btn btn-outline-secondary" type="button" id="saToggleConfirmPassword" tabindex="-1" title="Show/Hide password"><i class="feather-eye"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="saSavePasswordBtn">Save Password</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($profileRole === 'superadmin'): ?>
    <div class="modal fade" id="saPlanExtendRequestModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Plan Payment Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="saPeAdminName">Admin Name</label>
                            <input class="form-control" id="saPeAdminName" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="saPeAdminIdNumber">ID Number</label>
                            <input class="form-control" id="saPeAdminIdNumber" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="saPeMonths">Requested Months</label>
                            <input class="form-control" id="saPeMonths" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="saPeCurrentExpiry">Current Expires At</label>
                            <input class="form-control" id="saPeCurrentExpiry" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="saPeNewExpiry">Approved At</label>
                            <input class="form-control" id="saPeNewExpiry" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="saPeAmount">Amount Paid</label>
                            <input class="form-control" id="saPeAmount" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="saPeProvider">Payment Method</label>
                            <input class="form-control" id="saPeProvider" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="saPePaidAt">Paid At</label>
                            <input class="form-control" id="saPePaidAt" readonly>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="saPeNote">Message</label>
                            <textarea class="form-control" id="saPeNote" rows="2" readonly></textarea>
                        </div>
                    </div>
                    <div class="alert alert-warning mt-3 d-none" id="saPeAlert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($profileRole === 'admin'): ?>
    <div class="modal fade" id="adminPlanUsageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Plan Usage</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="fw-semibold">Status</div>
                            <span class="badge bg-soft-secondary text-secondary" id="apuStatusBadge">-</span>
                        </div>
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar" role="progressbar" style="width: 0%;" id="apuProgressBar"></div>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mt-1">
                            <span id="apuProgressLeft">-</span>
                            <span id="apuProgressRight">-</span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" for="apuPlanMonths">Plan Months <span class="badge bg-success-subtle text-success ms-1 d-none" id="apuFreeTrialBadge">Free Trial</span></label>
                            <input class="form-control" id="apuPlanMonths" readonly>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="apuPlanExpiresAt">Expires At</label>
                            <input class="form-control" id="apuPlanExpiresAt" readonly>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="apuDaysRemaining">Days Remaining</label>
                            <input class="form-control" id="apuDaysRemaining" readonly>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="apuUsersCreated">Users Created</label>
                            <input class="form-control" id="apuUsersCreated" readonly>
                        </div>
                    </div>

                    <div class="alert alert-warning mt-3 d-none" id="apuAlert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="adminPlanExtendModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="apeModalTitle">Request Plan Extension</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="apeStep1">
                        <div class="mb-3">
                            <label class="form-label" for="apeMonths">How many months?</label>
                            <select class="form-select" id="apeMonths">
                                <?php for ($m = 1; $m <= 60; $m++): ?>
                                    <option value="<?php echo (int)$m; ?>"><?php echo (int)$m; ?> month<?php echo $m === 1 ? '' : 's'; ?></option>
                                <?php endfor; ?>
                            </select>
                            <div class="form-text">Select the number of months you want to extend your plan.</div>
                        </div>
                        <div class="alert alert-info py-2 d-none" id="apePayInfo">
                            <div>Pay with GCash: <span class="fw-semibold" id="apePayAmount">₱--</span></div>
                            <div class="small mt-1" id="apePayBreakdown"></div>
                            <div class="small text-muted" id="apePayFeeText"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="apeNote">Message (optional)</label>
                            <textarea class="form-control" id="apeNote" rows="2" maxlength="255" placeholder="Add details or reason (optional)"></textarea>
                        </div>
                        <div class="alert alert-warning d-none" id="apeAlert"></div>
                    </div>
                    <div id="apeStep2" style="display:none;">
                        <div class="text-center mb-3">
                            <div class="fw-bold text-primary mb-1" style="font-size:18px;">GCash Payment</div>
                            <div class="text-muted small">Scan the QR code or send to the details below</div>
                        </div>
                        <div class="text-center mb-3">
                            <div class="d-inline-block p-2 border rounded bg-white">
                                <img src="assets/images/qrgcash.png" alt="GCash QR Code" style="width:220px;height:220px;display:block;border-radius:8px;" onerror="this.style.display='none';this.parentNode.innerHTML='<div class=\'text-muted p-4\'>QR Code</div>';">
                            </div>
                            <div class="text-muted small mt-2 fst-italic fw-bold" style="font-size:11px;">This system is a testament to the developer's hard work and dedication in creating a reliable and user-friendly solution.</div>
                        </div>
                        <div class="card mb-3" style="border:2px solid #007DFE;background:#f0f7ff;">
                            <div class="card-body py-3">
                                <div class="row mb-2">
                                    <div class="col-5 text-muted small">GCash Number</div>
                                    <div class="col-7 fw-bold">09938703743</div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-5 text-muted small">Nickname</div>
                                    <div class="col-7 fw-bold">DEV OPS.</div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-5 text-muted small">Name</div>
                                    <div class="col-7 fw-bold">JO*N KE****H A.</div>
                                </div>
                                <div class="row">
                                    <div class="col-5 text-muted small">Amount</div>
                                    <div class="col-7 fw-bold text-primary" id="apeStep2Amount">₱--</div>
                                </div>
                                <div class="small text-muted mt-2" id="apeStep2Breakdown"></div>
                                <div class="small text-muted" id="apeStep2FeeText"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Upload Proof of Payment</label>
                            <input type="file" class="form-control" id="apeProofFile" accept="image/jpeg,image/png,image/gif,image/webp,application/pdf">
                            <div class="form-text">Upload a screenshot or photo of your GCash payment confirmation. Max 10MB.</div>
                            <div class="mt-2 d-none" id="apeProofPreview">
                                <img id="apeProofPreviewImg" src="" alt="Proof preview" style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #ddd;">
                            </div>
                        </div>
                        <div class="alert alert-warning d-none" id="apeAlert2"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-outline-secondary d-none" id="apeBackBtn"><i class="feather-arrow-left"></i> Back</button>
                    <button type="button" class="btn btn-primary" id="apeSubmitBtn">Send Request</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="adminHomeLinkModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Home Links</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="fw-semibold" style="font-size:13px;">Status</div>
                        <div class="d-flex gap-1 align-items-center">
                            <span class="badge bg-success-subtle text-success d-none" id="ahlFreeTrialBadge">Free Trial</span>
                            <span class="badge bg-soft-secondary text-secondary" id="ahlStatusBadge">-</span>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="text-muted" style="font-size:12px;">Links: <span id="ahlDeviceCount">0</span> / 5 active</div>
                        <button type="button" class="btn btn-sm btn-primary" id="ahlNewLinkBtn"><i class="feather-plus"></i> New Link</button>
                    </div>
                    <div id="ahlLinksList"></div>
                    <div class="alert alert-warning mt-3 d-none" id="ahlAlert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Reset Password Modal (available for admin and superadmin) -->
    <?php if ($profileRole === 'admin' || $profileRole === 'superadmin'): ?>
    <div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="feather-key me-2"></i>Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="rpCurrentPassword">Current Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="rpCurrentPassword" placeholder="Enter current password" autocomplete="current-password">
                            <button type="button" class="btn btn-outline-secondary rp-toggle-pwd" data-target="rpCurrentPassword"><i class="feather-eye"></i></button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="rpNewPassword">New Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="rpNewPassword" placeholder="Enter new password (min 8 chars)" autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary rp-toggle-pwd" data-target="rpNewPassword"><i class="feather-eye"></i></button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="rpConfirmPassword">Confirm New Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="rpConfirmPassword" placeholder="Re-enter new password" autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary rp-toggle-pwd" data-target="rpConfirmPassword"><i class="feather-eye"></i></button>
                        </div>
                    </div>
                    <div class="alert alert-danger d-none" id="rpAlert"></div>
                    <div class="alert alert-success d-none" id="rpSuccess"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="rpSaveBtn"><i class="feather-save me-1"></i>Save Password</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    (function() {
        function initDarkMode() {
            var darkBtn = document.querySelector('.dark-button');
            var lightBtn = document.querySelector('.light-button');
            var htmlNode = document.documentElement;

            function applyTheme(isDark) {
                if (isDark) {
                    htmlNode.setAttribute('data-bs-theme', 'dark');
                    htmlNode.classList.add('app-skin-dark');
                    if (darkBtn) darkBtn.style.display = 'none';
                    if (lightBtn) lightBtn.style.display = 'inline-flex';
                } else {
                    htmlNode.setAttribute('data-bs-theme', 'light');
                    htmlNode.classList.remove('app-skin-dark');
                    if (darkBtn) darkBtn.style.display = 'inline-flex';
                    if (lightBtn) lightBtn.style.display = 'none';
                }
            }

            // Sync with existing localStorage key used by the template
            var savedTheme = localStorage.getItem('app-skin-dark');
            applyTheme(savedTheme === 'app-skin-dark');

            // Handle clicks
            document.body.addEventListener('click', function(e) {
                var btnDark = e.target.closest('.dark-button');
                var btnLight = e.target.closest('.light-button');

                if (btnDark) {
                    e.preventDefault();
                    localStorage.setItem('app-skin-dark', 'app-skin-dark');
                    applyTheme(true);
                } else if (btnLight) {
                    e.preventDefault();
                    localStorage.setItem('app-skin-dark', 'app-skin-light');
                    applyTheme(false);
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                initDarkMode();
                initThemeColor();
                initResetPassword();
            });
        } else {
            initDarkMode();
            initThemeColor();
            initResetPassword();
        }

        function normalizeHex(raw) {
            var s = String(raw == null ? '' : raw).trim();
            if (!s) return '';
            if (s[0] !== '#') s = '#' + s;
            if (!/^#[0-9a-fA-F]{6}$/.test(s)) return '';
            return s.toUpperCase();
        }

        function clamp(v, min, max) {
            v = Number(v);
            if (v < min) return min;
            if (v > max) return max;
            return v;
        }

        function hexToRgb(hex) {
            var h = normalizeHex(hex);
            if (!h) return null;
            return {
                r: parseInt(h.slice(1, 3), 16),
                g: parseInt(h.slice(3, 5), 16),
                b: parseInt(h.slice(5, 7), 16)
            };
        }

        function rgbToHex(r, g, b) {
            function p(n) {
                var s = clamp(Math.round(n), 0, 255).toString(16).toUpperCase();
                return s.length === 1 ? '0' + s : s;
            }
            return '#' + p(r) + p(g) + p(b);
        }

        function mix(c1, c2, t) {
            return rgbToHex(
                c1.r + (c2.r - c1.r) * t,
                c1.g + (c2.g - c1.g) * t,
                c1.b + (c2.b - c1.b) * t
            );
        }

        function applyThemeColor(baseHex) {
            var base = normalizeHex(baseHex);
            if (!base) return;
            var rgb = hexToRgb(base);
            if (!rgb) return;

            var soft = mix(rgb, { r: 255, g: 255, b: 255 }, 0.65);
            var soft2 = mix(rgb, { r: 255, g: 255, b: 255 }, 0.55);
            var accent = mix(rgb, { r: 0, g: 0, b: 0 }, 0.08);

            var root = document.documentElement;
            root.style.setProperty('--ctr-theme-base', base);
            root.style.setProperty('--ctr-theme-soft', soft);
            root.style.setProperty('--ctr-theme-soft2', soft2);
            root.style.setProperty('--ctr-theme-accent', accent);

            try {
                localStorage.setItem('ctr-theme-base', base);
            } catch (e) {}

            try {
                fetch('theme.php?ajax=1&action=set', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8', 'Accept': 'application/json' },
                    body: 'color=' + encodeURIComponent(base)
                }).catch(function () {});
            } catch (e) {}

            try {
                document.dispatchEvent(new CustomEvent('ctr:theme', { detail: { color: base } }));
            } catch (e) {}
        }

        function readCookie(name) {
            var n = String(name == null ? '' : name);
            if (!n) return '';
            var all = String(document.cookie || '');
            if (!all) return '';
            var parts = all.split(';');
            for (var i = 0; i < parts.length; i++) {
                var p = String(parts[i] || '').trim();
                if (!p) continue;
                var idx = p.indexOf('=');
                if (idx < 0) continue;
                var k = p.slice(0, idx).trim();
                if (k !== n) continue;
                var v = p.slice(idx + 1);
                try { return decodeURIComponent(v); } catch (e) { return v; }
            }
            return '';
        }

        function initThemeColor() {
            var picker = document.getElementById('themeColorPicker');
            var input = document.getElementById('themeColorText');
            var swatches = document.getElementById('themeSwatches');
            if (!picker || !input) return;

            var saved = '';
            try { saved = normalizeHex(localStorage.getItem('ctr-theme-base') || ''); } catch (e) {}
            if (!saved) saved = normalizeHex(readCookie('ctr_theme_base') || '');
            if (!saved) saved = '#2F7D28';

            picker.value = saved;
            input.value = saved;
            applyThemeColor(saved);

            picker.addEventListener('input', function () {
                var c = normalizeHex(picker.value);
                if (!c) return;
                input.value = c;
                applyThemeColor(c);
            });

            input.addEventListener('change', function () {
                var c = normalizeHex(input.value);
                if (!c) return;
                picker.value = c;
                input.value = c;
                applyThemeColor(c);
            });

            if (swatches) {
                swatches.addEventListener('click', function (e) {
                    var btn = e.target && e.target.closest ? e.target.closest('button[data-color]') : null;
                    if (!btn) return;
                    var c = normalizeHex(btn.getAttribute('data-color') || '');
                    if (!c) return;
                    picker.value = c;
                    input.value = c;
                    applyThemeColor(c);
                });
            }

            window.addEventListener('storage', function (e) {
                if (!e || e.key !== 'ctr-theme-base') return;
                var c = normalizeHex(e.newValue || '');
                if (!c) return;
                picker.value = c;
                input.value = c;
                applyThemeColor(c);
            });
        }

        function initNotifications() {
            var list = document.getElementById('liveNotiList');
            var badge = document.getElementById('liveNotiBadge');
            var clearBtn = document.getElementById('liveNotiClear');
            if (!list) return;
            if (window.__ctr_notifications_live_init === true) return;
            window.__ctr_notifications_live_init = true;

            var STORAGE_KEY = 'ctr_noti_last_read_ts';
            var LEGACY_KEY = 'ctr_noti_last_read';

            function escapeHtml(s) {
                return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            function getLastReadTs() {
                try {
                    var v = localStorage.getItem(STORAGE_KEY);
                    if (v != null && String(v).trim() !== '') {
                        var n = parseInt(String(v), 10);
                        return isFinite(n) && n > 0 ? n : 0;
                    }
                } catch (e) {}
                try {
                    var legacy = localStorage.getItem(LEGACY_KEY);
                    if (legacy != null && String(legacy).trim() !== '') {
                        var d = new Date(String(legacy));
                        var t = d && d.getTime ? d.getTime() : NaN;
                        if (isFinite(t) && t > 0) {
                            localStorage.setItem(STORAGE_KEY, String(t));
                            return t;
                        }
                    }
                } catch (e2) {}
                return 0;
            }

            function setLastReadTs(ts) {
                var n = Number(ts);
                if (!isFinite(n) || n <= 0) n = Date.now();
                try { localStorage.setItem(STORAGE_KEY, String(Math.floor(n))); } catch (e) {}
            }

            function fetchNotifications(force) {
                var lastReadTs = getLastReadTs();
                var url = 'notifications.php?action=list';
                // Cache the most recent payload for ~5s so back-to-back
                // polls (visible tab + badge refresh) don't keep
                // round-tripping the server for the same data. The cache is
                // bypassed (force) right after sending a notification so the
                // new item shows up instantly instead of from stale data.
                var cacheKey = 'ctr:notif:list';
                var cached = null;
                if (!force) {
                    try {
                        var raw = sessionStorage.getItem(cacheKey);
                        if (raw) {
                            var entry = JSON.parse(raw);
                            if (entry && entry.expiresAt && entry.expiresAt > Date.now() && entry.data) {
                                cached = entry.data;
                            }
                        }
                    } catch (eC) {}
                } else {
                    try { sessionStorage.removeItem(cacheKey); } catch (eR) {}
                }
                function writeCache(data) {
                    try {
                        sessionStorage.setItem(cacheKey, JSON.stringify({ data: data, expiresAt: Date.now() + 5000 }));
                    } catch (eS) {}
                }
                function render(res) {
                    if (!res.ok || !res.notifications) {
                        list.innerHTML = '<div class="text-center text-muted small py-3">No notifications yet.</div>';
                        if (badge) { badge.textContent = '0'; badge.classList.add('d-none'); }
                        return;
                    }
                    var notifs = Array.isArray(res.notifications) ? res.notifications : [];
                    var unreadCount = 0;
                    for (var i = 0; i < notifs.length; i++) {
                        var n = notifs[i];
                        var cts = n && n.created_ts != null ? parseInt(String(n.created_ts), 10) : 0;
                        if (cts && (!lastReadTs || cts > lastReadTs)) unreadCount++;
                    }
                    if (badge) {
                        badge.textContent = String(unreadCount);
                        if (unreadCount > 0) badge.classList.remove('d-none');
                        else badge.classList.add('d-none');
                    }
                    var html = '';
                    for (var j = 0; j < notifs.length; j++) {
                        var n = notifs[j];
                        var cts2 = n && n.created_ts != null ? parseInt(String(n.created_ts), 10) : 0;
                        var isUnread = cts2 && (!lastReadTs || cts2 > lastReadTs);
                        html += '<div class="d-flex align-items-start gap-2 py-2 border-bottom' + (isUnread ? ' bg-soft-primary' : '') + '">'
                            + '<div class="flex-grow-1">'
                            + '<div class="fw-semibold small">' + escapeHtml(n.name) + '</div>'
                            + '<div class="text-muted" style="font-size:12px;">' + escapeHtml(n.message) + '</div>'
                            + '</div>'
                            + '<div class="text-muted small text-nowrap">' + escapeHtml(n.time) + '</div>'
                            + '</div>';
                    }
                    list.innerHTML = html || '<div class="text-center text-muted small py-3">No notifications yet.</div>';
                }
                if (cached) {
                    render(cached);
                    return;
                }
                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        writeCache(res);
                        render(res);
                    })
                    .catch(function () {
                        list.innerHTML = '<div class="text-center text-muted small py-3">Failed to load.</div>';
                    });
            }

            fetchNotifications();
            var pollMs = 2500;
            var pollTimer = null;
            function startPoll() {
                if (pollTimer) return;
                pollTimer = setInterval(function () {
                    if (document.hidden) return;
                    fetchNotifications();
                }, pollMs);
            }
            startPoll();
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) fetchNotifications();
            });
            window.addEventListener('focus', function () {
                fetchNotifications();
            });
            window.addEventListener('storage', function (e) {
                if (!e) return;
                if (e.key === STORAGE_KEY || e.key === LEGACY_KEY) {
                    fetchNotifications();
                }
            });

            var toggle = document.getElementById('liveNotiToggle');
            if (toggle) {
                toggle.addEventListener('click', function () {
                    setTimeout(fetchNotifications, 100);
                });
            }

            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    if (badge) { badge.textContent = '0'; badge.classList.add('d-none'); }
                    setLastReadTs(Date.now());
                    fetchNotifications();
                });
            }

            var addBtn = document.getElementById('addNotiBtn');
            var addForm = document.getElementById('addNotiForm');
            var sendBtn = document.getElementById('sendNotiBtn');
            if (addBtn && addForm) {
                addBtn.addEventListener('click', function () {
                    addForm.style.display = addForm.style.display === 'none' ? 'block' : 'none';
                });
            }
            if (sendBtn) {
                sendBtn.addEventListener('click', function () {
                    var titleEl = document.getElementById('notiTitle');
                    var bodyEl = document.getElementById('notiBody');
                    var title = titleEl ? titleEl.value.trim() : '';
                    var body = bodyEl ? bodyEl.value.trim() : '';
                    if (!title) { if (titleEl) titleEl.focus(); return; }
                    sendBtn.disabled = true;
                    sendBtn.textContent = 'Sending...';
                    var fd = new FormData();
                    fd.append('action', 'create');
                    fd.append('title', title);
                    fd.append('body', body);
                    fetch('notifications.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            sendBtn.disabled = false;
                            sendBtn.textContent = 'Send Notification';
                            if (res.ok) {
                                if (titleEl) titleEl.value = '';
                                if (bodyEl) bodyEl.value = '';
                                addForm.style.display = 'none';
                                fetchNotifications(true);
                            } else {
                                alert(res.message || 'Failed to send');
                            }
                        })
                        .catch(function () {
                            sendBtn.disabled = false;
                            sendBtn.textContent = 'Send Notification';
                            alert('Network error');
                        });
                });
            }
        }

        function initPlanExtensionRequests() {
            var list = document.getElementById('planExtList');
            var badge = document.getElementById('planExtBadge');
            if (!list) return;
            if (window.__ctr_plan_ext_live_init === true) return;
            window.__ctr_plan_ext_live_init = true;

            function escapeHtml(s) {
                return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            function fetchJson(url, opts) {
                return fetch(url, Object.assign({
                    headers: Object.assign({ 'Accept': 'application/json' }, (opts && opts.headers) || {})
                }, opts || {})).then(function (r) { return r.json(); });
            }

            var modal = document.getElementById('saPlanExtendRequestModal');
            var currentRecordId = 0;

            function el(id) { return document.getElementById(id); }

            function setPlanAlert(msg) {
                var a = el('saPeAlert');
                if (!a) return;
                var t = String(msg == null ? '' : msg).trim();
                if (!t) {
                    a.classList.add('d-none');
                    a.textContent = '';
                    return;
                }
                a.textContent = t;
                a.classList.remove('d-none');
            }

            function setVal(id, val) {
                var x = el(id);
                if (!x) return;
                x.value = String(val == null ? '' : val);
            }

            function formatPlanExpiry(raw) {
                var v = String(raw == null ? '' : raw).trim();
                if (!v) return '-';

                var m = v.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
                if (!m) return v;

                var year = parseInt(m[1], 10);
                var month = parseInt(m[2], 10);
                var day = parseInt(m[3], 10);
                var hour24 = parseInt(m[4], 10);
                var minute = parseInt(m[5], 10);
                if (!isFinite(year) || !isFinite(month) || !isFinite(day) || !isFinite(hour24) || !isFinite(minute)) return v;

                var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                var monthName = month >= 1 && month <= 12 ? monthNames[month - 1] : '';
                if (!monthName) return v;

                var hour12 = hour24 % 12;
                if (hour12 === 0) hour12 = 12;
                var meridiem = hour24 >= 12 ? 'PM' : 'AM';
                var minuteText = minute < 10 ? ('0' + String(minute)) : String(minute);

                return monthName + ' ' + String(day) + ', ' + String(year) + ' at ' + String(hour12) + ':' + minuteText + ' ' + meridiem;
            }

            function statusBadge(status) {
                var s = String(status || '').toLowerCase();
                if (s === 'paid') return '<span class="badge bg-success-subtle text-success" style="font-size:11px;">Paid</span>';
                if (s === 'failed') return '<span class="badge bg-danger-subtle text-danger" style="font-size:11px;">Failed</span>';
                return '<span class="badge bg-warning-subtle text-warning-emphasis" style="font-size:11px;">Pending</span>';
            }

            function openPlanModal(paymentId) {
                if (!modal) return;
                currentRecordId = paymentId;
                setPlanAlert('');
                setVal('saPeAdminName', 'Loading...');
                setVal('saPeAdminIdNumber', '');
                setVal('saPeMonths', '');
                setVal('saPeCurrentExpiry', '');
                setVal('saPeNewExpiry', '');
                setVal('saPeAmount', '');
                setVal('saPeProvider', '');
                setVal('saPePaidAt', '');
                setVal('saPeNote', '');
                if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();

                fetchJson('notifications.php?action=get_plan_payment_record&payment_id=' + encodeURIComponent(String(paymentId)), { method: 'GET' })
                    .then(function (res) {
                        if (!res || res.ok !== true || !res.record) {
                            setPlanAlert((res && res.message) ? res.message : 'Failed to load payment record.');
                            setVal('saPeAdminName', '');
                            return;
                        }
                        var r = res.record || {};
                        setVal('saPeAdminName', r.admin_name || '');
                        setVal('saPeAdminIdNumber', r.admin_id_number || '');
                        setVal('saPeMonths', '+' + (r.months != null ? r.months : 0) + ' month' + (parseInt(r.months, 10) === 1 ? '' : 's'));
                        setVal('saPeCurrentExpiry', formatPlanExpiry(r.plan_expires_at || ''));
                        setVal('saPeNewExpiry', r.request_resolved_at ? formatPlanExpiry(r.request_resolved_at) : 'Not yet approved');
                        setVal('saPeAmount', '₱' + parseInt(r.amount_php || 0, 10).toLocaleString());
                        var provider = String(r.provider || '');
                        setVal('saPeProvider', provider === 'paymongo' ? 'GCash (PayMongo)' : (provider || '-'));
                        setVal('saPePaidAt', r.paid_at ? formatPlanExpiry(r.paid_at) : 'Not paid yet');
                        setVal('saPeNote', r.note || '');
                    })
                    .catch(function () {
                        setPlanAlert('Network error. Please try again.');
                        setVal('saPeAdminName', '');
                    });
            }

            function fetchRequests() {
                fetchJson('notifications.php?action=list_plan_payment_records', { method: 'GET' })
                    .then(function (res) {
                        if (!res || res.ok !== true || !res.records) {
                            list.innerHTML = '<div class="text-center text-muted small py-3">No payment records yet.</div>';
                            if (badge) { badge.textContent = '0'; badge.classList.add('d-none'); }
                            return;
                        }
                        var recs = Array.isArray(res.records) ? res.records : [];
                        var cnt = recs.length;
                        if (badge) {
                            badge.textContent = String(cnt);
                            if (cnt > 0) badge.classList.remove('d-none');
                            else badge.classList.add('d-none');
                        }
                        var html = '';
                        for (var i = 0; i < recs.length; i++) {
                            var r = recs[i] || {};
                            var id = r.id != null ? parseInt(String(r.id), 10) : 0;
                            if (!isFinite(id) || id <= 0) continue;
                            var name = String(r.admin_name || 'Admin');
                            var idnum = String(r.admin_id_number || '');
                            var months = r.months != null ? parseInt(String(r.months), 10) : 0;
                            if (!isFinite(months) || months <= 0) months = 1;
                            var amount = parseInt(String(r.amount_php || 0), 10) || 0;
                            var note = String(r.note || '').trim();
                            var msg = name + (idnum ? (' (' + idnum + ')') : '') + ' paid ₱' + amount.toLocaleString() + ' for +' + String(months) + ' month' + (months === 1 ? '' : 's') + (note ? (' — ' + note) : '');
                            var time = String(r.time || '');
                            html += '<div class="d-flex align-items-start gap-2 py-2 border-bottom" data-plan-request-id="' + String(id) + '" role="button" style="cursor:pointer;">'
                                + '<div class="flex-grow-1">'
                                + '<div class="fw-semibold small">Plan Payment ' + statusBadge(r.status) + '</div>'
                                + '<div class="text-muted" style="font-size:12px;">' + escapeHtml(msg) + '</div>'
                                + '</div>'
                                + '<div class="text-muted small text-nowrap">' + escapeHtml(time ? time : '') + '</div>'
                                + '</div>';
                        }
                        list.innerHTML = html || '<div class="text-center text-muted small py-3">No payment records yet.</div>';
                    })
                    .catch(function () {
                        list.innerHTML = '<div class="text-center text-muted small py-3">Failed to load.</div>';
                    });
            }

            fetchRequests();
            var pollMs = 2500;
            var pollTimer = null;
            function startPoll() {
                if (pollTimer) return;
                pollTimer = setInterval(function () {
                    if (document.hidden) return;
                    fetchRequests();
                }, pollMs);
            }
            startPoll();
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) fetchRequests();
            });
            window.addEventListener('focus', function () {
                fetchRequests();
            });

            var toggle = document.getElementById('planExtToggle');
            if (toggle) {
                toggle.addEventListener('click', function () {
                    setTimeout(fetchRequests, 100);
                });
            }

            list.addEventListener('click', function (e) {
                var item = e.target && e.target.closest ? e.target.closest('[data-plan-request-id]') : null;
                if (!item) return;
                var id = parseInt(String(item.getAttribute('data-plan-request-id') || '0'), 10);
                if (!isFinite(id) || id <= 0) return;
                openPlanModal(id);
            });
        }

        function initSaDashboardCharts() {
            var btn = document.getElementById('saDashboardChartsBtn');
            var modal = document.getElementById('saDashboardChartsModal');
            var refreshBtn = document.getElementById('saChartsRefreshBtn');
            if (!btn || !modal) return;
            if (window.__ctr_sa_charts_init === true) return;
            window.__ctr_sa_charts_init = true;

            var chartInstances = {};

            function escapeHtml(s) {
                return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            function setText(id, val) {
                var el = document.getElementById(id);
                if (el) el.textContent = String(val);
            }

            function getBaseColor() {
                var root = document.documentElement;
                var v = getComputedStyle(root).getPropertyValue('--ctr-theme-base');
                return (v || '').trim() || '#2F7D28';
            }

            function destroyCharts() {
                Object.keys(chartInstances).forEach(function (k) {
                    if (chartInstances[k] && chartInstances[k].destroy) {
                        try { chartInstances[k].destroy(); } catch (e) {}
                    }
                });
                chartInstances = {};
            }

            function truncateName(name, max) {
                name = String(name || '');
                max = max || 18;
                return name.length > max ? name.substring(0, max) + '...' : name;
            }

            function renderCharts(stats) {
                destroyCharts();
                var baseColor = getBaseColor();

                // 1. Donut - Today's attendance breakdown
                var donutPresent = parseInt(stats.attendance_today) || 0;
                var donutAbsent = parseInt(stats.absences_today) || 0;
                var donutTotal = donutPresent + donutAbsent;
                var donutPercent = donutTotal > 0 ? Math.round((donutPresent / donutTotal) * 100) : 0;

                chartInstances.donut = new ApexCharts(document.querySelector('#saChartDonutToday'), {
                    series: [donutPresent, donutAbsent],
                    chart: { type: 'donut', height: 280 },
                    labels: ['Present', 'Absent'],
                    colors: ['#198754', '#dc3545'],
                    plotOptions: {
                        pie: {
                            donut: {
                                size: '70%',
                                labels: {
                                    show: true,
                                    name: { show: true, fontSize: '14px' },
                                    value: { show: true, fontSize: '22px', fontWeight: 700 },
                                    total: {
                                        show: true,
                                        label: 'Attendance Rate',
                                        formatter: function () { return donutPercent + '%'; }
                                    }
                                }
                            }
                        }
                    },
                    legend: { position: 'bottom', fontSize: '13px' },
                    dataLabels: { enabled: false },
                    stroke: { width: 2, colors: ['#fff'] },
                    responsive: [{ breakpoint: 480, options: { chart: { height: 260 } } }]
                });
                chartInstances.donut.render();

                // 2. Bar - AM vs PM
                chartInstances.barAmPm = new ApexCharts(document.querySelector('#saChartBarAmPm'), {
                    series: [{
                        name: 'Check-ins',
                        data: [parseInt(stats.time_in_am) || 0, parseInt(stats.time_in_pm) || 0]
                    }],
                    chart: { type: 'bar', height: 280, toolbar: { show: false } },
                    plotOptions: {
                        bar: { borderRadius: 6, columnWidth: '50%', distributed: true }
                    },
                    xaxis: { categories: ['Morning (AM)', 'Afternoon (PM)'], labels: { style: { fontSize: '13px' } } },
                    yaxis: { labels: { style: { fontSize: '12px' } } },
                    colors: ['#0dcaf0', '#ffc107'],
                    legend: { show: false },
                    dataLabels: { enabled: true, style: { fontSize: '14px', fontWeight: 700 } },
                    grid: { borderColor: '#f0f0f0' }
                });
                chartInstances.barAmPm.render();

                // 3. Bar - Most Active Users
                var activeList = stats.most_active_list || [];
                var activeNames = [];
                var activeCounts = [];
                for (var i = 0; i < activeList.length; i++) {
                    activeNames.push(truncateName(activeList[i].full_name, 15));
                    activeCounts.push(parseInt(activeList[i].cnt) || 0);
                }
                if (activeNames.length === 0) { activeNames = ['No data']; activeCounts = [0]; }

                chartInstances.barActive = new ApexCharts(document.querySelector('#saChartBarActive'), {
                    series: [{ name: 'Records', data: activeCounts }],
                    chart: { type: 'bar', height: 280, toolbar: { show: false } },
                    plotOptions: {
                        bar: { borderRadius: 4, horizontal: true, distributed: true }
                    },
                    xaxis: { categories: activeNames, labels: { style: { fontSize: '11px' } } },
                    yaxis: { labels: { style: { fontSize: '11px' } } },
                    colors: ['#2f7d28', '#198754', '#20c997', '#0dcaf0', '#6f42c1'],
                    legend: { show: false },
                    dataLabels: { enabled: true, style: { fontSize: '13px', fontWeight: 700 } },
                    grid: { borderColor: '#f0f0f0' }
                });
                chartInstances.barActive.render();

                // 4. Bar - Most Absences
                var absencesList = stats.most_absences_list || [];
                var absNames = [];
                var absDays = [];
                for (var j = 0; j < absencesList.length; j++) {
                    absNames.push(truncateName(absencesList[j].name, 15));
                    absDays.push(parseInt(absencesList[j].absence_days) || 0);
                }
                if (absNames.length === 0) { absNames = ['No data']; absDays = [0]; }

                chartInstances.barAbsences = new ApexCharts(document.querySelector('#saChartBarAbsences'), {
                    series: [{ name: 'Absences', data: absDays }],
                    chart: { type: 'bar', height: 280, toolbar: { show: false } },
                    plotOptions: {
                        bar: { borderRadius: 4, horizontal: true, distributed: true }
                    },
                    xaxis: { categories: absNames, labels: { style: { fontSize: '11px' } } },
                    yaxis: { labels: { style: { fontSize: '11px' } } },
                    colors: ['#dc3545', '#e74c3c', '#ff6b6b', '#ff8787', '#ffa8a8'],
                    legend: { show: false },
                    dataLabels: { enabled: true, style: { fontSize: '13px', fontWeight: 700 } },
                    grid: { borderColor: '#f0f0f0' }
                });
                chartInstances.barAbsences.render();

                // 5. Area - Attendance Overview
                var totalUsers = parseInt(stats.total_users) || 0;
                var monthAtt = parseInt(stats.attendance_month) || 0;
                var workingDays = parseInt(stats.working_days) || 1;
                var attendanceRate = totalUsers > 0 ? Math.round((monthAtt / totalUsers) * 100 / workingDays) : 0;

                chartInstances.areaOverview = new ApexCharts(document.querySelector('#saChartAreaOverview'), {
                    series: [{
                        name: 'Attendance Rate %',
                        data: [attendanceRate]
                    }],
                    chart: { type: 'area', height: 300, toolbar: { show: false } },
                    stroke: { curve: 'smooth', width: 3, colors: [baseColor] },
                    fill: {
                        type: 'gradient',
                        gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05, stops: [0, 90, 100] }
                    },
                    xaxis: { categories: [attendanceRate + '%'], labels: { style: { fontSize: '14px' } } },
                    yaxis: { min: 0, max: 100, labels: { style: { fontSize: '12px' }, formatter: function (v) { return v + '%'; } } },
                    colors: [baseColor],
                    dataLabels: { enabled: false },
                    grid: { borderColor: '#f0f0f0' },
                    annotations: {
                        yaxis: [{
                            y: 75,
                            borderColor: '#198754',
                            strokeDashArray: 4,
                            label: { text: 'Target: 75%', style: { color: '#198754', fontSize: '11px' } }
                        }]
                    }
                });
                chartInstances.areaOverview.render();
            }

            function fetchAndRender() {
                setText('saChartTotalUsers', '...');
                setText('saChartPresentToday', '...');
                setText('saChartAbsentToday', '...');
                setText('saChartAm', '...');
                setText('saChartPm', '...');
                setText('saChartMonthAtt', '...');
                setText('saChartsUpdatedAt', '');

                fetch('index.php?ajax=1&action=get_stats', { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok || !res.stats) {
                            setText('saChartsUpdatedAt', 'Failed to load data.');
                            return;
                        }
                        var s = res.stats;
                        setText('saChartTotalUsers', s.total_users != null ? s.total_users : '-');
                        setText('saChartPresentToday', s.attendance_today != null ? s.attendance_today : '-');
                        setText('saChartAbsentToday', s.absences_today != null ? s.absences_today : '-');
                        setText('saChartAm', s.time_in_am != null ? s.time_in_am : '-');
                        setText('saChartPm', s.time_in_pm != null ? s.time_in_pm : '-');
                        setText('saChartMonthAtt', s.attendance_month != null ? s.attendance_month : '-');
                        setText('saChartsUpdatedAt', 'Updated: ' + (res.server_time || '-'));
                        renderCharts(s);
                    })
                    .catch(function () {
                        setText('saChartsUpdatedAt', 'Network error. Please try again.');
                    });
            }

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                if (window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).show();
                }
                fetchAndRender();
            });

            if (refreshBtn) {
                refreshBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    fetchAndRender();
                });
            }

            modal.addEventListener('hidden.bs.modal', function () {
                destroyCharts();
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                initDarkMode();
                initThemeColor();
                initNotifications();
                initPlanExtensionRequests();
                initSaDashboardCharts();
            });
        } else {
            initDarkMode();
            initThemeColor();
            initNotifications();
            initPlanExtensionRequests();
            initSaDashboardCharts();
            initResetPassword();
        }

        /* ── Reset Password ── */
        function initResetPassword() {
            var rpBtn = document.getElementById('resetPasswordBtn');
            if (!rpBtn) return;

            // Eye toggle for password fields
            document.body.addEventListener('click', function(e) {
                var toggleBtn = e.target.closest('.rp-toggle-pwd');
                if (!toggleBtn) return;
                e.preventDefault();
                var targetId = toggleBtn.getAttribute('data-target');
                var input = targetId ? document.getElementById(targetId) : null;
                if (!input) return;
                var icon = toggleBtn.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    if (icon) { icon.classList.remove('feather-eye'); icon.classList.add('feather-eye-off'); }
                } else {
                    input.type = 'password';
                    if (icon) { icon.classList.remove('feather-eye-off'); icon.classList.add('feather-eye'); }
                }
            });

            rpBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var modal = document.getElementById('resetPasswordModal');
                if (!modal) return;
                // Clear fields
                var f1 = document.getElementById('rpCurrentPassword');
                var f2 = document.getElementById('rpNewPassword');
                var f3 = document.getElementById('rpConfirmPassword');
                if (f1) f1.value = '';
                if (f2) f2.value = '';
                if (f3) f3.value = '';
                var al = document.getElementById('rpAlert');
                var ok = document.getElementById('rpSuccess');
                if (al) { al.classList.add('d-none'); al.textContent = ''; }
                if (ok) { ok.classList.add('d-none'); ok.textContent = ''; }
                var saveBtn = document.getElementById('rpSaveBtn');
                if (saveBtn) saveBtn.disabled = false;
                if (window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).show();
                }
            });

            var saveBtn = document.getElementById('rpSaveBtn');
            if (saveBtn) {
                saveBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var current = (document.getElementById('rpCurrentPassword') || {}).value || '';
                    var newPwd = (document.getElementById('rpNewPassword') || {}).value || '';
                    var confirm = (document.getElementById('rpConfirmPassword') || {}).value || '';
                    var al = document.getElementById('rpAlert');
                    var ok = document.getElementById('rpSuccess');
                    if (al) { al.classList.add('d-none'); al.textContent = ''; }
                    if (ok) { ok.classList.add('d-none'); ok.textContent = ''; }

                    if (!current.trim() || !newPwd.trim() || !confirm.trim()) {
                        if (al) { al.textContent = 'All fields are required.'; al.classList.remove('d-none'); }
                        return;
                    }
                    if (newPwd !== confirm) {
                        if (al) { al.textContent = 'New password and confirm password do not match.'; al.classList.remove('d-none'); }
                        return;
                    }
                    if (newPwd.length < 8) {
                        if (al) { al.textContent = 'New password must be at least 8 characters.'; al.classList.remove('d-none'); }
                        return;
                    }

                    saveBtn.disabled = true;
                    saveBtn.innerHTML = '<i class="feather-loader me-1"></i>Saving...';

                    var fd = new FormData();
                    fd.append('action', 'change_my_password');
                    fd.append('current_password', current);
                    fd.append('new_password', newPwd);
                    fd.append('confirm_password', confirm);

                    fetch('users.php?ajax=1&action=change_my_password', {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: fd
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res && res.ok) {
                            if (ok) { ok.textContent = 'Password updated successfully! Redirecting to login...'; ok.classList.remove('d-none'); }
                            if (al) al.classList.add('d-none');
                            setTimeout(function() {
                                window.location.href = <?php echo json_encode(ctr_url('logout')); ?>;
                            }, 1500);
                        } else {
                            var msg = (res && res.message) ? res.message : 'Failed to update password.';
                            if (al) { al.textContent = msg; al.classList.remove('d-none'); }
                            saveBtn.disabled = false;
                            saveBtn.innerHTML = '<i class="feather-save me-1"></i>Save Password';
                        }
                    })
                    .catch(function() {
                        if (al) { al.textContent = 'Network error. Please try again.'; al.classList.remove('d-none'); }
                        saveBtn.disabled = false;
                        saveBtn.innerHTML = '<i class="feather-save me-1"></i>Save Password';
                    });
                });
            }
        }
    })();
    </script>
