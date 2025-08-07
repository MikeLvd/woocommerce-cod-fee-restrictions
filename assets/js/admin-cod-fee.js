/**
 * WooCommerce COD Fee & Restrictions Admin Handler
 * Version: 1.0.0
 */

(function($) {
    'use strict';
    
    /**
     * Initialize admin functionality
     */
    function init() {
        handleFeeTypeChange();
        handleRestrictionsToggle();
        handleRestrictionTypeChange();
    }
    
    /**
     * Handle fee type change (fixed vs percentage)
     */
    function handleFeeTypeChange() {
        const $feeType = $('#woocommerce_cod_fee_type');
        const $amountField = $('#woocommerce_cod_fee_amount');
        
        if (!$feeType.length || !$amountField.length) {
            return;
        }
        
        $feeType.on('change', function() {
            const type = $(this).val();
            const $description = $amountField.closest('tr').find('.description');
            
            if (type === 'percentage') {
                $amountField.attr('max', '100');
                if ($description.length) {
                    $description.text(wcCodFeeAdmin.i18n.percentageDesc);
                }
            } else {
                $amountField.removeAttr('max');
                if ($description.length) {
                    $description.text(wcCodFeeAdmin.i18n.amountDesc);
                }
            }
        }).trigger('change');
    }
    
    /**
     * Handle restrictions enable/disable toggle
     */
    function handleRestrictionsToggle() {
        const $restrictionsEnabled = $('#woocommerce_cod_restrictions_enabled');
        
        if (!$restrictionsEnabled.length) {
            return;
        }
        
        const restrictionFields = [
            '#woocommerce_cod_restriction_type',
            '#woocommerce_cod_restricted_products',
            '#woocommerce_cod_restricted_categories'
        ];
        
        $restrictionsEnabled.on('change', function() {
            const isEnabled = $(this).is(':checked');
            const $rows = $(restrictionFields.join(', ')).closest('tr');
            
            if (isEnabled) {
                $rows.show();
                // Trigger restriction type change to show/hide appropriate fields
                $('#woocommerce_cod_restriction_type').trigger('change');
            } else {
                $rows.hide();
            }
        }).trigger('change');
    }
    
    /**
     * Handle restriction type change (products/categories/both)
     */
    function handleRestrictionTypeChange() {
        const $restrictionType = $('#woocommerce_cod_restriction_type');
        
        if (!$restrictionType.length) {
            return;
        }
        
        $restrictionType.on('change', function() {
            const type = $(this).val();
            const $productRow = $('#woocommerce_cod_restricted_products').closest('tr');
            const $categoryRow = $('#woocommerce_cod_restricted_categories').closest('tr');
            
            // Only show/hide if restrictions are enabled
            const restrictionsEnabled = $('#woocommerce_cod_restrictions_enabled').is(':checked');
            
            if (!restrictionsEnabled) {
                return;
            }
            
            switch (type) {
                case 'products':
                    $productRow.show();
                    $categoryRow.hide();
                    break;
                case 'categories':
                    $productRow.hide();
                    $categoryRow.show();
                    break;
                case 'both':
                    $productRow.show();
                    $categoryRow.show();
                    break;
                default:
                    $productRow.show();
                    $categoryRow.show();
            }
        }).trigger('change');
    }
    
    /**
     * Initialize Select2 for product search if available
     */
    function initProductSearch() {
        if ($.fn.selectWoo) {
            $('.wc-product-search').selectWoo({
                ajax: {
                    url: wcCodFeeAdmin.ajaxUrl,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            term: params.term,
                            action: 'woocommerce_json_search_products_and_variations',
                            security: wcCodFeeAdmin.searchNonce,
                            exclude_type: 'variable'
                        };
                    },
                    processResults: function(data) {
                        const terms = [];
                        if (data) {
                            $.each(data, function(id, text) {
                                terms.push({
                                    id: id,
                                    text: text
                                });
                            });
                        }
                        return {
                            results: terms
                        };
                    },
                    cache: true
                },
                minimumInputLength: 3
            });
        }
    }
    
    /**
     * Initialize enhanced selects for categories
     */
    function initCategorySelect() {
        if ($.fn.selectWoo) {
            $('.wc-enhanced-select').selectWoo({
                placeholder: wcCodFeeAdmin.i18n.selectCategories,
                allowClear: true
            });
        }
    }
    
    /**
     * Document ready
     */
    $(document).ready(function() {
        init();
        initProductSearch();
        initCategorySelect();
        
        // Re-initialize after WooCommerce settings are saved
        $(document.body).on('wc-enhanced-select-init', function() {
            initProductSearch();
            initCategorySelect();
        });
    });
    
})(jQuery);
