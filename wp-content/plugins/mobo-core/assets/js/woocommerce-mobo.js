jQuery(document).ready(function($) {
    $('#mobo-resync-button').on('click', function() {
        var productId = $(this).data('product-id');
        
        $.ajax({
            url: moboAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'mobo_resync_action',
                product_id: productId
            }
            // ,
            // success: function(response) {
            //     // alert('Response: ' + response);
            // },
            // error: function() {
            //     alert('An error occurred.');
            // }
        });
    });
});


