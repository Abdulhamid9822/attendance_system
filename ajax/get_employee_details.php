<?php
// ajax/get_employee_details.php

require_once __DIR__ . '/../includes/session.php';
$sessionContext = $_GET['sid'] ?? $_POST['sid'] ?? null;
if (!is_string($sessionContext) || !preg_match('/^[a-f0-9]{32}$/', $sessionContext)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Sessiya konteksti noto‘g‘ri.']);
    exit;
}
startAppSession($sessionContext);
header('Content-Type: application/json');
if (($_SESSION['logged_in'] ?? false) !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sessiya yaroqsiz. Qayta kiring.']);
    exit;
}
require_once __DIR__ . '/../includes/config/database.php';
require_once __DIR__ . '/../includes/functions/branch_access.php';
require_once __DIR__ . '/../includes/functions/employee.php';
require_once __DIR__ . '/../includes/functions/access_token.php';

// Xatoliklarni log qilish
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Parametrlarni olish va validatsiya
$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// Employee ID tekshirish
if($employeeId <= 0) {
    echo json_encode([
        'success' => false, 
        'error' => 'Xodim ID noto\'g\'ri'
    ]);
    exit;
}

// Oy formatini tekshirish
if(!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode([
        'success' => false, 
        'error' => 'Oy formati noto\'g\'ri'
    ]);
    exit;
}

// Xodimni tekshirish
$employee = getEmployeeById($employeeId);
if(!$employee || empty($employee) || (string)($employee['account_name'] ?? '') !== (string)($_SESSION['account'] ?? $_SESSION['account_name'] ?? '')) {
    echo json_encode([
        'success' => false, 
        'error' => 'Xodim topilmadi'
    ]);
    exit;
}
try {
    assertBranchEmployeeAccess(
        $pdo,
        $employeeId,
        (string)($_SESSION['account'] ?? $_SESSION['account_name'] ?? '')
    );
} catch (RuntimeException $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Xodim topilmadi yoki filial ruxsati yo‘q']);
    exit;
}

