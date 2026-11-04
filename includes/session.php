<?php

function loadAppEnvironment() {
    $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envFile) || !is_readable($envFile)) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Application environment file could not be read.');
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

loadAppEnvironment();

function appSessionContextFromRequest() {
    foreach ([$_GET['sid'] ?? null, $_GET['context'] ?? null, $_GET['tab_id'] ?? null, $_POST['sid'] ?? null] as $context) {
        if (is_string($context) && preg_match('/^[a-f0-9]{32}$/', $context)) {
            return $context;
        }
    }

    return null;
}

function startAppSession($context = null) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return $_SESSION['session_context'] ?? $context ?? '';
    }

    if (!is_string($context) || !preg_match('/^[a-f0-9]{32}$/', $context)) {
        $context = appSessionContextFromRequest() ?? bin2hex(random_bytes(16));
    }

    $sessionPath = getenv('SESSION_SAVE_PATH');
    if (!is_string($sessionPath) || $sessionPath === '') {
        $sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'attendance-system-sessions';
    }
    if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
        throw new RuntimeException('Session storage directory could not be created.');
    }
    if (!is_writable($sessionPath)) {
        throw new RuntimeException('Session storage directory is not writable.');
    }

    session_save_path($sessionPath);
    session_name('ATTENDANCE_' . substr(hash('sha256', $context), 0, 24));

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => $isHttps ? 'None' : 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    $sessionLifetime = filter_var(getenv('SESSION_LIFETIME') ?: 86400, FILTER_VALIDATE_INT);
    if ($sessionLifetime === false || $sessionLifetime < 300 || $sessionLifetime > 604800) {
        throw new RuntimeException('SESSION_LIFETIME must be between 300 and 604800 seconds.');
    }
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    if (!session_start()) {
        throw new RuntimeException('Session could not be started.');
    }

    $_SESSION['session_context'] = $context;
    return $context;
}
