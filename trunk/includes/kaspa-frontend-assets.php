<?php
/**
 * Kaspa Frontend Assets - KPUB Watch-Only
 * 
 * Handles thank you page template for KPUB watch-only wallet system
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display the thank you page for Kaspa payments
 */
function kasppaga_display_thankyou_page($order_id, $gateway_instance)
{
    $order = wc_get_order($order_id);

    if (!$order) {
        return;
    }

    // If order is completed, show success message instead of payment interface
    if ($order->get_status() === 'completed' || $order->get_status() === 'processing') {
        $txid = $order->get_meta('_kaspa_txid');
        $payment_address = $order->get_meta('_kaspa_payment_address') ?: $order->get_meta('_kaspa_address');
        kasppaga_render_payment_success($order_id, $payment_address, $txid);
        return;
    }

    // Check if payment is already confirmed
    $payment_address_meta = $order->get_meta('_kaspa_payment_address');
    // Also check the polling system's address field
    if (empty($payment_address_meta)) {
        $payment_address_meta = $order->get_meta('_kaspa_address');
    }

    // Check if address is pending generation
    $address_pending = $order->get_meta('_kaspa_address_pending');
    $txid = $order->get_meta('_kaspa_txid');

    if ($txid) {
        // Payment already confirmed - show success message
        kasppaga_render_payment_success($order_id, $payment_address_meta, $txid);
        return;
    }

    $expected_amount = $order->get_meta('_kaspa_expected_amount');

    // Allow proceeding if address is pending (will be generated client-side) OR if we have an address
    if (!$expected_amount || (!$payment_address_meta && !$address_pending)) {
        echo '<p>Error: Payment information missing. Please contact support.</p>';
        return;
    }

    // Ensure payment_address is a string before passing to template
    $payment_address = '';

    if ($address_pending && empty($payment_address_meta)) {
        $payment_address = 'pending-derivation';
    } elseif (is_string($payment_address_meta)) {
        $payment_address = $payment_address_meta;
    } elseif (is_array($payment_address_meta)) {
        if (isset($payment_address_meta['address']) && is_string($payment_address_meta['address'])) {
            $payment_address = $payment_address_meta['address'];
        } else {
            echo '<p>Error: Invalid payment address format. Please contact support.</p>';
            return;
        }
    } else {
        if (!$expected_amount) {
            echo '<p>Error: Invalid payment address type. Please contact support.</p>';
            return;
        }
        $payment_address = 'pending-derivation';
    }

    // Check if address is pending generation (placeholder)
    $is_pending = (strpos($payment_address, 'pending-') === 0) ||
        (empty($payment_address) && $order->get_meta('_kaspa_address_pending'));

    if (!$is_pending) {
        // Validate the address format for existing addresses
        if (!preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $payment_address)) {
            echo '<p>Error: Invalid payment address format. Please contact support.</p>';
            return;
        }
    }

    // Load checkout assets
    kasppaga_enqueue_checkout_assets($gateway_instance, $order_id, $expected_amount);

    // Enqueue wallet library - loaded synchronously for address generation
    $script_url = plugin_dir_url(dirname(__FILE__)) . 'assets/kaspa-wallet.js';
    wp_enqueue_script(
        'kaspa-wallet-bundle',
        $script_url,
        array('jquery'),
        '1.0.0',
        false // Load in header, not footer, for synchronous loading
    );

    // Always add address generation script - it will generate if pending or update if exists
    kasppaga_enqueue_address_generation_script($order_id, $is_pending);

    // Get current rate for live pricing
    $current_rate = $gateway_instance->get_kas_rate();

    // For pending addresses, use a placeholder; otherwise use the actual address
    $display_address = $is_pending ? '(Generating address...)' : $payment_address;

    // Render the checkout template with the address (or placeholder)
    kasppaga_render_checkout_template($order_id, $expected_amount, $current_rate, $display_address);
}


