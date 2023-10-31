(function ($, humane) {
    "use strict";
    let connBtn = $('.connect');

    let form = $('#debug_integration');
    form.on('submit', (e) => {
        e.preventDefault();
        let btn = form.find('.btn');
        btn.addClass('loading');
        let url = form.attr('action');
        let formData = form.serializeArray();

        $.post(url, formData, function (data) {
            btn.removeClass('loading');
            let html = '';
            if (data.error) {
                html += '<li><div class="alert alert-warning">'+data.error+'</div></li>';
            }
            if (data.result) {
                html += '<li>'+data.result+'</li>';
            }
            $('#result-container').html('<ul class="list-unstyled package-errors">'+html+'</ul>');
        });
    });

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
