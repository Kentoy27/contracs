    // MediaPipe's wasm prints its internal glog/TFLite chatter straight to
    // the console (it appears even in Google's official examples,
    // github.com/google-ai-edge/mediapipe #5639) and is cosmetic. Silence
    // that noise — but only messages that look like MediaPipe internals
    // (glog W/I lines, "INFO:" prefixes, or known benign strings), so real
    // app errors are never hidden.
    (function () {
        var benign = [
            'OpenGL error checking is disabled',
            'Using NORM_RECT without IMAGE_DIMENSIONS',
            'Sets FaceBlendshapesGraph acceleration to xnnpack by default'
        ];
        var glogRe = /^[WI]\d{4} \d{2}:\d{2}:\d{2}\.\d+ \d+ /;
        function matches(msg) {
            msg = String(msg == null ? '' : msg);
            if (glogRe.test(msg)) return true;
            if (/^(INFO|WARNING|WARN): /.test(msg)) return true;
            for (var i = 0; i < benign.length; i++) {
                if (msg.indexOf(benign[i]) !== -1) return true;
            }
            return false;
        }
        try {
            var origWarn = window.console.warn.bind(window.console);
            var origLog = window.console.log.bind(window.console);
            var origError = window.console.error.bind(window.console);
            window.console.warn = function () {
                if (matches(arguments.length ? arguments[0] : '')) return;
                origWarn.apply(window.console, arguments);
            };
            window.console.log = function () {
                if (matches(arguments.length ? arguments[0] : '')) return;
                origLog.apply(window.console, arguments);
            };
            // MediaPipe's glog output can land on stderr (console.error) too —
            // e.g. the XNNPACK delegate INFO line. Filter it the same way;
            // real errors never match the patterns above.
            window.console.error = function () {
                if (matches(arguments.length ? arguments[0] : '')) return;
                origError.apply(window.console, arguments);
            };
        } catch (e) {}
    })();
    // Table action dropdowns (the "⋮" menus in the users / attendance tables)
    // are positioned by Popper against the clipped .table-responsive and
    // .main-content overflow boxes instead of the real viewport — so on tall
    // tables the menu drops below the fold (or is cut off by the table edge)
    // and you must scroll to reach the actions. Re-point those menus at the
    // viewport (strategy: fixed) so they escape the containers and flip up
    // automatically when there's no room below the button.
    (function () {
        if (!window.bootstrap || !window.bootstrap.Dropdown) return;
        var D = window.bootstrap.Dropdown;
        function hasPositionTrap(el) {
            // A transform/filter/will-change/contain ancestor would hijack
            // position:fixed — skip the fix there and keep default behavior.
            var cur = el;
            while (cur && cur !== document.body) {
                var cs = window.getComputedStyle(cur);
                if ((cs.transform && cs.transform !== 'none') ||
                    (cs.filter && cs.filter !== 'none') ||
                    (cs.willChange && cs.willChange !== 'auto') ||
                    (cs.contain && cs.contain !== 'none')) return true;
                cur = cur.parentElement;
            }
            return false;
        }
        function fixedStrategyConfig() {
            return function (cfg) {
                try { cfg.strategy = 'fixed'; } catch (e) {}
                return cfg;
            };
        }
        // Bootstrap fires show.bs.dropdown BEFORE it creates the Popper, so
        // injecting the fixed strategy here is picked up by _getPopperConfig()
        // for this and every later opening of that menu.
        document.addEventListener('show.bs.dropdown', function (e) {
            var toggle = e && e.target;
            // Toggles are buttons with a data-bs-toggle="dropdown" attribute;
            // they may or may not carry the .dropdown-toggle class.
            if (!toggle || !toggle.getAttribute || toggle.getAttribute('data-bs-toggle') !== 'dropdown') return;
            var menu = toggle.nextElementSibling;
            if (!menu || !menu.classList || !menu.classList.contains('dropdown-menu')) return;
            if (!menu.closest('.table, .table-responsive')) return;
            if (hasPositionTrap(menu)) return;
            var inst = D.getInstance(toggle);
            if (inst && inst._config && typeof inst._config.popperConfig !== 'function') {
                try { inst._config.popperConfig = fixedStrategyConfig(); } catch (err) {}
            }
        });
        // While a table dropdown is open, keep it glued to its button if the
        // page scrolls (fixed positioning no longer rides along inside the
        // scroll container the way absolute positioning did).
        document.addEventListener('scroll', function () {
            var open = document.querySelectorAll('.table .dropdown-toggle[aria-expanded="true"], .table-responsive .dropdown-toggle[aria-expanded="true"]');
            for (var i = 0; i < open.length; i++) {
                var inst = D.getInstance(open[i]);
                if (inst && inst._popper) {
                    try { inst.update(); } catch (err) {}
                }
            }
        }, true);
    })();
     ;(function () {
    'use strict';

    var App = window.ContracsApp || {};
    if (App.__initialized) {
        window.ContracsApp = App;
        return;
    }
    App.__initialized = true;
    window.ContracsApp = App;

    var navState = {
        currentRoute: null,
        currentUrl: null,
        abortController: null,
        loading: false
    };

    function getMainContentEl() {
        return document.querySelector('.main-content');
    }

    function parseUrl(url) {
        try {
            return new URL(url, window.location.href);
        } catch (e) {
            var a = document.createElement('a');
            a.href = url;
            return {
                href: a.href,
                origin: a.origin || (window.location.protocol + '//' + window.location.host),
                pathname: a.pathname || '',
                search: a.search || '',
                hash: a.hash || ''
            };
        }
    }

    function normalizeRoute(route) {
        var r = String(route == null ? '' : route).trim();
        if (!r) return 'index';
        r = r.replace(/^\//, '');
        r = r.replace(/\.php$/i, '');
        if (!r) return 'index';
        return r;
    }

    function routeFromUrl(url) {
        var u = parseUrl(url);
        var path = String(u.pathname || '');
        var name = path.split('/').pop() || '';
        return normalizeRoute(name);
    }

    // Server-provided flag: when the app runs on a server without a rewrite
    // layer (bare "php -S"), page URLs carry an explicit .php suffix. Map
    // query-based API endpoints ("users?ajax=1") to match.
    var CTR_PHP_URLS = (function () {
        try { return !!(window.CTR_CONFIG && window.CTR_CONFIG.phpUrls); }
        catch (e) { return false; }
    })();

    function pageUrl(name) {
        var n = normalizeRoute(name);
        return CTR_PHP_URLS ? n + '.php' : n;
    }

    function setActiveRoute(route) {
        var r = normalizeRoute(route);
        var links = document.querySelectorAll('.nxl-link');
        for (var i = 0; i < links.length; i++) {
            var link = links[i];
            var item = link.closest ? link.closest('.nxl-item') : null;
            if (!item) continue;
            item.classList.remove('active');
        }
        for (var j = 0; j < links.length; j++) {
            var l = links[j];
            var href = l.getAttribute('href') || '';
            var hr = normalizeRoute(href.split('?')[0].split('#')[0]);
            if (hr === r) {
                var it = l.closest ? l.closest('.nxl-item') : null;
                if (it) it.classList.add('active');
            }
        }
    }

    function dispatchPjaxComplete() {
        try {
            document.dispatchEvent(new Event('pjax:complete'));
        } catch (e) {
            var ev = document.createEvent('Event');
            ev.initEvent('pjax:complete', true, true);
            document.dispatchEvent(ev);
        }
    }

    var pages = {};

    function destroyRoute(route) {
        var r = normalizeRoute(route);
        if (pages[r] && typeof pages[r].destroy === 'function') {
            try { pages[r].destroy(); } catch (e) {}
        }
    }

    function initRoute(route) {
        var r = normalizeRoute(route);
        if (pages[r] && typeof pages[r].init === 'function') {
            try { pages[r].init(); } catch (e) {}
        }
    }

    function setLoading(isLoading) {
        var mainContent = getMainContentEl();
        if (!mainContent) return;
        if (isLoading) {
            mainContent.style.opacity = '0.5';
            mainContent.style.pointerEvents = 'none';
            mainContent.style.transition = 'opacity 0.2s ease';
        } else {
            mainContent.style.opacity = '1';
            mainContent.style.pointerEvents = 'auto';
        }
    }

    function extractMainContent(html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(String(html || ''), 'text/html');
        var newContent = doc.querySelector('.main-content');
        var title = '';
        try {
            title = (doc.querySelector('title') && doc.querySelector('title').textContent) ? String(doc.querySelector('title').textContent) : '';
        } catch (e) {}
        return { el: newContent, title: title };
    }

    function cleanupOverlays() {
        try {
            var openModals = document.querySelectorAll('.modal.show');
            for (var i = 0; i < openModals.length; i++) {
                var m = openModals[i];
                if (window.bootstrap && window.bootstrap.Modal) {
                    var inst = window.bootstrap.Modal.getInstance(m) || window.bootstrap.Modal.getOrCreateInstance(m);
                    if (inst && typeof inst.hide === 'function') inst.hide();
                } else {
                    m.classList.remove('show');
                    m.style.display = 'none';
                    m.setAttribute('aria-hidden', 'true');
                }
            }
        } catch (e1) {}
        try {
            var backs = document.querySelectorAll('.modal-backdrop');
            for (var j = 0; j < backs.length; j++) {
                try { backs[j].remove(); } catch (e2) {}
            }
        } catch (e3) {}
        try {
            document.body.classList.remove('modal-open');
            document.body.style.paddingRight = '';
            document.body.style.overflow = '';
        } catch (e4) {}
    }

    function loadUrl(url, pushState) {
        var targetUrl = String(url || '');
        if (!targetUrl) return;

        cleanupOverlays();

        if (navState.abortController && typeof navState.abortController.abort === 'function') {
            try { navState.abortController.abort(); } catch (e) {}
        }
        navState.abortController = (window.AbortController ? new AbortController() : null);

        var mainContent = getMainContentEl();
        if (!mainContent) {
            window.location.href = targetUrl;
            return;
        }

        navState.loading = true;
        setLoading(true);

        var opts = {
            headers: { 'X-Requested-With': 'fetch' }
        };
        if (navState.abortController) {
            opts.signal = navState.abortController.signal;
        }

        fetch(targetUrl, opts).then(function (res) {
            if (!res.ok) throw new Error('Network error');
            return res.text();
        }).then(function (html) {
            var extracted = extractMainContent(html);
            if (!extracted.el) {
                window.location.href = targetUrl;
                return;
            }

            destroyRoute(navState.currentRoute);

            mainContent.innerHTML = extracted.el.innerHTML;
            cleanupOverlays();

            if (extracted.title) {
                try { document.title = extracted.title; } catch (e) {}
            }

            if (pushState) {
                try {
                    history.pushState({ url: targetUrl }, '', targetUrl);
                } catch (e) {}
            }

            navState.currentUrl = parseUrl(targetUrl).href || targetUrl;
            navState.currentRoute = routeFromUrl(targetUrl);
            setActiveRoute(navState.currentRoute);
            initRoute(navState.currentRoute);
            dispatchPjaxComplete();
        }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
            window.location.href = targetUrl;
        }).finally(function () {
            navState.loading = false;
            setLoading(false);
        });
    }

    function shouldHandleAnchorClick(e, a) {
        if (!a) return false;
        if (e.defaultPrevented) return false;
        if (typeof e.button === 'number' && e.button !== 0) return false;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return false;
        var href = a.getAttribute('href') || '';
        if (!href || href === '#' || href.indexOf('javascript:') === 0) return false;
        var u = parseUrl(href);
        if (u.origin && u.origin !== window.location.origin) return false;
        return true;
    }

    function initNavigation() {
        if (App.__navBound) return;
        App.__navBound = true;

        document.addEventListener('click', function (e) {
            var t = e.target;
            var a = (t && t.closest) ? t.closest('a.nxl-link, a.b-brand') : null;
            if (!a) return;
            if (!shouldHandleAnchorClick(e, a)) return;
            e.preventDefault();
            loadUrl(a.getAttribute('href'), true);
        });

        window.addEventListener('popstate', function (e) {
            var url = (e && e.state && e.state.url) ? e.state.url : (window.location.pathname.split('/').pop() || 'index');
            loadUrl(url, false);
        });
    }

    function initCurrentFromLocation() {
        var route = routeFromUrl(window.location.href);
        navState.currentRoute = route;
        navState.currentUrl = window.location.href;
        setActiveRoute(route);
        initRoute(route);
    }

    function initSuperadminProfile() {
        if (App.__superadminProfileBound) return;
        App.__superadminProfileBound = true;

        function el(id) { return document.getElementById(id); }

        function fetchJson(url, opts) {
            return fetch(url, Object.assign({
                cache: 'no-store',
                headers: Object.assign({ 'Accept': 'application/json' }, (opts && opts.headers) || {})
            }, opts || {})).then(function (r) {
                return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
            });
        }

        function postForm(action, payload) {
            var body = new URLSearchParams();
            body.set('action', action);
            Object.keys(payload || {}).forEach(function (k) {
                if (payload[k] === undefined || payload[k] === null) return;
                body.set(k, String(payload[k]));
            });
            return fetchJson(pageUrl('users') + '?ajax=1&action=' + encodeURIComponent(action), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
                body: body.toString()
            });
        }

        function openModal() {
            var modal = el('superAdminProfileModal');
            if (!modal) return;
            fetchJson(pageUrl('users') + '?ajax=1&action=get_my_profile', { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    var msg = (res.data && res.data.message) ? res.data.message : 'Request failed.';
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                    return;
                }
                var u = res.data.user || {};
                if (el('saProfileName')) el('saProfileName').value = String(u.name || '');
                if (el('saProfileUsername')) el('saProfileUsername').value = String(u.username || '');
                if (el('saProfileRole')) el('saProfileRole').value = String(u.role || '');
                if (el('saProfileIdNumber')) el('saProfileIdNumber').value = String(u.id_number || '');
                if (el('saCurrentPassword')) el('saCurrentPassword').value = '';
                if (el('saNewPassword')) el('saNewPassword').value = '';
                if (el('saConfirmPassword')) el('saConfirmPassword').value = '';
                renderProfileQr(u);
                if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
            });
        }

        function renderProfileQr(u) {
            var wrap = el('saProfileQrWrap');
            var img = el('saProfileQrImg');
            var cap = el('saProfileQrCaption');
            var dl = el('saDownloadQrBtn');
            if (!wrap || !img) return;
            var payload = u && u.qr_payload ? String(u.qr_payload) : '';
            if (!payload) {
                wrap.style.display = 'none';
                if (dl) dl.style.display = 'none';
                if (cap) cap.textContent = 'QR code unavailable.';
                return;
            }
            var t = Date.now ? Date.now() : new Date().getTime();
            img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=360x360&data=' + encodeURIComponent(payload) + '&t=' + encodeURIComponent(String(t));
            wrap.style.display = 'block';
            if (dl) {
                dl.style.display = '';
                dl.setAttribute('data-qr-payload', payload);
                if (u && u.name) dl.setAttribute('data-qr-name', String(u.name));
            }
            if (cap) {
                var parts = [];
                if (u && u.name) parts.push(String(u.name));
                if (u && u.id_number) parts.push(String(u.id_number));
                cap.textContent = parts.join(' • ');
            }
        }

        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('#superAdminProfileBtn') : null;
            if (!btn) return;
            e.preventDefault();
            openModal();
        });

        document.addEventListener('click', function (e) {
            var dl = e.target && e.target.closest ? e.target.closest('#saDownloadQrBtn') : null;
            if (!dl) return;
            e.preventDefault();
            var payload = dl.getAttribute('data-qr-payload') || '';
            if (!payload) return;
            var name = dl.getAttribute('data-qr-name') || 'profile';
            var url = 'https://api.qrserver.com/v1/create-qr-code/?size=720x720&data=' + encodeURIComponent(payload);
            fetch(url).then(function (r) { return r.ok ? r.blob() : null; }).then(function (blob) {
                if (!blob) {
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'qr-' + name.replace(/[^a-z0-9-_]+/gi, '_') + '.png';
                    a.target = '_blank';
                    a.rel = 'noopener';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    return;
                }
                var objUrl = URL.createObjectURL(blob);
                var a2 = document.createElement('a');
                a2.href = objUrl;
                a2.download = 'qr-' + name.replace(/[^a-z0-9-_]+/gi, '_') + '.png';
                document.body.appendChild(a2);
                a2.click();
                a2.remove();
                setTimeout(function () { URL.revokeObjectURL(objUrl); }, 1000);
            }).catch(function () {
                var a = document.createElement('a');
                a.href = url;
                a.download = 'qr-' + name.replace(/[^a-z0-9-_]+/gi, '_') + '.png';
                a.target = '_blank';
                a.rel = 'noopener';
                document.body.appendChild(a);
                a.click();
                a.remove();
            });
        });

        function togglePwd(inputId, btnId) {
            var inp = el(inputId);
            var btn = el(btnId);
            if (!inp || !btn) return;
            var isPassword = inp.type === 'password';
            inp.type = isPassword ? 'text' : 'password';
            var icon = btn.querySelector ? btn.querySelector('i') : null;
            if (icon) {
                if (isPassword) icon.className = 'feather feather-eye-off';
                else icon.className = 'feather feather-eye';
            }
        }

        document.addEventListener('click', function (e) {
            var b = e.target && e.target.closest ? e.target.closest('#saToggleCurrentPassword, #saToggleNewPassword, #saToggleConfirmPassword') : null;
            if (!b) return;
            e.preventDefault();
            var id = b.id || '';
            if (id === 'saToggleCurrentPassword') return togglePwd('saCurrentPassword', 'saToggleCurrentPassword');
            if (id === 'saToggleNewPassword') return togglePwd('saNewPassword', 'saToggleNewPassword');
            if (id === 'saToggleConfirmPassword') return togglePwd('saConfirmPassword', 'saToggleConfirmPassword');
        });

        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('#saSavePasswordBtn') : null;
            if (!btn) return;
            e.preventDefault();
            var current = el('saCurrentPassword') ? (el('saCurrentPassword').value || '') : '';
            var next = el('saNewPassword') ? (el('saNewPassword').value || '') : '';
            var confirm = el('saConfirmPassword') ? (el('saConfirmPassword').value || '') : '';
            if (!current || !next || !confirm) {
                if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: 'All password fields are required.' });
                return;
            }
            if (next !== confirm) {
                if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: 'New password and confirm password do not match.' });
                return;
            }
            postForm('change_my_password', {
                current_password: current,
                new_password: next,
                confirm_password: confirm
            }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    var msg = (res.data && res.data.message) ? res.data.message : 'Request failed.';
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                    return;
                }
                var modal = el('superAdminProfileModal');
                if (modal && window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).hide();
                if (window.Swal) Swal.fire({ icon: 'success', title: 'Password Updated', timer: 1200, showConfirmButton: false });
            });
        });
    }

    function initAdminPlanUsage() {
        if (App.__adminPlanUsageBound) return;
        App.__adminPlanUsageBound = true;

        function el(id) { return document.getElementById(id); }

        function fetchJson(url, opts) {
            return fetch(url, Object.assign({
                cache: 'no-store',
                headers: Object.assign({ 'Accept': 'application/json' }, (opts && opts.headers) || {})
            }, opts || {})).then(function (r) {
                return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
            });
        }

        function setBadge(text, kind) {
            var b = el('apuStatusBadge');
            if (!b) return;
            b.textContent = String(text == null ? '-' : text);
            b.className = 'badge';
            if (kind === 'success') b.className += ' bg-soft-success text-success';
            else if (kind === 'danger') b.className += ' bg-soft-danger text-danger';
            else if (kind === 'warning') b.className += ' bg-soft-warning text-warning';
            else b.className += ' bg-soft-secondary text-secondary';
        }

        function setAlert(msg) {
            var a = el('apuAlert');
            if (!a) return;
            var t = String(msg == null ? '' : msg).trim();
            if (!t) {
                a.classList.add('d-none');
                a.textContent = '';
                return;
            }
            a.textContent = t;
            a.classList.remove('d-none');
        }

        function setVal(id, val) {
            var x = el(id);
            if (!x) return;
            x.value = String(val == null ? '' : val);
        }

        function setText(id, val) {
            var x = el(id);
            if (!x) return;
            x.textContent = String(val == null ? '' : val);
        }

        function setProgress(percent, kind) {
            var pb = el('apuProgressBar');
            if (!pb) return;
            var p = parseInt(String(percent == null ? 0 : percent), 10);
            if (!isFinite(p)) p = 0;
            if (p < 0) p = 0;
            if (p > 100) p = 100;
            pb.style.width = String(p) + '%';
            pb.className = 'progress-bar';
            if (kind === 'success') pb.className += ' bg-success';
            else if (kind === 'danger') pb.className += ' bg-danger';
            else if (kind === 'warning') pb.className += ' bg-warning';
            else pb.className += ' bg-primary';
        }

        function openModal() {
            var modal = el('adminPlanUsageModal');
            if (!modal) return;

            setAlert('');
            setBadge('Loading...', 'secondary');
            setProgress(0, 'primary');
            setText('apuProgressLeft', 'Loading...');
            setText('apuProgressRight', '');
            setVal('apuPlanMonths', '');
            setVal('apuPlanExpiresAt', '');
            setVal('apuDaysRemaining', '');
            setVal('apuUsersCreated', '');
            var ftBadge = el('apuFreeTrialBadge');
            if (ftBadge) ftBadge.classList.add('d-none');

            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();

            fetchJson(pageUrl('users') + '?ajax=1&action=get_my_plan_usage', { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    var msg = (res.data && res.data.message) ? res.data.message : 'Request failed.';
                    setBadge('Error', 'danger');
                    setAlert(msg);
                    return;
                }

                var u = res.data.usage || {};
                var isActive = u.is_active === true;
                var hasPlan = u.has_plan === true;
                var expired = u.expired === true;
                var isFreeTrial = u.is_free_trial === true;
                var months = u.plan_months != null ? u.plan_months : '';
                var expAt = u.plan_expires_at != null ? u.plan_expires_at : '';
                var daysTotal = u.days_total != null ? Number(u.days_total) : 0;
                var daysUsed = u.days_used != null ? Number(u.days_used) : 0;
                var daysRem = u.days_remaining != null ? Number(u.days_remaining) : 0;
                var percentUsed = u.percent_used != null ? Number(u.percent_used) : 0;
                var created = u.created_users_count != null ? u.created_users_count : 0;

                var monthsLabel = (months !== '' && months != null) ? String(months) : '';
                if (isFreeTrial && monthsLabel) {
                    monthsLabel += ' (Free Trial)';
                }

                var formattedExpAt = '';
                if (expAt) {
                    try {
                        var d = new Date(String(expAt).replace(' ', 'T'));
                        if (!isNaN(d.getTime())) {
                            var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                            var day = d.getDate();
                            var month = monthNames[d.getMonth()];
                            var year = d.getFullYear();
                            var hours = d.getHours();
                            var minutes = d.getMinutes();
                            var ampm = hours >= 12 ? 'PM' : 'AM';
                            hours = hours % 12;
                            hours = hours ? hours : 12;
                            formattedExpAt = month + ' ' + (day < 10 ? '0' + day : day) + ', ' + year + ' ' + hours + ':' + (minutes < 10 ? '0' + minutes : minutes) + ' ' + ampm;
                        }
                    } catch (e) {}
                }

                setVal('apuPlanMonths', monthsLabel);
                setVal('apuPlanExpiresAt', formattedExpAt || expAt || (hasPlan ? '-' : 'No plan'));
                setVal('apuDaysRemaining', hasPlan ? daysRem : '-');
                setVal('apuUsersCreated', created);

                if (isFreeTrial) {
                    var b = el('apuFreeTrialBadge');
                    if (b) b.classList.remove('d-none');
                }

                if (!isActive) {
                    setBadge('Inactive', 'danger');
                    setProgress(0, 'danger');
                    setText('apuProgressLeft', 'Account inactive');
                    setText('apuProgressRight', '');
                    return;
                }

                if (!hasPlan) {
                    setBadge('No plan', 'secondary');
                    setProgress(0, 'primary');
                    setText('apuProgressLeft', '0% used');
                    setText('apuProgressRight', '');
                    setAlert('No plan is assigned yet. Contact the Superadmin.');
                    return;
                }

                if (expired) {
                    setBadge('Expired', 'danger');
                    setProgress(100, 'danger');
                    setText('apuProgressLeft', '100% used');
                    setText('apuProgressRight', '0 days left');
                    setAlert('Your plan has expired.');
                    return;
                }

                var kind = 'success';
                if (daysRem <= 3) kind = 'danger';
                else if (daysRem <= 7) kind = 'warning';
                setBadge('Active', kind === 'success' ? 'success' : (kind === 'warning' ? 'warning' : 'danger'));
                setProgress(percentUsed, kind);
                var left = String(percentUsed) + '% used';
                if (daysTotal > 0) left += ' (' + String(daysUsed) + '/' + String(daysTotal) + ' days)';
                setText('apuProgressLeft', left);
                setText('apuProgressRight', String(daysRem) + ' day(s) left');
            }).catch(function () {
                setBadge('Error', 'danger');
                setAlert('Network error. Please try again.');
            });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('#adminPlanUsageBtn') : null;
            if (!btn) return;
            e.preventDefault();
            openModal();
        });
    }

    function initAdminPlanExtendRequest() {
        if (App.__adminPlanExtendBound) return;
        App.__adminPlanExtendBound = true;

        function el(id) { return document.getElementById(id); }

        function fetchJson(url, opts) {
            return fetch(url, Object.assign({
                cache: 'no-store',
                headers: Object.assign({ 'Accept': 'application/json' }, (opts && opts.headers) || {})
            }, opts || {})).then(function (r) {
                return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
            });
        }

        function setAlert(msg) {
            var a = el('apeAlert');
            if (!a) return;
            var t = String(msg == null ? '' : msg).trim();
            if (!t) {
                a.classList.add('d-none');
                a.textContent = '';
                return;
            }
            a.textContent = t;
            a.classList.remove('d-none');
        }

        function setAlert2(msg) {
            var a = el('apeAlert2');
            if (!a) return;
            var t = String(msg == null ? '' : msg).trim();
            if (!t) {
                a.classList.add('d-none');
                a.textContent = '';
                return;
            }
            a.textContent = t;
            a.classList.remove('d-none');
        }

        function setPayAmount(amountPhp, breakdownText, feeText) {
            var wrap = el('apePayInfo');
            var amountEl = el('apePayAmount');
            var breakdownEl = el('apePayBreakdown');
            var feeEl = el('apePayFeeText');
            if (!wrap || !amountEl) return;
            var n = parseInt(String(amountPhp == null ? '0' : amountPhp), 10);
            if (!isFinite(n) || n <= 0) {
                wrap.classList.add('d-none');
                amountEl.textContent = '₱--';
                if (breakdownEl) breakdownEl.textContent = '';
                if (feeEl) feeEl.textContent = '';
                return;
            }
            amountEl.textContent = '₱' + n.toLocaleString();
            if (breakdownEl) breakdownEl.textContent = String(breakdownText || '').trim();
            if (feeEl) feeEl.textContent = String(feeText || '').trim();
            wrap.classList.remove('d-none');
        }

        function loadPrice(months) {
            var m = parseInt(String(months || '0'), 10);
            if (!isFinite(m) || m <= 0) m = 1;
            if (m > 60) m = 60;
            fetchJson('notifications.php?action=get_plan_extension_price&months=' + encodeURIComponent(String(m)), { method: 'GET' })
                .then(function (res) {
                    if (!res.data || res.data.ok !== true) {
                        setPayAmount(0, '', '');
                        return;
                    }
                    setPayAmount(res.data.amount_php, res.data.breakdown_text, res.data.fee_text);
                })
                .catch(function () {
                    setPayAmount(0, '', '');
                });
        }

        function showStep1() {
            var s1 = el('apeStep1');
            var s2 = el('apeStep2');
            var backBtn = el('apeBackBtn');
            var submitBtn = el('apeSubmitBtn');
            var title = el('apeModalTitle');
            if (s1) s1.style.display = '';
            if (s2) s2.style.display = 'none';
            if (backBtn) backBtn.classList.add('d-none');
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Send Request'; }
            if (title) title.textContent = 'Request Plan Extension';
            setAlert('');
            setAlert2('');
        }

        function showStep2(amount) {
            var s1 = el('apeStep1');
            var s2 = el('apeStep2');
            var backBtn = el('apeBackBtn');
            var submitBtn = el('apeSubmitBtn');
            var title = el('apeModalTitle');
            var step2Amount = el('apeStep2Amount');
            var payBreakdown = el('apePayBreakdown');
            var payFeeText = el('apePayFeeText');
            var step2Breakdown = el('apeStep2Breakdown');
            var step2FeeText = el('apeStep2FeeText');
            var proofFile = el('apeProofFile');
            var proofPreview = el('apeProofPreview');
            if (s1) s1.style.display = 'none';
            if (s2) s2.style.display = '';
            if (backBtn) backBtn.classList.remove('d-none');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Submit Payment Proof'; }
            if (title) title.textContent = 'GCash Payment';
            if (step2Amount) step2Amount.textContent = amount || '₱--';
            if (step2Breakdown) step2Breakdown.textContent = payBreakdown ? String(payBreakdown.textContent || '').trim() : '';
            if (step2FeeText) step2FeeText.textContent = payFeeText ? String(payFeeText.textContent || '').trim() : '';
            if (proofFile) proofFile.value = '';
            if (proofPreview) proofPreview.classList.add('d-none');
            setAlert('');
            setAlert2('');
        }

        function openModal() {
            var modal = el('adminPlanExtendModal');
            if (!modal) return;
            showStep1();
            var m = el('apeMonths');
            if (m) m.value = '1';
            var note = el('apeNote');
            if (note) note.value = '';
            loadPrice(1);
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
        }

        function goBack() {
            showStep1();
        }

        function goToCheckout() {
            var monthsEl = el('apeMonths');
            var noteEl = el('apeNote');
            var submitBtn = el('apeSubmitBtn');

            var months = monthsEl ? parseInt(String(monthsEl.value || '0'), 10) : 0;
            if (!isFinite(months) || months <= 0) months = 1;
            if (months > 60) months = 60;
            var note = noteEl ? String(noteEl.value || '').trim() : '';

            setAlert('');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Opening GCash checkout...';
            }

            var fd = new FormData();
            fd.append('action', 'create_plan_extension_payment');
            fd.append('months', String(months));
            fd.append('note', note);

            fetchJson('notifications.php', { method: 'POST', body: fd }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Send Request';
                    }
                    setAlert((res.data && res.data.message) ? res.data.message : 'Failed to start the payment. Please try again.');
                    return;
                }
                var url = (res.data.payment && res.data.payment.checkout_url) ? String(res.data.payment.checkout_url) : '';
                if (!url) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Send Request';
                    }
                    setAlert('Payment gateway did not return a checkout URL.');
                    return;
                }
                // Leave the modal in place while the hosted checkout opens.
                window.location.href = url;
            }).catch(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Send Request';
                }
                setAlert('Network error. Please try again.');
            });
        }

        function submit() {
            var step2 = el('apeStep2');
            if (step2 && step2.style.display !== 'none') {
                submitWithProof();
                return;
            }

            var monthsEl = el('apeMonths');
            var noteEl = el('apeNote');

            var months = monthsEl ? parseInt(String(monthsEl.value || '0'), 10) : 0;
            if (!isFinite(months) || months <= 0) months = 1;
            if (months > 60) months = 60;

            var amountText = el('apePayAmount');
            var amountStr = amountText ? amountText.textContent.replace('₱', '').replace(/,/g, '').trim() : '0';
            var amount = parseInt(amountStr, 10);
            if (!isFinite(amount) || amount <= 0) amount = 0;

            goToCheckout();
        }

        function submitWithProof() {
            var monthsEl = el('apeMonths');
            var noteEl = el('apeNote');
            var proofFile = el('apeProofFile');
            var submitBtn = el('apeSubmitBtn');

            var months = monthsEl ? parseInt(String(monthsEl.value || '0'), 10) : 0;
            if (!isFinite(months) || months <= 0) months = 1;
            if (months > 60) months = 60;
            var note = noteEl ? String(noteEl.value || '').trim() : '';

            setAlert2('');
            if (!proofFile || !proofFile.files || !proofFile.files[0]) {
                setAlert2('Please upload your proof of payment before submitting.');
                return;
            }

            var file = proofFile.files[0];
            if (file.size > 10 * 1024 * 1024) {
                setAlert2('File too large. Maximum size is 10MB.');
                return;
            }
            var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
            if (allowedTypes.indexOf(file.type) === -1) {
                setAlert2('Invalid file type. Only JPG, PNG, GIF, WebP, and PDF are allowed.');
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting...';
            }

            var fd = new FormData();
            fd.append('action', 'request_plan_extension');
            fd.append('months', String(months));
            fd.append('note', note);
            fd.append('payment_proof', proofFile.files[0]);

            fetchJson('notifications.php', { method: 'POST', body: fd }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit Payment Proof';
                    }
                    var msg = (res.data && res.data.message) ? res.data.message : 'Failed to submit request.';
                    setAlert2(msg);
                    return;
                }
                var modal = el('adminPlanExtendModal');
                if (modal && window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).hide();
                if (window.Swal) Swal.fire({ icon: 'success', title: 'Request Submitted', text: 'Your plan extension request with proof has been sent to Superadmin for approval.', timer: 2500, showConfirmButton: false });
                showStep1();
            }).catch(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Payment Proof';
                }
                setAlert2('Network error. Please try again.');
            });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('#adminPlanExtendBtn') : null;
            if (!btn) return;
            e.preventDefault();
            openModal();
        });

        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('#apeSubmitBtn') : null;
            if (!btn) return;
            e.preventDefault();
            submit();
        });

        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('#apeBackBtn') : null;
            if (!btn) return;
            e.preventDefault();
            goBack();
        });

        document.addEventListener('change', function (e) {
            var m = e.target && e.target.closest ? e.target.closest('#apeMonths') : null;
            if (!m) return;
            var months = parseInt(String(m.value || '0'), 10);
            if (!isFinite(months) || months <= 0) months = 1;
            if (months > 60) months = 60;
            loadPrice(months);
        });

        document.addEventListener('change', function (e) {
            var f = e.target && e.target.closest ? e.target.closest('#apeProofFile') : null;
            if (!f) return;
            var preview = el('apeProofPreview');
            var previewImg = el('apeProofPreviewImg');
            var submitBtn = el('apeSubmitBtn');
            if (!f.files || !f.files[0]) {
                if (preview) preview.classList.add('d-none');
                if (submitBtn) submitBtn.disabled = true;
                return;
            }
            var file = f.files[0];
            if (file.type.indexOf('image/') === 0) {
                var reader = new FileReader();
                reader.onload = function (ev) {
                    if (previewImg) previewImg.src = ev.target.result;
                    if (preview) preview.classList.remove('d-none');
                };
                reader.readAsDataURL(file);
            } else {
                if (previewImg) previewImg.src = '';
                if (preview) preview.classList.add('d-none');
            }
            if (submitBtn) submitBtn.disabled = false;
        });

        window.addEventListener('message', function (e) {
            if (!e || !e.data || e.data.type !== 'ctr_plan_payment_paid') return;
            var modal = el('adminPlanExtendModal');
            if (modal && window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).hide();
            if (window.Swal) {
                if (e.data.paid === true) {
                    Swal.fire({ icon: 'success', title: 'Payment Successful', text: 'Your plan has been extended automatically.', timer: 2500, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'info', title: 'Payment Processing', text: 'We will confirm your payment shortly.', timer: 2500, showConfirmButton: false });
                }
            }
            showStep1();
        });
    }

    function initAdminHomeLink() {
        if (App.__adminHomeLinkBound) return;
        App.__adminHomeLinkBound = true;

        function el(id) { return document.getElementById(id); }

        function fetchJson(url, opts) {
            return fetch(url, Object.assign({
                cache: 'no-store',
                headers: Object.assign({ 'Accept': 'application/json' }, (opts && opts.headers) || {})
            }, opts || {})).then(function (r) {
                return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
            });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function setBadge(text, kind) {
            var b = el('ahlStatusBadge');
            if (!b) return;
            b.textContent = String(text == null ? '-' : text);
            b.className = 'badge';
            if (kind === 'success') b.className += ' bg-soft-success text-success';
            else if (kind === 'danger') b.className += ' bg-soft-danger text-danger';
            else if (kind === 'warning') b.className += ' bg-soft-warning text-warning';
            else b.className += ' bg-soft-secondary text-secondary';
        }

        function setAlert(msg) {
            var a = el('ahlAlert');
            if (!a) return;
            var t = String(msg == null ? '' : msg).trim();
            if (!t) { a.classList.add('d-none'); a.textContent = ''; return; }
            a.textContent = t;
            a.classList.remove('d-none');
        }

        function formatDateTime(value) {
            var t = String(value == null ? '' : value).trim();
            if (!t) return '';
            var m = t.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})(?::(\d{2}))?$/);
            if (!m) return t;
            var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            var month = months[parseInt(m[2], 10) - 1];
            var day = parseInt(m[3], 10);
            var hh = parseInt(m[4], 10);
            var mm = m[5];
            var ap = hh >= 12 ? 'PM' : 'AM';
            var h12 = hh % 12; if (h12 === 0) h12 = 12;
            return month + ' ' + day + ', ' + m[1] + ' ' + h12 + ':' + mm + ' ' + ap;
        }

        function renderLinks(data) {
            var list = el('ahlLinksList');
            var countEl = el('ahlDeviceCount');
            if (countEl) countEl.textContent = String(data.count || 0);

            var isFreeTrial = data.is_free_trial === true;
            var ftB = el('ahlFreeTrialBadge');
            if (ftB) ftB.classList.toggle('d-none', !isFreeTrial);

            var expiresAt = data.expires_at || '';
            var expiresInfo = expiresAt ? ('Plan expires: ' + formatDateTime(expiresAt)) : '';

            if (!data.links || !data.links.length) {
                setBadge('No Links', 'secondary');
                if (list) list.innerHTML = '<div class="text-muted small text-center py-3">No active links yet.</div>'
                    + (expiresInfo ? '<div class="text-muted small text-center mb-2">' + escapeHtml(expiresInfo) + '</div>' : '');
                return;
            }

            setBadge(data.count + ' / ' + data.max, data.count >= data.max ? 'warning' : 'success');

            if (list) {
                var expiryHtml = expiresInfo ? '<div class="text-muted small mb-2" style="font-size:11px;"><i class="feather-clock" style="font-size:11px;"></i> ' + escapeHtml(expiresInfo) + '</div>' : '';
                list.innerHTML = expiryHtml + data.links.map(function (lnk, idx) {
                    var bound = lnk.device_bound === true;
                    var boundAt = lnk.device_bound_at || '';
                    var lastSeen = lnk.last_seen_at || '';
                    var url = lnk.url || '';
                    var linkId = lnk.id || 0;

                    // Extract short key from URL for display
                    var shortKey = '';
                    try {
                        var u = new URL(url);
                        var hk = u.searchParams.get('home_key') || '';
                        shortKey = hk.substring(0, 12) + (hk.length > 12 ? '...' : '');
                    } catch (e) {
                        var m = url.match(/home_key=([^&]+)/);
                        if (m) {
                            var tok = decodeURIComponent(m[1]);
                            shortKey = tok.substring(0, 12) + (tok.length > 12 ? '...' : '');
                        }
                    }

                    var statusBadge = bound
                        ? '<span class="badge bg-soft-warning text-warning" style="font-size:10px;">LOCKED</span>'
                        : '<span class="badge bg-soft-success text-success" style="font-size:10px;">ACTIVE</span>';

                    var deviceInfo = bound
                        ? ('Locked' + (boundAt ? (' since ' + formatDateTime(boundAt)) : ''))
                        : 'Awaiting first scan';
                    var lastSeenInfo = lastSeen ? ('Last seen: ' + formatDateTime(lastSeen)) : 'Never used';

                    var slotNum = idx + 1;

                    return ''
                        + '<div class="border rounded mb-2" style="padding:12px 14px;">'
                        + '  <div class="d-flex align-items-center gap-2 mb-2">'
                        + '    <span style="font-size:11px;font-weight:700;color:#6c757d;">#' + slotNum + '</span>'
                        + statusBadge
                        + '  </div>'
                        + '  <div class="input-group input-group-sm mb-1">'
                        + '    <span class="input-group-text" style="font-size:11px;background:transparent;border-right:none;"><i class="feather-link" style="font-size:12px;"></i></span>'
                        + '    <input class="form-control form-control-sm" readonly value="' + escapeHtml(url) + '" style="font-size:11px;border-left:none;background:transparent;">'
                        + '    <button type="button" class="btn btn-outline-secondary btn-sm ahl-copy-one" data-url="' + escapeHtml(url) + '" title="Copy link"><i class="feather-copy"></i></button>'
                        + '  </div>'
                        + '  <div class="d-flex justify-content-between align-items-center mt-1" style="font-size:11px;color:#6c757d;">'
                        + '    <span>' + escapeHtml(deviceInfo) + '</span>'
                        + '    <span>' + escapeHtml(lastSeenInfo) + '</span>'
                        + '  </div>'
                        + '</div>';
                }).join('');
            }
        }

        function openModal() {
            var modal = el('adminHomeLinkModal');
            if (!modal) return;
            setAlert('');
            setBadge('Loading...', 'secondary');
            var list = el('ahlLinksList');
            if (list) list.innerHTML = '<div class="text-muted small text-center py-3">Loading...</div>';
            var ftB = el('ahlFreeTrialBadge');
            if (ftB) ftB.classList.add('d-none');
            var countEl = el('ahlDeviceCount');
            if (countEl) countEl.textContent = '0';

            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();

            fetchJson(pageUrl('users') + '?ajax=1&action=get_my_home_links', { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    var msg = (res.data && res.data.message) ? res.data.message : 'Request failed.';
                    setBadge('Error', 'danger');
                    setAlert(msg);
                    return;
                }
                renderLinks(res.data);
            }).catch(function () {
                setBadge('Error', 'danger');
                setAlert('Network error. Please try again.');
            });
        }

        function copyToClipboard(text) {
            if (!text) { setAlert('No link to copy.'); return; }
            var done = function () {
                setAlert('');
                if (window.Swal) Swal.fire({ icon: 'success', title: 'Copied', timer: 900, showConfirmButton: false });
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function () {});
                return;
            }
            var tmp = document.createElement('input');
            tmp.value = text;
            document.body.appendChild(tmp);
            tmp.select();
            try { document.execCommand('copy'); done(); } catch (e) {}
            document.body.removeChild(tmp);
        }

        function issueNewLink() {
            setAlert('');
            var body = new URLSearchParams();
            fetchJson(pageUrl('users') + '?ajax=1&action=issue_my_home_link', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    var msg = (res.data && res.data.message) ? res.data.message : 'Failed to create link.';
                    setAlert(msg);
                    return;
                }
                openModal();
            }).catch(function () { setAlert('Network error.'); });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('#adminHomeLinkBtn') : null;
            if (!btn) return;
            e.preventDefault();
            openModal();
        });

        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('#ahlNewLinkBtn') : null;
            if (!btn) return;
            e.preventDefault();
            issueNewLink();
        });

        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('.ahl-copy-one') : null;
            if (!btn) return;
            e.preventDefault();
            copyToClipboard(btn.getAttribute('data-url') || '');
        });
    }

    App.navigate = function (url) {
        loadUrl(url, true);
    };

    // DataTables measures column widths once at init, so when the sidebar
    // collapses/expands (or the window resizes) the grid keeps its old widths
    // and the table no longer matches the container. Re-measure every live
    // grid after any layout change so tables always auto-adjust.
    function adjustAllDataTables() {
        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.dataTable) return;
        try {
            window.jQuery.each(window.jQuery.fn.dataTable.tables(true), function () {
                try {
                    var api = window.jQuery(this).DataTable();
                    api.columns.adjust();
                    if (api.responsive) {
                        try { api.responsive.recalc(); } catch (eRecalc) {}
                    }
                } catch (eGrid) { /* ignore individual grids */ }
            });
        } catch (e) { /* ignore */ }
    }

    function bindSidebarAwareTableAdjust() {
        // Sidebar toggles (desktop mini-menu + mobile). The container margin
        // animates over ~0.3s, so re-measure after the transition settles.
        var toggleIds = ['menu-mini-button', 'menu-expend-button', 'mobile-collapse', 'vertical-nav-toggle'];
        for (var i = 0; i < toggleIds.length; i++) {
            (function (id) {
                var btn = document.getElementById(id);
                if (btn) {
                    btn.addEventListener('click', function () {
                        setTimeout(adjustAllDataTables, 400);
                    });
                }
            })(toggleIds[i]);
        }
        // Window resizes (debounced) also change the available table width.
        var resizeTimer = null;
        window.addEventListener('resize', function () {
            if (resizeTimer) clearTimeout(resizeTimer);
            resizeTimer = setTimeout(adjustAllDataTables, 200);
        });
    }

    App.init = function () {
        initNavigation();
        initCurrentFromLocation();
        initSuperadminProfile();
        initAdminPlanUsage();
        initAdminPlanExtendRequest();
        initAdminHomeLink();
        bindSidebarAwareTableAdjust();
    };

    pages.index = (function () {
        var clockTimer = null;
        var autoRefreshTimer = null;
        var clickHandler = null;
        var homeTitleFormHandler = null;
        var CACHE_NS = 'ctr:idx:';
        var HOME_TITLE_SYNC_KEY = 'ctr-home-title-sync';
        var HOME_TITLE_CHANNEL = 'contracs-home-title';
        var cacheSupported = (function () {
            try {
                var k = CACHE_NS + '__t';
                window.localStorage.setItem(k, '1');
                window.localStorage.removeItem(k);
                return true;
            } catch (e) { return false; }
        })();

        function el(id) { return document.getElementById(id); }

        function fetchJson(url, opts) {
            return fetch(url, Object.assign({
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            }, opts || {})).then(function (r) {
                return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
            });
        }

        function cacheGet(key) {
            if (!cacheSupported) return null;
            try {
                var raw = window.localStorage.getItem(CACHE_NS + key);
                if (!raw) return null;
                var entry = JSON.parse(raw);
                if (!entry || typeof entry !== 'object') return null;
                if (!entry.expiresAt || entry.expiresAt < Date.now()) return null;
                return entry.data;
            } catch (e) { return null; }
        }

        function cacheSet(key, data, ttlSeconds) {
            if (!cacheSupported) return;
            try {
                var entry = { data: data, expiresAt: Date.now() + (ttlSeconds * 1000) };
                window.localStorage.setItem(CACHE_NS + key, JSON.stringify(entry));
            } catch (e) { /* quota or serialization error — ignore */ }
        }

        function cachedFetchJson(cacheKey, ttlSeconds) {
            var cached = cacheGet(cacheKey);
            if (cached) {
                return Promise.resolve({ ok: true, status: 200, data: cached, fromCache: true });
            }
            return fetchJson.apply(null, Array.prototype.slice.call(arguments, 2)).then(function (res) {
                if (res && res.data && res.data.ok === true) {
                    cacheSet(cacheKey, res.data, ttlSeconds || 15);
                }
                return res;
            });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatTime12(value) {
            var t = String(value == null ? '' : value).trim();
            if (!t) return '-';
            var m = t.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
            if (!m) return t;
            var hh = Number(m[1] || 0);
            var mm = String(m[2] || '00');
            var ap = hh >= 12 ? 'PM' : 'AM';
            var h12 = hh % 12;
            if (h12 === 0) h12 = 12;
            return String(h12) + ':' + mm + ' ' + ap;
        }

        function setHomeTitleAlert(type, message) {
            var box = el('adminHomeTitleAlert');
            if (!box) return;
            var kinds = ['success', 'danger', 'warning', 'info'];
            for (var i = 0; i < kinds.length; i++) {
                box.classList.remove('alert-' + kinds[i]);
            }
            var msg = String(message == null ? '' : message).trim();
            if (!msg) {
                box.textContent = '';
                box.classList.add('d-none');
                return;
            }
            var kind = String(type || 'info').trim();
            if (kinds.indexOf(kind) === -1) kind = 'info';
            box.textContent = msg;
            box.classList.add('alert-' + kind);
            box.classList.remove('d-none');
        }

        function broadcastHomeTitle(title, defaultTitle) {
            var payload = {
                title: String(title == null ? '' : title),
                default_title: String(defaultTitle == null ? '' : defaultTitle),
                ts: Date.now()
            };
            try {
                window.localStorage.setItem(HOME_TITLE_SYNC_KEY, JSON.stringify(payload));
            } catch (e) {}
            try {
                if ('BroadcastChannel' in window) {
                    var channel = new BroadcastChannel(HOME_TITLE_CHANNEL);
                    channel.postMessage(payload);
                    channel.close();
                }
            } catch (e2) {}
        }

        function bindHomeTitleForm() {
            var form = el('adminHomeTitleForm');
            var input = el('adminHomeTitleInput');
            var saveBtn = el('adminHomeTitleSaveBtn');
            if (!form || !input || !saveBtn || homeTitleFormHandler) return;

            homeTitleFormHandler = function (e) {
                e.preventDefault();
                var body = new URLSearchParams();
                body.set('action', 'save_admin_home_title');
                body.set('admin_home_title', String(input.value || '').trim());
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<i class="feather-loader me-1"></i>Saving...';
                setHomeTitleAlert('', '');

                fetchJson('index.php?ajax=1&action=save_admin_home_title', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: body.toString()
                }).then(function (res) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="feather-save me-1"></i>Save Home Title';
                    if (!res || !res.data || res.data.ok !== true) {
                        var err = (res && res.data && res.data.message) ? res.data.message : 'Failed to update the home title.';
                        setHomeTitleAlert('danger', err);
                        if (window.Swal) window.Swal.fire({ icon: 'error', title: 'Error', text: err });
                        return;
                    }

                    input.value = String(res.data.title || '');
                    setHomeTitleAlert('success', String(res.data.message || 'Home title updated successfully.'));
                    broadcastHomeTitle(res.data.title || '', res.data.default_title || 'SCHOOLS DIVISION OFFICE OF KABANKALAN CITY');
                    if (window.Swal) window.Swal.fire({ icon: 'success', title: 'Saved', timer: 1200, showConfirmButton: false });
                }).catch(function () {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="feather-save me-1"></i>Save Home Title';
                    setHomeTitleAlert('danger', 'Network error. Please try again.');
                    if (window.Swal) window.Swal.fire({ icon: 'error', title: 'Error', text: 'Network error. Please try again.' });
                });
            };

            form.addEventListener('submit', homeTitleFormHandler);
        }

        function renderMostActiveList(target, rows) {
            var list = typeof target === 'string' ? el(target) : target;
            if (!list) return;
            var arr = Array.isArray(rows) ? rows.slice(0, 3) : [];
            if (!arr.length) {
                list.innerHTML = '<li class="list-group-item px-0 text-muted small">No attendance records this month.</li>';
                return;
            }
            list.innerHTML = arr.map(function (r, idx) {
                var rank = idx + 1;
                var medal = rank === 1 ? '🥇' : (rank === 2 ? '🥈' : (rank === 3 ? '🥉' : ('#' + rank)));
                var cnt = parseInt(r.cnt || 0, 10);
                return '<li class="list-group-item px-0 d-flex align-items-center">'
                    + '<span class="me-2 ctr-rank-medal" style="width:28px;">' + medal + '</span>'
                    + '<span class="flex-grow-1 td-clip-200" title="' + escapeHtml(r.full_name || '-') + '">' + escapeHtml(r.full_name || '-') + '</span>'
                    + '<span class="badge bg-soft-primary text-primary ms-2">' + cnt + '</span>'
                    + '</li>';
            }).join('');
        }

        function renderMostAbsencesList(target, rows, workingDays) {
            var list = typeof target === 'string' ? el(target) : target;
            if (!list) return;
            var arr = Array.isArray(rows) ? rows.slice(0, 3) : [];
            var wd = parseInt(workingDays || 0, 10) || 0;
            if (!arr.length) {
                list.innerHTML = '<li class="list-group-item px-0 text-muted small">No absences this month.</li>';
                return;
            }
            list.innerHTML = arr.map(function (r, idx) {
                var rank = idx + 1;
                var medal = rank === 1 ? '🥇' : (rank === 2 ? '🥈' : (rank === 3 ? '🥉' : ('#' + rank)));
                var absDays = parseInt(r.absence_days || 0, 10);
                var attDays = parseInt(r.attended_days || 0, 10);
                return '<li class="list-group-item px-0 d-flex align-items-center">'
                    + '<span class="me-2 ctr-rank-medal" style="width:28px;">' + medal + '</span>'
                    + '<span class="flex-grow-1 td-clip-200" title="' + escapeHtml(r.name || r.username || '-') + '">' + escapeHtml(r.name || r.username || '-') + '</span>'
                    + '<span class="badge bg-soft-danger text-danger ms-2" title="Absent days / Working days ' + wd + '">' + absDays + '/' + wd + '</span>'
                    + '</li>';
            }).join('');
        }

        function updateStats(data) {
            var stats = data.stats || {};
            if (el('statTotalUsers')) el('statTotalUsers').textContent = stats.total_users || 0;
            if (el('statAttendanceToday')) el('statAttendanceToday').textContent = stats.attendance_today || 0;
            if (el('statTimeInAm')) el('statTimeInAm').textContent = stats.time_in_am || 0;
            if (el('statTimeInPm')) el('statTimeInPm').textContent = stats.time_in_pm || 0;
            if (el('statAbsencesToday')) el('statAbsencesToday').textContent = stats.absences_today || 0;
            if (el('statAttendanceMonth')) el('statAttendanceMonth').textContent = stats.attendance_month || 0;

            // Most Active inline list
            renderMostActiveList('statMostActiveList', stats.most_active_list || []);

            // Most Absences inline list
            var sub = el('statMostAbsencesSub');
            if (sub) {
                var wd = parseInt(stats.working_days || 0, 10) || 0;
                sub.textContent = 'Top 3 · ' + wd + ' working day(s) so far';
            }
            renderMostAbsencesList('statMostAbsencesList', stats.most_absences_list || [], stats.working_days || 0);

            var logs = data.recent_logs || [];
            var table = el('recentLogsTable');
            if (!table) return;
            var tbody = table.querySelector('tbody');
            if (!tbody) return;
            if (logs.length > 0) {
                tbody.innerHTML = logs.map(function (r) {
                    var session = r.session === 'morning' ? 'AM' : 'PM';
                    var type = r.scan_type === 'in' ? 'Time In' : 'Time Out';
                    var time = r.time_in || r.time_out || '-';
                    var statusBadge = r.status ? '<span class="badge bg-soft-primary text-primary">' + escapeHtml(r.status) + '</span>' : '';
                    return '<tr>'
                        + '<td>' + escapeHtml(r.id_number) + '</td>'
                        + '<td>' + escapeHtml(r.full_name) + '</td>'
                        + '<td>' + formatTime12(time) + '</td>'
                        + '<td>' + escapeHtml(session) + '</td>'
                        + '<td>' + escapeHtml(type) + '</td>'
                        + '<td>' + statusBadge + '</td>'
                        + '</tr>';
                }).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No attendance records today.</td></tr>';
            }
        }

        function refreshDashboard() {
            return cachedFetchJson('get_stats', 15, 'index.php?ajax=1&action=get_stats', { method: 'GET' }).then(function (res) {
                if (res.data && res.data.ok === true) updateStats(res.data);
            });
        }

        function renderAbsentTable(rows) {
            var table = el('absentTodayTable');
            var sub = el('absentTodaySub');
            if (sub) sub.textContent = (rows && rows.length ? (rows.length + ' person(s)') : '0 person(s)') + ' absent today';
            if (!table) return;
            var tbody = table.querySelector('tbody');
            if (!tbody) return;
            var arr = Array.isArray(rows) ? rows : [];
            if (!arr.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No absentees today.</td></tr>';
                return;
            }
            tbody.innerHTML = arr.map(function (u, idx) {
                return '<tr>'
                    + '<td>' + escapeHtml(idx + 1) + '</td>'
                    + '<td>' + escapeHtml(u.id_number || '-') + '</td>'
                    + '<td class="td-clip-200" title="' + escapeHtml(String(u.name || u.username || '')) + '">' + escapeHtml(u.name || u.username || '-') + '</td>'
                    + '<td>' + escapeHtml(u.position || '-') + '</td>'
                    + '<td>' + escapeHtml(u.department || '-') + '</td>'
                    + '</tr>';
            }).join('');
        }

        function refreshAbsentToday() {
            renderAbsentTable([]);
            var sub = el('absentTodaySub');
            if (sub) sub.textContent = 'Loading…';
            return cachedFetchJson('list_absent_today', 20, 'index.php?ajax=1&action=list_absent_today', { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    if (sub) sub.textContent = 'Failed to load.';
                    return;
                }
                var date = res.data.date ? String(res.data.date) : '';
                var rows = Array.isArray(res.data.absent) ? res.data.absent : [];
                if (sub) sub.textContent = (rows.length + ' person(s)') + (date ? (' absent on ' + date) : ' absent today');
                renderAbsentTable(rows);
            }).catch(function () {
                if (sub) sub.textContent = 'Failed to load.';
            });
        }

        function openAbsentTodayModal() {
            var modal = el('absentTodayModal');
            if (!modal) return;
            renderAbsentTable([]);
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
            refreshAbsentToday().catch(function () {});
        }

        function renderPresentTable(rows) {
            var table = el('presentTodayTable');
            var sub = el('presentTodaySub');
            if (sub) sub.textContent = (rows && rows.length ? (rows.length + ' person(s)') : '0 person(s)') + ' present today';
            if (!table) return;
            var tbody = table.querySelector('tbody');
            if (!tbody) return;
            var arr = Array.isArray(rows) ? rows : [];
            if (!arr.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No one has logged in yet today.</td></tr>';
                return;
            }
            tbody.innerHTML = arr.map(function (u, idx) {
                return '<tr>'
                    + '<td>' + escapeHtml(idx + 1) + '</td>'
                    + '<td>' + escapeHtml(u.id_number || '-') + '</td>'
                    + '<td class="td-clip-200" title="' + escapeHtml(String(u.name || u.username || '')) + '">' + escapeHtml(u.name || u.username || '-') + '</td>'
                    + '<td>' + escapeHtml(u.position || '-') + '</td>'
                    + '<td>' + escapeHtml(u.department || '-') + '</td>'
                    + '<td>' + formatTime12(u.first_in) + '</td>'
                    + '<td>' + (u.last_out ? formatTime12(u.last_out) : '<span class="text-muted">-</span>') + '</td>'
                    + '<td>' + escapeHtml(u.scan_count || 0) + '</td>'
                    + '</tr>';
            }).join('');
        }

        function refreshPresentToday() {
            renderPresentTable([]);
            var sub = el('presentTodaySub');
            if (sub) sub.textContent = 'Loading…';
            return cachedFetchJson('list_present_today', 20, 'index.php?ajax=1&action=list_present_today', { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    if (sub) sub.textContent = 'Failed to load.';
                    return;
                }
                var date = res.data.date ? String(res.data.date) : '';
                var rows = Array.isArray(res.data.present) ? res.data.present : [];
                if (sub) sub.textContent = (rows.length + ' person(s)') + (date ? (' present on ' + date) : ' present today');
                renderPresentTable(rows);
            }).catch(function () {
                if (sub) sub.textContent = 'Failed to load.';
            });
        }

        function openPresentTodayModal() {
            var modal = el('presentTodayModal');
            if (!modal) return;
            renderPresentTable([]);
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
            refreshPresentToday().catch(function () {});
        }

        function renderAllRecordsTable(rows) {
            var table = el('allRecordsTable');
            var sub = el('allRecordsSub');
            if (sub) sub.textContent = (rows && rows.length ? (rows.length + ' record(s)') : '0 record(s)') + ' today';
            if (!table) return;
            var tbody = table.querySelector('tbody');
            if (!tbody) return;
            var arr = Array.isArray(rows) ? rows : [];
            if (!arr.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No attendance records today.</td></tr>';
                return;
            }
            tbody.innerHTML = arr.map(function (r, idx) {
                var session = r.session === 'morning' ? 'AM' : 'PM';
                var type = r.scan_type === 'in' ? 'Time In' : 'Time Out';
                var time = r.time_in || r.time_out || '-';
                var statusBadge = r.status ? '<span class="badge bg-soft-primary text-primary">' + escapeHtml(r.status) + '</span>' : '';
                return '<tr>'
                    + '<td>' + escapeHtml(idx + 1) + '</td>'
                    + '<td>' + escapeHtml(r.id_number || '-') + '</td>'
                    + '<td class="td-clip-200" title="' + escapeHtml(String(r.full_name || '')) + '">' + escapeHtml(r.full_name || '-') + '</td>'
                    + '<td>' + formatTime12(time) + '</td>'
                    + '<td>' + escapeHtml(session) + '</td>'
                    + '<td>' + escapeHtml(type) + '</td>'
                    + '<td>' + statusBadge + '</td>'
                    + '</tr>';
            }).join('');
        }

        function refreshAllRecords() {
            renderAllRecordsTable([]);
            var sub = el('allRecordsSub');
            if (sub) sub.textContent = 'Loading…';
            return cachedFetchJson('list_all_records', 20, 'index.php?ajax=1&action=list_all_records', { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    if (sub) sub.textContent = 'Failed to load.';
                    return;
                }
                var date = res.data.date ? String(res.data.date) : '';
                var rows = Array.isArray(res.data.records) ? res.data.records : [];
                if (sub) sub.textContent = (rows.length + ' record(s)') + (date ? (' on ' + date) : ' today');
                renderAllRecordsTable(rows);
            }).catch(function () {
                if (sub) sub.textContent = 'Failed to load.';
            });
        }

        function openAllRecordsModal() {
            var modal = el('allRecordsModal');
            if (!modal) return;
            renderAllRecordsTable([]);
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
            refreshAllRecords().catch(function () {});
        }

        function renderMostActiveTable(rows) {
            var table = el('mostActiveTable');
            var sub = el('mostActiveSub');
            if (sub) sub.textContent = 'Top 5 · ' + (Array.isArray(rows) ? rows.length : 0) + ' user(s)';
            if (!table) return;
            var tbody = table.querySelector('tbody');
            if (!tbody) return;
            var arr = Array.isArray(rows) ? rows : [];
            if (!arr.length) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">No attendance records this month.</td></tr>';
                return;
            }
            tbody.innerHTML = arr.map(function (r, idx) {
                var rank = idx + 1;
                var medal = rank === 1 ? '🥇' : (rank === 2 ? '🥈' : (rank === 3 ? '🥉' : ('#' + rank)));
                var cnt = parseInt(r.cnt || 0, 10);
                return '<tr>'
                    + '<td>' + escapeHtml(medal) + '</td>'
                    + '<td class="td-clip-200" title="' + escapeHtml(r.full_name || '-') + '">' + escapeHtml(r.full_name || '-') + '</td>'
                    + '<td><span class="badge bg-soft-primary text-primary">' + cnt + ' record(s)</span></td>'
                    + '</tr>';
            }).join('');
        }

        function refreshMostActive() {
            renderMostActiveTable([]);
            return cachedFetchJson('list_most_active', 30, 'index.php?ajax=1&action=list_most_active', { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) return;
                renderMostActiveTable(res.data.rows || []);
            }).catch(function () {});
        }

        function openMostActiveModal() {
            var modal = el('mostActiveModal');
            if (!modal) return;
            renderMostActiveTable([]);
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
            refreshMostActive().catch(function () {});
        }

        function renderMostAbsencesTable(rows, workingDays) {
            var table = el('mostAbsencesTable');
            var sub = el('mostAbsencesSub');
            var wd = parseInt(workingDays || 0, 10) || 0;
            if (sub) sub.textContent = 'Top 5 · ' + (Array.isArray(rows) ? rows.length : 0) + ' user(s) · ' + wd + ' working day(s) so far';
            if (!table) return;
            var tbody = table.querySelector('tbody');
            if (!tbody) return;
            var arr = Array.isArray(rows) ? rows : [];
            if (!arr.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No absences this month.</td></tr>';
                return;
            }
            tbody.innerHTML = arr.map(function (u, idx) {
                var rank = idx + 1;
                var medal = rank === 1 ? '🥇' : (rank === 2 ? '🥈' : (rank === 3 ? '🥉' : ('#' + rank)));
                var absDays = parseInt(u.absence_days || 0, 10);
                var attDays = parseInt(u.attended_days || 0, 10);
                return '<tr>'
                    + '<td>' + escapeHtml(medal) + '</td>'
                    + '<td>' + escapeHtml(u.id_number || '-') + '</td>'
                    + '<td class="td-clip-200" title="' + escapeHtml(String(u.name || u.username || '')) + '">' + escapeHtml(u.name || u.username || '-') + '</td>'
                    + '<td>' + escapeHtml(u.position || '-') + '</td>'
                    + '<td>' + escapeHtml(u.department || '-') + '</td>'
                    + '<td><span class="badge bg-soft-success text-success">' + attDays + '</span></td>'
                    + '<td><span class="badge bg-soft-danger text-danger">' + absDays + ' / ' + wd + '</span></td>'
                    + '</tr>';
            }).join('');
        }

        function refreshMostAbsences() {
            renderMostAbsencesTable([], 0);
            return cachedFetchJson('list_most_absences', 30, 'index.php?ajax=1&action=list_most_absences', { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) return;
                renderMostAbsencesTable(res.data.rows || [], res.data.working_days || 0);
            }).catch(function () {});
        }

        function openMostAbsencesModal() {
            var modal = el('mostAbsencesModal');
            if (!modal) return;
            renderMostAbsencesTable([], 0);
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
            refreshMostAbsences().catch(function () {});
        }

        function startLiveClock() {
            if (clockTimer) clearInterval(clockTimer);
            function tick() {
                var now = new Date();
                var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                var y = now.getFullYear();
                var mo = months[now.getMonth()];
                var d = now.getDate();
                var h = now.getHours();
                var ampm = h >= 12 ? 'PM' : 'AM';
                h = h % 12 || 12;
                var mi = String(now.getMinutes()).padStart(2, '0');
                var s = String(now.getSeconds()).padStart(2, '0');
                var ts = mo + ' ' + d + ', ' + y + ' ' + h + ':' + mi + ':' + s + ' ' + ampm;
                if (el('dashboardUpdatedAt')) el('dashboardUpdatedAt').textContent = 'Updated: ' + ts;
            }
            tick();
            clockTimer = setInterval(tick, 1000);
        }

        function init() {
            if (!el('statTotalUsers')) return;
            destroy();
            bindHomeTitleForm();
            refreshDashboard().catch(function () {});
            startLiveClock();
            if (!clickHandler) {
                clickHandler = function (e) {
                    var t = e.target;
                    if (!t || !t.closest) return;
                    var card;
                    card = t.closest('#absentTodayCard');
                    if (card) { e.preventDefault(); openAbsentTodayModal(); return; }
                    card = t.closest('#presentTodayCard');
                    if (card) { e.preventDefault(); openPresentTodayModal(); return; }
                    card = t.closest('#recentLogsCard');
                    if (card) { e.preventDefault(); openAllRecordsModal(); return; }
                    card = t.closest('#mostActiveCard');
                    if (card) { e.preventDefault(); openMostActiveModal(); return; }
                    card = t.closest('#mostAbsencesCard');
                    if (card) { e.preventDefault(); openMostAbsencesModal(); return; }
                };
                document.addEventListener('click', clickHandler);
            }
            autoRefreshTimer = setInterval(function () {
                refreshDashboard().catch(function () {});
            }, 30000);
        }

        function destroy() {
            if (clockTimer) clearInterval(clockTimer);
            clockTimer = null;
            if (autoRefreshTimer) clearInterval(autoRefreshTimer);
            autoRefreshTimer = null;
            if (clickHandler) {
                document.removeEventListener('click', clickHandler);
                clickHandler = null;
            }
            var form = el('adminHomeTitleForm');
            if (form && homeTitleFormHandler) {
                form.removeEventListener('submit', homeTitleFormHandler);
            }
            homeTitleFormHandler = null;
        }

        return { init: init, destroy: destroy };
    })();

    pages.users = (function () {
        var apiBase = pageUrl('users') + '?ajax=1';
        var users = [];
        var adminUsers = [];
        var adminUsersAdminId = null;
        var dt = null;
        var refreshTimer = null;
        var enrolledFacesTimer = null;
        var clockTimer = null;
        var searchStoreKey = 'contracs_users_search';
        var pendingValidateTimer = null;
        var pendingDeleteId = null;
        var pendingPlanUserId = null;
        var lastQrData = null;
        var lastQrUserId = null;
        var selectedUserIds = {};
        var pendingIdTemplateIds = [];
        var sessionRole = '';
        var myIdentity = null;

        function el(id) { return document.getElementById(id); }

        function bindOnce(node, eventName, key, handler) {
            if (!node) return;
            var k = 'bound_' + String(key || eventName);
            if (node.dataset && node.dataset[k] === '1') return;
            if (node.dataset) node.dataset[k] = '1';
            node.addEventListener(eventName, handler);
        }

        function fetchJson(url, opts) {
            return fetch(url, Object.assign({
                cache: 'no-store',
                headers: Object.assign({ 'Accept': 'application/json' }, (opts && opts.headers) || {})
            }, opts || {})).then(function (r) {
                return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
            });
        }

        function postForm(action, payload) {
            var body = new URLSearchParams();
            body.set('action', action);
            Object.keys(payload || {}).forEach(function (k) {
                if (payload[k] === undefined || payload[k] === null) return;
                body.set(k, String(payload[k]));
            });
            return fetchJson(apiBase + '&action=' + encodeURIComponent(action), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
                body: body.toString()
            });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function userById(id) {
            for (var i = 0; i < users.length; i++) {
                if (String(users[i].id) === String(id)) return users[i];
            }
            return null;
        }

        function selectedIds() {
            return Object.keys(selectedUserIds || {}).filter(function (k) { return selectedUserIds[k] === true; });
        }

        function selectedIdsOrdered() {
            var out = [];
            for (var i = 0; i < users.length; i++) {
                var id = users[i] && users[i].id != null ? String(users[i].id) : '';
                if (id && selectedUserIds[id] === true) out.push(id);
            }
            var extra = selectedIds();
            for (var j = 0; j < extra.length; j++) {
                if (out.indexOf(extra[j]) === -1) out.push(extra[j]);
            }
            return out;
        }

        function updateSelectedCountUi() {
            var cnt = selectedIds().length;
            if (el('idTemplateSelectedCount')) el('idTemplateSelectedCount').textContent = String(cnt);
            if (el('bulkIdTemplateBtn')) el('bulkIdTemplateBtn').disabled = cnt === 0;
        }

        function syncSelectAllUi() {
            var all = el('usersSelectAll');
            var table = el('usersTable');
            if (!all || !table) return;
            var checks = table.querySelectorAll('input.users-check[data-id]');
            if (!checks || !checks.length) {
                all.checked = false;
                all.indeterminate = false;
                return;
            }
            var any = false;
            var every = true;
            for (var i = 0; i < checks.length; i++) {
                any = any || !!checks[i].checked;
                every = every && !!checks[i].checked;
            }
            all.checked = every;
            all.indeterminate = any && !every;
        }

        function openIdTemplateModal(ids) {
            if (!el('idTemplateModal')) return;
            pendingIdTemplateIds = Array.isArray(ids) ? ids.slice(0) : [];
            var label = '';
            if (!pendingIdTemplateIds.length) {
                label = 'No users selected.';
            } else {
                var names = [];
                for (var i = 0; i < pendingIdTemplateIds.length; i++) {
                    var u = userById(pendingIdTemplateIds[i]);
                    if (u && u.name) names.push(String(u.name));
                    if (names.length >= 3) break;
                }
                label = 'Selected: ' + String(pendingIdTemplateIds.length) + ' user(s)';
                if (names.length) label += ' • ' + names.join(', ') + (pendingIdTemplateIds.length > names.length ? ', …' : '');
            }
            if (el('idTemplateSelectionLabel')) el('idTemplateSelectionLabel').textContent = label;
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('idTemplateModal')).show();
        }

        var pendingUploadUserTemplateFile = null;

        function resetUploadUserTemplateUi() {
            pendingUploadUserTemplateFile = null;
            var fi = el('uploadUserTemplateFile');
            if (fi) fi.value = '';
            var warn = el('uploadUserTemplateWarn');
            if (warn) { warn.classList.add('d-none'); warn.textContent = ''; }
            var ok = el('uploadUserTemplateSuccess');
            if (ok) { ok.classList.add('d-none'); ok.textContent = ''; }
            var pw = el('uploadUserTemplatePreviewWrap');
            if (pw) pw.classList.add('d-none');
            var rw = el('uploadUserTemplateResultsWrap');
            if (rw) rw.classList.add('d-none');
            var pt = el('uploadUserTemplatePreviewTable');
            if (pt) { pt.querySelector('thead').innerHTML = ''; pt.querySelector('tbody').innerHTML = ''; }
            var pc = el('uploadUserTemplatePreviewCount');
            if (pc) pc.textContent = '0 row(s)';
            var rt = el('uploadUserTemplateResultsTable');
            if (rt) { var tb = rt.querySelector('tbody'); if (tb) tb.innerHTML = ''; }
            var cb = el('uploadUserTemplateCreatedBadge');
            if (cb) cb.textContent = 'Created: 0';
            var fb = el('uploadUserTemplateFailedBadge');
            if (fb) fb.textContent = 'Failed: 0';
            var ew = el('uploadUserTemplateErrorsWrap');
            if (ew) ew.classList.add('d-none');
            var elist = el('uploadUserTemplateErrorsList');
            if (elist) elist.innerHTML = '';
            var btn = el('confirmUploadUserTemplateBtn');
            if (btn) btn.disabled = true;
        }

        function openUploadUserTemplateModal() {
            if (!el('uploadUserTemplateModal')) return;
            resetUploadUserTemplateUi();
            var lockedNote = el('uploadUserTemplateLockedDeptNote');
            var adminDeptUnlocked = (sessionRole === 'admin' && myIdentity) && (String(myIdentity.admin_department_unlocked) === '1' || myIdentity.admin_department_unlocked === 1 || myIdentity.admin_department_unlocked === true);
            if (lockedNote && sessionRole === 'admin' && !adminDeptUnlocked && myIdentity && myIdentity.department) {
                lockedNote.innerHTML = '<i class="feather-lock me-1"></i>Department will be automatically locked to <strong>' + escapeHtml(String(myIdentity.department)) + '</strong> for every created user.';
            } else if (lockedNote && sessionRole === 'admin' && adminDeptUnlocked) {
                lockedNote.innerHTML = '<i class="feather-unlock me-1"></i>Department is unlocked. The Department column in your upload file will be used per row.';
            } else if (lockedNote && sessionRole === 'superadmin') {
                lockedNote.innerHTML = '<i class="feather-info me-1"></i>Superadmin uploads can specify a Department column; if left blank, it will be stored as empty.';
            } else if (lockedNote) {
                lockedNote.innerHTML = '<i class="feather-info me-1"></i>Upload the filled-in template to create users in bulk.';
            }
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('uploadUserTemplateModal')).show();
        }

        function readFileAsText(file) {
            return new Promise(function (resolve, reject) {
                var r = new FileReader();
                r.onload = function () { resolve(r.result); };
                r.onerror = function () { reject(r.error || new Error('Failed to read file.')); };
                r.readAsText(file);
            });
        }

        function onUploadUserTemplateFileChange(ev) {
            var warn = el('uploadUserTemplateWarn');
            var ok = el('uploadUserTemplateSuccess');
            if (warn) { warn.classList.add('d-none'); warn.textContent = ''; }
            if (ok) { ok.classList.add('d-none'); ok.textContent = ''; }
            var btn = el('confirmUploadUserTemplateBtn');
            if (btn) btn.disabled = true;
            pendingUploadUserTemplateFile = null;
            var rw = el('uploadUserTemplateResultsWrap');
            if (rw) rw.classList.add('d-none');
            var pw = el('uploadUserTemplatePreviewWrap');
            if (pw) pw.classList.add('d-none');
            var pt = el('uploadUserTemplatePreviewTable');
            if (pt) { pt.querySelector('thead').innerHTML = ''; pt.querySelector('tbody').innerHTML = ''; }
            var pc = el('uploadUserTemplatePreviewCount');
            if (pc) pc.textContent = '0 row(s)';

            var input = ev && ev.target ? ev.target : null;
            if (!input || !input.files || !input.files.length) return;
            var file = input.files[0];
            var name = (file.name || '').toLowerCase();
            var ext = '';
            var dot = name.lastIndexOf('.');
            if (dot >= 0 && dot < name.length - 1) ext = name.substring(dot + 1);
            if (['xlsx', 'xls', 'csv'].indexOf(ext) < 0) {
                if (warn) { warn.textContent = 'Unsupported file type. Use .xlsx, .xls, or .csv.'; warn.classList.remove('d-none'); }
                input.value = '';
                return;
            }
            pendingUploadUserTemplateFile = file;
            if (ext === 'csv') {
                readFileAsText(file).then(function (txt) {
                    var rows = parseCsvText(String(txt || ''));
                    renderUploadUserTemplatePreview(rows);
                    if (btn) btn.disabled = !rows || rows.length === 0;
                }).catch(function () {
                    if (btn) btn.disabled = true;
                });
            } else {
                var pcEl = el('uploadUserTemplatePreviewCount');
                if (pcEl) pcEl.textContent = 'Ready: ' + (file.name || 'file');
                if (pw) pw.classList.remove('d-none');
                if (btn) btn.disabled = false;
            }
        }

        function parseCsvText(text) {
            if (text.charCodeAt(0) === 0xFEFF) text = text.substring(1);
            var lines = text.split(/\r\n|\r|\n/);
            var out = [];
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i];
                if (line === '' && out.length === 0) continue;
                var cells = parseCsvLine(line);
                out.push(cells);
            }
            return out;
        }

        function parseCsvLine(line) {
            var cells = [];
            var cur = '';
            var inQuotes = false;
            for (var i = 0; i < line.length; i++) {
                var c = line.charAt(i);
                if (inQuotes) {
                    if (c === '"') {
                        if (i + 1 < line.length && line.charAt(i + 1) === '"') {
                            cur += '"';
                            i++;
                        } else {
                            inQuotes = false;
                        }
                    } else {
                        cur += c;
                    }
                } else {
                    if (c === ',') {
                        cells.push(cur);
                        cur = '';
                    } else if (c === '"' && cur === '') {
                        inQuotes = true;
                    } else {
                        cur += c;
                    }
                }
            }
            cells.push(cur);
            return cells;
        }

        function renderUploadUserTemplatePreview(rows) {
            var pw = el('uploadUserTemplatePreviewWrap');
            var pt = el('uploadUserTemplatePreviewTable');
            var pc = el('uploadUserTemplatePreviewCount');
            if (!pt) return;
            var thead = pt.querySelector('thead');
            var tbody = pt.querySelector('tbody');
            thead.innerHTML = '';
            tbody.innerHTML = '';
            if (!rows || rows.length === 0) {
                if (pw) pw.classList.add('d-none');
                if (pc) pc.textContent = '0 row(s)';
                return;
            }
            var maxCols = 0;
            for (var i = 0; i < rows.length; i++) {
                if (rows[i] && rows[i].length > maxCols) maxCols = rows[i].length;
            }
            var headerRow = document.createElement('tr');
            for (var c = 0; c < maxCols; c++) {
                var th = document.createElement('th');
                th.textContent = (rows[0] && rows[0][c] != null) ? String(rows[0][c]) : '';
                headerRow.appendChild(th);
            }
            thead.appendChild(headerRow);
            var start = (rows[0] && rows[0].length && /[a-z]/i.test(String(rows[0][0] || ''))) ? 1 : 0;
            for (var r2 = start; r2 < rows.length; r2++) {
                var tr = document.createElement('tr');
                for (var c2 = 0; c2 < maxCols; c2++) {
                    var td = document.createElement('td');
                    td.textContent = (rows[r2] && rows[r2][c2] != null) ? String(rows[r2][c2]) : '';
                    tr.appendChild(td);
                }
                tbody.appendChild(tr);
            }
            if (pc) pc.textContent = String(Math.max(0, rows.length - start)) + ' row(s)';
            if (pw) pw.classList.remove('d-none');
        }

        function confirmUploadUserTemplate() {
            if (!pendingUploadUserTemplateFile) return;
            var btn = el('confirmUploadUserTemplateBtn');
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…'; }
            var fd = new FormData();
            fd.append('action', 'bulk_create_users');
            fd.append('file', pendingUploadUserTemplateFile);
            fetch(pageUrl('users') + '?ajax=1', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd })
                .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; }); })
                .then(function (res) {
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="feather-upload me-1"></i> Upload & Create Users'; }
                    if (!res.data || res.data.ok !== true) {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Upload failed.';
                        var warn = el('uploadUserTemplateWarn');
                        if (warn) { warn.textContent = msg; warn.classList.remove('d-none'); }
                        return;
                    }
                    var created = res.data.created || 0;
                    var failed = res.data.failed || 0;
                    var accounts = res.data.accounts || [];
                    var errors = res.data.errors || [];
                    var ok = el('uploadUserTemplateSuccess');
                    if (ok) {
                        ok.textContent = 'Created ' + String(created) + ' user(s)' + (failed ? ('; ' + String(failed) + ' failed.') : '.');
                        ok.classList.remove('d-none');
                    }
                    var rw = el('uploadUserTemplateResultsWrap');
                    if (rw) rw.classList.remove('d-none');
                    var cb = el('uploadUserTemplateCreatedBadge');
                    if (cb) cb.textContent = 'Created: ' + String(created);
                    var fb = el('uploadUserTemplateFailedBadge');
                    if (fb) fb.textContent = 'Failed: ' + String(failed);
                    var rt = el('uploadUserTemplateResultsTable');
                    if (rt) {
                        var tb = rt.querySelector('tbody');
                        tb.innerHTML = '';
                        for (var i = 0; i < accounts.length; i++) {
                            var acc = accounts[i];
                            var tr = document.createElement('tr');
                            tr.innerHTML = '<td>' + escapeHtml(String(acc.username || '')) + '</td>'
                                + '<td>' + escapeHtml(String(acc.name || '')) + '</td>'
                                + '<td><code>' + escapeHtml(String(acc.default_password || '')) + '</code></td>';
                            tb.appendChild(tr);
                        }
                    }
                    var ew = el('uploadUserTemplateErrorsWrap');
                    var elist = el('uploadUserTemplateErrorsList');
                    if (errors && errors.length) {
                        ew.classList.remove('d-none');
                        elist.innerHTML = '';
                        for (var j = 0; j < errors.length; j++) {
                            var li = document.createElement('li');
                            li.textContent = 'Row ' + String(errors[j].row || '?') + ': ' + String(errors[j].message || '');
                            elist.appendChild(li);
                        }
                    } else if (ew) {
                        ew.classList.add('d-none');
                    }
                    if (typeof refreshUsers === 'function') {
                        try { refreshUsers(); } catch (e) {}
                    }
                })
                .catch(function () {
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="feather-upload me-1"></i> Upload & Create Users'; }
                    var warn = el('uploadUserTemplateWarn');
                    if (warn) { warn.textContent = 'Network error during upload.'; warn.classList.remove('d-none'); }
                });
        }

        function openPlanModal(u) {
            if (!el('planModal')) return;
            pendingPlanUserId = u ? u.id : null;
            if (el('planUserId')) el('planUserId').value = pendingPlanUserId ? String(pendingPlanUserId) : '';
            var label = '';
            if (u) {
                label = String(u.name || '') + (u.username ? (' (' + String(u.username) + ')') : '');
                if (u.id_number) label += ' • ' + String(u.id_number);
            }
            if (el('planUserLabel')) el('planUserLabel').textContent = label;
            var m = u && u.plan_months != null ? parseInt(u.plan_months, 10) : 1;
            if (!m || m < 1) m = 1;
            if (m > 12) m = 12;
            if (el('planMonthsModalInput')) el('planMonthsModalInput').value = String(m);
            // Prefill custom plan dates: start = today, end = current expiry (or today + 1 month)
            var nowD = new Date();
            if (el('planStartDateInput')) el('planStartDateInput').value = fmtDateInput(nowD);
            if (el('planEndDateInput')) {
                var endD = null;
                var expRaw = u && u.plan_expires_at != null ? String(u.plan_expires_at) : '';
                if (expRaw) {
                    var expD = new Date(expRaw.replace(' ', 'T'));
                    if (!isNaN(expD.getTime())) endD = expD;
                }
                if (!endD) {
                    endD = new Date(nowD.getFullYear(), nowD.getMonth() + 1, nowD.getDate());
                }
                el('planEndDateInput').value = fmtDateInput(endD);
            }
            updateCustomPlanRangeHint();
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('planModal')).show();
        }

        function fmtDateInput(d) {
            if (!d || isNaN(d.getTime())) return '';
            var mm = String(d.getMonth() + 1);
            var dd = String(d.getDate());
            return d.getFullYear() + '-' + (mm.length < 2 ? '0' + mm : mm) + '-' + (dd.length < 2 ? '0' + dd : dd);
        }

        function updateCustomPlanRangeHint() {
            var hint = el('customPlanRangeHint');
            if (!hint) return;
            var s = el('planStartDateInput') ? el('planStartDateInput').value : '';
            var e = el('planEndDateInput') ? el('planEndDateInput').value : '';
            if (!s || !e) {
                hint.innerHTML = 'Pick the exact month and day your plan starts and expires.';
                return;
            }
            if (e < s) {
                hint.innerHTML = '<span class="text-danger"><i class="feather-alert-triangle me-1"></i>Expiration date must be on or after the start date.</span>';
                return;
            }
            var sd = new Date(s + 'T00:00:00');
            var ed = new Date(e + 'T00:00:00');
            var months = (ed.getFullYear() - sd.getFullYear()) * 12 + (ed.getMonth() - sd.getMonth());
            if (months < 1) months = 1;
            var label = months === 1 ? '1 month' : months + ' months';
            hint.innerHTML = '<i class="feather-check-circle me-1"></i>Plan runs for about <strong>' + label + '</strong> and expires on ' + escapeHtml(e) + '.';
        }

        function escapeHtmlAttr(s) {
            return escapeHtml(s).replace(/"/g, '&quot;');
        }

        function loadAdminUsers(adminUser) {
            if (!adminUser || adminUser.id == null) return Promise.resolve();
            adminUsersAdminId = String(adminUser.id);
            adminUsers = [];
            if (el('adminUsersTitle')) {
                var cnt = adminUser.created_users_count != null ? String(adminUser.created_users_count) : '';
                var suffix = cnt ? (' • ' + cnt + ' user(s)') : '';
                el('adminUsersTitle').textContent = String(adminUser.name || adminUser.username || '') + suffix;
            }
            renderAdminUsersTable();
            return fetchJson(apiBase + '&action=list_users_for_admin&admin_id=' + encodeURIComponent(String(adminUser.id)), { method: 'GET' })
                .then(function (res) {
                    if (!res.data || res.data.ok !== true) return;
                    adminUsers = Array.isArray(res.data.users) ? res.data.users : [];
                    renderAdminUsersTable();
                });
        }

        function openAdminUsersModal(adminUser) {
            if (!el('adminUsersModal')) return;
            loadAdminUsers(adminUser).catch(function () {});
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('adminUsersModal')).show();
        }

        // Superadmin: view, revoke, and reset an admin's home links (the links
        // the admin manages from the topbar "My home link" modal).
        function ahlSaFormatDateTime(value) {
            var t = String(value == null ? '' : value).trim();
            if (!t) return '';
            var m = t.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})(?::(\d{2}))?$/);
            if (!m) return t;
            var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            var month = months[parseInt(m[2], 10) - 1];
            var day = parseInt(m[3], 10);
            var hh = parseInt(m[4], 10);
            var mm = m[5];
            var ap = hh >= 12 ? 'PM' : 'AM';
            var h12 = hh % 12; if (h12 === 0) h12 = 12;
            return month + ' ' + day + ', ' + m[1] + ' ' + h12 + ':' + mm + ' ' + ap;
        }

        function setAhlSaAlert(msg) {
            var a = el('ahlSaAlert');
            if (!a) return;
            var t = String(msg == null ? '' : msg).trim();
            if (!t) { a.classList.add('d-none'); a.textContent = ''; return; }
            a.textContent = t;
            a.classList.remove('d-none');
        }

        function renderAdminHomeLinks(data) {
            var countEl = el('ahlSaCount');
            if (countEl) countEl.textContent = String(data.count || 0);
            var list = el('ahlSaLinksList');
            if (!list) return;
            var links = data.links || [];
            if (!links.length) {
                list.innerHTML = '<div class="text-muted small text-center py-3">No active home links for this admin.</div>';
                return;
            }
            list.innerHTML = links.map(function (lnk, idx) {
                var bound = lnk.device_bound === true;
                var boundAt = lnk.device_bound_at || '';
                var lastSeen = lnk.last_seen_at || '';
                var url = lnk.url || '';
                var linkId = lnk.id || 0;

                var statusBadge = bound
                    ? '<span class="badge bg-soft-warning text-warning" style="font-size:10px;">LOCKED</span>'
                    : '<span class="badge bg-soft-success text-success" style="font-size:10px;">ACTIVE</span>';
                var deviceInfo = bound
                    ? ('Locked' + (boundAt ? (' since ' + ahlSaFormatDateTime(boundAt)) : ''))
                    : 'Awaiting first scan';
                var lastSeenInfo = lastSeen ? ('Last seen: ' + ahlSaFormatDateTime(lastSeen)) : 'Never used';

                return ''
                    + '<div class="border rounded mb-2" style="padding:12px 14px;">'
                    + '  <div class="d-flex align-items-center gap-2 mb-2">'
                    + '    <span style="font-size:11px;font-weight:700;color:#6c757d;">#' + (idx + 1) + '</span>'
                    + statusBadge
                    + '    <button type="button" class="btn btn-outline-danger btn-sm ms-auto ahl-sa-revoke" data-link-id="' + escapeHtml(String(linkId)) + '" title="Revoke this link"><i class="feather-trash-2" style="font-size:12px;"></i> Revoke</button>'
                    + '  </div>'
                    + '  <div class="input-group input-group-sm mb-1">'
                    + '    <span class="input-group-text" style="font-size:11px;background:transparent;border-right:none;"><i class="feather-link" style="font-size:12px;"></i></span>'
                    + '    <input class="form-control form-control-sm" readonly value="' + escapeHtml(url) + '" style="font-size:11px;border-left:none;background:transparent;">'
                    + '  </div>'
                    + '  <div class="d-flex justify-content-between align-items-center mt-1" style="font-size:11px;color:#6c757d;">'
                    + '    <span>' + escapeHtml(deviceInfo) + '</span>'
                    + '    <span>' + escapeHtml(lastSeenInfo) + '</span>'
                    + '  </div>'
                    + '</div>';
            }).join('');
        }

        function ahlSaRefresh(adminId) {
            return fetchJson(apiBase + '&action=list_admin_home_links&admin_id=' + encodeURIComponent(String(adminId)), { method: 'GET' }).then(function (res) {
                if (res.data && res.data.ok === true) renderAdminHomeLinks(res.data);
            });
        }

        function openAdminHomeLinksModal(u) {
            if (!u || !el('adminHomeLinksModal')) return;
            var adminId = u.id;
            var idInput = el('ahlSaAdminId');
            if (idInput) idInput.value = String(adminId);
            var label = el('ahlSaAdminLabel');
            if (label) label.textContent = 'Links for ' + ((u.name || u.username) ? ((u.name || u.username) + ' (' + u.username + ')') : ('Admin #' + adminId));
            var countEl = el('ahlSaCount');
            if (countEl) countEl.textContent = '0';
            var list = el('ahlSaLinksList');
            if (list) list.innerHTML = '<div class="text-muted small text-center py-3">Loading...</div>';
            setAhlSaAlert('');
            var resetBtn = el('ahlSaResetAllBtn');
            if (resetBtn) resetBtn.disabled = false;
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('adminHomeLinksModal')).show();

            fetchJson(apiBase + '&action=list_admin_home_links&admin_id=' + encodeURIComponent(String(adminId)), { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    var msg = (res.data && res.data.message) ? res.data.message : 'Request failed.';
                    setAhlSaAlert(msg);
                    if (list) list.innerHTML = '<div class="text-danger small text-center py-3">' + escapeHtml(msg) + '</div>';
                    return;
                }
                renderAdminHomeLinks(res.data);
            }).catch(function () {
                setAhlSaAlert('Network error. Please try again.');
                if (list) list.innerHTML = '<div class="text-danger small text-center py-3">Network error.</div>';
            });
        }

        // Revoke a single admin link (superadmin only).
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('.ahl-sa-revoke') : null;
            if (!btn) return;
            e.preventDefault();
            var linkId = btn.getAttribute('data-link-id');
            var adminId = el('ahlSaAdminId') ? el('ahlSaAdminId').value : '';
            if (!linkId || !adminId) return;
            btn.disabled = true;
            postForm('revoke_admin_home_link', { admin_id: adminId, link_id: linkId }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    var msg = (res.data && res.data.message) ? res.data.message : 'Request failed.';
                    setAhlSaAlert(msg);
                    return;
                }
                ahlSaRefresh(adminId).catch(function () {});
                if (window.Swal) Swal.fire({ icon: 'success', title: 'Revoked', timer: 900, showConfirmButton: false });
            }).finally(function () {
                try { btn.disabled = false; } catch (e2) {}
            });
        });

        // Reset ALL of an admin's home links (superadmin only).
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('#ahlSaResetAllBtn') : null;
            if (!btn) return;
            e.preventDefault();
            var adminId = el('ahlSaAdminId') ? el('ahlSaAdminId').value : '';
            if (!adminId) return;
            var adminName = (el('ahlSaAdminLabel') ? el('ahlSaAdminLabel').textContent : '') || 'this admin';
            var doReset = function () {
                btn.disabled = true;
                postForm('reset_admin_home_links', { admin_id: adminId }).then(function (res) {
                    if (!res.data || res.data.ok !== true) {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Request failed.';
                        setAhlSaAlert(msg);
                        return;
                    }
                    ahlSaRefresh(adminId).catch(function () {});
                    if (window.Swal) Swal.fire({ icon: 'success', title: 'Reset', text: 'All home links revoked.', timer: 1200, showConfirmButton: false });
                }).finally(function () {
                    try { btn.disabled = false; } catch (e2) {}
                });
            };
            if (window.Swal) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Reset all home links?',
                    text: 'This will revoke every active home link for ' + adminName + '. The admin will need a new link after this.',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    confirmButtonText: 'Yes, reset all',
                    cancelButtonText: 'Cancel'
                }).then(function (result) {
                    // This app's bundled SweetAlert2 resolves the confirm button
                    // with { value: true } (not the stock { isConfirmed: true }),
                    // so accept either shape. Cancel resolves as { dismiss: 'cancel' }
                    // and must not trigger the reset.
                    if (result && (result.isConfirmed || result.value)) doReset();
                });
            } else {
                if (window.confirm('Reset all home links for ' + adminName + '?')) doReset();
            }
        });

        function renderAdminUsersTable() {
            var t = el('adminUsersTable');
            if (!t) return;
            var tbody = t.querySelector('tbody');
            if (!tbody) return;
            if (!adminUsers || !adminUsers.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No users yet.</td></tr>';
                return;
            }
            tbody.innerHTML = adminUsers.map(function (u) {
                var active = u.is_active && String(u.is_active) !== '0';
                var statusBadge = active
                    ? '<span class="badge bg-soft-success text-success">Active</span>'
                    : '<span class="badge bg-soft-danger text-danger">Inactive</span>';
                var actions = ''
                    + '<div class="d-flex gap-1 flex-wrap">'
                    + '<button class="btn btn-sm btn-outline-primary" data-admin-users-action="edit" data-id="' + escapeHtmlAttr(u.id) + '">EDIT</button>'
                    + '<button class="btn btn-sm btn-outline-secondary" data-admin-users-action="qr" data-id="' + escapeHtmlAttr(u.id) + '">QR</button>'
                    + '<button class="btn btn-sm btn-outline-danger" data-admin-users-action="delete" data-id="' + escapeHtmlAttr(u.id) + '">DELETE</button>'
                    + '</div>';
                return ''
                    + '<tr>'
                    + '<td>' + escapeHtml(u.username) + '</td>'
                    + '<td class="td-clip-200" title="' + escapeHtmlAttr(u.name) + '">' + escapeHtml(u.name) + '</td>'
                    + '<td class="td-clip-200" title="' + escapeHtmlAttr(u.id_number) + '">' + escapeHtml(u.id_number) + '</td>'
                    + '<td>' + escapeHtml(u.role) + '</td>'
                    + '<td>' + escapeHtml(u.position) + '</td>'
                    + '<td>' + escapeHtml(u.department) + '</td>'
                    + '<td>' + statusBadge + '</td>'
                    + '<td>' + actions + '</td>'
                    + '</tr>';
            }).join('');
        }

        function openAdminUserQrModal(u) {
            if (!u || !el('qrModal')) return;
            var show = function () { openQrModal(u); };
            var parent = el('adminUsersModal');
            if (parent && parent.classList && parent.classList.contains('show') && window.bootstrap && window.bootstrap.Modal) {
                var onHidden = function () {
                    try { parent.removeEventListener('hidden.bs.modal', onHidden); } catch (e) {}
                    show();
                };
                try {
                    parent.addEventListener('hidden.bs.modal', onHidden);
                    window.bootstrap.Modal.getOrCreateInstance(parent).hide();
                } catch (e2) {
                    try { parent.removeEventListener('hidden.bs.modal', onHidden); } catch (e3) {}
                    show();
                }
                return;
            }
            show();
        }

        function renderTable() {
            var table = el('usersTable');
            if (!table) return;

            function buildRow(u) {
                var isSelected = u && u.id != null && selectedUserIds[String(u.id)] === true;
                var selectCell = '<input class="form-check-input users-check" type="checkbox" data-id="' + escapeHtml(u.id) + '"' + (isSelected ? ' checked' : '') + '>';
                var active = u.is_active && String(u.is_active) !== '0';
                var statusBadge = active
                    ? '<span class="badge bg-soft-success text-success">Active</span>'
                    : '<span class="badge bg-soft-danger text-danger">Inactive</span>';
                var planLine = '';
                var isAdmin = u && String(u.role || '') === 'admin';
                var canPlan = isAdmin && sessionRole === 'superadmin';
                if (isAdmin) {
                    var expRaw = u.plan_expires_at != null ? String(u.plan_expires_at) : '';
                    expRaw = expRaw.trim();
                    var expired = false;
                    var formattedDate = '';
                    if (expRaw) {
                        try {
                            var d = new Date(expRaw.replace(' ', 'T'));
                            expired = d.getTime && !isNaN(d.getTime()) ? (d.getTime() <= (Date.now ? Date.now() : new Date().getTime())) : false;
                            if (!isNaN(d.getTime())) {
                                var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                                var day = d.getDate();
                                var month = months[d.getMonth()];
                                var year = d.getFullYear();
                                formattedDate = month + ' ' + (day < 10 ? '0' + day : day) + ', ' + year;
                            }
                        } catch (e) {}
                    }
                    var isFreeTrial = u && (String(u.is_free_trial) === '1' || u.is_free_trial === 1 || u.is_free_trial === true);
                    if (!expRaw) {
                        planLine = '<span class="badge bg-soft-secondary text-secondary">No plan</span>';
                    } else if (expired) {
                        planLine = '<span class="badge bg-soft-danger text-danger">Plan expired</span>';
                    } else if (isFreeTrial) {
                        planLine = '<span class="badge bg-soft-success text-success">Free Trial <span class="ms-1">' + escapeHtml(formattedDate || expRaw.slice(0, 10)) + '</span></span>';
                    } else {
                        planLine = '<span class="badge bg-soft-primary text-primary">Plan until ' + escapeHtml(formattedDate || expRaw.slice(0, 10)) + '</span>';
                    }
                }
                var homeLinksCount = u && u.home_links_count != null ? parseInt(u.home_links_count, 10) : 0;
                if (isNaN(homeLinksCount)) homeLinksCount = 0;
                var homeLinksLine = (isAdmin && sessionRole === 'superadmin')
                    ? '<span class="badge bg-soft-info text-info">' + (homeLinksCount > 0 ? homeLinksCount + ' home link' + (homeLinksCount === 1 ? '' : 's') : 'No home links') + '</span>'
                    : '';
                var planBtn = '';
                var statusBtn = '';
                if (active) {
                    if (sessionRole === 'superadmin') {
                        statusBtn = '<button class="btn btn-sm btn-outline-danger" data-action="toggle" data-id="' + escapeHtml(u.id) + '" data-active="0">Deactivate</button>';
                    }
                    if (canPlan) {
                        planBtn = '<button class="btn btn-sm btn-outline-primary" data-action="plan" data-id="' + escapeHtml(u.id) + '">Set Plan</button>';
                    }
                } else {
                    if (canPlan) {
                        statusBtn = '<button class="btn btn-sm btn-outline-success" data-action="plan" data-id="' + escapeHtml(u.id) + '">Activate</button>';
                    } else {
                        statusBtn = '';
                    }
                }
                var statusCell = '<div class="d-flex flex-column gap-1 align-items-start">' + statusBadge + (planLine ? planLine : '') + (homeLinksLine ? homeLinksLine : '') + (planBtn ? planBtn : '') + (statusBtn ? statusBtn : '') + '</div>';
                var qrCell = '<button class="btn btn-sm btn-primary" data-action="qr" data-id="' + escapeHtml(u.id) + '">VIEW</button>';
                var faceEnrolled = u && (String(u.has_face_data) === '1' || u.has_face_data === 1 || u.has_face_data === true);
                var faceLegacy = faceEnrolled && String(u.face_kind || '') === 'legacy';
                var faceLabel = faceEnrolled
                    ? (faceLegacy
                        ? '<span class="badge bg-soft-warning text-warning ms-1" style="font-size:10px;" title="Enrolled with the old 42-point model — re-enroll with the new face model">Re-enroll Needed</span>'
                        : '<span class="badge bg-soft-success text-success ms-1" style="font-size:10px;">Face Enrolled</span>')
                    : '';
                var actionCell = '';
                if (sessionRole === 'superadmin') {
                    var viewUsersItem = (u && String(u.role || '') === 'admin')
                        ? '<button type="button" class="dropdown-item" data-action="viewusers" data-id="' + escapeHtml(u.id) + '"><i class="feather-users me-2"></i>View Users</button>'
                        : '';
                    var homeLinksItem = (u && String(u.role || '') === 'admin')
                        ? '<button type="button" class="dropdown-item" data-action="homelinks" data-id="' + escapeHtml(u.id) + '"><i class="feather-link me-2"></i>Home Links</button>'
                        : '';
                    var deptUnlocked = (u && String(u.role || '') === 'admin') && (String(u.admin_department_unlocked) === '1' || u.admin_department_unlocked === 1 || u.admin_department_unlocked === true);
                    var deptToggleItem = (u && String(u.role || '') === 'admin')
                        ? (
                            deptUnlocked
                                ? '<button type="button" class="dropdown-item" data-action="deptlock" data-id="' + escapeHtml(u.id) + '" data-unlocked="0"><i class="feather-lock me-2"></i>Lock Department</button>'
                                : '<button type="button" class="dropdown-item" data-action="deptlock" data-id="' + escapeHtml(u.id) + '" data-unlocked="1"><i class="feather-unlock me-2"></i>Unlock Department</button>'
                        )
                        : '';
                    var faceEnrollItem = '<button type="button" class="dropdown-item" data-action="face_enroll" data-id="' + escapeHtml(u.id) + '"><i class="feather-camera me-2"></i>' + (faceEnrolled ? 'Re-enroll Face' : 'Face Enrollment') + '</button>';
                    actionCell = ''
                        + '<div class="dropdown">'
                        + '  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Actions">'
                        + '    <i class="feather-more-vertical"></i>'
                        + '  </button>'
                        + '  <div class="dropdown-menu dropdown-menu-end">'
                        + '    <button type="button" class="dropdown-item" data-action="edit" data-id="' + escapeHtml(u.id) + '"><i class="feather-edit me-2"></i>Edit</button>'
                        +      viewUsersItem
                        +      homeLinksItem
                        +      deptToggleItem
                        +      faceEnrollItem
                        + '    <button type="button" class="dropdown-item" data-action="idtemplate" data-id="' + escapeHtml(u.id) + '"><i class="feather-download me-2"></i>ID Template</button>'
                        + '    <div class="dropdown-divider"></div>'
                        + '    <button type="button" class="dropdown-item text-danger" data-action="delete" data-id="' + escapeHtml(u.id) + '"><i class="feather-trash-2 me-2"></i>Delete</button>'
                        + '  </div>'
                        + '</div>';
                } else {
                    var faceEnrollItem2 = '<button type="button" class="dropdown-item" data-action="face_enroll" data-id="' + escapeHtml(u.id) + '"><i class="feather-camera me-2"></i>' + (faceEnrolled ? 'Re-enroll Face' : 'Face Enrollment') + '</button>';
                    actionCell = ''
                        + '<div class="dropdown">'
                        + '  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Actions">'
                        + '    <i class="feather-more-vertical"></i>'
                        + '  </button>'
                        + '  <div class="dropdown-menu dropdown-menu-end">'
                        + '    <button type="button" class="dropdown-item" data-action="edit" data-id="' + escapeHtml(u.id) + '"><i class="feather-edit me-2"></i>Edit</button>'
                        +      faceEnrollItem2
                        + '    <button type="button" class="dropdown-item" data-action="idtemplate" data-id="' + escapeHtml(u.id) + '"><i class="feather-download me-2"></i>ID Template</button>'
                        + '    <div class="dropdown-divider"></div>'
                        + '    <button type="button" class="dropdown-item text-danger" data-action="delete" data-id="' + escapeHtml(u.id) + '"><i class="feather-trash-2 me-2"></i>Delete</button>'
                        + '  </div>'
                        + '</div>';
                }
                return [
                    selectCell,
                    escapeHtml(u.username),
                    escapeHtml(u.name) + (faceLabel || ''),
                    escapeHtml(u.id_number),
                    escapeHtml(u.role),
                    escapeHtml(u.position),
                    escapeHtml(u.department),
                    statusCell,
                    qrCell,
                    actionCell
                ];
            }

            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.dataTable) {
                var rows = users.map(buildRow);

                var $t = window.jQuery('#usersTable');
                var isInit = window.jQuery.fn.DataTable.isDataTable('#usersTable');
                if (isInit && dt) {
                    var prevSearch = dt.search();
                    var prevPage = dt.page();
                    var prevLen = dt.page.len();
                    dt.page.len(prevLen);
                    dt.clear();
                    dt.rows.add(rows);
                    dt.search(prevSearch);
                    dt.draw(false);
                    if (typeof prevPage === 'number') {
                        try { dt.page(prevPage).draw(false); } catch (e) {}
                    }
                    return;
                }

                dt = $t.DataTable({
                    data: rows,
                    pageLength: 10,
                    lengthMenu: [10, 20, 50, 100, 200],
                    order: [[1, 'desc']],
                    deferRender: true,
                    destroy: true,
                    searchDelay: 300,
                    dom: "<'row dt-row'<'col-sm-12'tr>>" +
                         "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
                    columnDefs: [
                        { targets: [0, 7, 8, 9], orderable: false, searchable: false }
                    ]
                });
                // Move the "Showing X to Y of Z entries" text and the Previous/Next
                // pagination OUT of the horizontally scrollable table wrapper into
                // the external footer (below the scroll area), so sliding the table
                // never moves them. Runs on every draw (idempotent re-parent).
                var uInfoTarget = el('usersPageInfo');
                var uPagNav = el('usersPagination') ? el('usersPagination').parentNode : null;
                function uRelocateFooter() {
                    if (!uInfoTarget || !uPagNav || !dt) return;
                    var wrap = window.jQuery('#usersTable').closest('.dataTables_wrapper');
                    if (!wrap.length) return;
                    var infoN = wrap.find('.dataTables_info').first();
                    var pagN = wrap.find('.dataTables_paginate').first();
                    if (infoN.length && infoN[0].parentNode !== uInfoTarget) {
                        uInfoTarget.innerHTML = '';
                        uInfoTarget.appendChild(infoN[0]);
                    }
                    if (pagN.length && pagN[0].parentNode !== uPagNav) {
                        var oldUl = el('usersPagination');
                        if (oldUl && oldUl.parentNode === uPagNav) uPagNav.removeChild(oldUl);
                        pagN[0].classList.add('pagination-sm');
                        uPagNav.appendChild(pagN[0]);
                    }
                    // Hide the (now empty) DataTables footer row inside the wrapper
                    // (scan the wrapper's own rows — closest('.row') no longer works
                    // once the elements have been moved out of it)
                    wrap.find('> .row, > div > .row').each(function () {
                        var rowEl = this;
                        if (!window.jQuery(rowEl).find('.dataTables_info, .dataTables_paginate, table').length) {
                            rowEl.style.display = 'none';
                        }
                    });
                }
                try {
                    dt.on('draw.dt', uRelocateFooter);
                    uRelocateFooter();
                } catch (eReloc) {}
                // Bind external length/search controls (kept outside the DataTable wrapper
                // so they are not affected when the table is moved).
                try {
                    var $lenSel = window.jQuery('#usersLengthSelect');
                    if ($lenSel.length && !$lenSel.data('boundUsers')) {
                        $lenSel.on('change.usersExt', function () {
                            var v = parseInt(window.jQuery(this).val(), 10);
                            if (dt && !isNaN(v)) {
                                dt.page.len(v).draw(false);
                            }
                        }).data('boundUsers', true);
                    }
                    var $searchInp = window.jQuery('#usersSearchInput');
                    if ($searchInp.length && !$searchInp.data('boundUsers')) {
                        var usersSearchTimer = null;
                        $searchInp.on('keyup.usersExt input.usersExt', function () {
                            if (usersSearchTimer) clearTimeout(usersSearchTimer);
                            var v = this.value;
                            usersSearchTimer = setTimeout(function () {
                                if (dt) dt.search(v).draw(false);
                            }, 250);
                        }).data('boundUsers', true);
                    }
                    // Sync external controls to current DataTable state
                    if ($lenSel.length) $lenSel.val(String(dt.page.len()));
                } catch (eExt) { /* noop */ }
                try {
                    $t.off('draw.dt.usersSel').on('draw.dt.usersSel', function () { syncSelectAllUi(); });
                } catch (e) {}
                try {
                    var saved = '';
                    try { saved = sessionStorage.getItem(searchStoreKey) || ''; } catch (e2) {}
                    if (saved) {
                        dt.search(saved).draw(false);
                        try { window.jQuery('#usersSearchInput').val(saved); } catch (eSync) {}
                    }
                    $t.off('search.dt.usersPersist').on('search.dt.usersPersist', function () {
                        if (!dt) return;
                        var v = dt.search();
                        try { sessionStorage.setItem(searchStoreKey, String(v || '')); } catch (e3) {}
                    });
                    // Keep external search input in sync if cleared by user
                    try {
                        $t.off('search.dt.usersExt').on('search.dt.usersExt', function () {
                            var cur = dt.search();
                            var $s = window.jQuery('#usersSearchInput');
                            if ($s.length && $s.val() !== cur) $s.val(cur);
                        });
                    } catch (eExtSync) {}
                } catch (e4) {}
                return;
            }

            var tbody = table.querySelector('tbody');
            if (!tbody) return;
            tbody.innerHTML = users.length ? users.map(function (u) {
                var r = buildRow(u);
                return ''
                    + '<tr>'
                    + '<td>' + r[0] + '</td>'
                    + '<td>' + r[1] + '</td>'
                    + '<td class="td-clip-200" title="' + r[2] + '">' + r[2] + '</td>'
                    + '<td class="td-clip-280" title="' + r[3] + '">' + r[3] + '</td>'
                    + '<td class="td-clip-200" title="' + r[4] + '">' + r[4] + '</td>'
                    + '<td>' + r[5] + '</td>'
                    + '<td class="td-clip-200" title="' + r[6] + '">' + r[6] + '</td>'
                    + '<td class="td-clip-200" title="' + r[7] + '">' + r[7] + '</td>'
                    + '<td>' + r[8] + '</td>'
                    + '<td>' + r[9] + '</td>'
                    + '<td>' + r[10] + '</td>'
                    + '</tr>';
            }).join('') : '<tr><td colspan="11" class="text-center text-muted py-4">No users yet.</td></tr>';
            syncSelectAllUi();
        }

        function refreshUsers() {
            return fetchJson(apiBase + '&action=list_users', { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) return;
                users = Array.isArray(res.data.users) ? res.data.users : [];
                var live = {};
                for (var i = 0; i < users.length; i++) {
                    if (users[i] && users[i].id != null) live[String(users[i].id)] = true;
                }
                Object.keys(selectedUserIds || {}).forEach(function (id) {
                    if (!live[id]) delete selectedUserIds[id];
                });
                // Preserve scroll position during refresh.
                var scrollParent = el('usersTable') ? el('usersTable').closest('.dataTables_wrapper') || el('usersTable').parentElement : null;
                var scrollTop = scrollParent ? scrollParent.scrollTop : 0;
                renderTable();
                updateSelectedCountUi();
                if (scrollParent) scrollParent.scrollTop = scrollTop;
            });
        }

        function startLiveClock() {
            if (clockTimer) window.clearInterval(clockTimer);
            function tick() {
                var now = new Date();
                var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                var y = now.getFullYear();
                var mo = months[now.getMonth()];
                var d = now.getDate();
                var h = now.getHours();
                var ampm = h >= 12 ? 'PM' : 'AM';
                h = h % 12 || 12;
                var mi = String(now.getMinutes()).padStart(2, '0');
                var s = String(now.getSeconds()).padStart(2, '0');
                var ts = mo + ' ' + d + ', ' + y + ' ' + h + ':' + mi + ':' + s + ' ' + ampm;
                if (el('usersUpdatedAt')) el('usersUpdatedAt').textContent = 'Updated: ' + ts;
            }
            tick();
            clockTimer = window.setInterval(tick, 1000);
        }

        function openUserModal(mode, user) {
            if (!el('userModal')) return;
            var showModal = function () {
                if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('userModal')).show();
            };
            var modalRole = el('userModal').getAttribute('data-session-role') || sessionRole || '';
            var sessionUserId = el('userModal').getAttribute('data-session-user-id') || '';
            var isOwnProfile = user && String(user.id) === String(sessionUserId);
            var lockedDepartment = (modalRole === 'admin' && myIdentity && myIdentity.department) ? String(myIdentity.department) : '';
            var isAdmin = modalRole === 'admin';
            var adminDeptUnlocked = (isAdmin && myIdentity) && (String(myIdentity.admin_department_unlocked) === '1' || myIdentity.admin_department_unlocked === 1 || myIdentity.admin_department_unlocked === true);
            var isEditOwnAdmin = isOwnProfile && isAdmin;
            var isCreatingUser = (mode === 'add' && isAdmin);
            var isEditingUser = (mode === 'edit' && user && String(user.role || '') === 'user');
            var shouldLockDepartment = isAdmin && !adminDeptUnlocked && (isCreatingUser || isEditingUser);
            var departmentInput = el('departmentInput');
            var departmentLockedNote = el('departmentLockedNote');
            if (departmentInput) {
                if (shouldLockDepartment && lockedDepartment) {
                    departmentInput.value = lockedDepartment;
                    departmentInput.readOnly = true;
                    departmentInput.setAttribute('list', '');
                    departmentInput.classList.add('bg-light');
                } else {
                    departmentInput.readOnly = false;
                    departmentInput.setAttribute('list', 'departmentList');
                    departmentInput.classList.remove('bg-light');
                }
            }
            if (departmentLockedNote) {
                if (shouldLockDepartment && lockedDepartment) {
                    departmentLockedNote.classList.remove('d-none');
                } else {
                    departmentLockedNote.classList.add('d-none');
                }
            }
            var assignedAdminCol = el('assignedAdminCol');
            var assignedAdminInput = el('assignedAdminInput');
            function renderAssignedAdminOptions(selectedId) {
                if (!assignedAdminInput) return;
                var admins = (users || []).filter(function (u) { return u && String(u.role || '') === 'admin'; });
                assignedAdminInput.innerHTML = '<option value="">Select admin</option>' + admins.map(function (a) {
                    var label = String(a.name || a.username || ('Admin #' + String(a.id || ''))).trim();
                    return '<option value="' + escapeHtmlAttr(a.id) + '">' + escapeHtml(label) + '</option>';
                }).join('');
                assignedAdminInput.value = selectedId != null ? String(selectedId) : '';
            }
            if (mode === 'add') {
                el('userModalTitle').textContent = (modalRole === 'superadmin') ? 'Add Admin' : 'Add User';
            } else if (isOwnProfile && (modalRole === 'admin' || modalRole === 'superadmin')) {
                el('userModalTitle').textContent = 'Edit Admin';
            } else {
                el('userModalTitle').textContent = 'Edit User';
            }
            el('userId').value = user ? user.id : '';
            el('usernameInput').value = user ? (user.username || '') : '';
            el('idNumberInput').value = user ? (user.id_number || '') : '';
            el('realNameInput').value = user ? (user.name || '') : '';
            el('roleInput').value = user ? (user.role || 'user') : 'user';
            var roleDiv = el('roleInput').closest('.col-md-6');
            if (modalRole !== 'superadmin') {
                el('roleInput').value = 'user';
                if (roleDiv) roleDiv.style.display = 'none';
            } else {
                if (mode === 'add') {
                    el('roleInput').value = 'admin';
                    el('roleInput').disabled = true;
                    if (roleDiv) roleDiv.style.display = 'none';
                } else {
                    el('roleInput').disabled = false;
                    if (roleDiv) roleDiv.style.display = '';
                }
            }
            if (el('planMonthsInput')) {
                if (user && user.plan_months != null) {
                    el('planMonthsInput').value = String(user.plan_months || '1');
                } else if (mode === 'add' && modalRole === 'superadmin') {
                    el('planMonthsInput').value = '1';
                } else {
                    el('planMonthsInput').value = '1';
                }
            }
            var planFreeTrialBadge = el('planFreeTrialBadge');
            var planFreeTrialNote = el('planFreeTrialNote');
            var showFreeTrialUi = (mode === 'add' && modalRole === 'superadmin');
            if (planFreeTrialBadge) {
                if (showFreeTrialUi) planFreeTrialBadge.classList.remove('d-none');
                else planFreeTrialBadge.classList.add('d-none');
            }
            if (planFreeTrialNote) {
                if (showFreeTrialUi) planFreeTrialNote.classList.remove('d-none');
                else planFreeTrialNote.classList.add('d-none');
            }
            el('positionInput').value = user ? (user.position || '') : '';
            if (departmentInput) {
                if (shouldLockDepartment && lockedDepartment) {
                    departmentInput.value = lockedDepartment;
                } else {
                    departmentInput.value = user ? (user.department || '') : '';
                }
            }
            el('passwordInput').value = '';
            el('passwordInput').required = false;
            el('passwordInput').type = 'password';
            var passwordCol = el('passwordInput').closest('.col-md-6');
            function togglePasswordByRole() {
                var role = el('roleInput').value;
                if (modalRole === 'superadmin' && (role === 'admin' || isOwnProfile)) {
                    if (passwordCol) passwordCol.style.display = '';
                    if (el('passwordHelp')) el('passwordHelp').style.display = 'block';
                } else {
                    if (passwordCol) passwordCol.style.display = 'none';
                }
                if (assignedAdminCol) {
                    assignedAdminCol.style.display = (modalRole === 'superadmin' && role === 'user') ? '' : 'none';
                }
                var planCol = el('planMonthsCol');
                if (planCol) {
                    planCol.style.display = (modalRole === 'superadmin' && role === 'admin') ? '' : 'none';
                }
                if (modalRole === 'superadmin' && role === 'user') {
                    var sel = '';
                    if (assignedAdminInput && assignedAdminInput.value) sel = assignedAdminInput.value;
                    if (!sel && user && user.created_by != null) sel = String(user.created_by);
                    if (!sel && adminUsersAdminId) sel = String(adminUsersAdminId);
                    renderAssignedAdminOptions(sel);
                } else {
                    if (assignedAdminInput) assignedAdminInput.innerHTML = '';
                }
            }
            if (modalRole === 'superadmin') {
                togglePasswordByRole();
            } else {
                if (passwordCol) passwordCol.style.display = 'none';
                var planCol = el('planMonthsCol');
                if (planCol) planCol.style.display = 'none';
                if (assignedAdminCol) assignedAdminCol.style.display = 'none';
                if (assignedAdminInput) assignedAdminInput.innerHTML = '';
            }
            el('roleInput').onchange = function () { togglePasswordByRole(); };
            el('isActiveInput').checked = user ? (String(user.is_active) !== '0') : true;
            el('usernameInput').classList.remove('is-invalid');
            el('idNumberInput').classList.remove('is-invalid');
            var adminUsersModal = el('adminUsersModal');
            if (adminUsersModal && adminUsersModal.classList && adminUsersModal.classList.contains('show') && window.bootstrap && window.bootstrap.Modal) {
                var onHidden = function () {
                    try { adminUsersModal.removeEventListener('hidden.bs.modal', onHidden); } catch (e) {}
                    showModal();
                };
                try {
                    adminUsersModal.addEventListener('hidden.bs.modal', onHidden);
                    window.bootstrap.Modal.getOrCreateInstance(adminUsersModal).hide();
                } catch (e2) {
                    try { adminUsersModal.removeEventListener('hidden.bs.modal', onHidden); } catch (e3) {}
                    showModal();
                }
                return;
            }
            showModal();
        }

        function validateLive() {
            if (pendingValidateTimer) window.clearTimeout(pendingValidateTimer);
            pendingValidateTimer = window.setTimeout(function () {
                if (!el('userId')) return;
                var excludeId = el('userId').value || '0';
                var username = el('usernameInput').value || '';
                var idNumber = el('idNumberInput').value || '';
                var url = apiBase + '&action=validate'
                    + '&exclude_id=' + encodeURIComponent(excludeId)
                    + '&username=' + encodeURIComponent(username)
                    + '&id_number=' + encodeURIComponent(idNumber);
                fetchJson(url, { method: 'GET' }).then(function (res) {
                    if (!res.data || res.data.ok !== true) return;
                    var ua = res.data.username_available;
                    var ia = res.data.id_number_available;
                    if (ua === false) el('usernameInput').classList.add('is-invalid'); else el('usernameInput').classList.remove('is-invalid');
                    if (ia === false) el('idNumberInput').classList.add('is-invalid'); else el('idNumberInput').classList.remove('is-invalid');
                });
            }, 250);
        }

        function buildQrData(u) {
            var payload = (u && u.qr_payload) ? String(u.qr_payload) : '';
            if (payload) return payload;
            var token = (u && u.qr_token) ? String(u.qr_token) : '';
            if (token) return token;
            return '';
        }

        function qrUrlForData(data) {
            return 'https://api.qrserver.com/v1/create-qr-code/?size=256x256&data=' + encodeURIComponent(String(data || ''));
        }

        function setQrImage(data) {
            lastQrData = data || '';
            var t = Date.now ? Date.now() : new Date().getTime();
            if (el('qrImg')) el('qrImg').src = qrUrlForData(lastQrData) + '&t=' + encodeURIComponent(String(t));
        }

        function openQrModal(u) {
            if (!el('qrModal')) return;
            lastQrUserId = u ? u.id : null;
            var cap = [];
            if (u && u.name) cap.push(String(u.name));
            if (u && u.position) cap.push(String(u.position));
            if (u && u.department) cap.push(String(u.department));
            if (u && u.id_number) cap.push(String(u.id_number));
            if (el('qrCaption')) el('qrCaption').textContent = cap.join(' • ');
            setQrImage(buildQrData(u));
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('qrModal')).show();
        }

        /* ── Face Enrollment (MediaPipe Face Landmarker) ── */
        var faceEnrollUser = null;
        var faceEnrollStream = null;
        var faceEnrollModelsLoaded = false;
        var faceEnrollLandmarker = null;
        var faceEnrollCapturedDescriptor = null;
        var faceEnrollSamples = [];
        var faceEnrollSampleTarget = 5;
        var faceEnrollCapturedImage = null;
        var faceEnrollCapturedEmbedding = null;
        var faceEnrollLastCanvas = null;
        var faceEnrollDoSave = null; // populated by captureFaceEnroll()

        // MediaPipe canonical 6-point eye landmark indices for EAR
        // Left eye:  outer-corner, top1, top2, inner-corner, bottom1, bottom2
        // Right eye: inner-corner, top1, top2, outer-corner, bottom1, bottom2
        // (left eye is mirrored vs right eye so we reorder to [outer, top1, top2, inner, bot1, bot2])
        var MP_LEFT_EYE = [33, 160, 158, 133, 153, 144];
        var MP_RIGHT_EYE = [263, 387, 385, 362, 373, 380];
        // Stable faceprint landmarks (nose tip, chin, forehead, cheeks, mouth, eyes, brows, iris)
        // Used to build a comparable descriptor for the duplicate check.
        var MP_FACEPRINT_IDX = [
            10, 152, 1, 234, 454, 61, 291,           // forehead, chin, nose, cheeks, mouth corners
            33, 133, 362, 263,                        // eye corners
            70, 107, 336, 276,                        // eyebrow corners
            468, 473,                                 // iris centers (refined landmarks)
            168, 6, 351, 152,                         // nose bridge, nose tip, nose tip L/R
            127, 356, 130, 359,                       // jawline left/right upper
            162, 389, 21, 251, 284, 372,              // face contour points
            0, 17, 54, 287,                            // mouth outer corners wide
            37, 267, 82, 312,                          // upper/lower lip centers
            199, 419, 132, 361                         // additional contour points
        ];

        // MediaPipe model sources, in priority order. MediaPipe's
        // FilesetResolver caches its wasm assets with the Cache API
        // (Cache.addAll), which rejects with "Request failed" whenever a
        // single CDN fetch fails — taking the whole loader down with an
        // uncaught error. Trying the next mirror automatically makes the
        // loader self-heal through transient CDN failures.
        var mpSources = [
            {
                mp: 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.18/vision_bundle.mjs',
                wasm: 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.18/wasm',
                model: 'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task'
            },
            {
                mp: 'https://unpkg.com/@mediapipe/tasks-vision@0.10.18/vision_bundle.mjs',
                wasm: 'https://unpkg.com/@mediapipe/tasks-vision@0.10.18/wasm',
                model: 'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task'
            },
            {
                mp: 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.18/vision_bundle.mjs?cb=' + Date.now(),
                wasm: 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.18/wasm',
                model: 'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task'
            }
        ];

        function buildLandmarkerFromSource(src) {
            return import(src.mp).then(function (mod) {
                var FilesetResolver = mod.FilesetResolver || (mod.default && mod.default.FilesetResolver);
                var FaceLandmarker = mod.FaceLandmarker || (mod.default && mod.default.FaceLandmarker);
                if (!FilesetResolver || !FaceLandmarker) {
                    throw new Error('MediaPipe module loaded but exports are missing');
                }
                return FilesetResolver.forVisionTasks(src.wasm).then(function (filesetResolver) {
                    return FaceLandmarker.createFromOptions(filesetResolver, {
                        baseOptions: {
                            modelAssetPath: src.model,
                            delegate: 'GPU'
                        },
                        outputFaceBlendshapes: false,
                        outputFacialTransformationMatrixes: false,
                        runningMode: 'VIDEO',
                        numFaces: 1,
                        refineFaceLandmarks: true
                    });
                });
            });
        }

        function loadLandmarkerWithFallback(store) {
            var idx = 0;
            function attempt() {
                if (idx >= mpSources.length) {
                    throw new Error('Face detection model could not be downloaded from any source. Check your internet connection and try again.');
                }
                var src = mpSources[idx++];
                return buildLandmarkerFromSource(src).catch(function (err) {
                    if (idx >= mpSources.length) throw err;
                    return attempt();
                });
            }
            return attempt().then(function (lm) {
                if (store) {
                    store.landmarker = lm;
                    if ('modelsLoaded' in store) store.modelsLoaded = true;
                }
                return lm;
            });
        }

        function loadMediaPipeFaceLandmarker() {
            if (faceEnrollModelsLoaded && faceEnrollLandmarker) return Promise.resolve(faceEnrollLandmarker);
            // MediaPipe Tasks Vision is an ESM-only package - load it dynamically.
            return loadLandmarkerWithFallback(null).then(function (lm) {
                faceEnrollLandmarker = lm;
                faceEnrollModelsLoaded = true;
                preloadFaceEmbedder();
                return lm;
            });
        }

        // face-api (@vladmandic/face-api) supplies the 128-dim learned face
        // descriptor (FaceRecognitionNet) that replaces the old 42-dim
        // geometric faceprint. Detection, 68-point alignment and recognition
        // are handled internally; MediaPipe FaceLandmarker remains responsible
        // for the blink-twice liveness gate.
        var faceApiReady = false;
        var faceApiLoading = null;
        function loadFaceApi() {
            if (faceApiReady) return Promise.resolve();
            if (faceApiLoading) return faceApiLoading;
            var MODEL = 'https://cdn.jsdelivr.net/gh/vladmandic/face-api/model';
            faceApiLoading = new Promise(function (resolve, reject) {
                if (window.faceapi && window.faceapi.nets) { resolve(window.faceapi); return; }
                var s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/dist/face-api.js';
                s.onload = function () { resolve(window.faceapi); };
                s.onerror = function () { reject(new Error('Failed to load face-api')); };
                document.head.appendChild(s);
            }).then(function (api) {
                // Force the CPU backend. The default WebGL backend fails on many
                // kiosk/remote-desktop devices, and the fallback WASM backend
                // 404s from the jsDelivr CDN — both made every enrollment
                // silently save a legacy print with no 128-dim embedding, so
                // the "Re-enroll Needed" badge never cleared. CPU needs no
                // GPU/WASM and works everywhere; inference is one-shot per
                // capture/scan, so the speed cost is negligible.
                var tf = api && api.tf;
                if (tf && typeof tf.setBackend === 'function') {
                    try {
                        return tf.setBackend('cpu').then(function () { return tf.ready(); }).then(function () { return api; });
                    } catch (e) { /* fall through to default backend */ }
                }
                return api;
            }).then(function (api) {
                return Promise.all([
                    api.nets.tinyFaceDetector.loadFromUri(MODEL),
                    api.nets.faceLandmark68Net.loadFromUri(MODEL),
                    api.nets.faceRecognitionNet.loadFromUri(MODEL)
                ]);
            }).then(function () {
                faceApiReady = true;
                faceApiLoading = null;
            }).catch(function (err) {
                faceApiLoading = null;
                throw err;
            });
            return faceApiLoading;
        }

        // Run face detection + recognition on a canvas: resolves with a
        // 128-dim FaceRecognitionNet descriptor array (or null if no face was
        // found or the model is unavailable).
        function embedCanvas(canvas) {
            if (!canvas) return Promise.resolve(null);
            return loadFaceApi().then(function () {
                var opts = new window.faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.4 });
                return window.faceapi.detectSingleFace(canvas, opts).withFaceLandmarks().withFaceDescriptor();
            }).then(function (det) {
                return det && det.descriptor ? Array.prototype.slice.call(det.descriptor) : null;
            }).catch(function () { return null; });
        }

        // Wrap a geometric descriptor + face embedding into the v2 payload sent
        // as face_data: {"v":2,"emb":[...128 floats...],"desc":[...42 floats...]}.
        // Falls back to the bare legacy descriptor array when no embedding is
        // available.
        function buildFacePayload(desc, emb) {
            if (emb && emb.length >= 128) {
                var p = { v: 2, emb: emb };
                if (desc && desc.length > 0) p.desc = desc;
                return p;
            }
            return desc;
        }

        // Preload face-api (fire-and-forget) so it is ready by the time a
        // blink-twice capture finishes. Runs alongside the landmarker load.
        function preloadFaceEmbedder() {
            try { loadFaceApi().catch(function () {}); } catch (e) {}
        }

        // ---- Face Scan Diagnostics ----
        // Captures samples with the same blink-twice liveness gate used by
        // enrollment, then records genuine vs impostor distance distributions
        // via attendance.php (admin-session only). Lets admins pick an accept
        // threshold from real data instead of guessing.
        var faceDiag = { stream: null, subjectId: 0, samples: [], target: 5, busy: false, submitted: false, starting: false };

        function faceDiagFillSubjects() {
            var sel = el('faceDiagSubject');
            if (!sel) return;
            var current = String(faceDiag.subjectId || '');
            sel.innerHTML = '';
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select an enrolled user…';
            sel.appendChild(placeholder);
            for (var i = 0; i < users.length; i++) {
                var u = users[i];
                var hasFace = u && (String(u.has_face_data) === '1' || u.has_face_data === 1 || u.has_face_data === true);
                if (!hasFace) continue;
                var legacyFlag = String(u.face_kind || '') === 'legacy';
                var o = document.createElement('option');
                o.value = String(u.id);
                o.textContent = (u.name || u.username || ('User #' + u.id)) + (legacyFlag ? ' (re-enroll needed)' : '');
                sel.appendChild(o);
            }
            if (current && sel.querySelector('option[value="' + current + '"]')) sel.value = current;
        }

        function faceDiagStartCamera() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                return Promise.reject(new Error('Camera not supported'));
            }
            var video = el('faceDiagVideo');
            if (video) { video.srcObject = null; video.load(); }
            return navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' } }).then(function (stream) {
                faceDiag.stream = stream;
                var v = el('faceDiagVideo');
                if (!v) return;
                v.srcObject = stream;
                return new Promise(function (resolve) {
                    if (v.readyState >= 1) { v.play().then(resolve).catch(resolve); return; }
                    v.onloadedmetadata = function () { v.play().then(resolve).catch(resolve); };
                });
            });
        }

        function faceDiagStopCamera() {
            if (faceDiag.stream) {
                faceDiag.stream.getTracks().forEach(function (t) { t.stop(); });
                faceDiag.stream = null;
            }
            var video = el('faceDiagVideo');
            if (video) video.srcObject = null;
        }

        function faceDiagCapture() {
            if (faceDiag.busy) return;
            var sel = el('faceDiagSubject');
            faceDiag.subjectId = sel ? parseInt(sel.value, 10) : 0;
            if (!faceDiag.subjectId) {
                if (el('faceDiagMsg')) el('faceDiagMsg').textContent = 'Select an enrolled user first.';
                return;
            }
            var video = el('faceDiagVideo');
            var canvas = el('faceDiagCanvas');
            var msg = el('faceDiagMsg');
            if (!video || !canvas) return;
            faceDiag.busy = true;
            faceDiag.samples = [];
            var btn = el('faceDiagCaptureBtn');
            if (btn) btn.disabled = true;
            canvas.width = video.videoWidth || 640;
            canvas.height = video.videoHeight || 480;
            var ctx = canvas.getContext('2d');
            var blinkCount = 0;
            var blinkState = 'scanning';
            var closedSince = 0;
            var closedFrames = 0;
            var openFrames = 0;
            var lastDescriptor = null;
            var finished = false;
            var cooldown = false;
            var recentEARs = [];
            var PEAK_WINDOW = 30;
            var CLOSE_RATIO = 0.55;
            var OPEN_RATIO = 0.78;
            var ABS_OPEN = 0.20;
            var ABS_CLOSE = 0.15;
            var CLOSED_MIN_MS = 35;
            var CLOSED_MAX_MS = 1500;

            function tick() {
                if (finished || !video.srcObject) return;
                if (!faceEnrollLandmarker) { setTimeout(tick, 50); return; }
                ctx.drawImage(video, 0, 0);
                faceDiag.lastCanvas = canvas;
                var nowMs = performance.now();
                var mpResult;
                try { mpResult = faceEnrollLandmarker.detectForVideo(video, nowMs); }
                catch (e) { setTimeout(tick, 50); return; }
                if (!mpResult || !mpResult.faceLandmarks || mpResult.faceLandmarks.length === 0) {
                    if (msg) msg.textContent = 'Looking for face... Please face the camera.';
                    setTimeout(tick, 20);
                    return;
                }
                var landmarks = mpResult.faceLandmarks[0];
                var quality = checkFaceQuality(landmarks);
                if (!quality.ok) {
                    if (msg) msg.textContent = quality.reason;
                    setTimeout(tick, 20);
                    return;
                }
                var ear = (eyeAspectRatioMP(landmarks, MP_LEFT_EYE) + eyeAspectRatioMP(landmarks, MP_RIGHT_EYE)) / 2;
                var fp = buildFaceprintMP(landmarks);
                if (fp) lastDescriptor = fp;
                // Pre-blink samples (same as enrollment)
                if (fp && blinkCount < 2 && faceDiag.samples.length < 2 && !cooldown) {
                    faceDiag.samples.push(fp.slice());
                }
                if (recentEARs.length >= PEAK_WINDOW) recentEARs.shift();
                recentEARs.push(ear);
                var peakEAR = 0;
                for (var pi = 0; pi < recentEARs.length; pi++) { if (recentEARs[pi] > peakEAR) peakEAR = recentEARs[pi]; }
                var useRatio = recentEARs.length >= 8;
                var closeT = useRatio ? (peakEAR * CLOSE_RATIO) : ABS_CLOSE;
                var openT = useRatio ? (peakEAR * OPEN_RATIO) : ABS_OPEN;
                var now = Date.now();
                if (msg) {
                    msg.innerHTML = 'EAR: <strong>' + ear.toFixed(3) + '</strong> &middot; Blinks: <strong>' + blinkCount + '/2</strong>';
                }
                if (ear <= closeT) {
                    closedFrames++;
                    if (blinkState === 'scanning' && closedFrames >= 1) { blinkState = 'eyes_closed'; closedSince = now; openFrames = 0; }
                } else if (ear >= openT) {
                    if (blinkState === 'eyes_closed') {
                        openFrames++;
                        if (openFrames >= 2) {
                            var closedFor = now - closedSince;
                            if (closedFor >= CLOSED_MIN_MS && closedFor <= CLOSED_MAX_MS && !cooldown) {
                                blinkCount++;
                                cooldown = true;
                                if (msg) msg.innerHTML = '<span class="text-success fw-semibold">Blink ' + blinkCount + ' detected!</span> ' + (blinkCount < 2 ? 'Blink once more...' : '');
                                if (blinkCount >= 2) {
                                    if (fp) faceDiag.samples.push(fp.slice());
                                    collectPostBlinkSamples();
                                    return;
                                }
                                setTimeout(function () { cooldown = false; }, 450);
                            }
                            blinkState = 'scanning'; closedFrames = 0; openFrames = 0;
                        }
                    } else { closedFrames = 0; openFrames = 0; }
                } else {
                    if (blinkState === 'eyes_closed' && (now - closedSince) > CLOSED_MAX_MS) { blinkState = 'scanning'; closedFrames = 0; openFrames = 0; }
                }
                setTimeout(tick, 20);
            }

            function collectPostBlinkSamples() {
                finished = true;
                var interval = setInterval(function () {
                    if (!video.srcObject) { clearInterval(interval); return; }
                    var fpNow = null;
                    try {
                        var res = faceEnrollLandmarker.detectForVideo(video, performance.now());
                        if (res && res.faceLandmarks && res.faceLandmarks.length > 0) {
                            var q = checkFaceQuality(res.faceLandmarks[0]);
                            if (q.ok) fpNow = buildFaceprintMP(res.faceLandmarks[0]);
                        }
                    } catch (e) {}
                    if (fpNow && faceDiag.samples.length < faceDiag.target) {
                        var last = faceDiag.samples[faceDiag.samples.length - 1];
                        var d = 0;
                        if (last) {
                            for (var k = 0; k < Math.min(fpNow.length, last.length); k++) { var dd = fpNow[k] - last[k]; d += dd * dd; }
                            d = Math.sqrt(d);
                        }
                        if (d < 0.15) faceDiag.samples.push(fpNow.slice());
                    }
                    if (msg) msg.textContent = 'Collecting samples... (' + faceDiag.samples.length + '/' + faceDiag.target + ')';
                    if (faceDiag.samples.length >= faceDiag.target) {
                        clearInterval(interval);
                        faceDiagSubmit(averageDescriptors(faceDiag.samples));
                    }
                }, 350);
                setTimeout(function () {
                    clearInterval(interval);
                    if (!faceDiag.busy || !lastDescriptor) return;
                    if (faceDiag.samples.length < faceDiag.target && !faceDiag.submitted) {
                        faceDiagSubmit(averageDescriptors(faceDiag.samples));
                    }
                }, 2600);
            }
            tick();
        }

        function faceDiagSubmit(avg) {
            var msg = el('faceDiagMsg');
            faceDiagStopCamera();
            if (!avg || avg.length < 8) {
                faceDiag.busy = false;
                var btn0 = el('faceDiagCaptureBtn');
                if (btn0) btn0.disabled = false;
                if (msg) msg.textContent = 'Could not build a descriptor. Try again.';
                return;
            }
            faceDiag.submitted = true;
            if (msg) msg.innerHTML = 'Submitting sample...';
            // Build a v2 payload (128-dim descriptor + legacy descriptor) from
            // the last frame so the server records v2-space distances; falls
            // back to the descriptor alone if face-api is unavailable.
            embedCanvas(faceDiag.lastCanvas || null).then(function (emb) {
                var payload = buildFacePayload(avg, emb);
                var fd = new FormData();
                fd.append('subject_id', String(faceDiag.subjectId));
                fd.append('face_data', JSON.stringify(payload));
                return fetch(pageUrl('attendance') + '?ajax=1&action=face_diag_capture', { method: 'POST', body: fd, cache: 'no-store' });
            })
                .then(function (r) {
                    return r.text().then(function (t) {
                        var b = null;
                        try { b = JSON.parse(t); } catch (e) { b = { ok: false, message: t || 'Invalid server response' }; }
                        return { status: r.status, body: b };
                    });
                })
                .then(function (res) {
                    faceDiag.busy = false;
                    faceDiag.submitted = false;
                    var btn = el('faceDiagCaptureBtn');
                    if (btn) btn.disabled = false;
                    var r = res.body || {};
                    var resultEl = el('faceDiagResult');
                    if (res.status >= 200 && res.status < 300 && r.ok) {
                        var verdict = (r.genuine_dist !== null && r.genuine_dist < r.current_threshold)
                            ? '<span class="text-success fw-semibold">passes ' + r.current_threshold + '</span>'
                            : '<span class="text-danger fw-semibold">above ' + r.current_threshold + ' (would reject)</span>';
                        if (resultEl) {
                            resultEl.classList.remove('d-none');
                            resultEl.innerHTML =
                                '<div><span class="text-success fw-semibold">Genuine (you):</span> <strong>' + r.genuine_dist + '</strong> ' + verdict + '</div>' +
                                '<div><span class="text-danger fw-semibold">Impostor closest:</span> <strong>' + (r.min_impostor_dist == null ? '—' : r.min_impostor_dist) + '</strong> <span class="text-muted">(' + r.n_impostors + ' other users compared)</span></div>' +
                                '<div class="text-muted mt-1">The gap between the two curves is what makes a threshold reliable.</div>';
                        }
                        if (msg) msg.innerHTML = '<span class="text-success fw-semibold">Sample recorded for ' + escapeHtml(r.subject_name || '') + '.</span>';
                        loadFaceDiagStats();
                    } else {
                        var errMsg = r && r.message ? r.message : ('Server returned ' + res.status);
                        if (msg) msg.innerHTML = '<span class="text-danger fw-semibold">' + escapeHtml(errMsg) + '</span>';
                    }
                })
                .catch(function (err) {
                    faceDiag.busy = false;
                    faceDiag.submitted = false;
                    var btn = el('faceDiagCaptureBtn');
                    if (btn) btn.disabled = false;
                    if (msg) msg.innerHTML = '<span class="text-danger fw-semibold">Network error: ' + escapeHtml((err && err.message) || 'could not reach server') + '</span>';
                });
        }

        function loadFaceDiagStats() {
            fetch(pageUrl('attendance') + '?ajax=1&action=face_diag_stats', { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) renderFaceDiagStats(res);
                })
                .catch(function () {});
        }

        function renderFaceDiagStats(res) {
            var wrap = el('faceDiagStats');
            if (!wrap) return;
            var c = res.counts || {};
            var g = res.genuine || {};
            var im = res.impostor || {};
            if ((c.genuine || 0) === 0 && (c.impostor || 0) === 0) {
                wrap.className = 'text-muted small';
                wrap.innerHTML = 'No samples yet. Capture a few samples per enrolled user.';
                return;
            }
            wrap.className = 'small';
            var s = res.suggestion || {};
            var cur = res.current_threshold;
            var fmt = function (v) { return (v === null || v === undefined) ? '—' : v; };
            var html = '';
            html += '<div class="mb-2"><span class="text-success fw-semibold">Genuine:</span> n=' + (c.genuine || 0) + ' min=' + fmt(g.min) + ' max=' + fmt(g.max) + ' mean=' + fmt(g.mean) + ' &sigma;=' + fmt(g.stdev) + '</div>';
            html += '<div class="mb-2"><span class="text-danger fw-semibold">Impostor:</span> n=' + (c.impostor || 0) + ' min=' + fmt(im.min) + ' max=' + fmt(im.max) + ' mean=' + fmt(im.mean) + ' &sigma;=' + fmt(im.stdev) + '</div>';
            var hg = (res.histogram && res.histogram.genuine) ? res.histogram.genuine : [];
            var hi = (res.histogram && res.histogram.impostor) ? res.histogram.impostor : [];
            var maxCount = 1;
            for (var i = 0; i < hg.length; i++) { if (hg[i].count > maxCount) maxCount = hg[i].count; }
            for (var j = 0; j < hi.length; j++) { if (hi[j].count > maxCount) maxCount = hi[j].count; }
            var bars = '';
            for (var k = 0; k < hg.length; k++) {
                var b = hg[k];
                var gCount = b.count;
                var iCount = hi[k] ? hi[k].count : 0;
                if (gCount === 0 && iCount === 0) continue;
                var marker = '';
                if (cur !== null && cur !== undefined && cur >= b.lo && cur < b.hi) {
                    marker = '<div style="position:absolute;top:0;bottom:0;left:50%;width:2px;background:#334155;z-index:2;" title="current threshold ' + cur + '"></div>';
                }
                bars += '<div class="d-flex align-items-center gap-1 mb-1" style="position:relative;">' +
                    '<span class="text-end text-muted" style="width:34px;font-size:9px;">' + b.lo.toFixed(2) + '</span>' +
                    '<div style="flex:1;position:relative;">' +
                        '<div style="height:10px;background:#22c55e;opacity:0.85;width:' + Math.max(2, Math.round((gCount / maxCount) * 100)) + '%;border-radius:2px;" title="genuine ' + gCount + '"></div>' +
                        '<div style="height:10px;background:#ef4444;opacity:0.75;width:' + Math.max(2, Math.round((iCount / maxCount) * 100)) + '%;border-radius:2px;" title="impostor ' + iCount + '"></div>' +
                    '</div>' + marker +
                    '<span class="text-muted" style="width:18px;font-size:9px;">' + (gCount + iCount) + '</span>' +
                '</div>';
            }
            html += '<div class="border rounded p-2 mb-2" style="overflow:hidden;">' + (bars || '<div class="text-muted">No distances in the 0–2 range yet.</div>') + '</div>';
            if (s.threshold !== null && s.threshold !== undefined) {
                html += '<div class="alert alert-warning py-1 px-2 mb-1">Suggested threshold (FAR&asymp;FRR, EER proxy): <strong>' + s.threshold + '</strong> &mdash; FAR ' + s.far + ' / FRR ' + s.frr + '</div>';
            } else {
                html += '<div class="text-muted">Need at least one genuine AND one impostor sample to suggest a threshold.</div>';
            }
            html += '<div class="text-muted">Current accept threshold: <strong>' + cur + '</strong> (kiosk face scan).</div>';
            wrap.innerHTML = html;
        }

        function openFaceDiagModal() {
            var modal = el('faceDiagModal');
            if (!modal) return;
            faceDiag.subjectId = 0;
            faceDiag.busy = false;
            faceDiag.submitted = false;
            faceDiag.starting = false;
            faceDiagFillSubjects();
            var resultEl = el('faceDiagResult');
            if (resultEl) { resultEl.classList.add('d-none'); resultEl.innerHTML = ''; }
            if (el('faceDiagMsg')) el('faceDiagMsg').textContent = 'Select a user, then click Capture Sample.';
            var capBtn = el('faceDiagCaptureBtn');
            if (capBtn) capBtn.disabled = false;
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
            loadFaceDiagStats();
        }

        function clearFaceDiagData() {
            if (window.Swal) {
                Swal.fire({
                    title: 'Clear diagnostic samples?',
                    text: 'This removes all recorded genuine/impostor distances.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Clear',
                    cancelButtonText: 'Cancel'
                }).then(function (result) {
                    if (result && result.value) doClearFaceDiag();
                });
            } else if (window.confirm) {
                if (confirm('Clear diagnostic samples?')) doClearFaceDiag();
            }
        }

        function doClearFaceDiag() {
            fetch(pageUrl('attendance') + '?ajax=1&action=face_diag_clear', { method: 'POST', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) loadFaceDiagStats();
                })
                .catch(function () {});
        }

        function openFaceEnrollModal(u) {
            if (!el('faceEnrollModal')) return;
            faceEnrollUser = u;
            faceEnrollCapturedDescriptor = null;
            faceEnrollCapturedImage = null;
            faceEnrollCapturedEmbedding = null;
            faceEnrollLastCanvas = null;
            var label = [];
            if (u && u.name) label.push(String(u.name));
            if (u && u.username) label.push('(' + u.username + ')');
            if (el('faceEnrollUserLabel')) el('faceEnrollUserLabel').textContent = 'Enrolling: ' + label.join(' ');
            if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'Loading face detection models...';
            if (el('faceEnrollPreview')) el('faceEnrollPreview').classList.add('d-none');
            if (el('faceEnrollCaptureBtn')) el('faceEnrollCaptureBtn').classList.add('d-none');
            if (el('faceEnrollSaveBtn')) el('faceEnrollSaveBtn').classList.add('d-none');
            var hasFace = u && (String(u.has_face_data) === '1' || u.has_face_data === 1 || u.has_face_data === true);
            if (el('faceEnrollDeleteBtn')) {
                if (hasFace) {
                    el('faceEnrollDeleteBtn').classList.remove('d-none');
                } else {
                    el('faceEnrollDeleteBtn').classList.add('d-none');
                }
            }
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('faceEnrollModal')).show();
            loadMediaPipeFaceLandmarker().then(function () {
                if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'Starting camera...';
                return startFaceEnrollCamera();
            }).then(function () {
                captureFaceEnroll();
            }).catch(function (err) {
                console.error('Face enrollment init error:', err);
                var detail = (err && err.message) ? err.message : String(err || 'unknown');
                if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'Failed to load face detection: ' + detail;
            });
        }

        function startFaceEnrollCamera() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                return Promise.reject(new Error('Camera not supported'));
            }
            var video = el('faceEnrollVideo');
            if (video) {
                video.srcObject = null;
                video.load();
            }
            return navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' } }).then(function (stream) {
                faceEnrollStream = stream;
                var video = el('faceEnrollVideo');
                if (!video) return;
                video.srcObject = stream;
                return new Promise(function (resolve) {
                    if (video.readyState >= 1) {
                        video.play().then(resolve).catch(resolve);
                        return;
                    }
                    video.onloadedmetadata = function () { video.play().then(resolve).catch(resolve); };
                });
            });
        }

        function stopFaceEnrollCamera() {
            if (faceEnrollStream) {
                faceEnrollStream.getTracks().forEach(function (t) { t.stop(); });
                faceEnrollStream = null;
            }
            var video = el('faceEnrollVideo');
            if (video) video.srcObject = null;
        }

        // Eye Aspect Ratio (EAR) using MediaPipe Face Landmarker indices.
        // eyeIndices: [outer, top, top, inner, bottom, bottom] (6 points)
        // EAR = (|p2-p6| + |p3-p5|) / (2 * |p1-p4|)
        // Open eye ~ 0.22 - 0.35, closed eye ~ 0.03 - 0.10 (MediaPipe is more precise than face-api)
        function eyeAspectRatioMP(landmarks, eyeIndices) {
            var pts = eyeIndices.map(function (i) { return landmarks[i]; });
            var v1 = dist(pts[1], pts[5]);
            var v2 = dist(pts[2], pts[4]);
            var h = dist(pts[0], pts[3]);
            if (h <= 0.0001) return 0;
            return (v1 + v2) / (2 * h);
        }

        // Build a comparable faceprint from a MediaPipe landmark array (478 points, normalized 0-1).
        // We extract a stable subset, then center + scale-normalize so the descriptor is
        // invariant to head position in frame and to face size / distance.
        function buildFaceprintMP(landmarks) {
            // Reference face size: distance from nose tip (1) to chin (152) - vertical extent.
            var nose = landmarks[1];
            var chin = landmarks[152];
            var faceH = dist(nose, chin);
            if (faceH <= 0.0001) return null;
            // Use midpoint of eye corners as origin
            var leftEyeOuter = landmarks[33];
            var rightEyeOuter = landmarks[263];
            var ox = (leftEyeOuter.x + rightEyeOuter.x) / 2;
            var oy = (leftEyeOuter.y + rightEyeOuter.y) / 2;
            var fp = [];
            // Add normalized landmark positions
            for (var i = 0; i < MP_FACEPRINT_IDX.length; i++) {
                var p = landmarks[MP_FACEPRINT_IDX[i]];
                if (!p) continue;
                fp.push((p.x - ox) / faceH);
                fp.push((p.y - oy) / faceH);
            }
            // Add angular features for better discrimination
            // Angle: left eye outer to nose tip to right eye outer
            if (leftEyeOuter && nose && rightEyeOuter) {
                var a1 = Math.atan2(nose.y - leftEyeOuter.y, nose.x - leftEyeOuter.x);
                var a2 = Math.atan2(nose.y - rightEyeOuter.y, nose.x - rightEyeOuter.x);
                fp.push(a1 - a2); // eye-nose angle difference
            }
            // Angle: left cheek to nose to right cheek
            var leftCheek = landmarks[234];
            var rightCheek = landmarks[454];
            if (leftCheek && nose && rightCheek) {
                var a3 = Math.atan2(nose.y - leftCheek.y, nose.x - leftCheek.x);
                var a4 = Math.atan2(nose.y - rightCheek.y, nose.x - rightCheek.x);
                fp.push(a3 - a4); // cheek-nose angle difference
            }
            // Eye aspect ratio features
            var leftEyeInner = landmarks[133];
            var rightEyeInner = landmarks[362];
            var el159 = landmarks[159], el145 = landmarks[145], er386 = landmarks[386], er374 = landmarks[374];
            if (leftEyeOuter && leftEyeInner && rightEyeOuter && rightEyeInner && el159 && el145 && er386 && er374) {
                var eyeWidth = dist(leftEyeOuter, rightEyeOuter);
                var eyeHeight = (dist(el159, el145) + dist(er386, er374)) / 2;
                if (eyeWidth > 0.0001) fp.push(eyeHeight / eyeWidth); // eye aspect ratio
            }
            // Mouth width to face width ratio
            var mouthLeft = landmarks[61];
            var mouthRight = landmarks[291];
            if (mouthLeft && mouthRight && leftCheek && rightCheek) {
                var mouthW = dist(mouthLeft, mouthRight);
                var faceW = dist(leftCheek, rightCheek);
                if (faceW > 0.0001) fp.push(mouthW / faceW); // mouth-to-face ratio
            }
            return fp;
        }

        function dist(a, b) {
            return Math.sqrt(Math.pow(a.x - b.x, 2) + Math.pow(a.y - b.y, 2));
        }

        function averageDescriptors(samples) {
            if (!samples || samples.length === 0) return null;
            if (samples.length === 1) return samples[0];
            var len = samples[0].length;
            var result = [];
            for (var i = 0; i < len; i++) {
                var sum = 0;
                for (var j = 0; j < samples.length; j++) {
                    sum += samples[j][i] || 0;
                }
                result.push(sum / samples.length);
            }
            return result;
        }

        function checkFaceQuality(landmarks) {
            var nose = landmarks[1];
            var chin = landmarks[152];
            var leftEar = landmarks[234];
            var rightEar = landmarks[454];
            if (!nose || !chin || !leftEar || !rightEar) return { ok: false, reason: 'Missing landmarks' };
            var faceH = Math.sqrt(Math.pow(nose.x - chin.x, 2) + Math.pow(nose.y - chin.y, 2));
            var faceW = Math.sqrt(Math.pow(leftEar.x - rightEar.x, 2) + Math.pow(leftEar.y - rightEar.y, 2));
            if (faceH < 0.08) return { ok: false, reason: 'Face too small. Move closer.' };
            if (faceW < 0.10) return { ok: false, reason: 'Face too small. Move closer.' };
            var centerX = (nose.x + chin.x) / 2;
            if (centerX < 0.2 || centerX > 0.8) return { ok: false, reason: 'Center your face.' };
            var centerY = (nose.y + chin.y) / 2;
            if (centerY < 0.15 || centerY > 0.85) return { ok: false, reason: 'Center your face vertically.' };
            return { ok: true };
        }

        // Head-roll (tilt) angle in degrees from the eye line (outer corners 33/263)
        // relative to horizontal. 0 = head level; the abs value grows as the head tilts.
        function faceTiltDegMP(landmarks) {
            var l = landmarks[33];
            var r = landmarks[263];
            if (!l || !r) return 0;
            var dx = r.x - l.x;
            var dy = r.y - l.y;
            if (dx === 0 && dy === 0) return 0;
            return Math.abs(Math.atan2(dy, dx) * 180 / Math.PI);
        }

        // ===== Screen-presentation (anti-spoof) detection =====
// Detects a face displayed on an electronic screen (phone/tablet/monitor)
// held in front of the camera — which the blink liveness gate alone cannot
// distinguish from a live face when a video of a blinking person is shown.
// Three independent signals are scored on a downscaled grayscale copy of the
// frame:
//   1. bezel   — a bright, closed rectangular boundary around the face (the
//                screen frame): strong edge coverage on all four sides.
//   2. grid    — high-frequency subpixel texture inside the face region
//                (pixel grid / moiré) that live skin never shows.
//   3. flicker — periodic frame-to-frame luminance oscillation of the face
//                region caused by the screen refresh rate beating against the
//                camera's rolling shutter (the "device blinking" effect).
// A weighted score >= blockScore sustained for `consecutive` analyses blocks
// enrollment. Thresholds are conservative so genuine faces in normal office
// lighting are not blocked.
var enrollSpoofCfg = {
    downscale: 224,
    strongEdge: 48,
    bezelScales: [0.35, 0.8, 1.4, 2.2],
    bezelWeight: 0.40,
    gridWeight: 0.35,
    flickerWeight: 0.25,
    blockScore: 0.55,
    consecutive: 4,
    flickerWindow: 8,
    everyNFrames: 2
};
var enrollSpoofHist = [];
var CTR_ENG_BUILD = 6; // bump whenever the enrollment anti-spoof engine changes

function analyzeEnrollScreen(canvas, landmarks) {
    if (!canvas || !landmarks || !landmarks.length) return null;
    var W = enrollSpoofCfg.downscale;
    var H = Math.max(24, Math.round(W * 0.75));
    var tmp = document.createElement('canvas');
    tmp.width = W;
    tmp.height = H;
    var tctx = tmp.getContext('2d', { willReadFrequently: true });
    try { tctx.drawImage(canvas, 0, 0, W, H); } catch (e) { return null; }
    var img;
    try { img = tctx.getImageData(0, 0, W, H); } catch (e2) { return null; }
    var d = img.data;
    var gray = new Float32Array(W * H);
    var mean = 0;
    for (var i = 0; i < W * H; i++) {
        var r = d[i * 4], g = d[i * 4 + 1], b = d[i * 4 + 2];
        var v = r * 0.299 + g * 0.587 + b * 0.114;
        gray[i] = v;
        mean += v;
    }
    mean /= (W * H);

    // Face bounding box in downscaled coordinates
    var minX = 1, maxX = 0, minY = 1, maxY = 0;
    for (var li = 0; li < landmarks.length; li++) {
        var lm = landmarks[li];
        if (!lm) continue;
        if (lm.x < minX) minX = lm.x;
        if (lm.x > maxX) maxX = lm.x;
        if (lm.y < minY) minY = lm.y;
        if (lm.y > maxY) maxY = lm.y;
    }
    var fx = Math.round(minX * W), fy = Math.round(minY * H);
    var fw = Math.max(4, Math.round((maxX - minX) * W));
    var fh = Math.max(4, Math.round((maxY - minY) * H));

    // Edge magnitude map using 1px offsets. STRONG_EDGE is a FIXED absolute
    // threshold: a bright screen bezel or subpixel/moire texture produces
    // near-max edges (~150+), while skin, hair and gradients stay below it.
    var edges = new Float32Array(W * H);
    var maxEdge = 0;
    for (var y = 1; y < H - 1; y++) {
        for (var x = 1; x < W - 1; x++) {
            var idx = y * W + x;
            var dx = Math.abs(gray[idx + 1] - gray[idx]);
            var dy = Math.abs(gray[idx + W] - gray[idx]);
            var e = dx + dy;
            edges[idx] = e;
            if (e > maxEdge) maxEdge = e;
        }
    }
    if (maxEdge <= 0) return null;
    var STRONG_EDGE = enrollSpoofCfg.strongEdge;

    // 1) Bezel: scan each direction OUTWARD from the face box and find the
    // strongest edge line per side at ANY distance. A device screen is a bright
    // closed rectangle — all four sides have a strong edge line at a similar
    // distance from the face box (the screen is roughly centered on the face it
    // displays). Natural scenes almost never satisfy both conditions, so this
    // catches phones held at any distance without guessing ring scales.
    function lineCov(side, pos, from, to) {
        var s = 0, n = 0;
        if (side === 'top' || side === 'bottom') {
            if (pos < 1 || pos > H - 2) return 0;
            for (var c = from; c <= to; c++) { if (edges[pos * W + c] > STRONG_EDGE) s++; n++; }
        } else {
            if (pos < 1 || pos > W - 2) return 0;
            for (var rr = from; rr <= to; rr++) { if (edges[rr * W + pos] > STRONG_EDGE) s++; n++; }
        }
        return n ? (s / n) : 0;
    }
    var hR0 = Math.max(1, fx), hR1 = Math.min(W - 2, fx + fw);
    var vR0 = Math.max(1, fy), vR1 = Math.min(H - 2, fy + fh);
    function scanSide(side, startPos, endPos, step) {
        var best = 0, bestDist = 0;
        if (side === 'top' || side === 'bottom') {
            var faceLine = (side === 'top') ? fy : (fy + fh);
            for (var p = startPos; step > 0 ? (p <= endPos) : (p >= endPos); p += step) {
                var cov = lineCov(side, p, hR0, hR1);
                if (cov > best) { best = cov; bestDist = Math.abs(p - faceLine); }
            }
        } else {
            var faceLine2 = (side === 'left') ? fx : (fx + fw);
            for (var p = startPos; step > 0 ? (p <= endPos) : (p >= endPos); p += step) {
                var cov2 = lineCov(side, p, vR0, vR1);
                if (cov2 > best) { best = cov2; bestDist = Math.abs(p - faceLine2); }
            }
        }
        return { cov: best, dist: bestDist };
    }
    var t = scanSide('top', fy - 2, 1, -1);
    var b = scanSide('bottom', fy + fh + 2, H - 2, 1);
    var l = scanSide('left', fx - 2, 1, -1);
    var r = scanSide('right', fx + fw + 2, W - 2, 1);
    var minCov = Math.min(t.cov, b.cov, l.cov, r.cov);
    // Coherence: left/right and top/bottom edges roughly equidistant from the
    // face box => a real closed rectangle. Scattered scene edges fail this.
    var tolH = Math.max(6, Math.min(l.dist, r.dist) * 0.45);
    var tolV = Math.max(6, Math.min(t.dist, b.dist) * 0.45);
    var coherent = (Math.abs(l.dist - r.dist) <= tolH) && (Math.abs(t.dist - b.dist) <= tolV);
    var bezel = minCov * minCov * (coherent ? 1 : 0.35);

    // 2) Grid: density of strong fine edges inside the face box. Subpixel
    // screen texture / moire fills the face region; real faces have sparse
    // strong edges. 33%+ strong pixels => grid = 1.0.
    var strongN = 0, gridN = 0;
    var gx0 = Math.max(1, fx), gy0 = Math.max(1, fy);
    var gx1 = Math.min(W - 2, fx + fw), gy1 = Math.min(H - 2, fy + fh);
    for (var gy = gy0; gy <= gy1; gy++) {
        for (var gxx = gx0; gxx <= gx1; gxx++) {
            if (edges[gy * W + gxx] > STRONG_EDGE) strongN++;
            gridN++;
        }
    }
    var grid = gridN ? Math.min(1, (strongN / gridN) * 3) : 0;

    // 3) Flicker: normalized std of the face-region mean luminance history.
    // Screens flicker (refresh-rate beating vs webcam fps); real scenes do not.
    var faceMean = 0, fn = 0;
    for (var fyy = gy0; fyy <= gy1; fyy++) {
        for (var fxx = gx0; fxx <= gx1; fxx++) {
            faceMean += gray[fyy * W + fxx];
            fn++;
        }
    }
    faceMean = fn ? faceMean / fn : mean;
    enrollSpoofHist.push(faceMean);
    if (enrollSpoofHist.length > enrollSpoofCfg.flickerWindow) enrollSpoofHist.shift();
    var flicker = 0;
    if (enrollSpoofHist.length >= 6) {
        var hMean = 0;
        for (var hi = 0; hi < enrollSpoofHist.length; hi++) hMean += enrollSpoofHist[hi];
        hMean /= enrollSpoofHist.length;
        var hVar = 0;
        for (var hj = 0; hj < enrollSpoofHist.length; hj++) { var dv = enrollSpoofHist[hj] - hMean; hVar += dv * dv; }
        hVar /= enrollSpoofHist.length;
        var hStd = Math.sqrt(hVar);
        flicker = hMean > 0 ? Math.min(1, hStd / (Math.max(hMean, 60) * 0.10)) : 0;
    }

    var score = bezel * enrollSpoofCfg.bezelWeight
        + grid * enrollSpoofCfg.gridWeight
        + flicker * enrollSpoofCfg.flickerWeight;
    return { score: score, bezel: bezel, grid: grid, flicker: flicker };
}






function captureFaceEnroll() {
    var video = el('faceEnrollVideo');
    var canvas = el('faceEnrollCanvas');
    if (!video || !canvas) return;
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    var ctx = canvas.getContext('2d');
    faceEnrollSamples = [];
    faceEnrollSampleTarget = 5;
    if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'Look at the camera with your eyes open and blink twice.';
    if (el('faceEnrollCaptureBtn')) el('faceEnrollCaptureBtn').classList.add('d-none');
    if (el('faceEnrollSaveBtn')) el('faceEnrollSaveBtn').classList.add('d-none');
    if (el('faceEnrollPreview')) el('faceEnrollPreview').classList.add('d-none');
    hideEnrollSpoofAlert();
    var bb = el('faceEnrollBuildBadge'); if (bb) bb.textContent = 'eng b' + CTR_ENG_BUILD;

    var blinkCount = 0;
    var lastDescriptor = null;
    var lastEar = 0;
    var lastCanvas = null;
    var finished = false;
    var cooldown = false;
    var lastVideoTimeMs = -1;
    var collectingAfterBlink = false;

    // Ratio-based blink detection (rolling peak of open-eye EAR)
    var recentEARs = [];
    var PEAK_WINDOW = 30;
    var CLOSE_RATIO = 0.55;
    var OPEN_RATIO = 0.78;
    var ABS_OPEN = 0.20;
    var ABS_CLOSE = 0.15;
    var CLOSED_MIN_MS = 35;
    var CLOSED_MAX_MS = 1500;
    var ENROLL_TILT_MAX_DEG = 12; // reject heavily tilted heads (eye-line roll)

    // Instant blink counting: a natural blink (eyes close briefly then reopen)
    // is counted the moment it completes — no cue, no countdown, no waiting.
    var sessionStart = Date.now(); // stall guard: no completion in 60s stops the session

    // Blink state machine used by detectNaturalBlink (instant blink counting)
    var blinkState = 'scanning';
    var closedSince = 0;
    var closedFrames = 0;
    var openFrames = 0;
    var OPEN_FRAMES_NEEDED = 2;

    // Screen-presentation detection state
    var frameIndex = 0;
    var spoofEnabled = true;
    var spoofConsecutive = 0;
    var spoofBlocked = false;
    var screenDebug = 0;
    var lastTilt = 0; // tilt (degrees) of the most recent accepted frame — checked before Save
    var bezelWarnStreak = 0; // consecutive frames with high bezel but below block score
    var hardBezelStreak = 0; // consecutive frames with a strong closed rectangle (device edge)
    // Fresh flicker history per enrollment attempt (screens flicker, live faces do not)
    enrollSpoofHist.length = 0;

    function hideEnrollSpoofAlert() {
        var a = el('faceEnrollSpoofAlert');
        if (a) a.classList.add('d-none');
    }
    function showEnrollSpoofAlert(screen) {
        var a = el('faceEnrollSpoofAlert');
        if (!a) return;
        var t = el('faceEnrollSpoofText');
        if (t) {
            t.textContent = 'Screen detected — a face shown on a device screen (phone/tablet/monitor) cannot be enrolled. Please present your real face. (screen ' + screen.score.toFixed(2) + ')';
        }
        a.classList.remove('d-none');
    }

    // Detect one natural blink in the current frame; maintains internal state.
    function detectNaturalBlink(ear, closeT, openT, now) {
        if (ear <= closeT) {
            closedFrames++;
            if (blinkState === 'scanning' && closedFrames >= 1) {
                blinkState = 'eyes_closed';
                closedSince = now;
                openFrames = 0;
            }
            return false;
        }
        if (ear >= openT) {
            if (blinkState === 'eyes_closed') {
                openFrames++;
                if (openFrames >= OPEN_FRAMES_NEEDED) {
                    var closedFor = now - closedSince;
                    blinkState = 'scanning';
                    closedFrames = 0;
                    openFrames = 0;
                    return (closedFor >= CLOSED_MIN_MS && closedFor <= CLOSED_MAX_MS);
                }
            } else {
                closedFrames = 0;
                openFrames = 0;
            }
        } else {
            if (blinkState === 'eyes_closed' && (now - closedSince) > CLOSED_MAX_MS) {
                blinkState = 'scanning';
                closedFrames = 0;
                openFrames = 0;
            }
        }
        return false;
    }

    function startPostBlinkCollection(fpNow) {
        // Collect the remaining samples after the 2nd accepted blink. Samples
        // are only accepted while the eyes are OPEN so the averaged print
        // always comes from natural, open-eye frames.
        var localInterval = null;
        var localTimer = null;
        var localFinished = false;
        localInterval = setInterval(function () {
            if (!video.srcObject || finished || localFinished) { clearInterval(localInterval); return; }
            var fpUse = fpNow;
            var eyesOpen = false;
            var tilted2 = false;
            try {
                var res2 = faceEnrollLandmarker.detectForVideo(video, performance.now());
                if (res2 && res2.faceLandmarks && res2.faceLandmarks.length > 0) {
                    var lm2 = res2.faceLandmarks[0];
                    var q2 = checkFaceQuality(lm2);
                    if (q2.ok) {
                        var tilt2 = faceTiltDegMP(lm2);
                        tilted2 = tilt2 > ENROLL_TILT_MAX_DEG;
                        var ear2 = (eyeAspectRatioMP(lm2, MP_LEFT_EYE) + eyeAspectRatioMP(lm2, MP_RIGHT_EYE)) / 2;
                        eyesOpen = ear2 >= ABS_OPEN && !tilted2;
                        if (eyesOpen) fpUse = buildFaceprintMP(lm2);
                    }
                }
            } catch (e) {}
            if (eyesOpen && fpUse && faceEnrollSamples.length < faceEnrollSampleTarget) {
                var last = faceEnrollSamples[faceEnrollSamples.length - 1];
                var dist = 0;
                if (last) {
                    for (var k = 0; k < Math.min(fpUse.length, last.length); k++) {
                        var d = fpUse[k] - last[k];
                        dist += d * d;
                    }
                    dist = Math.sqrt(dist);
                }
                if (dist < 0.15) faceEnrollSamples.push(fpUse.slice());
            }
            if (el('faceEnrollMsg')) {
                el('faceEnrollMsg').textContent = tilted2
                    ? 'Face is tilted — keep your head straight... (' + faceEnrollSamples.length + '/' + faceEnrollSampleTarget + ')'
                    : eyesOpen
                        ? 'Collecting samples — keep your eyes open... (' + faceEnrollSamples.length + '/' + faceEnrollSampleTarget + ')'
                        : 'Eyes closed — open your eyes... (' + faceEnrollSamples.length + '/' + faceEnrollSampleTarget + ')';
            }
            if (faceEnrollSamples.length >= faceEnrollSampleTarget) {
                localFinished = true;
                clearInterval(localInterval);
                clearTimeout(localTimer);
                lastDescriptor = averageDescriptors(faceEnrollSamples);
                captureAndShowSave();
            }
        }, 250);
        localTimer = setTimeout(function () {
            if (localFinished) return;
            localFinished = true;
            clearInterval(localInterval);
            if (!finished) {
                lastDescriptor = averageDescriptors(faceEnrollSamples);
                captureAndShowSave();
            }
        }, 2500);
    }

    function tick() {
        if (finished || !video.srcObject) return;
        if (!faceEnrollLandmarker) { setTimeout(tick, 50); return; }
        ctx.drawImage(video, 0, 0);
        lastCanvas = canvas;
        var nowMs = performance.now();
        if (nowMs === lastVideoTimeMs) nowMs += 1;
        lastVideoTimeMs = nowMs;
        var mpResult;
        try {
            mpResult = faceEnrollLandmarker.detectForVideo(video, nowMs);
        } catch (e) {
            setTimeout(tick, 50);
            return;
        }
        if (!mpResult || !mpResult.faceLandmarks || mpResult.faceLandmarks.length === 0) {
            if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'Looking for face... Please face the camera.';
            setTimeout(tick, 20);
            return;
        }
        var landmarks = mpResult.faceLandmarks[0];
        var quality = checkFaceQuality(landmarks);
        if (!quality.ok) {
            if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = quality.reason;
            setTimeout(tick, 20);
            return;
        }
        var leftEAR = eyeAspectRatioMP(landmarks, MP_LEFT_EYE);
        var rightEAR = eyeAspectRatioMP(landmarks, MP_RIGHT_EYE);
        var ear = (leftEAR + rightEAR) / 2;
        lastEar = ear;
        var tilt = faceTiltDegMP(landmarks);
        lastTilt = tilt;
        var tilted = tilt > ENROLL_TILT_MAX_DEG;
        var fp = buildFaceprintMP(landmarks);
        if (fp) lastDescriptor = fp;

        // Rolling peak of EAR
        if (recentEARs.length >= PEAK_WINDOW) recentEARs.shift();
        recentEARs.push(ear);
        var peakEAR = 0;
        for (var pi = 0; pi < recentEARs.length; pi++) {
            if (recentEARs[pi] > peakEAR) peakEAR = recentEARs[pi];
        }
        var useRatio = recentEARs.length >= 8;
        var closeT = useRatio ? (peakEAR * CLOSE_RATIO) : ABS_CLOSE;
        var openT = useRatio ? (peakEAR * OPEN_RATIO) : ABS_OPEN;
        var now = Date.now();

        // Eye-open gated sampling: only capture while the eyes are open so the
        // enrolled print never comes from a squinting / closed-eyes frame.
        var eyesOpen = ear >= openT;
        // Tilt gate: never sample a frame where the head is tilted, so the
        // averaged print only comes from level, straight-on frames.
        if (fp && eyesOpen && !tilted && !collectingAfterBlink && blinkCount < 2) {
            if (faceEnrollSamples.length < 2 && !cooldown) {
                faceEnrollSamples.push(fp.slice());
            }
        }

        // Screen-presentation detection (every Nth frame)
        frameIndex++;
        if (spoofEnabled && (frameIndex % enrollSpoofCfg.everyNFrames === 0)) {
            var screen = analyzeEnrollScreen(canvas, landmarks);
            if (screen) {
                screenDebug = screen.score;
                if (screen.score >= enrollSpoofCfg.blockScore) {
                    spoofConsecutive++;
                } else {
                    spoofConsecutive = Math.max(0, spoofConsecutive - 1);
                }
                if (screen.bezel >= 0.5) { bezelWarnStreak++; } else { bezelWarnStreak = Math.max(0, bezelWarnStreak - 1); }
                // Hard rule: a strong closed rectangle around the face is almost
                // certainly a device screen edge — block after just 2 frames.
                if (screen.bezel >= 0.60) { hardBezelStreak++; } else { hardBezelStreak = Math.max(0, hardBezelStreak - 1); }
                if (hardBezelStreak >= 2) {
                    spoofBlocked = true;
                    showEnrollSpoofAlert(screen);
                } else if (spoofConsecutive >= enrollSpoofCfg.consecutive) {
                    spoofBlocked = true;
                    showEnrollSpoofAlert(screen);
                } else if (spoofConsecutive === 0 && spoofBlocked) {
                    spoofBlocked = false;
                    hideEnrollSpoofAlert();
    var bb = el('faceEnrollBuildBadge'); if (bb) bb.textContent = 'eng b' + CTR_ENG_BUILD;
                }
            }
        }

        var earPct = Math.round(ear * 100);
        var eyeLabel = eyesOpen
            ? '<span class="text-success fw-semibold">Eyes open</span>'
            : '<span class="text-danger fw-semibold">Eyes closed — open your eyes to continue</span>';
        if (el('faceEnrollMsg')) {
            var enrollWarn = (!spoofBlocked && bezelWarnStreak >= 6)
                ? '<span class="text-warning fw-semibold"><i class="feather-alert-triangle me-1"></i>We still see what looks like a screen — please present your real face.</span>'
                : null;
            if (tilted) {
                el('faceEnrollMsg').innerHTML = '<span class="text-warning fw-semibold"><i class="feather-alert-triangle me-1"></i>Face is tilted (' + Math.round(tilt) + '\u00b0) — keep your head straight</span>';
            } else if (enrollWarn) {
                el('faceEnrollMsg').innerHTML = enrollWarn;
            } else if (spoofBlocked) {
                el('faceEnrollMsg').innerHTML = '<span class="text-danger fw-semibold"><i class="feather-alert-triangle me-1"></i>Screen detected — showing a face on a device screen is not allowed.</span>';
            } else {
                el('faceEnrollMsg').innerHTML = 'EAR: ' + ear.toFixed(3) + ' (' + earPct + '%) · ' + eyeLabel + ' · Blinks: <strong>' + blinkCount + '/2</strong>' + (spoofEnabled ? ' · screen ' + screenDebug.toFixed(2) + ' · b' + CTR_ENG_BUILD : '');
            }
        }

        // Stall guard (also covers the spoof-blocked freeze, where a phone left
        // in front of the camera would otherwise poll forever): stop the 50fps
        // loop after 60s without completing.
        if (blinkCount < 2 && now - sessionStart > 60000) {
            finished = true;
            hideEnrollSpoofAlert();
    var bb = el('faceEnrollBuildBadge'); if (bb) bb.textContent = 'eng b' + CTR_ENG_BUILD;
            if (el('faceEnrollMsg')) el('faceEnrollMsg').innerHTML = '<span class="text-danger fw-semibold">Enrollment session timed out after 60 seconds. Close this window and click Re-enroll Face to try again.</span>';
            return;
        }
        if (spoofBlocked) {
            // Freeze the challenge while a screen is present.
            setTimeout(tick, 20);
            return;
        }


        // Instant blink detection: the moment a natural blink completes (eyes
        // close briefly, then reopen) it is counted and announced right away —
        // no hold/cue/window waiting, no "BLINK NOW!" prompt.
        if (!cooldown) {
            var blinkDone = detectNaturalBlink(ear, closeT, openT, now);
            if (blinkDone) {
                blinkCount++;
                cooldown = true;
                if (el('faceEnrollMsg')) {
                    el('faceEnrollMsg').innerHTML = '<span class="text-success fw-semibold" style="font-size:1.1em;">Blink ' + blinkCount + ' detected!</span>' + (blinkCount < 2 ? ' Blink once more...' : '');
                }
                if (blinkCount >= 2) {
                    if (fp) faceEnrollSamples.push(fp.slice());
                    collectingAfterBlink = true;
                    startPostBlinkCollection(fp);
                    return;
                }
                setTimeout(function () { cooldown = false; }, 450);
            }
        }
        setTimeout(tick, 20);
    }

    function captureAndShowSave() {
        finished = true;
        // Tilt check on the exact frame shown in the preview: a tilted photo
        // must never be saved — ask the user to straighten their head and retry.
        if (lastTilt > ENROLL_TILT_MAX_DEG) {
            if (el('faceEnrollMsg')) el('faceEnrollMsg').innerHTML = '<span class="text-danger fw-semibold"><i class="feather-alert-triangle me-1"></i>Face is tilted — try again. Keep your head straight.</span>';
            if (el('faceEnrollPreview')) el('faceEnrollPreview').classList.add('d-none');
            showRetryButton();
            return;
        }
        faceEnrollCapturedDescriptor = lastDescriptor ? lastDescriptor.slice() : null;
        faceEnrollLastCanvas = lastCanvas;
        faceEnrollCapturedEmbedding = null;
        var previewCanvas = document.createElement('canvas');
        previewCanvas.width = 240;
        previewCanvas.height = 180;
        var pCtx = previewCanvas.getContext('2d');
        pCtx.drawImage(lastCanvas, 0, 0, lastCanvas.width, lastCanvas.height, 0, 0, 240, 180);
        faceEnrollCapturedImage = previewCanvas.toDataURL('image/jpeg', 0.85);
        if (el('faceEnrollPreviewImg')) el('faceEnrollPreviewImg').src = faceEnrollCapturedImage;
        if (el('faceEnrollPreview')) el('faceEnrollPreview').classList.remove('d-none');
        if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'Building face embedding...';
        function showSaveUI(emb) {
            faceEnrollCapturedEmbedding = emb;
            if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'Face captured! Click Save to enroll.';
            if (el('faceEnrollPreviewLabel')) {
                var sampleCount = faceEnrollSamples ? faceEnrollSamples.length : 1;
                el('faceEnrollPreviewLabel').textContent = sampleCount + ' face samples averaged for accuracy.';
                el('faceEnrollPreviewLabel').className = 'mt-2 text-success small fw-semibold';
            }
            if (el('faceEnrollCaptureBtn')) el('faceEnrollCaptureBtn').classList.add('d-none');
            if (el('faceEnrollSaveBtn')) {
                el('faceEnrollSaveBtn').classList.remove('d-none');
                el('faceEnrollSaveBtn').disabled = false;
            }
            stopFaceEnrollCamera();
        }
        embedCanvas(lastCanvas).then(showSaveUI).catch(function () { showSaveUI(null); });
    }
    function saveFaceEnrollData() {
        if (!faceEnrollUser) {
            if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'No user selected.';
            return;
        }
        if (!faceEnrollCapturedDescriptor) {
            if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'No face data captured. Please click Capture first.';
            return;
        }
        if (!faceEnrollCapturedImage) {
            if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'No face image captured. Please click Capture first.';
            return;
        }
        if (!faceEnrollCapturedEmbedding || faceEnrollCapturedEmbedding.length < 128) {
            if (el('faceEnrollMsg')) el('faceEnrollMsg').innerHTML = 'Could not build the 128-dim face embedding (recognition model unavailable). Check your internet connection and click Capture to try again.';
            var saveBtn0 = el('faceEnrollSaveBtn');
            if (saveBtn0) { saveBtn0.disabled = false; saveBtn0.innerHTML = ' Save'; }
            return;
        }
        // Final tilt guard: never save a tilted capture, even if the Save UI was reached.
        if (lastTilt > ENROLL_TILT_MAX_DEG) {
            if (el('faceEnrollMsg')) el('faceEnrollMsg').innerHTML = '<span class="text-danger fw-semibold"><i class="feather-alert-triangle me-1"></i>Face is tilted — try again.</span>';
            return;
        }
        var saveBtn = el('faceEnrollSaveBtn');
        if (saveBtn) saveBtn.disabled = true;
        if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'Saving face data...';
        var fd = new FormData();
        fd.append('id', faceEnrollUser.id);
        fd.append('face_data', JSON.stringify(buildFacePayload(faceEnrollCapturedDescriptor, faceEnrollCapturedEmbedding)));
        fd.append('face_image', faceEnrollCapturedImage);
        fetch(apiBase + '&action=face_enroll', { method: 'POST', body: fd, cache: 'no-store' })
        .then(function (r) {
            return r.text().then(function (text) {
                var body = null;
                try { body = text ? JSON.parse(text) : null; } catch (e) { body = { ok: false, message: text || 'Invalid server response' }; }
                return { status: r.status, body: body };
            });
        })
        .then(function (result) {
            var res = result.body || {};
            if (result.status >= 200 && result.status < 300 && res.ok) {
                // The photo is mandatory — if the server could not store it,
                // treat the enrollment as failed so the user retries now
                // instead of discovering a half-enrolled "Re-enroll Needed"
                // user later (the DB row was not updated in that case).
                if (!res.face_image_path) {
                    if (el('faceEnrollMsg')) el('faceEnrollMsg').innerHTML = '<span class="text-danger fw-semibold">The face photo could not be stored on the server, so the enrollment was not saved. Please capture again.</span>';
                    if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = ' Save'; }
                    return;
                }
                for (var i = 0; i < users.length; i++) {
                    if (String(users[i].id) === String(faceEnrollUser.id)) {
                        users[i].has_face_data = 1;
                        users[i].face_kind = 'embed';
                        break;
                    }
                }
                renderTable();
                refreshUsers().catch(function () {});
                if (enrolledFacesTimer) refreshEnrolledFaces();
                if (el('faceEnrollMsg')) el('faceEnrollMsg').innerHTML = 'Face enrolled successfully!';
                if (el('faceEnrollPreviewLabel')) {
                    el('faceEnrollPreviewLabel').textContent = 'Enrolled successfully.';
                    el('faceEnrollPreviewLabel').className = 'mt-2 text-success small fw-semibold';
                }
                if (saveBtn) saveBtn.classList.add('d-none');
                setTimeout(function () {
                    if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('faceEnrollModal')).hide();
                    if (window.Swal) Swal.fire({ icon: 'success', title: 'Face Enrolled', timer: 1200, showConfirmButton: false });
                }, 500);
            } else if (result.status === 409) {
                var dupName = res.duplicate_user_name || 'another user';
                var dupUser = res.duplicate_username || '';
                if (el('faceEnrollMsg')) el('faceEnrollMsg').innerHTML = 'Duplicate! Already enrolled for ' + escapeHtml(dupName) + ' (' + escapeHtml(dupUser) + ').';
                if (el('faceEnrollPreviewLabel')) {
                    el('faceEnrollPreviewLabel').textContent = 'Duplicate face.';
                    el('faceEnrollPreviewLabel').className = 'mt-2 text-danger small fw-semibold';
                }
                if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = ' Save'; }
            } else {
                var errMsg = res && res.message ? res.message : ('Server returned ' + result.status);
                if (el('faceEnrollMsg')) el('faceEnrollMsg').innerHTML = 'Failed to enroll: ' + escapeHtml(errMsg) + '';
                if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = ' Save'; }
            }
        })
        .catch(function (err) {
            console.error('Save face enroll error:', err);
            if (el('faceEnrollMsg')) el('faceEnrollMsg').innerHTML = 'Network error: ' + escapeHtml((err && err.message) || 'Could not reach the server') + '. Click Save to try again.';
            if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = ' Save'; }
        });
    }
    faceEnrollDoSave = saveFaceEnrollData;
    function showRetryButton() {
        if (el('faceEnrollCaptureBtn')) {
            el('faceEnrollCaptureBtn').classList.remove('d-none');
            el('faceEnrollCaptureBtn').disabled = false;
            el('faceEnrollCaptureBtn').innerHTML = ' Retry';
            el('faceEnrollCaptureBtn').setAttribute('data-retry', '1');
        }
    }
    tick();
}

function deleteFaceEnroll() {
            if (!faceEnrollUser) return;
            var id = faceEnrollUser.id;
            var fd = new FormData();
            fd.append('id', id);
            fetch(apiBase + '&action=face_enroll_delete', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) {
                        for (var i = 0; i < users.length; i++) {
                            if (String(users[i].id) === String(id)) {
                                users[i].has_face_data = 0;
                                users[i].face_kind = '';
                                break;
                            }
                        }
                        renderTable();
                        refreshUsers().catch(function () {});
                        if (enrolledFacesTimer) refreshEnrolledFaces();
                        if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('faceEnrollModal')).hide();
                        if (window.Swal) Swal.fire({ icon: 'success', title: 'Face Data Removed', timer: 1500, showConfirmButton: false });
                    } else {
                        var msg = (res && res.message) ? res.message : 'Failed to remove face data.';
                        if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = msg;
                    }
                }).catch(function (err) {
                    if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'Network error. Please try again.';
                });
        }

        function renderEnrolledFaces(list) {
            var grid = el('enrolledFacesGrid');
            if (!grid) return;
            var arr = Array.isArray(list) ? list : [];
            if (el('enrolledFacesCount')) el('enrolledFacesCount').textContent = arr.length + ' enrolled';
            if (el('enrolledFacesUpdatedAt')) {
                var now = new Date();
                var hh = now.getHours();
                var mm = String(now.getMinutes()).padStart(2, '0');
                var ss = String(now.getSeconds()).padStart(2, '0');
                var ap = hh >= 12 ? 'PM' : 'AM';
                hh = hh % 12 || 12;
                el('enrolledFacesUpdatedAt').innerHTML = '<i class="feather-check-circle" style="font-size:12px;color:#17c666;"></i>Updated ' + hh + ':' + mm + ':' + ss + ' ' + ap + ' · auto-refreshes every 10s';
            }
            if (!arr.length) {
                grid.innerHTML = '<div class="text-center text-muted py-4">No enrolled faces found.</div>';
                return;
            }
            var html = '';
            for (var i = 0; i < arr.length; i++) {
                var u = arr[i];
                var img = u.face_image || '';
                var name = escapeHtml(u.name || '');
                var uname = escapeHtml(u.username || '');
                var dept = escapeHtml(u.department || '');
                var pos = escapeHtml(u.position || '');
                html += ''
                    + '<div class="col-sm-6 col-md-4 col-lg-3 mb-3">'
                    + '  <div class="card h-100 border-0 shadow-sm text-center" style="border-radius:12px;overflow:hidden;">'
                    + '    <div class="card-body p-3 d-flex flex-column align-items-center">'
                    + (img
                        ? '      <img data-face-img="1" src="' + img + '" alt="' + name + '" title="' + name + '" style="width:120px;height:120px;object-fit:cover;border-radius:50%;border:3px solid #17c666;margin-bottom:12px;">'
                        : '      <div style="width:120px;height:120px;border-radius:50%;background:#e9ecef;display:flex;align-items:center;justify-content:center;margin-bottom:12px;"><i class="feather-user" style="font-size:40px;color:#adb5bd;"></i></div>')
                    + '      <div class="fw-bold" style="font-size:14px;">' + name + '</div>'
                    + '      <div class="text-muted" style="font-size:12px;">@' + uname + '</div>'
                    + (dept ? '      <div class="text-muted mt-1" style="font-size:12px;">' + dept + '</div>' : '')
                    + (pos ? '      <div class="text-muted" style="font-size:12px;">' + pos + '</div>' : '')
                    + (sessionRole === 'superadmin'
                        ? '      <button type="button" class="btn btn-sm btn-outline-danger mt-2" data-action="delete-face" data-id="' + escapeHtml(u.id) + '" data-user-name="' + name + '"><i class="feather-trash-2 me-1"></i>Remove Face</button>'
                        : '')
                    + '    </div>'
                    + '  </div>'
                    + '</div>';
            }
            grid.innerHTML = html;
            // If a stored photo fails to load (file missing/corrupt on disk),
            // swap in the placeholder icon so a broken image never appears —
            // the user immediately sees there is no usable picture and can
            // re-enroll.
            var faceImgs = grid.querySelectorAll('img[data-face-img="1"]');
            for (var k = 0; k < faceImgs.length; k++) {
                (function (img) {
                    img.addEventListener('error', function () {
                        if (img.getAttribute('data-face-failed')) return;
                        img.setAttribute('data-face-failed', '1');
                        var ph = document.createElement('div');
                        ph.setAttribute('title', 'No stored photo — re-enroll');
                        ph.style.cssText = 'width:120px;height:120px;border-radius:50%;background:#e9ecef;display:flex;align-items:center;justify-content:center;margin-bottom:12px;';
                        ph.innerHTML = '<i class="feather-user" style="font-size:40px;color:#adb5bd;"></i>';
                        img.parentNode.replaceChild(ph, img);
                        // Visible cue so it is obvious this face has no usable
                        // stored photo and needs to be re-enrolled.
                        var badge = document.createElement('span');
                        badge.className = 'badge bg-soft-danger text-danger fw-semibold';
                        badge.style.cssText = 'font-size:10px;margin-top:6px;';
                        badge.textContent = 'No photo — Re-enroll';
                        var cardBody = ph.parentNode;
                        var nameDiv = cardBody ? cardBody.querySelector('.fw-bold') : null;
                        if (nameDiv && nameDiv.parentNode) {
                            nameDiv.parentNode.insertBefore(badge, nameDiv.nextSibling);
                        }
                    });
                })(faceImgs[k]);
            }
        }

        function refreshEnrolledFaces() {
            var grid = el('enrolledFacesGrid');
            var btn = el('enrolledFacesRefreshBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Refreshing...';
            }
            return fetch(apiBase + '&action=list_enrolled_faces', { method: 'GET', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok || !res.users) {
                        if (grid) grid.innerHTML = '<div class="text-center text-muted py-4">Failed to load enrolled faces.</div>';
                        return;
                    }
                    renderEnrolledFaces(res.users);
                })
                .catch(function () {
                    if (grid) grid.innerHTML = '<div class="text-center text-muted py-4">Network error. Please try again.</div>';
                })
                .finally(function () {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="feather-refresh-cw me-1"></i> Refresh';
                    }
                });
        }

        function stopEnrolledFacesLive() {
            if (enrolledFacesTimer) window.clearInterval(enrolledFacesTimer);
            enrolledFacesTimer = null;
        }

        function openEnrolledFacesModal() {
            if (!el('enrolledFacesModal')) return;
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('enrolledFacesModal')).show();
            refreshEnrolledFaces();
            stopEnrolledFacesLive();
            enrolledFacesTimer = window.setInterval(function () {
                refreshEnrolledFaces();
            }, 10000);
        }

        function showDeleteModal(u) {
            if (!el('confirmDeleteModal')) return;
            pendingDeleteId = u ? u.id : null;
            if (el('deleteLabel')) el('deleteLabel').textContent = u ? ((u.name || '') + ' (' + (u.username || '') + ')') : '';
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('confirmDeleteModal')).show();
        }

        function initHandlers() {
            bindOnce(el('addUserBtn'), 'click', 'add', function () { openUserModal('add', null); });
            bindOnce(el('refreshUsersBtn'), 'click', 'refresh', function () { refreshUsers().catch(function () {}); });
            bindOnce(el('togglePasswordBtn'), 'click', 'togglepwd', function () {
                var inp = el('passwordInput');
                if (!inp) return;
                var isPassword = inp.type === 'password';
                inp.type = isPassword ? 'text' : 'password';
                var icon = this.querySelector('i');
                if (icon) {
                    if (isPassword) {
                        icon.className = 'feather feather-eye-off';
                    } else {
                        icon.className = 'feather feather-eye';
                    }
                }
            });
            bindOnce(el('usernameInput'), 'input', 'username', validateLive);
            bindOnce(el('idNumberInput'), 'input', 'idnumber', validateLive);
            bindOnce(el('bulkIdTemplateBtn'), 'click', 'bulkidtpl', function () { openIdTemplateModal(selectedIdsOrdered()); });
            bindOnce(el('downloadUserTemplateBtn'), 'click', 'dlut', function () {
                var a = document.createElement('a');
                a.href = pageUrl('users') + '?download_user_template=1';
                document.body.appendChild(a);
                a.click();
                a.remove();
            });
            bindOnce(el('uploadUserTemplateBtn'), 'click', 'ulutopen', function () { openUploadUserTemplateModal(); });
            bindOnce(el('uploadUserTemplateFile'), 'change', 'ulutfile', function (ev) { onUploadUserTemplateFileChange(ev); });
            bindOnce(el('confirmUploadUserTemplateBtn'), 'click', 'ulutconfirm', function () { confirmUploadUserTemplate(); });
            bindOnce(el('faceEnrollCaptureBtn'), 'click', 'facecapture', function () {
                var btn = el('faceEnrollCaptureBtn');
                // If retry was triggered, restart the detection loop
                if (btn && btn.getAttribute('data-retry') === '1') {
                    btn.removeAttribute('data-retry');
                    btn.classList.add('d-none');
                    if (el('faceEnrollPreview')) el('faceEnrollPreview').classList.add('d-none');
                    if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'Starting camera...';
                    if (faceEnrollStream) {
                        var video = el('faceEnrollVideo');
                        if (video && !video.srcObject) {
                            video.srcObject = faceEnrollStream;
                            video.play().catch(function () {});
                        }
                        captureFaceEnroll();
                    } else {
                        startFaceEnrollCamera().then(function () {
                            captureFaceEnroll();
                        }).catch(function () {
                            if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'Failed to restart camera.';
                        });
                    }
                    return;
                }
                return;
            });
            bindOnce(el('faceEnrollSaveBtn'), 'click', 'facesave', function () {
                if (typeof faceEnrollDoSave === 'function') {
                    faceEnrollDoSave();
                    return;
                }
                if (el('faceEnrollMsg')) el('faceEnrollMsg').textContent = 'Please capture a face first.';
            });
            bindOnce(el('faceEnrollDeleteBtn'), 'click', 'facedelete', function () { deleteFaceEnroll(); });
            bindOnce(el('viewEnrolledFacesBtn'), 'click', 'viewenrolledfaces', function () { openEnrolledFacesModal(); });
            bindOnce(el('enrolledFacesRefreshBtn'), 'click', 'enrolledfacesrefresh', function () { refreshEnrolledFaces(); });
            bindOnce(el('enrolledFacesModal'), 'hidden.bs.modal', 'enrolledfaceshidden', function () { stopEnrolledFacesLive(); });
            bindOnce(el('enrolledFacesGrid'), 'click', 'enrolledfacesdelete', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('[data-action="delete-face"]') : null;
                if (!btn) return;
                e.preventDefault();
                var id = btn.getAttribute('data-id');
                var userName = btn.getAttribute('data-user-name') || 'this user';
                function doDelete() {
                    postForm('face_enroll_delete', { id: id }).then(function (res) {
                        if (res.data && res.data.ok === true) {
                            for (var i = 0; i < users.length; i++) {
                                if (String(users[i].id) === String(id)) {
                                    users[i].has_face_data = 0;
                                    users[i].face_kind = '';
                                    break;
                                }
                            }
                            renderTable();
                            refreshUsers().catch(function () {});
                            if (enrolledFacesTimer) refreshEnrolledFaces();
                            if (window.Swal) Swal.fire({ icon: 'success', title: 'Face Removed', timer: 1500, showConfirmButton: false });
                        } else {
                            var msg = (res.data && res.data.message) ? res.data.message : 'Failed to remove face.';
                            if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                        }
                    }).catch(function () {
                        if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: 'Network error.' });
                    });
                }
                if (window.Swal) {
                    Swal.fire({
                        title: 'Remove enrolled face?',
                        text: 'Remove the enrolled face for ' + userName + '? This cannot be undone.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Remove',
                        cancelButtonText: 'Cancel'
                    }).then(function (result) {
                        if (result && result.value) doDelete();
                    });
                } else if (window.confirm) {
                    if (confirm('Remove the enrolled face for ' + userName + '?')) doDelete();
                }
            });
            bindOnce(el('faceDiagBtn'), 'click', 'facediagopen', function () { openFaceDiagModal(); });
            bindOnce(el('faceDiagCaptureBtn'), 'click', 'facediagcap', function () {
                if (faceDiag.busy || faceDiag.starting) return;
                if (!faceDiag.stream) {
                    faceDiag.starting = true;
                    loadMediaPipeFaceLandmarker().then(function () {
                        return faceDiagStartCamera();
                    }).then(function () {
                        faceDiag.starting = false;
                        faceDiagCapture();
                    }).catch(function (err) {
                        faceDiag.starting = false;
                        var capBtn = el('faceDiagCaptureBtn');
                        if (capBtn) capBtn.disabled = false;
                        if (el('faceDiagMsg')) el('faceDiagMsg').textContent = 'Failed to start camera: ' + ((err && err.message) || 'unknown');
                    });
                } else {
                    faceDiagCapture();
                }
            });
            bindOnce(el('faceDiagClearBtn'), 'click', 'facediagclear', function () { clearFaceDiagData(); });
            var faceDiagModalEl = el('faceDiagModal');
            if (faceDiagModalEl) {
                faceDiagModalEl.addEventListener('hidden.bs.modal', function () {
                    faceDiagStopCamera();
                    faceDiag.busy = false;
                    faceDiag.submitted = false;
                    faceDiag.starting = false;
                    var capBtn = el('faceDiagCaptureBtn');
                    if (capBtn) capBtn.disabled = false;
                });
            }
            var faceEnrollModal = el('faceEnrollModal');
            if (faceEnrollModal) {
                faceEnrollModal.addEventListener('hidden.bs.modal', function () {
                    stopFaceEnrollCamera();
                    faceEnrollDoSave = null;
                });
            }
            bindOnce(el('usersSelectAll'), 'change', 'usersselectall', function () {
                var on = !!this.checked;
                selectedUserIds = {};
                if (on) {
                    // If DataTables is active with a search filter, only select filtered rows
                    if (dt && typeof dt.search === 'function' && dt.search()) {
                        var filteredData = dt.rows({ search: 'applied' }).data();
                        for (var fi = 0; fi < filteredData.length; fi++) {
                            var m = String(filteredData[fi][0] || '').match(/data-id="(\d+)"/);
                            if (m) selectedUserIds[m[1]] = true;
                        }
                    } else {
                        for (var i = 0; i < users.length; i++) {
                            if (users[i] && users[i].id != null) selectedUserIds[String(users[i].id)] = true;
                        }
                    }
                }
                renderTable();
                updateSelectedCountUi();
                syncSelectAllUi();
            });
            bindOnce(el('confirmIdTemplateBtn'), 'click', 'confirmidtpl', function () {
                if (!pendingIdTemplateIds || !pendingIdTemplateIds.length) return;
                var checked = document.querySelector('input[name="id_template_type"]:checked');
                var tpl = checked && checked.value ? String(checked.value) : 'cos_jo';
                var ids = pendingIdTemplateIds.slice(0);
                var a = document.createElement('a');
                a.href = pageUrl('users') + '?download_id_template=1&template=' + encodeURIComponent(tpl) + '&ids=' + encodeURIComponent(ids.join(','));
                document.body.appendChild(a);
                a.click();
                a.remove();
                if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('idTemplateModal')).hide();
            });

            bindOnce(el('confirmPlanBtn'), 'click', 'confirmplan', function () {
                var id = el('planUserId') ? (el('planUserId').value || '') : '';
                if (!id) return;
                var activePane = document.querySelector('#planModal .tab-pane.active');
                var isCustom = activePane && activePane.id === 'customPlanPane';
                var payload = null;
                if (isCustom) {
                    var startDate = el('planStartDateInput') ? el('planStartDateInput').value : '';
                    var endDate = el('planEndDateInput') ? el('planEndDateInput').value : '';
                    if (!startDate || !endDate) {
                        if (window.Swal) Swal.fire({ icon: 'warning', title: 'Missing dates', text: 'Please choose both a start date and an expiration date.' });
                        return;
                    }
                    if (endDate < startDate) {
                        if (window.Swal) Swal.fire({ icon: 'warning', title: 'Invalid dates', text: 'Expiration date must be on or after the start date.' });
                        return;
                    }
                    payload = { id: id, start_date: startDate, end_date: endDate };
                } else {
                    var months = el('planMonthsModalInput') ? (el('planMonthsModalInput').value || '1') : '1';
                    payload = { id: id, plan_months: months };
                }
                postForm('set_admin_plan', payload).then(function (res) {
                    if (!res.data || res.data.ok !== true) {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Request failed.';
                        if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                        return;
                    }
                    pendingPlanUserId = null;
                    if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('planModal')).hide();
                    refreshUsers().catch(function () {});
                    if (window.Swal) Swal.fire({ icon: 'success', title: isCustom ? 'Custom Plan Saved' : 'Plan Activated', timer: 1200, showConfirmButton: false });
                });
            });

            bindOnce(el('planStartDateInput'), 'input', 'planstartdate', function () { updateCustomPlanRangeHint(); });
            bindOnce(el('planEndDateInput'), 'input', 'planenddate', function () { updateCustomPlanRangeHint(); });

            var form = el('userForm');
            bindOnce(form, 'submit', 'submit', function (e) {
                e.preventDefault();
                var modal = el('userModal');
                function inputVal(id, name) {
                    var node = modal && modal.querySelector ? modal.querySelector('#' + id) : null;
                    if (!node) node = el(id);
                    var v = node && node.value !== undefined ? node.value : '';
                    v = String(v || '');
                    if (v.trim() !== '') return v;
                    if (form && window.FormData && name) {
                        try {
                            var fd2 = new FormData(form);
                            var vv = fd2.get(name);
                            return String(vv == null ? '' : vv);
                        } catch (err) {}
                    }
                    if (form && name) {
                        var byName = form.querySelector ? form.querySelector('[name="' + name + '"]') : null;
                        if (byName && byName.value !== undefined) return String(byName.value || '');
                    }
                    return v;
                }
                var id = el('userId').value;
                var adminDeptUnlocked = (sessionRole === 'admin' && myIdentity) && (String(myIdentity.admin_department_unlocked) === '1' || myIdentity.admin_department_unlocked === 1 || myIdentity.admin_department_unlocked === true);
                var forcedDepartment = (!adminDeptUnlocked && sessionRole === 'admin' && myIdentity && myIdentity.department) ? String(myIdentity.department) : '';
                var departmentValue = forcedDepartment || inputVal('departmentInput', 'department').trim();
                var payload = {
                    id: id,
                    username: inputVal('usernameInput', 'username').trim(),
                    password: inputVal('passwordInput', 'password'),
                    id_number: inputVal('idNumberInput', 'id_number').trim(),
                    name: inputVal('realNameInput', 'name').trim(),
                    role: inputVal('roleInput', 'role').trim(),
                    assigned_admin_id: inputVal('assignedAdminInput', 'assigned_admin_id').trim(),
                    position: inputVal('positionInput', 'position').trim(),
                    department: departmentValue,
                    plan_months: inputVal('planMonthsInput', 'plan_months').trim(),
                    is_active: el('isActiveInput').checked ? 1 : 0
                };
                var action = id ? 'update_user' : 'create_user';

                var missing = [];
                if (!payload.username) missing.push('Username');
                if (!payload.id_number) missing.push('ID Number');
                if (!payload.name) missing.push('Real Name');
                if (!payload.role) missing.push('Role');
                if (sessionRole === 'superadmin' && payload.role === 'user' && !payload.assigned_admin_id) missing.push('Assigned Admin');
                if (!payload.position) missing.push('Job title');
                if (!payload.department) missing.push('Department');

                if (missing.length) {
                    var first = missing[0];
                    var focusMap = {
                        'Username': 'usernameInput',
                        'ID Number': 'idNumberInput',
                        'Real Name': 'realNameInput',
                        'Role': 'roleInput',
                        'Job title': 'positionInput',
                        'Department': 'departmentInput',
                        'Password': 'passwordInput'
                    };
                    var focusId = focusMap[first];
                    if (focusId && el(focusId)) el(focusId).focus();
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: 'Missing: ' + missing.join(', ') });
                    return;
                }

                postForm(action, payload).then(function (res) {
                    if (!res.data || res.data.ok !== true) {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Request failed.';
                        if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                        return;
                    }
                    if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('userModal')).hide();
                    refreshUsers().then(function () {
                        if (!id) {
                            var created = null;
                            for (var i = 0; i < users.length; i++) {
                                if (String(users[i].id) === String(res.data.id)) {
                                    created = users[i];
                                    break;
                                }
                            }
                            if (created) openQrModal(created);
                        }
                    }).catch(function () {});
                    if (window.Swal) Swal.fire({ icon: 'success', title: 'Saved', timer: 1200, showConfirmButton: false });
                });
            });

            var table = el('usersTable');
            bindOnce(table, 'click', 'table', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
                if (!btn) return;
                var action = btn.getAttribute('data-action');
                var id = btn.getAttribute('data-id');
                var u = userById(id);
                if (action === 'edit' && u) return openUserModal('edit', u);
                if (action === 'qr' && u) return openQrModal(u);
                if (action === 'idtemplate' && u) return openIdTemplateModal([String(u.id)]);
                if (action === 'plan' && u) return openPlanModal(u);
                if (action === 'viewusers' && u) return openAdminUsersModal(u);
                if (action === 'homelinks' && u) return openAdminHomeLinksModal(u);
                if (action === 'deptlock' && u) {
                    var nextUnlocked = btn.getAttribute('data-unlocked');
                    btn.disabled = true;
                    return postForm('set_admin_department_unlocked', { id: id, unlocked: nextUnlocked }).then(function (res) {
                        if (!res.data || res.data.ok !== true) {
                            var msg = (res.data && res.data.message) ? res.data.message : 'Request failed.';
                            if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                            return;
                        }
                        for (var i = 0; i < users.length; i++) {
                            if (users[i] && String(users[i].id) === String(id)) {
                                users[i].admin_department_unlocked = String(nextUnlocked) === '1' ? 1 : 0;
                                break;
                            }
                        }
                        renderTable();
                        refreshUsers().catch(function () {});
                        if (window.Swal) Swal.fire({ icon: 'success', title: 'Updated', timer: 900, showConfirmButton: false });
                    }).finally(function () {
                        try { btn.disabled = false; } catch (e2) {}
                    });
                }
                if (action === 'toggle') {
                    var active = btn.getAttribute('data-active');
                    return postForm('toggle_active', { id: id, is_active: active }).then(function (res) {
                        if (res.data && res.data.ok === true) return refreshUsers().catch(function () {});
                    });
                }
                if (action === 'face_enroll' && u) return openFaceEnrollModal(u);
                if (action === 'user_schedule' && u) return openUserScheduleModal(u);
                if (action === 'delete' && u) return showDeleteModal(u);
            });

            var adminUsersTable = el('adminUsersTable');
            bindOnce(adminUsersTable, 'click', 'adminusers', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('button[data-admin-users-action]') : null;
                if (!btn) return;
                var action = btn.getAttribute('data-admin-users-action');
                var id = btn.getAttribute('data-id');
                var u = null;
                for (var i = 0; i < adminUsers.length; i++) {
                    if (String(adminUsers[i].id) === String(id)) { u = adminUsers[i]; break; }
                }
                if (!u) return;
                if (action === 'edit') return openUserModal('edit', u);
                if (action === 'delete') return showDeleteModal(u);
                if (action === 'qr') return openAdminUserQrModal(u);
            });

            bindOnce(table, 'change', 'tablesel', function (e) {
                var cb = e.target && e.target.closest ? e.target.closest('input.users-check[data-id]') : null;
                if (!cb) return;
                var id = cb.getAttribute('data-id');
                if (!id) return;
                if (cb.checked) selectedUserIds[String(id)] = true;
                else delete selectedUserIds[String(id)];
                updateSelectedCountUi();
                syncSelectAllUi();
            });

            bindOnce(el('confirmDeleteBtn'), 'click', 'confirmdelete', function () {
                var id = pendingDeleteId;
                if (!id) return;
                postForm('delete_user', { id: id }).then(function (res) {
                    if (!res.data || res.data.ok !== true) {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Request failed.';
                        if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                        return;
                    }
                    if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('confirmDeleteModal')).hide();
                    pendingDeleteId = null;
                    refreshUsers().catch(function () {});
                    if (adminUsersAdminId) {
                        var adminUser = userById(adminUsersAdminId);
                        if (adminUser) loadAdminUsers(adminUser).catch(function () {});
                    }
                    if (window.Swal) Swal.fire({ icon: 'success', title: 'Deleted', timer: 1200, showConfirmButton: false });
                });
            });

            bindOnce(el('downloadQrBtn'), 'click', 'downloadqr', function () {
                var id = lastQrUserId;
                if (!id) return;
                var a = document.createElement('a');
                a.href = pageUrl('users') + '?download_qr=1&id=' + encodeURIComponent(String(id)) + '&t=' + encodeURIComponent(String(Date.now ? Date.now() : new Date().getTime()));
                a.download = 'qr.png';
                document.body.appendChild(a);
                a.click();
                a.remove();
            });

            bindOnce(el('printQrBtn'), 'click', 'printqr', function () {
                var img = el('qrImg');
                var src = img ? (img.src || '') : '';
                if (!src) return;
                var w = window.open('', '_blank');
                if (!w) return;
                w.document.write('<html><head><title>Print QR</title></head><body style="margin:0;display:flex;align-items:center;justify-content:center;height:100vh;"><img src="' + src + '" style="width:320px;height:320px;"></body></html>');
                w.document.close();
                w.focus();
                setTimeout(function () { w.print(); }, 250);
            });

            bindOnce(el('regenQrBtn'), 'click', 'regenqr', function () {
                var id = lastQrUserId;
                if (!id) return;
                postForm('regenerate_qr', { id: id }).then(function (res) {
                    if (!res.data || res.data.ok !== true) {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Request failed.';
                        if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                        return;
                    }
                    var payload = String(res.data.qr_payload || '');
                    lastQrData = payload;
                    setQrImage(payload);
                    for (var i = 0; i < users.length; i++) {
                        if (String(users[i].id) === String(id)) {
                            users[i].qr_token = String(res.data.qr_token || users[i].qr_token || '');
                            users[i].qr_payload = payload;
                            break;
                        }
                    }
                    if (window.Swal) Swal.fire({ icon: 'success', title: 'QR Code successfully regenerated.', timer: 1400, showConfirmButton: false });
                });
            });

            var userModalEl = el('userModal');
            bindOnce(userModalEl, 'hidden.bs.modal', 'userhidden', function () {
                if (!el('userForm')) return;
                el('userForm').reset();
                el('userId').value = '';
                el('usernameInput').classList.remove('is-invalid');
                el('idNumberInput').classList.remove('is-invalid');
                var dInput = el('departmentInput');
                if (dInput) {
                    dInput.readOnly = false;
                    dInput.setAttribute('list', 'departmentList');
                    dInput.classList.remove('bg-light');
                }
                var dNote = el('departmentLockedNote');
                if (dNote) dNote.classList.add('d-none');
            });

            var deleteModalEl = el('confirmDeleteModal');
            bindOnce(deleteModalEl, 'hidden.bs.modal', 'deletehidden', function () {
                pendingDeleteId = null;
                if (el('deleteLabel')) el('deleteLabel').textContent = '';
            });

            var planModalEl = el('planModal');
            bindOnce(planModalEl, 'hidden.bs.modal', 'planhidden', function () {
                pendingPlanUserId = null;
                if (el('planUserId')) el('planUserId').value = '';
                if (el('planUserLabel')) el('planUserLabel').textContent = '';
            });

            var adminUsersModalEl = el('adminUsersModal');
            bindOnce(adminUsersModalEl, 'hidden.bs.modal', 'adminusershidden', function () {
                adminUsers = [];
                adminUsersAdminId = null;
                renderAdminUsersTable();
                if (el('adminUsersTitle')) el('adminUsersTitle').textContent = '';
            });
        }

        function fetchMyIdentity() {
            return fetchJson(apiBase + '&action=get_my_identity', { method: 'GET' }).then(function (res) {
                if (res && res.data && res.data.ok === true && res.data.user) {
                    myIdentity = res.data.user;
                }
                return myIdentity;
            }).catch(function () { return null; });
        }

        function init() {
            if (!el('usersTable')) return;
            destroy();
            var modal = el('userModal');
            sessionRole = modal ? (modal.getAttribute('data-session-role') || '') : '';
            initHandlers();
            fetchMyIdentity().then(function () { return refreshUsers(); }).catch(function () { refreshUsers().catch(function () {}); });
            startLiveClock();
            // Live AJAX: keep the table in sync with the database every 20s.
            // Pauses while a modal is open so camera/capture flows are never disturbed.
            if (refreshTimer) window.clearInterval(refreshTimer);
            refreshTimer = window.setInterval(function () {
                try {
                    if (document.querySelector('.modal.show')) return;
                    refreshUsers().catch(function () {});
                } catch (e) {}
            }, 20000);
            updateSelectedCountUi();
            syncSelectAllUi();
        }

        function destroy() {
            if (pendingValidateTimer) window.clearTimeout(pendingValidateTimer);
            pendingValidateTimer = null;
            if (refreshTimer) window.clearInterval(refreshTimer);
            refreshTimer = null;
            if (enrolledFacesTimer) window.clearInterval(enrolledFacesTimer);
            enrolledFacesTimer = null;
            if (clockTimer) window.clearInterval(clockTimer);
            clockTimer = null;
            pendingDeleteId = null;
            pendingPlanUserId = null;
            lastQrData = null;
            lastQrUserId = null;
            selectedUserIds = {};
            pendingIdTemplateIds = [];
            sessionRole = '';
            adminUsers = [];
            adminUsersAdminId = null;
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.dataTable) {
                if (window.jQuery.fn.DataTable.isDataTable('#usersTable')) {
                    try { window.jQuery('#usersTable').DataTable().destroy(); } catch (e) {}
                }
                try { window.jQuery('#usersTable').off('.usersSel').off('.usersPersist'); } catch (e2) {}
            }
            dt = null;
        }

        return { init: init, destroy: destroy };
    })();

    pages['print-dtr'] = (function () {
        var usersApiBase = pageUrl('users') + '?ajax=1';
        var attendanceApiBase = 'attendance.php?ajax=1';
        var users = [];
        var selectedUser = null;
        var lastDtr = null;
        var searchTimer = null;
        var selectedUserIds = {};
        var inChargeName = '';

        function el(id) { return document.getElementById(id); }

        function bindOnce(node, eventName, key, handler) {
            if (!node) return;
            var k = 'bound_' + String(key || eventName);
            if (node.dataset && node.dataset[k] === '1') return;
            if (node.dataset) node.dataset[k] = '1';
            node.addEventListener(eventName, handler);
        }

        function fetchJson(url, opts) {
            return fetch(url, Object.assign({
                cache: 'no-store',
                headers: Object.assign({ 'Accept': 'application/json' }, (opts && opts.headers) || {})
            }, opts || {})).then(function (r) {
                return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
            });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function isAdminSession() {
            return !!document.getElementById('adminPlanUsageBtn');
        }

        function readInChargeName() {
            try {
                // If the custom input field exists, use its value (even if empty).
                var customInput = document.getElementById('dtrInChargeName');
                if (customInput) {
                    inChargeName = String(customInput.value || '').trim();
                    return;
                }
                if (!isAdminSession()) {
                    inChargeName = '';
                    return;
                }
                var n = document.querySelector('.nxl-user-dropdown .dropdown-header .fw-semibold');
                var t = n ? n.textContent : '';
                inChargeName = String(t || '').trim();
            } catch (e) {
                inChargeName = '';
            }
        }

        function fetchInChargeName() {
            // Check for custom in-charge name input field first.
            // If the field exists (even if empty), use its value — don't fall back to API.
            var customInput = document.getElementById('dtrInChargeName');
            if (customInput) {
                var customVal = String(customInput.value || '').trim();
                inChargeName = customVal;
                return Promise.resolve(customVal);
            }
            if (!isAdminSession()) {
                inChargeName = '';
                return Promise.resolve('');
            }
            return fetchJson(usersApiBase + '&action=get_my_identity', { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    return '';
                }
                var u = res.data.user || {};
                var n = String(u.name || '').trim();
                inChargeName = n;
                return n;
            }).catch(function () { return ''; });
        }

        function ensureInChargeName() {
            return fetchInChargeName().then(function (n) {
                if (n) return n;
                readInChargeName();
                return inChargeName;
            });
        }

        function buildUserLabel(u) {
            var parts = [];
            if (u && u.name) parts.push(u.name);
            if (u && u.id_number) parts.push(u.id_number);
            if (u && u.department) parts.push(u.department);
            return parts.join(' • ');
        }

        function selectedIds() {
            return Object.keys(selectedUserIds || {}).filter(function (k) { return selectedUserIds[k] === true; });
        }

        function selectedIdsOrdered() {
            var out = [];
            for (var i = 0; i < users.length; i++) {
                var id = users[i] && users[i].id != null ? String(users[i].id) : '';
                if (id && selectedUserIds[id] === true) out.push(id);
            }
            var extra = selectedIds();
            for (var j = 0; j < extra.length; j++) {
                if (out.indexOf(extra[j]) === -1) out.push(extra[j]);
            }
            return out;
        }

        function updateSelectedCountUi() {
            var cnt = selectedIds().length;
            var c = el('dtrSelectedCount');
            if (c) c.textContent = String(cnt);
            var bulkBtn = el('dtrBulkPrintBtn');
            if (bulkBtn) bulkBtn.disabled = cnt === 0;
            var printBtn = el('dtrPrintBtn');
            if (printBtn) printBtn.disabled = (cnt === 0 && !lastDtr);
            updatePdfHref();
        }

        function syncSelectAllUi() {
            var all = el('dtrSelectAllUsers');
            var box = el('dtrSearchResults');
            if (!all || !box) return;
            var checks = box.querySelectorAll('input.dtr-user-check[data-id]');
            if (!checks || !checks.length) {
                all.checked = false;
                all.indeterminate = false;
                return;
            }
            var any = false;
            var every = true;
            for (var i = 0; i < checks.length; i++) {
                any = any || !!checks[i].checked;
                every = every && !!checks[i].checked;
            }
            all.checked = every;
            all.indeterminate = any && !every;
        }

        function renderResults(list) {
            var box = el('dtrSearchResults');
            if (!box) return;
            if (!list || !list.length) {
                box.innerHTML = '<div class="text-muted small px-2 py-2">No results.</div>';
                updateSelectedCountUi();
                syncSelectAllUi();
                return;
            }
            box.innerHTML = list.slice(0, 15).map(function (u) {
                var id = escapeHtml(u.id);
                var checked = !!selectedUserIds[String(u.id)];
                return ''
                    + '<div class="list-group-item d-flex align-items-start gap-2">'
                    + '  <input class="form-check-input mt-1 dtr-user-check" type="checkbox" data-id="' + id + '"' + (checked ? ' checked' : '') + '>'
                    + '  <div class="flex-grow-1" style="min-width:0;">'
                    + '    <div class="fw-semibold">' + escapeHtml(u.name || '') + '</div>'
                    + '    <div class="text-muted small">' + escapeHtml(buildUserLabel(u)) + '</div>'
                    + '  </div>'
                    + '  <button type="button" class="btn btn-sm btn-outline-primary" data-action="choose" data-id="' + id + '">Select</button>'
                    + '</div>';
            }).join('');
            updateSelectedCountUi();
            syncSelectAllUi();
        }

        function filterUsers(term) {
            term = String(term || '').trim().toLowerCase();
            if (!term) return users.slice(0, 15);
            return users.filter(function (u) {
                var hay = [u.name, u.id_number, u.department, u.position, u.username].join(' ').toLowerCase();
                return hay.indexOf(term) >= 0;
            });
        }

        function updatePdfHref() {
            var a = el('dtrPdfBtn');
            if (!a) return;
            var cnt = selectedIds().length;
            if (cnt === 0 && !selectedUser) {
                a.href = '#';
                a.classList.add('disabled');
                a.setAttribute('aria-disabled', 'true');
                return;
            }
            if (cnt <= 1 && selectedUser) {
                var df = el('dtrDateFrom') ? (el('dtrDateFrom').value || '') : '';
                var dt = el('dtrDateTo') ? (el('dtrDateTo').value || '') : '';
                var url = 'print-dtr.php?export=pdf&user_id=' + encodeURIComponent(String(selectedUser.id));
                if (df) url += '&date_from=' + encodeURIComponent(df);
                if (dt) url += '&date_to=' + encodeURIComponent(dt);
                var icInput = el('dtrInChargeName');
                var ic = icInput ? String(icInput.value || '').trim() : '';
                if (ic) url += '&in_charge=' + encodeURIComponent(ic);
                a.href = url;
            } else {
                a.href = '#';
            }
            a.classList.remove('disabled');
            a.setAttribute('aria-disabled', 'false');
        }

        function setSelected(u) {
            selectedUser = u || null;
            if (el('dtrUserId')) el('dtrUserId').value = selectedUser ? String(selectedUser.id) : '';
            if (el('dtrSelectedName')) el('dtrSelectedName').textContent = selectedUser ? String(selectedUser.name || '') : 'No employee selected';
            if (el('dtrSelectedMeta')) el('dtrSelectedMeta').textContent = selectedUser ? buildUserLabel(selectedUser) : '';
            if (el('dtrViewBtn')) el('dtrViewBtn').disabled = !selectedUser;
            if (el('dtrPrintBtn')) el('dtrPrintBtn').disabled = true;

            var pdfBtn = el('dtrPdfBtn');
            if (pdfBtn) {
                pdfBtn.classList.toggle('disabled', !selectedUser);
                pdfBtn.setAttribute('aria-disabled', selectedUser ? 'false' : 'true');
            }

            lastDtr = null;
            if (el('dtrPreview')) el('dtrPreview').style.display = 'none';
            if (el('dtrPreviewEmpty')) el('dtrPreviewEmpty').style.display = 'block';
            if (el('dtrRows')) el('dtrRows').innerHTML = '';
            updatePdfHref();
        }

        function formatCell(v) {
            var s = String(v || '').trim();
            return s ? s : '-';
        }

        function timeToInput(v) {
            var s = String(v || '').trim();
            if (!s) return '';
            var m = s.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
            if (!m) return '';
            var hh = String(m[1] || '').padStart(2, '0');
            var mm = String(m[2] || '00');
            return hh + ':' + mm;
        }

        function normalizeTimeForDb(v) {
            var s = String(v || '').trim();
            if (!s) return '';
            if (/^\d{2}:\d{2}:\d{2}$/.test(s)) return s;
            if (/^\d{2}:\d{2}$/.test(s)) return s + ':00';
            return '';
        }

        function renderDtr(dtr) {
            var rows = Array.isArray(dtr && dtr.records) ? dtr.records : [];
            if (el('dtrRows')) {
                el('dtrRows').innerHTML = rows.length ? rows.map(function (r) {
                    return '<tr>'
                        + '<td>' + escapeHtml(r.attend_date || '') + '</td>'
                        + '<td>' + escapeHtml(formatCell(r.am_in)) + '</td>'
                        + '<td>' + escapeHtml(formatCell(r.am_out)) + '</td>'
                        + '<td>' + escapeHtml(formatCell(r.pm_in)) + '</td>'
                        + '<td>' + escapeHtml(formatCell(r.pm_out)) + '</td>'
                        + '<td>'
                        + '  <button type="button" class="btn btn-sm btn-outline-primary" data-action="edit-dtr" data-date="' + escapeHtml(r.attend_date || '') + '"><i class="feather-edit-2"></i></button>'
                        + '</td>'
                        + '</tr>';
                }).join('') : '<tr><td colspan="6" class="text-center text-muted py-4">No attendance records found.</td></tr>';
            }
            if (el('dtrPreviewEmpty')) el('dtrPreviewEmpty').style.display = 'none';
            if (el('dtrPreview')) el('dtrPreview').style.display = 'block';
            if (el('dtrPrintBtn')) el('dtrPrintBtn').disabled = false;
        }

        var csForm48Style = ''
            + '@page { size: a4; margin: 0.5in; }'
            + 'html, body { margin: 0; padding: 0; background: #fff; }'
            + 'body { font-family: "Times New Roman", Times, serif; color: #000; }'
            + '.page { page-break-after: always; width: 100%; box-sizing: border-box; }'
            + '.page-side-by-side { display: flex; justify-content: center; align-items: flex-start; gap: 1.2in; }'
            + '.dtr-form { width: 2.8in; display: flex; flex-direction: column; }'
            + '.csf { font-size: 7pt; text-align: left; margin-bottom: 2px; }'
            + '.title { text-align:center; font-weight: 700; font-size: 10.5pt; }'
            + '.name-field { text-align: center; margin: 8px 0 6px; }'
            + '.name-value { font-weight: 700; font-size: 9.5pt; border-bottom: 1px solid #000; display: inline-block; min-width: 90%; padding-bottom: 1px; line-height: 1.2; }'
            + '.name-cap { font-size: 7pt; margin-top: 1px; }'
            + '.meta { font-size: 7.8pt; margin-bottom: 4px; }'
            + '.meta-row { display:flex; align-items:flex-end; gap: 3px; margin: 0px 0; }'
            + '.lbl { white-space:nowrap; }'
            + '.fill { flex:1; border-bottom: 1px solid #000; margin-left: 3px; min-height: 10px; }'
            + '.underline-text { text-align: center; }'
            + 'table.dtr { width: 100%; border-collapse: collapse; border: 1.2px solid #000; }'
            + 'table.dtr th, table.dtr td { border: 1px solid #000; padding: 0px 1px; font-size: 6.8pt; height: 11pt; }'
            + 'table.dtr th { text-align:center; font-weight: 700; }'
            + 'table.dtr th.block { font-size: 7.2pt; }'
            + 'table.dtr th.sub { font-size: 6.5pt; line-height: 1; vertical-align: middle; }'
            + 'td.c { text-align:center; }'
            + 'td.day { font-weight: 700; width: 7%; }'
            + 'td.time { font-weight: 700; }'
            + '.dtr-total { display: flex; align-items: flex-end; justify-content: flex-end; margin-top: 2px; padding: 2px 0; }'
            + '.total-lbl { font-size: 7pt; font-weight: 700; white-space: nowrap; margin-right: 3px; }'
            + '.total-line { width: 40%; border-bottom: 1px solid #000; min-height: 10px; }'
            + '.cert { font-size: 7pt; line-height: 1.1; text-align: justify; margin-top: 5px; }'
            + '.footer-area { margin-top: 5px; }'
            + '.sig-group { margin-top: 25px; }'
            + '.sig-name { text-align:center; font-weight: 700; font-size: 8.5pt; line-height: 1.1; min-height: 10pt; }'
            + '.sig-line { width: 100%; border-bottom: 1px solid #000; }'
            + '.sig-cap { text-align:center; font-size: 7.5pt; margin-top: 1px; }'
            + '.verify { font-size: 7.5pt; margin-top: 8px; }'
            + '.inst-header { text-align:center; font-weight: 700; font-size: 13pt; margin-top: 10px; }'
            + '.inst-ooo { display: flex; align-items: center; justify-content: center; gap: 5px; margin: 2px 0 5px; font-size: 9pt; }'
            + '.ooo-line { flex: 1; border-bottom: 1px solid #000; }'
            + '.inst-body { font-size: 8.8pt; line-height: 1.35; text-align: justify; padding: 0 3px; }'
            + '.inst-body p { margin: 7px 0; text-indent: 20px; }'
            + '.inst-divider { text-align: center; font-size: 9pt; margin: 10px 0; }'
            + '.inst-note { font-size: 7.8pt !important; line-height: 1.2; text-indent: 0 !important; }'
            + '@media print { .page { break-after: page; } }';

        function buildInstructionSide() {
            return ''
                + '<div class="dtr-form">'
                + '  <div class="inst-header">INSTRUCTIONS</div>'
                + '  <div class="inst-ooo"><span class="ooo-line"></span><span>oOo</span><span class="ooo-line"></span></div>'
                + '  <div class="inst-body">'
                + '    <p>Civil Service Form No. 48, after completion, should be filed in the records of the Bureau or Office which submits the monthly report on Civil Service Form No. 3 to the Bureau of Civil Service.</p>'
                + '    <p>In lieu of the above, court interpreters and stenographers who accompany the judges of the Court of First Instance will fill out the daily time reports on this form in triplicate, after which they should be approved by the judge with whom service has been rendered, or by an officer of the Department of Justice authorized to do so. The original should be forwarded promptly after the end of the month to the Bureau of Civil Service, thru the Department of Justice; and the triplicate in the office of the Clerk of Court where the service was rendered.</p>'
                + '    <p>In the space provided for the purpose on the other side will be indicated the office hours the employee is required to observe; as for example, Regular days, 8:00 -12:00 and 1:00 - 4:00; Saturdays, 8:00 - 1:00".</p>'
                + '    <p>Attention is invited to paragraph 3, Civil Service Rule XV, Executive Order No. 5, series of 1909, which read as follows:</p>'
                + '    <p>Each Chief of a Bureau or Office shall require a daily record of attendance of all the officers and employees under him entitled to leave of absence or vacation (including teachers) to be kept on the proper form and also systematic office record showing for each day all absences from duty from any cause whatever. At the beginning of each month he shall report to the Commissioner on the proper form all absences from any cause whatever, including the exact amount of undertime of each person for each day. Officers and employees serving in the field or on the water need not be required to keep a daily record, but all absences of such employees must be included in the monthly report of changes and absences. Falsification of time records will render the offending officer or employee liable to summary removal from the service and criminal prosecution."</p>'
                + '    <div class="inst-divider">║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║</div>'
                + '    <p class="inst-note">(NOTE. - A record made from memory at sometime subsequent to the occurrence of an event is not reliable. Non-observance of office hours deprives the employee of the leave privileges although he may have rendered overtime service. Where service rendered outside of the office of the whole morning or afternoon, notation to that effect should be made clearly.)</p>'
                + '  </div>'
                + '</div>';
        }

        function buildCsForm48Pages(user, records, dateFrom, dateTo, includeInstructions) {
            function pad2(n) {
                n = Number(n || 0);
                return (n < 10 ? '0' : '') + String(n);
            }

            function formatTimeOfficial(value) {
                var t = String(value == null ? '' : value).trim();
                if (!t) return '';
                var m = t.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
                if (!m) return '';
                var hh = Number(m[1] || 0);
                var mm = String(m[2] || '00');
                var h12 = hh % 12;
                if (h12 === 0) h12 = 12;
                return pad2(h12) + ':' + mm;
            }

            var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            var df = dateFrom ? new Date(dateFrom + 'T00:00:00') : null;
            var dt2 = dateTo ? new Date(dateTo + 'T00:00:00') : null;
            var ref = df || dt2;
            if (!ref && records && records.length && records[0] && records[0].attend_date) {
                ref = new Date(String(records[0].attend_date) + 'T00:00:00');
            }
            if (!ref) ref = new Date();
            var monthLabel = monthNames[ref.getMonth()] + ' ' + ref.getFullYear();

            var byDay = {};
            (records || []).forEach(function (r) {
                if (!r || !r.attend_date) return;
                var d = new Date(String(r.attend_date) + 'T00:00:00');
                var day = d.getDate();
                byDay[String(day)] = {
                    am_in: formatTimeOfficial(r.am_in),
                    am_out: formatTimeOfficial(r.am_out),
                    pm_in: formatTimeOfficial(r.pm_in),
                    pm_out: formatTimeOfficial(r.pm_out)
                };
            });

            function buildFrontPage() {
                var rows = '';
                for (var i = 1; i <= 31; i++) {
                    var rec = byDay[String(i)] || {};
                    rows += ''
                        + '<tr>'
                        + '<td class="c day">' + i + '</td>'
                        + '<td class="c time">' + (rec.am_in || '') + '</td>'
                        + '<td class="c time">' + (rec.am_out || '') + '</td>'
                        + '<td class="c time">' + (rec.pm_in || '') + '</td>'
                        + '<td class="c time">' + (rec.pm_out || '') + '</td>'
                        + '<td class="c utH"></td>'
                        + '<td class="c utM"></td>'
                        + '</tr>';
                }
                var inChargeHtml = inChargeName ? ('      <div class="sig-name">' + escapeHtml(inChargeName) + '</div>') : '';

                return ''
                    + '<div class="dtr-form">'
                    + '  <div class="csf">CS Form 48</div>'
                    + '  <div class="title">DAILY TIME RECORD</div>'
                    + '  <div class="name-field">'
                    + '    <div class="name-value">' + escapeHtml(user && user.name ? user.name : '') + '</div>'
                    + '    <div class="name-cap">Name</div>'
                    + '  </div>'
                    + '  <div class="meta">'
                    + '    <div class="meta-row"><span class="lbl">For the month of</span><span class="fill underline-text">' + escapeHtml(monthLabel) + '</span></div>'
                    + '    <div class="meta-row"><span class="lbl">Office Hours (regular days)</span><span class="fill"></span></div>'
                    + '    <div class="meta-row"><span class="lbl">Arrival & Departure</span><span class="fill"></span></div>'
                    + '    <div class="meta-row"><span class="lbl">Saturdays</span><span class="fill"></span></div>'
                    + '  </div>'
                    + '  <table class="dtr">'
                    + '    <thead>'
                    + '      <tr>'
                    + '        <th rowspan="2" class="day"></th>'
                    + '        <th colspan="2" class="block">AM</th>'
                    + '        <th colspan="2" class="block">PM</th>'
                    + '        <th rowspan="2" class="block">Hours</th>'
                    + '        <th rowspan="2" class="block">Min.</th>'
                    + '      </tr>'
                    + '      <tr>'
                    + '        <th class="sub">Arri-<br>val</th>'
                    + '        <th class="sub">Depar-<br>ture</th>'
                    + '        <th class="sub">Arri-<br>val</th>'
                    + '        <th class="sub">Depar-<br>Ture</th>'
                    + '      </tr>'
                    + '    </thead>'
                    + '    <tbody>'
                    + rows
                    + '    </tbody>'
                    + '  </table>'
                    + '  <div class="dtr-total">'
                    + '    <span class="total-lbl">Total</span>'
                    + '    <span class="total-line"></span>'
                    + '  </div>'
                    + '  <div class="footer-area">'
                    + '    <div class="cert">I certify on my honor that the above is true and correct record of the hours of work performed of which was made daily at the time of arrival and departure from the office.</div>'
                    + '    <div class="sig-group">'
                    + '      <div class="sig-line"></div>'
                    + '      <div class="sig-cap">(Signature)</div>'
                    + '    </div>'
                    + '    <div class="verify">Verified as to the prescribed office hours</div>'
                    + '    <div class="sig-group">'
                    + inChargeHtml
                    + '      <div class="sig-line"></div>'
                    + '      <div class="sig-cap">(In-charge)</div>'
                    + '    </div>'
                    + '  </div>'
                    + '</div>';
            }

            var html = ''
                + '<div class="page"><div class="page-side-by-side">'
                + buildFrontPage()
                + buildFrontPage()
                + '</div></div>';
            if (includeInstructions) {
                html += ''
                    + '<div class="page"><div class="page-side-by-side">'
                    + buildInstructionSide()
                    + buildInstructionSide()
                    + '</div></div>';
            }
            return html;
        }

        function buildCsForm48Html(user, records, dateFrom, dateTo) {
            return '<!DOCTYPE html>'
                + '<html><head>'
                + '<meta charset="utf-8">'
                + '<title>Daily Time Record - ' + escapeHtml(user && user.name ? user.name : '') + '</title>'
                + '<style>' + csForm48Style + '</style>'
                + '</head><body>'
                + buildCsForm48Pages(user, records, dateFrom, dateTo, true)
                + '</body></html>';
        }

        function loadUsers() {
            return fetchJson(usersApiBase + '&action=list_users', { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) return;
                users = Array.isArray(res.data.users) ? res.data.users : [];
                renderResults(users.slice(0, 15));
            });
        }

        function viewDtr() {
            if (!selectedUser) return Promise.resolve();
            var userId = selectedUser.id;
            var df = el('dtrDateFrom') ? (el('dtrDateFrom').value || '') : '';
            var dt = el('dtrDateTo') ? (el('dtrDateTo').value || '') : '';
            var url = attendanceApiBase + '&action=get_dtr&user_id=' + encodeURIComponent(String(userId));
            if (df) url += '&date_from=' + encodeURIComponent(df);
            if (dt) url += '&date_to=' + encodeURIComponent(dt);
            return fetchJson(url, { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    var msg = (res.data && res.data.message) ? res.data.message : 'Failed to generate DTR.';
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                    return;
                }
                lastDtr = res.data;
                renderDtr(res.data);
                updatePdfHref();
                updateSelectedCountUi();
            });
        }

        function printDtr() {
            if (!lastDtr) return;
            ensureInChargeName().then(function () {
                var w = window.open('', '_blank');
                if (!w) return;
                w.document.write(buildCsForm48Html(lastDtr.user || {}, lastDtr.records || [], lastDtr.date_from || '', lastDtr.date_to || ''));
                w.document.close();
                w.focus();
                setTimeout(function () { w.print(); }, 250);
            });
        }

        function initHandlers() {
            bindOnce(el('dtrSearchInput'), 'input', 'dtrSearchInput', function (e) {
                var value = (e && e.target) ? e.target.value : '';
                if (searchTimer) window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(function () {
                    renderResults(filterUsers(value));
                }, 120);
            });
            bindOnce(el('dtrSearchResults'), 'click', 'dtrResultsClick', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('button[data-action="choose"][data-id]') : null;
                if (!btn) return;
                var id = btn.getAttribute('data-id');
                var u = null;
                for (var i = 0; i < users.length; i++) {
                    if (String(users[i].id) === String(id)) { u = users[i]; break; }
                }
                if (u) setSelected(u);
            });
            bindOnce(el('dtrSearchResults'), 'change', 'dtrResultsSelect', function (e) {
                var t = e && e.target;
                if (!t || !(t.matches && t.matches('input.dtr-user-check[data-id]'))) return;
                var id = t.getAttribute('data-id');
                if (t.checked) selectedUserIds[String(id)] = true;
                else delete selectedUserIds[String(id)];
                updateSelectedCountUi();
                syncSelectAllUi();
            });
            bindOnce(el('dtrSelectAllUsers'), 'change', 'dtrSelectAllUsers', function (e) {
                var box = el('dtrSearchResults');
                if (!box) return;
                var checked = !!(e && e.target && e.target.checked);
                var checks = box.querySelectorAll('input.dtr-user-check[data-id]');
                for (var i = 0; i < checks.length; i++) {
                    checks[i].checked = checked;
                    var id = checks[i].getAttribute('data-id');
                    if (checked) selectedUserIds[String(id)] = true;
                    else delete selectedUserIds[String(id)];
                }
                updateSelectedCountUi();
                syncSelectAllUi();
            });
            bindOnce(el('dtrViewBtn'), 'click', 'dtrViewBtn', function () { viewDtr(); });
            bindOnce(el('dtrPrintBtn'), 'click', 'dtrPrintBtn', function () {
                if (selectedIds().length) {
                    var btn = el('dtrBulkPrintBtn');
                    if (btn) btn.click();
                    return;
                }
                printDtr();
            });
            bindOnce(el('dtrBulkPrintBtn'), 'click', 'dtrBulkPrintBtn', function () {
                var ids = selectedIdsOrdered();
                if (!ids.length) return;
                var df = el('dtrDateFrom') ? (el('dtrDateFrom').value || '') : '';
                var dt2 = el('dtrDateTo') ? (el('dtrDateTo').value || '') : '';

                ensureInChargeName().then(function () {
                    var requests = ids.map(function (id) {
                        var url = attendanceApiBase + '&action=get_dtr&user_id=' + encodeURIComponent(String(id));
                        if (df) url += '&date_from=' + encodeURIComponent(df);
                        if (dt2) url += '&date_to=' + encodeURIComponent(dt2);
                        return fetchJson(url, { method: 'GET' }).then(function (res) {
                            if (!res.data || res.data.ok !== true) return null;
                            return res.data;
                        }).catch(function () { return null; });
                    });

                    Promise.all(requests).then(function (results) {
                        var dtrs = results.filter(function (x) { return !!x; });
                        if (!dtrs.length) {
                            if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: 'No DTR data available.' });
                            return;
                        }
                        var pagesHtml = dtrs.map(function (d) {
                            return buildCsForm48Pages(d.user || {}, d.records || [], d.date_from || df, d.date_to || dt2, false);
                        }).join('');
                        var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Daily Time Record</title><style>' + csForm48Style + '</style></head><body>' + pagesHtml + '</body></html>';
                        var w = window.open('', '_blank');
                        if (!w) return;
                        w.document.open();
                        w.document.write(html);
                        w.document.close();
                        w.focus();
                        try {
                            w.onafterprint = function () {
                                try { w.close(); } catch (e) {}
                                try { window.focus(); } catch (e2) {}
                            };
                        } catch (e3) {}
                        setTimeout(function () {
                            try { w.print(); } catch (e4) {}
                            setTimeout(function () {
                                try { w.close(); } catch (e5) {}
                                try { window.focus(); } catch (e6) {}
                            }, 1200);
                        }, 250);
                    });
                });
            });
            bindOnce(el('dtrPrintBackBtn'), 'click', 'dtrPrintBackBtn', function () {
                var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>DTR Instructions</title><style>' + csForm48Style + '</style></head><body>'
                    + '<div class="page"><div class="page-side-by-side">'
                    + buildInstructionSide()
                    + buildInstructionSide()
                    + '</div></div>'
                    + '</body></html>';
                var w = window.open('', '_blank');
                if (!w) return;
                w.document.open();
                w.document.write(html);
                w.document.close();
                w.focus();
                try {
                    w.onafterprint = function () {
                        try { w.close(); } catch (e) {}
                        try { window.focus(); } catch (e2) {}
                    };
                } catch (e3) {}
                setTimeout(function () {
                    try { w.print(); } catch (e4) {}
                    setTimeout(function () {
                        try { w.close(); } catch (e5) {}
                        try { window.focus(); } catch (e6) {}
                    }, 1200);
                }, 250);
            });
            bindOnce(el('dtrClearDatesBtn'), 'click', 'dtrClearDatesBtn', function () {
                if (el('dtrDateFrom')) el('dtrDateFrom').value = '';
                if (el('dtrDateTo')) el('dtrDateTo').value = '';
                updatePdfHref();
            });
            bindOnce(el('dtrDateFrom'), 'change', 'dtrDateFrom', function () { updatePdfHref(); });
            bindOnce(el('dtrDateTo'), 'change', 'dtrDateTo', function () { updatePdfHref(); });
            bindOnce(el('dtrInChargeName'), 'input', 'dtrInChargeName', function () { updatePdfHref(); });
            bindOnce(el('dtrSaveInChargeBtn'), 'click', 'dtrSaveInChargeBtn', function () {
                try { localStorage.setItem(getIcStorageKey(), el('dtrInChargeName').value || ''); } catch (e) {}
                if (window.Swal) Swal.fire({ icon: 'success', title: 'Saved', timer: 1200, showConfirmButton: false });
            });
            bindOnce(el('dtrPdfBtn'), 'click', 'dtrPdfBtn', function (e) {
                var ids = selectedIdsOrdered();
                if (!ids.length && !selectedUser) { if (e) e.preventDefault(); return; }
                if (ids.length <= 1 && selectedUser) return;
                if (e) e.preventDefault();
                var df = el('dtrDateFrom') ? (el('dtrDateFrom').value || '') : '';
                var dt = el('dtrDateTo') ? (el('dtrDateTo').value || '') : '';
                var icInput = el('dtrInChargeName');
                var ic = icInput ? String(icInput.value || '').trim() : '';
                ids.forEach(function (id, idx) {
                    setTimeout(function () {
                        var iframe = document.createElement('iframe');
                        iframe.style.display = 'none';
                        iframe.src = 'print-dtr.php?export=pdf&user_id=' + encodeURIComponent(String(id)) + (df ? '&date_from=' + encodeURIComponent(df) : '') + (dt ? '&date_to=' + encodeURIComponent(dt) : '') + (ic ? '&in_charge=' + encodeURIComponent(ic) : '');
                        document.body.appendChild(iframe);
                        setTimeout(function () { try { iframe.remove(); } catch (e2) {} }, 10000);
                    }, idx * 500);
                });
            });

            bindOnce(el('dtrRows'), 'click', 'dtrRowsClick', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('button[data-action="edit-dtr"][data-date]') : null;
                if (!btn) return;
                if (!lastDtr || !selectedUser) return;
                var date = btn.getAttribute('data-date') || '';
                if (!date) return;
                var rec = null;
                var list = Array.isArray(lastDtr.records) ? lastDtr.records : [];
                for (var i = 0; i < list.length; i++) {
                    if (String(list[i].attend_date) === String(date)) { rec = list[i]; break; }
                }
                if (!rec) return;
                if (el('dtrEditDate')) el('dtrEditDate').value = String(date);
                if (el('dtrEditDateLabel')) el('dtrEditDateLabel').value = String(date);
                if (el('dtrEditAmIn')) el('dtrEditAmIn').value = timeToInput(rec.am_in);
                if (el('dtrEditAmOut')) el('dtrEditAmOut').value = timeToInput(rec.am_out);
                if (el('dtrEditPmIn')) el('dtrEditPmIn').value = timeToInput(rec.pm_in);
                if (el('dtrEditPmOut')) el('dtrEditPmOut').value = timeToInput(rec.pm_out);
                if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('dtrEditModal')).show();
            });

            bindOnce(el('dtrEditForm'), 'submit', 'dtrEditForm', function (e) {
                if (e && e.preventDefault) e.preventDefault();
                if (!selectedUser) return;
                var attendDate = el('dtrEditDate') ? (el('dtrEditDate').value || '') : '';
                if (!attendDate) return;
                var payload = {
                    user_id: selectedUser.id,
                    attend_date: attendDate,
                    am_in: normalizeTimeForDb(el('dtrEditAmIn') ? el('dtrEditAmIn').value : ''),
                    am_out: normalizeTimeForDb(el('dtrEditAmOut') ? el('dtrEditAmOut').value : ''),
                    pm_in: normalizeTimeForDb(el('dtrEditPmIn') ? el('dtrEditPmIn').value : ''),
                    pm_out: normalizeTimeForDb(el('dtrEditPmOut') ? el('dtrEditPmOut').value : '')
                };

                var body = new URLSearchParams();
                body.set('action', 'edit_attendance');
                Object.keys(payload).forEach(function (k) {
                    if (payload[k] === undefined || payload[k] === null) return;
                    body.set(k, String(payload[k]));
                });

                fetchJson(attendanceApiBase + '&action=edit_attendance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
                    body: body.toString()
                }).then(function (res) {
                    if (!res.data || res.data.ok !== true) {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Failed to update DTR.';
                        if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                        return;
                    }
                    if (window.bootstrap && window.bootstrap.Modal) {
                        var m = window.bootstrap.Modal.getInstance(el('dtrEditModal'));
                        if (m) m.hide();
                    }
                    if (window.Swal) Swal.fire({ icon: 'success', title: 'Saved', timer: 1200, showConfirmButton: false });
                    viewDtr().catch(function () {});
                }).catch(function () {
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: 'Network error.' });
                });
            });
        }

        function getIcStorageKey() {
            var icInput = el('dtrInChargeName');
            var adminId = icInput ? (icInput.getAttribute('data-admin-id') || '') : '';
            return 'dtr_in_charge_name_' + adminId;
        }

        function init() {
            if (!el('dtrSearchInput')) return;
            destroy();
            // Restore the saved in-charge name from localStorage (per admin).
            var savedIc = '';
            try { savedIc = localStorage.getItem(getIcStorageKey()) || ''; } catch (e) {}
            var icInput = el('dtrInChargeName');
            if (icInput && savedIc) icInput.value = savedIc;
            readInChargeName();
            initHandlers();
            setSelected(null);
            loadUsers().catch(function () {});
            updateSelectedCountUi();
            syncSelectAllUi();
        }

        function destroy() {
            users = [];
            selectedUser = null;
            lastDtr = null;
            selectedUserIds = {};
            if (searchTimer) window.clearTimeout(searchTimer);
            searchTimer = null;
        }

        if (!App.__printDtrPjaxHook) {
            App.__printDtrPjaxHook = true;
            document.addEventListener('pjax:complete', function () {
                var path = String(window.location && window.location.pathname ? window.location.pathname : '');
                path = path.replace(/\/+$/, '');
                if (!(/(^|\/)print-dtr$/).test(path)) return;
                if (!document.getElementById('dtrSearchInput')) return;
                init();
            });
        }

        return { init: init, destroy: destroy };
    })();

    pages.attendance = (function () {
        var apiBase = 'attendance.php?ajax=1';
        var logs = [];
        var dt = null;
        var currentPage = 1;
        var perPage = 50;
        var totalPages = 1;
        var totalRows = 0;
        var sessionRole = '';

        var stream = null;
        var detector = null;
        var scanning = false;
        var scanTimer = null;
        var cooldown = false;
        var jsQrPromise = null;

        var clockTimer = null;
        var autoRefreshTimer = null;

        function el(id) { return document.getElementById(id); }

        function bindOnce(node, eventName, key, handler) {
            if (!node) return;
            var k = 'bound_' + String(key || eventName);
            if (node.dataset && node.dataset[k] === '1') return;
            if (node.dataset) node.dataset[k] = '1';
            node.addEventListener(eventName, handler);
        }

        function fetchJson(url, options) {
            options = options || {};
            options.cache = 'no-store';
            options.headers = options.headers || {};
            options.headers['Accept'] = 'application/json';
            return fetch(url, options).then(function (r) {
                return r.json().then(function (data) {
                    return { ok: r.ok, status: r.status, data: data };
                });
            }).catch(function () {
                return { ok: false, status: 0, data: null };
            });
        }

        function postForm(action, payload) {
            var body = new URLSearchParams();
            body.set('action', action);
            Object.keys(payload || {}).forEach(function (k) {
                if (payload[k] === undefined || payload[k] === null) return;
                body.set(k, String(payload[k]));
            });
            return fetchJson(apiBase + '&action=' + encodeURIComponent(action), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
                body: body.toString()
            });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatTime12(value) {
            var t = String(value == null ? '' : value).trim();
            if (!t) return '';
            var m = t.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
            if (!m) return t;
            var hh = Number(m[1] || 0);
            var mm = String(m[2] || '00');
            var ap = hh >= 12 ? 'PM' : 'AM';
            var h12 = hh % 12;
            if (h12 === 0) h12 = 12;
            return String(h12) + ':' + mm + ' ' + ap;
        }

        function formatDate(value) {
            var t = String(value == null ? '' : value).trim();
            if (!t) return '';
            var m = t.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (!m) return t;
            var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            var month = months[parseInt(m[2], 10) - 1];
            var day = parseInt(m[3], 10);
            return month + ' ' + day + ', ' + m[1];
        }

        function formatTime24(value) {
            var t = String(value == null ? '' : value).trim();
            if (!t) return '';
            if (/^\d{2}:\d{2}$/.test(t)) return t;
            var m = t.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
            if (!m) return '';
            var hh = String(m[1]).padStart(2, '0');
            return hh + ':' + (m[2] || '00');
        }

        function actionButtons(r) {
            var html = '<div class="d-flex gap-1 flex-wrap">';
            if (sessionRole === 'superadmin') {
                html += '<button class="btn btn-sm btn-outline-info" data-action="user-logins" data-user-id="' + escapeHtml(r.user_id) + '" data-name="' + escapeHtml(r.full_name) + '" title="View this admin\'s user logins"><i class="feather-users me-1"></i>User Logins</button>';
            }
            html += '<button class="btn btn-sm btn-outline-primary" data-action="edit" data-user-id="' + escapeHtml(r.user_id) + '" data-date="' + escapeHtml(r.attend_date) + '" title="Edit"><i class="feather-edit-2"></i></button>'
                + '<button class="btn btn-sm btn-outline-danger" data-action="delete" data-user-id="' + escapeHtml(r.user_id) + '" data-date="' + escapeHtml(r.attend_date) + '" data-name="' + escapeHtml(r.full_name) + '" title="Delete"><i class="feather-trash-2"></i></button>'
                + '<button class="btn btn-sm btn-outline-success" data-action="print" data-user-id="' + escapeHtml(r.user_id) + '" data-name="' + escapeHtml(r.full_name) + '" title="Print DTR"><i class="feather-printer"></i></button>'
                + '</div>';
            return html;
        }

        function renderTable() {
            var table = el('attendanceTable');
            if (!table) return;

            function photoCell(p) {
                var path = String(p || '');
                if (!path) return '';
                var src = path.indexOf('http') === 0 ? path : path;
                return '<img src="' + escapeHtml(src) + '" data-action="view-photo" style="width:44px;height:44px;object-fit:cover;border-radius:8px;cursor:pointer;" alt="Photo">';
            }
            function row(r) {
                return [
                    escapeHtml(r.id_number),
                    escapeHtml(r.full_name),
                    photoCell(r.image_path),
                    escapeHtml(r.position || ''),
                    escapeHtml(formatDate(r.attend_date)),
                    escapeHtml(formatTime12(r.am_in || '')),
                    escapeHtml(formatTime12(r.am_out || '')),
                    escapeHtml(formatTime12(r.pm_in || '')),
                    escapeHtml(formatTime12(r.pm_out || '')),
                    escapeHtml(r.status || (!r.attend_date ? 'No log yet' : '')),
                    actionButtons(r)
                ];
            }

            function renderManual() {
                var tbody = table.querySelector('tbody');
                if (!tbody) return;
                tbody.innerHTML = logs.length ? logs.map(function (r) {
                    return '<tr>'
                        + '<td>' + escapeHtml(r.id_number) + '</td>'
                        + '<td>' + escapeHtml(r.full_name) + '</td>'
                        + '<td>' + photoCell(r.image_path) + '</td>'
                        + '<td>' + escapeHtml(r.position || '') + '</td>'
                        + '<td>' + escapeHtml(formatDate(r.attend_date)) + '</td>'
                        + '<td>' + escapeHtml(formatTime12(r.am_in || '')) + '</td>'
                        + '<td>' + escapeHtml(formatTime12(r.am_out || '')) + '</td>'
                        + '<td>' + escapeHtml(formatTime12(r.pm_in || '')) + '</td>'
                        + '<td>' + escapeHtml(formatTime12(r.pm_out || '')) + '</td>'
                        + '<td>' + escapeHtml(r.status || (!r.attend_date ? 'No log yet' : '')) + '</td>'
                        + '<td>' + actionButtons(r) + '</td>'
                        + '</tr>';
                }).join('') : '<tr><td colspan="11" class="text-center text-muted py-4">No logs yet.</td></tr>';
            }

            // The superadmin overview lists a handful of admins; render it
            // directly (DataTables init can hang the page on this dataset in
            // some browsers), keeping the full DataTables experience for admins.
            if (sessionRole === 'superadmin') {
                renderManual();
            } else if (window.jQuery && window.jQuery.fn && window.jQuery.fn.dataTable) {
                try {
                var rows = logs.map(row);

                if (window.jQuery.fn.DataTable.isDataTable('#attendanceTable')) {
                    // Update the existing grid in place — destroying and
                    // re-creating it on every 30s auto-refresh is fragile and
                    // can wedge the page. clear/rows.add/draw keeps the same
                    // instance (and its bound handlers) alive.
                    var existingDt = window.jQuery('#attendanceTable').DataTable();
                    existingDt.clear();
                    existingDt.rows.add(rows);
                    existingDt.draw();
                    dt = existingDt;
                    return;
                }

                dt = window.jQuery('#attendanceTable').DataTable({
                    data: rows,
                    columns: [
                        { title: 'QR Code ID' },
                        { title: 'Full Name' },
                        { title: 'Photo' },
                        { title: 'Job Title' },
                        { title: 'Date' },
                        { title: 'Time In (AM)' },
                        { title: 'Time Out (AM)' },
                        { title: 'Time In (PM)' },
                        { title: 'Time Out (PM)' },
                        { title: 'Status' },
                        { title: 'Actions', orderable: false, searchable: false }
                    ],
                    order: [[4, 'desc']],
                    pageLength: 25,
                    lengthMenu: [10, 25, 50, 100],
                    autoWidth: false,
                    responsive: true,
                    destroy: true,
                    dom: "<'row dt-row'<'col-sm-12'tr>>" +
                         "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>"
                });
                // Bind external length/search controls (kept outside the DataTable wrapper
                // so they are not affected when the table is moved).
                try {
                    var $aLenSel = window.jQuery('#attendanceLengthSelect');
                    if ($aLenSel.length && !$aLenSel.data('boundAttendance')) {
                        $aLenSel.on('change.attendanceExt', function () {
                            var v = parseInt(window.jQuery(this).val(), 10);
                            if (dt && !isNaN(v)) {
                                dt.page.len(v).draw(false);
                            }
                        }).data('boundAttendance', true);
                    }
                    var $aSearchInp = window.jQuery('#attendanceSearchInput');
                    if ($aSearchInp.length && !$aSearchInp.data('boundAttendance')) {
                        var attendanceSearchTimer = null;
                        $aSearchInp.on('keyup.attendanceExt input.attendanceExt', function () {
                            if (attendanceSearchTimer) clearTimeout(attendanceSearchTimer);
                            var v = this.value;
                            attendanceSearchTimer = setTimeout(function () {
                                if (dt) dt.search(v).draw(false);
                            }, 250);
                        }).data('boundAttendance', true);
                    }
                    // Sync external controls to current DataTable state
                    if ($aLenSel.length) $aLenSel.val(String(dt.page.len()));
                } catch (eExt) { /* noop */ }
                // Move the "Showing X to Y of Z entries" text and the Previous/Next
                // pagination OUT of the horizontally scrollable table wrapper into
                // the external footer (below the scroll area), so sliding the table
                // never moves them. Runs on every draw (idempotent re-parent).
                var aInfoTarget = el('attendancePageInfo');
                var aPagNav = el('attendancePaginationNav');
                function aRelocateFooter() {
                    if (!aInfoTarget || !aPagNav || !dt) return;
                    var wrap = window.jQuery('#attendanceTable').closest('.dataTables_wrapper');
                    if (!wrap.length) return;
                    var infoN = wrap.find('.dataTables_info').first();
                    var pagN = wrap.find('.dataTables_paginate').first();
                    if (infoN.length && infoN[0].parentNode !== aInfoTarget) {
                        aInfoTarget.innerHTML = '';
                        aInfoTarget.appendChild(infoN[0]);
                    }
                    if (pagN.length && pagN[0].parentNode !== aPagNav) {
                        var oldUl = el('attendancePagination');
                        if (oldUl && oldUl.parentNode === aPagNav) aPagNav.removeChild(oldUl);
                        pagN[0].classList.add('pagination-sm');
                        aPagNav.appendChild(pagN[0]);
                    }
                    // Hide the (now empty) DataTables footer row inside the wrapper
                    // (scan the wrapper's own rows — closest('.row') no longer works
                    // once the elements have been moved out of it)
                    wrap.find('> .row, > div > .row').each(function () {
                        var rowEl = this;
                        if (!window.jQuery(rowEl).find('.dataTables_info, .dataTables_paginate, table').length) {
                            rowEl.style.display = 'none';
                        }
                    });
                }
                try {
                    dt.on('draw.dt', aRelocateFooter);
                    aRelocateFooter();
                } catch (eReloc) {}
                } catch (eDataTables) {
                    // Never leave the table stuck on "Loading..." (or let the
                    // page hang) if DataTables fails on the current data —
                    // destroy any half-built instance and fall back to plain
                    // rendering so the table always works.
                    try {
                        if (dt && dt.destroy) dt.destroy();
                    } catch (eD2) {}
                    dt = null;
                    renderManual();
                }
            } else {
                renderManual();
            }
        }

        function renderPagination() {
            var info = el('attendancePageInfo');
            var pag = el('attendancePagination');
            if (!pag) return;
            if (info) {
                var start = totalRows === 0 ? 0 : (currentPage - 1) * perPage + 1;
                var end = Math.min(currentPage * perPage, totalRows);
                info.textContent = 'Showing ' + start + '-' + end + ' of ' + totalRows + ' records';
            }
            if (totalPages <= 1) { pag.innerHTML = ''; return; }
            var html = '';
            html += '<li class="page-item' + (currentPage <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (currentPage - 1) + '">&laquo;</a></li>';
            var startPage = Math.max(1, currentPage - 2);
            var endPage = Math.min(totalPages, currentPage + 2);
            for (var p = startPage; p <= endPage; p++) {
                html += '<li class="page-item' + (p === currentPage ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
            }
            html += '<li class="page-item' + (currentPage >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (currentPage + 1) + '">&raquo;</a></li>';
            pag.innerHTML = html;
        }

        function refreshLogs() {
            var dateFilter = el('attendanceDateFilter');
            var dateVal = dateFilter ? dateFilter.value : '';
            var url = apiBase + '&action=list_logs&page=' + currentPage + '&per_page=' + perPage;
            if (dateVal) url += '&date=' + encodeURIComponent(dateVal);
            return fetchJson(url, { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) return;
                logs = Array.isArray(res.data.logs) ? res.data.logs : [];
                totalRows = res.data.total || 0;
                totalPages = res.data.total_pages || 1;
                currentPage = res.data.page || 1;
                renderTable();
                renderPagination();
            });
        }

        function startLiveClock() {
            if (clockTimer) clearInterval(clockTimer);
            function tick() {
                var now = new Date();
                var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                var y = now.getFullYear();
                var mo = months[now.getMonth()];
                var d = now.getDate();
                var h = now.getHours();
                var ampm = h >= 12 ? 'PM' : 'AM';
                h = h % 12 || 12;
                var mi = String(now.getMinutes()).padStart(2, '0');
                var s = String(now.getSeconds()).padStart(2, '0');
                var ts = mo + ' ' + d + ', ' + y + ' ' + h + ':' + mi + ':' + s + ' ' + ampm;
                if (el('attendanceUpdatedAt')) el('attendanceUpdatedAt').textContent = 'Updated: ' + ts;
            }
            tick();
            clockTimer = setInterval(tick, 1000);
        }

        function showScannerResult(kind, text) {
            var box = el('scannerResult');
            if (!box) return;
            box.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-warning', 'alert-secondary');
            box.classList.add(kind === 'success' ? 'alert-success' : kind === 'warning' ? 'alert-warning' : 'alert-danger');
            box.textContent = String(text || '');
        }

        function clearScannerResult() {
            var box = el('scannerResult');
            if (!box) return;
            box.classList.add('d-none');
            box.textContent = '';
        }

        function stopCamera() {
            scanning = false;
            if (scanTimer) {
                window.clearTimeout(scanTimer);
                scanTimer = null;
            }
            var v = el('scannerVideo');
            if (v) {
                try { v.pause(); } catch (e) {}
                try { v.srcObject = null; } catch (e) {}
            }
            if (stream && stream.getTracks) {
                stream.getTracks().forEach(function (t) {
                    try { t.stop(); } catch (e) {}
                });
            }
            stream = null;
            detector = null;
            jsQrPromise = null;
        }

        function startCamera() {
            clearScannerResult();
            var hint = el('scannerHint');
            if (hint) hint.textContent = 'Starting camera...';

            if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
                if (hint) hint.textContent = 'Camera API not available in this browser.';
                return Promise.resolve(false);
            }

            var constraints = {
                video: {
                    facingMode: 'user',
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                },
                audio: false
            };
            return navigator.mediaDevices.getUserMedia(constraints).catch(function () {
                return navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            }).then(function (s) {
                stream = s;
                var v = el('scannerVideo');
                if (!v) return false;
                v.srcObject = stream;
                return v.play().then(function () {
                    if (hint) hint.textContent = 'Point the camera at the QR code.';
                    return true;
                }).catch(function () {
                    if (hint) hint.textContent = 'Unable to start video.';
                    return false;
                });
            }).catch(function () {
                if (hint) hint.textContent = 'Camera permission denied or unavailable.';
                return false;
            });
        }

        function processScan(rawValue) {
            var raw = String(rawValue == null ? '' : rawValue).trim();
            if (!raw) {
                showScannerResult('warning', 'Unreadable QR code.');
                return Promise.resolve();
            }
            var snap = '';
            try {
                var v = el('scannerVideo');
                if (v && v.videoWidth && v.videoHeight) {
                    var w = Math.min(480, v.videoWidth);
                    var scale = w / v.videoWidth;
                    var h = Math.max(1, Math.floor(v.videoHeight * scale));
                    var c = document.createElement('canvas');
                    c.width = Math.max(1, Math.floor(w));
                    c.height = h;
                    var cx = c.getContext('2d');
                    if (cx) {
                        cx.drawImage(v, 0, 0, c.width, c.height);
                        snap = c.toDataURL('image/jpeg', 0.8);
                    }
                }
            } catch (e) {}

            return postForm('scan', { qr_data: raw, snapshot: snap }).then(function (res) {
                var msg = (res.data && res.data.message) ? res.data.message : 'Scan failed.';
                if (res.data && res.data.ok === true) {
                    showScannerResult('success', msg);
                    refreshLogs().catch(function () {});
                    if (window.Swal) window.Swal.fire({ icon: 'success', title: msg, timer: 1200, showConfirmButton: false });
                } else {
                    showScannerResult('danger', msg);
                    if (window.Swal) window.Swal.fire({ icon: 'error', title: 'Scan Error', text: msg, timer: 1000, showConfirmButton: false });
                }
            });
        }

        function loadJsQr() {
            if (jsQrPromise) return jsQrPromise;
            if (window.jsQR) {
                jsQrPromise = Promise.resolve(true);
                return jsQrPromise;
            }
            jsQrPromise = new Promise(function (resolve) {
                var s = document.createElement('script');
                s.src = 'assets/js/cdn-qrcode.js';
                s.async = true;
                s.onload = function () { resolve(!!window.jsQR); };
                s.onerror = function () { resolve(false); };
                document.head.appendChild(s);
            });
            return jsQrPromise;
        }

        function startScanningLoop() {
            var hint = el('scannerHint');
            scanning = true;
            cooldown = false;

            var v = el('scannerVideo');
            var canvas = document.createElement('canvas');
            var ctx = canvas.getContext('2d', { willReadFrequently: true });

            function cooldownAfterScan() {
                window.setTimeout(function () {
                    clearScannerResult();
                    cooldown = false;
                }, 1200);
            }

            function stepBarcodeDetector() {
                if (!scanning) return;
                if (!v || v.readyState < 2) {
                    scanTimer = window.setTimeout(stepBarcodeDetector, 250);
                    return;
                }
                if (cooldown) {
                    scanTimer = window.setTimeout(stepBarcodeDetector, 250);
                    return;
                }
                Promise.resolve().then(function () {
                    return detector.detect(v);
                }).then(function (codes) {
                    if (!scanning) return;
                    if (codes && codes.length && codes[0] && codes[0].rawValue) {
                        cooldown = true;
                        return processScan(codes[0].rawValue).finally(cooldownAfterScan);
                    }
                }).catch(function () {
                }).finally(function () {
                    scanTimer = window.setTimeout(stepBarcodeDetector, 150);
                });
            }

            function stepJsQr() {
                if (!scanning) return;
                if (!v || v.readyState < 2) {
                    scanTimer = window.setTimeout(stepJsQr, 250);
                    return;
                }
                if (cooldown) {
                    scanTimer = window.setTimeout(stepJsQr, 250);
                    return;
                }
                var vw = v.videoWidth || 0;
                var vh = v.videoHeight || 0;
                if (!vw || !vh || !ctx) {
                    scanTimer = window.setTimeout(stepJsQr, 200);
                    return;
                }
                var targetW = 640;
                var scale = Math.min(1, targetW / vw);
                var w = Math.max(1, Math.floor(vw * scale));
                var h = Math.max(1, Math.floor(vh * scale));
                canvas.width = w;
                canvas.height = h;
                try {
                    ctx.drawImage(v, 0, 0, w, h);
                    var img = ctx.getImageData(0, 0, w, h);
                    var code = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' });
                    if (code && code.data) {
                        cooldown = true;
                        return processScan(code.data).finally(function () {
                            cooldownAfterScan();
                            scanTimer = window.setTimeout(stepJsQr, 180);
                        });
                    }
                } catch (e) {
                }
                scanTimer = window.setTimeout(stepJsQr, 180);
            }

            if ('BarcodeDetector' in window) {
                try {
                    detector = new window.BarcodeDetector({ formats: ['qr_code'] });
                    if (hint) hint.textContent = 'Point the camera at the QR code.';
                    stepBarcodeDetector();
                    return;
                } catch (e) {
                }
            }

            if (hint) hint.textContent = 'Loading QR scanner...';
            loadJsQr().then(function (ok) {
                if (!scanning) return;
                if (!ok) {
                    if (hint) hint.textContent = 'QR scanning not supported on this device.';
                    return;
                }
                if (hint) hint.textContent = 'Point the camera at the QR code.';
                stepJsQr();
            });
        }

        function openScanner() {
            if (!el('scannerModal')) return;
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('scannerModal')).show();
        }

        function openEditModal(userId, attendDate) {
            var record = null;
            for (var i = 0; i < logs.length; i++) {
                if (String(logs[i].user_id) === String(userId) && String(logs[i].attend_date) === String(attendDate)) {
                    record = logs[i];
                    break;
                }
            }
            if (!record) {
                if (window.Swal) Swal.fire({ icon: 'error', title: 'Record not found' });
                return;
            }

            el('editUserId').value = record.user_id;
            el('editAttendDateOrig').value = record.attend_date;
            el('editFullName').value = record.full_name;
            el('editAttendDate').value = record.attend_date;
            el('editAmIn').value = formatTime24(record.am_in);
            el('editAmOut').value = formatTime24(record.am_out);
            el('editPmIn').value = formatTime24(record.pm_in);
            el('editPmOut').value = formatTime24(record.pm_out);

            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('editAttendanceModal')).show();
        }

        function saveEditAttendance() {
            var payload = {
                user_id: el('editUserId').value,
                attend_date: el('editAttendDate').value,
                am_in: el('editAmIn').value,
                am_out: el('editAmOut').value,
                pm_in: el('editPmIn').value,
                pm_out: el('editPmOut').value
            };

            postForm('edit_attendance', payload).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    var msg = (res.data && res.data.message) ? res.data.message : 'Failed to update attendance.';
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                    return;
                }
                if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('editAttendanceModal')).hide();
                try { localStorage.setItem('ctr_attendance_changed', String(Date.now())); } catch (e) {}
                refreshLogs().catch(function () {});
                if (window.Swal) Swal.fire({ icon: 'success', title: 'Attendance updated successfully', timer: 1500, showConfirmButton: false });
            });
        }

        function openDeleteModal(userId, attendDate, fullName) {
            el('deleteUserId').value = userId;
            el('deleteAttendDate').value = attendDate;
            el('deleteAttendanceLabel').textContent = fullName + ' - ' + attendDate;
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('deleteAttendanceModal')).show();
        }

        function confirmDeleteAttendance() {
            var payload = {
                user_id: el('deleteUserId').value,
                attend_date: el('deleteAttendDate').value
            };

            postForm('delete_attendance', payload).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    var msg = (res.data && res.data.message) ? res.data.message : 'Failed to delete attendance.';
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                    return;
                }
                if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('deleteAttendanceModal')).hide();
                try { localStorage.setItem('ctr_attendance_changed', String(Date.now())); } catch (e) {}
                refreshLogs().catch(function () {});
                if (window.Swal) Swal.fire({ icon: 'success', title: 'Attendance record deleted', timer: 1500, showConfirmButton: false });
            });
        }

        function openPrintDtrModal(userId, fullName) {
            el('dtrUserId').value = userId;
            el('dtrEmployeeName').value = fullName;
            var today = new Date();
            var firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            el('dtrDateFrom').value = firstDay.toISOString().split('T')[0];
            el('dtrDateTo').value = today.toISOString().split('T')[0];
            el('dtrPreviewContainer').style.display = 'none';
            el('printDtrBtn').style.display = 'none';
            if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('printDtrModal')).show();
        }

        function buildDtrHtml(user, records, dateFrom, dateTo) {
            var inChargeName = '';
            try {
                if (document.getElementById('adminPlanUsageBtn')) {
                    var n = document.querySelector('.nxl-user-dropdown .dropdown-header .fw-semibold');
                    var t = n ? n.textContent : '';
                    inChargeName = String(t || '').trim();
                }
            } catch (e) {
                inChargeName = '';
            }

            function pad2(n) {
                n = Number(n || 0);
                return (n < 10 ? '0' : '') + String(n);
            }

            function formatTimeOfficial(value) {
                var t = String(value == null ? '' : value).trim();
                if (!t) return '';
                var m = t.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
                if (!m) return '';
                var hh = Number(m[1] || 0);
                var mm = String(m[2] || '00');
                var h12 = hh % 12;
                if (h12 === 0) h12 = 12;
                return pad2(h12) + ':' + mm;
            }

            var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            var df = dateFrom ? new Date(dateFrom + 'T00:00:00') : null;
            var dt2 = dateTo ? new Date(dateTo + 'T00:00:00') : null;
            var ref = df || dt2 || new Date();
            var monthLabel = monthNames[ref.getMonth()] + ' ' + ref.getFullYear();

            var byDay = {};
            (records || []).forEach(function (r) {
                if (!r || !r.attend_date) return;
                var d = new Date(String(r.attend_date) + 'T00:00:00');
                var day = d.getDate();
                byDay[String(day)] = {
                    am_in: formatTimeOfficial(r.am_in),
                    am_out: formatTimeOfficial(r.am_out),
                    pm_in: formatTimeOfficial(r.pm_in),
                    pm_out: formatTimeOfficial(r.pm_out)
                };
            });

            function buildFrontPage() {
                var rows = '';
                for (var i = 1; i <= 31; i++) {
                    var rec = byDay[String(i)] || {};
                    rows += ''
                        + '<tr>'
                        + '<td class="c day">' + i + '</td>'
                        + '<td class="c time">' + (rec.am_in || '') + '</td>'
                        + '<td class="c time">' + (rec.am_out || '') + '</td>'
                        + '<td class="c time">' + (rec.pm_in || '') + '</td>'
                        + '<td class="c time">' + (rec.pm_out || '') + '</td>'
                        + '<td class="c utH"></td>'
                        + '<td class="c utM"></td>'
                        + '</tr>';
                }
                var inChargeHtml = inChargeName ? ('      <div class="sig-name">' + escapeHtml(inChargeName) + '</div>') : '';

                return ''
                    + '<div class="dtr-form">'
                    + '  <div class="csf">CS Form 48</div>'
                    + '  <div class="title">DAILY TIME RECORD</div>'
                    + '  <div class="name-field">'
                    + '    <div class="name-value">' + escapeHtml(user.name || '') + '</div>'
                    + '    <div class="name-cap">Name</div>'
                    + '  </div>'
                    + '  <div class="meta">'
                    + '    <div class="meta-row"><span class="lbl">For the month of</span><span class="fill underline-text">' + escapeHtml(monthLabel) + '</span></div>'
                    + '    <div class="meta-row"><span class="lbl">Office Hours (regular days)</span><span class="fill"></span></div>'
                    + '    <div class="meta-row"><span class="lbl">Arrival & Departure</span><span class="fill"></span></div>'
                    + '    <div class="meta-row"><span class="lbl">Saturdays</span><span class="fill"></span></div>'
                    + '  </div>'
                    + '  <table class="dtr">'
                    + '    <thead>'
                    + '      <tr>'
                    + '        <th rowspan="2" class="day"></th>'
                    + '        <th colspan="2" class="block">AM</th>'
                    + '        <th colspan="2" class="block">PM</th>'
                    + '        <th rowspan="2" class="block">Hours</th>'
                    + '        <th rowspan="2" class="block">Min.</th>'
                    + '      </tr>'
                    + '      <tr>'
                    + '        <th class="sub">Arri-<br>val</th>'
                    + '        <th class="sub">Depar-<br>ture</th>'
                    + '        <th class="sub">Arri-<br>val</th>'
                    + '        <th class="sub">Depar-<br>Ture</th>'
                    + '      </tr>'
                    + '    </thead>'
                    + '    <tbody>'
                    + rows
                    + '    </tbody>'
                    + '  </table>'
                    + '  <div class="dtr-total">'
                    + '    <span class="total-lbl">Total</span>'
                    + '    <span class="total-line"></span>'
                    + '  </div>'
                    + '  <div class="footer-area">'
                    + '    <div class="cert">I certify on my honor that the above is true and correct record of the hours of work performed of which was made daily at the time of arrival and departure from the office.</div>'
                    + '    <div class="sig-group">'
                    + '      <div class="sig-line"></div>'
                    + '      <div class="sig-cap">(Signature)</div>'
                    + '    </div>'
                    + '    <div class="verify">Verified as to the prescribed office hours</div>'
                    + '    <div class="sig-group">'
                    + inChargeHtml
                    + '      <div class="sig-line"></div>'
                    + '      <div class="sig-cap">(In-charge)</div>'
                    + '    </div>'
                    + '  </div>'
                    + '</div>';
            }

            function buildInstructionSide() {
                return ''
                    + '<div class="dtr-form">'
                    + '  <div class="inst-header">INSTRUCTIONS</div>'
                    + '  <div class="inst-ooo"><span class="ooo-line"></span><span>oOo</span><span class="ooo-line"></span></div>'
                    + '  <div class="inst-body">'
                    + '    <p>Civil Service Form No. 48, after completion, should be filed in the records of the Bureau or Office which submits the monthly report on Civil Service Form No. 3 to the Bureau of Civil Service.</p>'
                    + '    <p>In lieu of the above, court interpreters and stenographers who accompany the judges of the Court of First Instance will fill out the daily time reports on this form in triplicate, after which they should be approved by the judge with whom service has been rendered, or by an officer of the Department of Justice authorized to do so. The original should be forwarded promptly after the end of the month to the Bureau of Civil Service, thru the Department of Justice; and the triplicate in the office of the Clerk of Court where the service was rendered.</p>'
                    + '    <p>In the space provided for the purpose on the other side will be indicated the office hours the employee is required to observe; as for example, Regular days, 8:00 -12:00 and 1:00 - 4:00; Saturdays, 8:00 - 1:00".</p>'
                    + '    <p>Attention is invited to paragraph 3, Civil Service Rule XV, Executive Order No. 5, series of 1909, which read as follows:</p>'
                    + '    <p>Each Chief of a Bureau or Office shall require a daily record of attendance of all the officers and employees under him entitled to leave of absence or vacation (including teachers) to be kept on the proper form and also systematic office record showing for each day all absences from duty from any cause whatever. At the beginning of each month he shall report to the Commissioner on the proper form all absences from any cause whatever, including the exact amount of undertime of each person for each day. Officers and employees serving in the field or on the water need not be required to keep a daily record, but all absences of such employees must be included in the monthly report of changes and absences. Falsification of time records will render the offending officer or employee liable to summary removal from the service and criminal prosecution."</p>'
                    + '    <div class="inst-divider">║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║ ║</div>'
                    + '    <p class="inst-note">(NOTE. - A record made from memory at sometime subsequent to the occurrence of an event is not reliable. Non-observance of office hours deprives the employee of the leave privileges although he may have rendered overtime service. Where service rendered outside of the office of the whole morning or afternoon, notation to that effect should be made clearly.)</p>'
                    + '  </div>'
                    + '</div>';
            }

            return '<!DOCTYPE html>'
                + '<html><head>'
                + '<meta charset="utf-8">'
                + '<title>Daily Time Record - ' + escapeHtml(user.name) + '</title>'
                + '<style>'
                + '@page { size: a4; margin: 0.5in; }'
                + 'html, body { margin: 0; padding: 0; background: #fff; }'
                + 'body { font-family: "Times New Roman", Times, serif; color: #000; }'
                + '.page { page-break-after: always; width: 100%; box-sizing: border-box; }'
                + '.page-side-by-side { display: flex; justify-content: center; align-items: flex-start; gap: 1.2in; }'
                + '.dtr-form { width: 2.8in; display: flex; flex-direction: column; }'
                + '.csf { font-size: 7pt; text-align: left; margin-bottom: 2px; }'
                + '.title { text-align:center; font-weight: 700; font-size: 10.5pt; }'
                + '.name-field { text-align: center; margin: 8px 0 6px; }'
                + '.name-value { font-weight: 700; font-size: 9.5pt; border-bottom: 1px solid #000; display: inline-block; min-width: 90%; padding-bottom: 1px; line-height: 1.2; }'
                + '.name-cap { font-size: 7pt; margin-top: 1px; }'
                + '.meta { font-size: 7.8pt; margin-bottom: 4px; }'
                + '.meta-row { display:flex; align-items:flex-end; gap: 3px; margin: 0px 0; }'
                + '.lbl { white-space:nowrap; }'
                + '.fill { flex:1; border-bottom: 1px solid #000; margin-left: 3px; min-height: 10px; }'
                + '.underline-text { text-align: center; }'
                + 'table.dtr { width: 100%; border-collapse: collapse; border: 1.2px solid #000; }'
                + 'table.dtr th, table.dtr td { border: 1px solid #000; padding: 0px 1px; font-size: 6.8pt; height: 11pt; }'
                + 'table.dtr th { text-align:center; font-weight: 700; }'
                + 'table.dtr th.block { font-size: 7.2pt; }'
                + 'table.dtr th.sub { font-size: 6.5pt; line-height: 1; vertical-align: middle; }'
                + 'td.c { text-align:center; }'
                + 'td.day { font-weight: 700; width: 7%; }'
                + 'td.time { font-weight: 700; }'
                + '.dtr-total { display: flex; align-items: flex-end; justify-content: flex-end; margin-top: 2px; padding: 2px 0; }'
                + '.total-lbl { font-size: 7pt; font-weight: 700; white-space: nowrap; margin-right: 3px; }'
                + '.total-line { width: 40%; border-bottom: 1px solid #000; min-height: 10px; }'
                + '.cert { font-size: 7pt; line-height: 1.1; text-align: justify; margin-top: 5px; }'
                + '.footer-area { margin-top: 5px; }'
                + '.sig-group { margin-top: 25px; }'
                + '.sig-name { text-align:center; font-weight: 700; font-size: 8.5pt; line-height: 1.1; min-height: 10pt; }'
                + '.sig-line { width: 100%; border-bottom: 1px solid #000; }'
                + '.sig-cap { text-align:center; font-size: 7.5pt; margin-top: 1px; }'
                + '.verify { font-size: 7.5pt; margin-top: 8px; }'
                + '.inst-header { text-align:center; font-weight: 700; font-size: 13pt; margin-top: 10px; }'
                + '.inst-ooo { display: flex; align-items: center; justify-content: center; gap: 5px; margin: 2px 0 5px; font-size: 9pt; }'
                + '.ooo-line { flex: 1; border-bottom: 1px solid #000; }'
                + '.inst-body { font-size: 8.8pt; line-height: 1.35; text-align: justify; padding: 0 3px; }'
                + '.inst-body p { margin: 7px 0; text-indent: 20px; }'
                + '.inst-divider { text-align: center; font-size: 9pt; margin: 10px 0; }'
                + '.inst-note { font-size: 7.8pt !important; line-height: 1.2; text-indent: 0 !important; }'
                + '@media print { .page { break-after: page; } }'
                + '</style>'
                + '</head><body>'
                + '<div class="page"><div class="page-side-by-side">'
                + buildFrontPage()
                + buildFrontPage()
                + '</div></div>'
                + '<div class="page"><div class="page-side-by-side">'
                + buildInstructionSide()
                + buildInstructionSide()
                + '</div></div>'
                + '</body></html>';
        }

        function generateDtr() {
            var userId = el('dtrUserId').value;
            var dateFrom = el('dtrDateFrom').value;
            var dateTo = el('dtrDateTo').value;

            var url = apiBase + '&action=get_dtr&user_id=' + encodeURIComponent(userId);
            if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
            if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);

            fetchJson(url, { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    var msg = (res.data && res.data.message) ? res.data.message : 'Failed to generate DTR.';
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                    return;
                }

                var user = res.data.user;
                var records = res.data.records;

                var dtrHtml = buildDtrHtml(user, records, dateFrom, dateTo);

                var iframe = el('dtrPreviewFrame');
                var doc = iframe.contentDocument || iframe.contentWindow.document;
                doc.open();
                doc.write(dtrHtml);
                doc.close();

                el('dtrPreviewContainer').style.display = 'block';
                el('printDtrBtn').style.display = 'inline-block';
            });
        }

        function printDtr() {
            var iframe = el('dtrPreviewFrame');
            var win = iframe.contentWindow;
            win.focus();
            win.print();
        }

        function renderAdminUsers(users) {
            var tbody = el('adminUsersTbody');
            if (!tbody) return;
            if (!users.length) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">No users under this admin yet.</td></tr>';
                return;
            }
            tbody.innerHTML = users.map(function (u) {
                var photo = u.image_path
                    ? '<img src="' + escapeHtml(u.image_path) + '" data-action="view-photo" style="width:44px;height:44px;object-fit:cover;border-radius:8px;cursor:pointer;" alt="Photo">'
                    : '<span class="text-muted">—</span>';
                var status = u.status || (!u.attend_date ? 'No log yet' : '');
                return '<tr>'
                    + '<td>' + photo + '</td>'
                    + '<td class="td-clip-200" title="' + escapeHtml(u.full_name || '') + '">' + escapeHtml(u.full_name || '') + '</td>'
                    + '<td>' + escapeHtml(u.id_number || '') + '</td>'
                    + '<td>' + escapeHtml(u.attend_date ? formatDate(u.attend_date) : '—') + '</td>'
                    + '<td>' + escapeHtml(formatTime12(u.am_in || '')) + '</td>'
                    + '<td>' + escapeHtml(formatTime12(u.am_out || '')) + '</td>'
                    + '<td>' + escapeHtml(formatTime12(u.pm_in || '')) + '</td>'
                    + '<td>' + escapeHtml(formatTime12(u.pm_out || '')) + '</td>'
                    + '<td>' + escapeHtml(status) + '</td>'
                    + '</tr>';
            }).join('');
        }

        function loadAdminUsers(adminId) {
            fetchJson(apiBase + '&action=list_admin_users&admin_id=' + encodeURIComponent(adminId), { method: 'GET' }).then(function (res) {
                if (!res.data || res.data.ok !== true) {
                    var tbody = el('adminUsersTbody');
                    if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Failed to load user logins.</td></tr>';
                    var metaEl = el('adminUsersMeta');
                    if (metaEl) metaEl.textContent = 'Could not load user logins.';
                    return;
                }
                var admin = res.data.admin || {};
                var users = Array.isArray(res.data.users) ? res.data.users : [];
                var titleEl = el('adminUsersTitle');
                if (titleEl) titleEl.textContent = admin.name || ('Admin #' + adminId);
                var metaEl = el('adminUsersMeta');
                if (metaEl) metaEl.textContent = users.length + (users.length === 1 ? ' user' : ' users') + ' under this admin';
                renderAdminUsers(users);
            }).catch(function () {
                var tbody = el('adminUsersTbody');
                if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Failed to load user logins.</td></tr>';
            });
        }

        function openAdminUsersModal(adminId, adminName) {
            if (!adminId) return;
            var titleEl = el('adminUsersTitle');
            if (titleEl) titleEl.textContent = adminName || ('Admin #' + adminId);
            var metaEl = el('adminUsersMeta');
            if (metaEl) metaEl.textContent = 'Loading user logins...';
            var tbody = el('adminUsersTbody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Loading...</td></tr>';
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(el('adminUsersModal')).show();
            }
            loadAdminUsers(adminId);
        }

        function initHandlers() {
            bindOnce(el('attendancePagination'), 'click', 'paginationClick', function (e) {
                var link = e.target && e.target.closest ? e.target.closest('a[data-page]') : null;
                if (!link) return;
                e.preventDefault();
                var page = parseInt(link.getAttribute('data-page'), 10);
                if (page < 1 || page > totalPages) return;
                currentPage = page;
                refreshLogs().catch(function () {});
            });
            bindOnce(el('attendanceTable'), 'click', 'tableClick', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('button[data-action]') : null;
                if (btn) {
                    var action = btn.getAttribute('data-action');
                    var userId = btn.getAttribute('data-user-id');
                    var date = btn.getAttribute('data-date');
                    var name = btn.getAttribute('data-name');

                    if (action === 'edit') openEditModal(userId, date);
                    else if (action === 'delete') openDeleteModal(userId, date, name);
                    else if (action === 'print') openPrintDtrModal(userId, name);
                    else if (action === 'user-logins') openAdminUsersModal(userId, name);
                    return;
                }

                var img = e.target && e.target.closest ? e.target.closest('img[data-action="view-photo"]') : null;
                if (img) {
                    var src = img.getAttribute('src');
                    if (src) {
                        el('viewImageTarget').src = src;
                        if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('imageViewModal')).show();
                    }
                }
            });

            bindOnce(el('adminUsersTbody'), 'click', 'adminUsersImgClick', function (e) {
                var img = e.target && e.target.closest ? e.target.closest('img[data-action="view-photo"]') : null;
                if (img) {
                    var src = img.getAttribute('src');
                    if (src) {
                        el('viewImageTarget').src = src;
                        if (window.bootstrap && window.bootstrap.Modal) window.bootstrap.Modal.getOrCreateInstance(el('imageViewModal')).show();
                    }
                }
            });

            bindOnce(el('editAttendanceForm'), 'submit', 'editSubmit', function (e) {
                e.preventDefault();
                saveEditAttendance();
            });

            bindOnce(el('confirmDeleteAttendanceBtn'), 'click', 'deleteConfirm', function () {
                confirmDeleteAttendance();
            });

            bindOnce(el('generateDtrBtn'), 'click', 'generateDtr', function () { generateDtr(); });
            bindOnce(el('printDtrBtn'), 'click', 'printDtr', function () { printDtr(); });

            // Schedule Settings
            bindOnce(el('openScheduleSettingsBtn'), 'click', 'schedOpen', function (e) {
                e.preventDefault();
                openScheduleModal();
            });
            bindOnce(el('saveScheduleBtn'), 'click', 'schedSave', function (e) {
                e.preventDefault();
                saveScheduleSettings();
            });
            bindOnce(el('resetScheduleBtn'), 'click', 'schedReset', function (e) {
                e.preventDefault();
                resetScheduleSettings();
            });
            bindOnce(el('saveUserScheduleBtn'), 'click', 'userSchedSave', function (e) {
                e.preventDefault();
                saveUserScheduleSettings();
            });
            bindOnce(el('resetUserScheduleBtn'), 'click', 'userSchedReset', function (e) {
                e.preventDefault();
                resetUserScheduleSettings();
            });
            bindOnce(el('openAllSchedulesBtn'), 'click', 'allSchedOpen', function (e) {
                e.preventDefault();
                openAllSchedulesModal();
            });
            bindOnce(el('allSchedSearchInput'), 'input', 'allschedsearch', function () {
                var inp = el('allSchedSearchInput');
                if (!inp) return;
                if (allSchedSearchTimer) clearTimeout(allSchedSearchTimer);
                allSchedSearchTimer = setTimeout(function () {
                    allSchedQuery = inp.value;
                    allSchedPage = 1;
                    renderAllSchedules();
                }, 250);
            });
            bindOnce(el('allSchedLengthSelect'), 'change', 'allschedlen', function () {
                var sel = el('allSchedLengthSelect');
                if (!sel) return;
                var v = parseInt(sel.value, 10);
                if (!isNaN(v) && v > 0) allSchedPerPage = v;
                allSchedPage = 1;
                renderAllSchedules();
            });
            bindOnce(el('allSchedPagination'), 'click', 'allschedpag', function (e) {
                var link = e.target && e.target.closest ? e.target.closest('a[data-sched-page]') : null;
                if (!link) return;
                e.preventDefault();
                var p = parseInt(link.getAttribute('data-sched-page'), 10);
                if (isNaN(p)) return;
                allSchedPage = p;
                renderAllSchedules();
            });

            // Attendance Records
            bindOnce(el('openAttendanceRecordsBtn'), 'click', 'attRecordsOpen', function (e) {
                e.preventDefault();
                openAttendanceRecordsModal();
            });
            bindOnce(el('attRecordsLoadBtn'), 'click', 'attRecordsLoad', function () { loadAttendanceRecords(); });
            bindOnce(el('attRecordsMonth'), 'change', 'attRecordsMonth', function () { loadAttendanceRecords(); });
            bindOnce(el('attRecordsPrintBtn'), 'click', 'attRecordsPrint', function () { printAttendanceRecords(); });
            bindOnce(el('attRecordsExportCsv'), 'click', 'attRecordsExportCsv', function (e) { e.preventDefault(); exportAttendanceRecords('csv'); });
            bindOnce(el('attRecordsExportXls'), 'click', 'attRecordsExportXls', function (e) { e.preventDefault(); exportAttendanceRecords('xls'); });
        }

        /* ===== Schedule Settings ===== */

        function loadScheduleSettings() {
            fetch(apiBase + '&action=get_schedule_settings', { method: 'GET', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) {
                        if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to load schedule settings.' });
                        return;
                    }
                    function fmt(t) {
                        if (!t || t === '') return '';
                        var parts = t.split(':');
                        return parts[0] + ':' + (parts[1] || '00');
                    }
                    if (el('schedAmIn')) el('schedAmIn').value = fmt(res.am_in);
                    if (el('schedAmOut')) el('schedAmOut').value = fmt(res.am_out);
                    if (el('schedPmIn')) el('schedPmIn').value = fmt(res.pm_in);
                    if (el('schedPmOut')) el('schedPmOut').value = fmt(res.pm_out);
                })
                .catch(function () {
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: 'Network error loading schedule.' });
                });
        }

        function openScheduleModal() {
            if (!el('scheduleSettingsModal')) return;
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(el('scheduleSettingsModal')).show();
            }
            loadScheduleSettings();
        }

        function saveScheduleSettings() {
            var amIn = el('schedAmIn') ? el('schedAmIn').value : '';
            var amOut = el('schedAmOut') ? el('schedAmOut').value : '';
            var pmIn = el('schedPmIn') ? el('schedPmIn').value : '';
            var pmOut = el('schedPmOut') ? el('schedPmOut').value : '';

            var body = new URLSearchParams();
            body.set('action', 'save_schedule_settings');
            body.set('am_in', amIn);
            body.set('am_out', amOut);
            body.set('pm_in', pmIn);
            body.set('pm_out', pmOut);

            var saveBtn = el('saveScheduleBtn');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<i class="feather-loader me-1"></i>Saving...';
            }

            fetch(apiBase, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
                body: body.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="feather-save me-1"></i>Save Schedule';
                }
                if (!res || !res.ok) {
                    var msg = (res && res.message) ? res.message : 'Failed to save.';
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                    return;
                }
                if (window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getInstance(el('scheduleSettingsModal')).hide();
                }
                if (window.Swal) Swal.fire({ icon: 'success', title: 'Schedule saved', timer: 1200, showConfirmButton: false });
            })
            .catch(function () {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="feather-save me-1"></i>Save Schedule';
                }
                if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: 'Network error.' });
            });
        }

        function resetScheduleSettings() {
            var resetBtn = el('resetScheduleBtn');
            if (resetBtn) {
                resetBtn.disabled = true;
                resetBtn.innerHTML = '<i class="feather-loader me-1"></i>Resetting...';
            }

            var body = new URLSearchParams();
            body.set('action', 'reset_schedule_settings');

            fetch(apiBase, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
                body: body.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (resetBtn) {
                    resetBtn.disabled = false;
                    resetBtn.innerHTML = '<i class="feather-rotate-ccw me-1"></i>Reset to Defaults';
                }
                if (!res || !res.ok) {
                    var msg = (res && res.message) ? res.message : 'Failed to reset.';
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                    return;
                }
                loadScheduleSettings();
                if (window.Swal) Swal.fire({ icon: 'success', title: 'Reset to defaults', timer: 1200, showConfirmButton: false });
            })
            .catch(function () {
                if (resetBtn) {
                    resetBtn.disabled = false;
                    resetBtn.innerHTML = '<i class="feather-rotate-ccw me-1"></i>Reset to Defaults';
                }
                if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: 'Network error.' });
            });
        }

        /* ===== User Schedule Settings ===== */

        function openUserScheduleModal(u) {
            if (!el('userScheduleModal') || !u) return;
            var userId = u.id;
            var userName = u.name || u.username || '';
            var userIdNum = u.id_number || '';
            el('userSchedUserId').value = userId;
            el('userScheduleModalTitle').textContent = 'Attendance Schedule';
            // Update the user info display
            var nameEl = el('userScheduleUserName');
            var idNumEl = el('userScheduleUserIdNum');
            if (nameEl) nameEl.textContent = userName;
            if (idNumEl) idNumEl.textContent = userIdNum ? '(' + userIdNum + ')' : '';
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(el('userScheduleModal')).show();
            }
            loadUserScheduleSettings(userId);
        }

        function loadUserScheduleSettings(userId) {
            fetch(apiBase + '&action=get_user_schedule_settings&user_id=' + encodeURIComponent(String(userId)), { method: 'GET', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) {
                        // Clear fields on error
                        el('userSchedAmIn').value = '';
                        el('userSchedAmOut').value = '';
                        el('userSchedPmIn').value = '';
                        el('userSchedPmOut').value = '';
                        return;
                    }
                    // API returns am_in/am_out/pm_in/pm_out at the top level
                    function fmtTime(t) {
                        var str = String(t || '').trim();
                        if (!str) return '';
                        var parts = str.split(':');
                        return parts[0] + ':' + (parts[1] || '00');
                    }
                    if (el('userSchedAmIn')) el('userSchedAmIn').value = fmtTime(res.am_in);
                    if (el('userSchedAmOut')) el('userSchedAmOut').value = fmtTime(res.am_out);
                    if (el('userSchedPmIn')) el('userSchedPmIn').value = fmtTime(res.pm_in);
                    if (el('userSchedPmOut')) el('userSchedPmOut').value = fmtTime(res.pm_out);
                })
                .catch(function () {
                    if (el('userSchedAmIn')) el('userSchedAmIn').value = '';
                    if (el('userSchedAmOut')) el('userSchedAmOut').value = '';
                    if (el('userSchedPmIn')) el('userSchedPmIn').value = '';
                    if (el('userSchedPmOut')) el('userSchedPmOut').value = '';
                });
        }

        function saveUserScheduleSettings() {
            var userId = el('userSchedUserId').value;
            var amIn = el('userSchedAmIn') ? el('userSchedAmIn').value : '';
            var amOut = el('userSchedAmOut') ? el('userSchedAmOut').value : '';
            var pmIn = el('userSchedPmIn') ? el('userSchedPmIn').value : '';
            var pmOut = el('userSchedPmOut') ? el('userSchedPmOut').value : '';

            var body = new URLSearchParams();
            body.set('action', 'save_user_schedule_settings');
            body.set('user_id', userId);
            body.set('am_in', amIn);
            body.set('am_out', amOut);
            body.set('pm_in', pmIn);
            body.set('pm_out', pmOut);

            var saveBtn = el('saveUserScheduleBtn');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<i class="feather-loader me-1"></i>Saving...';
            }

            fetch(apiBase, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
                body: body.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="feather-save me-1"></i>Save Schedule';
                }
                if (!res || !res.ok) {
                    var msg = (res && res.message) ? res.message : 'Failed to save.';
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                    return;
                }
                if (window.Swal) Swal.fire({ icon: 'success', title: 'Saved', timer: 1200, showConfirmButton: false });
                var modal = el('userScheduleModal');
                if (modal && window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getInstance(modal).hide();
                }
            })
            .catch(function () {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="feather-save me-1"></i>Save Schedule';
                }
                if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: 'Network error.' });
            });
        }

        function resetUserScheduleSettings() {
            var userId = el('userSchedUserId').value;
            var resetBtn = el('resetUserScheduleBtn');
            if (resetBtn) {
                resetBtn.disabled = true;
                resetBtn.innerHTML = '<i class="feather-loader me-1"></i>Resetting...';
            }

            var body = new URLSearchParams();
            body.set('action', 'save_user_schedule_settings');
            body.set('user_id', userId);
            body.set('am_in', '');
            body.set('am_out', '');
            body.set('pm_in', '');
            body.set('pm_out', '');

            fetch(apiBase, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
                body: body.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (resetBtn) {
                    resetBtn.disabled = false;
                    resetBtn.innerHTML = '<i class="feather-rotate-ccw me-1"></i>Reset to Admin Defaults';
                }
                if (!res || !res.ok) {
                    var msg = (res && res.message) ? res.message : 'Failed to reset.';
                    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
                    return;
                }
                loadUserScheduleSettings(userId);
                if (window.Swal) Swal.fire({ icon: 'success', title: 'Reset to admin defaults', timer: 1200, showConfirmButton: false });
            })
            .catch(function () {
                if (resetBtn) {
                    resetBtn.disabled = false;
                    resetBtn.innerHTML = '<i class="feather-rotate-ccw me-1"></i>Reset to Admin Defaults';
                }
                if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: 'Network error.' });
            });
        }

        /* ===== User Schedule Dropdown ===== */

        var userScheduleSearchTimer = null;
        var userScheduleLatestQ = '';

        function renderUserScheduleItems(users) {
            var container = el('userScheduleUsers') || el('userScheduleUserList');
            if (!container) return;
            var arr = Array.isArray(users) ? users : [];
            if (arr.length === 0) {
                container.innerHTML = '<div class="dropdown-item text-muted small">No users found.</div>';
                return;
            }
            // Sort by name
            arr.sort(function (a, b) {
                return (a.name || '').localeCompare(b.name || '');
            });
            var html = '';
            for (var i = 0; i < arr.length; i++) {
                var u = arr[i];
                var name = escapeHtml(u.name || u.username || 'Unknown');
                var idNum = escapeHtml(u.id_number || '');
                var role = escapeHtml(u.role || '');
                html += '<button type="button" class="dropdown-item" data-user-id="' + escapeHtml(u.id) + '" data-user-name="' + name + '" data-user-idnum="' + idNum + '" data-user-role="' + role + '">' + '<div class="d-flex flex-column">' + '<span class="fw-semibold">' + name + '</span>' + '<small class="text-muted">' + idNum + (role ? ' \u2022 ' + role : '') + '</small>' + '</div>' + '</button>';
            }
            container.innerHTML = html;
        }

        function loadUserScheduleDropdown(query) {
            var list = el('userScheduleUserList');
            if (!list) return;
            var loading = el('userScheduleLoading');

            var q = String(query == null ? '' : query).trim();
            userScheduleLatestQ = q;
            var url = apiBase + '&action=list_users';
            if (q !== '') url += '&search=' + encodeURIComponent(q);

            if (loading) loading.textContent = 'Loading users...';
            fetch(url, { method: 'GET', cache: 'no-store' })
                .then(function (r) {
                    if (!r.ok) {
                        if (loading && q === userScheduleLatestQ) loading.textContent = 'Server error (' + r.status + ').';
                        throw new Error('HTTP ' + r.status);
                    }
                    return r.json();
                })
                .then(function (res) {
                    if (q !== userScheduleLatestQ) return; // stale response
                    if (!res || !res.ok || !res.users) {
                        if (loading) loading.textContent = (res && res.message) ? res.message : 'Failed to load users.';
                        return;
                    }
                    renderUserScheduleItems(res.users || []);
                })
                .catch(function (err) {
                    console.error('loadUserScheduleDropdown error:', err);
                    if (loading) loading.textContent = 'Network error.';
                });
        }

        function initUserScheduleDropdown() {
            var list = el('userScheduleUserList');
            if (!list) return;

            // Handle user selection from dropdown
            list.addEventListener('click', function (e) {
                var btn = e.target.closest('button[data-user-id]');
                if (!btn) return;
                e.preventDefault();
                e.stopPropagation();

                var userId = btn.getAttribute('data-user-id');
                var userName = btn.getAttribute('data-user-name') || '';
                var userIdNum = btn.getAttribute('data-user-idnum') || '';
                var userRole = btn.getAttribute('data-user-role') || '';

                if (!userId) return;

                // Open user schedule modal with this user
                openUserScheduleModal({
                    id: userId,
                    name: userName,
                    id_number: userIdNum,
                    role: userRole
                });

                // Close the dropdown
                var dropdown = list.closest('.dropdown');
                if (dropdown && window.bootstrap && window.bootstrap.Dropdown) {
                    var dd = window.bootstrap.Dropdown.getInstance(dropdown.querySelector('[data-bs-toggle]'));
                    if (dd) dd.hide();
                }
            });

            // Load users when dropdown is shown
            var dropdownBtn = el('userScheduleDropdown');
            if (dropdownBtn) {
                dropdownBtn.addEventListener('show.bs.dropdown', function () {
                    var inp = el('userScheduleSearch');
                    if (inp) inp.value = '';
                    loadUserScheduleDropdown('');
                });
            }

            // Live AJAX search while typing in the dropdown search box
            var searchInput = el('userScheduleSearch');
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    var q = searchInput.value;
                    if (userScheduleSearchTimer) clearTimeout(userScheduleSearchTimer);
                    userScheduleSearchTimer = setTimeout(function () {
                        var loading = el('userScheduleLoading');
                        if (loading) loading.textContent = 'Searching users...';
                        loadUserScheduleDropdown(q);
                    }, 250);
                });
                // Keep the dropdown open while the search box is focused
                searchInput.addEventListener('click', function (e) { e.stopPropagation(); });
            }
        }

        /* ===== All Users Schedule ===== */

        function fmtSchedTime(t) {
            var str = String(t == null ? '' : t).trim();
            if (!str) return '\u2014';
            var parts = str.split(':');
            return parts[0] + ':' + (parts[1] || '00');
        }

        // Client-side search / pagination state for the All Schedules table
        var allSchedUsers = [];
        var allSchedQuery = '';
        var allSchedPage = 1;
        var allSchedPerPage = 10;
        var allSchedSearchTimer = null;
        var attRecords = [];

        function openAllSchedulesModal() {
            var modalEl = el('allSchedulesModal');
            if (!modalEl) return;
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
            loadAllSchedules();
        }

        function loadAllSchedules() {
            var tbody = el('allSchedulesTbody');
            if (!tbody) return;
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr>';
            fetch(apiBase + '&action=list_all_schedules', { method: 'GET', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok || !res.users) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">' + escapeHtml((res && res.message) || 'Failed to load schedules.') + '</td></tr>';
                        return;
                    }
                    allSchedUsers = Array.isArray(res.users) ? res.users : [];
                    allSchedQuery = '';
                    allSchedPage = 1;
                    var searchInput = el('allSchedSearchInput');
                    if (searchInput) searchInput.value = '';
                    var lenSel = el('allSchedLengthSelect');
                    if (lenSel) lenSel.value = String(allSchedPerPage);
                    renderAllSchedules();
                })
                .catch(function (err) {
                    console.error('loadAllSchedules error:', err);
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Network error.</td></tr>';
                });
        }

        function allSchedRowHtml(u) {
            var name = escapeHtml(u.name || u.username || 'Unknown');
            var idNum = escapeHtml(u.id_number || '');
            var badge = u.has_custom
                ? '<span class="badge bg-soft-primary text-primary">Custom</span>'
                : '<span class="badge bg-light text-secondary">Default</span>';
            return '<tr>'
                + '<td><div class="d-flex flex-column"><span class="fw-semibold">' + name + '</span><small class="text-muted">' + idNum + '</small></div></td>'
                + '<td>' + fmtSchedTime(u.am_in) + '</td>'
                + '<td>' + fmtSchedTime(u.am_out) + '</td>'
                + '<td>' + fmtSchedTime(u.pm_in) + '</td>'
                + '<td>' + fmtSchedTime(u.pm_out) + '</td>'
                + '<td>' + badge + '</td>'
                + '</tr>';
        }

        function renderAllSchedules() {
            var tbody = el('allSchedulesTbody');
            if (!tbody) return;
            var q = String(allSchedQuery || '').toLowerCase().trim();
            var filtered = allSchedUsers.filter(function (u) {
                if (!q) return true;
                var hay = String(u.name || '') + ' ' + String(u.username || '') + ' ' + String(u.id_number || '');
                return hay.toLowerCase().indexOf(q) !== -1;
            });
            var total = filtered.length;
            var totalPages = Math.max(1, Math.ceil(total / allSchedPerPage));
            if (allSchedPage > totalPages) allSchedPage = totalPages;
            if (allSchedPage < 1) allSchedPage = 1;
            var startIdx = (allSchedPage - 1) * allSchedPerPage;
            var pageRows = filtered.slice(startIdx, startIdx + allSchedPerPage);
            tbody.innerHTML = total === 0
                ? '<tr><td colspan="6" class="text-center text-muted py-4">No users found.</td></tr>'
                : pageRows.map(allSchedRowHtml).join('');
            var info = el('allSchedPageInfo');
            if (info) {
                var start = total === 0 ? 0 : startIdx + 1;
                var end = Math.min(startIdx + allSchedPerPage, total);
                info.textContent = 'Showing ' + start + ' to ' + end + ' of ' + total + (total === 1 ? ' entry' : ' entries');
            }
            // Pagination bar is always rendered (Previous/Next disabled at the
            // bounds), matching the DataTables footer on user.php / attendance.php.
            var pag = el('allSchedPagination');
            if (pag) {
                var html = '';
                html += '<li class="page-item' + (allSchedPage <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-sched-page="' + (allSchedPage - 1) + '">Previous</a></li>';
                for (var p = 1; p <= totalPages; p++) {
                    html += '<li class="page-item' + (p === allSchedPage ? ' active' : '') + '"><a class="page-link" href="#" data-sched-page="' + p + '">' + p + '</a></li>';
                }
                html += '<li class="page-item' + (allSchedPage >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" data-sched-page="' + (allSchedPage + 1) + '">Next</a></li>';
                pag.innerHTML = html;
            }
        }

        function attMonthLabel(month) {
            var m = String(month || '').match(/^(\d{4})-(\d{2})$/);
            if (!m) return String(month || '');
            var names = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            var idx = parseInt(m[2], 10) - 1;
            if (idx < 0 || idx > 11) return String(month);
            return names[idx] + ' ' + m[1];
        }

        function openAttendanceRecordsModal() {
            var modalEl = el('attendanceRecordsModal');
            if (!modalEl) return;
            var monthInput = el('attRecordsMonth');
            if (monthInput && !monthInput.value) {
                var d = new Date();
                var mm = String(d.getMonth() + 1);
                if (mm.length < 2) mm = '0' + mm;
                monthInput.value = d.getFullYear() + '-' + mm;
            }
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
            loadAttendanceRecords();
        }

        function loadAttendanceRecords() {
            var monthInput = el('attRecordsMonth');
            var month = monthInput ? monthInput.value : '';
            var tbody = el('attRecordsTbody');
            var count = el('attRecordsCount');
            if (!month) {
                if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Choose a month then click Load Records.</td></tr>';
                if (count) count.textContent = '';
                return;
            }
            if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Loading...</td></tr>';
            if (count) count.textContent = '';
            fetch(apiBase + '&action=monthly_records&month=' + encodeURIComponent(month), { method: 'GET', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) {
                        if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-4">' + escapeHtml((res && res.message) || 'Failed to load records.') + '</td></tr>';
                        return;
                    }
                    attRecords = Array.isArray(res.records) ? res.records : [];
                    renderAttendanceRecords();
                })
                .catch(function (err) {
                    console.error('loadAttendanceRecords error:', err);
                    if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-4">Network error.</td></tr>';
                });
        }

        function renderAttendanceRecords() {
            var tbody = el('attRecordsTbody');
            if (!tbody) return;
            var count = el('attRecordsCount');
            if (count) {
                var monthInput = el('attRecordsMonth');
                var lbl = attMonthLabel(monthInput ? monthInput.value : '');
                count.textContent = attRecords.length + (attRecords.length === 1 ? ' record' : ' records') + ' — ' + lbl;
            }
            tbody.innerHTML = attRecords.length === 0
                ? '<tr><td colspan="9" class="text-center text-muted py-4">No attendance records for this month.</td></tr>'
                : attRecords.map(function (r, i) {
                    return '<tr>'
                        + '<td>' + (i + 1) + '</td>'
                        + '<td class="td-clip-200" title="' + escapeHtml(r.full_name || '') + '">' + escapeHtml(r.full_name || '') + '</td>'
                        + '<td>' + escapeHtml(r.id_number || '') + '</td>'
                        + '<td>' + escapeHtml(formatDate(r.attend_date)) + '</td>'
                        + '<td>' + escapeHtml(formatTime12(r.am_in || '')) + '</td>'
                        + '<td>' + escapeHtml(formatTime12(r.am_out || '')) + '</td>'
                        + '<td>' + escapeHtml(formatTime12(r.pm_in || '')) + '</td>'
                        + '<td>' + escapeHtml(formatTime12(r.pm_out || '')) + '</td>'
                        + '<td>' + escapeHtml(r.status || '') + '</td>'
                        + '</tr>';
                }).join('');
        }

        function printAttendanceRecords() {
            if (!attRecords.length) {
                if (window.Swal) Swal.fire({ icon: 'warning', title: 'No records', text: 'Load attendance records for a month first.' });
                return;
            }
            var monthInput = el('attRecordsMonth');
            var month = monthInput ? monthInput.value : '';
            var rows = attRecords.map(function (r, i) {
                return '<tr>'
                    + '<td>' + (i + 1) + '</td>'
                    + '<td class="name">' + escapeHtml(r.full_name || '') + '</td>'
                    + '<td>' + escapeHtml(r.id_number || '') + '</td>'
                    + '<td>' + escapeHtml(formatDate(r.attend_date)) + '</td>'
                    + '<td>' + escapeHtml(formatTime12(r.am_in || '')) + '</td>'
                    + '<td>' + escapeHtml(formatTime12(r.am_out || '')) + '</td>'
                    + '<td>' + escapeHtml(formatTime12(r.pm_in || '')) + '</td>'
                    + '<td>' + escapeHtml(formatTime12(r.pm_out || '')) + '</td>'
                    + '<td>' + escapeHtml(r.status || '') + '</td>'
                    + '</tr>';
            }).join('');

            var style = ''
                + '@page { size: landscape; margin: 0.4in; }'
                + 'body { font-family: "Times New Roman", Times, serif; color: #000; margin: 0; }'
                + 'h2 { text-align: center; margin: 0 0 2px; font-size: 16pt; }'
                + '.sub { text-align: center; margin: 0 0 4px; font-size: 11pt; }'
                + '.meta { text-align: center; margin: 0 0 14px; font-size: 9pt; }'
                + 'table { width: 100%; border-collapse: collapse; font-size: 9.5pt; }'
                + 'th, td { border: 1px solid #000; padding: 3px 5px; text-align: center; }'
                + 'th { font-weight: 700; }'
                + 'td.name { text-align: left; }'
                + '.sig { margin-top: 46px; display: flex; justify-content: flex-end; }'
                + '.sig-box { text-align: center; }'
                + '.sig-line { width: 240px; border-bottom: 1px solid #000; height: 18px; }'
                + '.sig-cap { font-size: 10pt; }';

            var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Attendance Records</title><style>' + style + '</style></head><body>'
                + '<h2>ATTENDANCE RECORDS</h2>'
                + '<div class="sub">For the month of ' + escapeHtml(attMonthLabel(month)) + '</div>'
                + '<div class="meta">Generated: ' + escapeHtml(new Date().toLocaleString()) + '</div>'
                + '<table>'
                + '<thead><tr><th>#</th><th>Full Name</th><th>ID Number</th><th>Date</th><th>AM In</th><th>AM Out</th><th>PM In</th><th>PM Out</th><th>Status</th></tr></thead>'
                + '<tbody>' + rows + '</tbody>'
                + '</table>'
                + '<div class="sig"><div class="sig-box"><div class="sig-line"></div><div class="sig-cap">Prepared by (In-charge)</div></div></div>'
                + '</body></html>';

            var w = window.open('', '_blank');
            if (!w) return;
            w.document.open();
            w.document.write(html);
            w.document.close();
            w.focus();
            setTimeout(function () { try { w.print(); } catch (e) {} }, 250);
        }

        function downloadFile(blob, filename) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            setTimeout(function () {
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }, 400);
        }

        function exportAttendanceRecords(fmt) {
            if (!attRecords.length) {
                if (window.Swal) Swal.fire({ icon: 'warning', title: 'No records', text: 'Load attendance records for a month first.' });
                return;
            }
            var monthInput = el('attRecordsMonth');
            var month = monthInput ? monthInput.value : '';
            var stamp = month.replace(/[^0-9]/g, '') || 'records';
            var headers = ['#', 'Full Name', 'ID Number', 'Date', 'AM In', 'AM Out', 'PM In', 'PM Out', 'Status'];
            var rows = attRecords.map(function (r, i) {
                return [
                    String(i + 1),
                    r.full_name || '',
                    r.id_number || '',
                    formatDate(r.attend_date),
                    formatTime12(r.am_in || ''),
                    formatTime12(r.am_out || ''),
                    formatTime12(r.pm_in || ''),
                    formatTime12(r.pm_out || ''),
                    r.status || ''
                ];
            });

            if (fmt === 'xls') {
                // Excel 2003 SpreadsheetML — opens in Excel without format warnings
                var esc = function (s) {
                    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                };
                var rowXml = function (cells, isHeader) {
                    var cls = isHeader ? ' ss:StyleID="hdr"' : '';
                    return '<Row>' + cells.map(function (c) {
                        return '<Cell' + cls + '><Data ss:Type="String">' + esc(c) + '</Data></Cell>';
                    }).join('') + '</Row>';
                };
                var xls = '<?xml version="1.0" encoding="UTF-8"?>\n'
                    + '<?mso-application progid="Excel.Sheet"?>\n'
                    + '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">\n'
                    + '<Styles><Style ss:ID="hdr"><Font ss:Bold="1"/></Style></Styles>\n'
                    + '<Worksheet ss:Name="Attendance Records">\n<Table>\n'
                    + rowXml(headers, true) + '\n'
                    + rows.map(function (r) { return rowXml(r, false); }).join('\n') + '\n'
                    + '</Table></Worksheet></Workbook>';
                downloadFile(new Blob([xls], { type: 'application/vnd.ms-excel' }), 'attendance_records_' + stamp + '.xls');
            } else {
                // CSV with UTF-8 BOM so Excel reads names/special chars correctly
                var csvCell = function (c) {
                    var s = String(c == null ? '' : c);
                    if (/[",\r\n]/.test(s)) s = '"' + s.replace(/"/g, '""') + '"';
                    return s;
                };
                var csv = '\ufeff' + headers.map(csvCell).join(',')
                    + '\r\n' + rows.map(function (r) { return r.map(csvCell).join(','); }).join('\r\n');
                downloadFile(new Blob([csv], { type: 'text/csv;charset=utf-8;' }), 'attendance_records_' + stamp + '.csv');
            }
        }

        function init() {
            if (!el('attendanceTable')) return;
            var tbl = el('attendanceTable');
            sessionRole = (tbl.getAttribute('data-session-role') || '');
            destroy();
            initHandlers();
            initUserScheduleDropdown();
            refreshLogs().catch(function () {});
            startLiveClock();
            autoRefreshTimer = setInterval(function () {
                refreshLogs().catch(function () {});
            }, 30000);
        }

        function destroy() {
            stopCamera();
            if (clockTimer) clearInterval(clockTimer);
            clockTimer = null;
            if (autoRefreshTimer) clearInterval(autoRefreshTimer);
            autoRefreshTimer = null;
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.dataTable) {
                if (window.jQuery.fn.DataTable.isDataTable('#attendanceTable')) {
                    try { window.jQuery('#attendanceTable').DataTable().destroy(); } catch (e) {}
                }
            }
            dt = null;
        }

        return { init: init, destroy: destroy };
    })();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', App.init);
    } else {
        App.init();
    }
})();
