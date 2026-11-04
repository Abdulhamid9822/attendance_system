<?php
// sync_employees.php - TO'G'RILANGAN (poster_account_id -> account_id)

require_once __DIR__ . '/includes/session.php';
$sid = $_GET['sid'] ?? $_POST['sid'] ?? null;
if (!is_string($sid) || !preg_match('/^[a-f0-9]{32}$/', $sid)) {
    http_response_code(400);
    exit('Sessiya konteksti noto‘g‘ri.');
}
startAppSession($sid);
if (($_SESSION['logged_in'] ?? false) !== true) {
    http_response_code(403);
    exit('Sessiya yaroqsiz. Qayta kiring.');
}

require_once 'includes/config/database.php';

// ============================================================
// 1. MA'LUMOTLARNI OLISH
// ============================================================
$token = $_SESSION['access_token'] ?? $_SESSION['token'] ?? '';
$account = $_SESSION['account'] ?? '';
$account_id = $_SESSION['account_id'] ?? $_SESSION['poster_account_id'] ?? 0;

error_log("=== SYNC EMPLOYEES START ===");
error_log("Account: $account, Account ID: $account_id, SID: " . ($sid ?? 'NULL'));

// ============================================================
// 2. TOKEN VA ACCOUNT TEKSHIRISH
// ============================================================
if (empty($token) || empty($account)) {
    error_log("FATAL: No token or account found!");
    if (!empty($sid)) {
        header("Location: /?tab=employees&sid=" . urlencode($sid) . "&sync_error=1");
    } else {
        header("Location: ?tab=employees&sync_error=1");
    }
    exit;
}

// ============================================================
// 3. POSTER DAN KATEGORIYALARNI OLISH
// ============================================================
$url = "https://{$account}.joinposter.com/api/finance.getCategories?token=" . $token;
error_log("Poster API URL: " . $url);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

error_log("HTTP Status: $httpCode");

if ($curlError || $httpCode != 200 || empty($response)) {
    error_log("Poster API error: " . ($curlError ?: "HTTP $httpCode"));
    if (!empty($sid)) {
        header("Location: /?tab=employees&sid=" . urlencode($sid) . "&sync_error=2");
    } else {
        header("Location: ?tab=employees&sync_error=2");
    }
    exit;
}

$data = json_decode($response, true);

if (isset($data['error']) || !isset($data['response'])) {
    error_log("Poster API error: " . print_r($data['error'] ?? 'No response', true));
    if (!empty($sid)) {
        header("Location: /?tab=employees&sid=" . urlencode($sid) . "&sync_error=3");
    } else {
        header("Location: ?tab=employees&sync_error=3");
    }
    exit;
}

$categories = $data['response'];
error_log("Total categories: " . count($categories));

// ============================================================
// 4. "ISH HAQI" KATEGORIYASINI IZLASH
// ============================================================
$salaryId = null;
$salaryName = null;
$search_terms = ['ish haqi', 'ish haq', 'salary', 'зарплата', 'staff', 'xodimlar', 'Зарплата'];

foreach ($categories as $cat) {
    $catName = strtolower(trim($cat['name'] ?? ''));
    foreach ($search_terms as $term) {
        if (strpos($catName, $term) !== false) {
            $salaryId = $cat['category_id'];
            $salaryName = $cat['name'];
            error_log("Found salary category: ID=$salaryId, Name='$salaryName'");
            break 2;
        }
    }
}

if (!$salaryId && !empty($categories)) {
    $salaryId = $categories[0]['category_id'];
    $salaryName = $categories[0]['name'];
    error_log("No salary category found, using first: ID=$salaryId, Name='$salaryName'");
}

if (!$salaryId) {
    error_log("No category found!");
    if (!empty($sid)) {
        header("Location: /?tab=employees&sid=" . urlencode($sid) . "&sync_error=4");
    } else {
        header("Location: ?tab=employees&sync_error=4");
    }
    exit;
}

// ============================================================
// 5. XODIMLARNI SINXRONLASH
// ============================================================
$syncedCount = 0;
$createdCount = 0;
$updatedCount = 0;
$inactiveCount = 0;
$reactivatedCount = 0;
$errorCount = 0;

error_log("Starting employee sync for account: $account");

// Poster'dan kelgan xodim ID larini yig'ish
$posterEmployeeIds = [];

