<?php
// views/dashboard.php - DEFAULT QIYMATLARSIZ
// sync_functions.php bilan to'liq integratsiya
// Xodimlar holati faqat bugungi kun uchun

global $pdo, $dateFrom, $dateTo, $accountName, $currentLang, $accountId;

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

// ============================================================
// 2. ASOSIY O'ZGARUVCHILAR
// ============================================================
$dateFrom = $_GET['start_date'] ?? date('Y-m-01');
$dateTo = $_GET['end_date'] ?? date('Y-m-d');
$today = date('Y-m-d');
$accountName = $_SESSION['account'] ?? $_SESSION['account_name'] ?? '';
$accountId = $_SESSION['account_id'] ?? '';
$currentLang = $_SESSION['user_lang'] ?? 'en';
$GLOBALS['currentLang'] = $currentLang;

// ============================================================
// 2.1 ACCOUNT ID NI OLISH (agar sessiyada bo'lmasa)
// ============================================================
if(empty($accountId) && !empty($accountName)) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM accounts WHERE account_name = ?");
        $stmt->execute([$accountName]);
        $accountRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if($accountRow) {
            $accountId = $accountRow['id'];
            $_SESSION['account_id'] = $accountId;
        }
    } catch(Exception $e) {
        error_log("Account ID olish xatosi: " . $e->getMessage());
    }
}

// ============================================================
// 3. KUN NOMLARI TARJIMALARI - LANGUAGE.PHP DAN
// ============================================================
$dayNames = [
    'Mon' => t('mon_short'),
    'Tue' => t('tue_short'),
    'Wed' => t('wed_short'),
    'Thu' => t('thu_short'),
    'Fri' => t('fri_short'),
    'Sat' => t('sat_short'),
    'Sun' => t('sun_short')
];

$monthNames = [
    '01' => t('january_short'),
    '02' => t('february_short'),
    '03' => t('march_short'),
    '04' => t('april_short'),
    '05' => t('may_short'),
    '06' => t('june_short'),
    '07' => t('july_short'),
    '08' => t('august_short'),
    '09' => t('september_short'),
    '10' => t('october_short'),
    '11' => t('november_short'),
    '12' => t('december_short')
];

// ============================================================
// 4. FORMATLASH FUNKSIYALARI
// ============================================================

function formatDiff($value) {
    if ($value === null || $value === 0) {
        return '<span style="color:#94a3b8;">0 ' . t('minutes') . '</span>';
    }
    
    if ($value > 0) {
        return '<span style="color:#fd7e14;font-weight:600;">+' . $value . ' ' . t('minutes') . '</span>';
    } else {
        return '<span style="color:#dc3545;font-weight:600;">' . $value . ' ' . t('minutes') . '</span>';
    }
}

function getStatusText($lateMinutes, $earlyMinutes, $hasOut, $hasIn) {
    // IN holati
    if (!$hasIn) {
        $kelish = t('not_came');
    } elseif ($lateMinutes > 0) {
        $kelish = t('late_arrived') . ' ' . $lateMinutes . ' ' . t('minutes');
    } elseif ($lateMinutes < 0) {
        $kelish = t('early_arrived') . ' ' . abs($lateMinutes) . ' ' . t('minutes');
    } else {
        $kelish = t('on_time_arrived');
    }
    
    // OUT holati
    if (!$hasOut) {
        $ketish = t('departure_pending');
    } elseif ($earlyMinutes < 0) {
        $ketish = t('early_left') . ' ' . abs($earlyMinutes) . ' ' . t('minutes');
    } elseif ($earlyMinutes > 0) {
        $ketish = t('late_left') . ' ' . $earlyMinutes . ' ' . t('minutes');
    } else {
        $ketish = t('on_time_left');
    }
    
    return $kelish . ' | ' . $ketish;
}

function getStatusShort($lateMinutes, $earlyMinutes, $hasOut, $hasIn) {
    $status = '';
    
    // IN holati
    if (!$hasIn) {
        $status = t('not_came');
    } elseif ($lateMinutes > 0) {
        $status = t('late_arrived') . ' ' . $lateMinutes . ' ' . t('minutes');
    } elseif ($lateMinutes < 0) {
        $status = t('early_arrived') . ' ' . abs($lateMinutes) . ' ' . t('minutes');
    } else {
        $status = t('on_time_arrived');
    }
    
    // OUT holati
    if ($hasOut) {
        if ($earlyMinutes < 0) {
            $status .= ' / ' . t('early_left') . ' ' . abs($earlyMinutes) . ' ' . t('minutes');
        } elseif ($earlyMinutes > 0) {
            $status .= ' / ' . t('late_left') . ' ' . $earlyMinutes . ' ' . t('minutes');
        } else {
            $status .= ' / ' . t('on_time_left');
        }
    } else {
        $status .= ' / ' . t('departure_pending');
    }
    
    return $status;
}

