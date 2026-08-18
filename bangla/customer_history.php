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
<title>গ্রাহকের ইতিহাস — ফাইনবুলিয়ন ডেস্ক</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
    --status-paid-bg: #1b5238;
    --status-paid-light: #eaf4ee;
    --status-due-bg: #93292c;
    --status-due-light: #fbeceb;
    --status-total-bg: #b88328;
    --status-total-light: #fdf6e2;

    /* Premium summary-card palette (Gold Exchange / Buy / Sale) */
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
    background: var(--sc-bg);
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--bronze-text);
}

/* ---- page header ---- */
.list-header {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%);
    color: #fff;
    padding: 1.4rem 1.75rem;
    margin-top: 0;
    position: relative;
    border-top-left-radius: 0;
    border-top-right-radius: 0;
    border-bottom-left-radius: 20px;
    border-bottom-right-radius: 20px;
    box-shadow: 0 6px 24px rgba(201, 151, 58, 0.22);
}
.list-header h4, .list-header h4 i { color: #fff; font-weight: 800; letter-spacing: 0.02em; }
.list-header small { color: rgba(255,255,255,0.80); font-size: 0.8rem; }
.list-header .btn-back {
    color: #fff; border-color: rgba(255,255,255,0.6);
    border-radius: 999px;
}
.list-header .btn-back:hover { background: rgba(255,255,255,0.15); opacity: 0.92; }
.container-fluid.px-0 > *:not(.list-header) { margin-left: 1rem; margin-right: 1rem; }
.list-header + * { margin-top: 1.25rem; }

/* ---- customer info card ---- */
.detail-card-wrap {
    border-radius: 18px;
    overflow: hidden;
    border: none;
    box-shadow: 0 10px 30px rgba(180, 140, 50, 0.12);
}
.detail-card { padding: 1rem 1.25rem; }
.detail-label { font-size:.78rem; color: var(--muted); font-weight:500; white-space:nowrap; }
.detail-val   { font-size:.97rem; font-weight:600; color: var(--bronze-text); word-break:break-word; }

/* ---- summary cards: Gold Exchange / Gold Buy / Gold Sale ---- */
.section-block {
    margin-bottom: 10px;
}
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
.sc-header-left {
    display: flex;
    align-items: center;
    gap: 8px;
}
.sc-icon {
    width: 26px;
    height: 26px;
    min-width: 26px;
    border-radius: 50%;
    background: var(--sc-gold-light);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    color: var(--sc-gold);
    border: 1px solid var(--sc-border);
}
.section-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--sc-gold-dark);
    margin: 0;
}
.sc-header-icon {
    color: var(--sc-gold);
    font-size: 0.85rem;
    opacity: 0.8;
}

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
    width: 26px;
    height: 26px;
    min-width: 26px;
    border-radius: 50%;
    background: rgba(201, 151, 43, 0.09);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    color: var(--sc-gold-dark);
}
.stat-cell .s-text {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
}
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
.stat-cell .s-rule { display: none; }

