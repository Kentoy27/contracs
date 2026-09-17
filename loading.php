<?php
require __DIR__ . '/includes/cache_headers.php';
ctr_session_start();

$loggedIn = (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) || (!empty($_SESSION['user_id']));

if ($loggedIn) {
    header('Location: ' . ctr_url('index'));
    exit;
}

$target = ctr_url('login');
$delayMs = 3500;
$delaySeconds = (int)ceil($delayMs / 1000);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Loading ConTracS...</title>
    <meta name="theme-color" content="#05060a" />
    <meta http-equiv="refresh" content="<?= $delaySeconds ?>;url=<?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>" />
    <style>
        :root {
            --ctr-bg: #05060a;
            --ctr-fg: rgba(255, 255, 255, 0.92);
            --ctr-muted: rgba(255, 255, 255, 0.70);
            --ctr-cyan: #22d3ee;
            --ctr-teal: #2dd4bf;
            --ctr-blue: #60a5fa;
            --ctr-violet: #a78bfa;
            --ctr-line: rgba(148, 163, 184, 0.20);
            --ctr-card: rgba(12, 14, 24, 0.52);
            --ctr-card-strong: rgba(12, 14, 24, 0.74);
            --ctr-shadow: rgba(0, 0, 0, 0.55);
            --ctr-warn: #fbbf24;
            --ctr-ok: #34d399;
        }

        html,
        body {
            height: 100%;
        }

        body {
            margin: 0;
            background: var(--ctr-bg);
            color: var(--ctr-fg);
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            overflow: hidden;
        }

        .bg {
            position: fixed;
            inset: 0;
            background: radial-gradient(900px 560px at 16% 22%, rgba(34, 211, 238, 0.16), rgba(5, 6, 10, 0) 62%),
                radial-gradient(900px 700px at 84% 22%, rgba(96, 165, 250, 0.14), rgba(5, 6, 10, 0) 62%),
                radial-gradient(900px 650px at 50% 86%, rgba(167, 139, 250, 0.12), rgba(5, 6, 10, 0) 60%),
                radial-gradient(1100px 900px at 50% 50%, rgba(45, 212, 191, 0.08), rgba(5, 6, 10, 0) 70%),
                linear-gradient(180deg, #05060a, #060716);
            animation: bgBreath 7s ease-in-out infinite;
            z-index: 0;
        }

        .bg::after {
            content: "";
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(to right, rgba(148, 163, 184, 0.10) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(148, 163, 184, 0.10) 1px, transparent 1px);
            background-size: 72px 72px;
            mask-image: radial-gradient(circle at 50% 50%, rgba(0, 0, 0, 1) 0%, rgba(0, 0, 0, 0.35) 55%, rgba(0, 0, 0, 0) 78%);
            opacity: 0.55;
        }

        @keyframes bgBreath {
            0% {
                filter: saturate(1.05) brightness(1);
            }

            50% {
                filter: saturate(1.25) brightness(1.02);
            }

            100% {
                filter: saturate(1.05) brightness(1);
            }
        }

        .overlay {
            position: fixed;
            inset: 0;
            background: radial-gradient(circle at 50% 50%, rgba(5, 6, 10, 0.35), rgba(5, 6, 10, 0.86) 65%, rgba(5, 6, 10, 0.96) 100%);
            z-index: 1;
        }

        .overlay::after {
            content: "";
            position: fixed;
            inset: 0;
            background-image: radial-gradient(circle at 1px 1px, rgba(255, 255, 255, 0.07) 1px, rgba(0, 0, 0, 0) 1px);
            background-size: 40px 40px;
            opacity: 0.08;
            mix-blend-mode: overlay;
            pointer-events: none;
        }

        .wrap {
            position: relative;
            z-index: 2;
            min-height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            text-align: center;
        }

        .card {
            width: min(560px, 100%);
            border-radius: 22px;
            background: linear-gradient(180deg, rgba(12, 14, 24, 0.66), rgba(12, 14, 24, 0.42));
            border: 1px solid rgba(148, 163, 184, 0.18);
            box-shadow: 0 30px 120px var(--ctr-shadow);
            backdrop-filter: blur(12px);
            padding: 22px 20px 18px;
            position: relative;
            overflow: hidden;
            transform: translateY(0);
            animation: cardIn 320ms ease both;
        }

        .card::before {
            content: "";
            position: absolute;
            inset: -2px;
            background: radial-gradient(520px 220px at 20% 0%, rgba(34, 211, 238, 0.10), rgba(0, 0, 0, 0) 60%),
                radial-gradient(520px 280px at 80% 15%, rgba(167, 139, 250, 0.10), rgba(0, 0, 0, 0) 62%),
                radial-gradient(560px 280px at 50% 110%, rgba(96, 165, 250, 0.08), rgba(0, 0, 0, 0) 64%);
            pointer-events: none;
            opacity: 0.9;
        }

        .card::after {
            content: "";
            position: absolute;
            inset: 0;
            background-image: linear-gradient(135deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0) 45%);
            opacity: 0.9;
            pointer-events: none;
        }

        @keyframes cardIn {
            from {
                transform: translateY(10px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .stage {
            position: relative;
            display: grid;
            place-items: center;
            gap: 12px;
        }

        .ai-wrap {
            position: relative;
            width: 220px;
            height: 220px;
            display: grid;
            place-items: center;
            filter: drop-shadow(0 0 18px rgba(34, 211, 238, 0.18)) drop-shadow(0 0 26px rgba(167, 139, 250, 0.10));
        }

        .ai-wrap::before {
            content: "";
            position: absolute;
            inset: 24px;
            border-radius: 999px;
            background: radial-gradient(circle at 50% 50%, rgba(34, 211, 238, 0.16), rgba(5, 6, 10, 0.0) 62%);
            border: 1px solid rgba(148, 163, 184, 0.16);
            box-shadow: inset 0 0 0 1px rgba(96, 165, 250, 0.10);
        }

        .ai-svg {
            width: 220px;
            height: 220px;
        }

        .ring {
            transform-origin: 110px 110px;
            animation: spinSlow 2.8s linear infinite;
        }

        .ring2 {
            transform-origin: 110px 110px;
            animation: spinSlow2 3.6s linear infinite reverse;
            opacity: 0.9;
        }

        @keyframes spinSlow {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes spinSlow2 {
            to {
                transform: rotate(360deg);
            }
        }

        .scan {
            stroke-dasharray: 6 10;
            animation: dash 1.4s linear infinite;
        }

        @keyframes dash {
            to {
                stroke-dashoffset: -64;
            }
        }

        .node {
            transform-origin: center;
            animation: pulse 1.2s ease-in-out infinite;
        }

        .node.n2 {
            animation-delay: 0.15s;
        }

        .node.n3 {
            animation-delay: 0.3s;
        }

        .node.n4 {
            animation-delay: 0.45s;
        }

        .node.n5 {
            animation-delay: 0.6s;
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1);
                opacity: 0.9;
            }

            50% {
                transform: scale(1.12);
                opacity: 1;
            }
        }

        .ai-core {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
        }

        .ai-badge {
            width: 78px;
            height: 78px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(148, 163, 184, 0.16);
            box-shadow: 0 0 0 1px rgba(34, 211, 238, 0.10), 0 18px 60px rgba(0, 0, 0, 0.55);
            display: grid;
            place-items: center;
            backdrop-filter: blur(10px);
        }

        .ai-text {
            font-weight: 900;
            letter-spacing: 0.04em;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.92);
            text-shadow: 0 0 18px rgba(34, 211, 238, 0.25);
        }

        .ai-text span {
            background: linear-gradient(90deg, var(--ctr-cyan), var(--ctr-blue), var(--ctr-violet));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            animation: hue 2.2s ease-in-out infinite;
        }

        @keyframes hue {

            0%,
            100% {
                filter: hue-rotate(0deg);
            }

            50% {
                filter: hue-rotate(18deg);
            }
        }

        .title {
            margin: 0;
            font-weight: 900;
            letter-spacing: 0.02em;
            font-size: 20px;
        }

        .sub {
            margin: 0;
            color: var(--ctr-muted);
            font-size: 13px;
        }

        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center;
            margin-top: 2px;
        }

        .chip {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.02em;
            padding: 7px 10px;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.04);
            color: rgba(255, 255, 255, 0.78);
            backdrop-filter: blur(10px);
        }

        .chip.ok {
            border-color: rgba(52, 211, 153, 0.34);
            background: rgba(52, 211, 153, 0.08);
            color: rgba(255, 255, 255, 0.90);
        }

        .chip.warn {
            border-color: rgba(251, 191, 36, 0.34);
            background: rgba(251, 191, 36, 0.10);
            color: rgba(255, 255, 255, 0.90);
        }

        .meter {
            width: min(420px, 100%);
            height: 10px;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.14);
            border: 1px solid rgba(148, 163, 184, 0.14);
            overflow: hidden;
            box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.18);
        }

        .meter > span {
            display: block;
            height: 100%;
            width: 0%;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--ctr-cyan), var(--ctr-blue), var(--ctr-violet));
            box-shadow: 0 0 0 1px rgba(34, 211, 238, 0.10);
            transform-origin: left;
            transition: width 180ms ease;
            position: relative;
        }

        .meter > span::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(255, 255, 255, 0), rgba(255, 255, 255, 0.18), rgba(255, 255, 255, 0));
            transform: translateX(-60%);
            animation: shimmer 900ms ease-in-out infinite;
            opacity: 0.9;
        }

        @keyframes shimmer {
            0% { transform: translateX(-70%); }
            100% { transform: translateX(70%); }
        }

        .meta {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.74);
            margin-top: 2px;
        }

        .dot {
            opacity: 0.55;
        }

        .skip {
            margin-top: 10px;
            appearance: none;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0.03));
            color: rgba(255, 255, 255, 0.90);
            font-size: 12px;
            font-weight: 700;
            padding: 10px 14px;
            border-radius: 12px;
            cursor: pointer;
            transition: transform 120ms ease, background 120ms ease, border-color 120ms ease;
        }

        .skip:hover {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.04));
            border-color: rgba(148, 163, 184, 0.30);
            transform: translateY(-1px);
        }

        .skip:active {
            transform: translateY(0);
        }

        .skip:focus-visible {
            outline: 2px solid rgba(34, 211, 238, 0.55);
            outline-offset: 2px;
        }

        .hint {
            margin-top: 10px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.66);
        }

        .hint a {
            color: rgba(255, 255, 255, 0.92);
        }

        .fade-out {
            animation: fadeOut 240ms ease forwards;
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: translateY(-2px);
            }
        }

        @media (prefers-reduced-motion: reduce) {

            .bg,
            .ring,
            .ring2,
            .scan,
            .node,
            .ai-text span,
            .card {
                animation: none;
            }

            .meter > span {
                transition: none;
            }

            .meter > span::after {
                animation: none;
                opacity: 0;
            }
        }
    </style>
