<?php
/**
 * Minimal .env loader — no Composer package needed.
 * Reads KEY=VALUE lines from the .env file at the project root and
 * makes them available via getenv() / $_ENV, exactly like dotenv did
 * for the Node version.
 */

function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        // Skip comments
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        // Strip matching surrounding quotes, if present
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        if ($name === '') {
            continue;
        }

        putenv("$name=$value");
        $_ENV[$name] = $value;
    }
}

// Project root is one directory up from /src
loadEnv(dirname(__DIR__) . '/.env');
