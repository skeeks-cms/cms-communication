/**
 * Visits of our site by links of letters (step F). A marked link carries
 * the personal code of the recipient `sx=`; this script
 * - removes the code from the address bar at once (marks utm_* stay for
 *   Metrika): an address copied from the bar and forwarded is not the
 *   recipient's;
 * - after the page was shown (sxLinkSignal), reports the visit; the CRM
 *   answers with the token of a counted visit, kept in the cookie `sx_visit`
 *   until the browser is closed;
 * - on the next pages of the visit reports each page by that token.
 *
 * The CRM is the site this script came from: `/~comm-visit/visit.js` of the
 * CRM site for a site on another installation, otherwise this site.
 */
(function () {
    'use strict';

    var COOKIE = 'sx_visit';
    var SEEN_DELAY = 1500;
    var PAGE_DELAY = 1000;

    if (!window.sxLinkSignal || !window.fetch || !window.FormData) {
        return;
    }

    var script = document.currentScript;
    var crm = script && script.src ? script.src.match(/^(https?:\/\/[^\/]+)\/~comm-visit\//) : null;
    var base = (crm ? crm[1] : '') + '/~comm-visit';

    function readCookie() {
        var m = document.cookie.match(/(?:^|;\s*)sx_visit=([A-Za-z0-9_-]+)/);
        return m ? m[1] : null;
    }

    function writeCookie(token) {
        document.cookie = COOKIE + '=' + token + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    }

    function clearCookie() {
        document.cookie = COOKIE + '=; path=/; max-age=0; SameSite=Lax';
    }

    // The code leaves the address bar before anything else.
    var code = null;
    var search = location.search.replace(/^\?/, '');
    if (search) {
        var rest = [];
        search.split('&').forEach(function (pair) {
            var name = pair.split('=')[0];
            if (name.toLowerCase() === 'sx') {
                code = decodeURIComponent((pair.split('=')[1] || '').replace(/\+/g, ' '));
            } else if (pair !== '') {
                rest.push(pair);
            }
        });
        if (code !== null && window.history && history.replaceState) {
            history.replaceState(history.state, '', location.pathname + (rest.length ? '?' + rest.join('&') : '') + location.hash);
        }
    }
    if (code !== null && !/^[A-Za-z0-9_-]{16}$/.test(code)) {
        code = null;
    }
    var token = readCookie();
    if (!code && !token) {
        return;
    }

    function post(action, data) {
        var body = new FormData();
        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });
        return fetch(base + '/' + action, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            keepalive: true
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('http');
            }
            return response.json();
        });
    }

    window.sxLinkSignal.whenSeen(code ? SEEN_DELAY : PAGE_DELAY, function () {
        var data = {url: location.href, title: document.title};
        if (code) {
            var signals = window.sxLinkSignal.browserSignals();
            Object.keys(signals).forEach(function (key) {
                data[key] = signals[key];
            });
            data.code = code;
            if (token) {
                data.token = token;
            }
            return post('seen', data).then(function (response) {
                if (response && response.token) {
                    writeCookie(response.token);
                }
            });
        }
        data.token = token;
        return post('page', data).then(function (response) {
            if (response && (response.result === 'ended' || response.result === 'unknown')) {
                clearCookie();
            }
        });
    });
})();
