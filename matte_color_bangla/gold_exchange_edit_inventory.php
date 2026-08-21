<?php
/**
 * gold_exchange_edit_inventory.php  (TEST COPY — rename to gold_exchange_edit.php when done)
 * FineBullion Desk — Gold Exchange detail & edit
 *
 * Pure PHP + mysqli. No AJAX. Page-load renders everything.
 * POST → PRG (Post/Redirect/Get) to prevent duplicate submit on refresh.
 * Admin: edit existing items only (no add/remove).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/inventory_lib.php';

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
    return "{$t['v']} ভ {$t['a']} আ {$t['r']} র {$t['p']} প";
}

function trad_to_grams(int $v, int $a, int $r, int $p): float {
    return ($v * G_VORI) + ($a * G_ANA) + ($r * G_ROTI) + ($p * G_POINT);
}

function loss_points(float $lossGrams): int {
    return (int) round($lossGrams / G_POINT);
}

function fmt_dt(?string $s): string {
    if (!$s) return '—';
    return (new DateTime($s))->format('d M Y, g:i A');
}

function h($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

// -----------------------------------------------------------------------
// Require valid ID
// -----------------------------------------------------------------------
$exchangeId = (int)($_GET['id'] ?? 0);
if ($exchangeId <= 0) {
    header('Location: gold_exchange_list.php');
    exit;
}

$isAdmin = is_admin();

// -----------------------------------------------------------------------
// Live 24K stock (AJAX, GET) — polled by the edit modal so it can warn
// before submit if the recalculated final pure gold would exceed what's
// in inventory. Read-only, no lock (FOR UPDATE only happens inside the
// save_items transaction below).
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'stock_24k') {
    $stmt = mysqli_prepare($conn, "SELECT left_weight FROM inventory WHERE purity = 24.00");
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $left = $row ? (float)$row['left_weight'] : 0.0;

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'          => true,
        'left_weight'      => $left,
        'left_weight_trad' => fmt_trad($left),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------
// POST → process → PRG redirect (prevents duplicate on F5)
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // CSRF token check
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash_error'] = 'অনুরোধটি সঠিক নয়। আবার চেষ্টা করুন।';
        header("Location: gold_exchange_edit_inventory.php?id={$exchangeId}");
        exit;
    }

    // ---- Save note (any logged-in user) ---------------------------------
    if ($action === 'save_note') {
        $note = trim($_POST['note'] ?? '') ?: null;
        $stmt = mysqli_prepare($conn,
            "UPDATE gold_exchanges SET note = ?, updated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $note, $exchangeId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['flash_success'] = 'নোট সফলভাবে আপডেট করা হয়েছে।';
        header("Location: gold_exchange_edit_inventory.php?id={$exchangeId}");
        exit;
    }

    // ---- Save items (admin only) ----------------------------------------
    if ($action === 'save_items' && $isAdmin) {
        $rawItems = $_POST['items'] ?? [];
        $lossRate = isset($_POST['loss_rate']) && $_POST['loss_rate'] !== ''
                    ? max(0.0, (float)$_POST['loss_rate']) : 1.0;

        $errors    = [];
        $calcItems = [];
        $totalPure = 0.0;

        foreach ($rawItems as $i => $item) {
            $n     = $i + 1;
            $itemId = (int)($item['id'] ?? 0);

            // Each submitted item must map to a real existing item for THIS exchange
            if ($itemId <= 0) {
                $errors[] = "আইটেম $n: আইডি পাওয়া যায়নি।";
                continue;
            }

            $vori  = (int)($item['vori']  ?? 0);
            $ana   = (int)($item['ana']   ?? 0);
            $roti  = (int)($item['roti']  ?? 0);
            $point = (int)($item['point'] ?? 0);
            $karat = (float)($item['karat'] ?? 0);

            if ($vori < 0)               $errors[] = "আইটেম $n: ভরি ঋণাত্মক হতে পারবে না।";
            if ($ana  < 0 || $ana  > 15) $errors[] = "আইটেম $n: আনা ০–১৫ হতে হবে।";
            if ($roti < 0 || $roti > 5)  $errors[] = "আইটেম $n: রতি ০–৫ হতে হবে।";
            if ($point < 0 || $point > 9) $errors[] = "আইটেম $n: পয়েন্ট ০–৯ হতে হবে।";
            if ($karat < 0.01 || $karat > 24) $errors[] = "আইটেম $n: মান (ক্যারেট) ০.০১–২৪ হতে হবে।";

            $grams = trad_to_grams($vori, $ana, $roti, $point);
            if ($grams <= 0) $errors[] = "আইটেম $n: ওজন শূন্যের বেশি হতে হবে।";

            $purity = round(($karat / 24) * 100, 2); // matches DB column decimal(5,2)

            $calcItems[] = [
                'id'               => $itemId,
                'old_gold_weight'  => $grams,
                'old_gold_purity'  => $purity,
                'pure_gold_weight' => $grams * ($purity / 100),
            ];
            $totalPure += $grams * ($purity / 100);
        }

        if (empty($calcItems) && empty($errors)) {
            $errors[] = 'সংরক্ষণ করার মতো কোনো আইটেম নেই।';
        }

        if (empty($errors)) {
            // Re-calculate summary
            $lossPointsCeil = (int) ceil(($totalPure / G_VORI) * $lossRate);
            $lossGrams      = $lossPointsCeil * G_POINT;
            $finalPure      = max(0.0, $totalPure - $lossGrams);

            mysqli_begin_transaction($conn);
            try {
                // Verify every submitted item ID actually belongs to this exchange
                // before touching anything
                $ids = array_column($calcItems, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $chk = mysqli_prepare($conn,
                    "SELECT COUNT(*) FROM gold_exchange_items
                     WHERE gold_exchange_id = ? AND id IN ($placeholders)");
                $bindTypes = 'i' . str_repeat('i', count($ids));
                $bindArgs  = array_merge([$exchangeId], $ids);
                mysqli_stmt_bind_param($chk, $bindTypes, ...$bindArgs);
                mysqli_stmt_execute($chk);
                mysqli_stmt_bind_result($chk, $matchCount);
                mysqli_stmt_fetch($chk);
                mysqli_stmt_close($chk);

                if ((int)$matchCount !== count($ids)) {
                    throw new \RuntimeException('আইটেম আইডি অমিল — সিস্টেম সিকিউরিটি জনিত সমস্যা।');
                }

                // Lock the exchange header row and read its CURRENT
                // final_pure_gold before changing anything, so we know the
                // net delta to apply against 24K inventory.
                $oldStmt = mysqli_prepare($conn,
                    "SELECT final_pure_gold FROM gold_exchanges WHERE id = ? FOR UPDATE");
                mysqli_stmt_bind_param($oldStmt, 'i', $exchangeId);
                mysqli_stmt_execute($oldStmt);
                $oldRow = mysqli_fetch_assoc(mysqli_stmt_get_result($oldStmt));
                mysqli_stmt_close($oldStmt);
                $oldFinalPure = (float)($oldRow['final_pure_gold'] ?? 0.0);

                // Update each item (UPDATE, not DELETE+INSERT → safe on duplicate submit)
                $updItem = mysqli_prepare($conn,
                    "UPDATE gold_exchange_items
                     SET old_gold_weight = ?, old_gold_purity = ?, pure_gold_weight = ?
                     WHERE id = ? AND gold_exchange_id = ?");

                foreach ($calcItems as $ci) {
                    mysqli_stmt_bind_param($updItem, 'dddii',
                        $ci['old_gold_weight'],
                        $ci['old_gold_purity'],
                        $ci['pure_gold_weight'],
                        $ci['id'],
                        $exchangeId);
                    mysqli_stmt_execute($updItem);
                }
                mysqli_stmt_close($updItem);

                // Update exchange summary
                $updEx = mysqli_prepare($conn,
                    "UPDATE gold_exchanges
                     SET total_pure_gold = ?, loss = ?, final_pure_gold = ?,
                         loss_rate_points_per_vori = ?, updated_at = NOW()
                     WHERE id = ?");
                mysqli_stmt_bind_param($updEx, 'ddddi',
                    $totalPure, $lossGrams, $finalPure, $lossRate, $exchangeId);
                mysqli_stmt_execute($updEx);
                mysqli_stmt_close($updEx);

                // Apply the net delta on the 24K inventory row. Only the
                // header's final_pure_gold matters for inventory (per the
                // create-flow rule) — a single before/after comparison on
                // one number, not per-item purity tracking.
                $delta24k = $finalPure - $oldFinalPure;
                if ($delta24k > 0.0005) {
                    $row = inventory_lock_row($conn, 24.00);
                    if (!$row) throw new InventoryException('অজানা ক্যারেট (24K)।');
                    $left = (float)$row['left_weight'];
                    if ($left - $delta24k < -0.0005) {
                        throw new InventoryException(sprintf(
                            'পর্যাপ্ত ২৪K সোনা মজুদ নেই এই পরিবর্তনের জন্য। বর্তমান মজুদ: %s, প্রয়োজনীয় অতিরিক্ত: %s',
                            fmt_trad_g($left), fmt_trad_g($delta24k)
                        ));
                    }
                }
                inventory_apply_delta($conn, 24.00, $delta24k);

                mysqli_commit($conn);
                $_SESSION['flash_success'] = 'আইটেমসমূহ সফলভাবে আপডেট করা হয়েছে।';
            } catch (InventoryException $e) {
                mysqli_rollback($conn);
                $_SESSION['flash_error'] = $e->getMessage();
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['flash_error'] = 'সংরক্ষণ করতে ব্যর্থ হয়েছে: ' . h($e->getMessage());
            }
        } else {
            $_SESSION['flash_error'] = implode('<br>', $errors);
        }

        header("Location: gold_exchange_edit_inventory.php?id={$exchangeId}");
        exit;
    }

    // Fallback — unknown action
    header("Location: gold_exchange_edit_inventory.php?id={$exchangeId}");
    exit;
}

// -----------------------------------------------------------------------
// Generate CSRF token for GET requests
// -----------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// -----------------------------------------------------------------------
// Flash messages (set before redirect, consumed here)
// -----------------------------------------------------------------------
$postSuccess = $_SESSION['flash_success'] ?? '';
$postError   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// -----------------------------------------------------------------------
// Fetch exchange
// -----------------------------------------------------------------------
$stmt = mysqli_prepare($conn,
    "SELECT ge.id, ge.customer_id,
            c.name    AS customer_name,
            c.phone   AS customer_phone,
            c.address AS customer_address,
            ge.total_pure_gold, ge.loss, ge.final_pure_gold,
            ge.loss_rate_points_per_vori, ge.note,
            ge.created_at, ge.updated_at,
            u.username AS created_by_username
     FROM   gold_exchanges ge
     JOIN   customers      c ON c.id = ge.customer_id
     LEFT   JOIN users     u ON u.id = ge.created_by
     WHERE  ge.id = ?
     LIMIT  1");
if (!$stmt) { die('ডাটাবেস ত্রুটি: ' . mysqli_error($conn)); }
mysqli_stmt_bind_param($stmt, 'i', $exchangeId);
mysqli_stmt_execute($stmt);
$ex = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$ex) {
    header('Location: gold_exchange_list.php');
    exit;
}

// Fetch items
$iStmt = mysqli_prepare($conn,
    "SELECT id, old_gold_weight, old_gold_purity, pure_gold_weight
     FROM   gold_exchange_items
     WHERE  gold_exchange_id = ?
     ORDER  BY id ASC");
mysqli_stmt_bind_param($iStmt, 'i', $exchangeId);
mysqli_stmt_execute($iStmt);
$items = mysqli_fetch_all(mysqli_stmt_get_result($iStmt), MYSQLI_ASSOC);
mysqli_stmt_close($iStmt);

$lossRate      = (float)$ex['loss_rate_points_per_vori'];
$lossPointsVal = loss_points((float)$ex['loss']);
?>
<!DOCTYPE html>
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>এক্সচেঞ্জ #<?= $exchangeId ?> — ফাইনবুলিয়ন ডেস্ক</title>
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

/* Page Header */
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
.page-header h1, .page-header h5 {
    color: var(--text-on-navy);
    margin: 0;
    font-weight: 700;
    font-size: 22px;
}
.page-header small, .page-header .subtitle {
    color: rgba(255, 255, 255, 0.78);
    font-size: 13px;
}

