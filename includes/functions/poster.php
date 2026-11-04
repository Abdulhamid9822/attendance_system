<?php
// includes/functions/poster.php - SIZNING TIZIMINGIZGA MOS

/**
 * Poster API so'rov (ACCOUNT_NAME asosida)
 */
function posterApi($method, $params = [], $account_name = null, $access_token = null) {
    global $pdo;
    
    if ($account_name === null) {
        $account_name = $_SESSION['account'] ?? '';
        $account_name = strtolower($account_name);
    }
    
    if ($access_token === null) {
        $access_token = $_SESSION['access_token'] ?? '';
        if (empty($access_token)) {
            $stmt = $pdo->prepare("SELECT access_token FROM poster_tokens WHERE account_name = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$account_name]);
            $token = $stmt->fetch(PDO::FETCH_ASSOC);
            $access_token = $token['access_token'] ?? '';
        }
    }
    
    if (empty($account_name) || empty($access_token)) {
        error_log("Poster API Error: Account: $account_name, Token: " . ($access_token ? 'bor' : 'yo\'q'));
        return ['error' => 'Account yoki Token topilmadi'];
    }
    
    $url = "https://{$account_name}.joinposter.com/api/" . rawurlencode($method)
        . "?" . http_build_query(array_merge(['token' => $access_token], $params));
    
    error_log("Poster API: " . preg_replace('/token=[^&]+/', 'token=HIDDEN', $url));
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        return ['error' => 'CURL Error: ' . $curlError];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        return ['error' => 'Poster API HTTP ' . $httpCode];
    }
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON response'];
    }
    
    if (isset($data['error'])) {
        error_log("Poster API Error: " . print_r($data['error'], true));
        return ['error' => $data['error']];
    }
    
    return $data;
}

