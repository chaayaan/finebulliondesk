<?php
/**
 * gold_sale_edit_inventory.php
 * FineBullion Desk — Gold Sale detail & edit — Inventory-integrated build
 *
 * Pure PHP + mysqli. POST → PRG to prevent duplicate submit on refresh.
 * Any logged-in user: save note, add payment.
 * Admin only: edit existing sale items (weight + purity) + pure_gold_price.
 *
 * Payment tracking:
 *   - All payments stored in gold_sale_payments table.
 *   - gold_sales.paid_amount is a cache updated after each payment insert/delete.
 *   - Due = total_amount − SUM(gold_sale_payments.paid_amount).
 *
 * Inventory integration:
 *   - Item karat is restricted to the 5 karats tracked by Inventory
 *     (18K/20K/21K/22K/24K — Option A from the integration spec), matching
 *     gold_sale_inventory.php's create-side <select>.
 *   - Items are edited in place by ID (no add/remove — see save_items).
 *     An item's karat itself may change on edit, so the inventory delta is
 *     computed per PURITY across all edited items, not per item: for each
 *     item, subtract its OLD weight from its OLD purity's bucket and add
 *     its NEW weight to its NEW purity's bucket, then apply the net delta
 *     per karat (positive = deduct more stock, negative = restore stock).
 *   - The live "stock_all" endpoint reports current left_weight for all
 *     5 karats; the edit modal simulates, per karat, what would remain
 *     after the net delta so the admin sees a shortage warning before
 *     submitting. The server (inventory_deduct/inventory_apply_delta,
 *     inside the save_items transaction) is the final authority.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/inventory_lib.php';

// The 5 karats tracked by Inventory — Option A restricts sale items to
// exactly this set, matching inventory.php's KARATS constant and
// gold_sale_inventory.php's SALE_KARATS.
const SALE_KARATS = [18.00, 20.00, 21.00, 22.00, 24.00];

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
// Live inventory stock (all 5 karats) — polled by the edit modal so it can
// warn before submit if the recalculated per-karat deltas would exceed
// what's in inventory. Read-only, no lock (FOR UPDATE only happens inside
// the save_items transaction below).
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'stock_all') {
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
            'purity'           => $k,
            'label'            => karat_label($k),
            'left_weight'      => $left,
            'left_weight_trad' => fmt_trad($left),
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'stock' => $stock], JSON_UNESCAPED_UNICODE);
    exit;
}

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
        $_SESSION['flash_error'] = 'অনুরোধটি সঠিক নয়। অনুগ্রহ করে আবার চেষ্টা করুন।';
        header("Location: gold_sale_edit_inventory.php?id={$saleId}");
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
        $_SESSION['flash_success'] = 'নোট সফলভাবে আপডেট করা হয়েছে।';
        header("Location: gold_sale_edit_inventory.php?id={$saleId}");
        exit;
    }

    // ---- Add payment (any logged-in user) --------------------------------
    if ($action === 'add_payment') {
        $paidAmount     = (int) round((float)($_POST['paid_amount'] ?? 0));
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
        $currentDue = $chk ? (int) round((float)$chk['total_amount'] - (float)$chk['paid_so_far']) : 0;

        $errors = [];
        if ($currentDue <= 0) {
            $errors[] = 'এই বিক্রির টাকা আগেই সম্পূর্ণ পরিশোধ করা হয়েছে।';
        } elseif ($paidAmount <= 0) {
            $errors[] = 'পরিশোধিত টাকা শূন্যের চেয়ে বেশি হতে হবে।';
        } elseif ($paidAmount > $currentDue) {
            $errors[] = 'পরিমাণ অবশিষ্ট বকেয়ার (৳' . number_format($currentDue, 0) . ') চেয়ে বেশি।';
        }
        if (empty($paymentDate)) $errors[] = 'পেমেন্টের তারিখ দেওয়া আবশ্যক।';

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
                $_SESSION['flash_success'] = 'পেমেন্ট সফলভাবে রেকর্ড করা হয়েছে।';
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['flash_error'] = 'পেমেন্ট রেকর্ড করতে ব্যর্থ হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।';
            }
        } else {
            $_SESSION['flash_error'] = implode('<br>', $errors);
        }

        header("Location: gold_sale_edit_inventory.php?id={$saleId}");
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
                $_SESSION['flash_success'] = 'পেমেন্ট ডিলিট করা হয়েছে।';
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                $_SESSION['flash_error'] = 'পেমেন্ট ডিলিট করতে ব্যর্থ হয়েছে।';
            }
        }
        header("Location: gold_sale_edit_inventory.php?id={$saleId}");
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

        if ($pureGoldPrice <= 0) $errors[] = 'পাকা সোনার দাম শূন্যের বেশি হতে হবে।';

        foreach ($rawItems as $i => $item) {
            $n      = $i + 1;
            $itemId = (int)($item['id'] ?? 0);
            if ($itemId <= 0) { $errors[] = "আইটেম $n: আইডি পাওয়া যায়নি।"; continue; }

            $vori   = (int)($item['vori']    ?? 0);
            $ana    = (int)($item['ana']     ?? 0);
            $roti   = (int)($item['roti']    ?? 0);
            $point  = (int)($item['point']   ?? 0);
            $purity = round((float)($item['purity'] ?? 0), 2);

            if ($vori < 0)                $errors[] = "আইটেম $n: ভরি ঋণাত্মক হতে পারবে না।";
            if ($ana < 0 || $ana > 15)    $errors[] = "আইটেম $n: আনা ০–১৫ হতে হবে।";
            if ($roti < 0 || $roti > 5)   $errors[] = "আইটেম $n: রতি ০–৫ হতে হবে।";
            if ($point < 0 || $point > 9) $errors[] = "আইটেম $n: পয়েন্ট ০–৯ হতে হবে।";
            if (!in_array($purity, SALE_KARATS, true)) {
                $errors[] = "আইটেম $n: ক্যারেট অবশ্যই ১৮K, ২০K, ২১K, ২২K অথবা ২৪K এর একটি হতে হবে।";
            }

            $grams = trad_to_grams($vori, $ana, $roti, $point);
            if ($grams <= 0) $errors[] = "আইটেম $n: ওজন শূন্যের বেশি হতে হবে।";

            $price = (int) round(($grams / G_VORI) * ($purity / 24) * $pureGoldPrice);
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

                // Fetch OLD weight+purity for exactly these items, locked,
                // BEFORE updating them, so we know what to reverse against
                // inventory. An item's karat itself may have changed on
                // edit, so this is per-item old state, not just old totals.
                $oldStmt = mysqli_prepare($conn,
                    "SELECT id, weight, purity FROM gold_sale_items
                     WHERE gold_sale_id = ? AND id IN ($placeholders) FOR UPDATE");
                mysqli_stmt_bind_param($oldStmt, $bindTypes, ...$bindArgs);
                mysqli_stmt_execute($oldStmt);
                $oldRows = mysqli_fetch_all(mysqli_stmt_get_result($oldStmt), MYSQLI_ASSOC);
                mysqli_stmt_close($oldStmt);
                $oldById = [];
                foreach ($oldRows as $r) $oldById[(int)$r['id']] = $r;

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

                // Compute per-karat net delta across all edited items and
                // apply it against inventory. An item's karat itself may
                // have changed (e.g. 21K -> 22K), so this is NOT simply
                // "new_weight - old_weight" per item; it's a net movement
                // per PURITY, combining every item that touches that
                // purity — restore the old karat's old weight, deduct the
                // new karat's new weight.
                $deltaByPurity = [];
                foreach ($calcItems as $ci) {
                    $old = $oldById[$ci['id']] ?? null;
                    if ($old !== null) {
                        $oldPurity = round((float)$old['purity'], 2);
                        $deltaByPurity[$oldPurity] = ($deltaByPurity[$oldPurity] ?? 0.0) - (float)$old['weight'];
                    }
                    $newPurity = round((float)$ci['purity'], 2);
                    $deltaByPurity[$newPurity] = ($deltaByPurity[$newPurity] ?? 0.0) + (float)$ci['weight'];
                }
                ksort($deltaByPurity); // lock order — avoids deadlocks against concurrent sales/exchanges

                // Pre-check: every karat needing MORE stock must have it,
                // before applying anything (still one transaction, so this
                // is belt-and-braces rather than strictly required, since
                // inventory_deduct() re-checks anyway).
                foreach ($deltaByPurity as $purity => $delta) {
                    if ($delta > 0.0005) {
                        $row = inventory_lock_row($conn, $purity);
                        if (!$row) throw new InventoryException('অজানা ক্যারেট।');
                        $left = (float)$row['left_weight'];
                        if ($left - $delta < -0.0005) {
                            throw new InventoryException(sprintf(
                                '%s এর পর্যাপ্ত মজুদ নেই এই পরিবর্তনের জন্য। বর্তমান মজুদ: %s, প্রয়োজনীয় অতিরিক্ত: %s',
                                karat_label($purity), fmt_trad_g($left), fmt_trad_g($delta)
                            ));
                        }
                    }
                }
                foreach ($deltaByPurity as $purity => $delta) {
                    inventory_apply_delta($conn, $purity, $delta);
                }

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

        header("Location: gold_sale_edit_inventory.php?id={$saleId}");
        exit;
    }

    header("Location: gold_sale_edit_inventory.php?id={$saleId}");
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

// Baseline for the edit modal's live per-karat stock-status panel: this
// sale's CURRENT weight, grouped by karat, already reflected in
// inventory.left_weight (i.e. already deducted). Only the amount ABOVE
// this per karat needs to come from current stock; editing a karat's
// total down FREES stock instead of consuming it — same rule the server
// applies via $deltaByPurity in save_items.
$oldByPurity = [];
foreach ($items as $it) {
    $p = number_format(round((float)$it['purity'], 2), 2, '.', '');
    $oldByPurity[$p] = ($oldByPurity[$p] ?? 0.0) + (float)$it['weight'];
}

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
$dueAmount = (int) round((float)$sale['due_amount']);
$fullyPaid = $dueAmount <= 0;
?>
<!DOCTYPE html>
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>বিক্রি #<?= $saleId ?> — ফাইনবুলিয়ন ডেস্ক</title>
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
    grid-template-columns: 1.3fr repeat(4, 1fr);
    gap: .4rem;
}

.item-fields-row .field-col label {
    display: block;
    font-size: 11px;
    margin-bottom: .15rem;
    color: var(--text-secondary);
    white-space: nowrap;
}

.item-fields-row .field-col input,
.item-fields-row .field-col select {
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

/* Item Edit Modal — dynamic height, viewport-capped.
   With few items the modal hugs its content (no wasted empty space).
   With many items it grows only up to the viewport, then .modal-body
   becomes the single scrolling region so every item, field, and the
   Save/Cancel buttons stay reachable either way. */
