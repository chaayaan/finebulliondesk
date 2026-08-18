<?php
/**
 * gold_sale.php
 * FineBullion Desk — Gold Sale (create new sale)
 *
 * Business rules:
 *   - Pure Gold Price (24k) is entered in BDT per Vori.
 *   - Sale items hold PURE 24k gold weight (no purity field — it is
 *     already 24k by definition).  Weight is entered in traditional
 *     units (Vori / Ana / Roti / Point) and stored in grams.
 *       Vori  >= 0 (no upper limit)
 *       Ana   0–15
 *       Roti  0–5
 *       Point 0–9
 *     (10 Point = 1 Roti, 6 Roti = 1 Ana, 16 Ana = 1 Vori)
 *   - Item price  = (item_grams / G_PER_VORI) * pure_gold_price_per_vori
 *   - Total price = sum of all item prices
 *   - Due Amount  = Total Price – SUM(gold_sale_payments.paid_amount)
 *   - Weight stored in grams (decimal); price stored in BDT.
 *
 * Payment tracking:
 *   - paid_amount on gold_sales is kept as a convenience cache
 *     (= SUM of gold_sale_payments rows for that sale).
 *   - On create, if an initial payment is given it is written both
 *     to gold_sale_payments AND cached in gold_sales.paid_amount.
 */

require_once __DIR__ . '/auth.php';

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
    if ($roti >= 6)   { $roti -= 6;   $ana  += 1; }
    if ($ana >= 16)   { $ana  -= 16;  $vori += 1; }

    return ['vori' => $vori, 'ana' => $ana, 'roti' => $roti, 'point' => $point];
}

