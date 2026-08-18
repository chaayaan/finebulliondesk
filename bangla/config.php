<?php
/**
 * config.php
 * FineBullion Desk - Shared configuration
 *
 * Database connection with Bangladesh timezone.
 * Auth, CSRF, and validation will be added separately later.
 */

// ---------------------------------------------------------------------
// Timezone — Bangladesh Standard Time (UTC+6)
// ---------------------------------------------------------------------
ini_set('date.timezone', 'Asia/Dhaka');
date_default_timezone_set('Asia/Dhaka');

// ---------------------------------------------------------------------
// Database configuration
// ---------------------------------------------------------------------
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'finebullion_desk');
define('DB_USER', 'root'); 
define('DB_PASS', ''); 
// ---------------------------------------------------------------------
// mysqli connection
// ---------------------------------------------------------------------
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$conn) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>FineBullion Desk — Connection Error</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
        <style>
            :root {
                --gold-deep:  #c9973a;
                --gold-mid:   #dcb04a;
                --gold-light: #e9cd7d;
                --ivory:      #fbf8f2;
                --bronze-text:#3a2f1a;
                --muted:      #9a8f76;
                --hairline:   #ecdfb8;

                --status-paid-bg:    #1b5238;
                --status-paid-light: #eaf4ee;
                --status-due-bg:     #93292c;
                --status-due-light:  #fbeceb;
                --status-total-bg:   #b88328;
                --status-total-light:#fdf6e2;
            }

            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

            body {
                background: var(--ivory);
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                color: var(--bronze-text);
                min-height: 100vh;
                display: flex;
                flex-direction: column;
            }

            /* ── Page header ── */
            .ge-header {
                background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%) !important;
                color: #ffffff !important;
                min-height: 60px !important;
                max-height: 80px !important;
                padding: 0.85rem 1.75rem !important;
                margin: 0 !important;
                width: 100% !important;
                border-radius: 0 0 20px 20px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: nowrap;
                gap: 1rem;
            }
            .ge-header .brand-title {
                font-size: 1.15rem;
                font-weight: 800;
                color: #ffffff !important;
                letter-spacing: 0.01em;
                line-height: 1.2;
            }
            .ge-header .brand-sub {
                font-size: 0.72rem;
                font-weight: 600;
                color: rgba(255,255,255,0.80) !important;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                margin-top: 2px;
            }
            .ge-header .header-icon {
                width: 38px;
                height: 38px;
                background: rgba(255,255,255,0.18);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.1rem;
                color: #ffffff !important;
                flex-shrink: 0;
            }

            /* ── Content area ── */
            .page-inset {
                padding: 0 1.5rem;
                flex: 1;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            /* ── Error card ── */
            .error-card {
                background: #ffffff;
                border: none;
                border-radius: 18px;
                box-shadow: 0 10px 30px rgba(180,140,50,0.12);
                max-width: 480px;
                width: 100%;
                padding: 2.5rem 2rem;
                text-align: center;
                margin: 2.5rem auto;
            }
            .error-icon {
                width: 64px;
                height: 64px;
                background: var(--status-due-light);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 1.25rem;
                font-size: 1.75rem;
            }
            .error-title {
                font-size: 1.2rem;
                font-weight: 800;
                color: var(--status-due-bg);
                margin-bottom: 0.5rem;
            }
            .error-body {
                font-size: 0.9rem;
                color: var(--muted);
                line-height: 1.65;
                margin-bottom: 1.75rem;
            }
            .error-alert {
                background: var(--status-due-light);
                border: 1.5px solid var(--status-due-bg);
                border-radius: 12px;
                padding: 0.85rem 1rem;
                font-size: 0.82rem;
                color: var(--status-due-bg);
                font-weight: 600;
                margin-bottom: 1.5rem;
            }
            .contact-block {
                background: var(--ivory);
                border: 1.5px solid var(--hairline);
                border-radius: 12px;
                padding: 1rem 1.25rem;
                text-align: left;
            }
            .contact-label {
                font-size: 0.68rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                color: var(--muted);
                margin-bottom: 0.5rem;
            }
            .contact-row {
                display: flex;
                align-items: center;
                gap: 0.5rem;
                font-size: 0.85rem;
                color: var(--bronze-text);
                font-weight: 600;
            }
            .contact-row + .contact-row {
                margin-top: 0.4rem;
            }
            .contact-dot {
                width: 7px;
                height: 7px;
                border-radius: 50%;
                background: var(--gold-deep);
                flex-shrink: 0;
            }

            /* ── Footer ── */
            .error-footer {
                text-align: center;
                padding: 1.25rem;
                font-size: 0.72rem;
                color: var(--muted);
                border-top: 1px solid var(--hairline);
                margin-top: auto;
            }

            /* ── Mobile ── */
            @media (max-width: 767.98px) {
                .ge-header {
                    min-height: 60px !important;
                    max-height: 70px !important;
                    padding: 0.75rem 1rem !important;
                    border-radius: 0 0 16px 16px;
                }
                .page-inset { padding: 0 0.8rem; }
                .error-card { padding: 2rem 1.25rem; border-radius: 14px; }
            }
        </style>
    </head>
    <body>

        <!-- Header -->
        <div style="width:100%;">
            <header class="ge-header">
                <div>
                    <div class="brand-title">FineBullion Desk</div>
                    <div class="brand-sub">Gold Exchange Management</div>
                </div>
                <div class="header-icon">&#9670;</div>
            </header>
        </div>

        <!-- Error content -->
        <div class="page-inset">
            <div class="error-card">

                <div class="error-icon">&#9888;</div>

                <div class="error-title">Unable to Connect</div>
                <p class="error-body">
                    The system could not establish a connection to the database.
                    This is usually a temporary server issue.
                </p>

                <div class="error-alert">
                    Database connection failed — please do not retry repeatedly.
                </div>

                <div class="contact-block">
                    <div class="contact-label">Please contact the developer</div>
                    <div class="contact-row">
                        <span class="contact-dot"></span>
                        <span>Report this issue and the time it occurred</span>
                    </div>
                    <div class="contact-row">
                        <span class="contact-dot"></span>
                        <span>Do not share any transaction data over chat</span>
                    </div>
                </div>

            </div>
        </div>

        <footer class="error-footer">
            &copy; <?php echo date('Y'); ?> FineBullion Desk &mdash; Internal System
        </footer>

    </body>
    </html>
    <?php
    exit;
}

// Set Bangladesh timezone at the session level
mysqli_query($conn, "SET time_zone = '+06:00'");

mysqli_set_charset($conn, 'utf8mb4');