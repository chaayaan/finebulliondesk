<?php
/**
 * navbar.php
 * FineBullion Desk — Shared sidebar navigation
 *
 * Include this at the top of every protected page, AFTER session_start()
 * and config.php have already run, e.g.:
 *
 *   <?php
 *   session_start();
 *   require_once __DIR__ . '/config.php';
 *   if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
 *   require_once __DIR__ . '/navbar.php';
 *   ?>
 *   <!DOCTYPE html> ... rest of the page, wrapping content in .page-content
 *
 * This file only outputs the <aside> sidebar + mobile topbar markup and its
 * own <style>/<script>. It does not open <html>/<body> — the host page does.
 */

// Fetch the current user's display info (name / role / photo) if not
// already loaded by the host page. Fails quietly to session-only data.
if (!isset($navUser)) {
    $navUser = [
        'username'   => $_SESSION['username'] ?? 'User',
        'role'       => $_SESSION['role'] ?? '',
        'photo_path' => null,
    ];

    if (isset($conn) && isset($_SESSION['user_id'])) {
        $stmt = mysqli_prepare($conn, 'SELECT username, role, photo_path FROM users WHERE id = ? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $_SESSION['user_id']);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                $navUser['username']   = $row['username'];
                $navUser['role']       = $row['role'];
                $navUser['photo_path'] = $row['photo_path'];
            }
        }
    }
}

$navCurrentPage = basename($_SERVER['SCRIPT_NAME']);

$navLinks = [
    ['href' => 'dashboard.php',       'icon' => 'bi-grid-1x2-fill',   'label' => 'Dashboard'],
    ['href' => 'customers.php',       'icon' => 'bi-person-fill',     'label' => 'Customer'],
    ['href' => 'gold_exchange.php',        'icon' => 'bi-arrow-left-right','label' => 'Exchange'],
    ['href' => 'sale.php',            'icon' => 'bi-cash-coin',       'label' => 'Sale'],
    ['href' => 'buy.php',             'icon' => 'bi-cart-fill',       'label' => 'Buy'],
    ['href' => 'expenses.php',        'icon' => 'bi-wallet2',         'label' => 'Expenses'],
    ['href' => 'users.php',           'icon' => 'bi-people-fill',     'label' => 'Users'],
];

function nav_is_active(string $href, string $current): bool
{
    return $href === $current;
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
    :root {
        --nav-width: 212px;
        --nav-bg: #14161c;
        --nav-bg-soft: #1a1d25;
        --nav-border: #262a35;
        --nav-gold: #d4a847;
        --nav-gold-soft: #c9973a;
        --nav-text: #e7e9ee;
        --nav-text-dim: #9095a3;
    }

    body {
        margin: 0;
    }

    /* ---------- Sidebar ---------- */
    .app-sidebar {
        position: fixed;
        top: 0;
        left: 0;
        width: var(--nav-width);
        height: 100vh;
        background: var(--nav-bg);
        border-right: 1px solid var(--nav-border);
        display: flex;
        flex-direction: column;
        z-index: 1040;
        transition: transform 0.25s ease;
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    }

    .nav-brand {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        padding: 1.15rem 1rem;
        border-bottom: 1px solid var(--nav-border);
    }

    .nav-brand-icon {
        width: 36px;
        height: 36px;
        border-radius: 9px;
        background: linear-gradient(135deg, var(--nav-gold-soft) 0%, var(--nav-gold) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.05rem;
        color: #1a1a1a;
        flex-shrink: 0;
    }

    .nav-brand-text {
        line-height: 1.15;
        overflow: hidden;
    }

    .nav-brand-name {
        font-size: 0.92rem;
        font-weight: 700;
        color: #fff;
        letter-spacing: -0.01em;
        white-space: nowrap;
    }

    .nav-brand-sub {
        font-size: 0.62rem;
        color: var(--nav-text-dim);
        letter-spacing: 0.03em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .nav-links {
        flex: 1;
        overflow-y: auto;
        padding: 0.75rem 0.6rem;
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
    }

    .nav-link-item {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 0.62rem 0.75rem;
        border-radius: 8px;
        color: var(--nav-text);
        text-decoration: none;
        font-size: 0.88rem;
        font-weight: 500;
        border-left: 3px solid transparent;
        transition: background 0.15s, color 0.15s;
        white-space: nowrap;
    }

    .nav-link-item i {
        font-size: 1.02rem;
        width: 20px;
        text-align: center;
        color: var(--nav-gold);
        flex-shrink: 0;
    }

    .nav-link-item:hover {
        background: var(--nav-bg-soft);
        color: #fff;
    }

    .nav-link-item.active {
        background: linear-gradient(135deg, rgba(201, 151, 58, 0.28) 0%, rgba(212, 168, 71, 0.16) 100%);
        border-left-color: var(--nav-gold);
        color: var(--nav-gold);
    }

    .nav-link-item.active i {
        color: var(--nav-gold);
    }

    .nav-footer {
        border-top: 1px solid var(--nav-border);
        padding: 1.1rem 1rem;
        text-align: center;
    }

    .nav-avatar {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--nav-gold);
        margin-bottom: 0.55rem;
        background: #2a2d36;
    }

    .nav-avatar-fallback {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        border: 2px solid var(--nav-gold);
        margin: 0 auto 0.55rem;
        background: var(--nav-bg-soft);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--nav-gold);
    }

    .nav-user-name {
        font-size: 0.85rem;
        font-weight: 700;
        color: #fff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .nav-user-role {
        font-size: 0.72rem;
        color: var(--nav-text-dim);
        text-transform: capitalize;
        margin-bottom: 0.75rem;
    }

    .nav-logout-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        width: 100%;
        padding: 0.5rem;
        border-radius: 7px;
        border: 1px solid var(--nav-border);
        background: transparent;
        color: var(--nav-gold);
        font-size: 0.82rem;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.15s, border-color 0.15s;
    }

    .nav-logout-btn:hover {
        background: var(--nav-bg-soft);
        border-color: var(--nav-gold);
        color: var(--nav-gold);
    }

    /* ---------- Page content offset (desktop) ---------- */
    .page-content {
        margin-left: var(--nav-width);
        min-height: 100vh;
        background: #f0f2f5;
    }

    /* ---------- Mobile topbar ---------- */
    .nav-topbar {
        display: none;
        position: sticky;
        top: 0;
        z-index: 1030;
        align-items: center;
        gap: 0.75rem;
        background: var(--nav-bg);
        color: #fff;
        padding: 0.7rem 1rem;
        border-bottom: 1px solid var(--nav-border);
    }

    .nav-topbar-brand {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 700;
        font-size: 0.95rem;
    }

    .nav-topbar-brand .nav-brand-icon {
        width: 28px;
        height: 28px;
        font-size: 0.85rem;
    }

    .nav-toggle-btn {
        background: transparent;
        border: 1px solid var(--nav-border);
        color: #fff;
        width: 38px;
        height: 38px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }

    .nav-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.45);
        z-index: 1035;
    }

    /* ---------- Responsive breakpoint ---------- */
    @media (max-width: 991.98px) {
        .app-sidebar {
            transform: translateX(-100%);
            box-shadow: 0 0 32px rgba(0, 0, 0, 0.35);
        }

        .app-sidebar.nav-open {
            transform: translateX(0);
        }

        .page-content {
            margin-left: 0;
        }

        .nav-topbar {
            display: flex;
        }

        .nav-backdrop.nav-open {
            display: block;
        }
    }
