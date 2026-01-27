=== Layer Crypto Checkout - Crypto Payments for WooCommerce ===
Contributors: l2crypto
Donate link: https://layercryptocheckout.com
Tags: woocommerce, cryptocurrency, ethereum, payments, web3
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept ETH and USDC payments via MetaMask or WalletConnect on Layer 2 networks (Base, Optimism, Arbitrum) with low fees.

== Description ==

Layer Crypto Checkout enables WooCommerce stores to accept cryptocurrency payments through MetaMask or WalletConnect. Payments are processed on Layer 2 networks for minimal transaction fees.

= Key Features =

* **Multi-Network Support** - Accept payments on Ethereum, Base, Optimism, and Arbitrum
* **ETH & USDC Payments** - Customers can pay with native ETH or USDC stablecoin
* **Non-Custodial** - Payments go directly to your wallet. We never hold your funds
* **Low Fees** - Layer 2 networks offer transaction fees under $0.01
* **On-Chain Verification** - Every payment is verified on blockchain before order completion
* **Real-Time Conversion** - Automatic fiat to crypto conversion at checkout
* **Test Mode** - Test on testnets before going live with real payments

= How It Works =

1. Customer selects "Pay with Crypto" at checkout
2. Customer connects their MetaMask or WalletConnect wallet
3. Customer chooses network (Base, Optimism, Arbitrum, or Ethereum)
4. Customer chooses payment method (ETH or USDC)
5. Payment is sent directly to your wallet via smart contract
6. Order is completed after on-chain verification

= Supported Networks =

**Mainnets (Live Payments):**
* Ethereum Mainnet
* Base
* Optimism
* Arbitrum One

**Testnets (Testing):**
* Ethereum Sepolia
* Base Sepolia
* Optimism Sepolia
* Arbitrum Sepolia

= Security =

* **Immutable Smart Contract** - Contract code cannot be changed after deployment
* **Reentrancy Protection** - Built-in guard against reentrancy attacks (OpenZeppelin standard)
* **Replay Attack Protection** - Each payment is unique and cannot be reused
* **Open Source & Verified** - Contract source code is publicly verified on all block explorers

= Platform Fee =

A 1% platform fee is applied to each transaction to support ongoing development and maintenance.

== External Services ==

This plugin connects to the following external services:

= CoinGecko API =
Used to fetch real-time cryptocurrency prices for ETH and USDC conversion.
- Data sent: Currency code (EUR, USD, GBP, etc.)
- When: During checkout to calculate crypto amount
- Service: https://www.coingecko.com
- Terms: https://www.coingecko.com/en/terms
- Privacy: https://www.coingecko.com/en/privacy

= ExchangeRate-API =
Used to fetch fiat currency exchange rates for USDC payments.
- Data sent: Base currency code
- When: During checkout for non-USD currencies
- Service: https://www.exchangerate-api.com
- Terms: https://www.exchangerate-api.com/terms
- Privacy: https://www.exchangerate-api.com/terms

= CryptoCompare API (Fallback) =
Used as fallback when CoinGecko is unavailable.
- Data sent: Currency code
- When: Only if primary price API fails
- Service: https://www.cryptocompare.com
- Terms: https://www.cryptocompare.com/terms-conditions
- Privacy: https://www.cryptocompare.com/privacy-policy

= Blockchain RPC Endpoints =
Used to verify on-chain transactions and read smart contract data.
- Data sent: Transaction hashes, smart contract read calls (no personal data)
- When: After payment submission to verify transaction on blockchain

The plugin uses the following public RPC providers:

**PublicNode** (Ethereum Sepolia Testnet)
- URL: ethereum-sepolia-rpc.publicnode.com
- Service: https://www.publicnode.com
- Terms: https://www.publicnode.com/terms
- Privacy: https://www.publicnode.com/privacy

**LlamaNodes** (Ethereum Mainnet)
- URL: eth.llamarpc.com
- Service: https://llamarpc.com
- Terms: https://llamarpc.com/terms
- Privacy: https://llamarpc.com/privacy

**Base Network** (Coinbase L2)
- URLs: mainnet.base.org, sepolia.base.org
- Service: https://base.org
- Terms: https://base.org/terms-of-service
- Privacy: https://base.org/privacy-policy

