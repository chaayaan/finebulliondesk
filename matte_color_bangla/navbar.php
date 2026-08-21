<?php
/**
 * navbar.php
 * Clean Navigation with Dynamic Logo, Mobile Bottom Sheet, and PC Dropdown Menus
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
$isCustomerActive     = in_array($navCurrentPage, ['customers.php', 'customer_history.php']);
$isInventoryActive    = in_array($navCurrentPage, ['inventory.php', 'inventory_list.php', 'inventory_add.php']);
$isExchangeActive     = in_array($navCurrentPage, ['gold_exchange_inventory.php', 'gold_exchange_list.php', 'gold_exchange_edit_inventory.php']);
$isExchangeEditActive = ($navCurrentPage === 'gold_exchange_edit_inventory.php');

$isSaleActive         = in_array($navCurrentPage, ['gold_sale_inventory.php', 'gold_sale_list.php', 'gold_sale_edit_inventory.php']);
$isSaleEditActive     = ($navCurrentPage === 'gold_sale_edit_inventory.php');

$isBuyActive          = in_array($navCurrentPage, ['gold_buy.php', 'gold_buy_list.php', 'gold_buy_edit.php']);
$isBuyEditActive      = ($navCurrentPage === 'gold_buy_edit.php');

$isMenuActive         = in_array($navCurrentPage, ['inventory.php', 'expenses.php', 'customers.php', 'users.php']);

function nav_is_active(string $href, string $current): bool {
    return $href === $current;
}

// Preserve current URI for Edit routes (handles ?id=X)
$currentQueryString = $_SERVER['QUERY_STRING'] ?? '';
$exchangeEditUrl = 'gold_exchange_edit_inventory.php' . ($currentQueryString ? '?' . htmlspecialchars($currentQueryString) : '');
$saleEditUrl     = 'gold_sale_edit_inventory.php' . ($currentQueryString ? '?' . htmlspecialchars($currentQueryString) : '');
$buyEditUrl      = 'gold_buy_edit.php' . ($currentQueryString ? '?' . htmlspecialchars($currentQueryString) : '');
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
    :root {
        --nav-width: 212px;
        --nav-bg: #2F4156;
        --nav-bg-soft: #3A5068;
        --nav-border: rgba(255, 255, 255, 0.12);
        --nav-gold: #C8D9E6;
        --nav-text: #FFFFFF;
        --nav-text-dim: rgba(255, 255, 255, 0.65);
        --mobile-nav-height: 60px;
    }

    /* Zero out root margins and body padding */
    html, body {
        margin: 0 !important;
        padding: 0 !important;
    }

    /* Ensure no underlines across all interactive navigation links and buttons */
    a, a:hover, a:focus, a:active,
    button, button:hover, button:focus, button:active,
    .nav-link-item, .nav-sub-item, .mobile-nav-item, .bottom-sheet-item, .nav-logout-btn {
        text-decoration: none !important;
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
        border-radius: 8px; color: var(--nav-text); text-decoration: none !important;
        font-size: 0.88rem; font-weight: 500; cursor: pointer; background: none; border: none; width: 100%; text-align: left;
    }
    .nav-link-item i.nav-icon { font-size: 1.02rem; width: 20px; color: var(--nav-gold); text-align: center; }
    .nav-link-item:hover { background: var(--nav-bg-soft); color: #fff; text-decoration: none !important; }
    .nav-link-item.active { background: rgba(200, 217, 230, 0.18); color: var(--nav-gold); }

    /* Desktop Dropdown Styles */
    .nav-dropdown-toggle {
        justify-content: space-between;
    }
    .nav-dropdown-toggle .chevron-icon {
        font-size: 0.75rem;
        transition: transform 0.2s ease;
        color: var(--nav-text-dim);
    }
    .nav-dropdown.open .chevron-icon {
        transform: rotate(180deg);
    }
    .nav-submenu {
        display: none;
        flex-direction: column;
        gap: 0.15rem;
        padding-left: 0.8rem;
        margin-top: 0.15rem;
    }
    .nav-dropdown.open .nav-submenu {
        display: flex;
    }
    .nav-sub-item {
        display: flex; align-items: center; gap: 0.6rem; padding: 0.45rem 0.75rem;
        border-radius: 6px; color: var(--nav-text-dim); text-decoration: none !important;
        font-size: 0.82rem; font-weight: 500;
    }
    .nav-sub-item i { font-size: 0.9rem; color: var(--nav-gold); width: 16px; text-align: center; }
    .nav-sub-item:hover { background: var(--nav-bg-soft); color: #fff; text-decoration: none !important; }
    .nav-sub-item.active { background: rgba(200, 217, 230, 0.15); color: var(--nav-gold); font-weight: 600; }

    .nav-footer { border-top: 1px solid var(--nav-border); padding: 1rem; text-align: center; }
    .nav-user-name { font-size: 0.85rem; font-weight: 700; color: #fff; }
    .nav-user-role { font-size: 0.72rem; color: var(--nav-text-dim); text-transform: capitalize; margin-bottom: 0.5rem; }
    .nav-logout-btn {
        display: flex; align-items: center; justify-content: center; gap: 0.4rem;
        width: 100%; padding: 0.45rem; border-radius: 7px; border: 1px solid var(--nav-border);
        color: var(--nav-gold); text-decoration: none !important; font-size: 0.82rem; font-weight: 600;
    }

    .page-content { 
        margin-left: var(--nav-width); 
        margin-top: 0 !important; 
        padding-top: 0 !important; 
        min-height: 100vh; 
        background: #F5EFEB; 
    }

    /* Global Header Fix: Ensures no top spacing and rounds bottom corners only */
    .list-header {
        margin-top: 0 !important;
        border-top-left-radius: 0 !important;
        border-top-right-radius: 0 !important;
        border-bottom-left-radius: 20px;
        border-bottom-right-radius: 20px;
    }

    /* Mobile Bottom Navigation */
    .mobile-bottom-nav {
        display: none; position: fixed; bottom: 0; left: 0; right: 0;
        height: var(--mobile-nav-height); background: var(--nav-bg);
        border-top: 1px solid var(--nav-border); z-index: 1050;
    }
    .mobile-nav-container { display: flex; height: 100%; align-items: center; justify-content: space-around; }
    .mobile-nav-item {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        flex: 1; height: 100%; color: var(--nav-text-dim); background: none; border: none; padding: 0; gap: 3px; cursor: pointer;
        text-decoration: none !important;
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
        background: var(--nav-bg-soft); color: var(--nav-text); text-decoration: none !important; font-size: 0.9rem; font-weight: 500;
    }
    .bottom-sheet-item i { font-size: 1.1rem; color: var(--nav-gold); }
    .bottom-sheet-item.active { background: rgba(200, 217, 230, 0.2); color: var(--nav-gold); }

    /* Mobile Sheet Logo Header */
    .bottom-sheet-logo-container {
        display: flex;
        justify-content: center;
        align-items: center;
        margin-bottom: 0.5rem;
    }
    .bottom-sheet-logo {
        max-height: 50px;
        max-width: 80%;
        object-fit: contain;
    }

    @media (max-width: 991.98px) {
        .app-sidebar { display: none; }
        .mobile-bottom-nav { display: block; }
        .page-content { 
            margin-left: 0; 
            margin-top: 0 !important; 
            padding-top: 0 !important; 
            padding-bottom: calc(var(--mobile-nav-height) + 12px); 
        }

        /* ---- Mobile-only inversion: off-white surfaces, navy icons/text/logo ---- */
        .mobile-bottom-nav {
            background: #F8F5F0;
            border-top: 1px solid var(--sky, #C8D9E6);
        }
        .mobile-nav-item { color: var(--teal, #567C8D); }
        .mobile-nav-item.active { color: var(--navy, #2F4156); }

        .bottom-sheet {
            background: #F8F5F0;
            border-top: 1px solid var(--sky, #C8D9E6);
        }
        .bottom-sheet-drag-handle { background: var(--sky, #C8D9E6); }
        .bottom-sheet-title { color: var(--navy, #2F4156); }
        .bottom-sheet-item {
            background: #FFFFFF;
            color: var(--navy, #2F4156);
            border: 1px solid var(--sky, #C8D9E6);
        }
        .bottom-sheet-item i { color: var(--navy, #2F4156); }
        .bottom-sheet-item.active {
            background: rgba(47, 65, 86, 0.08);
            color: var(--navy, #2F4156);
        }

        /* Logo/wordmark inside the mobile menu sheet turns navy */
        #sheetMenu .nav-brand-text-fallback { color: var(--navy, #2F4156); }
        #sheetMenu .bottom-sheet-logo {
            filter: brightness(0) saturate(100%) invert(20%) sepia(22%) saturate(910%) hue-rotate(163deg) brightness(94%) contrast(88%);
        }

        /* ---- User info card (avatar/name/role + logout) in the mobile menu sheet ---- */
        .menu-user-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            background: rgba(47, 65, 86, 0.06);
            border-radius: 14px;
            padding: 0.75rem 1rem;
            margin-bottom: 0.85rem;
        }
        .menu-user-info { display: flex; align-items: center; gap: 0.65rem; min-width: 0; }
        .menu-user-avatar {
            width: 42px; height: 42px; border-radius: 50%; object-fit: cover; flex-shrink: 0;
        }
        .menu-user-avatar-fallback {
            display: flex; align-items: center; justify-content: center;
            background: var(--sky, #C8D9E6); color: var(--navy, #2F4156); font-size: 1.1rem;
        }
        .menu-user-text { min-width: 0; }
        .menu-user-name {
            font-size: 0.9rem; font-weight: 700; color: var(--navy, #2F4156);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .menu-user-role {
            font-size: 0.75rem; color: var(--teal, #567C8D); text-transform: capitalize;
        }
        .menu-logout-btn {
            display: flex; align-items: center; gap: 0.35rem; flex-shrink: 0;
            font-size: 0.82rem; font-weight: 600; color: #A6434B;
            text-decoration: none !important; padding: 0.3rem 0.1rem;
        }
        .menu-logout-btn i { font-size: 1rem; color: #A6434B; }

        /* ---- Icon-tile menu items (Inventory/Expenses/Customers/Users) ---- */
        .bs-item-tile {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            background: #FFFFFF;
            border: none;
            border-radius: 14px;
            padding: 0.85rem 1rem;
        }
        .bs-item-tile .bs-item-icon {
            width: 40px; height: 40px; min-width: 40px; border-radius: 12px;
            background: rgba(47, 65, 86, 0.06);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; color: var(--navy, #2F4156);
        }
        .bs-item-tile .bs-item-label {
            flex: 1; font-size: 0.92rem; font-weight: 600; color: var(--navy, #2F4156);
        }
        .bs-item-tile .bs-item-chevron {
            font-size: 0.85rem; color: var(--teal, #567C8D);
        }
        .bs-item-tile.active { background: #FFFFFF; }
        .bs-item-tile.active .bs-item-icon { background: var(--sky, #C8D9E6); }
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
            <i class="bi bi-grid-1x2-fill nav-icon"></i><span>ড্যাশবোর্ড</span>
        </a>
        <a href="inventory.php" class="nav-link-item<?= $isInventoryActive ? ' active' : '' ?>">
            <i class="bi bi-box-seam-fill nav-icon"></i><span>ইনভেন্টরি</span>
        </a>
        <a href="customers.php" class="nav-link-item<?= $isCustomerActive ? ' active' : '' ?>">
            <i class="bi bi-person-fill nav-icon"></i><span>কাস্টমার</span>
        </a>

        <!-- Exchange Dropdown -->
        <div class="nav-dropdown<?= $isExchangeActive ? ' open' : '' ?>">
            <button type="button" class="nav-link-item nav-dropdown-toggle<?= $isExchangeActive ? ' active' : '' ?>" onclick="toggleNavDropdown(this)">
                <div style="display: flex; align-items: center; gap: 0.7rem;">
                    <i class="bi bi-arrow-left-right nav-icon"></i><span>এক্সচেঞ্জ</span>
                </div>
                <i class="bi bi-chevron-down chevron-icon"></i>
            </button>
            <div class="nav-submenu">
                <a href="gold_exchange_inventory.php" class="nav-sub-item<?= nav_is_active('gold_exchange_inventory.php', $navCurrentPage) ? ' active' : '' ?>">
                    <i class="bi bi-plus-circle"></i><span>নতুন এক্সচেঞ্জ</span>
                </a>
                <a href="gold_exchange_list.php" class="nav-sub-item<?= nav_is_active('gold_exchange_list.php', $navCurrentPage) ? ' active' : '' ?>">
                    <i class="bi bi-journal-text"></i><span>এক্সচেঞ্জ তালিকা</span>
                </a>
                <?php if ($isExchangeEditActive): ?>
                    <a href="<?= $exchangeEditUrl ?>" class="nav-sub-item active">
                        <i class="bi bi-pencil-square"></i><span>এক্সচেঞ্জ এডিট</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sale Dropdown -->
        <div class="nav-dropdown<?= $isSaleActive ? ' open' : '' ?>">
            <button type="button" class="nav-link-item nav-dropdown-toggle<?= $isSaleActive ? ' active' : '' ?>" onclick="toggleNavDropdown(this)">
                <div style="display: flex; align-items: center; gap: 0.7rem;">
                    <i class="bi bi-cash-coin nav-icon"></i><span>বিক্রয়</span>
                </div>
                <i class="bi bi-chevron-down chevron-icon"></i>
            </button>
            <div class="nav-submenu">
                <a href="gold_sale_inventory.php" class="nav-sub-item<?= nav_is_active('gold_sale_inventory.php', $navCurrentPage) ? ' active' : '' ?>">
                    <i class="bi bi-plus-circle"></i><span>নতুন বিক্রয়</span>
                </a>
                <a href="gold_sale_list.php" class="nav-sub-item<?= nav_is_active('gold_sale_list.php', $navCurrentPage) ? ' active' : '' ?>">
                    <i class="bi bi-journal-text"></i><span>বিক্রয় তালিকা</span>
                </a>
                <?php if ($isSaleEditActive): ?>
                    <a href="<?= $saleEditUrl ?>" class="nav-sub-item active">
                        <i class="bi bi-pencil-square"></i><span>বিক্রয় এডিট</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Buy Dropdown -->
        <div class="nav-dropdown<?= $isBuyActive ? ' open' : '' ?>">
            <button type="button" class="nav-link-item nav-dropdown-toggle<?= $isBuyActive ? ' active' : '' ?>" onclick="toggleNavDropdown(this)">
                <div style="display: flex; align-items: center; gap: 0.7rem;">
                    <i class="bi bi-cart-fill nav-icon"></i><span>ক্রয়</span>
                </div>
                <i class="bi bi-chevron-down chevron-icon"></i>
            </button>
            <div class="nav-submenu">
                <a href="gold_buy.php" class="nav-sub-item<?= nav_is_active('gold_buy.php', $navCurrentPage) ? ' active' : '' ?>">
                    <i class="bi bi-plus-circle"></i><span>নতুন ক্রয়</span>
                </a>
                <a href="gold_buy_list.php" class="nav-sub-item<?= nav_is_active('gold_buy_list.php', $navCurrentPage) ? ' active' : '' ?>">
                    <i class="bi bi-journal-text"></i><span>ক্রয় তালিকা</span>
                </a>
                <?php if ($isBuyEditActive): ?>
                    <a href="<?= $buyEditUrl ?>" class="nav-sub-item active">
                        <i class="bi bi-pencil-square"></i><span>ক্রয় এডিট</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <a href="expenses.php" class="nav-link-item<?= nav_is_active('expenses.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-wallet2 nav-icon"></i><span>খরচ</span>
        </a>
        <?php if ($isAdmin): ?>
            <a href="users.php" class="nav-link-item<?= nav_is_active('users.php', $navCurrentPage) ? ' active' : '' ?>">
                <i class="bi bi-people-fill nav-icon"></i><span>ইউজার</span>
            </a>
        <?php endif; ?>
    </nav>

    <div class="nav-footer">
        <div class="nav-user-name"><?= htmlspecialchars($navUser['username']) ?></div>
        <div class="nav-user-role"><?= htmlspecialchars($navUser['role'] ?: 'Member') ?></div>
        <a href="logout.php" class="nav-logout-btn"><i class="bi bi-box-arrow-right"></i> লগআউট</a>
    </div>
</aside>

<!-- Mobile Bottom Navigation -->
<nav class="mobile-bottom-nav">
    <div class="mobile-nav-container">
        <button type="button" class="mobile-nav-item<?= nav_is_active('dashboard.php', $navCurrentPage) ? ' active' : '' ?>" onclick="openSheet('sheetOther')">
            <i class="bi bi-grid-1x2-fill"></i><span>ড্যাশবোর্ড</span>
        </button>
        <button type="button" class="mobile-nav-item<?= $isExchangeActive ? ' active' : '' ?>" onclick="openSheet('sheetExchange')">
            <i class="bi bi-arrow-left-right"></i><span>এক্সচেঞ্জ</span>
        </button>
        <button type="button" class="mobile-nav-item<?= $isSaleActive ? ' active' : '' ?>" onclick="openSheet('sheetSale')">
            <i class="bi bi-cash-coin"></i><span>বিক্রয়</span>
        </button>
        <button type="button" class="mobile-nav-item<?= $isBuyActive ? ' active' : '' ?>" onclick="openSheet('sheetBuy')">
            <i class="bi bi-cart-fill"></i><span>ক্রয়</span>
        </button>
        <button type="button" class="mobile-nav-item<?= $isMenuActive ? ' active' : '' ?>" onclick="openSheet('sheetMenu')">
            <i class="bi bi-three-dots"></i><span>মেন্যু</span>
        </button>
    </div>
</nav>

<!-- Backdrop -->
<div class="bottom-sheet-backdrop" id="sheetBackdrop" onclick="closeSheets()"></div>

<!-- Bottom Sheets -->
<div class="bottom-sheet" id="sheetOther">
    <div class="bottom-sheet-drag-handle"></div>
    <div class="bottom-sheet-title">ড্যাশবোর্ড</div>
    <div class="bottom-sheet-options">
        <a href="dashboard.php" class="bottom-sheet-item<?= nav_is_active('dashboard.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i><span>ড্যাশবোর্ড</span>
        </a>
    </div>
</div>

<div class="bottom-sheet" id="sheetExchange">
    <div class="bottom-sheet-drag-handle"></div>
    <div class="bottom-sheet-title">এক্সচেঞ্জ মডিউল</div>
    <div class="bottom-sheet-options">
        <a href="gold_exchange_inventory.php" class="bottom-sheet-item<?= nav_is_active('gold_exchange_inventory.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-plus-circle"></i><span>নতুন এক্সচেঞ্জ</span>
        </a>
        <a href="gold_exchange_list.php" class="bottom-sheet-item<?= nav_is_active('gold_exchange_list.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-journal-text"></i><span>এক্সচেঞ্জ তালিকা</span>
        </a>
    </div>
</div>

<div class="bottom-sheet" id="sheetSale">
    <div class="bottom-sheet-drag-handle"></div>
    <div class="bottom-sheet-title">বিক্রয় মডিউল</div>
    <div class="bottom-sheet-options">
        <a href="gold_sale_inventory.php" class="bottom-sheet-item<?= nav_is_active('gold_sale_inventory.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-plus-circle"></i><span>নতুন সোনা বিক্রয়</span>
        </a>
        <a href="gold_sale_list.php" class="bottom-sheet-item<?= nav_is_active('gold_sale_list.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-journal-text"></i><span>সোনা বিক্রয় তালিকা</span>
        </a>
    </div>
</div>

<div class="bottom-sheet" id="sheetBuy">
    <div class="bottom-sheet-drag-handle"></div>
    <div class="bottom-sheet-title">ক্রয় মডিউল</div>
    <div class="bottom-sheet-options">
        <a href="gold_buy.php" class="bottom-sheet-item<?= nav_is_active('gold_buy.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-plus-circle"></i><span>নতুন সোনা ক্রয়</span>
        </a>
        <a href="gold_buy_list.php" class="bottom-sheet-item<?= nav_is_active('gold_buy_list.php', $navCurrentPage) ? ' active' : '' ?>">
            <i class="bi bi-journal-text"></i><span>সোনা ক্রয় তালিকা</span>
        </a>
    </div>
</div>

<!-- Mobile Menu Sheet (Inventory, Expenses, Customer, Users, Logout) -->
<div class="bottom-sheet" id="sheetMenu">
    <div class="bottom-sheet-drag-handle"></div>

    <!-- Logo display above user info card -->
    <div class="bottom-sheet-logo-container">
        <?php if ($hasLogoImage): ?>
            <img src="<?= htmlspecialchars($logoImagePath) ?>" 
                 alt="FineBullion Desk" 
                 class="bottom-sheet-logo" 
                 onerror="this.style.display='none'; document.getElementById('menuSheetFallbackText').style.display='block';">
            <div class="nav-brand-text-fallback" id="menuSheetFallbackText" style="display: none;">
                FineBullion Desk
            </div>
        <?php else: ?>
            <div class="nav-brand-text-fallback">
                FineBullion Desk
            </div>
        <?php endif; ?>
    </div>

    <!-- User info card: avatar + name/role on the left, logout on the right -->
    <div class="menu-user-card">
        <div class="menu-user-info">
            <?php if (!empty($navUser['photo_path'])): ?>
                <img src="<?= htmlspecialchars($navUser['photo_path']) ?>" alt="" class="menu-user-avatar">
            <?php else: ?>
                <div class="menu-user-avatar menu-user-avatar-fallback"><i class="bi bi-person-fill"></i></div>
            <?php endif; ?>
            <div class="menu-user-text">
                <div class="menu-user-name"><?= htmlspecialchars($navUser['username']) ?></div>
                <div class="menu-user-role"><?= htmlspecialchars($navUser['role'] ?: 'Member') ?></div>
            </div>
        </div>
        <a href="logout.php" class="menu-logout-btn">
            <i class="bi bi-box-arrow-right"></i><span>লগআউট</span>
        </a>
    </div>

    <div class="bottom-sheet-options">
        <a href="inventory.php" class="bottom-sheet-item bs-item-tile<?= nav_is_active('inventory.php', $navCurrentPage) ? ' active' : '' ?>">
            <span class="bs-item-icon"><i class="bi bi-box-seam-fill"></i></span>
            <span class="bs-item-label">ইনভেন্টরি</span>
            <i class="bi bi-chevron-right bs-item-chevron"></i>
        </a>
        <a href="expenses.php" class="bottom-sheet-item bs-item-tile<?= nav_is_active('expenses.php', $navCurrentPage) ? ' active' : '' ?>">
            <span class="bs-item-icon"><i class="bi bi-wallet2"></i></span>
            <span class="bs-item-label">খরচ</span>
            <i class="bi bi-chevron-right bs-item-chevron"></i>
        </a>
        <a href="customers.php" class="bottom-sheet-item bs-item-tile<?= nav_is_active('customers.php', $navCurrentPage) ? ' active' : '' ?>">
            <span class="bs-item-icon"><i class="bi bi-person-fill"></i></span>
            <span class="bs-item-label">কাস্টমার</span>
            <i class="bi bi-chevron-right bs-item-chevron"></i>
        </a>
        <?php if ($isAdmin): ?>
            <a href="users.php" class="bottom-sheet-item bs-item-tile<?= nav_is_active('users.php', $navCurrentPage) ? ' active' : '' ?>">
                <span class="bs-item-icon"><i class="bi bi-people-fill"></i></span>
                <span class="bs-item-label">ইউজার</span>
                <i class="bi bi-chevron-right bs-item-chevron"></i>
            </a>
        <?php endif; ?>
    </div>
</div>

<script>
    function toggleNavDropdown(btn) {
        const parentDropdown = btn.closest('.nav-dropdown');
        if (parentDropdown) {
            parentDropdown.classList.toggle('open');
        }
    }

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