<?php
declare(strict_types=1);

function load_environment_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    if (!is_readable($path)) {
        throw new RuntimeException('The environment file is not readable.');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('The environment file could not be read.');
    }

    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            throw new RuntimeException(sprintf('Invalid environment setting on line %d.', $lineNumber + 1));
        }

        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name)) {
            throw new RuntimeException(sprintf('Invalid environment variable name on line %d.', $lineNumber + 1));
        }

        if (strlen($value) >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && $value[strlen($value) - 1] === $quote) {
                $value = substr($value, 1, -1);
            }
        }

        if (getenv($name) !== false) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
