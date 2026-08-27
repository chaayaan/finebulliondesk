<?php
/**
 * gold_exchange_list.php
 * FineBullion Desk — Gold Exchange history / listing
 */

require_once __DIR__ . '/auth.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$action = $_GET['action'] ?? $_POST['action'] ?? null;

// -----------------------------------------------------------------------
// Helper & Conversion Functions
// -----------------------------------------------------------------------
function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

define('G_PER_VORI', 11.664);
define('G_PER_ANA', 0.729);
define('G_PER_ROTI', 0.1215);
define('G_PER_POINT', 0.01215);

function gramsToTraditionalPHP($grams): array
{
    $EPS = 1e-9;
    $grams = (float)($grams ?? 0);
    $totalVori = $grams / G_PER_VORI;
    $vori = (int)floor($totalVori + $EPS);
    $fracVori = max(0.0, $totalVori - $vori);

    $totalAna = $fracVori * 16;
    $ana = (int)floor($totalAna + $EPS);
    $fracAna = max(0.0, $totalAna - $ana);

    $totalRoti = $fracAna * 6;
    $roti = (int)floor($totalRoti + $EPS);
    $fracRoti = max(0.0, $totalRoti - $roti);

    $point = (int)round($fracRoti * 10);

    if ($point >= 10) { $point -= 10; $roti += 1; }
    if ($roti >= 6)   { $roti -= 6;   $ana  += 1; }
    if ($ana  >= 16)  { $ana  -= 16;  $vori += 1; }

    return ['vori' => $vori, 'ana' => $ana, 'roti' => $roti, 'point' => $point];
}

function fmtTradPHP($grams): string
{
    $t = gramsToTraditionalPHP($grams);
    return "{$t['vori']} ভ {$t['ana']} আ {$t['roti']} র {$t['point']} প";
}

function lossPointsPHP($lossGrams): int
{
    return (int)round((float)($lossGrams ?? 0) / G_PER_POINT);
}

function fmtDatePHP($s): string
{
    if (!$s) return '—';
    $time = strtotime($s);
    if (!$time) return '—';
    return date('d M Y', $time);
}

