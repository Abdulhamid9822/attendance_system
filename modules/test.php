<?php
// device_manager.php - Qurilmalar qo'shilganda darhol RUNNING

$db_config = [
    'host' => getenv('IVMS_HOST'),
    'db'   => getenv('IVMS_DB'),
    'user' => getenv('IVMS_USER'),
    'pass' => getenv('IVMS_PASS')
];

try {
    $pdo = new PDO(
        "mysql:host={$db_config['host']};dbname={$db_config['db']};charset=utf8mb4",
        $db_config['user'],
        $db_config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("❌ Database ulanish xatosi: " . $e->getMessage());
}

session_start();

$account_id = $_SESSION['account_id'] ?? 0;
$account_name = $_SESSION['account'] ?? $_SESSION['account_name'] ?? '';

if (empty($account_name) && isset($_COOKIE['account_name'])) {
    $account_name = $_COOKIE['account_name'];
}

if (empty($account_id) && !empty($account_name)) {
    $stmt = $pdo->prepare("SELECT id FROM accounts WHERE username = ? OR name = ? LIMIT 1");
    $stmt->execute([$account_name, $account_name]);
    $user = $stmt->fetch();
    if ($user) {
        $account_id = $user['id'];
        $_SESSION['account_id'] = $account_id;
    }
}

if (empty($account_id) && empty($account_name)) {
    die("❌ Iltimos, tizimga kiring!");
}

// ============================================
// JADVALNI YARATISH
// ============================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `hikvision_settings` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `account_id` INT NOT NULL DEFAULT 1,
            `ip_address` VARCHAR(50) NOT NULL,
            `port` INT DEFAULT 8000,
            `username` VARCHAR(100) NOT NULL,
            `password` VARCHAR(255) NOT NULL,
            `device_name` VARCHAR(255) NULL,
            `is_enabled` TINYINT DEFAULT 1,
            `status` VARCHAR(50) DEFAULT 'running',
            `last_heartbeat` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_account_id` (`account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    // Jadval mavjud
}

