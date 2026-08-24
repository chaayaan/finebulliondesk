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
$isExpenseActive      = ($navCurrentPage === 'expenses.php');
$isUserActive         = ($navCurrentPage === 'users.php');

$isExchangeActive     = in_array($navCurrentPage, ['gold_exchange_inventory.php', 'gold_exchange_list.php', 'gold_exchange_edit_inventory.php']);
$isExchangeEditActive = ($navCurrentPage === 'gold_exchange_edit_inventory.php');

$isSaleActive         = in_array($navCurrentPage, ['gold_sale_inventory.php', 'gold_sale_list.php', 'gold_sale_edit_inventory.php']);
$isSaleEditActive     = ($navCurrentPage === 'gold_sale_edit_inventory.php');

$isBuyActive          = in_array($navCurrentPage, ['gold_buy.php', 'gold_buy_list.php', 'gold_buy_edit.php']);
$isBuyEditActive      = ($navCurrentPage === 'gold_buy_edit.php');

$isMenuActive         = ($isInventoryActive || $isExpenseActive || $isCustomerActive || $isUserActive);

function nav_is_active(string $href, string $current): bool {
    return $href === $current;
}

// Preserve current URI for Edit routes (handles ?id=X)
$currentQueryString = $_SERVER['QUERY_STRING'] ?? '';
$exchangeEditUrl = 'gold_exchange_edit_inventory.php' . ($currentQueryString ? '?' . htmlspecialchars($currentQueryString) : '');
$saleEditUrl     = 'gold_sale_edit_inventory.php' . ($currentQueryString ? '?' . htmlspecialchars($currentQueryString) : '');
$buyEditUrl      = 'gold_buy_edit.php' . ($currentQueryString ? '?' . htmlspecialchars($currentQueryString) : '');

/* Dynamic Menu Icon Logic for Mobile 3-Dot Menu */
$menuIconClass = 'bi-three-dots';
$menuLabelText = 'মেন্যু';

if ($isInventoryActive) {
    $menuIconClass = 'bi-box-seam-fill';
    $menuLabelText = 'ইনভেন্টরি';
} elseif ($isExpenseActive) {
    $menuIconClass = 'bi-wallet2';
    $menuLabelText = 'খরচ';
} elseif ($isCustomerActive) {
    $menuIconClass = 'bi-person-fill';
    $menuLabelText = 'কাস্টমার';
} elseif ($isUserActive) {
    $menuIconClass = 'bi-people-fill';
    $menuLabelText = 'ইউজার';
}

/* =========================================================
 * License Status System (self-contained inside navbar.php)
 * ========================================================= */
$branchName        = '';
$licenseKey         = '';
$branchAppLink      = '';
$licenseStatusRaw   = '';
$expireDate         = '';
$lastRenewDate      = '';
$daysLeft           = null;
$daysLeftText       = '';
$licenseWarningLevel = 'normal';
$hasLicenseRecord   = false;
$isLicenseExpired   = false;

if (!defined('LICENSE_DB_HOST')) define('LICENSE_DB_HOST', 'localhost');
if (!defined('LICENSE_DB_USER')) define('LICENSE_DB_USER', 'root');
if (!defined('LICENSE_DB_PASS')) define('LICENSE_DB_PASS', '');
if (!defined('LICENSE_DB_NAME')) define('LICENSE_DB_NAME', 'license_manager');

function license_human_days(?int $days): string
{
    if ($days === null) return '';

    if ($days < 0) {
        $absDays = abs($days);
        return 'Expired ' . $absDays . ' Day' . ($absDays === 1 ? '' : 's') . ' Ago';
    }
    if ($days === 0)  return 'Today';
    if ($days === 1)  return 'Tomorrow';
    if ($days < 30)   return $days . ' Days Left';

    $years         = intdiv($days, 365);
    $remAfterYears = $days % 365;
    $months        = intdiv($remAfterYears, 30);
    $remDays       = $remAfterYears % 30;

    $parts = [];
    if ($years > 0)  $parts[] = $years . ' Year' . ($years > 1 ? 's' : '');
    if ($months > 0) $parts[] = $months . ' Month' . ($months > 1 ? 's' : '');
    if ($years === 0 && $remDays > 0) $parts[] = $remDays . ' Day' . ($remDays > 1 ? 's' : '');
    if (empty($parts)) $parts[] = $days . ' Days';

    return implode(' ', $parts) . ' Left';
}

