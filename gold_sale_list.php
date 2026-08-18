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
        if ($id <= 0) json_out(['success' => false, 'message' => 'Invalid ID.'], 400);

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
        $sale = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$sale) json_out(['success' => false, 'message' => 'Record not found.'], 404);

        $itemStmt = mysqli_prepare($conn,
            "SELECT id, weight, purity, price
             FROM gold_sale_items WHERE gold_sale_id = ? ORDER BY id ASC");
        mysqli_stmt_bind_param($itemStmt, 'i', $id);
        mysqli_stmt_execute($itemStmt);
        $items = [];
        while ($row = mysqli_fetch_assoc(mysqli_stmt_get_result($itemStmt))) $items[] = $row;

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
        $payments = [];
        while ($row = mysqli_fetch_assoc(mysqli_stmt_get_result($pmtStmt))) $payments[] = $row;

        $sale['items']    = $items;
        $sale['payments'] = $payments;
        json_out(['success' => true, 'data' => $sale]);
    }

    json_out(['success' => false, 'message' => 'Unknown action.'], 400);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gold Sale List — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --gold-deep: #c9973a;
    --gold-mid: #dcb04a;
    --gold-light: #e9cd7d;
    --ivory: #fbf8f2;
    --bronze-text: #3a2f1a;
    --muted: #9a8f76;
    --hairline: #ecdfb8;

    --status-paid-bg: #1b5238;
    --status-paid-light: #eaf4ee;
    --status-due-bg: #93292c;
    --status-due-light: #fbeceb;
    --status-total-bg: #b88328;
    --status-total-light: #fdf6e2;

    /* Premium summary-card palette (matches customer_history.php) */
    --sc-bg: #F8F7F3;
    --sc-card: #FFFFFF;
    --sc-gold: #C9972B;
    --sc-gold-dark: #A97816;
    --sc-gold-light: #FBF5E7;
    --sc-text: #252525;
    --sc-text-2: #77736A;
    --sc-border: #ECE8DF;
    --sc-due-bg: #FDF0F0;
    --sc-due-text: #B33A3A;
    --sc-success: #246047;
}

body {
    background: var(--ivory);
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--bronze-text);
}

