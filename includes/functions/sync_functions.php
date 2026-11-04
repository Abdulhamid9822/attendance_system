<?php
/**
 * sync_functions.php - IVMS dan SalarySystem ga sinxronizatsiya
 * Kunduzgi, tungi va sutkalik smenalarni qo'llab-quvvatlaydi
 * Eng erta IN va eng kech OUT amal qiladi
 * 00:00 dan o'tgan bo'lsa ham sanani qarab kech deb hisoblanadi
 * TUZATILGAN: 00:00 dan o'tgan OUT to'g'ri hisoblanadi
 */
require_once __DIR__ . '/poster.php';
require_once __DIR__ . '/employee.php';

// ============================================================
// LOG FAYL YO'LLARI
// ============================================================
$logDir = dirname(__DIR__, 2) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

$syncLogFile = $logDir . '/sync.log';
$fullLogFile = $logDir . '/full_sync.log';
$errorLogFile = $logDir . '/error.log';

// ============================================================
// LOG FUNKSIYALARI
// ============================================================
function writeLogFile($file, $msg) {
    if (empty($file)) return;
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $log = date('Y-m-d H:i:s') . " | $msg\n";
    @file_put_contents($file, $log, FILE_APPEND | LOCK_EX);
    truncateLogFile($file, 1000);
}

function truncateLogFile($file, $maxLines = 1000) {
    if (!file_exists($file)) return;
    try {
        if (filesize($file) < 1024 * 1024) return;
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return;
        $lineCount = count($lines);
        if ($lineCount > $maxLines) {
            $keepLines = array_slice($lines, -$maxLines);
            file_put_contents($file, implode("\n", $keepLines) . "\n", LOCK_EX);
        }
    } catch (Exception $e) {}
}

function logError($msg) {
    global $errorLogFile;
    writeLogFile($errorLogFile, "❌ " . $msg);
}

function logInfo($msg) {
    global $syncLogFile;
    writeLogFile($syncLogFile, "ℹ️ " . $msg);
}

function logSuccess($msg) {
    global $syncLogFile;
    writeLogFile($syncLogFile, "✅ " . $msg);
}

