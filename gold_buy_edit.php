<?php
/**
 * gold_buy_edit.php
 * FineBullion Desk — Gold Buy detail & edit
 *
 * Pure PHP + mysqli. POST → PRG to prevent duplicate submit on refresh.
 * Any logged-in user: save note.
 * Admin only: edit existing items (weight, purity, price) + pure_gold_price,
 *             paid_amount — no add / remove of items.
 */

require_once __DIR__ . '/auth.php';

// -----------------------------------------------------------------------
// Conversion helpers
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

function trad_to_grams(int $v, int $a, int $r, int $p): float {
    return ($v * G_VORI) + ($a * G_ANA) + ($r * G_ROTI) + ($p * G_POINT);
}

function fmt_dt(?string $s): string {
    if (!$s) return '—';
    return (new DateTime($s))->format('d M Y, g:i A');
}

function h(mixed $s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

// -----------------------------------------------------------------------
// Require valid ID
// -----------------------------------------------------------------------
$buyId  = (int)($_GET['id'] ?? 0);
if ($buyId <= 0) {
    header('Location: gold_buy_list.php');
    exit;
}

$isAdmin = is_admin();

// -----------------------------------------------------------------------
// CSRF token
// -----------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// -----------------------------------------------------------------------
// POST → PRG
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash_error'] = 'Invalid request. Please try again.';
        header("Location: gold_buy_edit.php?id={$buyId}");
        exit;
    }

    // ---- Save note (any logged-in user) ---------------------------------
    if ($action === 'save_note') {
        $note = trim($_POST['note'] ?? '') ?: null;
        $stmt = mysqli_prepare($conn,
            "UPDATE gold_buys SET note = ?, updated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $note, $buyId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['flash_success'] = 'Note updated successfully.';
        header("Location: gold_buy_edit.php?id={$buyId}");
        exit;
    }

    // ---- Save items + amounts (admin only) ------------------------------
    if ($action === 'save_items' && $isAdmin) {
        $rawItems      = $_POST['items'] ?? [];
        $pureGoldPrice = isset($_POST['pure_gold_price']) && $_POST['pure_gold_price'] !== ''
                         ? max(0.0, (float)$_POST['pure_gold_price']) : 0.0;
        $paidAmount    = isset($_POST['paid_amount']) && $_POST['paid_amount'] !== ''
                         ? max(0.0, (float)$_POST['paid_amount']) : 0.0;

        $errors    = [];
        $calcItems = [];
        $totalAmt  = 0.0;

        if ($pureGoldPrice <= 0) $errors[] = 'Pure gold price must be greater than zero.';
        if ($paidAmount < 0)     $errors[] = 'Paid amount cannot be negative.';

        foreach ($rawItems as $i => $item) {
            $n      = $i + 1;
            $itemId = (int)($item['id'] ?? 0);
            if ($itemId <= 0) { $errors[] = "Item $n: missing ID."; continue; }

            $vori   = (int)($item['vori']   ?? 0);
            $ana    = (int)($item['ana']    ?? 0);
            $roti   = (int)($item['roti']   ?? 0);
            $point  = (int)($item['point']  ?? 0);
            $purity = (float)($item['purity'] ?? 0);

            if ($vori < 0)                $errors[] = "Item $n: Vori cannot be negative.";
            if ($ana < 0 || $ana > 15)    $errors[] = "Item $n: Ana must be 0–15.";
            if ($roti < 0 || $roti > 5)   $errors[] = "Item $n: Roti must be 0–5.";
            if ($point < 0 || $point > 9) $errors[] = "Item $n: Point must be 0–9.";
            if ($purity < 0.01 || $purity > 24) $errors[] = "Item $n: Purity must be 0.01–24.";

            $grams = trad_to_grams($vori, $ana, $roti, $point);
            if ($grams <= 0) $errors[] = "Item $n: Weight must be greater than zero.";

            // price = (grams / G_VORI) * (purity / 24) * pureGoldPrice
            $price = ($grams / G_VORI) * ($purity / 24) * $pureGoldPrice;
            $totalAmt += $price;

            $calcItems[] = [
                'id'     => $itemId,
                'weight' => $grams,
                'purity' => $purity,
                'price'  => $price,
            ];
        }

        if (empty($calcItems) && empty($errors)) $errors[] = 'No items to save.';

        if (empty($errors)) {
            mysqli_begin_transaction($conn);
            try {
                // Verify all submitted item IDs belong to this buy
                $ids          = array_column($calcItems, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $chk = mysqli_prepare($conn,
                    "SELECT COUNT(*) FROM gold_buy_items
                     WHERE gold_buy_id = ? AND id IN ($placeholders)");
                $bindTypes = 'i' . str_repeat('i', count($ids));
                $bindArgs  = array_merge([$buyId], $ids);
                mysqli_stmt_bind_param($chk, $bindTypes, ...$bindArgs);
                mysqli_stmt_execute($chk);
                mysqli_stmt_bind_result($chk, $matchCount);
                mysqli_stmt_fetch($chk);
                mysqli_stmt_close($chk);

                if ((int)$matchCount !== count($ids)) {
                    throw new \RuntimeException('Item ID mismatch — possible tamper attempt.');
                }

                // UPDATE each item
                $updItem = mysqli_prepare($conn,
                    "UPDATE gold_buy_items
                     SET weight = ?, purity = ?, price = ?
                     WHERE id = ? AND gold_buy_id = ?");
                foreach ($calcItems as $ci) {
                    mysqli_stmt_bind_param($updItem, 'dddii',
                        $ci['weight'], $ci['purity'], $ci['price'],
                        $ci['id'], $buyId);
                    mysqli_stmt_execute($updItem);
                }
                mysqli_stmt_close($updItem);

                // UPDATE buy summary
                $updBuy = mysqli_prepare($conn,
                    "UPDATE gold_buys
                     SET pure_gold_price = ?, total_amount = ?, paid_amount = ?, updated_at = NOW()
                     WHERE id = ?");
                mysqli_stmt_bind_param($updBuy, 'dddi',
                    $pureGoldPrice, $totalAmt, $paidAmount, $buyId);
                mysqli_stmt_execute($updBuy);
                mysqli_stmt_close($updBuy);

                mysqli_commit($conn);
                $_SESSION['flash_success'] = 'Items and amounts updated successfully.';
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['flash_error'] = 'Failed to save: ' . h($e->getMessage());
            }
        } else {
            $_SESSION['flash_error'] = implode('<br>', $errors);
        }

        header("Location: gold_buy_edit.php?id={$buyId}");
        exit;
    }

    header("Location: gold_buy_edit.php?id={$buyId}");
    exit;
}

// -----------------------------------------------------------------------
// Flash messages
// -----------------------------------------------------------------------
$postSuccess = $_SESSION['flash_success'] ?? '';
$postError   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// -----------------------------------------------------------------------
// Fetch buy record
// -----------------------------------------------------------------------
$stmt = mysqli_prepare($conn,
    "SELECT gb.id, gb.customer_id,
            c.name    AS customer_name,
            c.phone   AS customer_phone,
            c.address AS customer_address,
            gb.pure_gold_price, gb.total_amount, gb.paid_amount,
            (gb.total_amount - gb.paid_amount) AS due_amount,
            gb.note, gb.created_at, gb.updated_at,
            u.username AS created_by_username
     FROM gold_buys gb
     JOIN customers c ON c.id = gb.customer_id
     LEFT JOIN users u ON u.id = gb.created_by
     WHERE gb.id = ?
     LIMIT 1");
if (!$stmt) { die('DB error: ' . mysqli_error($conn)); }
mysqli_stmt_bind_param($stmt, 'i', $buyId);
mysqli_stmt_execute($stmt);
$buy = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$buy) {
    header('Location: gold_buy_list.php');
    exit;
}

// Fetch items
$iStmt = mysqli_prepare($conn,
    "SELECT id, weight, purity, price
     FROM gold_buy_items
     WHERE gold_buy_id = ?
     ORDER BY id ASC");
mysqli_stmt_bind_param($iStmt, 'i', $buyId);
mysqli_stmt_execute($iStmt);
$items = mysqli_fetch_all(mysqli_stmt_get_result($iStmt), MYSQLI_ASSOC);
mysqli_stmt_close($iStmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Buy #<?= $buyId ?> — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root { --fb-green:#0B412A; --fb-gold:#DCAD41; }
body  { background:#f5f6fa; font-family:"Segoe UI",Arial,sans-serif; }

/* header */
.buy-header {
    background:linear-gradient(135deg,var(--fb-green) 0%,#0e5636 100%);
    color:#fff; border-radius:10px; padding:1.2rem 1.5rem;
}
.buy-header small { color:rgba(255,255,255,.72); }

/* detail card */
.detail-card { background:#fff; border:1px solid #e2e5ea; border-radius:10px; padding:1.25rem 1.5rem; }
.detail-label { font-size:.78rem; color:#888; font-weight:500; white-space:nowrap; }
.detail-val   { font-size:.97rem; font-weight:600; color:#1a1a1a; word-break:break-word; }

/* item table badges */
.badge-weight { background:#eaf5ee; color:var(--fb-green); font-weight:600; font-size:.82rem; }
.badge-purity { background:#f0f0f0; color:#444;            font-weight:600; font-size:.82rem; }
.badge-price  { background:var(--fb-green); color:#fff;    font-weight:600; font-size:.82rem; }

/* summary ledger */
.ledger { border:1px solid #dee2e6; border-radius:8px; overflow:hidden; }
.ledger td { padding:.6rem .9rem; border-bottom:1px solid #eee; vertical-align:middle; }
.ledger tr:last-child td { border-bottom:none; }
.l-label { font-size:.83rem; color:#555; width:1%; white-space:nowrap; }
.l-val   { font-weight:700; font-size:.95rem; text-align:right; }
.l-price td  { background:#eaf5ee!important; }
.l-price .l-label,.l-price .l-val { color:var(--fb-green); }
.l-paid  td  { background:#e8f5e9!important; }
.l-paid  .l-label { color:#2e7d32; font-weight:600; }
.l-paid  .l-val   { color:#2e7d32; }
.l-due   td  { background:var(--fb-green)!important; border-bottom:none; }
.l-due   .l-label { color:rgba(255,255,255,.85); font-weight:600; }
.l-due   .l-val   { color:#fff; font-size:1.05rem; }

/* buttons */
.btn-gold { background:var(--fb-gold); border-color:var(--fb-gold); color:#1a1a1a; font-weight:600; }
.btn-gold:hover { background:#c99a2f; border-color:#c99a2f; color:#1a1a1a; }

/* edit modal item cards */
.edit-item-card {
    border:1px solid #e2e5ea; border-radius:10px;
    padding:1rem 1.1rem; margin-bottom:1rem;
    background:#fff; position:relative;
}
.edit-item-badge {
    position:absolute; top:-10px; left:14px;
    background:var(--fb-green); color:#fff;
    font-size:.72rem; font-weight:700;
    padding:.1rem .6rem; border-radius:10px;
}
.item-price-preview {
    background:#f4f9f6; border:1px dashed #bcd9c9;
    border-radius:8px; padding:.5rem .8rem;
    font-size:.88rem; color:var(--fb-green); font-weight:600;
}

.item-fields-row {
    display:grid; grid-template-columns:repeat(4,1fr); gap:.5rem;
}
.item-fields-row .field-col label {
    display:block; font-size:.72rem; margin-bottom:.15rem; color:#6c757d; white-space:nowrap;
}
.item-fields-row .field-col input {
    text-align:center; padding-left:.25rem; padding-right:.25rem;
}
.item-fields-row input.form-control.is-valid,
.item-fields-row input.form-control.is-invalid,
.purity-row input.form-control.is-valid,
.purity-row input.form-control.is-invalid {
    background-image:none!important; padding-right:.25rem!important;
}
.purity-row { margin-top:.6rem; }
.purity-row label { display:block; font-size:.72rem; margin-bottom:.15rem; color:#6c757d; }

/* preview total row inside modal */
.preview-total-row {
    background:#f4f9f6; border:1px solid #bcd9c9; border-radius:8px;
    padding:.5rem .85rem; font-size:.88rem;
    display:flex; justify-content:space-between; align-items:center;
}
.preview-total-row .pt-label { color:#6c757d; }
.preview-total-row .pt-value { font-weight:700; color:var(--fb-green); }

/* mobile */
@media (max-width: 767.98px) {
    html,body { height:100%; overflow:hidden; }
    .page-content { height:100vh; overflow:hidden; display:flex; flex-direction:column; }
    .page-content .container-fluid {
        padding:.45rem .5rem!important; display:flex; flex-direction:column;
        gap:.45rem; flex:1; min-height:0; overflow:hidden;
    }
    .container-fluid > .alert { padding:.4rem .65rem; font-size:.75rem; margin-bottom:0!important; }

    .buy-header { padding:.55rem .75rem; border-radius:8px; margin-bottom:0!important; }
    .buy-header h5 { font-size:1rem; }
    .buy-header h5 i { display:none; }
    .buy-header small { display:none; }
    .buy-header .text-end { display:none; }
    .buy-header .btn-outline-light { padding:.15rem .42rem; font-size:.8rem; }

    .detail-card-wrap { margin-bottom:0!important; }
    .detail-card-wrap .card-header { padding:.4rem .6rem; }
    .detail-card { padding:.55rem .7rem; }
    .detail-card .col-md-7,.detail-card .col-md-4 { flex:1 1 100%; max-width:100%; padding:0!important; }
    .detail-card .col-md-1 { display:none!important; }
    .detail-card hr { margin:.35rem 0!important; }
    .detail-card .row.g-2 { row-gap:.1rem!important; }
    .detail-label { font-size:.74rem; flex:0 0 auto; }
    .detail-val   { font-size:.85rem; }
    .detail-card .row.g-2 > .col-12 { display:flex; align-items:baseline; gap:.3rem; flex-wrap:nowrap; }

    .card { border-radius:8px; margin-bottom:0!important; }
    .card-header { padding:.4rem .6rem; }
    .card-header .fw-semibold { font-size:.85rem; }
    .card-header .badge { font-size:.68rem; }
    .card-header .btn-sm { padding:.15rem .45rem; font-size:.74rem; }

    table.table { font-size:.8rem; margin-bottom:0; }
    table.table th, table.table td { padding:.32rem .38rem; }
    .badge-weight,.badge-purity,.badge-price { font-size:.74rem; padding:.32em .52em; }

    .ledger td { padding:.38rem .6rem; font-size:.8rem; }
    .l-label { font-size:.76rem; }
    .l-val   { font-size:.85rem; }
    .l-due .l-val { font-size:.92rem; }

    .card.note-card { display:none!important; }

    .buy-header,.detail-card-wrap,.card { flex:0 0 auto; }
    .card:last-of-type { margin-bottom:0!important; }

    .edit-item-card { padding:.75rem .75rem .6rem; margin-bottom:.6rem; border-radius:8px; }
    .edit-item-badge { top:-9px; left:12px; font-size:.65rem; padding:.08rem .5rem; }
    .edit-item-card .form-control-sm { font-size:.82rem; padding:.28rem .4rem; }
    .item-fields-row { gap:.4rem; }
    .item-price-preview { padding:.35rem .6rem; font-size:.76rem; margin-top:.5rem!important; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid py-4">

<?php if ($postSuccess): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i><?= h($postSuccess) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($postError): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $postError ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ================================================================
     PAGE HEADER
================================================================ -->
<div class="buy-header mb-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="gold_buy_list.php" class="btn btn-sm btn-outline-light py-0 px-2">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <h5 class="mb-0">
                    <i class="bi bi-cash-coin me-1"></i>
                    Old Gold Buy
                    <span style="opacity:.65;">&nbsp;#<?= $buyId ?></span>
                </h5>
            </div>
            <small>Gold buy detail — FineBullion Desk</small>
        </div>
        <div class="text-end">
            <div style="font-size:.75rem;color:rgba(255,255,255,.6);letter-spacing:.03em;">CREATED BY</div>
            <div class="fw-semibold" style="font-size:.97rem;">
                <i class="bi bi-person-circle me-1"></i><?= h($buy['created_by_username'] ?? '—') ?>
            </div>
            <div style="font-size:.8rem;color:rgba(255,255,255,.65);">
                <?= h(fmt_dt($buy['created_at'])) ?>
            </div>
        </div>
    </div>
</div>

<!-- ================================================================
     CUSTOMER + DETAIL
================================================================ -->
<div class="card shadow-sm mb-4 detail-card-wrap">
    <div class="card-header bg-white fw-semibold d-md-none">
        <i class="bi bi-person-fill me-1" style="color:var(--fb-green);"></i> Customer
    </div>
    <div class="detail-card">
    <div class="row g-0">
        <!-- Left: customer info -->
        <div class="col-md-7 pe-md-4">
            <div class="row g-2">
                <div class="col-12">
                    <span class="detail-label">Name:</span>
                    <span class="detail-val ms-1"><?= h($buy['customer_name']) ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-val ms-1"><?= h($buy['customer_phone'] ?: '—') ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">Address:</span>
                    <span class="detail-val ms-1" style="font-weight:400;color:#555;">
                        <?= h($buy['customer_address'] ?: '—') ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Vertical divider (md+) -->
        <div class="col-md-1 d-none d-md-flex justify-content-center">
            <div style="width:1px;background:#e2e5ea;min-height:100%;"></div>
        </div>
        <!-- Horizontal divider (mobile) -->
        <div class="col-12 d-md-none my-3"><hr class="my-0"></div>

        <!-- Right: date + created-by -->
        <div class="col-md-4 ps-md-3">
            <div class="row g-2">
                <div class="col-12">
                    <span class="detail-label">Date &amp; Time:</span>
                    <span class="detail-val ms-1"><?= h(fmt_dt($buy['created_at'])) ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">Created By:</span>
                    <span class="detail-val ms-1"><?= h($buy['created_by_username'] ?? '—') ?></span>
                </div>
                <?php if ($buy['updated_at'] && $buy['updated_at'] !== $buy['created_at']): ?>
                <div class="col-12">
                    <span class="detail-label">Modified At:</span>
                    <span class="ms-1" style="font-size:.88rem;color:#888;">
                        <?= h(fmt_dt($buy['updated_at'])) ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </div>
</div>

<!-- ================================================================
     GOLD BUY ITEMS TABLE
================================================================ -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">
            <i class="bi bi-gem me-1" style="color:var(--fb-green);"></i>
            Old Gold Items
            <span class="badge bg-secondary ms-1"><?= count($items) ?></span>
        </span>
        <?php if ($isAdmin): ?>
        <button type="button" class="btn btn-sm btn-gold"
                data-bs-toggle="modal" data-bs-target="#editModal">
            <i class="bi bi-pencil-square me-1"></i> Edit Items
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:38px;">#</th>
                        <th>Weight (VARP)</th>
                        <th style="width:90px;">Purity</th>
                        <th class="text-end">Item Price</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">No items found.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $idx => $it): ?>
                    <tr>
                        <td class="text-muted small"><?= $idx + 1 ?></td>
                        <td><span class="badge badge-weight"><?= h(fmt_trad((float)$it['weight'])) ?></span></td>
                        <td><span class="badge badge-purity"><?= h(number_format((float)$it['purity'], 2)) ?>K</span></td>
                        <td class="text-end"><span class="badge badge-price">৳<?= number_format((float)$it['price'], 0) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================================================================
     BUY SUMMARY LEDGER
================================================================ -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-calculator me-1" style="color:var(--fb-green);"></i>
        Buy Summary
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0 ledger" style="border-radius:0;">
            <tbody>
                <tr class="l-price">
                    <td class="l-label">Pure Gold Price (24k / Vori)</td>
                    <td class="l-val">৳<?= number_format((float)$buy['pure_gold_price'], 0) ?></td>
                </tr>
                <tr class="l-price">
                    <td class="l-label">Total Amount</td>
                    <td class="l-val">৳<?= number_format((float)$buy['total_amount'], 0) ?></td>
                </tr>
                <tr class="l-paid">
                    <td class="l-label">Paid Amount</td>
                    <td class="l-val">৳<?= number_format((float)$buy['paid_amount'], 0) ?></td>
                </tr>
                <tr class="l-due">
                    <td class="l-label">Due Amount</td>
                    <td class="l-val">৳<?= number_format((float)$buy['due_amount'], 0) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ================================================================
     NOTE
================================================================ -->
<div class="card shadow-sm mb-4 note-card">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-pencil-square me-1" style="color:var(--fb-green);"></i>
        Note / Remarks
    </div>
    <div class="card-body">
        <form method="POST" action="gold_buy_edit.php?id=<?= $buyId ?>">
            <input type="hidden" name="action"     value="save_note">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <textarea class="form-control mb-2" name="note" rows="3"
                      placeholder="Optional note…"><?= h($buy['note'] ?? '') ?></textarea>
            <button type="submit" class="btn btn-gold btn-sm">
                <i class="bi bi-save-fill me-1"></i> Save Note
            </button>
        </form>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- ================================================================
     EDIT ITEMS MODAL (admin only)
     Existing items only — no add / remove.
================================================================ -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--fb-green);color:#fff;">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square me-2"></i>
                    Edit Buy Items — #<?= $buyId ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" action="gold_buy_edit.php?id=<?= $buyId ?>" id="editItemsForm">
                <input type="hidden" name="action"     value="save_items">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                <div class="modal-body">

                    <!-- Pure gold price + paid amount (top row) -->
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold mb-1">
                                Pure Gold Price (24k / Vori)
                                <small class="text-muted fw-normal">(BDT)</small>
                            </label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text" style="background:var(--fb-green);color:#fff;border-color:var(--fb-green);">৳</span>
                                <input type="number" name="pure_gold_price" id="pureGoldPriceInput"
                                       min="1" step="1"
                                       value="<?= h((int)$buy['pure_gold_price']) ?>"
                                       class="form-control"
                                       oninput="recalcAll()">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold mb-1">
                                Paid Amount
                                <small class="text-muted fw-normal">(BDT)</small>
                            </label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text" style="background:#2e7d32;color:#fff;border-color:#2e7d32;">৳</span>
                                <input type="number" name="paid_amount" id="paidAmountInput"
                                       min="0" step="1"
                                       value="<?= h((int)$buy['paid_amount']) ?>"
                                       class="form-control"
                                       oninput="recalcAll()">
                            </div>
                        </div>
                    </div>

                    <!-- Existing items -->
                    <?php if (empty($items)): ?>
                        <div class="text-muted text-center py-3">No items to edit.</div>
                    <?php else: ?>
                        <?php foreach ($items as $idx => $it):
                            $trad  = grams_to_trad((float)$it['weight']);
                            $karat = (float)$it['purity'];
                        ?>
                        <div class="edit-item-card">
                            <span class="edit-item-badge">Item <?= $idx + 1 ?></span>
                            <input type="hidden" name="items[<?= $idx ?>][id]"
                                   value="<?= (int)$it['id'] ?>">

                            <div class="item-fields-row mt-2">
                                <div class="field-col">
                                    <label>Vori</label>
                                    <input type="number" name="items[<?= $idx ?>][vori]"
                                           class="form-control form-control-sm"
                                           min="0" step="1" inputmode="numeric"
                                           value="<?= $trad['v'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                                <div class="field-col">
                                    <label>Ana</label>
                                    <input type="number" name="items[<?= $idx ?>][ana]"
                                           class="form-control form-control-sm"
                                           min="0" max="15" step="1" inputmode="numeric"
                                           value="<?= $trad['a'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                                <div class="field-col">
                                    <label>Roti</label>
                                    <input type="number" name="items[<?= $idx ?>][roti]"
                                           class="form-control form-control-sm"
                                           min="0" max="5" step="1" inputmode="numeric"
                                           value="<?= $trad['r'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                                <div class="field-col">
                                    <label>Point</label>
                                    <input type="number" name="items[<?= $idx ?>][point]"
                                           class="form-control form-control-sm"
                                           min="0" max="9" step="1" inputmode="numeric"
                                           value="<?= $trad['p'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                            </div>

                            <div class="purity-row">
                                <label>Purity (Karat)</label>
                                <input type="number" name="items[<?= $idx ?>][purity]"
                                       class="form-control form-control-sm"
                                       min="0.01" max="24" step="0.01"
                                       placeholder="e.g. 22"
                                       value="<?= h($karat) ?>"
                                       oninput="recalcItem(<?= $idx ?>)">
                            </div>

                            <div class="item-price-preview mt-2" id="itemPreview_<?= $idx ?>">
                                Item Price: ৳<?= number_format((float)$it['price'], 0) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Live summary preview -->
                    <div class="mt-3 pt-3 border-top">
                        <div class="text-muted small fw-semibold text-uppercase mb-2"
                             style="letter-spacing:.04em;">Preview Summary</div>
                        <table class="table table-sm mb-0 ledger">
                            <tbody>
                                <tr class="l-price">
                                    <td class="l-label">Total Amount</td>
                                    <td class="l-val" id="previewTotal">—</td>
                                </tr>
                                <tr class="l-paid">
                                    <td class="l-label">Paid Amount</td>
                                    <td class="l-val" id="previewPaid">—</td>
                                </tr>
                                <tr class="l-due">
                                    <td class="l-label">Due Amount</td>
                                    <td class="l-val" id="previewDue">—</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                </div><!-- /modal-body -->

                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-gold btn-sm">
                        <i class="bi bi-save-fill me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';
const G_VORI = 11.664, G_ANA = 0.729, G_ROTI = 0.1215, G_POINT = 0.01215;
const ITEM_COUNT = <?= count($items) ?>;

function tradToGrams(v,a,r,p){
    return v*G_VORI + a*G_ANA + r*G_ROTI + p*G_POINT;
}
function fmtBDT(n){ return '৳' + Math.round(n).toLocaleString('en-BD'); }

function getItemInputs(idx){
    return {
        v: parseInt(document.querySelector(`[name="items[${idx}][vori]"]`)?.value)   || 0,
        a: parseInt(document.querySelector(`[name="items[${idx}][ana]"]`)?.value)    || 0,
        r: parseInt(document.querySelector(`[name="items[${idx}][roti]"]`)?.value)   || 0,
        p: parseInt(document.querySelector(`[name="items[${idx}][point]"]`)?.value)  || 0,
        k: parseFloat(document.querySelector(`[name="items[${idx}][purity]"]`)?.value) || 0,
    };
}

function getPureGoldPrice(){
    return Math.max(0, parseFloat(document.getElementById('pureGoldPriceInput').value) || 0);
}
function getPaidAmount(){
    return Math.max(0, parseFloat(document.getElementById('paidAmountInput').value) || 0);
}

function recalcItem(idx){
    const {v,a,r,p,k} = getItemInputs(idx);
    const grams = tradToGrams(v,a,r,p);
    const price = (grams / G_VORI) * (k / 24) * getPureGoldPrice();
    const el = document.getElementById('itemPreview_' + idx);
    if (el) el.textContent = 'Item Price: ' + fmtBDT(price);
    recalcSummary();
}

function recalcAll(){
    for (let i = 0; i < ITEM_COUNT; i++) recalcItem(i);
}

function recalcSummary(){
    let total = 0;
    const pg = getPureGoldPrice();
    for (let i = 0; i < ITEM_COUNT; i++){
        const {v,a,r,p,k} = getItemInputs(i);
        total += (tradToGrams(v,a,r,p) / G_VORI) * (k / 24) * pg;
    }
    const paid = getPaidAmount();
    const due  = total - paid;
    document.getElementById('previewTotal').textContent = fmtBDT(total);
    document.getElementById('previewPaid').textContent  = fmtBDT(paid);
    document.getElementById('previewDue').textContent   = fmtBDT(Math.abs(due));
}

document.getElementById('editModal').addEventListener('shown.bs.modal', recalcAll);
</script>

<?php else: ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>

</div>
</div>
</body>
</html>