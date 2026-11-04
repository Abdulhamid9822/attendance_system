<?php
// Mobil ilova uchun REST API (token orqali)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../includes/config/database.php';
require_once __DIR__ . '/../includes/functions/mobile_access.php';
require_once __DIR__ . '/../includes/functions/excuses.php';
require_once __DIR__ . '/../includes/functions/push.php';

function out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function inputData()
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $_POST;
}

function monthParam()
{
    $m = $_GET['month'] ?? date('Y-m');
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : date('Y-m');
}

function dateParam($key, $default)
{
    $v = $_GET[$key] ?? '';
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : $default;
}

// from/to oralig'i (maks. 93 kun); berilmasa joriy/tanlangan oy
function rangeParams()
{
    $month = monthParam();
    $from = dateParam('from', $month . '-01');
    $to = dateParam('to', date('Y-m-t', strtotime($month . '-01')));
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > 92) {
        $from = date('Y-m-d', strtotime($to . ' -92 days'));
    }
    return [$from, $to];
}

function authUser(PDO $pdo)
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $header = $v;
            }
        }
    }
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
        out(['success' => false, 'error' => 'Avtorizatsiya talab qilinadi'], 401);
    }
    $stmt = $pdo->prepare("
        SELECT u.* FROM mobile_tokens t
        INNER JOIN mobile_users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.active = 1
        LIMIT 1
    ");
    $stmt->execute([hash('sha256', strtolower($m[1]))]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        out(['success' => false, 'error' => 'Sessiya tugagan. Qayta kiring.'], 401);
    }
    return $user;
}

function scopedEmployeeSql(array $user, $alias = 'e')
{
    $sql = " {$alias}.account_name = ? AND {$alias}.holati = 'active'";
    $params = [$user['account_name']];
    if ($user['role'] === 'employee') {
        $sql .= " AND {$alias}.id = ?";
        $params[] = (int)$user['xodim_id'];
    } elseif ($user['role'] === 'branch') {
        $sql .= " AND ({$alias}.branch_spot_id = ? OR {$alias}.poster_spot_id = ?)";
        $params[] = (int)$user['spot_id'];
        $params[] = (int)$user['spot_id'];
    }
    return [$sql, $params];
}

function loadScopedEmployee(PDO $pdo, array $user, $employeeId)
{
    [$where, $params] = scopedEmployeeSql($user);
    $stmt = $pdo->prepare("SELECT e.id, e.ismi, e.lavozimi, e.telefoni, e.branch_spot_id, e.poster_spot_id FROM xodimlar e WHERE {$where} AND e.id = ? LIMIT 1");
    $stmt->execute(array_merge($params, [(int)$employeeId]));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        out(['success' => false, 'error' => 'Xodim topilmadi yoki ruxsat yo\'q'], 403);
    }
    return $row;
}

// Poster'dan oy bo'yicha to'langan pullar: [category_id => [tranzaksiyalar]]; xatolikda null
function fetchPosterPayments(PDO $pdo, $accountName, $month, $categoryId = null)
{
    $stmt = $pdo->prepare("SELECT access_token, account_domain FROM poster_tokens WHERE account_name = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$accountName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['access_token'])) {
        return null;
    }
    $domain = preg_replace('/[^a-zA-Z0-9.-]/', '', trim((string)($row['account_domain'] ?? '')) ?: $accountName);
    $params = [
        'token' => $row['access_token'],
        'dateFrom' => date('Ymd', strtotime($month . '-01')),
        'dateTo' => date('Ymd', strtotime($month . '-01 +1 month -1 day')),
    ];
    if ($categoryId) {
        $params['category_id'] = (int)$categoryId;
    }
    $ch = curl_init("https://{$domain}.joinposter.com/api/finance.getTransactions?" . http_build_query($params));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => true]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = $body === false ? null : json_decode($body, true);
    if (!is_array($data) || !is_array($data['response'] ?? null)) {
        error_log('Mobile API: Poster tranzaksiyalari olinmadi');
        return null;
    }
    $byCategory = [];
    $seen = [];
    foreach ($data['response'] as $t) {
        if (!is_array($t) || (string)($t['delete'] ?? '0') === '1') {
            continue;
        }
        $amount = $t['amount'] ?? $t['amount_to'] ?? null;
        if (!is_numeric($amount) || !isset($t['category_id'])) {
            continue;
        }
        $fp = md5(json_encode([$t['transaction_date'] ?? $t['date'] ?? '', (string)$amount, (string)$t['category_id'],
            $t['comment'] ?? '', $t['payment_method'] ?? '', $t['name'] ?? '']));
        if (isset($seen[$fp])) {
            continue;
        }
        $seen[$fp] = true;
        $byCategory[(int)$t['category_id']][] = [
            'date' => (string)($t['transaction_date'] ?? $t['date'] ?? ''),
            'amount' => abs((float)$amount) / 100,
            'comment' => (string)($t['comment'] ?? $t['description'] ?? $t['note'] ?? ''),
            'method' => (string)($t['payment_method'] ?? ''),
        ];
    }
    return $byCategory;
}

