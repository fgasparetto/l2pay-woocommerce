<?php
/**
 * Plugin Name: Layer Crypto Checkout - Crypto Payments for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/layer-crypto-checkout
 * Description: Accept ETH and USDC payments via MetaMask or WalletConnect on Ethereum, Base, Optimism, and Arbitrum. Non-custodial, low fees (1%), instant settlements.
 * Version: 1.5.0
 * Author: Layer Crypto Checkout
 * Author URI: https://layercryptocheckout.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: layer-crypto-checkout
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('LCCP_VERSION', '1.6.0');
define('LCCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LCCP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LCCP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Plugin activation hook
 * Creates necessary database tables for security features
 */
function lccp_activate() {
    // Load the TX Hash class
    require_once LCCP_PLUGIN_DIR . 'includes/class-lccp-txhash.php';

    // Create the TX hash idempotency table
    LCCP_TxHash::create_table();
}
register_activation_hook(__FILE__, 'lccp_activate');

/**
 * Supported networks configuration
 * Contract addresses can be overridden in wp-config.php:
 * define('LCCP_CONTRACTS', ['sepolia' => '0x...', 'base_sepolia' => '0x...']);
 */
function lccp_get_networks() {
    // Default contract addresses (testnets)
    $default_contracts = array(
        'sepolia' => '0x027811E894b6388C514f909d54921a701337f467',
        'base_sepolia' => '0xF0DCC0C62587804d9c49B075d24725A9a6eA2c6E',
        'optimism_sepolia' => '0x3E9334D16A57ADC0cAb7Cea24703aC819c1DAB8D',
        'arbitrum_sepolia' => '0xC5913aE49d6C52267B58824297EC36d36c27740d',
        // Mainnets
        'ethereum' => '0x84f679497947f9186258Af929De2e760677D5949',
        'base' => '0x84f679497947f9186258Af929De2e760677D5949',
        'optimism' => '0x84f679497947f9186258Af929De2e760677D5949',
        'arbitrum' => '0x84f679497947f9186258Af929De2e760677D5949',
    );

    // USDC token addresses
    $usdc_addresses = array(
        // Testnets (MockUSDC - mintable for testing)
        'sepolia' => '0x7474e771f6f3d8123aa4cDD8d3593866651a14e6',
        'base_sepolia' => '0x0f411ff500f88BB528b800C7116c28d80f8BbD44',
        'optimism_sepolia' => '0x0f411ff500f88BB528b800C7116c28d80f8BbD44',
        'arbitrum_sepolia' => '0xd95480E52E671b87D6de3A3F05fbAb0E8526843F',
        // Mainnets (official Circle USDC)
        'ethereum' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
        'base' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
        'optimism' => '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85',
        'arbitrum' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831',
    );

    // Allow override from wp-config.php
    $contracts = defined('LCCP_CONTRACTS') ? LCCP_CONTRACTS : $default_contracts;
    $contracts = array_merge($default_contracts, $contracts);

    // Allow USDC override from wp-config.php
    if (defined('LCCP_USDC_ADDRESSES')) {
        $usdc_addresses = array_merge($usdc_addresses, LCCP_USDC_ADDRESSES);
    }

    return array(
        // === TESTNETS ===
        'sepolia' => array(
            'name' => 'Ethereum Sepolia',
            'chain_id' => '0xaa36a7', // 11155111
            'chain_id_dec' => 11155111,
            'contract' => $contracts['sepolia'],
            'rpc_url' => 'https://rpc.sepolia.org',
            'explorer' => 'https://sepolia.etherscan.io',
            'symbol' => 'ETH',
            'is_testnet' => true,
            'usdc_address' => $usdc_addresses['sepolia'],
            'usdc_decimals' => 6,
        ),
        'base_sepolia' => array(
            'name' => 'Base Sepolia',
            'chain_id' => '0x14a34', // 84532
            'chain_id_dec' => 84532,
            'contract' => $contracts['base_sepolia'],
            'rpc_url' => 'https://sepolia.base.org',
            'explorer' => 'https://sepolia.basescan.org',
            'symbol' => 'ETH',
            'is_testnet' => true,
            'usdc_address' => $usdc_addresses['base_sepolia'],
            'usdc_decimals' => 6,
        ),
        'optimism_sepolia' => array(
            'name' => 'Optimism Sepolia',
            'chain_id' => '0xaa37dc', // 11155420
            'chain_id_dec' => 11155420,
            'contract' => $contracts['optimism_sepolia'],
            'rpc_url' => 'https://sepolia.optimism.io',
            'explorer' => 'https://sepolia-optimism.etherscan.io',
            'symbol' => 'ETH',
            'is_testnet' => true,
            'usdc_address' => $usdc_addresses['optimism_sepolia'],
            'usdc_decimals' => 6,
        ),
        'arbitrum_sepolia' => array(
            'name' => 'Arbitrum Sepolia',
            'chain_id' => '0x66eee', // 421614
            'chain_id_dec' => 421614,
            'contract' => $contracts['arbitrum_sepolia'],
            'rpc_url' => 'https://sepolia-rollup.arbitrum.io/rpc',
            'explorer' => 'https://sepolia.arbiscan.io',
            'symbol' => 'ETH',
            'is_testnet' => true,
            'usdc_address' => $usdc_addresses['arbitrum_sepolia'],
            'usdc_decimals' => 6,
        ),
        // === MAINNETS ===
        'ethereum' => array(
            'name' => 'Ethereum',
            'chain_id' => '0x1', // 1
            'chain_id_dec' => 1,
            'contract' => $contracts['ethereum'],
            'rpc_url' => 'https://eth.llamarpc.com',
            'explorer' => 'https://etherscan.io',
            'symbol' => 'ETH',
            'is_testnet' => false,
            'usdc_address' => $usdc_addresses['ethereum'],
            'usdc_decimals' => 6,
        ),
        'base' => array(
            'name' => 'Base',
            'chain_id' => '0x2105', // 8453
            'chain_id_dec' => 8453,
            'contract' => $contracts['base'],
            'rpc_url' => 'https://mainnet.base.org',
            'explorer' => 'https://basescan.org',
            'symbol' => 'ETH',
            'is_testnet' => false,
            'usdc_address' => $usdc_addresses['base'],
            'usdc_decimals' => 6,
        ),
        'optimism' => array(
            'name' => 'Optimism',
            'chain_id' => '0xa', // 10
            'chain_id_dec' => 10,
            'contract' => $contracts['optimism'],
            'rpc_url' => 'https://mainnet.optimism.io',
            'explorer' => 'https://optimistic.etherscan.io',
            'symbol' => 'ETH',
            'is_testnet' => false,
            'usdc_address' => $usdc_addresses['optimism'],
            'usdc_decimals' => 6,
        ),
        'arbitrum' => array(
            'name' => 'Arbitrum One',
            'chain_id' => '0xa4b1', // 42161
            'chain_id_dec' => 42161,
            'contract' => $contracts['arbitrum'],
            'rpc_url' => 'https://arb1.arbitrum.io/rpc',
            'explorer' => 'https://arbiscan.io',
            'symbol' => 'ETH',
            'is_testnet' => false,
            'usdc_address' => $usdc_addresses['arbitrum'],
            'usdc_decimals' => 6,
        ),
    );
}

