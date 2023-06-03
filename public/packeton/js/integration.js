(function ($, humane) {
    "use strict";
    let connBtn = $('.connect');

    connBtn.on('click', (e) => {

        e.preventDefault();
        let el = $(e.currentTarget);
        let btn = el.find('.btn')
        btn.addClass('loading');
        let url = el.attr('href');

        let options = {
            type: 'POST',
            url: url,
            data: {
                'token': el.attr('data-token'),
                'org': el.attr('data-org')
            },

            success: (data) => {
                btn.removeClass('loading');
                if (data['connected']) {
                    btn.removeClass('btn-primary');
                    btn.addClass('btn-danger');
                    btn.html('Discontent');
                } else {
                    btn.removeClass('btn-danger');
                    btn.addClass('btn-primary');
                    btn.html('Connect');
                }
            },
            error: (err) => {
                btn.removeClass('loading');
                console.log(err);
            },
            always: () => {
                btn.removeClass('loading');
            }
        };

        $.ajax(options);
    });
})(jQuery, humane);
