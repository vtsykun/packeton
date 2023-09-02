(function ($) {
    let form = $('#submit-package-form');
    let onSubmit = function(e) {
        let handler = (data) => {

        };

        $('#submit').val('Submit');
        form.unbind('submit');

        let url = $(this).data('check-url');
        $.post(url, $(this).serializeArray(), handler);
        $('#submit').addClass('loading');
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
