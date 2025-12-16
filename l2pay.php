<?php
/**
 * Plugin Name: L2Pay - Crypto Payments for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/l2pay
 * Description: Accept ETH and USDC payments via MetaMask on Ethereum, Base, Optimism, and Arbitrum. Non-custodial, low fees (1%), instant settlements.
 * Version: 1.0.0
 * Author: L2Pay
 * Author URI: https://l2pay.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: l2pay
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
define('L2PAY_VERSION', '1.0.0');
define('L2PAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('L2PAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('L2PAY_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Supported networks configuration
 * Contract addresses can be overridden in wp-config.php:
 * define('L2PAY_CONTRACTS', ['sepolia' => '0x...', 'base_sepolia' => '0x...']);
 */
function l2pay_get_networks() {
    // Default contract addresses (testnets) - v4.1 with USDC fix
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
    $contracts = defined('L2PAY_CONTRACTS') ? L2PAY_CONTRACTS : $default_contracts;
    $contracts = array_merge($default_contracts, $contracts);

    // Allow USDC override from wp-config.php
    if (defined('L2PAY_USDC_ADDRESSES')) {
        $usdc_addresses = array_merge($usdc_addresses, L2PAY_USDC_ADDRESSES);
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
function l2pay_get_network_mode() {
    $settings = get_option('woocommerce_l2pay_settings', array());
    return isset($settings['network_mode']) ? $settings['network_mode'] : 'test';
}

/**
 * Check if we're in test mode
 */
function l2pay_is_test_mode() {
    return l2pay_get_network_mode() === 'test';
}

/**
 * Get available networks (with deployed contracts)
 * Filters based on current network mode (test/live)
 */
function l2pay_get_available_networks($respect_mode = true) {
    $networks = l2pay_get_networks();
    $available = array();
    $mode = l2pay_get_network_mode();

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
function l2pay_get_network($network_key) {
    $networks = l2pay_get_networks();
    return isset($networks[$network_key]) ? $networks[$network_key] : null;
}

/**
 * Check if WooCommerce is active
 */
function l2pay_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'l2pay_woocommerce_missing_notice');
        return false;
    }
    return true;
}

/**
 * Admin notice for missing WooCommerce
 */
function l2pay_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('L2Pay Gateway requires WooCommerce to be installed and active.', 'l2pay'); ?></p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 */
function l2pay_init() {
    // Load translations
    load_plugin_textdomain('l2pay', false, dirname(L2PAY_PLUGIN_BASENAME) . '/languages');

    if (!l2pay_check_woocommerce()) {
        return;
    }

    // Load the gateway class
    require_once L2PAY_PLUGIN_DIR . 'includes/class-l2pay-gateway.php';

    // Load the API class
    require_once L2PAY_PLUGIN_DIR . 'includes/class-l2pay-api.php';

    // Initialize API
    new L2Pay_API();
}
add_action('plugins_loaded', 'l2pay_init');

/**
 * Add the gateway to WooCommerce
 */
function l2pay_add_gateway($gateways) {
    $gateways[] = 'L2Pay_Gateway';
    return $gateways;
}
add_filter('woocommerce_payment_gateways', 'l2pay_add_gateway');

/**
 * Add settings link to plugins page
 */
function l2pay_plugin_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=l2pay') . '">' . __('Settings', 'l2pay') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . L2PAY_PLUGIN_BASENAME, 'l2pay_plugin_links');

/**
 * Enqueue scripts for checkout
 */
