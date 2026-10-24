/**
 * The honest "a person saw the page" signal shared by the pages of personal
 * links (the page of an offer, the transition of a presentation) and the
 * visit script of our sites: opening a link is not a visit, mail link
 * checkers and messenger previews fetch it first. The callback runs once
 * the page has been visible for a moment; while the tab is hidden the wait
 * is paused. The browser signals let the server tell automated browsers of
 * link checks (skeeks/cms-communication LinkChecks).
 */
(function () {
    'use strict';

    if (window.sxLinkSignal) {
        return;
    }

    window.sxLinkSignal = {
        /**
         * Calls send() once after the page has been visible for delay ms.
         * send() returns a promise: a failed signal is sent again the next
         * time the page becomes visible.
         */
        whenSeen: function (delay, send) {
            var sent = false;
            var timer = null;

            function arm() {
                if (sent || timer || document.visibilityState !== 'visible') {
                    return;
                }
                timer = setTimeout(function () {
                    timer = null;
                    if (sent || document.visibilityState !== 'visible') {
                        return;
                    }
                    sent = true;
                    Promise.resolve().then(send).catch(function () {
                        sent = false;
                    });
                }, delay);
            }

            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'visible') {
                    arm();
                } else if (timer) {
                    clearTimeout(timer);
                    timer = null;
                }
            });
            arm();
        },

        browserSignals: function () {
            return {
                webdriver: navigator.webdriver ? 1 : 0,
                languages: navigator.languages ? navigator.languages.length : '',
                // The screen, not the window: in-app browsers of phones report a window of zero size.
                width: window.screen ? window.screen.width : '',
                height: window.screen ? window.screen.height : ''
            };
        }
    };
})();
