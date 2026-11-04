<?php
// includes/functions/report.php - Hisobot funksiyalari (TO'LIQ VA TO'G'RI)

// ============================================
// 1. MAOSH SOZLAMASINI OLISH
// ============================================
function getEmployeeSalarySetting($employeeId, $month) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT oylik_maosh, maosh_turi, foiz_qiymati, poster_user_id 
                           FROM oylik_sozlamalar 
                           WHERE xodim_id = ? AND oy_vaqt = ?");
    $stmt->execute([$employeeId, $month . '-01']);
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$setting) {
        $stmt = $pdo->prepare("SELECT turi, oylik_miqdori, foiz_miqdori, poster_user_id FROM xodimlar WHERE id = ?");
        $stmt->execute([$employeeId]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'type' => $emp['turi'] ?? 'fixed',
            'salary' => (float)($emp['oylik_miqdori'] ?? 0),
            'percent' => (float)($emp['foiz_miqdori'] ?? 0),
            'poster_user_id' => $emp['poster_user_id'] ?? null
        ];
    }
    
    return [
        'type' => $setting['maosh_turi'],
        'salary' => (float)($setting['oylik_maosh'] ?? 0),
        'percent' => (float)($setting['foiz_qiymati'] ?? 0),
        'poster_user_id' => $setting['poster_user_id'] ?? null
    ];
}

// ============================================
// 2. ISHLAGAN KUNLARNI HISOBLASH
// ============================================
function getEmployeeWorkedDays($employeeId, $dateFrom, $dateTo) {
    global $pdo;
    
    $attended = 0;
    $attendedDates = [];
    
    $stmt = $pdo->prepare("SELECT sana, davomat_holati FROM davomat 
                           WHERE xodim_id = ? AND sana BETWEEN ? AND ?");
    $stmt->execute([$employeeId, $dateFrom, $dateTo]);
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['davomat_holati'];
        if(in_array($status, ['present', 'overtime', 'vacation', 'sick'])) {
            $attended += 1;
            $attendedDates[] = $row['sana'];
        } elseif($status == 'half_day') {
            $attended += 0.5;
            $attendedDates[] = $row['sana'];
        }
    }
    
    return ['attended' => $attended, 'dates' => $attendedDates];
}

// ============================================
// 3. FOIZLI MAOSH HISOBLASH
// ============================================
function calculatePercentSalary($employeeId, $dateFrom, $dateTo, $percentValue, $posterUserId) {
    if(empty($posterUserId) || $percentValue == 0) return 0;
    
    $worked = getEmployeeWorkedDays($employeeId, $dateFrom, $dateTo);
    $totalSales = 0;
    
    foreach($worked['dates'] as $date) {
        $dailyRevenue = getWaiterRevenueForReport($posterUserId, $date);
        $totalSales += $dailyRevenue;
    }
    
    return $totalSales * ($percentValue / 100);
}

// ============================================
// 4. BELGILANGAN MAOSH HISOBLASH
// ============================================
function calculateFixedSalary($monthlySalary, $attended, $daysInMonth) {
    if($daysInMonth == 0) return 0;
    return ($monthlySalary / $daysInMonth) * $attended;
}

// ============================================
// 5. BONUS VA JARIMANI OLISH
// ============================================
function getBonusPenaltyTotal($employeeId, $month) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT 
        COALESCE(SUM(CASE WHEN turi = 'bonus' THEN miqdor ELSE 0 END), 0) as bonus,
        COALESCE(SUM(CASE WHEN turi = 'penalty' THEN miqdor ELSE 0 END), 0) as penalty 
        FROM bonus_jarima WHERE xodim_id = ? AND oy_vaqt = ?");
    $stmt->execute([$employeeId, $month . '-01']);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'bonus' => (float)($data['bonus'] ?? 0),
        'penalty' => (float)($data['penalty'] ?? 0)
    ];
}

