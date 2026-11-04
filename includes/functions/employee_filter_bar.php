<?php

require_once __DIR__ . '/employee_classification.php';

function employeeFilterValues() {
    if (function_exists('isBranchUser') && isBranchUser()) {
        return ['spot' => 0, 'position' => 0];
    }
    return [
        'spot' => max(0, (int)($_GET['poster_spot_id'] ?? 0)),
        'position' => max(0, (int)($_GET['position_id'] ?? 0)),
    ];
}

// Muassasa va lavozim filtrlarini SQL shartiga aylantiradi (filial xodimi uchun bo'sh)
function employeeFilterSql($alias = '') {
    $prefix = $alias !== '' ? $alias . '.' : '';
    $values = employeeFilterValues();
    $sql = '';
    $params = [];
    if ($values['spot'] > 0) {
        $sql .= " AND ({$prefix}poster_spot_id = ? OR {$prefix}branch_spot_id = ?)";
        $params[] = $values['spot'];
        $params[] = $values['spot'];
    }
    if ($values['position'] > 0) {
        $sql .= " AND {$prefix}position_id = ?";
        $params[] = $values['position'];
    }
    return [$sql, $params];
}

function employeeFilterQueryString() {
    $values = employeeFilterValues();
    $qs = '';
    if ($values['spot'] > 0) {
        $qs .= '&poster_spot_id=' . $values['spot'];
    }
    if ($values['position'] > 0) {
        $qs .= '&position_id=' . $values['position'];
    }
    return $qs;
}