foreach ($categories as $cat) {
    if (($cat['parent_id'] ?? 0) == $salaryId) {
        $posterId = $cat['category_id'];
        $posterEmployeeIds[] = $posterId;
        $name = $cat['name'] ?? 'Noma\'lum';
        
        try {
            $checkStmt = $pdo->prepare("
                SELECT id, ismi, holati, passive_since 
                FROM xodimlar 
                WHERE poster_id = ? AND account_name = ?
            ");
            $checkStmt->execute([$posterId, $account]);
            $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($exists) {
                // Agar xodim 'inactive' holatida bo'lsa, qayta ACTIVE qilamiz
                if ($exists['holati'] === 'inactive') {
                    error_log("  🔄 Reactivating: $name (was inactive) - Account: $account");
                    
                    $stmt = $pdo->prepare("
                        UPDATE xodimlar 
                        SET ismi = ?, 
                            holati = 'active',
                            passive_since = NULL
                        WHERE poster_id = ? AND account_name = ?
                    ");
                    $stmt->execute([$name, $posterId, $account]);
                    $reactivatedCount++;
                } else {
                    if ($exists['ismi'] != $name) {
                        error_log("  📝 Updating: $name (ID: {$exists['id']}) - Account: $account");
                        
                        $stmt = $pdo->prepare("
                            UPDATE xodimlar 
                            SET ismi = ?
                            WHERE poster_id = ? AND account_name = ?
                        ");
                        $stmt->execute([$name, $posterId, $account]);
                        $updatedCount++;
                    } else {
                        error_log("  ✅ No change: $name (poster_id: $posterId) - Account: $account");
                    }
                }
            } else {
                error_log("  🆕 Creating: $name - Account: $account");
                
                // ✅ TO'G'RI: account_id ishlatiladi (poster_account_id emas)
                $stmt = $pdo->prepare("
                    INSERT INTO xodimlar 
                    (poster_id, ismi, holati, account_name, account_id, yaratilgan) 
                    VALUES (?, ?, 'active', ?, ?, NOW())
                ");
                $stmt->execute([
                    $posterId,
                    $name,
                    $account,
                    $account_id
                ]);
                $createdCount++;
            }
            $syncedCount++;
            
        } catch (Exception $e) {
            $errorCount++;
            error_log("  ERROR: " . $e->getMessage());
        }
    }
}

error_log("Sync results: Synced=$syncedCount, Created=$createdCount, Updated=$updatedCount, Reactivated=$reactivatedCount, Errors=$errorCount");

// ============================================================
// 6. POSTER'DA YO'Q XODIMLARNI INACTIVE QILISH
// ============================================================
try {
    $stmt = $pdo->prepare("
        SELECT id, poster_id, ismi 
        FROM xodimlar 
        WHERE account_name = ? 
        AND poster_id IS NOT NULL 
        AND poster_id != ''
        AND holati = 'active'
    ");
    $stmt->execute([$account]);
    $dbEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Active database employees for account $account: " . count($dbEmployees));
    
    foreach ($dbEmployees as $emp) {
        if (!in_array($emp['poster_id'], $posterEmployeeIds)) {
            error_log("  💤 Marking as inactive: {$emp['ismi']} (ID: {$emp['id']}, Poster ID: {$emp['poster_id']}) - Account: $account");
            
            $stmt = $pdo->prepare("
                UPDATE xodimlar 
                SET holati = 'inactive', 
                    passive_since = NOW()
                WHERE id = ? AND account_name = ?
            ");
            $stmt->execute([$emp['id'], $account]);
            
            $inactiveCount++;
        }
    }
    
    error_log("Marked as inactive for account $account: $inactiveCount employees");
    
} catch (Exception $e) {
    error_log("Update employee status error: " . $e->getMessage());
}

// ============================================================
// 7. ✅ REDIRECT
// ============================================================
error_log("=== SYNC COMPLETED for account: $account ===");
error_log("Results: Created=$createdCount, Updated=$updatedCount, Reactivated=$reactivatedCount, Inactive=$inactiveCount, Errors=$errorCount");
error_log("Category: $salaryName (ID: $salaryId)");

$redirectSid = !empty($sid) ? '&sid=' . urlencode($sid) : '';
$redirectUrl = "/?tab=employees&synced=1&count=" . $syncedCount . "&created=" . $createdCount . "&updated=" . $updatedCount . "&inactive=" . $inactiveCount . "&reactivated=" . $reactivatedCount . $redirectSid;

error_log("Redirecting to: $redirectUrl");

header("Location: $redirectUrl");
exit;

?>
