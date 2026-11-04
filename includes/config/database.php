<?php
// includes/config/database.php - Ma'lumotlar bazasi ulanishi

// ============================================
// ERROR REPORTING
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ============================================
// LOCALHOST TEKSHIRISH (XAVFSIZ)
// ============================================
function isLocalhost() {
    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
    $httpHost = $_SERVER['HTTP_HOST'] ?? '';
    
    if ($serverName === 'localhost' || $serverName === '127.0.0.1') return true;
    if ($serverAddr === '127.0.0.1' || $serverAddr === '::1') return true;
    if ($httpHost === 'localhost' || $httpHost === '127.0.0.1') return true;
    
    return false;
}

require_once __DIR__ . '/../session.php';
loadAppEnvironment();

// ============================================
// MYSQL SOZLAMALARI
// ============================================
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'ivms';
$username = getenv('DB_USER');
$password = getenv('DB_PASS');
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

// ============================================
// POSTER SOZLAMALARI (.ENV DAN)
// ============================================
$poster_application_id = getenv('POSTER_APPLICATION_ID') ?: '4753';
$poster_application_secret = getenv('POSTER_APPLICATION_SECRET') ?: null;

// ============================================
// GLOBAL O'ZGARUVCHILAR
// ============================================
$GLOBALS['pdo'] = null;
$GLOBALS['poster_application_id'] = $poster_application_id;
$GLOBALS['poster_application_secret'] = $poster_application_secret;

// ============================================
// PDO ULAMISH
// ============================================
if ($username === false || $username === '' || $password === false || $password === '') {
    error_log("Required database environment variables are missing.");
    http_response_code(500);
    die("⚠️ Tizim konfiguratsiyasida texnik muammo yuz berdi.");
}

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10
        ]
    );
    
    $GLOBALS['pdo'] = $pdo;
    
} catch(PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    
    if (isLocalhost()) {
        die("❌ Database Error: " . $e->getMessage());
    } else {
        die("⚠️ Tizimda texnik muammo yuz berdi. Iltimos, keyinroq qayta urinib ko'ring.");
    }
}

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$salaryTableExists = $pdo->query("
    SELECT 1
    FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'oylik_sozlamalar'
")->fetchColumn();
if ($salaryTableExists) {
    $salaryColumns = $pdo->query("SHOW COLUMNS FROM oylik_sozlamalar")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('smena_summa', $salaryColumns, true)) {
        $pdo->exec("ALTER TABLE oylik_sozlamalar ADD COLUMN smena_summa DECIMAL(15,2) NOT NULL DEFAULT 0");
        error_log("smena_summa column added to oylik_sozlamalar");
    }
    if (!in_array('auto_copied', $salaryColumns, true)) {
        $pdo->exec("ALTER TABLE oylik_sozlamalar ADD COLUMN auto_copied TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!in_array('balans_saqlangan', $salaryColumns, true)) {
        $pdo->exec("ALTER TABLE oylik_sozlamalar ADD COLUMN balans_saqlangan TINYINT(1) NOT NULL DEFAULT 0");
        // Avval saqlangan nolga teng bo'lmagan balanslar hisoblangan deb belgilanadi
        $pdo->exec("UPDATE oylik_sozlamalar SET balans_saqlangan = 1 WHERE keyingi_oyga_balansi IS NOT NULL AND keyingi_oyga_balansi != 0");
    }
}

?>