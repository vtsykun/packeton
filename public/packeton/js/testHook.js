(function ($) {
    "use strict";
    var submitButton = $('#test-form .btn-success');

    submitButton.on('click', function (e) {
        e.preventDefault();
        var form = $('#test-form');
        submitButton.addClass('loading');
        submitButton.attr("disabled", true);

        $.ajax({
            type: "POST",
            url: form.attr('action'),
            data: form.serialize(),
            success: function(html) {
                submitButton.removeClass('loading');
                submitButton.attr("disabled", false);
                $('.test-result').html(html);
            }
        });
    });
})(jQuery);
