<?php
// /var/www/html/poster_webhook.php
// Poster Webhook dan kelgan so'rovlarni qabul qiladi
// VAQT O'ZGARISHISIZ SAQLANADI (Poster vaqti)

// ====== VAQT ZONASINI O'CHIRAMIZ ======
// date_default_timezone_set('Asia/Tashkent'); // <-- BU QATOR O'CHIRILDI

// ====== MYSQL SOZLAMALARI ======
// ====== POSTER SOZLAMALARI ======
// Sirlar .env dan o'qiladi (kodda saqlanmaydi)
function envValue($key) {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return (string)$value;
    }
    $envFile = __DIR__ . '/.env';
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
            list($k, $v) = explode('=', $line, 2);
            if (trim($k) === $key) {
                return trim($v, " \t\"'");
            }
        }
    }
    return '';
}
define('POSTER_TOKEN', envValue('POSTER_WEBHOOK_TOKEN'));
define('CLIENT_SECRET', envValue('POSTER_WEBHOOK_SECRET'));

// ====== LOG SOZLAMALARI ======
define('LOG_FILE', '/var/log/hikvision/poster_webhook.log');

$logDir = dirname(LOG_FILE);
if (!is_dir($logDir)) mkdir($logDir, 0755, true);

function writeLog($msg) {
    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function getDB() {
    require_once __DIR__ . '/includes/config/database.php';
    return $GLOBALS['pdo'];
}

// ====== WEBHOOK VALIDATSIYASI ======
function validateWebhook($postData) {
    if (CLIENT_SECRET === '') {
        return false;
    }
    $verify_original = (string)($postData['verify'] ?? '');
    unset($postData['verify']);
    
    $verify = [
        $postData['account'] ?? '',
        $postData['object'] ?? '',
        $postData['object_id'] ?? '',
        $postData['action'] ?? '',
    ];
    
    if (isset($postData['data'])) {
        $verify[] = $postData['data'];
    }
    
    $verify[] = $postData['time'] ?? '';
    $verify[] = CLIENT_SECRET;
    
    $verify = md5(implode(';', $verify));
    
    return hash_equals($verify, $verify_original);
}

// ====== ASOSIY KOD ======
writeLog('📥 Webhook so\'rovi keldi');

$postJSON = file_get_contents('php://input');
$postData = json_decode($postJSON, true);

if (!$postData) {
    writeLog('⚠️ Ma\'lumot parse qilinmadi');
    http_response_code(200);
    echo 'OK';
    exit;
}

writeLog('📋 Webhook ma\'lumoti: ' . json_encode($postData));

// Validatsiya
if (!validateWebhook($postData)) {
    writeLog('❌ Validatsiya xatosi: Yaroqsiz verify');
    http_response_code(200);
    echo 'OK';
    exit;
}

writeLog('✅ Validatsiya muvaffaqiyatli');

// Faqat waiter o'zgarganda ishlaymiz
if ($postData['object'] === 'waiter' && $postData['action'] === 'changed') {
    $user_id = $postData['object_id'] ?? 0;
    writeLog("👤 Xodim ID: {$user_id} o'zgardi");
    
    if ($user_id) {
        // Poster API dan xodim ma'lumotlarini olish
        $url = "https://joinposter.com/api/access.getEmployees?token=" . POSTER_TOKEN;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            writeLog('❌ API xatosi: ' . $error);
            http_response_code(200);
            echo 'OK';
            exit;
        }
        
        $employees = json_decode($response, true);
        
        if ($employees && isset($employees['response'])) {
            foreach ($employees['response'] as $emp) {
                if ($emp['user_id'] == $user_id && isset($emp['last_in'])) {
                    // ====== POSTER VAQTI - O'ZGARISHISIZ ======
                    $last_in = $emp['last_in'];  // "2024-03-07 13:03:47" - Poster vaqti
                    $emp_name = $emp['name'] ?? "Xodim_{$user_id}";
                    
                    writeLog("📅 last_in (Poster vaqti): {$last_in} - Xodim: {$emp_name}");
                    
                    // attendance_data ga yozish
                    $pdo = getDB();
                    if ($pdo) {
                        try {
                            // ====== VAQTNI O'ZGARTIRMAYMIZ ======
                            // $last_in ni to'g'ridan-to'g'ri ishlatamiz
                            $auth_datetime = $last_in;  // O'ZGARISHISIZ
                            
                            // Sanani ajratib olish (Poster vaqtidan)
                            $auth_date = date('Y-m-d', strtotime($auth_datetime));
                            $auth_time = date('H:i:s', strtotime($auth_datetime));
                            
                            // Bugungi kunni Poster vaqti bilan tekshirish
                            $today = date('Y-m-d', strtotime($auth_datetime));
                            
                            $check = $pdo->prepare("
                                SELECT id FROM attendance_data 
                                WHERE DATE(auth_datetime) = ?
                                AND employee_id = ?
                                AND device_name = 'Poster Webhook'
                                LIMIT 1
                            ");
                            $check->execute([$today, $user_id]);
                            $exists = $check->fetch();
                            
                            if ($exists) {
                                // Yangilash - VAQT O'ZGARISHISIZ
                                $update = $pdo->prepare("
                                    UPDATE attendance_data 
                                    SET auth_datetime = ?,
                                        auth_date = ?,
                                        auth_time = ?,
                                        raw_data = ?
                                    WHERE id = ?
                                ");
                                $update->execute([
                                    $auth_datetime,  // Poster vaqti
                                    $auth_date,
                                    $auth_time,
                                    'webhook_updated: ' . json_encode($postData),
                                    $exists['id']
                                ]);
                                writeLog("✅ Yangilandi: {$emp_name} - {$auth_datetime} (Poster vaqti)");
                            } else {
                                // Yangi yozuv - VAQT O'ZGARISHISIZ
                                $stmt = $pdo->prepare("
                                    INSERT INTO attendance_data 
                                    (employee_id, employee_name, device_name, auth_datetime, auth_date, auth_time, direction, davomat_holati, device_ip, raw_data)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");
                                $stmt->execute([
                                    $user_id,
                                    $emp_name,
                                    'Poster Webhook',
                                    $auth_datetime,  // Poster vaqti
                                    $auth_date,
                                    $auth_time,
                                    'IN',
                                    'webhook',
                                    'poster_webhook',
                                    'webhook: ' . json_encode($postData)
                                ]);
                                writeLog("✅ Saqlandi: {$emp_name} - {$auth_datetime} (Poster vaqti)");
                            }
                        } catch (PDOException $e) {
                            writeLog('❌ Saqlash xatosi: ' . $e->getMessage());
                        }
                    }
                    break;
                }
            }
        } else {
            writeLog('❌ API dan ma\'lumot olishda xatolik');
        }
    }
}

http_response_code(200);
echo 'OK';
writeLog('✅ Webhook qabul qilindi');
exit;
?>
