/* ConTracS source guard — a deterrent against casual source viewing.
 *
 * IMPORTANT (read before judging this file): it is impossible to truly hide
 * client-side code. The browser must receive the HTML, CSS and JavaScript to
 * render the application, so a determined user can always bypass these checks
 * (browser menu, disabled JavaScript, proxy tools, or simply editing this file
 * in the response). Real protection lives on the server: PHP source code and
 * database credentials are never sent to the browser, and every sensitive
 * action is authorized server-side with sessions, roles and CSRF tokens.
 *
 * This script only raises the bar for casual users. It is intentionally
 * non-destructive: it never breaks the application's normal functionality.
 */
(function () {
    'use strict';
    if (window.__ctrSourceGuard) return;
    window.__ctrSourceGuard = true;

    /* 1) Block the most common "view source / developer tools" shortcuts.
     *    Normal app shortcuts (Enter, Tab, arrows, Ctrl+C/V/X, etc.) are
     *    left untouched so the system keeps working as expected. */
    function isSourceShortcut(e) {
        var k = e.keyCode || e.which;
        var mod = e.ctrlKey || e.metaKey;
        if (k === 123) return true;                              // F12
        if (mod && k === 85) return true;                        // Ctrl+U / Cmd+U (view source)
        if (mod && k === 83) return true;                        // Ctrl+S / Cmd+S (save page)
        if (mod && e.shiftKey && (k === 73 || k === 74)) return true; // Ctrl+Shift+I / J (devtools / console)
        if (mod && e.shiftKey && k === 67) return true;          // Ctrl+Shift+C (inspect element)
        return false;
    }
    document.addEventListener('keydown', function (e) {
        if (isSourceShortcut(e)) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);

    /* 2) Suppress the right-click context menu (which offers "Inspect"). */
    document.addEventListener('contextmenu', function (e) {
        e.preventDefault();
        return false;
    });

    /* 3) Warn when DevTools is (likely) open. Heuristic only: docked or
     *    separate-window DevTools change the window's outer vs inner size.
     *    Always-on-top / undocked tools bypass this, so treat the warning
     *    as a nudge rather than a gate. Dismissible so it never blocks work. */
    var warned = false;
    function devtoolsLikelyOpen() {
        try {
            var w = window.outerWidth - window.innerWidth;
            var h = window.outerHeight - window.innerHeight;
            return w > 160 || h > 160;
        } catch (err) {
            return false;
        }
    }
    function showDevtoolsWarning() {
        if (warned) return;
        warned = true;
        if (!document.body) return;
        var box = document.createElement('div');
        box.id = 'ctrSourceGuardWarn';
        box.setAttribute('role', 'alert');
        box.style.cssText =
            'position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:100000;' +
            'background:#7f1d1d;color:#fff;padding:10px 16px;border-radius:10px;' +
            'font:600 13px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;' +
            'box-shadow:0 10px 30px rgba(0,0,0,.35);display:flex;align-items:center;' +
            'gap:12px;max-width:90vw;';
        box.innerHTML =
            '<span>Developer tools are open. For the security of this system, ' +
            'please close them while using the application.</span>' +
            '<button type="button" style="background:rgba(255,255,255,.2);color:#fff;' +
            'border:1px solid rgba(255,255,255,.4);border-radius:8px;padding:4px 10px;' +
            'font-weight:700;cursor:pointer;">Dismiss</button>';
        var btn = box.querySelector('button');
        if (btn) {
            btn.addEventListener('click', function () { box.remove(); });
        }
        document.body.appendChild(box);
    }
    if (devtoolsLikelyOpen()) showDevtoolsWarning();
    window.setInterval(function () {
        if (devtoolsLikelyOpen()) showDevtoolsWarning();
    }, 2000);

    /* 4) A console notice — the console itself is only visible with
     *    DevTools open, so this doubles as a deterrent message. */
    try {
        console.log('%cConTracS', 'font-size:20px;font-weight:700;');
        console.log('%cSource viewing is restricted for security. PHP source and credentials never reach the browser.', 'color:#9ca3af;');
    } catch (err) {}
})();
