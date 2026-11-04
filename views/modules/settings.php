<?php
// modules/settings.php - XODIMLAR SONI BILAN

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 1. SID NI OLISH
// ============================================================
$sid = isset($_GET['sid']) ? $_GET['sid'] : (isset($_POST['sid']) ? $_POST['sid'] : '');
if(empty($sid) && isset($_SESSION['sid'])) {
    $sid = $_SESSION['sid'];
}
if(!empty($sid)) {
    $_SESSION['sid'] = $sid;
}

require_once __DIR__ . '/../../includes/language.php';

// ============================================
// MA'LUMOTLAR BAZASIGA ULANISH
// ============================================
global $pdo;

if (!isset($pdo)) {
    require_once __DIR__ . '/../includes/config/database.php';
}

// ============================================
// IVMS DATABASE ULANISH
// ============================================
$ivms_host = getenv('IVMS_HOST');
$ivms_dbname = getenv('IVMS_DB');
$ivms_user = getenv('IVMS_USER');
$ivms_pass = getenv('IVMS_PASS');

try {
    $ivms_pdo = new PDO("mysql:host=$ivms_host;dbname=$ivms_dbname;charset=utf8mb4", $ivms_user, $ivms_pass);
    $ivms_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ivms_pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $ivms_connected = true;
} catch(PDOException $e) {
    $ivms_pdo = $pdo;
    $ivms_connected = false;
    error_log("IVMS ulanish xatosi: " . $e->getMessage());
}

// ============================================
// AKKAUNT
// ============================================
$account_name = $_SESSION['account_name'] ?? $_SESSION['account'] ?? '';
$branch_spot_id = function_exists('isBranchUser') && isBranchUser()
    ? currentBranchSpotId()
    : null;

$deviceBranchSpots = [];
if (function_exists('posterApi')) {
    $spotsResponse = posterApi('spots.getSpots', [], $account_name, $_SESSION['access_token'] ?? '');
    foreach (($spotsResponse['response'] ?? []) as $spot) {
        $sid = (int)($spot['spot_id'] ?? 0);
        if ($sid > 0) {
            $deviceBranchSpots[$sid] = (string)($spot['name'] ?? $spot['spot_name'] ?? ('Filial ' . $sid));
        }
    }
}
if ($branch_spot_id !== null) {
    $deviceBranchSpots = [$branch_spot_id => $deviceBranchSpots[$branch_spot_id] ?? ('Filial ' . $branch_spot_id)];
}

if (empty($account_name)) {
    die('<p style="font-family:sans-serif;padding:20px;color:red;">❌ Account topilmadi. Iltimos, tizimga qayta kiring.</p>');
}

$account_id = 0;
try {
    $stmt = $pdo->prepare("SELECT id FROM accountlar WHERE name = ?");
    $stmt->execute([$account_name]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $account_id = (int)$result['id'];
    }
} catch (PDOException $e) {
    error_log('Hikvision branch scope schema update failed: ' . $e->getMessage());
}

if ($account_id == 0) {
    try {
        $stmt = $pdo->prepare("SELECT account_id FROM poster_tokens WHERE account_name = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$account_name]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && $result['account_id'] > 0) {
            $account_id = (int)$result['account_id'];
        }
    } catch (PDOException $e) {
        error_log('Hikvision branch scope schema update failed: ' . $e->getMessage());
    }
}

// ============================================
// JADVAL
// ============================================
if($ivms_pdo) {
    try {
        $ivms_pdo->exec("
            CREATE TABLE IF NOT EXISTS `hikvision_devices` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `account_name` VARCHAR(100) NOT NULL DEFAULT '',
                `ip_address` VARCHAR(50) NOT NULL,
                `port` INT DEFAULT 80,
                `username` VARCHAR(100) NOT NULL,
                `password` VARCHAR(255) NOT NULL,
                `device_name` VARCHAR(255) NULL,
                `is_active` TINYINT DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_ip_account` (`ip_address`, `account_name`),
                INDEX `idx_account_name` (`account_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $deviceColumns = $ivms_pdo->query("SHOW COLUMNS FROM `hikvision_devices`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('spot_id', $deviceColumns, true)) {
            $ivms_pdo->exec("ALTER TABLE `hikvision_devices` ADD COLUMN `spot_id` INT NULL");
        }
    } catch (PDOException $e) {
        error_log('Hikvision branch scope schema update failed: ' . $e->getMessage());
    }
}

// ============================================
// SOZLAMALAR
// ============================================
$settings = [];
try {
    $stmt = $pdo->prepare("SELECT kalit, qiymat FROM sozlamalar WHERE account_name = ?");
    $stmt->execute([$account_name]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['kalit']] = $row['qiymat'];
    }
} catch(Exception $e) {}

