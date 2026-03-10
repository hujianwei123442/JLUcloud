layui.use(['layer', 'jquery', 'form', 'table'], function() {
	var layer = void 0 === parent.layer ? layui.layer : top.layer;
	var $ = layui.$;
	var form = layui.form;
	var table = layui.table;

	console.log('文件分配管理页面加载');

	// 渲染文件分配表格
	table.render({
		elem: '#fileAssignTable',
		url: '../../ajax.php?act=getFileAssignments',
		method: 'post',
		cols: [[
			{field: 'file_name', title: '文件名称', width: 200},
			{field: 'file_path', title: '文件路径', width: 300},
			{field: 'assigned_to', title: '分配给', width: 120},
			{field: 'permission', title: '权限', width: 100, templet: function(d) {
				return d.permission === 'write' ? '<span class="layui-badge layui-bg-orange">读写</span>' : '<span class="layui-badge layui-bg-blue">只读</span>';
			}},
			{field: 'assigned_by', title: '分配人', width: 120},
			{field: 'create_time', title: '分配时间', width: 180},
			{title: '操作', minWidth: 300, templet: function(d) {
				var buttons = '<a class="layui-btn layui-btn-xs" lay-event="edit">编辑</a>' +
							  '<a class="layui-btn layui-btn-danger layui-btn-xs" lay-event="del">删除</a>';
				var remark = d.remark ? '<span style="margin-left: 15px; color: #999; font-size: 12px;">备注: ' + d.remark + '</span>' : '';
				return buttons + remark;
			}}
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

	// 添加文件分配
	$('#assignFile').click(function() {
		openFileAssignForm();
	});

	// 表格工具栏事件
	table.on('tool(fileAssignTable)', function(obj) {
		var data = obj.data;
		if (obj.event === 'del') {
			layer.confirm('确定删除此文件分配吗？', function(index) {
				$.ajax({
					url: '../../ajax.php?act=deleteFileAssignment',
					type: 'POST',
					data: {id: data.id},
					dataType: 'json',
					success: function(res) {
						layer.msg(res.msg, {icon: res.code});
						if (res.code == 1) {
							obj.del();
							layer.close(index);
						}
					}
				});
			});
		} else if (obj.event === 'edit') {
			openFileAssignForm(data);
		}
	});

	// 打开文件分配表单
	function openFileAssignForm(data) {
		data = data || {};
		var title = data.id ? '编辑文件分配' : '分配文件';
		var isEdit = !!data.id;

		// 先获取用户列表
		$.ajax({
			url: '../../ajax.php?act=getUsers',
			type: 'POST',
			dataType: 'json',
			success: function(res) {
				if (res.code != 1) {
					layer.msg(res.msg, {icon: 2});
					return;
				}

				var users = res.data || [];
				
				// 构建表单HTML
				var formHtml = '<form class="layui-form" lay-filter="fileAssignFormFilter" style="padding: 20px;">' +
					'<input type="hidden" name="id" value="' + (data.id || '') + '">' +
					'<div class="layui-form-item">' +
					'<label class="layui-form-label">文件路径</label>' +
					'<div class="layui-input-block">' +
					'<input type="text" name="file_path" value="' + (data.file_path || '') + '" placeholder="请输入文件路径，如：/upload/document.pdf" lay-verify="required" class="layui-input">' +
					'<div class="layui-form-mid layui-word-aux">相对于网站根目录的路径</div>' +
					'</div>' +
					'</div>' +
					'<div class="layui-form-item">' +
					'<label class="layui-form-label">文件名称</label>' +
					'<div class="layui-input-block">' +
					'<input type="text" name="file_name" value="' + (data.file_name || '') + '" placeholder="请输入文件名称" lay-verify="required" class="layui-input">' +
					'</div>' +
					'</div>' +
					'<div class="layui-form-item">' +
					'<label class="layui-form-label">分配给</label>' +
					'<div class="layui-input-block">' +
					'<select name="assigned_to" lay-verify="required">' +
					'<option value="">请选择用户</option>';

				// 添加用户选项
				users.forEach(function(user) {
					var selected = (data.assigned_to === user.username) ? 'selected' : '';
					formHtml += '<option value="' + user.username + '" ' + selected + '>' + user.username + '</option>';
				});

				formHtml += '</select>' +
					'</div>' +
					'</div>' +
					'<div class="layui-form-item">' +
					'<label class="layui-form-label">权限</label>' +
					'<div class="layui-input-block">' +
					'<select name="permission" lay-verify="required">' +
					'<option value="">请选择权限</option>' +
					'<option value="read" ' + ((data.permission === 'read' || !data.permission) ? 'selected' : '') + '>只读</option>' +
					'<option value="write" ' + (data.permission === 'write' ? 'selected' : '') + '>读写</option>' +
					'</select>' +
					'</div>' +
					'</div>' +
					'<div class="layui-form-item layui-form-text">' +
					'<label class="layui-form-label">备注</label>' +
					'<div class="layui-input-block">' +
					'<textarea name="remark" placeholder="请输入备注信息" class="layui-textarea">' + (data.remark || '') + '</textarea>' +
					'</div>' +
					'</div>' +
					'<div class="layui-form-item" style="text-align: right;">' +
					'<button class="layui-btn" lay-submit lay-filter="submitFileAssign">确定</button>' +
					'<button type="button" class="layui-btn layui-btn-primary" onclick="layer.closeAll()">取消</button>' +
					'</div>' +
					'</form>';

				layer.open({
					type: 1,
					title: title,
					area: ['600px', '550px'],
					content: formHtml,
					success: function(layero, index) {
						var $form = layero.find('form');

						// 渲染表单
						form.render();

						console.log('表单初始化完成');
						console.log('分配给 Select选项数量:', $form.find('select[name="assigned_to"] option').length);
						console.log('权限 Select选项数量:', $form.find('select[name="permission"] option').length);
						console.log('Layui select容器数量:', layero.find('.layui-form-select').length);

						// 如果 Layui 渲染失败，使用原生 select
						setTimeout(function() {
							if (layero.find('.layui-form-select').length === 0) {
								console.warn('Layui select 渲染失败，使用原生 select');
								$form.find('select').each(function() {
									$(this).show().css({
										'display': 'block',
										'width': '100%',
										'height': '38px',
										'line-height': '1.3',
										'border': '1px solid #e6e6e6',
										'background-color': '#fff',
										'border-radius': '2px',
										'padding': '0 10px'
									});
								});
							}
						}, 200);

						// 表单提交 - Layui 方式
						form.on('submit(submitFileAssign)', function(formData) {
							console.log('Layui 表单提交事件触发');
							var apiUrl = isEdit ? '../../ajax.php?act=editFileAssignment' : '../../ajax.php?act=assignFile';
							
							$.ajax({
								url: apiUrl,
								type: 'POST',
								data: formData.field,
								dataType: 'json',
								success: function(res) {
									console.log('提交响应:', res);
									layer.msg(res.msg, {icon: res.code});
									if (res.code == 1) {
										layer.close(index);
										table.reload('fileAssignTable');
									}
								},
								error: function(xhr) {
									console.error('提交失败:', xhr);
									layer.msg('操作失败，请重试', {icon: 2});
								}
							});
							return false;
						});

						// 表单提交 - jQuery 方式（备用）
						$form.on('submit', function(e) {
							e.preventDefault();
							console.log('jQuery 表单提交事件触发');

							var formData = {};
							$form.find('input[name], select[name], textarea[name]').each(function() {
								var name = $(this).attr('name');
								var value = $(this).val();
								if (name) {
									formData[name] = value;
								}
							});

							console.log('收集的表单数据:', formData);

							// 验证必填字段
							if (!formData.file_path) {
								layer.msg('文件路径不能为空', {icon: 2});
								return false;
							}
							if (!formData.file_name) {
								layer.msg('文件名称不能为空', {icon: 2});
								return false;
							}
							if (!formData.assigned_to) {
								layer.msg('请选择分配给的用户', {icon: 2});
								return false;
							}
							if (!formData.permission) {
								layer.msg('请选择权限', {icon: 2});
								return false;
							}

							var apiUrl = isEdit ? '../../ajax.php?act=editFileAssignment' : '../../ajax.php?act=assignFile';
							console.log('提交到:', apiUrl);

							$.ajax({
								url: apiUrl,
								type: 'POST',
								data: formData,
								dataType: 'json',
								success: function(res) {
									console.log('提交响应:', res);
									layer.msg(res.msg, {icon: res.code});
									if (res.code == 1) {
										layer.close(index);
										table.reload('fileAssignTable');
									}
								},
								error: function(xhr, status, error) {
									console.error('提交失败:', xhr.responseText);
									console.error('状态:', status, '错误:', error);
									layer.msg('操作失败，请重试', {icon: 2});
								}
							});

							return false;
						});
					}
				});
			},
			error: function() {
				layer.msg('获取用户列表失败', {icon: 2});
			}
		});
	}

	console.log('文件分配管理页面JavaScript已加载');
});

