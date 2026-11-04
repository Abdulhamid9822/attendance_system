// includes/cache.php
<?php
function cacheGet($key, $ttl = 3600) {
    $file = __DIR__ . '/../cache/' . md5($key) . '.cache';
    if(file_exists($file) && (time() - filemtime($file)) < $ttl) {
        return unserialize(file_get_contents($file));
    }
    return null;
}

function cacheSet($key, $data) {
    $file = __DIR__ . '/../cache/' . md5($key) . '.cache';
    file_put_contents($file, serialize($data));
}
?>