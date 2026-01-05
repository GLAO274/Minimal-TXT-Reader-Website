<?php
require_once 'config.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', 1);

session_start();

function check_secret_authentication() {
    global $secret_session_lifetime;
    
    if (!isset($_SESSION['secret_authenticated']) || $_SESSION['secret_authenticated'] !== true) {
        return false;
    }
    
    if (!isset($_SESSION['secret_login_time']) || !isset($_SESSION['secret_last_activity'])) {
        return false;
    }
    
    $now = time();
    if ($now - $_SESSION['secret_last_activity'] > $secret_session_lifetime) {
        return false;
    }
    
    $_SESSION['secret_last_activity'] = $now;
    return true;
}

// 验证登录
if (!check_secret_authentication()) {
    http_response_code(403);
    exit;
}

// 获取图片路径
$path = $_GET['path'] ?? '';

// 验证路径安全
if (strpos($path, '..') !== false || strpos($path, "\0") !== false) {
    http_response_code(403);
    exit;
}

$image_path = $secret_dir . '/' . $path;

// 验证文件存在且在 secret 目录内
$real_secret = realpath($secret_dir);
$real_image = realpath($image_path);

if ($real_image === false || $real_secret === false || strpos($real_image, $real_secret) !== 0) {
    http_response_code(404);
    exit;
}

if (!file_exists($image_path) || !is_file($image_path)) {
    http_response_code(404);
    exit;
}

// 获取 MIME 类型
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $image_path);
finfo_close($finfo);

// 输出图片
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($image_path));
readfile($image_path);
exit;
?>