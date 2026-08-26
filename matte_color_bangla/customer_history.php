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
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>গ্রাহকের ইতিহাস — ফাইনবুলিয়ন ডেস্ক</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
    --shadow: 0 2px 8px rgba(47, 65, 86, 0.08);
}

body {
    background-color: var(--bg-app);
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--text-primary);
}

/* Page Layout Container */
.page-container {
    padding: 0 1.5rem 1.5rem 1.5rem;
}

/* Page Header Structure */
.page-header {
    background: var(--navy);
    color: var(--text-on-navy);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    padding: 1rem 1.75rem;
    border-radius: 0 0 16px 16px;
    margin-bottom: 1.25rem;
}
.page-header .header-left { display: flex; flex-direction: column; gap: 0.2rem; min-width: 0; }
.page-header .header-right { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }

.page-header h1, .page-header h4 { color: var(--text-on-navy); margin: 0; font-weight: 700; font-size: 22px; }
.page-header small, .page-header .subtitle,
.page-header .header-meta { color: rgba(255, 255, 255, 0.78); font-size: 13px; font-weight: 500; }

.header-action-btn {
    background: var(--navy);
    border: 1.5px solid #fff;
    color: #fff;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    padding: 0.45rem 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    white-space: nowrap;
    text-decoration: none;
    transition: background-color 0.15s, border-color 0.15s, color 0.15s;
}
.header-action-btn:hover, .header-action-btn:focus {
    background: var(--teal);
    border-color: #fff;
    color: #fff;
}

/* Cards & Containers */
.card, .sc-card {
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 14px;
    box-shadow: var(--shadow);
    padding: 1rem 1.1rem;
}

.detail-card-wrap {
    margin-bottom: 1.25rem;
}
.detail-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-secondary);
    display: block;
    margin-bottom: 0.15rem;
}
.detail-val {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    word-break: break-word;
}

/* Section Blocks & Stat Cards */
.section-block {
    margin-bottom: 1rem;
}
.sc-card {
    padding: 0;
    overflow: hidden;
}
.sc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    background: var(--bg-card);
    border-bottom: 1px solid var(--border-default);
}
.sc-header-left {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.sc-icon {
    width: 34px;
    height: 34px;
    min-width: 34px;
    border-radius: 10px;
    background: var(--sky);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    color: var(--navy);
}
.section-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-secondary);
    margin: 0;
}

.stat-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
}
.stat-cell {
    padding: 0.85rem 1rem;
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 0.6rem;
    border-right: 1px solid var(--border-default);
    background: var(--bg-card);
}
.stat-cell:last-child { border-right: none; }

.stat-cell .s-icon {
    width: 30px;
    height: 30px;
    min-width: 30px;
    border-radius: 8px;
    background: var(--bg-hover);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.82rem;
    color: var(--teal);
}
.stat-cell .s-text {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
}
.stat-cell .s-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.stat-cell .s-value {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
}

/* Stat emphasis & status highlights */
.stat-cell.stat-emphasis {
    background: rgba(200, 217, 230, 0.25);
}
.stat-cell.stat-emphasis .s-value { color: var(--navy); }
.stat-cell.stat-emphasis .s-icon { background: var(--sky); color: var(--navy); }

