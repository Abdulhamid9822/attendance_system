<?php

// Kech kelish / erta ketish arizalari. Tasdiqlangan ariza bo'lgan kunga jarima qo'llanmaydi.

function ensureExcuseSchema(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS excuse_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_name VARCHAR(100) NOT NULL,
            xodim_id INT NOT NULL,
            request_date DATE NOT NULL,
            type ENUM('late','early') NOT NULL,
            reason VARCHAR(500) NOT NULL DEFAULT '',
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            decided_by VARCHAR(100) NULL,
            decided_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_excuse (xodim_id, request_date, type),
            KEY idx_excuse_account (account_name, status)
        ) CHARACTER SET utf8mb4
    ");
    $cols = $pdo->query("SHOW COLUMNS FROM excuse_requests")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('expected_time', $cols, true)) {
        $pdo->exec("ALTER TABLE excuse_requests ADD COLUMN expected_time TIME NULL AFTER type");
    }
    $done = true;
}

function excuseClock($value)
{
    return preg_match('/(\d{1,2}):(\d{2})/', (string)$value, $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : null;
}

// type: 'late' (kech kelish) yoki 'early' (erta ketish).
// $actualTime: haqiqiy kelgan/ketgan vaqt. Ariza vaqtidan kech kelsa yoki erta ketsa, ariza jarimani bekor qilmaydi.
function isExcused($employeeId, $date, $type, $actualTime = null)
{
    global $pdo;
    static $cache = [];
    $employeeId = (int)$employeeId;
    if ($employeeId <= 0 || !($pdo instanceof PDO)) {
        return false;
    }
    if (!isset($cache[$employeeId])) {
        $cache[$employeeId] = [];
        try {
            ensureExcuseSchema($pdo);
            $stmt = $pdo->prepare("SELECT request_date, type, expected_time FROM excuse_requests WHERE xodim_id = ? AND status = 'approved'");
            $stmt->execute([$employeeId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cache[$employeeId][$row['request_date'] . '|' . $row['type']] = excuseClock($row['expected_time']) ?? '';
            }
        } catch (Throwable $e) {
            error_log('isExcused: ' . $e->getMessage());
        }
    }
    $key = substr((string)$date, 0, 10) . '|' . $type;
    if (!isset($cache[$employeeId][$key])) {
        return false;
    }
    $expected = $cache[$employeeId][$key];
    $actual = excuseClock($actualTime);
    if ($expected === '' || $actual === null) {
        return true;
    }
    return $type === 'early' ? $actual >= $expected : $actual <= $expected;
}

function excuseTypeLabel($type)
{
    return $type === 'early' ? 'Erta ketish' : 'Kech kelish';
}
