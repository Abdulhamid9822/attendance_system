<?php
// oauth2callback.php

require_once __DIR__ . '/vendor/autoload.php';

use Google\Client as GoogleClient;

$client = new GoogleClient();
$client->setApplicationName('Database Backup');
$client->setScopes('https://www.googleapis.com/auth/drive.file');
$client->setAuthConfig(__DIR__ . '/credentials.json');
$client->setAccessType('offline');
$client->setPrompt('select_account consent');

// Domen bilan redirect
$redirectUri = 'https://attendance-face.online/oauth2callback.php';
$client->setRedirectUri($redirectUri);

if (!isset($_GET['code'])) {
    $authUrl = $client->createAuthUrl();
    header('Location: ' . filter_var($authUrl, FILTER_SANITIZE_URL));
    exit;
} else {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    $client->setAccessToken($token);
    
    // Windows uchun yo'l
    $tokenPath = __DIR__ . '/token.json';
    file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    
    echo "<h1>✅ Muvaffaqiyatli!</h1>";
    echo "<p>Token saqlandi: {$tokenPath}</p>";
    echo "<p><a href='https://attendance-face.online/'>Dashboardga qaytish</a></p>";
    exit;
}