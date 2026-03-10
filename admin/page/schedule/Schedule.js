
layui.use(['layer', 'jquery', 'form', 'laydate'], function() {
	var layer = void 0 === parent.layer ? layui.layer : top.layer;
	var $ = layui.$;
	var form = layui.form;
	var laydate = layui.laydate;
	var currentFilter = 'all';

	// 初始化日期选择器
	laydate.render({
		elem: '#scheduleDate',
		type: 'date',
		value: new Date()
	});

	// 加载日程列表
	function loadScheduleList(filter) {
		currentFilter = filter;
		console.log('开始加载日程列表，过滤条件:', filter);
		$('#scheduleList').html('<div class="layui-loading" style="text-align: center; padding: 50px;"><i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop"></i></div>');

		$.ajax({
			url: '../../ajax.php?act=scheduleList',
			type: 'POST',
			data: { filter: filter },
			dataType: 'json',
			success: function(res) {
				console.log('日程列表加载响应:', res);
				if (res.code == 1) {
					console.log('渲染日程列表，数据条数:', res.data ? res.data.length : 0);
					renderScheduleList(res.data);
				} else {
					console.log('日程列表加载失败:', res.msg);
					layer.msg(res.msg, { icon: 2 });
					$('#scheduleList').html('<div style="text-align: center; padding: 50px; color: #999;">暂无日程</div>');
				}
			},
			error: function(xhr, status, error) {
				console.log('日程列表加载AJAX错误:');
				console.log('- 状态码:', xhr.status);
				console.log('- 响应文本:', xhr.responseText);
				console.log('- 错误:', error);
				layer.msg('加载失败，请重试', { icon: 2 });
				$('#scheduleList').html('<div style="text-align: center; padding: 50px; color: #999;">加载失败</div>');
			}
		});
	}

	// 渲染日程列表
	function renderScheduleList(schedules) {
		if (schedules.length == 0) {
			$('#scheduleList').html('<div style="text-align: center; padding: 50px; color: #999;">暂无日程</div>');
			return;
		}

		var html = '';
		schedules.forEach(function(schedule) {
			var typeClass = schedule.type || 'normal';
			var statusClass = schedule.status == 'completed' ? 'completed' : '';
			var statusText = schedule.status == 'completed' ? '已完成' : '待完成';
			var statusIcon = schedule.status == 'completed' ? '&#xe605;' : '&#xe63f;';
			
			html += '<div class="schedule-item ' + typeClass + ' ' + statusClass + '">';
			html += '<div class="schedule-title">' + schedule.title + '</div>';
			if (schedule.content) {
				html += '<div style="color: #666; margin: 8px 0;">' + schedule.content + '</div>';
			}
			html += '<div class="schedule-meta">';
			html += '<span><i class="layui-icon">&#xe637;</i> ' + schedule.date + '</span>';
			if (schedule.time) {
				html += '<span style="margin-left: 15px;"><i class="layui-icon">&#xe60e;</i> ' + schedule.time + '</span>';
			}
			html += '<span style="margin-left: 15px;"><i class="layui-icon">' + statusIcon + '</i> ' + statusText + '</span>';
			html += '</div>';
			html += '<div style="margin-top: 10px; text-align: right;">';
			if (schedule.status != 'completed') {
				html += '<button class="layui-btn layui-btn-xs layui-btn-normal complete-btn" data-id="' + schedule.id + '">标记完成</button> ';
			} else {
				html += '<button class="layui-btn layui-btn-xs layui-btn-primary complete-btn" data-id="' + schedule.id + '">标记未完成</button> ';
			}
			html += '<button class="layui-btn layui-btn-xs edit-btn" data-id="' + schedule.id + '" data-title="' + schedule.title.replace(/"/g, '&quot;') + '" data-content="' + (schedule.content || '').replace(/"/g, '&quot;') + '" data-date="' + schedule.date + '" data-time="' + (schedule.time || '') + '" data-type="' + schedule.type + '">编辑</button> ';
			html += '<button class="layui-btn layui-btn-xs layui-btn-danger delete-btn" data-id="' + schedule.id + '">删除</button>';
			html += '</div>';
			html += '</div>';
		});
		
		$('#scheduleList').html(html);
	}

	var currentLayerIndex = null; // 记录当前弹窗，提交成功后关闭

	// 打开表单（新增/编辑）
	function openScheduleForm(options) {
		console.log('openScheduleForm 被调用，参数:', options);
		var defaults = {
			title: '添加日程',
			id: '',
			titleText: '',
			content: '',
			date: '',
			time: '',
			type: 'normal'
		};
		var opts = $.extend({}, defaults, options || {});
		var formHtml = $('#scheduleForm').html();
		console.log('表单HTML模板:', formHtml ? '存在' : '不存在');

		currentLayerIndex = layer.open({
			type: 1,
			title: opts.title,
			area: ['600px', '500px'],
			content: formHtml,
			success: function(layero, index) {
				console.log('弹窗打开成功，开始初始化表单');

				// 使用当前弹窗内的表单元素，避免作用域混乱
				var $form = layero.find('form');

				// 日期处理 - 区分新增和编辑
				var dateValue;
				if (opts.date && opts.date.trim() !== '') {
					// 编辑模式：使用提供的日期
					dateValue = opts.date;
					console.log('编辑模式，使用日期:', dateValue);
				} else {
					// 新增模式：使用今天日期
					var today = new Date();
					dateValue = today.getFullYear() + '-' +
						String(today.getMonth() + 1).padStart(2, '0') + '-' +
						String(today.getDate()).padStart(2, '0');
					console.log('新增模式，使用今天日期:', dateValue);
				}

				// 设置表单值
				$form.find('#scheduleId').val(opts.id);
				$form.find('input[name="title"]').val(opts.titleText || '测试日程');
				$form.find('textarea[name="content"]').val(opts.content || '');
				$form.find('input[name="date"]').val(dateValue);
				$form.find('input[name="time"]').val(opts.time || '09:00');

				// 设置日期选择器
				laydate.render({
					elem: layero.find('#scheduleDate')[0],
					type: 'date',
					value: dateValue
				});

				// 加载用户列表
				loadUsersForSelect($form, opts.assignedTo || '');

				// 设置优先级单选按钮 - 使用标准HTML单选按钮
				var targetType = opts.type || 'normal';
				console.log('设置优先级为:', targetType);

				$form.find('input[name="type"]').each(function() {
					var $radio = $(this);
					var $circle = $radio.next('.radio-circle');
					var radioValue = $radio.val();

					if (radioValue === targetType) {
						$radio.prop('checked', true);
						$circle.text('●').css('color', '#1E9FFF');
						console.log('选中单选按钮:', radioValue);
					} else {
						$radio.prop('checked', false);
						$circle.text('○').css('color', '#9ca3af');
					}
				});

				// 为单选按钮组添加变化监听
				$form.find('input[name="type"]').off('change.priority').on('change.priority', function() {
					var $radio = $(this);
					var $circle = $radio.next('.radio-circle');
					var selectedValue = $radio.val();

					// 重置所有圆圈
					$form.find('.radio-circle').each(function() {
						$(this).text('○').css('color', '#9ca3af');
					});

					// 设置选中的圆圈
					if ($radio.is(':checked')) {
						$circle.text('●').css('color', '#1E9FFF');
					}

					console.log('优先级选择变化为:', selectedValue);
				});

				console.log('单选按钮设置完成');

				console.log('表单数据设置完成:', {
					id: opts.id,
					title: opts.titleText || '测试日程',
					content: opts.content || '',
					date: dateValue,
					time: opts.time || '09:00',
					type: opts.type || 'normal'
				});

				// 绑定提交按钮事件
				layero.find('#submitBtn').on('click', function() {
					console.log('=== 弹窗提交按钮被点击 ===');

					try {
						var $form = $(this).closest('form');
						var formData = {};

						// 收集所有表单数据
						$form.find('input[name], textarea[name], select[name]').each(function() {
							var name = $(this).attr('name');
							var value = $(this).val();
							var type = $(this).attr('type');

							if (name) {
								console.log('字段:', name, '值:', value, '类型:', type);

								// 处理单选按钮和复选框
								if (type === 'radio' || type === 'checkbox') {
									if ($(this).is(':checked')) {
										formData[name] = value;
										console.log('选中:', name, '=', value);
									}
								} else {
									formData[name] = value;
								}
							}
						});

						console.log('收集到的表单数据:', formData);

						// 防止重复提交
						if ($(this).prop('disabled')) {
							console.log('按钮已被禁用，防止重复提交');
							return false;
						}

						$(this).prop('disabled', true);
						$(this).text('提交中...');

						console.log('调用submitSchedule...');
						submitSchedule(formData, $(this));
						return false;
					} catch (error) {
						console.error('提交按钮点击出错:', error);
						return false;
					}
				});


				// 绑定取消按钮
				layero.find('#cancelSchedule').on('click', function() {
					console.log('取消按钮点击');
					if (currentLayerIndex !== null) {
						layer.close(currentLayerIndex);
						currentLayerIndex = null;
					}
				});

				console.log('表单初始化完成');
			}
		});
	}

	// 提交表单处理函数
	function submitSchedule(data, $submitBtn) {
		var url = data.id ? '../../ajax.php?act=scheduleEdit' : '../../ajax.php?act=scheduleAdd';
		var action = data.id ? '编辑' : '添加';

		console.log(action + '日程 - 表单数据:', data);
		console.log('请求URL:', url);
		console.log('开始发送AJAX请求...');
		console.log('完整URL:', window.location.origin + '/' + url);
		console.log('当前页面URL:', window.location.href);

		$.ajax({
			url: url,
			type: 'POST',
			data: data,
			dataType: 'json',
			success: function(res) {
				console.log(action + '日程 - 服务器响应:', res);
				if (res.debug) {
					console.log('调试信息:', res.debug);
				}
				layer.msg(res.msg, { icon: res.code });
				if (res.code == 1) {
					console.log(action + '成功，刷新列表');
					if (currentLayerIndex !== null) {
						layer.close(currentLayerIndex);
						currentLayerIndex = null;
					}
					loadScheduleList(currentFilter);
				} else {
					console.log(action + '失败:', res.msg);
				}
				// 重新启用按钮
				if ($submitBtn && $submitBtn.length) {
					$submitBtn.prop('disabled', false);
					$submitBtn.text('确定');
				}
			},
			error: function(xhr, status, error) {
				console.log('AJAX错误详情:');
				console.log('- 状态码:', xhr.status);
				console.log('- 响应文本:', xhr.responseText);
				console.log('- 错误:', error);
				console.log('- 请求URL:', url);
				console.log('- 发送数据:', data);
				console.log('- 完整请求URL:', window.location.origin + '/' + url);

				// 尝试解析响应文本
				try {
					var responseJson = JSON.parse(xhr.responseText);
					console.log('- 解析后的响应:', responseJson);
					if (responseJson.msg) {
						layer.msg('错误：' + responseJson.msg, { icon: 2 });
					}
				} catch (e) {
					console.log('- 无法解析响应文本');
					layer.msg('提交失败：' + xhr.status + ' - ' + error, { icon: 2 });
				}

				// 重新启用按钮
				if ($submitBtn && $submitBtn.length) {
					$submitBtn.prop('disabled', false);
					$submitBtn.text('确定');
				}
			}
		});
	}



	// 添加日程
	$('#addSchedule').click(function() {
		console.log('添加日程按钮点击');
		openScheduleForm({
			title: '添加日程',
			id: '',
			titleText: '',
			content: '',
			date: '',
			time: '',
			type: 'normal'
		});

	});

	// 编辑日程
	$(document).on('click', '.edit-btn', function() {
		var $this = $(this);
		var editData = {
			id: $this.data('id'),
			title: $this.attr('data-title') || '',
			content: $this.attr('data-content') || '',
			date: $this.attr('data-date') || '',
			time: $this.attr('data-time') || '',
			type: $this.attr('data-type') || 'normal'
		};

		console.log('编辑按钮点击，原始数据:', editData);

		openScheduleForm({
			title: '编辑日程',
			id: editData.id,
			titleText: editData.title,
			content: editData.content,
			date: editData.date,
			time: editData.time,
			type: editData.type
		});

	});

	// 删除日程
	$(document).on('click', '.delete-btn', function() {
		var id = $(this).data('id');
		var that = $(this);
		
		layer.confirm('确定要删除这个日程吗？', { icon: 3, title: '提示' }, function(index) {
			$.ajax({
				url: '../../ajax.php?act=scheduleDelete',
				type: 'POST',
				data: { id: id },
				dataType: 'json',
				success: function(res) {
					layer.msg(res.msg, { icon: res.code });
					if (res.code == 1) {
						layer.close(index);
						loadScheduleList(currentFilter);
					}
				}
			});
		});
	});

	// 标记完成/未完成
	$(document).on('click', '.complete-btn', function() {
		var id = $(this).data('id');
		var buttonText = $(this).text().trim();
		var newStatus;

		console.log('标记完成按钮点击，ID:', id, '按钮文本:', buttonText);

		// 根据按钮文本确定要设置的新状态
		if (buttonText === '标记完成') {
			newStatus = 'completed';
			console.log('将状态设置为: completed');
		} else if (buttonText === '标记未完成') {
			newStatus = 'pending';
			console.log('将状态设置为: pending');
		} else {
			console.error('未知的按钮文本:', buttonText);
			return;
		}

		$.ajax({
			url: '../../ajax.php?act=scheduleComplete',
			type: 'POST',
			data: { id: id, status: newStatus },
			dataType: 'json',
			success: function(res) {
				console.log('标记完成响应:', res);
				layer.msg(res.msg, { icon: res.code });
				if (res.code == 1) {
					loadScheduleList(currentFilter);
				}
			},
			error: function(xhr, status, error) {
				console.error('标记完成AJAX错误:', xhr.responseText);
				layer.msg('操作失败，请重试', { icon: 2 });
			}
		});
	});

	// 加载用户列表用于分配选择
	function loadUsersForSelect($form, selectedUser) {
		console.log('加载用户列表用于分配选择, 选中用户:', selectedUser);

		$.ajax({
			url: '../../ajax.php?act=getUsers',
			type: 'POST',
			dataType: 'json',
			success: function(res) {
				console.log('获取用户列表响应:', res);

				var $select = $form.find('#assignedSelect');
				$select.empty();
				$select.append('<option value="">请选择用户</option>');

				if (res.code == 1 && res.data) {
					res.data.forEach(function(user) {
						var selected = (user.username === selectedUser) ? 'selected' : '';
						$select.append('<option value="' + user.username + '" ' + selected + '>' + user.username + '</option>');
					});
				}

				// 重新渲染select
				layui.form.render('select');
				console.log('用户列表加载完成');
			},
			error: function(xhr, status, error) {
				console.error('加载用户列表失败:', xhr.responseText);
				// 如果加载失败，至少添加当前用户
				var $select = $form.find('#assignedSelect');
				$select.append('<option value="admin" selected>admin</option>');
				layui.form.render('select');
			}
		});
	}

	// 筛选按钮
	$('.filter-btn').click(function() {
		$('.filter-btn').addClass('layui-btn-primary');
		$(this).removeClass('layui-btn-primary');
		var filter = $(this).data('filter');
		loadScheduleList(filter);
	});

	// 测试JavaScript是否正常加载
	console.log('日程管理页面JavaScript已加载');
	console.log('页面URL:', window.location.href);
	console.log('AJAX基础URL:', '../../ajax.php');

	// 初始化加载
	loadScheduleList('all');
});
