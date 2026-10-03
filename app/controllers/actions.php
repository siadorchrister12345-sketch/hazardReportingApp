<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'login') {
            $email = mb_strtolower(post_string('email', 190));
            $statement = db()->prepare('SELECT id, name, email, password_hash, role FROM roadline_app_users WHERE email = ? AND is_active = 1 AND deleted_at IS NULL LIMIT 1');
            $statement->execute([$email]);
            $account = $statement->fetch();
            if (!$account || !is_string($account['password_hash']) || $account['password_hash'] === '' || !password_verify(post_string('password'), $account['password_hash'])) {
                throw new RuntimeException('Email or password is incorrect.');
            }
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int) $account['id'], 'name' => $account['name'], 'email' => $account['email'], 'role' => $account['role']];
            flash('Welcome back, ' . $account['name'] . '.');
            redirect_to();
        }

        if ($action === 'register') {
            $name = post_string('name', 120);
            $email = mb_strtolower(post_string('email', 190));
            $password = (string) ($_POST['password'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
                throw new RuntimeException('Enter your name, a valid email, and a password of at least 10 characters.');
            }
            $statement = db()->prepare('INSERT INTO roadline_app_users (name, email, password_hash, role) VALUES (?, ?, ?, \'user\')');
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int) db()->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'user'];
            flash('Your reporter account is ready.');
            redirect_to();
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            redirect_to('login');
        }

        $actor = require_user();

        if ($action === 'submit_report') {
            require_role(['user']);
            $locations = roadline_locations();
            $locationId = post_string('road_location_id', 191);
            $location = null;
            if ($locationId !== '') {
                foreach ($locations as $candidate) {
                    if ((string) $candidate['id'] === $locationId) {
                        $location = $candidate;
                        break;
                    }
                }
            }
            $latitudeValue = post_string('latitude', 24);
            $longitudeValue = post_string('longitude', 24);
            $latitude = null;
            $longitude = null;
            if ($location) {
                $locationId = (string) $location['id'];
                $locationLabel = (string) $location['label'];
                $latitude = $location['latitude'];
                $longitude = $location['longitude'];
            } elseif (is_numeric($latitudeValue) && is_numeric($longitudeValue) && (float) $latitudeValue >= -90 && (float) $latitudeValue <= 90 && (float) $longitudeValue >= -180 && (float) $longitudeValue <= 180) {
                $latitude = number_format((float) $latitudeValue, 7, '.', '');
                $longitude = number_format((float) $longitudeValue, 7, '.', '');
                $locationId = null;
                $locationLabel = sprintf('Map pin · %.5f, %.5f', (float) $latitude, (float) $longitude);
            } else {
                throw new RuntimeException('Choose a Roadline location or place a valid map pin.');
            }
            $title = post_string('title', 160);
            $description = post_string('description', 4000);
            $category = post_string('category', 80);
            if ($title === '' || $description === '' || $category === '') {
                throw new RuntimeException('Complete every field before submitting your report.');
            }
            $publicId = strtoupper(bin2hex(random_bytes(6)));
            $statement = db()->prepare('INSERT INTO roadline_reports (public_id, reporter_id, road_location_id, road_location_label, latitude, longitude, category, title, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$publicId, $actor['id'], $locationId, $locationLabel, $latitude, $longitude, $category, $title, $description]);
            if ($latitude !== null && $longitude !== null) {
                $geometry = db()->prepare('UPDATE Hazards SET Coordinates = ST_GeomFromText(?) WHERE Hazard_ID = ?');
                $geometry->execute([sprintf('POINT(%s %s)', $longitude, $latitude), db()->lastInsertId()]);
            }
            flash('Report ' . $publicId . ' was submitted for review.');
            redirect_to('reports');
        }

        if ($action === 'review_report') {
            require_role(['admin', 'super_admin']);
            $reportId = filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT);
            $decision = post_string('decision', 20);
            if (!$reportId || !in_array($decision, ['approve', 'reject'], true)) {
                throw new RuntimeException('That review action is not valid.');
            }
            $pdo = db();
            $pdo->beginTransaction();
            $statement = $pdo->prepare('SELECT id, reporter_id, public_id, status FROM roadline_reports WHERE id = ? FOR UPDATE');
            $statement->execute([$reportId]);
            $report = $statement->fetch();
            if (!$report || $report['status'] !== 'pending_review') {
                throw new RuntimeException('This report has already been reviewed.');
            }
            $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
            $pdo->prepare('UPDATE roadline_reports SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')->execute([$newStatus, $actor['id'], $reportId]);
            if ($decision === 'approve') {
                $pdo->prepare('INSERT INTO roadline_jobs (report_id, assigned_by) VALUES (?, ?)')->execute([$reportId, $actor['id']]);
                audit_notice((int) $report['reporter_id'], (int) $reportId, 'Your report ' . $report['public_id'] . ' was verified and scheduled for repair.');
            } else {
                audit_notice((int) $report['reporter_id'], (int) $reportId, 'Your report ' . $report['public_id'] . ' was reviewed and could not be approved.');
            }
            $pdo->commit();
            flash($decision === 'approve' ? 'Report approved and converted to a job.' : 'Report declined and reporter notified.');
            redirect_to('reports');
        }

        if ($action === 'assign_job') {
            require_role(['admin', 'super_admin']);
            $jobId = filter_input(INPUT_POST, 'job_id', FILTER_VALIDATE_INT);
            $workerId = filter_input(INPUT_POST, 'worker_id', FILTER_VALIDATE_INT);
            if (!$jobId || !$workerId) {
                throw new RuntimeException('Choose an available worker.');
            }
            $worker = db()->prepare("SELECT id FROM roadline_app_users WHERE id = ? AND role = 'worker' AND is_active = 1 AND deleted_at IS NULL");
            $worker->execute([$workerId]);
            if (!$worker->fetch()) {
                throw new RuntimeException('That worker is not available.');
            }
            $statement = db()->prepare("UPDATE roadline_jobs SET worker_id = ?, assigned_by = ?, status = 'assigned' WHERE id = ? AND status = 'pending_assignment'");
            $statement->execute([$workerId, $actor['id'], $jobId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('This job is no longer awaiting assignment.');
            }
            $report = db()->prepare('SELECT r.id, r.public_id FROM roadline_reports r JOIN roadline_jobs j ON j.report_id = r.id WHERE j.id = ?');
            $report->execute([$jobId]);
            $jobReport = $report->fetch();
            audit_notice((int) $workerId, (int) $jobReport['id'], 'A new road hazard job has been assigned to you.');
            flash('Job assigned to worker.');
            redirect_to('jobs');
        }

        if ($action === 'worker_update') {
            require_role(['worker']);
            $jobId = filter_input(INPUT_POST, 'job_id', FILTER_VALIDATE_INT);
            $status = post_string('status', 40);
            $notes = post_string('worker_notes', 1000);
            if (!$jobId || !in_array($status, ['in_progress', 'pending_user_verification'], true)) {
                throw new RuntimeException('That job update is not valid.');
            }
            $pdo = db();
            $pdo->beginTransaction();
            $statement = $pdo->prepare('SELECT j.status, r.id AS report_id, r.reporter_id, r.public_id FROM roadline_jobs j JOIN roadline_reports r ON r.id = j.report_id WHERE j.id = ? AND j.worker_id = ? FOR UPDATE');
            $statement->execute([$jobId, $actor['id']]);
            $job = $statement->fetch();
            $allowed = $status === 'in_progress'
                ? ['assigned', 'in_progress']
                : ['assigned', 'in_progress'];
            if (!$job || !in_array($job['status'], $allowed, true)) {
                throw new RuntimeException('This job is not assigned to you or cannot be updated.');
            }
            if ($status === 'pending_user_verification') {
                $pdo->prepare('UPDATE roadline_jobs SET status = ?, worker_notes = ?, completed_at = NOW() WHERE id = ?')->execute([$status, $notes, $jobId]);
                audit_notice((int) $job['reporter_id'], (int) $job['report_id'], 'Work on report ' . $job['public_id'] . ' is marked complete. Please verify the repair.');
            } else {
                $pdo->prepare('UPDATE roadline_jobs SET status = ?, worker_notes = ?, completed_at = NULL WHERE id = ?')->execute([$status, $notes, $jobId]);
            }
            $pdo->commit();
            flash($status === 'pending_user_verification' ? 'Completion sent to the reporter for verification.' : 'Job progress updated.');
            redirect_to('jobs');
        }

        if ($action === 'verify_job') {
            require_role(['user']);
            $jobId = filter_input(INPUT_POST, 'job_id', FILTER_VALIDATE_INT);
            $decision = post_string('decision', 20);
            if (!$jobId || !in_array($decision, ['resolved', 'still_hazard'], true)) {
                throw new RuntimeException('Choose whether the hazard is resolved.');
            }
            $pdo = db();
            $pdo->beginTransaction();
            $statement = $pdo->prepare('SELECT j.status, r.id AS report_id, r.public_id FROM roadline_jobs j JOIN roadline_reports r ON r.id = j.report_id WHERE j.id = ? AND r.reporter_id = ? FOR UPDATE');
            $statement->execute([$jobId, $actor['id']]);
            $job = $statement->fetch();
            if (!$job || $job['status'] !== 'pending_user_verification') {
                throw new RuntimeException('This job is not awaiting your verification.');
            }
            if ($decision === 'resolved') {
                $pdo->prepare("UPDATE roadline_jobs SET status = 'completed', verified_at = NOW() WHERE id = ?")->execute([$jobId]);
                audit_notice((int) $actor['id'], (int) $job['report_id'], 'Your report ' . $job['public_id'] . ' is closed. Thanks for verifying the repair.');
                flash('Repair confirmed. The hazard report is now closed.');
            } else {
                $pdo->prepare("UPDATE roadline_jobs SET status = 'in_progress', completed_at = NULL, verified_at = NULL WHERE id = ?")->execute([$jobId]);
                $admins = $pdo->query("SELECT id FROM roadline_app_users WHERE role IN ('admin', 'super_admin') AND is_active = 1 AND deleted_at IS NULL")->fetchAll();
                foreach ($admins as $admin) {
                    audit_notice((int) $admin['id'], (int) $job['report_id'], 'Reporter says report ' . $job['public_id'] . ' still needs attention.');
                }
                flash('Thanks for checking. The job has been reopened for follow-up.', 'info');
            }
            $pdo->commit();
            redirect_to('reports');
        }

        if ($action === 'save_account') {
            $admin = require_role(['admin', 'super_admin']);
            $name = post_string('name', 120);
            $email = mb_strtolower(post_string('email', 190));
            $role = post_string('role', 20);
            $password = (string) ($_POST['password'] ?? '');
            $allowedRoles = $admin['role'] === 'super_admin' ? ['admin', 'worker', 'user'] : ['worker', 'user'];
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, $allowedRoles, true)) {
                throw new RuntimeException('Enter valid account details and a role you are allowed to manage.');
            }
            if (strlen($password) < 10) {
                throw new RuntimeException('Set a password with at least 10 characters.');
            }
            $statement = db()->prepare('INSERT INTO roadline_app_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            flash('Account created. Share the temporary password securely.');
            redirect_to('accounts');
        }

        if ($action === 'update_account') {
            $admin = require_role(['admin', 'super_admin']);
            $targetId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            $name = post_string('name', 120);
            $email = mb_strtolower(post_string('email', 190));
            $role = post_string('role', 20);
            $password = (string) ($_POST['password'] ?? '');
            $allowedRoles = $admin['role'] === 'super_admin' ? ['admin', 'worker', 'user'] : ['worker', 'user'];
            $targetStatement = db()->prepare('SELECT role FROM roadline_app_users WHERE id = ? AND deleted_at IS NULL');
            $targetStatement->execute([$targetId]);
            $targetRole = $targetStatement->fetchColumn();
            $canManageTarget = is_string($targetRole) && in_array($targetRole, $allowedRoles, true);
            if (!$targetId || !$canManageTarget || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, $allowedRoles, true)) {
                throw new RuntimeException('Enter valid account details and a role you are allowed to manage.');
            }
            if ($password !== '' && strlen($password) < 10) {
                throw new RuntimeException('A replacement password must contain at least 10 characters.');
            }
            if ($password === '') {
                db()->prepare('UPDATE roadline_app_users SET name = ?, email = ?, role = ? WHERE id = ?')->execute([$name, $email, $role, $targetId]);
            } else {
                db()->prepare('UPDATE roadline_app_users SET name = ?, email = ?, role = ?, password_hash = ? WHERE id = ?')->execute([$name, $email, $role, password_hash($password, PASSWORD_DEFAULT), $targetId]);
            }
            flash('Account details updated.');
            redirect_to('accounts');
        }

        if ($action === 'account_status') {
            $admin = require_role(['admin', 'super_admin']);
            $targetId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            $operation = post_string('operation', 20);
            if (!$targetId || $targetId === (int) $admin['id'] || !in_array($operation, ['deactivate', 'activate', 'delete'], true)) {
                throw new RuntimeException('That account action is not available.');
            }
            $statement = db()->prepare('SELECT id, role FROM roadline_app_users WHERE id = ? AND deleted_at IS NULL');
            $statement->execute([$targetId]);
            $target = $statement->fetch();
            $manageable = $admin['role'] === 'super_admin'
                ? in_array($target['role'] ?? '', ['admin', 'worker', 'user'], true)
                : in_array($target['role'] ?? '', ['worker', 'user'], true);
            if (!$target || !$manageable) {
                throw new RuntimeException('You cannot manage that account.');
            }
            if ($operation === 'delete') {
                db()->prepare('UPDATE roadline_app_users SET is_active = 0, deleted_at = NOW() WHERE id = ?')->execute([$targetId]);
                flash('Account removed. Historical report and job records have been retained.');
            } else {
                db()->prepare('UPDATE roadline_app_users SET is_active = ? WHERE id = ?')->execute([$operation === 'activate' ? 1 : 0, $targetId]);
                flash($operation === 'activate' ? 'Account activated.' : 'Account deactivated.');
            }
            redirect_to('accounts');
        }

        if ($action === 'mark_notifications_read') {
            db()->prepare('UPDATE roadline_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([$actor['id']]);
            flash('Notifications marked as read.');
            redirect_to('notifications');
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $error) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof PDOException) {
            error_log($error->__toString());
            $message = str_contains($error->getMessage(), '1062')
                ? 'That email address is already registered.'
                : 'We could not complete that request. Please try again or contact an administrator.';
        } else {
            $message = $error instanceof RuntimeException
                ? $error->getMessage()
                : 'We could not complete that request. Please try again or contact an administrator.';
            if (!$error instanceof RuntimeException) {
                error_log($error->__toString());
            }
        }
        flash($message, 'error');
        redirect_to((string) ($_GET['view'] ?? 'dashboard'));
    }
}
