<?php
// includes/functions/employee.php

// Kunlik grafik faqat o'zi saqlangan sanaga amal qiladi
function getEmployeeWorkScheduleOverride(PDO $pdo, $employeeId, $date) {
    $stmt = $pdo->prepare("
        SELECT effective_date AS amal_qiladi, ishga_kelish_vaqti, ishdan_ketish_vaqti,
               tushlik_boshlanish, tushlik_tugash
        FROM employee_work_schedules
        WHERE xodim_id = ? AND effective_date = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([(int)$employeeId, $date]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

    return $schedule ?: null;
}

const MONTHLY_SETTING_COPY_COLUMNS = [
    'amal_qiladi', 'ishga_kelish_vaqti', 'ishdan_ketish_vaqti', 'tushlik_boshlanish', 'tushlik_tugash',
    'oylik_maosh', 'maosh_turi', 'foiz_qiymati', 'poster_user_id', 'soatlik_stavka', 'smena_summa',
    'erta_kelish_bonus_enabled', 'erta_kelish_bonus_amount', 'erta_kelish_max_bonus',
    'kech_ketish_bonus_enabled', 'kech_ketish_bonus_amount', 'kech_ketish_max_bonus',
    'kech_kelish_jarima_enabled', 'kech_kelish_jarima_per_minute', 'kech_kelish_max_jarima',
    'erta_ketish_jarima_enabled', 'erta_ketish_jarima_per_minute', 'erta_ketish_max_jarima',
    'kech_kelish_grace_minutes', 'erta_ketish_grace_minutes',
];

// Har bir xodimning oxirgi sozlamasini bo'sh oylarga (keyingi oygacha yoki berilgan oygacha) ko'chiradi
function autoUpdateMonthlySettings($accountName, $throughMonth = null) {
    global $pdo;

    $limitMonth = date('Y-m', strtotime('+12 months'));
    $targetMonth = date('Y-m', strtotime('+1 month'));
    if (is_string($throughMonth) && preg_match('/^\d{4}-\d{2}$/', $throughMonth) && $throughMonth > $targetMonth) {
        $targetMonth = min($throughMonth, $limitMonth);
    }

    try {
        $columns = $pdo->query("SHOW COLUMNS FROM oylik_sozlamalar")->fetchAll(PDO::FETCH_COLUMN);
        $copyColumns = array_values(array_intersect(MONTHLY_SETTING_COPY_COLUMNS, $columns));
        $hasAutoFlag = in_array('auto_copied', $columns, true);

        $stmt = $pdo->prepare("SELECT id FROM xodimlar WHERE holati = 'active' AND account_name = ?");
        $stmt->execute([$accountName]);
        $employees = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $latestStmt = $pdo->prepare("
            SELECT * FROM oylik_sozlamalar
            WHERE xodim_id = ?
            ORDER BY oy_vaqt DESC, amal_qiladi DESC, id DESC
            LIMIT 1
        ");
        $insertColumns = array_merge(['xodim_id', 'oy_vaqt'], $copyColumns, $hasAutoFlag ? ['auto_copied'] : []);
        $insertStmt = $pdo->prepare(
            'INSERT IGNORE INTO oylik_sozlamalar (`' . implode('`, `', $insertColumns) . '`) VALUES ('
            . implode(', ', array_fill(0, count($insertColumns), '?')) . ')'
        );

        foreach ($employees as $employeeId) {
            $latestStmt->execute([$employeeId]);
            $latest = $latestStmt->fetch(PDO::FETCH_ASSOC);
            if (!$latest || empty($latest['oy_vaqt'])) {
                continue;
            }

            $month = date('Y-m', strtotime($latest['oy_vaqt'] . ' +1 month'));
            while ($month <= $targetMonth) {
                $values = [(int)$employeeId, $month . '-01'];
                foreach ($copyColumns as $column) {
                    $values[] = $latest[$column] ?? null;
                }
                if ($hasAutoFlag) {
                    $values[] = 1;
                }
                $insertStmt->execute($values);
                $month = date('Y-m', strtotime($month . '-01 +1 month'));
            }
        }
    } catch (Exception $e) {
        error_log("autoUpdateMonthlySettings error: " . $e->getMessage());
    }
}

// Qo'lda o'zgartirilgan oydan keyingi avtomatik ko'chirilgan oylarni o'chiradi, keyin qayta ko'chiradi
function refreshCarriedMonthlySettings($employeeId, $fromMonth, $accountName) {
    global $pdo;

    try {
        $columns = $pdo->query("SHOW COLUMNS FROM oylik_sozlamalar")->fetchAll(PDO::FETCH_COLUMN);
        $copyColumns = array_values(array_intersect(MONTHLY_SETTING_COPY_COLUMNS, $columns));
        if (in_array('auto_copied', $columns, true) && $copyColumns) {
            $srcStmt = $pdo->prepare("
                SELECT * FROM oylik_sozlamalar
                WHERE xodim_id = ? AND oy_vaqt = ?
                ORDER BY amal_qiladi DESC, id DESC LIMIT 1
            ");
            $srcStmt->execute([(int)$employeeId, $fromMonth . '-01']);
            $src = $srcStmt->fetch(PDO::FETCH_ASSOC);
            if ($src) {
                // Qarz balansi saqlanib qolishi uchun nusxalarni o'chirmay, yangilaymiz
                $set = implode(', ', array_map(fn($c) => "`$c` = ?", $copyColumns));
                $upd = $pdo->prepare("UPDATE oylik_sozlamalar SET $set WHERE xodim_id = ? AND oy_vaqt > ? AND auto_copied = 1");
                $vals = array_map(fn($c) => $src[$c] ?? null, $copyColumns);
                $upd->execute(array_merge($vals, [(int)$employeeId, $fromMonth . '-01']));
            }
        }
    } catch (Exception $e) {
        error_log('refreshCarriedMonthlySettings error: ' . $e->getMessage());
    }
    autoUpdateMonthlySettings($accountName);
}

function autoUpdateMonthlySettingsLegacy($accountName) {
    global $pdo;
    
    $nextMonth = date('Y-m', strtotime('+1 month'));
    $nextMonthStart = $nextMonth . '-01';
    
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM xodimlar 
            WHERE holati = 'active' AND account_name = ?
        ");
        $stmt->execute([$accountName]);
        $employees = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($employees)) {
            return;
        }
        
        foreach ($employees as $employeeId) {
            $stmt = $pdo->prepare("
                SELECT * FROM oylik_sozlamalar 
                WHERE xodim_id = ? 
                ORDER BY oy_vaqt DESC, amal_qiladi DESC, id DESC 
                LIMIT 1
            ");
            $stmt->execute([$employeeId]);
            $lastSetting = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$lastSetting) {
                continue;
            }
            
            $stmt = $pdo->prepare("
                SELECT id FROM oylik_sozlamalar 
                WHERE xodim_id = ? AND oy_vaqt = ?
                LIMIT 1
            ");
            $stmt->execute([$employeeId, $nextMonthStart]);
            $exists = $stmt->fetch();
            
            if ($exists) {
                $sql = "UPDATE oylik_sozlamalar SET 
                    maosh_turi = ?,
                    oylik_maosh = ?,
                    foiz_qiymati = ?,
                    soatlik_stavka = ?,
                    smena_summa = ?,
                    poster_user_id = ?,
                    amal_qiladi = ?,
                    ishga_kelish_vaqti = ?,
                    ishdan_ketish_vaqti = ?,
                    tushlik_boshlanish = ?,
                    tushlik_tugash = ?,
                    kech_kelish_jarima_enabled = ?,
                    kech_kelish_jarima_per_minute = ?,
                    kech_kelish_max_jarima = ?,
                    kech_kelish_grace_minutes = ?,
                    erta_ketish_jarima_enabled = ?,
                    erta_ketish_jarima_per_minute = ?,
                    erta_ketish_max_jarima = ?,
                    erta_ketish_grace_minutes = ?,
                    erta_kelish_bonus_enabled = ?,
                    erta_kelish_bonus_amount = ?,
                    erta_kelish_max_bonus = ?,
                    kech_ketish_bonus_enabled = ?,
                    kech_ketish_bonus_amount = ?,
                    kech_ketish_max_bonus = ?
                    WHERE xodim_id = ? AND oy_vaqt = ?";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $lastSetting['maosh_turi'],
                    $lastSetting['oylik_maosh'],
                    $lastSetting['foiz_qiymati'],
                    $lastSetting['soatlik_stavka'],
                    $lastSetting['smena_summa'] ?? 0,
                    $lastSetting['poster_user_id'],
                    $lastSetting['amal_qiladi'],
                    $lastSetting['ishga_kelish_vaqti'],
                    $lastSetting['ishdan_ketish_vaqti'],
                    $lastSetting['tushlik_boshlanish'],
                    $lastSetting['tushlik_tugash'],
                    $lastSetting['kech_kelish_jarima_enabled'],
                    $lastSetting['kech_kelish_jarima_per_minute'],
                    $lastSetting['kech_kelish_max_jarima'],
                    $lastSetting['kech_kelish_grace_minutes'],
                    $lastSetting['erta_ketish_jarima_enabled'],
                    $lastSetting['erta_ketish_jarima_per_minute'],
                    $lastSetting['erta_ketish_max_jarima'],
                    $lastSetting['erta_ketish_grace_minutes'],
                    $lastSetting['erta_kelish_bonus_enabled'],
                    $lastSetting['erta_kelish_bonus_amount'],
                    $lastSetting['erta_kelish_max_bonus'],
                    $lastSetting['kech_ketish_bonus_enabled'],
                    $lastSetting['kech_ketish_bonus_amount'],
                    $lastSetting['kech_ketish_max_bonus'],
                    $employeeId,
                    $nextMonthStart
                ]);
            } else {
                $sql = "INSERT INTO oylik_sozlamalar 
                    (xodim_id, oy_vaqt, maosh_turi, oylik_maosh, foiz_qiymati, soatlik_stavka, smena_summa,
                     poster_user_id, amal_qiladi, ishga_kelish_vaqti, ishdan_ketish_vaqti, 
                     tushlik_boshlanish, tushlik_tugash, kech_kelish_jarima_enabled, 
                     kech_kelish_jarima_per_minute, kech_kelish_max_jarima, kech_kelish_grace_minutes,
                     erta_ketish_jarima_enabled, erta_ketish_jarima_per_minute, erta_ketish_max_jarima, 
                     erta_ketish_grace_minutes, erta_kelish_bonus_enabled, erta_kelish_bonus_amount, 
                     erta_kelish_max_bonus, kech_ketish_bonus_enabled, kech_ketish_bonus_amount, 
                     kech_ketish_max_bonus) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $employeeId,
                    $nextMonthStart,
                    $lastSetting['maosh_turi'],
                    $lastSetting['oylik_maosh'],
                    $lastSetting['foiz_qiymati'],
                    $lastSetting['soatlik_stavka'],
                    $lastSetting['smena_summa'] ?? 0,
                    $lastSetting['poster_user_id'],
                    $lastSetting['amal_qiladi'],
                    $lastSetting['ishga_kelish_vaqti'],
                    $lastSetting['ishdan_ketish_vaqti'],
                    $lastSetting['tushlik_boshlanish'],
                    $lastSetting['tushlik_tugash'],
                    $lastSetting['kech_kelish_jarima_enabled'],
                    $lastSetting['kech_kelish_jarima_per_minute'],
                    $lastSetting['kech_kelish_max_jarima'],
                    $lastSetting['kech_kelish_grace_minutes'],
                    $lastSetting['erta_ketish_jarima_enabled'],
                    $lastSetting['erta_ketish_jarima_per_minute'],
                    $lastSetting['erta_ketish_max_jarima'],
                    $lastSetting['erta_ketish_grace_minutes'],
                    $lastSetting['erta_kelish_bonus_enabled'],
                    $lastSetting['erta_kelish_bonus_amount'],
                    $lastSetting['erta_kelish_max_bonus'],
                    $lastSetting['kech_ketish_bonus_enabled'],
                    $lastSetting['kech_ketish_bonus_amount'],
                    $lastSetting['kech_ketish_max_bonus']
                ]);
            }
        }
        
    } catch (Exception $e) {
        error_log("autoUpdateMonthlySettings error: " . $e->getMessage());
    }
}

