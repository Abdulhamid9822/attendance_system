<?php
// index.php
ini_set('display_errors', 0);
ini_set('error_log', __DIR__ . '/php_errors.log');
error_reporting(E_ALL);
// ============================================
// ERROR LOG CHEKLOVCHI - 10MB VA 30000 QATOR
// ============================================
function checkAndRotateErrorLog() {
    $logFile = __DIR__ . '/php_errors.log';
    
    if (!file_exists($logFile)) {
        return;
    }
    
    $maxSize = 10 * 1024 * 1024; // 10MB
    $maxLines = 30000;
    
    $fileSize = filesize($logFile);
    if ($fileSize > $maxSize) {
        // Log faylini arxivga o'tkazamiz
        $backupFile = __DIR__ . '/php_errors_old.log';
        if (file_exists($backupFile)) {
            unlink($backupFile);
        }
        rename($logFile, $backupFile);
        error_log("=== ERROR LOG ROTATED (size: " . round($fileSize/1024/1024, 2) . "MB) ===");
        return;
    }
    
    // Qatorlar sonini tekshiramiz
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false && count($lines) > $maxLines) {
        // Oxirgi 25000 qatorni saqlaymiz
        $keepLines = 25000;
        $newLines = array_slice($lines, -$keepLines);
        
        // Eski faylni arxivga o'tkazamiz
        $backupFile = __DIR__ . '/php_errors_old.log';
        if (file_exists($backupFile)) {
            unlink($backupFile);
        }
        rename($logFile, $backupFile);
        
        // Yangi faylni yaratamiz
        file_put_contents($logFile, "=== ERROR LOG ROTATED (lines: " . count($lines) . " -> " . count($newLines) . ") ===\n");
        file_put_contents($logFile, implode("\n", $newLines) . "\n", FILE_APPEND);
        
        error_log("=== ERROR LOG ROTATED (lines: " . count($lines) . " -> " . count($newLines) . ") ===");
    }
}

// Log faylini tekshiramiz
checkAndRotateErrorLog();
require_once __DIR__ . '/includes/session.php';
$sid = startAppSession();

error_log("=== INDEX.PHP ===");
error_log("tab=" . ($_GET['tab'] ?? 'NULL'));

// ============================================
// DATABASE
// ============================================
require_once __DIR__ . '/includes/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions/branch_access.php';
ensureBranchAccessSchema($pdo);

// ============================================
// CHIQISH
// ============================================
if (isset($_GET['logout']) || (isset($_GET['tab']) && $_GET['tab'] === 'logout')) {
    logout();
    $loginParams = [];
    if (is_string($sid) && preg_match('/^[a-f0-9]{32}$/', $sid)) {
        $loginParams['context'] = $sid;
    }
    if (in_array($_GET['tab'] ?? '', ['dashboard', 'employees', 'attendance', 'reports', 'settings', 'positions', 'branches', 'shifts'], true)) {
        $loginParams['tab'] = $_GET['tab'];
    }
    header('Location: /login.php' . ($loginParams ? '?' . http_build_query($loginParams) : ''));
    exit;
}

// ============================================
// ACCOUNT NAME
// ============================================
function fixAccountName($name) {
    if (empty($name)) return '';
    return strtolower(str_replace(' ', '-', trim($name)));
}

// ============================================
// POSTER OAUTH - CODE NI QAYTA ISHLASH
// ============================================
$application_id = getenv('POSTER_APPLICATION_ID');
$application_secret = getenv('POSTER_APPLICATION_SECRET');

$raw_code = $_GET['code'] ?? null;