#editModal .modal-dialog {
    max-height: calc(100dvh - 3.5rem);
    display: flex;
    flex-direction: column;
    justify-content: center;
    margin-top: 1.75rem;
    margin-bottom: 1.75rem;
}
#editModal .modal-content {
    max-height: 100%;
    display: flex;
    flex-direction: column;
    overflow: hidden; /* keep rounded corners; body scrolls, not this */
}
#editModal #editItemsForm {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
}
#editModal .modal-body {
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior: contain;
    flex: 1 1 auto;
    min-height: 0; /* required so flex-child scroll works instead of growing */
}
#editModal .modal-header,
#editModal .modal-footer {
    flex: 0 0 auto;
}

@media (max-width: 576px) {
    #editModal .modal-dialog {
        margin: 0.5rem auto;
        max-height: calc(100dvh - 1rem);
    }
}

/* Compact per-item live stock row (mirrors gold_sale_inventory.php) */
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
                <i class="bi bi-cart-check me-2"></i>
                সোনা বিক্রি
                <span style="opacity:.75; font-weight: 500;">#<?= $saleId ?></span>
            </h1>
            <div class="header-meta">
                <span><i class="bi bi-person-circle"></i> <?= h($sale['created_by_username'] ?? '—') ?></span>
                <span><i class="bi bi-clock"></i> <?= h(fmt_dt($sale['created_at'])) ?></span>
            </div>
        </div>
        <div class="header-right">
            <a href="gold_sale_list.php" class="header-action-btn">
                <i class="bi bi-arrow-left"></i> বিক্রি তালিকা
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
            <span class="detail-val ms-1"><?= h(fmt_dt($sale['created_at'])) ?></span>
        </div>
        <div class="col-12">
            <span class="detail-label">কাস্টমারের নাম:</span>
            <span class="detail-val ms-1"><?= h($sale['customer_name']) ?></span>
        </div>
        <div class="col-12">
            <span class="detail-label">ফোন নম্বর:</span>
            <span class="detail-val ms-1"><?= h($sale['customer_phone'] ?: '—') ?></span>
        </div>
        <div class="col-12">
            <span class="detail-label">ঠিকানা:</span>
            <span class="detail-val ms-1" style="font-weight:400;color:var(--text-secondary);">
                <?= h($sale['customer_address'] ?: '—') ?>
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
                    <span class="detail-val ms-1"><?= h($sale['customer_name']) ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">ফোন নম্বর:</span>
                    <span class="detail-val ms-1"><?= h($sale['customer_phone'] ?: '—') ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">ঠিকানা:</span>
                    <span class="detail-val ms-1" style="font-weight:400;color:var(--text-secondary);">
                        <?= h($sale['customer_address'] ?: '—') ?>
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
                    <span class="detail-val ms-1"><?= h(fmt_dt($sale['created_at'])) ?></span>
                </div>
                <div class="col-12">
                    <span class="detail-label">তৈরি করেছেন:</span>
                    <span class="detail-val ms-1"><?= h($sale['created_by_username'] ?? '—') ?></span>
                </div>
                <?php if ($sale['updated_at'] && $sale['updated_at'] !== $sale['created_at']): ?>
                <div class="col-12">
                    <span class="detail-label">আপডেট করা হয়েছে:</span>
                    <span class="ms-1" style="font-size:.88rem;color:var(--text-secondary);">
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
                        <td><span class="badge-purity"><?= h(karat_label((float)$it['purity'])) ?></span></td>
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
     SALE SUMMARY LEDGER
