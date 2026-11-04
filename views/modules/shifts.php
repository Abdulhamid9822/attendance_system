<?php

if (isBranchUser()) {
    http_response_code(403);
    exit('Umumiy smenalarni faqat bosh administrator boshqarishi mumkin.');
}

$shiftAccount = (string)($_SESSION['account'] ?? $_SESSION['account_name'] ?? '');
if ($shiftAccount === '') {
    http_response_code(403);
    exit('Account topilmadi.');
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$shiftCsrfToken = $_SESSION['csrf_token'];
$shiftSid = (string)($_GET['sid'] ?? $_SESSION['sid'] ?? '');
$shiftError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($shiftCsrfToken, $postedToken)) {
        http_response_code(403);
        $shiftError = t('csrf_invalid_refresh');
    } else {
        try {
            $action = (string)($_POST['shift_action'] ?? '');
            $templateId = (int)($_POST['template_id'] ?? 0);

            if ($action === 'delete') {
                if ($templateId <= 0) {
                    throw new InvalidArgumentException(t('shift_template_error'));
                }
                $deleteStmt = $pdo->prepare("
                    DELETE FROM employee_work_schedule_templates
                    WHERE id = ? AND account_name = ?
                ");
                $deleteStmt->execute([$templateId, $shiftAccount]);
                if ($deleteStmt->rowCount() !== 1) {
                    throw new RuntimeException(t('shift_template_error'));
                }
                header('Location: ?tab=shifts&sid=' . urlencode($shiftSid) . '&deleted=1');
                exit;
            }

            if ($action !== 'save' || ($templateId < 0)) {
                throw new InvalidArgumentException(t('shift_template_error'));
            }

            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
                throw new InvalidArgumentException(t('shift_template_error'));
            }

            $times = [];
            foreach ([
                'ishga_kelish_vaqti',
                'ishdan_ketish_vaqti',
                'tushlik_boshlanish',
                'tushlik_tugash',
            ] as $field) {
                $value = $_POST[$field] ?? null;
                if (!is_string($value) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
                    throw new InvalidArgumentException(t('shift_template_error'));
                }
                $times[$field] = $value . ':00';
            }

            if ($templateId > 0) {
                $saveStmt = $pdo->prepare("
                    UPDATE employee_work_schedule_templates
                    SET name = ?, ishga_kelish_vaqti = ?, ishdan_ketish_vaqti = ?,
                        tushlik_boshlanish = ?, tushlik_tugash = ?
                    WHERE id = ? AND account_name = ?
                ");
                $saveStmt->execute([
                    $name,
                    $times['ishga_kelish_vaqti'],
                    $times['ishdan_ketish_vaqti'],
                    $times['tushlik_boshlanish'],
                    $times['tushlik_tugash'],
                    $templateId,
                    $shiftAccount,
                ]);
                if ($saveStmt->rowCount() === 0) {
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM employee_work_schedule_templates
                        WHERE id = ? AND account_name = ?
                    ");
                    $checkStmt->execute([$templateId, $shiftAccount]);
                    if (!$checkStmt->fetchColumn()) {
                        throw new RuntimeException(t('shift_template_error'));
                    }
                }
            } else {
                $saveStmt = $pdo->prepare("
                    INSERT INTO employee_work_schedule_templates
                        (account_name, name, ishga_kelish_vaqti, ishdan_ketish_vaqti,
                         tushlik_boshlanish, tushlik_tugash)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        ishga_kelish_vaqti = VALUES(ishga_kelish_vaqti),
                        ishdan_ketish_vaqti = VALUES(ishdan_ketish_vaqti),
                        tushlik_boshlanish = VALUES(tushlik_boshlanish),
                        tushlik_tugash = VALUES(tushlik_tugash)
                ");
                $saveStmt->execute([
                    $shiftAccount,
                    $name,
                    $times['ishga_kelish_vaqti'],
                    $times['ishdan_ketish_vaqti'],
                    $times['tushlik_boshlanish'],
                    $times['tushlik_tugash'],
                ]);
            }

            header('Location: ?tab=shifts&sid=' . urlencode($shiftSid) . '&saved=1');
            exit;
        } catch (Throwable $e) {
            error_log('Shift template save failed: ' . $e->getMessage());
            $shiftError = $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : t('shift_template_error');
        }
    }
}

$editingShift = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editStmt = $pdo->prepare("
        SELECT id, name, ishga_kelish_vaqti, ishdan_ketish_vaqti,
               tushlik_boshlanish, tushlik_tugash
        FROM employee_work_schedule_templates
        WHERE id = ? AND account_name = ?
        LIMIT 1
    ");
    $editStmt->execute([$editId, $shiftAccount]);
    $editingShift = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$listStmt = $pdo->prepare("
    SELECT id, name, ishga_kelish_vaqti, ishdan_ketish_vaqti,
           tushlik_boshlanish, tushlik_tugash
    FROM employee_work_schedule_templates
    WHERE account_name = ?
    ORDER BY name
");
$listStmt->execute([$shiftAccount]);
$shiftTemplates = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$escapeShift = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
$shiftNotice = isset($_GET['saved'])
    ? t('shift_template_saved')
    : (isset($_GET['deleted']) ? t('shift_template_deleted') : '');
?>
<style>
    .shift-page { padding: 20px; color: #1e293b; }
    .shift-page h2 { margin: 0 0 8px; font-size: 20px; }
    .shift-page > p { max-width: 900px; margin: 0 0 18px; color: #64748b; font-size: 13px; line-height: 1.55; }
    .shift-card { padding: 16px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
    .shift-card h3 { margin: 0 0 14px; font-size: 15px; }
    .shift-form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .shift-form-grid label { color: #475569; font-size: 12px; font-weight: 600; }
    .shift-form-grid input { box-sizing: border-box; display: block; width: 100%; min-height: 38px; margin-top: 5px; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 7px; color: #0f172a; font: inherit; }
    .shift-form-grid input:focus { outline: 2px solid #bfdbfe; border-color: #2563eb; }
    .shift-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
    .shift-actions button, .shift-actions a { display: inline-flex; min-height: 36px; align-items: center; justify-content: center; padding: 8px 14px; border: 0; border-radius: 7px; background: #2563eb; color: #fff; font: inherit; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; }
    .shift-actions a { background: #e2e8f0; color: #334155; }
    .shift-list { width: 100%; margin-top: 18px; border-collapse: collapse; }
    .shift-list th, .shift-list td { padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: left; font-size: 13px; }
    .shift-list th { color: #64748b; font-size: 11px; text-transform: uppercase; }
    .shift-list-actions { display: flex; gap: 7px; align-items: center; }
    .shift-list-actions a, .shift-list-actions button { padding: 5px 9px; border: 1px solid #cbd5e1; border-radius: 5px; background: #fff; color: #334155; font: inherit; text-decoration: none; cursor: pointer; }
    .shift-list-actions button { border-color: #fecaca; color: #b91c1c; }
    .shift-notice { margin-bottom: 12px; padding: 10px 14px; border: 1px solid #a7f3d0; border-radius: 7px; background: #d1fae5; color: #065f46; font-size: 13px; }
    .shift-notice.error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    @media (max-width: 700px) {
        .shift-page { padding: 14px; }
        .shift-form-grid { grid-template-columns: 1fr; }
        .shift-list-wrap { overflow-x: auto; }
        .shift-list { min-width: 620px; }
    }
</style>

<main class="shift-page">
    <h2><?= t('shifts') ?></h2>
    <p><?= t('shift_templates_description') ?></p>
    <?php if ($shiftNotice !== ''): ?><div class="shift-notice"><?= $escapeShift($shiftNotice) ?></div><?php endif; ?>
    <?php if ($shiftError !== ''): ?><div class="shift-notice error"><?= $escapeShift($shiftError) ?></div><?php endif; ?>

    <section class="shift-card">
        <h3><?= $editingShift ? t('edit') . ': ' . $escapeShift($editingShift['name']) : t('schedule_save_template') ?></h3>
        <form method="POST" action="?tab=shifts">
            <input type="hidden" name="csrf_token" value="<?= $escapeShift($shiftCsrfToken) ?>">
            <input type="hidden" name="shift_action" value="save">
            <input type="hidden" name="template_id" value="<?= (int)($editingShift['id'] ?? 0) ?>">
            <input type="hidden" name="sid" value="<?= $escapeShift($shiftSid) ?>">
            <div class="shift-form-grid">
                <label><?= t('shift_template_name') ?>
                    <input type="text" name="name" maxlength="100" required value="<?= $escapeShift($editingShift['name'] ?? '') ?>">
                </label>
                <label><?= t('check_in') ?>
                    <input type="time" name="ishga_kelish_vaqti" required value="<?= $escapeShift(substr($editingShift['ishga_kelish_vaqti'] ?? '09:00:00', 0, 5)) ?>">
                </label>
                <label><?= t('check_out') ?>
                    <input type="time" name="ishdan_ketish_vaqti" required value="<?= $escapeShift(substr($editingShift['ishdan_ketish_vaqti'] ?? '18:00:00', 0, 5)) ?>">
                </label>
                <label><?= t('lunch_start') ?>
                    <input type="time" name="tushlik_boshlanish" required value="<?= $escapeShift(substr($editingShift['tushlik_boshlanish'] ?? '13:00:00', 0, 5)) ?>">
                </label>
                <label><?= t('lunch_end') ?>
                    <input type="time" name="tushlik_tugash" required value="<?= $escapeShift(substr($editingShift['tushlik_tugash'] ?? '14:00:00', 0, 5)) ?>">
                </label>
            </div>
            <div class="shift-actions">
                <button type="submit"><?= t('save') ?></button>
                <?php if ($editingShift): ?><a href="?tab=shifts&sid=<?= urlencode($shiftSid) ?>"><?= t('cancel') ?></a><?php endif; ?>
            </div>
        </form>

        <div class="shift-list-wrap">
            <table class="shift-list">
                <thead>
                    <tr>
                        <th><?= t('shift_template_name') ?></th>
                        <th><?= t('check_in') ?></th>
                        <th><?= t('check_out') ?></th>
                        <th><?= t('lunch_start') ?></th>
                        <th><?= t('lunch_end') ?></th>
                        <th><?= t('actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$shiftTemplates): ?>
                    <tr><td colspan="6"><?= t('no_data') ?></td></tr>
                    <?php else: foreach ($shiftTemplates as $template): ?>
                    <tr>
                        <td><?= $escapeShift($template['name']) ?></td>
                        <td><?= $escapeShift(substr($template['ishga_kelish_vaqti'], 0, 5)) ?></td>
                        <td><?= $escapeShift(substr($template['ishdan_ketish_vaqti'], 0, 5)) ?></td>
                        <td><?= $escapeShift(substr($template['tushlik_boshlanish'], 0, 5)) ?></td>
                        <td><?= $escapeShift(substr($template['tushlik_tugash'], 0, 5)) ?></td>
                        <td>
                            <div class="shift-list-actions">
                                <a href="?tab=shifts&sid=<?= urlencode($shiftSid) ?>&edit=<?= (int)$template['id'] ?>"><?= t('edit') ?></a>
                                <form method="POST" action="?tab=shifts" onsubmit="return confirm('<?= $escapeShift(t('delete')) ?>?')">
                                    <input type="hidden" name="csrf_token" value="<?= $escapeShift($shiftCsrfToken) ?>">
                                    <input type="hidden" name="shift_action" value="delete">
                                    <input type="hidden" name="template_id" value="<?= (int)$template['id'] ?>">
                                    <input type="hidden" name="sid" value="<?= $escapeShift($shiftSid) ?>">
                                    <button type="submit"><?= t('delete') ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
