<?php
require_once 'config.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', 1);

session_start();

if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

function refresh_session_id() {
    session_regenerate_id(true);
}

// ==================== CSRF 函数 ====================

function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ==================== 秘密书架函数 ====================

function check_secret_authentication() {
    global $secret_session_lifetime, $library_id;
    
    // 验证库 ID
    if (!isset($_SESSION['authenticated_library_id']) || $_SESSION['authenticated_library_id'] !== $library_id) {
        return false;
    }
    
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
        unset($_SESSION['authenticated_library_id']);
        return false;
    }
    
    $_SESSION['secret_last_activity'] = $now;
    return true;
}

function init_secret_config($secret_dir) {
    if (is_dir($secret_dir) && !file_exists("$secret_dir/.htaccess")) {
        $htaccess_content = <<<'HTACCESS'
Options -Indexes

<FilesMatch ".*">
    Require all denied
</FilesMatch>
HTACCESS;
        file_put_contents("$secret_dir/.htaccess", $htaccess_content);
    }
}

function check_and_update_secret_dir($secret_dir) {
    $config_file = 'secret_config.json';
    
    if (!file_exists($config_file)) {
        return;
    }
    
    $config = json_decode(file_get_contents($config_file), true);
    $configured_dir = $config['directory_name'] ?? null;
    
    if ($configured_dir && $configured_dir !== $secret_dir) {
        if (file_exists("$configured_dir/.htaccess")) {
            @unlink("$configured_dir/.htaccess");
        }
        
        $config['directory_name'] = $secret_dir;
        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
    }
    
    init_secret_config($secret_dir);
}

// 初始化秘密书架配置
init_secret_config($secret_dir);
check_and_update_secret_dir($secret_dir);

// 登出请求
if (isset($_GET['secret_action']) && $_GET['secret_action'] === 'logout') {
    if (isset($_GET['csrf']) && verify_csrf_token($_GET['csrf'])) {
        unset($_SESSION['secret_authenticated']);
        unset($_SESSION['secret_login_time']);
        unset($_SESSION['secret_last_activity']);
        unset($_SESSION['secret_must_change_password']);
        unset($_SESSION['authenticated_library_id']);
        refresh_session_id();
    }
    header('Location: index.php');
    exit;
}

// ==================== 验证函数 ====================

function validate_file_path($file_path, $allowed_base_dir) {
    $real_base = realpath($allowed_base_dir);
    $real_file = realpath($file_path);
    if ($real_file === false) return false;
    if ($real_base === false || strpos($real_file, $real_base) !== 0) return false;
    return true;
}

function validate_integer($value, $min = 1, $max = PHP_INT_MAX) {
    $int_value = filter_var($value, FILTER_VALIDATE_INT);
    if ($int_value === false || $int_value < $min || $int_value > $max) return false;
    return $int_value;
}

// 获取参数
function sanitize_filename($name) {
    $name = trim($name);
    $illegal_chars = ['\\', '/', ':', '*', '?', '"', '<', '>', '|', ';', '..', "\0"];
    foreach ($illegal_chars as $char) {
        if (strpos($name, $char) !== false) {
            header('Location: index.php');
            exit;
        }
    }
    $name = basename($name);
    if (empty($name) || $name === '.' || $name === '..') {
        header('Location: index.php');
        exit;
    }
    return $name;
}

$action = isset($_GET['action']) ? sanitize_filename($_GET['action']) : 'select_book';
$book = isset($_GET['book']) ? sanitize_filename($_GET['book']) : '';
$chapter = isset($_GET['chapter']) ? sanitize_filename($_GET['chapter']) : '';
$page_param = isset($_GET['page']) ? validate_integer($_GET['page'], 1, 999999) : 1;
$page = $page_param !== false ? $page_param : 1;
$is_secret = isset($_GET['secret']) && $_GET['secret'] === '1';

// 处理日夜模式切换请求
if (isset($_GET['mode']) && $_GET['mode'] == '1') {
    if (isset($_GET['csrf']) && verify_csrf_token($_GET['csrf'])) {
        $_SESSION['light_mode'] = !isset($_SESSION['light_mode']) || !$_SESSION['light_mode'];
    }
    exit;
}

// 获取当前的日夜模式
$mode_class = isset($_SESSION['light_mode']) && $_SESSION['light_mode'] ? 'light-mode' : 'dark-mode';

// 处理字体大小设置请求
if (isset($_GET['size'])) {
    if (isset($_GET['csrf']) && verify_csrf_token($_GET['csrf'])) {
        $size_map = [
            'small' => $font_size_small,
            'medium' => $font_size_medium,
            'large' => $font_size_large,
        ];
        // 设置字体大小
        if (array_key_exists($_GET['size'], $size_map)) {
            $_SESSION['font_size'] = $size_map[$_GET['size']];
        } else {
            $_SESSION['font_size'] = $font_size_large; 
        }
    }
    exit;
}

// 获取当前字体大小
$font_size = isset($_SESSION['font_size']) ? $_SESSION['font_size'] : $font_size_large; // 默认为大号字体

