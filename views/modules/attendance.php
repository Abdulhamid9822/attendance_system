<?php
// modules/attendance.php - PIVOT FORMAT UCHUN (TO'LIQ TUZATILGAN)

// ============================================================
// 1. SID NI OLISH - ENG BOSHIDA
// ============================================================
$sid = isset($_GET['sid']) ? $_GET['sid'] : (isset($_POST['sid']) ? $_POST['sid'] : '');
if(empty($sid) && isset($_SESSION['sid'])) {
    $sid = $_SESSION['sid'];
}
if(!empty($sid)) {
    $_SESSION['sid'] = $sid;
}

// ============================================================
// 2. HEADER() LAR - FORM SUBMITLARDAN OLDIN
// ============================================================
require_once __DIR__ . '/../../includes/language.php';
require_once __DIR__ . '/../../includes/functions/employee_filter_bar.php';
global $pdo;

$account_id = $_SESSION['account_id'] ?? null;
$account_name = $_SESSION['account'] ?? $_SESSION['account_name'] ?? null;
$attBranchSpot = (($_SESSION['auth_type'] ?? '') === 'poster_branch')
    ? max(0, (int)($_SESSION['branch_spot_id'] ?? 0)) : 0;
if (($_SESSION['auth_type'] ?? '') === 'poster_branch' && $attBranchSpot <= 0) {
    $attBranchSpot = -1; // filial aniqlanmagan: hech narsa ko'rsatilmaydi
}
function attendanceEmployeeAllowed($pdo, $employeeId, $accountName, $branchSpot) {
    if ($branchSpot === 0) {
        return true;
    }
    $s = $pdo->prepare("SELECT 1 FROM xodimlar WHERE id = ? AND account_name = ? AND branch_spot_id = ? LIMIT 1");
    $s->execute([(int)$employeeId, $accountName, $branchSpot]);
    return (bool)$s->fetchColumn();
}
$dateFrom = $_GET['start_date'] ?? date('Y-m-01');
$dateTo = $_GET['end_date'] ?? date('Y-m-d');
if (!is_string($dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-01');
}
if (!is_string($dateTo) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-d');
}

// Redirect uchun SID qo'shish
function getRedirectUrl($tab, $dateFrom, $dateTo, $sid, $params = []) {
    $url = "?tab=" . urlencode($tab) . "&start_date=" . urlencode($dateFrom) . "&end_date=" . urlencode($dateTo);
    if (!empty($sid)) {
        $url .= "&sid=" . urlencode($sid);
    }
    foreach ($params as $key => $value) {
        $url .= "&" . urlencode($key) . "=" . urlencode($value);
    }
    return $url;
}

// ============================================================
// 3. FORM SUBMITLAR
// ============================================================
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$attendanceCsrfToken = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!is_string($postedCsrf) || !hash_equals($attendanceCsrfToken, $postedCsrf)) {
        http_response_code(403);
        $_POST = [];
        $error = t('csrf_invalid_refresh');
    }
    
    // Yangilash
    if(isset($_POST['update_attendance'])) {
        $id = (int)$_POST['record_id'];
        $auth_datetime = $_POST['auth_datetime'];
        $direction = $_POST['direction'];
        $auth_date = date('Y-m-d', strtotime($auth_datetime));
        $auth_time = date('H:i:s', strtotime($auth_datetime));
        
        try {
            $stmt = $pdo->prepare("SELECT employee_id, auth_date, account_name FROM attendance_data WHERE id = ? AND account_name = ?");
            $stmt->execute([$id, $account_name]);
            $old = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($old && !attendanceEmployeeAllowed($pdo, $old['employee_id'], $account_name, $attBranchSpot)) {
                $old = false;
            }
            if ($old) {
                if ($direction == 'IN') {
                    $stmt = $pdo->prepare("
                        UPDATE attendance_data 
                        SET in_datetime = ?, in_time = ?, updated_at = NOW()
                        WHERE id = ? AND account_name = ?
                    ");
                    $stmt->execute([$auth_datetime, $auth_time, $id, $account_name]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE attendance_data 
                        SET out_datetime = ?, out_time = ?, updated_at = NOW()
                        WHERE id = ? AND account_name = ?
                    ");
                    $stmt->execute([$auth_datetime, $auth_time, $id, $account_name]);
                }
                
                recalculateAttendance($pdo, $old['employee_id'], $old['auth_date'], $old['account_name']);
            }
            
            header("Location: " . getRedirectUrl('attendance', $dateFrom, $dateTo, $sid, ['success' => 1]));
            exit;
        } catch (Exception $e) {
            $error = "Xatolik: " . $e->getMessage();
        }
    }
    
    // Qo'shish
    if(isset($_POST['add_attendance'])) {
        $employee_id = (int)$_POST['employee_id'];
        $auth_datetime = $_POST['auth_datetime'];
        $direction = $_POST['direction'];
        $auth_date = date('Y-m-d', strtotime($auth_datetime));
        $auth_time = date('H:i:s', strtotime($auth_datetime));
        
        try {
            $ownStmt = $pdo->prepare("SELECT id FROM xodimlar WHERE id = ? AND account_name = ? LIMIT 1");
            $ownStmt->execute([$employee_id, $account_name]);
            if (!$ownStmt->fetchColumn() || !attendanceEmployeeAllowed($pdo, $employee_id, $account_name, $attBranchSpot)) {
                throw new RuntimeException('Xodim topilmadi yoki ruxsat yo‘q.');
            }
            $stmt = $pdo->prepare("
                SELECT id FROM attendance_data 
                WHERE employee_id = ? AND auth_date = ? AND account_name = ?
            ");
            $stmt->execute([$employee_id, $auth_date, $account_name]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                if ($direction == 'IN') {
                    $stmt = $pdo->prepare("
                        UPDATE attendance_data 
                        SET in_datetime = ?, in_time = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$auth_datetime, $auth_time, $existing['id']]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE attendance_data 
                        SET out_datetime = ?, out_time = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$auth_datetime, $auth_time, $existing['id']]);
                }
            } else {
                if ($direction == 'IN') {
                    $stmt = $pdo->prepare("
                        INSERT INTO attendance_data (
                            employee_id, account_name, auth_date,
                            in_datetime, in_time
                        ) VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$employee_id, $account_name, $auth_date, $auth_datetime, $auth_time]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO attendance_data (
                            employee_id, account_name, auth_date,
                            out_datetime, out_time
                        ) VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$employee_id, $account_name, $auth_date, $auth_datetime, $auth_time]);
                }
            }
            
            recalculateAttendance($pdo, $employee_id, $auth_date, $account_name);
            
            header("Location: " . getRedirectUrl('attendance', $dateFrom, $dateTo, $sid, ['success' => 1]));
            exit;
        } catch (Exception $e) {
            $error = "Xatolik: " . $e->getMessage();
        }
    }
    
    // O'chirish
    if(isset($_POST['delete_attendance'])) {
        $id = (int)$_POST['record_id'];
        
        try {
            $stmt = $pdo->prepare("SELECT employee_id, auth_date, account_name FROM attendance_data WHERE id = ? AND account_name = ?");
            $stmt->execute([$id, $account_name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !attendanceEmployeeAllowed($pdo, $row['employee_id'], $account_name, $attBranchSpot)) {
                $row = false;
            }
            
            if ($row) {
                $stmt = $pdo->prepare("DELETE FROM attendance_data WHERE id = ? AND account_name = ?");
                $stmt->execute([$id, $account_name]);
                
                recalculateAttendance($pdo, $row['employee_id'], $row['auth_date'], $row['account_name']);
            }
            
            header("Location: " . getRedirectUrl('attendance', $dateFrom, $dateTo, $sid, ['success' => 1]));
            exit;
        } catch (Exception $e) {
            $error = "Xatolik: " . $e->getMessage();
        }
    }
}

