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
        $perPage  = 20;
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
        if ($id <= 0) json_out(['success' => false, 'message' => 'Invalid ID.'], 400);

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

        if (!$exchange) json_out(['success' => false, 'message' => 'Exchange not found.'], 404);

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

    json_out(['success' => false, 'message' => 'Unknown action.'], 400);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gold Exchange History — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --fb-green: #0B412A;
    --fb-gold:  #DCAD41;
}
body { background: #f5f6fa; font-family: "Segoe UI", Arial, sans-serif; }

/* ---- page header bar ---- */
.list-header {
    background: linear-gradient(135deg, var(--fb-green) 0%, #0e5636 100%);
    color: #fff;
    border-radius: 10px;
    padding: 1.25rem 1.5rem;
    position: relative;
}
.list-header h4 { color: #fff; }
.list-header small { color: rgba(255,255,255,0.75); }

/* ---- stat bar: Impure / Net Output / Loss / Pure Gold ---- */
.stat-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
}
.stat-cell {
    padding: 0.65rem 0.8rem;
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}
.stat-cell .s-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    opacity: 0.92;
    white-space: nowrap;
}
.stat-cell .s-value {
    font-size: 0.95rem;
    font-weight: 800;
    letter-spacing: 0.01em;
    white-space: nowrap;
}
.stat-impure { background: var(--fb-green); color: #fff; }
.stat-output { background: var(--fb-gold);  color: #1a1a1a; }
.stat-loss   { background: #2e7d32;         color: #fff; }
.stat-pure   { background: #c0392b;         color: #fff; }

/* ---- filter bar ---- */
.filter-bar { background: #fff; border-bottom: 1px solid #e9ecef; padding: 0.65rem 1rem;
              display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.filter-bar label { font-size: 0.75rem; color: #6c757d; margin-bottom: 0; }
.filter-bar input[type=date] { font-size: 0.82rem; padding: 0.3rem 0.5rem;
                                border: 1px solid #dee2e6; border-radius: 6px; }


/* ---- table weight badges ---- */
.badge-pure  { background: #eaf5ee; color: var(--fb-green); font-weight: 600; font-size: 0.82rem; }
.badge-loss  { background: #fdf1e0; color: #96660c;         font-weight: 600; font-size: 0.82rem; }
.badge-final { background: var(--fb-green); color: #fff;    font-weight: 600; font-size: 0.82rem; }

/* ---- action buttons ---- */
.btn-gold { background: var(--fb-gold); border-color: var(--fb-gold); color: #1a1a1a; font-weight: 600; }
.btn-gold:hover { background: #c99a2f; border-color: #c99a2f; color: #1a1a1a; }

.btn-actions { display: flex; gap: 4px; justify-content: center; }

/* ---- modal summary ledger ---- */
.ledger-table                  { border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden; }
.ledger-table td               { padding: 0.55rem 0.85rem; vertical-align: middle;
                                  border-bottom: 1px solid #e9ecef;
                                  --bs-table-bg: transparent; } /* neutralise Bootstrap override */
.ledger-table tr:last-child td { border-bottom: none; }

.ledger-label { font-size: 0.82rem; color: #555; white-space: nowrap; width: 1%; }
.ledger-rate  { font-weight: 400; color: #888; font-size: 0.78rem; }
.ledger-vorp  { font-weight: 700; font-size: 0.95rem; text-align: right; letter-spacing: 0.01em; }

/* Total row — light green */
.ledger-total td               { background-color: #eaf5ee !important; }
.ledger-total .ledger-label    { color: var(--fb-green); font-weight: 600; }
.ledger-total .ledger-vorp     { color: var(--fb-green); }

/* Loss row — light amber */
.ledger-loss td                { background-color: #fdf6ec !important; }
.ledger-loss .ledger-label     { color: #8a5e0a; font-weight: 600; }
.ledger-loss .ledger-vorp      { color: #96660c; }

/* Final row — dark green, white text */
.ledger-final td               { background-color: var(--fb-green) !important; border-bottom: none; }
.ledger-final .ledger-label    { color: rgba(255,255,255,0.85); font-weight: 600; }
.ledger-final .ledger-vorp     { color: #fff; font-size: 1.05rem; }
.ledger-final .ledger-rate     { color: rgba(255,255,255,0.6); }

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
.exchange-info-cell .info-label { color: #6c757d; }
.exchange-info-cell .info-value { font-weight: 600; color: #1a1a1a; }

/* ---------------------------------------------------------------
   Mobile compaction
--------------------------------------------------------------- */
@media (max-width: 767.98px) {
    .page-content .container-fluid { padding: 0.6rem 0.6rem 1rem; }

    .list-header { padding: 0.65rem 0.85rem; border-radius: 8px; justify-content: center !important; }
    .list-header h4 { font-size: 1rem; margin-bottom: 0; text-align: center; }
    .list-header h4 i { display: none; }
    .list-header small { display: none; }
    .list-header > a.btn {
        position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%);
        padding: 0.3rem 0.5rem; font-size: 0.75rem;
    }
    .list-header > a.btn span { display: none; }

    .stat-bar { grid-template-columns: repeat(2, 1fr); margin-bottom: 0.75rem; }
    .stat-cell { padding: 0.5rem 0.55rem; }
    .stat-cell .s-label { font-size: 0.62rem; white-space: normal; }
    .stat-cell .s-value { font-size: 0.78rem; white-space: normal; }

    /* Mobile pairing: Impure+Loss on row 1, Output+Pure on row 2 */
    .stat-impure { order: 1; }
    .stat-loss   { order: 2; }
    .stat-output { order: 3; }
    .stat-pure   { order: 4; }

    .filter-bar { padding: 0.5rem 0.6rem; gap: 0.4rem; }
    .filter-bar input[type=date] { font-size: 0.76rem; padding: 0.25rem 0.4rem; }

    .card { border-radius: 8px; }
    .card-header { padding: 0.5rem 0.6rem; }
    .card-header .fw-semibold { font-size: 0.82rem; }
    .card-header .input-group { max-width: 100% !important; width: 100%; }

    table.table { font-size: 0.78rem; }
    table.table th, table.table td { padding: 0.5rem 0.4rem; }

    .badge-pure, .badge-loss, .badge-final { font-size: 0.7rem; }

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
<div class="container-fluid py-4">

    <div class="list-header mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4 class="mb-0">
                <i class="bi bi-arrow-left-right me-2"></i>
                <span class="d-none d-md-inline">Gold Exchange History</span>
                <span class="d-md-none">Gold Exchange List</span>
            </h4>
            <small>FineBullion Desk</small>
        </div>
        <a href="gold_exchange.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-plus-lg me-1"></i> <span>New Exchange</span>
        </a>
    </div>

    <!-- Stat bar -->
    <div class="stat-bar" id="statBar">
        <div class="stat-cell stat-impure">
            <span class="s-label">Total Impure Gold</span>
            <span class="s-value" id="statImpure">—</span>
        </div>
        <div class="stat-cell stat-pure">
            <span class="s-label">Total Pure Gold</span>
            <span class="s-value" id="statPure">—</span>
        </div>
        <div class="stat-cell stat-loss">
            <span class="s-label">Total Loss</span>
            <span class="s-value" id="statLoss">—</span>
        </div>
        <div class="stat-cell stat-output">
            <span class="s-label">Net Pure Gold Output</span>
            <span class="s-value" id="statOutput">—</span>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-list-ul me-1"></i> Exchanges</span>
            <div class="input-group" style="max-width:300px;">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control" placeholder="Search customer name, phone…">
                <button class="btn btn-outline-secondary" id="clearSearchBtn"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>

        <!-- Date filter bar -->
        <div class="filter-bar">
            <span class="ms-auto d-none d-md-inline" style="font-size:0.8rem;color:#6c757d;">From</span>
            <label class="d-md-none" style="font-size:0.72rem;color:#6c757d;">From</label>
            <input type="date" id="dateFrom">
            <span style="font-size:0.8rem;color:#6c757d;">To</span>
            <input type="date" id="dateTo">
            <button class="btn btn-sm btn-outline-secondary ms-1" id="clearDatesBtn" title="Clear dates">
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
                            <th class="d-none d-md-table-cell">Total Pure Gold</th>
                            <th class="d-none d-md-table-cell">Loss</th>
                            <th class="d-none d-md-table-cell">Final Pure Gold</th>
                            <th class="d-md-none">Exchange Gold Info</th>
                            <th class="d-none d-md-table-cell" style="width:130px;">Date</th>
                            <th class="d-none d-md-table-cell" style="width:110px;">By</th>
                            <th style="width:100px;" class="text-center">Action</th>
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
            <nav>
                <ul class="pagination pagination-sm mb-0" id="paginationControls"></ul>
            </nav>
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
            <div class="modal-header" style="background: var(--fb-green); color:#fff;">
                <h5 class="modal-title">
                    <i class="bi bi-receipt me-2"></i>Exchange #<span id="viewId"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewBody">
                <div class="text-center text-muted py-4">Loading…</div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-1"></i>Close
                </button>
                <a id="btnOpenEdit" href="#" class="btn btn-gold btn-sm">
                    <i class="bi bi-pencil-square me-1"></i>Open Full Detail / Edit Note
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
    return `${t.vori} V ${t.ana} A ${t.roti} R ${t.point} P`;
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
    tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Loading…</td></tr>';

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
            tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger py-4">${escHtml(data.message || 'Failed to load.')}</td></tr>`;
            return;
        }

        // Stat bar
        const tot = data.totals || {};
        document.getElementById('statImpure').textContent = fmtTrad(tot.total_impure_gold      || 0);
        document.getElementById('statOutput').textContent = fmtTrad(tot.net_pure_gold_output    || 0);
        document.getElementById('statLoss').textContent   = fmtTrad(tot.total_loss              || 0);
        document.getElementById('statPure').textContent   = fmtTrad(tot.total_pure_gold          || 0);

        if (data.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">No exchanges found.</td></tr>';
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
                    <td class="d-none d-md-table-cell"><span class="badge badge-loss">${lossPoints(row.loss)} Point</span></td>
                    <td class="d-none d-md-table-cell"><span class="badge badge-final">${fmtTrad(row.final_pure_gold)}</span></td>
                    <td class="d-md-none exchange-info-cell">
                        <div class="info-row"><span class="info-label">Total Pure Gold</span><span class="info-value">${fmtTrad(row.total_pure_gold)}</span></div>
                        <div class="info-row"><span class="info-label">LossPoint (${lossRate} Pt/V)</span><span class="info-value">${lossPoints(row.loss)} P</span></div>
                        <div class="info-row"><span class="info-label">Final Pure Gold</span><span class="info-value">${fmtTrad(row.final_pure_gold)}</span></div>
                    </td>
                    <td class="small d-none d-md-table-cell">${fmtDate(row.created_at)}</td>
                    <td class="small d-none d-md-table-cell">${escHtml(row.created_by_username || '—')}</td>
                    <td>
                        <div class="btn-actions">
                            <button class="btn btn-sm btn-outline-secondary btn-view" title="Quick view" data-id="${row.id}">
                                <i class="bi bi-eye"></i>
                            </button>
                            <a href="gold_exchange_edit.php?id=${row.id}" class="btn btn-sm btn-outline-primary" title="Edit / detail">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            `;
            }).join('');
        }

        document.getElementById('paginationInfo').textContent =
            `Showing ${data.data.length} of ${data.totalRows} exchange(s)`;
        renderPagination(data.page, data.totalPages);
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">Network error.</td></tr>';
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
    document.getElementById('viewBody').innerHTML = '<div class="text-center text-muted py-4">Loading…</div>';

    const modal = new bootstrap.Modal(document.getElementById('viewModal'));
    modal.show();

    try {
        const res  = await fetch('gold_exchange_list.php?action=get&id=' + id, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (!data.success) {
            document.getElementById('viewBody').innerHTML =
                `<div class="text-danger">${escHtml(data.message || 'Failed to load.')}</div>`;
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
                    <td>${karat.toFixed(2)}K</td>
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
                    <small class="text-muted">By ${escHtml(ex.created_by_username || '—')}</small>
                </div>
            </div>

            <!-- Items table -->
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:32px;">#</th>
                            <th>Old Gold (VARP)</th>
                            <th style="width:70px;">Karat</th>
                            <th>Pure Gold (VARP)</th>
                        </tr>
                    </thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
            </div>

            <!-- Summary ledger -->
            <table class="table table-sm mb-3 ledger-table">
                <tbody>
                    <tr class="ledger-total">
                        <td class="ledger-label">Total Pure Gold</td>
                        <td class="ledger-vorp">${escHtml(fmtTrad(ex.total_pure_gold))}</td>
                    </tr>
                    <tr class="ledger-loss">
                        <td class="ledger-label">Loss <span class="ledger-rate">(${lossPointsVal} Point @ ${lossRate} Pt/Vori)</span></td>
                        <td class="ledger-vorp">${escHtml(fmtTrad(ex.loss))}</td>
                    </tr>
                    <tr class="ledger-final">
                        <td class="ledger-label">Final Pure Gold</td>
                        <td class="ledger-vorp">${escHtml(fmtTrad(ex.final_pure_gold))}</td>
                    </tr>
                </tbody>
            </table>

            ${ex.note ? `<div class="alert alert-light border mb-0 py-2"><strong>Note:</strong> ${escHtml(ex.note)}</div>` : ''}
        `;
    } catch (err) {
        document.getElementById('viewBody').innerHTML = '<div class="text-danger">Network error.</div>';
    }
}

// Set default date range: past 30 days → today
(function setDefaultDates() {
    const today = new Date();
    const from  = new Date(); from.setDate(today.getDate() - 30);
    const fmt   = d => d.toISOString().slice(0, 10);
    document.getElementById('dateFrom').value = fmt(from);
    document.getElementById('dateTo').value   = fmt(today);
    currentFrom = fmt(from);
    currentTo   = fmt(today);
})();

loadList(1);
</script>

</body>
</html>