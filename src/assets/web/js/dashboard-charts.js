/**
 * Antibot Admin Dashboard Visual Analytics (Apache ECharts)
 *
 * Modern, refined chart visuals designed to match Laricum C Admin UI Kit.
 * Native, dynamic support for Light & Dark themes with instant reactive re-rendering.
 * Seamless bilingual support (Russian / English).
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
        lang: 'ru',
        lastData: null,
        themeObserver: null,

        i18n: {
            ru: {
                monitoring: 'Мониторинг',
                blocked: 'Заблокировано',
                rateLimit: 'Rate Limit',
                challenge: 'Проверки (Challenge)',
                other: 'Другие события',
                totalEvents: 'Всего событий:',
                journalEvents: 'событий журнала',
                shareInJournal: 'Доля в журнале:',
                shareNote: '(% от учтённых событий журнала)',
                triggers: 'Срабатываний:',
                suspiciousEvents: 'Подозрительных событий:',
                noData: 'Нет данных',
                noDataEvents: 'Подозрительных событий в выборке не зафиксировано',
                noDataTriggers: 'Срабатываний не зафиксировано',
                noDataRoutes: 'Подозрительных обращений к маршрутам не зафиксировано',
                fullWindow: 'Полное окно',
                limitedSample: 'Ограниченная выборка',
                lastEvents: 'последние {n} событий',
                allHistory: 'Вся история',
                availableHistory: 'Доступная история'
            },
            en: {
                monitoring: 'Monitored',
                blocked: 'Blocked',
                rateLimit: 'Rate Limit',
                challenge: 'Challenges',
                other: 'Other Events',
                totalEvents: 'Total Events:',
                journalEvents: 'journal events',
                shareInJournal: 'Share in log:',
                shareNote: '(% of recorded journal events)',
                triggers: 'Triggers:',
                suspiciousEvents: 'Suspicious requests:',
                noData: 'No Data',
                noDataEvents: 'No suspicious events recorded in this period',
                noDataTriggers: 'No security triggers recorded',
                noDataRoutes: 'No suspicious requests to routes recorded',
                fullWindow: 'Full window',
                limitedSample: 'Limited sample',
                lastEvents: 'last {n} events',
                allHistory: 'All history',
                availableHistory: 'Available history'
            }
        },

        t: function (key, params) {
            var dict = this.i18n[this.lang] || this.i18n.ru;
            var str = dict[key] || (this.i18n.ru[key] || key);
            if (params) {
                for (var p in params) {
                    str = str.replace('{' + p + '}', params[p]);
                }
            }
            return str;
        },

        isDark: function () {
            return document.documentElement.classList.contains('dark') ||
                   document.body.classList.contains('dark');
        },

        /**
         * Initializes dashboard charts with provided initial dataset and endpoint.
         *
         * @param {Object} initialData Pre-rendered initial data from server
         * @param {string} endpointUrl API endpoint to fetch updated period data
         * @param {string} lang Language code ('ru' or 'en')
         */
        init: function (initialData, endpointUrl, lang) {
            this.endpoint = endpointUrl || '';
            this.lang = (lang === 'en') ? 'en' : 'ru';

            if (initialData && initialData.period) {
                this.currentPeriod = initialData.period;
            }
            this.lastData = initialData;

            if (typeof window.echarts === 'undefined') {
                console.warn('Antibot: Apache ECharts library is not loaded. Displaying accessible table fallbacks.');
                this.showFallbacks();
                return;
            }

            this.bindEvents();
            this.setupThemeObserver();
            this.renderAll(initialData);
            this.setupResponsiveResize();
        },

        /**
         * Renders all 4 charts with the given dataset.
         */
        renderAll: function (data) {
            if (!data) return;
            this.lastData = data;

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
            var isRu = (this.lang === 'ru');

            if (badgeCoverage) {
                if (data.coverage_status === 'full_window') {
                    var periodLabel = data.period === '24h'
                        ? (isRu ? '24 часа' : '24 hours')
                        : (data.period === '7d' ? (isRu ? '7 дней' : '7 days') : (isRu ? 'вся история' : 'all history'));
                    badgeCoverage.innerHTML = '⏱ <strong>' + this.t('fullWindow') + '</strong> (' + periodLabel + ')';
                    badgeCoverage.className = 'antibot-badge-soft badge-soft-success border';
                } else if (data.coverage_status === 'partial_window') {
                    badgeCoverage.innerHTML = '⚠️ <strong>' + this.t('limitedSample') + '</strong> (' + this.t('lastEvents', { n: data.events_in_sample || 0 }) + ')';
                    badgeCoverage.className = 'antibot-badge-soft badge-soft-warning border';
                } else {
                    badgeCoverage.innerHTML = 'ℹ️ <strong>' + (data.period === 'all' ? this.t('availableHistory') : this.t('noData')) + '</strong>';
                    badgeCoverage.className = 'antibot-badge-soft badge-soft-secondary border';
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
            var dark = this.isDark();
            var self = this;

            var textColor = dark ? '#a0b2d6' : '#64748b';
            var splitLineColor = dark ? '#243756' : '#f1f5fa';
            var axisLineColor = dark ? '#3c5272' : '#cbd5e1';
            var legendColor = dark ? '#dce8ff' : '#475569';

            var option = {
                backgroundColor: 'transparent',
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: dark ? '#17243c' : '#1e293b',
                    borderColor: dark ? '#334863' : '#334155',
                    borderWidth: 1,
                    textStyle: { color: '#f8fafc', fontSize: 12 },
                    borderRadius: 9,
                    padding: [10, 14],
                    axisPointer: {
                        type: 'line',
                        lineStyle: { color: dark ? '#455d82' : '#94a3b8', type: 'dashed' }
                    },
                    formatter: function (params) {
                        var html = '<div style="font-weight:700;margin-bottom:6px;border-bottom:1px solid ' + (dark ? '#334863' : '#334155') + ';padding-bottom:4px;color:#e2e8f0;">' + params[0].axisValue + '</div>';
                        var total = 0;
                        params.forEach(function (item) {
                            html += '<div style="display:flex;justify-content:space-between;gap:16px;margin:3px 0;">' +
                                '<span><span style="display:inline-block;margin-right:6px;border-radius:50%;width:8px;height:8px;background-color:' + item.color + '"></span>' + item.seriesName + '</span>' +
                                '<strong style="color:#ffffff;">' + item.value + '</strong>' +
                                '</div>';
                            total += item.value;
                        });
                        html += '<div style="margin-top:6px;padding-top:4px;border-top:1px solid ' + (dark ? '#334863' : '#334155') + ';color:#94a3b8;font-size:11px;display:flex;justify-content:space-between;">' +
                            '<span>' + self.t('totalEvents') + '</span><strong style="color:#ffffff;">' + total + '</strong>' +
                            '</div>';
                        return html;
                    }
                },
                legend: {
                    data: [this.t('monitoring'), this.t('blocked'), this.t('rateLimit'), this.t('challenge')],
                    top: 0,
                    icon: 'circle',
                    itemGap: 18,
                    textStyle: { color: legendColor, fontSize: 12, fontWeight: 550 }
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
                    axisLine: { lineStyle: { color: axisLineColor } },
                    axisLabel: { color: textColor, fontSize: 11 }
                },
                yAxis: {
                    type: 'value',
                    minInterval: 1,
                    splitLine: { lineStyle: { color: splitLineColor } },
                    axisLabel: { color: textColor, fontSize: 11 }
                },
                series: [
                    {
                        name: this.t('monitoring'),
                        type: 'line',
                        smooth: true,
                        itemStyle: { color: '#3189f7' },
                        lineStyle: { width: 2.5, color: '#3189f7' },
                        areaStyle: {
                            color: {
                                type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                                colorStops: [
                                    { offset: 0, color: 'rgba(49, 137, 247, 0.32)' },
                                    { offset: 1, color: 'rgba(49, 137, 247, 0.01)' }
                                ]
                            }
                        },
                        data: series.monitoring || []
                    },
                    {
                        name: this.t('blocked'),
                        type: 'line',
                        smooth: true,
                        itemStyle: { color: '#e24e6c' },
                        lineStyle: { width: 2.5, color: '#e24e6c' },
                        areaStyle: {
                            color: {
                                type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                                colorStops: [
                                    { offset: 0, color: 'rgba(226, 78, 108, 0.32)' },
                                    { offset: 1, color: 'rgba(226, 78, 108, 0.01)' }
                                ]
                            }
                        },
                        data: series.blocked || []
                    },
                    {
                        name: this.t('rateLimit'),
                        type: 'line',
                        smooth: true,
                        itemStyle: { color: '#eea63c' },
                        lineStyle: { width: 2.5, color: '#eea63c' },
                        areaStyle: {
                            color: {
                                type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                                colorStops: [
                                    { offset: 0, color: 'rgba(238, 166, 60, 0.32)' },
                                    { offset: 1, color: 'rgba(238, 166, 60, 0.01)' }
                                ]
                            }
                        },
                        data: series.throttled || []
                    },
                    {
                        name: this.t('challenge'),
                        type: 'line',
                        smooth: true,
                        itemStyle: { color: '#7c8cf8' },
                        lineStyle: { width: 2.5, color: '#7c8cf8' },
                        areaStyle: {
                            color: {
                                type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                                colorStops: [
                                    { offset: 0, color: 'rgba(124, 140, 248, 0.32)' },
                                    { offset: 1, color: 'rgba(124, 140, 248, 0.01)' }
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
            var dark = this.isDark();
            var self = this;

            if (total === 0 || actions.isEmpty) {
                chart.setOption({
                    backgroundColor: 'transparent',
                    title: {
                        text: this.t('noData'),
                        subtext: this.t('noDataEvents'),
                        left: 'center',
                        top: 'center',
                        textStyle: { color: dark ? '#dce8ff' : '#64748b', fontSize: 16, fontWeight: 'normal' },
                        subtextStyle: { color: dark ? '#a0b2d6' : '#94a3b8', fontSize: 12 }
                    }
                });
                return;
            }

            var colorMap = {
                'Мониторинг': '#3189f7',
                'Monitored': '#3189f7',
                'Заблокировано': '#e24e6c',
                'Blocked': '#e24e6c',
                'Rate Limit': '#eea63c',
                'Проверки (Challenge)': '#7c8cf8',
                'Challenges': '#7c8cf8',
                'Другие события': '#7b93be',
                'Other Events': '#7b93be'
            };

            var pieData = items.map(function (it) {
                return {
                    name: it.name,
                    value: it.value,
                    itemStyle: { color: colorMap[it.name] || it.color }
                };
            });

            var option = {
                backgroundColor: 'transparent',
                title: {
                    text: total,
                    subtext: this.t('journalEvents'),
                    left: 'center',
                    top: '38%',
                    textStyle: {
                        fontSize: 28,
                        fontWeight: 'bold',
                        color: dark ? '#eff4ff' : '#0f172a'
                    },
                    subtextStyle: {
                        fontSize: 12,
                        color: dark ? '#a0b2d6' : '#64748b'
                    }
                },
                tooltip: {
                    trigger: 'item',
                    backgroundColor: dark ? '#17243c' : '#1e293b',
                    borderColor: dark ? '#334863' : '#334155',
                    borderWidth: 1,
                    textStyle: { color: '#f8fafc', fontSize: 12 },
                    borderRadius: 9,
                    padding: [10, 14],
                    formatter: function (params) {
                        return '<div style="font-weight:700;color:#e2e8f0;margin-bottom:4px;">' + params.name + '</div>' +
                            '<div style="display:flex;justify-content:space-between;gap:14px;"><span>' + (self.lang === 'ru' ? 'Событий:' : 'Events:') + '</span><strong>' + params.value + '</strong></div>' +
                            '<div style="display:flex;justify-content:space-between;gap:14px;"><span>' + self.t('shareInJournal') + '</span><strong>' + params.percent + '%</strong></div>' +
                            '<div style="font-size:10px;color:#94a3b8;margin-top:6px;border-top:1px solid ' + (dark ? '#334863' : '#334155') + ';padding-top:3px;">' + self.t('shareNote') + '</div>';
                    }
                },
                legend: {
                    bottom: 0,
                    left: 'center',
                    icon: 'circle',
                    itemGap: 14,
                    textStyle: { color: dark ? '#dce8ff' : '#475569', fontSize: 11, fontWeight: 550 }
                },
                series: [
                    {
                        name: 'Actions',
                        type: 'pie',
                        radius: ['52%', '74%'],
                        center: ['50%', '45%'],
                        avoidLabelOverlap: true,
                        itemStyle: {
                            borderRadius: 8,
                            borderColor: dark ? '#1d2c46' : '#ffffff',
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
            var dark = this.isDark();
            var self = this;

            if (items.length === 0 || reasons.isEmpty) {
                chart.setOption({
                    backgroundColor: 'transparent',
                    title: {
                        text: this.t('noData'),
                        subtext: this.t('noDataTriggers'),
                        left: 'center',
                        top: 'center',
                        textStyle: { color: dark ? '#dce8ff' : '#64748b', fontSize: 16, fontWeight: 'normal' },
                        subtextStyle: { color: dark ? '#a0b2d6' : '#94a3b8', fontSize: 12 }
                    }
                });
                return;
            }

            var categories = items.map(function (it) { return it.label; });
            var values = items.map(function (it) { return it.count; });

            var textColor = dark ? '#a0b2d6' : '#64748b';
            var splitLineColor = dark ? '#243756' : '#f1f5fa';
            var axisLineColor = dark ? '#3c5272' : '#cbd5e1';
            var categoryColor = dark ? '#dce8ff' : '#334155';
            var trackColor = dark ? '#243756' : '#f8fafc';

            var option = {
                backgroundColor: 'transparent',
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: dark ? '#17243c' : '#1e293b',
                    borderColor: dark ? '#334863' : '#334155',
                    borderWidth: 1,
                    textStyle: { color: '#f8fafc', fontSize: 12 },
                    borderRadius: 9,
                    padding: [10, 14],
                    axisPointer: { type: 'shadow' },
                    formatter: function (params) {
                        var p = params[0];
                        return '<div style="font-weight:700;color:#e2e8f0;margin-bottom:4px;">' + p.name + '</div>' +
                            '<div style="display:flex;justify-content:space-between;gap:14px;"><span>' + self.t('triggers') + '</span><strong style="color:#ffffff;">' + p.value + '</strong></div>';
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
                    splitLine: { lineStyle: { color: splitLineColor } },
                    axisLabel: { color: textColor, fontSize: 11 }
                },
                yAxis: {
                    type: 'category',
                    data: categories,
                    axisLine: { lineStyle: { color: axisLineColor } },
                    axisLabel: {
                        color: categoryColor,
                        fontSize: 11,
                        fontWeight: 500,
                        formatter: function (val) {
                            return val.length > 24 ? val.substr(0, 22) + '…' : val;
                        }
                    }
                },
                series: [
                    {
                        name: 'Triggers',
                        type: 'bar',
                        data: values,
                        showBackground: true,
                        backgroundStyle: {
                            color: trackColor,
                            borderRadius: [0, 6, 6, 0]
                        },
                        itemStyle: {
                            color: {
                                type: 'linear', x: 0, y: 0, x2: 1, y2: 0,
                                colorStops: [
                                    { offset: 0, color: '#3189f7' },
                                    { offset: 1, color: '#4f46e5' }
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
            var dark = this.isDark();
            var self = this;

            if (items.length === 0 || routes.isEmpty) {
                chart.setOption({
                    backgroundColor: 'transparent',
                    title: {
                        text: this.t('noData'),
                        subtext: this.t('noDataRoutes'),
                        left: 'center',
                        top: 'center',
                        textStyle: { color: dark ? '#dce8ff' : '#64748b', fontSize: 16, fontWeight: 'normal' },
                        subtextStyle: { color: dark ? '#a0b2d6' : '#94a3b8', fontSize: 12 }
                    }
                });
                return;
            }

            var categories = items.map(function (it) { return it.route; });
            var values = items.map(function (it) { return it.count; });

            var textColor = dark ? '#a0b2d6' : '#64748b';
            var splitLineColor = dark ? '#243756' : '#f1f5fa';
            var axisLineColor = dark ? '#3c5272' : '#cbd5e1';
            var categoryColor = dark ? '#dce8ff' : '#334155';
            var trackColor = dark ? '#243756' : '#f8fafc';

            var option = {
                backgroundColor: 'transparent',
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: dark ? '#17243c' : '#1e293b',
                    borderColor: dark ? '#334863' : '#334155',
                    borderWidth: 1,
                    textStyle: { color: '#f8fafc', fontSize: 12 },
                    borderRadius: 9,
                    padding: [10, 14],
                    axisPointer: { type: 'shadow' },
                    formatter: function (params) {
                        var p = params[0];
                        var safeRoute = (p.name || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        return '<div style="font-family:monospace;font-size:12px;color:#e2e8f0;margin-bottom:4px;">' + safeRoute + '</div>' +
                            '<div style="display:flex;justify-content:space-between;gap:14px;"><span>' + self.t('suspiciousEvents') + '</span><strong style="color:#e24e6c;">' + p.value + '</strong></div>';
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
                    splitLine: { lineStyle: { color: splitLineColor } },
                    axisLabel: { color: textColor, fontSize: 11 }
                },
                yAxis: {
                    type: 'category',
                    data: categories,
                    axisLine: { lineStyle: { color: axisLineColor } },
                    axisLabel: {
                        color: categoryColor,
                        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                        fontSize: 11,
                        formatter: function (val) {
                            return val.length > 28 ? val.substr(0, 26) + '…' : val;
                        }
                    }
                },
                series: [
                    {
                        name: 'Events',
                        type: 'bar',
                        data: values,
                        showBackground: true,
                        backgroundStyle: {
                            color: trackColor,
                            borderRadius: [0, 6, 6, 0]
                        },
                        itemStyle: {
                            color: {
                                type: 'linear', x: 0, y: 0, x2: 1, y2: 0,
                                colorStops: [
                                    { offset: 0, color: '#e24e6c' },
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
         * Watches document theme changes (html.dark or body.dark) and re-renders charts.
         */
        setupThemeObserver: function () {
            var self = this;
            if (this.themeObserver) return;

            var target = document.documentElement;
            this.themeObserver = new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    if (mutations[i].attributeName === 'class') {
                        if (self.lastData) {
                            self.renderAll(self.lastData);
                        }
                        break;
                    }
                }
            });

            this.themeObserver.observe(target, { attributes: true, attributeFilter: ['class'] });
            if (document.body && document.body !== target) {
                this.themeObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
            }
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
