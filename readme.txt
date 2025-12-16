=== L2Pay - Accept Crypto Payments for WooCommerce ===
Contributors: l2pay
Tags: woocommerce, crypto, ethereum, payments, metamask, usdc, layer2, base, optimism, arbitrum
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept ETH and USDC payments via MetaMask on Layer 2 networks (Base, Optimism, Arbitrum) with low fees.

== Description ==

L2Pay enables WooCommerce stores to accept cryptocurrency payments directly through MetaMask. Payments are processed on Layer 2 networks for minimal transaction fees.

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
2. Customer connects their MetaMask wallet
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

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/l2pay/` or install through the WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to WooCommerce > Settings > Payments > L2Pay
4. Enter your Ethereum wallet address
5. Choose between Test Mode (testnets) or Live Mode (mainnets)
6. Enable the payment gateway

== Frequently Asked Questions ==

= What wallets are supported? =

Currently, only MetaMask is supported. Support for WalletConnect and other wallets is planned for future releases.

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

== Screenshots ==

1. Payment method selection at checkout
2. MetaMask connection and network selection
3. Admin settings page
4. Order details with transaction hash

== Changelog ==

= 1.0.0 =
* Initial release
* Support for ETH and USDC payments
* Multi-network support (Ethereum, Base, Optimism, Arbitrum)
* On-chain payment verification
* Test mode with testnet support

== Upgrade Notice ==

= 1.0.0 =
Initial release of L2Pay for WooCommerce.
