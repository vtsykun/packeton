if (!String.prototype.htmlSpecialChars) {
    String.prototype.htmlSpecialChars = function () {
        return this.replace(/&/g, '&amp;')
            .replace(/'/g, '&apos;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    };
}

if (window.defer === undefined) {
    let __defer = [];
    window._deferExec = function () {
        for (const f of __defer) { f(); }
    };
    window.defer = function (func) {
        __defer.push(func);
    }
}
