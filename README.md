# Minimal-TXT-Reader-Website

[Demo 链接](https://glao.xyz/demo/Minimal-TXT-Reader-Website/)

极简的TXT文档/电子书在线阅读网站。

从旧硬盘里翻出了一些还没看的旧电子书，所以写了这个网站让我可以随时随地看这些书。

## 功能特性

### 书本存放结构

```
books/
  |-- example book/
      |-- chapter 1.txt
      |-- chapter 2.jpg
      |-- chapter 2.txt
  |-- 范例书/
      |-- 第一章.txt
      |-- 第二章 插图.jpg
      |-- 第二章.txt
```

### 书本选择页面

- 根据 GBK 编码排序书本名。

### 章节选择页面

- 根据章节数字排序（可识别纯数字、英文、中文，以及罗马数字）。

### 文章内容页面

- 根据回车、标点符号等动态分页（会记录并生成缓存文件）。
- 第一页可返回上一章节，最后一页可跳转到下一章节。
- 可显示单独的图片文件（格式：JPG、JPEG、PNG、GIF、BMP、WEBP）。
- 可调整文字大小（小号、中号、大号）。
- 支持白天/夜间模式切换。

### 支持 PHP 的服务器

- 适用于支持 PHP 的服务器（如 Apache 或 Nginx）。

### 参数配置

- `index.php` 文件开头可按喜好修改：
  ```php
  // 设置参数
  $books_dir = "books"; // 存放书本的主文件夹名称
  $page_size = 2000; // 每页显示的最大字符数
  $font_size_small = "15px"; // 小号字体大小
  $font_size_medium = "18px"; // 中号字体大小
  $font_size_large = "21px"; // 大号字体大小
  ```
