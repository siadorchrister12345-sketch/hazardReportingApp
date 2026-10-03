<?php
declare(strict_types=1);

return [
    'database' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'roadline',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],
    'roadline' => [
        'table' => getenv('ROADLINE_TABLE') ?: 'hazards',
        'id_column' => getenv('ROADLINE_ID_COLUMN') ?: 'Hazard_ID',
        'label_column' => getenv('ROADLINE_LABEL_COLUMN') ?: 'Coordinates',
    ],
    'app' => [
        'name' => getenv('APP_NAME') ?: 'Roadline Safety',
        'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
    ],
];