function kasppaga_enqueue_address_generation_script($order_id, $is_pending = false)
{
    // Get KPUB for address generation
    $kpub = get_option('kasppaga_wallet_kpub');

    if (!$kpub) {
        return; // No KPUB available
    }

    // Check if address already exists
    $order = wc_get_order($order_id);
    $existing_address = $order ? $order->get_meta('_kaspa_payment_address') : null;

    // Register script handle for inline script
    wp_register_script('kaspa-address-generation', '', array('jquery', 'kaspa-wallet-bundle'), '1.0.0', true);
    wp_enqueue_script('kaspa-address-generation');

    // Prepare data for inline script
    $order_id_js = intval($order_id);
    $kpub_js = esc_js($kpub);
    $is_pending_js = $is_pending ? 'true' : 'false';
    $existing_address_js = $existing_address ? "'" . esc_js($existing_address) . "'" : 'null';
    $get_index_nonce = wp_create_nonce('kasppaga_get_index');
    $save_address_nonce = wp_create_nonce('kasppaga_save_address');
    $ajax_url = admin_url('admin-ajax.php');

    // Build the large inline script
    $inline_script = "function waitForWalletAndGenerate() {
        const orderId = {$order_id_js};
        const kpub = '{$kpub_js}';
        const isPending = {$is_pending_js};
        const existingAddress = {$existing_address_js};
        let attempts = 0;
        const maxAttempts = 20;
        function checkAndGenerate() {
            attempts++;
            const walletLib = window.kaspaWallet || window.KaspaWallet || window.wallet;
            let walletLibAlt = null;
            if (window.kaspa && window.kaspa.wallet) {
                walletLibAlt = window.kaspa.wallet;
            } else if (window.Kaspa && window.Kaspa.Wallet) {
                walletLibAlt = window.Kaspa.Wallet;
            }
            const finalWalletLib = walletLib || walletLibAlt;
            const hasGenerateMethod = finalWalletLib && typeof finalWalletLib.generateAddressesUniversal === 'function';
            if (finalWalletLib && hasGenerateMethod) {
                if (isPending || !existingAddress) {
                    const indexXhr = new XMLHttpRequest();
                    indexXhr.open('POST', '" . esc_url($ajax_url) . "', true);
                    indexXhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    indexXhr.onreadystatechange = function () {
                        if (indexXhr.readyState === 4 && indexXhr.status === 200) {
                            try {
                                const indexResponse = JSON.parse(indexXhr.responseText);
                                if (indexResponse.success) {
                                    const addressIndex = parseInt(indexResponse.data.index, 10);
                                    const numericIndex = parseInt(addressIndex, 10);
                                    if (isNaN(numericIndex)) {
                                        throw new Error('Invalid address index: ' + addressIndex);
                                    }
                                    finalWalletLib.generateAddressesUniversal(kpub, numericIndex, 1)
                                        .then(function (result) {
                                            if (result && result.addresses && Array.isArray(result.addresses) && result.addresses.length > 0) {
                                                const addressData = result.addresses[0];
                                                const address = addressData.address;
                                                if (address && typeof address === 'string' && address.startsWith('kaspa:')) {
                                                    updatePaymentAddress(address);
                                                    saveOrderAddress(orderId, address, numericIndex);
                                                } else {
                                                    console.error('❌ Invalid address format in result:', addressData);
                                                    throw new Error('Invalid address format returned: ' + (address ? address.substring(0, 50) : 'null'));
                                                }
                                            } else {
                                                console.error('❌ Result structure invalid:', result);
                                                throw new Error('generateAddressesUniversal returned invalid structure');
                                            }
                                        })
                                        .catch(function (error) {
                                            console.error('❌ Failed to generate address:', error);
                                            console.error('  - Error details:', error.message || error);
                                            console.error('  - KPUB preview:', kpub ? kpub.substring(0, 30) + '...' : 'null');
                                            console.error('  - KPUB length:', kpub ? kpub.length : 0);
                                            console.error('  - Address Index:', addressIndex);
                                            if (finalWalletLib.detectKPUBFormat) {
                                                try {
                                                    const format = finalWalletLib.detectKPUBFormat(kpub);
                                                    console.error('  - KPUB format detected:', format);
                                                } catch (e) {
                                                    console.error('  - KPUB format detection failed:', e.message);
                                                }
                                            }
                                        });
                                } else {
                                    console.error('❌ Failed to get next address index:', indexResponse.data);
                                    console.warn('⚠️ Falling back to orderId as index');
                                    generateAddressWithIndex(orderId);
                                }
                            } catch (e) {
                                console.error('❌ Error parsing index response:', e);
                                console.warn('⚠️ Falling back to orderId as index');
                                generateAddressWithIndex(orderId);
                            }
                        } else if (indexXhr.readyState === 4) {
                            console.error('❌ Failed to get next address index (HTTP error):', indexXhr.status);
                            console.warn('⚠️ Falling back to orderId as index');
                            generateAddressWithIndex(orderId);
                        }
                    };
                    const indexData = 'action=kasppaga_get_next_address_index&order_id=' + orderId + '&nonce=" . esc_js($get_index_nonce) . "';
                    indexXhr.send(indexData);
                    function generateAddressWithIndex(indexToUse) {
                        const numericIndex = parseInt(indexToUse, 10);
                        finalWalletLib.generateAddressesUniversal(kpub, numericIndex, 1)
                            .then(function (result) {
                                if (result && result.addresses && result.addresses.length > 0) {
                                    const address = result.addresses[0].address;
                                    if (address && address.startsWith('kaspa:')) {
                                        updatePaymentAddress(address);
                                        saveOrderAddress(orderId, address, numericIndex);
                                    }
                                }
                            })
                            .catch(function (error) {
                                console.error('❌ Fallback address generation also failed:', error);
                            });
                    }
                }
            } else if (attempts < maxAttempts) {
                setTimeout(checkAndGenerate, 500);
            } else {
                console.error('❌ Kaspa wallet library failed to load after', maxAttempts, 'attempts');
                const walletKeys = Object.keys(window).filter(k => k.toLowerCase().includes('kaspa') || k.toLowerCase().includes('wallet'));
                console.error('Available wallet-related globals:', walletKeys.length > 0 ? walletKeys : 'NONE FOUND');
                const scriptTag = document.querySelector('script[src*=\"kaspa-simple-wallet\"]');
                console.error('Script tag check:', scriptTag ? '✅ Found in DOM' : '❌ NOT in DOM');
                if (scriptTag) {
                    console.error('Script src:', scriptTag.src);
                    console.error('Script loaded:', scriptTag.complete || scriptTag.readyState === 'complete' ? '✅' : '❌');
                }
                console.error('All window properties containing \"kaspa\":', Object.keys(window).filter(k => k.toLowerCase().includes('kaspa')));
                console.error('All window properties containing \"wallet\":', Object.keys(window).filter(k => k.toLowerCase().includes('wallet')));
                console.error('Wallet library should be loaded from plugin assets');
            }
        }
        checkAndGenerate();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', waitForWalletAndGenerate);
    } else {
        waitForWalletAndGenerate();
    }";

    // Add helper functions to inline script
    $helper_functions = "function updatePaymentAddress(newAddress) {
        let amount = '0';
        const amountElements = document.querySelectorAll('.kaspa-copy-text');
        amountElements.forEach(element => {
            if (element.textContent.includes('KAS') && !element.textContent.includes('kaspa:')) {
                amount = element.textContent.replace(' KAS', '').trim();
            }
        });
        if (amount === '0' && window.kaspaCheckoutData && window.kaspaCheckoutData.expectedAmount) {
            amount = window.kaspaCheckoutData.expectedAmount;
        }
        const qrImg = document.querySelector('.kaspa-qr-image');
        if (qrImg) {
            const qrData = encodeURIComponent(newAddress + '?amount=' + amount);
            const newQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + qrData + '&bgcolor=ffffff&color=667eea';
            qrImg.src = newQrUrl;
            qrImg.onload = function () {
                // Update the QR note text from Generating to Scan to pay
                const qrNote = document.querySelector('.kaspa-qr-note');
                if (qrNote) {
                    qrNote.textContent = 'Scan to pay';
                    qrNote.classList.remove('generating');
                }
            };
            qrImg.onerror = function () {
                console.error('❌ QR code failed to load');
            };
        }
        const addressElements = document.querySelectorAll('.kaspa-copy-text, .kaspa-copy-text-compact');
        addressElements.forEach(function (element) {
            const text = element.textContent || element.innerText;
            if (text.includes('kaspa:') || text.includes('Generating') || text.includes('pending')) {
                element.textContent = newAddress;
                const copyField = element.closest('.kaspa-copy-field') || element.closest('.kaspa-copy-field-compact');
                if (copyField) {
                    copyField.onclick = function () { copyToClipboard(newAddress); };
                }
            }
        });
        const addressDisplay = document.getElementById('kaspa-address-display');
        if (addressDisplay) {
            addressDisplay.textContent = newAddress;
        }
    }
    function saveOrderAddress(orderId, address, addressIndex) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '" . esc_url($ajax_url) . "', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        // Address saved successfully
                    } else {
                        console.error('❌ Failed to save address:', response.data);
                    }
                } catch (e) {
                    console.error('❌ Error parsing save response:', e);
                }
            }
        };
        let data = 'action=kasppaga_save_order_address&order_id=' + orderId + '&address=' + encodeURIComponent(address);
        if (addressIndex !== undefined && addressIndex >= 0) {
            data += '&address_index=' + addressIndex;
        }
        data += '&nonce=" . esc_js($save_address_nonce) . "';
        xhr.send(data);
    }";

    // Combine both scripts
    $full_inline_script = $inline_script . "\n" . $helper_functions;

    // Add the inline script
    wp_add_inline_script('kaspa-address-generation', $full_inline_script);
}

