<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$name = trim((string) (getenv('SUPER_ADMIN_NAME') ?: ''));
$email = mb_strtolower(trim((string) (getenv('SUPER_ADMIN_EMAIL') ?: '')));
$password = (string) (getenv('SUPER_ADMIN_PASSWORD') ?: '');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Set SUPER_ADMIN_NAME, SUPER_ADMIN_EMAIL, and SUPER_ADMIN_PASSWORD (12+ characters) in your process environment, then run this script again.\n");
    exit(1);
}

$statement = db()->prepare("INSERT INTO roadline_app_users (name, email, password_hash, role) VALUES (?, ?, ?, 'super_admin')");
$statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
putenv('SUPER_ADMIN_PASSWORD');
fwrite(STDOUT, "Super Admin created for {$email}. Remove the password from your environment.\n");