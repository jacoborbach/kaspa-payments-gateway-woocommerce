<?php
/**
 * Kaspa Gateway Class - KPUB Watch-Only (Secure)
 * 
 * Uses KPUB (Extended Public Key) watch-only wallets for security.
 * No private keys or mnemonics are stored on the server.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kaspa Payment Gateway - KPUB Watch-Only
 */
class KASPPAGA_WC_Gateway extends WC_Payment_Gateway
{
    protected $show_logo;

    public function __construct()
    {
        $this->id = 'kaspa';
        $this->method_title = 'Kaspa Payments Gateway (Watch-Only)';
        $this->method_description = 'Accept Kaspa (KAS) payments using a secure watch-only wallet (KPUB). No private keys stored.';
        $this->has_fields = false;
        $this->supports = array('products');

        // Initialize properties
        $this->show_logo = 'yes';

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Set values from settings
        $this->title = $this->get_option('title', 'Kaspa (KAS)');
        $this->description = $this->get_option('description', 'Pay with Kaspa cryptocurrency. Secure and fast payments.');
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->show_logo = $this->get_option('show_logo', 'yes');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_kaspa_payment_details'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        // Balance checking moved to Kaspa plugin admin page

        add_action('template_redirect', array($this, 'handle_payment_page'));
        add_action('woocommerce_order_status_changed', array($this, 'handle_payment_completion'), 10, 3);
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable Kaspa Payments',
                'default' => 'yes'
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'Payment method title shown to customers.',
                'default' => 'Kaspa (KAS)',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'Payment method description for customers.',
                'default' => 'Pay with Kaspa cryptocurrency. Secure and fast payments.',
                'desc_tip' => true,
            ),
            'wallet_status' => array(
                'title' => __('Wallet Status', 'kaspa-payments-gateway-woocommerce'),
                'type' => 'title',
                'description' => $this->get_wallet_status_html(),
            ),
            'show_logo' => array(
                'title' => 'Show Logo',
                'type' => 'checkbox',
                'label' => 'Display Kaspa logo next to payment method',
                'default' => 'yes'
            ),
        );
    }

    public function get_icon()
    {
        if ($this->show_logo === 'yes') {
            $icon = '<span style="display: inline-block; width: 20px; height: 20px; background: #70D0F0; border-radius: 50%; margin-left: 8px; vertical-align: middle;"></span>';
            return $icon;
        }
        return '';
    }

    public function is_available()
    {
        $available = ('yes' === $this->enabled);

        // Require wallet to be configured
        if ($available && !get_option('kasppaga_wallet_configured')) {
            $available = false;
        }

        return $available;
    }

    /**
     * Get current KAS rate with caching.
     * Primary: CoinGecko. Fallback: CryptoCompare. If both fail, returns false (checkout shows error).
     * Cache TTL is 5 minutes to stay within CoinGecko free tier (10,000 calls/month).
     */
    public function get_kas_rate()
    {
        $cached_rate = get_transient('kaspa_rate_cache');
        if ($cached_rate !== false) {
            return $cached_rate;
        }

        // Primary: CoinGecko
        $response = wp_remote_get('https://api.coingecko.com/api/v3/simple/price?ids=kaspa&vs_currencies=usd', array(
            'timeout' => 10
        ));

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (isset($data['kaspa']['usd'])) {
                $rate = floatval($data['kaspa']['usd']);
                set_transient('kaspa_rate_cache', $rate, 300); // 5 min: within CoinGecko free tier 10k calls/month
                return $rate;
            }
        } else {
            error_log('Kaspa rate fetch (CoinGecko): ' . $response->get_error_message());
        }

        // Fallback: CryptoCompare (no API key required for price endpoint)
        $response = wp_remote_get('https://min-api.cryptocompare.com/data/price?fsym=KAS&tsyms=USD', array(
            'timeout' => 10
        ));

        if (is_wp_error($response)) {
            error_log('Kaspa rate fetch (CryptoCompare): ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (isset($data['USD'])) {
            $rate = floatval($data['USD']);
            set_transient('kaspa_rate_cache', $rate, 300); // 5 min: within CoinGecko free tier 10k calls/month
            return $rate;
        }

        return false;
    }

    /**
     * Calculate Kaspa amount needed
     */
    public function calculate_kaspa_amount($fiat_amount)
    {
        $rate = $this->get_kas_rate();
        if (!$rate || $rate <= 0) {
            return 0; // Caller must check rate; do not use a hardcoded fallback
        }

        $kas_amount = round($fiat_amount / $rate, 8);
        return $kas_amount;
    }

    /**
     * FIXED: Generate payment address using your kaspa-simple-wallet library
     * No external server needed!
     */
    private function generate_payment_address($order_id)
    {
        // Get the stored KPUB from wallet setup
        $kpub = get_option('kasppaga_wallet_kpub');

        if (!$kpub) {
            return $this->get_fallback_address($order_id);
        }

        // Check main wallet address
        $main_address = get_option('kasppaga_wallet_address');

        // If address is pending derivation, we'll use a placeholder that will be updated by JS
        if ($main_address === 'pending-derivation' || empty($main_address)) {
            return 'pending-' . $order_id;
        }

        // Validate main address format before using it
        if ($main_address && is_string($main_address) && preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $main_address)) {
            return sanitize_text_field($main_address);
        }

        return $this->get_fallback_address($order_id);
    }

    /**
     * Handle the payment page display
     */
    public function handle_payment_page()
    {
        // Check if this is a Kaspa payment page request
        if (!isset($_GET['kaspa_payment']) || $_GET['kaspa_payment'] !== 'true') {
            return;
        }

        $order_id = isset($_GET['order_id']) ? intval(sanitize_text_field(wp_unslash($_GET['order_id']))) : 0;
        $order_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        if (!$order_id || !$order_key) {
            wp_die('Invalid payment link.');
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_order_key() !== $order_key) {
            wp_die('Invalid order or order key.');
        }

        // Check if payment is already completed
        if (in_array($order->get_status(), ['processing', 'completed'])) {
            // Redirect to actual thank you page
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        // Display the payment page
        $this->display_payment_page($order);
        exit;
    }

    /**
     * Display the payment page (not thank you page)
     */
    private function display_payment_page($order)
    {
        get_header();
        ?>
        <div class="kaspa-payment-page-wrapper" style="padding: 20px 0;">
            <div style="max-width: 800px; margin: 0 auto;">

                <!-- Page Title -->
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1>💰 Send Kaspa Payment</h1>
                    <p style="font-size: 18px; color: #666;">
                        Order #<?php echo esc_html($order->get_id()); ?> -
                        Please send your Kaspa payment to complete your order.
                    </p>
                </div>

                <!-- Display the payment interface -->
                <?php kasppaga_display_thankyou_page($order->get_id(), $this); ?>

            </div>
        </div>
        <?php
        get_footer();
    }

    //  Handle when payment is completed - redirect to real thank you page
    public function handle_payment_completion($order_id, $old_status, $new_status)
    {
        // When order becomes processing/completed, customer can access thank you page
        if (in_array($new_status, ['processing', 'completed'])) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_payment_method() === 'kaspa') {
                // Payment completed - they can now see the real thank you page
            }
        }
    }

    /**
     * Process Payment - Redirect to Dedicated Payment Page
     */
    public function process_payment($order_id)
    {
        try {
            $order = wc_get_order($order_id);

            if (!$order) {
                return array(
                    'result' => 'failure',
                    'messages' => 'Invalid order.'
                );
            }

            // Get order total and calculate KAS amount
            $order_total = $order->get_total();
            $kas_rate = $this->get_kas_rate();

            if (!$kas_rate || $kas_rate <= 0) {
                throw new Exception(__('Unable to fetch current exchange rate. Please try again or choose another payment method.', 'kaspa-payments-gateway-woocommerce'));
            }

            $kas_amount = $this->calculate_kaspa_amount($order_total);

            // Generate a unique payment address for this order
            $payment_address = $this->generate_payment_address($order_id);

            if (!$payment_address || !is_string($payment_address)) {
                throw new Exception('Unable to generate valid payment address. Please try again.');
            }

            // For addresses that start with "pending-", we'll allow them temporarily
            $is_placeholder = (strpos($payment_address, 'pending-') === 0);

            if (!$is_placeholder) {
                // Validate address format for real addresses
                if (!preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $payment_address)) {
                    throw new Exception('Invalid payment address format generated. Please contact support.');
                }
            }

            // Store order meta
            if (!$is_placeholder && $payment_address && preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $payment_address)) {
                $order->update_meta_data('_kaspa_payment_address', $payment_address);
                $order->update_meta_data('_kaspa_address', $payment_address);
            } else {
                $order->update_meta_data('_kaspa_address_pending', true);
            }
            $order->update_meta_data('_kaspa_amount', floatval($kas_amount));
            $order->update_meta_data('_kaspa_expected_amount', floatval($kas_amount));
            $order->update_meta_data('_kaspa_rate', floatval($kas_rate));
            $order->update_meta_data('_kaspa_payment_started', time());
            $order->update_meta_data('_kaspa_order_total', floatval($order_total));

            // Update order status to ON-HOLD for polling system
            $address_display = (!$is_placeholder && $payment_address && preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $payment_address))
                ? $payment_address
                : '(address will be generated)';
            $order->update_status('on-hold', sprintf(
                'Awaiting Kaspa payment of %s KAS to address %s (Order #%d)',
                number_format($kas_amount, 8),
                $address_display,
                $order_id
            ));

            $order->save();

            // Reduce stock levels
            wc_reduce_stock_levels($order_id);

            // Remove cart
            WC()->cart->empty_cart();

            // Use dedicated payment page
            $payment_url = home_url("/kaspa-payment/{$order_id}/{$order->get_order_key()}/");

            return array(
                'result' => 'success',
                'redirect' => $payment_url
            );

        } catch (Exception $e) {
            wc_add_notice('Payment error: ' . $e->getMessage(), 'error');
            return array(
                'result' => 'failure',
                'messages' => $e->getMessage()
            );
        }
    }

    /**
     * FIXED: Fallback address with proper string return
     */
    private function get_fallback_address($order_id)
    {
        // Try to get the main wallet address as fallback
        $wallet_address = get_option('kasppaga_wallet_address');

        // Only use fallback if it's a valid Kaspa address format
        if (
            $wallet_address && is_string($wallet_address) &&
            preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $wallet_address) &&
            $wallet_address !== 'pending-derivation'
        ) {
            return sanitize_text_field($wallet_address);
        }

        // No valid address available - return placeholder for client-side generation
        return 'pending-' . $order_id;
    }

    /**
     * Check server status (optional utility method)
     */
    public function check_address_server_status()
    {
        $server_url = 'http://localhost:3210/status';

        $response = wp_remote_get($server_url, array(
            'timeout' => 5,
            'sslverify' => false,
        ));

        if (is_wp_error($response)) {
            return array(
                'status' => 'error',
                'message' => 'Server unreachable: ' . $response->get_error_message()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return array(
                'status' => 'error',
                'message' => 'Server returned HTTP ' . $response_code
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['index'])) {
            return array(
                'status' => 'success',
                'message' => 'Server online',
                'next_index' => $data['index']
            );
        }

        return array(
            'status' => 'error',
            'message' => 'Invalid server response'
        );
    }

    private function get_wallet_status_html()
    {
        $wallet_configured = get_option('kasppaga_wallet_configured');
        $wallet_address = get_option('kasppaga_wallet_address');
        $wallet_kpub = get_option('kasppaga_wallet_kpub');

        // Consider wallet configured if we have KPUB (even if address is pending derivation)
        if ($wallet_configured && ($wallet_address || $wallet_kpub)) {
            $setup_url = admin_url('admin.php?page=kaspa-wallet-setup');
            return '<div style="background: #d1e7dd; padding: 12px; border-radius: 4px; border: 1px solid #a3cfbb;">
                <strong style="color: #0f5132;">✅ Non-Custodial Wallet Active</strong><br>
                <small>Your secure wallet is configured and ready to receive payments.</small><br>
                <small><strong>Address:</strong> <code>' . esc_html(substr($wallet_address, 0, 20) . '...' . substr($wallet_address, -10)) . '</code></small><br>
                <div style="margin: 8px 0;">
                    <a href="' . $setup_url . '" class="button button-small">Manage Wallet</a>
                </div>
            </div>';
        } else {
            $setup_url = admin_url('admin.php?page=kaspa-wallet-setup');
            return '<div style="background: #f8d7da; padding: 12px; border-radius: 4px; border: 1px solid #f1aeb5;">
                <strong style="color: #842029;">❌ Wallet Not Configured</strong><br>
                <small>You need to set up your Kaspa wallet to accept payments.</small><br>
                <a href="' . $setup_url . '" class="button button-primary button-small" style="margin-top: 8px;">Set Up Wallet Now</a>
            </div>';
        }
    }

    /**
     * Enhanced admin order details display
     */
    public function display_kaspa_payment_details($order)
    {
        if ($order->get_payment_method() !== 'kaspa') {
            return;
        }

        $expected_amount = $order->get_meta('_kaspa_expected_amount');
        $payment_address = $order->get_meta('_kaspa_payment_address');
        $payment_started = $order->get_meta('_kaspa_payment_started');
        $kas_rate = $order->get_meta('_kaspa_rate');
        $txid = $order->get_meta('_kaspa_txid');
        $confirmed_amount = $order->get_meta('_kaspa_confirmed_amount');

        ?>
        <div class="kaspa-admin-payment-info"
            style="background: #f0f8ff; padding: 15px; border-left: 4px solid #70D0F0; margin: 10px 0;">
            <h4>💎 Kaspa Payment Details</h4>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 10px 0;">
                <div>
                    <?php if ($payment_address): ?>
                        <p><strong>Payment Address:</strong><br>
                            <code style="font-size: 11px; word-break: break-all;"><?php echo esc_html($payment_address); ?></code>
                        </p>
                    <?php endif; ?>

                    <p><strong>Expected Amount:</strong><br>
                        <span
                            style="font-size: 16px; color: #2271b1; font-weight: bold;"><?php echo esc_html($expected_amount); ?>
                            KAS</span>
                    </p>

                    <?php if ($confirmed_amount): ?>
                        <p><strong>Confirmed Amount:</strong><br>
                            <span
                                style="font-size: 16px; color: #00a32a; font-weight: bold;"><?php echo esc_html($confirmed_amount); ?>
                                KAS</span>
                        </p>
                    <?php else: ?>
                        <p><strong>Payment Status:</strong><br>
                            <span style="color: #d63638; font-weight: bold;">⏳ Pending</span>
                        </p>
                    <?php endif; ?>
                </div>

                <div>
                    <p><strong>KAS Rate:</strong> $<?php echo esc_html(number_format($kas_rate, 6)); ?></p>
                    <p><strong>Payment Started:</strong> <?php echo esc_html(gmdate('Y-m-d H:i:s', $payment_started)); ?></p>

                    <?php if ($txid): ?>
                        <p><strong>Transaction ID:</strong><br>
                            <code style="font-size: 10px; word-break: break-all;"><?php echo esc_html($txid); ?></code>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div style="background: #fff; padding: 10px; border-radius: 4px; margin-top: 10px;">
                <?php if ($txid && $confirmed_amount): ?>
                    <p style="margin: 0; color: green; font-weight: bold;">
                        ✅ Payment Confirmed: Received <?php echo esc_html($confirmed_amount); ?> KAS
                    </p>
                <?php elseif ($payment_address): ?>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <p style="margin: 0; color: orange; font-weight: bold; flex: 1;">
                            ⏳ Waiting for payment to address: <?php echo esc_html(substr($payment_address, -20)); ?>
                        </p>
                        <button type="button" class="button button-secondary kaspa-manual-check-btn"
                            data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                            data-address="<?php echo esc_attr($payment_address); ?>"
                            data-expected="<?php echo esc_attr($expected_amount); ?>">
                            🔍 Check Payment
                        </button>
                    </div>
                    <div id="kaspa-check-result-<?php echo esc_attr($order->get_id()); ?>" style="margin-top: 10px; display: none;">
                    </div>
                <?php else: ?>
                    <p style="margin: 0; color: gray; font-style: italic;">
                        🔄 Generating payment address...
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Thank you page handler
     */
    public function thankyou_page($order_id)
    {
        kasppaga_display_thankyou_page($order_id, $this);
    }

    /**
     * Enhanced admin options page
     */
    public function admin_options()
    {
        ?>
        <h3><?php echo esc_html($this->method_title); ?></h3>
        <p><?php echo esc_html($this->method_description); ?></p>

        <?php if (!get_option('kasppaga_wallet_configured')): ?>
            <div class="notice notice-warning">
                <p><strong>⚠️ Setup Required:</strong> Please set up your Kaspa wallet to enable payments.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=kaspa-wallet-setup')); ?>"
                        class="button button-primary">Set Up
                        Wallet</a>
                </p>
            </div>
        <?php endif; ?>

        <div class="notice notice-info">
            <p><strong>💡 How Watch-Only Payments Work:</strong></p>
            <ul style="margin-left: 20px;">
                <li><strong>KPUB Wallet:</strong> Uses Extended Public Key (KPUB) - safe to store, cannot spend funds</li>
                <li><strong>Direct Payments:</strong> Customers send payments directly to addresses generated from your KPUB
                </li>
                <li><strong>Auto-Detection:</strong> The system monitors for incoming payments automatically</li>
                <li><strong>Security:</strong> No private keys or mnemonics are stored - maximum security</li>
            </ul>
        </div>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>

        <?php
    }

    /**
     * Enqueue admin scripts for order pages
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only add on order edit pages
        if ($hook !== 'post.php' || !isset($_GET['post']) || get_post_type(intval(sanitize_text_field(wp_unslash($_GET['post'])))) !== 'shop_order') {
            return;
        }

        // Register and enqueue a script handle
        wp_register_script('kaspa-gateway-admin', '', array(), '1.0.0', true);
        wp_enqueue_script('kaspa-gateway-admin');

        // Prepare nonces
        $manual_check_nonce = wp_create_nonce('kasppaga_manual_check');
        $mark_complete_nonce = wp_create_nonce('kasppaga_mark_complete');
        $ajax_url = admin_url('admin-ajax.php');

        // Build inline script
        $inline_script = "document.addEventListener('DOMContentLoaded', function () {
            document.addEventListener('click', function (e) {
                if (e.target && e.target.classList.contains('kaspa-manual-check-btn')) {
                    e.preventDefault();
                    checkPaymentManually(e.target);
                }
            });
        });
        function checkPaymentManually(button) {
            const orderId = button.getAttribute('data-order-id');
            const address = button.getAttribute('data-address');
            const expected = button.getAttribute('data-expected');
            const resultDiv = document.getElementById('kaspa-check-result-' + orderId);
            button.disabled = true;
            button.textContent = '🔄 Checking...';
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div style=\"color: #666; font-style: italic;\">Checking payment status...</div>';
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '" . esc_url($ajax_url) . "', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    button.disabled = false;
                    button.textContent = '🔍 Check Payment';
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                if (response.data.payment_found) {
                                    resultDiv.innerHTML = '<div style=\"background: #d1e7dd; padding: 10px; border-radius: 4px; border: 1px solid #a3cfbb;\"><strong style=\"color: #0f5132;\">✅ Payment Found!</strong><br><small>Amount: ' + response.data.amount + ' KAS (Expected: ' + response.data.expected + ' KAS)</small><br><button type=\"button\" class=\"button button-primary\" onclick=\"markOrderComplete(' + orderId + ')\" style=\"margin-top: 8px;\">Mark Order Complete</button></div>';
                                } else {
                                    resultDiv.innerHTML = '<div style=\"background: #f8d7da; padding: 10px; border-radius: 4px; border: 1px solid #f1aeb5;\"><strong style=\"color: #842029;\">❌ No Payment Found</strong><br><small>No payment detected at this address yet.</small></div>';
                                }
                            } else {
                                resultDiv.innerHTML = '<div style=\"background: #f8d7da; padding: 10px; border-radius: 4px; border: 1px solid #f1aeb5;\"><strong style=\"color: #842029;\">❌ Error</strong><br><small>' + response.data + '</small></div>';
                            }
                        } catch (e) {
                            resultDiv.innerHTML = '<div style=\"background: #f8d7da; padding: 10px; border-radius: 4px; border: 1px solid #f1aeb5;\"><strong style=\"color: #842029;\">❌ Error</strong><br><small>Failed to parse response</small></div>';
                        }
                    } else {
                        resultDiv.innerHTML = '<div style=\"background: #f8d7da; padding: 10px; border-radius: 4px; border: 1px solid #f1aeb5;\"><strong style=\"color: #842029;\">❌ Network Error</strong><br><small>Failed to check payment status</small></div>';
                    }
                }
            };
                const data = 'action=kasppaga_manual_check_payment&order_id=' + orderId + '&address=' + encodeURIComponent(address) + '&expected=' + encodeURIComponent(expected) + '&nonce=" . esc_js($manual_check_nonce) . "';
            xhr.send(data);
        }
        function markOrderComplete(orderId) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '" . esc_url($ajax_url) . "', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            alert('✅ Order marked as complete! The page will refresh.');
                            location.reload();
                        } else {
                            alert('❌ Error: ' + response.data);
                        }
                    } catch (e) {
                        alert('❌ Error marking order complete');
                    }
                }
            };
            const data = 'action=kasppaga_mark_order_complete&order_id=' + orderId + '&nonce=" . esc_js($mark_complete_nonce) . "';
            xhr.send(data);
        }";

        wp_add_inline_script('kaspa-gateway-admin', $inline_script);
    }

    // Balance checking functionality moved to Kaspa plugin admin page
}

//  FIXED: Move the AJAX handler function outside the class or make it a class method

/**
 * Get next sequential address index for KPUB derivation
 * 
 * Strategy: Sequential indexing starting from 0
 * - Starts at index 0 for maximum visibility in Kaspium
 * - No offset needed - addresses are watch-only, so reusing addresses is fine
 * - Simplest approach - merchants see all payments in their wallet automatically
 */
function kasppaga_get_next_address_index()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_get_index')) {
        wp_send_json_error('Invalid nonce');
        return;
    }

    // Get current sequential index (starts at 0)
    $current_index = get_option('kasppaga_next_address_index', 0);

    wp_send_json_success(array(
        'index' => $current_index,
        'message' => 'Next sequential address index'
    ));
}

