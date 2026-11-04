<?php

http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo 'This legacy callback is disabled. Start authentication from /login.php.';
