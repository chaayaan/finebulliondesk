<?php
/**
 * customer_history.php
 * FineBullion Desk — Full transaction history for one customer
 *
 * Shows combined Buy / Sale / Exchange history for a single customer_id,
 * following the same stat-bar + table pattern as gold_buy_list.php,
 * gold_sale_list.php, and gold_exchange_list.php.
 */

require_once __DIR__ . '/auth.php';

// -----------------------------------------------------------------------
// Conversion helpers (VARP)
// -----------------------------------------------------------------------
define('G_VORI',  11.664);
define('G_ANA',    0.729);
define('G_ROTI',   0.1215);
define('G_POINT',  0.01215);

function grams_to_trad(float $g): array {
    $g = max(0.0, $g);
    $EPS = 1e-9;
    $tv = $g / G_VORI;
    $v  = (int) floor($tv + $EPS);
    $ta = max(0.0, $tv - $v) * 16;
    $a  = (int) floor($ta + $EPS);
    $tr = max(0.0, $ta - $a) * 6;
    $r  = (int) floor($tr + $EPS);
    $p  = (int) round(max(0.0, $tr - $r) * 10);
    if ($p >= 10) { $p -= 10; $r++; }
    if ($r >= 6)  { $r -= 6;  $a++; }
    if ($a >= 16) { $a -= 16; $v++; }
    return ['v' => $v, 'a' => $a, 'r' => $r, 'p' => $p];
}

function fmt_trad(float $g): string {
    $t = grams_to_trad($g);
    return "{$t['v']} V {$t['a']} A {$t['r']} R {$t['p']} P";
}

function fmt_dt(?string $s): string {
    if (!$s) return '—';
    return (new DateTime($s))->format('d M Y, g:i A');
}

