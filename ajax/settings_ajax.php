<?php
// ajax/settings_ajax.php - FAQAT SID ASOSIDA (TO'LIQ)

error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once __DIR__ . '/../includes/functions/branch_access.php';
require_once __DIR__ . '/../includes/session.php';

$sid = $_GET['sid'] ?? $_POST['sid'] ?? '';
if (!is_string($sid) || !preg_match('/^[a-f0-9]{32}$/', $sid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'SID noto‘g‘ri']);
    exit;
}
startAppSession($sid);
header('Content-Type: application/json');
if (($_SESSION['logged_in'] ?? false) !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Sessiya yaroqsiz. Qayta kiring.']);
    exit;
}

require_once __DIR__ . '/../includes/config/database.php';
$branchSpotId = isBranchUser() ? currentBranchSpotId() : null;
if ($branchSpotId !== null) {
    try {
        assertBranchSessionActive($pdo);
    } catch (RuntimeException $e) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Filial ruxsati bekor qilingan. Qayta kiring.']);
        exit;
    }
}

// Account data is bound to the authenticated session, never resolved from a URL token.
$pdo_salary = $pdo;
$account_name = trim((string)($_SESSION['account_name'] ?? $_SESSION['account'] ?? ''));
$account_id = (string)($_SESSION['account_number'] ?? $_SESSION['account_id'] ?? '');
$access_token = (string)($_SESSION['access_token'] ?? '');

// Agar topilmasa, xatolik
if (empty($account_name)) {
    echo json_encode(['success' => false, 'message' => 'Akkaunt topilmadi. Iltimos, qayta kiring.']);
    exit;
}

