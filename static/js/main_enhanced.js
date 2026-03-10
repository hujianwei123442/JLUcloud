/**
 * main_enhanced.js - 前台核心交互逻辑
 * 
 * 功能：处理前台所有用户交互
 * 主要功能：
 * - 文件列表展示和导航
 * - 文件搜索
 * - 文件预览（图片/视频/音频/PDF/TXT）
 * - 文件下载
 * - 文件删除
 * - 文件分享
 * - 批量操作
 * - 存储统计
 */

// ========== 全局变量 ==========
var nowHash, verify;           // 当前路径Hash、验证码
var selectedFiles = [];        // 已选中的文件列表
var isSearchMode = false;      // 是否处于搜索模式
var currentDir = '';           // 当前目录路径

// ========== 工具函数 ==========

/**
 * 获取文件类型对应的图标名称
 * @param {string} e - 文件扩展名
 * @returns {string} 图标名称
 */
function getType(e) {
    var a = "";
    switch (e) {
        case "zip": case "rar": case "7z": a = "file_zip"; break;
        case "jpg": case "png": case "bmp": case "gif": case "ico": a = "file_img"; break;
        case "htm": case "html": a = "file_html"; break;
        case "php": case "css": case "jsp": case "js": a = "file_code"; break;
        case "exe": a = "file_exe"; break;
        case "docx": case "doc": a = "file_word"; break;
        case "xlsx": case "xls": a = "file_excel"; break;
        case "pptx": case "ppt": a = "file_ppt"; break;
        case "pdf": a = "file_pdf"; break;
        case "psd": a = "file_psd"; break;
        case "mp4": a = "file_video"; break;
        case "mp3": a = "file_music"; break;
        case "txt": a = "file_txt"; break;
        case "wjj": a = "folder"; break;
        case "apk": a = "file_apk"; break;
        default: a = "file";
    }
    return a;
}

// ========== 文件列表相关 ==========

/**
 * 获取文件列表
 * @param {string} e - 触发方式：'nav'(导航点击)/'ml'(文件夹点击)/'hash'(URL变化)
 * @param {string} a - 目录路径
 * @param {object} t - 触发元素
 */
function getList(e, a, t) {
    layer.load(0, { shade: !1 });
    isSearchMode = false;
    var s = "";
    switch (e) {
        case "nav":
        case "hash":
            s = a;
            break;
        case "ml":
            s = a + $(t).text() + "/";
            break;
        default:
            var i = '<a class="breadcrumb-item"></a>';
            $("#nav").html(i);
    }
    currentDir = s;
    
    $.ajax({
        url: "Ajax.php?act=getList",
        type: "POST",
        data: { dir: s },
        dataType: "json",
        error: function () {
            $("#list").html('<tr><td class="text-center" colspan="7">请求错误</td></tr>');
            layer.closeAll("loading");
        },
        success: function (a) {
            setHash(s);
            renderFileList(a.data, s, e, t);
            layer.closeAll("loading");
        }
    });
}

/**
 * 渲染文件列表到页面
 * @param {array} data - 文件列表数据
 * @param {string} dir - 当前目录
 * @param {string} mode - 渲染模式
 * @param {object} element - 触发元素
 */
