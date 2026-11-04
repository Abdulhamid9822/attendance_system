#!/usr/bin/php
<?php
/**
 * Attendance Data Sync Script
 * ivms.attendance_data -> salary_system.attendance
 * Runs continuously with infinite loop
 */

require_once __DIR__ . '/includes/session.php';

// Configuration
$config = [
    'host' => getenv('IVMS_HOST'),
    'dbname' => getenv('IVMS_DB'),
    'username' => getenv('IVMS_USER'),
    'password' => getenv('IVMS_PASS'),
    'sync_interval' => 3, // seconds between syncs
    'batch_size' => 10, // records per batch
];

// Log file
$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'sync_log.txt';

// Log papkasini tekshirish
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $log = "[$timestamp] $message\n";
    echo $log;
    if (!empty($logFile)) {
        file_put_contents($logFile, $log, FILE_APPEND | LOCK_EX);
    }
}

// Database connection function
function getDBConnection($config) {
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4",
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        logMessage("❌ Database connection failed: " . $e->getMessage());
        return null;
    }
}

// Main sync function
function syncAttendance($pdo_ivms, $pdo_salary) {
    try {
        // Start transaction
        $pdo_salary->beginTransaction();
        
        // Get unsynced records from ivms
        $query = "
            SELECT 
                id,
                employee_id,
                employee_name,
                device_id,
                card_no,
                auth_datetime,
                auth_date,
                auth_time,
                direction,
                davomat_holati,
                device_name,
                device_ip,
                raw_data,
                source,
                created_at
            FROM attendance_data 
            WHERE synced = 0 OR synced IS NULL
            ORDER BY auth_datetime ASC
            LIMIT :batch_size
        ";
        
        $stmt = $pdo_ivms->prepare($query);
        $stmt->bindValue(':batch_size', 100, PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchAll();
        
        if (empty($records)) {
            $pdo_salary->commit();
            return 0;
        }
        
        $syncedCount = 0;
        $errorCount = 0;
        $skippedCount = 0;
        
        foreach ($records as $record) {
            try {
                // Calculate delay (kechikish_daqiqa) - assuming work starts at 09:00
                $delayMinutes = 0;
                if ($record['direction'] == 'in' && $record['auth_time'] > '09:00:00') {
                    $workStart = strtotime('09:00:00');
                    $authTime = strtotime($record['auth_time']);
                    $delayMinutes = round(($authTime - $workStart) / 60);
                    if ($delayMinutes < 0) $delayMinutes = 0;
                }
                
                // Check if record already exists in salary_system
                $checkQuery = "
                    SELECT id FROM salary_system.attendance_data
                    WHERE employee_id = :employee_id 
                    AND auth_date = :auth_date
                    AND auth_time = :auth_time
                ";
                $checkStmt = $pdo_salary->prepare($checkQuery);
                $checkStmt->execute([
                    ':employee_id' => $record['employee_id'],
                    ':auth_date' => $record['auth_date'],
                    ':auth_time' => $record['auth_time']
                ]);
                
                if ($checkStmt->rowCount() > 0) {
                    // Skip if already exists (or update if needed)
                    $skippedCount++;
                    // Mark as synced anyway to avoid re-processing
                    markAsSynced($pdo_ivms, $record['id']);
                    continue;
                }
                
                // Insert into salary_system
                $insertQuery = "
                    INSERT INTO salary_system.attendance_data (
                        employee_id,
                        auth_datetime,
                        auth_date,
                        auth_time,
                        direction,
                        davomat_holati,
                        kechikish_daqiqa,
                        device_name,
                        device_serial,
                        person_serial,
                        card_no,
                        synced_at,
                        account_name,
                        source,
                        created_at
                    ) VALUES (
                        :employee_id,
                        :auth_datetime,
                        :auth_date,
                        :auth_time,
                        :direction,
                        :davomat_holati,
                        :kechikish_daqiqa,
                        :device_name,
                        :device_serial,
                        :person_serial,
                        :card_no,
                        NOW(),
                        :account_name,
                        :source,
                        :created_at
                    )
                ";
                
                $insertStmt = $pdo_salary->prepare($insertQuery);
                $insertStmt->execute([
                    ':employee_id' => $record['employee_id'],
                    ':auth_datetime' => $record['auth_datetime'],
                    ':auth_date' => $record['auth_date'],
                    ':auth_time' => $record['auth_time'],
                    ':direction' => $record['direction'],
                    ':davomat_holati' => $record['davomat_holati'] ?? 'present',
                    ':kechikish_daqiqa' => $delayMinutes,
                    ':device_name' => $record['device_name'],
                    ':device_serial' => $record['device_id'],
                    ':person_serial' => $record['employee_id'],
                    ':card_no' => $record['card_no'],
                    ':account_name' => 'admin', // or from config
                    ':source' => $record['source'] ?? 'ivms',
                    ':created_at' => $record['created_at'] ?? date('Y-m-d H:i:s')
                ]);
                
                // Mark as synced in ivms
                markAsSynced($pdo_ivms, $record['id']);
                
                $syncedCount++;
                logMessage("✅ Synced: ID {$record['id']} - Employee: {$record['employee_id']} - Date: {$record['auth_date']} - Time: {$record['auth_time']}");
                
            } catch (PDOException $e) {
                $errorCount++;
                logMessage("❌ Error syncing record ID {$record['id']}: " . $e->getMessage());
            }
        }
        
        // Commit transaction
        $pdo_salary->commit();
        
        logMessage("📊 Sync summary: Synced: $syncedCount, Skipped: $skippedCount, Errors: $errorCount");
        return $syncedCount;
        
    } catch (Exception $e) {
        if ($pdo_salary->inTransaction()) {
            $pdo_salary->rollBack();
        }
        logMessage("❌ Fatal error: " . $e->getMessage());
        return -1;
    }
}

// Mark record as synced
function markAsSynced($pdo, $id) {
    $updateQuery = "
        UPDATE attendance_data 
        SET synced = 1, 
            synced_at = NOW() 
        WHERE id = :id
    ";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([':id' => $id]);
}

// Add synced columns if not exists
function checkAndAddColumns($pdo) {
    try {
        // Check if synced column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM attendance_data LIKE 'synced'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE attendance_data ADD COLUMN synced TINYINT(1) DEFAULT 0");
            $pdo->exec("ALTER TABLE attendance_data ADD COLUMN synced_at DATETIME NULL");
            logMessage("✅ Added 'synced' and 'synced_at' columns to attendance_data");
        }
        return true;
    } catch (PDOException $e) {
        logMessage("❌ Failed to check/add columns: " . $e->getMessage());
        return false;
    }
}

