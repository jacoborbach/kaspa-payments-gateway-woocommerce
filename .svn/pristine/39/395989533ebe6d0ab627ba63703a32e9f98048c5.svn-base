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

        error_log('Kaspa: Getting balance from: ' . $url);

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'Kaspa-Payments-Gateway-WooCommerce/1.0',
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            error_log('Kaspa Balance API Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        error_log('Kaspa Balance: Status ' . $code);
        error_log('Kaspa Balance: Body: ' . $body);

        if ($code !== 200) {
            error_log('Kaspa Balance: HTTP error ' . $code);
            return false;
        }

        $data = json_decode($body, true);
        if (!$data) {
            error_log('Kaspa Balance: Invalid JSON response');
            return false;
        }

        // Log the structure so we understand it
        error_log('Kaspa Balance: Response keys: ' . implode(', ', array_keys($data)));

        // Look for balance field
        if (isset($data['balance'])) {
            $balance_sompi = $data['balance'];
            $balance_kas = $balance_sompi / 100000000; // Convert sompi to KAS

            error_log('Kaspa Balance: Found balance: ' . $balance_kas . ' KAS');
            return $balance_kas;
        }

        error_log('Kaspa Balance: No balance field found');
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

        error_log('Kaspa: Getting UTXOs from: ' . $url);

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'Kaspa-Payments-Gateway-WooCommerce/1.0',
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            error_log('Kaspa UTXO API Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        error_log('Kaspa UTXO: Status ' . $code);
        error_log('Kaspa UTXO: Body: ' . substr($body, 0, 1000));

        if ($code !== 200) {
            error_log('Kaspa UTXO: HTTP error ' . $code);
            return false;
        }

        $data = json_decode($body, true);
        if (!$data) {
            error_log('Kaspa UTXO: Invalid JSON response');
            return false;
        }

        error_log('Kaspa UTXO: Response keys: ' . implode(', ', array_keys($data)));

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

        error_log('Kaspa: Getting full transactions from: ' . $url);

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'Kaspa-Payments-Gateway-WooCommerce/1.0',
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            error_log('Kaspa Transactions API Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        error_log('Kaspa Transactions: Status ' . $code);
        error_log('Kaspa Transactions: Body: ' . substr($body, 0, 1000));

        if ($code !== 200) {
            error_log('Kaspa Transactions: HTTP error ' . $code);
            return false;
        }

        $data = json_decode($body, true);
        if (!$data) {
            error_log('Kaspa Transactions: Invalid JSON response');
            return false;
        }

        error_log('Kaspa Transactions: Response keys: ' . implode(', ', array_keys($data)));

        return $data;
    }

    /**
     * Check if a specific payment has been received using BALANCE method
     * WITH TIMESTAMP VERIFICATION to prevent reusing old balances
     */
    public function check_payment_received($address, $expected_amount, $since_timestamp = null)
    {
        error_log('Kaspa: Checking payment for address: ' . $address);
        error_log('Kaspa: Expected amount: ' . $expected_amount . ' KAS');
        error_log('Kaspa: Payment started at: ' . ($since_timestamp ? gmdate('Y-m-d H:i:s', $since_timestamp) : 'not set'));

        // If we have a timestamp, we need to check transactions to see if new payments arrived
        if ($since_timestamp) {
            // Try to get recent transactions first
            $transactions = $this->get_kaspa_full_transactions($address);

            if ($transactions !== false) {
                error_log('Kaspa: Checking transactions for new payments since ' . gmdate('Y-m-d H:i:s', $since_timestamp));

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
            error_log('Kaspa: Failed to get balance for address: ' . $address);
            return array('found' => false, 'error' => 'API call failed');
        }

        error_log('Kaspa: Current balance: ' . $balance . ' KAS');

        // If no timestamp provided, just check balance (for compatibility)
        if (!$since_timestamp) {
            $tolerance = 0.00000001; // 1 sompi tolerance

            if ($balance >= ($expected_amount - $tolerance)) {
                error_log('Kaspa: Payment FOUND (balance check)! Expected: ' . $expected_amount . ', Balance: ' . $balance);

                return array(
                    'found' => true,
                    'txid' => 'balance-confirmed-' . time(),
                    'amount' => $balance,
                    'timestamp' => time(),
                    'confirmations' => 1
                );
            }
        }

        error_log('Kaspa: Payment NOT found. Expected: ' . $expected_amount . ', Balance: ' . $balance);

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
            error_log('Kaspa: No transactions array found in response');
            return false;
        }

        error_log('Kaspa: Checking ' . count($transactions) . ' transactions for new payments');

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
                            error_log('Kaspa: NEW payment found! Expected: ' . $expected_amount . ', Received: ' . $received_amount);

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
            error_log('Kaspa: Failed to get transactions for address: ' . $address);
            return false;
        }

        // The response structure might be different - let's handle various formats
        if (isset($data['transactions']) && is_array($data['transactions'])) {
            return $data['transactions'];
        }

        if (is_array($data) && !empty($data)) {
            return $data; // Direct array of transactions
        }

        error_log('Kaspa: Unexpected transaction response format');
        return false;
    }

    /**
     * AJAX handler for checking payment status
     */
    public function ajax_check_payment()
    {
        // ADD THIS DEBUG LOG AT THE VERY START
        $order_id_raw = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : 'none';
        error_log('Kaspa: ajax_check_payment() CALLED for order: ' . $order_id_raw);
        // Note: Only log sanitized POST data in production
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $sanitized_post = array_map('sanitize_text_field', wp_unslash($_POST));
            error_log('Kaspa: POST data: ' . print_r($sanitized_post, true));
        }

        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_check_payment')) {
            error_log('Kaspa: NONCE VERIFICATION FAILED');
            wp_send_json_error('Invalid nonce');
            return;
        }

        $order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : 0;

        if (!$order_id) {
            error_log('Kaspa: INVALID ORDER ID');
            wp_send_json_error('Invalid order ID');
            return;
        }

        error_log('Kaspa: Loading order ' . $order_id);

        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'kaspa') {
            error_log('Kaspa: ORDER NOT FOUND or wrong payment method');
            wp_send_json_error('Invalid order or payment method');
            return;
        }

        // Check if already completed
        if ($order->get_status() === 'completed' || $order->get_status() === 'processing') {
            error_log('Kaspa: Order already completed');
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

        error_log('Kaspa: Address: ' . $kaspa_address);
        error_log('Kaspa: Amount: ' . $kaspa_amount);
        error_log('Kaspa: Started: ' . $payment_started);

        if (!$kaspa_address || !$kaspa_amount) {
            error_log('Kaspa: MISSING payment information - address or amount not set yet');
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

        // NOW CHECK THE BLOCKCHAIN
        error_log('Kaspa: About to call check_payment_received()');
        $result = $this->check_payment_received($kaspa_address, $kaspa_amount, $payment_started);
        error_log('Kaspa: check_payment_received() returned: ' . print_r($result, true));

        // Handle the result
        if (!is_array($result)) {
            error_log('Kaspa: Result is not an array!');
            wp_send_json_error('Payment check failed - API error');
            return;
        }

        if (isset($result['error'])) {
            error_log('Kaspa: Result has error: ' . $result['error']);
            wp_send_json_error('Payment check failed: ' . $result['error']);
            return;
        }

        if ($result['found']) {
            error_log('Kaspa: PAYMENT FOUND! Completing order...');
            // Payment found! Update order
            $this->complete_order_payment($order, $result);

            wp_send_json_success(array(
                'status' => 'completed',
                'message' => 'Payment confirmed! Order is being processed.',
                'txid' => $result['txid'],
                'amount' => $result['amount']
            ));
        } else {
            // Don't show balance publicly - privacy/security concern
            $message = '⏳ Waiting for payment...';

            // Log balance for debugging (admin only)
            if (isset($result['current_balance']) && isset($result['expected'])) {
                error_log('Kaspa: Payment not found - Balance: ' . number_format($result['current_balance'], 8) . ' KAS, Need: ' . number_format($result['expected'], 8) . ' KAS');
            } else {
                error_log('Kaspa: Payment not found - waiting for payment');
            }

            wp_send_json_success(array(
                'status' => 'pending',
                'message' => $message,
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

        // Log the successful payment
        error_log(sprintf(
            'Kaspa payment confirmed for Order #%d. Amount: %s KAS',
            $order->get_id(),
            $transaction_data['amount']
        ));
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