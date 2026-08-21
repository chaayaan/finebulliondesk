<?php
/**
 * gold_exchange_inventory.php  (TEST COPY — rename to gold_exchange.php when done)
 * FineBullion Desk — Gold Exchange (create new exchange)
 *
 * Business rules:
 *   - Old gold weight is entered in traditional units — Vori, Ana, Roti,
 *     Point — each a WHOLE NUMBER (no decimals). Boundaries:
 *       Vori  >= 0, no upper limit
 *       Ana   0–15
 *       Roti  0–5
 *       Point 0–9
 *   - Karat/purity is a free DECIMAL input (e.g. 10.2, 16.36, 19.00,
 *     20.59 — anything from 0.01 up to 24.00), not restricted to the
 *     18/21/22/24 preset list.
 *   - Pure Gold per item = grams * (karat / 24)
 *   - Total Pure Gold     = sum of all items' pure gold (grams)
 *   - Loss                = customizable "Points of loss per Vori of pure
 *                            gold" rate (default 1), applied to the TOTAL
 *                            pure gold, not the original impure weight.
 *   - Final Pure Gold     = Total Pure Gold - Loss
 *   - Everything is calculated in grams; traditional units are derived only
 *     for display, to avoid compounding rounding errors.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/inventory_lib.php';

// -----------------------------------------------------------------------
// Conversion constants (grams)
// -----------------------------------------------------------------------
const G_PER_VORI  = 11.664;
const G_PER_ANA   = 0.729;    // 1 Vori / 16
const G_PER_ROTI  = 0.1215;   // 1 Ana / 6
const G_PER_POINT = 0.01215;  // 1 Roti / 10

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
    $totalVori = $grams / G_PER_VORI;

    $vori = (int) floor($totalVori + 1e-9);
    $fracVori = max(0.0, $totalVori - $vori);

    $totalAna = $fracVori * 16;
    $ana = (int) floor($totalAna + 1e-9);
    $fracAna = max(0.0, $totalAna - $ana);

    $totalRoti = $fracAna * 6;
    $roti = (int) floor($totalRoti + 1e-9);
    $fracRoti = max(0.0, $totalRoti - $roti);

    $point = (int) round($fracRoti * 10);

    if ($point >= 10) { $point -= 10; $roti += 1; }
    if ($roti >= 6)   { $roti -= 6;   $ana += 1; }
    if ($ana >= 16)   { $ana -= 16;   $vori += 1; }

    return [
        'vori'  => $vori,
        'ana'   => $ana,
        'roti'  => $roti,
        'point' => $point,
    ];
}

function format_traditional(array $t): string
{
    return "{$t['vori']} ভরি {$t['ana']} আনা {$t['roti']} রতি {$t['point']} পয়েন্ট";
}

// -----------------------------------------------------------------------
// AJAX actions
// -----------------------------------------------------------------------

if ($isAjax || $action !== null) {

    if ($action === 'customer' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'অকার্যকরকাস্টমার আইডি।'], 400);

        $stmt = mysqli_prepare($conn, "SELECT id, name, phone FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$row) json_out(['success' => false, 'message' => 'গ্রাহক পাওয়া যায়নি।'], 404);
        json_out(['success' => true, 'data' => $row]);
    }

    // Live 24K stock — polled by the frontend so the summary card can warn
    // before submit if the final pure gold would exceed what's in inventory.
    // Read-only, no lock (FOR UPDATE only happens inside the save transaction).
    if ($action === 'stock_24k' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = mysqli_prepare($conn,
            "SELECT left_weight FROM inventory WHERE purity = 24.00");
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        $left = $row ? (float)$row['left_weight'] : 0.0;
        json_out([
            'success' => true,
            'left_weight'      => $left,
            'left_weight_trad' => format_traditional(grams_to_traditional($left)),
        ]);
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $note       = trim($_POST['note'] ?? '') ?: null;
        $items      = json_decode($_POST['items'] ?? '[]', true);
        $lossRate   = isset($_POST['loss_rate']) && $_POST['loss_rate'] !== ''
                        ? (float)$_POST['loss_rate']
                        : 1.0;

        if ($customerId <= 0) {
            json_out(['success' => false, 'message' => 'অনুগ্রহ করে একজনকাস্টমার নির্বাচন করুন।'], 422);
        }
        if (!is_array($items) || count($items) === 0) {
            json_out(['success' => false, 'message' => 'কমপক্ষে একটি সোনার আইটেম যোগ করুন।'], 422);
        }
        if ($lossRate < 0) {
            json_out(['success' => false, 'message' => 'ক্ষতির হার ঋণাত্মক হতে পারবে না।'], 422);
        }

        $cstmt = mysqli_prepare($conn, "SELECT id FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($cstmt, 'i', $customerId);
        mysqli_stmt_execute($cstmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($cstmt))) {
            json_out(['success' => false, 'message' => 'নির্বাচিতকাস্টমার বিদ্যমান নেই।'], 404);
        }

        $calcItems = [];
        $totalPureGrams = 0.0;

        foreach ($items as $i => $item) {
            $n = $i + 1;

            foreach (['vori', 'ana', 'roti', 'point'] as $field) {
                $raw = $item[$field] ?? 0;
                if (!is_numeric($raw) || (float)$raw != (int)$raw) {
                    json_out(['success' => false, 'message' => "আইটেম $n: " . ucfirst($field) . " অবশ্যই পূর্ণসংখ্যা হতে হবে।"], 422);
                }
            }

            $vori  = (int)($item['vori']  ?? 0);
            $ana   = (int)($item['ana']   ?? 0);
            $roti  = (int)($item['roti']  ?? 0);
            $point = (int)($item['point'] ?? 0);
            $karat = (float)($item['karat'] ?? 0);

            if ($vori < 0) {
                json_out(['success' => false, 'message' => "আইটেম $n: ভরি ঋণাত্মক হতে পারবে না।"], 422);
            }
            if ($ana < 0 || $ana > 15) {
                json_out(['success' => false, 'message' => "আইটেম $n: আনা ০ থেকে ১৫ এর মধ্যে হতে হবে।"], 422);
            }
            if ($roti < 0 || $roti > 5) {
                json_out(['success' => false, 'message' => "আইটেম $n: রতি ০ থেকে ৫ এর মধ্যে হতে হবে।"], 422);
            }
            if ($point < 0 || $point > 9) {
                json_out(['success' => false, 'message' => "আইটেম $n: পয়েন্ট ০ থেকে ৯ এর মধ্যে হতে হবে।"], 422);
            }
            if ($karat < 0.01 || $karat > 24) {
                json_out(['success' => false, 'message' => "আইটেম $n: ক্যারেট ০.০১ থেকে ২৪.০০ এর মধ্যে হতে হবে।"], 422);
            }

            $grams = traditional_to_grams($vori, $ana, $roti, $point);
            if ($grams <= 0) {
                json_out(['success' => false, 'message' => "আইটেম $n: ওজন শূন্যের চেয়ে বেশি হতে হবে।"], 422);
            }

            $purityPct = ($karat / 24) * 100;
            $pureGold  = $grams * ($karat / 24);

            $totalPureGrams += $pureGold;

            $calcItems[] = [
                'old_gold_weight'  => $grams,
                'old_gold_purity'  => $purityPct,
                'pure_gold_weight' => $pureGold,
            ];
        }

        $totalPureVori   = $totalPureGrams / G_PER_VORI;
        $lossPointsExact = $totalPureVori * $lossRate;
        $lossPointsCeil  = (int) ceil($lossPointsExact);
        $lossGrams       = $lossPointsCeil * G_PER_POINT;

        $finalPureGrams = $totalPureGrams - $lossGrams;
        if ($finalPureGrams < 0) $finalPureGrams = 0.0;

        $userId = $currentUser['id'];

        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO gold_exchanges
                    (customer_id, total_pure_gold, loss, final_pure_gold, loss_rate_points_per_vori, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'iddddsi',
                $customerId, $totalPureGrams, $lossGrams, $finalPureGrams, $lossRate, $note, $userId);
            mysqli_stmt_execute($stmt);
            $exchangeId = (int) mysqli_insert_id($conn);

            $itemStmt = mysqli_prepare($conn,
                "INSERT INTO gold_exchange_items (gold_exchange_id, old_gold_weight, old_gold_purity, pure_gold_weight)
                 VALUES (?, ?, ?, ?)");

            foreach ($calcItems as $ci) {
                mysqli_stmt_bind_param($itemStmt, 'iddd',
                    $exchangeId, $ci['old_gold_weight'], $ci['old_gold_purity'], $ci['pure_gold_weight']);
                mysqli_stmt_execute($itemStmt);
            }

            // Deduct 24K inventory by the final pure gold output. Only the
            // OUTPUT (final pure gold) leaves inventory — the raw old-gold
            // items coming in are never inventory rows themselves.
            if ($finalPureGrams > 0) {
                inventory_deduct($conn, 24.00, $finalPureGrams, '২৪K বিনিময়');
            }

            mysqli_commit($conn);
        } catch (InventoryException $e) {
            mysqli_rollback($conn);
            json_out(['success' => false, 'message' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            json_out(['success' => false, 'message' => 'এক্সচেঞ্জ সেভ করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।'], 500);
        }

        json_out([
            'success' => true,
            'message' => 'এক্সচেঞ্জ সেভ হয়েছে।',
            'id'      => $exchangeId,
            'summary' => [
                'total_pure_gold' => $totalPureGrams,
                'loss'             => $lossGrams,
                'final_pure_gold'  => $finalPureGrams,
                'loss_rate'        => $lossRate,
            ],
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
<title>সোনা বদল — FineBullion Desk</title>
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
    --shadow: 0 2px 8px rgba(47, 65, 86, 0.08);
}

/* Global Reset */
html, body {
    margin: 0 !important;
    padding: 0 !important;
    background: var(--bg-app);
    font-family: 'Inter', 'Noto Sans Bengali', system-ui, -apple-system, sans-serif;
    color: var(--text-primary);
}

