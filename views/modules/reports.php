<?php 
// modules/reports.php - TO'LIQ (BADGE HAMMA TURLARNI KO'RSATADI)
// ============================================================

// ============================================================
// OYNI ANIQLASH (navbar date pickerdan)  <--- QO'SHIMCHA
// ============================================================
if (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) {
    $month = $_GET['month'];
    $dateFrom = $month . '-01';
    $dateTo = date('Y-m-t', strtotime($dateFrom));
    $GLOBALS['dateFrom'] = $dateFrom;
    $GLOBALS['dateTo'] = $dateTo;
    $reportAccount = $_SESSION['account'] ?? $_SESSION['account_name'] ?? '';
    if ($reportAccount !== '' && $month > date('Y-m')) {
        autoUpdateMonthlySettings($reportAccount, $month);
    }
}

global $pdo, $currencySymbol, $dateFrom, $dateTo, $token, $currentLang;
require_once __DIR__ . '/../../includes/functions/employee_filter_bar.php';

// ============================================
// 1. ASOSIY O'ZGARUVCHILAR
// ============================================
$currencySymbol = $currencySymbol ?? 'so\'m';
$dateFrom = $dateFrom ?? date('Y-m-01');
$dateTo = $dateTo ?? date('Y-m-d');
$token = $_SESSION['token'] ?? $_SESSION['access_token'] ?? '';
$currentLang = $currentLang ?? 'uz';
$currencySymbol = $_SESSION['currency_symbol'] ?? 'so\'m';
$currencyCode = strtoupper($_SESSION['currency_code'] ?? 'UZS');

// ============================================
// 1.1 CSRF TOKEN
// ============================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// QIDIRUV VA PAGINATION
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$itemsPerPage = 30;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if($currentPage < 1) $currentPage = 1;

// Oylar nomlari
$months_uz = ['Yanvar', 'Fevral', 'Mart', 'Aprel', 'May', 'Iyun', 'Iyul', 'Avgust', 'Sentabr', 'Oktabr', 'Noyabr', 'Dekabr'];
$months_ru = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
$months = $currentLang == 'ru' ? $months_ru : $months_uz;

// ============================================
// 2. ACCOUNT NI ANIQLASH
// ============================================
$account_name = $_SESSION['account'] ?? $_SESSION['account_name'] ?? '';