**Optimism Network**
- URLs: mainnet.optimism.io, sepolia.optimism.io
- Service: https://optimism.io
- Terms: https://optimism.io/terms
- Privacy: https://optimism.io/privacy

**Arbitrum Network**
- URLs: arb1.arbitrum.io, sepolia-rollup.arbitrum.io
- Service: https://arbitrum.io
- Terms: https://arbitrum.io/tos
- Privacy: https://arbitrum.io/privacy

= WalletConnect =
Optional wallet connection service for connecting crypto wallets.
- Data sent: Connection requests, transaction signing requests
- When: When customer chooses WalletConnect option
- Service: https://walletconnect.com
- Terms: https://walletconnect.com/terms
- Privacy: https://walletconnect.com/privacy

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/layer-crypto-checkout/` or install through the WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to WooCommerce > Settings > Payments > Layer Crypto Checkout
4. Enter your Ethereum wallet address
5. Choose between Test Mode (testnets) or Live Mode (mainnets)
6. Enable the payment gateway

== Frequently Asked Questions ==

= What wallets are supported? =

MetaMask (browser extension) and any WalletConnect-compatible wallet including Trust Wallet, Rainbow, Coinbase Wallet, and 300+ other wallets.

= What currencies can I accept? =

You can accept ETH (native Ethereum) and USDC (stablecoin pegged to USD) on all supported networks.

= How do refunds work? =

Crypto refunds must be processed manually by sending funds back to the customer's wallet address, which is recorded in the order details.

= Is there a minimum order amount? =

There's no minimum from the plugin side, but very small orders may not be practical due to gas fees on mainnet. Layer 2 networks have very low fees.

= How is the exchange rate determined? =

Exchange rates are fetched from CoinGecko API with a configurable price margin (default 2%) to account for volatility during transaction confirmation.

= What happens if the transaction fails? =

If a blockchain transaction fails, the customer will see an error message and can retry the payment. No order is created until payment is verified on-chain.

= How can I get support? =

For technical support, feature requests, or bug reports, please email us at support@layercryptocheckout.com or visit https://layercryptocheckout.com

== Screenshots ==

1. Payment method selection at checkout
2. MetaMask connection and network selection
3. Admin settings page
4. Order details with transaction hash

== Changelog ==

= 1.5.0 =
* Fixed network mismatch during checkout - wallet chain is now verified before and after transaction
* Improved checkout form validation - hidden shipping fields no longer block payment
* Improved form validation error messages - now shows which specific fields are missing
* Increased on-chain verification retry from 6s to 30s for better testnet and L2 compatibility

= 1.4.0 =
* Updated all code prefixes from 'lcc' (3 chars) to 'lccp' (4 chars) for WordPress plugin directory compliance
* Fixed REST API permission callbacks - endpoints now use __return_true for public checkout endpoints
* Documented all external RPC service providers in readme (PublicNode, LlamaNodes, Base, Optimism, Arbitrum)
* Removed unused nonce verification method from API class
* Renamed all class files, JavaScript, and CSS files to use new prefix

= 1.3.0 =
* Renamed plugin from LayerPay to Layer Crypto Checkout
* Updated all code prefixes and identifiers
* Improved WordPress plugin directory compliance
* Updated plugin slug to layer-crypto-checkout

= 1.2.0 =
* Fixed all output escaping for WordPress security standards
* Included WalletConnect library locally instead of CDN
* Documented all external services in readme

= 1.1.0 =
* Added WalletConnect support - customers can now connect with 300+ mobile wallets
* Improved wallet connection flow with AppKit modal
* Updated translations for all supported languages
* Better error handling for wallet connection issues

= 1.0.0 =
* Initial release
* Support for ETH and USDC payments
* Multi-network support (Ethereum, Base, Optimism, Arbitrum)
* On-chain payment verification
* Test mode with testnet support

== Upgrade Notice ==

= 1.5.0 =
Fixes network mismatch issue during checkout and improves payment verification reliability.

= 1.4.0 =
Code prefix update for WordPress compliance. All functionality remains the same.

= 1.3.0 =
Plugin renamed from LayerPay to Layer Crypto Checkout. All functionality remains the same.

= 1.2.0 =
Security improvements and WalletConnect library bundled locally.

= 1.1.0 =
Added WalletConnect support for mobile wallet users. No configuration changes needed.

= 1.0.0 =
Initial release of Layer Crypto Checkout for WooCommerce.