function getAllEmployees($accountName = null) {
    global $pdo;
    $employees = [];
    
    if($accountName) {
        $stmt = $pdo->prepare("SELECT * FROM xodimlar WHERE holati = 'active' AND account_name = ? ORDER BY ismi");
        $stmt->execute([$accountName]);
    } else {
        $stmt = $pdo->query("SELECT * FROM xodimlar WHERE holati = 'active' ORDER BY ismi");
    }
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $employees[] = $row;
    }
    return $employees;
}

function getEmployeeById($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM xodimlar WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function updateEmployee($id, $data) {
    global $pdo;
    
    $stmt = $pdo->prepare("UPDATE xodimlar SET turi = ?, oylik_miqdori = ?, foiz_miqdori = ?, lavozimi = ? WHERE id = ?");
    return $stmt->execute([
        $data['turi'],
        $data['oylik_miqdori'],
        $data['foiz_miqdori'],
        $data['lavozimi'] ?? '',
        $id
    ]);
}

function saveMonthlySalary($employeeId, $month, $monthlySalary, $salaryType, $percentValue) {
    global $pdo;
    
    $stmt = $pdo->prepare("INSERT INTO oylik_sozlamalar 
                           (xodim_id, oy_vaqt, oylik_maosh, maosh_turi, foiz_qiymati) 
                           VALUES (?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE 
                           oylik_maosh = VALUES(oylik_maosh),
                           maosh_turi = VALUES(maosh_turi),
                           foiz_qiymati = VALUES(foiz_qiymati)");
    
    return $stmt->execute([$employeeId, $month . '-01', $monthlySalary, $salaryType, $percentValue]);
}

function linkEmployeeToWaiter($employeeId, $posterUserId) {
    global $pdo;
    
    $stmt = $pdo->prepare("UPDATE xodimlar SET poster_user_id = ? WHERE id = ?");
    return $stmt->execute([$posterUserId, $employeeId]);
}
?>