<?php

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => false,
    'message' => 'Bu eski sessiya API o‘chirildi. Poster OAuth orqali qayta kiring.',
], JSON_UNESCAPED_UNICODE);