function l2pay_enqueue_scripts() {
    if (!is_checkout()) {
        return;
    }

    // Get gateway settings
    $gateway = new L2Pay_Gateway();

    if ($gateway->enabled !== 'yes') {
        return;
    }

    // Enqueue the checkout script
    wp_enqueue_script(
        'l2pay-checkout',
        L2PAY_PLUGIN_URL . 'assets/js/l2pay-checkout.js',
        array('jquery'),
        L2PAY_VERSION,
        true
    );

    // Enqueue styles
    wp_enqueue_style(
        'l2pay-styles',
        L2PAY_PLUGIN_URL . 'assets/css/l2pay.css',
        array(),
        L2PAY_VERSION
    );

    // Get available networks for checkout
    $available_networks = l2pay_get_available_networks();
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
    $network_mode = l2pay_get_network_mode();
    $is_test_mode = l2pay_is_test_mode();

    // Get cart total for fallback
    $cart_total = 0;
    if (WC()->cart) {
        $cart_total = WC()->cart->get_total('edit');
    }

    // Pass data to JavaScript
    wp_localize_script('l2pay-checkout', 'l2payData', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'restUrl' => rest_url('l2pay/v1/'),
        'nonce' => wp_create_nonce('l2pay-nonce'),
        'networks' => $networks_for_js,
        'defaultNetwork' => $is_test_mode ? 'base_sepolia' : 'base',
        'merchantAddress' => $gateway->get_option('merchant_address', ''),
        'currency' => get_woocommerce_currency(),
        'cartTotal' => $cart_total,
        'contractABI' => l2pay_get_contract_abi(),
        'erc20ABI' => l2pay_get_erc20_abi(),
        'networkMode' => $network_mode,
        'isTestMode' => $is_test_mode,
        'i18n' => array(
            'connectWallet' => __('Connect MetaMask', 'l2pay'),
            'payWithEth' => __('Pay with ETH', 'l2pay'),
            'payWithUsdc' => __('Pay with USDC', 'l2pay'),
            'processing' => __('Processing...', 'l2pay'),
            'approving' => __('Approving USDC...', 'l2pay'),
            'waitingConfirmation' => __('Waiting for confirmation...', 'l2pay'),
            'paymentComplete' => __('Payment complete!', 'l2pay'),
            'installMetamask' => __('Please install MetaMask to pay with crypto', 'l2pay'),
            'wrongNetwork' => __('Please switch network', 'l2pay'),
            'transactionFailed' => __('Transaction failed. Please try again.', 'l2pay'),
            'conversionError' => __('Error getting price. Please try again.', 'l2pay'),
            'selectNetwork' => __('Select Network', 'l2pay'),
            'selectPaymentMethod' => __('Payment Method', 'l2pay'),
            'merchantNotConfigured' => __('Merchant wallet not configured. Please contact store owner.', 'l2pay'),
            'insufficientUsdc' => __('Insufficient USDC balance', 'l2pay'),
            'approvalFailed' => __('USDC approval failed. Please try again.', 'l2pay'),
        ),
    ));
}
add_action('wp_enqueue_scripts', 'l2pay_enqueue_scripts');

/**
 * Get the contract ABI (pay and payWithToken functions)
 */
