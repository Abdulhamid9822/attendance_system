<?php
// includes/functions/helpers.php

// ============================================
// SOZLAMALAR FUNKSIYALARI
// ============================================


function getSettings() {
    global $pdo;
    
    if (!isset($pdo)) {
        if (file_exists(__DIR__ . '/../config/database.php')) {
            require_once __DIR__ . '/../config/database.php';
        }
        if (!isset($pdo)) {
            return [];
        }
    }
    
    // ============================================
    // TO'G'RI: account_id va account_name bo'yicha
    // ============================================
    $account_id = $_SESSION['account_id'] ?? $_SESSION['account_number'] ?? '';
    $account_name = $_SESSION['account_name'] ?? $_SESSION['account'] ?? '';
    
    $settings = [];
    try {
        if(!empty($account_id)) {
            $stmt = $pdo->prepare("SELECT kalit, qiymat FROM sozlamalar WHERE account_id = ?");
            $stmt->execute([$account_id]);
        } elseif(!empty($account_name)) {
            $stmt = $pdo->prepare("SELECT kalit, qiymat FROM sozlamalar WHERE account_name = ?");
            $stmt->execute([$account_name]);
        } else {
            $stmt = $pdo->prepare("SELECT kalit, qiymat FROM sozlamalar WHERE account_id IS NULL");
            $stmt->execute();
        }
        
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['kalit']] = $row['qiymat'];
        }
    } catch(Exception $e) {
        error_log("getSettings error: " . $e->getMessage());
        // Xatolikni ko'rsatmaslik uchun bo'sh qaytaramiz
        return [];
    }
    return $settings;
}

function getSetting($key, $default = null) {
    $settings = getSettings();
    return $settings[$key] ?? $default;
}

function updateSetting($key, $value) {
    global $pdo;
    
    if (!isset($pdo)) {
        if (file_exists(__DIR__ . '/../config/database.php')) {
            require_once __DIR__ . '/../config/database.php';
        }
        if (!isset($pdo)) {
            return false;
        }
    }
    
    $account_id = $_SESSION['account_id'] ?? $_SESSION['account_number'] ?? '';
    $account_name = $_SESSION['account_name'] ?? $_SESSION['account'] ?? '';
    
    try {
        if(!empty($account_id)) {
            $stmt = $pdo->prepare("
                INSERT INTO sozlamalar (kalit, qiymat, account_id, account_name) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE qiymat = VALUES(qiymat), account_name = VALUES(account_name)
            ");
            return $stmt->execute([$key, $value, $account_id, $account_name]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO sozlamalar (kalit, qiymat, account_name) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE qiymat = VALUES(qiymat)
            ");
            return $stmt->execute([$key, $value, $account_name]);
        }
    } catch(Exception $e) {
        error_log("updateSetting error: " . $e->getMessage());
        return false;
    }
}

// Qolgan funksiyalar...



// ============================================
// FORMATLASH FUNKSIYALARI
// ============================================

function formatMoney($amount, $symbol = null) {
    if($symbol === null) {
        $settings = getSettings();
        $symbol = $settings['currency_symbol'] ?? 'so\'m';
    }
    return number_format($amount, 0, ',', ' ') . ' ' . $symbol;
}

function formatDate($date, $format = 'd.m.Y') {
    if(empty($date)) return '-';
    return date($format, strtotime($date));
}

function formatDateTime($datetime, $format = 'd.m.Y H:i') {
    if(empty($datetime)) return '-';
    return date($format, strtotime($datetime));
}

// ============================================
// VAQT FUNKSIYALARI
// ============================================

function getDaysInMonth($year, $month) {
    return cal_days_in_month(CAL_GREGORIAN, $month, $year);
}

function getWorkingDays($startDate, $endDate) {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $end->modify('+1 day');
    
    $workingDays = 0;
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    
    foreach($period as $date) {
        $dayOfWeek = $date->format('N');
        if($dayOfWeek < 6) {
            $workingDays++;
        }
    }
    
    return $workingDays;
}

function isWeekend($date) {
    $dayOfWeek = date('N', strtotime($date));
    return $dayOfWeek >= 6;
}

// ============================================
// VALIDATSIYA FUNKSIYALARI
// ============================================

function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validatePhone($phone) {
    return preg_match('/^(\+998)?[0-9]{9}$/', $phone);
}

// ============================================
// ARRAY YORDAMCHI FUNKSIYALARI
// ============================================

function array_group_by($array, $key) {
    $result = [];
    foreach($array as $item) {
        $groupKey = is_object($item) ? $item->$key : $item[$key];
        $result[$groupKey][] = $item;
    }
    return $result;
}

function array_sum_by_key($array, $key) {
    $sum = 0;
    foreach($array as $item) {
        $sum += isset($item[$key]) ? (float)$item[$key] : 0;
    }
    return $sum;
}

// ============================================
// DEBUG FUNKSIYALARI
// ============================================

function debug($data, $die = false) {
    echo '<pre style="background: #f8fafc; padding: 15px; border-radius: 8px; margin: 10px; font-size: 12px; overflow: auto;">';
    print_r($data);
    echo '</pre>';
    if($die) die();
}

function debugLog($message, $data = null) {
    $logFile = __DIR__ . '/../../logs/debug_' . date('Y-m-d') . '.log';
    if(!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    
    $logEntry = date('Y-m-d H:i:s') . ' - ' . $message;
    if($data !== null) {
        $logEntry .= ' - ' . json_encode($data);
    }
    file_put_contents($logFile, $logEntry . PHP_EOL, FILE_APPEND);
}

// ============================================
// SESSION FUNKSIYALARI
// ============================================

function setFlash($key, $message) {
    $_SESSION['flash'][$key] = $message;
}

function getFlash($key) {
    if(isset($_SESSION['flash'][$key])) {
        $message = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $message;
    }
    return null;
}

function hasFlash($key) {
    return isset($_SESSION['flash'][$key]);
}

// ============================================
// URL FUNKSIYALARI
// ============================================

function currentUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $uri = $_SERVER['REQUEST_URI'];
    return $protocol . '://' . $host . $uri;
}

function redirect($url, $statusCode = 302) {
    header('Location: ' . $url, true, $statusCode);
    exit;
}

function back() {
    $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
    redirect($referer);
}
?>