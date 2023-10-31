(function () {
    "use strict";
    let el = document.getElementById('route');
    let route = el.getAttribute('href');

    setTimeout(() => {
        location.href = route;
    }, 500);
})();
