layui.use(['layer', 'jquery'], function() {
	var layer = void 0 === parent.layer ? layui.layer : top.layer;
	var $ = layui.$;
	var currentFilter = 'month';

	// 初始化图表
	var statusChart = echarts.init(document.getElementById('statusChart'));
	var typeChart = echarts.init(document.getElementById('typeChart'));
	var trendChart = echarts.init(document.getElementById('trendChart'));

	// 窗口大小改变时，重新调整图表大小
	window.addEventListener('resize', function() {
		statusChart.resize();
		typeChart.resize();
		trendChart.resize();
	});

	// 加载统计数据
	function loadAnalysisData(filter) {
		currentFilter = filter;
		var loading = layer.load(1, { shade: [0.1, '#fff'] });

		$.ajax({
			url: '../../ajax.php?act=scheduleAnalysis',
			type: 'POST',
			data: { filter: filter },
			dataType: 'json',
			success: function(res) {
				layer.close(loading);
				if (res.code == 1) {
					updateStatistics(res.data);
					updateCharts(res.data);
				} else {
					layer.msg(res.msg, { icon: 2 });
				}
			},
			error: function() {
				layer.close(loading);
				layer.msg('加载失败，请重试', { icon: 2 });
			}
		});
	}

	// 更新统计数字
	function updateStatistics(data) {
		$('#totalCount').text(data.total || 0);
		$('#completedCount').text(data.completed || 0);
		$('#pendingCount').text(data.pending || 0);
		$('#completionRate').text((data.completionRate || 0) + '%');
	}

	// 更新图表
	function updateCharts(data) {
		// 完成状态饼图
		statusChart.setOption({
			tooltip: {
				trigger: 'item',
				formatter: '{a} <br/>{b}: {c} ({d}%)'
			},
			legend: {
				orient: 'vertical',
				left: 'left',
				data: ['已完成', '待完成']
			},
			series: [{
				name: '完成状态',
				type: 'pie',
				radius: ['40%', '70%'],
				avoidLabelOverlap: false,
				itemStyle: {
					borderRadius: 10,
					borderColor: '#fff',
					borderWidth: 2
				},
				label: {
					show: true,
					formatter: '{b}: {c}\n({d}%)'
				},
				emphasis: {
					label: {
						show: true,
						fontSize: '20',
						fontWeight: 'bold'
					}
				},
				labelLine: {
					show: true
				},
				data: [
					{ value: data.byStatus.completed || 0, name: '已完成', itemStyle: { color: '#5FB878' } },
					{ value: data.byStatus.pending || 0, name: '待完成', itemStyle: { color: '#FFB800' } }
				]
			}]
		});

		// 优先级分布饼图
		typeChart.setOption({
			tooltip: {
				trigger: 'item',
				formatter: '{a} <br/>{b}: {c} ({d}%)'
			},
			legend: {
				orient: 'vertical',
				left: 'left',
				data: ['普通', '重要', '紧急']
			},
			series: [{
				name: '优先级',
				type: 'pie',
				radius: ['40%', '70%'],
				avoidLabelOverlap: false,
				itemStyle: {
					borderRadius: 10,
					borderColor: '#fff',
					borderWidth: 2
				},
				label: {
					show: true,
					formatter: '{b}: {c}\n({d}%)'
				},
				emphasis: {
					label: {
						show: true,
						fontSize: '20',
						fontWeight: 'bold'
					}
				},
				labelLine: {
					show: true
				},
				data: [
					{ value: data.byType.normal || 0, name: '普通', itemStyle: { color: '#1E9FFF' } },
					{ value: data.byType.important || 0, name: '重要', itemStyle: { color: '#FFB800' } },
					{ value: data.byType.urgent || 0, name: '紧急', itemStyle: { color: '#FF5722' } }
				]
			}]
		});

		// 完成趋势折线图
		trendChart.setOption({
			tooltip: {
				trigger: 'axis',
				axisPointer: {
					type: 'cross'
				}
			},
			legend: {
				data: ['完成数量']
			},
			grid: {
				left: '3%',
				right: '4%',
				bottom: '3%',
				containLabel: true
			},
			xAxis: {
				type: 'category',
				boundaryGap: false,
				data: data.trendLabels || []
			},
			yAxis: {
				type: 'value',
				name: '完成数量'
			},
			series: [{
				name: '完成数量',
				type: 'line',
				smooth: true,
				symbol: 'circle',
				symbolSize: 6,
				itemStyle: {
					color: '#5FB878'
				},
				areaStyle: {
					color: {
						type: 'linear',
						x: 0,
						y: 0,
						x2: 0,
						y2: 1,
						colorStops: [{
							offset: 0, color: 'rgba(95, 184, 120, 0.3)'
						}, {
							offset: 1, color: 'rgba(95, 184, 120, 0.1)'
						}]
					}
				},
				data: data.trendData || []
			}]
		});
	}

	// 筛选按钮
	$('.filter-btn').click(function() {
		$('.filter-btn').removeClass('layui-btn-primary').addClass('layui-btn-primary');
		$(this).removeClass('layui-btn-primary');
		var filter = $(this).data('filter');
		loadAnalysisData(filter);
	});

	// 初始化加载
	loadAnalysisData('month');
});