/**
 * Get the current network mode (test or live)
 */
function lccp_get_network_mode() {
    $settings = get_option('woocommerce_layer-crypto-checkout_settings', array());
    return isset($settings['network_mode']) ? $settings['network_mode'] : 'test';
}

/**
 * Check if we're in test mode
 */
function lccp_is_test_mode() {
    return lccp_get_network_mode() === 'test';
}

/**
 * Get available networks (with deployed contracts)
 * Filters based on current network mode (test/live)
 */
function lccp_get_available_networks($respect_mode = true) {
    $networks = lccp_get_networks();
    $available = array();
    $mode = lccp_get_network_mode();

    foreach ($networks as $key => $network) {
        if (!empty($network['contract'])) {
            if ($respect_mode) {
                // In test mode, only show testnets; in live mode, only show mainnets
                if ($mode === 'test' && $network['is_testnet']) {
                    $available[$key] = $network;
                } elseif ($mode === 'live' && !$network['is_testnet']) {
                    $available[$key] = $network;
                }
            } else {
                // Show all networks with contracts
                $available[$key] = $network;
            }
        }
    }

    return $available;
}

/**
 * Get network configuration by key
 */
function lccp_get_network($network_key) {
    $networks = lccp_get_networks();
    return isset($networks[$network_key]) ? $networks[$network_key] : null;
}

