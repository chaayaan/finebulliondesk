<?php
/**
 * gold_buy_edit.php
 * FineBullion Desk — Gold Buy detail & edit
 *
 * Pure PHP + mysqli. POST → PRG to prevent duplicate submit on refresh.
 * Any logged-in user: save note, add payment.
 * Admin only: edit existing items (weight, purity, price) + pure_gold_price.
 *
 * Payment tracking:
 *   - All payments stored in gold_buy_payments table.
 *   - gold_buys.paid_amount is a cache updated after each payment insert/delete.
 *   - Due = total_amount − SUM(gold_buy_payments.paid_amount).
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

function fmt_dt(?string $s): string {
    if (!$s) return '—';
    return (new DateTime($s))->format('d M Y, g:i A');
}

function h($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

// Helper: recalculate and cache paid_amount on gold_buys from payments table
function sync_paid_amount(mysqli $conn, int $buyId): void {
    $stmt = mysqli_prepare($conn,
        "UPDATE gold_buys
         SET paid_amount = (
             SELECT COALESCE(SUM(paid_amount), 0)
             FROM gold_buy_payments
             WHERE gold_buy_id = ?
         ), updated_at = NOW()
         WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $buyId, $buyId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
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
        $_SESSION['flash_error'] = 'অনুরোধটি সঠিক নয়। আবার চেষ্টা করুন।';
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
        $_SESSION['flash_success'] = 'নোট সফলভাবে আপডেট করা হয়েছে।';
        header("Location: gold_buy_edit.php?id={$buyId}");
        exit;
    }

    // ---- Add payment (any logged-in user) --------------------------------
    if ($action === 'add_payment') {
        $paidAmount     = (float)($_POST['paid_amount']    ?? 0);
        $paymentDate    = trim($_POST['payment_date']      ?? '');
        $transactionRef = trim($_POST['transaction_ref']   ?? '') ?: null;
        $paymentNote    = trim($_POST['payment_note']      ?? '') ?: null;
        $userId         = $currentUser['id'];

        $chkStmt = mysqli_prepare($conn,
            "SELECT gb.total_amount,
                    COALESCE(SUM(p.paid_amount), 0) AS paid_so_far
             FROM gold_buys gb
             LEFT JOIN gold_buy_payments p ON p.gold_buy_id = gb.id
             WHERE gb.id = ?
             GROUP BY gb.id, gb.total_amount");
        mysqli_stmt_bind_param($chkStmt, 'i', $buyId);
        mysqli_stmt_execute($chkStmt);
        $chk        = mysqli_fetch_assoc(mysqli_stmt_get_result($chkStmt));
        mysqli_stmt_close($chkStmt);
        $currentDue = $chk ? round((float)$chk['total_amount'] - (float)$chk['paid_so_far'], 2) : 0.0;

        $errors = [];
        if ($currentDue <= 0) {
            $errors[] = 'এই ক্রয়ের সমস্ত টাকা পরিশোধ করা হয়েছে।';
        } elseif ($paidAmount <= 0) {
            $errors[] = 'পরিশোধিত টাকা শূন্যের বেশি হতে হবে।';
        } elseif ($paidAmount > $currentDue + 0.009) {
            $errors[] = 'পরিমাণ বকেয়া টাকার (৳' . number_format($currentDue, 0) . ') চেয়ে বেশি হতে পারবে না।';
        }
        if (empty($paymentDate)) $errors[] = 'পেমেন্টের তারিখ আবশ্যক।';

        if (empty($errors)) {
            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare($conn,
                    "INSERT INTO gold_buy_payments
                        (gold_buy_id, paid_amount, transaction_ref, payment_date, note, received_by)
                     VALUES (?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, 'idsssi',
                    $buyId, $paidAmount, $transactionRef, $paymentDate, $paymentNote, $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                sync_paid_amount($conn, $buyId);
                mysqli_commit($conn);
                $_SESSION['flash_success'] = 'পেমেন্ট সফলভাবে সংরক্ষণ করা হয়েছে।';
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['flash_error'] = 'পেমেন্ট সংরক্ষণ করতে ব্যর্থ হয়েছে। আবার চেষ্টা করুন।';
            }
        } else {
            $_SESSION['flash_error'] = implode('<br>', $errors);
        }

        header("Location: gold_buy_edit.php?id={$buyId}");
        exit;
    }

    // ---- Delete payment (admin only) -------------------------------------
    if ($action === 'delete_payment' && $isAdmin) {
        $pmtId = (int)($_POST['payment_id'] ?? 0);
        if ($pmtId > 0) {
            mysqli_begin_transaction($conn);
            try {
                $stmt = mysqli_prepare($conn,
                    "DELETE FROM gold_buy_payments WHERE id = ? AND gold_buy_id = ?");
                mysqli_stmt_bind_param($stmt, 'ii', $pmtId, $buyId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                sync_paid_amount($conn, $buyId);
                mysqli_commit($conn);
                $_SESSION['flash_success'] = 'পেমেন্ট ডিলিট করা হয়েছে।';
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['flash_error'] = 'পেমেন্ট ডিলিট করতে ব্যর্থ হয়েছে।';
            }
        }
        header("Location: gold_buy_edit.php?id={$buyId}");
        exit;
    }

    // ---- Save items + amounts (admin only) ------------------------------
    if ($action === 'save_items' && $isAdmin) {
        $rawItems      = $_POST['items'] ?? [];
        $pureGoldPrice = isset($_POST['pure_gold_price']) && $_POST['pure_gold_price'] !== ''
                         ? max(0.0, (float)$_POST['pure_gold_price']) : 0.0;

        $errors    = [];
        $calcItems = [];
        $totalAmt  = 0.0;

        if ($pureGoldPrice <= 0) $errors[] = 'পাকা সোনার দাম শূন্যের বেশি হতে হবে।';

        foreach ($rawItems as $i => $item) {
            $n      = $i + 1;
            $itemId = (int)($item['id'] ?? 0);
            if ($itemId <= 0) { $errors[] = "আইটেম $n: আইডি পাওয়া যায়নি।"; continue; }

            $vori   = (int)($item['vori']   ?? 0);
            $ana    = (int)($item['ana']    ?? 0);
            $roti   = (int)($item['roti']   ?? 0);
            $point  = (int)($item['point']  ?? 0);
            $purity = (float)($item['purity'] ?? 0);

            if ($vori < 0)                $errors[] = "আইটেম $n: ভরি ঋণাত্মক হতে পারবে না।";
            if ($ana < 0 || $ana > 15)    $errors[] = "আইটেম $n: আনা ০–১৫ হতে হবে।";
            if ($roti < 0 || $roti > 5)   $errors[] = "আইটেম $n: রতি ০–৫ হতে হবে।";
            if ($point < 0 || $point > 9) $errors[] = "আইটেম $n: পয়েন্ট ০–৯ হতে হবে।";
            if ($purity < 0.01 || $purity > 24) $errors[] = "আইটেম $n: মান (ক্যারেট) ০.০১–২৪ হতে হবে।";

            $grams = trad_to_grams($vori, $ana, $roti, $point);
            if ($grams <= 0) $errors[] = "আইটেম $n: ওজন শূন্যের বেশি হতে হবে।";

            $price = ($grams / G_VORI) * ($purity / 24) * $pureGoldPrice;
            $totalAmt += $price;

            $calcItems[] = [
                'id'     => $itemId,
                'weight' => $grams,
                'purity' => $purity,
                'price'  => $price,
            ];
        }

        if (empty($calcItems) && empty($errors)) $errors[] = 'সংরক্ষণ করার মতো কোনো আইটেম নেই।';

        if (empty($errors)) {
            mysqli_begin_transaction($conn);
            try {
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

                $updBuy = mysqli_prepare($conn,
                    "UPDATE gold_buys
                     SET pure_gold_price = ?,
                         total_amount    = ?,
                         paid_amount     = (
                             SELECT COALESCE(SUM(paid_amount), 0)
                             FROM gold_buy_payments
                             WHERE gold_buy_id = ?
                         ),
                         updated_at      = NOW()
                     WHERE id = ?");
                mysqli_stmt_bind_param($updBuy, 'ddii',
                    $pureGoldPrice, $totalAmt, $buyId, $buyId);
                mysqli_stmt_execute($updBuy);
                mysqli_stmt_close($updBuy);

                mysqli_commit($conn);
                $_SESSION['flash_success'] = 'আইটেমসমূহ সফলভাবে আপডেট করা হয়েছে।';
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['flash_error'] = 'সংরক্ষণ করতে ব্যর্থ হয়েছে: ' . h($e->getMessage());
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
            gb.pure_gold_price, gb.total_amount,
            COALESCE(gbp.paid_sum, 0)                          AS paid_amount,
            (gb.total_amount - COALESCE(gbp.paid_sum, 0))      AS due_amount,
            gb.note, gb.created_at, gb.updated_at,
            u.username AS created_by_username
     FROM gold_buys gb
     JOIN customers c ON c.id = gb.customer_id
     LEFT JOIN users u ON u.id = gb.created_by
     LEFT JOIN (
         SELECT gold_buy_id, SUM(paid_amount) AS paid_sum
         FROM gold_buy_payments
         GROUP BY gold_buy_id
     ) gbp ON gbp.gold_buy_id = gb.id
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

// Fetch payment history
$pStmt = mysqli_prepare($conn,
    "SELECT p.id, p.paid_amount, p.transaction_ref, p.payment_date, p.note,
            p.created_at, u.username AS received_by_username
     FROM gold_buy_payments p
     LEFT JOIN users u ON u.id = p.received_by
     WHERE p.gold_buy_id = ?
     ORDER BY p.payment_date ASC, p.created_at ASC");
mysqli_stmt_bind_param($pStmt, 'i', $buyId);
mysqli_stmt_execute($pStmt);
$payments = mysqli_fetch_all(mysqli_stmt_get_result($pStmt), MYSQLI_ASSOC);
mysqli_stmt_close($pStmt);

$totalPaid = array_sum(array_column($payments, 'paid_amount'));
$dueAmount = round((float)$buy['due_amount'], 2);
$fullyPaid = $dueAmount <= 0;
?>
<!DOCTYPE html>
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>কেনা #<?= $buyId ?> — ফাইনবুলিয়ন ডেস্ক</title>
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
    --shadow: 0 2px 8px rgba(47,65,86,0.08);

    /* Mapped variables for backward compatibility */
    --gold-deep: var(--navy);
    --gold-mid: var(--navy);
    --gold-light: var(--teal);
    --ivory: var(--beige);
    --bronze-text: var(--navy);
    --muted: var(--teal);
    --hairline: var(--border-default);
}

