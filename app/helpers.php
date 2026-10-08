<?php
declare(strict_types=1);

function redirect_to(string $view = 'dashboard'): never
{
    header('Location: index.php?view=' . urlencode($view));
    exit;
}

function flash(string $message, string $kind = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
}

function post_string(string $key, int $maxLength = 2000): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    return mb_substr($value, 0, $maxLength);
}

function audit_notice(int $userId, int $reportId, string $message, ?string $photoPath = null): void
{
    $statement = db()->prepare('INSERT INTO roadline_notifications (user_id, report_id, message, photo_path) VALUES (?, ?, ?, ?)');
    $statement->execute([$userId, $reportId, $message, $photoPath]);
}

function save_conversation_message(int $reportId, int $senderId, string $message, ?string $photoPath = null): void
{
    $statement = db()->prepare('INSERT INTO roadline_conversation_messages (report_id, sender_user_id, message, photo_path) VALUES (?, ?, ?, ?)');
    $statement->execute([$reportId, $senderId, $message, $photoPath]);
}

function store_image_upload(string $field): ?string
{
    global $config;

    $upload = $_FILES[$field] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The image could not be uploaded. Choose an image up to 5 MB and try again.');
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    $size = (int) ($upload['size'] ?? 0);
    if ($size < 1 || $size > 5 * 1024 * 1024 || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('Choose an image up to 5 MB.');
    }

    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $imageInfo = @getimagesize($temporaryPath);
    $mimeType = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;
    if (!is_string($mimeType) || !isset($extensions[$mimeType])
        || (int) $imageInfo[0] * (int) $imageInfo[1] > 25000000) {
        throw new RuntimeException('Use a valid JPEG, PNG, or WebP image with dimensions under 25 megapixels.');
    }

    $directory = $config['uploads']['directory'];
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Image storage is unavailable. Please contact an administrator.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file($temporaryPath, $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('The image could not be saved. Please try again.');
    }

    return $filename;
}

function delete_stored_image(string $filename): void
{
    global $config;

    if (preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $filename)) {
        $path = $config['uploads']['directory'] . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function serve_report_image(): never
{
    global $config;

    $filename = (string) ($_GET['image'] ?? '');
    if (!preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/', $filename)) {
        http_response_code(404);
        exit;
    }

    $actor = current_user();
    if (!$actor) {
        http_response_code(404);
        exit;
    }

    $statement = db()->prepare('SELECT r.id FROM roadline_reports r LEFT JOIN roadline_jobs j ON j.report_id = r.id WHERE (r.photo_path = ? OR EXISTS (SELECT 1 FROM roadline_notifications n WHERE n.report_id = r.id AND n.photo_path = ?) OR EXISTS (SELECT 1 FROM roadline_conversation_messages m WHERE m.report_id = r.id AND m.photo_path = ?))');
    $statement->execute([$filename, $filename, $filename]);
    $authorized = false;
    foreach ($statement->fetchAll() as $report) {
        if (in_array($actor['role'], ['admin', 'super_admin'], true)) {
            $authorized = true;
            break;
        }
        $access = $actor['role'] === 'user'
            ? db()->prepare('SELECT 1 FROM roadline_reports WHERE id = ? AND reporter_id = ?')
            : db()->prepare('SELECT 1 FROM roadline_jobs WHERE report_id = ? AND worker_id = ?');
        $access->execute([(int) $report['id'], $actor['id']]);
        if ($access->fetchColumn()) {
            $authorized = true;
            break;
        }
    }
    $path = $config['uploads']['directory'] . DIRECTORY_SEPARATOR . $filename;
    if (!$authorized || !is_file($path)) {
        http_response_code(404);
        exit;
    }

    $contentTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    header('Content-Type: ' . $contentTypes[pathinfo($filename, PATHINFO_EXTENSION)]);
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function status_label(?string $status): string
{
    return match ($status) {
        'pending_review' => 'Awaiting review',
        'approved' => 'Verified',
        'rejected' => 'Not approved',
        'pending_assignment' => 'Needs assignment',
        'assigned' => 'Assigned',
        'in_progress' => 'In progress',
        'pending_user_verification' => 'Verify repair',
        'completed' => 'Resolved',
        default => 'Submitted',
    };
}

function status_class(?string $status): string
{
    return 'status-' . preg_replace('/[^a-z_]/', '', $status ?? 'submitted');
}

function report_status(array $report): string
{
    $jobStatus = $report['job_status'] ?? null;
    if (is_string($jobStatus) && trim($jobStatus) !== '') {
        return $jobStatus;
    }

    $reportStatus = $report['status'] ?? null;
    return is_string($reportStatus) && trim($reportStatus) !== '' ? $reportStatus : 'submitted';
}

function format_record_date(array $record, string $format): string
{
    $date = $record['created_at'] ?? null;
    if (!is_string($date) || $date === '') {
        $date = $record['updated_at'] ?? null;
    }
    if (!is_string($date) || $date === '') {
        return 'Date unavailable';
    }

    $timestamp = strtotime($date);
    return $timestamp === false ? 'Date unavailable' : date($format, $timestamp);
}