// ============================================
// HAR BIR KUN UCHUN MAOSH TURINI OLISH
// ============================================
function getDailySalarySetting($employeeId, $date) {
    global $pdo;
    
    $month = date('Y-m', strtotime($date));
    $oyVaqt = $month . '-01';
    
    $defaultReturn = [
        'type' => 'fixed',
        'value' => 0,
        'daily_rate' => 0,
        'type_text' => 'Qat\'iy',
        'amount_text' => '0 so\'m',
        'poster_user_id' => null
    ];
    
    try {
        // Oylik sozlamalarni tekshirish
        $stmt = $pdo->prepare("SELECT oylik_maosh, foiz_qiymati, maosh_turi, poster_user_id, amal_qiladi 
                               FROM oylik_sozlamalar 
                               WHERE xodim_id = ? AND oy_vaqt = ? 
                               AND (amal_qiladi <= ? OR amal_qiladi IS NULL)
                               ORDER BY amal_qiladi DESC LIMIT 1");
        $stmt->execute([$employeeId, $oyVaqt, $date]);
        $setting = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($setting && !empty($setting)) {
            $daysInMonth = (int)date('t', strtotime($month . '-01'));
            
            if($setting['maosh_turi'] == 'fixed') {
                $dailyRate = $daysInMonth > 0 ? (float)$setting['oylik_maosh'] / $daysInMonth : 0;
                return [
                    'type' => 'fixed',
                    'value' => (float)$setting['oylik_maosh'],
                    'daily_rate' => $dailyRate,
                    'type_text' => 'Qat\'iy',
                    'amount_text' => number_format($setting['oylik_maosh']) . ' so\'m/oy',
                    'poster_user_id' => null
                ];
            } else {
                return [
                    'type' => 'percent',
                    'value' => (float)$setting['foiz_qiymati'],
                    'daily_rate' => 0,
                    'type_text' => 'Foizli',
                    'amount_text' => $setting['foiz_qiymati'] . '%',
                    'poster_user_id' => $setting['poster_user_id']
                ];
            }
        }
    } catch(Exception $e) {
        error_log("getDailySalarySetting error: " . $e->getMessage());
    }
    
    // Asosiy maosh (xodimlar jadvalidan)
    try {
        $stmt = $pdo->prepare("SELECT turi, oylik_miqdori, foiz_miqdori, poster_user_id FROM xodimlar WHERE id = ?");
        $stmt->execute([$employeeId]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($emp && !empty($emp)) {
            $daysInMonth = (int)date('t', strtotime($month . '-01'));
            
            if($emp['turi'] == 'fixed') {
                $dailyRate = $daysInMonth > 0 ? (float)$emp['oylik_miqdori'] / $daysInMonth : 0;
                return [
                    'type' => 'fixed',
                    'value' => (float)$emp['oylik_miqdori'],
                    'daily_rate' => $dailyRate,
                    'type_text' => 'Qat\'iy',
                    'amount_text' => number_format($emp['oylik_miqdori']) . ' so\'m/oy',
                    'poster_user_id' => null
                ];
            } else {
                return [
                    'type' => 'percent',
                    'value' => (float)$emp['foiz_miqdori'],
                    'daily_rate' => 0,
                    'type_text' => 'Foizli',
                    'amount_text' => $emp['foiz_miqdori'] . '%',
                    'poster_user_id' => $emp['poster_user_id']
                ];
            }
        }
    } catch(Exception $e) {
        error_log("getDailySalarySetting base salary error: " . $e->getMessage());
    }
    
    return $defaultReturn;
}

function getDailyCalculatedSalary($employeeId, $date) {
    $dailySetting = getDailySalarySetting($employeeId, $date);
    
    if (!is_array($dailySetting)) {
        return 0;
    }
    
    $type = isset($dailySetting['type']) ? $dailySetting['type'] : 'fixed';
    $dailyRate = isset($dailySetting['daily_rate']) ? $dailySetting['daily_rate'] : 0;
    $percentValue = isset($dailySetting['value']) ? $dailySetting['value'] : 0;
    $posterUserId = isset($dailySetting['poster_user_id']) ? $dailySetting['poster_user_id'] : null;
    
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT davomat_holati FROM davomat WHERE xodim_id = ? AND sana = ?");
        $stmt->execute([$employeeId, $date]);
        $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
        $status = isset($attendance['davomat_holati']) ? $attendance['davomat_holati'] : 'absent';
    } catch(Exception $e) {
        error_log("getDailyCalculatedSalary attendance error: " . $e->getMessage());
        $status = 'absent';
    }
    
    $daySalary = 0;
    
    if($type == 'fixed') {
        if(in_array($status, ['present', 'overtime', 'vacation', 'sick'])) {
            $daySalary = $dailyRate;
        } elseif($status == 'half_day') {
            $daySalary = $dailyRate / 2;
        }
    } else {
        if(in_array($status, ['present', 'half_day', 'overtime', 'vacation', 'sick']) && !empty($posterUserId)) {
            if (function_exists('getWaiterRevenue')) {
                $dailyRevenue = getWaiterRevenue($posterUserId, $date);
                $daySalary = $dailyRevenue * ($percentValue / 100);
            }
        }
    }
    
    return round($daySalary);
}

// ============================================
// OFITSIANT SAVDOSI (Poster API)
// ============================================
if (!function_exists('getWaiterRevenue')) {
    function getWaiterRevenue($posterUserId, $date) {
        $token = getAccessToken();
        if(!$token) return 0;
        
        if(is_array($token)) {
            $token = isset($token['token']) ? $token['token'] : (isset($token[0]) ? $token[0] : null);
        }
        
        if(!$token) return 0;
        
        $dateFrom = date('Ymd', strtotime($date));
        $dateTo = date('Ymd', strtotime($date));
        $domain = isset($_SESSION['account']) ? $_SESSION['account'] : 'truthpos';
        
        $url = "https://{$domain}.joinposter.com/api/dash.getWaitersSales?token=" . $token;
        $url .= "&dateFrom=" . $dateFrom;
        $url .= "&dateTo=" . $dateTo;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        
        if(curl_errno($ch)) {
            error_log("CURL Error: " . curl_error($ch));
            curl_close($ch);
            return 0;
        }
        
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if(isset($data['response']) && is_array($data['response'])) {
            foreach($data['response'] as $waiter) {
                if(isset($waiter['user_id']) && $waiter['user_id'] == $posterUserId) {
                    return isset($waiter['revenue']) ? $waiter['revenue'] / 100 : 0;
                }
            }
        }
        
        return 0;
    }
}

// ============================================
// BARCHA KUNLARNI KO'RSATISH (0 bilan ham)
// ============================================
$daysInMonth = (int)date('t', strtotime($month . '-01'));
$currentMonthNum = (int)date('m', strtotime($month . '-01'));

// Oy nomlari
$monthNames = [
    1 => 'Yanvar', 2 => 'Fevral', 3 => 'Mart', 4 => 'Aprel',
    5 => 'May', 6 => 'Iyun', 7 => 'Iyul', 8 => 'Avgust',
    9 => 'Sentabr', 10 => 'Oktabr', 11 => 'Noyabr', 12 => 'Dekabr'
];

// ============================================
// HTML GENERATSIYA
// ============================================
$html = '<div class="toggle-section">';
$html .= '<div class="toggle-section-title"><i class="fas fa-calendar-alt"></i> Kunlik ish haqi</div>';
$html .= '<div class="table-responsive">';
$html .= '<table class="toggle-table">';
$html .= '<thead>';
$html .= '<tr>';
$html .= '<th>#</th>';
$html .= '<th>Sana</th>';
$html .= '<th>Maosh turi</th>';
$html .= '<th>Summa</th>';
$html .= '</tr>';
$html .= '</thead><tbody>';

for($day = 1; $day <= $daysInMonth; $day++) {
    $date = $month . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
    $salary = getDailyCalculatedSalary($employeeId, $date);
    $salarySetting = getDailySalarySetting($employeeId, $date);
    
    // Sana format
    $dayName = $day . ' ' . $monthNames[$currentMonthNum];
    
    // Maosh turi matni
    $typeText = $salarySetting['type_text'];
    $amountText = $salarySetting['amount_text'];
    
    $rowClass = ($day % 2 == 0) ? 'even-row' : 'odd-row';
    
    // Agar kelmagan bo'lsa (salary = 0 va status absent)
    try {
        $stmt = $pdo->prepare("SELECT davomat_holati FROM davomat WHERE xodim_id = ? AND sana = ?");
        $stmt->execute([$employeeId, $date]);
        $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
        $status = isset($attendance['davomat_holati']) ? $attendance['davomat_holati'] : 'absent';
    } catch(Exception $e) {
        $status = 'absent';
    }
    
    $isAbsent = ($status == 'absent' && $salary == 0);
    
    $html .= '<tr class="' . $rowClass . '">';
    $html .= '<td style="text-align:center; width:40px;">' . $day . '</td>';
    $html .= '<td>' . htmlspecialchars($dayName) . '</td>';
    $html .= '<td class="salary-type-cell">';
    $html .= '<span class="salary-type-badge ' . ($salarySetting['type'] == 'fixed' ? 'type-fixed' : 'type-percent') . '">';
    $html .= $typeText . ' (' . $amountText . ')';
    $html .= '</span>';
    $html .= '</td>';
    
    if($isAbsent) {
        $html .= '<td class="amount-absent">0 so\'m (Kelmagan)</span></td>';
    } elseif($salary > 0) {
        $html .= '<td class="amount-positive">' . number_format($salary) . ' so\'m</span></td>';
    } else {
        $html .= '<td class="amount-zero">0 so\'m</span></td>';
    }
    
    $html .= '</tr>';
}

$html .= '</tbody>';
$html .= '</table>';
$html .= '</div>';
$html .= '</div>';

// Bonus va jarimalar
try {
    $stmt = $pdo->prepare("SELECT turi, miqdor, izoh, created_at FROM bonus_jarima 
                           WHERE xodim_id = ? AND oy_vaqt = ?");
    $monthStart = $month . '-01';
    $stmt->execute([$employeeId, $monthStart]);
    $bonusItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    error_log("Bonus query error: " . $e->getMessage());
    $bonusItems = [];
}

if(count($bonusItems) > 0) {
    $html .= '<div class="toggle-section">';
    $html .= '<div class="toggle-section-title"><i class="fas fa-gift"></i> Bonus va jarimalar</div>';
    $html .= '<div class="table-responsive">';
    $html .= '<table class="toggle-table">';
    $html .= '<thead>';
    $html .= '<tr>';
    $html .= '<th>#</th>';
    $html .= '<th>Sana</th>';
    $html .= '<th>Turi</th>';
    $html .= '<th>Miqdor</th>';
    $html .= '<th>Izoh</th>';
    $html .= '</tr>';
    $html .= '</thead><tbody>';
    
    foreach($bonusItems as $index => $b) {
        $bonusDate = '';
        if(isset($b['created_at']) && $b['created_at']) {
            $dayNum = (int)date('d', strtotime($b['created_at']));
            $bonusDate = $dayNum . ' ' . $monthNames[$currentMonthNum];
        } else {
            $bonusDate = '-';
        }
        
        $typeText = $b['turi'] == 'bonus' ? '🎁 Bonus' : '⚠️ Jarima';
        $typeClass = $b['turi'] == 'bonus' ? 'bonus-row' : 'penalty-row';
        $amount = $b['turi'] == 'bonus' ? '+' . number_format($b['miqdor']) : '-' . number_format($b['miqdor']);
        
        $html .= '<tr class="' . $typeClass . '">';
        $html .= '<td style="text-align:center;">' . ($index + 1) . '</td>';
        $html .= '<td>' . htmlspecialchars($bonusDate) . '</td>';
        $html .= '<td>' . $typeText . '</td>';
        $html .= '<td>' . $amount . ' so\'m</span></td>';
        $html .= '<td>' . htmlspecialchars($b['izoh'] ?? '-') . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>';
    $html .= '</div>';
}

// JSON javob
echo json_encode([
    'success' => true,
    'employee_name' => htmlspecialchars($employee['ismi']),
    'html' => $html
]);

exit;
?>