<?php
declare(strict_types=1);

$user = current_user();
$view = (string) ($_GET['view'] ?? ($user ? 'dashboard' : 'login'));
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$appName = $config['app']['name'];

if (!$user && !in_array($view, ['login', 'register'], true)) {
    redirect_to('login');
}
if ($user && in_array($view, ['login', 'register'], true)) {
    redirect_to();
}

$isManager = $user && in_array($user['role'], ['admin', 'super_admin'], true);
$roadLocations = ($user && $user['role'] === 'user' && $view === 'add_report') ? roadline_locations() : [];
$counts = [];
$reportRows = [];
$jobRows = [];
$accountRows = [];
$notifications = [];
$workers = [];
$conversationMessages = [];

if ($user) {
    $pdo = db();
    if ($isManager) {
        $counts = [
            'review' => (int) $pdo->query("SELECT COUNT(*) FROM roadline_reports WHERE status = 'pending_review'")->fetchColumn(),
            'active' => (int) $pdo->query("SELECT COUNT(*) FROM roadline_jobs WHERE status IN ('pending_assignment','assigned','in_progress','pending_user_verification')")->fetchColumn(),
            'verify' => (int) $pdo->query("SELECT COUNT(*) FROM roadline_jobs WHERE status = 'pending_user_verification'")->fetchColumn(),
            'closed' => (int) $pdo->query("SELECT COUNT(*) FROM roadline_jobs WHERE status = 'completed'")->fetchColumn(),
        ];
    } elseif ($user['role'] === 'worker') {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM roadline_jobs WHERE worker_id = ? AND status IN ('assigned','in_progress')");
        $statement->execute([$user['id']]);
        $counts['active'] = (int) $statement->fetchColumn();
        $statement = $pdo->prepare("SELECT COUNT(*) FROM roadline_jobs WHERE worker_id = ? AND status = 'completed'");
        $statement->execute([$user['id']]);
        $counts['closed'] = (int) $statement->fetchColumn();
    } else {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM roadline_reports WHERE reporter_id = ? AND status <> 'rejected'");
        $statement->execute([$user['id']]);
        $counts['active'] = (int) $statement->fetchColumn();
        $statement = $pdo->prepare("SELECT COUNT(*) FROM roadline_reports r JOIN roadline_jobs j ON j.report_id = r.id WHERE r.reporter_id = ? AND j.status = 'pending_user_verification'");
        $statement->execute([$user['id']]);
        $counts['verify'] = (int) $statement->fetchColumn();
        $statement = $pdo->prepare("SELECT COUNT(*) FROM roadline_reports r JOIN roadline_jobs j ON j.report_id = r.id WHERE r.reporter_id = ? AND j.status = 'completed'");
        $statement->execute([$user['id']]);
        $counts['closed'] = (int) $statement->fetchColumn();
    }

    if ($isManager) {
        $reportRows = $pdo->query('SELECT r.*, u.name AS reporter_name, j.id AS job_id, j.status AS job_status, j.worker_id, w.name AS worker_name FROM roadline_reports r LEFT JOIN roadline_app_users u ON u.id = r.reporter_id LEFT JOIN roadline_jobs j ON j.report_id = r.id LEFT JOIN roadline_app_users w ON w.id = j.worker_id ORDER BY r.created_at DESC LIMIT 100')->fetchAll();
        $jobRows = $pdo->query('SELECT j.*, r.public_id, r.title, r.category, r.road_location_label, r.photo_path, r.reporter_id, reporter.name AS reporter_name, worker.name AS worker_name FROM roadline_jobs j JOIN roadline_reports r ON r.id = j.report_id LEFT JOIN roadline_app_users reporter ON reporter.id = r.reporter_id LEFT JOIN roadline_app_users worker ON worker.id = j.worker_id ORDER BY FIELD(j.status, \'pending_assignment\', \'assigned\', \'in_progress\', \'pending_user_verification\', \'completed\'), j.updated_at DESC LIMIT 100')->fetchAll();
    } elseif ($user['role'] === 'worker') {
        $statement = $pdo->prepare('SELECT j.*, r.public_id, r.title, r.category, r.description, r.road_location_label, r.photo_path, reporter.name AS reporter_name FROM roadline_jobs j JOIN roadline_reports r ON r.id = j.report_id LEFT JOIN roadline_app_users reporter ON reporter.id = r.reporter_id WHERE j.worker_id = ? ORDER BY FIELD(j.status, \'assigned\', \'in_progress\', \'pending_user_verification\', \'completed\'), j.updated_at DESC');
        $statement->execute([$user['id']]);
        $jobRows = $statement->fetchAll();
        $reportRows = $pdo->prepare('SELECT r.*, j.status AS job_status, j.id AS job_id FROM roadline_reports r LEFT JOIN roadline_jobs j ON j.report_id = r.id WHERE j.worker_id = ? ORDER BY r.created_at DESC');
        $reportRows->execute([$user['id']]);
        $reportRows = $reportRows->fetchAll();
    } else {
        $statement = $pdo->prepare('SELECT r.*, j.id AS job_id, j.status AS job_status, j.worker_notes, w.name AS worker_name FROM roadline_reports r LEFT JOIN roadline_jobs j ON j.report_id = r.id LEFT JOIN roadline_app_users w ON w.id = j.worker_id WHERE r.reporter_id = ? ORDER BY r.created_at DESC');
        $statement->execute([$user['id']]);
        $reportRows = $statement->fetchAll();
        $jobRows = $reportRows;
    }

    if (!$isManager && in_array($view, ['reports', 'jobs'], true)) {
        $reportIds = array_values(array_unique(array_map('intval', array_column($reportRows, 'id'))));
        if ($reportIds) {
            $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
            $statement = $pdo->prepare("SELECT m.*, u.name AS sender_name, u.role AS sender_role FROM roadline_conversation_messages m JOIN roadline_app_users u ON u.id = m.sender_user_id WHERE m.report_id IN ({$placeholders}) ORDER BY m.created_at, m.id");
            $statement->execute($reportIds);
            foreach ($statement->fetchAll() as $message) {
                $conversationMessages[(int) $message['report_id']][] = $message;
            }
        }
    }

    if ($isManager) {
        $allowedRoles = $user['role'] === 'super_admin' ? "'admin','worker','user'" : "'worker','user'";
        $accountRows = $pdo->query("SELECT id, name, email, role, is_active, created_at FROM roadline_app_users WHERE role IN ({$allowedRoles}) AND deleted_at IS NULL ORDER BY FIELD(role, 'admin', 'worker', 'user'), name")->fetchAll();
        $workers = $pdo->query("SELECT id, name FROM roadline_app_users WHERE role = 'worker' AND is_active = 1 AND deleted_at IS NULL ORDER BY name")->fetchAll();
    }
    $statement = $pdo->prepare('SELECT n.*, r.public_id, r.title FROM roadline_notifications n JOIN roadline_reports r ON r.id = n.report_id WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT 12');
    $statement->execute([$user['id']]);
    $notifications = $statement->fetchAll();
}

