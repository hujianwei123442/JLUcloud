/**
 * NodeSync.js - 节点同步管理逻辑
 * 
 * 功能：管理分布式节点，实现节点间数据同步
 * 主要功能：
 * - 节点管理（添加、编辑、删除、测试）
 * - 单节点同步和全部同步
 * - 自动同步（定时任务）
 * - 同步日志查看
 * - 同步设置配置
 */

layui.use(['form', 'layer', 'element'], function(){
    var form = layui.form;
    var layer = layui.layer;
    var element = layui.element;
    var $ = layui.$;

    var autoSyncTimer = null;      // 自动同步定时器
    var autoSyncEnabled = false;   // 自动同步是否启用

    // ========== 节点列表相关 ==========

    /**
     * 加载节点列表
     * 功能：从服务器获取所有节点信息并渲染
     */
    function loadNodes() {
        $.ajax({
            url: '../../ajax.php?act=getNodeList',
            type: 'GET',
            dataType: 'json',
            success: function(res) {
                if (res.code == 0 || res.code == 1) {
                    renderNodes(res.data || []);
                    updateStats(res.data || []);
                } else {
                    layer.msg('加载失败：' + res.msg, {icon: 2});
                }
            },
            error: function() {
                layer.msg('网络错误', {icon: 2});
            }
        });
    }

    /**
     * 渲染节点列表到页面
     * @param {array} nodes - 节点数据数组
     * 功能：生成节点卡片HTML并显示
     */
    function renderNodes(nodes) {
        var html = '';
        if (nodes.length == 0) {
            html = '<div class="layui-text-center" style="padding: 50px;">暂无节点，请点击"添加节点"按钮添加</div>';
        } else {
            nodes.forEach(function(node) {
                var statusClass = node.status == 1 ? 'online' : 'offline';
                var statusText = node.status == 1 ? '<span class="layui-badge layui-bg-green">在线</span>' : '<span class="layui-badge layui-bg-gray">离线</span>';
                var syncStatus = node.syncing ? '<span class="layui-badge layui-bg-orange">同步中</span>' : '';
                
                html += `
                    <div class="node-card ${statusClass}">
                        <div class="node-info">
                            <div>
                                <h3 style="margin: 0 0 10px 0;">
                                    <i class="layui-icon layui-icon-service" style="font-size: 20px;"></i>
                                    ${node.name}
                                    ${statusText}
                                    ${syncStatus}
                                </h3>
                                <p style="margin: 5px 0; color: #666;">
                                    <i class="layui-icon layui-icon-link"></i> ${node.url}
                                </p>
                                <p style="margin: 5px 0; color: #999; font-size: 12px;">
                                    <i class="layui-icon layui-icon-location"></i> ${node.location || '未设置位置'}
                                    &nbsp;&nbsp;
                                    <i class="layui-icon layui-icon-time"></i> 最后同步：${node.last_sync || '从未同步'}
                                </p>
                                ${node.remark ? '<p style="margin: 5px 0; color: #999; font-size: 12px;">' + node.remark + '</p>' : ''}
                            </div>
                            <div>
                                <button class="layui-btn layui-btn-xs layui-btn-normal" onclick="testNode('${node.id}')">
                                    <i class="layui-icon layui-icon-release"></i> 测试
                                </button>
                                <button class="layui-btn layui-btn-xs" onclick="syncNode('${node.id}')">
                                    <i class="layui-icon">&#xe9c6;</i> 同步
                                </button>
                                <button class="layui-btn layui-btn-xs layui-btn-warm" onclick="editNode('${node.id}')">
                                    <i class="layui-icon layui-icon-edit"></i> 编辑
                                </button>
                                <button class="layui-btn layui-btn-xs layui-btn-danger" onclick="deleteNode('${node.id}')">
                                    <i class="layui-icon layui-icon-delete"></i> 删除
                                </button>
                            </div>
                        </div>
                        ${node.syncing ? '<div class="sync-progress"><div class="layui-progress layui-progress-big" lay-showpercent="true"><div class="layui-progress-bar" lay-percent="' + (node.sync_progress || 0) + '%"></div></div></div>' : ''}
                    </div>
                `;
            });
        }
        $('#nodeList').html(html);
        element.render('progress');
    }

    /**
     * 更新统计数据
     * @param {array} nodes - 节点数据数组
     * 功能：计算总节点数、在线节点数、同步中节点数、最后同步时间
     */
    function updateStats(nodes) {
        var total = nodes.length;
        var online = 0;
        var syncing = 0;
        var lastSync = null;
        
        nodes.forEach(function(node) {
            if (node.status == 1) online++;
            if (node.syncing) syncing++;
            if (node.last_sync && node.last_sync != '从未同步') {
                if (!lastSync || node.last_sync > lastSync) {
                    lastSync = node.last_sync;
                }
            }
        });
        
        $('#totalNodes').text(total);
        $('#onlineNodes').text(online);
        $('#syncingNodes').text(syncing);
        if (lastSync) {
            // 显示时间部分，例如：2024-03-09 14:30:25 -> 14:30
            $('#lastSyncTime').text(lastSync.substring(11, 16));
        } else {
            $('#lastSyncTime').text('未同步');
        }
    }

    // 自定义URL验证规则
    form.verify({
        nodeUrl: function(value){
            if(!value) return '节点地址不能为空';
            // 允许 http:// 或 https:// 开头，支持 localhost、IP地址、域名
            var urlPattern = /^https?:\/\/(localhost|[\w\-]+(\.[\w\-]+)*|\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})(:\d+)?(\/.*)?$/i;
            if(!urlPattern.test(value)){
                return '节点地址格式不正确，例如：http://localhost:8002 或 http://192.168.1.100';
            }
        }
    });

    // ========== 节点操作相关 ==========

    /**
     * 添加节点
     * 功能：弹窗输入节点信息，提交到服务器
     */
    $('#addNode').on('click', function(){
        layer.open({
            type: 1,
            title: '添加节点',
            area: ['600px', '550px'],
            content: `
                <form class="layui-form" style="padding: 20px;" lay-filter="nodeForm">
                    <div class="layui-form-item">
                        <label class="layui-form-label">节点名称</label>
                        <div class="layui-input-block">
                            <input type="text" name="name" required lay-verify="required" placeholder="例如：办公室节点" class="layui-input">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">节点地址</label>
                        <div class="layui-input-block">
                            <input type="text" name="url" required lay-verify="required|nodeUrl" placeholder="http://localhost:8002 或 http://192.168.1.100" class="layui-input">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">位置描述</label>
                        <div class="layui-input-block">
                            <input type="text" name="location" placeholder="例如：公司办公室、家里寝室" class="layui-input">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">存储类型</label>
                        <div class="layui-input-block">
                            <select name="type" lay-verify="required">
                                <option value="">请选择</option>
                                <option value="local">本地存储</option>
                                <option value="oss">阿里云OSS</option>
                                <option value="cos">腾讯云COS</option>
                            </select>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">访问密钥</label>
                        <div class="layui-input-block">
                            <input type="text" name="access_key" placeholder="用于节点间认证（可选）" class="layui-input">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">密钥Secret</label>
                        <div class="layui-input-block">
                            <input type="password" name="secret_key" placeholder="用于节点间认证（可选）" class="layui-input">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label">备注</label>
                        <div class="layui-input-block">
                            <textarea name="remark" placeholder="节点说明" class="layui-textarea"></textarea>
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <div class="layui-input-block">
                            <button class="layui-btn" lay-submit lay-filter="submitNode">立即添加</button>
                            <button type="reset" class="layui-btn layui-btn-primary">重置</button>
                        </div>
                    </div>
                </form>
            `,
            success: function(layero, index){
                form.render();
                
                form.on('submit(submitNode)', function(data){
                    var loadIndex = layer.load(1);
                    $.post('../../ajax.php?act=addNode', data.field, function(res){
                        layer.close(loadIndex);
                        if(res.code == 1){
                            layer.msg(res.msg, {icon: 1});
                            layer.close(index);
                            loadNodes();
                        } else {
                            layer.msg(res.msg, {icon: 2});
                        }
                    }, 'json');
                    return false;
                });
            }
        });
    });

    /**
     * 测试节点连接
     * @param {string} id - 节点ID
     * 功能：测试节点是否在线可访问
     */
    window.testNode = function(id) {
        var loadIndex = layer.load(1, {shade: 0.3});
        $.post('../../ajax.php?act=testNode', {id: id}, function(res){
            layer.close(loadIndex);
            if(res.code == 1){
                layer.msg('连接成功！节点在线', {icon: 1});
                loadNodes();
            } else {
                layer.msg('连接失败：' + res.msg, {icon: 2});
            }
        }, 'json');
    };

    /**
     * 同步单个节点
     * @param {string} id - 节点ID
     * 功能：执行Pull+Push双向同步
     */
    window.syncNode = function(id) {
        layer.confirm('确定要同步该节点吗？', {icon: 3}, function(index){
            layer.close(index);
            var loadIndex = layer.load(1, {shade: 0.3});
            $.post('../../ajax.php?act=syncSingleNode', {id: id}, function(res){
                layer.close(loadIndex);
                if(res.code == 1){
                    layer.msg(res.msg, {icon: 1});
                    loadNodes();
                    loadSyncLogs();
                } else {
                    layer.msg(res.msg, {icon: 2});
                }
            }, 'json');
        });
    };

    /**
     * 编辑节点
     * @param {string} id - 节点ID
     * 功能：弹窗修改节点信息
     */
    window.editNode = function(id) {
        $.get('../../ajax.php?act=getNodeDetail', {id: id}, function(res){
            if(res.code == 1){
                var node = res.data;
                layer.open({
                    type: 1,
                    title: '编辑节点',
                    area: ['600px', '500px'],
                    content: `
                        <form class="layui-form" style="padding: 20px;" lay-filter="editNodeForm">
                            <input type="hidden" name="id" value="${node.id}">
                            <div class="layui-form-item">
                                <label class="layui-form-label">节点名称</label>
                                <div class="layui-input-block">
                                    <input type="text" name="name" value="${node.name}" required lay-verify="required" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">节点地址</label>
                                <div class="layui-input-block">
                                    <input type="text" name="url" value="${node.url}" required lay-verify="required|nodeUrl" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">位置描述</label>
                                <div class="layui-input-block">
                                    <input type="text" name="location" value="${node.location || ''}" class="layui-input">
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <label class="layui-form-label">备注</label>
                                <div class="layui-input-block">
                                    <textarea name="remark" class="layui-textarea">${node.remark || ''}</textarea>
                                </div>
                            </div>
                            <div class="layui-form-item">
                                <div class="layui-input-block">
                                    <button class="layui-btn" lay-submit lay-filter="submitEditNode">保存修改</button>
                                </div>
                            </div>
                        </form>
                    `,
                    success: function(layero, index){
                        form.render();
                        
                        form.on('submit(submitEditNode)', function(formData){
                            var loadIndex = layer.load(1);
                            $.post('../../ajax.php?act=updateNode', formData.field, function(res){
                                layer.close(loadIndex);
                                if(res.code == 1){
                                    layer.msg(res.msg, {icon: 1});
                                    layer.close(index);
                                    loadNodes();
                                } else {
                                    layer.msg(res.msg, {icon: 2});
                                }
                            }, 'json');
                            return false;
                        });
                    }
                });
            }
        }, 'json');
    };

    /**
     * 删除节点
     * @param {string} id - 节点ID
     * 功能：删除节点配置（不影响实际文件）
     */
    window.deleteNode = function(id) {
        layer.confirm('确定删除该节点吗？删除后无法恢复！', {icon: 0}, function(index){
            var loadIndex = layer.load(1);
            $.post('../../ajax.php?act=deleteNode', {id: id}, function(res){
                layer.close(loadIndex);
                if(res.code == 1){
                    layer.msg(res.msg, {icon: 1});
                    layer.close(index);
                    loadNodes();
                } else {
                    layer.msg(res.msg, {icon: 2});
                }
            }, 'json');
        });
    };

    // ========== 批量同步相关 ==========

    /**
     * 全部同步
     * 功能：同步所有节点
     */
    $('#syncAll').on('click', function(){
        layer.confirm('确定要同步所有节点吗？这可能需要一些时间。', {icon: 3}, function(index){
            layer.close(index);
            var loadIndex = layer.load(1, {shade: 0.3});
            $.post('../../ajax.php?act=syncAllNodes', {}, function(res){
                layer.close(loadIndex);
                if(res.code == 1){
                    layer.msg(res.msg, {icon: 1, time: 2000});
                    loadNodes();
                    loadSyncLogs();
                } else {
                    layer.msg(res.msg, {icon: 2});
                }
            }, 'json');
        });
    });

    /**
     * 自动同步
     * 功能：启动/停止定时自动同步（默认15分钟间隔）
     */
    $('#autoSync').on('click', function(){
        if(autoSyncEnabled){
            clearInterval(autoSyncTimer);
            autoSyncEnabled = false;
            $(this).html('<i class="layui-icon">&#xe60e;</i> 自动同步');
            $(this).removeClass('layui-btn-danger').addClass('layui-btn-warm');
            layer.msg('已停止自动同步', {icon: 1});
        } else {
            var interval = 15 * 60 * 1000; // 默认15分钟
            autoSyncTimer = setInterval(function(){
                $.post('../../ajax.php?act=syncAllNodes', {}, function(res){
                    if(res.code == 1){
                        loadNodes();
                        loadSyncLogs();
                    }
                }, 'json');
            }, interval);
            autoSyncEnabled = true;
            $(this).html('<i class="layui-icon">&#xe60e;</i> 停止自动同步');
            $(this).removeClass('layui-btn-warm').addClass('layui-btn-danger');
            layer.msg('已启动自动同步', {icon: 1});
        }
    });

    /**
     * 刷新节点列表
     * 功能：重新加载节点和日志
     */
    $('#refreshNodes').on('click', function(){
        loadNodes();
        loadSyncLogs();
        layer.msg('已刷新', {icon: 1});
    });

    // ========== 同步日志相关 ==========

    /**
     * 加载同步日志
     * 功能：获取最近的同步记录并显示
     */
    function loadSyncLogs() {
        $.get('../../ajax.php?act=getSyncLogs', function(res){
            if(res.code == 0 || res.code == 1){
                var logs = res.data || [];
                var html = '';
                if(logs.length == 0){
                    html = '<tr><td colspan="4" class="layui-text-center">暂无日志</td></tr>';
                } else {
                    logs.slice(0, 10).forEach(function(log){
                        var statusHtml = '';
                        if(log.status == 'success'){
                            statusHtml = '<span class="layui-badge layui-bg-green">成功</span>';
                        } else if(log.status == 'running'){
                            statusHtml = '<span class="layui-badge layui-bg-blue">进行中</span>';
                        } else {
                            statusHtml = '<span class="layui-badge layui-bg-red">失败</span>';
                        }
                        html += `
                            <tr>
                                <td>${log.start_time.substring(11, 19)}</td>
                                <td>${log.node_name}</td>
                                <td>${statusHtml}</td>
                                <td>${log.message}</td>
                            </tr>
                        `;
                    });
                }
                $('#syncLogs').html(html);
            }
        }, 'json');
    }

    // ========== 同步设置相关 ==========

    /**
     * 保存同步设置
     * 功能：保存同步内容、间隔等配置
     */
    form.on('submit(saveSyncSettings)', function(data){
        var loadIndex = layer.load(1);
        $.post('../../ajax.php?act=saveSyncSettings', data.field, function(res){
            layer.close(loadIndex);
            if(res.code == 1){
                layer.msg('保存成功！', {icon: 1});
            } else {
                layer.msg('保存失败：' + res.msg, {icon: 2});
            }
        }, 'json');
        return false;
    });

    /**
     * 加载同步设置
     * 功能：从服务器获取已保存的同步配置
     */
    function loadSyncSettings() {
        $.get('../../ajax.php?act=getSyncSettings', function(res){
            if(res.code == 1 && res.data){
                form.val('syncSettings', res.data);
            }
        }, 'json');
    }

    // ========== 初始化 ==========

    // 页面加载时执行
    loadNodes();           // 加载节点列表
    loadSyncLogs();        // 加载同步日志
    loadSyncSettings();    // 加载同步设置
    
    // 定时刷新节点状态（30秒一次）
    setInterval(function(){
        loadNodes();
    }, 30000); // 30秒刷新一次
});
