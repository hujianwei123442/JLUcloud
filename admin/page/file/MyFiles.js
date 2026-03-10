layui.use(['layer', 'jquery', 'table'], function() {
	var layer = void 0 === parent.layer ? layui.layer : top.layer;
	var $ = layui.$;
	var table = layui.table;

	console.log('我的文件页面加载');

	// 渲染我的文件表格
	table.render({
		elem: '#myFilesTable',
		url: '../../ajax.php?act=getMyFiles',
		method: 'post',
		cols: [[
			{field: 'file_name', title: '文件名称', width: 200},
			{field: 'file_path', title: '文件路径', width: 300},
			{field: 'permission', title: '权限', width: 100, templet: function(d) {
				return d.permission === 'write' ? '<span class="layui-badge layui-bg-orange">读写</span>' : '<span class="layui-badge layui-bg-blue">只读</span>';
			}},
			{field: 'assigned_by', title: '分配人', width: 120},
			{field: 'remark', title: '备注', width: 200},
			{field: 'create_time', title: '分配时间', width: 180},
			{fixed: 'right', title: '操作', toolbar: '#myFilesBar', width: 150}
		]],
		page: true,
		response: {
			statusCode: 1
		},
		parseData: function(res) {
			return {
				"code": res.code,
				"msg": res.msg,
				"count": res.data ? res.data.length : 0,
				"data": res.data || []
			};
		}
	});

	// 表格工具栏事件
	table.on('tool(myFilesTable)', function(obj) {
		var data = obj.data;
		if (obj.event === 'download') {
			// 下载文件
			$.ajax({
				url: '../../ajax.php?act=downloadAssignedFile',
				type: 'POST',
				data: {file_path: data.file_path},
				dataType: 'json',
				success: function(res) {
					if (res.code == 1) {
						window.location.href = res.data.url;
					} else {
						layer.msg(res.msg, {icon: 2});
					}
				},
				error: function() {
					layer.msg('下载失败', {icon: 2});
				}
			});
		} else if (obj.event === 'view') {
			// 查看文件详情
			var content = '<div style="padding: 20px;">' +
				'<p><strong>文件名称：</strong>' + data.file_name + '</p>' +
				'<p><strong>文件路径：</strong>' + data.file_path + '</p>' +
				'<p><strong>权限：</strong>' + (data.permission === 'write' ? '读写' : '只读') + '</p>' +
				'<p><strong>分配人：</strong>' + data.assigned_by + '</p>' +
				'<p><strong>分配时间：</strong>' + data.create_time + '</p>' +
				'<p><strong>备注：</strong>' + (data.remark || '无') + '</p>' +
				'</div>';
			
			layer.open({
				type: 1,
				title: '文件详情',
				area: ['500px', '400px'],
				content: content
			});
		}
	});

	console.log('我的文件页面JavaScript已加载');
});


































