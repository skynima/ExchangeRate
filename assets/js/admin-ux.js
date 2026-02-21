jQuery(function ($) {
    function showToast(text) {
        var $toast = $('.nerkh-copy-toast');
        if (!$toast.length) {
            $toast = $('<div class="nerkh-copy-toast" />').appendTo('body');
        }
        $toast.text(text || 'کپی شد').addClass('is-visible');
        setTimeout(function () {
            $toast.removeClass('is-visible');
        }, 1400);
    }

    function copyText(text) {
        if (!text) {
            return;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showToast('شورت‌کد کپی شد');
            });
            return;
        }

        var $tmp = $('<textarea>').val(text).appendTo('body').select();
        document.execCommand('copy');
        $tmp.remove();
        showToast('شورت‌کد کپی شد');
    }

    $(document).on('click', '.nerkh-copy-btn', function () {
        copyText($(this).attr('data-copy'));
    });

    $(document).on('input', '#nerkh-source-search', function () {
        var q = String($(this).val() || '').toLowerCase().trim();
        $('.nerkh-source-main-row').each(function () {
            var $main = $(this);
            var $extra = $main.next('.nerkh-shortcode-row');
            var hay = String($main.attr('data-search') || $main.text() || '').toLowerCase();
            var match = q === '' || hay.indexOf(q) !== -1;
            $main.toggle(match);
            if ($extra.length) {
                $extra.toggle(match);
            }
        });
    });
});
