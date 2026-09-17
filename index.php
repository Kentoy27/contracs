<?php
require __DIR__ . '/includes/cache_headers.php';
require 'config/db.php';

ctr_session_start();

ctr_canonicalize('index');

// Dynamic dashboard page: short edge cache so an auto-refresh can
// re-use the response body for a few seconds without re-running the
// heavy SQL aggregates on every poll.
ctr_cache_headers('short', 15);

date_default_timezone_set('Asia/Manila');

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function ensure_admin_home_title_schema_safe(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        $exists = $stmt ? $stmt->fetchColumn() : false;
        if (!$exists) {
            return;
        }
        $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
        $existing = [];
        foreach ($cols as $col) {
            $existing[strtolower((string)($col['Field'] ?? ''))] = true;
        }
        if (!isset($existing['admin_home_title'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN admin_home_title VARCHAR(255) NULL");
        }
    } catch (Throwable $e) {
    }
}

$loggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
if (!$loggedIn) {
    if (isset($_GET['ajax']) && (string)($_GET['ajax'] ?? '') === '1') {
        json_response(['ok' => false, 'message' => 'Unauthorized.'], 401);
    }
    header('Location: ' . ctr_url('login'));
    exit;
}

$sessionRole = (string)($_SESSION['role'] ?? '');
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$isAjax = isset($_GET['ajax']) && (string)($_GET['ajax'] ?? '') === '1';
$adminHomeTitle = '';

ensure_admin_home_title_schema_safe($pdo);

if (!$isAjax && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_admin_home_title') {
    if ($sessionRole !== 'admin' || $sessionUserId <= 0) {
        $_SESSION['admin_home_title_flash'] = ['type' => 'danger', 'message' => 'Only admin can update the home title.'];
        header('Location: ' . ctr_url('index'));
        exit;
    }
    ctr_csrf_check();

    $adminHomeTitleInput = trim((string)($_POST['admin_home_title'] ?? ''));
    if (strlen($adminHomeTitleInput) > 255) {
        $adminHomeTitleInput = substr($adminHomeTitleInput, 0, 255);
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET admin_home_title = :title WHERE id = :id AND role = 'admin'");
        $stmt->execute([
            ':title' => ($adminHomeTitleInput !== '' ? $adminHomeTitleInput : null),
            ':id' => $sessionUserId,
        ]);
        $_SESSION['admin_home_title_flash'] = [
            'type' => 'success',
            'message' => $adminHomeTitleInput !== ''
                ? 'Home title updated successfully.'
                : 'Home title reset to default successfully.',
        ];
    } catch (Throwable $e) {
        $_SESSION['admin_home_title_flash'] = ['type' => 'danger', 'message' => 'Failed to update the home title.'];
    }

    header('Location: ' . ctr_url('index'));
    exit;
}

if ($sessionRole === 'admin' && $sessionUserId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT admin_home_title FROM users WHERE id = :id AND role = 'admin' LIMIT 1");
        $stmt->execute([':id' => $sessionUserId]);
        $adminHomeTitle = trim((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $adminHomeTitle = '';
    }
}

$adminHomeTitleFlash = null;
if (!$isAjax && isset($_SESSION['admin_home_title_flash']) && is_array($_SESSION['admin_home_title_flash'])) {
    $adminHomeTitleFlash = $_SESSION['admin_home_title_flash'];
    unset($_SESSION['admin_home_title_flash']);
}

// Check admin plan status and set session flags
$adminIsActive = true;
$adminPlanActive = true;
$adminNeedsPlanRequest = false;

if ($sessionRole === 'admin' && $sessionUserId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT is_active, plan_expires_at FROM users WHERE id = :id AND role = 'admin' LIMIT 1");
        $stmt->execute([':id' => $sessionUserId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $adminIsActive = (int)($u['is_active'] ?? 0) === 1;
            $expRaw = $u['plan_expires_at'] ?? null;
            $expStr = is_string($expRaw) ? trim($expRaw) : '';
            if ($expStr !== '') {
                $tz = new DateTimeZone('Asia/Manila');
                $now = new DateTimeImmutable('now', $tz);
                $exp = new DateTimeImmutable($expStr, $tz);
                $adminPlanActive = $exp > $now;
            } else {
                $adminPlanActive = false;
            }
            $adminNeedsPlanRequest = !$adminIsActive || !$adminPlanActive;
        }
    } catch (Throwable $e) {
        $adminNeedsPlanRequest = true;
    }
}

// Store in session for use in other pages
$_SESSION['admin_is_active'] = $adminIsActive;
$_SESSION['admin_plan_active'] = $adminPlanActive;
$_SESSION['admin_needs_plan_request'] = $adminNeedsPlanRequest;

// AJAX handler for dashboard statistics
if (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1') {
    ctr_cache_headers('api', 15);
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');
    $monthStart = $now->format('Y-m-01');

    try {
        if ($action === 'save_admin_home_title') {
            if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
            }
            if ($sessionRole !== 'admin' || $sessionUserId <= 0) {
                json_response(['ok' => false, 'message' => 'Only admin can update the home title.'], 403);
            }
            ctr_csrf_check();

            $adminHomeTitleInput = trim((string)($_POST['admin_home_title'] ?? ''));
            if (strlen($adminHomeTitleInput) > 255) {
                $adminHomeTitleInput = substr($adminHomeTitleInput, 0, 255);
            }

            $stmt = $pdo->prepare("UPDATE users SET admin_home_title = :title WHERE id = :id AND role = 'admin'");
            $stmt->execute([
                ':title' => ($adminHomeTitleInput !== '' ? $adminHomeTitleInput : null),
                ':id' => $sessionUserId,
            ]);

            json_response([
                'ok' => true,
                'title' => $adminHomeTitleInput,
                'default_title' => 'SCHOOLS DIVISION OFFICE OF KABANKALAN CITY',
                'message' => $adminHomeTitleInput !== ''
                    ? 'Home title updated successfully.'
                    : 'Home title reset to default successfully.',
            ]);
        }

        if ($action === 'get_stats') {
            $isAdmin = $sessionRole !== 'superadmin';
            $userFilter = '';
            $userParams = [];
            if ($isAdmin) {
                $userFilter = ' AND created_by = :created_by';
                $userParams[':created_by'] = $sessionUserId;
            }
            $attUserFilter = '';
            $attParams = [];
            if ($isAdmin) {
                $attUserFilter = ' AND user_id IN (SELECT id FROM users WHERE created_by = :created_by OR id = :self_id)';
                $attParams[':created_by'] = $sessionUserId;
                $attParams[':self_id'] = $sessionUserId;
            }

            // Total Registered Users (exclude superadmin)
            if ($isAdmin) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role != 'superadmin' AND (created_by = :created_by OR id = :self_id)");
                $stmt->execute(array_merge($userParams, [':self_id' => $sessionUserId]));
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role != 'superadmin'");
                $stmt->execute();
            }
            $totalUsers = (int)$stmt->fetchColumn();

            // Total Attendance Today
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM attendance_logs WHERE attend_date = :today" . $attUserFilter);
            $attTodayParams = array_merge([':today' => $today], $attParams);
            $stmt->execute($attTodayParams);
            $attendanceToday = (int)$stmt->fetchColumn();

            // Total Time In AM Today
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_logs WHERE attend_date = :today AND session = 'morning' AND scan_type = 'in' AND time_in IS NOT NULL" . $attUserFilter);
            $stmt->execute($attTodayParams);
            $timeInAm = (int)$stmt->fetchColumn();

            // Total Time In PM Today
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_logs WHERE attend_date = :today AND session = 'afternoon' AND scan_type = 'in' AND time_in IS NOT NULL" . $attUserFilter);
            $stmt->execute($attTodayParams);
            $timeInPm = (int)$stmt->fetchColumn();

            // Total Absences Today (users who haven't logged in, exclude superadmin)
            $absParams = array_merge([':today' => $today], $userParams);
            if ($isAdmin) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_active = 1 AND role != 'superadmin' AND (created_by = :created_by OR id = :self_id) AND id NOT IN (SELECT DISTINCT user_id FROM attendance_logs WHERE attend_date = :today)");
                $absParams[':self_id'] = $sessionUserId;
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_active = 1 AND role != 'superadmin' AND id NOT IN (SELECT DISTINCT user_id FROM attendance_logs WHERE attend_date = :today)");
            }
            $stmt->execute($absParams);
            $absencesToday = (int)$stmt->fetchColumn();

            // Total Attendance This Month
            $attMonthParams = array_merge([':monthStart' => $monthStart], $attParams);
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM attendance_logs WHERE attend_date >= :monthStart" . $attUserFilter);
            $stmt->execute($attMonthParams);
            $attendanceMonth = (int)$stmt->fetchColumn();

            // Most Active User This Month (top 5)
            $stmt = $pdo->prepare("SELECT al.user_id, al.full_name, COUNT(*) AS cnt FROM attendance_logs al WHERE al.attend_date >= :monthStart" . $attUserFilter . " GROUP BY al.user_id, al.full_name ORDER BY cnt DESC, al.full_name ASC LIMIT 5");
            $stmt->execute($attMonthParams);
            $mostActiveList = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $mostActiveTop = $mostActiveList[0] ?? null;
            $mostActiveUser = $mostActiveTop ? $mostActiveTop['full_name'] : '-';
            $mostActiveCount = $mostActiveTop ? (int)$mostActiveTop['cnt'] : 0;

            // Most Absences This Month (top 5) - days elapsed in current month
            $workingDays = (int)$now->format('d');
            if ($workingDays < 1) $workingDays = 1;

            $absMonthParams = array_merge([
                ':monthStart' => $monthStart,
                ':today' => $today,
                ':workingDays' => $workingDays,
            ], $attParams);
            $isAdminAbs = $sessionRole !== 'superadmin';
            if ($isAdminAbs) {
                $absMonthParams[':created_by'] = $sessionUserId;
                $absMonthParams[':self_id'] = $sessionUserId;
                $sqlAbsMonth = "SELECT u.id, u.username, u.name, u.id_number, u.position, u.department,
                                       COALESCE(att.attended_days, 0) AS attended_days,
                                       (:workingDays - COALESCE(att.attended_days, 0)) AS absence_days
                                FROM users u
                                LEFT JOIN (
                                    SELECT user_id, COUNT(DISTINCT attend_date) AS attended_days
                                    FROM attendance_logs
                                    WHERE attend_date >= :monthStart AND attend_date <= :today
                                    GROUP BY user_id
                                ) att ON att.user_id = u.id
                                WHERE u.is_active = 1
                                  AND u.role != 'superadmin'
                                  AND (u.created_by = :created_by OR u.id = :self_id)
                                ORDER BY absence_days DESC, attended_days ASC, u.name ASC
                                LIMIT 5";
            } else {
                $sqlAbsMonth = "SELECT u.id, u.username, u.name, u.id_number, u.position, u.department, u.created_by,
                                       COALESCE(att.attended_days, 0) AS attended_days,
                                       (:workingDays - COALESCE(att.attended_days, 0)) AS absence_days
                                FROM users u
                                LEFT JOIN (
                                    SELECT user_id, COUNT(DISTINCT attend_date) AS attended_days
                                    FROM attendance_logs
                                    WHERE attend_date >= :monthStart AND attend_date <= :today
                                    GROUP BY user_id
                                ) att ON att.user_id = u.id
                                WHERE u.is_active = 1
                                  AND u.role != 'superadmin'
                                ORDER BY absence_days DESC, attended_days ASC, u.name ASC
                                LIMIT 5";
            }
            $stmt = $pdo->prepare($sqlAbsMonth);
            $stmt->execute($absMonthParams);
            $mostAbsencesList = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Recent Attendance Logs (last 10)
            $attRecentParams = array_merge([':today' => $today], $attParams);
            $stmt = $pdo->prepare("SELECT id_number, full_name, attend_date, time_in, time_out, session, scan_type, status, created_at FROM attendance_logs WHERE attend_date = :today" . $attUserFilter . " ORDER BY created_at DESC LIMIT 10");
            $stmt->execute($attRecentParams);
            $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ETag: bucket the response by minute so an auto-refresh
            // inside the same minute gets a 304 instead of re-hitting SQL.
            $payload = [
                'stats' => [
                    'total_users' => $totalUsers,
                    'attendance_today' => $attendanceToday,
                    'time_in_am' => $timeInAm,
                    'time_in_pm' => $timeInPm,
                    'absences_today' => $absencesToday,
                    'attendance_month' => $attendanceMonth,
                    'most_active_user' => $mostActiveUser,
                    'most_active_count' => $mostActiveCount,
                    'most_active_list' => $mostActiveList,
                    'most_absences_list' => $mostAbsencesList,
                    'working_days' => $workingDays,
                ],
                'recent_logs' => $recentLogs,
            ];
            $etag = ctr_etag_from([
                'b' => floor(time() / 60), // 1-minute bucket
                'role' => $sessionRole,
                'uid' => $sessionUserId,
                'p' => $payload,
            ]);
            ctr_handle_conditional($etag, 15);
            json_response(array_merge(['ok' => true, 'server_time' => $now->format('Y-m-d H:i:s')], $payload));
        }

        if ($action === 'list_absent_today') {
            $isAdmin = $sessionRole !== 'superadmin';
            if ($sessionRole !== 'admin' && $sessionRole !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }

            $params = [':today' => $today];
            if ($isAdmin) {
                $sql = "SELECT id, username, name, id_number, role, position, department
                        FROM users
                        WHERE is_active = 1
                          AND role != 'superadmin'
                          AND (created_by = :created_by OR id = :self_id)
                          AND id NOT IN (SELECT DISTINCT user_id FROM attendance_logs WHERE attend_date = :today)
                        ORDER BY name ASC, id ASC
                        LIMIT 500";
                $params[':created_by'] = $sessionUserId;
                $params[':self_id'] = $sessionUserId;
            } else {
                $sql = "SELECT id, username, name, id_number, role, position, department, created_by
                        FROM users
                        WHERE is_active = 1
                          AND role != 'superadmin'
                          AND id NOT IN (SELECT DISTINCT user_id FROM attendance_logs WHERE attend_date = :today)
                        ORDER BY role DESC, name ASC, id ASC
                        LIMIT 1000";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $etag = ctr_etag_from(['b' => floor(time() / 30), 'role' => $sessionRole, 'uid' => $sessionUserId, 'rows' => $rows]);
            ctr_handle_conditional($etag, 15);
            json_response([
                'ok' => true,
                'date' => $today,
                'absent' => $rows,
                'count' => count($rows),
            ]);
        }

        if ($action === 'list_present_today') {
            $isAdmin = $sessionRole !== 'superadmin';
            if ($sessionRole !== 'admin' && $sessionRole !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }

            $params = [':today' => $today];
            if ($isAdmin) {
                $sql = "SELECT u.id, u.username, u.name, u.id_number, u.role, u.position, u.department,
                               MIN(al.time_in) AS first_in,
                               MAX(al.time_out) AS last_out,
                               COUNT(al.id) AS scan_count
                        FROM users u
                        INNER JOIN attendance_logs al ON al.user_id = u.id
                        WHERE al.attend_date = :today
                          AND u.role != 'superadmin'
                          AND (u.created_by = :created_by OR u.id = :self_id)
                        GROUP BY u.id, u.username, u.name, u.id_number, u.role, u.position, u.department
                        ORDER BY first_in ASC, u.name ASC
                        LIMIT 1000";
                $params[':created_by'] = $sessionUserId;
                $params[':self_id'] = $sessionUserId;
            } else {
                $sql = "SELECT u.id, u.username, u.name, u.id_number, u.role, u.position, u.department, u.created_by,
                               MIN(al.time_in) AS first_in,
                               MAX(al.time_out) AS last_out,
                               COUNT(al.id) AS scan_count
                        FROM users u
                        INNER JOIN attendance_logs al ON al.user_id = u.id
                        WHERE al.attend_date = :today
                          AND u.role != 'superadmin'
                        GROUP BY u.id, u.username, u.name, u.id_number, u.role, u.position, u.department
                        ORDER BY first_in ASC, u.name ASC
                        LIMIT 2000";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $etag = ctr_etag_from(['b' => floor(time() / 30), 'role' => $sessionRole, 'uid' => $sessionUserId, 'rows' => $rows]);
            ctr_handle_conditional($etag, 15);
            json_response([
                'ok' => true,
                'date' => $today,
                'present' => $rows,
                'count' => count($rows),
            ]);
        }

        if ($action === 'list_all_records') {
            $isAdmin = $sessionRole !== 'superadmin';
            if ($sessionRole !== 'admin' && $sessionRole !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }

            $params = [':today' => $today];
            if ($isAdmin) {
                $sql = "SELECT al.id, al.id_number, al.full_name, al.attend_date, al.time_in, al.time_out, al.session, al.scan_type, al.status, al.created_at
                        FROM attendance_logs al
                        WHERE al.attend_date = :today
                          AND al.user_id IN (SELECT id FROM users WHERE created_by = :created_by OR id = :self_id)
                        ORDER BY al.created_at DESC
                        LIMIT 1000";
                $params[':created_by'] = $sessionUserId;
                $params[':self_id'] = $sessionUserId;
            } else {
                $sql = "SELECT id, id_number, full_name, attend_date, time_in, time_out, session, scan_type, status, created_at
                        FROM attendance_logs
                        WHERE attend_date = :today
                        ORDER BY created_at DESC
                        LIMIT 2000";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $etag = ctr_etag_from(['b' => floor(time() / 30), 'role' => $sessionRole, 'uid' => $sessionUserId, 'rows' => $rows]);
            ctr_handle_conditional($etag, 15);
            json_response([
                'ok' => true,
                'date' => $today,
                'records' => $rows,
                'count' => count($rows),
            ]);
        }

        if ($action === 'list_most_active') {
            $isAdmin = $sessionRole !== 'superadmin';
            if ($sessionRole !== 'admin' && $sessionRole !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }

            $params = [':monthStart' => $monthStart];
            if ($isAdmin) {
                $sql = "SELECT al.user_id, al.full_name, COUNT(*) AS cnt
                        FROM attendance_logs al
                        WHERE al.attend_date >= :monthStart
                          AND al.user_id IN (SELECT id FROM users WHERE created_by = :created_by OR id = :self_id)
                        GROUP BY al.user_id, al.full_name
                        ORDER BY cnt DESC, al.full_name ASC
                        LIMIT 5";
                $params[':created_by'] = $sessionUserId;
                $params[':self_id'] = $sessionUserId;
            } else {
                $sql = "SELECT user_id, full_name, COUNT(*) AS cnt
                        FROM attendance_logs
                        WHERE attend_date >= :monthStart
                        GROUP BY user_id, full_name
                        ORDER BY cnt DESC, full_name ASC
                        LIMIT 5";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $etag = ctr_etag_from(['b' => floor(time() / 60), 'role' => $sessionRole, 'uid' => $sessionUserId, 'rows' => $rows]);
            ctr_handle_conditional($etag, 30);
            json_response([
                'ok' => true,
                'rows' => $rows,
            ]);
        }

        if ($action === 'list_most_absences') {
            $isAdmin = $sessionRole !== 'superadmin';
            if ($sessionRole !== 'admin' && $sessionRole !== 'superadmin') {
                json_response(['ok' => false, 'message' => 'Forbidden.'], 403);
            }

            // Working days = days elapsed this month (from 1st up to today)
            $workingDays = (int)$now->format('d');
            if ($workingDays < 1) $workingDays = 1;

            $params = [
                ':monthStart' => $monthStart,
                ':today' => $today,
                ':workingDays' => $workingDays,
            ];

            if ($isAdmin) {
                $sql = "SELECT u.id, u.username, u.name, u.id_number, u.position, u.department,
                               COALESCE(att.attended_days, 0) AS attended_days,
                               (:workingDays - COALESCE(att.attended_days, 0)) AS absence_days
                        FROM users u
                        LEFT JOIN (
                            SELECT user_id, COUNT(DISTINCT attend_date) AS attended_days
                            FROM attendance_logs
                            WHERE attend_date >= :monthStart AND attend_date <= :today
                            GROUP BY user_id
                        ) att ON att.user_id = u.id
                        WHERE u.is_active = 1
                          AND u.role != 'superadmin'
                          AND (u.created_by = :created_by OR u.id = :self_id)
                        ORDER BY absence_days DESC, attended_days ASC, u.name ASC
                        LIMIT 5";
                $params[':created_by'] = $sessionUserId;
                $params[':self_id'] = $sessionUserId;
            } else {
                $sql = "SELECT u.id, u.username, u.name, u.id_number, u.position, u.department, u.created_by,
                               COALESCE(att.attended_days, 0) AS attended_days,
                               (:workingDays - COALESCE(att.attended_days, 0)) AS absence_days
                        FROM users u
                        LEFT JOIN (
                            SELECT user_id, COUNT(DISTINCT attend_date) AS attended_days
                            FROM attendance_logs
                            WHERE attend_date >= :monthStart AND attend_date <= :today
                            GROUP BY user_id
                        ) att ON att.user_id = u.id
                        WHERE u.is_active = 1
                          AND u.role != 'superadmin'
                        ORDER BY absence_days DESC, attended_days ASC, u.name ASC
                        LIMIT 5";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $etag = ctr_etag_from(['b' => floor(time() / 60), 'role' => $sessionRole, 'uid' => $sessionUserId, 'rows' => $rows]);
            ctr_handle_conditional($etag, 30);
            json_response([
                'ok' => true,
                'working_days' => $workingDays,
                'rows' => $rows,
            ]);
        }

        json_response(['ok' => false, 'message' => 'Unknown action.'], 400);
    }
    catch (Throwable $e) {
        json_response(['ok' => false, 'message' => 'Server error.'], 500);
    }
}

include 'includes/header.php';
ob_start();
ctr_cookie_consent_banner();
?>

<div class="container-fluid px-4 py-4 ctr-dash">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1">Dashboard</h4>
            <div class="text-muted small" id="dashboardUpdatedAt"></div>
        </div>
        <div class="d-flex gap-2">
            <?php if ($sessionRole === 'admin'): ?>
            <a class="btn btn-outline-info" href="forms/Instruction%20_admin_side.docx" download><i class="feather-download me-1"></i>Instruction Guide</a>
            <?php endif; ?>
            <?php if ($sessionRole === 'superadmin'): ?>
            <a class="btn btn-outline-success" href="home"><i class="feather-home me-1"></i>Go to Home</a>
            <?php endif; ?>
            <a class="btn btn-outline-secondary" href="attendance">Go to Attendance</a>
        </div>
    </div>

    <?php if ($adminNeedsPlanRequest): ?>
    <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
        <i class="feather-alert-triangle me-2 fs-4"></i>
        <div class="flex-grow-1">
            <strong>Plan Required</strong><br>
            <span>Your account needs an active plan to access all features. Please contact the Superadmin to request a plan activation.</span>
        </div>
        <button type="button" id="adminPlanExtendBtn" class="btn btn-warning btn-sm ms-3">
            <i class="feather-mail me-1"></i>Request Plan
        </button>
    </div>
    <?php endif; ?>

    <?php if ($sessionRole === 'admin'): ?>
    <?php
        $flashType = trim((string)($adminHomeTitleFlash['type'] ?? ''));
        if ($flashType !== 'success' && $flashType !== 'danger' && $flashType !== 'warning' && $flashType !== 'info') {
            $flashType = 'info';
        }
        $flashMessage = trim((string)($adminHomeTitleFlash['message'] ?? ''));
    ?>
    <?php if ($flashMessage !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> mb-4" role="alert">
        <?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                <div>
                    <h5 class="mb-1">Customize Home Title</h5>
                    <div class="text-muted small">Change the `home.php` title from `SCHOOLS DIVISION OFFICE OF KABANKALAN CITY` to your designated school name.</div>
                </div>
                <div class="text-muted small">Admin only</div>
            </div>
            <div class="alert d-none mb-3" id="adminHomeTitleAlert" role="alert"></div>
            <form method="post" action="index" id="adminHomeTitleForm">
                <input type="hidden" name="action" value="save_admin_home_title">
                <label class="form-label" for="adminHomeTitleInput">School Name for Home Page</label>
                <div class="d-flex gap-2 align-items-end">
                    <div class="flex-grow-1">
                        <input
                            type="text"
                            class="form-control"
                            id="adminHomeTitleInput"
                            name="admin_home_title"
                            maxlength="255"
                            placeholder="Enter your designated school name"
                            value="<?php echo htmlspecialchars($adminHomeTitle, ENT_QUOTES, 'UTF-8'); ?>"
                        >
                        <div class="form-text">Leave this blank if you want to use the default title.</div>
                    </div>
                    <button type="submit" class="btn btn-primary text-nowrap" id="adminHomeTitleSaveBtn">
                        <i class="feather-save me-1"></i>Save Home Title
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div style="position:relative;">
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 ctr-stat-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-muted small text-uppercase ctr-stat-label">Total Users</div>
                            <div class="fs-3 fw-bold text-primary ctr-stat-value" id="statTotalUsers">-</div>
                        </div>
                        <div class="ctr-stat-icon ctr-stat-icon-primary">
                            <i class="feather-users"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 bg-soft-success ctr-stat-card" id="presentTodayCard" role="button" tabindex="0" style="cursor:pointer;" title="View present list">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-muted small text-uppercase ctr-stat-label">Present Today</div>
                            <div class="fs-3 fw-bold text-success ctr-stat-value" id="statAttendanceToday">-</div>
                        </div>
                        <div class="ctr-stat-icon ctr-stat-icon-success">
                            <i class="feather-user-check"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 ctr-stat-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-muted small text-uppercase ctr-stat-label">Time In (AM)</div>
                            <div class="fs-3 fw-bold text-info ctr-stat-value" id="statTimeInAm">-</div>
                        </div>
                        <div class="ctr-stat-icon ctr-stat-icon-info">
                            <i class="feather-sunrise"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 ctr-stat-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-muted small text-uppercase ctr-stat-label">Time In (PM)</div>
                            <div class="fs-3 fw-bold text-warning ctr-stat-value" id="statTimeInPm">-</div>
                        </div>
                        <div class="ctr-stat-icon ctr-stat-icon-warning">
                            <i class="feather-sunset"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 bg-soft-danger ctr-stat-card" id="absentTodayCard" role="button" tabindex="0" style="cursor:pointer;" title="View absent list">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-muted small text-uppercase ctr-stat-label">Absent Today</div>
                            <div class="fs-3 fw-bold text-danger ctr-stat-value" id="statAbsencesToday">-</div>
                        </div>
                        <div class="ctr-stat-icon ctr-stat-icon-danger">
                            <i class="feather-user-x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100 ctr-stat-card">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-muted small text-uppercase ctr-stat-label">Monthly Attendance</div>
                            <div class="fs-3 fw-bold text-secondary ctr-stat-value" id="statAttendanceMonth">-</div>
                        </div>
                        <div class="ctr-stat-icon ctr-stat-icon-secondary">
                            <i class="feather-calendar"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100 ctr-list-card" id="mostActiveCard" role="button" tabindex="0" style="cursor:pointer;" title="View most active users">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <div class="flex-grow-1">
                            <div class="text-muted small text-uppercase ctr-stat-label">Most Active This Month</div>
                        </div>
                        <div class="ctr-stat-icon ctr-stat-icon-primary ctr-stat-icon-sm">
                            <i class="feather-award"></i>
                        </div>
                    </div>
                    <ul class="list-group list-group-flush" id="statMostActiveList">
                        <li class="list-group-item px-0 text-muted small">Loading...</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100 ctr-list-card" id="mostAbsencesCard" role="button" tabindex="0" style="cursor:pointer;" title="View most absences">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <div class="flex-grow-1">
                            <div class="text-muted small text-uppercase ctr-stat-label">Most Absences This Month</div>
                            <div class="text-muted small"><span id="statMostAbsencesSub">Top 3</span></div>
                        </div>
                        <div class="ctr-stat-icon ctr-stat-icon-danger ctr-stat-icon-sm">
                            <i class="feather-user-x"></i>
                        </div>
                    </div>
                    <ul class="list-group list-group-flush" id="statMostAbsencesList">
                        <li class="list-group-item px-0 text-muted small">Loading...</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php if ($adminNeedsPlanRequest): ?>
    <div class="ctr-lock-overlay">
        <div class="text-center p-4">
            <div style="font-size:42px;margin-bottom:8px;">&#128274;</div>
            <div class="fw-bold text-muted" style="font-size:14px;">Dashboard locked — plan required</div>
        </div>
    </div>
    <?php endif; ?>
    </div>

    <!-- Recent Attendance Logs -->
    <div class="card border-0 shadow-sm mb-3 ctr-list-card" id="recentLogsCard" role="button" tabindex="0" style="cursor:pointer;" title="View all records">
        <div class="card-header bg-transparent border-0 py-2 d-flex align-items-center">
            <h5 class="mb-0 flex-grow-1">Recent Attendance Logs (Today)</h5>
            <span class="text-muted small"><i class="feather-maximize-2 me-1"></i>Click to view all</span>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="recentLogsTable">
                    <thead>
                        <tr>
                            <th>ID Number</th>
                            <th>Full Name</th>
                            <th>Time</th>
                            <th>Session</th>
                            <th>Type</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="6" class="text-center text-muted py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="absentTodayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Absent Today</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-muted small mb-2" id="absentTodaySub">Loading…</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="absentTodayTable">
                        <thead>
                            <tr>
                                <th style="width:60px;">#</th>
                                <th>ID Number</th>
                                <th>Full Name</th>
                                <th>Job Title</th>
                                <th>Department</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="5" class="text-center text-muted py-3">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="presentTodayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Present Today</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-muted small mb-2" id="presentTodaySub">Loading…</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="presentTodayTable">
                        <thead>
                            <tr>
                                <th style="width:60px;">#</th>
                                <th>ID Number</th>
                                <th>Full Name</th>
                                <th>Job Title</th>
                                <th>Department</th>
                                <th>First In</th>
                                <th>Last Out</th>
                                <th>Scans</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="8" class="text-center text-muted py-3">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="allRecordsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">All Records (Today)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-muted small mb-2" id="allRecordsSub">Loading…</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="allRecordsTable">
                        <thead>
                            <tr>
                                <th style="width:60px;">#</th>
                                <th>ID Number</th>
                                <th>Full Name</th>
                                <th>Time</th>
                                <th>Session</th>
                                <th>Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="7" class="text-center text-muted py-3">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="mostActiveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Most Active Users (This Month)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-muted small mb-2" id="mostActiveSub">Top 5</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="mostActiveTable">
                        <thead>
                            <tr>
                                <th style="width:60px;">#</th>
                                <th>Full Name</th>
                                <th>Attendance Records</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="3" class="text-center text-muted py-3">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="mostAbsencesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Most Absences (This Month)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-muted small mb-2" id="mostAbsencesSub">Loading…</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="mostAbsencesTable">
                        <thead>
                            <tr>
                                <th style="width:60px;">#</th>
                                <th>ID Number</th>
                                <th>Full Name</th>
                                <th>Job Title</th>
                                <th>Department</th>
                                <th>Attended</th>
                                <th>Absences</th>
                </tr>
                </thead>
                <tbody>
                    <tr><td colspan="7" class="text-center text-muted py-3">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
    </div>
</div>
</div>
<?php
$body = ob_get_clean();
$etag = ctr_etag_from([
    'b' => floor(time() / 15),
    'uid' => $sessionUserId,
    'role' => $sessionRole,
    'title' => $adminHomeTitle,
    'flash' => is_array($adminHomeTitleFlash) ? $adminHomeTitleFlash : null,
]);
ctr_handle_conditional($etag, 15);
echo $body;
?>