/**
 * Enqueue checkout CSS and JS assets
 */
function kasppaga_enqueue_checkout_assets($gateway_instance, $order_id, $expected_amount)
{
    // Enqueue CSS
    wp_enqueue_style(
        'kaspa-checkout-style',
        plugin_dir_url(__DIR__) . 'assets/kaspa-checkout.css',
        array(),
        '2.1.1'
    );

    // Enqueue checkout JS
    wp_enqueue_script(
        'kaspa-checkout-script',
        plugin_dir_url(__DIR__) . 'assets/kaspa-checkout.js',
        array(),
        '2.0.0',
        true
    );

    $order = wc_get_order($order_id);

    // Pass checkout data to JavaScript
    $current_rate = $gateway_instance->get_kas_rate();
    wp_localize_script('kaspa-checkout-script', 'kaspaCheckoutData', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'currentRate' => $current_rate ? (float) $current_rate : 0,
        'orderId' => $order_id ? (int) $order_id : 0,
        'expectedAmount' => $expected_amount,
        'paymentNonce' => wp_create_nonce('kasppaga_check_payment'),
        'myAccountUrl' => wc_get_page_permalink('myaccount'),
        'thankYouUrl' => $order ? $order->get_checkout_order_received_url() : '',
        'siteUrl' => get_site_url(),
    ));
}

