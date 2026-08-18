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
    return "{$t['vori']} ভ {$t['ana']} আ {$t['roti']} র {$t['point']} প";
}

// -----------------------------------------------------------------------
// AJAX actions
// -----------------------------------------------------------------------

if ($isAjax || $action !== null) {

    if ($action === 'customer' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'অকার্যকর কাস্টমার আইডি।'], 400);

        $stmt = mysqli_prepare($conn, "SELECT id, name, phone FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$row) json_out(['success' => false, 'message' => 'কাস্টমার পাওয়া যায়নি।'], 404);
        json_out(['success' => true, 'data' => $row]);
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $note       = trim($_POST['note'] ?? '') ?: null;
        $items      = json_decode($_POST['items'] ?? '[]', true);
        $lossRate   = isset($_POST['loss_rate']) && $_POST['loss_rate'] !== ''
                        ? (float)$_POST['loss_rate']
                        : 1.0;

        if ($customerId <= 0) {
            json_out(['success' => false, 'message' => 'অনুগ্রহ করে একজন কাস্টমার নির্বাচন করুন।'], 422);
        }
        if (!is_array($items) || count($items) === 0) {
            json_out(['success' => false, 'message' => 'কমপক্ষে একটি সোনার আইটেম যোগ করুন।'], 422);
        }
        if ($lossRate < 0) {
            json_out(['success' => false, 'message' => 'লসের হার ঋণাত্মক হতে পারে না।'], 422);
        }

        $cstmt = mysqli_prepare($conn, "SELECT id FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($cstmt, 'i', $customerId);
        mysqli_stmt_execute($cstmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($cstmt))) {
            json_out(['success' => false, 'message' => 'নির্বাচিত কাস্টমার অস্তিত্বহীন।'], 404);
        }

        $calcItems = [];
        $totalPureGrams = 0.0;

        foreach ($items as $i => $item) {
            $n = $i + 1;

            foreach (['vori', 'ana', 'roti', 'point'] as $field) {
                $raw = $item[$field] ?? 0;
                if (!is_numeric($raw) || (float)$raw != (int)$raw) {
                    json_out(['success' => false, 'message' => "আইটেম $n: " . ucfirst($field) . " পূর্ণসংখ্যা হতে হবে।"], 422);
                }
            }

            $vori  = (int)($item['vori']  ?? 0);
            $ana   = (int)($item['ana']   ?? 0);
            $roti  = (int)($item['roti']  ?? 0);
            $point = (int)($item['point'] ?? 0);
            $karat = (float)($item['karat'] ?? 0);

            if ($vori < 0) {
                json_out(['success' => false, 'message' => "আইটেম $n: ভরি ঋণাত্মক হতে পারে না।"], 422);
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

            mysqli_commit($conn);
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            json_out(['success' => false, 'message' => 'সোনা বদল সংরক্ষণ করতে ব্যর্থ হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।'], 500);
        }

        json_out([
            'success' => true,
            'message' => 'সোনা বদল সংরক্ষিত হয়েছে।',
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
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>সোনা এক্সচেঞ্জ — ফাইনবুলিয়ন ডেস্ক</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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

/* Global Reset to eliminate top spacing entirely */
html, body {
    margin: 0 !important;
    padding: 0 !important;
    background: var(--ivory);
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--bronze-text);
}

.page-content {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

.page-content > .container-fluid:first-child {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

/* ---------------------------------------------------------------
   Page-Specific Header (.ge-header) — scoped strictly to .ge-header
   and its children so this block is completely self-contained and
   immune to overrides from navbar.php or any external stylesheet.
--------------------------------------------------------------- */
.ge-header,
.ge-header.d-flex {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%) !important;
    color: #ffffff !important;
    min-height: 60px !important;
    max-height: 80px !important;
    padding: 0.85rem 1.75rem !important;
    margin: 0 0 1.5rem 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    position: relative;
    top: 0;
    border-top-left-radius: 0 !important;
    border-top-right-radius: 0 !important;
    border-bottom-left-radius: 20px !important;
    border-bottom-right-radius: 20px !important;
    box-shadow: 0 6px 24px rgba(201, 151, 58, 0.22);
    box-sizing: border-box;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: nowrap !important;
    gap: 1rem !important;
    overflow: hidden;
}

.ge-header h4 {
    color: #ffffff !important;
    font-weight: 800;
    letter-spacing: 0.02em;
    margin: 0 !important;
    text-align: left !important;
    font-size: 1.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ge-header i {
    color: #ffffff !important;
}

.ge-header .btn-history {
    border-color: rgba(255, 255, 255, 0.6);
    color: #ffffff;
    border-radius: 999px;
    font-weight: 600;
    flex-shrink: 0;
}

.ge-header .btn-history:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
}

.page-inset { padding: 0 1.5rem; }

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

.customer-results-box {
    position: absolute;
    z-index: 20;
    background: #fff;
    border: 1px solid var(--hairline);
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(180, 140, 50, 0.18);
    width: 100%;
    max-height: 260px;
    overflow-y: auto;
    display: none;
}

.selected-customer-card {
    border: 1.5px solid var(--gold-deep);
    background: var(--status-total-light);
    border-radius: 14px;
    padding: 0.75rem 1rem;
    display: none;
    align-items: center;
    justify-content: space-between;
}

.gold-item-card {
    border: 1.5px solid var(--hairline);
    border-radius: 18px;
    padding: 1rem 1.1rem;
    margin-bottom: 1rem;
    background: #fff;
    position: relative;
    box-shadow: 0 10px 30px rgba(180, 140, 50, 0.08);
}
.gold-item-card .item-index {
    position: absolute;
    top: -10px;
    left: 14px;
    background: var(--gold-deep);
    color: #fff;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 0.1rem 0.6rem;
    border-radius: 999px;
}
.gold-item-card .btn-remove-item {
    position: absolute;
    top: 10px;
    right: 10px;
    border-radius: 999px;
    border: 1.5px solid var(--hairline);
    background: #fff;
    color: var(--status-due-bg);
}
.gold-item-card .btn-remove-item:hover { background: var(--status-due-light); }
.item-pure-result {
    background: var(--status-paid-light);
    border: 1px dashed var(--status-paid-bg);
    border-radius: 10px;
    padding: 0.5rem 0.8rem;
    font-size: 0.88rem;
    color: var(--status-paid-bg);
    font-weight: 600;
}

.summary-card {
    background: var(--status-total-bg);
    color: #fff;
    border-radius: 18px;
    padding: 1.4rem 1.6rem;
    box-shadow: 0 10px 30px rgba(180, 140, 50, 0.20);
}
.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 0.45rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.18);
}
.summary-row:last-child { border-bottom: none; }
.summary-row .label { color: rgba(255,255,255,0.8); font-size: 0.85rem; }
.summary-row .value { font-weight: 700; font-size: 1.05rem; }
.summary-row.final .value { color: #ffffff; font-size: 1.35rem; }
.summary-row .sub { font-size: 0.75rem; color: rgba(255,255,255,0.65); display:block; }
.loss-rate-input {
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.35);
    color: #fff;
    text-align: right;
    border-radius: 10px;
}
.loss-rate-input:focus {
    background: rgba(255,255,255,0.22);
    border-color: #ffffff;
    color: #fff;
    box-shadow: 0 0 0 0.15rem rgba(255,255,255,0.25);
}

.btn-gold, .btn-fb-primary {
    background: var(--gold-deep);
    border: 1.5px solid var(--gold-deep);
    color: #ffffff;
    font-weight: 700;
    border-radius: 999px;
}
.btn-gold:hover, .btn-gold:focus,
.btn-fb-primary:hover, .btn-fb-primary:focus { background: var(--gold-deep); border-color: var(--gold-deep); color: #ffffff; opacity: 0.92; }

.btn-fb-secondary {
    background: #ffffff;
    border: 1.5px solid var(--hairline);
    color: var(--muted);
    font-weight: 600;
    border-radius: 999px;
}
.btn-fb-secondary:hover { background: #fdf7ec; border-color: var(--hairline); color: var(--bronze-text); }

.btn-outline-danger {
    border-radius: 999px !important;
    border: 1.5px solid var(--hairline) !important;
    color: var(--status-due-bg) !important;
    background: #ffffff !important;
}
.btn-outline-danger:hover {
    background: var(--status-due-light) !important;
    border-color: var(--status-due-bg) !important;
    color: var(--status-due-bg) !important;
}

.card {
    background: #ffffff;
    border: none;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(180, 140, 50, 0.12);
}
.card-header {
    background: var(--ivory) !important;
    border-bottom: 1px solid var(--hairline);
    border-radius: 18px 18px 0 0 !important;
    color: var(--bronze-text);
}

.form-control {
    border: 1.5px solid var(--hairline);
    border-radius: 10px;
    color: var(--bronze-text);
    background: #ffffff;
}
.form-control:focus {
    border-color: var(--gold-deep);
    box-shadow: 0 0 0 0.15rem rgba(201, 151, 58, 0.18);
}

.item-fields-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.5rem;
}
.item-fields-row .field-col label {
    display: block;
    font-size: 0.72rem;
    margin-bottom: 0.15rem;
    color: var(--muted);
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
.karat-row {
    margin-top: 0.6rem;
}
.karat-row label {
    display: block;
    font-size: 0.72rem;
    margin-bottom: 0.15rem;
    color: var(--muted);
}

@media (max-width: 767.98px) {
    .page-content .container-fluid { padding: 0 !important; }
    .page-inset { padding: 0 0.8rem; }

    .ge-header { 
        min-height: 60px !important;
        max-height: 70px !important;
        padding: 0.75rem 1rem !important; 
        border-radius: 0 0 16px 16px !important;
        margin-bottom: 0.8rem !important;
    }
    .ge-header h4 { font-size: 0.95rem !important; }
    .ge-header .btn-history { padding: 0.22rem 0.55rem !important; font-size: 0.72rem !important; }

    .row.g-4 { --bs-gutter-y: 0.6rem; }

    .card { margin-bottom: 0.6rem !important; border-radius: 14px; }
    .card-header { padding: 0.45rem 0.75rem; font-size: 0.82rem; border-radius: 14px 14px 0 0 !important; }
    .card-body { padding: 0.6rem 0.75rem; }

    #customerSearch { font-size: 0.85rem; padding: 0.4rem 0.6rem; }
    .selected-customer-card { padding: 0.5rem 0.7rem; }

    .gold-item-card { padding: 0.75rem 0.75rem 0.6rem; margin-bottom: 0; border-radius: 14px; }
    .gold-item-card .item-index { top: -9px; left: 12px; font-size: 0.65rem; padding: 0.08rem 0.5rem; }
    .gold-item-card .btn-remove-item { top: 6px; right: 6px; padding: 0.15rem 0.4rem; }
    .gold-item-card .form-control-sm { font-size: 0.82rem; padding: 0.28rem 0.4rem; }
    .item-fields-row { gap: 0.4rem; }
    .item-pure-result { padding: 0.35rem 0.6rem; font-size: 0.76rem; margin-top: 0.5rem !important; }

    #note { min-height: 44px; }

    .summary-card { padding: 0.75rem 0.9rem; border-radius: 14px; }
    .summary-card h6 { font-size: 0.72rem; margin-bottom: 0.5rem !important; }
    .summary-row { padding: 0.3rem 0; }
    .summary-row .label { font-size: 0.75rem; }
    .summary-row .value { font-size: 0.88rem; }
    .summary-row.final .value { font-size: 1.05rem; }
    .summary-row .sub { font-size: 0.68rem; }
    .loss-rate-input { width: 55px !important; padding: 0.2rem 0.35rem; }

    #btnSave { padding: 0.5rem; font-size: 0.9rem; margin-top: 0.6rem !important; }
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
            <h4 class="m-0 text-white fw-bold">সোনা এক্সচেঞ্জ</h4>
        </div>
        <div>
            <a href="gold_exchange_list.php" class="btn btn-outline-light btn-history btn-sm d-inline-flex align-items-center gap-1">
                <i class="bi bi-list-ul"></i>
                <span>এক্সচেঞ্জ হিস্ট্রি</span>
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
                        <i class="bi bi-person-fill me-1"></i> কাস্টমার
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
                                <small class="text-muted" id="selectedCustomerPhone">—</small>
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
                        <span class="fw-semibold"><i class="bi bi-gem me-1"></i> পুরাতন সোনার আইটেম</span>
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
                        <i class="bi bi-pencil-square me-1"></i> নোট / মন্তব্য
                    </div>
                    <div class="card-body">
                        <textarea class="form-control" id="note" name="note" rows="2" placeholder="ঐচ্ছিক নোট…"></textarea>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="summary-card shadow-sm sticky-top" style="top: 1rem;">
                    <h6 class="mb-3 text-uppercase" style="letter-spacing:0.05em; color: rgba(255,255,255,0.8);">
                        এক্সচেঞ্জ সারসংক্ষেপ
                    </h6>

                    <div class="summary-row">
                        <span class="label">পাকা সোনার ওজন</span>
                        <span class="value" id="sumTotalPure">০ ভ ০ আ ০ র ০ প</span>
                    </div>

                    <div class="summary-row align-items-center">
                        <span class="label">লস</span>
                        <span class="d-flex align-items-center gap-1">
                            <input type="number" id="lossRateInput" min="0" step="0.001" value="1"
                                   class="form-control form-control-sm loss-rate-input" style="width:70px;">
                            <small style="color: rgba(255,255,255,0.7);">পয়েন্ট / ভরি</small>
                        </span>
                    </div>

                    <div class="summary-row">
                        <span class="label">লস</span>
                        <span class="value" id="sumLoss">০ প</span>
                    </div>
                    <div class="summary-row">
                        <span class="sub" id="sumLossTrad" style="color: rgba(255,255,255,0.6); font-size:0.75rem;">০ ভ ০ আ ০ র ০ প</span>
                    </div>

                    <div class="summary-row final">
                        <span class="label">চূড়ান্ত পাকা সোনা</span>
                        <span class="value" id="sumFinalPure">০ ভ ০ আ ০ র ০ প</span>
                    </div>

                    <button type="submit" class="btn btn-gold w-100 mt-3" id="btnSave">
                        <i class="bi bi-save-fill me-1"></i> এক্সচেঞ্জ সংরক্ষণ করুন
                    </button>
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
            <i class="bi bi-trash3"></i> <span class="d-none d-sm-inline ms-1">ডিলিট</span>
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
            পাকা সোনা: ০ ভ ০ আ ০ র ০ প
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
    return `${t.vori} ভ ${t.ana} আ ${t.roti} র ${t.point} প`;
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
                customerResults.innerHTML = '<div class="p-2 text-danger small">খুঁজতে ব্যর্থ হয়েছে।</div>';
                customerResults.style.display = 'block';
                return;
            }

            const list = data.data || [];

            if (list.length === 0) {
                customerResults.innerHTML = '<div class="p-2 text-muted small">কোনো কাস্টমার পাওয়া যায়নি।</div>';
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
            customerResults.innerHTML = '<div class="p-2 text-danger small">খুঁজতে ব্যর্থ হয়েছে।</div>';
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
        if (errEl) errEl.textContent = `${rules.label} পূর্ণসংখ্যা হতে হবে (দশমিক নয়)।`;
        return { valid: false, value: 0 };
    }

    const n = Number(raw);

    if (!Number.isFinite(n) || !Number.isInteger(n)) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} পূর্ণসংখ্যা হতে হবে।`;
        return { valid: false, value: 0 };
    }

    if (n < rules.min) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} ঋণাত্মক হতে পারে না।`;
        return { valid: false, value: 0 };
    }
    if (rules.max !== null && n > rules.max) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (errEl) errEl.textContent = `${rules.label} সর্বোচ্চ ${rules.max} হতে পারে।`;
        return { valid: false, value: rules.max };
    }

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
        if (karatErrEl) karatErrEl.textContent = 'ক্যারেট ০.০১ – ২৪.০০ এর মধ্যে হতে হবে।';
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

function renderItem(card) {
    const v = getItemValues(card);
    const grams    = traditionalToGrams(v.vori, v.ana, v.roti, v.point);
    const pureGrams = grams * (v.karat / 24);
    const pureTrad  = gramsToTraditional(pureGrams);

    card.querySelector('[data-pure-result]').innerHTML =
        `<span>পাকা সোনা: <strong>${formatTraditional(pureTrad)}</strong></span>`;
}

document.getElementById('btnAddItem').addEventListener('click', addItem);

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

    const lossRate        = getLossRate();
    const totalPureVori   = totalPureGrams / G_PER_VORI;
    const lossPointsExact = totalPureVori * lossRate;
    const lossPointsCeil  = Math.ceil(lossPointsExact);
    const lossGrams       = lossPointsCeil * G_PER_POINT;

    let finalPureGrams = totalPureGrams - lossGrams;
    if (finalPureGrams < 0) finalPureGrams = 0;

    const totalTrad = gramsToTraditional(totalPureGrams);
    const finalTrad = gramsToTraditional(finalPureGrams);
    const lossTrad  = gramsToTraditional(lossGrams);

    document.getElementById('sumTotalPure').textContent = formatTraditional(totalTrad);
    document.getElementById('sumLoss').textContent = lossPointsCeil + ' পয়েন্ট  (@ ' + lossRate + ' পয়েন্ট/ভরি)';
    document.getElementById('sumLossTrad').textContent = formatTraditional(lossTrad);
    document.getElementById('sumFinalPure').textContent = formatTraditional(finalTrad);
}

addItem();

document.getElementById('exchangeForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    if (!customerIdInput.value) {
        alert('অনুগ্রহ করে একজন কাস্টমার নির্বাচন করুন।');
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
        alert('কমপক্ষে একটি সোনার আইটেম যোগ করুন।');
        return;
    }

    if (hasValidationError) {
        alert('সংরক্ষণ করার আগে লাল চিহ্নিত ভুলগুলো সংশোধন করুন।');
        return;
    }

    for (const it of items) {
        const grams = traditionalToGrams(it.vori, it.ana, it.roti, it.point);
        if (grams <= 0) {
            alert('প্রতিটি আইটেমের ওজন শূন্যের চেয়ে বেশি হতে হবে।');
            return;
        }
    }

    const lossRate = getLossRate();

    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> সংরক্ষণ হচ্ছে…';

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
            alert(data.message || 'সোনা বদল সংরক্ষণ করতে ব্যর্থ হয়েছে।');
        }
    } catch (err) {
        alert('নেটওয়ার্ক সমস্যা। অনুগ্রহ করে আবার চেষ্টা করুন।');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-save-fill me-1"></i> এক্সচেঞ্জ সংরক্ষণ করুন';
    }
});
</script>

</body>
</html>