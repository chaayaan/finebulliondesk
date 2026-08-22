<?php
/**
 * dashboard.php
 * FineBullion Desk — Operational Business Dashboard
 *
 * Aggregates real data from the existing modules:
 *   gold_buys / gold_buy_items / gold_buy_payments
 *   gold_sales / gold_sale_items / gold_sale_payments
 *   gold_exchanges / gold_exchange_items
 *   expenses / expense_categories
 *
 * All calculations mirror the logic already used in gold_buy_list.php,
 * gold_sale_list.php, gold_exchange_list.php, customer_history.php and
 * expenses.php — no new business rules are introduced here.
 *
 * "Due" figures are always all-time (a running balance, not tied to a
 * single day). Everything else respects the selected period.
 */

require_once __DIR__ . '/auth.php';

// -----------------------------------------------------------------------
// Conversion helpers (VARP) — identical to the other modules
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
    return "{$t['v']} ভ {$t['a']} আ {$t['r']} র {$t['p']} প";
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

/** Resolve a period keyword (+ optional custom range) into [from, to, label]. */
function resolve_period(string $period, string $from, string $to): array {
    $today = date('Y-m-d');
    switch ($period) {
        case 'yesterday':
            $d = date('Y-m-d', strtotime('-1 day'));
            return [$d, $d, 'গতকাল'];
        case 'week':
            return [date('Y-m-d', strtotime('-6 days')), $today, 'এই সপ্তাহ'];
        case 'month':
            return [date('Y-m-01'), $today, 'এই মাস'];
        case 'custom':
            $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : $today;
            $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : $today;
            if ($f > $t) { [$f, $t] = [$t, $f]; }
            return [$f, $t, 'কাস্টম'];
        case 'today':
        default:
            return [$today, $today, 'আজ'];
    }
}

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$action = $_GET['action'] ?? null;