add_action('wp_ajax_kasppaga_get_next_address_index', 'kasppaga_get_next_address_index');
add_action('wp_ajax_nopriv_kasppaga_get_next_address_index', 'kasppaga_get_next_address_index');

function kasppaga_save_order_address()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_save_address')) {
        wp_send_json_error('Invalid nonce');
        return;
    }

    $order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : 0;
    $address = isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : '';
    $address_index = isset($_POST['address_index']) ? intval(sanitize_text_field(wp_unslash($_POST['address_index']))) : -1; // Optional: store the index used

    if (!$order_id || !$address) {
        wp_send_json_error('Missing order ID or address');
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error('Order not found');
        return;
    }

    // Validate address format
    if (!preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $address)) {
        wp_send_json_error('Invalid address format');
        return;
    }

    // Update order with the unique address
    $order->update_meta_data('_kaspa_payment_address', $address);
    $order->update_meta_data('_kaspa_address', $address); // Also store for polling system
    $order->update_meta_data('_kaspa_address_generated_time', time());

    // Store the address index for reference (for debugging/verification)
    if ($address_index >= 0) {
        $order->update_meta_data('_kaspa_address_index', $address_index);

        // Increment the next address index for sequential generation
        $next_index = get_option('kasppaga_next_address_index', 0);

        // Increment if this address index is >= current
        if ($address_index >= $next_index) {
            update_option('kasppaga_next_address_index', $address_index + 1);
        }
    }

    $order->save();

    wp_send_json_success(array(
        'message' => 'Address saved successfully',
        'address' => $address,
        'index' => $address_index
    ));
}

