<?php
/**
 * Layer Crypto Checkout Payment Gateway
 *
 * @package LayerCryptoCheckout
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * LCC Gateway Class
 */
class LCCP_Gateway extends WC_Payment_Gateway {

    /**
     * Merchant wallet address
     * @var string
     */
    public $merchant_address;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'layer-crypto-checkout';
        $this->icon = LCCP_PLUGIN_URL . 'assets/images/lccp-icon.svg';
        $this->has_fields = true;
        $this->method_title = __('Layer Crypto Checkout (ETH & USDC)', 'layer-crypto-checkout');
        $this->method_description = __('Accept ETH and USDC payments via MetaMask or WalletConnect. Payments are processed through a smart contract on multiple blockchains.', 'layer-crypto-checkout');
        $this->supports = array(
            'products',
            'refunds',
        );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->merchant_address = $this->get_option('merchant_address');

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'layer-crypto-checkout'),
                'type' => 'checkbox',
                'label' => __('Enable Layer Crypto Checkout Gateway', 'layer-crypto-checkout'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'layer-crypto-checkout'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'layer-crypto-checkout'),
                'default' => __('Pay with Crypto', 'layer-crypto-checkout'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'layer-crypto-checkout'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'layer-crypto-checkout'),
                'default' => __('Pay securely with ETH or USDC via MetaMask or WalletConnect.', 'layer-crypto-checkout'),
                'desc_tip' => true,
            ),
            'merchant_settings' => array(
                'title' => __('Merchant Settings', 'layer-crypto-checkout'),
                'type' => 'title',
                'description' => __('Configure your merchant wallet address.', 'layer-crypto-checkout'),
            ),
            'merchant_address' => array(
                'title' => __('Wallet Address', 'layer-crypto-checkout'),
                'type' => 'text',
                'description' => __('Your Ethereum wallet address where you will receive payments.', 'layer-crypto-checkout'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'pattern' => '^0x[a-fA-F0-9]{40}$',
                    'placeholder' => '0x...',
                    'required' => 'required',
                ),
            ),
            'advanced_settings' => array(
                'title' => __('Advanced Settings', 'layer-crypto-checkout'),
                'type' => 'title',
                'description' => __('Additional configuration options.', 'layer-crypto-checkout'),
            ),
            'price_margin' => array(
                'title' => __('Price Margin (%)', 'layer-crypto-checkout'),
                'type' => 'number',
                'description' => __('Add a margin to the ETH price to account for price volatility during transaction confirmation. Default: 2%', 'layer-crypto-checkout'),
                'default' => '2',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => '0',
                    'max' => '10',
                    'step' => '0.5',
                ),
            ),
            'price_cache_duration' => array(
                'title' => __('Price Cache Duration (seconds)', 'layer-crypto-checkout'),
                'type' => 'number',
                'description' => __('How long to cache the ETH price. Lower values = more accurate but more API calls. Default: 60 seconds', 'layer-crypto-checkout'),
                'default' => '60',
                'desc_tip' => true,
                'custom_attributes' => array(
                    'min' => '10',
                    'max' => '300',
                    'step' => '10',
                ),
            ),
            'debug_mode' => array(
                'title' => __('Debug Mode', 'layer-crypto-checkout'),
                'type' => 'checkbox',
                'label' => __('Enable debug logging', 'layer-crypto-checkout'),
                'default' => 'no',
                'description' => __('Log payment events and errors for debugging.', 'layer-crypto-checkout'),
            ),
            'network_settings' => array(
                'title' => __('Network Mode', 'layer-crypto-checkout'),
                'type' => 'title',
                'description' => __('Choose between test networks (for development) or live networks (for real payments).', 'layer-crypto-checkout'),
            ),
            'network_mode' => array(
                'title' => __('Mode', 'layer-crypto-checkout'),
                'type' => 'select',
                'description' => __('Test mode uses testnet networks (no real money). Live mode uses mainnet networks (real payments).', 'layer-crypto-checkout'),
                'default' => 'test',
                'desc_tip' => true,
                'options' => array(
                    'test' => __('Test Mode (Testnets)', 'layer-crypto-checkout'),
                    'live' => __('Live Mode (Mainnets)', 'layer-crypto-checkout'),
                ),
            ),
        );
    }

    /**
     * Admin Panel Options
     */
    public function admin_options() {
        $available_networks = lccp_get_available_networks();
        $is_live_mode = !lccp_is_test_mode();
        $mode_label = $is_live_mode ? __('Live Mode', 'layer-crypto-checkout') : __('Test Mode', 'layer-crypto-checkout');
        ?>
        <div class="lccp-admin-header" style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px;">
            <img src="<?php echo esc_url(LCCP_PLUGIN_URL . 'assets/images/lccp-icon.svg'); ?>" alt="Layer Crypto Checkout" style="width: 48px; height: 48px;">
            <div>
                <h2 style="margin: 0; padding: 0;"><?php esc_html_e('Layer Crypto Checkout', 'layer-crypto-checkout'); ?></h2>
                <span style="color: #646970; font-size: 13px;">Crypto Payments for WooCommerce</span>
            </div>
        </div>

        <?php if ($is_live_mode): ?>
        <div class="lccp-live-warning" style="background: #fcf0f0; border-left: 4px solid #d63638; padding: 12px 16px; margin: 20px 0; border-radius: 0 4px 4px 0;">
            <h4 style="margin: 0 0 8px 0; font-size: 14px; color: #d63638;"><?php esc_html_e('LIVE MODE - Real Payments Active', 'layer-crypto-checkout'); ?></h4>
            <p style="margin: 0; color: #1d2327;">
                <?php esc_html_e('You are accepting real cryptocurrency payments on mainnet networks. All transactions involve real money.', 'layer-crypto-checkout'); ?>
            </p>
        </div>
        <?php else: ?>
        <div class="lccp-test-notice" style="background: #fff8e5; border-left: 4px solid #dba617; padding: 12px 16px; margin: 20px 0; border-radius: 0 4px 4px 0;">
            <h4 style="margin: 0 0 8px 0; font-size: 14px; color: #9a6700;"><?php esc_html_e('TEST MODE - Using Testnets', 'layer-crypto-checkout'); ?></h4>
            <p style="margin: 0; color: #1d2327;">
                <?php esc_html_e('You are using testnet networks. No real money is involved. Switch to Live Mode when ready to accept real payments.', 'layer-crypto-checkout'); ?>
            </p>
        </div>
        <?php endif; ?>

        <div class="lccp-admin-notice" style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin: 20px 0; border-radius: 0 4px 4px 0;">
            <h4 style="margin: 0 0 8px 0; font-size: 14px;"><?php esc_html_e('Setup', 'layer-crypto-checkout'); ?></h4>
            <ol style="margin: 0; padding-left: 18px; color: #1d2327; line-height: 1.6;">
                <li><?php esc_html_e('Enter your wallet address below to receive payments', 'layer-crypto-checkout'); ?></li>
                <li><?php esc_html_e('Customers will choose their preferred network at checkout', 'layer-crypto-checkout'); ?></li>
                <li><?php esc_html_e('A 1% platform fee is applied to each transaction', 'layer-crypto-checkout'); ?></li>
            </ol>
        </div>

        <div class="lccp-security-info" style="background: #f0f9f0; border-left: 4px solid #00a32a; padding: 12px 16px; margin: 20px 0; border-radius: 0 4px 4px 0;">
            <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #00a32a;"><?php esc_html_e('Security & Reliability', 'layer-crypto-checkout'); ?></h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 12px; font-size: 13px; color: #1d2327;">
                <div>
                    <strong><?php esc_html_e('Non-Custodial', 'layer-crypto-checkout'); ?></strong><br>
                    <span style="color: #50575e;"><?php esc_html_e('Payments go directly to your wallet. We never hold your funds.', 'layer-crypto-checkout'); ?></span>
                </div>
                <div>
                    <strong><?php esc_html_e('Immutable Smart Contract', 'layer-crypto-checkout'); ?></strong><br>
                    <span style="color: #50575e;"><?php esc_html_e('Contract code cannot be changed after deployment. Your funds are safe.', 'layer-crypto-checkout'); ?></span>
                </div>
                <div>
                    <strong><?php esc_html_e('Reentrancy Protection', 'layer-crypto-checkout'); ?></strong><br>
                    <span style="color: #50575e;"><?php esc_html_e('Built-in guard against reentrancy attacks (OpenZeppelin standard).', 'layer-crypto-checkout'); ?></span>
                </div>
                <div>
                    <strong><?php esc_html_e('On-Chain Verification', 'layer-crypto-checkout'); ?></strong><br>
                    <span style="color: #50575e;"><?php esc_html_e('Every payment is verified on blockchain before order completion.', 'layer-crypto-checkout'); ?></span>
                </div>
                <div>
                    <strong><?php esc_html_e('Replay Attack Protection', 'layer-crypto-checkout'); ?></strong><br>
                    <span style="color: #50575e;"><?php esc_html_e('Each payment is unique and cannot be reused across chains.', 'layer-crypto-checkout'); ?></span>
                </div>
                <div>
                    <strong><?php esc_html_e('Open Source & Verified', 'layer-crypto-checkout'); ?></strong><br>
                    <span style="color: #50575e;"><?php esc_html_e('Contract source code is publicly verified on all block explorers.', 'layer-crypto-checkout'); ?></span>
                </div>
            </div>
            <p style="margin: 12px 0 0 0; padding-top: 10px; border-top: 1px solid #c3e6c3; font-size: 12px; color: #50575e;">
                <?php esc_html_e('Contract:', 'layer-crypto-checkout'); ?> <code style="background: #e7f5e7; padding: 2px 6px; border-radius: 3px;">0x84f679497947f9186258Af929De2e760677D5949</code>
                &nbsp;|&nbsp;
                <?php esc_html_e('Same address on all supported networks', 'layer-crypto-checkout'); ?>
            </p>
        </div>

        <div class="lccp-contract-info" style="background: #fff; border: 1px solid #c3c4c7; padding: 15px; margin: 20px 0; border-radius: 4px;">
            <h4 style="margin: 0 0 12px 0; font-size: 14px;">
                <?php echo $is_live_mode ? esc_html__('Available Mainnet Networks', 'layer-crypto-checkout') : esc_html__('Available Testnet Networks', 'layer-crypto-checkout'); ?>
            </h4>
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                <?php
                $networks_to_show = lccp_get_available_networks();
                if (empty($networks_to_show)):
                ?>
                    <p style="color: #9a6700; margin: 0;">
                        <?php echo $is_live_mode
                            ? esc_html__('No mainnet contracts deployed yet. Deploy contracts to mainnet networks first.', 'layer-crypto-checkout')
                            : esc_html__('No testnet contracts deployed yet.', 'layer-crypto-checkout');
                        ?>
                    </p>
                <?php else: ?>
                    <?php foreach ($networks_to_show as $key => $network): ?>
                    <a href="<?php echo esc_url($network['explorer'] . '/address/' . $network['contract']); ?>"
                       target="_blank"
                       style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: <?php echo $network['is_testnet'] ? '#fff8e5' : '#e7f5e7'; ?>; border: 1px solid <?php echo $network['is_testnet'] ? '#f0c33c' : '#68b368'; ?>; border-radius: 4px; text-decoration: none; color: #1d2327; font-size: 13px;">
                        <span style="color: <?php echo $network['is_testnet'] ? '#9a6700' : '#00a32a'; ?>;">&#9679;</span>
                        <?php echo esc_html($network['name']); ?>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }

    /**
     * Check if the gateway is available
     */
    public function is_available() {
        if ($this->enabled !== 'yes') {
            return false;
        }

        // Check if merchant address is set
        if (empty($this->merchant_address)) {
            return false;
        }

        return true;
    }

    /**
     * Payment fields displayed at checkout
     */
    public function payment_fields() {
        // Display description
        if ($this->description) {
            echo wp_kses_post(wpautop(wptexturize($this->description)));
        }

        // Get cart total
        $total = WC()->cart->get_total('edit');
        $available_networks = lccp_get_available_networks();
        $is_test_mode = lccp_is_test_mode();

        ?>
        <?php if ($is_test_mode): ?>
        <div class="lccp-mode-badge test" style="background: #fff8e5; border: 1px solid #dba617; padding: 8px 12px; margin-bottom: 15px; border-radius: 4px; display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 16px;">&#129514;</span>
            <span style="color: #9a6700; font-weight: 500; font-size: 13px;"><?php esc_html_e('Test Mode - No real money', 'layer-crypto-checkout'); ?></span>
        </div>
        <?php endif; ?>
        <div id="lccp-payment-container">
            <div class="lccp-selectors">
                <div class="lccp-payment-type-selector">
                    <label><?php esc_html_e('Pay with', 'layer-crypto-checkout'); ?></label>
                    <div class="lccp-payment-options">
                        <label class="lccp-option">
                            <input type="radio" name="lccp_payment_type" value="eth" checked>
                            <span>ETH</span>
                        </label>
                        <label class="lccp-option">
                            <input type="radio" name="lccp_payment_type" value="usdc">
                            <span>USDC</span>
                        </label>
                    </div>
                </div>

                <div class="lccp-network-selector">
                    <label for="lcc-network"><?php esc_html_e('Network', 'layer-crypto-checkout'); ?></label>
                    <select id="lccp-network" name="lccp_network">
                        <?php foreach ($available_networks as $key => $network): ?>
                            <option value="<?php echo esc_attr($key); ?>"
                                    data-chain-id="<?php echo esc_attr($network['chain_id']); ?>"
                                    data-symbol="<?php echo esc_attr($network['symbol']); ?>"
                                    data-usdc="<?php echo esc_attr(isset($network['usdc_address']) ? $network['usdc_address'] : ''); ?>">
                                <?php echo esc_html($network['name']); ?><?php if ($network['is_testnet']): ?> (Testnet)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="lccp-wallet-status" class="lccp-status disconnected">
                <span class="lccp-status-icon">&#128274;</span>
                <span class="lccp-status-text"><?php esc_html_e('Wallet not connected', 'layer-crypto-checkout'); ?></span>
                <button type="button" id="lccp-disconnect-btn" style="display:none; margin-left:10px; padding:2px 8px; font-size:12px; cursor:pointer; border:1px solid #ccc; border-radius:4px; background:#f5f5f5;">Disconnect</button>
            </div>

            <div id="lccp-price-display" class="lccp-price" style="display: none;">
                <div class="lccp-price-row">
                    <span class="lccp-label"><?php esc_html_e('Order Total:', 'layer-crypto-checkout'); ?></span>
                    <span class="lccp-value"><?php echo wp_kses_post(wc_price($total)); ?></span>
                </div>
                <div class="lccp-price-row">
                    <span class="lccp-label" id="lccp-amount-label"><?php esc_html_e('Amount:', 'layer-crypto-checkout'); ?></span>
                    <span class="lccp-value" id="lccp-crypto-amount">--</span>
                </div>
                <div class="lccp-price-row lcc-rate">
                    <span class="lccp-label"><?php esc_html_e('Rate:', 'layer-crypto-checkout'); ?></span>
                    <span class="lccp-value" id="lccp-rate">--</span>
                </div>
            </div>

            <button type="button" id="lccp-connect-btn" class="button lcc-btn">
                <span class="lccp-btn-icon">&#129418;</span>
                <?php esc_html_e('Connect Wallet', 'layer-crypto-checkout'); ?>
            </button>

            <div id="lccp-error" class="lccp-error" style="display: none;"></div>

            <input type="hidden" id="lccp-tx-hash" name="lccp_tx_hash" value="">
            <input type="hidden" id="lccp-eth-paid" name="lccp_eth_amount" value="">
            <input type="hidden" id="lccp-wallet-address" name="lccp_wallet_address" value="">
            <input type="hidden" id="lccp-payment-type" name="lccp_payment_type_hidden" value="eth">
        </div>
        <?php
    }

    /**
     * Validate payment fields
     */
    public function validate_fields() {
        // Nonce verification is handled by WooCommerce checkout process.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification
        $tx_hash = isset($_POST['lccp_tx_hash']) ? sanitize_text_field(wp_unslash($_POST['lccp_tx_hash'])) : '';

        if (empty($tx_hash)) {
            wc_add_notice(__('Please complete the crypto payment before placing the order.', 'layer-crypto-checkout'), 'error');
            return false;
        }

        // Validate transaction hash format
        if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $tx_hash)) {
            wc_add_notice(__('Invalid transaction hash. Please try again.', 'layer-crypto-checkout'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Process the payment with on-chain verification
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        // Nonce verification is handled by WooCommerce checkout process.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification
        $tx_hash = isset($_POST['lccp_tx_hash']) ? sanitize_text_field(wp_unslash($_POST['lccp_tx_hash'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $crypto_amount = isset($_POST['lccp_eth_amount']) ? sanitize_text_field(wp_unslash($_POST['lccp_eth_amount'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $wallet_address = isset($_POST['lccp_wallet_address']) ? sanitize_text_field(wp_unslash($_POST['lccp_wallet_address'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $network_key = isset($_POST['lccp_network']) ? sanitize_text_field(wp_unslash($_POST['lccp_network'])) : 'sepolia';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $payment_type = isset($_POST['lccp_payment_type']) ? sanitize_text_field(wp_unslash($_POST['lccp_payment_type'])) : 'eth';

        // Get network info
        $network = lccp_get_network($network_key);
        $explorer_url = lccp_get_tx_url($tx_hash, $network_key);

        // Determine symbol based on payment type
        $symbol = ($payment_type === 'usdc') ? 'USDC' : ($network ? $network['symbol'] : 'ETH');
        $network_name = $network ? $network['name'] : $network_key;

        // Save payment metadata first
        $order->update_meta_data('_lccp_tx_hash', $tx_hash);
        $order->update_meta_data('_lccp_crypto_amount', $crypto_amount);
        $order->update_meta_data('_lccp_payment_type', $payment_type);
        $order->update_meta_data('_lccp_symbol', $symbol);
        $order->update_meta_data('_lccp_payer_address', $wallet_address);
        $order->update_meta_data('_lccp_network', $network_key);
        $order->update_meta_data('_lccp_contract', $network ? $network['contract'] : '');
        $order->save();

        // Perform on-chain verification
        $verification_result = $this->verify_transaction_onchain($tx_hash, $network_key, $order);

        if (is_wp_error($verification_result)) {
            // Verification failed - mark order as failed
            $order->update_status('failed', sprintf(
                /* translators: %s: error message */
                __('Layer Crypto Checkout: On-chain verification failed: %s', 'layer-crypto-checkout'),
                $verification_result->get_error_message()
            ));
            $order->save();

            wc_add_notice(
                __('Payment verification failed: ', 'layer-crypto-checkout') . $verification_result->get_error_message(),
                'error'
            );

            // Log the error
            if ($this->get_option('debug_mode') === 'yes') {
                $this->log('Verification failed for TX ' . $tx_hash . ': ' . $verification_result->get_error_message());
            }

            return array(
                'result' => 'failure',
                'messages' => $verification_result->get_error_message(),
            );
        }

        // Verification passed - complete the order
        $order->update_meta_data('_lccp_verified', 'yes');
        $order->update_meta_data('_lccp_verified_at', time());

        if (isset($verification_result['payer'])) {
            $order->update_meta_data('_lccp_verified_payer', $verification_result['payer']);
        }
        if (isset($verification_result['amount'])) {
            $order->update_meta_data('_lccp_verified_amount', $verification_result['amount']);
        }
        if (isset($verification_result['block_number'])) {
            $order->update_meta_data('_lccp_block_number', $verification_result['block_number']);
        }

        // Mark as processing
        $order->payment_complete($tx_hash);

        // Add order note with verification details
        $order->add_order_note(
            sprintf(
                /* translators: 1: network name, 2: crypto amount, 3: currency symbol, 4: transaction link */
                __('Layer Crypto Checkout payment verified on-chain (%1$s). Amount: %2$s %3$s. TX: %4$s', 'layer-crypto-checkout'),
                $network_name,
                $crypto_amount,
                $symbol,
                '<a href="' . esc_url($explorer_url) . '" target="_blank">' . esc_html(substr($tx_hash, 0, 20)) . '...</a>'
            )
        );

        $order->save();

        // Empty cart
        WC()->cart->empty_cart();

        // Log if debug mode
        if ($this->get_option('debug_mode') === 'yes') {
            $this->log('Payment verified and processed for order #' . $order_id . ' on ' . $network_name . ' with ' . $symbol . '. TX: ' . $tx_hash);
        }

        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }

    /**
     * Verify transaction on-chain
     *
     * @param string $tx_hash Transaction hash
     * @param string $network_key Network identifier
     * @param WC_Order $order Order object
     * @return array|WP_Error Verification result or error
     */
    private function verify_transaction_onchain($tx_hash, $network_key, $order) {
        // Get RPC URL for the network
        $rpc_url = $this->get_rpc_url($network_key);
        if (!$rpc_url) {
            return new WP_Error('invalid_network', __('Invalid network configuration', 'layer-crypto-checkout'));
        }

        // Get network config
        $network = lccp_get_network($network_key);
        $contract_address = $network ? strtolower($network['contract']) : '';
        $merchant_address = strtolower($this->merchant_address);

        if (empty($contract_address)) {
            return new WP_Error('no_contract', __('Contract not configured for this network', 'layer-crypto-checkout'));
        }

        // Retry logic: transaction might not be propagated to all nodes immediately
        // Testnets and L2s can take 10-30 seconds for confirmation
        $max_retries = 10;
        $retry_delay = 3; // seconds
        $receipt = null;

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            // Call eth_getTransactionReceipt
            $receipt = $this->rpc_call($rpc_url, 'eth_getTransactionReceipt', array($tx_hash));

            if (is_wp_error($receipt)) {
                if ($attempt < $max_retries) {
                    sleep($retry_delay);
                    continue;
                }
                return $receipt;
            }

            if ($receipt !== null) {
                break; // Receipt found
            }

            if ($attempt < $max_retries) {
                sleep($retry_delay);
            }
        }

        if ($receipt === null) {
            return new WP_Error('tx_pending', __('Transaction not yet confirmed. Please wait a moment and try again.', 'layer-crypto-checkout'));
        }

        // Check transaction status
        $status = isset($receipt['status']) ? $receipt['status'] : '0x0';
        if ($this->hex_to_dec($status) !== '1') {
            return new WP_Error('tx_failed', __('Transaction failed on blockchain', 'layer-crypto-checkout'));
        }

        // Verify transaction was sent to our contract
        $tx_to = strtolower($receipt['to'] ?? '');
        if ($tx_to !== $contract_address) {
            return new WP_Error('wrong_contract', __('Transaction sent to wrong contract address', 'layer-crypto-checkout'));
        }

        // Parse event logs to verify payment
        $payment_data = $this->parse_payment_logs($receipt['logs'] ?? array());

        if (!$payment_data) {
            return new WP_Error('no_event', __('No valid payment event found in transaction', 'layer-crypto-checkout'));
        }

        // Verify merchant address
        if (strtolower($payment_data['merchant']) !== $merchant_address) {
            return new WP_Error('wrong_merchant', __('Payment sent to wrong merchant address', 'layer-crypto-checkout'));
        }

        // All checks passed
        return array(
            'verified' => true,
            'payer' => $payment_data['payer'],
            'merchant' => $payment_data['merchant'],
            'amount' => $payment_data['amount'],
            'payment_type' => $payment_data['type'],
            'block_number' => $this->hex_to_dec($receipt['blockNumber']),
        );
    }

    /**
     * Get RPC URL for a network
     */
    private function get_rpc_url($network_key) {
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
        return $rpc_urls[$network_key] ?? null;
    }

    /**
     * Make JSON-RPC call
     */
    private function rpc_call($rpc_url, $method, $params = array()) {
        $body = json_encode(array(
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ));

        $response = wp_remote_post($rpc_url, array(
            'timeout' => 30,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => $body,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('rpc_error', 'Failed to connect to blockchain: ' . $response->get_error_message());
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($result['error'])) {
            return new WP_Error('rpc_error', $result['error']['message'] ?? 'RPC error');
        }

        return $result['result'] ?? null;
    }

    /**
     * Parse payment logs from transaction receipt
     */
    private function parse_payment_logs($logs) {
        // Event topic hashes
        $eth_payment_topic = '0x4aa351061f13d3dff9e0f6cab4811de6a51a2f94e424b21ce31914f1e99c17bc';
        $token_payment_topic = '0x0a7e11d6b5194b35bf3d4e463e2cb08dd9681b79fe6d4a1ff9725977a7da38d7';

        foreach ($logs as $log) {
            if (empty($log['topics'])) {
                continue;
            }

            $event_topic = $log['topics'][0];

            if ($event_topic === $eth_payment_topic && count($log['topics']) >= 3) {
                // PaymentReceived event
                $data = substr($log['data'], 2);
                $chunks = str_split($data, 64);

                return array(
                    'type' => 'eth',
                    'payer' => $this->decode_address($log['topics'][1]),
                    'merchant' => $this->decode_address($log['topics'][2]),
                    'order_id' => $this->hex_to_dec($chunks[0] ?? '0'),
                    'amount' => $this->hex_to_dec($chunks[1] ?? '0'),
                );
            }

            if ($event_topic === $token_payment_topic && count($log['topics']) >= 4) {
                // TokenPaymentReceived event
                $data = substr($log['data'], 2);
                $chunks = str_split($data, 64);

                return array(
                    'type' => 'token',
                    'payer' => $this->decode_address($log['topics'][1]),
                    'merchant' => $this->decode_address($log['topics'][2]),
                    'token' => $this->decode_address($log['topics'][3]),
                    'order_id' => $this->hex_to_dec($chunks[0] ?? '0'),
                    'amount' => $this->hex_to_dec($chunks[1] ?? '0'),
                );
            }
        }

        return null;
    }

    /**
     * Decode address from 32-byte padded hex
     */
    private function decode_address($hex) {
        if (strpos($hex, '0x') === 0) {
            $hex = substr($hex, 2);
        }
        return '0x' . substr($hex, -40);
    }

    /**
     * Convert hex to decimal string
     */
    private function hex_to_dec($hex) {
        if (strpos($hex, '0x') === 0) {
            $hex = substr($hex, 2);
        }
        if (empty($hex)) {
            return '0';
        }
        // Use bcmath for large numbers
        $dec = '0';
        for ($i = 0; $i < strlen($hex); $i++) {
            $dec = bcmul($dec, '16');
            $dec = bcadd($dec, hexdec($hex[$i]));
        }
        return $dec;
    }

    /**
     * Thank you page
     */
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        $tx_hash = $order->get_meta('_lccp_tx_hash');
        $crypto_amount = $order->get_meta('_lccp_crypto_amount') ?: $order->get_meta('_lccp_eth_amount');
        $symbol = $order->get_meta('_lccp_symbol');
        $network_key = $order->get_meta('_lccp_network') ?: 'sepolia';
        $network = lccp_get_network($network_key);

        if ($tx_hash) {
            $explorer_url = lccp_get_tx_url($tx_hash, $network_key);
            if (!$symbol) {
                $symbol = $network ? $network['symbol'] : 'ETH';
            }
            $network_name = $network ? $network['name'] : $network_key;
            ?>
            <div class="lccp-thankyou">
                <h2><?php esc_html_e('Payment Details', 'layer-crypto-checkout'); ?></h2>
                <ul class="woocommerce-order-overview">
                    <li>
                        <strong><?php esc_html_e('Amount:', 'layer-crypto-checkout'); ?></strong>
                        <?php echo esc_html($crypto_amount); ?> <?php echo esc_html($symbol); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Transaction Hash:', 'layer-crypto-checkout'); ?></strong>
                        <a href="<?php echo esc_url($explorer_url); ?>" target="_blank">
                            <?php echo esc_html(substr($tx_hash, 0, 20)); ?>...
                        </a>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Network:', 'layer-crypto-checkout'); ?></strong>
                        <?php echo esc_html($network_name); ?>
                    </li>
                </ul>
            </div>
            <?php
        }
    }

    /**
     * Add payment info to emails
     */
    public function email_instructions($order, $sent_to_admin, $plain_text = false) {
        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        $tx_hash = $order->get_meta('_lccp_tx_hash');
        $crypto_amount = $order->get_meta('_lccp_crypto_amount') ?: $order->get_meta('_lccp_eth_amount');
        $symbol = $order->get_meta('_lccp_symbol');
        $network_key = $order->get_meta('_lccp_network') ?: 'sepolia';
        $network = lccp_get_network($network_key);

        if ($tx_hash) {
            $explorer_url = lccp_get_tx_url($tx_hash, $network_key);
            if (!$symbol) {
                $symbol = $network ? $network['symbol'] : 'ETH';
            }
            $network_name = $network ? $network['name'] : $network_key;

            if ($plain_text) {
                echo "\n" . esc_html__('Payment Details', 'layer-crypto-checkout') . "\n";
                echo esc_html__('Network:', 'layer-crypto-checkout') . ' ' . esc_html($network_name) . "\n";
                echo esc_html__('Amount:', 'layer-crypto-checkout') . ' ' . esc_html($crypto_amount) . ' ' . esc_html($symbol) . "\n";
                echo esc_html__('Transaction:', 'layer-crypto-checkout') . ' ' . esc_url($explorer_url) . "\n\n";
            } else {
                ?>
                <h2><?php esc_html_e('Payment Details', 'layer-crypto-checkout'); ?></h2>
                <p>
                    <strong><?php esc_html_e('Network:', 'layer-crypto-checkout'); ?></strong> <?php echo esc_html($network_name); ?><br>
                    <strong><?php esc_html_e('Amount:', 'layer-crypto-checkout'); ?></strong> <?php echo esc_html($crypto_amount); ?> <?php echo esc_html($symbol); ?><br>
                    <strong><?php esc_html_e('Transaction:', 'layer-crypto-checkout'); ?></strong>
                    <a href="<?php echo esc_url($explorer_url); ?>"><?php echo esc_html(substr($tx_hash, 0, 30)); ?>...</a>
                </p>
                <?php
            }
        }
    }

    /**
     * Process refund
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);

        // Add note that refund must be done manually on blockchain
        $order->add_order_note(
            sprintf(
                /* translators: %s: refund amount */
                __('Refund requested for %s. Note: Crypto refunds must be processed manually by sending ETH back to the customer wallet.', 'layer-crypto-checkout'),
                wp_kses_post(wc_price($amount))
            )
        );

        return true;
    }

    /**
     * Log messages
     */
    private function log($message) {
        if (class_exists('WC_Logger')) {
            $logger = wc_get_logger();
            $logger->info($message, array('source' => 'layer-crypto-checkout'));
        }
    }
}
