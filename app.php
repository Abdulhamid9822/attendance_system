<?php
// app.php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/includes/session.php';
$sid = startAppSession();

if (($_SESSION['auth_type'] ?? '') === 'branch') {
    require_once __DIR__ . '/includes/auth.php';
    logout();
    header('Location: /login.php');
    exit;
}

// Login tekshirish
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: /login.php");
    exit;
}

$_sid = (string)($_SESSION['session_context'] ?? '');

// Database
global $pdo;
if (!isset($pdo)) {
    require_once __DIR__ . '/includes/config/database.php';
}
require_once __DIR__ . '/includes/functions/branch_access.php';
ensureBranchAccessSchema($pdo);
if (($_SESSION['auth_type'] ?? '') === 'poster') {
    $accountName = (string)($_SESSION['account'] ?? $_SESSION['account_name'] ?? '');
    $posterUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($accountName !== '' && $posterUserId > 0) {
        $accessStmt = $pdo->prepare("
            SELECT access_role, spot_id
            FROM branch_user_access
            WHERE account_name = ? AND poster_user_id = ?
            LIMIT 1
        ");
        $accessStmt->execute([$accountName, $posterUserId]);
        $userAccess = $accessStmt->fetch(PDO::FETCH_ASSOC);
        if (($userAccess['access_role'] ?? '') === 'branch') {
            $_SESSION['auth_type'] = 'poster_branch';
            $_SESSION['branch_spot_id'] = (int)$userAccess['spot_id'];
        }
    }
}
try {
    assertBranchSessionActive($pdo);
} catch (RuntimeException $e) {
    http_response_code(403);
    exit('Filial menejerining ruxsati bekor qilingan. Qayta kiring yoki administratorga murojaat qiling.');
}

// ============================================
// TIL TIZIMI - language.php ni yuklash
// ============================================
// Language faylini yuklash (agar mavjud bo'lsa)
$language_file = __DIR__ . '/includes/language.php';
if (file_exists($language_file)) {
    require_once $language_file;
} else {
    // Agar language.php bo'lmasa, oddiy funksiyalar
    if (!function_exists('__')) {
        function __($key, $default = null) {
            return $default ?? $key;
        }
    }
    if (!function_exists('getCurrentLanguage')) {
        function getCurrentLanguage() {
            return $_SESSION['user_lang'] ?? 'en';
        }
    }
    if (!function_exists('formatMoney')) {
        function formatMoney($amount, $currency = null) {
            global $currency_symbol;
            $symbol = $currency ?? $currency_symbol ?? "USD";
            return number_format($amount, 0, ',', ' ') . ' ' . $symbol;
        }
    }
}

// ============================================
// Account ma'lumotlari
// ============================================
$account_id = $_SESSION['account_number'] ?? null;
$account_name = $_SESSION['account_name'] ?? null;
$api_token = $_SESSION['access_token'] ?? null;

// ============================================
// SOZLAMALAR - BAZADAN TILNI OLISH
// ============================================
$company_name = $_SESSION['company_name'] ?? '—';
$current_lang = $_SESSION['user_lang'] ?? 'uz';
$currency_symbol = $_SESSION['currency_symbol'] ?? "so'm";
$currency_code = $_SESSION['currency_code'] ?? 'UZS';
$timezone = $_SESSION['timezone'] ?? 'Asia/Tashkent';
$grace_minutes = $_SESSION['grace_minutes'] ?? 20;

try {
    if ($account_id) {
        $stmt = $pdo->prepare("SELECT kalit, qiymat FROM sozlamalar WHERE account_id = ?");
        $stmt->execute([$account_id]);
        $db_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if ($db_settings) {
            $current_lang = $db_settings['app_language'] ?? $current_lang;
            $currency_symbol = $db_settings['currency_symbol'] ?? $currency_symbol;
            $currency_code = $db_settings['currency_code'] ?? $currency_code;
            $timezone = $db_settings['timezone'] ?? $timezone;
            $company_name = $db_settings['company_name'] ?? $company_name;
            $grace_minutes = $db_settings['grace_minutes'] ?? $grace_minutes;
        }
    }
    
    // Agar sessionda til bo'lmasa, bazadan olingan tilni o'rnatamiz
    if (!isset($_SESSION['user_lang']) && $current_lang) {
        $_SESSION['user_lang'] = $current_lang;
    }
    
    // Agar sessionda til bo'lsa, uni ishlatamiz (ustunlik beramiz)
    if (isset($_SESSION['user_lang'])) {
        $current_lang = $_SESSION['user_lang'];
    }
} catch (Exception $e) {
    error_log("Settings error: " . $e->getMessage());
}

// ============================================
// POSTER API DAN TIL MA'LUMOTLARINI YANGILASH
// ============================================
if ($api_token && $account_id) {
    $account_domain = $_SESSION['account_domain'] ?? $account_id;
    $url = "https://{$account_domain}.joinposter.com/api/settings/getAllSettings?token={$api_token}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $api_resp = curl_exec($ch);
    $apiError = curl_error($ch);
    curl_close($ch);

    if ($api_resp === false) {
        error_log("Poster settings request failed: " . $apiError);
    }

    if ($api_resp) {
        $api_data = json_decode($api_resp, true);
        if (isset($api_data['response'])) {
            $resp = $api_data['response'];
            
            // Poster API dan tilni olish
            $api_lang = $resp['lang'] ?? 'en';
            $api_company_name = $resp['company_name'] ?? '—';
            $api_timezone = $resp['timezones'] ?? 'Asia/Tashkent';
            
            // Valyuta ma'lumotlari
            $api_currency_symbol = "so'm";
            $api_currency_code = 'UZS';
            if (isset($resp['currency'])) {
                if (is_array($resp['currency'])) {
                    $api_currency_code = strtoupper($resp['currency']['currency_code'] ?? 'UZS');
                    $api_currency_symbol = $resp['currency']['currency_symbol'] ?? "so'm";
                } else {
                    $api_currency_code = strtoupper($resp['currency']);
                    $syms = ['UZS' => "so'm", 'USD' => '$', 'EUR' => '€', 'RUB' => '₽'];
                    $api_currency_symbol = $syms[$api_currency_code] ?? "so'm";
                }
            }
            
            // Ma'lumotlarni bazaga saqlash
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO sozlamalar (account_id, kalit, qiymat) VALUES
                        (:aid1,'company_name',:cn),
                        (:aid2,'app_language',:lg),
                        (:aid3,'currency_code',:cc),
                        (:aid4,'currency_symbol',:cs),
                        (:aid5,'timezone',:tz)
                    ON DUPLICATE KEY UPDATE qiymat = VALUES(qiymat)
                ");
                $stmt->execute([
                    ':aid1' => $account_id,
                    ':aid2' => $account_id,
                    ':aid3' => $account_id,
                    ':aid4' => $account_id,
                    ':aid5' => $account_id,
                    ':cn' => $api_company_name,
                    ':lg' => $api_lang,
                    ':cc' => $api_currency_code,
                    ':cs' => $api_currency_symbol,
                    ':tz' => $api_timezone
                ]);
            } catch (Exception $e) {
                error_log("Settings update error: " . $e->getMessage());
            }
            
            // Agar sessionda til bo'lmasa yoki bazadagi til eskirgan bo'lsa, API dan olgan tilni ishlatamiz
            if (!isset($_SESSION['user_lang']) || $_SESSION['user_lang'] !== $api_lang) {
                // Session tilini yangilash (agar foydalanuvchi o'zi o'zgartmagan bo'lsa)
                $_SESSION['user_lang'] = $api_lang;
                $current_lang = $api_lang;
            }
            
            $company_name = $api_company_name;
            $currency_symbol = $api_currency_symbol;
            $currency_code = $api_currency_code;
            $timezone = $api_timezone;
        }
    }
}

