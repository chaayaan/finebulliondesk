<?php
/**
 * gold_exchange.php
 * FineBullion Desk — Gold Exchange (create new exchange)
 *
 * Business rules:
 *   - Old gold weight is entered in traditional units — Vori, Ana, Roti,
 *     Point — each a WHOLE NUMBER (no decimals). Boundaries:
 *       Vori  >= 0, no upper limit
 *       Ana   0–15
 *       Roti  0–5
 *       Point 0–9
 *     (10 Point = 1 Roti, 6 Roti = 1 Ana, 16 Ana = 1 Vori — so a value at
 *     the top of its range, e.g. 9 Point, never itself completes a full
 *     unit of the level above; it stays a remainder.)
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

/**
 * Convert traditional Vori/Ana/Roti/Point input into grams.
 * Vori/Ana/Roti/Point are expected to already be validated whole numbers
 * within their boundaries (Vori >= 0, Ana 0–15, Roti 0–5, Point 0–9).
 */
function traditional_to_grams(int $vori, int $ana, int $roti, int $point): float
{
    return ($vori * G_PER_VORI)
         + ($ana  * G_PER_ANA)
         + ($roti * G_PER_ROTI)
         + ($point * G_PER_POINT);
}

/**
 * Convert a gram value back into whole Vori / Ana / Roti / Point for
 * display. Nesting is exact: 16 Ana = 1 Vori, 6 Roti = 1 Ana,
 * 10 Point = 1 Roti — so this is plain base conversion, no ambiguity.
 */
function grams_to_traditional(float $grams): array
{
    $totalVori = $grams / G_PER_VORI;

    $vori = (int) floor($totalVori + 1e-9); // tiny epsilon guards float noise
    $fracVori = max(0.0, $totalVori - $vori);

    $totalAna = $fracVori * 16;
    $ana = (int) floor($totalAna + 1e-9);
    $fracAna = max(0.0, $totalAna - $ana);

    $totalRoti = $fracAna * 6;
    $roti = (int) floor($totalRoti + 1e-9);
    $fracRoti = max(0.0, $totalRoti - $roti);

    $point = (int) round($fracRoti * 10);

    // Rounding carry: 10 Point -> 1 Roti -> possibly 6 Roti -> 1 Ana -> possibly 16 Ana -> 1 Vori
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
    return "{$t['vori']} Vori {$t['ana']} Ana {$t['roti']} Roti {$t['point']} Point";
}

// -----------------------------------------------------------------------
// AJAX actions
// -----------------------------------------------------------------------