// -----------------------------------------------------------------------
// AJAX actions
// -----------------------------------------------------------------------
if ($isAjax || $action !== null) {

    // ---- Customer quick lookup ----
    if ($action === 'customer' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'ভুল কাস্টমার ID।'], 400);

        $stmt = mysqli_prepare($conn, "SELECT id, name, phone FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$row) json_out(['success' => false, 'message' => 'কাস্টমার পাওয়া যায়নি।'], 404);
        json_out(['success' => true, 'data' => $row]);
    }

    // ---- SAVE sale ---------------------------------------------------
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $customerId      = (int)($_POST['customer_id']       ?? 0);
        $pureGoldPrice   = (float)($_POST['pure_gold_price'] ?? 0);
        $paidAmount      = (float)($_POST['paid_amount']      ?? 0);
        $paymentDate     = trim($_POST['payment_date']        ?? '');
        $transactionRef  = trim($_POST['transaction_ref']     ?? '') ?: null;
        $paymentNote     = trim($_POST['payment_note']        ?? '') ?: null;
        $note            = trim($_POST['note'] ?? '') ?: null;
        $items           = json_decode($_POST['items'] ?? '[]', true);
        $userId          = $currentUser['id'];

        if ($customerId <= 0) {
            json_out(['success' => false, 'message' => 'একজন কাস্টমার সিলেক্ট করুন।'], 422);
        }
        if ($pureGoldPrice <= 0) {
            json_out(['success' => false, 'message' => 'পাকা সোনার দাম অবশ্যই শূন্যের বেশি হতে হবে।'], 422);
        }
        if (!is_array($items) || count($items) === 0) {
            json_out(['success' => false, 'message' => 'কমপক্ষে একটি সোনার আইটেম যোগ করুন।'], 422);
        }
        if ($paidAmount < 0) {
            json_out(['success' => false, 'message' => 'পরিশোধিত টাকা নেগেটিভ হতে পারবে না।'], 422);
        }

        // Default payment date to today if not provided
        if ($paidAmount > 0 && empty($paymentDate)) {
            $paymentDate = date('Y-m-d');
        }

        // Verify customer exists
        $cstmt = mysqli_prepare($conn, "SELECT id FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($cstmt, 'i', $customerId);
        mysqli_stmt_execute($cstmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($cstmt))) {
            json_out(['success' => false, 'message' => 'সিলেক্ট করা কাস্টমারের কোনো অস্তিত্ব নেই।'], 404);
        }

        $calcItems   = [];
        $totalAmount = 0.0;

        foreach ($items as $i => $item) {
            $n = $i + 1;

            // Vori/Ana/Roti/Point must be whole numbers within boundaries
            foreach (['vori', 'ana', 'roti', 'point'] as $field) {
                $raw = $item[$field] ?? 0;
                if (!is_numeric($raw) || (float)$raw != (int)$raw) {
                    $fieldLabels = ['vori' => 'ভ', 'ana' => 'আ', 'roti' => 'র', 'point' => 'প'];
                    $fieldLabel = $fieldLabels[$field] ?? ucfirst($field);
                    json_out(['success' => false, 'message' => "আইটেম $n: $fieldLabel অবশ্যই একটি পূর্ণ সংখ্যা হতে হবে।"], 422);
                }
            }

            $vori   = (int)($item['vori']   ?? 0);
            $ana    = (int)($item['ana']    ?? 0);
            $roti   = (int)($item['roti']   ?? 0);
            $point  = (int)($item['point']  ?? 0);
            $purity = (float)($item['purity'] ?? 0);

            if ($vori < 0)                json_out(['success' => false, 'message' => "আইটেম $n: ভরি নেগেটিভ হতে পারবে না।"], 422);
            if ($ana < 0 || $ana > 15)    json_out(['success' => false, 'message' => "আইটেম $n: আনা অবশ্যই 0–15 এর মধ্যে হতে হবে।"], 422);
            if ($roti < 0 || $roti > 5)   json_out(['success' => false, 'message' => "আইটেম $n: রতি অবশ্যই 0–5 এর মধ্যে হতে হবে।"], 422);
            if ($point < 0 || $point > 9) json_out(['success' => false, 'message' => "আইটেম $n: পয়েন্ট অবশ্যই 0–9 এর মধ্যে হতে হবে।"], 422);
            if ($purity < 0.01 || $purity > 24) {
                json_out(['success' => false, 'message' => "আইটেম $n: সোনার মান অবশ্যই 0.01–24.00 এর মধ্যে হতে হবে।"], 422);
            }

            $grams = traditional_to_grams($vori, $ana, $roti, $point);
            if ($grams <= 0) {
                json_out(['success' => false, 'message' => "আইটেম $n: ওজন অবশ্যই শূন্যের বেশি হতে হবে।"], 422);
            }

            // price = (grams / G_PER_VORI) * (purity / 24) * pure_gold_price
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
            // Insert gold_sales row — paid_amount cached from payment
            $stmt = mysqli_prepare($conn,
                "INSERT INTO gold_sales
                    (customer_id, pure_gold_price, total_amount, paid_amount, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'idddsi',
                $customerId, $pureGoldPrice, $totalAmount, $paidAmount, $note, $userId);
            mysqli_stmt_execute($stmt);
            $saleId = (int) mysqli_insert_id($conn);

            // Insert sale items
            $itemStmt = mysqli_prepare($conn,
                "INSERT INTO gold_sale_items (gold_sale_id, weight, purity, price)
                 VALUES (?, ?, ?, ?)");
            foreach ($calcItems as $ci) {
                mysqli_stmt_bind_param($itemStmt, 'iddd',
                    $saleId, $ci['weight'], $ci['purity'], $ci['price']);
                mysqli_stmt_execute($itemStmt);
            }

            // Insert initial payment record if paid_amount > 0
            if ($paidAmount > 0) {
                $pmtStmt = mysqli_prepare($conn,
                    "INSERT INTO gold_sale_payments
                        (gold_sale_id, paid_amount, transaction_ref, payment_date, note, received_by)
                     VALUES (?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($pmtStmt, 'idsssi',
                    $saleId, $paidAmount, $transactionRef, $paymentDate, $paymentNote, $userId);
                mysqli_stmt_execute($pmtStmt);
            }

            mysqli_commit($conn);
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            json_out(['success' => false, 'message' => 'বিক্রি সেভ করা যায়নি। আবার চেষ্টা করুন।'], 500);
        }

        json_out([
            'success' => true,
            'message' => 'সোনা বিক্রি সেভ হয়েছে।',
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
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>সোনা বিক্রি — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
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

body {
    background: var(--ivory);
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--bronze-text);
}

/* ---- Page header (flush) ---- */
.fb-header {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%) !important;
    color: #ffffff !important;
    width: 100% !important;
    margin: 0 !important;
    min-height: 60px !important;
    max-height: 80px !important;
    padding: 0.85rem 1.75rem !important;
    border-radius: 0 0 20px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: nowrap;
    gap: 1rem;
}
.fb-header * { color: #ffffff !important; }
.fb-header h4 { font-weight: 800; margin-bottom: 0.1rem; }
.fb-header small { color: rgba(255,255,255,0.85) !important; }
.fb-header .btn-fb-header {
    background: rgba(255,255,255,0.16);
    border: 1.5px solid rgba(255,255,255,0.55);
    color: #ffffff !important;
    font-weight: 600;
    border-radius: 999px;
    white-space: nowrap;
}
.fb-header .btn-fb-header:hover { background: rgba(255,255,255,0.26); }

/* ---- Page content inset ---- */
.page-inset { padding: 0 1.5rem; }

/* ---- Cards ---- */
.card {
    background: #ffffff;
    border: none;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(180,140,50,0.12);
}
.card-header {
    background: var(--ivory) !important;
    border-bottom: 1px solid var(--hairline);
    border-radius: 18px 18px 0 0 !important;
    color: var(--bronze-text);
    font-weight: 700;
}

/* ---- Inputs ---- */
.form-control,
.input-group-text {
    border: 1.5px solid var(--hairline);
    border-radius: 10px;
    color: var(--bronze-text);
    background: #ffffff;
}
.form-control:focus {
    border-color: var(--gold-deep);
    box-shadow: 0 0 0 0.15rem rgba(201,151,58,0.18);
}
.input-group-text {
    background: var(--gold-deep);
    color: #ffffff;
    border-color: var(--gold-deep);
    font-weight: 600;
}

/* ---- Buttons ---- */
.btn-fb-primary, .btn-record, .btn-add-item {
    background: var(--gold-deep);
    border: 1.5px solid var(--gold-deep);
    color: #ffffff;
    font-weight: 700;
    border-radius: 999px;
}
.btn-fb-primary:hover, .btn-record:hover, .btn-add-item:hover {
    opacity: 0.92;
    background: var(--gold-deep);
    border-color: var(--gold-deep);
    color: #ffffff;
}
.btn-fb-secondary {
    background: #ffffff;
    border: 1.5px solid var(--hairline);
    color: var(--muted);
    font-weight: 600;
    border-radius: 999px;
}
.btn-fb-secondary:hover {
    background: #fdf7ec;
    border-color: var(--hairline);
    color: var(--bronze-text);
}
.btn-remove-item {
    background: var(--status-due-light);
    border: 1.5px solid var(--status-due-bg);
    color: var(--status-due-bg);
    font-weight: 600;
    border-radius: 999px;
}
.btn-remove-item:hover { background: var(--status-due-bg); color: #ffffff; }

/* ---- Customer autocomplete ---- */
.customer-results-box {
    position: absolute;
    z-index: 20;
    background: #fff;
    border: 1.5px solid var(--hairline);
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(180,140,50,0.16);
    width: 100%;
    max-height: 260px;
    overflow-y: auto;
    display: none;
}
.customer-result-item {
    cursor: pointer;
    padding: 0.55rem 0.9rem;
    border-bottom: 1px solid var(--hairline);
}
.customer-result-item:hover { background: #fdf7ec; }
.customer-result-item:last-child { border-bottom: none; }
.customer-result-photo {
    width: 32px; height: 32px; border-radius: 50%; object-fit: cover;
    border: 1px solid var(--hairline); flex-shrink: 0;
}
.customer-result-photo-fallback {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    background: var(--gold-deep); color: #fff; font-size: 0.8rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}
.selected-customer-card {
    border: 1.5px solid var(--gold-deep);
    background: var(--status-total-light);
    border-radius: 12px;
    padding: 0.75rem 1rem;
    display: none;
    align-items: center;
    justify-content: space-between;
}

/* ---- Pure gold price row ---- */
.price-row {
    background: var(--ivory);
    border: 1.5px solid var(--hairline);
    border-radius: 12px;
    padding: 0.75rem 1rem;
}
.price-row label { font-size: 0.82rem; color: var(--muted); margin-bottom: 0.2rem; display: block; }

/* ---- Gold item card ---- */
.gold-item-card {
    border: 1.5px solid var(--hairline);
    border-radius: 14px;
    padding: 1rem 1.1rem 0.85rem;
    margin-bottom: 1rem;
    background: #fff;
    position: relative;
}
.gold-item-card .item-badge {
    position: absolute;
    top: -10px;
    left: 14px;
    background: var(--gold-deep);
    color: #fff;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 0.1rem 0.65rem;
    border-radius: 10px;
}
.gold-item-card .btn-remove-item {
    position: absolute;
    top: 10px;
    right: 10px;
}

/* Weight grid: Vori / Ana / Roti / Point in one row */
.weight-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.5rem;
}
.weight-grid .field-col label {
    display: block;
    font-size: 0.72rem;
    margin-bottom: 0.15rem;
    color: var(--muted);
    white-space: nowrap;
}
.weight-grid .field-col input {
    text-align: center;
    padding-left: 0.25rem;
    padding-right: 0.25rem;
}

/* Remove Bootstrap icon decorations on validated inputs */
.weight-grid input.form-control.is-valid,
.weight-grid input.form-control.is-invalid {
    background-image: none !important;
    padding-right: 0.25rem !important;
}
.form-control.is-valid { border-color: var(--status-paid-bg); }
.form-control.is-invalid { border-color: var(--status-due-bg); }
.invalid-feedback { color: var(--status-due-bg); }

/* Purity row — matches gold_buy.php */
.purity-row { margin-top: 0.6rem; }
.purity-row label {
    display: block;
    font-size: 0.72rem;
    margin-bottom: 0.15rem;
    color: var(--muted);
}
.purity-row input.form-control.is-valid,
.purity-row input.form-control.is-invalid {
    background-image: none !important;
    padding-right: 0.25rem !important;
}

/* Item price result chip */
.item-price-result {
    background: var(--status-total-light);
    border: 1px dashed var(--gold-deep);
    border-radius: 10px;
    padding: 0.45rem 0.8rem;
    font-size: 0.88rem;
    color: var(--status-total-bg);
    font-weight: 600;
    margin-top: 0.65rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.item-price-result .weight-sub {
    font-size: 0.75rem;
    color: var(--muted);
    font-weight: 400;
}

/* ---- Summary panel ---- */
.summary-card {
    background: #fff;
    border: none;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(180,140,50,0.12);
    overflow: hidden;
}
.summary-card .sum-header {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%);
    color: #fff;
    padding: 0.75rem 1.2rem;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.summary-card .sum-body { padding: 0.9rem 1.2rem; }
.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 0.4rem 0;
    border-bottom: 1px solid var(--hairline);
}
.summary-row:last-of-type { border-bottom: none; }
.summary-row .s-label { font-size: 0.83rem; color: var(--muted); }
.summary-row .s-value { font-weight: 700; font-size: 0.97rem; color: var(--bronze-text); }
.summary-row.s-total .s-value { color: var(--status-total-bg); font-size: 1.05rem; }

/* Payment section in summary */
.payment-section {
    border-top: 1px dashed var(--hairline);
    margin-top: 0.5rem;
    padding-top: 0.75rem;
}
.payment-section .section-title {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--muted);
    margin-bottom: 0.5rem;
}
.payment-section label { font-size: 0.78rem; color: var(--muted); margin-bottom: 0.2rem; display: block; }
.payment-section input[type="number"],
.payment-section input[type="date"],
.payment-section input[type="text"] {
    font-size: 0.88rem;
}

/* Payment fields — label + input in the same row */
.payment-field-line {
    display: grid;
    grid-template-columns: 90px 1fr;
    align-items: center;
    gap: 0.6rem;
    margin-bottom: 0.5rem;
}
.payment-field-line label {
    margin-bottom: 0;
    white-space: normal;
    line-height: 1.2;
}
.payment-field-line .input-group-text { padding: 0.25rem 0.5rem; }
.payment-field-line:last-child { margin-bottom: 0; }

/* Paid amount editable row */
.paid-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.45rem 0;
    border-bottom: 1px solid var(--hairline);
}
.paid-row .s-label { font-size: 0.83rem; color: var(--muted); }
.paid-row input {
    width: 130px;
    text-align: right;
    font-weight: 700;
    border: 1.5px solid var(--hairline);
    border-radius: 8px;
    padding: 0.25rem 0.5rem;
    font-size: 0.95rem;
    color: var(--status-total-bg);
}
.paid-row input:focus {
    outline: none;
    border-color: var(--gold-deep);
    box-shadow: 0 0 0 0.15rem rgba(201,151,58,0.18);
}

/* Due amount row */
.due-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 0.5rem 0 0.1rem;
    border-top: 2px solid var(--gold-deep);
    margin-top: 0.2rem;
}
.due-row .s-label { font-size: 0.9rem; font-weight: 700; color: var(--bronze-text); }
.due-row .s-value { font-size: 1.2rem; font-weight: 800; color: var(--status-due-bg); }
.due-row .s-value.zero { color: var(--status-paid-bg); }

/* ── Mobile ── */
@media (max-width: 767.98px) {
    .page-inset { padding: 0 0.8rem; }

    .fb-header {
        min-height: 60px !important;
        max-height: 70px !important;
        padding: 0.75rem 1rem !important;
        border-radius: 0 0 16px 16px;
    }
    .fb-header h4 { font-size: 1rem; margin-bottom: 0; }
    .fb-header small { font-size: 0.7rem; }
    .fb-header .btn-fb-header { padding: 0.3rem 0.65rem; font-size: 0.72rem; }

    .row.g-4 { --bs-gutter-y: 0.6rem; }
    .card { margin-bottom: 0.6rem !important; border-radius: 14px; }
    .card-header { padding: 0.45rem 0.75rem; font-size: 0.82rem; }
    .card-body { padding: 0.6rem 0.75rem; }

    #customerSearch { font-size: 0.85rem; padding: 0.4rem 0.6rem; }
    .selected-customer-card { padding: 0.5rem 0.7rem; }

    .gold-item-card { padding: 0.75rem 0.75rem 0.6rem; margin-bottom: 0.5rem; border-radius: 12px; }
    .gold-item-card .item-badge { top: -9px; left: 12px; font-size: 0.65rem; padding: 0.08rem 0.5rem; }
    .gold-item-card .btn-remove-item { top: 6px; right: 6px; padding: 0.15rem 0.4rem; }
    .weight-grid { gap: 0.4rem; }
    .item-price-result { font-size: 0.78rem; padding: 0.35rem 0.6rem; margin-top: 0.5rem; }

    .summary-card .sum-body { padding: 0.7rem 0.9rem; }
    .paid-row input { width: 110px; font-size: 0.88rem; }
    .due-row .s-value { font-size: 1.05rem; }

    .payment-field-line { grid-template-columns: 78px 1fr; gap: 0.4rem; margin-bottom: 0.4rem; }
    .payment-field-line label { font-size: 0.72rem; }
    .payment-field-note { display: none !important; }

    #btnSave { padding: 0.5rem; font-size: 0.9rem; margin-top: 0.6rem !important; }
    #noteCard { display: none !important; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0">
    <!-- Page header -->
    <div class="fb-header">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-bag-check-fill me-2"></i>
                <span class="d-none d-md-inline">নতুন সোনা বিক্রি</span>
                <span class="d-md-none">সোনা বিক্রি</span>
            </h4>
            <small class="d-none d-md-inline">কাস্টমারের কাছে ২৪ ক্যারেট পাকা সোনা বিক্রি করুন</small>
        </div>
        <a href="gold_sale_list.php" class="btn btn-fb-header btn-sm d-inline-flex align-items-center">
            <i class="bi bi-list-ul me-1"></i> বিক্রির হিস্ট্রি
        </a>
    </div>
</div>

<div class="page-inset py-4">

    <form id="saleForm" autocomplete="off">
        <div class="row g-4">

            <!-- ── Left column ── -->
            <div class="col-lg-8">

                <!-- Customer -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-person-fill me-1 text-success"></i> কাস্টমার খুঁজুন
                    </div>
                    <div class="card-body">
                        <div class="position-relative">
                            <input type="text" class="form-control" id="customerSearch"
                                   placeholder="কাস্টমারের নাম বা ফোন নম্বর দিয়ে খুঁজুন">
                            <div class="customer-results-box" id="customerResults"></div>
                        </div>
                        <input type="hidden" id="customerId" name="customer_id">

                        <div class="selected-customer-card mt-3" id="selectedCustomerCard">
                            <div>
                                <div class="fw-semibold" id="selectedCustomerName">—</div>
                                <small class="text-muted" id="selectedCustomerPhone">—</small>
                            </div>
                            <button type="button" class="btn btn-sm btn-fb-secondary" id="btnClearCustomer">
                                <i class="bi bi-x-lg me-1"></i> মুছুন
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Pure Gold Price -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-coin me-1 text-warning"></i> পাকা সোনার দাম (২৪ ক্যারেট)
                    </div>
                    <div class="card-body">
                        <div class="price-row">
                            <label>প্রতি ভরি পাকা সোনার দাম (টাকা)</label>
                            <div class="input-group">
                                <span class="input-group-text">৳</span>
                                <input type="number" class="form-control form-control-lg fw-bold"
                                       id="pureGoldPrice" name="pure_gold_price"
                                       min="1" step="1" placeholder="যেমন ২৩৬০০০"
                                       style="font-size: 1.25rem;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sale Items -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">
                            <i class="bi bi-gem me-1 text-warning"></i> সোনার আইটেম
                        </span>
                        <button type="button" class="btn btn-sm btn-add-item" id="btnAddItem">
                            <i class="bi bi-plus-lg me-1"></i> যোগ করুন
                        </button>
                    </div>
                    <div class="card-body" id="itemsContainer">
                        <!-- items injected by JS -->
                    </div>
                </div>

                <!-- Note (hidden on mobile) -->
                <div class="card shadow-sm mb-4" id="noteCard">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-pencil-square me-1"></i> নোট / মন্তব্য
                    </div>
                    <div class="card-body">
                        <textarea class="form-control" id="note" name="note" rows="2"
                                  placeholder="নোট লিখুন (ঐচ্ছিক)…"></textarea>
                    </div>
                </div>
            </div>

            <!-- ── Right column: Summary ── -->
            <div class="col-lg-4">
                <div class="summary-card shadow-sm sticky-top" style="top: 1rem;">
                    <div class="sum-header">সামারি</div>
                    <div class="sum-body">

                        <div class="summary-row">
                            <span class="s-label">মোট সোনার ওজন</span>
                            <span class="s-value" id="sumTotalWeight">০ ভ ০ আ ০ র ০ প</span>
                        </div>

                        <div class="summary-row s-total">
                            <span class="s-label">মোট দাম:</span>
                            <span class="s-value" id="sumTotalPrice">৳০</span>
                        </div>

                        <!-- Initial Payment Section -->
                        <div class="payment-section">
                            <div class="section-title">
                                <i class="bi bi-cash-stack me-1"></i> প্রাথমিক পেমেন্ট
                            </div>

                            <div class="payment-field-line">
                                <label for="paymentDate">পেমেন্টের তারিখ</label>
                                <input type="date" id="paymentDate" name="payment_date"
                                       class="form-control form-control-sm"
                                       value="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="payment-field-line">
                                <label for="paidAmount">পরিশোধিত টাকা (টাকা)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">৳</span>
                                    <input type="number" id="paidAmount" name="paid_amount"
                                           class="form-control"
                                           min="0" step="1" value="0" placeholder="0">
                                </div>
                            </div>

                            <div class="payment-field-line">
                                <label for="transactionRef">ট্রানজেকশন রেফারেন্স <small class="text-muted">(চেক / ব্যাংক / MFS)</small></label>
                                <input type="text" id="transactionRef" name="transaction_ref"
                                       class="form-control form-control-sm"
                                       placeholder="রেফারেন্স নম্বর (ঐচ্ছিক)…">
                            </div>

                            <div class="payment-field-line payment-field-note">
                                <label for="paymentNote">পেমেন্ট নোট <small class="text-muted">(ঐচ্ছিক)</small></label>
                                <input type="text" id="paymentNote" name="payment_note"
                                       class="form-control form-control-sm"
                                       placeholder="যেমন bKash, নগদ, ব্যাংক ট্রান্সফার…">
                            </div>
                        </div>

                        <div class="due-row mt-2">
                            <span class="s-label">বকেয়া টাকা:</span>
                            <span class="s-value" id="sumDueAmount">৳০</span>
                        </div>
                    </div>

                    <div class="px-3 pb-3 mt-1">
                        <button type="submit" class="btn btn-record w-100 py-2" id="btnSave">
                            <i class="bi bi-check2-circle me-1"></i> সোনা বিক্রি রেকর্ড করুন
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
        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item" data-remove title="ডিলিট">
            <i class="bi bi-trash3"></i>
        </button>

        <!-- Weight in traditional units -->
        <div class="weight-grid mt-3">
            <div class="field-col">
                <label>ভরি</label>
                <input type="number" min="0" step="1"
                       class="form-control form-control-sm" data-field="vori" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="vori"></div>
            </div>
            <div class="field-col">
                <label>আনা</label>
                <input type="number" min="0" max="15" step="1"
                       class="form-control form-control-sm" data-field="ana" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="ana"></div>
            </div>
            <div class="field-col">
                <label>রতি</label>
                <input type="number" min="0" max="5" step="1"
                       class="form-control form-control-sm" data-field="roti" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="roti"></div>
            </div>
            <div class="field-col">
                <label>পয়েন্ট</label>
                <input type="number" min="0" max="9" step="1"
                       class="form-control form-control-sm" data-field="point" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="point"></div>
            </div>
        </div>

        <!-- Purity (karat) — same as buy form -->
        <div class="purity-row">
            <label>সোনার মান (ক্যারেট)</label>
            <input type="number" min="0.01" max="24" step="0.01"
                   class="form-control form-control-sm" data-field="purity" value="24"
                   placeholder="যেমন ১৮, ২২, ২৪">
            <div class="invalid-feedback" data-error="purity"></div>
        </div>

        <div class="item-price-result" data-price-result>
            <span>আইটেমের দাম : <strong data-price-value>৳০</strong></span>
            <span class="weight-sub" data-weight-sub>0g</span>
        </div>
    </div>
</template>

<script>
// ── Conversion constants ──
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
    if (roti >= 6)   { roti -= 6;   ana  += 1; }
    if (ana  >= 16)  { ana  -= 16;  vori += 1; }

    return { vori, ana, roti, point };
}

function formatTrad(t) { return `${t.vori} ভ ${t.ana} আ ${t.roti} র ${t.point} প`; }
function formatBDT(n)  { return '৳' + Math.round(n).toLocaleString('bn-BD'); }

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
                customerResults.innerHTML = '<div class="p-2 text-danger small">খুঁজতে ব্যর্থ হয়েছে।</div>';
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
            customerResults.innerHTML = '<div class="p-2 text-danger small">খুঁজতে ব্যর্থ হয়েছে।</div>';
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
        if (errEl) errEl.textContent = `${rules.label} অবশ্যই একটি পূর্ণ সংখ্যা হতে হবে।`;
        return { valid: false, value: 0 };
    }
    const n = Number(raw);
    if (!Number.isFinite(n) || !Number.isInteger(n)) {
        input.classList.add('is-invalid'); input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} অবশ্যই একটি পূর্ণ সংখ্যা হতে হবে।`;
        return { valid: false, value: 0 };
    }
    if (n < rules.min) {
        input.classList.add('is-invalid'); input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} নেগেটিভ হতে পারবে না।`;
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

    // Purity (karat)
    const purityInput = card.querySelector('[data-field="purity"]');
    const purityErrEl = purityInput.parentElement.querySelector('[data-error="purity"]');
    const purity = parseFloat(purityInput.value);
    if (isNaN(purity) || purity < 0.01 || purity > 24) {
        purityInput.classList.add('is-invalid'); purityInput.classList.remove('is-valid');
        if (purityErrEl) purityErrEl.textContent = 'সোনার মান অবশ্যই 0.01 – 24.00 এর মধ্যে হতে হবে।';
        allValid = false;
        results.purity = 24;
    } else {
        purityInput.classList.remove('is-invalid'); purityInput.classList.add('is-valid');
        if (purityErrEl) purityErrEl.textContent = '';
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
    const { grams, price } = calcItemPrice(v, getPureGoldPrice());
    card.querySelector('[data-price-value]').textContent = formatBDT(price);
    card.querySelector('[data-weight-sub]').textContent  = grams.toFixed(4) + ' গ্রাম';
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

function renumberItems() {
    itemsContainer.querySelectorAll('[data-item]').forEach((card, idx) => {
        card.querySelector('[data-item-num]').textContent = idx + 1;
    });
}

document.getElementById('btnAddItem').addEventListener('click', addItem);

// ── Summary ──
const paidAmountInput = document.getElementById('paidAmount');
paidAmountInput.addEventListener('input', renderSummary);

function renderSummary() {
    let totalGrams = 0;
    let totalPrice = 0;

    itemsContainer.querySelectorAll('[data-item]').forEach(card => {
        const v = getItemValues(card);
        const { grams, price } = calcItemPrice(v, getPureGoldPrice());
        totalGrams += grams;
        totalPrice += price;
    });

    const trad = gramsToTraditional(totalGrams);
    document.getElementById('sumTotalWeight').textContent = formatTrad(trad);
    document.getElementById('sumTotalPrice').textContent  = formatBDT(totalPrice);

    const paid = Math.max(0, parseFloat(paidAmountInput.value) || 0);
    const due  = totalPrice - paid;

    const dueEl = document.getElementById('sumDueAmount');
    dueEl.textContent = formatBDT(Math.abs(due));
    dueEl.className   = 's-value' + (due <= 0 ? ' zero' : '');
}

// Start with one item
addItem();

// ── Save ──
document.getElementById('saleForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    if (!customerIdInput.value) { alert('একজন কাস্টমার সিলেক্ট করুন।'); return; }

    const price24k = getPureGoldPrice();
    if (price24k <= 0) { alert('পাকা সোনার দাম (২৪ ক্যারেট) লিখুন।'); return; }

    const items = [];
    let hasError = false;

    itemsContainer.querySelectorAll('[data-item]').forEach(card => {
        const v = getItemValues(card);
        if (!v.allValid) hasError = true;
        items.push({ vori: v.vori, ana: v.ana, roti: v.roti, point: v.point, purity: v.purity });
    });

    if (items.length === 0) { alert('কমপক্ষে একটি সোনার আইটেম যোগ করুন।'); return; }
    if (hasError)           { alert('সেভ করার আগে হাইলাইট করা ভুলগুলো ঠিক করুন।'); return; }

    for (const it of items) {
        if (traditionalToGrams(it.vori, it.ana, it.roti, it.point) <= 0) {
            alert('প্রতিটি আইটেমের ওজন অবশ্যই শূন্যের বেশি হতে হবে।');
            return;
        }
    }

    const paid = Math.max(0, parseFloat(paidAmountInput.value) || 0);

    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> সেভ হচ্ছে…';

    try {
        const fd = new FormData();
        fd.append('action',          'save');
        fd.append('customer_id',     customerIdInput.value);
        fd.append('pure_gold_price', price24k);
        fd.append('paid_amount',     paid);
        fd.append('payment_date',    document.getElementById('paymentDate').value);
        fd.append('transaction_ref', document.getElementById('transactionRef').value);
        fd.append('payment_note',    document.getElementById('paymentNote').value);
        fd.append('note',            document.getElementById('note').value);
        fd.append('items',           JSON.stringify(items));

        const res  = await fetch('gold_sale.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        });
        const data = await res.json();

        if (data.success) {
            window.location.href = 'gold_sale_list.php';
        } else {
            alert(data.message || 'সোনা বিক্রি সেভ করা যায়নি।');
        }
    } catch {
        alert('নেটওয়ার্ক সমস্যা। আবার চেষ্টা করুন।');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> সোনা বিক্রি রেকর্ড করুন';
    }
});
</script>

</body>
</html>