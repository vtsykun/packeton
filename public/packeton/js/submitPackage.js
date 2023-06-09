(function ($) {
    let showSimilarMax = 5;
    let form = $('#submit-package-form');
    let onSubmit = function(e) {
        let success;
        $('ul.package-errors, ul.similar-packages, div.confirmation', this).remove();
        success = function (data) {
            let html = '';
            $('.hide-unchecked').show();
            $('#submit').removeClass('loading');
            if (data.status === 'error') {
                $.each(data.reason, function (k, v) {
                    html += '<li><div class="alert alert-warning">'+v+'</div></li>';
                });
                $('#submit-package-form').prepend('<ul class="list-unstyled package-errors">'+html+'</ul>');
            } else {
                if (data.similar && data.similar.length) {
                    let $similar = $('<ul class="list-unstyled similar-packages">');
                    let limit = data.similar.length > showSimilarMax ? showSimilarMax : data.similar.length;
                    for ( let i = 0; i < limit; i++ ) {
                        let similar = data.similar[i];
                        let $link = $('<a>').attr('href', similar.url).text(similar.name);
                        $similar.append($('<li>').append($link))
                    }
                    if (limit != data.similar.length) {
                        $similar.append($('<li>').text('And ' + (data.similar.length - limit) + ' more'));
                    }
                    $('#submit-package-form input[type="submit"]').before($('<div>').append(
                        '<p><strong>Notice:</strong> One or more similarly named packages have already been submitted to Packagist. If this is a fork read the notice above regarding VCS Repositories.'
                    ).append(
                        '<p>Similarly named packages:'
                    ).append($similar));
                }

                if (data['details']) {
                    $('#submit-package-form input[type="submit"]').before($('<div>').append('<pre>' + data['details'] + '</pre>'));
                }

                $('#submit-package-form input[type="submit"]').before(
                    '<div class="confirmation">The package name found for your repository is: <strong>'+data.name+'</strong>, press Submit to confirm.</div>'
                );
                $('#submit').val('Submit');
                $('#submit-package-form').unbind('submit');
            }
        };

        let url = $(this).data('check-url');
        $.post(url, $(this).serializeArray(), success);
        $('#submit').addClass('loading');
        e.preventDefault();
    };

    let packRepo = $('.package-repo-info');
    packRepo.change(function() {
        form.unbind('submit');
        form.submit(onSubmit);
        $('#submit').val('Check');
    });

    $('.hide-unchecked').hide();
    packRepo.triggerHandler('change');

    $('.repo-type').on('change', () => {
        let repoType = $('.repo-type').val();
        if (!repoType) {
            return;
        }

        let url = form.data('submit-url') + '/' + repoType;
        window.location.href = url;
    });
})(jQuery);
