<?php
// includes/functions/salary.php - Ish haqi hisoblash

function addBonusPenalty($employeeId, $month, $type, $amount, $note = '') {
    global $pdo;
    
    $stmt = $pdo->prepare("INSERT INTO bonus_jarima (xodim_id, oy_vaqt, turi, miqdor, izoh) VALUES (?, ?, ?, ?, ?)");
    return $stmt->execute([$employeeId, $month . '-01', $type, $amount, $note]);
}

function getBonusPenalty($employeeId, $month) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM bonus_jarima WHERE xodim_id = ? AND oy_vaqt = ?");
    $stmt->execute([$employeeId, $month . '-01']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function deleteBonusPenalty($id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM bonus_jarima WHERE id = ?");
    return $stmt->execute([$id]);
}

function getEmployeeSales($employeeId, $month) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT poster_id FROM xodimlar WHERE id = ?");
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$emp || !$emp['poster_id']) return 0;
    
    $startDate = str_replace('-', '', $month . '-01');
    $endDate = str_replace('-', '', date('Y-m-t', strtotime($month . '-01')));
    
    $transactions = posterApi('finance.getTransactions', [
        'dateFrom' => $startDate,
        'dateTo' => $endDate,
        'category_id' => $emp['poster_id']
    ]);
    
    $total = 0;
    foreach($transactions['response'] ?? [] as $t) {
        $total += abs($t['amount'] ?? 0) / 100;
    }
    
    return $total;
}

function getCurrentMonthPayment($employeeId, $month) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT poster_id FROM xodimlar WHERE id = ?");
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$emp || !$emp['poster_id']) return 0;
    
    $startDate = str_replace('-', '', $month . '-01');
    $endDate = str_replace('-', '', date('Y-m-t', strtotime($month . '-01')));
    
    $transactions = posterApi('finance.getTransactions', [
        'dateFrom' => $startDate,
        'dateTo' => $endDate,
        'category_id' => $emp['poster_id']
    ]);
    
    $total = 0;
    foreach($transactions['response'] ?? [] as $t) {
        $total += abs($t['amount'] ?? 0) / 100;
    }
    
    return $total;
}


