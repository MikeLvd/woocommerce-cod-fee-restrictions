<?php
/**
 * Plugin Name: WooCommerce COD Fee
 * Plugin URI: https://yourwebsite.com
 * Description: Adds a configurable fee for Cash on Delivery payment method in WooCommerce
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: wc-cod-fee
 * WC requires at least: 9.0
 * WC tested up to: 10.0
 */

namespace WCCodFee;

// Prevent direct access
defined('ABSPATH') || exit;

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

/**
 * COD Fee Manager Class
 */
final class CODFeeManager {
    
    /**
     * Plugin version
     */
    private const VERSION = '1.0.0';
    
    /**
     * Instance
     */
    private static ?self $instance = null;
    
    /**
     * Default fee amount
     */
    private const DEFAULT_FEE = 0;
    
    /**
     * Get instance
     */
    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // Add settings to COD payment method
        add_filter('woocommerce_settings_api_form_fields_cod', [$this, 'add_cod_fee_settings']);
        
        // Apply fee at checkout
        add_action('woocommerce_cart_calculate_fees', [$this, 'add_cod_fee']);
        
        // Enqueue checkout scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_cod_fee_scripts']);
        
        // Save COD fee in order meta
        add_action('woocommerce_checkout_create_order', [$this, 'save_cod_fee_to_order'], 20, 2);
        
        // Display COD fee in admin order
        add_action('woocommerce_admin_order_totals_after_tax', [$this, 'display_cod_fee_in_admin']);
        