body {
    background: var(--bg-app);
    font-family: 'Inter', 'Noto Sans Bengali', system-ui, -apple-system, sans-serif;
    color: var(--text-primary);
}

/* Page Header (§3) */
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
    gap: .2rem;
    min-width: 0;
}
.page-header .header-right {
    display: flex;
    align-items: center;
    gap: .6rem;
    flex-wrap: wrap;
}
.page-header h1 {
    color: var(--text-on-navy);
    margin: 0;
    font-weight: 700;
    font-size: 22px;
    line-height: 1.3;
}
.page-header .header-meta {
    color: rgba(255,255,255,.78);
    font-size: 12.5px;
    font-weight: 500;
    display: flex;
    flex-wrap: wrap;
    gap: .5rem 1rem;
    margin-top: .15rem;
}
.page-header .header-meta span {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
}

.header-action-btn {
    background: var(--navy);
    border: 1.5px solid #fff;
    color: #fff !important;
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
    color: #fff !important;
}

/* Page Inset */
.page-inset {
    padding: 0 1.5rem;
}

/* Buttons (§4) */
.btn-primary, .btn-gold, .btn-fb-primary {
    background: var(--navy);
    border: 1.5px solid var(--navy);
    color: #fff !important;
    border-radius: 8px;
    font-weight: 600;
}
.btn-primary:hover, .btn-gold:hover, .btn-fb-primary:hover,
.btn-primary:focus, .btn-gold:focus, .btn-fb-primary:focus {
    background: var(--teal);
    border-color: var(--teal);
    color: #fff !important;
}