/**
 * Check if WooCommerce is active
 */
function lccp_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'lccp_woocommerce_missing_notice');
        return false;
    }
    return true;
}

/**
 * Admin notice for missing WooCommerce
 */
function lccp_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e('Layer Crypto Checkout requires WooCommerce to be installed and active.', 'layer-crypto-checkout'); ?></p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 */
function lccp_init() {
    if (!lccp_check_woocommerce()) {
        return;
    }

    // Load the TX Hash idempotency class
    require_once LCCP_PLUGIN_DIR . 'includes/class-lccp-txhash.php';

    // Only run create_table when DB version changes (upgrades)
    if (get_option('lccp_txhash_db_version') !== '1.0') {
        LCCP_TxHash::create_table();
    }

    // Load the gateway class
    require_once LCCP_PLUGIN_DIR . 'includes/class-lccp-gateway.php';

    // Load the API class
    require_once LCCP_PLUGIN_DIR . 'includes/class-lccp-api.php';

    // Initialize API
    new LCCP_API();
}
add_action('plugins_loaded', 'lccp_init');

/**
 * Add the gateway to WooCommerce
 */
function lccp_add_gateway($gateways) {
    $gateways[] = 'LCCP_Gateway';
    return $gateways;
}
add_filter('woocommerce_payment_gateways', 'lccp_add_gateway');

/**
 * Add settings link to plugins page
 */
