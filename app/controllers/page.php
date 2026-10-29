<?php
declare(strict_types=1);

$user = current_user();
$view = (string) ($_GET['view'] ?? ($user ? 'dashboard' : 'login'));
$reportTab = (string) ($_GET['tab'] ?? 'ongoing');
if (!in_array($reportTab, $user && $user['role'] === 'user' ? ['messages', 'history', 'ongoing'] : ['history', 'ongoing'], true)) {
    $reportTab = 'ongoing';
}
$jobTab = (string) ($_GET['job_tab'] ?? 'ongoing');
if (!in_array($jobTab, ['history', 'ongoing'], true)) {
    $jobTab = 'ongoing';
}
$legalViews = ['privacy', 'terms', 'moderation', 'copyright'];
$isLegalPage = in_array($view, $legalViews, true);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$appName = $config['app']['name'];

if (!$user && !in_array($view, array_merge(['login', 'register'], $legalViews), true)) {
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
        $reportRows = $pdo->query('SELECT r.*, u.name AS reporter_name, j.id AS job_id, j.status AS job_status, j.worker_id, w.name AS worker_name FROM roadline_reports r LEFT JOIN roadline_app_users u ON u.id = r.reporter_id LEFT JOIN roadline_jobs j ON j.report_id = r.id LEFT JOIN roadline_app_users w ON w.id = j.worker_id ORDER BY r.id DESC LIMIT 100')->fetchAll();
        $jobRows = $pdo->query('SELECT j.*, r.public_id, r.title, r.category, r.road_location_label, r.photo_path, r.reporter_id, reporter.name AS reporter_name, worker.name AS worker_name FROM roadline_jobs j JOIN roadline_reports r ON r.id = j.report_id LEFT JOIN roadline_app_users reporter ON reporter.id = r.reporter_id LEFT JOIN roadline_app_users worker ON worker.id = j.worker_id ORDER BY FIELD(j.status, \'pending_assignment\', \'assigned\', \'in_progress\', \'pending_user_verification\', \'completed\'), j.updated_at DESC LIMIT 100')->fetchAll();
    } elseif ($user['role'] === 'worker') {
        $statement = $pdo->prepare('SELECT j.*, r.public_id, r.title, r.category, r.description, r.road_location_label, r.photo_path, reporter.name AS reporter_name FROM roadline_jobs j JOIN roadline_reports r ON r.id = j.report_id LEFT JOIN roadline_app_users reporter ON reporter.id = r.reporter_id WHERE j.worker_id = ? ORDER BY FIELD(j.status, \'assigned\', \'in_progress\', \'pending_user_verification\', \'completed\'), j.updated_at DESC');
        $statement->execute([$user['id']]);
        $jobRows = $statement->fetchAll();
        $reportRows = $pdo->prepare('SELECT r.*, j.status AS job_status, j.id AS job_id FROM roadline_reports r LEFT JOIN roadline_jobs j ON j.report_id = r.id WHERE j.worker_id = ? ORDER BY r.id DESC');
        $reportRows->execute([$user['id']]);
        $reportRows = $reportRows->fetchAll();
    } else {
        $statement = $pdo->prepare('SELECT r.*, j.id AS job_id, j.status AS job_status, j.worker_notes, w.name AS worker_name FROM roadline_reports r LEFT JOIN roadline_jobs j ON j.report_id = r.id LEFT JOIN roadline_app_users w ON w.id = j.worker_id WHERE r.reporter_id = ? ORDER BY r.id DESC');
        $statement->execute([$user['id']]);
        $reportRows = $statement->fetchAll();
        $jobRows = $reportRows;
    }

    $displayReportRows = $reportRows;
    if ($view === 'reports') {
        $displayReportRows = array_values(array_filter($reportRows, static function (array $report) use ($reportTab): bool {
            $status = report_status($report);
            return match ($reportTab) {
                'messages' => !empty($report['job_id']),
                'history' => in_array($status, ['completed', 'rejected'], true),
                default => !in_array($status, ['completed', 'rejected'], true),
            };
        }));
    }

    $displayJobRows = $jobRows;
    if ($view === 'jobs') {
        $displayJobRows = array_values(array_filter($jobRows, static fn (array $job): bool => (
            $jobTab === 'history' ? $job['status'] === 'completed' : $job['status'] !== 'completed'
        )));
    }

    if ($view === 'jobs' || ($view === 'reports' && $user['role'] === 'user' && $reportTab === 'messages')) {
        $conversationRows = $view === 'jobs' ? $displayJobRows : $displayReportRows;
        $reportIdColumn = $view === 'jobs' ? 'report_id' : 'id';
        $reportIds = array_values(array_unique(array_map('intval', array_column($conversationRows, $reportIdColumn))));
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

$titleByView = [
    'dashboard' => 'Overview',
    'reports' => 'My reports',
    'add_report' => 'Add report',
    'jobs' => 'Work orders',
    'accounts' => 'People & access',
    'notifications' => 'Notifications',
    'privacy' => 'Privacy policy',
    'terms' => 'Terms of use',
    'moderation' => 'Moderation policy',
    'copyright' => 'Copyright policy',
];
$legalLinkLabels = [
    'privacy' => 'Privacy policy',
    'terms' => 'Terms of use',
    'moderation' => 'Moderation',
    'copyright' => 'Copyright',
];
$pageTitle = $user
    ? ($titleByView[$view] ?? 'Overview')
    : ($titleByView[$view] ?? ($view === 'register' ? 'Create account' : 'Sign in'));
if ($user && !in_array($view, array_merge(['dashboard', 'reports', 'add_report', 'jobs', 'accounts', 'notifications'], $legalViews), true)) {
    $view = 'dashboard';
    $pageTitle = 'Overview';
}

$legalPolicies = [
    'privacy' => [
        'intro' => 'This privacy policy describes information used by this academic demonstration project. Roadline is not a real company or public service.',
        'sections' => [
            [
                'heading' => 'Information in the demonstration',
                'paragraphs' => [
                    'Account details include the name and email address you provide and a password stored as a password hash. Hazard reports may include a category, title, description, location, and uploaded photo. Report conversations and workflow updates may also be stored with their timestamps.',
                    'The site uses a session cookie to keep you signed in and protect form submissions. Do not use real passwords or submit sensitive personal information, private images, or details about other people.',
                ],
            ],
            [
                'heading' => 'How information is used and shared',
                'paragraphs' => [
                    'Information is used to demonstrate account access, hazard reporting, review, work assignment, and report updates. Depending on the account role and report, authorized demonstration administrators or assigned workers may be able to view report details and photos.',
                    'The application stores data in its configured database and uploaded images in its configured storage directory. The project also loads Google Fonts, and the report map uses Leaflet resources hosted by unpkg. Those providers may receive ordinary connection information from your browser when their resources are requested.',
                ],
            ],
            [
                'heading' => 'Storage, retention, and choices',
                'paragraphs' => [
                    'Data remains in the project database or image storage until it is removed by a project administrator or the demonstration data is reset. The project may be changed or taken offline as part of coursework. Do not rely on it as a permanent record or submit information you need to keep.',
                    'If you have entered personal information and want it removed, contact the project maintainer or course instructor through the channel used for this class project. This site is not operated as a commercial service, and this notice is not a promise of a particular legal compliance status.',
                ],
            ],
        ],
    ],
    'terms' => [
        'intro' => 'These terms apply to use of Roadline as an academic and demonstration project only. It is not an official road authority, emergency service, registered business, or commercial service.',
        'sections' => [
            [
                'heading' => 'Demonstration only',
                'paragraphs' => [
                    'Reports, maps, job assignments, status changes, and notifications demonstrate software workflows. They do not submit a notice to a government agency, dispatch a real repair crew, or guarantee that anyone will review or act on a report.',
                    'For an immediate danger or a real road emergency, contact the appropriate local emergency service or road authority directly. Do not rely on this website for urgent help.',
                ],
            ],
            [
                'heading' => 'Responsible use',
                'paragraphs' => [
                    'Use only an account you are authorized to access. Provide lawful, relevant, and respectful demonstration content. Do not upload content that is abusive, threatening, unlawful, misleading, confidential, or that infringes another person’s rights.',
                    'Do not attempt to disrupt the site, access another person’s account or data without permission, or use the demonstration to impersonate a real organization or official service.',
                ],
            ],
            [
                'heading' => 'Availability and changes',
                'paragraphs' => [
                    'The project is provided for coursework and evaluation, without a promise of uninterrupted availability, data preservation, operational accuracy, or fitness for real-world use. Features and stored demonstration data may change or be removed without notice.',
                    'Administrators may restrict or deactivate access when needed to protect the project or its users. These terms describe the project’s intended use and are not a substitute for advice about your legal rights.',
                ],
            ],
        ],
    ],
    'moderation' => [
        'intro' => 'Moderation standards help keep this classroom demonstration useful and respectful. The site is not continuously monitored, and submitting a report does not create a real-world response.',
        'sections' => [
            [
                'heading' => 'Content that is not allowed',
                'paragraphs' => [
                    'Do not submit threats, harassment, hate, spam, knowingly false or irrelevant reports, unlawful material, private or sensitive personal information, or images and text you do not have permission to share.',
                    'Keep report descriptions focused on the road hazard. Avoid including identifiable people, vehicle registration details, home addresses, or other information that is not needed to demonstrate the report workflow.',
                ],
            ],
            [
                'heading' => 'Review and action',
                'paragraphs' => [
                    'Demonstration administrators may review submitted reports and related content, reject content that does not fit the project, and restrict or deactivate accounts using the controls available to them. Review may be delayed or may not happen at all.',
                    'If you believe content should be reviewed, contact the project maintainer or course instructor through the channel used for this class project and identify the report. Do not include sensitive details in a public message.',
                ],
            ],
            [
                'heading' => 'Real-world hazards',
                'paragraphs' => [
                    'This project does not notify a real road authority or emergency service. Report actual hazards directly to the responsible local authority, and use emergency services for immediate danger.',
                ],
            ],
        ],
    ],
    'copyright' => [
        'intro' => 'This page explains the project’s approach to copyright for an educational demonstration. It is informational and does not determine ownership or replace legal advice.',
        'sections' => [
            [
                'heading' => 'Project and third-party materials',
                'paragraphs' => [
                    'The Roadline name, interface, and project materials are presented as part of coursework and are not a claim of ownership over any third-party work. Code, fonts, maps, libraries, and other materials may belong to their respective authors and remain subject to their licenses and terms.',
                    'Do not copy, redistribute, or use project or third-party material outside the permissions granted by its owner or applicable license.',
                ],
            ],
            [
                'heading' => 'Content you submit',
                'paragraphs' => [
                    'You remain responsible for the text and images you submit and must have the rights or permission needed to share them. Submitting content does not transfer its ownership to this project. You allow it to be stored and displayed within the demonstration workflows for coursework and evaluation.',
                    'Do not submit copyrighted or confidential material unless you are authorized to do so. Submitted content may remain in the project storage until removed by an administrator or the demonstration data is reset.',
                ],
            ],
            [
                'heading' => 'Copyright concerns',
                'paragraphs' => [
                    'If you believe material in this demonstration infringes your copyright, contact the project maintainer or course instructor through the channel used for this class project. Include enough information to identify the material and explain your concern; avoid sending sensitive personal information.',
                ],
            ],
        ],
    ],
];
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
$navItems[] = ['notifications', 'Notifications', '◉'];