function h($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$action     = $_GET['action'] ?? null;
$customerId = (int)($_GET['customer_id'] ?? 0);

// -----------------------------------------------------------------------
// AJAX actions
// -----------------------------------------------------------------------
if ($isAjax || $action !== null) {

    if ($customerId <= 0) {
        json_out(['success' => false, 'message' => 'Invalid customer.'], 400);
    }

    // ---- BUY / SALE / EXCHANGE list (shared shape, table name driven) ----
    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $type = $_GET['type'] ?? '';
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 10;
        $offset  = ($page - 1) * $perPage;

        if ($type === 'buy') {
            $totSql = "SELECT
                           COALESCE(SUM(gbi.weight), 0)       AS total_weight_g,
                           COALESCE(SUM(gb.total_amount), 0)  AS total_amount,
                           COALESCE(SUM(gbp.paid), 0)         AS total_paid,
                           COALESCE(SUM(gb.total_amount - COALESCE(gbp.paid,0)), 0) AS total_due
                       FROM gold_buys gb
                       LEFT JOIN (SELECT gold_buy_id, SUM(weight) weight FROM gold_buy_items GROUP BY gold_buy_id) gbi
                              ON gbi.gold_buy_id = gb.id
                       LEFT JOIN (SELECT gold_buy_id, SUM(paid_amount) paid FROM gold_buy_payments GROUP BY gold_buy_id) gbp
                              ON gbp.gold_buy_id = gb.id
                       WHERE gb.customer_id = ?";
            $totStmt = mysqli_prepare($conn, $totSql);
            mysqli_stmt_bind_param($totStmt, 'i', $customerId);
            mysqli_stmt_execute($totStmt);
            $totals = mysqli_fetch_assoc(mysqli_stmt_get_result($totStmt));

            $cntStmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM gold_buys WHERE customer_id = ?");
            mysqli_stmt_bind_param($cntStmt, 'i', $customerId);
            mysqli_stmt_execute($cntStmt);
            mysqli_stmt_bind_result($cntStmt, $total);
            mysqli_stmt_fetch($cntStmt);
            mysqli_stmt_close($cntStmt);

            $sql = "SELECT gb.id, gb.pure_gold_price, gb.total_amount,
                           COALESCE(gbp.paid, 0) AS paid_amount,
                           (gb.total_amount - COALESCE(gbp.paid, 0)) AS due_amount,
                           COALESCE(gbi.weight, 0) AS total_weight_g,
                           gb.created_at
                    FROM gold_buys gb
                    LEFT JOIN (SELECT gold_buy_id, SUM(weight) weight FROM gold_buy_items GROUP BY gold_buy_id) gbi
                           ON gbi.gold_buy_id = gb.id
                    LEFT JOIN (SELECT gold_buy_id, SUM(paid_amount) paid FROM gold_buy_payments GROUP BY gold_buy_id) gbp
                           ON gbp.gold_buy_id = gb.id
                    WHERE gb.customer_id = ?
                    ORDER BY gb.created_at DESC
                    LIMIT ? OFFSET ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'iii', $customerId, $perPage, $offset);
            mysqli_stmt_execute($stmt);
            $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

            json_out([
                'success' => true, 'data' => $rows, 'totals' => $totals,
                'page' => $page, 'totalRows' => (int)$total,
                'totalPages' => max(1, (int)ceil($total / $perPage)),
            ]);
        }

        if ($type === 'sale') {
            $totSql = "SELECT
                           COALESCE(SUM(gsi.weight), 0)       AS total_weight_g,
                           COALESCE(SUM(gs.total_amount), 0)  AS total_amount,
                           COALESCE(SUM(gsp.paid), 0)         AS total_paid,
                           COALESCE(SUM(gs.total_amount - COALESCE(gsp.paid,0)), 0) AS total_due
                       FROM gold_sales gs
                       LEFT JOIN (SELECT gold_sale_id, SUM(weight) weight FROM gold_sale_items GROUP BY gold_sale_id) gsi
                              ON gsi.gold_sale_id = gs.id
                       LEFT JOIN (SELECT gold_sale_id, SUM(paid_amount) paid FROM gold_sale_payments GROUP BY gold_sale_id) gsp
                              ON gsp.gold_sale_id = gs.id
                       WHERE gs.customer_id = ?";
            $totStmt = mysqli_prepare($conn, $totSql);
            mysqli_stmt_bind_param($totStmt, 'i', $customerId);
            mysqli_stmt_execute($totStmt);
            $totals = mysqli_fetch_assoc(mysqli_stmt_get_result($totStmt));

            $cntStmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM gold_sales WHERE customer_id = ?");
            mysqli_stmt_bind_param($cntStmt, 'i', $customerId);
            mysqli_stmt_execute($cntStmt);
            mysqli_stmt_bind_result($cntStmt, $total);
            mysqli_stmt_fetch($cntStmt);
            mysqli_stmt_close($cntStmt);

            $sql = "SELECT gs.id, gs.pure_gold_price, gs.total_amount,
                           COALESCE(gsp.paid, 0) AS paid_amount,
                           (gs.total_amount - COALESCE(gsp.paid, 0)) AS due_amount,
                           COALESCE(gsi.weight, 0) AS total_weight_g,
                           gs.created_at
                    FROM gold_sales gs
                    LEFT JOIN (SELECT gold_sale_id, SUM(weight) weight FROM gold_sale_items GROUP BY gold_sale_id) gsi
                           ON gsi.gold_sale_id = gs.id
                    LEFT JOIN (SELECT gold_sale_id, SUM(paid_amount) paid FROM gold_sale_payments GROUP BY gold_sale_id) gsp
                           ON gsp.gold_sale_id = gs.id
                    WHERE gs.customer_id = ?
                    ORDER BY gs.created_at DESC
                    LIMIT ? OFFSET ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'iii', $customerId, $perPage, $offset);
            mysqli_stmt_execute($stmt);
            $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

            json_out([
                'success' => true, 'data' => $rows, 'totals' => $totals,
                'page' => $page, 'totalRows' => (int)$total,
                'totalPages' => max(1, (int)ceil($total / $perPage)),
            ]);
        }

        if ($type === 'exchange') {
            $totSql = "SELECT
                           COALESCE(SUM(gei.old_gold_weight), 0) AS total_impure_gold,
                           COALESCE(SUM(ge.total_pure_gold),  0) AS total_pure_gold,
                           COALESCE(SUM(ge.loss),             0) AS total_loss,
                           COALESCE(SUM(ge.final_pure_gold),  0) AS net_pure_gold_output
                       FROM gold_exchanges ge
                       LEFT JOIN (SELECT gold_exchange_id, SUM(old_gold_weight) old_gold_weight
                                  FROM gold_exchange_items GROUP BY gold_exchange_id) gei
                              ON gei.gold_exchange_id = ge.id
                       WHERE ge.customer_id = ?";
            $totStmt = mysqli_prepare($conn, $totSql);
            mysqli_stmt_bind_param($totStmt, 'i', $customerId);
            mysqli_stmt_execute($totStmt);
            $totals = mysqli_fetch_assoc(mysqli_stmt_get_result($totStmt));

            $cntStmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM gold_exchanges WHERE customer_id = ?");
            mysqli_stmt_bind_param($cntStmt, 'i', $customerId);
            mysqli_stmt_execute($cntStmt);
            mysqli_stmt_bind_result($cntStmt, $total);
            mysqli_stmt_fetch($cntStmt);
            mysqli_stmt_close($cntStmt);

            $sql = "SELECT ge.id, ge.total_pure_gold, ge.loss, ge.final_pure_gold,
                           ge.loss_rate_points_per_vori, ge.created_at
                    FROM gold_exchanges ge
                    WHERE ge.customer_id = ?
                    ORDER BY ge.created_at DESC
                    LIMIT ? OFFSET ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'iii', $customerId, $perPage, $offset);
            mysqli_stmt_execute($stmt);
            $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

            json_out([
                'success' => true, 'data' => $rows, 'totals' => $totals,
                'page' => $page, 'totalRows' => (int)$total,
                'totalPages' => max(1, (int)ceil($total / $perPage)),
            ]);
        }

        json_out(['success' => false, 'message' => 'Unknown type.'], 400);
    }

    json_out(['success' => false, 'message' => 'Unknown action.'], 400);
}

