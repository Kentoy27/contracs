<?php
http_response_code(404);
require __DIR__ . '/includes/cache_headers.php';
ctr_cache_headers('static', 86400);
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
$baseHref = $basePath === '' ? '/' : $basePath . '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 &mdash; Page Not Found</title>
    <base href="<?php echo htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/sdo.png">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            background: linear-gradient(135deg, #f0f4ff 0%, #e8f5e9 50%, #f0f4ff 100%);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .ctr-404-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .ctr-404-card {
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06), 0 1px 4px rgba(0, 0, 0, 0.04);
            padding: 3.5rem 2.5rem;
            max-width: 520px;
            width: 100%;
            text-align: center;
            animation: ctr-fadeIn 0.6s ease-out;
        }

        @keyframes ctr-fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .ctr-404-logo {
            width: 64px;
            height: 64px;
            object-fit: contain;
            margin-bottom: 1.5rem;
            opacity: 0.85;
        }

        .ctr-404-illustration {
            margin-bottom: 1.75rem;
        }

        .ctr-404-illustration svg {
            width: 200px;
            height: 160px;
        }

        .ctr-404-code {
            font-size: 4.5rem;
            font-weight: 800;
            line-height: 1;
            margin: 0 0 0.5rem;
            background: linear-gradient(135deg, #1f65b8, #2f7d28);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -2px;
        }

        .ctr-404-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 0.75rem;
        }

        .ctr-404-desc {
            font-size: 0.95rem;
            color: #64748b;
            line-height: 1.6;
            margin: 0 0 2rem;
            max-width: 380px;
            margin-left: auto;
            margin-right: auto;
        }

        .ctr-404-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: center;
        }

        .ctr-404-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.7rem 1.5rem;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }

        .ctr-404-btn-primary {
            background: linear-gradient(135deg, #1f65b8 0%, #2563a0 100%);
            color: #fff;
            box-shadow: 0 2px 8px rgba(31, 101, 184, 0.3);
        }

        .ctr-404-btn-primary:hover {
            background: linear-gradient(135deg, #1a56a0 0%, #1f4f8c 100%);
            box-shadow: 0 4px 12px rgba(31, 101, 184, 0.4);
            transform: translateY(-1px);
            color: #fff;
        }

        .ctr-404-btn-outline {
            background: transparent;
            color: #64748b;
            border: 1.5px solid #e2e8f0;
        }

        .ctr-404-btn-outline:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
            color: #334155;
        }

        .ctr-404-btn svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        .ctr-404-footer {
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #f1f5f9;
        }

        .ctr-404-footer p {
            font-size: 0.8rem;
            color: #94a3b8;
            margin: 0;
        }

        .ctr-404-footer a {
            color: #1f65b8;
            text-decoration: none;
            font-weight: 500;
        }

        .ctr-404-footer a:hover {
            text-decoration: underline;
        }

        /* Dark mode */
        html[data-bs-theme="dark"] body,
        html.app-skin-dark body {
            background: linear-gradient(135deg, #0f172a 0%, #1a2332 50%, #0f172a 100%);
        }

        html[data-bs-theme="dark"] .ctr-404-card,
        html.app-skin-dark .ctr-404-card {
            background: #1e293b;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3), 0 1px 4px rgba(0, 0, 0, 0.2);
        }

        html[data-bs-theme="dark"] .ctr-404-title,
        html.app-skin-dark .ctr-404-title {
            color: #f1f5f9;
        }

        html[data-bs-theme="dark"] .ctr-404-desc,
        html.app-skin-dark .ctr-404-desc {
            color: #94a3b8;
        }

        html[data-bs-theme="dark"] .ctr-404-btn-outline,
        html.app-skin-dark .ctr-404-btn-outline {
            color: #94a3b8;
            border-color: #334155;
        }

        html[data-bs-theme="dark"] .ctr-404-btn-outline:hover,
        html.app-skin-dark .ctr-404-btn-outline:hover {
            border-color: #475569;
            background: rgba(255, 255, 255, 0.05);
            color: #e2e8f0;
        }

        html[data-bs-theme="dark"] .ctr-404-footer,
        html.app-skin-dark .ctr-404-footer {
            border-top-color: #334155;
        }

        html[data-bs-theme="dark"] .ctr-404-footer p,
        html.app-skin-dark .ctr-404-footer p {
            color: #64748b;
        }

        html[data-bs-theme="dark"] .ctr-404-footer a,
        html.app-skin-dark .ctr-404-footer a {
            color: #60a5fa;
        }

        /* Responsive */
        @media (max-width: 480px) {
            .ctr-404-card {
                padding: 2.5rem 1.5rem;
                border-radius: 20px;
            }

            .ctr-404-code {
                font-size: 3.5rem;
            }

            .ctr-404-illustration svg {
                width: 160px;
                height: 130px;
            }

            .ctr-404-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .ctr-404-btn {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<?php ctr_cookie_consent_banner(); ?>
    <div class="ctr-404-wrapper">
        <main class="ctr-404-card">
            <img src="assets/images/logo.png" alt="Logo" class="ctr-404-logo">

            <div class="ctr-404-illustration">
                <svg viewBox="0 0 200 160" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Page/document -->
                    <rect x="40" y="20" width="80" height="104" rx="8" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="1.5"/>
                    <rect x="56" y="40" width="48" height="6" rx="3" fill="#e2e8f0"/>
                    <rect x="56" y="54" width="36" height="6" rx="3" fill="#e2e8f0"/>
                    <rect x="56" y="68" width="44" height="6" rx="3" fill="#e2e8f0"/>
                    <rect x="56" y="82" width="30" height="6" rx="3" fill="#e2e8f0"/>
                    <!-- Question mark -->
                    <circle cx="150" cy="68" r="32" fill="#eef2ff" stroke="#c7d2fe" stroke-width="1.5"/>
                    <text x="150" y="78" text-anchor="middle" font-family="Inter, sans-serif" font-size="32" font-weight="700" fill="#818cf8">?</text>
                    <!-- Decorative dots -->
                    <circle cx="30" cy="50" r="3" fill="#c7d2fe" opacity="0.6"/>
                    <circle cx="20" cy="80" r="2" fill="#a5b4fc" opacity="0.4"/>
                    <circle cx="175" cy="110" r="2.5" fill="#c7d2fe" opacity="0.5"/>
                    <!-- Small warning triangle -->
                    <path d="M145 110 L155 128 L135 128 Z" fill="#fef3c7" stroke="#fbbf24" stroke-width="1.2" stroke-linejoin="round"/>
                    <text x="145" y="126" text-anchor="middle" font-family="Inter, sans-serif" font-size="11" font-weight="600" fill="#d97706">!</text>
                </svg>
            </div>

            <p class="ctr-404-code">404</p>
            <h1 class="ctr-404-title">Page not found</h1>
            <p class="ctr-404-desc">The page you're looking for doesn't exist or has been moved. Let's get you back on track.</p>

            <div class="ctr-404-actions">
                <a href="<?php echo htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8'); ?>" class="ctr-404-btn ctr-404-btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                    Back to Home
                </a>
                <button onclick="history.back()" class="ctr-404-btn ctr-404-btn-outline">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"/>
                        <polyline points="12 19 5 12 12 5"/>
                    </svg>
                    Go Back
                </button>
            </div>

            <div class="ctr-404-footer">
                <p>Need help? <a href="<?php echo htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8'); ?>contact">Contact Support</a></p>
            </div>
        </main>
    </div>
</body>
</html>