================================================================ -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-calculator me-1" style="color:var(--navy);"></i>
        বিক্রির সারসংক্ষেপ
    </div>
    <div class="card-body p-0">
        <table class="table table-sm ledger" style="border-radius:0;">
            <tbody>
                <tr class="l-price">
                    <td class="l-label">২৪ ক্যারেট পাকা সোনার দাম (প্রতি ভরি)</td>
                    <td class="l-val">৳<?= number_format((float)$sale['pure_gold_price'], 0) ?></td>
                </tr>
                <tr class="l-price">
                    <td class="l-label">মোট দাম</td>
                    <td class="l-val">৳<?= number_format((float)$sale['total_amount'], 0) ?></td>
                </tr>
                <tr class="l-paid">
                    <td class="l-label">মোট পরিশোধিত টাকা</td>
                    <td class="l-val">৳<?= number_format((float)$totalPaid, 0) ?></td>
                </tr>
                <tr class="l-due">
                    <td class="l-label">পাওনা বাকি</td>
                    <td class="l-val">৳<?= number_format((float)$sale['due_amount'], 0) ?></td>
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
                <form method="POST" action="gold_sale_edit_inventory.php?id=<?= $saleId ?>"
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
        <form method="POST" action="gold_sale_edit_inventory.php?id=<?= $saleId ?>">
            <input type="hidden" name="action"     value="save_note">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <textarea class="form-control mb-3" name="note" rows="3"
                      placeholder="ঐচ্ছিক নোট…"><?= h($sale['note'] ?? '') ?></textarea>
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
                    <i class="bi bi-cash-stack me-2"></i>পেমেন্ট যোগ করুন — বিক্রি #<?= $saleId ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="gold_sale_edit_inventory.php?id=<?= $saleId ?>">
                <input type="hidden" name="action"     value="add_payment">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <div class="modal-body">

                    <div class="alert alert-warning py-2 mb-3" style="font-size:.85rem;">
                        <div class="d-flex justify-content-between">
                            <span>মোট দাম:</span>
                            <strong>৳<?= number_format((float)$sale['total_amount'], 0) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>মোট পরিশোধিত টাকা:</span>
                            <strong class="text-success">৳<?= number_format((float)$totalPaid, 0) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between border-top pt-1 mt-1">
                            <span class="fw-bold">এখন পরিশোধ করছে:</span>
                            <strong class="text-danger" id="modalCurrentDue">৳<?= number_format($dueAmount, 0) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between border-top pt-1 mt-1" id="dueAfterRow" style="display:none;">
                            <span class="fw-bold">এই পেমেন্টের পর পাওনা বাকি:</span>
                            <strong id="dueAfterValue">—</strong>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">পরিশোধ করছে (টাকা) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text" style="background:var(--navy);color:#fff;border-color:var(--navy);">৳</span>
                            <input type="number" name="paid_amount" id="salePayAmountInput" class="form-control"
                                   min="1" max="<?= $dueAmount ?>" step="1" required
                                   placeholder="পেমেন্টের পরিমাণ লিখুন"
                                   value="<?= $dueAmount ?>">
                        </div>
                        <div class="form-text" id="salePayAmountHint"></div>
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
                    <button type="submit" class="btn btn-primary btn-sm" id="btnRecordSalePayment">
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
                    আইটেম এডিট করুন — #<?= $saleId ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" action="gold_sale_edit_inventory.php?id=<?= $saleId ?>" id="editItemsForm">
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
                                       value="<?= h((int)$sale['pure_gold_price']) ?>"
                                       class="form-control"
                                       oninput="recalcAll()">
                            </div>
                        </div>
                    </div>

                    <!-- Live inventory status: compact, per-item (see item-stock-row below each item) -->
                    <div class="d-flex justify-content-end mb-2">
                        <span class="badge bg-secondary" id="stockStatusSpinner" style="display:none;">
                            <span class="spinner-border spinner-border-sm me-1"></span>মজুদ আপডেট হচ্ছে…
                        </span>
                    </div>
                    <div id="stockShortageNotice" class="alert alert-danger py-2 mb-3 small" style="display:none;">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        সতর্কবার্তা: কিছু ক্যারেটে পর্যাপ্ত ইনভেন্টরি স্টক নেই। সংরক্ষণ করা যাবে না।
                    </div>

                    <!-- Existing items -->
                    <?php if (empty($items)): ?>
                        <div class="text-muted text-center py-3">এডিট করার মতো কোনো আইটেম নেই।</div>
                    <?php else: ?>
                        <?php foreach ($items as $idx => $it):
                            $trad  = grams_to_trad((float)$it['weight']);
                            $karat = round((float)$it['purity'], 2);
                        ?>
                        <div class="edit-item-card">
                            <span class="edit-item-badge">আইটেম <?= $idx + 1 ?></span>
                            <input type="hidden" name="items[<?= $idx ?>][id]"
                                   value="<?= (int)$it['id'] ?>">

                            <div class="item-fields-row mt-2">
                                <div class="field-col">
                                    <label>ক্যারেট</label>
                                    <select name="items[<?= $idx ?>][purity]"
                                            class="form-select form-select-sm"
                                            onchange="recalcItem(<?= $idx ?>)">
                                        <?php foreach (SALE_KARATS as $k): ?>
                                        <option value="<?= number_format($k, 2, '.', '') ?>"
                                                <?= abs($karat - $k) < 0.01 ? 'selected' : '' ?>>
                                            <?= karat_label($k) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
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

                            <div class="item-price-preview mt-2" id="itemPreview_<?= $idx ?>">
                                আইটেমের দাম: ৳<?= number_format((float)$it['price'], 0) ?>
                            </div>

                            <!-- Live per-karat stock row -->
                            <div class="item-stock-row" data-item-stock id="itemStockRow_<?= $idx ?>">
                                <div class="isr-top">
                                    <span class="s-label"><i class="bi bi-box-seam me-1"></i>
                                        <span id="itemStockLabel_<?= $idx ?>"><?= karat_label($karat) ?></span> বর্তমান মজুদ
                                    </span>
                                    <span class="s-value" id="itemStockValue_<?= $idx ?>">লোড হচ্ছে…</span>
                                </div>
                                <div class="isr-warning" id="itemStockWarning_<?= $idx ?>" style="display:none;">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                    <span id="itemStockWarningText_<?= $idx ?>">পর্যাপ্ত মজুদ নেই</span>
                                </div>
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
const ITEM_COUNT  = <?= count($items) ?>;
const TOTAL_PAID  = <?= (float)$totalPaid ?>;
const OLD_BY_PURITY = <?= json_encode($oldByPurity) ?>;
const SALE_KARATS   = [18, 20, 21, 22, 24];

