var dashboardConfig = (function() {
    for (var key in window) {
        if (window.hasOwnProperty(key) && key.endsWith('_dashboard') && typeof window[key] === 'object' && window[key].retailer_slug) {
            return window[key];
        }
    }
    throw new Error('Retailer configuration not found. Please ensure constants.php is properly configured.');
})();

function getSelector(identifier) {
    return '#' + dashboardConfig.retailer_slug + '-' + identifier;
}

jQuery(document).ready(function($) {
    var iframe = document.getElementById('shopwoo-iframe');
    var iframeContainer = $(getSelector('iframe-container'));

    if (!iframe) {
        return;
    }

    function showLoading() {
        iframeContainer.prepend('<div class="iframe-loading" id="iframe-loading"><p>Loading ' + dashboardConfig.retailer_slug.charAt(0).toUpperCase() + dashboardConfig.retailer_slug.slice(1) + ' App...</p></div>');
    }

    function hideLoading() {
        $('#iframe-loading').remove();
    }

    showLoading();

    iframe.onload = function() {

        try {
            var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (iframeDoc && iframeDoc.body) {
                if (iframeDoc.body.innerHTML.length === 0) {
                    iframeContainer.html('<div class="notice notice-error"><p><strong>Dashboard blocked by browser security.</strong><br>Try: <a href="' + iframe.src + '" target="_blank">Open in new tab</a> or disable browser shields.</p></div>');
                    return;
                }
            }
        } catch (e) {
        }

        hideLoading();

        try {
            if (iframe.contentWindow) {
                var targetOrigin = new URL(dashboardConfig.iframe_url).origin;

                try {
                    iframe.contentWindow.postMessage({
                        type: 'parent_ready',
                        source: 'woocommerce_plugin'
                    }, targetOrigin);
                } catch (postMessageError) {

                    try {
                        iframe.contentWindow.postMessage({
                            type: 'parent_ready',
                            source: 'woocommerce_plugin'
                        }, '*');
                    } catch (wildcardError) {
                    }
                }

            } else {
                console.error('Iframe contentWindow not available');
            }
        } catch (e) {
            console.error('Error in iframe onload handler:', e);
        }
    };

    iframe.onerror = function() {
        hideLoading();
        iframeContainer.html('<div class="notice notice-error"><p>Failed to load dashboard. Please try refreshing the page.</p></div>');
    };

    window.addEventListener('message', function(event) {
        var allowedOrigin = new URL(dashboardConfig.iframe_url).origin;
        if (event.origin !== allowedOrigin) {
            return;
        }

        var data = event.data;

        switch(data.type) {
            case 'iframe_ready':
                hideLoading();
                break;

            case 'resize_iframe':
                if (data.height && data.height > 400) {
                    $(iframe).animate({
                        height: Math.min(data.height, $(window).height() - 100) + 'px'
                    }, 300);
                }
                break;

            case 'error':
                console.error('Iframe error:', data.message);
                iframeContainer.prepend(
                    '<div class="notice notice-error is-dismissible">' +
                    '<p>Error: ' + data.message + '</p>' +
                    '<button type="button" class="notice-dismiss"></button>' +
                    '</div>'
                );
                break;

            case 'success':
                iframeContainer.prepend(
                    '<div class="notice notice-success is-dismissible">' +
                    '<p>' + data.message + '</p>' +
                    '<button type="button" class="notice-dismiss"></button>' +
                    '</div>'
                );
                break;

            case 'logout':
                window.location.reload();
                break;

            default:
        }
    }, false);

    $(document).on('click', '.notice-dismiss', function() {
        $(this).closest('.notice').fadeOut();
    });

    setTimeout(function() {
        $('.notice.is-dismissible').fadeOut();
    }, 5000);

    setTimeout(function() {
        if ($('#iframe-loading').length > 0) {
            $('#iframe-fallback').show();
            hideLoading();
        }
    }, 8000);

    var urlParams = new URLSearchParams(window.location.search);
    var successOAuthParam = 'wc-oauth-success';

    if (urlParams.get(successOAuthParam) === '1') {
        iframeContainer.prepend(
            '<div class="notice notice-success is-dismissible">' +
            '<p>WooCommerce authorization successful! Your store is now connected.</p>' +
            '<button type="button" class="notice-dismiss"></button>' +
            '</div>'
        );

        var cleanUrl = window.location.href.split('?')[0];
        var params = new URLSearchParams(window.location.search);
        params.delete(successOAuthParam);
        var paramString = params.toString();

        if (paramString) {
            cleanUrl += '?' + paramString;
        }

        window.history.replaceState({}, document.title, cleanUrl);
    }
});
