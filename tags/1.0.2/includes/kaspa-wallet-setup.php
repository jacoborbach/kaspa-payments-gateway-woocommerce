<?php
/**
 * Kaspa Wallet Setup - KPUB Watch-Only (Secure)
 * File: includes/kaspa-wallet-setup.php
 * 
 * This plugin uses KPUB (Extended Public Key) watch-only wallets for security.
 * Mnemonic phrases and private keys are NOT handled or stored.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class KASPPAGA_Wallet_Setup
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_setup_menu'), 20);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_setup_assets'));

        // AJAX handlers - only KPUB save, no mnemonic handling
        add_action('wp_ajax_kasppaga_clear_wallet', array($this, 'ajax_clear_wallet'));
        add_action('wp_ajax_kasppaga_save_kpub_wallet', array($this, 'ajax_save_kpub_wallet'));

        // Handle wallet reset
        add_action('admin_init', array($this, 'maybe_reset_wallet'));
    }

    /**
     * Add setup menu to admin
     */
    public function add_setup_menu()
    {
        add_submenu_page(
            'kaspa-payments-gateway',
            'Wallet Setup',
            'Wallet Setup',
            'manage_woocommerce',
            'kaspa-wallet-setup',
            array($this, 'render_setup_page')
        );
    }

    /**
     * Enqueue setup assets with crypto libraries
     */
    public function enqueue_setup_assets($hook)
    {
        if (strpos($hook, 'kaspa-wallet-setup') === false) {
            return;
        }

        $plugin_url = plugin_dir_url(dirname(__FILE__));

        // SDK is not needed for KPUB-only setup - removed to avoid loading issues
        // Address derivation happens on checkout page when orders are created

        // Load wallet setup CSS
        wp_enqueue_style(
            'kaspa-wallet-setup-style',
            $plugin_url . 'assets/kaspa-wallet-setup.css',
            array(),
            '2.4.0'
        );

        // Load wallet setup JS (only depends on jquery - no SDK needed)
        wp_enqueue_script(
            'kaspa-wallet-setup-script',
            $plugin_url . 'assets/kaspa-wallet-setup.js',
            array('jquery'), // No SDK dependency - KPUB validation is format-only
            '2.4.0',
            true
        );

        // Localize script
        wp_localize_script('kaspa-wallet-setup-script', 'kaspaWalletSetup', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kasppaga_wallet_setup'),
            'redirectUrl' => admin_url('admin.php?page=wc-settings&tab=checkout&section=kaspa')
        ));
    }
    /**
     * AJAX: Save KPUB wallet configuration
     */
    public function ajax_save_kpub_wallet()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_wallet_setup') || !current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
            return;
        }

        $kpub = isset($_POST['kpub']) ? sanitize_text_field(wp_unslash($_POST['kpub'])) : '';
        $address = isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : '';
        $wallet_data = isset($_POST['wallet_data']) ? sanitize_text_field(wp_unslash($_POST['wallet_data'])) : '';

        if (empty($kpub)) {
            wp_send_json_error('Missing KPUB data');
            return;
        }

        // Note: We removed the address offset - addresses now start from index 0
        // This ensures maximum visibility in Kaspium wallets

        // Validate KPUB format
        if (!$this->is_valid_kpub($kpub)) {
            wp_send_json_error('Invalid KPUB format');
            return;
        }

        // If address is missing or invalid, we'll derive it from KPUB on the server
        // For now, accept KPUB without address - address derivation can happen later
        $valid_address = null;
        if (!empty($address) && $address !== 'pending-derivation' && $this->is_valid_kaspa_address($address)) {
            $valid_address = $address;
        } else {
            // Address will need to be derived from KPUB
            // For now, we'll use a placeholder and derive it when needed
            // TODO: Implement server-side address derivation from KPUB
            error_log('Kaspa: KPUB saved without address - address derivation needed');
            $valid_address = null; // Will be derived when first address is needed
        }

        // Save KPUB wallet data (no encryption needed as KPUB is safe to store)
        update_option('kasppaga_wallet_kpub', $kpub);

        // Only save address if we have a valid one
        if ($valid_address) {
            update_option('kasppaga_wallet_address', $valid_address);
            error_log('Kaspa Wallet: KPUB watch-only wallet configured with address: ' . substr($valid_address, 0, 20) . '...');
        } else {
            // Clear any old address since we'll derive it from KPUB
            delete_option('kasppaga_wallet_address');
            error_log('Kaspa Wallet: KPUB saved - address will be derived from KPUB when needed');
        }

        update_option('kasppaga_wallet_configured', true);
        update_option('kasppaga_wallet_setup_date', current_time('mysql'));
        update_option('kasppaga_address_type', 'kpub_watch_only');
        update_option('kasppaga_wallet_data', $wallet_data);

        // Enable the payment gateway
        $settings = get_option('woocommerce_kaspa_settings', array());
        $settings['enabled'] = 'yes';
        if ($valid_address) {
            $settings['kaspa_address'] = $valid_address;
        } else {
            unset($settings['kaspa_address']);
        }
        $settings['wallet_type'] = 'kpub_watch_only';
        update_option('woocommerce_kaspa_settings', $settings);

        wp_send_json_success(array(
            'message' => $valid_address ? 'KPUB watch-only wallet saved successfully' : 'KPUB saved - address will be derived when needed',
            'address_type' => 'kpub_watch_only',
            'address_provided' => !empty($valid_address),
            'redirect_url' => admin_url('admin.php?page=kaspa-wallet-setup')
        ));
    }

    /**
     * Render the setup page
     */
    public function render_setup_page()
    {
        $wallet_configured = get_option('kasppaga_wallet_configured');
        $wallet_address = get_option('kasppaga_wallet_address');

        // If address is pending-derivation or empty, check if we have KPUB
        if (($wallet_address === 'pending-derivation' || empty($wallet_address)) && get_option('kasppaga_wallet_kpub')) {
            $wallet_configured = true; // Consider it configured if we have KPUB
        }

        if ($wallet_configured) {
            $this->render_wallet_dashboard($wallet_address ?: 'pending-derivation');
        } else {
            $this->render_wallet_setup_wizard();
        }
    }

    /**
     * Render wallet setup wizard - KPUB Only (Watch-Only)
     */
    private function render_wallet_setup_wizard()
    {
        ?>
        <div class="wrap kaspa-wallet-setup">
            <div class="kaspa-setup-container">
                <div class="kaspa-setup-header">
                    <h1>💎 Kaspa Wallet Setup</h1>
                    <p>Set up your Kaspa wallet using an Extended Public Key (KPUB) for secure watch-only access</p>
                    <div class="kaspa-production-notice">
                        <strong>🔐 Watch-Only Mode:</strong> This is a secure read-only wallet setup. No private keys or
                        mnemonics are stored.
                    </div>
                </div>

                <div class="kaspa-setup-content">
                    <!-- Step Indicator -->
                    <div class="kaspa-step-indicator">
                        <div class="kaspa-step-dot active" id="dot-1"></div>
                        <div class="kaspa-step-dot" id="dot-2"></div>
                    </div>

                    <!-- Step 1: Import KPUB -->
                    <div class="kaspa-setup-step active" id="step-1">
                        <h2>🔑 Import Extended Public Key (KPUB)</h2>
                        <p>Enter your Kaspa extended public key to set up watch-only wallet functionality for secure payment
                            monitoring.</p>

                        <div class="kaspa-security-notice"
                            style="background: #e7f3ff; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #2271b1;">
                            <h4>🔐 Security First</h4>
                            <p><strong>This plugin uses KPUB (watch-only) wallets for maximum security:</strong></p>
                            <ul style="margin-left: 20px;">
                                <li>✅ No private keys or mnemonics are stored on your WordPress server</li>
                                <li>✅ KPUB allows address generation without spending capability</li>
                                <li>✅ Perfect for payment processing and monitoring</li>
                                <li>✅ Your private keys stay in your secure wallet software</li>
                            </ul>
                        </div>

                        <form id="kaspa-kpub-form">
                            <div class="kaspa-form-group">
                                <label for="kaspa-kpub-input">Extended Public Key (KPUB)</label>
                                <textarea id="kaspa-kpub-input" name="kpub"
                                    placeholder="Enter your Kaspa extended public key (kpub...)..." rows="3"
                                    required></textarea>
                                <div class="kaspa-help-text">
                                    <strong>How to get your KPUB:</strong>
                                    <ul style="margin-left: 20px; margin-top: 10px;">
                                        <li>Open your Kaspa wallet software (Kaspium, Kaspa Wallet, etc.)</li>
                                        <li>Navigate to wallet settings or export options</li>
                                        <li>Export or view your Extended Public Key (XPUB/KPUB)</li>
                                        <li>Copy the KPUB (starts with "kpub" and is ~111 characters)</li>
                                    </ul>
                                </div>
                            </div>


                            <div class="kaspa-kpub-info">
                                <h4>🔍 What is a KPUB?</h4>
                                <ul>
                                    <li><strong>Extended Public Key</strong> for Kaspa HD wallets</li>
                                    <li><strong>Safe to share</strong> - cannot be used to spend funds</li>
                                    <li><strong>Generate addresses</strong> - creates unlimited receiving addresses</li>
                                    <li><strong>Monitor payments</strong> - view balances and transaction history</li>
                                    <li><strong>Perfect for businesses</strong> - ideal for payment processing</li>
                                </ul>
                            </div>

                            <div class="kaspa-validation-result" id="kaspa-kpub-validation"></div>

                            <div class="kaspa-button-group">
                                <button type="submit" class="kaspa-btn kaspa-btn-primary" id="kaspa-kpub-btn">
                                    Validate & Import KPUB
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Step 2: Setup Complete -->
                    <div class="kaspa-setup-step" id="step-2">
                        <h2>✅ Kaspa Wallet Setup Complete!</h2>
                        <p>Your Kaspa watch-only wallet has been successfully configured and is ready to receive payments.</p>

                        <div class="kaspa-success-summary">
                            <h4>🎉 Setup Summary</h4>
                            <div class="kaspa-wallet-info">
                                <div class="kaspa-info-item">
                                    <strong>Wallet Type:</strong>
                                    <span>🔑 KPUB Watch-Only</span>
                                </div>
                                <div class="kaspa-info-item">
                                    <strong>KPUB (Extended Public Key):</strong>
                                    <div class="kaspa-address-display" style="font-size: 11px; word-break: break-all;">
                                        <?php
                                        $kpub = get_option('kasppaga_wallet_kpub', '');
                                        echo esc_html($kpub ? substr($kpub, 0, 30) . '...' . substr($kpub, -20) : 'Not configured');
                                        ?>
                                    </div>
                                </div>
                                <div class="kaspa-info-item">
                                    <strong>Security:</strong>
                                    <span>✅ No private keys stored</span>
                                </div>
                                <div class="kaspa-info-item">
                                    <strong>Status:</strong>
                                    <span class="kaspa-status-active">🟢 Ready to receive payments</span>
                                </div>
                                <div class="kaspa-info-item"
                                    style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">
                                    <small style="color: #666; font-style: italic;">
                                        ℹ️ Unique addresses are automatically generated for each order from your KPUB
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="kaspa-next-steps">
                            <h4>🎯 Next Steps</h4>
                            <ul class="kaspa-feature-list">
                                <li>Enable the Kaspa payment method in WooCommerce settings</li>
                                <li>Test with a small payment to verify everything works</li>
                                <li>Monitor payments in the Kaspa admin dashboard</li>
                                <li>Each order will get a unique receiving address generated from your KPUB</li>
                            </ul>
                        </div>

                        <div class="kaspa-button-group">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=kaspa')); ?>"
                                class="kaspa-btn kaspa-btn-primary">
                                Go to Payment Settings
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=kaspa-payments-gateway')); ?>"
                                class="kaspa-btn kaspa-btn-secondary">
                                View Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function maybe_reset_wallet()
    {
        if (!is_admin() || !current_user_can('manage_options'))
            return;

        // Verify nonce for reset action
        if (isset($_GET['page']) && $_GET['page'] === 'kaspa-wallet-setup' && isset($_GET['action']) && $_GET['action'] === 'reset') {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'kaspa_reset_wallet')) {
                wp_die('Security check failed');
                return;
            }
            error_log('[Kaspa Wallet] Reset triggered by admin');

            // Clear all wallet data
            delete_option('kasppaga_wallet_address');
            delete_option('kasppaga_wallet_configured');
            delete_option('kasppaga_wallet_setup_date');
            delete_option('kasppaga_address_type');
            delete_option('kasppaga_wallet_kpub');
            delete_option('kasppaga_wallet_data');

            $settings = get_option('woocommerce_kaspa_settings', array());
            $settings['enabled'] = 'no';
            unset($settings['kaspa_address']);
            unset($settings['wallet_type']);
            update_option('woocommerce_kaspa_settings', $settings);

            wp_safe_redirect(admin_url('admin.php?page=kaspa-wallet-setup'));
            exit;
        }
    }


    /**
     * Render wallet dashboard (when wallet is already configured)
     */
    private function render_wallet_dashboard($wallet_address)
    {
        $wallet_type = get_option('kasppaga_address_type', 'unknown');
        $has_kpub = !empty(get_option('kasppaga_wallet_kpub'));

        // If we have KPUB, it's definitely a KPUB wallet (even if type isn't set yet)
        $is_kpub_wallet = ($wallet_type === 'kpub_watch_only') || $has_kpub;
        ?>
        <div class="wrap kaspa-wallet-setup">
            <div class="kaspa-setup-container">
                <div class="kaspa-setup-header">
                    <h1>✅ Kaspa Wallet Active</h1>
                    <p><?php echo esc_html($is_kpub_wallet ? 'Your watch-only wallet (KPUB) is configured and ready to receive payments' : 'Your wallet is configured and ready to receive payments'); ?>
                    </p>
                </div>

                <div class="kaspa-setup-content">
                    <div class="kaspa-wallet-dashboard">
                        <div class="kaspa-wallet-status">
                            <h3>📊 Wallet Status</h3>
                            <div class="kaspa-status-grid">
                                <div class="kaspa-status-item">
                                    <span class="kaspa-status-label">Status:</span>
                                    <span class="kaspa-status-value success">🟢 Active</span>
                                </div>
                                <div class="kaspa-status-item">
                                    <span class="kaspa-status-label">Type:</span>
                                    <span class="kaspa-status-value">
                                        <?php if ($is_kpub_wallet): ?>
                                            🔑 KPUB Watch-Only
                                        <?php else: ?>
                                            💎 Full Wallet
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="kaspa-status-item">
                                    <span class="kaspa-status-label">Address Type:</span>
                                    <span class="kaspa-status-value">
                                        <?php if ($wallet_address === 'pending-derivation'): ?>
                                            ⏳ Will be derived from KPUB
                                        <?php else: ?>
                                            💎 Real Mainnet
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="kaspa-status-item">
                                    <span class="kaspa-status-label">Security:</span>
                                    <span class="kaspa-status-value">
                                        <?php if ($is_kpub_wallet): ?>
                                            🔒 Watch-Only (No Private Keys)
                                        <?php else: ?>
                                            🔒 Password Protected
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <?php if ($is_kpub_wallet): ?>
                            <div class="kaspa-kpub-info">
                                <h3>🔑 KPUB Wallet Information</h3>
                                <div class="kaspa-info-box">
                                    <p><strong>✅ Benefits:</strong></p>
                                    <ul>
                                        <li>Can generate unlimited receiving addresses</li>
                                        <li>Safe for business monitoring</li>
                                        <li>No private keys stored</li>
                                        <li>Perfect for payment processing</li>
                                    </ul>
                                    <p><strong>⚠️ Limitations:</strong></p>
                                    <ul>
                                        <li>Cannot send transactions</li>
                                        <li>View-only access</li>
                                    </ul>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="kaspa-wallet-info">
                            <h3>💰 Wallet Information</h3>
                            <?php if ($wallet_address && $wallet_address !== 'pending-derivation'): ?>
                                <div class="kaspa-info-item">
                                    <label>Primary Address (m/44'/111111'/0'/0/0):</label>
                                    <div class="kaspa-address-display">
                                        <?php echo esc_html($wallet_address); ?>
                                        <button class="kaspa-btn kaspa-btn-small"
                                            onclick="copyAddress('<?php echo esc_js($wallet_address); ?>')">📋 Copy</button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="kaspa-info-item">
                                    <label>Address Status:</label>
                                    <div class="kaspa-address-display">
                                        <span style="color: #666; font-style: italic;">
                                            ℹ️ Address will be automatically derived from your KPUB when orders are created
                                        </span>
                                    </div>
                                </div>
                                <?php if ($is_kpub_wallet): ?>
                                    <div class="kaspa-info-item" style="margin-top: 15px;">
                                        <label>KPUB (Extended Public Key):</label>
                                        <div class="kaspa-address-display" style="font-size: 11px; word-break: break-all;">
                                            <?php
                                            $kpub = get_option('kasppaga_wallet_kpub', '');
                                            echo esc_html($kpub ? substr($kpub, 0, 30) . '...' . substr($kpub, -20) : 'Not available');
                                            ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <div class="kaspa-wallet-balance-display" id="kaspa-auto-balance"
                            style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; display: none;">
                            <h3>💰 Wallet Balance</h3>
                            <div id="balance-loading" style="color: #666;">
                                <small>🔄 Loading balance...</small>
                            </div>
                            <div id="balance-content" style="display: none;"></div>
                        </div>

                        <div class="kaspa-wallet-actions">
                            <h3>⚙️ Actions</h3>
                            <div class="kaspa-button-group">
                                <button class="kaspa-btn kaspa-btn-primary" onclick="checkWalletBalance()">
                                    💰 Refresh Balance
                                </button>
                                <button class="kaspa-btn kaspa-btn-secondary" onclick="showKPUBInfo()">
                                    🔑 View KPUB Info
                                </button>
                                <button class="kaspa-btn kaspa-btn-warning" onclick="reconfigureWallet()">
                                    🔄 Reconfigure Wallet
                                </button>
                            </div>
                        </div>

                        <div class="kaspa-security-reminders">
                            <h3>🛡️ Security Reminders</h3>
                            <div class="kaspa-reminder-box">
                                <ul>
                                    <li><strong>KPUB Security:</strong> Your KPUB is safe to store - it cannot spend funds</li>
                                    <li><strong>Private Keys:</strong> Keep your private keys secure in your wallet software
                                    </li>
                                    <li><strong>Address:</strong> This is a real Kaspa mainnet address - payments will be
                                        received</li>
                                    <li><strong>Testing:</strong> Test with small amounts before processing large payments</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        // Prepare data for inline script
        $ajax_url = admin_url('admin-ajax.php');
        $consolidated_balance_nonce = wp_create_nonce('kasppaga_consolidated_balance');
        $kpub_js = esc_js(get_option('kasppaga_wallet_kpub', ''));
        $reset_url = wp_nonce_url(admin_url('admin.php?page=kaspa-wallet-setup&action=reset'), 'kaspa_reset_wallet');

        // Build inline script
        $wallet_setup_script = "document.addEventListener('DOMContentLoaded', function () {
            setTimeout(function () {
                autoCheckWalletBalance();
            }, 500);
        });
        function autoCheckWalletBalance() {
            const balanceDisplay = document.getElementById('kaspa-auto-balance');
            const balanceLoading = document.getElementById('balance-loading');
            const balanceContent = document.getElementById('balance-content');
            if (!balanceDisplay) return;
            balanceDisplay.style.display = 'block';
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '" . esc_url($ajax_url) . "', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    balanceLoading.style.display = 'none';
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                const totalBalance = parseFloat(response.data.total_balance || 0).toFixed(8);
                                const usdValue = response.data.total_usd_value ? '$' + parseFloat(response.data.total_usd_value).toFixed(2) : 'N/A';
                                const addressCount = response.data.address_count || 0;
                                const kasRate = response.data.kas_rate;
                                balanceContent.innerHTML = '<div style=\"text-align: center;\"><div style=\"font-size: 32px; font-weight: bold; color: #2271b1; margin: 10px 0;\">' + totalBalance + ' KAS</div><div style=\"font-size: 18px; color: #666; margin-bottom: 15px;\">' + usdValue + (kasRate ? '<small style=\"display: block; margin-top: 5px;\">Rate: $' + parseFloat(kasRate).toFixed(6) + '/KAS</small>' : '') + '</div><div style=\"font-size: 12px; color: #999; margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;\"><small>💰 Checking ' + addressCount + ' address' + (addressCount !== 1 ? 'es' : '') + ' (main wallet + order addresses)</small><br><small>Last updated: ' + new Date().toLocaleString() + '</small></div></div>';
                                balanceContent.style.display = 'block';
                            } else {
                                balanceContent.innerHTML = '<div style=\"text-align: center; color: #666;\"><small>ℹ️ ' + (response.data || 'Unable to load balance. Addresses will be checked when orders are created.') + '</small></div>';
                                balanceContent.style.display = 'block';
                            }
                        } catch (e) {
                            balanceContent.innerHTML = '<div style=\"text-align: center; color: #666;\"><small>ℹ️ Balance check unavailable. Addresses will be monitored when orders are created.</small></div>';
                            balanceContent.style.display = 'block';
                        }
                    } else {
                        balanceContent.innerHTML = '<div style=\"text-align: center; color: #666;\"><small>ℹ️ Balance check unavailable. Addresses will be monitored when orders are created.</small></div>';
                        balanceContent.style.display = 'block';
                    }
                }
            };
            const data = 'action=kasppaga_get_consolidated_balance&nonce=" . esc_js($consolidated_balance_nonce) . "';
            xhr.send(data);
        }
        function copyAddress(address) {
            if (!address) {
                alert('❌ No address provided to copy');
                return;
            }
            console.log('📋 Copying address:', address);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(address).then(() => {
                    alert('✅ Address copied to clipboard!');
                }).catch((err) => {
                    console.warn('Clipboard API failed:', err);
                    fallbackCopyAddress(address);
                });
            } else {
                fallbackCopyAddress(address);
            }
            function fallbackCopyAddress(addressText) {
                try {
                    const textArea = document.createElement('textarea');
                    textArea.value = addressText;
                    textArea.style.position = 'fixed';
                    textArea.style.left = '-999999px';
                    textArea.style.top = '-999999px';
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    const successful = document.execCommand('copy');
                    document.body.removeChild(textArea);
                    if (successful) {
                        alert('✅ Address copied to clipboard!');
                    } else {
                        throw new Error('execCommand failed');
                    }
                } catch (err) {
                    console.warn('Fallback copy failed:', err);
                    showAddressForManualCopy(addressText);
                }
            }
            function showAddressForManualCopy(addressText) {
                const message = 'Copy this Kaspa address:\\n\\n' + addressText + '\\n\\nPress Ctrl+C to copy, then click OK.';
                const tempInput = document.createElement('input');
                tempInput.value = addressText;
                tempInput.style.position = 'fixed';
                tempInput.style.left = '-999999px';
                document.body.appendChild(tempInput);
                tempInput.select();
                alert(message);
                document.body.removeChild(tempInput);
            }
        }
        function checkWalletBalance() {
            const button = document.querySelector('button[onclick=\"checkWalletBalance()\"]');
            if (!button) return;
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = '🔄 Refreshing...';
            const balanceLoading = document.getElementById('balance-loading');
            const balanceContent = document.getElementById('balance-content');
            if (balanceLoading) balanceLoading.style.display = 'block';
            if (balanceContent) balanceContent.style.display = 'none';
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '" . esc_url($ajax_url) . "', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    button.disabled = false;
                    button.textContent = originalText;
                    if (balanceLoading) balanceLoading.style.display = 'none';
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                const totalBalance = parseFloat(response.data.total_balance || 0).toFixed(8);
                                const usdValue = response.data.total_usd_value ? '$' + parseFloat(response.data.total_usd_value).toFixed(2) : 'N/A';
                                const addressCount = response.data.address_count || 0;
                                const kasRate = response.data.kas_rate;
                                if (balanceContent) {
                                    balanceContent.innerHTML = '<div style=\"text-align: center;\"><div style=\"font-size: 32px; font-weight: bold; color: #2271b1; margin: 10px 0;\">' + totalBalance + ' KAS</div><div style=\"font-size: 18px; color: #666; margin-bottom: 15px;\">' + usdValue + (kasRate ? '<small style=\"display: block; margin-top: 5px;\">Rate: $' + parseFloat(kasRate).toFixed(6) + '/KAS</small>' : '') + '</div><div style=\"font-size: 12px; color: #999; margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;\"><small>💰 Checking ' + addressCount + ' order address' + (addressCount !== 1 ? 'es' : '') + '</small><br><small style=\"color: #d63638; font-style: italic;\">⚠️ Only shows balance from plugin-generated order addresses. Funds sent manually to other addresses won\\'t appear here.</small><br><small>Last updated: ' + new Date().toLocaleString() + '</small></div></div>';
                                    balanceContent.style.display = 'block';
                                }
                            } else {
                                if (balanceContent) {
                                    balanceContent.innerHTML = '<div style=\"text-align: center; color: #666;\"><small>ℹ️ ' + (response.data || 'Unable to load balance') + '</small></div>';
                                    balanceContent.style.display = 'block';
                                }
                            }
                        } catch (e) {
                            console.error('Balance check error:', e);
                            if (balanceContent) {
                                balanceContent.innerHTML = '<div style=\"text-align: center; color: #666;\"><small>ℹ️ Error loading balance. Please try again.</small></div>';
                                balanceContent.style.display = 'block';
                            }
                        }
                    }
                }
            };
            const data = 'action=kasppaga_get_consolidated_balance&nonce=" . esc_js($consolidated_balance_nonce) . "';
            xhr.send(data);
        }
        function showKPUBInfo() {
            const kpub = '{$kpub_js}';
            if (kpub) {
                const message = 'Your Extended Public Key (KPUB):\\n\\n' + kpub + '\\n\\n⚠️ Keep this secure - it\\'s used to generate receiving addresses.\\nNote: This KPUB cannot spend funds, only generate addresses.';
                alert(message);
            } else {
                alert('KPUB information not available.');
            }
        }
        function reconfigureWallet() {
            if (confirm('Are you sure you want to reconfigure your wallet? This will remove your current KPUB configuration and you will need to import a new KPUB.')) {
                window.location.href = '" . esc_url($reset_url) . "';
            }
        }";

        // Add inline script to the already enqueued script
        wp_add_inline_script('kaspa-wallet-setup-script', $wallet_setup_script);
    }

    private function is_valid_kpub($kpub)
    {
        // Basic validation for Kaspa KPUB format
        if (!is_string($kpub) || strlen($kpub) < 100) {
            return false;
        }

        // Check for kpub prefix
        if (strpos($kpub, 'kpub') !== 0) {
            return false;
        }

        // Check length (typical KPUB length is around 111 characters)
        if (strlen($kpub) < 110 || strlen($kpub) > 120) {
            return false;
        }

        return true;
    }

    // ajax_save_wallet removed - only KPUB wallets are supported

    /**
     * Validate Kaspa address format
     */
    private function is_valid_kaspa_address($address)
    {
        // Basic validation for Kaspa address format
        if (!is_string($address) || strlen($address) < 10) {
            return false;
        }

        // Check for kaspa: prefix
        if (strpos($address, 'kaspa:') !== 0) {
            return false;
        }

        // Check length (Kaspa addresses are typically around 63 characters)
        if (strlen($address) < 50 || strlen($address) > 90) {
            return false;
        }

        // Check for valid Bech32m characters after prefix
        $address_part = substr($address, 6); // Remove 'kaspa:'
        if (!preg_match('/^[qpzry9x8gf2tvdw0s3jn54khce6mua7l]+$/', $address_part)) {
            return false;
        }

        return true;
    }

    /**
     * AJAX: Clear wallet configuration
     */
    public function ajax_clear_wallet()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_wallet_setup') || !current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
            return;
        }

        // Remove all wallet data
        delete_option('kasppaga_wallet_address');
        delete_option('kasppaga_wallet_configured');
        delete_option('kasppaga_wallet_setup_date');
        delete_option('kasppaga_address_type');
        delete_option('kasppaga_wallet_kpub');
        delete_option('kasppaga_wallet_data');

        // Disable the payment gateway
        $settings = get_option('woocommerce_kaspa_settings', array());
        $settings['enabled'] = 'no';
        unset($settings['kaspa_address']);
        update_option('woocommerce_kaspa_settings', $settings);

        wp_send_json_success(array(
            'message' => 'Wallet configuration cleared',
            'redirect_url' => admin_url('admin.php?page=kaspa-wallet-setup')
        ));
    }
}

