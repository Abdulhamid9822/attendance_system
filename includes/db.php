<?php
// includes/db.php - Ma'lumotlar bazasi ulanishi
// MUHIM: session_start() INDEX.PHP da boshlangan!

// ============================================
// XAVFSIZLIK: Error reporting ni o'chirish
// ============================================

// SERVER_NAME ni tekshiramiz
$serverName = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '';

// SERVER_ADDR ni tekshiramiz (faqat mavjud bo'lsa)
$serverAddr = $_SERVER['SERVER_ADDR'] ?? '';

// Localhost ekanligini aniqlash
$isLocal = false;

// 1. SERVER_NAME bo'yicha
if ($serverName === 'localhost' || $serverName === '127.0.0.1' || $serverName === '::1') {
    $isLocal = true;
}

// 2. SERVER_ADDR bo'yicha (agar mavjud bo'lsa)
if (!$isLocal && $serverAddr === '127.0.0.1' || $serverAddr === '::1') {
    $isLocal = true;
}

// 3. HTTP_HOST bo'yicha
if (!$isLocal) {
    $httpHost = $_SERVER['HTTP_HOST'] ?? '';
    if ($httpHost === 'localhost' || $httpHost === '127.0.0.1') {
        $isLocal = true;
    }
}

// Error reporting
if ($isLocal) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
}

// ============================================
// .ENV FAYLDAN MA'LUMOTLARNI O'QISH
// ============================================
function loadEnv() {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Kommentariyalarni o'tkazib yuborish
            if (strpos(trim($line), '#') === 0) continue;
            
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Qiymatni o'rnatish
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        return true;
    }
    return false;
}

// .env faylni yuklash
loadEnv();

// ============================================
// MYSQL SOZLAMALARI (ENV dan)
// ============================================
$host = getenv('DB_HOST') ?: '192.168.1.111';
$dbname = getenv('DB_NAME') ?: 'salary_system';
$username = getenv('DB_USER');
$password = getenv('DB_PASS');
$db_charset = getenv('DB_CHARSET') ?: 'utf8mb4';

if ($username === false || $username === '' || $password === false || $password === '') {
    error_log("Required database environment variables are missing.");
    http_response_code(500);
    die("⚠️ Tizim konfiguratsiyasida texnik muammo yuz berdi.");
}