function previousBalanceFor(PDO $pdo, $employeeId, $month)
{
    $stmt = $pdo->prepare("
        SELECT s.keyingi_oyga_balansi FROM oylik_sozlamalar s
        INNER JOIN xodimlar x ON x.id = s.xodim_id
        WHERE s.xodim_id = ? AND s.balans_saqlangan = 1 AND s.oy_vaqt < ?
          AND (x.yaratilgan IS NULL OR s.oy_vaqt >= DATE_FORMAT(x.yaratilgan, '%Y-%m-01'))
        ORDER BY s.oy_vaqt DESC, s.amal_qiladi DESC, s.id DESC LIMIT 1
    ");
    $stmt->execute([(int)$employeeId, $month . '-01']);
    $v = $stmt->fetchColumn();
    return $v === false ? 0.0 : (float)$v;
}

function salaryFor(PDO $pdo, $employeeId, $month, $paidTotal = null)
{
    $stmt = $pdo->prepare("
        SELECT oylik_maosh, soatlik_stavka, foiz_qiymati, smena_summa, maosh_turi, ishlagan_kun,
               keyingi_oyga_balansi, balans_saqlangan
        FROM oylik_sozlamalar
        WHERE xodim_id = ? AND oy_vaqt <= ?
        ORDER BY oy_vaqt DESC, amal_qiladi DESC, id DESC LIMIT 1
    ");
    $stmt->execute([(int)$employeeId, $month . '-01']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $monthStmt = $pdo->prepare("
        SELECT keyingi_oyga_balansi, balans_saqlangan
        FROM oylik_sozlamalar WHERE xodim_id = ? AND oy_vaqt = ?
        ORDER BY amal_qiladi DESC, id DESC LIMIT 1
    ");
    $monthStmt->execute([(int)$employeeId, $month . '-01']);
    $cur = $monthStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $types = json_decode((string)($row['maosh_turi'] ?? ''), true);
    if (!is_array($types)) {
        $types = $row['maosh_turi'] ? [(string)$row['maosh_turi']] : [];
    }
    $prev = previousBalanceFor($pdo, $employeeId, $month);

    // Hisoblangan summa hisobot ochilganda saqlangan balansdan tiklanadi: balans = hisoblangan + oldingi - to'langan
    $earned = null;
    $balance = null;
    if (!empty($cur['balans_saqlangan']) && $paidTotal !== null) {
        $earned = (float)$cur['keyingi_oyga_balansi'] - $prev + $paidTotal;
        $balance = $earned + $prev - $paidTotal;
    } elseif (!empty($cur['balans_saqlangan'])) {
        $balance = (float)$cur['keyingi_oyga_balansi'];
    }

    return [
        'types' => $types,
        'fixed' => (float)($row['oylik_maosh'] ?? 0),
        'hourly' => (float)($row['soatlik_stavka'] ?? 0),
        'percent' => (float)($row['foiz_qiymati'] ?? 0),
        'shift' => (float)($row['smena_summa'] ?? 0),
        'earned' => $earned,
        'previous_balance' => $prev,
        'paid' => $paidTotal,
        'balance' => $balance,
    ];
}

try {
    ensureMobileSchema($pdo);
    ensureExcuseSchema($pdo);
    ensurePushSchema($pdo);
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    if ($action === 'login' && $method === 'POST') {
        $in = inputData();
        $phone = normalizeMobilePhone($in['phone'] ?? '');
        $pin = (string)($in['pin'] ?? '');
        if ($phone === '' || !preg_match('/^\d{4,6}$/', $pin)) {
            out(['success' => false, 'error' => 'Telefon yoki PIN noto\'g\'ri'], 422);
        }
        $stmt = $pdo->prepare("SELECT * FROM mobile_users WHERE phone = ? AND active = 1 LIMIT 1");
        $stmt->execute([$phone]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
            out(['success' => false, 'error' => 'Juda ko\'p urinish. 15 daqiqadan keyin qayta urining.'], 429);
        }
        if (!$user || !password_verify($pin, $user['pin_hash'])) {
            if ($user) {
                $fails = (int)$user['failed_attempts'] + 1;
                $lock = $fails >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
                $pdo->prepare("UPDATE mobile_users SET failed_attempts = ?, locked_until = ? WHERE id = ?")
                    ->execute([$fails >= 5 ? 0 : $fails, $lock, $user['id']]);
            }
            out(['success' => false, 'error' => 'Telefon yoki PIN noto\'g\'ri'], 401);
        }
        $pdo->prepare("UPDATE mobile_users SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$user['id']]);
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("DELETE FROM mobile_tokens WHERE expires_at < NOW()")->execute();
        $pdo->prepare("INSERT INTO mobile_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))")
            ->execute([$user['id'], hash('sha256', $token)]);
        out(['success' => true, 'token' => $token, 'user' => [
            'name' => $user['full_name'], 'role' => $user['role'],
            'account' => $user['account_name'], 'employee_id' => $user['xodim_id'] ? (int)$user['xodim_id'] : null,
        ]]);
    }

    $user = authUser($pdo);

    if ($action === 'register_device' && $method === 'POST') {
        $in = inputData();
        $fcm = trim((string)($in['fcm_token'] ?? ''));
        if ($fcm === '' || strlen($fcm) > 4096) {
            out(['success' => false, 'error' => 'Token noto\'g\'ri'], 422);
        }
        $pdo->prepare("INSERT INTO mobile_devices (user_id, token_hash, token, platform) VALUES (?, ?, ?, 'android')
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), token = VALUES(token)")
            ->execute([(int)$user['id'], hash('sha256', $fcm), $fcm]);
        out(['success' => true]);
    }

    if ($action === 'unregister_device' && $method === 'POST') {
        $in = inputData();
        $fcm = trim((string)($in['fcm_token'] ?? ''));
        if ($fcm !== '') {
            $pdo->prepare("DELETE FROM mobile_devices WHERE token_hash = ? AND user_id = ?")
                ->execute([hash('sha256', $fcm), (int)$user['id']]);
        }
        out(['success' => true]);
    }

    if ($action === 'push_test') {
        $diag = sendPushToUsers($pdo, [(int)$user['id']], 'Sinov', 'Push ishlayapti', ['type' => 'test']);
        out(['success' => true, 'diag' => $diag]);
    }

    if ($action === 'logout' && $method === 'POST') {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
            $pdo->prepare("DELETE FROM mobile_tokens WHERE token_hash = ?")->execute([hash('sha256', strtolower($m[1]))]);
        }
        out(['success' => true]);
    }

    if ($action === 'me') {
        out(['success' => true, 'user' => [
            'name' => $user['full_name'], 'role' => $user['role'], 'account' => $user['account_name'],
            'employee_id' => $user['xodim_id'] ? (int)$user['xodim_id'] : null,
            'spot_id' => $user['spot_id'] ? (int)$user['spot_id'] : null,
        ]]);
    }

    if ($action === 'change_pin' && $method === 'POST') {
        $in = inputData();
        $old = (string)($in['old_pin'] ?? '');
        $new = (string)($in['new_pin'] ?? '');
        if (!password_verify($old, $user['pin_hash'])) {
            out(['success' => false, 'error' => 'Eski PIN noto\'g\'ri'], 422);
        }
        if (!preg_match('/^\d{4,6}$/', $new)) {
            out(['success' => false, 'error' => 'PIN 4-6 raqamdan iborat bo\'lishi kerak'], 422);
        }
        $pdo->prepare("UPDATE mobile_users SET pin_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        out(['success' => true]);
    }

    if ($action === 'salary') {
        $month = monthParam();
        $employeeId = $user['role'] === 'employee' ? (int)$user['xodim_id'] : (int)($_GET['employee_id'] ?? $user['xodim_id']);
        if ($employeeId <= 0) {
            out(['success' => false, 'error' => 'Xodim tanlanmagan'], 422);
        }
        $emp = loadScopedEmployee($pdo, $user, $employeeId);
        $catStmt = $pdo->prepare("SELECT poster_id FROM xodimlar WHERE id = ?");
        $catStmt->execute([$emp['id']]);
        $categoryId = (int)$catStmt->fetchColumn();
        $payments = [];
        $paidTotal = null;
        if ($categoryId > 0) {
            $byCat = fetchPosterPayments($pdo, $user['account_name'], $month, $categoryId);
            if ($byCat !== null) {
                $payments = $byCat[$categoryId] ?? [];
                usort($payments, function ($a, $b) {
                    return strcmp($b['date'], $a['date']);
                });
                $paidTotal = array_sum(array_column($payments, 'amount'));
            }
        }
        out(['success' => true, 'month' => $month, 'employee' => ['id' => (int)$emp['id'], 'name' => $emp['ismi'], 'position' => $emp['lavozimi']],
            'salary' => salaryFor($pdo, $emp['id'], $month, $paidTotal), 'payments' => $payments,
            'payments_available' => $paidTotal !== null]);
    }

    if ($action === 'attendance') {
        $month = monthParam();
        $employeeId = $user['role'] === 'employee' ? (int)$user['xodim_id'] : (int)($_GET['employee_id'] ?? $user['xodim_id']);
        $emp = loadScopedEmployee($pdo, $user, $employeeId);
        [$from, $to] = rangeParams();
        $stmt = $pdo->prepare("
            SELECT auth_date AS sana, 'present' AS davomat_holati, in_time AS check_in, out_time AS check_out,
                COALESCE(in_late_minutes, 0) AS kechikish_daqiqa, total_work_hours
            FROM attendance_data
            WHERE employee_id = ? AND account_name = ? AND auth_date BETWEEN ? AND ?
              AND (in_time IS NOT NULL OR out_time IS NOT NULL)
            ORDER BY auth_date DESC
        ");
        $stmt->execute([(string)$emp['id'], $user['account_name'], $from, $to]);
        out(['success' => true, 'month' => $month, 'from' => $from, 'to' => $to,
            'employee' => ['id' => (int)$emp['id'], 'name' => $emp['ismi']],
            'days' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($user['role'] === 'employee' && in_array($action, ['employees', 'branches'], true)) {
        out(['success' => false, 'error' => 'Ruxsat yo\'q'], 403);
    }

    if ($action === 'dashboard') {
        [$from, $to] = rangeParams();
        [$where, $params] = scopedEmployeeSql($user);
        if ($user['role'] === 'super' && (int)($_GET['spot_id'] ?? 0) > 0) {
            $where .= " AND (e.branch_spot_id = ? OR e.poster_spot_id = ?)";
            $params[] = (int)$_GET['spot_id'];
            $params[] = (int)$_GET['spot_id'];
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM xodimlar e WHERE {$where}");
        $cnt->execute($params);
        $totalEmployees = (int)$cnt->fetchColumn();
        $stmt = $pdo->prepare("
            SELECT a.auth_date AS sana,
                COUNT(DISTINCT CASE WHEN a.in_time IS NOT NULL OR a.out_time IS NOT NULL THEN e.id END) AS came,
                COUNT(DISTINCT CASE WHEN a.in_late_minutes > 0 THEN e.id END) AS late
            FROM attendance_data a
            INNER JOIN xodimlar e ON e.id = a.employee_id
            WHERE {$where} AND a.account_name = ? AND a.auth_date BETWEEN ? AND ?
            GROUP BY a.auth_date ORDER BY a.auth_date
        ");
        $stmt->execute(array_merge($params, [$user['account_name'], $from, $to]));
        $days = [];
        $sum = ['came' => 0, 'late' => 0, 'absent' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $came = (int)$r['came'];
            $absent = max(0, $totalEmployees - $came);
            $days[] = ['date' => $r['sana'], 'came' => $came, 'late' => (int)$r['late'], 'absent' => $absent];
            $sum['came'] += $came;
            $sum['late'] += (int)$r['late'];
            $sum['absent'] += $absent;
        }
        out(['success' => true, 'from' => $from, 'to' => $to, 'total_employees' => $totalEmployees,
            'summary' => $sum, 'days' => $days]);
    }

    if ($action === 'daily') {
        $date = dateParam('date', date('Y-m-d'));
        [$where, $params] = scopedEmployeeSql($user);
        if ($user['role'] === 'super' && (int)($_GET['spot_id'] ?? 0) > 0) {
            $where .= " AND (e.branch_spot_id = ? OR e.poster_spot_id = ?)";
            $params[] = (int)$_GET['spot_id'];
            $params[] = (int)$_GET['spot_id'];
        }
        $stmt = $pdo->prepare("
            SELECT e.id, e.ismi, e.lavozimi, a.in_time, a.out_time, a.in_late_minutes
            FROM xodimlar e
            LEFT JOIN attendance_data a ON a.employee_id = e.id AND a.account_name = ? AND a.auth_date = ?
            WHERE {$where}
            ORDER BY e.ismi LIMIT 500
        ");
        $stmt->execute(array_merge([$user['account_name'], $date], $params));
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $anyone = false;
        foreach ($list as $r) {
            if ($r['in_time'] !== null || $r['out_time'] !== null) {
                $anyone = true;
                break;
            }
        }
        $rows = [];
        foreach ($list as $r) {
            $came = $r['in_time'] !== null || $r['out_time'] !== null;
            // Kimdir kelgan kunda kelmaganlar "kelmadi", hech kim yo'q kunda esa "belgilanmagan"
            $status = $came ? 'present' : ($anyone && $date <= date('Y-m-d') ? 'absent' : null);
            $rows[] = ['id' => (int)$r['id'], 'name' => $r['ismi'], 'position' => $r['lavozimi'],
                'status' => $status, 'check_in' => $r['in_time'], 'check_out' => $r['out_time'],
                'late' => (int)$r['in_late_minutes']];
        }
        out(['success' => true, 'date' => $date, 'rows' => $rows]);
    }

    if ($action === 'employees') {
        $month = monthParam();
        $q = trim((string)($_GET['q'] ?? ''));
        [$where, $params] = scopedEmployeeSql($user);
        if ($user['role'] === 'super' && (int)($_GET['spot_id'] ?? 0) > 0) {
            $where .= " AND (e.branch_spot_id = ? OR e.poster_spot_id = ?)";
            $params[] = (int)$_GET['spot_id'];
            $params[] = (int)$_GET['spot_id'];
        }
        if ($q !== '') {
            $where .= " AND e.ismi LIKE ?";
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $stmt = $pdo->prepare("
            SELECT e.id, e.ismi, e.lavozimi, e.branch_spot_id, e.poster_id
            FROM xodimlar e
            WHERE {$where}
            ORDER BY e.ismi LIMIT 500
        ");
        $stmt->execute($params);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byCat = fetchPosterPayments($pdo, $user['account_name'], $month);
        $rows = [];
        $totalPaid = 0.0;
        $totalEarned = 0.0;
        foreach ($list as $r) {
            $cat = (int)$r['poster_id'];
            $paid = $byCat === null ? null : (float)array_sum(array_column($byCat[$cat] ?? [], 'amount'));
            $s = salaryFor($pdo, $r['id'], $month, $paid);
            $rows[] = ['id' => (int)$r['id'], 'name' => $r['ismi'], 'position' => $r['lavozimi'],
                'spot_id' => $r['branch_spot_id'] ? (int)$r['branch_spot_id'] : null,
                'types' => $s['types'], 'fixed' => $s['fixed'], 'hourly' => $s['hourly'],
                'percent' => $s['percent'], 'shift' => $s['shift'],
                'earned' => $s['earned'], 'paid' => $paid, 'balance' => $s['balance']];
            $totalPaid += (float)$paid;
            $totalEarned += (float)$s['earned'];
        }
        out(['success' => true, 'month' => $month, 'total_earned' => $totalEarned, 'total_paid' => $totalPaid,
            'payments_available' => $byCat !== null, 'employees' => $rows]);
    }

    if ($action === 'branches') {
        [$where, $params] = scopedEmployeeSql($user);
        $stmt = $pdo->prepare("
            SELECT COALESCE(e.branch_spot_id, 0) AS spot, COUNT(*) AS cnt
            FROM xodimlar e WHERE {$where} GROUP BY COALESCE(e.branch_spot_id, 0)
        ");
        $stmt->execute($params);
        $list = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $list[] = ['spot_id' => (int)$r['spot'], 'employees' => (int)$r['cnt']];
        }
        out(['success' => true, 'branches' => $list]);
    }

    if ($action === 'excuses') {
        $status = (string)($_GET['status'] ?? '');
        $sql = "SELECT r.id, r.xodim_id, e.ismi, e.lavozimi, e.branch_spot_id, r.request_date, r.type, r.expected_time, r.reason,
                       r.status, r.created_at, r.decided_by, r.decided_at,
                       a.in_time, a.out_time, a.in_late_minutes, a.out_early_minutes
                FROM excuse_requests r INNER JOIN xodimlar e ON e.id = r.xodim_id
                LEFT JOIN attendance_data a ON a.employee_id = e.id AND a.account_name = ? AND a.auth_date = r.request_date
                WHERE r.account_name = ?";
        $params = [$user['account_name'], $user['account_name']];
        if ($user['role'] === 'employee') {
            $sql .= " AND r.xodim_id = ?";
            $params[] = (int)$user['xodim_id'];
        } elseif ($user['role'] === 'branch') {
            $sql .= " AND (e.branch_spot_id = ? OR e.poster_spot_id = ?)";
            $params[] = (int)$user['spot_id'];
            $params[] = (int)$user['spot_id'];
        }
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }
        $stmt = $pdo->prepare($sql . " ORDER BY r.status = 'pending' DESC, r.request_date DESC, r.id DESC LIMIT 200");
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['id' => (int)$r['id'], 'employee_id' => (int)$r['xodim_id'], 'name' => $r['ismi'],
                'position' => $r['lavozimi'], 'spot_id' => $r['branch_spot_id'] ? (int)$r['branch_spot_id'] : null,
                'date' => $r['request_date'], 'type' => $r['type'], 'time' => excuseClock($r['expected_time']), 'reason' => $r['reason'],
                'status' => $r['status'], 'created_at' => $r['created_at'],
                'decided_by' => $r['decided_by'], 'decided_at' => $r['decided_at'],
                'actual_in' => $r['in_time'], 'actual_out' => $r['out_time'],
                'late_minutes' => $r['in_late_minutes'] !== null ? (int)$r['in_late_minutes'] : null,
                'early_minutes' => $r['out_early_minutes'] !== null ? (int)$r['out_early_minutes'] : null];
        }
        out(['success' => true, 'requests' => $rows]);
    }

    if ($action === 'excuse_create' && $method === 'POST') {
        if ($user['role'] !== 'employee' || (int)$user['xodim_id'] <= 0) {
            out(['success' => false, 'error' => 'Ariza faqat xodim uchun'], 403);
        }
        $in = inputData();
        $type = (string)($in['type'] ?? '');
        $date = (string)($in['date'] ?? '');
        $reason = trim((string)($in['reason'] ?? ''));
        $time = excuseClock($in['time'] ?? '');
        if ($time === null) {
            out(['success' => false, 'error' => $type === 'early' ? 'Ketish vaqtini kiriting' : 'Kelish vaqtini kiriting'], 422);
        }
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if (!in_array($type, ['late', 'early'], true) || !$d || $d->format('Y-m-d') !== $date) {
            out(['success' => false, 'error' => 'Ariza ma\'lumotlari noto\'g\'ri'], 422);
        }
        if ($date < date('Y-m-d', strtotime('-31 days')) || $date > date('Y-m-d', strtotime('+7 days'))) {
            out(['success' => false, 'error' => 'Sana oralig\'i noto\'g\'ri'], 422);
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            out(['success' => false, 'error' => 'Sababni yozing (500 belgigacha)'], 422);
        }
        $ex = $pdo->prepare("SELECT id, status FROM excuse_requests WHERE xodim_id = ? AND request_date = ? AND type = ?");
        $ex->execute([(int)$user['xodim_id'], $date, $type]);
        $old = $ex->fetch(PDO::FETCH_ASSOC);
        if ($old && $old['status'] !== 'rejected') {
            out(['success' => false, 'error' => 'Bu sana uchun ariza allaqachon yuborilgan'], 409);
        }
        if ($old) {
            $pdo->prepare("UPDATE excuse_requests SET reason = ?, expected_time = ?, status = 'pending', decided_by = NULL, decided_at = NULL WHERE id = ?")
                ->execute([$reason, $time, (int)$old['id']]);
        } else {
            $pdo->prepare("INSERT INTO excuse_requests (account_name, xodim_id, request_date, type, expected_time, reason) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$user['account_name'], (int)$user['xodim_id'], $date, $type, $time, $reason]);
        }
        $who = $user['full_name'] ?: 'Xodim';
        notifyExcuseAdmins($pdo, $user['account_name'], (int)$user['xodim_id'],
            'Yangi ariza: ' . excuseTypeLabel($type),
            $who . ' — ' . $date . ', ' . substr($time, 0, 5) . '. ' . mb_substr($reason, 0, 80),
            ['type' => 'excuse_new', 'tab' => 'excuses']);
        out(['success' => true]);
    }

    if ($action === 'excuse_decide' && $method === 'POST') {
        if ($user['role'] === 'employee') {
            out(['success' => false, 'error' => 'Ruxsat yo\'q'], 403);
        }
        $in = inputData();
        $decision = (string)($in['decision'] ?? '');
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            out(['success' => false, 'error' => 'Qaror noto\'g\'ri'], 422);
        }
        $find = $pdo->prepare("SELECT r.id, r.xodim_id, r.request_date, r.type, e.branch_spot_id, e.poster_spot_id FROM excuse_requests r
            INNER JOIN xodimlar e ON e.id = r.xodim_id WHERE r.id = ? AND r.account_name = ?");
        $find->execute([(int)($in['id'] ?? 0), $user['account_name']]);
        $req = $find->fetch(PDO::FETCH_ASSOC);
        if (!$req || ($user['role'] === 'branch'
            && (int)$req['branch_spot_id'] !== (int)$user['spot_id'] && (int)$req['poster_spot_id'] !== (int)$user['spot_id'])) {
            out(['success' => false, 'error' => 'Ariza topilmadi'], 404);
        }
        $pdo->prepare("UPDATE excuse_requests SET status = ?, decided_by = ?, decided_at = NOW() WHERE id = ?")
            ->execute([$decision, 'mobile:' . ($user['full_name'] ?: $user['phone']), (int)$req['id']]);
        $target = $pdo->prepare("SELECT id FROM mobile_users WHERE account_name = ? AND xodim_id = ? AND active = 1");
        $target->execute([$user['account_name'], (int)$req['xodim_id']]);
        sendPushToUsers($pdo, array_column($target->fetchAll(PDO::FETCH_ASSOC), 'id'),
            $decision === 'approved' ? 'Arizangiz tasdiqlandi' : 'Arizangiz rad etildi',
            excuseTypeLabel($req['type']) . ' — ' . $req['request_date'],
            ['type' => 'excuse_decided', 'tab' => 'excuses']);
        out(['success' => true]);
    }

    out(['success' => false, 'error' => 'Noma\'lum amal'], 404);
} catch (Throwable $e) {
    error_log('Mobile API error: ' . $e->getMessage());
    out(['success' => false, 'error' => 'Server xatosi'], 500);
}