function posterApiPost($method, array $params, $accessToken) {
    $url = 'https://joinposter.com/api/' . rawurlencode($method)
        . '?' . http_build_query(['token' => $accessToken]);
    $ch = curl_init();
    if ($ch === false) {
        return ['error' => 'Poster API so‘rovini boshlash imkonsiz.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['error' => 'Poster API so‘rovi bajarilmadi: ' . $curlError];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        return ['error' => 'Poster API HTTP ' . $httpCode];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return ['error' => 'Poster API javobi yaroqli JSON emas.'];
    }
    if (isset($data['error'])) {
        $apiError = is_scalar($data['error'])
            ? (string)$data['error']
            : json_encode($data['error'], JSON_UNESCAPED_UNICODE);
        return ['error' => 'Poster API moliya kategoriyasini o‘zgartirishni rad etdi: ' . $apiError];
    }

    return $data;
}

function syncPosterEmployeesToSalaryCategories(PDO $pdo, $accountName, $accessToken, $accountId) {
    $employeeResponse = posterApi('access.getEmployees', [], $accountName, $accessToken);
    if (isset($employeeResponse['error']) || !is_array($employeeResponse['response'] ?? null)) {
        throw new RuntimeException('Poster xodimlari olinmadi: ' . ($employeeResponse['error'] ?? 'javob formati noto‘g‘ri.'));
    }

    $posterEmployees = [];
    foreach ($employeeResponse['response'] as $employee) {
        if (!is_array($employee)) {
            continue;
        }
        $posterUserId = filter_var($employee['user_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string)($employee['name'] ?? ''));
        if ($posterUserId === false || $posterUserId <= 0 || $name === '') {
            continue;
        }
        $posterEmployees[$posterUserId] = [
            'user_id' => $posterUserId,
            'name' => $name,
            'role_name' => trim((string)($employee['role_name'] ?? '')),
        ];
    }
    if ($posterEmployees === []) {
        throw new RuntimeException('Poster javobida yaroqli xodimlar topilmadi; mavjud xodimlar o‘zgartirilmadi.');
    }

    $categoryResponse = posterApi('finance.getCategories', [], $accountName, $accessToken);
    if (isset($categoryResponse['error']) || !is_array($categoryResponse['response'] ?? null)) {
        throw new RuntimeException('Poster moliya kategoriyalari olinmadi: ' . ($categoryResponse['error'] ?? 'javob formati noto‘g‘ri.'));
    }

    $categories = $categoryResponse['response'];
    $normalizeName = static function ($name) {
        $name = trim((string)$name);
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    };
    $salaryCategoryId = 0;
    $salaryCategoryTerms = [
        'ish haqi', 'maosh', 'oylik', 'зарплат', 'заработная плата',
        'оплата труда', 'жалақы', 'еңбекақы', 'заробітна плата',
        'salary', 'payroll', 'wage', 'salario', 'راتب', 'الأجور',
        'palk', 'maaş', 'əmək haqqı', 'заробак',
    ];
    foreach ($categories as $category) {
        if (!is_array($category)) {
            continue;
        }
        $name = $normalizeName($category['name'] ?? '');
        $isSalaryCategory = false;
        foreach ($salaryCategoryTerms as $term) {
            if (strpos($name, $term) !== false) {
                $isSalaryCategory = true;
                break;
            }
        }
        if ((int)($category['parent_id'] ?? 0) === 0 && $isSalaryCategory) {
            $salaryCategoryId = (int)($category['category_id'] ?? 0);
            if ($salaryCategoryId > 0) {
                break;
            }
        }
    }
    if ($salaryCategoryId <= 0) {
        throw new RuntimeException('Posterda yuqori darajadagi ish haqi kategoriyasi topilmadi; xodimlar o‘zgartirilmadi.');
    }

    $existingCategories = [];
    $categoriesById = [];
    $salaryChildCategoryIds = [];
    $archiveCategoryId = 0;
    foreach ($categories as $category) {
        if (!is_array($category)) {
            continue;
        }
        $categoryId = (int)($category['category_id'] ?? 0);
        if ($categoryId > 0) {
            $categoriesById[$categoryId] = $category;
        }
        if ($categoryId <= 0 || (int)($category['parent_id'] ?? 0) !== $salaryCategoryId) {
            continue;
        }
        $categoryName = trim((string)($category['name'] ?? $category['category_name'] ?? ''));
        if ($normalizeName($categoryName) === 'arxiv') {
            $archiveCategoryId = $categoryId;
            continue;
        }
        if ($categoryId > 0) {
            $salaryChildCategoryIds[$categoryId] = true;
        }
        if ($categoryId > 0 && $categoryName !== '') {
            $existingCategories[$normalizeName($categoryName)][] = $categoryId;
        }
    }

    $stmt = $pdo->prepare("
        SELECT id, poster_id, poster_user_id, ismi, holati, account_id, branch_spot_id
        FROM xodimlar
        WHERE account_name = ? AND holati != 'deleted'
    ");
    $stmt->execute([$accountName]);
    $databaseEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filial foydalanuvchisi faqat o'z filialidagi va yangi (hali bazada yo'q) xodimlarni sinxronlaydi
    $branchSyncSpot = (($_SESSION['auth_type'] ?? '') === 'poster_branch')
        ? max(0, (int)($_SESSION['branch_spot_id'] ?? 0)) : 0;
    if (($_SESSION['auth_type'] ?? '') === 'poster_branch' && $branchSyncSpot <= 0) {
        throw new RuntimeException('Filial aniqlanmadi. Qayta kiring.');
    }
    if ($branchSyncSpot > 0) {
        $dbByPosterUser = [];
        foreach ($databaseEmployees as $databaseEmployee) {
            $puid = (int)($databaseEmployee['poster_user_id'] ?? 0);
            if ($puid > 0) {
                $dbByPosterUser[$puid] = $databaseEmployee;
            }
        }
        foreach ($posterEmployees as $puid => $employee) {
            if (isset($dbByPosterUser[$puid]) && (int)($dbByPosterUser[$puid]['branch_spot_id'] ?? 0) !== $branchSyncSpot) {
                unset($posterEmployees[$puid]);
            }
        }
        if ($posterEmployees === []) {
            return [
                'added' => 0, 'updated' => 0, 'reactivated' => 0, 'inactive' => 0,
                'categories_created' => 0, 'categories_updated' => 0,
                'categories_archived' => 0, 'positions_created' => 0,
            ];
        }
    }
    $employeesByPosterId = [];
    $reservedCategoryIds = [];
    foreach ($databaseEmployees as $databaseEmployee) {
        $posterUserId = (int)($databaseEmployee['poster_user_id'] ?? 0);
        $categoryId = (int)($databaseEmployee['poster_id'] ?? 0);
        if ($posterUserId > 0) {
            $employeesByPosterId[$posterUserId] = $databaseEmployee;
            if ($categoryId > 0) {
                $reservedCategoryIds[$categoryId] = $posterUserId;
            }
        }
    }

    $activePosterIdSet = array_fill_keys(array_map('intval', array_keys($posterEmployees)), true);
    $employeesToArchive = [];
    foreach ($databaseEmployees as $databaseEmployee) {
        if ($branchSyncSpot > 0) {
            break;
        }
        $posterUserId = (int)($databaseEmployee['poster_user_id'] ?? 0);
        $categoryId = (int)($databaseEmployee['poster_id'] ?? 0);
        if (
            $posterUserId > 0
            && !isset($activePosterIdSet[$posterUserId])
            && isset($salaryChildCategoryIds[$categoryId])
        ) {
            $employeesToArchive[$posterUserId] = $categoryId;
        }
    }

    $categoriesArchived = 0;
    if ($employeesToArchive !== []) {
        if ($archiveCategoryId <= 0) {
            $createdArchiveCategory = posterApiPost('finance.createCategory', [
                'category_name' => 'Arxiv',
                'category_parent' => $salaryCategoryId,
                'operations_in' => 0,
                'operations_out' => 1,
            ], $accessToken);
            $archiveCategoryId = filter_var($createdArchiveCategory['response'] ?? null, FILTER_VALIDATE_INT);
            if (
                isset($createdArchiveCategory['error'])
                || $archiveCategoryId === false
                || $archiveCategoryId <= 0
            ) {
                throw new RuntimeException(
                    'Posterda "Arxiv" kategoriyasini yaratib bo‘lmadi: '
                    . ($createdArchiveCategory['error'] ?? 'kategoriya ID qaytmadi.')
                );
            }
        }

        foreach ($employeesToArchive as $posterUserId => $categoryId) {
            $category = $categoriesById[$categoryId] ?? null;
            $categoryName = trim((string)(
                $category['name']
                ?? $category['category_name']
                ?? ($employeesByPosterId[$posterUserId]['ismi'] ?? '')
            ));
            if ($categoryName === '') {
                throw new RuntimeException(
                    'Poster ID ' . $posterUserId . ' uchun ish haqi kategoriyasi nomi topilmadi; arxivlash bajarilmadi.'
                );
            }

            $archivedCategory = posterApiPost('finance.updateCategory', [
                'category_id' => $categoryId,
                'category_name' => $categoryName,
                'category_parent' => $archiveCategoryId,
                'operations_in' => 0,
                'operations_out' => 1,
            ], $accessToken);
            $archivedCategoryId = filter_var($archivedCategory['response'] ?? null, FILTER_VALIDATE_INT);
            if (
                isset($archivedCategory['error'])
                || $archivedCategoryId === false
                || $archivedCategoryId !== $categoryId
            ) {
                throw new RuntimeException(
                    'Poster ID ' . $posterUserId . ' ish haqi kategoriyasini arxivga ko‘chirib bo‘lmadi: '
                    . ($archivedCategory['error'] ?? 'kategoriya ID mos kelmadi.')
                );
            }
            $categoriesArchived++;
        }
    }

    $nameCounts = [];
    foreach ($posterEmployees as $employee) {
        $key = $normalizeName($employee['name']);
        $nameCounts[$key] = ($nameCounts[$key] ?? 0) + 1;
    }

    $createdCategories = 0;
    $updatedCategories = 0;
    foreach ($posterEmployees as &$employee) {
        $categoryName = $employee['name'];
        if ($nameCounts[$normalizeName($categoryName)] > 1) {
            $categoryName .= ' (Poster ID: ' . $employee['user_id'] . ')';
        }

        $linkedEmployee = $employeesByPosterId[$employee['user_id']] ?? null;
        $linkedCategoryId = (int)($linkedEmployee['poster_id'] ?? 0);
        $linkedCategoryParentId = (int)($categoriesById[$linkedCategoryId]['parent_id'] ?? 0);
        $linkedCategoryIsArchived = $archiveCategoryId > 0 && $linkedCategoryParentId === $archiveCategoryId;
        if (
            $linkedEmployee !== null
            && $linkedCategoryId > 0
            && (isset($salaryChildCategoryIds[$linkedCategoryId]) || $linkedCategoryIsArchived)
            && ($reservedCategoryIds[$linkedCategoryId] ?? $employee['user_id']) === $employee['user_id']
        ) {
            $currentCategoryName = trim((string)(
                $categoriesById[$linkedCategoryId]['name']
                ?? $categoriesById[$linkedCategoryId]['category_name']
                ?? ''
            ));
            if ($currentCategoryName !== $categoryName || $linkedCategoryIsArchived) {
                $updatedCategory = posterApiPost('finance.updateCategory', [
                    'category_id' => $linkedCategoryId,
                    'category_name' => $categoryName,
                    'category_parent' => $salaryCategoryId,
                    'operations_in' => 0,
                    'operations_out' => 1,
                ], $accessToken);
                $updatedCategoryId = filter_var($updatedCategory['response'] ?? null, FILTER_VALIDATE_INT);
                if (
                    isset($updatedCategory['error'])
                    || $updatedCategoryId === false
                    || $updatedCategoryId !== $linkedCategoryId
                ) {
                    throw new RuntimeException(
                        'Posterda xodim ID ' . $employee['user_id'] . ' ish haqi kategoriyasini yangilab bo‘lmadi: '
                        . ($updatedCategory['error'] ?? 'kategoriya ID mos kelmadi.')
                    );
                }
                $updatedCategories++;
            }
            $employee['category_id'] = $linkedCategoryId;
            continue;
        }

        $categoryKey = $normalizeName($categoryName);
        if ($linkedEmployee === null && !empty($existingCategories[$categoryKey])) {
            while (!empty($existingCategories[$categoryKey])) {
                $candidateCategoryId = array_shift($existingCategories[$categoryKey]);
                if (!isset($reservedCategoryIds[$candidateCategoryId])) {
                    $employee['category_id'] = $candidateCategoryId;
                    $reservedCategoryIds[$candidateCategoryId] = $employee['user_id'];
                    continue 2;
                }
            }
        }

        $createdCategory = posterApiPost('finance.createCategory', [
            'category_name' => $categoryName,
            'category_parent' => $salaryCategoryId,
            'operations_in' => 0,
            'operations_out' => 1,
        ], $accessToken);
        $categoryId = filter_var($createdCategory['response'] ?? null, FILTER_VALIDATE_INT);
        if (isset($createdCategory['error']) || $categoryId === false || $categoryId <= 0) {
            throw new RuntimeException(
                'Posterda "' . $categoryName . '" ish haqi kategoriyasini yaratib bo‘lmadi: '
                . ($createdCategory['error'] ?? 'kategoriya ID qaytmadi.')
            );
        }
        $employee['category_id'] = $categoryId;
        $createdCategories++;
    }
    unset($employee);

    $pdo->beginTransaction();
    try {
        $employeesByCategoryId = [];
        $legacyEmployeesByName = [];
        foreach ($databaseEmployees as $databaseEmployee) {
            if ((int)($databaseEmployee['poster_id'] ?? 0) > 0) {
                $employeesByCategoryId[(int)$databaseEmployee['poster_id']] = $databaseEmployee;
                if ((int)($databaseEmployee['poster_user_id'] ?? 0) <= 0) {
                    $legacyEmployeesByName[$normalizeName($databaseEmployee['ismi'])][] = $databaseEmployee;
                }
            }
        }

        $positionIds = [];
        $positionsCreated = 0;
        $positionLookup = $pdo->prepare("
            SELECT id FROM employee_positions WHERE account_name = ? AND name = ? LIMIT 1
        ");
        $insertPosition = $pdo->prepare("
            INSERT IGNORE INTO employee_positions (account_name, name) VALUES (?, ?)
        ");
        foreach ($posterEmployees as $employee) {
            if ($employee['role_name'] === '') {
                continue;
            }
            $positionLookup->execute([$accountName, $employee['role_name']]);
            $positionId = $positionLookup->fetchColumn();
            if (!$positionId) {
                $insertPosition->execute([$accountName, $employee['role_name']]);
                $positionsCreated += $insertPosition->rowCount() > 0 ? 1 : 0;
                $positionLookup->execute([$accountName, $employee['role_name']]);
                $positionId = $positionLookup->fetchColumn();
            }
            if (!$positionId) {
                throw new RuntimeException('Poster lavozimini tizimga qo‘shib bo‘lmadi: ' . $employee['role_name']);
            }
            $positionIds[$employee['user_id']] = (int)$positionId;
        }

        $added = 0;
        $updated = 0;
        $reactivated = 0;
        $activePosterIds = [];
        $matchedEmployeeIds = [];
        $updateEmployee = $pdo->prepare("
            UPDATE xodimlar
            SET poster_id = ?, poster_user_id = ?, account_id = ?, ismi = ?, lavozimi = ?,
                position_id = COALESCE(?, position_id), holati = 'active', passive_since = NULL
            WHERE id = ? AND account_name = ?
        ");
        $branchSpotForNew = (($_SESSION['auth_type'] ?? '') === 'poster_branch')
            ? max(0, (int)($_SESSION['branch_spot_id'] ?? 0)) : 0;
        $insertEmployee = $pdo->prepare("
            INSERT INTO xodimlar
                (poster_id, poster_user_id, account_name, account_id, ismi, lavozimi, position_id, holati, branch_spot_id, poster_spot_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
        ");

        foreach ($posterEmployees as $employee) {
            $posterUserId = (int)$employee['user_id'];
            $categoryId = (int)$employee['category_id'];
            $activePosterIds[] = $posterUserId;
            $existing = $employeesByPosterId[$posterUserId] ?? $employeesByCategoryId[$categoryId] ?? null;
            if ($existing === null) {
                $sameNameRows = $legacyEmployeesByName[$normalizeName($employee['name'])] ?? [];
                if (count($sameNameRows) === 1) {
                    $existing = $sameNameRows[0];
                }
            }

            if ($existing !== null && $branchSyncSpot > 0 && (int)($existing['branch_spot_id'] ?? 0) !== $branchSyncSpot) {
                continue;
            }

            $positionId = $positionIds[$posterUserId] ?? null;
            $roleName = $employee['role_name'] !== '' ? $employee['role_name'] : null;
            if ($existing !== null) {
                $updateEmployee->execute([
                    $categoryId,
                    $posterUserId,
                    (string)$accountId,
                    $employee['name'],
                    $roleName,
                    $positionId,
                    (int)$existing['id'],
                    $accountName,
                ]);
                $matchedEmployeeIds[(int)$existing['id']] = true;
                if (($existing['holati'] ?? '') === 'inactive') {
                    $reactivated++;
                } else {
                    $updated++;
                }
            } else {
                $insertEmployee->execute([
                    $categoryId,
                    $posterUserId,
                    $accountName,
                    (string)$accountId,
                    $employee['name'],
                    $roleName,
                    $positionId,
                    $branchSpotForNew > 0 ? $branchSpotForNew : null,
                    $branchSpotForNew > 0 ? $branchSpotForNew : null,
                ]);
                $added++;
            }
        }

        $activePosterIds = array_values(array_unique($activePosterIds));
        $inactive = 0;
        if ($branchSyncSpot === 0) {
        $placeholders = implode(',', array_fill(0, count($activePosterIds), '?'));
        $deactivate = $pdo->prepare("
            UPDATE xodimlar
            SET holati = 'inactive', passive_since = NOW()
            WHERE account_name = ? AND holati = 'active' AND poster_user_id IS NOT NULL
                AND poster_user_id NOT IN ({$placeholders})
        ");
        $deactivate->execute(array_merge([$accountName], $activePosterIds));
        $inactive = max(0, $deactivate->rowCount());

        $deactivateLegacy = $pdo->prepare("
            UPDATE xodimlar
            SET holati = 'inactive', passive_since = NOW()
            WHERE id = ? AND account_name = ? AND holati = 'active'
        ");
        foreach ($databaseEmployees as $databaseEmployee) {
            $legacyCategoryId = (int)($databaseEmployee['poster_id'] ?? 0);
            if (
                (int)($databaseEmployee['poster_user_id'] ?? 0) <= 0
                && isset($salaryChildCategoryIds[$legacyCategoryId])
                && !isset($matchedEmployeeIds[(int)$databaseEmployee['id']])
            ) {
                $deactivateLegacy->execute([(int)$databaseEmployee['id'], $accountName]);
                $inactive += max(0, $deactivateLegacy->rowCount());
            }
        }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'added' => $added,
        'updated' => $updated,
        'reactivated' => $reactivated,
        'inactive' => $inactive,
        'categories_created' => $createdCategories,
        'categories_updated' => $updatedCategories,
        'categories_archived' => $categoriesArchived,
        'positions_created' => $positionsCreated,
    ];
}

/**
 * Poster ulanishini tekshirish
 */
function checkPosterConnection($account_name = null, $access_token = null) {
    $response = posterApi('spots.getSpots', [], $account_name, $access_token);
    
    if (isset($response['error'])) {
        return ['success' => false, 'message' => $response['error']];
    }
    
    return ['success' => true, 'message' => 'Poster API connected'];
}

/**
 * Kategoriyalarni olish (finance.getCategories)
 */
function getPosterCategories($account_name = null, $access_token = null) {
    $response = posterApi('finance.getCategories', [], $account_name, $access_token);
    
    if (isset($response['error'])) {
        return ['error' => $response['error']];
    }
    
    return $response['response'] ?? [];
}

/**
 * Xodimlarni kategoriya bo'yicha olish ("Ish haqi" kategoriyasi)
 */
function getPosterEmployeesByCategory($account_name = null, $access_token = null) {
    $categories = getPosterCategories($account_name, $access_token);
    
    if (isset($categories['error'])) {
        return ['error' => $categories['error']];
    }
    
    if (empty($categories)) {
        return ['error' => 'Kategoriyalar topilmadi'];
    }
    
    // "Ish haqi" kategoriyasini topish
    $salaryCategoryId = null;
    $employees = [];
    
    foreach ($categories as $cat) {
        $name = strtolower($cat['name'] ?? '');
        if (strpos($name, 'ish haqi') !== false || 
            strpos($name, 'salary') !== false || 
            strpos($name, 'зарплат') !== false ||
            strpos($name, 'staff') !== false ||
            strpos($name, 'айлық') !== false) {
            $salaryCategoryId = $cat['category_id'];
            
            // Bolalarini olish (xodimlar)
            if (isset($cat['children']) && is_array($cat['children'])) {
                foreach ($cat['children'] as $child) {
                    $employees[] = [
                        'user_id' => $child['category_id'],
                        'name' => $child['name'],
                        'parent_id' => $child['parent_id']
                    ];
                }
            }
            break;
        }
    }
    
    // Agar "Ish haqi" topilmasa, barcha kategoriyalarni tekshirish
    if ($salaryCategoryId === null) {
        foreach ($categories as $cat) {
            if (($cat['parent_id'] ?? 0) == 0 && isset($cat['children'])) {
                foreach ($cat['children'] as $child) {
                    $employees[] = [
                        'user_id' => $child['category_id'],
                        'name' => $child['name'],
                        'parent_id' => $child['parent_id']
                    ];
                }
            }
        }
    }
    
    return [
        'success' => true,
        'salary_category_id' => $salaryCategoryId,
        'employees' => $employees,
        'count' => count($employees)
    ];
}

/**
 * Xodimlarni sinxronlash (Poster -> Baza)
 */
function syncEmployeesFromPoster($account_name = null, $access_token = null) {
    global $pdo;
    
    if ($account_name === null) {
        $account_name = $_SESSION['account'] ?? '';
        $account_name = strtolower($account_name);
    }
    
    if ($access_token === null) {
        $access_token = $_SESSION['access_token'] ?? '';
        if (empty($access_token)) {
            $stmt = $pdo->prepare("SELECT access_token FROM poster_tokens WHERE account_name = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$account_name]);
            $token = $stmt->fetch(PDO::FETCH_ASSOC);
            $access_token = $token['access_token'] ?? '';
        }
    }
    
    $result = [
        'success' => false,
        'message' => '',
        'deleted' => 0,
        'added' => 0,
        'updated' => 0,
        'salary_category_id' => null
    ];
    
    // 1. Kategoriya bo'yicha xodimlarni olish
    $posterData = getPosterEmployeesByCategory($account_name, $access_token);
    
    if (isset($posterData['error'])) {
        $result['message'] = 'Poster xatolik: ' . json_encode($posterData['error']);
        return $result;
    }
    
    if (empty($posterData['employees'])) {
        $result['message'] = 'Xodimlar topilmadi (Ish haqi kategoriyasi bo\'sh)';
        return $result;
    }
    
    $result['salary_category_id'] = $posterData['salary_category_id'];
    $posterEmployees = $posterData['employees'];
    $posterUserIds = array_column($posterEmployees, 'user_id');
    
    // 2. Bazadagi xodimlarni olish
    $stmt = $pdo->prepare("SELECT id, poster_user_id, ismi FROM xodimlar WHERE account_name = ?");
    $stmt->execute([$account_name]);
    $dbEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $dbMap = [];
    $dbPosterIds = [];
    foreach ($dbEmployees as $emp) {
        if ($emp['poster_user_id']) {
            $dbMap[$emp['poster_user_id']] = $emp;
            $dbPosterIds[] = $emp['poster_user_id'];
        }
    }
    
    // 3. O'chirilganlar (Poster da yo'q)
    $deletedIds = array_diff($dbPosterIds, $posterUserIds);
    foreach ($deletedIds as $posterId) {
        if (isset($dbMap[$posterId])) {
            $emp = $dbMap[$posterId];
            
            $stmt = $pdo->prepare("DELETE FROM attendance_data WHERE employee_id = ? AND account_name = ?");
            $stmt->execute([$emp['id'], $account_name]);
            
            $stmt = $pdo->prepare("DELETE FROM oylik_sozlamalar WHERE xodim_id = ?");
            $stmt->execute([$emp['id']]);
            
            $stmt = $pdo->prepare("DELETE FROM bonus_jarima WHERE xodim_id = ?");
            $stmt->execute([$emp['id']]);
            
            $stmt = $pdo->prepare("DELETE FROM xodimlar WHERE id = ? AND account_name = ?");
            $stmt->execute([$emp['id'], $account_name]);
            
            $result['deleted']++;
        }
    }
    
    // 4. Yangi va yangilash
    foreach ($posterEmployees as $posterEmp) {
        $posterId = $posterEmp['user_id'];
        $name = $posterEmp['name'] ?? 'Noma\'lum';
        
        if (in_array($posterId, $dbPosterIds)) {
            // Mavjud - ismini yangilash
            $dbEmp = $dbMap[$posterId];
            if ($dbEmp['ismi'] != $name) {
                $stmt = $pdo->prepare("UPDATE xodimlar SET ismi = ? WHERE id = ? AND account_name = ?");
                $stmt->execute([$name, $dbEmp['id'], $account_name]);
                $result['updated']++;
            }
        } else {
            // Yangi xodim
            $stmt = $pdo->prepare("INSERT INTO xodimlar (ismi, poster_user_id, account_name, holati, created_at) VALUES (?, ?, ?, 'active', NOW())");
            $stmt->execute([$name, $posterId, $account_name]);
            $result['added']++;
        }
    }
    
    $result['success'] = true;
    $result['message'] = "✅ Sinxronlash yakunlandi!";
    
    return $result;
}

/**
 * Bitta xodimni tekshirish va yangilash
 */
function checkSingleEmployee($employee_id, $account_name = null, $access_token = null) {
    global $pdo;
    
    if ($account_name === null) {
        $account_name = $_SESSION['account'] ?? '';
        $account_name = strtolower($account_name);
    }
    
    if ($access_token === null) {
        $access_token = $_SESSION['access_token'] ?? '';
        if (empty($access_token)) {
            $stmt = $pdo->prepare("SELECT access_token FROM poster_tokens WHERE account_name = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$account_name]);
            $token = $stmt->fetch(PDO::FETCH_ASSOC);
            $access_token = $token['access_token'] ?? '';
        }
    }
    
    // 1. Xodimni bazadan olish
    $stmt = $pdo->prepare("SELECT * FROM xodimlar WHERE id = ? AND account_name = ?");
    $stmt->execute([$employee_id, $account_name]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        return ['success' => false, 'message' => 'Xodim topilmadi'];
    }
    
    if (empty($employee['poster_user_id'])) {
        return ['success' => false, 'message' => 'Xodim Poster bilan bog\'lanmagan'];
    }
    
    // 2. Poster dan xodimni olish
    $response = posterApi('users.getEmployee', [
        'user_id' => $employee['poster_user_id']
    ], $account_name, $access_token);
    
    if (isset($response['error'])) {
        // Xodim Poster da yo'q -> o'chirish
        $stmt = $pdo->prepare("DELETE FROM attendance_data WHERE employee_id = ? AND account_name = ?");
        $stmt->execute([$employee_id, $account_name]);
        
        $stmt = $pdo->prepare("DELETE FROM oylik_sozlamalar WHERE xodim_id = ?");
        $stmt->execute([$employee_id]);
        
        $stmt = $pdo->prepare("DELETE FROM xodimlar WHERE id = ? AND account_name = ?");
        $stmt->execute([$employee_id, $account_name]);
        
        return ['success' => true, 'message' => 'Xodim Poster dan o\'chirilganligi uchun bazadan ham o\'chirildi'];
    }
    
    if (isset($response['response'])) {
        $posterEmp = $response['response'];
        $newName = $posterEmp['name'] ?? $posterEmp['user_name'] ?? $employee['ismi'];
        
        if ($employee['ismi'] != $newName) {
            $stmt = $pdo->prepare("UPDATE xodimlar SET ismi = ? WHERE id = ? AND account_name = ?");
            $stmt->execute([$newName, $employee_id, $account_name]);
        }
        
        return ['success' => true, 'message' => "Xodim yangilandi: $newName"];
    }
    
    return ['success' => false, 'message' => 'Noma\'lum xatolik'];
}

/**
 * Ofitsiantlar savdosini olish
 */
function getWaitersSales($dateFrom = null, $dateTo = null, $account_name = null, $access_token = null) {
    if ($dateFrom === null) {
        $dateFrom = date('Ymd', strtotime('first day of this month'));
    }
    if ($dateTo === null) {
        $dateTo = date('Ymd');
    }
    
    $response = posterApi('dash.getWaitersSales', [
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo
    ], $account_name, $access_token);
    
    if (isset($response['error'])) {
        return ['error' => $response['error'], 'data' => []];
    }
    
    $result = [];
    if (isset($response['response']) && is_array($response['response'])) {
        foreach ($response['response'] as $waiter) {
            $result[] = [
                'user_id' => $waiter['user_id'],
                'name' => $waiter['name'] ?? 'Noma\'lum',
                'revenue' => ($waiter['revenue'] ?? 0) / 100,
                'profit' => ($waiter['profit'] ?? 0) / 100,
                'clients' => $waiter['clients'] ?? 0
            ];
        }
    }
    
    return ['success' => true, 'data' => $result];
}

/**
 * Xodim tranzaksiyalarini olish
 */
function getEmployeeTransactions($posterUserId, $dateFrom, $dateTo, $account_name = null, $access_token = null) {
    $apiDateFrom = str_replace('-', '', $dateFrom);
    $apiDateTo = str_replace('-', '', $dateTo);
    
    $response = posterApi('finance.getTransactions', [
        'dateFrom' => $apiDateFrom,
        'dateTo' => $apiDateTo,
        'category_id' => $posterUserId
    ], $account_name, $access_token);
    
    if (isset($response['error'])) {
        return ['error' => $response['error'], 'transactions' => [], 'total_paid' => 0];
    }
    
    $result = [];
    $totalPaid = 0;
    
    if (isset($response['response']) && is_array($response['response'])) {
        foreach ($response['response'] as $t) {
            $amount = abs($t['amount'] ?? 0) / 100;
            $isPayment = ($t['amount'] ?? 0) < 0;
            
            $result[] = [
                'date' => $t['transaction_date'] ?? $t['date'] ?? '',
                'amount' => $amount,
                'is_payment' => $isPayment,
                'comment' => $t['comment'] ?? ''
            ];
            
            if ($isPayment) {
                $totalPaid += $amount;
            }
        }
    }
    
    return ['transactions' => $result, 'total_paid' => $totalPaid];
}

/**
 * Barcha ofitsiantlar savdosi (oy bo'yicha)
 */
function getAllWaitersSales($month = null, $account_name = null, $access_token = null) {
    if ($month === null) {
        $month = date('Y-m');
    }
    
    $dateFrom = date('Ymd', strtotime($month . '-01'));
    $dateTo = date('Ymd', strtotime($month . '-01 +1 month -1 day'));
    
    return getWaitersSales($dateFrom, $dateTo, $account_name, $access_token);
}

/**
 * Bitta ofitsiantning kunlik savdosi
 */
function getWaiterDailyRevenue($posterUserId, $date, $account_name = null, $access_token = null) {
    $dateFrom = date('Ymd', strtotime($date));
    $dateTo = date('Ymd', strtotime($date));
    
    $response = posterApi('dash.getWaitersSales', [
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo
    ], $account_name, $access_token);
    
    if (isset($response['error']) || !isset($response['response'])) {
        return 0;
    }
    
    foreach ($response['response'] as $waiter) {
        if ($waiter['user_id'] == $posterUserId) {
            return ($waiter['revenue'] ?? 0) / 100;
        }
    }
    
    return 0;
}
?>