// ============================================
// XAVFSIZ ULAMISH
// ============================================
try {
    // MySQL ga ulanish
    $pdo = new PDO(
        "mysql:host=$host;charset=$db_charset", 
        $username, 
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5
        ]
    );
    
    // Bazani yaratish va ishlatish
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET $db_charset COLLATE {$db_charset}_unicode_ci");
    $pdo->exec("USE `$dbname`");
    $pdo->exec("SET NAMES $db_charset");
    
    // ============================================
    // JADVALLARNI YARATISH
    // ============================================
    
    // 1. Sozlamalar jadvali
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sozlamalar` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `kalit` VARCHAR(100) UNIQUE NOT NULL,
        `qiymat` TEXT DEFAULT NULL,
        `turi` ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
        `aktiv` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=$db_charset COLLATE={$db_charset}_unicode_ci");
    
    // 2. Xodimlar jadvali
    $pdo->exec("CREATE TABLE IF NOT EXISTS `xodimlar` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `poster_id` INT UNIQUE NULL,
        `poster_user_id` INT NULL,
        `account_name` VARCHAR(100) NULL,
        `ismi` VARCHAR(255) NOT NULL,
        `familyasi` VARCHAR(255) DEFAULT NULL,
        `lavozimi` VARCHAR(255) DEFAULT NULL,
        `telefon` VARCHAR(50) DEFAULT NULL,
        `email` VARCHAR(255) DEFAULT NULL,
        `manzil` TEXT DEFAULT NULL,
        `turi` ENUM('fixed', 'percent') DEFAULT 'fixed',
        `oylik_miqdori` DECIMAL(15,2) DEFAULT 0,
        `foiz_miqdori` DECIMAL(10,2) DEFAULT 0,
        `ishga_olingan_sana` DATE DEFAULT NULL,
        `holati` ENUM('active', 'inactive') DEFAULT 'active',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_holati` (`holati`),
        INDEX `idx_poster_id` (`poster_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=$db_charset COLLATE={$db_charset}_unicode_ci");
    
    // 3. Oylik sozlamalar jadvali
    $pdo->exec("CREATE TABLE IF NOT EXISTS `oylik_sozlamalar` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `xodim_id` INT NOT NULL,
        `oy_vaqt` DATE NOT NULL,
        `amal_qiladi` DATE NULL,
        `ishga_kelish_vaqti` TIME NULL,
        `ishdan_ketish_vaqti` TIME NULL,
        `tushlik_boshlanish` TIME NULL,
        `tushlik_tugash` TIME NULL,
        `oylik_maosh` DECIMAL(15,2) DEFAULT 0,
        `maosh_turi` TEXT NULL,
        `foiz_qiymati` DECIMAL(10,2) DEFAULT 0,
        `poster_user_id` INT NULL,
        `ishlagan_kun` DECIMAL(10,1) DEFAULT 0,
        `hisoblangan_maosh` DECIMAL(15,2) DEFAULT 0,
        `bonus` DECIMAL(15,2) DEFAULT 0,
        `jarima` DECIMAL(15,2) DEFAULT 0,
        `qarzdorlik` DECIMAL(15,2) DEFAULT 0,
        `oldingi_oy_balansi` DECIMAL(15,2) DEFAULT 0,
        `keyingi_oyga_balansi` DECIMAL(15,2) DEFAULT 0,
        `soatlik_stavka` DECIMAL(12,2) DEFAULT 0,
        `smena_summa` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `erta_kelish_bonus_enabled` TINYINT(1) DEFAULT 1,
        `erta_kelish_bonus_amount` DECIMAL(12,2) DEFAULT 5000,
        `erta_kelish_max_bonus` DECIMAL(12,2) DEFAULT 50000,
        `kech_ketish_bonus_enabled` TINYINT(1) DEFAULT 1,
        `kech_ketish_bonus_amount` DECIMAL(12,2) DEFAULT 10000,
        `kech_ketish_max_bonus` DECIMAL(12,2) DEFAULT 100000,
        `kech_kelish_jarima_enabled` TINYINT(1) DEFAULT 1,
        `kech_kelish_jarima_per_minute` DECIMAL(12,2) DEFAULT 1000,
        `kech_kelish_max_jarima` DECIMAL(12,2) DEFAULT 50000,
        `erta_ketish_jarima_enabled` TINYINT(1) DEFAULT 1,
        `erta_ketish_jarima_per_minute` DECIMAL(12,2) DEFAULT 1000,
        `erta_ketish_max_jarima` DECIMAL(12,2) DEFAULT 50000,
        `kech_kelish_grace_minutes` INT DEFAULT 10,
        `erta_ketish_grace_minutes` INT DEFAULT 10,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`xodim_id`) REFERENCES `xodimlar`(`id`) ON DELETE CASCADE,
        UNIQUE KEY `unique_monthly` (`xodim_id`, `oy_vaqt`, `amal_qiladi`),
        INDEX `idx_oy_vaqt` (`oy_vaqt`),
        INDEX `idx_amal_qiladi` (`amal_qiladi`)
    ) ENGINE=InnoDB DEFAULT CHARSET=$db_charset COLLATE={$db_charset}_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `employee_work_schedules` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `xodim_id` INT NOT NULL,
        `account_name` VARCHAR(100) NOT NULL,
        `effective_date` DATE NOT NULL,
        `ishga_kelish_vaqti` TIME NOT NULL,
        `ishdan_ketish_vaqti` TIME NOT NULL,
        `tushlik_boshlanish` TIME NOT NULL,
        `tushlik_tugash` TIME NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`xodim_id`) REFERENCES `xodimlar`(`id`) ON DELETE CASCADE,
        UNIQUE KEY `unique_employee_schedule_date` (`xodim_id`, `effective_date`),
        INDEX `idx_account_schedule_date` (`account_name`, `effective_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=$db_charset COLLATE={$db_charset}_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `employee_work_schedule_templates` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `account_name` VARCHAR(100) NOT NULL,
        `name` VARCHAR(100) NOT NULL,
        `ishga_kelish_vaqti` TIME NOT NULL,
        `ishdan_ketish_vaqti` TIME NOT NULL,
        `tushlik_boshlanish` TIME NOT NULL,
        `tushlik_tugash` TIME NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_account_schedule_template` (`account_name`, `name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=$db_charset COLLATE={$db_charset}_unicode_ci");
    
    // 4. Davomat jadvali (attendance_data)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `attendance_data` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` VARCHAR(50) NOT NULL,
        `account_name` VARCHAR(100) NULL,
        `auth_date` DATE NOT NULL,
        `in_time` TIME NULL,
        `in_datetime` DATETIME NULL,
        `in_device` VARCHAR(100) NULL,
        `in_late_minutes` INT DEFAULT 0,
        `out_time` TIME NULL,
        `out_datetime` DATETIME NULL,
        `out_device` VARCHAR(100) NULL,
        `out_early_minutes` INT DEFAULT 0,
        `total_work_hours` DECIMAL(10,2) DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_employee` (`employee_id`),
        INDEX `idx_auth_date` (`auth_date`),
        UNIQUE KEY `unique_attendance` (`employee_id`, `auth_date`, `account_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=$db_charset COLLATE={$db_charset}_unicode_ci");
    
    // 5. Bonus va jarima jadvali
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bonus_jarima` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `xodim_id` INT NOT NULL,
        `oy_vaqt` DATE NOT NULL,
        `turi` ENUM('bonus', 'penalty') NOT NULL,
        `miqdor` DECIMAL(15,2) NOT NULL DEFAULT 0,
        `sabab` TEXT DEFAULT NULL,
        `izoh` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`xodim_id`) REFERENCES `xodimlar`(`id`) ON DELETE CASCADE,
        INDEX `idx_xodim_oy` (`xodim_id`, `oy_vaqt`)
    ) ENGINE=InnoDB DEFAULT CHARSET=$db_charset COLLATE={$db_charset}_unicode_ci");
    
    // 6. Accounts jadvali
    $pdo->exec("CREATE TABLE IF NOT EXISTS `accounts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `account_id` VARCHAR(100) UNIQUE NOT NULL,
        `account_name` VARCHAR(255) DEFAULT NULL,
        `access_token` TEXT DEFAULT NULL,
        `token_expires` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=$db_charset COLLATE={$db_charset}_unicode_ci");
    
    // ============================================
    // DEFAULT SOZLAMALAR
    // ============================================
    $defaults = [
        ['app_name', 'HR Boshqaruv Tizimi', 'string'],
        ['app_language', 'uz', 'string'],
        ['company_name', 'MCHJ "Korxona"', 'string'],
        ['company_address', 'Toshkent sh., Amir Temur ko\'ch. 15', 'string'],
        ['company_phone', '+998 71 123-45-67', 'string'],
        ['company_email', 'info@korxona.uz', 'string'],
        ['work_start_time', '09:00:00', 'string'],
        ['work_end_time', '18:00:00', 'string'],
        ['grace_minutes', '20', 'number'],
        ['default_salary', '1000000', 'number'],
        ['currency', 'so\'m', 'string'],
        ['currency_symbol', 'so\'m', 'string'],
        ['timezone', 'Asia/Tashkent', 'string'],
        ['working_days_per_month', '0', 'number'],
        ['work_hours_per_day', '8', 'number'],
        ['overtime_rate', '1.5', 'number'],
        ['half_day_rate', '0.5', 'number'],
        ['hikvision_enabled', '0', 'boolean'],
        ['hikvision_ip', '192.168.1.100', 'string'],
        ['hikvision_port', '80', 'string'],
        ['hikvision_user', 'admin', 'string'],
        ['hikvision_pass', '', 'string'],
        ['poster_access_token', '', 'string'],
        ['last_employee_sync', '0', 'number']
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO `sozlamalar` (`kalit`, `qiymat`, `turi`) VALUES (?, ?, ?)");
    foreach($defaults as $d) {
        $stmt->execute([$d[0], $d[1], $d[2]]);
    }
    
    // ============================================
    // SOZLAMALARNI O'QISH
    // ============================================
    $settings = [];
    $stmt = $pdo->query("SELECT `kalit`, `qiymat` FROM `sozlamalar` WHERE `aktiv` = 1");
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['kalit']] = $row['qiymat'];
    }
    
    // ============================================
    // GLOBAL O'ZGARUVCHILARNI O'RNATISH
    // ============================================
    $GLOBALS['pdo'] = $pdo;
    $GLOBALS['settings'] = $settings;
    $GLOBALS['companyName'] = $settings['company_name'] ?? 'HR System';
    $GLOBALS['currentLang'] = $settings['app_language'] ?? 'uz';
    $GLOBALS['currency'] = $settings['currency'] ?? 'so\'m';
    $GLOBALS['currencySymbol'] = $settings['currency_symbol'] ?? 'so\'m';
    $GLOBALS['defaultSalary'] = (int)($settings['default_salary'] ?? 1000000);
    $GLOBALS['workingDays'] = (int)($settings['working_days_per_month'] ?? 0);
    $GLOBALS['workHoursPerDay'] = (float)($settings['work_hours_per_day'] ?? 8);
    $GLOBALS['overtimeRate'] = (float)($settings['overtime_rate'] ?? 1.5);
    $GLOBALS['halfDayRate'] = (float)($settings['half_day_rate'] ?? 0.5);
    $GLOBALS['workStartTime'] = $settings['work_start_time'] ?? '09:00:00';
    $GLOBALS['workEndTime'] = $settings['work_end_time'] ?? '18:00:00';
    $GLOBALS['graceMinutes'] = (int)($settings['grace_minutes'] ?? 20);
    $GLOBALS['isLocal'] = $isLocal;
    
} catch(PDOException $e) {
    // Xatolikni logga yozamiz
    error_log("Database error: " . $e->getMessage());
    
    // Xavfsiz xatolik xabari
    if ($isLocal) {
        die("❌ Ma'lumotlar bazasiga ulanishda xatolik: " . $e->getMessage());
    } else {
        die("⚠️ Tizimda texnik muammo yuz berdi. Iltimos, keyinroq qayta urinib ko'ring.");
    }
}
?>