// ============================================
// ACTIONS
// ============================================
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    // QURILMA QO'SHISH (darhol running)
    if ($_POST['action'] == 'add_device') {
        try {
            $check = $pdo->prepare("SELECT id FROM hikvision_settings WHERE ip_address = ? AND account_id = ?");
            $check->execute([$_POST['ip_address'], $account_id]);
            
            if ($check->fetch()) {
                $error = "❌ Bu IP manzil allaqachon mavjud!";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO hikvision_settings 
                    (account_id, ip_address, port, username, password, device_name, is_enabled, status, last_heartbeat, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 1, 'running', NOW(), NOW())
                ");
                $stmt->execute([
                    $account_id,
                    $_POST['ip_address'],
                    $_POST['port'] ?? 8000,
                    $_POST['username'],
                    $_POST['password'],
                    $_POST['device_name']
                ]);
                $message = "✅ Qurilma muvaffaqiyatli qo'shildi! Holati: RUNNING";
            }
        } catch (Exception $e) {
            $error = "❌ Xatolik: " . $e->getMessage();
        }
    }
    
    // QURILMA O'CHIRISH
    if ($_POST['action'] == 'delete_device') {
        try {
            $stmt = $pdo->prepare("DELETE FROM hikvision_settings WHERE id = ? AND account_id = ?");
            $stmt->execute([$_POST['id'], $account_id]);
            $message = "✅ Qurilma o'chirildi!";
        } catch (Exception $e) {
            $error = "❌ Xatolik: " . $e->getMessage();
        }
    }
    
    // QURILMA HOLATINI O'ZGARTIRISH
    if ($_POST['action'] == 'toggle_device') {
        try {
            // Faollashtirilganda status running, aks holda stopped
            $newStatus = $_POST['is_enabled'] == 1 ? 'running' : 'stopped';
            $stmt = $pdo->prepare("
                UPDATE hikvision_settings 
                SET is_enabled = ?, status = ?, last_heartbeat = NOW() 
                WHERE id = ? AND account_id = ?
            ");
            $stmt->execute([$_POST['is_enabled'], $newStatus, $_POST['id'], $account_id]);
            $message = "✅ Qurilma holati o'zgartirildi! Status: " . strtoupper($newStatus);
        } catch (Exception $e) {
            $error = "❌ Xatolik: " . $e->getMessage();
        }
    }
    
    // BARCHA QURILMALARNI RUNNING QILISH
    if ($_POST['action'] == 'set_all_running') {
        try {
            $stmt = $pdo->prepare("
                UPDATE hikvision_settings 
                SET status = 'running', last_heartbeat = NOW(), is_enabled = 1 
                WHERE account_id = ?
            ");
            $stmt->execute([$account_id]);
            $message = "✅ Barcha qurilmalar RUNNING holatiga o'tkazildi!";
        } catch (Exception $e) {
            $error = "❌ Xatolik: " . $e->getMessage();
        }
    }
}

// ============================================
// QURILMALARNI OLISH
// ============================================
$stmt = $pdo->prepare("
    SELECT id, ip_address, port, username, device_name, is_enabled, status, last_heartbeat, created_at,
           TIMESTAMPDIFF(SECOND, last_heartbeat, NOW()) as heartbeat_ago
    FROM hikvision_settings 
    WHERE account_id = ?
    ORDER BY is_enabled DESC, id DESC
");
$stmt->execute([$account_id]);
$devices = $stmt->fetchAll();

// Statistika
$total = count($devices);
$running = 0;
foreach ($devices as $d) {
    if ($d['status'] == 'running' && $d['is_enabled'] == 1) $running++;
}
?>

<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hikvision Qurilmalar</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 12px; margin-bottom: 20px; }
        .header h1 { font-size: 24px; margin-bottom: 5px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card h2 { font-size: 18px; margin-bottom: 15px; color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: 600; margin-bottom: 5px; color: #555; font-size: 14px; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        input:focus, select:focus { outline: none; border-color: #667eea; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; }
        button { background: #667eea; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: background 0.3s; }
        button:hover { background: #5a67d8; }
        .btn-danger { background: #e53e3e; }
        .btn-danger:hover { background: #c53030; }
        .btn-success { background: #48bb78; }
        .btn-success:hover { background: #38a169; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f7fafc; font-weight: 600; color: #4a5568; }
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-active { background: #c6f6d5; color: #22543d; }
        .status-inactive { background: #fed7d7; color: #742a2a; }
        .status-running { background: #c6f6d5; color: #22543d; }
        .status-stopped { background: #e2e8f0; color: #4a5568; }
        .alert { padding: 12px; border-radius: 6px; margin-bottom: 15px; }
        .alert-success { background: #c6f6d5; color: #22543d; border-left: 4px solid #48bb78; }
        .alert-error { background: #fed7d7; color: #742a2a; border-left: 4px solid #e53e3e; }
        .info-text { font-size: 12px; color: #718096; margin-top: 5px; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .account-info { background: #e0e7ff; padding: 10px 15px; border-radius: 8px; margin-bottom: 15px; display: inline-block; }
        .running-badge { background: #48bb78; color: white; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; margin-left: 8px; }
        .stats { display: flex; gap: 20px; margin-top: 10px; flex-wrap: wrap; }
        .stat-card { background: #f7fafc; padding: 10px 20px; border-radius: 8px; text-align: center; }
        .stat-number { font-size: 24px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 12px; color: #718096; }
        @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; } table { font-size: 12px; } th, td { padding: 8px; } }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🎥 Hikvision Qurilmalar</h1>
        <p>Qurilma qo'shilganda darhol RUNNING holatida bo'ladi</p>
    </div>
    
    <div class="account-info">
        👤 Akkaunt: <strong><?= htmlspecialchars($account_name) ?></strong> (ID: <?= $account_id ?>)
    </div>
    
    <!-- STATISTIKA -->
    <div class="stats">
        <div class="stat-card">
            <div class="stat-number"><?= $total ?></div>
            <div class="stat-label">Jami Qurilmalar</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color: #48bb78;"><?= $running ?></div>
            <div class="stat-label">RUNNING Holatida</div>
        </div>
    </div>
    
    <?php if($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <?php if($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <!-- Yangi qurilma qo'shish (darhol running) -->
    <div class="card">
        <h2>➕ Yangi Qurilma Qo'shish <span class="running-badge">Avtomatik RUNNING</span></h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_device">
            <div class="form-row">
                <div class="form-group">
                    <label>IP Manzil *</label>
                    <input type="text" name="ip_address" required placeholder="192.168.1.104">
                </div>
                <div class="form-group">
                    <label>Port</label>
                    <input type="number" name="port" value="8000">
                </div>
                <div class="form-group">
                    <label>Qurilma Nomi *</label>
                    <input type="text" name="device_name" required placeholder="Asosiy_Eshik">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Foydalanuvchi *</label>
                    <input type="text" name="username" required placeholder="admin">
                </div>
                <div class="form-group">
                    <label>Parol *</label>
                    <input type="password" name="password" required placeholder="********">
                </div>
            </div>
            <button type="submit">➕ Qurilma Qo'shish (Running)</button>
        </form>
    </div>
    
    <!-- Tezkor amallar -->
    <div class="card">
        <h2>⚡ Tezkor Amallar</h2>
        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="set_all_running">
            <button type="submit" class="btn-success">🟢 Barcha Qurilmalarni Running Qilish</button>
        </form>
    </div>
    
    <!-- Qurilmalar ro'yxati -->
    <div class="card">
        <h2>📋 Qurilmalar Ro'yxati</h2>
        
        <?php if(count($devices) > 0): ?>
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nomi</th>
                            <th>IP Manzil</th>
                            <th>Holat</th>
                            <th>Oxirgi heartbeat</th>
                            <th>Amallar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($devices as $device): ?>
                        <tr>
                            <td><?= $device['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($device['device_name'] ?? '-') ?></strong>
                                <?php if($device['status'] == 'running' && $device['is_enabled']): ?>
                                    <span class="running-badge">🟢 RUNNING</span>
                                <?php endif; ?>
                             </a>
                            <td><code><?= $device['ip_address'] ?>:<?= $device['port'] ?></code><br><small><?= htmlspecialchars($device['username']) ?></small></td>
                            <td>
                                <?php if($device['is_enabled']): ?>
                                    <span class="status-badge status-active">✅ Faol</span>
                                    <?php if($device['status'] == 'running'): ?>
                                        <span class="status-badge status-running">🟢 RUNNING</span>
                                    <?php else: ?>
                                        <span class="status-badge status-stopped">⏸ STOPPED</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status-badge status-inactive">⛔ Nofaol</span>
                                <?php endif; ?>
                             </a>
                            <td>
                                <?php if($device['last_heartbeat']): ?>
                                    <?= date('H:i:s', strtotime($device['last_heartbeat'])) ?>
                                    <span class="info-text">(<?= $device['heartbeat_ago'] ?> sek)</span>
                                <?php else: ?>
                                    <span class="running-badge">Yangi - RUNNING</span>
                                <?php endif; ?>
                             </a>
                            <td class="actions">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle_device">
                                    <input type="hidden" name="id" value="<?= $device['id'] ?>">
                                    <input type="hidden" name="is_enabled" value="<?= $device['is_enabled'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn-sm <?= $device['is_enabled'] ? 'btn-danger' : 'btn-success' ?>">
                                        <?= $device['is_enabled'] ? '⛔ Nofaollashtirish' : '✅ Faollashtirish' ?>
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Rostdan ham o\'chirmoqchimisiz?')">
                                    <input type="hidden" name="action" value="delete_device">
                                    <input type="hidden" name="id" value="<?= $device['id'] ?>">
                                    <button type="submit" class="btn-sm btn-danger">🗑 O'chirish</button>
                                </form>
                             </a>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="text-align: center; padding: 40px; color: #718096;">
                📭 Hech qanday qurilma topilmadi. Yangi qurilma qo'shing!
            </p>
        <?php endif; ?>
    </div>
    
    <!-- Worker ishga tushirish -->
    <div class="card">
        <h2>⚙️ Worker Boshqaruvi</h2>
        <code style="background: #f7fafc; padding: 10px; display: block; border-radius: 6px; margin: 10px 0;">
            cd C:\Users\muham\Desktop\APIHik\api\attendance<br>
            php fixed_worker.php --id=ID --ip=IP --port=80 --user=admin --pass=parol --name=Qurilma_Nomi
        </code>
        <div class="info-text" style="margin-top: 10px; padding: 10px; background: #fef5e7; border-radius: 6px;">
            💡 <strong>Maslahat:</strong> Qurilma qo'shilganda avtomatik ravishda RUNNING holatida bo'ladi. Worker ishga tushirilganda heartbeat yozila boshlaydi.
        </div>
    </div>
</div>

<script>
// Avtomatik yangilash har 10 soniyada
setTimeout(function() {
    location.reload();
}, 10000);
</script>

</body>
</html>