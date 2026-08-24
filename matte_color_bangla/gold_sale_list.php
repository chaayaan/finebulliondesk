<?php
/**
 * gold_sale_list.php
 * FineBullion Desk — Gold Sale history / listing
 *
 * Mobile : stat bar (Weight / Total Amount / Total Paid / Total Due)
 *          + compact table rows with stacked Weight & Amount info cell.
 * Desktop: same stat bar + full table with individual columns.
 *
 * Payment amounts are now sourced from gold_sale_payments table.
 */

require_once __DIR__ . '/auth.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$action = $_GET['action'] ?? $_POST['action'] ?? null;

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------
function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function gramsToTraditionalPHP($grams): string
{
    $grams = (float)$grams;
    $EPS = 1e-9;
    $totalVori = $grams / 11.664;
    $vori = (int)floor($totalVori + $EPS);
    $fracVori = max(0, $totalVori - $vori);
    $totalAna = $fracVori * 16;
    $ana = (int)floor($totalAna + $EPS);
    $fracAna = max(0, $totalAna - $ana);
    $totalRoti = $fracAna * 6;
    $roti = (int)floor($totalRoti + $EPS);
    $fracRoti = max(0, $totalRoti - $roti);
    $point = (int)round($fracRoti * 10);
    if ($point >= 10) { $point -= 10; $roti++; }
    if ($roti >= 6)   { $roti -= 6;   $ana++;  }
    if ($ana >= 16)   { $ana -= 16;   $vori++; }
    return "{$vori} ভ {$ana} আ {$roti} র {$point} প";
}

function fmtBDTPHP($n): string
{
    return '৳' . number_format((float)round((float)$n));
}

function fmtDatePHP($s): string
{
    if (!$s) return '—';
    $t = strtotime($s);
    return $t ? date('d M Y', $t) : '—';
}

// -----------------------------------------------------------------------
// Server-side View Data Fetching (When view_id is passed)
// -----------------------------------------------------------------------
$viewId = isset($_GET['view_id']) ? (int)$_GET['view_id'] : 0;
$viewSale = null;
$viewItems = [];
$viewPayments = [];