function calculateSalary($employeeId, $month, $monthlySalary, $salaryType = 'fixed', $percentValue = 0) {
    global $pdo, $workHoursPerDay, $overtimeRate;
    
    $workHoursPerDay = $workHoursPerDay ?? 8;
    $overtimeRate = $overtimeRate ?? 1.5;
    
    $daysInMonth = getDaysInMonth($month);
    $dailyRate = $daysInMonth > 0 ? $monthlySalary / $daysInMonth : 0;
    
    $stmt = $pdo->prepare("SELECT sana, davomat_holati, ish_soati, qoshimcha_soat FROM davomat WHERE xodim_id = ? AND sana LIKE ?");
    $stmt->execute([$employeeId, $month . '%']);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $attended = 0;
    $overtimeHours = 0;
    
    foreach($attendance as $day) {
        $status = $day['davomat_holati'];
        switch($status) {
            case 'present': $attended += 1; break;
            case 'half_day': $attended += 0.5; break;
            case 'overtime': $attended += 1; $overtimeHours += $day['qoshimcha_soat']; break;
            case 'vacation': case 'sick': $attended += 1; break;
        }
    }
    
    $stmt = $pdo->prepare("SELECT turi, miqdor FROM bonus_jarima WHERE xodim_id = ? AND oy_vaqt = ?");
    $stmt->execute([$employeeId, $month . '-01']);
    $bonusItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalBonus = 0;
    $totalPenalty = 0;
    foreach($bonusItems as $item) {
        if($item['turi'] == 'bonus') $totalBonus += $item['miqdor'];
        else $totalPenalty += $item['miqdor'];
    }
    
    if($salaryType == 'percent') {
        $totalSales = getEmployeeSales($employeeId, $month);
        $currentCalculated = $totalSales * ($percentValue / 100);
    } else {
        $currentCalculated = $dailyRate * $attended;
        if($overtimeHours > 0 && $workHoursPerDay > 0) {
            $hourlyRate = $dailyRate / $workHoursPerDay;
            $currentCalculated += $hourlyRate * $overtimeHours * $overtimeRate;
        }
    }
    
    $currentCalculated += $totalBonus - $totalPenalty;
    
    $stmt = $pdo->prepare("INSERT INTO oylik_sozlamalar 
        (xodim_id, oy_vaqt, oylik_maosh, maosh_turi, foiz_qiymati, bonus, jarima, ishlagan_kun, hisoblangan_maosh) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        oylik_maosh = VALUES(oylik_maosh), maosh_turi = VALUES(maosh_turi), foiz_qiymati = VALUES(foiz_qiymati),
        bonus = VALUES(bonus), jarima = VALUES(jarima), ishlagan_kun = VALUES(ishlagan_kun), hisoblangan_maosh = VALUES(hisoblangan_maosh)");
    
    $stmt->execute([$employeeId, $month . '-01', $monthlySalary, $salaryType, $percentValue,
        $totalBonus, $totalPenalty, $attended, round($currentCalculated)]);
    
    return [
        'calculated' => round($currentCalculated),
        'attended' => $attended,
        'daily_rate' => round($dailyRate),
        'days_in_month' => $daysInMonth,
        'bonus' => $totalBonus,
        'penalty' => $totalPenalty
    ];
}

function calculateSalaryWithBalance($employeeId, $month, $monthlySalary, $salaryType = 'fixed', $percentValue = 0) {
    global $pdo, $workHoursPerDay, $overtimeRate;
    
    $workHoursPerDay = $workHoursPerDay ?? 8;
    $overtimeRate = $overtimeRate ?? 1.5;
    
    $daysInMonth = getDaysInMonth($month);
    $dailyRate = $daysInMonth > 0 ? $monthlySalary / $daysInMonth : 0;
    
    $stmt = $pdo->prepare("SELECT sana, davomat_holati, ish_soati, qoshimcha_soat FROM davomat WHERE xodim_id = ? AND sana LIKE ?");
    $stmt->execute([$employeeId, $month . '%']);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $attended = 0;
    $overtimeHours = 0;
    
    foreach($attendance as $day) {
        $status = $day['davomat_holati'];
        switch($status) {
            case 'present': $attended += 1; break;
            case 'half_day': $attended += 0.5; break;
            case 'overtime': $attended += 1; $overtimeHours += $day['qoshimcha_soat']; break;
            case 'vacation': case 'sick': $attended += 1; break;
        }
    }
    
    $stmt = $pdo->prepare("SELECT turi, miqdor FROM bonus_jarima WHERE xodim_id = ? AND oy_vaqt = ?");
    $stmt->execute([$employeeId, $month . '-01']);
    $bonusItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalBonus = 0;
    $totalPenalty = 0;
    foreach($bonusItems as $item) {
        if($item['turi'] == 'bonus') $totalBonus += $item['miqdor'];
        else $totalPenalty += $item['miqdor'];
    }
    
    if($salaryType == 'percent') {
        $totalSales = getEmployeeSales($employeeId, $month);
        $currentCalculated = $totalSales * ($percentValue / 100);
    } else {
        $currentCalculated = $dailyRate * $attended;
        if($overtimeHours > 0 && $workHoursPerDay > 0) {
            $hourlyRate = $dailyRate / $workHoursPerDay;
            $currentCalculated += $hourlyRate * $overtimeHours * $overtimeRate;
        }
    }
    
    $currentCalculated += $totalBonus - $totalPenalty;
    
    $previousBalance = getPreviousMonthBalance($employeeId, $month);
    $currentPayment = getCurrentMonthPayment($employeeId, $month);
    $totalToPay = $currentCalculated + $previousBalance;
    $debt = $totalToPay - $currentPayment;
    $currentDebt = $debt > 0 ? $debt : 0;
    
    $stmt = $pdo->prepare("INSERT INTO oylik_sozlamalar 
        (xodim_id, oy_vaqt, oylik_maosh, maosh_turi, foiz_qiymati, bonus, jarima, ishlagan_kun, hisoblangan_maosh, jami_to_lov, qarzdorlik, oldingi_oy_balansi) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        oylik_maosh = VALUES(oylik_maosh), maosh_turi = VALUES(maosh_turi), foiz_qiymati = VALUES(foiz_qiymati),
        bonus = VALUES(bonus), jarima = VALUES(jarima), ishlagan_kun = VALUES(ishlagan_kun), hisoblangan_maosh = VALUES(hisoblangan_maosh),
        jami_to_lov = VALUES(jami_to_lov), qarzdorlik = VALUES(qarzdorlik), oldingi_oy_balansi = VALUES(oldingi_oy_balansi)");
    
    $stmt->execute([$employeeId, $month . '-01', $monthlySalary, $salaryType, $percentValue,
        $totalBonus, $totalPenalty, $attended, round($currentCalculated), round($currentPayment), round($currentDebt), round($previousBalance)]);
    
    return [
        'calculated' => round($currentCalculated),
        'attended' => $attended,
        'daily_rate' => round($dailyRate),
        'days_in_month' => $daysInMonth,
        'bonus' => $totalBonus,
        'penalty' => $totalPenalty,
        'previous_balance' => round($previousBalance),
        'current_payment' => round($currentPayment),
        'total_to_pay' => round($totalToPay),
        'debt' => round($currentDebt)
    ];
}

function calculateMonthlySalary($employeeId, $month) {
    $daysInMonth = (int)date('t', strtotime($month . '-01'));
    $totalSalary = 0;
    for($day = 1; $day <= $daysInMonth; $day++) {
        $date = $month . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
        $totalSalary += getDailyCalculatedSalaryForReport($employeeId, $date);
    }
    return round($totalSalary);
}

function getEmployeeWaiterSales($employeeId, $month = null) {
    global $pdo;
    
    if($month === null) $month = date('Y-m');
    
    $dateFrom = date('Ymd', strtotime($month . '-01'));
    $dateTo = date('Ymd', strtotime($month . '-01 +1 month -1 day'));
    
    $stmt = $pdo->prepare("SELECT poster_user_id, ismi FROM xodimlar WHERE id = ?");
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$emp || !$emp['poster_user_id']) {
        return ['error' => 'Xodim ofitsiant bilan bog\'lanmagan', 'sales' => 0];
    }
    
    $waiters = getWaitersSales($dateFrom, $dateTo);
    
    if(isset($waiters['error'])) {
        return ['error' => $waiters['error'], 'sales' => 0];
    }
    
    $sales = 0;
    $waiterData = null;
    
    foreach($waiters['response'] as $waiter) {
        if($waiter['user_id'] == $emp['poster_user_id']) {
            $sales = $waiter['revenue'] / 100;
            $waiterData = $waiter;
            break;
        }
    }
    
    return [
        'sales' => $sales,
        'sales_formatted' => number_format($sales),
        'waiter_name' => $waiterData['name'] ?? $emp['ismi'],
        'clients' => $waiterData['clients'] ?? 0,
        'middle_invoice' => $waiterData['middle_invoice'] ?? 0
    ];
}