// 章节排序函数
function getSortedChapters($book_dir) {
    $chapters = array_filter(glob("$book_dir/*"), function ($chapter) {
        return is_file($chapter) && pathinfo($chapter, PATHINFO_EXTENSION) !== 'json';
    });
    
    if (empty($chapters)) {
        header('Location: index.php');
        exit;
    }

    usort($chapters, function ($a, $b) {
        // 序章识别与排序
        $prologueKeywords = [
            '序', '序章', '序言', '序曲', '前言', '前序', '引子', '引言', '楔子',
            '开篇', '開篇', '开场', '開場', '开端', '開端', '缘起', '緣起',
            '导言', '導言', '导读', '導讀', '绪论', '緒論',
            'prologue', 'preface', 'foreword', 'introduction', 'intro', 'prelude', 'opening'
        ];
        
        $isPrologue = function ($filename) use ($prologueKeywords) {
            $name = pathinfo(basename($filename), PATHINFO_FILENAME);
            $lower = mb_strtolower($name);
            
            foreach ($prologueKeywords as $keyword) {
                // 完全匹配
                if ($name === $keyword || $lower === $keyword) {
                    return true;
                }
                // 前缀匹配(支持"序章1"、"Prologue 1"等)
                $keywordLower = mb_strtolower($keyword);
                if (mb_strpos($lower, $keywordLower) === 0) {
                    $rest = mb_substr($lower, mb_strlen($keywordLower));
                    // 后面是空白、数字或中文数字
                    if (empty($rest) || 
                        preg_match('/^[\s\d一二三四五六七八九十壹贰贰叁肆伍陆柒捌玖拾]+/u', $rest)) {
                        return true;
                    }
                }
            }
            
            return false;
        };
        
        $aIsPrologue = $isPrologue($a);
        $bIsPrologue = $isPrologue($b);
        
        // 同为序章时:纯序章排在前
        if ($aIsPrologue && $bIsPrologue) {
            $nameA = mb_strtolower(pathinfo(basename($a), PATHINFO_FILENAME));
            $nameB = mb_strtolower(pathinfo(basename($b), PATHINFO_FILENAME));
            
            $aPure = in_array($nameA, array_map('mb_strtolower', $prologueKeywords), true);
            $bPure = in_array($nameB, array_map('mb_strtolower', $prologueKeywords), true);
            
            if ($aPure && !$bPure) return -1;
            if (!$aPure && $bPure) return 1;
        }
        
        // 序章优先
        if ($aIsPrologue && !$bIsPrologue) return -1;
        if (!$aIsPrologue && $bIsPrologue) return 1;
        
        // 提取排序信息
        $extractOrder = function ($string) {
            static $cache = [];

            if (isset($cache[$string])) {
                return $cache[$string];
            }
            
            // 中文数字解析
            $parseChineseNumber = function ($string) {
                $chineseToNumber = [
                    '零' => 0, '一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4, '五' => 5,
                    '六' => 6, '七' => 7, '八' => 8, '九' => 9
                ];
                
                $traditionalChineseToNumber = [
                    '零' => 0, '壹' => 1, '贰' => 2, '貳' => 2, '两' => 2, '兩' => 2, 
                    '叁' => 3, '參' => 3, '仨' => 3, '肆' => 4, '伍' => 5,
                    '陆' => 6, '陸' => 6, '柒' => 7, '捌' => 8, '玖' => 9
                ];
                
                $allNumbers = array_merge($chineseToNumber, $traditionalChineseToNumber);
                
                $units = [
                    '十' => 10, '拾' => 10,
                    '百' => 100, '佰' => 100,
                    '千' => 1000, '仟' => 1000,
                    '万' => 10000, '萬' => 10000,
                    '亿' => 100000000, '億' => 100000000
                ];
                
                $total = 0;
                $current_section = 0;
                $current_digit = 0;
                $prev_was_unit = false;
                
                foreach (mb_str_split($string) as $char) {
                    if (isset($allNumbers[$char])) {
                        $current_digit = $allNumbers[$char];
                        $prev_was_unit = false;
                    } elseif (isset($units[$char])) {
                        $unit_value = $units[$char];
                        
                        if ($current_digit == 0 && !$prev_was_unit) {
                            $current_digit = 1;
                        }
                        
                        if ($unit_value >= 10000) {
                            if ($current_section == 0 && $current_digit == 0) {
                                $current_section = 1;
                            } else {
                                $current_section += $current_digit;
                            }
                            $total += $current_section * $unit_value;
                            $current_section = 0;
                            $current_digit = 0;
                        } else {
                            $current_section += $current_digit * $unit_value;
                            $current_digit = 0;
                        }
                        
                        $prev_was_unit = true;
                    }
                }
                
                return $total + $current_section + $current_digit;
            };
            
            // 英文数字解析
            $parseEnglishNumber = function ($string) {
                static $mapping = [
                    'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 
                    'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 
                    'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 
                    'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 
                    'thirty' => 30,'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70,
                    'eighty' => 80, 'ninety' => 90, 'hundred' => 100, 'thousand' => 1000,
                    'million' => 1000000, 'billion' => 1000000000
                ];
                $total = 0;
                $current = 0;
                $words = preg_split('/\s+|-/', strtolower($string));

                foreach ($words as $word) {
                    if (isset($mapping[$word])) {
                        $value = $mapping[$word];
                        if ($value >= 1000) {
                            $current = ($current > 0 ? $current : 1) * $value;
                            $total += $current;
                            $current = 0;
                        } elseif ($value >= 100) {
                            $current = ($current > 0 ? $current : 1) * $value;
                        } else {
                            $current += $value;
                        }
                    } else {
                        $total += $current;
                        $current = 0;
                    }
                }
                return $total + $current;
            };
            
            // 罗马数字验证
            $isValidRoman = function ($string) {
                if (empty($string)) {
                    return false;
                }
                
                $string = strtoupper($string);
                
                if (!preg_match('/^[IVXLCDM]+$/', $string)) {
                    return false;
                }
                
                $commonWords = ['IN', 'DIM', 'MIX', 'VIM', 'LID', 'MID', 'MILD', 'LIVID'];
                if (in_array($string, $commonWords)) {
                    return false;
                }
                
                if (strlen($string) > 15) {
                    return false;
                }
                
                if (strpos($string, 'IIII') !== false || 
                    strpos($string, 'XXXX') !== false || 
                    strpos($string, 'CCCC') !== false || 
                    strpos($string, 'MMMM') !== false) {
                    return false;
                }
                
                if (strpos($string, 'VV') !== false || 
                    strpos($string, 'LL') !== false || 
                    strpos($string, 'DD') !== false) {
                    return false;
                }
                
                $invalid = ['IL', 'IC', 'ID', 'IM', 'XD', 'XM', 'VX', 'VL', 'VC', 'VD', 'VM', 'LC', 'LD', 'LM', 'DM'];
                foreach ($invalid as $pattern) {
                    if (strpos($string, $pattern) !== false) {
                        return false;
                    }
                }
                
                return true;
            };
            
            // 罗马数字解析
            $parseRomanNumber = function ($string) {
                $romanToNumber = [
                    'I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000
                ];
                $total = 0;
                $length = strlen($string);

                for ($i = 0; $i < $length; $i++) {
                    $value = $romanToNumber[$string[$i]] ?? 0;
                    $nextValue = $romanToNumber[$string[$i + 1] ?? ''] ?? 0;
                    if ($value < $nextValue) {
                        $total -= $value;
                    } else {
                        $total += $value;
                    }
                }
                return $total;
            };
            
            // 提取数字和文本段
            $extractNumericAndTextSegments = function ($string) use ($parseChineseNumber, $parseEnglishNumber, $parseRomanNumber, $isValidRoman) {
                
                static $unicodeRomanMap = [
                    'Ⅰ' => 1, 'Ⅱ' => 2, 'Ⅲ' => 3, 'Ⅳ' => 4, 'Ⅴ' => 5,
                    'Ⅵ' => 6, 'Ⅶ' => 7, 'Ⅷ' => 8, 'Ⅸ' => 9, 'Ⅹ' => 10,
                    'Ⅺ' => 11, 'Ⅻ' => 12, 'Ⅼ' => 50, 'Ⅽ' => 100, 'Ⅾ' => 500, 'Ⅿ' => 1000,
                    'ⅰ' => 1, 'ⅱ' => 2, 'ⅲ' => 3, 'ⅳ' => 4, 'ⅴ' => 5,
                    'ⅵ' => 6, 'ⅶ' => 7, 'ⅷ' => 8, 'ⅸ' => 9, 'ⅹ' => 10,
                    'ⅺ' => 11, 'ⅻ' => 12, 'ⅼ' => 50, 'ⅽ' => 100, 'ⅾ' => 500, 'ⅿ' => 1000
                ];
                
                // 序章关键词列表
                $prologueKeywords = [
                    '序', '序章', '序言', '序曲',
                    '前言', '前序', '引子', '引言', '楔子', 
                    '开篇', '開篇', '开场', '開場',  
                    '开端', '開端', '缘起', '緣起',  
                    '导言', '導言', '导读', '導讀',  
                    '绪论', '緒論', 
                    'prologue', 'preface', 'foreword', 
                    'introduction', 'intro', 'prelude',
                    'opening'
                ];
                
                $segments = [];
                $numericFound = false;
                preg_match_all('/(\d+)|([^\d]+)/u', $string, $matches);
                
                foreach ($matches[0] as $match) {
                    if (is_numeric($match)) {
                        $segments[] = (int)$match; 
                        $numericFound = true;
                    } elseif (preg_match('/[零一二两兩三四五六七八九十百千万萬亿億壹贰貳叁參仨肆伍陆陸柒捌玖拾佰仟]+/u', $match)) {
                        $segments[] = $parseChineseNumber($match); 
                        $numericFound = true;
                    } else {
                        // 文本段：需要按空格分词处理
                        $words = preg_split('/\s+/', trim($match));
                        
                        foreach ($words as $word) {
                            if (empty($word)) continue;
                            
                            $lowerWord = mb_strtolower($word);
                            
                            // 是否为序章关键词
                            $isPrologueKw = false;
                            foreach ($prologueKeywords as $keyword) {
                                if ($lowerWord === mb_strtolower($keyword)) {
                                    $segments[] = 0; // 序章关键词用0表示
                                    $numericFound = true;
                                    $isPrologueKw = true;
                                    break;
                                }
                            }
                            
                            if ($isPrologueKw) {
                                continue;
                            }
                            
                            // 检查单词中是否包含 Unicode 罗马数字
                            $hasUnicodeRoman = false;
                            foreach (mb_str_split($word) as $char) {
                                if (isset($unicodeRomanMap[$char])) {
                                    $segments[] = $unicodeRomanMap[$char];
                                    $numericFound = true;
                                    $hasUnicodeRoman = true;
                                    break;
                                }
                            }
                            
                            if ($hasUnicodeRoman) {
                                continue;
                            }
                            
                            // 检查罗马数字
                            if (preg_match('/^[a-z]+$/i', $word)) {
                                if ($isValidRoman($word)) {
                                    $segments[] = $parseRomanNumber(strtoupper($word)); 
                                    $numericFound = true;
                                } else {
                                    $value = $parseEnglishNumber($word); 
                                    if ($value != 0) {
                                        $segments[] = $value;
                                        $numericFound = true;
                                    } else {
                                        $segments[] = $word;
                                    }
                                }
                            } else {
                                $segments[] = $word;
                            }
                        }
                    }
                }
                
                return ['hasNumber' => $numericFound, 'segments' => $segments];
            };

            $extracted = $extractNumericAndTextSegments($string);
            return $cache[$string] = $extracted;
        };
        
        $orderA = $extractOrder(basename($a));
        $orderB = $extractOrder(basename($b));
        
        // 确定优先级（三级）
        if ($aIsPrologue) {
            $priorityA = 0;
        } elseif ($orderA['hasNumber']) {
            $priorityA = 1;
        } else {
            $priorityA = 2;
        }
        
        if ($bIsPrologue) {
            $priorityB = 0;
        } elseif ($orderB['hasNumber']) {
            $priorityB = 1;
        } else {
            $priorityB = 2;
        }
        
        // 先按优先级排序
        if ($priorityA != $priorityB) {
            return $priorityA <=> $priorityB;
        }
        
        // 同优先级内按内容排序
        $segmentsA = $orderA['segments'];
        $segmentsB = $orderB['segments'];
        
        $length = max(count($segmentsA), count($segmentsB));
        for ($i = 0; $i < $length; $i++) {
            if (!isset($segmentsA[$i])) return -1; 
            if (!isset($segmentsB[$i])) return 1;  

            if (is_string($segmentsA[$i]) && is_string($segmentsB[$i])) {
                $result = strcmp($segmentsA[$i], $segmentsB[$i]);
            } else {
                $result = $segmentsA[$i] <=> $segmentsB[$i];
            }

            if ($result !== 0) return $result;
        }

        if (count($segmentsA) == count($segmentsB)) {
            return $segmentsA < $segmentsB ? -1 : 1;
        }

        return count($segmentsA) < count($segmentsB) ? -1 : 1;
    });

    return $chapters;
}