function renderFileList(data, dir, mode, element) {
    var html = '';
    for (var n = 0, o = data.length; n < o; n++) {
        var file = data[n];
        var checkbox = '<input type="checkbox" class="file-checkbox" data-file="' + dir + file.name + '" onchange="updateSelection()">';
        var icon = '<svg class="icon" aria-hidden="true"><use xlink:href="#icon-' + getType(file.type) + '"></use></svg>';
        
        var nameLink = '';
        if (file.type == "wjj") {
            nameLink = '<a href="javascript:;" onclick="getList(\'ml\',\'' + dir + '\',this)">' + file.name + '</a>';
        } else {
            // 普通文件，点击文件名直接下载
            nameLink = '<a href="javascript:;" onclick="downloadFile(\'' + dir + file.name + '\')">' + file.name + '</a>';
        }
        
        // 操作按钮
        var actions = '';
        if (file.type != 'null' && file.type != 'wjj') {
            // 判断是否可预览
            var previewTypes = ['jpg', 'png', 'bmp', 'gif', 'ico', 'mp4', 'mp3', 'pdf', 'txt'];
            if (previewTypes.indexOf(file.type) !== -1) {
                actions = '<button class="btn btn-sm btn-primary" onclick="previewFile(\'' + file.type + '\',\'' + file.name + '\',\'' + dir + file.name + '\')">预览</button> ';
            }
            actions += '<button class="btn btn-sm btn-success" onclick="downloadFile(\'' + dir + file.name + '\')">下载</button> ';
            actions += '<button class="btn btn-sm btn-info" onclick="createShareLink(\'' + dir + file.name + '\',\'' + file.name + '\')">分享</button> ';
            actions += '<button class="btn btn-sm btn-danger" onclick="deleteFile(\'' + dir + file.name + '\')">删除</button>';
        }
        
        html += '<tr>';
        html += '<td>' + (file.type != 'null' ? checkbox : '') + '</td>';
        html += '<td>' + icon + '</td>';
        html += '<td>' + nameLink + (file.path ? '<small class="text-muted"> (' + file.path + ')</small>' : '') + '</td>';
        html += '<td class="text-right">' + file.size + '</td>';
        html += '<td class="text-center">' + file.time + '</td>';
        html += '<td class="text-center">' + actions + '</td>';
        html += '</tr>';
    }
    
    // 更新导航
    switch (mode) {
        case "nav":
            $(element).nextAll().detach();
            break;
        case "ml":
            var r = '<a class="breadcrumb-item" href="javascript:;" onclick="getList(\'nav\',\'' + dir + '\',this)">' + $(element).text() + '</a>';
            $("#nav").append(r);
            break;
        case "hash":
            var r = '<a class="breadcrumb-item"></a>';
            var d = "", p = dir.split("/");
            for (var i = 0; i < p.length - 1; i++) {
                d += p[i] + "/";
                r += '<a class="breadcrumb-item" href="javascript:;" onclick="getList(\'nav\',\'' + d + '\',this)">' + p[i] + '</a>';
            }
            $("#nav").html(r);
    }
    
    $("#list").html(html);
}

// ========== 文件搜索相关 ==========

/**
 * 搜索文件
 * 功能：根据关键词搜索本地文件和同步文件
 */
function searchFiles() {
    var keyword = $("#searchInput").val().trim();
    if (!keyword) {
        layer.msg('请输入搜索关键词', { icon: 2 });
        return;
    }
    
    layer.load(0, { shade: !1 });
    isSearchMode = true;
    
    $.ajax({
        url: "Ajax.php?act=search",
        type: "POST",
        data: { keyword: keyword, dir: currentDir },
        dataType: "json",
        success: function (res) {
            layer.closeAll("loading");
            if (res.code == 1) {
                renderFileList(res.data, currentDir, 'search', null);
                layer.msg('找到 ' + res.data.length + ' 个文件', { icon: 1 });
            } else {
                layer.msg(res.msg, { icon: 2 });
            }
        },
        error: function () {
            layer.closeAll("loading");
            layer.msg('搜索失败', { icon: 2 });
        }
    });
}

/**
 * 清除搜索，返回正常浏览模式
 */
function clearSearch() {
    $("#searchInput").val('');
    isSearchMode = false;
    getList('hash', currentDir);
}

// ========== 文件预览相关 ==========

/**
 * 文件预览（增强版）
 * @param {string} type - 文件类型
 * @param {string} name - 文件名
 * @param {string} path - 文件路径
 * 功能：支持图片、视频、音频、PDF、TXT预览
 */
function previewFile(type, name, path) {
    var previewTypes = ['jpg', 'png', 'bmp', 'gif', 'ico', 'mp4', 'mp3', 'pdf', 'txt'];
    
    if (previewTypes.indexOf(type) !== -1) {
        $.ajax({
            url: "Ajax.php?act=getUrl",
            type: "POST",
            data: { dir: path },
            dataType: "json",
            success: function (res) {
                if (res.code == 1) {
                    var url = res.data.url;
                    var content = '';
                    var width = '60%', height = '70%';
                    
                    switch (type) {
                        case 'mp3':
                            width = 'auto'; height = 'auto';
                            content = '<audio width="100%" height="100%" controls><source src="' + url + '" type="audio/mpeg">您的浏览器不支持该音频格式。</audio>';
                            break;
                        case 'mp4':
                            if (window.screen.width < 1024) { width = '100%'; height = 'auto'; }
                            content = '<video width="100%" height="100%" controls style="object-fit: fill"><source src="' + url + '" type="video/mp4">您的浏览器不支持该视频格式。</video>';
                            break;
                        case 'pdf':
                            width = '90%'; height = '90%';
                            content = '<iframe src="' + url + '" width="100%" height="100%" style="border:none;"></iframe>';
                            break;
                        case 'txt':
                            width = '70%'; height = '70%';
                            $.get(url, function(data) {
                                layer.open({
                                    type: 1,
                                    title: name + ' - 文件预览',
                                    area: [width, height],
                                    shadeClose: true,
                                    shade: 0.8,
                                    content: '<div style="padding:20px;"><pre>' + $('<div>').text(data).html() + '</pre></div>'
                                });
                            });
                            return;
                        case 'jpg': case 'png': case 'bmp': case 'gif': case 'ico':
                            // 图片预览，添加下载按钮
                            layer.open({
                                type: 1,
                                title: name + ' - 图片预览',
                                area: ['80%', '80%'],
                                shadeClose: true,
                                shade: 0.8,
                                content: '<div style="text-align:center;padding:20px;"><img src="' + url + '" style="max-width:100%;max-height:70vh;"><br><br><a href="' + url + '" download="' + name + '" class="btn btn-success">下载图片</a></div>'
                            });
                            return;
                        default:
                            window.location.href = url;
                            return;
                    }
                    
                    layer.open({
                        type: 1,
                        title: name + ' - 文件预览',
                        area: [width, height],
                        shadeClose: true,
                        shade: 0.8,
                        content: content
                    });
                } else {
                    layer.alert('错误信息：' + res.msg, { title: '预览出错', icon: 2 });
                }
            }
        });
    } else {
        downloadFile(path);
    }
}

