<?php
error_reporting(0); // 关闭错误提示
if (file_exists('install.lock')) {
    echo '<div class="alert alert-warning">您已经安装过，如需重新安装请删除<font color=red> install/install.lock </font>文件后再安装！</div>';
    exit;
}
?>
<html lang="zh-CN">

<head>
    <title>安装向导 - Amoli私有云</title>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#4d545d">
    <link rel="shortcut icon" href="../favicon.ico" />
    <link href="../admin/layui/css/layui.css" rel="stylesheet">
    <script type="text/javascript" src="../admin/layui/layui.js"></script>
</head>
<style type="text/css">
    body{text-align:center}.header{position:fixed;left:0;top:0;width:80%;height:60px;line-height:60px;background:#000;padding:0 10%;z-index:10000}.header h1{color:#fff;font-size:20px;font-weight:600;text-align:center}.install-box{margin:100px auto 0;background:#fff;border-radius:10px;padding:20px;overflow:hidden;box-shadow:5px 5px 15px#888888;display:inline-block;width:680px;min-height:500px}.protocol{text-align:left;height:400px;overflow-y:auto;padding:10px;color:#333}.protocol h2{text-align:center;font-size:16px;color:#000}.step-btns{padding:20px 0 10px 0}.layui-table td,.layui-table th{text-align:left}.layui-table tbody tr.no{background-color:#f00;color:#fff}
    /* 新增功能介绍列表样式 */
    .feature-list {
        list-style: none;
        padding-left: 0;
        margin: 0;
    }
    .feature-list li {
        margin-bottom: 20px;
        border-bottom: 1px dashed #eee;
        padding-bottom: 15px;
    }
    .feature-list li:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }
    .feature-icon {
        font-size: 24px;
        margin-right: 12px;
        vertical-align: middle;
        display: inline-block;
        width: 36px;
        text-align: center;
    }
    .feature-title {
        font-size: 18px;
        font-weight: 600;
        color: #1E9FFF;
        display: inline-block;
        vertical-align: middle;
    }
    .feature-desc {
        margin-top: 8px;
        line-height: 1.7;
        color: #555;
        font-size: 14px;
        padding-left: 48px; /* 图标宽度+间距 */
    }
</style>

<body>
<div class="header">
    <h1>感谢您选择Amoli私有云系统</h1>