.page-content {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

.page-content > .container-fluid:first-child {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

/* Page Header */
.ge-header,
.ge-header.d-flex {
    background: var(--navy) !important;
    color: var(--text-on-navy) !important;
    min-height: 60px !important;
    max-height: 80px !important;
    padding: 1rem 1.75rem !important;
    margin: 0 0 1.5rem 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    position: relative;
    top: 0;
    border-top-left-radius: 0 !important;
    border-top-right-radius: 0 !important;
    border-bottom-left-radius: 16px !important;
    border-bottom-right-radius: 16px !important;
    box-shadow: none;
    box-sizing: border-box;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: nowrap !important;
    gap: 1rem !important;
    overflow: hidden;
}

.ge-header h4 {
    color: var(--text-on-navy) !important;
    font-weight: 700;
    letter-spacing: 0.02em;
    margin: 0 !important;
    text-align: left !important;
    font-size: 22px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ge-header i {
    color: var(--text-on-navy) !important;
}

.ge-header .btn-history {
    background: rgba(255, 255, 255, 0.12);
    border: 1.5px solid rgba(255, 255, 255, 0.5);
    color: #ffffff;
    border-radius: 8px;
    font-weight: 600;
    flex-shrink: 0;
    font-size: 13px;
    padding: 0.4rem 0.8rem;
}

.ge-header .btn-history:hover {
    background: #ffffff;
    color: var(--navy);
    border-color: #ffffff;
}

.page-inset { padding: 0 1.5rem; }

.customer-result-item {
    cursor: pointer;
    padding: 0.55rem 0.9rem;
    border-bottom: 1px solid var(--border-default);
}
.customer-result-item:hover { background: var(--bg-hover); }
.customer-result-item:last-child { border-bottom: none; }
.customer-result-photo {
    width: 32px; height: 32px; border-radius: 50%; object-fit: cover;
    border: 1px solid var(--border-default); flex-shrink: 0;
}
.customer-result-photo-fallback {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    background: var(--navy); color: #fff; font-size: 0.8rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}

.customer-results-box {
    position: absolute;
    z-index: 20;
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 12px;
    box-shadow: var(--shadow);
    width: 100%;
    max-height: 260px;
    overflow-y: auto;
    display: none;
}

.selected-customer-card {
    border: 1.5px solid var(--teal);
    background: var(--sky);
    border-radius: 14px;
    padding: 0.75rem 1rem;
    display: none;
    align-items: center;
    justify-content: space-between;
}

.gold-item-card {
    border: 1px solid var(--border-default);
    border-radius: 14px;
    padding: 1rem 1.1rem;
    margin-bottom: 1rem;
    background: var(--bg-card);
    position: relative;
    box-shadow: var(--shadow);
}
.gold-item-card .item-index {
    position: absolute;
    top: -10px;
    left: 14px;
    background: var(--navy);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    padding: 0.1rem 0.6rem;
    border-radius: 8px;
}
.gold-item-card .btn-remove-item {
    position: absolute;
    top: 10px;
    right: 10px;
    border-radius: 8px;
    border: 1.5px solid var(--danger);
    background: #fff;
    color: var(--danger);
    font-weight: 600;
    font-size: 13px;
    padding: 0.25rem 0.6rem;
}
.gold-item-card .btn-remove-item:hover {
    background: var(--danger);
    color: #fff;
}

.item-pure-result {
    background: #EAF3EE;
    border: 1px dashed var(--success);
    border-radius: 8px;
    padding: 0.5rem 0.8rem;
    font-size: 13.5px;
    color: var(--success);
    font-weight: 600;
}

/* Summary Card */
.summary-card {
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 14px;
    box-shadow: var(--shadow);
    overflow: hidden;
}
.summary-card .sum-header {
    background: var(--navy);
    color: #fff;
    padding: 0.75rem 1.2rem;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
}
.summary-card .sum-body { padding: 0.9rem 1.2rem; }
.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.45rem 0;
    border-bottom: 1px solid var(--border-default);
}
.summary-row:last-of-type { border-bottom: none; }
.summary-row .s-label { font-size: 12.5px; color: var(--text-secondary); font-weight: 500; }
.summary-row .s-value { font-weight: 700; font-size: 14px; color: var(--text-primary); }

.loss-rate-input {
    border: 1.5px solid var(--border-default);
    text-align: right;
    border-radius: 8px;
    font-weight: 700;
    padding: 0.2rem 0.4rem;
    color: var(--text-primary);
    background: #fff;
}
.loss-rate-input:focus {
    outline: none;
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(86,124,141,.15);
}

.final-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 0.6rem 0 0.2rem;
    border-top: 2px solid var(--navy);
    margin-top: 0.4rem;
}
.final-row .s-label { font-size: 14px; font-weight: 700; color: var(--text-primary); }
.final-row .s-value { font-size: 16px; font-weight: 800; color: var(--navy); }

.stock-status-row {
    border-radius: 8px;
    padding: 0.65rem 0.8rem;
    background: #EAF3EE;
    border: 1px solid rgba(61, 122, 92, 0.2);
    transition: background 0.15s ease, border-color 0.15s ease;
}
.stock-status-row .ss-top { display: flex; justify-content: space-between; align-items: center; }
.stock-status-row .s-label { font-size: 12.5px; color: var(--text-secondary); }
.stock-status-row .s-value { font-size: 13.5px; font-weight: 700; color: var(--success); }
.stock-status-row .ss-warning {
    display: flex; align-items: center;
    margin-top: 0.4rem; padding-top: 0.4rem;
    border-top: 1px dashed rgba(166, 67, 75, 0.25);
    font-size: 12px; font-weight: 600; color: var(--danger);
}
.stock-status-row.insufficient {
    background: #FBECEC;
    border-color: rgba(166, 67, 75, 0.25);
}
.stock-status-row.insufficient .s-value { color: var(--danger); }

.btn-gold, .btn-fb-primary {
    background: var(--navy);
    border: 1.5px solid var(--navy);
    color: #ffffff;
    font-weight: 600;
    border-radius: 8px;
}
.btn-gold:hover, .btn-gold:focus,
.btn-fb-primary:hover, .btn-fb-primary:focus {
    background: var(--teal);
    border-color: var(--teal);
    color: #ffffff;
}

.btn-fb-secondary {
    background: #ffffff;
    border: 1.5px solid var(--border-default);
    color: var(--navy);
    font-weight: 600;
    border-radius: 8px;
}
.btn-fb-secondary:hover {
    background: var(--bg-hover);
    border-color: var(--teal);
    color: var(--navy);
}

.btn-outline-danger {
    border-radius: 8px !important;
    border: 1.5px solid var(--danger) !important;
    color: var(--danger) !important;
    background: #ffffff !important;
    font-weight: 600;
}
.btn-outline-danger:hover {
    background: var(--danger) !important;
    border-color: var(--danger) !important;
    color: #ffffff !important;
}

.card {
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 14px;
    box-shadow: var(--shadow);
}
.card-header {
    background: var(--beige) !important;
    border-bottom: 1px solid var(--border-default);
    border-radius: 14px 14px 0 0 !important;
    color: var(--text-primary);
    font-weight: 700;
}

.form-control {
    background: #fff;
    border: 1.5px solid var(--border-default);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    padding: .55rem .75rem;
    transition: border-color .15s, box-shadow .15s;
}
.form-control:focus {
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(86,124,141,.15);
    outline: none;
}

.item-fields-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.5rem;
}
.item-fields-row .field-col label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-bottom: 0.3rem;
    color: var(--text-secondary);
    white-space: nowrap;
}
.item-fields-row .field-col input {
    text-align: center;
    padding-left: 0.25rem;
    padding-right: 0.25rem;
}

