<?php
// backup.php - DUPLIKAT YO'Q (ON DUPLICATE KEY UPDATE)

require_once '/var/www/html/vendor/autoload.php';
require_once '/var/www/html/includes/config/database.php';

use Google\Client as GoogleClient;
use Google\Service\Drive;

ini_set('memory_limit', '512M');
set_time_limit(0);

function backupAppendDatabase() {
    global $pdo;

    $today = date('Y-m-d');
    $localDir = '/var/www/html/backups';
    $localFile = "$localDir/backup_append_$today.sql";

    if (!is_dir($localDir)) {
        mkdir($localDir, 0755, true);
    }

    // ============================================
    // 1. BUGUNGI MA'LUMOTLARNI OLISH
    // ============================================
    $fp = fopen($localFile, 'w');
    if ($fp === false) {
        echo "❌ Xatolik: lokal fayl yaratib bo'lmadi\n";
        return;
    }

    $totalRows = 0;

    try {
        fwrite($fp, "-- ============================================\n");
        fwrite($fp, "-- APPEND BACKUP - " . date('Y-m-d H:i:s') . "\n");
        fwrite($fp, "-- salary_system + ivms\n");
        fwrite($fp, "-- DUPLIKATLARSIZ (ON DUPLICATE KEY UPDATE)\n");
        fwrite($fp, "-- ============================================\n\n");

        $databases = ['salary_system', 'ivms'];

        foreach ($databases as $dbName) {
            fwrite($fp, "-- ============================================\n");
            fwrite($fp, "-- DATABASE: $dbName\n");
            fwrite($fp, "-- ============================================\n\n");
            
            fwrite($fp, "USE `$dbName`;\n\n");

            $stmt = $pdo->query("SHOW TABLES FROM `$dbName`");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $hasAuthDate = false;
                $hasCreatedAt = false;
                $hasUpdatedAt = false;
                $hasEmployeeId = false;
                
                try {
                    $colStmt = $pdo->query("SHOW COLUMNS FROM `$dbName`.`$table`");
                    $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
                    $hasAuthDate = in_array('auth_date', $columns);
                    $hasCreatedAt = in_array('created_at', $columns);
                    $hasUpdatedAt = in_array('updated_at', $columns);
                    $hasEmployeeId = in_array('employee_id', $columns);
                } catch (Exception $e) {
                    // Jadval kolonnalarini olishda xatolik
                }

                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                
                if ($hasAuthDate) {
                    $dataStmt = $pdo->prepare("SELECT * FROM `$dbName`.`$table` WHERE auth_date = ?");
                    $dataStmt->execute([$today]);
                } elseif ($hasCreatedAt) {
                    $dataStmt = $pdo->prepare("SELECT * FROM `$dbName`.`$table` WHERE DATE(created_at) = ?");
                    $dataStmt->execute([$today]);
                } elseif ($hasUpdatedAt) {
                    $dataStmt = $pdo->prepare("SELECT * FROM `$dbName`.`$table` WHERE DATE(updated_at) = ?");
                    $dataStmt->execute([$today]);
                } else {
                    $dataStmt = null;
                }

                $rowCount = 0;
                $columnsStr = null;
                $insertSQL = "";

                if ($dataStmt) {
                    while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
                        if ($columnsStr === null) {
                            $columnsStr = implode('`, `', array_keys($row));
                        }

                        $values = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $values[] = 'NULL';
                            } else {
                                $values[] = $pdo->quote($value);
                            }
                        }
                        $valuesStr = implode(', ', $values);
                        
                        // ============================================
                        // ✅ ON DUPLICATE KEY UPDATE - DUPLIKAT YO'Q!
                        // ============================================
                        $insertSQL .= "INSERT INTO `$table` (`$columnsStr`) VALUES ($valuesStr)\n";
                        $insertSQL .= "ON DUPLICATE KEY UPDATE\n";
                        
                        // Dinamik UPDATE qismi
                        $updateParts = [];
                        foreach (array_keys($row) as $col) {
                            if ($col != 'id' && $col != 'created_at') {
                                $updateParts[] = "    `$col` = VALUES(`$col`)";
                            }
                        }
                        $insertSQL .= implode(",\n", $updateParts) . ";\n";
                        
                        $rowCount++;
                    }
                    $dataStmt->closeCursor();
                }

                if ($rowCount > 0) {
                    fwrite($fp, "-- Table: $dbName.$table ($rowCount records)\n");
                    fwrite($fp, $insertSQL);
                    fwrite($fp, "\n");
                    $totalRows += $rowCount;
                }

                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }

        fclose($fp);

    } catch (Exception $e) {
        fclose($fp);
        echo "❌ SQL generatsiya xatosi: " . $e->getMessage() . "\n";
        return;
    }

    if ($totalRows == 0) {
        echo "✅ Bugungi ma'lumotlar yo'q\n";
        @unlink($localFile);
        return;
    }

    $fileSize = round(filesize($localFile) / 1024 / 1024, 2);
    echo "📊 Bugungi ma'lumotlar: $totalRows ta yozuv ($fileSize MB)\n";

    // ============================================
    // 2. GOOGLE DRIVE GA APPEND
    // ============================================
    try {
        $client = new GoogleClient();
        $client->setApplicationName('Database Backup');
        $client->setScopes(Drive::DRIVE_FILE);
        $client->setAuthConfig('/var/www/html/credentials.json');
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');

        $tokenPath = '/var/www/html/token.json';
        if (file_exists($tokenPath)) {
            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $client->setAccessToken($accessToken);
        }

        if ($client->isAccessTokenExpired()) {
            $refreshToken = $client->getRefreshToken();
            if (!$refreshToken) {
                throw new Exception("Refresh token topilmadi");
            }
            $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
            if (isset($newToken['error'])) {
                throw new Exception("Token yangilashda xato: " . ($newToken['error_description'] ?? $newToken['error']));
            }
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }

        $drive = new Drive($client);
        $fileId = '1crMH_17LxWq-SI1GVKVN0vDFBnPgLToZ';

        // Eski content ni olish
        try {
            $response = $drive->files->get($fileId, ['alt' => 'media']);
            $existingContent = $response->getBody()->getContents();
            $fileExists = true;
            $oldSize = round(strlen($existingContent) / 1024 / 1024, 2);
            echo "📁 Eski fayl hajmi: $oldSize MB\n";
        } catch (Exception $e) {
            $fileExists = false;
            $existingContent = "";
            echo "📁 Yangi fayl yaratiladi\n";
        }

        // Yangi content = eski + yangi
        $newContent = file_get_contents($localFile);
        $finalContent = $existingContent . "\n\n" . $newContent;
        $newSize = round(strlen($finalContent) / 1024 / 1024, 2);
        
        if ($fileExists) {
            echo "📝 APPEND: $oldSize MB → $newSize MB (+" . round($newSize - $oldSize, 2) . " MB)\n";
        }

        // Google Drive ga yuklash
        $client->setDefer(true);
        $request = $drive->files->update($fileId, new Drive\DriveFile(), ['uploadType' => 'resumable']);

        $chunkSizeBytes = 10 * 1024 * 1024;
        $media = new \Google\Http\MediaFileUpload(
            $client,
            $request,
            'text/plain',
            null,
            true,
            $chunkSizeBytes
        );
        $media->setFileSize(strlen($finalContent));

        $status = false;
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $finalContent);
        rewind($handle);

        while (!$status && !feof($handle)) {
            $chunk = fread($handle, $chunkSizeBytes);
            $status = $media->nextChunk($chunk);
        }
        fclose($handle);
        $client->setDefer(false);

        // Local faylni o'chirish
        @unlink($localFile);

        echo "✅ Google Drive'ga APPEND qilindi!\n";
        echo "📁 Fayl: https://drive.google.com/file/d/$fileId/view\n";
        echo "📊 Jami hajm: $newSize MB\n";

        // 1 yillik bashorat
        $dailyMB = round($newSize - ($fileExists ? $oldSize : 0), 2);
        if ($dailyMB > 0) {
            $yearlyGB = round(($dailyMB * 365) / 1024, 2);
            echo "📈 Kunlik o'sish: $dailyMB MB\n";
            if ($yearlyGB > 0) {
                echo "📊 1 yillik bashorat: $yearlyGB GB\n";
            } else {
                echo "📊 1 yillik bashorat: " . round($dailyMB * 365, 2) . " MB\n";
            }
        }

    } catch (Exception $e) {
        echo "⚠️ Google Drive'ga yuklashda xatolik: " . $e->getMessage() . "\n";
        echo "ℹ️ Lokal fayl saqlanib qoldi: $localFile\n";
    }
}

backupAppendDatabase();
