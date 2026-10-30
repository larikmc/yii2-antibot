/**
 * Antibot Admin Dashboard Visual Analytics (Apache ECharts)
 *
 * Implements lazy chart rendering, responsive resize, period switching,
 * tooltips, legends, and fail-safe graceful degradation without external CDNs.
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
                    badgeCoverage.innerHTML = '⏱ <strong>Полное окно</strong> (' + (data.period === '24h' ? '24 часа' : (data.period === '7d' ? '7 дней' : 'история')) + ')';
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
         * Chart 1: Dynamic of suspicious activity (Line / Area Chart)
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
                    axisPointer: {
                        type: 'cross',
                        label: { backgroundColor: '#6a7985' }
                    },
                    formatter: function (params) {
                        var html = '<strong>' + params[0].axisValue + '</strong><br/>';
                        var total = 0;
                        params.forEach(function (item) {
                            html += '<span style="display:inline-block;margin-right:5px;border-radius:10px;width:9px;height:9px;background-color:' + item.color + '"></span>' +
                                item.seriesName + ': <strong>' + item.value + '</strong><br/>';
                            total += item.value;
                        });
                        html += '<span style="color:#6c757d;font-size:11px;">Сумма за интервал: ' + total + '</span>';
                        return html;
                    }
                },
                legend: {
                    data: ['Мониторинг', 'Заблокировано', 'Rate Limit', 'Проверки (Challenge)'],
                    top: 0,
                    icon: 'circle',
                    itemGap: 16
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
                    axisLine: { lineStyle: { color: '#ced4da' } },
                    axisLabel: { color: '#6c757d', fontSize: 11 }
                },
                yAxis: {
                    type: 'value',
                    minInterval: 1,
                    splitLine: { lineStyle: { color: '#f1f3f5' } },
                    axisLabel: { color: '#6c757d', fontSize: 11 }
                },
                series: [
                    {
                        name: 'Мониторинг',
                        type: 'line',
                        smooth: true,
                        itemStyle: { color: '#0d6efd' },
                        lineStyle: { width: 2, color: '#0d6efd' },
                        areaStyle: { color: 'rgba(13, 110, 253, 0.12)' },
                        data: series.monitoring || []
                    },
                    {
                        name: 'Заблокировано',
                        type: 'line',
                        smooth: true,
                        itemStyle: { color: '#dc3545' },
                        lineStyle: { width: 2, color: '#dc3545' },
                        areaStyle: { color: 'rgba(220, 53, 69, 0.12)' },
                        data: series.blocked || []
                    },
                    {
                        name: 'Rate Limit',
                        type: 'line',
                        smooth: true,
                        itemStyle: { color: '#fd7e14' },
                        lineStyle: { width: 2, color: '#fd7e14' },
                        areaStyle: { color: 'rgba(253, 126, 20, 0.12)' },
                        data: series.throttled || []
                    },
                    {
                        name: 'Проверки (Challenge)',
                        type: 'line',
                        smooth: true,
                        itemStyle: { color: '#6f42c1' },
                        lineStyle: { width: 2, color: '#6f42c1' },
                        areaStyle: { color: 'rgba(111, 66, 193, 0.12)' },
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
                        textStyle: { color: '#6c757d', fontSize: 16, fontWeight: 'normal' },
                        subtextStyle: { color: '#adb5bd', fontSize: 12 }
                    }
                });
                return;
            }

            var pieData = items.map(function (it) {
                return {
                    name: it.name,
                    value: it.value,
                    itemStyle: { color: it.color }
                };
            });

            var option = {
                title: {
                    text: total,
                    subtext: 'событий в выборке',
                    left: 'center',
                    top: '38%',
                    textStyle: {
                        fontSize: 26,
                        fontWeight: 'bold',
                        color: '#212529'
                    },
                    subtextStyle: {
                        fontSize: 11,
                        color: '#6c757d'
                    }
                },
                tooltip: {
                    trigger: 'item',
                    formatter: function (params) {
                        return '<strong>' + params.name + '</strong><br/>' +
                            'Количество: <strong>' + params.value + '</strong><br/>' +
                            'Доля в журнале: <strong>' + params.percent + '%</strong><br/>' +
                            '<span style="font-size:10px;color:#adb5bd;">(% от учтённых событий журнала)</span>';
                    }
                },
                legend: {
                    bottom: 0,
                    left: 'center',
                    icon: 'circle',
                    itemGap: 12
                },
                series: [
                    {
                        name: 'Распределение событий',
                        type: 'pie',
                        radius: ['45%', '70%'],
                        center: ['50%', '45%'],
                        avoidLabelOverlap: true,
                        itemStyle: {
                            borderRadius: 6,
                            borderColor: '#fff',
                            borderWidth: 2
                        },
                        label: {
                            show: false
                        },
                        emphasis: {
                            label: {
                                show: false
                            }
                        },
                        data: pieData
                    }
                ]
            };

            chart.setOption(option);
        },

        /**
         * Chart 3: Main trigger reasons (Horizontal Bar Chart)
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
                        textStyle: { color: '#6c757d', fontSize: 16, fontWeight: 'normal' },
                        subtextStyle: { color: '#adb5bd', fontSize: 12 }
                    }
                });
                return;
            }

            var categories = items.map(function (it) { return it.label; });
            var values = items.map(function (it) { return it.count; });

            var option = {
                tooltip: {
                    trigger: 'axis',
                    axisPointer: { type: 'shadow' },
                    formatter: function (params) {
                        var p = params[0];
                        return '<strong>' + p.name + '</strong><br/>' +
                            'Количество срабатываний: <strong>' + p.value + '</strong>';
                    }
                },
                grid: {
                    left: '3%',
                    right: '6%',
                    bottom: '3%',
                    top: '6%',
                    containLabel: true
                },
                xAxis: {
                    type: 'value',
                    minInterval: 1,
                    splitLine: { lineStyle: { color: '#f1f3f5' } },
                    axisLabel: { color: '#6c757d', fontSize: 11 }
                },
                yAxis: {
                    type: 'category',
                    data: categories,
                    axisLine: { lineStyle: { color: '#ced4da' } },
                    axisLabel: {
                        color: '#495057',
                        fontSize: 11,
                        formatter: function (val) {
                            return val.length > 22 ? val.substr(0, 20) + '…' : val;
                        }
                    }
                },
                series: [
                    {
                        name: 'Срабатывания',
                        type: 'bar',
                        data: values,
                        itemStyle: {
                            color: '#0d6efd',
                            borderRadius: [0, 4, 4, 0]
                        },
                        barMaxWidth: 20
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
                        textStyle: { color: '#6c757d', fontSize: 16, fontWeight: 'normal' },
                        subtextStyle: { color: '#adb5bd', fontSize: 12 }
                    }
                });
                return;
            }

            var categories = items.map(function (it) { return it.route; });
            var values = items.map(function (it) { return it.count; });

            var option = {
                tooltip: {
                    trigger: 'axis',
                    axisPointer: { type: 'shadow' },
                    formatter: function (params) {
                        var p = params[0];
                        // Safe sanitization in tooltip
                        var safeRoute = (p.name || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        return '<code>' + safeRoute + '</code><br/>' +
                            'Подозрительных событий: <strong>' + p.value + '</strong>';
                    }
                },
                grid: {
                    left: '3%',
                    right: '6%',
                    bottom: '3%',
                    top: '6%',
                    containLabel: true
                },
                xAxis: {
                    type: 'value',
                    minInterval: 1,
                    splitLine: { lineStyle: { color: '#f1f3f5' } },
                    axisLabel: { color: '#6c757d', fontSize: 11 }
                },
                yAxis: {
                    type: 'category',
                    data: categories,
                    axisLine: { lineStyle: { color: '#ced4da' } },
                    axisLabel: {
                        color: '#495057',
                        fontFamily: 'monospace',
                        fontSize: 11,
                        formatter: function (val) {
                            return val.length > 25 ? val.substr(0, 23) + '…' : val;
                        }
                    }
                },
                series: [
                    {
                        name: 'События',
                        type: 'bar',
                        data: values,
                        itemStyle: {
                            color: '#dc3545',
                            borderRadius: [0, 4, 4, 0]
                        },
                        barMaxWidth: 20
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

            // Period selector buttons
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

            // Refresh button
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
