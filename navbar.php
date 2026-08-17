<?php
/**
 * navbar.php
 * Clean 5-Item Navigation with Dynamic Full-Width Logo / Text Fallback
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
$isAdmin = (strtolower(trim($navUser['role'])) === 'admin');

// Check if logo image file physically exists on the server
$logoImagePath = 'finebullion desk logo.png';
$hasLogoImage = file_exists(__DIR__ . '/' . $logoImagePath);

// Active State Logic
$isCustomerActive  = in_array($navCurrentPage, ['customers.php', 'customer_history.php']);
$isExchangeActive  = in_array($navCurrentPage, ['gold_exchange.php', 'gold_exchange_list.php', 'gold_exchange_edit.php']);
$isSaleActive      = in_array($navCurrentPage, ['gold_sale.php', 'gold_sale_list.php', 'gold_sale_edit.php']);
$isBuyActive       = in_array($navCurrentPage, ['gold_buy.php', 'gold_buy_list.php', 'gold_buy_edit.php']);
$isMenuActive      = in_array($navCurrentPage, ['dashboard.php', 'expenses.php', 'users.php']);

function nav_is_active(string $href, string $current): bool {
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
        --nav-text: #e7e9ee;
        --nav-text-dim: #9095a3;
        --mobile-nav-height: 60px;
    }

    /* Zero out root margins and body padding */
    html, body {
    margin: 0 !important;
    padding: 0 !important;
    }

    /* Remove spacing from parent layout wrappers */
    .page-content,
    .main-wrapper,
    .content-wrapper,
    .container,
    .container-fluid {
    margin-top: 0 !important;
    padding-top: 0 !important;
    }

    /* Force header banner flush to the top edge */
    .list-header {
    margin-top: 0 !important;
    border-top-left-radius: 0 !important;
    border-top-right-radius: 0 !important;
    position: relative;
    top: 0;
    }

    /* Prevent CSS margin collapsing from child headings/icons */
    .page-content > *:first-child,
    .list-header > *:first-child {
    margin-top: 0 !important;
    }
    /* Desktop Sidebar */
    .app-sidebar {
        position: fixed; top: 0; left: 0;
        width: var(--nav-width); height: 100vh;
        background: var(--nav-bg); border-right: 1px solid var(--nav-border);
        display: flex; flex-direction: column; z-index: 1040;
        font-family: system-ui, -apple-system, sans-serif;
    }
    
    /* Full-Width Logo Header Container */
    .nav-brand {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem 0.75rem;
        border-bottom: 1px solid var(--nav-border);
        min-height: 48px;
        box-sizing: border-box;
    }
    
    /* Full-width logo image layout */
    .nav-brand-logo-full {
        width: 100%;
        max-height: 42px;
        object-fit: contain;
        display: block;
    }

    /* Fallback Text Layout */
    .nav-brand-text-fallback {
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--nav-gold);
        letter-spacing: -0.02em;
        text-transform: uppercase;
        text-align: center;
        line-height: 1.2;
    }

    .nav-links { flex: 1; overflow-y: auto; padding: 0.75rem 0.6rem; display: flex; flex-direction: column; gap: 0.15rem; }
    .nav-link-item {
        display: flex; align-items: center; gap: 0.7rem; padding: 0.62rem 0.75rem;
        border-radius: 8px; color: var(--nav-text); text-decoration: none;
        font-size: 0.88rem; font-weight: 500; cursor: pointer; background: none; border: none; width: 100%; text-align: left;
    }
    .nav-link-item i { font-size: 1.02rem; width: 20px; color: var(--nav-gold); text-align: center; }
    .nav-link-item:hover { background: var(--nav-bg-soft); color: #fff; }
    .nav-link-item.active { background: rgba(212, 168, 71, 0.18); color: var(--nav-gold); }

    .nav-footer { border-top: 1px solid var(--nav-border); padding: 1rem; text-align: center; }
    .nav-user-name { font-size: 0.85rem; font-weight: 700; color: #fff; }
    .nav-user-role { font-size: 0.72rem; color: var(--nav-text-dim); text-transform: capitalize; margin-bottom: 0.5rem; }
    .nav-logout-btn {
        display: flex; align-items: center; justify-content: center; gap: 0.4rem;
        width: 100%; padding: 0.45rem; border-radius: 7px; border: 1px solid var(--nav-border);
        color: var(--nav-gold); text-decoration: none; font-size: 0.82rem; font-weight: 600;
    }

    .page-content { 
        margin-left: var(--nav-width); 
        margin-top: 0 !important; 
        padding-top: 0 !important; 
        min-height: 100vh; 
        background: #f0f2f5; 
    }

    /* Global Header Fix: Ensures no top spacing and rounds bottom corners only */
    .list-header {
        margin-top: 0 !important;
        border-top-left-radius: 0 !important;
        border-top-right-radius: 0 !important;
        border-bottom-left-radius: 20px;
        border-bottom-right-radius: 20px;
    }

    /* Mobile Bottom Navigation (5 Action Items) */
    .mobile-bottom-nav {
        display: none; position: fixed; bottom: 0; left: 0; right: 0;
        height: var(--mobile-nav-height); background: var(--nav-bg);
        border-top: 1px solid var(--nav-border); z-index: 1050;
    }
    .mobile-nav-container { display: flex; height: 100%; align-items: center; justify-content: space-around; }
    .mobile-nav-item {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        flex: 1; height: 100%; color: var(--nav-text-dim); background: none; border: none; padding: 0; gap: 3px; cursor: pointer;
    }
    .mobile-nav-item i { font-size: 1.2rem; }
    .mobile-nav-item span { font-size: 0.65rem; font-weight: 500; }
    .mobile-nav-item.active { color: var(--nav-gold); }

    /* Bottom Sheets */
    .bottom-sheet-backdrop {
        display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.6); z-index: 1060; opacity: 0; transition: opacity 0.2s ease;
    }
    .bottom-sheet-backdrop.active { display: block; opacity: 1; }
    .bottom-sheet {
        position: fixed; left: 0; right: 0; bottom: 0; background: var(--nav-bg);
        border-top-left-radius: 18px; border-top-right-radius: 18px; border-top: 1px solid var(--nav-border);
        padding: 0.75rem 1.25rem 1.5rem; z-index: 1070; transform: translateY(100%); transition: transform 0.25s ease;
    }
    .bottom-sheet.open { transform: translateY(0); }
    .bottom-sheet-drag-handle { width: 36px; height: 4px; background: var(--nav-border); border-radius: 2px; margin: 0 auto 0.85rem; }
    .bottom-sheet-title { color: #fff; font-size: 0.95rem; font-weight: 700; margin-bottom: 0.85rem; text-align: center; }
    .bottom-sheet-options { display: flex; flex-direction: column; gap: 0.5rem; }
    .bottom-sheet-item {
        display: flex; align-items: center; gap: 0.85rem; padding: 0.75rem 1rem; border-radius: 10px;
        background: var(--nav-bg-soft); color: var(--nav-text); text-decoration: none; font-size: 0.9rem; font-weight: 500;
    }
    .bottom-sheet-item i { font-size: 1.1rem; color: var(--nav-gold); }
    .bottom-sheet-item.active { background: rgba(212, 168, 71, 0.2); color: var(--nav-gold); }

    @media (max-width: 991.98px) {
        .app-sidebar { display: none; }
        .mobile-bottom-nav { display: block; }
        .page-content { 
            margin-left: 0; 
            margin-top: 0 !important; 
            padding-top: 0 !important; 
            padding-bottom: calc(var(--mobile-nav-height) + 12px); 
        }
    }
</style>

<!-- Desktop Sidebar -->
<aside class="app-sidebar">
    <div class="nav-brand">
        <?php if ($hasLogoImage): ?>
            <!-- Full Width Image Logo -->
            <img src="<?= htmlspecialchars($logoImagePath) ?>" 
                 alt="FineBullion Desk" 
                 class="nav-brand-logo-full" 
                 onerror="this.style.display='none'; document.getElementById('navBrandFallbackText').style.display='block';">
            <div class="nav-brand-text-fallback" id="navBrandFallbackText" style="display: none;">
                FineBullion Desk
            </div>
        <?php else: ?>
            <!-- Text Fallback when logo file is missing -->
            <div class="nav-brand-text-fallback">
                FineBullion Desk
            </div>
        <?php endif; ?>
    </div>

    <nav class="nav-links">
        <a href="dashboard.php" class="nav-link-item<?= nav_is_active('dashboard.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span>
        </a>
        <a href="customers.php" class="nav-link-item<?= $isCustomerActive ? ' active' : '' ?>">
            <i class="bi bi-person-fill"></i><span>Customer</span>
        </a>
        <a href="gold_exchange.php" class="nav-link-item<?= $isExchangeActive ? ' active' : '' ?>">
            <i class="bi bi-arrow-left-right"></i><span>Exchange</span>
        </a>
        <a href="gold_sale.php" class="nav-link-item<?= $isSaleActive ? ' active' : '' ?>">
            <i class="bi bi-cash-coin"></i><span>Sale</span>
        </a>
        <a href="gold_buy.php" class="nav-link-item<?= $isBuyActive ? ' active' : '' ?>">
            <i class="bi bi-cart-fill"></i><span>Buy</span>
        </a>
        <a href="expenses.php" class="nav-link-item<?= nav_is_active('expenses.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-wallet2"></i><span>Expenses</span>
        </a>
        <?php if ($isAdmin): ?>
            <a href="users.php" class="nav-link-item<?= nav_is_active('users.php', $navCurrentPage) ? ' active' : '' ?>">
                <i class="bi bi-people-fill"></i><span>Users</span>
            </a>
        <?php endif; ?>
    </nav>
    <div class="nav-footer">
        <div class="nav-user-name"><?= htmlspecialchars($navUser['username']) ?></div>
        <div class="nav-user-role"><?= htmlspecialchars($navUser['role'] ?: 'Member') ?></div>
        <a href="logout.php" class="nav-logout-btn"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>
</aside>

<!-- Mobile Bottom Navigation (5 Action Items) -->
<nav class="mobile-bottom-nav">
    <div class="mobile-nav-container">
        <button type="button" class="mobile-nav-item<?= $isCustomerActive ? ' active' : '' ?>" onclick="openSheet('sheetCustomer')">
            <i class="bi bi-person-fill"></i><span>Customer</span>
        </button>
        <button type="button" class="mobile-nav-item<?= $isExchangeActive ? ' active' : '' ?>" onclick="openSheet('sheetExchange')">
            <i class="bi bi-arrow-left-right"></i><span>Exchange</span>
        </button>
        <button type="button" class="mobile-nav-item<?= $isSaleActive ? ' active' : '' ?>" onclick="openSheet('sheetSale')">
            <i class="bi bi-cash-coin"></i><span>Sale</span>
        </button>
        <button type="button" class="mobile-nav-item<?= $isBuyActive ? ' active' : '' ?>" onclick="openSheet('sheetBuy')">
            <i class="bi bi-cart-fill"></i><span>Buy</span>
        </button>
        <button type="button" class="mobile-nav-item<?= $isMenuActive ? ' active' : '' ?>" onclick="openSheet('sheetMenu')">
            <i class="bi bi-three-dots"></i><span>Menu</span>
        </button>
    </div>
</nav>

<!-- Backdrop -->
<div class="bottom-sheet-backdrop" id="sheetBackdrop" onclick="closeSheets()"></div>

<!-- Bottom Sheets -->
<div class="bottom-sheet" id="sheetCustomer">
    <div class="bottom-sheet-drag-handle"></div>
    <div class="bottom-sheet-title">Customer Module</div>
    <div class="bottom-sheet-options">
        <a href="customers.php" class="bottom-sheet-item<?= nav_is_active('customers.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-people"></i><span>Customers List</span>
        </a>
    </div>
</div>

<div class="bottom-sheet" id="sheetExchange">
    <div class="bottom-sheet-drag-handle"></div>
    <div class="bottom-sheet-title">Exchange Module</div>
    <div class="bottom-sheet-options">
        <a href="gold_exchange.php" class="bottom-sheet-item<?= nav_is_active('gold_exchange.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-plus-circle"></i><span>New Exchange</span>
        </a>
        <a href="gold_exchange_list.php" class="bottom-sheet-item<?= nav_is_active('gold_exchange_list.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-journal-text"></i><span>Exchange List</span>
        </a>
    </div>
</div>

<div class="bottom-sheet" id="sheetSale">
    <div class="bottom-sheet-drag-handle"></div>
    <div class="bottom-sheet-title">Sale Module</div>
    <div class="bottom-sheet-options">
        <a href="gold_sale.php" class="bottom-sheet-item<?= nav_is_active('gold_sale.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-plus-circle"></i><span>New Gold Sale</span>
        </a>
        <a href="gold_sale_list.php" class="bottom-sheet-item<?= nav_is_active('gold_sale_list.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-journal-text"></i><span>Gold Sale List</span>
        </a>
    </div>
</div>

<div class="bottom-sheet" id="sheetBuy">
    <div class="bottom-sheet-drag-handle"></div>
    <div class="bottom-sheet-title">Buy Module</div>
    <div class="bottom-sheet-options">
        <a href="gold_buy.php" class="bottom-sheet-item<?= nav_is_active('gold_buy.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-plus-circle"></i><span>New Gold Buy</span>
        </a>
        <a href="gold_buy_list.php" class="bottom-sheet-item<?= nav_is_active('gold_buy_list.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-journal-text"></i><span>Gold Buy List</span>
        </a>
    </div>
</div>

<!-- Three Dot Menu (Home, Expenses, Users, Logout) -->
<div class="bottom-sheet" id="sheetMenu">
    <div class="bottom-sheet-drag-handle"></div>
    <div class="bottom-sheet-title">Main Menu</div>
    <div class="bottom-sheet-options">
        <a href="dashboard.php" class="bottom-sheet-item<?= nav_is_active('dashboard.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i><span>Dashboard (Home)</span>
        </a>
        <a href="expenses.php" class="bottom-sheet-item<?= nav_is_active('expenses.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-wallet2"></i><span>Expenses</span>
        </a>
        <?php if ($isAdmin): ?>
            <a href="users.php" class="bottom-sheet-item<?= nav_is_active('users.php', $navCurrentPage) ? ' active' : '' ?>">
                <i class="bi bi-people-fill"></i><span>User Management</span>
            </a>
        <?php endif; ?>
        <a href="logout.php" class="bottom-sheet-item" style="color: #ff6b6b;">
            <i class="bi bi-box-arrow-right" style="color: #ff6b6b;"></i><span>Logout</span>
        </a>
    </div>
</div>

<script>
    function openSheet(id) {
        closeSheets();
        const sheet = document.getElementById(id);
        const backdrop = document.getElementById('sheetBackdrop');
        if (sheet && backdrop) {
            backdrop.classList.add('active');
            sheet.classList.add('open');
        }
    }

    function closeSheets() {
        const backdrop = document.getElementById('sheetBackdrop');
        if (backdrop) backdrop.classList.remove('active');
        document.querySelectorAll('.bottom-sheet').forEach(sheet => sheet.classList.remove('open'));
    }
</script>