let liveStockData   = {};
let liveStockLoaded = false;

function tradToGrams(v,a,r,p){
    return v*G_VORI + a*G_ANA + r*G_ROTI + p*G_POINT;
}
function fmtBDT(n){ return '৳' + Math.round(n).toLocaleString('en-BD'); }

function gramsToTradStr(g){
    if (g < 0) g = 0;
    const EPS = 1e-9;
    const tv = g / G_VORI;
    let v = Math.floor(tv + EPS);
    let ta = Math.max(0, tv - v) * 16;
    let a = Math.floor(ta + EPS);
    let tr = Math.max(0, ta - a) * 6;
    let r = Math.floor(tr + EPS);
    let p = Math.round(Math.max(0, tr - r) * 10);
    if (p >= 10) { p -= 10; r++; }
    if (r >= 6)  { r -= 6;  a++; }
    if (a >= 16) { a -= 16; v++; }
    return `${v} ভ ${a} আ ${r} র ${p} প`;
}

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
    const price = Math.round((tradToGrams(v,a,r,p) / G_VORI) * (k / 24) * getPureGoldPrice());
    const el = document.getElementById('itemPreview_' + idx);
    if (el) el.textContent = 'আইটেমের দাম: ' + fmtBDT(price);
    recalcSummary();
}

