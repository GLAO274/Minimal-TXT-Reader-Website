<?php
require_once 'config.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', 1);

session_start();

function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function get_client_ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return trim($ip);
}

function check_rate_limit($ip) {
    $lock_file = 'rate_limit.lock';
    $data_file = 'rate_limit.json';
    
    $fp = fopen($lock_file, 'c');
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return ['allowed' => false, 'reason' => 'system_busy'];
    }
    
    $data = file_exists($data_file) 
        ? json_decode(file_get_contents($data_file), true) 
        : [];
    
    // 清理过期记录
    $now = time();
    $cleanup_threshold = 7 * 24 * 3600; // 7天
    $cleaned = false;
    
    if (is_array($data)) {
        foreach ($data as $recorded_ip => $ip_data) {
            if (isset($ip_data['last_attempt_time']) && 
                ($now - $ip_data['last_attempt_time'] > $cleanup_threshold) &&
                (!isset($ip_data['locked_until']) || $ip_data['locked_until'] <= $now)) {
                unset($data[$recorded_ip]);
                $cleaned = true;
            }
        }
        
        if ($cleaned) {
            file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT));
        }
    }
    
    $ip_data = $data[$ip] ?? [
        'failed_attempts' => 0,
        'last_attempt_time' => 0,
        'locked_until' => 0
    ];
    
    if (isset($ip_data['locked_until']) && $ip_data['locked_until'] > $now) {
        $remaining = $ip_data['locked_until'] - $now;
        flock($fp, LOCK_UN);
        fclose($fp);
        return [
            'allowed' => false, 
            'reason' => 'locked',
            'remaining_seconds' => $remaining
        ];
    }
    
    if (isset($ip_data['locked_until']) && $ip_data['locked_until'] > 0 && $ip_data['locked_until'] <= $now) {
        $ip_data['failed_attempts'] = 0;
        $ip_data['locked_until'] = 0;
    }
    
    flock($fp, LOCK_UN);
    fclose($fp);
    return ['allowed' => true, 'ip_data' => $ip_data];
}

function record_failed_attempt($ip) {
    $lock_file = 'rate_limit.lock';
    $data_file = 'rate_limit.json';
    
    $fp = fopen($lock_file, 'c');
    flock($fp, LOCK_EX);
    
    $data = file_exists($data_file) 
        ? json_decode(file_get_contents($data_file), true) 
        : [];
    
    if (!is_array($data)) {
        $data = [];
    }
    
    $ip_data = $data[$ip] ?? ['failed_attempts' => 0];
    $ip_data['failed_attempts']++;
    $ip_data['last_attempt_time'] = time();
    
    if ($ip_data['failed_attempts'] >= 3) {
        $ip_data['locked_until'] = time() + (15 * 60);
    }
    
    $data[$ip] = $ip_data;
    file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT));
    
    flock($fp, LOCK_UN);
    fclose($fp);
    
    return $ip_data;
}

function reset_failed_attempts($ip) {
    $lock_file = 'rate_limit.lock';
    $data_file = 'rate_limit.json';
    
    $fp = fopen($lock_file, 'c');
    flock($fp, LOCK_EX);
    
    $data = file_exists($data_file) 
        ? json_decode(file_get_contents($data_file), true) 
        : [];
    
    if (is_array($data) && isset($data[$ip])) {
        unset($data[$ip]);
        file_put_contents($data_file, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    flock($fp, LOCK_UN);
    fclose($fp);
}

function validate_password_strength($password) {
    if (strlen($password) < 8) {
        return ['valid' => false, 'message' => '密码至少需要8位'];
    }
    if (strlen($password) > 256) {
        return ['valid' => false, 'message' => '密码最多256位'];
    }
    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'message' => '密码必须包含数字'];
    }
    if (!preg_match('/[a-zA-Z]/', $password)) {
        return ['valid' => false, 'message' => '密码必须包含字母'];
    }
    return ['valid' => true];
}

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
        unset($_SESSION['secret_authenticated']);
        unset($_SESSION['secret_login_time']);
        unset($_SESSION['secret_last_activity']);
        unset($_SESSION['secret_login_type']);
        return false;
    }
    
    $_SESSION['secret_last_activity'] = $now;
    return true;
}

function refresh_session_id() {
    session_regenerate_id(true);
}

// ==================== 处理请求 ====================

header('Content-Type: application/json');

$request_data = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