// ========== 文件下载相关 ==========

/**
 * 下载文件
 * @param {string} path - 文件路径
 * 功能：获取下载URL后跳转下载（同步文件会重定向到源节点）
 */
function downloadFile(path) {
    $.ajax({
        url: "Ajax.php?act=getUrl",
        type: "POST",
        data: { dir: path, force_download: true },
        dataType: "json",
        success: function (res) {
            if (res.code == 1) {
                // 直接跳转到下载链接
                window.location.href = res.data.url;
            } else {
                layer.msg('下载失败：' + res.msg, { icon: 2 });
            }
        }
    });
}

// ========== 文件删除相关 ==========

/**
 * 删除文件
 * @param {string} path - 文件路径
 * 功能：删除文件（同步文件只删除元数据，普通文件删除实际文件）
 */
function deleteFile(path) {
    layer.confirm('删除后无法恢复，确定删除吗？', { icon: 0 }, function (index) {
        $.ajax({
            url: "Ajax.php?act=Delfile",
            type: "POST",
            data: { dir: path },
            dataType: "json",
            success: function (res) {
                layer.msg(res.msg, { icon: res.code, time: 1000 });
                if (res.code == 1) {
                    setTimeout(function() {
                        isSearchMode ? searchFiles() : getList('hash', currentDir);
                    }, 1000);
                }
            }
        });
        layer.close(index);
    });
}

// ========== 文件分享相关 ==========

/**
 * 创建分享链接
 * @param {string} filePath - 文件路径
 * @param {string} fileName - 文件名
 * 功能：弹窗设置有效期、密码、下载次数，生成分享链接
 */
function createShareLink(filePath, fileName) {
    layer.open({
        type: 1,
        title: '创建分享链接 - ' + fileName,
        area: ['500px', 'auto'],
        content: '<div style="padding:20px;">' +
            '<div class="form-group">' +
            '<label>有效期（天）：</label>' +
            '<select class="form-control" id="expireDays">' +
            '<option value="1">1天</option>' +
            '<option value="7" selected>7天</option>' +
            '<option value="30">30天</option>' +
            '<option value="365">1年</option>' +
            '</select>' +
            '</div>' +
            '<div class="form-group">' +
            '<label>访问密码（可选）：</label>' +
            '<input type="text" class="form-control" id="sharePassword" placeholder="留空则无需密码">' +
            '</div>' +
            '<div class="form-group">' +
            '<label>最大下载次数（0为不限制）：</label>' +
            '<input type="number" class="form-control" id="maxDownloads" value="0" min="0">' +
            '</div>' +
            '<button class="btn btn-primary btn-block" onclick="submitShare(\'' + filePath + '\')">生成分享链接</button>' +
            '</div>',
        shadeClose: true
    });
}

/**
 * 提交分享设置
 * @param {string} filePath - 文件路径
 * 功能：调用API创建分享链接
 */
