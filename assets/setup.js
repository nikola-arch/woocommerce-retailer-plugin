var setupConfig = (function() {
    for (var key in window) {
        if (window.hasOwnProperty(key) && key.endsWith('_ajax') && typeof window[key] === 'object' && window[key].retailer_slug) {
            return window[key];
        }
    }
    throw new Error('Retailer configuration not found. Please ensure constants.php is properly configured.');
})();

function getSelector(identifier) {
    return '#' + setupConfig.retailer_slug + '-' + identifier;
}

function getClassName(identifier) {
    return '.' + setupConfig.retailer_slug + '-' + identifier;
}

function getActionName(action) {
    return setupConfig.ajax_action_prefix + action;
}

function formatDebugInfo(debug) {
    if (!debug) return '';

    var html = '<div class="retailer-debug-info">';
    html += '<h4>Debug Information:</h4>';

    if (debug.endpoint) {
        html += '<p><strong>Endpoint:</strong> ' + debug.endpoint + '</p>';
    }

    if (debug.response_code) {
        html += '<p><strong>Response Code:</strong> ' + debug.response_code + '</p>';
    }

    if (debug.request_data) {
        html += '<p><strong>Request Data:</strong></p>';
        html += '<pre class="debug-json">' + JSON.stringify(debug.request_data, null, 2) + '</pre>';
    }

    if (debug.raw_response) {
        html += '<p><strong>Raw Response:</strong></p>';
        html += '<pre class="debug-json">' + debug.raw_response + '</pre>';
    }

    if (debug.parsed_response) {
        html += '<p><strong>Parsed Response:</strong></p>';
        html += '<pre class="debug-json">' + JSON.stringify(debug.parsed_response, null, 2) + '</pre>';
    }

    if (debug.error) {
        html += '<p><strong>Error:</strong> ' + debug.error + '</p>';
    }

    html += '</div>';
    return html;
}

