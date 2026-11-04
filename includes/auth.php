<?php
// includes/auth.php

function isLoggedIn() {
    if (session_status() === PHP_SESSION_NONE) return false;
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function login($data) {
    $_SESSION['logged_in'] = true;
    $_SESSION['access_token'] = $data['access_token'] ?? null;
    $_SESSION['account_number'] = $data['account_number'] ?? null;
    $_SESSION['account_name'] = $data['account_name'] ?? null;

    // MUHIM: account_id ham o'rnatiladi (Poster account_number asosida),
    // aks holda barcha modullarda $_SESSION['account_id'] har doim 0 bo'lib qoladi
    // va hisoblar bir-biridan ajratilmaydi.
    $_SESSION['account_id'] = $data['account_number'] ?? null;
}

function logout() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $cookieParams = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $cookieParams['path'] ?: '/',
            'domain' => $cookieParams['domain'],
            'secure' => $cookieParams['secure'],
            'httponly' => true,
            'samesite' => $cookieParams['samesite'] ?: 'Lax',
        ]);
    }
    $_SESSION = array();
    session_destroy();
}