// =========================================================================
// AJAX: single combined data payload for the selected period
// =========================================================================
if (($isAjax || $action !== null) && $action === 'data' && $_SERVER['REQUEST_METHOD'] === 'GET') {

    [$from, $to, $periodLabel] = resolve_period(
        trim($_GET['period'] ?? 'today'),
        trim($_GET['from']   ?? ''),
        trim($_GET['to']     ?? '')
    );

    // ---- Gold Buy card (period, by created_at — same pattern as gold_buy_list.php) ----
    $stmt = mysqli_prepare($conn,
        "SELECT COUNT(*) cnt,
                COALESCE(SUM(gb.total_amount), 0) amt,
                COALESCE(SUM(gbi.weight), 0)      wt,
                COALESCE(SUM(gbp.paid), 0)        paid
         FROM gold_buys gb
         LEFT JOIN (SELECT gold_buy_id, SUM(weight) weight FROM gold_buy_items GROUP BY gold_buy_id) gbi
                ON gbi.gold_buy_id = gb.id
         LEFT JOIN (SELECT gold_buy_id, SUM(paid_amount) paid FROM gold_buy_payments GROUP BY gold_buy_id) gbp
                ON gbp.gold_buy_id = gb.id
         WHERE DATE(gb.created_at) BETWEEN ? AND ?");
    mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
    mysqli_stmt_execute($stmt);
    $buy = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // ---- Gold Sale card (period) ----
    $stmt = mysqli_prepare($conn,
        "SELECT COUNT(*) cnt,
                COALESCE(SUM(gs.total_amount), 0) amt,
                COALESCE(SUM(gsi.weight), 0)      wt,
                COALESCE(SUM(gsp.paid), 0)        paid
         FROM gold_sales gs
         LEFT JOIN (SELECT gold_sale_id, SUM(weight) weight FROM gold_sale_items GROUP BY gold_sale_id) gsi
                ON gsi.gold_sale_id = gs.id
         LEFT JOIN (SELECT gold_sale_id, SUM(paid_amount) paid FROM gold_sale_payments GROUP BY gold_sale_id) gsp
                ON gsp.gold_sale_id = gs.id
         WHERE DATE(gs.created_at) BETWEEN ? AND ?");
    mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
    mysqli_stmt_execute($stmt);
    $sale = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // ---- Gold Exchange card (period) ----
    $stmt = mysqli_prepare($conn,
        "SELECT COUNT(*) cnt,
                COALESCE(SUM(gei.old_gold_weight), 0) old_wt,
                COALESCE(SUM(ge.total_pure_gold), 0)  pure_wt,
                COALESCE(SUM(ge.loss), 0)             loss_wt,
                COALESCE(SUM(ge.final_pure_gold), 0)  final_wt
         FROM gold_exchanges ge
         LEFT JOIN (SELECT gold_exchange_id, SUM(old_gold_weight) old_gold_weight
                    FROM gold_exchange_items GROUP BY gold_exchange_id) gei
                ON gei.gold_exchange_id = ge.id
         WHERE DATE(ge.created_at) BETWEEN ? AND ?");
    mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
    mysqli_stmt_execute($stmt);
    $exchange = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // ---- Expenses card (period, by date_of_expenses — same field expenses.php filters on) ----
    $stmt = mysqli_prepare($conn,
        "SELECT COUNT(*) cnt, COALESCE(SUM(amount), 0) amt
         FROM expenses WHERE date_of_expenses BETWEEN ? AND ?");
    mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
    mysqli_stmt_execute($stmt);
    $expense = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // ---- Financial overview (period) ----
    $saleTotal = (float)$sale['amt'];
    $buyTotal  = (float)$buy['amt'];
    $expTotal  = (float)$expense['amt'];
    $salePaid  = (float)$sale['paid'];
    $buyPaid   = (float)$buy['paid'];
    $netCash   = $salePaid - $buyPaid - $expTotal;

    // ---- Expense breakdown by category (period) — real categories only ----
    $stmt = mysqli_prepare($conn,
        "SELECT c.category, COALESCE(SUM(e.amount),0) total
         FROM expenses e
         JOIN expense_categories c ON c.id = e.expense_category_id
         WHERE e.date_of_expenses BETWEEN ? AND ?
         GROUP BY c.id, c.category
         ORDER BY total DESC");
    mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
    mysqli_stmt_execute($stmt);
    $expenseBreakdown = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    // ---- Trend: daily transaction counts across the period ----
    $trendMap = [];
    $cursor = new DateTime($from);
    $end    = new DateTime($to);
    while ($cursor <= $end) {
        $d = $cursor->format('Y-m-d');
        $trendMap[$d] = ['date' => $d, 'buy' => 0, 'sale' => 0, 'exchange' => 0, 'expense' => 0];
        $cursor->modify('+1 day');
    }
    $trendQueries = [
        'buy'      => "SELECT DATE(created_at) d, COUNT(*) c FROM gold_buys WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at)",
        'sale'     => "SELECT DATE(created_at) d, COUNT(*) c FROM gold_sales WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at)",
        'exchange' => "SELECT DATE(created_at) d, COUNT(*) c FROM gold_exchanges WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at)",
        'expense'  => "SELECT date_of_expenses d, COUNT(*) c FROM expenses WHERE date_of_expenses BETWEEN ? AND ? GROUP BY date_of_expenses",
    ];
    foreach ($trendQueries as $key => $tq) {
        $stmt = mysqli_prepare($conn, $tq);
        mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            if (isset($trendMap[$row['d']])) $trendMap[$row['d']][$key] = (int)$row['c'];
        }
    }
    $trend = array_values($trendMap);

    // ---- Due / outstanding (ALL-TIME — a running balance, not period-based) ----
    $stmt = mysqli_prepare($conn,
        "SELECT
            COALESCE(SUM(CASE WHEN (gb.total_amount - COALESCE(gbp.paid,0)) > 0.009
                          THEN (gb.total_amount - COALESCE(gbp.paid,0)) ELSE 0 END), 0) due_sum,
            SUM(CASE WHEN (gb.total_amount - COALESCE(gbp.paid,0)) > 0.009 THEN 1 ELSE 0 END) due_cnt
         FROM gold_buys gb
         LEFT JOIN (SELECT gold_buy_id, SUM(paid_amount) paid FROM gold_buy_payments GROUP BY gold_buy_id) gbp
                ON gbp.gold_buy_id = gb.id");
    mysqli_stmt_execute($stmt);
    $buyDue = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    $stmt = mysqli_prepare($conn,
        "SELECT
            COALESCE(SUM(CASE WHEN (gs.total_amount - COALESCE(gsp.paid,0)) > 0.009
                          THEN (gs.total_amount - COALESCE(gsp.paid,0)) ELSE 0 END), 0) due_sum,
            SUM(CASE WHEN (gs.total_amount - COALESCE(gsp.paid,0)) > 0.009 THEN 1 ELSE 0 END) due_cnt
         FROM gold_sales gs
         LEFT JOIN (SELECT gold_sale_id, SUM(paid_amount) paid FROM gold_sale_payments GROUP BY gold_sale_id) gsp
                ON gsp.gold_sale_id = gs.id");
    mysqli_stmt_execute($stmt);
    $saleDue = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    $totalDue    = (float)$buyDue['due_sum'] + (float)$saleDue['due_sum'];
    $totalDueCnt = (int)$buyDue['due_cnt']   + (int)$saleDue['due_cnt'];

    // Top outstanding transactions (combined buy + sale), highest due first
    $stmt = mysqli_prepare($conn,
        "SELECT gb.id, c.name AS customer_name,
                (gb.total_amount - COALESCE(gbp.paid,0)) AS due_amount, gb.created_at
         FROM gold_buys gb
         JOIN customers c ON c.id = gb.customer_id
         LEFT JOIN (SELECT gold_buy_id, SUM(paid_amount) paid FROM gold_buy_payments GROUP BY gold_buy_id) gbp
                ON gbp.gold_buy_id = gb.id
         HAVING due_amount > 0.009
         ORDER BY due_amount DESC LIMIT 5");
    mysqli_stmt_execute($stmt);
    $topBuyDue = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $stmt = mysqli_prepare($conn,
        "SELECT gs.id, c.name AS customer_name,
                (gs.total_amount - COALESCE(gsp.paid,0)) AS due_amount, gs.created_at
         FROM gold_sales gs
         JOIN customers c ON c.id = gs.customer_id
         LEFT JOIN (SELECT gold_sale_id, SUM(paid_amount) paid FROM gold_sale_payments GROUP BY gold_sale_id) gsp
                ON gsp.gold_sale_id = gs.id
         HAVING due_amount > 0.009
         ORDER BY due_amount DESC LIMIT 5");
    mysqli_stmt_execute($stmt);
    $topSaleDue = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $topDue = [];
    foreach ($topBuyDue  as $r) $topDue[] = ['type' => 'buy',  'id' => (int)$r['id'], 'customer_name' => $r['customer_name'], 'due_amount' => (float)$r['due_amount'], 'created_at' => $r['created_at']];
    foreach ($topSaleDue as $r) $topDue[] = ['type' => 'sale', 'id' => (int)$r['id'], 'customer_name' => $r['customer_name'], 'due_amount' => (float)$r['due_amount'], 'created_at' => $r['created_at']];
    usort($topDue, fn($a, $b) => $b['due_amount'] <=> $a['due_amount']);
    $topDue = array_slice($topDue, 0, 5);

    // ---- Recent transactions (combined, most recent first) ----
    $stmt = mysqli_prepare($conn,
        "SELECT gb.id, c.name AS customer_name, gb.total_amount, gb.created_at,
                COALESCE(gbi.weight,0) AS weight,
                (gb.total_amount - COALESCE(gbp.paid,0)) AS due_amount
         FROM gold_buys gb
         JOIN customers c ON c.id = gb.customer_id
         LEFT JOIN (SELECT gold_buy_id, SUM(weight) weight FROM gold_buy_items GROUP BY gold_buy_id) gbi
                ON gbi.gold_buy_id = gb.id
         LEFT JOIN (SELECT gold_buy_id, SUM(paid_amount) paid FROM gold_buy_payments GROUP BY gold_buy_id) gbp
                ON gbp.gold_buy_id = gb.id
         ORDER BY gb.created_at DESC LIMIT 6");
    mysqli_stmt_execute($stmt);
    $recentBuy = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $stmt = mysqli_prepare($conn,
        "SELECT gs.id, c.name AS customer_name, gs.total_amount, gs.created_at,
                COALESCE(gsi.weight,0) AS weight,
                (gs.total_amount - COALESCE(gsp.paid,0)) AS due_amount
         FROM gold_sales gs
         JOIN customers c ON c.id = gs.customer_id
         LEFT JOIN (SELECT gold_sale_id, SUM(weight) weight FROM gold_sale_items GROUP BY gold_sale_id) gsi
                ON gsi.gold_sale_id = gs.id
         LEFT JOIN (SELECT gold_sale_id, SUM(paid_amount) paid FROM gold_sale_payments GROUP BY gold_sale_id) gsp
                ON gsp.gold_sale_id = gs.id
         ORDER BY gs.created_at DESC LIMIT 6");
    mysqli_stmt_execute($stmt);
    $recentSale = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $stmt = mysqli_prepare($conn,
        "SELECT ge.id, c.name AS customer_name, ge.final_pure_gold, ge.created_at
         FROM gold_exchanges ge
         JOIN customers c ON c.id = ge.customer_id
         ORDER BY ge.created_at DESC LIMIT 6");
    mysqli_stmt_execute($stmt);
    $recentExchange = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $stmt = mysqli_prepare($conn,
        "SELECT e.id, c.category, e.amount, e.details, e.date_of_expenses, e.created_at
         FROM expenses e
         JOIN expense_categories c ON c.id = e.expense_category_id
         ORDER BY e.created_at DESC LIMIT 6");
    mysqli_stmt_execute($stmt);
    $recentExpense = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $recent = [];
    foreach ($recentBuy as $r) $recent[] = [
        'type' => 'buy', 'id' => (int)$r['id'], 'title' => $r['customer_name'],
        'weight' => (float)$r['weight'], 'amount' => (float)$r['total_amount'],
        'due' => (float)$r['due_amount'], 'date' => $r['created_at'],
    ];
    foreach ($recentSale as $r) $recent[] = [
        'type' => 'sale', 'id' => (int)$r['id'], 'title' => $r['customer_name'],
        'weight' => (float)$r['weight'], 'amount' => (float)$r['total_amount'],
        'due' => (float)$r['due_amount'], 'date' => $r['created_at'],
    ];
    foreach ($recentExchange as $r) $recent[] = [
        'type' => 'exchange', 'id' => (int)$r['id'], 'title' => $r['customer_name'],
        'weight' => (float)$r['final_pure_gold'], 'amount' => null,
        'due' => null, 'date' => $r['created_at'],
    ];
    foreach ($recentExpense as $r) $recent[] = [
        'type' => 'expense', 'id' => (int)$r['id'], 'title' => $r['category'],
        'subtitle' => $r['details'], 'weight' => null, 'amount' => (float)$r['amount'],
        'due' => null, 'date' => $r['created_at'],
    ];
    usort($recent, fn($a, $b) => strtotime($b['date']) <=> strtotime($a['date']));
    $recent = array_slice($recent, 0, 10);

    json_out([
        'success' => true,
        'period'  => ['from' => $from, 'to' => $to, 'label' => $periodLabel],
        'cards'   => [
            'exchange' => [
                'count' => (int)$exchange['cnt'],
                'old_weight' => (float)$exchange['old_wt'],
                'pure_weight' => (float)$exchange['pure_wt'],
                'loss' => (float)$exchange['loss_wt'],
                'final_weight' => (float)$exchange['final_wt'],
            ],
            'buy' => [
                'count' => (int)$buy['cnt'], 'amount' => $buyTotal,
                'weight' => (float)$buy['wt'], 'paid' => $buyPaid,
            ],
            'sale' => [
                'count' => (int)$sale['cnt'], 'amount' => $saleTotal,
                'weight' => (float)$sale['wt'], 'paid' => $salePaid,
            ],
            'expense' => [
                'count' => (int)$expense['cnt'], 'amount' => $expTotal,
            ],
        ],
        'financial' => [
            'sale_total' => $saleTotal, 'buy_total' => $buyTotal, 'expense_total' => $expTotal,
            'sale_paid'  => $salePaid,  'buy_paid'  => $buyPaid,  'net_cash_flow' => $netCash,
        ],
        'gold_movement' => [
            'buy_weight' => (float)$buy['wt'], 'sale_weight' => (float)$sale['wt'],
            'exchange_old_weight' => (float)$exchange['old_wt'],
            'exchange_final_weight' => (float)$exchange['final_wt'],
        ],
        'trend' => $trend,
        'buy_vs_sale' => [
            'buy_amount' => $buyTotal, 'sale_amount' => $saleTotal,
            'buy_count' => (int)$buy['cnt'], 'sale_count' => (int)$sale['cnt'],
        ],
        'expense_breakdown' => $expenseBreakdown,
        'due' => [
            'total' => $totalDue, 'count' => $totalDueCnt, 'top' => $topDue,
        ],
        'recent' => $recent,
    ]);
}

if ($isAjax || $action !== null) {
    json_out(['success' => false, 'message' => 'অজানা অ্যাকশন।'], 400);
}
?>
<!DOCTYPE html>
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ড্যাশবোর্ড — ফাইনবুলিয়ন ডেস্ক</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Bengali:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
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
    font-family: 'Inter', 'Noto Sans Bengali', system-ui, -apple-system, sans-serif;
    color: var(--bronze-text);
}
.page-inset { padding: 0 1.5rem 2rem; }

/* ---- header (matches exchange-header/fb-header pattern) ---- */
.dash-header {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%);
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap;
    gap: 1rem; padding: 1rem 1.75rem; margin: 0; width: 100%;
    border-radius: 0 0 20px 20px; color: #fff;
}
.dash-header h4 { color: #fff !important; margin: 0; }
.dash-header small { color: rgba(255,255,255,.85); }

/* ---- period pills ---- */
.period-pills { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
.period-pill {
    background: rgba(255,255,255,.18); border: 1.5px solid rgba(255,255,255,.35);
    color: #fff; font-weight: 600; font-size: .82rem; border-radius: 999px;
    padding: .32rem .85rem; cursor: pointer; white-space: nowrap; transition: .15s;
}
.period-pill:hover { background: rgba(255,255,255,.3); }
.period-pill.active { background: #fff; color: var(--gold-deep); border-color: #fff; }
.custom-range-box {
    display: none; align-items: center; gap: .4rem; background: rgba(255,255,255,.18);
    border: 1.5px solid rgba(255,255,255,.35); border-radius: 999px; padding: .25rem .6rem;
}
.custom-range-box.show { display: flex; }
.custom-range-box input {
    border: none; background: transparent; color: #fff; font-size: .78rem; width: 108px;
}
.custom-range-box input::-webkit-calendar-picker-indicator { filter: invert(1); }

/* ---- quick actions ---- */
.quick-actions { display: flex; flex-wrap: wrap; gap: .6rem; margin-bottom: 1.25rem; }
.qa-btn {
    flex: 1 1 160px; display: flex; align-items: center; gap: .6rem;
    background: #fff; border: 1.5px solid var(--hairline); border-radius: 14px;
    padding: .7rem .9rem; text-decoration: none; color: var(--bronze-text);
    font-weight: 700; font-size: .88rem; box-shadow: 0 2px 8px rgba(37,37,37,.03);
    transition: .15s;
}
.qa-btn:hover { border-color: var(--gold-deep); background: #fdf7ec; color: var(--bronze-text); }
.qa-btn .qa-icon {
    width: 34px; height: 34px; min-width: 34px; border-radius: 10px;
    background: var(--sc-gold-light); color: var(--gold-deep);
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
}

/* ---- section card shell (reused sc-card pattern) ---- */
.section-block { margin-bottom: 1.25rem; }
.sc-card {
    background: var(--sc-card); border: 1px solid var(--sc-border); border-radius: 14px;
    box-shadow: 0 2px 8px rgba(37,37,37,.03); overflow: hidden;
}
.sc-header {
    display: flex; align-items: center; justify-content: space-between; gap: .5rem;
    padding: 10px 14px; border-bottom: 1px solid var(--sc-border);
}
.sc-header-left { display: flex; align-items: center; gap: 8px; }
.sc-icon {
    width: 26px; height: 26px; min-width: 26px; border-radius: 50%;
    background: var(--sc-gold-light); border: 1px solid var(--sc-border);
    display: flex; align-items: center; justify-content: center; font-size: .75rem; color: var(--sc-gold);
}
.section-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    color: var(--sc-gold-dark); margin: 0;
}
.sc-header-icon { color: var(--sc-gold); font-size: .85rem; opacity: .8; }
.sc-body { padding: 14px; }

/* ---- primary summary cards ---- */
.summary-grid {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.25rem;
}
.summary-card {
    background: #fff; border: 1px solid var(--sc-border); border-radius: 14px;
    box-shadow: 0 2px 8px rgba(37,37,37,.03); padding: 1rem 1.1rem;
}
.summary-card .sc-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: .6rem; }
.summary-card .sc-title { font-size: .78rem; font-weight: 700; color: var(--sc-text-2); text-transform: uppercase; letter-spacing: .03em; }
.summary-card .sc-badge-icon {
    width: 34px; height: 34px; border-radius: 10px; background: var(--sc-gold-light);
    color: var(--gold-deep); display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.summary-card .sc-main-value { font-size: 1.35rem; font-weight: 800; color: var(--bronze-text); line-height: 1.2; }
.summary-card .sc-sub-value { font-size: .8rem; color: var(--sc-text-2); margin-top: .15rem; }

/* ---- financial overview rows ---- */
.fin-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; }
.fin-cell {
    padding: 12px 16px; border-right: 1px solid var(--sc-border); border-bottom: 1px solid var(--sc-border);
}
.fin-cell:nth-child(3n) { border-right: none; }
.fin-label { font-size: .74rem; color: var(--sc-text-2); text-transform: uppercase; letter-spacing: .03em; font-weight: 600; }
.fin-value { font-size: 1.1rem; font-weight: 800; color: var(--bronze-text); margin-top: 2px; }
.fin-value.positive { color: var(--status-paid-bg); }
.fin-value.negative { color: var(--status-due-bg); }

/* ---- gold movement stat bar (reuse stat-bar pattern) ---- */
.stat-bar { display: grid; grid-template-columns: repeat(4, 1fr); }
.stat-cell {
    padding: 10px 12px; display: flex; align-items: center; gap: 8px;
    border-right: 1px solid var(--sc-border); background: var(--sc-card);
}
.stat-cell:last-child { border-right: none; }
.stat-cell .s-icon {
    width: 26px; height: 26px; min-width: 26px; border-radius: 50%;
    background: rgba(201,151,43,.09); display: flex; align-items: center; justify-content: center;
    font-size: .72rem; color: var(--sc-gold-dark);
}
.stat-cell .s-text { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.stat-cell .s-label {
    font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em;
    color: var(--sc-text-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.stat-cell .s-value { font-size: .92rem; font-weight: 700; color: var(--bronze-text); }

/* ---- two-column layout ---- */
.dash-row { display: grid; grid-template-columns: 1.4fr 1fr; gap: 1.25rem; }

/* ---- chart wrap ---- */
.chart-wrap { position: relative; height: 220px; }
.mini-chart-wrap { position: relative; height: 170px; }

/* ---- expense breakdown list ----- */
.eb-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--sc-border); }
.eb-row:last-child { border-bottom: none; }
.eb-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.eb-name { flex: 1; font-size: .85rem; font-weight: 600; color: var(--bronze-text); }
.eb-amount { font-size: .85rem; font-weight: 700; color: var(--sc-gold-dark); }
.eb-bar-track { height: 5px; border-radius: 999px; background: var(--sc-border); margin-top: 4px; overflow: hidden; }
.eb-bar-fill { height: 100%; border-radius: 999px; }

/* ---- due list ---- */
.due-row {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 9px 0; border-bottom: 1px solid var(--sc-border); text-decoration: none; color: inherit;
}
.due-row:last-child { border-bottom: none; }
.due-row:hover { background: #fdf7ec; }
.due-type-badge {
    font-size: .68rem; font-weight: 700; padding: 2px 8px; border-radius: 999px;
    text-transform: uppercase; letter-spacing: .02em;
}
.due-type-badge.buy  { background: var(--status-total-light); color: var(--status-total-bg); }
.due-type-badge.sale { background: var(--sc-gold-light); color: var(--sc-gold-dark); }
.due-name { font-size: .85rem; font-weight: 600; color: var(--bronze-text); }
.due-amount { font-size: .88rem; font-weight: 800; color: var(--status-due-bg); }

/* ---- recent transactions ---- */
.recent-row {
    display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--sc-border);
    text-decoration: none; color: inherit;
}
.recent-row:last-child { border-bottom: none; }
.recent-row:hover { background: #fdf7ec; }
.recent-icon {
    width: 38px; height: 38px; min-width: 38px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.recent-icon.exchange { background: #eef2ff; color: #3b4fce; }
.recent-icon.buy      { background: var(--status-total-light); color: var(--status-total-bg); }
.recent-icon.sale     { background: var(--status-paid-light); color: var(--status-paid-bg); }
.recent-icon.expense  { background: var(--status-due-light); color: var(--status-due-bg); }
.recent-body { flex: 1; min-width: 0; }
.recent-title { font-size: .87rem; font-weight: 700; color: var(--bronze-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.recent-sub { font-size: .74rem; color: var(--sc-text-2); }
.recent-right { text-align: right; flex-shrink: 0; }
.recent-amount { font-size: .87rem; font-weight: 700; color: var(--bronze-text); }
.recent-due { font-size: .7rem; color: var(--status-due-bg); font-weight: 600; }
.recent-type-tag {
    font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .02em;
    padding: 1px 7px; border-radius: 999px; display: inline-block; margin-bottom: 2px;
}
.recent-type-tag.exchange { background: #eef2ff; color: #3b4fce; }
.recent-type-tag.buy      { background: var(--status-total-light); color: var(--status-total-bg); }
.recent-type-tag.sale     { background: var(--status-paid-light); color: var(--status-paid-bg); }
.recent-type-tag.expense  { background: var(--status-due-light); color: var(--status-due-bg); }

.view-all-link { font-size: .8rem; font-weight: 700; color: var(--gold-deep); text-decoration: none; }
.view-all-link:hover { color: var(--sc-gold-dark); }

.empty-state { text-align: center; color: var(--sc-text-2); padding: 2rem 1rem; font-size: .88rem; }

.skel { background: linear-gradient(90deg, #f1efe8 25%, #f8f6f0 37%, #f1efe8 63%); background-size: 400% 100%; animation: skel 1.4s ease infinite; border-radius: 8px; }
@keyframes skel { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }

/* ── Mobile ── */
@media (max-width: 991.98px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .fin-grid { grid-template-columns: repeat(2, 1fr); }
    .fin-cell:nth-child(3n) { border-right: 1px solid var(--sc-border); }
    .fin-cell:nth-child(2n) { border-right: none; }
    .dash-row { grid-template-columns: 1fr; }
}
@media (max-width: 767.98px) {
    .page-inset { padding: 0 .8rem 1.5rem; }
    .dash-header { border-radius: 0 0 16px 16px; padding: .9rem 1.1rem; }
    .summary-grid { grid-template-columns: repeat(2, 1fr); gap: .7rem; }
    .stat-bar { grid-template-columns: repeat(2, 1fr); }
    .stat-cell:nth-child(2) { border-right: none; }
    .stat-cell:nth-child(3), .stat-cell:nth-child(4) { border-top: 1px solid var(--sc-border); }
    .quick-actions { gap: .5rem; }
    .qa-btn { flex: 1 1 45%; font-size: .8rem; padding: .6rem .7rem; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0">

    <div class="dash-header">
        <div>
            <h4><i class="bi bi-grid-1x2-fill me-2"></i>ড্যাশবোর্ড</h4>
            <small>ব্যবসায়িক কার্যক্রমের সংক্ষিপ্তসার</small>
        </div>
        <div class="period-pills" id="periodPills">
            <button type="button" class="period-pill active" data-period="today">আজ</button>
            <button type="button" class="period-pill" data-period="yesterday">গতকাল</button>
            <button type="button" class="period-pill" data-period="week">এই সপ্তাহ</button>
            <button type="button" class="period-pill" data-period="month">এই মাস</button>
            <button type="button" class="period-pill" data-period="custom">কাস্টম</button>
            <div class="custom-range-box" id="customRangeBox">
                <input type="date" id="customFrom">
                <span style="color:rgba(255,255,255,.7);">—</span>
                <input type="date" id="customTo">
            </div>
        </div>
    </div>

    <div class="page-inset py-4">

        <!-- Quick actions -->
        <div class="quick-actions">
            <a href="gold_exchange_inventory.php" class="qa-btn">
                <span class="qa-icon"><i class="bi bi-arrow-left-right"></i></span> নতুন সোনা বিনিময়
            </a>
            <a href="gold_buy.php" class="qa-btn">
                <span class="qa-icon"><i class="bi bi-bag-plus"></i></span> নতুন সোনা ক্রয়
            </a>
            <a href="gold_sale_inventory.php" class="qa-btn">
                <span class="qa-icon"><i class="bi bi-bag-check"></i></span> নতুন সোনা বিক্রয়
            </a>
            <a href="expenses.php" class="qa-btn">
                <span class="qa-icon"><i class="bi bi-wallet2"></i></span> নতুন খরচ
            </a>
        </div>

        <!-- Primary summary cards -->
        <div class="summary-grid" id="summaryGrid">
            <div class="summary-card">
                <div class="sc-top">
                    <span class="sc-title">সোনা বিনিময়</span>
                    <span class="sc-badge-icon"><i class="bi bi-arrow-left-right"></i></span>
                </div>
                <div class="sc-main-value" id="cardExCount">—</div>
                <div class="sc-sub-value" id="cardExSub">—</div>
            </div>
            <div class="summary-card">
                <div class="sc-top">
                    <span class="sc-title">সোনা ক্রয়</span>
                    <span class="sc-badge-icon"><i class="bi bi-bag-plus"></i></span>
                </div>
                <div class="sc-main-value" id="cardBuyAmt">—</div>
                <div class="sc-sub-value" id="cardBuySub">—</div>
            </div>
            <div class="summary-card">
                <div class="sc-top">
                    <span class="sc-title">সোনা বিক্রয়</span>
                    <span class="sc-badge-icon"><i class="bi bi-bag-check"></i></span>
                </div>
                <div class="sc-main-value" id="cardSaleAmt">—</div>
                <div class="sc-sub-value" id="cardSaleSub">—</div>
            </div>
            <div class="summary-card">
                <div class="sc-top">
                    <span class="sc-title">খরচ</span>
                    <span class="sc-badge-icon"><i class="bi bi-wallet2"></i></span>
                </div>
                <div class="sc-main-value" id="cardExpAmt">—</div>
                <div class="sc-sub-value" id="cardExpSub">—</div>
            </div>
        </div>

        <!-- Financial overview -->
        <div class="section-block">
            <div class="sc-card">
                <div class="sc-header">
                    <div class="sc-header-left">
                        <div class="sc-icon"><i class="bi bi-calculator"></i></div>
                        <p class="section-label">আর্থিক সারসংক্ষেপ</p>
                    </div>
                    <i class="bi bi-cash-coin sc-header-icon"></i>
                </div>
                <div class="fin-grid" id="finGrid">
                    <div class="fin-cell"><div class="fin-label">মোট বিক্রয়</div><div class="fin-value" id="finSaleTotal">—</div></div>
                    <div class="fin-cell"><div class="fin-label">মোট ক্রয়</div><div class="fin-value" id="finBuyTotal">—</div></div>
                    <div class="fin-cell"><div class="fin-label">মোট খরচ</div><div class="fin-value" id="finExpTotal">—</div></div>
                    <div class="fin-cell"><div class="fin-label">প্রাপ্ত টাকা (বিক্রয়)</div><div class="fin-value" id="finSalePaid">—</div></div>
                    <div class="fin-cell"><div class="fin-label">পরিশোধিত টাকা (ক্রয়)</div><div class="fin-value" id="finBuyPaid">—</div></div>
                    <div class="fin-cell"><div class="fin-label">নেট ক্যাশ প্রবাহ</div><div class="fin-value" id="finNetCash">—</div></div>
                </div>
            </div>
        </div>

        <!-- Gold movement -->
        <div class="section-block">
            <div class="sc-card">
                <div class="sc-header">
                    <div class="sc-header-left">
                        <div class="sc-icon"><i class="bi bi-gem"></i></div>
                        <p class="section-label">সোনা চলাচলের সারসংক্ষেপ</p>
                    </div>
                    <i class="bi bi-speedometer2 sc-header-icon"></i>
                </div>
                <div class="stat-bar">
                    <div class="stat-cell">
                        <div class="s-icon"><i class="bi bi-bag-plus"></i></div>
                        <div class="s-text"><span class="s-label">ক্রয়কৃত সোনা</span><span class="s-value" id="gmBuy">—</span></div>
                    </div>
                    <div class="stat-cell">
                        <div class="s-icon"><i class="bi bi-bag-check"></i></div>
                        <div class="s-text"><span class="s-label">বিক্রিত সোনা</span><span class="s-value" id="gmSale">—</span></div>
                    </div>
                    <div class="stat-cell">
                        <div class="s-icon"><i class="bi bi-arrow-left-right"></i></div>
                        <div class="s-text"><span class="s-label">বিনিময়কৃত সোনা</span><span class="s-value" id="gmExOld">—</span></div>
                    </div>
                    <div class="stat-cell">
                        <div class="s-icon"><i class="bi bi-bullseye"></i></div>
                        <div class="s-text"><span class="s-label">খাঁটি সোনা আউটপুট</span><span class="s-value" id="gmExFinal">—</span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Trend + Buy vs Sale -->
        <div class="dash-row section-block">
            <div class="sc-card">
                <div class="sc-header">
                    <div class="sc-header-left">
                        <div class="sc-icon"><i class="bi bi-graph-up"></i></div>
                        <p class="section-label">লেনদেনের প্রবণতা</p>
                    </div>
                    <span class="sc-header-icon" id="trendPeriodLabel"></span>
                </div>
                <div class="sc-body">
                    <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
                </div>
            </div>

            <div class="sc-card">
                <div class="sc-header">
                    <div class="sc-header-left">
                        <div class="sc-icon"><i class="bi bi-bar-chart"></i></div>
                        <p class="section-label">ক্রয় বনাম বিক্রয়</p>
                    </div>
                </div>
                <div class="sc-body">
                    <div class="mini-chart-wrap"><canvas id="bvsChart"></canvas></div>
                </div>
            </div>
        </div>

        <!-- Expense breakdown + Due -->
        <div class="dash-row section-block">
            <div class="sc-card">
                <div class="sc-header">
                    <div class="sc-header-left">
                        <div class="sc-icon"><i class="bi bi-pie-chart"></i></div>
                        <p class="section-label">খরচের সারসংক্ষেপ</p>
                    </div>
                    <a href="expenses.php" class="view-all-link">সব দেখুন</a>
                </div>
                <div class="sc-body" id="expenseBreakdownBody">
                    <div class="skel" style="height:16px;margin-bottom:10px;"></div>
                    <div class="skel" style="height:16px;margin-bottom:10px;"></div>
                    <div class="skel" style="height:16px;"></div>
                </div>
            </div>

            <div class="sc-card">
                <div class="sc-header">
                    <div class="sc-header-left">
                        <div class="sc-icon"><i class="bi bi-file-earmark-text"></i></div>
                        <p class="section-label">বকেয়া</p>
                    </div>
                    <span class="sc-header-icon" id="dueSummaryLabel"></span>
                </div>
                <div class="sc-body" id="dueBody">
                    <div class="skel" style="height:16px;margin-bottom:10px;"></div>
                    <div class="skel" style="height:16px;margin-bottom:10px;"></div>
                    <div class="skel" style="height:16px;"></div>
                </div>
            </div>
        </div>

        <!-- Recent transactions -->
        <div class="section-block">
            <div class="sc-card">
                <div class="sc-header">
                    <div class="sc-header-left">
                        <div class="sc-icon"><i class="bi bi-clock-history"></i></div>
                        <p class="section-label">সাম্প্রতিক লেনদেন</p>
                    </div>
                </div>
                <div class="sc-body" id="recentBody">
                    <div class="skel" style="height:44px;margin-bottom:10px;"></div>
                    <div class="skel" style="height:44px;margin-bottom:10px;"></div>
                    <div class="skel" style="height:44px;margin-bottom:10px;"></div>
                    <div class="skel" style="height:44px;"></div>
                </div>
            </div>
        </div>

    </div>
</div>
</div>

<script>
'use strict';

// ── Unit conversion (identical to other modules) ──
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
function fmtBDTSigned(n) {
    const v = parseFloat(n) || 0;
    const sign = v < 0 ? '-' : '';
    return sign + '৳' + Math.round(Math.abs(v)).toLocaleString('bn-BD');
}
function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(s.replace(' ', 'T'));
    return d.toLocaleDateString('bn-BD', { day: '2-digit', month: 'short', year: 'numeric' });
}
function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

const CAT_COLORS = ['#c9973a', '#2f4fce', '#2e8b47', '#8e44ad', '#d68910', '#16a085', '#c2185b', '#93292c'];

let trendChart = null;
let bvsChart   = null;
let currentPeriod = 'today';
let customFrom = '';
let customTo   = '';

function localDateStr(d) {
    const y  = d.getFullYear();
    const m  = String(d.getMonth() + 1).padStart(2, '0');
    const dy = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${dy}`;
}
(function initCustomRange() {
    const today = localDateStr(new Date());
    document.getElementById('customFrom').value = today;
    document.getElementById('customTo').value   = today;
    customFrom = today; customTo = today;
})();

// ── Period pill handling ──
document.querySelectorAll('.period-pill').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.period-pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentPeriod = btn.dataset.period;
        const customBox = document.getElementById('customRangeBox');
        if (currentPeriod === 'custom') {
            customBox.classList.add('show');
        } else {
            customBox.classList.remove('show');
        }
        loadDashboard();
    });
});
document.getElementById('customFrom').addEventListener('change', function () { customFrom = this.value; if (currentPeriod === 'custom') loadDashboard(); });
document.getElementById('customTo').addEventListener('change', function () { customTo = this.value; if (currentPeriod === 'custom') loadDashboard(); });

// ── Load everything ──
async function loadDashboard() {
    const params = new URLSearchParams({ action: 'data', period: currentPeriod });
    if (currentPeriod === 'custom') {
        params.set('from', customFrom);
        params.set('to', customTo);
    }
    try {
        const res  = await fetch('dashboard.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        if (!data.success) return;
        renderCards(data);
        renderFinancial(data.financial);
        renderGoldMovement(data.gold_movement);
        renderTrend(data.trend, data.period.label);
        renderBuyVsSale(data.buy_vs_sale);
        renderExpenseBreakdown(data.expense_breakdown);
        renderDue(data.due);
        renderRecent(data.recent);
    } catch (e) {
        console.error(e);
    }
}

function renderCards(d) {
    const ex = d.cards.exchange, buy = d.cards.buy, sale = d.cards.sale, exp = d.cards.expense;

    document.getElementById('cardExCount').textContent = ex.count + ' টি';
    document.getElementById('cardExSub').textContent    = ex.count > 0
        ? `আউটপুট: ${fmtTrad(ex.final_weight)}` : 'কোনো এক্সচেঞ্জ নেই';

    document.getElementById('cardBuyAmt').textContent = fmtBDT(buy.amount);
    document.getElementById('cardBuySub').textContent = buy.count > 0
        ? `${buy.count} টি · ${fmtTrad(buy.weight)}` : 'কোনো ক্রয় নেই';

    document.getElementById('cardSaleAmt').textContent = fmtBDT(sale.amount);
    document.getElementById('cardSaleSub').textContent = sale.count > 0
        ? `${sale.count} টি · ${fmtTrad(sale.weight)}` : 'কোনো বিক্রয় নেই';

    document.getElementById('cardExpAmt').textContent = fmtBDT(exp.amount);
    document.getElementById('cardExpSub').textContent = exp.count > 0
        ? `${exp.count} টি লেনদেন` : 'কোনো খরচ নেই';
}

function renderFinancial(f) {
    document.getElementById('finSaleTotal').textContent = fmtBDT(f.sale_total);
    document.getElementById('finBuyTotal').textContent  = fmtBDT(f.buy_total);
    document.getElementById('finExpTotal').textContent  = fmtBDT(f.expense_total);
    document.getElementById('finSalePaid').textContent  = fmtBDT(f.sale_paid);
    document.getElementById('finBuyPaid').textContent   = fmtBDT(f.buy_paid);
    const netEl = document.getElementById('finNetCash');
    netEl.textContent = fmtBDTSigned(f.net_cash_flow);
    netEl.className = 'fin-value ' + (f.net_cash_flow >= 0 ? 'positive' : 'negative');
}

function renderGoldMovement(g) {
    document.getElementById('gmBuy').textContent    = fmtTrad(g.buy_weight);
    document.getElementById('gmSale').textContent   = fmtTrad(g.sale_weight);
    document.getElementById('gmExOld').textContent  = fmtTrad(g.exchange_old_weight);
    document.getElementById('gmExFinal').textContent = fmtTrad(g.exchange_final_weight);
}

function renderTrend(trend, label) {
    document.getElementById('trendPeriodLabel').textContent = label;
    const ctx = document.getElementById('trendChart').getContext('2d');
    const labels = trend.map(t => {
        const d = new Date(t.date + 'T00:00:00');
        return d.toLocaleDateString('bn-BD', { day: '2-digit', month: 'short' });
    });
    const datasets = [
        { label: 'ক্রয়', data: trend.map(t => t.buy), borderColor: CAT_COLORS[1], backgroundColor: CAT_COLORS[1], tension: .3 },
        { label: 'বিক্রয়', data: trend.map(t => t.sale), borderColor: CAT_COLORS[2], backgroundColor: CAT_COLORS[2], tension: .3 },
        { label: 'এক্সচেঞ্জ', data: trend.map(t => t.exchange), borderColor: CAT_COLORS[0], backgroundColor: CAT_COLORS[0], tension: .3 },
        { label: 'খরচ', data: trend.map(t => t.expense), borderColor: CAT_COLORS[7], backgroundColor: CAT_COLORS[7], tension: .3 },
    ];
    if (trendChart) trendChart.destroy();
    const isMobile = window.innerWidth < 768;
    trendChart = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: isMobile ? 8 : 10 }, maxRotation: isMobile ? 60 : 0, autoSkip: true, maxTicksLimit: isMobile ? 6 : 14 } },
                y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } }, grid: { color: '#eef0f3', borderDash: [3,3] } },
            },
        },
    });
}

function renderBuyVsSale(b) {
    const ctx = document.getElementById('bvsChart').getContext('2d');
    if (bvsChart) bvsChart.destroy();
    bvsChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['পরিমাণ (৳)', 'লেনদেন সংখ্যা'],
            datasets: [
                { label: 'ক্রয়', data: [b.buy_amount, b.buy_count], backgroundColor: CAT_COLORS[1], borderRadius: 4, maxBarThickness: 42 },
                { label: 'বিক্রয়', data: [b.sale_amount, b.sale_count], backgroundColor: CAT_COLORS[2], borderRadius: 4, maxBarThickness: 42 },
            ],
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            if (ctx.dataIndex === 0) return ctx.dataset.label + ': ' + fmtBDT(ctx.raw);
                            return ctx.dataset.label + ': ' + ctx.raw + ' টি';
                        },
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: { beginAtZero: true, ticks: { display: false }, grid: { color: '#eef0f3', borderDash: [3,3] } },
            },
        },
    });
}

function renderExpenseBreakdown(rows) {
    const el = document.getElementById('expenseBreakdownBody');
    if (!rows || rows.length === 0) {
        el.innerHTML = '<div class="empty-state"><i class="bi bi-inbox fs-3 d-block mb-2"></i>কোনো খরচ পাওয়া যায়নি</div>';
        return;
    }
    const max = Math.max(...rows.map(r => parseFloat(r.total) || 0));
    el.innerHTML = rows.map((r, i) => {
        const color = CAT_COLORS[i % CAT_COLORS.length];
        const pct = max > 0 ? Math.max(4, (parseFloat(r.total) / max) * 100) : 0;
        return `
        <div class="eb-row" style="display:block;">
            <div style="display:flex;align-items:center;gap:10px;">
                <span class="eb-dot" style="background:${color}"></span>
                <span class="eb-name">${escHtml(r.category)}</span>
                <span class="eb-amount">${fmtBDT(r.total)}</span>
            </div>
            <div class="eb-bar-track"><div class="eb-bar-fill" style="width:${pct}%;background:${color};"></div></div>
        </div>`;
    }).join('');
}

function renderDue(d) {
    document.getElementById('dueSummaryLabel').textContent = 'সর্বমোট: ' + fmtBDT(d.total);
    const el = document.getElementById('dueBody');
    if (!d.top || d.top.length === 0) {
        el.innerHTML = '<div class="empty-state"><i class="bi bi-check-circle fs-3 d-block mb-2"></i>কোনো বকেয়া নেই</div>';
        return;
    }
    const summaryLine = `
        <div class="d-flex justify-content-between align-items-center mb-2 pb-2" style="border-bottom:1px solid var(--sc-border);">
            <span class="fw-semibold small text-muted">মোট বকেয়া লেনদেন</span>
            <span class="fw-bold" style="color:var(--status-due-bg);">${d.count} টি</span>
        </div>`;
    el.innerHTML = summaryLine + d.top.map(r => {
        const page = r.type === 'buy' ? 'gold_buy_edit.php' : 'gold_sale_edit_inventory.php';
        const typeLabel = r.type === 'buy' ? 'ক্রয়' : 'বিক্রয়';
        return `
        <a href="${page}?id=${r.id}" class="due-row">
            <span class="due-type-badge ${r.type}">${typeLabel}</span>
            <span class="due-name flex-grow-1 mx-2">${escHtml(r.customer_name)}</span>
            <span class="due-amount">${fmtBDT(r.due_amount)}</span>
        </a>`;
    }).join('');
}

const RECENT_ICON = { exchange: 'bi-arrow-left-right', buy: 'bi-bag-plus', sale: 'bi-bag-check', expense: 'bi-wallet2' };
const RECENT_LABEL = { exchange: 'বিনিময়', buy: 'ক্রয়', sale: 'বিক্রয়', expense: 'খরচ' };
const RECENT_PAGE = { exchange: 'gold_exchange_edit_inventory.php', buy: 'gold_buy_edit.php', sale: 'gold_sale_edit_inventory.php', expense: null };

function renderRecent(rows) {
    const el = document.getElementById('recentBody');
    if (!rows || rows.length === 0) {
        el.innerHTML = '<div class="empty-state"><i class="bi bi-clock-history fs-3 d-block mb-2"></i>কোনো লেনদেন পাওয়া যায়নি</div>';
        return;
    }
    el.innerHTML = rows.map(r => {
        const page = RECENT_PAGE[r.type];
        const href = page ? `${page}?id=${r.id}` : 'expenses.php';
        const tag  = `<span class="recent-type-tag ${r.type}">${RECENT_LABEL[r.type]}</span>`;

        let rightMain, rightSub = '';
        if (r.type === 'exchange') {
            rightMain = fmtTrad(r.weight);
        } else if (r.type === 'expense') {
            rightMain = fmtBDT(r.amount);
        } else {
            rightMain = fmtBDT(r.amount);
            if (r.due > 0.009) rightSub = `বকেয়া ${fmtBDT(r.due)}`;
        }

        const subtitle = r.type === 'expense'
            ? (r.subtitle ? escHtml(r.subtitle) : fmtDate(r.date))
            : fmtDate(r.date);

        return `
        <a href="${href}" class="recent-row">
            <span class="recent-icon ${r.type}"><i class="bi ${RECENT_ICON[r.type]}"></i></span>
            <div class="recent-body">
                <div class="recent-title">${tag}<br>${escHtml(r.title)}</div>
                <div class="recent-sub">${subtitle}</div>
            </div>
            <div class="recent-right">
                <div class="recent-amount">${rightMain}</div>
                ${rightSub ? `<div class="recent-due">${rightSub}</div>` : ''}
            </div>
        </a>`;
    }).join('');
}

let resizeTimer = null;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(loadDashboard, 300);
});

loadDashboard();
</script>

</body>
</html>