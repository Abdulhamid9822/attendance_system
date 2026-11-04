<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============ TOKENNI O'QISH ============
$access_token = null;
$account_name = null;

// 1-usul: token.txt va account.txt dan o'qish
if(file_exists("token.txt")) {
    $token_content = file_get_contents("token.txt");
    $token_data = json_decode($token_content, true);
    
    if($token_data && isset($token_data['access_token'])) {
        // JSON formatda saqlangan
        $access_token = $token_data['access_token'];
        $account_name = $token_data['account_name'] ?? null;
    } else {
        // Plain text formatda saqlangan
        $access_token = trim($token_content);
    }
}

// Agar account alohida faylda bo'lsa
if(file_exists("account.txt") && !$account_name) {
    $account_name = trim(file_get_contents("account.txt"));
}

// Agar account topilmasa, default qiymat
if(!$account_name) {
    $account_name = "truthpos";
}

// Token tekshirish
if(!$access_token) {
    die("❌ Token topilmadi! <a href='get_new_token.php'>Yangi token olish</a>");
}

// ============ NATIJANI KO'RSATISH ============
echo "Account: " . $account_name . "<br>";
echo "Token: " . $access_token . "<br><br>";

// ============ SERVER VAQTINI TEKSHIRISH ============
echo "<h3>⏰ Server vaqti:</h3>";
$url = "https://joinposter.com/api/serverTime?token=" . urlencode($access_token) . "&account=" . urlencode($account_name);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if(isset($data['response'])) {
    echo "✅ API ishlayapti! Server vaqti: " . date('Y-m-d H:i:s', $data['response']) . "<br>";
} else {
    echo "❌ Xato: " . print_r($data, true) . "<br>";
    echo "<br><a href='reset_token.php'>Tokenlarni o'chirib, qayta urining</a>";
    exit;
}

echo "<br><hr><br>";

// ============ MAHSULOTLARNI OLISH ============
echo "<h3>📦 Mahsulotlar:</h3>";
$url = "https://joinposter.com/api/products?token=" . urlencode($access_token) . "&account=" . urlencode($account_name);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if(isset($data['response']) && count($data['response']) > 0) {
    foreach($data['response'] as $product) {
        echo "▪️ " . ($product['product_name'] ?? 'Noma\'lum') . " - " . ($product['product_price'] ?? 0) . " so'm<br>";
    }
    echo "<br><strong>Jami:</strong> " . count($data['response']) . " ta mahsulot";
} else {
    echo "Hech qanday mahsulot topilmadi yoki xato:<br>";
    print_r($data);
}

echo "<br><hr><br>";

// ============ KATEGORIYALARNI OLISH ============
echo "<h3>📂 Kategoriyalar:</h3>";
$url = "https://joinposter.com/api/menu/getCategories?token=" . urlencode($access_token) . "&account=" . urlencode($account_name);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if(isset($data['response']) && count($data['response']) > 0) {
    foreach($data['response'] as $category) {
        echo "▪️ " . $category['category_name'] . " (ID: " . $category['category_id'] . ")<br>";
    }
} else {
    echo "Hech qanday kategoriya topilmadi yoki xato:<br>";
    print_r($data);
}
?>