// -----------------------------------------------------------------------
// AJAX / Server-Rendered Actions
// -----------------------------------------------------------------------
if ($isAjax || $action !== null) {

    // ---- LIST ------------------------------------------------------------
    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $search   = trim($_GET['search']    ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo   = trim($_GET['date_to']   ?? '');
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $perPage  = 50;
        $offset   = ($page - 1) * $perPage;

        $conditions = [];
        $params     = [];
        $types      = '';

        if ($search !== '') {
            $conditions[] = "(c.name LIKE ? OR c.phone LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like;
            $types .= 'ss';
        }
        if ($dateFrom !== '') {
            $conditions[] = "DATE(ge.created_at) >= ?";
            $params[] = $dateFrom; $types .= 's';
        }
        if ($dateTo !== '') {
            $conditions[] = "DATE(ge.created_at) <= ?";
            $params[] = $dateTo; $types .= 's';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Totals (for stat bar)
        $totSql = "SELECT
                       COALESCE(SUM(gei.old_gold_weight), 0) AS total_impure_gold,
                       COALESCE(SUM(ge.total_pure_gold),  0) AS total_pure_gold,
                       COALESCE(SUM(ge.loss),             0) AS total_loss,
                       COALESCE(SUM(ge.final_pure_gold),  0) AS net_pure_gold_output
                   FROM gold_exchanges ge
                   JOIN customers c ON c.id = ge.customer_id
                   LEFT JOIN (
                       SELECT gold_exchange_id, SUM(old_gold_weight) AS old_gold_weight
                       FROM gold_exchange_items GROUP BY gold_exchange_id
                   ) gei ON gei.gold_exchange_id = ge.id
                   $where";
        $totStmt = mysqli_prepare($conn, $totSql);
        if ($params) mysqli_stmt_bind_param($totStmt, $types, ...$params);
        mysqli_stmt_execute($totStmt);
        $totRow = mysqli_fetch_assoc(mysqli_stmt_get_result($totStmt));

        $cntSql = "SELECT COUNT(*) FROM gold_exchanges ge
                   JOIN customers c ON c.id = ge.customer_id
                   $where";
        $cntStmt = mysqli_prepare($conn, $cntSql);
        if ($params) mysqli_stmt_bind_param($cntStmt, $types, ...$params);
        mysqli_stmt_execute($cntStmt);
        mysqli_stmt_bind_result($cntStmt, $total);
        mysqli_stmt_fetch($cntStmt);
        mysqli_stmt_close($cntStmt);

        $sql = "SELECT ge.id, ge.customer_id, c.name AS customer_name, c.phone AS customer_phone,
                       ge.total_pure_gold, ge.loss, ge.final_pure_gold, ge.loss_rate_points_per_vori, ge.note,
                       ge.created_at, u.username AS created_by_username
                FROM gold_exchanges ge
                JOIN customers c ON c.id = ge.customer_id
                LEFT JOIN users u ON u.id = ge.created_by
                $where
                ORDER BY ge.created_at DESC
                LIMIT ? OFFSET ?";
        $stmt = mysqli_prepare($conn, $sql);

        if ($params) {
            $allParams = array_merge($params, [$perPage, $offset]);
            mysqli_stmt_bind_param($stmt, $types . 'ii', ...$allParams);
        } else {
            mysqli_stmt_bind_param($stmt, 'ii', $perPage, $offset);
        }

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }

        json_out([
            'success'    => true,
            'data'       => $rows,
            'totals'     => $totRow,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalRows'  => (int)$total,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
        ]);
    }

    // ---- GET single (Server-Rendered PHP) --------------------------------
    if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo '<div class="text-danger p-3">আইডি সঠিক নয়।</div>';
            exit;
        }

        $stmt = mysqli_prepare($conn,
            "SELECT ge.id, ge.customer_id, c.name AS customer_name, c.phone AS customer_phone,
                    ge.total_pure_gold, ge.loss, ge.final_pure_gold, ge.loss_rate_points_per_vori, ge.note,
                    ge.created_at, ge.updated_at, u.username AS created_by_username
             FROM gold_exchanges ge
             JOIN customers c ON c.id = ge.customer_id
             LEFT JOIN users u ON u.id = ge.created_by
             WHERE ge.id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $exchange = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$exchange) {
            echo '<div class="text-danger p-3">সোনা এক্সচেঞ্জের তথ্য পাওয়া যায়নি।</div>';
            exit;
        }

        $itemStmt = mysqli_prepare($conn,
            "SELECT id, old_gold_weight, old_gold_purity, pure_gold_weight
             FROM gold_exchange_items
             WHERE gold_exchange_id = ?
             ORDER BY id ASC");
        mysqli_stmt_bind_param($itemStmt, 'i', $id);
        mysqli_stmt_execute($itemStmt);
        $itemsResult = mysqli_stmt_get_result($itemStmt);
        $items = [];
        while ($row = mysqli_fetch_assoc($itemsResult)) {
            $items[] = $row;
        }

        $lossRate = isset($exchange['loss_rate_points_per_vori']) ? (float)$exchange['loss_rate_points_per_vori'] : 1;
        $lossPointsVal = lossPointsPHP($exchange['loss']);
        ?>
        <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
            <div>
                <div class="fw-bold fs-6"><?= htmlspecialchars($exchange['customer_name']) ?></div>
                <small class="text-secondary"><?= htmlspecialchars($exchange['customer_phone'] ?? '') ?></small>
            </div>
            <div class="text-end">
                <small class="text-secondary d-block"><?= htmlspecialchars(fmtDatePHP($exchange['created_at'])) ?></small>
                <small class="text-secondary">এন্ট্রি প্রদানকারী: <?= htmlspecialchars($exchange['created_by_username'] ?? '—') ?></small>
            </div>
        </div>

        <div class="table-responsive mb-3">
          <table class="table align-middle gold-items-table">
              <thead>
                  <tr>
                      <th class="col-num">#</th>
                      <th class="col-old">পুরাতন সোনা</th>
                      <th class="col-karat">ক্যারেট</th>
                      <th class="col-pure">পাকা সোনা</th>
                  </tr>
              </thead>
              <tbody>
                  <?php foreach ($items as $idx => $it): 
                      $karat = ((float)$it['old_gold_purity'] / 100) * 24;
                      $oldTrad = fmtTradPHP($it['old_gold_weight']);
                      $pureTrad = fmtTradPHP($it['pure_gold_weight']);
                  ?>
                  <tr>
                      <td class="text-secondary col-num"><?= $idx + 1 ?></td>
                      <td class="col-old"><?= htmlspecialchars($oldTrad) ?></td>
                      <td class="col-karat"><?= number_format($karat, 2, '.', '') ?> K</td>
                      <td class="col-pure"><?= htmlspecialchars($pureTrad) ?></td>
                  </tr>
                  <?php endforeach; ?>
              </tbody>
          </table>
      </div>

        <table class="ledger-table mb-3">
            <tbody>
                <tr class="ledger-total">
                    <td class="ledger-label">মোট পাকা সোনা</td>
                    <td class="ledger-vorp"><?= htmlspecialchars(fmtTradPHP($exchange['total_pure_gold'])) ?></td>
                </tr>
                <tr class="ledger-loss">
                    <td class="ledger-label">লস <span class="ledger-rate">(<?= $lossPointsVal ?> পয়েন্ট @ <?= $lossRate ?> প/ভ)</span></td>
                    <td class="ledger-vorp"><?= htmlspecialchars(fmtTradPHP($exchange['loss'])) ?></td>
                </tr>
                <tr class="ledger-final">
                    <td class="ledger-label">চূড়ান্ত পাকা সোনা</td>
                    <td class="ledger-vorp"><?= htmlspecialchars(fmtTradPHP($exchange['final_pure_gold'])) ?></td>
                </tr>
            </tbody>
        </table>

        <?php if (!empty($exchange['note'])): ?>
            <div class="alert-fb mb-0"><strong>নোট:</strong> <?= htmlspecialchars($exchange['note']) ?></div>
        <?php endif; ?>
        <?php
        exit;
    }

    json_out(['success' => false, 'message' => 'অজানা অ্যাকশন।'], 400);
}
?>
<!DOCTYPE html>
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>সোনা এক্সচেঞ্জের ইতিহাস — FineBullion Desk</title>
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
}