function lccp_plugin_links($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=layer-crypto-checkout')) . '">' . esc_html__('Settings', 'layer-crypto-checkout') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . LCCP_PLUGIN_BASENAME, 'lccp_plugin_links');

/**
 * Enqueue scripts for checkout
 */
function lccp_enqueue_scripts() {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    // Skip classic scripts on block checkout (blocks support handles its own scripts)
    if (has_block('woocommerce/checkout')) {
        return;
    }

    // Get gateway settings
    $gateway = new LCCP_Gateway();

    if ($gateway->enabled !== 'yes') {
        return;
    }

    // Enqueue the checkout script
    wp_enqueue_script(
        'lccp-checkout',
        LCCP_PLUGIN_URL . 'assets/js/lccp-checkout.js',
        array('jquery'),
        LCCP_VERSION,
        true
    );

    // Enqueue styles
    wp_enqueue_style(
        'lccp-styles',
        LCCP_PLUGIN_URL . 'assets/css/lccp.css',
        array(),
        LCCP_VERSION
    );

    // Get available networks for checkout
    $available_networks = lccp_get_available_networks();
    $networks_for_js = array();
    foreach ($available_networks as $key => $network) {
        $networks_for_js[$key] = array(
            'name' => $network['name'],
            'chainId' => $network['chain_id'],
            'chainIdDec' => $network['chain_id_dec'],
            'contract' => $network['contract'],
            'rpcUrl' => $network['rpc_url'],
            'explorer' => $network['explorer'],
            'symbol' => $network['symbol'],
            'isTestnet' => $network['is_testnet'],
            'usdcAddress' => isset($network['usdc_address']) ? $network['usdc_address'] : '',
            'usdcDecimals' => isset($network['usdc_decimals']) ? $network['usdc_decimals'] : 6,
        );
    }

    // Get network mode
    $network_mode = lccp_get_network_mode();
    $is_test_mode = lccp_is_test_mode();

    // Get cart total for fallback
    $cart_total = 0;
    if (WC()->cart) {
        $cart_total = WC()->cart->get_total('edit');
    }

    // Pass data to JavaScript
    wp_localize_script('lccp-checkout', 'lccpData', array(
        'pluginUrl' => LCCP_PLUGIN_URL,
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'restUrl' => rest_url('lccp/v1/'),
        'nonce' => wp_create_nonce('lccp-nonce'),
        'networks' => $networks_for_js,
        'defaultNetwork' => $is_test_mode ? 'base_sepolia' : 'base',
        'merchantAddress' => $gateway->get_option('merchant_address', ''),
        'currency' => get_woocommerce_currency(),
        'cartTotal' => $cart_total,
        'contractABI' => lccp_get_contract_abi(),
        'erc20ABI' => lccp_get_erc20_abi(),
        'networkMode' => $network_mode,
        'isTestMode' => $is_test_mode,
        'walletConnectProjectId' => 'bde3cb22b79eeda9c1dcfce0e9e4cdbd',
        'i18n' => array(
            'connectWallet' => __('Connect Wallet', 'layer-crypto-checkout'),
            'payWithEth' => __('Pay with ETH', 'layer-crypto-checkout'),
            'payWithUsdc' => __('Pay with USDC', 'layer-crypto-checkout'),
            'processing' => __('Processing...', 'layer-crypto-checkout'),
            'approving' => __('Approving USDC...', 'layer-crypto-checkout'),
            'waitingConfirmation' => __('Waiting for confirmation...', 'layer-crypto-checkout'),
            'paymentComplete' => __('Payment complete!', 'layer-crypto-checkout'),
            'noWalletFound' => __('No wallet found. Please install MetaMask or use WalletConnect.', 'layer-crypto-checkout'),
            'wrongNetwork' => __('Please switch network', 'layer-crypto-checkout'),
            'transactionFailed' => __('Transaction failed. Please try again.', 'layer-crypto-checkout'),
            'conversionError' => __('Error getting price. Please try again.', 'layer-crypto-checkout'),
            'selectNetwork' => __('Select Network', 'layer-crypto-checkout'),
            'selectPaymentMethod' => __('Payment Method', 'layer-crypto-checkout'),
            'merchantNotConfigured' => __('Merchant wallet not configured. Please contact store owner.', 'layer-crypto-checkout'),
            'insufficientUsdc' => __('Insufficient USDC balance', 'layer-crypto-checkout'),
            'approvalFailed' => __('USDC approval failed. Please try again.', 'layer-crypto-checkout'),
            'connecting' => __('Connecting...', 'layer-crypto-checkout'),
            'disconnected' => __('Wallet disconnected', 'layer-crypto-checkout'),
        ),
    ));
}
add_action('wp_enqueue_scripts', 'lccp_enqueue_scripts');

/**
 * Enqueue WalletConnect scripts
 */
function lccp_enqueue_walletconnect() {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    // Skip on block checkout
    if (has_block('woocommerce/checkout')) {
        return;
    }

    $gateway = new LCCP_Gateway();
    if ($gateway->enabled !== 'yes') {
        return;
    }

    // Enqueue WalletConnect from local file
    wp_enqueue_script(
        'walletconnect-provider',
        LCCP_PLUGIN_URL . 'assets/js/vendor/walletconnect-provider.min.js',
        array(),
        '2.17.0',
        false
    );

    // Add polyfill before WalletConnect loads
    wp_add_inline_script('walletconnect-provider', '
        if (typeof process === "undefined") {
            window.process = { env: {}, browser: true };
        }
        if (typeof global === "undefined") {
            window.global = window;
        }
    ', 'before');
}
add_action('wp_enqueue_scripts', 'lccp_enqueue_walletconnect', 5);

/**
 * Add WalletConnect configuration via wp_add_inline_script
 */
function lccp_add_walletconnect_config() {
    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    // Skip on block checkout
    if (has_block('woocommerce/checkout')) {
        return;
    }

    $gateway = new LCCP_Gateway();
    if ($gateway->enabled !== 'yes') {
        return;
    }

    $is_test_mode = lccp_is_test_mode();
    $chain_ids = $is_test_mode
        ? '[11155111, 84532, 11155420, 421614]'
        : '[1, 8453, 10, 42161]';
    $default_chain = $is_test_mode ? '11155111' : '1';

    $inline_script = sprintf(
        'window.LCCPWalletConfig = {
            projectId: "%s",
            chainIds: %s,
            isTestMode: %s,
            defaultChainId: %s
        };',
        esc_js('bde3cb22b79eeda9c1dcfce0e9e4cdbd'),
        $chain_ids,
        $is_test_mode ? 'true' : 'false',
        $default_chain
    );

    wp_add_inline_script('walletconnect-provider', $inline_script, 'after');
}
add_action('wp_enqueue_scripts', 'lccp_add_walletconnect_config', 6);

/**
 * Get the contract ABI (pay and payWithToken functions)
 */
function lccp_get_contract_abi() {
    return json_encode(array(
        // pay(uint256,address) - ETH payments
        array(
            'inputs' => array(
                array('internalType' => 'uint256', 'name' => '_orderId', 'type' => 'uint256'),
                array('internalType' => 'address', 'name' => '_merchantAddress', 'type' => 'address')
            ),
            'name' => 'pay',
            'outputs' => array(),
            'stateMutability' => 'payable',
            'type' => 'function'
        ),
        // payWithToken(uint256,address,address,uint256) - ERC20 token payments
        array(
            'inputs' => array(
                array('internalType' => 'uint256', 'name' => '_orderId', 'type' => 'uint256'),
                array('internalType' => 'address', 'name' => '_merchantAddress', 'type' => 'address'),
                array('internalType' => 'address', 'name' => '_tokenAddress', 'type' => 'address'),
                array('internalType' => 'uint256', 'name' => '_amount', 'type' => 'uint256')
            ),
            'name' => 'payWithToken',
            'outputs' => array(),
            'stateMutability' => 'nonpayable',
            'type' => 'function'
        ),
        // PaymentReceived event
        array(
            'anonymous' => false,
            'inputs' => array(
                array('indexed' => true, 'internalType' => 'address', 'name' => 'payer', 'type' => 'address'),
                array('indexed' => true, 'internalType' => 'address', 'name' => 'merchant', 'type' => 'address'),
                array('indexed' => false, 'internalType' => 'uint256', 'name' => 'orderId', 'type' => 'uint256'),
                array('indexed' => false, 'internalType' => 'uint256', 'name' => 'amount', 'type' => 'uint256'),
                array('indexed' => false, 'internalType' => 'uint256', 'name' => 'merchantAmount', 'type' => 'uint256'),
                array('indexed' => false, 'internalType' => 'uint256', 'name' => 'platformFee', 'type' => 'uint256'),
                array('indexed' => false, 'internalType' => 'uint256', 'name' => 'timestamp', 'type' => 'uint256')
            ),
            'name' => 'PaymentReceived',
            'type' => 'event'
        ),
        // TokenPaymentReceived event
        array(
            'anonymous' => false,
            'inputs' => array(
                array('indexed' => true, 'internalType' => 'address', 'name' => 'payer', 'type' => 'address'),
                array('indexed' => true, 'internalType' => 'address', 'name' => 'merchant', 'type' => 'address'),
                array('indexed' => true, 'internalType' => 'address', 'name' => 'token', 'type' => 'address'),
                array('indexed' => false, 'internalType' => 'uint256', 'name' => 'orderId', 'type' => 'uint256'),
                array('indexed' => false, 'internalType' => 'uint256', 'name' => 'amount', 'type' => 'uint256'),
                array('indexed' => false, 'internalType' => 'uint256', 'name' => 'merchantAmount', 'type' => 'uint256'),
                array('indexed' => false, 'internalType' => 'uint256', 'name' => 'platformFee', 'type' => 'uint256'),
                array('indexed' => false, 'internalType' => 'uint256', 'name' => 'timestamp', 'type' => 'uint256')
            ),
            'name' => 'TokenPaymentReceived',
            'type' => 'event'
        )
    ));
}

/**
 * Get ERC20 token ABI (approve and balanceOf)
 */
function lccp_get_erc20_abi() {
    return json_encode(array(
        // approve(address,uint256)
        array(
            'inputs' => array(
                array('internalType' => 'address', 'name' => 'spender', 'type' => 'address'),
                array('internalType' => 'uint256', 'name' => 'amount', 'type' => 'uint256')
            ),
            'name' => 'approve',
            'outputs' => array(array('internalType' => 'bool', 'name' => '', 'type' => 'bool')),
            'stateMutability' => 'nonpayable',
            'type' => 'function'
        ),
        // balanceOf(address)
        array(
            'inputs' => array(
                array('internalType' => 'address', 'name' => 'account', 'type' => 'address')
            ),
            'name' => 'balanceOf',
            'outputs' => array(array('internalType' => 'uint256', 'name' => '', 'type' => 'uint256')),
            'stateMutability' => 'view',
            'type' => 'function'
        ),
        // allowance(address,address)
        array(
            'inputs' => array(
                array('internalType' => 'address', 'name' => 'owner', 'type' => 'address'),
                array('internalType' => 'address', 'name' => 'spender', 'type' => 'address')
            ),
            'name' => 'allowance',
            'outputs' => array(array('internalType' => 'uint256', 'name' => '', 'type' => 'uint256')),
            'stateMutability' => 'view',
            'type' => 'function'
        ),
        // decimals()
        array(
            'inputs' => array(),
            'name' => 'decimals',
            'outputs' => array(array('internalType' => 'uint8', 'name' => '', 'type' => 'uint8')),
            'stateMutability' => 'view',
            'type' => 'function'
        )
    ));
}

/**
 * Get explorer URL for a transaction
 */
function lccp_get_tx_url($tx_hash, $network_key = 'sepolia') {
    $network = lccp_get_network($network_key);
    if (!$network) {
        $network = lccp_get_network('sepolia');
    }
    return $network['explorer'] . '/tx/' . $tx_hash;
}

/**
 * Get explorer URL for an address
 */
function lccp_get_address_url($address, $network_key = 'sepolia') {
    $network = lccp_get_network($network_key);
    if (!$network) {
        $network = lccp_get_network('sepolia');
    }
    return $network['explorer'] . '/address/' . $address;
}

/**
 * AJAX handler to verify payment - DEPRECATED
 *
 * This endpoint is kept for backwards compatibility but now performs
 * on-chain verification before completing the order.
 *
 * The main verification happens in LCCP_Gateway::process_payment()
 * which is called when the checkout form is submitted.
 */
function lccp_verify_payment() {
    check_ajax_referer('lccp-nonce', 'nonce');

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $tx_hash = isset($_POST['tx_hash']) ? sanitize_text_field(wp_unslash($_POST['tx_hash'])) : '';
    $network_key = isset($_POST['network']) ? sanitize_text_field(wp_unslash($_POST['network'])) : 'sepolia';

    if (!$order_id || !$tx_hash) {
        wp_send_json_error(array('message' => 'Invalid parameters'));
    }

    // Validate TX hash format
    if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $tx_hash)) {
        wp_send_json_error(array('message' => 'Invalid transaction hash format'));
    }

    $order = wc_get_order($order_id);

    if (!$order) {
        wp_send_json_error(array('message' => 'Order not found'));
    }

    // Check if already verified
    if ($order->get_meta('_lccp_verified') === 'yes') {
        wp_send_json_success(array(
            'message' => 'Payment already verified',
            'redirect' => $order->get_checkout_order_received_url()
        ));
        return;
    }

    // SECURITY: Check TX hash idempotency - prevent replay attacks
    if (class_exists('LCCP_TxHash')) {
        $existing = LCCP_TxHash::is_used($tx_hash);
        if ($existing) {
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %d: existing order ID */
                    __('This transaction has already been used for order #%d', 'layer-crypto-checkout'),
                    $existing['order_id']
                )
            ));
            return;
        }
    }

    // SECURITY: Perform on-chain verification before completing
    $gateway = new LCCP_Gateway();
    $network = lccp_get_network($network_key);

    if (!$network || empty($network['contract'])) {
        wp_send_json_error(array('message' => 'Invalid network configuration'));
    }

    // Get RPC URL and verify transaction on-chain
    $rpc_urls = array(
        'sepolia' => 'https://ethereum-sepolia-rpc.publicnode.com',
        'base_sepolia' => 'https://sepolia.base.org',
        'optimism_sepolia' => 'https://sepolia.optimism.io',
        'arbitrum_sepolia' => 'https://sepolia-rollup.arbitrum.io/rpc',
        'ethereum' => 'https://eth.llamarpc.com',
        'base' => 'https://mainnet.base.org',
        'optimism' => 'https://mainnet.optimism.io',
        'arbitrum' => 'https://arb1.arbitrum.io/rpc',
    );

    $rpc_url = isset($rpc_urls[$network_key]) ? $rpc_urls[$network_key] : null;

    if (!$rpc_url) {
        wp_send_json_error(array('message' => 'No RPC configured for this network'));
    }

    // Call eth_getTransactionReceipt
    $response = wp_remote_post($rpc_url, array(
        'timeout' => 30,
        'headers' => array('Content-Type' => 'application/json'),
        'body' => json_encode(array(
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'eth_getTransactionReceipt',
            'params' => array($tx_hash)
        ))
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Failed to verify on blockchain: ' . $response->get_error_message()));
    }

    $result = json_decode(wp_remote_retrieve_body($response), true);
    $receipt = isset($result['result']) ? $result['result'] : null;

    if (!$receipt) {
        wp_send_json_error(array('message' => 'Transaction not yet confirmed. Please wait.'));
    }

    // Verify transaction status
    $status = isset($receipt['status']) ? hexdec($receipt['status']) : 0;
    if ($status !== 1) {
        wp_send_json_error(array('message' => 'Transaction failed on blockchain'));
    }

    // Verify transaction was sent to our contract
    $tx_to = strtolower($receipt['to'] ?? '');
    $expected_contract = strtolower($network['contract']);

    if ($tx_to !== $expected_contract) {
        wp_send_json_error(array('message' => 'Transaction sent to wrong contract'));
    }

    // Verify payment event exists and merchant matches
    $merchant_address = strtolower($gateway->get_option('merchant_address', ''));
    $eth_topic = '0x4aa351061f13d3dff9e0f6cab4811de6a51a2f94e424b21ce31914f1e99c17bc';
    $token_topic = '0x0a7e11d6b5194b35bf3d4e463e2cb08dd9681b79fe6d4a1ff9725977a7da38d7';

    $valid_payment = false;
    foreach ($receipt['logs'] as $log) {
        if (empty($log['topics'])) continue;

        $topic = $log['topics'][0];
        if (($topic === $eth_topic || $topic === $token_topic) && count($log['topics']) >= 3) {
            $event_merchant = '0x' . substr($log['topics'][2], -40);
            if (strtolower($event_merchant) === $merchant_address) {
                $valid_payment = true;
                break;
            }
        }
    }

    if (!$valid_payment) {
        wp_send_json_error(array('message' => 'No valid payment to merchant found in transaction'));
    }

    // SECURITY: Record TX hash to prevent reuse (race condition handled)
    if (class_exists('LCCP_TxHash')) {
        $record_result = LCCP_TxHash::record($tx_hash, $order_id, $network_key);
        if ($record_result === 'duplicate') {
            $existing = LCCP_TxHash::is_used($tx_hash);
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: existing order ID */
                    __('This transaction has already been used for order #%s', 'layer-crypto-checkout'),
                    $existing ? $existing['order_id'] : 'unknown'
                )
            ));
            return;
        }
    }

    // All verifications passed - update order
    $explorer_url = lccp_get_tx_url($tx_hash, $network_key);

    $order->update_meta_data('_lccp_tx_hash', $tx_hash);
    $order->update_meta_data('_lccp_network', $network_key);
    $order->update_meta_data('_lccp_verified', 'yes');
    $order->update_meta_data('_lccp_verified_at', time());
    $order->update_meta_data('_lccp_payment_time', current_time('mysql'));

    $order->payment_complete($tx_hash);
    $order->add_order_note(
        sprintf(
            /* translators: 1: network name, 2: transaction link */
            __('Layer Crypto Checkout payment verified on-chain (%1$s). Transaction: %2$s', 'layer-crypto-checkout'),
            esc_html($network['name']),
            '<a href="' . esc_url($explorer_url) . '" target="_blank">' . esc_html(substr($tx_hash, 0, 20)) . '...</a>'
        )
    );

    $order->save();

    // Empty the cart
    WC()->cart->empty_cart();

    wp_send_json_success(array(
        'message' => 'Payment verified on-chain',
        'redirect' => $order->get_checkout_order_received_url()
    ));
}
add_action('wp_ajax_lccp_verify_payment', 'lccp_verify_payment');
add_action('wp_ajax_nopriv_lccp_verify_payment', 'lccp_verify_payment');