// Initialize the wallet setup
new KASPPAGA_Wallet_Setup();

/**
 * AJAX handler for checking wallet balance
 */
add_action('wp_ajax_kasppaga_check_wallet_balance', 'kasppaga_check_wallet_balance_ajax');
add_action('wp_ajax_kasppaga_get_consolidated_balance', 'kasppaga_get_consolidated_balance_ajax');

// Removed: Kaspa_PHP_Address_Generator class - no longer needed (KPUB-only)

function kasppaga_check_wallet_balance_ajax()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_wallet_balance') || !current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied');
        return;
    }

    $address = isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : '';

    if (empty($address)) {
        wp_send_json_error('Address is required');
        return;
    }

    // Check if address is pending derivation
    if ($address === 'pending-derivation' || !preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $address)) {
        wp_send_json_error('Address not yet derived. Addresses will be generated automatically when orders are created.');
        return;
    }

    try {
        // Use the transaction polling class to get balance
        $polling = new KASPPAGA_Transaction_Polling();
        $balance = $polling->get_kaspa_balance($address);

        if ($balance === false) {
            wp_send_json_error('Failed to fetch balance from Kaspa API. The address may be invalid or the API may be temporarily unavailable.');
            return;
        }

        // Get current KAS price for USD conversion
        $gateway = new KASPPAGA_WC_Gateway();
        $kas_rate = $gateway->get_kas_rate();
        $usd_value = $kas_rate ? $balance * $kas_rate : null;

        wp_send_json_success(array(
            'balance' => $balance,
            'usd_value' => $usd_value,
            'kas_rate' => $kas_rate,
            'address' => $address,
            'timestamp' => time()
        ));

    } catch (Exception $e) {
        error_log('Kaspa balance check error: ' . $e->getMessage());
        wp_send_json_error('Error checking balance: ' . $e->getMessage());
    }
}

