/**
 * WooCommerce COD Fee & Restrictions Handler
 * Version: 1.1.0
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
        });
        
        // Initialize on load
        $(document.body).on('init_checkout', function() {
            const initialMethod = $('input[name="payment_method"]:checked').val();
            if (initialMethod) {
                lastPaymentMethod = initialMethod;
            }
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
                            settings: wcCodFee.codSettings,
                            hasRestrictedProducts: wcCodFee.hasRestrictedProducts
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
