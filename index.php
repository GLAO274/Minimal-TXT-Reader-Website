<?php
session_start();

// 设置参数
$books_dir = "books"; // 存放书本的主文件夹名称
$page_size = 2000; // 每页显示的字符数
$font_size_small = "15px"; // 小号字体大小
$font_size_medium = "18px"; // 中号字体大小
$font_size_large = "21px"; // 大号字体大小

// 获取参数
$action = isset($_GET['action']) ? $_GET['action'] : 'select_book';
$book = isset($_GET['book']) ? $_GET['book'] : '';
$chapter = isset($_GET['chapter']) ? $_GET['chapter'] : '';
$page = isset($_GET['page']) && intval($_GET['page']) > 0 ? intval($_GET['page']) : 1;

// 处理日夜模式切换请求
if (isset($_GET['mode']) && $_GET['mode'] == '1') {
    $_SESSION['light_mode'] = !isset($_SESSION['light_mode']) || !$_SESSION['light_mode'];
    exit;
}

// 获取当前的日夜模式
$mode_class = isset($_SESSION['light_mode']) && $_SESSION['light_mode'] ? 'light-mode' : 'dark-mode';

// 处理字体大小设置请求
if (isset($_GET['size'])) {
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
    exit;
}

// 获取当前字体大小
$font_size = isset($_SESSION['font_size']) ? $_SESSION['font_size'] : $font_size_large; // 默认为大号字体

// 章节排序函数
function getSortedChapters($book_dir) {
    $chapters = array_filter(glob("$book_dir/*"), function($chapter) {
        return is_file($chapter) && pathinfo($chapter, PATHINFO_EXTENSION) !== 'json';
    });
    usort($chapters, function ($a, $b) {
        $extractOrder = function ($string) {
            static $cache = [];
            if (isset($cache[$string])) {
                return $cache[$string];
            }
            // 纯数字解析
            if (preg_match('/\d+/', $string, $matches)) {
                return $cache[$string] = (int)$matches[0];
            }
            // 英文数字解析
            $englishToNumber = function ($string) {
                static $mapping = [
                    'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
                    'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
                    'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
                    'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
                    'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40,
                    'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
                    'hundred' => 100, 'thousand' => 1000, 'million' => 1000000, 'billion' => 1000000000
                ];
                $words = preg_split('/\s+|-/', strtolower($string));
                $current = $total = 0;
                foreach ($words as $word) {
                    if (isset($mapping[$word])) {
                        $value = $mapping[$word];
                        $current = ($value >= 100) ? $current * $value : $current + $value;
                    } else {
                        $total += $current;
                        $current = 0;
                    }
                }
                return $total + $current;
            };
            $englishNumber = $englishToNumber($string);
            if ($englishNumber > 0) {
                return $cache[$string] = $englishNumber;
            }
            // 中文数字解析
            $parseChineseNumber = function ($string) {
                $chineseToNumber = [
                    '零' => 0, '一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5,
                    '六' => 6, '七' => 7, '八' => 8, '九' => 9, '十' => 10,
                    '百' => 100, '千' => 1000, '万' => 10000, '亿' => 100000000
                ];
                $number = $segment = 0;
                $unit = 1;
                $chars = mb_str_split($string);
                foreach (array_reverse($chars) as $char) {
                    if (isset($chineseToNumber[$char])) {
                        $value = $chineseToNumber[$char];
                        if ($value >= 10) {
                            $unit = max($unit, $value);
                        } else {
                            $segment += $value * $unit;
                        }
                    }
                }
                return $number + $segment;
            };
            $chineseNumber = $parseChineseNumber($string);
            if ($chineseNumber > 0) {
                return $cache[$string] = $chineseNumber;
            }
            // 罗马数字解析
            $romanToNumber = [
                'I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000
            ];
            $result = 0;
            $previous = 0;
            $chars = str_split(strtoupper($string));
            foreach (array_reverse($chars) as $char) {
                $current = $romanToNumber[$char] ?? 0;
                $result += ($current < $previous) ? -$current : $current;
                $previous = $current;
            }
            return $cache[$string] = $result > 0 ? $result : PHP_INT_MAX;
        };
        $orderA = $extractOrder(basename($a));
        $orderB = $extractOrder(basename($b));
        return $orderA === $orderB ? strcmp(basename($a), basename($b)) : $orderA <=> $orderB;
    });
    return $chapters;
}

