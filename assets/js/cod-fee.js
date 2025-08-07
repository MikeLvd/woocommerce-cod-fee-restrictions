/**
 * WooCommerce COD Fee Handler
 * Version: 1.0.0
 */

(function($) {
    'use strict';
    
    if (typeof wcCodFee === 'undefined') {
        return;
    }
    
    let isUpdating = false;
    let lastPaymentMethod = null;
    
    /**
     * Initialize COD fee handling
     */
    function init() {
        // Handle payment method change
        $(document.body).on('change', 'input[name="payment_method"]', function() {
            const currentMethod = $(this).val();
            
            if (currentMethod !== lastPaymentMethod) {
                lastPaymentMethod = currentMethod;
                
                if (!isUpdating) {
                    updateCheckout();
                }
            }
        });
        
        // Handle checkout updates
        $(document.body).on('updated_checkout', function() {
            isUpdating = false;
            updateFeeDisplay();
        });
        
        // Initialize on load
        $(document.body).on('init_checkout', function() {
            const initialMethod = $('input[name="payment_method"]:checked').val();
            if (initialMethod) {
                lastPaymentMethod = initialMethod;
            }
            updateFeeDisplay();
        });
        
        // Handle country/state changes (might affect tax)
        $(document.body).on('country_to_state_changed', function() {
            const currentMethod = $('input[name="payment_method"]:checked').val();
            if (currentMethod === 'cod') {
                updateCheckout();
            }
        });
    }
    
    /**
     * Trigger checkout update
     */
    function updateCheckout() {
        if (isUpdating) {
            return;
        }
        
        isUpdating = true;
        $(document.body).trigger('update_checkout');
    }
    
    /**
     * Update fee display in payment method label
     */
    function updateFeeDisplay() {
        const settings = wcCodFee.codSettings;
        
        if (!settings || settings.enabled !== 'yes') {
            return;
        }
        
        const $codLabel = $('label[for="payment_method_cod"]');
        
        if ($codLabel.length) {
            // Remove existing fee display
            $codLabel.find('.cod-fee-info').remove();
            
            // Add fee information
            let feeText = '';
            if (settings.type === 'percentage') {
                feeText = `(+${settings.amount}% ${settings.label})`;
            } else if (settings.amount > 0) {
                // Format with currency
                const formattedAmount = new Intl.NumberFormat(
                    document.documentElement.lang || 'en-US',
                    { 
                        style: 'currency', 
                        currency: wcCodFee.currency || 'USD',
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 2
                    }
                ).format(settings.amount);
                
                feeText = `(+${formattedAmount} ${settings.label})`;
            }
            
            if (feeText) {
                $codLabel.append(`
                    <span class="cod-fee-info" style="
                        display: inline-block;
                        margin-left: 8px;
                        font-size: 0.9em;
                        color: #666;
                        font-weight: normal;
                    ">${feeText}</span>
                `);
            }
        }
    }
    
    /**
     * Handle block checkout
     */
    function initBlockCheckout() {
        if (!wcCodFee.isBlockCheckout || !window.wp?.data) {
            return;
        }
        
        const { subscribe, select } = window.wp.data;
        let previousMethod = null;
        
        subscribe(() => {
            try {
                const store = select('wc/store/checkout');
                if (!store) return;
                
                const currentMethod = store.getPaymentMethod?.() || 
                                    store.getActivePaymentMethod?.();
                
                if (currentMethod && currentMethod !== previousMethod) {
                    previousMethod = currentMethod;
                    
                    // Dispatch event for other integrations
                    document.dispatchEvent(new CustomEvent('cod_payment_selected', {
                        detail: { 
                            selected: currentMethod === 'cod',
                            settings: wcCodFee.codSettings 
                        }
                    }));
                }
            } catch (e) {
                console.error('COD Fee: Block checkout error', e);
            }
        });
    }
    
    // Initialize when ready
    $(document).ready(function() {
        init();
        initBlockCheckout();
    });
    
})(jQuery);