/* ui-fixes.js — replaces the browser's plain alert() pop-up with a styled toast.
   Anything in the system that calls alert('…') now shows a message in the top-right corner. */
(function () {
    'use strict';

    function host() {
        var h = document.getElementById('uiToastHost');
        if (!h) {
            h = document.createElement('div');
            h.id = 'uiToastHost';
            h.setAttribute('aria-live', 'polite');
            (document.body || document.documentElement).appendChild(h);
        }
        return h;
    }

    function guessType(msg) {
        var m = String(msg).toLowerCase();
        if (/(error|fail|could not|cannot|can't|invalid|network|not (saved|deleted|updated)|wrong|denied|refresh)/.test(m)) return 'error';
        if (/(saved|success|updated|deleted|confirmed|sent|done|recorded)/.test(m)) return 'success';
        return 'info';
    }

    window.uiToast = function (msg, type) {
        if (!document.body) { document.addEventListener('DOMContentLoaded', function () { window.uiToast(msg, type); }); return; }
        type = type || guessType(msg);
        var icons = { error: 'bi-x-octagon-fill', success: 'bi-check-circle-fill', info: 'bi-info-circle-fill' };
        var t = document.createElement('div');
        t.className = 'ui-toast' + (type === 'error' ? ' ui-toast-error' : type === 'success' ? ' ui-toast-success' : '');
        t.setAttribute('role', type === 'error' ? 'alert' : 'status');

        var i = document.createElement('i');
        i.className = 'bi ' + (icons[type] || icons.info);
        i.setAttribute('aria-hidden', 'true');

        var span = document.createElement('span');
        span.className = 'ui-toast-msg';
        span.textContent = String(msg);          // textContent: never interpreted as HTML

        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'ui-toast-x';
        x.setAttribute('aria-label', 'Dismiss');
        x.innerHTML = '&times;';

        t.appendChild(i); t.appendChild(span); t.appendChild(x);
        host().appendChild(t);

        function close() { if (t.parentNode) t.parentNode.removeChild(t); }
        x.addEventListener('click', close);
        setTimeout(close, type === 'error' ? 8000 : 5000);
    };

    // Every alert('…') in the system now uses the toast.
    window.alert = function (msg) { window.uiToast(msg); };
})();
