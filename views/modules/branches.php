<?php
require_once __DIR__ . '/../../includes/functions/branch_access.php';

if (isBranchUser() || (int)($_SESSION['poster_user_type'] ?? -1) !== 90) {
    http_response_code(403);
    exit('Filiallarni faqat Poster akkaunt egasi boshqarishi mumkin.');
}

$accountName = $_SESSION['account'] ?? $_SESSION['account_name'] ?? '';
if ($accountName === '') {
    http_response_code(403);
    exit('Account topilmadi.');
}

ensureBranchAccessSchema($pdo);
$currentPosterUserId = (int)($_SESSION['user_id'] ?? 0);
if (empty($_SESSION['branch_admin_csrf'])) {
    $_SESSION['branch_admin_csrf'] = bin2hex(random_bytes(32));
}
$branchAdminCsrf = $_SESSION['branch_admin_csrf'];
$branchPageError = '';
$branchPageSuccess = '';

$branchCatalogResponse = posterApi('spots.getSpots', [], $accountName, $_SESSION['access_token'] ?? '');
$branchSpots = [];
if (isset($branchCatalogResponse['error'])) {
    error_log('Branch management spot catalog request failed: ' . json_encode($branchCatalogResponse['error']));
    $branchPageError = t('branch_spots_load_error');
} elseif (is_array($branchCatalogResponse['response'] ?? null)) {
    foreach ($branchCatalogResponse['response'] as $spot) {
        $spotId = (int)($spot['spot_id'] ?? 0);
        if ($spotId > 0) {
            $branchSpots[$spotId] = (string)($spot['name'] ?? $spot['spot_name'] ?? (t('spot') . ' ' . $spotId));
        }
    }
}
$posterEmployeeResponse = posterApi('access.getEmployees', [], $accountName, $_SESSION['access_token'] ?? '');
$posterEmployeesWithAdminAccess = [];
$branchEmployeeCatalogError = '';
if (isset($posterEmployeeResponse['error'])) {
    error_log('Branch employee access catalog request failed: ' . json_encode($posterEmployeeResponse['error']));
    $branchEmployeeCatalogError = t('branch_employee_catalog_error');
} elseif (is_array($posterEmployeeResponse['response'] ?? null)) {
    foreach ($posterEmployeeResponse['response'] as $posterEmployee) {
        $posterUserId = (int)($posterEmployee['user_id'] ?? 0);
        if (
            $posterUserId > 0
            && $posterUserId !== $currentPosterUserId
            && posterEmployeeHasAdminPanelAccess($posterEmployee)
        ) {
            $posterEmployeesWithAdminAccess[$posterUserId] = (string)($posterEmployee['name'] ?? ('ID ' . $posterUserId));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($branchAdminCsrf, $postedToken)) {
        http_response_code(403);
        $branchPageError = t('csrf_invalid_refresh');
    } else {
        try {
            $action = (string)($_POST['branch_action'] ?? '');
            if ($action === 'save_employee_branch') {
                $employeeId = (int)($_POST['employee_id'] ?? 0);
                $spotId = (int)($_POST['branch_spot_id'] ?? 0);
                if ($employeeId <= 0 || ($spotId > 0 && !isset($branchSpots[$spotId]))) {
                    throw new InvalidArgumentException(t('branch_invalid_selection'));
                }
                $stmt = $pdo->prepare("
                    UPDATE xodimlar
                    SET branch_spot_id = ?, poster_spot_id = ?
                    WHERE id = ? AND account_name = ?
                ");
                $stmt->execute([$spotId > 0 ? $spotId : null, $spotId > 0 ? $spotId : null, $employeeId, $accountName]);
                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare("SELECT id FROM xodimlar WHERE id = ? AND account_name = ?");
                    $check->execute([$employeeId, $accountName]);
                    if (!$check->fetchColumn()) {
                        throw new RuntimeException(t('employee_not_found'));
                    }
                }
                $branchPageSuccess = t('branch_assignment_saved');
            } elseif ($action === 'bulk_assign_employees') {
                $spotId = (int)($_POST['branch_spot_id'] ?? 0);
                $ids = array_values(array_unique(array_filter(
                    array_map('intval', (array)($_POST['employee_ids'] ?? [])),
                    static function ($id) { return $id > 0; }
                )));
                if ($ids === [] || ($spotId > 0 && !isset($branchSpots[$spotId]))) {
                    throw new InvalidArgumentException(t('branch_invalid_selection'));
                }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("
                    UPDATE xodimlar
                    SET branch_spot_id = ?, poster_spot_id = ?
                    WHERE account_name = ? AND holati != 'deleted' AND id IN ({$placeholders})
                ");
                $stmt->execute(array_merge(
                    [$spotId > 0 ? $spotId : null, $spotId > 0 ? $spotId : null, $accountName],
                    $ids
                ));
                $branchPageSuccess = t('branch_assignment_saved') . ' (' . count($ids) . ')';
            } elseif ($action === 'save_poster_manager') {
                $posterUserId = (int)($_POST['poster_user_id'] ?? 0);
                $spotId = (int)($_POST['spot_id'] ?? 0);
                if (
                    $posterUserId <= 0
                    || !isset($posterEmployeesWithAdminAccess[$posterUserId])
                    || $spotId <= 0
                    || !isset($branchSpots[$spotId])
                ) {
                    throw new InvalidArgumentException(t('branch_invalid_selection'));
                }
                if ($posterUserId === $currentPosterUserId) {
                    throw new InvalidArgumentException(t('branch_manager_self_assignment'));
                }
                $stmt = $pdo->prepare("
                    INSERT INTO branch_user_access
                        (account_name, poster_user_id, spot_id, access_role)
                    VALUES (?, ?, ?, 'branch')
                    ON DUPLICATE KEY UPDATE
                        spot_id = VALUES(spot_id),
                        access_role = 'branch'
                ");
                $stmt->execute([$accountName, $posterUserId, $spotId]);
                $branchPageSuccess = t('branch_manager_saved');
            } elseif ($action === 'remove_poster_manager') {
                $posterUserId = (int)($_POST['poster_user_id'] ?? 0);
                if ($posterUserId <= 0) {
                    throw new InvalidArgumentException(t('branch_invalid_selection'));
                }
                $stmt = $pdo->prepare("
                    DELETE FROM branch_user_access
                    WHERE account_name = ? AND poster_user_id = ? AND access_role = 'branch'
                ");
                $stmt->execute([$accountName, $posterUserId]);
                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException(t('branch_manager_not_found'));
                }
                $branchPageSuccess = t('branch_manager_removed');
            } else {
                throw new InvalidArgumentException(t('branch_unknown_action'));
            }
        } catch (Throwable $e) {
            error_log('Branch management action failed: ' . $e->getMessage());
            $branchPageError = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
                ? $e->getMessage()
                : t('branch_manager_save_error');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['branch_flash'] = ['error' => $branchPageError, 'success' => $branchPageSuccess];
    $redirectSid = $sid ?? ($_GET['sid'] ?? $_POST['sid'] ?? '');
    header('Location: ?tab=branches' . ($redirectSid !== '' ? '&sid=' . urlencode((string)$redirectSid) : ''));
    exit;
}
if (!empty($_SESSION['branch_flash'])) {
    $branchPageError = $_SESSION['branch_flash']['error'] ?: $branchPageError;
    $branchPageSuccess = $_SESSION['branch_flash']['success'] ?? '';
    unset($_SESSION['branch_flash']);
}

$managerStmt = $pdo->prepare("
    SELECT poster_user_id, spot_id
    FROM branch_user_access
    WHERE account_name = ? AND access_role = 'branch'
    ORDER BY spot_id, poster_user_id
");
$managerStmt->execute([$accountName]);
$branchManagers = $managerStmt->fetchAll(PDO::FETCH_ASSOC);
$pendingManagerStmt = $pdo->prepare("
    SELECT poster_user_id
    FROM branch_user_access
    WHERE account_name = ? AND access_role = 'pending'
    ORDER BY poster_user_id
");
$pendingManagerStmt->execute([$accountName]);
$pendingManagers = $pendingManagerStmt->fetchAll(PDO::FETCH_COLUMN);

$employeeStmt = $pdo->prepare("
    SELECT id, ismi, branch_spot_id
    FROM xodimlar
    WHERE account_name = ? AND holati = 'active'
    ORDER BY ismi
");
$employeeStmt->execute([$accountName]);
$branchEmployees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);

$managersBySpot = [];
foreach ($branchManagers as $manager) {
    $managersBySpot[(int)$manager['spot_id']][] = (int)$manager['poster_user_id'];
}
$employeeCountBySpot = [];
$unassignedCount = 0;
foreach ($branchEmployees as $employee) {
    $spot = (int)($employee['branch_spot_id'] ?? 0);
    if ($spot > 0) {
        $employeeCountBySpot[$spot] = ($employeeCountBySpot[$spot] ?? 0) + 1;
    } else {
        $unassignedCount++;
    }
}
$h = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
$managerName = static function ($posterUserId) use ($posterEmployeesWithAdminAccess) {
    return $posterEmployeesWithAdminAccess[(int)$posterUserId] ?? ('ID ' . (int)$posterUserId);
};
?>
<style>
    .ba { max-width: 1180px; margin: 0 auto; padding: 24px 20px 40px; color: #0f172a; font-family: inherit; }
    .ba * { box-sizing: border-box; }
    .ba-head { margin-bottom: 20px; }
    .ba-head h2 { margin: 0 0 4px; font-size: 22px; font-weight: 700; }
    .ba-head p { margin: 0; color: #64748b; font-size: 13px; line-height: 1.55; }
    .ba-msg { margin-bottom: 14px; padding: 11px 14px; border-radius: 10px; font-size: 13px; border: 1px solid; }
    .ba-msg.error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
    .ba-msg.success { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
    .ba-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
    .ba-stat { padding: 14px 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; }
    .ba-stat b { display: block; font-size: 24px; line-height: 1.1; }
    .ba-stat span { color: #64748b; font-size: 12px; }
    .ba-stat.warn b { color: #d97706; }
    .ba-card { margin-bottom: 20px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
    .ba-card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 14px 18px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
    .ba-card-head h3 { margin: 0; font-size: 15px; font-weight: 600; }
    .ba-card-body { padding: 18px; }
    .ba-branches { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
    .ba-branch { padding: 14px 16px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
    .ba-branch-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 10px; }
    .ba-branch-name { font-weight: 600; font-size: 14px; }
    .ba-badge { display: inline-block; padding: 2px 9px; border-radius: 999px; background: #eff6ff; color: #1d4ed8; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .ba-badge.warn { background: #fffbeb; color: #b45309; }
    .ba-label { margin: 10px 0 6px; color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
    .ba-chip { display: inline-flex; align-items: center; gap: 6px; margin: 0 6px 6px 0; padding: 4px 6px 4px 10px; border-radius: 999px; background: #f1f5f9; font-size: 12px; }
    .ba-chip form { display: inline; margin: 0; }
    .ba-chip button { width: 20px; height: 20px; padding: 0; border: 0; border-radius: 50%; background: #e2e8f0; color: #475569; cursor: pointer; font-size: 13px; line-height: 1; }
    .ba-chip button:hover { background: #fecaca; color: #991b1b; }
    .ba-empty { color: #94a3b8; font-size: 12px; }
    .ba-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: end; }
    .ba-field label { display: block; margin-bottom: 5px; color: #475569; font-size: 12px; font-weight: 500; }
    .ba select, .ba input[type="text"], .ba input[type="search"] { width: 100%; min-height: 38px; padding: 7px 10px; border: 1px solid #d5dde8; border-radius: 8px; background: #fff; font-size: 13px; color: #0f172a; }
    .ba select:focus, .ba input:focus { outline: 2px solid #bfdbfe; border-color: #3b82f6; }
    .ba-btn { min-height: 38px; padding: 7px 16px; border: 0; border-radius: 8px; background: #2563eb; color: #fff; font-size: 13px; font-weight: 500; cursor: pointer; white-space: nowrap; }
    .ba-btn:hover { background: #1d4ed8; }
    .ba-btn.ghost { background: #f1f5f9; color: #334155; }
    .ba-btn.ghost:hover { background: #e2e8f0; }
    .ba-pending { margin-top: 16px; padding-top: 14px; border-top: 1px dashed #e2e8f0; }
    .ba-pending-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: center; margin-bottom: 8px; }
    .ba-tools { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .ba-tools .grow { flex: 1 1 220px; }
    .ba-tools select { width: auto; min-width: 170px; }
    .ba-bulk { display: none; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 18px; background: #eff6ff; border-bottom: 1px solid #dbeafe; font-size: 13px; }
    .ba-bulk.show { display: flex; }
    .ba-bulk select { width: auto; min-width: 180px; }
    .ba-table-wrap { max-height: 560px; overflow: auto; }
    .ba table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .ba th { position: sticky; top: 0; z-index: 1; padding: 10px 14px; background: #f8fafc; color: #64748b; font-weight: 600; text-align: left; border-bottom: 1px solid #e2e8f0; }
    .ba td { padding: 8px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .ba tr:hover td { background: #fafcff; }
    .ba td.check, .ba th.check { width: 40px; text-align: center; }
    .ba td select { min-width: 200px; }
    .ba-saving { opacity: .55; pointer-events: none; }
    @media (max-width: 860px) {
        .ba { padding: 14px; }
        .ba-stats { grid-template-columns: repeat(2, 1fr); }
        .ba-row, .ba-pending-row { grid-template-columns: 1fr; }
        .ba td select { min-width: 150px; }
    }
</style>

<main class="ba">
    <div class="ba-head">
        <h2><?= t('branch_management') ?></h2>
        <p><?= t('branch_management_description') ?></p>
    </div>

    <?php if ($branchPageError !== ''): ?><div class="ba-msg error"><?= $h($branchPageError) ?></div><?php endif; ?>
    <?php if ($branchPageSuccess !== ''): ?><div class="ba-msg success"><?= $h($branchPageSuccess) ?></div><?php endif; ?>
    <?php if ($branchSpots === []): ?><div class="ba-msg error"><?= t('branch_spots_unavailable') ?></div><?php endif; ?>
    <?php if ($branchEmployeeCatalogError !== ''): ?><div class="ba-msg error"><?= $h($branchEmployeeCatalogError) ?></div><?php endif; ?>

    <div class="ba-stats">
        <div class="ba-stat"><b><?= count($branchSpots) ?></b><span>Filiallar</span></div>
        <div class="ba-stat"><b><?= count($branchManagers) ?></b><span>Filial adminlari</span></div>
        <div class="ba-stat"><b><?= count($branchEmployees) - $unassignedCount ?></b><span>Biriktirilgan xodimlar</span></div>
        <div class="ba-stat <?= $unassignedCount > 0 ? 'warn' : '' ?>"><b><?= $unassignedCount ?></b><span>Biriktirilmagan xodimlar</span></div>
    </div>

    <section class="ba-card">
        <div class="ba-card-head"><h3>Filiallar va adminlar</h3></div>
        <div class="ba-card-body">
            <div class="ba-branches">
                <?php foreach ($branchSpots as $spotId => $spotName): ?>
                    <div class="ba-branch">
                        <div class="ba-branch-top">
                            <span class="ba-branch-name"><?= $h($spotName) ?></span>
                            <span class="ba-badge"><?= (int)($employeeCountBySpot[$spotId] ?? 0) ?> xodim</span>
                        </div>
                        <div class="ba-label">Filial admini</div>
                        <?php if (!empty($managersBySpot[$spotId])): ?>
                            <?php foreach ($managersBySpot[$spotId] as $managerId): ?>
                                <span class="ba-chip">
                                    <?= $h($managerName($managerId)) ?>
                                    <form method="POST" onsubmit="return confirm('Adminni olib tashlaysizmi?');">
                                        <input type="hidden" name="csrf_token" value="<?= $h($branchAdminCsrf) ?>">
                                        <input type="hidden" name="branch_action" value="remove_poster_manager">
                                        <input type="hidden" name="poster_user_id" value="<?= (int)$managerId ?>">
                                        <button type="submit" title="<?= $h(t('delete')) ?>">&times;</button>
                                    </form>
                                </span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="ba-empty">Admin tayinlanmagan</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="ba-card">
        <div class="ba-card-head"><h3>Filial adminini tayinlash</h3></div>
        <div class="ba-card-body">
            <p class="ba-empty" style="margin:0 0 12px;"><?= t('branch_oauth_admin_employees_help') ?></p>
            <?php if ($branchSpots !== []): ?>
                <form method="POST" class="ba-row">
                    <input type="hidden" name="csrf_token" value="<?= $h($branchAdminCsrf) ?>">
                    <input type="hidden" name="branch_action" value="save_poster_manager">
                    <div class="ba-field">
                        <label><?= t('poster_spot') ?></label>
                        <select name="spot_id" required>
                            <option value=""><?= t('select_branch') ?></option>
                            <?php foreach ($branchSpots as $spotId => $spotName): ?>
                                <option value="<?= (int)$spotId ?>"><?= $h($spotName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ba-field">
                        <label><?= t('branch_employee_with_admin_access') ?></label>
                        <select name="poster_user_id" required <?= $posterEmployeesWithAdminAccess === [] ? 'disabled' : '' ?>>
                            <option value=""><?= t('select_admin_employee') ?></option>
                            <?php foreach ($posterEmployeesWithAdminAccess as $posterUserId => $adminEmployeeName): ?>
                                <option value="<?= (int)$posterUserId ?>"><?= $h($adminEmployeeName) ?> (ID <?= (int)$posterUserId ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="ba-btn"><?= t('branch_manager_assign') ?></button>
                </form>
            <?php endif; ?>

            <?php if ($pendingManagers !== []): ?>
                <div class="ba-pending">
                    <div class="ba-label" style="margin-top:0;"><?= t('branch_pending_admin_employees') ?></div>
                    <?php foreach ($pendingManagers as $pendingPosterUserId): ?>
                        <form method="POST" class="ba-pending-row">
                            <input type="hidden" name="csrf_token" value="<?= $h($branchAdminCsrf) ?>">
                            <input type="hidden" name="branch_action" value="save_poster_manager">
                            <input type="hidden" name="poster_user_id" value="<?= (int)$pendingPosterUserId ?>">
                            <span><?= $h($managerName($pendingPosterUserId)) ?> <span class="ba-badge warn">Kutilmoqda</span></span>
                            <select name="spot_id" required>
                                <option value=""><?= t('select_branch') ?></option>
                                <?php foreach ($branchSpots as $spotId => $spotName): ?>
                                    <option value="<?= (int)$spotId ?>"><?= $h($spotName) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="ba-btn"><?= t('branch_manager_assign') ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="ba-card">
        <div class="ba-card-head">
            <h3><?= t('branch_employee_assignment') ?></h3>
            <div class="ba-tools">
                <input type="search" id="baSearch" class="grow" placeholder="<?= $h(t('search_employees')) ?>..">
                <select id="baFilter">
                    <option value="all">Barcha xodimlar</option>
                    <option value="0">Biriktirilmagan</option>
                    <?php foreach ($branchSpots as $spotId => $spotName): ?>
                        <option value="<?= (int)$spotId ?>"><?= $h($spotName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <form method="POST" id="baBulkForm" class="ba-bulk">
            <input type="hidden" name="csrf_token" value="<?= $h($branchAdminCsrf) ?>">
            <input type="hidden" name="branch_action" value="bulk_assign_employees">
            <strong><span id="baSelectedCount">0</span> ta tanlandi</strong>
            <select name="branch_spot_id">
                <option value="0"><?= t('unassigned') ?></option>
                <?php foreach ($branchSpots as $spotId => $spotName): ?>
                    <option value="<?= (int)$spotId ?>"><?= $h($spotName) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="ba-btn">Biriktirish</button>
            <button type="button" class="ba-btn ghost" id="baClear">Bekor qilish</button>
        </form>

        <div class="ba-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="check"><input type="checkbox" id="baCheckAll" aria-label="Hammasini tanlash"></th>
                        <th><?= t('employee') ?></th>
                        <th><?= t('poster_spot') ?></th>
                    </tr>
                </thead>
                <tbody id="baRows">
                    <?php foreach ($branchEmployees as $employee): ?>
                        <?php $empSpot = (int)($employee['branch_spot_id'] ?? 0); ?>
                        <tr data-name="<?= $h(function_exists('mb_strtolower') ? mb_strtolower($employee['ismi'], 'UTF-8') : strtolower($employee['ismi'])) ?>" data-spot="<?= $empSpot ?>">
                            <td class="check"><input type="checkbox" class="ba-check" name="employee_ids[]" value="<?= (int)$employee['id'] ?>" form="baBulkForm"></td>
                            <td><?= $h($employee['ismi']) ?></td>
                            <td>
                                <form method="POST" class="ba-single">
                                    <input type="hidden" name="csrf_token" value="<?= $h($branchAdminCsrf) ?>">
                                    <input type="hidden" name="branch_action" value="save_employee_branch">
                                    <input type="hidden" name="employee_id" value="<?= (int)$employee['id'] ?>">
                                    <select name="branch_spot_id">
                                        <option value="0"><?= t('unassigned') ?></option>
                                        <?php foreach ($branchSpots as $spotId => $spotName): ?>
                                            <option value="<?= (int)$spotId ?>" <?= $empSpot === (int)$spotId ? 'selected' : '' ?>><?= $h($spotName) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($branchEmployees === []): ?><tr><td colspan="3" class="ba-empty"><?= t('no_employees') ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
(function () {
    var rows = Array.prototype.slice.call(document.querySelectorAll('#baRows tr[data-spot]'));
    var search = document.getElementById('baSearch');
    var filter = document.getElementById('baFilter');
    var bulk = document.getElementById('baBulkForm');
    var countEl = document.getElementById('baSelectedCount');
    var checkAll = document.getElementById('baCheckAll');

    function visible(tr) { return tr.style.display !== 'none'; }

    function applyFilter() {
        var q = search.value.trim().toLowerCase();
        var f = filter.value;
        rows.forEach(function (tr) {
            var okName = q === '' || tr.getAttribute('data-name').indexOf(q) !== -1;
            var okSpot = f === 'all' || tr.getAttribute('data-spot') === f;
            tr.style.display = (okName && okSpot) ? '' : 'none';
        });
        refreshSelection();
    }

    function refreshSelection() {
        var checked = rows.filter(function (tr) { return tr.querySelector('.ba-check').checked; });
        countEl.textContent = checked.length;
        bulk.classList.toggle('show', checked.length > 0);
        var shown = rows.filter(visible);
        checkAll.checked = shown.length > 0 && shown.every(function (tr) { return tr.querySelector('.ba-check').checked; });
    }

    search.addEventListener('input', applyFilter);
    filter.addEventListener('change', applyFilter);
    checkAll.addEventListener('change', function () {
        rows.filter(visible).forEach(function (tr) { tr.querySelector('.ba-check').checked = checkAll.checked; });
        refreshSelection();
    });
    rows.forEach(function (tr) {
        tr.querySelector('.ba-check').addEventListener('change', refreshSelection);
    });
    document.getElementById('baClear').addEventListener('click', function () {
        rows.forEach(function (tr) { tr.querySelector('.ba-check').checked = false; });
        refreshSelection();
    });

    document.querySelectorAll('.ba-single select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            sel.form.classList.add('ba-saving');
            sel.form.submit();
        });
    });
})();
</script>