function recalcAll(){
    for (let i = 0; i < ITEM_COUNT; i++) recalcItem(i);
}

function karatLabelJs(k) {
    const n = Number(k);
    return (Number.isInteger(n) ? n : n.toFixed(2)) + 'K';
}

function recalcSummary(){
    let total = 0;
    const pg = getPureGoldPrice();
    const newByPurity = {};

    for (let i = 0; i < ITEM_COUNT; i++){
        const {v,a,r,p,k} = getItemInputs(i);
        const grams = tradToGrams(v,a,r,p);
        total += Math.round((grams / G_VORI) * (k / 24) * pg);

        const kKey = k.toFixed(2);
        newByPurity[kKey] = (newByPurity[kKey] || 0) + grams;
    }

    const due = total - TOTAL_PAID;
    document.getElementById('previewTotal').textContent = fmtBDT(total);
    document.getElementById('previewDue').textContent   = fmtBDT(Math.max(0, due));

    updateStockPanel(newByPurity);
}

// Per-karat: what would remain in inventory after applying the net delta
// (new total for this karat across all edited items, minus the old total
// for this karat before editing).
function remainingForKarat(kKey){
    const currStockGrams = liveStockData[kKey] ?? 0;
    const oldGrams        = OLD_BY_PURITY[kKey] ?? 0;

    let newGrams = 0;
    for (let i = 0; i < ITEM_COUNT; i++){
        const {v,a,r,p,k} = getItemInputs(i);
        if (k.toFixed(2) === kKey) newGrams += tradToGrams(v,a,r,p);
    }

    const netDelta = newGrams - oldGrams;
    return currStockGrams - netDelta;
}