// ==================== 页面渲染逻辑 ====================

// 选择书本页面
if ($action === 'select_book') {
    $is_authenticated = check_secret_authentication();
    
    // 获取普通书籍
    $books = array_filter(glob("$books_dir/*"), 'is_dir');
    
    // 按 GBK 编码排序书本名称
    usort($books, function ($a, $b) {
        return strcoll(iconv("UTF-8", "GBK", basename($a)), iconv("UTF-8", "GBK", basename($b)));
    });
    
    // 获取秘密书籍
    $secret_books = [];
    if ($is_authenticated && is_dir($secret_dir)) {
        $secret_books = array_filter(glob("$secret_dir/*"), 'is_dir');
        usort($secret_books, function ($a, $b) {
            return strcoll(iconv("UTF-8", "GBK", basename($a)), iconv("UTF-8", "GBK", basename($b)));
        });
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="robots" content="noindex, nofollow">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="author" content="EAHI">
        <meta name="csrf-token" content="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
        <title>书架</title>
        <link rel="stylesheet" type="text/css" href="style.css?v=40">
    </head>
    <body class="<?php echo $mode_class; ?>">
        <div class="container">
            <div class="header-buttons">
                <?php if ($is_authenticated && isset($_SESSION['secret_login_type']) && $_SESSION['secret_login_type'] === 'master'): ?>
                    <button id="change-password-btn" class="toggle-btn">修改密码</button>
                <?php endif; ?>
                <?php if ($is_authenticated): ?>
                    <button id="logout-btn" class="toggle-btn">登出</button>
                <?php endif; ?>
                <button id="light-mode-toggle" class="toggle-btn">
                    <?php echo $mode_class === "light-mode" ? "关灯" : "开灯"; ?>
                </button>
            </div>
            <h3>书本列表</h3>
            <ul>
                <?php foreach ($books as $book_dir): ?>
                <li><a id="<?php echo $mode_class; ?>" href="?action=select_chapter&book=<?php echo urlencode(basename($book_dir)); ?>"><?php echo htmlspecialchars(basename($book_dir)); ?></a></li>
                <?php endforeach; ?>
                
                <?php if (!empty($secret_books)): ?>
                <li style="list-style: none; margin: 15px 0;"><hr></li>
                <?php endif; ?>
                
                <?php foreach ($secret_books as $book_dir): ?>
                <li><a id="<?php echo $mode_class; ?>" href="?action=select_chapter&book=<?php echo urlencode(basename($book_dir)); ?>&secret=1"><?php echo htmlspecialchars(basename($book_dir)); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        
        <!-- 隐藏触发区域 -->
        <div id="secret-trigger"></div>
        
        <script src="script.js?v=26"></script>
    </body>
    </html>
    <?php
    exit;

// 选择章节页面
} elseif ($action === 'select_chapter' && $book) {
    // 确定书籍目录
    if ($is_secret) {
        if (!check_secret_authentication()) {
            header('Location: index.php');
            exit;
        }
        $book_path = "$secret_dir/$book";
        $base_dir = $secret_dir;
    } else {
        $book_path = "$books_dir/$book";
        $base_dir = $books_dir;
    }
    
    if (!validate_file_path($book_path, $base_dir) || !is_dir($book_path)) {
        header('Location: index.php');
        exit;
    }
    
    $chapters = getSortedChapters($book_path);
    
    // 支持的图片格式
    $image_formats = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="robots" content="noindex, nofollow">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="author" content="EAHI">
        <meta name="csrf-token" content="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
        <title><?php echo htmlspecialchars($book); ?> - 章节列表</title>
        <link rel="stylesheet" type="text/css" href="style.css?v=40">
    </head>
    <body class="<?php echo $mode_class; ?>">
        <div class="container">
            <div class="header-buttons">
                <?php if ($is_secret && check_secret_authentication()): ?>
                    <button id="logout-btn" class="toggle-btn">登出</button>
                <?php endif; ?>
                <button id="light-mode-toggle" class="toggle-btn">
                 <?php echo $mode_class === "light-mode" ? "关灯" : "开灯"; ?>
                </button>
            </div>
            <h3><?php echo htmlspecialchars($book); ?></h3>
            <h4>章节列表</h4>
            <ul>
            <?php foreach ($chapters as $chapter): ?>
                <?php
                $extension = strtolower(pathinfo($chapter, PATHINFO_EXTENSION));
                $secret_param = $is_secret ? '&secret=1' : '';
                // 如果章节是图片
                if (in_array($extension, $image_formats)): ?>
                    <li>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode(basename($chapter)); ?><?php echo $secret_param; ?>">
                            <?php echo htmlspecialchars(basename($chapter, '.' . $extension)); ?>
                        </a>
                    </li>
                <?php else: // 正常文字章节 ?>
                    <li>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode(basename($chapter, '.' . $extension)); ?>&page=1<?php echo $secret_param; ?>">
                            <?php echo htmlspecialchars(basename($chapter, '.' . $extension)); ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
            </ul>
            <a id="<?php echo $mode_class; ?>" href="?action=select_book">返回书本选择</a>
        </div>
        <script src="script.js?v=26"></script>
    </body>
    </html>
    <?php
    exit;

// 文章内容页面
} elseif ($action === 'read' && $book && $chapter) {
    // 确定书籍目录
    if ($is_secret) {
        if (!check_secret_authentication()) {
            header('Location: index.php');
            exit;
        }
        $book_path = "$secret_dir/$book";
        $base_dir = $secret_dir;
    } else {
        $book_path = "$books_dir/$book";
        $base_dir = $books_dir;
    }
    
    if (!validate_file_path($book_path, $base_dir) || !is_dir($book_path)) {
        header('Location: index.php');
        exit;
    }
    
    $chapter_path = "$book_path/$chapter";
    $extension = strtolower(pathinfo($chapter_path, PATHINFO_EXTENSION));
    
    // 支持的图片格式
    $image_formats = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    $secret_param = $is_secret ? '&secret=1' : '';
    
    // 如果章节是图片
    if (in_array($extension, $image_formats)) {
        
        // 获取章节列表
        $chapters = getSortedChapters($book_path);
        $chapters = array_map(function($chapter_path) {
            $extension_is_txt = pathinfo($chapter_path, PATHINFO_EXTENSION);
            if ($extension_is_txt === 'txt') {
                return pathinfo($chapter_path, PATHINFO_FILENAME); 
            }
            return basename($chapter_path); 
        }, $chapters);
        
        // 获取当前章节索引
        $current_chapter_index = array_search($chapter, $chapters);
        
        // 确定前一章节
        $previous_chapter = $current_chapter_index > 0 ? basename($chapters[$current_chapter_index - 1]) : null;
        if ($previous_chapter !== null && substr($previous_chapter, -4) === '.txt') {
            $previous_chapter = substr($previous_chapter, 0, -4);
        }
        
        // 确定后一章节
        $next_chapter = $current_chapter_index < count($chapters) - 1 ? basename($chapters[$current_chapter_index + 1]) : null;
        if ($next_chapter !== null && substr($next_chapter, -4) === '.txt') {
            $next_chapter = substr($next_chapter, 0, -4);
        }
        
        // 对于秘密书架的图片，通过 image.php 输出
        if ($is_secret) {
            $book_name = basename($book_path);
            $image_url = "image.php?path=" . urlencode($book_name . '/' . $chapter);
        } else {
            $image_url = htmlspecialchars($chapter_path);
        }
        
    ?>
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="UTF-8">
            <meta name="robots" content="noindex, nofollow">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <meta name="author" content="EAHI">
            <meta name="csrf-token" content="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
            <title><?php echo htmlspecialchars(basename($chapter, '.' . $extension)); ?></title>
            <link rel="stylesheet" type="text/css" href="style.css?v=40">
        </head>
        <body class="<?php echo $mode_class; ?>">
            <div class="container">
                <div class="header-buttons">
                    <?php if ($is_secret && check_secret_authentication()): ?>
                        <button id="logout-btn" class="toggle-btn">登出</button>
                    <?php endif; ?>
                    <button id="light-mode-toggle" class="toggle-btn">
                     <?php echo $mode_class === "light-mode" ? "关灯" : "开灯"; ?>
                    </button>
                </div>
                <h3><?php echo htmlspecialchars(basename($chapter, '.' . $extension)); ?></h3>
                <div class="content" style="text-align: center;">
                    <img src="<?php echo $image_url; ?>" alt="<?php echo htmlspecialchars($chapter); ?>" style="max-width: 100%; height: auto;">
                </div>
                <div class="navigation">
                    <?php if ($previous_chapter != null): ?>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode($previous_chapter); ?>&page=1<?php echo $secret_param; ?>">上一章节</a>
                        <?php echo " | "; ?>
                    <?php endif; ?>
                    <?php if ($next_chapter != null): ?>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode($next_chapter); ?>&page=1<?php echo $secret_param; ?>">下一章节</a>
                    <?php endif; ?>
                    <?php if ($next_chapter === null): ?>
                        <span>无后续章节</span>
                    <?php endif; ?>
                </div>
                <div class="back-to-menu">
                    <a id="<?php echo $mode_class; ?>" href="?action=select_chapter&book=<?php echo urlencode($book); ?><?php echo $secret_param; ?>">返回章节目录</a> | 
                    <a id="<?php echo $mode_class; ?>" href="?action=select_book">返回书本选择</a>
                </div>
            </div>
            <script src="script.js?v=26"></script>
        </body>
        </html>
        <?php
        exit;
    
    // 正常文本章节
    } else {
        $chapter_path .= '.txt'; // 默认文本文件扩展名为 .txt
        if (!file_exists($chapter_path)) {
            die("文章不存在！");
        }
        
        // 读取章节内容
        $content = file_get_contents($chapter_path);
        
        // 检测文件内容的编码格式（支持常见编码类型）
        $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'ISO-8859-1', 'ASCII', 'BIG5', 'EUC-JP', 'SJIS', 'EUC-KR'], true);
        
        // 如果不是 UTF-8 编码，则将转换为 UTF-8 编码
        if ($encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        
        // 获取章节内容的字符总数
        $total_chars = mb_strlen($content, 'UTF-8');
        
        // 定义标点符号优先级列表
        $punctuations = [
            "\r\n", "\r", "\n", // 回车最为优先
            '。”', '。’', '！”', '！’', '？”', '？’', '.\"', '.\'', '!\"', '!\'', '?\"', '?\'', // 引号组合标点
            '”', '’', // 引号结尾
            '？！', '?!', '！？', '!?', // 组合标点
            '。', '.', '！', '!', '？', '?', // 中英文结束标点
            '；', ';', '：', ':', '，', ',', // 中英文中间标点
            '}', '》', '>', '】', ']', '）', ')', // 中英文括号和其他标点
            '…', '---', '--' // 省略号和破折号
        ];
        
        // 分页缓存文件路径
        $pagination_cache = "$book_path/{$chapter}_pagination.json";
        
        // 读取缓存文件
        $regenerate_cache = false;
        if (file_exists($pagination_cache)) {
            $cached_data = json_decode(file_get_contents($pagination_cache), true);
            
            // 检查缓存格式
            if (!is_array($cached_data) || 
                !isset($cached_data['page_size']) || 
                !isset($cached_data['pagination']) ||
                !isset($cached_data['file_mtime'])) {
                $regenerate_cache = true;
            } else {
                // 检查是否需要更新
                if ($cached_data['page_size'] !== $page_size || 
                    $cached_data['file_mtime'] !== filemtime($chapter_path)) {
                    $regenerate_cache = true;
                } else {
                    $pagination = $cached_data['pagination'];
                }
            }
        } else {
            $regenerate_cache = true;
        }
        
        // 如果需要重新生成缓存
        if ($regenerate_cache) {
            $pagination = [];
            $current_pos = 0;
            
            while ($current_pos < $total_chars) {
                $raw_content = mb_substr($content, $current_pos, $page_size, 'UTF-8');
                
                // 查找最后一个标点的位置
                $last_punctuation = false;
                foreach ($punctuations as $punctuation) {
                    $pos = mb_strrpos($raw_content, $punctuation);
                    if ($pos !== false) {
                        $last_punctuation = $pos;
                        break;
                    }
                }
                
                // 确保分页点合理且有内容
                if ($last_punctuation !== false) {
                    $current_end = $current_pos + $last_punctuation + 1;
                } else {
                    $current_end = $current_pos + $page_size;
                }
                
                // 确保当前分页点有效
                if ($current_end > $total_chars) {
                    $current_end = $total_chars;
                }
                if ($current_end <= $current_pos) {
                    break;
                }
                
                // 检查分割内容是否为空白，空白则跳过此分页点
                $segment = mb_substr($content, $current_pos, $current_end - $current_pos, 'UTF-8');
                if (trim($segment) === '') {
                    $current_pos = $current_end;
                    continue;
                }
                
                // 添加到分页数组
                $pagination[] = $current_pos;
                $current_pos = $current_end;
            }
            
            // 确保最后一个位置是文件末尾
            if (empty($pagination) || end($pagination) !== $total_chars) {
                $pagination[] = $total_chars;
            }
            
            // 递归合并短页（少于 page_size 的 25%）
            $min_page_size = $page_size * 0.25;
            while (count($pagination) > 2) {
                $second_last = $pagination[count($pagination) - 2];
                $last = $pagination[count($pagination) - 1];
                if ($last - $second_last < $min_page_size) {
                    array_pop($pagination);
                    $pagination[count($pagination) - 1] = $total_chars;
                } else {
                    break;
                }
            }
            
            // 存储新的分页数据到 JSON 文件
            $cache_data = [
                'page_size' => $page_size,
                'file_mtime' => filemtime($chapter_path),
                'pagination' => $pagination
            ];
            
            if ($fp = fopen($pagination_cache, 'c')) { // 'c'模式
                if (flock($fp, LOCK_EX)) { // 独占锁
                    ftruncate($fp, 0); // 清空旧内容
                    fwrite($fp, json_encode($cache_data));
                    fflush($fp); // 刷新输出缓冲到文件
                    flock($fp, LOCK_UN); // 释放锁
                }
                fclose($fp);
            }
        }
        
        // 计算最大页数
        $max_pages = count($pagination) - 1;
        
        // 确保 $page 在合法范围内
        if ($page > $max_pages) {
            $page = $max_pages;
        } elseif ($page < 1) {
            $page = 1;
        }
        
        // 获取当前页的开始和结束位置
        $start_pos = $pagination[$page - 1];
        $end_pos = $pagination[$page] ?? $total_chars;
        
        // 获取当前页内容
        $page_content = mb_substr($content, $start_pos, $end_pos - $start_pos, 'UTF-8');
        
        // 如果第一页或最后一页，获取章节列表
        if ($page === 1 || $page === $max_pages) {
            $chapters = getSortedChapters($book_path);
            $chapters = array_map(function($chapter_path) {
                $extension_is_txt = pathinfo($chapter_path, PATHINFO_EXTENSION);
                if ($extension_is_txt === 'txt') {
                    return pathinfo($chapter_path, PATHINFO_FILENAME); 
                }
                return basename($chapter_path); 
            }, $chapters);
            
            // 获取当前章节索引
            $current_chapter_index = array_search($chapter, $chapters);
            
            // 确定前一章节
            if ($page === 1) {
                $previous_chapter = $current_chapter_index > 0 ? basename($chapters[$current_chapter_index - 1]) : null;
                if ($previous_chapter !== null && substr($previous_chapter, -4) === '.txt') {
                    $previous_chapter = substr($previous_chapter, 0, -4);
                }
            }
            
            // 确定后一章节
            if ($page === $max_pages) {
                $next_chapter = $current_chapter_index < count($chapters) - 1 ? basename($chapters[$current_chapter_index + 1]) : null;
                if ($next_chapter !== null && substr($next_chapter, -4) === '.txt') {
                    $next_chapter = substr($next_chapter, 0, -4);
                }
            }
        }
        
        ?>
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="UTF-8">
            <meta name="robots" content="noindex, nofollow">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <meta name="author" content="EAHI">
            <meta name="csrf-token" content="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
            <title><?php echo htmlspecialchars($chapter); ?></title>
            <link rel="stylesheet" type="text/css" href="style.css?v=40">
            <style>
                .content {
                    font-size: <?php echo $font_size; ?>;
                }
            </style>
        </head>
        <body class="<?php echo $mode_class; ?>">
            <div class="container">
                <div class="header-buttons">
                    <?php if ($is_secret && check_secret_authentication()): ?>
                        <button id="logout-btn" class="toggle-btn">登出</button>
                    <?php endif; ?>
                    <button id="light-mode-toggle" class="toggle-btn">
                     <?php echo $mode_class === "light-mode" ? "关灯" : "开灯"; ?>
                    </button>
                </div>
                <h3><?php echo htmlspecialchars($chapter); ?></h3>
                <div class="back-to-menu-top">
                    <a id="<?php echo $mode_class; ?>" href="?action=select_chapter&book=<?php echo urlencode($book); ?><?php echo $secret_param; ?>">返回章节目录</a>
                </div>
                <div class="content">
                    <?php 
                    // 按换行符分割内容
                    $paragraphs = preg_split('/\r\n|\r|\n/', $page_content);
                    foreach ($paragraphs as $paragraph) {
                        // 替换开头的空格为 &nbsp;
                        $paragraph_with_spaces = preg_replace_callback('/^(\s+)/u', function ($matches) {
                            // 使用 mb_strlen 确保多字节字符长度正确
                            $space_count = mb_strlen($matches[1], 'UTF-8');
                            return str_repeat('&nbsp;', $space_count);
                        }, htmlspecialchars($paragraph));
                        echo '<p>' . $paragraph_with_spaces . '</p>';
                    }
                    ?>
                </div>
                <div class="navigation">
                    <?php if ($page === 1 && $previous_chapter != null): ?>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode($previous_chapter); ?>&page=1<?php echo $secret_param; ?>">上一章节</a>
                        <?php echo " | "; ?>
                    <?php endif; ?>
                    <?php if ($page > 1): ?>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode($chapter); ?>&page=<?php echo urlencode($page) - 1; ?><?php echo $secret_param; ?>">上一页</a>
                    <?php endif; ?>
                    <?php if ($page > 1 && $page <= $max_pages): ?>
                        <?php echo " | "; ?>
                    <?php endif; ?>
                    <?php if ($page < $max_pages): ?>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode($chapter); ?>&page=<?php echo urlencode($page) + 1; ?><?php echo $secret_param; ?>">下一页</a>
                    <?php endif; ?>
                    <?php if ($page === $max_pages && $next_chapter != null): ?>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode($next_chapter); ?>&page=1<?php echo $secret_param; ?>">下一章节</a>
                    <?php endif; ?>
                    <?php if ($page === $max_pages && $next_chapter === null): ?>
                        <span>无后续章节</span>
                    <?php endif; ?>
                </div>
                <div class="navigation">第 <?php echo htmlspecialchars($page); ?> 页，共 <?php echo $max_pages; ?> 页</div>
                <div class="jump-to-page">
                    <form method="get">
                        <input type="hidden" name="action" value="read">
                        <input type="hidden" name="book" value="<?php echo htmlspecialchars($book); ?>">
                        <input type="hidden" name="chapter" value="<?php echo htmlspecialchars($chapter); ?>">
                        <?php if ($is_secret): ?>
                        <input type="hidden" name="secret" value="1">
                        <?php endif; ?>
                        <input type="number" name="page" min="1" max="<?php echo $max_pages; ?>" placeholder="页码">
                        <button class="jump-to-page-btn" type="submit">跳转</button>
                    </form>
                </div>
                <div class="font-size-controls">
                    <span>字体大小：</span>
                    <a id="<?php echo $mode_class; ?>" href="#" class="font-size-link <?php echo ($font_size === $font_size_small) ? 'font-size-disabled' : ''; ?>" data-size="small" style="font-size: <?php echo $font_size_small; ?>;">小</a><span>&nbsp;</span>
                    <a id="<?php echo $mode_class; ?>" href="#" class="font-size-link <?php echo ($font_size === $font_size_medium) ? 'font-size-disabled' : ''; ?>" data-size="medium" style="font-size: <?php echo $font_size_medium; ?>;">中</a><span>&nbsp;</span>
                    <a id="<?php echo $mode_class; ?>" href="#" class="font-size-link <?php echo ($font_size === $font_size_large) ? 'font-size-disabled' : ''; ?>" data-size="large" style="font-size: <?php echo $font_size_large; ?>;">大</a> 
                </div>
                <div class="back-to-menu">
                    <a id="<?php echo $mode_class; ?>" href="?action=select_chapter&book=<?php echo urlencode($book); ?><?php echo $secret_param; ?>">返回章节目录</a> | 
                    <a id="<?php echo $mode_class; ?>" href="?action=select_book">返回书本选择</a>
                </div>
                <br/>
            </div>
            <script src="script.js?v=26"></script>
        </body>
        </html>
        <?php
        exit;
    }
}
?>
