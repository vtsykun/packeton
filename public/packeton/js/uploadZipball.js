(function ($) {
    let token = $('.csrf-token').val();

    let fileUploader = function (e) {
        e.stopPropagation();
        e.preventDefault();
        let files = e.target.files || e.dataTransfer.files;
        $('.error-container').html('');

        dragHandler(e);
        for (let file of files) {
            uploadFile(file);
        }
    };

    let uploadFile = function (file) {
        let url = $('#releases-upload').attr('data-url');
        let err = $('.error-container');
        let att = $('.files-container');

        let data = new FormData();
        data.append('archive', file);
        data.append('token', token);

        let container = $('.progress-container');
        let progressBar = $('<div class="progress"><div class="progress-bar" style="width: 0"></div></div>');
        container.append(progressBar);

        let request = new XMLHttpRequest();
        request.open("POST", url);

        request.upload.addEventListener('progress', (e) => {
            progressBar.find('.progress-bar').css('width', Math.round(100 * e.loaded/e.total) + '%');
            console.log('progress', e);
        });

        // File received / failed
        request.onreadystatechange = (e) => {
            if (request.readyState === 4) {
                progressBar.remove();
                let result = JSON.parse(request.responseText);

                if (result.error) {
                    err.html(result.error);
                }

                if (result.id) {
                    let name = formatFilename(result);
                    let el = $('<div class="attachment-item"><span>'+ name + '</span><span class="fa fa-times"></span></div>');
                    el.find('.fa-times').on('click', () => {
                        deleteFile(el, result.id);
                    });
                    att.append(el);
                    updateSelect2();
                }
            }
        };

        request.send(data);
    }

    let dragHandler = function (e) {
        e.stopPropagation();
        e.preventDefault();

        if (e.type === 'dragover') {
            $('#releases-drag').addClass('dragover');
        } else {
            $('#releases-drag').removeClass('dragover');
        }
    };

    let searchUrl = '/archive/list';
    let deleteUrl = '/archive/remove/';

    let fileSelect = document.getElementById('releases-upload');
    let fileDrag = document.getElementById('releases-drag');

    fileSelect.addEventListener('change', fileUploader);
    fileDrag.addEventListener('drop', fileUploader);

    fileDrag.addEventListener('dragover', dragHandler);
    fileDrag.addEventListener('dragleave', dragHandler);

    function deleteFile(el, id = null) {
        if (id === null) {
            id = el.attr('data-id');
        }
        el.remove();

        $.ajax({
            type: "DELETE",
            url: deleteUrl + id,
            data: {"token": token},
            success: () => updateSelect2(),
        });
    }

    function updateSelect2()
    {
        let select2 = $('.archive-select');
        $.ajax({
            url: searchUrl,
            success: (data) => {
                let result = [];
                for (let item of data) {
                    result.push({'id': item.id, 'text': formatFilename(item)});
                }
                select2.select2({'data': result});
            }
        });
    }
    function formatFilename(file)
    {
        let name = file.filename;
        let size = file.size + ' B';
        if (file.size > 1048576) {
            size = Math.round(file.size * 10/1048576) / 10  + ' MB';
        } else if (file.size > 1024) {
            size = Math.round(file.size * 10/1024)/ 10 + ' KB';
        }
        return name + ' (' + size + ')';
    }

})(jQuery)
