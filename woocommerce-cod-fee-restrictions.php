<?php
/**
 * Plugin Name: WooCommerce COD Fee & Restrictions Manager
 * Plugin URI: https://github.com/MikeLvd/woocommerce-cod-fee-restrictions
 * Description: Adds configurable fees and restrictions for Cash on Delivery payment method in WooCommerce 10.0+
 * Version: 2.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Author: Mike Lvd
 * License: GPL v2 or later
 * Text Domain: wc-cod-fee-restrictions-restrictions
 * WC requires at least: 9.5
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
 * COD Fee Manager Class - Updated for WooCommerce 10.0
 */
final class CODFeeManager {
    
    /**
     * Plugin version
     */
    private const VERSION = '2.0.0';
    
    /**
     * Instance
     */
    private static ?self $instance = null;
    
    /**
     * Settings key
     */
    private const SETTINGS_KEY = 'woocommerce_cod_fee_settings';
    
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
        // Add admin menu for COD fee settings
        add_action('admin_menu', [$this, 'add_admin_menu'], 99);
        
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // Apply fee at checkout
        add_action('woocommerce_cart_calculate_fees', [$this, 'add_cod_fee']);
        
        // Handle payment method changes
        add_action('woocommerce_checkout_update_order_review', [$this, 'refresh_checkout_on_payment_methods']);
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Save COD fee in order meta
        add_action('woocommerce_checkout_create_order', [$this, 'save_cod_fee_to_order'], 20, 2);
        
        // Display in admin order
        add_action('woocommerce_admin_order_totals_after_tax', [$this, 'display_cod_fee_in_admin']);
        