body {
  background: var(--bg-app);
  font-family: 'Inter', 'Noto Sans Bengali', system-ui, -apple-system, sans-serif;
  color: var(--text-primary);
  margin: 0;
  padding: 0;
}

/* Page Header System */
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
  box-shadow: var(--shadow);
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
  font-size: 22px;
  color: var(--text-on-navy);
  margin: 0;
  font-weight: 700;
  line-height: 1.3;
}
.page-header small, .page-header .subtitle {
  color: rgba(255,255,255,.78);
  font-size: 13px;
  font-weight: 500;
}

/* Header Action Button */
.header-action-btn {
  background: var(--navy);
  border: 1.5px solid #fff;
  color: #fff;
  border-radius: 8px;
  font-weight: 600;
  font-size: 13px;
  padding: .45rem .9rem;
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  white-space: nowrap;
  text-decoration: none;
  transition: all .15s ease-in-out;
}
.header-action-btn:hover, .header-action-btn:focus {
  background: var(--teal);
  border-color: #fff;
  color: #fff;
}
.header-action-btn i, .header-action-btn svg { color: #fff; }

/* Inset Container */
.page-inset {
  padding: 1.25rem 1.5rem;
}

/* Cards & Summary Section */
.section-block {
  margin-bottom: 1.25rem;
}
.card, .sc-card {
  background: var(--bg-card);
  border: 1px solid var(--border-default);
  border-radius: 14px;
  box-shadow: var(--shadow);
  padding: 1.1rem;
}
.sc-card {
  padding: 0;
  overflow: hidden;
}
.sc-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 10px 14px;
  border-bottom: 1px solid var(--border-default);
  background: var(--bg-card);
}
.sc-header-left {
  display: flex;
  align-items: center;
  gap: 8px;
}
.sc-icon {
  width: 26px;
  height: 26px;
  min-width: 26px;
  border-radius: 50%;
  background: var(--sky);
  border: 1px solid var(--border-default);
  color: var(--navy);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.75rem;
}
.section-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--navy);
  margin: 0;
}
.sc-header-icon {
  color: var(--teal);
  font-size: 0.85rem;
  opacity: 0.8;
}

/* Stat Bar */
.stat-bar {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
}
.stat-cell {
  padding: 10px 12px;
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 8px;
  border-right: 1px solid var(--border-default);
  background: var(--bg-card);
}
.stat-cell:last-child {
  border-right: none;
}
.stat-cell .s-icon {
  width: 26px;
  height: 26px;
  min-width: 26px;
  border-radius: 50%;
  background: var(--sky);
  color: var(--navy);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.72rem;
}
.stat-cell .s-text {
  display: flex;
  flex-direction: column;
  gap: 1px;
  min-width: 0;
}
.stat-cell .s-label {
  font-size: 9px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--text-secondary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.stat-cell .s-value {
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0.01em;
  color: var(--text-primary);
  white-space: nowrap;
}
.stat-cell.stat-emphasis {
  background: var(--sky);
}
.stat-cell.stat-emphasis .s-value {
  color: var(--navy);
}
.stat-cell.stat-emphasis .s-icon {
  background: #ffffff;
  color: var(--navy);
}
.stat-cell.stat-due {
  background: #FBECEC;
}
.stat-cell.stat-due .s-value {
  color: var(--danger);
}
.stat-cell.stat-due .s-icon {
  background: #ffffff;
  color: var(--danger);
}

/* Card Headers & Layout */
.card-header-custom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: .75rem;
  margin-bottom: .85rem;
}
.card-header-title {
  font-size: 16px;
  font-weight: 700;
  color: var(--text-primary);
  margin: 0;
  display: flex;
  align-items: center;
  gap: .4rem;
}

/* Filter Bar */
.filter-bar {
  background: var(--bg-app);
  border: 1px solid var(--border-default);
  border-radius: 8px;
  padding: .65rem .85rem;
  display: flex;
  align-items: center;
  gap: .6rem;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}