.item-fields-row input.form-control.is-valid,
.item-fields-row input.form-control.is-invalid,
.karat-row input.form-control.is-valid,
.karat-row input.form-control.is-invalid {
    background-image: none !important;
    padding-right: 0.25rem !important;
}
.item-fields-row input.form-control.is-invalid,
.karat-row input.form-control.is-invalid {
    border-color: var(--danger);
    box-shadow: 0 0 0 3px rgba(166,67,75,.12);
}

.karat-row {
    margin-top: 0.6rem;
}
.karat-row label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-bottom: 0.3rem;
    color: var(--text-secondary);
}

@media (max-width: 576px) {
    .page-content .container-fluid { padding: 0 !important; }
    .page-inset { padding: 0 1rem; }

    .ge-header {
        min-height: auto !important;
        max-height: none !important;
        padding: 0.85rem 1.1rem !important;
        border-radius: 0 0 14px 14px !important;
        margin-bottom: 1rem !important;
    }
    .ge-header h4 { font-size: 18px !important; }
    .ge-header .btn-history { padding: 0.3rem 0.6rem !important; font-size: 12px !important; }

    .row.g-4 { --bs-gutter-y: 0.8rem; }

    .card { margin-bottom: 0.8rem !important; border-radius: 14px; }
    .card-header { padding: 0.55rem 0.85rem; font-size: 14px; border-radius: 14px 14px 0 0 !important; }
    .card-body { padding: 0.85rem; }

    #customerSearch { font-size: 16px; padding: 0.6rem 0.8rem; }
    .selected-customer-card { padding: 0.6rem 0.8rem; }

    .gold-item-card { padding: 0.85rem; margin-bottom: 0; border-radius: 14px; }
    .gold-item-card .item-index { top: -9px; left: 12px; font-size: 10px; padding: 0.08rem 0.5rem; }
    .gold-item-card .btn-remove-item { top: 6px; right: 6px; padding: 0.2rem 0.5rem; font-size: 12px; }
    .gold-item-card .form-control-sm { font-size: 16px; padding: 0.6rem 0.8rem; }
    .item-fields-row { gap: 0.4rem; }
    .item-pure-result { padding: 0.4rem 0.7rem; font-size: 12.5px; margin-top: 0.5rem !important; }

    #note { min-height: 44px; font-size: 16px; padding: 0.6rem 0.8rem; }

    .summary-card { border-radius: 14px; }
    .summary-card .sum-header { font-size: 11px; padding: 0.6rem 0.9rem; }
    .summary-card .sum-body { padding: 0.85rem; }
    .summary-row { padding: 0.35rem 0; }
    .summary-row .s-label { font-size: 12px; }
    .summary-row .s-value { font-size: 13.5px; }
    .final-row .s-label { font-size: 13.5px; }
    .final-row .s-value { font-size: 15px; }
    .loss-rate-input { width: 55px !important; padding: 0.2rem 0.35rem; font-size: 14px; }

    #btnSave { padding: 0.6rem 1rem; font-size: 13.5px; margin-top: 0.6rem !important; }
    #noteCard { display: none !important; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0">

    <!-- Isolated Page Header -->
    <div class="ge-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-arrow-left-right text-white fs-4"></i>
            <h4 class="m-0 text-white fw-bold">সোনা বদল</h4>
        </div>
        <div>
            <a href="gold_exchange_list.php" class="btn btn-history d-inline-flex align-items-center gap-1">
                <i class="bi bi-list-ul"></i>
                <span>এক্সচেঞ্জ ইতিহাস</span>
            </a>
        </div>
    </div>

    <div class="page-inset">
    <form id="exchangeForm" autocomplete="off">
        <div class="row g-4">
            <div class="col-lg-8">

                <!-- Customer -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-person-fill me-1 text-teal"></i>কাস্টমার খুঁজুন
                    </div>
                    <div class="card-body">
                        <div class="position-relative">
                            <input type="text" class="form-control" id="customerSearch"
                                   placeholder="নাম বা ফোন নম্বর দিয়ে কাস্টমার খুঁজুন…">
                            <div class="customer-results-box" id="customerResults"></div>
                        </div>
                        <input type="hidden" id="customerId" name="customer_id">

                        <div class="selected-customer-card mt-3" id="selectedCustomerCard">
                            <div>
                                <div class="fw-semibold" id="selectedCustomerName">—</div>
                                <small class="text-secondary" id="selectedCustomerPhone">—</small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger d-inline-flex align-items-center" id="btnClearCustomer">
                                <i class="bi bi-x-lg"></i> <span class="d-none d-sm-inline ms-1">মুছে ফেলুন</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Old gold items -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-semibold"><i class="bi bi-gem me-1 text-teal"></i> পুরাতন / অপাকা সোনা</span>
                        <button type="button" class="btn btn-sm btn-gold d-inline-flex align-items-center" id="btnAddItem">
                            <i class="bi bi-plus-lg me-1"></i> <span>আইটেম যোগ করুন</span>
                        </button>
                    </div>
                    <div class="card-body" id="itemsContainer">
                        <!-- items injected by JS -->
                    </div>
                </div>

                <!-- Note -->
                <div class="card shadow-sm mb-4" id="noteCard">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-pencil-square me-1 text-teal"></i> নোট / মন্তব্য
                    </div>
                    <div class="card-body">
                        <textarea class="form-control" id="note" name="note" rows="2" placeholder="ঐচ্ছিক নোট…"></textarea>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="summary-card shadow-sm sticky-top" style="top: 1rem;">
                    <div class="sum-header">
                        <i class="bi bi-calculator me-1"></i> এক্সচেঞ্জ সারাংশ
                    </div>
                    <div class="sum-body">
                        <div class="summary-row">
                            <span class="s-label">মোট পাকা সোনা</span>
                            <span class="s-value" id="sumTotalPure">০ ভরি ০ আনা ০ রতি ০ পয়েন্ট</span>
                        </div>

                        <div class="summary-row align-items-center">
                            <span class="s-label">ক্ষতি হার (পয়েন্ট/ভরি)</span>
                            <span class="d-flex align-items-center gap-1">
                                <input type="number" id="lossRateInput" min="0" step="0.001" value="1"
                                       class="form-control form-control-sm loss-rate-input" style="width:70px;">
                                <small class="text-secondary">পয়েন্ট/ভরি</small>
                            </span>
                        </div>

                        <div class="summary-row">
                            <span class="s-label">মোট ক্ষতি</span>
                            <span class="s-value" id="sumLoss">০ পয়েন্ট</span>
                        </div>
                        <div class="summary-row">
                            <span class="text-secondary" id="sumLossTrad" style="font-size:0.75rem;">০ ভরি ০ আনা ০ রতি ০ পয়েন্ট</span>
                        </div>

                        <div class="final-row mt-2">
                            <span class="s-label">অবশিষ্ট পাকা সোনা</span>
                            <span class="s-value" id="sumFinalPure">০ ভরি ০ আনা ০ রতি ০ পয়েন্ট</span>
                        </div>

                        <div class="stock-status-row mt-2" id="stockStatusRow">
                            <div class="ss-top">
                                <span class="s-label"><i class="bi bi-box-seam me-1"></i> বর্তমান ২৪K মজুদ</span>
                                <span class="s-value" id="sumStock24k">লোড হচ্ছে…</span>
                            </div>
                            <div class="ss-warning" id="stockWarning" style="display:none;">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                <span id="stockWarningText">পর্যাপ্ত মজুদ নেই</span>
                            </div>
                        </div>
                    </div>

                    <div class="px-3 pb-3 mt-1">
                        <button type="submit" class="btn btn-gold w-100 py-2" id="btnSave">
                            <i class="bi bi-save-fill me-1"></i> এক্সচেঞ্জ সেভ করুন
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
    </div>

</div>
</div>

<template id="itemTemplate">
    <div class="gold-item-card" data-item>
        <span class="item-index">আইটেম <span data-item-num></span></span>
        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item d-inline-flex align-items-center" data-remove>
            <i class="bi bi-trash3"></i> <span class="d-none d-sm-inline ms-1">মুছে ফেলুন</span>
        </button>
        <div class="item-fields-row mt-2">
            <div class="field-col">
                <label>ভরি</label>
                <input type="number" min="0" step="1" class="form-control form-control-sm" data-field="vori" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="vori"></div>
            </div>
            <div class="field-col">
                <label>আনা</label>
                <input type="number" min="0" max="15" step="1" class="form-control form-control-sm" data-field="ana" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="ana"></div>
            </div>
            <div class="field-col">
                <label>রতি</label>
                <input type="number" min="0" max="5" step="1" class="form-control form-control-sm" data-field="roti" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="roti"></div>
            </div>
            <div class="field-col">
                <label>পয়েন্ট</label>
                <input type="number" min="0" max="9" step="1" class="form-control form-control-sm" data-field="point" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="point"></div>
            </div>
        </div>
        <div class="karat-row">
            <label>ক্যারেট</label>
            <input type="number" min="0.01" max="24" step="0.01" class="form-control form-control-sm" data-field="karat" value="22" placeholder="যেমন: ১৯.০০">
            <div class="invalid-feedback" data-error="karat"></div>
        </div>
        <div class="item-pure-result mt-2" data-pure-result>
            পাকা সোনা: ০ ভরি ০ আনা ০ রতি ০ পয়েন্ট
        </div>
    </div>
</template>

<script>
const G_PER_VORI  = 11.664;
const G_PER_ANA   = 0.729;
const G_PER_ROTI  = 0.1215;
const G_PER_POINT = 0.01215;

function traditionalToGrams(vori, ana, roti, point) {
    return (vori * G_PER_VORI) + (ana * G_PER_ANA) + (roti * G_PER_ROTI) + (point * G_PER_POINT);
}

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
    if (roti >= 6)   { roti -= 6;   ana += 1; }
    if (ana >= 16)   { ana -= 16;   vori += 1; }

    return { vori, ana, roti, point };
}