// 选择书本页面
if ($action === 'select_book') {
    $books = array_filter(glob("$books_dir/*"), 'is_dir');
    // 按 GBK 编码排序书本名称
    usort($books, function ($a, $b) {
        return strcoll(iconv("UTF-8", "GBK", basename($a)), iconv("UTF-8", "GBK", basename($b)));
    });
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="robots" content="noindex, nofollow">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="author" content="EAHI">
        <title>书架</title>
        <link rel="stylesheet" type="text/css" href="style.css?v=28">
    </head>
    <body class="<?php echo $mode_class; ?>">
        <div class="container">
            <button id="light-mode-toggle" class="toggle-btn">
                <?php echo $mode_class === "light-mode" ? "关灯" : "开灯"; ?>
            </button>
            <h3>书本列表</h3>
            <ul>
                <?php foreach ($books as $book_dir): ?>
                <li><a id="<?php echo $mode_class; ?>" href="?action=select_chapter&book=<?php echo urlencode(basename($book_dir)); ?>"><?php echo htmlspecialchars(basename($book_dir)); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <script src="script.js?v=13"></script>
    </body>
    </html>
    <?php
    exit;

// 选择章节页面
} elseif ($action === 'select_chapter' && $book) {
    $chapters = getSortedChapters("$books_dir/$book");
    
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
        <title><?php echo htmlspecialchars($book); ?> - 章节列表</title>
        <link rel="stylesheet" type="text/css" href="style.css?v=28">
    </head>
    <body class="<?php echo $mode_class; ?>">
        <div class="container">
            <button id="light-mode-toggle" class="toggle-btn">
             <?php echo $mode_class === "light-mode" ? "关灯" : "开灯"; ?>
            </button>
            <h3><?php echo htmlspecialchars($book); ?></h3>
            <h4>章节列表</h4>
            <ul>
            <?php foreach ($chapters as $chapter): ?>
                <?php
                $extension = strtolower(pathinfo($chapter, PATHINFO_EXTENSION));
                // 如果章节是图片
                if (in_array($extension, $image_formats)): ?>
                    <li>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode(basename($chapter)); ?>">
                            <?php echo htmlspecialchars(basename($chapter, '.' . $extension)); ?>
                        </a>
                    </li>
                <?php else: // 正常文字章节 ?>
                    <li>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode(basename($chapter, '.' . $extension)); ?>&page=1">
                            <?php echo htmlspecialchars(basename($chapter, '.' . $extension)); ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
            </ul>
            <a id="<?php echo $mode_class; ?>" href="?action=select_book">返回书本选择</a>
        </div>
        <script src="script.js?v=13"></script>
    </body>
    </html>
    <?php
    exit;

// 文章内容页面
} elseif ($action === 'read' && $book && $chapter) {
    $chapter_path = "$books_dir/$book/$chapter";
    $extension = strtolower(pathinfo($chapter_path, PATHINFO_EXTENSION));
    
    // 支持的图片格式
    $image_formats = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    
    // 如果章节是图片
    if (in_array($extension, $image_formats)) {
        
        // 获取章节列表
        $chapters = getSortedChapters("$books_dir/$book");
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
        
    ?>
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="UTF-8">
            <meta name="robots" content="noindex, nofollow">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <meta name="author" content="EAHI">
            <title><?php echo htmlspecialchars(basename($chapter, '.' . $extension)); ?></title>
            <link rel="stylesheet" type="text/css" href="style.css?v=28">
        </head>
        <body class="<?php echo $mode_class; ?>">
            <div class="container">
                <button id="light-mode-toggle" class="toggle-btn">
                 <?php echo $mode_class === "light-mode" ? "关灯" : "开灯"; ?>
                </button>
                <h3><?php echo htmlspecialchars(basename($chapter, '.' . $extension)); ?></h3>
                <div class="content" style="text-align: center;">
                    <img src="<?php echo htmlspecialchars($chapter_path); ?>" alt="<?php echo htmlspecialchars($chapter); ?>" style="max-width: 100%; height: auto;">
                </div>
                <div class="navigation">
                    <?php if ($previous_chapter != null): ?>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode($previous_chapter); ?>&page=1">上一章节</a>
                        <?php echo " | "; ?>
                    <?php endif; ?>
                    <?php if ($next_chapter != null): ?>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode($next_chapter); ?>&page=1">下一章节</a>
                    <?php endif; ?>
                    <?php if ($next_chapter === null): ?>
                        <span>无后续章节</span>
                    <?php endif; ?>
                </div>
                <div class="back-to-menu">
                    <a id="<?php echo $mode_class; ?>" href="?action=select_chapter&book=<?php echo urlencode($book); ?>">返回章节目录</a> | 
                    <a id="<?php echo $mode_class; ?>" href="?action=select_book">返回书本选择</a>
                </div>
            </div>
            <script src="script.js?v=13"></script>
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
            "\r", "\n", "\r\n", // 回车最为优先
            '。”', '。’', '！”', '！’', '？”', '？’', '.\"', '.\'', '!\"', '!\'', '?\"', '?\'', // 引号组合标点
            '”', '’', // 引号结尾
            '？！', '?!', '！？', '!?', // 组合标点
            '。', '.', '！', '!', '？', '?', // 中英文结束标点
            '；', ';', '：', ':', '，', ',', // 中英文中间标点
            '}', '》', '>', '】', ']', '）', ')', // 中英文括号和其他标点
            '…', '---', '--' // 省略号和破折号
        ];
        
        // 分页缓存文件路径
        $pagination_cache = "$books_dir/$book/{$chapter}_pagination.json";
        
        // 读取缓存文件
        $regenerate_cache = false;
        if (file_exists($pagination_cache)) {
            $cached_data = json_decode(file_get_contents($pagination_cache), true);
            
            // 检查缓存格式是否正确
            if (!is_array($cached_data) || !isset($cached_data['page_size']) || !isset($cached_data['pagination'])) {
                $regenerate_cache = true;
            } else {
                $cached_page_size = $cached_data['page_size'];
                $pagination = $cached_data['pagination'];
                
                // 如果缓存的 page_size 和当前的不一致，更新缓存
                if ($cached_page_size !== $page_size) {
                    $regenerate_cache = true;
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
                
            // **合并最后一段内容到上一页**
            if (count($pagination) > 1 && $pagination[count($pagination) - 1] < $total_chars) {
                $pagination[count($pagination) - 1] = $total_chars;
            } elseif (empty($pagination) || end($pagination) < $total_chars) {
                $pagination[] = $total_chars; // 如果没有分页点，确保总长度作为唯一的分页点
            }
        
            // 存储新的分页数据到 JSON 文件
            file_put_contents($pagination_cache, json_encode(['page_size' => $page_size, 'pagination' => $pagination]));
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
            $chapters = getSortedChapters("$books_dir/$book");
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
            <title><?php echo htmlspecialchars($chapter); ?></title>
            <link rel="stylesheet" type="text/css" href="style.css?v=28">
            <style>
                .content {
                    font-size: <?php echo $font_size; ?>;
                }
            </style>
        </head>
        <body class="<?php echo $mode_class; ?>">
            <div class="container">
                <button id="light-mode-toggle" class="toggle-btn">
                 <?php echo $mode_class === "light-mode" ? "关灯" : "开灯"; ?>
                </button>
                <h3><?php echo htmlspecialchars($chapter); ?></h3>
                <div class="back-to-menu-top">
                    <a id="<?php echo $mode_class; ?>" href="?action=select_chapter&book=<?php echo urlencode($book); ?>">返回章节目录</a>
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
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode($previous_chapter); ?>&page=1">上一章节</a>
                        <?php echo " | "; ?>
                    <?php endif; ?>
                    <?php if ($page > 1): ?>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode($chapter); ?>&page=<?php echo urlencode($page) - 1; ?>">上一页</a>
                    <?php endif; ?>
                    <?php if ($page > 1 && $page <= $max_pages): ?>
                        <?php echo " | "; ?>
                    <?php endif; ?>
                    <?php if ($page < $max_pages): ?>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode($chapter); ?>&page=<?php echo urlencode($page) + 1; ?>">下一页</a>
                    <?php endif; ?>
                    <?php if ($page === $max_pages && $next_chapter != null): ?>
                        <a id="<?php echo $mode_class; ?>" href="?action=read&book=<?php echo urlencode($book); ?>&chapter=<?php echo urlencode($next_chapter); ?>&page=1">下一章节</a>
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
                        <input type="number" name="page" min="1" max="<?php echo $max_pages; ?>" placeholder="页码">
                        <button class="jump-to-page-btn" type="submit">跳转</button>
                    </form>
                </div>
                <div class="font-size-controls">
                    <span>字体大小：</span>
                    <a id="<?php echo $mode_class; ?>" href="#" class="font-size-link" data-size="small" style="font-size: <?php echo $font_size_small; ?>;">小</a><span>&nbsp;</span>
                    <a id="<?php echo $mode_class; ?>" href="#" class="font-size-link" data-size="medium" style="font-size: <?php echo $font_size_medium; ?>;">中</a><span>&nbsp;</span>
                    <a id="<?php echo $mode_class; ?>" href="#" class="font-size-link" data-size="large" style="font-size: <?php echo $font_size_large; ?>;">大</a> 
                </div>
                <div class="back-to-menu">
                    <a id="<?php echo $mode_class; ?>" href="?action=select_chapter&book=<?php echo urlencode($book); ?>">返回章节目录</a> | 
                    <a id="<?php echo $mode_class; ?>" href="?action=select_book">返回书本选择</a>
                </div>
                <br/>
            </div>
            <script src="script.js?v=13"></script>
        </body>
        </html>
        <?php
        exit;
    }
}
?>