// ============================================
// SESSION GA TIL MA'LUMOTLARINI YOZISH
// ============================================
$_SESSION['user_lang'] = $current_lang;
$_SESSION['currency_code'] = strtoupper($currency_code);
$_SESSION['currency_symbol'] = $currency_symbol;
$_SESSION['timezone'] = $timezone;
$_SESSION['grace_minutes'] = $grace_minutes;
$_SESSION['company_name'] = $company_name;

// PHP vaqt zonasini o'rnatish
date_default_timezone_set($timezone);

// Global o'zgaruvchilar
$GLOBALS['lang'] = $current_lang;
$GLOBALS['currency'] = $currency_code;
$GLOBALS['currency_symbol'] = $currency_symbol;
$GLOBALS['timezone'] = $timezone;
$GLOBALS['company_name'] = $company_name;

// ============================================
// TIL MA'LUMOTLARINI YUKLASH (language.php dan)
// ============================================
// Agar language.php da loadTranslations funksiyasi bo'lsa
if (function_exists('loadTranslations')) {
    loadTranslations($current_lang);
}

// ============================================
// FUNKSIYALAR
// ============================================
$includes = [
    __DIR__ . '/includes/functions/helpers.php',
    __DIR__ . '/includes/functions/access_token.php',
    __DIR__ . '/includes/functions/attendance.php',
    __DIR__ . '/includes/functions/employee.php',
    __DIR__ . '/includes/functions/poster.php',
    __DIR__ . '/includes/functions/salary.php',
    __DIR__ . '/includes/functions/report.php',
];