function submitShare(filePath) {
    var expireDays = $("#expireDays").val();
    var password = $("#sharePassword").val();
    var maxDownloads = $("#maxDownloads").val();
    
    $.ajax({
        url: "Ajax.php?act=createShare",
        type: "POST",
        data: {
            dir: filePath,
            expire_days: expireDays,
            password: password,
            max_downloads: maxDownloads
        },
        dataType: "json",
        success: function (res) {
            if (res.code == 1) {
                layer.closeAll();
                var msg = '分享链接：<br><input type="text" class="form-control" id="shareUrl" value="' + res.data.url + '" readonly>';
                if (res.data.password) {
                    msg += '<br>访问密码：<strong>' + res.data.password + '</strong>';
                }
                layer.open({
                    type: 1,
                    title: '分享成功',
                    area: ['500px', 'auto'],
                    content: '<div style="padding:20px;">' + msg + '<br><button class="btn btn-success btn-block mt-2" onclick="copyShareUrl()">复制链接</button></div>'
                });
            } else {
                layer.msg(res.msg, { icon: 2 });
            }
        }
    });
}

// 复制分享链接
function copyShareUrl() {
    var shareUrl = $("#shareUrl");
    shareUrl.select();
    document.execCommand("Copy");
    layer.msg('已复制到剪贴板', { icon: 1 });
}

// ========== 存储统计相关 ==========

/**
 * 显示存储统计
 * 功能：获取并展示存储空间使用情况、文件类型分布
 */
function showStorageStats() {
    layer.load(0, { shade: !1 });
    $.ajax({
        url: "Ajax.php?act=getStorageStats",
        type: "POST",
        dataType: "json",
        success: function (res) {
            layer.closeAll("loading");
            if (res.code == 1) {
                renderStorageStats(res.data);
            } else {
                layer.msg('获取统计失败', { icon: 2 });
            }
        }
    });
}

