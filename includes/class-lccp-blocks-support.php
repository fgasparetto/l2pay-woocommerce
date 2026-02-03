<?php
/**
 * WooCommerce Blocks integration for Layer Crypto Checkout.
 *
 * @package LayerCryptoCheckout
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * LCCP_Blocks_Support class.
 *
 * Registers the Layer Crypto Checkout payment method with WooCommerce Block Checkout.
 */
final class LCCP_Blocks_Support extends AbstractPaymentMethodType {

    /**
     * Payment method name. Must match the gateway ID.
     *
     * @var string
     */
    protected $name = 'layer-crypto-checkout';

    /**
     * Gateway instance.
     *
     * @var LCCP_Gateway
     */
    private $gateway;

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_layer-crypto-checkout_settings', array());
        $gateways       = WC()->payment_gateways->payment_gateways();
        $this->gateway  = isset($gateways[ $this->name ]) ? $gateways[ $this->name ] : null;
    }

    /**
     * Returns if this payment method should be active.
     *
     * @return boolean
     */
    public function is_active() {
        if ( ! $this->gateway ) {
            return false;
        }
        return $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $asset_path = LCCP_PLUGIN_DIR . 'assets/js/lccp-blocks.asset.php';
        $asset      = file_exists( $asset_path ) ? require $asset_path : array(
            'dependencies' => array(),
            'version'      => LCCP_VERSION,
        );

        // Register walletconnect provider (same as classic checkout).
        wp_register_script(
            'walletconnect-provider-blocks',
            LCCP_PLUGIN_URL . 'assets/js/vendor/walletconnect-provider.min.js',
            array(),
            '2.17.0',
            false
        );

        wp_add_inline_script(
            'walletconnect-provider-blocks',
            'if(typeof process==="undefined"){window.process={env:{},browser:true};}if(typeof global==="undefined"){window.global=window;}',
            'before'
        );

        // Inject WalletConnect config.
        $is_test_mode = lccp_is_test_mode();
        $chain_ids    = $is_test_mode
            ? '[11155111,84532,11155420,421614]'
            : '[1,8453,10,42161]';
        $default_chain = $is_test_mode ? '11155111' : '1';

        wp_add_inline_script(
            'walletconnect-provider-blocks',
            sprintf(
                'window.LCCPWalletConfig={projectId:"%s",chainIds:%s,isTestMode:%s,defaultChainId:%s};',
                esc_js( 'bde3cb22b79eeda9c1dcfce0e9e4cdbd' ),
                $chain_ids,
                $is_test_mode ? 'true' : 'false',
                $default_chain
            ),
            'after'
        );

        wp_enqueue_script( 'walletconnect-provider-blocks' );

        // Register the blocks integration script.
        wp_register_script(
            'lccp-blocks',
            LCCP_PLUGIN_URL . 'assets/js/lccp-blocks.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        // Localize data for the blocks script.
        $available_networks = lccp_get_available_networks();
        $networks_for_js    = array();
        foreach ( $available_networks as $key => $network ) {
            $networks_for_js[ $key ] = array(
                'name'         => $network['name'],
                'chainId'      => $network['chain_id'],
                'chainIdDec'   => $network['chain_id_dec'],
                'contract'     => $network['contract'],
                'rpcUrl'       => $network['rpc_url'],
                'explorer'     => $network['explorer'],
                'symbol'       => $network['symbol'],
                'isTestnet'    => $network['is_testnet'],
                'usdcAddress'  => isset( $network['usdc_address'] ) ? $network['usdc_address'] : '',
                'usdcDecimals' => isset( $network['usdc_decimals'] ) ? $network['usdc_decimals'] : 6,
            );
        }

        $network_mode = lccp_get_network_mode();

        $cart_total = 0;
        if ( WC()->cart ) {
            $cart_total = WC()->cart->get_total( 'edit' );
        }

        wp_localize_script( 'lccp-blocks', 'lccpBlocksData', array(
            'pluginUrl'              => LCCP_PLUGIN_URL,
            'restUrl'                => rest_url( 'lccp/v1/' ),
            'nonce'                  => wp_create_nonce( 'lccp-nonce' ),
            'networks'               => $networks_for_js,
            'defaultNetwork'         => $is_test_mode ? 'base_sepolia' : 'base',
            'merchantAddress'        => $this->gateway->get_option( 'merchant_address', '' ),
            'currency'               => get_woocommerce_currency(),
            'cartTotal'              => $cart_total,
            'networkMode'            => $network_mode,
            'isTestMode'             => $is_test_mode,
            'walletConnectProjectId' => 'bde3cb22b79eeda9c1dcfce0e9e4cdbd',
            'i18n'                   => array(
                'connectWallet'       => __( 'Connect Wallet', 'layer-crypto-checkout' ),
                'payWithEth'          => __( 'Pay with ETH', 'layer-crypto-checkout' ),
                'payWithUsdc'         => __( 'Pay with USDC', 'layer-crypto-checkout' ),
                'processing'          => __( 'Processing...', 'layer-crypto-checkout' ),
                'approving'           => __( 'Approving USDC...', 'layer-crypto-checkout' ),
                'waitingConfirmation' => __( 'Waiting for confirmation...', 'layer-crypto-checkout' ),
                'paymentComplete'     => __( 'Payment complete!', 'layer-crypto-checkout' ),
                'noWalletFound'       => __( 'No wallet found. Please install MetaMask or use WalletConnect.', 'layer-crypto-checkout' ),
                'wrongNetwork'        => __( 'Please switch network', 'layer-crypto-checkout' ),
                'transactionFailed'   => __( 'Transaction failed. Please try again.', 'layer-crypto-checkout' ),
                'conversionError'     => __( 'Error getting price. Please try again.', 'layer-crypto-checkout' ),
                'selectNetwork'       => __( 'Select Network', 'layer-crypto-checkout' ),
                'selectPaymentMethod' => __( 'Payment Method', 'layer-crypto-checkout' ),
                'merchantNotConfigured' => __( 'Merchant wallet not configured. Please contact store owner.', 'layer-crypto-checkout' ),
                'insufficientUsdc'    => __( 'Insufficient USDC balance', 'layer-crypto-checkout' ),
                'approvalFailed'      => __( 'USDC approval failed. Please try again.', 'layer-crypto-checkout' ),
                'connecting'          => __( 'Connecting...', 'layer-crypto-checkout' ),
                'disconnected'        => __( 'Wallet disconnected', 'layer-crypto-checkout' ),
            ),
        ) );

        // Enqueue the CSS.
        wp_enqueue_style(
            'lccp-blocks-styles',
            LCCP_PLUGIN_URL . 'assets/css/lccp.css',
            array(),
            LCCP_VERSION
        );

        return array( 'lccp-blocks' );
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return array(
            'title'       => $this->gateway ? $this->gateway->get_title() : __( 'Pay with Crypto', 'layer-crypto-checkout' ),
            'description' => $this->gateway ? $this->gateway->get_description() : '',
            'supports'    => $this->gateway ? array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ) : array( 'products' ),
            'iconUrl'     => LCCP_PLUGIN_URL . 'assets/images/lccp-icon.svg',
        );
    }
}