// Main loop
function main() {
    global $config;
    
    logMessage("🚀 Attendance Sync Service Started");
    logMessage("📋 Configuration: " . json_encode([
        'interval' => $config['sync_interval'] . 's',
        'batch_size' => $config['batch_size']
    ]));
    
    // Connect to databases
    $pdo_ivms = getDBConnection($config);
    if (!$pdo_ivms) {
        logMessage("❌ Failed to connect to ivms database. Exiting.");
        exit(1);
    }
    
    $salaryConfig = [
        'host' => getenv('DB_HOST'),
        'dbname' => getenv('DB_NAME'),
        'username' => getenv('DB_USER'),
        'password' => getenv('DB_PASS'),
    ];
    $pdo_salary = getDBConnection($salaryConfig);
    if (!$pdo_salary) {
        logMessage("❌ Failed to connect to salary_system database. Exiting.");
        exit(1);
    }
    
    // Check and add required columns
    if (!checkAndAddColumns($pdo_ivms)) {
        logMessage("❌ Failed to setup database. Exiting.");
        exit(1);
    }
    
    logMessage("✅ Database connections established");
    logMessage("🔄 Starting continuous sync loop...");
    logMessage("⏱️  Sync interval: {$config['sync_interval']} seconds");
    
    $iteration = 0;
    
    while (true) {
        $iteration++;
        try {
            logMessage("🔄 Iteration #$iteration - Starting sync...");
            
            $startTime = microtime(true);
            $synced = syncAttendance($pdo_ivms, $pdo_salary);
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            
            if ($synced >= 0) {
                logMessage("✅ Iteration #$iteration completed in {$duration}s");
            } else {
                logMessage("⚠️  Iteration #$iteration completed with errors");
            }
            
        } catch (Exception $e) {
            logMessage("❌ Iteration #$iteration failed: " . $e->getMessage());
        }
        
        // Reconnect if connection lost
        if (!$pdo_ivms || !$pdo_salary) {
            logMessage("🔄 Reconnecting to databases...");
            $pdo_ivms = getDBConnection($config);
            $salaryConfig = $config;
            $salaryConfig['dbname'] = 'salary_system';
            $pdo_salary = getDBConnection($salaryConfig);
        }
        
        // Wait before next iteration
        sleep($config['sync_interval']);
    }
}

// Run the script
try {
    main();
} catch (Exception $e) {
    logMessage("💥 Critical error: " . $e->getMessage());
    exit(1);
}
?>