<?php
error_reporting(0); // 关闭错误提示
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: text/html; charset=UTF-8');
require_once '../app/class/Amoli.class.php';
$C = new Config('../Config');
$Amoli = new Amoli();
$act = $_GET['act'];
$oss = $C->get('oss');
$cos = $C->get('cos');
$user = $C->get('user');
$pass = $C->get('pass');
$type = $C->get('type', 'local');
$schedulePath = __DIR__ . '/../Schedule'; // 日程存储文件（绝对路径，避免相对路径失效）
$usersPath = __DIR__ . '/../Users'; // 用户存储文件
$currentUser = '';

// 获取当前登录用户 - 优先检查所有用户Cookie
$Users = new Config($usersPath);
$users = $Users->get('users', []);

// 先检查普通用户的Cookie
foreach ($users as $userData) {
    $userCookie = $_COOKIE['AmoliAdmin_' . $userData['username']] ?? '';
    if (!empty($userCookie) && $userCookie == $userData['password']) {
        $currentUser = $userData['username'];
        error_log("检测到用户登录: " . $currentUser);
        break;
    }
}

// 如果没有找到普通用户登录，再检查默认管理员
if (empty($currentUser)) {
    $Cookie = $_COOKIE['AmoliAdmin_' . $user] ?? '';
    if (!empty($Cookie) && $Cookie == $pass) {
        $currentUser = $user; // 默认管理员用户
        error_log("检测到默认管理员登录: " . $currentUser);
    }
}

// 判断是否登录(排除登录操作和同步相关操作)
if (empty($currentUser)) {
    // 同步相关的API不需要登录认证
    $noAuthActions = ['login', 'register', 'getUsers', 'getSyncData', 'receiveSyncData'];
    if (!in_array($act, $noAuthActions)) {
        echo json_encode(['code' => 2, 'msg' => '你未登录，请先登录！']);
        return;
    }
}

