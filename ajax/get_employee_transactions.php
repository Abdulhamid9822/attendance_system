<?php
// ajax/get_employee_transactions.php

require_once __DIR__ . '/../includes/session.php';
$input = json_decode(file_get_contents('php://input'), true);
$sessionContext = $_GET['sid'] ?? $_POST['sid'] ?? ($input['sid'] ?? null);
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
require_once __DIR__ . '/../includes/functions/helpers.php';
require_once __DIR__ . '/../includes/functions/access_token.php';
require_once __DIR__ . '/../includes/functions/employee.php';

$employeeId = $input['employee_id'] ?? 0;
$dateFrom = $input['date_from'] ?? date('Y-m-01');
$dateTo = $input['date_to'] ?? date('Y-m-d');

if(!$employeeId) {
    echo json_encode(['success' => false, 'error' => 'Employee ID required']);
    exit;
}

// Xodimni olish
try {
    assertBranchEmployeeAccess(
        $pdo,
        (int)$employeeId,
        (string)($_SESSION['account'] ?? $_SESSION['account_name'] ?? '')
    );
} catch (RuntimeException $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Xodim topilmadi yoki filial ruxsati yo‘q', 'transactions' => [], 'total_paid' => 0]);
    exit;
}

$employeeSql = "SELECT id, ismi, poster_id, poster_user_id FROM xodimlar WHERE id = ? AND account_name = ?";
$employeeParams = [$employeeId, (string)($_SESSION['account'] ?? $_SESSION['account_name'] ?? '')];
if (isBranchUser()) {
    $employeeSql .= " AND branch_spot_id = ?";
    $employeeParams[] = currentBranchSpotId();
}
$stmt = $pdo->prepare($employeeSql);
$stmt->execute($employeeParams);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$employee || empty($employee['poster_id'])) {
    echo json_encode(['success' => false, 'error' => 'Poster salary category ID not found', 'transactions' => [], 'total_paid' => 0]);
    exit;
}
// Tranzaksiyalarni olish
$token = getAccessToken();
if(!$token) {
    echo json_encode(['success' => false, 'error' => 'Poster token not found', 'transactions' => [], 'total_paid' => 0]);
    exit;
}

$apiDateFrom = str_replace('-', '', $dateFrom);
$apiDateTo = str_replace('-', '', $dateTo);
$accountDomain = trim((string)($_SESSION['account_domain'] ?? ''));
if ($accountDomain === '') {
    $accountDomain = trim((string)($_SESSION['account'] ?? $_SESSION['account_name'] ?? ''));
}
$accountDomain = preg_replace('/[^a-zA-Z0-9.-]/', '', $accountDomain);
if ($accountDomain === '') {
    echo json_encode(['success' => false, 'error' => 'Poster account domain not found', 'transactions' => [], 'total_paid' => 0]);
    exit;
}

$url = "https://{$accountDomain}.joinposter.com/api/finance.getTransactions?"
    . http_build_query([
        'token' => $token,
        'dateFrom' => $apiDateFrom,
        'dateTo' => $apiDateTo,
        'category_id' => $employee['poster_id'],
    ]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

$transactions = [];
$totalPaid = 0;

if(isset($data['response']) && is_array($data['response'])) {
    foreach($data['response'] as $t) {
        if (!is_array($t) || (string)($t['delete'] ?? '0') === '1') {
            continue;
        }
        $amount = $t['amount'] ?? null;
        if (!is_numeric($amount)) {
            continue;
        }
        $amount = (float)$amount;
        $amountInSom = abs($amount) / 100;
        $totalPaid += $amountInSom;
        
        $transactions[] = [
            'date' => $t['transaction_date'] ?? $t['date'] ?? '',
            'amount' => $amountInSom,
            'is_payment' => true,
            'comment' => $t['comment'] ?? ''
        ];
    }
}

echo json_encode([
    'success' => true,
    'transactions' => $transactions,
    'total_paid' => $totalPaid,
    'employee_name' => $employee['ismi']
]);