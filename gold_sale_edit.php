<?php
/**
 * gold_sale_edit.php
 * FineBullion Desk — Gold Sale detail & edit
 *
 * Pure PHP + mysqli. POST → PRG to prevent duplicate submit on refresh.
 * Any logged-in user: save note, add payment.
 * Admin only: edit existing sale items (weight + purity) + pure_gold_price.
 *
 * Payment tracking:
 *   - All payments stored in gold_sale_payments table.
 *   - gold_sales.paid_amount is a cache updated after each payment insert/delete.
 *   - Due = total_amount − SUM(gold_sale_payments.paid_amount).
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

// Helper: recalculate and cache paid_amount on gold_sales from payments table
function sync_paid_amount(mysqli $conn, int $saleId): void {
    $stmt = mysqli_prepare($conn,
        "UPDATE gold_sales
         SET paid_amount = (
             SELECT COALESCE(SUM(paid_amount), 0)
             FROM gold_sale_payments
             WHERE gold_sale_id = ?
         ), updated_at = NOW()
         WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $saleId, $saleId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// -----------------------------------------------------------------------
// Require valid ID
// -----------------------------------------------------------------------
$saleId = (int)($_GET['id'] ?? 0);
if ($saleId <= 0) {
    header('Location: gold_sale_list.php');
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
        header("Location: gold_sale_edit.php?id={$saleId}");
        exit;
    }

    // ---- Save note (any logged-in user) ---------------------------------
    if ($action === 'save_note') {
        $note = trim($_POST['note'] ?? '') ?: null;
        $stmt = mysqli_prepare($conn,
            "UPDATE gold_sales SET note = ?, updated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $note, $saleId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['flash_success'] = 'Note updated successfully.';
        header("Location: gold_sale_edit.php?id={$saleId}");
        exit;
    }

    // ---- Add payment (any logged-in user) --------------------------------
    if ($action === 'add_payment') {
        $paidAmount     = (float)($_POST['paid_amount']    ?? 0);
        $paymentDate    = trim($_POST['payment_date']      ?? '');
        $transactionRef = trim($_POST['transaction_ref']   ?? '') ?: null;
        $paymentNote    = trim($_POST['payment_note']      ?? '') ?: null;
        $userId         = $currentUser['id'];

        // Re-fetch the live due amount to guard against race conditions or
        // a stale/tampered form (never trust the client-submitted due figure).
        $chkStmt = mysqli_prepare($conn,
            "SELECT gs.total_amount,
                    COALESCE(SUM(p.paid_amount), 0) AS paid_so_far
             FROM gold_sales gs
             LEFT JOIN gold_sale_payments p ON p.gold_sale_id = gs.id
             WHERE gs.id = ?
             GROUP BY gs.id, gs.total_amount");
        mysqli_stmt_bind_param($chkStmt, 'i', $saleId);
        mysqli_stmt_execute($chkStmt);
        $chk        = mysqli_fetch_assoc(mysqli_stmt_get_result($chkStmt));
        mysqli_stmt_close($chkStmt);
        $currentDue = $chk ? round((float)$chk['total_amount'] - (float)$chk['paid_so_far'], 2) : 0.0;

        $errors = [];
        if ($currentDue <= 0) {
            $errors[] = 'This sale is already fully paid.';
        } elseif ($paidAmount <= 0) {
            $errors[] = 'Paid amount must be greater than zero.';
        } elseif ($paidAmount > $currentDue + 0.009) {
            $errors[] = 'Amount exceeds remaining due (৳' . number_format($currentDue, 0) . ').';
        }
        if (empty($paymentDate)) $errors[] = 'Payment date is required.';

        if (empty($errors)) {
            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare($conn,
                    "INSERT INTO gold_sale_payments
                        (gold_sale_id, paid_amount, transaction_ref, payment_date, note, received_by)
                     VALUES (?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, 'idsssi',
                    $saleId, $paidAmount, $transactionRef, $paymentDate, $paymentNote, $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                // Sync cache
                sync_paid_amount($conn, $saleId);
                mysqli_commit($conn);
                $_SESSION['flash_success'] = 'Payment recorded successfully.';
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['flash_error'] = 'Failed to record payment. Please try again.';
            }
        } else {
            $_SESSION['flash_error'] = implode('<br>', $errors);
        }

        header("Location: gold_sale_edit.php?id={$saleId}");
        exit;
    }

    // ---- Delete payment (admin only) -------------------------------------
    if ($action === 'delete_payment' && $isAdmin) {
        $pmtId = (int)($_POST['payment_id'] ?? 0);
        if ($pmtId > 0) {
            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare($conn,
                    "DELETE FROM gold_sale_payments WHERE id = ? AND gold_sale_id = ?");
                mysqli_stmt_bind_param($stmt, 'ii', $pmtId, $saleId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                sync_paid_amount($conn, $saleId);
                mysqli_commit($conn);
                $_SESSION['flash_success'] = 'Payment deleted.';
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['flash_error'] = 'Failed to delete payment.';
            }
        }
        header("Location: gold_sale_edit.php?id={$saleId}");
        exit;
    }

    // ---- Save items + gold price (admin only) ----------------------------
    if ($action === 'save_items' && $isAdmin) {
        $rawItems      = $_POST['items'] ?? [];
        $pureGoldPrice = isset($_POST['pure_gold_price']) && $_POST['pure_gold_price'] !== ''
                         ? max(0.0, (float)$_POST['pure_gold_price']) : 0.0;

        $errors    = [];
        $calcItems = [];
        $totalAmt  = 0.0;

        if ($pureGoldPrice <= 0) $errors[] = 'Pure gold price must be greater than zero.';

        foreach ($rawItems as $i => $item) {
            $n      = $i + 1;
            $itemId = (int)($item['id'] ?? 0);
            if ($itemId <= 0) { $errors[] = "Item $n: missing ID."; continue; }

            $vori   = (int)($item['vori']    ?? 0);
            $ana    = (int)($item['ana']     ?? 0);
            $roti   = (int)($item['roti']    ?? 0);
            $point  = (int)($item['point']   ?? 0);
            $purity = (float)($item['purity'] ?? 0);

            if ($vori < 0)                $errors[] = "Item $n: Vori cannot be negative.";
            if ($ana < 0 || $ana > 15)    $errors[] = "Item $n: Ana must be 0–15.";
            if ($roti < 0 || $roti > 5)   $errors[] = "Item $n: Roti must be 0–5.";
            if ($point < 0 || $point > 9) $errors[] = "Item $n: Point must be 0–9.";
            if ($purity < 0.01 || $purity > 24) $errors[] = "Item $n: Purity must be 0.01–24.";

            $grams = trad_to_grams($vori, $ana, $roti, $point);
            if ($grams <= 0) $errors[] = "Item $n: Weight must be greater than zero.";

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
                // Verify all submitted item IDs belong to this sale
                $ids          = array_column($calcItems, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $chk = mysqli_prepare($conn,
                    "SELECT COUNT(*) FROM gold_sale_items
                     WHERE gold_sale_id = ? AND id IN ($placeholders)");
                $bindTypes = 'i' . str_repeat('i', count($ids));
                $bindArgs  = array_merge([$saleId], $ids);
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
                    "UPDATE gold_sale_items
                     SET weight = ?, purity = ?, price = ?
                     WHERE id = ? AND gold_sale_id = ?");
                foreach ($calcItems as $ci) {
                    mysqli_stmt_bind_param($updItem, 'dddii',
                        $ci['weight'], $ci['purity'], $ci['price'], $ci['id'], $saleId);
                    mysqli_stmt_execute($updItem);
                }
                mysqli_stmt_close($updItem);

                // UPDATE sale — total_amount recalculated; paid_amount synced from payments
                $updSale = mysqli_prepare($conn,
                    "UPDATE gold_sales
                     SET pure_gold_price = ?,
                         total_amount    = ?,
                         paid_amount     = (
                             SELECT COALESCE(SUM(paid_amount), 0)
                             FROM gold_sale_payments
                             WHERE gold_sale_id = ?
                         ),
                         updated_at      = NOW()
                     WHERE id = ?");
                mysqli_stmt_bind_param($updSale, 'ddii',
                    $pureGoldPrice, $totalAmt, $saleId, $saleId);
                mysqli_stmt_execute($updSale);
                mysqli_stmt_close($updSale);

                mysqli_commit($conn);
                $_SESSION['flash_success'] = 'Items updated successfully.';
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['flash_error'] = 'Failed to save: ' . h($e->getMessage());
            }
        } else {
            $_SESSION['flash_error'] = implode('<br>', $errors);
        }

        header("Location: gold_sale_edit.php?id={$saleId}");
        exit;
    }

    header("Location: gold_sale_edit.php?id={$saleId}");
    exit;
}

// -----------------------------------------------------------------------
// Flash messages
// -----------------------------------------------------------------------
$postSuccess = $_SESSION['flash_success'] ?? '';
$postError   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// -----------------------------------------------------------------------
// Fetch sale record
// -----------------------------------------------------------------------
$stmt = mysqli_prepare($conn,
    "SELECT gs.id, gs.customer_id,
            c.name    AS customer_name,
            c.phone   AS customer_phone,
            c.address AS customer_address,
            gs.pure_gold_price, gs.total_amount,
            COALESCE(gsp.paid_sum, 0)                          AS paid_amount,
            (gs.total_amount - COALESCE(gsp.paid_sum, 0))      AS due_amount,
            gs.note, gs.created_at, gs.updated_at,
            u.username AS created_by_username
     FROM gold_sales gs
     JOIN customers c ON c.id = gs.customer_id
     LEFT JOIN users u ON u.id = gs.created_by
     LEFT JOIN (
         SELECT gold_sale_id, SUM(paid_amount) AS paid_sum
         FROM gold_sale_payments
         GROUP BY gold_sale_id
     ) gsp ON gsp.gold_sale_id = gs.id
     WHERE gs.id = ?
     LIMIT 1");
if (!$stmt) { die('DB error: ' . mysqli_error($conn)); }
mysqli_stmt_bind_param($stmt, 'i', $saleId);
mysqli_stmt_execute($stmt);
$sale = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$sale) {
    header('Location: gold_sale_list.php');
    exit;
}

// Fetch items
$iStmt = mysqli_prepare($conn,
    "SELECT id, weight, purity, price
     FROM gold_sale_items
     WHERE gold_sale_id = ?
     ORDER BY id ASC");
mysqli_stmt_bind_param($iStmt, 'i', $saleId);
mysqli_stmt_execute($iStmt);
$items = mysqli_fetch_all(mysqli_stmt_get_result($iStmt), MYSQLI_ASSOC);
mysqli_stmt_close($iStmt);

// Fetch payment history
$pStmt = mysqli_prepare($conn,
    "SELECT p.id, p.paid_amount, p.transaction_ref, p.payment_date, p.note,
            p.created_at, u.username AS received_by_username
     FROM gold_sale_payments p
     LEFT JOIN users u ON u.id = p.received_by
     WHERE p.gold_sale_id = ?
     ORDER BY p.payment_date ASC, p.created_at ASC");
mysqli_stmt_bind_param($pStmt, 'i', $saleId);
mysqli_stmt_execute($pStmt);
$payments = mysqli_fetch_all(mysqli_stmt_get_result($pStmt), MYSQLI_ASSOC);
mysqli_stmt_close($pStmt);

$totalPaid = array_sum(array_column($payments, 'paid_amount'));
$dueAmount = round((float)$sale['due_amount'], 2);
$fullyPaid = $dueAmount <= 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sale #<?= $saleId ?> — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root { --fb-green:#0B412A; --fb-gold:#DCAD41; }
body  { background:#f5f6fa; font-family:"Segoe UI",Arial,sans-serif; }

/* header */
.sale-header {
    background:linear-gradient(135deg,var(--fb-green) 0%,#0e5636 100%);
    color:#fff; border-radius:10px; padding:1.2rem 1.5rem;
}
.sale-header small { color:rgba(255,255,255,.72); }

/* detail card */
.detail-card { background:#fff; border:1px solid #e2e5ea; border-radius:10px; padding:1.25rem 1.5rem; }
.detail-label { font-size:.78rem; color:#888; font-weight:500; white-space:nowrap; }
.detail-val   { font-size:.97rem; font-weight:600; color:#1a1a1a; word-break:break-word; }

/* item table badges */
.badge-weight  { background:#eaf5ee; color:var(--fb-green); font-weight:600; font-size:.82rem; }
.badge-purity  { background:#fdf8ec; color:#7a5c10; font-weight:600; font-size:.82rem;
                 border:1px solid #DCAD41; }
.badge-price   { background:var(--fb-green); color:#fff; font-weight:600; font-size:.82rem; }

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

/* payment history */
.pmt-item {
    display:flex; align-items:flex-start; gap:.6rem;
    padding:.6rem .75rem;
    border-bottom:1px solid #f0f0f0;
    font-size:.84rem;
}
.pmt-item:last-child { border-bottom:none; }
.pmt-date  { color:#6c757d; white-space:nowrap; min-width:85px; font-size:.78rem; }
.pmt-body  { flex:1; min-width:0; }
.pmt-ref   { color:#555; font-size:.78rem; }
.pmt-note  { color:#888; font-size:.75rem; font-style:italic; }
.pmt-amount{ font-weight:700; color:#2e7d32; white-space:nowrap; }
.pmt-by    { color:#999; font-size:.73rem; }

/* add payment form */
.payment-form-card { background:#fafffe; border:1px solid #c3ddd1; border-radius:10px; }
.payment-form-card .card-header {
    background:var(--fb-green); color:#fff; font-size:.82rem; border-radius:9px 9px 0 0;
}

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

.purity-row { margin-top:.6rem; }
.purity-row label { display:block; font-size:.72rem; margin-bottom:.15rem; color:#6c757d; }
.purity-row input.form-control.is-valid,
.purity-row input.form-control.is-invalid {
    background-image:none!important; padding-right:.25rem!important;
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
.item-fields-row input.form-control.is-invalid {
    background-image:none!important; padding-right:.25rem!important;
}

/* mobile */
@media (max-width: 767.98px) {
    html,body { height:100%; overflow:hidden; }
    .page-content { height:100vh; overflow:hidden; display:flex; flex-direction:column; }
    .page-content .container-fluid {
        padding:.45rem .5rem!important; display:flex; flex-direction:column;
        gap:.45rem; flex:1; min-height:0; overflow:hidden;
    }
    .container-fluid > .alert { padding:.4rem .65rem; font-size:.75rem; margin-bottom:0!important; }

    .sale-header { padding:.55rem .75rem; border-radius:8px; margin-bottom:0!important; }
    .sale-header h5 { font-size:1rem; }
    .sale-header h5 i { display:none; }
    .sale-header small { display:none; }
    .sale-header .text-end { display:none; }
    .sale-header .btn-outline-light { padding:.15rem .42rem; font-size:.8rem; }

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

    .sale-header,.detail-card-wrap,.card { flex:0 0 auto; }
    .card:last-of-type { margin-bottom:0!important; }

    .edit-item-card { padding:.75rem .75rem .6rem; margin-bottom:.6rem; border-radius:8px; }
    .edit-item-badge { top:-9px; left:12px; font-size:.65rem; padding:.08rem .5rem; }
    .edit-item-card .form-control-sm { font-size:.82rem; padding:.28rem .4rem; }
    .item-fields-row { gap:.4rem; }
    .item-price-preview { padding:.35rem .6rem; font-size:.76rem; margin-top:.5rem!important; }

    .pmt-item { padding:.45rem .5rem; font-size:.78rem; }
    .pmt-date { min-width:70px; font-size:.72rem; }
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
<div class="sale-header mb-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="gold_sale_list.php" class="btn btn-sm btn-outline-light py-0 px-2">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <h5 class="mb-0">
                    <i class="bi bi-bag-check-fill me-1"></i>
                    Gold Sale
                    <span style="opacity:.65;">&nbsp;#<?= $saleId ?></span>
                </h5>
            </div>
            <small>Gold sale detail — FineBullion Desk</small>
        </div>
        <div class="text-end">
            <div style="font-size:.75rem;color:rgba(255,255,255,.6);letter-spacing:.03em;">CREATED BY</div>
            <div class="fw-semibold" style="font-size:.97rem;">
                <i class="bi bi-person-circle me-1"></i><?= h($sale['created_by_username'] ?? '—') ?>
            </div>
            <div style="font-size:.8rem;color:rgba(255,255,255,.65);">
                <?= h(fmt_dt($sale['created_at'])) ?>
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
        <div class="col-md-7 pe-md-4">
            <div class="row g-2">
                <div class="col-12">
                    <span class="detail-label">Name:</span>
                    <span class="detail-val ms-1"><?= h($sale['customer_name']) ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-val ms-1"><?= h($sale['customer_phone'] ?: '—') ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">Address:</span>
                    <span class="detail-val ms-1" style="font-weight:400;color:#555;">
                        <?= h($sale['customer_address'] ?: '—') ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="col-md-1 d-none d-md-flex justify-content-center">
            <div style="width:1px;background:#e2e5ea;min-height:100%;"></div>
        </div>
        <div class="col-12 d-md-none my-3"><hr class="my-0"></div>
        <div class="col-md-4 ps-md-3">
            <div class="row g-2">
                <div class="col-12">
                    <span class="detail-label">Date &amp; Time:</span>
                    <span class="detail-val ms-1"><?= h(fmt_dt($sale['created_at'])) ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">Created By:</span>
                    <span class="detail-val ms-1"><?= h($sale['created_by_username'] ?? '—') ?></span>
                </div>
                <?php if ($sale['updated_at'] && $sale['updated_at'] !== $sale['created_at']): ?>
                <div class="col-12">
                    <span class="detail-label">Modified At:</span>
                    <span class="ms-1" style="font-size:.88rem;color:#888;">
                        <?= h(fmt_dt($sale['updated_at'])) ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </div>
</div>

<!-- ================================================================
     GOLD SALE ITEMS TABLE
================================================================ -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">
            <i class="bi bi-gem me-1 text-warning"></i>
            Gold Items
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
                        <th style="width:90px;" class="text-center">Purity</th>
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
                        <td class="text-center">
                            <span class="badge badge-purity"><?= h(number_format((float)$it['purity'], 2)) ?>K</span>
                        </td>
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
     SALE SUMMARY LEDGER
================================================================ -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-calculator me-1" style="color:var(--fb-green);"></i>
        Sale Summary
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0 ledger" style="border-radius:0;">
            <tbody>
                <tr class="l-price">
                    <td class="l-label">Pure Gold Price (24k / Vori)</td>
                    <td class="l-val">৳<?= number_format((float)$sale['pure_gold_price'], 0) ?></td>
                </tr>
                <tr class="l-price">
                    <td class="l-label">Total Amount</td>
                    <td class="l-val">৳<?= number_format((float)$sale['total_amount'], 0) ?></td>
                </tr>
                <tr class="l-paid">
                    <td class="l-label">Total Paid</td>
                    <td class="l-val">৳<?= number_format((float)$totalPaid, 0) ?></td>
                </tr>
                <tr class="l-due">
                    <td class="l-label">Due Amount</td>
                    <td class="l-val">৳<?= number_format((float)$sale['due_amount'], 0) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ================================================================
     PAYMENT HISTORY
================================================================ -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">
            <i class="bi bi-cash-stack me-1" style="color:var(--fb-green);"></i>
            Payment History
            <span class="badge bg-secondary ms-1"><?= count($payments) ?></span>
        </span>
        <?php if (!$fullyPaid): ?>
        <button type="button" class="btn btn-sm btn-gold"
                data-bs-toggle="modal" data-bs-target="#addPaymentModal">
            <i class="bi bi-plus-lg me-1"></i> Add Payment
        </button>
        <?php else: ?>
        <span class="badge bg-success">
            <i class="bi bi-check-circle-fill me-1"></i> Fully Paid
        </span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($payments)): ?>
            <div class="text-center text-muted py-4 small fst-italic">No payments recorded yet.</div>
        <?php else: ?>
            <?php foreach ($payments as $pmt): ?>
            <div class="pmt-item">
                <div class="pmt-date">
                    <?= h((new DateTime($pmt['payment_date']))->format('d M Y')) ?>
                </div>
                <div class="pmt-body">
                    <?php if ($pmt['transaction_ref']): ?>
                        <div class="pmt-ref"><i class="bi bi-hash"></i> <?= h($pmt['transaction_ref']) ?></div>
                    <?php endif; ?>
                    <?php if ($pmt['note']): ?>
                        <div class="pmt-note"><?= h($pmt['note']) ?></div>
                    <?php endif; ?>
                    <?php if ($pmt['received_by_username']): ?>
                        <div class="pmt-by">By <?= h($pmt['received_by_username']) ?> · <?= h(fmt_dt($pmt['created_at'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="pmt-amount">৳<?= number_format((float)$pmt['paid_amount'], 0) ?></div>
                <?php if ($isAdmin): ?>
                <form method="POST" action="gold_sale_edit.php?id=<?= $saleId ?>"
                      onsubmit="return confirm('Delete this payment record?')"
                      class="ms-2">
                    <input type="hidden" name="action"     value="delete_payment">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="payment_id" value="<?= (int)$pmt['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete payment">
                        <i class="bi bi-trash3" style="font-size:.75rem;"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
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
        <form method="POST" action="gold_sale_edit.php?id=<?= $saleId ?>">
            <input type="hidden" name="action"     value="save_note">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <textarea class="form-control mb-2" name="note" rows="3"
                      placeholder="Optional note…"><?= h($sale['note'] ?? '') ?></textarea>
            <button type="submit" class="btn btn-gold btn-sm">
                <i class="bi bi-save-fill me-1"></i> Save Note
            </button>
        </form>
    </div>
</div>

<?php if (!$fullyPaid): ?>
<!-- ================================================================
     ADD PAYMENT MODAL (all logged-in users)
================================================================ -->
<div class="modal fade" id="addPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--fb-green);color:#fff;">
                <h5 class="modal-title">
                    <i class="bi bi-cash-stack me-2"></i>Add Payment — Sale #<?= $saleId ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="gold_sale_edit.php?id=<?= $saleId ?>">
                <input type="hidden" name="action"     value="add_payment">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <div class="modal-body">

                    <!-- Current due summary -->
                    <div class="alert alert-warning py-2 mb-3" style="font-size:.85rem;">
                        <div class="d-flex justify-content-between">
                            <span>Total Amount:</span>
                            <strong>৳<?= number_format((float)$sale['total_amount'], 0) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Total Paid:</span>
                            <strong class="text-success">৳<?= number_format((float)$totalPaid, 0) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between border-top pt-1 mt-1">
                            <span class="fw-bold">Due Amount:</span>
                            <strong class="text-danger" id="modalCurrentDue">৳<?= number_format($dueAmount, 0) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between border-top pt-1 mt-1" id="dueAfterRow" style="display:none;">
                            <span class="fw-bold">Due After This Payment:</span>
                            <strong id="dueAfterValue">—</strong>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Paid Amount (BDT) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text" style="background:var(--fb-green);color:#fff;border-color:var(--fb-green);">৳</span>
                            <input type="number" name="paid_amount" id="salePayAmountInput" class="form-control"
                                   min="0.01" max="<?= $dueAmount ?>" step="0.01" required
                                   placeholder="Enter payment amount"
                                   value="<?= number_format($dueAmount, 2, '.', '') ?>">
                        </div>
                        <div class="form-text" id="salePayAmountHint"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" class="form-control" required
                               value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Transaction Reference
                            <small class="fw-normal text-muted">(cheque no., bank ref., MFS trxn ID)</small>
                        </label>
                        <input type="text" name="transaction_ref" class="form-control"
                               placeholder="Optional reference…">
                    </div>

                    <div class="mb-0">
                        <label class="form-label fw-semibold">Note <small class="fw-normal text-muted">(optional)</small></label>
                        <input type="text" name="payment_note" class="form-control"
                               placeholder="e.g. bKash, cash, bank transfer…">
                    </div>

                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-gold btn-sm" id="btnRecordSalePayment">
                        <i class="bi bi-save-fill me-1"></i> Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

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
                    Edit Sale Items — #<?= $saleId ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" action="gold_sale_edit.php?id=<?= $saleId ?>" id="editItemsForm">
                <input type="hidden" name="action"     value="save_items">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                <div class="modal-body">

                    <!-- Pure gold price -->
                    <div class="row g-3 mb-4">
                        <div class="col-sm-12">
                            <label class="form-label small fw-semibold mb-1">
                                Pure Gold Price (24k / Vori)
                                <small class="text-muted fw-normal">(BDT)</small>
                            </label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"
                                      style="background:var(--fb-green);color:#fff;border-color:var(--fb-green);">৳</span>
                                <input type="number" name="pure_gold_price" id="pureGoldPriceInput"
                                       min="1" step="1"
                                       value="<?= h((int)$sale['pure_gold_price']) ?>"
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
                                       placeholder="e.g. 18, 22, 24"
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
                                    <td class="l-label">Already Paid</td>
                                    <td class="l-val" id="previewPaid">৳<?= number_format((float)$totalPaid, 0) ?></td>
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
const ITEM_COUNT  = <?= count($items) ?>;
const TOTAL_PAID  = <?= (float)$totalPaid ?>;

function tradToGrams(v,a,r,p){
    return v*G_VORI + a*G_ANA + r*G_ROTI + p*G_POINT;
}
function fmtBDT(n){ return '৳' + Math.round(n).toLocaleString('en-BD'); }

function getItemInputs(idx){
    return {
        v: parseInt(document.querySelector(`[name="items[${idx}][vori]"]`)?.value)    || 0,
        a: parseInt(document.querySelector(`[name="items[${idx}][ana]"]`)?.value)     || 0,
        r: parseInt(document.querySelector(`[name="items[${idx}][roti]"]`)?.value)    || 0,
        p: parseInt(document.querySelector(`[name="items[${idx}][point]"]`)?.value)   || 0,
        k: parseFloat(document.querySelector(`[name="items[${idx}][purity]"]`)?.value) || 0,
    };
}

function getPureGoldPrice(){
    return Math.max(0, parseFloat(document.getElementById('pureGoldPriceInput').value) || 0);
}

function recalcItem(idx){
    const {v,a,r,p,k} = getItemInputs(idx);
    const price = (tradToGrams(v,a,r,p) / G_VORI) * (k / 24) * getPureGoldPrice();
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
    const due = total - TOTAL_PAID;
    document.getElementById('previewTotal').textContent = fmtBDT(total);
    document.getElementById('previewDue').textContent   = fmtBDT(Math.max(0, due));
}

document.getElementById('editModal').addEventListener('shown.bs.modal', recalcAll);
</script>

<?php else: ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>

<?php if (!$fullyPaid): ?>
<script>
'use strict';
// ── Add Payment modal: live due calculation (all logged-in users) ──
(function initSalePaymentModal(){
    const DUE = <?= $dueAmount ?>;
    const inp  = document.getElementById('salePayAmountInput');
    const btn  = document.getElementById('btnRecordSalePayment');
    const row  = document.getElementById('dueAfterRow');
    const val  = document.getElementById('dueAfterValue');
    const hint = document.getElementById('salePayAmountHint');
    if (!inp) return;

    inp.addEventListener('input', function(){
        const entered   = parseFloat(this.value) || 0;
        const remaining = DUE - entered;

        btn.disabled = !(entered > 0 && entered <= DUE + 0.009);

        if (entered <= 0) {
            row.style.display = 'none';
            hint.textContent = entered < 0 ? 'Payment cannot be negative.' : '';
            hint.className    = 'form-text text-danger';
            return;
        }

        row.style.display = '';

        if (remaining <= 0.009) {
            val.textContent  = '৳0 — Fully settled';
            val.className    = 'text-success';
            hint.textContent = '';
        } else if (remaining < 0) {
            val.textContent  = 'Overpay by ৳' + Math.round(Math.abs(remaining)).toLocaleString('en-BD');
            val.className    = 'text-danger';
            hint.textContent = 'Amount exceeds remaining due.';
            hint.className   = 'form-text text-danger';
        } else {
            val.textContent  = '৳' + Math.round(remaining).toLocaleString('en-BD');
            val.className    = 'text-danger';
            hint.textContent = '';
        }
    });

    document.getElementById('addPaymentModal')
        .addEventListener('shown.bs.modal', () => inp.dispatchEvent(new Event('input')));
})();
</script>
<?php endif; ?>

</div>
</div>
</body>
</html>