function formatTraditional(t) {
    return `${t.vori} ভরি ${t.ana} আনা ${t.roti} রতি ${t.point} পয়েন্ট`;
}

const customerSearch  = document.getElementById('customerSearch');
const customerResults = document.getElementById('customerResults');
const customerIdInput = document.getElementById('customerId');
const selectedCard    = document.getElementById('selectedCustomerCard');
const selectedName    = document.getElementById('selectedCustomerName');
const selectedPhone   = document.getElementById('selectedCustomerPhone');

let searchTimer = null;

function escAttr(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}
function escHtml(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

customerSearch.addEventListener('input', function () {
    const q = this.value.trim();
    clearTimeout(searchTimer);

    if (q.length < 2) {
        customerResults.style.display = 'none';
        customerResults.innerHTML = '';
        return;
    }

    searchTimer = setTimeout(async () => {
        try {
            const res = await fetch('customer_search.php?q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();

            if (!data.success) {
                customerResults.innerHTML = '<div class="p-2 text-danger small">অনুসন্ধান ব্যর্থ হয়েছে।</div>';
                customerResults.style.display = 'block';
                return;
            }

            const list = data.data || [];

            if (list.length === 0) {
                customerResults.innerHTML = '<div class="p-2 text-muted small">কোনোকাস্টমার পাওয়া যায়নি।</div>';
                customerResults.style.display = 'block';
                return;
            }

            customerResults.innerHTML = list.map(c => `
                <div class="customer-result-item d-flex align-items-center gap-2"
                     data-id="${c.id}"
                     data-name="${escAttr(c.name)}"
                     data-phone="${escAttr(c.phone)}">
                    ${c.photo_path
                        ? `<img src="${escAttr(c.photo_path)}" class="customer-result-photo" alt="">`
                        : `<div class="customer-result-photo-fallback">${escHtml((c.name || '?').charAt(0).toUpperCase())}</div>`}
                    <div class="flex-grow-1">
                        <div class="fw-semibold">${escHtml(c.name)}</div>
                        <small class="text-secondary">${escHtml(c.phone)}${c.address ? ' · ' + escHtml(c.address) : ''}</small>
                    </div>
                </div>
            `).join('');
            customerResults.style.display = 'block';
        } catch (err) {
            customerResults.innerHTML = '<div class="p-2 text-danger small">অনুসন্ধান ব্যর্থ হয়েছে।</div>';
            customerResults.style.display = 'block';
        }
    }, 300);
});

customerResults.addEventListener('click', function (e) {
    const item = e.target.closest('.customer-result-item');
    if (!item) return;

    customerIdInput.value = item.dataset.id;
    selectedName.textContent = item.dataset.name;
    selectedPhone.textContent = item.dataset.phone;
    selectedCard.style.display = 'flex';
    customerSearch.value = '';
    customerResults.style.display = 'none';
    customerResults.innerHTML = '';
});

document.getElementById('btnClearCustomer').addEventListener('click', function () {
    customerIdInput.value = '';
    selectedCard.style.display = 'none';
});

document.addEventListener('click', function (e) {
    if (!e.target.closest('#customerSearch') && !e.target.closest('#customerResults')) {
        customerResults.style.display = 'none';
    }
});

const itemsContainer = document.getElementById('itemsContainer');
const itemTemplate    = document.getElementById('itemTemplate');
let itemCounter = 0;

const FIELD_RULES = {
    vori:  { min: 0,    max: null, label: 'ভরি'  },
    ana:   { min: 0,    max: 15,   label: 'আনা'   },
    roti:  { min: 0,    max: 5,    label: 'রতি'  },
    point: { min: 0,    max: 9,    label: 'পয়েন্ট' },
};

function validateField(input, field) {
    const raw   = input.value;
    const rules = FIELD_RULES[field];
    const errEl = input.parentElement.querySelector(`[data-error="${field}"]`);

    if (raw === '' || raw === null) {
        input.classList.remove('is-invalid', 'is-valid');
        if (errEl) errEl.textContent = '';
        return { valid: true, value: 0 };
    }

    if (/[.,]/.test(raw)) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} অবশ্যই পূর্ণসংখ্যা হতে হবে (দশমিক নয়)।`;
        return { valid: false, value: 0 };
    }

    const n = Number(raw);

    if (!Number.isFinite(n) || !Number.isInteger(n)) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} অবশ্যই পূর্ণসংখ্যা হতে হবে।`;
        return { valid: false, value: 0 };
    }

    if (n < rules.min) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} ঋণাত্মক হতে পারবে না।`;
        return { valid: false, value: 0 };
    }
    if (rules.max !== null && n > rules.max) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label}-এর সর্বোচ্চ মান ${rules.max}।`;
        return { valid: false, value: rules.max };
    }

    input.classList.remove('is-invalid');
    input.classList.add('is-valid');
    if (errEl) errEl.textContent = '';
    return { valid: true, value: n };
}

