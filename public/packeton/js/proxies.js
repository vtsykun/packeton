(function ($, humane) {
    "use strict";

    if (!String.prototype.htmlSpecialChars) {
        String.prototype.htmlSpecialChars = function () {
            return this.replace(/&/g, '&amp;')
                .replace(/'/g, '&apos;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        };
    }

    $('.view-log').on('click', function (e) {
        e.preventDefault();
        let target = $(this);
        let details = target.attr('data-details');
        let message = target.attr('data-msg');
        let close = '<a class="close" onclick="humane.remove();">x</a>';
        if (message.length > 64) {
            if (message.length > 120) {
                details = '<pre>' + message + '</pre>' + details;
            }

            message = message.substring(0, 60) + '...';
        }
        humane.log([close, message, details], {timeout: 0});
    });

    let gridBtn = $('.grid-buttons .btn');
    gridBtn.on('click', (e) => {
        e.preventDefault();
        dispatchAjaxGridForm(e.target);
    });
    gridBtn.on('submit', (e) => {
        e.preventDefault();
        dispatchAjaxGridForm(e.target);
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

            button.removeClass('loading');
            let msg = '';
            if (data['valid'] && data['valid'].length > 0) {
                validTextarea = req['packages'];
                button.val('Confirm & Submit');
            } else {
                msg += '<div class="alert alert-warning">Not found a valid package in the textarea.</div>';
            }

            if (data['invalid'] && data['invalid'].length > 0) {
                console.log(data['invalid']);
                msg += '<div class="alert alert-warning">Package not found:' + data['invalid'].join(', ') + '</div>';
            }

            let info = "";
            for (let item of data['validData']) {
                let str = item['name'] + " " + item['license'] + " - " + item['description'];
                info += str.trim().replace(/[\-,]+$/, '').htmlSpecialChars() + "\n";
            }

            msg += '<b>Valid packages</b><pre style="max-height: 175px;">' + info + '</pre>';
            $('#result-validate').html(msg);
        }

        $.ajax(options);
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
