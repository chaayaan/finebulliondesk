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
        $outOfStockCount = 0;
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
            $isOut  = $leftW <= 0.0009;
            if ($isLow) $lowStockCount++;
            if ($isOut) $outOfStockCount++;

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
                'is_out'        => $isOut,
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
        $today = date('Y-m-d');
        while ($row = mysqli_fetch_assoc($recentRes)) {
            $entryDate = date('Y-m-d', strtotime($row['created_at']));
            $recent[] = [
                'id'         => (int)$row['id'],
                'purity'     => (float)$row['purity'],
                'purity_label' => fmt_karat((float)$row['purity']),
                'weight'     => (float)$row['weight'],
                'weight_trad'=> format_traditional(grams_to_traditional((float)$row['weight'])),
                'note'       => $row['note'],
                'created_at' => $row['created_at'],
                'user_name'  => $row['user_name'] ?? '—',
                'can_modify' => is_admin() && $entryDate === $today,
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
                'out_of_stock_count' => $outOfStockCount,
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
        if (!is_admin()) {
            json_out(['success' => false, 'message' => 'শুধুমাত্র অ্যাডমিন এই কাজ করতে পারবেন।'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'অকার্যকর আইডি।'], 400);

        $stmt = mysqli_prepare($conn, "SELECT purity, weight, created_at FROM stock_in WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$row) json_out(['success' => false, 'message' => 'রেকর্ড পাওয়া যায়নি।'], 404);

        if (date('Y-m-d', strtotime($row['created_at'])) !== date('Y-m-d')) {
            json_out(['success' => false, 'message' => 'শুধুমাত্র আজকের এন্ট্রি বাতিল করা যাবে।'], 403);
        }

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

    if ($action === 'edit_stock_in' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_admin()) {
            json_out(['success' => false, 'message' => 'শুধুমাত্র অ্যাডমিন এই কাজ করতে পারবেন।'], 403);
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'অকার্যকর আইডি।'], 400);

        $stmt = mysqli_prepare($conn, "SELECT purity, weight, created_at FROM stock_in WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$row) json_out(['success' => false, 'message' => 'রেকর্ড পাওয়া যায়নি।'], 404);

        if (date('Y-m-d', strtotime($row['created_at'])) !== date('Y-m-d')) {
            json_out(['success' => false, 'message' => 'শুধুমাত্র আজকের এন্ট্রি সম্পাদনা করা যাবে।'], 403);
        }

        $oldPurity = (float)$row['purity'];
        $oldWeight = (float)$row['weight'];

        $newPurity = (float)($_POST['purity'] ?? 0);
        $note      = trim($_POST['note'] ?? '') ?: null;
        $vori      = (int)($_POST['vori']  ?? 0);
        $ana       = (int)($_POST['ana']   ?? 0);
        $roti      = (int)($_POST['roti']  ?? 0);
        $point     = (int)($_POST['point'] ?? 0);

        $errors = [];
        if (!in_array(round($newPurity, 2), KARATS, true)) {
            $errors['purity'] = 'সঠিক ক্যারেট নির্বাচন করুন।';
        }
        if ($vori < 0)               $errors['vori']  = 'সঠিক মান দিন।';
        if ($ana  < 0 || $ana  > 15) $errors['ana']   = 'আনা 0–15 এর মধ্যে হতে হবে।';
        if ($roti < 0 || $roti > 5)  $errors['roti']  = 'রতি 0–5 এর মধ্যে হতে হবে।';
        if ($point < 0 || $point > 9) $errors['point'] = 'পয়েন্ট 0–9 এর মধ্যে হতে হবে।';

        $newWeight = traditional_to_grams($vori, $ana, $roti, $point);
        if ($newWeight <= 0) {
            $errors['weight'] = 'মোট ওজন শূন্যের বেশি হতে হবে।';
        }

        if (!empty($errors)) {
            json_out(['success' => false, 'message' => 'তথ্য যাচাই ব্যর্থ হয়েছে।', 'errors' => $errors], 422);
        }

        // If this stock-in already fed a sale/exchange for its karat, reducing
        // its weight (or moving it to another karat) below what's currently
        // used elsewhere would drive left_weight negative — block that.
        $stmt = mysqli_prepare($conn, "SELECT left_weight FROM inventory WHERE purity = ?");
        mysqli_stmt_bind_param($stmt, 'd', $oldPurity);
        mysqli_stmt_execute($stmt);
        $invRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $oldPurityLeft = (float)($invRow['left_weight'] ?? 0);

        // Simulate reverting the old entry first.
        $simulatedLeftAfterRevert = $oldPurityLeft - $oldWeight;
        if ($simulatedLeftAfterRevert < -0.0005) {
            json_out([
                'success' => false,
                'message' => 'এই স্টক ইন সম্পাদনা করা যাবে না — এই স্টক ইতিমধ্যে বিক্রয়/বিনিময়ে ব্যবহৃত হয়েছে।',
            ], 409);
        }

        mysqli_begin_transaction($conn);
        try {
            if (abs($oldPurity - $newPurity) < 0.0001) {
                // Same karat — just adjust the delta.
                $delta = $newWeight - $oldWeight;
                $stmt = mysqli_prepare($conn,
                    "UPDATE inventory SET total_weight = total_weight + ?, left_weight = left_weight + ?
                     WHERE purity = ?");
                mysqli_stmt_bind_param($stmt, 'ddd', $delta, $delta, $newPurity);
                mysqli_stmt_execute($stmt);
            } else {
                // Karat changed — revert from old karat, apply to new karat.
                $stmt = mysqli_prepare($conn,
                    "UPDATE inventory SET total_weight = total_weight - ?, left_weight = left_weight - ?
                     WHERE purity = ?");
                mysqli_stmt_bind_param($stmt, 'ddd', $oldWeight, $oldWeight, $oldPurity);
                mysqli_stmt_execute($stmt);

                $stmt = mysqli_prepare($conn,
                    "UPDATE inventory SET total_weight = total_weight + ?, left_weight = left_weight + ?
                     WHERE purity = ?");
                mysqli_stmt_bind_param($stmt, 'ddd', $newWeight, $newWeight, $newPurity);
                mysqli_stmt_execute($stmt);
            }

            $stmt = mysqli_prepare($conn,
                "UPDATE stock_in SET purity = ?, weight = ?, note = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'ddsi', $newPurity, $newWeight, $note, $id);
            mysqli_stmt_execute($stmt);

            mysqli_commit($conn);
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            json_out(['success' => false, 'message' => 'সম্পাদনা করতে ব্যর্থ হয়েছে।'], 500);
        }

        json_out([
            'success' => true,
            'message' => 'স্টক ইন এন্ট্রি সম্পাদনা করা হয়েছে।',
            'weight_trad' => format_traditional(grams_to_traditional($newWeight)),
        ]);
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
    --warning: #B7791F;
    --shadow: 0 2px 8px rgba(47, 65, 86, 0.08);
}

html, body {
    margin: 0 !important;
    padding: 0 !important;
    background: var(--bg-app);
    font-family: 'Inter', 'Noto Sans Bengali', system-ui, -apple-system, sans-serif;
    color: var(--text-primary);
}
.page-content { margin-top: 0 !important; padding-top: 0 !important; }
.page-content > .container-fluid:first-child { margin-top: 0 !important; padding-top: 0 !important; }

/* Page Header (§3) */
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
    margin-bottom: 1.5rem;
}
.page-header .header-left { display: flex; flex-direction: column; gap: .2rem; min-width: 0; }
.page-header .header-right { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.page-header h1 {
    color: var(--text-on-navy);
    margin: 0;
    font-size: 22px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: .5rem;
}

/* Header Action Button (§3) */
.header-action-btn {
    background: var(--navy);
    border: 1.5px solid #fff;
    color: #fff;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    padding: .45rem .9rem;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    white-space: nowrap;
    text-decoration: none;
    cursor: pointer;
}
.header-action-btn:hover, .header-action-btn:focus {
    background: var(--teal);
    border-color: #fff;
    color: #fff;
}
.header-action-btn i { color: #fff; }

.page-inset { padding: 0 1.5rem 2rem; }

/* Date Filter Bar */
.date-filter-bar {
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 14px;
    padding: .8rem 1rem;
    margin: 0 0 1.25rem;
    box-shadow: var(--shadow);
}
.date-filter-bar .dfb-row {
    display: flex;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: .5rem;
}
.date-filter-bar .dfb-field { display: flex; flex-direction: column; gap: .25rem; flex: 1 1 calc(50% - .25rem); }
.date-filter-bar .dfb-actions { display: flex; gap: .5rem; flex: 1 1 100%; }
.date-filter-bar .dfb-actions .btn { flex: 1; text-align: center; justify-content: center; }

.date-filter-bar .dfb-field label {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: .03em;
    white-space: nowrap;
}
.date-filter-bar .form-control { 
    width: 100%;
    padding: .45rem .6rem; 
    font-size: 13.5px; 
}
.date-filter-bar .dfb-period {
    display: inline-block;
    margin-top: .6rem;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--navy);
    background: var(--sky);
    padding: .3rem .75rem;
    border-radius: 8px;
}

/* Base Buttons (§4) */
.btn-primary {
    background: var(--navy);
    border: 1.5px solid var(--navy);
    color: #fff;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    padding: .55rem 1.1rem;
}
.btn-primary:hover, .btn-primary:focus {
    background: var(--teal);
    border-color: var(--teal);
    color: #fff;
}
.btn-secondary {
    background: #fff;
    border: 1.5px solid var(--border-default);
    color: var(--navy);
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    padding: .55rem 1.1rem;
}
.btn-secondary:hover {
    background: var(--bg-hover);
    border-color: var(--teal);
    color: var(--navy);
}
.btn-sm-custom {
    padding: .4rem .8rem;
    font-size: 13px;
    border-radius: 8px;
}

/* Circular Micro Controls Exception (§4.6) */
.btn-icon-round {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--sky);
    color: var(--navy);
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}
.btn-icon-round:hover {
    background: var(--teal);
    color: #fff;
}

/* Cards & Summary Grid (§5) */
.summary-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 1rem; margin-bottom: 1.25rem; }
.card, .summary-card {
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 14px;
    box-shadow: var(--shadow);
    padding: 1rem 1.1rem;
}
.summary-card .sc-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: .6rem; }
.summary-card .sc-title { font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .04em; }
.summary-card .sc-badge-icon {
    width: 34px; height: 34px; border-radius: 10px; background: var(--sky);
    color: var(--navy); display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.summary-card.low .sc-badge-icon { background: #FBF3D9; color: var(--warning); }
.summary-card .sc-main-value { font-size: 1.25rem; font-weight: 800; color: var(--text-primary); line-height: 1.2; }
.summary-card .sc-sub-value { font-size: 12.5px; color: var(--text-secondary); margin-top: .15rem; }
.summary-card.low .sc-main-value { color: var(--warning); }

.card-header-custom {
    background: var(--bg-card);
    border-bottom: 1.5px solid var(--border-default);
    padding: .85rem 1.1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-radius: 14px 14px 0 0;
}
.card-header-custom .title-text {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: .5rem;
}
.card-header-custom .title-text i { color: var(--teal); }

.karat-grid {
    display: grid; grid-template-columns: repeat(2, 1fr); gap: .75rem; margin-bottom: 1.25rem;
}
.karat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 14px;
    box-shadow: var(--shadow);
    padding: .75rem .85rem;
}
.karat-card.low { border-color: var(--warning); }
.karat-card.out { border-color: var(--danger); border-width: 2px; background: #FFF5F5; }

.kc-details-inner { display: flex; flex-direction: column; padding: 0; }
.kc-header-row {
    display: flex; align-items: center; justify-content: space-between; gap: .4rem;
    margin-bottom: .5rem;
}
.karat-card .kc-badge {
    font-size: 12px; font-weight: 700; color: #fff; background: var(--navy);
    border-radius: 8px; padding: .25rem .55rem; display: inline-flex; align-items: center; gap: .3rem;
    flex-shrink: 0;
}
.karat-card.low .kc-badge { background: var(--warning); }
.karat-card.out .kc-badge { background: #8B0000; }

.kc-out-pill {
    font-size: 10.5px; font-weight: 800; color: #fff; background: #8B0000;
    border-radius: 6px; padding: .15rem .45rem; display: inline-flex; align-items: center; gap: .25rem;
    letter-spacing: .01em; flex-shrink: 0;
}

.kc-mobile-plus {
    width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border-default);
    background: var(--bg-app); color: var(--navy);
    display: flex; align-items: center; justify-content: center; font-size: .85rem;
    flex-shrink: 0; cursor: pointer;
}
.kc-mobile-plus:hover { background: var(--sky); color: var(--navy); }

.kc-mobile-rows { display: flex; flex-direction: column; }
.kc-row { display: flex; justify-content: space-between; align-items: center; padding: .35rem 0; border-top: 1px dashed var(--border-default); }
.kc-row:first-child { border-top: none; padding-top: 0; }
.kc-row .kc-label { font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .02em; display: flex; align-items: center; gap: .3rem; }
.kc-row .kc-value { font-size: 13px; font-weight: 600; color: var(--text-primary); }
.kc-row.kc-used .kc-value { color: var(--danger); }
.kc-row.kc-current .kc-value { color: var(--success); font-weight: 700; }
.karat-card.low .kc-row.kc-current .kc-value { color: var(--warning); }
.karat-card.out .kc-row.kc-current .kc-value { color: var(--danger); }

.low-stock-banner.d-none { display: none !important; }
.low-stock-banner {
    display: flex; align-items: center; gap: .55rem;
    background: #FBF3D9; border: 1px solid var(--warning);
    color: var(--warning); border-radius: 12px; padding: .65rem .9rem;
    font-size: 13.5px; font-weight: 700; margin: .6rem 0 1.1rem;
}
.low-stock-banner.zero { background: #EAF3EE; border-color: var(--success); color: var(--success); }

.stockout-banner.d-none { display: none !important; }
.stockout-banner {
    display: flex; align-items: center; gap: .55rem;
    background: #FDE8E8; border: 1.5px solid #8B0000;
    color: #8B0000; border-radius: 12px; padding: .65rem .9rem;
    font-size: 13.5px; font-weight: 700; margin: .6rem 0 1.1rem;
}


/* Input Fields (§6) */
.form-control, .form-select, textarea {
    background: #fff;
    border: 1.5px solid var(--border-default);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    padding: .55rem .75rem;
    transition: border-color .15s, box-shadow .15s;
}
.form-control::placeholder { color: var(--text-secondary); opacity: .7; }
.form-control:focus, .form-select:focus, textarea:focus {
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(86,124,141,.15);
    outline: none;
}
label, .form-label {
    font-size: 12.5px; font-weight: 700; color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: .03em; margin-bottom: .3rem;
}

/* Weight fields row */
.item-fields-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; }
.item-fields-row .field-col label { display: block; font-size: 11px; margin-bottom: 0.15rem; color: var(--text-secondary); white-space: nowrap; text-transform: uppercase; }
.item-fields-row .field-col input { text-align: center; padding-left: 0.25rem; padding-right: 0.25rem; }

/* Tables (§8) */
.history-table { width: 100%; border-collapse: collapse; }
.history-table th {
    background: var(--beige);
    color: var(--text-secondary);
    font-size: 12px; font-weight: 700; text-transform: uppercase;
    border-bottom: 1.5px solid var(--border-default);
    padding: .65rem .75rem;
    text-align: left; white-space: nowrap;
}
.history-table td { padding: .65rem .75rem; border-bottom: 1px solid var(--border-default); font-size: 13.5px; color: var(--text-primary); vertical-align: middle; }
.history-table tr:hover td { background: var(--bg-hover); }
.history-karat-badge {
    display: inline-block; font-size: 11px; font-weight: 700; color: #fff; background: var(--navy);
    padding: 2px 8px; border-radius: 999px;
}
.history-note { color: var(--text-secondary); font-size: 12.5px; }

/* Status Badges (§7) */
.chip-paid   { background: #EAF3EE; color: var(--success); padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.chip-due    { background: #FBECEC; color: var(--danger); padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.chip-total  { background: var(--sky); color: var(--navy); padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }

.empty-state { text-align: center; color: var(--text-secondary); padding: 2rem 1rem; font-size: 13.5px; }
.skel { background: linear-gradient(90deg, #EAF1F6 25%, #F5EFEB 37%, #EAF1F6 63%); background-size: 400% 100%; animation: skel 1.4s ease infinite; border-radius: 8px; height: 1em; }
@keyframes skel { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }

.section-title { font-size: 16px; font-weight: 700; color: var(--text-primary); margin: 0 0 .9rem; display: flex; align-items: center; gap: .5rem; }
.section-title i { color: var(--teal); }

/* Responsive adjustments (§10) */
@media (min-width: 768px) {
    .date-filter-bar .dfb-row {
        flex-wrap: nowrap;
    }
    .date-filter-bar .dfb-field {
        flex: 0 0 auto;
        width: 140px;
    }
    .date-filter-bar .dfb-actions {
        flex: 0 0 auto;
        width: auto;
    }
}
@media (max-width: 991.98px) {
    .summary-grid { grid-template-columns: repeat(3, 1fr); }
    .karat-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 576px) {
    .page-content .container-fluid { padding: 0 !important; }
    .page-inset { padding: 0 1rem 1.5rem; }
    .page-header { padding: .85rem 1.1rem; border-radius: 0 0 14px 14px; margin-bottom: 1rem; }
    .page-header h1 { font-size: 18px; }
    .page-header .header-left, .page-header .header-right { width: 100%; }
    .header-action-btn { font-size: 12.5px; padding: .4rem .75rem; width: 100%; justify-content: center; }

    .summary-grid { grid-template-columns: repeat(2, 1fr); gap: .5rem; }
    .summary-card { padding: .85rem; }
    .summary-card .sc-main-value { font-size: 1rem; }

    .summary-grid #lowStockCard { display: none !important; }

    .card { border-radius: 14px; margin-bottom: .8rem; padding: .85rem; }
    .history-table th, .history-table td { padding: .55rem .5rem; font-size: 13px; }
    .history-table .col-note, .history-table .col-user { display: none; }

    .karat-grid { grid-template-columns: 1fr; gap: .5rem; }
    .form-control, .form-select, textarea { font-size: 16px; padding: .6rem .8rem; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0">

    <!-- Page Header (§3) -->
    <div class="page-header">
        <div class="header-left">
            <h1><i class="bi bi-boxes"></i> ইনভেন্টরি</h1>
            <div class="header-meta">
                <span>ক্যারেট অনুযায়ী সোনার মজুদের তথ্য</span>
            </div>
        </div>
        <div class="header-right">
            <button type="button" class="header-action-btn" id="btnOpenStockIn">
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
            <div class="summary-card">
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

        <!-- Stockout banner (mobile only) -->
        <div class="stockout-banner d-none" id="stockoutBanner">
            <i class="bi bi-x-octagon-fill"></i>
            <span>স্টক আউট&nbsp; <span class="osb-count" id="osbCount">0</span> ক্যারেট <span class="osb-list" id="osbList"></span></span>
        </div>
        
        <!-- Date range filter -->
        <div class="date-filter-bar">
            <div class="dfb-row">
                <div class="dfb-field">
                    <label for="filterFrom">শুরুর তারিখ</label>
                    <input type="date" class="form-control" id="filterFrom">
                </div>
                <div class="dfb-field">
                    <label for="filterTo">শেষ তারিখ</label>
                    <input type="date" class="form-control" id="filterTo">
                </div>
                <div class="dfb-actions">
                    <button type="button" class="btn btn-primary btn-sm-custom" id="btnApplyFilter">
                        ফিল্টার
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm-custom" id="btnThisMonth">
                        রিসেট
                    </button>
                </div>
            </div>
            <span class="dfb-period" id="dfbPeriodLabel"></span>
        </div>

        <!-- Karat-wise cards -->
        <p class="section-title"><i class="bi bi-gem"></i> ক্যারেট অনুযায়ী মজুদ</p>
        <div class="karat-grid" id="karatGrid">
            <!-- injected by JS -->
        </div>

        <!-- Stock-in history -->
        <div class="card p-0 mb-4 overflow-hidden">
            <div class="card-header-custom">
                <div class="title-text">
                    <i class="bi bi-clock-history"></i>
                    <span>স্টক যোগের ইতিহাস <small class="text-secondary fw-normal fs-6">(নির্বাচিত সময়সীমা অনুযায়ী)</small></span>
                </div>
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
    <div class="modal-content" style="border-radius:14px; border:1px solid var(--border-default); box-shadow:var(--shadow);">
      <div class="modal-header" style="background:var(--navy); color:#fff; border-radius:13px 13px 0 0;">
        <h5 class="modal-title fw-bold text-white fs-6" id="stockInModalTitle"><i class="bi bi-box-arrow-in-down me-1"></i> নতুন স্টক যোগ করুন</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="stockInForm" autocomplete="off">
        <input type="hidden" id="siEditId" value="">
        <div class="modal-body p-3">

            <label class="form-label">ক্যারেট</label>
            <select class="form-select mb-3" id="siPurity" name="purity" required>
                <option value="18.00">18K</option>
                <option value="20.00">20K</option>
                <option value="21.00" selected>21K</option>
                <option value="22.00">22K</option>
                <option value="24.00">24K Pure Gold</option>
            </select>

            <label class="form-label">ওজন</label>
            <div class="item-fields-row mb-2">
                <div class="field-col">
                    <label>ভরি</label>
                    <input type="number" min="0" step="1" class="form-control traditional-weight-input" id="siVori" placeholder="0" value="" inputmode="numeric">
                </div>
                <div class="field-col">
                    <label>আনা</label>
                    <input type="number" min="0" max="15" step="1" class="form-control traditional-weight-input" id="siAna" placeholder="0" value="" inputmode="numeric">
                </div>
                <div class="field-col">
                    <label>রতি</label>
                    <input type="number" min="0" max="5" step="1" class="form-control traditional-weight-input" id="siRoti" placeholder="0" value="" inputmode="numeric">
                </div>
                <div class="field-col">
                    <label>পয়েন্ট</label>
                    <input type="number" min="0" max="9" step="1" class="form-control traditional-weight-input" id="siPoint" placeholder="0" value="" inputmode="numeric">
                </div>
            </div>
            <div class="text-danger small mb-3" id="siWeightError" style="display:none;"></div>

            <label class="form-label">নোট (ঐচ্ছিক)</label>
            <textarea class="form-control" id="siNote" rows="2" placeholder="যেমন: Refinery, Purchase, Adjustment…"></textarea>

        </div>
        <div class="modal-footer" style="border-top:1px solid var(--border-default);">
            <button type="button" class="btn-secondary btn-sm-custom" data-bs-dismiss="modal">বাতিল</button>
            <button type="submit" class="btn-primary btn-sm-custom" id="btnSaveStockIn">
                <i class="bi bi-save-fill me-1"></i> <span id="btnSaveStockInLabel">স্টক যোগ করুন</span>
            </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Minimum Stock Modal -->
<div class="modal fade" id="minStockModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:14px; border:1px solid var(--border-default); box-shadow:var(--shadow);">
      <div class="modal-header" style="background:var(--beige); border-bottom:1px solid var(--border-default); border-radius:13px 13px 0 0;">
        <h5 class="modal-title fw-bold fs-6" id="minStockTitle"><i class="bi bi-sliders me-1"></i> সর্বনিম্ন মজুদ নির্ধারণ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="minStockForm" autocomplete="off">
        <div class="modal-body p-3">
            <input type="hidden" id="msPurity">
            <label class="form-label">সর্বনিম্ন মজুদ (Low Stock Alert)</label>
            <div class="item-fields-row mb-1">
                <div class="field-col">
                    <label>ভরি</label>
                    <input type="number" min="0" step="1" class="form-control traditional-weight-input" id="msVori" placeholder="0" value="" inputmode="numeric">
                </div>
                <div class="field-col">
                    <label>আনা</label>
                    <input type="number" min="0" max="15" step="1" class="form-control traditional-weight-input" id="msAna" placeholder="0" value="" inputmode="numeric">
                </div>
                <div class="field-col">
                    <label>রতি</label>
                    <input type="number" min="0" max="5" step="1" class="form-control traditional-weight-input" id="msRoti" placeholder="0" value="" inputmode="numeric">
                </div>
                <div class="field-col">
                    <label>পয়েন্ট</label>
                    <input type="number" min="0" max="9" step="1" class="form-control traditional-weight-input" id="msPoint" placeholder="0" value="" inputmode="numeric">
                </div>
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--border-default);">
            <button type="button" class="btn-secondary btn-sm-custom" data-bs-dismiss="modal">বাতিল</button>
            <button type="submit" class="btn-primary btn-sm-custom"><i class="bi bi-check-lg me-1"></i> সংরক্ষণ করুন</button>
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

function gramsToTraditional(grams) {
    grams = Math.max(0, grams);
    const EPS = 1e-9;
    const totalVori = grams / G_PER_VORI;

    let vori = Math.floor(totalVori + EPS);
    const fracVori = Math.max(0, totalVori - vori);

    const totalAna = fracVori * 16;
    let ana = Math.floor(totalAna + EPS);
    const fracAna = Math.max(0, totalAna - ana);

    const totalRoti = fracAna * 6;
    let roti = Math.floor(totalRoti + EPS);
    const fracRoti = Math.max(0, totalRoti - roti);

    let point = Math.round(fracRoti * 10);

    if (point >= 10) { point -= 10; roti += 1; }
    if (roti >= 6)   { roti -= 6;   ana += 1; }
    if (ana >= 16)   { ana -= 16;   vori += 1; }

    return { vori, ana, roti, point };
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

// -----------------------------------------------------------------------
// Input Placeholder Logic
// -----------------------------------------------------------------------
document.querySelectorAll('.traditional-weight-input').forEach(input => {
    input.addEventListener('focus', function() {
        if (this.value === '0') {
            this.value = '';
        }
    });

    input.addEventListener('blur', function() {
        if (this.value.trim() === '0') {
            this.value = '';
        }
    });
});

const stockInModal = new bootstrap.Modal(document.getElementById('stockInModal'));
const minStockModal = new bootstrap.Modal(document.getElementById('minStockModal'));

document.getElementById('btnOpenStockIn').addEventListener('click', () => {
    document.getElementById('stockInForm').reset();
    document.querySelectorAll('#stockInForm .traditional-weight-input').forEach(input => input.value = '');
    document.getElementById('siWeightError').style.display = 'none';
    document.getElementById('siEditId').value = '';
    document.getElementById('stockInModalTitle').innerHTML = '<i class="bi bi-box-arrow-in-down me-1"></i> নতুন স্টক যোগ করুন';
    document.getElementById('btnSaveStockInLabel').textContent = 'স্টক যোগ করুন';
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

    const outBanner = document.getElementById('stockoutBanner');
    const outCards = (cards || []).filter(c => c.is_out);
    if (outCards.length > 0) {
        document.getElementById('osbCount').textContent = outCards.length;
        document.getElementById('osbList').textContent = '(' + outCards.map(c => c.purity_label).join(',') + ')';
        outBanner.classList.remove('d-none');
    } else {
        outBanner.classList.add('d-none');
    }

    if (!cards || cards.length === 0) {
        el.innerHTML = '<div class="empty-state">কোনো ইনভেন্টরি পাওয়া যায়নি</div>';
        return;
    }
    el.innerHTML = cards.map(c => `
        <div class="karat-card ${c.is_low ? 'low' : ''} ${c.is_out ? 'out' : ''}" data-karat-card="${c.purity}">
            <div class="kc-details-inner">
                <div class="kc-header-row">
                    <span class="kc-badge"><i class="bi bi-gem"></i> ${escHtml(c.purity_label)}</span>
                    ${c.is_out ? `<span class="kc-out-pill"><i class="bi bi-x-octagon-fill"></i> স্টক আউট (${escHtml(c.purity_label)})</span>` : ''}
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
            document.querySelectorAll('#minStockForm .traditional-weight-input').forEach(input => input.value = '');
            document.getElementById('msPurity').value = btn.dataset.setMin;
            minStockModal.show();
        });
    });
    el.querySelectorAll('[data-quick-stock]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            document.getElementById('stockInForm').reset();
            document.querySelectorAll('#stockInForm .traditional-weight-input').forEach(input => input.value = '');
            document.getElementById('siPurity').value = parseFloat(btn.dataset.quickStock).toFixed(2);
            document.getElementById('siWeightError').style.display = 'none';
            document.getElementById('siEditId').value = '';
            document.getElementById('stockInModalTitle').innerHTML = '<i class="bi bi-box-arrow-in-down me-1"></i> নতুন স্টক যোগ করুন';
            document.getElementById('btnSaveStockInLabel').textContent = 'স্টক যোগ করুন';
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
                ${r.can_modify ? `
                <button type="button" class="btn-icon-round" data-edit-stock="${r.id}"
                    data-purity="${r.purity}" data-weight="${r.weight}" data-note="${r.note ? escHtml(r.note) : ''}"
                    title="সম্পাদনা করুন">
                    <i class="bi bi-pencil"></i>
                </button>
                <button type="button" class="btn-icon-round" data-del-stock="${r.id}" title="বাতিল করুন">
                    <i class="bi bi-trash3"></i>
                </button>
                ` : ''}
            </td>
        </tr>
    `).join('');

    el.querySelectorAll('[data-del-stock]').forEach(btn => {
        btn.addEventListener('click', () => deleteStockIn(btn.dataset.delStock, btn));
    });
    el.querySelectorAll('[data-edit-stock]').forEach(btn => {
        btn.addEventListener('click', () => openEditStockIn(btn));
    });
}

function openEditStockIn(btn) {
    const id = btn.dataset.editStock;
    const purity = parseFloat(btn.dataset.purity);
    const weightGrams = parseFloat(btn.dataset.weight);
    const note = btn.dataset.note || '';
    const trad = gramsToTraditional(weightGrams);

    document.getElementById('stockInForm').reset();
    document.getElementById('siEditId').value = id;
    document.getElementById('siPurity').value = purity.toFixed(2);
    document.getElementById('siVori').value = trad.vori || '';
    document.getElementById('siAna').value = trad.ana || '';
    document.getElementById('siRoti').value = trad.roti || '';
    document.getElementById('siPoint').value = trad.point || '';
    document.getElementById('siNote').value = note;
    document.getElementById('siWeightError').style.display = 'none';
    document.getElementById('stockInModalTitle').innerHTML = '<i class="bi bi-pencil me-1"></i> স্টক এন্ট্রি সম্পাদনা করুন';
    document.getElementById('btnSaveStockInLabel').textContent = 'পরিবর্তন সংরক্ষণ করুন';
    stockInModal.show();
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
    const editId = document.getElementById('siEditId').value;
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
    const label = document.getElementById('btnSaveStockInLabel');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> সংরক্ষণ হচ্ছে…';

    try {
        const fd = new FormData();
        fd.append('action', editId ? 'edit_stock_in' : 'stock_in');
        if (editId) fd.append('id', editId);
        fd.append('purity', purity);
        fd.append('vori', vori);
        fd.append('ana', ana);
        fd.append('roti', roti);
        fd.append('point', point);
        fd.append('note', note);

        const res = await fetch('inventory.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();

        if (!data.success) {
            errEl.textContent = data.message || (editId ? 'সম্পাদনা করতে ব্যর্থ হয়েছে।' : 'স্টক যোগ করতে ব্যর্থ হয়েছে।');
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
        btn.innerHTML = `<i class="bi bi-save-fill me-1"></i> <span id="btnSaveStockInLabel">${label ? label.textContent : 'স্টক যোগ করুন'}</span>`;
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