if ($isAjax || $action !== null) {

    // ---- Customer quick lookup (used after selecting from autocomplete) ----
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

    // ---- SAVE exchange ---------------------------------------------------
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $note       = trim($_POST['note'] ?? '') ?: null;
        $items      = json_decode($_POST['items'] ?? '[]', true);
        $lossRate   = isset($_POST['loss_rate']) && $_POST['loss_rate'] !== ''
                        ? (float)$_POST['loss_rate']
                        : 1.0;

        if ($customerId <= 0) {
            json_out(['success' => false, 'message' => 'Please select a customer.'], 422);
        }
        if (!is_array($items) || count($items) === 0) {
            json_out(['success' => false, 'message' => 'Add at least one gold item.'], 422);
        }
        if ($lossRate < 0) {
            json_out(['success' => false, 'message' => 'Loss rate cannot be negative.'], 422);
        }

        // Verify customer exists
        $cstmt = mysqli_prepare($conn, "SELECT id FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($cstmt, 'i', $customerId);
        mysqli_stmt_execute($cstmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($cstmt))) {
            json_out(['success' => false, 'message' => 'Selected customer does not exist.'], 404);
        }

        // Validate & calculate each item server-side (never trust client math)
        $calcItems = [];
        $totalPureGrams = 0.0;

        foreach ($items as $i => $item) {
            $n = $i + 1;

            // Vori/Ana/Roti/Point must be whole numbers within their
            // boundaries. Reject anything that isn't a clean integer
            // (covers stray floats/decimals slipping through from the client).
            foreach (['vori', 'ana', 'roti', 'point'] as $field) {
                $raw = $item[$field] ?? 0;
                if (!is_numeric($raw) || (float)$raw != (int)$raw) {
                    json_out(['success' => false, 'message' => "Item $n: " . ucfirst($field) . " must be a whole number."], 422);
                }
            }

            $vori  = (int)($item['vori']  ?? 0);
            $ana   = (int)($item['ana']   ?? 0);
            $roti  = (int)($item['roti']  ?? 0);
            $point = (int)($item['point'] ?? 0);
            $karat = (float)($item['karat'] ?? 0);

            if ($vori < 0) {
                json_out(['success' => false, 'message' => "Item $n: Vori cannot be negative."], 422);
            }
            if ($ana < 0 || $ana > 15) {
                json_out(['success' => false, 'message' => "Item $n: Ana must be between 0 and 15."], 422);
            }
            if ($roti < 0 || $roti > 5) {
                json_out(['success' => false, 'message' => "Item $n: Roti must be between 0 and 5."], 422);
            }
            if ($point < 0 || $point > 9) {
                json_out(['success' => false, 'message' => "Item $n: Point must be between 0 and 9."], 422);
            }
            if ($karat < 0.01 || $karat > 24) {
                json_out(['success' => false, 'message' => "Item $n: Karat must be between 0.01 and 24.00."], 422);
            }

            $grams = traditional_to_grams($vori, $ana, $roti, $point);
            if ($grams <= 0) {
                json_out(['success' => false, 'message' => "Item $n: weight must be greater than zero."], 422);
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

        // Loss = ceil(Points of loss per Vori of TOTAL pure gold).
        // e.g. 18.6 Points → ceiled to 19 Points before converting to grams.
        $totalPureVori   = $totalPureGrams / G_PER_VORI;
        $lossPointsExact = $totalPureVori * $lossRate;
        $lossPointsCeil  = (int) ceil($lossPointsExact);   // ← ceil
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

            mysqli_commit($conn);
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            json_out(['success' => false, 'message' => 'Failed to save exchange. Please try again.'], 500);
        }

        json_out([
            'success' => true,
            'message' => 'Exchange saved.',
            'id'      => $exchangeId,
            'summary' => [
                'total_pure_gold' => $totalPureGrams,
                'loss'             => $lossGrams,
                'final_pure_gold'  => $finalPureGrams,
                'loss_rate'        => $lossRate,
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
<title>Gold Exchange — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --fb-green: #0B412A;
    --fb-gold: #DCAD41;
}
body { background: #f5f6fa; font-family: "Segoe UI", Arial, sans-serif; }

.exchange-header {
    background: linear-gradient(135deg, var(--fb-green) 0%, #0e5636 100%);
    color: #fff;
    border-radius: 10px;
    padding: 1.25rem 1.5rem;
}
.exchange-header small { color: rgba(255,255,255,0.75); }

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

.selected-customer-card {
    border: 1px solid var(--fb-gold);
    background: #fdf8ec;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    display: none;
    align-items: center;
    justify-content: space-between;
}

.gold-item-card {
    border: 1px solid #e2e5ea;
    border-radius: 10px;
    padding: 1rem 1.1rem;
    margin-bottom: 1rem;
    background: #fff;
    position: relative;
}
.gold-item-card .item-index {
    position: absolute;
    top: -10px;
    left: 14px;
    background: var(--fb-green);
    color: #fff;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 0.1rem 0.6rem;
    border-radius: 10px;
}
.gold-item-card .btn-remove-item {
    position: absolute;
    top: 10px;
    right: 10px;
}
.item-pure-result {
    background: #f4f9f6;
    border: 1px dashed #bcd9c9;
    border-radius: 8px;
    padding: 0.5rem 0.8rem;
    font-size: 0.88rem;
    color: var(--fb-green);
    font-weight: 600;
}

.summary-card {
    background: var(--fb-green);
    color: #fff;
    border-radius: 12px;
    padding: 1.4rem 1.6rem;
}
.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 0.45rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.12);
}
.summary-row:last-child { border-bottom: none; }
.summary-row .label { color: rgba(255,255,255,0.75); font-size: 0.85rem; }
.summary-row .value { font-weight: 700; font-size: 1.05rem; }
.summary-row.final .value { color: var(--fb-gold); font-size: 1.35rem; }
.summary-row .sub { font-size: 0.75rem; color: rgba(255,255,255,0.6); display:block; }
.loss-rate-input {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.25);
    color: #fff;
    text-align: right;
}
.loss-rate-input:focus {
    background: rgba(255,255,255,0.18);
    border-color: var(--fb-gold);
    color: #fff;
    box-shadow: 0 0 0 0.15rem rgba(220,173,65,0.35);
}

.btn-gold {
    background: var(--fb-gold);
    border-color: var(--fb-gold);
    color: #1a1a1a;
    font-weight: 600;
}
.btn-gold:hover { background: #c99a2f; border-color: #c99a2f; color: #1a1a1a; }

/* ---------------------------------------------------------------
   Item fields — Vori / Ana / Roti / Point always in ONE row
   (4 equal columns), Karat as its own full-width row underneath.
   This mirrors the compact mobile layout regardless of card width.
--------------------------------------------------------------- */
.item-fields-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.5rem;
}
.item-fields-row .field-col label {
    display: block;
    font-size: 0.72rem;
    margin-bottom: 0.15rem;
    color: #6c757d;
    white-space: nowrap;
}
.item-fields-row .field-col input {
    text-align: center;
    padding-left: 0.25rem;
    padding-right: 0.25rem;
}
/* Remove Bootstrap's built-in valid/invalid checkmark & X icons on weight inputs */
.item-fields-row input.form-control.is-valid,
.item-fields-row input.form-control.is-invalid,
.karat-row input.form-control.is-valid,
.karat-row input.form-control.is-invalid {
    background-image: none !important;
    padding-right: 0.25rem !important;
}
.karat-row {
    margin-top: 0.6rem;
}
.karat-row label {
    display: block;
    font-size: 0.72rem;
    margin-bottom: 0.15rem;
    color: #6c757d;
}

/* ---------------------------------------------------------------
   Mobile compaction — keep the whole form (header, customer,
   one gold item, summary, save button) visible without scrolling
   on a typical phone viewport for a single item.
--------------------------------------------------------------- */
@media (max-width: 767.98px) {
    .page-content .container-fluid { padding: 0.6rem 0.6rem 1rem; }

    .exchange-header { padding: 0.65rem 0.85rem; border-radius: 8px; justify-content: center !important; }
    .exchange-header h4 { font-size: 1rem; margin-bottom: 0; text-align: center; }
    .exchange-header small { font-size: 0.7rem; }
    .exchange-header .btn { padding: 0.2rem 0.5rem; font-size: 0.72rem; }

    .row.g-4 { --bs-gutter-y: 0.6rem; }

    .card { margin-bottom: 0.6rem !important; border-radius: 8px; }
    .card-header { padding: 0.45rem 0.75rem; font-size: 0.82rem; }
    .card-body { padding: 0.6rem 0.75rem; }

    #customerSearch { font-size: 0.85rem; padding: 0.4rem 0.6rem; }
    .selected-customer-card { padding: 0.5rem 0.7rem; }

    .gold-item-card { padding: 0.75rem 0.75rem 0.6rem; margin-bottom: 0; border-radius: 8px; }
    .gold-item-card .item-index { top: -9px; left: 12px; font-size: 0.65rem; padding: 0.08rem 0.5rem; }
    .gold-item-card .btn-remove-item { top: 6px; right: 6px; padding: 0.15rem 0.4rem; }
    .gold-item-card .form-control-sm { font-size: 0.82rem; padding: 0.28rem 0.4rem; }
    .item-fields-row { gap: 0.4rem; }
    .item-pure-result { padding: 0.35rem 0.6rem; font-size: 0.76rem; margin-top: 0.5rem !important; }

    #note { min-height: 44px; }

    .summary-card { padding: 0.75rem 0.9rem; border-radius: 10px; }
    .summary-card h6 { font-size: 0.72rem; margin-bottom: 0.5rem !important; }
    .summary-row { padding: 0.3rem 0; }
    .summary-row .label { font-size: 0.75rem; }
    .summary-row .value { font-size: 0.88rem; }
    .summary-row.final .value { font-size: 1.05rem; }
    .summary-row .sub { font-size: 0.68rem; }
    .loss-rate-input { width: 55px !important; padding: 0.2rem 0.35rem; }

    #btnSave { padding: 0.5rem; font-size: 0.9rem; margin-top: 0.6rem !important; }

    /* Note / Remarks is skipped entirely on mobile */
    #noteCard { display: none !important; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid py-4">

    <div class="exchange-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-arrow-left-right me-2 d-none d-md-inline"></i>
                <span class="d-none d-md-inline">New Gold Exchange</span>
                <span class="d-md-none">Gold Exchange</span>
            </h4>
            <small class="d-none d-md-inline">Convert old / impure gold into pure gold for a customer</small>
        </div>
        <a href="gold_exchange_list.php" class="btn btn-outline-light btn-sm d-none d-md-inline-flex align-items-center">
            <i class="bi bi-list-ul me-1"></i> Exchange History
        </a>
    </div>

    <form id="exchangeForm" autocomplete="off">
        <div class="row g-4">
            <div class="col-lg-8">

                <!-- Customer -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-person-fill me-1"></i> Customer
                    </div>
                    <div class="card-body">
                        <div class="position-relative">
                            <input type="text" class="form-control" id="customerSearch"
                                   placeholder="Search customer by name or phone…">
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

                <!-- Old gold items -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-semibold"><i class="bi bi-gem me-1"></i> Old / Impure Gold Items</span>
                        <button type="button" class="btn btn-sm btn-gold" id="btnAddItem">
                            <i class="bi bi-plus-lg me-1"></i> Add Item
                        </button>
                    </div>
                    <div class="card-body" id="itemsContainer">
                        <!-- items injected by JS -->
                    </div>
                </div>

                <!-- Note -->
                <div class="card shadow-sm mb-4" id="noteCard">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-pencil-square me-1"></i> Note / Remarks
                    </div>
                    <div class="card-body">
                        <textarea class="form-control" id="note" name="note" rows="2" placeholder="Optional note…"></textarea>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="summary-card shadow-sm sticky-top" style="top: 1rem;">
                    <h6 class="mb-3 text-uppercase" style="letter-spacing:0.05em; color: rgba(255,255,255,0.8);">
                        Exchange Summary
                    </h6>

                    <div class="summary-row">
                        <span class="label">Total Pure Gold</span>
                        <span class="value" id="sumTotalPure">0 Vori 0 Ana 0 Roti 0 Point</span>
                    </div>

                    <div class="summary-row align-items-center">
                        <span class="label">Loss rate</span>
                        <span class="d-flex align-items-center gap-1">
                            <input type="number" id="lossRateInput" min="0" step="0.001" value="1"
                                   class="form-control form-control-sm loss-rate-input" style="width:70px;">
                            <small style="color: rgba(255,255,255,0.7);">Pt / Vori</small>
                        </span>
                    </div>

                    <div class="summary-row">
                        <span class="label">Loss</span>
                        <span class="value" id="sumLoss">0 Point</span>
                    </div>
                    <div class="summary-row">
                        <span class="sub" id="sumLossTrad" style="color: rgba(255,255,255,0.6); font-size:0.75rem;">0 Vori 0 Ana 0 Roti 0 Point</span>
                    </div>

                    <div class="summary-row final">
                        <span class="label">Final Pure Gold</span>
                        <span class="value" id="sumFinalPure">0 Vori 0 Ana 0 Roti 0 Point</span>
                    </div>

                    <button type="submit" class="btn btn-gold w-100 mt-3" id="btnSave">
                        <i class="bi bi-save-fill me-1"></i> Save Exchange
                    </button>
                </div>
            </div>
        </div>
    </form>

</div>
</div>

<template id="itemTemplate">
    <div class="gold-item-card" data-item>
        <span class="item-index">Item <span data-item-num></span></span>
        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item" data-remove>
            <i class="bi bi-trash3"></i>
        </button>
        <div class="item-fields-row mt-2">
            <div class="field-col">
                <label>Vori</label>
                <input type="number" min="0" step="1" class="form-control form-control-sm" data-field="vori" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="vori"></div>
            </div>
            <div class="field-col">
                <label>Ana</label>
                <input type="number" min="0" max="15" step="1" class="form-control form-control-sm" data-field="ana" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="ana"></div>
            </div>
            <div class="field-col">
                <label>Roti</label>
                <input type="number" min="0" max="5" step="1" class="form-control form-control-sm" data-field="roti" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="roti"></div>
            </div>
            <div class="field-col">
                <label>Point</label>
                <input type="number" min="0" max="9" step="1" class="form-control form-control-sm" data-field="point" value="0" inputmode="numeric">
                <div class="invalid-feedback" data-error="point"></div>
            </div>
        </div>
        <div class="karat-row">
            <label>Karat</label>
            <input type="number" min="0.01" max="24" step="0.01" class="form-control form-control-sm" data-field="karat" value="22" placeholder="e.g. 19.00">
            <div class="invalid-feedback" data-error="karat"></div>
        </div>
        <div class="item-pure-result mt-2" data-pure-result>
            Pure Gold: 0 Vori 0 Ana 0 Roti 0 Point
        </div>
    </div>
</template>

<script>
const G_PER_VORI  = 11.664;
const G_PER_ANA   = 0.729;    // 1 Vori / 16
const G_PER_ROTI  = 0.1215;   // 1 Ana / 6
const G_PER_POINT = 0.01215;  // 1 Roti / 10

function traditionalToGrams(vori, ana, roti, point) {
    return (vori * G_PER_VORI) + (ana * G_PER_ANA) + (roti * G_PER_ROTI) + (point * G_PER_POINT);
}

// Convert grams back to Vori/Ana/Roti/Point for display only.
// Nesting is exact: 16 Ana = 1 Vori, 6 Roti = 1 Ana, 10 Point = 1 Roti.
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
    return `${t.vori} Vori ${t.ana} Ana ${t.roti} Roti ${t.point} Point`;
}

// ---------------------------------------------------------------
// Customer search
// ---------------------------------------------------------------
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
                customerResults.innerHTML = '<div class="p-2 text-danger small">Search failed.</div>';
                customerResults.style.display = 'block';
                return;
            }

            const list = data.data || [];

            if (list.length === 0) {
                customerResults.innerHTML = '<div class="p-2 text-muted small">No customers found.</div>';
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
                        <small class="text-muted">${escHtml(c.phone)}${c.address ? ' · ' + escHtml(c.address) : ''}</small>
                    </div>
                </div>
            `).join('');
            customerResults.style.display = 'block';
        } catch (err) {
            customerResults.innerHTML = '<div class="p-2 text-danger small">Search failed.</div>';
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

// ---------------------------------------------------------------
// Gold items
// ---------------------------------------------------------------
const itemsContainer = document.getElementById('itemsContainer');
const itemTemplate    = document.getElementById('itemTemplate');
let itemCounter = 0;

// Validation rules for each traditional-unit field
const FIELD_RULES = {
    vori:  { min: 0,    max: null, label: 'Vori'  },
    ana:   { min: 0,    max: 15,   label: 'Ana'   },
    roti:  { min: 0,    max: 5,    label: 'Roti'  },
    point: { min: 0,    max: 9,    label: 'Point' },
};

/**
 * Validate a single traditional-unit input in real time.
 * Returns { valid, value } where value is the integer (or 0 on error).
 * Marks the input is-invalid / is-valid and fills the error span.
 */
function validateField(input, field) {
    const raw   = input.value;
    const rules = FIELD_RULES[field];
    const errEl = input.parentElement.querySelector(`[data-error="${field}"]`);

    // Empty → treat as 0, no error shown while typing
    if (raw === '' || raw === null) {
        input.classList.remove('is-invalid', 'is-valid');
        if (errEl) errEl.textContent = '';
        return { valid: true, value: 0 };
    }

    // Reject decimals: if the string contains a dot/comma it is a float
    if (/[.,]/.test(raw)) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} must be a whole number (no decimals).`;
        return { valid: false, value: 0 };
    }

    const n = Number(raw);

    // Must be a finite integer
    if (!Number.isFinite(n) || !Number.isInteger(n)) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} must be a whole number.`;
        return { valid: false, value: 0 };
    }

    // Range check
    if (n < rules.min) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} cannot be negative.`;
        return { valid: false, value: 0 };
    }
    if (rules.max !== null && n > rules.max) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} max is ${rules.max}.`;
        return { valid: false, value: rules.max };
    }

    // Valid
    input.classList.remove('is-invalid');
    input.classList.add('is-valid');
    if (errEl) errEl.textContent = '';
    return { valid: true, value: n };
}

function addItem() {
    itemCounter++;
    const node = itemTemplate.content.cloneNode(true);
    const card = node.querySelector('[data-item]');
    card.dataset.itemId = itemCounter;
    node.querySelector('[data-item-num]').textContent = itemCounter;

    // Attach real-time validation + render to every input
    card.querySelectorAll('input').forEach(el => {
        el.addEventListener('input', () => { renderItem(card); renderSummary(); });
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

/**
 * Read and validate all fields from a card.
 * Returns { allValid, vori, ana, roti, point, karat }
 * For display/calculation, uses the integer value even when invalid
 * (so the result display can still update live — errors are shown in the field).
 */
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
        if (karatErrEl) karatErrEl.textContent = 'Karat must be 0.01 – 24.00.';
        allValid = false;
        results.karat = 22; // fallback for display
    } else {
        karatInput.classList.remove('is-invalid');
        karatInput.classList.add('is-valid');
        if (karatErrEl) karatErrEl.textContent = '';
        results.karat = karat;
    }

    results.allValid = allValid;
    return results;
}

function renderItem(card) {
    const v = getItemValues(card);
    const grams    = traditionalToGrams(v.vori, v.ana, v.roti, v.point);
    const pureGrams = grams * (v.karat / 24);
    const pureTrad  = gramsToTraditional(pureGrams);
    const oldTrad   = { vori: v.vori, ana: v.ana, roti: v.roti, point: v.point };

    card.querySelector('[data-pure-result]').innerHTML =
        `<span>Pure Gold: <strong>${formatTraditional(pureTrad)}</strong></span>`;
}

document.getElementById('btnAddItem').addEventListener('click', addItem);

// ---------------------------------------------------------------
// Summary calculation
// ---------------------------------------------------------------
const lossRateInput = document.getElementById('lossRateInput');
lossRateInput.addEventListener('input', renderSummary);

function getLossRate() {
    const n = parseFloat(lossRateInput.value);
    return (isNaN(n) || n < 0) ? 0 : n;
}

function renderSummary() {
    let totalPureGrams = 0;

    itemsContainer.querySelectorAll('[data-item]').forEach(card => {
        const v = getItemValues(card);
        const grams = traditionalToGrams(v.vori, v.ana, v.roti, v.point);
        totalPureGrams += grams * (v.karat / 24);
    });

    // Loss points = ceil(total_pure_vori * loss_rate)
    // e.g. 18.6 Points → ceiled to 19 Points before converting to grams
    const lossRate        = getLossRate();
    const totalPureVori   = totalPureGrams / G_PER_VORI;
    const lossPointsExact = totalPureVori * lossRate;
    const lossPointsCeil  = Math.ceil(lossPointsExact);   // ← ceil here
    const lossGrams       = lossPointsCeil * G_PER_POINT;

    let finalPureGrams = totalPureGrams - lossGrams;
    if (finalPureGrams < 0) finalPureGrams = 0;

    const totalTrad = gramsToTraditional(totalPureGrams);
    const finalTrad = gramsToTraditional(finalPureGrams);
    const lossTrad  = gramsToTraditional(lossGrams);

    document.getElementById('sumTotalPure').textContent = formatTraditional(totalTrad);

    // Loss shown as ceiled point count, then traditional breakdown
    document.getElementById('sumLoss').textContent = lossPointsCeil + ' Point  (@ ' + lossRate + ' Pt/Vori)';
    document.getElementById('sumLossTrad').textContent = formatTraditional(lossTrad);

    document.getElementById('sumFinalPure').textContent = formatTraditional(finalTrad);
}

// Start with one item row
addItem();

// ---------------------------------------------------------------
// Save
// ---------------------------------------------------------------
document.getElementById('exchangeForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    if (!customerIdInput.value) {
        alert('Please select a customer.');
        return;
    }

    const items = [];
    let hasValidationError = false;

    itemsContainer.querySelectorAll('[data-item]').forEach(card => {
        const v = getItemValues(card);
        if (!v.allValid) hasValidationError = true;
        items.push(v);
    });

    if (items.length === 0) {
        alert('Add at least one gold item.');
        return;
    }

    if (hasValidationError) {
        alert('Please fix the highlighted errors before saving.');
        return;
    }

    for (const it of items) {
        const grams = traditionalToGrams(it.vori, it.ana, it.roti, it.point);
        if (grams <= 0) {
            alert('Each item must have a weight greater than zero.');
            return;
        }
    }

    const lossRate = getLossRate();

    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

    try {
        const formData = new FormData();
        formData.append('action', 'save');
        formData.append('customer_id', customerIdInput.value);
        formData.append('note', document.getElementById('note').value);
        formData.append('items', JSON.stringify(items));
        formData.append('loss_rate', lossRate);

        const res = await fetch('gold_exchange.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            window.location.href = 'gold_exchange_list.php';
        } else {
            alert(data.message || 'Failed to save exchange.');
        }
    } catch (err) {
        alert('Network error. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-save-fill me-1"></i> Save Exchange';
    }
});
</script>

</body>
</html>