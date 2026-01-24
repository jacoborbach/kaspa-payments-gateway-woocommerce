(function () {
    'use strict';

    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { createElement } = window.wp.element;
    const { __ } = window.wp.i18n;
    const { getSetting } = window.wc.wcSettings;

    // Get settings with fallback
    let settings = {};
    try {
        if (typeof getSetting === 'function') {
            settings = getSetting('kaspa_data', {});
        }
    } catch (e) {
        // Fallback to direct access
        if (window.wc && window.wc.wcSettings && window.wc.wcSettings.allSettings) {
            settings = window.wc.wcSettings.allSettings.kaspa_data || {};
        }
    }

    // Use settings or defaults
    const kaspaTitle = settings.title || __('Kaspa (KAS)', 'kaspawoo');
    const kaspaDescription = settings.description || __('Pay with Kaspa cryptocurrency. Fast and secure.', 'kaspawoo');

    // Payment method content with icon
    const paymentContent = createElement('div', {
        className: 'kaspa-payment-method-content',
        style: {
            padding: '12px',
            background: '#f0f8ff',
            border: '1px solid #70D0F0',
            borderRadius: '6px'
        }
    }, [
        createElement('div', {
            key: 'icon-row',
            style: {
                display: 'flex',
                alignItems: 'center',
                marginBottom: '8px'
            }
        }, [
            createElement('span', {
                key: 'icon',
                style: {
                    display: 'inline-block',
                    width: '20px',
                    height: '20px',
                    background: '#70D0F0',
                    borderRadius: '50%',
                    marginRight: '8px'
                }
            }),
            createElement('span', {
                key: 'icon-text',
                style: { fontWeight: 'bold' }
            }, '💎 Fast and secure Kaspa payments')
        ]),
        createElement('div', {
            key: 'description'
        }, kaspaDescription),
        createElement('div', {
            key: 'note',
            style: {
                fontSize: '13px',
                color: '#666',
                marginTop: '8px'
            }
        }, 'You will receive payment instructions after placing your order.')
    ]);

    registerPaymentMethod({
        name: 'kaspa',
        label: kaspaTitle,
        content: paymentContent,
        edit: paymentContent,
        canMakePayment: function () {
            return true;
        },
        ariaLabel: kaspaTitle,
        supports: {
            features: ['products']
        }
    });

})();