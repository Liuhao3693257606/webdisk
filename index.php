<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
try {
    $db = new PDO("sqlite:user_account.db");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $err) {
    exit("数据库异常：" . $err->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'lifetime' => 3600
    ]);
    session_start();
}

error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);
ignore_user_abort(true);

// ============================================================
// ★★★ 分片上传接口：必须放在最前面，优先于任何 HTML 输出 ★★★
// ============================================================
$CHUNK_SIZE = 512 * 1024;
$allowExt = ['jpg','png','gif','jpeg','webp','txt','md','pdf','zip','rar','7z','docx','xlsx','pptx','mp4','mkv','mov'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['filename']) && isset($_POST['index'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_FILES['chunk_data'])) {
        echo json_encode(['ok' => false, 'msg' => '服务器未收到分片，可能超过 post_max_size 限制']);
        exit;
    }
    if ($_FILES['chunk_data']['error'] !== UPLOAD_ERR_OK) {
        $errMap = [
            1 => '文件超过 upload_max_filesize',
            2 => '超过表单限制',
            3 => '只上传了一部分',
            4 => '没有文件',
            6 => '缺少临时目录',
            7 => '写入磁盘失败',
            8 => 'PHP 扩展阻止上传',
        ];
        $msg = $errMap[$_FILES['chunk_data']['error']] ?? '未知上传错误';
        echo json_encode(['ok' => false, 'msg' => $msg]);
        exit;
    }
    if (!isset($_SESSION['login'])) {
        echo json_encode(['ok' => false, 'msg' => '未登录']);
        exit;
    }

    $root = __DIR__ . "/user_files/" . $_SESSION['user'];
    if (!is_dir($root)) mkdir($root, 0755, true);
    $rootReal = realpath($root);

    $curPath = trim($_POST['path'] ?? '');
    $curPath = basename(ltrim($curPath, '/'));
    if (preg_match('/[\\\\\/:*?"<>|]/', $curPath)) $curPath = '';
    $real = $curPath !== '' ? realpath($rootReal . "/" . $curPath) : $rootReal;
    if (!$real || strpos($real, $rootReal) !== 0 || !is_dir($real)) {
        $real = $rootReal;
    }

    $fn = basename($_POST['filename'] ?? '');
    $idx = (int)($_POST['index'] ?? -1);
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));

    if (!$fn || preg_match('/[\\\\\/:*?"<>|]/', $fn) || $idx < 0 || $idx > 100000) {
        echo json_encode(['ok' => false, 'msg' => '参数非法']); exit;
    }
    if (!in_array($ext, $allowExt)) {
        echo json_encode(['ok' => false, 'msg' => '禁止上传该类型文件']); exit;
    }

    $save = "$real/$fn";
    if ($idx === 0 && file_exists($save)) @unlink($save);

    $chunk = file_get_contents($_FILES['chunk_data']['tmp_name']);
    $fh = fopen($save, 'c+b');
    if (!$fh) { echo json_encode(['ok' => false, 'msg' => '写入失败']); exit; }
    fseek($fh, $idx * $CHUNK_SIZE);
    fwrite($fh, $chunk);
    fclose($fh);
    echo json_encode(['ok' => true]);
    exit;
}
// ============================================================
// ★★★ 上传接口结束 ★★★
// ============================================================

header("Content-Type:text/html;charset=utf-8");
$tip = "";
$act = $_GET['type'] ?? 'login';

$db->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT UNIQUE NOT NULL,password TEXT NOT NULL,role TEXT DEFAULT 'user')");

