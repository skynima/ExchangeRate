jQuery(function ($) {
    if (typeof NerkhLiveConfig === 'undefined') {
        return;
    }

    var sourceTimers = Array.isArray(NerkhLiveConfig.sourceTimers) ? NerkhLiveConfig.sourceTimers : [];
    if (!sourceTimers.length) {
        return;
    }

    var $status = $('#nerkh-live-status');
    var state = {};

    sourceTimers.forEach(function (item) {
        if (!item || !item.key) {
            return;
        }
        var interval = parseInt(item.interval, 10);
        if (!interval || interval < 10) {
            return;
        }
        var remaining = parseInt(item.remaining, 10);
        if (Number.isNaN(remaining) || remaining < 0) {
            remaining = interval;
        }
        state[item.key] = {
            key: item.key,
            interval: interval,
            remaining: remaining,
            browserRelayUrl: item.browserRelayUrl || '',
            busy: false
        };
    });

    function setStatus(text, isError) {
        if (!$status.length) {
            return;
        }
        $status.text(text || '');
        $status.toggleClass('is-error', !!isError);
    }

    function updateCountdownLabel(sourceKey, seconds) {
        var $label = $('.nerkh-source-countdown[data-source-key="' + sourceKey + '"]');
        if (!$label.length) {
            return;
        }
        var value = Math.max(0, parseInt(seconds, 10) || 0);
        $label.text(value + ' ثانیه');
    }

    function runServerTick(timer) {
        if (!timer || timer.busy) {
            return $.Deferred().resolve().promise();
        }
        timer.busy = true;
        setStatus(NerkhLiveConfig.i18nTicking, false);

        return $.post(NerkhLiveConfig.ajaxUrl, {
            action: 'exchange_rate_live_tick',
            nonce: NerkhLiveConfig.nonce,
            source_key: timer.key
        }).done(function () {
            setStatus(NerkhLiveConfig.i18nIdle, false);
        }).fail(function () {
            setStatus(NerkhLiveConfig.i18nError, true);
        }).always(function () {
            timer.busy = false;
            timer.remaining = timer.interval;
            updateCountdownLabel(timer.key, timer.remaining);
        });
    }

    function runBrowserRelay(timer) {
        if (!timer || !timer.browserRelayUrl) {
            return $.Deferred().resolve().promise();
        }

        return fetch(timer.browserRelayUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json, text/plain, */*'
            }
        }).then(function (res) {
            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }
            return res.text();
        }).then(function (body) {
            return $.post(NerkhLiveConfig.ajaxUrl, {
                action: 'exchange_rate_ingest_browser',
                nonce: NerkhLiveConfig.nonce,
                source_key: timer.key,
                request_url: timer.browserRelayUrl,
                payload: body
            });
        }).then(function () {
            timer.remaining = timer.interval;
            updateCountdownLabel(timer.key, timer.remaining);
            setStatus(NerkhLiveConfig.i18nIdle, false);
        }).catch(function () {
            setStatus(NerkhLiveConfig.i18nError, true);
        });
    }

    Object.keys(state).forEach(function (key) {
        updateCountdownLabel(key, state[key].remaining);
    });

    setInterval(function () {
        Object.keys(state).forEach(function (key) {
            var timer = state[key];
            if (!timer || timer.busy) {
                return;
            }

            timer.remaining -= 1;
            updateCountdownLabel(timer.key, timer.remaining);
            if (timer.remaining > 0) {
                return;
            }

            runServerTick(timer).fail(function () {
                runBrowserRelay(timer);
            });
        });
    }, 1000);
});