// 节点同步核心函数 - 双向同步
function performNodeSync($node, $C, $Amoli) {
    $startTime = date('Y-m-d H:i:s');
    $logEntry = [
        'id' => time() . rand(1000, 9999),
        'node_name' => $node['name'],
        'status' => 'running',
        'files_count' => 0,
        'success_count' => 0,
        'fail_count' => 0,
        'start_time' => $startTime,
        'end_time' => '',
        'message' => '同步中...'
    ];
    
    // 1. 测试节点连接
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $node['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode != 200 && $httpCode != 302) {
        $logEntry['status'] = 'failed';
        $logEntry['message'] = '节点连接失败，HTTP状态码：' . $httpCode;
        $logEntry['end_time'] = date('Y-m-d H:i:s');
        return [
            'status' => 0,
            'message' => '节点连接失败',
            'log' => $logEntry
        ];
    }
    
    // 2. 获取同步设置
    $settingsPath = __DIR__ . '/../SyncSettings';
    $Settings = new Config($settingsPath);
    $settings = $Settings->get('settings', ['sync_files' => 'on', 'sync_schedule' => 'on', 'sync_users' => 'off']);
    
    $syncedItems = 0;
    $failedItems = 0;
    
    // 3. 同步日程数据（双向）
    if ($settings['sync_schedule'] == 'on') {
        try {
            $schedulePath = __DIR__ . '/../Schedule';
            $Schedule = new Config($schedulePath);
            $localSchedules = $Schedule->get('schedules', []);
            
            // 3.1 获取远程节点的日程数据（直接读取文件，不需要认证）
            $remoteScheduleUrl = rtrim($node['url'], '/') . '/admin/ajax.php?act=getSyncData&type=schedule';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $remoteScheduleUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $scheduleJson = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($scheduleJson) {
                $scheduleData = json_decode($scheduleJson, true);
                if ($scheduleData && $scheduleData['code'] == 1 && isset($scheduleData['data'])) {
                    $remoteSchedules = $scheduleData['data'];
                    
                    // 3.2 合并远程日程到本地（避免重复）
                    $existingIds = array_column($localSchedules, 'id');
                    $newFromRemote = 0;
                    foreach ($remoteSchedules as $remoteSchedule) {
                        if (!in_array($remoteSchedule['id'], $existingIds)) {
                            $localSchedules[] = $remoteSchedule;
                            $newFromRemote++;
                            $syncedItems++;
                        }
                    }
                    
                    // 3.3 推送本地日程到远程节点
                    $pushUrl = rtrim($node['url'], '/') . '/admin/ajax.php?act=receiveSyncData';
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $pushUrl);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                        'type' => 'schedule',
                        'data' => json_encode($localSchedules)
                    ]));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    $pushResult = curl_exec($ch);
                    curl_close($ch);
                    
                    // 3.4 保存合并后的本地日程
                    $Schedule->set('schedules', $localSchedules);
                    $Schedule->save();
                    
                    $logEntry['message'] .= " 日程：从远程获取{$newFromRemote}条";
                } else {
                    $failedItems++;
                    $logEntry['message'] .= " 日程同步失败：" . ($scheduleData['msg'] ?? '未知错误');
                }
            } else {
                $failedItems++;
                $logEntry['message'] .= " 日程同步失败：无法连接到远程节点 ($curlError)";
            }
        } catch (Exception $e) {
            $failedItems++;
            $logEntry['message'] .= " 日程同步异常：" . $e->getMessage();
        }
    }
    
    // 4. 同步文件元数据（双向）
    if ($settings['sync_files'] == 'on') {
        try {
            $fileMetaPath = __DIR__ . '/../FileMeta';
            $FileMeta = new Config($fileMetaPath);
            $localFiles = $FileMeta->get('files', []);
            $originalLocalFiles = $localFiles; // 保存原始本地文件列表，用于推送
            
            // 4.1 获取远程节点的文件元数据
            $remoteFileUrl = rtrim($node['url'], '/') . '/admin/ajax.php?act=getSyncData&type=files';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $remoteFileUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $fileJson = curl_exec($ch);
            curl_close($ch);
            
            if ($fileJson) {
                $fileData = json_decode($fileJson, true);
                if ($fileData && $fileData['code'] == 1 && isset($fileData['data'])) {
                    $remoteFiles = $fileData['data'];
                    
                    // 4.2 合并远程文件元数据到本地
                    // 使用文件名来判断是否存在，而不是路径
                    $existingNames = array_column($localFiles, 'name');
                    $newFilesFromRemote = 0;
                    foreach ($remoteFiles as $remoteFile) {
                        // 检查文件名是否已存在
                        if (!in_array($remoteFile['name'], $existingNames)) {
                            // 确保远程文件有 source_node_url 字段
                            if (!isset($remoteFile['source_node_url']) || empty($remoteFile['source_node_url'])) {
                                $remoteFile['source_node_url'] = $node['url'];
                            }
                            $localFiles[] = $remoteFile;
                            $existingNames[] = $remoteFile['name']; // 添加到已存在列表
                            $newFilesFromRemote++;
                            $syncedItems++;
                        }
                    }
                    
                    // 4.3 推送本地原有的文件元数据到远程节点（不包含刚从远程获取的）
                    $pushUrl = rtrim($node['url'], '/') . '/admin/ajax.php?act=receiveSyncData';
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $pushUrl);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                        'type' => 'files',
                        'data' => json_encode($originalLocalFiles) // 只推送原始本地文件
                    ]));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    $pushResult = curl_exec($ch);
                    curl_close($ch);
                    
                    // 4.4 保存合并后的本地文件元数据
                    $FileMeta->set('files', $localFiles);
                    $FileMeta->save();
                    
                    $logEntry['message'] .= " 文件：从远程获取{$newFilesFromRemote}个";
                    $logEntry['files_count'] = $newFilesFromRemote;
                }
            }
        } catch (Exception $e) {
            $failedItems++;
            $logEntry['message'] .= " 文件同步异常：" . $e->getMessage();
        }
    }
    
    $logEntry['success_count'] = $syncedItems;
    $logEntry['fail_count'] = $failedItems;
    $logEntry['end_time'] = date('Y-m-d H:i:s');
    $logEntry['status'] = 'success';
    
    if ($syncedItems > 0) {
        if (empty($logEntry['message']) || $logEntry['message'] == '同步中...') {
            $logEntry['message'] = "同步成功，共同步 {$syncedItems} 项数据";
        } else {
            $logEntry['message'] .= " | 总计同步 {$syncedItems} 项";
        }
        return [
            'status' => 1,
            'message' => $logEntry['message'],
            'log' => $logEntry
        ];
    } else {
        $logEntry['message'] = "同步完成，数据已是最新";
        return [
            'status' => 1,
            'message' => "同步完成，数据已是最新",
            'log' => $logEntry
        ];
    }
}
switch ($act) {
    case 'getList': // 加载目录
        $dir = $_POST['dir'];
        $reply = [];
        ($dir) ? $reply[] = ['type' => 'reply', 'name' => '返回上一层', 'size' => '', 'time' => ''] : '';
        switch ($type) {
            case 'local':
                $dir = $_SERVER['DOCUMENT_ROOT'] . '/' . $Amoli->getEncoding($C->get('localhost') . $dir, true);
                $list = $Amoli->getLocalList($dir);
                
                // 如果是根目录，合并同步的文件元数据
                if (($_POST['dir'] == '' || $_POST['dir'] == '/') && is_array($list)) {
                    $fileMetaPath = __DIR__ . '/../FileMeta';
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
                                    'upload_by' => $syncedFile['upload_by'] ?? '未知'
                                ];
                            }
                        }
                    }
                }
                break;
            case 'oss':
                $dir = $oss['osshost'] . $dir;
                $list = $Amoli->getOssList($oss['bucket'], $oss['endpoint'], $oss['accessKeyId'], $oss['accessKeySecret'], $dir);
                break;
            case 'cos':
                $dir = $cos['coshost'] . $dir;
                $list = $Amoli->getCosList($cos['bucket'], $cos['region'], $cos['secretId'], $cos['secretKey'], $dir);
                break;
        }
        $list = array_merge($reply, (array) $list);
        $result = ['code' => 0, 'msg' => '获取成功！', 'data' => $list];
        break;
    case 'Downfile': // 下载文件
        $object = $_POST['dir'];
        
        // 检查是否是同步文件（文件名包含 [同步] 标记）
        if (strpos($object, ' [同步]') !== false) {
            // 这是同步文件，需要从源节点获取下载链接
            $fileMetaPath = __DIR__ . '/../FileMeta';
            $FileMeta = new Config($fileMetaPath);
            $files = $FileMeta->get('files', []);
            
            // 移除 [同步] 标记获取真实文件名
            $realFileName = str_replace(' [同步]', '', basename($object));
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
                $sourceUrl = rtrim($fileInfo['source_node_url'], '/');
                $downloadUrl = $sourceUrl . '/' . $C->get('localhost') . $fileInfo['path'];
                $result = ['code' => 1, 'msg' => '获取成功！', 'data' => ['url' => $downloadUrl, 'is_synced' => true]];
            } else {
                $result = ['code' => 2, 'msg' => '同步文件源节点信息缺失，无法下载！'];
            }
        } else {
            // 普通文件，按原逻辑处理
            switch ($type) {
                case 'local':
                    $url = '/' . $C->get('localhost') . $object;
                    $result = ['code' => 1, 'msg' => '获取成功！', 'data' => ['url' => $url]];
                    break;
                case 'oss':
                    $object = $oss['osshost'] . $object;
                    $result = $Amoli->getOssUrl($oss['bucket'], $oss['endpoint'], $oss['accessKeyId'], $oss['accessKeySecret'], $oss['ossdomain'], $object);
                    break;
                case 'cos':
                    $object = $cos['coshost'] . $object;
                    $result = $Amoli->getCosUrl($cos['bucket'], $cos['region'], $cos['secretId'], $cos['secretKey'], $object);
                    break;
            }
        }
        break;
    case 'NewFolder': // 新建目录
        $dir = $_POST['dir'];
        //判断目录格式
        if ((strrpos($dir, '/') + 1) != strlen($dir)) {
            $result = ['code' => 0, 'msg' => '目录格式有误！'];
            echo json_encode($result);
            return;
        };
        switch ($type) {
            case 'local':
                $dir = $_SERVER['DOCUMENT_ROOT'] . '/' . $Amoli->getEncoding($C->get('localhost') . $dir, true);
                mkdir($dir, 0777, true) ? $result = ['code' => 1, 'msg' => '创建成功！'] : $result = ['code' => 2, 'msg' => '创建失败！'];
                break;
            case 'oss':
                $dir = $oss['osshost'] . $dir;
                $result = $Amoli->OssNewFolder($oss['bucket'], $oss['endpoint'], $oss['accessKeyId'], $oss['accessKeySecret'], $dir);
                break;
            case 'cos':
                $dir = $cos['coshost'] . $dir;
                $result = $Amoli->CosNewFolder($cos['bucket'], $cos['region'], $cos['secretId'], $cos['secretKey'], $dir);
                break;
        }
        break;
    case 'share': // 分享文件
        $object = $_POST['dir'];
        $time = date('Y-m-d H:i:s');
        $size = $_POST['size'];
        
        // 生成分享链接（不依赖外部API）
        $url = 'http://';
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') $url = 'https://';
        $url .= $_SERVER['HTTP_HOST'];
        $name = dirname(dirname($_SERVER['SCRIPT_NAME']));
        ($name == DIRECTORY_SEPARATOR) ? $dir = '' : $dir = $name;
        
        // 检查是否是同步文件（文件名包含 [同步] 标记）
        if (strpos($object, ' [同步]') !== false) {
            // 这是同步文件，需要特殊处理
            $fileMetaPath = __DIR__ . '/../FileMeta';
            $FileMeta = new Config($fileMetaPath);
            $files = $FileMeta->get('files', []);
            
            // 移除 [同步] 标记获取真实文件名
            $realFileName = str_replace(' [同步]', '', basename($object));
            $found = false;
            $fileInfo = null;
            
            foreach ($files as $file) {
                if ($file['name'] == $realFileName) {
                    $fileInfo = $file;
                    $found = true;
                    break;
                }
            }
            
            if ($found) {
                // 使用真实路径生成分享链接
                $realPath = $fileInfo['path'];
                $data = rawurlencode(base64_encode($realPath . '{/}' . $time . '{/}' . $size . '{/}synced'));
                $shareUrl = $url . $dir . '/share.php?' . $data;
                $result = ['code' => 1, 'msg' => '获取成功！', 'data' => ['url' => $shareUrl, 'is_synced' => true]];
            } else {
                $result = ['code' => 2, 'msg' => '同步文件信息缺失，无法分享！'];
            }
        } else {
            // 普通文件，直接生成分享链接
            $data = rawurlencode(base64_encode($object . '{/}' . $time . '{/}' . $size));
            $shareUrl = $url . $dir . '/share.php?' . $data;
            $result = ['code' => 1, 'msg' => '获取成功！', 'data' => ['url' => $shareUrl]];
        }
        break;
    case 'Delfile': // 删除文件
        $object = $_POST['dir'];
        
        // 检查是否是同步文件（文件名包含 [同步] 标记）
        if (strpos($object, ' [同步]') !== false) {
            // 这是同步文件，从元数据中删除
            $fileMetaPath = __DIR__ . '/../FileMeta';
            $FileMeta = new Config($fileMetaPath);
            $files = $FileMeta->get('files', []);
            
            // 移除 [同步] 标记获取真实文件名
            $realFileName = str_replace(' [同步]', '', basename($object));
            $found = false;
            
            foreach ($files as $key => $file) {
                if ($file['name'] == $realFileName) {
                    unset($files[$key]);
                    $files = array_values($files);
                    $found = true;
                    break;
                }
            }
            
            if ($found) {
                $FileMeta->set('files', $files);
                $msg = $FileMeta->save();
                if ($msg === true) {
                    $result = ['code' => 1, 'msg' => '同步文件元数据删除成功！'];
                } else {
                    $result = ['code' => 2, 'msg' => '删除失败：' . $msg];
                }
            } else {
                $result = ['code' => 2, 'msg' => '未找到该同步文件！'];
            }
        } else {
            // 普通文件，按原逻辑删除
            switch ($type) {
                case 'local':
                    $file = $_SERVER['DOCUMENT_ROOT'] . '/' . $Amoli->getEncoding($C->get('localhost') . $object, true);
                    if (unlink($file)) {
                        $result = ['code' => 1, 'msg' => '删除成功！'];
                    } else {
                        $result = ['code' => 2, 'msg' => '删除失败！'];
                    }
                    break;
                case 'oss':
                    $object = $oss['osshost'] . $object;
                    $result = $Amoli->getOssDel($oss['bucket'], $oss['endpoint'], $oss['accessKeyId'], $oss['accessKeySecret'], $object);
                    break;
                case 'cos':
                    $object = $cos['coshost'] . $object;
                    $result = $Amoli->getCosDel($cos['bucket'], $cos['region'], $cos['secretId'], $cos['secretKey'], $object);
                    break;
            }
        }
        break;
    case 'Upfile': // 上传文件
        $dir = $_POST['dir'];
        switch ($type) {
            case 'local':
                $filename = $_FILES['file']['name'];
                if ($filename) {
                    $source = $_FILES['file']['tmp_name'];
                    $dir = $Amoli->getEncoding($C->get('localhost') . $dir . $filename, true);
                    $destination = $_SERVER['DOCUMENT_ROOT'] . '/' . $dir;
                    $uploadSuccess = move_uploaded_file($source, $destination);
                    $msg = $uploadSuccess ? '上传成功' : '上传失败';
                    $data = ['msg' => $msg, 'name' => $filename];
                    
                    // 记录文件元数据用于同步
                    if ($uploadSuccess) {
                        $fileMetaPath = __DIR__ . '/../FileMeta';
                        $FileMeta = new Config($fileMetaPath);
                        $files = $FileMeta->get('files', []);
                        
                        $filePath = $_POST['dir'] . $filename;
                        $fileSize = filesize($destination);
                        
                        // 生成完整的源节点URL
                        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? 'https://' : 'http://';
                        $host = $_SERVER['HTTP_HOST'];
                        $scriptDir = dirname(dirname($_SERVER['SCRIPT_NAME']));
                        $baseUrl = $protocol . $host . ($scriptDir == '/' ? '' : $scriptDir);
                        
                        $files[] = [
                            'id' => time() . rand(1000, 9999),
                            'name' => $filename,
                            'path' => $filePath,
                            'size' => $fileSize,
                            'type' => $type,
                            'upload_by' => $currentUser,
                            'upload_time' => date('Y-m-d H:i:s'),
                            'source_node_url' => $baseUrl
                        ];
                        
                        $FileMeta->set('files', $files);
                        $FileMeta->save();
                    }
                }
                break;
            case 'oss':
                $dir = $oss['osshost'] . $dir;
                $data = $Amoli->OssUpfile($oss['bucket'], $oss['endpoint'], $oss['accessKeyId'], $oss['accessKeySecret'], $dir);
                
                // 记录文件元数据
                if (isset($data['name'])) {
                    $fileMetaPath = __DIR__ . '/../FileMeta';
                    $FileMeta = new Config($fileMetaPath);
                    $files = $FileMeta->get('files', []);
                    
                    $files[] = [
                        'id' => time() . rand(1000, 9999),
                        'name' => $data['name'],
                        'path' => $_POST['dir'] . $data['name'],
                        'size' => $_FILES['file']['size'],
                        'type' => $type,
                        'upload_by' => $currentUser,
                        'upload_time' => date('Y-m-d H:i:s'),
                        'source_node_url' => 'http://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME']))
                    ];
                    
                    $FileMeta->set('files', $files);
                    $FileMeta->save();
                }
                break;
            case 'cos':
                $dir = $cos['coshost'] . $dir;
                $data = $Amoli->CosUpfile($cos['bucket'], $cos['region'], $cos['secretId'], $cos['secretKey'], $dir);
                
                // 记录文件元数据
                if (isset($data['name'])) {
                    $fileMetaPath = __DIR__ . '/../FileMeta';
                    $FileMeta = new Config($fileMetaPath);
                    $files = $FileMeta->get('files', []);
                    
                    $files[] = [
                        'id' => time() . rand(1000, 9999),
                        'name' => $data['name'],
                        'path' => $_POST['dir'] . $data['name'],
                        'size' => $_FILES['file']['size'],
                        'type' => $type,
                        'upload_by' => $currentUser,
                        'upload_time' => date('Y-m-d H:i:s'),
                        'source_node_url' => 'http://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME']))
                    ];
                    
                    $FileMeta->set('files', $files);
                    $FileMeta->save();
                }
                break;
        }
        $data = array_merge(['type' => $type], (array) $data);
        $result = ['code' => 1, 'msg' => '获取成功！', 'data' => $data];
        break;
    case 'systemParameter': // 系统基本参数
        $data = $C->get() + [
            'tongji' => ($_GET['bool']) ? file_get_contents('../static/js/tj.js') : '',
            'server' => PHP_OS,
            'host' => $_SERVER['HTTP_HOST'],
            'root' => $_SERVER['DOCUMENT_ROOT'] . dirname(dirname($_SERVER['SCRIPT_NAME'])),
            'server_software' => $_SERVER['SERVER_SOFTWARE'],
            'php_version' => PHP_VERSION,
            'upload_max' => get_cfg_var("file_uploads") ? get_cfg_var("upload_max_filesize") : '空间不允许上传',
            'time' => date('Y-m-d H:i:s', time())
        ];
        $result = ['code' => 1, 'msg' => '获取成功！', 'data' => $data];
        break;
    case 'webconfig': // 网站配置
        $C->set('name', $_POST['name']);
        $type = trim($_POST['type']);
        $C->set('type', $type);
        $C->set('localhost', trim($_POST['localhost']));
        $oss = [
            'bucket' => trim($_POST['OssBucket']),
            'endpoint' => trim($_POST['endpoint']),
            'accessKeyId' => trim($_POST['accessKeyId']),
            'accessKeySecret' => trim($_POST['accessKeySecret']),
            'ossdomain' => trim($_POST['ossdomain']),
            'osshost' => trim($_POST['osshost'])
        ];
        $C->set('oss', $oss);
        $cos = [
            'bucket' => trim($_POST['CosBucket']),
            'region' => trim($_POST['region']),
            'secretId' => trim($_POST['secretId']),
            'secretKey' => trim($_POST['secretKey']),
            'coshost' => trim($_POST['coshost'])
        ];
        $C->set('cos', $cos);
        $C->set('indexpass', trim($_POST['indexpass']));
        ($_POST['verify'] == 'true') ? $verify = true : $verify = false;
        $C->set('verify', $verify);
        $C->set('record', $_POST['record']);
        $msg = $C->save();
        //统计代码
        $file_strm = fopen('../static/js/tj.js', 'w');
        if (!$file_strm) $msg = '写入文件失败，请赋予 tj.js 文件写权限！';
        fwrite($file_strm, $_POST['tongji']);
        fclose($file_strm);
        //设置跨域规则
        switch ($type) {
            case 'oss':
                $Amoli->OssCors($oss['bucket'], $oss['endpoint'], $oss['accessKeyId'], $oss['accessKeySecret']);
                break;
            case 'cos':
                $Amoli->CosCors($cos['bucket'], $cos['region'], $cos['secretId'], $cos['secretKey']);
                break;
        }
        ($msg == true) ? $result = ['code' => 1, 'msg' => '修改成功！'] : $result = ['code' => 2, 'msg' => $msg];
        break;
    case 'login': // 登录后台
        $POST_user = $_POST['user'];
        $POST_pass = MD5($_POST['pass'] . '$$Www.Amoli.Co$$');
        $loginTime = date('Y-m-d H:i:s');
        
        // 先清除所有用户的Cookie，避免多个用户Cookie同时存在
        error_log("清除所有旧的Cookie");
        
        // 清除默认管理员Cookie
        setcookie('AmoliAdmin_' . $user, '', time() - 3600, '/', '', false, true);
        
        // 清除所有其他用户的Cookie
        $Users = new Config($usersPath);
        $users = $Users->get('users', []);
        foreach ($users as $userData) {
            setcookie('AmoliAdmin_' . $userData['username'], '', time() - 3600, '/', '', false, true);
        }
        
        // 清除所有以 AmoliAdmin_ 开头的Cookie
        foreach ($_COOKIE as $cookieName => $cookieValue) {
            if (strpos($cookieName, 'AmoliAdmin_') === 0) {
                setcookie($cookieName, '', time() - 3600, '/', '', false, true);
            }
        }
        
        // 验证登录并设置新Cookie
        $loginSuccess = false;
        
        // 先检查是否是默认管理员
        if ($POST_user == $user && $POST_pass == $pass) {
            setcookie('AmoliAdmin_' . $POST_user, $POST_pass, time() + 3600 * 24, '/', '', false, true);
            $result = ['code' => 1, 'msg' => '登录成功！'];
            $loginSuccess = true;
            error_log("默认管理员 $POST_user 登录成功");
        } else {
            // 检查是否是其他用户
            foreach ($users as $userData) {
                if ($userData['username'] == $POST_user && $userData['password'] == $POST_pass) {
                    setcookie('AmoliAdmin_' . $POST_user, $POST_pass, time() + 3600 * 24, '/', '', false, true);
                    $result = ['code' => 1, 'msg' => '登录成功！'];
                    $loginSuccess = true;
                    error_log("用户 $POST_user 登录成功");
                    break;
                }
            }
        }
        
        if (!$loginSuccess) {
            $result = ['code' => 2, 'msg' => '帐号或者密码错误！'];
            error_log("登录失败: username=$POST_user");
        }
        
        $C->set('loginTime', $loginTime);
        $C->save();
        break;
    case 'logout': // 退出登录
        // 删除当前登录用户的 Cookie
        if (!empty($currentUser)) {
            setcookie('AmoliAdmin_' . $currentUser, '', time() - 3600, '/', '', false, true);
            error_log("用户 $currentUser 退出登录");
        }
        
        // 同时删除默认管理员的 Cookie（兼容旧逻辑）
        setcookie('AmoliAdmin_' . $user, '', time() - 3600, '/', '', false, true);
        
        // 删除所有可能的用户 Cookie
        $Users = new Config($usersPath);
        $users = $Users->get('users', []);
        foreach ($users as $userData) {
            setcookie('AmoliAdmin_' . $userData['username'], '', time() - 3600, '/', '', false, true);
        }
        
        // 清除所有以 AmoliAdmin_ 开头的 Cookie
        foreach ($_COOKIE as $cookieName => $cookieValue) {
            if (strpos($cookieName, 'AmoliAdmin_') === 0) {
                setcookie($cookieName, '', time() - 3600, '/', '', false, true);
                error_log("清除 Cookie: $cookieName");
            }
        }
        
        exit('<script language="javascript">
            // 清除所有 Cookie
            document.cookie.split(";").forEach(function(c) { 
                document.cookie = c.replace(/^ +/, "").replace(/=.*/, "=;expires=" + new Date().toUTCString() + ";path=/"); 
            });
            alert("您已成功注销本次登陆！");
            window.location.href="./?logout=1";
        </script>');
        break;
    case 'lock': // 锁屏验证
        $lockPwd = MD5($_POST['lockPwd'] . '$$Www.Amoli.Co$$');
        if ($lockPwd == $pass) {
            $result = ['code' => 1, 'msg' => '成功！'];
        } else {
            $result = ['code' => 2, 'msg' => '密码错误！'];
        }
        break;
    case 'setaccount': // 修改后台帐号密码
        $POST_user = $_POST['user'];
        $POST_pass = MD5($_POST['pass'] . '$$Www.Amoli.Co$$');
        $POST_confirmPwd = $_POST['confirmPwd'];
        if ($POST_pass != $pass) {
            $result = ['code' => 2, 'msg' => '密码错误，请重新输入！'];
        } else {
            $C->set('user', $POST_user);
            $C->set('pass', MD5($POST_confirmPwd . '$$Www.Amoli.Co$$'));
            $msg = $C->save();
            if ($msg) {
                $result = ['code' => 1, 'msg' => '修改成功！'];
            } else {
                $result = ['code' => 2, 'msg' => $msg];
            }
        }
        break;
    // ========== 日程管理相关接口 ==========
    case 'scheduleList': // 获取日程列表
        $Schedule = new Config($schedulePath);
        $schedules = $Schedule->get('schedules', []);
        $filter = isset($_POST['filter']) ? $_POST['filter'] : 'all'; // all, today, week, month
        $date = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');

        // 只显示分配给当前用户或当前用户创建的日程
        $userSchedules = [];
        foreach ($schedules as $schedule) {
            if (($schedule['assigned_to'] ?? $schedule['created_by'] ?? '') == $currentUser ||
                ($schedule['created_by'] ?? '') == $currentUser) {
                $userSchedules[] = $schedule;
            }
        }

        $filteredSchedules = [];
        foreach ($userSchedules as $schedule) {
            $scheduleDate = $schedule['date'];
            $include = false;

            switch ($filter) {
                case 'today':
                    $include = ($scheduleDate == date('Y-m-d'));
                    break;
                case 'week':
                    $weekStart = date('Y-m-d', strtotime('monday this week'));
                    $weekEnd = date('Y-m-d', strtotime('sunday this week'));
                    $include = ($scheduleDate >= $weekStart && $scheduleDate <= $weekEnd);
                    break;
                case 'month':
                    $monthStart = date('Y-m-01');
                    $monthEnd = date('Y-m-t');
                    $include = ($scheduleDate >= $monthStart && $scheduleDate <= $monthEnd);
                    break;
                default:
                    $include = true;
            }

            if ($include) {
                $filteredSchedules[] = $schedule;
            }
        }
        
        // 按日期排序
        usort($filteredSchedules, function($a, $b) {
            return strcmp($a['date'], $b['date']);
        });
        
        $result = ['code' => 1, 'msg' => '获取成功！', 'data' => $filteredSchedules];
        break;
        
    case 'scheduleAdd': // 添加日程
        $Schedule = new Config($schedulePath);
        $schedules = $Schedule->get('schedules', []);

        $newSchedule = [
            'id' => time() . rand(1000, 9999),
            'title' => trim($_POST['title']),
            'content' => trim($_POST['content']),
            'date' => trim($_POST['date']),
            'time' => trim($_POST['time']),
            'type' => isset($_POST['type']) ? trim($_POST['type']) : 'normal', // low, normal, important, urgent
            'status' => 'pending', // pending, completed
            'created_by' => $currentUser, // 创建者
            'assigned_to' => isset($_POST['assigned_to']) ? trim($_POST['assigned_to']) : $currentUser, // 分配给谁
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s')
        ];

        $schedules[] = $newSchedule;
        $Schedule->set('schedules', $schedules);
        $msg = $Schedule->save();

        // 添加调试信息到响应中
        $debug_info = [
            'received_data' => $_POST,
            'schedule_path' => $schedulePath,
            'current_count' => count($schedules),
            'new_schedule' => $newSchedule,
            'save_result' => $msg
        ];

        if ($msg === true) {
            $result = ['code' => 1, 'msg' => '添加成功！', 'data' => $newSchedule, 'debug' => $debug_info];
        } else {
            $result = ['code' => 2, 'msg' => '保存失败：' . $msg, 'debug' => $debug_info];
        }
        break;
        
    case 'scheduleEdit': // 编辑日程
        $Schedule = new Config($schedulePath);
        $schedules = $Schedule->get('schedules', []);
        $id = $_POST['id'];

        $found = false;
        $old_data = null;
        $new_data = null;

        foreach ($schedules as $key => $schedule) {
            if ($schedule['id'] == $id) {
                $old_data = $schedule;
                $schedules[$key]['title'] = trim($_POST['title']);
                $schedules[$key]['content'] = trim($_POST['content']);
                $schedules[$key]['date'] = trim($_POST['date']);
                $schedules[$key]['time'] = trim($_POST['time']);
                $schedules[$key]['type'] = isset($_POST['type']) ? trim($_POST['type']) : 'normal';
                $schedules[$key]['assigned_to'] = isset($_POST['assigned_to']) ? trim($_POST['assigned_to']) : $schedule['assigned_to'];
                $schedules[$key]['update_time'] = date('Y-m-d H:i:s');
                $new_data = $schedules[$key];
                $found = true;
                break;
            }
        }

        $debug_info = [
            'received_data' => $_POST,
            'schedule_path' => $schedulePath,
            'searching_id' => $id,
            'current_count' => count($schedules),
            'found' => $found,
            'old_data' => $old_data,
            'new_data' => $new_data
        ];

        if ($found) {
            $Schedule->set('schedules', $schedules);
            $msg = $Schedule->save();
            $debug_info['save_result'] = $msg;

            if ($msg === true) {
                $result = ['code' => 1, 'msg' => '修改成功！', 'debug' => $debug_info];
            } else {
                $result = ['code' => 2, 'msg' => '保存失败：' . $msg, 'debug' => $debug_info];
            }
        } else {
            $result = ['code' => 2, 'msg' => '日程不存在！', 'debug' => $debug_info];
        }
        break;
        
    case 'scheduleDelete': // 删除日程
        $Schedule = new Config($schedulePath);
        $schedules = $Schedule->get('schedules', []);
        $id = $_POST['id'];
        
        $found = false;
        foreach ($schedules as $key => $schedule) {
            if ($schedule['id'] == $id) {
                unset($schedules[$key]);
                $schedules = array_values($schedules); // 重新索引数组
                $found = true;
                break;
            }
        }
        
        if ($found) {
            $Schedule->set('schedules', $schedules);
            $msg = $Schedule->save();
            ($msg === true) ? $result = ['code' => 1, 'msg' => '删除成功！'] : $result = ['code' => 2, 'msg' => '保存失败：' . $msg];
        } else {
            $result = ['code' => 2, 'msg' => '日程不存在！'];
        }
        break;
        
    case 'scheduleComplete': // 标记完成/未完成
        $Schedule = new Config($schedulePath);
        $schedules = $Schedule->get('schedules', []);
        $id = $_POST['id'];
        $status = $_POST['status']; // completed 或 pending
        
        $found = false;
        foreach ($schedules as $key => $schedule) {
            if ($schedule['id'] == $id) {
                $schedules[$key]['status'] = $status;
                $schedules[$key]['update_time'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        
        if ($found) {
            $Schedule->set('schedules', $schedules);
            $msg = $Schedule->save();
            ($msg === true) ? $result = ['code' => 1, 'msg' => '操作成功！'] : $result = ['code' => 2, 'msg' => '保存失败：' . $msg];
        } else {
            $result = ['code' => 2, 'msg' => '日程不存在！'];
        }
        break;
        
    case 'scheduleAnalysis': // 获取统计分析数据
        $Schedule = new Config($schedulePath);
        $schedules = $Schedule->get('schedules', []);
        $filter = isset($_POST['filter']) ? $_POST['filter'] : 'month'; // week, month, year, all
        
        $startDate = '';
        $endDate = date('Y-m-d');
        
        switch ($filter) {
            case 'week':
                $startDate = date('Y-m-d', strtotime('monday this week'));
                break;
            case 'month':
                $startDate = date('Y-m-01');
                break;
            case 'year':
                $startDate = date('Y-01-01');
                break;
            case 'all':
                $startDate = '1970-01-01';
                break;
        }
        
        $total = 0;
        $completed = 0;
        $pending = 0;
        $byType = ['normal' => 0, 'important' => 0, 'urgent' => 0];
        $byDate = [];
        $byStatus = ['completed' => 0, 'pending' => 0];
        
        foreach ($schedules as $schedule) {
            $scheduleDate = $schedule['date'];
            if ($scheduleDate >= $startDate && $scheduleDate <= $endDate) {
                $total++;
                if ($schedule['status'] == 'completed') {
                    $completed++;
                    $byStatus['completed']++;
                } else {
                    $pending++;
                    $byStatus['pending']++;
                }
                
                if (isset($byType[$schedule['type']])) {
                    $byType[$schedule['type']]++;
                }
                
                // 按日期统计
                if (!isset($byDate[$scheduleDate])) {
                    $byDate[$scheduleDate] = ['total' => 0, 'completed' => 0];
                }
                $byDate[$scheduleDate]['total']++;
                if ($schedule['status'] == 'completed') {
                    $byDate[$scheduleDate]['completed']++;
                }
            }
        }
        
        // 计算完成率
        $completionRate = $total > 0 ? round(($completed / $total) * 100, 2) : 0;
        
        // 准备日期趋势数据（最近30天）
        $trendData = [];
        $trendLabels = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $trendLabels[] = date('m-d', strtotime("-$i days"));
            $trendData[] = isset($byDate[$date]) ? $byDate[$date]['completed'] : 0;
        }
        
        $result = [
            'code' => 1,
            'msg' => '获取成功！',
            'data' => [
                'total' => $total,
                'completed' => $completed,
                'pending' => $pending,
                'completionRate' => $completionRate,
                'byType' => $byType,
                'byStatus' => $byStatus,
                'byDate' => $byDate,
                'trendLabels' => $trendLabels,
                'trendData' => $trendData
            ]
        ];
        break;

    case 'register': // 用户注册（仅管理员）
        // 检查当前用户是否是管理员
        $isAdmin = false;
        if ($currentUser == $user) {
            $isAdmin = true;
        } else {
            $Users = new Config($usersPath);
            $allUsers = $Users->get('users', []);
            foreach ($allUsers as $userData) {
                if ($userData['username'] == $currentUser && $userData['role'] == 'admin') {
                    $isAdmin = true;
                    break;
                }
            }
        }

        if (!$isAdmin) {
            $result = ['code' => 0, 'msg' => '权限不足，只有管理员可以添加用户'];
            break;
        }

        $Users = new Config($usersPath);
        $users = $Users->get('users', []);

        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $role = trim($_POST['role'] ?? 'user'); // admin, user

        // 调试信息
        error_log("注册请求: username=$username, password=" . substr($password, 0, 3) . "..., role=$role");
        error_log("现有用户数量: " . count($users));

        // 检查用户名是否已存在
        foreach ($users as $userData) {
            if ($userData['username'] == $username) {
                error_log("用户名已存在: $username");
                $result = ['code' => 0, 'msg' => '用户名已存在'];
                break 2;
            }
        }

        // 添加新用户 - 使用与登录相同的加密方式
        $users[] = [
            'username' => $username,
            'password' => MD5($password . '$$Www.Amoli.Co$$'), // 与登录加密方式一致
            'role' => $role,
            'create_time' => date('Y-m-d H:i:s')
        ];

        $Users->set('users', $users);
        $msg = $Users->save();

        if ($msg === true) {
            $result = ['code' => 1, 'msg' => '注册成功'];
        } else {
            $result = ['code' => 0, 'msg' => '注册失败：' . $msg];
        }
        break;

    case 'editUser': // 编辑用户（仅管理员）
        // 检查当前用户是否是管理员
        $isAdmin = false;
        if ($currentUser == $user) {
            $isAdmin = true;
        } else {
            $Users = new Config($usersPath);
            $allUsers = $Users->get('users', []);
            foreach ($allUsers as $userData) {
                if ($userData['username'] == $currentUser && $userData['role'] == 'admin') {
                    $isAdmin = true;
                    break;
                }
            }
        }

        if (!$isAdmin) {
            $result = ['code' => 0, 'msg' => '权限不足，只有管理员可以编辑用户'];
            break;
        }

        $Users = new Config($usersPath);
        $users = $Users->get('users', []);

        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $role = trim($_POST['role'] ?? 'user');

        error_log("编辑用户请求: username=$username, password=" . ($password ? substr($password, 0, 3) . "..." : "空") . ", role=$role");

        $found = false;
        foreach ($users as $key => $userData) {
            if ($userData['username'] == $username) {
                // 更新角色
                $users[$key]['role'] = $role;
                
                // 如果提供了密码，则更新密码 - 使用与登录相同的加密方式
                if (!empty($password)) {
                    $users[$key]['password'] = MD5($password . '$$Www.Amoli.Co$$'); // 与登录加密方式一致
                    error_log("更新密码");
                }
                
                $users[$key]['update_time'] = date('Y-m-d H:i:s');
                $found = true;
                error_log("找到用户并更新");
                break;
            }
        }

        if (!$found) {
            error_log("用户不存在: $username");
            $result = ['code' => 0, 'msg' => '用户不存在'];
            break;
        }

        $Users->set('users', $users);
        $msg = $Users->save();

        if ($msg === true) {
            $result = ['code' => 1, 'msg' => '修改成功'];
        } else {
            $result = ['code' => 0, 'msg' => '修改失败：' . $msg];
        }
        break;

    case 'getCurrentUser': // 获取当前登录用户信息
        if (empty($currentUser)) {
            $result = ['code' => 0, 'msg' => '未登录'];
            break;
        }

        $isAdmin = false;
        $role = 'user';
        
        if ($currentUser == $user) { // 默认admin用户
            $isAdmin = true;
            $role = 'admin';
        } else {
            // 检查其他用户的角色
            $Users = new Config($usersPath);
            $allUsers = $Users->get('users', []);
            foreach ($allUsers as $userData) {
                if ($userData['username'] == $currentUser) {
                    $role = $userData['role'] ?? 'user';
                    $isAdmin = ($role == 'admin');
                    break;
                }
            }
        }

        $result = [
            'code' => 1, 
            'msg' => '获取成功', 
            'data' => [
                'username' => $currentUser,
                'role' => $role,
                'isAdmin' => $isAdmin
            ]
        ];
        break;

    case 'getUsers': // 获取用户列表（管理员权限）
        // 检查当前用户是否是管理员
        $isAdmin = false;
        if ($currentUser == $user) { // 默认admin用户
            $isAdmin = true;
        } else {
            // 检查其他用户是否是管理员
            $Users = new Config($usersPath);
            $allUsers = $Users->get('users', []);
            foreach ($allUsers as $userData) {
                if ($userData['username'] == $currentUser && $userData['role'] == 'admin') {
                    $isAdmin = true;
                    break;
                }
            }
        }

        if (!$isAdmin) {
            $result = ['code' => 0, 'msg' => '权限不足，只有管理员可以查看用户列表'];
            break;
        }

        $Users = new Config($usersPath);
        $users = $Users->get('users', []);
        $result = ['code' => 1, 'msg' => '获取成功', 'data' => $users];
        break;

    case 'deleteUser': // 删除用户（仅管理员）
        // 检查当前用户是否是管理员
        $isAdmin = false;
        if ($currentUser == $user) {
            $isAdmin = true;
        } else {
            $Users = new Config($usersPath);
            $allUsers = $Users->get('users', []);
            foreach ($allUsers as $userData) {
                if ($userData['username'] == $currentUser && $userData['role'] == 'admin') {
                    $isAdmin = true;
                    break;
                }
            }
        }

        if (!$isAdmin) {
            $result = ['code' => 0, 'msg' => '权限不足，只有管理员可以删除用户'];
            break;
        }

        $Users = new Config($usersPath);
        $users = $Users->get('users', []);

        $username = trim($_POST['username']);

        error_log("删除用户请求: username=$username");

        // 不允许删除默认管理员
        if ($username == $user) {
            $result = ['code' => 0, 'msg' => '不能删除默认管理员账号'];
            break;
        }

        $found = false;
        foreach ($users as $key => $userData) {
            if ($userData['username'] == $username) {
                unset($users[$key]);
                $users = array_values($users); // 重新索引数组
                $found = true;
                error_log("找到用户并删除: $username");
                break;
            }
        }

        if (!$found) {
            error_log("用户不存在: $username");
            $result = ['code' => 0, 'msg' => '用户不存在'];
            break;
        }

        $Users->set('users', $users);
        $msg = $Users->save();

        if ($msg === true) {
            $result = ['code' => 1, 'msg' => '删除成功'];
        } else {
            $result = ['code' => 0, 'msg' => '删除失败：' . $msg];
        }
        break;

    // ========== 日程管理接口结束 ==========

    // ========== 文件分配管理接口 ==========
    case 'assignFile': // 分配文件给用户
        $fileAssignPath = __DIR__ . '/../FileAssignments'; // 文件分配存储文件
        
        // 调试信息
        error_log("=== 文件分配调试 ===");
        error_log("文件路径: " . $fileAssignPath);
        error_log("文件是否存在: " . (file_exists($fileAssignPath) ? '是' : '否'));
        error_log("文件是否可写: " . (is_writable($fileAssignPath) ? '是' : '否'));
        error_log("当前用户: " . $currentUser);
        error_log("POST数据: " . json_encode($_POST));
        
        $FileAssign = new Config($fileAssignPath);
        $assignments = $FileAssign->get('assignments', []);
        
        error_log("现有分配数量: " . count($assignments));

        $newAssignment = [
            'id' => time() . rand(1000, 9999),
            'file_path' => trim($_POST['file_path']),
            'file_name' => trim($_POST['file_name']),
            'assigned_to' => trim($_POST['assigned_to']),
            'assigned_by' => $currentUser,
            'permission' => isset($_POST['permission']) ? trim($_POST['permission']) : 'read', // read, write
            'remark' => isset($_POST['remark']) ? trim($_POST['remark']) : '',
            'create_time' => date('Y-m-d H:i:s')
        ];
        
        error_log("新分配数据: " . json_encode($newAssignment));

        $assignments[] = $newAssignment;
        $FileAssign->set('assignments', $assignments);
        $msg = $FileAssign->save();
        
        error_log("保存结果: " . ($msg === true ? '成功' : $msg));
        error_log("保存后数量: " . count($assignments));

        if ($msg === true) {
            $result = ['code' => 1, 'msg' => '分配成功！', 'data' => $newAssignment];
        } else {
            $result = ['code' => 2, 'msg' => '分配失败：' . $msg];
        }
        break;

    case 'getFileAssignments': // 获取文件分配列表（根据角色返回不同数据）
        $fileAssignPath = __DIR__ . '/../FileAssignments';
        $FileAssign = new Config($fileAssignPath);
        $assignments = $FileAssign->get('assignments', []);
        
        // 判断当前用户是否是管理员
        $isAdmin = false;
        if ($currentUser == $user) {
            $isAdmin = true;
        } else {
            $Users = new Config($usersPath);
            $allUsers = $Users->get('users', []);
            foreach ($allUsers as $userData) {
                if ($userData['username'] == $currentUser && $userData['role'] == 'admin') {
                    $isAdmin = true;
                    break;
                }
            }
        }
        
        // 如果是管理员，返回所有文件分配记录
        if ($isAdmin) {
            // 按创建时间倒序排序
            usort($assignments, function($a, $b) {
                return strcmp($b['create_time'], $a['create_time']);
            });
            
            $result = ['code' => 1, 'msg' => '获取成功！', 'data' => $assignments, 'isAdmin' => true];
        } else {
            // 如果是普通用户，只返回分配给自己的文件
            $myAssignments = [];
            foreach ($assignments as $assignment) {
                if ($assignment['assigned_to'] == $currentUser) {
                    $myAssignments[] = $assignment;
                }
            }
            
            // 按创建时间倒序排序
            usort($myAssignments, function($a, $b) {
                return strcmp($b['create_time'], $a['create_time']);
            });
            
            $result = ['code' => 1, 'msg' => '获取成功！', 'data' => $myAssignments, 'isAdmin' => false];
        }
        break;

    case 'getMyFiles': // 获取分配给当前用户的文件
        $fileAssignPath = __DIR__ . '/../FileAssignments';
        $FileAssign = new Config($fileAssignPath);
        $assignments = $FileAssign->get('assignments', []);
        
        // 只返回分配给当前用户的文件
        $myFiles = [];
        foreach ($assignments as $assignment) {
            if ($assignment['assigned_to'] == $currentUser) {
                $myFiles[] = $assignment;
            }
        }
        
        // 按创建时间倒序排序
        usort($myFiles, function($a, $b) {
            return strcmp($b['create_time'], $a['create_time']);
        });
        
        $result = ['code' => 1, 'msg' => '获取成功！', 'data' => $myFiles];
        break;

    case 'editFileAssignment': // 编辑文件分配
        $fileAssignPath = __DIR__ . '/../FileAssignments';
        $FileAssign = new Config($fileAssignPath);
        $assignments = $FileAssign->get('assignments', []);
        $id = $_POST['id'];

        $found = false;
        foreach ($assignments as $key => $assignment) {
            if ($assignment['id'] == $id) {
                $assignments[$key]['file_path'] = trim($_POST['file_path']);
                $assignments[$key]['file_name'] = trim($_POST['file_name']);
                $assignments[$key]['assigned_to'] = trim($_POST['assigned_to']);
                $assignments[$key]['permission'] = isset($_POST['permission']) ? trim($_POST['permission']) : 'read';
                $assignments[$key]['remark'] = isset($_POST['remark']) ? trim($_POST['remark']) : '';
                $assignments[$key]['update_time'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }

        if ($found) {
            $FileAssign->set('assignments', $assignments);
            $msg = $FileAssign->save();
            ($msg === true) ? $result = ['code' => 1, 'msg' => '修改成功！'] : $result = ['code' => 2, 'msg' => '保存失败：' . $msg];
        } else {
            $result = ['code' => 2, 'msg' => '文件分配不存在！'];
        }
        break;

    case 'deleteFileAssignment': // 删除文件分配
        $fileAssignPath = __DIR__ . '/../FileAssignments';
        $FileAssign = new Config($fileAssignPath);
        $assignments = $FileAssign->get('assignments', []);
        $id = $_POST['id'];
        
        $found = false;
        foreach ($assignments as $key => $assignment) {
            if ($assignment['id'] == $id) {
                unset($assignments[$key]);
                $assignments = array_values($assignments);
                $found = true;
                break;
            }
        }
        
        if ($found) {
            $FileAssign->set('assignments', $assignments);
            $msg = $FileAssign->save();
            ($msg === true) ? $result = ['code' => 1, 'msg' => '删除成功！'] : $result = ['code' => 2, 'msg' => '保存失败：' . $msg];
        } else {
            $result = ['code' => 2, 'msg' => '文件分配不存在！'];
        }
        break;

    case 'downloadAssignedFile': // 下载分配的文件
        $filePath = trim($_POST['file_path']);
        
        // 检查用户是否有权限访问此文件
        $fileAssignPath = __DIR__ . '/../FileAssignments';
        $FileAssign = new Config($fileAssignPath);
        $assignments = $FileAssign->get('assignments', []);
        
        $hasPermission = false;
        foreach ($assignments as $assignment) {
            if ($assignment['file_path'] == $filePath && $assignment['assigned_to'] == $currentUser) {
                $hasPermission = true;
                break;
            }
        }
        
        if (!$hasPermission) {
            $result = ['code' => 0, 'msg' => '您没有权限访问此文件'];
            break;
        }
        
        // 返回文件下载地址
        $result = ['code' => 1, 'msg' => '获取成功！', 'data' => ['url' => $filePath]];
        break;

    // ========== 文件分配管理接口结束 ==========

    // ========== 节点同步管理接口 ==========
    case 'getNodeList': // 获取节点列表
        $nodePath = __DIR__ . '/../Nodes';
        $Node = new Config($nodePath);
        $nodes = $Node->get('nodes', []);
        
        $result = ['code' => 0, 'msg' => '获取成功！', 'count' => count($nodes), 'data' => $nodes];
        break;

    case 'addNode': // 添加节点
        $nodePath = __DIR__ . '/../Nodes';
        $Node = new Config($nodePath);
        $nodes = $Node->get('nodes', []);

        $newNode = [
            'id' => time() . rand(1000, 9999),
            'name' => trim($_POST['name']),
            'url' => trim($_POST['url']),
            'location' => trim($_POST['location'] ?? ''),
            'type' => trim($_POST['type']),
            'access_key' => trim($_POST['access_key'] ?? ''),
            'secret_key' => trim($_POST['secret_key'] ?? ''),
            'status' => 0, // 0-离线, 1-在线
            'syncing' => false,
            'sync_progress' => 0,
            'last_sync' => '',
            'remark' => trim($_POST['remark'] ?? ''),
            'create_time' => date('Y-m-d H:i:s')
        ];

        $nodes[] = $newNode;
        $Node->set('nodes', $nodes);
        $msg = $Node->save();

        if ($msg === true) {
            $result = ['code' => 1, 'msg' => '添加成功！', 'data' => $newNode];
        } else {
            $result = ['code' => 2, 'msg' => '添加失败：' . $msg];
        }
        break;

    case 'getNodeDetail': // 获取节点详情
        $nodePath = __DIR__ . '/../Nodes';
        $Node = new Config($nodePath);
        $nodes = $Node->get('nodes', []);
        $id = $_GET['id'];
        
        $found = false;
        foreach ($nodes as $node) {
            if ($node['id'] == $id) {
                $result = ['code' => 1, 'msg' => '获取成功！', 'data' => $node];
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $result = ['code' => 2, 'msg' => '节点不存在！'];
        }
        break;

    case 'updateNode': // 更新节点
        $nodePath = __DIR__ . '/../Nodes';
        $Node = new Config($nodePath);
        $nodes = $Node->get('nodes', []);
        $id = $_POST['id'];

        $found = false;
        foreach ($nodes as $key => $node) {
            if ($node['id'] == $id) {
                $nodes[$key]['name'] = trim($_POST['name']);
                $nodes[$key]['url'] = trim($_POST['url']);
                $nodes[$key]['location'] = trim($_POST['location'] ?? '');
                $nodes[$key]['remark'] = trim($_POST['remark'] ?? '');
                $nodes[$key]['update_time'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }

        if ($found) {
            $Node->set('nodes', $nodes);
            $msg = $Node->save();
            ($msg === true) ? $result = ['code' => 1, 'msg' => '修改成功！'] : $result = ['code' => 2, 'msg' => '保存失败：' . $msg];
        } else {
            $result = ['code' => 2, 'msg' => '节点不存在！'];
        }
        break;

    case 'deleteNode': // 删除节点
        $nodePath = __DIR__ . '/../Nodes';
        $Node = new Config($nodePath);
        $nodes = $Node->get('nodes', []);
        $id = $_POST['id'];
        
        $found = false;
        foreach ($nodes as $key => $node) {
            if ($node['id'] == $id) {
                unset($nodes[$key]);
                $nodes = array_values($nodes);
                $found = true;
                break;
            }
        }
        
        if ($found) {
            $Node->set('nodes', $nodes);
            $msg = $Node->save();
            ($msg === true) ? $result = ['code' => 1, 'msg' => '删除成功！'] : $result = ['code' => 2, 'msg' => '保存失败：' . $msg];
        } else {
            $result = ['code' => 2, 'msg' => '节点不存在！'];
        }
        break;

    case 'testNode': // 测试节点连接
        $nodePath = __DIR__ . '/../Nodes';
        $Node = new Config($nodePath);
        $nodes = $Node->get('nodes', []);
        $id = $_POST['id'];
        
        $found = false;
        $nodeData = null;
        $nodeKey = null;
        foreach ($nodes as $key => $node) {
            if ($node['id'] == $id) {
                $nodeData = $node;
                $nodeKey = $key;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $result = ['code' => 2, 'msg' => '节点不存在！'];
            break;
        }
        
        // 测试连接
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $nodeData['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200 || $httpCode == 302) {
            // 更新节点状态
            $nodes[$nodeKey]['status'] = 1;
            $Node->set('nodes', $nodes);
            $Node->save();
            $result = ['code' => 1, 'msg' => '连接成功！节点在线'];
        } else {
            $nodes[$nodeKey]['status'] = 0;
            $Node->set('nodes', $nodes);
            $Node->save();
            $result = ['code' => 2, 'msg' => '连接失败，HTTP状态码：' . $httpCode];
        }
        break;

    case 'syncSingleNode': // 同步单个节点
        $nodePath = __DIR__ . '/../Nodes';
        $logPath = __DIR__ . '/../SyncLogs';
        
        $Node = new Config($nodePath);
        $Log = new Config($logPath);
        
        $nodes = $Node->get('nodes', []);
        $logs = $Log->get('logs', []);
        $id = $_POST['id'];
        
        $found = false;
        $nodeKey = null;
        foreach ($nodes as $key => $node) {
            if ($node['id'] == $id) {
                $found = true;
                $nodeKey = $key;
                break;
            }
        }
        
        if (!$found) {
            $result = ['code' => 2, 'msg' => '节点不存在！'];
            break;
        }
        
        $node = $nodes[$nodeKey];
        
        // 执行同步
        $syncResult = performNodeSync($node, $C, $Amoli);
        
        // 更新节点状态
        $nodes[$nodeKey]['status'] = $syncResult['status'];
        $nodes[$nodeKey]['last_sync'] = date('Y-m-d H:i:s');
        $Node->set('nodes', $nodes);
        $Node->save();
        
        // 记录日志
        $logs[] = $syncResult['log'];
        $Log->set('logs', $logs);
        $Log->save();
        
        $result = ['code' => 1, 'msg' => $syncResult['message']];
        break;

    case 'syncAllNodes': // 同步所有节点
        $nodePath = __DIR__ . '/../Nodes';
        $logPath = __DIR__ . '/../SyncLogs';
        
        $Node = new Config($nodePath);
        $Log = new Config($logPath);
        
        $nodes = $Node->get('nodes', []);
        $logs = $Log->get('logs', []);
        
        $successCount = 0;
        $failCount = 0;
        
        foreach ($nodes as $key => $node) {
            // 执行同步
            $syncResult = performNodeSync($node, $C, $Amoli);
            
            // 更新节点状态
            $nodes[$key]['status'] = $syncResult['status'];
            $nodes[$key]['last_sync'] = date('Y-m-d H:i:s');
            
            // 记录日志
            $logs[] = $syncResult['log'];
            
            if ($syncResult['status'] == 1) {
                $successCount++;
            } else {
                $failCount++;
            }
        }
        
        $Node->set('nodes', $nodes);
        $Node->save();
        
        $Log->set('logs', $logs);
        $Log->save();
        
        $result = ['code' => 1, 'msg' => "同步完成！成功：{$successCount}，失败：{$failCount}"];
        break;

    case 'getSyncLogs': // 获取同步日志
        $logPath = __DIR__ . '/../SyncLogs';
        $Log = new Config($logPath);
        $logs = $Log->get('logs', []);
        
        // 按时间倒序排序
        usort($logs, function($a, $b) {
            return strcmp($b['start_time'], $a['start_time']);
        });
        
        $result = ['code' => 0, 'msg' => '获取成功！', 'count' => count($logs), 'data' => $logs];
        break;

    case 'saveSyncSettings': // 保存同步设置
        $settingsPath = __DIR__ . '/../SyncSettings';
        $Settings = new Config($settingsPath);
        
        $settings = [
            'sync_files' => isset($_POST['sync_files']) ? 'on' : 'off',
            'sync_schedule' => isset($_POST['sync_schedule']) ? 'on' : 'off',
            'sync_users' => isset($_POST['sync_users']) ? 'on' : 'off',
            'sync_interval' => intval($_POST['sync_interval']),
            'conflict_strategy' => trim($_POST['conflict_strategy']),
            'update_time' => date('Y-m-d H:i:s')
        ];
        
        $Settings->set('settings', $settings);
        $msg = $Settings->save();
        
        ($msg === true) ? $result = ['code' => 1, 'msg' => '保存成功！'] : $result = ['code' => 2, 'msg' => '保存失败：' . $msg];
        break;

    case 'getSyncSettings': // 获取同步设置
        $settingsPath = __DIR__ . '/../SyncSettings';
        $Settings = new Config($settingsPath);
        $settings = $Settings->get('settings', [
            'sync_files' => 'on',
            'sync_schedule' => 'on',
            'sync_users' => 'off',
            'sync_interval' => 15,
            'conflict_strategy' => 'newer'
        ]);
        
        $result = ['code' => 1, 'msg' => '获取成功！', 'data' => $settings];
        break;

    case 'getSyncData': // 提供同步数据给其他节点（无需认证）
        $type = $_GET['type'];
        
        if ($type == 'schedule') {
            $schedulePath = __DIR__ . '/../Schedule';
            $Schedule = new Config($schedulePath);
            $schedules = $Schedule->get('schedules', []);
            $result = ['code' => 1, 'msg' => '获取成功', 'data' => $schedules];
        } elseif ($type == 'files') {
            $fileMetaPath = __DIR__ . '/../FileMeta';
            $FileMeta = new Config($fileMetaPath);
            $files = $FileMeta->get('files', []);
            $result = ['code' => 1, 'msg' => '获取成功', 'data' => $files];
        } elseif ($type == 'users') {
            $usersPath = __DIR__ . '/../Users';
            $Users = new Config($usersPath);
            $users = $Users->get('users', []);
            $result = ['code' => 1, 'msg' => '获取成功', 'data' => $users];
        } else {
            $result = ['code' => 0, 'msg' => '未知的数据类型'];
        }
        break;

    case 'receiveSyncData': // 接收其他节点推送的同步数据（无需认证）
        $type = $_POST['type'];
        $dataJson = $_POST['data'];
        $data = json_decode($dataJson, true);
        
        if (!$data || !is_array($data)) {
            $result = ['code' => 0, 'msg' => '数据格式错误'];
            break;
        }
        
        if ($type == 'schedule') {
            $schedulePath = __DIR__ . '/../Schedule';
            $Schedule = new Config($schedulePath);
            $localSchedules = $Schedule->get('schedules', []);
            
            // 合并远程推送的日程（避免重复）
            $existingIds = array_column($localSchedules, 'id');
            $newCount = 0;
            foreach ($data as $remoteSchedule) {
                if (!in_array($remoteSchedule['id'], $existingIds)) {
                    $localSchedules[] = $remoteSchedule;
                    $existingIds[] = $remoteSchedule['id'];
                    $newCount++;
                }
            }
            
            $Schedule->set('schedules', $localSchedules);
            $msg = $Schedule->save();
            
            if ($msg === true) {
                $result = ['code' => 1, 'msg' => "接收成功，新增 {$newCount} 条日程"];
            } else {
                $result = ['code' => 0, 'msg' => '保存失败：' . $msg];
            }
        } elseif ($type == 'files') {
            $fileMetaPath = __DIR__ . '/../FileMeta';
            $FileMeta = new Config($fileMetaPath);
            $localFiles = $FileMeta->get('files', []);
            
            // 合并远程推送的文件元数据（避免重复）
            $existingPaths = array_column($localFiles, 'path');
            $newCount = 0;
            foreach ($data as $remoteFile) {
                if (!in_array($remoteFile['path'], $existingPaths)) {
                    $localFiles[] = $remoteFile;
                    $existingPaths[] = $remoteFile['path'];
                    $newCount++;
                }
            }
            
            $FileMeta->set('files', $localFiles);
            $msg = $FileMeta->save();
            
            if ($msg === true) {
                $result = ['code' => 1, 'msg' => "接收成功，新增 {$newCount} 个文件"];
            } else {
                $result = ['code' => 0, 'msg' => '保存失败：' . $msg];
            }
        } elseif ($type == 'users') {
            $usersPath = __DIR__ . '/../Users';
            $Users = new Config($usersPath);
            $localUsers = $Users->get('users', []);
            
            // 合并远程推送的用户（避免重复）
            $existingUsernames = array_column($localUsers, 'username');
            $newCount = 0;
            foreach ($data as $remoteUser) {
                if (!in_array($remoteUser['username'], $existingUsernames)) {
                    $localUsers[] = $remoteUser;
                    $existingUsernames[] = $remoteUser['username'];
                    $newCount++;
                }
            }
            
            $Users->set('users', $localUsers);
            $msg = $Users->save();
            
            if ($msg === true) {
                $result = ['code' => 1, 'msg' => "接收成功，新增 {$newCount} 个用户"];
            } else {
                $result = ['code' => 0, 'msg' => '保存失败：' . $msg];
            }
        } else {
            $result = ['code' => 0, 'msg' => '未知的数据类型'];
        }
        break;

    // ========== 节点同步管理接口结束 ==========
    
    default:
        $result = ['code' => 2, 'msg' => 'No Act!'];
}
echo json_encode($result);