/**
 * Render the checkout template
 */
function kasppaga_render_checkout_template($order_id, $expected_amount, $current_rate, $payment_address)
{
    ?>
    <div class="kaspa-payment-container">
        <!-- Compact Header with Price -->
        <div class="kaspa-payment-header-compact">
            <h2>💰 Pay <?php echo esc_html($expected_amount); ?> KAS</h2>
            <?php if ($current_rate): ?>
                <div class="kaspa-price-compact">
                    <span>Rate: $<span id="kaspa-current-price"><?php echo number_format($current_rate, 5); ?></span></span>
                    <small id="kaspa-last-update">Updated: <?php echo esc_html(current_time('H:i:s')); ?></small>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payment Methods - QR Code + Address + Amount in one compact section -->
        <div id="kaspa-payment-methods-container">
            <?php echo wp_kses_post(kasppaga_render_payment_methods($payment_address, $expected_amount)); ?>
        </div>

        <!-- Payment Status - Compact -->
        <div class="kaspa-payment-status-compact">
            <div id="kaspa-payment-status" class="kaspa-payment-status checking">
                ⏳ Setting up payment address...
            </div>
            <button id="kaspa-check-button" class="kaspa-check-button-compact" onclick="checkPaymentStatus()">
                Check Status
            </button>
        </div>

        <!-- Minimal Instructions -->
        <div class="kaspa-instructions-compact">
            <p><strong>📋 Quick Steps:</strong> Send <strong><?php echo esc_html($expected_amount); ?> KAS</strong> to the
                address above. Payment is detected automatically.</p>
            <p class="kaspa-critical-notice-compact"><strong>⚠️ Send exactly <?php echo esc_html($expected_amount); ?>
                    KAS</strong> for automatic confirmation.</p>
        </div>
    </div>
    <?php
}