if ($viewId > 0 && !$isAjax && $action === null) {
    $stmt = mysqli_prepare($conn,
        "SELECT gs.id, gs.customer_id, c.name AS customer_name, c.phone AS customer_phone,
                gs.pure_gold_price, gs.total_amount,
                COALESCE(gsp.paid_sum, 0)                             AS paid_amount,
                (gs.total_amount - COALESCE(gsp.paid_sum, 0))         AS due_amount,
                gs.note, gs.created_at, gs.updated_at, u.username AS created_by_username
         FROM gold_sales gs
         JOIN customers c ON c.id = gs.customer_id
         LEFT JOIN users u ON u.id = gs.created_by
         LEFT JOIN (
             SELECT gold_sale_id, SUM(paid_amount) AS paid_sum
             FROM gold_sale_payments
             GROUP BY gold_sale_id
         ) gsp ON gsp.gold_sale_id = gs.id
         WHERE gs.id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $viewId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $viewSale = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if ($viewSale) {
        // Items
        $itemStmt = mysqli_prepare($conn,
            "SELECT id, weight, purity, price
             FROM gold_sale_items WHERE gold_sale_id = ? ORDER BY id ASC");
        mysqli_stmt_bind_param($itemStmt, 'i', $viewId);
        mysqli_stmt_execute($itemStmt);
        $itemRes = mysqli_stmt_get_result($itemStmt);
        while ($row = mysqli_fetch_assoc($itemRes)) {
            $viewItems[] = $row;
        }
        mysqli_stmt_close($itemStmt);

        // Payments
        $pmtStmt = mysqli_prepare($conn,
            "SELECT p.id, p.paid_amount, p.transaction_ref, p.payment_date, p.note,
                    p.created_at, u.username AS received_by_username
             FROM gold_sale_payments p
             LEFT JOIN users u ON u.id = p.received_by
             WHERE p.gold_sale_id = ?
             ORDER BY p.payment_date ASC, p.created_at ASC");
        mysqli_stmt_bind_param($pmtStmt, 'i', $viewId);
        mysqli_stmt_execute($pmtStmt);
        $pmtRes = mysqli_stmt_get_result($pmtStmt);
        while ($row = mysqli_fetch_assoc($pmtRes)) {
            $viewPayments[] = $row;
        }
        mysqli_stmt_close($pmtStmt);
    }
}

// -----------------------------------------------------------------------
// AJAX actions
// -----------------------------------------------------------------------
if ($isAjax || $action !== null) {

    // ---- LIST ------------------------------------------------------------
    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $search   = trim($_GET['search']    ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo   = trim($_GET['date_to']   ?? '');
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $perPage  = 50;
        $offset   = ($page - 1) * $perPage;

        $conditions = [];
        $params     = [];
        $types      = '';

        if ($search !== '') {
            $conditions[] = "(c.name LIKE ? OR c.phone LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like;
            $types .= 'ss';
        }
        if ($dateFrom !== '') {
            $conditions[] = "DATE(gs.created_at) >= ?";
            $params[] = $dateFrom; $types .= 's';
        }
        if ($dateTo !== '') {
            $conditions[] = "DATE(gs.created_at) <= ?";
            $params[] = $dateTo; $types .= 's';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Totals (for stat bar) — paid from gold_sale_payments
        $totSql = "SELECT
                       COALESCE(SUM(gsi.weight),         0) AS total_weight_g,
                       COALESCE(SUM(gs.total_amount),    0) AS total_amount,
                       COALESCE(SUM(gsp.paid_sum),       0) AS total_paid,
                       COALESCE(SUM(gs.total_amount) - SUM(COALESCE(gsp.paid_sum, 0)), 0) AS total_due
                   FROM gold_sales gs
                   JOIN customers c ON c.id = gs.customer_id
                   LEFT JOIN (
                       SELECT gold_sale_id, SUM(weight) AS weight
                       FROM gold_sale_items GROUP BY gold_sale_id
                   ) gsi ON gsi.gold_sale_id = gs.id
                   LEFT JOIN (
                       SELECT gold_sale_id, SUM(paid_amount) AS paid_sum
                       FROM gold_sale_payments GROUP BY gold_sale_id
                   ) gsp ON gsp.gold_sale_id = gs.id
                   $where";
        $totStmt = mysqli_prepare($conn, $totSql);
        if ($params) mysqli_stmt_bind_param($totStmt, $types, ...$params);
        mysqli_stmt_execute($totStmt);
        $totRow = mysqli_fetch_assoc(mysqli_stmt_get_result($totStmt));

        // Karat-wise weight breakdown (for the stat bar)
        $karatSql = "SELECT gsi.purity, COALESCE(SUM(gsi.weight), 0) AS weight_g
                     FROM gold_sale_items gsi
                     JOIN gold_sales gs ON gs.id = gsi.gold_sale_id
                     JOIN customers c ON c.id = gs.customer_id
                     $where
                     GROUP BY gsi.purity
                     ORDER BY gsi.purity ASC";
        $karatStmt = mysqli_prepare($conn, $karatSql);
        if ($params) mysqli_stmt_bind_param($karatStmt, $types, ...$params);
        mysqli_stmt_execute($karatStmt);
        $karatRes = mysqli_stmt_get_result($karatStmt);
        $byKarat = [];
        while ($row = mysqli_fetch_assoc($karatRes)) {
            $key = number_format((float)$row['purity'], 2, '.', '');
            $byKarat[$key] = (float)$row['weight_g'];
        }

        $byKaratOut = [];
        foreach ([18.00, 20.00, 21.00, 22.00, 24.00] as $k) {
            $key = number_format($k, 2, '.', '');
            $byKaratOut[] = [
                'purity'   => $k,
                'weight_g' => $byKarat[$key] ?? 0.0,
            ];
        }
        $totRow['by_karat'] = $byKaratOut;

        // Count
        $cntSql  = "SELECT COUNT(*) FROM gold_sales gs
                    JOIN customers c ON c.id = gs.customer_id
                    $where";
        $cntStmt = mysqli_prepare($conn, $cntSql);
        if ($params) mysqli_stmt_bind_param($cntStmt, $types, ...$params);
        mysqli_stmt_execute($cntStmt);
        mysqli_stmt_bind_result($cntStmt, $total);
        mysqli_stmt_fetch($cntStmt);
        mysqli_stmt_close($cntStmt);

        // Rows — paid_amount from payments table
        $sql = "SELECT gs.id, gs.customer_id, c.name AS customer_name, c.phone AS customer_phone,
                       gs.pure_gold_price, gs.total_amount,
                       COALESCE(gsp.paid_sum, 0)                             AS paid_amount,
                       (gs.total_amount - COALESCE(gsp.paid_sum, 0))         AS due_amount,
                       COALESCE(gsi.weight, 0)     AS total_weight_g,
                       COALESCE(gsi.item_count, 0) AS item_count,
                       gs.note, gs.created_at, u.username AS created_by_username
                FROM gold_sales gs
                JOIN customers c ON c.id = gs.customer_id
                LEFT JOIN users u ON u.id = gs.created_by
                LEFT JOIN (
                    SELECT gold_sale_id,
                           SUM(weight) AS weight,
                           COUNT(*)    AS item_count
                    FROM gold_sale_items
                    GROUP BY gold_sale_id
                ) gsi ON gsi.gold_sale_id = gs.id
                LEFT JOIN (
                    SELECT gold_sale_id, SUM(paid_amount) AS paid_sum
                    FROM gold_sale_payments
                    GROUP BY gold_sale_id
                ) gsp ON gsp.gold_sale_id = gs.id
                $where
                ORDER BY gs.created_at DESC
                LIMIT ? OFFSET ?";

        $stmt = mysqli_prepare($conn, $sql);
        if ($params) {
            $allParams = array_merge($params, [$perPage, $offset]);
            mysqli_stmt_bind_param($stmt, $types . 'ii', ...$allParams);
        } else {
            mysqli_stmt_bind_param($stmt, 'ii', $perPage, $offset);
        }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;

        json_out([
            'success'    => true,
            'data'       => $rows,
            'totals'     => $totRow,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalRows'  => (int)$total,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
        ]);
    }

    // ---- GET single (with items + payment history) -----------------------
    if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'অকার্যকর আইডি।'], 400);

        $stmt = mysqli_prepare($conn,
            "SELECT gs.id, gs.customer_id, c.name AS customer_name, c.phone AS customer_phone,
                    gs.pure_gold_price, gs.total_amount,
                    COALESCE(gsp.paid_sum, 0)                             AS paid_amount,
                    (gs.total_amount - COALESCE(gsp.paid_sum, 0))         AS due_amount,
                    gs.note, gs.created_at, gs.updated_at, u.username AS created_by_username
             FROM gold_sales gs
             JOIN customers c ON c.id = gs.customer_id
             LEFT JOIN users u ON u.id = gs.created_by
             LEFT JOIN (
                 SELECT gold_sale_id, SUM(paid_amount) AS paid_sum
                 FROM gold_sale_payments
                 GROUP BY gold_sale_id
             ) gsp ON gsp.gold_sale_id = gs.id
             WHERE gs.id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $saleRes = mysqli_stmt_get_result($stmt);
        $sale = mysqli_fetch_assoc($saleRes);
        if (!$sale) json_out(['success' => false, 'message' => 'কোনো তথ্য পাওয়া যায়নি।'], 404);

        $itemStmt = mysqli_prepare($conn,
            "SELECT id, weight, purity, price
             FROM gold_sale_items WHERE gold_sale_id = ? ORDER BY id ASC");
        mysqli_stmt_bind_param($itemStmt, 'i', $id);
        mysqli_stmt_execute($itemStmt);
        $itemRes = mysqli_stmt_get_result($itemStmt);
        $items = [];
        while ($row = mysqli_fetch_assoc($itemRes)) $items[] = $row;

        // Payment history
        $pmtStmt = mysqli_prepare($conn,
            "SELECT p.id, p.paid_amount, p.transaction_ref, p.payment_date, p.note,
                    p.created_at, u.username AS received_by_username
             FROM gold_sale_payments p
             LEFT JOIN users u ON u.id = p.received_by
             WHERE p.gold_sale_id = ?
             ORDER BY p.payment_date ASC, p.created_at ASC");
        mysqli_stmt_bind_param($pmtStmt, 'i', $id);
        mysqli_stmt_execute($pmtStmt);
        $pmtRes = mysqli_stmt_get_result($pmtStmt);
        $payments = [];
        while ($row = mysqli_fetch_assoc($pmtRes)) $payments[] = $row;

        $sale['items']    = $items;
        $sale['payments'] = $payments;
        json_out(['success' => true, 'data' => $sale]);
    }

    json_out(['success' => false, 'message' => 'অজানা অ্যাকশন।'], 400);
}
?>
<!DOCTYPE html>
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>সোনা বিক্রির তালিকা — ফাইনবুলিয়ন ডায়াল</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --navy: #2F4156;
    --teal: #567C8D;
    --sky: #C8D9E6;
    --beige: #F5EFEB;
    --white: #FFFFFF;

    --text-primary: #2F4156;
    --text-secondary: #567C8D;
    --text-on-navy: #FFFFFF;
    --border-default: #C8D9E6;
    --border-strong: #567C8D;
    --bg-app: #F5EFEB;
    --bg-card: #FFFFFF;
    --bg-hover: #EAF1F6;
    --success: #3D7A5C;
    --danger: #A6434B;
    --shadow: 0 2px 8px rgba(47,65,86,0.08);

    /* Legacy variable mappings for system stability */
    --gold-deep: var(--navy);
    --gold-mid: var(--navy);
    --gold-light: var(--sky);
    --ivory: var(--bg-app);
    --bronze-text: var(--text-primary);
    --muted: var(--text-secondary);
    --hairline: var(--border-default);

    --status-paid-bg: var(--success);
    --status-paid-light: #EAF3EE;
    --status-due-bg: var(--danger);
    --status-due-light: #FBECEC;
    --status-total-bg: var(--navy);
    --status-total-light: var(--sky);

    --sc-bg: var(--bg-app);
    --sc-card: var(--bg-card);
    --sc-gold: var(--teal);
    --sc-gold-dark: var(--navy);
    --sc-gold-light: var(--sky);
    --sc-text: var(--text-primary);
    --sc-text-2: var(--text-secondary);
    --sc-border: var(--border-default);
    --sc-due-bg: #FBECEC;
    --sc-due-text: var(--danger);
    --sc-success: var(--success);
}
body {
    background: var(--bg-app);
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--text-primary);
}

/* ---- page header ---- */
.fb-header {
    background: var(--navy) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: nowrap !important;
    gap: 1rem !important;
    min-height: 60px !important;
    max-height: 80px !important;
    padding: 0.85rem 1.75rem !important;
    margin: 0 !important;
    width: 100% !important;
    border-radius: 0 0 20px 20px !important;
}
.fb-header h4, .fb-header h4 i { color: var(--text-on-navy) !important; }
.fb-header small { color: rgba(255,255,255,0.78) !important; }
.fb-header .header-title-block { min-width: 0; }
.fb-header .header-actions { display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; }

.page-inset { padding: 0 1.5rem; }

/* ---- action button in header ---- */
.fb-header .btn-fb-primary {
    background: var(--navy);
    border: 1.5px solid #ffffff;
    color: #ffffff !important;
    font-weight: 700;
    border-radius: 8px;
}
.fb-header .btn-fb-primary:hover, .fb-header .btn-fb-primary:focus {
    opacity: 1;
    background: var(--teal);
    border-color: #ffffff;
    color: #ffffff !important;
}

/* ---- normalized 8px buttons ---- */
.btn-fb-primary, .btn-gold {
    background: var(--navy);
    border: 1.5px solid var(--navy);
    color: #ffffff !important;
    font-weight: 700;
    border-radius: 8px;
}
.btn-fb-primary:hover, .btn-gold:hover { opacity: 0.92; background: var(--teal); border-color: var(--teal); color: #ffffff !important; }

.btn-fb-secondary, .btn-secondary {
    background: #ffffff;
    border: 1.5px solid var(--border-default);
    color: var(--navy);
    font-weight: 600;
    border-radius: 8px;
}
.btn-fb-secondary:hover, .btn-secondary:hover { background: var(--bg-hover); border-color: var(--teal); color: var(--navy); }

.btn-outline-secondary {
    background: transparent;
    border: 1.5px solid var(--teal);
    color: var(--teal);
    font-weight: 600;
    border-radius: 8px;
}
.btn-outline-secondary:hover { background: var(--sky); border-color: var(--navy); color: var(--navy); }

.btn-outline-danger {
    background: #ffffff;
    border: 1.5px solid var(--danger);
    color: var(--danger);
    font-weight: 600;
    border-radius: 8px;
}
.btn-outline-danger:hover { background: var(--danger); border-color: var(--danger); color: #ffffff; }

.btn-outline-primary {
    background: transparent;
    border: 1.5px solid var(--teal);
    color: var(--teal);
    font-weight: 600;
    border-radius: 8px;
}
.btn-outline-primary:hover { background: var(--sky); border-color: var(--navy); color: var(--navy); }

/* ---- summary card ---- */
.section-block { margin-bottom: 1.25rem; }
.sc-card {
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 14px;
    box-shadow: var(--shadow);
    overflow: hidden;
}
.sc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 10px 14px;
    border-bottom: 1px solid var(--border-default);
}
.sc-header-left { display: flex; align-items: center; gap: 8px; }
.sc-icon {
    width: 26px; height: 26px; min-width: 26px;
    border-radius: 50%;
    background: var(--sky);
    border: 1px solid var(--border-default);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem;
    color: var(--navy);
}
.section-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--navy);
    margin: 0;
}
.sc-header-icon { color: var(--teal); font-size: 0.85rem; opacity: 0.8; }

.stat-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
}
.stat-cell {
    padding: 10px 12px;
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 8px;
    border-right: 1px solid var(--border-default);
    background: var(--bg-card);
}
.stat-cell:last-child { border-right: none; }
.stat-cell .s-icon {
    width: 26px; height: 26px; min-width: 26px;
    border-radius: 50%;
    background: var(--sky);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.72rem;
    color: var(--navy);
}
.stat-cell .s-text { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.stat-cell .s-label {
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.stat-cell .s-value {
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.01em;
    color: var(--text-primary);
    white-space: nowrap;
}
.stat-cell.stat-emphasis { background: var(--sky); }
.stat-cell.stat-emphasis .s-value { color: var(--navy); }
.stat-cell.stat-emphasis .s-icon { background: #ffffff; color: var(--navy); }
.stat-cell.stat-due { background: #FBECEC; }
.stat-cell.stat-due .s-value { color: var(--danger); }
.stat-cell.stat-due .s-icon { background: #ffffff; color: var(--danger); }

/* ---- karat-wise weight breakdown row ---- */
.karat-bar {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    border-top: 1px solid var(--border-default);
}
.karat-cell {
    padding: 8px 10px;
    text-align: center;
    border-right: 1px solid var(--border-default);
    background: var(--bg-app);
}
.karat-cell:last-child { border-right: none; }
.karat-cell .k-label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.03em;
    color: var(--text-secondary);
    margin-bottom: 2px;
}
.karat-cell .k-value {
    display: block;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
}

/* ---- amount badges ---- */
.badge-amount { background: var(--sky); color: var(--navy); font-weight: 600; font-size: 0.82rem; }
.badge-paid   { background: #EAF3EE; color: var(--success); font-weight: 600; font-size: 0.82rem; }
.badge-due    { background: #FBECEC; color: var(--danger); font-weight: 600; font-size: 0.82rem; }
.badge-due.zero { background: #EAF3EE; color: var(--success); }
.badge-weight { background: var(--bg-app); color: var(--text-primary); font-weight: 600; font-size: 0.82rem;
                border: 1px solid var(--border-default); }

/* ---- action buttons ---- */
.btn-actions { display: flex; gap: 4px; justify-content: center; }

/* ---- mobile info cell ---- */
.sale-info-cell { min-width: 160px; }
.sale-info-cell .info-row {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
    font-size: 0.72rem;
    line-height: 1.45;
    white-space: nowrap;
}
.sale-info-cell .info-label { color: var(--text-secondary); }
.sale-info-cell .info-value { font-weight: 600; color: var(--text-primary); }
.sale-info-cell .info-value.red   { color: var(--danger); }
.sale-info-cell .info-value.green { color: var(--success); }

/* ---- modal ledger ---- */
.ledger-table td { padding: 0.55rem 0.85rem; vertical-align: middle;
                    border-bottom: 1px solid var(--border-default); --bs-table-bg: transparent; }
.ledger-table tr:last-child td { border-bottom: none; }
.ledger-label { font-size: 0.82rem; color: var(--text-secondary); white-space: nowrap; width: 1%; }
.ledger-vorp  { font-weight: 700; font-size: 0.95rem; text-align: right; }
.ledger-total td { background-color: var(--sky) !important; }
.ledger-total .ledger-label { color: var(--navy); font-weight: 600; }
.ledger-total .ledger-vorp  { color: var(--navy); }
.ledger-paid td  { background-color: #EAF3EE !important; }
.ledger-paid .ledger-label  { color: var(--success); font-weight: 600; }
.ledger-paid .ledger-vorp   { color: var(--success); }
.ledger-due td   { background-color: var(--danger) !important; border-bottom: none; }
.ledger-due .ledger-label   { color: rgba(255,255,255,0.85); font-weight: 600; }
.ledger-due .ledger-vorp    { color: #fff; font-size: 1.05rem; }

/* ---- filter bar ---- */
.filter-bar { background: #ffffff; border-bottom: 1px solid var(--border-default); padding: 0.65rem 1rem;
              display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.filter-bar label { font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0; }
.filter-bar input[type=date] { font-size: 0.82rem; padding: 0.3rem 0.5rem;
                                border: 1.5px solid var(--border-default); border-radius: 10px; color: var(--text-primary); }

/* ---- cards ---- */
.card { background: var(--bg-card); border: 1px solid var(--border-default); border-radius: 18px; box-shadow: var(--shadow); }
.card-header { background: var(--beige) !important; border-bottom: 1px solid var(--border-default); border-radius: 18px 18px 0 0 !important; color: var(--text-primary); }
.card-footer { background: #ffffff !important; border-top: 1px solid var(--border-default); border-radius: 0 0 18px 18px !important; }

/* ---- inputs ---- */
.form-control, .form-select, .input-group-text {
    border: 1.5px solid var(--border-default);
    border-radius: 10px;
    color: var(--text-primary);
    background: #ffffff;
}
.form-control:focus, .form-select:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(86,124,141,0.15); }

/* ---- table ---- */
.table thead.table-light th, .table thead th {
    background: var(--beige) !important;
    color: var(--text-secondary);
    text-transform: uppercase;
    font-size: 0.72rem;
    letter-spacing: 0.04em;
    border-color: var(--border-default) !important;
    font-weight: 700;
}
.table td { border-color: var(--border-default) !important; vertical-align: middle; color: var(--text-primary); }
.table tbody tr:hover { background-color: var(--bg-hover); }

/* ---- modal ---- */
.modal-content { border-radius: 18px; overflow: hidden; border: none; }
.modal-header { background: var(--navy) !important; color: #fff !important; }
.modal-header .modal-title { color: #ffffff; font-weight: 800; }
.modal-header .btn-close, .modal-header .btn-close-white { filter: brightness(0) invert(1); }

/* ---- badges (bootstrap default overrides) ---- */
.badge.bg-light { background: var(--beige) !important; color: var(--text-secondary) !important; border-color: var(--border-default) !important; }

/* ---- responsive breakpoints ---- */
@media (min-width: 768px) and (max-width: 991.98px) {
    .stat-bar { grid-template-columns: repeat(2, 1fr); }
    .stat-cell {
        border-right: 1px solid var(--border-default);
        border-bottom: 1px solid var(--border-default);
    }
    .stat-bar .stat-cell:nth-child(2n) { border-right: none; }
    .stat-bar .stat-cell:nth-last-child(-n+2) { border-bottom: none; }
}

@media (max-width: 767.98px) {
    .page-inset { padding: 0 0.8rem 1rem; }

    .fb-header {
        min-height: 60px !important;
        max-height: 70px !important;
        padding: 0.75rem 1rem !important;
        border-radius: 0 0 16px 16px !important;
        justify-content: space-between !important;
    }
    .fb-header h4 { font-size: 1rem; margin-bottom: 0; }
    .fb-header small { display: none; }

    .stat-bar { grid-template-columns: repeat(2, 1fr); }
    .section-block { margin-bottom: 0.75rem; }
    .sc-card { border-radius: 12px; }
    .sc-header { padding: 8px 10px; }
    .sc-icon { width: 22px; height: 22px; font-size: 0.65rem; }
    .section-label { font-size: 10px; letter-spacing: 0.03em; }
    .sc-header-icon { font-size: 0.75rem; }
    .stat-cell {
        padding: 8px 9px;
        gap: 6px;
        border-right: 1px solid var(--border-default);
        border-bottom: 1px solid var(--border-default);
    }
    .stat-bar .stat-cell:nth-child(2n) { border-right: none; }
    .stat-bar .stat-cell:nth-last-child(-n+2) { border-bottom: none; }
    .stat-cell .s-icon { width: 22px; height: 22px; min-width: 22px; font-size: 0.62rem; }
    .stat-cell .s-label { font-size: 8px; }
    .stat-cell .s-value { font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .karat-bar { grid-template-columns: repeat(3, 1fr); }
    .karat-bar .karat-cell:nth-child(3n) { border-right: none; }
    .karat-bar .karat-cell:nth-last-child(-n+2):nth-child(3n+1),
    .karat-bar .karat-cell:nth-last-child(-n+2):nth-child(3n+2) { border-bottom: none; }
    .karat-cell { padding: 7px 6px; }
    .karat-cell .k-label { font-size: 9px; }
    .karat-cell .k-value { font-size: 11px; }

    .card { border-radius: 14px; }
    .card-header { padding: 0.5rem 0.6rem; }
    .card-header .fw-semibold { font-size: 0.82rem; }
    .card-header .input-group { max-width: 100% !important; width: 100%; }
    .filter-bar { padding: 0.5rem 0.6rem; gap: 0.35rem; }
    .filter-bar input[type=date] { font-size: 0.74rem; padding: 0.22rem 0.35rem; }

    table.table { font-size: 0.78rem; }
    table.table th, table.table td { padding: 0.5rem 0.4rem; }

    .btn-actions { flex-direction: column; gap: 4px; }
    .btn-actions .btn { padding: 0.2rem 0.4rem; font-size: 0.75rem; }

    .card-footer { padding: 0.5rem 0.6rem; }
    .card-footer small { font-size: 0.7rem; }
    .pagination-sm .page-link { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0">
    <!-- Header -->
    <div class="fb-header">
        <div class="header-title-block">
            <h4 class="mb-0">
                <i class="bi bi-bag-check-fill me-2"></i>
                <span class="d-none d-md-inline">সোনা বিক্রির ইতিহাস</span>
                <span class="d-md-none">সোনা বিক্রির তালিকা</span>
            </h4>
            <small>ফাইনবুলিয়ন ডায়াল</small>
        </div>
        <div class="header-actions">
            <a href="gold_sale_inventory.php" class="btn btn-fb-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> <span>নতুন বিক্রি</span>
            </a>
        </div>
    </div>
</div>

<div class="page-inset py-4">

    <!-- Summary card -->
    <div class="section-block">
        <div class="sc-card">
            <div class="sc-header">
                <div class="sc-header-left">
                    <div class="sc-icon"><i class="bi bi-tag"></i></div>
                    <p class="section-label">সোনা বিক্রি</p>
                </div>
                <i class="bi bi-graph-up-arrow sc-header-icon"></i>
            </div>
            <div class="stat-bar" id="statBar">
                <div class="stat-cell">
                    <div class="s-icon"><i class="bi bi-speedometer"></i></div>
                    <div class="s-text">
                        <span class="s-label">ওজন</span>
                        <span class="s-value" id="statWeight">—</span>
                    </div>
                </div>
                <div class="stat-cell stat-emphasis">
                    <div class="s-icon"><i class="bi bi-cash-stack"></i></div>
                    <div class="s-text">
                        <span class="s-label">মোট পরিমাণ</span>
                        <span class="s-value" id="statTotal">—</span>
                    </div>
                </div>
                <div class="stat-cell">
                    <div class="s-icon"><i class="bi bi-wallet2"></i></div>
                    <div class="s-text">
                        <span class="s-label">মোট পরিশোধিত</span>
                        <span class="s-value" id="statPaid">—</span>
                    </div>
                </div>
                <div class="stat-cell stat-due">
                    <div class="s-icon"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="s-text">
                        <span class="s-label">মোট বকেয়া</span>
                        <span class="s-value" id="statDue">—</span>
                    </div>
                </div>
            </div>
            <div class="karat-bar" id="karatBar"></div>
        </div>
    </div>

    <!-- Table card -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-list-ul me-1"></i> বিক্রয়ের রেকর্ডসমূহ</span>
            <div class="input-group" style="max-width:300px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control" placeholder="কাস্টমারের নাম, ফোন নম্বর খুঁজুন…">
                <button class="btn btn-outline-secondary" id="clearSearchBtn"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>

        <!-- Date filter bar -->
        <div class="filter-bar">
            <span class="d-none d-md-inline" style="font-size:0.8rem;color:var(--text-secondary);">ক্যাটাগরি</span>
            <span class="d-none d-md-inline badge bg-light text-dark border" style="font-size:0.75rem;">সব</span>
            <span class="ms-auto d-none d-md-inline" style="font-size:0.8rem;color:var(--text-secondary);">শুরু</span>
            <label class="d-md-none" style="font-size:0.72rem;color:var(--text-secondary);">শুরু</label>
            <input type="date" id="dateFrom">
            <span style="font-size:0.8rem;color:var(--text-secondary);">শেষ</span>
            <input type="date" id="dateTo">
            <button class="btn btn-sm btn-outline-secondary ms-1" id="clearDatesBtn" title="তারিখ রিসেট করুন">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:60px;">#</th>
                            <th>কাস্টমার</th>
                            <!-- Desktop columns -->
                            <th class="d-none d-md-table-cell">ওজন</th>
                            <th class="d-none d-md-table-cell">মোট পরিমাণ</th>
                            <th class="d-none d-md-table-cell">পরিশোধিত টাকা</th>
                            <th class="d-none d-md-table-cell">বকেয়া টাকা</th>
                            <!-- Mobile combined column -->
                            <th class="d-md-none">ওজন ও পরিমাণ</th>
                            <th class="d-none d-md-table-cell" style="width:130px;">তারিখ</th>
                            <th class="d-none d-md-table-cell" style="width:110px;">গ্রহীতা</th>
                            <th style="width:90px;" class="text-center">অ্যাকশন</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <tr><td colspan="9" class="text-center text-muted py-4">লোড হচ্ছে…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted" id="paginationInfo">—</small>
            <nav><ul class="pagination pagination-sm mb-0" id="paginationControls"></ul></nav>
        </div>
    </div>

</div>
</div>

<!-- ================================================================
     VIEW MODAL (Server-Rendered PHP)
================================================================ -->
<?php if ($viewSale): ?>
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-receipt me-2"></i>বিক্রয় #<?= (int)$viewSale['id'] ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewBody">
                <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
                    <div>
                        <div class="fw-semibold fs-6"><?= htmlspecialchars($viewSale['customer_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        <small class="text-muted"><?= htmlspecialchars($viewSale['customer_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                    </div>
                    <div class="text-end">
                        <small class="text-muted d-block"><?= fmtDatePHP($viewSale['created_at']) ?></small>
                        <small class="text-muted">গ্রহীতা: <?= htmlspecialchars($viewSale['created_by_username'] ?? '—', ENT_QUOTES, 'UTF-8') ?></small>
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:32px;">#</th>
                                <th>ওজন (ভ-আ-র-প)</th>
                                <th style="width:70px;">ক্যারেট</th>
                                <th class="text-end"> দাম</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($viewItems)): ?>
                                <tr><td colspan="4" class="text-center text-muted">কোনো আইটেম পাওয়া যায়নি।</td></tr>
                            <?php else: ?>
                                <?php foreach ($viewItems as $idx => $it): ?>
                                    <tr>
                                        <td class="text-muted"><?= $idx + 1 ?></td>
                                        <td><?= gramsToTraditionalPHP($it['weight']) ?></td>
                                        <td><?= number_format((float)$it['purity'], 2) ?> K</td>
                                        <td class="text-end"><?= fmtBDTPHP($it['price']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mb-3">
                    <div class="fw-semibold small text-muted mb-1">পেমেন্ট ইতিহাস</div>
                    <?php if (empty($viewPayments)): ?>
                        <p class="text-muted small mb-0">এখনো কোনো পেমেন্ট করা হয়নি।</p>
                    <?php else: ?>
                        <?php foreach ($viewPayments as $p): ?>
                            <div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="font-size:.82rem;">
                                <span class="text-muted">
                                    <?= fmtDatePHP($p['payment_date']) ?>
                                </span>
                                <span class="fw-semibold text-success"><?= fmtBDTPHP($p['paid_amount']) ?></span>
                                <span class="text-muted small"><?= htmlspecialchars($p['received_by_username'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if (!empty($p['transaction_ref'])): ?>
                                    <span class="badge bg-light text-dark border">#<?= htmlspecialchars($p['transaction_ref'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <span></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php $due = (float)($viewSale['due_amount'] ?? 0); ?>
                <table class="table table-sm mb-3 ledger-table">
                    <tbody>
                        <tr class="ledger-total">
                            <td class="ledger-label">২৪ ক্যারেট পাকা সোনার দাম (প্রতি ভরি)</td>
                            <td class="ledger-vorp"><?= fmtBDTPHP($viewSale['pure_gold_price']) ?></td>
                        </tr>
                        <tr class="ledger-total">
                            <td class="ledger-label">মোট</td>
                            <td class="ledger-vorp"><?= fmtBDTPHP($viewSale['total_amount']) ?></td>
                        </tr>
                        <tr class="ledger-paid">
                            <td class="ledger-label">পরিশোধিত</td>
                            <td class="ledger-vorp"><?= fmtBDTPHP($viewSale['paid_amount']) ?></td>
                        </tr>
                        <tr class="ledger-due">
                            <td class="ledger-label">বকেয়া</td>
                            <td class="ledger-vorp"><?= fmtBDTPHP($due) ?></td>
                        </tr>
                    </tbody>
                </table>
                <?php if (!empty($viewSale['note'])): ?>
                    <div class="alert alert-light border mb-0 py-2"><strong>নোট:</strong> <?= htmlspecialchars($viewSale['note'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-fb-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-1"></i>বন্ধ করুন
                </button>
                <a id="btnOpenEdit" href="gold_sale_edit_inventory.php?id=<?= (int)$viewSale['id'] ?>" class="btn btn-fb-primary btn-sm">
                    <i class="bi bi-pencil-square me-1"></i>সম্পূর্ণ বিস্তারিত / এডিট
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Unit conversion ──
const G_PER_VORI  = 11.664;
const G_PER_ANA   = 0.729;
const G_PER_ROTI  = 0.1215;
const G_PER_POINT = 0.01215;

function gramsToTraditional(grams) {
    const EPS = 1e-9;
    const totalVori = grams / G_PER_VORI;
    let vori = Math.floor(totalVori + EPS);
    let fracVori = Math.max(0, totalVori - vori);
    let totalAna = fracVori * 16;
    let ana = Math.floor(totalAna + EPS);
    let fracAna = Math.max(0, totalAna - ana);
    let totalRoti = fracAna * 6;
    let roti = Math.floor(totalRoti + EPS);
    let fracRoti = Math.max(0, totalRoti - roti);
    let point = Math.round(fracRoti * 10);
    if (point >= 10) { point -= 10; roti++; }
    if (roti >= 6)   { roti -= 6;   ana++;  }
    if (ana >= 16)   { ana -= 16;   vori++; }
    return `${vori} ভ ${ana} আ ${roti} র ${point} প`;
}

function fmtTrad(grams) {
    const t = gramsToTraditional(parseFloat(grams) || 0);
    return t;
}

function fmtBDT(n) {
    return '৳' + Math.round(parseFloat(n) || 0).toLocaleString('bn-BD');
}

function renderKaratBar(byKarat) {
    const el = document.getElementById('karatBar');
    if (!el) return;
    if (!byKarat || byKarat.length === 0) { el.innerHTML = ''; return; }

    el.innerHTML = byKarat.map(k => {
        const purity = parseFloat(k.purity) || 0;
        const label  = (Number.isInteger(purity) ? purity : purity.toFixed(2)) + 'K';
        return `
            <div class="karat-cell">
                <span class="k-label">${label}</span>
                <span class="k-value">${fmtTrad(k.weight_g || 0)}</span>
            </div>`;
    }).join('');
}

function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(s.replace(' ', 'T'));
    return d.toLocaleDateString('bn-BD', { day: '2-digit', month: 'short', year: 'numeric' });
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── State ──
let currentPage   = 1;
let currentSearch = '';
let currentFrom   = '';
let currentTo     = '';
let searchTimer   = null;

// ── Load list ──
async function loadList(page = 1) {
    currentPage = page;
    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">লোড হচ্ছে…</td></tr>';

    try {
        const params = new URLSearchParams({
            action: 'list', page,
            search:    currentSearch,
            date_from: currentFrom,
            date_to:   currentTo,
        });
        const res  = await fetch('gold_sale_list.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger py-4">${escHtml(data.message || 'ব্যর্থ হয়েছে।')}</td></tr>`;
            return;
        }

        // Stat bar & Karat bar
        const tot = data.totals || {};
        document.getElementById('statWeight').textContent = fmtTrad(tot.total_weight_g || 0);
        document.getElementById('statTotal').textContent  = fmtBDT(tot.total_amount   || 0);
        document.getElementById('statPaid').textContent   = fmtBDT(tot.total_paid     || 0);
        document.getElementById('statDue').textContent    = fmtBDT(tot.total_due      || 0);
        renderKaratBar(tot.by_karat);

        if (data.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">কোনো তথ্য পাওয়া যায়নি।</td></tr>';
        } else {
            tbody.innerHTML = data.data.map(row => {
                const due    = parseFloat(row.due_amount) || 0;
                const dueVal = fmtBDT(due);
                const dueClass = due <= 0 ? 'green' : 'red';
                return `
                <tr>
                    <td class="text-muted small">
                        <div>#${row.id}</div>
                        <div class="d-md-none" style="font-size:0.68rem;color:var(--text-secondary);">${fmtDate(row.created_at)}</div>
                    </td>
                    <td>
                        <div class="fw-semibold">${escHtml(row.customer_name)}</div>
                        <small class="text-muted">${escHtml(row.customer_phone || '')}</small>
                    </td>

                    <!-- Desktop columns -->
                    <td class="d-none d-md-table-cell">
                        <span class="badge badge-weight">${fmtTrad(row.total_weight_g)}</span>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <span class="badge badge-amount">${fmtBDT(row.total_amount)}</span>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <span class="badge badge-paid">${fmtBDT(row.paid_amount)}</span>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <span class="badge badge-due ${due <= 0 ? 'zero' : ''}">${dueVal}</span>
                    </td>

                    <!-- Mobile combined column -->
                    <td class="d-md-none sale-info-cell">
                        <div class="info-row">
                            <span class="info-label">ওজন</span>
                            <span class="info-value">${fmtTrad(row.total_weight_g)}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">মোট</span>
                            <span class="info-value">${fmtBDT(row.total_amount)}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">পরিশোধিত</span>
                            <span class="info-value">${fmtBDT(row.paid_amount)}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">বকেয়া</span>
                            <span class="info-value ${dueClass}">${dueVal}</span>
                        </div>
                    </td>

                    <td class="small d-none d-md-table-cell">${fmtDate(row.created_at)}</td>
                    <td class="small d-none d-md-table-cell">${escHtml(row.created_by_username || '—')}</td>
                    <td>
                        <div class="btn-actions">
                            <a href="gold_sale_list.php?view_id=${row.id}" class="btn btn-sm btn-outline-secondary btn-view" title="দ্রুত দেখুন">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="gold_sale_edit_inventory.php?id=${row.id}" class="btn btn-sm btn-outline-primary" title="এডিট / বিস্তারিত">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        document.getElementById('paginationInfo').textContent =
            `মোট ${data.totalRows} টি বিক্রয়ের রেকর্ডের মধ্যে ${data.data.length} টি দেখানো হচ্ছে`;
        renderPagination(data.page, data.totalPages);
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-4">নেটওয়ার্ক সমস্যা।</td></tr>';
    }
}

function renderPagination(page, totalPages) {
    const el = document.getElementById('paginationControls');
    el.innerHTML = '';
    if (totalPages <= 1) return;
    const mk = (label, target, disabled, active) => {
        const li = document.createElement('li');
        li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
        const a = document.createElement('a');
        a.className = 'page-link'; a.href = '#'; a.textContent = label;
        if (!disabled && !active) a.addEventListener('click', e => { e.preventDefault(); loadList(target); });
        li.appendChild(a); return li;
    };
    el.appendChild(mk('«', page - 1, page <= 1, false));
    for (let p = 1; p <= totalPages; p++) el.appendChild(mk(String(p), p, false, p === page));
    el.appendChild(mk('»', page + 1, page >= totalPages, false));
}

// ── Set Default / Reset Date Range (Current Month) ──
function resetToCurrentMonth() {
    const localDateStr = d => {
        const y  = d.getFullYear();
        const m  = String(d.getMonth() + 1).padStart(2, '0');
        const dy = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${dy}`;
    };
    const today = new Date();
    const from  = new Date(today.getFullYear(), today.getMonth(), 1);
    document.getElementById('dateFrom').value = localDateStr(from);
    document.getElementById('dateTo').value   = localDateStr(today);
    currentFrom = localDateStr(from);
    currentTo   = localDateStr(today);
}

// ── Filters ──
document.getElementById('searchInput').addEventListener('input', function () {
    clearTimeout(searchTimer);
    const val = this.value;
    searchTimer = setTimeout(() => { currentSearch = val.trim(); loadList(1); }, 350);
});
document.getElementById('clearSearchBtn').addEventListener('click', () => {
    document.getElementById('searchInput').value = '';
    currentSearch = '';
    resetToCurrentMonth();
    loadList(1);
});
document.getElementById('dateFrom').addEventListener('change', function () {
    currentFrom = this.value; loadList(1);
});
document.getElementById('dateTo').addEventListener('change', function () {
    currentTo = this.value; loadList(1);
});
document.getElementById('clearDatesBtn').addEventListener('click', () => {
    document.getElementById('searchInput').value = '';
    currentSearch = '';
    resetToCurrentMonth();
    loadList(1);
});

// Set default date range: current month (1st → today)
resetToCurrentMonth();

loadList(1);
</script>

<?php if ($viewSale): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const viewModalEl = document.getElementById('viewModal');
    if (viewModalEl) {
        const modal = new bootstrap.Modal(viewModalEl);
        modal.show();
    }
});
</script>
<?php endif; ?>

</body>
</html>