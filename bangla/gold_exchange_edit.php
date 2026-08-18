<?php
/**
 * gold_exchange_edit.php
 * FineBullion Desk — Gold Exchange detail & edit
 *
 * Pure PHP + mysqli. No AJAX. Page-load renders everything.
 * POST → PRG (Post/Redirect/Get) to prevent duplicate submit on refresh.
 * Admin: edit existing items only (no add/remove).
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
// POST → process → PRG redirect (prevents duplicate on F5)
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // CSRF token check
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash_error'] = 'অকার্যকর অনুরোধ। অনুগ্রহ করে আবার চেষ্টা করুন।';
        header("Location: gold_exchange_edit.php?id={$exchangeId}");
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
        header("Location: gold_exchange_edit.php?id={$exchangeId}");
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
                $errors[] = "আইটেম $n: আইডি পাওয়া যায়নি।";
                continue;
            }

            $vori  = (int)($item['vori']  ?? 0);
            $ana   = (int)($item['ana']   ?? 0);
            $roti  = (int)($item['roti']  ?? 0);
            $point = (int)($item['point'] ?? 0);
            $karat = (float)($item['karat'] ?? 0);

            if ($vori < 0)               $errors[] = "আইটেম $n: ভরির পরিমাণ ঋণাত্মক হতে পারবে না।";
            if ($ana  < 0 || $ana  > 15) $errors[] = "আইটেম $n: আনা ০০০–১৫ হতে হবে।";
            if ($roti < 0 || $roti > 5)  $errors[] = "আইটেম $n: রতি ০–৫ হতে হবে।";
            if ($point < 0 || $point > 9) $errors[] = "আইটেম $n: পয়েন্ট ০–৯ হতে হবে।";
            if ($karat < 0.01 || $karat > 24) $errors[] = "আইটেম $n: ক্যারেট ০.০১–২৪ হতে হবে।";

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

                mysqli_commit($conn);
                $_SESSION['flash_success'] = 'আইটেমসমূহ সফলভাবে আপডেট করা হয়েছে।';
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['flash_error'] = 'সংরক্ষণ করতে ব্যর্থ হয়েছে: ' . h($e->getMessage());
            }
        } else {
            $_SESSION['flash_error'] = implode('<br>', $errors);
        }

        header("Location: gold_exchange_edit.php?id={$exchangeId}");
        exit;
    }

    // Fallback — unknown action
    header("Location: gold_exchange_edit.php?id={$exchangeId}");
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
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>এক্সচেঞ্জ #<?= $exchangeId ?> — ফাইনবুলিয়ন ডেস্ক</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    /* Brand Foundation */
    --gold-deep: #c9973a;
    --gold-mid: #dcb04a;
    --gold-light: #e9cd7d;
    --ivory: #fbf8f2;
    --bronze-text: #3a2f1a;
    --muted: #9a8f76;
    --hairline: #ecdfb8;

    /* Jewel Tone Financial Status Colors */
    --status-paid-bg: #1b5238;      /* Deep Emerald (Paid / Impure / Loss) */
    --status-paid-light: #eaf4ee;   /* Soft Emerald Tint */
    --status-due-bg: #93292c;       /* Deep Ruby (Due / Pure / Outflow) */
    --status-due-light: #fbeceb;    /* Soft Ruby Tint */
    --status-total-bg: #b88328;     /* Rich Gold (Totals / Net Output) */
    --status-total-light: #fdf6e2;  /* Soft Gold Tint */
}
body  { background: var(--ivory); font-family: 'Inter', system-ui, -apple-system, sans-serif; color: var(--bronze-text); }

/* header — scoped strictly to .exchange-header and its children so this
   block is completely self-contained and immune to overrides from
   navbar.php or any external stylesheet. Full width, flush to the
   viewport top with no top/side margin. */