// Just replace this function in your existing code - no CSS changes needed!

function kasppaga_render_payment_methods($payment_address, $kas_amount)
{
    // Ensure payment_address is a string
    if (!is_string($payment_address)) {
        if (is_array($payment_address) && isset($payment_address['address'])) {
            $payment_address = $payment_address['address'];
        } else {
            $payment_address = '(Generating address...)';
        }
    }

    // Check if address is pending/placeholder
    $is_pending = (
        strpos($payment_address, 'kaspa:') !== 0 &&
        strpos($payment_address, 'pending') === false &&
        strpos($payment_address, 'Generating') === false
    );

    // If address is pending, we'll show a placeholder QR code that will be updated by JS
    $kas_amount_formatted = number_format($kas_amount, 8, '.', '');

    if (strpos($payment_address, 'kaspa:') === 0) {
        // Valid address - generate real QR code
        $qr_data = urlencode($payment_address . '?amount=' . $kas_amount_formatted);
        $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={$qr_data}&bgcolor=ffffff&color=667eea";
        $show_qr = true;
    } else {
        // Pending address - show placeholder that JS will update
        $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=Loading...&bgcolor=ffffff&color=cccccc";
        $show_qr = true; // Still show QR code section, will be updated by JS
    }

    ob_start();
    ?>
    <div class="kaspa-payment-methods-compact">
        <!-- QR Code + Address + Amount in one row -->
        <div class="kaspa-payment-row">
            <!-- QR Code - Left Side -->
            <div class="kaspa-qr-compact">
                <?php if ($show_qr): ?>
                    <img src="<?php echo esc_url($qr_url); ?>" alt="Kaspa Payment QR Code" class="kaspa-qr-image"
                        id="kaspa-qr-image" />
                    <?php if (strpos($payment_address, 'kaspa:') !== 0): ?>
                        <p class="kaspa-qr-note generating">⏳ Generating...</p>
                    <?php else: ?>
                        <p class="kaspa-qr-note">Scan to pay</p>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="kaspa-qr-placeholder"
                        style="width: 160px; height: 160px; display: flex; align-items: center; justify-content: center; background: #f0f0f0; border-radius: 12px; color: #666;">
                        ⏳ Loading...
                    </div>
                <?php endif; ?>
            </div>

            <!-- Address + Amount - Right Side -->
            <div class="kaspa-payment-info">
                <!-- Address -->
                <div class="kaspa-info-item">
                    <label>Payment Address</label>
                    <div class="kaspa-copy-field-compact"
                        onclick="copyToClipboard('<?php echo esc_js($payment_address); ?>')">
                        <span class="kaspa-copy-text-compact" id="kaspa-address-display">
                            <?php echo esc_html($payment_address); ?>
                        </span>
                        <button type="button" class="kaspa-copy-button-compact">📋</button>
                    </div>
                </div>

                <!-- Amount -->
                <div class="kaspa-info-item">
                    <label>Amount to Send</label>
                    <div class="kaspa-copy-field-compact"
                        onclick="copyToClipboard('<?php echo esc_js($kas_amount_formatted); ?>')">
                        <span class="kaspa-copy-text-compact">
                            <?php echo esc_html($kas_amount_formatted); ?> KAS
                        </span>
                        <button type="button" class="kaspa-copy-button-compact">📋</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    // Register and enqueue script for inline script
    wp_register_script('kaspa-payment-methods', '', array(), '1.0.0', true);
    wp_enqueue_script('kaspa-payment-methods');

    // Add inline script
    $qr_data_js = esc_js($payment_address . '?amount=' . $kas_amount_formatted);
    $payment_methods_script = "document.addEventListener('DOMContentLoaded', function () {
        // Payment methods loaded
    });";
    wp_add_inline_script('kaspa-payment-methods', $payment_methods_script);

    return ob_get_clean();
}

