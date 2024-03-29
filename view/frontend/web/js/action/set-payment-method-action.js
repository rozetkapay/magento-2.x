define(
    [
        'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/url-builder',
        'mage/storage',
        'Magento_Checkout/js/model/error-processor',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/model/full-screen-loader'
    ],
    function ($, quote, urlBuilder, storage, errorProcessor, customer, fullScreenLoader) {
        'use strict';
        return function (messageContainer) {
            $.ajax({
                url: "/rozetkapay/action/getredirect",
                type: "GET",
                data: "",
                success: function (response) {
                    if(response.redirect_url) {
                        $.mage.redirect(response.redirect_url);
                    } else {
                        $.mage.redirect('/cart');
                    }
                }
            });
        };
    }
);
