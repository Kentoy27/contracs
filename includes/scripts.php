<?php
$assetVer = static function (string $absPath): string {
    $t = @filemtime($absPath);
    return $t ? ('v=' . rawurlencode((string)$t)) : ('v=' . rawurlencode((string)time()));
};
?>
<script src="assets/vendors/js/vendors.min.js?<?php echo $assetVer(__DIR__ . '/../assets/vendors/js/vendors.min.js'); ?>"></script>
<script src="assets/vendors/js/daterangepicker.min.js?<?php echo $assetVer(__DIR__ . '/../assets/vendors/js/daterangepicker.min.js'); ?>"></script>
<script src="assets/vendors/js/apexcharts.min.js?<?php echo $assetVer(__DIR__ . '/../assets/vendors/js/apexcharts.min.js'); ?>"></script>
<script src="assets/vendors/js/circle-progress.min.js?<?php echo $assetVer(__DIR__ . '/../assets/vendors/js/circle-progress.min.js'); ?>"></script>
<script src="assets/js/common-init.min.js?<?php echo $assetVer(__DIR__ . '/../assets/js/common-init.min.js'); ?>"></script>
<script src="assets/vendors/js/dataTables.min.js?<?php echo $assetVer(__DIR__ . '/../assets/vendors/js/dataTables.min.js'); ?>"></script>
<script src="assets/vendors/js/dataTables.bs5.min.js?<?php echo $assetVer(__DIR__ . '/../assets/vendors/js/dataTables.bs5.min.js'); ?>"></script>
<script src="assets/vendors/js/sweetalert2.min.js?<?php echo $assetVer(__DIR__ . '/../assets/vendors/js/sweetalert2.min.js'); ?>"></script>
<script src="assets/js/source-guard.js?<?php echo $assetVer(__DIR__ . '/../assets/js/source-guard.js'); ?>"></script>
<script src="assets/js/app-main.js?<?php echo $assetVer(__DIR__ . '/../assets/js/app-main.js'); ?>"></script>
<script>
/* ConTracS CSRF guard: attach the per-session token to every same-origin
   state-changing request (fetch, XMLHttpRequest, native form posts). */
(function () {
    if (window.__ctrCsrfPatched) return;
    window.__ctrCsrfPatched = true;

    function csrfToken() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? (m.getAttribute('content') || '') : '';
    }

    function sameOrigin(url) {
        try {
            var u = new URL(String(url), window.location.href);
            return u.origin === window.location.origin;
        } catch (e) {
            var s = String(url || '');
            return s.indexOf('//') !== 0 && s.indexOf('http:') !== 0 && s.indexOf('https:') !== 0;
        }
    }

    var origFetch = window.fetch;
    if (typeof origFetch === 'function') {
        window.fetch = function (input, init) {
            init = init || {};
            var method = String(init.method || (input && input.method) || 'GET').toUpperCase();
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            if (method !== 'GET' && method !== 'HEAD' && sameOrigin(url)) {
                var token = csrfToken();
                if (token) {
                    init.headers = init.headers || {};
                    if (typeof Headers !== 'undefined' && init.headers instanceof Headers) {
                        if (!init.headers.has('X-CSRF-Token')) init.headers.set('X-CSRF-Token', token);
                    } else if (Array.isArray(init.headers)) {
                        init.headers.push(['X-CSRF-Token', token]);
                    } else {
                        init.headers['X-CSRF-Token'] = token;
                    }
                }
            }
            return origFetch.call(this, input, init);
        };
    }

    if (typeof XMLHttpRequest !== 'undefined') {
        var origOpen = XMLHttpRequest.prototype.open;
        var origSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (method, url) {
            this.__ctrMethod = String(method || 'GET').toUpperCase();
            this.__ctrUrl = url;
            return origOpen.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function (body) {
            if (this.__ctrMethod && this.__ctrMethod !== 'GET' && this.__ctrMethod !== 'HEAD' && sameOrigin(this.__ctrUrl)) {
                var token = csrfToken();
                if (token) this.setRequestHeader('X-CSRF-Token', token);
            }
            return origSend.apply(this, arguments);
        };
    }

    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || f.tagName !== 'FORM') return;
        if (String(f.method || 'get').toLowerCase() === 'get') return;
        if (!sameOrigin(f.getAttribute('action') || window.location.href)) return;
        var token = csrfToken();
        if (!token) return;
        if (f.querySelector('input[name="ctr_csrf_token"]')) return;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ctr_csrf_token';
        input.value = token;
        f.appendChild(input);
    });
})();
</script>
