<?php
/**
 * inventory_lib.php
 * FineBullion Desk — Shared Inventory helpers
 *
 * Included by: gold_sale.php, gold_sale_edit.php,
 *              gold_exchange.php, gold_exchange_edit.php
 *
 * Include AFTER auth.php (needs $conn) and AFTER the including page's own
 * gram/traditional conversion helpers are defined (fmt_trad_g() below
 * detects and reuses whichever naming convention the page already has —
 * see the compatibility shim at the bottom of this file).
 *
 * Usage:
 *   require_once __DIR__ . '/auth.php';
 *   require_once __DIR__ . '/inventory_lib.php';
 *   ...
 *   mysqli_begin_transaction($conn);
 *   try {
 *       // ... existing sale/exchange inserts or updates ...
 *       inventory_deduct($conn, 21.00, $grams, '21K বিক্রয়');
 *       mysqli_commit($conn);
 *   } catch (InventoryException $e) {
 *       mysqli_rollback($conn);
 *       // show $e->getMessage() to the user
 *   } catch (\Throwable $e) {
 *       mysqli_rollback($conn);
 *       // generic failure message
 *   }
 *
 * Core rule (unchanged from the Inventory module):
 *   stock_in                 -> total_weight UP,  left_weight UP
 *   gold_sale (create)       -> left_weight DOWN per item's karat
 *   gold_exchange (create)   -> 24K left_weight DOWN by final_pure_gold
 *   edit (either module)     -> net delta = new - old, applied to left_weight
 *   total_weight is NEVER touched by sale/exchange — only stock_in changes it.
 */

if (!class_exists('InventoryException')) {
    class InventoryException extends \RuntimeException {}
}

/**
 * Lock and fetch the inventory row for a karat, inside an open transaction.
 * Uses SELECT ... FOR UPDATE so two concurrent sales/exchanges against the
 * same karat can't both pass a stale "enough stock?" check and both deduct,
 * pushing left_weight negative. Must be called inside an open
 * mysqli_begin_transaction($conn) block.
 */
