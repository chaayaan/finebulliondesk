<?php
/**
 * gold_sale_inventory.php
 * FineBullion Desk — Gold Sale (create new sale) — Inventory-integrated build
 *
 * Business rules:
 *   - Pure Gold Price (24k) is entered in BDT per Vori.
 *   - Sale items are sold at a specific KARAT, restricted to the 5 karats
 *     tracked by the Inventory module: 18K / 20K / 21K / 22K / 24K
 *   - Item price  = (item_grams / G_PER_VORI) * (purity/24) * pure_gold_price_per_vori
 *   - Total price = sum of all item prices
 *   - Due Amount  = Total Price – Paid Amount
 *   - Inventory deduction and live stock tracking included.
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

const SALE_KARATS = [18.00, 20.00, 21.00, 22.00, 24.00];

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
    if ($roti >= 6)   { $roti -= 6;   $ana  += 1; }
    if ($ana >= 16)   { $ana  -= 16;  $vori += 1; }

    return ['vori' => $vori, 'ana' => $ana, 'roti' => $roti, 'point' => $point];
}

function format_traditional(array $t): string
{
    return "{$t['vori']} ভরি {$t['ana']} আনা {$t['roti']} রতি {$t['point']} পয়েন্ট";
}

// -----------------------------------------------------------------------
// AJAX actions
// -----------------------------------------------------------------------
if ($isAjax || $action !== null) {

    // ---- Customer quick lookup ----
    if ($action === 'customer' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'কাস্টমার আইডি সঠিক নয়।'], 400);

        $stmt = mysqli_prepare($conn, "SELECT id, name, phone FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$row) json_out(['success' => false, 'message' => 'কাস্টমার পাওয়া যায়নি।'], 404);
        json_out(['success' => true, 'data' => $row]);
    }

    // ---- Live inventory stock (all 5 karats) ----
    if ($action === 'stock_all' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $res = mysqli_query($conn,
            "SELECT purity, left_weight FROM inventory WHERE purity IN (18.00,20.00,21.00,22.00,24.00)");
        $byPurity = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $byPurity[number_format((float)$row['purity'], 2, '.', '')] = (float)$row['left_weight'];
        }

        $stock = [];
        foreach (SALE_KARATS as $k) {
            $key  = number_format($k, 2, '.', '');
            $left = $byPurity[$key] ?? 0.0;
            $stock[] = [
                'purity'            => $k,
                'label'             => karat_label($k),
                'left_weight'       => $left,
                'left_weight_trad'  => grams_to_traditional($left),
            ];
        }

        json_out(['success' => true, 'stock' => $stock]);
    }

    // ---- SAVE sale ----
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $customerId      = (int)($_POST['customer_id']       ?? 0);
        $pureGoldPrice   = (float)($_POST['pure_gold_price'] ?? 0);
        $paidAmount      = (float)($_POST['paid_amount']      ?? 0);
        $paymentDate     = date('Y-m-d');
        $note            = trim($_POST['note'] ?? '') ?: null;
        $items           = json_decode($_POST['items'] ?? '[]', true);
        $userId          = $currentUser['id'];

        if ($customerId <= 0) {
            json_out(['success' => false, 'message' => 'অনুগ্রহ করে একজন কাস্টমার নির্বাচন করুন।'], 422);
        }
        if ($pureGoldPrice <= 0) {
            json_out(['success' => false, 'message' => 'পাকা সোনার দাম শূন্যের বেশি হতে হবে।'], 422);
        }
        if (!is_array($items) || count($items) === 0) {
            json_out(['success' => false, 'message' => 'কমপক্ষে একটি স্বর্ণের আইটেম যোগ করুন।'], 422);
        }
        if ($paidAmount < 0) {
            json_out(['success' => false, 'message' => 'পরিশোধিত টাকা ঋণাত্মক হতে পারবে না।'], 422);
        }

        // Verify customer exists
        $cstmt = mysqli_prepare($conn, "SELECT id FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($cstmt, 'i', $customerId);
        mysqli_stmt_execute($cstmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($cstmt))) {
            json_out(['success' => false, 'message' => 'নির্বাচিত কাস্টমার খুঁজে পাওয়া যায়নি।'], 404);
        }

        $calcItems   = [];
        $totalAmount = 0.0;

        foreach ($items as $i => $item) {
            $n = $i + 1;

            foreach (['vori', 'ana', 'roti', 'point'] as $field) {
                $raw = $item[$field] ?? 0;
                if (!is_numeric($raw) || (float)$raw != (int)$raw) {
                    $fieldBn = $field === 'vori' ? 'ভ' : ($field === 'ana' ? 'আ' : ($field === 'roti' ? 'র' : 'প'));
                    json_out(['success' => false, 'message' => "আইটেম $n: $fieldBn অবশ্যই পূর্ণসংখ্যা হতে হবে।"], 422);
                }
            }

            $vori   = (int)($item['vori']   ?? 0);
            $ana    = (int)($item['ana']    ?? 0);
            $roti   = (int)($item['roti']   ?? 0);
            $point  = (int)($item['point']  ?? 0);
            $purity = round((float)($item['purity'] ?? 0), 2);

            if ($vori < 0)                json_out(['success' => false, 'message' => "আইটেম $n: ভরি ঋণাত্মক হতে পারবে না।"], 422);
            if ($ana < 0 || $ana > 15)    json_out(['success' => false, 'message' => "আইটেম $n: আনা ০–১৫ হতে হবে।"], 422);
            if ($roti < 0 || $roti > 5)   json_out(['success' => false, 'message' => "আইটেম $n: রতি ০–৫ হতে হবে।"], 422);
            if ($point < 0 || $point > 9) json_out(['success' => false, 'message' => "আইটেম $n: পয়েন্ট ০–৯ হতে হবে।"], 422);
            if (!in_array($purity, SALE_KARATS, true)) {
                json_out(['success' => false, 'message' => "আইটেম $n: ক্যারেট অবশ্যই ১৮K, ২০K, ২১K, ২২K অথবা ২৪K হতে হবে।"], 422);
            }

            $grams = traditional_to_grams($vori, $ana, $roti, $point);
            if ($grams <= 0) {
                json_out(['success' => false, 'message' => "আইটেম $n: ওজন শূন্যের বেশি হতে হবে।"], 422);
            }

            $price = ($grams / G_PER_VORI) * ($purity / 24) * $pureGoldPrice;
            $totalAmount += $price;

            $calcItems[] = [
                'weight' => $grams,
                'purity' => $purity,
                'price'  => $price,
            ];
        }

        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO gold_sales
                    (customer_id, pure_gold_price, total_amount, paid_amount, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'idddsi',
                $customerId, $pureGoldPrice, $totalAmount, $paidAmount, $note, $userId);
            mysqli_stmt_execute($stmt);
            $saleId = (int) mysqli_insert_id($conn);

            $itemStmt = mysqli_prepare($conn,
                "INSERT INTO gold_sale_items (gold_sale_id, weight, purity, price)
                 VALUES (?, ?, ?, ?)");
            foreach ($calcItems as $ci) {
                mysqli_stmt_bind_param($itemStmt, 'iddd',
                    $saleId, $ci['weight'], $ci['purity'], $ci['price']);
                mysqli_stmt_execute($itemStmt);
            }

            if ($paidAmount > 0) {
                $pmtStmt = mysqli_prepare($conn,
                    "INSERT INTO gold_sale_payments
                        (gold_sale_id, paid_amount, transaction_ref, payment_date, note, received_by)
                     VALUES (?, ?, NULL, ?, NULL, ?)");
                mysqli_stmt_bind_param($pmtStmt, 'idsi',
                    $saleId, $paidAmount, $paymentDate, $userId);
                mysqli_stmt_execute($pmtStmt);
            }

            $neededByPurity = [];
            foreach ($calcItems as $ci) {
                $p = round((float)$ci['purity'], 2);
                $neededByPurity[$p] = ($neededByPurity[$p] ?? 0.0) + (float)$ci['weight'];
            }
            ksort($neededByPurity);

            foreach ($neededByPurity as $purity => $neededGrams) {
                inventory_deduct($conn, $purity, $neededGrams, karat_label($purity) . ' বিক্রয়');
            }

            mysqli_commit($conn);
        } catch (InventoryException $e) {
            mysqli_rollback($conn);
            json_out(['success' => false, 'message' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            json_out(['success' => false, 'message' => 'বিক্রি সংরক্ষণ করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।'], 500);
        }

        json_out([
            'success' => true,
            'message' => 'স্বর্ণ বিক্রি সফলভাবে সংরক্ষিত হয়েছে।',
            'id'      => $saleId,
            'summary' => [
                'total_amount' => $totalAmount,
                'paid_amount'  => $paidAmount,
                'due_amount'   => $totalAmount - $paidAmount,
            ],
        ]);
    }

    json_out(['success' => false, 'message' => 'অজানা অ্যাকশন।'], 400);
}
?>
<!DOCTYPE html>
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>নতুন সোনা বিক্রি — ফাইনবুলিয়ন ডায়াল</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
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
    background: var(--bg-app);
    font-family: 'Inter', 'Noto Sans Bengali', system-ui, -apple-system, sans-serif;
    color: var(--text-primary);
}

/* ---- Page header (§3) ---- */
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
}
.page-header .header-left {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    min-width: 0;
}
.page-header .header-right {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.page-header h1, .page-header h4 {
    color: var(--text-on-navy);
    margin: 0;
    font-weight: 700;
    font-size: 22px;
}
.page-header small, .page-header .subtitle, .page-header .header-meta {
    color: rgba(255,255,255,.78);
    font-size: 12.5px;
    font-weight: 500;
}

/* Header action button (§3) */
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
}
.header-action-btn:hover, .header-action-btn:focus {
    background: var(--teal);
    border-color: #fff;
    color: #fff;
}
.header-action-btn svg, .header-action-btn i { color: #fff; }

.page-inset { padding: 0 1.5rem; }

/* ---- Buttons (§4) ---- */
.btn-primary {
    background: var(--navy);
    border: 1.5px solid var(--navy);
    color: #fff;
    border-radius: 8px;
    font-weight: 600;
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
}
.btn-secondary:hover {
    background: var(--bg-hover);
    border-color: var(--teal);
    color: var(--navy);
}

.btn-danger {
    background: #fff;
    border: 1.5px solid var(--danger);
    color: var(--danger);
    border-radius: 8px;
    font-weight: 600;
}
.btn-danger:hover {
    background: var(--danger);
    color: #fff;
}

.btn-icon-round {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--sky);
    color: var(--navy);
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}
.btn-icon-round:hover {
    background: var(--teal);
    color: #fff;
}

