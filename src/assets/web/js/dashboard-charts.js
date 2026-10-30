/**
 * Antibot Admin Dashboard Visual Analytics (Apache ECharts)
 *
 * Modern, refined chart visuals with linear gradients, custom dark tooltips,
 * responsive resizing, and accessible fallbacks.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
(function (window, document) {
    'use strict';

    var AntibotCharts = {
        instances: {
            dynamics: null,
            distribution: null,
            reasons: null,
            routes: null
        },
        currentPeriod: '24h',
        endpoint: '',
        isRefreshing: false,

        /**
         * Initializes dashboard charts with provided initial dataset and endpoint.
         *
         * @param {Object} initialData Pre-rendered initial data from server
         * @param {string} endpointUrl API endpoint to fetch updated period data
         */
        init: function (initialData, endpointUrl) {
            this.endpoint = endpointUrl || '';
            if (initialData && initialData.period) {
                this.currentPeriod = initialData.period;
            }

            if (typeof window.echarts === 'undefined') {
                console.warn('Antibot: Apache ECharts library is not loaded. Displaying accessible table fallbacks.');
                this.showFallbacks();
                return;
            }

            this.bindEvents();
            this.renderAll(initialData);
            this.setupResponsiveResize();
        },

        /**
         * Renders all 4 charts with the given dataset.
         */
        renderAll: function (data) {
            if (!data) return;

            this.updateMetadata(data);
            this.renderDynamics(data.timeline);
            this.renderDistribution(data.actions);
            this.renderReasons(data.reasons);
            this.renderRoutes(data.routes);
        },

        /**
         * Updates header and KPI metadata badges.
         */
        updateMetadata: function (data) {
            var badgeCoverage = document.getElementById('antibot-badge-coverage');
            var badgeProcessed = document.getElementById('antibot-badge-processed');
            var badgeGenerated = document.getElementById('antibot-badge-generated');

            if (badgeCoverage) {
                if (data.coverage_status === 'full_window') {
                    badgeCoverage.innerHTML = '⏱ <strong>Полное окно</strong> (' + (data.period === '24h' ? '24 часа' : (data.period === '7d' ? '7 дней' : 'вся история')) + ')';
                    badgeCoverage.className = 'antibot-badge-soft text-success border';
                } else if (data.coverage_status === 'partial_window') {
                    badgeCoverage.innerHTML = '⚠️ <strong>Ограниченная выборка</strong> (последние ' + (data.events_in_sample || 0) + ' событий)';
                    badgeCoverage.className = 'antibot-badge-soft text-warning border';
                } else {
                    badgeCoverage.innerHTML = 'ℹ️ <strong>' + (data.period === 'all' ? 'Доступная история' : 'Нет данных') + '</strong>';
                    badgeCoverage.className = 'antibot-badge-soft text-muted border';
                }
            }

            if (badgeProcessed) {
                badgeProcessed.innerText = data.events_processed || 0;
            }
            if (badgeGenerated && data.generated_at) {
                badgeGenerated.innerText = data.generated_at;
            }
        },

        /**
         * Chart 1: Dynamic of suspicious activity (Line / Area Chart with gradients)
         */
        renderDynamics: function (timeline) {
            var container = document.getElementById('antibot-chart-dynamics');
            if (!container) return;

            if (this.instances.dynamics) {
                this.instances.dynamics.dispose();
            }

            var chart = window.echarts.init(container);
            this.instances.dynamics = chart;

            var labels = (timeline && timeline.labels) ? timeline.labels : [];
            var series = (timeline && timeline.series) ? timeline.series : {};

            var option = {
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: '#1e293b',
                    borderColor: '#334155',
                    borderWidth: 1,
                    textStyle: { color: '#f8fafc', fontSize: 12 },
                    borderRadius: 8,
                    padding: [10, 14],
                    axisPointer: {
                        type: 'line',
                        lineStyle: { color: '#94a3b8', type: 'dashed' }
                    },
                    formatter: function (params) {
                        var html = '<div style="font-weight:700;margin-bottom:6px;border-bottom:1px solid #334155;padding-bottom:4px;color:#e2e8f0;">' + params[0].axisValue + '</div>';
                        var total = 0;
                        params.forEach(function (item) {
                            html += '<div style="display:flex;justify-content:space-between;gap:16px;margin:3px 0;">' +
                                '<span><span style="display:inline-block;margin-right:6px;border-radius:50%;width:8px;height:8px;background-color:' + item.color + '"></span>' + item.seriesName + '</span>' +
                                '<strong style="color:#ffffff;">' + item.value + '</strong>' +
                                '</div>';
                            total += item.value;
                        });
                        html += '<div style="margin-top:6px;padding-top:4px;border-top:1px solid #334155;color:#94a3b8;font-size:11px;display:flex;justify-content:space-between;">' +
                            '<span>Всего событий:</span><strong style="color:#ffffff;">' + total + '</strong>' +
                            '</div>';
                        return html;
                    }
                },
                legend: {
                    data: ['Мониторинг', 'Заблокировано', 'Rate Limit', 'Проверки (Challenge)'],
                    top: 0,
                    icon: 'circle',
                    itemGap: 18,
                    textStyle: { color: '#475569', fontSize: 12, fontWeight: 500 }
                },
                grid: {
                    left: '2%',
                    right: '3%',
                    bottom: '3%',
                    top: 45,
                    containLabel: true
                },
                xAxis: {
                    type: 'category',
                    boundaryGap: false,
                    data: labels,
                    axisLine: { lineStyle: { color: '#cbd5e1' } },
                    axisLabel: { color: '#64748b', fontSize: 11 }
                },
                yAxis: {
                    type: 'value',
                    minInterval: 1,
                    splitLine: { lineStyle: { color: '#f1f5f9' } },
                    axisLabel: { color: '#64748b', fontSize: 11 }
                },
                series: [
                    {
                        name: 'Мониторинг',
                        type: 'line',
                        smooth: true,
                        itemStyle: { color: '#3b82f6' },
                        lineStyle: { width: 2.5, color: '#3b82f6' },
                        areaStyle: {
                            color: {
                                type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                                colorStops: [
                                    { offset: 0, color: 'rgba(59, 130, 246, 0.28)' },
                                    { offset: 1, color: 'rgba(59, 130, 246, 0.01)' }
                                ]
                            }
                        },
                        data: series.monitoring || []
                    },
                    {
                        name: 'Заблокировано',
                        type: 'line',
                        smooth: true,
                        itemStyle: { color: '#ef4444' },
                        lineStyle: { width: 2.5, color: '#ef4444' },
                        areaStyle: {
                            color: {
                                type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                                colorStops: [
                                    { offset: 0, color: 'rgba(239, 68, 68, 0.28)' },
                                    { offset: 1, color: 'rgba(239, 68, 68, 0.01)' }
                                ]
                            }
                        },
                        data: series.blocked || []
                    },
                    {
                        name: 'Rate Limit',
                        type: 'line',
                        smooth: true,
                        itemStyle: { color: '#f59e0b' },
                        lineStyle: { width: 2.5, color: '#f59e0b' },
                        areaStyle: {
                            color: {
                                type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                                colorStops: [
                                    { offset: 0, color: 'rgba(245, 158, 11, 0.28)' },
                                    { offset: 1, color: 'rgba(245, 158, 11, 0.01)' }
                                ]
                            }
                        },
                        data: series.throttled || []
                    },
                    {
                        name: 'Проверки (Challenge)',
                        type: 'line',
                        smooth: true,
                        itemStyle: { color: '#8b5cf6' },
                        lineStyle: { width: 2.5, color: '#8b5cf6' },
                        areaStyle: {
                            color: {
                                type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                                colorStops: [
                                    { offset: 0, color: 'rgba(139, 92, 246, 0.28)' },
                                    { offset: 1, color: 'rgba(139, 92, 246, 0.01)' }
                                ]
                            }
                        },
                        data: series.challenge || []
                    }
                ]
            };

            chart.setOption(option);
        },

        /**
         * Chart 2: Event distribution (Donut Chart)
         */
        renderDistribution: function (actions) {
            var container = document.getElementById('antibot-chart-distribution');
            if (!container) return;

            if (this.instances.distribution) {
                this.instances.distribution.dispose();
            }

            var chart = window.echarts.init(container);
            this.instances.distribution = chart;

            var total = (actions && actions.total) ? actions.total : 0;
            var items = (actions && actions.items) ? actions.items : [];

            if (total === 0 || actions.isEmpty) {
                chart.setOption({
                    title: {
                        text: 'Нет данных',
                        subtext: 'Подозрительных событий в выборке не зафиксировано',
                        left: 'center',
                        top: 'center',
                        textStyle: { color: '#64748b', fontSize: 16, fontWeight: 'normal' },
                        subtextStyle: { color: '#94a3b8', fontSize: 12 }
                    }
                });
                return;
            }

            var colorMap = {
                'Мониторинг': '#3b82f6',
                'Заблокировано': '#ef4444',
                'Rate Limit': '#f59e0b',
                'Проверки (Challenge)': '#8b5cf6',
                'Другие события': '#64748b'
            };

            var pieData = items.map(function (it) {
                return {
                    name: it.name,
                    value: it.value,
                    itemStyle: { color: colorMap[it.name] || it.color }
                };
            });

            var option = {
                title: {
                    text: total,
                    subtext: 'событий журнала',
                    left: 'center',
                    top: '38%',
                    textStyle: {
                        fontSize: 28,
                        fontWeight: 'bold',
                        color: '#0f172a'
                    },
                    subtextStyle: {
                        fontSize: 12,
                        color: '#64748b'
                    }
                },
                tooltip: {
                    trigger: 'item',
                    backgroundColor: '#1e293b',
                    borderColor: '#334155',
                    borderWidth: 1,
                    textStyle: { color: '#f8fafc', fontSize: 12 },
                    borderRadius: 8,
                    padding: [10, 14],
                    formatter: function (params) {
                        return '<div style="font-weight:700;color:#e2e8f0;margin-bottom:4px;">' + params.name + '</div>' +
                            '<div style="display:flex;justify-content:space-between;gap:14px;"><span>Событий:</span><strong>' + params.value + '</strong></div>' +
                            '<div style="display:flex;justify-content:space-between;gap:14px;"><span>Доля в журнале:</span><strong>' + params.percent + '%</strong></div>' +
                            '<div style="font-size:10px;color:#94a3b8;margin-top:6px;border-top:1px solid #334155;padding-top:3px;">(% от учтённых событий журнала)</div>';
                    }
                },
                legend: {
                    bottom: 0,
                    left: 'center',
                    icon: 'circle',
                    itemGap: 14,
                    textStyle: { color: '#475569', fontSize: 11, fontWeight: 500 }
                },
                series: [
                    {
                        name: 'Распределение событий',
                        type: 'pie',
                        radius: ['52%', '74%'],
                        center: ['50%', '45%'],
                        avoidLabelOverlap: true,
                        itemStyle: {
                            borderRadius: 8,
                            borderColor: '#ffffff',
                            borderWidth: 3
                        },
                        label: { show: false },
                        emphasis: {
                            label: { show: false },
                            scale: true,
                            scaleSize: 6
                        },
                        data: pieData
                    }
                ]
            };

            chart.setOption(option);
        },

        /**
         * Chart 3: Main trigger reasons (Horizontal Bar Chart with background track)
         */
        renderReasons: function (reasons) {
            var container = document.getElementById('antibot-chart-reasons');
            if (!container) return;

            if (this.instances.reasons) {
                this.instances.reasons.dispose();
            }

            var chart = window.echarts.init(container);
            this.instances.reasons = chart;

            var items = (reasons && reasons.items) ? reasons.items.slice().reverse() : [];

            if (items.length === 0 || reasons.isEmpty) {
                chart.setOption({
                    title: {
                        text: 'Нет данных',
                        subtext: 'Срабатываний не зафиксировано',
                        left: 'center',
                        top: 'center',
                        textStyle: { color: '#64748b', fontSize: 16, fontWeight: 'normal' },
                        subtextStyle: { color: '#94a3b8', fontSize: 12 }
                    }
                });
                return;
            }

            var categories = items.map(function (it) { return it.label; });
            var values = items.map(function (it) { return it.count; });

            var option = {
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: '#1e293b',
                    borderColor: '#334155',
                    borderWidth: 1,
                    textStyle: { color: '#f8fafc', fontSize: 12 },
                    borderRadius: 8,
                    padding: [10, 14],
                    axisPointer: { type: 'shadow' },
                    formatter: function (params) {
                        var p = params[0];
                        return '<div style="font-weight:700;color:#e2e8f0;margin-bottom:4px;">' + p.name + '</div>' +
                            '<div style="display:flex;justify-content:space-between;gap:14px;"><span>Срабатываний:</span><strong style="color:#ffffff;">' + p.value + '</strong></div>';
                    }
                },
                grid: {
                    left: '3%',
                    right: '6%',
                    bottom: '3%',
                    top: '4%',
                    containLabel: true
                },
                xAxis: {
                    type: 'value',
                    minInterval: 1,
                    splitLine: { lineStyle: { color: '#f1f5f9' } },
                    axisLabel: { color: '#64748b', fontSize: 11 }
                },
                yAxis: {
                    type: 'category',
                    data: categories,
                    axisLine: { lineStyle: { color: '#cbd5e1' } },
                    axisLabel: {
                        color: '#334155',
                        fontSize: 11,
                        fontWeight: 500,
                        formatter: function (val) {
                            return val.length > 24 ? val.substr(0, 22) + '…' : val;
                        }
                    }
                },
                series: [
                    {
                        name: 'Срабатывания',
                        type: 'bar',
                        data: values,
                        showBackground: true,
                        backgroundStyle: {
                            color: '#f8fafc',
                            borderRadius: [0, 6, 6, 0]
                        },
                        itemStyle: {
                            color: {
                                type: 'linear', x: 0, y: 0, x2: 1, y2: 0,
                                colorStops: [
                                    { offset: 0, color: '#4f46e5' },
                                    { offset: 1, color: '#6366f1' }
                                ]
                            },
                            borderRadius: [0, 6, 6, 0]
                        },
                        barMaxWidth: 18
                    }
                ]
            };

            chart.setOption(option);
        },

        /**
         * Chart 4: Top targeted URLs (Horizontal Bar Chart)
         */
        renderRoutes: function (routes) {
            var container = document.getElementById('antibot-chart-routes');
            if (!container) return;

            if (this.instances.routes) {
                this.instances.routes.dispose();
            }

            var chart = window.echarts.init(container);
            this.instances.routes = chart;

            var items = (routes && routes.items) ? routes.items.slice().reverse() : [];

            if (items.length === 0 || routes.isEmpty) {
                chart.setOption({
                    title: {
                        text: 'Нет данных',
                        subtext: 'Подозрительных обращений к маршрутам не зафиксировано',
                        left: 'center',
                        top: 'center',
                        textStyle: { color: '#64748b', fontSize: 16, fontWeight: 'normal' },
                        subtextStyle: { color: '#94a3b8', fontSize: 12 }
                    }
                });
                return;
            }

            var categories = items.map(function (it) { return it.route; });
            var values = items.map(function (it) { return it.count; });

            var option = {
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: '#1e293b',
                    borderColor: '#334155',
                    borderWidth: 1,
                    textStyle: { color: '#f8fafc', fontSize: 12 },
                    borderRadius: 8,
                    padding: [10, 14],
                    axisPointer: { type: 'shadow' },
                    formatter: function (params) {
                        var p = params[0];
                        var safeRoute = (p.name || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        return '<div style="font-family:monospace;font-size:12px;color:#e2e8f0;margin-bottom:4px;">' + safeRoute + '</div>' +
                            '<div style="display:flex;justify-content:space-between;gap:14px;"><span>Подозрительных событий:</span><strong style="color:#ef4444;">' + p.value + '</strong></div>';
                    }
                },
                grid: {
                    left: '3%',
                    right: '6%',
                    bottom: '3%',
                    top: '4%',
                    containLabel: true
                },
                xAxis: {
                    type: 'value',
                    minInterval: 1,
                    splitLine: { lineStyle: { color: '#f1f5f9' } },
                    axisLabel: { color: '#64748b', fontSize: 11 }
                },
                yAxis: {
                    type: 'category',
                    data: categories,
                    axisLine: { lineStyle: { color: '#cbd5e1' } },
                    axisLabel: {
                        color: '#334155',
                        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                        fontSize: 11,
                        formatter: function (val) {
                            return val.length > 28 ? val.substr(0, 26) + '…' : val;
                        }
                    }
                },
                series: [
                    {
                        name: 'События',
                        type: 'bar',
                        data: values,
                        showBackground: true,
                        backgroundStyle: {
                            color: '#f8fafc',
                            borderRadius: [0, 6, 6, 0]
                        },
                        itemStyle: {
                            color: {
                                type: 'linear', x: 0, y: 0, x2: 1, y2: 0,
                                colorStops: [
                                    { offset: 0, color: '#ef4444' },
                                    { offset: 1, color: '#f87171' }
                                ]
                            },
                            borderRadius: [0, 6, 6, 0]
                        },
                        barMaxWidth: 18
                    }
                ]
            };

            chart.setOption(option);
        },

        /**
         * Binds period switcher buttons and refresh action.
         */
        bindEvents: function () {
            var self = this;

            var periodButtons = document.querySelectorAll('.antibot-btn-period');
            periodButtons.forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var p = btn.getAttribute('data-period');
                    if (!p || p === self.currentPeriod) return;

                    periodButtons.forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    self.currentPeriod = p;

                    self.fetchAnalytics(p, false);
                });
            });

            var refreshBtn = document.getElementById('antibot-btn-refresh');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (self.isRefreshing) return;

                    self.isRefreshing = true;
                    refreshBtn.classList.add('is-spinning');
                    refreshBtn.setAttribute('disabled', 'disabled');

                    self.fetchAnalytics(self.currentPeriod, true, function () {
                        setTimeout(function () {
                            self.isRefreshing = false;
                            refreshBtn.classList.remove('is-spinning');
                            refreshBtn.removeAttribute('disabled');
                        }, 1200);
                    });
                });
            }
        },

        /**
         * Fetches analytics via AJAX from endpoint.
         */
        fetchAnalytics: function (period, forceRefresh, callback) {
            var self = this;
            if (!this.endpoint) {
                if (callback) callback();
                return;
            }

            var url = this.endpoint + (this.endpoint.indexOf('?') >= 0 ? '&' : '?') +
                'period=' + encodeURIComponent(period) +
                (forceRefresh ? '&refresh=1' : '');

            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function () {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        self.renderAll(response);
                    } catch (e) {
                        console.error('Antibot: Failed to parse analytics JSON', e);
                    }
                }
                if (callback) callback();
            };
            xhr.onerror = function () {
                console.error('Antibot: Network error loading analytics');
                if (callback) callback();
            };
            xhr.send();
        },

        /**
         * Sets up responsive resize with debouncing.
         */
        setupResponsiveResize: function () {
            var self = this;
            var resizeTimer = null;
            window.addEventListener('resize', function () {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function () {
                    for (var key in self.instances) {
                        if (self.instances[key] && !self.instances[key].isDisposed()) {
                            self.instances[key].resize();
                        }
                    }
                }, 150);
            });
        },

        /**
         * Fallback for environments where JavaScript or ECharts fails.
         */
        showFallbacks: function () {
            var containers = document.querySelectorAll('.antibot-chart-fallback');
            containers.forEach(function (el) {
                el.style.display = 'block';
            });
        }
    };

    window.AntibotCharts = AntibotCharts;
})(window, document);
