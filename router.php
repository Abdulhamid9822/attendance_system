<?php
// router.php

error_log("=== ROUTER START ===");

header('ngrok-skip-browser-warning: true');

$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;

if (
    preg_match('#^/(?:includes|views|vendor|config|sessions|logs|cache|tmp|temp|backup)(?:/|$)#i', $uri)
    || preg_match('#^/\.(?!well-known(?:/|$))#i', $uri)
    || preg_match('#^/(?:manage|check_speed|test)\.php$|^/modules/(?:test|hikvision_sync|settings_ajax)\.php$#i', $uri)
    || preg_match('#\.(?:log|sql|sql\.gz|bak|bak2|save|phpt|pub)$#i', $uri)
    || preg_match('#^/(?:credentials\.json|token\.json|token_[^/]+\.txt|account_[^/]+\.txt)$#i', $uri)
) {
    http_response_code(404);
    exit;
}

// ============================================
// 1) STATIK FAYLLAR
// ============================================
$static = ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'eot'];
$ext = pathinfo($uri, PATHINFO_EXTENSION);
if (in_array($ext, $static) && is_file($file)) {
    $rootPath = realpath(__DIR__);
    $staticPath = realpath($file);
    if (
        $rootPath === false
        || $staticPath === false
        || strpos(strtolower($staticPath), strtolower($rootPath . DIRECTORY_SEPARATOR)) !== 0
    ) {
        http_response_code(404);
        exit;
    }

    // CSS uchun header
    if ($ext === 'css') {
        header('Content-Type: text/css');
    }
    if ($ext === 'js') {
        header('Content-Type: application/javascript');
    }
    readfile($file);
    exit;
}

// ============================================
// 2) POSTER OAUTH CALLBACK: /index.php/{32char}
// ============================================
if (preg_match('#^/index\.php/([a-f0-9]{32})#', $uri, $m)) {
    $_GET['code'] = $m[1];
    require __DIR__ . '/index.php';
    return true;
}

// ============================================
// 3) TAB YO'LLARI: /{tab}/{sid}  (masalan /employees/5cf3a53a...)
// ============================================
$allowedTabs = ['dashboard', 'employees', 'attendance', 'reports', 'settings', 'positions', 'branches', 'shifts'];
$tabPattern  = implode('|', $allowedTabs);

if (preg_match('#^/(' . $tabPattern . ')/([a-f0-9]{32})/?$#', $uri, $m)) {
    $_GET['tab'] = $m[1];
    $_GET['sid'] = $m[2];
    require __DIR__ . '/index.php';
    return true;
}

if (preg_match('#^/(' . $tabPattern . ')/?$#', $uri, $m)) {
    $_GET['tab'] = $m[1];
    require __DIR__ . '/index.php';
    return true;
}

// /logout/{sid}
if (preg_match('#^/logout/([a-f0-9]{32})/?$#', $uri, $m)) {
    $_GET['tab'] = 'logout';
    $_GET['sid'] = $m[1];
    require __DIR__ . '/index.php';
    return true;
}

if (preg_match('#^/logout/?$#', $uri)) {
    $_GET['logout'] = '1';
    require __DIR__ . '/index.php';
    return true;
}

// ============================================
// 4) MAVJUD PHP FAYL (masalan /login.php, /ajax/xxx.php)
// ============================================
if (is_file($file) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
    require $file;
    return true;
}

// ============================================
// 5) QOLGANI — index.php orqali (eski ?tab=&sid= format ham ishlaydi)
// ============================================
error_log("ROUTER: default -> index.php");
require __DIR__ . '/index.php';
