<?php
/**
 * Clean Kaspa Admin Dashboard
 * Simplified version with just the essentials
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class KASPPAGA_Admin_Dashboard
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_kasppaga_get_stats', array($this, 'ajax_get_stats'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        // Main menu
        add_menu_page(
            'Kaspa Payments Gateway',
            'Kaspa Payments Gateway',
            'manage_woocommerce',
            'kaspa-payments-gateway',
            array($this, 'render_dashboard_page'),
            $this->get_kaspa_icon(),
            56
        );

        // Sub-menu: Analytics
        add_submenu_page(
            'kaspa-payments-gateway',
            'Analytics',
            'Analytics',
            'manage_woocommerce',
            'kaspa-analytics',
            array($this, 'render_analytics_page')
        );
    }

    /**
     * Main Dashboard Page
     */
    public function render_dashboard_page()
    {
        $stats = $this->get_payment_stats();
        $recent_orders = $this->get_recent_kaspa_orders(5);
        ?>
        <div class="wrap kaspa-admin-dashboard">
            <h1>💎 Kaspa Payments Gateway Dashboard</h1>

            <!-- Stats Cards -->
            <div class="kaspa-stats-grid">
                <div class="kaspa-stat-card">
                    <div class="kaspa-stat-icon">💰</div>
                    <div class="kaspa-stat-content">
                        <h3><?php echo esc_html($stats['total_revenue_kas']); ?> KAS</h3>
                        <p>Total Revenue</p>
                        <small>$<?php echo number_format($stats['total_revenue_usd'], 2); ?> USD</small>
                    </div>
                </div>

                <div class="kaspa-stat-card">
                    <div class="kaspa-stat-icon">📦</div>
                    <div class="kaspa-stat-content">
                        <h3><?php echo esc_html($stats['total_orders']); ?></h3>
                        <p>Total Orders</p>
                        <small><?php echo esc_html($stats['orders_this_month']); ?> this month</small>
                    </div>
                </div>

                <div class="kaspa-stat-card">
                    <div class="kaspa-stat-icon">⚡</div>
                    <div class="kaspa-stat-content">
                        <h3><?php echo esc_html($stats['success_rate']); ?>%</h3>
                        <p>Success Rate</p>
                        <small><?php echo esc_html($stats['total_attempts']); ?> attempts</small>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="kaspa-quick-actions" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 8px;">
                <h2 style="margin-top: 0; font-size: 16px;">⚡ Quick Actions</h2>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=kaspa-wallet-setup')); ?>" class="button">
                        🔑 Wallet Setup
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=kaspa')); ?>" class="button button-primary">
                        ⚙️ Payment Settings
                    </a>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order&payment_method=kaspa')); ?>" class="button">
                        📦 View All Orders
                    </a>
                </div>
            </div>

            <!-- Wallet Balance Section -->
            <?php $this->render_wallet_balance_section(); ?>

            <!-- Recent Orders -->
            <div class="kaspa-recent-orders">
                <h2>📋 Recent Kaspa Orders</h2>
                <div class="kaspa-orders-table">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_orders)): ?>
                                <?php foreach ($recent_orders as $order): ?>
                                    <tr>
                                        <td><strong>#<?php echo esc_html($order['id']); ?></strong></td>
                                        <td><?php echo esc_html($order['customer']); ?></td>
                                        <td>
                                            <strong><?php echo esc_html($order['kas_amount']); ?> KAS</strong><br>
                                            <small>$<?php echo esc_html($order['usd_amount']); ?></small>
                                        </td>
                                        <td>
                                            <span class="kaspa-status-badge <?php echo esc_attr($order['status']); ?>">
                                                <?php echo esc_html(ucfirst($order['status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($order['date']); ?></td>
                                        <td>
                                            <a href="<?php echo esc_url($order['edit_url']); ?>" class="button button-small">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 40px; color: #666;">
                                        <div style="font-size: 32px; margin-bottom: 16px;">💎</div>
                                        <h4>No Kaspa Orders Yet</h4>
                                        <p>Once you start receiving Kaspa payments, they'll appear here.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($recent_orders)): ?>
                    <p><a href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order&payment_method=kaspa')); ?>"
                            class="button">View
                            All Kaspa Orders →</a></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        // Add inline script for stats refresh
        $inline_script = "jQuery(document).ready(function ($) {
            setInterval(function () {
                $.post(ajaxurl, { action: 'kasppaga_get_stats' }, function (response) {
                    if (response.success) {
                        console.log('Stats refreshed');
                    }
                });
            }, 30000);
        });";
        wp_add_inline_script('kaspa-admin-script', $inline_script);
    }

    /**
     * Analytics Page
     */
    public function render_analytics_page()
    {
        $analytics = $this->get_analytics_data();
        ?>
        <div class="wrap kaspa-analytics">
            <h1>📊 Kaspa Payment Analytics</h1>

            <!-- Key Metrics -->
            <div class="kaspa-analytics-grid">
                <div class="kaspa-analytics-card">
                    <h3>⚡ Payment Performance</h3>
                    <div class="kaspa-metrics-list">
                        <div class="kaspa-metric">
                            <span class="kaspa-metric-label">Average Order Value:</span>
                            <span class="kaspa-metric-value"><?php echo esc_html($analytics['avg_order_value']); ?> KAS</span>
                        </div>
                        <div class="kaspa-metric">
                            <span class="kaspa-metric-label">Success Rate:</span>
                            <span class="kaspa-metric-value"><?php echo esc_html($analytics['success_rate']); ?>%</span>
                        </div>
                        <div class="kaspa-metric">
                            <span class="kaspa-metric-label">Total Volume:</span>
                            <span class="kaspa-metric-value"><?php echo esc_html($analytics['total_volume']); ?> KAS</span>
                        </div>
                    </div>
                </div>

                <div class="kaspa-analytics-card">
                    <h3>👥 Customer Insights</h3>
                    <div class="kaspa-metrics-list">
                        <div class="kaspa-metric">
                            <span class="kaspa-metric-label">Unique Customers:</span>
                            <span class="kaspa-metric-value"><?php echo esc_html($analytics['unique_customers']); ?></span>
                        </div>
                        <div class="kaspa-metric">
                            <span class="kaspa-metric-label">Repeat Customers:</span>
                            <span class="kaspa-metric-value"><?php echo esc_html($analytics['repeat_customers']); ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Trends -->
            <?php if (!empty($analytics['daily_trends'])): ?>
                <h3>📈 Recent Activity (Last 7 Days)</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Orders</th>
                            <th>Revenue (KAS)</th>
                            <th>Revenue (USD)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($analytics['daily_trends'], -7) as $day): ?>
                            <tr>
                                <td><?php echo esc_html($day['date']); ?></td>
                                <td><?php echo esc_html($day['orders']); ?></td>
                                <td><?php echo esc_html($day['revenue_kas']); ?></td>
                                <td>$<?php echo esc_html($day['revenue_usd']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; background: #f9f9f9; border-radius: 8px; color: #666;">
                    <div style="font-size: 32px; margin-bottom: 16px;">📊</div>
                    <h4>No Analytics Data Yet</h4>
                    <p>Start receiving Kaspa payments to see detailed analytics here.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // Customize page removed - streamlined version without customization options

    /**
     * Get payment statistics
     */
    private function get_payment_stats()
    {
        $orders = wc_get_orders(array(
            'payment_method' => 'kaspa',
            'limit' => -1,
            'status' => array('completed', 'processing')
        ));

        $all_attempts = wc_get_orders(array(
            'payment_method' => 'kaspa',
            'limit' => -1
        ));

        $stats = array(
            'total_orders' => count($orders),
            'total_revenue_kas' => 0,
            'total_revenue_usd' => 0,
            'orders_this_month' => 0,
            'success_rate' => 0,
            'total_attempts' => count($all_attempts)
        );

        $month_start = strtotime('first day of this month');

        foreach ($orders as $order) {
            $kas_amount = $order->get_meta('_kaspa_confirmed_amount') ?: $order->get_meta('_kaspa_expected_amount');
            $stats['total_revenue_kas'] += floatval($kas_amount);
            $stats['total_revenue_usd'] += floatval($order->get_total());

            if ($order->get_date_created()->getTimestamp() >= $month_start) {
                $stats['orders_this_month']++;
            }
        }

        // Calculate success rate
        if (count($all_attempts) > 0) {
            $stats['success_rate'] = round((count($orders) / count($all_attempts)) * 100, 1);
        }

        return $stats;
    }

    /**
     * Get recent orders
     */
    private function get_recent_kaspa_orders($limit = 10)
    {
        $orders = wc_get_orders(array(
            'payment_method' => 'kaspa',
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC'
        ));

        $recent_orders = array();
        foreach ($orders as $order) {
            $recent_orders[] = array(
                'id' => $order->get_id(),
                'date' => $order->get_date_created()->format('M j, Y'),
                'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'kas_amount' => $order->get_meta('_kaspa_confirmed_amount') ?: $order->get_meta('_kaspa_expected_amount') ?: '0',
                'usd_amount' => number_format($order->get_total(), 2),
                'status' => $order->get_status(),
                'edit_url' => $order->get_edit_order_url()
            );
        }

        return $recent_orders;
    }

    /**
     * Get analytics data
     */
    private function get_analytics_data()
    {
        $orders = wc_get_orders(array(
            'payment_method' => 'kaspa',
            'limit' => -1,
            'status' => array('completed', 'processing')
        ));

        $all_attempts = wc_get_orders(array(
            'payment_method' => 'kaspa',
            'limit' => -1
        ));

        $total_revenue_kas = 0;
        $unique_customers = array();
        $daily_data = array();

        foreach ($orders as $order) {
            $kas_amount = floatval($order->get_meta('_kaspa_confirmed_amount') ?: $order->get_meta('_kaspa_expected_amount'));
            $total_revenue_kas += $kas_amount;

            // Track unique customers
            $customer_email = $order->get_billing_email();
            if ($customer_email && !in_array($customer_email, $unique_customers)) {
                $unique_customers[] = $customer_email;
            }

            // Group by day
            $order_date = $order->get_date_created()->format('Y-m-d');
            if (!isset($daily_data[$order_date])) {
                $daily_data[$order_date] = array(
                    'orders' => 0,
                    'revenue_kas' => 0,
                    'revenue_usd' => 0
                );
            }
            $daily_data[$order_date]['orders']++;
            $daily_data[$order_date]['revenue_kas'] += $kas_amount;
            $daily_data[$order_date]['revenue_usd'] += floatval($order->get_total());
        }

        // Calculate metrics
        $avg_order_value = count($orders) > 0 ? $total_revenue_kas / count($orders) : 0;
        $success_rate = count($all_attempts) > 0 ? (count($orders) / count($all_attempts)) * 100 : 0;

        // Calculate repeat customers
        $customer_counts = array_count_values(array_map(function ($order) {
            return $order->get_billing_email();
        }, $orders));

        $repeat_customers = 0;
        foreach ($customer_counts as $email => $count) {
            if ($count > 1) {
                $repeat_customers++;
            }
        }
        $repeat_customer_rate = count($unique_customers) > 0 ? ($repeat_customers / count($unique_customers)) * 100 : 0;

        // Prepare daily trends
        $daily_trends = array();
        foreach ($daily_data as $date => $data) {
            $daily_trends[] = array(
                'date' => gmdate('M j', strtotime($date)),
                'orders' => $data['orders'],
                'revenue_kas' => number_format($data['revenue_kas'], 8),
                'revenue_usd' => number_format($data['revenue_usd'], 2)
            );
        }

        return array(
            'avg_order_value' => number_format($avg_order_value, 8),
            'success_rate' => number_format($success_rate, 1),
            'total_volume' => number_format($total_revenue_kas, 8),
            'unique_customers' => count($unique_customers),
            'repeat_customers' => number_format($repeat_customer_rate, 1),
            'daily_trends' => $daily_trends
        );
    }

    /**
     * Get Kaspa icon
     */
    private function get_kaspa_icon()
    {
        return 'data:image/svg+xml;base64,' . base64_encode('
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="10" cy="10" r="8" fill="#70D0F0"/>
                <text x="10" y="14" text-anchor="middle" fill="white" font-size="8" font-weight="bold">K</text>
            </svg>
        ');
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook)
    {
        if (strpos($hook, 'kaspa-') === false) {
            return;
        }

        wp_enqueue_style('kaspa-admin-style', plugin_dir_url(__DIR__) . 'assets/kaspa-admin.css', array(), '1.0.0');
        wp_enqueue_script('kaspa-admin-script', plugin_dir_url(__DIR__) . 'assets/kaspa-admin.js', array('jquery'), '1.0.0', true);
    }

    /**
     * AJAX: Get stats
     */
    public function ajax_get_stats()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $stats = $this->get_payment_stats();
        wp_send_json_success($stats);
    }

    /**
     * Render wallet balance section
     */
    public function render_wallet_balance_section()
    {
        $wallet_configured = get_option('kasppaga_wallet_configured');
        $kpub = get_option('kasppaga_wallet_kpub');

        if (!$wallet_configured || !$kpub) {
            return;
        }
        ?>
        <div class="kaspa-wallet-balance-section">
            <h2>💰 Wallet Balance</h2>
            <p style="color: #666; font-size: 14px; margin-bottom: 15px;">
                <strong>Consolidated Balance:</strong> Shows total KAS received across all order payment addresses generated by
                the plugin.
                <br><small style="color: #d63638;">⚠️ Funds sent manually to other addresses in your KPUB wallet will not appear
                    here.</small>
            </p>
            <div class="kaspa-wallet-balance-card">
                <div class="kaspa-wallet-info">
                    <div class="kaspa-wallet-address">
                        <label>KPUB Wallet:</label>
                        <div class="kaspa-address-display">
                            <?php
                            $kpub = get_option('kasppaga_wallet_kpub', '');
                            if ($kpub) {
                                $kpub_display = substr($kpub, 0, 30) . '...' . substr($kpub, -20);
                                echo '<code style="font-size: 11px;">' . esc_html($kpub_display) . '</code>';
                            } else {
                                echo '<span style="color: #d63638;">⚠️ KPUB not configured</span>';
                            }
                            ?>
                            <?php if ($kpub): ?>
                                <button type="button" class="button button-small"
                                    onclick="copyToClipboard('<?php echo esc_js($kpub); ?>')">📋 Copy KPUB</button>
                            <?php endif; ?>
                        </div>
                        <small style="color: #666; margin-top: 5px; display: block;">
                            ℹ️ Unique addresses are generated per order from your KPUB
                        </small>
                    </div>
                </div>

                <div class="kaspa-balance-display">
                    <div id="kaspa-balance-info" class="kaspa-balance-loading">
                        <div class="kaspa-loading-spinner"></div>
                        <span>Loading balance...</span>
                    </div>
                    <div class="kaspa-balance-actions">
                        <button type="button" class="button button-primary" id="kaspa-refresh-balance">
                            🔄 Refresh Balance
                        </button>
                        <small class="kaspa-last-updated" id="kaspa-last-updated"></small>
                    </div>
                </div>
            </div>
        </div>

        <?php
        // Register and enqueue script for inline JavaScript
        wp_register_script('kaspa-admin-dashboard-inline', '', array('jquery'), '1.0.0', true);
        wp_enqueue_script('kaspa-admin-dashboard-inline');

        // Prepare inline JavaScript
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        $nonce = esc_js(wp_create_nonce('kasppaga_consolidated_balance'));
        $inline_script = 'document.addEventListener("DOMContentLoaded", function () {
            // Load balance on page load
            loadWalletBalance();

            // Add refresh button event listener
            const refreshBtn = document.getElementById("kaspa-refresh-balance");
            if (refreshBtn) {
                refreshBtn.addEventListener("click", function () {
                    loadWalletBalance();
                });
            }
        });

        function loadWalletBalance() {
            const balanceInfo = document.getElementById("kaspa-balance-info");
            const lastUpdated = document.getElementById("kaspa-last-updated");
            const refreshBtn = document.getElementById("kaspa-refresh-balance");

            if (!balanceInfo || !refreshBtn) {
                return;
            }

            // Show loading state
            balanceInfo.innerHTML = "<div class=\"kaspa-loading-spinner\"></div><span>Loading consolidated balance...</span>";
            balanceInfo.className = "kaspa-balance-loading";
            refreshBtn.disabled = true;
            refreshBtn.textContent = "🔄 Loading...";

            const xhr = new XMLHttpRequest();
            xhr.open("POST", ' . json_encode($ajax_url) . ', true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    refreshBtn.disabled = false;
                    refreshBtn.textContent = "🔄 Refresh Balance";

                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                const balance = parseFloat(response.data.total_balance).toFixed(8);
                                const usdValue = response.data.total_usd_value ? "$" + parseFloat(response.data.total_usd_value).toFixed(2) : "N/A";
                                const kasRate = response.data.kas_rate ? "$" + parseFloat(response.data.kas_rate).toFixed(5) : "N/A";
                                const addressCount = response.data.address_count || 0;

                                balanceInfo.innerHTML = "<div class=\"kaspa-balance-main\"><div class=\"kaspa-balance-amount\"><span class=\"kaspa-balance-kas\">" + balance + " KAS</span><span class=\"kaspa-balance-usd\">" + usdValue + "</span></div><div class=\"kaspa-balance-details\"><small>Consolidated across " + addressCount + " addresses</small><br><small>Current Rate: " + kasRate + " per KAS</small></div></div>";
                                balanceInfo.className = "kaspa-balance-success";

                                if (lastUpdated) {
                                    lastUpdated.textContent = "Last updated: " + new Date().toLocaleString();
                                }
                            } else {
                                balanceInfo.innerHTML = "<div class=\"kaspa-balance-error\">❌ Error: " + (response.data || "Unknown error") + "</div>";
                                balanceInfo.className = "kaspa-balance-error";
                            }
                        } catch (e) {
                            balanceInfo.innerHTML = "<div class=\"kaspa-balance-error\">❌ Error parsing response</div>";
                            balanceInfo.className = "kaspa-balance-error";
                        }
                    } else {
                        balanceInfo.innerHTML = "<div class=\"kaspa-balance-error\">❌ Network error</div>";
                        balanceInfo.className = "kaspa-balance-error";
                    }
                }
            };

            const data = "action=kasppaga_get_consolidated_balance&nonce=" + ' . json_encode($nonce) . ';
            xhr.send(data);
        }

        function copyToClipboard(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    showNotification("✅ Address copied to clipboard!", "success");
                }).catch(function () {
                    fallbackCopy(text);
                });
            } else {
                fallbackCopy(text);
            }
        }

        function fallbackCopy(text) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.left = "-999999px";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                const successful = document.execCommand("copy");
                if (successful) {
                    showNotification("✅ Address copied to clipboard!", "success");
                } else {
                    showNotification("❌ Copy failed", "error");
                }
            } catch (err) {
                showNotification("❌ Copy failed", "error");
            }

            document.body.removeChild(textArea);
        }

        function showNotification(message, type) {
            const notification = document.createElement("div");
            notification.className = "kaspa-notification kaspa-notification-" + type;
            notification.textContent = message;
            notification.style.cssText = "position: fixed; top: 20px; right: 20px; background: " + (type === "success" ? "#28a745" : "#dc3545") + "; color: white; padding: 12px 20px; border-radius: 6px; z-index: 9999; font-weight: bold; box-shadow: 0 4px 12px rgba(0,0,0,0.3); transform: translateX(400px); transition: transform 0.3s ease;";

            document.body.appendChild(notification);

            setTimeout(function () {
                notification.style.transform = "translateX(0)";
            }, 100);

            setTimeout(function () {
                notification.style.transform = "translateX(400px)";
                setTimeout(function () {
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }';

        // Add inline script
        wp_add_inline_script('kaspa-admin-dashboard-inline', $inline_script);

        // Register and enqueue style for inline CSS
        wp_register_style('kaspa-admin-dashboard-inline', '', array(), '1.0.0');
        wp_enqueue_style('kaspa-admin-dashboard-inline');

        // Prepare inline CSS
        $inline_style = '.kaspa-wallet-balance-section {
            margin: 20px 0;
        }

        .kaspa-wallet-balance-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .kaspa-wallet-address {
            margin-bottom: 20px;
        }

        .kaspa-address-display {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 5px;
        }

        .kaspa-address-display code {
            flex: 1;
            background: #f8f9fa;
            padding: 8px;
            border-radius: 4px;
            font-size: 12px;
            word-break: break-all;
        }

        .kaspa-balance-display {
            text-align: center;
        }

        .kaspa-balance-main {
            margin-bottom: 15px;
        }

        .kaspa-balance-amount {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }

        .kaspa-balance-kas {
            font-size: 24px;
            font-weight: bold;
            color: #007cba;
        }

        .kaspa-balance-usd {
            font-size: 18px;
            color: #666;
        }

        .kaspa-balance-rate {
            margin-top: 10px;
        }

        .kaspa-balance-actions {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .kaspa-last-updated {
            color: #666;
            font-style: italic;
        }

        .kaspa-loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #007cba;
            border-radius: 50%;
            animation: kaspa-spin 1s linear infinite;
            margin-right: 8px;
        }

        @keyframes kaspa-spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .kaspa-balance-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
        }

        .kaspa-balance-success {
            color: #28a745;
        }

        .kaspa-balance-error {
            color: #dc3545;
        }';

        // Add inline style
        wp_add_inline_style('kaspa-admin-dashboard-inline', $inline_style);
    }
}

// Initialize
new KASPPAGA_Admin_Dashboard();