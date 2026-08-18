<?php
/**
 * gold_exchange_list.php
 * FineBullion Desk — Gold Exchange history / listing
 */

require_once __DIR__ . '/auth.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$action = $_GET['action'] ?? $_POST['action'] ?? null;

// -----------------------------------------------------------------------
// Helper
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
            $conditions[] = "DATE(ge.created_at) >= ?";
            $params[] = $dateFrom; $types .= 's';
        }
        if ($dateTo !== '') {
            $conditions[] = "DATE(ge.created_at) <= ?";
            $params[] = $dateTo; $types .= 's';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Totals (for stat bar)
        $totSql = "SELECT
                       COALESCE(SUM(gei.old_gold_weight), 0) AS total_impure_gold,
                       COALESCE(SUM(ge.total_pure_gold),  0) AS total_pure_gold,
                       COALESCE(SUM(ge.loss),             0) AS total_loss,
                       COALESCE(SUM(ge.final_pure_gold),  0) AS net_pure_gold_output
                   FROM gold_exchanges ge
                   JOIN customers c ON c.id = ge.customer_id
                   LEFT JOIN (
                       SELECT gold_exchange_id, SUM(old_gold_weight) AS old_gold_weight
                       FROM gold_exchange_items GROUP BY gold_exchange_id
                   ) gei ON gei.gold_exchange_id = ge.id
                   $where";
        $totStmt = mysqli_prepare($conn, $totSql);
        if ($params) mysqli_stmt_bind_param($totStmt, $types, ...$params);
        mysqli_stmt_execute($totStmt);
        $totRow = mysqli_fetch_assoc(mysqli_stmt_get_result($totStmt));

        $cntSql = "SELECT COUNT(*) FROM gold_exchanges ge
                   JOIN customers c ON c.id = ge.customer_id
                   $where";
        $cntStmt = mysqli_prepare($conn, $cntSql);
        if ($params) mysqli_stmt_bind_param($cntStmt, $types, ...$params);
        mysqli_stmt_execute($cntStmt);
        mysqli_stmt_bind_result($cntStmt, $total);
        mysqli_stmt_fetch($cntStmt);
        mysqli_stmt_close($cntStmt);

        $sql = "SELECT ge.id, ge.customer_id, c.name AS customer_name, c.phone AS customer_phone,
                       ge.total_pure_gold, ge.loss, ge.final_pure_gold, ge.loss_rate_points_per_vori, ge.note,
                       ge.created_at, u.username AS created_by_username
                FROM gold_exchanges ge
                JOIN customers c ON c.id = ge.customer_id
                LEFT JOIN users u ON u.id = ge.created_by
                $where
                ORDER BY ge.created_at DESC
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
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }

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
        if ($id <= 0) json_out(['success' => false, 'message' => 'আইডি সঠিক নয়।'], 400);

        $stmt = mysqli_prepare($conn,
            "SELECT ge.id, ge.customer_id, c.name AS customer_name, c.phone AS customer_phone,
                    ge.total_pure_gold, ge.loss, ge.final_pure_gold, ge.loss_rate_points_per_vori, ge.note,
                    ge.created_at, ge.updated_at, u.username AS created_by_username
             FROM gold_exchanges ge
             JOIN customers c ON c.id = ge.customer_id
             LEFT JOIN users u ON u.id = ge.created_by
             WHERE ge.id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $exchange = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$exchange) json_out(['success' => false, 'message' => 'সোনা বদলের তথ্য পাওয়া যায়নি।'], 404);

        $itemStmt = mysqli_prepare($conn,
            "SELECT id, old_gold_weight, old_gold_purity, pure_gold_weight
             FROM gold_exchange_items
             WHERE gold_exchange_id = ?
             ORDER BY id ASC");
        mysqli_stmt_bind_param($itemStmt, 'i', $id);
        mysqli_stmt_execute($itemStmt);
        $itemsResult = mysqli_stmt_get_result($itemStmt);
        $items = [];
        while ($row = mysqli_fetch_assoc($itemsResult)) {
            $items[] = $row;
        }

        $exchange['items'] = $items;
        json_out(['success' => true, 'data' => $exchange]);
    }

    json_out(['success' => false, 'message' => 'অজানা অ্যাকশন।'], 400);
}
?>
<!DOCTYPE html>
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>সোনা বদলের ইতিহাস — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    /* Brand Foundation */
    --gold-deep: #c9973a;
    --gold-mid: #dcb04a;
    --gold-light: #e9cd7d;
    --ivory: #fbf8f2;
    --bronze-text: #3a2f1a;
    --muted: #9a8f76;
    --hairline: #ecdfb8;

    /* Jewel Tone Financial Status Colors */
    --status-paid-bg: #1b5238;      /* Deep Emerald (Paid / Impure / Loss) */
    --status-paid-light: #eaf4ee;   /* Soft Emerald Tint */
    --status-due-bg: #93292c;       /* Deep Ruby (Due / Pure / Outflow) */
    --status-due-light: #fbeceb;    /* Soft Ruby Tint */
    --status-total-bg: #b88328;     /* Rich Gold (Totals / Net Output) */
    --status-total-light: #fdf6e2;  /* Soft Gold Tint */

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

