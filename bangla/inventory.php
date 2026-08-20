<?php
/**
 * inventory.php
 * FineBullion Desk — Inventory (karat-wise gold stock)
 *
 * Model:
 *   stock_in  -> historical additions of usable karat-wise gold
 *   inventory -> cumulative total_weight + current left_weight per karat
 *
 *   stock_in       : total_weight += X, left_weight += X
 *   gold_sale      : left_weight -= X   (total_weight unchanged)
 *   gold_exchange  : 24K left_weight -= X (total_weight unchanged)
 *
 * weight  DECIMAL(12,3) grams
 * purity  DECIMAL(5,2)
 */

require_once __DIR__ . '/auth.php';

// -----------------------------------------------------------------------
// Conversion constants (grams) — identical to other modules
// -----------------------------------------------------------------------
const G_PER_VORI  = 11.664;
const G_PER_ANA   = 0.729;
const G_PER_ROTI  = 0.1215;
const G_PER_POINT = 0.01215;

const KARATS = [18.00, 20.00, 21.00, 22.00, 24.00];

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

function traditional_to_grams(int $vori, int $ana, int $roti, int $point): float
{
    return ($vori * G_PER_VORI)
         + ($ana  * G_PER_ANA)
         + ($roti * G_PER_ROTI)
         + ($point * G_PER_POINT);
}

function grams_to_traditional(float $grams): array
{
    $grams = max(0.0, $grams);
    $EPS = 1e-9;
    $totalVori = $grams / G_PER_VORI;

    $vori = (int) floor($totalVori + $EPS);
    $fracVori = max(0.0, $totalVori - $vori);

    $totalAna = $fracVori * 16;
    $ana = (int) floor($totalAna + $EPS);
    $fracAna = max(0.0, $totalAna - $ana);

    $totalRoti = $fracAna * 6;
    $roti = (int) floor($totalRoti + $EPS);
    $fracRoti = max(0.0, $totalRoti - $roti);

    $point = (int) round($fracRoti * 10);

    if ($point >= 10) { $point -= 10; $roti += 1; }
    if ($roti >= 6)   { $roti -= 6;   $ana += 1; }
    if ($ana >= 16)   { $ana -= 16;   $vori += 1; }

    return ['vori' => $vori, 'ana' => $ana, 'roti' => $roti, 'point' => $point];
}

function format_traditional(array $t): string
{
    return "{$t['vori']} ভরি {$t['ana']} আনা {$t['roti']} রতি {$t['point']} পয়েন্ট";
}

function fmt_karat(float $p): string
{
    $rounded = rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.');
    return $rounded . 'K';
}

