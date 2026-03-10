<?php
error_reporting(0);
require_once __DIR__ . '/app/class/Amoli.class.php';

$C = new Config('Config');
$Amoli = new Amoli();

// 获取文件路径
$file = $_GET['file'] ?? '';
if (empty($file)) {
    die('文件路径不能为空');
}

// 构建完整文件路径
$filePath = $_SERVER['DOCUMENT_ROOT'] . '/' . $Amoli->getEncoding($C->get('localhost') . $file, true);

// 检查文件是否存在
if (!file_exists($filePath)) {
    die('文件不存在');
}

// 获取文件名
$fileName = basename($file);

// 设置响应头，强制下载
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: must-revalidate');
header('Pragma: public');

// 输出文件内容
readfile($filePath);
exit();