// ========== 注册 / 登录 ==========
if ($_POST) {
    if (isset($_POST['register'])) {
        $u = trim($_POST['user'] ?? '');
        $p = trim($_POST['pwd'] ?? '');
        if ($u && $p) {
            if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]{2,20}$/u', $u)) {
                $tip = "账号仅允许 2-20 位字母、数字、下划线或中文";
            } else {
                $stmt_check = $db->prepare("SELECT 1 FROM users WHERE username = ?");
                $stmt_check->execute([$u]);
                if (!$stmt_check->fetch()) {
                    $hash = password_hash($p, PASSWORD_DEFAULT);
                    $stmt_insert = $db->prepare("INSERT INTO users(username,password) VALUES(?,?)");
                    $stmt_insert->execute([$u, $hash]);
                    $tip = "注册成功";
                    $act = "login";
                } else {
                    $tip = "账号已存在";
                }
            }
        } else {
            $tip = "账号密码不能为空";
        }
    }
    if (isset($_POST['login'])) {
        $u = trim($_POST['user'] ?? '');
        $p = trim($_POST['pwd'] ?? '');
        $stmt = $db->prepare("SELECT password FROM users WHERE username = ?");
        $stmt->execute([$u]);
        $row = $stmt->fetch();
        if ($row && password_verify($p, $row['password'])) {
            session_regenerate_id(true);
            $_SESSION['login'] = 1;
            $_SESSION['user'] = $u;
            header("Location:index.php");
            exit;
        } else {
            $tip = "账号或密码错误";
        }
    }
}

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    setcookie("PHPSESSID", "", time() - 3600);
    header("Location:index.php");
    exit;
}

