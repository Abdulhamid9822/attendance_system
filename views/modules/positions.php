<?php

require_once __DIR__ . '/../../includes/functions/employee_classification.php';

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$classificationAccount = $_SESSION['account'] ?? $_SESSION['account_name'] ?? '';
$selectedMonth = $_GET['month'] ?? date('Y-m');
$parsedMonth = is_string($selectedMonth) ? DateTimeImmutable::createFromFormat('!Y-m', $selectedMonth) : false;
if (!$parsedMonth || $parsedMonth->format('Y-m') !== $selectedMonth) {
    $selectedMonth = date('Y-m');
}

try {
    ensureEmployeeClassificationSchema($pdo);
} catch (PDOException $e) {
    error_log("Classification page schema error: " . $e->getMessage());
    http_response_code(500);
    echo '<div class="alert error">' . htmlspecialchars(t('position_schema_error'), ENT_QUOTES, 'UTF-8') . '</div>';
    return;
}

$classificationError = match ($_GET['error'] ?? '') {
    'save' => t('position_save_error'),
    default => '',
};
$classificationSaved = isset($_GET['saved']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        echo '<div class="alert error">' . htmlspecialchars(t('csrf_invalid_refresh'), ENT_QUOTES, 'UTF-8') . '</div>';
        return;
    }

    try {
        if ($classificationAccount === '') {
            throw new Exception('Account topilmadi.');
        }

        $action = $_POST['classification_action'] ?? '';
        if ($action === 'add_position') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name, 'UTF-8') > 150) {
                throw new Exception('Nomi 1 dan 150 belgigacha bo\'lishi kerak.');
            }

            $sourceType = $_POST['commission_source_type'] ?? 'waiter';
            validatePositionSalesSource($sourceType);
            $stmt = $pdo->prepare("
                INSERT INTO employee_positions
                    (account_name, name, default_percent, commission_source_type,
                     commission_poster_spot_id, commission_poster_category_id)
                VALUES (?, ?, 0, ?, ?, ?)
            ");
            $stmt->execute([
                $classificationAccount,
                $name,
                $sourceType,
                null,
                null,
            ]);
        } elseif ($action === 'save_position') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Lavozim qiymati noto\'g\'ri.');
            }
            $sourceType = $_POST['commission_source_type'] ?? 'waiter';
            validatePositionSalesSource($sourceType);
            $stmt = $pdo->prepare("
                UPDATE employee_positions
                SET commission_source_type = ?, commission_poster_spot_id = ?,
                    commission_poster_category_id = ?, commission_institution_id = NULL,
                    commission_category_id = NULL
                WHERE id = ? AND account_name = ?
            ");
            $stmt->execute([
                $sourceType,
                null,
                null,
                $id,
                $classificationAccount,
            ]);
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare("SELECT id FROM employee_positions WHERE id = ? AND account_name = ?");
                $check->execute([$id, $classificationAccount]);
                if (!$check->fetchColumn()) {
                    throw new Exception('Lavozim topilmadi.');
                }
            }
        } else {
            throw new Exception('Noma\'lum amal.');
        }

        header('Location: ?tab=positions&month=' . urlencode($selectedMonth) . '&sid=' . urlencode($sid ?? '') . '&saved=1');
        exit;
    } catch (Exception $e) {
        error_log("Classification update error: " . $e->getMessage());
        header('Location: ?tab=positions&month=' . urlencode($selectedMonth) . '&sid=' . urlencode($sid ?? '') . '&error=save');
        exit;
    }
}

$positions = getEmployeeClassificationOptions($pdo, 'employee_positions', $classificationAccount);