if(empty($account_name)) {
    try {
        $sid = $_GET['sid'] ?? '';
        if(!empty($sid)) {
            $stmt = $pdo->prepare("SELECT account_domain, account_name FROM poster_tokens WHERE MD5(CONCAT('poster_', account_id)) = ?");
            $stmt->execute([$sid]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if($account) {
                $account_name = $account['account_domain'] ?? $account['account_name'] ?? '';
                $_SESSION['account'] = $account_name;
            }
        }
    } catch(Exception $e) {
        error_log("Account aniqlash xatosi: " . $e->getMessage());
    }
}

$months_uz = ['Yanvar', 'Fevral', 'Mart', 'Aprel', 'May', 'Iyun', 'Iyul', 'Avgust', 'Sentabr', 'Oktabr', 'Noyabr', 'Dekabr'];
$months_ru = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
$months = $currentLang == 'ru' ? $months_ru : $months_uz;
$GLOBALS['months'] = $months;

// ============================================
// 3. BONUS/JARIMA QO'SHISH, TAHRIRLASH, O'CHIRISH (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    try {
        $postedCsrf = $_POST['csrf_token'] ?? '';
        if (empty($postedCsrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $postedCsrf)) {
            throw new Exception('CSRF token noto\'g\'ri yoki muddati o\'tgan. Sahifani yangilang.');
        }

        if (empty($account_name)) {
            throw new Exception('Account aniqlanmadi. Tizimga qayta kiring.');
        }

        $action = $_POST['action'];
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $oyVaqt = $_POST['oy_vaqt'] ?? date('Y-m-01');
        $turi = $_POST['turi'] ?? 'bonus';
        $turi = in_array($turi, ['bonus', 'penalty'], true) ? $turi : 'bonus';
        $miqdor = (float)($_POST['miqdor'] ?? 0);
        $izoh = trim($_POST['izoh'] ?? '');
        $sana = $_POST['sana'] ?? date('Y-m-d');
        $createdBy = $_SESSION['user_id'] ?? $_SESSION['user_name'] ?? 'system';

        if ($employeeId <= 0) {
            throw new Exception('Xodim aniqlanmadi!');
        }
        $ownerSql = "SELECT id FROM xodimlar WHERE id = ? AND account_name = ?";
        $ownerParams = [$employeeId, $account_name];
        if (isBranchUser()) {
            $ownerSql .= " AND branch_spot_id = ?";
            $ownerParams[] = currentBranchSpotId();
        }
        $ownerCheckStmt = $pdo->prepare($ownerSql);
        $ownerCheckStmt->execute($ownerParams);
        if (!$ownerCheckStmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('Xodim topilmadi yoki sizga tegishli emas!');
        }

        if ($action === 'add') {
            if ($miqdor <= 0) {
                throw new Exception('Miqdor 0 dan katta bo\'lishi kerak!');
            }

            $stmt = $pdo->prepare("INSERT INTO bonus_jarima 
                (xodim_id, oy_vaqt, turi, miqdor, izoh, sana, created_by, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([$employeeId, $oyVaqt, $turi, $miqdor, $izoh, $sana, $createdBy]);

            $response['success'] = true;
            $response['message'] = $turi == 'bonus' ? 'Bonus qo\'shildi' : 'Jarima qo\'shildi';
            $response['id'] = $pdo->lastInsertId();

        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Yozuv topilmadi!');
            }

            $recordCheckStmt = $pdo->prepare("SELECT id FROM bonus_jarima WHERE id = ? AND xodim_id = ?");
            $recordCheckStmt->execute([$id, $employeeId]);
            if (!$recordCheckStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('Yozuv topilmadi!');
            }

            $stmt = $pdo->prepare("UPDATE bonus_jarima 
                SET turi = ?, miqdor = ?, izoh = ?, sana = ?, updated_at = NOW() 
                WHERE id = ? AND xodim_id = ?");
            $stmt->execute([$turi, $miqdor, $izoh, $sana, $id, $employeeId]);

            $response['success'] = true;
            $response['message'] = 'Yozuv tahrirlandi';

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Yozuv topilmadi!');
            }

            $recordCheckStmt = $pdo->prepare("SELECT id FROM bonus_jarima WHERE id = ? AND xodim_id = ?");
            $recordCheckStmt->execute([$id, $employeeId]);
            if (!$recordCheckStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('Yozuv topilmadi!');
            }

            $stmt = $pdo->prepare("DELETE FROM bonus_jarima WHERE id = ? AND xodim_id = ?");
            $stmt->execute([$id, $employeeId]);

            $response['success'] = true;
            $response['message'] = 'Yozuv o\'chirildi';
        } else {
            throw new Exception('Noma\'lum amal!');
        }
    } catch(Exception $e) {
        $response['message'] = $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

// ============================================
// 4. XODIMLARNI OLISH
// ============================================
if(empty($account_name) || empty($account_id)) {
    echo '<div class="error" style="margin:20px; padding:20px; background:#f8d7da; border-radius:8px;">
        ❌ Iltimos, tizimga qayta kiring. Account topilmadi.
    </div>';
    $employees = [];
    $totalEmployees = 0;
    $totalPages = 1;
} else {
    try {
        // Asosiy so'rov - faqat account_id bo'yicha
        $sql = "SELECT id, poster_id, poster_user_id, account_id, ismi, telefoni, emaili, lavozimi, holati, yaratilgan 
                FROM xodimlar 
                WHERE holati = 'active' AND account_id = ?";
        $params = [$account_id];
        if (isBranchUser()) {
            $sql .= " AND branch_spot_id = ?";
            $params[] = currentBranchSpotId();
        }
        [$filterSql, $filterParams] = employeeFilterSql();
        $sql .= $filterSql;
        $params = array_merge($params, $filterParams);
        if(!empty($searchQuery)) {
            $searchTerm = '%' . $searchQuery . '%';
            $sql .= " AND (ismi LIKE ? OR id IN (
                SELECT DISTINCT xodim_id 
                FROM oylik_sozlamalar 
                WHERE oylik_maosh LIKE ? 
                OR maosh_turi LIKE ? 
                OR foiz_qiymati LIKE ? 
                OR soatlik_stavka LIKE ?
            ))";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Umumiy sonini hisoblash
        $countSql = "SELECT COUNT(*) FROM (" . $sql . ") as subquery";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalEmployees = $countStmt->fetchColumn();
        $totalPages = ceil($totalEmployees / $itemsPerPage);
        $offset = ($currentPage - 1) * $itemsPerPage;

        // Asosiy ma'lumotlarni olish
        $sql .= " ORDER BY ismi LIMIT ? OFFSET ?";
        $params[] = $itemsPerPage;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch(Exception $e) {
        error_log("Xodimlarni olish xatosi: " . $e->getMessage());
        echo '<div class="error" style="margin:20px; padding:20px; background:#f8d7da; border-radius:8px; border:1px solid #dc3545;">
            ❌ Xodimlarni olishda xatolik yuz berdi. Iltimos, qayta urinib ko\'ring.
        </div>';
        $employees = [];
        $totalEmployees = 0;
        $totalPages = 1;
    }
}

// ============================================
// 5. ASOSIY O'ZGARUVCHILAR
// ============================================
$currentMonth = date('Y-m', strtotime($dateFrom));
$daysInMonth = (int)date('t', strtotime($currentMonth . '-01'));
$standardWorkHours = 8;

// ============================================
// 6. FUNKSIYALAR
// ============================================

// ============================================================
// 6.1 OYLIK SOZLAMALARNI OLISH - maosh_turi ARRAY BILAN
// ============================================================
function getOylikSozlamalar($employeeIds, $month) {
    global $pdo, $currencySymbol;
    if(empty($employeeIds)) return [];

    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $oyVaqt = $month . '-01';
    $daysInMonth = date('t', strtotime($month . '-01'));

    try {
        $stmt = $pdo->prepare("SELECT * FROM oylik_sozlamalar 
                               WHERE xodim_id IN ({$placeholders}) AND oy_vaqt = ? 
                               ORDER BY xodim_id ASC, amal_qiladi ASC, id ASC");
        $params = array_merge($employeeIds, [$oyVaqt]);
        $stmt->execute($params);

        $result = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $xodimId = $row['xodim_id'];

            $maoshTurlari = json_decode($row['maosh_turi'], true);
            if (!is_array($maoshTurlari) || empty($maoshTurlari)) {
                $maoshTurlari = ['fixed'];
            }
            $row['maosh_turi_array'] = $maoshTurlari;
            
            $displayParts = [];
            $dailyDisplayRaw = null;
            
            if (in_array('fixed', $maoshTurlari) && isset($row['oylik_maosh'])) {
                $dailyDisplayRaw = $daysInMonth > 0 ? $row['oylik_maosh'] / $daysInMonth : null;
                $displayParts[] = number_format($dailyDisplayRaw ?? 0) . ' ' . $currencySymbol;
            }
            
            if (in_array('shift', $maoshTurlari) && isset($row['smena_summa'])) {
                $displayParts[] = number_format($row['smena_summa']) . ' ' . $currencySymbol . '/' . t('shift');
            }
            
            if (in_array('hourly', $maoshTurlari) && isset($row['soatlik_stavka'])) {
                $displayParts[] = number_format($row['soatlik_stavka']) . ' ' . $currencySymbol . '/soat';
            }
            
            if (in_array('percent', $maoshTurlari) && isset($row['foiz_qiymati'])) {
                $displayParts[] = $row['foiz_qiymati'] . '%';
            }
            
            $row['daily_display'] = !empty($displayParts) ? implode(' + ', $displayParts) : '-';
            $row['daily_display_raw'] = $dailyDisplayRaw;

            if(!isset($result[$xodimId])) {
                $result[$xodimId] = [];
            }
            $result[$xodimId][] = $row;
        }

        foreach ($result as $xodimId => $settings) {
            $lastSetting = end($settings);
            $result[$xodimId]['_meta'] = [
                'last_daily_display' => $lastSetting['daily_display'] ?? null,
                'last_daily_display_raw' => $lastSetting['daily_display_raw'] ?? null,
                'last_maosh_turi_array' => $lastSetting['maosh_turi_array'] ?? ['fixed'],
                'last_maosh_turi' => $lastSetting['maosh_turi'] ?? 'fixed',
                'last_amal_qiladi' => $lastSetting['amal_qiladi'] ?? null,
                'last_smena_summa' => $lastSetting['smena_summa'] ?? 0,
            ];
        }

        return $result;
    } catch(Exception $e) {
        error_log("getOylikSozlamalar error: " . $e->getMessage());
        return [];
    }
}
// ============================================================
// 6.2 ATTENDANCE DATA
// ============================================================
function getAttendanceData($employeeIds, $month) {
    global $pdo, $account_name;
    if(empty($employeeIds)) return [];

    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $monthStart = $month . '-01';
    $monthEnd = $month . '-' . date('t', strtotime($monthStart));

    try {
        $stmt = $pdo->prepare("SELECT 
                                employee_id, 
                                auth_date, 
                                in_time,
                                out_time,
                                in_late_minutes,
                                out_early_minutes,
                                out_late_minutes,
                                total_work_hours
                              FROM attendance_data 
                              WHERE employee_id IN ({$placeholders}) 
                              AND auth_date BETWEEN ? AND ?
                              AND account_name = ?
                              ORDER BY auth_date ASC");

        $params = array_merge($employeeIds, [$monthStart, $monthEnd, $account_name]);
        $stmt->execute($params);

        $result = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $empId = $row['employee_id'];
            $date = $row['auth_date'];

            if(!isset($result[$empId][$date])) {
                $result[$empId][$date] = [
                    'check_in' => $row['in_time'],
                    'check_out' => $row['out_time'],
                    'in_late_minutes' => (int)($row['in_late_minutes'] ?? 0),
                    'out_early_minutes' => (int)($row['out_early_minutes'] ?? 0),
                    'out_late_minutes' => (int)($row['out_late_minutes'] ?? 0),
                    'total_work_hours' => (float)($row['total_work_hours'] ?? 0),
                    'status' => 'absent'
                ];

                if($row['in_time'] && $row['out_time']) {
                    $result[$empId][$date]['status'] = 'present';
                } elseif($row['in_time']) {
                    $result[$empId][$date]['status'] = 'partial_in';
                } elseif($row['out_time']) {
                    $result[$empId][$date]['status'] = 'partial_out';
                }
            }
        }
        return $result;
    } catch(Exception $e) {
        error_log("getAttendanceData error: " . $e->getMessage());
        return [];
    }
}

// ============================================================
// 6.3 BONUS DATA
// ============================================================
function getBonusData($employeeIds, $month) {
    global $pdo;
    if(empty($employeeIds)) return [];

    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $oyVaqt = $month . '-01';

    try {
        $stmt = $pdo->prepare("SELECT id, xodim_id, turi, miqdor, izoh, sana, created_by, created_at 
                               FROM bonus_jarima 
                               WHERE xodim_id IN ({$placeholders}) AND oy_vaqt = ?
                               ORDER BY created_at DESC");
        $params = array_merge($employeeIds, [$oyVaqt]);
        $stmt->execute($params);

        $result = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['xodim_id']][] = $row;
        }
        return $result;
    } catch(Exception $e) {
        error_log("getBonusData error: " . $e->getMessage());
        return [];
    }
}

// ============================================================
// 6.4 PREVIOUS BALANCE
// ============================================================
function getPreviousBalance($employeeIds, $month) {
    global $pdo;
    if(empty($employeeIds)) return [];

    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $prevMonth = date('Y-m', strtotime($month . '-01 -1 month')) . '-01';

    try {
        // Xodim yaratilgan oydan oldingi balanslar hisobga olinmaydi, yaratilgan oyda esa oldingi qarz bo'lmaydi
        $createdStmt = $pdo->prepare("SELECT id FROM xodimlar
                                      WHERE id IN ({$placeholders})
                                      AND yaratilgan IS NOT NULL
                                      AND DATE_FORMAT(yaratilgan, '%Y-%m') >= ?");
        $createdStmt->execute(array_merge($employeeIds, [$month]));
        $notYetCreated = array_flip($createdStmt->fetchAll(PDO::FETCH_COLUMN));
        $employeeIds = array_values(array_filter($employeeIds, fn($id) => !isset($notYetCreated[$id])));
        if(empty($employeeIds)) return [];
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

        // Eng yaqin oldingi oyning saqlangan balansi (nolga teng bo'lsa ham, qarz yopilgan bo'ladi)
        $stmt = $pdo->prepare("SELECT s.xodim_id, s.keyingi_oyga_balansi
                               FROM oylik_sozlamalar s
                               INNER JOIN xodimlar x ON x.id = s.xodim_id
                               WHERE s.xodim_id IN ({$placeholders})
                               AND s.balans_saqlangan = 1
                               AND s.oy_vaqt < ?
                               AND (x.yaratilgan IS NULL OR s.oy_vaqt >= DATE_FORMAT(x.yaratilgan, '%Y-%m-01'))
                               AND s.oy_vaqt = (SELECT MAX(s2.oy_vaqt) FROM oylik_sozlamalar s2
                                                WHERE s2.xodim_id = s.xodim_id AND s2.balans_saqlangan = 1 AND s2.oy_vaqt < ?
                                                AND (x.yaratilgan IS NULL OR s2.oy_vaqt >= DATE_FORMAT(x.yaratilgan, '%Y-%m-01')))
                               ORDER BY s.amal_qiladi ASC, s.id ASC");
        $stmt->execute(array_merge($employeeIds, [$month . '-01', $month . '-01']));
        $saved = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $saved[$row['xodim_id']] = (float)$row['keyingi_oyga_balansi'];
        }
        if(!empty($saved)) {
            return $saved;
        }

        $stmt = $pdo->prepare("SELECT DISTINCT xodim_id, keyingi_oyga_balansi as qarzdorlik
                               FROM oylik_sozlamalar 
                               WHERE xodim_id IN ({$placeholders}) 
                               AND oy_vaqt = ? 
                               AND keyingi_oyga_balansi IS NOT NULL 
                               AND keyingi_oyga_balansi != 0");
        $params = array_merge($employeeIds, [$prevMonth]);
        $stmt->execute($params);

        $result = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['xodim_id']] = (float)($row['qarzdorlik'] ?? 0);
        }

        if(empty($result)) {
            $stmt = $pdo->prepare("SELECT DISTINCT xodim_id, qarzdorlik 
                                   FROM oylik_sozlamalar 
                                   WHERE xodim_id IN ({$placeholders}) 
                                   AND oy_vaqt = ? 
                                   AND qarzdorlik IS NOT NULL 
                                   AND qarzdorlik != 0");
            $params = array_merge($employeeIds, [$prevMonth]);
            $stmt->execute($params);

            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result[$row['xodim_id']] = (float)($row['qarzdorlik'] ?? 0);
            }
        }

        if(empty($result)) {
            $prevPrevMonth = date('Y-m', strtotime($month . '-01 -2 month')) . '-01';
            $stmt = $pdo->prepare("SELECT DISTINCT xodim_id, qarzdorlik 
                                   FROM oylik_sozlamalar 
                                   WHERE xodim_id IN ({$placeholders}) 
                                   AND oy_vaqt = ? 
                                   AND qarzdorlik IS NOT NULL 
                                   AND qarzdorlik != 0");
            $params = array_merge($employeeIds, [$prevPrevMonth]);
            $stmt->execute($params);

            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result[$row['xodim_id']] = (float)($row['qarzdorlik'] ?? 0);
            }
        }

        return $result;
    } catch(Exception $e) {
        error_log("getPreviousBalance error: " . $e->getMessage());
        return [];
    }
}

// ============================================================
// 6.5 SAVE BALANCE
// ============================================================
function saveBalance($employeeId, $month, $balance) {
    global $pdo;
    $oyVaqt = $month . '-01';

    try {
        $stmt = $pdo->prepare("SELECT id FROM oylik_sozlamalar WHERE xodim_id = ? AND oy_vaqt = ? ORDER BY amal_qiladi DESC, id DESC LIMIT 1");
        $stmt->execute([$employeeId, $oyVaqt]);
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);

        if(!$exists) {
            $accountForBalance = $_SESSION['account'] ?? $_SESSION['account_name'] ?? '';
            if($accountForBalance !== '') {
                autoUpdateMonthlySettings($accountForBalance, $month);
                $stmt->execute([$employeeId, $oyVaqt]);
                $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        if($exists) {
            $stmt = $pdo->prepare("UPDATE oylik_sozlamalar 
                                   SET qarzdorlik = ?, 
                                       keyingi_oyga_balansi = ?,
                                       balans_saqlangan = 1,
                                       updated_at = NOW() 
                                   WHERE id = ?");
            $stmt->execute([$balance, $balance, $exists['id']]);
            return true;
        }

        return false;
    } catch(Exception $e) {
        error_log("saveBalance error for employee $employeeId: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// 6.6 GET WAITERS REVENUE
// ============================================================
function getWaitersRevenue() {
    global $token, $account_name, $dateFrom, $dateTo;
    if(!$token) return [];
    if(is_array($token)) $token = $token['token'] ?? $token[0] ?? null;
    if(!$token) return [];

    $apiFrom = str_replace('-', '', $dateFrom);
    $apiTo = str_replace('-', '', $dateTo);

    try {
        $url = "https://{$account_name}.joinposter.com/api/dash.getWaitersSales?token=" . $token . "&dateFrom=" . $apiFrom . "&dateTo=" . $apiTo;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $revenues = [];
        if(isset($data['response']) && is_array($data['response'])) {
            foreach($data['response'] as $waiter) {
                $revenues[$waiter['user_id']] = $waiter['revenue'] / 100;
            }
        }
        return $revenues;
    } catch(Exception $e) {
        error_log("getWaitersRevenue error: " . $e->getMessage());
        return [];
    }
}

// ============================================================
// 6.6b PARALLEL HTTP FETCH
// ============================================================
function fetchUrlsParallel($urls, $maxConcurrent = 10, $timeout = 20) {
    $results = [];
    if (empty($urls)) return $results;

    $urlChunks = array_chunk($urls, $maxConcurrent, true);

    foreach ($urlChunks as $chunk) {
        $multiHandle = curl_multi_init();
        $curlHandles = [];

        foreach ($chunk as $key => $url) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[$key] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running) {
                curl_multi_select($multiHandle, 1.0);
            }
        } while ($running && $status == CURLM_OK);

        foreach ($curlHandles as $key => $ch) {
            $response = curl_multi_getcontent($ch);
            $errno = curl_errno($ch);
            $error = $errno ? curl_error($ch) : '';

            $results[$key] = [
                'success'  => ($errno === 0),
                'response' => $response,
                'errno'    => $errno,
                'error'    => $error
            ];

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);
    }

    return $results;
}

// ============================================================
// 6.7 GET TRANSACTIONS
// ============================================================
function getTransactions($posterIds, $employeeCreatedDates = [], $posterUserIds = []) {
    global $token, $account_name, $dateFrom, $dateTo;

    if(!isset($GLOBALS['transactionsFetchFailed'])) {
        $GLOBALS['transactionsFetchFailed'] = [];
    }
    $GLOBALS['posterTransactionDiagnostics'] = [];
    $transactionFingerprint = static function (array $transaction): string {
        return 'data:' . hash('sha256', json_encode([
            $transaction['date'] ?? $transaction['transaction_date'] ?? '',
            (string)($transaction['amount'] ?? $transaction['amount_to'] ?? ''),
            (string)($transaction['category_id'] ?? ''),
            (string)($transaction['user_id'] ?? $transaction['employee_id'] ?? $transaction['poster_user_id'] ?? ''),
            (string)($transaction['comment'] ?? $transaction['description'] ?? $transaction['note'] ?? ''),
            (string)($transaction['payment_method'] ?? ''),
            (string)($transaction['payment_system'] ?? ''),
            (string)($transaction['name'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    };

    if(empty($posterIds)) return [];
    if(is_array($token)) $token = $token['token'] ?? $token[0] ?? null;
    if(empty($token)) {
        foreach (array_keys($posterIds) as $empId) {
            $GLOBALS['transactionsFetchFailed'][$empId] = true;
        }
        error_log('Poster tranzaksiyalari olinmadi: access token topilmadi.');
        return [];
    }

    $apiFrom = str_replace('-', '', $dateFrom);
    $apiTo = str_replace('-', '', $dateTo);
    $apiDomain = trim((string)($_SESSION['account_domain'] ?? '')) ?: $account_name;
    $apiDomain = preg_replace('/[^a-zA-Z0-9.-]/', '', $apiDomain);
    if ($apiDomain === '') {
        error_log('Poster tranzaksiyalari olinmadi: account domain aniqlanmadi.');
        foreach (array_keys($posterIds) as $empId) {
            $GLOBALS['transactionsFetchFailed'][$empId] = true;
        }
        return [];
    }

    $urls = [];
    foreach ($posterIds as $empId => $posterCategoryId) {
        $posterCategoryId = filter_var($posterCategoryId, FILTER_VALIDATE_INT);
        if ($posterCategoryId === false || $posterCategoryId <= 0) {
            $GLOBALS['transactionsFetchFailed'][$empId] = true;
            $GLOBALS['posterTransactionDiagnostics'][$empId] = [
                'category_id' => (string)$posterCategoryId,
                'response_count' => 0,
                'mapped_count' => 0,
                'error' => 'invalid_category_id',
            ];
            error_log("getTransactions: xodim $empId uchun Poster kategoriya ID yaroqsiz.");
            continue;
        }

        $urls[$empId] = "https://{$apiDomain}.joinposter.com/api/finance.getTransactions?"
            . http_build_query([
                'token' => $token,
                'dateFrom' => $apiFrom,
                'dateTo' => $apiTo,
                'category_id' => $posterCategoryId,
            ]);
    }

    if (empty($urls)) return [];

    $fetchResults = fetchUrlsParallel($urls, 10, 30);
    $results = [];
    foreach ($urls as $empId => $url) {
        $fetch = $fetchResults[$empId] ?? ['success' => false, 'response' => null, 'error' => 'no result'];
        if (!$fetch['success']) {
            error_log("getTransactions curl xatosi ($empId): " . $fetch['error']);
            $GLOBALS['transactionsFetchFailed'][$empId] = true;
            $results[$empId] = [];
            $GLOBALS['posterTransactionDiagnostics'][$empId] = [
                'category_id' => (string)filter_var($posterIds[$empId], FILTER_VALIDATE_INT),
                'response_count' => 0,
                'mapped_count' => 0,
                'error' => 'request_failed',
            ];
            continue;
        }

        $data = json_decode($fetch['response'], true);
        if (!is_array($data) || !isset($data['response']) || !is_array($data['response'])) {
            error_log("getTransactions kutilmagan javob ($empId): " . substr((string)$fetch['response'], 0, 300));
            $GLOBALS['transactionsFetchFailed'][$empId] = true;
            $results[$empId] = [];
            $GLOBALS['posterTransactionDiagnostics'][$empId] = [
                'category_id' => (string)filter_var($posterIds[$empId], FILTER_VALIDATE_INT),
                'response_count' => 0,
                'mapped_count' => 0,
                'error' => 'unexpected_response',
            ];
            continue;
        }

        $responseCategoryIds = [];
        $responsePosterUserIds = [];
        foreach ($data['response'] as $responseTransaction) {
            if (is_array($responseTransaction)) {
                if (isset($responseTransaction['category_id'])) {
                    $responseCategoryIds[(string)$responseTransaction['category_id']] = true;
                }
                $responsePosterUserId = $responseTransaction['user_id']
                    ?? $responseTransaction['employee_id']
                    ?? $responseTransaction['poster_user_id']
                    ?? null;
                if ($responsePosterUserId !== null) {
                    $responsePosterUserIds[(string)$responsePosterUserId] = true;
                }
            }
        }

        $transactions = [];
        $seenTransactions = [];
        foreach ($data['response'] as $t) {
            if (!is_array($t) || (string)($t['delete'] ?? '0') === '1') {
                continue;
            }
            $amount = $t['amount'] ?? $t['amount_to'] ?? null;
            if (!is_numeric($amount)) {
                continue;
            }
            $fingerprint = $transactionFingerprint($t);
            if (isset($seenTransactions[$fingerprint])) {
                continue;
            }
            $seenTransactions[$fingerprint] = true;

            $transactionDate = $t['transaction_date'] ?? $t['date'] ?? '';

            $comment = $t['comment'] ?? $t['description'] ?? $t['note'] ?? '';
            $paymentMethod = $t['payment_method'] ?? '';
            $paymentSystem = $t['payment_system'] ?? '';
            $fullComment = $comment;
            if (!empty($paymentMethod)) {
                $fullComment = empty($fullComment) ? $paymentMethod : $paymentMethod . ' | ' . $fullComment;
            }
            if (!empty($paymentSystem)) {
                $fullComment = empty($fullComment) ? $paymentSystem : $fullComment . ' (' . $paymentSystem . ')';
            }

            $transactions[] = [
                'date' => $transactionDate,
                'amount' => abs((float)$amount) / 100,
                'type' => 'payment',
                'comment' => $comment,
                'full_comment' => $fullComment,
                'method' => $paymentMethod,
                'system' => $paymentSystem,
                'name' => $t['name'] ?? '',
                '_dedupe_key' => $fingerprint,
            ];
        }

        usort($transactions, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        $results[$empId] = $transactions;
        $GLOBALS['posterTransactionDiagnostics'][$empId] = [
            'category_id' => (string)filter_var($posterIds[$empId], FILTER_VALIDATE_INT),
            'poster_user_id' => (string)($posterUserIds[$empId] ?? ''),
            'response_count' => count($data['response']),
            'mapped_count' => count($transactions),
            'response_category_ids' => implode(', ', array_slice(array_keys($responseCategoryIds), 0, 10)),
            'response_poster_user_ids' => implode(', ', array_slice(array_keys($responsePosterUserIds), 0, 10)),
            'error' => '',
        ];
    }

    $employeesWithoutTransactions = [];
    foreach ($posterIds as $empId => $posterCategoryId) {
        if (empty($results[$empId])) {
            $employeesWithoutTransactions[$empId] = (string)$posterCategoryId;
        }
    }

    if ($employeesWithoutTransactions !== []) {
        $categoryNamesUrl = "https://{$apiDomain}.joinposter.com/api/finance.getCategories?"
            . http_build_query(['token' => $token]);
        $categoryNamesResults = fetchUrlsParallel(['categories' => $categoryNamesUrl], 1, 30);
        $categoryNamesFetch = $categoryNamesResults['categories'] ?? [
            'success' => false,
            'response' => null,
            'error' => 'no result',
        ];
        $fallbackCategoryOwners = $employeesWithoutTransactions;
        $normalizeCategoryName = static function ($name) {
            $name = trim((string)$name);
            return function_exists('mb_strtolower')
                ? mb_strtolower($name, 'UTF-8')
                : strtolower($name);
        };
        $employeesByCategoryName = [];
        foreach ($posterIds as $empId => $categoryId) {
            $employeeName = trim((string)($GLOBALS['reportEmployeeNames'][$empId] ?? ''));
            if ($employeeName === '') {
                continue;
            }
            $employeesByCategoryName[$normalizeCategoryName($employeeName)][] = $empId;

            $posterUserId = $posterUserIds[$empId] ?? null;
            if ($posterUserId !== null && $posterUserId !== '') {
                $suffixedName = $employeeName . ' (Poster ID: ' . $posterUserId . ')';
                $employeesByCategoryName[$normalizeCategoryName($suffixedName)][] = $empId;
            }
        }

        if ($categoryNamesFetch['success']) {
            $categoryNamesData = json_decode($categoryNamesFetch['response'], true);
            if (is_array($categoryNamesData) && is_array($categoryNamesData['response'] ?? null)) {
                foreach ($categoryNamesData['response'] as $category) {
                    if (!is_array($category)) {
                        continue;
                    }
                    $categoryId = filter_var($category['category_id'] ?? null, FILTER_VALIDATE_INT);
                    $categoryName = $normalizeCategoryName($category['name'] ?? $category['category_name'] ?? '');
                    if ($categoryId === false || $categoryId <= 0 || empty($employeesByCategoryName[$categoryName])) {
                        continue;
                    }
                    foreach (array_unique($employeesByCategoryName[$categoryName]) as $empId) {
                        $fallbackCategoryOwners[$empId] = (string)$categoryId;
                        if (isset($GLOBALS['posterTransactionDiagnostics'][$empId])) {
                            $GLOBALS['posterTransactionDiagnostics'][$empId]['matched_category_name'] =
                                $category['name'] ?? $category['category_name'] ?? '';
                            $GLOBALS['posterTransactionDiagnostics'][$empId]['matched_category_id'] = (string)$categoryId;
                        }
                    }
                }
            } else {
                error_log('Poster finance.getCategories kutilmagan javob qaytardi.');
            }
        } else {
            error_log('Poster finance.getCategories curl xatosi: ' . $categoryNamesFetch['error']);
        }

        $allTransactionsUrl = "https://{$apiDomain}.joinposter.com/api/finance.getTransactions?"
            . http_build_query([
                'token' => $token,
                'dateFrom' => $apiFrom,
                'dateTo' => $apiTo,
            ]);
        $fallbackResults = fetchUrlsParallel(['all' => $allTransactionsUrl], 1, 30);
        $fallbackFetch = $fallbackResults['all'] ?? [
            'success' => false,
            'response' => null,
            'error' => 'no result',
        ];

        if ($fallbackFetch['success']) {
            $fallbackData = json_decode($fallbackFetch['response'], true);
            if (is_array($fallbackData) && is_array($fallbackData['response'] ?? null)) {
                $fallbackMatchedCounts = [];
                foreach ($fallbackData['response'] as $transaction) {
                    if (
                        !is_array($transaction)
                        || (string)($transaction['delete'] ?? '0') === '1'
                        || !is_numeric($transaction['amount'] ?? $transaction['amount_to'] ?? null)
                        || !isset($transaction['category_id'])
                    ) {
                        continue;
                    }

                    $empId = null;
                    // Tranzaksiyadagi user_id — pulni kiritgan foydalanuvchi, xodim emas; faqat kategoriya bo'yicha bog'lanadi
                    $transactionCategoryId = (string)($transaction['category_id'] ?? '');
                    if ($empId === null && $transactionCategoryId !== '') {
                        foreach ($fallbackCategoryOwners as $candidateEmployeeId => $categoryId) {
                            if ($categoryId === $transactionCategoryId) {
                                $empId = $candidateEmployeeId;
                                break;
                            }
                        }
                    }
                    if ($empId === null) {
                        continue;
                    }

                    $transactionDate = $transaction['transaction_date'] ?? $transaction['date'] ?? '';

                    $fingerprint = $transactionFingerprint($transaction);
                    $alreadyIncluded = false;
                    foreach ($results[$empId] as $existingTransaction) {
                        if (($existingTransaction['_dedupe_key'] ?? null) === $fingerprint) {
                            $alreadyIncluded = true;
                            break;
                        }
                    }
                    if ($alreadyIncluded) {
                        continue;
                    }

                    $results[$empId][] = [
                        'date' => $transactionDate,
                        'amount' => abs((float)($transaction['amount'] ?? $transaction['amount_to'])) / 100,
                        'type' => 'payment',
                        'comment' => $transaction['comment'] ?? $transaction['description'] ?? $transaction['note'] ?? '',
                        'full_comment' => $transaction['comment'] ?? $transaction['description'] ?? $transaction['note'] ?? '',
                        'method' => $transaction['payment_method'] ?? '',
                        'system' => $transaction['payment_system'] ?? '',
                        'name' => $transaction['name'] ?? '',
                        '_dedupe_key' => $fingerprint,
                    ];
                    $fallbackMatchedCounts[$empId] = ($fallbackMatchedCounts[$empId] ?? 0) + 1;
                    unset($GLOBALS['transactionsFetchFailed'][$empId]);
                }
                foreach ($employeesWithoutTransactions as $empId => $categoryId) {
                    if (isset($GLOBALS['posterTransactionDiagnostics'][$empId])) {
                        $GLOBALS['posterTransactionDiagnostics'][$empId]['fallback_response_count'] = count($fallbackData['response']);
                        $GLOBALS['posterTransactionDiagnostics'][$empId]['fallback_matched_count'] = $fallbackMatchedCounts[$empId] ?? 0;
                        $GLOBALS['posterTransactionDiagnostics'][$empId]['fallback_category_id'] = $fallbackCategoryOwners[$empId] ?? '';
                        $GLOBALS['posterTransactionDiagnostics'][$empId]['fallback_mapping'] = isset($fallbackMatchedCounts[$empId])
                            ? 'poster_user_id/category_id'
                            : '';
                    }
                }
            } else {
                error_log('Poster transactions fallback kutilmagan javob qaytardi.');
                foreach ($employeesWithoutTransactions as $empId => $categoryId) {
                    if (isset($GLOBALS['posterTransactionDiagnostics'][$empId])) {
                        $GLOBALS['posterTransactionDiagnostics'][$empId]['fallback_error'] = 'unexpected_response';
                    }
                }
            }
        } else {
            error_log('Poster transactions fallback curl xatosi: ' . $fallbackFetch['error']);
            foreach ($employeesWithoutTransactions as $empId => $categoryId) {
                if (isset($GLOBALS['posterTransactionDiagnostics'][$empId])) {
                    $GLOBALS['posterTransactionDiagnostics'][$empId]['fallback_error'] = 'request_failed';
                }
            }
        }
    }

    foreach ($results as &$transactions) {
        usort($transactions, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        foreach ($transactions as &$transaction) {
            unset($transaction['_dedupe_key']);
        }
        unset($transaction);
    }
    unset($transactions);

    return $results;
}

// ============================================================
// 6.8 GET SETTINGS FOR DATE
// ============================================================
function getSettingsForDate($settingsHistory, $date) {
    if (empty($settingsHistory) || !is_array($settingsHistory)) {
        return null;
    }

    $applicableSettings = null;
    $latestEffectiveDate = '0000-00-00';

    foreach ($settingsHistory as $key => $settings) {
        if ($key === '_meta') continue;
        if (!isset($settings['amal_qiladi'])) {
            continue;
        }

        $effectiveDate = $settings['amal_qiladi'];

        if ($effectiveDate <= $date) {
            if ($effectiveDate > $latestEffectiveDate) {
                $latestEffectiveDate = $effectiveDate;
                $applicableSettings = $settings;
            }
        }
    }

    if ($applicableSettings) {
        $maoshTurlari = json_decode($applicableSettings['maosh_turi'], true);
        if (!is_array($maoshTurlari) || empty($maoshTurlari)) {
            $maoshTurlari = ['fixed'];
        }
        $applicableSettings['maosh_turi_array'] = $maoshTurlari;
    }

    return $applicableSettings;
}

// ============================================================
// 6.9 CALCULATE EMPLOYEE SALARY
// ============================================================
function calculateEmployeeSalary($employeeId, $month, $monthlySettings, $attendanceData, $dailyWaitersRevenue) {
    require_once __DIR__ . '/../../includes/functions/excuses.php';
    global $daysInMonth, $standardWorkHours, $currencySymbol;

    $settingsHistory = $monthlySettings[$employeeId] ?? null;
    if (!$settingsHistory || !is_array($settingsHistory) || empty($settingsHistory)) {
        return [
            'total' => 0,
            'days' => 0,
            'hours' => 0,
            'daily_salaries' => [],
            'daily_statuses' => [],
            'daily_details' => [],
            'total_bonus' => 0,
            'total_penalty' => 0,
            'daily_bonus' => [],
            'daily_penalty' => []
        ];
    }

    $totalSalary = 0;
    $workedDays = 0;
    $totalHours = 0;
    $dailySalaries = [];
    $dailyStatuses = [];
    $dailyDetails = [];
    $totalBonus = 0;
    $totalPenalty = 0;
    $dailyBonus = [];
    $dailyPenalty = [];

    $workingDaysCount = 0;
    foreach ($attendanceData as $date => $data) {
        if ($data['check_in'] && $data['check_out']) {
            $workingDaysCount++;
        }
    }
    if ($workingDaysCount == 0) $workingDaysCount = 1;

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = date('Y-m-d', strtotime($month . '-' . str_pad($day, 2, '0', STR_PAD_LEFT)));

        $settings = getSettingsForDate($settingsHistory, $date);
        if (!$settings) {
            $dailySalaries[$date] = 0;
            $dailyStatuses[$date] = 'no_settings';
            $dailyBonus[$date] = 0;
            $dailyPenalty[$date] = 0;
            $dailyDetails[$date] = [
                'salary' => 0,
                'status' => 'no_settings',
                'check_in' => null,
                'check_out' => null,
                'worked_hours' => 0,
                'in_late_minutes' => 0,
                'out_early_minutes' => 0,
                'out_late_minutes' => 0,
                'day_penalty' => 0,
                'day_bonus' => 0,
                'late_penalty' => 0,
                'early_leave_penalty' => 0,
                'early_arrival_bonus' => 0,
                'late_leave_bonus' => 0,
                'daily_revenue' => 0,
                'amal_qiladi' => null,
                'maosh_turi_array' => ['fixed']
            ];
            continue;
        }
        $scheduleOverride = getEmployeeWorkScheduleOverride($GLOBALS['pdo'], $employeeId, $date);
        if ($scheduleOverride && $scheduleOverride['amal_qiladi'] >= ($settings['amal_qiladi'] ?? '')) {
            foreach (['ishga_kelish_vaqti', 'ishdan_ketish_vaqti', 'tushlik_boshlanish', 'tushlik_tugash'] as $field) {
                $settings[$field] = $scheduleOverride[$field];
            }
        }

        $maoshTurlari = isset($settings['maosh_turi_array']) ? $settings['maosh_turi_array'] : ['fixed'];
        if (!is_array($maoshTurlari) || empty($maoshTurlari)) {
            $maoshTurlari = ['fixed'];
        }
        
        $oylikMaosh = isset($settings['oylik_maosh']) ? (float)$settings['oylik_maosh'] : null;
        $soatlikStavka = isset($settings['soatlik_stavka']) ? (float)$settings['soatlik_stavka'] : null;
        $foizQiymati = isset($settings['foiz_qiymati']) ? (float)$settings['foiz_qiymati'] : null;
        $smenaSumma = isset($settings['smena_summa']) ? (float)$settings['smena_summa'] : null;
        $posterUserId = $settings['poster_user_id'] ?? null;

        $dailyRate = ($oylikMaosh !== null && $daysInMonth > 0) ? $oylikMaosh / $daysInMonth : null;

        $att = $attendanceData[$date] ?? null;

        $daySalary = 0;
        $status = 'absent';
        $checkIn = null;
        $checkOut = null;
        $workedHours = 0;
        $inLateMinutes = 0;
        $outEarlyMinutes = 0;
        $outLateMinutes = 0;
        $dayPenalty = 0;
        $dayBonus = 0;
        $dailyRevenue = 0;

        $latePenaltyAmt = 0;
        $earlyLeavePenaltyAmt = 0;
        $earlyArrivalBonusAmt = 0;
        $lateLeaveBonusAmt = 0;

        if ($att && $att['check_in'] && $att['check_out']) {
            $status = 'present';
            $checkIn = $att['check_in'];
            $checkOut = $att['check_out'];
            $workedHours = $att['total_work_hours'] ?? $standardWorkHours;
            $inLateMinutes = (int)($att['in_late_minutes'] ?? 0);
            $outEarlyMinutes = (int)($att['out_early_minutes'] ?? 0);
            $outLateMinutes = (int)($att['out_late_minutes'] ?? 0);

            $totalHours += $workedHours;
            $workedDays++;

            foreach ($maoshTurlari as $tur) {
                if ($tur == 'fixed' && $dailyRate !== null) {
                    $daySalary += $dailyRate;
                } elseif ($tur == 'shift' && $smenaSumma !== null) {
                    // SMENA BO'YICHA TO'LOV - bir smena uchun belgilangan summa
                    $daySalary += $smenaSumma;
                } elseif ($tur == 'hourly' && $soatlikStavka !== null) {
                    $daySalary += $soatlikStavka * $workedHours;
                } elseif ($tur == 'percent' && $posterUserId && $foizQiymati !== null) {
                    $dailyRevenue = 0;
                    if (is_array($dailyWaitersRevenue) && isset($dailyWaitersRevenue[$posterUserId][$date])) {
                        $dailyRevenue = $dailyWaitersRevenue[$posterUserId][$date];
                    } elseif (is_array($dailyWaitersRevenue) && isset($dailyWaitersRevenue[$posterUserId])) {
                        $monthlyRevenue = is_array($dailyWaitersRevenue[$posterUserId])
                            ? array_sum($dailyWaitersRevenue[$posterUserId])
                            : (float)$dailyWaitersRevenue[$posterUserId];
                        if ($monthlyRevenue > 0) {
                            $dailyRevenue = $monthlyRevenue / $workingDaysCount;
                        }
                    } elseif (isset($GLOBALS['waitersRevenue'][$posterUserId])) {
                        $monthlyRevenue = (float)$GLOBALS['waitersRevenue'][$posterUserId];
                        if ($monthlyRevenue > 0) {
                            $dailyRevenue = $monthlyRevenue / $workingDaysCount;
                        }
                    }
                    $daySalary += $dailyRevenue * ($foizQiymati / 100);
                }
            }

            // KECH KELISH → JARIMA
            if ($inLateMinutes > 0 && !isExcused($employeeId, $date, 'late', $checkIn)) {
                $enabled = isset($settings['kech_kelish_jarima_enabled']) ? (int)$settings['kech_kelish_jarima_enabled'] : 1;
                if ($enabled == 1) {
                    $perMinute = isset($settings['kech_kelish_jarima_per_minute']) ? (float)$settings['kech_kelish_jarima_per_minute'] : null;
                    $maxPenalty = isset($settings['kech_kelish_max_jarima']) ? (float)$settings['kech_kelish_max_jarima'] : null;
                    $graceMinutes = isset($settings['kech_kelish_grace_minutes']) ? (int)$settings['kech_kelish_grace_minutes'] : 0;

                    if ($perMinute !== null && $maxPenalty !== null) {
                        $effective = max(0, $inLateMinutes - $graceMinutes);
                        $penalty = min($effective * $perMinute, $maxPenalty);
                        $latePenaltyAmt += $penalty;
                        $dayPenalty += $penalty;
                        $totalPenalty += $penalty;
                    }
                }
            }

            // ERTA KELISH → BONUS
            if ($inLateMinutes < 0) {
                $enabled = isset($settings['erta_kelish_bonus_enabled']) ? (int)$settings['erta_kelish_bonus_enabled'] : 1;
                if ($enabled == 1) {
                    $earlyMinutes = abs($inLateMinutes);
                    $perMinute = isset($settings['erta_kelish_bonus_amount']) ? (float)$settings['erta_kelish_bonus_amount'] : null;
                    $maxBonus = isset($settings['erta_kelish_max_bonus']) ? (float)$settings['erta_kelish_max_bonus'] : null;

                    if ($perMinute !== null && $maxBonus !== null) {
                        $bonus = min($earlyMinutes * $perMinute, $maxBonus);
                        $earlyArrivalBonusAmt += $bonus;
                        $dayBonus += $bonus;
                        $totalBonus += $bonus;
                    }
                }
            }
            
            // ERTA KELISH (check_in orqali)
            if ($inLateMinutes == 0 && $checkIn) {
                $enabled = isset($settings['erta_kelish_bonus_enabled']) ? (int)$settings['erta_kelish_bonus_enabled'] : 1;
                if ($enabled == 1) {
                    $workStart = isset($settings['ishga_kelish_vaqti']) ? strtotime($settings['ishga_kelish_vaqti']) : null;
                    if ($workStart !== null) {
                        $checkInTime = strtotime($checkIn);
                        $earlyMinutes = round(($workStart - $checkInTime) / 60);

                        if ($earlyMinutes > 0) {
                            $perMinute = isset($settings['erta_kelish_bonus_amount']) ? (float)$settings['erta_kelish_bonus_amount'] : null;
                            $maxBonus = isset($settings['erta_kelish_max_bonus']) ? (float)$settings['erta_kelish_max_bonus'] : null;

                            if ($perMinute !== null && $maxBonus !== null) {
                                $bonus = min($earlyMinutes * $perMinute, $maxBonus);
                                $earlyArrivalBonusAmt += $bonus;
                                $dayBonus += $bonus;
                                $totalBonus += $bonus;
                            }
                        }
                    }
                }
            }

            // ERTA KETISH → JARIMA
            if ($outEarlyMinutes < 0 && !isExcused($employeeId, $date, 'early', $checkOut)) {
                $enabled = isset($settings['erta_ketish_jarima_enabled']) ? (int)$settings['erta_ketish_jarima_enabled'] : 1;
                if ($enabled == 1) {
                    $earlyMinutes = abs($outEarlyMinutes);
                    $perMinute = isset($settings['erta_ketish_jarima_per_minute']) ? (float)$settings['erta_ketish_jarima_per_minute'] : null;
                    $maxPenalty = isset($settings['erta_ketish_max_jarima']) ? (float)$settings['erta_ketish_max_jarima'] : null;
                    $graceMinutes = isset($settings['erta_ketish_grace_minutes']) ? (int)$settings['erta_ketish_grace_minutes'] : 0;

                    if ($perMinute !== null && $maxPenalty !== null) {
                        $effective = max(0, $earlyMinutes - $graceMinutes);
                        $penalty = min($effective * $perMinute, $maxPenalty);
                        $earlyLeavePenaltyAmt += $penalty;
                        $dayPenalty += $penalty;
                        $totalPenalty += $penalty;
                    }
                }
            }

            // KECH KETISH → BONUS
            if ($outLateMinutes > 0) {
                $enabled = isset($settings['kech_ketish_bonus_enabled']) ? (int)$settings['kech_ketish_bonus_enabled'] : 1;
                if ($enabled == 1) {
                    $perMinute = isset($settings['kech_ketish_bonus_amount']) ? (float)$settings['kech_ketish_bonus_amount'] : null;
                    $maxBonus = isset($settings['kech_ketish_max_bonus']) ? (float)$settings['kech_ketish_max_bonus'] : null;

                    if ($perMinute !== null && $maxBonus !== null) {
                        $bonus = min($outLateMinutes * $perMinute, $maxBonus);
                        $lateLeaveBonusAmt += $bonus;
                        $dayBonus += $bonus;
                        $totalBonus += $bonus;
                    }
                }
            }
            
            // KECH KETISH (check_out orqali)
            if ($outLateMinutes == 0 && $checkOut) {
                $enabled = isset($settings['kech_ketish_bonus_enabled']) ? (int)$settings['kech_ketish_bonus_enabled'] : 1;
                if ($enabled == 1) {
                    $workEnd = isset($settings['ishdan_ketish_vaqti']) ? strtotime($settings['ishdan_ketish_vaqti']) : null;
                    if ($workEnd !== null) {
                        $checkOutTime = strtotime($checkOut);
                        $lateMinutes = round(($checkOutTime - $workEnd) / 60);

                        if ($lateMinutes > 0) {
                            $perMinute = isset($settings['kech_ketish_bonus_amount']) ? (float)$settings['kech_ketish_bonus_amount'] : null;
                            $maxBonus = isset($settings['kech_ketish_max_bonus']) ? (float)$settings['kech_ketish_max_bonus'] : null;

                            if ($perMinute !== null && $maxBonus !== null) {
                                $bonus = min($lateMinutes * $perMinute, $maxBonus);
                                $lateLeaveBonusAmt += $bonus;
                                $dayBonus += $bonus;
                                $totalBonus += $bonus;
                            }
                        }
                    }
                }
            }

        } elseif ($att && $att['check_in']) {
            $status = 'partial_in';
            $checkIn = $att['check_in'];
            $inLateMinutes = (int)($att['in_late_minutes'] ?? 0);
            $workedHours = $att['total_work_hours'] ?? 0;

            if ($inLateMinutes > 0 && !isExcused($employeeId, $date, 'late', $checkIn)) {
                $enabled = isset($settings['kech_kelish_jarima_enabled']) ? (int)$settings['kech_kelish_jarima_enabled'] : 1;
                if ($enabled == 1) {
                    $perMinute = isset($settings['kech_kelish_jarima_per_minute']) ? (float)$settings['kech_kelish_jarima_per_minute'] : null;
                    $maxPenalty = isset($settings['kech_kelish_max_jarima']) ? (float)$settings['kech_kelish_max_jarima'] : null;
                    $graceMinutes = isset($settings['kech_kelish_grace_minutes']) ? (int)$settings['kech_kelish_grace_minutes'] : 0;

                    if ($perMinute !== null && $maxPenalty !== null) {
                        $effective = max(0, $inLateMinutes - $graceMinutes);
                        $penalty = min($effective * $perMinute, $maxPenalty);
                        $latePenaltyAmt += $penalty;
                        $dayPenalty += $penalty;
                        $totalPenalty += $penalty;
                    }
                }
            }

            if ($inLateMinutes < 0) {
                $enabled = isset($settings['erta_kelish_bonus_enabled']) ? (int)$settings['erta_kelish_bonus_enabled'] : 1;
                if ($enabled == 1) {
                    $earlyMinutes = abs($inLateMinutes);
                    $perMinute = isset($settings['erta_kelish_bonus_amount']) ? (float)$settings['erta_kelish_bonus_amount'] : null;
                    $maxBonus = isset($settings['erta_kelish_max_bonus']) ? (float)$settings['erta_kelish_max_bonus'] : null;

                    if ($perMinute !== null && $maxBonus !== null) {
                        $bonus = min($earlyMinutes * $perMinute, $maxBonus);
                        $earlyArrivalBonusAmt += $bonus;
                        $dayBonus += $bonus;
                        $totalBonus += $bonus;
                    }
                }
            }

        } elseif ($att && $att['check_out']) {
            $status = 'partial_out';
            $checkOut = $att['check_out'];
            $outEarlyMinutes = (int)($att['out_early_minutes'] ?? 0);
            $workedHours = $att['total_work_hours'] ?? 0;

            if ($outEarlyMinutes < 0 && !isExcused($employeeId, $date, 'early', $checkOut)) {
                $enabled = isset($settings['erta_ketish_jarima_enabled']) ? (int)$settings['erta_ketish_jarima_enabled'] : 1;
                if ($enabled == 1) {
                    $earlyMinutes = abs($outEarlyMinutes);
                    $perMinute = isset($settings['erta_ketish_jarima_per_minute']) ? (float)$settings['erta_ketish_jarima_per_minute'] : null;
                    $maxPenalty = isset($settings['erta_ketish_max_jarima']) ? (float)$settings['erta_ketish_max_jarima'] : null;
                    $graceMinutes = isset($settings['erta_ketish_grace_minutes']) ? (int)$settings['erta_ketish_grace_minutes'] : 0;

                    if ($perMinute !== null && $maxPenalty !== null) {
                        $effective = max(0, $earlyMinutes - $graceMinutes);
                        $penalty = min($effective * $perMinute, $maxPenalty);
                        $earlyLeavePenaltyAmt += $penalty;
                        $dayPenalty += $penalty;
                        $totalPenalty += $penalty;
                    }
                }
            }

            if ($outEarlyMinutes > 0) {
                $enabled = isset($settings['kech_ketish_bonus_enabled']) ? (int)$settings['kech_ketish_bonus_enabled'] : 1;
                if ($enabled == 1) {
                    $perMinute = isset($settings['kech_ketish_bonus_amount']) ? (float)$settings['kech_ketish_bonus_amount'] : null;
                    $maxBonus = isset($settings['kech_ketish_max_bonus']) ? (float)$settings['kech_ketish_max_bonus'] : null;

                    if ($perMinute !== null && $maxBonus !== null) {
                        $bonus = min($outEarlyMinutes * $perMinute, $maxBonus);
                        $lateLeaveBonusAmt += $bonus;
                        $dayBonus += $bonus;
                        $totalBonus += $bonus;
                    }
                }
            }
        }

        $totalSalary += $daySalary;
        $dailySalaries[$date] = round($daySalary);
        $dailyStatuses[$date] = $status;
        $dailyBonus[$date] = round($dayBonus);
        $dailyPenalty[$date] = round($dayPenalty);
        
        $dailyDetails[$date] = [
            'salary' => round($daySalary),
            'status' => $status,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'worked_hours' => round($workedHours, 1),
            'in_late_minutes' => $inLateMinutes,
            'out_early_minutes' => $outEarlyMinutes,
            'out_late_minutes' => $outLateMinutes,
            'day_penalty' => round($dayPenalty),
            'day_bonus' => round($dayBonus),
            'late_penalty' => round($latePenaltyAmt),
            'early_leave_penalty' => round($earlyLeavePenaltyAmt),
            'early_arrival_bonus' => round($earlyArrivalBonusAmt),
            'late_leave_bonus' => round($lateLeaveBonusAmt),
            'daily_revenue' => $dailyRevenue,
            'amal_qiladi' => $settings['amal_qiladi'] ?? null,
            'maosh_turi_array' => $maoshTurlari,
            'smena_summa' => $smenaSumma
        ];
    }

    return [
        'total' => $totalSalary,
        'days' => $workedDays,
        'hours' => $totalHours,
        'daily_salaries' => $dailySalaries,
        'daily_statuses' => $dailyStatuses,
        'daily_details' => $dailyDetails,
        'total_bonus' => $totalBonus,
        'total_penalty' => $totalPenalty,
        'daily_bonus' => $dailyBonus,
        'daily_penalty' => $dailyPenalty
    ];
}

// ============================================================
// 6.10 GET DAILY WAITERS REVENUE
// ============================================================
function getDailyWaitersRevenue() {
    global $token, $account_name, $dateFrom, $dateTo;
    if(!$token) return [];
    if(is_array($token)) $token = $token['token'] ?? $token[0] ?? null;
    if(!$token) return [];

    $urls = [];
    $startDate = new DateTime($dateFrom);
    $endDate = new DateTime($dateTo);
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($startDate, $interval, (clone $endDate)->modify('+1 day'));

    foreach($period as $date) {
        $apiDate = $date->format('Ymd');
        $dateStr = $date->format('Y-m-d');
        $urls[$dateStr] = "https://{$account_name}.joinposter.com/api/dash.getWaitersSales?token=" . urlencode($token) . "&dateFrom=" . $apiDate . "&dateTo=" . $apiDate;
    }

    if(empty($urls)) return [];

    $fetchResults = fetchUrlsParallel($urls, 10, 30);

    $results = [];
    foreach($urls as $dateStr => $url) {
        $fetch = $fetchResults[$dateStr] ?? ['success' => false, 'response' => null, 'error' => 'no result'];

        if (!$fetch['success']) {
            error_log("Daily revenue curl xatosi $dateStr: " . $fetch['error']);
            continue;
        }

        $data = json_decode($fetch['response'], true);

        if(isset($data['response']) && is_array($data['response'])) {
            foreach($data['response'] as $waiter) {
                $userId = $waiter['user_id'];
                $revenue = isset($waiter['revenue']) ? (float)$waiter['revenue'] / 100 : 0;

                if(!isset($results[$userId])) {
                    $results[$userId] = [];
                }

                $results[$userId][$dateStr] = $revenue;
            }
        }
    }

    return $results;
}

// ============================================
// 7. ASOSIY MA'LUMOTLARNI YIG'ISH
// ============================================

$employeeIds = array_column($employees, 'id');

$monthlySettings = getOylikSozlamalar($employeeIds, $currentMonth);
$attendanceData = getAttendanceData($employeeIds, $currentMonth);
$bonusData = getBonusData($employeeIds, $currentMonth);
$previousBalances = getPreviousBalance($employeeIds, $currentMonth);

$waitersRevenue = getWaitersRevenue();
$dailyWaitersRevenue = getDailyWaitersRevenue();

if(empty($dailyWaitersRevenue) || !is_array($dailyWaitersRevenue)) {
    $dailyWaitersRevenue = $waitersRevenue;
}

$GLOBALS['waitersRevenue'] = $waitersRevenue;
$GLOBALS['transactionsFetchFailed'] = [];

$posterIds = [];
$employeeCreatedDates = [];

foreach($employees as $emp) {
    $empId = $emp['id'];
    $employeeCreatedDates[$empId] = $emp['yaratilgan'] ?? null;
    if(!empty($emp['poster_id'])) {
        $posterIds[$empId] = $emp['poster_id'];
    } else {
        $GLOBALS['transactionsFetchFailed'][$empId] = true;
        error_log("Xodim $empId uchun Poster ish haqi kategoriya ID bog‘lanmagan; to‘langan summa olinmadi.");
    }
}

$GLOBALS['reportEmployeeNames'] = array_column($employees, 'ismi', 'id');
$transactions = getTransactions($posterIds, $employeeCreatedDates, array_column($employees, 'poster_user_id', 'id'));

$empData = [];
$totalCalculated = 0;
$totalPaid = 0;
$totalBalance = 0;
$totalPreviousBalance = 0;
$totalToPaySum = 0;
$totalBonus = 0;
$totalPenalty = 0;
$totalDailyDisplay = 0;
$i = 1;

foreach($employees as $emp) {
    $empId = $emp['id'];
    $settings = $monthlySettings[$empId] ?? null;

    if(!$settings) {
        $settings = [];
    }

    // ✅ maosh_turi_array ni olish
    $maoshTurlari = isset($settings['maosh_turi_array']) ? $settings['maosh_turi_array'] : ['fixed'];
    if (!is_array($maoshTurlari) || empty($maoshTurlari)) {
        $maoshTurlari = ['fixed'];
    }
    
    // ✅ Barcha turlar uchun badge matni
    $typeTexts = [];
    foreach($maoshTurlari as $tur) {
        if($tur == 'fixed') $typeTexts[] = t('fixed');
        elseif($tur == 'hourly') $typeTexts[] = t('hourly');
        elseif($tur == 'percent') $typeTexts[] = t('percent');
        elseif($tur == 'shift') $typeTexts[] = t('shift');
    }
    $hasSalarySettings = false;
    foreach (['oylik_maosh', 'soatlik_stavka', 'foiz_qiymati', 'smena_summa'] as $salaryField) {
        if (isset($settings[$salaryField]) && (float)$settings[$salaryField] > 0) {
            $hasSalarySettings = true;
            break;
        }
    }
    $badgeText = implode(' + ', $typeTexts);
    
    // ✅ Badge class - asosiy tur bo'yicha
    $primaryType = $maoshTurlari[0] ?? 'fixed';
    $badgeClass = match($primaryType) {
        'fixed' => 'salary-badge-fixed',
        'hourly' => 'salary-badge-hourly',
        'percent' => 'salary-badge-percent',
        'shift' => 'salary-badge-shift',
        default => 'salary-badge-fixed'
    };
    if (!$hasSalarySettings) {
        $badgeText = 'Belgilanmagan';
        $badgeClass = 'salary-badge-unset';
    }

    // ✅ Maosh display
    $displayParts = [];
    if(in_array('fixed', $maoshTurlari) && isset($settings['oylik_maosh'])) {
        $displayParts[] = number_format($settings['oylik_maosh']) . ' ' . $currencySymbol . '/' . t('month');
    }
    if(in_array('hourly', $maoshTurlari) && isset($settings['soatlik_stavka'])) {
        $displayParts[] = number_format($settings['soatlik_stavka']) . ' ' . $currencySymbol . '/' . t('hour');
    }
    if(in_array('percent', $maoshTurlari) && isset($settings['foiz_qiymati'])) {
        $displayParts[] = $settings['foiz_qiymati'] . '%';
    }
    if(in_array('shift', $maoshTurlari) && isset($settings['smena_summa'])) {
        $displayParts[] = number_format($settings['smena_summa']) . ' ' . $currencySymbol . '/' . t('shift');
    }
    $salaryDisplay = implode(' + ', $displayParts);

    $salaryInfo = [
        'type' => $primaryType,
        'type_text' => $badgeText,
        'amount' => isset($settings['oylik_maosh']) ? (float)$settings['oylik_maosh'] : 
                   (isset($settings['soatlik_stavka']) ? (float)$settings['soatlik_stavka'] : 
                   (isset($settings['foiz_qiymati']) ? (float)$settings['foiz_qiymati'] :
                   (isset($settings['smena_summa']) ? (float)$settings['smena_summa'] : null))),
        'display_text' => $salaryDisplay
    ];

    $empAttendance = $attendanceData[$empId] ?? [];

    $calc = calculateEmployeeSalary($empId, $currentMonth, $monthlySettings, $empAttendance, $dailyWaitersRevenue);

    $empExtraBonus = 0;
    $empExtraPenalty = 0;
    $empBonusItems = $bonusData[$empId] ?? [];

    if(!empty($empBonusItems)) {
        foreach($empBonusItems as $b) {
            if($b['turi'] == 'bonus') {
                $empExtraBonus += $b['miqdor'];
            } else {
                $empExtraPenalty += $b['miqdor'];
            }
        }
    }

    $empBonus = $calc['total_bonus'] + $empExtraBonus;
    $empPenalty = $calc['total_penalty'] + $empExtraPenalty;

    $totalBonus += $empBonus;
    $totalPenalty += $empPenalty;

    $calculated = round($calc['total']);
    $previousBalance = $previousBalances[$empId] ?? 0;

    $empTransactions = $transactions[$empId] ?? [];

    $paid = array_sum(array_column($empTransactions, 'amount'));

    $totalToPay = $calculated + $empBonus - $empPenalty + $previousBalance;
    $balance = $totalToPay - $paid;

    $transactionsReliable = empty($GLOBALS['transactionsFetchFailed'][$empId]);
    if ($transactionsReliable) {
        saveBalance($empId, $currentMonth, $balance);
    } else {
        error_log("Xodim $empId uchun balans saqlanmadi: Poster tranzaksiyalarini olishda xatolik bo'ldi.");
    }

    $totalCalculated += $calculated;
    $totalPreviousBalance += $previousBalance;
    $totalPaid += $paid;
    $totalToPaySum += $totalToPay;
    $totalBalance += $balance;

    $attended = $calc['days'];
    $totalWorkedHours = $calc['hours'];

    $hourlyRateDisplay = 0;
    if(in_array('fixed', $maoshTurlari) && $attended > 0 && isset($settings['oylik_maosh'])) {
        $hourlyRateDisplay = round($settings['oylik_maosh'] / $attended / $standardWorkHours);
    } elseif(in_array('hourly', $maoshTurlari) && isset($settings['soatlik_stavka'])) {
        $hourlyRateDisplay = round($settings['soatlik_stavka']);
    }

    // daily_display
    $lastSettingsMeta = $monthlySettings[$empId]['_meta'] ?? null;
    $dailyDisplayRaw = null;
    if ($lastSettingsMeta && isset($lastSettingsMeta['last_daily_display']) && $lastSettingsMeta['last_daily_display'] !== null) {
        $dailyDisplayRaw = $lastSettingsMeta['last_daily_display_raw'] ?? null;
        $dailyDisplayValue = $lastSettingsMeta['last_daily_display'] ?? '-';
    } else {
        $dailyDisplayValue = '-';
    }

    if ($dailyDisplayRaw !== null) {
        $totalDailyDisplay += $dailyDisplayRaw;
    }

    $empData[] = [
        'id' => $empId,
        'name' => $emp['ismi'],
        'phone' => $emp['telefoni'] ?? '',
        'email' => $emp['emaili'] ?? '',
        'position' => $emp['lavozimi'] ?? '',
        'created' => $emp['yaratilgan'] ?? '',
        'badge_text' => $salaryInfo['type_text'],
        'badge_class' => $badgeClass,
        'salary_display' => $salaryInfo['display_text'],
        'daily_display' => $dailyDisplayValue,
        'daily_display_raw' => $dailyDisplayRaw,
        'attended' => $attended,
        'days_in_month' => $daysInMonth,
        'calculated' => $calculated,
        'bonus' => $empBonus,
        'penalty' => $empPenalty,
        'attendance_bonus' => $calc['total_bonus'],
        'attendance_penalty' => $calc['total_penalty'],
        'extra_bonus' => $empExtraBonus,
        'extra_penalty' => $empExtraPenalty,
        'previous_balance' => $previousBalance,
        'prev_class' => $previousBalance > 0 ? 'negative' : ($previousBalance < 0 ? 'positive' : ''),
        'prev_sign' => $previousBalance > 0 ? '+' : ($previousBalance < 0 ? '-' : ''),
        'prev_text' => $previousBalance > 0 ? t('debt') : ($previousBalance < 0 ? t('overpaid') : ''),
        'total_to_pay' => $totalToPay,
        'paid' => $paid,
        'balance' => $balance,
        'balance_text' => $balance > 0 ? t('debtor') : ($balance < 0 ? t('overpaid') : t('zero_balance')),
        'daily_data' => $calc['daily_salaries'],
        'daily_salaries' => $calc['daily_salaries'],
        'attendance_statuses' => $calc['daily_statuses'],
        'daily_details' => $calc['daily_details'],
        'daily_bonus' => $calc['daily_bonus'],
        'daily_penalty' => $calc['daily_penalty'],
        'transactions' => $empTransactions,
        'bonus_items' => $empBonusItems,
        'total_worked_hours' => $totalWorkedHours,
        'hourly_rate_display' => $hourlyRateDisplay,
        'maosh_turi_array' => $maoshTurlari,
        'kech_kelish_jarima_per_minute' => isset($settings['kech_kelish_jarima_per_minute']) ? $settings['kech_kelish_jarima_per_minute'] : null,
        'kech_kelish_max_jarima' => isset($settings['kech_kelish_max_jarima']) ? $settings['kech_kelish_max_jarima'] : null,
        'erta_kelish_bonus_amount' => isset($settings['erta_kelish_bonus_amount']) ? $settings['erta_kelish_bonus_amount'] : null,
        'erta_kelish_max_bonus' => isset($settings['erta_kelish_max_bonus']) ? $settings['erta_kelish_max_bonus'] : null,
        'kech_ketish_bonus_amount' => isset($settings['kech_ketish_bonus_amount']) ? $settings['kech_ketish_bonus_amount'] : null,
        'kech_ketish_max_bonus' => isset($settings['kech_ketish_max_bonus']) ? $settings['kech_ketish_max_bonus'] : null,
        'erta_ketish_jarima_per_minute' => isset($settings['erta_ketish_jarima_per_minute']) ? $settings['erta_ketish_jarima_per_minute'] : null,
        'erta_ketish_max_jarima' => isset($settings['erta_ketish_max_jarima']) ? $settings['erta_ketish_max_jarima'] : null,
        'erta_ketish_grace_minutes' => isset($settings['erta_ketish_grace_minutes']) ? $settings['erta_ketish_grace_minutes'] : null,
        'kech_kelish_grace_minutes' => isset($settings['kech_kelish_grace_minutes']) ? $settings['kech_kelish_grace_minutes'] : null
    ];
}

// ============================================
// 8. HTML
// ============================================
?>
<style>
.table-wrapper { overflow-x: auto; overflow-y: auto; max-height: calc(100vh - 174px); position: relative; }
.table-wrapper table { width: 100%; border-collapse: separate; border-spacing: 0; background: white; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
.table-wrapper thead { position: sticky; top: 0; z-index: 10; }
.reports-table th { background: #f8fafc; padding: 12px 15px; text-align: center; font-size: 12px; font-weight: 300; color: #64748b; border-bottom: 2px solid #e2e8f0; position: sticky; top: 0; z-index: 11; }
.reports-table td { padding: 10px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; vertical-align: middle; text-align: right; background: white; }
.reports-table .positive { color: #10b981; font-weight: 600; }
.reports-table .negative { color: #ef4444; font-weight: 600; }
.reports-table tfoot td { font-weight: 600; border-top: 2px solid #e2e8f0; }
.reports-table td:nth-child(2) { text-align: right; }
.reports-table th:first-child, .reports-table td:first-child { position: sticky; left: 0; background: white; z-index: 5; min-width: 50px; }
.reports-table th:first-child { background: #f8fafc; z-index: 12; }
.reports-table th:nth-child(2), .reports-table td:nth-child(2) { position: sticky; left: 50px; background: white; z-index: 5; min-width: 100px; }
.reports-table th:nth-child(2) { background: #f8fafc; z-index: 12; }
.reports-table th:last-child, .reports-table td:last-child { position: sticky; right: 0; background: white; z-index: 5; }
.reports-table th:last-child { background: #f8fafc; z-index: 12; }
.reports-table tbody tr:hover td { background: #fafbfc; }
.reports-table tbody tr:hover td:first-child, .reports-table tbody tr:hover td:nth-child(2), .reports-table tbody tr:hover td:last-child { background: #fafbfc; }
.employee-name { cursor: pointer; color: #1e293b; font-weight: 600; text-decoration: none; }
.reports-table tfoot { position: sticky; bottom: 0; z-index: 10; }
.reports-table tfoot td { font-weight: 600; border-top: 2px solid #e2e8f0; position: sticky; bottom: 0; z-index: 11; text-align: right; }

.salary-badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; min-width: 110px; text-align: center; }
.salary-badge-fixed { background: #f1f8ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.salary-badge-unset { background: #fef2f2; color: #b91c1c; border: 1px dashed #fca5a5; }
.salary-badge-hourly { background: #f1fff8; color: #065f46; border: 1px solid #a7f3d0; }
.salary-badge-percent { background: #fffcf3; color: #92400e; border: 1px solid #fde68a; }
.salary-badge-shift { background: #faf5ff; color: #7e22ce; border: 1px solid #e9d5ff; }

.bonus-action-btn { background: #f1f5f9; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 11px; color: #475569; }
.bonus-action-btn:hover { background: #e2e8f0; }
.bonus-action-btn.btn-add { background: #dcfce7; color: #166534; }
.bonus-action-btn.btn-add:hover { background: #bbf7d0; }
.bonus-action-btn.btn-edit { background: white; color: #444444; }
.bonus-action-btn.btn-delete { background: white; color: #444444; }

.report-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; flex-wrap: wrap; gap: 15px; padding: 12px 10px; }
.modal-popup { display: none; position: fixed; background: white; border-radius: 4px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); width: 500px; max-width: 500px; height: 600px; max-height: 600px; z-index: 1000; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 20px; }
.modal-popup.active { display: block; }
.modal-popup-header { background: #f5f6fa; color: #333; padding: 8px 12px; font-size: 14px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10; border-bottom: 1px solid #e2e8f0; flex-wrap: wrap; gap: 8px; }
.modal-popup-header .header-actions { display: flex; gap: 8px; align-items: center; }
.modal-popup-header .header-actions button { background: none; border: none; cursor: pointer; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
.modal-popup-header .header-actions .btn-add-bonus { background: #19bb5a; color: white; padding: 6px 12px; }
.modal-popup-header button.close-btn { background: none; border: none; color: #333; font-size: 28px; cursor: pointer; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.modal-popup-header button.close-btn:hover { background: rgba(0,0,0,0.1); }
#employeePopup .modal-popup-body { display: flex !important; flex-direction: column !important; height: calc(100% - 60px) !important; max-height: calc(100% - 60px) !important; }
.modal-popup-body::-webkit-scrollbar { width: 4px; background: transparent; }
.modal-popup-body::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
.modal-popup-body { scrollbar-width: thin; -ms-overflow-style: none; height: calc(100% - 50px); overflow-y: auto; }
.popup-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.popup-table th { text-align: left; padding: 6px 8px 6px 8px; background: #f8fafc; border-bottom: 2px solid #e2e8f0; font-weight: 600; color: #64748b; position: sticky; top: 0; font-size: 11px; }
.popup-table td { padding: 5px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; max-width: 50vh; font-size: 12px; }
.popup-table td:last-child { white-space: nowrap; }
.popup-amount.income { color: #10b981; font-weight: 600; }
.popup-amount.expense { color: #ef4444; font-weight: 600; }
.popup-amount.salary { color: #10b981; font-weight: 600; }
.bonus-form-modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0px; background: rgba(0,0,0,0.4); z-index: 2000; justify-content: center; align-items: center; }
.bonus-form-modal.active { display: flex; }
.bonus-form-modal .form-container { background: white; border-radius: 12px; padding: 24px; width: 420px; max-width: 95%; box-shadow: 0 8px 30px rgba(0,0,0,0.2); position: relative; }
.bonus-form-modal .form-container h3 { margin: 0 0 1; font-size: 16px; font-weight: 600; }
.bonus-form-modal .form-group { margin-bottom: 14px; }
.bonus-form-modal .form-group label { display: block; font-size: 12px; font-weight: 500; color: #475569; margin-bottom: 4px; }
.bonus-form-modal .form-group input, .bonus-form-modal .form-group select, .bonus-form-modal .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; box-sizing: border-box; }
.bonus-form-modal .form-group textarea { height: 60px; resize: vertical; }
.bonus-form-modal .form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.bonus-form-modal .form-actions button { padding: 8px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; }
.bonus-form-modal .form-actions .btn-cancel { background: #f1f5f9; color: #475569; }
.bonus-form-modal .form-actions .btn-cancel:hover { background: #e2e8f0; }
.bonus-form-modal .form-actions .btn-submit { background: #3b82f6; color: white; }
.bonus-form-modal .form-actions .btn-submit:hover { background: #2563eb; }
.bonus-form-modal .form-actions .btn-submit.btn-edit-mode { background: #f59e0b; }
.bonus-form-modal .form-actions .btn-submit.btn-edit-mode:hover { background: #d97706; }
.bonus-form-modal .close-form { position: absolute; top: 12px; right: 16px; background: none; border: none; font-size: 24px; cursor: pointer; color: #94a3b8; }
.bonus-form-modal .close-form:hover { color: #475569; }
.pagination { display: flex; justify-content: left; align-items: center; gap: 8px; padding: 10px 6px; flex-wrap: wrap; background: white; border-top: 1px solid #e2e8f0; }
.pagination a, .pagination span { padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; color: #1e293b; }
.pagination a:hover { background: #f1f5f9; }
.pagination .active { background: #2563eb; color: white; }
.pagination .disabled { color: #94a3b8; cursor: not-allowed; }
.page-info { font-size: 13px; color: #64748b; margin-right: 15px; }
</style>

<div class="report-header">
    <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
        <h3 style="font-weight: 500"><?= t('reports') ?></h3>
        <?= renderEmployeeFilterBar($pdo, 'reports', $account_name, $token, (string)$searchQuery, ['start_date' => $dateFrom, 'end_date' => $dateTo], $sid ?? '') ?>
    </div>
</div>

<div class="table-wrapper">
    <table class="reports-table">
        <thead>
            <tr>
                <th>#</th>
                <th><?= t('employee') ?></th>
                <th><?= t('daily_percent') ?></th>
                <th><?= t('worked_days') ?></th>
                <th><?= t('calculated') ?></th>
                <th><?= t('bonus') ?></th>
                <th><?= t('penalty') ?></th>
                <th><?= t('previous_balance') ?></th>
                <th><?= t('total_to_pay') ?></th>
                <th><?= t('paid') ?></th>
                <th><?= t('balance') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($empData)): ?>
            <tr>
                <td colspan="12" style="text-align:center; padding:40px; color:#94a3b8;"><?= t('no_data') ?></td>
            </tr>
            <?php else: ?>
            <?php foreach($empData as $e): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td style="text-align: left;">
                    <span class="employee-name" data-employee='<?= htmlspecialchars(json_encode($e), ENT_QUOTES) ?>' onclick="showPopup(this, event)">
                        <?= htmlspecialchars($e['name']) ?>
                        <small class="employee-details">
                            <?php if(!empty($e['position'])): ?>
                                <?= htmlspecialchars($e['position']) ?>
                            <?php endif; ?>
                        </small>
                    </span>
                </td>
                
                <td><strong><?= $e['daily_display'] ?></strong></td>
                <td><?= $e['attended'] ?> / <?= $e['days_in_month'] ?></td>
                <td class="positive"><?= number_format($e['calculated']) ?> <?= $currencySymbol ?></td>
                <td class="positive"><?= number_format($e['bonus']) ?> <?= $currencySymbol ?></td>
                <td class="negative"><?= number_format($e['penalty']) ?> <?= $currencySymbol ?></td>
                <td class="<?= $e['prev_class'] ?>">
                    <?php if($e['previous_balance'] != 0): ?>
                        <?= $e['prev_sign'] ?> <?= number_format(abs($e['previous_balance'])) ?> <?= $currencySymbol ?>
                    <?php else: ?>
                        0
                    <?php endif; ?>
                </td>
                <td class="positive"><strong><?= number_format($e['total_to_pay']) ?> <?= $currencySymbol ?></strong></td>
                <td class="negative"><?= number_format($e['paid']) ?> <?= $currencySymbol ?></td>
                <td class="<?= $e['balance'] > 0 ? 'negative' : ($e['balance'] < 0 ? 'positive' : '') ?>">
                    <?php if($e['balance'] != 0): ?>
                        <?= number_format(abs($e['balance'])) ?> <?= $currencySymbol ?>
                    <?php else: ?>
                        0
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" class="positive"><strong><?= t('total') ?>:</strong></td>
                <td class="positive"><strong><?= number_format($totalDailyDisplay) ?> <?= $currencySymbol ?></strong></td>
              
                <td class="positive"><strong><?= number_format($totalCalculated) ?> <?= $currencySymbol ?></strong></td>
                <td class="positive"><strong><?= number_format($totalBonus) ?> <?= $currencySymbol ?></strong></td>
                <td class="negative"><strong><?= number_format($totalPenalty) ?> <?= $currencySymbol ?></strong></td>
                <td><strong><?= number_format($totalPreviousBalance) ?> <?= $currencySymbol ?></strong></td>
                <td class="positive"><strong><?= number_format($totalToPaySum) ?> <?= $currencySymbol ?></strong></td>
                <td class="negative"><strong><?= number_format($totalPaid) ?> <?= $currencySymbol ?></strong></td>
                <td class="<?= $totalBalance > 0 ? 'negative' : ($totalBalance < 0 ? 'positive' : '') ?>">
                    <strong><?= number_format(abs($totalBalance)) ?> <?= $currencySymbol ?></strong>
                </td>
            </tr>
        </tfoot>
    </table>
</div>

<!-- PAGINATION -->
<div class="pagination">
    <span class="page-info"><?= t('showing') ?> <?= ($currentPage - 1) * $itemsPerPage + 1 ?> - <?= min($currentPage * $itemsPerPage, $totalEmployees) ?> / <?= $totalEmployees ?> <?= t('employees') ?></span>
    <?php if($currentPage > 1): ?>
    <a href="?tab=reports&start_date=<?= urlencode($dateFrom) ?>&end_date=<?= urlencode($dateTo) ?>&page=<?= $currentPage - 1 ?>&sid=<?= urlencode($sid) ?><?= employeeFilterQueryString() ?><?= !empty($searchQuery) ? '&search='.urlencode($searchQuery) : '' ?>">« <?= t('previous') ?></a>
    <?php else: ?>
    <span class="disabled">« <?= t('previous') ?></span>
    <?php endif; ?>
    <?php
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    if($startPage > 1): ?>
    <a href="?tab=reports&start_date=<?= urlencode($dateFrom) ?>&end_date=<?= urlencode($dateTo) ?>&page=1&sid=<?= urlencode($sid) ?><?= employeeFilterQueryString() ?><?= !empty($searchQuery) ? '&search='.urlencode($searchQuery) : '' ?>">1</a>
    <?php if($startPage > 2): ?><span>...</span><?php endif; ?>
    <?php endif;
    for($p = $startPage; $p <= $endPage; $p++):
        if($p == $currentPage): ?>
        <span class="active"><?= $p ?></span>
        <?php else: ?>
        <a href="?tab=reports&start_date=<?= urlencode($dateFrom) ?>&end_date=<?= urlencode($dateTo) ?>&page=<?= $p ?>&sid=<?= urlencode($sid) ?><?= employeeFilterQueryString() ?><?= !empty($searchQuery) ? '&search='.urlencode($searchQuery) : '' ?>"><?= $p ?></a>
        <?php endif;
    endfor;
    if($endPage < $totalPages):
        if($endPage < $totalPages - 1): ?><span>...</span><?php endif; ?>
        <a href="?tab=reports&start_date=<?= urlencode($dateFrom) ?>&end_date=<?= urlencode($dateTo) ?>&page=<?= $totalPages ?>&sid=<?= urlencode($sid) ?><?= employeeFilterQueryString() ?><?= !empty($searchQuery) ? '&search='.urlencode($searchQuery) : '' ?>"><?= $totalPages ?></a>
    <?php endif; ?>
    <?php if($currentPage < $totalPages): ?>
    <a href="?tab=reports&start_date=<?= urlencode($dateFrom) ?>&end_date=<?= urlencode($dateTo) ?>&page=<?= $currentPage + 1 ?>&sid=<?= urlencode($sid) ?><?= employeeFilterQueryString() ?><?= !empty($searchQuery) ? '&search='.urlencode($searchQuery) : '' ?>"><?= t('next') ?> »</a>
    <?php else: ?>
    <span class="disabled"><?= t('next') ?> »</span>
    <?php endif; ?>
</div>

<!-- MODAL POPUP -->
<div id="employeePopup" class="modal-popup">
    <div class="modal-popup-header">
        <span id="popupEmployeeName"></span>
        <div class="header-actions">
            <button class="btn-add-bonus" onclick="openBonusForm(event)"><?= t('add') ?></button>
            <button class="close-btn" onclick="closePopup()">&times;</button>
        </div>
    </div>
    <div class="modal-popup-body" id="popupContent">
        <div style="text-align:center; padding:40px;"><i class="fas fa-spinner fa-spin"></i> <?= t('loading') ?></div>
    </div>
</div>

<!-- BONUS/JARIMA FORM MODAL -->
<div id="bonusFormModal" class="bonus-form-modal">
    <div class="form-container">
        <button class="close-form" onclick="closeBonusForm()">&times;</button>
        <h3 id="bonusFormTitle"><?= t('add_bonus_penalty') ?></h3>
        <input type="hidden" id="bonusFormEmployeeId" value="">
        <input type="hidden" id="bonusFormMonth" value="<?= $currentMonth ?>-01">
        <input type="hidden" id="bonusFormEditId" value="">
        <div class="form-group">
            <label for="bonusFormType"><?= t('type') ?></label>
            <select id="bonusFormType">
                <option value="bonus"><?= t('bonus') ?></option>
                <option value="penalty"><?= t('penalty') ?></option>
            </select>
        </div>
        <div class="form-group">
            <label for="bonusFormAmount"><?= t('amount') ?> (<?= $currencySymbol ?>)</label>
            <input type="number" id="bonusFormAmount" step="0.01" min="0.01" placeholder="0.00">
        </div>
        <div class="form-group">
            <label for="bonusFormDescription"><?= t('description') ?></label>
            <input type="text" id="bonusFormDescription" placeholder="<?= t('enter_description') ?>">
        </div>
        <div class="form-group">
            <label for="bonusFormDate"><?= t('date') ?></label>
            <input type="date" id="bonusFormDate" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-actions">
            <button class="btn-cancel" onclick="closeBonusForm()"><?= t('cancel') ?></button>
            <button class="btn-submit" onclick="saveBonus()"><?= t('save') ?></button>
        </div>
    </div>
</div>

<script>
// ============================================================
// TARJIMALAR
// ============================================================
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
    "month": "<?= t('month') ?>",
    "hour": "<?= t('hour') ?>",
    "mo.": "<?= t('mo.') ?>",
    "grace": "<?= t('grace') ?>",
    "no_data": "<?= t('no_data') ?>",
    "fixed": "<?= t('fixed') ?>",
    "hourly": "<?= t('hourly') ?>",
    "percent": "<?= t('percent') ?>",
    "shift": "<?= t('shift') ?>"
};

// ============================================================
// POPUP FUNKSIYALARI
// ============================================================
let currentPopup = null;
let currentEmployeeData = null;
const monthNames = <?= json_encode($months) ?>;
const currencySymbol = '<?= $currencySymbol ?>';
const currentMonth = '<?= $currentMonth ?>';
const csrfToken = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
const dateFrom = '<?= $dateFrom ?>';

function showPopup(element, event) {
    const employeeData = JSON.parse(element.getAttribute('data-employee'));
    currentEmployeeData = employeeData;
    const popup = document.getElementById('employeePopup');
    const popupName = document.getElementById('popupEmployeeName');
    const popupContent = document.getElementById('popupContent');

    let employeeInfo = escapeHtml(employeeData.name);
    if (employeeData.position) {
        employeeInfo += ' <small style="font-weight:400;color:#64748b;">(' + escapeHtml(employeeData.position) + ')</small>';
    }
    popupName.innerHTML = employeeInfo;

    let entriesByDate = {};
    let totalBonus = 0;
    let totalPenalty = 0;
    let totalPaid = 0;
    let dailySalaries = employeeData.daily_salaries || {};
    let dailyDetails = employeeData.daily_details || {};
    let dailyStatuses = employeeData.attendance_statuses || {};

    const previousBalance = employeeData.previous_balance || 0;

    if (previousBalance !== 0) {
        const bonusDate = dateFrom;
        if (!entriesByDate[bonusDate]) entriesByDate[bonusDate] = [];
        entriesByDate[bonusDate].push({
            time: '',
            description: previousBalance > 0 ? translations.previous_debt : translations.previous_overpaid,
            amount: Math.abs(previousBalance),
            type: previousBalance > 0 ? 'previous_debt' : 'previous_overpaid'
        });
    }

    for (let day = 1; day <= employeeData.days_in_month; day++) {
        const dateStr = dateFrom.substring(0, 7) + '-' + day.toString().padStart(2, '0');
        const daySalary = dailySalaries[dateStr] || 0;
        const status = dailyStatuses[dateStr] || 'absent';
        const details = dailyDetails[dateStr] || {};

        if (daySalary <= 0 && status == 'absent') continue;
        if (!entriesByDate[dateStr]) entriesByDate[dateStr] = [];

        const latePenalty = details.late_penalty || 0;
        const earlyLeavePenalty = details.early_leave_penalty || 0;
        const earlyArrivalBonus = details.early_arrival_bonus || 0;
        const lateLeaveBonus = details.late_leave_bonus || 0;

        // 1. KECH KELISH (JARIMA)
        if (latePenalty > 0 && details.in_late_minutes > 0) {
            const maxPenalty = employeeData.kech_kelish_max_jarima;
            totalPenalty += latePenalty;

            let checkInTime = details.check_in || 'N/A';
            if (checkInTime !== 'N/A' && checkInTime.length >= 5) {
                checkInTime = checkInTime.substring(0, 5);
            }

            let description = `${translations.late_arrival} ${details.in_late_minutes} ${translations.minutes}`;
            if (maxPenalty !== null && maxPenalty !== undefined && maxPenalty > 0 && latePenalty >= maxPenalty) {
                description += ` ${translations.max_penalty}`;
            }

            entriesByDate[dateStr].push({
                time: checkInTime,
                description: description,
                amount: latePenalty,
                type: 'penalty'
            });
        }

        // 2. ERTA KELISH (BONUS)
        if (earlyArrivalBonus > 0) {
            totalBonus += earlyArrivalBonus;

            let checkInTime = details.check_in || 'N/A';
            if (checkInTime !== 'N/A') {
                if (checkInTime.includes(' ')) checkInTime = checkInTime.split(' ')[1];
                if (checkInTime.length > 5) checkInTime = checkInTime.substring(0, 5);
            }

            const earlyMinutes = details.in_late_minutes < 0 ? Math.abs(details.in_late_minutes) : 0;
            let description = `${translations.early_arrival} ${earlyMinutes} ${translations.minutes}`;
            
            entriesByDate[dateStr].push({
                time: checkInTime,
                description: description,
                amount: earlyArrivalBonus,
                type: 'bonus'
            });
        }

        // 3. ERTA KETISH (JARIMA)
        if (earlyLeavePenalty > 0 && details.out_early_minutes < 0) {
            const maxPenalty = employeeData.erta_ketish_max_jarima;
            totalPenalty += earlyLeavePenalty;

            let checkOutTime = details.check_out || 'N/A';
            if (checkOutTime !== 'N/A' && checkOutTime.length >= 5) {
                checkOutTime = checkOutTime.substring(0, 5);
            }

            const earlyMinutes = Math.abs(details.out_early_minutes);
            let description = `${translations.early_departure} ${earlyMinutes} ${translations.minutes}`;
            if (maxPenalty !== null && maxPenalty !== undefined && maxPenalty > 0 && earlyLeavePenalty >= maxPenalty) {
                description += ` ${translations.max_penalty}`;
            }

            entriesByDate[dateStr].push({
                time: checkOutTime,
                description: description,
                amount: earlyLeavePenalty,
                type: 'penalty'
            });
        }

        // 4. KECH KETISH (BONUS)
        if (lateLeaveBonus > 0) {
            totalBonus += lateLeaveBonus;

            let checkOutTime = details.check_out || 'N/A';
            if (checkOutTime !== 'N/A' && checkOutTime.length >= 5) {
                checkOutTime = checkOutTime.substring(0, 5);
            }

            const lateMinutes = details.out_early_minutes > 0 ? details.out_early_minutes : 0;
            let description = `${translations.late_departure} ${lateMinutes} ${translations.minutes}`;
            
            entriesByDate[dateStr].push({
                time: checkOutTime,
                description: description,
                amount: lateLeaveBonus,
                type: 'bonus'
            });
        }

        // 5. KUNLIK MAOSH
        if (daySalary > 0) {
            let checkOutTime = details.check_out || 'N/A';
            if (checkOutTime !== 'N/A' && checkOutTime.length >= 5) {
                checkOutTime = checkOutTime.substring(0, 5);
            }

            const maoshTurlari = details.maosh_turi_array || ['fixed'];
            const typeText = maoshTurlari.map(t => {
                if(t == 'fixed') return translations.fixed;
                if(t == 'hourly') return translations.hourly;
                if(t == 'percent') return translations.percent;
                if(t == 'shift') return translations.shift;
                return t;
            }).join(' + ');

            entriesByDate[dateStr].push({
                time: checkOutTime,
                description: `${translations.daily_salary} (${typeText})`,
                amount: daySalary,
                type: 'salary'
            });
        }
    }

    // 6. QO'SHIMCHA BONUS/JARIMALAR
    if (employeeData.bonus_items) {
        for (let b of employeeData.bonus_items) {
            if (b.miqdor <= 0) continue;

            const bonusDate = b.sana || dateFrom;
            if (!entriesByDate[bonusDate]) entriesByDate[bonusDate] = [];
            const isBonus = b.turi == 'bonus';
            if (isBonus) totalBonus += b.miqdor;
            else totalPenalty += b.miqdor;

            entriesByDate[bonusDate].push({
                time: '',
                description: (isBonus ? '' : '') + (b.izoh || (isBonus ? translations.bonus : translations.penalty)),
                amount: b.miqdor,
                type: b.turi,
                id: b.id,
                is_manual: true
            });
        }
    }

    // 7. POSTER TO'LOVLAR
    if (employeeData.transactions) {
        for (let t of employeeData.transactions) {
            if (t.amount <= 0) continue;

            const tranDate = t.date.substring(0, 10);
            let tranTime = t.date.length > 10 ? t.date.substring(11, 16) : '00:00';
            if (tranTime.length > 5) {
                tranTime = tranTime.substring(0, 5);
            }

            if (!entriesByDate[tranDate]) entriesByDate[tranDate] = [];
            totalPaid += t.amount;

            let description = '';
            if (t.full_comment && t.full_comment.trim() !== '') {
                description = t.full_comment;
            } else if (t.comment && t.comment.trim() !== '') {
                description = t.comment;
            }
            if (t.method && t.method.trim() !== '' && !t.full_comment?.includes(t.method)) {
                description += ' (' + t.method + ')';
            }

            entriesByDate[tranDate].push({
                time: tranTime,
                description: description || translations.poster_payment,
                amount: t.amount,
                type: 'payment'
            });
        }
    }

    // 8. HTML YARATISH
    const sortedDates = Object.keys(entriesByDate).sort((a, b) => new Date(b) - new Date(a));

    if (sortedDates.length === 0) {
        popupContent.innerHTML = `
            <div style="text-align:center; padding:40px; color:#94a3b8;">
                <i class="fas fa-info-circle" style="font-size:24px; display:block; margin-bottom:10px;"></i>
                ${translations.no_data || 'Ma\'lumotlar mavjud emas'}
            </div>
        `;
        popup.classList.add('active');
        popup.style.display = 'block';

        const rect = element.getBoundingClientRect();
        let leftPos = rect.right + 10, topPos = rect.top - 10;
        if (leftPos + 450 > window.innerWidth) leftPos = rect.left - 460;
        if (topPos < 0) topPos = 5;
        if (topPos + 400 > window.innerHeight) topPos = window.innerHeight - 410;
        popup.style.left = leftPos + 'px';
        popup.style.top = topPos + 'px';
        currentPopup = popup;
        return;
    }

    let html = `
    <div style="flex:1;overflow-y:auto;scrollbar-width:none;-ms-overflow-style:none;">
        <table class="popup-table" style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:6px 8px;background:#f8fafc;border-bottom:2px solid #e2e8f0;font-weight:400;color:#64748b;position:sticky;top:0;width:60px;font-size:11px;z-index:10;">${translations.date}</th>
                    <th style="text-align:left;padding:6px 8px;background:#f8fafc;border-bottom:2px solid #e2e8f0;font-weight:400;color:#64748b;position:sticky;top:0;width:45px;font-size:11px;z-index:10;">${translations.time}</th>
                    <th style="text-align:left;padding:6px 8px;background:#f8fafc;border-bottom:2px solid #e2e8f0;font-weight:400;color:#64748b;position:sticky;top:0;font-size:11px;z-index:10;">${translations.description}</th>
                    <th style="text-align:right;padding:6px 8px;background:#f8fafc;border-bottom:2px solid #e2e8f0;font-weight:400;color:#64748b;position:sticky;top:0;width:90px;font-size:11px;z-index:10;">${translations.amount}</th>
                    <th style="text-align:center;padding:6px 8px;background:#f8fafc;border-bottom:2px solid #e2e8f0;font-weight:400;color:#64748b;position:sticky;top:0;width:70px;font-size:11px;z-index:10;">${translations.actions}</th>
                </tr>
            </thead>
            <tbody>
    `;

    for (let i = 0; i < sortedDates.length; i++) {
        const date = sortedDates[i];
        const entries = entriesByDate[date];
        const dateObj = new Date(date);
        const day = dateObj.getDate();
        const month = monthNames[dateObj.getMonth()] || monthNames[0];
        const formattedDate = `${day} ${month}`;

        entries.sort((a, b) => {
            if (a.type == 'previous_debt' || a.type == 'previous_overpaid') return -1;
            if (b.type == 'previous_debt' || b.type == 'previous_overpaid') return 1;
            if (a.type == 'penalty' && b.type != 'penalty') return -1;
            if (b.type == 'penalty' && a.type != 'penalty') return 1;
            if (a.type == 'bonus' && b.type != 'bonus' && b.type != 'penalty') return -1;
            if (b.type == 'bonus' && a.type != 'bonus' && a.type != 'penalty') return 1;
            if (a.type == 'payment' && b.type != 'payment' && b.type != 'penalty' && b.type != 'bonus') return -1;
            if (b.type == 'payment' && a.type != 'payment' && a.type != 'penalty' && a.type != 'bonus') return 1;
            if (a.type != 'salary' && b.type == 'salary') return -1;
            if (a.type == 'salary' && b.type != 'salary') return 1;
            return 0;
        });

        let showDate = true;
        for (let j = 0; j < entries.length; j++) {
            const entry = entries[j];
            let amountClass = '', amountSign = '';
            const dateDisplay = showDate ? formattedDate : '';
            showDate = false;

            if (entry.type == 'salary') {
                amountClass = 'salary';
                amountSign = '+';
            } else if (entry.type == 'bonus') {
                amountClass = 'income';
                amountSign = '+';
            } else if (entry.type == 'penalty') {
                amountClass = 'expense';
                amountSign = '-';
            } else if (entry.type == 'payment') {
                amountClass = 'expense';
                amountSign = '-';
            } else if (entry.type == 'income') {
                amountClass = 'income';
                amountSign = '+';
            } else if (entry.type == 'previous_debt') {
                amountClass = 'expense';
                amountSign = '';
            } else if (entry.type == 'previous_overpaid') {
                amountClass = 'income';
                amountSign = '';
            }

            const style = 'padding:4px 8px 4px 8px;';
            let colorClass = '';
            if (entry.type == 'previous_debt') {
                colorClass = 'color:#ef4444;';
            } else if (entry.type == 'previous_overpaid') {
                colorClass = 'color:#10b981;';
            } else {
                colorClass = amountClass == 'income' || amountClass == 'salary' ? 'color:#10b981;' : (amountClass == 'expense' ? 'color:#ef4444;' : '');
            }

            let actionsHtml = '';
            if (entry.is_manual && entry.id) {
                actionsHtml = `
                    <button class="bonus-action-btn btn-edit" onclick="event.stopPropagation(); editBonus(${entry.id})" title="${translations.edit}">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="bonus-action-btn btn-delete" onclick="event.stopPropagation(); deleteBonus(${entry.id})" title="${translations.delete}">
                        <i class="fas fa-trash"></i>
                    </button>
                `;
            }

            html += `<tr>
                <td style="white-space:nowrap;${style}">${dateDisplay}</td>
                <td style="white-space:nowrap;${style}">${entry.time || '00:00'}</td>
                <td style="${style}">${escapeHtml(entry.description)}</td>
                <td style="white-space:nowrap;text-align:right;${style}${colorClass}">
                    <strong>${amountSign} ${formatNumber(entry.amount)} ${currencySymbol}</strong>
                </td>
                <td style="white-space:nowrap;text-align:center;${style}">
                    ${actionsHtml}
                </td>
            </tr>`;
        }
    }

    html += '</tbody></table></div>';

    const totalAmount = employeeData.balance;
    let totalClass = '';
    let totalSign = '';
    let totalLabel = '';

    if (totalAmount > 0) {
        totalClass = 'color:#ef4444;';
        totalSign = '';
        totalLabel = translations.debt;
    } else if (totalAmount < 0) {
        totalClass = 'color: #10b981;';
        totalSign = '';
        totalLabel = translations.overpaid;
    } else {
        totalClass = 'color:#64748b;';
        totalSign = '';
        totalLabel = translations.zero_balance;
    }

    html += `
    <div class="popup-footer" style="flex-shrink:0;font-size:13px;padding:8px 10px;background:#fbfbfb;border-top:1px solid #d5d5d5;display:flex;justify-content:space-between;align-items:center;font-weight:400;position:sticky;bottom:0;z-index:10;">
        <strong>${totalLabel}:</strong>
        <strong style="${totalClass}">${totalSign} ${formatNumber(Math.abs(totalAmount))} ${currencySymbol}</strong>
    </div>
    `;

    popupContent.style.display = 'flex';
    popupContent.style.flexDirection = 'column';
    popupContent.style.height = '100%';
    popupContent.style.maxHeight = '100%';
    popupContent.innerHTML = html;

    const rect = element.getBoundingClientRect();
    let leftPos = rect.right + 10, topPos = rect.top - 10;
    if (leftPos + 650 > window.innerWidth) leftPos = rect.left - 660;
    if (topPos < 0) topPos = 5;
    if (topPos + 600 > window.innerHeight) topPos = window.innerHeight - 610;

    popup.style.left = leftPos + 'px';
    popup.style.top = topPos + 'px';
    popup.classList.add('active');
    popup.style.display = 'block';
    currentPopup = popup;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function (m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        if (m === '"') return '&quot;';
        if (m === "'") return '&#39;';
        return m;
    });
}

function closePopup() {
    const popup = document.getElementById('employeePopup');
    if (popup) {
        popup.classList.remove('active');
        popup.style.display = 'none';
    }
    currentPopup = null;
    currentEmployeeData = null;
}

function formatNumber(num) {
    if (isNaN(num)) return '0';
    return new Intl.NumberFormat('uz-UZ').format(Math.round(num));
}

function openBonusForm(event) {
    if (event) event.stopPropagation();
    if (!currentEmployeeData) {
        alert(translations.please_select_employee);
        return;
    }

    const modal = document.getElementById('bonusFormModal');
    document.getElementById('bonusFormEmployeeId').value = currentEmployeeData.id;
    document.getElementById('bonusFormMonth').value = currentMonth + '-01';
    document.getElementById('bonusFormEditId').value = '';
    document.getElementById('bonusFormTitle').textContent = translations.add_bonus_penalty;
    document.getElementById('bonusFormType').value = 'bonus';
    document.getElementById('bonusFormAmount').value = '';
    document.getElementById('bonusFormDescription').value = '';
    document.getElementById('bonusFormDate').value = '<?= date('Y-m-d') ?>';
    document.querySelector('.btn-submit').textContent = translations.save;
    document.querySelector('.btn-submit').className = 'btn-submit';

    modal.classList.add('active');
}

function closeBonusForm() {
    document.getElementById('bonusFormModal').classList.remove('active');
}

function editBonus(id) {
    if (!currentEmployeeData) {
        alert(translations.please_select_employee);
        return;
    }

    const item = currentEmployeeData.bonus_items.find(b => b.id == id);
    if (!item) {
        alert(translations.record_not_found);
        return;
    }

    const modal = document.getElementById('bonusFormModal');
    document.getElementById('bonusFormEmployeeId').value = currentEmployeeData.id;
    document.getElementById('bonusFormMonth').value = currentMonth + '-01';
    document.getElementById('bonusFormEditId').value = id;
    document.getElementById('bonusFormTitle').textContent = translations.edit_bonus_penalty;
    document.getElementById('bonusFormType').value = item.turi;
    document.getElementById('bonusFormAmount').value = item.miqdor;
    document.getElementById('bonusFormDescription').value = item.izoh || '';
    document.getElementById('bonusFormDate').value = item.sana || '<?= date('Y-m-d') ?>';
    document.querySelector('.btn-submit').textContent = translations.update;
    document.querySelector('.btn-submit').className = 'btn-submit btn-edit-mode';

    modal.classList.add('active');
}

function deleteBonus(id) {
    if (!confirm(translations.delete_confirm)) return;
    if (!currentEmployeeData) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    formData.append('employee_id', currentEmployeeData.id);
    formData.append('csrf_token', csrfToken);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(translations.success_delete);
            location.reload();
        } else {
            alert(translations.error_occurred + ': ' + data.message);
        }
    })
    .catch(error => {
        alert(translations.server_error + ' ' + error.message);
    });
}

function saveBonus() {
    const employeeId = document.getElementById('bonusFormEmployeeId').value;
    const editId = document.getElementById('bonusFormEditId').value;
    const type = document.getElementById('bonusFormType').value;
    const amount = parseFloat(document.getElementById('bonusFormAmount').value);
    const description = document.getElementById('bonusFormDescription').value.trim();
    const date = document.getElementById('bonusFormDate').value;
    const month = document.getElementById('bonusFormMonth').value;

    if (!employeeId || !amount || amount <= 0) {
        alert(translations.please_enter_amount);
        return;
    }

    const action = editId ? 'update' : 'add';

    const formData = new FormData();
    formData.append('action', action);
    formData.append('employee_id', employeeId);
    formData.append('oy_vaqt', month);
    formData.append('turi', type);
    formData.append('miqdor', amount);
    formData.append('izoh', description || (type == 'bonus' ? translations.bonus : translations.penalty));
    formData.append('sana', date || '<?= date('Y-m-d') ?>');
    formData.append('csrf_token', csrfToken);

    if (editId) {
        formData.append('id', editId);
    }

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(action == 'add' ? translations.success_save : translations.success_update);
            closeBonusForm();
            location.reload();
        } else {
            alert(translations.error_occurred + ': ' + data.message);
        }
    })
    .catch(error => {
        alert(translations.server_error + ' ' + error.message);
    });
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        if (currentPopup) closePopup();
        if (document.getElementById('bonusFormModal').classList.contains('active')) closeBonusForm();
    }
});

document.addEventListener('click', function (e) {
    if (currentPopup && !e.target.classList.contains('employee-name') && !currentPopup.contains(e.target)) {
        closePopup();
    }
    const modal = document.getElementById('bonusFormModal');
    if (modal.classList.contains('active') && e.target === modal) {
        closeBonusForm();
    }
});

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