.filter-bar label {
  font-size: 12.5px;
  font-weight: 700;
  color: var(--text-secondary);
  text-transform: uppercase;
  margin: 0;
}
.filter-bar input[type=date] {
  font-size: 0.82rem;
  padding: 0.3rem 0.5rem;
  border: 1.5px solid var(--border-default);
  border-radius: 8px;
  color: var(--text-primary);
  background: #fff;
  width: auto;
}
.filter-bar input[type=date]:focus {
  border-color: var(--teal);
  box-shadow: 0 0 0 3px rgba(86,124,141,.15);
  outline: none;
}
.filter-bar .btn-reset {
  background: #fff;
  border: 1.5px solid var(--border-default);
  color: var(--navy);
  border-radius: 8px;
  font-weight: 600;
  font-size: 12.5px;
  padding: .3rem .65rem;
  white-space: nowrap;
}
.filter-bar .btn-reset:hover {
  background: var(--bg-hover);
  border-color: var(--teal);
}

/* Form Controls & Inputs */
.form-control, .form-select, textarea {
  background: #fff;
  border: 1.5px solid var(--border-default);
  border-radius: 8px;
  color: var(--text-primary);
  font-size: 14px;
  padding: .55rem .75rem;
  transition: border-color .15s, box-shadow .15s;
}
.form-control::placeholder {
  color: var(--text-secondary);
  opacity: .7;
}
.form-control:focus, .form-select:focus, textarea:focus {
  border-color: var(--teal);
  box-shadow: 0 0 0 3px rgba(86,124,141,.15);
  outline: none;
}

/* Buttons */
.btn-primary {
  background: var(--navy);
  border: 1.5px solid var(--navy);
  color: #fff;
  border-radius: 8px;
  font-weight: 600;
  font-size: 14px;
  padding: .55rem 1.1rem;
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
  border-radius: 8px;
  font-weight: 600;
  font-size: 14px;
  padding: .55rem 1.1rem;
}
.btn-secondary:hover {
  background: var(--bg-hover);
  border-color: var(--teal);
  color: var(--navy);
}
.btn-outline {
  background: transparent;
  border: 1.5px solid var(--teal);
  color: var(--teal);
  border-radius: 8px;
  font-weight: 600;
  font-size: 13px;
  padding: .3rem .55rem;
}
.btn-outline:hover {
  background: var(--sky);
  color: var(--navy);
  border-color: var(--navy);
}

.btn-danger {
  background: #fff;
  border: 1.5px solid var(--danger);
  color: var(--danger);
  border-radius: 8px;
  font-weight: 600;
  font-size: 14px;
  padding: .55rem 1.1rem;
}
.btn-danger:hover {
  background: var(--danger);
  color: #fff;
}

.btn-actions {
  display: flex;
  gap: 6px;
  justify-content: center;
}

/* Status Chips & Badges */
.chip-paid {
  background: #EAF3EE;
  color: var(--success);
  border-radius: 999px;
  padding: 2px 10px;
  font-size: 11px;
  font-weight: 700;
  display: inline-block;
}
.chip-due {
  background: #FBECEC;
  color: var(--danger);
  border-radius: 999px;
  padding: 2px 10px;
  font-size: 11px;
  font-weight: 700;
  display: inline-block;
}
.chip-total {
  background: var(--sky);
  color: var(--navy);
  border-radius: 999px;
  padding: 2px 10px;
  font-size: 11px;
  font-weight: 700;
  display: inline-block;
}

/* Tables */
.table-responsive {
  border: 1px solid var(--border-default);
  border-radius: 8px;
  overflow: hidden;
}
table.table {
  width: 100%;
  margin-bottom: 0;
}
table.table thead th {
  background: var(--beige);
  color: var(--text-secondary);
  font-size: 11.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  border-bottom: 1.5px solid var(--border-default);
  padding: .55rem .6rem;
  white-space: nowrap;
}
table.table tbody td {
  padding: .5rem .6rem;
  border-bottom: 1px solid var(--border-default);
  font-size: 13.5px;
  color: var(--text-primary);
}
table.table tbody tr:last-child td {
  border-bottom: none;
}
table.table-hover tbody tr:hover {
  background-color: var(--bg-hover);
}

/* Mobile Stack View Cells */
.exchange-info-cell {
  min-width: 150px;
}
.exchange-info-cell .info-row {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 12px;
  line-height: 1.4;
  white-space: nowrap;
}
.exchange-info-cell .info-label {
  color: var(--text-secondary);
}
.exchange-info-cell .info-value {
  font-weight: 600;
  color: var(--text-primary);
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
  border-bottom: none;
  padding: 1rem 1.25rem;
}
.modal-header .modal-title {
  color: var(--text-on-navy);
  font-size: 16px;
  font-weight: 700;
}
.modal-header .btn-close {
  filter: brightness(0) invert(1);
}
.modal-footer {
  border-top: 1px solid var(--border-default);
  padding: .75rem 1.25rem;
  background: #fff;
}
/* Gold items modal table styling */
.gold-items-table {
  width: 100%;
}

/* Reduced spacing for mobile view */
.gold-items-table th, 
.gold-items-table td {
  padding-left: 0.25rem !important;
  padding-right: 0.25rem !important;
  white-space: nowrap;
}