function getItemValues(card) {
    let allValid = true;
    const results = {};

    for (const field of ['vori', 'ana', 'roti', 'point']) {
        const input = card.querySelector(`[data-field="${field}"]`);
        const { valid, value } = validateField(input, field);
        if (!valid) allValid = false;
        results[field] = value;
    }

    const karatInput = card.querySelector('[data-field="karat"]');
    const karatErrEl = karatInput.parentElement.querySelector('[data-error="karat"]');
    const karat = parseFloat(karatInput.value);

    if (isNaN(karat) || karat < 0.01 || karat > 24) {
        karatInput.classList.add('is-invalid');
        karatInput.classList.remove('is-valid');
        if (karatErrEl) karatErrEl.textContent = 'ক্যারেট ০.০১ থেকে ২৪.০০ এর মধ্যে হতে হবে।';
        allValid = false;
        results.karat = 22;
    } else {
        karatInput.classList.remove('is-invalid');
        karatInput.classList.add('is-valid');
        if (karatErrEl) karatErrEl.textContent = '';
        results.karat = karat;
    }

    results.allValid = allValid;
    return results;
}

function calcItemPure(v) {
    const grams = traditionalToGrams(v.vori, v.ana, v.roti, v.point);
    const pureGrams = grams * (v.karat / 24);
    const pureTrad  = gramsToTraditional(pureGrams);
    return { grams, pureGrams, pureTrad };
}

