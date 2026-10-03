<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';
date_default_timezone_set($config['app']['timezone']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('roadline_safety_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

function db(): PDO
{
    static $connection;
    global $config;

    if (!$connection instanceof PDO) {
        $database = $config['database'];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $database['host'],
            $database['port'],
            $database['name'],
            $database['charset']
        );
        $connection = new PDO($dsn, $database['username'], $database['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $connection;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        exit('Your session has expired. Refresh the page and try again.');
    }
}

function current_user(): ?array
{
    static $checked = false;
    static $user = null;

    if ($checked) {
        return $user;
    }
    $checked = true;

    $sessionUser = $_SESSION['user'] ?? null;
    if (!is_array($sessionUser) || empty($sessionUser['id'])) {
        return null;
    }

    $statement = db()->prepare('SELECT id, name, email, role FROM roadline_app_users WHERE id = ? AND is_active = 1 AND deleted_at IS NULL');
    $statement->execute([$sessionUser['id']]);
    $account = $statement->fetch();
    if (!$account) {
        unset($_SESSION['user']);
        session_regenerate_id(true);
        return null;
    }

    $user = [
        'id' => (int) $account['id'],
        'name' => $account['name'],
        'email' => $account['email'],
        'role' => $account['role'],
    ];
    $_SESSION['user'] = $user;

    return $user;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: index.php?view=login');
        exit;
    }

    return $user;
}

function require_role(array $roles): array
{
    $user = require_user();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to perform this action.');
    }

    return $user;
}

function roadline_location_source(): ?array
{
    global $config;
    $settings = $config['roadline'];
    $configured = array_filter($settings, static fn (string $value): bool => $value !== '');

    if (count($configured) !== 3) {
        return null;
    }

    $columns = db()->query('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')
        ->fetchAll();
    $available = [];
    foreach ($columns as $column) {
        $available[$column['TABLE_NAME']][$column['COLUMN_NAME']] = $column['DATA_TYPE'];
    }

    if (!isset(
        $available[$settings['table']][$settings['id_column']],
        $available[$settings['table']][$settings['label_column']]
    )) {
        return null;
    }

    return $settings;
}

function roadline_locations(): array
{
    $source = roadline_location_source();
    if ($source === null) {
        return [];
    }

    $table = '`' . str_replace('`', '``', $source['table']) . '`';
    $idColumn = '`' . str_replace('`', '``', $source['id_column']) . '`';
    $labelColumn = '`' . str_replace('`', '``', $source['label_column']) . '`';
    $column = db()->prepare('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $column->execute([$source['table'], $source['label_column']]);
    $dataType = $column->fetchColumn();
    if ($dataType === 'geometry') {
        $label = "CONCAT('Roadline hazard #', {$idColumn}, ' · ', ST_AsText({$labelColumn}))";
        $coordinates = "ST_Y(ST_Centroid({$labelColumn})) AS latitude, ST_X(ST_Centroid({$labelColumn})) AS longitude";
    } else {
        $label = $labelColumn;
        $coordinates = 'NULL AS latitude, NULL AS longitude';
    }
    $sql = "SELECT {$idColumn} AS id, {$label} AS label, {$coordinates} FROM {$table} WHERE {$labelColumn} IS NOT NULL ORDER BY {$idColumn} DESC LIMIT 1000";

    return db()->query($sql)->fetchAll();
}