<?php
/**
 * gold_buy.php
 * FineBullion Desk — Old Gold Buy (create new buy)
 *
 * Business rules:
 *   - Pure Gold Price (24k) is entered in BDT per Vori.
 *   - Old gold weight entered in traditional units:
 *       Vori  >= 0 (no upper limit)
 *       Ana   0–15
 *       Roti  0–5
 *       Point 0–9
 *     (10 Point = 1 Roti, 6 Roti = 1 Ana, 16 Ana = 1 Vori)
 *   - Purity (karat) is a free DECIMAL input (0.01 – 24.00).
 *   - Item grams    = traditional_to_grams(vori, ana, roti, point)
 *   - Item price    = (item_grams / G_PER_VORI) * (purity / 24) * pure_gold_price_per_vori
 *   - Total price   = sum of all item prices
 *   - Due Amount    = Total Price – Paid Amount
 *   - Weight is stored in grams; purity stored as karat decimal.
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
    if ($roti >= 6)   { $roti -= 6;   $ana += 1; }
    if ($ana >= 16)   { $ana -= 16;   $vori += 1; }

    return ['vori' => $vori, 'ana' => $ana, 'roti' => $roti, 'point' => $point];
}

// -----------------------------------------------------------------------
// AJAX actions
// -----------------------------------------------------------------------
if ($isAjax || $action !== null) {

    // ---- Customer quick lookup ----
    if ($action === 'customer' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'Invalid customer ID.'], 400);

        $stmt = mysqli_prepare($conn, "SELECT id, name, phone FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$row) json_out(['success' => false, 'message' => 'Customer not found.'], 404);
        json_out(['success' => true, 'data' => $row]);
    }

    // ---- SAVE buy ---------------------------------------------------
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $customerId     = (int)($_POST['customer_id']      ?? 0);
        $pureGoldPrice  = (float)($_POST['pure_gold_price'] ?? 0);
        $paidAmount     = (float)($_POST['paid_amount']      ?? 0);
        $note           = trim($_POST['note'] ?? '') ?: null;
        $items          = json_decode($_POST['items'] ?? '[]', true);

        if ($customerId <= 0) {
            json_out(['success' => false, 'message' => 'Please select a customer.'], 422);
        }
        if ($pureGoldPrice <= 0) {
            json_out(['success' => false, 'message' => 'Pure gold price must be greater than zero.'], 422);
        }
        if (!is_array($items) || count($items) === 0) {
            json_out(['success' => false, 'message' => 'Add at least one gold item.'], 422);
        }
        if ($paidAmount < 0) {
            json_out(['success' => false, 'message' => 'Paid amount cannot be negative.'], 422);
        }

        // Verify customer exists
        $cstmt = mysqli_prepare($conn, "SELECT id FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($cstmt, 'i', $customerId);
        mysqli_stmt_execute($cstmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($cstmt))) {
            json_out(['success' => false, 'message' => 'Selected customer does not exist.'], 404);
        }

        $calcItems   = [];
        $totalAmount = 0.0;

        foreach ($items as $i => $item) {
            $n = $i + 1;

            foreach (['vori', 'ana', 'roti', 'point'] as $field) {
                $raw = $item[$field] ?? 0;
                if (!is_numeric($raw) || (float)$raw != (int)$raw) {
                    json_out(['success' => false, 'message' => "Item $n: " . ucfirst($field) . " must be a whole number."], 422);
                }
            }

            $vori  = (int)($item['vori']   ?? 0);
            $ana   = (int)($item['ana']    ?? 0);
            $roti  = (int)($item['roti']   ?? 0);
            $point = (int)($item['point']  ?? 0);
            $purity = (float)($item['purity'] ?? 0);

            if ($vori < 0)               json_out(['success' => false, 'message' => "Item $n: Vori cannot be negative."], 422);
            if ($ana < 0 || $ana > 15)   json_out(['success' => false, 'message' => "Item $n: Ana must be 0–15."], 422);
            if ($roti < 0 || $roti > 5)  json_out(['success' => false, 'message' => "Item $n: Roti must be 0–5."], 422);
            if ($point < 0 || $point > 9) json_out(['success' => false, 'message' => "Item $n: Point must be 0–9."], 422);
            if ($purity < 0.01 || $purity > 24) {
                json_out(['success' => false, 'message' => "Item $n: Purity must be 0.01–24.00."], 422);
            }

            $grams = traditional_to_grams($vori, $ana, $roti, $point);
            if ($grams <= 0) {
                json_out(['success' => false, 'message' => "Item $n: Weight must be greater than zero."], 422);
            }

            // Price = (grams / G_PER_VORI) * (purity / 24) * pureGoldPrice
            $price = ($grams / G_PER_VORI) * ($purity / 24) * $pureGoldPrice;
            $totalAmount += $price;

            $calcItems[] = [
                'weight' => $grams,
                'purity' => $purity,
                'price'  => $price,
            ];
        }

        $userId = $currentUser['id'];

        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO gold_buys
                    (customer_id, pure_gold_price, total_amount, paid_amount, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'idddsi',
                $customerId, $pureGoldPrice, $totalAmount, $paidAmount, $note, $userId);
            mysqli_stmt_execute($stmt);
            $buyId = (int) mysqli_insert_id($conn);

            $itemStmt = mysqli_prepare($conn,
                "INSERT INTO gold_buy_items (gold_buy_id, weight, purity, price)
                 VALUES (?, ?, ?, ?)");

            foreach ($calcItems as $ci) {
                mysqli_stmt_bind_param($itemStmt, 'iddd',
                    $buyId, $ci['weight'], $ci['purity'], $ci['price']);
                mysqli_stmt_execute($itemStmt);
            }

            mysqli_commit($conn);
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            json_out(['success' => false, 'message' => 'Failed to save buy. Please try again.'], 500);
        }

        json_out([
            'success' => true,
            'message' => 'Gold buy saved.',
            'id'      => $buyId,
            'summary' => [
                'total_amount' => $totalAmount,
                'paid_amount'  => $paidAmount,
                'due_amount'   => $totalAmount - $paidAmount,
            ],
        ]);
    }

    json_out(['success' => false, 'message' => 'Unknown action.'], 400);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Old Gold Buy — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --fb-green: #0B412A;
    --fb-gold:  #DCAD41;
}
body { background: #f5f6fa; font-family: "Segoe UI", Arial, sans-serif; }

/* ---- Page header ---- */
.buy-header {
    background: linear-gradient(135deg, var(--fb-green) 0%, #0e5636 100%);
    color: #fff;
    border-radius: 10px;
    padding: 1.25rem 1.5rem;
}
.buy-header small { color: rgba(255,255,255,0.75); }

/* ---- Customer autocomplete ---- */
.customer-results-box {
    position: absolute;
    z-index: 20;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.12);
    width: 100%;
    max-height: 260px;
    overflow-y: auto;
    display: none;
}
.customer-result-item {
    cursor: pointer;
    padding: 0.55rem 0.9rem;
    border-bottom: 1px solid #eee;
}
.customer-result-item:hover { background: #f8f4e8; }
.customer-result-item:last-child { border-bottom: none; }
.customer-result-photo {
    width: 32px; height: 32px; border-radius: 50%; object-fit: cover;
    border: 1px solid #dee2e6; flex-shrink: 0;
}
.customer-result-photo-fallback {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    background: var(--fb-green); color: #fff; font-size: 0.8rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}
.selected-customer-card {
    border: 1px solid var(--fb-gold);
    background: #fdf8ec;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    display: none;
    align-items: center;
    justify-content: space-between;
}

/* ---- Pure gold price row ---- */
.price-row {
    background: #f9f9f9;
    border: 1px solid #e2e5ea;
    border-radius: 8px;
    padding: 0.75rem 1rem;
}
.price-row label { font-size: 0.82rem; color: #6c757d; margin-bottom: 0.2rem; display: block; }
.price-row .input-group-text {
    background: var(--fb-green);
    color: #fff;
    border-color: var(--fb-green);
    font-weight: 600;
}

/* ---- Gold item card ---- */
.gold-item-card {
    border: 1px solid #e2e5ea;
    border-radius: 10px;
    padding: 1rem 1.1rem 0.85rem;
    margin-bottom: 1rem;
    background: #fff;
    position: relative;
}
.gold-item-card .item-badge {
    position: absolute;
    top: -10px;
    left: 14px;
    background: var(--fb-green);
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
.weight-grid .field-col label,
.purity-row label {
    display: block;
    font-size: 0.72rem;
    margin-bottom: 0.15rem;
    color: #6c757d;
    white-space: nowrap;
}
.weight-grid .field-col input { text-align: center; padding-left: 0.25rem; padding-right: 0.25rem; }

/* Remove Bootstrap icon decorations on validated weight/purity inputs */
.weight-grid input.form-control.is-valid,
.weight-grid input.form-control.is-invalid,
.purity-row input.form-control.is-valid,
.purity-row input.form-control.is-invalid {
    background-image: none !important;
    padding-right: 0.25rem !important;
}
.purity-row { margin-top: 0.6rem; }

/* Item price result chip */
.item-price-result {
    background: #f4f9f6;
    border: 1px dashed #bcd9c9;
    border-radius: 8px;
    padding: 0.45rem 0.8rem;
    font-size: 0.88rem;
    color: var(--fb-green);
    font-weight: 600;
    margin-top: 0.65rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.item-price-result .weight-sub {
    font-size: 0.75rem;
    color: #6c757d;
    font-weight: 400;
}

/* ---- Summary panel ---- */
.summary-card {
    background: #fff;
    border: 1px solid #e2e5ea;
    border-radius: 12px;
    overflow: hidden;
}
.summary-card .sum-header {
    background: var(--fb-green);
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
    border-bottom: 1px solid #f0f0f0;
}
.summary-row:last-of-type { border-bottom: none; }
.summary-row .s-label { font-size: 0.83rem; color: #6c757d; }
.summary-row .s-value { font-weight: 700; font-size: 0.97rem; color: #1a1a1a; }
.summary-row.s-total .s-value { color: var(--fb-green); font-size: 1.05rem; }

/* Paid amount editable row */
.paid-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.45rem 0;
    border-bottom: 1px solid #f0f0f0;
}
.paid-row .s-label { font-size: 0.83rem; color: #6c757d; }
.paid-row input {
    width: 130px;
    text-align: right;
    font-weight: 700;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 0.25rem 0.5rem;
    font-size: 0.95rem;
    color: var(--fb-green);
}
.paid-row input:focus {
    outline: none;
    border-color: var(--fb-gold);
    box-shadow: 0 0 0 0.15rem rgba(220,173,65,.3);
}

/* Due amount row */
.due-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 0.5rem 0 0.1rem;
    border-top: 2px solid var(--fb-green);
    margin-top: 0.2rem;
}
.due-row .s-label { font-size: 0.9rem; font-weight: 700; color: var(--fb-green); }
.due-row .s-value { font-size: 1.2rem; font-weight: 800; color: #c0392b; }
.due-row .s-value.zero { color: #2e7d32; }

/* Gold buy button */
.btn-record {
    background: var(--fb-gold);
    border-color: var(--fb-gold);
    color: #1a1a1a;
    font-weight: 700;
    font-size: 1rem;
    letter-spacing: 0.02em;
}
.btn-record:hover { background: #c99a2f; border-color: #c99a2f; color: #1a1a1a; }

/* Add item button */
.btn-add-item {
    background: var(--fb-green);
    border-color: var(--fb-green);
    color: #fff;
    font-weight: 600;
}
.btn-add-item:hover { background: #09331f; color: #fff; }

/* ── Mobile ── */
@media (max-width: 767.98px) {
    .page-content .container-fluid { padding: 0.6rem 0.6rem 1rem; }

    .buy-header { padding: 0.65rem 0.85rem; border-radius: 8px; justify-content: center !important; }
    .buy-header h4 { font-size: 1rem; margin-bottom: 0; text-align: center; }
    .buy-header small { font-size: 0.7rem; }
    .buy-header .btn { padding: 0.2rem 0.5rem; font-size: 0.72rem; }

    .row.g-4 { --bs-gutter-y: 0.6rem; }
    .card { margin-bottom: 0.6rem !important; border-radius: 8px; }
    .card-header { padding: 0.45rem 0.75rem; font-size: 0.82rem; }
    .card-body { padding: 0.6rem 0.75rem; }

    #customerSearch { font-size: 0.85rem; padding: 0.4rem 0.6rem; }
    .selected-customer-card { padding: 0.5rem 0.7rem; }

    .gold-item-card { padding: 0.75rem 0.75rem 0.6rem; margin-bottom: 0.5rem; border-radius: 8px; }
    .gold-item-card .item-badge { top: -9px; left: 12px; font-size: 0.65rem; padding: 0.08rem 0.5rem; }
    .gold-item-card .btn-remove-item { top: 6px; right: 6px; padding: 0.15rem 0.4rem; }
    .weight-grid { gap: 0.4rem; }
    .item-price-result { font-size: 0.78rem; padding: 0.35rem 0.6rem; margin-top: 0.5rem; }

    .summary-card .sum-body { padding: 0.7rem 0.9rem; }
    .paid-row input { width: 110px; font-size: 0.88rem; }
    .due-row .s-value { font-size: 1.05rem; }

    #btnSave { padding: 0.5rem; font-size: 0.9rem; margin-top: 0.6rem !important; }
    #noteCard { display: none !important; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid py-4">

    <!-- Page header -->
    <div class="buy-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-cash-coin me-2 d-none d-md-inline"></i>
                <span class="d-none d-md-inline">Old Gold Buy</span>
                <span class="d-md-none">OLD GOLD BUY</span>
            </h4>
            <small class="d-none d-md-inline">Purchase old / impure gold from a customer</small>
        </div>
        <a href="gold_buy_list.php" class="btn btn-outline-light btn-sm d-none d-md-inline-flex align-items-center">
            <i class="bi bi-list-ul me-1"></i> Buy History
        </a>
    </div>

    <form id="buyForm" autocomplete="off">
        <div class="row g-4">

            <!-- ── Left column ── -->
            <div class="col-lg-8">

                <!-- Customer -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-person-fill me-1 text-success"></i> Customer Search
                    </div>
                    <div class="card-body">
                        <div class="position-relative">
                            <input type="text" class="form-control" id="customerSearch"
                                   placeholder="Search by name or phone">
                            <div class="customer-results-box" id="customerResults"></div>
                        </div>
                        <input type="hidden" id="customerId" name="customer_id">

                        <div class="selected-customer-card mt-3" id="selectedCustomerCard">
                            <div>
                                <div class="fw-semibold" id="selectedCustomerName">—</div>
                                <small class="text-muted" id="selectedCustomerPhone">—</small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="btnClearCustomer">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Pure Gold Price -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-coin me-1 text-warning"></i> Pure Gold Price (24k)
                    </div>
                    <div class="card-body">
                        <div class="price-row">
                            <label>Price per Vori (BDT)</label>
                            <div class="input-group">
                                <span class="input-group-text">৳</span>
                                <input type="number" class="form-control form-control-lg fw-bold"
                                       id="pureGoldPrice" name="pure_gold_price"
                                       min="1" step="1" placeholder="e.g. 236000"
                                       style="font-size: 1.25rem;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Old Gold Items -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-semibold"><i class="bi bi-gem me-1 text-success"></i> Old Gold Items</span>
                        <button type="button" class="btn btn-sm btn-add-item" id="btnAddItem">
                            <i class="bi bi-plus-lg me-1"></i> add
                        </button>
                    </div>
                    <div class="card-body" id="itemsContainer">
                        <!-- items injected by JS -->
                    </div>
                </div>

                <!-- Note (hidden on mobile) -->
                <div class="card shadow-sm mb-4" id="noteCard">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-pencil-square me-1"></i> Note / Remarks
                    </div>
                    <div class="card-body">
                        <textarea class="form-control" id="note" name="note" rows="2"
                                  placeholder="Optional note…"></textarea>
                    </div>
                </div>
            </div>

            <!-- ── Right column: Summary ── -->
            <div class="col-lg-4">
                <div class="summary-card shadow-sm sticky-top" style="top: 1rem;">
                    <div class="sum-header">Summary</div>
                    <div class="sum-body">

                        <div class="summary-row">
                            <span class="s-label">Total Weight</span>
                            <span class="s-value" id="sumTotalWeight">0V 0A 0R 0P</span>
                        </div>

                        <div class="summary-row s-total">
                            <span class="s-label">TotalPrice:</span>
                            <span class="s-value" id="sumTotalPrice">৳0</span>
                        </div>

                        <div class="paid-row">
                            <span class="s-label">Paid Amount:</span>
                            <input type="number" id="paidAmount" name="paid_amount"
                                   min="0" step="1" value="0" placeholder="0">
                        </div>

                        <div class="due-row">
                            <span class="s-label">Due Amount:</span>
                            <span class="s-value" id="sumDueAmount">৳0</span>
                        </div>
                    </div>

                    <div class="px-3 pb-3 mt-1">
                        <button type="submit" class="btn btn-record w-100 py-2" id="btnSave">
                            <i class="bi bi-check2-circle me-1"></i> Record Buy
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
        <span class="item-badge">Item <span data-item-num></span></span>
        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item" data-remove title="Remove">
            <i class="bi bi-trash3"></i>
        </button>

        <div class="weight-grid mt-3">
            <div class="field-col">
                <label>Vori</label>
                <input type="number" min="0" step="1"
                       class="form-control form-control-sm" data-field="vori" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="vori"></div>
            </div>
            <div class="field-col">
                <label>Ana</label>
                <input type="number" min="0" max="15" step="1"
                       class="form-control form-control-sm" data-field="ana" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="ana"></div>
            </div>
            <div class="field-col">
                <label>Roti</label>
                <input type="number" min="0" max="5" step="1"
                       class="form-control form-control-sm" data-field="roti" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="roti"></div>
            </div>
            <div class="field-col">
                <label>Point</label>
                <input type="number" min="0" max="9" step="1"
                       class="form-control form-control-sm" data-field="point" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="point"></div>
            </div>
        </div>

        <div class="purity-row">
            <label>Purity (Karat)</label>
            <input type="number" min="0.01" max="24" step="0.01"
                   class="form-control form-control-sm" data-field="purity" value="24"
                   placeholder="e.g. 22, 18.5">
            <div class="invalid-feedback" data-error="purity"></div>
        </div>

        <div class="item-price-result" data-price-result>
            <span>Item Price : <strong data-price-value>৳0</strong></span>
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

function formatTrad(t) {
    return `${t.vori}V ${t.ana}A ${t.roti}R ${t.point}P`;
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
                customerResults.innerHTML = '<div class="p-2 text-danger small">Search failed.</div>';
                customerResults.style.display = 'block'; return;
            }
            const list = data.data || [];
            if (list.length === 0) {
                customerResults.innerHTML = '<div class="p-2 text-muted small">No customers found.</div>';
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
            customerResults.innerHTML = '<div class="p-2 text-danger small">Search failed.</div>';
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
    vori:  { min: 0, max: null, label: 'Vori'  },
    ana:   { min: 0, max: 15,   label: 'Ana'   },
    roti:  { min: 0, max: 5,    label: 'Roti'  },
    point: { min: 0, max: 9,    label: 'Point' },
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
        if (errEl) errEl.textContent = `${rules.label} must be a whole number.`;
        return { valid: false, value: 0 };
    }
    const n = Number(raw);
    if (!Number.isFinite(n) || !Number.isInteger(n)) {
        input.classList.add('is-invalid'); input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} must be a whole number.`;
        return { valid: false, value: 0 };
    }
    if (n < rules.min) {
        input.classList.add('is-invalid'); input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} cannot be negative.`;
        return { valid: false, value: 0 };
    }
    if (rules.max !== null && n > rules.max) {
        input.classList.add('is-invalid'); input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} max is ${rules.max}.`;
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

    // Purity
    const purityInput = card.querySelector('[data-field="purity"]');
    const purityErrEl = purityInput.parentElement.querySelector('[data-error="purity"]');
    const purity = parseFloat(purityInput.value);
    if (isNaN(purity) || purity < 0.01 || purity > 24) {
        purityInput.classList.add('is-invalid'); purityInput.classList.remove('is-valid');
        if (purityErrEl) purityErrEl.textContent = 'Purity must be 0.01 – 24.00.';
        allValid = false;
        results.purity = 24; // fallback for display
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
    const price24k = getPureGoldPrice();

    itemsContainer.querySelectorAll('[data-item]').forEach(card => {
        const v = getItemValues(card);
        const { grams, price } = calcItemPrice(v, price24k);
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
document.getElementById('buyForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    if (!customerIdInput.value) { alert('Please select a customer.'); return; }

    const price24k = getPureGoldPrice();
    if (price24k <= 0) { alert('Please enter the pure gold price (24k).'); return; }

    const items = [];
    let hasError = false;

    itemsContainer.querySelectorAll('[data-item]').forEach(card => {
        const v = getItemValues(card);
        if (!v.allValid) hasError = true;
        items.push(v);
    });

    if (items.length === 0) { alert('Add at least one gold item.'); return; }
    if (hasError)           { alert('Please fix the highlighted errors before saving.'); return; }

    for (const it of items) {
        if (traditionalToGrams(it.vori, it.ana, it.roti, it.point) <= 0) {
            alert('Each item must have a weight greater than zero.');
            return;
        }
    }

    const paid = Math.max(0, parseFloat(paidAmountInput.value) || 0);

    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

    try {
        const fd = new FormData();
        fd.append('action',          'save');
        fd.append('customer_id',     customerIdInput.value);
        fd.append('pure_gold_price', price24k);
        fd.append('paid_amount',     paid);
        fd.append('note',            document.getElementById('note').value);
        fd.append('items',           JSON.stringify(items));

        const res  = await fetch('gold_buy.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        });
        const data = await res.json();

        if (data.success) {
            window.location.href = 'gold_buy_list.php';
        } else {
            alert(data.message || 'Failed to save buy.');
        }
    } catch {
        alert('Network error. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Record Buy';
    }
});
</script>

</body>
</html>