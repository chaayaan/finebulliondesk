<?php
/**
 * gold_buy_list.php
 * FineBullion Desk — Old Gold Buy history / listing
 *
 * Mobile : stat bar (Weight / Total Amount / Total Paid / Total Due)
 *          + compact table rows with stacked Weight & Amount info cell.
 * Desktop: same stat bar + full table with individual columns.
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
            $conditions[] = "DATE(gb.created_at) >= ?";
            $params[] = $dateFrom; $types .= 's';
        }
        if ($dateTo !== '') {
            $conditions[] = "DATE(gb.created_at) <= ?";
            $params[] = $dateTo; $types .= 's';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Totals (for stat bar) — paid/due are derived from gold_buy_payments,
        // the source of truth (gb.paid_amount is kept in sync but this is safer).
        $totSql = "SELECT
                       COALESCE(SUM(gbi.weight),      0) AS total_weight_g,
                       COALESCE(SUM(gb.total_amount),  0) AS total_amount,
                       COALESCE(SUM(gbp.paid), 0) AS total_paid,
                       COALESCE(SUM(gb.total_amount - COALESCE(gbp.paid, 0)), 0) AS total_due
                   FROM gold_buys gb
                   JOIN customers c ON c.id = gb.customer_id
                   LEFT JOIN (
                       SELECT gold_buy_id, SUM(weight) AS weight
                       FROM gold_buy_items GROUP BY gold_buy_id
                   ) gbi ON gbi.gold_buy_id = gb.id
                   LEFT JOIN (
                       SELECT gold_buy_id, SUM(paid_amount) AS paid
                       FROM gold_buy_payments GROUP BY gold_buy_id
                   ) gbp ON gbp.gold_buy_id = gb.id
                   $where";
        $totStmt = mysqli_prepare($conn, $totSql);
        if ($params) mysqli_stmt_bind_param($totStmt, $types, ...$params);
        mysqli_stmt_execute($totStmt);
        $totRow = mysqli_fetch_assoc(mysqli_stmt_get_result($totStmt));

        // Count
        $cntSql  = "SELECT COUNT(*) FROM gold_buys gb
                    JOIN customers c ON c.id = gb.customer_id
                    $where";
        $cntStmt = mysqli_prepare($conn, $cntSql);
        if ($params) mysqli_stmt_bind_param($cntStmt, $types, ...$params);
        mysqli_stmt_execute($cntStmt);
        mysqli_stmt_bind_result($cntStmt, $total);
        mysqli_stmt_fetch($cntStmt);
        mysqli_stmt_close($cntStmt);

        // Rows
        $sql = "SELECT gb.id, gb.customer_id, c.name AS customer_name, c.phone AS customer_phone,
                       gb.pure_gold_price, gb.total_amount,
                       COALESCE(gbp.paid, 0) AS paid_amount,
                       (gb.total_amount - COALESCE(gbp.paid, 0)) AS due_amount,
                       COALESCE(gbi.weight, 0) AS total_weight_g,
                       COALESCE(gbi.item_count, 0) AS item_count,
                       gb.note, gb.created_at, u.username AS created_by_username
                FROM gold_buys gb
                JOIN customers c ON c.id = gb.customer_id
                LEFT JOIN users u ON u.id = gb.created_by
                LEFT JOIN (
                    SELECT gold_buy_id,
                           SUM(weight)  AS weight,
                           COUNT(*)     AS item_count
                    FROM gold_buy_items
                    GROUP BY gold_buy_id
                ) gbi ON gbi.gold_buy_id = gb.id
                LEFT JOIN (
                    SELECT gold_buy_id, SUM(paid_amount) AS paid
                    FROM gold_buy_payments GROUP BY gold_buy_id
                ) gbp ON gbp.gold_buy_id = gb.id
                $where
                ORDER BY gb.created_at DESC
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

    // ---- GET single (with items) -----------------------------------------
    if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'অকার্যকর আইডি।'], 400);

        $stmt = mysqli_prepare($conn,
            "SELECT gb.id, gb.customer_id, c.name AS customer_name, c.phone AS customer_phone,
                    gb.pure_gold_price, gb.total_amount, gb.paid_amount,
                    (gb.total_amount - gb.paid_amount) AS due_amount,
                    gb.note, gb.created_at, gb.updated_at, u.username AS created_by_username
             FROM gold_buys gb
             JOIN customers c ON c.id = gb.customer_id
             LEFT JOIN users u ON u.id = gb.created_by
             WHERE gb.id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $buy = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$buy) json_out(['success' => false, 'message' => 'কোনো তথ্য পাওয়া যায়নি।'], 404);

        $itemStmt = mysqli_prepare($conn,
            "SELECT id, weight, purity, price
             FROM gold_buy_items WHERE gold_buy_id = ? ORDER BY id ASC");
        mysqli_stmt_bind_param($itemStmt, 'i', $id);
        mysqli_stmt_execute($itemStmt);
        $items = [];
        while ($row = mysqli_fetch_assoc(mysqli_stmt_get_result($itemStmt))) $items[] = $row;

        // Payment history — paid/due shown to the user are recalculated from
        // this table rather than trusting gb.paid_amount blindly.
        $pStmt = mysqli_prepare($conn,
            "SELECT p.id, p.paid_amount, p.transaction_ref,
                    p.payment_date, p.note, u.username AS received_by
             FROM gold_buy_payments p
             LEFT JOIN users u ON u.id = p.received_by
             WHERE p.gold_buy_id = ?
             ORDER BY p.payment_date ASC, p.created_at ASC");
        mysqli_stmt_bind_param($pStmt, 'i', $id);
        mysqli_stmt_execute($pStmt);
        $payments = mysqli_fetch_all(mysqli_stmt_get_result($pStmt), MYSQLI_ASSOC);

        $buy['items']       = $items;
        $buy['payments']    = $payments;
        $buy['total_paid']  = array_sum(array_column($payments, 'paid_amount'));
        $buy['paid_amount'] = $buy['total_paid'];
        $buy['due_amount']  = (float)$buy['total_amount'] - $buy['total_paid'];

        json_out(['success' => true, 'data' => $buy]);
    }

    json_out(['success' => false, 'message' => 'অজানা অ্যাকশন।'], 400);
}
?>
<!DOCTYPE html>
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>সোনা কেনার তালিকা — ফাইনবুলিয়ন ডায়াল</title>
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

/* ---- page header ---- */
.fb-header {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%) !important;
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
.fb-header h4, .fb-header h4 i { color: #ffffff !important; }
.fb-header small { color: rgba(255,255,255,0.85) !important; }
.fb-header .header-title-block { min-width: 0; }
.fb-header .header-actions { display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; }

.page-inset { padding: 0 1.5rem; }

/* ---- pill buttons ---- */
.btn-fb-primary, .btn-gold {
    background: var(--gold-deep);
    border: 1.5px solid var(--gold-deep);
    color: #ffffff !important;
    font-weight: 700;
    border-radius: 999px;
}
.btn-fb-primary:hover, .btn-gold:hover { opacity: 0.92; background: var(--gold-deep); border-color: var(--gold-deep); color: #ffffff !important; }

.btn-fb-secondary, .btn-secondary {
    background: #ffffff;
    border: 1.5px solid var(--hairline);
    color: var(--muted);
    font-weight: 600;
    border-radius: 999px;
}
.btn-fb-secondary:hover, .btn-secondary:hover { background: #fdf7ec; border-color: var(--hairline); color: var(--bronze-text); }

.btn-outline-secondary {
    background: #ffffff;
    border: 1.5px solid var(--hairline);
    color: var(--muted);
    font-weight: 600;
    border-radius: 999px;
}
.btn-outline-secondary:hover { background: #fdf7ec; border-color: var(--hairline); color: var(--bronze-text); }

.btn-outline-danger {
    background: #ffffff;
    border: 1.5px solid var(--status-due-bg);
    color: var(--status-due-bg);
    font-weight: 600;
    border-radius: 999px;
}
.btn-outline-danger:hover { background: var(--status-due-bg); border-color: var(--status-due-bg); color: #ffffff; }

.btn-outline-primary {
    background: #ffffff;
    border: 1.5px solid var(--gold-deep);
    color: var(--gold-deep);
    font-weight: 600;
    border-radius: 999px;
}
.btn-outline-primary:hover { background: var(--gold-deep); border-color: var(--gold-deep); color: #ffffff; }

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
.badge-paid   { background: var(--status-paid-light); color: var(--status-paid-bg); font-weight: 600; font-size: 0.82rem; }
.badge-due    { background: var(--status-due-light); color: var(--status-due-bg); font-weight: 600; font-size: 0.82rem; }
.badge-due.zero { background: var(--status-paid-light); color: var(--status-paid-bg); }
.badge-weight { background: var(--ivory); color: var(--bronze-text); font-weight: 600; font-size: 0.82rem;
                border: 1px solid var(--hairline); }

/* ---- action buttons ---- */
.btn-actions { display: flex; gap: 4px; justify-content: center; }

/* ---- mobile info cell ---- */
.buy-info-cell { min-width: 160px; }
.buy-info-cell .info-row {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
    font-size: 0.72rem;
    line-height: 1.45;
    white-space: nowrap;
}
.buy-info-cell .info-label { color: var(--muted); }
.buy-info-cell .info-value { font-weight: 600; color: var(--bronze-text); }
.buy-info-cell .info-value.red { color: var(--status-due-bg); }
.buy-info-cell .info-value.green { color: var(--status-paid-bg); }

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
.ledger-due td   { background-color: var(--status-due-bg) !important; border-bottom: none; }
.ledger-due .ledger-label   { color: rgba(255,255,255,0.85); font-weight: 600; }
.ledger-due .ledger-vorp    { color: #fff; font-size: 1.05rem; }

/* ---- filter bar ---- */
.filter-bar { background: #ffffff; border-bottom: 1px solid var(--hairline); padding: 0.65rem 1rem;
              display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.filter-bar label { font-size: 0.75rem; color: var(--muted); margin-bottom: 0; }
.filter-bar input[type=date] { font-size: 0.82rem; padding: 0.3rem 0.5rem;
                                border: 1.5px solid var(--hairline); border-radius: 10px; }

/* ---- cards ---- */
.card { background:#ffffff; border:none; border-radius:18px; box-shadow:0 10px 30px rgba(180,140,50,0.12); }
.card-header { background: var(--ivory) !important; border-bottom:1px solid var(--hairline); border-radius:18px 18px 0 0 !important; color: var(--bronze-text); }
.card-footer { background:#ffffff !important; border-top:1px solid var(--hairline); border-radius: 0 0 18px 18px !important; }

/* ---- inputs ---- */
.form-control, .form-select, .input-group-text {
    border: 1.5px solid var(--hairline);
    border-radius: 10px;
    color: var(--bronze-text);
    background: #ffffff;
}
.form-control:focus, .form-select:focus { border-color: var(--gold-deep); box-shadow: 0 0 0 0.15rem rgba(201,151,58,0.18); }

/* ---- table ---- */
.table thead.table-light th, .table thead th {
    background: var(--ivory) !important;
    color: var(--muted);
    text-transform: uppercase;
    font-size: 0.72rem;
    letter-spacing: 0.04em;
    border-color: var(--hairline) !important;
    font-weight: 700;
}
.table td { border-color: var(--hairline) !important; vertical-align: middle; }
.table tbody tr:hover { background: #fdf7ec; }

/* ---- modal ---- */
.modal-content { border-radius: 18px; overflow: hidden; border: none; }
.modal-header { background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%) !important; color: #fff !important; }
.modal-header .modal-title { color: #ffffff; font-weight: 800; }
.modal-header .btn-close, .modal-header .btn-close-white { filter: brightness(0) invert(1); }

/* ---- badges (bootstrap default overrides) ---- */
.badge.bg-light { background: var(--ivory) !important; color: var(--muted) !important; border-color: var(--hairline) !important; }

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
    <div class="fb-header">
        <div class="header-title-block">
            <h4 class="mb-0">
                <i class="bi bi-cash-coin me-2"></i>
                <span class="d-none d-md-inline">সোনা কেনার ইতিহাস</span>
                <span class="d-md-none">সোনা কেনার তালিকা</span>
            </h4>
            <small>ফাইনবুলিয়ন ডায়াল</small>
        </div>
        <div class="header-actions">
            <a href="gold_buy.php" class="btn btn-fb-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> <span>নতুন কেনা</span>
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
                    <div class="sc-icon"><i class="bi bi-cart"></i></div>
                    <p class="section-label">সোনা কেনা</p>
                </div>
                <i class="bi bi-bag-check sc-header-icon"></i>
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
        </div>
    </div>

    <!-- Table card -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-list-ul me-1"></i> কেনাকাটার রেকর্ডসমূহ</span>
            <div class="input-group" style="max-width:300px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control" placeholder="কাস্টমারের নাম, ফোন নম্বর খুঁজুন…">
                <button class="btn btn-outline-secondary" id="clearSearchBtn"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>

        <!-- Date filter bar -->
        <div class="filter-bar">
            <span class="d-none d-md-inline" style="font-size:0.8rem;color:var(--muted);">ক্যাটাগরি</span>
            <span class="d-none d-md-inline badge bg-light text-dark border" style="font-size:0.75rem;">সব</span>
            <span class="ms-auto d-none d-md-inline" style="font-size:0.8rem;color:var(--muted);">শুরু</span>
            <label class="d-md-none" style="font-size:0.72rem;color:var(--muted);">শুরু</label>
            <input type="date" id="dateFrom">
            <span style="font-size:0.8rem;color:var(--muted);">শেষ</span>
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
     VIEW MODAL
================================================================ -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-receipt me-2"></i>কেনাকাটা #<span id="viewId"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewBody">
                <div class="text-center text-muted py-4">লোড হচ্ছে…</div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-fb-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-1"></i>বন্ধ করুন
                </button>
                <a id="btnOpenEdit" href="#" class="btn btn-fb-primary btn-sm">
                    <i class="bi bi-pencil-square me-1"></i>সম্পূর্ণ বিস্তারিত / এডিট
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
    return `${t.vori}ভ ${t.ana}আ ${t.roti}র ${t.point}প`;
}

function fmtBDT(n) {
    return '৳' + Math.round(parseFloat(n) || 0).toLocaleString('bn-BD');
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
        const res  = await fetch('gold_buy_list.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger py-4">${escHtml(data.message || 'ব্যর্থ হয়েছে।')}</td></tr>`;
            return;
        }

        // Stat bar
        const tot = data.totals || {};
        document.getElementById('statWeight').textContent = fmtTrad(tot.total_weight_g || 0);
        document.getElementById('statTotal').textContent  = fmtBDT(tot.total_amount   || 0);
        document.getElementById('statPaid').textContent   = fmtBDT(tot.total_paid     || 0);
        document.getElementById('statDue').textContent    = fmtBDT(tot.total_due      || 0);

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
                    <td class="d-md-none buy-info-cell">
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
                            <button class="btn btn-sm btn-outline-secondary btn-view" title="দ্রুত দেখুন" data-id="${row.id}">
                                <i class="bi bi-eye"></i>
                            </button>
                            <a href="gold_buy_edit.php?id=${row.id}" class="btn btn-sm btn-outline-primary" title="এডিট / বিস্তারিত">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        document.getElementById('paginationInfo').textContent =
            `মোট ${data.totalRows} টি ক্রয়ের রেকর্ডের মধ্যে ${data.data.length} টি দেখানো হচ্ছে`;
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
    document.getElementById('btnOpenEdit').href = 'gold_buy_edit.php?id=' + id;
    document.getElementById('viewId').textContent = id;
    document.getElementById('viewBody').innerHTML = '<div class="text-center text-muted py-4">লোড হচ্ছে…</div>';
    const modal = new bootstrap.Modal(document.getElementById('viewModal'));
    modal.show();

    try {
        const res  = await fetch('gold_buy_list.php?action=get&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        if (!data.success) {
            document.getElementById('viewBody').innerHTML = `<div class="text-danger">${escHtml(data.message || 'ব্যর্থ হয়েছে।')}</div>`;
            return;
        }
        const b = data.data;

        const itemsHtml = b.items.map((it, idx) => {
            const t = gramsToTraditional(parseFloat(it.weight) || 0);
            return `<tr>
                <td class="text-muted">${idx + 1}</td>
                <td>${t.vori}ভ ${t.ana}আ ${t.roti}র ${t.point}প</td>
                <td>${parseFloat(it.purity).toFixed(2)} ক্যারেট</td>
                <td class="text-end">${fmtBDT(it.price)}</td>
            </tr>`;
        }).join('');

        const due = parseFloat(b.due_amount) || 0;

        const pmtHtml = (!b.payments || b.payments.length === 0)
            ? '<p class="text-muted small mb-0">এখনো কোনো পেমেন্ট করা হয়নি।</p>'
            : b.payments.map(p => `
                <div class="d-flex justify-content-between align-items-center
                            py-1 border-bottom" style="font-size:.82rem;">
                    <span class="text-muted">
                        ${new Date(p.payment_date).toLocaleDateString('bn-BD',
                          {day:'2-digit',month:'short',year:'numeric'})}
                    </span>
                    <span class="fw-semibold text-success">${fmtBDT(p.paid_amount)}</span>
                    <span class="text-muted small">${escHtml(p.received_by ?? '—')}</span>
                    ${p.transaction_ref
                        ? `<span class="badge bg-light text-dark border">#${escHtml(p.transaction_ref)}</span>`
                        : '<span></span>'}
                </div>`).join('');

        document.getElementById('viewBody').innerHTML = `
            <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
                <div>
                    <div class="fw-semibold fs-6">${escHtml(b.customer_name)}</div>
                    <small class="text-muted">${escHtml(b.customer_phone || '')}</small>
                </div>
                <div class="text-end">
                    <small class="text-muted d-block">${fmtDate(b.created_at)}</small>
                    <small class="text-muted">গ্রহীতা: ${escHtml(b.created_by_username || '—')}</small>
                </div>
            </div>

            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:32px;">#</th>
                            <th>সোনার ওজন (ভ-আ-র-প)</th>
                            <th style="width:70px;">সোনার মান</th>
                            <th class="text-end">আইটেমের দাম</th>
                        </tr>
                    </thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
            </div>

            <div class="mb-3">
                <div class="fw-semibold small text-muted mb-1">পেমেন্ট ইতিহাস</div>
                ${pmtHtml}
            </div>

            <table class="table table-sm mb-3 ledger-table">
                <tbody>
                    <tr class="ledger-total">
                        <td class="ledger-label">২৪ ক্যারেট পাকা সোনার দাম (প্রতি ভরি)</td>
                        <td class="ledger-vorp">${fmtBDT(b.pure_gold_price)}</td>
                    </tr>
                    <tr class="ledger-total">
                        <td class="ledger-label">মোট</td>
                        <td class="ledger-vorp">${fmtBDT(b.total_amount)}</td>
                    </tr>
                    <tr class="ledger-paid">
                        <td class="ledger-label">পরিশোধিত</td>
                        <td class="ledger-vorp">${fmtBDT(b.paid_amount)}</td>
                    </tr>
                    <tr class="ledger-due">
                        <td class="ledger-label">বকেয়া</td>
                        <td class="ledger-vorp">${fmtBDT(due)}</td>
                    </tr>
                </tbody>
            </table>
            ${b.note ? `<div class="alert alert-light border mb-0 py-2"><strong>নোট:</strong> ${escHtml(b.note)}</div>` : ''}
        `;
    } catch {
        document.getElementById('viewBody').innerHTML = '<div class="text-danger">নেটওয়ার্ক সমস্যা।</div>';
    }
}

// Set default date range: current month (1st → today)
// NOTE: toISOString() returns UTC which causes a date shift for UTC+6 users
// (e.g. at 3 AM in Dhaka, UTC is still the previous day).
// localDateStr() uses the browser's local clock instead.
(function setDefaultDates() {
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
})();

loadList(1);
</script>

</body>
</html>