function ensure_inventory_rows(mysqli $conn): void
{
    $stmt = mysqli_prepare($conn,
        "INSERT INTO inventory (purity, total_weight, left_weight, minimum_stock)
         VALUES (?, 0.000, 0.000, 0.000)
         ON DUPLICATE KEY UPDATE purity = purity");
    foreach (KARATS as $k) {
        mysqli_stmt_bind_param($stmt, 'd', $k);
        mysqli_stmt_execute($stmt);
    }
}

// =========================================================================
// AJAX actions
// =========================================================================
if ($isAjax || $action !== null) {

    if ($action === 'data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        ensure_inventory_rows($conn);

        $from = trim($_GET['from'] ?? '');
        $to   = trim($_GET['to']   ?? '');
        $today = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $today;
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        $res = mysqli_query($conn, "SELECT purity, total_weight, left_weight, minimum_stock FROM inventory ORDER BY purity ASC");
        $invRows = mysqli_fetch_all($res, MYSQLI_ASSOC);

        $sold = [];
        $r = mysqli_query($conn,
            "SELECT purity, COALESCE(SUM(weight),0) wt
             FROM gold_sale_items GROUP BY purity");
        if ($r) { while ($row = mysqli_fetch_assoc($r)) $sold[(string)round((float)$row['purity'],2)] = (float)$row['wt']; }

        $exchanged24 = 0.0;
        $r = mysqli_query($conn, "SELECT COALESCE(SUM(final_pure_gold),0) wt FROM gold_exchanges");
        if ($r) { $row = mysqli_fetch_assoc($r); $exchanged24 = (float)$row['wt']; }

        $cards = [];
        $totalStockIn = 0.0;
        $totalLeft = 0.0;
        $lowStockCount = 0;
        foreach ($invRows as $row) {
            $purity = (float)$row['purity'];
            $totalW = (float)$row['total_weight'];
            $leftW  = (float)$row['left_weight'];
            $minW   = (float)$row['minimum_stock'];
            $key    = (string)round($purity, 2);
            $soldW  = $sold[$key] ?? 0.0;
            $exchW  = (abs($purity - 24.00) < 0.001) ? $exchanged24 : 0.0;
            $usedW  = $soldW + $exchW;
            $isLow  = $minW > 0 && $leftW < $minW;
            if ($isLow) $lowStockCount++;

            $totalStockIn += $totalW;
            $totalLeft    += $leftW;

            $cards[] = [
                'purity'        => $purity,
                'purity_label'  => fmt_karat($purity),
                'total_weight'  => $totalW,
                'left_weight'   => $leftW,
                'minimum_stock' => $minW,
                'used_weight'   => $usedW,
                'sold_weight'   => $soldW,
                'exchanged_weight' => $exchW,
                'total_trad'    => format_traditional(grams_to_traditional($totalW)),
                'left_trad'     => format_traditional(grams_to_traditional($leftW)),
                'used_trad'     => format_traditional(grams_to_traditional($usedW)),
                'min_trad'      => format_traditional(grams_to_traditional($minW)),
                'is_low'        => $isLow,
            ];
        }

        $stmt = mysqli_prepare($conn,
            "SELECT COALESCE(SUM(weight),0) wt, COUNT(*) cnt FROM stock_in WHERE DATE(created_at) BETWEEN ? AND ?");
        mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
        mysqli_stmt_execute($stmt);
        $periodStockIn = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        $stmt = mysqli_prepare($conn,
            "SELECT COALESCE(SUM(gsi.weight),0) wt
             FROM gold_sale_items gsi
             JOIN gold_sales gs ON gs.id = gsi.gold_sale_id
             WHERE DATE(gs.created_at) BETWEEN ? AND ?");
        mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
        mysqli_stmt_execute($stmt);
        $periodSold = (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['wt'] ?? 0);

        $stmt = mysqli_prepare($conn,
            "SELECT COALESCE(SUM(final_pure_gold),0) wt
             FROM gold_exchanges WHERE DATE(created_at) BETWEEN ? AND ?");
        mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
        mysqli_stmt_execute($stmt);
        $periodExchanged = (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['wt'] ?? 0);

        $stmt = mysqli_prepare($conn,
            "SELECT si.id, si.purity, si.weight, si.note, si.created_at, u.username AS user_name
             FROM stock_in si
             LEFT JOIN users u ON u.id = si.created_by
             WHERE DATE(si.created_at) BETWEEN ? AND ?
             ORDER BY si.created_at DESC, si.id DESC LIMIT 500");
        mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
        mysqli_stmt_execute($stmt);
        $recentRes = mysqli_stmt_get_result($stmt);
        $recent = [];
        while ($row = mysqli_fetch_assoc($recentRes)) {
            $recent[] = [
                'id'         => (int)$row['id'],
                'purity'     => (float)$row['purity'],
                'purity_label' => fmt_karat((float)$row['purity']),
                'weight'     => (float)$row['weight'],
                'weight_trad'=> format_traditional(grams_to_traditional((float)$row['weight'])),
                'note'       => $row['note'],
                'created_at' => $row['created_at'],
                'user_name'  => $row['user_name'] ?? '—',
            ];
        }

        json_out([
            'success' => true,
            'period'  => ['from' => $from, 'to' => $to],
            'summary' => [
                'total_stock_in' => $totalStockIn,
                'total_stock_in_trad' => format_traditional(grams_to_traditional($totalStockIn)),
                'total_left'     => $totalLeft,
                'total_left_trad'=> format_traditional(grams_to_traditional($totalLeft)),
                'period_stock_in'      => (float)$periodStockIn['wt'],
                'period_stock_in_trad' => format_traditional(grams_to_traditional((float)$periodStockIn['wt'])),
                'period_stock_in_count'=> (int)$periodStockIn['cnt'],
                'period_sold'      => $periodSold,
                'period_sold_trad' => format_traditional(grams_to_traditional($periodSold)),
                'period_exchanged'      => $periodExchanged,
                'period_exchanged_trad' => format_traditional(grams_to_traditional($periodExchanged)),
                'low_stock_count' => $lowStockCount,
            ],
            'cards'   => $cards,
            'recent'  => $recent,
        ]);
    }

    if ($action === 'stock_in' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        ensure_inventory_rows($conn);

        $purity = (float)($_POST['purity'] ?? 0);
        $note   = trim($_POST['note'] ?? '') ?: null;
        $vori   = (int)($_POST['vori']  ?? 0);
        $ana    = (int)($_POST['ana']   ?? 0);
        $roti   = (int)($_POST['roti']  ?? 0);
        $point  = (int)($_POST['point'] ?? 0);

        $errors = [];
        if (!in_array(round($purity, 2), KARATS, true)) {
            $errors['purity'] = 'সঠিক ক্যারেট নির্বাচন করুন।';
        }
        if ($vori < 0)              $errors['vori']  = 'সঠিক মান দিন।';
        if ($ana  < 0 || $ana  > 15) $errors['ana']   = 'আনা 0–15 এর মধ্যে হতে হবে।';
        if ($roti < 0 || $roti > 5)  $errors['roti']  = 'রতি 0–5 এর মধ্যে হতে হবে।';
        if ($point < 0 || $point > 9) $errors['point'] = 'পয়েন্ট 0–9 এর মধ্যে হতে হবে।';

        $weight = traditional_to_grams($vori, $ana, $roti, $point);
        if ($weight <= 0) {
            $errors['weight'] = 'মোট ওজন শূন্যের বেশি হতে হবে।';
        }

        if (!empty($errors)) {
            json_out(['success' => false, 'message' => 'তথ্য যাচাই ব্যর্থ হয়েছে।', 'errors' => $errors], 422);
        }

        $userId = $currentUser['id'];

        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO stock_in (purity, weight, note, created_by) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'ddsi', $purity, $weight, $note, $userId);
            mysqli_stmt_execute($stmt);
            $stockInId = (int) mysqli_insert_id($conn);

            $stmt = mysqli_prepare($conn,
                "UPDATE inventory SET total_weight = total_weight + ?, left_weight = left_weight + ?
                 WHERE purity = ?");
            mysqli_stmt_bind_param($stmt, 'ddd', $weight, $weight, $purity);
            mysqli_stmt_execute($stmt);

            mysqli_commit($conn);
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            json_out(['success' => false, 'message' => 'স্টক যোগ করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।'], 500);
        }

        json_out([
            'success' => true,
            'message' => 'স্টক সফলভাবে যোগ করা হয়েছে।',
            'id'      => $stockInId,
            'weight_trad' => format_traditional(grams_to_traditional($weight)),
        ]);
    }

    if ($action === 'set_minimum' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        ensure_inventory_rows($conn);

        $purity = (float)($_POST['purity'] ?? 0);
        $vori   = (int)($_POST['vori']  ?? 0);
        $ana    = (int)($_POST['ana']   ?? 0);
        $roti   = (int)($_POST['roti']  ?? 0);
        $point  = (int)($_POST['point'] ?? 0);

        if (!in_array(round($purity, 2), KARATS, true)) {
            json_out(['success' => false, 'message' => 'সঠিক ক্যারেট নির্বাচন করুন।'], 422);
        }
        if ($vori < 0 || $ana < 0 || $ana > 15 || $roti < 0 || $roti > 5 || $point < 0 || $point > 9) {
            json_out(['success' => false, 'message' => 'সঠিক ওজন দিন।'], 422);
        }

        $minWeight = traditional_to_grams($vori, $ana, $roti, $point);

        $stmt = mysqli_prepare($conn, "UPDATE inventory SET minimum_stock = ? WHERE purity = ?");
        mysqli_stmt_bind_param($stmt, 'dd', $minWeight, $purity);
        mysqli_stmt_execute($stmt);

        json_out([
            'success' => true,
            'message' => 'সর্বনিম্ন মজুদ আপডেট হয়েছে।',
            'minimum_trad' => format_traditional(grams_to_traditional($minWeight)),
        ]);
    }

    if ($action === 'delete_stock_in' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'অকার্যকর আইডি।'], 400);

        $stmt = mysqli_prepare($conn, "SELECT purity, weight FROM stock_in WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$row) json_out(['success' => false, 'message' => 'রেকর্ড পাওয়া যায়নি।'], 404);

        $purity = (float)$row['purity'];
        $weight = (float)$row['weight'];

        $stmt = mysqli_prepare($conn, "SELECT left_weight FROM inventory WHERE purity = ?");
        mysqli_stmt_bind_param($stmt, 'd', $purity);
        mysqli_stmt_execute($stmt);
        $invRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $currentLeft = (float)($invRow['left_weight'] ?? 0);

        if ($currentLeft - $weight < -0.0005) {
            json_out([
                'success' => false,
                'message' => 'এই স্টক ইন বাতিল করা যাবে না — এই স্টক ইতিমধ্যে বিক্রয়/বিনিময়ে ব্যবহৃত হয়েছে।',
            ], 409);
        }

        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn,
                "UPDATE inventory SET total_weight = total_weight - ?, left_weight = left_weight - ?
                 WHERE purity = ?");
            mysqli_stmt_bind_param($stmt, 'ddd', $weight, $weight, $purity);
            mysqli_stmt_execute($stmt);

            $stmt = mysqli_prepare($conn, "DELETE FROM stock_in WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);

            mysqli_commit($conn);
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            json_out(['success' => false, 'message' => 'বাতিল করতে ব্যর্থ হয়েছে।'], 500);
        }

        json_out(['success' => true, 'message' => 'স্টক ইন এন্ট্রি বাতিল করা হয়েছে।']);
    }

    json_out(['success' => false, 'message' => 'অজানা অ্যাকশন।'], 400);
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>ইনভেন্টরি — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Bengali:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
}

html, body {
    margin: 0 !important;
    padding: 0 !important;
    background: var(--ivory);
    font-family: 'Noto Sans Bengali', 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--bronze-text);
}
.page-content { margin-top: 0 !important; padding-top: 0 !important; }
.page-content > .container-fluid:first-child { margin-top: 0 !important; padding-top: 0 !important; }

/* Header */
.inv-header,
.inv-header.d-flex {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%) !important;
    color: #ffffff !important;
    min-height: 60px !important;
    max-height: 80px !important;
    padding: 0.85rem 1.75rem !important;
    margin: 0 0 1.5rem 0 !important;
    width: 100%; max-width: 100%;
    border-top-left-radius: 0 !important; border-top-right-radius: 0 !important;
    border-bottom-left-radius: 20px !important; border-bottom-right-radius: 20px !important;
    box-shadow: 0 6px 24px rgba(201, 151, 58, 0.22);
    box-sizing: border-box;
    display: flex !important; align-items: center !important; justify-content: space-between !important;
    flex-wrap: nowrap !important; gap: 1rem !important; overflow: hidden;
}
.inv-header h4 {
    color: #ffffff !important; font-weight: 800; letter-spacing: 0.02em; margin: 0 !important;
    font-size: 1.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.inv-header i { color: #ffffff !important; }
.inv-header .btn-history {
    border-color: rgba(255, 255, 255, 0.6); color: #ffffff; border-radius: 999px;
    font-weight: 600; flex-shrink: 0;
}
.inv-header .btn-history:hover { background: rgba(255, 255, 255, 0.2); color: #ffffff; }

.page-inset { padding: 0 1.5rem 2rem; }

/* Date range filter */
.date-filter-bar {
    background: #ffffff;
    border: 1px solid var(--hairline);
    border-radius: 14px;
    padding: .8rem 1rem;
    margin: 0 0 1.25rem;
}
.date-filter-bar .dfb-row {
    display: flex;
    align-items: end;
    flex-wrap: nowrap;
    gap: .4rem;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}
.date-filter-bar .dfb-row::-webkit-scrollbar { display: none; }
.date-filter-bar .dfb-field { display: flex; flex-direction: column; gap: .25rem; flex: 0 0 auto; }
.date-filter-bar .dfb-field label {
    font-size: .7rem; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: .02em; white-space: nowrap;
}
.date-filter-bar .form-control { 
    width: 128px; 
    min-width: 128px; 
    padding: .25rem .4rem; 
    font-size: .82rem; 
    flex: 0 0 auto; 
}
.date-filter-bar .btn { border-radius: 10px; font-weight: 600; white-space: nowrap; flex: 0 0 auto; }
.date-filter-bar .dfb-period {
    display: inline-block;
    margin-top: .6rem;
    font-size: .8rem;
    font-weight: 600;
    color: var(--gold-deep);
    background: #fdf6e2;
    padding: .3rem .7rem;
    border-radius: 999px;
}
@media (max-width: 767.98px) {
    .date-filter-bar { padding: .7rem .8rem; margin: 0 0 1rem; }
    .date-filter-bar .form-control { 
        width: 112px; 
        min-width: 112px; 
        font-size: .75rem; 
        padding: .2rem .3rem; 
    }
    .date-filter-bar .dfb-period { width: 100%; text-align: center; }
}

/* Summary cards */
.summary-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 1rem; margin-bottom: 1.25rem; }
.summary-card {
    background: #fff; border: 1px solid var(--hairline); border-radius: 14px;
    box-shadow: 0 2px 8px rgba(37,37,37,.03); padding: 1rem 1.1rem;
}
.summary-card .sc-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: .6rem; }
.summary-card .sc-title { font-size: .74rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .03em; }
.summary-card .sc-badge-icon {
    width: 34px; height: 34px; border-radius: 10px; background: var(--status-total-light);
    color: var(--gold-deep); display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.summary-card.low .sc-badge-icon { background: var(--status-due-light); color: var(--status-due-bg); }
.summary-card .sc-main-value { font-size: 1.2rem; font-weight: 800; color: var(--bronze-text); line-height: 1.2; }
.summary-card .sc-sub-value { font-size: .76rem; color: var(--muted); margin-top: .15rem; }
.summary-card.low .sc-main-value { color: var(--status-due-bg); }

/* Section card shell */
.card { background: #ffffff; border: none; border-radius: 18px; box-shadow: 0 10px 30px rgba(180, 140, 50, 0.12); }
.card-header { background: var(--ivory) !important; border-bottom: 1px solid var(--hairline); border-radius: 18px 18px 0 0 !important; color: var(--bronze-text); }

.karat-grid {
    display: grid; grid-template-columns: repeat(5, 1fr); gap: .7rem; margin-bottom: 1.25rem;
}
.karat-card {
    background: #fff; border: 1.5px solid var(--hairline); border-radius: 14px;
    box-shadow: 0 6px 18px rgba(180, 140, 50, 0.06); overflow: hidden;
    padding: .65rem .75rem;
}
.karat-card.low { border-color: var(--status-due-bg); box-shadow: 0 6px 18px rgba(147,41,44,0.1); }

.kc-summary { display: none !important; }

.kc-details-inner {
    display: flex; flex-direction: column; padding: 0;
}
.kc-header-row {
    display: flex; align-items: center; justify-content: space-between; gap: .4rem;
    margin-bottom: .4rem;
}
.karat-card .kc-badge {
    font-size: .78rem; font-weight: 800; color: #fff; background: var(--gold-deep);
    border-radius: 8px; padding: .28rem .55rem; display: inline-flex; align-items: center; gap: .3rem;
    flex-shrink: 0;
}
.karat-card.low .kc-badge { background: var(--status-due-bg); }

.kc-mobile-plus {
    width: 26px; height: 26px; border-radius: 8px; border: none;
    background: var(--status-total-light); color: var(--gold-deep);
    display: flex; align-items: center; justify-content: center; font-size: .85rem;
    flex-shrink: 0;
}
.kc-mobile-plus:hover { opacity: .85; }

.kc-mobile-rows { display: flex; flex-direction: column; }
.kc-row { display: flex; justify-content: space-between; align-items: center; padding: .3rem 0; border-top: 1px dashed var(--hairline); }
.kc-row:first-child { border-top: none; padding-top: 0; }
.kc-row .kc-label { font-size: .68rem; color: var(--muted); display: flex; align-items: center; gap: .3rem; }
.kc-row .kc-label i { font-size: .62rem; }
.kc-row .kc-value { font-size: .72rem; font-weight: 700; }
.kc-row.kc-total .kc-value { color: var(--bronze-text); }
.kc-row.kc-used .kc-value { color: var(--status-due-bg); }
.kc-row.kc-min .kc-value { color: var(--muted); }
.kc-row.kc-current .kc-value { color: var(--status-paid-bg); }
.karat-card.low .kc-row.kc-current .kc-value { color: var(--status-due-bg); }

.karat-card .kc-min-btn {
    width: 100%; margin-top: .5rem; border: 1.5px dashed var(--hairline); background: transparent;
    color: var(--muted); font-size: .66rem; font-weight: 600; border-radius: 999px; padding: .3rem;
}
.karat-card .kc-min-btn:hover { border-color: var(--gold-deep); color: var(--gold-deep); }
.karat-card .kc-stock-btn { display: none; }

/* Buttons */
.btn-gold, .btn-fb-primary {
    background: var(--gold-deep); border: 1.5px solid var(--gold-deep); color: #ffffff;
    font-weight: 700; border-radius: 999px;
}
.btn-gold:hover, .btn-gold:focus, .btn-fb-primary:hover, .btn-fb-primary:focus {
    background: var(--gold-deep); border-color: var(--gold-deep); color: #ffffff; opacity: 0.92;
}
.btn-fb-secondary { background: #ffffff; border: 1.5px solid var(--hairline); color: var(--muted); font-weight: 600; border-radius: 999px; }
.btn-fb-secondary:hover { background: #fdf7ec; border-color: var(--hairline); color: var(--bronze-text); }

.form-control, .form-select {
    border: 1.5px solid var(--hairline); border-radius: 10px; color: var(--bronze-text); background: #ffffff;
}
.form-control:focus, .form-select:focus { border-color: var(--gold-deep); box-shadow: 0 0 0 0.15rem rgba(201, 151, 58, 0.18); }

/* Weight fields row */
.item-fields-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; }
.item-fields-row .field-col label { display: block; font-size: 0.72rem; margin-bottom: 0.15rem; color: var(--muted); white-space: nowrap; }
.item-fields-row .field-col input { text-align: center; padding-left: 0.25rem; padding-right: 0.25rem; }

/* History table */
.history-table { width: 100%; border-collapse: collapse; }
.history-table th {
    text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; color: var(--muted);
    font-weight: 700; padding: .6rem .8rem; border-bottom: 1.5px solid var(--hairline); white-space: nowrap;
}
.history-table td { padding: .65rem .8rem; border-bottom: 1px solid var(--hairline); font-size: .85rem; vertical-align: middle; }
.history-table tr:last-child td { border-bottom: none; }
.history-table tr:hover td { background: #fdf7ec; }
.history-karat-badge {
    display: inline-block; font-size: .72rem; font-weight: 700; color: #fff; background: var(--gold-deep);
    padding: 2px 9px; border-radius: 999px;
}
.history-note { color: var(--muted); font-size: .78rem; }
.btn-del-stock {
    border: 1.5px solid var(--hairline); background: #fff; color: var(--status-due-bg);
    border-radius: 999px; width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
}
.btn-del-stock:hover { background: var(--status-due-light); border-color: var(--status-due-bg); }

.empty-state { text-align: center; color: var(--muted); padding: 2rem 1rem; font-size: .88rem; }
.skel { background: linear-gradient(90deg, #f1efe8 25%, #f8f6f0 37%, #f1efe8 63%); background-size: 400% 100%; animation: skel 1.4s ease infinite; border-radius: 8px; height: 1em; }
@keyframes skel { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }

.section-title { font-size: 1rem; font-weight: 800; color: var(--bronze-text); margin: 0 0 .9rem; display: flex; align-items: center; gap: .5rem; }
.section-title i { color: var(--gold-deep); }

.karat-row { margin-top: 0.6rem; }
.karat-row label { display: block; font-size: 0.72rem; margin-bottom: 0.15rem; color: var(--muted); }

@media (max-width: 991.98px) {
    .summary-grid { grid-template-columns: repeat(3, 1fr); }
    .karat-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 767.98px) {
    .page-content .container-fluid { padding: 0 !important; }
    .page-inset { padding: 0 0.8rem 1.5rem; }
    .inv-header { min-height: 60px !important; max-height: 70px !important; padding: 0.75rem 1rem !important; border-radius: 0 0 16px 16px !important; margin-bottom: 0.8rem !important; }
    .inv-header h4 { font-size: 0.95rem !important; }
    .inv-header .btn-history { padding: 0.22rem 0.55rem !important; font-size: 0.72rem !important; }

    .summary-grid { grid-template-columns: repeat(2, 1fr); gap: .5rem; }
    .summary-card {
        padding: .7rem .75rem;
        display: flex; flex-direction: column; align-items: flex-start;
        background: var(--ivory);
        border: none;
    }
    .summary-card .sc-top { display: flex; align-items: center; gap: .4rem; margin-bottom: .35rem; }
    .summary-card .sc-badge-icon {
        order: 0; width: 26px; height: 26px; border-radius: 8px; font-size: .8rem; flex-shrink: 0;
        background: transparent; color: var(--gold-deep);
    }
    .summary-card .sc-title {
        order: 1; font-size: .7rem; font-weight: 700; color: #555; text-transform: none;
        letter-spacing: 0; white-space: normal; line-height: 1.2;
    }
    .summary-card .sc-main-value {
        font-size: .92rem; font-weight: 800; color: var(--gold-deep); line-height: 1.25;
    }
    .summary-card.current .sc-main-value,
    .summary-card.current .sc-badge-icon { color: #3a8a3a; }
    .summary-card .sc-sub-value { display: block; font-size: .64rem; color: var(--muted); margin-top: .2rem; }

    .summary-grid #lowStockCard { display: none !important; }
    .low-stock-banner.d-none { display: none !important; }
    .low-stock-banner {
        display: flex; align-items: center; gap: .55rem;
        background: var(--ivory); border: none;
        color: #c0272d; border-radius: 12px; padding: .65rem .9rem;
        font-size: .9rem; font-weight: 800; margin: .6rem 0 1.1rem;
    }
    .low-stock-banner i { font-size: 1.15rem; flex-shrink: 0; color: #c0272d; }
    .low-stock-banner .lsb-count { font-weight: 800; }
    .low-stock-banner .lsb-list { font-weight: 700; opacity: .95; }
    .low-stock-banner.zero { color: #3a8a3a; }
    .low-stock-banner.zero i { color: #3a8a3a; }
    .low-stock-banner.zero i::before { content: "\f26a"; }

    .card { border-radius: 14px; margin-bottom: .8rem; }
    .card-header { border-radius: 14px 14px 0 0 !important; padding: .6rem .9rem; }
    .history-table { width: 100%; }
    .history-table th,
    .history-table td { padding: .55rem .5rem; font-size: .8rem; }
    .history-table .col-note,
    .history-table .col-user { display: none; }

    .karat-grid { grid-template-columns: 1fr; gap: .45rem; }
    .karat-card { border-radius: 12px; padding: .6rem .75rem; }
    .kc-badge { font-size: .78rem; padding: .3rem .55rem; }
    .kc-row .kc-label { font-size: .72rem; }
    .kc-row .kc-value { font-size: .78rem; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0">

    <div class="inv-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-boxes text-white fs-4"></i>
            <h4 class="m-0 text-white fw-bold">ইনভেন্টরি</h4>
        </div>
        <div>
            <button type="button" class="btn btn-outline-light btn-history btn-sm d-inline-flex align-items-center gap-1" id="btnOpenStockIn">
                <i class="bi bi-plus-lg"></i>
                <span>নতুন স্টক যোগ করুন</span>
            </button>
        </div>
    </div>

    <div class="page-inset">

        <!-- Summary cards -->
        <div class="summary-grid" id="summaryGrid">
            <div class="summary-card">
                <div class="sc-top">
                    <span class="sc-title">মোট স্টক ইন</span>
                    <span class="sc-badge-icon"><i class="bi bi-box-arrow-in-down"></i></span>
                </div>
                <div class="sc-main-value" id="sumTotalStockIn"><span class="skel d-inline-block" style="width:70px;">&nbsp;</span></div>
                <div class="sc-sub-value">সর্বমোট (all-time)</div>
            </div>
            <div class="summary-card current">
                <div class="sc-top">
                    <span class="sc-title">বর্তমান মজুদ</span>
                    <span class="sc-badge-icon"><i class="bi bi-boxes"></i></span>
                </div>
                <div class="sc-main-value" id="sumTotalLeft"><span class="skel d-inline-block" style="width:70px;">&nbsp;</span></div>
                <div class="sc-sub-value">সকল ক্যারেট</div>
            </div>
            <div class="summary-card">
                <div class="sc-top">
                    <span class="sc-title">বিক্রয়ে গেছে</span>
                    <span class="sc-badge-icon"><i class="bi bi-bag-check"></i></span>
                </div>
                <div class="sc-main-value" id="sumSold"><span class="skel d-inline-block" style="width:70px;">&nbsp;</span></div>
                <div class="sc-sub-value" id="sumSoldSub">নির্বাচিত সময়</div>
            </div>
            <div class="summary-card">
                <div class="sc-top">
                    <span class="sc-title">বিনিময়ে গেছে</span>
                    <span class="sc-badge-icon"><i class="bi bi-arrow-left-right"></i></span>
                </div>
                <div class="sc-main-value" id="sumExchanged"><span class="skel d-inline-block" style="width:70px;">&nbsp;</span></div>
                <div class="sc-sub-value" id="sumExchangedSub">২৪K, নির্বাচিত সময়</div>
            </div>
            <div class="summary-card" id="lowStockCard">
                <div class="sc-top">
                    <span class="sc-title">কম মজুদ</span>
                    <span class="sc-badge-icon"><i class="bi bi-exclamation-triangle"></i></span>
                </div>
                <div class="sc-main-value" id="sumLowStock"><span class="skel d-inline-block" style="width:30px;">&nbsp;</span></div>
                <div class="sc-sub-value">ক্যারেট</div>
            </div>
        </div>

        <!-- Low stock banner (mobile only) -->
        <div class="low-stock-banner d-none" id="lowStockBanner">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>কম মজুদ&nbsp; <span class="lsb-count" id="lsbCount">0</span> ক্যারেট <span class="lsb-list" id="lsbList"></span></span>
        </div>
        
        <!-- Date range filter -->
        <div class="date-filter-bar">
            <div class="dfb-row">
                <div class="dfb-field">
                    <label for="filterFrom">শুরুর তারিখ</label>
                    <input type="date" class="form-control form-control-sm" id="filterFrom">
                </div>
                <div class="dfb-field">
                    <label for="filterTo">শেষ তারিখ</label>
                    <input type="date" class="form-control form-control-sm" id="filterTo">
                </div>
                <button type="button" class="btn btn-gold btn-sm dfb-apply" id="btnApplyFilter">
                    <!-- <i class="bi bi-funnel-fill me-1"></i> -->Filter
                </button>
                <button type="button" class="btn btn-fb-secondary btn-sm dfb-reset" id="btnThisMonth">
                    <!-- <i class="bi bi-arrow-counterclockwise me-1"></i> -->Reset
                </button>
            </div>
            <span class="dfb-period" id="dfbPeriodLabel"></span>
        </div>

        <!-- Karat-wise cards -->
        <p class="section-title"><i class="bi bi-gem"></i> ক্যারেট অনুযায়ী মজুদ</p>
        <div class="karat-grid" id="karatGrid">
            <!-- injected by JS -->
        </div>

        <!-- Stock-in history -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-clock-history me-1 text-warning"></i> স্টক যোগের ইতিহাস <small class="text-muted fw-normal">(নির্বাচিত সময়সীমা অনুযায়ী)</small></span>
            </div>
            <div class="card-body p-0">
                <div style="overflow-x:auto;">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>তারিখ</th>
                            <th>ক্যারেট</th>
                            <th>ওজন</th>
                            <th class="col-note">নোট</th>
                            <th class="col-user">ব্যবহারকারী</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="historyBody">
                        <tr><td colspan="6" class="empty-state">লোড হচ্ছে…</td></tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

    </div>
</div>
</div>

<!-- Stock In Modal -->
<div class="modal fade" id="stockInModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px; border:none; box-shadow:0 20px 50px rgba(180,140,50,0.25);">
      <div class="modal-header" style="background:linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%); border-radius:18px 18px 0 0; border:none;">
        <h5 class="modal-title text-white fw-bold"><i class="bi bi-box-arrow-in-down me-1"></i> নতুন স্টক যোগ করুন</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="stockInForm" autocomplete="off">
        <div class="modal-body">

            <label class="form-label fw-semibold small">ক্যারেট</label>
            <select class="form-select mb-3" id="siPurity" name="purity" required>
                <option value="18.00">18K</option>
                <option value="20.00">20K</option>
                <option value="21.00" selected>21K</option>
                <option value="22.00">22K</option>
                <option value="24.00">24K Pure Gold</option>
            </select>

            <label class="form-label fw-semibold small">ওজন</label>
            <div class="item-fields-row mb-1">
                <div class="field-col">
                    <label>ভরি</label>
                    <input type="number" min="0" step="1" class="form-control form-control-sm" id="siVori" value="0" inputmode="numeric">
                </div>
                <div class="field-col">
                    <label>আনা</label>
                    <input type="number" min="0" max="15" step="1" class="form-control form-control-sm" id="siAna" value="0" inputmode="numeric">
                </div>
                <div class="field-col">
                    <label>রতি</label>
                    <input type="number" min="0" max="5" step="1" class="form-control form-control-sm" id="siRoti" value="0" inputmode="numeric">
                </div>
                <div class="field-col">
                    <label>পয়েন্ট</label>
                    <input type="number" min="0" max="9" step="1" class="form-control form-control-sm" id="siPoint" value="0" inputmode="numeric">
                </div>
            </div>
            <div class="text-danger small mb-3" id="siWeightError" style="display:none;"></div>

            <label class="form-label fw-semibold small">নোট (ঐচ্ছিক)</label>
            <textarea class="form-control" id="siNote" rows="2" placeholder="যেমন: Refinery, Purchase, Adjustment…"></textarea>

        </div>
        <div class="modal-footer" style="border-top:1px solid var(--hairline);">
            <button type="button" class="btn btn-fb-secondary" data-bs-dismiss="modal">বাতিল</button>
            <button type="submit" class="btn btn-gold" id="btnSaveStockIn">
                <i class="bi bi-save-fill me-1"></i> স্টক যোগ করুন
            </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Minimum Stock Modal -->
<div class="modal fade" id="minStockModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px; border:none; box-shadow:0 20px 50px rgba(180,140,50,0.25);">
      <div class="modal-header" style="background:var(--ivory); border-radius:18px 18px 0 0; border-bottom:1px solid var(--hairline);">
        <h5 class="modal-title fw-bold" id="minStockTitle"><i class="bi bi-sliders me-1"></i> সর্বনিম্ন মজুদ নির্ধারণ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="minStockForm" autocomplete="off">
        <div class="modal-body">
            <input type="hidden" id="msPurity">
            <label class="form-label fw-semibold small">সর্বনিম্ন মজুদ (Low Stock Alert)</label>
            <div class="item-fields-row mb-1">
                <div class="field-col">
                    <label>ভরি</label>
                    <input type="number" min="0" step="1" class="form-control form-control-sm" id="msVori" value="0" inputmode="numeric">
                </div>
                <div class="field-col">
                    <label>আনা</label>
                    <input type="number" min="0" max="15" step="1" class="form-control form-control-sm" id="msAna" value="0" inputmode="numeric">
                </div>
                <div class="field-col">
                    <label>রতি</label>
                    <input type="number" min="0" max="5" step="1" class="form-control form-control-sm" id="msRoti" value="0" inputmode="numeric">
                </div>
                <div class="field-col">
                    <label>পয়েন্ট</label>
                    <input type="number" min="0" max="9" step="1" class="form-control form-control-sm" id="msPoint" value="0" inputmode="numeric">
                </div>
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--hairline);">
            <button type="button" class="btn btn-fb-secondary" data-bs-dismiss="modal">বাতিল</button>
            <button type="submit" class="btn btn-gold"><i class="bi bi-check-lg me-1"></i> সংরক্ষণ করুন</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const G_PER_VORI  = 11.664;
const G_PER_ANA   = 0.729;
const G_PER_ROTI  = 0.1215;
const G_PER_POINT = 0.01215;

function traditionalToGrams(vori, ana, roti, point) {
    return (vori * G_PER_VORI) + (ana * G_PER_ANA) + (roti * G_PER_ROTI) + (point * G_PER_POINT);
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(s.replace(' ', 'T'));
    return d.toLocaleDateString('bn-BD', { day: '2-digit', month: 'short', year: 'numeric' });
}

const stockInModal = new bootstrap.Modal(document.getElementById('stockInModal'));
const minStockModal = new bootstrap.Modal(document.getElementById('minStockModal'));

document.getElementById('btnOpenStockIn').addEventListener('click', () => {
    document.getElementById('stockInForm').reset();
    document.getElementById('siWeightError').style.display = 'none';
    stockInModal.show();
});

// -----------------------------------------------------------------------
// Date range filter
// -----------------------------------------------------------------------
function currentMonthRange() {
    const now = new Date();
    const from = new Date(now.getFullYear(), now.getMonth(), 1);
    const toStr = toISODate(now);
    const fromStr = toISODate(from);
    return { from: fromStr, to: toStr };
}

function toISODate(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function fmtDateShort(iso) {
    if (!iso) return '';
    const d = new Date(iso + 'T00:00:00');
    return d.toLocaleDateString('bn-BD', { day: '2-digit', month: 'short', year: 'numeric' });
}

const filterFromEl = document.getElementById('filterFrom');
const filterToEl = document.getElementById('filterTo');

const defaultRange = currentMonthRange();
filterFromEl.value = defaultRange.from;
filterToEl.value = defaultRange.to;

document.getElementById('btnApplyFilter').addEventListener('click', () => {
    let from = filterFromEl.value;
    let to = filterToEl.value;
    if (!from || !to) {
        alert('অনুগ্রহ করে শুরু ও শেষ তারিখ নির্বাচন করুন।');
        return;
    }
    if (from > to) { [from, to] = [to, from]; filterFromEl.value = from; filterToEl.value = to; }
    loadInventory();
});

document.getElementById('btnThisMonth').addEventListener('click', () => {
    const r = currentMonthRange();
    filterFromEl.value = r.from;
    filterToEl.value = r.to;
    loadInventory();
});

// -----------------------------------------------------------------------
// Load data
// -----------------------------------------------------------------------
async function loadInventory() {
    try {
        const from = filterFromEl.value || defaultRange.from;
        const to = filterToEl.value || defaultRange.to;
        const params = new URLSearchParams({ action: 'data', from, to });
        const res = await fetch(`inventory.php?${params.toString()}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'লোড ব্যর্থ হয়েছে');
        renderSummary(data.summary, data.period);
        renderKaratCards(data.cards);
        renderHistory(data.recent);
    } catch (e) {
        console.error(e);
    }
}

function renderSummary(s, period) {
    document.getElementById('sumTotalStockIn').textContent = s.total_stock_in_trad;
    document.getElementById('sumTotalLeft').textContent = s.total_left_trad;
    document.getElementById('sumSold').textContent = s.period_sold_trad;
    document.getElementById('sumExchanged').textContent = s.period_exchanged_trad;
    document.getElementById('sumLowStock').textContent = s.low_stock_count;
    const lowCard = document.getElementById('lowStockCard');
    lowCard.classList.toggle('low', s.low_stock_count > 0);

    if (period) {
        const rangeLabel = `${fmtDateShort(period.from)} – ${fmtDateShort(period.to)}`;
        document.getElementById('dfbPeriodLabel').textContent = rangeLabel;
        document.getElementById('sumSoldSub').textContent = rangeLabel;
        document.getElementById('sumExchangedSub').textContent = `২৪K, ${rangeLabel}`;
    }
}

function renderKaratCards(cards) {
    const el = document.getElementById('karatGrid');

    const banner = document.getElementById('lowStockBanner');
    const lowCards = (cards || []).filter(c => c.is_low);
    document.getElementById('lsbCount').textContent = lowCards.length;
    document.getElementById('lsbList').textContent = lowCards.length > 0
        ? '(' + lowCards.map(c => c.purity_label).join(',') + ')'
        : '';
    banner.classList.remove('d-none');
    banner.classList.toggle('zero', lowCards.length === 0);

    if (!cards || cards.length === 0) {
        el.innerHTML = '<div class="empty-state">কোনো ইনভেন্টরি পাওয়া যায়নি</div>';
        return;
    }
    el.innerHTML = cards.map(c => `
        <div class="karat-card ${c.is_low ? 'low' : ''}" data-karat-card="${c.purity}">
            <div class="kc-details-inner">
                <div class="kc-header-row">
                    <span class="kc-badge"><i class="bi bi-gem"></i> ${escHtml(c.purity_label)}</span>
                    <button type="button" class="kc-mobile-plus" data-quick-stock="${c.purity}" title="স্টক যোগ করুন">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
                <div class="kc-mobile-rows">
                    <div class="kc-row kc-total">
                        <span class="kc-label"><i class="bi bi-box-seam"></i> মোট স্টক ইন</span>
                        <span class="kc-value">${escHtml(c.total_trad)}</span>
                    </div>
                    <div class="kc-row kc-current">
                        <span class="kc-label"><i class="bi bi-boxes"></i> বর্তমান মজুদ</span>
                        <span class="kc-value">${escHtml(c.left_trad)}</span>
                    </div>
                    <div class="kc-row kc-used">
                        <span class="kc-label"><i class="bi bi-arrow-down-circle"></i> ব্যবহৃত</span>
                        <span class="kc-value">${escHtml(c.used_trad)}</span>
                    </div>
                </div>
            </div>
        </div>
    `).join('');

    el.querySelectorAll('[data-set-min]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            document.getElementById('msPurity').value = btn.dataset.setMin;
            document.getElementById('minStockTitle').innerHTML =
                `<i class="bi bi-sliders me-1"></i> সর্বনিম্ন মজুদ — ${escHtml(btn.dataset.minLabel)}`;
            document.getElementById('minStockForm').reset();
            document.getElementById('msPurity').value = btn.dataset.setMin;
            minStockModal.show();
        });
    });
    el.querySelectorAll('[data-quick-stock]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            document.getElementById('stockInForm').reset();
            document.getElementById('siPurity').value = parseFloat(btn.dataset.quickStock).toFixed(2);
            document.getElementById('siWeightError').style.display = 'none';
            stockInModal.show();
        });
    });
}

function renderHistory(rows) {
    const el = document.getElementById('historyBody');
    if (!rows || rows.length === 0) {
        el.innerHTML = '<tr><td colspan="6" class="empty-state"><i class="bi bi-inbox fs-3 d-block mb-2"></i>কোনো স্টক ইন রেকর্ড পাওয়া যায়নি</td></tr>';
        return;
    }
    el.innerHTML = rows.map(r => `
        <tr>
            <td>${fmtDate(r.created_at)}</td>
            <td><span class="history-karat-badge">${escHtml(r.purity_label)}</span></td>
            <td class="fw-semibold">${escHtml(r.weight_trad)}</td>
            <td class="history-note col-note">${r.note ? escHtml(r.note) : '—'}</td>
            <td class="col-user">${escHtml(r.user_name)}</td>
            <td>
                <button type="button" class="btn-del-stock" data-del-stock="${r.id}" title="বাতিল করুন">
                    <i class="bi bi-trash3"></i>
                </button>
            </td>
        </tr>
    `).join('');

    el.querySelectorAll('[data-del-stock]').forEach(btn => {
        btn.addEventListener('click', () => deleteStockIn(btn.dataset.delStock, btn));
    });
}

async function deleteStockIn(id, btn) {
    if (!confirm('এই স্টক ইন এন্ট্রি বাতিল করতে চান?')) return;
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('action', 'delete_stock_in');
        fd.append('id', id);
        const res = await fetch('inventory.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        if (!data.success) {
            alert(data.message || 'বাতিল করতে ব্যর্থ হয়েছে।');
            btn.disabled = false;
            return;
        }
        loadInventory();
    } catch (e) {
        alert('একটি ত্রুটি ঘটেছে।');
        btn.disabled = false;
    }
}

// -----------------------------------------------------------------------
// Stock In form submit
// -----------------------------------------------------------------------
document.getElementById('stockInForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const purity = document.getElementById('siPurity').value;
    const vori  = parseInt(document.getElementById('siVori').value || '0', 10);
    const ana   = parseInt(document.getElementById('siAna').value || '0', 10);
    const roti  = parseInt(document.getElementById('siRoti').value || '0', 10);
    const point = parseInt(document.getElementById('siPoint').value || '0', 10);
    const note  = document.getElementById('siNote').value.trim();

    const grams = traditionalToGrams(vori, ana, roti, point);
    const errEl = document.getElementById('siWeightError');
    if (grams <= 0) {
        errEl.textContent = 'মোট ওজন শূন্যের বেশি হতে হবে।';
        errEl.style.display = 'block';
        return;
    }
    errEl.style.display = 'none';

    const btn = document.getElementById('btnSaveStockIn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> সংরক্ষণ হচ্ছে…';

    try {
        const fd = new FormData();
        fd.append('action', 'stock_in');
        fd.append('purity', purity);
        fd.append('vori', vori);
        fd.append('ana', ana);
        fd.append('roti', roti);
        fd.append('point', point);
        fd.append('note', note);

        const res = await fetch('inventory.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();

        if (!data.success) {
            errEl.textContent = data.message || 'স্টক যোগ করতে ব্যর্থ হয়েছে।';
            errEl.style.display = 'block';
            return;
        }

        stockInModal.hide();
        loadInventory();
    } catch (e) {
        errEl.textContent = 'একটি ত্রুটি ঘটেছে। আবার চেষ্টা করুন।';
        errEl.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-save-fill me-1"></i> স্টক যোগ করুন';
    }
});

// -----------------------------------------------------------------------
// Minimum stock form submit
// -----------------------------------------------------------------------
document.getElementById('minStockForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const purity = document.getElementById('msPurity').value;
    const vori  = parseInt(document.getElementById('msVori').value || '0', 10);
    const ana   = parseInt(document.getElementById('msAna').value || '0', 10);
    const roti  = parseInt(document.getElementById('msRoti').value || '0', 10);
    const point = parseInt(document.getElementById('msPoint').value || '0', 10);

    try {
        const fd = new FormData();
        fd.append('action', 'set_minimum');
        fd.append('purity', purity);
        fd.append('vori', vori);
        fd.append('ana', ana);
        fd.append('roti', roti);
        fd.append('point', point);

        const res = await fetch('inventory.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        if (!data.success) {
            alert(data.message || 'আপডেট করতে ব্যর্থ হয়েছে।');
            return;
        }
        minStockModal.hide();
        loadInventory();
    } catch (e) {
        alert('একটি ত্রুটি ঘটেছে।');
    }
});

loadInventory();
</script>

</body>
</html>