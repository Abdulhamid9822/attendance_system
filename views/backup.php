<?php
// backup.php - MUSTAQIL, XATOLIKSIZ

require_once '/var/www/html/vendor/autoload.php';
require_once '/var/www/html/config/database.php';

use Google\Client as GoogleClient;
use Google\Service\Drive;

function backupToGoogleDrive($accountName) {
    global $pdo;
    
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
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }
        
        $drive = new Drive($client);
        $folderId = '1HkRtZ6oku_iQByYg9ODkGm4zQiFEnqbD';
        
        $fileName = "backup_{$accountName}_" . date('Y-m-d') . ".zip";
        $tempFile = "/var/www/html/temp/{$fileName}";
        
        if (!is_dir('/var/www/html/temp')) {
            mkdir('/var/www/html/temp', 0777, true);
        }
        
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $sql = "-- Database Backup\n";
        $sql .= "-- Account: {$accountName}\n";
        $sql .= "-- Updated: " . date('Y-m-d H:i:s') . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $row['Create Table'] . ";\n\n";
            
            $stmt = $pdo->query("SELECT * FROM `{$table}`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($rows) > 0) {
                $columns = array_keys($rows[0]);
                $columnsStr = implode('`, `', $columns);
                
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . addslashes($value) . "'";
                        }
                    }
                    $valuesStr = implode(', ', $values);
                    $sql .= "INSERT INTO `{$table}` (`{$columnsStr}`) VALUES ({$valuesStr});\n";
                }
                $sql .= "\n";
            }
        }
        
        $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
        
        $sqlFile = str_replace('.zip', '.sql', $tempFile);
        file_put_contents($sqlFile, $sql);
        
        $zip = new ZipArchive();
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFile($sqlFile, str_replace('.zip', '.sql', $fileName));
            $zip->close();
            unlink($sqlFile);
        }
        
        $content = file_get_contents($tempFile);
        
        $fileMetadata = new Drive\DriveFile([
            'name' => $fileName,
            'parents' => [$folderId]
        ]);
        
        $file = $drive->files->create($fileMetadata, [
            'data' => $content,
            'mimeType' => 'application/zip',
            'uploadType' => 'multipart',
            'fields' => 'id, name, size'
        ]);
        
        $query = "name contains 'backup_{$accountName}' and mimeType='application/zip' and '{$folderId}' in parents";
        $results = $drive->files->listFiles([
            'q' => $query,
            'fields' => 'files(id, name, createdTime)',
            'pageSize' => 100
        ]);
        
        foreach ($results->getFiles() as $oldFile) {
            if ($oldFile->getId() !== $file->id) {
                try {
                    $drive->files->delete($oldFile->getId());
                } catch (Exception $e) {}
            }
        }
        
        @unlink($tempFile);
        
        echo "✅ Backup yuklandi: {$fileName} (" . round($file->size / 1024 / 1024, 2) . " MB)\n";
        echo "📂 Google Drive: https://drive.google.com/drive/folders/{$folderId}\n";
        
    } catch (Exception $e) {
        echo "❌ Xatolik: " . $e->getMessage() . "\n";
    }
}

$accountName = 'truthpos';
backupToGoogleDrive($accountName);
