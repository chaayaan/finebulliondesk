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
        if ($id <= 0) json_out(['success' => false, 'message' => 'Invalid ID.'], 400);

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
        if (!$buy) json_out(['success' => false, 'message' => 'Record not found.'], 404);

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

    json_out(['success' => false, 'message' => 'Unknown action.'], 400);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gold Buy List — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --fb-green: #0B412A;
    --fb-gold:  #DCAD41;
}
body { background: #f5f6fa; font-family: "Segoe UI", Arial, sans-serif; }

/* ---- page header ---- */
.list-header {
    background: linear-gradient(135deg, var(--fb-green) 0%, #0e5636 100%);
    color: #fff;
    border-radius: 10px;
    padding: 1.25rem 1.5rem;
    position: relative;
}
.list-header h4  { color: #fff; }
.list-header small { color: rgba(255,255,255,0.75); }

/* ---- stat bar (matches image: Weight / Total / Paid / Due) ---- */
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
.stat-weight  { background: var(--fb-green);    color: #fff; }
.stat-total   { background: var(--fb-gold);     color: #1a1a1a; }
.stat-paid    { background: #2e7d32;            color: #fff; }
.stat-due     { background: #c0392b;            color: #fff; }

/* ---- amount badges ---- */
.badge-amount { background: #eaf5ee; color: var(--fb-green); font-weight: 600; font-size: 0.82rem; }
.badge-paid   { background: #e8f5e9; color: #2e7d32;          font-weight: 600; font-size: 0.82rem; }
.badge-due    { background: #fdecea; color: #c0392b;           font-weight: 600; font-size: 0.82rem; }
.badge-due.zero { background: #e8f5e9; color: #2e7d32; }
.badge-weight { background: #f4f9f6; color: var(--fb-green);  font-weight: 600; font-size: 0.82rem;
                border: 1px solid #bcd9c9; }

/* ---- action buttons ---- */
.btn-gold { background: var(--fb-gold); border-color: var(--fb-gold); color: #1a1a1a; font-weight: 600; }
.btn-gold:hover { background: #c99a2f; border-color: #c99a2f; color: #1a1a1a; }
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
.buy-info-cell .info-label { color: #6c757d; }
.buy-info-cell .info-value { font-weight: 600; color: #1a1a1a; }
.buy-info-cell .info-value.red { color: #c0392b; }
.buy-info-cell .info-value.green { color: #2e7d32; }

/* ---- modal ledger ---- */
.ledger-table td { padding: 0.55rem 0.85rem; vertical-align: middle;
                    border-bottom: 1px solid #e9ecef; --bs-table-bg: transparent; }
.ledger-table tr:last-child td { border-bottom: none; }
.ledger-label { font-size: 0.82rem; color: #555; white-space: nowrap; width: 1%; }
.ledger-vorp  { font-weight: 700; font-size: 0.95rem; text-align: right; }
.ledger-total td { background-color: #eaf5ee !important; }
.ledger-total .ledger-label { color: var(--fb-green); font-weight: 600; }
.ledger-total .ledger-vorp  { color: var(--fb-green); }
.ledger-paid td  { background-color: #e8f5e9 !important; }
.ledger-paid .ledger-label  { color: #2e7d32; font-weight: 600; }
.ledger-paid .ledger-vorp   { color: #2e7d32; }
.ledger-due td   { background-color: var(--fb-green) !important; border-bottom: none; }
.ledger-due .ledger-label   { color: rgba(255,255,255,0.85); font-weight: 600; }
.ledger-due .ledger-vorp    { color: #fff; font-size: 1.05rem; }

/* ---- filter bar ---- */
.filter-bar { background: #fff; border-bottom: 1px solid #e9ecef; padding: 0.65rem 1rem;
              display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.filter-bar label { font-size: 0.75rem; color: #6c757d; margin-bottom: 0; }
.filter-bar input[type=date] { font-size: 0.82rem; padding: 0.3rem 0.5rem;
                                border: 1px solid #dee2e6; border-radius: 6px; }

/* ---- mobile ---- */
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

    .card { border-radius: 8px; }
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
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="list-header mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2 position-relative">
        <div>
            <h4 class="mb-0">
                <i class="bi bi-cash-coin me-2"></i>
                <span class="d-none d-md-inline">Gold Buy History</span>
                <span class="d-md-none">GOLD BUY LIST</span>
            </h4>
            <small>FineBullion Desk</small>
        </div>
        <a href="gold_buy.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-plus-lg me-1"></i> <span>New Buy</span>
        </a>
    </div>

    <!-- Stat bar -->
    <div class="stat-bar" id="statBar">
        <div class="stat-cell stat-weight">
            <span class="s-label">Weight</span>
            <span class="s-value" id="statWeight">—</span>
        </div>
        <div class="stat-cell stat-total">
            <span class="s-label">Total Amount:</span>
            <span class="s-value" id="statTotal">—</span>
        </div>
        <div class="stat-cell stat-paid">
            <span class="s-label">Total Paid:</span>
            <span class="s-value" id="statPaid">—</span>
        </div>
        <div class="stat-cell stat-due">
            <span class="s-label">Total Due:</span>
            <span class="s-value" id="statDue">—</span>
        </div>
    </div>

    <!-- Table card -->
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-list-ul me-1"></i> Buy Records</span>
            <div class="input-group" style="max-width:300px;">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control" placeholder="Search customer name, phone…">
                <button class="btn btn-outline-secondary" id="clearSearchBtn"><i class="bi bi-x-lg"></i></button>
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
            <div class="modal-header" style="background: var(--fb-green); color:#fff;">
                <h5 class="modal-title">
                    <i class="bi bi-receipt me-2"></i>Buy #<span id="viewId"></span>
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
        const res  = await fetch('gold_buy_list.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
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
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">No buy records found.</td></tr>';
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
                            <button class="btn btn-sm btn-outline-secondary btn-view" title="Quick view" data-id="${row.id}">
                                <i class="bi bi-eye"></i>
                            </button>
                            <a href="gold_buy_edit.php?id=${row.id}" class="btn btn-sm btn-outline-primary" title="Edit / detail">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }

        document.getElementById('paginationInfo').textContent =
            `Showing ${data.data.length} of ${data.totalRows} buy record(s)`;
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
    document.getElementById('btnOpenEdit').href = 'gold_buy_edit.php?id=' + id;
    document.getElementById('viewId').textContent = id;
    document.getElementById('viewBody').innerHTML = '<div class="text-center text-muted py-4">Loading…</div>';
    const modal = new bootstrap.Modal(document.getElementById('viewModal'));
    modal.show();

    try {
        const res  = await fetch('gold_buy_list.php?action=get&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        if (!data.success) {
            document.getElementById('viewBody').innerHTML = `<div class="text-danger">${escHtml(data.message || 'Failed.')}</div>`;
            return;
        }
        const b = data.data;

        const itemsHtml = b.items.map((it, idx) => {
            const t = gramsToTraditional(parseFloat(it.weight) || 0);
            return `<tr>
                <td class="text-muted">${idx + 1}</td>
                <td>${t.vori}V ${t.ana}A ${t.roti}R ${t.point}P</td>
                <td>${parseFloat(it.purity).toFixed(2)}K</td>
                <td class="text-end">${fmtBDT(it.price)}</td>
            </tr>`;
        }).join('');

        const due = parseFloat(b.due_amount) || 0;

        const pmtHtml = (!b.payments || b.payments.length === 0)
            ? '<p class="text-muted small mb-0">No payments yet.</p>'
            : b.payments.map(p => `
                <div class="d-flex justify-content-between align-items-center
                            py-1 border-bottom" style="font-size:.82rem;">
                    <span class="text-muted">
                        ${new Date(p.payment_date).toLocaleDateString('en-GB',
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
                    <small class="text-muted">By ${escHtml(b.created_by_username || '—')}</small>
                </div>
            </div>

            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:32px;">#</th>
                            <th>Weight (VARP)</th>
                            <th style="width:70px;">Purity</th>
                            <th class="text-end">Item Price</th>
                        </tr>
                    </thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
            </div>

            <div class="mb-3">
                <div class="fw-semibold small text-muted mb-1">Payment History</div>
                ${pmtHtml}
            </div>

            <table class="table table-sm mb-3 ledger-table">
                <tbody>
                    <tr class="ledger-total">
                        <td class="ledger-label">Pure Gold Price (24k / Vori)</td>
                        <td class="ledger-vorp">${fmtBDT(b.pure_gold_price)}</td>
                    </tr>
                    <tr class="ledger-total">
                        <td class="ledger-label">Total Amount</td>
                        <td class="ledger-vorp">${fmtBDT(b.total_amount)}</td>
                    </tr>
                    <tr class="ledger-paid">
                        <td class="ledger-label">Paid Amount</td>
                        <td class="ledger-vorp">${fmtBDT(b.paid_amount)}</td>
                    </tr>
                    <tr class="ledger-due">
                        <td class="ledger-label">Due Amount</td>
                        <td class="ledger-vorp">${fmtBDT(due)}</td>
                    </tr>
                </tbody>
            </table>
            ${b.note ? `<div class="alert alert-light border mb-0 py-2"><strong>Note:</strong> ${escHtml(b.note)}</div>` : ''}
        `;
    } catch {
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