if (!isset($_SESSION['login'])) {
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
body{background:#f7f8fa;color:#222;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:18px}
.login-card{width:100%;max-width:420px;background:#fff;padding:28px 24px;border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,.06)}
.app-title{text-align:center;font-size:22px;font-weight:600;color:#1f2937;margin-bottom:4px}
.app-subtitle{text-align:center;font-size:14px;color:#6b7280;margin-bottom:24px}
.red{background:#fef2f2;color:#dc2626;padding:10px 12px;border-radius:8px;margin-bottom:16px;font-size:14px;text-align:center}
.green{background:#f0fdf4;color:#16a34a;padding:10px 12px;border-radius:8px;margin-bottom:16px;font-size:14px;text-align:center}
.form-group{margin-bottom:16px}
.form-group label{display:block;margin-bottom:6px;color:#374151;font-size:14px;font-weight:500}
.form-group input{width:100%;padding:12px 14px;border:1px solid #d1d5db;border-radius:10px;outline:none;font-size:15px;background:#f9fafb;transition:all .2s}
.form-group input:focus{border-color:#7299c7;background:#fff;box-shadow:0 0 0 3px rgba(114,153,199,.15)}
.btn{width:100%;padding:12px;border:none;border-radius:10px;background:#7299c7;color:#fff;font-size:15px;font-weight:500;cursor:pointer;transition:background .2s}
.btn:hover{background:#5e87b8}
.btn:active{background:#4f7aa8}
.link-row{text-align:center;margin-top:18px;font-size:14px;color:#6b7280}
.link-row a{color:#7299c7;text-decoration:none;font-weight:500}
.link-row a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="login-card">
    <div class="app-title">局域网网盘</div>
    <div class="app-subtitle">也可以修改为外网可访问</div>
    <?php if ($tip): ?>
        <div class="<?= $tip === '注册成功' ? 'green' : 'red' ?>"><?= htmlspecialchars($tip) ?></div>
    <?php endif; ?>
    <?php if ($act == 'login'): ?>
    <form method="post">
        <div class="form-group"><label>账号</label><input name="user" required placeholder="请输入账号" autocomplete="username"></div>
        <div class="form-group"><label>密码</label><input type="password" name="pwd" required placeholder="请输入密码" autocomplete="current-password"></div>
        <button class="btn" name="login">登录</button>
    </form>
    <div class="link-row">没有账号？<a href="?type=reg">注册</a></div>
    <?php else: ?>
    <form method="post">
        <div class="form-group"><label>账号</label><input name="user" required placeholder="设置账号（2-20位）" autocomplete="username"></div>
        <div class="form-group"><label>密码</label><input type="password" name="pwd" required placeholder="设置密码" autocomplete="new-password"></div>
        <button class="btn" name="register">注册</button>
    </form>
    <div class="link-row">已有账号？<a href="?type=login">登录</a></div>
    <?php endif; ?>
</div>
</body>
</html>
<?php
    exit;
}

// ========== 已登录 ==========
$root = __DIR__ . "/user_files/" . $_SESSION['user'];
if (!is_dir($root)) mkdir($root, 0755, true);
$rootReal = realpath($root);

$curPath = trim($_GET['path'] ?? '');
$curPath = basename(ltrim($curPath, '/'));
if (preg_match('/[\\\\\/:*?"<>|]/', $curPath)) $curPath = '';

$real = $curPath !== '' ? realpath($rootReal . "/" . $curPath) : $rootReal;
if (!$real || strpos($real, $rootReal) !== 0 || !is_dir($real)) {
    $real = $rootReal;
    $curPath = '';
}

// 新建文件夹
if (!empty($_POST['newdir'])) {
    $d = basename(trim($_POST['dirname'] ?? ''));
    if ($d && $d !== '.' && $d !== '..' && !preg_match('/[\\\\\/:*?"<>|]/', $d)) {
        @mkdir("$real/$d", 0755);
    }
    header("Location:?path=" . urlencode($curPath));
    exit;
}

// 删除
if (!empty($_GET['del'])) {
    $del = basename(trim($_GET['del']));
    if ($del && $del !== '.' && $del !== '..' && !preg_match('/[\\\\\/:*?"<>|]/', $del)) {
        $f = "$real/$del";
        if (is_dir($f)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($f, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $node) {
                $node->isDir() ? @rmdir($node->getPathname()) : @unlink($node->getPathname());
            }
            @rmdir($f);
        } elseif (is_file($f)) {
            @unlink($f);
        }
    }
    header("Location:?path=" . urlencode($curPath));
    exit;
}

// 预览
if (!empty($_GET['view'])) {
    $view = basename(trim($_GET['view']));
    if ($view && !preg_match('/[\\\\\/:*?"<>|]/', $view)) {
        $f = "$real/$view";
        if (is_file($f)) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $inlineExt = ['jpg','jpeg','png','gif','webp','bmp','svg','pdf','txt','md','log','json','xml','csv'];
            if (in_array($ext, $inlineExt)) {
                header("Content-Type: " . (@mime_content_type($f) ?: 'application/octet-stream'));
                header("X-Content-Type-Options: nosniff");
                header("Content-Disposition: inline; filename=\"" . rawurlencode($view) . "\"");
                readfile($f);
            } else {
                header("Content-Type: application/octet-stream");
                header("X-Content-Type-Options: nosniff");
                header("Content-Disposition: attachment; filename=" . rawurlencode($view));
                readfile($f);
            }
            exit;
        }
    }
    header("Location:?path=" . urlencode($curPath));
    exit;
}

// 下载
if (!empty($_GET['download'])) {
    $down = basename(trim($_GET['download']));
    if ($down && !preg_match('/[\\\\\/:*?"<>|]/', $down)) {
        $f = "$real/$down";
        if (is_file($f)) {
            header("Content-Type: application/octet-stream");
            header("X-Content-Type-Options: nosniff");
            header("Content-Disposition: attachment; filename=" . rawurlencode($down));
            header("Content-Length: " . filesize($f));
            readfile($f);
            exit;
        }
    }
    header("Location:?path=" . urlencode($curPath));
    exit;
}

$list = scandir($real);
usort($list, function ($a, $b) use ($real) {
    if ($a === '.' || $a === '..') return -1;
    if ($b === '.' || $b === '..') return 1;
    $ad = is_dir("$real/$a");
    $bd = is_dir("$real/$b");
    if ($ad !== $bd) return $bd - $ad;
    return strcasecmp($a, $b);
});

function fmtSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>简易网盘</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
body{background:#f7f8fa;color:#2a2a2a;padding:12px;min-height:100vh}
.wrap{max-width:700px;margin:0 auto;padding:18px;background:#fff;border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,.06)}
.top-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;gap:10px}
.top-bar h3{font-size:18px;font-weight:600;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.logout-btn{padding:10px 14px;border:none;border-radius:10px;background:#f3f4f6;color:#6b7280;font-size:14px;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .2s}
.logout-btn:hover{background:#e5e7eb;color:#374151}
.back-row{margin-bottom:16px}
.back-link{color:#7299c7;text-decoration:none;font-size:14px;font-weight:500}
.back-link:hover{text-decoration:underline}
.upload-area{background:#f9fafb;border:1px dashed #d1d5db;border-radius:12px;padding:16px;margin-bottom:16px;transition:border-color .2s}
.upload-area:hover{border-color:#7299c7}
.upload-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
#fileInp{display:none}
.btn-blue{padding:10px 22px;border:none;border-radius:10px;background:#7299c7;color:#fff;font-size:14px;cursor:pointer;font-weight:500;white-space:nowrap;transition:background .2s}
.btn-blue:hover{background:#5e87b8}
.btn-blue:disabled{background:#b1c6e0;cursor:not-allowed}
#tip{margin-top:10px;font-size:14px;color:#6b7280;min-height:18px}
.progress{margin-top:8px;height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;display:none}
.progress.show{display:block}
.progress-bar{height:100%;width:0%;background:#7299c7;transition:width .2s}
.new-folder-row{display:flex;gap:10px;align-items:center;margin-bottom:18px}
.new-folder-row input{flex:1;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;outline:none;font-size:14px;background:#f9fafb;transition:all .2s}
.new-folder-row input:focus{border-color:#7299c7;background:#fff;box-shadow:0 0 0 3px rgba(114,153,199,.15)}
hr{border:0;border-top:1px solid #e5e7eb;margin:18px 0}
h4{margin-bottom:12px;color:#374151;font-size:16px;font-weight:600}
.item{padding:12px 14px;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;border-radius:8px;transition:background .15s}
.item:hover{background:#f9fafb}
.item:last-child{border-bottom:none}
.item-name{flex:1;min-width:140px;font-size:15px;color:#1f2937;display:flex;align-items:center;gap:8px;overflow:hidden}
.item-name .name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.item-name .size{font-size:12px;color:#9ca3af;flex-shrink:0}
.item-actions{display:flex;gap:6px;flex-wrap:wrap}
a{text-decoration:none}
.link-blue{color:#7299c7;font-size:14px;padding:8px 10px;border-radius:8px;background:#f0f4f8;transition:background .2s}
.link-blue:hover{background:#e4edf5}
.link-del{color:#dc2626;font-size:14px;padding:8px 10px;border-radius:8px;background:#fef2f2;transition:background .2s}
.link-del:hover{background:#fee2e2}
.empty-tip{color:#6b7280;padding:30px 20px;text-align:center;font-size:14px}
@media (max-width:520px){
    .wrap{padding:14px;border-radius:12px}
    .item-name{min-width:100%}
    .item-actions{width:100%}
}
</style>
</head>
<body>
<div class="wrap">
    <div class="top-bar">
        <h3>📂 /<?php echo htmlspecialchars($curPath); ?></h3>
        <a class="logout-btn" href="?logout">退出登录</a>
    </div>
    <?php if ($curPath !== ''): ?>
    <div class="back-row">
        <a class="back-link" href="?path=<?php echo urlencode(dirname($curPath) === '.' ? '' : dirname($curPath)); ?>">← 返回上级目录</a>
    </div>
    <?php endif; ?>

    <div class="upload-area">
        <div class="upload-row">
            <input type="file" id="fileInp" multiple>
            <label for="fileInp" class="btn-blue">选择文件</label>
            <button class="btn-blue" id="upBtn" onclick="up(event)">上传文件</button>
        </div>
        <div id="tip">支持图片、文档、压缩包、视频等，单文件建议 ≤ 500MB</div>
        <div class="progress" id="prog"><div class="progress-bar" id="progBar"></div></div>
    </div>

    <form class="new-folder-row" method="post">
        <input name="newdir" hidden value="1">
        <input placeholder="输入新文件夹名称" name="dirname" required>
        <button class="btn-blue">创建文件夹</button>
    </form>

    <hr>
    <h4>文件列表</h4>

    <?php
    $hasFile = false;
    foreach ($list as $item):
        if ($item === '.' || $item === '..') continue;
        $hasFile = true;
        $isDir = is_dir("$real/$item");
        $nextPath = ltrim("$curPath/$item", '/');
        $sizeStr = $isDir ? '' : fmtSize(@filesize("$real/$item") ?: 0);
    ?>
    <div class="item">
        <span class="item-name">
            <span><?= $isDir ? '📁' : '📄' ?></span>
            <?php if ($isDir): ?>
                <a class="link-blue" style="background:transparent;padding:0" href="?path=<?= urlencode($nextPath) ?>"><?= htmlspecialchars($item) ?></a>
            <?php else: ?>
                <span class="name"><?= htmlspecialchars($item) ?></span>
                <span class="size"><?= $sizeStr ?></span>
            <?php endif; ?>
        </span>
        <span class="item-actions">
            <?php if (!$isDir): ?>
            <a class="link-blue" href="?path=<?= urlencode($curPath) ?>&view=<?= urlencode($item) ?>" target="_blank">预览</a>
            <a class="link-blue" href="?path=<?= urlencode($curPath) ?>&download=<?= urlencode($item) ?>">下载</a>
            <?php endif; ?>
            <a class="link-del" href="javascript:delConfirm('<?= urlencode($item) ?>')">删除</a>
        </span>
    </div>
    <?php endforeach; ?>
    <?php if (!$hasFile): ?>
    <div class="empty-tip">暂无文件，上传一个试试吧</div>
    <?php endif; ?>
</div>

<script>
const chunkSize = 512 * 1024;
const curPath = <?= json_encode($curPath, JSON_UNESCAPED_UNICODE) ?>;

function delConfirm(name) {
    if (confirm("确定要删除该文件/文件夹吗？")) {
        location.href = "?path=" + encodeURIComponent(curPath) + "&del=" + name;
    }
}

async function sendChunk(file, i) {
    const s = i * chunkSize;
    const e = Math.min(s + chunkSize, file.size);
    const fd = new FormData();
    fd.append("chunk_data", file.slice(s, e));
    fd.append("filename", file.name);
    fd.append("index", i);
    fd.append("path", curPath);
    for (let r = 0; r < 3; r++) {
        try {
            const res = await fetch(location.href, {
                method: "POST",
                body: fd,
                signal: AbortSignal.timeout(20000)
            });
            const text = await res.text();
            let j;
            try { j = JSON.parse(text); }
            catch(e) {
                document.getElementById("tip").innerText = "服务器返回异常：" + text.substring(0, 200);
                return false;
            }
            if (j.ok) return true;
            if (j.msg) { document.getElementById("tip").innerText = "错误：" + j.msg; return false; }
        } catch (err) {
            await new Promise(t => setTimeout(t, 700));
        }
    }
    return false;
}

async function up(ev) {
    const files = document.getElementById("fileInp").files;
    const tip = document.getElementById("tip");
    const btn = ev.target;
    const prog = document.getElementById("prog");
    const progBar = document.getElementById("progBar");

    if (!files.length) { tip.innerText = "请先选择文件"; return; }
    btn.disabled = true;

    let totalChunks = 0;
    for (let f of files) totalChunks += Math.ceil(f.size / chunkSize);
    let doneChunks = 0;
    prog.classList.add("show");

    for (let f of files) {
        const total = Math.ceil(f.size / chunkSize);
        for (let i = 0; i < total; i++) {
            tip.innerText = `上传中：${f.name} (${i + 1}/${total})`;
            if (!await sendChunk(f, i)) {
                btn.disabled = false;
                return;
            }
            doneChunks++;
            progBar.style.width = Math.round(doneChunks / totalChunks * 100) + "%";
        }
    }
    tip.innerText = "✅ 上传完成，正在刷新…";
    setTimeout(() => location.reload(), 700);
}
</script>
</body>
</html>
