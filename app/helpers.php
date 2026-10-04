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

function audit_notice(int $userId, int $reportId, string $message): void
{
    $statement = db()->prepare('INSERT INTO roadline_notifications (user_id, report_id, message) VALUES (?, ?, ?)');
    $statement->execute([$userId, $reportId, $message]);
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
    return $report['job_status'] ?: $report['status'];
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