</style>

<div class="nav-topbar">
    <button class="nav-toggle-btn" id="navToggleBtn" aria-label="Open menu" aria-expanded="false">
        <i class="bi bi-list"></i>
    </button>
    <div class="nav-topbar-brand">
        <div class="nav-brand-icon"><i class="bi bi-gem"></i></div>
        <span>FineBullion Desk</span>
    </div>
</div>

<div class="nav-backdrop" id="navBackdrop"></div>

<aside class="app-sidebar" id="appSidebar">

    <div class="nav-brand">
        <div class="nav-brand-icon"><i class="bi bi-gem"></i></div>
        <div class="nav-brand-text">
            <div class="nav-brand-name">FineBullion Desk</div>
            <div class="nav-brand-sub">Artisan Gold Trade &amp; Pure-Weight Ledger</div>
        </div>
    </div>

    <nav class="nav-links">
        <?php foreach ($navLinks as $link): ?>
            <a href="<?= htmlspecialchars($link['href']) ?>"
               class="nav-link-item<?= nav_is_active($link['href'], $navCurrentPage) ? ' active' : '' ?>">
                <i class="bi <?= htmlspecialchars($link['icon']) ?>"></i>
                <span><?= htmlspecialchars($link['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="nav-footer">
        <?php if (!empty($navUser['photo_path'])): ?>
            <img src="<?= htmlspecialchars($navUser['photo_path']) ?>" alt="Profile photo" class="nav-avatar">
        <?php else: ?>
            <div class="nav-avatar-fallback">
                <?= htmlspecialchars(strtoupper(substr($navUser['username'] ?? 'U', 0, 1))) ?>
            </div>
        <?php endif; ?>
        <div class="nav-user-name"><?= htmlspecialchars($navUser['username'] ?? 'User') ?></div>
        <div class="nav-user-role"><?= htmlspecialchars($navUser['role'] ?: 'Member') ?></div>
        <a href="logout.php" class="nav-logout-btn">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </div>

</aside>

<script>
    (function () {
        const sidebar  = document.getElementById('appSidebar');
        const backdrop = document.getElementById('navBackdrop');
        const toggle   = document.getElementById('navToggleBtn');

        function openNav() {
            sidebar.classList.add('nav-open');
            backdrop.classList.add('nav-open');
            toggle.setAttribute('aria-expanded', 'true');
        }

        function closeNav() {
            sidebar.classList.remove('nav-open');
            backdrop.classList.remove('nav-open');
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function () {
            sidebar.classList.contains('nav-open') ? closeNav() : openNav();
        });

        backdrop.addEventListener('click', closeNav);

        // Close drawer automatically if resized up to desktop width
        window.addEventListener('resize', function () {
            if (window.innerWidth > 991.98) closeNav();
        });
    })();
</script>