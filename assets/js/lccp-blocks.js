/**
 * Layer Crypto Checkout - WooCommerce Block Checkout Integration
 *
 * React component (no JSX, uses wp.element.createElement) for the
 * WooCommerce Block-based Checkout. Ports the wallet connection,
 * price conversion, and payment processing logic from lccp-checkout.js.
 */
(function () {
    'use strict';

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var useCallback = wp.element.useCallback;
    var useRef = wp.element.useRef;
    var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
    var decodeEntities = wp.htmlEntities.decodeEntities;

    var data = lccpBlocksData;
    var i18n = data.i18n;

    /* ─── Helpers ────────────────────────────────────────────────── */

    function getNetworkByKey(key) {
        return data.networks[key] || null;
    }

    function findNetworkKeyByChainId(chainId) {
        for (var k in data.networks) {
            if (data.networks[k].chainId === chainId) return k;
        }
        return null;
    }

    function shortAddr(addr) {
        if (!addr) return '';
        return addr.slice(0, 6) + '...' + addr.slice(-4);
    }

    function initWalletConnect() {
        if (window.LCCPWalletConfig) return window.LCCPWalletConfig;
        return null;
    }

    function getWcProviderClass() {
        if (window.EthereumProvider) {
            return window.EthereumProvider.EthereumProvider || window.EthereumProvider;
        }
        var wcModule = window['@walletconnect/ethereum-provider'];
        if (wcModule) return wcModule.EthereumProvider || wcModule.default || wcModule;
        return null;
    }

    /* --- Network switching --- */
    async function ensureCorrectNetwork(provider, network) {
        if (!provider) return;
        var targetChainId = network.chainId;
        try {
            var currentChainId = await provider.request({ method: 'eth_chainId' });
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
                                nativeCurrency: { name: network.symbol, symbol: network.symbol, decimals: 18 }
                            }],
                        });
                    } else {
                        throw switchError;
                    }
                }
            }
        } catch (err) {
            console.warn('LCCP Blocks: Could not switch network:', err);
        }
    }

    /* --- Wait for tx receipt --- */
    async function waitForTransaction(provider, txHash, maxAttempts) {
        maxAttempts = maxAttempts || 60;
        for (var i = 0; i < maxAttempts; i++) {
            var receipt = await provider.request({
                method: 'eth_getTransactionReceipt',
                params: [txHash],
            });
            if (receipt) {
                if (receipt.status === '0x1') return receipt;
                throw new Error('Transaction failed');
            }
            await new Promise(function (r) { setTimeout(r, 2000); });
        }
        throw new Error('Transaction confirmation timeout');
    }

    /* --- USDC helpers --- */
    async function getUsdcBalance(provider, usdcAddress, account) {
        var callData = '0x70a08231' + account.slice(2).toLowerCase().padStart(64, '0');
        return await provider.request({ method: 'eth_call', params: [{ to: usdcAddress, data: callData }, 'latest'] });
    }

    async function getUsdcAllowance(provider, usdcAddress, spender, account) {
        var callData = '0xdd62ed3e' +
            account.slice(2).toLowerCase().padStart(64, '0') +
            spender.slice(2).toLowerCase().padStart(64, '0');
        return await provider.request({ method: 'eth_call', params: [{ to: usdcAddress, data: callData }, 'latest'] });
    }

    async function approveUsdc(provider, usdcAddress, spender, amount, account) {
        var approvalAmount = BigInt(amount) * 110n / 100n;
        var callData = '0x095ea7b3' +
            spender.slice(2).toLowerCase().padStart(64, '0') +
            approvalAmount.toString(16).padStart(64, '0');
        var txParams = { to: usdcAddress, from: account, data: callData };
        try {
            var gas = await provider.request({ method: 'eth_estimateGas', params: [txParams] });
            txParams.gas = '0x' + Math.floor(parseInt(gas, 16) * 1.2).toString(16);
        } catch (e) {
            txParams.gas = '0x20000';
        }
        return await provider.request({ method: 'eth_sendTransaction', params: [txParams] });
    }

    /* --- Send ETH transaction --- */
    async function sendEthTransaction(provider, account, network, weiAmount) {
        var merchantAddress = data.merchantAddress;
        if (!merchantAddress || !merchantAddress.match(/^0x[a-fA-F0-9]{40}$/)) {
            throw new Error(i18n.merchantNotConfigured);
        }
        var orderId = Date.now();
        var selector = '0x31cbf5e3';
        var encodedOrderId = orderId.toString(16).padStart(64, '0');
        var encodedMerchant = merchantAddress.slice(2).toLowerCase().padStart(64, '0');
        var txParams = {
            to: network.contract,
            from: account,
            value: '0x' + BigInt(weiAmount).toString(16),
            data: selector + encodedOrderId + encodedMerchant,
        };
        try {
            var gas = await provider.request({ method: 'eth_estimateGas', params: [txParams] });
            txParams.gas = '0x' + Math.floor(parseInt(gas, 16) * 1.2).toString(16);
        } catch (e) {
            txParams.gas = '0x30000';
        }
        var txHash = await provider.request({ method: 'eth_sendTransaction', params: [txParams] });
        await waitForTransaction(provider, txHash);
        return txHash;
    }

    /* --- Send token (USDC) transaction --- */
    async function sendTokenTransaction(provider, account, network, tokenAddress, amount) {
        var merchantAddress = data.merchantAddress;
        var orderId = Date.now();
        var selector = '0xd6bcaa76';
        var encodedOrderId = orderId.toString(16).padStart(64, '0');
        var encodedMerchant = merchantAddress.slice(2).toLowerCase().padStart(64, '0');
        var encodedToken = tokenAddress.slice(2).toLowerCase().padStart(64, '0');
        var encodedAmount = BigInt(amount).toString(16).padStart(64, '0');
        var txParams = {
            to: network.contract,
            from: account,
            data: selector + encodedOrderId + encodedMerchant + encodedToken + encodedAmount,
        };
        try {
            var gas = await provider.request({ method: 'eth_estimateGas', params: [txParams] });
            txParams.gas = '0x' + Math.floor(parseInt(gas, 16) * 1.2).toString(16);
        } catch (e) {
            txParams.gas = '0x50000';
        }
        return await provider.request({ method: 'eth_sendTransaction', params: [txParams] });
    }

    /* ─── Content Component ──────────────────────────────────────── */

    var Content = function (props) {
        var eventRegistration = props.eventRegistration;
        var emitResponse = props.emitResponse;
        var onPaymentSetup = eventRegistration.onPaymentSetup;

        // Billing total from block checkout props (in minor units, e.g. cents).
        var billing = props.billing;
        var cartTotalRaw = billing && billing.cartTotal ? billing.cartTotal.value : 0;
        var cartTotalCurrency = billing && billing.currency ? billing.currency.code : data.currency;
        // WooCommerce Blocks passes cartTotal.value in minor units (cents).
        var cartTotalDecimal = parseInt(cartTotalRaw, 10) / 100;

        // State
        var stateRef = useRef({});
        var _connected = useState(false);
        var isConnected = _connected[0]; var setIsConnected = _connected[1];
        var _account = useState(null);
        var account = _account[0]; var setAccount = _account[1];
        var _networkKey = useState(data.defaultNetwork);
        var networkKey = _networkKey[0]; var setNetworkKey = _networkKey[1];
        var _paymentType = useState('eth');
        var paymentType = _paymentType[0]; var setPaymentType = _paymentType[1];
        var _status = useState('idle');
        var status = _status[0]; var setStatus = _status[1];
        var _error = useState('');
        var error = _error[0]; var setError = _error[1];
        var _cryptoDisplay = useState('');
        var cryptoDisplay = _cryptoDisplay[0]; var setCryptoDisplay = _cryptoDisplay[1];
        var _rateDisplay = useState('');
        var rateDisplay = _rateDisplay[0]; var setRateDisplay = _rateDisplay[1];
        var _showModal = useState(false);
        var showModal = _showModal[0]; var setShowModal = _showModal[1];
        var _showPrice = useState(false);
        var showPrice = _showPrice[0]; var setShowPrice = _showPrice[1];

        // Refs for mutable state that doesn't need re-renders
        var providerRef = useRef(null);
        var wcProviderRef = useRef(null);
        var connectionTypeRef = useRef(null);
        var listenersSetupRef = useRef(false);

        // Payment data refs (need current values in async flows)
        var ethAmountRef = useRef(null);
        var weiAmountRef = useRef(null);
        var usdcAmountRef = useRef(null);
        var usdcSmallestUnitRef = useRef(null);
        var txHashRef = useRef('');
        var accountRef = useRef(null);
        var networkKeyRef = useRef(data.defaultNetwork);
        var paymentTypeRef = useRef('eth');

        // Keep refs in sync with state
        useEffect(function () { accountRef.current = account; }, [account]);
        useEffect(function () { networkKeyRef.current = networkKey; }, [networkKey]);
        useEffect(function () { paymentTypeRef.current = paymentType; }, [paymentType]);

        /* --- Get current network object --- */
        var getNetwork = useCallback(function () {
            return getNetworkByKey(networkKeyRef.current) || getNetworkByKey(data.defaultNetwork) || {};
        }, []);

        /* --- Setup provider event listeners --- */
        var setupListeners = useCallback(function (provider) {
            if (!provider || listenersSetupRef.current) return;
            if (provider.on) {
                provider.on('accountsChanged', function (accounts) {
                    if (accounts.length === 0) {
                        doDisconnect();
                    } else {
                        setAccount(accounts[0]);
                        accountRef.current = accounts[0];
                    }
                });
                provider.on('chainChanged', function (chainId) {
                    var key = findNetworkKeyByChainId(chainId);
                    if (key) {
                        setNetworkKey(key);
                        networkKeyRef.current = key;
                    }
                });
                provider.on('disconnect', function () {
                    doDisconnect();
                });
            }
            listenersSetupRef.current = true;
        }, []);

        /* --- Disconnect --- */
        var doDisconnect = useCallback(function () {
            setIsConnected(false);
            setAccount(null);
            accountRef.current = null;
            ethAmountRef.current = null;
            weiAmountRef.current = null;
            usdcAmountRef.current = null;
            usdcSmallestUnitRef.current = null;
            txHashRef.current = '';
            setShowPrice(false);
            setStatus('idle');
            setCryptoDisplay('');
            setRateDisplay('');

            if (wcProviderRef.current && wcProviderRef.current.disconnect) {
                try { wcProviderRef.current.disconnect(); } catch (e) { /* ignore */ }
            }
            connectionTypeRef.current = null;
            wcProviderRef.current = null;
            providerRef.current = null;
            listenersSetupRef.current = false;
        }, []);

        /* --- Fetch conversion --- */
        var fetchConversion = useCallback(async function () {
            var net = getNetwork();
            var total = cartTotalDecimal;

            if (!total || total <= 0) {
                // Fallback to localized cart total
                total = parseFloat(data.cartTotal) || 0;
            }
            if (!total || total <= 0) {
                setError('Could not determine cart total. Please refresh the page.');
                return;
            }

            setCryptoDisplay('Loading...');
            setRateDisplay('Loading...');

            var pType = paymentTypeRef.current;
            var endpoint = pType === 'usdc' ? 'convert-usdc' : 'convert';
            var symbol = pType === 'usdc' ? 'USDC' : (net.symbol || 'ETH');

            try {
                var resp = await fetch(data.restUrl + endpoint + '?amount=' + total + '&currency=' + (cartTotalCurrency || data.currency));
                var result = await resp.json();

                if (!result.success) throw new Error(result.error || i18n.conversionError);

                if (pType === 'usdc') {
                    usdcAmountRef.current = result.usdc_amount;
                    usdcSmallestUnitRef.current = result.usdc_smallest_unit;
                    if (!usdcSmallestUnitRef.current || usdcSmallestUnitRef.current === '0') {
                        throw new Error('Invalid USDC conversion. Please try again.');
                    }
                    setCryptoDisplay(result.usdc_amount.toFixed(2) + ' ' + symbol);
                    setRateDisplay('1 ' + symbol + ' = 1.00 USD (Rate: 1 ' + result.fiat_currency + ' = ' + result.exchange_rate.toFixed(4) + ' USD)');
                } else {
                    ethAmountRef.current = result.eth_amount;
                    weiAmountRef.current = result.wei_amount;
                    if (!weiAmountRef.current || weiAmountRef.current === '0') {
                        throw new Error('Invalid ETH conversion. Please try again.');
                    }
                    setCryptoDisplay(result.eth_amount.toFixed(6) + ' ' + symbol);
                    setRateDisplay('1 ' + symbol + ' = ' + result.eth_price.toFixed(2) + ' ' + result.fiat_currency);
                }

                setShowPrice(true);
                setError('');
            } catch (err) {
                console.error('LCCP Blocks: Conversion error:', err);
                setError(i18n.conversionError);
            }
        }, [cartTotalDecimal, cartTotalCurrency, getNetwork]);

        /* --- Connect MetaMask --- */
        var connectMetaMask = useCallback(async function () {
            try {
                setStatus('connecting');
                setShowModal(false);
                setError('');

                if (!window.ethereum) {
                    setError(i18n.noWalletFound);
                    setStatus('idle');
                    return;
                }

                var provider = null;
                if (window.ethereum.providers && window.ethereum.providers.length) {
                    provider = window.ethereum.providers.find(function (p) { return p.isMetaMask; });
                }
                if (!provider) provider = window.ethereum;

                providerRef.current = provider;
                setupListeners(provider);

                var accounts = await provider.request({ method: 'eth_requestAccounts' });
                if (!accounts || accounts.length === 0) throw new Error('No accounts found');

                setAccount(accounts[0]);
                accountRef.current = accounts[0];
                setIsConnected(true);
                connectionTypeRef.current = 'metamask';

                await ensureCorrectNetwork(provider, getNetwork());
                await fetchConversion();
                setStatus('ready');
            } catch (err) {
                console.error('LCCP Blocks: MetaMask error:', err);
                if (err.code !== 4001) setError(i18n.transactionFailed);
                setStatus('idle');
            }
        }, [setupListeners, getNetwork, fetchConversion]);

        /* --- Connect WalletConnect --- */
        var connectWalletConnect = useCallback(async function () {
            try {
                setStatus('connecting');
                setShowModal(false);
                setError('');

                var WCProviderClass = getWcProviderClass();
                if (!WCProviderClass) {
                    setError('WalletConnect not available. Please use MetaMask.');
                    setStatus('idle');
                    return;
                }

                var config = initWalletConnect();
                if (!config || !config.projectId) {
                    setError('WalletConnect configuration missing.');
                    setStatus('idle');
                    return;
                }

                var provider = await WCProviderClass.init({
                    projectId: config.projectId,
                    chains: [config.defaultChainId],
                    optionalChains: config.chainIds,
                    showQrModal: true,
                    qrModalOptions: {
                        explorerRecommendedWalletIds: [
                            'c57ca95b47569778a828d19178114f4db188b89b763c899ba0be274e97267d96',
                            '4622a2b2d6af1c9844944291e5e7351a6aa24cd7b23099efac1b2fd875da31a0',
                            '1ae92b26df02f0abca6304df07debccd18262fdf5fe82daa81593582dac9a369',
                            '18388be9ac2d02726dbac9777c96efaac06d744b2f6d580fccdd4127a6d01fd1'
                        ]
                    },
                    metadata: {
                        name: 'Layer Crypto Checkout',
                        description: 'Crypto Payments',
                        url: window.location.origin,
                        icons: []
                    }
                });

                await provider.enable();

                providerRef.current = provider;
                wcProviderRef.current = provider;
                setupListeners(provider);

                var accounts = await provider.request({ method: 'eth_accounts' });
                if (!accounts || accounts.length === 0) throw new Error('No accounts found');

                setAccount(accounts[0]);
                accountRef.current = accounts[0];
                setIsConnected(true);
                connectionTypeRef.current = 'walletconnect';

                await ensureCorrectNetwork(provider, getNetwork());
                await fetchConversion();
                setStatus('ready');
            } catch (err) {
                console.error('LCCP Blocks: WalletConnect error:', err);
                if (!(err.message && err.message.includes('User rejected'))) {
                    setError('WalletConnect connection failed. Try MetaMask instead.');
                }
                setStatus('idle');
            }
        }, [setupListeners, getNetwork, fetchConversion]);

        /* --- Process payment (ETH or USDC) --- */
        var processPayment = useCallback(async function () {
            var provider = providerRef.current;
            if (!provider) {
                setError('Wallet not connected. Please reconnect.');
                return;
            }

            try {
                setStatus('processing');
                setError('');

                var net = getNetwork();
                await ensureCorrectNetwork(provider, net);

                // Verify chain after switch
                var currentChainId = await provider.request({ method: 'eth_chainId' });
                var expectedChainId = net.chainId;
                if (currentChainId !== expectedChainId) {
                    var actualKey = findNetworkKeyByChainId(currentChainId);
                    if (actualKey) {
                        setNetworkKey(actualKey);
                        networkKeyRef.current = actualKey;
                        net = getNetworkByKey(actualKey);
                    } else {
                        throw new Error('Your wallet is on an unsupported network. Please switch to a supported network.');
                    }
                }

                // Refresh conversion
                await fetchConversion();

                var pType = paymentTypeRef.current;

                // Validate amounts
                if (pType === 'usdc' && !usdcSmallestUnitRef.current) {
                    throw new Error('Failed to get USDC conversion. Please try again.');
                }
                if (pType !== 'usdc' && !weiAmountRef.current) {
                    throw new Error('Failed to get ETH conversion. Please try again.');
                }

                // Check minimums
                var MIN_ETH_WEI = '100000000000000';
                var MIN_USDC = '100000';
                if (pType === 'usdc' && BigInt(usdcSmallestUnitRef.current) < BigInt(MIN_USDC)) {
                    throw new Error('Order total is below minimum (0.1 USDC). Please add more items.');
                }
                if (pType !== 'usdc' && BigInt(weiAmountRef.current) < BigInt(MIN_ETH_WEI)) {
                    throw new Error('Order total is below minimum (0.0001 ETH). Please add more items.');
                }

                var txHash;
                var acct = accountRef.current;

                if (pType === 'usdc') {
                    // USDC flow: check balance, allowance, approve, pay
                    var usdcAddr = net.usdcAddress;
                    if (!usdcAddr) throw new Error('USDC not available on this network');

                    var balance = await getUsdcBalance(provider, usdcAddr, acct);
                    if (BigInt(balance) < BigInt(usdcSmallestUnitRef.current)) {
                        throw new Error(i18n.insufficientUsdc);
                    }

                    var allowance = await getUsdcAllowance(provider, usdcAddr, net.contract, acct);
                    if (BigInt(allowance) < BigInt(usdcSmallestUnitRef.current)) {
                        setStatus('approving');
                        var approveTx = await approveUsdc(provider, usdcAddr, net.contract, usdcSmallestUnitRef.current, acct);
                        await waitForTransaction(provider, approveTx);
                    }

                    setStatus('confirming');
                    txHash = await sendTokenTransaction(provider, acct, net, usdcAddr, usdcSmallestUnitRef.current);
                    await waitForTransaction(provider, txHash);
                } else {
                    setStatus('confirming');
                    txHash = await sendEthTransaction(provider, acct, net, weiAmountRef.current);
                }

                // Read actual chain after TX
                var finalChainId = await provider.request({ method: 'eth_chainId' });
                var finalKey = findNetworkKeyByChainId(finalChainId);
                if (finalKey) {
                    setNetworkKey(finalKey);
                    networkKeyRef.current = finalKey;
                }

                txHashRef.current = txHash;
                setStatus('complete');
            } catch (err) {
                console.error('LCCP Blocks: Payment error:', err);
                setError(err.message || i18n.transactionFailed);
                setStatus('ready');
            }
        }, [getNetwork, fetchConversion]);

        /* --- Handle connect button click (state machine) --- */
        var handleButtonClick = useCallback(function () {
            if (!isConnected) {
                setShowModal(true);
                return;
            }
            var pType = paymentTypeRef.current;
            if ((pType === 'eth' && !ethAmountRef.current) || (pType === 'usdc' && !usdcAmountRef.current)) {
                fetchConversion();
                return;
            }
            processPayment();
        }, [isConnected, fetchConversion, processPayment]);

        /* --- Network change handler --- */
        var onNetworkChange = useCallback(async function (newKey) {
            setNetworkKey(newKey);
            networkKeyRef.current = newKey;
            ethAmountRef.current = null;
            weiAmountRef.current = null;
            usdcAmountRef.current = null;
            usdcSmallestUnitRef.current = null;

            if (isConnected && providerRef.current) {
                try {
                    await ensureCorrectNetwork(providerRef.current, getNetworkByKey(newKey));
                    await fetchConversion();
                } catch (err) {
                    setError('Failed to switch network. Please switch manually in your wallet.');
                }
            }
        }, [isConnected, fetchConversion]);

        /* --- Payment type change handler --- */
        var onPaymentTypeChange = useCallback(async function (newType) {
            setPaymentType(newType);
            paymentTypeRef.current = newType;
            ethAmountRef.current = null;
            weiAmountRef.current = null;
            usdcAmountRef.current = null;
            usdcSmallestUnitRef.current = null;

            if (isConnected) {
                await fetchConversion();
            }
        }, [isConnected, fetchConversion]);

        /* --- onPaymentSetup: pass data to WooCommerce Blocks --- */
        useEffect(function () {
            var unsubscribe = onPaymentSetup(function () {
                if (!txHashRef.current) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Please complete the crypto payment before placing the order.',
                    };
                }

                var pType = paymentTypeRef.current;
                var nKey = networkKeyRef.current;

                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            lccp_tx_hash: txHashRef.current,
                            lccp_eth_amount: pType === 'usdc'
                                ? String(usdcAmountRef.current || '')
                                : String(ethAmountRef.current || ''),
                            lccp_wallet_address: accountRef.current || '',
                            lccp_network: nKey,
                            lccp_payment_type: pType,
                            lccp_expected_amount: pType === 'usdc'
                                ? String(usdcSmallestUnitRef.current || '')
                                : String(weiAmountRef.current || ''),
                        },
                    },
                };
            });
            return unsubscribe;
        }, [onPaymentSetup, emitResponse]);

        /* --- Re-fetch conversion when cart total changes --- */
        useEffect(function () {
            if (isConnected && cartTotalDecimal > 0) {
                fetchConversion();
            }
        }, [cartTotalDecimal]);

        /* --- Button text and state --- */
        var net = getNetworkByKey(networkKey) || {};
        var symbol = paymentType === 'usdc' ? 'USDC' : (net.symbol || 'ETH');
        var buttonLabel, buttonDisabled;

        switch (status) {
            case 'connecting':
                buttonLabel = i18n.connecting || 'Connecting...';
                buttonDisabled = true;
                break;
            case 'processing':
                buttonLabel = i18n.processing;
                buttonDisabled = true;
                break;
            case 'approving':
                buttonLabel = i18n.approving;
                buttonDisabled = true;
                break;
            case 'confirming':
                buttonLabel = i18n.waitingConfirmation;
                buttonDisabled = true;
                break;
            case 'complete':
                buttonLabel = i18n.paymentComplete;
                buttonDisabled = true;
                break;
            case 'ready':
                buttonLabel = 'Pay with ' + symbol;
                buttonDisabled = false;
                break;
            default: // idle
                buttonLabel = i18n.connectWallet;
                buttonDisabled = false;
        }

        var availableNetworks = [];
        for (var k in data.networks) {
            availableNetworks.push({ key: k, network: data.networks[k] });
        }

        /* --- Render --- */
        return el('div', { id: 'lccp-payment-container' },

            // Test mode badge
            data.isTestMode ? el('div', {
                className: 'lccp-mode-badge test',
                style: { background: '#fff8e5', border: '1px solid #dba617', padding: '8px 12px', marginBottom: '15px', borderRadius: '4px', display: 'flex', alignItems: 'center', gap: '8px' }
            },
                el('span', { style: { fontSize: '16px' } }, '\uD83E\uDDEA'),
                el('span', { style: { color: '#9a6700', fontWeight: 500, fontSize: '13px' } }, 'Test Mode - No real money')
            ) : null,

            // Selectors row
            el('div', { className: 'lccp-selectors' },
                // Payment type selector
                el('div', { className: 'lccp-payment-type-selector' },
                    el('label', null, 'Pay with'),
                    el('div', { className: 'lccp-payment-options' },
                        el('label', { className: 'lccp-option' },
                            el('input', {
                                type: 'radio',
                                name: 'lccp_payment_type_block',
                                value: 'eth',
                                checked: paymentType === 'eth',
                                onChange: function () { onPaymentTypeChange('eth'); }
                            }),
                            el('span', null, 'ETH')
                        ),
                        el('label', { className: 'lccp-option' },
                            el('input', {
                                type: 'radio',
                                name: 'lccp_payment_type_block',
                                value: 'usdc',
                                checked: paymentType === 'usdc',
                                onChange: function () { onPaymentTypeChange('usdc'); }
                            }),
                            el('span', null, 'USDC')
                        )
                    )
                ),

                // Network selector
                el('div', { className: 'lccp-network-selector' },
                    el('label', { htmlFor: 'lccp-network-block' }, 'Network'),
                    el('select', {
                        id: 'lccp-network-block',
                        value: networkKey,
                        onChange: function (e) { onNetworkChange(e.target.value); }
                    },
                        availableNetworks.map(function (item) {
                            return el('option', { key: item.key, value: item.key },
                                item.network.name + (item.network.isTestnet ? ' (Testnet)' : '')
                            );
                        })
                    )
                )
            ),

            // Wallet status
            el('div', {
                id: 'lccp-wallet-status',
                className: 'lccp-status ' + (isConnected ? 'connected' : 'disconnected')
            },
                el('span', { className: 'lccp-status-icon' }, isConnected ? '\u2705' : '\uD83D\uDD12'),
                el('span', { className: 'lccp-status-text' },
                    isConnected ? 'Connected: ' + shortAddr(account) + ' (' + net.name + ')' : 'Wallet not connected'
                ),
                isConnected ? el('button', {
                    type: 'button',
                    onClick: doDisconnect,
                    style: { marginLeft: '10px', padding: '2px 8px', fontSize: '12px', cursor: 'pointer', border: '1px solid #ccc', borderRadius: '4px', background: '#f5f5f5' }
                }, 'Disconnect') : null
            ),

            // Price display
            showPrice ? el('div', { id: 'lccp-price-display', className: 'lccp-price' },
                el('div', { className: 'lccp-price-row' },
                    el('span', { className: 'lccp-label' }, 'Amount:'),
                    el('span', { className: 'lccp-value' }, cryptoDisplay)
                ),
                el('div', { className: 'lccp-price-row lcc-rate' },
                    el('span', { className: 'lccp-label' }, 'Rate:'),
                    el('span', { className: 'lccp-value' }, rateDisplay)
                )
            ) : null,

            // Connect / Pay button
            el('button', {
                type: 'button',
                id: 'lccp-connect-btn',
                className: 'button lcc-btn' + (status === 'complete' ? ' complete' : '') + (['connecting', 'processing', 'approving', 'confirming'].indexOf(status) !== -1 ? ' processing' : ''),
                disabled: buttonDisabled,
                onClick: handleButtonClick,
            },
                ['connecting', 'processing', 'approving', 'confirming'].indexOf(status) !== -1
                    ? el('span', { className: 'lccp-spinner' })
                    : (status === 'complete'
                        ? el('span', { className: 'lccp-btn-icon' }, '\u2705')
                        : (isConnected
                            ? el('span', { className: 'lccp-btn-icon' }, '\uD83D\uDCB0')
                            : el('span', { className: 'lccp-btn-icon' }, '\uD83E\uDD8A')
                        )
                    ),
                ' ' + buttonLabel
            ),

            // Error display
            error ? el('div', { id: 'lccp-error', className: 'lccp-error' }, error) : null,

            // Wallet selection modal
            showModal ? el('div', {
                id: 'lccp-wallet-modal',
                style: { display: 'flex', position: 'fixed', top: 0, left: 0, right: 0, bottom: 0, background: 'rgba(0,0,0,0.5)', zIndex: 99999, alignItems: 'center', justifyContent: 'center' },
                onClick: function (e) { if (e.target === e.currentTarget) setShowModal(false); }
            },
                el('div', { style: { background: 'white', borderRadius: '16px', padding: '24px', maxWidth: '360px', width: '90%', boxShadow: '0 10px 40px rgba(0,0,0,0.2)' } },
                    el('h3', { style: { margin: '0 0 20px', fontSize: '18px', textAlign: 'center' } }, 'Connect Wallet'),
                    el('button', {
                        type: 'button',
                        onClick: connectMetaMask,
                        style: { width: '100%', padding: '14px', marginBottom: '12px', border: '1px solid #e5e5e5', borderRadius: '12px', background: 'white', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: '12px', fontSize: '16px' }
                    },
                        el('img', { src: data.pluginUrl + 'assets/images/metamask.svg', style: { width: '32px', height: '32px' } }),
                        el('span', null, 'MetaMask')
                    ),
                    el('button', {
                        type: 'button',
                        onClick: connectWalletConnect,
                        style: { width: '100%', padding: '14px', border: '1px solid #e5e5e5', borderRadius: '12px', background: 'white', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: '12px', fontSize: '16px' }
                    },
                        el('img', { src: data.pluginUrl + 'assets/images/walletconnect.png', style: { width: '32px', height: '32px', borderRadius: '8px' } }),
                        el('span', null, 'WalletConnect')
                    ),
                    el('button', {
                        type: 'button',
                        onClick: function () { setShowModal(false); },
                        style: { width: '100%', padding: '10px', marginTop: '16px', border: 'none', background: 'none', cursor: 'pointer', color: '#666', fontSize: '14px' }
                    }, 'Cancel')
                )
            ) : null
        );
    };

    /* ─── Label Component ────────────────────────────────────────── */

    var Label = function (props) {
        var PaymentMethodLabel = props.components.PaymentMethodLabel;
        var text = decodeEntities(data.title || 'Pay with Crypto');

        return el('span', { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
            el('img', {
                src: data.pluginUrl + 'assets/images/lccp-icon.svg',
                alt: '',
                style: { width: '24px', height: '24px' }
            }),
            el('span', null, text)
        );
    };

    /* ─── Edit Component (Block Editor) ──────────────────────────── */

    var Edit = function () {
        return el('div', { style: { padding: '16px', background: '#f9f9f9', borderRadius: '8px', textAlign: 'center', color: '#666' } },
            el('p', null, 'Layer Crypto Checkout - Pay with ETH / USDC'),
            el('p', { style: { fontSize: '12px' } }, 'Wallet connection UI will appear on the frontend.')
        );
    };

    /* ─── Register Payment Method ────────────────────────────────── */

    registerPaymentMethod({
        name: 'layer-crypto-checkout',
        label: el(Label, null),
        content: el(Content, null),
        edit: el(Edit, null),
        canMakePayment: function () { return true; },
        ariaLabel: 'Pay with Crypto',
        supports: {
            features: ['products'],
        },
    });

})();
