<?php
error_reporting(0);
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/app/class/Amoli.class.php';

$C = new Config('Config');
$Amoli = new Amoli();

// 检查是否是新格式（带id参数）
$shareId = $_GET['id'] ?? '';
if ($shareId) {
    // 新格式：使用数据库存储的分享信息
    $Share = new Config('Shares');
    $shares = $Share->get('shares', []);
    $shareInfo = null;

    foreach ($shares as $share) {
        if ($share['id'] == $shareId) {
            $shareInfo = $share;
            break;
        }
    }

    if (!$shareInfo) {
        exit('分享链接不存在或已失效');
    }

    if (strtotime($shareInfo['expire_time']) < time()) {
        exit('分享链接已过期');
    }

    if ($shareInfo['max_downloads'] > 0 && $shareInfo['download_count'] >= $shareInfo['max_downloads']) {
        exit('下载次数已达上限');
    }

    $fileName = basename($shareInfo['file_path']);
    $filePath = $shareInfo['file_path'];
    $hasPassword = !empty($shareInfo['password']);
    $expireTime = $shareInfo['expire_time'];
    $fileSize = $shareInfo['file_size'] ?? '';
    $isSynced = false;
} else {
    // 旧格式：从URL参数解析分享信息
    $queryString = $_SERVER['QUERY_STRING'];
    if (empty($queryString)) {
        exit('分享链接无效');
    }
    
    try {
        $decoded = base64_decode(rawurldecode($queryString));
        $parts = explode('{/}', $decoded);
        
        if (count($parts) < 3) {
            exit('分享链接格式错误');
        }
        
        $filePath = $parts[0];
        $expireTime = $parts[1];
        $fileSize = $parts[2];
        $isSynced = isset($parts[3]) && $parts[3] == 'synced';
        
        $fileName = basename($filePath);
        $hasPassword = false;
        $shareId = '';
    } catch (Exception $e) {
        exit('分享链接解析失败');
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>文件分享 - <?php echo htmlspecialchars($fileName); ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="static/css/bootstrap.min.css">
    <script src="static/js/jquery.min.js"></script>
    <script src="static/js/bootstrap.min.js"></script>
    <script src="static/layer/layer.js"></script>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .share-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 40px;
            max-width: 500px;
            width: 90%;
        }
        .share-icon {
            text-align: center;
            font-size: 60px;
            color: #667eea;
            margin-bottom: 20px;
        }
        .share-title {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
            word-break: break-all;
        }
        .share-info {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
        }
        .share-info p {
            margin: 5px 0;
        }
        .password-input {
            margin-bottom: 20px;
        }
        .btn-download {
            width: 100%;
            padding: 12px;
            font-size: 16px;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 8px;
            color: white;
            transition: transform 0.2s;
        }
        .btn-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
    </style>
</head>
<body>
    <div class="share-container">
        <div class="share-icon">📦</div>
        <div class="share-title"><?php echo htmlspecialchars($fileName); ?></div>
        <div class="share-info">
            <?php if ($shareId): ?>
            <p>过期时间：<?php echo $expireTime; ?></p>
            <?php if (isset($shareInfo['max_downloads']) && $shareInfo['max_downloads'] > 0): ?>
            <p>下载次数：<?php echo $shareInfo['download_count']; ?> / <?php echo $shareInfo['max_downloads']; ?></p>
            <?php endif; ?>
            <?php else: ?>
            <p>文件大小：<?php echo $fileSize; ?></p>
            <p>分享时间：<?php echo $expireTime; ?></p>
            <?php if ($isSynced): ?>
            <p style="color: #667eea;">🔄 同步文件</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <?php if ($hasPassword): ?>
        <div class="password-input">
            <input type="password" class="form-control" id="sharePassword" placeholder="请输入访问密码">
        </div>
        <?php endif; ?>
        
        <button class="btn btn-download" onclick="downloadFile()">
            <?php echo $hasPassword ? '验证并下载' : '立即下载'; ?>
        </button>
    </div>

    <script>
    var shareId = '<?php echo $shareId; ?>';
    var hasPassword = <?php echo $hasPassword ? 'true' : 'false'; ?>;
    var filePath = '<?php echo addslashes($filePath); ?>';
    var isSynced = <?php echo $isSynced ? 'true' : 'false'; ?>;

    function downloadFile() {
        if (hasPassword) {
            var password = $('#sharePassword').val();
            if (!password) {
                layer.msg('请输入访问密码', {icon: 2});
                return;
            }
            
            layer.load(0, {shade: 0.3});
            $.ajax({
                url: 'Ajax.php?act=verifySharePassword',
                type: 'POST',
                data: {
                    id: shareId,
                    password: password
                },
                dataType: 'json',
                success: function(res) {
                    layer.closeAll('loading');
                    if (res.code == 1) {
                        layer.msg('验证成功，开始下载...', {icon: 1});
                        setTimeout(function() {
                            getDownloadUrl(res.data.file_path);
                        }, 1000);
                    } else {
                        layer.msg(res.msg, {icon: 2});
                    }
                },
                error: function() {
                    layer.closeAll('loading');
                    layer.msg('验证失败', {icon: 2});
                }
            });
        } else {
            getDownloadUrl(filePath);
        }
    }

    function getDownloadUrl(path) {
        if (isSynced) {
            // 同步文件，需要从源节点下载
            layer.msg('正在从源节点获取下载链接...', {icon: 16, time: 2000});
            $.ajax({
                url: 'admin/ajax.php?act=downloadSyncedFile',
                type: 'POST',
                data: {dir: path},
                dataType: 'json',
                success: function(res) {
                    if (res.code == 1) {
                        window.location.href = res.data.url;
                    } else {
                        layer.msg(res.msg || '获取下载链接失败', {icon: 2});
                    }
                },
                error: function() {
                    layer.msg('获取下载链接失败', {icon: 2});
                }
            });
        } else {
            // 本地文件，直接下载
            $.ajax({
                url: 'Ajax.php?act=getUrl',
                type: 'POST',
                data: {dir: path},
                dataType: 'json',
                success: function(res) {
                    if (res.code == 1) {
                        window.location.href = res.data.url;
                    } else {
                        layer.msg('获取下载链接失败', {icon: 2});
                    }
                },
                error: function() {
                    layer.msg('获取下载链接失败', {icon: 2});
                }
            });
        }
    }
    </script>
</body>
</html>
