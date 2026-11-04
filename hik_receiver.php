<?php
// /var/www/html/hik_receiver.php
date_default_timezone_set('Asia/Tashkent');

// ====== MYSQL SOZLAMALARI ======
// ====== LOG SOZLAMALARI ======
define('LOG_FILE', '/var/log/hikvision/receiver.log');
define('ATTENDANCE_LOG_FILE', '/var/log/hikvision/attendance.log');

$logDir = dirname(LOG_FILE);
if (!is_dir($logDir)) mkdir($logDir, 0755, true);

function writeLog($msg) {
    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function writeAttendanceLog($direction, $device_name, $employee_name, $datetime) {
    $icon = ($direction === 'IN') ? '🔵 KIRDI' : '🔴 CHIQDI';
    file_put_contents(ATTENDANCE_LOG_FILE, "{$icon} | {$device_name} | {$employee_name} | {$datetime}" . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function getDB() {
    require_once __DIR__ . '/includes/config/database.php';
    return $GLOBALS['pdo'];
}

// Qurilma so'rovi maxfiy kalit bilan autentifikatsiya qilinadi: /hik_receiver.php?key=...
function receiverSecret() {
    $value = getenv('HIK_RECEIVER_SECRET');
    if ($value !== false && $value !== '') {
        return (string)$value;
    }
    $envFile = __DIR__ . '/.env';
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
            list($k, $v) = explode('=', $line, 2);
            if (trim($k) === 'HIK_RECEIVER_SECRET') {
                return trim($v, " \t\"'");
            }
        }
    }
    return '';
}

function sendResponse($message = 'OK', $code = 200) {
    http_response_code($code);
    echo $message;
    writeLog("📤 Javob: {$message} ({$code})");
    exit;
}

$expectedKey = receiverSecret();
$givenKey = (string)($_GET['key'] ?? $_SERVER['HTTP_X_RECEIVER_KEY'] ?? '');
if ($expectedKey === '' || !hash_equals($expectedKey, $givenKey)) {
    writeLog('⛔ Ruxsatsiz so‘rov rad etildi - IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function getDeviceInfo($pdo, $ip) {
    if (empty($ip) || !$pdo) {
        return ['device_name' => $ip, 'account_name' => ''];
    }
    try {
        $stmt = $pdo->prepare("SELECT device_name, account_name FROM hikvision_devices WHERE ip_address = ? LIMIT 1");
        $stmt->execute([$ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'device_name' => $row['device_name'] ?? $ip,
                'account_name' => $row['account_name'] ?? ''
            ];
        }
    } catch (PDOException $e) {}
    return ['device_name' => $ip, 'account_name' => ''];
}

// ============================================================
// HTTPS / SSL
// ============================================================
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            || ($_SERVER['SERVER_PORT'] ?? 0) == 443;

if ($is_https) {
    writeLog("🔒 HTTPS so'rov");
} else {
    writeLog("🔓 HTTP so'rov");
}

// ============================================================
// ASOSIY KOD
// ============================================================
writeLog("📥 So'rov keldi - IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
writeLog("📋 Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'none'));

$raw_data = file_get_contents('php://input');
$data = null;

// ====== 1. MULTIPART ======
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false) {
    writeLog("📦 Multipart so'rov");
    
    if (isset($_POST['AccessControllerEvent'])) {
        $json_str = $_POST['AccessControllerEvent'];
        $parsed = json_decode($json_str, true);
        
        if (is_array($parsed)) {
            $eventType = $parsed['eventType'] ?? '';
            
            if (stripos($eventType, 'heartbeat') !== false) {
                writeLog("💓 Heartbeat - 200 OK");
                sendResponse('OK', 200);
            }
            
            if (isset($parsed['AccessControllerEvent']) && is_array($parsed['AccessControllerEvent'])) {
                $data = $parsed;
                writeLog("✅ POST dan JSON topildi");
            } else {
                $data = ['AccessControllerEvent' => $parsed];
                writeLog("✅ POST dan JSON topildi (ACE ichi)");
            }
        }
    }
    
    if (!$data) {
        foreach ($_FILES as $field => $file) {
            if (empty($file['tmp_name'])) continue;
            $content = file_get_contents($file['tmp_name']);
            if (empty($content)) continue;
            
            $try = json_decode($content, true);
            if (is_array($try)) {
                if (isset($try['AccessControllerEvent'])) {
                    $data = $try;
                    writeLog("✅ Multipart fayldan JSON topildi");
                    break;
                }
                if (isset($try['employeeNoString'])) {
                    $data = ['AccessControllerEvent' => $try];
                    writeLog("✅ Multipart fayldan JSON topildi (ACE ichi)");
                    break;
                }
            }
        }
    }
}

// ====== 2. JSON ======
if (!$data && !empty($raw_data)) {
    $try = json_decode($raw_data, true);
    if (is_array($try)) {
        if (isset($try['AccessControllerEvent'])) {
            $data = $try;
            writeLog("✅ Raw JSON topildi");
        } elseif (isset($try['employeeNoString'])) {
            $data = ['AccessControllerEvent' => $try];
            writeLog("✅ Raw JSON topildi (ACE ichi)");
        }
    }
}

// ====== 3. XML ======
if (!$data && !empty($raw_data)) {
    $try = simplexml_load_string($raw_data);
    if ($try) {
        $try = json_decode(json_encode($try), true);
        if (is_array($try) && isset($try['AccessControllerEvent'])) {
            $data = $try;
            writeLog("✅ Raw XML topildi");
        }
    }
}

if (!$data) {
    writeLog("⚠️ Ma'lumot parse qilinmadi, 200 OK");
    sendResponse('OK', 200);
}

$ace = $data['AccessControllerEvent'] ?? [];
if (!is_array($ace)) $ace = (array)$ace;

// ====== HEARTBEAT ======
$eventType = $ace['eventType'] ?? $data['eventType'] ?? '';
if (stripos($eventType, 'heartbeat') !== false) {
    writeLog("💓 Heartbeat - 200 OK");
    sendResponse('OK', 200);
}

// ====== XODIM ID ======
$emp_id = $ace['employeeNoString'] ?? '';
if (empty($emp_id)) {
    writeLog("⏭️ Xodim IDsiz event - 200 OK");
    sendResponse('OK', 200);
}

// ====== ATTENDANCE ======
$emp_name = $ace['name'] ?? 'Noma\'lum';
$dt_raw = $ace['dateTime'] ?? $data['dateTime'] ?? date('Y-m-d\TH:i:s');
$label = $ace['label'] ?? '';
$status = $ace['attendanceStatus'] ?? '';
$device_ip = $ace['ipAddress'] ?? $data['ipAddress'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');

$dt_clean = str_replace('T', ' ', explode('+', $dt_raw)[0]);
$auth_date = date('Y-m-d', strtotime($dt_clean));
$auth_time = date('H:i:s', strtotime($dt_clean));

$direction = 'OUT';
if (stripos($label, 'kirish') !== false || 
    stripos($status, 'in') !== false || 
    stripos($label, 'IN') !== false) {
    $direction = 'IN';
}

// ====== DAVOMAT HOLATI ======
$davomat_holati = $status;

writeLog("👤 Xodim: {$emp_name} ({$emp_id}) - {$direction} - {$dt_clean} - Holat: {$davomat_holati}");

// ====== DEVICE ======
$pdo = getDB();
if (!$pdo) {
    writeLog("❌ DB ulanish xatosi, 200 OK");
    sendResponse('OK', 200);
}

$device_info = getDeviceInfo($pdo, $device_ip);
$device_name = $device_info['device_name'];
$account_name = $device_info['account_name'];

writeLog("📟 Qurilma: [{$account_name}] {$device_name} (IP: {$device_ip})");

// ============================================================
// RAW_DATA - FAQAT LABEL
// ============================================================
$raw_data_save = $label;

// ============================================================
// BAZAGA YOZISH - FAQAT KERAKLI USTUNLAR
// ============================================================
try {
    $stmt = $pdo->prepare("INSERT INTO attendance_data 
        (employee_id, employee_name, device_name, auth_datetime, auth_date, auth_time, direction, davomat_holati, device_ip, raw_data)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->execute([
        $emp_id,                    // employee_id
        $emp_name,                  // employee_name
        $device_name,               // device_name
        $dt_clean,                  // auth_datetime
        $auth_date,                 // auth_date
        $auth_time,                 // auth_time
        $direction,                 // direction
        $davomat_holati,            // davomat_holati
        $device_ip,                 // device_ip
        $raw_data_save              // raw_data (faqat label)
    ]);
    
    writeLog("✅ Saqlandi: {$emp_name} ({$emp_id}) - [{$account_name}] {$device_name} - Label: {$label}");
    writeAttendanceLog($direction, $device_name, $emp_name, $dt_clean);
    sendResponse('OK', 200);
    
} catch (PDOException $e) {
    writeLog("❌ PDO xatosi: " . $e->getMessage() . " - 200 OK");
    sendResponse('OK', 200);
}
