<?php
// includes/functions/access_token.php

function getAccessToken($userId = null) {
    // SESSION dan tokenni olish (Poster OAuth)
    if(isset($_SESSION['access_token'])) {
        return $_SESSION['access_token'];
    }
    
    return null;
}

function getPosterDomain() {
    if (!empty($_SESSION['account_domain'])) {
        return $_SESSION['account_domain'];
    }

    if(isset($_SESSION['account'])) {
        return $_SESSION['account'];
    }
    
    return null;
}

function getAccountNumber() {
    return $_SESSION['account_number'] ?? null;
}

function isPosterConnected() {
    return getAccessToken() !== null && getPosterDomain() !== null;
}

function posterApiCall($method, $params = []) {
    $token = getAccessToken();
    $domain = getPosterDomain();
    
    if(!$token || !$domain) {
        return ['error' => 'Token yoki domain topilmadi. Iltimos, qaytadan login qiling.'];
    }
    
    $url = "https://{$domain}.joinposter.com/api/{$method}?token=" . $token;
    foreach($params as $k => $v) {
        $url .= "&" . urlencode($k) . "=" . urlencode($v);
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if($httpCode != 200) {
        return ['error' => "HTTP $httpCode", 'response' => $response];
    }
    
    return json_decode($response, true);
}

// Xodim to'lovlarini olish
function getEmployeePayments($posterId, $dateFrom, $dateTo) {
    $apiDateFrom = str_replace('-', '', $dateFrom);
    $apiDateTo = str_replace('-', '', $dateTo);
    
    $result = posterApiCall('finance.getTransactions', [
        'dateFrom' => $apiDateFrom,
        'dateTo' => $apiDateTo,
        'category_id' => $posterId
    ]);
    
    if(isset($result['error'])) {
        return ['total_paid' => 0, 'transactions' => [], 'error' => $result['error']];
    }
    
    $totalPaid = 0;
    $transactions = [];
    
    if(isset($result['response']) && is_array($result['response'])) {
        foreach($result['response'] as $t) {
            if (!is_array($t) || (string)($t['delete'] ?? '0') === '1') {
                continue;
            }
            if (!isset($t['amount']) || !is_numeric($t['amount'])) {
                continue;
            }
            $amount = abs((float)$t['amount']) / 100;
            $totalPaid += $amount;
            $transactions[] = [
                'date' => $t['transaction_date'] ?? $t['date'] ?? '',
                'amount' => $amount,
                'comment' => $t['comment'] ?? ''
            ];
        }
    }
    
    return ['total_paid' => $totalPaid, 'transactions' => $transactions];
}
?>