function l2pay_get_contract_abi() {
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
function l2pay_get_erc20_abi() {
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
function l2pay_get_tx_url($tx_hash, $network_key = 'sepolia') {
    $network = l2pay_get_network($network_key);
    if (!$network) {
        $network = l2pay_get_network('sepolia');
    }
    return $network['explorer'] . '/tx/' . $tx_hash;
}

/**
 * Get explorer URL for an address
 */
function l2pay_get_address_url($address, $network_key = 'sepolia') {
    $network = l2pay_get_network($network_key);
    if (!$network) {
        $network = l2pay_get_network('sepolia');
    }
    return $network['explorer'] . '/address/' . $address;
}

/**
 * AJAX handler to verify payment - DEPRECATED
 *
 * This endpoint is kept for backwards compatibility but now performs
 * on-chain verification before completing the order.
 *
 * The main verification happens in L2Pay_Gateway::process_payment()
 * which is called when the checkout form is submitted.
 */
function l2pay_verify_payment() {
    check_ajax_referer('l2pay-nonce', 'nonce');

    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $tx_hash = isset($_POST['tx_hash']) ? sanitize_text_field($_POST['tx_hash']) : '';
    $network_key = isset($_POST['network']) ? sanitize_text_field($_POST['network']) : 'sepolia';

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
    if ($order->get_meta('_l2pay_verified') === 'yes') {
        wp_send_json_success(array(
            'message' => 'Payment already verified',
            'redirect' => $order->get_checkout_order_received_url()
        ));
        return;
    }

    // SECURITY: Perform on-chain verification before completing
    $gateway = new L2Pay_Gateway();
    $network = l2pay_get_network($network_key);

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

    // All verifications passed - update order
    $explorer_url = l2pay_get_tx_url($tx_hash, $network_key);

    $order->update_meta_data('_l2pay_tx_hash', $tx_hash);
    $order->update_meta_data('_l2pay_network', $network_key);
    $order->update_meta_data('_l2pay_verified', 'yes');
    $order->update_meta_data('_l2pay_verified_at', time());
    $order->update_meta_data('_l2pay_payment_time', current_time('mysql'));

    $order->payment_complete($tx_hash);
    $order->add_order_note(
        sprintf(
            __('L2Pay payment verified on-chain (%s). Transaction: %s', 'l2pay'),
            $network['name'],
            '<a href="' . $explorer_url . '" target="_blank">' . substr($tx_hash, 0, 20) . '...</a>'
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
add_action('wp_ajax_l2pay_verify_payment', 'l2pay_verify_payment');
add_action('wp_ajax_nopriv_l2pay_verify_payment', 'l2pay_verify_payment');

/**
 * Display transaction hash on order details
 */
function l2pay_display_tx_hash($order) {
    $tx_hash = $order->get_meta('_l2pay_tx_hash');
    $network_key = $order->get_meta('_l2pay_network') ?: 'sepolia';
    $network = l2pay_get_network($network_key);

    if ($tx_hash) {
        $explorer_url = l2pay_get_tx_url($tx_hash, $network_key);
        echo '<p><strong>' . __('Transaction Hash:', 'l2pay') . '</strong><br>';
        echo '<a href="' . esc_url($explorer_url) . '" target="_blank">' . esc_html($tx_hash) . '</a>';
        if ($network) {
            echo '<br><small>(' . esc_html($network['name']) . ')</small>';
        }
        echo '</p>';
    }
}
add_action('woocommerce_order_details_after_order_table', 'l2pay_display_tx_hash');

/**
 * Display transaction hash in admin order page
 */
function l2pay_admin_order_data($order) {
    $tx_hash = $order->get_meta('_l2pay_tx_hash');
    $eth_amount = $order->get_meta('_l2pay_eth_amount');
    $network_key = $order->get_meta('_l2pay_network') ?: 'sepolia';
    $network = l2pay_get_network($network_key);

    if ($tx_hash || $eth_amount) {
        echo '<div class="order_data_column">';
        echo '<h4>' . __('L2Pay Payment', 'l2pay') . '</h4>';

        if ($network) {
            echo '<p><strong>' . __('Network:', 'l2pay') . '</strong> ' . esc_html($network['name']) . '</p>';
        }

        if ($eth_amount) {
            $symbol = $network ? $network['symbol'] : 'ETH';
            echo '<p><strong>' . __('Amount:', 'l2pay') . '</strong> ' . esc_html($eth_amount) . ' ' . esc_html($symbol) . '</p>';
        }

        if ($tx_hash) {
            $explorer_url = l2pay_get_tx_url($tx_hash, $network_key);
            echo '<p><strong>' . __('Transaction:', 'l2pay') . '</strong><br>';
            echo '<a href="' . esc_url($explorer_url) . '" target="_blank" style="word-break: break-all;">' . esc_html($tx_hash) . '</a></p>';
        }

        echo '</div>';
    }
}
add_action('woocommerce_admin_order_data_after_shipping_address', 'l2pay_admin_order_data');

/**
 * Declare HPOS compatibility
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