/**
 * AJAX handler for getting consolidated wallet balance
 */
function kasppaga_get_consolidated_balance_ajax()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'kasppaga_consolidated_balance') || !current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied');
        return;
    }

    try {
        $polling = new KASPPAGA_Transaction_Polling();
        $total_balance = 0;
        $addresses_checked = array();
        $address_count = 0;

        // Verify wallet is configured
        $kpub = get_option('kasppaga_wallet_kpub');
        if (!$kpub) {
            wp_send_json_error('Wallet not configured - KPUB required');
            return;
        }

        // Only check addresses from orders generated by the plugin
        // Note: We cannot efficiently check ALL addresses in a KPUB wallet (would require checking infinite indices)
        // So we only show balance from plugin-managed order addresses

        // Get all Kaspa orders with payment addresses
        $orders = wc_get_orders(array(
            'payment_method' => 'kaspa',
            'limit' => -1,
            'status' => array('pending', 'on-hold', 'processing', 'completed'),
            'meta_query' => array(
                array(
                    'key' => '_kaspa_payment_address',
                    'compare' => 'EXISTS'
                )
            )
        ));

        // Check all order-specific addresses
        foreach ($orders as $order) {
            $payment_address = $order->get_meta('_kaspa_payment_address');
            if ($payment_address && is_string($payment_address) && !in_array($payment_address, $addresses_checked)) {
                // Validate address format (must be kaspa: followed by 61-63 alphanumeric characters)
                if (preg_match('/^kaspa:[a-z0-9]{61,63}$/i', $payment_address)) {
                    $balance = $polling->get_kaspa_balance($payment_address);
                    if ($balance !== false) {
                        $total_balance += $balance;
                        $addresses_checked[] = $payment_address;
                        $address_count++;
                    }
                }
            }
        }

        // Get current KAS price for USD conversion
        $gateway = new KASPPAGA_WC_Gateway();
        $kas_rate = $gateway->get_kas_rate();
        $total_usd_value = $kas_rate ? $total_balance * $kas_rate : null;

        wp_send_json_success(array(
            'total_balance' => $total_balance,
            'total_usd_value' => $total_usd_value,
            'kas_rate' => $kas_rate,
            'address_count' => $address_count,
            'addresses_checked' => $addresses_checked,
            'note' => 'Balance shown is only from plugin-generated order addresses. Funds sent manually to other addresses in your KPUB wallet will not appear here.',
            'timestamp' => time()
        ));

    } catch (Exception $e) {
        error_log('Kaspa consolidated balance error: ' . $e->getMessage());
        wp_send_json_error('Error getting consolidated balance: ' . $e->getMessage());
    }
}