/* ---- Cards & Containers (§5) ---- */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 14px;
    box-shadow: var(--shadow);
    padding: 0;
}
.card-header {
    background: transparent !important;
    border-bottom: 1px solid var(--border-default);
    border-radius: 14px 14px 0 0 !important;
    padding: 0.85rem 1.1rem;
    color: var(--text-primary);
    font-size: 16px;
    font-weight: 700;
}
.card-body { padding: 1.1rem; }

.card-icon-chip {
    width: 34px;
    height: 34px;
    background: var(--sky);
    color: var(--navy);
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: 0.5rem;
}

/* ---- Input Fields (§6) ---- */
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

.form-control.is-invalid, .form-control:invalid[data-touched] {
    border-color: var(--danger);
    box-shadow: 0 0 0 3px rgba(166,67,75,.12);
}

label, .form-label {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: .03em;
    margin-bottom: .3rem;
    display: block;
}

.input-group-text {
    background: var(--navy);
    color: #fff;
    border: 1.5px solid var(--navy);
    border-radius: 8px 0 0 8px;
    font-weight: 600;
}
.input-group .form-control {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
}

/* ---- Customer autocomplete ---- */
.customer-results-box {
    position: absolute;
    z-index: 20;
    background: #ffffff;
    border: 1.5px solid var(--border-default);
    border-radius: 8px;
    box-shadow: var(--shadow);
    width: 100%;
    max-height: 260px;
    overflow-y: auto;
    display: none;
}
.customer-result-item {
    cursor: pointer;
    padding: 0.55rem 0.9rem;
    border-bottom: 1px solid var(--border-default);
}
.customer-result-item:hover { background: var(--bg-hover); }
.customer-result-item:last-child { border-bottom: none; }
.customer-result-photo {
    width: 32px; height: 32px; border-radius: 50%; object-fit: cover;
    border: 1.5px solid var(--border-default); flex-shrink: 0;
}
.customer-result-photo-fallback {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    background: var(--navy); color: #fff; font-size: 0.8rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}
.selected-customer-card {
    border: 1.5px solid var(--teal);
    background: var(--sky);
    border-radius: 8px;
    padding: 0.75rem 1rem;
    display: none;
    align-items: center;
    justify-content: space-between;
}

/* ---- Pure gold price row ---- */
.price-row {
    background: var(--beige);
    border: 1px solid var(--border-default);
    border-radius: 8px;
    padding: 0.75rem 1rem;
}

/* ---- Gold item card ---- */
.gold-item-card {
    border: 1px solid var(--border-default);
    border-radius: 14px;
    padding: 1rem 1.1rem;
    margin-bottom: 1rem;
    background: #ffffff;
    position: relative;
    box-shadow: var(--shadow);
}
.gold-item-card .item-badge {
    position: absolute;
    top: -10px;
    left: 14px;
    background: var(--navy);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    padding: 0.1rem 0.65rem;
    border-radius: 999px;
}
.gold-item-card .btn-remove-item {
    position: absolute;
    top: 10px;
    right: 10px;
}

/* Item Compact Horizontal Row (Karat + Weight) */
.item-inputs-row {
    display: grid;
    grid-template-columns: 1.1fr repeat(4, 1fr);
    gap: 0.4rem;
    align-items: start;
}
.item-inputs-row .field-col {
    position: relative;
}
.item-inputs-row label {
    font-size: 11.5px;
    margin-bottom: 0.2rem;
    text-align: center;
    white-space: nowrap;
}
.item-inputs-row .form-control,
.item-inputs-row .form-select {
    text-align: center;
    padding: 0.35rem 0.2rem;
    font-size: 13.5px;
}
.item-inputs-row .invalid-feedback {
    position: absolute;
    bottom: -18px;
    left: 0;
    font-size: 10px;
    white-space: nowrap;
}

.item-inputs-row input.form-control.is-valid,
.item-inputs-row input.form-control.is-invalid,
.item-inputs-row select.form-select.is-valid,
.item-inputs-row select.form-select.is-invalid {
    background-image: none !important;
    padding-right: 0.2rem !important;
}

/* Item price result chip */
.item-price-result {
    background: var(--bg-hover);
    border: 1px solid var(--border-default);
    border-radius: 8px;
    padding: 0.45rem 0.8rem;
    font-size: 13.5px;
    color: var(--text-primary);
    font-weight: 600;
    margin-top: 0.65rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.item-price-result .weight-sub {
    font-size: 12.5px;
    color: var(--text-secondary);
    font-weight: 400;
}

/* Inventory Live Stock Row inside item card */
.item-stock-row {
    border-radius: 8px;
    padding: 0.5rem 0.8rem;
    background: #EAF3EE;
    border: 1px solid rgba(61, 122, 92, 0.25);
    margin-top: 0.65rem;
    font-size: 13px;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.item-stock-row .isr-top { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
.item-stock-row .s-label { font-size: 12px; color: var(--text-secondary); font-weight: 700; text-transform: uppercase; }
.item-stock-row .s-value { font-size: 13.5px; font-weight: 700; color: var(--success); }
.item-stock-row .isr-warning {
    display: flex; align-items: center;
    margin-top: 0.35rem; padding-top: 0.35rem;
    border-top: 1px dashed rgba(166, 67, 75, 0.3);
    font-size: 12px; font-weight: 600; color: var(--danger);
}
.item-stock-row.insufficient {
    background: #FBECEC;
    border-color: rgba(166, 67, 75, 0.25);
}
.item-stock-row.insufficient .s-value { color: var(--danger); }

/* Overall Stock Status inside Summary */
.stock-status-row {
    border-radius: 8px;
    padding: 0.5rem 0.8rem;
    background: #EAF3EE;
    border: 1px solid rgba(61, 122, 92, 0.25);
    margin-bottom: 0.5rem;
}
.stock-status-row .ss-top { display: flex; justify-content: space-between; align-items: center; }
.stock-status-row .s-label { font-size: 12px; color: var(--text-secondary); font-weight: 700; text-transform: uppercase; }
.stock-status-row .s-value { font-size: 13px; font-weight: 700; color: var(--success); }
.stock-status-row .ss-warning {
    display: flex; align-items: center;
    margin-top: 0.35rem; padding-top: 0.35rem;
    border-top: 1px dashed rgba(166, 67, 75, 0.3);
    font-size: 12px; font-weight: 600; color: var(--danger);
}
.stock-status-row.insufficient {
    background: #FBECEC;
    border-color: rgba(166, 67, 75, 0.25);
}
.stock-status-row.insufficient .s-value { color: var(--danger); }

/* ---- Summary panel (§5) ---- */
.summary-card {
    background: #ffffff;
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
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.summary-card .sum-body { padding: 0.9rem 1.2rem; }
.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 0.4rem 0;
    border-bottom: 1px solid var(--border-default);
}
.summary-row:last-of-type { border-bottom: none; }
.summary-row .s-label { font-size: 12.5px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; }
.summary-row .s-value { font-weight: 700; font-size: 14px; color: var(--text-primary); }
.summary-row.s-total .s-value { color: var(--navy); font-size: 16px; }

/* Paid amount editable row */
.paid-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.45rem 0;
    border-bottom: 1px solid var(--border-default);
}
.paid-row .s-label { font-size: 12.5px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; }
.paid-row input {
    width: 130px;
    text-align: right;
    font-weight: 700;
}

/* Due amount row */
.due-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 0.5rem 0 0.1rem;
    border-top: 1.5px solid var(--border-strong);
    margin-top: 0.2rem;
}
.due-row .s-label { font-size: 13.5px; font-weight: 700; color: var(--text-primary); text-transform: uppercase; }
.due-row .s-value { font-size: 18px; font-weight: 700; color: var(--danger); }
.due-row .s-value.zero { color: var(--success); }

/* ── Mobile (§10) ── */
@media (max-width: 576px) {
    .page-inset { padding: 0 1rem 1rem; }

    .page-header {
        padding: .85rem 1.1rem;
        border-radius: 0 0 14px 14px;
    }
    .page-header h1, .page-header h4 { font-size: 18px; }
    .page-header small { font-size: 12px; }

    .header-action-btn {
        padding: .4rem .75rem;
        font-size: 12.5px;
    }

    .card { border-radius: 14px; }
    .card-header { padding: 0.65rem .85rem; font-size: 15px; }
    .card-body { padding: .85rem; }

    .form-control, .form-select, textarea {
        padding: .6rem .8rem;
        font-size: 16px;
    }

    .gold-item-card { padding: 0.85rem; margin-bottom: 0.75rem; }
    .item-inputs-row { gap: 0.25rem; }
    .item-inputs-row .form-control, .item-inputs-row .form-select { padding: 0.4rem 0.1rem; font-size: 14px; }

    .summary-card .sum-body { padding: 0.75rem; }
    .paid-row input { width: 110px; }

    #btnSave { padding: .75rem 1rem; width: 100%; font-size: 14px; margin-top: 0.6rem !important; }
    #noteCard { display: none !important; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0">
    <div class="page-header">
        <div class="header-left">
            <h4 class="mb-0">
                <i class="bi bi-bag-check-fill me-2 d-none d-md-inline"></i>
                <span>নতুন সোনা বিক্রি</span>
            </h4>
            <small>কাস্টমারের কাছে সোনা বিক্রি ও ইনভেন্টরি ম্যানেজ করুন</small>
        </div>
        <div class="header-right">
            <a href="gold_sale_list.php" class="header-action-btn">
                <i class="bi bi-list-ul"></i> বিক্রির তালিকা
            </a>
        </div>
    </div>
</div>

<div class="page-inset py-4">

    <form id="saleForm" autocomplete="off">
        <div class="row g-4">

            <!-- ── Left column ── -->
            <div class="col-lg-8">

                <!-- Customer -->
                <div class="card mb-4">
                    <div class="card-header">
                        <span class="card-icon-chip"><i class="bi bi-person-fill"></i></span> কাস্টমার খুঁজুন
                    </div>
                    <div class="card-body">
                        <div class="position-relative">
                            <input type="text" class="form-control" id="customerSearch"
                                   placeholder="নাম বা ফোন নম্বর দিয়ে খুঁজুন">
                            <div class="customer-results-box" id="customerResults"></div>
                        </div>
                        <input type="hidden" id="customerId" name="customer_id">

                        <div class="selected-customer-card mt-3" id="selectedCustomerCard">
                            <div>
                                <div class="fw-semibold" id="selectedCustomerName">—</div>
                                <small class="text-muted" id="selectedCustomerPhone">—</small>
                            </div>
                            <button type="button" class="btn btn-icon-round" id="btnClearCustomer" title="মুছে ফেলুন">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Pure Gold Price -->
                <div class="card mb-4">
                    <div class="card-header">
                        <span class="card-icon-chip"><i class="bi bi-coin"></i></span> পাকা সোনার দাম (২৪ ক্যারেট)
                    </div>
                    <div class="card-body">
                        <div class="price-row">
                            <label>ভরি প্রতি দাম (টাকা)</label>
                            <div class="input-group">
                                <span class="input-group-text">৳</span>
                                <input type="number" class="form-control form-control-lg fw-bold"
                                       id="pureGoldPrice" name="pure_gold_price"
                                       min="1" step="1" placeholder="যেমন: ২৩৬০০০"
                                       style="font-size: 1.25rem;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sale Items -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><span class="card-icon-chip"><i class="bi bi-gem"></i></span> স্বর্ণের আইটেম</span>
                        <button type="button" class="btn btn-primary btn-sm" id="btnAddItem">
                            <i class="bi bi-plus-lg me-1"></i> যোগ করুন
                        </button>
                    </div>
                    <div class="card-body" id="itemsContainer">
                        <!-- items injected by JS -->
                    </div>
                </div>

                <!-- Note (hidden on mobile) -->
                <div class="card mb-4" id="noteCard">
                    <div class="card-header">
                        <span class="card-icon-chip"><i class="bi bi-pencil-square"></i></span> নোট / মন্তব্য
                    </div>
                    <div class="card-body">
                        <textarea class="form-control" id="note" name="note" rows="2"
                                  placeholder="নোট লিখুন (ঐচ্ছিক)…"></textarea>
                    </div>
                </div>
            </div>

            <!-- ── Right column: Summary ── -->
            <div class="col-lg-4">
                <div class="summary-card sticky-top" style="top: 1rem;">
                    <div class="sum-header">সারসংক্ষেপ</div>
                    <div class="sum-body">

                        <div class="summary-row">
                            <span class="s-label">মোট ওজন</span>
                            <span class="s-value" id="sumTotalWeight">০ভ ০আ ০র ০প</span>
                        </div>

                        <div class="summary-row s-total">
                            <span class="s-label">মোট দাম:</span>
                            <span class="s-value" id="sumTotalPrice">৳০</span>
                        </div>

                        <div class="paid-row">
                            <span class="s-label">পরিশোধিত টাকা:</span>
                            <input type="number" class="form-control" id="paidAmount" name="paid_amount"
                                   min="0" step="1" value="0" placeholder="0">
                        </div>

                        <div class="due-row">
                            <span class="s-label">বকেয়া টাকা:</span>
                            <span class="s-value" id="sumDueAmount">৳০</span>
                        </div>

                        <!-- Aggregate stock status -->
                        <div class="mt-3" id="stockStatusContainer">
                            <!-- karat rows injected by renderStockStatus() -->
                        </div>
                    </div>

                    <div class="px-3 pb-3 mt-1">
                        <button type="submit" class="btn btn-primary w-100" id="btnSave">
                            <i class="bi bi-check2-circle me-1"></i> বিক্রি সংরক্ষণ করুন
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </form>

</div>
</div>

<!-- ── Item template ── -->
<template id="itemTemplate">
    <div class="gold-item-card" data-item>
        <span class="item-badge">আইটেম <span data-item-num></span></span>
        <button type="button" class="btn btn-icon-round btn-remove-item" data-remove title="ডিলিট">
            <i class="bi bi-trash3"></i>
        </button>

        <div class="item-inputs-row mt-3">
            <div class="field-col">
                <label>ক্যারেট</label>
                <select class="form-select form-select-sm" data-field="purity">
                    <option value="18.00">18K</option>
                    <option value="20.00">20K</option>
                    <option value="21.00">21K</option>
                    <option value="22.00">22K</option>
                    <option value="24.00" selected>24K</option>
                </select>
                <div class="invalid-feedback" data-error="purity"></div>
            </div>
            <div class="field-col">
                <label>ভরি</label>
                <input type="number" min="0" step="1"
                       class="form-control form-control-sm" data-field="vori" value="" placeholder="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="vori"></div>
            </div>
            <div class="field-col">
                <label>আনা</label>
                <input type="number" min="0" max="15" step="1"
                       class="form-control form-control-sm" data-field="ana" value="" placeholder="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="ana"></div>
            </div>
            <div class="field-col">
                <label>রতি</label>
                <input type="number" min="0" max="5" step="1"
                       class="form-control form-control-sm" data-field="roti" value="" placeholder="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="roti"></div>
            </div>
            <div class="field-col">
                <label>পয়েন্ট</label>
                <input type="number" min="0" max="9" step="1"
                       class="form-control form-control-sm" data-field="point" value="" placeholder="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="point"></div>
            </div>
        </div>

        <div class="item-price-result" data-price-result>
            <span>আইটেমের দাম : <strong data-price-value>৳০</strong></span>
            <span class="weight-sub" data-weight-sub>0g</span>
        </div>

        <!-- Live per-karat stock row -->
        <div class="item-stock-row" data-item-stock>
            <div class="isr-top">
                <span class="s-label"><i class="bi bi-box-seam me-1"></i> এই ক্যারেটের মজুদ (এই আইটেমের পর)</span>
                <span class="s-value" data-item-stock-value>লোড হচ্ছে…</span>
            </div>
            <div class="isr-warning" data-item-stock-warning style="display:none;">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <span data-item-stock-warning-text>পর্যাপ্ত মজুদ নেই</span>
            </div>
        </div>
    </div>
</template>

<script>
// ── Conversion constants ──
const G_PER_VORI  = 11.664;
const G_PER_ANA   = 0.729;
const G_PER_ROTI  = 0.1215;
const G_PER_POINT = 0.01215;
const ALLOWED_KARATS = [18, 20, 21, 22, 24];

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
    if (roti >= 6)   { roti -= 6;   ana  += 1; }
    if (ana  >= 16)  { ana  -= 16;  vori += 1; }

    return { vori, ana, roti, point };
}

function formatTrad(t) {
    return `${t.vori} ভরি ${t.ana} আনা ${t.roti} রতি ${t.point} পয়েন্ট`;
}

function formatBDT(n) {
    return '৳' + Math.round(n).toLocaleString('en-BD');
}

// ── Customer search ──
const customerSearch  = document.getElementById('customerSearch');
const customerResults = document.getElementById('customerResults');
const customerIdInput = document.getElementById('customerId');
const selectedCard    = document.getElementById('selectedCustomerCard');
const selectedName    = document.getElementById('selectedCustomerName');
const selectedPhone   = document.getElementById('selectedCustomerPhone');

function escAttr(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
function escHtml(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

let searchTimer = null;

customerSearch.addEventListener('input', function () {
    const q = this.value.trim();
    clearTimeout(searchTimer);
    if (q.length < 2) { customerResults.style.display = 'none'; customerResults.innerHTML = ''; return; }

    searchTimer = setTimeout(async () => {
        try {
            const res  = await fetch('customer_search.php?q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await res.json();
            if (!data.success) {
                customerResults.innerHTML = '<div class="p-2 text-danger small">অনুসন্ধান ব্যর্থ হয়েছে।</div>';
                customerResults.style.display = 'block'; return;
            }
            const list = data.data || [];
            if (list.length === 0) {
                customerResults.innerHTML = '<div class="p-2 text-muted small">কোনো কাস্টমার পাওয়া যায়নি।</div>';
                customerResults.style.display = 'block'; return;
            }
            customerResults.innerHTML = list.map(c => `
                <div class="customer-result-item d-flex align-items-center gap-2"
                     data-id="${c.id}" data-name="${escAttr(c.name)}" data-phone="${escAttr(c.phone)}">
                    ${c.photo_path
                        ? `<img src="${escAttr(c.photo_path)}" class="customer-result-photo" alt="">`
                        : `<div class="customer-result-photo-fallback">${escHtml((c.name||'?').charAt(0).toUpperCase())}</div>`}
                    <div class="flex-grow-1">
                        <div class="fw-semibold">${escHtml(c.name)}</div>
                        <small class="text-muted">${escHtml(c.phone)}${c.address ? ' · ' + escHtml(c.address) : ''}</small>
                    </div>
                </div>`).join('');
            customerResults.style.display = 'block';
        } catch {
            customerResults.innerHTML = '<div class="p-2 text-danger small">অনুসন্ধান ব্যর্থ হয়েছে।</div>';
            customerResults.style.display = 'block';
        }
    }, 300);
});

customerResults.addEventListener('click', function (e) {
    const item = e.target.closest('.customer-result-item');
    if (!item) return;
    customerIdInput.value     = item.dataset.id;
    selectedName.textContent  = item.dataset.name;
    selectedPhone.textContent = item.dataset.phone;
    selectedCard.style.display = 'flex';
    customerSearch.value = '';
    customerResults.style.display = 'none';
    customerResults.innerHTML = '';
});

document.getElementById('btnClearCustomer').addEventListener('click', () => {
    customerIdInput.value = '';
    selectedCard.style.display = 'none';
});

document.addEventListener('click', e => {
    if (!e.target.closest('#customerSearch') && !e.target.closest('#customerResults')) {
        customerResults.style.display = 'none';
    }
});

// ── Pure gold price ──
const pureGoldPriceInput = document.getElementById('pureGoldPrice');
pureGoldPriceInput.addEventListener('input', () => { renderAllItems(); renderSummary(); });

function getPureGoldPrice() {
    const n = parseFloat(pureGoldPriceInput.value);
    return (isNaN(n) || n <= 0) ? 0 : n;
}

// ── Gold Items ──
const itemsContainer = document.getElementById('itemsContainer');
const itemTemplate   = document.getElementById('itemTemplate');
let itemCounter = 0;

const FIELD_RULES = {
    vori:  { min: 0, max: null, label: 'ভরি'  },
    ana:   { min: 0, max: 15,   label: 'আনা'   },
    roti:  { min: 0, max: 5,    label: 'রতি'  },
    point: { min: 0, max: 9,    label: 'পয়েন্ট' },
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
        input.classList.add('is-invalid'); input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} অবশ্যই পূর্ণসংখ্যা হতে হবে।`;
        return { valid: false, value: 0 };
    }
    const n = Number(raw);
    if (!Number.isFinite(n) || !Number.isInteger(n)) {
        input.classList.add('is-invalid'); input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} অবশ্যই পূর্ণসংখ্যা হতে হবে।`;
        return { valid: false, value: 0 };
    }
    if (n < rules.min) {
        input.classList.add('is-invalid'); input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} ঋণাত্মক হতে পারবে না।`;
        return { valid: false, value: 0 };
    }
    if (rules.max !== null && n > rules.max) {
        input.classList.add('is-invalid'); input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} সর্বোচ্চ ${rules.max} হতে পারবে।`;
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

    const purityInput = card.querySelector('[data-field="purity"]');
    const purity = parseFloat(purityInput.value);
    if (isNaN(purity) || !ALLOWED_KARATS.includes(purity)) {
        allValid = false;
        results.purity = 24;
    } else {
        results.purity = purity;
    }

    results.allValid = allValid;
    return results;
}

function calcItemPrice(v, pureGoldPrice) {
    const grams = traditionalToGrams(v.vori, v.ana, v.roti, v.point);
    const price = (grams / G_PER_VORI) * (v.purity / 24) * pureGoldPrice;
    return { grams, price };
}

function renderItem(card) {
    const v = getItemValues(card);
    const price24k = getPureGoldPrice();
    const { grams, price } = calcItemPrice(v, price24k);

    card.querySelector('[data-price-value]').textContent = formatBDT(price);
    card.querySelector('[data-weight-sub]').textContent  = grams.toFixed(4) + 'g';
}

function renderAllItems() {
    itemsContainer.querySelectorAll('[data-item]').forEach(card => renderItem(card));
}

function addItem() {
    itemCounter++;
    const node = itemTemplate.content.cloneNode(true);
    const card = node.querySelector('[data-item]');
    card.dataset.itemId = itemCounter;
    node.querySelector('[data-item-num]').textContent = itemCounter;

    card.querySelectorAll('input, select').forEach(el => {
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

function renumberItems() {
    itemsContainer.querySelectorAll('[data-item]').forEach((card, idx) => {
        card.querySelector('[data-item-num]').textContent = idx + 1;
    });
}

document.getElementById('btnAddItem').addEventListener('click', addItem);

// ── Live inventory stock ──
let stockByKarat = null;

async function loadStockAll() {
    try {
        const res  = await fetch('gold_sale_inventory.php?action=stock_all', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        if (data.success) {
            stockByKarat = {};
            data.stock.forEach(s => { stockByKarat[s.purity.toFixed(2)] = s.left_weight; });
        }
    } catch {
        stockByKarat = null;
    }
    renderSummary();
}

function karatKey(k) { return Number(k).toFixed(2); }

function karatLabel(k) {
    const n = Number(k);
    return (Number.isInteger(n) ? n : n.toFixed(2)) + 'K';
}

// ── Summary ──
const paidAmountInput = document.getElementById('paidAmount');
paidAmountInput.addEventListener('input', renderSummary);

function renderSummary() {
    let totalGrams = 0;
    let totalPrice = 0;
    const price24k = getPureGoldPrice();
    const runningByKarat = {};

    itemsContainer.querySelectorAll('[data-item]').forEach(card => {
        const v = getItemValues(card);
        const { grams, price } = calcItemPrice(v, price24k);
        totalGrams += grams;
        totalPrice += price;

        const key = karatKey(v.purity);
        runningByKarat[key] = (runningByKarat[key] || 0) + grams;

        // Per-item live stock row
        const stockRow      = card.querySelector('[data-item-stock]');
        const stockValueEl  = card.querySelector('[data-item-stock-value]');
        const warningEl     = card.querySelector('[data-item-stock-warning]');
        const warningTextEl = card.querySelector('[data-item-stock-warning-text]');

        if (stockByKarat === null) {
            stockValueEl.textContent = 'লোড হচ্ছে…';
            stockRow.classList.remove('insufficient');
            warningEl.style.display = 'none';
        } else {
            const startingStock = stockByKarat[key] ?? 0;
            const remaining     = startingStock - runningByKarat[key];

            if (remaining < -0.0005) {
                const shortfall = runningByKarat[key] - startingStock;
                stockValueEl.textContent = formatTrad(gramsToTraditional(0));
                stockRow.classList.add('insufficient');
                warningEl.style.display = 'flex';
                warningTextEl.textContent =
                    `পর্যাপ্ত ${karatLabel(v.purity)} মজুদ নেই — অতিরিক্ত প্রয়োজন: ${formatTrad(gramsToTraditional(shortfall))}`;
            } else {
                stockValueEl.textContent = formatTrad(gramsToTraditional(remaining));
                stockRow.classList.remove('insufficient');
                warningEl.style.display = 'none';
            }
        }
    });

    const trad = gramsToTraditional(totalGrams);
    document.getElementById('sumTotalWeight').textContent = formatTrad(trad);
    document.getElementById('sumTotalPrice').textContent  = formatBDT(totalPrice);

    const paid = Math.max(0, parseFloat(paidAmountInput.value) || 0);
    const due  = totalPrice - paid;

    const dueEl = document.getElementById('sumDueAmount');
    dueEl.textContent = formatBDT(Math.abs(due));
    dueEl.className   = 's-value' + (due <= 0 ? ' zero' : '');

    renderStockStatus(runningByKarat);
}

function renderStockStatus(runningByKarat) {
    const container = document.getElementById('stockStatusContainer');
    const karatsUsed = Object.keys(runningByKarat).filter(k => runningByKarat[k] > 0);

    if (karatsUsed.length === 0) {
        container.innerHTML = '';
        return;
    }

    karatsUsed.sort((a, b) => parseFloat(a) - parseFloat(b));

    let html = '';

    karatsUsed.forEach(key => {
        const needed = runningByKarat[key];
        const stock  = stockByKarat === null ? null : (stockByKarat[key] ?? 0);
        const label  = karatLabel(parseFloat(key));

        if (stock === null) {
            html += `
                <div class="stock-status-row mb-2">
                    <div class="ss-top">
                        <span class="s-label"><i class="bi bi-box-seam me-1"></i> ${label} মজুদ</span>
                        <span class="s-value">লোড হচ্ছে…</span>
                    </div>
                </div>`;
            return;
        }

        const shortfall = needed - stock;
        if (shortfall > 0.0005) {
            html += `
                <div class="stock-status-row mb-2 insufficient">
                    <div class="ss-top">
                        <span class="s-label"><i class="bi bi-box-seam me-1"></i> ${label} মজুদ</span>
                        <span class="s-value">${formatTrad(gramsToTraditional(stock))}</span>
                    </div>
                    <div class="ss-warning">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <span>ঘাটতি: ${formatTrad(gramsToTraditional(shortfall))}</span>
                    </div>
                </div>`;
        } else {
            const remaining = stock - needed;
            html += `
                <div class="stock-status-row mb-2">
                    <div class="ss-top">
                        <span class="s-label"><i class="bi bi-box-seam me-1"></i> ${label} অবশিষ্ট মজুদ</span>
                        <span class="s-value">${formatTrad(gramsToTraditional(remaining))}</span>
                    </div>
                </div>`;
        }
    });

    container.innerHTML = html;
}

// Initialize
loadStockAll();
addItem();

// ── Save ──
document.getElementById('saleForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    if (!customerIdInput.value) { alert('অনুগ্রহ করে একজন কাস্টমার নির্বাচন করুন।'); return; }

    const price24k = getPureGoldPrice();
    if (price24k <= 0) { alert('অনুগ্রহ করে পাকা সোনার দাম (২৪ ক্যারেট) লিখুন।'); return; }

    const items = [];
    let hasError = false;

    itemsContainer.querySelectorAll('[data-item]').forEach(card => {
        const v = getItemValues(card);
        if (!v.allValid) hasError = true;
        items.push(v);
    });

    if (items.length === 0) { alert('কমপক্ষে একটি স্বর্ণের আইটেম যোগ করুন।'); return; }
    if (hasError)           { alert('সংরক্ষণ করার আগে লাল চিহ্নিত ভুলগুলো সংশোধন করুন।'); return; }

    for (const it of items) {
        if (traditionalToGrams(it.vori, it.ana, it.roti, it.point) <= 0) {
            alert('প্রত্যেকটি আইটেমের ওজন শূন্যের বেশি হতে হবে।');
            return;
        }
    }

    const paid = Math.max(0, parseFloat(paidAmountInput.value) || 0);

    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> সংরক্ষিত হচ্ছে…';

    try {
        const fd = new FormData();
        fd.append('action',          'save');
        fd.append('customer_id',     customerIdInput.value);
        fd.append('pure_gold_price', price24k);
        fd.append('paid_amount',     paid);
        fd.append('note',            document.getElementById('note').value);
        fd.append('items',           JSON.stringify(items));

        const res  = await fetch('gold_sale_inventory.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        });
        const data = await res.json();

        if (data.success) {
            window.location.href = 'gold_sale_list.php';
        } else {
            alert(data.message || 'বিক্রি সংরক্ষণ করতে ব্যর্থ হয়েছে।');
        }
    } catch {
        alert('নেটওয়ার্ক সমস্যা। অনুগ্রহ করে আবার চেষ্টা করুন।');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> বিক্রি সংরক্ষণ করুন';
    }
});
</script>

</body>
</html>