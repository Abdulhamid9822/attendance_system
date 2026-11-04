<?php
/**
 * calculate_late_minutes.php - Kechikishni hisoblab bazaga yozadi
 * Barcha accountlar uchun ishlaydi
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once 'includes/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// 1. Sozlamalarni olish (barcha accountlar uchun)
// ============================================================
$work_start_time = '09:00:00';
$grace_minutes = 20;

try {
    // Account bo'yicha sozlamalarni olish
    $stmt = $pdo->prepare("SELECT account_name, kalit, qiymat FROM sozlamalar");
    $stmt->execute();
    $allSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Account bo'yicha sozlamalarni guruhlash
    $settingsByAccount = [];
    foreach ($allSettings as $s) {
        $account = $s['account_name'];
        if (!isset($settingsByAccount[$account])) {
            $settingsByAccount[$account] = [
                'work_start_time' => '09:00:00',
                'grace_minutes' => 20
            ];
        }
        if ($s['kalit'] == 'work_start_time') {
            $settingsByAccount[$account]['work_start_time'] = $s['qiymat'];
        }
        if ($s['kalit'] == 'grace_minutes') {
            $settingsByAccount[$account]['grace_minutes'] = (int)$s['qiymat'];
        }
    }
    
} catch(Exception $e) {
    error_log("Sozlamalarni olish xatosi: " . $e->getMessage());
}

// ============================================================
// 2. Barcha IN yozuvlarni olish (account_name bo'yicha)
// ============================================================
try {
    // Barcha accountlar uchun kechikishi hisoblanmagan yozuvlar
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            employee_id, 
            auth_date, 
            auth_datetime, 
            account_name,
            kechikish_daqiqa
        FROM attendance_data 
        WHERE direction = 'IN' 
        AND (kechikish_daqiqa IS NULL OR kechikish_daqiqa = 0)
        ORDER BY account_name, auth_datetime DESC
    ");
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total = count($records);
    $updated = 0;
    $errors = 0;
    
    if ($total == 0) {
        echo "✅ Hisoblanishi kerak bo'lgan yozuvlar yo'q!";
        exit;
    }
    
    echo "📊 Jami $total ta yozuv topildi\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    foreach ($records as $record) {
        $id = $record['id'];
        $employee_id = $record['employee_id'];
        $auth_date = $record['auth_date'];
        $auth_datetime = $record['auth_datetime'];
        $account_name = $record['account_name'];
        
        // Account sozlamalarini olish
        $accWorkStart = $settingsByAccount[$account_name]['work_start_time'] ?? '09:00:00';
        $accGraceMinutes = $settingsByAccount[$account_name]['grace_minutes'] ?? 20;
        
        try {
            // Xodimning ishga kelish vaqtini olish
            $stmt2 = $pdo->prepare("
                SELECT ishga_kelish_vaqti 
                FROM xodimlar 
                WHERE id = ? AND account_name = ?
            ");
            $stmt2->execute([$employee_id, $account_name]);
            $employee = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            // Xodimning shaxsiy vaqti yoki account sozlamasi
            $emp_work_start = $employee['ishga_kelish_vaqti'] ?? $accWorkStart;
            
            // Kechikishni hisoblash
            $auth_timestamp = strtotime($auth_datetime);
            $work_start_timestamp = strtotime($auth_date . ' ' . $emp_work_start);
            
            if ($auth_timestamp > $work_start_timestamp) {
                $late_minutes = floor(($auth_timestamp - $work_start_timestamp) / 60);
                $late_minutes = max(0, $late_minutes - $accGraceMinutes);
            } else {
                $late_minutes = 0;
            }
            
            // Bazaga yozish
            $stmt3 = $pdo->prepare("
                UPDATE attendance_data 
                SET kechikish_daqiqa = ? 
                WHERE id = ? AND account_name = ?
            ");
            $stmt3->execute([$late_minutes, $id, $account_name]);
            $updated++;
            
            if ($late_minutes > 0) {
                echo "✅ Account: $account_name | ID: $id | Employee: $employee_id | Kechikish: {$late_minutes} daqiqa\n";
            }
            
        } catch(Exception $e) {
            $errors++;
            echo "❌ Xatolik ID: $id - " . $e->getMessage() . "\n";
        }
    }
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "✅ Yangilandi: $updated / $total ta yozuv\n";
    if ($errors > 0) {
        echo "⚠️  Xatoliklar: $errors ta\n";
    }
    
} catch(Exception $e) {
    error_log("Kechikish hisoblash xatosi: " . $e->getMessage());
    echo "❌ Xatolik: " . $e->getMessage();
}
?>