// 登录请求
if (isset($request_data['secret_action']) && $request_data['secret_action'] === 'login') {
    
    // 如果已登录，直接返回
    if (check_secret_authentication()) {
        echo json_encode(['success' => true, 'message' => '已登录']);
        exit;
    }
    
    if (!isset($request_data['csrf']) || !verify_csrf_token($request_data['csrf'])) {
        echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
        exit;
    }
    
    $ip = get_client_ip();
    $rate_check = check_rate_limit($ip);
    
    if (!$rate_check['allowed']) {
        if ($rate_check['reason'] === 'locked') {
            $minutes = ceil($rate_check['remaining_seconds'] / 60);
            echo json_encode([
                'success' => false, 
                'message' => "登录失败次数过多，请等待 {$minutes} 分钟后重试"
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '系统繁忙，请稍后重试']);
        }
        exit;
    }
    
    $password = $request_data['password'] ?? '';
    
    // 验证长度
    if (strlen($password) > 256) {
        echo json_encode(['success' => false, 'message' => '密码过长']);
        exit;
    }
    
    // 检查初始密码 JSON 文件
    if (file_exists($initial_password_file)) {
        $initial_data = json_decode(file_get_contents($initial_password_file), true);
        
        if (isset($initial_data['is_initial']) && $initial_data['is_initial'] === true) {
            $initial_password = $initial_data['initial_password'] ?? '';
            
            if ($password === $initial_password) {
                $_SESSION['secret_authenticated'] = true;
                $_SESSION['secret_login_time'] = time();
                $_SESSION['secret_last_activity'] = time();
                $_SESSION['secret_must_setup_passwords'] = true;
                $_SESSION['secret_login_type'] = 'master';
                
                reset_failed_attempts($ip);
                
                echo json_encode([
                    'success' => true, 
                    'message' => '登录成功',
                    'must_setup_passwords' => true
                ]);
                exit;
            }
        }
    }
    
    // 检查配置文件中的密码
    if (file_exists('secret_config.json')) {
        $config = json_decode(file_get_contents('secret_config.json'), true);
        
        // 尝试 Master Key
        if (isset($config['master_key_hash']) && password_verify($password, $config['master_key_hash'])) {
            $_SESSION['secret_authenticated'] = true;
            $_SESSION['secret_login_time'] = time();
            $_SESSION['secret_last_activity'] = time();
            $_SESSION['secret_login_type'] = 'master';
            
            reset_failed_attempts($ip);
            
            echo json_encode(['success' => true, 'message' => '登录成功']);
            exit;
        }
        
        // 尝试 User Key
        if (isset($config['user_key_hash']) && password_verify($password, $config['user_key_hash'])) {
            $_SESSION['secret_authenticated'] = true;
            $_SESSION['secret_login_time'] = time();
            $_SESSION['secret_last_activity'] = time();
            $_SESSION['secret_login_type'] = 'user';
            
            reset_failed_attempts($ip);
            
            echo json_encode(['success' => true, 'message' => '登录成功']);
            exit;
        }
    }
    
    // 登录失败
    $ip_data = record_failed_attempt($ip);
    $remaining_attempts = 3 - $ip_data['failed_attempts'];
    
    if ($remaining_attempts > 0) {
        echo json_encode([
            'success' => false, 
            'message' => "密码错误，还可尝试 {$remaining_attempts} 次"
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => '登录失败次数过多，已锁定15分钟'
        ]);
    }
    exit;
}

// 首次设置密码请求
if (isset($request_data['secret_action']) && $request_data['secret_action'] === 'setup_passwords') {
    
    if (!isset($request_data['csrf']) || !verify_csrf_token($request_data['csrf'])) {
        echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
        exit;
    }
    
    if (!check_secret_authentication()) {
        echo json_encode(['success' => false, 'message' => '未登录']);
        exit;
    }
    
    if (!isset($_SESSION['secret_must_setup_passwords']) || $_SESSION['secret_must_setup_passwords'] !== true) {
        echo json_encode(['success' => false, 'message' => '无需设置']);
        exit;
    }
    
    $master_key = $request_data['master_key'] ?? '';
    $user_key = $request_data['user_key'] ?? '';
    
    // 验证长度
    if (strlen($master_key) > 256 || strlen($user_key) > 256) {
        echo json_encode(['success' => false, 'message' => '密码过长']);
        exit;
    }
    
    // 验证复杂度
    $master_validation = validate_password_strength($master_key);
    if (!$master_validation['valid']) {
        echo json_encode(['success' => false, 'message' => '主密码: ' . $master_validation['message']]);
        exit;
    }
    
    $user_validation = validate_password_strength($user_key);
    if (!$user_validation['valid']) {
        echo json_encode(['success' => false, 'message' => '用户密码: ' . $user_validation['message']]);
        exit;
    }
    
    // 验证两个密码不能相同
    if ($master_key === $user_key) {
        echo json_encode(['success' => false, 'message' => '主密码和用户密码不能相同']);
        exit;
    }
    
    $master_hash = password_hash($master_key, PASSWORD_DEFAULT);
    $user_hash = password_hash($user_key, PASSWORD_DEFAULT);
    
    $config = [
        'master_key_hash' => $master_hash,
        'user_key_hash' => $user_hash,
        'directory_name' => $secret_dir,
        'created_at' => time(),
        'updated_at' => time()
    ];
    
    file_put_contents('secret_config.json', json_encode($config, JSON_PRETTY_PRINT));
    
    // 只有在成功创建配置文件后，才标记初始密码失效
    if (file_exists($initial_password_file)) {
        $initial_data = json_decode(file_get_contents($initial_password_file), true);
        $initial_data['is_initial'] = false;
        file_put_contents($initial_password_file, json_encode($initial_data, JSON_PRETTY_PRINT));
    }
    
    unset($_SESSION['secret_must_setup_passwords']);
    $_SESSION['secret_login_type'] = 'master';
    
    echo json_encode(['success' => true, 'message' => '密码设置成功']);
    exit;
}