foreach ($includes as $f) {
    if (file_exists($f)) require_once $f;
}

// ============================================
// AKTIV TAB
// ============================================
$tab = preg_replace('/[^a-z_]/', '', $_GET['tab'] ?? 'dashboard');
$allowedTabs = ['dashboard', 'employees', 'attendance', 'reports', 'settings', 'positions', 'branches', 'shifts'];
if (!in_array($tab, $allowedTabs)) $tab = 'dashboard';
if (isBranchUser() && in_array($tab, ['positions', 'branches', 'shifts'], true)) {
    http_response_code(403);
    exit('Ushbu bo‘lim faqat bosh administrator uchun.');
}
if (isBranchUser() && $tab === 'attendance' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    exit('Filial foydalanuvchisi davomatni faqat ko‘rishi mumkin.');
}
if (isBranchUser() && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($tab, ['employees', 'reports'], true)
    && !($tab === 'employees' && isset($_POST['sync_employees']))) {
    $targetEmployeeId = (int)($_POST['employee_id'] ?? 0);
    $settingId = 0;
    if ($tab === 'employees' && isset($_POST['update_setting'])) {
        $settingId = (int)$_POST['update_setting'];
    } elseif ($tab === 'employees' && isset($_POST['delete_setting'])) {
        $settingId = (int)$_POST['delete_setting'];
    }
    if ($targetEmployeeId <= 0 && $settingId > 0) {
        $ownerStmt = $pdo->prepare("
            SELECT s.xodim_id
            FROM oylik_sozlamalar s
            INNER JOIN xodimlar e ON e.id = s.xodim_id
            WHERE s.id = ? AND e.account_name = ?
            LIMIT 1
        ");
        $ownerStmt->execute([$settingId, $_SESSION['account'] ?? $_SESSION['account_name'] ?? '']);
        $targetEmployeeId = (int)$ownerStmt->fetchColumn();
    }

    try {
        assertBranchEmployeeAccess(
            $pdo,
            $targetEmployeeId,
            $_SESSION['account'] ?? $_SESSION['account_name'] ?? ''
        );
    } catch (Throwable $e) {
        http_response_code(403);
        exit('Xodim topilmadi yoki filial ruxsati yo‘q.');
    }
}

$attendanceSyncResult = null;
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && in_array($tab, ['dashboard', 'reports'], true)
) {
    require_once __DIR__ . '/includes/functions/sync_functions.php';
    try {
        $attendanceSyncResult = syncAttendanceData();
        if (($attendanceSyncResult['status'] ?? 'error') !== 'success') {
            error_log('Sahifa ochilganda davomat sinxronlash yakunlanmadi: ' . json_encode($attendanceSyncResult));
        }
    } catch (Throwable $e) {
        error_log('Sahifa ochilganda davomat sinxronlash xatosi: ' . $e->getMessage());
        $attendanceSyncResult = ['status' => 'error'];
    }
}

// ============================================
// HEADER
// ============================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    include __DIR__ . '/views/header.php';
}

// ============================================
// MODUL
// ============================================
if ($tab === 'dashboard') {
    $moduleFile = __DIR__ . '/views/dashboard.php';
} else {
    $moduleFile = __DIR__ . '/views/modules/' . $tab . '.php';
}

if (file_exists($moduleFile)) {
    include $moduleFile;
} else {
    echo '<p style="padding:20px;color:red;">⚠️ Modul topilmadi</p>';
}

ob_end_flush();