// -----------------------------------------------------------------------
// Page load — fetch the customer record
// -----------------------------------------------------------------------
if ($customerId <= 0) {
    header('Location: customers.php');
    exit;
}

$stmt = mysqli_prepare($conn,
    "SELECT c.id, c.name, c.phone, c.address, c.email, c.nid, c.note, c.created_at,
            u.username AS created_by_username
     FROM customers c
     LEFT JOIN users u ON u.id = c.created_by
     WHERE c.id = ?
     LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $customerId);
mysqli_stmt_execute($stmt);
$customer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$customer) {
    header('Location: customers.php');
    exit;
}

// Combined lifetime totals across all three transaction types (for the header stat bar)
$sumSql = "SELECT
    (SELECT COALESCE(SUM(total_amount),0) FROM gold_buys WHERE customer_id = ?)  AS buy_total,
    (SELECT COALESCE(SUM(total_amount),0) FROM gold_sales WHERE customer_id = ?) AS sale_total,
    (SELECT COUNT(*) FROM gold_buys WHERE customer_id = ?)      AS buy_count,
    (SELECT COUNT(*) FROM gold_sales WHERE customer_id = ?)     AS sale_count,
    (SELECT COUNT(*) FROM gold_exchanges WHERE customer_id = ?) AS exchange_count,
    (SELECT COALESCE(SUM(gb.total_amount - COALESCE(gbp.paid,0)),0)
       FROM gold_buys gb
       LEFT JOIN (SELECT gold_buy_id, SUM(paid_amount) paid FROM gold_buy_payments GROUP BY gold_buy_id) gbp
              ON gbp.gold_buy_id = gb.id
      WHERE gb.customer_id = ?) AS buy_due,
    (SELECT COALESCE(SUM(gs.total_amount - COALESCE(gsp.paid,0)),0)
       FROM gold_sales gs
       LEFT JOIN (SELECT gold_sale_id, SUM(paid_amount) paid FROM gold_sale_payments GROUP BY gold_sale_id) gsp
              ON gsp.gold_sale_id = gs.id
      WHERE gs.customer_id = ?) AS sale_due";
