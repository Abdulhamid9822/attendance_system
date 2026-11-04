<?php
// includes/session_handler.php
class DatabaseSessionHandler implements SessionHandlerInterface {
    private $pdo;
    private $table = 'sessions';
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function open($savePath, $sessionName): bool {
        return true;
    }
    
    public function close(): bool {
        return true;
    }
    
    public function read($sessionId): string|false {
        try {
            $stmt = $this->pdo->prepare("SELECT data FROM {$this->table} WHERE id = ? AND expires > ?");
            $stmt->execute([$sessionId, time()]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row['data'] : '';
        } catch (Exception $e) {
            return '';
        }
    }
    
    public function write($sessionId, $data): bool {
        try {
            $expires = time() + 3600;
            $stmt = $this->pdo->prepare("
                INSERT INTO {$this->table} (id, data, expires) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                    data = VALUES(data), 
                    expires = VALUES(expires)
            ");
            return $stmt->execute([$sessionId, $data, $expires]);
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function destroy($sessionId): bool {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?");
            return $stmt->execute([$sessionId]);
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function gc($maxlifetime): int|false {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE expires < ?");
            return $stmt->execute([time()]);
        } catch (Exception $e) {
            return 0;
        }
    }
}
?>