$titleByView = ['dashboard' => 'Overview', 'reports' => 'My reports', 'add_report' => 'Add report', 'jobs' => 'Work orders', 'accounts' => 'People & access', 'notifications' => 'Notifications'];
$pageTitle = $user
    ? ($titleByView[$view] ?? 'Overview')
    : ($view === 'register' ? 'Create account' : 'Sign in');
if ($user && !in_array($view, ['dashboard', 'reports', 'add_report', 'jobs', 'accounts', 'notifications'], true)) {
    $view = 'dashboard';
    $pageTitle = 'Overview';
}
if ($view === 'add_report' && $user && $user['role'] !== 'user') {
    redirect_to('reports');
}
if ($view === 'accounts' && !$isManager) {
    http_response_code(403);
    exit('You do not have permission to view this page.');
}
if ($view === 'jobs' && $user && $user['role'] === 'user') {
    redirect_to('reports');
}

$unreadCount = 0;
if ($user) {
    $statement = db()->prepare('SELECT COUNT(*) FROM roadline_notifications WHERE user_id = ? AND read_at IS NULL');
    $statement->execute([$user['id']]);
    $unreadCount = (int) $statement->fetchColumn();
}
$navItems = [['dashboard', 'Overview', '⌂']];
if ($user && $user['role'] === 'user') {
    $navItems[] = ['reports', 'My reports', '◎'];
    $navItems[] = ['add_report', 'Add report', '＋'];
} else {
    $navItems[] = ['reports', 'Hazard reports', '⚑'];
    $navItems[] = ['jobs', 'Work orders', '▤'];
}
if ($isManager) {
    $navItems[] = ['accounts', 'People & access', '♙'];
}
$navItems[] = ['notifications', 'Notifications' . ($unreadCount ? ' (' . $unreadCount . ')' : ''), '◉'];