function renderEmployeeFilterBar(PDO $pdo, $tab, $accountName, $token, $searchQuery, array $hidden, $sid) {
    $isBranch = function_exists('isBranchUser') && isBranchUser();
    $values = employeeFilterValues();
    $spots = [];
    $positions = [];
    if (!$isBranch) {
        try {
            $positions = getEmployeeClassificationOptions($pdo, 'employee_positions', $accountName);
        } catch (Throwable $e) {
            error_log('Filter positions load failed: ' . $e->getMessage());
        }
        if (function_exists('posterApi')) {
            $cache = $_SESSION['filter_spots_cache'][$accountName] ?? null;
            if (is_array($cache) && (time() - (int)$cache['time']) < 600) {
                $spots = $cache['data'];
            } else {
                $response = posterApi('spots.getSpots', [], $accountName, is_string($token) ? $token : '');
                if (is_array($response['response'] ?? null)) {
                    $spots = $response['response'];
                    $_SESSION['filter_spots_cache'][$accountName] = ['time' => time(), 'data' => $spots];
                } elseif (is_array($cache)) {
                    $spots = $cache['data'];
                }
            }
        }
    }

    $e = static function ($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };
    $baseUrl = '?tab=' . urlencode($tab);
    foreach ($hidden as $k => $v) {
        $baseUrl .= '&' . urlencode($k) . '=' . urlencode($v);
    }
    $baseUrl .= '&sid=' . urlencode($sid ?? '');
    // Qidiruvni tozalash filtrlarni saqlaydi, filtrni tozalash qidiruvni saqlaydi
    $clearSearchUrl = $baseUrl . employeeFilterQueryString();
    $clearFilterUrl = $baseUrl . ($searchQuery !== '' ? '&search=' . urlencode($searchQuery) : '');

    ob_start();
    ?>
    <form method="GET" style="margin: 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="tab" value="<?= $e($tab) ?>">
        <?php foreach ($hidden as $k => $v): ?>
        <input type="hidden" name="<?= $e($k) ?>" value="<?= $e($v) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="sid" value="<?= $e($sid ?? '') ?>">
        <input type="hidden" name="page" value="1">
        <div style="display: flex; gap: 1px; align-items: center; background: none; border-radius: 8px; border: 1px solid #e2e8f0;">
            <input type="text" name="search" id="searchInput" value="<?= $e($searchQuery) ?>" placeholder="<?= $e(t('search_employees')) ?>.." style="border: none; padding: 6px 12px; border-radius: 6px; font-size: 14px; min-width: 200px; background: none; outline: none;">
            <button type="submit" style="background: none; color: #333; border: none; padding: 6px 6px; cursor: pointer; font-size: 13px; font-weight: 500;"><i class="fa-solid fa-magnifying-glass"></i></button>
            <?php if ($searchQuery !== ''): ?>
            <a href="<?= $e($clearSearchUrl) ?>" style="color: #ff6c6c; border: none; padding: 3px 6px; text-decoration: none; font-size: 13px; font-weight: 600;">✕</a>
            <?php endif; ?>
        </div>
        <?php if (!$isBranch): ?>
        <div style="display: flex; gap: 1px; align-items: center; border-radius: 8px; border: 1px solid #e2e8f0;">
            <select name="poster_spot_id" onchange="this.form.submit()" aria-label="<?= $e(t('poster_spot')) ?>" style="border: none; padding: 6px; background: transparent;">
                <option value="0"><?= $e(t('all_branches')) ?></option>
                <?php foreach ($spots as $spot): ?>
                <?php $spotId = (int)($spot['spot_id'] ?? 0); ?>
                <?php if ($spotId > 0): ?>
                <option value="<?= $spotId ?>" <?= $values['spot'] === $spotId ? 'selected' : '' ?>><?= $e($spot['name'] ?? $spot['spot_name'] ?? ('Spot ' . $spotId)) ?></option>
                <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <select name="position_id" onchange="this.form.submit()" aria-label="<?= $e(t('position')) ?>" style="border: none; padding: 6px; background: transparent;">
                <option value="0"><?= $e(t('all_positions')) ?></option>
                <?php foreach ($positions as $position): ?>
                <option value="<?= (int)$position['id'] ?>" <?= $values['position'] === (int)$position['id'] ? 'selected' : '' ?>><?= $e($position['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($values['spot'] > 0 || $values['position'] > 0): ?>
            <a href="<?= $e($clearFilterUrl) ?>" style="color: #ff6c6c; border: none; padding: 3px 6px; text-decoration: none; font-size: 13px; font-weight: 600;">✕</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </form>
    <script>
    (function () {
        var input = document.getElementById('searchInput');
        if (!input || input.dataset.live) return;
        input.dataset.live = '1';
        var form = input.form, timer = null, controller = null;

        // Darhol: sahifadagi qatorlarni yozilgan matn bo'yicha yashirish
        function instantFilter() {
            var q = input.value.trim().toLowerCase();
            document.querySelectorAll('.table-wrapper tbody > tr').forEach(function (tr) {
                if (tr.id && tr.id.indexOf('edit_panel_') === 0) return;
                tr.style.display = (q === '' || tr.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
            });
        }

        function buildUrl() {
            var params = new URLSearchParams(new FormData(form));
            return '?' + params.toString();
        }

        // Fonda: server natijasini sahifani yangilamasdan almashtirish
        function load() {
            if (controller) controller.abort();
            controller = new AbortController();
            var url = buildUrl();
            fetch(url, { credentials: 'same-origin', signal: controller.signal })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    ['.table-wrapper', '.pagination'].forEach(function (sel) {
                        var cur = document.querySelector(sel), next = doc.querySelector(sel);
                        if (cur && next) cur.replaceWith(next);
                        else if (cur && !next) cur.innerHTML = '';
                    });
                    var curBar = document.getElementById('searchInput').closest('form');
                    var newBar = doc.getElementById('searchInput');
                    if (newBar) {
                        var curClear = form.querySelectorAll('a');
                        var newClear = newBar.closest('form').querySelectorAll('a');
                        curClear.forEach(function (a, i) { if (newClear[i]) a.href = newClear[i].href; });
                    }
                    history.replaceState(null, '', url);
                })
                .catch(function (err) { if (err.name !== 'AbortError') form.submit(); });
        }

        input.addEventListener('input', function () {
            instantFilter();
            clearTimeout(timer);
            timer = setTimeout(load, 250);
        });
        form.querySelectorAll('select').forEach(function (s) {
            s.onchange = function () { load(); };
        });
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            load();
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