function updateStockPanel(newByPurity){
    let hasShortage = false;

    for (let i = 0; i < ITEM_COUNT; i++){
        const {k} = getItemInputs(i);
        const kKey = k.toFixed(2);

        const rowEl     = document.getElementById(`itemStockRow_${i}`);
        const labelEl   = document.getElementById(`itemStockLabel_${i}`);
        const valueEl   = document.getElementById(`itemStockValue_${i}`);
        const warnEl    = document.getElementById(`itemStockWarning_${i}`);
        const warnTxtEl = document.getElementById(`itemStockWarningText_${i}`);
        if (!rowEl) continue;

        if (labelEl) labelEl.textContent = karatLabelJs(k);

        if (!liveStockLoaded) {
            valueEl.textContent = 'লোড হচ্ছে…';
            rowEl.classList.remove('insufficient');
            if (warnEl) warnEl.style.display = 'none';
            continue;
        }

        const remGrams = remainingForKarat(kKey);

        if (remGrams < -0.0005) {
            hasShortage = true;
            rowEl.classList.add('insufficient');
            valueEl.textContent = gramsToTradStr(0);
            if (warnEl)    warnEl.style.display = 'flex';
            if (warnTxtEl) warnTxtEl.textContent =
                `পর্যাপ্ত ${karatLabelJs(k)} মজুদ নেই — অতিরিক্ত প্রয়োজন: ${gramsToTradStr(Math.abs(remGrams))}`;
        } else {
            rowEl.classList.remove('insufficient');
            valueEl.textContent = gramsToTradStr(remGrams);
            if (warnEl) warnEl.style.display = 'none';
        }
    }

    const noticeBtn = document.getElementById('stockShortageNotice');
    const saveBtn   = document.getElementById('btnSaveItems');

    if (noticeBtn) noticeBtn.style.display = hasShortage ? '' : 'none';
    if (saveBtn)   saveBtn.disabled = hasShortage;
}

