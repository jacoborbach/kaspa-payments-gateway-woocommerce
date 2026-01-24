/**
 * Kaspa Wallet Setup - KPUB Watch-Only (Secure)
 * 
 * This plugin uses KPUB (Extended Public Key) watch-only wallets for security.
 * No mnemonic phrases or private keys are handled.
 */

(function ($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function () {
        console.log('🚀 Kaspa Wallet Setup - KPUB Watch-Only Mode');

        // No SDK needed for KPUB-only setup - just initialize directly
        initializeSetup();
        bindEvents();
    });

    // Removed SDK loading code - not needed for KPUB-only setup
    // KPUB format validation is done client-side, address derivation happens when orders are created

    /**
     * Initialize the setup
     */
    function initializeSetup() {
        updateStepIndicator();
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // KPUB import form
        $('#kaspa-kpub-form').on('submit', handleKPUBImport);
    }

    /**
     * Handle KPUB import
     */
    async function handleKPUBImport(e) {
        e.preventDefault();

        const kpubInput = $('#kaspa-kpub-input').val().trim();
        const validationDiv = $('#kaspa-kpub-validation');
        const submitBtn = $('#kaspa-kpub-btn');

        if (!kpubInput) {
            showValidationError(validationDiv, 'Please enter a KPUB');
            return;
        }

        // Validate KPUB format
        if (!isValidKPUB(kpubInput)) {
            showValidationError(validationDiv, 'Invalid KPUB format. KPUB should start with "kpub" and be approximately 111 characters long.');
            return;
        }

        // Disable button and show loading
        submitBtn.prop('disabled', true).text('Validating...');
        validationDiv.html('<div style="color: #666;">Validating KPUB format...</div>').show();

        try {
            // KPUB format validation only - address derivation happens when orders are created
            console.log('✅ KPUB format validated');
            showValidationSuccess(validationDiv, '✅ KPUB format validated. Saving... (Address will be derived automatically when orders are created)');

            // Save KPUB to WordPress without address - it will be derived when needed
            await saveKPUBToWordPress(kpubInput, 'pending-derivation');

        } catch (error) {
            console.error('❌ KPUB import failed:', error);
            showValidationError(validationDiv, 'Failed to import KPUB: ' + error.message);
            submitBtn.prop('disabled', false).text('Validate & Import KPUB');
        }
    }

    /**
     * Validate KPUB format
     */
    function isValidKPUB(kpub) {
        if (!kpub || typeof kpub !== 'string') {
            return false;
        }

        // Check if starts with kpub
        if (!kpub.startsWith('kpub')) {
            return false;
        }

        // Check length (typically 111 characters)
        if (kpub.length < 110 || kpub.length > 120) {
            return false;
        }

        return true;
    }

    /**
     * Save KPUB to WordPress
     */
    async function saveKPUBToWordPress(kpub, firstAddress) {
        const submitBtn = $('#kaspa-kpub-btn');
        const validationDiv = $('#kaspa-kpub-validation');

        submitBtn.text('Saving...');

        try {
            const response = await $.ajax({
                url: kaspaWalletSetup.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'kasppaga_save_kpub_wallet',
                    kpub: kpub,
                    address: firstAddress,
                    wallet_data: JSON.stringify({
                        type: 'kpub_watch_only',
                        first_address: firstAddress,
                        setup_date: new Date().toISOString()
                    }),
                    nonce: kaspaWalletSetup.nonce
                }
            });

            if (response.success) {
                console.log('✅ KPUB saved successfully');
                showValidationSuccess(validationDiv, '✅ KPUB imported successfully! Loading dashboard...');

                // Always reload to show the dashboard with updated wallet info
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                throw new Error(response.data || 'Failed to save KPUB');
            }

        } catch (error) {
            console.error('❌ Save failed:', error);
            showValidationError(validationDiv, 'Failed to save KPUB: ' + (error.responseJSON?.data || error.message));
            submitBtn.prop('disabled', false).text('Validate & Import KPUB');
        }
    }

    /**
     * Show validation error
     */
    function showValidationError(element, message) {
        element.html(
            '<div style="background: #f8d7da; color: #842029; padding: 12px; border-radius: 4px; border: 1px solid #f1aeb5;">' +
            '<strong>❌ Error:</strong> ' + escapeHtml(message) +
            '</div>'
        ).show();
    }

    /**
     * Show validation success
     */
    function showValidationSuccess(element, message) {
        element.html(
            '<div style="background: #d1e7dd; color: #0f5132; padding: 12px; border-radius: 4px; border: 1px solid #a3cfbb;">' +
            '<strong>✅ Success:</strong> ' + escapeHtml(message) +
            '</div>'
        ).show();
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    /**
     * Show notification
     */
    function showNotification(message, type = 'info') {
        const colors = {
            success: '#28a745',
            error: '#dc3545',
            warning: '#ffc107',
            info: '#17a2b8'
        };

        const notification = $('<div>')
            .text(message)
            .css({
                position: 'fixed',
                top: '20px',
                right: '20px',
                background: colors[type] || colors.info,
                color: 'white',
                padding: '12px 20px',
                borderRadius: '6px',
                zIndex: 9999,
                fontWeight: 'bold',
                boxShadow: '0 4px 12px rgba(0,0,0,0.3)'
            });

        $('body').append(notification);

        setTimeout(() => {
            notification.fadeOut(300, () => notification.remove());
        }, 5000);
    }

    /**
     * Show step
     */
    function showStep(stepId) {
        $('.kaspa-setup-step').removeClass('active');
        $('#' + stepId).addClass('active');
        updateStepIndicator();
    }

    /**
     * Update step indicator
     */
    function updateStepIndicator() {
        const activeStep = $('.kaspa-setup-step.active');
        const stepIndex = activeStep.index() + 1;
        const totalSteps = $('.kaspa-setup-step').length;

        $('.kaspa-step-dot').each(function (index) {
            if (index < stepIndex) {
                $(this).addClass('active');
            } else {
                $(this).removeClass('active');
            }
        });
    }

    // Global functions for button handlers
    window.kaspaPrevStep = function () {
        const currentStep = $('.kaspa-setup-step.active');
        const prevStep = currentStep.prev('.kaspa-setup-step');
        if (prevStep.length) {
            currentStep.removeClass('active');
            prevStep.addClass('active');
            updateStepIndicator();
        }
    };

    window.kaspaNextStep = function () {
        const currentStep = $('.kaspa-setup-step.active');
        const nextStep = currentStep.next('.kaspa-setup-step');
        if (nextStep.length) {
            currentStep.removeClass('active');
            nextStep.addClass('active');
            updateStepIndicator();
        }
    };

})(jQuery);
