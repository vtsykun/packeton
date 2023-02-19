if (!String.prototype.htmlSpecialChars) {
    String.prototype.htmlSpecialChars = function () {
        return this.replace(/&/g, '&amp;')
            .replace(/'/g, '&apos;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    };
}
