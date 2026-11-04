<?php

function ensureMobileSchema(PDO $pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mobile_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_name VARCHAR(100) NOT NULL,
            xodim_id INT NULL,
            full_name VARCHAR(100) NOT NULL DEFAULT '',
            phone VARCHAR(20) NOT NULL,
            pin_hash VARCHAR(255) NOT NULL,
            role ENUM('employee','branch','super') NOT NULL DEFAULT 'employee',
            spot_id INT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            failed_attempts INT NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mobile_phone (phone),
            KEY idx_mobile_account (account_name)
        ) CHARACTER SET utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mobile_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_token_hash (token_hash),
            KEY idx_token_user (user_id)
        ) CHARACTER SET utf8mb4
    ");
    $done = true;
}

function normalizeMobilePhone($phone)
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    return strlen($digits) >= 9 ? substr($digits, -9) : '';
}
