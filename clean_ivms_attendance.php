<?php
// clean_ivms_attendance.php - IVMS.ATTENDANCE_DATA NI TOZALASH (CRON UCHUN)

require_once '/var/www/html/includes/config/database.php';

function cleanOldSyncedAttendance() {
    global $pdo;
    
    try {
        // ============================================
        // 1. O'chiriladigan yozuvlar soni
        // ============================================
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM ivms.attendance_data 
            WHERE synced = 1 
            AND created_at < DATE_SUB(NOW(), INTERVAL 72 HOUR)
        ");
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($count == 0) {
            echo date('Y-m-d H:i:s') . " | ✅ O'chiriladigan yozuvlar yo'q\n";
            return;
        }
        
        echo date('Y-m-d H:i:s') . " | 📊 O'chiriladigan yozuvlar soni: $count\n";
        
        // ============================================
        // 2. O'CHIRISH
        // ============================================
        $stmt = $pdo->prepare("
            DELETE FROM ivms.attendance_data 
            WHERE synced = 1 
            AND created_at < DATE_SUB(NOW(), INTERVAL 72 HOUR)
        ");
        $stmt->execute();
        
        $deleted = $stmt->rowCount();
        
        echo date('Y-m-d H:i:s') . " | ✅ $deleted ta yozuv o'chirildi\n";
        echo date('Y-m-d H:i:s') . " | 📅 72 soatdan eski synced=1 yozuvlar o'chirildi\n";
        
        // ============================================
        // 3. QOLGAN YOZUVLAR
        // ============================================
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM ivms.attendance_data WHERE synced = 1");
        $remaining = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        echo date('Y-m-d H:i:s') . " | 📊 Qolgan synced=1 yozuvlar: $remaining\n";
        
        // ============================================
        // 4. DISK HAJMI (ixtiyoriy)
        // ============================================
        $stmt = $pdo->query("
            SELECT 
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
            FROM information_schema.tables 
            WHERE table_schema = 'ivms' 
            AND table_name = 'attendance_data'
        ");
        $size = $stmt->fetch(PDO::FETCH_ASSOC)['size_mb'] ?? 0;
        echo date('Y-m-d H:i:s') . " | 💾 Jadval hajmi: $size MB\n";
        
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " | ❌ Xatolik: " . $e->getMessage() . "\n";
    }
}

// ============================================
// ISHGA TUSHIRISH
// ============================================
cleanOldSyncedAttendance();