/**
 * Display transaction hash on order details
 */
function lccp_display_tx_hash($order) {
    $tx_hash = $order->get_meta('_lccp_tx_hash');
    $network_key = $order->get_meta('_lccp_network') ?: 'sepolia';
    $network = lccp_get_network($network_key);

    if ($tx_hash) {
        $explorer_url = lccp_get_tx_url($tx_hash, $network_key);
        echo '<p><strong>' . esc_html__('Transaction Hash:', 'layer-crypto-checkout') . '</strong><br>';
        echo '<a href="' . esc_url($explorer_url) . '" target="_blank">' . esc_html($tx_hash) . '</a>';
        if ($network) {
            echo '<br><small>(' . esc_html($network['name']) . ')</small>';
        }
        echo '</p>';
    }
}
add_action('woocommerce_order_details_after_order_table', 'lccp_display_tx_hash');

/**
 * Display transaction hash in admin order page
 */
function lccp_admin_order_data($order) {
    $tx_hash = $order->get_meta('_lccp_tx_hash');
    $eth_amount = $order->get_meta('_lccp_eth_amount');
    $network_key = $order->get_meta('_lccp_network') ?: 'sepolia';
    $network = lccp_get_network($network_key);

    if ($tx_hash || $eth_amount) {
        echo '<div class="order_data_column">';
        echo '<h4>' . esc_html__('Layer Crypto Checkout Payment', 'layer-crypto-checkout') . '</h4>';

        if ($network) {
            echo '<p><strong>' . esc_html__('Network:', 'layer-crypto-checkout') . '</strong> ' . esc_html($network['name']) . '</p>';
        }

        if ($eth_amount) {
            $symbol = $network ? $network['symbol'] : 'ETH';
            echo '<p><strong>' . esc_html__('Amount:', 'layer-crypto-checkout') . '</strong> ' . esc_html($eth_amount) . ' ' . esc_html($symbol) . '</p>';
        }

        if ($tx_hash) {
            $explorer_url = lccp_get_tx_url($tx_hash, $network_key);
            echo '<p><strong>' . esc_html__('Transaction:', 'layer-crypto-checkout') . '</strong><br>';
            echo '<a href="' . esc_url($explorer_url) . '" target="_blank" style="word-break: break-all;">' . esc_html($tx_hash) . '</a></p>';
        }

        echo '</div>';
    }
}
add_action('woocommerce_admin_order_data_after_shipping_address', 'lccp_admin_order_data');

/**
 * Declare HPOS compatibility
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Register payment method type for WooCommerce Block Checkout
 */
add_action('woocommerce_blocks_loaded', function() {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }
    require_once LCCP_PLUGIN_DIR . 'includes/class-lccp-blocks-support.php';
    add_action('woocommerce_blocks_payment_method_type_registration', function($registry) {
        $registry->register(new LCCP_Blocks_Support());
    });
});
