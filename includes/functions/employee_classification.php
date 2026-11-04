<?php

function ensureEmployeeClassificationSchema(PDO $pdo) {
    $posterCategoryAssignmentsExist = (bool)$pdo
        ->query("SHOW TABLES LIKE 'employee_poster_categories'")
        ->fetchColumn();

    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_institutions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_name VARCHAR(100) NOT NULL,
        name VARCHAR(150) NOT NULL,
        poster_spot_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_institution_account (account_name, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_name VARCHAR(100) NOT NULL,
        name VARCHAR(150) NOT NULL,
        poster_category_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_category_account (account_name, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_positions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_name VARCHAR(100) NOT NULL,
        name VARCHAR(150) NOT NULL,
        default_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        commission_source_type VARCHAR(20) NOT NULL DEFAULT 'waiter',
        commission_institution_id INT NULL,
        commission_category_id INT NULL,
        commission_poster_spot_id INT NULL,
        commission_poster_category_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_position_account (account_name, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_poster_categories (
        account_name VARCHAR(100) NOT NULL,
        employee_id INT NOT NULL,
        poster_category_id INT NOT NULL,
        PRIMARY KEY (account_name, employee_id, poster_category_id),
        KEY employee_poster_categories_employee (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $catalogColumns = [
        'employee_institutions' => ['poster_spot_id' => 'INT NULL'],
        'employee_categories' => ['poster_category_id' => 'INT NULL'],
        'employee_positions' => [
            'commission_source_type' => "VARCHAR(20) NOT NULL DEFAULT 'waiter'",
            'commission_institution_id' => 'INT NULL',
            'commission_category_id' => 'INT NULL',
            'commission_poster_spot_id' => 'INT NULL',
            'commission_poster_category_id' => 'INT NULL',
        ],
    ];
    foreach ($catalogColumns as $table => $columnsToEnsure) {
        $columns = $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($columnsToEnsure as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        }
    }

    $employeeColumns = $pdo->query("SHOW COLUMNS FROM xodimlar")->fetchAll(PDO::FETCH_COLUMN);
    $employeeColumnsToEnsure = [
        'poster_user_id' => 'INT NULL',
        'lavozimi' => 'VARCHAR(255) NULL',
        'passive_since' => 'DATETIME NULL',
        'institution_id' => 'INT NULL',
        'category_id' => 'INT NULL',
        'position_id' => 'INT NULL',
        'poster_spot_id' => 'INT NULL',
        'foiz_mustasno' => 'DECIMAL(5,2) NULL DEFAULT NULL',
        'commission_source_type' => "VARCHAR(20) NOT NULL DEFAULT 'waiter'",
    ];
    foreach ($employeeColumnsToEnsure as $column => $definition) {
        if (!in_array($column, $employeeColumns, true)) {
            $pdo->exec("ALTER TABLE xodimlar ADD COLUMN {$column} {$definition}");
        }
    }

    $pdo->exec("
        UPDATE employee_positions p
        INNER JOIN employee_institutions i
            ON i.id = p.commission_institution_id
            AND i.account_name COLLATE utf8mb4_unicode_ci = p.account_name COLLATE utf8mb4_unicode_ci
        SET p.commission_poster_spot_id = i.poster_spot_id
        WHERE p.commission_poster_spot_id IS NULL
            AND i.poster_spot_id IS NOT NULL
            AND p.commission_source_type = 'institution'
    ");
    $pdo->exec("
        UPDATE employee_positions p
        INNER JOIN employee_categories c
            ON c.id = p.commission_category_id
            AND c.account_name COLLATE utf8mb4_unicode_ci = p.account_name COLLATE utf8mb4_unicode_ci
        SET p.commission_poster_category_id = c.poster_category_id
        WHERE p.commission_poster_category_id IS NULL
            AND c.poster_category_id IS NOT NULL
            AND p.commission_source_type = 'category'
    ");
    $pdo->exec("
        UPDATE xodimlar e
        INNER JOIN employee_institutions i
            ON i.id = e.institution_id
            AND i.account_name COLLATE utf8mb4_unicode_ci = e.account_name COLLATE utf8mb4_unicode_ci
        SET e.poster_spot_id = i.poster_spot_id
        WHERE e.poster_spot_id IS NULL
            AND i.poster_spot_id IS NOT NULL
    ");
    $pdo->exec("
        UPDATE xodimlar e
        INNER JOIN employee_positions p ON p.id = e.position_id
            AND p.account_name COLLATE utf8mb4_unicode_ci = e.account_name COLLATE utf8mb4_unicode_ci
        SET e.poster_spot_id = p.commission_poster_spot_id
        WHERE e.poster_spot_id IS NULL
            AND p.commission_source_type = 'institution'
            AND p.commission_poster_spot_id IS NOT NULL
    ");

    if (!$posterCategoryAssignmentsExist) {
        $pdo->exec("
            INSERT IGNORE INTO employee_poster_categories (account_name, employee_id, poster_category_id)
            SELECT e.account_name, e.id, p.commission_poster_category_id
            FROM xodimlar e
            INNER JOIN employee_positions p ON p.id = e.position_id
                AND p.account_name COLLATE utf8mb4_unicode_ci = e.account_name COLLATE utf8mb4_unicode_ci
            WHERE p.commission_source_type = 'category'
                AND p.commission_poster_category_id IS NOT NULL
        ");
    }
}

function getEmployeeClassificationOptions(PDO $pdo, $table, $accountName) {
    $allowedColumns = [
        'employee_institutions' => 'name, poster_spot_id',
        'employee_categories' => 'name, poster_category_id',
        'employee_positions' => 'name, commission_source_type, commission_poster_spot_id',
    ];
    if (!isset($allowedColumns[$table])) {
        throw new InvalidArgumentException('Noma\'lum tasnif jadvali.');
    }

    $stmt = $pdo->prepare("SELECT id, {$allowedColumns[$table]} FROM {$table} WHERE account_name = ? ORDER BY name");
    $stmt->execute([$accountName]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function validatePositionSalesSource($sourceType) {
    if (!in_array($sourceType, ['waiter', 'institution', 'category'], true)) {
        throw new InvalidArgumentException('Savdo manbai noto‘g‘ri.');
    }
}

function normalizeEmployeePosterCategoryIds($categoryIds) {
    if (!is_array($categoryIds)) {
        if ($categoryIds === null) {
            return [];
        }
        $categoryIds = [$categoryIds];
    }

    $normalized = [];
    foreach ($categoryIds as $categoryId) {
        if (!is_scalar($categoryId)) {
            throw new InvalidArgumentException('Poster kategoriyasi noto‘g‘ri.');
        }
        $categoryId = filter_var((string)$categoryId, FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId <= 0) {
            throw new InvalidArgumentException('Poster kategoriyasi noto‘g‘ri.');
        }
        $normalized[] = $categoryId;
    }

    return array_values(array_unique($normalized));
}

function replaceEmployeePosterCategories(PDO $pdo, $employeeId, $accountName, array $categoryIds) {
    $delete = $pdo->prepare("
        DELETE FROM employee_poster_categories
        WHERE employee_id = ? AND account_name = ?
    ");
    $delete->execute([$employeeId, $accountName]);

    if ($categoryIds === []) {
        return;
    }

    $insert = $pdo->prepare("
        INSERT INTO employee_poster_categories (account_name, employee_id, poster_category_id)
        VALUES (?, ?, ?)
    ");
    foreach ($categoryIds as $categoryId) {
        $insert->execute([$accountName, $employeeId, $categoryId]);
    }
}

function getEmployeePosterCategoryAssignments(PDO $pdo, $accountName) {
    $stmt = $pdo->prepare("
        SELECT employee_id, poster_category_id
        FROM employee_poster_categories
        WHERE account_name = ?
        ORDER BY employee_id, poster_category_id
    ");
    $stmt->execute([$accountName]);

    $assignments = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $assignments[(int)$row['employee_id']][] = (int)$row['poster_category_id'];
    }
    return $assignments;
}

function flattenPosterCategories(array $categories, $depth = 0) {
    $flattened = [];
    foreach ($categories as $category) {
        if (!is_array($category)) {
            continue;
        }
        $categoryId = (int)($category['category_id'] ?? 0);
        if ($categoryId > 0) {
            $name = (string)($category['category_name'] ?? $category['name'] ?? ('Kategoriya ' . $categoryId));
            $flattened[] = [
                'category_id' => $categoryId,
                'category_name' => str_repeat('— ', $depth) . $name,
            ];
        }
        if (isset($category['children']) && is_array($category['children'])) {
            $flattened = array_merge($flattened, flattenPosterCategories($category['children'], $depth + 1));
        }
    }
    return $flattened;
}

function calculateMonthlySalesCommission($sourceType, $percent, $sourceId, $posterUserId, array $groupSales, array $waiterSales) {
    $revenue = 0;
    if ($sourceType === 'institution') {
        $revenue = (float)($groupSales['spots'][(int)$sourceId]['revenue'] ?? 0);
    } elseif ($sourceType === 'category') {
        $categoryIds = is_array($sourceId) ? array_unique(array_map('intval', $sourceId)) : [(int)$sourceId];
        foreach ($categoryIds as $categoryId) {
            $revenue += (float)($groupSales['categories'][$categoryId]['revenue'] ?? 0);
        }
    } else {
        $revenue = (float)($waiterSales[(int)$posterUserId]['revenue'] ?? 0);
    }

    return round($revenue * ((float)$percent / 100));
}

function getPosterGroupSalesAnalytics($selectedMonth, $accountName, $token, $branchSpotId = 0) {
    if ($accountName === '' || $token === '') {
        return ['error' => 'Poster akkaunti yoki token topilmadi.', 'spots' => [], 'categories' => []];
    }

    $dateFrom = str_replace('-', '', $selectedMonth) . '01';
    $dateTo = str_replace('-', '', $selectedMonth) . date('t', strtotime($selectedMonth . '-01'));
    $sales = ['spots' => [], 'categories' => []];
    $normalizeRevenue = static function ($amount, $minorUnits = false) {
        $isMinorUnitValue = $minorUnits
            || is_int($amount)
            || (is_string($amount) && ctype_digit($amount));
        $amount = (float)$amount;
        return $isMinorUnitValue ? $amount / 100 : $amount;
    };

    $request = static function ($method, array $params) use ($accountName, $token) {
        $url = 'https://' . rawurlencode($accountName) . '.joinposter.com/api/' . $method;
        $query = http_build_query(array_merge([
            'token' => $token,
        ], $params));
        $ch = curl_init($url . '?' . $query);
        if ($ch === false) {
            throw new RuntimeException('Poster savdo so‘rovini boshlash imkoni bo‘lmadi.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('Poster savdosini olishda xatolik: ' . ($curlError ?: "HTTP {$httpCode}"));
        }
        $payload = json_decode($response, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE || isset($payload['error'])) {
            throw new RuntimeException('Poster savdo javobi noto‘g‘ri.');
        }
        return $payload['response'] ?? null;
    };

    try {
        $categoryParams = ['dateFrom' => $dateFrom, 'dateTo' => $dateTo];
        if ((int)$branchSpotId > 0) {
            $categoryParams['spot_id'] = (int)$branchSpotId;
        }
        $categoryRows = $request('dash.getCategoriesSales', $categoryParams);
        if (!is_array($categoryRows)) {
            throw new RuntimeException('Poster kategoriya savdo javobi formati noto‘g‘ri.');
        }
        foreach ($categoryRows as $row) {
            if (!is_array($row) || !is_numeric($row['category_id'] ?? null)) {
                continue;
            }
            $categoryId = (int)$row['category_id'];
            $sales['categories'][$categoryId] = [
                'name' => (string)($row['category_name'] ?? ''),
                'revenue' => $normalizeRevenue($row['revenue'] ?? 0, true),
                'days' => [],
            ];
        }

        global $pdo;
        $spotSql = "
            SELECT DISTINCT e.poster_spot_id
            FROM xodimlar e
            INNER JOIN employee_positions p ON p.id = e.position_id
                AND p.account_name COLLATE utf8mb4_unicode_ci = e.account_name COLLATE utf8mb4_unicode_ci
            WHERE e.account_name = ? AND p.commission_source_type = 'institution'
              AND e.poster_spot_id IS NOT NULL
        ";
        $spotParams = [$accountName];
        if ((int)$branchSpotId > 0) {
            $spotSql .= " AND e.branch_spot_id = ?";
            $spotParams[] = (int)$branchSpotId;
        }
        $spotStmt = $pdo->prepare($spotSql);
        $spotStmt->execute($spotParams);
        $spotIds = $spotStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($spotIds as $spotId) {
            $spotId = (int)$spotId;
            $spotReport = $request('dash.getSpotsSales', [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'spot_id' => $spotId,
            ]);
            if (!is_array($spotReport) || !isset($spotReport['revenue'])) {
                throw new RuntimeException('Poster filial savdo javobi formati noto‘g‘ri.');
            }
            $sales['spots'][$spotId] = [
                'name' => '',
                'revenue' => $normalizeRevenue($spotReport['revenue']),
                'days' => [],
            ];
        }
    } catch (RuntimeException $e) {
        error_log("Poster grouped sales error: " . $e->getMessage());
        return ['error' => $e->getMessage(), 'spots' => [], 'categories' => []];
    } catch (PDOException $e) {
        error_log("Poster grouped sales mapping query failed: " . $e->getMessage());
        return ['error' => 'Savdo manbalarini bazadan olishda xatolik.', 'spots' => [], 'categories' => []];
    }

    return $sales;
}
