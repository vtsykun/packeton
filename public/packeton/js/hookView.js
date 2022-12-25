(function ($) {
    "use strict";
    $('form.delete-hook').on('submit', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        var form = $(e.target);
        if (window.confirm('Are you sure?')) {
            $.ajax({
                type: "DELETE",
                url: form.attr('action'),
                data: form.serialize(),
                success: function() {
                    location.reload();
                }
            });
        }
    });
})(jQuery);