/* highlighted / emphasis cells (net output, amount) */
.stat-cell.stat-emphasis {
    background: var(--sc-gold-light);
}
.stat-cell.stat-emphasis .s-value { color: var(--sc-gold-dark); }
.stat-cell.stat-emphasis .s-icon { background: #ffffff; }

/* due / outstanding cells */
.stat-cell.stat-due {
    background: var(--sc-due-bg);
}
.stat-cell.stat-due .s-value { color: var(--sc-due-text); }
.stat-cell.stat-due .s-icon { background: #ffffff; color: var(--sc-due-text); }

/* neutral cells keep dark text, gold rule/icon accents by default above */

/* ---- tabs ---- */
.history-tabs { border-bottom: 2px solid var(--hairline); margin-bottom: 0; }
.history-tabs .nav-link {
    color: var(--muted); font-weight: 600; font-size: 0.88rem;
    border: none; border-bottom: 3px solid transparent;
    padding: 0.75rem 1.1rem; border-radius: 0;
}
.history-tabs .nav-link i { margin-right: 0.35rem; }
.history-tabs .nav-link.active {
    color: var(--gold-deep); border-bottom-color: var(--gold-deep); background: transparent;
}
.history-tabs .nav-link .badge { font-size: 0.68rem; margin-left: 0.3rem; }

/* ---- amount badges ---- */
.badge-amount { background: var(--status-total-light); color: var(--status-total-bg); font-weight: 600; font-size: 0.82rem; }
.badge-paid   { background: var(--status-paid-light); color: var(--status-paid-bg); font-weight: 600; font-size: 0.82rem; }
.badge-due    { background: var(--status-due-light); color: var(--status-due-bg); font-weight: 600; font-size: 0.82rem; }
.badge-due.zero { background: var(--status-paid-light); color: var(--status-paid-bg); }
.badge-weight { background: var(--status-paid-light); color: var(--status-paid-bg); font-weight: 600; font-size: 0.82rem;
                border: 1px solid var(--hairline); }

/* ---- mobile info cell ---- */
.hist-info-cell { min-width: 160px; }
.hist-info-cell .info-row {
    display: flex; justify-content: space-between; gap: 0.5rem;
    font-size: 0.72rem; line-height: 1.45; white-space: nowrap;
}
.hist-info-cell .info-label { color: var(--muted); }
.hist-info-cell .info-value { font-weight: 600; color: var(--bronze-text); }
.hist-info-cell .info-value.red   { color: var(--status-due-bg); }
.hist-info-cell .info-value.green { color: var(--status-paid-bg); }

.btn-gold {
    background: var(--gold-deep); border-color: var(--gold-deep); color: #fff; font-weight: 700;
    border-radius: 999px;
}
.btn-gold:hover { background: var(--gold-deep); border-color: var(--gold-deep); color: #fff; opacity: 0.92; }

/* ---- generic card / table / form styling ---- */
.card {
    background: #ffffff;
    border-radius: 18px;
    border: none;
    box-shadow: 0 10px 30px rgba(180, 140, 50, 0.12);
}
.card-footer { border-top: 1px solid var(--hairline); border-radius: 0 0 18px 18px; }
table.table thead.table-light th {
    background: var(--ivory) !important;
    color: var(--muted);
    text-transform: uppercase;
    font-size: 0.72rem;
    letter-spacing: 0.04em;
    border-bottom: 1.5px solid var(--hairline);
}
table.table td, table.table th { border-color: var(--hairline); }
table.table tbody tr:hover { background: #fdf7ec; }

.btn-outline-secondary {
    background: #ffffff;
    border: 1.5px solid var(--hairline);
    color: var(--muted);
    border-radius: 999px;
}
.btn-outline-secondary:hover {
    background: var(--ivory);
    border-color: var(--gold-deep);
    color: var(--gold-deep);
}

.pagination .page-link { color: var(--gold-deep); border-radius: 999px; margin: 0 2px; border: 1.5px solid var(--hairline); }
.pagination .page-item.active .page-link {
    background: var(--gold-deep); border-color: var(--gold-deep); color: #fff;
}

.badge.bg-light { background: var(--ivory) !important; color: var(--muted); border-color: var(--hairline) !important; }

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
/* ---- mobile ---- */
@media (max-width: 767.98px) {
    .page-content .container-fluid { padding: 0 0 1rem; }
    .container-fluid.px-0 > *:not(.list-header) { margin-left: 0.6rem; margin-right: 0.6rem; }

    .list-header {
        padding: 1rem 1rem;
        border-bottom-left-radius: 16px;
        border-bottom-right-radius: 16px;
        margin-bottom: 1rem !important;
    }
    .list-header h4 { font-size: 1rem; margin-bottom: 0; }
    .list-header small { display: none; }

    .detail-card { padding: 0.75rem 0.9rem; }

    .stat-bar { grid-template-columns: repeat(2, 1fr); }
    .section-block { margin-bottom: 8px; }
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

    .history-tabs .nav-link { font-size: 0.78rem; padding: 0.6rem 0.6rem; }
    .history-tabs .nav-link .badge { display: none; }

    .card { border-radius: 14px; }
    table.table { font-size: 0.78rem; }
    table.table th, table.table td { padding: 0.5rem 0.4rem; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0 pb-4">

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
                <small>গ্রাহকের ইতিহাস — ফাইনবুলিয়ন ডেস্ক</small>
            </div>
        </div>
    </div>

    <!-- Customer info card -->
    <div class="card shadow-sm mb-4 detail-card-wrap">
        <div class="detail-card">
            <div class="row g-2">
                <div class="col-6 col-md-3">
                    <span class="detail-label">ফোন:</span>
                    <span class="detail-val d-block"><?= h($customer['phone'] ?: '—') ?></span>
                </div>
                <div class="col-6 col-md-3">
                    <span class="detail-label">ঠিকানা:</span>
                    <span class="detail-val d-block" style="font-weight:400;color:#555;">
                        <?= h($customer['address'] ?: '—') ?>
                    </span>
                </div>
                <div class="col-6 col-md-3">
                    <span class="detail-label">ইমেইল:</span>
                    <span class="detail-val d-block" style="font-weight:400;color:#555;">
                        <?= h($customer['email'] ?: '—') ?>
                    </span>
                </div>
                <div class="col-6 col-md-3">
                    <span class="detail-label">এনআইডি:</span>
                    <span class="detail-val d-block" style="font-weight:400;color:#555;">
                        <?= h($customer['nid'] ?: '—') ?>
                    </span>
                </div>
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
                <i class="bi bi-slash-lg sc-header-icon" style="transform:rotate(90deg) scaleX(0.6);"></i>
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
                <i class="bi bi-bag-check sc-header-icon"></i>
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
                        <span class="s-label">মোট বকেয়া</span>
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
                <i class="bi bi-graph-up-arrow sc-header-icon"></i>
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
                        <span class="s-label">মোট বকেয়া</span>
                        <span class="s-value" id="statSaleDue">—</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabbed history -->
    <div class="card shadow-sm">
        <ul class="nav history-tabs px-2 pt-1" id="historyTabs">
            <li class="nav-item">
                <button class="nav-link active" data-type="buy" type="button">
                    <i class="bi bi-cart"></i>বাই <span class="badge bg-light text-dark border"><?= (int)$summary['buy_count'] ?></span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-type="sale" type="button">
                    <i class="bi bi-cash-coin"></i>সেল <span class="badge bg-light text-dark border"><?= (int)$summary['sale_count'] ?></span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-type="exchange" type="button">
                    <i class="bi bi-arrow-left-right"></i>এক্সচেঞ্জ <span class="badge bg-light text-dark border"><?= (int)$summary['exchange_count'] ?></span>
                </button>
            </li>
        </ul>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light" id="tableHead"></thead>
                    <tbody id="tableBody">
                        <tr><td class="text-center text-muted py-4">লোড হচ্ছে…</td></tr>
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
        <th class="d-none d-md-table-cell">বিশুদ্ধ স্বর্ণ</th>
        <th class="d-none d-md-table-cell">ক্ষতি</th>
        <th class="d-none d-md-table-cell">চূড়ান্ত বিশুদ্ধ স্বর্ণ</th>
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
            <div class="d-md-none" style="font-size:0.68rem;color:#999;">${fmtDate(row.created_at)}</div>
        </td>
        <td class="d-none d-md-table-cell"><span class="badge badge-weight">${fmtTrad(row.total_weight_g)}</span></td>
        <td class="d-none d-md-table-cell"><span class="badge badge-amount">${fmtBDT(row.total_amount)}</span></td>
        <td class="d-none d-md-table-cell"><span class="badge badge-paid">${fmtBDT(row.paid_amount)}</span></td>
        <td class="d-none d-md-table-cell"><span class="badge badge-due ${dueClass}">${fmtBDT(due)}</span></td>
        <td class="d-md-none hist-info-cell">
            <div class="info-row"><span class="info-label">ওজন</span><span class="info-value">${fmtTrad(row.total_weight_g)}</span></div>
            <div class="info-row"><span class="info-label">মোট</span><span class="info-value">${fmtBDT(row.total_amount)}</span></div>
            <div class="info-row"><span class="info-label">পরিশোধিত</span><span class="info-value green">${fmtBDT(row.paid_amount)}</span></div>
            <div class="info-row"><span class="info-label">বকেয়া</span><span class="info-value ${due <= 0 ? 'green' : 'red'}">${fmtBDT(due)}</span></div>
        </td>
        <td class="d-none d-md-table-cell text-muted small">${fmtDate(row.created_at)}</td>
        <td class="text-center">
            <a href="${editPage}?id=${row.id}" class="btn btn-sm btn-outline-secondary" title="দেখুন / সম্পাদনা করুন">
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
            <div class="info-row"><span class="info-label">মোট বিশুদ্ধ স্বর্ণ</span><span class="info-value">${fmtTrad(row.total_pure_gold)}</span></div>
            <div class="info-row"><span class="info-label">ক্ষতি (${lossRate} Pt/V)</span><span class="info-value red">${fmtTrad(row.loss)}</span></div>
            <div class="info-row"><span class="info-label">চূড়ান্ত বিশুদ্ধ স্বর্ণ</span><span class="info-value green">${fmtTrad(row.final_pure_gold)}</span></div>
        </td>
        <td class="d-none d-md-table-cell text-muted small">${fmtDate(row.created_at)}</td>
        <td class="text-center">
            <a href="gold_exchange_edit.php?id=${row.id}" class="btn btn-sm btn-outline-secondary" title="দেখুন / সম্পাদনা করুন">
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