function getStatusBadgeClass($lateMinutes, $earlyMinutes, $hasOut, $hasIn) {
    if (!$hasIn) {
        return 'badge-absent';
    }
    
    if (!$hasOut) {
        return 'badge-warning';
    }
    
    if ($lateMinutes > 0 && $earlyMinutes < 0) {
        return 'badge-danger';
    } elseif ($lateMinutes > 0 && $earlyMinutes > 0) {
        return 'badge-warning';
    } elseif ($lateMinutes > 0) {
        return 'badge-warning';
    } elseif ($lateMinutes < 0 && $earlyMinutes < 0) {
        return 'badge-early-come';
    } elseif ($lateMinutes < 0 && $earlyMinutes > 0) {
        return 'badge-early-come';
    } elseif ($lateMinutes < 0) {
        return 'badge-early-come';
    } elseif ($earlyMinutes < 0) {
        return 'badge-early';
    } elseif ($earlyMinutes > 0) {
        return 'badge-late-go';
    } else {
        return 'badge-ontime';
    }
}

// ============================================================
// 5. XODIMLARNI OLISH
// ============================================================
$employees = [];
$totalEmployees = 0;

if(!empty($accountName) && !empty($accountId)) {
    try {
        $employeeSql = "
            SELECT id, ismi
            FROM xodimlar
            WHERE holati = 'active' AND account_id = ? AND account_name = ?
        ";
        $employeeParams = [$accountId, $accountName];
        if (isBranchUser()) {
            $employeeSql .= " AND branch_spot_id = ?";
            $employeeParams[] = currentBranchSpotId();
        }
        $employeeSql .= " ORDER BY ismi";
        $stmt = $pdo->prepare($employeeSql);
        $stmt->execute($employeeParams);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalEmployees = count($employees);
    } catch(Exception $e) {
        error_log("Xodimlarni olish xatosi: " . $e->getMessage());
    }
}

