layui.use(['layer', 'jquery', 'form', 'table'], function() {
	var layer = void 0 === parent.layer ? layui.layer : top.layer;
	var $ = layui.$;
	var form = layui.form;
	var table = layui.table;

	// 全局变量存储当前用户信息
	var currentUserInfo = null;

	// 检查用户权限
	console.log('=== 开始检查管理员权限 ===');
	$.ajax({
		url: '../../ajax.php?act=getCurrentUser',
		type: 'POST',
		dataType: 'json',
		async: false, // 同步请求，确保获取到权限后再渲染页面
		success: function(res) {
			console.log('getCurrentUser 响应:', res);
			console.log('响应代码:', res.code);
			console.log('响应数据:', res.data);
			if (res.code == 1) {
				currentUserInfo = res.data;
				console.log('用户名:', currentUserInfo.username);
				console.log('用户角色:', currentUserInfo.role);
				console.log('是否管理员:', currentUserInfo.isAdmin);
				console.log('isAdmin 类型:', typeof currentUserInfo.isAdmin);
				console.log('isAdmin === true:', currentUserInfo.isAdmin === true);
				console.log('isAdmin == true:', currentUserInfo.isAdmin == true);
			} else {
				console.error('获取用户信息失败:', res.msg);
				layer.msg('获取用户信息失败: ' + res.msg, {icon: 2});
			}
		},
		error: function(xhr, status, error) {
			console.error('AJAX请求失败:', status, error);
			console.error('响应文本:', xhr.responseText);
			layer.msg('获取用户信息失败', {icon: 2});
		}
	});

	console.log('=== 权限检查结果 ===');
	console.log('currentUserInfo:', currentUserInfo);
	console.log('!currentUserInfo:', !currentUserInfo);
	console.log('!currentUserInfo.isAdmin:', currentUserInfo ? !currentUserInfo.isAdmin : 'currentUserInfo为null');
	console.log('最终判断 - 是否阻止访问:', !currentUserInfo || !currentUserInfo.isAdmin);

	// 如果不是管理员，直接提示并阻止加载
	if (!currentUserInfo || !currentUserInfo.isAdmin) {
		console.log('=== 阻止普通用户访问 ===');
		$('#addUser').hide();
		$('.layui-card-body').html('<div style="text-align: center; padding: 50px; color: #999;"><i class="layui-icon layui-icon-tips" style="font-size: 50px;"></i><p style="font-size: 16px; margin-top: 20px;">您没有权限访问此页面</p><p style="font-size: 14px;">只有管理员可以管理用户</p></div>');
		layer.msg('权限不足，只有管理员可以管理用户', {icon: 0, time: 3000});
		return; // 直接返回，不加载表格
	}

	console.log('=== 管理员权限验证通过，开始加载表格 ===');

	// 渲染用户表格（只有管理员才会执行到这里）
	table.render({
		elem: '#userTable',
		url: '../../ajax.php?act=getUsers',
		method: 'post',
		cols: [[
			{field: 'username', title: '用户名', width: 150},
			{field: 'role', title: '角色', width: 100, templet: function(d) {
				return d.role === 'admin' ? '<span class="layui-badge layui-bg-blue">管理员</span>' : '<span class="layui-badge">普通用户</span>';
			}},
			{field: 'create_time', title: '创建时间', width: 180},
			{fixed: 'right', title: '操作', toolbar: '#userBar', width: 120}
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

	// 添加用户
	$('#addUser').click(function() {
		openUserForm();
	});

	// 表格工具栏事件
	table.on('tool(userTable)', function(obj) {
		var data = obj.data;
		if (obj.event === 'del') {
			layer.confirm('确定删除用户 ' + data.username + ' 吗？删除后无法恢复！', {
				icon: 3,
				title: '删除确认'
			}, function(index) {
				// 删除用户
				$.ajax({
					url: '../../ajax.php?act=deleteUser',
					type: 'POST',
					data: {username: data.username},
					dataType: 'json',
					success: function(res) {
						console.log('删除响应:', res);
						layer.msg(res.msg, {icon: res.code});
						if (res.code == 1) {
							obj.del(); // 删除表格行
							layer.close(index);
						}
					},
					error: function(xhr, status, error) {
						console.error('删除请求失败:', xhr.responseText);
						console.error('状态:', status, '错误:', error);
						layer.msg('删除失败，请重试', {icon: 2});
					}
				});
			});
		} else if (obj.event === 'edit') {
			openUserForm(data);
		}
	});

	// 打开用户表单
	function openUserForm(data) {
		data = data || {};
		var title = data.username ? '编辑用户' : '添加用户';
		var isEdit = !!data.username;
		var roleValue = data.role || 'user';

		// 构建表单HTML
		var formHtml = '<form class="layui-form" lay-filter="userFormFilter" style="padding: 20px;">' +
			'<input type="hidden" name="userId" id="userId">' +
			'<div class="layui-form-item">' +
			'<label class="layui-form-label">用户名</label>' +
			'<div class="layui-input-block">' +
			'<input type="text" name="username" value="' + (data.username || '') + '" placeholder="请输入用户名" lay-verify="required" class="layui-input" ' + (isEdit ? 'readonly' : '') + '>' +
			'</div>' +
			'</div>' +
			'<div class="layui-form-item">' +
			'<label class="layui-form-label">密码</label>' +
			'<div class="layui-input-block">' +
			'<input type="password" name="password" placeholder="' + (isEdit ? '留空则不修改密码' : '请输入密码') + '" ' + (isEdit ? '' : 'lay-verify="required"') + ' class="layui-input">' +
			'</div>' +
			'</div>' +
			'<div class="layui-form-item">' +
			'<label class="layui-form-label">角色</label>' +
			'<div class="layui-input-block">' +
			'<select name="role" lay-verify="required">' +
			'<option value="">请选择角色</option>' +
			'<option value="user" ' + (roleValue === 'user' ? 'selected' : '') + '>普通用户</option>' +
			'<option value="admin" ' + (roleValue === 'admin' ? 'selected' : '') + '>管理员</option>' +
			'</select>' +
			'</div>' +
			'</div>' +
			'<div class="layui-form-item" style="text-align: right;">' +
			'<button class="layui-btn" lay-submit lay-filter="submitUser">确定</button>' +
			'<button type="button" class="layui-btn layui-btn-primary" onclick="layer.closeAll()">取消</button>' +
			'</div>' +
			'</form>';

		layer.open({
			type: 1,
			title: title,
			area: ['500px', '400px'],
			content: formHtml,
			success: function(layero, index) {
				var $form = layero.find('form');

				// 延迟渲染，确保DOM完全加载
				setTimeout(function() {
					// 重新渲染表单
					form.render(null, 'userFormFilter');
					
					console.log('表单初始化完成');
					console.log('Select选项数量:', $form.find('select[name="role"] option').length);
					console.log('Select当前值:', $form.find('select[name="role"]').val());
					console.log('Layui select容器:', layero.find('.layui-form-select').length);
					console.log('原始select是否隐藏:', $form.find('select[name="role"]').is(':hidden'));
					
					// 如果 Layui 渲染失败，使用原生 select
					if (layero.find('.layui-form-select').length === 0) {
						console.warn('Layui select 渲染失败，使用原生 select');
						var $select = $form.find('select[name="role"]');
						$select.show().css({
							'display': 'block',
							'width': '100%',
							'height': '38px',
							'line-height': '1.3',
							'border': '1px solid #e6e6e6',
							'background-color': '#fff',
							'border-radius': '2px',
							'padding': '0 10px'
						});
					}
				}, 100);

				// 使用jQuery绑定表单提交事件
				$form.on('submit', function(e) {
					e.preventDefault();
					console.log('jQuery表单提交事件触发');

					var formData = {};
					$form.find('input[name], select[name]').each(function() {
						var name = $(this).attr('name');
						var value = $(this).val();
						formData[name] = value;
					});

					console.log('收集的表单数据:', formData);
					console.log('是否编辑模式:', isEdit);

					// 检查必填字段
					if (!formData.username) {
						layer.msg('用户名不能为空', {icon: 2});
						return false;
					}

					// 添加用户时密码必填，编辑时密码可选
					if (!isEdit && !formData.password) {
						layer.msg('密码不能为空', {icon: 2});
						return false;
					}

					if (!formData.role) {
						formData.role = 'user'; // 设置默认值
					}

					// 如果是编辑模式且密码为空，删除密码字段（不修改密码）
					if (isEdit && !formData.password) {
						delete formData.password;
					}

					console.log('准备发送数据:', formData);

					// 根据是否编辑选择不同的接口
					var apiUrl = isEdit ? '../../ajax.php?act=editUser' : '../../ajax.php?act=register';
					var actionText = isEdit ? '修改' : '添加';

					$.ajax({
						url: apiUrl,
						type: 'POST',
						data: formData,
						dataType: 'json',
						success: function(res) {
							console.log(actionText + '响应:', res);
							layer.msg(res.msg, {icon: res.code});
							if (res.code == 1) {
								layer.close(index);
								table.reload('userTable');
							}
						},
						error: function(xhr, status, error) {
							console.error(actionText + '请求失败:', xhr.responseText);
							console.error('状态:', status, '错误:', error);
							layer.msg('操作失败，请重试', {icon: 2});
						}
					});

					return false;
				});

				// 同时保留Layui的事件绑定作为备用
				form.on('submit(submitUser)', function(formData) {
					console.log('Layui表单提交事件也触发了:', formData.field);
					return false;
				});
			}
		});
	}

	// 页面加载完成后的测试
	setTimeout(function() {
		console.log('用户管理页面完全加载');
		console.log('表格元素存在:', $('#userTable').length > 0);
		console.log('添加用户按钮存在:', $('#addUser').length > 0);
	}, 1000);

	console.log('用户管理页面JavaScript已加载');
});
