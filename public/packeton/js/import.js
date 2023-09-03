(function ($) {
    let form = $('#submit-package-form');
    let onSubmit = function(e) {
        let btn = $('#submit');
        $('ul.package-errors, .repo-result, div.confirmation', this).remove();
        let handler = (data) => {
            btn.removeClass('loading');
            let html = '';
            if (data.status === 'error') {
                data.reason = typeof data.reason === 'string' ? [data.reason] : data.reason;

                $.each(data.reason, function (k, v) {
                    html += '<li><div class="alert alert-warning">'+v+'</div></li>';
                });
                form.prepend('<ul class="list-unstyled package-errors">'+html+'</ul>');
                return;
            }

            let repos = data['repos'] || [];
            repos = repos.join("\n");
            $('#submit-package-form input[type="submit"]').before($('<div class="repo-result">').append('<pre>' + repos + '</pre>'));

            form.unbind('submit');
            btn.val('Submit');
            btn.unbind('submit');
        };

        let url = $(this).data('check-url');
        $.post(url, $(this).serializeArray(), handler);
        btn.addClass('loading');
        e.preventDefault();
    };

    $('.type-hide').closest('.form-group').hide();

    let typeSelect =  $('.package-repo-type');
    typeSelect.on('change', () => {
        let repoType = typeSelect.val();
        if (!repoType) {
            return;
        }
        $('.type-hide').closest('.form-group').hide();
        $('.'+repoType).closest('.form-group').show();
    });

    let select = $('.integration-select');

    select.on('change', (e) => {
        let val = select.val();
        let s2 = $('.integration-repo');
        if (!val) {
            s2.select2({'data': []});
            s2.html('').change();
            return;
        }

        let url = '/integration/all/' + val + '/repos';
        $.ajax({
            url: url,
            success: (result) => {
                let options = result.map((item) => '<option value="' + item.id + '">' + item.text + '</option>');
                s2.select2({'data': result});
                s2.html(options.join('')).change();
            }
        });
    });

    typeSelect.triggerHandler('change');

    let packRepo = $('.package-repo-info');
    packRepo.change(function() {
        form.unbind('submit');
        form.submit(onSubmit);
        $('#submit').val('Check');
    });
    packRepo.triggerHandler('change');

})(jQuery);
