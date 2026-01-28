/**
 * Layer Crypto Checkout - Wallet Integration (MetaMask + WalletConnect)
 *
 * Handles wallet connection, price conversion, and payment processing
 * Supports ETH and USDC payments on multiple blockchain networks
 * Uses Web3Modal v1.x for multi-wallet support
 */

(function($) {
    'use strict';

    // State management
    const LCCP = {
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
        connectionType: null, // 'metamask' or 'walletconnect'
        walletConfig: null,
        wcProviderClass: null,
        wcProvider: null,
        provider: null,
        listenersSetup: false,

        /**
         * Initialize the payment handler
         */
        init: function() {
            const self = this;

            // Initialize Web3Modal for WalletConnect support
            this.initWeb3Modal();

            // Use event delegation for dynamic elements
            $(document).on('click', '#lccp-connect-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.handleConnectClick();
            });

            // Disconnect button
            $(document).on('click', '#lccp-disconnect-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.disconnect();
            });

            // Listen for network selector change
            $(document).on('change', '#lccp-network', function() {
                const networkKey = $(this).val();
                self.onNetworkChange(networkKey);
            });

            // Listen for payment type change
            $(document).on('change', 'input[name="lccp_payment_type"]', function() {
                const paymentType = $(this).val();
                self.onPaymentTypeChange(paymentType);
            });

            // Listen for payment method change
            $(document).on('change', 'input[name="payment_method"]', function() {
                if ($(this).val() === 'layer-crypto-checkout') {
                    self.onPaymentMethodSelected();
                }
            });

            // Check if already selected on page load
            $(document).ready(function() {
                setTimeout(function() {
                    if ($('input[name="payment_method"]:checked').val() === 'layer-crypto-checkout') {
                        self.onPaymentMethodSelected();
                    }
                }, 500);
            });

            // Listen for checkout updates
            $(document.body).on('updated_checkout', function() {
                if ($('input[name="payment_method"]:checked').val() === 'layer-crypto-checkout') {
                    self.onPaymentMethodSelected();
                }
            });

            // Form validation
            $(document).on('checkout_place_order_layer-crypto-checkout', function() {
                return self.validatePayment();
            });

        },

        /**
         * Initialize wallet connection options
         */
        initWeb3Modal: function() {
            const self = this;

            // Check if WalletConnect libraries are loaded
            const checkLibraries = function() {
                if (window.LCCPWalletConfig) {
                    self.walletConfig = window.LCCPWalletConfig;
                    console.log('LCCP: Wallet config ready');

                    // Check for WalletConnect ethereum provider
                    if (window.EthereumProvider) {
                        self.wcProviderClass = window.EthereumProvider.EthereumProvider || window.EthereumProvider;
                        console.log('LCCP: WalletConnect provider available');
                    }

                    return true;
                }
                return false;
            };

            // Try immediately
            if (!checkLibraries()) {
                let attempts = 0;
                const interval = setInterval(function() {
                    attempts++;
                    if (checkLibraries() || attempts >= 10) {
                        clearInterval(interval);
                    }
                }, 500);
            }

            // Create wallet selection modal
            this.createWalletModal();
        },

        /**
         * Create the wallet selection modal HTML
         */
        createWalletModal: function() {
            if ($('#lccp-wallet-modal').length) return;

            const modalHtml = `
                <div id="lccp-wallet-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
                    <div style="background:white; border-radius:16px; padding:24px; max-width:360px; width:90%; box-shadow:0 10px 40px rgba(0,0,0,0.2);">
                        <h3 style="margin:0 0 20px; font-size:18px; text-align:center;">Connect Wallet</h3>
                        <button id="lccp-connect-metamask" style="width:100%; padding:14px; margin-bottom:12px; border:1px solid #e5e5e5; border-radius:12px; background:white; cursor:pointer; display:flex; align-items:center; gap:12px; font-size:16px; transition:background 0.2s;">
                            <img src="${lccpData.pluginUrl}assets/images/metamask.svg" style="width:32px; height:32px;">
                            <span>MetaMask</span>
                        </button>
                        <button id="lccp-connect-walletconnect" style="width:100%; padding:14px; border:1px solid #e5e5e5; border-radius:12px; background:white; cursor:pointer; display:flex; align-items:center; gap:12px; font-size:16px; transition:background 0.2s;">
                            <img src="${lccpData.pluginUrl}assets/images/walletconnect.png" style="width:32px; height:32px; border-radius:8px;">
                            <span>WalletConnect</span>
                        </button>
                        <button id="lccp-modal-close" style="width:100%; padding:10px; margin-top:16px; border:none; background:none; cursor:pointer; color:#666; font-size:14px;">Cancel</button>
                    </div>
                </div>
            `;
            $('body').append(modalHtml);

            // Add hover effects
            $('#lccp-connect-metamask, #lccp-connect-walletconnect').hover(
                function() { $(this).css('background', '#f5f5f5'); },
                function() { $(this).css('background', 'white'); }
            );

            // Event handlers
            const self = this;
            $('#lccp-wallet-modal').on('click', function(e) {
                if (e.target === this) self.hideWalletModal();
            });
            $('#lccp-modal-close').on('click', function() {
                self.hideWalletModal();
            });
            $('#lccp-connect-metamask').on('click', function() {
                self.hideWalletModal();
                self.connectMetaMask();
            });
            $('#lccp-connect-walletconnect').on('click', function() {
                self.hideWalletModal();
                self.connectWalletConnect();
            });
        },

        /**
         * Show wallet selection modal
         */
        showWalletModal: function() {
            $('#lccp-wallet-modal').css('display', 'flex');
        },

        /**
         * Hide wallet selection modal
         */
        hideWalletModal: function() {
            $('#lccp-wallet-modal').hide();
            this.setButtonState('connect');
        },

        /**
         * Get the currently selected network configuration
         */
        getSelectedNetwork: function() {
            const networkKey = $('#lccp-network').val() || lccpData.defaultNetwork || 'sepolia';
            const network = lccpData.networks[networkKey];
            if (!network) {
                console.error('LCCP: Network not found:', networkKey);
                return lccpData.networks['sepolia'] || {};
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
            const network = lccpData.networks[networkKey];
            if (!network) {
                console.error('LCCP: Network not found:', networkKey);
                return;
            }

            this.selectedNetwork = network;

            // Reset payment state
            this.ethAmount = null;
            this.weiAmount = null;
            this.usdcAmount = null;
            this.usdcSmallestUnit = null;

            // If connected, switch network and refresh conversion
            if (this.isConnected && this.provider) {
                try {
                    await self.ensureCorrectNetwork();
                    await self.fetchConversion();
                } catch (err) {
                    console.error('LCCP: Network switch failed:', err);
                    self.showError('Failed to switch network. Please switch manually in your wallet.');
                }
            }
        },

        /**
         * Called when payment type changes (ETH vs USDC)
         */
        onPaymentTypeChange: async function(paymentType) {
            this.paymentType = paymentType;

            // Update the hidden field for form submission
            $('#lccp-payment-type').val(paymentType);

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
         * Sync network selector when user changes chain in wallet
         */
        syncNetworkSelector: function(chainId) {
            const networks = lccpData.networks;
            for (const key in networks) {
                if (networks[key].chainId === chainId) {
                    $('#lccp-network').val(key);
                    this.selectedNetwork = networks[key];
                    this.fetchConversion();
                    return;
                }
            }
            this.showError('Please select a supported network.');
        },

        /**
         * Called when Layer Crypto Checkout payment method is selected
         */
        onPaymentMethodSelected: function() {
            // Initialize selected network
            const network = this.getSelectedNetwork();
            this.selectedNetwork = network;

            // Check current payment type
            const paymentType = $('input[name="lccp_payment_type"]:checked').val() || 'eth';
            this.paymentType = paymentType;

            // Check if already connected (e.g., from previous session)
            this.checkExistingConnection();

            if (this.isConnected) {
                this.updateUI();
                this.fetchConversion();
            }
        },

        /**
         * Check for existing wallet connection
         */
        checkExistingConnection: function() {
            const self = this;

            // Web3Modal handles cached provider reconnection in initWeb3Modal
            // Here we just check for injected provider
            if (window.ethereum) {
                let provider = null;

                if (window.ethereum.providers && window.ethereum.providers.length) {
                    provider = window.ethereum.providers.find(p => p.isMetaMask);
                } else if (window.ethereum.isMetaMask) {
                    provider = window.ethereum;
                } else {
                    provider = window.ethereum;
                }

                if (provider) {
                    this.provider = provider;
                    this.setupProviderListeners();

                    // Check if already connected
                    provider.request({ method: 'eth_accounts' }).then(function(accounts) {
                        if (accounts && accounts.length > 0) {
                            self.account = accounts[0];
                            self.isConnected = true;
                            self.updateUI();
                            self.fetchConversion();
                        }
                    }).catch(function() {
                        // Not connected, that's fine
                    });
                }
            }
        },

        /**
         * Setup provider event listeners
         */
        setupProviderListeners: function() {
            const self = this;

            if (this.provider && !this.listenersSetup) {
                if (this.provider.on) {
                    this.provider.on('accountsChanged', function(accounts) {
                        if (accounts.length === 0) {
                            self.disconnect();
                        } else {
                            self.account = accounts[0];
                            self.updateUI();
                        }
                    });

                    this.provider.on('chainChanged', function(chainId) {
                        self.syncNetworkSelector(chainId);
                    });

                    this.provider.on('disconnect', function() {
                        self.disconnect();
                    });
                }

                this.listenersSetup = true;
            }
        },

        /**
         * Get DOM elements (fresh lookup each time)
         */
        getElements: function() {
            return {
                container: $('#lccp-payment-container'),
                connectBtn: $('#lccp-connect-btn'),
                networkSelect: $('#lccp-network'),
                walletStatus: $('#lccp-wallet-status'),
                priceDisplay: $('#lccp-price-display'),
                cryptoAmountEl: $('#lccp-crypto-amount'),
                rateEl: $('#lccp-rate'),
                errorEl: $('#lccp-error'),
                txHashInput: $('#lccp-tx-hash'),
                cryptoPaidInput: $('#lccp-eth-paid'),
                walletInput: $('#lccp-wallet-address'),
                expectedAmountInput: $('#lccp-expected-amount'),
            };
        },

        /**
         * Handle connect button click
         */
        handleConnectClick: async function() {
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
         * Connect wallet - shows wallet selection modal
         */
        connect: async function() {
            this.hideError();

            // Show wallet selection modal
            this.showWalletModal();
        },

        /**
         * Connect via MetaMask (injected provider)
         */
        connectMetaMask: async function() {
            const self = this;

            try {
                this.setButtonState('connecting');

                if (!window.ethereum) {
                    this.showError(lccpData.i18n.noWalletFound);
                    this.setButtonState('connect');
                    return;
                }

                let provider = null;
                if (window.ethereum.providers && window.ethereum.providers.length) {
                    provider = window.ethereum.providers.find(p => p.isMetaMask);
                }
                if (!provider) {
                    provider = window.ethereum;
                }

                this.provider = provider;
                this.setupProviderListeners();

                const accounts = await provider.request({
                    method: 'eth_requestAccounts'
                });

                if (accounts.length === 0) {
                    throw new Error('No accounts found');
                }

                this.account = accounts[0];
                this.isConnected = true;
                this.connectionType = 'metamask';

                await this.onConnectionSuccess();

            } catch (error) {
                console.error('LCCP: MetaMask connection error:', error);
                if (error.code === 4001) {
                    // User rejected
                    this.setButtonState('connect');
                } else {
                    this.showError(lccpData.i18n.transactionFailed);
                    this.setButtonState('connect');
                }
            }
        },

        /**
         * Connect via WalletConnect
         */
        connectWalletConnect: async function() {
            const self = this;

            try {
                this.setButtonState('connecting');

                // Try different possible export names
                if (!this.wcProviderClass) {
                    var wcModule = window['@walletconnect/ethereum-provider'];
                    if (wcModule) {
                        this.wcProviderClass = wcModule.EthereumProvider || wcModule.default || wcModule;
                    }
                }

                if (!this.wcProviderClass) {
                    this.showError('WalletConnect not available. Please use MetaMask.');
                    this.setButtonState('connect');
                    return;
                }

                const config = this.walletConfig || window.LCCPWalletConfig;
                if (!config || !config.projectId) {
                    this.showError('WalletConnect configuration missing.');
                    this.setButtonState('connect');
                    return;
                }

                // Initialize WalletConnect provider
                const provider = await this.wcProviderClass.init({
                    projectId: config.projectId,
                    chains: [config.defaultChainId],
                    optionalChains: config.chainIds,
                    showQrModal: true,
                    qrModalOptions: {
                        explorerRecommendedWalletIds: [
                            'c57ca95b47569778a828d19178114f4db188b89b763c899ba0be274e97267d96', // MetaMask
                            '4622a2b2d6af1c9844944291e5e7351a6aa24cd7b23099efac1b2fd875da31a0', // Trust Wallet
                            '1ae92b26df02f0abca6304df07debccd18262fdf5fe82daa81593582dac9a369', // Rainbow
                            '18388be9ac2d02726dbac9777c96efaac06d744b2f6d580fccdd4127a6d01fd1'  // Rabby Wallet
                        ]
                    },
                    metadata: {
                        name: 'Layer Crypto Checkout',
                        description: 'Crypto Payments',
                        url: window.location.origin,
                        icons: []
                    }
                });

                // Enable session (shows QR modal)
                await provider.enable();

                this.provider = provider;
                this.wcProvider = provider;
                this.setupProviderListeners();

                const accounts = await provider.request({ method: 'eth_accounts' });

                if (!accounts || accounts.length === 0) {
                    throw new Error('No accounts found');
                }

                this.account = accounts[0];
                this.isConnected = true;
                this.connectionType = 'walletconnect';

                await this.onConnectionSuccess();

            } catch (error) {
                console.error('LCCP: WalletConnect error:', error);
                if (error.message && error.message.includes('User rejected')) {
                    this.setButtonState('connect');
                } else {
                    this.showError('WalletConnect connection failed. Try MetaMask instead.');
                    this.setButtonState('connect');
                }
            }
        },

        /**
         * Legacy connect for direct provider (kept for compatibility)
         */
        connectDirect: async function() {
            const self = this;
            const elements = this.getElements();

            try {
                this.setButtonState('connecting');
                this.hideError();

                if (!window.ethereum) {
                    this.showError(lccpData.i18n.noWalletFound);
                    this.setButtonState('connect');
                    return;
                }

                let provider = null;
                if (window.ethereum.providers && window.ethereum.providers.length) {
                    provider = window.ethereum.providers.find(p => p.isMetaMask);
                }
                if (!provider) {
                    provider = window.ethereum;
                }

                this.provider = provider;
                this.setupProviderListeners();

                const accounts = await provider.request({
                    method: 'eth_requestAccounts'
                });

                if (accounts.length === 0) {
                    throw new Error('No accounts found');
                }

                this.account = accounts[0];
                this.isConnected = true;

                await this.onConnectionSuccess();

            } catch (error) {
                console.error('LCCP: Connection error:', error);
                if (error.code === 4001) {
                    // User rejected the request
                    this.showError('Connection cancelled by user.');
                } else {
                    this.showError(error.message || 'Connection failed');
                }
                this.setButtonState('connect');
            }
        },

        /**
         * Handle successful connection
         */
        onConnectionSuccess: async function() {
            const elements = this.getElements();

            await this.ensureCorrectNetwork();
            this.updateUI();
            elements.walletInput.val(this.account);
            await this.fetchConversion();
            this.setButtonState('ready');
        },

        /**
         * Ensure we're on the correct network
         */
        ensureCorrectNetwork: async function() {
            if (!this.provider) return;

            const provider = this.provider;
            const network = this.getSelectedNetwork();
            const targetChainId = network.chainId;

            try {
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
            } catch (error) {
                console.warn('LCCP: Could not switch network:', error);
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
                    .html('<span class="lccp-status-icon">&#9989;</span>' +
                          '<span class="lccp-status-text">Connected: ' + shortAddress + ' (' + network.name + ')</span>');
            } else {
                elements.walletStatus
                    .removeClass('connected')
                    .addClass('disconnected')
                    .html('<span class="lccp-status-icon">&#128274;</span>' +
                          '<span class="lccp-status-text">' + lccpData.i18n.connectWallet + '</span>');
            }
        },

        /**
         * Update button text based on payment type
         */
        updateButtonText: function() {
            if (!this.isConnected) return;

            const btn = this.getElements().connectBtn;
            if (this.paymentType === 'usdc') {
                btn.html('<span class="lccp-btn-icon">&#128176;</span> ' + lccpData.i18n.payWithUsdc);
            } else {
                const network = this.getSelectedNetwork();
                btn.html('<span class="lccp-btn-icon">&#128176;</span> Pay with ' + (network.symbol || 'ETH'));
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
                    lccpData.restUrl + endpoint + '?amount=' + cartTotal + '&currency=' + lccpData.currency
                );

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || lccpData.i18n.conversionError);
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

                // Store expected amount in smallest units for backend verification
                const expectedSmallestUnit = this.paymentType === 'usdc' ? this.usdcSmallestUnit : this.weiAmount;
                elements.expectedAmountInput.val(expectedSmallestUnit || '');

                this.hideError();
                this.updateButtonText();

            } catch (error) {
                console.error('LCCP: Conversion error:', error);
                this.showError(lccpData.i18n.conversionError);
            }
        },

        /**
         * Validate the checkout form before payment
         */
        validateForm: function() {
            const $form = $('form.checkout');
            const missingFields = [];
            const shipToDifferent = $('#ship-to-different-address-checkbox').is(':checked');

            $form.find('.validate-required').each(function() {
                const $field = $(this);

                // Skip hidden fields (e.g., state field hidden when country has no states)
                // Also skip shipping fields when "ship to different address" is unchecked
                if ($field.is(':hidden') || $field.closest('.shipping_address').length && !shipToDifferent) {
                    $field.removeClass('woocommerce-invalid woocommerce-invalid-required-field');
                    return;
                }

                // Check select elements first (including Select2-hidden selects)
                const $select = $field.find('select');
                if ($select.length) {
                    if ($select.val()) {
                        $field.removeClass('woocommerce-invalid woocommerce-invalid-required-field');
                        return;
                    }
                }

                // Check visible inputs and textareas
                const $input = $field.find('input, textarea').not('[type="hidden"]').filter(':visible');
                if ($input.length) {
                    if ($input.val() && $input.val().trim()) {
                        $field.removeClass('woocommerce-invalid woocommerce-invalid-required-field');
                        return;
                    }
                }

                // If we have no visible input/select, or the field has a value, skip
                if (!$select.length && !$input.length) {
                    $field.removeClass('woocommerce-invalid woocommerce-invalid-required-field');
                    return;
                }

                // Field is empty - mark as invalid
                $field.addClass('woocommerce-invalid woocommerce-invalid-required-field');
                const label = $field.find('label').first().clone().children().remove().end().text().trim().replace(/\s*\*$/, '');
                if (label) {
                    missingFields.push(label);
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
                if (missingFields.length > 0) {
                    this.showError('Please fill in: ' + missingFields.join(', '));
                } else {
                    this.showError('Please fill in all required fields before paying.');
                }
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

            if (!this.provider) {
                this.showError('Wallet not connected. Please reconnect.');
                return;
            }

            try {
                this.setButtonState('processing');
                this.hideError();

                // Ensure wallet is on the correct network before sending
                await this.ensureCorrectNetwork();

                // Verify the wallet actually switched
                const currentChainId = await this.provider.request({ method: 'eth_chainId' });
                const expectedChainId = this.getSelectedNetwork().chainId;
                if (currentChainId !== expectedChainId) {
                    // Wallet is on a different network - sync selector to actual network
                    const networks = lccpData.networks;
                    let actualNetworkKey = null;
                    for (const key in networks) {
                        if (networks[key].chainId === currentChainId) {
                            actualNetworkKey = key;
                            break;
                        }
                    }
                    if (actualNetworkKey) {
                        $('#lccp-network').val(actualNetworkKey);
                        this.selectedNetwork = networks[actualNetworkKey];
                    } else {
                        throw new Error('Your wallet is on an unsupported network. Please switch to a supported network.');
                    }
                }

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

                // Store expected amount in smallest units for backend verification
                const expectedSmallestUnit = this.paymentType === 'usdc' ? this.usdcSmallestUnit : this.weiAmount;
                elements.expectedAmountInput.val(expectedSmallestUnit || '');

                // Also store payment type
                $('#lccp-payment-type').val(this.paymentType);

                // Read the ACTUAL chain from the wallet after TX to ensure backend verifies on the correct network
                const actualChainId = await this.provider.request({ method: 'eth_chainId' });
                const networks = lccpData.networks;
                for (const key in networks) {
                    if (networks[key].chainId === actualChainId) {
                        $('#lccp-network').val(key);
                        break;
                    }
                }

                this.setButtonState('complete');

                // Use a short delay to ensure UI updates, then submit
                setTimeout(function() {
                    // Verify values are set before submitting
                    if (!elements.txHashInput.val()) {
                        console.error('LCCP: TX hash not set before submit!');
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
                        console.error('LCCP: Checkout form not found');
                        self.showError('Checkout form not found. Please refresh and try again.');
                    }
                }, 500);

            } catch (error) {
                console.error('LCCP: Payment error:', error);
                this.showError(error.message || lccpData.i18n.transactionFailed);
                this.setButtonState('ready');
            }
        },

        /**
         * Process USDC payment (approve + transfer)
         */
        processUsdcPayment: async function(orderId) {
            const provider = this.provider;
            const network = this.getSelectedNetwork();
            const merchantAddress = lccpData.merchantAddress;

            if (!merchantAddress || !merchantAddress.match(/^0x[a-fA-F0-9]{40}$/)) {
                throw new Error(lccpData.i18n.merchantNotConfigured);
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
                throw new Error(lccpData.i18n.insufficientUsdc);
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
            const merchantAddress = lccpData.merchantAddress;

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
            const merchantAddress = lccpData.merchantAddress;

            if (!merchantAddress || !merchantAddress.match(/^0x[a-fA-F0-9]{40}$/)) {
                throw new Error(lccpData.i18n.merchantNotConfigured);
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

            // Fallback: try to get from lccpData if available
            if (typeof lccpData !== 'undefined' && lccpData.cartTotal) {
                return parseFloat(lccpData.cartTotal) || 0;
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
                       .html('<span class="lccp-btn-icon">&#129418;</span> ' + lccpData.i18n.connectWallet);
                    break;

                case 'connecting':
                    btn.prop('disabled', true)
                       .addClass('processing')
                       .html('<span class="lccp-spinner"></span> ' + (lccpData.i18n.connecting || 'Connecting...'));
                    break;

                case 'ready':
                    btn.prop('disabled', false)
                       .removeClass('processing')
                       .html('<span class="lccp-btn-icon">&#128176;</span> Pay with ' + symbol);
                    break;

                case 'processing':
                    btn.prop('disabled', true)
                       .addClass('processing')
                       .html('<span class="lccp-spinner"></span> ' + lccpData.i18n.processing);
                    break;

                case 'approving':
                    btn.prop('disabled', true)
                       .addClass('processing')
                       .html('<span class="lccp-spinner"></span> ' + lccpData.i18n.approving);
                    break;

                case 'confirming':
                    btn.prop('disabled', true)
                       .addClass('processing')
                       .html('<span class="lccp-spinner"></span> ' + lccpData.i18n.waitingConfirmation);
                    break;

                case 'complete':
                    btn.prop('disabled', true)
                       .removeClass('processing')
                       .addClass('complete')
                       .html('<span class="lccp-btn-icon">&#9989;</span> ' + lccpData.i18n.paymentComplete);
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

            // Disconnect WalletConnect provider if applicable
            if (this.wcProvider && this.wcProvider.disconnect) {
                try {
                    this.wcProvider.disconnect();
                } catch (e) {
                    // Ignore disconnect errors
                }
            }

            this.connectionType = null;
            this.wcProvider = null;
            this.provider = null;

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
        LCCP.init();
    });

    // Expose globally for debugging
    window.LCCP = LCCP;

})(jQuery);