function renderItem(card) {
    const v = getItemValues(card);
    const { pureTrad } = calcItemPure(v);
    card.querySelector('[data-pure-result]').textContent = 'পাকা সোনা: ' + formatTraditional(pureTrad);
}

function renumberItems() {
    itemsContainer.querySelectorAll('[data-item]').forEach((card, idx) => {
        card.querySelector('[data-item-num]').textContent = idx + 1;
    });
}

function addItem() {
    itemCounter++;
    const node = itemTemplate.content.cloneNode(true);
    const card = node.querySelector('[data-item]');
    card.dataset.itemId = itemCounter;
    node.querySelector('[data-item-num]').textContent = itemCounter;

    card.querySelectorAll('input').forEach(el => {
        el.addEventListener('input',  () => { renderItem(card); renderSummary(); });
        el.addEventListener('change', () => { renderItem(card); renderSummary(); });
    });

    node.querySelector('[data-remove]').addEventListener('click', () => {
        card.remove();
        renumberItems();
        renderSummary();
    });

    itemsContainer.appendChild(node);
    renderItem(itemsContainer.querySelector(`[data-item-id="${itemCounter}"]`));
    renderSummary();
}

document.getElementById('btnAddItem').addEventListener('click', addItem);

document.getElementById('lossRateInput').addEventListener('input', renderSummary);