.gold-items-table .col-num { width: 25px; }
.gold-items-table .col-old { text-align: left; }
.gold-items-table .col-karat { text-align: center; width: 70px; }
.gold-items-table .col-pure { text-align: right; }

/* PC View: Space-around column distribution */
@media (min-width: 768px) {
  .gold-items-table {
    table-layout: fixed;
  }
  .gold-items-table th, 
  .gold-items-table td {
    padding-left: 1.25rem !important;
    padding-right: 1.25rem !important;
  }
  .gold-items-table .col-num { width: 8%; }
  .gold-items-table .col-old { width: 34%; text-align: left; }
  .gold-items-table .col-karat { width: 24%; text-align: center; }
  .gold-items-table .col-pure { width: 34%; text-align: right; }
}

/* Modal Summary Ledger Table */
.ledger-table {
  border: 1px solid var(--border-default);
  border-radius: 8px;
  overflow: hidden;
  width: 100%;
}
.ledger-table td {
  padding: 0.6rem 0.9rem;
  vertical-align: middle;
  border-bottom: 1px solid var(--border-default);
  font-size: 13.5px;
}
.ledger-table tr:last-child td {
  border-bottom: none;
}
.ledger-label {
  font-size: 12.5px;
  font-weight: 700;
  color: var(--text-secondary);
  white-space: nowrap;
  width: 1%;
}
.ledger-rate {
  font-weight: 400;
  color: var(--text-secondary);
  font-size: 12px;
}
.ledger-vorp {
  font-weight: 700;
  font-size: 14px;
  text-align: right;
  color: var(--text-primary);
}

.ledger-total td {
  background-color: #FBECEC !important;
}
.ledger-total .ledger-label, .ledger-total .ledger-vorp {
  color: var(--danger);
}

.ledger-loss td {
  background-color: var(--bg-hover) !important;
}
.ledger-loss .ledger-label, .ledger-loss .ledger-vorp {
  color: var(--text-primary);
}

.ledger-final td {
  background-color: var(--navy) !important;
}
.ledger-final .ledger-label, .ledger-final .ledger-vorp {
  color: #fff;
}

/* Pagination */
.pagination {
  gap: 4px;
}
.pagination .page-link {
  color: var(--navy);
  border: 1.5px solid var(--border-default);
  border-radius: 8px !important;
  font-size: 13px;
  font-weight: 600;
  padding: .35rem .75rem;
}
.pagination .page-item.active .page-link {
  background: var(--navy);
  border-color: var(--navy);
  color: #fff;
}
.pagination .page-item.disabled .page-link {
  color: var(--text-secondary);
  background: var(--bg-app);
}

/* Note Alert */
.alert-fb {
  background: var(--beige);
  border: 1px solid var(--border-default);
  color: var(--text-primary);
  border-radius: 8px;
  padding: .65rem .85rem;
  font-size: 13px;
}

/* Responsive Media Queries */
@media (min-width: 768px) and (max-width: 991.98px) {
  .stat-bar {
    grid-template-columns: repeat(2, 1fr);
  }
  .stat-cell {
    border-right: 1px solid var(--border-default);
    border-bottom: 1px solid var(--border-default);
  }
  .stat-bar .stat-cell:nth-child(2n) {
    border-right: none;
  }
  .stat-bar .stat-cell:nth-last-child(-n+2) {
    border-bottom: none;
  }
}