// ============================================================
// 4. IVMS BAZASIGA ULANISH
// ============================================================
$ivms_config = [
    'host' => getenv('IVMS_HOST'),
    'db'   => getenv('IVMS_DB'),
    'user' => getenv('IVMS_USER'),
    'pass' => getenv('IVMS_PASS')
];
if (in_array(false, $ivms_config, true) || in_array('', $ivms_config, true)) {
    error_log('Required IVMS database environment variables are missing.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'IVMS ulanish sozlamalari mavjud emas.']);
    exit;
}

try {
    $pdo_ivms = new PDO(
        "mysql:host={$ivms_config['host']};dbname={$ivms_config['db']};charset=utf8mb4",
        $ivms_config['user'],
        $ivms_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log('IVMS database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'IVMS bazasiga ulanishda xatolik yuz berdi.']);
    exit;
}
$branchEmployeePdo = $branchSpotId !== null ? $pdo : $pdo_salary;

// ============================================================
// 5. HIKVISION DEVICES JADVALINI YARATISH
// ============================================================
try {
    $pdo_ivms->exec("
        CREATE TABLE IF NOT EXISTS `hikvision_devices` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `account_id` INT NOT NULL DEFAULT 0,
            `account_name` VARCHAR(100) NOT NULL DEFAULT '',
            `device_name` VARCHAR(255) DEFAULT NULL,
            `ip_address` VARCHAR(50) NOT NULL,
            `port` INT DEFAULT 8000,
            `username` VARCHAR(100) NOT NULL,
            `password` VARCHAR(255) NOT NULL,
            `is_active` TINYINT DEFAULT 0,
            `status` VARCHAR(50) DEFAULT 'offline',
            `last_sync` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_account_id` (`account_id`),
            INDEX `idx_account_name` (`account_name`),
            INDEX `idx_is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $deviceColumns = $pdo_ivms->query("SHOW COLUMNS FROM hikvision_devices")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('spot_id', $deviceColumns, true)) {
        $pdo_ivms->exec("ALTER TABLE hikvision_devices ADD COLUMN spot_id INT NULL");
    }
} catch (PDOException $e) {
    error_log("Hikvision device branch scope schema failed: " . $e->getMessage());
}

// ============================================================
// 5b. LOCAL AGENT NAVBATI (server mijozning local tarmog'iga
//     to'g'ridan-to'g'ri kira olmaydi, shuning uchun mijoz
//     tarmog'ida ishlaydigan agent.php shu navbatni o'qib,
//     qurilmaga yozib, natijasini qaytarib yuboradi)
// ============================================================
try {
    $pdo_ivms->exec("
        CREATE TABLE IF NOT EXISTS `agent_jobs` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `account_id` INT NOT NULL DEFAULT 0,
            `account_name` VARCHAR(100) NOT NULL DEFAULT '',
            `action` VARCHAR(50) NOT NULL,
            `payload` TEXT,
            `status` ENUM('pending','processing','done','error') DEFAULT 'pending',
            `result` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_account_status` (`account_name`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo_ivms->exec("
        CREATE TABLE IF NOT EXISTS `agent_heartbeats` (
            `account_name` VARCHAR(100) NOT NULL,
            `last_seen` DATETIME NOT NULL,
            PRIMARY KEY (`account_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    error_log("agent_jobs jadvali: " . $e->getMessage());
}

// ============================================================
// 6. SOZLAMALAR JADVALINI YARATISH
// ============================================================
try {
    $pdo_salary->exec("
        CREATE TABLE IF NOT EXISTS `sozlamalar` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `account_name` VARCHAR(100) NOT NULL,
            `kalit` VARCHAR(50) NOT NULL,
            `qiymat` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_setting` (`account_name`, `kalit`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    error_log("Sozlamalar jadvali: " . $e->getMessage());
}

// ============================================================
// 7. HIKVISION API KLASSI
// ============================================================
class HikvisionAPI {
    private $ip;
    private $port;
    private $username;
    private $password;

    public function __construct($ip, $port, $username, $password) {
        $this->ip = $ip;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    private function sendRequest($url, $method = 'GET', $data = null, $contentType = 'application/xml') {
        $ch = curl_init();
        $fullUrl = "http://{$this->ip}:{$this->port}{$url}";

        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        if ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: {$contentType}"]);
            }
        } elseif ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: {$contentType}"]);
            }
        } elseif ($method == 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        return ['code' => $httpCode, 'data' => $response, 'curl_errno' => $curlErrNo, 'curl_error' => $curlErr];
    }

    public function testConnection() {
        $result = $this->sendRequest('/ISAPI/System/deviceInfo');
        return $result['code'] == 200 || $result['code'] == 401;
    }

    public function getDeviceInfo() {
        $result = $this->sendRequest('/ISAPI/System/deviceInfo');
        if ($result['code'] == 200) {
            preg_match('/<deviceName>(.*?)<\/deviceName>/', $result['data'], $name);
            preg_match('/<deviceID>(.*?)<\/deviceID>/', $result['data'], $deviceId);
            return [
                'success' => true,
                'deviceName' => $name[1] ?? 'N/A',
                'deviceId' => $deviceId[1] ?? 'N/A'
            ];
        }
        return ['success' => false, 'message' => 'Connection failed'];
    }

    public function getNetworkSettings() {
        $result = $this->sendRequest('/ISAPI/System/Network');
        if ($result['code'] == 200) {
            preg_match('/<ipAddress>(.*?)<\/ipAddress>/', $result['data'], $ip);
            preg_match('/<subnetMask>(.*?)<\/subnetMask>/', $result['data'], $mask);
            preg_match('/<gateway>(.*?)<\/gateway>/', $result['data'], $gateway);
            return [
                'success' => true,
                'ipAddress' => $ip[1] ?? 'N/A',
                'subnetMask' => $mask[1] ?? 'N/A',
                'gateway' => $gateway[1] ?? 'N/A'
            ];
        }
        return ['success' => false];
    }

    public function setNetworkSettings($ip, $mask, $gateway) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
        <Network>
            <ipVersion>v4</ipVersion>
            <IPAddress>
                <ipAddress>' . $ip . '</ipAddress>
                <subnetMask>' . $mask . '</subnetMask>
                <defaultGateway>' . $gateway . '</defaultGateway>
            </IPAddress>
        </Network>';
        $result = $this->sendRequest('/ISAPI/System/Network', 'PUT', $xml);
        return $result['code'] == 200;
    }

    public function getHttpListeningSettings() {
        $result = $this->sendRequest('/ISAPI/Event/Notification/httpHosts');
        if ($result['code'] == 200) {
            preg_match_all('/<id>(\d+)<\/id>/', $result['data'], $ids);
            preg_match_all('/<ipAddress>(.*?)<\/ipAddress>/', $result['data'], $ips);
            preg_match_all('/<portNo>(.*?)<\/portNo>/', $result['data'], $ports);

            $listeners = [];
            for ($i = 0; $i < count($ips[1]); $i++) {
                $ipAddr = trim($ips[1][$i]);
                if ($ipAddr != '0.0.0.0') {
                    $listeners[] = [
                        'id' => $ids[1][$i] ?? '',
                        'ipAddress' => $ipAddr,
                        'portNo' => $ports[1][$i] ?? 80
                    ];
                }
            }
            return ['success' => true, 'listeners' => $listeners];
        }
        return ['success' => false, 'listeners' => []];
    }

    public function updateHttpListeningIP($id, $newIp, $portNo = 80) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
        <HttpHostNotification version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">
            <id>' . $id . '</id>
            <ipAddress>' . $newIp . '</ipAddress>
            <portNo>' . $portNo . '</portNo>
            <url>/index.php</url>
            <protocolType>HTTP</protocolType>
            <parameterFormatType>XML</parameterFormatType>
            <addressingFormatType>ipaddress</addressingFormatType>
            <httpAuthenticationMethod>none</httpAuthenticationMethod>
        </HttpHostNotification>';
        $result = $this->sendRequest('/ISAPI/Event/Notification/httpHosts/' . $id, 'PUT', $xml);

        error_log("updateHttpListeningIP -> HTTP {$result['code']}, curl_errno={$result['curl_errno']}, curl_error={$result['curl_error']}, body=" . substr((string)$result['data'], 0, 500));

        return [
            'success' => $result['code'] == 200,
            'http_code' => $result['code'],
            'raw_response' => $result['data'],
            'curl_errno' => $result['curl_errno'],
            'curl_error' => $result['curl_error']
        ];
    }

    public function addEmployee($employeeNo, $name, $cardNo = '') {
        $data = [
            "UserInfo" => [
                "employeeNo" => (string)$employeeNo,
                "name" => $name,
                "userType" => "normal",
                "Valid" => [
                    "enable" => true,
                    "beginTime" => date("Y-m-d") . "T00:00:00",
                    "endTime" => "2036-04-29T23:59:59"
                ],
                "doorRight" => "1"
            ]
        ];

        if (!empty($cardNo)) {
            $data["UserInfo"]["cardNo"] = (string)$cardNo;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $result = $this->sendRequest('/ISAPI/AccessControl/UserInfo/Record?format=json', 'POST', $json, 'application/json');
        return $result['code'] == 200 || $result['code'] == 201;
    }

    public function rebootDevice() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Device><reboot>reboot</reboot></Device>';
        $result = $this->sendRequest('/ISAPI/System/reboot', 'PUT', $xml);
        return $result['code'] == 200;
    }
}

// ============================================================
// 8. YORDAMCHI FUNKSIYALAR
// ============================================================
function getActiveDevice($pdo, $account_id, $account_name) {
    global $branchSpotId;

    if ($account_id > 0) {
        $sql = "SELECT * FROM hikvision_devices WHERE account_id = ? AND is_active = 1";
        $params = [$account_id];
    } else {
        $sql = "SELECT * FROM hikvision_devices WHERE account_name = ? AND is_active = 1";
        $params = [$account_name];
    }
    if ($branchSpotId !== null) {
        $sql .= " AND spot_id = ?";
        $params[] = $branchSpotId;
    }
    $stmt = $pdo->prepare($sql . " LIMIT 1");
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAllDevices($pdo, $account_id, $account_name) {
    global $branchSpotId;

    if ($account_id > 0) {
        $sql = "SELECT * FROM hikvision_devices WHERE account_id = ?";
        $params = [$account_id];
    } else {
        $sql = "SELECT * FROM hikvision_devices WHERE account_name = ?";
        $params = [$account_name];
    }
    if ($branchSpotId !== null) {
        $sql .= " AND spot_id = ?";
        $params[] = $branchSpotId;
    }
    $stmt = $pdo->prepare($sql . " ORDER BY is_active DESC, created_at DESC");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function scanNetwork($subnetPrefix = null, $ports = [8000, 80]) {
    if (empty($subnetPrefix)) {
        $localIp = getServerLocalIp();
        $parts = explode('.', $localIp);
        if (count($parts) == 4) {
            $subnetPrefix = $parts[0] . '.' . $parts[1] . '.' . $parts[2];
        } else {
            $subnetPrefix = '192.168.1';
        }
    }

    $targets = [];
    for ($i = 1; $i <= 254; $i++) {
        $ip = $subnetPrefix . '.' . $i;
        foreach ($ports as $port) {
            $targets[] = ['ip' => $ip, 'port' => $port];
        }
    }

    $found = [];
    $batchSize = 30;
    $batches = array_chunk($targets, $batchSize);

    foreach ($batches as $batch) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($batch as $idx => $target) {
            $url = "http://{$target['ip']}:{$target['port']}/ISAPI/System/deviceInfo";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = ['handle' => $ch, 'ip' => $target['ip'], 'port' => $target['port']];
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.1);
        } while ($running > 0);

        foreach ($handles as $idx => $h) {
            $httpCode = curl_getinfo($h['handle'], CURLINFO_HTTP_CODE);
            $body = curl_multi_getcontent($h['handle']);

            if (in_array($httpCode, [200, 401, 403])) {
                $deviceName = null;
                if ($httpCode == 200 && $body) {
                    if (preg_match('/<deviceName>(.*?)<\/deviceName>/', $body, $m)) {
                        $deviceName = $m[1];
                    }
                }

                $key = $h['ip'];
                if (!isset($found[$key])) {
                    $found[$key] = [
                        'ip' => $h['ip'],
                        'port' => $h['port'],
                        'http_code' => $httpCode,
                        'device_name' => $deviceName,
                        'auth_required' => ($httpCode == 401)
                    ];
                }
            }

            curl_multi_remove_handle($mh, $h['handle']);
            curl_close($h['handle']);
        }

        curl_multi_close($mh);
    }

    return array_values($found);
}

function getServerLocalIp() {
    if (function_exists('socket_create')) {
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock) {
            @socket_connect($sock, '8.8.8.8', 53);
            @socket_getsockname($sock, $localIp);
            @socket_close($sock);
            if (!empty($localIp)) {
                return $localIp;
            }
        }
    }

    if (!empty($_SERVER['SERVER_ADDR'])) {
        return $_SERVER['SERVER_ADDR'];
    }

    if (function_exists('shell_exec')) {
        $out = @shell_exec('hostname -I 2>/dev/null');
        if ($out) {
            $ips = explode(' ', trim($out));
            if (!empty($ips[0]) && filter_var($ips[0], FILTER_VALIDATE_IP)) {
                return $ips[0];
            }
        }
    }

    $hostIp = @gethostbyname(gethostname());
    if ($hostIp && $hostIp !== gethostname() && filter_var($hostIp, FILTER_VALIDATE_IP)) {
        return $hostIp;
    }

    return '192.168.1.1';
}

// ============================================================
// 8b. LOCAL AGENT ORQALI ISH BAJARISH
// Qurilma bilan bevosita gaplashish o'rniga, ish "navbat"ga
// qo'yiladi. Mijoz tarmog'idagi agent.php shuni o'qib bajaradi
// va natijani shu yerga qaytarib yozadi — biz esa kutib turamiz.
// ============================================================
function runViaAgent($pdo_ivms, $account_id, $account_name, $jobAction, $payload, $timeoutSeconds = 20) {
    $stmt = $pdo_ivms->prepare("INSERT INTO agent_jobs (account_id, account_name, action, payload, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt->execute([$account_id, $account_name, $jobAction, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    $jobId = $pdo_ivms->lastInsertId();

    $elapsedMs = 0;
    $stepMs = 500;
    while ($elapsedMs < $timeoutSeconds * 1000) {
        usleep($stepMs * 1000);
        $elapsedMs += $stepMs;

        $check = $pdo_ivms->prepare("SELECT status, result FROM agent_jobs WHERE id = ?");
        $check->execute([$jobId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        if ($row && in_array($row['status'], ['done', 'error'])) {
            $decoded = json_decode($row['result'], true);
            return is_array($decoded) ? $decoded : ['success' => false, 'message' => 'Agent javobini o\'qib bo\'lmadi'];
        }
    }

    return [
        'success' => false,
        'message' => 'Local Agent javob bermadi (offline yoki band). Mijoz kompyuteridagi agent.php ishga tushirilganini tekshiring.'
    ];
}

function isAgentOnline($pdo_ivms, $account_name, $maxAgeSeconds = 30) {
    try {
        $stmt = $pdo_ivms->prepare("SELECT last_seen FROM agent_heartbeats WHERE account_name = ?");
        $stmt->execute([$account_name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        return (time() - strtotime($row['last_seen'])) <= $maxAgeSeconds;
    } catch (Exception $e) {
        return false;
    }
}

// ============================================================
// 9. AJAX HANDLER
// ============================================================
$action = $_POST['action'] ?? '';

$sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
$postedCsrf = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Xavfsizlik tokeni yaroqsiz. Sahifani yangilang.']);
    exit;
}
if (empty($action)) {
    echo json_encode(['success' => false, 'message' => 'Action topilmadi!']);
    exit;
}
if ($branchSpotId !== null && !in_array($action, [
    'scan_network',
    'save_device',
    'get_devices',
    'delete_device',
    'toggle_device',
    'update_credentials',
    'get_device_info',
    'get_network_info',
    'update_network',
    'get_listeners',
    'update_listening',
    'sync_employees',
    'export_employees',
    'get_employees_count',
], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ushbu amal faqat bosh administrator uchun.']);
    exit;
}

$response = ['success' => false, 'message' => 'Noma\'lum amal'];

try {
    // ========== SCAN NETWORK ==========
    // ESLATMA: server mijozning local tarmog'ida emas, shuning uchun
    // skanerlashni Local Agent (mijoz tarmog'idagi agent.php) bajaradi.
    if ($action == 'scan_network') {
        set_time_limit(90);
        ignore_user_abort(true);

        if (!isAgentOnline($pdo_ivms, $account_name)) {
            $response = [
                'success' => false,
                'message' => 'Local Agent ulanmagan. Mijoz kompyuterida agent.php ishga tushirilganini tekshiring.'
            ];
        } else {
            $result = runViaAgent($pdo_ivms, $account_id, $account_name, 'scan_network', [], 60);
            $response = [
                'success' => $result['success'] ?? false,
                'devices' => $result['devices'] ?? [],
                'count' => count($result['devices'] ?? []),
                'message' => $result['message'] ?? null
            ];
        }
    }

    // ========== SAVE DEVICE ==========
    elseif ($action == 'save_device') {
        $ip = trim($_POST['ip_address']);
        $port = (int)($_POST['port'] ?? 8000);
        $device_name = trim($_POST['device_name'] ?? '');
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $is_active = isset($_POST['is_enabled']) ? 1 : 0;

        if (!isAgentOnline($pdo_ivms, $account_name)) {
            $response = ['success' => false, 'message' => 'Local Agent ulanmagan. Mijoz kompyuterida agent.php ishga tushirilganini tekshiring.'];
        } else {
        $testResult = runViaAgent($pdo_ivms, $account_id, $account_name, 'test_connection', [
            'ip' => $ip, 'port' => $port, 'username' => $username, 'password' => $password
        ]);
        $connectionTest = $testResult['success'] ?? false;

        if (!$connectionTest) {
            $response = ['success' => false, 'message' => 'Qurilmaga ulanish mumkin emas! IP, port yoki login/parolni tekshiring.'];
        } else {
            if ($is_active) {
                if ($account_id > 0) {
                    $deactivateSql = "UPDATE hikvision_devices SET is_active = 0 WHERE account_id = ?";
                    $deactivateParams = [$account_id];
                } else {
                    $deactivateSql = "UPDATE hikvision_devices SET is_active = 0 WHERE account_name = ?";
                    $deactivateParams = [$account_name];
                }
                if ($branchSpotId !== null) {
                    $deactivateSql .= " AND spot_id = ?";
                    $deactivateParams[] = $branchSpotId;
                }
                $pdo_ivms->prepare($deactivateSql)->execute($deactivateParams);
            }

            $fullDeviceName = $device_name ?: ($testResult['deviceName'] ?? $ip);

            if ($account_id > 0) {
                $deviceSql = "SELECT id FROM hikvision_devices WHERE account_id = ? AND ip_address = ?";
                $deviceParams = [$account_id, $ip];
            } else {
                $deviceSql = "SELECT id FROM hikvision_devices WHERE account_name = ? AND ip_address = ?";
                $deviceParams = [$account_name, $ip];
            }
            if ($branchSpotId !== null) {
                $deviceSql .= " AND spot_id = ?";
                $deviceParams[] = $branchSpotId;
            }
            $stmt = $pdo_ivms->prepare($deviceSql);
            $stmt->execute($deviceParams);
            $exists = $stmt->fetch();

            if ($exists) {
                if ($account_id > 0) {
                    $updateSql = "UPDATE hikvision_devices SET device_name = ?, port = ?, username = ?, password = ?, is_active = ?, status = 'online', updated_at = NOW() WHERE account_id = ? AND ip_address = ?";
                    $updateParams = [$fullDeviceName, $port, $username, $password, $is_active, $account_id, $ip];
                } else {
                    $updateSql = "UPDATE hikvision_devices SET device_name = ?, port = ?, username = ?, password = ?, is_active = ?, status = 'online', updated_at = NOW() WHERE account_name = ? AND ip_address = ?";
                    $updateParams = [$fullDeviceName, $port, $username, $password, $is_active, $account_name, $ip];
                }
                if ($branchSpotId !== null) {
                    $updateSql .= " AND spot_id = ?";
                    $updateParams[] = $branchSpotId;
                }
                $stmt = $pdo_ivms->prepare($updateSql);
                $stmt->execute($updateParams);
                $response = ['success' => true, 'message' => 'Qurilma muvaffaqiyatli yangilandi!'];
            } else {
                if ($account_id > 0) {
                    $stmt = $pdo_ivms->prepare("INSERT INTO hikvision_devices (account_id, account_name, spot_id, device_name, ip_address, port, username, password, is_active, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'online', NOW())");
                    $stmt->execute([$account_id, $account_name, $branchSpotId, $fullDeviceName, $ip, $port, $username, $password, $is_active]);
                } else {
                    $stmt = $pdo_ivms->prepare("INSERT INTO hikvision_devices (account_name, spot_id, device_name, ip_address, port, username, password, is_active, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'online', NOW())");
                    $stmt->execute([$account_name, $branchSpotId, $fullDeviceName, $ip, $port, $username, $password, $is_active]);
                }
                $response = ['success' => true, 'message' => 'Qurilma muvaffaqiyatli qo\'shildi!'];
            }
        }
        }
    }

    // ========== GET DEVICES ==========
    elseif ($action == 'get_devices') {
        $devices = getAllDevices($pdo_ivms, $account_id, $account_name);
        $response = ['success' => true, 'devices' => $devices];
    }

    // ========== DELETE DEVICE ==========
    elseif ($action == 'delete_device') {
        $id = (int)$_POST['device_id'];
        if ($account_id > 0) {
            $deleteSql = "DELETE FROM hikvision_devices WHERE id = ? AND account_id = ?";
            $deleteParams = [$id, $account_id];
        } else {
            $deleteSql = "DELETE FROM hikvision_devices WHERE id = ? AND account_name = ?";
            $deleteParams = [$id, $account_name];
        }
        if ($branchSpotId !== null) {
            $deleteSql .= " AND spot_id = ?";
            $deleteParams[] = $branchSpotId;
        }
        $pdo_ivms->prepare($deleteSql)->execute($deleteParams);
        $response = ['success' => true, 'message' => 'Qurilma o\'chirildi!'];
    }

    // ========== TOGGLE DEVICE ==========
    elseif ($action == 'toggle_device') {
        $id = (int)$_POST['device_id'];
        $is_active = (int)$_POST['is_enabled'];

        if ($is_active) {
            if ($account_id > 0) {
                $deactivateSql = "UPDATE hikvision_devices SET is_active = 0 WHERE account_id = ?";
                $deactivateParams = [$account_id];
            } else {
                $deactivateSql = "UPDATE hikvision_devices SET is_active = 0 WHERE account_name = ?";
                $deactivateParams = [$account_name];
            }
            if ($branchSpotId !== null) {
                $deactivateSql .= " AND spot_id = ?";
                $deactivateParams[] = $branchSpotId;
            }
            $pdo_ivms->prepare($deactivateSql)->execute($deactivateParams);
        }

        $status = $is_active ? 'online' : 'offline';
        if ($account_id > 0) {
            $updateSql = "UPDATE hikvision_devices SET is_active = ?, status = ?, updated_at = NOW() WHERE id = ? AND account_id = ?";
            $updateParams = [$is_active, $status, $id, $account_id];
        } else {
            $updateSql = "UPDATE hikvision_devices SET is_active = ?, status = ?, updated_at = NOW() WHERE id = ? AND account_name = ?";
            $updateParams = [$is_active, $status, $id, $account_name];
        }
        if ($branchSpotId !== null) {
            $updateSql .= " AND spot_id = ?";
            $updateParams[] = $branchSpotId;
        }
        $pdo_ivms->prepare($updateSql)->execute($updateParams);
        $response = ['success' => true, 'message' => $is_active ? 'Qurilma faollashtirildi!' : 'Qurilma o\'chirildi!'];
    }

    // ========== UPDATE CREDENTIALS ==========
    elseif ($action == 'update_credentials') {
        $id = (int)$_POST['device_id'];
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);

        if ($account_id > 0) {
            $deviceSql = "SELECT ip_address, port FROM hikvision_devices WHERE id = ? AND account_id = ?";
            $deviceParams = [$id, $account_id];
        } else {
            $deviceSql = "SELECT ip_address, port FROM hikvision_devices WHERE id = ? AND account_name = ?";
            $deviceParams = [$id, $account_name];
        }
        if ($branchSpotId !== null) {
            $deviceSql .= " AND spot_id = ?";
            $deviceParams[] = $branchSpotId;
        }
        $stmt = $pdo_ivms->prepare($deviceSql);
        $stmt->execute($deviceParams);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($device) {
            if (!isAgentOnline($pdo_ivms, $account_name)) {
                $response = ['success' => false, 'message' => 'Local Agent ulanmagan. Mijoz kompyuterida agent.php ishga tushirilganini tekshiring.'];
            } elseif ((runViaAgent($pdo_ivms, $account_id, $account_name, 'test_connection', [
                'ip' => $device['ip_address'], 'port' => $device['port'], 'username' => $username, 'password' => $password
            ])['success'] ?? false)) {
                if ($account_id > 0) {
                    $updateSql = "UPDATE hikvision_devices SET username = ?, password = ? WHERE id = ? AND account_id = ?";
                    $updateParams = [$username, $password, $id, $account_id];
                } else {
                    $updateSql = "UPDATE hikvision_devices SET username = ?, password = ? WHERE id = ? AND account_name = ?";
                    $updateParams = [$username, $password, $id, $account_name];
                }
                if ($branchSpotId !== null) {
                    $updateSql .= " AND spot_id = ?";
                    $updateParams[] = $branchSpotId;
                }
                $pdo_ivms->prepare($updateSql)->execute($updateParams);
                $response = ['success' => true, 'message' => 'Login/parol yangilandi va ulanish muvaffaqiyatli!'];
            } else {
                $response = ['success' => false, 'message' => 'Yangi login/parol bilan qurilmaga ulanish mumkin emas!'];
            }
        } else {
            $response = ['success' => false, 'message' => 'Qurilma topilmadi!'];
        }
    }

    // ========== GET DEVICE INFO ==========
    elseif ($action == 'get_device_info') {
        $active_device = getActiveDevice($pdo_ivms, $account_id, $account_name);
        if ($active_device && !isAgentOnline($pdo_ivms, $account_name)) {
            $response = ['success' => false, 'message' => 'Local Agent ulanmagan'];
        } elseif ($active_device) {
            $response = runViaAgent($pdo_ivms, $account_id, $account_name, 'get_device_info', [
                'ip' => $active_device['ip_address'], 'port' => $active_device['port'],
                'username' => $active_device['username'], 'password' => $active_device['password']
            ]);
            if ($response['success'] ?? false) {
                if ($account_id > 0) {
                    $updateSql = "UPDATE hikvision_devices SET device_name = ? WHERE id = ? AND account_id = ?";
                    $updateParams = [$response['deviceName'], $active_device['id'], $account_id];
                } else {
                    $updateSql = "UPDATE hikvision_devices SET device_name = ? WHERE id = ? AND account_name = ?";
                    $updateParams = [$response['deviceName'], $active_device['id'], $account_name];
                }
                if ($branchSpotId !== null) {
                    $updateSql .= " AND spot_id = ?";
                    $updateParams[] = $branchSpotId;
                }
                $pdo_ivms->prepare($updateSql)->execute($updateParams);
            }
        } else {
            $response = ['success' => false, 'message' => 'Aktiv qurilma topilmadi'];
        }
    }

    // ========== GET NETWORK INFO ==========
    elseif ($action == 'get_network_info') {
        $active_device = getActiveDevice($pdo_ivms, $account_id, $account_name);
        if (!$active_device) {
            $response = ['success' => false, 'message' => 'Aktiv qurilma topilmadi'];
        } elseif (!isAgentOnline($pdo_ivms, $account_name)) {
            $response = ['success' => false, 'message' => 'Local Agent ulanmagan'];
        } else {
            $response = runViaAgent($pdo_ivms, $account_id, $account_name, 'get_network_info', [
                'ip' => $active_device['ip_address'], 'port' => $active_device['port'],
                'username' => $active_device['username'], 'password' => $active_device['password']
            ]);
        }
    }

    // ========== UPDATE NETWORK ==========
    elseif ($action == 'update_network') {
        $active_device = getActiveDevice($pdo_ivms, $account_id, $account_name);
        if ($active_device && !isAgentOnline($pdo_ivms, $account_name)) {
            $response = ['success' => false, 'message' => 'Local Agent ulanmagan'];
        } elseif ($active_device) {
            $agentResult = runViaAgent($pdo_ivms, $account_id, $account_name, 'update_network', [
                'ip' => $active_device['ip_address'], 'port' => $active_device['port'],
                'username' => $active_device['username'], 'password' => $active_device['password'],
                'new_ip' => $_POST['ip_address'], 'subnet_mask' => $_POST['subnet_mask'], 'gateway' => $_POST['gateway']
            ]);
            $result = $agentResult['success'] ?? false;
            if ($result) {
                if ($account_id > 0) {
                    $updateSql = "UPDATE hikvision_devices SET ip_address = ? WHERE id = ? AND account_id = ?";
                    $updateParams = [$_POST['ip_address'], $active_device['id'], $account_id];
                } else {
                    $updateSql = "UPDATE hikvision_devices SET ip_address = ? WHERE id = ? AND account_name = ?";
                    $updateParams = [$_POST['ip_address'], $active_device['id'], $account_name];
                }
                if ($branchSpotId !== null) {
                    $updateSql .= " AND spot_id = ?";
                    $updateParams[] = $branchSpotId;
                }
                $pdo_ivms->prepare($updateSql)->execute($updateParams);
                $response = ['success' => true, 'message' => 'IP sozlamalari o\'rnatildi! Qurilma qayta yuklanadi.'];
            } else {
                $response = ['success' => false, 'message' => 'Xatolik! Qurilma IPni qabul qilmadi.'];
            }
        } else {
            $response = ['success' => false, 'message' => 'Aktiv qurilma topilmadi'];
        }
    }

    // ========== GET LISTENERS ==========
    elseif ($action == 'get_listeners') {
        $active_device = getActiveDevice($pdo_ivms, $account_id, $account_name);
        if (!$active_device) {
            $response = ['success' => false, 'listeners' => [], 'message' => 'Aktiv qurilma topilmadi'];
        } elseif (!isAgentOnline($pdo_ivms, $account_name)) {
            $response = ['success' => false, 'listeners' => [], 'message' => 'Local Agent ulanmagan'];
        } else {
            $response = runViaAgent($pdo_ivms, $account_id, $account_name, 'get_listeners', [
                'ip' => $active_device['ip_address'], 'port' => $active_device['port'],
                'username' => $active_device['username'], 'password' => $active_device['password']
            ]);
        }
    }

    // ========== UPDATE LISTENING (TUZATILGAN — DIAGNOSTIKA BILAN) ==========
    elseif ($action == 'update_listening') {
        $active_device = getActiveDevice($pdo_ivms, $account_id, $account_name);
        if ($active_device && !isAgentOnline($pdo_ivms, $account_name)) {
            $response = ['success' => false, 'message' => 'Local Agent ulanmagan'];
        } elseif ($active_device) {
            if (empty($_POST['id']) || empty($_POST['new_ip'])) {
                $response = ['success' => false, 'message' => 'ID yoki yangi IP kiritilmagan'];
            } else {
                $result = runViaAgent($pdo_ivms, $account_id, $account_name, 'update_listening', [
                    'ip' => $active_device['ip_address'], 'port' => $active_device['port'],
                    'username' => $active_device['username'], 'password' => $active_device['password'],
                    'id' => $_POST['id'], 'new_ip' => $_POST['new_ip'], 'port_no' => $_POST['port_no'] ?? 80
                ]);

                if ($result['success'] ?? false) {
                    $response = ['success' => true, 'message' => 'IP o\'zgartirildi!'];
                } elseif (!isset($result['http_code'])) {
                    $response = ['success' => false, 'message' => 'Xatolik: ' . ($result['message'] ?? 'Local Agentdan javob kelmadi')];
                } else {
                    $reason = 'HTTP ' . $result['http_code'];

                    if ($result['http_code'] == 0 && !empty($result['curl_error'])) {
                        $reason = 'Qurilmaga ulanib bo\'lmadi: ' . $result['curl_error'];
                    } elseif ($result['http_code'] == 401) {
                        $reason = 'Login yoki parol noto\'g\'ri (401)';
                    } elseif (!empty($result['raw_response'])) {
                        if (preg_match('/<statusString>(.*?)<\/statusString>/', $result['raw_response'], $m)) {
                            $reason .= ' - ' . $m[1];
                        } elseif (preg_match('/<subStatusCode>(.*?)<\/subStatusCode>/', $result['raw_response'], $m)) {
                            $reason .= ' - ' . $m[1];
                        }
                    }

                    $response = ['success' => false, 'message' => 'Xatolik: ' . $reason];
                }
            }
        } else {
            $response = ['success' => false, 'message' => 'Aktiv qurilma topilmadi'];
        }
    }

    // ========== GET SETTINGS ==========
    elseif ($action == 'get_settings') {
        try {
            $stmt = $pdo_salary->prepare("SELECT kalit, qiymat FROM sozlamalar WHERE account_name = ?");
            $stmt->execute([$account_name]);
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['kalit']] = $row['qiymat'];
            }
            $response = ['success' => true, 'settings' => $settings];
        } catch (PDOException $e) {
            $response = ['success' => true, 'settings' => []];
        }
    }

    // ========== SAVE SETTINGS ==========
    elseif ($action == 'save_settings') {
        try {
            $settingsToSave = ['kompaniya_nomi', 'til', 'standart_oylik', 'kunlik_soat', 'qoshimcha_koeff', 'yarim_kun_koeff', 'work_start_time', 'grace_minutes'];
            foreach ($settingsToSave as $key) {
                if (isset($_POST[$key])) {
                    $stmt = $pdo_salary->prepare("INSERT INTO sozlamalar (account_name, kalit, qiymat) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qiymat = VALUES(qiymat)");
                    $stmt->execute([$account_name, $key, $_POST[$key]]);
                }
            }
            $response = ['success' => true, 'message' => 'Umumiy sozlamalar saqlandi!'];
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Sozlamalarni saqlash xatosi: ' . $e->getMessage()];
        }
    }

    // ========== SAVE PENALTY SETTINGS ==========
    elseif ($action == 'save_penalty_settings') {
        try {
            $penaltySettings = [
                'shtraf_enabled' => isset($_POST['shtraf_enabled']) ? 1 : 0,
                'shtraf_level1_minutes' => (int)($_POST['level1_minutes'] ?? 0),
                'shtraf_level1_percent' => (float)($_POST['level1_percent'] ?? 0),
                'shtraf_level2_minutes' => (int)($_POST['level2_minutes'] ?? 0),
                'shtraf_level2_percent' => (float)($_POST['level2_percent'] ?? 0),
                'shtraf_level3_minutes' => (int)($_POST['level3_minutes'] ?? 0),
                'shtraf_level3_percent' => (float)($_POST['level3_percent'] ?? 0),
                'shtraf_max_percent' => (float)($_POST['max_percent'] ?? 50)
            ];
            foreach ($penaltySettings as $key => $value) {
                $stmt = $pdo_salary->prepare("INSERT INTO sozlamalar (account_name, kalit, qiymat) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qiymat = VALUES(qiymat)");
                $stmt->execute([$account_name, $key, $value]);
            }
            $response = ['success' => true, 'message' => 'Shtraf sozlamalari saqlandi!'];
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Shtraf sozlamalarini saqlash xatosi: ' . $e->getMessage()];
        }
    }

    // ========== SYNC EMPLOYEES ==========
    elseif ($action == 'sync_employees') {
        // Har bir qurilma o'z filialiga bog'langan bo'lsa, faqat shu filial xodimlari yuboriladi
        $active_device = null;
        $requestedDeviceId = filter_var($_POST['device_id'] ?? null, FILTER_VALIDATE_INT);
        if ($requestedDeviceId) {
            foreach (getAllDevices($pdo_ivms, $account_id, $account_name) as $candidateDevice) {
                if ((int)$candidateDevice['id'] === $requestedDeviceId) {
                    $active_device = $candidateDevice;
                    break;
                }
            }
        } else {
            $active_device = getActiveDevice($pdo_ivms, $account_id, $account_name);
        }
        $syncSpotId = $branchSpotId ?? ((int)($active_device['spot_id'] ?? 0) > 0 ? (int)$active_device['spot_id'] : null);
        if (!$active_device) {
            $response = ['success' => false, 'message' => 'Aktiv qurilma topilmadi'];
        } elseif (!isAgentOnline($pdo_ivms, $account_name)) {
            $response = ['success' => false, 'message' => 'Local Agent ulanmagan. Mijoz kompyuterida agent.php ishga tushirilganini tekshiring.'];
        } else {
            try {
                $employeeSql = "SELECT id, ismi FROM xodimlar WHERE holati = 'active' AND account_name = ?";
                $employeeParams = [$account_name];
                if ($syncSpotId !== null) {
                    $employeeSql .= " AND branch_spot_id = ?";
                    $employeeParams[] = $syncSpotId;
                }
                $stmt = $branchEmployeePdo->prepare($employeeSql);
                $stmt->execute($employeeParams);
                $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($employees)) {
                    $response = ['success' => false, 'message' => 'Xodimlar topilmadi'];
                } else {
                    // Sinxronlash bir necha o'nlab xodim bo'lishi mumkin bo'lgani uchun
                    // navbat kutish vaqtini xodimlar soniga qarab kengaytiramiz.
                    $timeout = min(120, 20 + count($employees) * 2);

                    $agentResult = runViaAgent($pdo_ivms, $account_id, $account_name, 'sync_employees', [
                        'ip' => $active_device['ip_address'], 'port' => $active_device['port'],
                        'username' => $active_device['username'], 'password' => $active_device['password'],
                        'employees' => $employees
                    ], $timeout);

                    if ($agentResult['success'] ?? false) {
                        if ($account_id > 0) {
                            $updateSql = "UPDATE hikvision_devices SET last_sync = NOW() WHERE id = ? AND account_id = ?";
                            $updateParams = [$active_device['id'], $account_id];
                        } else {
                            $updateSql = "UPDATE hikvision_devices SET last_sync = NOW() WHERE id = ? AND account_name = ?";
                            $updateParams = [$active_device['id'], $account_name];
                        }
                        if ($branchSpotId !== null) {
                            $updateSql .= " AND spot_id = ?";
                            $updateParams[] = $branchSpotId;
                        }
                        $pdo_ivms->prepare($updateSql)->execute($updateParams);
                        $response = ['success' => true, 'total' => $agentResult['total'] ?? count($employees), 'synced' => $agentResult['synced'] ?? 0];
                    } else {
                        $response = ['success' => false, 'message' => $agentResult['message'] ?? 'Sinxronlash xatosi'];
                    }
                }
            } catch (PDOException $e) {
                $response = ['success' => false, 'message' => 'Xodimlarni o\'qish xatosi: ' . $e->getMessage()];
            }
        }
    }

    // ========== REBOOT DEVICE ==========
    elseif ($action == 'reboot_device') {
        $active_device = getActiveDevice($pdo_ivms, $account_id, $account_name);
        if (!$active_device) {
            $response = ['success' => false, 'message' => 'Aktiv qurilma topilmadi'];
        } elseif (!isAgentOnline($pdo_ivms, $account_name)) {
            $response = ['success' => false, 'message' => 'Local Agent ulanmagan'];
        } else {
            $agentResult = runViaAgent($pdo_ivms, $account_id, $account_name, 'reboot_device', [
                'ip' => $active_device['ip_address'], 'port' => $active_device['port'],
                'username' => $active_device['username'], 'password' => $active_device['password']
            ]);
            $result = $agentResult['success'] ?? false;
            $response = ['success' => $result, 'message' => $result ? 'Qurilma qayta yuklanmoqda...' : 'Xatolik!'];
        }
    }

    // ========== EXPORT EMPLOYEES ==========
    elseif ($action == 'export_employees') {
        try {
            $employeeSql = "SELECT id, ismi, telefon, card_number FROM xodimlar WHERE holati = 'active' AND account_name = ?";
            $employeeParams = [$account_name];
            if ($branchSpotId !== null) {
                $employeeSql .= " AND branch_spot_id = ?";
                $employeeParams[] = $branchSpotId;
            }
            $stmt = $branchEmployeePdo->prepare($employeeSql);
            $stmt->execute($employeeParams);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $csv = "ID,Ismi,Telefon,Karta raqami\n";
            foreach ($employees as $emp) {
                $csv .= "\"{$emp['id']}\",\"{$emp['ismi']}\",\"{$emp['telefon']}\",\"{$emp['card_number']}\"\n";
            }
            $response = ['success' => true, 'csv' => $csv];
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Xodimlarni eksport qilish xatosi: ' . $e->getMessage()];
        }
    }

    // ========== GET EMPLOYEES COUNT ==========
    elseif ($action == 'get_employees_count') {
        try {
            $employeeSql = "SELECT COUNT(*) as count FROM xodimlar WHERE holati = 'active' AND account_name = ?";
            $employeeParams = [$account_name];
            if ($branchSpotId !== null) {
                $employeeSql .= " AND branch_spot_id = ?";
                $employeeParams[] = $branchSpotId;
            }
            $stmt = $branchEmployeePdo->prepare($employeeSql);
            $stmt->execute($employeeParams);
            $count = $stmt->fetch(PDO::FETCH_ASSOC);
            $response = ['success' => true, 'count' => $count['count']];
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Xodimlar jadvali topilmadi: ' . $e->getMessage()];
        }
    }

    // ========== CHANGE LANGUAGE ==========
    elseif ($action == 'change_language') {
        try {
            $lang = $_POST['lang'] ?? 'uz';
            $stmt = $pdo_salary->prepare("INSERT INTO sozlamalar (account_name, kalit, qiymat) VALUES (?, 'til', ?) ON DUPLICATE KEY UPDATE qiymat = VALUES(qiymat)");
            $stmt->execute([$account_name, $lang]);
            $response = ['success' => true, 'message' => 'Til o\'zgartirildi'];
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Tilni o\'zgartirish xatosi: ' . $e->getMessage()];
        }
    }

} catch (Exception $e) {
    $response = ['success' => false, 'message' => 'Xatolik: ' . $e->getMessage()];
}

echo json_encode($response);
exit;