// 渲染存储统计
function renderStorageStats(data) {
    var fileTypesHtml = '';
    var labels = [];
    var sizes = [];
    var colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF'];
    
    var index = 0;
    for (var type in data.file_types) {
        var info = data.file_types[type];
        var size = (info.size / 1024 / 1024).toFixed(2);
        fileTypesHtml += '<tr><td>.' + type + '</td><td>' + info.count + '</td><td>' + size + ' MB</td></tr>';
        labels.push('.' + type);
        sizes.push(size);
        index++;
        if (index >= 8) break;
    }
    
    var content = '<div style="padding:20px;">' +
        '<h5>存储概览</h5>' +
        '<p>总文件数：<strong>' + data.total_files + '</strong></p>' +
        '<p>总大小：<strong>' + data.total_size_formatted + '</strong></p>' +
        '<hr>' +
        '<h5>文件类型分布</h5>' +
        '<div style="width:60%;margin:0 auto;"><canvas id="storageChart" width="300" height="300"></canvas></div>' +
        '<table class="table table-sm mt-3">' +
        '<thead><tr><th>类型</th><th>数量</th><th>大小</th></tr></thead>' +
        '<tbody>' + fileTypesHtml + '</tbody>' +
        '</table>' +
        '</div>';
    
    layer.open({
        type: 1,
        title: '存储空间统计',
        area: ['800px', '750px'],
        content: content,
        success: function() {
            var ctx = document.getElementById('storageChart').getContext('2d');
            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: sizes,
                        backgroundColor: colors
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
    });
}

// ========== 批量操作相关 ==========

/**
 * 切换全选状态
 * 功能：全选或取消全选所有文件
 */
function toggleSelectAll() {
    var checked = $("#selectAll").prop('checked');
    $(".file-checkbox").prop('checked', checked);
    updateSelection();
}

/**
 * 更新选中文件列表
 * 功能：统计已选中的文件数量，显示/隐藏批量操作栏
 */
function updateSelection() {
    selectedFiles = [];
    $(".file-checkbox:checked").each(function() {
        selectedFiles.push($(this).data('file'));
    });
    
    $("#selectedCount").text(selectedFiles.length);
    if (selectedFiles.length > 0) {
        $("#batchBar").show();
    } else {
        $("#batchBar").hide();
    }
}

/**
 * 取消批量选择
 * 功能：清空所有选中状态
 */
function cancelBatch() {
    $(".file-checkbox").prop('checked', false);
    $("#selectAll").prop('checked', false);
    updateSelection();
}

/**
 * 批量删除文件
 * 功能：删除所有选中的文件
 */
function batchDelete() {
    if (selectedFiles.length == 0) {
        layer.msg('请先选择文件', { icon: 2 });
        return;
    }
    
    layer.confirm('确定要删除选中的 ' + selectedFiles.length + ' 个文件吗？', { icon: 0 }, function (index) {
        layer.load(0, { shade: !1 });
        $.ajax({
            url: "Ajax.php?act=batchDelete",
            type: "POST",
            data: { files: selectedFiles },
            dataType: "json",
            success: function (res) {
                layer.closeAll("loading");
                layer.msg(res.msg, { icon: res.code, time: 2000 });
                if (res.code == 1) {
                    cancelBatch();
                    setTimeout(function() {
                        isSearchMode ? searchFiles() : getList('hash', currentDir);
                    }, 2000);
                }
            }
        });
        layer.close(index);
    });
}

/**
 * 批量下载文件
 * 功能：逐个下载所有选中的文件
 */
function batchDownload() {
    if (selectedFiles.length == 0) {
        layer.msg('请先选择文件', { icon: 2 });
        return;
    }
    
    // 如果只有一个文件，直接下载
    if (selectedFiles.length == 1) {
        downloadFile(selectedFiles[0]);
        return;
    }
    
    // 多个文件逐个下载
    layer.confirm('将为您逐个下载 ' + selectedFiles.length + ' 个文件，是否继续？', { icon: 3 }, function(index) {
        layer.close(index);
        var downloadIndex = 0;
        
        function downloadNext() {
            if (downloadIndex < selectedFiles.length) {
                layer.msg('正在下载第 ' + (downloadIndex + 1) + ' 个文件...', { icon: 16, time: 1000 });
                downloadFile(selectedFiles[downloadIndex]);
                downloadIndex++;
                setTimeout(downloadNext, 1500); // 延迟1.5秒下载下一个
            } else {
                layer.msg('所有文件下载完成！', { icon: 1 });
                cancelBatch();
            }
        }
        
        downloadNext();
    });
}

// ========== Hash路由相关 ==========

/**
 * 设置URL Hash
 * @param {string} e - 路径
 * 功能：更新浏览器地址栏Hash，实现前端路由
 */
function setHash(e) {
    location.hash = "/" + e;
    nowHash = e;
}

/**
 * 处理Hash变化
 * 功能：监听URL Hash变化，自动加载对应目录
 */
function doHash() {
    if ("/" == location.hash.substr(-1)) {
        var e = decodeURI(location.hash.replace("#/", ""));
        e != nowHash && getList("hash", e);
    } else getList();
}

// ========== 初始化和登录 ==========

/**
 * 页面初始化
 * 功能：检查系统配置、登录状态、是否安装
 */
$(function () {
    var e = "4.2.2";
    $.ajax({
        url: "Ajax.php?act=getConfig",
        dataType: "json",
        success: function (a) {
            var t = a.data;
            verify = t.verify;
            if (t.install) {
                console.info("欢迎使用 AmoliCloud!\n当前版本：" + e + " \n作者：无名氏Studio(https://wums.cn)\n官网：Amoli私有云(https://www.amoli.co)\nGithub：https://github.com/ChinaMoli/AmoliCloud");
                $("title,.navbar-brand").prepend(t.name);
                $("#record").text(t.record);
                if (t.log) {
                    doHash();
                } else {
                    layer.open({
                        type: 1,
                        title: "请输入查看密码",
                        area: ["350px", "auto"],
                        content: '<div class="container text-right"><br><form onsubmit="return login();"><div class="input-group mb-3"><div class="input-group-prepend"><span class="input-group-text">密码：</span></div><input type="password" class="form-control" id="indexpass"></div><input type="submit" class="btn btn-primary" value="确认"><div><p></p></div></form></div>'
                    });
                }
            } else {
                layer.open({
                    title: "提示",
                    content: "你还没有安装程序，点击确定安装",
                    icon: 2,
                    yes: function () {
                        window.location.href = "install/index.php";
                    }
                });
            }
        },
        error: function () {
            layer.open({
                title: "提示",
                content: "请检查当前服务器的PHP版本<br>PHP版本必须高于5.6！",
                icon: 2
            });
        }
    });
});

/**
 * 用户登录
 * 功能：验证密码，写入Cookie
 * @returns {boolean} false - 阻止表单提交
 */
function login() {
    var e = layer.msg("登录验证中，请稍候", { icon: 16, time: !1, shade: .8 });
    var a = $("#indexpass").val();
    $.ajax({
        url: "Ajax.php?act=login",
        type: "POST",
        dataType: "json",
        data: { indexpass: a },
        success: function (a) {
            setTimeout(function () {
                layer.close(e);
                layer.msg(a.msg, { icon: a.code, time: 1e3 });
                setTimeout(function () {
                    if (a.code == 1) {
                        layer.closeAll();
                        doHash();
                    }
                }, 500);
            }, 500);
        }
    });
    return false;
}