@media (max-width: 767.98px) {
  .page-inset {
    padding: 0 .8rem 1rem;
  }
  .section-block {
    margin-bottom: .75rem;
  }
  .card, .sc-card {
    border-radius: 12px;
  }
  .sc-header {
    padding: 8px 10px;
  }
  .sc-icon {
    width: 22px;
    height: 22px;
    font-size: 0.65rem;
  }
  .section-label {
    font-size: 10px;
    letter-spacing: 0.03em;
  }
  .sc-header-icon {
    font-size: 0.75rem;
  }
  .stat-bar {
    grid-template-columns: repeat(2, 1fr);
  }
  .stat-cell {
    padding: 8px 9px;
    gap: 6px;
    border-right: 1px solid var(--border-default);
    border-bottom: 1px solid var(--border-default);
  }
  .stat-bar .stat-cell:nth-child(2n) {
    border-right: none;
  }
  .stat-bar .stat-cell:nth-last-child(-n+2) {
    border-bottom: none;
  }
  .stat-cell .s-icon {
    width: 22px;
    height: 22px;
    min-width: 22px;
    font-size: 0.62rem;
  }
  .stat-cell .s-label {
    font-size: 8px;
  }
  .stat-cell .s-value {
    font-size: 11.5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  table.table {
    font-size: 0.78rem;
  }
  table.table th, table.table td {
    padding: .5rem .3rem;
  }
  table.table th:first-child, table.table td:first-child {
    padding-left: .35rem;
    padding-right: .15rem;
  }
  table.table th:nth-child(2), table.table td:nth-child(2) {
    padding-right: .2rem;
  }
  table.table th:last-child, table.table td:last-child {
    padding-left: .15rem;
    padding-right: .3rem;
  }
  .card-header-custom .input-group {
    max-width: 100% !important;
    width: 100%;
  }
  .filter-bar {
    padding: .5rem .6rem;
    gap: .35rem;
  }
  .filter-bar label {
    font-size: 10.5px;
  }
  .filter-bar input[type=date] {
    font-size: .74rem;
    padding: .22rem .3rem;
    width: 108px;
  }
  .filter-bar input[type=date]::-webkit-calendar-picker-indicator {
    padding: 0;
    margin-left: 2px;
  }
  .filter-bar .btn-reset {
    padding: .22rem .5rem;
    font-size: .72rem;
  }
  .btn-actions {
    flex-direction: column;
    gap: 4px;
  }
  .btn-actions .btn-outline {
    padding: .2rem .4rem;
    font-size: .75rem;
  }
  .exchange-info-cell {
    min-width: 118px;
  }
  .exchange-info-cell .info-row {
    font-size: 11px;
    gap: .3rem;
  }
  table.table th:first-child {
    width: 40px;
  }
  table.table th:last-child {
    width: 44px;
  }
}

@media (max-width: 576px) {
  .page-inset {
    padding: 1rem;
  }
  .page-header {
    padding: .85rem 1.1rem;
    border-radius: 0 0 14px 14px;
  }
  .page-header h1 {
    font-size: 18px;
  }
  .header-action-btn {
    padding: .4rem .75rem;
    font-size: 12.5px;
  }
  .card, .sc-card {
    padding: .85rem;
    border-radius: 14px;
  }
  .stat-bar {
    grid-template-columns: repeat(2, 1fr);
  }
  .stat-cell {
    padding: .65rem .75rem;
  }
  .form-control, .form-select, textarea {
    font-size: 16px;
    padding: .6rem .8rem;
  }
  .btn-primary, .btn-secondary {
    padding: .6rem 1rem;
    font-size: 13.5px;
  }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
  <!-- Page Header -->
  <div class="page-header">
    <div class="header-left">
      <h1>
        <i class="bi bi-arrow-left-right me-1"></i>
        <span class="d-none d-md-inline">সোনা এক্সচেঞ্জের ইতিহাস</span>
        <span class="d-md-none">সোনা এক্সচেঞ্জের তালিকা</span>
      </h1>
      <small class="subtitle">FineBullion Desk</small>
    </div>
    <div class="header-right">
      <a href="gold_exchange_inventory.php" class="header-action-btn">
        <i class="bi bi-plus-lg"></i> <span>নতুন সোনা এক্সচেঞ্জ</span>
      </a>
    </div>
  </div>

  <div class="page-inset">
    <!-- Summary Card Block -->
    <div class="section-block">
      <div class="sc-card">
        <div class="sc-header">
          <div class="sc-header-left">
            <div class="sc-icon"><i class="bi bi-arrow-left-right"></i></div>
            <p class="section-label">সোনা এক্সচেঞ্জ</p>
          </div>
          <i class="bi bi-bar-chart-line sc-header-icon"></i>
        </div>
        <div class="stat-bar" id="statBar">
          <div class="stat-cell">
            <div class="s-icon"><i class="bi bi-bricks"></i></div>
            <div class="s-text">
              <span class="s-label">মোট পুরাতন সোনা</span>
              <span class="s-value" id="statImpure">—</span>
            </div>
          </div>
          <div class="stat-cell">
            <div class="s-icon"><i class="bi bi-gem"></i></div>
            <div class="s-text">
              <span class="s-label">মোট পাকা সোনা</span>
              <span class="s-value" id="statPure">—</span>
            </div>
          </div>
          <div class="stat-cell">
            <div class="s-icon"><i class="bi bi-graph-down-arrow"></i></div>
            <div class="s-text">
              <span class="s-label">মোট লস</span>
              <span class="s-value" id="statLoss">—</span>
            </div>
          </div>
          <div class="stat-cell stat-emphasis">
            <div class="s-icon"><i class="bi bi-bullseye"></i></div>
            <div class="s-text">
              <span class="s-label">চূড়ান্ত পাকা সোনা</span>
              <span class="s-value" id="statOutput">—</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Data Table Container -->
    <div class="card">
      <div class="card-header-custom">
        <h2 class="card-header-title"><i class="bi bi-list-ul me-1"></i> সোনা এক্সচেঞ্জসমূহ</h2>
        <div class="input-group" style="max-width:320px;">
          <input type="text" id="searchInput" class="form-control" placeholder="কাস্টমারের নাম, ফোন নম্বর খুঁজুন…">
          <button class="btn btn-secondary" id="clearSearchBtn" type="button"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>

      <!-- Date Filter Bar -->
      <div class="filter-bar">
        <label>শুরু</label>
        <input type="date" id="dateFrom">
        <label>শেষ</label>
        <input type="date" id="dateTo">
        <button class="btn-reset ms-auto" id="clearDatesBtn" title="তারিখ মুছুন" type="button">
          <i class="bi bi-arrow-clockwise"></i>
        </button>
      </div>

      <div class="table-responsive mb-3">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th style="width:50px;">#</th>
              <th>কাস্টমার</th>
              <th class="d-none d-md-table-cell">মোট পাকা</th>
              <th class="d-none d-md-table-cell">লস</th>
              <th class="d-none d-md-table-cell">নেট পাকা</th>
              <th class="d-md-none">সোনা এক্সচেঞ্জের তথ্য</th>
              <th class="d-none d-md-table-cell" style="width:110px;">তারিখ</th>
              <th class="d-none d-md-table-cell" style="width:100px;">এন্ট্রি প্রদানকারী</th>
              <th style="width:70px;" class="text-center">অ্যাকশন</th>
            </tr>
          </thead>
          <tbody id="tableBody">
            <tr><td colspan="9" class="text-center text-muted py-4">লোড হচ্ছে…</td></tr>
          </tbody>
        </table>
      </div>

      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <small class="text-secondary" id="paginationInfo">—</small>
        <nav>
          <ul class="pagination pagination-sm mb-0" id="paginationControls"></ul>
        </nav>
      </div>
    </div>
  </div>
</div>

<!-- ================================================================
     VIEW MODAL
     ================================================================ -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-receipt me-2"></i>সোনা এক্সচেঞ্জ #<span id="viewId"></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="viewBody">
        <div class="text-center text-muted py-4">লোড হচ্ছে…</div>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="bi bi-x-lg me-1"></i>বন্ধ করুন
        </button>
        <a id="btnOpenEdit" href="#" class="btn btn-primary">
          <i class="bi bi-pencil-square me-1"></i>সম্পূর্ণ বিস্তারিত / নোট এডিট করুন
        </a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ----------------------------------------------------------------
// Unit conversion constants
// ----------------------------------------------------------------
const G_PER_VORI  = 11.664;
const G_PER_ANA   = 0.729;
const G_PER_ROTI  = 0.1215;
const G_PER_POINT = 0.01215;

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

/** Full long-form: "2 Vori 3 Ana 1 Roti 5 Point" */
function fmtTrad(grams) {
    const t = gramsToTraditional(parseFloat(grams) || 0);
    return `${t.vori} ভ ${t.ana} আ ${t.roti} র ${t.point} প`;
}

/** Loss stored as grams → recover ceiled point count for display */
function lossPoints(lossGrams) {
    return Math.round(parseFloat(lossGrams) / G_PER_POINT);
}

function fmtDate(s) {
    if (!s) return '—';
    const d = new Date(s.replace(' ', 'T'));
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ----------------------------------------------------------------
// Table list
// ----------------------------------------------------------------
let currentPage   = 1;
let currentSearch = '';
let currentFrom   = '';
let currentTo     = '';
let searchTimer   = null;

async function loadList(page = 1) {
    currentPage = page;
    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">লোড হচ্ছে…</td></tr>';

    try {
        const params = new URLSearchParams({
            action: 'list', page,
            search:    currentSearch,
            date_from: currentFrom,
            date_to:   currentTo,
        });
        const res  = await fetch('gold_exchange_list.php?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger py-4">${escHtml(data.message || 'লোড করতে ব্যর্থ হয়েছে।')}</td></tr>`;
            return;
        }

        // Stat bar
        const tot = data.totals || {};
        document.getElementById('statImpure').textContent = fmtTrad(tot.total_impure_gold      || 0);
        document.getElementById('statOutput').textContent = fmtTrad(tot.net_pure_gold_output    || 0);
        document.getElementById('statLoss').textContent   = fmtTrad(tot.total_loss              || 0);
        document.getElementById('statPure').textContent   = fmtTrad(tot.total_pure_gold          || 0);

        if (data.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">কোনো তথ্য পাওয়া যায়নি।</td></tr>';
        } else {
            tbody.innerHTML = data.data.map(row => {
                const lossRate = row.loss_rate_points_per_vori !== undefined
                                  ? parseFloat(row.loss_rate_points_per_vori) : 1;
                return `
                <tr>
                    <td class="text-secondary small">
                        <div>#${row.id}</div>
                        <div class="d-md-none text-secondary" style="font-size:0.75rem;">${fmtDate(row.created_at)}</div>
                    </td>
                    <td>
                        <div class="fw-bold">${escHtml(row.customer_name)}</div>
                        <small class="text-secondary">${escHtml(row.customer_phone || '')}</small>
                    </td>
                    <td class="d-none d-md-table-cell"><span class="chip-due">${fmtTrad(row.total_pure_gold)}</span></td>
                    <td class="d-none d-md-table-cell"><span class="chip-total">${lossPoints(row.loss)} পয়েন্ট</span></td>
                    <td class="d-none d-md-table-cell"><span class="chip-total" style="background:var(--navy); color:#fff;">${fmtTrad(row.final_pure_gold)}</span></td>
                    <td class="d-md-none exchange-info-cell">
                        <div class="info-row"><span class="info-label">মোট পাকা</span><span class="info-value">${fmtTrad(row.total_pure_gold)}</span></div>
                        <div class="info-row"><span class="info-label">লস (${lossRate} প/ভ)</span><span class="info-value">${lossPoints(row.loss)} পয়েন্ট</span></div>
                        <div class="info-row"><span class="info-label">নেট পাকা</span><span class="info-value">${fmtTrad(row.final_pure_gold)}</span></div>
                    </td>
                    <td class="small d-none d-md-table-cell">${fmtDate(row.created_at)}</td>
                    <td class="small d-none d-md-table-cell">${escHtml(row.created_by_username || '—')}</td>
                    <td>
                        <div class="btn-actions">
                            <button class="btn btn-outline btn-view" title="দ্রুত দেখুন" data-id="${row.id}">
                                <i class="bi bi-eye"></i>
                            </button>
                            <a href="gold_exchange_edit_inventory.php?id=${row.id}" class="btn btn-outline" title="এডিট / বিস্তারিত">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            `;
            }).join('');
        }

        document.getElementById('paginationInfo').textContent =
            `মোট ${data.totalRows} টি সোনা এক্সচেঞ্জের মধ্যে ${data.data.length} টি দেখানো হচ্ছে`;
        renderPagination(data.page, data.totalPages);
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger py-4">নেটওয়ার্ক ত্রুটি।</td></tr>';
    }
}

function renderPagination(page, totalPages) {
    const el = document.getElementById('paginationControls');
    el.innerHTML = '';
    if (totalPages <= 1) return;

    const mkItem = (label, target, disabled, active) => {
        const li = document.createElement('li');
        li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
        const a = document.createElement('a');
        a.className = 'page-link';
        a.href = '#';
        a.textContent = label;
        if (!disabled && !active) {
            a.addEventListener('click', e => { e.preventDefault(); loadList(target); });
        }
        li.appendChild(a);
        return li;
    };

    el.appendChild(mkItem('«', page - 1, page <= 1, false));
    for (let p = 1; p <= totalPages; p++) {
        el.appendChild(mkItem(String(p), p, false, p === page));
    }
    el.appendChild(mkItem('»', page + 1, page >= totalPages, false));
}

document.getElementById('searchInput').addEventListener('input', function () {
    clearTimeout(searchTimer);
    const val = this.value;
    searchTimer = setTimeout(() => { currentSearch = val.trim(); loadList(1); }, 350);
});

document.getElementById('clearSearchBtn').addEventListener('click', function () {
    document.getElementById('searchInput').value = '';
    currentSearch = '';
    loadList(1);
});

document.getElementById('dateFrom').addEventListener('change', function () {
    currentFrom = this.value; loadList(1);
});
document.getElementById('dateTo').addEventListener('change', function () {
    currentTo = this.value; loadList(1);
});
document.getElementById('clearDatesBtn').addEventListener('click', () => {
    resetFilters();
});

// ----------------------------------------------------------------
// View modal (Loads server-rendered PHP snippet)
// ----------------------------------------------------------------
document.getElementById('tableBody').addEventListener('click', async function (e) {
    const btn = e.target.closest('.btn-view');
    if (!btn) return;
    await openView(btn.dataset.id);
});

async function openView(id) {
    document.getElementById('btnOpenEdit').href = 'gold_exchange_edit_inventory.php?id=' + id;
    document.getElementById('viewId').textContent = id;
    document.getElementById('viewBody').innerHTML = '<div class="text-center text-muted py-4">লোড হচ্ছে…</div>';

    const modal = new bootstrap.Modal(document.getElementById('viewModal'));
    modal.show();

    try {
        const res  = await fetch('gold_exchange_list.php?action=get&id=' + id, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const html = await res.text();
        document.getElementById('viewBody').innerHTML = html;
    } catch (err) {
        document.getElementById('viewBody').innerHTML = '<div class="text-danger p-3">নেটওয়ার্ক ত্রুটি।</div>';
    }
}

// Reset all filters back to current month (1st → today) and clear search
function resetFilters() {
    document.getElementById('searchInput').value = '';
    currentSearch = '';

    const localDateStr = d => {
        const y  = d.getFullYear();
        const m  = String(d.getMonth() + 1).padStart(2, '0');
        const dy = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${dy}`;
    };
    const today = new Date();
    const from  = new Date(today.getFullYear(), today.getMonth(), 1);

    document.getElementById('dateFrom').value = localDateStr(from);
    document.getElementById('dateTo').value   = localDateStr(today);
    currentFrom = localDateStr(from);
    currentTo   = localDateStr(today);

    loadList(1);
}

// Set default date range on page load
resetFilters();
</script>

</body>
</html>