.stat-cell.stat-due {
    background: #FBECEC;
}
.stat-cell.stat-due .s-value { color: var(--danger); }
.stat-cell.stat-due .s-icon { background: #FFFFFF; color: var(--danger); }

/* Navigation History Tabs */
.history-tabs-card {
    padding: 0;
    overflow: hidden;
}
.history-tabs {
    border-bottom: 1.5px solid var(--border-default);
    background: var(--bg-card);
    padding: 0.5rem 0.75rem 0 0.75rem;
    margin-bottom: 0;
}
.history-tabs .nav-link {
    color: var(--text-secondary);
    font-weight: 600;
    font-size: 14px;
    border: none;
    border-bottom: 3px solid transparent;
    padding: 0.65rem 1rem;
    border-radius: 0;
    transition: color 0.15s, border-color 0.15s;
}
.history-tabs .nav-link i { margin-right: 0.35rem; }
.history-tabs .nav-link.active {
    color: var(--navy);
    border-bottom-color: var(--navy);
    background: transparent;
}
.history-tabs .nav-link .badge {
    font-size: 10.5px;
    font-weight: 700;
    margin-left: 0.35rem;
    border-radius: 999px;
    background: var(--sky) !important;
    color: var(--navy) !important;
}

/* Tables */
table.table {
    margin-bottom: 0;
}
table.table thead th {
    background: var(--beige) !important;
    color: var(--text-secondary);
    text-transform: uppercase;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
    border-bottom: 1.5px solid var(--border-default);
    padding: 0.65rem 0.75rem;
}
table.table td {
    padding: 0.65rem 0.75rem;
    border-color: var(--border-default);
    font-size: 13.5px;
    color: var(--text-primary);
}
table.table tbody tr:hover {
    background: var(--bg-hover);
}

/* Informational Chips / Badges */
.chip {
    display: inline-block;
    padding: 2px 10px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 999px;
    white-space: nowrap;
}
.chip-paid   { background: #EAF3EE; color: var(--success); }
.chip-due    { background: #FBECEC; color: var(--danger); }
.chip-due.zero { background: #EAF3EE; color: var(--success); }
.chip-total  { background: var(--sky); color: var(--navy); }
.chip-weight { background: var(--beige); color: var(--navy); border: 1px solid var(--border-default); }

/* Buttons */
.btn-secondary {
    background: #FFFFFF;
    border: 1.5px solid var(--border-default);
    color: var(--navy);
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    padding: 0.35rem 0.75rem;
    transition: background-color 0.15s, border-color 0.15s, color 0.15s;
}
.btn-secondary:hover, .btn-secondary:focus {
    background: var(--bg-hover);
    border-color: var(--teal);
    color: var(--navy);
}

/* Card Footer & Pagination */
.card-footer-custom {
    padding: 0.75rem 1rem;
    background: #FFFFFF;
    border-top: 1px solid var(--border-default);
}
.pagination .page-link {
    color: var(--navy);
    border-radius: 8px;
    margin: 0 2px;
    border: 1.5px solid var(--border-default);
    font-size: 13px;
    font-weight: 600;
}
.pagination .page-item.active .page-link {
    background: var(--navy);
    border-color: var(--navy);
    color: #FFFFFF;
}

/* Mobile Cell layout */
.hist-info-cell { min-width: 160px; }
.hist-info-cell .info-row {
    display: flex; justify-content: space-between; gap: 0.5rem;
    font-size: 12px; line-height: 1.45; white-space: nowrap;
}
.hist-info-cell .info-label { color: var(--text-secondary); }
.hist-info-cell .info-value { font-weight: 600; color: var(--text-primary); }
.hist-info-cell .info-value.red   { color: var(--danger); }
.hist-info-cell .info-value.green { color: var(--success); }

/* Responsive adjustments */
@media (min-width: 577px) and (max-width: 991.98px) {
    .stat-bar { grid-template-columns: repeat(2, 1fr); }
    .stat-cell {
        border-right: 1px solid var(--border-default);
        border-bottom: 1px solid var(--border-default);
    }
    .stat-bar .stat-cell:nth-child(2n) { border-right: none; }
    .stat-bar .stat-cell:nth-last-child(-n+2) { border-bottom: none; }
}

@media (max-width: 576px) {
    .page-container { padding: 0 1rem 1rem 1rem; }
    .page-header {
        padding: 0.85rem 1.1rem;
        border-radius: 0 0 14px 14px;
        margin-bottom: 1rem;
    }
    .page-header h4 { font-size: 18px; }
    .page-header small { font-size: 12px; }

    .stat-bar { grid-template-columns: repeat(2, 1fr); }
    .stat-cell {
        padding: 0.65rem 0.75rem;
        gap: 0.5rem;
        border-right: 1px solid var(--border-default);
        border-bottom: 1px solid var(--border-default);
    }
    .stat-bar .stat-cell:nth-child(2n) { border-right: none; }
    .stat-bar .stat-cell:nth-last-child(-n+2) { border-bottom: none; }
    .stat-cell .s-icon { width: 26px; height: 26px; min-width: 26px; font-size: 0.75rem; }
    .stat-cell .s-label { font-size: 10px; }
    .stat-cell .s-value { font-size: 12px; }

    .history-tabs .nav-link { font-size: 13px; padding: 0.5rem 0.65rem; }
    .history-tabs .nav-link .badge { display: none; }

    table.table th, table.table td { padding: 0.5rem 0.4rem; font-size: 13px; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
    <!-- Header -->
    <div class="page-header">
        <div class="header-left">
            <h4 class="mb-0">
                <i class="bi bi-person-lines-fill me-2"></i><?= h($customer['name']) ?>
            </h4>
            <small>গ্রাহকের ইতিহাস — ফাইনবুলিয়ন ডেস্ক</small>
        </div>
        <div class="header-right">
            <a href="customers.php" class="header-action-btn">
                <i class="bi bi-arrow-left"></i>
                <span>ফিরে যান</span>
            </a>
        </div>
    </div>

    <div class="page-container">
        <!-- Customer info card -->
        <div class="card detail-card-wrap">
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <span class="detail-label">ফোন:</span>
                    <span class="detail-val"><?= h($customer['phone'] ?: '—') ?></span>
                </div>
                <div class="col-6 col-md-3">
                    <span class="detail-label">ঠিকানা:</span>
                    <span class="detail-val"><?= h($customer['address'] ?: '—') ?></span>
                </div>
                <div class="col-6 col-md-3">
                    <span class="detail-label">ইমেইল:</span>
                    <span class="detail-val"><?= h($customer['email'] ?: '—') ?></span>
                </div>
                <div class="col-6 col-md-3">
                    <span class="detail-label">এনআইডি:</span>
                    <span class="detail-val"><?= h($customer['nid'] ?: '—') ?></span>
                </div>
            </div>
        </div>

        <!-- ── GOLD EXCHANGE stat block ── -->
        <div class="section-block" id="statBlockExchange">
            <div class="sc-card">
                <div class="sc-header">
                    <div class="sc-header-left">
                        <div class="sc-icon"><i class="bi bi-arrow-left-right"></i></div>
                        <p class="section-label">গোল্ড এক্সচেঞ্জ</p>
                    </div>
                </div>
                <div class="stat-bar" id="statBarExchange">
                    <div class="stat-cell">
                        <div class="s-icon"><i class="bi bi-bricks"></i></div>
                        <div class="s-text">
                            <span class="s-label">মোট অবিশুদ্ধ স্বর্ণ</span>
                            <span class="s-value" id="statExImpure">—</span>
                        </div>
                    </div>
                    <div class="stat-cell">
                        <div class="s-icon"><i class="bi bi-gem"></i></div>
                        <div class="s-text">
                            <span class="s-label">মোট বিশুদ্ধ স্বর্ণ</span>
                            <span class="s-value" id="statExPure">—</span>
                        </div>
                    </div>
                    <div class="stat-cell">
                        <div class="s-icon"><i class="bi bi-graph-down-arrow"></i></div>
                        <div class="s-text">
                            <span class="s-label">মোট ক্ষতি</span>
                            <span class="s-value" id="statExLoss">—</span>
                        </div>
                    </div>
                    <div class="stat-cell stat-emphasis">
                        <div class="s-icon"><i class="bi bi-bullseye"></i></div>
                        <div class="s-text">
                            <span class="s-label">নিট বিশুদ্ধ স্বর্ণ আউটপুট</span>
                            <span class="s-value" id="statExNet">—</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── GOLD BUY stat block ── -->
        <div class="section-block" id="statBlockBuy">
            <div class="sc-card">
                <div class="sc-header">
                    <div class="sc-header-left">
                        <div class="sc-icon"><i class="bi bi-cart"></i></div>
                        <p class="section-label">গোল্ড বাই</p>
                    </div>
                </div>
                <div class="stat-bar" id="statBarBuy">
                    <div class="stat-cell">
                        <div class="s-icon"><i class="bi bi-speedometer"></i></div>
                        <div class="s-text">
                            <span class="s-label">ওজন</span>
                            <span class="s-value" id="statBuyWeight">—</span>
                        </div>
                    </div>
                    <div class="stat-cell stat-emphasis">
                        <div class="s-icon"><i class="bi bi-cash-stack"></i></div>
                        <div class="s-text">
                            <span class="s-label">মোট পরিমাণ</span>
                            <span class="s-value" id="statBuyTotal">—</span>
                        </div>
                    </div>
                    <div class="stat-cell">
                        <div class="s-icon"><i class="bi bi-wallet2"></i></div>
                        <div class="s-text">
                            <span class="s-label">মোট পরিশোধিত</span>
                            <span class="s-value" id="statBuyPaid">—</span>
                        </div>
                    </div>
                    <div class="stat-cell stat-due">
                        <div class="s-icon"><i class="bi bi-file-earmark-text"></i></div>
                        <div class="s-text">
                            <span class="s-label">পরিশোধ বাকি</span>
                            <span class="s-value" id="statBuyDue">—</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── GOLD SALE stat block ── -->
        <div class="section-block" id="statBlockSale">
            <div class="sc-card">
                <div class="sc-header">
                    <div class="sc-header-left">
                        <div class="sc-icon"><i class="bi bi-tag"></i></div>
                        <p class="section-label">গোল্ড সেল</p>
                    </div>
                </div>
                <div class="stat-bar" id="statBarSale">
                    <div class="stat-cell">
                        <div class="s-icon"><i class="bi bi-speedometer"></i></div>
                        <div class="s-text">
                            <span class="s-label">ওজন</span>
                            <span class="s-value" id="statSaleWeight">—</span>
                        </div>
                    </div>
                    <div class="stat-cell stat-emphasis">
                        <div class="s-icon"><i class="bi bi-cash-stack"></i></div>
                        <div class="s-text">
                            <span class="s-label">মোট পরিমাণ</span>
                            <span class="s-value" id="statSaleTotal">—</span>
                        </div>
                    </div>
                    <div class="stat-cell">
                        <div class="s-icon"><i class="bi bi-wallet2"></i></div>
                        <div class="s-text">
                            <span class="s-label">মোট পরিশোধিত</span>
                            <span class="s-value" id="statSalePaid">—</span>
                        </div>
                    </div>
                    <div class="stat-cell stat-due">
                        <div class="s-icon"><i class="bi bi-file-earmark-text"></i></div>
                        <div class="s-text">
                            <span class="s-label">পাওনা বাকি</span>
                            <span class="s-value" id="statSaleDue">—</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabbed history -->
        <div class="card history-tabs-card">
            <ul class="nav history-tabs" id="historyTabs">
                <li class="nav-item">
                    <button class="nav-link active" data-type="buy" type="button">
                        <i class="bi bi-cart"></i>বাই <span class="badge"><?= (int)$summary['buy_count'] ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-type="sale" type="button">
                        <i class="bi bi-cash-coin"></i>সেল <span class="badge"><?= (int)$summary['sale_count'] ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-type="exchange" type="button">
                        <i class="bi bi-arrow-left-right"></i>এক্সচেঞ্জ <span class="badge"><?= (int)$summary['exchange_count'] ?></span>
                    </button>
                </li>
            </ul>

            <div class="p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead id="tableHead"></thead>
                        <tbody id="tableBody">
                            <tr><td class="text-center text-muted py-4">লোড হচ্ছে…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-footer-custom d-flex justify-content-between align-items-center flex-wrap gap-2">
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
        <th class="d-none d-md-table-cell">ওজন</th>
        <th class="d-none d-md-table-cell">মোট পরিমাণ</th>
        <th class="d-none d-md-table-cell">পরিশোধিত</th>
        <th class="d-none d-md-table-cell">বকেয়া</th>
        <th class="d-md-none">বাই তথ্য</th>
        <th class="d-none d-md-table-cell" style="width:140px;">তারিখ</th>
        <th style="width:70px;" class="text-center">অ্যাকশন</th>
    </tr>`,
    sale: `<tr>
        <th style="width:60px;">#</th>
        <th class="d-none d-md-table-cell">ওজন</th>
        <th class="d-none d-md-table-cell">মোট পরিমাণ</th>
        <th class="d-none d-md-table-cell">পরিশোধিত</th>
        <th class="d-none d-md-table-cell">বকেয়া</th>
        <th class="d-md-none">সেল তথ্য</th>
        <th class="d-none d-md-table-cell" style="width:140px;">তারিখ</th>
        <th style="width:70px;" class="text-center">অ্যাকশন</th>
    </tr>`,
    exchange: `<tr>
        <th style="width:60px;">#</th>
        <th class="d-none d-md-table-cell">মোট পাকা</th>
        <th class="d-none d-md-table-cell">লস</th>
        <th class="d-none d-md-table-cell">নেট পাকা</th>
        <th class="d-md-none">এক্সচেঞ্জ তথ্য</th>
        <th class="d-none d-md-table-cell" style="width:140px;">তারিখ</th>
        <th style="width:70px;" class="text-center">অ্যাকশন</th>
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
            <div class="d-md-none" style="font-size:0.68rem;color:#777;">${fmtDate(row.created_at)}</div>
        </td>
        <td class="d-none d-md-table-cell"><span class="chip chip-weight">${fmtTrad(row.total_weight_g)}</span></td>
        <td class="d-none d-md-table-cell"><span class="chip chip-total">${fmtBDT(row.total_amount)}</span></td>
        <td class="d-none d-md-table-cell"><span class="chip chip-paid">${fmtBDT(row.paid_amount)}</span></td>
        <td class="d-none d-md-table-cell"><span class="chip chip-due ${dueClass}">${fmtBDT(due)}</span></td>
        <td class="d-md-none hist-info-cell">
            <div class="info-row"><span class="info-label">ওজন</span><span class="info-value">${fmtTrad(row.total_weight_g)}</span></div>
            <div class="info-row"><span class="info-label">মোট</span><span class="info-value">${fmtBDT(row.total_amount)}</span></div>
            <div class="info-row"><span class="info-label">পরিশোধিত</span><span class="info-value green">${fmtBDT(row.paid_amount)}</span></div>
            <div class="info-row"><span class="info-label">বকেয়া</span><span class="info-value ${due <= 0 ? 'green' : 'red'}">${fmtBDT(due)}</span></div>
        </td>
        <td class="d-none d-md-table-cell text-muted small">${fmtDate(row.created_at)}</td>
        <td class="text-center">
            <a href="${editPage}?id=${row.id}" class="btn btn-secondary" title="দেখুন / সম্পাদনা করুন">
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
            <div class="d-md-none" style="font-size:0.68rem;color:#777;">${fmtDate(row.created_at)}</div>
        </td>
        <td class="d-none d-md-table-cell"><span class="chip chip-weight">${fmtTrad(row.total_pure_gold)}</span></td>
        <td class="d-none d-md-table-cell"><span class="chip chip-due">${fmtTrad(row.loss)}</span></td>
        <td class="d-none d-md-table-cell"><span class="chip chip-paid">${fmtTrad(row.final_pure_gold)}</span></td>
        <td class="d-md-none hist-info-cell">
            <div class="info-row"><span class="info-label">মোট পাকা</span><span class="info-value">${fmtTrad(row.total_pure_gold)}</span></div>
            <div class="info-row"><span class="info-label">লস(${lossRate} Pt/V)</span><span class="info-value red">${fmtTrad(row.loss)}</span></div>
            <div class="info-row"><span class="info-label">নেট পাকা</span><span class="info-value green">${fmtTrad(row.final_pure_gold)}</span></div>
        </td>
        <td class="d-none d-md-table-cell text-muted small">${fmtDate(row.created_at)}</td>
        <td class="text-center">
            <a href="gold_exchange_edit.php?id=${row.id}" class="btn btn-secondary" title="দেখুন / সম্পাদনা করুন">
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
    tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-muted py-4">লোড হচ্ছে…</td></tr>`;

    try {
        const params = new URLSearchParams({ action: 'list', type, page, customer_id: CUSTOMER_ID });
        const res  = await fetch('customer_history.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-danger py-4">${escHtml(data.message || 'লোড করতে ব্যর্থ হয়েছে।')}</td></tr>`;
            return;
        }

        if (data.data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-muted py-4">কোনো ${escHtml(type)} রেকর্ড পাওয়া যায়নি।</td></tr>`;
        } else if (type === 'exchange') {
            tbody.innerHTML = data.data.map(rowExchange).join('');
        } else {
            tbody.innerHTML = data.data.map(r => rowBuySale(r, type)).join('');
        }

        // Pagination
        const info = document.getElementById('paginationInfo');
        const start = data.totalRows === 0 ? 0 : (data.page - 1) * 10 + 1;
        const end   = Math.min(data.page * 10, data.totalRows);
        info.textContent = `${start}–${end} দেখানো হচ্ছে, মোট ${data.totalRows} টির মধ্যে`;

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
        tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-danger py-4">নেটওয়ার্ক ত্রুটি।</td></tr>`;
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