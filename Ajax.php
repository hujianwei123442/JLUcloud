<?php
/**
 * Ajax.php - 前台 API 接口文件
 * 
 * 功能：处理前台所有 AJAX 请求
 * 主要接口：
 * - getConfig: 获取系统配置
 * - getList: 获取文件列表（支持本地/OSS/COS，自动合并同步文件）
 * - getUrl: 获取文件下载链接（同步文件重定向到源节点）
 * - search: 搜索文件（同时搜索本地和同步文件）
 * - Delfile: 删除文件（同步文件只删元数据）
 * - createShare: 创建分享链接
 * - login/logout: 用户登录登出
 * - getStorageStats: 获取存储统计
 */

error_reporting(0); // 关闭错误提示
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/app/class/Amoli.class.php';

// 获取请求的操作类型
$act = $_GET['act'];

// 初始化配置和工具类
$C = new Config('Config');        // 配置管理类
$Amoli = new Amoli();             // 业务逻辑类
$oss = $C->get('oss');            // OSS配置
$cos = $C->get('cos');            // COS配置
$type = $C->get('type', 'local'); // 存储类型：local/oss/cos
$indexpass = $C->get('indexpass');// 前台访问密码
$Cookie = $_COOKIE['Amoli_index'];// 登录Cookie

// 判断是否登录（仅对需要登录的接口进行验证）
if ($indexpass) {
    if ($act == 'getList') {
        if (!isset($Cookie) || $Cookie != md5($indexpass)) {
            $login = false;
            echo json_encode(['code' => 2, 'msg' => '你未登录，请先登录！']);
            exit();
        }
    }
}
switch ($act) {
    case 'getConfig': // 获取配置
        // 判断是否登录
        ($Cookie == md5($indexpass) || !$indexpass) ? $log = true : $log = false;
        // 判断是否安装
        file_exists('install/install.lock') ? $install = true : $install = false;
        $result = ['code' => 1, 'msg' => '获取成功！', 'data' => ['name' => $C->get('name'),  'record' => $C->get('record'), 'install' => $install, 'log' => $log, 'verify' => $C->get('verify', false)]];
        break;
    case 'getList': // 加载目录
        $dir = $_POST['dir'];
        switch ($type) {
            case 'local': //本地存储
                $dir = $_SERVER['DOCUMENT_ROOT'] . '/' . $Amoli->getEncoding($C->get('localhost') . $dir, true);
                $list = $Amoli->getLocalList($dir);
                
                // 如果是根目录，合并同步的文件元数据
                if (($_POST['dir'] == '' || $_POST['dir'] == '/') && is_array($list)) {
                    $fileMetaPath = __DIR__ . '/FileMeta';
                    if (file_exists($fileMetaPath . '.php')) {
                        $FileMeta = new Config($fileMetaPath);
                        $syncedFiles = $FileMeta->get('files', []);
                        
                        // 获取本地已存在的文件名
                        $localFileNames = [];
                        foreach ($list as $item) {
                            if (isset($item['type']) && $item['type'] == 'file' && isset($item['name'])) {
                                $localFileNames[] = $item['name'];
                            }
                        }
                        
                        // 添加同步的文件（标记为来自其他节点）
                        foreach ($syncedFiles as $syncedFile) {
                            // 检查文件是否已在本地列表中
                            if (isset($syncedFile['name']) && !in_array($syncedFile['name'], $localFileNames)) {
                                // 格式化文件大小
                                $fileSize = isset($syncedFile['size']) ? $syncedFile['size'] : 0;
                                if ($fileSize >= 1073741824) {
                                    $sizeStr = round($fileSize / 1073741824, 2) . ' GB';
                                } elseif ($fileSize >= 1048576) {
                                    $sizeStr = round($fileSize / 1048576, 2) . ' MB';
                                } elseif ($fileSize >= 1024) {
                                    $sizeStr = round($fileSize / 1024, 2) . ' KB';
                                } else {
                                    $sizeStr = $fileSize . ' B';
                                }
                                
                                $list[] = [
                                    'type' => 'file',
                                    'name' => $syncedFile['name'] . ' [同步]',
                                    'size' => $sizeStr,
                                    'time' => $syncedFile['upload_time'] ?? '',
                                    'synced' => true,
                                    'source_node_url' => $syncedFile['source_node_url'] ?? '',
                                    'upload_by' => $syncedFile['upload_by'] ?? '未知'
                                ];
                            }
                        }
                    }
                }
                break;
            case 'oss': //OSS存储
                $dir = $oss['osshost'] . $dir;
                $list = $Amoli->getOssList($oss['bucket'], $oss['endpoint'], $oss['accessKeyId'], $oss['accessKeySecret'], $dir);
                break;
            case 'cos': //COS存储
                $dir = $cos['coshost'] . $dir;
                $list = $Amoli->getCosList($cos['bucket'], $cos['region'], $cos['secretId'], $cos['secretKey'], $dir);
                break;
        }
        $result = ['code' => 1, 'msg' => '获取成功', 'data' => $list];
        break;
    case 'verify': // 生成验证
        require_once __DIR__ . '/app/class/Geetestlib.class.php';
        $GtSdk = new GeetestLib('Amoli', '1552294270');
        $data = [
            'user_id' => $C->get('name'),
            'client_type' => 'web',
            'ip_address' => $_SERVER["REMOTE_ADDR"]
        ];
        $status = $GtSdk->pre_process($data, 1);
        echo $GtSdk->get_response_str();
        return;
    case 'getUrl': // 获取文件下载Url
        if ($C->get('verify', false)) {
            require_once __DIR__ . '/app/class/Geetestlib.class.php';
            $GtSdk = new GeetestLib('Amoli', '1552294270');
            $data = [
                'user_id' => $C->get('name'),
                'client_type' => 'web',
                'ip_address' => $_SERVER["REMOTE_ADDR"]
            ];
            if (!$GtSdk->success_validate($_POST['geetest_challenge'], $_POST['geetest_validate'], $_POST['geetest_seccode'], $data)) {
                $result = ['code' => 2, 'msg' => '非法访问！'];
                break;
            }
        }
        $dir = $_POST['dir'];
        $forceDownload = isset($_POST['force_download']) ? $_POST['force_download'] : false;
        
        if (!$dir) {
            $result = ['code' => 2, 'msg' => '非法访问！'];
            break;
        }
        
        // 检查是否是同步文件（文件名包含 [同步] 标记）
        if (strpos($dir, ' [同步]') !== false) {
            // 这是同步文件，需要从源节点获取下载链接
            $fileMetaPath = __DIR__ . '/FileMeta';
            $FileMeta = new Config($fileMetaPath);
            $files = $FileMeta->get('files', []);
            
            // 移除 [同步] 标记获取真实文件名
            $realFileName = str_replace(' [同步]', '', basename($dir));
            $found = false;
            $fileInfo = null;
            
            foreach ($files as $file) {
                if ($file['name'] == $realFileName) {
                    $fileInfo = $file;
                    $found = true;
                    break;
                }
            }
            
            if ($found && isset($fileInfo['source_node_url'])) {
                // 从源节点获取下载链接
                $sourceUrl = rtrim($fileInfo['source_node_url'], '/\\');
                // 构造完整的下载URL：源节点URL + upload目录 + 文件名
                // 例如：http://localhost:8002/upload/文件名.docx
                $downloadUrl = $sourceUrl . '/upload/' . $fileInfo['path'];
                $result = ['code' => 1, 'msg' => '获取成功！', 'data' => ['url' => $downloadUrl]];
            } else {
                $result = ['code' => 2, 'msg' => '同步文件源节点信息缺失，无法下载！'];
            }
        } else {
            // 普通文件，按原逻辑处理
            switch ($type) {
                case 'local':
                    // 如果是强制下载，使用download.php
                    if ($forceDownload) {
                        $result = ['code' => 1, 'msg' => '获取成功！', 'data' => ['url' => '/download.php?file=' . urlencode($dir)]];
                    } else {
                        $url = '/' . $C->get('localhost') . $dir;
                        $result = ['code' => 1, 'msg' => '获取成功！', 'data' => ['url' => $url]];
                    }
                    break;
                case 'oss':
                    $object = $oss['osshost'] . $dir;
                    $result = $Amoli->getOssUrl($oss['bucket'], $oss['endpoint'], $oss['accessKeyId'], $oss['accessKeySecret'], $oss['ossdomain'], $object);
                    break;
                case 'cos':
                    $object = $cos['coshost'] . $dir;
                    $result = $Amoli->getCosUrl($cos['bucket'], $cos['region'], $cos['secretId'], $cos['secretKey'], $object);
                    break;
            }
        }
        break;
    case 'login': // 前台登录
        $POST_pass = md5($_POST['indexpass']);
        if ($POST_pass == md5($indexpass)) {
            setcookie('Amoli_index', $POST_pass, time() + 3600 * 24); // 写入Cookies
            $result = ['code' => 1, 'msg' => '登录成功！'];
        } else {
            $result = ['code' => 2, 'msg' => '密码错误！'];
        }
        break;

    case 'logout': // 退出登录
        setcookie('Amoli_index', '', time() - 1552294270);
        exit('<script language="javascript">alert("您已成功注销本次登陆！");window.location.href="./";</script>');
        break;

    case 'search': // 文件搜索
        $keyword = $_POST['keyword'];
        $dir = $_POST['dir'] ?? '';
        if (!$keyword) {
            $result = ['code' => 2, 'msg' => '请输入搜索关键词'];
            break;
        }
        switch ($type) {
            case 'local':
                $searchDir = $_SERVER['DOCUMENT_ROOT'] . '/' . $Amoli->getEncoding($C->get('localhost') . $dir, true);
                $files = $Amoli->searchLocalFiles($searchDir, $keyword);
                
                // 同时搜索同步的文件
                $fileMetaPath = __DIR__ . '/FileMeta';
                if (file_exists($fileMetaPath . '.php')) {
                    $FileMeta = new Config($fileMetaPath);
                    $syncedFiles = $FileMeta->get('files', []);
                    
                    foreach ($syncedFiles as $syncedFile) {
                        // 检查文件名是否包含关键词
                        if (isset($syncedFile['name']) && stripos($syncedFile['name'], $keyword) !== false) {
                            // 格式化文件大小
                            $fileSize = isset($syncedFile['size']) ? $syncedFile['size'] : 0;
                            if ($fileSize >= 1073741824) {
                                $sizeStr = round($fileSize / 1073741824, 2) . ' GB';
                            } elseif ($fileSize >= 1048576) {
                                $sizeStr = round($fileSize / 1048576, 2) . ' MB';
                            } elseif ($fileSize >= 1024) {
                                $sizeStr = round($fileSize / 1024, 2) . ' KB';
                            } else {
                                $sizeStr = $fileSize . ' B';
                            }
                            
                            // 获取文件扩展名
                            $ext = strtolower(pathinfo($syncedFile['name'], PATHINFO_EXTENSION));
                            
                            $files[] = [
                                'type' => $ext,
                                'name' => $syncedFile['name'] . ' [同步]',
                                'size' => $sizeStr,
                                'time' => $syncedFile['upload_time'] ?? '',
                                'path' => dirname($syncedFile['path']) != '.' ? dirname($syncedFile['path']) : ''
                            ];
                        }
                    }
                }
                
                // 如果没有找到任何文件，显示提示
                if (empty($files) || (count($files) == 1 && $files[0]['type'] == 'null')) {
                    $files = [['type' => 'null', 'name' => '未找到匹配的文件', 'size' => '', 'time' => '', 'path' => '']];
                }
                break;
            case 'oss':
                $searchDir = $oss['osshost'] . $dir;
                $files = $Amoli->searchOssFiles($oss['bucket'], $oss['endpoint'], $oss['accessKeyId'], $oss['accessKeySecret'], $searchDir, $keyword);
                break;
            case 'cos':
                $searchDir = $cos['coshost'] . $dir;
                $files = $Amoli->searchCosFiles($cos['bucket'], $cos['region'], $cos['secretId'], $cos['secretKey'], $searchDir, $keyword);
                break;
        }
        $result = ['code' => 1, 'msg' => '搜索成功', 'data' => $files];
        break;

    case 'createShare': // 创建分享链接
        $dir = $_POST['dir'];
        $expireDays = intval($_POST['expire_days'] ?? 7);
        $password = $_POST['password'] ?? '';
        $maxDownloads = intval($_POST['max_downloads'] ?? 0);
        
        if (!$dir) {
            $result = ['code' => 2, 'msg' => '文件路径不能为空'];
            break;
        }
        
        $shareId = substr(md5($dir . time() . rand(1000, 9999)), 0, 8);
        $shareData = [
            'id' => $shareId,
            'file_path' => $dir,
            'password' => $password,
            'expire_time' => date('Y-m-d H:i:s', time() + $expireDays * 86400),
            'max_downloads' => $maxDownloads,
            'download_count' => 0,
            'create_time' => date('Y-m-d H:i:s')
        ];
        
        $Share = new Config('Shares');
        $shares = $Share->get('shares', []);
        $shares[] = $shareData;
        $Share->set('shares', $shares);
        $Share->save();
        
        $shareUrl = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/share.php?id=' . $shareId;
        $result = ['code' => 1, 'msg' => '分享链接创建成功', 'data' => ['url' => $shareUrl, 'id' => $shareId, 'password' => $password]];
        break;

    case 'getShareInfo': // 获取分享信息
        $shareId = $_GET['id'];
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
            $result = ['code' => 2, 'msg' => '分享链接不存在或已失效'];
            break;
        }
        
        if (strtotime($shareInfo['expire_time']) < time()) {
            $result = ['code' => 2, 'msg' => '分享链接已过期'];
            break;
        }
        
        if ($shareInfo['max_downloads'] > 0 && $shareInfo['download_count'] >= $shareInfo['max_downloads']) {
            $result = ['code' => 2, 'msg' => '下载次数已达上限'];
            break;
        }
        
        $result = ['code' => 1, 'msg' => '获取成功', 'data' => [
            'file_path' => $shareInfo['file_path'],
            'has_password' => !empty($shareInfo['password']),
            'expire_time' => $shareInfo['expire_time'],
            'download_count' => $shareInfo['download_count'],
            'max_downloads' => $shareInfo['max_downloads']
        ]];
        break;

    case 'verifySharePassword': // 验证分享密码
        $shareId = $_POST['id'];
        $password = $_POST['password'];
        $Share = new Config('Shares');
        $shares = $Share->get('shares', []);
        
        foreach ($shares as $key => $share) {
            if ($share['id'] == $shareId) {
                if ($share['password'] == $password) {
                    // 增加下载次数
                    $shares[$key]['download_count']++;
                    $Share->set('shares', $shares);
                    $Share->save();
                    $result = ['code' => 1, 'msg' => '验证成功', 'data' => ['file_path' => $share['file_path']]];
                } else {
                    $result = ['code' => 2, 'msg' => '密码错误'];
                }
                break;
            }
        }
        break;

    case 'getStorageStats': // 获取存储统计
        switch ($type) {
            case 'local':
                $dir = $_SERVER['DOCUMENT_ROOT'] . '/' . $Amoli->getEncoding($C->get('localhost'), true);
                $stats = $Amoli->getLocalStorageStats($dir);
                break;
            case 'oss':
                $stats = $Amoli->getOssStorageStats($oss['bucket'], $oss['endpoint'], $oss['accessKeyId'], $oss['accessKeySecret'], $oss['osshost']);
                break;
            case 'cos':
                $stats = $Amoli->getCosStorageStats($cos['bucket'], $cos['region'], $cos['secretId'], $cos['secretKey'], $cos['coshost']);
                break;
        }
        $result = ['code' => 1, 'msg' => '获取成功', 'data' => $stats];
        break;

    case 'Delfile': // 删除单个文件
        $dir = $_POST['dir'];
        if (!$dir) {
            $result = ['code' => 2, 'msg' => '文件路径不能为空'];
            break;
        }
        
        // 检查是否是同步文件
        if (strpos($dir, ' [同步]') !== false) {
            // 同步文件只删除元数据，不删除实际文件
            $fileMetaPath = __DIR__ . '/FileMeta';
            $FileMeta = new Config($fileMetaPath);
            $files = $FileMeta->get('files', []);
            
            $realFileName = str_replace(' [同步]', '', basename($dir));
            $newFiles = [];
            $found = false;
            
            foreach ($files as $file) {
                if ($file['name'] != $realFileName) {
                    $newFiles[] = $file;
                } else {
                    $found = true;
                }
            }
            
            if ($found) {
                $FileMeta->set('files', $newFiles);
                $FileMeta->save();
                $result = ['code' => 1, 'msg' => '同步文件元数据删除成功！'];
            } else {
                $result = ['code' => 2, 'msg' => '未找到该同步文件'];
            }
        } else {
            // 普通文件，按原逻辑删除
            switch ($type) {
                case 'local':
                    $filePath = $_SERVER['DOCUMENT_ROOT'] . '/' . $Amoli->getEncoding($C->get('localhost') . $dir, true);
                    if (file_exists($filePath) && unlink($filePath)) {
                        $result = ['code' => 1, 'msg' => '删除成功！'];
                    } else {
                        $result = ['code' => 2, 'msg' => '删除失败，文件不存在或无权限'];
                    }
                    break;
                case 'oss':
                    $object = $oss['osshost'] . $dir;
                    $result = $Amoli->getOssDel($oss['bucket'], $oss['endpoint'], $oss['accessKeyId'], $oss['accessKeySecret'], $object);
                    break;
                case 'cos':
                    $object = $cos['coshost'] . $dir;
                    $result = $Amoli->getCosDel($cos['bucket'], $cos['region'], $cos['secretId'], $cos['secretKey'], $object);
                    break;
            }
        }
        break;

    case 'batchDelete': // 批量删除
        $files = $_POST['files'];
        if (!$files || !is_array($files)) {
            $result = ['code' => 2, 'msg' => '请选择要删除的文件'];
            break;
        }
        
        $successCount = 0;
        $failCount = 0;
        
        foreach ($files as $file) {
            // 检查是否是同步文件
            if (strpos($file, ' [同步]') !== false) {
                // 同步文件只删除元数据
                $fileMetaPath = __DIR__ . '/FileMeta';
                $FileMeta = new Config($fileMetaPath);
                $files = $FileMeta->get('files', []);
                
                $realFileName = str_replace(' [同步]', '', basename($file));
                $newFiles = [];
                
                foreach ($files as $f) {
                    if ($f['name'] != $realFileName) {
                        $newFiles[] = $f;
                    }
                }
                
                $FileMeta->set('files', $newFiles);
                $FileMeta->save();
                $successCount++;
            } else {
                // 普通文件
                switch ($type) {
                    case 'local':
                        $filePath = $_SERVER['DOCUMENT_ROOT'] . '/' . $Amoli->getEncoding($C->get('localhost') . $file, true);
                        if (unlink($filePath)) {
                            $successCount++;
                        } else {
                            $failCount++;
                        }
                        break;
                    case 'oss':
                        $object = $oss['osshost'] . $file;
                        $delResult = $Amoli->getOssDel($oss['bucket'], $oss['endpoint'], $oss['accessKeyId'], $oss['accessKeySecret'], $object);
                        if ($delResult['code'] == 1) {
                            $successCount++;
                        } else {
                            $failCount++;
                        }
                        break;
                    case 'cos':
                        $object = $cos['coshost'] . $file;
                        $delResult = $Amoli->getCosDel($cos['bucket'], $cos['region'], $cos['secretId'], $cos['secretKey'], $object);
                        if ($delResult['code'] == 1) {
                            $successCount++;
                        } else {
                            $failCount++;
                        }
                        break;
                }
            }
        }
        
        $result = ['code' => 1, 'msg' => "成功删除 {$successCount} 个文件，失败 {$failCount} 个", 'data' => ['success' => $successCount, 'fail' => $failCount]];
        break;

    case 'batchDownload': // 批量下载（生成zip）
        $files = $_POST['files'];
        if (!$files || !is_array($files)) {
            $result = ['code' => 2, 'msg' => '请选择要下载的文件'];
            break;
        }
        
        $zipName = 'batch_download_' . date('YmdHis') . '.zip';
        $zipPath = sys_get_temp_dir() . '/' . $zipName;
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
            $result = ['code' => 2, 'msg' => '创建压缩包失败'];
            break;
        }
        
        foreach ($files as $file) {
            switch ($type) {
                case 'local':
                    $filePath = $_SERVER['DOCUMENT_ROOT'] . '/' . $Amoli->getEncoding($C->get('localhost') . $file, true);
                    if (file_exists($filePath)) {
                        $zip->addFile($filePath, basename($file));
                    }
                    break;
            }
        }
        
        $zip->close();
        $result = ['code' => 1, 'msg' => '压缩包创建成功', 'data' => ['url' => '/temp/' . $zipName]];
        break;

    default:
        $result = ['code' => 2, 'msg' => 'No Act!'];
}
echo json_encode($result);