        // HPOS compatibility
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);
        
        // Add settings link in plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
        
        // Add custom settings tab to WooCommerce
        add_filter('woocommerce_settings_tabs_array', [$this, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_cod_fee', [$this, 'settings_tab_content']);
        add_action('woocommerce_update_options_cod_fee', [$this, 'update_settings']);
        
        // Alternative: Add to payment settings section
        add_filter('woocommerce_get_sections_checkout', [$this, 'add_cod_fee_section']);
        add_filter('woocommerce_get_settings_checkout', [$this, 'add_cod_fee_settings'], 10, 2);
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
     * Add settings link to plugins page
     */
    public function add_settings_link(array $links): array {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=cod_fee') . '">' . 
                        __('Settings', 'wc-cod-fee-restrictions') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Add settings tab to WooCommerce settings
     */
    public function add_settings_tab(array $settings_tabs): array {
        $settings_tabs['cod_fee'] = __('COD Fee', 'wc-cod-fee-restrictions');
        return $settings_tabs;
    }
    
    /**
     * Add section to checkout settings
     */
    public function add_cod_fee_section(array $sections): array {
        $sections['cod_fee'] = __('Cash on Delivery Fee', 'wc-cod-fee-restrictions');
        return $sections;
    }
    
    /**
     * Add COD fee settings to checkout section
     */
    public function add_cod_fee_settings(array $settings, string $current_section): array {
        if ($current_section === 'cod_fee') {
            $settings = [];
            
            $settings[] = [
                'title' => __('Cash on Delivery Fee Settings', 'wc-cod-fee-restrictions'),
                'type'  => 'title',
                'desc'  => __('Configure additional fee for Cash on Delivery payment method.', 'wc-cod-fee-restrictions'),
                'id'    => 'cod_fee_options',
            ];
            
            $settings[] = [
                'title'   => __('Enable COD Fee', 'wc-cod-fee-restrictions'),
                'id'      => 'woocommerce_cod_fee_enabled',
                'type'    => 'checkbox',
                'default' => 'no',
                'desc'    => __('Enable Cash on Delivery fee', 'wc-cod-fee-restrictions'),
            ];
            
            $settings[] = [
                'title'             => __('Fee Amount', 'wc-cod-fee-restrictions'),
                'id'                => 'woocommerce_cod_fee_amount',
                'type'              => 'number',
                'custom_attributes' => [
                    'step' => '0.01',
                    'min'  => '0',
                ],
                'default'           => '5',
                'desc'              => sprintf(__('Enter the fee amount in %s', 'wc-cod-fee-restrictions'), get_woocommerce_currency()),
                'desc_tip'          => true,
            ];
            
            $settings[] = [
                'title'    => __('Fee Type', 'wc-cod-fee-restrictions'),
                'id'       => 'woocommerce_cod_fee_type',
                'type'     => 'select',
                'default'  => 'fixed',
                'options'  => [
                    'fixed'      => __('Fixed Amount', 'wc-cod-fee-restrictions'),
                    'percentage' => __('Percentage of Cart Total', 'wc-cod-fee-restrictions'),
                ],
                'desc'     => __('Choose whether the fee is a fixed amount or percentage.', 'wc-cod-fee-restrictions'),
                'desc_tip' => true,
            ];
            
            $settings[] = [
                'title'    => __('Fee Label', 'wc-cod-fee-restrictions'),
                'id'       => 'woocommerce_cod_fee_label',
                'type'     => 'text',
                'default'  => __('Cash on Delivery Fee', 'wc-cod-fee-restrictions'),
                'desc'     => __('Label displayed at checkout', 'wc-cod-fee-restrictions'),
                'desc_tip' => true,
            ];
            
            $settings[] = [
                'title'    => __('Tax Status', 'wc-cod-fee-restrictions'),
                'id'       => 'woocommerce_cod_fee_tax_status',
                'type'     => 'select',
                'default'  => 'taxable',
                'options'  => [
                    'taxable' => __('Taxable', 'wc-cod-fee-restrictions'),
                    'none'    => __('Not Taxable', 'wc-cod-fee-restrictions'),
                ],
                'desc'     => __('Is the COD fee taxable?', 'wc-cod-fee-restrictions'),
                'desc_tip' => true,
            ];
            
            $settings[] = [
                'title'             => __('Minimum Order Amount', 'wc-cod-fee-restrictions'),
                'id'                => 'woocommerce_cod_fee_min_amount',
                'type'              => 'number',
                'custom_attributes' => [
                    'step' => '0.01',
                    'min'  => '0',
                ],
                'default'           => '0',
                'desc'              => __('Minimum order amount to apply fee (0 = no minimum)', 'wc-cod-fee-restrictions'),
                'desc_tip'          => true,
            ];
            
            $settings[] = [
                'title'             => __('Maximum Order Amount', 'wc-cod-fee-restrictions'),
                'id'                => 'woocommerce_cod_fee_max_amount',
                'type'              => 'number',
                'custom_attributes' => [
                    'step' => '0.01',
                    'min'  => '0',
                ],
                'default'           => '0',
                'desc'              => __('Maximum order amount to apply fee (0 = no maximum)', 'wc-cod-fee-restrictions'),
                'desc_tip'          => true,
            ];
            
            $settings[] = [
                'type' => 'sectionend',
                'id'   => 'cod_fee_options',
            ];
        }
        
        return $settings;
    }
    
    /**
     * Settings tab content
     */
    public function settings_tab_content(): void {
        woocommerce_admin_fields($this->get_settings_fields());
    }
    
    /**
     * Update settings
     */
    public function update_settings(): void {
        woocommerce_update_options($this->get_settings_fields());
    }
    
    /**
     * Get settings fields
     */
    private function get_settings_fields(): array {
        return [
            [
                'title' => __('Cash on Delivery Fee Settings', 'wc-cod-fee-restrictions'),
                'type'  => 'title',
                'desc'  => __('Configure additional fee for Cash on Delivery payment method.', 'wc-cod-fee-restrictions'),
                'id'    => 'cod_fee_section',
            ],
            [
                'title'   => __('Enable COD Fee', 'wc-cod-fee-restrictions'),
                'id'      => 'woocommerce_cod_fee_enabled',
                'type'    => 'checkbox',
                'default' => 'no',
                'desc'    => __('Enable Cash on Delivery fee', 'wc-cod-fee-restrictions'),
            ],
            [
                'title'             => __('Fee Amount', 'wc-cod-fee-restrictions'),
                'id'                => 'woocommerce_cod_fee_amount',
                'type'              => 'number',
                'custom_attributes' => [
                    'step' => '0.01',
                    'min'  => '0',
                ],
                'default'           => '5',
                'desc'              => sprintf(__('Fee amount in %s', 'wc-cod-fee-restrictions'), get_woocommerce_currency()),
                'desc_tip'          => true,
            ],
            [
                'title'    => __('Fee Type', 'wc-cod-fee-restrictions'),
                'id'       => 'woocommerce_cod_fee_type',
                'type'     => 'select',
                'default'  => 'fixed',
                'options'  => [
                    'fixed'      => __('Fixed Amount', 'wc-cod-fee-restrictions'),
                    'percentage' => __('Percentage', 'wc-cod-fee-restrictions'),
                ],
            ],
            [
                'title'    => __('Fee Label', 'wc-cod-fee-restrictions'),
                'id'       => 'woocommerce_cod_fee_label',
                'type'     => 'text',
                'default'  => __('Cash on Delivery Fee', 'wc-cod-fee-restrictions'),
            ],
            [
                'title'    => __('Tax Status', 'wc-cod-fee-restrictions'),
                'id'       => 'woocommerce_cod_fee_tax_status',
                'type'     => 'select',
                'default'  => 'taxable',
                'options'  => [
                    'taxable' => __('Taxable', 'wc-cod-fee-restrictions'),
                    'none'    => __('Not Taxable', 'wc-cod-fee-restrictions'),
                ],
            ],
            [
                'title'             => __('Minimum Order', 'wc-cod-fee-restrictions'),
                'id'                => 'woocommerce_cod_fee_min_amount',
                'type'              => 'number',
                'custom_attributes' => [
                    'step' => '0.01',
                    'min'  => '0',
                ],
                'default'           => '0',
                'desc_tip'          => true,
                'desc'              => __('0 = no minimum', 'wc-cod-fee-restrictions'),
            ],
            [
                'title'             => __('Maximum Order', 'wc-cod-fee-restrictions'),
                'id'                => 'woocommerce_cod_fee_max_amount',
                'type'              => 'number',
                'custom_attributes' => [
                    'step' => '0.01',
                    'min'  => '0',
                ],
                'default'           => '0',
                'desc_tip'          => true,
                'desc'              => __('0 = no maximum', 'wc-cod-fee-restrictions'),
            ],
            [
                'type' => 'sectionend',
                'id'   => 'cod_fee_section',
            ],
        ];
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('COD Fee Settings', 'wc-cod-fee-restrictions'),
            __('COD Fee', 'wc-cod-fee-restrictions'),
            'manage_woocommerce',
            'wc-cod-fee-restrictions-settings',
            [$this, 'admin_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings(): void {
        register_setting('wc_cod_fee_settings', self::SETTINGS_KEY);
    }
    
    /**
     * Admin page
     */
    public function admin_page(): void {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Cash on Delivery Fee Settings', 'wc-cod-fee-restrictions'); ?></h1>
            
            <div class="notice notice-info">
                <p>
                    <?php 
                    echo sprintf(
                        __('You can also configure these settings in %sWooCommerce Settings → COD Fee%s or %sWooCommerce Settings → Payments → Cash on Delivery Fee%s', 'wc-cod-fee-restrictions'),
                        '<a href="' . admin_url('admin.php?page=wc-settings&tab=cod_fee') . '">',
                        '</a>',
                        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=cod_fee') . '">',
                        '</a>'
                    );
                    ?>
                </p>
            </div>
            
            <form method="post" action="<?php echo admin_url('admin.php?page=wc-settings&tab=cod_fee'); ?>">
                <?php 
                settings_fields('wc_cod_fee_settings');
                woocommerce_admin_fields($this->get_settings_fields());
                submit_button(__('Save Changes', 'wc-cod-fee-restrictions'));
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Get COD fee settings
     */
    private function get_cod_settings(): array {
        return [
            'enabled'     => get_option('woocommerce_cod_fee_enabled', 'no'),
            'amount'      => (float) get_option('woocommerce_cod_fee_amount', 5),
            'type'        => get_option('woocommerce_cod_fee_type', 'fixed'),
            'label'       => get_option('woocommerce_cod_fee_label', __('Cash on Delivery Fee', 'wc-cod-fee-restrictions')),
            'tax_status'  => get_option('woocommerce_cod_fee_tax_status', 'taxable'),
            'min_amount'  => (float) get_option('woocommerce_cod_fee_min_amount', 0),
            'max_amount'  => (float) get_option('woocommerce_cod_fee_max_amount', 0),
        ];
    }
    
    /**
     * Check if COD is selected
     */
    private function is_cod_selected(): bool {
        if (!WC()->session) {
            return false;
        }
        
        $chosen_payment_method = WC()->session->get('chosen_payment_method');
        
        if (isset($_POST['payment_method'])) {
            $chosen_payment_method = sanitize_text_field(wp_unslash($_POST['payment_method']));
        }
        
        return $chosen_payment_method === 'cod';
    }
    
    /**
     * Calculate fee amount
     */
    private function calculate_fee_amount(array $settings): float {
        if ($settings['enabled'] !== 'yes' || $settings['amount'] <= 0) {
            return 0;
        }
        
        $cart_total = WC()->cart->get_subtotal() + WC()->cart->get_shipping_total();
        
        if (wc_prices_include_tax()) {
            $cart_total += WC()->cart->get_subtotal_tax() + WC()->cart->get_shipping_tax();
        }
        
        // Check min/max
        if ($settings['min_amount'] > 0 && $cart_total < $settings['min_amount']) {
            return 0;
        }
        
        if ($settings['max_amount'] > 0 && $cart_total > $settings['max_amount']) {
            return 0;
        }
        
        // Calculate fee
        if ($settings['type'] === 'percentage') {
            $fee = ($cart_total * $settings['amount']) / 100;
        } else {
            $fee = $settings['amount'];
        }
        
        return round($fee, wc_get_price_decimals());
    }
    
    /**
     * Add COD fee to cart
     */
    public function add_cod_fee(): void {
        if (!is_checkout() && !wp_doing_ajax()) {
            return;
        }
        
        if (!$this->is_cod_selected()) {
            return;
        }
        
        $settings = $this->get_cod_settings();
        $fee_amount = $this->calculate_fee_amount($settings);
        
        if ($fee_amount > 0) {
            $taxable = $settings['tax_status'] === 'taxable';
            WC()->cart->add_fee($settings['label'], $fee_amount, $taxable);
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
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts(): void {
        if (!is_checkout()) {
            return;
        }
        
        // Enqueue the external JavaScript file
        wp_enqueue_script(
            'wc-cod-fee-restrictions-restrictions',
            plugin_dir_url(__FILE__) . 'assets/js/cod-fee.js',
            ['jquery', 'wc-checkout'],
            self::VERSION,
            true
        );
        
        // Localize script with settings
        wp_localize_script('wc-cod-fee-restrictions-restrictions', 'wcCodFee', [
            'codSettings' => $this->get_cod_settings(),
            'currency' => get_woocommerce_currency(),
            'currencySymbol' => get_woocommerce_currency_symbol(),
            'isBlockCheckout' => class_exists('\Automattic\WooCommerce\Blocks\Package'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc-cod-fee-restrictions-restrictions'),
        ]);
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook): void {
        if (strpos($hook, 'wc-cod-fee-restrictions-settings') === false && 
            strpos($hook, 'wc-settings') === false) {
            return;
        }
        
        wp_add_inline_script('jquery', "
            jQuery(function($) {
                // Dynamic preview of fee settings
                $('#woocommerce_cod_fee_type').on('change', function() {
                    var type = $(this).val();
                    var amountField = $('#woocommerce_cod_fee_amount');
                    if (type === 'percentage') {
                        amountField.attr('max', '100');
                        amountField.closest('tr').find('.description').text('" . __('Enter percentage (0-100)', 'wc-cod-fee-restrictions-restrictions') . "');
                    } else {
                        amountField.removeAttr('max');
                        amountField.closest('tr').find('.description').text('" . sprintf(__('Enter amount in %s', 'wc-cod-fee-restrictions-restrictions'), get_woocommerce_currency()) . "');
                    }
                }).trigger('change');
            });
        ");
    }
    
    /**
     * Save COD fee to order
     */
    public function save_cod_fee_to_order(\WC_Order $order, array $data): void {
        if ($order->get_payment_method() !== 'cod') {
            return;
        }
        
        $settings = $this->get_cod_settings();
        
        foreach ($order->get_fees() as $fee) {
            if ($fee->get_name() === $settings['label']) {
                $order->update_meta_data('_cod_fee_amount', $fee->get_total());
                $order->update_meta_data('_cod_fee_label', $fee->get_name());
                break;
            }
        }
    }
    
    /**
     * Display COD fee in admin order
     */
    public function display_cod_fee_in_admin(int $order_id): void {
        $order = wc_get_order($order_id);
        
        if (!$order || $order->get_payment_method() !== 'cod') {
            return;
        }
        
        $cod_fee = $order->get_meta('_cod_fee_amount');
        $cod_label = $order->get_meta('_cod_fee_label');
        
        if ($cod_fee) {
            ?>
            <tr>
                <td class="label"><?php echo esc_html($cod_label ?: __('COD Fee', 'wc-cod-fee-restrictions')); ?>:</td>
                <td width="1%"></td>
                <td class="total">
                    <?php echo wc_price($cod_fee, ['currency' => $order->get_currency()]); ?>
                </td>
            </tr>
            <?php
        }
    }
}

// Initialize plugin
add_action('plugins_loaded', function() {
    CODFeeManager::get_instance();
}, 10);

// Activation hook
register_activation_hook(__FILE__, function() {
    if (version_compare(PHP_VERSION, '8.0', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('This plugin requires PHP 8.0 or higher.', 'wc-cod-fee-restrictions'));
    }
    
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('This plugin requires WooCommerce to be installed and active.', 'wc-cod-fee-restrictions'));
    }
    
    // Set default options
    add_option('woocommerce_cod_fee_enabled', 'no');
    add_option('woocommerce_cod_fee_amount', '5');
    add_option('woocommerce_cod_fee_type', 'fixed');
    add_option('woocommerce_cod_fee_label', __('Cash on Delivery Fee', 'wc-cod-fee-restrictions'));
    add_option('woocommerce_cod_fee_tax_status', 'taxable');
    add_option('woocommerce_cod_fee_min_amount', '0');
    add_option('woocommerce_cod_fee_max_amount', '0');
});
