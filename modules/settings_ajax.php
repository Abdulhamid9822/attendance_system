<?php

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'message' => 'This legacy endpoint is disabled.']);