$lang = $settings['til'] ?? $_SESSION['user_lang'] ?? $_SESSION['lang'] ??  'uz';

// ============================================
// QURILMALARNI OLISH
// ============================================
$devices = [];
try {
    $deviceSql = "SELECT * FROM `hikvision_devices` WHERE account_name = ?";
    $deviceParams = [$account_name];
    if ($branch_spot_id !== null) {
        $deviceSql .= " AND spot_id = ?";
        $deviceParams[] = $branch_spot_id;
    }
    $deviceSql .= " ORDER BY is_active DESC, id DESC";
    $stmt = $ivms_pdo->prepare($deviceSql);
    $stmt->execute($deviceParams);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $devices = [];
}

$active_devices = [];
foreach ($devices as $d) {
    if ($d['is_active'] == 1) {
        $active_devices[] = $d;
    }
}
$active_device = !empty($active_devices) ? $active_devices[0] : null;
$total_devices = count($devices);
$running_devices = count($active_devices);

// ============================================
// XODIMLAR SONI (QOLDIRILDI)
// ============================================
$employeesCount = 0;
try {
    $employeeCountSql = "SELECT COUNT(*) as count FROM xodimlar WHERE holati = 'active' AND account_name = ?";
    $employeeCountParams = [$account_name];
    if ($branch_spot_id !== null) {
        $employeeCountSql .= " AND branch_spot_id = ?";
        $employeeCountParams[] = $branch_spot_id;
    }
    $stmt = $pdo->prepare($employeeCountSql);
    $stmt->execute($employeeCountParams);
    $employeesCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (PDOException $e) {
    $employeesCount = 0;
}

// ============================================
// CRUD
// ============================================

// CREATE / UPDATE
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$settingsCsrfToken = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_device'])) {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!is_string($postedCsrf) || !hash_equals($settingsCsrfToken, $postedCsrf)) {
        http_response_code(403);
        die('Xavfsizlik tokeni yaroqsiz. Sahifani yangilab qayta urinib ko‘ring.');
    }
    $ip_address = trim($_POST['ip_address'] ?? '');
    $port = (int)($_POST['port'] ?? 80);
    $device_name = $_POST['device_name'] ?? '';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if(empty($ip_address)) {
        $error = t('ip_required');
    } else {
        try {
            $deviceScope = $branch_spot_id === null ? '' : ' AND spot_id = ?';
            $saveSpotId = $branch_spot_id;
            if ($branch_spot_id === null) {
                $postedSpot = (int)($_POST['device_spot_id'] ?? 0);
                $saveSpotId = isset($deviceBranchSpots[$postedSpot]) ? $postedSpot : null;
            }
            $checkStmt = $ivms_pdo->prepare(
                "SELECT id FROM `hikvision_devices` WHERE ip_address = ? AND account_name = ?" . $deviceScope
            );
            $checkParams = [$ip_address, $account_name];
            if ($branch_spot_id !== null) {
                $checkParams[] = $branch_spot_id;
            }
            $checkStmt->execute($checkParams);
            $existing = $checkStmt->fetch();
            
            if($existing) {
                $spotUpdate = ', spot_id = ?';
                $updateSql = "
                    UPDATE `hikvision_devices` SET
                        port = ?, device_name = ?, username = ?, password = ?, is_active = ?,
                        account_name = ?" . $spotUpdate . "
                    WHERE id = ? AND account_name = ?" . $deviceScope;
                $updateParams = [$port, $device_name, $username, $password, $is_active, $account_name, $saveSpotId];
                $updateParams[] = $existing['id'];
                $updateParams[] = $account_name;
                if ($branch_spot_id !== null) {
                    $updateParams[] = $branch_spot_id;
                }
                $updateStmt = $ivms_pdo->prepare($updateSql);
                $updateStmt->execute($updateParams);
            } else {
                $insertStmt = $ivms_pdo->prepare("
                    INSERT INTO `hikvision_devices` 
                    (account_name, spot_id, ip_address, port, device_name, username, password, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insertStmt->execute([
                    $account_name, $saveSpotId, $ip_address, $port,
                    $device_name, $username, $password, $is_active
                ]);
            }
            
            header("Location: ?tab=settings&sid=" . urlencode($sid) . "&device_success=1");
            exit;
        } catch(Exception $e) {
            $error = t('error') . ": " . $e->getMessage();
        }
    }
}

// DELETE
if (isset($_GET['delete_device'])) {
    $device_id = (int)$_GET['delete_device'];
    try {
        $deviceScope = $branch_spot_id === null ? '' : ' AND spot_id = ?';
        $deleteStmt = $ivms_pdo->prepare(
            "DELETE FROM `hikvision_devices` WHERE id = ? AND account_name = ?" . $deviceScope
        );
        $deleteParams = [$device_id, $account_name];
        if ($branch_spot_id !== null) {
            $deleteParams[] = $branch_spot_id;
        }
        $deleteStmt->execute($deleteParams);
        header("Location: ?tab=settings&sid=" . urlencode($sid) . "&delete_success=1");
        exit;
    } catch(Exception $e) {
        $error = t('delete_error') . ": " . $e->getMessage();
    }
}

// TOGGLE
if (isset($_GET['toggle_device'])) {
    $device_id = (int)$_GET['toggle_device'];
    try {
        $deviceScope = $branch_spot_id === null ? '' : ' AND spot_id = ?';
        $statusStmt = $ivms_pdo->prepare(
            "SELECT is_active FROM `hikvision_devices` WHERE id = ? AND account_name = ?" . $deviceScope
        );
        $statusParams = [$device_id, $account_name];
        if ($branch_spot_id !== null) {
            $statusParams[] = $branch_spot_id;
        }
        $statusStmt->execute($statusParams);
        $current = $statusStmt->fetch();
        
        if($current) {
            $new_status = $current['is_active'] == 1 ? 0 : 1;
            $updateStmt = $ivms_pdo->prepare(
                "UPDATE `hikvision_devices` SET is_active = ? WHERE id = ? AND account_name = ?" . $deviceScope
            );
            $updateParams = [$new_status, $device_id, $account_name];
            if ($branch_spot_id !== null) {
                $updateParams[] = $branch_spot_id;
            }
            $updateStmt->execute($updateParams);
        }
        header("Location: ?tab=settings&sid=" . urlencode($sid) . "&toggle_success=1");
        exit;
    } catch(Exception $e) {
        $error = t('error') . ": " . $e->getMessage();
    }
}

$success = isset($_GET['device_success']) || isset($_GET['delete_success']) || isset($_GET['toggle_success']);
?>

<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('settings') ?> | Hikvision <?= t('devices') ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #ffffff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .container { max-width: 100%; margin: 0 auto; padding: 10px; }
        
        .toast { position: fixed; top: 20px; right: 20px; padding: 12px 16px; border-radius: 12px; background: white; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease; display: none; }
        .toast-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .toast-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .toast-info { background: #e0f2fe; color: #0369a1; border-left: 4px solid #3b82f6; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .card { margin-bottom: 24px; }
        .card-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; }
        .card-header h2 { font-size: 18px; font-weight: 600; color: #1e293b; margin: 0; }
        
        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: 14px; font-weight: 600; color: #475569; }
        input, select { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 12px; font-size: 14px; }
        input:focus, select:focus { border-color: #f97316; outline: none; box-shadow: 0 0 0 3px rgba(249,115,22,0.1); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        
        .btn { background: #10b981; border: none; padding: 10px 20px; border-radius: 8px; color: white; font-weight: 600; cursor: pointer; font-size: 14px; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-success { background: #10b981; }
        .btn-success:hover { background: #059669; }
        .btn-sm { padding: 6px 14px; font-size: 12px; }
        .btn-danger { background: #ef4444; }
        .btn-danger:hover { background: #dc2626; }
        .btn-warning { background: #f59e0b; }
        .btn-warning:hover { background: #d97706; }
        
        .checkbox-group { display: flex; align-items: center; gap: 10px; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; color: #64748b; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 30px; font-size: 11px; }
        .badge-active { background: #d1fae5; color: #065f46; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }
        
        .stats { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; background: #f4f8fd; border-radius: 10px; border: 1px solid #a5c7ee; }
        .stat-card { flex:1; padding: 15px 25px; text-align: center; }
        .stat-number { font-size: 28px; font-weight: bold; color: #333; }
        .stat-label { font-size: 12px; color: #64748b; margin-top: 5px; }
        
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .alert-success { background: #d1fae5; color: #065f46; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #ef4444; }
        .alert-info { background: #e0f2fe; color: #0369a1; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid #3b82f6; }
        .device-info-bar { background: #ffffff; padding: 15px 0; border-bottom: solid 1px #e2e8f0; margin-bottom: 20px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px; }
        .spinner { display: inline-block; width: 20px; height: 20px; border: 3px solid #e2e8f0; border-top: 3px solid #10b981; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } table { font-size: 12px; } th, td { padding: 8px; } }
    </style>
</head>
<body>

<div class="container">
    
    <div id="toast" class="toast"></div>
    
    <?php if($success): ?>
    <div class="alert-success"><?= t('success_save') ?></div>
    <?php endif; ?>
    
    <?php if(isset($error) && $error): ?>
    <div class="alert-error"><?= $error ?></div>
    <?php endif; ?>

    <!-- STATISTIKA -->
    <div class="stats">
        <div class="stat-card">
            <div class="stat-number"><?= $total_devices ?></div>
            <div class="stat-label"><?= t('total_devices') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $running_devices ?></div>
            <div class="stat-label"><?= t('active_devices') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $employeesCount ?></div>
            <div class="stat-label"><?= t('total_employees') ?></div>
        </div>
    </div>

    <!-- Aktiv qurilma -->
    <div class="device-info-bar">
        <?php if($active_device): ?>
        <div><strong><?= t('active_device') ?>:</strong> <span id="activeDeviceIp"><?= htmlspecialchars($active_device['ip_address']) ?></span></div>
        <div><span id="deviceName"><?= htmlspecialchars($active_device['device_name'] ?? t('unknown')) ?></span></div>
        <div><?= t('status') ?>: <?php if($active_device['is_active'] == 1): ?>
                                        <span class="badge badge-active"><?= t('active') ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive"><?= t('inactive') ?></span>
                                    <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="alert-info" style="margin:0; width:100%;"><?= t('no_device') ?></div>
        <?php endif; ?>
    </div>

    <!-- ==================== HIKVISION QURILMALARI ==================== -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-server"></i> <?= t('hikvision_devices') ?></h2>
        </div>
        <?php if (!empty($active_devices)): ?>
        <div style="margin-bottom:18px; display:flex; flex-wrap:wrap; gap:8px;">
            <?php foreach ($active_devices as $syncDevice): ?>
            <button type="button" class="btn btn-success sync-employees-button" onclick="syncEmployeesToDevice(<?= (int)$syncDevice['id'] ?>, this)">
                <i class="fas fa-upload"></i> <?= t('send_to_device') ?>
                — <?= htmlspecialchars(($syncDevice['device_name'] ?: $syncDevice['ip_address']) . ((int)($syncDevice['spot_id'] ?? 0) > 0 ? ' (Filial #' . (int)$syncDevice['spot_id'] . ')' : '')) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Qo'lda qo'shish -->
        <form method="POST" style="margin-bottom:30px; padding-bottom:20px; border-bottom:1px solid #e2e8f0;">
            <h3 style="font-size:14px; margin-bottom:12px; color:#475569;">
                <i class="fas fa-plus-circle"></i> <?= t('add_manual') ?>
            </h3>
            <input type="hidden" name="account_name" value="<?= htmlspecialchars($account_name) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($settingsCsrfToken) ?>">
            <input type="hidden" name="sid" value="<?= htmlspecialchars($sid) ?>">
            <div class="grid-2">
                <div class="form-group">
                    <label><?= t('ip_address') ?> *</label>
                    <input type="text" name="ip_address" id="device_ip" required placeholder="192.168.1.104">
                </div>
                <div class="form-group">
                    <label><?= t('port') ?></label>
                    <input type="number" name="port" id="device_port" value="80">
                </div>
                <div class="form-group">
                    <label><?= t('device_name') ?></label>
                    <input type="text" name="device_name" id="device_name" placeholder="<?= t('main_door') ?>">
                </div>
                <div class="form-group">
                    <label><?= t('username') ?></label>
                    <input type="text" name="username" id="device_username" placeholder="admin" required>
                </div>
                <div class="form-group">
                    <label><?= t('password') ?></label>
                    <input type="password" name="password" id="device_password" required>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" name="is_active" id="device_active" value="1" checked>
                    <label><?= t('make_active') ?></label>
                </div>
                <?php if ($branch_spot_id === null): ?>
                <div class="form-group">
                    <label>Filial</label>
                    <select name="device_spot_id">
                        <option value="0">Barcha filiallar</option>
                        <?php foreach ($deviceBranchSpots as $sid => $sname): ?>
                        <option value="<?= (int)$sid ?>"><?= htmlspecialchars($sname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <div class="form-group">
                    <label>Filial</label>
                    <input type="text" value="<?= htmlspecialchars($deviceBranchSpots[$branch_spot_id]) ?>" disabled>
                </div>
                <?php endif; ?>
            </div>
            <button type="submit" name="save_device" class="btn btn-success">
                <i class="fas fa-save"></i> <?= t('save') ?>
            </button>
        </form>
        
        <!-- Saqlangan qurilmalar -->
        <div>
            <h3 style="font-size:14px; color:#475569; margin-bottom:12px;">
                <i class="fas fa-list"></i> <?= t('saved_devices') ?> (<?= count($devices) ?>)
            </h3>
            <div style="overflow-x:auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th><?= t('device_name') ?></th>
                            <th>IP:port</th>
                            <th><?= t('username') ?></th>
                            <th><?= t('status') ?></th>
                            <th><?= t('actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($devices) > 0): ?>
                            <?php foreach($devices as $device): ?>
                            <tr>
                                <td><?= $device['id'] ?></td>
                                <td><strong><?= htmlspecialchars($device['device_name'] ?? '-') ?></strong></td>
                                <td><code><?= htmlspecialchars($device['ip_address']) ?>:<?= $device['port'] ?></code></td>
                                <td><code><?= htmlspecialchars($device['username']) ?></code></td>
                                <td>
                                    <?php if($device['is_active'] == 1): ?>
                                        <span class="badge badge-active"><?= t('active') ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive"><?= t('inactive') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a href="?tab=settings&sid=<?= urlencode($sid) ?>&toggle_device=<?= $device['id'] ?>" 
                                       class="btn btn-sm <?= $device['is_active'] ? 'btn-warning' : 'btn-success' ?>">
                                        <i class="fas <?= $device['is_active'] ? 'fa-pause' : 'fa-play' ?>"></i>
                                        <?= $device['is_active'] ? t('disable') : t('enable') ?>
                                    </a>
                                    <a href="?tab=settings&sid=<?= urlencode($sid) ?>&delete_device=<?= $device['id'] ?>" 
                                       class="btn btn-sm btn-danger" 
                                       onclick="return confirm('<?= t('delete_confirm_device') ?>')">
                                        <i class="fas fa-trash"></i> <?= t('delete') ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">
                                 <?= t('no_devices_found') ?>
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
const SID = <?= json_encode((string)$sid, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function showToast(msg, type) {
    let toast = document.getElementById('toast');
    if(!toast) return;
    toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i> ${msg}`;
    toast.className = `toast toast-${type === 'success' ? 'success' : type === 'error' ? 'error' : 'info'}`;
    toast.style.display = 'block';
    setTimeout(() => { if(toast) toast.style.display = 'none'; }, 3000);
}

function escapeHtml(str) {
    if(!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if(m == '&') return '&amp;';
        if(m == '<') return '&lt;';
        if(m == '>') return '&gt;';
        return m;
    });
}

document.addEventListener('DOMContentLoaded', function() {
    console.log('Settings sahifasi yuklandi');
});

function syncEmployeesToDevice(deviceId, button) {
    if (!button) return;

    button.disabled = true;
    fetch('/ajax/settings_ajax.php?sid=' + encodeURIComponent(SID), {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
        body: new URLSearchParams({action: 'sync_employees', device_id: deviceId, sid: SID, csrf_token: <?= json_encode($settingsCsrfToken) ?>})
    }).then(async function(response) {
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Qurilmaga yuborishda xatolik yuz berdi.');
        }
        showToast(result.synced + ' / ' + result.total + ' <?= htmlspecialchars(t('employees'), ENT_QUOTES, 'UTF-8') ?>', 'success');
    }).catch(function(error) {
        showToast(error.message, 'error');
    }).finally(function() {
        button.disabled = false;
    });
}
</script>

</body>
</html>