function syncPosterCashShiftAttendance(PDO $pdoSalary) {
    $result = [
        'status' => 'success',
        'accounts' => 0,
        'shifts' => 0,
        'attendance_records' => 0,
        'errors' => [],
    ];

    $dateTo = date('Ymd');
    $dateFrom = date('Ymd', strtotime('-30 days'));
    $isValidDateTime = static function ($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return false;
        }
        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $dateTime !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $dateTime->format('Y-m-d H:i:s') === $value;
    };

    try {
        $tokenRows = $pdoSalary->query("
            SELECT account_id, account_name, account_domain, access_token
            FROM poster_tokens
            ORDER BY id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $result['status'] = 'error';
        $result['errors'][] = 'Poster tokenlari olinmadi: ' . $e->getMessage();
        logError($result['errors'][0]);
        return $result;
    }

    $tokensByAccount = [];
    foreach ($tokenRows as $tokenRow) {
        $accountName = trim((string)($tokenRow['account_name'] ?? ''));
        if ($accountName === '' || isset($tokensByAccount[$accountName])) {
            continue;
        }
        $token = trim((string)($tokenRow['access_token'] ?? ''));
        $apiDomain = trim((string)($tokenRow['account_domain'] ?? '')) ?: $accountName;
        if ($token === '') {
            $result['errors'][] = "Poster token bo'sh: {$accountName}";
            continue;
        }
        $tokensByAccount[$accountName] = [
            'access_token' => $token,
            'api_domain' => $apiDomain,
        ];
    }

    foreach ($tokensByAccount as $accountName => $account) {
        $result['accounts']++;
        try {
            $employeeStmt = $pdoSalary->prepare("
                SELECT id, poster_user_id
                FROM xodimlar
                WHERE account_name = ? AND poster_user_id IS NOT NULL AND holati != 'deleted'
            ");
            $employeeStmt->execute([$accountName]);
            $employeeIdsByPosterId = [];
            foreach ($employeeStmt->fetchAll(PDO::FETCH_ASSOC) as $employee) {
                $posterUserId = (int)($employee['poster_user_id'] ?? 0);
                if ($posterUserId > 0) {
                    $employeeIdsByPosterId[$posterUserId] = (int)$employee['id'];
                }
            }
            if ($employeeIdsByPosterId === []) {
                continue;
            }

            $response = posterApi('finance.getCashShifts', [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'timezone' => 'client',
            ], $account['api_domain'], $account['access_token']);
            if (isset($response['error']) || !is_array($response['response'] ?? null)) {
                throw new RuntimeException(
                    'Kassa smenalari olinmadi: ' . ($response['error'] ?? 'Poster javob formati noto‘g‘ri.')
                );
            }

            $result['shifts'] += count($response['response']);
            $dailyEvents = [];
            foreach ($response['response'] as $cashShift) {
                if (!is_array($cashShift)) {
                    continue;
                }

                $startUserId = (int)($cashShift['user_id_start'] ?? 0);
                $startDateTime = trim((string)($cashShift['date_start'] ?? ''));
                if (
                    $startUserId > 0
                    && isset($employeeIdsByPosterId[$startUserId])
                    && $isValidDateTime($startDateTime)
                ) {
                    $employeeId = $employeeIdsByPosterId[$startUserId];
                    $date = substr($startDateTime, 0, 10);
                    $key = $employeeId . '|' . $date;
                    if (!isset($dailyEvents[$key])) {
                        $dailyEvents[$key] = [
                            'employee_id' => $employeeId,
                            'date' => $date,
                            'in_datetime' => null,
                            'out_datetime' => null,
                        ];
                    }
                    if (
                        $dailyEvents[$key]['in_datetime'] === null
                        || $startDateTime < $dailyEvents[$key]['in_datetime']
                    ) {
                        $dailyEvents[$key]['in_datetime'] = $startDateTime;
                    }
                }

                $endUserId = (int)($cashShift['user_id_end'] ?? 0);
                $endDateTime = trim((string)($cashShift['date_end'] ?? ''));
                if (
                    $endUserId > 0
                    && isset($employeeIdsByPosterId[$endUserId])
                    && $isValidDateTime($endDateTime)
                ) {
                    $employeeId = $employeeIdsByPosterId[$endUserId];
                    $date = substr($endDateTime, 0, 10);
                    if (
                        $endUserId === $startUserId
                        && $isValidDateTime($startDateTime)
                    ) {
                        $date = substr($startDateTime, 0, 10);
                    }
                    $key = $employeeId . '|' . $date;
                    if (!isset($dailyEvents[$key])) {
                        $dailyEvents[$key] = [
                            'employee_id' => $employeeId,
                            'date' => $date,
                            'in_datetime' => null,
                            'out_datetime' => null,
                        ];
                    }
                    if (
                        $dailyEvents[$key]['out_datetime'] === null
                        || $endDateTime > $dailyEvents[$key]['out_datetime']
                    ) {
                        $dailyEvents[$key]['out_datetime'] = $endDateTime;
                    }
                }
            }

            foreach ($dailyEvents as $event) {
                $employeeId = $event['employee_id'];
                $date = $event['date'];
                $stmt = $pdoSalary->prepare("
                    SELECT id, in_time, in_datetime, in_device, in_late_minutes,
                           out_time, out_datetime, out_device, out_early_minutes, total_work_hours
                    FROM attendance_data
                    WHERE employee_id = ? AND account_name = ? AND auth_date = ?
                    LIMIT 1
                ");
                $stmt->execute([(string)$employeeId, $accountName, $date]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

                $inDateTime = $event['in_datetime'];
                $inTime = $inDateTime !== null ? substr($inDateTime, 11, 8) : null;
                $inDevice = $inDateTime !== null ? 'Poster kassasi' : null;
                if ($existing && !empty($existing['in_time'])) {
                    $existingInDateTime = $existing['in_datetime']
                        ?: $date . ' ' . $existing['in_time'];
                    if ($inDateTime === null || $existingInDateTime <= $inDateTime) {
                        $inDateTime = $existingInDateTime;
                        $inTime = $existing['in_time'];
                        $inDevice = $existing['in_device'];
                    }
                }

                $outDateTime = $event['out_datetime'];
                $outTime = $outDateTime !== null ? substr($outDateTime, 11, 8) : null;
                $outDevice = $outDateTime !== null ? 'Poster kassasi' : null;
                if ($existing && !empty($existing['out_time'])) {
                    $existingOutDateTime = $existing['out_datetime']
                        ?: $date . ' ' . $existing['out_time'];
                    if ($outDateTime === null || $existingOutDateTime >= $outDateTime) {
                        $outDateTime = $existingOutDateTime;
                        $outTime = $existing['out_time'];
                        $outDevice = $existing['out_device'];
                    }
                }

                $monthlySettings = getMonthlySettings($pdoSalary, $employeeId, $date);
                $inLateMinutes = $inTime !== null && $monthlySettings !== null
                    ? calculateInMinutes($inTime, $monthlySettings)
                    : (int)($existing['in_late_minutes'] ?? 0);
                $outEarlyMinutes = $outTime !== null && $monthlySettings !== null
                    ? calculateOutMinutes($outTime, $date, $monthlySettings)
                    : (int)($existing['out_early_minutes'] ?? 0);
                $workHours = $inDateTime !== null && $outDateTime !== null && $monthlySettings !== null
                    ? calculateWorkHours($inDateTime, $outDateTime, $date, $monthlySettings)
                    : (float)($existing['total_work_hours'] ?? 0);

                if ($existing) {
                    $saveStmt = $pdoSalary->prepare("
                        UPDATE attendance_data
                        SET in_time = ?, in_datetime = ?, in_device = ?, in_late_minutes = ?,
                            out_time = ?, out_datetime = ?, out_device = ?, out_early_minutes = ?,
                            total_work_hours = ?, updated_at = NOW()
                        WHERE id = ? AND account_name = ?
                    ");
                    $saveStmt->execute([
                        $inTime, $inDateTime, $inDevice, $inLateMinutes,
                        $outTime, $outDateTime, $outDevice, $outEarlyMinutes,
                        $workHours, $existing['id'], $accountName,
                    ]);
                } else {
                    $saveStmt = $pdoSalary->prepare("
                        INSERT INTO attendance_data (
                            employee_id, account_name, auth_date,
                            in_time, in_datetime, in_device, in_late_minutes,
                            out_time, out_datetime, out_device, out_early_minutes,
                            total_work_hours, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $saveStmt->execute([
                        (string)$employeeId, $accountName, $date,
                        $inTime, $inDateTime, $inDevice, $inLateMinutes,
                        $outTime, $outDateTime, $outDevice, $outEarlyMinutes,
                        $workHours,
                    ]);
                }
                $result['attendance_records']++;
            }
        } catch (Throwable $e) {
            $result['status'] = 'partial';
            $result['errors'][] = $accountName . ': ' . $e->getMessage();
            logError('Poster kassa smenasi sinxronlash xatosi (' . $accountName . '): ' . $e->getMessage());
        }
    }

    if ($result['errors'] !== [] && $result['status'] === 'success') {
        $result['status'] = 'partial';
    }
    logSuccess(sprintf(
        'Poster kassa smenalari: %d akkaunt, %d smena, %d davomat yozuvi, %d xato',
        $result['accounts'],
        $result['shifts'],
        $result['attendance_records'],
        count($result['errors'])
    ));

    return $result;
}

// ============================================================
// 0. SMENA TURLARINI ANIQLASH
// ============================================================

// 0.1 TUNGI SMENA ANIQLASH
function isNightShift($work_start, $work_end) {
    if (empty($work_start) || empty($work_end)) return false;
    $start_ts = strtotime($work_start);
    $end_ts = strtotime($work_end);
    if ($start_ts === false || $end_ts === false) return false;
    return $start_ts > $end_ts;
}

// 0.2 SUTKALIK SMENA ANIQLASH (24 soat)
function isFullDayShift($work_start, $work_end) {
    if (empty($work_start) || empty($work_end)) return false;
    
    $start_ts = strtotime($work_start);
    $end_ts = strtotime($work_end);
    if ($start_ts === false || $end_ts === false) return false;
    
    $start_time = date('H:i', $start_ts);
    $end_time = date('H:i', $end_ts);
    
    return $start_time === $end_time;
}

// 0.3 SMENA TURINI OLISH
function getShiftType($work_start, $work_end) {
    if (empty($work_start) || empty($work_end)) return 'unknown';
    
    if (isFullDayShift($work_start, $work_end)) {
        return 'full_day';
    } elseif (isNightShift($work_start, $work_end)) {
        return 'night';
    } else {
        return 'day';
    }
}

// ============================================================
// 0.4 DIRECTION NI VAQTGA QARAB ANIQLASH
// ============================================================
function getDirectionBySchedule($auth_time, $employee_id, $pdo_salary) {
    $monthlySettings = getMonthlySettings($pdo_salary, $employee_id);
    
    if ($monthlySettings === null) {
        $hour = (int)date('H', strtotime($auth_time));
        if ($hour >= 6 && $hour < 12) {
            return 'in';
        } else {
            return 'out';
        }
    }
    
    $work_start = $monthlySettings['ishga_kelish_vaqti'] ?? '09:00:00';
    $work_end = $monthlySettings['ishdan_ketish_vaqti'] ?? '18:00:00';
    
    $shiftType = getShiftType($work_start, $work_end);
    
    $hour = (int)date('H', strtotime($auth_time));
    $minute = (int)date('i', strtotime($auth_time));
    $time_decimal = $hour + ($minute / 60);
    
    $start_hour = (int)substr($work_start, 0, 2);
    $start_minute = (int)substr($work_start, 3, 2);
    $start_decimal = $start_hour + ($start_minute / 60);
    
    $end_hour = (int)substr($work_end, 0, 2);
    $end_minute = (int)substr($work_end, 3, 2);
    $end_decimal = $end_hour + ($end_minute / 60);
    
    if ($shiftType == 'full_day') {
        if ($time_decimal >= 6 && $time_decimal < 12) {
            return 'in';
        } elseif ($time_decimal >= 18 || $time_decimal < 6) {
            return 'out';
        } else {
            return 'out';
        }
    }
    
    if ($shiftType == 'night') {
        if ($time_decimal >= $start_decimal || $time_decimal < $end_decimal) {
            return 'in';
        } else {
            return 'out';
        }
    }
    
    if ($shiftType == 'day') {
        if ($time_decimal >= 6 && $time_decimal < 12) {
            return 'in';
        } elseif ($time_decimal >= 18 || $time_decimal < 2) {
            return 'out';
        } else {
            if ($time_decimal >= $start_decimal - 1 && $time_decimal < $start_decimal + 2) {
                return 'in';
            } elseif ($time_decimal >= $end_decimal - 2 && $time_decimal < $end_decimal + 1) {
                return 'out';
            } else {
                return ($time_decimal < 12) ? 'in' : 'out';
            }
        }
    }
    
    return 'out';
}

// ============================================================
// 1. XODIM MA'LUMOTLARINI OLISH
// ============================================================
function getEmployeeSettings($pdo, $employee_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, ismi, account_name, poster_id, holati
            FROM xodimlar 
            WHERE id = ? AND holati = 'active'
            LIMIT 1
        ");
        $stmt->execute([$employee_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) return $result;
        
        $stmt = $pdo->prepare("
            SELECT id, ismi, account_name, poster_id, holati
            FROM xodimlar 
            WHERE poster_id = ? AND holati = 'active'
              AND (SELECT COUNT(*) FROM xodimlar x2 WHERE x2.poster_id = xodimlar.poster_id AND x2.holati = 'active') = 1
            LIMIT 1
        ");
        $stmt->execute([$employee_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) return $result;
        
        return null;
    } catch (Exception $e) {
        logError("Xodim ma'lumotlarini olish xatosi (ID: $employee_id): " . $e->getMessage());
        return null;
    }
}

// ============================================================
// 2. OYLIK SOZLAMALARNI OLISH
// ============================================================
function getMonthlySettings($pdo, $employee_id, $workDate = null)
{
    try {
        if (empty($workDate)) {
            $workDate = date('Y-m-d');
        }
        $currentMonth = date('Y-m-01', strtotime($workDate));

        $stmt = $pdo->prepare("
            SELECT
                id,
                xodim_id,
                oy_vaqt,
                oylik_maosh,
                maosh_turi,
                foiz_qiymati,
                poster_user_id,
                amal_qiladi,
                ishlagan_kun,
                hisoblangan_maosh,
                bonus,
                jarima,
                qarzdorlik,
                oldingi_oy_balansi,
                keyingi_oyga_balansi,
                created_at,
                updated_at,
                soatlik_stavka,
                smena_summa,
                ishga_kelish_vaqti,
                ishdan_ketish_vaqti,
                tushlik_boshlanish,
                tushlik_tugash,
                erta_kelish_bonus_enabled,
                erta_kelish_bonus_amount,
                erta_kelish_max_bonus,
                kech_ketish_bonus_enabled,
                kech_ketish_bonus_amount,
                kech_ketish_max_bonus,
                kech_kelish_jarima_enabled,
                kech_kelish_jarima_per_minute,
                kech_kelish_max_jarima,
                erta_ketish_jarima_enabled,
                erta_ketish_jarima_per_minute,
                erta_ketish_max_jarima,
                kech_kelish_grace_minutes,
                erta_ketish_grace_minutes
            FROM oylik_sozlamalar
            WHERE
                xodim_id = ?
                AND oy_vaqt = ?
                AND amal_qiladi <= ?
            ORDER BY
                amal_qiladi DESC,
                id DESC
            LIMIT 1
        ");

        $stmt->execute([$employee_id, $currentMonth, $workDate]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            $stmt = $pdo->prepare("
                SELECT
                    id, xodim_id, oy_vaqt, oylik_maosh, maosh_turi, foiz_qiymati,
                    poster_user_id, amal_qiladi, ishlagan_kun, hisoblangan_maosh,
                    bonus, jarima, qarzdorlik, oldingi_oy_balansi, keyingi_oyga_balansi,
                    created_at, updated_at, soatlik_stavka, smena_summa, ishga_kelish_vaqti,
                    ishdan_ketish_vaqti, tushlik_boshlanish, tushlik_tugash,
                    erta_kelish_bonus_enabled, erta_kelish_bonus_amount, erta_kelish_max_bonus,
                    kech_ketish_bonus_enabled, kech_ketish_bonus_amount, kech_ketish_max_bonus,
                    kech_kelish_jarima_enabled, kech_kelish_jarima_per_minute,
                    kech_kelish_max_jarima, erta_ketish_jarima_enabled,
                    erta_ketish_jarima_per_minute, erta_ketish_max_jarima,
                    kech_kelish_grace_minutes, erta_ketish_grace_minutes
                FROM oylik_sozlamalar
                WHERE xodim_id = ? AND amal_qiladi <= ?
                ORDER BY oy_vaqt DESC, amal_qiladi DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$employee_id, $workDate]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($result) {
            $scheduleOverride = getEmployeeWorkScheduleOverride($pdo, $employee_id, $workDate);
            if ($scheduleOverride && $scheduleOverride['amal_qiladi'] >= ($result['amal_qiladi'] ?? '')) {
                foreach (['ishga_kelish_vaqti', 'ishdan_ketish_vaqti', 'tushlik_boshlanish', 'tushlik_tugash'] as $field) {
                    $result[$field] = $scheduleOverride[$field];
                }
            }
            logInfo("✅ Oylik sozlama topildi: employee={$employee_id}, ishga_kelish={$result['ishga_kelish_vaqti']}, ishdan_ketish={$result['ishdan_ketish_vaqti']}");
            return $result;
        }

        logInfo("ℹ️ Oylik sozlama topilmadi: employee={$employee_id}");
        return null;

    } catch (Exception $e) {
        logError("Oylik sozlamalarni olish xatosi: " . $e->getMessage());
        return null;
    }
}

// ============================================================
// 3. IN VAQTI HISOBI
// ============================================================
function calculateInMinutes($in_time, $monthlySettings) {
    if (empty($in_time) || empty($monthlySettings)) {
        return 0;
    }
    
    try {
        if (empty($monthlySettings['ishga_kelish_vaqti'])) {
            return 0;
        }
        
        $work_start = $monthlySettings['ishga_kelish_vaqti'];
        $grace_minutes = isset($monthlySettings['kech_kelish_grace_minutes']) ? (int)$monthlySettings['kech_kelish_grace_minutes'] : 0;
        
        $in_ts = strtotime($in_time);
        $start_ts = strtotime($work_start);
        
        if ($in_ts > $start_ts) {
            $diff = floor(($in_ts - $start_ts) / 60);
            $diff = max(0, $diff - $grace_minutes);
            return $diff;
        } elseif ($in_ts < $start_ts) {
            return -floor(($start_ts - $in_ts) / 60);
        }
        return 0;
    } catch (Exception $e) {
        logError("IN vaqtini hisoblash xatosi: " . $e->getMessage());
        return 0;
    }
}

// ============================================================
// 4. OUT VAQTI HISOBI (TUZATILGAN)
// ============================================================
function calculateOutMinutes($out_time, $auth_date, $monthlySettings) {
    if (empty($out_time) || empty($monthlySettings)) {
        return 0;
    }
    
    try {
        if (empty($monthlySettings['ishdan_ketish_vaqti'])) {
            return 0;
        }
        
        $work_start = $monthlySettings['ishga_kelish_vaqti'] ?? '09:00:00';
        $work_end = $monthlySettings['ishdan_ketish_vaqti'];
        
        $shiftType = getShiftType($work_start, $work_end);
        
        $out_ts = strtotime($out_time);
        $out_hour = (int)date('H', $out_ts);
        
        // ============================================================
        // KUNDUZGI SMENA
        // ============================================================
        if ($shiftType == 'day') {
            // Agar OUT 00:00-05:59 bo'lsa (ertalab)
            if ($out_hour >= 0 && $out_hour < 6) {
                // Ertalabgi OUT - oldingi kunga tegishli
                // Ish vaqti tugashi oldingi kun
                $end_ts = strtotime($auth_date . ' ' . $work_end . ' -1 day');
                
                // OUT vaqtini 23:59 deb hisoblaymiz (chunki u shunday o'zgartirilgan)
                $out_ts_for_calc = strtotime($auth_date . ' 23:59:00 -1 day');
                
                $diff_minutes = floor(($out_ts_for_calc - $end_ts) / 60);
                
                logInfo("☀️ KUNDUZGI - ERTALABGI OUT (23:59): diff={$diff_minutes} min");
                return $diff_minutes;
            }
            
            // Normal OUT (kechqurun yoki tushdan keyin)
            $end_ts = strtotime($auth_date . ' ' . $work_end);
            $diff_minutes = floor(($out_ts - $end_ts) / 60);
            
            $grace_minutes = isset($monthlySettings['erta_ketish_grace_minutes']) 
                ? (int)$monthlySettings['erta_ketish_grace_minutes'] 
                : 0;
            
            if ($diff_minutes > 0) {
                $diff_minutes = max(0, $diff_minutes - $grace_minutes);
                logInfo("☀️ KUNDUZGI - KECH KETISH: diff={$diff_minutes} min (BONUS)");
            } elseif ($diff_minutes < 0) {
                $diff_minutes = min(0, $diff_minutes + $grace_minutes);
                logInfo("☀️ KUNDUZGI - ERTA KETISH: diff={$diff_minutes} min (JARIMA)");
            }
            
            return $diff_minutes;
        }
        
        // ============================================================
        // TUNGI SMENA
        // ============================================================
        if ($shiftType == 'night') {
            $end_ts = strtotime($auth_date . ' ' . $work_end . ' +1 day');
            
            // Agar OUT 00:00-05:59 bo'lsa (ertalab)
            if ($out_hour >= 0 && $out_hour < 6) {
                // OUT 23:59 deb hisoblaymiz (oldingi kun)
                $out_ts_for_calc = strtotime($auth_date . ' 23:59:00 -1 day');
                $diff_minutes = floor(($out_ts_for_calc - $end_ts) / 60);
                
                logInfo("🌙 TUNGI - ERTALABGI OUT (23:59): diff={$diff_minutes} min");
                return $diff_minutes;
            }
            
            // Normal OUT
            $diff_minutes = floor(($out_ts - $end_ts) / 60);
            logInfo("🌙 TUNGI SMENA: out={$out_time}, diff={$diff_minutes} min");
            return $diff_minutes;
        }
        
        // ============================================================
        // SUTKALIK SMENA
        // ============================================================
        if ($shiftType == 'full_day') {
            $end_ts = strtotime($auth_date . ' ' . $work_end . ' +1 day');
            
            // Agar OUT 00:00-05:59 bo'lsa (ertalab)
            if ($out_hour >= 0 && $out_hour < 6) {
                $out_ts_for_calc = strtotime($auth_date . ' 23:59:00 -1 day');
                $diff_minutes = floor(($out_ts_for_calc - $end_ts) / 60);
                
                logInfo("🔄 SUTKALIK - ERTALABGI OUT (23:59): diff={$diff_minutes} min");
                return $diff_minutes;
            }
            
            // Normal OUT
            $diff_minutes = floor(($out_ts - $end_ts) / 60);
            logInfo("🔄 SUTKALIK: out={$out_time}, diff={$diff_minutes} min");
            return $diff_minutes;
        }
        
        return 0;
        
    } catch (Exception $e) {
        logError("OUT vaqtini hisoblash xatosi: " . $e->getMessage());
        return 0;
    }
}

// ============================================================
// 5. ISH VAQTINI HISOBLASH
// ============================================================
function calculateWorkHours($in_datetime, $out_datetime, $auth_date, $monthlySettings) {
    if (empty($in_datetime) || empty($out_datetime)) return 0;
    try {
        $in_ts = strtotime($in_datetime);
        $out_ts = strtotime($out_datetime);
        
        if ($out_ts <= $in_ts) {
            $out_ts = strtotime('+1 day', $out_ts);
        }
        
        if ($out_ts <= $in_ts) return 0;
        
        $total_seconds = $out_ts - $in_ts;
        
        if (!empty($monthlySettings) && 
            !empty($monthlySettings['tushlik_boshlanish']) && 
            !empty($monthlySettings['tushlik_tugash'])) {
            
            $work_start = $monthlySettings['ishga_kelish_vaqti'] ?? '09:00:00';
            $work_end = $monthlySettings['ishdan_ketish_vaqti'] ?? '18:00:00';
            $shiftType = getShiftType($work_start, $work_end);
            
            if ($shiftType == 'day') {
                $lunch_start = strtotime($auth_date . ' ' . $monthlySettings['tushlik_boshlanish']);
                $lunch_end = strtotime($auth_date . ' ' . $monthlySettings['tushlik_tugash']);
                
                if ($lunch_start !== false && $lunch_end !== false &&
                    $in_ts <= $lunch_start && $out_ts >= $lunch_end) {
                    $total_seconds -= ($lunch_end - $lunch_start);
                }
            }
        }
        
        return round(max(0, $total_seconds) / 3600, 2);
    } catch (Exception $e) {
        logError("Ish vaqtini hisoblash xatosi: " . $e->getMessage());
        return 0;
    }
}

// ============================================================
// 6. YOZUVNI SAQLASH
// ============================================================
function saveOrUpdateAttendance($pdo, $data, $account_name) {
    try {
        $monthlySettings = getMonthlySettings($pdo, $data['employee_id']);
        
        $work_start = $monthlySettings['ishga_kelish_vaqti'] ?? '09:00:00';
        $work_end = $monthlySettings['ishdan_ketish_vaqti'] ?? '18:00:00';
        
        $shiftType = getShiftType($work_start, $work_end);
        
        $shiftTypeText = 'KUNDUZGI';
        if ($shiftType == 'night') $shiftTypeText = 'TUNGI';
        elseif ($shiftType == 'full_day') $shiftTypeText = 'SUTKALIK (24 soat)';
        
        logInfo("📋 SMENA TURI: {$shiftTypeText} (start={$work_start}, end={$work_end})");
        
        // ============================================================
        // 1. OCHIQ IN YOZUVNI TOPISH
        // ============================================================
        $stmt = $pdo->prepare("
            SELECT 
                id, employee_id, auth_date,
                in_datetime, in_time, in_device, in_late_minutes,
                out_time, out_datetime, out_early_minutes, total_work_hours
            FROM attendance_data 
            WHERE employee_id = ? 
                AND account_name = ?
                AND out_time IS NULL
                AND in_time IS NOT NULL
            ORDER BY auth_date ASC, in_time ASC
            LIMIT 1
        ");
        $stmt->execute([$data['employee_id'], $account_name]);
        $openRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // ============================================================
        // 2. OCHIQ IN + OUT KELSA
        // ============================================================
        if ($openRecord && !empty($data['out_time'])) {
            logInfo("🔍 Ochiq IN topildi va OUT keldi: ID={$openRecord['id']}, date={$openRecord['auth_date']}");
            
            $out_time = $data['out_time'];
            $out_datetime = $data['out_datetime'];
            
            // OUT vaqti 00:00-05:59 bo'lsa -> 23:59 ga o'zgartiramiz
            $out_hour = (int)date('H', strtotime($out_time));
            if ($out_hour >= 0 && $out_hour < 6) {
                $out_date = date('Y-m-d', strtotime($out_datetime) - 86400);
                $out_time = '23:59:00';
                $out_datetime = $out_date . ' 23:59:00';
                logInfo("🔄 OUT 00:00 dan o'tib ketdi, 23:59 ga o'zgartirildi: {$out_datetime}");
            }
            
            $out_early_minutes = 0;
            $total_hours = 0;
            
            if ($monthlySettings !== null) {
                $out_early_minutes = calculateOutMinutes($out_time, $openRecord['auth_date'], $monthlySettings);
                
                if (!empty($openRecord['in_datetime'])) {
                    $total_hours = calculateWorkHours($openRecord['in_datetime'], $out_datetime, $openRecord['auth_date'], $monthlySettings);
                }
            }
            
            $stmt = $pdo->prepare("
                UPDATE attendance_data 
                SET 
                    out_time = ?,
                    out_datetime = ?,
                    out_device = ?,
                    out_early_minutes = ?,
                    total_work_hours = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $out_time,
                $out_datetime,
                $data['out_device'] ?? 'device',
                $out_early_minutes,
                $total_hours,
                $openRecord['id']
            ]);
            
            logSuccess("✅ Ochiq IN OUT bilan yopildi: ID={$openRecord['id']}, OUT={$out_time}, erta_ketish={$out_early_minutes} min");
            return true;
        }
        
        // ============================================================
        // 3. OCHIQ IN + YANGI IN KELSA (OUT qilmagan)
        // ============================================================
        if ($openRecord && !empty($data['in_time']) && $openRecord['auth_date'] != $data['auth_date']) {
            logInfo("🔍 Ochiq IN topildi (OUT qilmagan), yangi IN keldi: ID={$openRecord['id']}, oldingi date={$openRecord['auth_date']}");
            
            if ($monthlySettings && !empty($monthlySettings['ishdan_ketish_vaqti'])) {
                $work_end_time = $monthlySettings['ishdan_ketish_vaqti'];
                $end_ts = strtotime($openRecord['auth_date'] . ' ' . $work_end_time);
                
                if ($shiftType == 'night' || $shiftType == 'full_day') {
                    $end_ts = strtotime($openRecord['auth_date'] . ' ' . $work_end_time . ' +1 day');
                }
                
                // 30 daqiqa oldin
                $auto_out_ts = $end_ts - 1800;
                $auto_out_time = date('H:i:s', $auto_out_ts);
                $auto_out_datetime = date('Y-m-d H:i:s', $auto_out_ts);
                
                logInfo("🔄 Ochiq IN avtomatik yopiladi: OUT={$auto_out_time} (ish vaqti tugashidan 30 daqiqa oldin)");
                
                $out_early_minutes = 0;
                if ($monthlySettings !== null) {
                    $out_early_minutes = calculateOutMinutes($auto_out_time, $openRecord['auth_date'], $monthlySettings);
                }
                
                $total_hours = 0;
                if ($monthlySettings !== null && !empty($openRecord['in_datetime'])) {
                    $total_hours = calculateWorkHours($openRecord['in_datetime'], $auto_out_datetime, $openRecord['auth_date'], $monthlySettings);
                }
                
                $stmt = $pdo->prepare("
                    UPDATE attendance_data 
                    SET 
                        out_time = ?,
                        out_datetime = ?,
                        out_device = ?,
                        out_early_minutes = ?,
                        total_work_hours = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $auto_out_time,
                    $auto_out_datetime,
                    'auto_close_early',
                    $out_early_minutes,
                    $total_hours,
                    $openRecord['id']
                ]);
                
                logSuccess("✅ Ochiq IN avtomatik yopildi: ID={$openRecord['id']}, OUT={$auto_out_time}");
                
            } else {
                $auto_out_datetime = $openRecord['auth_date'] . ' 23:59:00';
                $auto_out_time = '23:59:00';
                
                logInfo("🔄 Ish vaqti sozlamasi yo'q, 23:59 da yopiladi");
                
                $stmt = $pdo->prepare("
                    UPDATE attendance_data 
                    SET 
                        out_time = ?,
                        out_datetime = ?,
                        out_device = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $auto_out_time,
                    $auto_out_datetime,
                    'auto_close_midnight',
                    $openRecord['id']
                ]);
                
                logSuccess("✅ Ochiq IN 23:59 da yopildi: ID={$openRecord['id']}");
            }
        }
        
        // ============================================================
        // 4. MAVJUD YOZUVNI TEKSHIRISH
        // ============================================================
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                in_time, in_datetime, in_device, in_late_minutes,
                out_time, out_datetime, out_device, out_early_minutes,
                total_work_hours
            FROM attendance_data 
            WHERE employee_id = ? 
                AND account_name = ?
                AND auth_date = ?
            LIMIT 1
        ");
        $stmt->execute([$data['employee_id'], $account_name, $data['auth_date']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // ============================================================
        // 5. MAVJUD YOZUVNI YANGILASH
        // ============================================================
        if ($existing) {
            logInfo("📝 Mavjud yozuv topildi: ID={$existing['id']}, date={$data['auth_date']}");
            
            $updates = [];
            $params = [];
            $hasUpdate = false;
            
            // IN yangilash
            if (!empty($data['in_time'])) {
                $shouldUpdateIn = ($existing['in_time'] === null);
                if (!$shouldUpdateIn && !empty($existing['in_datetime']) && !empty($data['in_datetime'])) {
                    $shouldUpdateIn = strtotime($data['in_datetime']) < strtotime($existing['in_datetime']);
                }
                
                if ($shouldUpdateIn) {
                    $in_late_minutes = 0;
                    if ($monthlySettings !== null) {
                        $in_late_minutes = calculateInMinutes($data['in_time'], $monthlySettings);
                    }
                    
                    $updates[] = "in_time = ?";
                    $params[] = $data['in_time'];
                    $updates[] = "in_datetime = ?";
                    $params[] = $data['in_datetime'];
                    $updates[] = "in_device = ?";
                    $params[] = $data['in_device'] ?? null;
                    $updates[] = "in_late_minutes = ?";
                    $params[] = $in_late_minutes;
                    $hasUpdate = true;
                    
                    logInfo("🔄 IN yangilandi: {$data['in_time']}");
                }
            }
            
            // OUT yangilash
            if (!empty($data['out_time'])) {
                $out_time = $data['out_time'];
                $out_datetime = $data['out_datetime'];
                
                $out_hour = (int)date('H', strtotime($out_time));
                if ($out_hour >= 0 && $out_hour < 6) {
                    $out_date = date('Y-m-d', strtotime($out_datetime) - 86400);
                    $out_time = '23:59:00';
                    $out_datetime = $out_date . ' 23:59:00';
                    logInfo("🔄 OUT 00:00 dan o'tib ketdi, 23:59 ga o'zgartirildi");
                }
                
                $shouldUpdateOut = ($existing['out_time'] === null);
                if (!$shouldUpdateOut) {
                    if (!empty($existing['out_datetime']) && !empty($out_datetime)) {
                        $shouldUpdateOut = strtotime($out_datetime) > strtotime($existing['out_datetime']);
                    } else {
                        $shouldUpdateOut = $out_time > $existing['out_time'];
                    }
                }
                
                if ($shouldUpdateOut) {
                    $out_early_minutes = 0;
                    $total_hours = 0;
                    
                    if ($monthlySettings !== null) {
                        $out_early_minutes = calculateOutMinutes($out_time, $data['auth_date'], $monthlySettings);
                        
                        $in_datetime = !empty($updates) && !empty($data['in_datetime']) ? $data['in_datetime'] : $existing['in_datetime'];
                        if (!empty($in_datetime)) {
                            $total_hours = calculateWorkHours($in_datetime, $out_datetime, $data['auth_date'], $monthlySettings);
                        }
                    }
                    
                    $updates[] = "out_time = ?";
                    $params[] = $out_time;
                    $updates[] = "out_datetime = ?";
                    $params[] = $out_datetime;
                    $updates[] = "out_device = ?";
                    $params[] = $data['out_device'] ?? null;
                    $updates[] = "out_early_minutes = ?";
                    $params[] = $out_early_minutes;
                    $updates[] = "total_work_hours = ?";
                    $params[] = $total_hours;
                    $hasUpdate = true;
                    
                    logInfo("🔄 OUT yangilandi: {$out_time}");
                }
            }
            
            if (!$hasUpdate) {
                logInfo("ℹ️ Yangilash uchun ma'lumot yo'q");
                return true;
            }
            
            $updates[] = "updated_at = NOW()";
            $sql = "UPDATE attendance_data SET " . implode(", ", $updates) . " WHERE id = ?";
            $params[] = $existing['id'];
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            logSuccess("✅ Yozuv yangilandi: ID={$existing['id']}");
            return true;
        }
        
        // ============================================================
        // 6. YANGI YOZUV YARATISH
        // ============================================================
        logInfo("🆕 Yangi yozuv yaratilmoqda: employee={$data['employee_id']}, date={$data['auth_date']}, smena={$shiftTypeText}");
        
        // OUT 00:00-05:59 bo'lsa -> 23:59 ga o'zgartiramiz
        if (!empty($data['out_time'])) {
            $out_hour = (int)date('H', strtotime($data['out_time']));
            if ($out_hour >= 0 && $out_hour < 6) {
                $out_date = date('Y-m-d', strtotime($data['out_datetime']) - 86400);
                $data['out_time'] = '23:59:00';
                $data['out_datetime'] = $out_date . ' 23:59:00';
                logInfo("🔄 OUT 00:00 dan o'tib ketdi, 23:59 ga o'zgartirildi");
            }
        }
        
        $in_late_minutes = 0;
        $in_status = '';
        if (!empty($data['in_time']) && $monthlySettings !== null) {
            $in_late_minutes = calculateInMinutes($data['in_time'], $monthlySettings);
            
            if ($in_late_minutes > 0) {
                $in_status = "kechikish: {$in_late_minutes} min";
            } elseif ($in_late_minutes < 0) {
                $in_status = "erta kelish: " . abs($in_late_minutes) . " min";
            } else {
                $in_status = "vaqtida";
            }
        }
        
        $out_early_minutes = 0;
        $total_hours = 0;
        $out_status = '';
        
        if (!empty($data['out_time']) && $monthlySettings !== null) {
            $out_early_minutes = calculateOutMinutes($data['out_time'], $data['auth_date'], $monthlySettings);
            
            if ($out_early_minutes > 0) {
                $out_status = "kech ketish: {$out_early_minutes} min (BONUS)";
            } elseif ($out_early_minutes < 0) {
                $out_status = "erta ketish: " . abs($out_early_minutes) . " min (JARIMA)";
            } else {
                $out_status = "vaqtida";
            }
            
            if (!empty($data['in_datetime'])) {
                $total_hours = calculateWorkHours($data['in_datetime'], $data['out_datetime'], $data['auth_date'], $monthlySettings);
            }
        } else {
            $out_status = "kutilmoqda";
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO attendance_data (
                employee_id, account_name, auth_date,
                in_time, in_datetime, in_device, in_late_minutes,
                out_time, out_datetime, out_device, out_early_minutes,
                total_work_hours,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?,
                NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            $data['employee_id'],
            $account_name,
            $data['auth_date'],
            $data['in_time'] ?? null,
            $data['in_datetime'] ?? null,
            $data['in_device'] ?? null,
            $in_late_minutes,
            $data['out_time'] ?? null,
            $data['out_datetime'] ?? null,
            $data['out_device'] ?? null,
            $out_early_minutes,
            $total_hours
        ]);
        
        $new_id = $pdo->lastInsertId();
        
        $status_msg = "IN: {$in_status}";
        if (!empty($data['out_time'])) {
            $status_msg .= ", OUT: {$out_status} ({$data['out_time']})";
        } else {
            $status_msg .= ", OUT: kutilmoqda";
        }
        
        logSuccess("➕ Yangi yozuv qo'shildi: ID={$new_id}, {$status_msg}, smena={$shiftTypeText}");
        return true;
        
    } catch (Exception $e) {
        logError("❌ Yozuvni saqlash xatosi: " . $e->getMessage());
        logError("❌ Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

// ============================================================
// 7. ASOSIY SINXRONIZATSIYA FUNKSIYASI
// ============================================================
function syncAttendanceData() {
    global $syncLogFile, $fullLogFile;
    
    $host = getenv('IVMS_HOST');
    $username = getenv('IVMS_USER');
    $password = getenv('IVMS_PASS');
    $salaryHost = getenv('DB_HOST');
    $salaryUsername = getenv('DB_USER');
    $salaryPassword = getenv('DB_PASS');
    $salaryDatabase = getenv('DB_NAME');
    
    $startTime = microtime(true);
    $stats = [
        'read' => 0,
        'grouped' => 0,
        'inserted' => 0,
        'updated' => 0,
        'errors' => 0,
        'synced' => 0,
        'skipped' => 0
    ];
    
    try {
        $pdo_ivms = new PDO(
            "mysql:host=$host;dbname=ivms;charset=utf8mb4",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $pdo_salary = new PDO(
            "mysql:host=$salaryHost;dbname=$salaryDatabase;charset=utf8mb4",
            $salaryUsername,
            $salaryPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        logInfo("🔄 SYNC STARTED");
        
        // IVMS da synced ustuni
        try {
            $check = $pdo_ivms->query("SHOW COLUMNS FROM attendance_data LIKE 'synced'");
            if ($check->rowCount() == 0) {
                $pdo_ivms->exec("ALTER TABLE attendance_data ADD COLUMN synced TINYINT(1) DEFAULT 0");
                $pdo_ivms->exec("ALTER TABLE attendance_data ADD COLUMN synced_at DATETIME NULL");
                logSuccess("✅ IVMS ga synced ustunlari qo'shildi");
            }
        } catch (Exception $e) {
            logError("⚠️ synced ustunlarini tekshirish xatosi: " . $e->getMessage());
        }
        
        // IVMS dan ma'lumotlarni olish
        $query = "
            SELECT 
                id, employee_id, employee_name,
                auth_date, auth_datetime, auth_time,
                direction, device_name, davomat_holati
            FROM attendance_data
            WHERE (synced = 0 OR synced IS NULL)
              AND auth_date >= CURDATE() - INTERVAL 30 DAY
              AND employee_id IS NOT NULL
              AND employee_id != '' AND employee_id != '0'
            ORDER BY employee_id, auth_date, auth_time ASC
            LIMIT 5000
        ";
        
        $records = $pdo_ivms->query($query)->fetchAll(PDO::FETCH_ASSOC);
        $stats['read'] = count($records);
        
        if (empty($records)) {
            logSuccess("ℹ️ Yangi ma'lumotlar yo'q");
            return [
                'status' => 'success',
                'message' => 'IVMS sinxronizatsiyasi yakunlandi',
                'stats' => $stats,
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
        
        logInfo("📊 " . $stats['read'] . " ta yozuv o'qildi");
        
        // ============================================================
        // IN va OUT LARNI GURUHLASH
        // ============================================================
        $grouped = [];
        $byEmployee = [];
        
        foreach ($records as $r) {
            $byEmployee[$r['employee_id']][] = $r;
        }
        
        foreach ($byEmployee as $employee_id => $empRecords) {
            $dailyGroups = [];
            
            $monthlySettings = getMonthlySettings($pdo_salary, $employee_id);
            $work_start = $monthlySettings['ishga_kelish_vaqti'] ?? '09:00:00';
            $work_end = $monthlySettings['ishdan_ketish_vaqti'] ?? '18:00:00';
            $shiftType = getShiftType($work_start, $work_end);
            
            foreach ($empRecords as $r) {
                $ivms_direction = strtolower(trim($r['direction'] ?? ''));
                $auth_date = $r['auth_date'];
                $auth_time = $r['auth_time'];
                $auth_datetime = $r['auth_datetime'];
                $auth_timestamp = strtotime($auth_datetime);
                
                if (strpos($ivms_direction, 'access') !== false || 
                    strpos($ivms_direction, 'controller') !== false) {
                    $dir = getDirectionBySchedule($auth_time, $employee_id, $pdo_salary);
                    logInfo("🔄 Access Controller ({$auth_time}) -> {$dir} deb belgilandi");
                } else {
                    $dir = $ivms_direction;
                }
                
                $group_date = $auth_date;
                
                // OUT ertalab bo'lsa -> oldingi kun
                if ($dir == 'out') {
                    $hour = (int)date('H', $auth_timestamp);
                    if ($hour >= 0 && $hour < 12) {
                        $group_date = date('Y-m-d', strtotime($auth_date . ' -1 day'));
                        logInfo("🌅 ERTALABGI OUT: {$auth_datetime} -> {$group_date} kunga tegishli");
                    }
                }
                
                $key = $employee_id . '|' . $group_date;
                
                if (!isset($dailyGroups[$key])) {
                    $dailyGroups[$key] = [
                        'employee_id' => $employee_id,
                        'employee_name' => $r['employee_name'] ?? null,
                        'auth_date' => $group_date,
                        'in_time' => null,
                        'in_datetime' => null,
                        'in_timestamp' => null,
                        'in_device' => null,
                        'out_time' => null,
                        'out_datetime' => null,
                        'out_timestamp' => null,
                        'out_device' => null,
                        'ids' => [],
                        'shift_type' => $shiftType
                    ];
                }
                
                if ($dir == 'in') {
                    $current_in_ts = strtotime($auth_datetime);
                    if ($dailyGroups[$key]['in_timestamp'] === null || 
                        $current_in_ts < $dailyGroups[$key]['in_timestamp']) {
                        $dailyGroups[$key]['in_time'] = $auth_time;
                        $dailyGroups[$key]['in_datetime'] = $auth_datetime;
                        $dailyGroups[$key]['in_timestamp'] = $current_in_ts;
                        $dailyGroups[$key]['in_device'] = $r['device_name'] ?? null;
                    }
                    $dailyGroups[$key]['ids'][] = $r['id'];
                    
                } elseif ($dir == 'out') {
                    $current_out_ts = strtotime($auth_datetime);
                    
                    // Ertalabgi OUT ga +1 kun qo'shib solishtiramiz
                    $hour = (int)date('H', $current_out_ts);
                    if ($hour >= 0 && $hour < 12) {
                        $current_out_ts = strtotime('+1 day', $current_out_ts);
                    }
                    
                    if ($dailyGroups[$key]['out_timestamp'] === null || 
                        $current_out_ts > $dailyGroups[$key]['out_timestamp']) {
                        $dailyGroups[$key]['out_time'] = $auth_time;
                        $dailyGroups[$key]['out_datetime'] = $auth_datetime;
                        $dailyGroups[$key]['out_timestamp'] = $current_out_ts;
                        $dailyGroups[$key]['out_device'] = $r['device_name'] ?? null;
                    }
                    $dailyGroups[$key]['ids'][] = $r['id'];
                }
            }
            
            foreach ($dailyGroups as $key => $data) {
                if ($data['in_time'] !== null || $data['out_time'] !== null) {
                    $grouped[$key] = $data;
                }
            }
        }
        
        $stats['grouped'] = count($grouped);
        logInfo("📊 " . $stats['grouped'] . " ta kunlik yozuvga birlashtirildi");
        
        $all_ids = [];
        
        foreach ($grouped as $key => $data) {
            try {
                $employee = getEmployeeSettings($pdo_salary, $data['employee_id']);
                
                if (!$employee) {
                    logInfo("⏭️ Xodim topilmadi: employee_id={$data['employee_id']}");
                    $stats['skipped']++;
                    continue;
                }
                
                $account_name = $employee['account_name'] ?? null;
                
                if (empty($account_name)) {
                    logInfo("⏭️ Account name yo'q: employee_id={$data['employee_id']}");
                    $stats['skipped']++;
                    continue;
                }
                
                $saveData = [
                    'employee_id' => $data['employee_id'],
                    'auth_date' => $data['auth_date'],
                    'in_time' => $data['in_time'],
                    'in_datetime' => $data['in_datetime'],
                    'in_device' => $data['in_device'],
                    'out_time' => $data['out_time'],
                    'out_datetime' => $data['out_datetime'],
                    'out_device' => $data['out_device']
                ];
                
                $log_msg = "employee={$data['employee_id']}, date={$data['auth_date']}";
                if ($data['in_time']) {
                    $log_msg .= ", IN={$data['in_time']} (eng erta)";
                }
                if ($data['out_time']) {
                    $log_msg .= ", OUT={$data['out_time']} (eng kech)";
                }
                logInfo(" " . $log_msg);
                
                $success = saveOrUpdateAttendance($pdo_salary, $saveData, $account_name);
                
                if ($success) {
                    $stats['inserted']++;
                    foreach ($data['ids'] as $id) {
                        $all_ids[] = $id;
                    }
                } else {
                    $stats['errors']++;
                }
                
            } catch (Exception $e) {
                $stats['errors']++;
                logError("❌ Xatolik (employee: {$data['employee_id']}): " . $e->getMessage());
            }
        }
        
        // IVMS da synced deb belgilash
        if (!empty($all_ids)) {
            $all_ids = array_unique(array_filter($all_ids));
            $updated_sync = 0;
            
            $chunks = array_chunk($all_ids, 100);
            foreach ($chunks as $chunk) {
                try {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $stmt = $pdo_ivms->prepare("
                        UPDATE attendance_data 
                        SET synced = 1, synced_at = NOW() 
                        WHERE id IN ($placeholders)
                    ");
                    $stmt->execute($chunk);
                    $updated_sync += count($chunk);
                } catch (Exception $e) {
                    logError("⚠️ Batch sync xatosi: " . $e->getMessage());
                    foreach ($chunk as $id) {
                        try {
                            $stmt = $pdo_ivms->prepare("
                                UPDATE attendance_data 
                                SET synced = 1, synced_at = NOW() 
                                WHERE id = ?
                            ");
                            $stmt->execute([$id]);
                            $updated_sync++;
                        } catch (Exception $e2) {
                            logError("❌ ID $id synced qilishda xatolik: " . $e2->getMessage());
                        }
                    }
                }
            }
            
            $stats['synced'] = $updated_sync;
            logSuccess("✅ $updated_sync ta IVMS yozuvi synced deb belgilandi");
        }

        $executionTime = round(microtime(true) - $startTime, 2);
        logSuccess("✅ Sync tugadi: " . $stats['inserted'] . " ta yozuv, " . $executionTime . " sek");
        
        return [
            'status' => 'success',
            'message' => 'Sync completed successfully',
            'stats' => $stats,
            'execution_time' => $executionTime,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        $executionTime = round(microtime(true) - $startTime, 2);
        logError("❌ Sync xatosi: " . $e->getMessage());
        
        return [
            'status' => 'error',
            'message' => $e->getMessage(),
            'execution_time' => $executionTime,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

// ============================================================
// 8. ASOSIY SINXRONIZATSIYA
// ============================================================
function runFullSync() {
    global $fullLogFile;
    
    writeLogFile($fullLogFile, "🔄 FULL SYNC STARTED");
    $result = syncAttendanceData();
    writeLogFile($fullLogFile, "📊 Result: " . ($result['status'] ?? 'unknown'));
    writeLogFile($fullLogFile, "✅ FULL SYNC COMPLETED");
    
    return $result;
}

// ============================================================
// 9. AVTOMATIK ISHGA TUSHIRISH
// ============================================================
if (basename($_SERVER['PHP_SELF']) === 'sync_functions.php') {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
    }
    
    try {
        $result = runFullSync();
        echo json_encode($result, JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_PRETTY_PRINT);
    }
    exit;
}
