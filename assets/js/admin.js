(function($){
    function pwrNotify(resp, fallback){
        try {
            if (resp && typeof resp === 'object') {
                if (resp.ok) { alert('OK'); return; }
                if (resp.message) { alert(resp.message); return; }
            }
        } catch(e){}
        alert(fallback || 'Error');
    }
    $(document).on('click', '.pwr-act', function(e){
        e.preventDefault();
        var $btn = $(this);
        if (!pwrConfig || !pwrConfig.capability) return;
        $btn.prop('disabled', true).addClass('is-busy');
        var url  = $btn.data('url');
        var type = $btn.data('type');
        var endpoint = pwrConfig.restBase + '/purge' + '?_wpnonce=' + encodeURIComponent(pwrConfig.restNonce || '');
        $.ajax({
            url: endpoint,
            type: 'POST',
            data: { url: url, type: type, _wpnonce: (pwrConfig.nonce || '') },
            xhrFields: { withCredentials: true },
            beforeSend: function(xhr){
                if (pwrConfig && pwrConfig.restNonce) {
                    xhr.setRequestHeader('X-WP-Nonce', pwrConfig.restNonce);
                }
            }
        }).done(function(resp){
            pwrNotify(resp, 'Error');
        }).fail(function(jq, text, err){
            var msg = 'Error';
            try{
                if (jq && jq.responseJSON && jq.responseJSON.message) { msg = jq.responseJSON.message; }
                else if (jq && jq.responseText) { msg = jq.responseText; }
            }catch(e){}
            alert(msg);
        }).always(function(){
            $btn.prop('disabled', false).removeClass('is-busy');
        });
    });
})(jQuery);