$licenseConn = @mysqli_connect(LICENSE_DB_HOST, LICENSE_DB_USER, LICENSE_DB_PASS, LICENSE_DB_NAME);

if ($licenseConn) {
    $licenseSql = "SELECT branch_name, branch_app_link, license_key, expire_date, last_renew_date, status
                   FROM finebulion_desk_licenses
                   ORDER BY id DESC LIMIT 1";
    $licenseResult = mysqli_query($licenseConn, $licenseSql);

    if ($licenseResult && ($licenseRow = mysqli_fetch_assoc($licenseResult))) {
        $hasLicenseRecord = true;

        $branchName      = $licenseRow['branch_name'];
        $licenseKey      = $licenseRow['license_key'];
        $branchAppLink   = $licenseRow['branch_app_link'];
        $licenseStatusRaw = $licenseRow['status'];
        $expireDate      = $licenseRow['expire_date'];
        $lastRenewDate   = $licenseRow['last_renew_date'];

        $todayDate     = new DateTime('today');
        $expireDateObj = new DateTime($expireDate);
        $interval      = $todayDate->diff($expireDateObj);
        $daysLeft      = (int)$interval->format('%r%a');

        $isLicenseExpired = $daysLeft < 0;
        $daysLeftText     = license_human_days($daysLeft);

        if ($isLicenseExpired) {
            $licenseWarningLevel = 'expired';
        } elseif ($daysLeft === 0) {
            $licenseWarningLevel = 'today';
        } elseif ($daysLeft >= 1 && $daysLeft <= 3) {
            $licenseWarningLevel = 'darkred';
        } elseif ($daysLeft >= 4 && $daysLeft <= 7) {
            $licenseWarningLevel = 'red';
        } elseif ($daysLeft >= 8 && $daysLeft <= 30) {
            $licenseWarningLevel = 'orange';
        } else {
            $licenseWarningLevel = 'normal';
        }
    }

    mysqli_close($licenseConn);
}
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
        --mobile-nav-height: 66px;

        /* License status accent colors */
        --license-gold: #D4AF37;
        --license-gold-bg: rgba(212, 175, 55, 0.16);
        --license-orange: #E8973B;
        --license-orange-bg: rgba(232, 151, 59, 0.18);
        --license-red: #D64545;
        --license-red-bg: rgba(214, 69, 69, 0.18);
        --license-darkred: #8B1E1E;
        --license-darkred-bg: rgba(139, 30, 30, 0.22);
    }

    html, body {
        margin: 0 !important;
        padding: 0 !important;
    }

    a, a:hover, a:focus, a:active,
    button, button:hover, button:focus, button:active,
    .nav-link-item, .nav-sub-item, .mobile-nav-item, .bottom-sheet-item, .nav-logout-btn {
        text-decoration: none !important;
    }

    .page-content,
    .main-wrapper,
    .content-wrapper,
    .container,
    .container-fluid {
        margin-top: 0 !important;
        padding-top: 0 !important;
    }

    .list-header {
        margin-top: 0 !important;
        border-top-left-radius: 0 !important;
        border-top-right-radius: 0 !important;
        position: relative;
        top: 0;
    }

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
    
    .nav-brand {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem 0.75rem;
        border-bottom: 1px solid var(--nav-border);
        min-height: 48px;
        box-sizing: border-box;
    }
    
    .nav-brand-logo-full {
        width: 100%;
        max-height: 42px;
        object-fit: contain;
        display: block;
    }

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

    .nav-dropdown-toggle { justify-content: space-between; }
    .nav-dropdown-toggle .chevron-icon { font-size: 0.75rem; transition: transform 0.2s ease; color: var(--nav-text-dim); }
    .nav-dropdown.open .chevron-icon { transform: rotate(180deg); }
    .nav-submenu { display: none; flex-direction: column; gap: 0.15rem; padding-left: 0.8rem; margin-top: 0.15rem; }
    .nav-dropdown.open .nav-submenu { display: flex; }
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
        height: var(--mobile-nav-height); background: #F8F5F0;
        border-top: 1px solid #C8D9E6; z-index: 1050; padding: 0 4px;
    }
    .mobile-nav-container { display: flex; height: 100%; align-items: center; justify-content: space-between; }
    .mobile-nav-item {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        flex: 1; height: 82%; color: #567C8D; background: none; border: none; padding: 2px 4px; gap: 2px; cursor: pointer;
        text-decoration: none !important; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        border-radius: 24px; margin: 0 2px;
    }
    .mobile-nav-item i { font-size: 1.25rem; }
    .mobile-nav-item span { font-size: 0.68rem; font-weight: 600; line-height: 1; }

    /* Active State: Capsule/Pill Glow Effect */
    .mobile-nav-item.active {
        background: #1C2D42;
        color: #FFFFFF !important;
        flex-direction: row;
        gap: 6px;
        padding: 0 12px;
        border-radius: 30px;
        box-shadow: 0 0 12px rgba(28, 45, 66, 0.35);
    }
    .mobile-nav-item.active i { font-size: 1.15rem; color: #FFFFFF; }
    .mobile-nav-item.active span { font-size: 0.8rem; font-weight: 700; color: #FFFFFF; }

    /* Bottom Sheets */
    .bottom-sheet-backdrop {
        display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.6); z-index: 1060; opacity: 0; transition: opacity 0.2s ease;
    }
    .bottom-sheet-backdrop.active { display: block; opacity: 1; }
    .bottom-sheet {
        position: fixed; left: 0; right: 0; bottom: 0; background: #F8F5F0;
        border-top-left-radius: 18px; border-top-right-radius: 18px; border-top: 1px solid #C8D9E6;
        padding: 0.75rem 1.25rem 1.5rem; z-index: 1070; transform: translateY(100%); transition: transform 0.25s ease;
    }
    .bottom-sheet.open { transform: translateY(0); }
    .bottom-sheet-drag-handle { width: 36px; height: 4px; background: #C8D9E6; border-radius: 2px; margin: 0 auto 0.85rem; }
    .bottom-sheet-title { color: #2F4156; font-size: 0.95rem; font-weight: 700; margin-bottom: 0.85rem; text-align: center; }
    .bottom-sheet-options { display: flex; flex-direction: column; gap: 0.5rem; }
    .bottom-sheet-item {
        display: flex; align-items: center; gap: 0.85rem; padding: 0.75rem 1rem; border-radius: 10px;
        background: #FFFFFF; color: #2F4156; text-decoration: none !important; font-size: 0.9rem; font-weight: 500;
        border: 1px solid #C8D9E6;
    }
    .bottom-sheet-item i { font-size: 1.1rem; color: #2F4156; }
    .bottom-sheet-item.active { background: rgba(47, 65, 86, 0.08); color: #2F4156; }

    .bottom-sheet-logo-container { display: flex; justify-content: center; align-items: center; margin-bottom: 0.5rem; }
    .bottom-sheet-logo { max-height: 50px; max-width: 80%; object-fit: contain; }

    @media (max-width: 991.98px) {
        .app-sidebar { display: none; }
        .mobile-bottom-nav { display: block; }
        .page-content { 
            margin-left: 0; 
            margin-top: 0 !important; 
            padding-top: 0 !important; 
            padding-bottom: calc(var(--mobile-nav-height) + 12px); 
        }

        #sheetMenu .nav-brand-text-fallback { color: #2F4156; }
        #sheetMenu .bottom-sheet-logo {
            filter: brightness(0) saturate(100%) invert(20%) sepia(22%) saturate(910%) hue-rotate(163deg) brightness(94%) contrast(88%);
        }

        .menu-user-card {
            display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;
            background: rgba(47, 65, 86, 0.06); border-radius: 14px; padding: 0.75rem 1rem; margin-bottom: 0.85rem;
        }
        .menu-user-info { display: flex; align-items: center; gap: 0.65rem; min-width: 0; }
        .menu-user-avatar { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
        .menu-user-avatar-fallback {
            display: flex; align-items: center; justify-content: center;
            background: #C8D9E6; color: #2F4156; font-size: 1.1rem;
        }
        .menu-user-text { min-width: 0; }
        .menu-user-name { font-size: 0.9rem; font-weight: 700; color: #2F4156; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .menu-user-role { font-size: 0.75rem; color: #567C8D; text-transform: capitalize; }
        .menu-logout-btn {
            display: flex; align-items: center; gap: 0.35rem; flex-shrink: 0;
            font-size: 0.82rem; font-weight: 600; color: #A6434B; text-decoration: none !important; padding: 0.3rem 0.1rem;
        }
        .menu-logout-btn i { font-size: 1rem; color: #A6434B; }

        .bs-item-tile {
            display: flex; align-items: center; gap: 0.85rem; background: #FFFFFF;
            border: none; border-radius: 14px; padding: 0.85rem 1rem;
        }
        .bs-item-tile .bs-item-icon {
            width: 40px; height: 40px; min-width: 40px; border-radius: 12px;
            background: rgba(47, 65, 86, 0.06); display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; color: #2F4156;
        }
        .bs-item-tile .bs-item-label { flex: 1; font-size: 0.92rem; font-weight: 600; color: #2F4156; }
        .bs-item-tile .bs-item-chevron { font-size: 0.85rem; color: #567C8D; }
        .bs-item-tile.active { background: #FFFFFF; }
        .bs-item-tile.active .bs-item-icon { background: #C8D9E6; }

        #sheetMenu .nav-license-widget { background: #FFFFFF; border: 1px solid #C8D9E6; }
        #sheetMenu .nav-license-widget:hover { background: #FFFFFF; }
        #sheetMenu .nav-license-branch { color: #2F4156; }
        #sheetMenu .nav-license-widget.nav-license-expired {
            background: rgba(139, 30, 30, 0.08); border-color: rgba(139, 30, 30, 0.35);
        }
        #sheetMenu .nav-license-widget.nav-license-expired .nav-license-icon { color: var(--license-darkred); background: rgba(139,30,30,0.14); }
        #sheetMenu .nav-license-widget.nav-license-expired .nav-license-days { color: var(--license-darkred); }
    }

    /* License Status Widget */
    @keyframes licensePulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(139, 30, 30, 0.35); }
        50%      { box-shadow: 0 0 0 6px rgba(139, 30, 30, 0); }
    }

    .nav-license-widget {
        display: flex; align-items: center; gap: 0.65rem;
        margin: 0 0.75rem 0.85rem; padding: 0.6rem 0.7rem;
        border-radius: 12px; cursor: pointer;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.1);
        transition: background 0.15s ease, transform 0.1s ease;
    }
    .nav-license-widget:hover { background: rgba(255, 255, 255, 0.12); }
    .nav-license-widget:active { transform: scale(0.98); }

    .nav-license-icon {
        width: 34px; height: 34px; min-width: 34px; border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem; background: var(--license-gold-bg); color: var(--license-gold);
    }
    .nav-license-text { min-width: 0; }
    .nav-license-branch { font-size: 0.82rem; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .nav-license-days { font-size: 0.72rem; font-weight: 600; color: var(--license-gold); margin-top: 1px; }

    .nav-license-widget.nav-license-orange .nav-license-icon { background: var(--license-orange-bg); color: var(--license-orange); }
    .nav-license-widget.nav-license-orange .nav-license-days { color: var(--license-orange); }

    .nav-license-widget.nav-license-red .nav-license-icon { background: var(--license-red-bg); color: var(--license-red); }
    .nav-license-widget.nav-license-red .nav-license-days { color: var(--license-red); }

    .nav-license-widget.nav-license-darkred { animation: licensePulse 2s infinite; }
    .nav-license-widget.nav-license-darkred .nav-license-icon { background: var(--license-darkred-bg); color: var(--license-darkred); }
    .nav-license-widget.nav-license-darkred .nav-license-days { color: var(--license-darkred); font-weight: 700; }

    .nav-license-widget.nav-license-today { animation: licensePulse 1.4s infinite; }
    .nav-license-widget.nav-license-today .nav-license-icon { background: var(--license-red-bg); color: var(--license-red); }
    .nav-license-widget.nav-license-today .nav-license-days { color: var(--license-red); font-weight: 700; }

    .nav-license-widget.nav-license-expired {
        background: rgba(139, 30, 30, 0.28);
        border-color: rgba(139, 30, 30, 0.5);
        animation: licensePulse 1.2s infinite;
    }
    .nav-license-widget.nav-license-expired .nav-license-icon { background: rgba(255, 255, 255, 0.18); color: #fff; }
    .nav-license-widget.nav-license-expired .nav-license-days { color: #fff; font-weight: 700; }

    /* License Details Modal */
    .license-modal-backdrop {
        display: none; position: fixed; inset: 0; z-index: 5000;
        background: rgba(47, 65, 86, 0.55);
        align-items: center; justify-content: center; padding: 1rem;
        font-family: system-ui, -apple-system, sans-serif;
    }
    .license-modal-backdrop.open { display: flex; }

    .license-modal {
        background: #FDFBF8; border-radius: 18px; width: 100%; max-width: 420px;
        max-height: 90vh; overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.28);
    }
    .license-modal-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 1.1rem 1.3rem; border-bottom: 1px solid #EDE6DC; background: #FFFFFF;
        border-top-left-radius: 18px; border-top-right-radius: 18px;
    }
    .license-modal-header h3 { margin: 0; font-size: 1rem; font-weight: 800; color: #2F4156; }
    .license-modal-close { background: none; border: none; color: #567C8D; font-size: 1rem; cursor: pointer; padding: 0.2rem; line-height: 1; }
    .license-modal-close:hover { color: #2F4156; }

    .license-modal-body { padding: 1.15rem 1.3rem 1.4rem; display: flex; flex-direction: column; gap: 0.8rem; }
    .license-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; font-size: 0.85rem; }
    .license-label { color: #8592A6; font-weight: 600; flex-shrink: 0; }
    .license-value { color: #2F4156; font-weight: 700; text-align: right; word-break: break-word; }
    .license-key-value { font-family: 'Courier New', monospace; letter-spacing: 0.02em; font-size: 0.82rem; }

    .license-status-badge {
        display: inline-block; padding: 0.2rem 0.65rem; border-radius: 20px;
        font-size: 0.7rem; font-weight: 800; text-transform: capitalize;
        background: var(--license-gold-bg); color: #9C7D18;
    }
    .license-remaining-normal { color: #9C7D18; }
    .license-remaining-orange, .license-status-badge.license-status-orange { color: var(--license-orange); background: var(--license-orange-bg); }
    .license-remaining-red, .license-status-badge.license-status-red { color: var(--license-red); background: var(--license-red-bg); }
    .license-remaining-darkred, .license-status-badge.license-status-darkred { color: var(--license-darkred); background: var(--license-darkred-bg); }
    .license-remaining-today, .license-status-badge.license-status-today { color: var(--license-red); background: var(--license-red-bg); }
    .license-remaining-expired, .license-status-badge.license-status-expired { color: #fff; background: var(--license-darkred); }

    .license-high-risk-tag {
        align-self: flex-start; background: var(--license-darkred); color: #fff;
        padding: 0.25rem 0.7rem; border-radius: 20px; font-size: 0.68rem;
        font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase;
    }

    /* Expired License Full Lock */
    .license-lock-overlay {
        position: fixed; inset: 0; z-index: 99999;
        background: rgba(139, 20, 20, 0.96);
        display: flex; align-items: center; justify-content: center;
        padding: 1.5rem; font-family: system-ui, -apple-system, sans-serif;
    }
    .license-lock-box {
        background: #FFFFFF; border-radius: 20px; padding: 2.4rem 2rem;
        max-width: 420px; width: 100%; text-align: center;
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.4);
    }
    .license-lock-icon { font-size: 2.4rem; color: var(--license-darkred); margin-bottom: 0.75rem; }
    .license-lock-box h2 { margin: 0 0 0.9rem; font-size: 1.25rem; font-weight: 800; color: var(--license-darkred); }
    .license-lock-branch { font-size: 1rem; font-weight: 700; color: #2F4156; margin-bottom: 0.25rem; }
    .license-lock-key { font-size: 0.8rem; font-family: 'Courier New', monospace; color: #567C8D; margin-bottom: 0.75rem; }
    .license-lock-expired-text { font-size: 0.85rem; font-weight: 800; color: var(--license-darkred); margin-bottom: 1rem; }
    .license-lock-box p { margin: 0; font-size: 0.85rem; color: #567C8D; line-height: 1.5; }

    @media (max-width: 575.98px) {
        .license-modal { max-width: 100%; }
        .license-lock-box { padding: 2rem 1.4rem; }
    }
</style>

<!-- Desktop Sidebar -->
<aside class="app-sidebar">
    <div class="nav-brand">
        <?php if ($hasLogoImage): ?>
            <img src="<?= htmlspecialchars($logoImagePath) ?>" 
                 alt="FineBullion Desk" 
                 class="nav-brand-logo-full" 
                 onerror="this.style.display='none'; document.getElementById('navBrandFallbackText').style.display='block';">
            <div class="nav-brand-text-fallback" id="navBrandFallbackText" style="display: none;">
                FineBullion Desk
            </div>
        <?php else: ?>
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

    <?php if ($hasLicenseRecord): ?>
        <div class="nav-license-widget nav-license-<?= htmlspecialchars($licenseWarningLevel) ?>"
             onclick="openLicenseModal()" role="button" tabindex="0"
             onkeypress="if(event.key==='Enter'){openLicenseModal();}">
            <div class="nav-license-icon"><i class="bi bi-shield-lock-fill"></i></div>
            <div class="nav-license-text">
                <div class="nav-license-branch"><?= htmlspecialchars($branchName) ?></div>
                <div class="nav-license-days"><?= htmlspecialchars($daysLeftText) ?></div>
            </div>
        </div>
    <?php endif; ?>

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
            <i class="bi <?= $menuIconClass ?>"></i><span><?= $menuLabelText ?></span>
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

<!-- Mobile Menu Sheet -->
<div class="bottom-sheet" id="sheetMenu">
    <div class="bottom-sheet-drag-handle"></div>

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

    <?php if ($hasLicenseRecord): ?>
        <div class="nav-license-widget nav-license-<?= htmlspecialchars($licenseWarningLevel) ?>"
             onclick="openLicenseModal()" role="button" tabindex="0"
             onkeypress="if(event.key==='Enter'){openLicenseModal();}">
            <div class="nav-license-icon"><i class="bi bi-shield-lock-fill"></i></div>
            <div class="nav-license-text">
                <div class="nav-license-branch"><?= htmlspecialchars($branchName) ?></div>
                <div class="nav-license-days"><?= htmlspecialchars($daysLeftText) ?></div>
            </div>
        </div>
    <?php endif; ?>

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

<?php if ($hasLicenseRecord): ?>
<!-- License Details Modal -->
<div class="license-modal-backdrop" id="licenseModalBackdrop" onclick="closeLicenseModal(event)">
    <div class="license-modal" onclick="event.stopPropagation()">
        <div class="license-modal-header">
            <h3><i class="bi bi-shield-lock-fill"></i> License Details</h3>
            <?php if (!$isLicenseExpired): ?>
                <button type="button" class="license-modal-close" onclick="closeLicenseModal()"><i class="bi bi-x-lg"></i></button>
            <?php endif; ?>
        </div>
        <div class="license-modal-body">
            <div class="license-row">
                <span class="license-label">Branch Name</span>
                <span class="license-value"><?= htmlspecialchars($branchName) ?></span>
            </div>
            <div class="license-row">
                <span class="license-label">License Key</span>
                <span class="license-value license-key-value"><?= htmlspecialchars($licenseKey) ?></span>
            </div>
            <div class="license-row">
                <span class="license-label">Current Status</span>
                <span class="license-value">
                    <span class="license-status-badge license-status-<?= htmlspecialchars($licenseWarningLevel) ?>">
                        <?= htmlspecialchars(ucfirst($licenseStatusRaw)) ?>
                    </span>
                </span>
            </div>
            <div class="license-row">
                <span class="license-label">Expiry Date</span>
                <span class="license-value"><?= htmlspecialchars(date('d M, Y', strtotime($expireDate))) ?></span>
            </div>
            <div class="license-row">
                <span class="license-label">Last Renewal</span>
                <span class="license-value"><?= $lastRenewDate ? htmlspecialchars(date('d M, Y', strtotime($lastRenewDate))) : '—' ?></span>
            </div>
            <div class="license-row">
                <span class="license-label">Remaining</span>
                <span class="license-value license-remaining-<?= htmlspecialchars($licenseWarningLevel) ?>"><?= htmlspecialchars($daysLeftText) ?></span>
            </div>
            <?php if ($licenseWarningLevel === 'darkred'): ?>
                <div class="license-high-risk-tag">High Risk</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isLicenseExpired): ?>
<!-- Expired License Full Lock -->
<div class="license-lock-overlay" id="licenseLockOverlay">
    <div class="license-lock-box">
        <div class="license-lock-icon"><i class="bi bi-lock-fill"></i></div>
        <h2>License Expired</h2>
        <div class="license-lock-branch"><?= htmlspecialchars($branchName) ?></div>
        <div class="license-lock-key">Key: <?= htmlspecialchars($licenseKey) ?></div>
        <div class="license-lock-expired-text"><?= htmlspecialchars($daysLeftText) ?></div>
        <p>Please renew your license to continue using FineBullion Desk.</p>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

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

    var licenseIsExpired = <?= $isLicenseExpired ? 'true' : 'false' ?>;

    function openLicenseModal() {
        const backdrop = document.getElementById('licenseModalBackdrop');
        if (backdrop) backdrop.classList.add('open');
    }

    function closeLicenseModal(event) {
        if (licenseIsExpired) return;
        const backdrop = document.getElementById('licenseModalBackdrop');
        if (backdrop) backdrop.classList.remove('open');
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (licenseIsExpired) {
            openLicenseModal();
            document.body.style.overflow = 'hidden';
        }
    });
</script>