</head>

<body>
    <div class="bg" aria-hidden="true"></div>
    <div class="overlay" aria-hidden="true"></div>
    <div class="wrap">
        <main class="card" id="loadingCard" role="status" aria-live="polite" aria-busy="true">
            <div class="stage">
                <div class="ai-wrap" aria-hidden="true">
                    <svg class="ai-svg" viewBox="0 0 220 220" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <linearGradient id="g1" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0" stop-color="#22D3EE" stop-opacity="0.95" />
                                <stop offset="0.55" stop-color="#60A5FA" stop-opacity="0.85" />
                                <stop offset="1" stop-color="#A78BFA" stop-opacity="0.92" />
                            </linearGradient>
                            <linearGradient id="g2" x1="1" y1="0" x2="0" y2="1">
                                <stop offset="0" stop-color="#2DD4BF" stop-opacity="0.85" />
                                <stop offset="1" stop-color="#22D3EE" stop-opacity="0.75" />
                            </linearGradient>
                        </defs>
                        <g class="ring">
                            <circle cx="110" cy="110" r="78" fill="none" stroke="url(#g1)" stroke-opacity="0.18" stroke-width="2" />
                            <circle class="scan" cx="110" cy="110" r="78" fill="none" stroke="url(#g1)" stroke-width="2.5" stroke-linecap="round" />
                        </g>
                        <g class="ring2">
                            <circle cx="110" cy="110" r="58" fill="none" stroke="url(#g2)" stroke-opacity="0.14" stroke-width="2" />
                            <path class="scan" d="M110 52a58 58 0 1 1-0.01 0" fill="none" stroke="url(#g2)" stroke-width="2.2" stroke-linecap="round" />
                        </g>
                        <g stroke="rgba(148,163,184,0.25)" stroke-width="1.2" fill="none">
                            <path d="M56 116 C78 88, 96 80, 110 78 C124 76, 142 84, 164 108" />
                            <path d="M70 152 C92 132, 98 120, 110 110 C124 100, 140 100, 156 86" />
                            <path d="M60 86 C82 96, 92 108, 110 110 C126 112, 142 128, 162 146" />
                        </g>
                        <g fill="url(#g1)">
                            <circle class="node n1" cx="56" cy="116" r="4.2" />
                            <circle class="node n2" cx="70" cy="152" r="3.6" />
                            <circle class="node n3" cx="60" cy="86" r="3.6" />
                            <circle class="node n4" cx="164" cy="108" r="4.2" />
                            <circle class="node n5" cx="162" cy="146" r="3.8" />
                            <circle class="node n2" cx="156" cy="86" r="3.6" />
                            <circle class="node n3" cx="110" cy="78" r="3.2" />
                            <circle class="node n4" cx="110" cy="110" r="3.8" />
                        </g>
                    </svg>
                    <div class="ai-core">
                        <div class="ai-badge">
                            <div class="ai-text"><span>ContracS</span></div>
                        </div>
                    </div>
                </div>

                <h1 class="title">Initializing ConTracS</h1>
                <div class="sub" id="loadingStatus">Establishing secure session…</div>
                <div class="chips">
                    <div class="chip" id="connChip">Checking connection…</div>
                    <div class="chip" aria-hidden="true">Enter/Space to continue</div>
                </div>

                <div class="meter" role="progressbar" aria-label="Loading progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                    <span id="loadingBar"></span>
                </div>
                <div class="meta" aria-hidden="true">
                    <span id="loadingPercent">0%</span>
                    <span class="dot">•</span>
                    <span id="loadingEta">Preparing…</span>
                </div>

                <button class="skip" type="button" id="skipBtn">Continue now</button>

                <div class="hint">If you are not redirected, <a href="<?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>">continue here</a>.</div>
                <noscript>
                    <div class="hint" style="margin-top:10px;">JavaScript is disabled. Continue with <a href="<?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>">this link</a>.</div>
                </noscript>
            </div>
        </main>
    </div>

    <script>
        (function() {
            var target = <?= json_encode($target) ?>;
            var delayMs = <?= (int)$delayMs ?>;
            var status = document.getElementById('loadingStatus');
            var card = document.getElementById('loadingCard');
            var bar = document.getElementById('loadingBar');
            var percentEl = document.getElementById('loadingPercent');
            var etaEl = document.getElementById('loadingEta');
            var meter = document.querySelector('.meter[role="progressbar"]');
            var skipBtn = document.getElementById('skipBtn');
            var connChip = document.getElementById('connChip');

            var steps = [
                { t: 'Establishing secure session…', p: 8 },
                { t: 'Loading modules…', p: 32 },
                { t: 'Syncing data…', p: 58 },
                { t: 'Optimizing interface…', p: 82 },
                { t: 'Ready…', p: 100 }
            ];

            function clamp(n, min, max) {
                return Math.max(min, Math.min(max, n));
            }

            function formatSeconds(seconds) {
                if (!isFinite(seconds) || seconds <= 0) return 'Almost there…';
                if (seconds < 1) return 'Almost there…';
                if (seconds < 2) return 'About 1 second…';
                return 'About ' + Math.round(seconds) + ' seconds…';
            }

            var startedAt = performance.now ? performance.now() : Date.now();
            var lastStepIndex = -1;
            var finished = false;

            function render(pct) {
                var p = clamp(pct, 0, 100);
                if (bar) bar.style.width = p + '%';
                if (percentEl) percentEl.textContent = Math.round(p) + '%';
                if (meter) meter.setAttribute('aria-valuenow', String(Math.round(p)));

                var stepIndex = 0;
                for (var i = 0; i < steps.length; i++) {
                    if (p >= steps[i].p) stepIndex = i;
                }
                if (stepIndex !== lastStepIndex && status) {
                    status.textContent = steps[stepIndex].t;
                    lastStepIndex = stepIndex;
                }

                var remainingMs = delayMs - (p / 100) * delayMs;
                if (etaEl) etaEl.textContent = formatSeconds(remainingMs / 1000);
                if (skipBtn) {
                    var s = Math.max(0, Math.ceil(remainingMs / 1000));
                    skipBtn.textContent = s > 0 ? ('Continue now (' + s + 's)') : 'Continue now';
                }
            }

            function goNow() {
                if (finished) return;
                finished = true;
                render(100);
                if (card) card.classList.add('fade-out');
                setTimeout(function() {
                    window.location.replace(target);
                }, 170);
            }

            function setConn(text, state) {
                if (!connChip) return;
                connChip.textContent = text;
                connChip.classList.remove('ok');
                connChip.classList.remove('warn');
                if (state) connChip.classList.add(state);
            }

            function checkConnection() {
                if (!window.fetch) return;
                var controller = null;
                var timeoutId = null;
                try {
                    controller = window.AbortController ? new AbortController() : null;
                } catch (e) { controller = null; }

                timeoutId = setTimeout(function() {
                    if (controller) controller.abort();
                }, 1200);

                var started = performance.now ? performance.now() : Date.now();
                fetch(target, {
                    method: 'GET',
                    cache: 'no-store',
                    signal: controller ? controller.signal : undefined,
                    headers: { 'X-Requested-With': 'fetch' }
                }).then(function(res) {
                    clearTimeout(timeoutId);
                    var now = performance.now ? performance.now() : Date.now();
                    var ms = Math.max(0, Math.round(now - started));
                    if (res && res.ok) {
                        setConn('Connection OK (' + ms + 'ms)', 'ok');
                        return;
                    }
                    setConn('Server responding…', null);
                }).catch(function() {
                    clearTimeout(timeoutId);
                    setConn('Network slow/offline', 'warn');
                });
            }

            function tick() {
                if (finished) return;
                var now = performance.now ? performance.now() : Date.now();
                var elapsed = now - startedAt;
                var raw = clamp(elapsed / delayMs, 0, 1);
                var eased = 1 - Math.pow(1 - raw, 3);
                var pct = eased * 100;
                render(pct);
                if (elapsed >= delayMs) {
                    goNow();
                    return;
                }
                requestAnimationFrame(tick);
            }

            render(0);
            setConn('Checking connection…', null);
            checkConnection();
            if (skipBtn) skipBtn.addEventListener('click', goNow);
            window.addEventListener('keydown', function(e) {
                if (!e) return;
                if (e.key === 'Enter' || e.key === ' ') goNow();
            });

            requestAnimationFrame(tick);
        })();
    </script>
</body>

</html>
