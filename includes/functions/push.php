<?php
// Firebase Cloud Messaging (HTTP v1). Service account fayli: config/fcm-service-account.json
// yoki FCM_SERVICE_ACCOUNT_FILE muhit o'zgaruvchisi. Fayl bo'lmasa push jimgina o'tkazib yuboriladi.

function ensurePushSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS mobile_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        token TEXT NOT NULL,
        platform VARCHAR(20) NOT NULL DEFAULT 'android',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_token (token_hash),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function pushServiceAccount(): ?array
{
    static $sa = false;
    if ($sa !== false) {
        return $sa;
    }
    $file = getenv('FCM_SERVICE_ACCOUNT_FILE') ?: __DIR__ . '/../../config/fcm-service-account.json';
    $sa = null;
    if (is_readable($file)) {
        $data = json_decode((string)file_get_contents($file), true);
        if (is_array($data) && !empty($data['private_key']) && !empty($data['client_email']) && !empty($data['project_id'])) {
            $sa = $data;
        }
    }
    return $sa;
}

function pushB64(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function pushAccessToken(array $sa): ?string
{
    $cache = sys_get_temp_dir() . '/fcm_token_' . md5($sa['client_email']) . '.json';
    if (is_readable($cache)) {
        $c = json_decode((string)file_get_contents($cache), true);
        if (is_array($c) && ($c['exp'] ?? 0) > time() + 60) {
            return $c['token'];
        }
    }
    $now = time();
    $head = pushB64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = pushB64(json_encode([
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $sig = '';
    if (!openssl_sign("$head.$claim", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => "$head.$claim." . pushB64($sig),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['access_token'])) {
        error_log('FCM token error: ' . json_encode($res));
        return null;
    }
    @file_put_contents($cache, json_encode(['token' => $res['access_token'], 'exp' => $now + (int)($res['expires_in'] ?? 3600)]), LOCK_EX);
    return $res['access_token'];
}

/** Berilgan mobile_users ID lariga push yuboradi. Xato bo'lsa asosiy oqimni buzmaydi; diagnostika qaytaradi. */
function sendPushToUsers(PDO $pdo, array $userIds, string $title, string $body, array $data = []): array
{
    $diag = ['service_account' => false, 'users' => 0, 'devices' => 0, 'sent' => 0, 'errors' => []];
    try {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $diag['users'] = count($userIds);
        $sa = pushServiceAccount();
        $diag['service_account'] = (bool)$sa;
        if (!$sa) {
            error_log('Push: config/fcm-service-account.json topilmadi yoki noto\'g\'ri');
            return $diag;
        }
        if (!$userIds) {
            return $diag;
        }
        ensurePushSchema($pdo);
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("SELECT id, token FROM mobile_devices WHERE user_id IN ($in)");
        $stmt->execute($userIds);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $diag['devices'] = count($devices);
        if (!$devices) {
            return $diag;
        }
        $access = pushAccessToken($sa);
        if (!$access) {
            $diag['errors'][] = 'Google token olinmadi';
            return $diag;
        }
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($sa['project_id']) . '/messages:send';
        $strData = [];
        foreach ($data as $k => $v) {
            $strData[(string)$k] = (string)$v;
        }
        foreach ($devices as $d) {
            $payload = ['message' => [
                'token' => $d['token'],
                'notification' => ['title' => $title, 'body' => $body],
                'data' => $strData ?: new stdClass(),
                'android' => ['priority' => 'HIGH', 'notification' => ['channel_id' => 'excuses', 'sound' => 'default']],
            ]];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
            ]);
            $resp = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            if ($code >= 200 && $code < 300) {
                $diag['sent']++;
            } else {
                $diag['errors'][] = "HTTP $code " . ($curlErr ?: mb_substr($resp, 0, 300));
                error_log("FCM send failed ($code): $curlErr $resp");
                if ($code === 404 || strpos($resp, 'UNREGISTERED') !== false) {
                    $pdo->prepare("DELETE FROM mobile_devices WHERE id = ?")->execute([(int)$d['id']]);
                }
            }
        }
    } catch (Throwable $e) {
        $diag['errors'][] = $e->getMessage();
        error_log('Push error: ' . $e->getMessage());
    }
    return $diag;
}

/** Xodimning filialiga mos adminlarga (filial admini + super) yangi ariza haqida xabar. */
function notifyExcuseAdmins(PDO $pdo, string $account, int $employeeId, string $title, string $body, array $data): void
{
    $e = $pdo->prepare("SELECT branch_spot_id, poster_spot_id FROM xodimlar WHERE id = ? LIMIT 1");
    $e->execute([$employeeId]);
    $emp = $e->fetch(PDO::FETCH_ASSOC) ?: [];
    $spots = array_values(array_filter([(int)($emp['branch_spot_id'] ?? 0), (int)($emp['poster_spot_id'] ?? 0)]));
    $sql = "SELECT id FROM mobile_users WHERE account_name = ? AND active = 1 AND (role = 'super'";
    $params = [$account];
    if ($spots) {
        $sql .= " OR (role = 'branch' AND spot_id IN (" . implode(',', array_fill(0, count($spots), '?')) . "))";
        $params = array_merge($params, $spots);
    }
    $sql .= ")";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    sendPushToUsers($pdo, array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'), $title, $body, $data);
}
