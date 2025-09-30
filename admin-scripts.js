jQuery(document).ready(function($) {
    // #############################################
    // LOGIK FÜR TAGGING-OBERFLÄCHE (URLs & IPs)
    // #############################################
    
    /**
     * Sanitize HTML to prevent XSS
     */
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    /**
     * Validate IP Address format
     */
    function isValidIP(ip) {
        var ipv4Regex = /^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
        var ipv6Regex = /^(([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(ffff(:0{1,4}){0,1}:){0,1}((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])|([0-9a-fA-F]{1,4}:){1,4}:((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9]))$/;
        return ipv4Regex.test(ip) || ipv6Regex.test(ip);
    }
    
    function addTag(tagToAdd, $container) {
        var $input = $container.find('.daet-tag-input');
        var $tagDisplay = $container.find('.daet-tags-display');
        var $hidden = $container.find('.daet-tag-hidden-input');
        var $feedback = $container.find('.daet-feedback');
        var fieldId = $input.data('field-id');
        var raw = $hidden.val();
        var tags = raw ? raw.split(',') : [];
        
        if (!tagToAdd) { 
            $feedback.text('Bitte einen Wert eingeben.').addClass('error'); 
            return; 
        }
        
        // Validierung für IP-Adressen
        if (fieldId === 'allowed_ips' && !isValidIP(tagToAdd)) {
            $feedback.text('Ungültige IP-Adresse. Bitte geben Sie eine gültige IPv4 oder IPv6 Adresse ein.').addClass('error');
            return;
        }
        
        if (tags.indexOf(tagToAdd) !== -1) { 
            $feedback.text('Dieser Wert existiert bereits.').addClass('error'); 
            $input.val(''); 
            return; 
        }
        
        $feedback.text('').removeClass('error');
        tags.push(tagToAdd);
        
        // XSS-sichere Tag-Erstellung
        var $tagElement = $('<span>')
            .addClass('daet-tag-item button-primary')
            .text(tagToAdd)
            .append(
                $('<span>')
                    .addClass('daet-remove-tag')
                    .attr('data-tag', tagToAdd)
                    .text('×')
            );
        
        $tagDisplay.append($tagElement);
        $tagDisplay.show(); 
        $hidden.val(tags.join(','));
        $input.val('');
    }
    
    $('.daet-tag-container .daet-tag-input').on('keydown', function(e) {
        if (e.keyCode === 13) {
            e.preventDefault();
            var $container = $(this).closest('.daet-tag-container');
            var tag = $(this).val().trim();
            var fieldId = $(this).data('field-id');
            
            if (fieldId === 'dev_urls' || fieldId === 'prod_urls') {
                try {
                    var tempUrl = tag.startsWith('http') ? tag : 'https://' + tag;
                    var urlObject = new URL(tempUrl);
                    tag = urlObject.hostname;
                } catch (err) { 
                    tag = tag.replace(/^(https?:\/\/)?/, '').replace(/\/.*$/, ''); 
                }
            }
            addTag(tag, $container);
        }
    });
    
    $(document).on('click', '#daet-add-current-ip', function(e) {
        e.preventDefault();
        var $link = $(this);
        var ipToAdd = $link.data('ip-address');
        var $container = $link.closest('.daet-tag-container');
        
        if (!ipToAdd || ipToAdd === '0.0.0.0') {
            var $feedback = $container.find('.daet-feedback');
            $feedback.text('IP-Adresse konnte nicht ermittelt werden.').addClass('error');
            return;
        }
        
        addTag(ipToAdd, $container);
    });
    
    $(document).on('click', '.daet-remove-tag', function(e) {
        e.preventDefault();
        var $tagItem = $(this).parent();
        var $container = $tagItem.closest('.daet-tag-container');
        var $hidden = $container.find('.daet-tag-hidden-input');
        var $tagDisplay = $container.find('.daet-tags-display');
        var tagText = $(this).data('tag');
        var raw = $hidden.val();
        var tags = raw ? raw.split(',') : [];
        
        tags = tags.filter(function(t) { 
            return t !== tagText; 
        });
        
        $hidden.val(tags.join(','));
        $tagItem.remove();
        $container.find('.daet-feedback').text('').removeClass('error');
        
        if (tags.length === 0) { 
            $tagDisplay.hide(); 
        }
    });
    
    $('.daet-tag-container').each(function() {
        var $container = $(this);
        var $tagDisplay = $container.find('.daet-tags-display');
        var $hidden = $container.find('.daet-tag-hidden-input');
        var raw = $hidden.val();
        var tags = raw ? raw.split(',') : [];
        if (tags.length > 0 && tags.join('') !== '') { 
            $tagDisplay.show(); 
        }
    });

    // #############################################
    // LOGIK FÜR USER-AGENT CHECKBOXEN
    // #############################################
    function syncUserAgentTextarea() {
        var $textarea = $('#allowed_user_agents');
        if (!$textarea.length) return;
        var agents = new Set($textarea.val().split('\n').filter(Boolean));
        $('.daet-ua-checkbox').each(function() {
            var $checkbox = $(this);
            var agentKeywords = $checkbox.data('agents').split(',');
            if ($checkbox.is(':checked')) {
                agentKeywords.forEach(function(agent) { 
                    agents.add(agent.trim()); 
                });
            } else {
                agentKeywords.forEach(function(agent) { 
                    agents.delete(agent.trim()); 
                });
            }
        });
        $textarea.val(Array.from(agents).sort().join('\n'));
    }
    
    var $textarea = $('#allowed_user_agents');
    if ($textarea.length) {
        var currentAgents = $textarea.val();
        $('.daet-ua-checkbox').each(function() {
            var $checkbox = $(this);
            var agentKeywords = $checkbox.data('agents').split(',');
            var allFound = agentKeywords.every(function(agent) { 
                return currentAgents.includes(agent.trim()); 
            });
            if (allFound) { 
                $checkbox.prop('checked', true); 
            }
        });
    }
    
    $(document).on('change', '.daet-ua-checkbox', syncUserAgentTextarea);

    // #############################################
    // LOGIK FÜR COLOR PICKER
    // #############################################
    if ($.fn.wpColorPicker) {
        $('.wp-color-picker-field').wpColorPicker();
    }
    
    // #############################################
    // AUDIT LOG TABLE ENHANCEMENTS
    // #############################################
    // Auto-refresh Audit Log every 30 seconds when on that page
    if ($('body').hasClass('tools_page_daet_audit_log')) {
        var refreshInterval = setInterval(function() {
            // Check if user is still on the page
            if (!document.hidden) {
                // Future: AJAX refresh implementation
                // Could be implemented to refresh the table without page reload
            }
        }, 30000);
        
        // Clear interval when leaving page
        $(window).on('beforeunload', function() {
            clearInterval(refreshInterval);
        });
    }
    
    // #############################################
    // AJAX-Vorbereitung für zukünftige Features
    // #############################################
    if (typeof daet_ajax !== 'undefined') {
        // Nonce ist verfügbar für AJAX-Requests
        // Beispiel für zukünftige Implementierung:
        // $.post(daet_ajax.ajax_url, {
        //     action: 'daet_ajax_action',
        //     _ajax_nonce: daet_ajax.nonce,
        //     data: 'value'
        // }, function(response) {
        //     // Handle response
        // });
    }
});