function inventory_lock_row(mysqli $conn, float $purity): ?array
{
    $stmt = mysqli_prepare($conn,
        "SELECT id, purity, total_weight, left_weight, minimum_stock
         FROM inventory WHERE purity = ? FOR UPDATE");
    mysqli_stmt_bind_param($stmt, 'd', $purity);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * Reduce left_weight for a karat by $grams.
 * Throws InventoryException (with a ready-to-display Bengali message) if
 * the karat is unknown or there isn't enough left_weight.
 * $context is prepended to the shortage message, e.g. "আইটেম ২" or "২১K বিক্রয়".
 * No-op if $grams <= 0.
 */
function inventory_deduct(mysqli $conn, float $purity, float $grams, string $context = ''): void
{
    if ($grams <= 0) return;

    $row = inventory_lock_row($conn, $purity);
    if (!$row) {
        throw new InventoryException("অজানা ক্যারেট (" . karat_label($purity) . ") — ইনভেন্টরিতে পাওয়া যায়নি।");
    }

    $left = (float)$row['left_weight'];
    if ($left - $grams < -0.0005) { // epsilon guards against float/DECIMAL rounding noise
        throw new InventoryException(sprintf(
            '%sপর্যাপ্ত %s সোনার মজুদ নেই। বর্তমান মজুদ: %s, প্রয়োজন: %s, ঘাটতি: %s',
            $context !== '' ? "$context: " : '',
            karat_label($purity),
            fmt_trad_g($left),
            fmt_trad_g($grams),
            fmt_trad_g(max(0.0, $grams - $left))
        ));
    }

    $stmt = mysqli_prepare($conn,
        "UPDATE inventory SET left_weight = left_weight - ? WHERE purity = ?");
    mysqli_stmt_bind_param($stmt, 'dd', $grams, $purity);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * Increase left_weight for a karat by $grams (used when reversing an edit,
 * or restoring stock on delete). total_weight is never touched here — only
 * Stock In changes total_weight. No-op if $grams <= 0.
 */
function inventory_restore(mysqli $conn, float $purity, float $grams): void
{
    if ($grams <= 0) return;

    $row = inventory_lock_row($conn, $purity);
    if (!$row) {
        throw new InventoryException("অজানা ক্যারেট (" . karat_label($purity) . ") — ইনভেন্টরিতে পাওয়া যায়নি।");
    }

    $stmt = mysqli_prepare($conn,
        "UPDATE inventory SET left_weight = left_weight + ? WHERE purity = ?");
    mysqli_stmt_bind_param($stmt, 'dd', $grams, $purity);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * Apply a signed net delta to a karat's left_weight in one call.
 * Positive delta = needs more stock (deduct). Negative delta = frees stock
 * (restore). |delta| <= 0.0005 is treated as unchanged (no-op), matching
 * the epsilon used everywhere else in this file.
 *
 * This is the primitive edit flows should use: compute
 * `$delta = $newWeight - $oldWeight` per karat, then call this once per karat.
 */
function inventory_apply_delta(mysqli $conn, float $purity, float $delta, string $context = ''): void
{
    if ($delta > 0.0005) {
        inventory_deduct($conn, $purity, $delta, $context);
    } elseif ($delta < -0.0005) {
        inventory_restore($conn, $purity, -$delta);
    }
    // else: no-op, net change is negligible
}

/**
 * Validate a shortage/insufficient-stock check WITHOUT mutating anything.
 * Useful for a pre-check pass before applying several deltas (e.g. edit
 * flows that want to validate every karat needing more stock before
 * touching any of them). Throws InventoryException on shortage, otherwise
 * returns silently.
 */
function inventory_assert_available(mysqli $conn, float $purity, float $grams, string $context = ''): void
{
    if ($grams <= 0.0005) return;

    $row = inventory_lock_row($conn, $purity);
    if (!$row) {
        throw new InventoryException("অজানা ক্যারেট (" . karat_label($purity) . ") — ইনভেন্টরিতে পাওয়া যায়নি।");
    }

    $left = (float)$row['left_weight'];
    if ($left - $grams < -0.0005) {
        throw new InventoryException(sprintf(
            '%s এর পর্যাপ্ত মজুদ নেই এই পরিবর্তনের জন্য। বর্তমান মজুদ: %s, প্রয়োজনীয় অতিরিক্ত: %s',
            karat_label($purity),
            fmt_trad_g($left),
            fmt_trad_g($grams)
        ));
    }
}

/**
 * "18.00" -> "18K", "19.50" -> "19.5K", "24.00" -> "24K"
 * Matches the karat display convention used across inventory.php.
 */
function karat_label(float $p): string
{
    $s = rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.');
    return $s . 'K';
}

/**
 * Round a raw purity value to the nearest valid inventory karat
 * (18.00 / 20.00 / 21.00 / 22.00 / 24.00). Only relevant if a page allows
 * free-decimal purity input but inventory needs a fixed bucket to deduct
 * from — see the "Option B" discussion in the integration spec. Not used
 * by the default (Option A) flows, but kept here so any page can opt in
 * without duplicating the karat list.
 */
function snap_to_inventory_karat(float $purity): float
{
    static $karats = [18.00, 20.00, 21.00, 22.00, 24.00];
    $best = $karats[0];
    $bestDiff = abs($purity - $best);
    foreach ($karats as $k) {
        $diff = abs($purity - $k);
        if ($diff < $bestDiff) {
            $best = $k;
            $bestDiff = $diff;
        }
    }
    return $best;
}

// -----------------------------------------------------------------------
// Compatibility shim: reuse whichever grams<->traditional formatter the
// including page already defines, instead of redefining/duplicating it.
//
//   gold_exchange.php, gold_sale.php  -> grams_to_traditional() + format_traditional()
//   gold_sale_edit.php, gold_exchange_edit.php -> grams_to_trad() + fmt_trad()
// -----------------------------------------------------------------------
if (!function_exists('fmt_trad_g')) {
    function fmt_trad_g(float $grams): string
    {
        if (function_exists('format_traditional') && function_exists('grams_to_traditional')) {
            return format_traditional(grams_to_traditional($grams));
        }
        if (function_exists('fmt_trad')) {
            return fmt_trad($grams);
        }
        // Fallback — should not be reached in any of the four target files,
        // since each already defines one of the pairs above.
        return number_format($grams, 3) . ' গ্রাম';
    }
}