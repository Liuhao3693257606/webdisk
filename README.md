# 简易网盘

一个部署简单、开箱即用的轻量级网盘。PHP 单文件实现，SQLite 存储账号，支持多用户隔离、分片上传、在线预览与文件夹管理。界面浅灰底配柔和蓝色主题，移动端自适应。

---

## 功能特性

- 多用户注册与登录，密码使用 password_hash() 加密存储
- 文件夹管理：新建、进入、返回上级、递归删除
- 分片上传：前端切片上传，支持大文件与重试
- 在线预览：图片、文本、PDF 等可直接预览
- 文件下载：标准 attachment 下载
- 文件列表：文件夹优先排序，显示文件大小
- 响应式界面：手机、平板、桌面均可使用
- 安全防护：目录穿越过滤、XSS 防护、上传白名单、nosniff

---

## 界面说明

- 登录页：卡片式布局，柔和阴影
- 主界面：顶栏显示当前目录与用户操作，下方依次为上传区、新建文件夹区、文件列表
- 移动端：操作按钮自动换行，触摸友好

---

## 部署

### 环境要求

- PHP 7.0 及以上（推荐 7.4+ 或 8.x）
- 启用扩展：pdo_sqlite、fileinfo、mbstring（推荐）
- Web 服务器：Apache、Nginx、PHP 内置服务器、KSWEB、Termux 均可

### 步骤

1. 上传文件

   将 index.php 放入站点根目录，例如：

   /var/www/html/index.php

2. 确保目录可写

   chmod 755 /var/www/html

   PHP 进程需要对站点目录有写权限，用于创建 user_account.db 与 user_files/。

3. 访问

   浏览器打开：

   http://你的域名或IP/index.php

4. 注册并登录

   首次访问进入登录页，点击"注册"创建账号（2-20 位字母、数字、下划线或中文），注册成功后自动切回登录。

---

## 目录结构

    站点根目录/
    ├── index.php              主程序（单文件）
    ├── user_account.db        SQLite 数据库（自动生成）
    ├── user_files/            用户文件目录（自动生成）
    │   ├── userA/             每个用户独立目录
    │   └── userB/
    └── ...

user_account.db 与 user_files/ 由程序运行时自动生成，请勿删除。所有文件输出均通过 PHP 处理，不会直接暴露。

---

## 配置

### 分片大小

默认每片 512KB，前后端必须保持一致。

PHP 端（index.php 顶部）：

    $CHUNK_SIZE = 512 * 1024;

JS 端（页面底部 script）：

    const chunkSize = 512 * 1024;

若上传失败并提示"服务器未收到分片"，通常是 PHP 的 post_max_size 或 upload_max_filesize 太小。可调大 php.ini 中这两个值（推荐不小于 8M），或将分片改小（如 256KB）并同步修改前后端。

### 允许上传的类型

编辑 index.php 中的 $allowExt：

    $allowExt = ['jpg','png','gif','jpeg','webp','txt','md','pdf','zip','rar','7z','docx','xlsx','pptx','mp4','mkv','mov'];

### 单文件大小提示

仅用于前端展示，实际限制由 post_max_size 决定：

    <div id="tip">支持图片、文档、压缩包、视频等，单文件建议 ≤ 500MB</div>

---

## 安全说明

| 项目 | 实现 |
|------|------|
| 密码存储 | password_hash() 与 password_verify() |
| SQL 注入 | 全部使用 PDO 预处理 |
| 目录穿越 | 过滤 .. / \ 等字符，realpath 二次校验 |
| XSS 防护 | 输出统一 htmlspecialchars()；预览非安全类型强制下载 |
| Session | httponly 与 samesite=Lax |
| 上传白名单 | 扩展名白名单与文件名过滤 |
| 响应头 | 预览与下载带 X-Content-Type-Options: nosniff |

若暴露在公网，建议启用 HTTPS，并在 Web 服务器层增加 Basic Auth 作为额外防护，同时定期备份 user_account.db 与 user_files/。

---

## 常见问题

### 登录页出现 session_start(): 在首部已发送

原因：index.php 文件开头有 UTF-8 BOM 头，或 <?php 前有空格、空行

解决方式：

- VSCode：右下角编码改为 UTF-8（不带 BOM），保存
- Notepad++：编码菜单选择"转为 UTF-8 无 BOM 编码"，保存
- 手机编辑器（MT 管理器等）：在编码选项中选择 UTF-8 无 BOM

### 上传失败，提示"服务器未收到分片"

原因：PHP 的 post_max_size 或 upload_max_filesize 太小。

解决方式：

编辑 php.ini：

    upload_max_filesize = 64M
    post_max_size = 64M
    max_file_uploads = 20
    max_execution_time = 300

或将分片大小调小（如 256KB），PHP 与 JS 同步修改。

### 上传失败，提示"禁止上传该类型文件"

原因：文件扩展名不在白名单。

解决方式：编辑 index.php 中的 $allowExt，加入所需类型。

### 上传后文件列表未刷新

前端上传完成后会自动刷新页面。若无反应，手动刷新即可。

### 忘记密码

程序未提供找回密码功能。可在数据库中删除该用户后重新注册：

    sqlite3 user_account.db "DELETE FROM users WHERE username='你的账号';"

---

## 技术栈

- 后端：PHP（原生，无框架）
- 数据库：SQLite 3（PDO）
- 前端：原生 HTML、CSS、JavaScript
- 上传：Fetch API 与 FormData 分片上传

---

## 更新日志

### v1.1

- 修复上传接口未优先处理导致返回 HTML 的问题
- 前后端分片大小统一为 512KB
- 前端上传接口补传 path 参数
- 完善错误捕获与提示
- 账号注册加格式校验
- 预览区分 inline 与 download
- 文件夹优先排序，显示文件大小

### v1.0

- 初始版本
- 多用户注册登录
- 文件夹管理、上传、下载、预览、删除

---

## 许可

本程序仅供学习与个人使用，请勿用于非法用途。使用者需自行承担因部署于公网而产生的一切风险