// ============================================
// 6. OFITSIANT SAVDOSINI OLISH
// ============================================
function getWaiterRevenueForReport($posterUserId, $date) {
    if(empty($posterUserId)) return 0;
    
    $token = getAccessToken();
    $domain = getPosterDomain();
    
    if(!$token) return 0;
    
    $dateFrom = date('Ymd', strtotime($date));
    $dateTo = date('Ymd', strtotime($date));
    
    $url = "https://{$domain}.joinposter.com/api/dash.getWaitersSales?token=" . $token;
    $url .= "&dateFrom=" . $dateFrom;
    $url .= "&dateTo=" . $dateTo;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    //curl_close($ch);
    
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

// ============================================
// 7. TRANZAKSIYALARNI OLISH (POSTER DAN)
// ============================================
function getEmployeeTransactionsForReport($posterId, $dateFrom, $dateTo) {
    $token = getAccessToken();
    $domain = getPosterDomain();
    
    if(!$token || !$posterId) {
        return ['transactions' => [], 'total_paid' => 0];
    }
    
    $apiDateFrom = str_replace('-', '', $dateFrom);
    $apiDateTo = str_replace('-', '', $dateTo);
    
    $url = "https://{$domain}.joinposter.com/api/finance.getTransactions?token=" . $token;
    $url .= "&dateFrom=" . $apiDateFrom;
    $url .= "&dateTo=" . $apiDateTo;
    $url .= "&category_id=" . $posterId;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    //curl_close($ch);
    
    if($httpCode != 200) {
        return ['transactions' => [], 'total_paid' => 0];
    }
    
    $data = json_decode($response, true);
    
    $totalPaid = 0;
    $transactionList = [];
    
    if(isset($data['response']) && is_array($data['response'])) {
        foreach($data['response'] as $t) {
            if (!is_array($t) || (string)($t['delete'] ?? '0') === '1') {
                continue;
            }
            if (!isset($t['amount']) || !is_numeric($t['amount'])) {
                continue;
            }
            $amountInSom = abs((float)$t['amount']) / 100;
            $totalPaid += $amountInSom;
            $transactionList[] = [
                'date' => $t['transaction_date'] ?? $t['date'] ?? '',
                'amount' => $amountInSom,
                'is_payment' => true,
                'comment' => $t['comment'] ?? ''
            ];
        }
    }
    
    return ['transactions' => $transactionList, 'total_paid' => $totalPaid];
}
// ============================================
// 8. O'TGAN OY BALANSINI OLISH (FAQAT O'TGAN OY)
// ============================================
function getPreviousMonthBalance($employeeId, $currentMonth) {
    global $pdo;
    
    // O'tgan oyni aniqlash
    $date = DateTime::createFromFormat('Y-m', $currentMonth);
    $date->modify('-1 month');
    $previousMonth = $date->format('Y-m') . '-01';
    
    // O'tgan oyning qarzini (balansini) olish
    $stmt = $pdo->prepare("SELECT qarzdorlik FROM oylik_sozlamalar 
                           WHERE xodim_id = ? AND oy_vaqt = ?");
    $stmt->execute([$employeeId, $previousMonth]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // O'tgan oyning balansi (qarzdorlik) - bu joriy oyning "O'tgan oy farqi"
    $previousBalance = $result ? (float)$result['qarzdorlik'] : 0;
    
    return $previousBalance;
}

// ============================================
// 9. JORIY OY TO'LOVLARINI OLISH
// ============================================
function getCurrentMonthPayments($employeeId, $dateFrom, $dateTo) {
    $emp = getEmployeeById($employeeId);
    if(!$emp || empty($emp['poster_id'])) return 0;
    
    $trans = getEmployeeTransactionsForReport($emp['poster_id'], $dateFrom, $dateTo);
    return $trans['total_paid'] ?? 0;
}

// ============================================
// 10. ASOSIY HISOBLASH FUNKSIYASI (TO'G'RI VERSIYA)
// ============================================
function calculateMonthlySalaryWithDetails($employeeId, $dateFrom, $dateTo) {
    global $pdo, $currencySymbol;
    
    $month = date('Y-m', strtotime($dateFrom));
    $daysInMonth = (int)date('t', strtotime($dateFrom));
    $monthStart = $month . '-01';
    
    $emp = getEmployeeById($employeeId);
    if(!$emp) return [];
    
    // 1. Maosh sozlamalari
    $salarySetting = getEmployeeSalarySetting($employeeId, $month);
    
    // 2. Ishlagan kunlar
    $worked = getEmployeeWorkedDays($employeeId, $dateFrom, $dateTo);
    $attended = $worked['attended'];
    
    // 3. Hisoblangan maosh (joriy oy uchun)
    if($salarySetting['type'] == 'percent') {
        $calculatedSalary = calculatePercentSalary(
            $employeeId, $dateFrom, $dateTo,
            $salarySetting['percent'],
            $salarySetting['poster_user_id']
        );
        $dailyRateDisplay = $salarySetting['percent'] . '%';
    } else {
        $calculatedSalary = calculateFixedSalary($salarySetting['salary'], $attended, $daysInMonth);
        $dailyRate = $daysInMonth > 0 ? $salarySetting['salary'] / $daysInMonth : 0;
        $dailyRateDisplay = number_format($dailyRate) . ' ' . ($currencySymbol ?? 'so\'m');
    }
    
    // 4. Bonus/jarima
    $bonusPenalty = getBonusPenaltyTotal($employeeId, $month);
    $calculatedWithBonus = $calculatedSalary + $bonusPenalty['bonus'] - $bonusPenalty['penalty'];
    
    // 5. O'tgan oyning qarzi (balansi) - BU MUHIM!
    $previousBalance = getPreviousMonthBalance($employeeId, $month);
    
    // 6. Jami to'lov (hisoblangan + o'tgan oy qarzi)
    $totalToPay = $calculatedWithBonus + $previousBalance;
    
    // 7. Joriy oyda qilingan to'lovlar (Poster dan)
    $paid = getCurrentMonthPayments($employeeId, $dateFrom, $dateTo);
    
    // 8. Yangi balans (keyingi oyga o'tadigan qarz)
    $newBalance = $totalToPay - $paid;
    
    // 9. BAZAGA SAQLASH (keyingi oy uchun)
    $stmt = $pdo->prepare("INSERT INTO oylik_sozlamalar 
        (xodim_id, oy_vaqt, oylik_maosh, hisoblangan_maosh, qarzdorlik, jami_to_lov, oldingi_oy_balansi, ishlagan_kun) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        hisoblangan_maosh = VALUES(hisoblangan_maosh),
        qarzdorlik = VALUES(qarzdorlik),
        jami_to_lov = VALUES(jami_to_lov),
        oldingi_oy_balansi = VALUES(oldingi_oy_balansi),
        ishlagan_kun = VALUES(ishlagan_kun)");
    
    $stmt->execute([
        $employeeId,
        $monthStart,
        $salarySetting['salary'],
        round($calculatedWithBonus),
        round($newBalance),        // BU KEYINGI OYGA O'TADI
        round($totalToPay),
        round($previousBalance),   // BU O'TGAN OYning balansi
        $attended
    ]);
    
    // 10. Ko'rinish uchun ma'lumotlar
    $attendedDisplay = $attended . ' / ' . $daysInMonth;
    
    if($newBalance > 0) {
        $balanceClass = 'positive';
        $balanceText = 'Qarzdor';
    } elseif($newBalance < 0) {
        $balanceClass = 'negative';
        $balanceText = 'Ortiqcha to\'langan';
    } else {
        $balanceClass = '';
        $balanceText = 'Balans nol';
    }
    
    if($previousBalance > 0) {
        $prevClass = 'negative';
        $prevSign = '+';
        $prevText = 'qarz';
    } elseif($previousBalance < 0) {
        $prevClass = 'positive';
        $prevSign = '-';
        $prevText = 'ortiqcha';
    } else {
        $prevClass = '';
        $prevSign = '';
        $prevText = '';
    }
    
    return [
        'calculated' => round($calculatedWithBonus),
        'previous_balance' => $previousBalance,
        'prev_class' => $prevClass,
        'prev_sign' => $prevSign,
        'prev_text' => $prevText,
        'total_to_pay' => round($totalToPay),
        'paid' => round($paid),
        'balance' => round($newBalance),
        'balance_class' => $balanceClass,
        'balance_text' => $balanceText,
        'daily_rate_display' => $dailyRateDisplay,
        'attended_display' => $attendedDisplay,
        'salary_type' => $salarySetting['type']
    ];
}

// ============================================
// 11. OLDINGI VERSIYA BILAN MOSLASH UCHUN (ALIAS)
// ============================================
function calculateAccumulatedPreviousBalance($employeeId, $currentMonth) {
    return getPreviousMonthBalance($employeeId, $currentMonth);
}
?>