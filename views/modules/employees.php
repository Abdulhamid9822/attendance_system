<?php
// modules/employees.php - TO'LIQ (BIR NECHTA MAOSH TURLARI BILAN) + yaratilgan tahrirlash

require_once __DIR__ . '/../../includes/language.php';
require_once __DIR__ . '/../../index.php';      
require_once __DIR__ . '/../../includes/functions/employee_classification.php';
require_once __DIR__ . '/../../includes/functions/employee_filter_bar.php';
require_once __DIR__ . '/../../includes/functions/employee.php';
require_once __DIR__ . '/../../includes/functions/branch_access.php';

global $pdo, $defaultSalary, $currencySymbol, $dateFrom, $dateTo, $currentLang;

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedCsrfToken) || !hash_equals($csrfToken, $postedCsrfToken)) {
        http_response_code(403);
        if (isset($_POST['action'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'CSRF token noto\'g\'ri. Sahifani yangilang.']);
        } else {
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'CSRF token noto\'g\'ri. Sahifani yangilang.';
        }
        exit;
    }
}

function isValidEmployeeDateInput($value, $format) {
    if (!is_string($value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
    return $date !== false && $date->format($format) === $value;
}

function normalizeEmployeeSalaryTypes($salaryTypes) {
    if (!is_array($salaryTypes)) {
        $salaryTypes = [$salaryTypes];
    }

    $normalized = [];
    foreach ($salaryTypes as $salaryType) {
        if (is_string($salaryType) && in_array($salaryType, ['fixed', 'hourly', 'percent', 'shift'], true)) {
            $normalized[] = $salaryType;
        }
    }

    $normalized = array_values(array_unique($normalized));
    if (empty($normalized)) {
        throw new Exception('Kamida bitta yaroqli maosh turi tanlanishi kerak.');
    }

    return $normalized;
}

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && in_array($_POST['action'] ?? '', ['load_schedule', 'save_schedule', 'apply_schedule_bulk'], true)
) {
    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => false, 'message' => ''];

    try {
        $action = $_POST['action'];
        $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
        if ($employeeId === false || $employeeId <= 0) {
            throw new InvalidArgumentException('Xodim noto‘g‘ri.');
        }

        $accountNameForAction = (string)($_SESSION['account'] ?? $_SESSION['account_name'] ?? '');
        $ownerStmt = $pdo->prepare("
            SELECT id FROM xodimlar
            WHERE id = ? AND account_name = ?
            LIMIT 1
        ");
        $ownerStmt->execute([$employeeId, $accountNameForAction]);
        if (!$ownerStmt->fetchColumn()) {
            throw new RuntimeException('Xodim topilmadi yoki ruxsat yo‘q.');
        }
        assertBranchEmployeeAccess($pdo, $employeeId, $accountNameForAction);

        $readSelectedTemplate = static function () use ($pdo, $accountNameForAction) {
            $templateId = filter_var($_POST['template_id'] ?? null, FILTER_VALIDATE_INT);
            if ($templateId === false || $templateId <= 0) {
                throw new InvalidArgumentException('Avval saqlangan smenani tanlang.');
            }
            $templateStmt = $pdo->prepare("
                SELECT ishga_kelish_vaqti, ishdan_ketish_vaqti,
                       tushlik_boshlanish, tushlik_tugash
                FROM employee_work_schedule_templates
                WHERE id = ? AND account_name = ?
                LIMIT 1
            ");
            $templateStmt->execute([$templateId, $accountNameForAction]);
            $template = $templateStmt->fetch(PDO::FETCH_ASSOC);
            if (!$template) {
                throw new InvalidArgumentException('Tanlangan smena topilmadi. Ro‘yxatni yangilang.');
            }
            return $template;
        };

        if ($action === 'load_schedule') {
            $effectiveDate = $_POST['effective_date'] ?? null;
            if (!isValidEmployeeDateInput($effectiveDate, 'Y-m-d')) {
                throw new InvalidArgumentException('Sana noto‘g‘ri.');
            }
            $baselineStmt = $pdo->prepare("
                SELECT amal_qiladi, ishga_kelish_vaqti, ishdan_ketish_vaqti,
                       tushlik_boshlanish, tushlik_tugash
                FROM oylik_sozlamalar
                WHERE xodim_id = ? AND oy_vaqt = ? AND amal_qiladi <= ?
                ORDER BY amal_qiladi DESC, id DESC
                LIMIT 1
            ");
            $baselineStmt->execute([$employeeId, date('Y-m-01', strtotime($effectiveDate)), $effectiveDate]);
            $schedule = $baselineStmt->fetch(PDO::FETCH_ASSOC);
            if (!$schedule) {
                $baselineStmt = $pdo->prepare("
                    SELECT amal_qiladi, ishga_kelish_vaqti, ishdan_ketish_vaqti,
                           tushlik_boshlanish, tushlik_tugash
                    FROM oylik_sozlamalar
                    WHERE xodim_id = ? AND amal_qiladi <= ?
                    ORDER BY amal_qiladi DESC, oy_vaqt DESC, id DESC
                    LIMIT 1
                ");
                $baselineStmt->execute([$employeeId, $effectiveDate]);
                $schedule = $baselineStmt->fetch(PDO::FETCH_ASSOC);
            }
            $schedule = $schedule ?: [
                'amal_qiladi' => null,
                'ishga_kelish_vaqti' => '09:00:00',
                'ishdan_ketish_vaqti' => '18:00:00',
                'tushlik_boshlanish' => '13:00:00',
                'tushlik_tugash' => '14:00:00',
            ];
            $override = getEmployeeWorkScheduleOverride($pdo, $employeeId, $effectiveDate);
            if ($override && $override['amal_qiladi'] >= ($schedule['amal_qiladi'] ?? '')) {
                $schedule = $override;
            }
            $historyStmt = $pdo->prepare("
                SELECT effective_date AS amal_qiladi, ishga_kelish_vaqti, ishdan_ketish_vaqti,
                       tushlik_boshlanish, tushlik_tugash, 'day' AS source
                FROM employee_work_schedules
                WHERE xodim_id = ? AND account_name = ?
                UNION ALL
                SELECT oylik_sozlamalar.amal_qiladi, oylik_sozlamalar.ishga_kelish_vaqti,
                       oylik_sozlamalar.ishdan_ketish_vaqti, oylik_sozlamalar.tushlik_boshlanish,
                       oylik_sozlamalar.tushlik_tugash, 'setting' AS source
                FROM oylik_sozlamalar
                WHERE oylik_sozlamalar.xodim_id = ?
                  AND oylik_sozlamalar.amal_qiladi IS NOT NULL
                ORDER BY amal_qiladi DESC
            ");
            $historyStmt->execute([
                $employeeId,
                $accountNameForAction,
                $employeeId,
            ]);
            $response = [
                'success' => true,
                'employee_id' => $employeeId,
                'effective_date' => $effectiveDate,
                'schedule' => $schedule,
                'history' => $historyStmt->fetchAll(PDO::FETCH_ASSOC),
            ];
        } elseif ($action === 'apply_schedule_bulk') {
            $selectedDates = $_POST['selected_dates'] ?? null;
            if (!is_array($selectedDates) || count($selectedDates) < 1 || count($selectedDates) > 366) {
                throw new InvalidArgumentException('1 dan 366 tagacha kunni tanlang.');
            }
            foreach ($selectedDates as $date) {
                if (!isValidEmployeeDateInput($date, 'Y-m-d')) {
                    throw new InvalidArgumentException('Tanlangan sanalardan biri noto‘g‘ri.');
                }
            }
            $selectedDates = array_values(array_unique($selectedDates));
            sort($selectedDates);
            $times = $readSelectedTemplate();

            $saveSchedule = $pdo->prepare("
                INSERT INTO employee_work_schedules
                    (xodim_id, account_name, effective_date, ishga_kelish_vaqti,
                     ishdan_ketish_vaqti, tushlik_boshlanish, tushlik_tugash)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    account_name = VALUES(account_name),
                    ishga_kelish_vaqti = VALUES(ishga_kelish_vaqti),
                    ishdan_ketish_vaqti = VALUES(ishdan_ketish_vaqti),
                    tushlik_boshlanish = VALUES(tushlik_boshlanish),
                    tushlik_tugash = VALUES(tushlik_tugash)
            ");
            $pdo->beginTransaction();
            foreach ($selectedDates as $effectiveDate) {
                $saveSchedule->execute([
                    $employeeId,
                    $accountNameForAction,
                    $effectiveDate,
                    $times['ishga_kelish_vaqti'],
                    $times['ishdan_ketish_vaqti'],
                    $times['tushlik_boshlanish'],
                    $times['tushlik_tugash'],
                ]);
            }
            $pdo->commit();

            $recalculation = recalculateAttendanceForEmployee($employeeId, $selectedDates[0]);
            $response = [
                'success' => true,
                'message' => 'Tanlangan kunlar uchun ish grafigi saqlandi.',
                'employee_id' => $employeeId,
                'effective_date' => $selectedDates[0],
                'selected_dates' => $selectedDates,
                'schedule' => $times,
                'recalculated' => (int)($recalculation['updated'] ?? 0),
                'recalculation_message' => (string)($recalculation['message'] ?? ''),
            ];
        } else {
            $effectiveDate = $_POST['effective_date'] ?? null;
            if (!isValidEmployeeDateInput($effectiveDate, 'Y-m-d')) {
                throw new InvalidArgumentException('Sana noto‘g‘ri.');
            }
            $times = $readSelectedTemplate();

            $saveSchedule = $pdo->prepare("
                INSERT INTO employee_work_schedules
                    (xodim_id, account_name, effective_date, ishga_kelish_vaqti,
                     ishdan_ketish_vaqti, tushlik_boshlanish, tushlik_tugash)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    account_name = VALUES(account_name),
                    ishga_kelish_vaqti = VALUES(ishga_kelish_vaqti),
                    ishdan_ketish_vaqti = VALUES(ishdan_ketish_vaqti),
                    tushlik_boshlanish = VALUES(tushlik_boshlanish),
                    tushlik_tugash = VALUES(tushlik_tugash)
            ");
            $saveSchedule->execute([
                $employeeId,
                $accountNameForAction,
                $effectiveDate,
                $times['ishga_kelish_vaqti'],
                $times['ishdan_ketish_vaqti'],
                $times['tushlik_boshlanish'],
                $times['tushlik_tugash'],
            ]);

            $recalculation = recalculateAttendanceForEmployee($employeeId, $effectiveDate);
            $response = [
                'success' => true,
                'message' => 'Ish jadvali saqlandi.',
                'employee_id' => $employeeId,
                'effective_date' => $effectiveDate,
                'schedule' => $times,
                'recalculated' => (int)($recalculation['updated'] ?? 0),
                'recalculation_message' => (string)($recalculation['message'] ?? ''),
            ];
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code($e instanceof InvalidArgumentException ? 422 : 400);
        error_log('Quick schedule update failed: ' . $e->getMessage());
        $response['message'] = $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : 'Ish jadvalini saqlab bo‘lmadi. Sahifani yangilab qayta urinib ko‘ring.';
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// SID
// ============================================================
$sid = isset($_GET['sid']) ? $_GET['sid'] : (isset($_POST['sid']) ? $_POST['sid'] : '');
if(empty($sid) && isset($_SESSION['sid'])) {
    $sid = $_SESSION['sid'];
}
if(!empty($sid)) {
    $_SESSION['sid'] = $sid;
}

$scheduleTemplateAccount = (string)($_SESSION['account'] ?? $_SESSION['account_name'] ?? '');
$scheduleTemplateStmt = $pdo->prepare("
    SELECT id, name, ishga_kelish_vaqti, ishdan_ketish_vaqti,
           tushlik_boshlanish, tushlik_tugash
    FROM employee_work_schedule_templates
    WHERE account_name = ?
    ORDER BY name
");
$scheduleTemplateStmt->execute([$scheduleTemplateAccount]);
$scheduleTemplates = $scheduleTemplateStmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// SOZLAMALAR JADVALIGA oy_vaqt VA maosh_turi USTUNLARINI TEKSHIRISH
// ============================================================
try {
    $columnInfo = $pdo->query("SHOW COLUMNS FROM oylik_sozlamalar")->fetchAll(PDO::FETCH_ASSOC);
    $columns = array_column($columnInfo, 'Field');
    
    if (!in_array('oy_vaqt', $columns)) {
        $pdo->exec("ALTER TABLE oylik_sozlamalar ADD COLUMN oy_vaqt DATE NULL AFTER xodim_id");
        error_log("oy_vaqt column added to oylik_sozlamalar");
    }
    
    if (!in_array('maosh_turi', $columns)) {
        $pdo->exec("ALTER TABLE oylik_sozlamalar ADD COLUMN maosh_turi TEXT NULL AFTER oy_vaqt");
        error_log("maosh_turi column added to oylik_sozlamalar");
    } else {
        foreach ($columnInfo as $column) {
            if ($column['Field'] === 'maosh_turi' && stripos($column['Type'], 'enum(') === 0) {
                $pdo->exec("ALTER TABLE oylik_sozlamalar MODIFY maosh_turi TEXT NULL");
                error_log("maosh_turi column converted to TEXT in oylik_sozlamalar");
                break;
            }
        }
    }
    if (!in_array('smena_summa', $columns)) {
        $pdo->exec("ALTER TABLE oylik_sozlamalar ADD COLUMN smena_summa DECIMAL(15,2) NOT NULL DEFAULT 0");
        error_log("smena_summa column added to oylik_sozlamalar");
    }
    
    // Eski ma'lumotlarni to'ldirish
    $pdo->exec("UPDATE oylik_sozlamalar SET oy_vaqt = DATE_FORMAT(amal_qiladi, '%Y-%m-01') WHERE oy_vaqt IS NULL");
    $pdo->exec("UPDATE oylik_sozlamalar SET maosh_turi = '[\"fixed\"]' WHERE maosh_turi IS NULL OR maosh_turi = ''");
    
} catch (PDOException $e) {
    error_log("Jadval tuzatish xatosi: " . $e->getMessage());
}

// ============================================================
// XODIMLAR JADVALIGA account_id USTUNINI QO'SHISH
// ============================================================
try {
    $columns = $pdo->query("SHOW COLUMNS FROM xodimlar")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('account_id', $columns)) {
        $pdo->exec("ALTER TABLE xodimlar ADD COLUMN account_id VARCHAR(50) NULL AFTER account_name");
        $pdo->exec("ALTER TABLE xodimlar ADD INDEX idx_account_id (account_id)");
        error_log("account_id column added to xodimlar");
        
        // Mavjud xodimlarga account_id qo'shish
        $stmt = $pdo->prepare("SELECT DISTINCT account_name FROM xodimlar WHERE account_id IS NULL");
        $stmt->execute();
        $accounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($accounts as $account) {
            // account_name ga mos account_id ni sozlamalardan olish
            $stmt2 = $pdo->prepare("SELECT qiymat FROM sozlamalar WHERE kalit = 'account_id' AND account_name = ?");
            $stmt2->execute([$account]);
            $account_id = $stmt2->fetchColumn();
            
            if (!empty($account_id)) {
                $stmt3 = $pdo->prepare("UPDATE xodimlar SET account_id = ? WHERE account_name = ? AND account_id IS NULL");
                $stmt3->execute([$account_id, $account]);
                error_log("Updated account_id for $account: $account_id");
            }
        }
    }
} catch (PDOException $e) {
    error_log("xodimlar jadvalini tuzatish xatosi: " . $e->getMessage());
}

try {
    ensureEmployeeClassificationSchema($pdo);
} catch (PDOException $e) {
    error_log("Employee classification schema error: " . $e->getMessage());
    throw $e;
}

// ============================================================
// YARATILGAN SANANI YANGILASH (AJAX)
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'update_created_at') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    try {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $yaratilganInput = $_POST['yaratilgan'] ?? '';
        $yaratilganDate = is_string($yaratilganInput)
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $yaratilganInput)
            : false;
        
        if ($employeeId <= 0) {
            throw new Exception('Xodim ID notogri!');
        }
        if (!$yaratilganDate || $yaratilganDate->format('Y-m-d H:i:s') !== $yaratilganInput) {
            throw new Exception('Sana yoki vaqt formati noto\'g\'ri!');
        }
        
        $account_name = $_SESSION['account'] ?? '';
        if (empty($account_name)) {
            throw new Exception('Account topilmadi!');
        }

        $ownerStmt = $pdo->prepare("SELECT id FROM xodimlar WHERE id = ? AND account_name = ?");
        $ownerStmt->execute([$employeeId, $account_name]);
        if (!$ownerStmt->fetchColumn()) {
            throw new Exception('Xodim topilmadi yoki bu amal uchun ruxsat yo\'q.');
        }

        $stmt = $pdo->prepare("UPDATE xodimlar SET yaratilgan = ? WHERE id = ? AND account_name = ?");
        $stmt->execute([$yaratilganDate->format('Y-m-d H:i:s'), $employeeId, $account_name]);
        
        if ($stmt->rowCount() > 0) {
            $response['success'] = true;
            $response['message'] = 'Yaratilgan sana yangilandi!';
        } else {
            $response['success'] = true;
            $response['message'] = 'Sana ozgarishsiz qoldi.';
        }
        
    } catch(Exception $e) {
        $response['message'] = $e->getMessage();
        error_log("update_created_at error: " . $e->getMessage());
    }
    
    echo json_encode($response);
    exit;
}

if (isset($_POST['classification_action'])) {
    $classificationAccount = $_SESSION['account'] ?? $_SESSION['account_name'] ?? '';
    $redirectSid = !empty($sid) ? '&sid=' . urlencode($sid) : '';
    $redirectMonth = $_GET['month'] ?? date('Y-m');
    $redirectPage = max(1, (int)($_GET['page'] ?? 1));

    try {
        if ($classificationAccount === '') {
            throw new Exception('Account topilmadi.');
        }

        $action = $_POST['classification_action'];
        if ($action === 'save_employee_classification') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                throw new Exception('Xodim ID noto\'g\'ri.');
            }

            $positionId = (int)($_POST['position_id'] ?? 0);
            if ($positionId < 0) {
                throw new Exception('Lavozim qiymati noto\'g\'ri.');
            }
            if ($positionId > 0) {
                $positionCheck = $pdo->prepare("SELECT id FROM employee_positions WHERE id = ? AND account_name = ?");
                $positionCheck->execute([$positionId, $classificationAccount]);
                if (!$positionCheck->fetchColumn()) {
                    throw new Exception('Tanlangan lavozim ushbu akkauntga tegishli emas.');
                }
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                UPDATE xodimlar
                SET institution_id = NULL, category_id = NULL, position_id = ?,
                    foiz_mustasno = NULL
                WHERE id = ? AND account_name = ?
            ");
            $stmt->execute([
                $positionId ?: null,
                $employeeId,
                $classificationAccount,
            ]);
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare("SELECT id FROM xodimlar WHERE id = ? AND account_name = ?");
                $check->execute([$employeeId, $classificationAccount]);
                if (!$check->fetchColumn()) {
                    throw new Exception('Xodim topilmadi yoki bu amal uchun ruxsat yo\'q.');
                }
            }
            $pdo->commit();

            header("Location: ?tab=employees&month=" . urlencode($redirectMonth) . "&page=" . $redirectPage . $redirectSid . "&classification_saved=1");
            exit;
        }

        throw new Exception('Noma\'lum tasnif amali.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Employee classification error: " . $e->getMessage());
        header("Location: ?tab=employees&month=" . urlencode($redirectMonth) . "&page=" . $redirectPage . $redirectSid . "&classification_error=1");
        exit;
    }
}

// ============================================================
$sync_message = (string)($_SESSION['employee_sync_message'] ?? '');
$sync_error = (string)($_SESSION['employee_sync_error'] ?? '');
unset($_SESSION['employee_sync_message'], $_SESSION['employee_sync_error']);

if (isset($_POST['sync_employees'])) {
    $account_name = trim((string)($_SESSION['account'] ?? $_SESSION['account_name'] ?? ''));
    $account_id = $_SESSION['account_id'] ?? '';
    $access_token = (string)($_SESSION['access_token'] ?? '');

    try {
        if ($account_name === '') {
            throw new RuntimeException('Poster akkaunti aniqlanmadi. Qayta kiring.');
        }
        if ($account_id === '') {
            $stmt = $pdo->prepare("SELECT qiymat FROM sozlamalar WHERE kalit = 'account_id' AND account_name = ?");
            $stmt->execute([$account_name]);
            $account_id = $stmt->fetchColumn() ?: '';
            if ($account_id !== '') {
                $_SESSION['account_id'] = $account_id;
            }
        }
        if ($access_token === '') {
            $stmt = $pdo->prepare("SELECT access_token FROM poster_tokens WHERE account_name = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$account_name]);
            $access_token = (string)($stmt->fetchColumn() ?: '');
        }
        if ($access_token === '') {
            throw new RuntimeException('Poster token topilmadi. Qayta kiring.');
        }

        $syncResult = syncPosterEmployeesToSalaryCategories($pdo, $account_name, $access_token, $account_id);
        $_SESSION['employee_sync_message'] = sprintf(
            'Sinxronlash yakunlandi. Yangi xodim: %d, yangilangan: %d, qayta faollashgan: %d, yangi ish haqi kategoriyasi: %d, nomi yangilangan kategoriya: %d, arxivga ko‘chirilgan kategoriya: %d, yangi lavozim: %d.',
            $syncResult['added'],
            $syncResult['updated'],
            $syncResult['reactivated'],
            $syncResult['categories_created'],
            $syncResult['categories_updated'],
            $syncResult['categories_archived'],
            $syncResult['positions_created']
        );
    } catch (Throwable $e) {
        error_log('Poster employee sync failed for account ' . $account_name . ': ' . $e->getMessage());
        $_SESSION['employee_sync_error'] = 'Sinxronlash xatosi: ' . $e->getMessage();
    }

    $redirectSid = !empty($sid) ? '&sid=' . urlencode($sid) : '';
    $month = $_GET['month'] ?? date('Y-m');
    $page = max(1, (int)($_GET['page'] ?? 1));
    header('Location: ?tab=employees&month=' . urlencode($month) . '&page=' . $page . $redirectSid);
    exit;
}

// ASOSIY O'ZGARUVCHILAR
// ============================================================
$dateFrom = $dateFrom ?? date('Y-m-01');
$dateTo = $dateTo ?? date('Y-m-d');
$token = $_SESSION['token'] ?? $_SESSION['access_token'] ?? '';
$accountId = $_SESSION['account_id'] ?? '';
$accountName = $_SESSION['account'] ?? '';
$currentLang = $currentLang ?? 'uz';
$currencySymbol = $_SESSION['currency_symbol'] ?? 'som';
$currencyCode = strtoupper($_SESSION['currency_code'] ?? 'UZS');

$itemsPerPage = 30;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if($currentPage < 1) $currentPage = 1;

$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$searchType = isset($_GET['search_type']) ? $_GET['search_type'] : 'all';

// ============================================================
// SOZLAMALARNI OLISH
// ============================================================
function getAccountSettings() {
    global $pdo, $accountName;
    $settings = [];
    try {
        $stmt = $pdo->prepare("SELECT kalit, qiymat FROM sozlamalar WHERE account_name = ?");
        $stmt->execute([$accountName]);
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['kalit']] = $row['qiymat'];
        }
    } catch(PDOException $e) {
        error_log("Get settings error: " . $e->getMessage());
    }
    return $settings;
}

$settings = getAccountSettings();
$defaultSalary = (int)($settings['standart_oylik'] ?? 7500000);
$workHoursPerDay = (float)($settings['kunlik_soat'] ?? 8);
$overtimeRate = (float)($settings['qoshimcha_koeff'] ?? 1.5);
$halfDayRate = (float)($settings['yarim_kun_koeff'] ?? 0.5);

// ============================================================
// DEFAULT SOZLAMALAR
// ============================================================
function getDefaultSettings() {
    return [
        'maosh_turi' => '["fixed"]',
        'maosh_turi_array' => ['fixed'],
        'oylik_maosh' => 0,
        'foiz_qiymati' => 0,
        'soatlik_stavka' => 0,
        'smena_summa' => 0,
        'poster_user_id' => null,
        'amal_qiladi' => date('Y-m-d'),
        'ishga_kelish_vaqti' => '09:00:00',
        'ishdan_ketish_vaqti' => '18:00:00',
        'tushlik_boshlanish' => '13:00:00',
        'tushlik_tugash' => '14:00:00',
        'kech_kelish_jarima_enabled' => 1,
        'kech_kelish_jarima_per_minute' => 1000,
        'kech_kelish_max_jarima' => 50000,
        'kech_kelish_grace_minutes' => 10,
        'erta_ketish_jarima_enabled' => 1,
        'erta_ketish_jarima_per_minute' => 1000,
        'erta_ketish_max_jarima' => 50000,
        'erta_ketish_grace_minutes' => 10,
        'erta_kelish_bonus_enabled' => 1,
        'erta_kelish_bonus_amount' => 1000,
        'erta_kelish_max_bonus' => 50000,
        'kech_ketish_bonus_enabled' => 1,
        'kech_ketish_bonus_amount' => 1000,
        'kech_ketish_max_bonus' => 50000
    ];
}

// ============================================================
// XODIMNING OYLIK SOZLAMALARINI OLISH
// ============================================================
function getEmployeeMonthlySettings($employeeId, $month, $date = null) {
    global $pdo;
    $oyVaqt = $month . '-01';
    
    if (!$date) {
        $date = date('Y-m-d');
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM oylik_sozlamalar 
            WHERE xodim_id = ? 
            AND oy_vaqt = ? 
            AND amal_qiladi <= ?
            ORDER BY amal_qiladi DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$employeeId, $oyVaqt, $date]);
        $activeSetting = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($activeSetting) {
            $maoshTurlari = json_decode($activeSetting['maosh_turi'], true);
            if (!is_array($maoshTurlari)) {
                $maoshTurlari = [$activeSetting['maosh_turi'] ?? 'fixed'];
            }
            $activeSetting['maosh_turi_array'] = $maoshTurlari;
            $scheduleOverride = getEmployeeWorkScheduleOverride($pdo, $employeeId, $date);
            if ($scheduleOverride && $scheduleOverride['amal_qiladi'] >= ($activeSetting['amal_qiladi'] ?? '')) {
                foreach (['ishga_kelish_vaqti', 'ishdan_ketish_vaqti', 'tushlik_boshlanish', 'tushlik_tugash'] as $field) {
                    $activeSetting[$field] = $scheduleOverride[$field];
                }
                $activeSetting['schedule_effective_date'] = $scheduleOverride['amal_qiladi'];
            }
            return $activeSetting;
        }

        $defaultSettings = getDefaultSettings();
        $scheduleOverride = getEmployeeWorkScheduleOverride($pdo, $employeeId, $date);
        if ($scheduleOverride) {
            foreach (['ishga_kelish_vaqti', 'ishdan_ketish_vaqti', 'tushlik_boshlanish', 'tushlik_tugash'] as $field) {
                $defaultSettings[$field] = $scheduleOverride[$field];
            }
            $defaultSettings['schedule_effective_date'] = $scheduleOverride['amal_qiladi'];
        }
        return $defaultSettings;
        
    } catch(Exception $e) {
        error_log("getEmployeeMonthlySettings error: " . $e->getMessage());
        return getDefaultSettings();
    }
}

// ============================================================
// XODIMNING BARCHA SOZLAMALARINI OLISH
// ============================================================
function getEmployeeAllSettings($employeeId, $month) {
    global $pdo;
    
    $oyVaqt = $month . '-01';
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM oylik_sozlamalar 
            WHERE xodim_id = ? AND oy_vaqt = ?
            ORDER BY amal_qiladi ASC, id ASC
        ");
        $stmt->execute([$employeeId, $oyVaqt]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach($results as &$row) {
            $maoshTurlari = json_decode($row['maosh_turi'], true);
            if (!is_array($maoshTurlari)) {
                $maoshTurlari = [$row['maosh_turi'] ?? 'fixed'];
            }
            $row['maosh_turi_array'] = $maoshTurlari;
        }
        
        return $results;
        
    } catch(Exception $e) {
        error_log("getEmployeeAllSettings error: " . $e->getMessage());
        return [];
    }
}

// ============================================================
// KUNLIK MAOSH SOZLAMASINI OLISH
// ============================================================
function getDailySalarySetting($employeeId, $date, $monthlySettings) {
    $daysInMonth = (int)date('t', strtotime($date));
    $maoshTurlari = json_decode($monthlySettings['maosh_turi'], true);
    if (!is_array($maoshTurlari)) {
        $maoshTurlari = [$monthlySettings['maosh_turi'] ?? 'fixed'];
    }
    
    $result = [
        'type' => $maoshTurlari[0] ?? 'fixed',
        'types' => $maoshTurlari,
        'value' => 0,
        'daily_rate' => 0,
        'hourly_rate' => 0,
        'poster_user_id' => null,
        'setting_date' => $monthlySettings['amal_qiladi'] ?? null
    ];
    
    $oylikMaosh = (float)($monthlySettings['oylik_maosh'] ?? 0);
    $soatlikStavka = (float)($monthlySettings['soatlik_stavka'] ?? 0);
    $shiftAmount = (float)($monthlySettings['smena_summa'] ?? 0);
    
    if (in_array('fixed', $maoshTurlari)) {
        $result['daily_rate'] = $daysInMonth > 0 ? $oylikMaosh / $daysInMonth : 0;
        $result['value'] = $oylikMaosh;
    }
    
    if (in_array('hourly', $maoshTurlari)) {
        $result['hourly_rate'] = $soatlikStavka;
        if ($result['value'] == 0) {
            $result['value'] = $soatlikStavka;
        }
    }
    
    if (in_array('percent', $maoshTurlari)) {
        $result['poster_user_id'] = $monthlySettings['poster_user_id'] ?? null;
        if ($result['value'] == 0) {
            $result['value'] = (float)($monthlySettings['foiz_qiymati'] ?? 0);
        }

    }

    if (in_array('shift', $maoshTurlari, true)) {
        $result['shift_amount'] = $shiftAmount;
        if ($result['value'] == 0) {
            $result['value'] = $shiftAmount;
        }
    }
    
    return $result;
}

// ============================================================
// KECH KELISH JARIMASI
// ============================================================
function calculateLateArrivalPenalty($checkIn, $workStartTime, $monthlySettings) {
    if (!$monthlySettings) return 0;
    
    $enabled = $monthlySettings['kech_kelish_jarima_enabled'] ?? 1;
    if(!$enabled || empty($checkIn) || empty($workStartTime)) return 0;
    
    $checkInTime = strtotime($checkIn);
    $workStart = strtotime($workStartTime);
    
    if($checkInTime <= $workStart) return 0;
    
    $lateMinutes = round(($checkInTime - $workStart) / 60);
    $graceMinutes = (int)($monthlySettings['kech_kelish_grace_minutes'] ?? 10);
    
    if($lateMinutes <= $graceMinutes) return 0;
    
    $penaltyMinutes = $lateMinutes - $graceMinutes;
    $perMinute = (float)($monthlySettings['kech_kelish_jarima_per_minute'] ?? 1000);
    $maxPenalty = (float)($monthlySettings['kech_kelish_max_jarima'] ?? 50000);
    
    $penalty = $penaltyMinutes * $perMinute;
    return min($penalty, $maxPenalty);
}

// ============================================================
// ERTA KETISH JARIMASI
// ============================================================
function calculateEarlyDeparturePenalty($checkOut, $workEndTime, $monthlySettings) {
    if (!$monthlySettings) return 0;
    
    $enabled = $monthlySettings['erta_ketish_jarima_enabled'] ?? 1;
    if(!$enabled || empty($checkOut) || empty($workEndTime)) return 0;
    
    $checkOutTime = strtotime($checkOut);
    $workEnd = strtotime($workEndTime);
    
    if($checkOutTime >= $workEnd) return 0;
    
    $earlyMinutes = round(($workEnd - $checkOutTime) / 60);
    $graceMinutes = (int)($monthlySettings['erta_ketish_grace_minutes'] ?? 10);
    
    if($earlyMinutes <= $graceMinutes) return 0;
    
    $penaltyMinutes = $earlyMinutes - $graceMinutes;
    $perMinute = (float)($monthlySettings['erta_ketish_jarima_per_minute'] ?? 1000);
    $maxPenalty = (float)($monthlySettings['erta_ketish_max_jarima'] ?? 50000);
    
    $penalty = $penaltyMinutes * $perMinute;
    return min($penalty, $maxPenalty);
}

// ============================================================
// ERTA KELISH BONUSI
// ============================================================
function calculateEarlyArrivalBonus($checkIn, $workStartTime, $monthlySettings) {
    if (!$monthlySettings) return 0;
    
    $enabled = $monthlySettings['erta_kelish_bonus_enabled'] ?? 1;
    if(!$enabled || empty($checkIn) || empty($workStartTime)) return 0;
    
    $checkInTime = strtotime($checkIn);
    $workStart = strtotime($workStartTime);
    
    if($checkInTime >= $workStart) return 0;
    
    $earlyMinutes = round(($workStart - $checkInTime) / 60);
    $perMinute = (float)($monthlySettings['erta_kelish_bonus_amount'] ?? 1000);
    $maxBonus = (float)($monthlySettings['erta_kelish_max_bonus'] ?? 50000);
    
    $bonus = $earlyMinutes * $perMinute;
    return min($bonus, $maxBonus);
}

// ============================================================
// KECH KETISH BONUSI
// ============================================================
function calculateLateDepartureBonus($checkOut, $workEndTime, $monthlySettings) {
    if (!$monthlySettings) return 0;
    
    $enabled = $monthlySettings['kech_ketish_bonus_enabled'] ?? 1;
    if(!$enabled || empty($checkOut) || empty($workEndTime)) return 0;
    
    $checkOutTime = strtotime($checkOut);
    $workEnd = strtotime($workEndTime);
    
    if($checkOutTime <= $workEnd) return 0;
    
    $lateMinutes = round(($checkOutTime - $workEnd) / 60);
    $perMinute = (float)($monthlySettings['kech_ketish_bonus_amount'] ?? 1000);
    $maxBonus = (float)($monthlySettings['kech_ketish_max_bonus'] ?? 50000);
    
    $bonus = $lateMinutes * $perMinute;
    return min($bonus, $maxBonus);
}

// ============================================================
// KUNLIK MAOSHNI HISOBLASH (MULTIPLE TYPES)
// ============================================================
function calculateDailySalary($employeeId, $date, $waitersData, $attendanceStatus, $dailySetting, 
                              $checkIn = null, $checkOut = null, $monthlySalary = 0, 
                              $monthlySettings = null, $workSchedule = null) {
    
    if ($monthlySettings === null) {
        $monthlySettings = getEmployeeMonthlySettings($employeeId, date('Y-m', strtotime($date)), $date);
    }
    
    $maoshTurlari = json_decode($monthlySettings['maosh_turi'], true);
    if (!is_array($maoshTurlari)) {
        $maoshTurlari = [$monthlySettings['maosh_turi'] ?? 'fixed'];
    }
    
    $dailyRate = $dailySetting['daily_rate'] ?? 0;
    $hourlyRate = $dailySetting['hourly_rate'] ?? 0;
    $shiftAmount = (float)($dailySetting['shift_amount'] ?? $monthlySettings['smena_summa'] ?? 0);
    $percentValue = (float)($monthlySettings['foiz_qiymati'] ?? 0);
    $posterUserId = $monthlySettings['poster_user_id'] ?? null;
    
    $standardWorkHours = 8;
    if($workSchedule && isset($workSchedule['start_time']) && isset($workSchedule['end_time'])) {
        $start = strtotime($workSchedule['start_time']);
        $end = strtotime($workSchedule['end_time']);
        $standardWorkHours = round(($end - $start) / 3600, 1);
        
        if(isset($workSchedule['lunch_start']) && isset($workSchedule['lunch_end'])) {
            $lunchStart = strtotime($workSchedule['lunch_start']);
            $lunchEnd = strtotime($workSchedule['lunch_end']);
            $lunchHours = round(($lunchEnd - $lunchStart) / 3600, 1);
            $standardWorkHours -= $lunchHours;
        }
    }
    
    $baseSalary = 0;
    
    foreach ($maoshTurlari as $type) {
        $typeSalary = 0;
        
        if ($type == 'fixed') {
            if(in_array($attendanceStatus, ['present', 'overtime', 'vacation', 'sick'])) {
                $typeSalary = $dailyRate;
            } elseif($attendanceStatus == 'half_day') {
                $typeSalary = $dailyRate / 2;
            }
        } elseif ($type == 'hourly') {
            if(in_array($attendanceStatus, ['present', 'overtime', 'vacation', 'sick'])) {
                $workedHours = $standardWorkHours;
                if($checkIn && $checkOut) {
                    $checkInTime = strtotime($checkIn);
                    $checkOutTime = strtotime($checkOut);
                    if($checkInTime && $checkOutTime && $checkOutTime > $checkInTime) {
                        $workedSeconds = $checkOutTime - $checkInTime;
                        $workedHours = round($workedSeconds / 3600, 1);
                    }
                }
                $typeSalary = round($hourlyRate * $workedHours);
            } elseif($attendanceStatus == 'half_day') {
                $typeSalary = round($hourlyRate * ($standardWorkHours / 2));
            }
        } elseif ($type == 'shift') {
            if (in_array($attendanceStatus, ['present', 'overtime'], true) && $checkIn && $checkOut) {
                $typeSalary = $shiftAmount;
            }
        } elseif ($type == 'percent') {
            if(in_array($attendanceStatus, ['present', 'half_day', 'overtime', 'vacation', 'sick'])
                && !empty($posterUserId)) {
                $dailyRevenue = 0;
                if(isset($waitersData[$posterUserId])) {
                    $waiterData = $waitersData[$posterUserId];
                    if(isset($waiterData['daily']) && is_array($waiterData['daily'])) {
                        foreach($waiterData['daily'] as $dayData) {
                            if($dayData['date'] == $date) {
                                $dailyRevenue = isset($dayData['revenue']) ? (float)$dayData['revenue'] : 0;
                                break;
                            }
                        }
                    }
                }
                if($dailyRevenue > 0) {
                    $typeSalary = round($dailyRevenue * ($percentValue / 100));
                }
            }
        }
        
        $baseSalary += $typeSalary;
    }
    
    $penaltyTotal = 0;
    $bonusTotal = 0;
    
    if($checkIn && $workSchedule && isset($workSchedule['start_time'])) {
        $penaltyTotal += calculateLateArrivalPenalty($checkIn, $workSchedule['start_time'], $monthlySettings);
        $bonusTotal += calculateEarlyArrivalBonus($checkIn, $workSchedule['start_time'], $monthlySettings);
    }
    
    if($checkOut && $workSchedule && isset($workSchedule['end_time'])) {
        $penaltyTotal += calculateEarlyDeparturePenalty($checkOut, $workSchedule['end_time'], $monthlySettings);
        $bonusTotal += calculateLateDepartureBonus($checkOut, $workSchedule['end_time'], $monthlySettings);
    }
    
    $result = $baseSalary - $penaltyTotal + $bonusTotal;
    return max(0, $result);
}

// ============================================================
// ATTENDANCE MA'LUMOTLARINI OLISH
// ============================================================
function getAllAttendanceFast($employeeIds, $month) {
    global $pdo;
    if(empty($employeeIds)) return [];
    
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $monthStart = $month . '-01';
    $monthEnd = $month . '-' . date('t', strtotime($monthStart));
    
    try {
        $stmt = $pdo->prepare("
            SELECT employee_id, auth_date, in_time, out_time, in_late_minutes, out_early_minutes
            FROM attendance_data 
            WHERE employee_id IN ({$placeholders}) 
            AND auth_date BETWEEN ? AND ?
            ORDER BY auth_date ASC
        ");
        
        $params = array_merge($employeeIds, [$monthStart, $monthEnd]);
        $stmt->execute($params);
        
        $result = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $empId = $row['employee_id'];
            $date = $row['auth_date'];
            $inTime = $row['in_time'];
            $outTime = $row['out_time'];
            $lateMinutes = (int)($row['in_late_minutes'] ?? 0);
            $earlyMinutes = (int)($row['out_early_minutes'] ?? 0);
            
            $status = 'absent';
            if($inTime && $outTime) {
                $status = 'present';
            } elseif($inTime) {
                $status = 'partial_in';
            } elseif($outTime) {
                $status = 'partial_out';
            }
            
            if(!isset($result[$empId][$date])) {
                $result[$empId][$date] = [
                    'status' => $status,
                    'check_in' => $inTime,
                    'check_out' => $outTime,
                    'late_minutes' => $lateMinutes,
                    'early_minutes' => $earlyMinutes
                ];
            } else {
                if($inTime) {
                    $result[$empId][$date]['check_in'] = $inTime;
                    $result[$empId][$date]['late_minutes'] = $lateMinutes;
                    $result[$empId][$date]['status'] = $result[$empId][$date]['status'] == 'partial_out' ? 'present' : $status;
                }
                if($outTime) {
                    $result[$empId][$date]['check_out'] = $outTime;
                    $result[$empId][$date]['early_minutes'] = $earlyMinutes;
                    $result[$empId][$date]['status'] = $result[$empId][$date]['status'] == 'partial_in' ? 'present' : $status;
                }
            }
        }
        
        return $result;
    } catch(Exception $e) {
        error_log("getAllAttendanceFast error: " . $e->getMessage());
        return [];
    }
}

// ============================================================
// WAITERS ANALYTICS
// ============================================================
function getWaitersAnalytics($selectedMonth) {
    global $token, $account_name;
    
    if(empty($token)) {
        $token = $_SESSION['access_token'] ?? '';
    }
    if(empty($token)) {
        error_log("Token topilmadi!");
        return [];
    }
    
    $year = substr($selectedMonth, 0, 4);
    $monthNum = substr($selectedMonth, 5, 2);
    $dateFrom = $year . $monthNum . '01';
    $dateTo = $year . $monthNum . date('t', strtotime($selectedMonth . '-01'));
    $domain = $_SESSION['account'] ?? $account_name ?? '';
    
    if(empty($domain)) return [];
    
    $url = "https://{$domain}.joinposter.com/api/dash.getWaitersSales?token=" . urlencode($token);
    $url .= "&dateFrom=" . $dateFrom;
    $url .= "&dateTo=" . $dateTo;
    if (function_exists('isBranchUser') && isBranchUser()) {
        $url .= "&spot_id=" . currentBranchSpotId();
    }
    
    $ch = curl_init();
    if ($ch === false) {
        error_log("Waiters API request could not be initialized.");
        return [];
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        error_log("Waiters API request failed: " . $curlError);
        return [];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("Waiters API returned HTTP {$httpCode}.");
        return [];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        error_log("Waiters API returned invalid JSON.");
        return [];
    }
    
    if(isset($data['error'])) {
        error_log("Waiters API Error: " . print_r($data['error'], true));
        return [];
    }
    
    $waitersStats = [];
    
    if(isset($data['response']) && is_array($data['response'])) {
        foreach($data['response'] as $waiter) {
            $userId = $waiter['user_id'] ?? 0;
            
            $monthlyRevenue = isset($waiter['revenue']) ? (float)$waiter['revenue'] : 0;
            $monthlyProfit = isset($waiter['profit']) ? (float)$waiter['profit'] : 0;
            
            if($monthlyRevenue > 10000) $monthlyRevenue = $monthlyRevenue / 100;
            if($monthlyProfit > 10000) $monthlyProfit = $monthlyProfit / 100;
            
            $dailyRevenues = [];
            if(isset($waiter['days']) && is_array($waiter['days'])) {
                foreach($waiter['days'] as $day) {
                    $date = $day['date'] ?? '';
                    $dayRevenue = isset($day['revenue']) ? (float)$day['revenue'] : 0;
                    $dayProfit = isset($day['profit']) ? (float)$day['profit'] : 0;
                    
                    if($dayRevenue > 10000) $dayRevenue = $dayRevenue / 100;
                    if($dayProfit > 10000) $dayProfit = $dayProfit / 100;
                    
                    $dailyRevenues[] = [
                        'date' => $date,
                        'revenue' => $dayRevenue,
                        'profit' => $dayProfit
                    ];
                }
            }
            
            $waitersStats[$userId] = [
                'user_id' => $userId,
                'name' => $waiter['name'] ?? 'Ofitsiant ' . $userId,
                'revenue' => $monthlyRevenue,
                'profit' => $monthlyProfit,
                'daily' => $dailyRevenues,
                'clients' => (int)($waiter['clients'] ?? 0)
            ];
        }
    }
    
    return $waitersStats;
}

// ============================================================
// SETTING O'CHIRISH
// ============================================================
if(isset($_POST['delete_setting']) && is_numeric($_POST['delete_setting'])) {
    $settingId = (int)$_POST['delete_setting'];
    try {
        $accountNameForAction = $_SESSION['account'] ?? $_SESSION['account_name'] ?? '';
        $stmt = $pdo->prepare("
            DELETE oylik_sozlamalar
            FROM oylik_sozlamalar
            INNER JOIN xodimlar ON xodimlar.id = oylik_sozlamalar.xodim_id
            WHERE oylik_sozlamalar.id = ? AND xodimlar.account_name = ?
        ");
        $stmt->execute([$settingId, $accountNameForAction]);
        if ($stmt->rowCount() === 0) {
            throw new Exception('Sozlama topilmadi yoki bu amal uchun ruxsat yo\'q.');
        }
        
        $redirectSid = !empty($sid) ? '&sid=' . urlencode($sid) : '';
        $month = $_GET['month'] ?? date('Y-m');
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        header("Location: ?tab=employees&month=" . urlencode($month) . "&page=" . $page . $redirectSid . "&deleted=1");
        exit;
    } catch(Exception $e) {
        error_log("Delete setting error: " . $e->getMessage());
        $redirectSid = !empty($sid) ? '&sid=' . urlencode($sid) : '';
        $month = $_GET['month'] ?? date('Y-m');
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        header("Location: ?tab=employees&month=" . urlencode($month) . "&page=" . $page . $redirectSid . "&error=1");
        exit;
    }
}

// ============================================================
// SETTING QO'SHISH (SAVE) - BIR NECHTA TURLAR BILAN
// ============================================================
if(isset($_POST['save_setting'])) {
    try {
        $id = (int)$_POST['employee_id'];
        $month = $_POST['month'];
        $startDate = $_POST['start_date'];
        $accountNameForAction = $_SESSION['account'] ?? $_SESSION['account_name'] ?? '';
        $ownerStmt = $pdo->prepare("SELECT id FROM xodimlar WHERE id = ? AND account_name = ?");
        $ownerStmt->execute([$id, $accountNameForAction]);
        if (!$ownerStmt->fetchColumn()) {
            throw new Exception('Xodim topilmadi yoki bu amal uchun ruxsat yo\'q.');
        }

        $salaryTypes = normalizeEmployeeSalaryTypes($_POST['salary_types'] ?? ['fixed']);
        $positionId = (int)($_POST['position_id'] ?? 0);
        $posterSpotId = (int)($_POST['poster_spot_id'] ?? 0);
        $posterCategoryIds = normalizeEmployeePosterCategoryIds($_POST['poster_category_ids'] ?? []);
        if ($positionId < 0 || $posterSpotId < 0) {
            throw new Exception('Lavozim yoki filial qiymati noto\'g\'ri.');
        }
        $positionSourceType = 'waiter';
        if ($positionId > 0) {
            $positionCheck = $pdo->prepare("SELECT commission_source_type FROM employee_positions WHERE id = ? AND account_name = ?");
            $positionCheck->execute([$positionId, $accountNameForAction]);
            $positionSourceType = $positionCheck->fetchColumn();
            if ($positionSourceType === false) {
                throw new Exception('Tanlangan lavozim ushbu akkauntga tegishli emas.');
            }
            if (in_array('percent', $salaryTypes, true) && $positionSourceType === 'category' && $posterCategoryIds === []) {
                throw new Exception('Kategoriya savdosi uchun kamida bitta kategoriyani tanlang.');
            }
            if (in_array('percent', $salaryTypes, true) && $positionSourceType === 'institution' && $posterSpotId <= 0) {
                throw new Exception('Spot savdosi uchun Poster filialini tanlang.');
            }
        }
        
        if (!isValidEmployeeDateInput($month, 'Y-m') || !isValidEmployeeDateInput($startDate, 'Y-m-d')) {
            throw new Exception('Oy yoki amal qilish sanasi noto\'g\'ri.');
        }
        
        $maoshTuriJson = json_encode($salaryTypes);
        
        $fixedSalary = (float)($_POST['fixed_salary'] ?? 0);
        $percentValue = (float)($_POST['percent_value'] ?? 0);
        $hourlyRate = (float)($_POST['hourly_rate'] ?? 0);
        $shiftAmount = filter_var($_POST['smena_summa'] ?? 0, FILTER_VALIDATE_FLOAT);
        if ($shiftAmount === false || $shiftAmount < 0) {
            throw new InvalidArgumentException('Smena uchun to‘lov miqdori noto‘g‘ri.');
        }
        $posterUserId = !empty($_POST['poster_user_id']) ? $_POST['poster_user_id'] : null;
        
        $ishgaKelishVaqti = $_POST['ishga_kelish_vaqti'] ?? '09:00:00';
        $ishdanKetishVaqti = $_POST['ishdan_ketish_vaqti'] ?? '18:00:00';
        $tushlikBoshlanish = $_POST['tushlik_boshlanish'] ?? '13:00:00';
        $tushlikTugash = $_POST['tushlik_tugash'] ?? '14:00:00';
        
        $kech_kelish_jarima_enabled = isset($_POST['kech_kelish_jarima_enabled']) ? 1 : 0;
        $kech_kelish_jarima_per_minute = (float)($_POST['kech_kelish_jarima_per_minute'] ?? 1000);
        $kech_kelish_max_jarima = (float)($_POST['kech_kelish_max_jarima'] ?? 50000);
        $kech_kelish_grace_minutes = (int)($_POST['kech_kelish_grace_minutes'] ?? 10);
        
        $erta_ketish_jarima_enabled = isset($_POST['erta_ketish_jarima_enabled']) ? 1 : 0;
        $erta_ketish_jarima_per_minute = (float)($_POST['erta_ketish_jarima_per_minute'] ?? 1000);
        $erta_ketish_max_jarima = (float)($_POST['erta_ketish_max_jarima'] ?? 50000);
        $erta_ketish_grace_minutes = (int)($_POST['erta_ketish_grace_minutes'] ?? 10);
        
        $erta_kelish_bonus_enabled = isset($_POST['erta_kelish_bonus_enabled']) ? 1 : 0;
        $erta_kelish_bonus_amount = (float)($_POST['erta_kelish_bonus_per_minute'] ?? 1000);
        $erta_kelish_max_bonus = (float)($_POST['erta_kelish_max_bonus'] ?? 50000);
        
        $kech_ketish_bonus_enabled = isset($_POST['kech_ketish_bonus_enabled']) ? 1 : 0;
        $kech_ketish_bonus_amount = (float)($_POST['kech_ketish_bonus_per_minute'] ?? 1000);
        $kech_ketish_max_bonus = (float)($_POST['kech_ketish_max_bonus'] ?? 50000);
        
        $oyVaqt = $month . '-01';

        $pdo->beginTransaction();
        $classificationUpdate = $pdo->prepare("
            UPDATE xodimlar
            SET institution_id = NULL, category_id = NULL, position_id = ?,
                poster_spot_id = CASE WHEN ? = 1 THEN ? ELSE poster_spot_id END,
                foiz_mustasno = NULL
            WHERE id = ? AND account_name = ?
        ");
        $classificationUpdate->execute([
            $positionId ?: null,
            in_array('percent', $salaryTypes, true) ? 1 : 0,
            in_array('percent', $salaryTypes, true) && $positionSourceType === 'institution' && $posterSpotId > 0 ? $posterSpotId : null,
            $id,
            $accountNameForAction,
        ]);
        if (in_array('percent', $salaryTypes, true) && $positionSourceType === 'category') {
            replaceEmployeePosterCategories($pdo, $id, $accountNameForAction, $posterCategoryIds);
        }
        
        $sql = "INSERT INTO oylik_sozlamalar 
            (xodim_id, oy_vaqt, maosh_turi, oylik_maosh, foiz_qiymati, soatlik_stavka, smena_summa, poster_user_id, amal_qiladi,
             ishga_kelish_vaqti, ishdan_ketish_vaqti, tushlik_boshlanish, tushlik_tugash,
             kech_kelish_jarima_enabled, kech_kelish_jarima_per_minute, kech_kelish_max_jarima, kech_kelish_grace_minutes,
             erta_ketish_jarima_enabled, erta_ketish_jarima_per_minute, erta_ketish_max_jarima, erta_ketish_grace_minutes,
             erta_kelish_bonus_enabled, erta_kelish_bonus_amount, erta_kelish_max_bonus,
             kech_ketish_bonus_enabled, kech_ketish_bonus_amount, kech_ketish_max_bonus) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $id, $oyVaqt, $maoshTuriJson, $fixedSalary, $percentValue, $hourlyRate, $shiftAmount, $posterUserId, $startDate,
            $ishgaKelishVaqti, $ishdanKetishVaqti, $tushlikBoshlanish, $tushlikTugash,
            $kech_kelish_jarima_enabled, $kech_kelish_jarima_per_minute, $kech_kelish_max_jarima, $kech_kelish_grace_minutes,
            $erta_ketish_jarima_enabled, $erta_ketish_jarima_per_minute, $erta_ketish_max_jarima, $erta_ketish_grace_minutes,
            $erta_kelish_bonus_enabled, $erta_kelish_bonus_amount, $erta_kelish_max_bonus,
            $kech_ketish_bonus_enabled, $kech_ketish_bonus_amount, $kech_ketish_max_bonus
        ]);
        $pdo->commit();

        refreshCarriedMonthlySettings($id, $month, $accountNameForAction);

        $redirectSid = !empty($sid) ? '&sid=' . urlencode($sid) : '';
        header("Location: ?tab=employees&month=" . urlencode($month) . "&saved=1&page=" . $currentPage . $redirectSid);
        exit;
        
    } catch(Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Save setting error: " . $e->getMessage());
        $redirectSid = !empty($sid) ? '&sid=' . urlencode($sid) : '';
        header("Location: ?tab=employees&month=" . urlencode($month) . "&error=1&page=" . $currentPage . $redirectSid);
        exit;
    }
}

// ============================================================
// SETTING YANGILASH (UPDATE) - BIR NECHTA TURLAR BILAN + ATTENDANCE QAYTA HISOBLASH
// ============================================================
if(isset($_POST['update_setting'])) {
    try {
        $settingId = (int)$_POST['setting_id'];
        $startDate = $_POST['start_date'];
        $accountNameForAction = $_SESSION['account'] ?? $_SESSION['account_name'] ?? '';
        $ownerStmt = $pdo->prepare("
            SELECT oylik_sozlamalar.xodim_id
            FROM oylik_sozlamalar
            INNER JOIN xodimlar ON xodimlar.id = oylik_sozlamalar.xodim_id
            WHERE oylik_sozlamalar.id = ? AND xodimlar.account_name = ?
        ");
        $ownerStmt->execute([$settingId, $accountNameForAction]);
        $xodimId = $ownerStmt->fetchColumn();
        if (!$xodimId) {
            throw new Exception('Sozlama topilmadi yoki bu amal uchun ruxsat yo\'q.');
        }
        
        $salaryTypes = normalizeEmployeeSalaryTypes($_POST['salary_types'] ?? ['fixed']);
        if (!isValidEmployeeDateInput($startDate, 'Y-m-d')) {
            throw new Exception('Amal qilish sanasi noto\'g\'ri.');
        }
        
        $maoshTuriJson = json_encode($salaryTypes);
        
        $oylikMaosh = (float)($_POST['fixed_salary'] ?? 0);
        $foizQiymati = (float)($_POST['percent_value'] ?? 0);
        $soatlikStavka = (float)($_POST['hourly_rate'] ?? 0);
        $shiftAmount = filter_var($_POST['smena_summa'] ?? 0, FILTER_VALIDATE_FLOAT);
        if ($shiftAmount === false || $shiftAmount < 0) {
            throw new InvalidArgumentException('Smena uchun to‘lov miqdori noto‘g‘ri.');
        }
        $posterUserId = !empty($_POST['poster_user_id']) ? (int)$_POST['poster_user_id'] : null;
        
        $ishgaKelishVaqti = $_POST['ishga_kelish_vaqti'] ?? '09:00:00';
        $ishdanKetishVaqti = $_POST['ishdan_ketish_vaqti'] ?? '18:00:00';
        $tushlikBoshlanish = $_POST['tushlik_boshlanish'] ?? '13:00:00';
        $tushlikTugash = $_POST['tushlik_tugash'] ?? '14:00:00';
        
        $kech_kelish_jarima_enabled = isset($_POST['kech_kelish_jarima_enabled']) ? 1 : 0;
        $kech_kelish_jarima_per_minute = (float)($_POST['kech_kelish_jarima_per_minute'] ?? 1000);
        $kech_kelish_max_jarima = (float)($_POST['kech_kelish_max_jarima'] ?? 50000);
        $kech_kelish_grace_minutes = (int)($_POST['kech_kelish_grace_minutes'] ?? 10);
        
        $erta_ketish_jarima_enabled = isset($_POST['erta_ketish_jarima_enabled']) ? 1 : 0;
        $erta_ketish_jarima_per_minute = (float)($_POST['erta_ketish_jarima_per_minute'] ?? 1000);
        $erta_ketish_max_jarima = (float)($_POST['erta_ketish_max_jarima'] ?? 50000);
        $erta_ketish_grace_minutes = (int)($_POST['erta_ketish_grace_minutes'] ?? 10);
        
        $erta_kelish_bonus_enabled = isset($_POST['erta_kelish_bonus_enabled']) ? 1 : 0;
        $erta_kelish_bonus_amount = (float)($_POST['erta_kelish_bonus_per_minute'] ?? 1000);
        $erta_kelish_max_bonus = (float)($_POST['erta_kelish_max_bonus'] ?? 50000);
        
        $kech_ketish_bonus_enabled = isset($_POST['kech_ketish_bonus_enabled']) ? 1 : 0;
        $kech_ketish_bonus_amount = (float)($_POST['kech_ketish_bonus_per_minute'] ?? 1000);
        $kech_ketish_max_bonus = (float)($_POST['kech_ketish_max_bonus'] ?? 50000);
        
        $sql = "UPDATE oylik_sozlamalar SET 
            amal_qiladi = ?,
            maosh_turi = ?,
            oylik_maosh = ?,
            foiz_qiymati = ?,
            soatlik_stavka = ?,
            smena_summa = ?,
            poster_user_id = ?,
            ishga_kelish_vaqti = ?,
            ishdan_ketish_vaqti = ?,
            tushlik_boshlanish = ?,
            tushlik_tugash = ?,
            erta_kelish_bonus_enabled = ?,
            erta_kelish_bonus_amount = ?,
            erta_kelish_max_bonus = ?,
            kech_ketish_bonus_enabled = ?,
            kech_ketish_bonus_amount = ?,
            kech_ketish_max_bonus = ?,
            kech_kelish_jarima_enabled = ?,
            kech_kelish_jarima_per_minute = ?,
            kech_kelish_max_jarima = ?,
            kech_kelish_grace_minutes = ?,
            erta_ketish_jarima_enabled = ?,
            erta_ketish_jarima_per_minute = ?,
            erta_ketish_max_jarima = ?,
            erta_ketish_grace_minutes = ?
            WHERE id = ? AND EXISTS (
                SELECT 1 FROM xodimlar
                WHERE xodimlar.id = oylik_sozlamalar.xodim_id
                  AND xodimlar.account_name = ?
            )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $startDate,
            $maoshTuriJson,
            $oylikMaosh,
            $foizQiymati,
            $soatlikStavka,
            $shiftAmount,
            $posterUserId,
            $ishgaKelishVaqti,
            $ishdanKetishVaqti,
            $tushlikBoshlanish,
            $tushlikTugash,
            $erta_kelish_bonus_enabled,
            $erta_kelish_bonus_amount,
            $erta_kelish_max_bonus,
            $kech_ketish_bonus_enabled,
            $kech_ketish_bonus_amount,
            $kech_ketish_max_bonus,
            $kech_kelish_jarima_enabled,
            $kech_kelish_jarima_per_minute,
            $kech_kelish_max_jarima,
            $kech_kelish_grace_minutes,
            $erta_ketish_jarima_enabled,
            $erta_ketish_jarima_per_minute,
            $erta_ketish_max_jarima,
            $erta_ketish_grace_minutes,
            $settingId,
            $accountNameForAction
        ]);
        
        if ($xodimId) {
            recalculateAttendanceForEmployee($xodimId, $startDate);
            $rowStmt = $pdo->prepare("UPDATE oylik_sozlamalar SET auto_copied = 0 WHERE id = ?");
            $rowStmt->execute([$settingId]);
            $monthStmt = $pdo->prepare("SELECT DATE_FORMAT(oy_vaqt, '%Y-%m') FROM oylik_sozlamalar WHERE id = ?");
            $monthStmt->execute([$settingId]);
            $editedMonth = $monthStmt->fetchColumn();
            if ($editedMonth) {
                refreshCarriedMonthlySettings($xodimId, $editedMonth, $accountNameForAction);
            }
        }
        
        $redirectSid = !empty($sid) ? '&sid=' . urlencode($sid) : '';
        $month = $_GET['month'] ?? date('Y-m');
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        header("Location: ?tab=employees&month=" . urlencode($month) . "&page=" . $page . $redirectSid . "&updated=1");
        exit;
        
    } catch(Exception $e) {
        error_log("Settings update error: " . $e->getMessage());
        $redirectSid = !empty($sid) ? '&sid=' . urlencode($sid) : '';
        $month = $_GET['month'] ?? date('Y-m');
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        header("Location: ?tab=employees&month=" . urlencode($month) . "&page=" . $page . $redirectSid . "&error=1");
        exit;
    }
}

// ============================================================
// ATTENDANCE MA'LUMOTLARINI QAYTA HISOBLASH FUNKSIYASI
// ============================================================
function recalculateAttendanceForEmployee($employeeId, $fromDate, $toDate = null) {
    global $pdo, $accountName;
    if (empty($accountName)) {
        $accountName = $_SESSION['account'] ?? $_SESSION['account_name'] ?? '';
    }

    if ($accountName === '') {
        throw new RuntimeException('Hisob aniqlanmagani uchun davomatni qayta hisoblab bo\'lmadi.');
    }
    
    if ($toDate === null) {
        $toDate = date('Y-m-d');
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, auth_date, in_time, out_time 
            FROM attendance_data 
            WHERE employee_id = ? AND auth_date BETWEEN ? AND ? AND account_name = ?
        ");
        $stmt->execute([$employeeId, $fromDate, $toDate, $accountName]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($records)) {
            return ['updated' => 0, 'message' => 'Yozuvlar topilmadi'];
        }
        
        $updateStmt = $pdo->prepare("
            UPDATE attendance_data 
            SET 
                in_late_minutes = ?, 
                out_early_minutes = ?, 
                total_work_hours = ?, 
                updated_at = NOW()
            WHERE id = ? AND account_name = ?
        ");
        
        $updatedCount = 0;
        
        foreach ($records as $record) {
            try {
                $date = $record['auth_date'];
                $month = date('Y-m', strtotime($date));
                
                $daySettings = getEmployeeMonthlySettings($employeeId, $month, $date);
                
                $workStartTime = $daySettings['ishga_kelish_vaqti'] ?? '09:00:00';
                $workEndTime = $daySettings['ishdan_ketish_vaqti'] ?? '18:00:00';
                $lunchStart = $daySettings['tushlik_boshlanish'] ?? '13:00:00';
                $lunchEnd = $daySettings['tushlik_tugash'] ?? '14:00:00';
                
                $lateGraceMinutes = (int)($daySettings['kech_kelish_grace_minutes'] ?? 10);
                $earlyGraceMinutes = (int)($daySettings['erta_ketish_grace_minutes'] ?? 10);
                
                $lateMinutes = 0;
                $earlyMinutes = 0;
                $totalWorkHours = 0;
                
                if (!empty($record['in_time'])) {
                    $checkInTime = strtotime($record['in_time']);
                    $workStart = strtotime($workStartTime);
                    if ($checkInTime && $workStart && $checkInTime > $workStart) {
                        $lateMinutes = round(($checkInTime - $workStart) / 60);
                        $lateMinutes = max(0, $lateMinutes - $lateGraceMinutes);
                    }
                }
                
                if (!empty($record['out_time'])) {
                    $checkOutTime = strtotime($record['out_time']);
                    $workEnd = strtotime($workEndTime);
                    if ($checkOutTime && $workEnd && $checkOutTime < $workEnd) {
                        $earlyMinutes = round(($workEnd - $checkOutTime) / 60);
                        $earlyMinutes = max(0, $earlyMinutes - $earlyGraceMinutes);
                    }
                }
                
                if (!empty($record['in_time']) && !empty($record['out_time'])) {
                    $checkInTime = strtotime($record['in_time']);
                    $checkOutTime = strtotime($record['out_time']);
                    
                    if ($checkInTime && $checkOutTime && $checkOutTime > $checkInTime) {
                        $totalSeconds = $checkOutTime - $checkInTime;
                        
                        $lunchStartStamp = strtotime($lunchStart);
                        $lunchEndStamp = strtotime($lunchEnd);
                        
                        if ($lunchStartStamp && $lunchEndStamp && $lunchEndStamp > $lunchStartStamp) {
                            $workStartStamp = strtotime($workStartTime);
                            $workEndStamp = strtotime($workEndTime);
                            
                            if ($lunchStartStamp >= $workStartStamp && $lunchEndStamp <= $workEndStamp) {
                                $lunchStartCheck = max($checkInTime, $lunchStartStamp);
                                $lunchEndCheck = min($checkOutTime, $lunchEndStamp);
                                
                                if ($lunchEndCheck > $lunchStartCheck) {
                                    $totalSeconds -= ($lunchEndCheck - $lunchStartCheck);
                                }
                            }
                        }
                        
                        $totalWorkHours = round($totalSeconds / 3600, 1);
                    }
                }
                
                $updateStmt->execute([
                    $lateMinutes,
                    $earlyMinutes,
                    $totalWorkHours,
                    $record['id'],
                    $accountName
                ]);
                
                $updatedCount++;
                
            } catch (Exception $e) {
                error_log("Record update error: " . $e->getMessage());
            }
        }
        
        return ['updated' => $updatedCount, 'message' => "$updatedCount ta yozuv qayta hisoblandi"];
        
    } catch (Exception $e) {
        error_log("recalculateAttendanceForEmployee error: " . $e->getMessage());
        return ['updated' => 0, 'message' => 'Xatolik: ' . $e->getMessage()];
    }
}

// ============================================================
// XODIMLARNI OLISH - account_id BILAN
// ============================================================
$positions = getEmployeeClassificationOptions($pdo, 'employee_positions', $accountName);
$employeePosterCategoryIds = getEmployeePosterCategoryAssignments($pdo, $accountName);
$posterSpots = [];
$posterCategories = [];
$posterCatalogResponse = posterApi('spots.getSpots', [], $accountName, $token);
if (isset($posterCatalogResponse['error'])) {
    error_log('Employee Poster spot catalog request failed: ' . json_encode($posterCatalogResponse['error']));
} elseif (is_array($posterCatalogResponse['response'] ?? null)) {
    $posterSpots = $posterCatalogResponse['response'];
}
if (isBranchUser()) {
    $branchSpotId = currentBranchSpotId();
    $posterSpots = array_values(array_filter($posterSpots, static function ($spot) use ($branchSpotId) {
        return (int)($spot['spot_id'] ?? 0) === $branchSpotId;
    }));
}
$posterCategoryResponse = posterApi('menu.getCategories', [], $accountName, $token);
if (isset($posterCategoryResponse['error'])) {
    error_log('Employee Poster category catalog request failed: ' . json_encode($posterCategoryResponse['error']));
} elseif (is_array($posterCategoryResponse['response'] ?? null)) {
    $posterCategories = flattenPosterCategories($posterCategoryResponse['response']);
}
$posterSpotNames = [];
foreach ($posterSpots as $spot) {
    $spotId = (int)($spot['spot_id'] ?? 0);
    if ($spotId > 0) {
        $posterSpotNames[$spotId] = (string)($spot['name'] ?? $spot['spot_name'] ?? ('Spot ' . $spotId));
    }
}
$posterCategoryNames = [];
foreach ($posterCategories as $category) {
    $categoryId = (int)($category['category_id'] ?? 0);
    if ($categoryId > 0) {
        $posterCategoryNames[$categoryId] = (string)($category['category_name'] ?? ('Kategoriya ' . $categoryId));
    }
}
$positionSalesSourceLabels = [];
foreach ($positions as $position) {
    $sourceType = $position['commission_source_type'] ?? 'waiter';
    $sourceLabel = t('waiter_sales');
    if ($sourceType === 'institution') {
        $sourceLabel = t('spot_sales_employee');
    } elseif ($sourceType === 'category') {
        $sourceLabel = t('category_sales_employee');
    }
    $positionSalesSourceLabels[(int)$position['id']] = $sourceLabel;
}
$spotFilter = isBranchUser() ? 0 : max(0, (int)($_GET['poster_spot_id'] ?? 0));
$positionFilter = isBranchUser() ? 0 : max(0, (int)($_GET['position_id'] ?? 0));

if(empty($accountName)) {
    echo '<div class="alert error"> Iltimos, tizimga qayta kiring. Account topilmadi.</div>';
    $employees = [];
    $totalEmployees = 0;
    $totalPages = 1;
} else {
    try {
        $account_id = $_SESSION['account_id'] ?? '';
        
        if (!empty($account_id)) {
            $whereSql = "WHERE e.holati = 'active' AND e.account_name = ? AND e.account_id = ?";
            $params = [$accountName, $account_id];
            $countParams = [$accountName, $account_id];
        } else {
            $whereSql = "WHERE e.holati = 'active' AND e.account_name = ?";
            $params = [$accountName];
            $countParams = [$accountName];
        }
        if (isBranchUser()) {
            $whereSql .= " AND e.branch_spot_id = ?";
            $params[] = currentBranchSpotId();
            $countParams[] = currentBranchSpotId();
        }
        
        if(!empty($searchQuery)) {
            $searchTerm = '%' . $searchQuery . '%';
            $whereSql .= " AND e.ismi LIKE ?";
            $params[] = $searchTerm;
            $countParams[] = $searchTerm;
        }

        foreach ([
            ['field' => 'poster_spot_id', 'value' => $spotFilter],
            ['field' => 'position_id', 'value' => $positionFilter],
        ] as $filter) {
            if ($filter['value'] > 0) {
                $whereSql .= $filter['field'] === 'poster_spot_id'
                    ? " AND (e.poster_spot_id = ? OR e.branch_spot_id = ?)"
                    : " AND e.{$filter['field']} = ?";
                $params[] = $filter['value'];
                $countParams[] = $filter['value'];
                if ($filter['field'] === 'poster_spot_id') {
                    $params[] = $filter['value'];
                    $countParams[] = $filter['value'];
                }
            }
        }
        
        $joins = "
            FROM xodimlar e
            LEFT JOIN employee_positions p ON p.id = e.position_id
                AND p.account_name COLLATE utf8mb4_unicode_ci = e.account_name COLLATE utf8mb4_unicode_ci
        ";
        $countSql = "SELECT COUNT(*) " . $joins . " " . $whereSql;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $totalEmployees = $countStmt->fetchColumn();
        $totalPages = ceil($totalEmployees / $itemsPerPage);
        $offset = ($currentPage - 1) * $itemsPerPage;
        
        $sql = "SELECT e.*, p.name AS position_name,
                       p.commission_source_type AS position_commission_source_type
                " . $joins . " " . $whereSql . " ORDER BY e.ismi LIMIT ? OFFSET ?";
        $params[] = $itemsPerPage;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll();
        
    } catch(PDOException $e) {
        echo '<div class="alert error">Database xatolik: ' . $e->getMessage() . '</div>';
        $employees = [];
        $totalEmployees = 0;
        $totalPages = 1;
    }
}

// ============================================================
// MA'LUMOTLARNI YIG'ISH (qolgan kod o'zgarmaydi)
// ============================================================
$currentMonth = date('Y-m');
$selectedMonthInput = $_GET['month'] ?? $currentMonth;
if (preg_match('/^\d{4}-\d{2}$/', (string)$selectedMonthInput) && $accountName !== '') {
    autoUpdateMonthlySettings($accountName, $selectedMonthInput);
}
$selectedMonth = isValidEmployeeDateInput($selectedMonthInput, 'Y-m')
    ? $selectedMonthInput
    : $currentMonth;
$saved = isset($_GET['saved']) ? 1 : 0;
$deleted = isset($_GET['deleted']) ? 1 : 0;
$updated = isset($_GET['updated']) ? 1 : 0;
$classificationSaved = isset($_GET['classification_saved']) ? 1 : 0;
$classificationError = isset($_GET['classification_error']) ? 1 : 0;

$months = [];
for($i = 12; $i >= 0; $i--) $months[] = date('Y-m', strtotime("-$i months"));
for($i = 1; $i <= 12; $i++) $months[] = date('Y-m', strtotime("+$i months"));
$months = array_unique($months);
sort($months);

$monthNames = ['01' => t('january'), '02' => t('february'), '03' => t('march'), '04' => t('april'), '05' => t('may'), '06' => t('june'), '07' => t('july'), '08' => t('august'), '09' => t('september'), '10' => t('october'), '11' => t('november'), '12' => t('december')];

$waitersAnalytics = getWaitersAnalytics($selectedMonth);
$posterGroupSales = getPosterGroupSalesAnalytics(
    $selectedMonth,
    (string)($_SESSION['account'] ?? $accountName),
    (string)($_SESSION['access_token'] ?? $token),
    currentBranchSpotId()
);
if (isset($posterGroupSales['error'])) {
    $posterGroupSalesError = $posterGroupSales['error'];
    $posterGroupSales = ['spots' => [], 'categories' => []];
} else {
    $posterGroupSalesError = '';
}
$employeeIds = array_column($employees, 'id');

$allSettingsHistory = [];
$monthlySettingsCache = [];

foreach($employeeIds as $id) { 
    $allSettings = getEmployeeAllSettings($id, $selectedMonth);
    $allSettingsHistory[$id] = $allSettings;
    
    $monthlySettingsCache[$id] = [];
    $daysInMonth = (int)date('t', strtotime($selectedMonth . '-01'));
    for($day = 1; $day <= $daysInMonth; $day++) {
        $date = $selectedMonth . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
        $monthlySettingsCache[$id][$date] = getEmployeeMonthlySettings($id, $selectedMonth, $date);
    }
}

$allAttendance = getAllAttendanceFast($employeeIds, $selectedMonth);

// ============================================================
// XODIM MA'LUMOTLARINI YIG'ISH (qolgan kod o'zgarmaydi)
// ============================================================
$totalCalculated = 0;
$pageTotalSalary = 0;
$daysInMonth = (int)date('t', strtotime($selectedMonth . '-01'));

foreach($employees as $key => $emp) {
    $empId = $emp['id'];
    $employees[$key]['institution_name'] = $posterSpotNames[(int)($emp['poster_spot_id'] ?? 0)] ?? null;
    $assignedCategoryIds = $employeePosterCategoryIds[(int)$empId] ?? [];
    $employees[$key]['poster_category_ids'] = $assignedCategoryIds;
    $assignedCategoryNames = array_values(array_filter(array_map(
        static fn($categoryId) => $posterCategoryNames[$categoryId] ?? null,
        $assignedCategoryIds
    )));
    $employees[$key]['employee_poster_category_names'] = $assignedCategoryNames;
        
    $today = date('Y-m-d');
    $currentSettings = getEmployeeMonthlySettings($empId, $selectedMonth, $today);
    $commissionSourceType = $emp['position_commission_source_type'] ?? 'waiter';
    $commissionSourceId = match ($commissionSourceType) {
        'institution' => (int)($emp['poster_spot_id'] ?? 0),
        'category' => $assignedCategoryIds,
        default => 0,
    };
    $maoshTurlari = json_decode($currentSettings['maosh_turi'], true);
    if (!is_array($maoshTurlari)) {
        $maoshTurlari = [$currentSettings['maosh_turi'] ?? 'fixed'];
    }
    
    $primaryType = $maoshTurlari[0] ?? 'fixed';
    
    $typeTexts = [];
    foreach($maoshTurlari as $type) {
        if($type == 'fixed') $typeTexts[] = t('fixed');
        elseif($type == 'hourly') $typeTexts[] = t('hourly');
        elseif($type == 'percent') $typeTexts[] = t('percent');
        elseif($type == 'shift') $typeTexts[] = t('shift');
    }
    $badgeText = implode(' + ', $typeTexts);
    
    $displayParts = [];
    foreach($maoshTurlari as $type) {
        if($type == 'fixed') {
            $displayParts[] = number_format($currentSettings['oylik_maosh'] ?? 0) . ' ' . $currencySymbol . '/' . t('month');
        } elseif($type == 'shift') {
            $displayParts[] = number_format($currentSettings['smena_summa'] ?? 0) . ' ' . $currencySymbol . '/' . t('shift');
        } elseif($type == 'hourly') {
            $displayParts[] = number_format($currentSettings['soatlik_stavka'] ?? 0) . ' ' . $currencySymbol . '/' . t('hour');
        } elseif($type == 'percent') {
            $displayParts[] = number_format($currentSettings['foiz_qiymati'] ?? 0, 1) . '%';
        }
    }
    $salaryDisplay = implode(' + ', $displayParts);
    
    $workSchedule = [
        'start_time' => $currentSettings['ishga_kelish_vaqti'] ?? '09:00:00',
        'end_time' => $currentSettings['ishdan_ketish_vaqti'] ?? '18:00:00',
        'lunch_start' => $currentSettings['tushlik_boshlanish'] ?? '13:00:00',
        'lunch_end' => $currentSettings['tushlik_tugash'] ?? '14:00:00'
    ];
    
    $empAttendance = $allAttendance[$empId] ?? [];
    
    $totalSalary = 0;
    $totalWorkedHours = 0;
    $attended = 0;
    $dailySalaries = [];
    
    $totalLateArrivalPenalty = 0;
    $totalEarlyDeparturePenalty = 0;
    $totalEarlyArrivalBonus = 0;
    $totalLateDepartureBonus = 0;
    
    $expectedWorkHours = 8;
    if($workSchedule['start_time'] && $workSchedule['end_time']) {
        $start = strtotime($workSchedule['start_time']);
        $end = strtotime($workSchedule['end_time']);
        $expectedWorkHours = round(($end - $start) / 3600, 1);
        if($workSchedule['lunch_start'] && $workSchedule['lunch_end']) {
            $lunchStart = strtotime($workSchedule['lunch_start']);
            $lunchEnd = strtotime($workSchedule['lunch_end']);
            $expectedWorkHours -= round(($lunchEnd - $lunchStart) / 3600, 1);
        }
    }
    
    for($day = 1; $day <= $daysInMonth; $day++) {
        $date = $selectedMonth . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
        
        $daySettings = $monthlySettingsCache[$empId][$date] ?? $currentSettings;
        
        $attData = $empAttendance[$date] ?? ['status' => 'absent', 'check_in' => null, 'check_out' => null, 'late_minutes' => 0, 'early_minutes' => 0];
        $attendanceStatus = $attData['status'];
        $checkIn = $attData['check_in'];
        $checkOut = $attData['check_out'];
        $lateMinutes = $attData['late_minutes'] ?? 0;
        $earlyMinutes = $attData['early_minutes'] ?? 0;
        
        $dailySetting = getDailySalarySetting($empId, $date, $daySettings);
        
        $dayWorkSchedule = [
            'start_time' => $daySettings['ishga_kelish_vaqti'] ?? '09:00:00',
            'end_time' => $daySettings['ishdan_ketish_vaqti'] ?? '18:00:00',
            'lunch_start' => $daySettings['tushlik_boshlanish'] ?? '13:00:00',
            'lunch_end' => $daySettings['tushlik_tugash'] ?? '14:00:00'
        ];
        
        if(in_array($attendanceStatus, ['present', 'overtime', 'vacation', 'sick'])) {
            $attended++;
            if(in_array('fixed', $maoshTurlari) || in_array('hourly', $maoshTurlari)) {
                if($checkIn && $checkOut) {
                    $checkInTime = strtotime($checkIn);
                    $checkOutTime = strtotime($checkOut);
                    if($checkInTime && $checkOutTime && $checkOutTime > $checkInTime) {
                        $workedSeconds = $checkOutTime - $checkInTime;
                        $totalWorkedHours += round($workedSeconds / 3600, 1);
                    } else { 
                        $totalWorkedHours += $expectedWorkHours; 
                    }
                } else { 
                    $totalWorkedHours += $expectedWorkHours; 
                }
            }
        } elseif($attendanceStatus == 'half_day') {
            $attended += 0.5;
            if(in_array('fixed', $maoshTurlari) || in_array('hourly', $maoshTurlari)) { 
                $totalWorkedHours += $expectedWorkHours / 2; 
            }
        }
        
        $dailyCalculationSettings = $daySettings;
        if (in_array($commissionSourceType, ['institution', 'category'], true)) {
            $dailyTypes = json_decode($daySettings['maosh_turi'] ?? '', true);
            if (!is_array($dailyTypes)) {
                $dailyTypes = [$daySettings['maosh_turi'] ?? 'fixed'];
            }
            $dailyTypes = array_values(array_filter(
                $dailyTypes,
                static fn($type) => $type !== 'percent'
            ));
            $dailyCalculationSettings['maosh_turi'] = json_encode($dailyTypes);
        }
        $daySalary = calculateDailySalary($empId, $date, $waitersAnalytics, $attendanceStatus, $dailySetting, 
                                           $checkIn, $checkOut, 0, 
                                           $dailyCalculationSettings, $dayWorkSchedule);
        
        if($checkIn && $dayWorkSchedule['start_time']) {
            $totalLateArrivalPenalty += calculateLateArrivalPenalty($checkIn, $dayWorkSchedule['start_time'], $daySettings);
            $totalEarlyArrivalBonus += calculateEarlyArrivalBonus($checkIn, $dayWorkSchedule['start_time'], $daySettings);
        }
        if($checkOut && $dayWorkSchedule['end_time']) {
            $totalEarlyDeparturePenalty += calculateEarlyDeparturePenalty($checkOut, $dayWorkSchedule['end_time'], $daySettings);
            $totalLateDepartureBonus += calculateLateDepartureBonus($checkOut, $dayWorkSchedule['end_time'], $daySettings);
        }
        
        $totalSalary += $daySalary;
        
        $dailySalaries[$date] = [
            'amount' => round($daySalary),
            'setting_date' => $dailySetting['setting_date'] ?? 'asosiy',
            'maosh_turi' => $daySettings['maosh_turi'] ?? 'fixed',
            'late_arrival_penalty' => calculateLateArrivalPenalty($checkIn, $dayWorkSchedule['start_time'], $daySettings),
            'early_departure_penalty' => calculateEarlyDeparturePenalty($checkOut, $dayWorkSchedule['end_time'], $daySettings),
            'early_arrival_bonus' => calculateEarlyArrivalBonus($checkIn, $dayWorkSchedule['start_time'], $daySettings),
            'late_departure_bonus' => calculateLateDepartureBonus($checkOut, $dayWorkSchedule['end_time'], $daySettings)
        ];
    }

    $groupSalesCommission = 0;
    if (in_array('percent', $maoshTurlari, true) && in_array($commissionSourceType, ['institution', 'category'], true)) {
        $groupSalesCommission = calculateMonthlySalesCommission(
            $commissionSourceType,
            $currentSettings['foiz_qiymati'] ?? 0,
            $commissionSourceId,
            null,
            $posterGroupSales,
            $waitersAnalytics
        );
        $totalSalary += $groupSalesCommission;
    }
    
    $lastDaySalary = 0;
    $lastDate = null;
    $lastAttendanceStatus = 'absent';
    $lastCheckIn = null;
    $lastCheckOut = null;
    
    if(!empty($empAttendance)) {
        $attendanceDates = array_keys($empAttendance);
        rsort($attendanceDates);
        
        foreach($attendanceDates as $date) {
            $attData = $empAttendance[$date] ?? ['status' => 'absent', 'check_in' => null, 'check_out' => null];
            if($attData['status'] != 'absent') {
                $lastDate = $date;
                $lastAttendanceStatus = $attData['status'];
                $lastCheckIn = $attData['check_in'];
                $lastCheckOut = $attData['check_out'];
                
                $daySettings = $monthlySettingsCache[$empId][$date] ?? $currentSettings;
                $dailySetting = getDailySalarySetting($empId, $date, $daySettings);
                $dayWorkSchedule = [
                    'start_time' => $daySettings['ishga_kelish_vaqti'] ?? '09:00:00',
                    'end_time' => $daySettings['ishdan_ketish_vaqti'] ?? '18:00:00',
                    'lunch_start' => $daySettings['tushlik_boshlanish'] ?? '13:00:00',
                    'lunch_end' => $daySettings['tushlik_tugash'] ?? '14:00:00'
                ];
                
                $lastDaySettings = $daySettings;
                if (in_array($commissionSourceType, ['institution', 'category'], true)) {
                    $lastDayTypes = json_decode($daySettings['maosh_turi'] ?? '', true);
                    if (!is_array($lastDayTypes)) {
                        $lastDayTypes = [$daySettings['maosh_turi'] ?? 'fixed'];
                    }
                    $lastDayTypes = array_values(array_filter(
                        $lastDayTypes,
                        static fn($type) => $type !== 'percent'
                    ));
                    $lastDaySettings['maosh_turi'] = json_encode($lastDayTypes);
                }
                $lastDaySalary = calculateDailySalary(
                    $empId, $date, $waitersAnalytics, $attData['status'],
                    $dailySetting, $attData['check_in'], $attData['check_out'],
                    0, $lastDaySettings, $dayWorkSchedule
                );
                break;
            }
        }
    }
    
    if($lastDate === null) {
        $lastDaySalary = 0;
    }
    
    $calculated = round($totalSalary);
    $allSettings = $allSettingsHistory[$empId] ?? [];
    
    $hourlyRateForDisplay = 0;
    if(in_array('fixed', $maoshTurlari)) {
        $hourlyRateForDisplay = $daysInMonth > 0 ? ($currentSettings['oylik_maosh'] / $daysInMonth) / $expectedWorkHours : 0;
    } elseif(in_array('hourly', $maoshTurlari)) {
        $hourlyRateForDisplay = $currentSettings['soatlik_stavka'] ?? 0;
    }
    
    $employees[$key]['calculated'] = round($lastDaySalary);
    $employees[$key]['total_calculated'] = round($totalSalary);
    $employees[$key]['group_sales_commission'] = $groupSalesCommission;
    $employees[$key]['last_attendance_date'] = $lastDate;
    $employees[$key]['last_attendance_status'] = $lastAttendanceStatus;
    $employees[$key]['last_check_in'] = $lastCheckIn;
    $employees[$key]['last_check_out'] = $lastCheckOut;
    $employees[$key]['badge_type'] = $primaryType;
    $employees[$key]['badge_text'] = $badgeText;
    $employees[$key]['badge_class'] = match($primaryType) {
        'fixed' => 'badge-fixed',
        'hourly' => 'badge-hourly',
        'percent' => 'badge-percent',
        'shift' => 'badge-shift',
        default => 'badge-fixed'
    };
    $employees[$key]['salary_display'] = $salaryDisplay;
    $employees[$key]['current_type'] = $primaryType;
    $employees[$key]['salary_types'] = $maoshTurlari;
    $employees[$key]['current_salary'] = in_array('fixed', $maoshTurlari) ? ($currentSettings['oylik_maosh'] ?? 0) : 0;
    $employees[$key]['current_percent'] = in_array('percent', $maoshTurlari) ? ($currentSettings['foiz_qiymati'] ?? 0) : 0;
    $employees[$key]['current_hourly'] = in_array('hourly', $maoshTurlari) ? ($currentSettings['soatlik_stavka'] ?? 0) : 0;
    $employees[$key]['current_shift_amount'] = in_array('shift', $maoshTurlari, true) ? ($currentSettings['smena_summa'] ?? 0) : 0;
    $employees[$key]['poster_user_id'] = $currentSettings['poster_user_id'] ?? null;
    $employees[$key]['settings'] = $allSettings;
    $employees[$key]['current_schedule_setting_id'] = (int)($currentSettings['id'] ?? 0);
    $employees[$key]['settings_count'] = count($allSettings);
    $employees[$key]['has_multiple_settings'] = count($allSettings) > 1;
    $employees[$key]['total_worked_hours'] = $totalWorkedHours;
    $employees[$key]['hourly_rate_display'] = round($hourlyRateForDisplay);
    $employees[$key]['daily_salaries'] = $dailySalaries;
    $employees[$key]['attended'] = $attended;
    $employees[$key]['work_schedule'] = $workSchedule;
    $employees[$key]['ishga_kelish_vaqti'] = $workSchedule['start_time'];
    $employees[$key]['ishdan_ketish_vaqti'] = $workSchedule['end_time'];
    $employees[$key]['tushlik_boshlanish'] = $workSchedule['lunch_start'];
    $employees[$key]['tushlik_tugash'] = $workSchedule['lunch_end'];
    $employees[$key]['amal_qiladi'] = $currentSettings['amal_qiladi'] ?? null;
    $employees[$key]['yaratilgan'] = $emp['yaratilgan'] ?? null;
    
    $employees[$key]['kech_kelish_jarima_enabled'] = $currentSettings['kech_kelish_jarima_enabled'] ?? 1;
    $employees[$key]['kech_kelish_jarima_per_minute'] = $currentSettings['kech_kelish_jarima_per_minute'] ?? 1000;
    $employees[$key]['kech_kelish_max_jarima'] = $currentSettings['kech_kelish_max_jarima'] ?? 50000;
    $employees[$key]['kech_kelish_grace_minutes'] = $currentSettings['kech_kelish_grace_minutes'] ?? 10;
    
    $employees[$key]['erta_ketish_jarima_enabled'] = $currentSettings['erta_ketish_jarima_enabled'] ?? 1;
    $employees[$key]['erta_ketish_jarima_per_minute'] = $currentSettings['erta_ketish_jarima_per_minute'] ?? 1000;
    $employees[$key]['erta_ketish_max_jarima'] = $currentSettings['erta_ketish_max_jarima'] ?? 50000;
    $employees[$key]['erta_ketish_grace_minutes'] = $currentSettings['erta_ketish_grace_minutes'] ?? 10;
    
    $employees[$key]['erta_kelish_bonus_enabled'] = $currentSettings['erta_kelish_bonus_enabled'] ?? 1;
    $employees[$key]['erta_kelish_bonus_per_minute'] = $currentSettings['erta_kelish_bonus_amount'] ?? 1000;
    $employees[$key]['erta_kelish_max_bonus'] = $currentSettings['erta_kelish_max_bonus'] ?? 50000;
    
    $employees[$key]['kech_ketish_bonus_enabled'] = $currentSettings['kech_ketish_bonus_enabled'] ?? 1;
    $employees[$key]['kech_ketish_bonus_per_minute'] = $currentSettings['kech_ketish_bonus_amount'] ?? 1000;
    $employees[$key]['kech_ketish_max_bonus'] = $currentSettings['kech_ketish_max_bonus'] ?? 50000;
    
    $employees[$key]['total_late_arrival_penalty'] = $totalLateArrivalPenalty;
    $employees[$key]['total_early_departure_penalty'] = $totalEarlyDeparturePenalty;
    $employees[$key]['total_early_arrival_bonus'] = $totalEarlyArrivalBonus;
    $employees[$key]['total_late_departure_bonus'] = $totalLateDepartureBonus;
    $employees[$key]['total_penalty'] = $totalLateArrivalPenalty + $totalEarlyDeparturePenalty;
    $employees[$key]['total_bonus'] = $totalEarlyArrivalBonus + $totalLateDepartureBonus;
    $employees[$key]['net_bonus'] = ($totalEarlyArrivalBonus + $totalLateDepartureBonus) - ($totalLateArrivalPenalty + $totalEarlyDeparturePenalty);
    
    $totalCalculated += round($lastDaySalary);
    $pageTotalSalary += round($totalSalary);
}

// ============================================================
// JORIY OY UCHUN BARCHA XODIMLARNING OYLIK MAOSHLARI YIG'INDISI
// ============================================================
$totalMonthlySalary = 0;
$totalEmployeesCount = 0;

try {
    $stmt = $pdo->prepare("
        SELECT e.id, e.ismi, e.poster_user_id,
               e.position_id, e.poster_spot_id,
               p.commission_source_type, p.commission_poster_spot_id
        FROM xodimlar e
        LEFT JOIN employee_positions p ON p.id = e.position_id
            AND p.account_name COLLATE utf8mb4_unicode_ci = e.account_name COLLATE utf8mb4_unicode_ci
        WHERE e.holati = 'active' AND e.account_name = ?
        " . (isBranchUser() ? "AND e.branch_spot_id = ?" : "") . "
    ");
    $allEmployeeParams = [$accountName];
    if (isBranchUser()) {
        $allEmployeeParams[] = currentBranchSpotId();
    }
    $stmt->execute($allEmployeeParams);
    $allEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalEmployeesCount = count($allEmployees);
    
    if ($totalEmployeesCount > 0) {
        foreach ($allEmployees as $employee) {
            $empId = $employee['id'];
            $settings = getEmployeeMonthlySettings($empId, $selectedMonth, date('Y-m-d'));
            
            $maoshTurlari = json_decode($settings['maosh_turi'], true);
            if (!is_array($maoshTurlari)) {
                $maoshTurlari = [$settings['maosh_turi'] ?? 'fixed'];
            }
            
            $monthlyAmount = 0;
            
            if (in_array('fixed', $maoshTurlari)) {
                $monthlyAmount += (float)($settings['oylik_maosh'] ?? 0);
            }
            if (in_array('hourly', $maoshTurlari)) {
                $soatlikStavka = (float)($settings['soatlik_stavka'] ?? 0);
                $daysInMonthLocal = (int)date('t', strtotime($selectedMonth . '-01'));
                $monthlyAmount += $soatlikStavka * 8 * $daysInMonthLocal;
            }
            if (in_array('percent', $maoshTurlari, true)) {
                $percent = (float)($settings['foiz_qiymati'] ?? 0);
                $sourceType = $employee['commission_source_type'] ?? 'waiter';
                $salesSourceId = match ($sourceType) {
                    'institution' => (int)($employee['poster_spot_id'] ?? 0),
                    'category' => $employeePosterCategoryIds[(int)$empId] ?? [],
                    default => 0,
                };
                $monthlyAmount += calculateMonthlySalesCommission(
                    $sourceType,
                    $percent,
                    $salesSourceId,
                    $settings['poster_user_id'] ?? $employee['poster_user_id'] ?? null,
                    $posterGroupSales,
                    $waitersAnalytics
                );
            }
            
            $totalMonthlySalary += $monthlyAmount;
        }
    }
    
} catch (Exception $e) {
    error_log("Total monthly salary calculation error: " . $e->getMessage());
    $totalMonthlySalary = 0;
}

// ============================================================
// WAITERS
// ============================================================
$waiters = [];
if(!empty($waitersAnalytics)) {
    foreach($waitersAnalytics as $userId => $data) {
        $waiters[] = [
            'user_id' => $userId,
            'name' => $data['name'],
            'profit' => number_format($data['profit']),
            'revenue' => number_format($data['revenue']),
            'profit_amount' => $data['profit'],
            'revenue_amount' => $data['revenue'],
            'clients' => $data['clients']
        ];
    }
}

$toggleEmployeeId = isset($_GET['toggle_id']) ? (int)$_GET['toggle_id'] : 0;
?>



<!-- ============================================================ -->
<!-- HTML - employees.php -->
<!-- ============================================================ -->
<style>
    ::-webkit-scrollbar { width: 0px; background: transparent; }
    .month-selector { display: flex; align-items: center; justify-content: space-between; padding: 8px 16px; background: white; flex-wrap: wrap; gap: 10px; }
    .month-selector h3 { margin: 0; font-size: 18px; color: #1e293b; }
    .month-selector select { padding: 8px 15px; border-radius: 8px; border: 1px solid #b4d5fa; font-size: 14px; background: #f8fafc; color: #4a9ee9; }
    .effective-badge { font-size: 11px; background: #f1f5f9; padding: 2px 10px; border-radius: 12px; color: #475569; }
    .success-message { margin: 10px 20px; padding: 12px 16px; background: #d1fae5; color: #065f46; border-radius: 8px; border-left: 4px solid #10b981; }
    .table-wrapper { overflow-x: auto; background: white; max-height: calc(100vh - 174px); }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    thead { background: #f1f5f9; }
    th { padding: 8px 10px; text-align: left; font-weight: 400; color: #475569; border-bottom: 1px solid #eaeaea; font-size: 12px; }
    td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    tr:hover { background: #f8fafc; }
    .amount { font-weight: 600; color: #0f172a; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 500; min-width: 100px; text-align: center; }
    .badge-fixed { border: 1px solid #3c66d8; background: #f3f8ff; color: #3c66d8; }
    .badge-hourly { border: 1px solid #2a6655; background: #e8fff3; color: #2a6655; }
    .badge-percent { border: 1px solid #985e3a; background: #fff9e3; color: #985e3a; }
    .employee-name { color: #333; font-weight: 500; text-decoration: none; cursor: pointer; font-size: 14px; }
    .time-badge { font-size: 9px; color: #64748b; margin-top: 1px; }
    .btn-edit { cursor: pointer; font-size: 14px; transition: all 0.2s; background: white; border: none; padding: 4px 10px; border-radius: 4px; }
    .btn-edit:hover { background: #f1f5f9; }
    .edit-panel { background: #f8fafc; padding: 20px !important; }
    .edit-panel-inner { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
    .edit-panel-inner label { display: block; font-size: 12px; font-weight: 500; color: #333333; margin-bottom: 3px; }
    .edit-panel-inner input, .edit-panel-inner select { width: 300px; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; }
    .btn-save { padding: 8px 20px; background: #22c55e; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; margin-top: 10px; margin-left: 5px; }
    .btn-save:hover { background: #16a34a; }
    .btn-cancel { padding: 8px 8px; background: none; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; color: red; margin-top: 5px; }
    .btn-cancel:hover { background: none; }
    .work-schedule-settings { margin: 15px 0; padding: 15px 0; background: none; }
    .schedule-title { font-weight: 600; margin-bottom: 10px; color: #222222; font-size: 15px}
    .schedule-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 5px; }
    .schedule-row label { font-size: 12px; color: #475569; min-width: 110px; }
    .schedule-row input { padding: 4px 8px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 12px; width: 115px;}
    .penalty-settings, .bonus-settings { margin: 10px 0;}
    .penalty-settings { background: none; border-top: 1px #e2e8f0 solid; }
    .bonus-settings { background: none; border-top: 1px #e2e8f0 solid; }
    .bonus-settings.blue { background: none; border-top: 1px #e2e8f0 solid; }
    .penalty-title, .bonus-title { font-weight: 600; margin-bottom: 10px; color: #1e293b; font-size: 15px; padding-top:10px}
    .checkbox-group { display: flex; align-items: center; gap: 1px; margin-bottom: 10px; }
    .checkbox-group input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }
    .penalty-row, .bonus-row { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; margin-bottom: 5px; }
    .penalty-row label, .bonus-row label { font-size: 12px; color: #475569; min-width: 150px; }
    .penalty-row input, .bonus-row input { padding: 4px 8px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 12px; width: 120px;}
    label { font-size: 11px; }
    .grace-hint { font-size: 11px; color: #64748b; }
    .settings-list { margin-top: 15px; padding: 10px; background: none; border-top: 1px solid #e2e8f0; }
    .setting-item { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-bottom: 1px solid #f1f5f9; border-top: 1px solid #e2e8f0; }
    .setting-item:last-child { border-bottom: none; }
    .setting-date { font-weight: 500; color: #1e293b; }
    .setting-info { color: #475569; font-size: 13px; }
    .btn-delete-setting { color: #ef4444; text-decoration: none; font-size: 16px; padding: 0 5px; }
    .btn-delete-setting:hover { color: #dc2626; }
    .bonus-summary { margin-top: 5px; padding: 5px; background: none; border-top: 1px solid #e2e8f0; }
    .bonus-summary div { font-size: 13px; }
    .pagination { display: flex; justify-content: left; align-items: center; gap: 8px; padding: 10px 6px; flex-wrap: wrap; }
    .pagination a, .pagination span { padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; color: #1e293b; }
    .pagination a:hover { background: #f1f5f9; }
    .pagination .active { background: #2563eb; color: white; }
    .pagination .disabled { color: #94a3b8; cursor: not-allowed; }
    .page-info { font-size: 13px; color: #64748b; margin-right: 15px; }
    .toggle-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; }
    .toggle-overlay.active { display: block; }
    .toggle-panel { position: fixed; top: 0; right: -600px; width: 400px; max-width: 90vw; height: 100%; background: white; z-index: 1000; transition: right 0.3s ease; overflow-y: auto; box-shadow: -2px 0 10px rgba(0,0,0,0.1); }
    .toggle-panel.open { right: 0; }
    .toggle-header { display: flex; justify-content: space-between; align-items: center; padding: 20px; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; background: white; z-index: 1; }
    .toggle-header h3 { margin: 0; font-size: 18px; color: #1e293b; }
    .toggle-close { background: none; border: none; font-size: 28px; cursor: pointer; color: #94a3b8; }
    .toggle-close:hover { color: #1e293b; }
    .toggle-body { padding: 20px; max-width: 100%; }
    .history-toggle-btn { background: none; border: 1px solid #e2e8f0; border-radius: 4px; padding: 2px 8px; cursor: pointer; font-size: 11px; color: #64748b; }
    .history-toggle-btn:hover { background: #f1f5f9; }
    .history-content { max-height: 0; overflow: hidden; transition: max-height 0.3s ease; }
    .history-content.open { max-height: 500px; }
    .edit-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; }
    .edit-modal-box { background: white; border-radius: 12px; width: 750px; max-width: 95%; max-height: 90vh; overflow-y: auto; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
    .edit-modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e2e8f0; }
    .edit-modal-close { background: none; border: none; font-size: 28px; cursor: pointer; color: #94a3b8; padding: 0 10px; }
    .edit-modal-close:hover { color: #1e293b; }
    .modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center; }
    .modal-content { background-color: #fff; border-radius: 10px; max-width: 450px; width: 90%; box-shadow: 0 5px 20px rgba(0,0,0,0.3); animation: slideDown 0.3s ease; }
    @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .modal-header { padding: 15px 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h3 { margin: 0; color: #d32f2f; }
    .modal-close { font-size: 28px; cursor: pointer; color: #999; }
    .modal-close:hover { color: #333; }
    .modal-body { padding: 20px; }
    .modal-body p { margin: 0; font-size: 16px; }
    .modal-footer { padding: 15px 20px; border-top: 1px solid #ddd; display: flex; justify-content: flex-end; gap: 10px; }
    .btn-cancel-modal { padding: 8px 20px; background-color: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; }
    .btn-delete-modal { padding: 8px 20px; background-color: #d32f2f; color: white; text-decoration: none; border-radius: 5px; cursor: pointer; }
    .btn-cancel-modal:hover { background-color: #5a6268; }
    .btn-delete-modal:hover { background-color: #b71c1c; }
    .created-at-edit { margin: 10px 0; padding: 10px; background: #f0f9ff; border-radius: 8px; border: 1px solid #bae6fd; }
    .created-at-edit label { font-size: 12px; font-weight: 500; color: #0369a1; }
    .created-at-edit .input-group { display: flex; gap: 8px; align-items: center; margin-top: 4px; flex-wrap: wrap; }
    .created-at-edit input[type="datetime-local"] { padding: 6px 10px; border: 1px solid #bae6fd; border-radius: 6px; font-size: 13px; background: white; }
    .created-at-edit .btn-update-created { padding: 6px 16px; background: #0ea5e9; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; }
    .created-at-edit .btn-update-created:hover { background: #0284c7; }
    .created-at-edit .update-status { font-size: 12px; margin-top: 4px; }
    .created-at-edit .update-status.success { color: #16a34a; }
    .created-at-edit .update-status.error { color: #dc2626; }
    [id^="commission_source_div_"].category-source-layout { display: contents !important; }
    [id^="commission_source_div_"].category-source-layout > [id^="commission_source_label_"] { display: none; }
    .employee-category-picker { position: relative; }
    .employee-category-trigger { display: flex; align-items: center; justify-content: space-between; gap: 12px; box-sizing: border-box; width: 300px; max-width: 100%; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; color: #333; text-align: left; font-size: 13px; cursor: pointer; }
    .employee-category-selection { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .employee-category-trigger:hover, .employee-category-trigger[aria-expanded="true"] { border-color: #b4d5fa; }
    .employee-category-trigger::after { content: ""; width: 7px; height: 7px; flex: 0 0 7px; border-right: 1.5px solid #64748b; border-bottom: 1.5px solid #64748b; transform: rotate(45deg) translateY(-2px); }
    .employee-category-menu { position: absolute; z-index: 20; top: calc(100% + 5px); left: 0; width: 300px; max-width: min(300px, calc(100vw - 32px)); display: none; overflow: hidden; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; box-shadow: 0 5px 16px rgba(15, 23, 42, .12); }
    .employee-category-menu.open { display: block; }
    .employee-category-actions { display: flex; gap: 12px; padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
    .employee-category-actions button { padding: 0; border: 0; background: transparent; color: #2563eb; font-size: 12px; cursor: pointer; }
    .employee-category-actions button:hover { color: #1d4ed8; text-decoration: underline; }
    .employee-category-search { box-sizing: border-box; width: calc(100% - 16px) !important; margin: 8px; padding: 6px 10px !important; border: 1px solid #e2e8f0 !important; border-radius: 6px !important; font-size: 13px !important; }
    .employee-category-options { max-height: 220px; overflow-y: auto; padding: 4px 0; }
    [id^="employee_categories_div_"] .employee-category-option { display: flex; align-items: center; gap: 8px; margin: 0; padding: 6px 10px; color: #333; font-size: 12px; font-weight: 400; cursor: pointer; }
    [id^="employee_categories_div_"] .employee-category-option:hover { background: #f8fafc; }
</style>

<!-- ============================================================ -->
<!-- MONTH SELECTOR -->
<!-- ============================================================ -->
<div class="month-selector">
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <h3><?= t('employees') ?></h3>
        <?= renderEmployeeFilterBar($pdo, 'employees', (string)$accountName, $token, (string)$searchQuery, ['month' => $selectedMonth], $sid ?? '') ?>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <form method="POST" style="margin: 0; display: inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="sid" value="<?= htmlspecialchars($sid ?? '') ?>">
            <button type="submit" name="sync_employees" style="background: #22c55e; color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 300; transition: all 0.2s; display: flex; align-items: center; box-shadow: 0 2px 8px rgba(34, 197, 94, 0.3);" onmouseover="this.style.background='#16a34a'; this.style.transform='scale(1.02)';" onmouseout="this.style.background='#22c55e'; this.style.transform='scale(1)';"><?= t('sync') ?></button>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- MESSAGES -->
<!-- ============================================================ -->
<?php if (!empty($sync_message)): ?>
<div style="margin: 0 20px 15px 20px; padding: 12px 16px; background: #d1fae5; color: #065f46; border-radius: 8px; border-left: 4px solid #10b981; display: flex; align-items: center; gap: 10px;"><span><?= htmlspecialchars($sync_message, ENT_QUOTES, 'UTF-8') ?></span></div>
<?php endif; ?>

<?php if (!empty($sync_error)): ?>
<div style="margin: 0 20px 15px 20px; padding: 12px 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; border-left: 4px solid #ef4444; display: flex; align-items: center; gap: 10px;"><span><?= htmlspecialchars($sync_error, ENT_QUOTES, 'UTF-8') ?></span></div>
<?php endif; ?>

<?php if ($posterGroupSalesError !== ''): ?>
<div style="margin: 0 20px 15px 20px; padding: 12px 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; border-left: 4px solid #ef4444;"><span>Poster muassasa/kategoriya savdosi olinmadi: <?= htmlspecialchars($posterGroupSalesError, ENT_QUOTES, 'UTF-8') ?></span></div>
<?php endif; ?>

<?php if($saved): ?>
<div class="success-message">✓ <?= t('success_save') ?></div>
<?php endif; ?>

<?php if($deleted): ?>
<div class="success-message" style="background:#fef3c7; color:#92400e; border-left-color:#f59e0b;">✓ <?= t('success_delete') ?></div>
<?php endif; ?>

<?php if($updated): ?>
<div class="success-message">✓ <?= t('success_update') ?></div>
<?php endif; ?>

<?php if($classificationSaved): ?>
<div class="success-message">✓ <?= t('position_settings_saved') ?></div>
<?php endif; ?>

<?php if($classificationError): ?>
<div class="success-message" style="background:#fee2e2; color:#991b1b; border-left-color:#ef4444;"><?= t('position_save_error') ?></div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- TABLE -->
<!-- ============================================================ -->
<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th><?= t('employee') ?></th>
                <th><?= t('salary_type') ?></th>
                <th style="text-align: right;"><?= t('salary_amount') ?></th>
                <th style="text-align: right;"></th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $startNumber = ($currentPage - 1) * $itemsPerPage + 1;
            $i = $startNumber; 
            if(count($employees) > 0):
            foreach($employees as $emp): 
                $schedule = $emp['work_schedule'];
                $createdAt = $emp['yaratilgan'] ?? '';
                $createdAtDisplay = !empty($createdAt) ? date('d.m.Y H:i', strtotime($createdAt)) : '-';
            ?>
            <tr>
                <td><?= $i++ ?></td>
                <td>
                    <a href="javascript:void(0)" onclick="openToggle(<?= $emp['id'] ?>)" class="employee-name">
                        <?= htmlspecialchars($emp['ismi']) ?>
                    </a>
                    <div class="time-badge" id="employee_schedule_<?= (int)$emp['id'] ?>" data-setting-id="<?= (int)$emp['current_schedule_setting_id'] ?>">
                        <?= substr($schedule['start_time'], 0, 5) ?> - <?= substr($schedule['end_time'], 0, 5) ?>
                        <div>
                            <?= htmlspecialchars($emp['position_name'] ?? t('position_unassigned'), ENT_QUOTES, 'UTF-8') ?>
                            <?php
                            $positionSalesSource = match ($emp['position_commission_source_type'] ?? 'waiter') {
                                'institution' => t('spot') . ': ' . ($emp['institution_name'] ?? t('unassigned')),
                                'category' => t('poster_categories'),
                                default => t('waiter_sales'),
                            };
                            ?>
                            · <?= t('sales') ?>: <?= htmlspecialchars($positionSalesSource, ENT_QUOTES, 'UTF-8') ?>
                            <?php if (in_array('percent', $emp['salary_types'] ?? [], true)): ?>
                            (<?= number_format((float)($emp['current_percent'] ?? 0), 2) ?>%)
                            <?php endif; ?>
                        </div>
                        <?php if($emp['amal_qiladi']): ?>
                        <span style="margin-left:8px;"> <?= date('d.m', strtotime($emp['amal_qiladi'])) ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td><span class="badge <?= $emp['badge_class'] ?>"><?= $emp['badge_text'] ?></span></td>
                <td style="text-align: right; font-size:14px;">
                    <?= $emp['salary_display'] ?>
                    <?php if (!empty($emp['group_sales_commission'])): ?>
                    <div class="time-badge">Guruh savdosidan: <?= number_format($emp['group_sales_commission']) ?> <?= htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </td>
                <td style="text-align: right;">
                    <div class="employee-actions">
                        <button type="button" class="btn-schedule-quick" onclick="openScheduleModal(<?= (int)$emp['id'] ?>)" aria-label="<?= htmlspecialchars(t('quick_edit_schedule'), ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fa-regular fa-clock" aria-hidden="true"></i> <?= htmlspecialchars(t('quick_schedule'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button type="button" class="btn-edit" onclick="showEditPanel(<?= $emp['id'] ?>)"><?= t('edit') ?></button>
                    </div>
                </td>
            </tr>
            
            <!-- ============================================================ -->
            <!-- EDIT PANEL -->
            <!-- ============================================================ -->
            <tr id="edit_panel_<?= $emp['id'] ?>" style="display:none;">
                <td colspan="6" class="edit-panel">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
                        <input type="hidden" name="month" value="<?= $selectedMonth ?>">
                        <input type="hidden" name="sid" value="<?= htmlspecialchars($sid) ?>">

                        <div id="employee-classification" style="padding:12px; margin-bottom:14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px;">
                            <strong style="display:block; margin-bottom:10px;"><?= t('employee_classification') ?></strong>
                            <small style="display:block; margin-bottom:8px; color:#475569;"><?= t('choose_employee_position') ?></small>
                            <div class="edit-panel-inner" style="margin-bottom:8px;">
                                <div>
                                    <label><?= t('position') ?></label>
                                    <select name="position_id" id="employee_position_<?= (int)$emp['id'] ?>" onchange="updateCommissionSourceUi(<?= (int)$emp['id'] ?>)">
                                        <option value="0"><?= t('unassigned') ?></option>
                                        <?php foreach ($positions as $position): ?>
                                        <option value="<?= (int)$position['id'] ?>"
                                                data-source-type="<?= htmlspecialchars($position['commission_source_type'] ?? 'waiter', ENT_QUOTES, 'UTF-8') ?>"
                                                data-source-label="<?= htmlspecialchars($positionSalesSourceLabels[(int)$position['id']] ?? t('waiter_sales'), ENT_QUOTES, 'UTF-8') ?>"
                                                <?= (int)($emp['position_id'] ?? 0) === (int)$position['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($position['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <button type="submit" name="classification_action" value="save_employee_classification" formnovalidate class="btn-save"><?= t('save_employee_position') ?></button>
                            </div>
                        </div>
                        
                        <!-- Effective From -->
                        <div class="edit-panel-inner">
                            <div>
                                <label><?= t('effective_from') ?> *</label>
                                <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        
                        <!-- Salary Types (Checkbox) -->
                        <div style="margin-bottom:15px;background:#f8fafc; border-radius:8px;">
                            <label style="display:block; margin-bottom:8px; font-weight:600; font-size:15px"><?= t('salary_types') ?>:</label>
                           <div style="display:flex; gap:15px; flex-wrap:wrap; align-items:center;padding-left:5px">
    <label style="display:inline-flex; align-items:center; gap:5px; font-weight:normal; cursor:pointer;">
        <input style="width: 16px;height: 16px;" type="checkbox" name="salary_types[]" value="fixed" checked onchange="toggleSalaryFields(<?= $emp['id'] ?>)"> 
        <?= t('fixed') ?>
    </label>
    <label style="display:inline-flex; align-items:center; gap:5px; font-weight:normal; cursor:pointer;">
        <input style="width: 16px;height: 16px;" type="checkbox" name="salary_types[]" value="hourly" onchange="toggleSalaryFields(<?= $emp['id'] ?>)"> 
        <?= t('hourly') ?>
    </label>
    <label style="display:inline-flex; align-items:center; gap:5px; font-weight:normal; cursor:pointer;">
        <input style="width: 16px;height: 16px; color: red;" type="checkbox" name="salary_types[]" value="percent" onchange="toggleSalaryFields(<?= $emp['id'] ?>)"> 
        <?= t('percent') ?>
    </label>
    <label style="display:inline-flex; align-items:center; gap:5px; font-weight:normal; cursor:pointer;">
        <input style="width: 16px;height: 16px;" type="checkbox" name="salary_types[]" value="shift" onchange="toggleSalaryFields(<?= $emp['id'] ?>)">
        <?= t('shift') ?>
    </label>
</div>
                        </div>
                        
                        <!-- Salary Fields -->
                        <div class="edit-panel-inner">
                            <div id="fixed_div_<?= $emp['id'] ?>">
                                <label><?= t('monthly_salary') ?> (<?= $currencySymbol ?>)</label>
                                <input type="number" name="fixed_salary" value="<?= $emp['current_salary'] ?>" step="1">
                            </div>
                            <div id="hourly_div_<?= $emp['id'] ?>" style="display:none;">
                                <label><?= t('hourly_rate') ?> (<?= $currencySymbol ?>/<?= t('hour') ?>)</label>
                                <input type="number" name="hourly_rate" value="<?= $emp['current_hourly'] ?>" step="1">
                            </div>
                            <div id="percent_div_<?= $emp['id'] ?>" style="display:none;">
                                <label><?= t('percent_value') ?> (%)</label>
                                <input type="number" name="percent_value" value="<?= $emp['current_percent'] ?>" step="0.1">
                            </div>
                            <div id="shift_div_<?= $emp['id'] ?>" style="display:none;">
                                <label><?= t('shift_amount') ?> (<?= $currencySymbol ?>/<?= t('shift') ?>)</label>
                                <input type="number" name="smena_summa" value="<?= htmlspecialchars((string)$emp['current_shift_amount'], ENT_QUOTES, 'UTF-8') ?>" min="0" step="1">
                            </div>
                            <div id="commission_source_div_<?= (int)$emp['id'] ?>" style="display:none;">
                                <div id="commission_source_label_<?= (int)$emp['id'] ?>" style="margin-bottom:8px; color:#475569;"></div>
                                <div id="employee_spots_div_<?= (int)$emp['id'] ?>">
                                    <label><?= t('poster_spot') ?></label>
                                    <select name="poster_spot_id" onchange="updateCommissionSourceUi(<?= (int)$emp['id'] ?>)">
                                        <option value="0"><?= t('unassigned') ?></option>
                                        <?php foreach ($posterSpots as $spot): ?>
                                        <?php $spotId = (int)($spot['spot_id'] ?? 0); ?>
                                        <?php if ($spotId > 0): ?>
                                        <option value="<?= $spotId ?>" <?= (int)($emp['poster_spot_id'] ?? 0) === $spotId ? 'selected' : '' ?>><?= htmlspecialchars($spot['name'] ?? $spot['spot_name'] ?? ('Spot ' . $spotId), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div id="employee_categories_div_<?= (int)$emp['id'] ?>">
                                    <label><?= t('poster_categories') ?></label>
                                    <div class="employee-category-picker">
                                        <button type="button" class="employee-category-trigger" aria-expanded="false">
                                            <span class="employee-category-selection"><?= t('select_categories') ?></span>
                                        </button>
                                        <div class="employee-category-menu">
                                            <div class="employee-category-actions">
                                                <button type="button" class="employee-category-select-all"><?= t('select_all_categories') ?></button>
                                                <button type="button" class="employee-category-clear"><?= t('clear') ?></button>
                                            </div>
                                            <input type="search" class="employee-category-search" placeholder="<?= htmlspecialchars(t('search_categories'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                                            <div class="employee-category-options">
                                        <?php foreach ($posterCategories as $category): ?>
                                        <?php $categoryId = (int)($category['category_id'] ?? 0); ?>
                                        <?php if ($categoryId > 0): ?>
                                        <label class="employee-category-option">
                                            <input type="checkbox" name="poster_category_ids[]" value="<?= $categoryId ?>" <?= in_array($categoryId, $emp['poster_category_ids'] ?? [], true) ? 'checked' : '' ?> onchange="updateCommissionSourceUi(<?= (int)$emp['id'] ?>)">
                                            <span><?= htmlspecialchars($category['category_name'] ?? ('Kategoriya ' . $categoryId), ENT_QUOTES, 'UTF-8') ?></span>
                                        </label>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="waiter_select_div_<?= (int)$emp['id'] ?>">
                                <label><?= t('select_waiter') ?></label>
                                <select name="poster_user_id">
                                    <option value="">-- <?= t('select_waiter') ?> --</option>
                                    <?php foreach($waiters as $w): ?>
                                    <option value="<?= $w['user_id'] ?>" <?= ($emp['poster_user_id'] == $w['user_id']) ? 'selected' : '' ?>><?= htmlspecialchars($w['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                </div>
                            </div>
                        </div>
                        
                        <input type="hidden" name="ishga_kelish_vaqti" value="<?= htmlspecialchars((string)($emp['ishga_kelish_vaqti'] ?? '09:00:00'), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="ishdan_ketish_vaqti" value="<?= htmlspecialchars((string)($emp['ishdan_ketish_vaqti'] ?? '18:00:00'), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="tushlik_boshlanish" value="<?= htmlspecialchars((string)($emp['tushlik_boshlanish'] ?? '13:00:00'), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="tushlik_tugash" value="<?= htmlspecialchars((string)($emp['tushlik_tugash'] ?? '14:00:00'), ENT_QUOTES, 'UTF-8') ?>">
                        
                        <!-- Penalty & Bonus Settings -->
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div class="penalty-settings">
                                <div class="penalty-title"><?= t('late_arrival_penalty') ?></div>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="kech_kelish_jarima_enabled" id="kech_kelish_jarima_enabled_<?= $emp['id'] ?>" value="1" <?= ($emp['kech_kelish_jarima_enabled'] ?? 1) ? 'checked' : '' ?>>
                                    <label><?= t('enable_penalty') ?></label>
                                </div>
                                <div class="penalty-row">
                                    <label><?= t('grace_minutes') ?>:</label>
                                    <input type="number" name="kech_kelish_grace_minutes" value="<?= $emp['kech_kelish_grace_minutes'] ?? 10 ?>" step="1" >
                                </div>
                                <div class="penalty-row">
                                    <label>min:</label>
                                    <input type="number" name="kech_kelish_jarima_per_minute" value="<?= $emp['kech_kelish_jarima_per_minute'] ?? 1000 ?>" step="1" > <?= $currencySymbol ?>
                                </div>
                                <div class="penalty-row">
                                    <label><?= t('max_penalty') ?>:</label>
                                    <input type="number" name="kech_kelish_max_jarima" value="<?= $emp['kech_kelish_max_jarima'] ?? 50000 ?>" step="1" > <?= $currencySymbol ?>
                                </div>
                            </div>
                            
                            <div class="penalty-settings">
                                <div class="penalty-title"><?= t('early_departure_penalty') ?></div>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="erta_ketish_jarima_enabled" id="erta_ketish_jarima_enabled_<?= $emp['id'] ?>" value="1" <?= ($emp['erta_ketish_jarima_enabled'] ?? 1) ? 'checked' : '' ?>>
                                    <label><?= t('enable_penalty') ?></label>
                                </div>
                                <div class="penalty-row">
                                    <label><?= t('grace_minutes') ?>:</label>
                                    <input type="number" name="erta_ketish_grace_minutes" value="<?= $emp['erta_ketish_grace_minutes'] ?? 10 ?>" step="1">
                                </div>
                                <div class="penalty-row">
                                    <label>min:</label>
                                    <input type="number" name="erta_ketish_jarima_per_minute" value="<?= $emp['erta_ketish_jarima_per_minute'] ?? 1000 ?>" step="1" > <?= $currencySymbol ?>
                                </div>
                                <div class="penalty-row">
                                    <label><?= t('max_penalty') ?>:</label>
                                    <input type="number" name="erta_ketish_max_jarima" value="<?= $emp['erta_ketish_max_jarima'] ?? 50000 ?>" step="1" > <?= $currencySymbol ?>
                                </div>
                            </div>
                            
                            <div class="bonus-settings">
                                <div class="bonus-title"><?= t('early_arrival_bonus') ?></div>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="erta_kelish_bonus_enabled" id="erta_kelish_bonus_enabled_<?= $emp['id'] ?>" value="1" <?= ($emp['erta_kelish_bonus_enabled'] ?? 1) ? 'checked' : '' ?>>
                                    <label><?= t('enable_bonus') ?></label>
                                </div>
                                <div class="bonus-row">
                                    <label>min:</label>
                                    <input type="number" name="erta_kelish_bonus_per_minute" value="<?= $emp['erta_kelish_bonus_per_minute'] ?? 1000 ?>" step="1" > <?= $currencySymbol ?>
                                </div>
                                <div class="bonus-row">
                                    <label><?= t('max_bonus') ?>:</label>
                                    <input type="number" name="erta_kelish_max_bonus" value="<?= $emp['erta_kelish_max_bonus'] ?? 50000 ?>" step="1" > <?= $currencySymbol ?>
                                </div>
                            </div>
                            
                            <div class="bonus-settings blue">
                                <div class="bonus-title"><?= t('late_departure_bonus') ?></div>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="kech_ketish_bonus_enabled" id="kech_ketish_bonus_enabled_<?= $emp['id'] ?>" value="1" <?= ($emp['kech_ketish_bonus_enabled'] ?? 1) ? 'checked' : '' ?>>
                                    <label><?= t('enable_bonus') ?></label>
                                </div>
                                <div class="bonus-row">
                                    <label>min:</label>
                                    <input type="number" name="kech_ketish_bonus_per_minute" value="<?= $emp['kech_ketish_bonus_per_minute'] ?? 1000 ?>" step="1" > <?= $currencySymbol ?>
                                </div>
                                <div class="bonus-row">
                                    <label><?= t('max_bonus') ?>:</label>
                                    <input type="number" name="kech_ketish_max_bonus" value="<?= $emp['kech_ketish_max_bonus'] ?? 50000 ?>" step="1" > <?= $currencySymbol ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Buttons -->
                        <button type="submit" name="save_setting" class="btn-save"><?= t('save') ?></button>
                        <button type="button" class="btn-cancel" onclick="hideEditPanel(<?= $emp['id'] ?>)"><?= t('cancel') ?></button>
                    </form>
                    
                    <!-- ============================================================ -->
                    <!-- SETTINGS HISTORY -->
                    <!-- ============================================================ -->
                    <?php if(count($emp['settings']) > 0): ?>
                        <div class="settings-list">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                <strong><?= t('settings_history') ?> (<?= count($emp['settings']) ?>):</strong>
                                <button class="history-toggle-btn" onclick="toggleHistory(<?= $emp['id'] ?>)">
                                    <span id="history_toggle_<?= $emp['id'] ?>">▼</span>
                                </button>
                            </div>
                            <div id="history_content_<?= $emp['id'] ?>" class="history-content" >
                            <?php 
                            $sortedSettings = array_reverse($emp['settings']);
                            foreach($sortedSettings as $s): 
                                $maoshTurlari = json_decode($s['maosh_turi'], true);
                                if (!is_array($maoshTurlari)) {
                                    $maoshTurlari = [$s['maosh_turi'] ?? 'fixed'];
                                }
                            ?>
                                <div class="setting-item" id="setting_row_<?= $s['id'] ?>">
                                    <div>
                                        <span class="setting-date"><?= date('d.m.Y', strtotime($s['amal_qiladi'])) ?>:</span>
                                        <span class="setting-info">
                                            <?php 
                                            $typeLabels = [];
                                            foreach($maoshTurlari as $type) {
                                                if($type == 'fixed') {
                                                    $typeLabels[] = number_format($s['oylik_maosh'] ?? 0) . ' ' . $currencySymbol . '/oy';
                                                } elseif($type == 'hourly') {
                                                    $typeLabels[] = number_format($s['soatlik_stavka'] ?? 0) . ' ' . $currencySymbol . '/soat';
                                                } elseif($type == 'shift') {
                                                    $typeLabels[] = number_format($s['smena_summa'] ?? 0) . ' ' . $currencySymbol . '/' . t('shift');
                                                } elseif($type == 'percent') {
                                                    $typeLabels[] = number_format($s['foiz_qiymati'] ?? 0, 1) . '%';
                                                }
                                            }
                                            echo implode(' + ', $typeLabels);
                                            ?>
                                            
                                            <?php if(isset($s['kech_kelish_jarima_enabled']) && $s['kech_kelish_jarima_enabled']): ?>
                                                <span style="color:#ef4444;"> -<?= number_format($s['kech_kelish_jarima_per_minute'] ?? 0) ?></span>
                                            <?php endif; ?>
                                            <?php if(isset($s['erta_kelish_bonus_enabled']) && $s['erta_kelish_bonus_enabled']): ?>
                                                <span style="color:#16a34a;"> +<?= number_format($s['erta_kelish_bonus_amount'] ?? 0) ?></span>
                                            <?php endif; ?>
                                            <?php if(isset($s['kech_ketish_bonus_enabled']) && $s['kech_ketish_bonus_enabled']): ?>
                                                <span style="color:#2563eb;"> +<?= number_format($s['kech_ketish_bonus_amount'] ?? 0) ?></span>
                                            <?php endif; ?>
                                            <?php if(isset($s['erta_ketish_jarima_enabled']) && $s['erta_ketish_jarima_enabled']): ?>
                                                <span style="color:#ef4444;"> -<?= number_format($s['erta_ketish_jarima_per_minute'] ?? 0) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div style="display:flex; gap:5px;">
                                        <button type="button" class="btn-schedule-quick" onclick="openScheduleModal(<?= (int)$emp['id'] ?>, '<?= htmlspecialchars($s['amal_qiladi'], ENT_QUOTES, 'UTF-8') ?>')" aria-label="<?= htmlspecialchars(t('quick_edit_schedule'), ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="fa-regular fa-clock" aria-hidden="true"></i> <?= htmlspecialchars(t('quick_schedule'), ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                        <button class="btn-edit" onclick="openEditModal(<?= $s['id'] ?>)" style="padding:2px 8px; font-size:14px;background:#f8fafc;cursor:pointer;">
                                           <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <a href="#" onclick="openDeleteModal(<?= (int)$s['id'] ?>); return false;" class="btn-delete-setting" style="text-decoration:none;">
                                          <i class="fa-solid fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; 
            else: ?>
            <tr>
                <td colspan="6" style="text-align:center; padding:40px;">
                    <?= t('no_employees') ?><br>
                    <small><?= htmlspecialchars($accountName) ?> accountida xodimlar topilmadi.</small>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3"><strong><?= t('total') ?></strong></td>
                <td style="text-align: right;"><strong><?= number_format($totalMonthlySalary) ?> <?= $currencySymbol ?></strong></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>

<!-- ============================================================ -->
<!-- PAGINATION -->
<!-- ============================================================ -->
<?php
$paginationParams = [
    'tab' => 'employees',
    'month' => $selectedMonth,
    'sid' => $sid,
    'search' => $searchQuery,
    'poster_spot_id' => $spotFilter,
    'position_id' => $positionFilter,
];
$paginationQuery = http_build_query($paginationParams);
?>
<div class="pagination">
    <span class="page-info">
        <?= t('showing') ?> <?= ($currentPage - 1) * $itemsPerPage + 1 ?> - <?= min($currentPage * $itemsPerPage, $totalEmployees) ?> / <?= $totalEmployees ?> <?= t('employees') ?>
    </span>
    
    <?php if($currentPage > 1): ?>
    <a href="?<?= htmlspecialchars($paginationQuery, ENT_QUOTES, 'UTF-8') ?>&page=<?= $currentPage - 1 ?>">« <?= t('previous') ?></a>
    <?php else: ?>
    <span class="disabled">« <?= t('previous') ?></span>
    <?php endif; ?>
    
    <?php
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    
    if($startPage > 1): ?>
    <a href="?<?= htmlspecialchars($paginationQuery, ENT_QUOTES, 'UTF-8') ?>&page=1">1</a>
    <?php if($startPage > 2): ?><span>...</span><?php endif; ?>
    <?php endif;
    
    for($p = $startPage; $p <= $endPage; $p++):
        if($p == $currentPage): ?>
        <span class="active"><?= $p ?></span>
        <?php else: ?>
        <a href="?<?= htmlspecialchars($paginationQuery, ENT_QUOTES, 'UTF-8') ?>&page=<?= $p ?>"><?= $p ?></a>
        <?php endif;
    endfor;
    
    if($endPage < $totalPages):
        if($endPage < $totalPages - 1): ?><span>...</span><?php endif; ?>
        <a href="?<?= htmlspecialchars($paginationQuery, ENT_QUOTES, 'UTF-8') ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a>
    <?php endif; ?>
    
    <?php if($currentPage < $totalPages): ?>
    <a href="?<?= htmlspecialchars($paginationQuery, ENT_QUOTES, 'UTF-8') ?>&page=<?= $currentPage + 1 ?>"><?= t('next') ?> »</a>
    <?php else: ?>
    <span class="disabled"><?= t('next') ?> »</span>
    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- TOGGLE PANEL -->
<!-- ============================================================ -->
<div id="toggleOverlay" class="toggle-overlay" onclick="closeToggle()"></div>
<div id="togglePanel" class="toggle-panel">
    <div class="toggle-header">
        <h3><span id="toggleEmployeeName"></span> - <?= t('details') ?></h3>
        <button class="toggle-close" onclick="closeToggle()">&times;</button>
    </div>
    <div class="toggle-body" id="toggleBody">
        <div style="text-align:center; padding:40px; color:#94a3b8;"><?= t('loading') ?></div>
    </div>
</div>

<!-- ============================================================ -->
<!-- DELETE MODAL -->
<!-- ============================================================ -->
<div id="deleteModal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?= t('delete_confirm') ?></h3>
            <span class="modal-close" onclick="closeDeleteModal()">&times;</span>
        </div>
        <div class="modal-body">
            <p><?= t('delete_message') ?></p>
        </div>
        <div class="modal-footer">
            <button onclick="closeDeleteModal()" class="btn-cancel-modal"><?= t('cancel') ?></button>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="delete_setting" id="deleteSettingId" value="">
                <input type="hidden" name="month" value="<?= htmlspecialchars($selectedMonth, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
                <input type="hidden" name="sid" value="<?= htmlspecialchars($sid, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn-delete-modal"><?= t('delete') ?></button>
            </form>
        </div>
    </div>
</div>

<div id="scheduleQuickModal" class="schedule-quick-overlay" aria-hidden="true">
    <section class="schedule-quick-dialog" role="dialog" aria-modal="true" aria-labelledby="scheduleQuickTitle">
        <div class="schedule-quick-header">
            <div>
                <h3 id="scheduleQuickTitle"><?= htmlspecialchars(t('quick_edit_schedule'), ENT_QUOTES, 'UTF-8') ?></h3>
                <p id="scheduleQuickEffectiveDate"></p>
            </div>
            <button type="button" class="schedule-quick-close" onclick="closeScheduleModal()" aria-label="<?= htmlspecialchars(t('close'), ENT_QUOTES, 'UTF-8') ?>">&times;</button>
        </div>
        <form id="scheduleQuickForm">
            <input type="hidden" name="action" id="scheduleQuickAction" value="save_schedule">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="employee_id" id="quick_schedule_employee_id">
            <input type="hidden" name="sid" value="<?= htmlspecialchars((string)$sid, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="effective_date" id="quick_schedule_date" required>
            <div class="schedule-calendar">
                <label class="schedule-multi-select">
                    <input type="checkbox" id="scheduleMultiSelect">
                    <?= htmlspecialchars(t('schedule_multi_select'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <p id="scheduleSelectedCount" class="schedule-selected-count" aria-live="polite"></p>
                <div class="schedule-calendar-toolbar">
                    <button type="button" class="schedule-calendar-nav" id="scheduleCalendarPrev" aria-label="<?= htmlspecialchars(t('prev_month'), ENT_QUOTES, 'UTF-8') ?>">&lsaquo;</button>
                    <strong id="scheduleCalendarMonth"></strong>
                    <button type="button" class="schedule-calendar-nav" id="scheduleCalendarNext" aria-label="<?= htmlspecialchars(t('next_month'), ENT_QUOTES, 'UTF-8') ?>">&rsaquo;</button>
                </div>
                <div class="schedule-calendar-weekdays" id="scheduleCalendarWeekdays"></div>
                <div class="schedule-calendar-days" id="scheduleCalendarDays" role="grid" tabindex="-1" aria-label="<?= htmlspecialchars(t('work_schedule'), ENT_QUOTES, 'UTF-8') ?>"></div>
                <p class="schedule-calendar-hint"><?= htmlspecialchars(t('schedule_calendar_hint'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <p class="schedule-quick-note"><?= htmlspecialchars(t('schedule_applies_until_change'), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="schedule-template-controls">
                <label><?= htmlspecialchars(t('schedule_templates'), ENT_QUOTES, 'UTF-8') ?>
                    <select id="scheduleTemplateSelect" name="template_id" required>
                        <option value=""><?= htmlspecialchars(t('schedule_select_template'), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php foreach ($scheduleTemplates as $template): ?>
                        <option value="<?= (int)$template['id'] ?>">
                            <?= htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div id="scheduleQuickMessage" class="schedule-quick-message" role="status" aria-live="polite"></div>
            <div class="schedule-quick-actions">
                <button type="button" class="btn-cancel" onclick="closeScheduleModal()"><?= htmlspecialchars(t('cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="submit" class="btn-save" id="scheduleQuickSave"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>
    </section>
</div>

<!-- ============================================================ -->
<!-- EDIT MODAL -->
<!-- ============================================================ -->
<div id="editModal" class="edit-modal-overlay">
    <div class="edit-modal-box">
        <div class="edit-modal-header">
            <h3 style="margin:0;"><?= t('edit_setting') ?></h3>
            <button class="edit-modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="edit-modal-body">
            <form method="POST" id="editSettingForm" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="update_setting" value="1">
                <input type="hidden" name="setting_id" id="edit_setting_id" value="">
                <input type="hidden" name="month" value="<?= htmlspecialchars($selectedMonth) ?>">
                <input type="hidden" name="page" value="<?= $currentPage ?>">
                <input type="hidden" name="sid" value="<?= htmlspecialchars($sid) ?>">
                
                <div class="edit-panel-inner" style="grid-template-columns:1fr 1fr;">
                    <div>
                        <label><?= t('effective_from') ?> *</label>
                        <input type="date" name="start_date" id="edit_start_date" required>
                    </div>
                </div>
                
                <!-- Edit Modal Salary Types (Checkbox) -->
                <div style="margin-bottom:15px; padding:10px; background:#f8fafc; border-radius:8px;">
    <label style="display:block; margin-bottom:8px; font-weight:600;"><?= t('salary_types') ?>:</label>
    <div style="display:flex; gap:15px; flex-wrap:wrap; align-items:center; padding-left:5px;">
        <label style="display:inline-flex; align-items:center; gap:5px; font-weight:normal; cursor:pointer;">
            <input type="checkbox" name="salary_types[]" value="fixed" id="edit_fixed_check" style="width:16px; height:16px; margin:0;" onchange="toggleEditFields()"> 
            <?= t('fixed') ?>
        </label>
        <label style="display:inline-flex; align-items:center; gap:5px; font-weight:normal; cursor:pointer;">
            <input type="checkbox" name="salary_types[]" value="hourly" id="edit_hourly_check" style="width:16px; height:16px; margin:0;" onchange="toggleEditFields()"> 
            <?= t('hourly') ?>
        </label>
        <label style="display:inline-flex; align-items:center; gap:5px; font-weight:normal; cursor:pointer;">
            <input type="checkbox" name="salary_types[]" value="percent" id="edit_percent_check" style="width:16px; height:16px; margin:0;" onchange="toggleEditFields()"> 
            <?= t('percent') ?>
        </label>
        <label style="display:inline-flex; align-items:center; gap:5px; font-weight:normal; cursor:pointer;">
            <input type="checkbox" name="salary_types[]" value="shift" id="edit_shift_check" style="width:16px; height:16px; margin:0;" onchange="toggleEditFields()">
            <?= t('shift') ?>
        </label>
    </div>
</div>
                
                <div class="edit-panel-inner" style="grid-template-columns:1fr 1fr; margin-top:10px;">
                    <div id="edit_fixed_div">
                        <label><?= t('monthly_salary') ?> (<?= $currencySymbol ?>)</label>
                        <input type="number" name="fixed_salary" id="edit_fixed_salary" step="1" value="0">
                    </div>
                    <div id="edit_hourly_div" style="display:none;">
                        <label><?= t('hourly_rate') ?> (<?= $currencySymbol ?>/<?= t('hour') ?>)</label>
                        <input type="number" name="hourly_rate" id="edit_hourly_rate" step="1" value="0">
                    </div>
                    <div id="edit_percent_div" style="display:none;">
                        <label><?= t('percent_value') ?> (%)</label>
                        <input type="number" name="percent_value" id="edit_percent_value" step="0.1" value="0">
                    </div>
                    <div id="edit_shift_div" style="display:none;">
                        <label><?= t('shift_amount') ?> (<?= $currencySymbol ?>/<?= t('shift') ?>)</label>
                        <input type="number" name="smena_summa" id="edit_smena_summa" min="0" step="1" value="0">
                    </div>
                    <div id="edit_waiter_div" style="display:none;">
                        <div id="edit_commission_source_label" style="margin-bottom:8px; color:#475569;"></div>
                        <div id="edit_waiter_select_div">
                        <label><?= t('select_waiter') ?></label>
                        <select name="poster_user_id" id="edit_poster_user_id">
                            <option value="">-- <?= t('select_waiter') ?> --</option>
                            <?php foreach($waiters as $w): ?>
                            <option value="<?= $w['user_id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        </div>
                    </div>
                </div>
                
                <input type="hidden" name="ishga_kelish_vaqti" id="edit_ishga_kelish">
                <input type="hidden" name="ishdan_ketish_vaqti" id="edit_ishdan_ketish">
                <input type="hidden" name="tushlik_boshlanish" id="edit_tushlik_boshlanish">
                <input type="hidden" name="tushlik_tugash" id="edit_tushlik_tugash">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin:10px 0;">
                    <div class="penalty-settings" style="margin:0; padding:10px;">
                        <div class="penalty-title" style="font-size:12px; font-weight:600;"><?= t('late_arrival_penalty') ?></div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="kech_kelish_jarima_enabled" id="edit_kech_kelish_enabled" value="1">
                            <label><?= t('enable') ?></label>
                        </div>
                        <div class="penalty-row" style="display:flex; align-items:center; gap:5px; margin:3px 0;">
                            <label style="min-width:50px; font-size:11px;"><?= t('grace') ?>:</label>
                            <input type="number" name="kech_kelish_grace_minutes" id="edit_kech_kelish_grace" style="width:50px; padding:3px 5px;" value="10">
                            <span style="font-size:10px; color:#64748b;">min</span>
                        </div>
                        <div class="penalty-row" style="display:flex; align-items:center; gap:5px; margin:3px 0;">
                            <label style="min-width:50px; font-size:11px;">min:</label>
                            <input type="number" name="kech_kelish_jarima_per_minute" id="edit_kech_kelish_per_minute" style="width:70px; padding:3px 5px;" value="1000">
                            <span style="font-size:10px; color:#64748b;"><?= $currencySymbol ?></span>
                        </div>
                        <div class="penalty-row" style="display:flex; align-items:center; gap:5px; margin:3px 0;">
                            <label style="min-width:50px; font-size:11px;"><?= t('max_penalty') ?>:</label>
                            <input type="number" name="kech_kelish_max_jarima" id="edit_kech_kelish_max_jarima" style="width:70px; padding:3px 5px;" value="50000">
                            <span style="font-size:10px; color:#64748b;"><?= $currencySymbol ?></span>
                        </div>
                    </div>
                    
                    <div class="penalty-settings" style="margin:0; padding:10px;">
                        <div class="penalty-title" style="font-size:12px; font-weight:600;"><?= t('early_departure_penalty') ?></div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="erta_ketish_jarima_enabled" id="edit_erta_ketish_enabled" value="1">
                            <label><?= t('enable') ?></label>
                        </div>
                        <div class="penalty-row" style="display:flex; align-items:center; gap:5px; margin:3px 0;">
                            <label style="min-width:50px; font-size:11px;"><?= t('grace') ?>:</label>
                            <input type="number" name="erta_ketish_grace_minutes" id="edit_erta_ketish_grace" style="width:50px; padding:3px 5px;" value="10">
                            <span style="font-size:10px; color:#64748b;">min</span>
                        </div>
                        <div class="penalty-row" style="display:flex; align-items:center; gap:5px; margin:3px 0;">
                            <label style="min-width:50px; font-size:11px;">min:</label>
                            <input type="number" name="erta_ketish_jarima_per_minute" id="edit_erta_ketish_per_minute" style="width:70px; padding:3px 5px;" value="1000">
                            <span style="font-size:10px; color:#64748b;"><?= $currencySymbol ?></span>
                        </div>
                        <div class="penalty-row" style="display:flex; align-items:center; gap:5px; margin:3px 0;">
                            <label style="min-width:50px; font-size:11px;"><?= t('max_penalty') ?>:</label>
                            <input type="number" name="erta_ketish_max_jarima" id="edit_erta_ketish_max_jarima" style="width:70px; padding:3px 5px;" value="50000">
                            <span style="font-size:10px; color:#64748b;"><?= $currencySymbol ?></span>
                        </div>
                    </div>
                    
                    <div class="bonus-settings" style="margin:0; padding:10px;">
                        <div class="bonus-title" style="font-size:12px; font-weight:600;"><?= t('early_arrival_bonus') ?></div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="erta_kelish_bonus_enabled" id="edit_erta_kelish_bonus_enabled" value="1">
                            <label><?= t('enable') ?></label>
                        </div>
                        <div class="bonus-row" style="display:flex; align-items:center; gap:5px; margin:3px 0;">
                            <label style="min-width:50px; font-size:11px;">min:</label>
                            <input type="number" name="erta_kelish_bonus_per_minute" id="edit_erta_kelish_bonus_per_minute" style="width:70px; padding:3px 5px;" value="1000">
                            <span style="font-size:10px; color:#64748b;"><?= $currencySymbol ?></span>
                        </div>
                        <div class="bonus-row" style="display:flex; align-items:center; gap:5px; margin:3px 0;">
                            <label style="min-width:50px; font-size:11px;"><?= t('max_bonus') ?>:</label>
                            <input type="number" name="erta_kelish_max_bonus" id="edit_erta_kelish_max_bonus" style="width:70px; padding:3px 5px;" value="50000">
                            <span style="font-size:10px; color:#64748b;"><?= $currencySymbol ?></span>
                        </div>
                    </div>
                    
                    <div class="bonus-settings" style="margin:0; padding:10px;">
                        <div class="bonus-title" style="font-size:12px; font-weight:600;"><?= t('late_departure_bonus') ?></div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="kech_ketish_bonus_enabled" id="edit_kech_ketish_bonus_enabled" value="1">
                            <label><?= t('enable') ?></label>
                        </div>
                        <div class="bonus-row" style="display:flex; align-items:center; gap:5px; margin:3px 0;">
                            <label style="min-width:50px; font-size:11px;">min:</label>
                            <input type="number" name="kech_ketish_bonus_per_minute" id="edit_kech_ketish_bonus_per_minute" style="width:70px; padding:3px 5px;" value="1000">
                            <span style="font-size:10px; color:#64748b;"><?= $currencySymbol ?></span>
                        </div>
                        <div class="bonus-row" style="display:flex; align-items:center; gap:5px; margin:3px 0;">
                            <label style="min-width:50px; font-size:11px;"><?= t('max_bonus') ?>:</label>
                            <input type="number" name="kech_ketish_max_bonus" id="edit_kech_ketish_max_bonus" style="width:70px; padding:3px 5px;" value="50000">
                            <span style="font-size:10px; color:#64748b;"><?= $currencySymbol ?></span>
                        </div>
                    </div>
                </div>
                
                <div style="display:flex; gap:10px; margin-top:15px; padding-top:10px; border-top:1px solid #e2e8f0;">
                    <button type="submit" class="btn-save"><?= t('update') ?></button>
                    <button type="button" class="btn-cancel" onclick="closeEditModal()"><?= t('cancel') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- JAVASCRIPT -->
<!-- ============================================================ -->
<script>
// ============================================================
// SETTINGS DATA (Edit modal uchun) — barcha xodimlarning
// oylik_sozlamalar tarixi PHP dan JS ga uzatiladi
// ============================================================
var settingsData = {};
var quickScheduleTranslations = <?= json_encode([
    'effectiveFrom' => t('schedule_effective_from'),
    'saving' => t('schedule_saving'),
    'saved' => t('schedule_saved'),
    'saveFailed' => t('schedule_save_failed'),
    'selectTemplate' => t('schedule_select_template'),
    'selectedDays' => t('schedule_selected_days'),
    'selectDays' => t('schedule_select_days'),
    'bulkSaved' => t('schedule_bulk_saved'),
    'applySelected' => t('schedule_apply_selected'),
    'save' => t('save'),
    'notFound' => t('schedule_not_found'),
    'recalculationFailed' => t('schedule_recalculation_failed'),
    'loadFailed' => t('schedule_load_failed'),
    'locale' => [
        'uz' => 'uz-UZ',
        'ru' => 'ru-RU',
        'en' => 'en-US',
        'ua' => 'uk-UA',
        'es' => 'es-ES',
        'ar' => 'ar',
        'et' => 'et-EE',
        'az' => 'az-AZ',
        'kk' => 'kk-KZ',
        'be' => 'be-BY',
    ][$currentLang] ?? 'uz-UZ',
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
var quickScheduleToday = <?= json_encode(date('Y-m-d')) ?>;
var quickScheduleCalendarMonth = new Date(Number(quickScheduleToday.substring(0, 4)), Number(quickScheduleToday.substring(5, 7)) - 1, 1);
var quickScheduleHistory = [];
var quickScheduleTemplates = <?= json_encode($scheduleTemplates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
var quickScheduleSelectedTemplateId = '';
var quickScheduleSelectedDates = {};
var quickScheduleLoadSequence = 0;
<?php foreach($employees as $emp): ?>
    <?php foreach($emp['settings'] as $s): ?>
    settingsData[<?= (int)$s['id'] ?>] = <?= json_encode([
        'id'                              => (int)$s['id'],
        'employee_id'                     => (int)$emp['id'],
        'amal_qiladi'                     => $s['amal_qiladi'],
        'maosh_turi'                      => $s['maosh_turi'],
        'oylik_maosh'                     => (float)($s['oylik_maosh'] ?? 0),
        'soatlik_stavka'                  => (float)($s['soatlik_stavka'] ?? 0),
        'smena_summa'                     => (float)($s['smena_summa'] ?? 0),
        'foiz_qiymati'                    => (float)($s['foiz_qiymati'] ?? 0),
        'poster_user_id'                  => $s['poster_user_id'] ?? null,
        'ishga_kelish_vaqti'              => $s['ishga_kelish_vaqti'] ?? '09:00:00',
        'ishdan_ketish_vaqti'             => $s['ishdan_ketish_vaqti'] ?? '18:00:00',
        'tushlik_boshlanish'              => $s['tushlik_boshlanish'] ?? '13:00:00',
        'tushlik_tugash'                  => $s['tushlik_tugash'] ?? '14:00:00',
        'kech_kelish_jarima_enabled'      => (int)($s['kech_kelish_jarima_enabled'] ?? 1),
        'kech_kelish_grace_minutes'       => (int)($s['kech_kelish_grace_minutes'] ?? 10),
        'kech_kelish_jarima_per_minute'   => (float)($s['kech_kelish_jarima_per_minute'] ?? 1000),
        'kech_kelish_max_jarima'          => (float)($s['kech_kelish_max_jarima'] ?? 50000),
        'erta_ketish_jarima_enabled'      => (int)($s['erta_ketish_jarima_enabled'] ?? 1),
        'erta_ketish_grace_minutes'       => (int)($s['erta_ketish_grace_minutes'] ?? 10),
        'erta_ketish_jarima_per_minute'   => (float)($s['erta_ketish_jarima_per_minute'] ?? 1000),
        'erta_ketish_max_jarima'          => (float)($s['erta_ketish_max_jarima'] ?? 50000),
        'erta_kelish_bonus_enabled'       => (int)($s['erta_kelish_bonus_enabled'] ?? 1),
        'erta_kelish_bonus_amount'        => (float)($s['erta_kelish_bonus_amount'] ?? 1000),
        'erta_kelish_max_bonus'           => (float)($s['erta_kelish_max_bonus'] ?? 50000),
        'kech_ketish_bonus_enabled'       => (int)($s['kech_ketish_bonus_enabled'] ?? 1),
        'kech_ketish_bonus_amount'        => (float)($s['kech_ketish_bonus_amount'] ?? 1000),
        'kech_ketish_max_bonus'           => (float)($s['kech_ketish_max_bonus'] ?? 50000),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    <?php endforeach; ?>
<?php endforeach; ?>

// ============================================================
// TRANSLATIONS OBJECT - language.php dan json_encode bilan
// ============================================================
var translations = <?= json_encode([
    // Xodim ma'lumotlari
    'employee_info' => t('employee_info'),
    'name' => t('name'),
    'active' => t('active'),
    'inactive' => t('inactive'),
    'deleted' => t('deleted'),
    'created_at' => t('created_at'),
    'update' => t('update'),
    'status' => t('status'),
    
    // Ish jadvali
    'work_schedule' => t('work_schedule'),
    'check_in' => t('check_in'),
    'check_out' => t('check_out'),
    'lunch_start' => t('lunch_start'),
    'lunch_end' => t('lunch_end'),
    'valid_from' => t('valid_from'),
    
    // Maosh
    'salary' => t('salary'),
    'salary_type' => t('salary_type'),
    'salary_amount' => t('salary_amount'),
    'calculated_salary' => t('calculated_salary'),
    'worked_days' => t('worked_days'),
    'worked_hours' => t('worked_hours'),
    'total_penalty' => t('total_penalty'),
    'total_bonus' => t('total_bonus'),
    'net_bonus' => t('net_bonus'),
    'fixed' => t('fixed'),
    'hourly' => t('hourly'),
    'percent' => t('percent'),
    'hours' => t('hours'),
    'minutes' => t('minutes'),
    
    // Jarima va bonus
    'penalty_bonus_settings' => t('penalty_bonus_settings'),
    'enabled' => t('enabled'),
    'disabled' => t('disabled'),
    'grace' => t('grace'),
    'min_per_minute' => t('min_per_minute'),
    'max_penalty' => t('max_penalty'),
    'max_bonus' => t('max_bonus'),
    'late_arrival' => t('late_arrival'),
    'early_departure' => t('early_departure'),
    'early_arrival_bonus' => t('early_arrival_bonus'),
    'late_departure_bonus' => t('late_departure_bonus'),
    
    // Oxirgi kelish
    'last_attendance' => t('last_attendance'),
    'date' => t('date'),
    'present' => t('present'),
    'partial_in' => t('partial_in'),
    'partial_out' => t('partial_out'),
    'absent' => t('absent'),
    'half_day' => t('half_day'),
    'overtime' => t('overtime'),
    'vacation' => t('vacation'),
    'sick' => t('sick'),
    'unknown' => t('unknown'),
    
    // Hafta kunlari
    'monday' => t('monday'),
    'tuesday' => t('tuesday'),
    'wednesday' => t('wednesday'),
    'thursday' => t('thursday'),
    'friday' => t('friday'),
    'saturday' => t('saturday'),
    'sunday' => t('sunday'),
    
    // Yuklanish
    'loading' => t('loading'),
    'employee_not_found' => t('employee_not_found'),
    'no_data' => t('no_data'),
    'waiter_sales' => t('waiter_sales'),
    'poster_categories' => t('poster_categories'),
    'commission_sales_source' => t('commission_sales_source'),
    'spot' => t('spot'),
    'unassigned' => t('unassigned'),
    'select_categories' => t('select_categories'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

// ============================================================
// EMPLOYEES DATA
// ============================================================
var employeesData = <?= json_encode($employees, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
var activeEditEmployeeId = null;

// ============================================================
// EDIT PANEL (jadval ichidagi qator - yangi sozlama qo'shish)
// ============================================================
function showEditPanel(id) {
    document.querySelectorAll('[id^="edit_panel_"]').forEach(function(el) {
        el.style.display = "none";
    });
    var panel = document.getElementById("edit_panel_" + id);
    if (panel) {
        panel.style.display = "table-row";
        panel.scrollIntoView({ behavior: "smooth", block: "center" });
    }
}

function hideEditPanel(id) {
    var panel = document.getElementById("edit_panel_" + id);
    if (panel) {
        panel.style.display = "none";
    }
}

// ============================================================
// TOGGLE SALARY FIELDS
// ============================================================
function toggleSalaryFields(id) {
    var panel = document.getElementById("edit_panel_" + id);
    if (!panel) return;

    var checkboxes = panel.querySelectorAll('input[name="salary_types[]"]');
    var fixedDiv = document.getElementById('fixed_div_' + id);
    var hourlyDiv = document.getElementById('hourly_div_' + id);
    var percentDiv = document.getElementById('percent_div_' + id);
    var shiftDiv = document.getElementById('shift_div_' + id);
    var commissionSourceDiv = document.getElementById('commission_source_div_' + id);

    var hasFixed = false;
    var hasHourly = false;
    var hasPercent = false;
    var hasShift = false;

    checkboxes.forEach(function(cb) {
        if (cb.checked) {
            if (cb.value == 'fixed') hasFixed = true;
            if (cb.value == 'hourly') hasHourly = true;
            if (cb.value == 'percent') hasPercent = true;
            if (cb.value == 'shift') hasShift = true;
        }
    });

    if (fixedDiv) fixedDiv.style.display = hasFixed ? 'block' : 'none';
    if (hourlyDiv) hourlyDiv.style.display = hasHourly ? 'block' : 'none';
    if (percentDiv) percentDiv.style.display = hasPercent ? 'block' : 'none';
    if (shiftDiv) shiftDiv.style.display = hasShift ? 'block' : 'none';
    if (commissionSourceDiv) commissionSourceDiv.style.display = hasPercent ? 'grid' : 'none';
    updateCommissionSourceUi(id);
}

// ============================================================
// TOGGLE EDIT FIELDS
// ============================================================
function toggleEditFields() {
    var hasFixed = document.getElementById('edit_fixed_check').checked;
    var hasHourly = document.getElementById('edit_hourly_check').checked;
    var hasPercent = document.getElementById('edit_percent_check').checked;
    var hasShift = document.getElementById('edit_shift_check').checked;

    var fixedDiv = document.getElementById('edit_fixed_div');
    var hourlyDiv = document.getElementById('edit_hourly_div');
    var percentDiv = document.getElementById('edit_percent_div');
    var shiftDiv = document.getElementById('edit_shift_div');
    var waiterDiv = document.getElementById('edit_waiter_div');

    if (fixedDiv) fixedDiv.style.display = hasFixed ? 'block' : 'none';
    if (hourlyDiv) hourlyDiv.style.display = hasHourly ? 'block' : 'none';
    if (percentDiv) percentDiv.style.display = hasPercent ? 'block' : 'none';
    if (shiftDiv) shiftDiv.style.display = hasShift ? 'block' : 'none';
    if (waiterDiv) waiterDiv.style.display = hasPercent ? 'block' : 'none';
    updateEditCommissionSourceUi(activeEditEmployeeId);
}

function updateCommissionSourceUi(id) {
    var panel = document.getElementById('edit_panel_' + id);
    var positionSelect = document.getElementById('employee_position_' + id);
    var sourceLabel = document.getElementById('commission_source_label_' + id);
    var waiterSelectDiv = document.getElementById('waiter_select_div_' + id);
    var waiterSelect = waiterSelectDiv ? waiterSelectDiv.querySelector('select[name="poster_user_id"]') : null;
    var spotDiv = document.getElementById('employee_spots_div_' + id);
    var spotSelect = spotDiv ? spotDiv.querySelector('select[name="poster_spot_id"]') : null;
    var categoryDiv = document.getElementById('employee_categories_div_' + id);
    var categoryInputs = categoryDiv ? categoryDiv.querySelectorAll('input[name="poster_category_ids[]"]') : [];
    if (!panel || !sourceLabel || !waiterSelectDiv) return;
    var percentCheckbox = panel.querySelector('input[name="salary_types[]"][value="percent"]');
    var hasPercent = !!(percentCheckbox && percentCheckbox.checked);

    var selectedOption = positionSelect && positionSelect.selectedOptions.length
        ? positionSelect.selectedOptions[0]
        : null;
    var sourceType = selectedOption ? selectedOption.dataset.sourceType : 'waiter';
    var sourceName = selectedOption ? selectedOption.dataset.sourceLabel : translations.waiter_sales;
    sourceType = sourceType || 'waiter';
    sourceName = sourceName || translations.waiter_sales;
    var commissionSourceDiv = document.getElementById('commission_source_div_' + id);
    if (commissionSourceDiv) commissionSourceDiv.classList.toggle('category-source-layout', sourceType === 'category');
    if (sourceType === 'institution') {
        var selectedSpot = spotSelect && spotSelect.selectedOptions.length
            ? spotSelect.selectedOptions[0].textContent
            : '';
        sourceName = translations.spot + ': ' + (selectedSpot && spotSelect.value !== '0' ? selectedSpot : translations.unassigned);
    } else if (sourceType === 'category') {
        sourceName = translations.poster_categories;
    }
    if (categoryDiv) {
        updateEmployeeCategoryPicker(categoryDiv.querySelector('.employee-category-picker'));
    }

    sourceLabel.textContent = translations.commission_sales_source + ': ' + sourceName;
    waiterSelectDiv.style.display = sourceType === 'waiter' ? 'block' : 'none';
    if (waiterSelect) waiterSelect.disabled = !hasPercent || sourceType !== 'waiter';
    if (spotDiv) spotDiv.style.display = hasPercent && sourceType === 'institution' ? 'block' : 'none';
    if (spotSelect) spotSelect.disabled = !hasPercent || sourceType !== 'institution';
    if (categoryDiv) categoryDiv.style.display = hasPercent && sourceType === 'category' ? 'block' : 'none';
    categoryInputs.forEach(function(input) {
        input.disabled = !hasPercent || sourceType !== 'category';
        input.closest('.employee-category-option').classList.toggle('selected', input.checked);
    });
}

function updateEmployeeCategoryPicker(picker) {
    if (!picker) return;
    var selectedLabels = Array.from(picker.querySelectorAll('input[name="poster_category_ids[]"]:checked'))
        .map(function(input) { return input.nextElementSibling.textContent.trim(); });
    var selection = picker.querySelector('.employee-category-selection');
    if (selection) {
        var summary = selectedLabels.length
            ? selectedLabels.join(', ')
            : translations.select_categories;
        selection.textContent = summary;
        picker.querySelector('.employee-category-trigger').title = summary;
    }
}

function closeEmployeeCategoryMenus(exceptMenu) {
    document.querySelectorAll('.employee-category-menu.open').forEach(function(menu) {
        if (menu === exceptMenu) return;
        menu.classList.remove('open');
        var trigger = menu.parentElement.querySelector('.employee-category-trigger');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
    });
}

function updateEditCommissionSourceUi(employeeId) {
    var sourceLabel = document.getElementById('edit_commission_source_label');
    var waiterSelectDiv = document.getElementById('edit_waiter_select_div');
    var waiterSelect = document.getElementById('edit_poster_user_id');
    if (!sourceLabel || !waiterSelectDiv) return;

    var employee = Array.isArray(employeesData)
        ? employeesData.find(function(item) { return String(item.id) === String(employeeId); })
        : null;
    var sourceType = employee ? (employee.position_commission_source_type || 'waiter') : 'waiter';
    var sourceName = translations.waiter_sales;
    if (employee && sourceType === 'institution') {
        sourceName = translations.spot + ': ' + (employee.institution_name || translations.unassigned);
    } else if (employee && sourceType === 'category') {
        sourceName = translations.poster_categories;
    }

    sourceLabel.textContent = translations.commission_sales_source + ': ' + sourceName;
    waiterSelectDiv.style.display = sourceType === 'waiter' ? 'block' : 'none';
    if (waiterSelect) waiterSelect.disabled = sourceType !== 'waiter';
}

// ============================================================
// HISTORY TOGGLE
// ============================================================
function toggleHistory(empId) {
    var content = document.getElementById("history_content_" + empId);
    var toggle = document.getElementById("history_toggle_" + empId);
    
    if (!content || !toggle) return;
    
    if (content.classList.contains("open")) {
        content.classList.remove("open");
        content.style.maxHeight = "0";
        toggle.textContent = "▶";
        toggle.style.color = "#64748b";
    } else {
        content.classList.add("open");
        content.style.maxHeight = content.scrollHeight + "px";
        toggle.textContent = "▼";
        toggle.style.color = "#1e293b";
    }
}

// ============================================================
// DOM READY
// ============================================================
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll('.employee-category-picker').forEach(function(picker) {
        var trigger = picker.querySelector('.employee-category-trigger');
        var menu = picker.querySelector('.employee-category-menu');
        var search = picker.querySelector('.employee-category-search');
        var options = Array.from(picker.querySelectorAll('.employee-category-option'));
        var selectAll = picker.querySelector('.employee-category-select-all');
        var clear = picker.querySelector('.employee-category-clear');

        updateEmployeeCategoryPicker(picker);
        if (trigger && menu) {
            trigger.addEventListener('click', function() {
                var shouldOpen = !menu.classList.contains('open');
                closeEmployeeCategoryMenus(menu);
                menu.classList.toggle('open', shouldOpen);
                trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
                if (shouldOpen && search) search.focus();
            });
        }
        if (search) {
            search.addEventListener('input', function() {
                var query = search.value.trim().toLocaleLowerCase();
                options.forEach(function(option) {
                    option.style.display = option.textContent.trim().toLocaleLowerCase().includes(query) ? '' : 'none';
                });
            });
        }
        if (selectAll) {
            selectAll.addEventListener('click', function() {
                options.forEach(function(option) {
                    var checkbox = option.querySelector('input[name="poster_category_ids[]"]');
                    if (checkbox && !checkbox.disabled) checkbox.checked = true;
                });
                updateEmployeeCategoryPicker(picker);
                var employeeId = picker.closest('[id^="employee_categories_div_"]').id.replace('employee_categories_div_', '');
                updateCommissionSourceUi(employeeId);
            });
        }
        if (clear) {
            clear.addEventListener('click', function() {
                options.forEach(function(option) {
                    var checkbox = option.querySelector('input[name="poster_category_ids[]"]');
                    if (checkbox && !checkbox.disabled) checkbox.checked = false;
                });
                updateEmployeeCategoryPicker(picker);
                var employeeId = picker.closest('[id^="employee_categories_div_"]').id.replace('employee_categories_div_', '');
                updateCommissionSourceUi(employeeId);
            });
        }
    });
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.employee-category-picker')) closeEmployeeCategoryMenus(null);
    });
    document.querySelectorAll('select[id^="employee_position_"]').forEach(function(select) {
        updateCommissionSourceUi(select.id.replace('employee_position_', ''));
    });
    document.querySelectorAll('.settings-list').forEach(function(list) {
        var items = list.querySelectorAll('.setting-item');
        if (items.length === 0) {
            var emptyMsg = document.createElement('div');
            emptyMsg.style.cssText = 'text-align:center; padding:15px; color:#94a3b8; font-size:13px;';
            emptyMsg.textContent = '<?= t('no_settings_for_this_month') ?>';
            list.querySelector('.history-content').appendChild(emptyMsg);
        }
    });
});

// ============================================================
// OPEN EDIT MODAL
// ============================================================
function openEditModal(settingId) {
    var data = settingsData[settingId];
    if (!data) {
        alert('Xatolik: Sozlama topilmadi!');
        return;
    }

    document.getElementById("editModal").style.display = "flex";
    activeEditEmployeeId = data.employee_id;
    updateEditCommissionSourceUi(activeEditEmployeeId);

    document.getElementById("edit_setting_id").value = data.id;
    document.getElementById("edit_start_date").value = data.amal_qiladi;

    var maoshTurlari = [];
    try {
        maoshTurlari = JSON.parse(data.maosh_turi);
    } catch (e) {
        maoshTurlari = [data.maosh_turi || 'fixed'];
    }
    if (!Array.isArray(maoshTurlari)) {
        maoshTurlari = [maoshTurlari];
    }

    document.getElementById('edit_fixed_check').checked = maoshTurlari.indexOf('fixed') !== -1;
    document.getElementById('edit_hourly_check').checked = maoshTurlari.indexOf('hourly') !== -1;
    document.getElementById('edit_percent_check').checked = maoshTurlari.indexOf('percent') !== -1;
    document.getElementById('edit_shift_check').checked = maoshTurlari.indexOf('shift') !== -1;

    document.getElementById("edit_fixed_salary").value = data.oylik_maosh;
    document.getElementById("edit_hourly_rate").value = data.soatlik_stavka;
    document.getElementById("edit_percent_value").value = data.foiz_qiymati;
    document.getElementById("edit_smena_summa").value = data.smena_summa || 0;
    document.getElementById("edit_poster_user_id").value = data.poster_user_id || "";

    document.getElementById("edit_ishga_kelish").value = (data.ishga_kelish_vaqti || "09:00:00").substring(0, 5);
    document.getElementById("edit_ishdan_ketish").value = (data.ishdan_ketish_vaqti || "18:00:00").substring(0, 5);
    document.getElementById("edit_tushlik_boshlanish").value = (data.tushlik_boshlanish || "13:00:00").substring(0, 5);
    document.getElementById("edit_tushlik_tugash").value = (data.tushlik_tugash || "14:00:00").substring(0, 5);

    document.getElementById("edit_kech_kelish_enabled").checked = data.kech_kelish_jarima_enabled == 1;
    document.getElementById("edit_kech_kelish_grace").value = data.kech_kelish_grace_minutes;
    document.getElementById("edit_kech_kelish_per_minute").value = data.kech_kelish_jarima_per_minute;
    document.getElementById("edit_kech_kelish_max_jarima").value = data.kech_kelish_max_jarima;

    document.getElementById("edit_erta_ketish_enabled").checked = data.erta_ketish_jarima_enabled == 1;
    document.getElementById("edit_erta_ketish_grace").value = data.erta_ketish_grace_minutes;
    document.getElementById("edit_erta_ketish_per_minute").value = data.erta_ketish_jarima_per_minute;
    document.getElementById("edit_erta_ketish_max_jarima").value = data.erta_ketish_max_jarima;

    document.getElementById("edit_erta_kelish_bonus_enabled").checked = data.erta_kelish_bonus_enabled == 1;
    document.getElementById("edit_erta_kelish_bonus_per_minute").value = data.erta_kelish_bonus_amount;
    document.getElementById("edit_erta_kelish_max_bonus").value = data.erta_kelish_max_bonus;

    document.getElementById("edit_kech_ketish_bonus_enabled").checked = data.kech_ketish_bonus_enabled == 1;
    document.getElementById("edit_kech_ketish_bonus_per_minute").value = data.kech_ketish_bonus_amount;
    document.getElementById("edit_kech_ketish_max_bonus").value = data.kech_ketish_max_bonus;

    toggleEditFields();
}

function closeEditModal() {
    document.getElementById("editModal").style.display = "none";
}

function updateScheduleSelectionUi() {
    var multiSelect = document.getElementById('scheduleMultiSelect').checked;
    var dates = Object.keys(quickScheduleSelectedDates);
    document.getElementById('scheduleSelectedCount').textContent =
        multiSelect ? quickScheduleTranslations.selectedDays + dates.length : '';
    document.getElementById('scheduleQuickSave').textContent = multiSelect
        ? quickScheduleTranslations.applySelected
        : quickScheduleTranslations.save;
}

function toggleScheduleDateSelection(date) {
    if (quickScheduleSelectedDates[date]) {
        delete quickScheduleSelectedDates[date];
    } else {
        quickScheduleSelectedDates[date] = true;
    }
    updateScheduleSelectionUi();
    renderScheduleCalendar();
}

function formatScheduleDate(year, month, day) {
    return year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
}

function scheduleShiftName(schedule) {
    if (!schedule) {
        return '';
    }
    if (schedule.name) {
        return schedule.name;
    }
    var match = quickScheduleTemplates.find(function(template) {
        return ['ishga_kelish_vaqti', 'ishdan_ketish_vaqti', 'tushlik_boshlanish', 'tushlik_tugash'].every(function(key) {
            return String(template[key] || '').substring(0, 5) === String(schedule[key] || '').substring(0, 5);
        });
    });
    return match ? match.name : '';
}

function renderScheduleCalendar() {
    var monthLabel = document.getElementById('scheduleCalendarMonth');
    var weekdays = document.getElementById('scheduleCalendarWeekdays');
    var days = document.getElementById('scheduleCalendarDays');
    var year = quickScheduleCalendarMonth.getFullYear();
    var month = quickScheduleCalendarMonth.getMonth();
    var locale = quickScheduleTranslations.locale;
    var monthFormatter = new Intl.DateTimeFormat(locale, {month: 'long', year: 'numeric'});
    monthLabel.textContent = monthFormatter.format(quickScheduleCalendarMonth);
    weekdays.replaceChildren();
    days.replaceChildren();

    var weekdayFormatter = new Intl.DateTimeFormat(locale, {weekday: 'short', timeZone: 'UTC'});
    for (var weekday = 0; weekday < 7; weekday++) {
        var weekdayDate = new Date(Date.UTC(2024, 0, 1 + weekday));
        var weekdayCell = document.createElement('span');
        weekdayCell.textContent = weekdayFormatter.format(weekdayDate);
        weekdays.appendChild(weekdayCell);
    }

    var firstWeekday = (new Date(year, month, 1).getDay() + 6) % 7;
    var daysInMonth = new Date(year, month + 1, 0).getDate();
    var selectedDate = document.getElementById('quick_schedule_date').value;
    var isMultiSelect = document.getElementById('scheduleMultiSelect').checked;
    var selectedTemplate = quickScheduleTemplates.find(function(template) {
        return String(template.id) === quickScheduleSelectedTemplateId;
    });
    var sortedHistory = quickScheduleHistory.slice().sort(function(left, right) {
        return left.amal_qiladi.localeCompare(right.amal_qiladi);
    });

    for (var blank = 0; blank < firstWeekday; blank++) {
        var emptyCell = document.createElement('span');
        emptyCell.className = 'schedule-calendar-empty';
        emptyCell.setAttribute('aria-hidden', 'true');
        days.appendChild(emptyCell);
    }

    for (var day = 1; day <= daysInMonth; day++) {
        var date = formatScheduleDate(year, month, day);
        var schedule = null;
        var dayEntry = null;
        sortedHistory.forEach(function(entry) {
            if (entry.source === 'day') {
                if (entry.amal_qiladi === date) {
                    dayEntry = entry;
                }
            } else if (entry.amal_qiladi <= date) {
                schedule = entry;
            }
        });
        if (dayEntry) {
            schedule = dayEntry;
        }
        var isTemplatePreview = !!selectedTemplate && (
            isMultiSelect ? !!quickScheduleSelectedDates[date] : date === selectedDate
        );
        if (isTemplatePreview) {
            schedule = selectedTemplate;
        }
        var dayButton = document.createElement('button');
        dayButton.type = 'button';
        dayButton.className = 'schedule-calendar-day';
        dayButton.setAttribute('role', 'gridcell');
        if (isTemplatePreview) {
            dayButton.classList.add('has-template-preview');
            dayButton.title = selectedTemplate.name;
        }
        var isMultiSelected = !!quickScheduleSelectedDates[date];
        dayButton.setAttribute('aria-pressed', (isMultiSelected || (!document.getElementById('scheduleMultiSelect').checked && date === selectedDate)) ? 'true' : 'false');
        dayButton.setAttribute('aria-label', date + (schedule ? ', ' +
            schedule.ishga_kelish_vaqti.substring(0, 5) + '-' +
            schedule.ishdan_ketish_vaqti.substring(0, 5) : ''));
        if (isMultiSelected) {
            dayButton.classList.add('is-multi-selected');
        } else if (!document.getElementById('scheduleMultiSelect').checked && date === selectedDate) {
            dayButton.classList.add('is-selected');
        }
        if (date === quickScheduleToday) {
            dayButton.classList.add('is-today');
        }
        if (new Date(year, month, day).getDay() === 0 || new Date(year, month, day).getDay() === 6) {
            dayButton.classList.add('is-weekend');
        }

        var number = document.createElement('span');
        number.className = 'schedule-calendar-number';
        number.textContent = String(day);
        dayButton.appendChild(number);
        if (schedule) {
            var shiftName = scheduleShiftName(schedule);
            if (shiftName) {
                var nameEl = document.createElement('strong');
                nameEl.className = 'schedule-calendar-shift';
                nameEl.textContent = shiftName;
                dayButton.appendChild(nameEl);
                dayButton.title = shiftName;
            }
            dayButton.classList.add(schedule.amal_qiladi === date ? 'has-schedule' : 'has-inherited-schedule');
        }
        dayButton.addEventListener('click', function(selected) {
            return function() {
                if (document.getElementById('scheduleMultiSelect').checked) {
                    toggleScheduleDateSelection(selected);
                } else {
                    selectScheduleDate(selected);
                }
            };
        }(date));
        days.appendChild(dayButton);
    }
}

function selectScheduleDate(date) {
    var selected = new Date(date + 'T00:00:00');
    quickScheduleCalendarMonth = new Date(selected.getFullYear(), selected.getMonth(), 1);
    document.getElementById('quick_schedule_date').value = date;
    document.getElementById('scheduleQuickEffectiveDate').textContent =
        quickScheduleTranslations.effectiveFrom + ': ' + date;
    document.getElementById('scheduleQuickMessage').textContent = '';
    document.getElementById('scheduleQuickMessage').className = 'schedule-quick-message';
    renderScheduleCalendar();
    loadScheduleForDate(date).catch(function(error) {
        var message = document.getElementById('scheduleQuickMessage');
        message.textContent = error.message || quickScheduleTranslations.loadFailed;
        message.className = 'schedule-quick-message is-error';
    });
}

function loadScheduleForDate(date) {
    var requestSequence = ++quickScheduleLoadSequence;
    var formData = new FormData();
    formData.append('action', 'load_schedule');
    formData.append('csrf_token', document.querySelector('#scheduleQuickForm [name="csrf_token"]').value);
    formData.append('employee_id', document.getElementById('quick_schedule_employee_id').value);
    formData.append('effective_date', date);
    formData.append('sid', document.querySelector('#scheduleQuickForm [name="sid"]').value);

    return fetch(window.location.href, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    }).then(function(response) {
        return response.json().then(function(data) {
            if (!response.ok || !data.success) {
                throw new Error(data.message || quickScheduleTranslations.loadFailed);
            }
            return data;
        });
    }).then(function(data) {
        if (requestSequence !== quickScheduleLoadSequence) {
            return data;
        }
        quickScheduleHistory = data.history || [];
        renderScheduleCalendar();
        if (date === document.getElementById('quick_schedule_date').value && data.schedule) {
            var s = data.schedule;
            var headerShift = scheduleShiftName(s);
            document.getElementById('scheduleQuickEffectiveDate').textContent =
                quickScheduleTranslations.effectiveFrom + ': ' + date + ' · ' +
                (headerShift ? headerShift + ' · ' : '') +
                s.ishga_kelish_vaqti.substring(0, 5) + '–' + s.ishdan_ketish_vaqti.substring(0, 5) +
                ' (' + s.tushlik_boshlanish.substring(0, 5) + '–' + s.tushlik_tugash.substring(0, 5) + ')';
        }
        return data;
    });
}

function openScheduleModal(employeeId, date) {
    document.getElementById('quick_schedule_employee_id').value = employeeId;
    var selectedDate = date || quickScheduleToday;
    document.getElementById('quick_schedule_date').value = selectedDate;
    var selected = new Date(selectedDate + 'T00:00:00');
    quickScheduleCalendarMonth = new Date(selected.getFullYear(), selected.getMonth(), 1);
    document.getElementById('scheduleQuickEffectiveDate').textContent =
        quickScheduleTranslations.effectiveFrom + ': ' + selectedDate;
    quickScheduleHistory = [];
    quickScheduleSelectedDates = {};
    document.getElementById('scheduleMultiSelect').checked = false;
    quickScheduleSelectedTemplateId = '';
    document.getElementById('scheduleTemplateSelect').value = '';
    updateScheduleSelectionUi();
    document.getElementById('scheduleQuickMessage').textContent = '';
    document.getElementById('scheduleQuickMessage').className = 'schedule-quick-message';
    document.getElementById('scheduleQuickModal').style.display = 'flex';
    document.getElementById('scheduleQuickModal').setAttribute('aria-hidden', 'false');
    renderScheduleCalendar();
    loadScheduleForDate(document.getElementById('quick_schedule_date').value).catch(function(error) {
        document.getElementById('scheduleQuickMessage').textContent = error.message || quickScheduleTranslations.loadFailed;
        document.getElementById('scheduleQuickMessage').className = 'schedule-quick-message is-error';
    });
    document.getElementById('scheduleCalendarDays').focus();
}

function closeScheduleModal() {
    var modal = document.getElementById('scheduleQuickModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function changeScheduleCalendarMonth(offset) {
    quickScheduleCalendarMonth.setMonth(quickScheduleCalendarMonth.getMonth() + offset);
    renderScheduleCalendar();
}

document.getElementById('scheduleCalendarPrev').addEventListener('click', function() {
    changeScheduleCalendarMonth(-1);
});
document.getElementById('scheduleCalendarNext').addEventListener('click', function() {
    changeScheduleCalendarMonth(1);
});
document.getElementById('scheduleMultiSelect').addEventListener('change', function() {
    quickScheduleSelectedDates = {};
    updateScheduleSelectionUi();
    renderScheduleCalendar();
});
document.getElementById('scheduleTemplateSelect').addEventListener('change', function(event) {
    var selectedId = event.currentTarget.value;
    var template = quickScheduleTemplates.find(function(item) {
        return String(item.id) === selectedId;
    });
    quickScheduleSelectedTemplateId = template ? selectedId : '';
    if (!template) {
        renderScheduleCalendar();
        return;
    }
    renderScheduleCalendar();
    document.getElementById('scheduleQuickMessage').textContent = template.name;
    document.getElementById('scheduleQuickMessage').className = 'schedule-quick-message is-success';
});

document.getElementById('scheduleQuickForm').addEventListener('submit', function(event) {
    event.preventDefault();

    var form = event.currentTarget;
    var saveButton = document.getElementById('scheduleQuickSave');
    var message = document.getElementById('scheduleQuickMessage');
    var isBulk = document.getElementById('scheduleMultiSelect').checked;
    var selectedDates = Object.keys(quickScheduleSelectedDates).sort();
    if (!quickScheduleSelectedTemplateId) {
        message.textContent = quickScheduleTranslations.selectTemplate;
        message.className = 'schedule-quick-message is-error';
        document.getElementById('scheduleTemplateSelect').focus();
        return;
    }
    if (isBulk && selectedDates.length === 0) {
        message.textContent = quickScheduleTranslations.selectDays;
        message.className = 'schedule-quick-message is-error';
        return;
    }
    var formData = new FormData(form);
    formData.set('action', isBulk ? 'apply_schedule_bulk' : 'save_schedule');
    if (isBulk) {
        selectedDates.forEach(function(date) {
            formData.append('selected_dates[]', date);
        });
    }
    saveButton.disabled = true;
    message.textContent = quickScheduleTranslations.saving;
    message.className = 'schedule-quick-message';

    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    }).then(function(response) {
        return response.json().then(function(data) {
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Ish jadvalini saqlab bo‘lmadi.');
            }
            return data;
        });
    }).then(function(data) {
        if (isBulk) {
            var firstAppliedDate = data.effective_date || selectedDates[0];
            document.getElementById('quick_schedule_date').value = firstAppliedDate;
            var firstAppliedDateValue = new Date(firstAppliedDate + 'T00:00:00');
            quickScheduleCalendarMonth = new Date(firstAppliedDateValue.getFullYear(), firstAppliedDateValue.getMonth(), 1);
            quickScheduleSelectedDates = {};
            document.getElementById('scheduleMultiSelect').checked = false;
            updateScheduleSelectionUi();
        }
        var recalculationFailed = data.recalculation_message &&
            data.recalculation_message.indexOf('Xatolik:') === 0;
        message.textContent = (isBulk
            ? data.selected_dates.length + ' ' + quickScheduleTranslations.bulkSaved
            : quickScheduleTranslations.saved) +
            (recalculationFailed ? ' ' + quickScheduleTranslations.recalculationFailed : '');
        message.className = recalculationFailed
            ? 'schedule-quick-message is-error'
            : 'schedule-quick-message is-success';

        return Promise.all([
            loadScheduleForDate(document.getElementById('quick_schedule_date').value),
            loadScheduleForDate(quickScheduleToday)
        ]).then(function(results) {
            var todayData = results[1];
            var scheduleBadge = document.getElementById('employee_schedule_' + data.employee_id);
            if (scheduleBadge) {
                scheduleBadge.firstChild.textContent =
                    todayData.schedule.ishga_kelish_vaqti.substring(0, 5) + ' - ' +
                    todayData.schedule.ishdan_ketish_vaqti.substring(0, 5) + ' ';
            }
        }).catch(function(error) {
            message.textContent = quickScheduleTranslations.saved + ' ' +
                (error.message || quickScheduleTranslations.loadFailed);
            message.className = 'schedule-quick-message is-error';
        });
    }).catch(function(error) {
        message.textContent = error.message || 'Saqlashda xatolik yuz berdi.';
        message.className = 'schedule-quick-message is-error';
    }).finally(function() {
        saveButton.disabled = false;
    });
});

// ============================================================
// TOGGLE PANEL
// ============================================================
function openToggle(employeeId) {
    var overlay = document.getElementById("toggleOverlay");
    var panel = document.getElementById("togglePanel");
    var body = document.getElementById("toggleBody");
    var nameSpan = document.getElementById("toggleEmployeeName");

    if (overlay) overlay.classList.add("active");
    if (panel) panel.classList.add("open");

    if (body) {
        body.innerHTML = '<div style="text-align:center; padding:40px; color:#94a3b8;">' + 
            '<div style="display:inline-block; width:30px; height:30px; border:3px solid #e2e8f0; border-top:3px solid #3b82f6; border-radius:50%; animation: spin 1s linear infinite;"></div>' +
            '<div style="margin-top:10px;">' + (translations.loading || 'Yuklanmoqda...') + '</div>' +
            '</div>';
    }

    setTimeout(function() {
        try {
            if (typeof translations === 'undefined') {
                if (body) {
                    body.innerHTML = '<div style="color:#ef4444; text-align:center; padding:40px;">❌ ' + (translations.no_data || 'Til tarjimalari yuklanmadi!') + '</div>';
                }
                return;
            }

            if (typeof employeesData === 'undefined' || !employeesData || employeesData.length === 0) {
                if (body) {
                    body.innerHTML = '<div style="color:#ef4444; text-align:center; padding:40px;">❌ ' + (translations.no_data || 'Xodim ma\'lumotlari yuklanmadi!') + '</div>';
                }
                return;
            }

            var employee = employeesData.find(function(emp) { return emp.id == employeeId; });

            if (employee && body && nameSpan) {
                nameSpan.textContent = employee.ismi || "Xodim";
                
                if (typeof generateEmployeeDetails === 'function') {
                    body.innerHTML = generateEmployeeDetails(employee);
                } else {
                    body.innerHTML = '<div style="color:#ef4444; text-align:center; padding:40px;">❌ generateEmployeeDetails funksiyasi topilmadi!</div>';
                }
            } else if (body) {
                body.innerHTML = '<div style="color:#ef4444; text-align:center; padding:40px;">❌ ' + (translations.employee_not_found || 'Xodim topilmadi') + ' (ID: ' + employeeId + ')</div>';
            }
        } catch (e) {
            console.error('openToggle error:', e);
            if (body) {
                body.innerHTML = '<div style="color:#ef4444; text-align:center; padding:40px;">❌ Xatolik: ' + e.message + '</div>';
            }
        }
    }, 200);
}

function closeToggle() {
    var overlay = document.getElementById("toggleOverlay");
    var panel = document.getElementById("togglePanel");
    if (overlay) overlay.classList.remove("active");
    if (panel) panel.classList.remove("open");
}

// ============================================================
// GENERATE EMPLOYEE DETAILS
// ============================================================
function generateEmployeeDetails(emp) {
    try {
        var html = "";
        var schedule = emp.work_schedule || {};
        var currencySymbol = window.currencySymbol || "so'm";
        
        // 1. XODIM HAQIDA
        html += '<div style="margin-bottom:15px; padding:15px; background:#f8fafc; border-radius:8px; border-left: 4px solid #3b82f6;">';
        html += '<h4 style="margin-top:0; border-bottom: 1px solid #e2e8f0; padding-bottom:8px; font-size: 18px; color: #1e293b;">👤 ' + translations.employee_info + '</h4>';
        html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 5px 15px;">';
        html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.name + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + (emp.ismi || translations.unknown) + '</span></p>';
        
        var statusColor = '#22c55e';
        var statusText = translations.active;
        if (emp.holati === 'inactive') { statusColor = '#ef4444'; statusText = translations.inactive; }
        else if (emp.holati === 'deleted') { statusColor = '#94a3b8'; statusText = translations.deleted; }
        
        html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.status + ':</strong> <span style="color: ' + statusColor + '; font-weight: 600;">' + statusText + '</span></p>';
        html += '</div></div>';
        
        // 2. YARATILGAN SANANI TAHRIRLASH
        var createdAt = emp.yaratilgan || '';
        var createdAtDisplay = createdAt ? new Date(createdAt).toLocaleString('uz-UZ', {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit'
        }) : '-';
        var createdAtInput = createdAt ? createdAt.replace(' ', 'T') : '';
        
        html += '<div class="created-at-edit" style="margin-bottom:15px; padding:15px; background:#f0f9ff; border-radius:8px; border:1px solid #bae6fd;">';
        html += '<label style="font-size:12px; font-weight:500; color:#0369a1; display:block; margin-bottom:4px;">' + translations.created_at + '</label>';
        html += '<div class="input-group" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';
        html += '<span style="font-size:14px; color:#0c4a6e; background:white; padding:4px 12px; border-radius:4px; border:1px solid #bae6fd;">' + createdAtDisplay + '</span>';
        html += '<input type="datetime-local" id="created_at_input_' + emp.id + '" value="' + createdAtInput + '" style="padding:6px 10px; border:1px solid #bae6fd; border-radius:6px; font-size:13px; background:white;">';
        html += '<button class="btn-update-created" onclick="updateCreatedAt(' + emp.id + ')" style="padding:6px 16px; background:#0ea5e9; color:white; border:none; border-radius:6px; cursor:pointer; font-size:13px;">' + translations.update + '</button>';
        html += '</div>';
        html += '<div id="created_status_' + emp.id + '" class="update-status" style="font-size:12px; margin-top:4px;"></div>';
        html += '</div>';
        
        // 3. ISH JADVALI
        html += '<div style="margin-bottom:15px; padding:15px; background:#f8fafc; border-radius:8px; border-left: 4px solid #8b5cf6;">';
        html += '<h4 style="margin-top:0; border-bottom: 1px solid #e2e8f0; padding-bottom:8px; font-size: 18px; color: #1e293b;">⏰ ' + translations.work_schedule + '</h4>';
        html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 5px 15px;">';
        html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.check_in + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + (schedule.start_time ? schedule.start_time.substring(0, 5) : "09:00") + '</span></p>';
        html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.check_out + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + (schedule.end_time ? schedule.end_time.substring(0, 5) : "18:00") + '</span></p>';
        html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.lunch_start + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + (schedule.lunch_start ? schedule.lunch_start.substring(0, 5) : "13:00") + '</span></p>';
        html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.lunch_end + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + (schedule.lunch_end ? schedule.lunch_end.substring(0, 5) : "14:00") + '</span></p>';
        html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.valid_from + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + (emp.amal_qiladi ? new Date(emp.amal_qiladi).toLocaleDateString('uz-UZ') : '-') + '</span></p>';
        html += '</div></div>';
        
        // 4. MAOSH
        html += '<div style="margin-bottom:15px; padding:15px; background:#f8fafc; border-radius:8px; border-left: 4px solid #f59e0b;">';
        html += '<h4 style="margin-top:0; border-bottom: 1px solid #e2e8f0; padding-bottom:8px; font-size: 18px; color: #1e293b;">💰 ' + translations.salary + '</h4>';
        html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 5px 15px;">';
        html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.salary_type + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + (emp.badge_text || translations.fixed) + '</span></p>';
        html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.salary_amount + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + (emp.salary_display || "0") + '</span></p>';
        html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.calculated_salary + ':</strong> <span style="color: #16a34a; font-weight: 600;">' + formatNumber(emp.calculated || 0) + " " + currencySymbol + '</span></p>';
        html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.worked_days + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + (emp.attended || 0) + " / " + (emp.days_in_month || 30) + '</span></p>';
        html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.worked_hours + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + (emp.total_worked_hours || 0) + " " + translations.hours + '</span></p>';
        html += '</div>';
        
        if (emp.total_penalty || emp.total_bonus) {
            html += '<hr style="border-color:#e2e8f0; margin:10px 0;">';
            html += '<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 5px 15px;">';
            html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.total_penalty + ':</strong> <span style="color: #ef4444; font-weight: 600;">-' + formatNumber(emp.total_penalty || 0) + " " + currencySymbol + '</span></p>';
            html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.total_bonus + ':</strong> <span style="color: #16a34a; font-weight: 600;">+' + formatNumber(emp.total_bonus || 0) + " " + currencySymbol + '</span></p>';
            var netColor = (emp.net_bonus || 0) >= 0 ? '#16a34a' : '#ef4444';
            var netSign = (emp.net_bonus || 0) >= 0 ? '+' : '';
            html += '<p style="margin: 4px 0;"><strong style="color: #475569;">' + translations.net_bonus + ':</strong> <span style="color: ' + netColor + '; font-weight: 600;">' + netSign + formatNumber(emp.net_bonus || 0) + " " + currencySymbol + '</span></p>';
            html += '</div>';
        }
        
        html += '</div>';
        
        // 5. JARIMA VA BONUS SOZLAMALARI
        html += '<div style="margin-bottom:15px; padding:15px; background:#f8fafc; border-radius:8px; border-left: 4px solid #ef4444;">';
        html += '<h4 style="margin-top:0; border-bottom: 1px solid #e2e8f0; padding-bottom:8px; font-size: 18px; color: #1e293b;">⚖️ ' + translations.penalty_bonus_settings + '</h4>';
        
        var latePenaltyEnabled = emp.kech_kelish_jarima_enabled ? translations.enabled : translations.disabled;
        html += '<div style="margin-bottom:8px; padding:8px; background:#fef2f2; border-radius:4px; border-left: 3px solid #dc2626;">';
        html += '<strong style="color:#dc2626;">' + translations.late_arrival + ':</strong> ';
        html += '<span style="color: ' + (emp.kech_kelish_jarima_enabled ? '#16a34a' : '#94a3b8') + ';">' + latePenaltyEnabled + '</span>';
        html += ' | ' + translations.grace + ': ' + (emp.kech_kelish_grace_minutes || 10) + ' ' + translations.minutes;
        html += ' | ' + translations.min_per_minute + ': ' + formatNumber(emp.kech_kelish_jarima_per_minute || 0) + ' ' + currencySymbol;
        html += ' | ' + translations.max_penalty + ': ' + formatNumber(emp.kech_kelish_max_jarima || 0) + ' ' + currencySymbol;
        html += '</div>';
        
        var earlyPenaltyEnabled = emp.erta_ketish_jarima_enabled ? translations.enabled : translations.disabled;
        html += '<div style="margin-bottom:8px; padding:8px; background:#fef2f2; border-radius:4px; border-left: 3px solid #dc2626;">';
        html += '<strong style="color:#dc2626;">' + translations.early_departure + ':</strong> ';
        html += '<span style="color: ' + (emp.erta_ketish_jarima_enabled ? '#16a34a' : '#94a3b8') + ';">' + earlyPenaltyEnabled + '</span>';
        html += ' | ' + translations.grace + ': ' + (emp.erta_ketish_grace_minutes || 10) + ' ' + translations.minutes;
        html += ' | ' + translations.min_per_minute + ': ' + formatNumber(emp.erta_ketish_jarima_per_minute || 0) + ' ' + currencySymbol;
        html += ' | ' + translations.max_penalty + ': ' + formatNumber(emp.erta_ketish_max_jarima || 0) + ' ' + currencySymbol;
        html += '</div>';
        
        var earlyBonusEnabled = emp.erta_kelish_bonus_enabled ? translations.enabled : translations.disabled;
        html += '<div style="margin-bottom:8px; padding:8px; background:#f0fdf4; border-radius:4px; border-left: 3px solid #22c55e;">';
        html += '<strong style="color:#16a34a;">' + translations.early_arrival_bonus + ':</strong> ';
        html += '<span style="color: ' + (emp.erta_kelish_bonus_enabled ? '#16a34a' : '#94a3b8') + ';">' + earlyBonusEnabled + '</span>';
        html += ' | ' + translations.min_per_minute + ': ' + formatNumber(emp.erta_kelish_bonus_per_minute || 0) + ' ' + currencySymbol;
        html += ' | ' + translations.max_bonus + ': ' + formatNumber(emp.erta_kelish_max_bonus || 0) + ' ' + currencySymbol;
        html += '</div>';
        
        var lateBonusEnabled = emp.kech_ketish_bonus_enabled ? translations.enabled : translations.disabled;
        html += '<div style="margin-bottom:8px; padding:8px; background:#f0fdf4; border-radius:4px; border-left: 3px solid #22c55e;">';
        html += '<strong style="color:#16a34a;">' + translations.late_departure_bonus + ':</strong> ';
        html += '<span style="color: ' + (emp.kech_ketish_bonus_enabled ? '#16a34a' : '#94a3b8') + ';">' + lateBonusEnabled + '</span>';
        html += ' | ' + translations.min_per_minute + ': ' + formatNumber(emp.kech_ketish_bonus_per_minute || 0) + ' ' + currencySymbol;
        html += ' | ' + translations.max_bonus + ': ' + formatNumber(emp.kech_ketish_max_bonus || 0) + ' ' + currencySymbol;
        html += '</div>';
        
        html += '</div>';
        
        // 6. OXIRGI KELISH
        if (emp.last_attendance_date) {
            var statusMap = {
                'present': translations.present,
                'partial_in': translations.partial_in,
                'partial_out': translations.partial_out,
                'absent': translations.absent,
                'half_day': translations.half_day,
                'overtime': translations.overtime,
                'vacation': translations.vacation,
                'sick': translations.sick
            };
            
            var statusText = statusMap[emp.last_attendance_status] || emp.last_attendance_status || translations.unknown;
            
            var dateObj = new Date(emp.last_attendance_date + 'T00:00:00');
            var dateStr = dateObj.toLocaleDateString('uz-UZ', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit'
            });
            
            var weekdays = [
                translations.sunday,
                translations.monday,
                translations.tuesday,
                translations.wednesday,
                translations.thursday,
                translations.friday,
                translations.saturday
            ];
            var weekday = weekdays[dateObj.getDay()] || translations.unknown;
            
            html += '<div style="margin-bottom:15px; padding:15px; background:#f8fafc; border-radius:8px; border-left: 4px solid #3b82f6;">';
            html += '<h4 style="margin-top:0; border-bottom: 1px solid #e2e8f0; padding-bottom:8px; font-size: 18px; color: #1e293b;">📅 ' + translations.last_attendance + '</h4>';
            
            html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 5px 15px;">';
            html += '<p style="margin: 4px 0;"><strong style="color: #475569;">📆 ' + translations.date + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + dateStr + ' (' + weekday + ')</span></p>';
            
            var statusColor = '#64748b';
            if (emp.last_attendance_status === 'present') statusColor = '#22c55e';
            else if (emp.last_attendance_status === 'partial_in') statusColor = '#f59e0b';
            else if (emp.last_attendance_status === 'partial_out') statusColor = '#f59e0b';
            else if (emp.last_attendance_status === 'absent') statusColor = '#ef4444';
            else if (emp.last_attendance_status === 'half_day') statusColor = '#8b5cf6';
            else if (emp.last_attendance_status === 'overtime') statusColor = '#ec4899';
            else if (emp.last_attendance_status === 'vacation') statusColor = '#06b6d4';
            else if (emp.last_attendance_status === 'sick') statusColor = '#f43f5e';
            
            html += '<p style="margin: 4px 0;"><strong style="color: #475569;">📊 ' + translations.status + ':</strong> <span style="color: ' + statusColor + '; font-weight: 600;">' + statusText + '</span></p>';
            
            if (emp.last_check_in) {
                var checkInTime = emp.last_check_in.substring(0, 5);
                html += '<p style="margin: 4px 0;"><strong style="color: #475569;">🚪 ' + translations.check_in + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + checkInTime + '</span></p>';
            } else {
                html += '<p style="margin: 4px 0;"><strong style="color: #475569;">🚪 ' + translations.check_in + ':</strong> <span style="color: #94a3b8;">—</span></p>';
            }
            
            if (emp.last_check_out) {
                var checkOutTime = emp.last_check_out.substring(0, 5);
                html += '<p style="margin: 4px 0;"><strong style="color: #475569;">🚪 ' + translations.check_out + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + checkOutTime + '</span></p>';
            } else {
                html += '<p style="margin: 4px 0;"><strong style="color: #475569;">🚪 ' + translations.check_out + ':</strong> <span style="color: #94a3b8;">—</span></p>';
            }
            
            if (emp.last_check_in && emp.last_check_out) {
                var inTime = new Date('1970-01-01T' + emp.last_check_in);
                var outTime = new Date('1970-01-01T' + emp.last_check_out);
                var diffMs = outTime - inTime;
                
                if (diffMs > 0) {
                    var diffHours = Math.floor(diffMs / (1000 * 60 * 60));
                    var diffMinutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
                    var workedTime = diffHours + ' ' + translations.hours + ' ' + diffMinutes + ' ' + translations.minutes;
                    html += '<p style="margin: 4px 0;"><strong style="color: #475569;">⏱️ ' + translations.worked_hours + ':</strong> <span style="color: #0f172a; font-weight: 500;">' + workedTime + '</span></p>';
                }
            }
            
            html += '</div></div>';
        }
        
        return html;
    } catch (e) {
        console.error('generateEmployeeDetails error:', e);
        return '<div style="color:#ef4444; text-align:center; padding:40px;">❌ Xatolik: ' + e.message + '</div>';
    }
}

// ============================================================
// UPDATE CREATED AT (AJAX)
// ============================================================
function updateCreatedAt(employeeId) {
    var input = document.getElementById('created_at_input_' + employeeId);
    var statusDiv = document.getElementById('created_status_' + employeeId);
    
    if (!input) return;
    
    var value = input.value;
    if (!value) {
        statusDiv.textContent = 'Iltimos, sana va vaqtni tanlang!';
        statusDiv.className = 'update-status error';
        return;
    }
    
    var formatted = value.replace('T', ' ') + ':00';
    
    statusDiv.textContent = 'Yangilanmoqda...';
    statusDiv.className = 'update-status';
    
    var formData = new FormData();
    formData.append('action', 'update_created_at');
    formData.append('employee_id', employeeId);
    formData.append('yaratilgan', formatted);
    formData.append('csrf_token', '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            statusDiv.textContent = '' + data.message;
            statusDiv.className = 'update-status success';
            var displaySpan = input.parentElement.querySelector('span');
            if (displaySpan) {
                var dateObj = new Date(value);
                displaySpan.textContent = dateObj.toLocaleString('uz-UZ', {
                    year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit'
                });
            }
            var emp = employeesData.find(function(e) { return e.id == employeeId; });
            if (emp) {
                emp.yaratilgan = formatted;
            }
        } else {
            statusDiv.textContent = ' ' + (data.message || 'Xatolik yuz berdi!');
            statusDiv.className = 'update-status error';
        }
    })
    .catch(function(error) {
        statusDiv.textContent = ' Tarmoq xatoligi: ' + error.message;
        statusDiv.className = 'update-status error';
        console.error('Update created at error:', error);
    });
}

// ============================================================
// FORMAT NUMBER
// ============================================================
function formatNumber(num) {
    if (num === undefined || num === null) return "0";
    return new Intl.NumberFormat("uz-UZ").format(Math.round(num));
}

// ============================================================
// DELETE MODAL
// ============================================================
function openDeleteModal(settingId) {
    document.getElementById('deleteModal').style.display = 'flex';
    document.getElementById('deleteSettingId').value = settingId;
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") {
        closeToggle();
        closeEditModal();
        closeDeleteModal();
    }
});

// ============================================================
// MODAL OVERLAY CLICK TO CLOSE
// ============================================================
document.addEventListener("DOMContentLoaded", function() {
    var deleteModal = document.getElementById('deleteModal');
    if (deleteModal) {
        deleteModal.addEventListener('click', function(e) {
            if (e.target === deleteModal) closeDeleteModal();
        });
    }
    var editModal = document.getElementById('editModal');
    if (editModal) {
        editModal.addEventListener('click', function(e) {
            if (e.target === editModal) closeEditModal();
        });
    }
});

// ============================================================
// CSS ANIMATION
// ============================================================
var style = document.createElement('style');
style.textContent = `
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
`;
document.head.appendChild(style);

// ============================================================
// DOM READY
// ============================================================
document.addEventListener("DOMContentLoaded", function() {
    <?php foreach($employees as $emp): ?>
    toggleSalaryFields(<?= $emp['id'] ?>);
    <?php endforeach; ?>

    <?php if($toggleEmployeeId): ?>
    openToggle(<?= $toggleEmployeeId ?>);
    <?php endif; ?>
    
    window.currencySymbol = "<?= $currencySymbol ?>";
});
</script>
