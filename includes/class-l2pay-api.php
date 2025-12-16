<?php
/**
 * L2Pay REST API
 *
 * Handles price conversion and payment verification endpoints
 *
 * @package L2Pay
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * L2Pay API Class
 */
class L2Pay_API {

    /**
     * Cache key for ETH price
     */
    const PRICE_CACHE_KEY = 'l2pay_eth_price';

    /**
     * Cache key for EUR/USD rate
     */
    const EUR_USD_CACHE_KEY = 'l2pay_eur_usd_rate';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Get ETH price
        register_rest_route('l2pay/v1', '/price', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_eth_price'),
            'permission_callback' => '__return_true',
            'args' => array(
                'currency' => array(
                    'default' => 'eur',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // Convert amount to ETH
        register_rest_route('l2pay/v1', '/convert', array(
            'methods' => 'GET',
            'callback' => array($this, 'convert_to_eth'),
            'permission_callback' => '__return_true',
            'args' => array(
                'amount' => array(
                    'required' => true,
                    'sanitize_callback' => array($this, 'sanitize_float'),
                ),
                'currency' => array(
                    'default' => 'eur',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // Convert amount to USDC
        register_rest_route('l2pay/v1', '/convert-usdc', array(
            'methods' => 'GET',
            'callback' => array($this, 'convert_to_usdc'),
            'permission_callback' => '__return_true',
            'args' => array(
                'amount' => array(
                    'required' => true,
                    'sanitize_callback' => array($this, 'sanitize_float'),
                ),
                'currency' => array(
                    'default' => 'eur',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // Verify transaction
        register_rest_route('l2pay/v1', '/verify', array(
            'methods' => 'POST',
            'callback' => array($this, 'verify_transaction'),
            'permission_callback' => array($this, 'verify_nonce'),
            'args' => array(
                'tx_hash' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'order_id' => array(
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        // Create pending order
        register_rest_route('l2pay/v1', '/create-order', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_pending_order'),
            'permission_callback' => array($this, 'verify_nonce'),
        ));
    }

    /**
     * Sanitize float value
     */
    public function sanitize_float($value) {
        return floatval($value);
    }

    /**
     * Verify nonce for protected endpoints
     */
    public function verify_nonce($request) {
        $nonce = $request->get_header('X-WP-Nonce');

        if (!$nonce) {
            $nonce = $request->get_param('nonce');
        }

        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('invalid_nonce', 'Invalid security token', array('status' => 403));
        }

        return true;
    }

    /**
     * Get ETH price from CoinGecko
     */
    public function get_eth_price($request) {
        $currency = strtolower($request->get_param('currency'));

        // Supported currencies
        $supported = array('eur', 'usd', 'gbp', 'chf', 'cad', 'aud', 'jpy');

        if (!in_array($currency, $supported)) {
            $currency = 'eur';
        }

        // Check cache
        $cache_key = self::PRICE_CACHE_KEY . '_' . $currency;
        $cached_price = get_transient($cache_key);

        if ($cached_price !== false) {
            return rest_ensure_response(array(
                'success' => true,
                'price' => floatval($cached_price),
                'currency' => strtoupper($currency),
                'cached' => true,
                'timestamp' => time(),
            ));
        }

        // Fetch from CoinGecko
        $price_data = $this->fetch_eth_price($currency);

        if (is_wp_error($price_data)) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => $price_data->get_error_message(),
            ));
        }

        // Get cache duration from settings
        $gateway = new L2Pay_Gateway();
        $cache_duration = intval($gateway->get_option('price_cache_duration', 60));

        // Cache the price
        set_transient($cache_key, $price_data['price'], $cache_duration);

        return rest_ensure_response(array(
            'success' => true,
            'price' => $price_data['price'],
            'currency' => strtoupper($currency),
            'cached' => false,
            'timestamp' => time(),
        ));
    }

    /**
     * Convert fiat amount to ETH
     */
    public function convert_to_eth($request) {
        $amount = floatval($request->get_param('amount'));
        $currency = strtolower($request->get_param('currency'));

        if ($amount <= 0) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Amount must be greater than 0',
            ));
        }

        // Get ETH price
        $price_request = new WP_REST_Request('GET', '/l2pay/v1/price');
        $price_request->set_param('currency', $currency);
        $price_response = $this->get_eth_price($price_request);
        $price_data = $price_response->get_data();

        if (!$price_data['success']) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => $price_data['error'] ?? 'Failed to get ETH price',
            ));
        }

        $eth_price = $price_data['price'];

        // Get price margin from settings
        $gateway = new L2Pay_Gateway();
        $margin = floatval($gateway->get_option('price_margin', 2));

        // Calculate ETH amount
        $eth_amount = $amount / $eth_price;

        // Apply margin (increase ETH amount to account for volatility)
        $eth_with_margin = $eth_amount * (1 + ($margin / 100));

        // Round to 8 decimal places (standard for crypto)
        $eth_with_margin = round($eth_with_margin, 8);

        // Convert to Wei (1 ETH = 10^18 Wei)
        $wei_amount = bcmul(number_format($eth_with_margin, 18, '.', ''), '1000000000000000000', 0);

        return rest_ensure_response(array(
            'success' => true,
            'fiat_amount' => $amount,
            'fiat_currency' => strtoupper($currency),
            'eth_price' => $eth_price,
            'eth_amount' => $eth_with_margin,
            'eth_amount_raw' => $eth_amount,
            'wei_amount' => $wei_amount,
            'margin_percent' => $margin,
            'timestamp' => time(),
            'valid_for' => intval($gateway->get_option('price_cache_duration', 60)),
        ));
    }

    /**
     * Convert fiat amount to USDC
     * USDC is pegged to USD, so we need EUR/USD rate for EUR amounts
     */
    public function convert_to_usdc($request) {
        $amount = floatval($request->get_param('amount'));
        $currency = strtolower($request->get_param('currency'));

        if ($amount <= 0) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Amount must be greater than 0',
            ));
        }

        // USDC has 6 decimals
        $usdc_decimals = 6;

        // If currency is already USD, amount equals USDC
        if ($currency === 'usd') {
            $usd_amount = $amount;
            $exchange_rate = 1.0;
        } else {
            // Get exchange rate to USD
            $rate_data = $this->get_fiat_to_usd_rate($currency);

            if (is_wp_error($rate_data)) {
                return rest_ensure_response(array(
                    'success' => false,
                    'error' => $rate_data->get_error_message(),
                ));
            }

            $exchange_rate = $rate_data['rate'];
            $usd_amount = $amount * $exchange_rate;
        }

        // Get price margin from settings
        $gateway = new L2Pay_Gateway();
        $margin = floatval($gateway->get_option('price_margin', 2));

        // Apply margin (increase USDC amount to account for any slippage)
        $usdc_with_margin = $usd_amount * (1 + ($margin / 100));

        // Round to 2 decimal places (USDC is a stablecoin, no need for more precision)
        $usdc_with_margin = round($usdc_with_margin, 2);

        // Convert to smallest unit (6 decimals for USDC)
        // Use bcmul for precision
        $usdc_smallest_unit = bcmul(number_format($usdc_with_margin, 6, '.', ''), '1000000', 0);

        return rest_ensure_response(array(
            'success' => true,
            'fiat_amount' => $amount,
            'fiat_currency' => strtoupper($currency),
            'exchange_rate' => $exchange_rate,
            'usd_amount' => $usd_amount,
            'usdc_amount' => $usdc_with_margin,
            'usdc_amount_raw' => $usd_amount,
            'usdc_smallest_unit' => $usdc_smallest_unit,
            'usdc_decimals' => $usdc_decimals,
            'margin_percent' => $margin,
            'timestamp' => time(),
            'valid_for' => intval($gateway->get_option('price_cache_duration', 60)),
        ));
    }

