<?php
declare(strict_types=1);

$actor = current_user();
if (!$actor) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Sign in to receive live updates.']);
    exit;
}
session_write_close();
$pdo = db();

$rawCursors = $_GET['messages'] ?? '{}';
$cursors = is_string($rawCursors) ? json_decode($rawCursors, true) : null;
if (!is_array($cursors)) {
    http_response_code(400);
    exit('Invalid message cursor.');
}

$reportCursors = [];
foreach ($cursors as $reportId => $messageId) {
    if (count($reportCursors) >= 100) {
        http_response_code(400);
        exit('Too many message cursors.');
    }
    if (!is_string($reportId) && !is_int($reportId)) {
        continue;
    }
    $reportId = filter_var($reportId, FILTER_VALIDATE_INT);
    $messageId = filter_var($messageId, FILTER_VALIDATE_INT);
    if ($reportId === false || $reportId < 1 || $messageId === false || $messageId < 0) {
        http_response_code(400);
        exit('Invalid message cursor.');
    }
    $reportCursors[$reportId] = $messageId;
}

$messages = [];
if ($reportCursors) {
    $reportIds = array_keys($reportCursors);
    $idPlaceholders = implode(',', array_fill(0, count($reportIds), '?'));
    $access = $actor['role'] === 'user'
        ? $pdo->prepare("SELECT id FROM roadline_reports WHERE reporter_id = ? AND id IN ({$idPlaceholders})")
        : $pdo->prepare("SELECT report_id FROM roadline_jobs WHERE worker_id = ? AND report_id IN ({$idPlaceholders})");
    $access->execute(array_merge([$actor['id']], $reportIds));
    $allowedReportIds = array_map('intval', $access->fetchAll(PDO::FETCH_COLUMN));
    $allowedCursors = array_intersect_key($reportCursors, array_flip($allowedReportIds));

    if ($allowedCursors) {
        $cursorSelections = [];
        $cursorParameters = [];
        foreach ($allowedCursors as $reportId => $messageId) {
            $cursorSelections[] = 'SELECT ? AS report_id, ? AS last_message_id';
            $cursorParameters[] = $reportId;
            $cursorParameters[] = $messageId;
        }
        $cursorUnion = implode(' UNION ALL ', $cursorSelections);
        $statement = $pdo->prepare("SELECT m.id, m.report_id, m.sender_user_id, m.message, m.photo_path, m.created_at, u.name AS sender_name FROM roadline_conversation_messages m JOIN ({$cursorUnion}) cursors ON cursors.report_id = m.report_id JOIN roadline_app_users u ON u.id = m.sender_user_id WHERE m.id > cursors.last_message_id ORDER BY m.report_id, m.id");
        $statement->execute($cursorParameters);
        foreach ($statement->fetchAll() as $message) {
            $message['display_date'] = date('M j, g:i a', strtotime($message['created_at']));
            $messages[] = $message;
        }
    }
}

$statement = $pdo->prepare('SELECT n.id, n.report_id, n.message, n.photo_path, n.read_at, n.created_at, r.title FROM roadline_notifications n JOIN roadline_reports r ON r.id = n.report_id WHERE n.user_id = ? ORDER BY n.created_at DESC, n.id DESC LIMIT 12');
$statement->execute([$actor['id']]);
$notifications = $statement->fetchAll();
foreach ($notifications as &$notification) {
    $notification['display_date'] = date('M j, Y · g:i a', strtotime($notification['created_at']));
    $notification['report_url'] = $actor['role'] === 'user'
        ? 'index.php?view=reports&tab=messages'
        : 'index.php?view=jobs';
}
unset($notification);

$statement = $pdo->prepare('SELECT COUNT(*) FROM roadline_notifications WHERE user_id = ? AND read_at IS NULL');
$statement->execute([$actor['id']]);
$unreadCount = (int) $statement->fetchColumn();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'messages' => $messages,
    'notifications' => $notifications,
    'unread_count' => $unreadCount,
], JSON_THROW_ON_ERROR);
exit;
