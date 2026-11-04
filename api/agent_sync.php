<?php
/**
 * api/agent_sync.php
 * Agent uchun REST API - FAQAT ID VA ISM
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/session.php';

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================
// KONFIGURATSIYA
// ============================================================

define('LOG_FILE', __DIR__ . '/../logs/agent_api.log');
define('COMMAND_DIR', __DIR__ . '/../tmp/commands');

if (!is_dir(COMMAND_DIR)) mkdir(COMMAND_DIR, 0755, true);
if (!is_dir(dirname(LOG_FILE))) mkdir(dirname(LOG_FILE), 0755, true);

// ============================================================
// LOGGER
// ============================================================

function logMessage($msg, $type = 'INFO') {
    $line = '[' . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// ============================================================
// MA'LUMOTLAR BAZASI ULASH (salary_system)
// ============================================================

$db_config = [
    'host' => getenv('DB_HOST'),
    'dbname' => getenv('DB_NAME'),
    'user' => getenv('DB_USER'),
    'pass' => getenv('DB_PASS'),
    'charset' => 'utf8mb4'
];

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset={$db_config['charset']}",
        $db_config['user'],
        $db_config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]
    );
} catch (PDOException $e) {
    logMessage("DB ulanish xatosi: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB ulanish xatosi']);
    exit;
}

// ============================================================
// IVMS BAZASI (hikvision_devices)
// ============================================================

$ivms_config = [
    'host' => getenv('IVMS_HOST'),
    'dbname' => getenv('IVMS_DB'),
    'user' => getenv('IVMS_USER'),
    'pass' => getenv('IVMS_PASS'),
    'charset' => 'utf8mb4'
];

$ivms_pdo = null;
try {
    $ivms_pdo = new PDO(
        "mysql:host={$ivms_config['host']};dbname={$ivms_config['dbname']};charset={$ivms_config['charset']}",
        $ivms_config['user'],
        $ivms_config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]
    );
} catch (PDOException $e) {
    logMessage("IVMS ulanish xatosi: " . $e->getMessage(), 'ERROR');
}

// ============================================================
// ACCOUNT FUNKSIYALARI
// ============================================================

function generateApiKey($length = 32) {
    return bin2hex(random_bytes($length));
}

function getOrCreateAccount($accountName) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id, name, api_key FROM accountlar WHERE name = ?");
    $stmt->execute([$accountName]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($account) {
        return [
            'success' => true,
            'account_id' => $account['id'],
            'account_name' => $account['name'],
            'api_key' => $account['api_key'],
            'exists' => true
        ];
    }
    
    $apiKey = generateApiKey(16);
    $stmt = $pdo->prepare("INSERT INTO accountlar (name, api_key) VALUES (?, ?)");
    $stmt->execute([$accountName, $apiKey]);
    
    return [
        'success' => true,
        'account_id' => $pdo->lastInsertId(),
        'account_name' => $accountName,
        'api_key' => $apiKey,
        'exists' => false
    ];
}

function getAccountByName($accountName) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id, name, api_key FROM accountlar WHERE name = ?");
    $stmt->execute([$accountName]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function validateApiKey($apiKey, $accountName) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM accountlar WHERE name = ? AND api_key = ?");
    $stmt->execute([$accountName, $apiKey]);
    return $stmt->fetch() !== false;
}

// ============================================================
// XODIMLAR FUNKSIYALARI - FAQAT ID VA ISM
// ============================================================

function getEmployees($accountName) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                ismi
            FROM xodimlar 
            WHERE account_name = ? 
              AND holati = 'active'
              AND (device_sync_status = 0 OR device_sync_status IS NULL)
            ORDER BY id
        ");
        $stmt->execute([$accountName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logMessage("Xodimlarni olish xatosi: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

function getAllEmployees($accountName) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                ismi
            FROM xodimlar 
            WHERE account_name = ? 
              AND holati = 'active'
            ORDER BY id
        ");
        $stmt->execute([$accountName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logMessage("Barcha xodimlarni olish xatosi: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

function markSynced($employeeId, $accountName, $deviceUserId = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE xodimlar 
            SET device_sync_status = 1, 
                device_sync_date = NOW(),
                device_user_id = COALESCE(?, device_user_id)
            WHERE id = ? AND account_name = ?
        ");
        return $stmt->execute([$deviceUserId, $employeeId, $accountName]);
    } catch (PDOException $e) {
        logMessage("Mark synced xatosi: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

function markUnsynced($employeeId, $accountName) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE xodimlar 
            SET device_sync_status = 0 
            WHERE id = ? AND account_name = ?
        ");
        return $stmt->execute([$employeeId, $accountName]);
    } catch (PDOException $e) {
        logMessage("Mark unsynced xatosi: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// ============================================================
// QURILMALAR FUNKSIYALARI
// ============================================================

function getDevices($accountName) {
    global $ivms_pdo;
    
    if (!$ivms_pdo) {
        return [];
    }
    
    try {
        $stmt = $ivms_pdo->prepare("
            SELECT id, ip_address, port, username, password, device_name, is_active
            FROM hikvision_devices 
            WHERE account_name = ? AND is_active = 1
        ");
        $stmt->execute([$accountName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logMessage("Qurilmalarni olish xatosi: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

// ============================================================
// COMMAND FUNKSIYALARI
// ============================================================

function getCommandFile($accountName) {
    return COMMAND_DIR . "/command_{$accountName}.flag";
}

function setCommand($accountName, $command) {
    file_put_contents(getCommandFile($accountName), $command);
    logMessage("Command set: $command for $accountName", 'INFO');
    return true;
}

function getAndClearCommand($accountName) {
    $file = getCommandFile($accountName);
    if (file_exists($file)) {
        $command = file_get_contents($file);
        unlink($file);
        logMessage("Command read: $command for $accountName", 'INFO');
        return $command;
    }
    return null;
}

// ============================================================
// SO'ROVNI QAYTA ISHLASH
// ============================================================

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST ?: $_GET;

$apiKey = $input['api_key'] ?? '';
$accountName = $input['account'] ?? '';
$action = $input['action'] ?? '';
$data = $input['data'] ?? [];
$registrationSecret = getenv('AGENT_REGISTRATION_SECRET');

logMessage("Request: account=$accountName, action=$action", 'DEBUG');

$response = ['success' => false, 'message' => 'Noma\'lum amal'];

try {
    switch ($action) {
        
        // ============================================================
        // PING
        // ============================================================
        case 'ping':
            $response = [
                'success' => true,
                'message' => 'API ishlayapti',
                'data' => [
                    'version' => '2.0',
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
            break;
        
        // ============================================================
        // GET_API_KEY
        // ============================================================
        case 'get_api_key':
            $providedRegistrationSecret = $_SERVER['HTTP_X_AGENT_REGISTRATION_SECRET'] ?? '';
            if (
                !is_string($registrationSecret)
                || strlen($registrationSecret) < 32
                || !is_string($providedRegistrationSecret)
                || !hash_equals($registrationSecret, $providedRegistrationSecret)
            ) {
                http_response_code(403);
                $response = ['success' => false, 'message' => 'Ro‘yxatdan o‘tkazish uchun ruxsat yo‘q'];
                break;
            }
            if (empty($accountName)) {
                $response = ['success' => false, 'message' => 'Account nomi kerak'];
                break;
            }
            
            $result = getOrCreateAccount($accountName);
            
            if ($result['success']) {
                $response = [
                    'success' => true,
                    'message' => $result['exists'] ? 'API key topildi' : 'Yangi account yaratildi',
                    'data' => [
                        'account_name' => $result['account_name'],
                        'api_key' => $result['api_key'],
                        'account_id' => $result['account_id'],
                        'is_new' => !$result['exists']
                    ]
                ];
                logMessage("API key: $accountName", 'INFO');
            }
            break;
        
        // ============================================================
        // LOGIN
        // ============================================================
        case 'login':
            if (empty($apiKey) || empty($accountName)) {
                $response = ['success' => false, 'message' => 'API kalit va account kerak'];
                break;
            }
            
            if (validateApiKey($apiKey, $accountName)) {
                $account = getAccountByName($accountName);
                $response = [
                    'success' => true,
                    'message' => 'Login muvaffaqiyatli',
                    'data' => [
                        'account_id' => $account['id'],
                        'account_name' => $account['name']
                    ]
                ];
                logMessage("Login: $accountName", 'INFO');
            } else {
                $response = ['success' => false, 'message' => 'Noto\'g\'ri API kalit'];
                logMessage("Login xato: $accountName", 'WARNING');
            }
            break;
        
        // ============================================================
        // SET_COMMAND
        // ============================================================
        case 'set_command':
            if (!validateApiKey($apiKey, $accountName)) {
                $response = ['success' => false, 'message' => 'Noto\'g\'ri API kalit'];
                break;
            }
            
            $command = $data['command'] ?? '';
            if (empty($command)) {
                $response = ['success' => false, 'message' => 'Buyruq kiritilmagan'];
                break;
            }
            
            setCommand($accountName, $command);
            $response = ['success' => true, 'message' => "Buyruq qabul qilindi: $command"];
            break;
        
        // ============================================================
        // GET_COMMAND
        // ============================================================
        case 'get_command':
            if (!validateApiKey($apiKey, $accountName)) {
                $response = ['success' => false, 'message' => 'Noto\'g\'ri API kalit'];
                break;
            }
            
            $command = getAndClearCommand($accountName);
            $response = ['success' => true, 'data' => ['command' => $command]];
            break;
        
        // ============================================================
        // GET_ALL_DATA - FAQAT ID VA ISM
        // ============================================================
        case 'get_all_data':
            if (!validateApiKey($apiKey, $accountName)) {
                $response = ['success' => false, 'message' => 'Noto\'g\'ri API kalit'];
                break;
            }
            
            $devices = getDevices($accountName);
            $employees = getEmployees($accountName);
            
            $response = [
                'success' => true,
                'data' => [
                    'account' => ['name' => $accountName],
                    'devices' => $devices,
                    'employees' => $employees,
                    'total_employees' => count($employees),
                    'total_devices' => count($devices)
                ]
            ];
            logMessage("Ma'lumotlar: " . count($employees) . " xodim, " . count($devices) . " qurilma", 'INFO');
            break;
        
        // ============================================================
        // GET_ALL_EMPLOYEES - FAQAT ID VA ISM
        // ============================================================
        case 'get_all_employees':
            if (!validateApiKey($apiKey, $accountName)) {
                $response = ['success' => false, 'message' => 'Noto\'g\'ri API kalit'];
                break;
            }
            
            $employees = getAllEmployees($accountName);
            
            $response = [
                'success' => true,
                'data' => [
                    'employees' => $employees,
                    'total' => count($employees)
                ]
            ];
            break;
        
        // ============================================================
        // GET_EMPLOYEES - FAQAT SINXRONLANMAGANLAR
        // ============================================================
        case 'get_employees':
            if (!validateApiKey($apiKey, $accountName)) {
                $response = ['success' => false, 'message' => 'Noto\'g\'ri API kalit'];
                break;
            }
            
            $employees = getEmployees($accountName);
            
            $response = [
                'success' => true,
                'data' => [
                    'employees' => $employees,
                    'total' => count($employees)
                ]
            ];
            break;
        
        // ============================================================
        // GET_DEVICES
        // ============================================================
        case 'get_devices':
            if (!validateApiKey($apiKey, $accountName)) {
                $response = ['success' => false, 'message' => 'Noto\'g\'ri API kalit'];
                break;
            }
            
            $devices = getDevices($accountName);
            
            $response = [
                'success' => true,
                'data' => [
                    'devices' => $devices,
                    'total' => count($devices)
                ]
            ];
            break;
        
        // ============================================================
        // SYNC_RESULT
        // ============================================================
        case 'sync_result':
            if (!validateApiKey($apiKey, $accountName)) {
                $response = ['success' => false, 'message' => 'Noto\'g\'ri API kalit'];
                break;
            }
            
            $results = $data['results'] ?? [];
            $synced = 0;
            $failed = 0;
            
            foreach ($results as $result) {
                $empId = (int)($result['employee_id'] ?? 0);
                $status = $result['status'] ?? 'failed';
                $deviceUserId = $result['device_user_id'] ?? null;
                $error = $result['error'] ?? '';
                
                if ($empId > 0 && $status === 'success') {
                    markSynced($empId, $accountName, $deviceUserId);
                    $synced++;
                } elseif ($empId > 0 && $status === 'failed') {
                    markUnsynced($empId, $accountName);
                    $failed++;
                    logMessage("Xodim ID $empId sinxronlanmadi: $error", 'WARNING');
                }
            }
            
            $response = [
                'success' => true,
                'message' => "Sinxronlash yakunlandi: $synced/$failed",
                'data' => ['synced' => $synced, 'failed' => $failed]
            ];
            logMessage("Sinxronlash yakunlandi: $synced/$failed", 'INFO');
            break;
        
        // ============================================================
        // MARK_SYNCED
        // ============================================================
        case 'mark_synced':
            if (!validateApiKey($apiKey, $accountName)) {
                $response = ['success' => false, 'message' => 'Noto\'g\'ri API kalit'];
                break;
            }
            
            $employeeId = (int)($data['employee_id'] ?? 0);
            $deviceUserId = $data['device_user_id'] ?? null;
            
            if ($employeeId <= 0) {
                $response = ['success' => false, 'message' => 'Xodim ID kerak'];
                break;
            }
            
            if (markSynced($employeeId, $accountName, $deviceUserId)) {
                $response = [
                    'success' => true,
                    'message' => "Xodim ID $employeeId sinxronlangan deb belgilandi"
                ];
            } else {
                $response = ['success' => false, 'message' => 'Xatolik yuz berdi'];
            }
            break;
        
        // ============================================================
        // MARK_UNSYNCED
        // ============================================================
        case 'mark_unsynced':
            if (!validateApiKey($apiKey, $accountName)) {
                $response = ['success' => false, 'message' => 'Noto\'g\'ri API kalit'];
                break;
            }
            
            $employeeId = (int)($data['employee_id'] ?? 0);
            
            if ($employeeId <= 0) {
                $response = ['success' => false, 'message' => 'Xodim ID kerak'];
                break;
            }
            
            if (markUnsynced($employeeId, $accountName)) {
                $response = [
                    'success' => true,
                    'message' => "Xodim ID $employeeId sinxronlanmagan deb belgilandi"
                ];
            } else {
                $response = ['success' => false, 'message' => 'Xatolik yuz berdi'];
            }
            break;
        
        // ============================================================
        // ACCOUNT_INFO
        // ============================================================
        case 'account_info':
            if (!validateApiKey($apiKey, $accountName)) {
                $response = ['success' => false, 'message' => 'Noto\'g\'ri API kalit'];
                break;
            }
            
            $account = getAccountByName($accountName);
            
            if ($account) {
                $response = [
                    'success' => true,
                    'data' => [
                        'account_id' => $account['id'],
                        'account_name' => $account['name'],
                        'api_key' => $account['api_key']
                    ]
                ];
            } else {
                $response = ['success' => false, 'message' => 'Account topilmadi'];
            }
            break;
        
        // ============================================================
        // SYNC_STATS
        // ============================================================
        case 'sync_stats':
            if (!validateApiKey($apiKey, $accountName)) {
                $response = ['success' => false, 'message' => 'Noto\'g\'ri API kalit'];
                break;
            }
            
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN device_sync_status = 1 THEN 1 ELSE 0 END) as synced,
                        SUM(CASE WHEN device_sync_status = 0 OR device_sync_status IS NULL THEN 1 ELSE 0 END) as unsynced
                    FROM xodimlar 
                    WHERE account_name = ? AND holati = 'active'
                ");
                $stmt->execute([$accountName]);
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $response = [
                    'success' => true,
                    'data' => [
                        'total' => (int)($stats['total'] ?? 0),
                        'synced' => (int)($stats['synced'] ?? 0),
                        'unsynced' => (int)($stats['unsynced'] ?? 0)
                    ]
                ];
            } catch (PDOException $e) {
                $response = ['success' => false, 'message' => $e->getMessage()];
            }
            break;
        
        default:
            $response = ['success' => false, 'message' => "Noma'lum amal: $action"];
    }
    
} catch (PDOException $e) {
    logMessage("DB xatosi: " . $e->getMessage(), 'ERROR');
    $response = ['success' => false, 'message' => 'DB xatosi: ' . $e->getMessage()];
} catch (Exception $e) {
    logMessage("Xatolik: " . $e->getMessage(), 'ERROR');
    $response = ['success' => false, 'message' => 'Xatolik: ' . $e->getMessage()];
}

echo json_encode($response);