    /**
     * Get exchange rate from any fiat currency to USD
     */
    private function get_fiat_to_usd_rate($currency = 'eur') {
        $currency = strtolower($currency);
        $cache_key = self::EUR_USD_CACHE_KEY . '_' . $currency;

        // Check cache
        $cached_rate = get_transient($cache_key);
        if ($cached_rate !== false) {
            return array(
                'rate' => floatval($cached_rate),
                'source' => 'cache',
            );
        }

        // Fetch from exchange rate API
        $url = 'https://api.exchangerate-api.com/v4/latest/' . strtoupper($currency);

        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            // Try fallback
            return $this->get_fiat_to_usd_rate_fallback($currency);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['rates']['USD'])) {
            return $this->get_fiat_to_usd_rate_fallback($currency);
        }

        $rate = floatval($data['rates']['USD']);

        // Cache for 30 minutes (exchange rates don't change as fast as crypto)
        set_transient($cache_key, $rate, 1800);

        return array(
            'rate' => $rate,
            'source' => 'exchangerate-api',
        );
    }

    /**
     * Fallback for exchange rate using CoinGecko
     */
    private function get_fiat_to_usd_rate_fallback($currency = 'eur') {
        // Use CoinGecko's exchange rate data
        $url = 'https://api.coingecko.com/api/v3/simple/price?ids=tether&vs_currencies=' . $currency . ',usd';

        $response = wp_remote_get($url, array(
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            // Last resort: use approximate EUR/USD rate
            if ($currency === 'eur') {
                return array(
                    'rate' => 1.08, // Approximate EUR/USD
                    'source' => 'fallback',
                );
            }
            return new WP_Error('api_error', 'Failed to get exchange rate');
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['tether'][$currency]) || !isset($data['tether']['usd'])) {
            if ($currency === 'eur') {
                return array(
                    'rate' => 1.08,
                    'source' => 'fallback',
                );
            }
            return new WP_Error('api_error', 'Invalid exchange rate response');
        }

        // Calculate rate: if 1 USDT = X EUR and 1 USDT = Y USD, then 1 EUR = Y/X USD
        $rate = $data['tether']['usd'] / $data['tether'][$currency];

        return array(
            'rate' => $rate,
            'source' => 'coingecko',
        );
    }

    /**
     * Fetch ETH price from CoinGecko API
     */
    private function fetch_eth_price($currency = 'eur') {
        $url = 'https://api.coingecko.com/api/v3/simple/price?ids=ethereum&vs_currencies=' . $currency;

        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            // Try fallback API (CryptoCompare)
            return $this->fetch_eth_price_fallback($currency);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['ethereum'][$currency])) {
            return $this->fetch_eth_price_fallback($currency);
        }

        return array(
            'price' => floatval($data['ethereum'][$currency]),
            'source' => 'coingecko',
        );
    }

    /**
     * Fallback price fetch from CryptoCompare
     */
    private function fetch_eth_price_fallback($currency = 'eur') {
        $url = 'https://min-api.cryptocompare.com/data/price?fsym=ETH&tsyms=' . strtoupper($currency);

        $response = wp_remote_get($url, array(
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('api_error', 'Failed to fetch ETH price from all sources');
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        $currency_upper = strtoupper($currency);

        if (!isset($data[$currency_upper])) {
            return new WP_Error('api_error', 'Invalid response from price API');
        }

        return array(
            'price' => floatval($data[$currency_upper]),
            'source' => 'cryptocompare',
        );
    }

    /**
     * Event topic hashes for our contract events
     */
    const EVENT_PAYMENT_RECEIVED = '0x4aa351061f13d3dff9e0f6cab4811de6a51a2f94e424b21ce31914f1e99c17bc';
    const EVENT_TOKEN_PAYMENT_RECEIVED = '0x0a7e11d6b5194b35bf3d4e463e2cb08dd9681b79fe6d4a1ff9725977a7da38d7';

    /**
     * RPC endpoints for different networks
     */
    private function get_rpc_url($network) {
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
        return $rpc_urls[$network] ?? $rpc_urls['base_sepolia'];
    }

    /**
     * Get block explorer URL for a network
     */
    private function get_explorer_url($network, $tx_hash) {
        $explorers = array(
            'sepolia' => 'https://sepolia.etherscan.io/tx/',
            'base_sepolia' => 'https://sepolia.basescan.org/tx/',
            'optimism_sepolia' => 'https://sepolia-optimism.etherscan.io/tx/',
            'arbitrum_sepolia' => 'https://sepolia.arbiscan.io/tx/',
            'ethereum' => 'https://etherscan.io/tx/',
            'base' => 'https://basescan.org/tx/',
            'optimism' => 'https://optimistic.etherscan.io/tx/',
            'arbitrum' => 'https://arbiscan.io/tx/',
        );
        $base_url = $explorers[$network] ?? $explorers['sepolia'];
        return $base_url . $tx_hash;
    }

    /**
     * Call JSON-RPC method on blockchain
     */
    private function rpc_call($network, $method, $params = array()) {
        $rpc_url = $this->get_rpc_url($network);

        $body = json_encode(array(
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ));

        $response = wp_remote_post($rpc_url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
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
     * Get transaction receipt from blockchain
     */
    private function get_transaction_receipt($network, $tx_hash) {
        return $this->rpc_call($network, 'eth_getTransactionReceipt', array($tx_hash));
    }

    /**
     * Decode hex to integer
     */
    private function hex_to_int($hex) {
        if (strpos($hex, '0x') === 0) {
            $hex = substr($hex, 2);
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
     * Decode address from 32-byte padded hex
     */
    private function decode_address($hex) {
        // Address is last 20 bytes (40 chars) of 32-byte hex
        if (strpos($hex, '0x') === 0) {
            $hex = substr($hex, 2);
        }
        return '0x' . substr($hex, -40);
    }

    /**
     * Parse PaymentReceived event data
     * Event: PaymentReceived(address indexed payer, address indexed merchant, uint256 orderId, uint256 amount, uint256 merchantAmount, uint256 platformFee, uint256 timestamp)
     */
    private function parse_payment_received_event($log) {
        // Topics: [eventSig, payer, merchant]
        // Data: orderId, amount, merchantAmount, platformFee, timestamp
        if (count($log['topics']) < 3) {
            return null;
        }

        $data = substr($log['data'], 2); // Remove 0x
        $chunks = str_split($data, 64); // Split into 32-byte chunks

        if (count($chunks) < 5) {
            return null;
        }

        return array(
            'type' => 'eth',
            'payer' => $this->decode_address($log['topics'][1]),
            'merchant' => $this->decode_address($log['topics'][2]),
            'order_id' => $this->hex_to_int($chunks[0]),
            'amount' => $this->hex_to_int($chunks[1]),
            'merchant_amount' => $this->hex_to_int($chunks[2]),
            'platform_fee' => $this->hex_to_int($chunks[3]),
            'timestamp' => $this->hex_to_int($chunks[4]),
        );
    }

    /**
     * Parse TokenPaymentReceived event data
     * Event: TokenPaymentReceived(address indexed payer, address indexed merchant, address indexed token, uint256 orderId, uint256 amount, uint256 merchantAmount, uint256 platformFee, uint256 timestamp)
     */
    private function parse_token_payment_received_event($log) {
        // Topics: [eventSig, payer, merchant, token]
        // Data: orderId, amount, merchantAmount, platformFee, timestamp
        if (count($log['topics']) < 4) {
            return null;
        }

        $data = substr($log['data'], 2); // Remove 0x
        $chunks = str_split($data, 64); // Split into 32-byte chunks

        if (count($chunks) < 5) {
            return null;
        }

        return array(
            'type' => 'token',
            'payer' => $this->decode_address($log['topics'][1]),
            'merchant' => $this->decode_address($log['topics'][2]),
            'token' => $this->decode_address($log['topics'][3]),
            'order_id' => $this->hex_to_int($chunks[0]),
            'amount' => $this->hex_to_int($chunks[1]),
            'merchant_amount' => $this->hex_to_int($chunks[2]),
            'platform_fee' => $this->hex_to_int($chunks[3]),
            'timestamp' => $this->hex_to_int($chunks[4]),
        );
    }

    /**
     * Verify transaction on blockchain with full on-chain verification
     */
    public function verify_transaction($request) {
        $tx_hash = $request->get_param('tx_hash');
        $order_id = $request->get_param('order_id');

        // Validate tx hash format
        if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $tx_hash)) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Invalid transaction hash format',
            ));
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Order not found',
            ));
        }

        // Get gateway settings
        $gateway = new L2Pay_Gateway();
        $network = $gateway->get_option('network', 'base_sepolia');
        $merchant_address = strtolower($gateway->get_option('merchant_wallet', ''));
        $contract_address = strtolower($gateway->get_option('contract_address', ''));

        // Get transaction receipt from blockchain
        $receipt = $this->get_transaction_receipt($network, $tx_hash);

        if (is_wp_error($receipt)) {
            $order->update_meta_data('_l2pay_tx_hash', $tx_hash);
            $order->update_meta_data('_l2pay_verified', 'error');
            $order->update_meta_data('_l2pay_verify_error', $receipt->get_error_message());
            $order->save();

            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Failed to verify transaction: ' . $receipt->get_error_message(),
            ));
        }

        if ($receipt === null) {
            // Transaction not yet mined
            $order->update_meta_data('_l2pay_tx_hash', $tx_hash);
            $order->update_meta_data('_l2pay_verified', 'pending');
            $order->save();

            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Transaction not yet confirmed. Please wait.',
                'status' => 'pending',
            ));
        }

        // Check transaction status (1 = success, 0 = failed)
        $tx_status = $this->hex_to_int($receipt['status'] ?? '0x0');

        if ($tx_status !== '1') {
            $order->update_meta_data('_l2pay_tx_hash', $tx_hash);
            $order->update_meta_data('_l2pay_verified', 'failed');
            $order->add_order_note('L2Pay: Transaction failed on blockchain');
            $order->save();

            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Transaction failed on blockchain',
            ));
        }

        // Verify transaction was sent to our contract
        $tx_to = strtolower($receipt['to'] ?? '');
        if ($tx_to !== $contract_address) {
            $order->update_meta_data('_l2pay_tx_hash', $tx_hash);
            $order->update_meta_data('_l2pay_verified', 'invalid');
            $order->add_order_note('L2Pay: Transaction sent to wrong contract address');
            $order->save();

            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Transaction sent to invalid contract',
            ));
        }

        // Parse event logs to find our payment event
        $payment_data = null;
        $logs = $receipt['logs'] ?? array();

        foreach ($logs as $log) {
            if (empty($log['topics'])) {
                continue;
            }

            $event_topic = $log['topics'][0];

            if ($event_topic === self::EVENT_PAYMENT_RECEIVED) {
                $payment_data = $this->parse_payment_received_event($log);
                break;
            } elseif ($event_topic === self::EVENT_TOKEN_PAYMENT_RECEIVED) {
                $payment_data = $this->parse_token_payment_received_event($log);
                break;
            }
        }

        if ($payment_data === null) {
            $order->update_meta_data('_l2pay_tx_hash', $tx_hash);
            $order->update_meta_data('_l2pay_verified', 'invalid');
            $order->add_order_note('L2Pay: No valid payment event found in transaction');
            $order->save();

            return rest_ensure_response(array(
                'success' => false,
                'error' => 'No valid payment event found in transaction',
            ));
        }

        // Verify order ID matches
        if ($payment_data['order_id'] !== strval($order_id)) {
            $order->update_meta_data('_l2pay_tx_hash', $tx_hash);
            $order->update_meta_data('_l2pay_verified', 'invalid');
            $order->add_order_note(sprintf(
                'L2Pay: Order ID mismatch. Expected %d, got %s',
                $order_id,
                $payment_data['order_id']
            ));
            $order->save();

            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Order ID mismatch in transaction',
            ));
        }

        // Verify merchant address matches
        if (strtolower($payment_data['merchant']) !== $merchant_address) {
            $order->update_meta_data('_l2pay_tx_hash', $tx_hash);
            $order->update_meta_data('_l2pay_verified', 'invalid');
            $order->add_order_note('L2Pay: Merchant address mismatch');
            $order->save();

            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Merchant address mismatch',
            ));
        }

        // Get expected amount from order meta
        $expected_amount = $order->get_meta('_l2pay_amount');
        $payment_type = $order->get_meta('_l2pay_payment_type') ?: 'eth';

        // Verify amount (with small tolerance for rounding)
        if ($expected_amount) {
            $received_amount = $payment_data['amount'];
            $expected_int = bcmul($expected_amount, '1', 0); // Remove decimals

            // Allow 0.1% tolerance for any rounding differences
            $min_amount = bcmul($expected_int, '0.999', 0);

            if (bccomp($received_amount, $min_amount) < 0) {
                $order->update_meta_data('_l2pay_tx_hash', $tx_hash);
                $order->update_meta_data('_l2pay_verified', 'underpaid');
                $order->add_order_note(sprintf(
                    'L2Pay: Underpaid. Expected %s, received %s',
                    $expected_amount,
                    $received_amount
                ));
                $order->save();

                return rest_ensure_response(array(
                    'success' => false,
                    'error' => 'Payment amount is less than expected',
                    'expected' => $expected_amount,
                    'received' => $received_amount,
                ));
            }
        }

        // All verifications passed - mark order as verified and complete
        $order->update_meta_data('_l2pay_tx_hash', $tx_hash);
        $order->update_meta_data('_l2pay_verified', 'yes');
        $order->update_meta_data('_l2pay_verified_at', time());
        $order->update_meta_data('_l2pay_payer', $payment_data['payer']);
        $order->update_meta_data('_l2pay_amount_received', $payment_data['amount']);
        $order->update_meta_data('_l2pay_merchant_amount', $payment_data['merchant_amount']);
        $order->update_meta_data('_l2pay_platform_fee', $payment_data['platform_fee']);
        $order->update_meta_data('_l2pay_block_number', $this->hex_to_int($receipt['blockNumber']));

        if ($payment_data['type'] === 'token') {
            $order->update_meta_data('_l2pay_token_address', $payment_data['token']);
        }

        // Mark payment as complete
        $order->payment_complete($tx_hash);

        // Add order note with verification details
        $explorer_url = $this->get_explorer_url($network, $tx_hash);
        $symbol = $payment_type === 'usdc' ? 'USDC' : 'ETH';
        $decimals = $payment_type === 'usdc' ? 6 : 18;
        $amount_formatted = bcdiv($payment_data['amount'], bcpow('10', $decimals), $decimals);

        $order->add_order_note(sprintf(
            'L2Pay: Payment verified on-chain. Amount: %s %s. TX: %s',
            $amount_formatted,
            $symbol,
            $explorer_url
        ));

        $order->save();

        return rest_ensure_response(array(
            'success' => true,
            'verified' => true,
            'message' => 'Payment verified on blockchain',
            'tx_hash' => $tx_hash,
            'order_id' => $order_id,
            'payment_type' => $payment_data['type'],
            'amount' => $payment_data['amount'],
            'merchant' => $payment_data['merchant'],
            'payer' => $payment_data['payer'],
            'block_number' => $this->hex_to_int($receipt['blockNumber']),
            'explorer_url' => $explorer_url,
        ));
    }

    /**
     * Create a pending order for crypto payment
     */
    public function create_pending_order($request) {
        // This endpoint allows creating an order before the blockchain transaction
        // so we have an order ID to pass to the smart contract

        if (!WC()->cart || WC()->cart->is_empty()) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Cart is empty',
            ));
        }

        // Get ETH conversion
        $total = WC()->cart->get_total('edit');
        $currency = get_woocommerce_currency();

        $convert_request = new WP_REST_Request('GET', '/l2pay/v1/convert');
        $convert_request->set_param('amount', $total);
        $convert_request->set_param('currency', $currency);
        $convert_response = $this->convert_to_eth($convert_request);
        $conversion = $convert_response->get_data();

        if (!$conversion['success']) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => 'Failed to convert price',
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'cart_total' => $total,
            'currency' => $currency,
            'eth_amount' => $conversion['eth_amount'],
            'wei_amount' => $conversion['wei_amount'],
            'eth_price' => $conversion['eth_price'],
            'valid_for' => $conversion['valid_for'],
        ));
    }
}