        // Support for block-based checkout
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'handle_block_checkout_fee'], 10, 2);
        
        // Add HPOS compatibility
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);
        
        // Handle AJAX payment method updates
        add_action('woocommerce_checkout_update_order_review', [$this, 'refresh_checkout_on_payment_methods']);
    }
    
    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility(): void {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }
    
    /**
     * Add COD fee settings to payment method settings
     */
    public function add_cod_fee_settings(array $form_fields): array {
        $form_fields['cod_fee_section'] = [
            'title'       => __('COD Fee Settings', 'wc-cod-fee'),
            'type'        => 'title',
            'description' => __('Configure additional fee for Cash on Delivery payments.', 'wc-cod-fee'),
        ];
        
        $form_fields['cod_fee_enabled'] = [
            'title'       => __('Enable COD Fee', 'wc-cod-fee'),
            'type'        => 'checkbox',
            'label'       => __('Add a fee for Cash on Delivery payments', 'wc-cod-fee'),
            'default'     => 'no',
            'description' => __('If enabled, a fee will be added when Cash on Delivery is selected.', 'wc-cod-fee'),
        ];
        
        $form_fields['cod_fee_amount'] = [
            'title'             => __('Fee Amount', 'wc-cod-fee'),
            'type'              => 'number',
            'custom_attributes' => [
                'step' => '0.01',
                'min'  => '0',
            ],
            'default'           => self::DEFAULT_FEE,
            'description'       => sprintf(
                __('Enter the fee amount. Currency: %s', 'wc-cod-fee'),
                get_woocommerce_currency_symbol()
            ),
            'desc_tip'          => true,
            'sanitize_callback' => [$this, 'sanitize_fee_amount'],
        ];
        
        $form_fields['cod_fee_type'] = [
            'title'       => __('Fee Type', 'wc-cod-fee'),
            'type'        => 'select',
            'default'     => 'fixed',
            'options'     => [
                'fixed'      => __('Fixed Amount', 'wc-cod-fee'),
                'percentage' => __('Percentage (%)', 'wc-cod-fee'),
            ],
            'description' => __('Choose whether the fee is a fixed amount or a percentage of the order total.', 'wc-cod-fee'),
            'desc_tip'    => true,
        ];
        
        $form_fields['cod_fee_label'] = [
            'title'       => __('Fee Label', 'wc-cod-fee'),
            'type'        => 'text',
            'default'     => __('Cash on Delivery Fee', 'wc-cod-fee'),
            'description' => __('This text will be displayed as the fee name at checkout.', 'wc-cod-fee'),
            'desc_tip'    => true,
            'placeholder' => __('Cash on Delivery Fee', 'wc-cod-fee'),
        ];
        
        $form_fields['cod_fee_tax_status'] = [
            'title'       => __('Tax Status', 'wc-cod-fee'),
            'type'        => 'select',
            'default'     => 'taxable',
            'options'     => [
                'taxable' => __('Taxable', 'wc-cod-fee'),
                'none'    => __('Not Taxable', 'wc-cod-fee'),
            ],
            'description' => __('Define whether the COD fee is taxable.', 'wc-cod-fee'),
            'desc_tip'    => true,
        ];
        
        $form_fields['cod_fee_min_amount'] = [
            'title'             => __('Minimum Order Amount', 'wc-cod-fee'),
            'type'              => 'number',
            'custom_attributes' => [
                'step' => '0.01',
                'min'  => '0',
            ],
            'default'           => '0',
            'description'       => __('Minimum order amount required to apply COD fee. Set 0 for no minimum.', 'wc-cod-fee'),
            'desc_tip'          => true,
            'sanitize_callback' => [$this, 'sanitize_fee_amount'],
        ];
        
        $form_fields['cod_fee_max_amount'] = [
            'title'             => __('Maximum Order Amount', 'wc-cod-fee'),
            'type'              => 'number',
            'custom_attributes' => [
                'step' => '0.01',
                'min'  => '0',
            ],
            'default'           => '0',
            'description'       => __('Maximum order amount to apply COD fee. Set 0 for no maximum.', 'wc-cod-fee'),
            'desc_tip'          => true,
            'sanitize_callback' => [$this, 'sanitize_fee_amount'],
        ];
        
        $form_fields['cod_fee_round_fee'] = [
            'title'       => __('Round Fee Amount', 'wc-cod-fee'),
            'type'        => 'checkbox',
            'label'       => __('Round the fee to the nearest whole number', 'wc-cod-fee'),
            'default'     => 'no',
            'description' => __('If enabled, the fee will be rounded to the nearest whole number.', 'wc-cod-fee'),
        ];
        
        return $form_fields;
    }
    
    /**
     * Sanitize fee amount
     */
    public function sanitize_fee_amount($value): string {
        return (string) abs((float) str_replace(',', '.', $value));
    }
    
    /**
     * Get COD fee settings
     */
    private function get_cod_settings(): array {
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        
        if (!isset($payment_gateways['cod'])) {
            return $this->get_default_settings();
        }
        
        $cod_gateway = $payment_gateways['cod'];
        
        return [
            'enabled'     => $cod_gateway->get_option('cod_fee_enabled', 'no'),
            'amount'      => (float) $cod_gateway->get_option('cod_fee_amount', self::DEFAULT_FEE),
            'type'        => $cod_gateway->get_option('cod_fee_type', 'fixed'),
            'label'       => $cod_gateway->get_option('cod_fee_label', __('Cash on Delivery Fee', 'wc-cod-fee')),
            'tax_status'  => $cod_gateway->get_option('cod_fee_tax_status', 'taxable'),
            'min_amount'  => (float) $cod_gateway->get_option('cod_fee_min_amount', 0),
            'max_amount'  => (float) $cod_gateway->get_option('cod_fee_max_amount', 0),
            'round_fee'   => $cod_gateway->get_option('cod_fee_round_fee', 'no'),
        ];
    }
    
    /**
     * Get default settings
     */
    private function get_default_settings(): array {
        return [
            'enabled'     => 'no',
            'amount'      => 0,
            'type'        => 'fixed',
            'label'       => __('Cash on Delivery Fee', 'wc-cod-fee'),
            'tax_status'  => 'taxable',
            'min_amount'  => 0,
            'max_amount'  => 0,
            'round_fee'   => 'no',
        ];
    }
    
    /**
     * Check if COD is selected payment method
     */
    private function is_cod_selected(): bool {
        if (!WC()->session) {
            return false;
        }
        
        $chosen_payment_method = WC()->session->get('chosen_payment_method');
        
        // Also check POST data for real-time updates
        if (isset($_POST['payment_method'])) {
            $chosen_payment_method = sanitize_text_field(wp_unslash($_POST['payment_method']));
        }
        
        return $chosen_payment_method === 'cod';
    }
    
    /**
     * Calculate COD fee amount
     */
    private function calculate_fee_amount(array $settings): float {
        if ($settings['enabled'] !== 'yes' || $settings['amount'] <= 0) {
            return 0;
        }
        
        // Get cart total (subtotal + shipping + taxes)
        $cart_total = WC()->cart->get_subtotal() + WC()->cart->get_shipping_total();
        
        // Apply tax if prices include tax
        if (wc_prices_include_tax()) {
            $cart_total += WC()->cart->get_subtotal_tax() + WC()->cart->get_shipping_tax();
        }
        
        // Check minimum amount
        if ($settings['min_amount'] > 0 && $cart_total < $settings['min_amount']) {
            return 0;
        }
        
        // Check maximum amount
        if ($settings['max_amount'] > 0 && $cart_total > $settings['max_amount']) {
            return 0;
        }
        
        // Calculate fee based on type
        if ($settings['type'] === 'percentage') {
            $fee = ($cart_total * $settings['amount']) / 100;
        } else {
            $fee = $settings['amount'];
        }
        
        // Round if enabled
        if ($settings['round_fee'] === 'yes') {
            $fee = round($fee);
        }
        
        return round($fee, wc_get_price_decimals());
    }
    
    /**
     * Add COD fee to cart
     */
    public function add_cod_fee(): void {
        // Only on checkout
        if (!is_checkout() && !wp_doing_ajax()) {
            return;
        }
        
        // Check if COD is selected
        if (!$this->is_cod_selected()) {
            return;
        }
        
        $settings = $this->get_cod_settings();
        $fee_amount = $this->calculate_fee_amount($settings);
        
        if ($fee_amount > 0) {
            $taxable = $settings['tax_status'] === 'taxable';
            
            // Remove any existing COD fee first
            $fees = WC()->cart->get_fees();
            foreach ($fees as $key => $fee) {
                if ($fee->name === $settings['label']) {
                    unset($fees[$key]);
                }
            }
            
            // Add the fee
            WC()->cart->add_fee(
                $settings['label'],
                $fee_amount,
                $taxable,
                ''
            );
        }
    }
    
    /**
     * Refresh checkout on payment method change
     */
    public function refresh_checkout_on_payment_methods($post_data): void {
        parse_str($post_data, $data);
        
        if (isset($data['payment_method'])) {
            WC()->session->set('chosen_payment_method', $data['payment_method']);
        }
    }
    
    /**
     * Enqueue COD fee scripts
     */
    public function enqueue_cod_fee_scripts(): void {
        if (!is_checkout()) {
            return;
        }
        
        wp_enqueue_script(
            'wc-cod-fee',
            plugin_dir_url(__FILE__) . 'assets/js/cod-fee.js',
            ['jquery', 'wc-checkout'],
            self::VERSION,
            true
        );
        
        // Add inline script if JS file doesn't exist
        if (!file_exists(plugin_dir_path(__FILE__) . 'assets/js/cod-fee.js')) {
            wp_add_inline_script('wc-checkout', $this->get_inline_script());
        }
        
        wp_localize_script('wc-cod-fee', 'wcCodFee', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('update-order-review'),
            'isBlockCheckout' => has_block('woocommerce/checkout'),
            'codSettings'    => $this->get_cod_settings(),
        ]);
    }
    
    /**
     * Get inline JavaScript
     */
    private function get_inline_script(): string {
        return "
        jQuery(function($) {
            'use strict';
            
            // Trigger checkout update on payment method change
            $(document.body).on('change', 'input[name=\"payment_method\"]', function() {
                $(document.body).trigger('update_checkout');
            });
            
            // Update on checkout init
            $(document.body).on('init_checkout', function() {
                $(document.body).trigger('update_checkout');
            });
        });
        ";
    }
    
    /**
     * Save COD fee to order meta
     */
    public function save_cod_fee_to_order(\WC_Order $order, array $data): void {
        if ($order->get_payment_method() !== 'cod') {
            return;
        }
        
        $settings = $this->get_cod_settings();
        
        // Find the fee in the order
        foreach ($order->get_fees() as $fee) {
            if ($fee->get_name() === $settings['label']) {
                $order->update_meta_data('_cod_fee_amount', $fee->get_total());
                $order->update_meta_data('_cod_fee_label', $fee->get_name());
                $order->update_meta_data('_cod_fee_tax', $fee->get_total_tax());
                break;
            }
        }
    }
    
    /**
     * Display COD fee in admin order page
     */
    public function display_cod_fee_in_admin(int $order_id): void {
        $order = wc_get_order($order_id);
        
        if (!$order || $order->get_payment_method() !== 'cod') {
            return;
        }
        
        $cod_fee = $order->get_meta('_cod_fee_amount');
        $cod_label = $order->get_meta('_cod_fee_label');
        $cod_tax = $order->get_meta('_cod_fee_tax');
        
        if ($cod_fee) {
            ?>
            <tr>
                <td class="label"><?php echo esc_html($cod_label ?: __('COD Fee', 'wc-cod-fee')); ?>:</td>
                <td width="1%"></td>
                <td class="total">
                    <?php 
                    $total = $cod_fee;
                    if ($cod_tax) {
                        $total += $cod_tax;
                        echo wc_price($total, ['currency' => $order->get_currency()]);
                        echo ' <small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small>';
                    } else {
                        echo wc_price($total, ['currency' => $order->get_currency()]);
                    }
                    ?>
                </td>
            </tr>
            <?php
        }
    }
    
    /**
     * Handle block checkout fee
     */
    public function handle_block_checkout_fee(\WC_Order $order, \WP_REST_Request $request): void {
        $payment_method = $request->get_param('payment_method');
        
        if ($payment_method === 'cod') {
            $settings = $this->get_cod_settings();
            
            // Find the fee in the order
            foreach ($order->get_fees() as $fee) {
                if ($fee->get_name() === $settings['label']) {
                    $order->update_meta_data('_cod_fee_amount', $fee->get_total());
                    $order->update_meta_data('_cod_fee_label', $fee->get_name());
                    $order->update_meta_data('_cod_fee_tax', $fee->get_total_tax());
                    break;
                }
            }
        }
    }
}

// Initialize plugin
add_action('plugins_loaded', function() {
    CODFeeManager::get_instance();
}, 10);

// Activation hook
register_activation_hook(__FILE__, function() {
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.0', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('This plugin requires PHP 8.0 or higher.', 'wc-cod-fee'),
            esc_html__('Plugin Activation Error', 'wc-cod-fee'),
            ['response' => 200, 'back_link' => true]
        );
    }
    
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('This plugin requires WooCommerce to be installed and active.', 'wc-cod-fee'),
            esc_html__('Plugin Activation Error', 'wc-cod-fee'),
            ['response' => 200, 'back_link' => true]
        );
    }
});