// 修改密码请求
if (isset($request_data['secret_action']) && $request_data['secret_action'] === 'change_passwords') {
    
    if (!isset($request_data['csrf']) || !verify_csrf_token($request_data['csrf'])) {
        echo json_encode(['success' => false, 'message' => 'CSRF验证失败']);
        exit;
    }
    
    if (!check_secret_authentication()) {
        echo json_encode(['success' => false, 'message' => '未登录']);
        exit;
    }
    
    // 只有 Master Key 登录才能修改密码
    if (!isset($_SESSION['secret_login_type']) || $_SESSION['secret_login_type'] !== 'master') {
        echo json_encode(['success' => false, 'message' => '权限不足，只有主密码可以修改密码']);
        exit;
    }
    
    $current_master = $request_data['current_master'] ?? '';
    $change_master = $request_data['change_master'] ?? 'false';
    $change_user = $request_data['change_user'] ?? 'false';
    $new_master = $request_data['new_master'] ?? '';
    $new_user = $request_data['new_user'] ?? '';
    
    // 验证长度
    if (strlen($current_master) > 256 || strlen($new_master) > 256 || strlen($new_user) > 256) {
        echo json_encode(['success' => false, 'message' => '密码过长']);
        exit;
    }
    
    // 至少要修改一个
    if ($change_master !== 'true' && $change_user !== 'true') {
        echo json_encode(['success' => false, 'message' => '请至少选择修改一个密码']);
        exit;
    }
    
    // 读取当前配置
    if (!file_exists('secret_config.json')) {
        echo json_encode(['success' => false, 'message' => '配置文件不存在']);
        exit;
    }
    
    $config = json_decode(file_get_contents('secret_config.json'), true);
    
    // 验证当前 Master Key
    if (!isset($config['master_key_hash']) || !password_verify($current_master, $config['master_key_hash'])) {
        echo json_encode(['success' => false, 'message' => '当前主密码错误']);
        exit;
    }
    
    // 验证新密码
    if ($change_master === 'true') {
        $validation = validate_password_strength($new_master);
        if (!$validation['valid']) {
            echo json_encode(['success' => false, 'message' => '新主密码: ' . $validation['message']]);
            exit;
        }
    }
    
    if ($change_user === 'true') {
        $validation = validate_password_strength($new_user);
        if (!$validation['valid']) {
            echo json_encode(['success' => false, 'message' => '新用户密码: ' . $validation['message']]);
            exit;
        }
    }
    
    // 如果两个都改，验证不能相同
    if ($change_master === 'true' && $change_user === 'true') {
        if ($new_master === $new_user) {
            echo json_encode(['success' => false, 'message' => '主密码和用户密码不能相同']);
            exit;
        }
    }
    
    // 如果只改其中一个，验证与另一个不能相同
    if ($change_master === 'true' && $change_user !== 'true') {
        // 只改 Master，验证与现有 User 不同
        if (isset($config['user_key_hash']) && password_verify($new_master, $config['user_key_hash'])) {
            echo json_encode(['success' => false, 'message' => '主密码不能与用户密码相同']);
            exit;
        }
    }
    
    if ($change_user === 'true' && $change_master !== 'true') {
        // 只改 User，验证与现有 Master 不同
        if (isset($config['master_key_hash']) && password_verify($new_user, $config['master_key_hash'])) {
            echo json_encode(['success' => false, 'message' => '用户密码不能与主密码相同']);
            exit;
        }
    }
    
    // 更新密码
    if ($change_master === 'true') {
        $config['master_key_hash'] = password_hash($new_master, PASSWORD_DEFAULT);
    }
    
    if ($change_user === 'true') {
        $config['user_key_hash'] = password_hash($new_user, PASSWORD_DEFAULT);
    }
    
    $config['updated_at'] = time();
    
    file_put_contents('secret_config.json', json_encode($config, JSON_PRETTY_PRINT));
    
    echo json_encode(['success' => true, 'message' => '密码修改成功']);
    exit;
}

// 无效请求
echo json_encode(['success' => false, 'message' => '无效的请求']);
?>