if (!empty($raw_code) && strlen($raw_code) === 32) {
    // Login CSRF himoyasi: state brauzer sessiyasidagi qiymatga mos bo'lishi shart
    $expectedState = $_SESSION['oauth_state'] ?? '';
    $givenState = $_GET['state'] ?? '';
    // Poster filial xodimlari kirishida state yuborilmasligi mumkin; noto'g'ri state esa rad etiladi
    $stateMissing = ($givenState === '' || $givenState === null);
    $stateValid = is_string($expectedState) && $expectedState !== '' && is_string($givenState)
        && hash_equals($expectedState, $givenState);
    if (!$stateMissing && !$stateValid) {
        error_log('Poster OAuth state mismatch.');
        http_response_code(403);
        exit('Kirish so‘rovi yaroqsiz. Iltimos, qaytadan kirishni boshlang.');
    }
    if ($stateMissing) {
        error_log('Poster OAuth callback without state (Poster-initiated login).');
    }
    unset($_SESSION['oauth_state']);
    if (!$sid || !is_string($application_id) || $application_id === ''
        || !is_string($application_secret) || $application_secret === '') {
        error_log('Poster OAuth callback missing session context or application configuration.');
        http_response_code(500);
        exit('Poster integratsiyasi sozlamalari noto‘g‘ri. Administratorga murojaat qiling.');
    }

    $auth = [
        'application_id'     => $application_id,
        'application_secret' => $application_secret,
        'code'               => $raw_code,
    ];
    $auth['verify'] = md5(implode(':', $auth));

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://joinposter.com/api/v2/auth/manage');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($auth));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("Poster Auth Response Code: " . $httpCode);
    if ($response === false) {
        error_log("Poster auth request failed: " . $curlError);
    }

    if ($response) {
        $auth_data = json_decode($response, true);

        if (isset($auth_data['access_token'])) {
            $account_number = $auth_data['account_number'] ?? null;
            $user           = $auth_data['user']      ?? [];
            $owner          = $auth_data['ownerInfo'] ?? [];
            $new_token      = $auth_data['access_token'];
            $account_domain = $owner['account_name'] ?? $auth_data['account_name'] ?? $account_number;
            $account_name   = fixAccountName($owner['company_name'] ?? $account_number);

            // Mijoz kaliti kompaniya nomidan olinadi, shuning uchun uni o'zgarmas account_number ga bog'laymiz
            if ($account_number === null || $account_number === '' || $account_name === '') {
                http_response_code(403);
                exit('Poster akkaunti aniqlanmadi. Administratorga murojaat qiling.');
            }
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_account_bindings (
                    account_name VARCHAR(191) NOT NULL PRIMARY KEY,
                    account_number VARCHAR(64) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB");
                $pdo->exec("INSERT IGNORE INTO tenant_account_bindings (account_name, account_number)
                    SELECT account_name, MIN(account_id) FROM poster_tokens
                    WHERE account_name IS NOT NULL AND account_name <> '' GROUP BY account_name");
                $pdo->prepare("INSERT IGNORE INTO tenant_account_bindings (account_name, account_number) VALUES (?, ?)")
                    ->execute([$account_name, (string)$account_number]);
                $bindingStmt = $pdo->prepare("SELECT account_number FROM tenant_account_bindings WHERE account_name = ?");
                $bindingStmt->execute([$account_name]);
                $boundNumber = $bindingStmt->fetchColumn();
            } catch (Throwable $e) {
                error_log('Tenant binding check failed: ' . $e->getMessage());
                http_response_code(500);
                exit('Akkauntni tekshirib bo‘lmadi. Keyinroq qayta urinib ko‘ring.');
            }
            if ((string)$boundNumber !== (string)$account_number) {
                error_log("Tenant name collision blocked: {$account_name} bound to {$boundNumber}, login from {$account_number}");
                http_response_code(403);
                exit('Bu kompaniya nomi boshqa Poster akkauntiga tegishli. Administratorga murojaat qiling.');
            }
            $posterUserId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT);
            if ($posterUserId === false || $posterUserId === null || $posterUserId <= 0) {
                http_response_code(403);
                exit('Poster foydalanuvchisi aniqlanmadi. Administratorga murojaat qiling.');
            }

            $employeesUrl = 'https://' . rawurlencode((string)$account_domain)
                . '.joinposter.com/api/access.getEmployees?'
                . http_build_query(['token' => $new_token]);
            $employeesCh = curl_init($employeesUrl);
            if ($employeesCh === false) {
                http_response_code(502);
                exit('Poster xodimlar ro‘yxatini tekshirib bo‘lmadi. Keyinroq qayta urinib ko‘ring.');
            }
            curl_setopt_array($employeesCh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => 15,
            ]);
            $employeesResponse = curl_exec($employeesCh);
            $employeesCurlError = curl_error($employeesCh);
            $employeesHttpCode = (int)curl_getinfo($employeesCh, CURLINFO_HTTP_CODE);
            curl_close($employeesCh);
            $employeesData = $employeesResponse !== false
                ? json_decode($employeesResponse, true)
                : null;
            if (
                $employeesResponse === false
                || $employeesHttpCode < 200
                || $employeesHttpCode >= 300
                || !is_array($employeesData['response'] ?? null)
            ) {
                error_log('Poster access.getEmployees failed: ' . ($employeesCurlError ?: "HTTP {$employeesHttpCode}"));
                http_response_code(502);
                exit('Poster xodimlar ro‘yxatini tekshirib bo‘lmadi. Keyinroq qayta urinib ko‘ring.');
            }
            $posterEmployee = null;
            foreach ($employeesData['response'] as $posterEmployeeRow) {
                if ((int)($posterEmployeeRow['user_id'] ?? 0) === (int)$posterUserId) {
                    $posterEmployee = $posterEmployeeRow;
                    break;
                }
            }
            $hasAdminPanelAccess = is_array($posterEmployee)
                && posterEmployeeHasAdminPanelAccess($posterEmployee);
            $isPosterOwner = (int)($posterEmployee['user_type'] ?? -1) === 90;

            $userAccess = null;
            $accessStmt = $pdo->prepare("
                SELECT access_role, spot_id
                FROM branch_user_access
                WHERE account_name = ? AND poster_user_id = ?
                LIMIT 1
            ");
            $accessStmt->execute([$account_name, $posterUserId]);
            $userAccess = $accessStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($isPosterOwner && ($userAccess['access_role'] ?? '') !== 'admin') {
                $registerAdminStmt = $pdo->prepare("
                    INSERT INTO branch_user_access
                        (account_name, poster_user_id, spot_id, access_role)
                    VALUES (?, ?, NULL, 'admin')
                    ON DUPLICATE KEY UPDATE spot_id = NULL, access_role = 'admin'
                ");
                $registerAdminStmt->execute([$account_name, $posterUserId]);
                $userAccess = ['access_role' => 'admin', 'spot_id' => null];
            }
            if ($userAccess === null && $hasAdminPanelAccess) {
                $pendingStmt = $pdo->prepare("
                    INSERT IGNORE INTO branch_user_access
                        (account_name, poster_user_id, spot_id, access_role)
                    VALUES (?, ?, NULL, 'pending')
                ");
                $pendingStmt->execute([$account_name, $posterUserId]);
                http_response_code(403);
                exit('Poster foydalanuvchisi filialga biriktirilmagan. Bosh administratorga murojaat qiling.');
            }
            if ($userAccess === null && !$isPosterOwner) {
                http_response_code(403);
                exit('Poster foydalanuvchisiga tizimga kirish huquqi biriktirilmagan. Bosh administratorga murojaat qiling.');
            }
            if (($userAccess['access_role'] ?? '') === 'pending') {
                http_response_code(403);
                exit('Poster foydalanuvchisi hali filialga biriktirilmagan. Bosh administratorga murojaat qiling.');
            }
            if (($userAccess['access_role'] ?? '') === 'admin' && !$isPosterOwner) {
                http_response_code(403);
                exit('Filiallarni faqat Poster akkaunt egasi boshqarishi mumkin.');
            }
            if (($userAccess['access_role'] ?? '') === 'branch' && !$hasAdminPanelAccess) {
                http_response_code(403);
                exit('Poster foydalanuvchisida admin paneliga ruxsat yo‘q. Administratorga murojaat qiling.');
            }

            if (($userAccess['access_role'] ?? '') === 'branch') {
                session_regenerate_id(true);
                $_SESSION['logged_in'] = true;
                $_SESSION['auth_type'] = 'poster_branch';
                $_SESSION['branch_spot_id'] = (int)$userAccess['spot_id'];
                $_SESSION['user_id'] = (int)$posterUserId;
                $_SESSION['user_name'] = $user['name'] ?? null;
                $_SESSION['user_email'] = $user['email'] ?? null;
                $_SESSION['user_role'] = $user['role_id'] ?? null;
                $_SESSION['poster_user_type'] = (int)($posterEmployee['user_type'] ?? -1);
                $_SESSION['access_token'] = $new_token;
                $_SESSION['account_number'] = $account_number;
                $_SESSION['account_id'] = $account_number;
                $_SESSION['account_name'] = $account_name;
                $_SESSION['account'] = $account_name;
                $_SESSION['account_domain'] = $account_domain;
                $_SESSION['ownerInfo'] = $owner;

                $targetTab = in_array($_GET['tab'] ?? '', ['dashboard', 'employees', 'attendance', 'reports', 'settings', 'positions', 'branches', 'shifts'], true)
                    ? $_GET['tab']
                    : 'dashboard';
                header('Location: /' . $targetTab . '/' . rawurlencode($sid));
                exit;
            }

            // Bazaga saqlash
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO poster_tokens (account_id, access_token, account_name, account_domain)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        access_token = VALUES(access_token),
                        account_name = VALUES(account_name),
                        account_domain = VALUES(account_domain)
                ");
                $stmt->execute([$account_number, $new_token, $account_name, $account_domain]);
            } catch (Exception $e) {
                error_log("Token save error: " . $e->getMessage());
            }

            // Session ga yozish
            session_regenerate_id(true);
            if (function_exists('login')) {
                login([
                    'access_token'   => $new_token,
                    'account_number' => $account_number,
                    'account_name'   => $account_name,
                    'user'           => $user,
                    'ownerInfo'      => $owner,
                ]);
            } else {
                $_SESSION['logged_in']      = true;
                $_SESSION['access_token']   = $new_token;
                $_SESSION['account_number'] = $account_number;
                $_SESSION['account_name']   = $account_name;
            }

            $_SESSION['auth_type'] = 'poster';
            unset($_SESSION['branch_spot_id']);
            $_SESSION['account']        = $account_name;
            $_SESSION['account_domain'] = $account_domain;
            $_SESSION['user_id']        = $user['id'] ?? null;
            $_SESSION['user_name']      = $user['name'] ?? null;
            $_SESSION['user_email']     = $user['email'] ?? null;
            $_SESSION['user_role']      = $user['role_id'] ?? null;
            $_SESSION['poster_user_type'] = (int)($posterEmployee['user_type'] ?? -1);
            $_SESSION['ownerInfo']      = $owner;

            $targetTab = in_array($_GET['tab'] ?? '', ['dashboard', 'employees', 'attendance', 'reports', 'settings', 'positions', 'branches', 'shifts'], true)
                ? $_GET['tab']
                : 'dashboard';
            header('Location: /' . $targetTab . '/' . rawurlencode($sid));
            exit;

        } else {
            $err_msg = $auth_data['message'] ?? 'Autorizatsiya xatosi';
            $err_msg .= ' (HTTP: ' . $httpCode . ')';
            error_log("Poster authentication rejected with HTTP status " . $httpCode . ".");
            die('<p style="font-family:sans-serif;padding:20px;color:red;">❌ ' . htmlspecialchars($err_msg) . '</p>');
        }
    } else {
        die('<p style="font-family:sans-serif;padding:20px;color:red;">❌ Poster serveriga ulanib bo\'lmadi.</p>');
    }
}

// ============================================
// SID BILAN KELDI — SESSIYA TEKSHIRISH
// ============================================
if ($sid && isLoggedIn()) {
    require_once __DIR__ . '/app.php';
    exit;
}

// ============================================
// HECH NARSA YO'Q — LOGIN SAHIFASIGA
// ============================================
$loginParams = [];
if (is_string($sid) && preg_match('/^[a-f0-9]{32}$/', $sid)) {
    $loginParams['context'] = $sid;
}
if (in_array($_GET['tab'] ?? '', ['dashboard', 'employees', 'attendance', 'reports', 'settings', 'positions', 'branches', 'shifts'], true)) {
    $loginParams['tab'] = $_GET['tab'];
}
header('Location: /login.php' . ($loginParams ? '?' . http_build_query($loginParams) : ''));
exit;