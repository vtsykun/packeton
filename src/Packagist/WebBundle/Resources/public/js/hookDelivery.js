(function ($) {
    "use strict";
    var template = $('#loader_svg_template').html();
    $('.hook-delivery-guid').on('click', function (e) {
        if (!e.target) {
            return;
        }
        var $el = $(e.target);
        var containerId = $el.attr('aria-controls');
        var jobPath = $el.attr('data-job-url');
        if (!containerId || !jobPath) {
            return;
        }

        var container = $('#' + containerId);
        if (!container.length) {
            return;
        }

        if (container.html() && container.html().trim()) {
            return;
        }
        container.html(template);

        $.ajax({
            type: "POST",
            url: jobPath,
            success: function(html) {
                container.html(html);
            }
        });
    });
})(jQuery);