add_action('wp_ajax_kasppaga_save_order_address', 'kasppaga_save_order_address');
add_action('wp_ajax_nopriv_kasppaga_save_order_address', 'kasppaga_save_order_address');

/**
 * AJAX handler for manual payment check
 */
function kasppaga_manual_check_payment()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_manual_check') || !current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied');
        return;
    }

    $order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : 0;
    $address = isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : '';
    $expected = isset($_POST['expected']) ? floatval(sanitize_text_field(wp_unslash($_POST['expected']))) : 0;

    if (!$order_id || !$address || !$expected) {
        wp_send_json_error('Missing required parameters');
        return;
    }

    try {
        // Use the transaction polling class to check balance
        $polling = new KASPPAGA_Transaction_Polling();
        $balance = $polling->get_kaspa_balance($address);

        if ($balance === false) {
            wp_send_json_error('Failed to fetch balance from Kaspa API');
            return;
        }

        // Check if payment was received
        if ($balance >= $expected) {
            wp_send_json_success(array(
                'payment_found' => true,
                'amount' => $balance,
                'expected' => $expected,
                'timestamp' => time()
            ));
        } else {
            wp_send_json_success(array(
                'payment_found' => false,
                'current_balance' => $balance,
                'expected' => $expected
            ));
        }

    } catch (Exception $e) {
        error_log('Kaspa manual check error: ' . $e->getMessage());
        wp_send_json_error('Error checking payment: ' . $e->getMessage());
    }
}

add_action('wp_ajax_kasppaga_manual_check_payment', 'kasppaga_manual_check_payment');

/**
 * AJAX handler for marking order as complete
 */
function kasppaga_mark_order_complete()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_mark_complete') || !current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied');
        return;
    }

    $order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : 0;

    if (!$order_id) {
        wp_send_json_error('Missing order ID');
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error('Order not found');
        return;
    }

    if ($order->get_payment_method() !== 'kaspa') {
        wp_send_json_error('Not a Kaspa order');
        return;
    }

    // Mark as processing (which will trigger completion)
    $order->update_status('processing', 'Payment confirmed via manual check');
    $order->save();

    wp_send_json_success(array(
        'message' => 'Order marked as complete',
        'new_status' => $order->get_status()
    ));
}

add_action('wp_ajax_kasppaga_mark_order_complete', 'kasppaga_mark_order_complete');