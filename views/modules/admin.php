<?php
// modules/admin.php - ADMIN PANEL

// SESSIONni boshlash
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
// AKKAUNT MA'LUMOTLARINI OLISH
// ============================================
$account_name = $_SESSION['account_name'] ?? $_SESSION['account'] ?? '';

if (empty($account_name)) {
    die('<p style="font-family:sans-serif;padding:20px;color:red;">❌ Account topilmadi. Iltimos, tizimga qayta kiring.</p>');
}

// ============================================
// ACCOUNT_ID NI O'QIYMIZ
// ============================================
$account_id = 0;

try {
    $stmt = $pdo->prepare("SELECT account_id FROM poster_tokens WHERE account_name = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$account_name]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && $result['account_id'] > 0) {
        $account_id = (int)$result['account_id'];
    } else {
        $stmt = $pdo->prepare("SELECT id FROM accounts WHERE name = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$account_name]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $account_id = (int)$result['id'];
            $updateStmt = $pdo->prepare("UPDATE poster_tokens SET account_id = ? WHERE account_name = ?");
            $updateStmt->execute([$account_id, $account_name]);
        }
    }
} catch (PDOException $e) {
    error_log("Account ID olish xatosi: " . $e->getMessage());
}

// ============================================
// STATISTIKA MA'LUMOTLARI
// ============================================
$stats = [];