.btn-secondary, .btn-fb-secondary {
    background: #fff;
    border: 1.5px solid var(--border-default);
    color: var(--navy);
    border-radius: 8px;
    font-weight: 600;
}
.btn-secondary:hover, .btn-fb-secondary:hover {
    background: var(--bg-hover);
    border-color: var(--teal);
    color: var(--navy);
}

.btn-outline-danger {
    background: #fff;
    border: 1.5px solid var(--danger);
    color: var(--danger);
    border-radius: 8px;
    font-weight: 600;
}
.btn-outline-danger:hover {
    background: var(--danger);
    color: #fff;
}

.btn-sm {
    border-radius: 8px;
}

/* Cards (§5) */
.card, .detail-card-wrap {
    background: var(--bg-card);
    border: 1px solid var(--border-default) !important;
    border-radius: 14px !important;
    box-shadow: var(--shadow) !important;
}

.card-header {
    background: var(--beige) !important;
    border-bottom: 1px solid var(--border-default) !important;
    border-radius: 14px 14px 0 0 !important;
    color: var(--text-primary);
    font-size: 16px;
    font-weight: 700;
    padding: .85rem 1.1rem;
}

.card-body, .detail-card {
    padding: 1.1rem;
}

.detail-label {
    font-size: 12.5px;
    color: var(--text-secondary);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .03em;
}

.detail-val {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}

/* Inputs (§6) */
.form-control, .form-select, textarea, .input-group-text {
    background: #fff;
    border: 1.5px solid var(--border-default);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    padding: .55rem .75rem;
}

.form-control:focus, .form-select:focus, textarea:focus {
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(86,124,141,.15);
    outline: none;
}

label, .form-label {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: .03em;
    margin-bottom: .3rem;
}

