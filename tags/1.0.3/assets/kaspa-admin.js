/**
 * Kaspa Admin Dashboard JavaScript - Updated for Mnemonic System
 * Removed WebSocket dependencies, focused on wallet management
 */

(function ($) {
    'use strict';

    // Global Kaspa admin object
    window.KaspaAdmin = {
        // Configuration
        config: {
            refreshInterval: 30000, // 30 seconds
            chartColors: {
                primary: '#70D0F0',
                secondary: '#4FC3F7',
                success: '#00a32a',
                warning: '#dba617',
                error: '#d63384'
            }
        },

        // State management
        state: {
            autoRefresh: true,
            currentPeriod: '30',
            lastRefresh: null,
            walletConfigured: false
        },

        // Initialize the admin interface
        init: function () {
            console.log('🚀 Kaspa Admin Dashboard initializing...');

            this.bindEvents();
            this.checkSystemStatus();
            this.initializeCharts();
            this.startAutoRefresh();

            console.log('✅ Kaspa Admin Dashboard initialized');
        },

        // Bind event handlers
        bindEvents: function () {
            // Analytics period selector
            $(document).on('change', '#kaspa-analytics-period', this.handlePeriodChange.bind(this));

            // Refresh buttons
            $(document).on('click', '[data-kaspa-action="refresh"]', this.handleRefresh.bind(this));

            // Auto-refresh toggle
            $(document).on('change', '#kaspa-auto-refresh', this.handleAutoRefreshToggle.bind(this));

            // Export data
            $(document).on('click', '[data-kaspa-action="export"]', this.handleExport.bind(this));

            // Search/filter
            $(document).on('input', '.kaspa-search-input', this.handleSearch.bind(this));

            // Order actions
            $(document).on('click', '.kaspa-order-action', this.handleOrderAction.bind(this));
        },

        // Check system status (simplified - no HD wallet server)
        checkSystemStatus: function () {
            console.log('🔍 Checking Kaspa system status...');

            // Check payment gateway status
            this.checkGatewayStatus();

            // Check wallet configuration
            this.checkWalletStatus();
        },

        // Check payment gateway status
        checkGatewayStatus: function () {
            const gatewayStatusEl = $('#gateway-status');
            if (gatewayStatusEl.length) {
                $.ajax({
                    url: kaspaAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'kaspa_check_gateway_status',
                        nonce: kaspaAdmin.nonce
                    },
                    success: (response) => {
                        if (response.success && response.data.enabled) {
                            this.updateStatusIndicator(gatewayStatusEl, 'success', 'Active');
                        } else {
                            this.updateStatusIndicator(gatewayStatusEl, 'warning', 'Disabled');
                        }
                    },
                    error: () => {
                        this.updateStatusIndicator(gatewayStatusEl, 'error', 'Check Failed');
                    }
                });
            }
        },

        // Check wallet configuration status
        checkWalletStatus: function () {
            const walletStatusEl = $('#wallet-status');
            if (walletStatusEl.length) {
                // Simple check based on page content or AJAX call
                if (window.location.href.includes('kaspa-wallet-setup')) {
                    // We're on the setup page, check if wallet is configured
                    if ($('.kaspa-wallet-dashboard').length > 0) {
                        this.updateStatusIndicator(walletStatusEl, 'success', 'Configured');
                        this.state.walletConfigured = true;
                    } else {
                        this.updateStatusIndicator(walletStatusEl, 'warning', 'Not Configured');
                        this.state.walletConfigured = false;
                    }
                }
            }
        },

        // Update status indicator
        updateStatusIndicator: function (element, status, text) {
            const indicator = element.find('.kaspa-status-indicator');
            const textEl = element.find('.kaspa-status-text');

            // Remove existing status classes
            indicator.removeClass('loading success error warning');

            // Add new status
            indicator.addClass(status);

            // Update icon and text
            switch (status) {
                case 'loading':
                    indicator.text('🔄');
                    break;
                case 'success':
                    indicator.text('✅');
                    break;
                case 'error':
                    indicator.text('❌');
                    break;
                case 'warning':
                    indicator.text('⚠️');
                    break;
            }

            if (textEl.length) {
                textEl.text(text);
            }
        },

        // Initialize charts (simplified)
        initializeCharts: function () {
            if (typeof Chart === 'undefined') {
                console.log('📊 Chart.js not loaded, skipping chart initialization');
                return;
            }

            this.initRevenueChart();
        },

        // Initialize revenue chart
        initRevenueChart: function () {
            const ctx = document.getElementById('kaspa-revenue-chart');
            if (!ctx) return;

            // Sample data - replace with real data from PHP
            const data = {
                labels: this.getLast30Days(),
                datasets: [{
                    label: 'Revenue (KAS)',
                    data: this.generateSampleRevenueData(),
                    borderColor: this.config.chartColors.primary,
                    backgroundColor: this.config.chartColors.primary + '20',
                    fill: true,
                    tension: 0.4
                }]
            };

            new Chart(ctx, {
                type: 'line',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Revenue (KAS)'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function (context) {
                                    return `Revenue: ${context.parsed.y} KAS`;
                                }
                            }
                        }
                    }
                }
            });
        },

        // Get last 30 days for chart labels
        getLast30Days: function () {
            const days = [];
            for (let i = 29; i >= 0; i--) {
                const date = new Date();
                date.setDate(date.getDate() - i);
                days.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
            }
            return days;
        },

        // Generate sample revenue data (replace with real data)
        generateSampleRevenueData: function () {
            const data = [];
            for (let i = 0; i < 30; i++) {
                data.push(Math.random() * 500 + 100);
            }
            return data;
        },

        // Start auto-refresh
        startAutoRefresh: function () {
            if (!this.state.autoRefresh) return;

            this.refreshInterval = setInterval(() => {
                if (this.state.autoRefresh) {
                    this.refreshDashboardData();
                }
            }, this.config.refreshInterval);

            console.log(`🔄 Auto-refresh started (${this.config.refreshInterval / 1000}s interval)`);
        },

        // Stop auto-refresh
        stopAutoRefresh: function () {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
                console.log('⏹️ Auto-refresh stopped');
            }
        },

        // Refresh dashboard data
        refreshDashboardData: function () {
            console.log('🔄 Refreshing dashboard data...');

            $.ajax({
                url: kaspaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'kasppaga_get_stats',
                    nonce: kaspaAdmin.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.updateDashboardStats(response.data);
                        this.state.lastRefresh = new Date();
                        this.updateLastRefreshTime();
                        console.log('✅ Dashboard data refreshed');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('❌ Failed to refresh dashboard data:', error);
                    this.showNotification('Failed to refresh data', 'error');
                }
            });
        },

        // Update dashboard statistics
        updateDashboardStats: function (stats) {
            // Update stat cards
            $('.kaspa-stat-card').each(function () {
                const card = $(this);
                const statType = card.data('stat-type');

                if (stats[statType] !== undefined) {
                    card.find('h3').text(stats[statType]);
                }
            });

            // Update status indicators
            this.checkSystemStatus();

            // Refresh recent orders
            this.refreshRecentOrders();
        },

        // Refresh recent orders
        refreshRecentOrders: function () {
            $.ajax({
                url: kaspaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'kaspa_get_recent_orders',
                    nonce: kaspaAdmin.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.updateRecentOrdersTable(response.data);
                    }
                }
            });
        },

        // Update recent orders table
        updateRecentOrdersTable: function (orders) {
            const tbody = $('.kaspa-orders-table tbody');
            if (!tbody.length) return;

            tbody.empty();

            orders.forEach(order => {
                const row = $(`
                    <tr>
                        <td>
                            <strong>#${order.id}</strong><br>
                            <small>${order.date}</small>
                        </td>
                        <td>${order.customer}</td>
                        <td>
                            <strong>${order.kas_amount} KAS</strong><br>
                            <small>$${order.usd_amount}</small>
                        </td>
                        <td>
                            <span class="kaspa-status-badge ${order.status}">
                                ${order.status.charAt(0).toUpperCase() + order.status.slice(1)}
                            </span>
                        </td>
                        <td>${order.confirmation_time}</td>
                        <td>
                            <a href="${order.edit_url}" class="button button-small">View</a>
                        </td>
                    </tr>
                `);
                tbody.append(row);
            });
        },

        // Update last refresh time
        updateLastRefreshTime: function () {
            const lastRefreshEl = $('#kaspa-last-refresh');
            if (lastRefreshEl.length && this.state.lastRefresh) {
                const timeString = this.state.lastRefresh.toLocaleTimeString();
                lastRefreshEl.text(`Last updated: ${timeString}`);
            }
        },

        // Event handlers
        handlePeriodChange: function (e) {
            this.state.currentPeriod = $(e.target).val();
            console.log(`📅 Analytics period changed to: ${this.state.currentPeriod} days`);
            this.refreshAnalytics();
        },

        handleRefresh: function (e) {
            e.preventDefault();
            const button = $(e.target);
            const originalText = button.text();

            button.prop('disabled', true).text('Refreshing...');

            this.refreshDashboardData();

            setTimeout(() => {
                button.prop('disabled', false).text(originalText);
            }, 2000);
        },

        handleAutoRefreshToggle: function (e) {
            this.state.autoRefresh = $(e.target).is(':checked');

            if (this.state.autoRefresh) {
                this.startAutoRefresh();
                this.showNotification('Auto-refresh enabled', 'success');
            } else {
                this.stopAutoRefresh();
                this.showNotification('Auto-refresh disabled', 'info');
            }
        },

        handleExport: function (e) {
            e.preventDefault();
            const exportType = $(e.target).data('export-type');

            this.showNotification('Preparing export...', 'info');
            console.log(`📤 Exporting ${exportType} data`);
        },

        handleSearch: function (e) {
            const searchTerm = $(e.target).val().toLowerCase();
            const table = $(e.target).closest('.kaspa-search-container').find('table');

            table.find('tbody tr').each(function () {
                const row = $(this);
                const text = row.text().toLowerCase();

                if (text.includes(searchTerm)) {
                    row.show();
                } else {
                    row.hide();
                }
            });
        },

        handleOrderAction: function (e) {
            e.preventDefault();
            const action = $(e.target).data('action');
            const orderId = $(e.target).data('order-id');

            console.log(`🔧 Order action: ${action} for order #${orderId}`);

            switch (action) {
                case 'check-payment':
                    this.checkOrderPayment(orderId);
                    break;
                case 'mark-paid':
                    this.markOrderPaid(orderId);
                    break;
            }
        },

        // Utility functions
        showNotification: function (message, type = 'info') {
            const notification = $(`
                <div class="kaspa-notification kaspa-notification-${type}">
                    <span>${message}</span>
                    <button type="button" class="kaspa-notification-close">&times;</button>
                </div>
            `);

            $('body').append(notification);

            // Auto-remove after 5 seconds
            setTimeout(() => {
                notification.fadeOut(() => notification.remove());
            }, 5000);

            // Manual close
            notification.find('.kaspa-notification-close').on('click', function () {
                notification.fadeOut(() => notification.remove());
            });
        },

        // Order-specific actions
        checkOrderPayment: function (orderId) {
            this.showNotification('Checking payment status...', 'info');

            $.ajax({
                url: kaspaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'kasppaga_check_payment',
                    order_id: orderId,
                    nonce: kaspaAdmin.nonce
                },
                success: (response) => {
                    if (response.success) {
                        if (response.data.status === 'completed') {
                            this.showNotification('Payment confirmed!', 'success');
                            this.refreshRecentOrders();
                        } else {
                            this.showNotification('Payment still pending', 'warning');
                        }
                    } else {
                        this.showNotification('Failed to check payment', 'error');
                    }
                }
            });
        },

        markOrderPaid: function (orderId) {
            if (!confirm('Are you sure you want to manually mark this order as paid?')) {
                return;
            }

            $.ajax({
                url: kaspaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'kasppaga_manual_verify',
                    order_id: orderId,
                    nonce: kaspaAdmin.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotification('Order marked as paid', 'success');
                        this.refreshRecentOrders();
                    } else {
                        this.showNotification('Failed to mark order as paid', 'error');
                    }
                }
            });
        },

        // Analytics functions
        refreshAnalytics: function () {
            $.ajax({
                url: kaspaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'kaspa_get_analytics',
                    period: this.state.currentPeriod,
                    nonce: kaspaAdmin.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.updateAnalyticsData(response.data);
                        console.log('📊 Analytics data refreshed');
                    }
                }
            });
        },

        updateAnalyticsData: function (data) {
            // Update analytics metrics
            $('.kaspa-metric-value').each(function () {
                const metric = $(this);
                const metricType = metric.data('metric-type');

                if (data[metricType] !== undefined) {
                    metric.text(data[metricType]);
                }
            });

            // Update charts if Chart.js is available
            if (typeof Chart !== 'undefined') {
                this.updateCharts(data);
            }
        },

        updateCharts: function (data) {
            // Update revenue chart
            const revenueChart = Chart.getChart('kaspa-revenue-chart');
            if (revenueChart && data.revenue_data) {
                revenueChart.data.labels = data.revenue_data.labels;
                revenueChart.data.datasets[0].data = data.revenue_data.values;
                revenueChart.update();
            }
        }
    };

    // Global functions for inline onclick handlers
    window.kaspaRefreshStats = function () {
        KaspaAdmin.refreshDashboardData();
    };

    window.kaspaRefreshAnalytics = function () {
        KaspaAdmin.refreshAnalytics();
    };

    // Initialize when document is ready
    $(document).ready(function () {
        // Only initialize on Kaspa admin pages
        if (window.location.href.includes('kaspa-')) {
            KaspaAdmin.init();
        }
    });

    // Handle page visibility changes (pause auto-refresh when tab is hidden)
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            KaspaAdmin.stopAutoRefresh();
            console.log('⏸️ Page hidden - paused auto-refresh');
        } else {
            if (KaspaAdmin.state.autoRefresh) {
                KaspaAdmin.startAutoRefresh();
                console.log('▶️ Page visible - resumed auto-refresh');
            }
        }
    });

})(jQuery);