// Xodimlar soni
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM xodimlar WHERE account_name = ? AND holati = 'active'");
    $stmt->execute([$account_name]);
    $stats['employees'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (PDOException $e) {
    $stats['employees'] = 0;
}

// Xodimlar sozlamalari soni
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM oylik_sozlamalar WHERE xodim_id IN (SELECT id FROM xodimlar WHERE account_name = ?)");
    $stmt->execute([$account_name]);
    $stats['settings'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (PDOException $e) {
    $stats['settings'] = 0;
}

// Lavozimlar soni
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM lavozimlar WHERE account_name = ? AND status = 1");
    $stmt->execute([$account_name]);
    $stats['lavozimlar'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
} catch (PDOException $e) {
    $stats['lavozimlar'] = 0;
}

// Qurilmalar soni
$stats['devices'] = 0;
if (isset($ivms_pdo) && $ivms_pdo) {
    try {
        $stmt = $ivms_pdo->prepare("SELECT COUNT(*) as count FROM hikvision_devices WHERE account_name = ? AND is_active = 1");
        $stmt->execute([$account_name]);
        $stats['devices'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    } catch (PDOException $e) {
        $stats['devices'] = 0;
    }
}

// ============================================
// TIZIM MA'LUMOTLARI
// ============================================
$system_info = [
    'php_version' => phpversion(),
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Noma\'lum',
    'database' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . ' ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
    'account_name' => $account_name,
    'account_id' => $account_id,
    'sid' => $sid,
    'session_id' => substr((string)($_SESSION['session_context'] ?? ''), 0, 20),
    'current_time' => date('Y-m-d H:i:s')
];

// ============================================
// ACTIVE USERS (SESSION)
// ============================================
$active_users = [];
// Bu yerda session ma'lumotlarini olish mumkin
// Hozircha faqat joriy foydalanuvchi
$active_users[] = [
    'username' => $_SESSION['username'] ?? $account_name,
    'account' => $account_name,
    'last_activity' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Noma\'lum'
];

// ============================================
// SO'NGI XODIMLAR
// ============================================
$latest_employees = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, ismi, holati, yaratilgan 
        FROM xodimlar 
        WHERE account_name = ? 
        ORDER BY id DESC 
        LIMIT 10
    ");
    $stmt->execute([$account_name]);
    $latest_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $latest_employees = [];
}
?>

<!DOCTYPE html>
<html lang="<?= $lang ?? 'uz' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        
        .admin-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        /* Header */
        .admin-header { 
            background: white; 
            border-radius: 12px; 
            padding: 20px 24px; 
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .admin-header h1 { font-size: 24px; color: #1e293b; display: flex; align-items: center; gap: 10px; }
        .admin-header .badge { 
            background: #e2e8f0; 
            padding: 4px 12px; 
            border-radius: 20px; 
            font-size: 12px; 
            color: #475569; 
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card .icon { font-size: 28px; margin-bottom: 8px; }
        .stat-card .number { font-size: 28px; font-weight: 700; color: #1e293b; }
        .stat-card .label { font-size: 14px; color: #64748b; margin-top: 4px; }
        .stat-card.blue .icon { color: #3b82f6; }
        .stat-card.green .icon { color: #22c55e; }
        .stat-card.purple .icon { color: #8b5cf6; }
        .stat-card.orange .icon { color: #f97316; }
        .stat-card.red .icon { color: #ef4444; }
        
        /* Grid Layout */
        .admin-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        @media (max-width: 768px) {
            .admin-grid { grid-template-columns: 1fr; }
        }
        
        .admin-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .admin-card .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .admin-card .card-header h3 {
            font-size: 16px;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .admin-card .card-header h3 i { color: #64748b; }
        
        /* Table */
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        th { background: #f8fafc; color: #64748b; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover { background: #f8fafc; }
        
        .badge-status {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        .badge-status.active { background: #d1fae5; color: #065f46; }
        .badge-status.inactive { background: #fee2e2; color: #991b1b; }
        .badge-status.pending { background: #fef3c7; color: #92400e; }
        
        .system-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 24px;
        }
        .system-info-item {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .system-info-item .key { color: #64748b; font-size: 13px; }
        .system-info-item .value { color: #1e293b; font-size: 13px; font-weight: 500; }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-success { background: #22c55e; color: white; }
        .btn-success:hover { background: #16a34a; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        
        .empty-state {
            text-align: center;
            padding: 30px;
            color: #94a3b8;
        }
        .empty-state i { font-size: 32px; display: block; margin-bottom: 8px; }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
        }
        .quick-action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 16px;
            background: #f8fafc;
            border-radius: 10px;
            text-decoration: none;
            color: #1e293b;
            transition: all 0.2s;
            border: 1px solid #e2e8f0;
        }
        .quick-action-btn:hover {
            background: #f1f5f9;
            border-color: #3b82f6;
            transform: translateY(-2px);
        }
        .quick-action-btn i { font-size: 24px; margin-bottom: 6px; }
        .quick-action-btn span { font-size: 13px; font-weight: 500; }
        
        @media (max-width: 768px) {
            .admin-header { flex-direction: column; align-items: flex-start; }
            .system-info-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<div class="admin-container">
    
    <!-- ============================================================ -->
    <!-- HEADER -->
    <!-- ============================================================ -->
    <div class="admin-header">
        <div>
            <h1>
                <i class="fas fa-shield-alt" style="color:#3b82f6;"></i>
                Admin Panel
            </h1>
            <div style="font-size:13px; color:#64748b; margin-top:4px;">
                <span class="badge"><i class="fas fa-user"></i> <?= htmlspecialchars($account_name) ?></span>
                <span class="badge"><i class="fas fa-clock"></i> <?= date('d.m.Y H:i') ?></span>
            </div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="?tab=dashboard&sid=<?= urlencode($sid) ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="?tab=employees&sid=<?= urlencode($sid) ?>" class="btn btn-success btn-sm">
                <i class="fas fa-users"></i> Xodimlar
            </a>
            <a href="?tab=settings&sid=<?= urlencode($sid) ?>" class="btn btn-sm" style="background:#8b5cf6; color:white;">
                <i class="fas fa-sliders-h"></i> Sozlamalar
            </a>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- STATISTIKA -->
    <!-- ============================================================ -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="icon"><i class="fas fa-users"></i></div>
            <div class="number"><?= $stats['employees'] ?></div>
            <div class="label">Xodimlar</div>
        </div>
        <div class="stat-card green">
            <div class="icon"><i class="fas fa-cogs"></i></div>
            <div class="number"><?= $stats['settings'] ?></div>
            <div class="label">Sozlamalar</div>
        </div>
        <div class="stat-card purple">
            <div class="icon"><i class="fas fa-briefcase"></i></div>
            <div class="number"><?= $stats['lavozimlar'] ?></div>
            <div class="label">Lavozimlar</div>
        </div>
        <div class="stat-card orange">
            <div class="icon"><i class="fas fa-video"></i></div>
            <div class="number"><?= $stats['devices'] ?></div>
            <div class="label">Qurilmalar</div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- ADMIN GRID -->
    <!-- ============================================================ -->
    <div class="admin-grid">
        
        <!-- SO'NGI XODIMLAR -->
        <div class="admin-card">
            <div class="card-header">
                <h3><i class="fas fa-user-plus"></i> So'nggi xodimlar</h3>
                <a href="?tab=employees&sid=<?= urlencode($sid) ?>" class="btn btn-sm btn-primary">Barchasi</a>
            </div>
            <div class="table-responsive">
                <?php if(count($latest_employees) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Ismi</th>
                            <th>Holati</th>
                            <th>Yaratilgan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($latest_employees as $emp): ?>
                        <tr>
                            <td><?= $emp['id'] ?></td>
                            <td><strong><?= htmlspecialchars($emp['ismi']) ?></strong></td>
                            <td>
                                <span class="badge-status <?= $emp['holati'] == 'active' ? 'active' : 'inactive' ?>">
                                    <?= $emp['holati'] ?? 'noma\'lum' ?>
                                </span>
                            </td>
                            <td><?= $emp['yaratilgan'] ? date('d.m.Y H:i', strtotime($emp['yaratilgan'])) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <p>Xodimlar topilmadi</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TIZIM MA'LUMOTLARI -->
        <div class="admin-card">
            <div class="card-header">
                <h3><i class="fas fa-info-circle"></i> Tizim ma'lumotlari</h3>
            </div>
            <div class="system-info-grid">
                <div class="system-info-item">
                    <span class="key">PHP versiya</span>
                    <span class="value"><?= $system_info['php_version'] ?></span>
                </div>
                <div class="system-info-item">
                    <span class="key">Server</span>
                    <span class="value"><?= htmlspecialchars($system_info['server_software']) ?></span>
                </div>
                <div class="system-info-item">
                    <span class="key">Ma'lumotlar bazasi</span>
                    <span class="value"><?= htmlspecialchars($system_info['database']) ?></span>
                </div>
                <div class="system-info-item">
                    <span class="key">Account</span>
                    <span class="value"><?= htmlspecialchars($system_info['account_name']) ?></span>
                </div>
                <div class="system-info-item">
                    <span class="key">Account ID</span>
                    <span class="value"><?= $system_info['account_id'] ?></span>
                </div>
                <div class="system-info-item">
                    <span class="key">Session context</span>
                    <span class="value"><?= substr($system_info['session_id'], 0, 20) ?>...</span>
                </div>
                <div class="system-info-item" style="grid-column: 1 / -1;">
                    <span class="key">Joriy vaqt</span>
                    <span class="value"><?= $system_info['current_time'] ?></span>
                </div>
            </div>
        </div>

        <!-- ACTIVE USERS -->
        <div class="admin-card">
            <div class="card-header">
                <h3><i class="fas fa-user-check"></i> Faol foydalanuvchilar</h3>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Foydalanuvchi</th>
                            <th>Account</th>
                            <th>Oxirgi faollik</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($active_users as $user): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                            <td><?= htmlspecialchars($user['account']) ?></td>
                            <td><?= $user['last_activity'] ?></td>
                            <td><?= htmlspecialchars($user['ip']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- QUICK ACTIONS -->
        <div class="admin-card">
            <div class="card-header">
                <h3><i class="fas fa-bolt"></i> Tezkor amallar</h3>
            </div>
            <div class="quick-actions">
                <a href="?tab=employees&sid=<?= urlencode($sid) ?>" class="quick-action-btn">
                    <i class="fas fa-user-plus" style="color:#3b82f6;"></i>
                    <span>Xodim qo'shish</span>
                </a>
                <a href="?tab=settings&lavozim-tab&sid=<?= urlencode($sid) ?>" class="quick-action-btn">
                    <i class="fas fa-briefcase" style="color:#8b5cf6;"></i>
                    <span>Lavozim qo'shish</span>
                </a>
                <a href="?tab=settings&hikvision-tab&sid=<?= urlencode($sid) ?>" class="quick-action-btn">
                    <i class="fas fa-video" style="color:#f97316;"></i>
                    <span>Qurilma qo'shish</span>
                </a>
                <a href="?tab=settings&general-tab&sid=<?= urlencode($sid) ?>" class="quick-action-btn">
                    <i class="fas fa-sliders-h" style="color:#22c55e;"></i>
                    <span>Sozlamalar</span>
                </a>
            </div>
        </div>

    </div>

    <!-- ============================================================ -->
    <!-- FOOTER -->
    <!-- ============================================================ -->
    <div style="text-align:center; padding:20px; color:#94a3b8; font-size:13px; border-top:1px solid #e2e8f0; margin-top:10px;">
        <i class="fas fa-shield-alt" style="color:#3b82f6;"></i>
        Admin Panel v1.0 &bull; <?= date('Y') ?> &bull; 
        <a href="?tab=dashboard&sid=<?= urlencode($sid) ?>" style="color:#3b82f6; text-decoration:none;">Dashboard</a> &bull;
    </div>
</div>

<script>
// ============================================================
// ADMIN PANEL JAVASCRIPT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('Admin Panel yuklandi');
    console.log('Account:', '<?= htmlspecialchars($account_name) ?>');
});
</script>

</body>
</html>