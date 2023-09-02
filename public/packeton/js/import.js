(function ($) {
    let form = $('#submit-package-form');
    let onSubmit = function(e) {

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

    typeSelect.triggerHandler('change');
})(jQuery);