$escape = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
?>
<style>
    .classification-page { padding: 20px; color: #1e293b; }
    .classification-page > h2 { margin: 0 0 8px; font-size: 18px; font-weight: 600; }
    .classification-page > p { max-width: 900px; margin: 0 0 18px; color: #64748b; font-size: 13px; line-height: 1.55; }
    .classification-card { padding: 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; }
    .classification-card h3 { margin: 0 0 14px; color: #1e293b; font-size: 15px; font-weight: 600; }
    .classification-add { display: grid; grid-template-columns: minmax(200px, 1fr) minmax(190px, 300px) auto; gap: 10px; margin: 0 0 14px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0; }
    .classification-row { display: grid; grid-template-columns: minmax(180px, 1fr) minmax(190px, 300px) auto; align-items: center; gap: 10px; margin: 0; padding: 10px 0; border: 0; border-bottom: 1px solid #e2e8f0; }
    .classification-row:last-child { border-bottom: 0; padding-bottom: 0; }
    .classification-row strong { color: #334155; font-size: 13px; font-weight: 500; }
    .classification-page input, .classification-page select { box-sizing: border-box; width: 100%; min-height: 34px; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; color: #334155; font: inherit; font-size: 13px; }
    .classification-page input:focus, .classification-page select:focus { outline: none; border-color: #b4d5fa; }
    .classification-page button { min-height: 34px; padding: 8px 16px; border: 0; border-radius: 6px; background: #22c55e; color: #fff; font-size: 13px; font-weight: 500; cursor: pointer; white-space: nowrap; }
    .classification-page button:hover { background: #16a34a; }
    .classification-month-filter { display: flex; align-items: center; gap: 10px; margin: 0 0 16px !important; }
    .classification-month-filter label { color: #475569; font-size: 12px; }
    .classification-month-filter input[type="month"] { width: 170px; }
    .classification-notice { margin-bottom: 12px; padding: 10px 14px; border: 1px solid #a7f3d0; border-radius: 6px; background: #d1fae5; color: #065f46; font-size: 13px; }
    .classification-notice.error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    @media (max-width: 650px) {
        .classification-page { padding: 14px; }
        .classification-card { padding: 12px; }
        .classification-add, .classification-row { grid-template-columns: 1fr; }
        .classification-row button { justify-self: start; }
    }
</style>

<main class="classification-page">
    <h2><?= t('positions') ?></h2>
    <p><?= t('positions_description') ?></p>
    <?php if ($classificationSaved): ?><div class="classification-notice"><?= t('position_settings_saved') ?></div><?php endif; ?>
    <?php if ($classificationError !== ''): ?><div class="classification-notice error"><?= $escape($classificationError) ?></div><?php endif; ?>

    <form method="GET" class="classification-month-filter">
        <input type="hidden" name="tab" value="positions">
        <input type="hidden" name="sid" value="<?= $escape($sid ?? '') ?>">
        <label for="classification-month"><?= t('sales_month') ?>:</label>
        <input id="classification-month" type="month" name="month" value="<?= $escape($selectedMonth) ?>">
        <button type="submit"><?= t('show') ?></button>
    </form>

        <section class="classification-card" id="positions">
            <h3><?= t('position_sales_source') ?></h3>
            <form method="POST" class="classification-add">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="classification_action" value="add_position">
                <input type="text" name="name" maxlength="150" required placeholder="<?= $escape(t('position_name')) ?>">
                <select name="commission_source_type" required>
                    <option value="waiter"><?= t('waiter_sales') ?></option>
                    <option value="institution"><?= t('spot_sales') ?></option>
                    <option value="category"><?= t('category_sales') ?></option>
                </select>
                <button type="submit"><?= t('add_position') ?></button>
            </form>
            <?php foreach ($positions as $position): ?>
            <form method="POST" class="classification-row">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="classification_action" value="save_position">
                <input type="hidden" name="id" value="<?= (int)$position['id'] ?>">
                <strong><?= $escape($position['name']) ?></strong>
                <span class="mapping-form">
                    <select name="commission_source_type" required>
                        <option value="waiter" <?= ($position['commission_source_type'] ?? 'waiter') === 'waiter' ? 'selected' : '' ?>><?= t('waiter_sales') ?></option>
                        <option value="institution" <?= ($position['commission_source_type'] ?? '') === 'institution' ? 'selected' : '' ?>><?= t('spot_sales') ?></option>
                        <option value="category" <?= ($position['commission_source_type'] ?? '') === 'category' ? 'selected' : '' ?>><?= t('category_sales') ?></option>
                    </select>
                </span>
                <button type="submit"><?= t('save') ?></button>
            </form>
            <?php endforeach; ?>
        </section>
</main>
