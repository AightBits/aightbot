/**
 * AightBot Admin JavaScript
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Test Connection
        $('#test-connection-btn').on('click', function(e) {
            e.preventDefault();
            testConnection();
        });
        
        // JSON Validation
        $('#validate-json-btn').on('click', function(e) {
            e.preventDefault();
            validateJSON();
        });
        
        // Real-time JSON validation on blur
        $('#sampler_overrides').on('blur', function() {
            var value = $(this).val().trim();
            if (value !== '') {
                validateJSON(false); // Silent validation
            }
        });
        
        /**
         * Test API connection
         */
        function testConnection() {
            var $btn = $('#test-connection-btn');
            var $result = $('#test-result');
            var originalHTML = $btn.html();
            
            // Disable button
            $btn.prop('disabled', true).html(
                '<span class="dashicons dashicons-update spin"></span> ' + 
                aightbotAdmin.strings.testing_connection
            );
            
            // Clear previous result
            $result.hide().empty().removeClass('notice notice-success notice-error');
            
            $.ajax({
                url: aightbotAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'aightbot_test_connection',
                    nonce: aightbotAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showResult('success', aightbotAdmin.strings.test_success, response.data);
                    } else {
                        showResult('error', aightbotAdmin.strings.test_error, response.data);
                    }
                },
                error: function(xhr, status, error) {
                    showResult('error', aightbotAdmin.strings.test_error, error);
                },
                complete: function() {
                    $btn.prop('disabled', false).html(originalHTML);
                }
            });
        }
        
        /**
         * Validate JSON in sampler overrides field
         */
        function validateJSON(showMessage = true) {
            var $textarea = $('#sampler_overrides');
            var $result = $('#json-validation-result');
            var value = $textarea.val().trim();
            
            $textarea.removeClass('error');
            $result.empty();
            
            if (value === '') {
                return true;
            }
            
            try {
                var parsed = JSON.parse(value);
                
                // Check if it's an object
                if (typeof parsed !== 'object' || Array.isArray(parsed)) {
                    throw new Error('Sampler overrides must be a JSON object, not an array.');
                }
                
                if (showMessage) {
                    $result.html('<span class="success-text">✓ Valid JSON</span>');
                }
                return true;
                
            } catch (e) {
                $textarea.addClass('error');
                
                if (showMessage) {
                    $result.html(
                        '<span class="error-text">✗ ' + 
                        aightbotAdmin.strings.invalid_json + ': ' + 
                        e.message + 
                        '</span>'
                    );
                }
                return false;
            }
        }
        
        /**
         * Show test result message
         */
        function showResult(type, title, message) {
            var $result = $('#test-result');
            var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            
            $result.html(
                '<div class="notice ' + noticeClass + ' is-dismissible">' +
                '<p><strong>' + title + '</strong></p>' +
                (message ? '<p>' + message + '</p>' : '') +
                '</div>'
            ).slideDown();
            
            // Make dismissible
            $result.find('.notice-dismiss').on('click', function() {
                $result.slideUp();
            });
        }
        
        /**
         * Add spinner animation CSS
         */
        if (!$('#aightbot-spinner-style').length) {
            $('<style id="aightbot-spinner-style">')
                .text('@keyframes aightbot-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } } .dashicons.spin { animation: aightbot-spin 1s linear infinite; }')
                .appendTo('head');
        }
        
        // ========================================
        // RAG Index Management
        // ========================================
        
        // Reindex content
        $('#reindex-content-btn').on('click', function(e) {
            e.preventDefault();
            reindexContent();
        });
        
        // Clear index
        $('#clear-index-btn').on('click', function(e) {
            e.preventDefault();
            if (confirm('Are you sure you want to clear the entire content index? This action cannot be undone.')) {
                clearIndex();
            }
        });
        
        /**
         * Reindex all content
         */
        function reindexContent() {
            var $btn = $('#reindex-content-btn');
            var $result = $('#index-result');
            var originalHTML = $btn.html();
            
            // Disable button
            $btn.prop('disabled', true).html(
                '<span class="dashicons dashicons-update spin"></span> Indexing...'
            );
            $result.hide().empty();
            
            $.ajax({
                url: aightbotAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'aightbot_index_content',
                    nonce: aightbotAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showIndexResult('success', 'Indexing Complete', response.data.message || response.data);
                        updateIndexStatus();
                    } else {
                        showIndexResult('error', 'Indexing Failed', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    showIndexResult('error', 'Indexing Failed', 'An error occurred: ' + error);
                },
                complete: function() {
                    $btn.prop('disabled', false).html(originalHTML);
                }
            });
        }
        
        /**
         * Clear the content index
         */
        function clearIndex() {
            var $btn = $('#clear-index-btn');
            var $result = $('#index-result');
            var originalHTML = $btn.html();
            
            $btn.prop('disabled', true).html(
                '<span class="dashicons dashicons-update spin"></span> Clearing...'
            );
            $result.hide().empty();
            
            $.ajax({
                url: aightbotAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'aightbot_clear_index',
                    nonce: aightbotAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showIndexResult('success', 'Index Cleared', response.data);
                        updateIndexStatus();
                    } else {
                        showIndexResult('error', 'Clear Failed', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    showIndexResult('error', 'Clear Failed', 'An error occurred: ' + error);
                },
                complete: function() {
                    $btn.prop('disabled', false).html(originalHTML);
                }
            });
        }
        
        /**
         * Update index status display
         */
        function updateIndexStatus() {
            $.ajax({
                url: aightbotAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'aightbot_get_index_status',
                    nonce: aightbotAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var status = response.data;
                        $('#index-count').text(status.count);
                        $('#index-size').text(status.size_mb + ' MB');
                        $('#last-indexed').text(status.last_indexed_human);
                    }
                }
            });
        }
        
        /**
         * Show index operation result
         */
        function showIndexResult(type, title, message) {
            var $result = $('#index-result');
            var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            
            $result.html(
                '<div class="notice ' + noticeClass + ' is-dismissible">' +
                '<p><strong>' + title + '</strong></p>' +
                (message ? '<p>' + message + '</p>' : '') +
                '</div>'
            ).slideDown();
        }
    });
    
})(jQuery);