function fetchStockData(){
    const spinner = document.getElementById('stockStatusSpinner');
    if (spinner) spinner.style.display = '';

    fetch('gold_sale_edit_inventory.php?id=<?= $saleId ?>&action=stock_all')
        .then(r => r.json())
        .then(res => {
            if (res.success && Array.isArray(res.stock)) {
                liveStockData = {};
                res.stock.forEach(st => {
                    const kKey = parseFloat(st.purity).toFixed(2);
                    liveStockData[kKey] = parseFloat(st.left_weight) || 0;
                });
                liveStockLoaded = true;
            }
            recalcAll();
        })
        .catch(err => {
            console.error('Failed to fetch stock:', err);
            recalcAll();
        })
        .finally(() => {
            if (spinner) spinner.style.display = 'none';
        });
}

document.getElementById('editModal').addEventListener('shown.bs.modal', fetchStockData);
</script>

<?php else: ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>

<?php if (!$fullyPaid): ?>
<script>
'use strict';
(function initSalePaymentModal(){
    const DUE = <?= $dueAmount ?>;
    const inp  = document.getElementById('salePayAmountInput');
    const btn  = document.getElementById('btnRecordSalePayment');
    const row  = document.getElementById('dueAfterRow');
    const val  = document.getElementById('dueAfterValue');
    const hint = document.getElementById('salePayAmountHint');
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