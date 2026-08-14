<?php
/**
 * navbar.php
 * FineBullion Desk — Shared sidebar navigation
 */

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

// Array of exchange sub-items
$exchangePages = ['gold_exchange.php', 'gold_exchange_list.php', 'gold_exchange_edit.php'];
$isExchangeActive = in_array($navCurrentPage, $exchangePages);

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

    /* Logo Image Style */
    .nav-brand-logo {
        width: 36px;
        height: 36px;
        object-fit: contain;
        flex-shrink: 0;
        border-radius: 6px;
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
        cursor: pointer;
        background: none;
        border-top: none;
        border-right: none;
        border-bottom: none;
        width: 100%;
        text-align: left;
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

    /* Collapsible Submenu Styles */
    .nav-dropdown-toggle {
        justify-content: space-between;
    }

    .nav-dropdown-toggle .chevron-icon {
        font-size: 0.75rem;
        transition: transform 0.2s ease;
        margin-left: auto;
    }

    .nav-dropdown-toggle.open .chevron-icon {
        transform: rotate(90deg);
    }

    .nav-sub-menu {
        display: none;
        flex-direction: column;
        gap: 0.15rem;
        padding-left: 1.25rem;
        margin-top: 0.15rem;
    }

    .nav-sub-menu.show {
        display: flex;
    }

    .nav-sub-item {
        font-size: 0.82rem;
        padding: 0.5rem 0.75rem;
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

    .nav-topbar-brand .nav-brand-logo {
        width: 28px;
        height: 28px;
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
        <!-- Replacing icon with logo image in topbar -->
        <img src="assets/images/logo.png" alt="Logo" class="nav-brand-logo">
        <span>FineBullion Desk</span>
    </div>
</div>

<div class="nav-backdrop" id="navBackdrop"></div>

<aside class="app-sidebar" id="appSidebar">

    <div class="nav-brand">
        <!-- Replacing icon with logo image in sidebar -->
        <img src="fine bullion desk logo.png" alt="Logo" class="nav-brand-logo">
        <div class="nav-brand-text">
            <div class="nav-brand-name">FineBullion Desk</div>
            <div class="nav-brand-sub">Artisan Gold Trade &amp; Pure-Weight Ledger</div>
        </div>
    </div>

    <nav class="nav-links">
        <a href="dashboard.php" class="nav-link-item<?= nav_is_active('dashboard.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a href="customers.php" class="nav-link-item<?= nav_is_active('customers.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-person-fill"></i>
            <span>Customer</span>
        </a>

        <!-- Collapsible Exchange Menu -->
        <div class="nav-dropdown">
            <button type="button" class="nav-link-item nav-dropdown-toggle<?= $isExchangeActive ? ' open' : '' ?>" id="exchangeToggle">
                <div style="display:flex; align-items:center; gap:0.7rem;">
                    <i class="bi bi-arrow-left-right"></i>
                    <span>Exchange</span>
                </div>
                <i class="bi bi-chevron-right chevron-icon"></i>
            </button>
            <div class="nav-sub-menu<?= $isExchangeActive ? ' show' : '' ?>" id="exchangeSubMenu">
                <a href="gold_exchange.php" class="nav-link-item nav-sub-item<?= nav_is_active('gold_exchange.php', $navCurrentPage) ? ' active' : '' ?>">
                    <span>Gold Exchange</span>
                </a>
                <a href="gold_exchange_list.php" class="nav-link-item nav-sub-item<?= nav_is_active('gold_exchange_list.php', $navCurrentPage) ? ' active' : '' ?>">
                    <span>Gold Exchange List</span>
                </a>
                
                <!-- Shown strictly when user is currently on the edit page -->
                <?php if ($navCurrentPage === 'gold_exchange_edit.php'): ?>
                    <a href="gold_exchange_edit.php" class="nav-link-item nav-sub-item active">
                        <span>Gold Exchange Edit</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <a href="gold_sale.php" class="nav-link-item<?= nav_is_active('gold_sale.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-cash-coin"></i>
            <span>Sale</span>
        </a>

        <a href="gold_buy.php" class="nav-link-item<?= nav_is_active('gold_buy.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-cart-fill"></i>
            <span>Buy</span>
        </a>

        <a href="expenses.php" class="nav-link-item<?= nav_is_active('expenses.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-wallet2"></i>
            <span>Expenses</span>
        </a>

        <a href="users.php" class="nav-link-item<?= nav_is_active('users.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-people-fill"></i>
            <span>Users</span>
        </a>
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

        window.addEventListener('resize', function () {
            if (window.innerWidth > 991.98) closeNav();
        });

        // Exchange Accordion Toggle
        const exchangeToggle = document.getElementById('exchangeToggle');
        const exchangeSubMenu = document.getElementById('exchangeSubMenu');

        if (exchangeToggle && exchangeSubMenu) {
            exchangeToggle.addEventListener('click', function (e) {
                e.preventDefault();
                exchangeToggle.classList.toggle('open');
                exchangeSubMenu.classList.toggle('show');
            });
        }
    })();
</script>