// ============================================================
// 4. FUNKSIYALAR - GRACE MINUTLAR BILAN
// ============================================================

function getEmployeeSettingsForDate($pdo, $employee_id, $auth_date, $account_name) {
    try {
        $oy_vaqt = date('Y-m-01', strtotime($auth_date));
        
        $stmt = $pdo->prepare("
            SELECT * FROM oylik_sozlamalar 
            WHERE xodim_id = ? 
            AND oy_vaqt = ? 
            AND amal_qiladi <= ?
            ORDER BY amal_qiladi DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$employee_id, $oy_vaqt, $auth_date]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$settings) {
            $stmt = $pdo->prepare("
                SELECT * FROM oylik_sozlamalar
                WHERE xodim_id = ? AND amal_qiladi <= ?
                ORDER BY oy_vaqt DESC, amal_qiladi DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$employee_id, $auth_date]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $scheduleOverride = getEmployeeWorkScheduleOverride($pdo, $employee_id, $auth_date);
        if (!$settings && $scheduleOverride) {
            $settings = [
                'kech_kelish_grace_minutes' => 10,
                'erta_ketish_grace_minutes' => 10,
            ];
        }
        if (
            $settings
            && $scheduleOverride
            && $scheduleOverride['amal_qiladi'] >= ($settings['amal_qiladi'] ?? '')
        ) {
            foreach (['ishga_kelish_vaqti', 'ishdan_ketish_vaqti', 'tushlik_boshlanish', 'tushlik_tugash'] as $field) {
                $settings[$field] = $scheduleOverride[$field];
            }
        }
        
        return $settings;
        
    } catch (Exception $e) {
        error_log("getEmployeeSettingsForDate xatosi: " . $e->getMessage());
        return null;
    }
}

