<?php
// main_functions.php - TEZLASHTIRILGAN VERSIYA

// 1. Autoloader
spl_autoload_register(function($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/includes/';
    
    if(strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    
    $file = $base_dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if(file_exists($file)) require_once $file;
});

// 2. Faqat kerakli global funksiyalar
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// 3. Caching (ixtiyoriy)
$cache_enabled = true;

function getCachedOrFresh($key, $callback, $ttl = 3600) {
    global $cache_enabled;
    if(!$cache_enabled) return $callback();
    
    $cache_file = __DIR__ . '/cache/' . md5($key) . '.cache';
    if(file_exists($cache_file) && (time() - filemtime($cache_file)) < $ttl) {
        return unserialize(file_get_contents($cache_file));
    }
    
    $data = $callback();
    file_put_contents($cache_file, serialize($data));
    return $data;
}

// Global o'zgaruvchilar (tez kirish uchun)
$GLOBALS['settings'] = getCachedOrFresh('settings', function() {
    global $pdo;
    $settings = [];
    $stmt = $pdo->query("SELECT kalit, qiymat FROM sozlamalar");
    while($row = $stmt->fetch()) $settings[$row['kalit']] = $row['qiymat'];
    return $settings;
}, 86400); // 24 soat