.exchange-header,
.exchange-header.mb-4 {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%) !important;
    color: #ffffff !important;
    border-radius: 0 0 20px 20px !important;
    min-height: 60px !important;
    max-height: 80px !important;
    padding: 0.85rem 1.75rem !important;
    margin: 0 !important;
    top: 0;
    width: 100% !important;
    max-width: 100% !important;
    display: flex !important;
    align-items: center !important;
    box-sizing: border-box;
    overflow: hidden;
}
.exchange-header h5,
.exchange-header h5 * { color: #ffffff !important; }
.exchange-header small { color: rgba(255,255,255,0.8) !important; }
.exchange-header .btn-outline-light {
    border-color: rgba(255,255,255,0.6);
    color: #ffffff;
}
.exchange-header .btn-outline-light:hover {
    background: rgba(255,255,255,0.15);
    border-color: #ffffff;
    color: #ffffff;
}

/* Inset spacing for content below the flush full-width header */
.page-inset { padding-left: 1.5rem; padding-right: 1.5rem; }

/* customer / detail card */
.detail-card {
    background: #ffffff; border: none;
    border-radius: 18px; padding: 1.25rem 1.5rem;
}
.detail-label {
    font-size: 0.78rem; color: var(--muted); font-weight: 500;
    white-space: nowrap;
}
.detail-val {
    font-size: 0.97rem; font-weight: 600; color: var(--bronze-text);
    word-break: break-word;
}

/* item table badges */
.badge-old   { background: var(--status-paid-light); color: var(--status-paid-bg); font-weight: 600; font-size: 0.82rem; }
.badge-karat { background: var(--status-total-light); color: var(--status-total-bg); font-weight: 600; font-size: 0.82rem; }
.badge-pure  { background: var(--status-due-light);  color: var(--status-due-bg);  font-weight: 600; font-size: 0.82rem; }

/* ledger */
.ledger { border: 1px solid var(--hairline); border-radius: 12px; overflow: hidden; }
.ledger td { padding: 0.6rem 0.9rem; border-bottom: 1px solid var(--hairline); vertical-align: middle; --bs-table-bg: transparent; }
.ledger tr:last-child td { border-bottom: none; }
.l-label { font-size: 0.82rem; color: var(--muted); width: 1%; white-space: nowrap; }
.l-rate  { color: var(--muted); font-size: 0.78rem; font-weight: 400; }
.l-val   { font-weight: 700; font-size: 0.95rem; text-align: right; letter-spacing: 0.01em; }
.l-total td  { background-color: var(--status-due-light) !important; }
.l-total .l-label, .l-total .l-val { color: var(--status-due-bg); }
.l-total .l-label { font-weight: 600; }
.l-loss  td  { background-color: #fdf1e0 !important; }
.l-loss  .l-label { color: #7a5417; font-weight: 600; }
.l-loss  .l-val   { color: #7a5417; }
.l-final td  { background-color: var(--status-total-bg) !important; border-bottom: none; }
.l-final .l-label { color: rgba(255,255,255,0.88); font-weight: 600; }
.l-final .l-val   { color: #fff; font-size: 1.05rem; }
.l-final .l-rate  { color: rgba(255,255,255,0.65); }

/* Primary action buttons (pill, solid gold, white text) */
.btn-gold, .btn-fb-primary {
    background: var(--gold-deep);
    border: 1.5px solid var(--gold-deep);
    color: #ffffff;
    font-weight: 700;
    border-radius: 999px;
}
.btn-gold:hover, .btn-fb-primary:hover { background: var(--gold-deep); border-color: var(--gold-deep); color: #fff; opacity: 0.92; }

/* Secondary / cancel buttons (pill, white, hairline border) */
.btn-secondary, .btn-fb-secondary {
    background: #ffffff;
    border: 1.5px solid var(--hairline);
    color: var(--muted);
    font-weight: 600;
    border-radius: 999px;
}
.btn-secondary:hover, .btn-fb-secondary:hover { background: #fdf7ec; border-color: var(--hairline); color: var(--bronze-text); }

/* cards */
.card {
    background: #ffffff;
    border: none;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(180, 140, 50, 0.12);
}
.card-header {
    background: #ffffff !important;
    border-bottom: 1px solid var(--hairline);
    border-radius: 18px 18px 0 0 !important;
    color: var(--bronze-text);
}

/* tables */
table.table thead.table-light th {
    background: var(--ivory) !important;
    color: var(--muted);
    text-transform: uppercase;
    font-size: 0.72rem;
    letter-spacing: 0.04em;
    border-bottom: 1.5px solid var(--hairline);
}
table.table td, table.table th { border-color: var(--hairline); }
table.table-hover tbody tr:hover { background-color: #fdf7ec; }

/* inputs */
.form-control, .input-group-text {
    border: 1.5px solid var(--hairline);
    border-radius: 10px;
    color: var(--bronze-text);
    background: #fff;
}
.form-control:focus { border-color: var(--gold-deep); box-shadow: 0 0 0 0.2rem rgba(201,151,58,0.15); }

/* modal */
.modal-content { border-radius: 18px; overflow: hidden; border: none; }
.modal-header { border-bottom: none; }

/* alerts */
.alert-success { background: var(--status-paid-light); border: 1px solid var(--status-paid-bg); color: var(--status-paid-bg); border-radius: 12px; }
.alert-danger  { background: var(--status-due-light);  border: 1px solid var(--status-due-bg);  color: var(--status-due-bg);  border-radius: 12px; }

/* edit modal item card — matches gold_exchange.php "gold-item-card" pattern */
.edit-item-card {
    border: 1.5px solid var(--hairline); border-radius: 14px;
    padding: 1rem 1.1rem; margin-bottom: 1rem;
    background: #ffffff; position: relative;
}
.edit-item-badge {
    position: absolute; top: -10px; left: 14px;
    background: var(--gold-deep); color: #ffffff;
    font-size: 0.72rem; font-weight: 700;
    padding: 0.1rem 0.6rem; border-radius: 999px;
}
.item-pure-preview {
    background: var(--status-due-light); border: 1px dashed var(--status-due-bg);
    border-radius: 10px; padding: 0.5rem 0.8rem;
    font-size: 0.88rem; color: var(--status-due-bg); font-weight: 600;
}

/* Vori / Ana / Roti / Point always in ONE row (4 equal columns),
   Karat as its own full-width row underneath — mirrors gold_exchange.php */
.item-fields-row {
    display:grid;
    grid-template-columns:repeat(4, 1fr);
    gap:.5rem;
}
.item-fields-row .field-col label {
    display:block; font-size:.72rem;
    margin-bottom:.15rem; color:var(--muted); white-space:nowrap;
}
.item-fields-row .field-col input {
    text-align:center; padding-left:.25rem; padding-right:.25rem;
}
.item-fields-row input.form-control.is-valid,
.item-fields-row input.form-control.is-invalid,
.karat-row input.form-control.is-valid,
.karat-row input.form-control.is-invalid {
    background-image:none !important;
    padding-right:.25rem !important;
}
.karat-row { margin-top:.6rem; }
.karat-row label {
    display:block; font-size:.72rem;
    margin-bottom:.15rem; color:var(--muted);
}

/* ---------------------------------------------------------------
   Mobile — compact single-screen layout (no scroll), structured
   like the reference: tight header, stacked customer/date block,
   compact items table, compact summary table — all visible without
   scrolling. Note card is hidden on mobile to fit everything.
--------------------------------------------------------------- */
@media (max-width: 767.98px) {
    html, body { height:100%; overflow:hidden; }
    .page-content { height:100vh; overflow:hidden; display:flex; flex-direction:column; }
    .page-content .container-fluid { flex:1; min-height:0; overflow:hidden; display:flex; flex-direction:column; }
    .page-inset {
        padding:.45rem .5rem !important; display:flex; flex-direction:column;
        gap:.45rem; flex:1; min-height:0; overflow:hidden;
    }

    /* alerts collapse tightly if present */
    .page-inset > .alert { padding:.4rem .65rem; font-size:.75rem; margin-bottom:0!important; }

    /* header bar */
    .exchange-header {
        min-height: 60px !important;
        max-height: 70px !important;
        padding: 0.75rem 1rem !important;
        border-radius: 0 0 16px 16px !important;
    }
    .exchange-header h5 { font-size: 0.95rem; }
    .exchange-header small { display: none; }
    .exchange-header .text-end { display: none; } /* created-by folded into detail card on mobile */
    .exchange-header .btn-outline-light { padding: 0.15rem 0.42rem; font-size: 0.8rem; }

    /* customer + detail card */
    .detail-card-wrap { margin-bottom:0!important; }
    .detail-card-wrap .card-header { padding:.4rem .6rem; }
    .detail-card-wrap .card-header.fw-semibold,
    .detail-card-wrap .card-header { font-size:.85rem; }
    .detail-card { padding:.55rem .7rem; }
    .detail-card .row.g-0 { display:flex; flex-wrap:wrap; }
    .detail-card .col-md-7,
    .detail-card .col-md-4 { flex:1 1 100%; max-width:100%; padding:0!important; }
    .detail-card .col-md-1 { display:none!important; }
    .detail-card hr { margin:.35rem 0!important; }
    .detail-card .row.g-2 { row-gap:.1rem!important; }
    .detail-label { font-size:.74rem; flex:0 0 auto; }
    .detail-val { font-size:.85rem; }
    .detail-card .row.g-2 > .col-12 {
        display:flex; align-items:baseline; gap:.3rem; flex-wrap:nowrap;
    }
    .detail-val[style*="font-weight:400"] {
        flex:1 1 auto; overflow:hidden; text-overflow:ellipsis;
        white-space:nowrap; min-width:0;
    }

    /* items table card */
    .card { border-radius: 14px; margin-bottom: 0 !important; }
    .card-header { padding:.4rem .6rem; }
    .card-header .fw-semibold { font-size:.85rem; }
    .card-header .badge { font-size:.68rem; }
    .card-header .btn-sm { padding:.15rem .45rem; font-size:.74rem; }
    .card-header .btn-sm i { margin-right:.2rem!important; }

    table.table { font-size:.8rem; margin-bottom:0; }
    table.table th, table.table td { padding:.32rem .38rem; }
    .badge-old, .badge-karat, .badge-pure { font-size:.74rem; padding:.32em .52em; }

    /* summary ledger */
    .ledger td { padding:.38rem .6rem; font-size:.8rem; }
    .l-label { font-size:.76rem; }
    .l-rate { font-size:.66rem; }
    .l-val { font-size:.85rem; }
    .l-final .l-val { font-size:.92rem; }

    /* hide note card on mobile to keep everything on one screen */
    .card.note-card { display:none!important; }

    /* tighten vertical rhythm so header+customer+items+summary fit one screen */
    .exchange-header, .detail-card-wrap, .card { flex:0 0 auto; }
    .card:last-of-type { margin-bottom:0!important; }

    /* edit modal item cards — same compaction pattern as gold_exchange.php */
    .edit-item-card { padding:.75rem .75rem .6rem; margin-bottom:.6rem; border-radius:12px; }
    .edit-item-badge { top:-9px; left:12px; font-size:.65rem; padding:.08rem .5rem; }
    .edit-item-card .form-control-sm { font-size:.82rem; padding:.28rem .4rem; }
    .item-fields-row { gap:.4rem; }
    .item-pure-preview { padding:.35rem .6rem; font-size:.76rem; margin-top:.5rem!important; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0">

<!-- ================================================================
     PAGE HEADER — full width, flush to viewport edges, no top/side margin
================================================================ -->
<div class="exchange-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3" style="width:100%;">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="gold_exchange_list.php" class="btn btn-sm btn-outline-light py-0 px-2">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <h5 class="mb-0">
                    <i class="bi bi-arrow-left-right me-1"></i>
                    সোনা বদল
                    <span style="opacity:.65;">&nbsp;#<?= $exchangeId ?></span>
                </h5>
            </div>
            <small>সোনা বদল বিস্তারিত — ফাইনবুলিয়ন ডেস্ক</small>
        </div>
        <div class="text-end">
            <div style="font-size:.75rem;color:rgba(255,255,255,.6);letter-spacing:.03em;">তৈরি করেছেন</div>
            <div class="fw-semibold" style="font-size:.97rem;">
                <i class="bi bi-person-circle me-1"></i><?= h($ex['created_by_username'] ?? '—') ?>
            </div>
            <div style="font-size:.8rem;color:rgba(255,255,255,.65);">
                <?= h(fmt_dt($ex['created_at'])) ?>
            </div>
        </div>
    </div>
</div>

<div class="page-inset py-4">

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
     CUSTOMER + DETAIL — two-column layout
================================================================ -->
<div class="card shadow-sm mb-4 detail-card-wrap">
    <div class="card-header bg-white fw-semibold d-md-none">
        <i class="bi bi-person-fill me-1" style="color:var(--gold-deep);"></i>
        কাস্টমার
    </div>
    <div class="detail-card">

    <!-- Mobile-only: Date & Time, Name, Phone, Address in that exact order -->
    <div class="row g-2 d-md-none">
        <div class="col-12">
            <span class="detail-label">তারিখ ও সময়:</span>
            <span class="detail-val ms-1"><?= h(fmt_dt($ex['created_at'])) ?></span>
        </div>
        <div class="col-12">
            <span class="detail-label">কাস্টমারের নাম:</span>
            <span class="detail-val ms-1"><?= h($ex['customer_name']) ?></span>
        </div>
        <div class="col-12">
            <span class="detail-label">ফোন নম্বর:</span>
            <span class="detail-val ms-1"><?= h($ex['customer_phone'] ?: '—') ?></span>
        </div>
        <div class="col-12">
            <span class="detail-label">ঠিকানা:</span>
            <span class="detail-val ms-1" style="font-weight:400;color:#555;">
                <?= h($ex['customer_address'] ?: '—') ?>
            </span>
        </div>
    </div>

    <!-- Desktop: two-column layout (customer info left, date/created-by right) -->
    <div class="row g-0 d-none d-md-flex">

        <!-- Left: customer info -->
        <div class="col-md-7 pe-md-4">
            <div class="row g-2">
                <div class="col-12">
                    <span class="detail-label">কাস্টমারের নাম:</span>
                    <span class="detail-val ms-1"><?= h($ex['customer_name']) ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">ফোন নম্বর:</span>
                    <span class="detail-val ms-1"><?= h($ex['customer_phone'] ?: '—') ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">ঠিকানা:</span>
                    <span class="detail-val ms-1" style="font-weight:400;color:#555;">
                        <?= h($ex['customer_address'] ?: '—') ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Vertical divider (md+) -->
        <div class="col-md-1 d-none d-md-flex justify-content-center">
            <div style="width:1px;background:var(--hairline);min-height:100%;"></div>
        </div>

        <!-- Right: date & created-by -->
        <div class="col-md-4 ps-md-3">
            <div class="row g-2">
                <div class="col-12">
                    <span class="detail-label">তারিখ ও সময়:</span>
                    <span class="detail-val ms-1"><?= h(fmt_dt($ex['created_at'])) ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">তৈরি করেছেন:</span>
                    <span class="detail-val ms-1"><?= h($ex['created_by_username'] ?? '—') ?></span>
                </div>
                <?php if ($ex['updated_at'] && $ex['updated_at'] !== $ex['created_at']): ?>
                <div class="col-12">
                    <span class="detail-label">সর্বশেষ আপডেট:</span>
                    <span class="ms-1" style="font-size:.88rem;color:#888;">
                        <?= h(fmt_dt($ex['updated_at'])) ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
    </div><!-- /detail-card -->
</div><!-- /detail-card-wrap -->

<!-- ================================================================
     GOLD ITEMS TABLE
================================================================ -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">
            <i class="bi bi-gem me-1" style="color:var(--gold-deep);"></i>
            পুরাতন সোনা / খাদযুক্ত সোনা
            <span class="badge bg-secondary ms-1"><?= count($items) ?></span>
        </span>
        <?php if ($isAdmin): ?>
        <button type="button" class="btn btn-sm btn-gold"
                data-bs-toggle="modal" data-bs-target="#editModal">
            <i class="bi bi-pencil-square me-1"></i> এডিট করুন
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:38px;">#</th>
                        <th>পুরাতন সোনার ওজন</th>
                        <th style="width:90px;">ক্যারেট</th>
                        <th>পাকা সোনার ওজন</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">কোনো তথ্য পাওয়া যায়নি।</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $idx => $it):
                        $karat    = round(((float)$it['old_gold_purity'] / 100) * 24, 2);
                    ?>
                    <tr>
                        <td class="text-muted small"><?= $idx + 1 ?></td>
                        <td><span class="badge badge-old"><?= h(fmt_trad((float)$it['old_gold_weight'])) ?></span></td>
                        <td><span class="badge badge-karat"><?= h($karat) ?> ক্যারেট</span></td>
                        <td><span class="badge badge-pure"><?= h(fmt_trad((float)$it['pure_gold_weight'])) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================================================================
     EXCHANGE SUMMARY
================================================================ -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-calculator me-1" style="color:var(--gold-deep);"></i>
        হিসাব বিবরণী
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0 ledger" style="border-radius:0;">
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
</div>

<!-- ================================================================
     NOTE
================================================================ -->
<div class="card shadow-sm mb-4 note-card">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-pencil-square me-1" style="color:var(--gold-deep);"></i>
        নোট / মন্তব্য
    </div>
    <div class="card-body">
        <form method="POST" action="gold_exchange_edit.php?id=<?= $exchangeId ?>">
            <input type="hidden" name="action"     value="save_note">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <textarea class="form-control mb-2" name="note" rows="3"
                      placeholder="ঐচ্ছিক নোট লিখুন…"><?= h($ex['note'] ?? '') ?></textarea>
            <button type="submit" class="btn btn-gold btn-sm">
                <i class="bi bi-save-fill me-1"></i> নোট সংরক্ষণ করুন
            </button>
        </form>
    </div>
</div>

</div><!-- /page-inset -->

<?php if ($isAdmin): ?>
<!-- ================================================================
     EDIT ITEMS MODAL (admin only)
     Only existing items — no add/remove.
================================================================ -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%);color:#fff;">
                <h5 class="modal-title" style="color:#fff;">
                    <i class="bi bi-pencil-square me-2"></i>
                    আইটেম এডিট — এক্সচেঞ্জ #<?= $exchangeId ?>
                </h5>
                <button type="button" class="btn-close"
                        style="filter:brightness(0) invert(1);"
                        data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" action="gold_exchange_edit.php?id=<?= $exchangeId ?>"
                  id="editItemsForm">
                <input type="hidden" name="action"     value="save_items">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                <div class="modal-body">

                    <!-- Loss rate -->
                    <div class="d-flex align-items-end gap-3 mb-4">
                        <div>
                            <label class="form-label small fw-semibold mb-1">
                                লসের হার
                                <small class="text-muted fw-normal">(ভরি প্রতি পয়েন্ট)</small>
                            </label>
                            <input type="number" name="loss_rate" id="lossRateInput"
                                   min="0" step="0.001" value="<?= h($lossRate) ?>"
                                   class="form-control form-control-sm" style="width:120px;"
                                   oninput="recalcSummary()">
                        </div>
                        <small class="text-muted pb-1">
                            লস = ceil(মোট পাকা ভরি × হার) পয়েন্ট
                        </small>
                    </div>

                    <!-- Existing items only -->
                    <?php if (empty($items)): ?>
                        <div class="text-muted text-center py-3">এডিট করার মতো কোনো আইটেম নেই।</div>
                    <?php else: ?>
                        <?php foreach ($items as $idx => $it):
                            $karat = round(((float)$it['old_gold_purity'] / 100) * 24, 2);
                            $trad  = grams_to_trad((float)$it['old_gold_weight']);
                        ?>
                        <div class="edit-item-card">
                            <span class="edit-item-badge">আইটেম <?= $idx + 1 ?></span>
                            <!-- item ID is fixed — prevents adding new rows -->
                            <input type="hidden"
                                   name="items[<?= $idx ?>][id]"
                                   value="<?= (int)$it['id'] ?>">
                            <div class="item-fields-row mt-2">
                                <div class="field-col">
                                    <label>ভরি</label>
                                    <input type="number"
                                           name="items[<?= $idx ?>][vori]"
                                           class="form-control form-control-sm"
                                           min="0" step="1" inputmode="numeric"
                                           value="<?= $trad['v'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                                <div class="field-col">
                                    <label>আনা</label>
                                    <input type="number"
                                           name="items[<?= $idx ?>][ana]"
                                           class="form-control form-control-sm"
                                           min="0" max="15" step="1" inputmode="numeric"
                                           value="<?= $trad['a'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                                <div class="field-col">
                                    <label>রতি</label>
                                    <input type="number"
                                           name="items[<?= $idx ?>][roti]"
                                           class="form-control form-control-sm"
                                           min="0" max="5" step="1" inputmode="numeric"
                                           value="<?= $trad['r'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                                <div class="field-col">
                                    <label>পয়েন্ট</label>
                                    <input type="number"
                                           name="items[<?= $idx ?>][point]"
                                           class="form-control form-control-sm"
                                           min="0" max="9" step="1" inputmode="numeric"
                                           value="<?= $trad['p'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                            </div>
                            <div class="karat-row">
                                <label>ক্যারেট</label>
                                <input type="number"
                                       name="items[<?= $idx ?>][karat]"
                                       class="form-control form-control-sm"
                                       min="0.01" max="24" step="0.01"
                                       placeholder="যেমন: ১৯.০০"
                                       value="<?= h($karat) ?>"
                                       oninput="recalcItem(<?= $idx ?>)">
                            </div>
                            <div class="item-pure-preview" id="itemPreview_<?= $idx ?>">
                                পাকা সোনার ওজন: <?= h(fmt_trad((float)$it['pure_gold_weight'])) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Live summary preview -->
                    <div class="mt-3 pt-3 border-top">
                        <div class="text-muted small fw-semibold text-uppercase mb-2"
                             style="letter-spacing:.04em;">খসড়া হিসাব</div>
                        <table class="table table-sm mb-0 ledger">
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
                    </div>

                </div><!-- /modal-body -->

                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary btn-sm"
                            data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> বাতিল
                    </button>
                    <button type="submit" class="btn btn-gold btn-sm">
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

const G_VORI  = 11.664, G_ANA = 0.729, G_ROTI = 0.1215, G_POINT = 0.01215;
const ITEM_COUNT = <?= count($items) ?>;

function gramsToTrad(g) {
    g = Math.max(0, parseFloat(g) || 0);
    const EPS = 1e-9;
    const tv = g / G_VORI;
    let v  = Math.floor(tv + EPS);
    let ta = Math.max(0, tv - v) * 16;
    let a  = Math.floor(ta + EPS);
    let tr = Math.max(0, ta - a) * 6;
    let r  = Math.floor(tr + EPS);
    let p  = Math.round(Math.max(0, tr - r) * 10);
    if (p >= 10){p-=10;r++;} if (r>=6){r-=6;a++;} if (a>=16){a-=16;v++;}
    return {v,a,r,p};
}
function tradToGrams(v,a,r,p){
    return v*G_VORI + a*G_ANA + r*G_ROTI + p*G_POINT;
}
function fmtTrad(g) {
    const t = gramsToTrad(g);
    return `${t.v} ভ ${t.a} আ ${t.r} র ${t.p} প`;
}

function getItemInputs(idx) {
    return {
        v: parseInt(document.querySelector(`[name="items[${idx}][vori]"]`)?.value)  || 0,
        a: parseInt(document.querySelector(`[name="items[${idx}][ana]"]`)?.value)   || 0,
        r: parseInt(document.querySelector(`[name="items[${idx}][roti]"]`)?.value)  || 0,
        p: parseInt(document.querySelector(`[name="items[${idx}][point]"]`)?.value) || 0,
        k: parseFloat(document.querySelector(`[name="items[${idx}][karat]"]`)?.value) || 0,
    };
}

function recalcItem(idx) {
    const {v,a,r,p,k} = getItemInputs(idx);
    const grams    = tradToGrams(v, a, r, p);
    const pureGrams = grams * (k / 24);
    const el = document.getElementById('itemPreview_' + idx);
    if (el) el.textContent = 'পাকা সোনার ওজন: ' + fmtTrad(pureGrams);
    recalcSummary();
}

function recalcSummary() {
    let totalPure = 0;
    for (let i = 0; i < ITEM_COUNT; i++) {
        const {v,a,r,p,k} = getItemInputs(i);
        totalPure += tradToGrams(v, a, r, p) * (k / 24);
    }
    const lossRate       = Math.max(0, parseFloat(document.getElementById('lossRateInput').value) || 0);
    const lossPointsCeil = Math.ceil((totalPure / G_VORI) * lossRate);
    const lossGrams      = lossPointsCeil * G_POINT;
    const finalPure      = Math.max(0, totalPure - lossGrams);

    document.getElementById('previewTotal').textContent    = fmtTrad(totalPure);
    document.getElementById('previewLossRate').textContent = `(${lossPointsCeil} পয়েন্ট @ ${lossRate} প/ভ)`;
    document.getElementById('previewLoss').textContent     = fmtTrad(lossGrams);
    document.getElementById('previewFinal').textContent    = fmtTrad(finalPure);
}

// Init preview when modal opens
document.getElementById('editModal').addEventListener('shown.bs.modal', recalcSummary);
</script>

<?php else: ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>

</div>
</div>
</body>
</html>