jQuery(document).ready(function($) {
    $(getSelector('setup-form')).on('submit', function(e) {
        e.preventDefault();

        var formData = $(this).serialize();
        var submitButton = $(this).find('button[type="submit"]');
        var messagesContainer = $(getSelector('messages'));

        submitButton.prop('disabled', true).text('Submitting...');
        messagesContainer.empty();

        $.ajax({
            url: setupConfig.ajax_url,
            type: 'POST',
            data: formData + '&action=' + getActionName('submit_setup') + '&nonce=' + setupConfig.nonce,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    messagesContainer.html('<div class="notice success"><p>' + response.data + '</p></div>');
                    setTimeout(function() {
                        window.location.href = window.location.href + '&step=2';
                    }, 2000);
                } else {
                    var errorHtml = '<div class="notice error"><p>' + (response.data.message || response.data) + '</p>';
                    if (response.data.debug) {
                        errorHtml += formatDebugInfo(response.data.debug);
                    }
                    errorHtml += '</div>';
                    messagesContainer.html(errorHtml);
                    submitButton.prop('disabled', false).text('Submit & Verify Email');
                }
            },
            error: function() {
                messagesContainer.html('<div class="notice error"><p>An error occurred. Please try again.</p></div>');
                submitButton.prop('disabled', false).text('Submit & Verify Email');
            }
        });
    });

    $(getSelector('verify-form')).on('submit', function(e) {
        e.preventDefault();

        var formData = $(this).serialize();
        var submitButton = $(this).find('button[type="submit"]');
        var messagesContainer = $(getSelector('messages'));

        submitButton.prop('disabled', true).text('Verifying...');
        messagesContainer.empty();

        $.ajax({
            url: setupConfig.ajax_url,
            type: 'POST',
            data: formData + '&action=' + getActionName('verify_email') + '&nonce=' + setupConfig.nonce,
            dataType: 'json',
            success: function(response) {

                if (response.success) {
                    var responseData = response.data;

                    if (typeof responseData === 'object' && responseData.redirect_url) {
                        messagesContainer.html('<div class="notice success"><p>' + responseData.message + '</p></div>');

                        setTimeout(function() {
                            window.location.href = responseData.redirect_url;
                        }, 1500);
                    }
                } else {
                    var errorHtml = '<div class="notice error"><p>' + (response.data.message || response.data) + '</p>';
                    if (response.data.debug) {
                        errorHtml += formatDebugInfo(response.data.debug);
                    }
                    errorHtml += '</div>';
                    messagesContainer.html(errorHtml);
                    submitButton.prop('disabled', false).text('Verify & Authorize WooCommerce');
                }
            },
            error: function() {
                messagesContainer.html('<div class="notice error"><p>An error occurred. Please try again.</p></div>');
                submitButton.prop('disabled', false).text('Verify & Authorize WooCommerce');
            }
        });
    });

    $(getSelector('complete-form')).on('submit', function(e) {
        e.preventDefault();

        var submitButton = $(this).find('button[type="submit"]');
        var messagesContainer = $(getSelector('messages'));

        submitButton.prop('disabled', true).text('Completing Registration...');
        messagesContainer.empty();

        $.ajax({
            url: setupConfig.ajax_url,
            type: 'POST',
            data: {
                action: getActionName('complete_registration'),
                nonce: setupConfig.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var message = (typeof response.data === 'object' && response.data.message) ? response.data.message : response.data;
                    messagesContainer.html('<div class="notice success"><p>' + message + '</p></div>');

                    setTimeout(function() {
                        if (response.data && response.data.dashboard_url) {
                            window.location.href = response.data.dashboard_url;
                        } else {
                            window.location.href = setupConfig.admin_url + 'admin.php?page=' + setupConfig.retailer_slug + '-home';
                        }
                    }, 3000);
                } else {
                    messagesContainer.html('<div class="notice error"><p>' + response.data + '</p></div>');
                    submitButton.prop('disabled', false).text('Continue & Complete Registration');
                }
            },
            error: function() {
                messagesContainer.html('<div class="notice error"><p>An error occurred. Please try again.</p></div>');
                submitButton.prop('disabled', false).text('Continue & Complete Registration');
            }
        });
    });

    // Auto-start registration completion when user lands on completion page
    // This detects if we're on step 2 with complete_registration=1 parameter
    if ($('#step-2-registration').length > 0) {
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('complete_registration') === '1') {

            $('#step-2-registration ' + getClassName('step-status'))
                .removeClass('pending')
                .addClass('in-progress');

            setTimeout(function() {
                $.ajax({
                    url: setupConfig.ajax_url,
                    type: 'POST',
                    data: {
                        action: getActionName('complete_registration'),
                        nonce: setupConfig.nonce
                    },
                    dataType: 'json',
                    success: function(response) {

                        if (response.success) {
                            $('#step-2-registration ' + getClassName('step-status'))
                                .removeClass('in-progress')
                                .addClass('completed');

                            $('#step-2-registration .step-description')
                                .text('Registration completed successfully!');

                            $(getSelector('messages')).html(
                                '<div class="notice notice-success"><p>Registration completed successfully! Your store is now connected to ' + setupConfig.retailer_slug.charAt(0).toUpperCase() + setupConfig.retailer_slug.slice(1) + '.</p></div>'
                            );

                            setTimeout(function() {
                                if (response.data && response.data.dashboard_url) {
                                    window.location.href = response.data.dashboard_url;
                                } else {
                                    window.location.href = setupConfig.admin_url + 'admin.php?page=' + setupConfig.retailer_slug + '-home';
                                }
                            }, 3000);
                        } else {
                            $('#step-2-registration ' + getClassName('step-status'))
                                .removeClass('in-progress')
                                .addClass('error');

                            $('#step-2-registration .step-description')
                                .text('Registration failed - please try again');

                            var errorHtml = '<div class="notice notice-error"><p>' + (response.data.message || response.data) + '</p>';
                            if (response.data && response.data.debug) {
                                errorHtml += formatDebugInfo(response.data.debug);
                            }
                            errorHtml += '</div>';
                            $(getSelector('messages')).html(errorHtml);

                            $(getSelector('complete-form')).show();
                        }
                    },
                    error: function(xhr, status, error) {

                        $('#step-2-registration ' + getClassName('step-status'))
                            .removeClass('in-progress')
                            .addClass('error');

                        $('#step-2-registration .step-description')
                            .text('Connection error - please try again');

                        $(getSelector('messages')).html(
                            '<div class="notice notice-error"><p>Connection error occurred. Please try again.</p></div>'
                        );

                        $(getSelector('complete-form')).show();
                    }
                });
            }, 1000);
        }
    }
});
