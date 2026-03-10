layui.use(['form', 'layer', 'jquery'], function() {
	var form = layui.form;
	var layer = layui.layer;
	var $ = layui.jquery;

	// 页面加载时检查是否已登录
	$(function() {
		// 检查是否是从退出登录跳转过来的
		var urlParams = new URLSearchParams(window.location.search);
		if (urlParams.get('logout') === '1') {
			// 如果是退出登录，不检查登录状态
			console.log('退出登录，不自动登录');
			return;
		}

		// 否则检查登录状态
		$.ajax({
			url: 'ajax.php',
			dataType: 'json',
			success: function(res) {
				if (res.msg == 'No Act!') {
					// 已登录，跳转到主页
					window.location.href = 'home.html';
				}
			}
		});
	});

	// 登录表单提交
	form.on('submit(login)', function(data) {
		var loadIndex = layer.msg('数据提交中，请稍候', {
			icon: 16,
			time: false,
			shade: 0.8
		});

		$.ajax({
			url: 'ajax.php?act=login',
			type: 'POST',
			data: data.field,
			dataType: 'json',
			success: function(res) {
				setTimeout(function() {
					layer.close(loadIndex);
					layer.msg(res.msg, {
						icon: res.code,
						time: 1000
					});
					setTimeout(function() {
						if (res.code == 1) {
							window.location.href = 'home.html';
						}
					}, 1500);
				}, 500);
			}
		});

		return false;
	});

	// 输入框焦点效果
	$('.loginBody .input-item').click(function(e) {
		e.stopPropagation();
		$(this).addClass('layui-input-focus').find('.layui-input').focus();
	});

	$('.loginBody .layui-form-item .layui-input').focus(function() {
		$(this).parent().addClass('layui-input-focus');
	});

	$('.loginBody .layui-form-item .layui-input').blur(function() {
		$(this).parent().removeClass('layui-input-focus');
		if ($(this).val() != '') {
			$(this).parent().addClass('layui-input-active');
		} else {
			$(this).parent().removeClass('layui-input-active');
		}
	});
});


































