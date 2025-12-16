/**
 * L2Pay Checkout - MetaMask Integration
 *
 * Handles wallet connection, price conversion, and payment processing
 * Supports ETH and USDC payments on multiple blockchain networks
 */

(function($) {
    'use strict';

    // State management
    const L2Pay = {
        isConnected: false,
        account: null,
        ethAmount: null,
        weiAmount: null,
        usdcAmount: null,
        usdcSmallestUnit: null,
        orderId: null,
        initialized: false,
        selectedNetwork: null,
        paymentType: 'eth', // 'eth' or 'usdc'

        /**
         * Initialize the payment handler
         */
        init: function() {
            const self = this;

            // Use event delegation for dynamic elements
            $(document).on('click', '#l2pay-connect-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.handleConnectClick();
            });

            // Listen for network selector change
            $(document).on('change', '#l2pay-network', function() {
                const networkKey = $(this).val();
                self.onNetworkChange(networkKey);
            });

            // Listen for payment type change
            $(document).on('change', 'input[name="l2pay_payment_type"]', function() {
                const paymentType = $(this).val();
                self.onPaymentTypeChange(paymentType);
            });

            // Listen for payment method change
            $(document).on('change', 'input[name="payment_method"]', function() {
                if ($(this).val() === 'l2pay') {
                    self.onPaymentMethodSelected();
                }
            });

            // Check if already selected on page load
            $(document).ready(function() {
                setTimeout(function() {
                    if ($('input[name="payment_method"]:checked').val() === 'l2pay') {
                        self.onPaymentMethodSelected();
                    }
                }, 500);
            });

            // Listen for checkout updates
            $(document.body).on('updated_checkout', function() {
                if ($('input[name="payment_method"]:checked').val() === 'l2pay') {
                    self.onPaymentMethodSelected();
                }
            });

            // Setup MetaMask event listeners after first check
            self.setupProviderListeners = function() {
                if (self.provider && !self.listenersSetup) {
                    self.provider.on('accountsChanged', function(accounts) {
                        if (accounts.length === 0) {
                            self.disconnect();
                        } else {
                            self.account = accounts[0];
                            self.updateUI();
                        }
                    });

                    self.provider.on('chainChanged', function(chainId) {
                        self.syncNetworkSelector(chainId);
                    });

                    self.listenersSetup = true;
                }
            };

            // Form validation
            $(document).on('checkout_place_order_l2pay', function() {
                return self.validatePayment();
            });

        },

        /**
         * Get the currently selected network configuration
         */
        getSelectedNetwork: function() {
            const networkKey = $('#l2pay-network').val() || l2payData.defaultNetwork || 'sepolia';
            const network = l2payData.networks[networkKey];
            if (!network) {
                console.error('L2Pay: Network not found:', networkKey);
                return l2payData.networks['sepolia'] || {};
            }
            return {
                key: networkKey,
                name: network.name,
                chainId: network.chainId,
                chainIdDec: network.chainIdDec,
                contract: network.contract,
                rpcUrl: network.rpcUrl,
                explorer: network.explorer,
                symbol: network.symbol,
                isTestnet: network.isTestnet,
                usdcAddress: network.usdcAddress,
                usdcDecimals: network.usdcDecimals || 6
            };
        },

        /**
         * Called when network selector changes
         */
        onNetworkChange: async function(networkKey) {
            const self = this;
            const network = l2payData.networks[networkKey];
            if (!network) {
                console.error('L2Pay: Network not found:', networkKey);
                return;
            }

            this.selectedNetwork = network;

            // Reset payment state
            this.ethAmount = null;
            this.weiAmount = null;
            this.usdcAmount = null;
            this.usdcSmallestUnit = null;

            // If connected, switch network and refresh conversion
            if (this.isConnected) {
                try {
                    await self.ensureCorrectNetwork();
                    await self.fetchConversion();
                } catch (err) {
                    console.error('L2Pay: Network switch failed:', err);
                    self.showError('Failed to switch network. Please switch manually in MetaMask.');
                }
            }
        },

        /**
         * Called when payment type changes (ETH vs USDC)
         */
        onPaymentTypeChange: async function(paymentType) {
            this.paymentType = paymentType;

            // Update the hidden field for form submission
            $('#l2pay-payment-type').val(paymentType);

            // Reset amounts
            this.ethAmount = null;
            this.weiAmount = null;
            this.usdcAmount = null;
            this.usdcSmallestUnit = null;

            // Update button text
            this.updateButtonText();

            // Refresh conversion if connected
            if (this.isConnected) {
                await this.fetchConversion();
            }
        },

        /**
         * Sync network selector when user changes chain in MetaMask
         */
        syncNetworkSelector: function(chainId) {
            const networks = l2payData.networks;
            for (const key in networks) {
                if (networks[key].chainId === chainId) {
                    $('#l2pay-network').val(key);
                    this.selectedNetwork = networks[key];
                    this.fetchConversion();
                    return;
                }
            }
            this.showError('Please select a supported network.');
        },

        /**
         * Called when L2Pay payment method is selected
         */
        onPaymentMethodSelected: function() {
            this.checkMetaMask();

            // Initialize selected network
            const network = this.getSelectedNetwork();
            this.selectedNetwork = network;

            // Check current payment type
            const paymentType = $('input[name="l2pay_payment_type"]:checked').val() || 'eth';
            this.paymentType = paymentType;

            if (this.isConnected) {
                this.updateUI();
                this.fetchConversion();
            }
        },

        /**
         * Get DOM elements (fresh lookup each time)
         */
        getElements: function() {
            return {
                container: $('#l2pay-payment-container'),
                connectBtn: $('#l2pay-connect-btn'),
                networkSelect: $('#l2pay-network'),
                walletStatus: $('#l2pay-wallet-status'),
                priceDisplay: $('#l2pay-price-display'),
                cryptoAmountEl: $('#l2pay-crypto-amount'),
                rateEl: $('#l2pay-rate'),
                errorEl: $('#l2pay-error'),
                txHashInput: $('#l2pay-tx-hash'),
                cryptoPaidInput: $('#l2pay-eth-paid'),
                walletInput: $('#l2pay-wallet-address'),
            };
        },

        /**
         * Check if MetaMask is installed and get the correct provider
         */
        checkMetaMask: function() {
            const elements = this.getElements();

            let provider = null;

            if (window.ethereum) {
                if (window.ethereum.providers && window.ethereum.providers.length) {
                    provider = window.ethereum.providers.find(p => p.isMetaMask);
                } else if (window.ethereum.isMetaMask) {
                    provider = window.ethereum;
                }
            }

            if (!provider) {
                this.showError(l2payData.i18n.installMetamask);
                elements.connectBtn.prop('disabled', true);
                return false;
            }

            this.provider = provider;

            if (this.setupProviderListeners) {
                this.setupProviderListeners();
            }

            return true;
        },

        /**
         * Handle connect button click
         */
        handleConnectClick: async function() {

            if (!this.checkMetaMask()) {
                return;
            }

            if (!this.isConnected) {
                await this.connect();
            } else if ((this.paymentType === 'eth' && !this.ethAmount) ||
                       (this.paymentType === 'usdc' && !this.usdcAmount)) {
                await this.fetchConversion();
            } else {
                await this.processPayment();
            }
        },

        /**
         * Connect to MetaMask
         */
        connect: async function() {
            const elements = this.getElements();
            const provider = this.provider;

            try {
                this.setButtonState('connecting');

                const accounts = await provider.request({
                    method: 'eth_requestAccounts'
                });


                if (accounts.length === 0) {
                    throw new Error('No accounts found');
                }

                this.account = accounts[0];
                this.isConnected = true;

                await this.ensureCorrectNetwork();
                this.updateUI();
                elements.walletInput.val(this.account);
                await this.fetchConversion();
                this.setButtonState('ready');

            } catch (error) {
                console.error('L2Pay: Connection error:', error);
                this.showError(error.message);
                this.setButtonState('connect');
            }
        },

        /**
         * Ensure we're on the correct network
         */
        ensureCorrectNetwork: async function() {
            const provider = this.provider;
            const network = this.getSelectedNetwork();
            const targetChainId = network.chainId;

            const currentChainId = await provider.request({ method: 'eth_chainId' });

            if (currentChainId !== targetChainId) {
                try {
                    await provider.request({
                        method: 'wallet_switchEthereumChain',
                        params: [{ chainId: targetChainId }],
                    });
                } catch (switchError) {
                    if (switchError.code === 4902) {
                        await provider.request({
                            method: 'wallet_addEthereumChain',
                            params: [{
                                chainId: targetChainId,
                                chainName: network.name,
                                rpcUrls: [network.rpcUrl],
                                blockExplorerUrls: [network.explorer],
                                nativeCurrency: {
                                    name: network.symbol,
                                    symbol: network.symbol,
                                    decimals: 18
                                }
                            }],
                        });
                    } else {
                        throw switchError;
                    }
                }
            }
        },

        /**
         * Update UI elements
         */
        updateUI: function() {
            const elements = this.getElements();
            const network = this.getSelectedNetwork();

            if (this.isConnected && this.account) {
                const shortAddress = this.account.slice(0, 6) + '...' + this.account.slice(-4);
                elements.walletStatus
                    .removeClass('disconnected')
                    .addClass('connected')
                    .html('<span class="l2pay-status-icon">&#9989;</span>' +
                          '<span class="l2pay-status-text">Connected: ' + shortAddress + ' (' + network.name + ')</span>');
            } else {
                elements.walletStatus
                    .removeClass('connected')
                    .addClass('disconnected')
                    .html('<span class="l2pay-status-icon">&#128274;</span>' +
                          '<span class="l2pay-status-text">' + l2payData.i18n.connectWallet + '</span>');
            }
        },

        /**
         * Update button text based on payment type
         */
        updateButtonText: function() {
            if (!this.isConnected) return;

            const btn = this.getElements().connectBtn;
            if (this.paymentType === 'usdc') {
                btn.html('<span class="l2pay-btn-icon">&#128176;</span> ' + l2payData.i18n.payWithUsdc);
            } else {
                const network = this.getSelectedNetwork();
                btn.html('<span class="l2pay-btn-icon">&#128176;</span> Pay with ' + (network.symbol || 'ETH'));
            }
        },

        /**
         * Fetch fiat to crypto conversion
         */
        fetchConversion: async function() {
            const elements = this.getElements();
            const network = this.getSelectedNetwork();

            try {
                elements.cryptoAmountEl.text('Loading...');
                elements.rateEl.text('Loading...');

                const cartTotal = this.getCartTotal();

                if (!cartTotal || cartTotal <= 0) {
                    throw new Error('Could not determine cart total. Please refresh the page.');
                }

                let endpoint, symbol;
                if (this.paymentType === 'usdc') {
                    endpoint = 'convert-usdc';
                    symbol = 'USDC';
                } else {
                    endpoint = 'convert';
                    symbol = network.symbol || 'ETH';
                }

                const response = await fetch(
                    l2payData.restUrl + endpoint + '?amount=' + cartTotal + '&currency=' + l2payData.currency
                );

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || l2payData.i18n.conversionError);
                }

                if (this.paymentType === 'usdc') {
                    this.usdcAmount = data.usdc_amount;
                    this.usdcSmallestUnit = data.usdc_smallest_unit;

                    // Validate USDC conversion result
                    if (!this.usdcSmallestUnit || this.usdcSmallestUnit === '0') {
                        throw new Error('Invalid USDC conversion. Please try again.');
                    }

                    elements.cryptoAmountEl.text(data.usdc_amount.toFixed(2) + ' ' + symbol);
                    elements.rateEl.text('1 ' + symbol + ' = 1.00 USD (Rate: 1 ' + data.fiat_currency + ' = ' + data.exchange_rate.toFixed(4) + ' USD)');
                } else {
                    this.ethAmount = data.eth_amount;
                    this.weiAmount = data.wei_amount;

                    // Validate ETH conversion result
                    if (!this.weiAmount || this.weiAmount === '0') {
                        throw new Error('Invalid ETH conversion. Please try again.');
                    }

                    elements.cryptoAmountEl.text(data.eth_amount.toFixed(6) + ' ' + symbol);
                    elements.rateEl.text('1 ' + symbol + ' = ' + data.eth_price.toFixed(2) + ' ' + data.fiat_currency);
                }

                elements.priceDisplay.show();
                elements.cryptoPaidInput.val(this.paymentType === 'usdc' ? this.usdcAmount : this.ethAmount);

                this.hideError();
                this.updateButtonText();

            } catch (error) {
                console.error('L2Pay: Conversion error:', error);
                this.showError(l2payData.i18n.conversionError);
            }
        },

        /**
         * Validate the checkout form before payment
         */
        validateForm: function() {
            const $form = $('form.checkout');

            $form.find('.validate-required').each(function() {
                const $field = $(this);
                const $input = $field.find('input, select, textarea').not('[type="hidden"]');

                if ($input.length && !$input.val()) {
                    $field.addClass('woocommerce-invalid woocommerce-invalid-required-field');
                } else {
                    $field.removeClass('woocommerce-invalid woocommerce-invalid-required-field');
                }
            });

            const hasErrors = $form.find('.woocommerce-invalid').length > 0;

            if (hasErrors) {
                const $firstError = $form.find('.woocommerce-invalid').first();
                if ($firstError.length) {
                    $('html, body').animate({
                        scrollTop: $firstError.offset().top - 100
                    }, 500);
                }
                this.showError('Please fill in all required fields before paying.');
                return false;
            }

            const $email = $('#billing_email');
            if ($email.length && $email.val()) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test($email.val())) {
                    this.showError('Please enter a valid email address.');
                    return false;
                }
            }

            return true;
        },

        /**
         * Process the payment
         */
        processPayment: async function() {
            const self = this;
            const elements = this.getElements();

            if (!this.validateForm()) {
                return;
            }

            try {
                this.setButtonState('processing');
                this.hideError();

                // Refresh conversion with latest price
                await this.fetchConversion();

                // Check if we have valid amounts after conversion
                if (this.paymentType === 'usdc' && !this.usdcSmallestUnit) {
                    throw new Error('Failed to get USDC conversion. Please try again.');
                }
                if (this.paymentType !== 'usdc' && !this.weiAmount) {
                    throw new Error('Failed to get ETH conversion. Please try again.');
                }

                // Check minimum payment amounts (contract requirements)
                const MIN_ETH_WEI = '100000000000000'; // 0.0001 ETH
                const MIN_USDC = '100000'; // 0.1 USDC (6 decimals)

                if (this.paymentType === 'usdc' && BigInt(this.usdcSmallestUnit) < BigInt(MIN_USDC)) {
                    throw new Error('Order total is below minimum (0.1 USDC). Please add more items.');
                }
                if (this.paymentType !== 'usdc' && BigInt(this.weiAmount) < BigInt(MIN_ETH_WEI)) {
                    throw new Error('Order total is below minimum (0.0001 ETH ≈ €0.35). Please add more items.');
                }

                const paymentId = Date.now();

                let txHash;
                if (this.paymentType === 'usdc') {
                    txHash = await this.processUsdcPayment(paymentId);
                } else {
                    this.setButtonState('confirming');
                    txHash = await this.sendTransaction(paymentId);
                }


                // Store the values in hidden fields BEFORE any form submission
                elements.txHashInput.val(txHash);
                elements.cryptoPaidInput.val(this.paymentType === 'usdc' ? this.usdcAmount : this.ethAmount);

                // Also store network and payment type
                $('#l2pay-payment-type').val(this.paymentType);

                // Store the selected network
                const network = this.getSelectedNetwork();
                if (network && network.key) {
                    $('#l2pay-network').val(network.key);
                }

                this.setButtonState('complete');

                // Use a short delay to ensure UI updates, then submit
                setTimeout(function() {
                    // Verify values are set before submitting
                    if (!elements.txHashInput.val()) {
                        console.error('L2Pay: TX hash not set before submit!');
                        self.showError('Payment completed but form submission failed. TX: ' + txHash);
                        return;
                    }


                    // For WooCommerce AJAX checkout, we need to trigger the checkout submission
                    // Using the standard jQuery submit() which WooCommerce intercepts
                    const $form = $('form.checkout');

                    if ($form.length) {
                        // Remove any existing error messages
                        $('.woocommerce-error').remove();

                        // Trigger WooCommerce checkout
                        $form.submit();
                    } else {
                        console.error('L2Pay: Checkout form not found');
                        self.showError('Checkout form not found. Please refresh and try again.');
                    }
                }, 500);

            } catch (error) {
                console.error('L2Pay: Payment error:', error);
                this.showError(error.message || l2payData.i18n.transactionFailed);
                this.setButtonState('ready');
            }
        },

        /**
         * Process USDC payment (approve + transfer)
         */
        processUsdcPayment: async function(orderId) {
            const provider = this.provider;
            const network = this.getSelectedNetwork();
            const merchantAddress = l2payData.merchantAddress;

            if (!merchantAddress || !merchantAddress.match(/^0x[a-fA-F0-9]{40}$/)) {
                throw new Error(l2payData.i18n.merchantNotConfigured);
            }

            if (!network.usdcAddress) {
                throw new Error('USDC not available on this network');
            }

            const usdcAddress = network.usdcAddress;
            const contractAddress = network.contract;
            const amount = this.usdcSmallestUnit;

            // Check USDC balance first
            this.setButtonState('processing');
            const balance = await this.getUsdcBalance(usdcAddress);

            if (BigInt(balance) < BigInt(amount)) {
                throw new Error(l2payData.i18n.insufficientUsdc);
            }

            // Check current allowance
            const currentAllowance = await this.getUsdcAllowance(usdcAddress, contractAddress);

            // If allowance is insufficient, request approval
            if (BigInt(currentAllowance) < BigInt(amount)) {
                this.setButtonState('approving');

                const approveTxHash = await this.approveUsdc(usdcAddress, contractAddress, amount);

                // Wait for approval confirmation
                await this.waitForTransaction(approveTxHash);
            }

            // Now send the payment
            this.setButtonState('confirming');
            const txHash = await this.sendTokenTransaction(orderId, usdcAddress, amount);

            await this.waitForTransaction(txHash);

            return txHash;
        },

        /**
         * Get USDC balance
         */
        getUsdcBalance: async function(usdcAddress) {
            const provider = this.provider;

            // balanceOf(address) selector: 0x70a08231
            const data = '0x70a08231' + this.account.slice(2).toLowerCase().padStart(64, '0');

            const result = await provider.request({
                method: 'eth_call',
                params: [{
                    to: usdcAddress,
                    data: data
                }, 'latest']
            });

            return result;
        },

        /**
         * Get USDC allowance
         */
        getUsdcAllowance: async function(usdcAddress, spender) {
            const provider = this.provider;

            // allowance(address,address) selector: 0xdd62ed3e
            const data = '0xdd62ed3e' +
                this.account.slice(2).toLowerCase().padStart(64, '0') +
                spender.slice(2).toLowerCase().padStart(64, '0');

            const result = await provider.request({
                method: 'eth_call',
                params: [{
                    to: usdcAddress,
                    data: data
                }, 'latest']
            });

            return result;
        },

        /**
         * Approve USDC spending
         */
        approveUsdc: async function(usdcAddress, spender, amount) {
            const provider = this.provider;

            // approve(address,uint256) selector: 0x095ea7b3
            // SECURITY: Only approve exact amount needed (with 10% buffer for price changes)
            const approvalAmount = BigInt(amount) * 110n / 100n; // +10% buffer
            const data = '0x095ea7b3' +
                spender.slice(2).toLowerCase().padStart(64, '0') +
                approvalAmount.toString(16).padStart(64, '0');

            const txParams = {
                to: usdcAddress,
                from: this.account,
                data: data
            };

            // Estimate gas
            try {
                const gasEstimate = await provider.request({
                    method: 'eth_estimateGas',
                    params: [txParams]
                });
                txParams.gas = '0x' + Math.floor(parseInt(gasEstimate, 16) * 1.2).toString(16);
            } catch (e) {
                txParams.gas = '0x20000'; // 131072
            }

            const txHash = await provider.request({
                method: 'eth_sendTransaction',
                params: [txParams]
            });

            return txHash;
        },

        /**
         * Send token payment transaction
         */
        sendTokenTransaction: async function(orderId, tokenAddress, amount) {
            const provider = this.provider;
            const network = this.getSelectedNetwork();
            const merchantAddress = l2payData.merchantAddress;

            // payWithToken(uint256,address,address,uint256) selector
            // keccak256("payWithToken(uint256,address,address,uint256)") = 0xd6bcaa76
            const functionSelector = '0xd6bcaa76';

            const encodedOrderId = orderId.toString(16).padStart(64, '0');
            const encodedMerchant = merchantAddress.slice(2).toLowerCase().padStart(64, '0');
            const encodedToken = tokenAddress.slice(2).toLowerCase().padStart(64, '0');
            const encodedAmount = BigInt(amount).toString(16).padStart(64, '0');

            const transactionParameters = {
                to: network.contract,
                from: this.account,
                data: functionSelector + encodedOrderId + encodedMerchant + encodedToken + encodedAmount,
            };

            // Estimate gas
            try {
                const gasEstimate = await provider.request({
                    method: 'eth_estimateGas',
                    params: [transactionParameters],
                });
                transactionParameters.gas = '0x' + Math.floor(parseInt(gasEstimate, 16) * 1.2).toString(16);
            } catch (gasError) {
                transactionParameters.gas = '0x50000'; // 327680
            }


            const txHash = await provider.request({
                method: 'eth_sendTransaction',
                params: [transactionParameters],
            });

            return txHash;
        },

        /**
         * Send ETH transaction to smart contract
         */
        sendTransaction: async function(orderId) {
            const provider = this.provider;
            const network = this.getSelectedNetwork();
            const merchantAddress = l2payData.merchantAddress;

            if (!merchantAddress || !merchantAddress.match(/^0x[a-fA-F0-9]{40}$/)) {
                throw new Error(l2payData.i18n.merchantNotConfigured);
            }

            // pay(uint256,address) function selector: 0x31cbf5e3
            const functionSelector = '0x31cbf5e3';
            const encodedOrderId = orderId.toString(16).padStart(64, '0');
            const encodedMerchant = merchantAddress.slice(2).toLowerCase().padStart(64, '0');

            const transactionParameters = {
                to: network.contract,
                from: this.account,
                value: '0x' + BigInt(this.weiAmount).toString(16),
                data: functionSelector + encodedOrderId + encodedMerchant,
            };

            try {
                const gasEstimate = await provider.request({
                    method: 'eth_estimateGas',
                    params: [transactionParameters],
                });
                const gasLimit = Math.floor(parseInt(gasEstimate, 16) * 1.2);
                transactionParameters.gas = '0x' + gasLimit.toString(16);
            } catch (gasError) {
                transactionParameters.gas = '0x30000';
            }


            const txHash = await provider.request({
                method: 'eth_sendTransaction',
                params: [transactionParameters],
            });

            await this.waitForTransaction(txHash);

            return txHash;
        },

        /**
         * Wait for transaction to be mined
         */
        waitForTransaction: async function(txHash, maxAttempts) {
            const provider = this.provider;
            maxAttempts = maxAttempts || 60;

            for (let i = 0; i < maxAttempts; i++) {
                const receipt = await provider.request({
                    method: 'eth_getTransactionReceipt',
                    params: [txHash],
                });

                if (receipt) {
                    if (receipt.status === '0x1') {
                        return receipt;
                    } else {
                        throw new Error('Transaction failed');
                    }
                }

                await new Promise(function(resolve) { setTimeout(resolve, 2000); });
            }

            throw new Error('Transaction confirmation timeout');
        },

        /**
         * Get cart total from page
         */
        getCartTotal: function() {
            // Try multiple selectors for compatibility with different themes
            const selectors = [
                '.order-total .woocommerce-Price-amount bdi',
                '.order-total .woocommerce-Price-amount',
                '.order-total td .amount',
                '.order-total .amount',
                'tr.order-total td:last-child',
                '.cart-subtotal .woocommerce-Price-amount bdi',
                '.cart-subtotal .woocommerce-Price-amount',
                '.cart_totals .order-total .amount',
                '#order_review .order-total .amount'
            ];

            let totalEl = null;
            for (let i = 0; i < selectors.length; i++) {
                totalEl = $(selectors[i]).first();
                if (totalEl.length && totalEl.text().trim()) {
                    break;
                }
                totalEl = null;
            }

            if (totalEl && totalEl.length) {
                let text = totalEl.text();
                // Remove currency symbols, spaces, and normalize decimal separator
                // Handle both comma and dot as decimal separators
                text = text.replace(/[^\d.,]/g, '');

                // If there's both comma and dot, determine which is decimal separator
                // European format: 1.234,56 -> American format: 1234.56
                if (text.indexOf(',') > text.indexOf('.')) {
                    // Comma is decimal separator (European format)
                    text = text.replace(/\./g, '').replace(',', '.');
                } else {
                    // Dot is decimal separator (American format)
                    text = text.replace(/,/g, '');
                }

                const value = parseFloat(text);
                return value || 0;
            }

            // Fallback: try to get from l2payData if available
            if (typeof l2payData !== 'undefined' && l2payData.cartTotal) {
                return parseFloat(l2payData.cartTotal) || 0;
            }

            return 0;
        },

        /**
         * Set button state
         */
        setButtonState: function(state) {
            const btn = this.getElements().connectBtn;
            const network = this.getSelectedNetwork();
            const symbol = this.paymentType === 'usdc' ? 'USDC' : (network ? network.symbol : 'ETH');

            switch(state) {
                case 'connect':
                    btn.prop('disabled', false)
                       .removeClass('processing complete')
                       .html('<span class="l2pay-btn-icon">&#129418;</span> ' + l2payData.i18n.connectWallet);
                    break;

                case 'connecting':
                    btn.prop('disabled', true)
                       .addClass('processing')
                       .html('<span class="l2pay-spinner"></span> Connecting...');
                    break;

                case 'ready':
                    btn.prop('disabled', false)
                       .removeClass('processing')
                       .html('<span class="l2pay-btn-icon">&#128176;</span> Pay with ' + symbol);
                    break;

                case 'processing':
                    btn.prop('disabled', true)
                       .addClass('processing')
                       .html('<span class="l2pay-spinner"></span> ' + l2payData.i18n.processing);
                    break;

                case 'approving':
                    btn.prop('disabled', true)
                       .addClass('processing')
                       .html('<span class="l2pay-spinner"></span> ' + l2payData.i18n.approving);
                    break;

                case 'confirming':
                    btn.prop('disabled', true)
                       .addClass('processing')
                       .html('<span class="l2pay-spinner"></span> ' + l2payData.i18n.waitingConfirmation);
                    break;

                case 'complete':
                    btn.prop('disabled', true)
                       .removeClass('processing')
                       .addClass('complete')
                       .html('<span class="l2pay-btn-icon">&#9989;</span> ' + l2payData.i18n.paymentComplete);
                    break;
            }
        },

        /**
         * Show error message
         */
        showError: function(message) {
            this.getElements().errorEl.text(message).show();
        },

        /**
         * Hide error message
         */
        hideError: function() {
            this.getElements().errorEl.hide().text('');
        },

        /**
         * Disconnect wallet
         */
        disconnect: function() {
            this.isConnected = false;
            this.account = null;
            this.ethAmount = null;
            this.weiAmount = null;
            this.usdcAmount = null;
            this.usdcSmallestUnit = null;
            this.updateUI();
            this.getElements().priceDisplay.hide();
            this.setButtonState('connect');
        },

        /**
         * Validate payment before form submission
         */
        validatePayment: function() {
            const txHash = this.getElements().txHashInput.val();

            if (!txHash) {
                this.showError('Please complete the payment first.');
                return false;
            }

            return true;
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        L2Pay.init();
    });

    // Expose globally for debugging
    window.L2Pay = L2Pay;

})(jQuery);
