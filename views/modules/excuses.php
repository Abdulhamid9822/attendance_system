<?php
// modules/excuses.php - kech kelish / erta ketish arizalari (tasdiqlansa jarima qo'llanmaydi)

require_once __DIR__ . '/../../includes/functions/branch_access.php';
require_once __DIR__ . '/../../includes/functions/excuses.php';
require_once __DIR__ . '/../../includes/functions/push.php';

global $pdo;

$accountName = $_SESSION['account'] ?? $_SESSION['account_name'] ?? '';
if ($accountName === '') {
    http_response_code(403);
    exit('Account topilmadi.');
}

$isBranchAdmin = isBranchUser();
if ($isBranchAdmin) {
    try {
        assertBranchSessionActive($pdo);
    } catch (RuntimeException $e) {
        http_response_code(403);
        exit($e->getMessage());
    }
}
ensureExcuseSchema($pdo);

if (empty($_SESSION['excuse_csrf'])) {
    $_SESSION['excuse_csrf'] = bin2hex(random_bytes(32));
}
$excuseCsrf = $_SESSION['excuse_csrf'];
$scopeSql = $isBranchAdmin ? " AND e.branch_spot_id = " . (int)currentBranchSpotId() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = ['error' => '', 'success' => ''];
    $token = $_POST['csrf_token'] ?? '';
    $decision = (string)($_POST['decision'] ?? '');
    if (!is_string($token) || !hash_equals($excuseCsrf, $token)) {
        $msg['error'] = 'Sessiya eskirgan, sahifani yangilang.';
    } elseif (!in_array($decision, ['approved', 'rejected'], true)) {
        $msg['error'] = 'Qaror noto\'g\'ri.';
    } else {
        $stmt = $pdo->prepare("
            UPDATE excuse_requests r INNER JOIN xodimlar e ON e.id = r.xodim_id
            SET r.status = ?, r.decided_by = ?, r.decided_at = NOW()
            WHERE r.id = ? AND r.account_name = ?{$scopeSql}
        ");
        $stmt->execute([$decision, (string)($_SESSION['user_name'] ?? 'admin'), (int)($_POST['request_id'] ?? 0), $accountName]);
        $msg['success'] = $stmt->rowCount() > 0 ? 'Saqlandi.' : 'Ariza topilmadi.';
        if ($stmt->rowCount() > 0) {
            $info = $pdo->prepare("SELECT r.xodim_id, r.type, r.request_date FROM excuse_requests r INNER JOIN xodimlar e ON e.id = r.xodim_id WHERE r.id = ? AND r.account_name = ?{$scopeSql}");
            $info->execute([(int)($_POST['request_id'] ?? 0), $accountName]);
            if ($req = $info->fetch(PDO::FETCH_ASSOC)) {
                $target = $pdo->prepare("SELECT id FROM mobile_users WHERE account_name = ? AND xodim_id = ? AND active = 1");
                $target->execute([$accountName, (int)$req['xodim_id']]);
                sendPushToUsers($pdo, array_column($target->fetchAll(PDO::FETCH_ASSOC), 'id'),
                    $decision === 'approved' ? 'Arizangiz tasdiqlandi' : 'Arizangiz rad etildi',
                    excuseTypeLabel($req['type']) . ' — ' . $req['request_date'],
                    ['type' => 'excuse_decided', 'tab' => 'excuses']);
            }
        }
    }
    $_SESSION['excuse_flash'] = $msg;
    $sid = $_GET['sid'] ?? '';
    header('Location: ?tab=excuses' . ($sid !== '' ? '&sid=' . urlencode((string)$sid) : ''));
    exit;
}

$flash = $_SESSION['excuse_flash'] ?? ['error' => '', 'success' => ''];
unset($_SESSION['excuse_flash']);

$filter = (string)($_GET['status'] ?? 'pending');
if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $filter = 'pending';
}
$sql = "SELECT r.id, r.xodim_id, e.ismi, e.lavozimi, r.request_date, r.type, r.expected_time, r.reason, r.status,
               r.decided_by, r.decided_at, r.created_at,
               a.in_time, a.out_time, a.in_late_minutes, a.out_early_minutes
        FROM excuse_requests r INNER JOIN xodimlar e ON e.id = r.xodim_id
        LEFT JOIN attendance_data a ON a.employee_id = e.id AND a.account_name = ? AND a.auth_date = r.request_date
        WHERE r.account_name = ?{$scopeSql}";