$sumStmt = mysqli_prepare($conn, $sumSql);
mysqli_stmt_bind_param($sumStmt, 'iiiiiii', $customerId, $customerId, $customerId, $customerId, $customerId, $customerId, $customerId);
mysqli_stmt_execute($sumStmt);
$summary = mysqli_fetch_assoc(mysqli_stmt_get_result($sumStmt));

$totalTransactions = (int)$summary['buy_count'] + (int)$summary['sale_count'] + (int)$summary['exchange_count'];
$totalDue = (float)$summary['buy_due'] + (float)$summary['sale_due'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customer History — FineBullion Desk</title>
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
.list-header .btn-back {
    color: #fff; border-color: rgba(255,255,255,0.5);
}

/* ---- customer info card ---- */
.detail-card-wrap { border-radius: 10px; overflow: hidden; }
.detail-card { padding: 1rem 1.25rem; }
.detail-label { font-size:.78rem; color:#888; font-weight:500; white-space:nowrap; }
.detail-val   { font-size:.97rem; font-weight:600; color:#1a1a1a; word-break:break-word; }

/* ---- section stat blocks ---- */
.section-block {
    margin-bottom: 1rem;
}
.section-label {
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--fb-green);
    padding: 0.35rem 0.6rem;
    background: #e6f0ea;
    border-left: 4px solid var(--fb-green);
    border-radius: 4px 4px 0 0;
    margin-bottom: 0;
}
.stat-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0;
    border-radius: 0 0 8px 8px;
    overflow: hidden;
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
    white-space: normal;
}
.stat-cell .s-value {
    font-size: 0.95rem;
    font-weight: 800;
    letter-spacing: 0.01em;
    white-space: nowrap;
}
/* Exchange colours — same as gold_exchange_list.php */
.stat-impure  { background: var(--fb-green); color: #fff; }
.stat-output  { background: var(--fb-gold);  color: #1a1a1a; }
.stat-loss    { background: #2e7d32;          color: #fff; }
.stat-pure    { background: #c0392b;          color: #fff; }
/* Buy / Sale colours */
.stat-weight  { background: var(--fb-green); color: #fff; }
.stat-total   { background: var(--fb-gold);  color: #1a1a1a; }
.stat-paid    { background: #2e7d32;          color: #fff; }
.stat-due     { background: #c0392b;          color: #fff; }

/* ---- tabs ---- */
.history-tabs { border-bottom: 2px solid #e9ecef; margin-bottom: 0; }
.history-tabs .nav-link {
    color: #6c757d; font-weight: 600; font-size: 0.88rem;
    border: none; border-bottom: 3px solid transparent;
    padding: 0.75rem 1.1rem; border-radius: 0;
}
.history-tabs .nav-link i { margin-right: 0.35rem; }
.history-tabs .nav-link.active {
    color: var(--fb-green); border-bottom-color: var(--fb-green); background: transparent;
}
.history-tabs .nav-link .badge { font-size: 0.68rem; margin-left: 0.3rem; }

/* ---- amount badges ---- */
.badge-amount { background: #eaf5ee; color: var(--fb-green); font-weight: 600; font-size: 0.82rem; }
.badge-paid   { background: #e8f5e9; color: #2e7d32;          font-weight: 600; font-size: 0.82rem; }
.badge-due    { background: #fdecea; color: #c0392b;           font-weight: 600; font-size: 0.82rem; }
.badge-due.zero { background: #e8f5e9; color: #2e7d32; }
.badge-weight { background: #f4f9f6; color: var(--fb-green);  font-weight: 600; font-size: 0.82rem;
                border: 1px solid #bcd9c9; }

/* ---- mobile info cell ---- */
.hist-info-cell { min-width: 160px; }
.hist-info-cell .info-row {
    display: flex; justify-content: space-between; gap: 0.5rem;
    font-size: 0.72rem; line-height: 1.45; white-space: nowrap;
}
.hist-info-cell .info-label { color: #6c757d; }
.hist-info-cell .info-value { font-weight: 600; color: #1a1a1a; }
.hist-info-cell .info-value.red   { color: #c0392b; }
.hist-info-cell .info-value.green { color: #2e7d32; }

.btn-gold { background: var(--fb-gold); border-color: var(--fb-gold); color: #1a1a1a; font-weight: 600; }
.btn-gold:hover { background: #c99a2f; border-color: #c99a2f; color: #1a1a1a; }

/* ---- mobile ---- */
@media (max-width: 767.98px) {
    .page-content .container-fluid { padding: 0.6rem 0.6rem 1rem; }

    .list-header { padding: 0.65rem 0.85rem; border-radius: 8px; }
    .list-header h4 { font-size: 1rem; margin-bottom: 0; }
    .list-header small { display: none; }

    .detail-card { padding: 0.75rem 0.9rem; }

    .stat-bar { grid-template-columns: repeat(2, 1fr); }
    .section-block { margin-bottom: 0.75rem; }
    .section-label { font-size: 0.7rem; padding: 0.28rem 0.5rem; }
    .stat-cell { padding: 0.5rem 0.55rem; }
    .stat-cell .s-label { font-size: 0.62rem; white-space: normal; }
    .stat-cell .s-value { font-size: 0.78rem; white-space: normal; }

    .history-tabs .nav-link { font-size: 0.78rem; padding: 0.6rem 0.6rem; }
    .history-tabs .nav-link .badge { display: none; }

    .card { border-radius: 8px; }
    table.table { font-size: 0.78rem; }
    table.table th, table.table td { padding: 0.5rem 0.4rem; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="list-header mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <a href="customers.php" class="btn btn-outline-light btn-sm btn-back">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div>
                <h4 class="mb-0">
                    <i class="bi bi-person-lines-fill me-2"></i><?= h($customer['name']) ?>
                </h4>
                <small>Customer History — FineBullion Desk</small>
            </div>
        </div>
    </div>

    <!-- Customer info card -->
    <div class="card shadow-sm mb-4 detail-card-wrap">
        <div class="detail-card">
            <div class="row g-2">
                <div class="col-6 col-md-3">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-val d-block"><?= h($customer['phone'] ?: '—') ?></span>
                </div>
                <div class="col-6 col-md-3">
                    <span class="detail-label">Address:</span>
                    <span class="detail-val d-block" style="font-weight:400;color:#555;">
                        <?= h($customer['address'] ?: '—') ?>
                    </span>
                </div>
                <div class="col-6 col-md-3">
                    <span class="detail-label">Email:</span>
                    <span class="detail-val d-block" style="font-weight:400;color:#555;">
                        <?= h($customer['email'] ?: '—') ?>
                    </span>
                </div>
                <div class="col-6 col-md-3">
                    <span class="detail-label">NID:</span>
                    <span class="detail-val d-block" style="font-weight:400;color:#555;">
                        <?= h($customer['nid'] ?: '—') ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- ── GOLD EXCHANGE stat block ── -->
    <div class="section-block" id="statBlockExchange">
        <div class="section-label"><i class="bi bi-arrow-left-right me-1"></i> GOLD EXCHANGE</div>
        <div class="stat-bar" id="statBarExchange">
            <div class="stat-cell stat-impure">
                <span class="s-label">Total Impure Gold</span>
                <span class="s-value" id="statExImpure">—</span>
            </div>
            <div class="stat-cell stat-pure">
                <span class="s-label">Total Pure Gold</span>
                <span class="s-value" id="statExPure">—</span>
            </div>
            <div class="stat-cell stat-loss">
                <span class="s-label">Total Loss</span>
                <span class="s-value" id="statExLoss">—</span>
            </div>
            <div class="stat-cell stat-output">
                <span class="s-label">Net Pure Gold Output</span>
                <span class="s-value" id="statExNet">—</span>
            </div>
        </div>
    </div>

    <!-- ── GOLD BUY stat block ── -->
    <div class="section-block" id="statBlockBuy">
        <div class="section-label"><i class="bi bi-cart me-1"></i> GOLD BUY</div>
        <div class="stat-bar" id="statBarBuy">
            <div class="stat-cell stat-weight">
                <span class="s-label">Weight</span>
                <span class="s-value" id="statBuyWeight">—</span>
            </div>
            <div class="stat-cell stat-total">
                <span class="s-label">Total Amount:</span>
                <span class="s-value" id="statBuyTotal">—</span>
            </div>
            <div class="stat-cell stat-paid">
                <span class="s-label">Total Paid:</span>
                <span class="s-value" id="statBuyPaid">—</span>
            </div>
            <div class="stat-cell stat-due">
                <span class="s-label">Total Due:</span>
                <span class="s-value" id="statBuyDue">—</span>
            </div>
        </div>
    </div>

    <!-- ── GOLD SALE stat block ── -->
    <div class="section-block" id="statBlockSale">
        <div class="section-label"><i class="bi bi-cash-coin me-1"></i> GOLD SALE</div>
        <div class="stat-bar" id="statBarSale">
            <div class="stat-cell stat-weight">
                <span class="s-label">Weight</span>
                <span class="s-value" id="statSaleWeight">—</span>
            </div>
            <div class="stat-cell stat-total">
                <span class="s-label">Total Amount:</span>
                <span class="s-value" id="statSaleTotal">—</span>
            </div>
            <div class="stat-cell stat-paid">
                <span class="s-label">Total Paid:</span>
                <span class="s-value" id="statSalePaid">—</span>
            </div>
            <div class="stat-cell stat-due">
                <span class="s-label">Total Due:</span>
                <span class="s-value" id="statSaleDue">—</span>
            </div>
        </div>
    </div>

    <!-- Tabbed history -->
    <div class="card shadow-sm">
        <ul class="nav history-tabs px-2 pt-1" id="historyTabs">
            <li class="nav-item">
                <button class="nav-link active" data-type="buy" type="button">
                    <i class="bi bi-cart"></i>Buy <span class="badge bg-light text-dark border"><?= (int)$summary['buy_count'] ?></span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-type="sale" type="button">
                    <i class="bi bi-cash-coin"></i>Sale <span class="badge bg-light text-dark border"><?= (int)$summary['sale_count'] ?></span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-type="exchange" type="button">
                    <i class="bi bi-arrow-left-right"></i>Exchange <span class="badge bg-light text-dark border"><?= (int)$summary['exchange_count'] ?></span>
                </button>
            </li>
        </ul>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light" id="tableHead"></thead>
                    <tbody id="tableBody">
                        <tr><td class="text-center text-muted py-4">Loading…</td></tr>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CUSTOMER_ID = <?= (int)$customerId ?>;

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
let currentType = 'buy';
let currentPage = 1;

const HEAD = {
    buy: `<tr>
        <th style="width:60px;">#</th>
        <th class="d-none d-md-table-cell">Weight</th>
        <th class="d-none d-md-table-cell">Total Amount</th>
        <th class="d-none d-md-table-cell">Paid</th>
        <th class="d-none d-md-table-cell">Due</th>
        <th class="d-md-none">Buy Info</th>
        <th class="d-none d-md-table-cell" style="width:140px;">Date</th>
        <th style="width:70px;" class="text-center">Action</th>
    </tr>`,
    sale: `<tr>
        <th style="width:60px;">#</th>
        <th class="d-none d-md-table-cell">Weight</th>
        <th class="d-none d-md-table-cell">Total Amount</th>
        <th class="d-none d-md-table-cell">Paid</th>
        <th class="d-none d-md-table-cell">Due</th>
        <th class="d-md-none">Sale Info</th>
        <th class="d-none d-md-table-cell" style="width:140px;">Date</th>
        <th style="width:70px;" class="text-center">Action</th>
    </tr>`,
    exchange: `<tr>
        <th style="width:60px;">#</th>
        <th class="d-none d-md-table-cell">Pure Gold</th>
        <th class="d-none d-md-table-cell">Loss</th>
        <th class="d-none d-md-table-cell">Final Pure Gold</th>
        <th class="d-md-none">Exchange Info</th>
        <th class="d-none d-md-table-cell" style="width:140px;">Date</th>
        <th style="width:70px;" class="text-center">Action</th>
    </tr>`,
};

// ── Load all three stat bars via AJAX ──
async function loadAllStatBars() {
    // Buy stats
    try {
        const r = await fetch(`customer_history.php?action=list&type=buy&page=1&customer_id=${CUSTOMER_ID}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const d = await r.json();
        if (d.success && d.totals) {
            document.getElementById('statBuyWeight').textContent = fmtTrad(d.totals.total_weight_g || 0);
            document.getElementById('statBuyTotal').textContent  = fmtBDT(d.totals.total_amount || 0);
            document.getElementById('statBuyPaid').textContent   = fmtBDT(d.totals.total_paid || 0);
            document.getElementById('statBuyDue').textContent    = fmtBDT(d.totals.total_due || 0);
        }
    } catch(e) {}

    // Sale stats
    try {
        const r = await fetch(`customer_history.php?action=list&type=sale&page=1&customer_id=${CUSTOMER_ID}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const d = await r.json();
        if (d.success && d.totals) {
            document.getElementById('statSaleWeight').textContent = fmtTrad(d.totals.total_weight_g || 0);
            document.getElementById('statSaleTotal').textContent  = fmtBDT(d.totals.total_amount || 0);
            document.getElementById('statSalePaid').textContent   = fmtBDT(d.totals.total_paid || 0);
            document.getElementById('statSaleDue').textContent    = fmtBDT(d.totals.total_due || 0);
        }
    } catch(e) {}

    // Exchange stats
    try {
        const r = await fetch(`customer_history.php?action=list&type=exchange&page=1&customer_id=${CUSTOMER_ID}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const d = await r.json();
        if (d.success && d.totals) {
            document.getElementById('statExImpure').textContent = fmtTrad(d.totals.total_impure_gold      || 0);
            document.getElementById('statExNet').textContent    = fmtTrad(d.totals.net_pure_gold_output   || 0);
            document.getElementById('statExLoss').textContent   = fmtTrad(d.totals.total_loss             || 0);
            document.getElementById('statExPure').textContent   = fmtTrad(d.totals.total_pure_gold        || 0);
        }
    } catch(e) {}
}

function rowBuySale(row, type) {
    const due      = parseFloat(row.due_amount) || 0;
    const dueClass = due <= 0 ? 'zero' : '';
    const editPage = type === 'buy' ? 'gold_buy_edit.php' : 'gold_sale_edit.php';
    return `
    <tr>
        <td class="text-muted small">
            <div>#${row.id}</div>
            <div class="d-md-none" style="font-size:0.68rem;color:#999;">${fmtDate(row.created_at)}</div>
        </td>
        <td class="d-none d-md-table-cell"><span class="badge badge-weight">${fmtTrad(row.total_weight_g)}</span></td>
        <td class="d-none d-md-table-cell"><span class="badge badge-amount">${fmtBDT(row.total_amount)}</span></td>
        <td class="d-none d-md-table-cell"><span class="badge badge-paid">${fmtBDT(row.paid_amount)}</span></td>
        <td class="d-none d-md-table-cell"><span class="badge badge-due ${dueClass}">${fmtBDT(due)}</span></td>
        <td class="d-md-none hist-info-cell">
            <div class="info-row"><span class="info-label">Weight</span><span class="info-value">${fmtTrad(row.total_weight_g)}</span></div>
            <div class="info-row"><span class="info-label">Total</span><span class="info-value">${fmtBDT(row.total_amount)}</span></div>
            <div class="info-row"><span class="info-label">Paid</span><span class="info-value green">${fmtBDT(row.paid_amount)}</span></div>
            <div class="info-row"><span class="info-label">Due</span><span class="info-value ${due <= 0 ? 'green' : 'red'}">${fmtBDT(due)}</span></div>
        </td>
        <td class="d-none d-md-table-cell text-muted small">${fmtDate(row.created_at)}</td>
        <td class="text-center">
            <a href="${editPage}?id=${row.id}" class="btn btn-sm btn-outline-secondary" title="View / Edit">
                <i class="bi bi-eye"></i>
            </a>
        </td>
    </tr>`;
}

function rowExchange(row) {
    const lossRate = row.loss_rate_points_per_vori !== undefined ? row.loss_rate_points_per_vori : 1;
    return `
    <tr>
        <td class="text-muted small">
            <div>#${row.id}</div>
            <div class="d-md-none" style="font-size:0.68rem;color:#999;">${fmtDate(row.created_at)}</div>
        </td>
        <td class="d-none d-md-table-cell"><span class="badge badge-weight">${fmtTrad(row.total_pure_gold)}</span></td>
        <td class="d-none d-md-table-cell"><span class="badge badge-due">${fmtTrad(row.loss)}</span></td>
        <td class="d-none d-md-table-cell"><span class="badge badge-paid">${fmtTrad(row.final_pure_gold)}</span></td>
        <td class="d-md-none hist-info-cell">
            <div class="info-row"><span class="info-label">Total Pure Gold</span><span class="info-value">${fmtTrad(row.total_pure_gold)}</span></div>
            <div class="info-row"><span class="info-label">Loss (${lossRate} Pt/V)</span><span class="info-value red">${fmtTrad(row.loss)}</span></div>
            <div class="info-row"><span class="info-label">Final Pure Gold</span><span class="info-value green">${fmtTrad(row.final_pure_gold)}</span></div>
        </td>
        <td class="d-none d-md-table-cell text-muted small">${fmtDate(row.created_at)}</td>
        <td class="text-center">
            <a href="gold_exchange_edit.php?id=${row.id}" class="btn btn-sm btn-outline-secondary" title="View / Edit">
                <i class="bi bi-eye"></i>
            </a>
        </td>
    </tr>`;
}

async function loadHistory(type, page = 1) {
    currentType = type;
    currentPage = page;

    document.getElementById('tableHead').innerHTML = HEAD[type];
    const tbody = document.getElementById('tableBody');
    const colspan = type === 'exchange' ? 6 : 7;
    tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-muted py-4">Loading…</td></tr>`;

    try {
        const params = new URLSearchParams({ action: 'list', type, page, customer_id: CUSTOMER_ID });
        const res  = await fetch('customer_history.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-danger py-4">${escHtml(data.message || 'Failed to load.')}</td></tr>`;
            return;
        }

        if (data.data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-muted py-4">No ${escHtml(type)} records found.</td></tr>`;
        } else if (type === 'exchange') {
            tbody.innerHTML = data.data.map(rowExchange).join('');
        } else {
            tbody.innerHTML = data.data.map(r => rowBuySale(r, type)).join('');
        }

        // Pagination
        const info = document.getElementById('paginationInfo');
        const start = data.totalRows === 0 ? 0 : (data.page - 1) * 10 + 1;
        const end   = Math.min(data.page * 10, data.totalRows);
        info.textContent = `Showing ${start}–${end} of ${data.totalRows}`;

        const controls = document.getElementById('paginationControls');
        controls.innerHTML = '';
        if (data.totalPages > 1) {
            for (let p = 1; p <= data.totalPages; p++) {
                const li = document.createElement('li');
                li.className = 'page-item' + (p === data.page ? ' active' : '');
                li.innerHTML = `<a class="page-link" href="#">${p}</a>`;
                li.addEventListener('click', e => { e.preventDefault(); loadHistory(type, p); });
                controls.appendChild(li);
            }
        }
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-danger py-4">Network error.</td></tr>`;
    }
}

document.querySelectorAll('#historyTabs .nav-link').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('#historyTabs .nav-link').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        loadHistory(this.dataset.type, 1);
    });
});

loadHistory('buy', 1);
loadAllStatBars();
</script>
</body>
</html>