/* Status Chips (§7) */
.chip-paid, .badge-weight {
    background: #EAF3EE;
    color: var(--success);
    border-radius: 999px;
    padding: 3px 10px;
    font-size: 11px;
    font-weight: 700;
}

.chip-due, .badge-price {
    background: var(--sky);
    color: var(--navy);
    border-radius: 999px;
    padding: 3px 10px;
    font-size: 11px;
    font-weight: 700;
}

.badge-purity {
    background: var(--beige);
    color: var(--text-secondary);
    border: 1px solid var(--border-default);
    border-radius: 999px;
    padding: 3px 10px;
    font-size: 11px;
    font-weight: 700;
}

/* Tables (§8) */
.table {
    margin-bottom: 0;
}

thead th {
    background: var(--beige) !important;
    color: var(--text-secondary) !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    text-transform: uppercase;
    border-bottom: 1.5px solid var(--border-default) !important;
    padding: .65rem .75rem !important;
}

tbody td {
    padding: .65rem .75rem !important;
    border-bottom: 1px solid var(--border-default) !important;
    font-size: 13.5px;
    color: var(--text-primary);
}

tbody tr:hover {
    background: var(--bg-hover) !important;
}

/* Ledger (§8) */
.ledger {
    border: 1px solid var(--border-default);
    border-radius: 8px;
    overflow: hidden;
}

.ledger td {
    padding: .65rem .9rem;
    border-bottom: 1px solid var(--border-default);
    vertical-align: middle;
}

.l-label {
    font-size: 12.5px;
    color: var(--text-secondary);
    font-weight: 700;
    text-transform: uppercase;
    width: 1%;
    white-space: nowrap;
}

.l-val {
    font-weight: 700;
    font-size: 14px;
    text-align: right;
    color: var(--text-primary);
}

.l-price td { background: var(--sky) !important; }
.l-price .l-label, .l-price .l-val { color: var(--navy); }

