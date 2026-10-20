/**
 * laravel-razorpay-billing checkout helper.
 *
 * Opens Razorpay Checkout with the data from a PHP `Checkout` object and posts
 * the handler response to the package's verify route.
 *
 *   RazorpayBilling.checkout(@json($checkout))
 *       .then((result) => { ... result.subscription.status ... })
 *       .catch((error) => { if (! error.dismissed) { ... } });
 *
 * Optional second argument:
 *   - any Checkout option, merged over the server's options
 *   - onPaymentFailed(error): called on each failed attempt (the modal stays
 *     open so the customer can retry)
 *
 * @see https://razorpay.com/docs/payments/subscriptions/integration-guide
 */
(function (global) {
    'use strict';

    var CHECKOUT_SRC = 'https://checkout.razorpay.com/v1/checkout.js';

    function loadCheckout() {
        if (global.Razorpay) {
            return Promise.resolve(global.Razorpay);
        }

        return new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = CHECKOUT_SRC;
            script.async = true;
            script.onload = function () { resolve(global.Razorpay); };
            script.onerror = function () { reject(new Error('Could not load Razorpay Checkout.')); };
            document.head.appendChild(script);
        });
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : null;
    }

    function verify(url, response) {
        var headers = {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
        var token = csrfToken();

        if (token) {
            headers['X-CSRF-TOKEN'] = token;
        }

        return fetch(url, {
            method: 'POST',
            headers: headers,
            credentials: 'same-origin',
            body: JSON.stringify(response)
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (body) {
                if (! res.ok) {
                    var error = new Error(body.message || 'Payment verification failed.');
                    error.response = body;
                    throw error;
                }

                return body;
            });
        });
    }

    function checkout(data, overrides) {
        overrides = overrides || {};

        var onPaymentFailed = overrides.onPaymentFailed;
        delete overrides.onPaymentFailed;

        return loadCheckout().then(function (Razorpay) {
            return new Promise(function (resolve, reject) {
                var options = Object.assign({}, data.options, overrides);
                var modal = Object.assign({}, options.modal);
                var userDismiss = modal.ondismiss;

                options.handler = function (response) {
                    verify(data.verify_url, response).then(resolve, reject);
                };

                modal.ondismiss = function () {
                    if (typeof userDismiss === 'function') {
                        userDismiss();
                    }

                    var error = new Error('Checkout was closed.');
                    error.dismissed = true;
                    reject(error);
                };
                options.modal = modal;

                var instance = new Razorpay(options);

                instance.on('payment.failed', function (response) {
                    if (typeof onPaymentFailed === 'function') {
                        onPaymentFailed(response.error);
                    }
                });

                instance.open();
            });
        });
    }

    global.RazorpayBilling = { checkout: checkout };
})(typeof window !== 'undefined' ? window : this);
