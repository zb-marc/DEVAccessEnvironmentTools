jQuery(document).ready(function($) {
    // #############################################
    // LOGIK FÜR TAGGING-OBERFLÄCHE (URLs & IPs)
    // #############################################
    function addTag(tagToAdd, $container) {
        var $input = $container.find('.daet-tag-input');
        var $tagDisplay = $container.find('.daet-tags-display');
        var $hidden = $container.find('.daet-tag-hidden-input');
        var $feedback = $container.find('.daet-feedback');
        var raw = $hidden.val();
        var tags = raw ? raw.split(',') : [];
        if (!tagToAdd) { $feedback.text('Bitte einen Wert eingeben.'); return; }
        if (tags.indexOf(tagToAdd) !== -1) { $feedback.text('Dieser Wert existiert bereits.'); $input.val(''); return; }
        $feedback.text('');
        tags.push(tagToAdd);
        var tagHtml = '<span class="daet-tag-item button-primary">' + $('<div>').text(tagToAdd).html() + '<span class="daet-remove-tag">×</span></span>';
        $tagDisplay.append(tagHtml);
        $tagDisplay.show(); 
        $hidden.val(tags.join(','));
        $input.val('');
    }
    $('.daet-tag-container .daet-tag-input').on('keydown', function(e) {
        if (e.keyCode === 13) {
            e.preventDefault();
            var $container = $(this).closest('.daet-tag-container');
            var tag = $(this).val().trim();
            if ($container.find('.daet-tag-hidden-input').attr('id') === 'dev_urls' || $container.find('.daet-tag-hidden-input').attr('id') === 'prod_urls') {
                try {
                    var tempUrl = tag.startsWith('http') ? tag : 'https://' + tag;
                    var urlObject = new URL(tempUrl);
                    tag = urlObject.hostname;
                } catch (err) { tag = tag.replace(/^(https?:\/\/)?/, '').replace(/\/.*$/, ''); }
            }
            addTag(tag, $container);
        }
    });
    $(document).on('click', '#daet-add-current-ip', function(e) {
        e.preventDefault();
        var $link = $(this);
        var ipToAdd = $link.data('ip-address');
        var $container = $link.closest('.daet-tag-container');
        addTag(ipToAdd, $container);
    });
    $(document).on('click', '.daet-remove-tag', function(e) {
        e.preventDefault();
        var $tagItem = $(this).parent();
        var $container = $tagItem.closest('.daet-tag-container');
        var $hidden = $container.find('.daet-tag-hidden-input');
        var $tagDisplay = $container.find('.daet-tags-display');
        var tagText = $tagItem.text().slice(0, -1).trim();
        var raw = $hidden.val();
        var tags = raw ? raw.split(',') : [];
        tags = tags.filter(function(t) { return t !== tagText; });
        $hidden.val(tags.join(','));
        $tagItem.remove();
        $container.find('.daet-feedback').text('');
        if (tags.length === 0) { $tagDisplay.hide(); }
    });
    $('.daet-tag-container').each(function() {
        var $container = $(this);
        var $tagDisplay = $container.find('.daet-tags-display');
        var $hidden = $container.find('.daet-tag-hidden-input');
        var raw = $hidden.val();
        var tags = raw ? raw.split(',') : [];
        if (tags.length > 0 && tags.join('') !== '') { $tagDisplay.show(); }
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
                agentKeywords.forEach(function(agent) { agents.add(agent); });
            } else {
                agentKeywords.forEach(function(agent) { agents.delete(agent); });
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
            var allFound = agentKeywords.every(function(agent) { return currentAgents.includes(agent); });
            if (allFound) { $checkbox.prop('checked', true); }
        });
    }
    $(document).on('change', '.daet-ua-checkbox', syncUserAgentTextarea);

    // #############################################
    // LOGIK FÜR COLOR PICKER
    // #############################################
    $('.wp-color-picker-field').wpColorPicker();
});