/**
 * Render payment success message
 */
function kasppaga_render_payment_success($order_id, $payment_address, $txid)
{
    ?>
    <div class="kaspa-payment-success">
        <div class="kaspa-success-icon">✅</div>
        <h3>Payment Confirmed!</h3>
        <p>Your Kaspa payment has been received and confirmed.</p>

        <div class="kaspa-success-details">
            <p><strong>Order:</strong> #<?php echo esc_html($order_id); ?></p>
            <p><strong>Payment Address:</strong><br>
                <code><?php echo esc_html($payment_address); ?></code>
            </p>
            <p><strong>Transaction:</strong><br>
                <code><?php echo esc_html($txid); ?></code>
            </p>
        </div>

        <p>Your order is being processed. Thank you for your payment!</p>
    </div>
    <?php
}

// Add AJAX endpoint for live price updates
add_action('wp_ajax_get_kasppaga_price', 'kasppaga_get_live_price');
add_action('wp_ajax_nopriv_get_kasppaga_price', 'kasppaga_get_live_price');

function kasppaga_get_live_price()
{
    $kaspa_gateway = new KASPPAGA_WC_Gateway();
    $rate = $kaspa_gateway->get_kas_rate();

    if ($rate) {
        wp_send_json_success(array(
            'rate' => $rate,
            'formatted_rate' => number_format($rate, 5),
            'timestamp' => time(),
            'time_formatted' => current_time('H:i:s')
        ));
    } else {
        wp_send_json_error(array(
            'message' => 'Unable to fetch current rate'
        ));
    }
}

// Payment checking is handled by the transaction polling system
// No duplicate AJAX handler needed here

// Helper function to manually mark order as paid (for testing)
add_action('wp_ajax_kasppaga_manual_confirm', 'kasppaga_manual_confirm_payment');
add_action('wp_ajax_nopriv_kasppaga_manual_confirm', 'kasppaga_manual_confirm_payment');

function kasppaga_manual_confirm_payment()
{
    // This is for testing purposes - remove in production
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied');
        return;
    }

    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_manual_confirm')) {
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

    $expected_amount = $order->get_meta('_kaspa_expected_amount');

    // Mark payment as confirmed
    $order->update_meta_data('_kaspa_txid', $txid ?: 'manual-test-' . time());
    $order->update_meta_data('_kaspa_confirmed_amount', $expected_amount);
    $order->update_meta_data('_kaspa_payment_confirmed', time());

    // Update order status
    $order->update_status('processing', 'Kaspa payment manually confirmed for testing.');
    $order->save();

    wp_send_json_success(array(
        'message' => 'Payment confirmed',
        'order_id' => $order_id
    ));
}