$params = [$accountName, $accountName];
if ($filter !== 'all') {
    $sql .= " AND r.status = ?";
    $params[] = $filter;
}
$stmt = $pdo->prepare($sql . " ORDER BY r.request_date DESC, r.id DESC LIMIT 300");
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$h = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$statusLabels = ['pending' => 'Kutilmoqda', 'approved' => 'Tasdiqlangan', 'rejected' => 'Rad etilgan'];
$statusColors = ['pending' => '#b45309', 'approved' => '#047857', 'rejected' => '#b91c1c'];
$sidQ = isset($_GET['sid']) ? '&sid=' . urlencode((string)$_GET['sid']) : '';
?>
<style>
    .ex { max-width: 1000px; margin: 20px auto; padding: 0 16px; }
    .ex h2 { margin: 0 0 4px; }
    .ex p.sub { margin: 0 0 16px; color: #64748b; font-size: 13px; }
    .ex-filters a { display: inline-block; padding: 6px 14px; margin-right: 6px; border-radius: 999px; border: 1px solid #cbd5e1; color: #334155; text-decoration: none; font-size: 13px; }
    .ex-filters a.active { background: #2563eb; color: #fff; border-color: #2563eb; }
    .ex-msg { margin: 12px 0; padding: 10px 14px; border-radius: 10px; font-size: 13px; }
    .ex-msg.error { background: #fef2f2; color: #991b1b; }
    .ex-msg.success { background: #ecfdf5; color: #065f46; }
    .ex .tw { overflow-x: auto; }
    .ex table { width: 100%; min-width: 640px; border-collapse: collapse; margin-top: 14px; background: #fff; border-radius: 10px; overflow: hidden; }
    .ex small { display: block; color: #64748b; margin-top: 2px; }
    .ex-filters { display: flex; flex-wrap: wrap; gap: 6px; }
    .ex-filters a { margin-right: 0; }
    .ex th, .ex td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 13px; vertical-align: top; }
    .ex th { background: #f8fafc; }
    .ex form { display: inline; }
    .ex button { border: 0; border-radius: 8px; padding: 6px 12px; cursor: pointer; color: #fff; font-size: 12px; }
    .ex .ok { background: #059669; } .ex .no { background: #dc2626; }
</style>
<main class="ex">
    <h2>Arizalar</h2>
    <p class="sub">Tasdiqlangan kech kelish / erta ketish arizasi bo'lgan kunga jarima qo'llanmaydi.</p>
    <div class="ex-filters">
        <?php foreach (['pending' => 'Kutilmoqda', 'approved' => 'Tasdiqlangan', 'rejected' => 'Rad etilgan', 'all' => 'Hammasi'] as $k => $label): ?>
            <a href="?tab=excuses&status=<?= $k . $sidQ ?>" class="<?= $filter === $k ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($flash['error'] !== ''): ?><div class="ex-msg error"><?= $h($flash['error']) ?></div><?php endif; ?>
    <?php if ($flash['success'] !== ''): ?><div class="ex-msg success"><?= $h($flash['success']) ?></div><?php endif; ?>
    <div class="tw"><table>
        <tr><th>Xodim</th><th>Sana</th><th>Turi / so'ralgan vaqt</th><th>Haqiqiy davomat</th><th>Sabab</th><th>Holat</th><th></th></tr>
        <?php foreach ($requests as $r): ?>
            <?php
            $actualIn = $r['in_time'] ? substr((string)$r['in_time'], -8, 5) : '';
            $actualOut = $r['out_time'] ? substr((string)$r['out_time'], -8, 5) : '';
            $lateMin = (int)($r['in_late_minutes'] ?? 0);
            $earlyMin = (int)($r['out_early_minutes'] ?? 0);
            ?>
            <tr>
                <td><?= $h($r['ismi']) ?><small><?= $h($r['lavozimi'] ?? '') ?></small></td>
                <td><?= $h($r['request_date']) ?><small>Yuborilgan: <?= $h(substr((string)$r['created_at'], 0, 16)) ?></small></td>
                <td><?= $h(excuseTypeLabel($r['type'])) ?><?= excuseClock($r['expected_time']) ? ' — ' . $h(excuseClock($r['expected_time'])) : '' ?></td>
                <td>
                    <?php if ($actualIn === '' && $actualOut === ''): ?>
                        <small>Ma'lumot yo'q</small>
                    <?php else: ?>
                        Kirdi: <?= $actualIn ?: '—' ?> • Chiqdi: <?= $actualOut ?: '—' ?>
                        <?php if ($r['type'] === 'late' && $lateMin > 0): ?><small>Kechikish: <?= $lateMin ?> daq</small><?php endif; ?>
                        <?php if ($r['type'] === 'early' && $earlyMin < 0): ?><small>Erta ketdi: <?= abs($earlyMin) ?> daq</small><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td><?= $h($r['reason']) ?></td>
                <td style="color:<?= $statusColors[$r['status']] ?>"><?= $statusLabels[$r['status']] ?>
                    <?php if ($r['status'] !== 'pending' && $r['decided_by']): ?>
                        <small><?= $h(preg_replace('/^mobile:/', '', (string)$r['decided_by'])) ?>, <?= $h(substr((string)$r['decided_at'], 0, 16)) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php foreach (['approved' => ['ok', 'Tasdiqlash'], 'rejected' => ['no', 'Rad etish']] as $dec => [$cls, $label]): ?>
                        <?php if ($r['status'] !== $dec): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $h($excuseCsrf) ?>">
                                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="decision" value="<?= $dec ?>">
                                <button type="submit" class="<?= $cls ?>"><?= $label ?></button>
                            </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($requests === []): ?><tr><td colspan="7" style="color:#64748b">Arizalar yo'q</td></tr><?php endif; ?>
    </table></div>
</main>
