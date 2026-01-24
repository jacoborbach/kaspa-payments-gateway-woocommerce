<?php
/**
 * Kaspa Transaction Polling System
 * 
 * Handles transaction verification and auto-completion using official Kaspa API
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kaspa Transaction Polling Class
 */
class KASPPAGA_Transaction_Polling
{
    private $api_base_url = 'https://api.kaspa.org';
    private $polling_interval = 30; // seconds

    public function __construct()
    {
        add_action('init', array($this, 'init'));
    }

    public function init()
    {
        // AJAX endpoints for polling
        add_action('wp_ajax_kasppaga_check_payment', array($this, 'ajax_check_payment'));
        add_action('wp_ajax_nopriv_kasppaga_check_payment', array($this, 'ajax_check_payment'));

        // Admin manual verification
        add_action('wp_ajax_kasppaga_manual_verify', array($this, 'ajax_manual_verify'));

        // Add admin metabox to orders
        add_action('add_meta_boxes', array($this, 'add_order_metabox'));

        // Cron job for background polling
        add_action('kasppaga_poll_payments', array($this, 'poll_pending_payments'));

        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('kasppaga_poll_payments')) {
            wp_schedule_event(time(), 'kasppaga_polling_interval', 'kasppaga_poll_payments');
        }

        // Add custom cron interval
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
    }

    /**
     * Add custom cron interval for polling
     */
    public function add_cron_interval($schedules)
    {
        $schedules['kasppaga_polling_interval'] = array(
            'interval' => $this->polling_interval,
            // translators: %d is the number of seconds
            'display' => sprintf(__('Every %d seconds', 'kaspa-payments-gateway-woocommerce'), $this->polling_interval)
        );
        return $schedules;
    }

    /**
     * Get balance for a Kaspa address using OFFICIAL API endpoint
     */
    public function get_kaspa_balance($address)
    {
        // Ensure address HAS kaspa: prefix (API requires it!)
        $full_address = $address;
        if (strpos($address, 'kaspa:') !== 0) {
            $full_address = 'kaspa:' . $address;
        }

        // Use OFFICIAL balance endpoint with FULL address
        $url = $this->api_base_url . '/addresses/' . urlencode($full_address) . '/balance';

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'Kaspa-Payments-Gateway-WooCommerce/1.0',
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            return false;
        }

        $data = json_decode($body, true);
        if (!$data) {
            return false;
        }

        // Look for balance field
        if (isset($data['balance'])) {
            $balance_sompi = $data['balance'];
            $balance_kas = $balance_sompi / 100000000; // Convert sompi to KAS
            return $balance_kas;
        }

        return false;
    }

    /**
     * Get UTXOs for a Kaspa address using OFFICIAL API endpoint
     */
    public function get_kaspa_utxos($address)
    {
        // Ensure address HAS kaspa: prefix (API requires it!)
        $full_address = $address;
        if (strpos($address, 'kaspa:') !== 0) {
            $full_address = 'kaspa:' . $address;
        }

        // Use OFFICIAL utxos endpoint with FULL address
        $url = $this->api_base_url . '/addresses/' . urlencode($full_address) . '/utxos';

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'Kaspa-Payments-Gateway-WooCommerce/1.0',
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            return false;
        }

        $data = json_decode($body, true);
        if (!$data) {
            return false;
        }

        return $data;
    }

    /**
     * Get full transactions for a Kaspa address using OFFICIAL API endpoint
     */
    public function get_kaspa_full_transactions($address)
    {
        // Ensure address HAS kaspa: prefix (API requires it!)
        $full_address = $address;
        if (strpos($address, 'kaspa:') !== 0) {
            $full_address = 'kaspa:' . $address;
        }

        // Use OFFICIAL full-transactions endpoint with FULL address
        $url = $this->api_base_url . '/addresses/' . urlencode($full_address) . '/full-transactions';

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'Kaspa-Payments-Gateway-WooCommerce/1.0',
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            return false;
        }

        $data = json_decode($body, true);
        if (!$data) {
            return false;
        }

        return $data;
    }

    /**
     * Check if a specific payment has been received using BALANCE method
     * WITH TIMESTAMP VERIFICATION to prevent reusing old balances
     */
    public function check_payment_received($address, $expected_amount, $since_timestamp = null)
    {
        // If we have a timestamp, we need to check transactions to see if new payments arrived
        if ($since_timestamp) {
            // Try to get recent transactions first
            $transactions = $this->get_kaspa_full_transactions($address);

            if ($transactions !== false) {
                // Look for transactions after our order was created
                $new_payment_found = $this->check_new_transactions($transactions, $address, $expected_amount, $since_timestamp);

                if ($new_payment_found) {
                    return $new_payment_found;
                }
            }
        }

        // Try balance endpoint (fallback or if no timestamp)
        $balance = $this->get_kaspa_balance($address);

        if ($balance === false) {
            return array('found' => false, 'error' => 'API call failed');
        }

        // If no timestamp provided, just check balance (for compatibility)
        if (!$since_timestamp) {
            $tolerance = 0.00000001; // 1 sompi tolerance

            if ($balance >= ($expected_amount - $tolerance)) {
                return array(
                    'found' => true,
                    'txid' => 'balance-confirmed-' . time(),
                    'amount' => $balance,
                    'timestamp' => time(),
                    'confirmations' => 1
                );
            }
        }

        return array(
            'found' => false,
            'expected' => $expected_amount,
            'current_balance' => $balance,
            'difference' => $expected_amount - $balance,
            'message' => $since_timestamp ? 'No new transactions found since order creation' : 'Insufficient balance'
        );
    }

    /**
     * Check for new transactions since a given timestamp
     */
    private function check_new_transactions($transactions_data, $address, $expected_amount, $since_timestamp)
    {
        if (!is_array($transactions_data)) {
            return false;
        }

        $transactions = $transactions_data;
        if (isset($transactions_data['transactions'])) {
            $transactions = $transactions_data['transactions'];
        }

        if (!is_array($transactions)) {
            return false;
        }

        foreach ($transactions as $tx) {
            // Skip if transaction is older than our timestamp
            $tx_time = null;
            if (isset($tx['block_time'])) {
                $tx_time = $tx['block_time'];
            } elseif (isset($tx['timestamp'])) {
                $tx_time = $tx['timestamp'];
            }

            if ($tx_time && $tx_time <= $since_timestamp) {
                continue; // Skip old transactions
            }

            // Check if this transaction has outputs to our address
            if (isset($tx['outputs']) && is_array($tx['outputs'])) {
                foreach ($tx['outputs'] as $output) {
                    if (
                        isset($output['script_public_key_address']) &&
                        $output['script_public_key_address'] === $address &&
                        isset($output['amount'])
                    ) {
                        // Convert from sompi to KAS (1 KAS = 100,000,000 sompi)
                        $received_amount = $output['amount'] / 100000000;

                        // Check if amount matches (with small tolerance for rounding)
                        if (abs($received_amount - $expected_amount) < 0.00000001) {
                            return array(
                                'found' => true,
                                'txid' => $tx['transaction_id'] ?? 'tx-' . time(),
                                'amount' => $received_amount,
                                'timestamp' => $tx_time ?? time(),
                                'confirmations' => isset($tx['is_accepted']) && $tx['is_accepted'] ? 1 : 0
                            );
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get address transactions using FULL-TRANSACTIONS endpoint (fallback method)
     */
    public function get_address_transactions($address, $limit = 50)
    {
        // Use the official full-transactions endpoint
        $data = $this->get_kaspa_full_transactions($address);

        if ($data === false) {
            return false;
        }

        // The response structure might be different - let's handle various formats
        if (isset($data['transactions']) && is_array($data['transactions'])) {
            return $data['transactions'];
        }

        if (is_array($data) && !empty($data)) {
            return $data; // Direct array of transactions
        }

        return false;
    }

    /**
     * AJAX handler for checking payment status
     */
    public function ajax_check_payment()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_check_payment')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        $order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : 0;

        if (!$order_id) {
            wp_send_json_error('Invalid order ID');
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'kaspa') {
            wp_send_json_error('Invalid order or payment method');
            return;
        }

        // Check if already completed
        if ($order->get_status() === 'completed' || $order->get_status() === 'processing') {
            wp_send_json_success(array(
                'status' => 'completed',
                'message' => 'Payment already confirmed!'
            ));
            return;
        }

        // Check both old and new meta keys for compatibility
        $kaspa_address = $order->get_meta('_kaspa_payment_address');
        if (empty($kaspa_address)) {
            $kaspa_address = $order->get_meta('_kaspa_address'); // Fallback to old key
        }

        $kaspa_amount = $order->get_meta('_kaspa_expected_amount');
        if (empty($kaspa_amount)) {
            $kaspa_amount = $order->get_meta('_kaspa_amount'); // Fallback to old key
        }

        $payment_started = $order->get_meta('_kaspa_payment_started');

        if (!$kaspa_address || !$kaspa_amount) {
            // Don't show error if address is still being generated
            if (strpos($kaspa_address, 'pending-') === 0 || strpos($kaspa_address, 'Generating') !== false) {
                wp_send_json_success(array(
                    'status' => 'pending',
                    'message' => '⏳ Generating payment address...'
                ));
            } else {
                wp_send_json_error('Missing payment information');
            }
            return;
        }

        // Check the blockchain
        $result = $this->check_payment_received($kaspa_address, $kaspa_amount, $payment_started);

        // Handle the result
        if (!is_array($result)) {
            wp_send_json_error('Payment check failed - API error');
            return;
        }

        if (isset($result['error'])) {
            wp_send_json_error('Payment check failed: ' . $result['error']);
            return;
        }

        if ($result['found']) {
            // Payment found! Update order
            $this->complete_order_payment($order, $result);

            wp_send_json_success(array(
                'status' => 'completed',
                'message' => 'Payment confirmed! Order is being processed.',
                'txid' => $result['txid'],
                'amount' => $result['amount']
            ));
        } else {
            wp_send_json_success(array(
                'status' => 'pending',
                'message' => '⏳ Waiting for payment...',
                'address' => $kaspa_address
            ));
        }
    }

    /**
     * Complete order payment
     */
    private function complete_order_payment($order, $transaction_data)
    {
        // Store transaction details
        $order->update_meta_data('_kaspa_txid', $transaction_data['txid']);
        $order->update_meta_data('_kaspa_confirmed_amount', $transaction_data['amount']);
        $order->update_meta_data('_kaspa_payment_confirmed', time());

        // Update order status
        $order->update_status('processing', sprintf(
            'Kaspa payment confirmed. Amount: %s KAS (Balance check)',
            $transaction_data['amount']
        ));

        // Save order
        $order->save();
    }

    /**
     * Poll all pending Kaspa payments (background cron job)
     */
    public function poll_pending_payments()
    {
        $orders = wc_get_orders(array(
            'status' => 'on-hold',
            'payment_method' => 'kaspa',
            'limit' => 50,
            'meta_query' => array(
                array(
                    'key' => '_kaspa_txid',
                    'compare' => 'NOT EXISTS'
                )
            )
        ));

        foreach ($orders as $order) {
            // Check both old and new meta keys for compatibility
            $kaspa_address = $order->get_meta('_kaspa_payment_address');
            if (empty($kaspa_address)) {
                $kaspa_address = $order->get_meta('_kaspa_address'); // Fallback to old key
            }

            $kaspa_amount = $order->get_meta('_kaspa_expected_amount');
            if (empty($kaspa_amount)) {
                $kaspa_amount = $order->get_meta('_kaspa_amount'); // Fallback to old key
            }

            $payment_started = $order->get_meta('_kaspa_payment_started');

            if (!$kaspa_address || !$kaspa_amount) {
                continue;
            }

            // Skip orders older than 24 hours
            if ($payment_started && (time() - $payment_started) > 86400) {
                continue;
            }

            $result = $this->check_payment_received($kaspa_address, $kaspa_amount, $payment_started);

            if (is_array($result) && $result['found']) {
                $this->complete_order_payment($order, $result);
            }
        }
    }

    /**
     * Manual verification AJAX handler
     */
    public function ajax_manual_verify()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_manual_verify')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        $order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : 0;
        $txid = isset($_POST['txid']) ? sanitize_text_field(wp_unslash($_POST['txid'])) : '';

        if (!$order_id) {
            wp_send_json_error('Invalid order ID');
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'kaspa') {
            wp_send_json_error('Invalid order');
            return;
        }

        // Mark as manually verified
        $order->update_meta_data('_kaspa_txid', $txid ?: 'manually-verified-' . time());
        $order->update_meta_data('_kaspa_manual_verification', time());
        $order->update_meta_data('_kaspa_verified_by', get_current_user_id());

        $order->update_status('processing', 'Kaspa payment manually verified by admin.');
        $order->save();

        wp_send_json_success('Payment manually verified');
    }

    /**
     * Add admin metabox to orders
     */
    public function add_order_metabox()
    {
        add_meta_box(
            'kaspa-payment-details',
            'Kaspa Payment Details',
            array($this, 'render_order_metabox'),
            'shop_order',
            'side',
            'high'
        );
    }

    /**
     * Render admin metabox
     */
    public function render_order_metabox($post)
    {
        $order = wc_get_order($post->ID);

        if (!$order || $order->get_payment_method() !== 'kaspa') {
            echo '<p>Not a Kaspa payment.</p>';
            return;
        }

        // Check both old and new meta keys for compatibility
        $kaspa_amount = $order->get_meta('_kaspa_expected_amount');
        if (empty($kaspa_amount)) {
            $kaspa_amount = $order->get_meta('_kaspa_amount'); // Fallback to old key
        }

        $kaspa_address = $order->get_meta('_kaspa_payment_address');
        if (empty($kaspa_address)) {
            $kaspa_address = $order->get_meta('_kaspa_address'); // Fallback to old key
        }

        $kaspa_txid = $order->get_meta('_kaspa_txid');
        $payment_started = $order->get_meta('_kaspa_payment_started');

        ?>
        <div class="kaspa-admin-details">
            <p><strong>Expected Amount:</strong> <?php echo esc_html($kaspa_amount); ?> KAS</p>
            <p><strong>Address:</strong> <br><code
                    style="font-size: 10px; word-break: break-all;"><?php echo esc_html($kaspa_address); ?></code></p>

            <?php if ($kaspa_txid): ?>
                <p><strong>Transaction ID:</strong><br><code
                        style="font-size: 10px; word-break: break-all;"><?php echo esc_html($kaspa_txid); ?></code></p>
                <p style="color: green;">✅ Payment Confirmed</p>
            <?php else: ?>
                <p style="color: orange;">⏳ Payment Pending</p>

                <div style="margin: 10px 0;">
                    <button type="button" class="button button-secondary"
                        onclick="kasppagaCheckPayment(<?php echo esc_js($order->get_id()); ?>)">
                        🔍 Check Balance Now
                    </button>
                </div>

                <div style="margin: 10px 0; padding-top: 10px; border-top: 1px solid #ddd;">
                    <label for="kaspa-manual-txid">Manual TXID (optional):</label><br>
                    <input type="text" id="kaspa-manual-txid" placeholder="Enter transaction ID or leave blank"
                        style="width: 100%; margin: 5px 0;">
                    <button type="button" class="button button-primary"
                        onclick="kasppagaManualVerify(<?php echo esc_js($order->get_id()); ?>)">
                        ✅ Mark as Paid
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
        // Enqueue jQuery if not already enqueued and add inline script
        wp_enqueue_script('jquery');
        $check_payment_nonce = wp_create_nonce('kasppaga_check_payment');
        $manual_verify_nonce = wp_create_nonce('kasppaga_manual_verify');
        $inline_script = "function kasppagaCheckPayment(orderId) {
            const button = jQuery('button:contains(\"Check Balance Now\")');
            button.prop('disabled', true).text('🔄 Checking...');
            jQuery.post(ajaxurl, {
                    action: 'kasppaga_check_payment',
                order_id: orderId,
                nonce: '" . esc_js($check_payment_nonce) . "'
            }, function (response) {
                if (response.success) {
                    alert(response.data.message);
                    if (response.data.status === 'completed') {
                        location.reload();
                    }
                } else {
                    alert('Error: ' + response.data);
                }
            }).fail(function () {
                alert('Network error occurred. Please try again.');
            }).always(function () {
                button.prop('disabled', false).text('🔍 Check Balance Now');
            });
        }
        function kasppagaManualVerify(orderId) {
            var txid = jQuery('#kaspa-manual-txid').val();
            if (!confirm('Mark this payment as received?')) return;
            jQuery.post(ajaxurl, {
                    action: 'kasppaga_manual_verify',
                order_id: orderId,
                txid: txid,
                nonce: '" . esc_js($manual_verify_nonce) . "'
            }, function (response) {
                if (response.success) {
                    alert('Payment marked as verified!');
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            });
        }";
        wp_add_inline_script('jquery', $inline_script);
    }
}

// Initialize the polling system
new KASPPAGA_Transaction_Polling();