/* ---- Page header (flush) ---- */
.fb-header {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%) !important;
    color: #ffffff !important;
    width: 100% !important;
    margin: 0 !important;
    min-height: 60px !important;
    max-height: 80px !important;
    padding: 0.85rem 1.75rem !important;
    border-radius: 0 0 20px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: nowrap;
    gap: 1rem;
    position: relative;
}
.fb-header * { color: #ffffff !important; }
.fb-header h4 { font-weight: 800; margin-bottom: 0.1rem; }
.fb-header small { color: rgba(255,255,255,0.85) !important; }
.fb-header .btn-fb-header {
    background: rgba(255,255,255,0.16);
    border: 1.5px solid rgba(255,255,255,0.55);
    color: #ffffff !important;
    font-weight: 600;
    border-radius: 999px;
    white-space: nowrap;
}
.fb-header .btn-fb-header:hover { background: rgba(255,255,255,0.26); }

/* ---- Page content inset ---- */
.page-inset { padding: 0 1.5rem; }

/* ---- Cards ---- */
.card {
    background: #ffffff;
    border: none;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(180,140,50,0.12);
}
.card-header {
    background: var(--ivory) !important;
    border-bottom: 1px solid var(--hairline);
    border-radius: 18px 18px 0 0 !important;
    color: var(--bronze-text);
    font-weight: 700;
}
.card-footer {
    background: #ffffff !important;
    border-top: 1px solid var(--hairline);
}

/* ---- Inputs ---- */
.form-control,
.input-group-text {
    border: 1.5px solid var(--hairline);
    border-radius: 10px;
    color: var(--bronze-text);
    background: #ffffff;
}
.form-control:focus {
    border-color: var(--gold-deep);
    box-shadow: 0 0 0 0.15rem rgba(201,151,58,0.18);
}

/* ---- Buttons ---- */
.btn-fb-primary, .btn-gold {
    background: var(--gold-deep);
    border: 1.5px solid var(--gold-deep);
    color: #ffffff;
    font-weight: 700;
    border-radius: 999px;
}
.btn-fb-primary:hover, .btn-gold:hover {
    opacity: 0.92;
    background: var(--gold-deep);
    border-color: var(--gold-deep);
    color: #ffffff;
}
.btn-fb-secondary {
    background: #ffffff;
    border: 1.5px solid var(--hairline);
    color: var(--muted);
    font-weight: 600;
    border-radius: 999px;
}
.btn-fb-secondary:hover {
    background: #fdf7ec;
    border-color: var(--hairline);
    color: var(--bronze-text);
}

/* ---- summary card (Weight / Total / Paid / Due) ---- */
.section-block { margin-bottom: 1.25rem; }
.sc-card {
    background: var(--sc-card);
    border: 1px solid var(--sc-border);
    border-radius: 14px;
    box-shadow: 0 2px 8px rgba(37, 37, 37, 0.03);
    overflow: hidden;
}
.sc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 10px 14px;
    border-bottom: 1px solid var(--sc-border);
}
.sc-header-left { display: flex; align-items: center; gap: 8px; }
.sc-icon {
    width: 26px; height: 26px; min-width: 26px;
    border-radius: 50%;
    background: var(--sc-gold-light);
    border: 1px solid var(--sc-border);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem;
    color: var(--sc-gold);
}
.section-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--sc-gold-dark);
    margin: 0;
}
.sc-header-icon { color: var(--sc-gold); font-size: 0.85rem; opacity: 0.8; }

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
    border-right: 1px solid var(--sc-border);
    background: var(--sc-card);
}
.stat-cell:last-child { border-right: none; }
.stat-cell .s-icon {
    width: 26px; height: 26px; min-width: 26px;
    border-radius: 50%;
    background: rgba(201, 151, 43, 0.09);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.72rem;
    color: var(--sc-gold-dark);
}
.stat-cell .s-text { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.stat-cell .s-label {
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--sc-text-2);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.stat-cell .s-value {
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.01em;
    color: var(--sc-text);
    white-space: nowrap;
}
.stat-cell.stat-emphasis { background: var(--sc-gold-light); }
.stat-cell.stat-emphasis .s-value { color: var(--sc-gold-dark); }
.stat-cell.stat-emphasis .s-icon { background: #ffffff; }
.stat-cell.stat-due { background: var(--sc-due-bg); }
.stat-cell.stat-due .s-value { color: var(--sc-due-text); }
.stat-cell.stat-due .s-icon { background: #ffffff; color: var(--sc-due-text); }

/* ---- amount badges ---- */
.badge-amount { background: var(--status-total-light); color: var(--status-total-bg); font-weight: 600; font-size: 0.82rem; }
.badge-paid   { background: var(--status-paid-light);  color: var(--status-paid-bg);  font-weight: 600; font-size: 0.82rem; }
.badge-due    { background: var(--status-due-light);   color: var(--status-due-bg);   font-weight: 600; font-size: 0.82rem; }
.badge-due.zero { background: var(--status-paid-light); color: var(--status-paid-bg); }
.badge-weight { background: var(--status-total-light); color: var(--status-total-bg); font-weight: 600; font-size: 0.82rem;
                border: 1px solid var(--gold-deep); }

/* ---- action buttons ---- */
.btn-actions { display: flex; gap: 4px; justify-content: center; }
.btn-actions .btn {
    border-radius: 999px;
    border: 1.5px solid var(--hairline);
    background: #fff;
    color: var(--muted);
}
.btn-actions .btn:hover { background: #fdf7ec; color: var(--bronze-text); }

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
.sale-info-cell .info-label { color: var(--muted); }
.sale-info-cell .info-value { font-weight: 600; color: var(--bronze-text); }
.sale-info-cell .info-value.red   { color: var(--status-due-bg); }
.sale-info-cell .info-value.green { color: var(--status-paid-bg); }

/* ---- modal ledger ---- */
.ledger-table td { padding: 0.55rem 0.85rem; vertical-align: middle;
                    border-bottom: 1px solid var(--hairline); --bs-table-bg: transparent; }
.ledger-table tr:last-child td { border-bottom: none; }
.ledger-label { font-size: 0.82rem; color: var(--muted); white-space: nowrap; width: 1%; }
.ledger-vorp  { font-weight: 700; font-size: 0.95rem; text-align: right; }
.ledger-total td { background-color: var(--status-total-light) !important; }
.ledger-total .ledger-label { color: var(--status-total-bg); font-weight: 600; }
.ledger-total .ledger-vorp  { color: var(--status-total-bg); }
.ledger-paid td  { background-color: var(--status-paid-light) !important; }
.ledger-paid .ledger-label  { color: var(--status-paid-bg); font-weight: 600; }
.ledger-paid .ledger-vorp   { color: var(--status-paid-bg); }
.ledger-due td   { background-color: var(--gold-deep) !important; border-bottom: none; }
.ledger-due .ledger-label   { color: rgba(255,255,255,0.9); font-weight: 600; }
.ledger-due .ledger-vorp    { color: #fff; font-size: 1.05rem; }

/* ---- payment history in modal ---- */
.pmt-row {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    padding: 0.55rem 0;
    border-bottom: 1px dashed var(--hairline);
    font-size: 0.83rem;
}
.pmt-row:last-child { border-bottom: none; }
.pmt-date { color: var(--muted); white-space: nowrap; min-width: 80px; }
.pmt-amount { font-weight: 700; color: var(--status-paid-bg); white-space: nowrap; }
.pmt-ref { color: var(--bronze-text); font-size: 0.78rem; }
.pmt-note { color: var(--muted); font-size: 0.76rem; font-style: italic; }

/* ---- filter bar ---- */
.filter-bar { background: #fff; border-bottom: 1px solid var(--hairline); padding: 0.65rem 1rem;
              display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.filter-bar label { font-size: 0.75rem; color: var(--muted); margin-bottom: 0; }
.filter-bar input[type=date] { font-size: 0.82rem; padding: 0.3rem 0.5rem;
                                border: 1.5px solid var(--hairline); border-radius: 8px; }

/* ---- modal ---- */
.modal-content {
    border-radius: 18px;
    overflow: hidden;
    border: none;
}
.modal-header {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%);
    color: #fff;
}
.modal-header .btn-close {
    filter: brightness(0) invert(1);
}

/* ---- mobile ---- */
/* ---- tablet: 2 metrics per row ---- */
@media (min-width: 768px) and (max-width: 991.98px) {
    .stat-bar { grid-template-columns: repeat(2, 1fr); }
    .stat-cell {
        border-right: 1px solid var(--sc-border);
        border-bottom: 1px solid var(--sc-border);
    }
    .stat-bar .stat-cell:nth-child(2n) { border-right: none; }
    .stat-bar .stat-cell:nth-last-child(-n+2) { border-bottom: none; }
}

@media (max-width: 767.98px) {
    .page-inset { padding: 0 0.8rem; }

    .fb-header {
        min-height: 60px !important;
        max-height: 70px !important;
        padding: 0.75rem 1rem !important;
        border-radius: 0 0 16px 16px;
    }
    .fb-header h4 { font-size: 1rem; margin-bottom: 0; }
    .fb-header small { font-size: 0.7rem; }
    .fb-header .btn-fb-header { padding: 0.3rem 0.65rem; font-size: 0.72rem; }

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
        border-right: 1px solid var(--sc-border);
        border-bottom: 1px solid var(--sc-border);
    }
    .stat-bar .stat-cell:nth-child(2n) { border-right: none; }
    .stat-bar .stat-cell:nth-last-child(-n+2) { border-bottom: none; }
    .stat-cell .s-icon { width: 22px; height: 22px; min-width: 22px; font-size: 0.62rem; }
    .stat-cell .s-label { font-size: 8px; }
    .stat-cell .s-value { font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

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
        <div>
            <h4 class="mb-0">
                <i class="bi bi-bag-check-fill me-2"></i>
                <span class="d-none d-md-inline">Gold Sale History</span>
                <span class="d-md-none">GOLD SALE LIST</span>
            </h4>
            <small>FineBullion Desk</small>
        </div>
        <a href="gold_sale.php" class="btn btn-fb-header btn-sm d-inline-flex align-items-center">
            <i class="bi bi-plus-lg me-1"></i> <span>New Sale</span>
        </a>
    </div>
</div>

<div class="page-inset py-4">

    <!-- Summary card -->
    <div class="section-block">
        <div class="sc-card">
            <div class="sc-header">
                <div class="sc-header-left">
                    <div class="sc-icon"><i class="bi bi-tag"></i></div>
                    <p class="section-label">Gold Sale</p>
                </div>
                <i class="bi bi-graph-up-arrow sc-header-icon"></i>
            </div>
            <div class="stat-bar" id="statBar">
                <div class="stat-cell">
                    <div class="s-icon"><i class="bi bi-speedometer"></i></div>
                    <div class="s-text">
                        <span class="s-label">Weight</span>
                        <span class="s-value" id="statWeight">—</span>
                    </div>
                </div>
                <div class="stat-cell stat-emphasis">
                    <div class="s-icon"><i class="bi bi-cash-stack"></i></div>
                    <div class="s-text">
                        <span class="s-label">Total Amount</span>
                        <span class="s-value" id="statTotal">—</span>
                    </div>
                </div>
                <div class="stat-cell">
                    <div class="s-icon"><i class="bi bi-wallet2"></i></div>
                    <div class="s-text">
                        <span class="s-label">Total Paid</span>
                        <span class="s-value" id="statPaid">—</span>
                    </div>
                </div>
                <div class="stat-cell stat-due">
                    <div class="s-icon"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="s-text">
                        <span class="s-label">Total Due</span>
                        <span class="s-value" id="statDue">—</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Table card -->
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-list-ul me-1"></i> Sale Records</span>
            <div class="input-group" style="max-width:300px;">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control" placeholder="Search customer name, phone…">
                <button class="btn btn-fb-secondary" id="clearSearchBtn"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>

        <!-- Date filter bar -->
        <div class="filter-bar">
            <span class="d-none d-md-inline" style="font-size:0.8rem;color:#6c757d;">Category</span>
            <span class="d-none d-md-inline badge bg-light text-dark border" style="font-size:0.75rem;">All</span>
            <span class="ms-auto d-none d-md-inline" style="font-size:0.8rem;color:#6c757d;">From</span>
            <label class="d-md-none" style="font-size:0.72rem;color:#6c757d;">From</label>
            <input type="date" id="dateFrom">
            <span style="font-size:0.8rem;color:#6c757d;">To</span>
            <input type="date" id="dateTo">
            <button class="btn btn-sm btn-fb-secondary ms-1" id="clearDatesBtn" title="Clear dates">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:60px;">#</th>
                            <th>Customer</th>
                            <!-- Desktop columns -->
                            <th class="d-none d-md-table-cell">Weight</th>
                            <th class="d-none d-md-table-cell">Total Amount</th>
                            <th class="d-none d-md-table-cell">Paid Amount</th>
                            <th class="d-none d-md-table-cell">Due Amount</th>
                            <!-- Mobile combined column -->
                            <th class="d-md-none">Weight &amp; Amount</th>
                            <th class="d-none d-md-table-cell" style="width:130px;">Date</th>
                            <th class="d-none d-md-table-cell" style="width:110px;">By</th>
                            <th style="width:90px;" class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <tr><td colspan="9" class="text-center text-muted py-4">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted" id="paginationInfo">—</small>
            <nav><ul class="pagination pagination-sm mb-0" id="paginationControls"></ul></nav>
        </div>
    </div>

</div>
</div>

<!-- ================================================================
     VIEW MODAL
================================================================ -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-receipt me-2"></i>Sale #<span id="viewId"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewBody">
                <div class="text-center text-muted py-4">Loading…</div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-fb-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-1"></i>Close
                </button>
                <a id="btnOpenEdit" href="#" class="btn btn-fb-primary btn-sm">
                    <i class="bi bi-pencil-square me-1"></i>Open Full Detail / Edit
                </a>
            </div>
        </div>
    </div>
</div>

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
    return { vori, ana, roti, point };
}

function fmtTrad(grams) {
    const t = gramsToTraditional(parseFloat(grams) || 0);
    return `${t.vori}V ${t.ana}A ${t.roti}R ${t.point}P`;
}

function fmtBDT(n) {
    return '৳' + Math.round(parseFloat(n) || 0).toLocaleString('en-BD');
}

function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(s.replace(' ', 'T'));
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
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
    tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Loading…</td></tr>';

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
            tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger py-4">${escHtml(data.message || 'Failed.')}</td></tr>`;
            return;
        }

        // Stat bar
        const tot = data.totals || {};
        document.getElementById('statWeight').textContent = fmtTrad(tot.total_weight_g || 0);
        document.getElementById('statTotal').textContent  = fmtBDT(tot.total_amount   || 0);
        document.getElementById('statPaid').textContent   = fmtBDT(tot.total_paid     || 0);
        document.getElementById('statDue').textContent    = fmtBDT(tot.total_due      || 0);

        if (data.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">No sale records found.</td></tr>';
        } else {
            tbody.innerHTML = data.data.map(row => {
                const due      = parseFloat(row.due_amount) || 0;
                const dueVal   = fmtBDT(due);
                const dueClass = due <= 0 ? 'green' : 'red';
                return `
                <tr>
                    <td class="text-muted small">
                        <div>#${row.id}</div>
                        <div class="d-md-none" style="font-size:0.68rem;color:#999;">${fmtDate(row.created_at)}</div>
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
                            <span class="info-label">Weight</span>
                            <span class="info-value">${fmtTrad(row.total_weight_g)}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Total Amount</span>
                            <span class="info-value">${fmtBDT(row.total_amount)}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Paid Amount</span>
                            <span class="info-value">${fmtBDT(row.paid_amount)}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Due Amount</span>
                            <span class="info-value ${dueClass}">${dueVal}</span>
                        </div>
                    </td>

                    <td class="small d-none d-md-table-cell">${fmtDate(row.created_at)}</td>
                    <td class="small d-none d-md-table-cell">${escHtml(row.created_by_username || '—')}</td>
                    <td>
                        <div class="btn-actions">
                            <button class="btn btn-sm btn-view" title="Quick view" data-id="${row.id}">
                                <i class="bi bi-eye"></i>
                            </button>
                            <a href="gold_sale_edit.php?id=${row.id}" class="btn btn-sm" title="Edit / detail">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        document.getElementById('paginationInfo').textContent =
            `Showing ${data.data.length} of ${data.totalRows} sale record(s)`;
        renderPagination(data.page, data.totalPages);
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-4">Network error.</td></tr>';
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

// ── Filters ──
document.getElementById('searchInput').addEventListener('input', function () {
    clearTimeout(searchTimer);
    const val = this.value;
    searchTimer = setTimeout(() => { currentSearch = val.trim(); loadList(1); }, 350);
});
document.getElementById('clearSearchBtn').addEventListener('click', () => {
    document.getElementById('searchInput').value = '';
    currentSearch = ''; loadList(1);
});
document.getElementById('dateFrom').addEventListener('change', function () {
    currentFrom = this.value; loadList(1);
});
document.getElementById('dateTo').addEventListener('change', function () {
    currentTo = this.value; loadList(1);
});
document.getElementById('clearDatesBtn').addEventListener('click', () => {
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value   = '';
    currentFrom = ''; currentTo = ''; loadList(1);
});

// ── View modal ──
document.getElementById('tableBody').addEventListener('click', async function (e) {
    const btn = e.target.closest('.btn-view');
    if (!btn) return;
    await openView(btn.dataset.id);
});

async function openView(id) {
    document.getElementById('btnOpenEdit').href = 'gold_sale_edit.php?id=' + id;
    document.getElementById('viewId').textContent = id;
    document.getElementById('viewBody').innerHTML = '<div class="text-center text-muted py-4">Loading…</div>';
    const modal = new bootstrap.Modal(document.getElementById('viewModal'));
    modal.show();

    try {
        const res  = await fetch('gold_sale_list.php?action=get&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        if (!data.success) {
            document.getElementById('viewBody').innerHTML = `<div class="text-danger">${escHtml(data.message || 'Failed.')}</div>`;
            return;
        }
        const s = data.data;

        const itemsHtml = s.items.map((it, idx) => {
            const t = gramsToTraditional(parseFloat(it.weight) || 0);
            const purity = parseFloat(it.purity || 24).toFixed(2);
            return `<tr>
                <td class="text-muted">${idx + 1}</td>
                <td>${t.vori}V ${t.ana}A ${t.roti}R ${t.point}P</td>
                <td class="text-center">
                    <span class="badge" style="background:var(--status-total-light);color:var(--status-total-bg);font-weight:600;font-size:0.75rem;">
                        ${purity}K
                    </span>
                </td>
                <td class="text-end">${fmtBDT(it.price)}</td>
            </tr>`;
        }).join('');

        // Payment history rows
        const paymentsHtml = (s.payments && s.payments.length > 0)
            ? s.payments.map(p => `
                <div class="pmt-row">
                    <div class="pmt-date">${fmtDate(p.payment_date)}</div>
                    <div class="flex-grow-1">
                        ${p.transaction_ref ? `<span class="pmt-ref me-1"><i class="bi bi-hash"></i>${escHtml(p.transaction_ref)}</span>` : ''}
                        ${p.note ? `<span class="pmt-note">${escHtml(p.note)}</span>` : ''}
                        ${p.received_by_username ? `<small class="text-muted ms-1">· ${escHtml(p.received_by_username)}</small>` : ''}
                    </div>
                    <div class="pmt-amount">${fmtBDT(p.paid_amount)}</div>
                </div>`).join('')
            : '<div class="text-muted small fst-italic">No payments recorded yet.</div>';

        const due = parseFloat(s.due_amount) || 0;

        document.getElementById('viewBody').innerHTML = `
            <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
                <div>
                    <div class="fw-semibold fs-6">${escHtml(s.customer_name)}</div>
                    <small class="text-muted">${escHtml(s.customer_phone || '')}</small>
                </div>
                <div class="text-end">
                    <small class="text-muted d-block">${fmtDate(s.created_at)}</small>
                    <small class="text-muted">By ${escHtml(s.created_by_username || '—')}</small>
                </div>
            </div>

            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:32px;">#</th>
                            <th>Weight (VARP)</th>
                            <th style="width:90px;" class="text-center">Purity</th>
                            <th class="text-end">Item Price</th>
                        </tr>
                    </thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
            </div>

            <table class="table table-sm mb-3 ledger-table">
                <tbody>
                    <tr class="ledger-total">
                        <td class="ledger-label">Pure Gold Price (24k / Vori)</td>
                        <td class="ledger-vorp">${fmtBDT(s.pure_gold_price)}</td>
                    </tr>
                    <tr class="ledger-total">
                        <td class="ledger-label">Total Amount</td>
                        <td class="ledger-vorp">${fmtBDT(s.total_amount)}</td>
                    </tr>
                    <tr class="ledger-paid">
                        <td class="ledger-label">Total Paid</td>
                        <td class="ledger-vorp">${fmtBDT(s.paid_amount)}</td>
                    </tr>
                    <tr class="ledger-due">
                        <td class="ledger-label">Due Amount</td>
                        <td class="ledger-vorp">${fmtBDT(due)}</td>
                    </tr>
                </tbody>
            </table>

            <!-- Payment History -->
            <div class="mb-3">
                <div class="fw-semibold small text-uppercase mb-2" style="letter-spacing:.04em;color:var(--status-total-bg);">
                    <i class="bi bi-cash-stack me-1"></i> Payment History
                </div>
                <div class="border rounded p-2">
                    ${paymentsHtml}
                </div>
            </div>

            ${s.note ? `<div class="alert alert-light border mb-0 py-2"><strong>Note:</strong> ${escHtml(s.note)}</div>` : ''}
        `;
    } catch {
        document.getElementById('viewBody').innerHTML = '<div class="text-danger">Network error.</div>';
    }
}

// Set default date range: past 30 days → today
(function setDefaultDates() {
    const localDateStr = d => {
        const y  = d.getFullYear();
        const m  = String(d.getMonth() + 1).padStart(2, '0');
        const dy = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${dy}`;
    };
    const today = new Date();
    const from  = new Date(); from.setDate(today.getDate() - 30);
    document.getElementById('dateFrom').value = localDateStr(from);
    document.getElementById('dateTo').value   = localDateStr(today);
    currentFrom = localDateStr(from);
    currentTo   = localDateStr(today);
})();

loadList(1);
</script>

</body>
</html>