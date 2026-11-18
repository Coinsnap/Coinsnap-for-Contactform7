jQuery(document).ready(function ($) {
    
    if($('#coinsnap_provider').length){
        
        setProvider();
        
        $('#coinsnap_provider').change(function(){
            setProvider();
        });
    }
    
    function setProvider(){
        if($('#coinsnap_provider').val() === 'coinsnap'){
            $('.coinsnapcf7-btcpay').hide();
            $('.coinsnapcf7-btcpay input').removeAttr('required');
            $('.coinsnapcf7-coinsnap').show();
            // Key fields stay empty when a key is saved (an empty field keeps it), so they are never required.
            $('.coinsnapcf7-coinsnap input:not([type=password])').attr('required','required');
        }
        else {
            $('.coinsnapcf7-coinsnap').hide();
            $('.coinsnapcf7-coinsnap input').removeAttr('required');
            $('.coinsnapcf7-btcpay').show();
            $('.coinsnapcf7-btcpay input:not([type=password])').attr('required','required');
        }
    }
    
    function isValidUrl(serverUrl) {
        try {
            const url = new URL(serverUrl);
            if (url.protocol !== 'https:' && url.protocol !== 'http:') {
                return false;
            }
	}
        catch (e) {
            return false;
	}
        return true;
    }

    $('.btcpay-apikey-link').click(function(e) {
        e.preventDefault();
        const host = $('#btcpay_server_url').val();
	if (isValidUrl(host)) {
            let data = {
                'action': 'cf7_btcpay_server_apiurl_handler',
                'cf7_post': coinsnap_ajax.cf7_post,
                'host': host,
                'apiNonce': coinsnap_ajax.nonce
            };
            
            $.post(coinsnap_ajax.ajax_url, data, function(response) {
                if (response.data.url) {
                    window.location = response.data.url;
		}
            }).fail( function() {
		alert('Error processing your request. Please make sure to enter a valid BTCPay Server instance URL.')
            });
	}
        else {
            alert('Please enter a valid url including https:// in the BTCPay Server URL input field.')
        }
    });
});