// ============================================================
// 6. OYLIK SOZLAMALARDAN ISH JADVALINI OLISH
// ============================================================
function getEmployeeWorkSchedule($employeeId, $date) {
    global $pdo;
    $month = date('Y-m', strtotime($date));
    $oyVaqt = $month . '-01';
    
    try {
        $stmt = $pdo->prepare("
            SELECT ishga_kelish_vaqti, ishdan_ketish_vaqti, 
                   tushlik_boshlanish, tushlik_tugash
            FROM oylik_sozlamalar 
            WHERE xodim_id = ? AND oy_vaqt = ? 
            AND amal_qiladi <= ?
            ORDER BY amal_qiladi DESC, id DESC LIMIT 1
        ");
        $stmt->execute([$employeeId, $oyVaqt, $date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return [
                'start_time' => $result['ishga_kelish_vaqti'],
                'end_time' => $result['ishdan_ketish_vaqti'],
                'lunch_start' => $result['tushlik_boshlanish'],
                'lunch_end' => $result['tushlik_tugash']
            ];
        }
    } catch(Exception $e) {
        error_log("getEmployeeWorkSchedule error: " . $e->getMessage());
    }
    
    return null;
}

// ============================================================
// OYLIK SOZLAMALARNI FAQAT OY O'ZGARGANDA YANGILASH
// ============================================================
if (isset($_SESSION['account']) && !empty($_SESSION['account'])) {
    $accountName = $_SESSION['account'];
    
    $lastCheckedMonth = isset($_SESSION['settings_last_month']) ? $_SESSION['settings_last_month'] : '';
    $currentMonth = date('Y-m');
    
    if ($lastCheckedMonth !== $currentMonth) {
        if (function_exists('autoUpdateMonthlySettings')) {
            autoUpdateMonthlySettings($accountName);
        }
        $_SESSION['settings_last_month'] = $currentMonth;
    }
}


// ============================================================
// 8. STATISTIKA HISOBI
// ============================================================
$presentToday = 0;
$absentToday = 0;
$lateToday = 0;
$ontimeToday = 0;
$earlyToday = 0;
$totalLateMinutesToday = 0;
$totalEarlyMinutesToday = 0;
$lateEmployeesToday = [];
$earlyEmployeesToday = [];

$totalPresentMonth = 0;
$totalAbsentMonth = 0;
$totalLateMonth = 0;
$totalOntimeMonth = 0;
$totalEarlyMonth = 0;
$totalOvertimeHoursMonth = 0;
$totalLateMinutesMonth = 0;
$totalEarlyMinutesMonth = 0;
$employeeStats = [];

// ============================================================
// 8.1 OYLIK CHART UCHUN MA'LUMOTLAR - KUN NOMLARI BILAN
// ============================================================
$monthlyData = [];
$current = new DateTime($dateFrom);
$end = new DateTime($dateTo);
$days = (clone $current)->diff($end)->days + 1;

for($i = 0; $i < $days; $i++) {
    $date = $current->format('Y-m-d');
    $dayShort = $current->format('D');
    $dayName = $dayNames[$dayShort] ?? $dayShort;
    $dayNum = $current->format('d');
    
    $monthlyData[$date] = [
        'day' => $dayNum,
        'day_name' => $dayName,
        'day_short' => $dayShort,
        'present' => 0,
        'late' => 0,
        'absent' => $totalEmployees,
        'date' => $date
    ];
    $current->modify('+1 day');
}

$empIds = array_column($employees, 'id');

if (!empty($empIds)) {
    $placeholders = implode(',', array_fill(0, count($empIds), '?'));
    
    // ============================================================
    // A. BUGUNGI KUN STATISTIKASI
    // ============================================================
    try {
        $totalEmployeesToday = $totalEmployees;
        $stmt = $pdo->prepare("
            SELECT 
                employee_id,
                in_time,
                in_late_minutes,
                out_time,
                out_early_minutes,
                total_work_hours
            FROM attendance_data 
            WHERE auth_date = ?
                AND employee_id IN ($placeholders)
                AND account_name = ?
        ");
        $params = array_merge([$today], $empIds, [$accountName]);
        $stmt->execute($params);
        $todayData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($todayData as $row) {
            $lateMinutes = (int)($row['in_late_minutes'] ?? 0);
            $earlyMinutes = (int)($row['out_early_minutes'] ?? 0);
            
            if (!empty($row['in_time'])) {
                $presentToday++;
                if ($lateMinutes > 0) {
                    $lateToday++;
                    $totalLateMinutesToday += $lateMinutes;
                    $lateEmployeesToday[] = [
                        'employee_id' => $row['employee_id'],
                        'minutes' => $lateMinutes
                    ];
                } else {
                    $ontimeToday++;
                }
            }
            
            if (!empty($row['out_time']) && $earlyMinutes < 0) {
                $earlyToday++;
                $totalEarlyMinutesToday += abs($earlyMinutes);
                $earlyEmployeesToday[] = [
                    'employee_id' => $row['employee_id'],
                    'minutes' => abs($earlyMinutes)
                ];
            }
        }
         
        $absentToday = $totalEmployeesToday - $presentToday;

    } catch (Exception $e) {
        error_log("Bugungi statistika xatosi: " . $e->getMessage());
    }
    
    // ============================================================
    // B. TANLANGAN KUN ORALIG'IDAGI STATISTIKA
    // ============================================================
    try {
        $stmt = $pdo->prepare("
            SELECT 
                employee_id,
                auth_date,
                in_time,
                in_late_minutes,
                out_time,
                out_early_minutes,
                total_work_hours
            FROM attendance_data 
            WHERE auth_date BETWEEN ? AND ?
                AND employee_id IN ($placeholders)
                AND account_name = ?
            ORDER BY employee_id, auth_date DESC
        ");
        $params = array_merge([$dateFrom, $dateTo], $empIds, [$accountName]);
        $stmt->execute($params);
        $allData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $groupedData = [];
        foreach ($allData as $row) {
            $empId = $row['employee_id'];
            if (!isset($groupedData[$empId])) {
                $groupedData[$empId] = [];
            }
            $date = $row['auth_date'];
            if (!isset($groupedData[$empId][$date])) {
                $groupedData[$empId][$date] = $row;
            }
        }
        
        foreach ($employees as $emp) {
            $empId = $emp['id'];
            $present = 0;
            $absent = 0;
            $late = 0;
            $ontime = 0;
            $early = 0;
            $overtime = 0;
            $totalLateMin = 0;
            $totalEarlyMin = 0;
            $lastLateMinutes = 0;
            $lastEarlyMinutes = 0;
            $hasOut = false;
            $hasIn = false;
            $empRecords = [];
            
            if (isset($groupedData[$empId])) {
                $dates = array_keys($groupedData[$empId]);
                rsort($dates);
                $lastDate = $dates[0];
                $lastRecord = $groupedData[$empId][$lastDate];
                
                $lastLateMinutes = (int)($lastRecord['in_late_minutes'] ?? 0);
                $lastEarlyMinutes = (int)($lastRecord['out_early_minutes'] ?? 0);
                $hasIn = !empty($lastRecord['in_time']);
                $hasOut = !empty($lastRecord['out_time']);
                
                foreach ($groupedData[$empId] as $date => $record) {
                    $lateMin = (int)($record['in_late_minutes'] ?? 0);
                    $earlyMin = (int)($record['out_early_minutes'] ?? 0);
                    
                    if (!empty($record['in_time'])) {
                        if ($lateMin > 0) {
                            $late++;
                            $totalLateMin += $lateMin;
                        } else {
                            $ontime++;
                        }
                        $present++;
                        
                        if (isset($monthlyData[$date])) {
                            $monthlyData[$date]['present']++;
                            $monthlyData[$date]['absent']--;
                            if ($lateMin > 0) {
                                $monthlyData[$date]['late']++;
                            }
                        }
                    } else {
                        $absent++;
                    }
                    
                    if (!empty($record['out_time']) && $earlyMin < 0) {
                        $early++;
                        $totalEarlyMin += abs($earlyMin);
                    }
                    
                    if (!empty($record['out_time'])) {
                        $overtime += (float)($record['total_work_hours'] ?? 0);
                    }
                    
                    $empRecords[$date] = $record;
                }
            }
            
            $totalPresentMonth += $present;
            $totalAbsentMonth += $absent;
            $totalLateMonth += $late;
            $totalOntimeMonth += $ontime;
            $totalEarlyMonth += $early;
            $totalOvertimeHoursMonth += $overtime;
            $totalLateMinutesMonth += $totalLateMin;
            $totalEarlyMinutesMonth += $totalEarlyMin;
            
            $statusText = getStatusText($lastLateMinutes, $lastEarlyMinutes, $hasOut, $hasIn);
            $statusShort = getStatusShort($lastLateMinutes, $lastEarlyMinutes, $hasOut, $hasIn);
            $badgeClass = getStatusBadgeClass($lastLateMinutes, $lastEarlyMinutes, $hasOut, $hasIn);
            
            $employeeStats[] = [
                'id' => $empId,
                'name' => $emp['ismi'],
                'present' => $present,
                'absent' => $absent,
                'late' => $late,
                'ontime' => $ontime,
                'early' => $early,
                'overtime' => round($overtime, 1),
                'total_late_minutes' => $totalLateMin,
                'total_early_minutes' => $totalEarlyMin,
                'last_late_minutes' => $lastLateMinutes,
                'last_early_minutes' => $lastEarlyMinutes,
                'has_out' => $hasOut,
                'has_in' => $hasIn,
                'status_text' => $statusText,
                'status_short' => $statusShort,
                'badge_class' => $badgeClass,
                'attendance_rate' => $days > 0 ? round(($present / $days) * 100, 1) : 0,
                'has_attendance' => isset($groupedData[$empId]) && !empty($groupedData[$empId]),
                'records' => isset($groupedData[$empId]) ? count($groupedData[$empId]) : 0,
            ];
        }
        
    } catch (Exception $e) {
        error_log("Oylik statistika xatosi: " . $e->getMessage());
        foreach ($employees as $emp) {
            $employeeStats[] = [
                'id' => $emp['id'],
                'name' => $emp['ismi'],
                'present' => 0,
                'absent' => $days,
                'late' => 0,
                'ontime' => 0,
                'early' => 0,
                'overtime' => 0,
                'total_late_minutes' => 0,
                'total_early_minutes' => 0,
                'last_late_minutes' => 0,
                'last_early_minutes' => 0,
                'has_out' => false,
                'has_in' => false,
                'status_text' => t('no_data'),
                'status_short' => t('absent'),
                'badge_class' => 'badge-absent',
                'attendance_rate' => 0,
                'has_attendance' => false,
                'records' => 0,
            ];
        }
    }

    // ============================================================
    // C. XODIMLAR HOLATI - FAQAT BUGUNGI KUN UCHUN
    // ============================================================
    $employeeStatusToday = [];
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                employee_id,
                in_time,
                in_late_minutes,
                out_time,
                out_early_minutes
            FROM attendance_data 
            WHERE auth_date = ?
                AND employee_id IN ($placeholders)
                AND account_name = ?
        ");
        $params = array_merge([$today], $empIds, [$accountName]);
        $stmt->execute($params);
        $todayData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $todayDataIndexed = [];
        foreach ($todayData as $row) {
            $todayDataIndexed[$row['employee_id']] = $row;
        }
        
        foreach ($employees as $emp) {
            $empId = $emp['id'];
            $hasIn = false;
            $hasOut = false;
            $lateMinutes = 0;
            $earlyMinutes = 0;
            $statusShort = '';
            $badgeClass = '';
            $attendanceStatus = '';
            
            if (isset($todayDataIndexed[$empId])) {
                $record = $todayDataIndexed[$empId];
                $lateMinutes = (int)($record['in_late_minutes'] ?? 0);
                $earlyMinutes = (int)($record['out_early_minutes'] ?? 0);
                $hasIn = !empty($record['in_time']);
                $hasOut = !empty($record['out_time']);
            }
            
            if ($hasIn) {
                if ($lateMinutes > 0) {
                    $statusShort = t('late_arrived') . ' ' . $lateMinutes . ' ' . t('minutes');
                    $badgeClass = 'badge-warning';
                    $attendanceStatus = t('late');
                } elseif ($lateMinutes < 0) {
                    $statusShort = t('early_arrived') . ' ' . abs($lateMinutes) . ' ' . t('minutes');
                    $badgeClass = 'badge-early-come';
                    $attendanceStatus = t('early_arrived');
                } else {
                    $statusShort = t('on_time_arrived');
                    $badgeClass = 'badge-ontime';
                    $attendanceStatus = t('on_time');
                }
                
                if ($hasOut) {
                    if ($earlyMinutes < 0) {
                        $statusShort .= ' / ' . t('early_left') . ' ' . abs($earlyMinutes) . ' ' . t('minutes');
                        $badgeClass = 'badge-early';
                        $attendanceStatus .= ' / ' . t('early_left');
                    } elseif ($earlyMinutes > 0) {
                        $statusShort .= ' / ' . t('late_left') . ' ' . $earlyMinutes . ' ' . t('minutes');
                        $badgeClass = 'badge-late-go';
                        $attendanceStatus .= ' / ' . t('late_left');
                    } else {
                        $statusShort .= ' / ' . t('on_time_left');
                        $badgeClass = 'badge-ontime';
                        $attendanceStatus .= ' / ' . t('on_time');
                    }
                } else {
                    $statusShort .= ' / ' . t('departure_pending');
                    $badgeClass = 'badge-warning';
                    $attendanceStatus .= ' / ' . t('no_departure');
                }
            } else {
                $statusShort = t('not_came');
                $badgeClass = 'badge-absent';
                $attendanceStatus = t('absent');
            }
            
            $employeeStatusToday[] = [
                'id' => $empId,
                'name' => $emp['ismi'],
                'status_short' => $statusShort,
                'badge_class' => $badgeClass,
                'has_attendance' => $hasIn,
                'late_minutes' => $lateMinutes,
                'early_minutes' => $earlyMinutes,
                'attendance_status' => $attendanceStatus
            ];
        }
        
    } catch (Exception $e) {
        error_log("Bugungi xodim holati xatosi: " . $e->getMessage());
    }
}

// ============================================================
// 9. UMUMIY STATISTIKA
// ============================================================
$totalPossibleDays = $totalEmployees * $days;
$attendanceRate = $totalPossibleDays > 0 ? round(($totalPresentMonth / $totalPossibleDays) * 100, 1) : 0;
$lateRate = $totalPresentMonth > 0 ? round(($totalLateMonth / $totalPresentMonth) * 100, 1) : 0;
$earlyRate = $totalPresentMonth > 0 ? round(($totalEarlyMonth / $totalPresentMonth) * 100, 1) : 0;

$chartPresent = max(0, $presentToday - $lateToday);
$chartLate = $lateToday;
$chartEarly = $earlyToday;
$chartAbsent = $absentToday;
$chartTotal = $chartPresent + $chartLate + $chartEarly + $chartAbsent;

// ============================================================
// 9.1 OYLIK CHART MA'LUMOTLARI
// ============================================================
$chartLabels = [];
$chartPresentData = [];
$chartLateData = [];
$chartAbsentData = [];

foreach ($monthlyData as $date => $data) {
    $label = $data['day'] . ' (' . $data['day_name'] . ')';
    $chartLabels[] = $label;
    $chartPresentData[] = max(0, $data['present']);
    $chartLateData[] = max(0, $data['late']);
    $chartAbsentData[] = max(0, $data['absent']);
}

// ============================================================
// 9.2 KECH QOLGAN VA ERTA KETGAN XODIMLAR
// ============================================================
$empNameById = array_column($employees, 'ismi', 'id');
foreach ($lateEmployeesToday as &$le) {
    $le['name'] = $empNameById[$le['employee_id']] ?? $le['employee_id'];
}
unset($le);
usort($lateEmployeesToday, fn($a, $b) => $b['minutes'] <=> $a['minutes']);

foreach ($earlyEmployeesToday as &$ee) {
    $ee['name'] = $empNameById[$ee['employee_id']] ?? $ee['employee_id'];
}
unset($ee);
usort($earlyEmployeesToday, fn($a, $b) => $b['minutes'] <=> $a['minutes']);

// BUGUNGI STATISTIKA
$todayStats = [
    'present' => $presentToday,
    'absent' => $absentToday,
    'late' => $lateToday,
    'ontime' => $ontimeToday,
    'early' => $earlyToday,
    'late_minutes_total' => $totalLateMinutesToday,
    'early_minutes_total' => $totalEarlyMinutesToday,
    'late_minutes_avg' => $lateToday > 0 ? round($totalLateMinutesToday / $lateToday) : 0,
    'totalEmployeesToday' => $totalEmployees
];

$employeesWithData = array_filter($employeeStats, function($emp) {
    return $emp['has_attendance'] === true;
});
$employeesWithDataCount = count($employeesWithData); 
?>

<!-- CSS -->
<style>
.dashboard-container{width:97%;margin:0 auto;padding-top:20px;}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:28px;background-color:#eff6ff;border:solid 1px #a5c7ee;border-radius:8px;padding:15px;}
.stat-card{border-radius:8px;padding:10px;text-align:center;transition:all .2s}
.stat-card:hover{transform:translateY(-2px);}
.stat-value{font-size:28px;font-weight:700;color:#333333}
.stat-label{font-size:13px;color:#64748b;margin-top:5px}
.stat-sub{font-size:11px;color:#94a3b8;margin-top:5px}
.card{background:#fff;border-radius:8px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:28px}
.card-header{padding:16px 15px;font-weight:600;font-size:15px;border-bottom:1px solid #e2e8f0}
.card-body{padding:20px}
.two-columns{display:grid;grid-template-columns:1fr 1fr;gap:28px;margin-bottom:28px}
@media(max-width:768px){.two-columns{grid-template-columns:1fr;gap:20px}.stats-grid{grid-template-columns:repeat(2,1fr)}}
.progress-wrapper{margin-top:20px}
.progress-item{margin-bottom:16px}
.progress-label{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;color:#475569}
.progress-bar-bg{height:8px;background:#e2e8f0;border-radius:10px;overflow:hidden}
.progress-fill{height:100%;border-radius:10px;transition:width .3s}
.fill-green{background:#10b981}.fill-orange{background:#f97316}.fill-red{background:#ef4444}
.leaderboard-item{display:flex;align-items:center;padding:12px 0;border-bottom:1px solid #e2e8f0}
.leaderboard-item:last-child{border-bottom:none}
.leaderboard-rank{width:32px;height:32px;background:#f1f5f9;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;margin-right:12px}
.rank-1{background:#fef2e8;color:#f97316}
.leaderboard-info{flex:1}
.leaderboard-name{font-weight:600;font-size:14px}
.leaderboard-stats{font-size:11px;color:#64748b;margin-top:2px}
.leaderboard-value{font-weight:700;font-size:14px;color:#f97316}
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table th{text-align:left;padding:12px 16px;background:#f8fafc;font-weight:600;font-size:12px;color:#64748b;border-bottom:2px solid #e2e8f0}
.data-table td{padding:12px 16px;border-bottom:1px solid #e2e8f0}
.data-table tbody tr:hover td{background:#fafbfc}
.badge{display:inline-block;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:500;}
.badge-success{background:#f1fff8;color:#065f46;border:1px solid #64ffaf}
.badge-danger{background:rgb(255,243,243);color:#991b1b;border:1px solid #ff8b8b}
.badge-warning{background:#fffdf3;color:#d97706;border:1px solid #ffda48}
.badge-early{background:#fff2f6;color:#c62828;border:1px solid #ff8fb4}
.badge-early-come{background:#f2fff8;color:#065f46;border:1px solid #8bffc3}
.badge-late-go{background:#fffdf3;color:#d97706;border:1px solid #ffe789}
.badge-ontime{background:#f4faff;color:#0369a1;border:1px solid #80ccff}
.badge-absent{background:#fff4f4;color:#991b1b;border:1px solid #ff9494}
.chart-container{overflow-x:auto}
.today-status{font-size:12px;color:#64748b;margin-left:10px;font-weight:400;}
</style>

<!-- HTML -->
<div class="dashboard-container">
    <!-- Kunlik statistika -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $todayStats['present'] ?></div>
            <div class="stat-label"><?= t('present_today') ?></div>
            <div class="stat-sub"><?= $todayStats['ontime'] ?> <?= t('on_time') ?> | <?= $todayStats['late'] ?> <?= t('late') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $todayStats['late'] ?></div>
            <div class="stat-label"><?= t('late_employees') ?></div>
            <div class="stat-sub"><?= t('total') ?> <?= $todayStats['late_minutes_total'] ?> <?= t('minutes') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $todayStats['early'] ?></div>
            <div class="stat-label"><?= t('early_left_employees') ?></div>
            <div class="stat-sub"><?= t('total') ?> <?= $todayStats['early_minutes_total'] ?> <?= t('minutes') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $todayStats['absent'] ?></div>
            <div class="stat-label"><?= t('absent_today') ?></div>
            <div class="stat-sub"><?= t('total') ?> <?= $todayStats['totalEmployeesToday'] ?> <?= t('employees') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $attendanceRate ?>%</div>
            <div class="stat-label"><?= t('attendance_rate') ?></div>
            <div class="stat-sub"><?= t('monthly') ?> (<?= $days ?> <?= t('days') ?>)</div>
        </div>
    </div>

    <!-- Chart va Xodimlar holati (faqat bugun) -->
    <div class="two-columns">
        <div class="card">
            <div class="card-header"><?= t('attendance_statistics') ?> (<?= $dateFrom ?> - <?= $dateTo ?>)</div>
            <div class="card-body">
                <div style="display:flex;justify-content:center;padding:10px 0">
                    <canvas id="attendanceChart" width="200" height="200"></canvas>
                </div>
                <div class="progress-wrapper">
                    <div class="progress-item">
                        <div class="progress-label">
                            <span><?= t('present') ?></span>
                            <span><strong><?= number_format($totalPresentMonth) ?></strong> / <?= $totalPossibleDays ?></span>
                        </div>
                        <div class="progress-bar-bg"><div class="progress-fill fill-green" style="width:<?= min(100, $attendanceRate) ?>%"></div></div>
                    </div>
                    <div class="progress-item">
                        <div class="progress-label">
                            <span><?= t('late_arrivals') ?></span>
                            <span><strong><?= number_format($totalLateMonth) ?></strong> (<?= formatDiff($totalLateMinutesMonth ?? 0) ?>)</span>
                        </div>
                        <div class="progress-bar-bg"><div class="progress-fill fill-orange" style="width:<?= min(100, $lateRate) ?>%"></div></div>
                    </div>
                    <div class="progress-item">
                        <div class="progress-label">
                            <span><?= t('early_departures') ?></span>
                            <span><strong><?= number_format($totalEarlyMonth) ?></strong> (<?= formatDiff($totalEarlyMinutesMonth ?? 0) ?>)</span>
                        </div>
                        <div class="progress-bar-bg"><div class="progress-fill fill-red" style="width:<?= min(100, $earlyRate) ?>%"></div></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Xodimlar holati - FAQAT BUGUN -->
        <div class="card">
            <div class="card-header">
                <?= t('employees_status') ?> (<?= date('Y-m-d') ?>)
                <span class="today-status"><?= t('today_status') ?></span>
            </div>
            <div class="card-body" style="padding:8px 20px; max-height:800px; overflow-y:auto;">
                <?php if (isset($employeeStatusToday) && is_array($employeeStatusToday) && count($employeeStatusToday) > 0): ?>
                    <?php foreach ($employeeStatusToday as $idx => $emp): ?>
                    <div class="leaderboard-item">
                        <div class="leaderboard-rank <?= $idx < 3 ? 'rank-1' : '' ?>"><?= $idx + 1 ?></div>
                        <div class="leaderboard-info">
                            <div class="leaderboard-name"><?= htmlspecialchars($emp['name'] ?? t('unknown')) ?></div>
                            <div class="leaderboard-stats">
                                <span class="badge <?= $emp['badge_class'] ?? 'badge-secondary' ?>"><?= $emp['status_short'] ?? '--' ?></span>
                            </div>
                        </div>
                        <div class="leaderboard-value">
                            <?php if (isset($emp['has_attendance']) && $emp['has_attendance']): ?>
                                <span style="color:#10b981;">●</span>
                            <?php else: ?>
                                <span style="color:#dc3545;">●</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align:center;padding:30px;color:#94a3b8;">
                        <?= t('no_data') ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Oylik chart -->
    <div class="card">
        <div class="card-header">
            <?= t('monthly_attendance') ?> 
            (<?= date('F Y', strtotime($dateFrom)) ?>)
            <span style="font-size:12px;color:#64748b;font-weight:400;margin-left:10px;">
                <?= $totalEmployees ?> <?= t('employees') ?>
            </span>
        </div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="monthlyChart" style="width:100%;height:300px"></canvas>
            </div>
        </div>
    </div>

    <!-- Xodimlar jadvali -->
    <div class="card">
        <div class="card-header"><?= t('employees_attendance') ?> (<?= $dateFrom ?> - <?= $dateTo ?>)</div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?= t('employee') ?></th>
                        <th><?= t('present') ?></th>
                        <th><?= t('late') ?></th>
                        <th><?= t('on_time') ?></th>
                        <th><?= t('early_left') ?></th>
                        <th><?= t('absent') ?></th>
                        <th><?= t('arrival_diff') ?></th>
                        <th><?= t('departure_diff') ?></th>
                        <th><?= t('overtime') ?></th>
                        <th><?= t('efficiency') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = 1; foreach ($employeeStats as $emp): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><strong><?= htmlspecialchars($emp['name']) ?></strong></td>
                    <td><span class="badge badge-success"><?= number_format($emp['present']) ?></span></td>
                    <td><span class="badge badge-warning"><?= $emp['late'] ?></span></td>
                    <td><span class="badge badge-success"><?= $emp['ontime'] ?></span></td>
                    <td><span class="badge badge-early"><?= $emp['early'] ?></span></td>
                    <td><span class="badge badge-danger"><?= $emp['absent'] ?></span></td>
                    <td><?= formatDiff($emp['total_late_minutes'] ?? 0) ?></td>
                    <td><?= formatDiff($emp['total_early_minutes'] ?? 0) ?></td>
                    <td><span class="badge badge-warning"><?= number_format($emp['overtime'], 1) ?></span></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:5px">
                            <div style="flex:1;background:#e2e8f0;border-radius:10px;height:6px;width:60px">
                                <div style="width:<?= $emp['attendance_rate'] ?>%;background:#10b981;height:6px;border-radius:10px"></div>
                            </div>
                            <span><?= $emp['attendance_rate'] ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot style="background:#f8fafc;font-weight:600">
                    <tr>
                        <td colspan="2"><?= t('total') ?></td>
                        <td><strong><?= number_format($totalPresentMonth) ?></strong></td>
                        <td><strong><?= number_format($totalLateMonth) ?></strong></td>
                        <td><strong><?= number_format($totalOntimeMonth) ?></strong></td>
                        <td><strong><?= number_format($totalEarlyMonth) ?></strong></td>
                        <td><strong><?= number_format($totalAbsentMonth) ?></strong></td>
                        <td><strong><?= formatDiff($totalLateMinutesMonth ?? 0) ?></strong></td>
                        <td><strong><?= formatDiff($totalEarlyMinutesMonth ?? 0) ?></strong></td>
                        <td><strong><?= number_format($totalOvertimeHoursMonth, 1) ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- CHART.JS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    window.addEventListener('load', function () {
        top.postMessage({hideSpinner: true}, '*')
    }, false);

    (function(){
        var ctx1 = document.getElementById('attendanceChart');
        if(ctx1) {
            new Chart(ctx1.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['<?= t('present') ?>', '<?= t('late') ?>', '<?= t('early_left') ?>', '<?= t('absent') ?>'],
                    datasets: [{
                        data: [<?= max(0, $chartPresent) ?>, <?= max(0, $chartLate) ?>, <?= max(0, $chartEarly) ?>, <?= max(0, $chartAbsent) ?>],
                        backgroundColor: ['#10b981', '#f97316', '#dc3545', '#94a3b8'],
                        borderWidth: 0,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: { size: 11 },
                                usePointStyle: true
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(c) {
                                    var total = <?= max(1, $chartTotal) ?>;
                                    return c.label + ': ' + c.raw + ' (' + Math.round(c.raw / total * 100) + '%)';
                                }
                            }
                        }
                    },
                    cutout: '65%'
                }
            });
        }

        var ctx2 = document.getElementById('monthlyChart');
        if(ctx2) {
            new Chart(ctx2.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chartLabels) ?>,
                    datasets: [
                        {
                            label: '<?= t('present') ?>',
                            data: <?= json_encode($chartPresentData) ?>,
                            backgroundColor: '#10b981',
                            borderRadius: 6,
                            barPercentage: 0.6
                        },
                        {
                            label: '<?= t('late') ?>',
                            data: <?= json_encode($chartLateData) ?>,
                            backgroundColor: '#f97316',
                            borderRadius: 6,
                            barPercentage: 0.6
                        },
                        {
                            label: '<?= t('absent') ?>',
                            data: <?= json_encode($chartAbsentData) ?>,
                            backgroundColor: '#ef4444',
                            borderRadius: 6,
                            barPercentage: 0.6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: { size: 11 },
                                usePointStyle: true
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#e2e8f0' }
                        },
                        x: {
                            ticks: {
                                font: { size: 9 },
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            });
        }
    })();
</script>