function recalculateAttendance($pdo, $employee_id, $auth_date, $account_name) {
    try {
        // IN va OUT vaqtlarini olish
        $stmt = $pdo->prepare("
            SELECT in_time, out_time, in_datetime, out_datetime 
            FROM attendance_data 
            WHERE employee_id = ? AND auth_date = ? AND account_name = ?
        ");
        $stmt->execute([$employee_id, $auth_date, $account_name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return;
        }
        
        // Sozlamalarni olish
        $settings = getEmployeeSettingsForDate($pdo, $employee_id, $auth_date, $account_name);
        
        $in_late_minutes = 0;
        $out_early_minutes = 0;
        $total_work_hours = 0;
        
        // ============================================================
        // 1. KECH KELISH (in_late_minutes) - GRACE MINUTLAR BILAN
        // ============================================================
        if (!empty($row['in_time']) && $settings !== null) {
            $work_start = $settings['ishga_kelish_vaqti'] ?? null;
            $grace_minutes = isset($settings['kech_kelish_grace_minutes']) ? (int)$settings['kech_kelish_grace_minutes'] : null;
            
            if ($work_start !== null && $grace_minutes !== null) {
                $in_time_ts = strtotime($row['in_time']);
                $work_start_ts = strtotime($work_start);
                
                if ($in_time_ts > $work_start_ts) {
                    // Kech qolgan daqiqalar
                    $late_minutes = floor(($in_time_ts - $work_start_ts) / 60);
                    // Grace minutlarni ayirish, faqat musbat qiymat
                    $in_late_minutes = max(0, $late_minutes - $grace_minutes);
                } else {
                    $in_late_minutes = 0;
                }
            }
        }
        
        // ============================================================
        // 2. ERTA KETISH (out_early_minutes) - GRACE MINUTLAR BILAN
        // ============================================================
        if (!empty($row['out_time']) && $settings !== null) {
            $work_end = $settings['ishdan_ketish_vaqti'] ?? null;
            $grace_minutes = isset($settings['erta_ketish_grace_minutes']) ? (int)$settings['erta_ketish_grace_minutes'] : null;
            
            if ($work_end !== null && $grace_minutes !== null) {
                $out_time_ts = strtotime($row['out_time']);
                $work_end_ts = strtotime($work_end);
                
                if ($out_time_ts < $work_end_ts) {
                    // Erta ketgan daqiqalar
                    $early_minutes = floor(($work_end_ts - $out_time_ts) / 60);
                    // Grace minutlarni ayirish, faqat musbat qiymat
                    $effective_early = max(0, $early_minutes - $grace_minutes);
                    // Manfiy qiymat - erta ketishni bildiradi
                    $out_early_minutes = -$effective_early;
                } elseif ($out_time_ts > $work_end_ts) {
                    // Kech ketish - bonus uchun ijobiy qiymat
                    $late_minutes = floor(($out_time_ts - $work_end_ts) / 60);
                    $out_early_minutes = $late_minutes;
                } else {
                    $out_early_minutes = 0;
                }
            }
        }
        
        // ============================================================
        // 3. ISH VAQTI (total_work_hours)
        // ============================================================
        if (!empty($row['in_datetime']) && !empty($row['out_datetime']) && $settings !== null) {
            $in_ts = strtotime($row['in_datetime']);
            $out_ts = strtotime($row['out_datetime']);
            
            if ($out_ts > $in_ts) {
                $total_seconds = $out_ts - $in_ts;
                
                // Tushlik vaqtini ayirish
                $lunch_start = $settings['tushlik_boshlanish'] ?? null;
                $lunch_end = $settings['tushlik_tugash'] ?? null;
                
                if ($lunch_start !== null && $lunch_end !== null) {
                    $lunch_start_ts = strtotime($auth_date . ' ' . $lunch_start);
                    $lunch_end_ts = strtotime($auth_date . ' ' . $lunch_end);
                    
                    // Agar tushlik vaqti ish vaqtiga to'g'ri kelsa
                    if ($in_ts <= $lunch_start_ts && $out_ts >= $lunch_end_ts) {
                        $total_seconds -= ($lunch_end_ts - $lunch_start_ts);
                    }
                }
                
                $total_work_hours = round(max(0, $total_seconds) / 3600, 2);
            }
        }
        
        // ============================================================
        // 4. BAZAGA YOZISH
        // ============================================================
        $stmt = $pdo->prepare("
            UPDATE attendance_data 
            SET in_late_minutes = ?, 
                out_early_minutes = ?,
                total_work_hours = ?,
                updated_at = NOW()
            WHERE employee_id = ? AND auth_date = ? AND account_name = ?
        ");
        $stmt->execute([
            $in_late_minutes,
            $out_early_minutes,
            $total_work_hours,
            $employee_id,
            $auth_date,
            $account_name
        ]);
        
    } catch (Exception $e) {
        error_log("recalculateAttendance xatosi: " . $e->getMessage());
    }
}

// ============================================================
// 5. QIDIRISH PARAMETRLARI
// ============================================================
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$itemsPerPage = 30;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if($currentPage < 1) $currentPage = 1;

// ============================================================
// 6. XODIMLARNI OLISH
// ============================================================
$employees = [];
$totalEmployees = 0;
$totalPages = 1;

if (empty($account_name) || empty($account_id)) {
    $employees = [];
    $totalEmployees = 0;
    $totalPages = 1;
} else {
    try {
        $sql = "SELECT id, ismi FROM xodimlar 
                WHERE holati = 'active' AND account_id = ? AND account_name = ?";
        $params = [$account_id, $account_name];
        if ($attBranchSpot !== 0) {
            $sql .= " AND branch_spot_id = ?";
            $params[] = $attBranchSpot;
        }
        [$filterSql, $filterParams] = employeeFilterSql();
        $sql .= $filterSql;
        $params = array_merge($params, $filterParams);

        if(!empty($searchQuery)) {
            $searchTerm = '%' . $searchQuery . '%';
            $sql .= " AND ismi LIKE ?";
            $params[] = $searchTerm;
        }

        $countSql = "SELECT COUNT(*) FROM (" . $sql . ") as subquery";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalEmployees = (int)$countStmt->fetchColumn();
        $totalPages = ($totalEmployees > 0) ? ceil($totalEmployees / $itemsPerPage) : 1;
        $offset = ($currentPage - 1) * $itemsPerPage;

        $sql .= " ORDER BY ismi LIMIT ? OFFSET ?";
        $params[] = $itemsPerPage;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch(Exception $e) {
        error_log("Xodimlarni olish xatosi: " . $e->getMessage());
        $employees = [];
        $totalEmployees = 0;
        $totalPages = 1;
    }
}

// ============================================================
// 7. ATTENDANCE MA'LUMOTLARI
// ============================================================
$start = new DateTime($dateFrom);
$end = new DateTime($dateTo);
$days = (clone $start)->diff($end)->days + 1;
$today = date('Y-m-d');
$daysInMonth = (int)date('t', strtotime($dateFrom));

$attendance = [];

if(!empty($employees)) {
    $employeeIds = array_column($employees, 'id');
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                employee_id,
                auth_date,
                in_time,
                in_datetime,
                in_device,
                in_late_minutes,
                out_time,
                out_datetime,
                out_device,
                out_early_minutes,
                total_work_hours
            FROM attendance_data 
            WHERE employee_id IN ($placeholders) 
            AND auth_date BETWEEN ? AND ?
            AND account_name = ?
            ORDER BY employee_id, auth_date ASC
        ");
        
        $params = array_merge($employeeIds, [$dateFrom, $dateTo, $account_name]);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $empId = $row['employee_id'];
            $sana = $row['auth_date'];
            
            if (!isset($attendance[$empId][$sana])) {
                $attendance[$empId][$sana] = [
                    'id' => $row['id'],
                    'in_time' => $row['in_time'],
                    'in_datetime' => $row['in_datetime'],
                    'in_device' => $row['in_device'],
                    'in_late_minutes' => $row['in_late_minutes'],
                    'out_time' => $row['out_time'],
                    'out_datetime' => $row['out_datetime'],
                    'out_device' => $row['out_device'],
                    'out_early_minutes' => $row['out_early_minutes'],
                    'total_work_hours' => $row['total_work_hours'],
                    'records' => []
                ];
            }
            
            if (!empty($row['in_time'])) {
                $attendance[$empId][$sana]['records'][] = [
                    'id' => $row['id'],
                    'time' => $row['in_time'],
                    'full_datetime' => $row['in_datetime'],
                    'direction' => 'IN',
                    'status' => ($row['in_late_minutes'] ?? 0) > 0 ? 'late' : 'present'
                ];
            }
            if (!empty($row['out_time'])) {
                $attendance[$empId][$sana]['records'][] = [
                    'id' => $row['id'],
                    'time' => $row['out_time'],
                    'full_datetime' => $row['out_datetime'],
                    'direction' => 'OUT',
                    'status' => 'present'
                ];
            }
        }
    } catch(Exception $e) {
        error_log("Attendance ma'lumotlarini olish xatosi: " . $e->getMessage());
    }
}

// ============================================================
// 8. MA'LUMOTLARNI TAYYORLASH
// ============================================================
$employeeData = [];

foreach($employees as $emp) {
    $empId = $emp['id'];
    $workedDays = 0;
    $daily = [];
    
    $current = clone $start;
    for($i = 0; $i < $days; $i++) {
        $date = $current->format('Y-m-d');
        if(isset($attendance[$empId][$date])) {
            $workedDays++;
            $daily[$date] = $attendance[$empId][$date];
        } else {
            $daily[$date] = null;
        }
        $current->modify('+1 day');
    }
    
    $employeeData[$empId] = [
        'name' => $emp['ismi'],
        'worked' => $workedDays,
        'daily' => $daily
    ];
}

$success = isset($_GET['success']) ? 1 : 0;

// Excel export
if(isset($_GET['export_excel']) && !empty($employees)) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="attendance_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<table border="1">';
    echo '<tr><th>Employee</th>';
    
    $current = clone $start;
    for ($i = 0; $i < $days; $i++) {
        echo '<th>' . $current->format('d.m') . '</th>';
        $current->modify('+1 day');
    }
    echo '<th>Worked Days</th></tr>';
    
    foreach($employeeData as $empId => $data) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($data['name']) . '</td>';
        
        $current = clone $start;
        for($i = 0; $i < $days; $i++) {
            $date = $current->format('Y-m-d');
            $dayInfo = $data['daily'][$date];
            $hasRecord = ($dayInfo !== null);
            
            if($hasRecord) {
                $inTime = $dayInfo['in_time'] ? substr($dayInfo['in_time'], 0, 5) : '';
                $outTime = $dayInfo['out_time'] ? substr($dayInfo['out_time'], 0, 5) : '';
                echo '<td>' . $inTime . ' - ' . $outTime . '</td>';
            } else {
                echo '<td>-</td>';
            }
            $current->modify('+1 day');
        }
        echo '<td>' . $data['worked'] . '/' . $daysInMonth . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}
?>

<!-- ============================================================ -->
<!-- HTML - TO'LIQ QIDIRUV VA PAGINATION BILAN -->
<!-- ============================================================ -->
<style>
    .attendance-wrap { 
    background: white; 
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.header { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    margin-bottom: 0;
    padding: 12px 15px;
    flex-wrap: wrap; 
    gap: 10px; 
    background: white;
    position: sticky;
    top: 0;
    z-index: 90;
    border-bottom: 1px solid #e2e8f0;
}

.header h3 { color: #333; font-weight: 500;}

.badges { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.badge { background: #f0f0f0; padding: 5px 12px; border-radius: 20px; font-size: 12px; }

.table-wrapper { 
    overflow-x: auto; 
    overflow-y: auto; 
    max-height: calc(100vh - 174px);
    scrollbar-width: none;
}

table { 
    width: max-content;
    min-width: 100%;
    border-collapse: collapse; 
    font-size: 12px; 
}

thead tr {
    position: sticky;
    top: 0;
    z-index: 10;
}

th { 
    background: #f5f5f5; 
    padding: 2px 1px; 
    text-align: center; 
    border-bottom: 1px solid #ddd; 
    position: sticky; 
    top: 0;
}

th:first-child, 
td:first-child { 
    position: sticky; 
    left: 0; 
    background: white; 
    z-index: 5;
    min-width: max-content;
}

th:first-child {
    background: #f5f5f5;
    z-index: 6;
        padding-left: 10px;
    font-size: 15px;s
}

td:not(:first-child) { 
    padding: 4px 4px;
    text-align: center; 
    border-bottom: 1px solid #eee;
    vertical-align: middle;
    width: 10px;
}

/* Hujayra uslubi */
.attendance-cell {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1px;
    cursor: pointer;
    padding: 1px;
    border-radius: 4px;
    transition: all 0.2s;
    height: 50px;
}

.attendance-cell:hover {
    background: #f0f0f0;
    transform: scale(1.02);
}

/* Iconlar */
.status-icon {
    display: inline-block;
    width: 24px;
    height: 24px;
    line-height: 24px;
    border-radius: 4px;
    font-size: 18px;
    text-align: center;
}

.status-icon.present {
    background: #28a745;
    color: white;
    box-shadow: 0 2px 4px rgba(40,167,69,0.3);
}

.status-icon.absent {
    background: #ff6575;
    color: white;
    box-shadow: 0 2px 4px rgba(220,53,69,0.3);
}

.status-icon.partial {
    background: #ffc107;
    color: #333;
    box-shadow: 0 2px 4px rgba(255,193,7,0.3);
}

.status-icon.late {
    background: #fd7e14;
    color: white;
    box-shadow: 0 2px 4px rgba(253,126,20,0.3);
}

/* IN va OUT vaqtlari */
.time-in {
    font-size: 8px;
    font-weight: 500;
    color: #28a745;
    background: #e8f5e9;
    padding: 1px 3px;
    border-radius: 12px;
    display: inline-block;
}

.time-out {
    font-size: 8px;
    font-weight: 500;
    color: #dc3545;
    background: #e8f5e9;
    padding: 1px 3px;
    border-radius: 12px;
    display: inline-block;
}

.time-both {
    display: flex;
    gap: 1px;
    justify-content: center;
    flex-wrap: wrap;
}

.employee-name { 
    text-align: left; 
    font-weight: 600;
    padding: 8px 10px;
    background: white;
    text-decoration: none;
}

.worked-cell { 
    font-weight: 600; 
    background: #f0f0f0; 
    min-width: 150px;
    width: 150px;
    position: sticky;
    right: 0;
    z-index: 5;
    padding: 8px 6px;
    text-align: center;
}

.progress { 
    width: 100%; 
    height: 3px; 
    background: #e0e0e0; 
    border-radius: 2px; 
    margin-top: 4px; 
    overflow: hidden; 
}
.fa-arrow-right-to-bracket{
    color: #fff
}
.progress-fill { 
    height: 100%; 
    background: #f97316; 
    border-radius: 2px; 
}

.weekend { 
    background: #ffebee; 
    color: #c62828; 
}

.success { background: #d4edda; color: #155724; padding: 10px; border-radius: 8px; margin: 16px; }
.error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 8px; margin: 16px; }
.warning { background: #fff3cd; color: #856404; padding: 10px; border-radius: 8px; margin: 16px; }

/* Pagination */
.pagination {
    display: flex;
    justify-content: left;
    align-items: center;
    gap: 8px;
    padding: 10px 6px;
    flex-wrap: wrap;
    background: white;
    border-top: 1px solid #e2e8f0;
}
.pagination a, .pagination span {
    padding: 6px 12px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 13px;
    color: #1e293b;
}
.pagination a:hover {
    background: #f1f5f9;
}
.pagination .active {
    background: #2563eb;
    color: white;
}
.pagination .disabled {
    color: #94a3b8;
    cursor: not-allowed;
}
.page-info {
    font-size: 13px;
    color: #64748b;
    margin-right: 15px;
}

/* Modal */
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
.modal-box { background: white; border-radius: 12px; width: 400px; max-width: 90%; }
.modal-header { padding: 15px 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
.modal-header button { background: none; border: none; font-size: 24px; cursor: pointer; }
.modal-body { padding: 20px; max-height: 400px; overflow-y: auto; }

.btn-save { background: #28a745; color: white; padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; }
.btn-cancel { background: #6c757d; color: white; padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; }
.btn-delete { background: #dc3545; color: white; padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; width: 100%; margin-bottom: 10px; }

.record-item { padding: 10px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 8px; cursor: pointer; }
.record-item:hover { background: #f5f5f5; }
.record-item.selected { background: #e3f2fd; border-color: #2196f3; }
.record-time { font-weight: 600; }
.record-dir { font-size: 11px; margin-top: 4px; }
.record-dir.in { color: #155724; }
.record-dir.out { color: #721c24; }

@media (max-width: 768px) {
    .table-wrapper { max-height: calc(100vh - 180px); margin: 0 8px 8px 8px; scrollbar-width: none;}
    th:first-child, td:first-child { min-width: 100px; width: 100px; }
    .time-in, .time-out { font-size: 9px; padding: 1px 4px; }
    .status-icon { width: 24px; height: 24px; line-height: 24px; font-size: 12px; }
}
</style>

<div class="attendance-wrap">
    <div class="header">
        <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap; width: 100%;">
            <h3 style="margin: 0; display: flex; align-items: center; gap: 8px;">
                <?= t('attendance') ?>
            </h3>
            
            <!-- QIDIRUV FORM -->
            <?= renderEmployeeFilterBar($pdo, 'attendance', (string)$account_name, $_SESSION['token'] ?? $_SESSION['access_token'] ?? '', (string)($searchQuery ?? ''), ['start_date' => $dateFrom, 'end_date' => $dateTo], $sid ?? '') ?>
        </div>
    </div>
</div>
    
    <?php if($success == 1): ?>
    <div class="success"><?= t('success_save') ?></div>
    <?php endif; ?>
    
    <?php if(isset($error)): ?>
    <div class="error"><?= $error ?></div>
    <?php endif; ?>
    
   
    
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="text-align:left; min-width: 230px;"><?= t('employee') ?></th>
                    <?php
                    $current = clone $start;
                    for ($i = 0; $i < $days; $i++):
                        $dayNum = $current->format('d');
                       $dayName = getDayShort((int)$current->format('N'));
                        $dateValue = $current->format('Y-m-d');
                        $isWeekend = (date('N', strtotime($dateValue)) >= 6);
                        $isFuture = ($dateValue > $today);
                    ?>
                        <th class="<?= $isWeekend ? 'weekend' : '' ?>" style="<?= $isFuture ? 'opacity:0.5;' : '' ?>">
    <?= $dayNum ?><br><small><?= getDayShort((int)$current->format('N')) ?></small>
</th>
                    <?php
                        $current->modify('+1 day');
                    endfor;
                    ?>
                    <th class="worked-cell"><?= t('worked_days') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($employeeData as $empId => $data): ?>
                <tr>
                    <td class="employee-name">
                        <?= htmlspecialchars($data['name']) ?>
                    </td>
                    
                    <?php
                    $current = clone $start;
                    for($i = 0; $i < $days; $i++):
                        $date = $current->format('Y-m-d');
                        $dayInfo = $data['daily'][$date];
                        $hasRecord = ($dayInfo !== null);
                        $isFuture = ($date > $today);
                        
                        $inTime = $hasRecord ? $dayInfo['in_time'] : null;
                        $outTime = $hasRecord ? $dayInfo['out_time'] : null;
                        $lateMinutes = $hasRecord ? ($dayInfo['in_late_minutes'] ?? 0) : 0;
                        $records = $hasRecord ? $dayInfo['records'] : [];
                        
                        $status = 'absent';
                        $icon = '<i class="fa-solid fa-x"></i>';
                        $iconClass = 'absent';
                        
                        if($hasRecord) {
                            if($inTime && $outTime) {
                                if ($lateMinutes > 0) {
                                    $status = 'late';
                                    $icon = '<i class="fa-regular fa-clock"></i>';
                                    $iconClass = 'late';
                                } else {
                                    $status = 'present';
                                    $icon = '<i class="fa-solid fa-check"></i>';
                                    $iconClass = 'present';
                                }
                            } elseif($inTime) {
                                $status = 'partial';
                                $icon = '<i class="fa-solid fa-arrow-right-to-bracket"></i>';
                                $iconClass = 'partial';
                            } elseif($outTime) {
                                $status = 'partial';
                                $icon = '<i class="fa-solid fa-arrow-right-from-bracket"></i>';
                                $iconClass = 'partial';
                            }
                        }
                    ?>
                        <td>
                            <?php if(!$isFuture): ?>
                                <div class="attendance-cell" onclick="openModal(<?= $empId ?>, '<?= $date ?>', '<?= htmlspecialchars($data['name']) ?>', <?= htmlspecialchars(json_encode($records)) ?>)">
                                    <div class="status-icon <?= $iconClass ?>">
                                        <?= $icon ?>
                                    </div>
                                    <?php if($inTime || $outTime): ?>
                                        <div class="time-both">
                                            <?php if($inTime): ?>
                                                <span class="time-in"> <?= substr($inTime, 0, 5) ?></span>
                                            <?php endif; ?>
                                            <?php if($outTime): ?>
                                                <span class="time-out"><?= substr($outTime, 0, 5) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="time-both">
                                            <span class="time-in" style="opacity:0.5;">--:--</span>
                                        </div>
                                    <?php endif; ?>
                                  
                                </div>
                            <?php else: ?>
                                <div class="attendance-cell" style="opacity:0.4; cursor:not-allowed;">
                                    <div class="status-icon absent"><i class="fa-solid fa-lock"></i></div>
                                </div>
                            <?php endif; ?>
                        </td>
                    <?php
                        $current->modify('+1 day');
                    endfor;
                    ?>
                    <td class="worked-cell">
                        <?= $data['worked'] ?> / <?= $daysInMonth ?>
                        <div class="progress">
                            <div class="progress-fill" style="width: <?= ($data['worked'] / max($daysInMonth, 1) * 100) ?>%"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- PAGINATION -->
    <div class="pagination">
        <span class="page-info">
            <?= t('showing') ?> <?= ($currentPage - 1) * $itemsPerPage + 1 ?> - <?= min($currentPage * $itemsPerPage, $totalEmployees) ?> / <?= $totalEmployees ?> <?= t('employees') ?>
        </span>
        
        <?php if($currentPage > 1): ?>
        <a href="?tab=attendance&start_date=<?= urlencode($dateFrom) ?>&end_date=<?= urlencode($dateTo) ?>&page=<?= $currentPage - 1 ?>&sid=<?= urlencode($sid) ?><?= employeeFilterQueryString() ?><?= !empty($searchQuery) ? '&search='.urlencode($searchQuery) : '' ?>">« <?= t('previous') ?></a>
        <?php else: ?>
        <span class="disabled">« <?= t('previous') ?></span>
        <?php endif; ?>
        
        <?php
        $startPage = max(1, $currentPage - 2);
        $endPage = min($totalPages, $currentPage + 2);
        
        if($startPage > 1): ?>
        <a href="?tab=attendance&start_date=<?= urlencode($dateFrom) ?>&end_date=<?= urlencode($dateTo) ?>&page=1&sid=<?= urlencode($sid) ?><?= employeeFilterQueryString() ?><?= !empty($searchQuery) ? '&search='.urlencode($searchQuery) : '' ?>">1</a>
        <?php if($startPage > 2): ?><span>...</span><?php endif; ?>
        <?php endif;
        
        for($p = $startPage; $p <= $endPage; $p++):
            if($p == $currentPage): ?>
            <span class="active"><?= $p ?></span>
            <?php else: ?>
            <a href="?tab=attendance&start_date=<?= urlencode($dateFrom) ?>&end_date=<?= urlencode($dateTo) ?>&page=<?= $p ?>&sid=<?= urlencode($sid) ?><?= employeeFilterQueryString() ?><?= !empty($searchQuery) ? '&search='.urlencode($searchQuery) : '' ?>"><?= $p ?></a>
            <?php endif;
        endfor;
        
        if($endPage < $totalPages):
            if($endPage < $totalPages - 1): ?><span>...</span><?php endif; ?>
            <a href="?tab=attendance&start_date=<?= urlencode($dateFrom) ?>&end_date=<?= urlencode($dateTo) ?>&page=<?= $totalPages ?>&sid=<?= urlencode($sid) ?><?= employeeFilterQueryString() ?><?= !empty($searchQuery) ? '&search='.urlencode($searchQuery) : '' ?>"><?= $totalPages ?></a>
        <?php endif; ?>
        
        <?php if($currentPage < $totalPages): ?>
        <a href="?tab=attendance&start_date=<?= urlencode($dateFrom) ?>&end_date=<?= urlencode($dateTo) ?>&page=<?= $currentPage + 1 ?>&sid=<?= urlencode($sid) ?><?= employeeFilterQueryString() ?><?= !empty($searchQuery) ? '&search='.urlencode($searchQuery) : '' ?>"><?= t('next') ?> »</a>
        <?php else: ?>
        <span class="disabled"><?= t('next') ?> »</span>
        <?php endif; ?>
    </div>
    
   
</div>

<!-- Modal - SID QO'SHILGAN -->
<?php $attendanceReadOnly = isBranchUser(); ?>
<div id="attModal" class="modal">
    <div class="modal-box">
        <div class="modal-header">
            <h4 id="modalTitle"><?= t('records') ?></h4>
            <button onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p><strong id="empName"></strong> - <span id="dateShow"></span></p>
            <div id="recordsList" style="margin: 15px 0;"></div>
            
            <?php if (!$attendanceReadOnly): ?>
            <div id="editForm" style="display:none;">
                <form method="POST">
                    <input type="hidden" name="update_attendance" value="1">
                    <input type="hidden" name="record_id" id="edit_id">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($attendanceCsrfToken) ?>">
                    <input type="hidden" name="sid" value="<?= htmlspecialchars($sid) ?>">
                    
                    <label><?= t('datetime') ?>:</label>
                    <input type="datetime-local" name="auth_datetime" id="edit_datetime" style="width:100%; margin:5px 0 10px; padding:6px;" required>
                    
                    <label><?= t('direction') ?>:</label>
                    <select name="direction" id="edit_direction" style="width:100%; margin:5px 0 10px; padding:6px;" required>
                        <option value="IN"><?= t('check_in') ?></option>
                        <option value="OUT"><?= t('check_out') ?></option>
                    </select>
                    
                    <div style="display:flex; gap:10px; margin-bottom: 5px;">
                        <button type="button" class="btn-cancel" onclick="cancelEdit()"><?= t('cancel') ?></button>
                        <button type="submit" class="btn-save"><?= t('update') ?></button>
                    </div>
                </form>
            </div>
            
            <div id="addForm" style="display:none;">
                <form method="POST">
                    <input type="hidden" name="add_attendance" value="1">
                    <input type="hidden" name="employee_id" id="add_employee_id">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($attendanceCsrfToken) ?>">
                    <input type="hidden" name="sid" value="<?= htmlspecialchars($sid) ?>">
                    
                    <label><?= t('datetime') ?>:</label>
                    <input type="datetime-local" name="auth_datetime" id="add_datetime" style="width:100%; margin:5px 0 10px; padding:6px;" required>
                    
                    <label><?= t('direction') ?>:</label>
                    <select name="direction" id="add_direction" style="width:100%; margin:5px 0 10px; padding:6px;" required>
                        <option value="IN"><?= t('check_in') ?></option>
                        <option value="OUT"><?= t('check_out') ?></option>
                    </select>
                    
                    <div style="display:flex; gap:10px;">
                        <button type="submit" class="btn-save"><?= t('add') ?></button>
                        <button type="button" class="btn-cancel" onclick="cancelAdd()"><?= t('cancel') ?></button>
                    </div>
                </form>
            </div>
            
            <form id="deleteForm" method="POST" style="display:none;">
                <input type="hidden" name="delete_attendance" value="1">
                <input type="hidden" name="record_id" id="delete_id">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($attendanceCsrfToken) ?>">
                <input type="hidden" name="sid" value="<?= htmlspecialchars($sid) ?>">
            </form>
            <?php endif; ?>
            
            <?php if (!$attendanceReadOnly): ?>
            <button class="btn-delete" id="deleteBtn" onclick="deleteRecord()" style="display:none;"><i class="fa-solid fa-trash"></i> <?= t('delete_record') ?></button>
            <button class="btn-save" id="showAddBtn" onclick="showAddForm()" style="width:100%; margin-top:10px;"><i class="fa-solid fa-plus"></i> <?= t('add_record') ?></button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ============================================================
// PHP TARJIMALARINI JS GA OLISH
// ============================================================

// Tarjimalar obyekti - PHP dan olingan
const translations = {
    "previous_debt": "<?= t('previous_debt') ?>",
    "previous_overpaid": "<?= t('previous_overpaid') ?>",
    "late_arrival": "<?= t('late_arrival') ?>",
    "early_departure": "<?= t('early_departure') ?>",
    "early_arrival": "<?= t('early_arrival') ?>",
    "late_departure": "<?= t('late_departure') ?>",
    "minutes": "<?= t('minutes') ?>",
    "max_penalty": "<?= t('max_penalty') ?>",
    "no_penalty": "<?= t('no_penalty') ?>",
    "max_bonus": "<?= t('max_bonus') ?>",
    "daily_salary": "<?= t('daily_salary') ?>",
    "revenue": "<?= t('revenue') ?>",
    "payment": "<?= t('payment') ?>",
    "poster_payment": "<?= t('poster_payment') ?>",
    "on_time": "<?= t('on_time') ?>",
    "bonus": "<?= t('bonus') ?>",
    "penalty": "<?= t('penalty') ?>",
    "debt": "<?= t('debt') ?>",
    "overpaid": "<?= t('overpaid') ?>",
    "zero_balance": "<?= t('zero_balance') ?>",
    "debtor": "<?= t('debtor') ?>",
    "salary": "<?= t('salary') ?>",
    "edit": "<?= t('edit') ?>",
    "delete": "<?= t('delete') ?>",
    "loading": "<?= t('loading') ?>",
    "add_bonus_penalty": "<?= t('add_bonus_penalty') ?>",
    "type": "<?= t('type') ?>",
    "amount": "<?= t('amount') ?>",
    "description": "<?= t('description') ?>",
    "enter_description": "<?= t('enter_description') ?>",
    "date": "<?= t('date') ?>",
    "save": "<?= t('save') ?>",
    "cancel": "<?= t('cancel') ?>",
    "update": "<?= t('update') ?>",
    "success_save": "<?= t('success_save') ?>",
    "success_update": "<?= t('success_update') ?>",
    "success_delete": "<?= t('success_delete') ?>",
    "error_occurred": "<?= t('error_occurred') ?>",
    "please_select_employee": "<?= t('please_select_employee') ?>",
    "please_enter_amount": "<?= t('please_enter_amount') ?>",
    "server_error": "<?= t('server_error') ?>",
    "delete_confirm": "<?= t('delete_confirm') ?>",
    "record_not_found": "<?= t('record_not_found') ?>",
    "record_deleted": "<?= t('record_deleted') ?>",
    "record_updated": "<?= t('record_updated') ?>",
    "record_added": "<?= t('record_added') ?>",
    "edit_bonus_penalty": "<?= t('edit_bonus_penalty') ?>",
    "grace_period": "<?= t('grace_period') ?>",
    "actions": "<?= t('actions') ?>",
    "time": "<?= t('time') ?>",
    "search_employees": "<?= t('search_employees') ?>",
    "attendance": "<?= t('attendance') ?>",
    "reports": "<?= t('reports') ?>",
    "settings": "<?= t('settings') ?>",
    "statistics": "<?= t('statistics') ?>",
    "employees": "<?= t('employees') ?>",
    "employee": "<?= t('employee') ?>",
    "total": "<?= t('total') ?>",
    "print": "<?= t('print') ?>",
    "export_excel": "<?= t('export_excel') ?>",
    "today": "<?= t('today') ?>",
    "yesterday": "<?= t('yesterday') ?>",
    "days7": "<?= t('days7') ?>",
    "days30": "<?= t('days30') ?>",
    "prev_month": "<?= t('prev_month') ?>",
    "this_month": "<?= t('this_month') ?>",
    "clear": "<?= t('clear') ?>",
    "apply": "<?= t('apply') ?>",
    "mon_short": "<?= t('mon_short') ?>",
    "tue_short": "<?= t('tue_short') ?>",
    "wed_short": "<?= t('wed_short') ?>",
    "thu_short": "<?= t('thu_short') ?>",
    "fri_short": "<?= t('fri_short') ?>",
    "sat_short": "<?= t('sat_short') ?>",
    "sun_short": "<?= t('sun_short') ?>",
    "january_short": "<?= t('january_short') ?>",
    "february_short": "<?= t('february_short') ?>",
    "march_short": "<?= t('march_short') ?>",
    "april_short": "<?= t('april_short') ?>",
    "may_short": "<?= t('may_short') ?>",
    "june_short": "<?= t('june_short') ?>",
    "july_short": "<?= t('july_short') ?>",
    "august_short": "<?= t('august_short') ?>",
    "september_short": "<?= t('september_short') ?>",
    "october_short": "<?= t('october_short') ?>",
    "november_short": "<?= t('november_short') ?>",
    "december_short": "<?= t('december_short') ?>",
    "january": "<?= t('january') ?>",
    "february": "<?= t('february') ?>",
    "march": "<?= t('march') ?>",
    "april": "<?= t('april') ?>",
    "may": "<?= t('may') ?>",
    "june": "<?= t('june') ?>",
    "july": "<?= t('july') ?>",
    "august": "<?= t('august') ?>",
    "september": "<?= t('september') ?>",
    "october": "<?= t('october') ?>",
    "november": "<?= t('november') ?>",
    "december": "<?= t('december') ?>",
    "month": "<?= t('month') ?>",
    "hour": "<?= t('hour') ?>",
    "mo": "<?= t('mo') ?>",
    "grace": "<?= t('grace') ?>",
    "no_data": "<?= t('no_data') ?>",
    "records": "<?= t('records') ?>",
    "add_new": "<?= t('add_new') ?>",
    "no_records": "<?= t('no_records') ?>",
    "late": "<?= t('late') ?>"
};

// Tarjima funksiyasi
function t(key) {
    return translations[key] || key;
}

// ============================================================
// ASOSIY JS KOD
// ============================================================

let currentRecords = [];
let currentEmpId = null;
let currentDate = null;
const attendanceReadOnly = <?= $attendanceReadOnly ? 'true' : 'false' ?>;

function openModal(empId, date, empName, records) {
    if (typeof records === 'string') {
        try {
            records = JSON.parse(records);
        } catch(e) {
            records = [];
        }
    }
    
    currentEmpId = empId;
    currentDate = date;
    currentRecords = records || [];
    
    document.getElementById('empName').innerHTML = empName;
    document.getElementById('dateShow').innerHTML = formatDate(date);
    document.getElementById('modalTitle').innerHTML = empName + ' - ' + date;
    if (!attendanceReadOnly) {
        document.getElementById('add_employee_id').value = empId;
    }
    
    let html = '<div style="margin-bottom:10px;"><b>' + t('records') + ':</b></div>';
    if(currentRecords.length > 0) {
        currentRecords.forEach((r, index) => {
            let time = r.full_datetime ? r.full_datetime.substring(11, 16) : (r.time || '--:--');
            let dirClass = (r.direction == 'IN') ? 'in' : 'out';
            let dirIcon = (r.direction == 'IN') ? 'IN' : 'OUT';
            let statusBadge = '';
            if (r.status == 'late') {
                statusBadge = ' <span style="color:#fd7e14;font-size:10px;">' + t('late') + '</span>';
            }
            html += `
                <div class="record-item ${index === 0 ? 'selected' : ''}" 
                     ${attendanceReadOnly ? '' : `onclick="selectRecord(${r.id}, '${r.full_datetime || ''}', '${r.direction}', event)"`}>
                    <div class="record-time">${time} ${statusBadge}</div>
                    <div class="record-dir ${dirClass}">${dirIcon}</div>
                </div>
            `;
        });
    } else {
        html += '<div style="color:#888; padding:20px; text-align:center;">' + t('no_records') + '<br>' + t('add_new') + '</div>';
    }
    document.getElementById('recordsList').innerHTML = html;
    
    if (!attendanceReadOnly) {
        document.getElementById('editForm').style.display = 'none';
        document.getElementById('addForm').style.display = 'none';
        document.getElementById('deleteBtn').style.display = 'none';
        document.getElementById('showAddBtn').style.display = 'block';
    }
    
    document.getElementById('attModal').style.display = 'flex';
}

function selectRecord(id, datetime, direction, event) {
    if (attendanceReadOnly) return;
    document.querySelectorAll('.record-item').forEach(el => el.classList.remove('selected'));
    
    if(event && event.currentTarget) {
        event.currentTarget.classList.add('selected');
    }
    
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_datetime').value = datetime ? datetime.replace(' ', 'T').substring(0, 16) : '';
    document.getElementById('edit_direction').value = direction;
    document.getElementById('delete_id').value = id;
    
    document.getElementById('editForm').style.display = 'block';
    document.getElementById('addForm').style.display = 'none';
    document.getElementById('deleteBtn').style.display = 'block';
    document.getElementById('showAddBtn').style.display = 'block';
}

function deleteRecord() {
    if (attendanceReadOnly) return;
    if(confirm(t('delete_confirm'))) {
        document.getElementById('deleteForm').submit();
    }
}

function showAddForm() {
    if (attendanceReadOnly) return;
    let now = new Date();
    let defaultTime = currentDate + 'T' + now.toTimeString().slice(0,5);
    document.getElementById('add_datetime').value = defaultTime;
    document.getElementById('add_direction').value = 'IN';
    
    document.getElementById('editForm').style.display = 'none';
    document.getElementById('addForm').style.display = 'block';
    document.getElementById('deleteBtn').style.display = 'none';
    document.getElementById('showAddBtn').style.display = 'none';
}

function cancelEdit() {
    document.getElementById('editForm').style.display = 'none';
    document.getElementById('deleteBtn').style.display = 'none';
    document.querySelectorAll('.record-item').forEach(el => el.classList.remove('selected'));
}

function cancelAdd() {
    document.getElementById('addForm').style.display = 'none';
    document.getElementById('showAddBtn').style.display = 'block';
}

function closeModal() {
    document.getElementById('attModal').style.display = 'none';
}

function formatDate(dateStr) {
    let months = [t('january'), t('february'), t('march'), t('april'), t('may'), t('june'), t('july'), t('august'), t('september'), t('october'), t('november'), t('december')];
    let d = new Date(dateStr);
    return d.getDate() + ' ' + months[d.getMonth()];
}

function exportToExcel() {
    let startDate = '<?= $dateFrom ?>';
    let endDate = '<?= $dateTo ?>';
    let sid = '<?= urlencode($sid) ?>';
    window.location.href = '?tab=attendance&start_date=' + startDate + '&end_date=' + endDate + '&export_excel=1&sid=' + sid;
}

window.onclick = function(e) {
    if(e.target === document.getElementById('attModal')) {
        closeModal();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    
    if (searchInput) {
        let timeoutId = null;
        
        function submitSearch() {
            const searchValue = searchInput.value.trim();
            const params = new URLSearchParams(window.location.search);
            
            if (searchValue.length > 0) {
                params.set('search', searchValue);
            } else {
                params.delete('search');
            }
            
            window.location.href = '?' + params.toString();
        }
        
        searchInput.addEventListener('input', function() {
            clearTimeout(timeoutId);
            const value = this.value.trim();
            
            if (value.length >= 2 || value.length === 0) {
                timeoutId = setTimeout(submitSearch, 500);
            }
        });
        
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(timeoutId);
                submitSearch();
            }
        });
    }
});
</script>
