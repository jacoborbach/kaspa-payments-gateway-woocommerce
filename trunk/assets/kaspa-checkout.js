/**
 * Updated Kaspa Checkout JavaScript - Polling Only (No WebSocket)
 * Simplified version that works with direct wallet payments using AJAX polling
 */

(function () {
    'use strict';

    // Global variables
    let priceUpdateInterval;
    let countdownInterval;
    let paymentCheckInterval;
    let secondsRemaining = 15;
    let paymentCheckActive = false;

    // Get data from WordPress
    const ajaxUrl = window.kaspaCheckoutData ? window.kaspaCheckoutData.ajaxUrl : '';
    const orderId = window.kaspaCheckoutData ? window.kaspaCheckoutData.orderId : 0;
    const expectedAmount = window.kaspaCheckoutData ? window.kaspaCheckoutData.expectedAmount : 0;
    const paymentNonce = window.kaspaCheckoutData ? window.kaspaCheckoutData.paymentNonce : '';
    const myAccountUrl = window.kaspaCheckoutData ? window.kaspaCheckoutData.myAccountUrl : '';
    const thankYouUrl = window.kaspaCheckoutData ? window.kaspaCheckoutData.thankYouUrl : '';

    // No WebSocket functions needed - using AJAX polling only

    /**
     * Initialize the checkout system
     */
    function initializeCheckout() {
        console.log('🚀 Kaspa Checkout initialized (Polling Only)');
        console.log('Order ID:', orderId);
        console.log('Expected Amount:', expectedAmount, 'KAS');
        console.log('AJAX URL:', ajaxUrl);
        console.log('Payment Nonce:', paymentNonce);
        console.log('kaspaCheckoutData:', window.kaspaCheckoutData);

        // Start price updates if price widget exists
        if (document.getElementById('kaspa-current-price')) {
            startPriceUpdates();
        }

        // Show initial status message
        updatePaymentStatus('⏳ Setting up payment address...', 'checking');

        // Delay payment monitoring to allow address generation (15 seconds)
        setTimeout(function () {
            startPaymentMonitoring();
        }, 15000); // Wait 15 seconds before first check

        // Set up cleanup on page unload
        window.addEventListener('beforeunload', cleanup);
    }

    /**
     * Start monitoring for payment (AJAX polling)
     */
    function startPaymentMonitoring() {
        console.log('👁️ startPaymentMonitoring() called - paymentCheckActive:', paymentCheckActive, 'orderId:', orderId);

        if (paymentCheckActive || !orderId) {
            console.log('❌ Payment monitoring skipped - paymentCheckActive:', paymentCheckActive, 'orderId:', orderId);
            return;
        }

        console.log('👁️ Starting AJAX payment monitoring...');
        paymentCheckActive = true;

        // Update status message
        updatePaymentStatus('🔄 Monitoring for payment...', 'checking');

        // Check immediately (after the 15 second delay), then every 15 seconds
        checkPaymentStatus();
        paymentCheckInterval = setInterval(checkPaymentStatus, 15000);

        // Stop after 30 minutes
        setTimeout(function () {
            stopPaymentChecking();
            updatePaymentStatus('⏰ Payment monitoring timeout. Please contact support if you sent payment.', 'timeout');
        }, 1800000); // 30 minutes
    }

    /**
     * Check payment status via AJAX polling
     */
    function checkPaymentStatus() {
        console.log('🔍 checkPaymentStatus() called - paymentCheckActive:', paymentCheckActive, 'ajaxUrl:', ajaxUrl, 'orderId:', orderId);

        if (!paymentCheckActive || !ajaxUrl) {
            console.log('❌ Payment check skipped - paymentCheckActive:', paymentCheckActive, 'ajaxUrl:', ajaxUrl);
            return;
        }

        console.log('🔍 Checking payment status via AJAX...');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            handlePaymentResponse(response.data);
                        } else {
                            // Don't show error if address is still being generated
                            const errorMsg = response.data || '';
                            if (errorMsg.includes('Missing payment information') || errorMsg.includes('Generating')) {
                                console.log('Address still being generated, will retry...');
                                updatePaymentStatus('⏳ Setting up payment address...', 'checking');
                            } else {
                                console.error('Payment check failed:', response.data);
                                updatePaymentStatus('❌ Payment check failed: ' + response.data, 'error');
                            }
                        }
                    } catch (e) {
                        console.error('Payment check parse error:', e);
                        updatePaymentStatus('❌ Error checking payment status', 'error');
                    }
                } else {
                    console.error('Payment check HTTP error:', xhr.status);
                    updatePaymentStatus('❌ Network error checking payment', 'error');
                }
            }
        };

        const data = `action=kasppaga_check_payment&order_id=${orderId}&nonce=${paymentNonce}`;
        xhr.send(data);
    }

    /**
     * Handle payment response from AJAX polling
     */
    function handlePaymentResponse(data) {
        console.log('📨 AJAX Payment response:', data);

        if (data.status === 'completed') {
            handlePaymentConfirmed(data);
        } else if (data.status === 'pending') {
            updatePaymentStatus(data.message || '⏳ Waiting for payment...', 'checking');
        } else {
            updatePaymentStatus(data.message || '❓ Unknown payment status', 'error');
        }
    }

    /**
     * Handle payment confirmation from AJAX polling
     */
    function handlePaymentConfirmed(data) {
        console.log('🎉 AJAX Payment confirmed!', data);

        stopPaymentChecking();
        updatePaymentStatus('✅ Payment confirmed! Your order is being processed.', 'success');
        showNotification('🎉 Payment received and confirmed!', 'success');

        // Show success details if available
        if (data.txid) {
            const statusEl = document.getElementById('kaspa-payment-status');
            if (statusEl) {
                statusEl.innerHTML += `<br><small>Transaction: ${data.txid}</small>`;
            }
        }

        // Redirect to thank you page after a few seconds
        setTimeout(function () {
            if (thankYouUrl) {
                // Redirect to WooCommerce thank you page (order received page)
                window.location.href = thankYouUrl;
            } else if (myAccountUrl) {
                // Fallback to account page if thank you URL not available
                window.location.href = myAccountUrl;
            } else {
                // Last resort: reload the page
                window.location.reload();
            }
        }, 3000);
    }

    /**
     * Update payment status display
     */
    function updatePaymentStatus(message, status) {
        const statusEl = document.getElementById('kaspa-payment-status');
        if (statusEl) {
            statusEl.textContent = message;
            statusEl.className = 'kaspa-payment-status ' + status;
        }

        console.log('📊 Payment status:', status, '-', message);
    }

    /**
     * Stop payment checking
     */
    function stopPaymentChecking() {
        paymentCheckActive = false;
        if (paymentCheckInterval) {
            clearInterval(paymentCheckInterval);
            paymentCheckInterval = null;
        }
        console.log('⏹️ Payment monitoring stopped');
    }

    /**
     * Live pricing functionality
     */
    function startPriceUpdates() {
        if (!ajaxUrl) return;

        console.log('💰 Starting price updates...');
        priceUpdateInterval = setInterval(updateKaspaPrice, 15000);
        startCountdown();
    }

    function startCountdown() {
        secondsRemaining = 15;
        updateCountdownDisplay();

        countdownInterval = setInterval(function () {
            secondsRemaining--;
            updateCountdownDisplay();

            if (secondsRemaining <= 0) {
                clearInterval(countdownInterval);
            }
        }, 1000);
    }

    function updateCountdownDisplay() {
        const countdownEl = document.getElementById('kaspa-countdown');
        const progressEl = document.getElementById('kaspa-progress');

        if (countdownEl) {
            countdownEl.textContent = secondsRemaining > 0 ? secondsRemaining + 's' : 'Updating...';
        }

        if (progressEl) {
            const progressPercent = ((15 - secondsRemaining) / 15) * 100;
            progressEl.style.width = progressPercent + '%';
        }
    }

    function updateKaspaPrice() {
        if (!ajaxUrl) return;

        const xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success && response.data) {
                        const priceEl = document.getElementById('kaspa-current-price');
                        if (priceEl) {
                            priceEl.textContent = '$' + parseFloat(response.data.rate).toFixed(5);
                            priceEl.classList.add('price-updating');
                            setTimeout(() => priceEl.classList.remove('price-updating'), 300);
                        }

                        const timeEl = document.getElementById('kaspa-last-update');
                        if (timeEl && response.data.time_formatted) {
                            timeEl.textContent = 'Updated: ' + response.data.time_formatted;
                        }

                        startCountdown();
                    } else {
                        console.error('Price update failed:', response.data);
                        setTimeout(startCountdown, 5000);
                    }
                } catch (e) {
                    console.error('Price update parse error:', e);
                    setTimeout(startCountdown, 5000);
                }
            } else if (xhr.readyState === 4) {
                console.error('Price update HTTP error:', xhr.status);
                setTimeout(startCountdown, 5000);
            }
        };

        xhr.send('action=get_kasppaga_price');
    }

    /**
     * Copy to clipboard functionality
     */
    window.copyToClipboard = function (text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showNotification('✅ Copied to clipboard!', 'success');
            }).catch(function () {
                fallbackCopy(text);
            });
        } else {
            fallbackCopy(text);
        }
    };

    function fallbackCopy(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showNotification('✅ Copied to clipboard!', 'success');
            } else {
                showNotification('❌ Copy failed - please select manually', 'error');
            }
        } catch (err) {
            console.error('Copy failed:', err);
            showNotification('❌ Copy failed - please select manually', 'error');
        }

        document.body.removeChild(textArea);
    }

    /**
     * Manual payment check (for button click)
     */
    window.checkPaymentStatus = function () {
        const button = document.getElementById('kaspa-check-button');
        if (button) {
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Checking...';

            checkPaymentStatus();

            setTimeout(() => {
                button.disabled = false;
                button.textContent = originalText;
            }, 3000);
        } else {
            checkPaymentStatus();
        }
    };

    /**
     * Show notification
     */
    function showNotification(message, type = 'info') {
        // Remove existing notifications
        const existingNotifications = document.querySelectorAll('.kaspa-notification');
        existingNotifications.forEach(n => n.remove());

        const notification = document.createElement('div');
        notification.className = `kaspa-notification kaspa-notification-${type}`;
        notification.textContent = message;

        notification.style.cssText = `
            position: fixed; 
            top: 20px; 
            right: 20px; 
            background: ${getNotificationColor(type)}; 
            color: white; 
            padding: 12px 20px; 
            border-radius: 6px; 
            z-index: 9999; 
            font-weight: bold; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            transform: translateX(400px);
            transition: transform 0.3s ease;
        `;

        document.body.appendChild(notification);

        // Animate in
        setTimeout(() => {
            notification.style.transform = 'translateX(0)';
        }, 100);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            notification.style.transform = 'translateX(400px)';
            setTimeout(() => {
                if (document.body.contains(notification)) {
                    document.body.removeChild(notification);
                }
            }, 300);
        }, 5000);
    }

    function getNotificationColor(type) {
        switch (type) {
            case 'success': return '#28a745';
            case 'error': return '#dc3545';
            case 'warning': return '#ffc107';
            case 'info':
            default: return '#17a2b8';
        }
    }

    /**
     * Cleanup function
     */
    function cleanup() {
        if (priceUpdateInterval) clearInterval(priceUpdateInterval);
        if (countdownInterval) clearInterval(countdownInterval);
        if (paymentCheckInterval) clearInterval(paymentCheckInterval);
        console.log('🧹 Cleanup completed');
    }

    /**
     * Debug functions (remove in production)
     */
    window.kaspaDebug = {
        checkPayment: checkPaymentStatus,
        stopMonitoring: stopPaymentChecking,
        startMonitoring: startPaymentMonitoring,
        updatePrice: updateKaspaPrice,

        // Test function to manually confirm payment (admin only)
        manualConfirm: function (txid = null) {
            if (!ajaxUrl || !orderId) {
                console.error('Missing required data for manual confirmation');
                return;
            }

            const xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            console.log('Manual confirmation response:', response);
                            if (response.success) {
                                showNotification('✅ Payment manually confirmed', 'success');
                                setTimeout(() => window.location.reload(), 2000);
                            } else {
                                showNotification('❌ Manual confirmation failed', 'error');
                            }
                        } catch (e) {
                            console.error('Manual confirmation parse error:', e);
                        }
                    } else {
                        console.error('Manual confirmation HTTP error:', xhr.status);
                    }
                }
            };

            const data = `action=kasppaga_manual_confirm&order_id=${orderId}&txid=${txid || 'test-' + Date.now()}`;
            xhr.send(data);
        }
    };

    /**
     * Enhanced error handling
     */
    window.addEventListener('error', function (e) {
        console.error('Kaspa Checkout Error:', e.error);
        showNotification('❌ An error occurred. Please refresh the page.', 'error');
    });

    /**
     * Handle page visibility changes
     */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            console.log('⏸️ Page hidden - pausing updates');
            if (priceUpdateInterval) clearInterval(priceUpdateInterval);
            if (countdownInterval) clearInterval(countdownInterval);
        } else {
            console.log('▶️ Page visible - resuming updates');
            if (document.getElementById('kaspa-current-price')) {
                startPriceUpdates();
            }
        }
    });

    /**
     * Mobile-specific enhancements
     */
    function setupMobileEnhancements() {
        // Prevent zoom on input focus for mobile
        const metaViewport = document.querySelector('meta[name="viewport"]');
        if (metaViewport && /iPhone|iPad|iPod|Android/i.test(navigator.userAgent)) {
            metaViewport.content = 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no';
        }

        // Add touch feedback for copy buttons
        const copyFields = document.querySelectorAll('.kaspa-copy-field');
        copyFields.forEach(field => {
            field.addEventListener('touchstart', function () {
                this.style.background = '#e3f2fd';
            });

            field.addEventListener('touchend', function () {
                setTimeout(() => {
                    this.style.background = '';
                }, 150);
            });
        });
    }

    /**
     * Accessibility enhancements
     */
    function setupAccessibility() {
        // Add ARIA labels to interactive elements
        const copyFields = document.querySelectorAll('.kaspa-copy-field');
        copyFields.forEach((field, index) => {
            field.setAttribute('role', 'button');
            field.setAttribute('tabindex', '0');
            field.setAttribute('aria-label', `Copy ${field.querySelector('.kaspa-copy-text')?.textContent || 'text'} to clipboard`);

            // Add keyboard support
            field.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    field.click();
                }
            });
        });

        // Add live region for payment status updates
        const statusEl = document.getElementById('kaspa-payment-status');
        if (statusEl) {
            statusEl.setAttribute('aria-live', 'polite');
            statusEl.setAttribute('aria-atomic', 'true');
        }
    }

    // No WebSocket monitoring needed

    /**
     * Initialize when DOM is ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initializeCheckout();
            setupMobileEnhancements();
            setupAccessibility();
        });
    } else {
        initializeCheckout();
        setupMobileEnhancements();
        setupAccessibility();
    }

    // Global access for debugging
    window.kaspaCheckout = {
        checkPayment: checkPaymentStatus,
        copyToClipboard: window.copyToClipboard,
        showNotification: showNotification,
        debug: window.kaspaDebug,
        startMonitoring: startPaymentMonitoring
    };

})();