/* ---- page header bar (flush to viewport top) ----
   Scoped strictly to .list-header and its children so this block is
   completely self-contained and immune to overrides from navbar.php
   or any external stylesheet. */
.list-header,
.list-header.mb-3 {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%) !important;
    color: #ffffff !important;
    border-radius: 0 0 20px 20px !important;
    min-height: 60px !important;
    max-height: 80px !important;
    padding: 0.85rem 1.75rem !important;
    margin-top: 0 !important;
    top: 0;
    width: 100% !important;
    position: relative;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: nowrap !important;
    gap: 1rem !important;
    box-sizing: border-box;
    overflow: hidden;
}
.list-header > div:first-child {
    display: flex;
    flex-direction: column;
    justify-content: center;
    text-align: left;
    min-width: 0;
}
.list-header h4,
.list-header h4 * { color: #ffffff !important; }
.list-header h4 {
    margin-bottom: 0;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.list-header small { color: rgba(255,255,255,0.8) !important; white-space: nowrap; }
.list-header > a.btn { flex-shrink: 0; }

.page-inset { padding: 0 1.5rem; }

/* ---- summary card: Impure / Pure / Loss / Net Output ---- */
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

/* ---- filter bar ---- */
.filter-bar { background: #fff; border-bottom: 1px solid var(--hairline); padding: 0.75rem 1.25rem;
              display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.filter-bar label { font-size: 0.75rem; color: var(--muted); margin-bottom: 0; }
.filter-bar input[type=date] { font-size: 0.82rem; padding: 0.4rem 0.6rem;
                                border: 1.5px solid var(--hairline); border-radius: 10px;
                                color: var(--bronze-text); }
.filter-bar input[type=date]:focus { outline: none; border-color: var(--gold-deep); }

/* ---- table weight badges ---- */
.badge-pure  { background: var(--status-due-light);  color: var(--status-due-bg);  font-weight: 600; font-size: 0.82rem; }
.badge-loss  { background: #fdf1e0; color: #7a5417;   font-weight: 600; font-size: 0.82rem; }
.badge-final { background: var(--status-total-bg); color: #fff; font-weight: 600; font-size: 0.82rem; }

/* ---- action buttons ---- */
.btn-gold {
    background: var(--gold-deep);
    border-color: var(--gold-deep);
    color: #ffffff;
    font-weight: 600;
    border-radius: 999px;
}
.btn-gold:hover { background: var(--gold-deep); border-color: var(--gold-deep); color: #fff; opacity: 0.92; }

/* Primary action buttons (pill, solid gold, white text) */
.btn-fb-primary {
    background: var(--gold-deep);
    border: 1.5px solid var(--gold-deep);
    color: #ffffff;
    font-weight: 700;
    border-radius: 999px;
    padding: 0.5rem 1.1rem;
}
.btn-fb-primary:hover { background: var(--gold-deep); border-color: var(--gold-deep); color: #fff; opacity: 0.92; }

/* Secondary / cancel buttons (pill, white, hairline border) */
.btn-fb-secondary {
    background: #ffffff;
    border: 1.5px solid var(--hairline);
    color: var(--muted);
    font-weight: 600;
    border-radius: 999px;
}
.btn-fb-secondary:hover { background: #fdf7ec; border-color: var(--hairline); color: var(--bronze-text); }

.btn-actions { display: flex; gap: 4px; justify-content: center; }
.btn-actions .btn { border-radius: 999px; }

/* ---- cards ---- */
.card {
    background: #ffffff;
    border: none;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(180, 140, 50, 0.12);
}
.card-header {
    background: #ffffff;
    border-bottom: 1px solid var(--hairline);
    border-radius: 18px 18px 0 0 !important;
    color: var(--bronze-text);
}
.card-footer {
    background: #ffffff;
    border-top: 1px solid var(--hairline);
    border-radius: 0 0 18px 18px !important;
}

/* ---- tables ---- */
table.table thead.table-light th {
    background: var(--ivory) !important;
    color: var(--muted);
    text-transform: uppercase;
    font-size: 0.72rem;
    letter-spacing: 0.04em;
    border-bottom: 1.5px solid var(--hairline);
}
table.table td, table.table th { border-color: var(--hairline); }
table.table-hover tbody tr:hover { background-color: #fdf7ec; }

/* ---- inputs ---- */
.form-control, .input-group-text {
    border: 1.5px solid var(--hairline);
    border-radius: 10px;
    color: var(--bronze-text);
    background: #fff;
}
.form-control:focus { border-color: var(--gold-deep); box-shadow: 0 0 0 0.2rem rgba(201,151,58,0.15); }
.input-group .form-control { border-radius: 0; }
.input-group .input-group-text:first-child { border-radius: 10px 0 0 10px; }
.input-group .btn:last-child { border-radius: 0 10px 10px 0; }

/* ---- modal ---- */
.modal-content { border-radius: 18px; overflow: hidden; border: none; }
.modal-header.fb-modal-header {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%);
    color: #fff;
    border-bottom: none;
}
.modal-header.fb-modal-header .modal-title { color: #fff; }
.modal-header.fb-modal-header .btn-close { filter: brightness(0) invert(1); }

/* ---- pagination ---- */
.pagination .page-link { color: var(--bronze-text); border-color: var(--hairline); }
.pagination .page-item.active .page-link { background: var(--gold-deep); border-color: var(--gold-deep); color: #fff; }
.pagination .page-item.disabled .page-link { color: var(--muted); }

/* ---- alerts ---- */
.alert-fb {
    background: #fdf1e0;
    border: 1px solid #f1cf8e;
    color: #7a5417;
    border-radius: 12px;
}

/* ---- modal summary ledger ---- */
.ledger-table                  { border: 1px solid var(--hairline); border-radius: 12px; overflow: hidden; }
.ledger-table td               { padding: 0.6rem 0.9rem; vertical-align: middle;
                                  border-bottom: 1px solid var(--hairline);
                                  --bs-table-bg: transparent; } /* neutralise Bootstrap override */
.ledger-table tr:last-child td { border-bottom: none; }

.ledger-label { font-size: 0.82rem; color: var(--muted); white-space: nowrap; width: 1%; }
.ledger-rate  { font-weight: 400; color: var(--muted); font-size: 0.78rem; }
.ledger-vorp  { font-weight: 700; font-size: 0.95rem; text-align: right; letter-spacing: 0.01em; }

/* Total Pure Gold row — soft ruby tint */
.ledger-total td               { background-color: var(--status-due-light) !important; }
.ledger-total .ledger-label    { color: var(--status-due-bg); font-weight: 600; }
.ledger-total .ledger-vorp     { color: var(--status-due-bg); }

/* Loss row — soft amber tint */
.ledger-loss td                { background-color: #fdf1e0 !important; }
.ledger-loss .ledger-label     { color: #7a5417; font-weight: 600; }
.ledger-loss .ledger-vorp      { color: #7a5417; }

/* Final Pure Gold row — rich gold, white text */
.ledger-final td               { background-color: var(--status-total-bg) !important; border-bottom: none; }
.ledger-final .ledger-label    { color: rgba(255,255,255,0.88); font-weight: 600; }
.ledger-final .ledger-vorp     { color: #fff; font-size: 1.05rem; }
.ledger-final .ledger-rate     { color: rgba(255,255,255,0.65); }

/* ---------------------------------------------------------------
   Mobile "Exchange Gold Info" combined cell (Total / Loss / Final
   stacked as label–value rows), shown instead of the separate
   Total / Loss / Final / Date / By desktop columns.
--------------------------------------------------------------- */
.exchange-info-cell { min-width: 150px; }
.exchange-info-cell .info-row {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
    font-size: 0.72rem;
    line-height: 1.35;
    white-space: nowrap;
}
.exchange-info-cell .info-label { color: var(--muted); }
.exchange-info-cell .info-value { font-weight: 600; color: var(--bronze-text); }

/* ---------------------------------------------------------------
   Mobile compaction
--------------------------------------------------------------- */
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
    .page-inset { padding: 0 0.75rem; }
    .page-content .container-fluid { padding: 0.6rem 0 1rem; }

    .list-header {
        min-height: 60px !important;
        max-height: 70px !important;
        padding: 0.75rem 1rem !important;
        border-radius: 0 0 16px 16px !important;
        justify-content: space-between !important;
    }
    .list-header h4 { font-size: 0.95rem; margin-bottom: 0; text-align: left; }
    .list-header small { display: block; font-size: 0.68rem; }
    .list-header > a.btn {
        padding: 0.4rem 0.75rem; font-size: 0.75rem;
    }

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

    .filter-bar { padding: 0.6rem 0.75rem; gap: 0.4rem; }
    .filter-bar input[type=date] { font-size: 0.76rem; padding: 0.3rem 0.5rem; }

    .card { border-radius: 14px; }
    .card-header { padding: 0.6rem 0.75rem; border-radius: 14px 14px 0 0 !important; }
    .card-header .fw-semibold { font-size: 0.82rem; }
    .card-header .input-group { max-width: 100% !important; width: 100%; }

    table.table { font-size: 0.78rem; }
    table.table th, table.table td { padding: 0.5rem 0.4rem; }

    .badge-pure, .badge-loss, .badge-final { font-size: 0.7rem; }

    .btn-actions { flex-direction: column; gap: 4px; }
    .btn-actions .btn { padding: 0.25rem 0.5rem; font-size: 0.75rem; }

    .card-footer { padding: 0.6rem 0.75rem; border-radius: 0 0 14px 14px !important; }
    .card-footer small { font-size: 0.7rem; }
    .pagination-sm .page-link { padding: 0.3rem 0.55rem; font-size: 0.75rem; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0">

    <div class="list-header mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4 class="mb-0">
                <i class="bi bi-arrow-left-right me-2"></i>
                <span class="d-none d-md-inline">সোনা বদলের ইতিহাস</span>
                <span class="d-md-none">সোনা বদলের তালিকা</span>
            </h4>
            <small>FineBullion Desk</small>
        </div>
        <a href="gold_exchange.php" class="btn btn-fb-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> <span>নতুন সোনা বদল</span>
        </a>
    </div>

    <div class="page-inset">

    <!-- Summary card -->
    <div class="section-block">
        <div class="sc-card">
            <div class="sc-header">
                <div class="sc-header-left">
                    <div class="sc-icon"><i class="bi bi-arrow-left-right"></i></div>
                    <p class="section-label">সোনা বদল</p>
                </div>
                <i class="bi bi-slash-lg sc-header-icon" style="transform:rotate(90deg) scaleX(0.6);"></i>
            </div>
            <div class="stat-bar" id="statBar">
                <div class="stat-cell">
                    <div class="s-icon"><i class="bi bi-bricks"></i></div>
                    <div class="s-text">
                        <span class="s-label">মোট পুরাতন সোনা</span>
                        <span class="s-value" id="statImpure">—</span>
                    </div>
                </div>
                <div class="stat-cell">
                    <div class="s-icon"><i class="bi bi-gem"></i></div>
                    <div class="s-text">
                        <span class="s-label">মোট পাকা সোনা</span>
                        <span class="s-value" id="statPure">—</span>
                    </div>
                </div>
                <div class="stat-cell">
                    <div class="s-icon"><i class="bi bi-graph-down-arrow"></i></div>
                    <div class="s-text">
                        <span class="s-label">মোট লস</span>
                        <span class="s-value" id="statLoss">—</span>
                    </div>
                </div>
                <div class="stat-cell stat-emphasis">
                    <div class="s-icon"><i class="bi bi-bullseye"></i></div>
                    <div class="s-text">
                        <span class="s-label">চূড়ান্ত পাকা সোনা</span>
                        <span class="s-value" id="statOutput">—</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-list-ul me-1"></i> সোনা বদলসমূহ</span>
            <div class="input-group" style="max-width:300px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control" placeholder="কাস্টমারের নাম, ফোন নম্বর খুঁজুন…">
                <button class="btn btn-fb-secondary" id="clearSearchBtn"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>

        <!-- Date filter bar -->
        <div class="filter-bar">
            <span class="ms-auto d-none d-md-inline" style="font-size:0.8rem;color:var(--muted);">শুরু</span>
            <label class="d-md-none" style="font-size:0.72rem;color:var(--muted);">শুরু</label>
            <input type="date" id="dateFrom">
            <span style="font-size:0.8rem;color:var(--muted);">শেষ</span>
            <input type="date" id="dateTo">
            <button class="btn btn-sm btn-fb-secondary ms-1" id="clearDatesBtn" title="তারিখ মুছুন">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:60px;">#</th>
                            <th>কাস্টমার</th>
                            <th class="d-none d-md-table-cell">মোট পাকা সোনা</th>
                            <th class="d-none d-md-table-cell">লস</th>
                            <th class="d-none d-md-table-cell">চূড়ান্ত পাকা সোনা</th>
                            <th class="d-md-none">সোনা বদলের তথ্য</th>
                            <th class="d-none d-md-table-cell" style="width:130px;">তারিখ</th>
                            <th class="d-none d-md-table-cell" style="width:110px;">এন্ট্রি প্রদানকারী</th>
                            <th style="width:100px;" class="text-center">অ্যাকশন</th>
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
            <nav>
                <ul class="pagination pagination-sm mb-0" id="paginationControls"></ul>
            </nav>
        </div>
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
            <div class="modal-header fb-modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-receipt me-2"></i>সোনা বদল #<span id="viewId"></span>
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
                <a id="btnOpenEdit" href="#" class="btn btn-gold btn-sm">
                    <i class="bi bi-pencil-square me-1"></i>সম্পূর্ণ বিস্তারিত / নোট এডিট করুন
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ----------------------------------------------------------------
// Unit conversion constants
// ----------------------------------------------------------------
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

    if (point >= 10) { point -= 10; roti += 1; }
    if (roti >= 6)   { roti -= 6;   ana  += 1; }
    if (ana  >= 16)  { ana  -= 16;  vori += 1; }

    return { vori, ana, roti, point };
}

/** Full long-form: "2 Vori 3 Ana 1 Roti 5 Point" */
function fmtTrad(grams) {
    const t = gramsToTraditional(parseFloat(grams) || 0);
    return `${t.vori} ভ ${t.ana} আ ${t.roti} র ${t.point} প`;
}

/** Loss stored as grams → recover ceiled point count for display */
function lossPoints(lossGrams) {
    return Math.round(parseFloat(lossGrams) / G_PER_POINT);
}

function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(s.replace(' ', 'T'));
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ----------------------------------------------------------------
// Table list
// ----------------------------------------------------------------
let currentPage   = 1;
let currentSearch = '';
let currentFrom   = '';
let currentTo     = '';
let searchTimer   = null;

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
        const res  = await fetch('gold_exchange_list.php?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger py-4">${escHtml(data.message || 'লোড করতে ব্যর্থ হয়েছে।')}</td></tr>`;
            return;
        }

        // Stat bar
        const tot = data.totals || {};
        document.getElementById('statImpure').textContent = fmtTrad(tot.total_impure_gold      || 0);
        document.getElementById('statOutput').textContent = fmtTrad(tot.net_pure_gold_output    || 0);
        document.getElementById('statLoss').textContent   = fmtTrad(tot.total_loss              || 0);
        document.getElementById('statPure').textContent   = fmtTrad(tot.total_pure_gold          || 0);

        if (data.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">কোনো তথ্য পাওয়া যায়নি।</td></tr>';
        } else {
            tbody.innerHTML = data.data.map(row => {
                const lossRate = row.loss_rate_points_per_vori !== undefined
                                  ? parseFloat(row.loss_rate_points_per_vori) : 1;
                return `
                <tr>
                    <td class="text-muted small">
                        <div>#${row.id}</div>
                        <div class="d-md-none text-muted" style="font-size:0.68rem;">${fmtDate(row.created_at)}</div>
                    </td>
                    <td>
                        <div class="fw-semibold">${escHtml(row.customer_name)}</div>
                        <small class="text-muted">${escHtml(row.customer_phone || '')}</small>
                    </td>
                    <td class="d-none d-md-table-cell"><span class="badge badge-pure">${fmtTrad(row.total_pure_gold)}</span></td>
                    <td class="d-none d-md-table-cell"><span class="badge badge-loss">${lossPoints(row.loss)} পয়েন্ট</span></td>
                    <td class="d-none d-md-table-cell"><span class="badge badge-final">${fmtTrad(row.final_pure_gold)}</span></td>
                    <td class="d-md-none exchange-info-cell">
                        <div class="info-row"><span class="info-label">মোট পাকা সোনা</span><span class="info-value">${fmtTrad(row.total_pure_gold)}</span></div>
                        <div class="info-row"><span class="info-label">লস (${lossRate} প/ভ)</span><span class="info-value">${lossPoints(row.loss)} পয়েন্ট</span></div>
                        <div class="info-row"><span class="info-label">চূড়ান্ত পাকা সোনা</span><span class="info-value">${fmtTrad(row.final_pure_gold)}</span></div>
                    </td>
                    <td class="small d-none d-md-table-cell">${fmtDate(row.created_at)}</td>
                    <td class="small d-none d-md-table-cell">${escHtml(row.created_by_username || '—')}</td>
                    <td>
                        <div class="btn-actions">
                            <button class="btn btn-sm btn-outline-secondary btn-view" title="দ্রুত দেখুন" data-id="${row.id}">
                                <i class="bi bi-eye"></i>
                            </button>
                            <a href="gold_exchange_edit.php?id=${row.id}" class="btn btn-sm btn-outline-primary" title="এডিট / বিস্তারিত">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            `;
            }).join('');
        }

        document.getElementById('paginationInfo').textContent =
            `মোট ${data.totalRows} টি সোনা বদলের মধ্যে ${data.data.length} টি দেখানো হচ্ছে`;
        renderPagination(data.page, data.totalPages);
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">নেটওয়ার্ক ত্রুটি।</td></tr>';
    }
}

function renderPagination(page, totalPages) {
    const el = document.getElementById('paginationControls');
    el.innerHTML = '';
    if (totalPages <= 1) return;

    const mkItem = (label, target, disabled, active) => {
        const li = document.createElement('li');
        li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
        const a = document.createElement('a');
        a.className = 'page-link';
        a.href = '#';
        a.textContent = label;
        if (!disabled && !active) {
            a.addEventListener('click', e => { e.preventDefault(); loadList(target); });
        }
        li.appendChild(a);
        return li;
    };

    el.appendChild(mkItem('«', page - 1, page <= 1, false));
    for (let p = 1; p <= totalPages; p++) {
        el.appendChild(mkItem(String(p), p, false, p === page));
    }
    el.appendChild(mkItem('»', page + 1, page >= totalPages, false));
}

document.getElementById('searchInput').addEventListener('input', function () {
    clearTimeout(searchTimer);
    const val = this.value;
    searchTimer = setTimeout(() => { currentSearch = val.trim(); loadList(1); }, 350);
});

document.getElementById('clearSearchBtn').addEventListener('click', function () {
    document.getElementById('searchInput').value = '';
    currentSearch = '';
    loadList(1);
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

// ----------------------------------------------------------------
// View modal
// ----------------------------------------------------------------
document.getElementById('tableBody').addEventListener('click', async function (e) {
    const btn = e.target.closest('.btn-view');
    if (!btn) return;
    await openView(btn.dataset.id);
});

async function openView(id) {
    // Update "Open Full Detail" link before showing modal
    document.getElementById('btnOpenEdit').href = 'gold_exchange_edit.php?id=' + id;
    document.getElementById('viewId').textContent = id;
    document.getElementById('viewBody').innerHTML = '<div class="text-center text-muted py-4">লোড হচ্ছে…</div>';

    const modal = new bootstrap.Modal(document.getElementById('viewModal'));
    modal.show();

    try {
        const res  = await fetch('gold_exchange_list.php?action=get&id=' + id, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (!data.success) {
            document.getElementById('viewBody').innerHTML =
                `<div class="text-danger">${escHtml(data.message || 'লোড করতে ব্যর্থ হয়েছে।')}</div>`;
            return;
        }

        const ex       = data.data;
        const lossRate = ex.loss_rate_points_per_vori !== undefined
                            ? parseFloat(ex.loss_rate_points_per_vori) : 1;
        const lossPointsVal = lossPoints(ex.loss);   // ceiled points recovered from stored grams

        // Per-item rows — VARP only, no grams
        const itemsHtml = ex.items.map((it, idx) => {
            const karat  = (parseFloat(it.old_gold_purity) / 100) * 24;
            const oldTrad = fmtTrad(it.old_gold_weight);
            const pureTrad = fmtTrad(it.pure_gold_weight);
            return `
                <tr>
                    <td class="text-muted">${idx + 1}</td>
                    <td>${escHtml(oldTrad)}</td>
                    <td>${karat.toFixed(2)} ক্যারেট</td>
                    <td>${escHtml(pureTrad)}</td>
                </tr>`;
        }).join('');

        document.getElementById('viewBody').innerHTML = `
            <!-- Customer + meta -->
            <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
                <div>
                    <div class="fw-semibold fs-6">${escHtml(ex.customer_name)}</div>
                    <small class="text-muted">${escHtml(ex.customer_phone || '')}</small>
                </div>
                <div class="text-end">
                    <small class="text-muted d-block">${fmtDate(ex.created_at)}</small>
                    <small class="text-muted">এন্ট্রি প্রদানকারী: ${escHtml(ex.created_by_username || '—')}</small>
                </div>
            </div>

            <!-- Items table -->
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:32px;">#</th>
                            <th>পুরাতন সোনা (ভরি-আনা-রতি-পয়েন্ট)</th>
                            <th style="width:70px;">ক্যারেট</th>
                            <th>পাকা সোনা (ভরি-আনা-রতি-পয়েন্ট)</th>
                        </tr>
                    </thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
            </div>

            <!-- Summary ledger -->
            <table class="table table-sm mb-3 ledger-table">
                <tbody>
                    <tr class="ledger-total">
                        <td class="ledger-label">মোট পাকা সোনা</td>
                        <td class="ledger-vorp">${escHtml(fmtTrad(ex.total_pure_gold))}</td>
                    </tr>
                    <tr class="ledger-loss">
                        <td class="ledger-label">লস <span class="ledger-rate">(${lossPointsVal} পয়েন্ট @ ${lossRate} প/ভ)</span></td>
                        <td class="ledger-vorp">${escHtml(fmtTrad(ex.loss))}</td>
                    </tr>
                    <tr class="ledger-final">
                        <td class="ledger-label">চূড়ান্ত পাকা সোনা</td>
                        <td class="ledger-vorp">${escHtml(fmtTrad(ex.final_pure_gold))}</td>
                    </tr>
                </tbody>
            </table>

            ${ex.note ? `<div class="alert-fb mb-0 py-2 px-3"><strong>নোট:</strong> ${escHtml(ex.note)}</div>` : ''}
        `;
    } catch (err) {
        document.getElementById('viewBody').innerHTML = '<div class="text-danger">নেটওয়ার্ক ত্রুটি।</div>';
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