.l-paid td { background: #EAF3EE !important; }
.l-paid .l-label, .l-paid .l-val { color: var(--success); }

.l-due td { background: #FBECEC !important; }
.l-due .l-label, .l-due .l-val { color: var(--danger); }

/* Payment History */
.pmt-item {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .65rem .9rem;
    border-bottom: 1px solid var(--border-default);
    font-size: 13.5px;
}

.pmt-item:last-child { border-bottom: none; }
.pmt-date { color: var(--text-secondary); font-size: 12.5px; min-width: 90px; }
.pmt-body { flex: 1; min-width: 0; }
.pmt-ref { color: var(--text-primary); font-size: 12.5px; font-weight: 600; }
.pmt-note { color: var(--text-secondary); font-size: 12px; font-style: italic; }
.pmt-amount { font-weight: 700; color: var(--success); font-size: 14px; }
.pmt-by { color: var(--text-secondary); font-size: 12px; }

/* Edit Item Cards */
.edit-item-card {
    border: 1px solid var(--border-default);
    border-radius: 12px;
    padding: 1rem 1.1rem;
    margin-bottom: 1rem;
    background: var(--bg-card);
    position: relative;
}

.edit-item-badge {
    position: absolute;
    top: -10px;
    left: 14px;
    background: var(--navy);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    padding: .15rem .6rem;
    border-radius: 999px;
}

.item-price-preview {
    background: #EAF3EE;
    border: 1px dashed var(--success);
    border-radius: 8px;
    padding: .5rem .8rem;
    font-size: 13px;
    color: var(--success);
    font-weight: 600;
}

.item-fields-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: .5rem;
}

.item-fields-row .field-col label {
    display: block;
    font-size: 11px;
    margin-bottom: .15rem;
    color: var(--text-secondary);
    white-space: nowrap;
}

.item-fields-row .field-col input {
    text-align: center;
    padding-left: .25rem;
    padding-right: .25rem;
}

/* Modals */
.modal-content {
    border-radius: 14px;
    overflow: hidden;
    border: 1px solid var(--border-default);
}

.modal-header {
    background: var(--navy) !important;
    color: #fff !important;
    border-bottom: none;
}

.modal-header .modal-title {
    color: #fff;
    font-size: 16px;
    font-weight: 700;
}

.modal-header .btn-close {
    filter: brightness(0) invert(1);
}

/* Alerts */
.alert-danger {
    background: #FBECEC;
    color: var(--danger);
    border: 1px solid var(--danger);
    border-radius: 8px;
}

.alert-success {
    background: #EAF3EE;
    color: var(--success);
    border: 1px solid var(--success);
    border-radius: 8px;
}

.alert-warning {
    background: var(--sky);
    color: var(--navy);
    border: 1px solid var(--border-default);
    border-radius: 8px;
}

/* Mobile Breakpoint (§10) */
@media (max-width: 576px) {
    .page-header {
        padding: .85rem 1.1rem;
        border-radius: 0 0 14px 14px;
    }
    .page-header h1 {
        font-size: 18px;
    }
    .page-inset {
        padding: 0 1rem;
    }
    .card-body, .detail-card {
        padding: .85rem;
    }
    .form-control, .form-select, textarea {
        font-size: 16px;
        padding: .6rem .8rem;
    }
    .btn-primary, .btn-gold, .btn-fb-primary, .btn-secondary, .btn-fb-secondary {
        font-size: 13.5px;
        padding: .6rem 1rem;
    }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0">
    <div class="page-header mb-4">
        <div class="header-left">
            <h1>
                <i class="bi bi-cash-coin me-2"></i>
                পুরাতন সোনা কেনা
                <span style="opacity:.75; font-weight: 500;">#<?= $buyId ?></span>
            </h1>
            <div class="header-meta">
                <span><i class="bi bi-person-circle"></i> <?= h($buy['created_by_username'] ?? '—') ?></span>
                <span><i class="bi bi-clock"></i> <?= h(fmt_dt($buy['created_at'])) ?></span>
            </div>
        </div>
        <div class="header-right">
            <a href="gold_buy_list.php" class="header-action-btn">
                <i class="bi bi-arrow-left"></i> কেনা তালিকা
            </a>
        </div>
    </div>
</div>

<div class="page-inset py-2">

<?php if ($postSuccess): ?>
<div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i><?= h($postSuccess) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($postError): ?>
<div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $postError ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ================================================================
     CUSTOMER + DETAIL
================================================================ -->
<div class="card mb-4 detail-card-wrap">
    <div class="card-header d-md-none">
        <i class="bi bi-person-fill me-1" style="color:var(--navy);"></i> কাস্টমার
    </div>
    <div class="detail-card">

    <!-- Mobile-only: Date & Time, Name, Phone, Address -->
    <div class="row g-2 d-md-none">
        <div class="col-12">
            <span class="detail-label">তারিখ ও সময়:</span>
            <span class="detail-val ms-1"><?= h(fmt_dt($buy['created_at'])) ?></span>
        </div>
        <div class="col-12">
            <span class="detail-label">কাস্টমারের নাম:</span>
            <span class="detail-val ms-1"><?= h($buy['customer_name']) ?></span>
        </div>
        <div class="col-12">
            <span class="detail-label">ফোন নম্বর:</span>
            <span class="detail-val ms-1"><?= h($buy['customer_phone'] ?: '—') ?></span>
        </div>
        <div class="col-12">
            <span class="detail-label">ঠিকানা:</span>
            <span class="detail-val ms-1" style="font-weight:400;color:var(--text-secondary);">
                <?= h($buy['customer_address'] ?: '—') ?>
            </span>
        </div>
    </div>

    <!-- Desktop: two-column layout -->
    <div class="row g-0 d-none d-md-flex">
        <!-- Left: customer info -->
        <div class="col-md-7 pe-md-4">
            <div class="row g-2">
                <div class="col-12">
                    <span class="detail-label">কাস্টমারের নাম:</span>
                    <span class="detail-val ms-1"><?= h($buy['customer_name']) ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">ফোন নম্বর:</span>
                    <span class="detail-val ms-1"><?= h($buy['customer_phone'] ?: '—') ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">ঠিকানা:</span>
                    <span class="detail-val ms-1" style="font-weight:400;color:var(--text-secondary);">
                        <?= h($buy['customer_address'] ?: '—') ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Vertical divider -->
        <div class="col-md-1 d-none d-md-flex justify-content-center">
            <div style="width:1px;background:var(--border-default);min-height:100%;"></div>
        </div>

        <!-- Right: date + created-by -->
        <div class="col-md-4 ps-md-3">
            <div class="row g-2">
                <div class="col-12">
                    <span class="detail-label">তারিখ ও সময়:</span>
                    <span class="detail-val ms-1"><?= h(fmt_dt($buy['created_at'])) ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">তৈরি করেছেন:</span>
                    <span class="detail-val ms-1"><?= h($buy['created_by_username'] ?? '—') ?></span>
                </div>
                <?php if ($buy['updated_at'] && $buy['updated_at'] !== $buy['created_at']): ?>
                <div class="col-12">
                    <span class="detail-label">আপডেট করা হয়েছে:</span>
                    <span class="ms-1" style="font-size:.88rem;color:var(--text-secondary);">
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
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-gem me-1" style="color:var(--navy);"></i>
            আইটেমসমূহ
            <span class="chip-due ms-1"><?= count($items) ?></span>
        </span>
        <?php if ($isAdmin): ?>
        <button type="button" class="btn btn-sm btn-primary"
                data-bs-toggle="modal" data-bs-target="#editModal">
            <i class="bi bi-pencil-square me-1"></i> এডিট করুন
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th style="width:20px;">#</th>
                        <th>ওজন (ভ-আ-র-প)</th>
                        <th style="width:110px;">ক্যারেট</th>
                        <th class="text-end">দাম</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">কোনো তথ্য পাওয়া যায়নি।</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $idx => $it): ?>
                    <tr>
                        <td class="text-muted small"><?= $idx + 1 ?></td>
                        <td><span class="badge-weight"><?= h(fmt_trad((float)$it['weight'])) ?></span></td>
                        <td><span class="badge-purity"><?= h(number_format((float)$it['purity'], 2)) ?> K</span></td>
                        <td class="text-end"><span class="badge-price">৳<?= number_format((float)$it['price'], 0) ?></span></td>
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
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-calculator me-1" style="color:var(--navy);"></i>
        ক্রয়ের সারসংক্ষেপ
    </div>
    <div class="card-body p-0">
        <table class="table table-sm ledger" style="border-radius:0;">
            <tbody>
                <tr class="l-price">
                    <td class="l-label">২৪ ক্যারেট পাকা সোনার দাম (প্রতি ভরি)</td>
                    <td class="l-val">৳<?= number_format((float)$buy['pure_gold_price'], 0) ?></td>
                </tr>
                <tr class="l-price">
                    <td class="l-label">মোট দাম</td>
                    <td class="l-val">৳<?= number_format((float)$buy['total_amount'], 0) ?></td>
                </tr>
                <tr class="l-paid">
                    <td class="l-label">মোট পরিশোধিত টাকা</td>
                    <td class="l-val">৳<?= number_format((float)$totalPaid, 0) ?></td>
                </tr>
                <tr class="l-due">
                    <td class="l-label">বকেয়া টাকা</td>
                    <td class="l-val">৳<?= number_format((float)$buy['due_amount'], 0) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ================================================================
     PAYMENT HISTORY
================================================================ -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-cash-stack me-1" style="color:var(--navy);"></i>
            পেমেন্টের ইতিহাস
            <span class="chip-due ms-1"><?= count($payments) ?></span>
        </span>
        <?php if (!$fullyPaid): ?>
        <button type="button" class="btn btn-sm btn-primary"
                data-bs-toggle="modal" data-bs-target="#addPaymentModal">
            <i class="bi bi-plus-lg me-1"></i> পেমেন্ট করুন
        </button>
        <?php else: ?>
        <span class="chip-paid">
            <i class="bi bi-check-circle-fill me-1"></i>পরিশোধিত
        </span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($payments)): ?>
            <div class="text-center text-muted py-4 small fst-italic">এখনো কোনো পেমেন্ট রেকর্ড করা হয়নি।</div>
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
                        <div class="pmt-by">গ্রহণ করেছেন <?= h($pmt['received_by_username']) ?> · <?= h(fmt_dt($pmt['created_at'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="pmt-amount">৳<?= number_format((float)$pmt['paid_amount'], 0) ?></div>
                <?php if ($isAdmin): ?>
                <form method="POST" action="gold_buy_edit.php?id=<?= $buyId ?>"
                      onsubmit="return confirm('আপনি কি এই পেমেন্ট রেকর্ডটি ডিলিট করতে নিশ্চিত?')"
                      class="ms-2">
                    <input type="hidden" name="action"     value="delete_payment">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="payment_id" value="<?= (int)$pmt['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="পেমেন্ট ডিলিট করুন">
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
<div class="card mb-4 note-card">
    <div class="card-header">
        <i class="bi bi-pencil-square me-1" style="color:var(--navy);"></i>
        নোট / মন্তব্য
    </div>
    <div class="card-body">
        <form method="POST" action="gold_buy_edit.php?id=<?= $buyId ?>">
            <input type="hidden" name="action"     value="save_note">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <textarea class="form-control mb-3" name="note" rows="3"
                      placeholder="ঐচ্ছিক নোট…"><?= h($buy['note'] ?? '') ?></textarea>
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-save-fill me-1"></i> নোট সংরক্ষণ করুন
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
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-cash-stack me-2"></i>পেমেন্ট যোগ করুন — কেনা #<?= $buyId ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="gold_buy_edit.php?id=<?= $buyId ?>">
                <input type="hidden" name="action"     value="add_payment">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <div class="modal-body">

                    <div class="alert alert-warning py-2 mb-3" style="font-size:.85rem;">
                        <div class="d-flex justify-content-between">
                            <span>মোট দাম:</span>
                            <strong>৳<?= number_format((float)$buy['total_amount'], 0) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>মোট পরিশোধিত টাকা:</span>
                            <strong class="text-success">৳<?= number_format((float)$totalPaid, 0) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between border-top pt-1 mt-1">
                            <span class="fw-bold">বকেয়া টাকা:</span>
                            <strong class="text-danger" id="modalCurrentDue">৳<?= number_format($dueAmount, 0) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between border-top pt-1 mt-1" id="dueAfterRow" style="display:none;">
                            <span class="fw-bold">এই পেমেন্টের পর অবশিষ্ট বকেয়া:</span>
                            <strong id="dueAfterValue">—</strong>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">পরিশোধিত টাকা (টাকা) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text" style="background:var(--navy);color:#fff;border-color:var(--navy);">৳</span>
                            <input type="number" name="paid_amount" id="buyPayAmountInput" class="form-control"
                                   min="0.01" max="<?= $dueAmount ?>" step="0.01" required
                                   placeholder="পেমেন্টের পরিমাণ লিখুন"
                                   value="<?= number_format($dueAmount, 2, '.', '') ?>">
                        </div>
                        <div class="form-text" id="buyPayAmountHint"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">পেমেন্টের তারিখ <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" class="form-control" required
                               value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">লেনদেনের রেফারেন্স
                            <small class="fw-normal text-muted">(চেক নম্বর, ব্যাংক রেফ., মোবাইল ব্যাংকিং ট্রানজেকশন আইডি)</small>
                        </label>
                        <input type="text" name="transaction_ref" class="form-control"
                               placeholder="ঐচ্ছিক রেফারেন্স…">
                    </div>

                    <div class="mb-0">
                        <label class="form-label">নোট <small class="fw-normal text-muted">(ঐচ্ছিক)</small></label>
                        <input type="text" name="payment_note" class="form-control"
                               placeholder="যেমন: বিকাশ, নগদ, ব্যাংক ট্রান্সফার…">
                    </div>

                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> বাতিল
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm" id="btnRecordBuyPayment">
                        <i class="bi bi-save-fill me-1"></i> পেমেন্ট সংরক্ষণ করুন
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
================================================================ -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square me-2"></i>
                    আইটেম এডিট করুন — #<?= $buyId ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" action="gold_buy_edit.php?id=<?= $buyId ?>" id="editItemsForm">
                <input type="hidden" name="action"     value="save_items">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                <div class="modal-body">

                    <!-- Pure gold price -->
                    <div class="row g-3 mb-4">
                        <div class="col-sm-12">
                            <label class="form-label mb-1">
                                ২৪ ক্যারেট পাকা সোনার দাম (প্রতি ভরি)
                                <small class="text-muted fw-normal">(টাকা)</small>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:var(--navy);color:#fff;border-color:var(--navy);">৳</span>
                                <input type="number" name="pure_gold_price" id="pureGoldPriceInput"
                                       min="1" step="1"
                                       value="<?= h((int)$buy['pure_gold_price']) ?>"
                                       class="form-control"
                                       oninput="recalcAll()">
                            </div>
                        </div>
                    </div>

                    <!-- Existing items -->
                    <?php if (empty($items)): ?>
                        <div class="text-muted text-center py-3">এডিট করার মতো কোনো আইটেম নেই।</div>
                    <?php else: ?>
                        <?php foreach ($items as $idx => $it):
                            $trad  = grams_to_trad((float)$it['weight']);
                            $karat = (float)$it['purity'];
                        ?>
                        <div class="edit-item-card">
                            <span class="edit-item-badge">আইটেম <?= $idx + 1 ?></span>
                            <input type="hidden" name="items[<?= $idx ?>][id]"
                                   value="<?= (int)$it['id'] ?>">

                            <div class="item-fields-row mt-2">
                                <div class="field-col">
                                    <label>ভরি</label>
                                    <input type="number" name="items[<?= $idx ?>][vori]"
                                           class="form-control form-control-sm"
                                           min="0" step="1" inputmode="numeric"
                                           value="<?= $trad['v'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                                <div class="field-col">
                                    <label>আনা</label>
                                    <input type="number" name="items[<?= $idx ?>][ana]"
                                           class="form-control form-control-sm"
                                           min="0" max="15" step="1" inputmode="numeric"
                                           value="<?= $trad['a'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                                <div class="field-col">
                                    <label>রতি</label>
                                    <input type="number" name="items[<?= $idx ?>][roti]"
                                           class="form-control form-control-sm"
                                           min="0" max="5" step="1" inputmode="numeric"
                                           value="<?= $trad['r'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                                <div class="field-col">
                                    <label>পয়েন্ট</label>
                                    <input type="number" name="items[<?= $idx ?>][point]"
                                           class="form-control form-control-sm"
                                           min="0" max="9" step="1" inputmode="numeric"
                                           value="<?= $trad['p'] ?>"
                                           oninput="recalcItem(<?= $idx ?>)">
                                </div>
                            </div>

                            <div class="mt-2">
                                <label class="form-label">সোনার মান (ক্যারেট)</label>
                                <input type="number" name="items[<?= $idx ?>][purity]"
                                       class="form-control form-control-sm"
                                       min="0.01" max="24" step="0.01"
                                       placeholder="যেমন: ২২"
                                       value="<?= h($karat) ?>"
                                       oninput="recalcItem(<?= $idx ?>)">
                            </div>

                            <div class="item-price-preview mt-2" id="itemPreview_<?= $idx ?>">
                                আইটেমের দাম: ৳<?= number_format((float)$it['price'], 0) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Live summary preview -->
                    <div class="mt-3 pt-3 border-top">
                        <div class="text-muted small fw-semibold text-uppercase mb-2"
                             style="letter-spacing:.04em;">সারসংক্ষেপ প্রিভিউ</div>
                        <table class="table table-sm ledger">
                            <tbody>
                                <tr class="l-price">
                                    <td class="l-label">মোট দাম</td>
                                    <td class="l-val" id="previewTotal">—</td>
                                </tr>
                                <tr class="l-paid">
                                    <td class="l-label">পূর্বে পরিশোধিত</td>
                                    <td class="l-val" id="previewPaid">৳<?= number_format((float)$totalPaid, 0) ?></td>
                                </tr>
                                <tr class="l-due">
                                    <td class="l-label">বকেয়া টাকা</td>
                                    <td class="l-val" id="previewDue">—</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                </div>

                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i> বাতিল
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm">
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
const ITEM_COUNT  = <?= count($items) ?>;
const TOTAL_PAID  = <?= (float)$totalPaid ?>;

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

function recalcItem(idx){
    const {v,a,r,p,k} = getItemInputs(idx);
    const price = (tradToGrams(v,a,r,p) / G_VORI) * (k / 24) * getPureGoldPrice();
    const el = document.getElementById('itemPreview_' + idx);
    if (el) el.textContent = 'আইটেমের দাম: ' + fmtBDT(price);
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
(function initBuyPaymentModal(){
    const DUE = <?= $dueAmount ?>;
    const inp  = document.getElementById('buyPayAmountInput');
    const btn  = document.getElementById('btnRecordBuyPayment');
    const row  = document.getElementById('dueAfterRow');
    const val  = document.getElementById('dueAfterValue');
    const hint = document.getElementById('buyPayAmountHint');
    if (!inp) return;

    function update(){
        const raw     = inp.value;
        const entered = raw === '' ? 0 : (parseFloat(raw) || 0);
        const remaining = DUE - entered;

        row.style.display = '';

        if (raw === '') {
            val.textContent  = '৳' + Math.round(DUE).toLocaleString('en-BD');
            val.className    = 'text-danger';
            hint.textContent = '';
            btn.disabled     = true;
            return;
        }

        if (entered < 0) {
            val.textContent  = '৳' + Math.round(DUE).toLocaleString('en-BD');
            val.className    = 'text-danger';
            hint.textContent = 'পেমেন্ট ঋণাত্মক হতে পারবে না।';
            hint.className   = 'form-text text-danger';
            btn.disabled     = true;
            return;
        }

        if (entered === 0) {
            val.textContent  = '৳' + Math.round(DUE).toLocaleString('en-BD');
            val.className    = 'text-danger';
            hint.textContent = '';
            btn.disabled     = true;
            return;
        }

        btn.disabled = !(entered > 0 && entered <= DUE + 0.009);

        if (remaining <= 0.009) {
            val.textContent  = '৳০ — সম্পূর্ণ পরিশোধিত';
            val.className    = 'text-success';
            hint.textContent = '';
        } else if (remaining < 0) {
            val.textContent  = '৳' + Math.round(Math.abs(remaining)).toLocaleString('en-BD') + ' অতিরিক্ত পেমেন্ট';
            val.className    = 'text-danger';
            hint.textContent = 'পরিমাণ বকেয়া টাকার চেয়ে বেশি।';
            hint.className   = 'form-text text-danger';
        } else {
            val.textContent  = '৳' + Math.round(remaining).toLocaleString('en-BD');
            val.className    = 'text-danger';
            hint.textContent = '';
        }
    }

    ['input', 'keyup', 'change', 'paste'].forEach(evt =>
        inp.addEventListener(evt, () => setTimeout(update, 0))
    );

    document.getElementById('addPaymentModal')
        .addEventListener('shown.bs.modal', update);
})();
</script>
<?php endif; ?>

</div>
</div>
</body>
</html>