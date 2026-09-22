(function () {
    var HIDE_AFTER_MS = 30000;

    document.querySelectorAll('.secret-value').forEach(function (row) {
        var text    = row.querySelector('.secret-text');
        var reveal  = row.querySelector('.secret-reveal');
        var copy    = row.querySelector('.secret-copy');
        var value   = text.dataset.value;
        var masked  = text.textContent;
        var timer   = null;

        function hide() {
            clearTimeout(timer);
            text.textContent = masked;
            reveal.textContent = 'Show';
        }

        reveal.addEventListener('click', function () {
            if (reveal.textContent === 'Hide') { hide(); return; }

            text.textContent = value;
            reveal.textContent = 'Hide';
            timer = setTimeout(hide, HIDE_AFTER_MS);
        });

        copy.addEventListener('click', function () {
            navigator.clipboard.writeText(value).then(function () {
                copy.textContent = 'Copied';
                setTimeout(function () { copy.textContent = 'Copy'; }, 1500);
            }).catch(function () {
                copy.textContent = 'Copy failed';
                setTimeout(function () { copy.textContent = 'Copy'; }, 1500);
            });
        });
    });
})();