// -----------------------------------------------------------------------
// Live 24K stock (fetched once on load; re-checked against the running
// final-pure-gold total on every summary render)
// -----------------------------------------------------------------------
let stock24kGrams = null;   // null = not loaded yet
let stock24kTrad  = 'লোড হচ্ছে…';

async function loadStock24k() {
    try {
        const res = await fetch('gold_exchange_inventory.php?action=stock_24k', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        if (data.success) {
            stock24kGrams = data.left_weight;
            stock24kTrad  = data.left_weight_trad;
        } else {
            stock24kTrad = 'অজানা';
        }
    } catch {
        stock24kTrad = 'লোড ব্যর্থ';
    }
    renderSummary();
}

function renderSummary() {
    let totalPureGrams = 0;

    itemsContainer.querySelectorAll('[data-item]').forEach(card => {
        const v = getItemValues(card);
        const { pureGrams } = calcItemPure(v);
        totalPureGrams += pureGrams;
    });

    const lossRate = parseFloat(document.getElementById('lossRateInput').value) || 0;
    const totalPureVori   = totalPureGrams / G_PER_VORI;
    const lossPointsExact = totalPureVori * lossRate;
    const lossPointsCeil  = Math.ceil(lossPointsExact);
    const lossGrams       = lossPointsCeil * G_PER_POINT;
    const finalPureGrams  = Math.max(0, totalPureGrams - lossGrams);

    document.getElementById('sumTotalPure').textContent = formatTraditional(gramsToTraditional(totalPureGrams));
    document.getElementById('sumLoss').textContent      = `${lossPointsCeil} পয়েন্ট`;
    document.getElementById('sumLossTrad').textContent  = formatTraditional(gramsToTraditional(lossGrams));
    document.getElementById('sumFinalPure').textContent = formatTraditional(gramsToTraditional(finalPureGrams));

    // Stock status
    const stockRow     = document.getElementById('stockStatusRow');
    const stockValueEl = document.getElementById('sumStock24k');
    const warningEl     = document.getElementById('stockWarning');
    const warningTextEl = document.getElementById('stockWarningText');

    stockValueEl.textContent = stock24kTrad;

    if (stock24kGrams === null) {
        // Still loading / failed to load — don't claim insufficiency either way.
        stockRow.classList.remove('insufficient');
        warningEl.style.display = 'none';
        window.__stockInsufficient = false;
        return;
    }

    const shortfall = finalPureGrams - stock24kGrams;
    if (shortfall > 0.0005) {
        stockRow.classList.add('insufficient');
        warningEl.style.display = 'flex';
        warningTextEl.textContent =
            `পর্যাপ্ত ২৪K মজুদ নেই — ঘাটতি: ${formatTraditional(gramsToTraditional(shortfall))}`;
        window.__stockInsufficient = true;
    } else {
        stockRow.classList.remove('insufficient');
        warningEl.style.display = 'none';
        window.__stockInsufficient = false;
    }
}

// Start with one item
addItem();
loadStock24k();

// Save
document.getElementById('exchangeForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    if (!customerIdInput.value) {
        alert('অনুগ্রহ করে একজনকাস্টমার নির্বাচন করুন।');
        return;
    }

    const items = [];
    let hasError = false;

    itemsContainer.querySelectorAll('[data-item]').forEach(card => {
        const v = getItemValues(card);
        if (!v.allValid) hasError = true;
        items.push({ vori: v.vori, ana: v.ana, roti: v.roti, point: v.point, karat: v.karat });
    });

    if (items.length === 0) {
        alert('কমপক্ষে একটি সোনার আইটেম যোগ করুন।');
        return;
    }
    if (hasError) {
        alert('অনুগ্রহ করে সেভ করার আগে চিহ্নিত ভুলগুলো ঠিক করুন।');
        return;
    }

    for (const it of items) {
        if (traditionalToGrams(it.vori, it.ana, it.roti, it.point) <= 0) {
            alert('প্রতিটি আইটেমের ওজন শূন্যের চেয়ে বেশি হতে হবে।');
            return;
        }
    }

    // Soft client-side warning — the server (§ inventory_deduct) is the
    // real authority and will reject with a precise message if stock ran
    // out between the last poll and now; this just saves a round trip.
    if (window.__stockInsufficient) {
        const proceed = confirm(
            'সতর্কতা: বর্তমান ২৪K মজুদ পর্যাপ্ত নয় বলে মনে হচ্ছে। তবুও সেভ করার চেষ্টা করবেন? ' +
            '(সার্ভার প্রকৃত মজুদ যাচাই করে চূড়ান্ত সিদ্ধান্ত নেবে।)'
        );
        if (!proceed) return;
    }

    const lossRate = parseFloat(document.getElementById('lossRateInput').value) || 0;

    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> সেভ হচ্ছে…';

    try {
        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('customer_id', customerIdInput.value);
        fd.append('loss_rate', lossRate);
        fd.append('note', document.getElementById('note').value);
        fd.append('items', JSON.stringify(items));

        const res = await fetch('gold_exchange_inventory.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        });
        const data = await res.json();

        if (data.success) {
            window.location.href = 'gold_exchange_list.php';
        } else {
            alert(data.message || 'এক্সচেঞ্জ সেভ করতে ব্যর্থ হয়েছে।');
            loadStock24k(); // refresh — the failure may itself be a stock shortage
        }
    } catch {
        alert('নেটওয়ার্ক ত্রুটি। আবার চেষ্টা করুন।');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-save-fill me-1"></i> এক্সচেঞ্জ সেভ করুন';
    }
});
</script>

</body>
</html>