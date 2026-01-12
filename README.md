# Minimal-TXT-Reader-Website
极简的TXT文档/电子书在线阅读网站。无数据库，纯文件系统管理，支持密码保护的私密书柜。

**[Demo 链接](https://glao.xyz/demo/Minimal-TXT-Reader-Website/)**（Demo 用户密码：pass1234）

**秘密书柜访问方式**：右下角连续点击3次或按快捷键 `Alt+Ctrl+.`（Mac 为 `Alt+Cmd+.`）打开密码输入框

## 功能特性

### 项目结构
```
Minimal-TXT-Reader-Website/
  |-- index.php                  # 主程序文件
  |-- verify.php                 # 密码验证处理
  |-- config.php                 # 配置文件
  |-- style.css                  # 样式文件
  |-- script.js                  # 脚本文件
  |-- .htaccess                  # 安全配置
  |-- secret_initial.json        # 初始密码配置文件
  |-- books/                     # 普通书本目录
      |-- example book/
          |-- chapter 1.txt
          |-- chapter 2.jpg
          |-- chapter 2.txt
      |-- 范例书/
          |-- 第一章.txt
          |-- 第二章 插图.jpg
          |-- 第二章.txt
  |-- secret/                    # 秘密书柜目录（需要密码访问）
      |-- .htaccess              # 秘密书柜安全配置（首次访问时自动生成）
      |-- 私密书本1/
          |-- 章节.txt
      |-- 私密书本2/
          |-- 章节.txt
```
### 自动生成的文件
以下文件会在系统运行过程中自动生成，无需手动创建：
- `secret_config.json` - 密码配置文件（首次设置密码后生成）
- `*_pagination.json` - 章节分页缓存文件（如 `Chapter_01_pagination.json`）
- `secret/.htaccess` - 秘密书柜安全配置（首次访问秘密书柜时生成）
- `rate_limit.json` - 速率限制记录文件（密码验证时生成）
- `rate_limit.lock` - 速率限制锁文件（密码验证时生成）

## 全站功能
- 支持白天/夜间模式切换
- 书名、章节和页码保存在网页链接（URL）中，可通过浏览器的默认书签功能记录阅读进度

### 书本选择页面
- 根据 GBK 编码排序书本名

### 章节选择页面
- 智能章节排序，支持识别纯数字、英文数字、中文数字、罗马数字

### 阅读页面
- 根据回车、标点符号等动态分页（自动生成缓存文件）
- 第一页可返回上一章节，最后一页可跳转到下一章节
- 支持显示图片章节（JPG、JPEG、PNG、GIF、BMP、WEBP）
- 可调整文字大小（小号、中号、大号）

## 秘密书柜
- **双密码系统**：主密码由站长持有用于管理和修改所有密码，用户密码可分享给他人仅用于登录访问
- **首次设置**：通过初始密码配置文件设置初始密码，首次访问时设置主密码和用户密码
- **访问方式**：右下角连续点击3次或按快捷键 `Alt+Ctrl+.`（Mac 为 `Alt+Cmd+.`）打开密码输入框
- **多图书馆支持**：通过 `library_id` 配置，支持在同一域名下部署多个独立的图书馆，每个图书馆拥有独立的密码和会话管理

## 安装与配置

### 服务器要求
- 支持 PHP 的服务器（Apache 或 Nginx）
- PHP 5.4 或更高版本
- Apache 需启用 mod_rewrite（用于 .htaccess）

### 重要：设置 .htaccess 文件
**在部署前，必须先创建 `.htaccess` 文件以保护敏感文件！**

1. 在项目根目录创建 `.htaccess` 文件
2. 将 `htaccess_template.txt` 中的内容复制到 `.htaccess` 文件中
3. secret/ 目录下的 `.htaccess` 会在首次访问秘密书柜时自动生成

### 参数配置
在 `config.php` 文件中按需修改参数：
```php
$library_id = "library_id";              // 图书馆ID，不同图书馆请设置不同的ID（例如: "my_collection", "Library_A", "Library_B" 等）
$books_dir = "books";                    // 存放书本的主文件夹名称
$secret_dir = "secret";                  // 秘密书柜的文件夹名称
$initial_password_file = "secret_initial.json";  // 初始密码文件名
$page_size = 2000;                       // 每页显示的字符数
$font_size_small = "15px";               // 小号字体大小
$font_size_medium = "18px";              // 中号字体大小
$font_size_large = "21px";               // 大号字体大小
$secret_session_lifetime = 12 * 3600;    // 秘密书柜会话有效期（秒）
```

**多图书馆部署说明**：
- 如果在同一域名下部署多个图书馆（如 `example.com/libraryA/` 和 `example.com/libraryB/`），请为每个图书馆设置不同的 `$library_id`
- 例如：libraryA 设置为 `$library_id = "libraryA"`，libraryB 设置为 `$library_id = "libraryB"`
- 这样可以确保不同图书馆的会话完全隔离

### 秘密书柜初始化
创建初始密码配置文件（默认为 `secret_initial.json`，与 `index.php` 同级）：
```json
{
    "initial_password": "your-initial-password",
    "is_initial": true
}
```

**重要提示**：
- 首次访问秘密书柜时，系统会要求设置主密码和用户密码
- 设置完成后，初始密码配置文件中的 `is_initial` 会自动变为 `false`
- 如需重新初始化密码，可在初始密码配置文件中将 `is_initial` 改回 `true` 并修改 `initial_password` 为新的初始密码

## 使用说明
1. 将普通电子书（TXT 文件）和图片放入 `books/` 目录的子文件夹中
2. 将秘密电子书（TXT 文件）和图片放入 `secret/` 目录的子文件夹中
3. 访问网站首页，选择要阅读的书本
4. 选择章节开始阅读
5. 使用页面底部的导航按钮翻页或切换章节
6. 使用对应方式打开秘密书柜登录界面

## 安全特性
- CSRF 保护：所有表单提交均包含 token 验证
- 会话安全：HttpOnly、SameSite、Strict Mode 等安全属性配置
- 会话隔离：通过 library_id 实现多图书馆会话完全隔离，不同图书馆使用独立的 session
- 文件路径验证：严格的路径遍历保护
- 密码加密存储：使用安全的哈希算法存储密码
- .htaccess 保护：通过 Apache 配置限制对敏感目录和文件的直接访问

## 注意事项
- 确保所有 TXT 文件使用 UTF-8 编码以避免中文乱码
- 图片文件需与对应章节放在同一目录

## Version
2.0.4

## Changelog
- **2.0.5**: 增强密码验证安全性
- **2.0.4**: 修复 image.php 会话隔离问题
- **2.0.3**: 使用独立 session 名称实现多图书馆会话完全隔离，优化书本排序逻辑
- **2.0.2**: 完善会话隔离机制，修复 index.php 中的 library_id 验证逻辑
- **2.0.1**: 修复会话安全漏洞，新增 library_id 配置实现多图书馆会话隔离
- **2.0.0**: 新增秘密书柜功能（双密码系统），增强安全性（CSRF保护、会话安全强化、文件路径验证），优化章节排序算法和分页缓存机制
- **1.0.4**: 新增 sanitize_filename() 检测非法字符并跳转回首页，getSortedChapters() 检查章节为空时跳转回首页，分页缓存写入增加 flock() 文件锁
- **1.0.3**: 更新章节排序函数
- **1.0.2**: 更新章节排序函数
- **1.0.1**: 更新分页函数，page_size 改变或缓存格式错误时自动重新生成缓存
- **1.0.0**: 基础功能实现