/* Inset Container */
.page-inset {
    padding-left: 1.5rem;
    padding-right: 1.5rem;
}

/* Cards & Containers */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 14px;
    box-shadow: var(--shadow);
    padding: 1rem 1.1rem;
    margin-bottom: 1.25rem;
}
.card-header-custom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border-default);
}
.card-title-custom {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

/* Detail Labels */
.detail-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 700;
    color: var(--text-secondary);
}
.detail-val {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
}

/* Buttons */
.btn {
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    padding: 0.55rem 1.1rem;
}
.btn-sm {
    padding: 0.4rem 0.8rem;
    font-size: 13px;
}
.btn-primary {
    background: var(--navy);
    border: 1.5px solid var(--navy);
    color: #fff;
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
}
.btn-secondary:hover {
    background: var(--bg-hover);
    border-color: var(--teal);
    color: var(--navy);
}
.btn-ghost-light {
    background: rgba(255, 255, 255, 0.12);
    border: 1.5px solid rgba(255, 255, 255, 0.5);
    color: #fff;
}
.btn-ghost-light:hover {
    background: #fff;
    color: var(--navy);
    border-color: #fff;
}

/* Status Chips / Badges */
.chip {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}
.chip-paid { background: #EAF3EE; color: var(--success); }
.chip-due { background: #FBECEC; color: var(--danger); }
.chip-total { background: var(--sky); color: var(--navy); }

/* Tables */
.table {
    margin-bottom: 0;
}
thead th {
    background: var(--beige) !important;
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    border-bottom: 1.5px solid var(--border-default) !important;
    padding: 0.65rem 0.75rem;
}
tbody td {
    padding: 0.65rem 0.75rem;
    border-bottom: 1px solid var(--border-default);
    font-size: 13.5px;
    color: var(--text-primary);
}
tbody tr:hover {
    background: var(--bg-hover);
}

/* Summary Ledger */
.ledger {
    border: 1px solid var(--border-default);
    border-radius: 8px;
    overflow: hidden;
}
.ledger td {
    padding: 0.65rem 0.75rem;
    border-bottom: 1px solid var(--border-default);
}
.l-label {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-secondary);
    width: 1%;
    white-space: nowrap;
}
.l-rate {
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 400;
}
.l-val {
    font-weight: 700;
    font-size: 14px;
    text-align: right;
    color: var(--text-primary);
}
.l-total td { background-color: var(--bg-hover); }
.l-total .l-label, .l-total .l-val { color: var(--navy); }
.l-loss td { background-color: #FBECEC; }
.l-loss .l-label, .l-loss .l-val { color: var(--danger); }
.l-final td { background-color: var(--sky); border-bottom: none; }
.l-final .l-label, .l-final .l-val { color: var(--navy); font-size: 15px; }

/* Input Fields */
.form-control, .form-select {
    background: #fff;
    border: 1.5px solid var(--border-default);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    padding: 0.55rem 0.75rem;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.form-control:focus, .form-select:focus {
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(86, 124, 141, 0.15);
    outline: none;
}
label, .form-label {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-bottom: 0.3rem;
}

/* Modals */
.modal-content {
    border-radius: 14px;
    border: 1px solid var(--border-default);
    box-shadow: var(--shadow);
    overflow: hidden;
}
.modal-header {
    background: var(--navy);
    color: var(--text-on-navy);
    padding: 1rem 1.25rem;
}
.modal-title {
    color: var(--text-on-navy);
    font-weight: 700;
    font-size: 18px;
}
.modal-header .btn-close {
    filter: brightness(0) invert(1);
}

/* Edit Modal Cards */
.edit-item-card {
    border: 1px solid var(--border-default);
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    background: #fff;
    position: relative;
}
.edit-item-badge {
    position: absolute;
    top: -10px;
    left: 12px;
    background: var(--navy);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 0.15rem 0.5rem;
    border-radius: 8px;
}
.item-pure-preview {
    background: var(--sky);
    border-radius: 8px;
    padding: 0.5rem 0.75rem;
    font-size: 13px;
    color: var(--navy);
    font-weight: 600;
}

.item-fields-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.5rem;
}
.item-fields-row .field-col label {
    display: block;
    font-size: 11px;
    margin-bottom: 0.15rem;
}
.item-fields-row .field-col input {
    text-align: center;
    padding-left: 0.25rem;
    padding-right: 0.25rem;
}

/* Stock Status Display */
.stock-status-row {
    border-radius: 8px;
    padding: 0.75rem 1rem;
    background: #EAF3EE;
    border: 1px solid var(--border-default);
}
.stock-status-row .ss-top { display: flex; justify-content: space-between; align-items: center; }
.stock-status-row .ss-label { font-size: 12.5px; color: var(--text-secondary); font-weight: 700; }
.stock-status-row .ss-value { font-size: 14px; font-weight: 700; color: var(--success); }
.stock-status-row .ss-warning {
    display: flex; align-items: center;
    margin-top: 0.4rem; padding-top: 0.4rem;
    border-top: 1px dashed var(--border-default);
    font-size: 12px; font-weight: 600; color: var(--danger);
}
.stock-status-row.insufficient {
    background: #FBECEC;
}
.stock-status-row.insufficient .ss-value { color: var(--danger); }

/* Mobile Overrides */
@media (max-width: 576px) {
    .page-header {
        padding: 0.85rem 1.1rem;
        border-radius: 0 0 14px 14px;
    }
    .page-header h1, .page-header h5 { font-size: 18px; }
    .page-header small { font-size: 12px; }
    .page-inset { padding-left: 1rem; padding-right: 1rem; }
    .card { padding: 0.85rem; }
    .btn { font-size: 13.5px; padding: 0.6rem 1rem; }
    .form-control, .form-select { font-size: 16px; padding: 0.6rem 0.8rem; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0">

<!-- ================================================================
     PAGE HEADER
================================================================ -->
<div class="page-header mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 style="width:100%;">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="gold_exchange_list.php" class="btn btn-sm btn-ghost-light">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <h5 class="mb-0">
                    <i class="bi bi-arrow-left-right me-1"></i>
                    সোনা বদল
                    <span style="opacity:.75;">&nbsp;#<?= $exchangeId ?></span>
                </h5>
            </div>
            <small class="subtitle">সোনা বদল বিস্তারিত — ফাইনবুলিয়ন ডেস্ক</small>
        </div>
        <div class="text-end">
            <div style="font-size:11px; text-transform:uppercase; color:rgba(255,255,255,.75); font-weight:700;">তৈরি করেছেন</div>
            <div class="fw-semibold" style="font-size:14px;">
                <i class="bi bi-person-circle me-1"></i><?= h($ex['created_by_username'] ?? '—') ?>
            </div>
            <div style="font-size:12px; color:rgba(255,255,255,.75);">
                <?= h(fmt_dt($ex['created_at'])) ?>
            </div>
        </div>
    </div>
</div>

<div class="page-inset">

<?php if ($postSuccess): ?>
<div class="alert alert-success alert-dismissible fade show border-0 text-white mb-3" style="background:var(--success); border-radius:8px;" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i><?= h($postSuccess) ?>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($postError): ?>
<div class="alert alert-danger alert-dismissible fade show border-0 text-white mb-3" style="background:var(--danger); border-radius:8px;" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $postError ?>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ================================================================
     CUSTOMER + DETAIL
================================================================ -->
<div class="card">
    <div class="card-header-custom">
        <h2 class="card-title-custom">
            <i class="bi bi-person-fill me-1" style="color:var(--teal);"></i>
            কাস্টমার বিস্তারিত
        </h2>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="mb-2">
                <div class="detail-label">কাস্টমারের নাম</div>
                <div class="detail-val"><?= h($ex['customer_name']) ?></div>
            </div>
            <div class="mb-2">
                <div class="detail-label">ফোন নম্বর</div>
                <div class="detail-val"><?= h($ex['customer_phone'] ?: '—') ?></div>
            </div>
            <div>
                <div class="detail-label">ঠিকানা</div>
                <div class="detail-val"><?= h($ex['customer_address'] ?: '—') ?></div>
            </div>
        </div>

        <div class="col-md-6 border-start-md ps-md-4">
            <div class="mb-2">
                <div class="detail-label">তারিখ ও সময়</div>
                <div class="detail-val"><?= h(fmt_dt($ex['created_at'])) ?></div>
            </div>
            <div class="mb-2">
                <div class="detail-label">তৈরি করেছেন</div>
                <div class="detail-val"><?= h($ex['created_by_username'] ?? '—') ?></div>
            </div>
            <?php if ($ex['updated_at'] && $ex['updated_at'] !== $ex['created_at']): ?>
            <div>
                <div class="detail-label">সর্বশেষ আপডেট</div>
                <div class="detail-val" style="color:var(--text-secondary);">
                    <?= h(fmt_dt($ex['updated_at'])) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ================================================================
     GOLD ITEMS TABLE
================================================================ -->
<div class="card">
    <div class="card-header-custom">
        <h2 class="card-title-custom">
            <i class="bi bi-gem me-1" style="color:var(--teal);"></i>
            পুরাতন আইটেমসমূহ
            <span class="chip chip-total ms-1"><?= count($items) ?></span>
        </h2>
        <?php if ($isAdmin): ?>
        <button type="button" class="btn btn-sm btn-primary"
                data-bs-toggle="modal" data-bs-target="#editModal">
            <i class="bi bi-pencil-square me-1"></i> এডিট করুন
        </button>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th style="width:20px;">#</th>
                    <th>জমা (ভ-আ-র-প)</th>
                    <th style="width: 80px;">ক্যারেট</th>
                    <th>পাকা (ভ-আ-র-প)</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">কোনো তথ্য পাওয়া যায়নি।</td></tr>
            <?php else: ?>
                <?php foreach ($items as $idx => $it):
                    $karat = round(((float)$it['old_gold_purity'] / 100) * 24, 2);
                ?>
                <tr>
                    <td class="text-secondary small"><?= $idx + 1 ?></td>
                    <td><span class="chip chip-paid"><?= h(fmt_trad((float)$it['old_gold_weight'])) ?></span></td>
                    <td><span class="chip chip-total"><?= h($karat) ?> K</span></td>
                    <td><span class="chip chip-due"><?= h(fmt_trad((float)$it['pure_gold_weight'])) ?></span></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ================================================================
     EXCHANGE SUMMARY
================================================================ -->
<div class="card">
    <div class="card-header-custom">
        <h2 class="card-title-custom">
            <i class="bi bi-calculator me-1" style="color:var(--teal);"></i>
            হিসাব বিবরণী
        </h2>
    </div>
    <table class="table table-sm ledger">
        <tbody>
            <tr class="l-total">
                <td class="l-label">মোট পাকা সোনা</td>
                <td class="l-val"><?= h(fmt_trad((float)$ex['total_pure_gold'])) ?></td>
            </tr>
            <tr class="l-loss">
                <td class="l-label">
                    লস
                    <span class="l-rate">
                        (<?= $lossPointsVal ?> পয়েন্ট @ <?= h($lossRate) ?> প/ভ)
                    </span>
                </td>
                <td class="l-val"><?= h(fmt_trad((float)$ex['loss'])) ?></td>
            </tr>
            <tr class="l-final">
                <td class="l-label">চূড়ান্ত পাকা সোনা</td>
                <td class="l-val"><?= h(fmt_trad((float)$ex['final_pure_gold'])) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<!-- ================================================================
     NOTE
================================================================ -->
<div class="card">
    <div class="card-header-custom">
        <h2 class="card-title-custom">
            <i class="bi bi-pencil-square me-1" style="color:var(--teal);"></i>
            নোট / মন্তব্য
        </h2>
    </div>
    <form method="POST" action="gold_exchange_edit_inventory.php?id=<?= $exchangeId ?>">
        <input type="hidden" name="action"     value="save_note">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <textarea class="form-control mb-3" name="note" rows="3"
                  placeholder="ঐচ্ছিক নোট…"><?= h($ex['note'] ?? '') ?></textarea>
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-save-fill me-1"></i> নোট সংরক্ষণ করুন
        </button>
    </form>
</div>

</div><!-- /page-inset -->

<?php if ($isAdmin): ?>
<!-- ================================================================
     EDIT ITEMS MODAL (admin only)
================================================================ -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square me-2"></i>
                    আইটেম এডিট করুন — #<?= $exchangeId ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" action="gold_exchange_edit_inventory.php?id=<?= $exchangeId ?>" id="editItemsForm">
                <input type="hidden" name="action"     value="save_items">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                <div class="modal-body">

                    <!-- Loss rate -->
                    <div class="mb-4">
                        <label class="form-label">
                            লসের হার
                            <span class="text-secondary fw-normal" style="text-transform:none;">(ভরি প্রতি পয়েন্ট)</span>
                        </label>
                        <input type="number" name="loss_rate" id="lossRateInput"
                               min="0" step="0.001" value="<?= h($lossRate) ?>"
                               class="form-control"
                               oninput="recalcSummary()">
                    </div>

                    <!-- Existing items -->
                    <?php if (empty($items)): ?>
                        <div class="text-muted text-center py-3">এডিট করার মতো কোনো আইটেম নেই।</div>
                    <?php else: ?>
                        <?php foreach ($items as $idx => $it):
                            $karat = round(((float)$it['old_gold_purity'] / 100) * 24, 2);
                            $trad  = grams_to_trad((float)$it['old_gold_weight']);
                        ?>
                        <div class="edit-item-card">
                            <span class="edit-item-badge">আইটেম <?= $idx + 1 ?></span>
                            <input type="hidden" name="items[<?= $idx ?>][id]"
                                   value="<?= (int)$it['id'] ?>">

                            <div class="item-fields-row mt-2">
                                <div class="field-col">
                                    <label>ভরি</label>
                                    <input type="number" name="items[<?= $idx ?>][vori]"
                                           class="form-control"
                                           min="0" step="1" inputmode="numeric"
                                           value="<?= $trad['v'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                                <div class="field-col">
                                    <label>আনা</label>
                                    <input type="number" name="items[<?= $idx ?>][ana]"
                                           class="form-control"
                                           min="0" max="15" step="1" inputmode="numeric"
                                           value="<?= $trad['a'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                                <div class="field-col">
                                    <label>রতি</label>
                                    <input type="number" name="items[<?= $idx ?>][roti]"
                                           class="form-control"
                                           min="0" max="5" step="1" inputmode="numeric"
                                           value="<?= $trad['r'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                                <div class="field-col">
                                    <label>পয়েন্ট</label>
                                    <input type="number" name="items[<?= $idx ?>][point]"
                                           class="form-control"
                                           min="0" max="9" step="1" inputmode="numeric"
                                           value="<?= $trad['p'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                            </div>

                            <div class="mt-2">
                                <label>সোনার মান (ক্যারেট)</label>
                                <input type="number" name="items[<?= $idx ?>][karat]"
                                       class="form-control"
                                       min="0.01" max="24" step="0.01"
                                       placeholder="যেমন: ২২"
                                       value="<?= h($karat) ?>"
                                       oninput="recalcItem(<?= $idx ?>)">
                            </div>

                            <div class="item-pure-preview mt-2" id="itemPreview_<?= $idx ?>">
                                পাকা সোনার ওজন: <?= h(fmt_trad((float)$it['pure_gold_weight'])) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Live summary preview -->
                    <div class="mt-3 pt-3 border-top">
                        <div class="detail-label mb-2">সারসংক্ষেপ প্রিভিউ</div>
                        <table class="table table-sm ledger">
                            <tbody>
                                <tr class="l-total">
                                    <td class="l-label">মোট পাকা সোনা</td>
                                    <td class="l-val" id="previewTotal">—</td>
                                </tr>
                                <tr class="l-loss">
                                    <td class="l-label">
                                        লস
                                        <span class="l-rate" id="previewLossRate"></span>
                                    </td>
                                    <td class="l-val" id="previewLoss">—</td>
                                </tr>
                                <tr class="l-final">
                                    <td class="l-label">চূড়ান্ত পাকা সোনা</td>
                                    <td class="l-val" id="previewFinal">—</td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="stock-status-row mt-3" id="stockStatusRow">
                            <div class="ss-top">
                                <span class="ss-label"><i class="bi bi-box-seam me-1"></i> বর্তমান ২৪K মজুদ</span>
                                <span class="ss-value" id="sumStock24k">লোড হচ্ছে…</span>
                            </div>
                            <div class="ss-warning" id="stockWarning" style="display:none;">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                <span id="stockWarningText">পর্যাপ্ত মজুদ নেই</span>
                            </div>
                        </div>
                    </div>

                </div><!-- /modal-body -->

                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> বাতিল
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm" id="btnSaveItems">
                        <i class="bi bi-save-fill me-1"></i> পরিবর্তন সংরক্ষণ করুন
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

const OLD_FINAL_PURE_GRAMS = <?= json_encode((float)$ex['final_pure_gold']) ?>;

let stock24kGrams = null;
let stock24kTrad  = 'লোড হচ্ছে…';

async function loadStock24k() {
    try {
        const res = await fetch('gold_exchange_edit_inventory.php?id=<?= $exchangeId ?>&action=stock_24k', {
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
    recalcSummary();
}

function tradToGrams(v, a, r, p) {
    return v * G_VORI + a * G_ANA + r * G_ROTI + p * G_POINT;
}

function gramsToTrad(g) {
    g = Math.max(0, g);
    const EPS = 1e-9;
    let tv = g / G_VORI;
    let v  = Math.floor(tv + EPS);
    let ta = Math.max(0, tv - v) * 16;
    let a  = Math.floor(ta + EPS);
    let tr = Math.max(0, ta - a) * 6;
    let r  = Math.floor(tr + EPS);
    let p  = Math.round(Math.max(0, tr - r) * 10);
    if (p >= 10) { p -= 10; r++; }
    if (r >= 6)  { r -= 6;  a++; }
    if (a >= 16) { a -= 16; v++; }
    return `${v} ভ ${a} আ ${r} র ${p} প`;
}

function getItemInputs(idx) {
    return {
        v: parseInt(document.querySelector(`[name="items[${idx}][vori]"]`)?.value)   || 0,
        a: parseInt(document.querySelector(`[name="items[${idx}][ana]"]`)?.value)    || 0,
        r: parseInt(document.querySelector(`[name="items[${idx}][roti]"]`)?.value)   || 0,
        p: parseInt(document.querySelector(`[name="items[${idx}][point]"]`)?.value)  || 0,
        k: parseFloat(document.querySelector(`[name="items[${idx}][karat]"]`)?.value) || 0,
    };
}

function getLossRate() {
    return Math.max(0, parseFloat(document.getElementById('lossRateInput')?.value) || 0);
}

function recalcItem(idx) {
    const {v, a, r, p, k} = getItemInputs(idx);
    const oldGrams = tradToGrams(v, a, r, p);
    const pureGrams = oldGrams * (k / 24);
    const el = document.getElementById('itemPreview_' + idx);
    if (el) el.textContent = 'পাকা সোনার ওজন: ' + gramsToTrad(pureGrams);
    recalcSummary();
}

function recalcAll() {
    for (let i = 0; i < ITEM_COUNT; i++) recalcItem(i);
}

function recalcSummary() {
    let totalPureGrams = 0;
    for (let i = 0; i < ITEM_COUNT; i++) {
        const {v, a, r, p, k} = getItemInputs(i);
        totalPureGrams += tradToGrams(v, a, r, p) * (k / 24);
    }

    const lossRate = getLossRate();
    const lossPointsCeil = Math.ceil((totalPureGrams / G_VORI) * lossRate);
    const lossGrams = lossPointsCeil * G_POINT;
    const finalPureGrams = Math.max(0, totalPureGrams - lossGrams);

    const elTotal = document.getElementById('previewTotal');
    const elLoss = document.getElementById('previewLoss');
    const elLossRate = document.getElementById('previewLossRate');
    const elFinal = document.getElementById('previewFinal');

    if (elTotal) elTotal.textContent = gramsToTrad(totalPureGrams);
    if (elLoss) elLoss.textContent = gramsToTrad(lossGrams);
    if (elLossRate) elLossRate.textContent = `(${lossPointsCeil} পয়েন্ট @ ${lossRate} প/ভ)`;
    if (elFinal) elFinal.textContent = gramsToTrad(finalPureGrams);

    const stockRow     = document.getElementById('stockStatusRow');
    const stockValueEl = document.getElementById('sumStock24k');
    const warningEl     = document.getElementById('stockWarning');
    const warningTextEl = document.getElementById('stockWarningText');
    const saveBtn        = document.getElementById('btnSaveItems');

    stockValueEl.textContent = stock24kTrad;

    if (stock24kGrams === null) {
        stockRow.classList.remove('insufficient');
        warningEl.style.display = 'none';
        window.__stockInsufficient = false;
        if (saveBtn) saveBtn.disabled = false;
        return;
    }

    const delta24k = finalPureGrams - OLD_FINAL_PURE_GRAMS;
    const shortfall = delta24k - stock24kGrams;

    if (delta24k > 0.0005 && shortfall > 0.0005) {
        stockRow.classList.add('insufficient');
        warningEl.style.display = 'flex';
        warningTextEl.textContent =
            `পর্যাপ্ত ২৪K মজুদ নেই এই পরিবর্তনের জন্য — প্রয়োজনীয় অতিরিক্ত: ${gramsToTrad(delta24k)}, ঘাটতি: ${gramsToTrad(shortfall)}`;
        window.__stockInsufficient = true;
        if (saveBtn) saveBtn.disabled = true;
    } else {
        stockRow.classList.remove('insufficient');
        warningEl.style.display = 'none';
        window.__stockInsufficient = false;
        if (saveBtn) saveBtn.disabled = false;
    }
}

document.getElementById('editModal').addEventListener('shown.bs.modal', () => {
    recalcAll();
    loadStock24k();
});

document.getElementById('editItemsForm').addEventListener('submit', (e) => {
    if (window.__stockInsufficient) {
        e.preventDefault();
        alert('পর্যাপ্ত ২৪K মজুদ নেই — এই পরিবর্তন সংরক্ষণ করা যাবে না। ওজন/মান কমান অথবা আগে স্টক যোগ করুন।');
    }
});
</script>

<?php else: ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>

</div>
</div>
</body>
</html>