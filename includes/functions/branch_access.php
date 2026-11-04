<?php

function ensureBranchAccessSchema(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS branch_user_access (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_name VARCHAR(100) NOT NULL,
        poster_user_id BIGINT UNSIGNED NOT NULL,
        spot_id INT NULL,
        access_role VARCHAR(16) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY branch_user_account_poster (account_name, poster_user_id),
        KEY branch_user_account_spot (account_name, spot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $employeeColumns = $pdo->query("SHOW COLUMNS FROM xodimlar")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('branch_spot_id', $employeeColumns, true)) {
        $pdo->exec("ALTER TABLE xodimlar ADD COLUMN branch_spot_id INT NULL");
    }

    $indexes = $pdo->query("SHOW INDEX FROM xodimlar")->fetchAll(PDO::FETCH_COLUMN, 2);
    if (!in_array('idx_xodimlar_branch_spot', $indexes, true)) {
        $pdo->exec("ALTER TABLE xodimlar ADD INDEX idx_xodimlar_branch_spot (account_name, branch_spot_id)");
    }
}

function isBranchUser() {
    return ($_SESSION['auth_type'] ?? '') === 'poster_branch';
}

function currentBranchSpotId() {
    return isBranchUser() ? max(0, (int)($_SESSION['branch_spot_id'] ?? 0)) : 0;
}

function posterEmployeeHasAdminPanelAccess(array $posterEmployee) {
    $accessMask = filter_var($posterEmployee['access_mask'] ?? null, FILTER_VALIDATE_INT);
    if ($accessMask === false || $accessMask === null || $accessMask < 0) {
        return false;
    }

    return ($accessMask & ~1) !== 0;
}

function requireSuperAdmin() {
    if (!isBranchUser()) {
        return;
    }

    http_response_code(403);
    echo '<div class="alert error">Ushbu amal faqat bosh administrator uchun.</div>';
    exit;
}

function assertBranchSessionActive(PDO $pdo) {
    if (($_SESSION['auth_type'] ?? '') !== 'poster_branch') {
        return;
    }

    $accountName = (string)($_SESSION['account'] ?? $_SESSION['account_name'] ?? '');
    $posterUserId = (int)($_SESSION['user_id'] ?? 0);
    $spotId = currentBranchSpotId();
    $stmt = $pdo->prepare("
        SELECT id
        FROM branch_user_access
        WHERE account_name = ? AND poster_user_id = ? AND spot_id = ? AND access_role = 'branch'
        LIMIT 1
    ");
    $stmt->execute([$accountName, $posterUserId, $spotId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Filial menejerining ruxsati bekor qilingan.');
    }
}

function assertBranchEmployeeAccess(PDO $pdo, $employeeId, $accountName) {
    if (!isBranchUser()) {
        // Egalar va adminlar ham faqat o'z hisobidagi xodimlarga kira oladi
        $ownerStmt = $pdo->prepare("SELECT id FROM xodimlar WHERE id = ? AND account_name = ? LIMIT 1");
        $ownerStmt->execute([(int)$employeeId, (string)$accountName]);
        if (!$ownerStmt->fetchColumn()) {
            throw new RuntimeException('Xodim topilmadi yoki ruxsat yo‘q.');
        }
        return;
    }

    assertBranchSessionActive($pdo);
    $branchSpotId = currentBranchSpotId();
    if ($branchSpotId <= 0) {
        throw new RuntimeException('Filial ruxsati aniqlanmadi.');
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM xodimlar
        WHERE id = ? AND account_name = ? AND branch_spot_id = ?
        LIMIT 1
    ");
    $stmt->execute([(int)$employeeId, $accountName, $branchSpotId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Xodim topilmadi yoki filial ruxsati yo‘q.');
    }
}

function branchEmployeeSql($alias = 'e') {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('SQL jadval aliasi noto‘g‘ri.');
    }

    return isBranchUser() ? " AND {$alias}.branch_spot_id = " . currentBranchSpotId() : '';
}
