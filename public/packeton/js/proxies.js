(function ($, humane) {
    "use strict";

    let gridBtn = $('.grid-buttons .btn');
    gridBtn.on('click', (e) => {
        e.preventDefault();
        dispatchAjaxGridForm(e.target);
    });
    gridBtn.on('submit', (e) => {
        e.preventDefault();
        dispatchAjaxGridForm(e.target);
    });

    $('a.package-info').on('click', (e) => {
        e.preventDefault();
        let model = $('#json-model');

        let options = {
            type: 'POST',
            url: $(e.target).attr('href'),
            success: (data) => {
                model.find('.modal-body').html(data);
                model.modal({show: true});
            }
        };

        $.ajax(options);
    });

    let updateBtn = $('.update.action').find('.btn');
    updateBtn.on('click', (e) => {
        e.preventDefault();
        $('#json-model').modal({show: true});

        return;
        let data = {};
        let options = ajaxFormData(e.target, data);
        if (data['force']) {
            if (!window.confirm('All data will remove and resync again. Are you sure?')) {
                return;
            }
        }

        options['success'] = () => {
            humane.log('The job has been scheduled');
        }
        $.ajax(options);
    });

    let packagesMirror = $('#packages_mirror .btn-success');
    packagesMirror.on('click', (e) => {
        e.preventDefault();
        dispatchCheckPackagesForm(e.target);
    });
    packagesMirror.on('submit', (e) => {
        e.preventDefault();
        dispatchCheckPackagesForm(e.target);
    });

    $('.tabpanel-packages').on('click', (e) => {
        e.preventDefault();
        let checkbox = $(e.target).closest('.tab-pane').find('.checkbox-id');
        checkbox.prop('checked', true);
    });

    $('.tabpanel-packages-new').on('click', (e) => {
        e.preventDefault();
        let checkbox = $(e.target).closest('.tab-pane').find('.checkbox-new');
        checkbox.prop('checked', true);
    });

    function serializeForm(el) {
        let data = {};
        let form = $(el).closest('form');
        $.each(form.serializeArray(), function() {
            data[this.name] = this.value;
        });

        return data;
    }

    function ajaxFormData(el, data = {}) {
        let form = $(el).closest('form');
        $.each(form.serializeArray(), function() {
            data[this.name] = this.value;
        });

        return {
            data: JSON.stringify(data),
            contentType: 'application/json; charset=utf-8',
            type: form.attr('method'),
            url: form.attr('action'),
        };
    }

    let validTextarea = null;
    function dispatchCheckPackagesForm(el) {
        let req = serializeForm(el);

        let button = $('#mirror-submit');
        if (button.hasClass('loading')) {
            return;
        }

        button.addClass('loading');
        req['check'] = req['packages'] !== validTextarea;

        let options = ajaxFormData(el, req);
        options['success'] = (data) => {
            if (req['check'] === false && data['valid']) {
                window.location.reload();
                return;
            }

            let msg = '';
            if (data['valid'] && data['valid'].length > 0) {
                validTextarea = req['packages'];
                button.val('Confirm & Submit');
            } else {
                msg += '<div class="alert alert-warning">Not found a valid package in the textarea.</div>';
            }

            if (data['invalid'] && data['invalid'].length > 0) {
                msg += '<div class="alert alert-warning">Package not found:' + data['invalid'].join(', ') + '</div>';
            }
            if (data['errors'] && data['errors'].length > 0) {
                msg += '<div class="alert alert-warning">' + data['errors'].join('<br>') + '</div>';
            }

            let info = "";
            if (data['newData'] && data['newData'].length > 0) {
                for (let item of data['newData']) {
                    let str = item['name'] + " " + item['license'] + " - " + item['description'];
                    info += str.trim().replace(/[\-,]+$/, '').htmlSpecialChars() + "\n";
                }
                msg += '<b>New packages</b><pre style="max-height: 175px;">' + info + '</pre>';
            }
            if (data['updateData'] && data['updateData'].length > 0) {
                info = '';
                for (let item of data['updateData']) {
                    let str = item['name'] + " " + item['license'] + " - " + item['description'];
                    info += str.trim().replace(/[\-,]+$/, '').htmlSpecialChars() + "\n";
                }
                msg += '<b>Already enabled packages</b><pre style="max-height: 175px;">' + info + '</pre>';
            }

            $('#result-validate').html(msg);
        }

        $.ajax(options).always(() => {button.removeClass('loading')});
    }

    function dispatchAjaxGridForm(el) {
        let packages = [];
        let selected = $("input.checkbox-id:checked");
        if (selected.length > 0) {
            for (let i = 0; i < selected.length; i++) {
                packages.push(selected[i].name);
            }
        }

        if (packages.length === 0) {
            alert("Not packages selected")
            return;
        }

        let data = { 'packages': packages };
        let options = ajaxFormData(el, data);
        options['success'] = () => {
            window.location.reload();
        }

        $.ajax(options);
    }


})(jQuery, humane);