</div>
<?php
$step = $_GET['step'];
switch ($step) {
    case '':
    case '1':
        echo '<div class="install-box">
        <fieldset class="layui-elem-field site-demo-button">
            <legend>私有云功能介绍</legend>
            <div class="protocol">
                <ul class="feature-list">
                    <li>
                        <span class="feature-icon">📁</span>
                        <span class="feature-title">文件管理</span>
                        <div class="feature-desc">支持文件上传、下载、预览（图片、文档、音视频）、搜索、批量操作、文件夹管理。界面简洁，操作流畅。</div>
                    </li>
                    <li>
                        <span class="feature-icon">☁️</span>
                        <span class="feature-title">多云存储适配</span>
                        <div class="feature-desc">内置本地存储、阿里云 OSS、腾讯云 COS 驱动，可随时切换存储后端，统一管理分散在不同云上的文件。</div>
                    </li>
                    <li>
                        <span class="feature-icon">🔁</span>
                        <span class="feature-title">分布式节点同步</span>
                        <div class="feature-desc">支持多节点部署，节点间通过元数据双向同步，实现跨节点文件透明访问（下载时自动重定向至源节点）。同步日志全程记录。</div>
                    </li>
                    <li>
                        <span class="feature-icon">👥</span>
                        <span class="feature-title">用户与权限管理</span>
                        <div class="feature-desc">支持管理员与普通用户两级角色。管理员可分配文件给指定用户，并控制读写权限。</div>
                    </li>
                    <li>
                        <span class="feature-icon">📅</span>
                        <span class="feature-title">日程协作</span>
                        <div class="feature-desc">内置日程管理模块，可创建、分配、跟踪团队日程，支持完成状态统计与分析。</div>
                    </li>
                    <li>
                        <span class="feature-icon">🔗</span>
                        <span class="feature-title">文件分享</span>
                        <div class="feature-desc">可生成分享链接，支持设置有效期、访问密码、下载次数限制，方便对外协作。</div>
                    </li>
                </ul>
            </div>
        </fieldset>
        <div class="step-btns">
            <a href="?step=2" class="layui-btn layui-btn-big layui-btn-normal">同意协议并安装系统</a>
        </div>
        </div>';
        break;
    case '2':
        if (phpversion() < '5.6') {
            $version = 'no';
            $fr = '<a href="javascript:;" class="layui-btn layui-btn-big layui-btn-disabled fr">进行下一步</a>';
        } else {
            $version = 'ok';
            $fr = '<a href="?step=3" class="layui-btn layui-btn-big layui-btn-normal fr">进行下一步</a>';
        }
        echo '<div class="install-box">
            <fieldset class="layui-elem-field layui-field-title">
                <legend>运行环境检测</legend>
            </fieldset>
            <table class="layui-table" lay-skin="line">
                <thead>
                    <tr>
                        <th>环境名称</th>
                        <th>当前配置</th>
                        <th>所需配置</th>
                    </tr> 
                </thead>
                <tbody>
                    <tr class="ok">
                        <td>操作系统</td>
                        <td>WINNT</td>
                        <td>Windows/Unix</td>
                    </tr>
                    <tr class="' . $version . '">
                        <td>推荐PHP版本</td>
                        <td>' . phpversion() . '</td>
                        <td>5.6及以上</td>
                    </tr>
                            </tbody>
            </table>
            <table class="layui-table" lay-skin="line">
                <thead>
                    <tr>
                        <th>目录/文件</th>
                        <th>所需权限</th>
                        <th>当前权限</th>
                    </tr> 
                </thead>
                <tbody>
                    <tr class="ok">
                        <td>/Config.php</td>
                        <td>读写</td>
                        <td>未知</td>
                    </tr>
                </tbody>
            </table>
            <div class="step-btns">
                <a href="?step=1" class="layui-btn layui-btn-primary layui-btn-big fl">返回上一步</a>
                ' . $fr . '
                </div>
        </div>';
        break;
    case '3':
        echo '<div class="install-box">
            <fieldset class="layui-elem-field layui-field-title">
                <legend>网站信息配置</legend>
            </fieldset>
            <form class="layui-form layui-form-pane" action="?step=4" method="post">
                <div class="layui-form-item">
                    <label class="layui-form-label">网站名称</label>
                    <div class="layui-input-inline">
                        <input type="text" class="layui-input" name="name" lay-verify="required" value="Amoli云盘">
                    </div>
                    <div class="layui-form-mid" style="color: #FF5722;">您的网站名称 *必填</div>
                </div>
                <div class="layui-form-item">
                    <label class="layui-form-label">前台密码</label>
                    <div class="layui-input-inline">
                        <input type="text" class="layui-input" name="indexpass">
                    </div>
                    <div class="layui-form-mid layui-word-aux">留空即为关闭密码</div>
                </div>
                <div class="layui-form-item">
                    <label class="layui-form-label">网站备案号</label>
                    <div class="layui-input-inline">
                        <input type="text" class="layui-input" name="record">
                    </div>
                    <div class="layui-form-mid layui-word-aux">网站备案号</div>
                </div>
                <fieldset class="layui-elem-field layui-field-title">
                    <legend>管理账号设置</legend>
                </fieldset>
                <div class="layui-form-item">
                    <label class="layui-form-label">管理员账号</label>
                    <div class="layui-input-inline">
                        <input type="text" class="layui-input" name="user" lay-verify="required|user">
                    </div>
                    <div class="layui-form-mid" style="color: #FF5722;">管理员账号最少5位 *必填</div>
                </div>
                <div class="layui-form-item">
                    <label class="layui-form-label">管理员密码</label>
                    <div class="layui-input-inline">
                        <input type="password" class="layui-input" name="pass" lay-verify="required|pass">
                    </div>
                    <div class="layui-form-mid" style="color: #FF5722;">管理员密码最少6位 *必填</div>
                </div>
                <div class="step-btns">
                    <a href="?step=2" class="layui-btn layui-btn-primary layui-btn-big fl">返回上一步</a>
                    <button lay-submit="" lay-filter="ossConfig" class="layui-btn layui-btn-big layui-btn-normal fr">立即执行安装</button>
                </div>
            </form>
        </div>
        <script>
        layui.use(["form"], function () {
            var form = layui.form;
            form.verify({
                user: function (value) {
                    if (value.length < 5) {
                        return "用户名长度不能小于5位";
                    }
                },
                pass: function (value) {
                    if (value.length < 6) {
                        return "密码长度不能小于6位";
                    }
                }
            })
        })
        </script>
        ';
        break;
    case '4':
        $name = $_POST['name'];
        $indexpass = $_POST['indexpass'];
        $record = $_POST['record'];
        $user = $_POST['user'];
        $pass = MD5($_POST['pass'] . '$$Www.Amoli.Co$$');
        if ($name && $user && $pass) {
            require_once '../app/class/Amoli.class.php';
            $C = new Config('../Config');
            // 存储数据
            $C->set('name', $name); // 网站名称
            $C->set('indexpass', $indexpass); // 前台密码
            $C->set('record', $record); // 网站备案号
            $C->set('user', $user); // 后台账户
            $C->set('pass', $pass); // 后台密码
            $msg = $C->save();
            if ($msg) {
                file_put_contents('install.lock', 'www.amoli.co') ? $result = '安装完成!' : $result = 'install.lock写入失败!';
            } else {
                $result = $msg;
            }
        } else {
            $result = '网站名称、管理员账号、管理员密码不允许为空！';
        }
        echo '<div class="install-box">
                <fieldset class="layui-elem-field layui-field-title">
                    <legend>安装提示</legend>
                </fieldset>
                <h1>' . $result . '</h1>
                <div class="step-btns">
                <a href="/" class="layui-btn layui-btn-primary layui-btn-big fl">返回首页</a>
                <a href="../admin/index.html" class="layui-btn layui-btn-big layui-btn-normal fr">前往后台</a>
                </div>
            </div>';
        break;
}
?>
</body>

</html>