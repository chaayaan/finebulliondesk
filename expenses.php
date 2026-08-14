<?php
/**
 * expenses.php
 * FineBullion Desk — Expense Tracking
 */

require_once __DIR__ . '/auth.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$action = $_GET['action'] ?? $_POST['action'] ?? null;

// -----------------------------------------------------------------------
// Helper
// -----------------------------------------------------------------------
function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------
// AJAX actions
// -----------------------------------------------------------------------
if ($isAjax || $action !== null) {

    // ---- CATEGORY LIST (for filter dropdown + form select) --------------
    if ($action === 'categories' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = mysqli_prepare($conn, "SELECT id, category FROM expense_categories ORDER BY category ASC");
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;
        json_out(['success' => true, 'data' => $rows]);
    }

    // ---- LIST -------------------------------------------------------------
    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $categoryId = (int)($_GET['category_id'] ?? 0);
        $from       = trim($_GET['from'] ?? '');
        $to         = trim($_GET['to'] ?? '');
        $page       = max(1, (int)($_GET['page'] ?? 1));
        $perPage    = 20;
        $offset     = ($page - 1) * $perPage;

        // Default to current month if no dates supplied
        if ($from === '' && $to === '') {
            $from = date('Y-m-01');
            $to   = date('Y-m-t');
        } elseif ($from === '') {
            $from = date('Y-m-01');
        } elseif ($to === '') {
            $to = date('Y-m-t');
        }

        $where  = ['e.date_of_expenses BETWEEN ? AND ?'];
        $types  = 'ss';
        $params = [$from, $to];

        if ($categoryId > 0) {
            $where[]  = 'e.expense_category_id = ?';
            $types   .= 'i';
            $params[] = $categoryId;
        }

        $whereSql = implode(' AND ', $where);

        // Count
        $cntSql  = "SELECT COUNT(*) FROM expenses e WHERE $whereSql";
        $cntStmt = mysqli_prepare($conn, $cntSql);
        mysqli_stmt_bind_param($cntStmt, $types, ...$params);
        mysqli_stmt_execute($cntStmt);
        mysqli_stmt_bind_result($cntStmt, $total);
        mysqli_stmt_fetch($cntStmt);
        mysqli_stmt_close($cntStmt);

        // Rows
        $sql = "SELECT e.id, e.details, e.amount, e.date_of_expenses,
                       c.id AS category_id, c.category
                FROM expenses e
                JOIN expense_categories c ON c.id = e.expense_category_id
                WHERE $whereSql
                ORDER BY e.date_of_expenses DESC, e.id DESC
                LIMIT ? OFFSET ?";
        $stmt = mysqli_prepare($conn, $sql);
        $allTypes  = $types . 'ii';
        $allParams = array_merge($params, [$perPage, $offset]);
        mysqli_stmt_bind_param($stmt, $allTypes, ...$allParams);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;

        // Sum for the filtered range (not just current page)
        $sumSql  = "SELECT COALESCE(SUM(amount),0) FROM expenses e WHERE $whereSql";
        $sumStmt = mysqli_prepare($conn, $sumSql);
        mysqli_stmt_bind_param($sumStmt, $types, ...$params);
        mysqli_stmt_execute($sumStmt);
        mysqli_stmt_bind_result($sumStmt, $sumTotal);
        mysqli_stmt_fetch($sumStmt);
        mysqli_stmt_close($sumStmt);

        json_out([
            'success'    => true,
            'data'       => $rows,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalRows'  => (int)$total,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
            'sumAmount'  => (float)$sumTotal,
            'from'       => $from,
            'to'         => $to,
        ]);
    }

    // ---- CHART (monthly totals per category, current year by default) ---
    if ($action === 'chart' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $year = (int)($_GET['year'] ?? date('Y'));

        $sql = "SELECT MONTH(e.date_of_expenses) AS m, c.category, SUM(e.amount) AS total
                FROM expenses e
                JOIN expense_categories c ON c.id = e.expense_category_id
                WHERE YEAR(e.date_of_expenses) = ?
                GROUP BY MONTH(e.date_of_expenses), c.category";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $year);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }

        json_out(['success' => true, 'data' => $data, 'year' => $year]);
    }

    // ---- GET single --------------------------------------------------------
    if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'Invalid ID.'], 400);

        $stmt = mysqli_prepare($conn,
            "SELECT e.id, e.details, e.amount, e.date_of_expenses, e.expense_category_id,
                    c.category, e.created_at, e.updated_at, u.username AS created_by_username
             FROM expenses e
             JOIN expense_categories c ON c.id = e.expense_category_id
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$row) json_out(['success' => false, 'message' => 'Expense not found.'], 404);
        json_out(['success' => true, 'data' => $row]);
    }

    // ---- SAVE (add or update) ----------------------------------------------
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id           = (int)($_POST['id'] ?? 0);
        $categoryId   = (int)($_POST['expense_category_id'] ?? 0);
        $details      = trim($_POST['details'] ?? '') ?: null;
        $amount       = (float)($_POST['amount'] ?? 0);
        $dateExpenses = trim($_POST['date_of_expenses'] ?? '');

        if ($categoryId <= 0) json_out(['success' => false, 'message' => 'Category is required.'], 422);
        if ($amount <= 0)     json_out(['success' => false, 'message' => 'Amount must be greater than 0.'], 422);
        if ($dateExpenses === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateExpenses)) {
            json_out(['success' => false, 'message' => 'A valid expense date is required.'], 422);
        }

        // Validate category exists
        $catCheck = mysqli_prepare($conn, "SELECT id FROM expense_categories WHERE id = ?");
        mysqli_stmt_bind_param($catCheck, 'i', $categoryId);
        mysqli_stmt_execute($catCheck);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($catCheck))) {
            json_out(['success' => false, 'message' => 'Invalid category.'], 422);
        }

        if ($id > 0) {
            $stmt = mysqli_prepare($conn,
                "UPDATE expenses SET expense_category_id=?, details=?, amount=?, date_of_expenses=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'isdsi', $categoryId, $details, $amount, $dateExpenses, $id);
            mysqli_stmt_execute($stmt);

            json_out(['success' => true, 'message' => 'Expense updated.', 'id' => $id]);
        } else {
            $userId = $currentUser['id'];
            $stmt   = mysqli_prepare($conn,
                "INSERT INTO expenses (expense_category_id, details, amount, date_of_expenses, created_by)
                 VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'isdsi', $categoryId, $details, $amount, $dateExpenses, $userId);
            mysqli_stmt_execute($stmt);
            $newId = (int)mysqli_insert_id($conn);

            json_out(['success' => true, 'message' => 'Expense added.', 'id' => $newId]);
        }
    }

    // ---- DELETE -------------------------------------------------------------
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'Invalid ID.'], 400);

        $stmt = mysqli_prepare($conn, "DELETE FROM expenses WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);

        json_out(['success' => true, 'message' => 'Expense deleted.']);
    }

    json_out(['success' => false, 'message' => 'Unknown action.'], 400);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Expense Tracking — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
:root {
    --fb-green: #0B412A;
    --fb-gold:  #DCAD41;
}
body { background: #f5f6fa; font-family: "Segoe UI", Arial, sans-serif; }

/* ---- page header bar ---- */
.list-header {
    background: linear-gradient(135deg, var(--fb-green) 0%, #0e5636 100%);
    color: #fff;
    border-radius: 10px;
    padding: 1.25rem 1.5rem;
    position: relative;
}
.list-header h4 { color: #fff; }
.list-header small { color: rgba(255,255,255,0.75); }

/* ---- gold accent button ---- */
.btn-gold { background: var(--fb-gold); border-color: var(--fb-gold); color: #1a1a1a; font-weight: 600; }
.btn-gold:hover { background: #c99a2f; border-color: #c99a2f; color: #1a1a1a; }

/* ---- chart card ---- */
.chart-card {
    background: #fff;
    border: 2px solid #7c4dff33;
    border-radius: 12px;
    padding: 1rem 1.25rem 0.5rem;
}
.chart-legend { display:flex; gap:1.25rem; flex-wrap:wrap; font-size:0.85rem; margin-bottom:0.5rem; }
.chart-legend .dot { width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:5px; }
.chart-wrap { position: relative; height: 260px; }

/* ---- total summary cards after graph ---- */
.summary-card {
    background: #fff;
    border-radius: 10px;
    border: 1px solid #eef0f3;
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 6px rgba(0,0,0,0.03);
}
.summary-card-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}

/* ---- total strip ---- */
.total-strip {
    background: #eaf5ee;
    color: var(--fb-green);
    font-weight: 600;
    font-size: 0.85rem;
    padding: 0.55rem 1rem;
    border-bottom: 1px solid #e1ece5;
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.35rem;
}

/* ---- filter bar ---- */
.filter-bar { background:#fff; border-bottom:1px solid #eef0f3; padding:0.75rem 1rem; display:flex; gap:0.6rem; flex-wrap:wrap; align-items:end; }
.filter-bar .filter-field { display:flex; flex-direction:column; gap:0.2rem; }
.filter-bar label { font-size:0.72rem; color:#6c757d; font-weight:600; margin-bottom:0; }
.filter-bar .form-select, .filter-bar .form-control { font-size:0.85rem; }

/* ---- expense list row (mobile card-style) ---- */
.expense-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.7rem 1rem;
    border-bottom: 1px solid #eef0f3;
}
.expense-row:last-child { border-bottom: none; }
.expense-row:hover { background: #f8faf9; }
.expense-row .er-cat-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.expense-row .er-info { flex: 1; min-width: 0; }
.expense-row .er-cat { font-weight: 600; font-size: 0.9rem; color: #1a1a1a; }
.expense-row .er-details { font-size: 0.78rem; color: #8a8f98; }
.expense-row .er-date { font-size: 0.72rem; color: #9aa0a6; white-space: nowrap; }
.expense-row .er-amount { font-weight: 700; color: #198754; font-size: 0.92rem; white-space: nowrap; }
.er-actions { display: flex; gap: 4px; flex-shrink: 0; }
.er-actions .btn {
    width: 32px; height: 32px; padding: 0; display: inline-flex;
    align-items: center; justify-content: center; border-radius: 7px; font-size: 0.85rem;
}

/* ---- desktop table ---- */
.cat-badge { display:inline-flex; align-items:center; gap:6px; font-weight:600; font-size:0.82rem; }
.cat-badge .dot { width:9px; height:9px; border-radius:50%; }
.amount-cell { font-weight: 700; color: #198754; }

/* ---- modal form styling ---- */
.modal-header.fb-modal-header { background: var(--fb-green); color: #fff; }
.modal-header.fb-modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
.form-label .text-danger { font-weight: 700; }
.form-control:focus, .form-select:focus { border-color: var(--fb-gold); box-shadow: 0 0 0 0.2rem rgba(220,173,65,0.18); }

.field-row { display: flex; align-items: center; gap: 0.75rem; }
.field-row .field-label { flex: 0 0 130px; max-width: 130px; margin-bottom: 0; padding-right: 0.25rem; }
.field-row .field-input { flex: 1 1 auto; min-width: 0; }
@media (max-width: 575.98px) {
    .field-row { align-items: flex-start; }
    .field-row .field-label { flex-basis: 92px; max-width: 92px; font-size: 0.85rem; padding-top: 0.4rem; }
}

/* ---------------------------------------------------------------
   Mobile compaction
--------------------------------------------------------------- */
@media (max-width: 767.98px) {
    .page-content .container-fluid { padding: 0.6rem 0.6rem 1rem; }

    .list-header { padding: 0.65rem 0.85rem; border-radius: 8px; justify-content: center !important; }
    .list-header h4 { font-size: 1rem; margin-bottom: 0; text-align: center; }
    .list-header h4 i { display: none; }
    .list-header small { display: none; }
    .list-header > button.btn {
        position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%);
        padding: 0.3rem 0.5rem; font-size: 0.75rem;
    }
    .list-header > button.btn span { display: none; }

    .chart-card { padding: 0.75rem 0.75rem 0.4rem; border-radius: 10px; }
    .chart-wrap { height: 220px; }
    .chart-legend { font-size: 0.75rem; gap: 0.75rem; }

    /* Summary cards mobile adjustment */
    .summary-card { padding: 0.65rem 0.75rem; }
    .summary-card span.text-muted { font-size: 0.68rem !important; }
    .summary-card h3 { font-size: 1.15rem !important; }
    .summary-card-icon { width: 34px; height: 34px; font-size: 1rem; }

    .card { border-radius: 10px; }
    .filter-bar { padding: 0.6rem 0.7rem; gap: 0.5rem; }
    .filter-bar .filter-field { flex: 1 1 45%; }

    .total-strip { font-size: 0.78rem; padding: 0.45rem 0.85rem; }

    .expense-row { padding: 0.6rem 0.75rem; gap: 0.5rem; }
    .expense-row .er-cat { font-size: 0.84rem; }
    .expense-row .er-details, .expense-row .er-date { font-size: 0.72rem; }
    .er-actions .btn { width: 28px; height: 28px; font-size: 0.75rem; }

    .card-footer { padding: 0.5rem 0.6rem; }
    .card-footer small { font-size: 0.7rem; }
    .pagination-sm .page-link { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid py-4">

    <div class="list-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4 class="mb-0">
                <i class="bi bi-wallet2 me-2"></i>
                <span class="d-none d-md-inline">Expense Tracking</span>
                <span class="d-md-none">Expenses</span>
            </h4>
            <small>FineBullion Desk</small>
        </div>
        <div class="d-none d-md-flex gap-2">
            <button type="button" class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#expenseModal" id="btnAddExpense">
                <i class="bi bi-plus-lg me-1"></i> Add Expenses
            </button>
        </div>
        <button type="button" class="btn btn-gold btn-sm d-md-none" data-bs-toggle="modal" data-bs-target="#expenseModal" id="btnAddExpenseMobile">
            <i class="bi bi-plus-lg me-1"></i><span>Add</span>
        </button>
    </div>

    <!-- CHART -->
    <div class="chart-card mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <div class="chart-legend" id="chartLegend"></div>
            <select id="chartYear" class="form-select form-select-sm" style="width:auto;"></select>
        </div>
        <div class="chart-wrap">
            <canvas id="expenseChart"></canvas>
        </div>
    </div>

    <!-- SUMMARY CARDS AFTER GRAPH (SIDE-BY-SIDE ON ALL SCREENS INCL. MOBILE) -->
    <div class="row g-2 g-md-3 mb-4">
        <div class="col-6">
            <div class="summary-card">
                <div>
                    <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Total Transaction</span>
                    <h3 class="mb-0 fw-bold text-dark" id="summaryTotalCount">0</h3>
                </div>
                <div class="summary-card-icon bg-light text-primary">
                    <i class="bi bi-receipt"></i>
                </div>
            </div>
        </div>
        <div class="col-6">
            <div class="summary-card">
                <div>
                    <span class="text-muted small fw-semibold text-uppercase d-block mb-1">Total Expenses</span>
                    <h3 class="mb-0 fw-bold text-success" id="summaryTotalSum">৳0</h3>
                </div>
                <div class="summary-card-icon bg-light text-success">
                    <i class="bi bi-wallet-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="filter-bar">
            <div class="filter-field">
                <label for="filterCategory">Category</label>
                <select id="filterCategory" class="form-select form-select-sm" style="min-width:150px;">
                    <option value="0">All</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="filterFrom">From</label>
                <input type="date" id="filterFrom" class="form-control form-control-sm">
            </div>
            <div class="filter-field">
                <label for="filterTo">To</label>
                <input type="date" id="filterTo" class="form-control form-control-sm">
            </div>
            <div class="filter-field">
                <label>&nbsp;</label>
                <button class="btn btn-sm btn-outline-secondary" id="resetFilterBtn"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</button>
            </div>
        </div>

        <div class="total-strip">
            <span>Total : <span id="totalCount">0</span> expenses</span>
            <span>Sum : <span id="totalSum">৳0</span></span>
        </div>

        <div class="card-body p-0">

            <!-- ============ MOBILE LIST (card rows) ============ -->
            <div id="expenseListMobile" class="d-md-none">
                <div class="text-center py-4 text-muted">
                    <span class="spinner-border spinner-border-sm me-2"></span>Loading…
                </div>
            </div>

            <!-- ============ DESKTOP TABLE ============ -->
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:120px;">Date</th>
                            <th style="width:150px;">Category</th>
                            <th>Details</th>
                            <th style="width:120px;">Amount</th>
                            <th style="width:100px;" class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="expensesTableBody">
                        <tr><td colspan="5" class="text-center py-4 text-muted">Loading…</td></tr>
                    </tbody>
                </table>
            </div>

        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted" id="paginationInfo">&nbsp;</small>
            <nav><ul class="pagination pagination-sm mb-0" id="paginationControls"></ul></nav>
        </div>
    </div>
</div>
</div>

<!-- ADD / EDIT MODAL -->
<div class="modal fade" id="expenseModal" tabindex="-1" aria-labelledby="expenseModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="expenseForm">
        <div class="modal-header fb-modal-header">
          <h5 class="modal-title" id="expenseModalLabel"><i class="bi bi-plus-circle-fill me-1"></i> Add Expense</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="formAlert" class="alert alert-danger d-none"></div>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="expenseId" value="0">
          <div class="row g-3">
            <div class="col-12 field-row">
              <label class="form-label field-label">Category <span class="text-danger">*</span></label>
              <div class="field-input">
                <select class="form-select" id="expense_category_id" name="expense_category_id" required>
                  <option value="">Select category…</option>
                </select>
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">Date <span class="text-danger">*</span></label>
              <div class="field-input">
                <input type="date" class="form-control" id="date_of_expenses" name="date_of_expenses" required>
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">Amount <span class="text-danger">*</span></label>
              <div class="field-input">
                <input type="number" step="0.01" min="0.01" class="form-control" id="amount" name="amount" required>
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">Details</label>
              <div class="field-input">
                <textarea class="form-control" id="details" name="details" rows="2"></textarea>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-danger me-auto d-none" id="deleteExpenseBtn"><i class="bi bi-trash-fill me-1"></i> Delete</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-gold" id="saveExpenseBtn">
            <span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span>
            <i class="bi bi-check-lg me-1"></i> Add Expense
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
  <div id="appToast" class="toast align-items-center text-white border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body" id="appToastBody">Message</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const state = {
        page: 1,
        categoryId: 0,
        from: '',
        to: '',
        categories: [],
    };
    const CAT_COLORS = ['#c0392b', '#2f4fce', '#2e8b47', '#8e44ad', '#d68910', '#16a085', '#c2185b'];

    const expenseModal = new bootstrap.Modal(document.getElementById('expenseModal'));
    const appToastEl    = document.getElementById('appToast');
    const appToast       = new bootstrap.Toast(appToastEl, { delay: 3000 });
    let chartInstance    = null;

    function esc(s) {
        return s == null ? '' : String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;');
    }

    function showToast(msg, isError) {
        appToastEl.classList.remove('bg-success','bg-danger');
        appToastEl.classList.add(isError ? 'bg-danger' : 'bg-success');
        document.getElementById('appToastBody').textContent = msg;
        appToast.show();
    }

    function fmtDate(s) {
        if (!s) return '-';
        const d = new Date(s.replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
    }

    function fmtAmount(n) {
        return '৳' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
    }

    function colorForCategory(name) {
        if (!state.categories.length) return CAT_COLORS[0];
        const idx = state.categories.findIndex(c => c.category === name);
        return CAT_COLORS[idx >= 0 ? idx % CAT_COLORS.length : 0];
    }

    function todayStr() {
        return new Date().toISOString().slice(0, 10);
    }
    function monthStartStr() {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-01`;
    }

    // ── Init default date range = current month ─────────────────────────
    state.from = monthStartStr();
    state.to   = todayStr();
    document.getElementById('filterFrom').value = state.from;
    document.getElementById('filterTo').value   = state.to;

    // ── Categories ────────────────────────────────────────────────────
    function loadCategories() {
        return fetch('expenses.php?action=categories', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;
                state.categories = res.data;
                const filterSel = document.getElementById('filterCategory');
                const formSel   = document.getElementById('expense_category_id');
                filterSel.innerHTML = '<option value="0">All</option>' +
                    res.data.map(c => `<option value="${c.id}">${esc(c.category)}</option>`).join('');
                formSel.innerHTML = '<option value="">Select category…</option>' +
                    res.data.map(c => `<option value="${c.id}">${esc(c.category)}</option>`).join('');
            });
    }

    // ── Chart ─────────────────────────────────────────────────────────
    function populateYearSelect() {
        const sel = document.getElementById('chartYear');
        const currentYear = new Date().getFullYear();
        let html = '';
        for (let y = currentYear; y >= currentYear - 4; y--) {
            html += `<option value="${y}" ${y === currentYear ? 'selected' : ''}>${y}</option>`;
        }
        sel.innerHTML = html;
    }

    function loadChart(year) {
        fetch('expenses.php?action=chart&year=' + year, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;
                renderChart(res.data);
            });
    }

    function renderChart(rows) {
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sept','Oct','Nov','Dec'];
        const cats = state.categories.length
            ? state.categories.map(c => c.category)
            : [...new Set(rows.map(r => r.category))];

        const datasets = cats.map((cat, i) => {
            const data = months.map((_, mIdx) => {
                const found = rows.find(r => Number(r.m) === mIdx + 1 && r.category === cat);
                return found ? Number(found.total) : 0;
            });
            return {
                label: cat,
                data,
                backgroundColor: CAT_COLORS[i % CAT_COLORS.length],
                borderRadius: 3,
                maxBarThickness: 14,
            };
        });

        const legend = document.getElementById('chartLegend');
        legend.innerHTML = cats.map((cat, i) =>
            `<span><span class="dot" style="background:${CAT_COLORS[i % CAT_COLORS.length]}"></span>${esc(cat)}</span>`
        ).join('');

        const ctx = document.getElementById('expenseChart').getContext('2d');
        if (chartInstance) chartInstance.destroy();
        chartInstance = new Chart(ctx, {
            type: 'bar',
            data: { labels: months, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } },
                },
            },
        });
    }

    document.getElementById('chartYear').addEventListener('change', function () {
        loadChart(this.value);
    });

    // ── Load list ──────────────────────────────────────────────────────
    function loadExpenses(page) {
        state.page = page || 1;

        const mobileList = document.getElementById('expenseListMobile');
        const tbody = document.getElementById('expensesTableBody');
        mobileList.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">' +
            '<span class="spinner-border spinner-border-sm me-2"></span>Loading…</td></tr>';

        const params = new URLSearchParams({
            action: 'list',
            page: state.page,
            category_id: state.categoryId,
            from: state.from,
            to: state.to,
        });
        fetch('expenses.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">Failed to load.</td></tr>';
                    mobileList.innerHTML = '<div class="text-center py-4 text-danger">Failed to load.</div>';
                    return;
                }
                // Update table total strip
                document.getElementById('totalCount').textContent = res.totalRows;
                document.getElementById('totalSum').textContent   = fmtAmount(res.sumAmount);

                // Update summary cards after the graph
                const summaryCount = document.getElementById('summaryTotalCount');
                const summarySum   = document.getElementById('summaryTotalSum');
                if (summaryCount) summaryCount.textContent = res.totalRows;
                if (summarySum)   summarySum.textContent   = fmtAmount(res.sumAmount);

                // keep filter inputs synced with server-resolved defaults
                document.getElementById('filterFrom').value = res.from;
                document.getElementById('filterTo').value   = res.to;
                state.from = res.from;
                state.to   = res.to;

                renderTable(res.data);
                renderMobileList(res.data);
                renderPagination(res.page, res.totalPages, res.totalRows, res.data.length);
            })
            .catch(() => {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">Network error.</td></tr>';
                mobileList.innerHTML = '<div class="text-center py-4 text-danger">Network error.</div>';
            });
    }

    // ── Desktop table ─────────────────────────────────────────────────
    function renderTable(rows) {
        const tbody = document.getElementById('expensesTableBody');
        if (!rows || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No expenses found.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(e => `
            <tr>
                <td><small>${esc(fmtDate(e.date_of_expenses))}</small></td>
                <td>
                    <span class="cat-badge">
                        <span class="dot" style="background:${colorForCategory(e.category)}"></span>
                        ${esc(e.category)}
                    </span>
                </td>
                <td class="text-muted">${esc(e.details || '-')}</td>
                <td class="amount-cell">${fmtAmount(e.amount)}</td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-primary btn-edit" data-id="${e.id}" title="Edit"><i class="bi bi-pencil-fill"></i></button>
                </td>
            </tr>`).join('');
    }

    // ── Mobile card-row list ─────────────────────────────────────────
    function renderMobileList(rows) {
        const wrap = document.getElementById('expenseListMobile');
        if (!rows || !rows.length) {
            wrap.innerHTML = '<div class="text-center py-4 text-muted">No expenses found.</div>';
            return;
        }
        wrap.innerHTML = rows.map(e => `
            <div class="expense-row">
                <span class="er-cat-dot" style="background:${colorForCategory(e.category)}"></span>
                <div class="er-info">
                    <div class="er-cat">${esc(e.category)}</div>
                    ${e.details ? `<div class="er-details">${esc(e.details)}</div>` : ''}
                    <div class="er-date">${esc(fmtDate(e.date_of_expenses))}</div>
                </div>
                <div class="text-end">
                    <div class="er-amount mb-1">${fmtAmount(e.amount)}</div>
                    <div class="er-actions">
                        <button class="btn btn-outline-primary btn-edit" data-id="${e.id}" title="Edit"><i class="bi bi-pencil-fill"></i></button>
                    </div>
                </div>
            </div>`).join('');
    }

    function renderPagination(page, totalPages, totalRows, onPage) {
        const info  = document.getElementById('paginationInfo');
        const ctrl  = document.getElementById('paginationControls');
        const start = totalRows === 0 ? 0 : (page - 1) * 20 + 1;
        const end   = (page - 1) * 20 + onPage;
        info.textContent = `Showing ${start}–${end} of ${totalRows} expenses`;
        ctrl.innerHTML = '';
        if (totalPages <= 1) return;

        const mk = (label, target, disabled, active) => {
            const li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            const a = document.createElement('a');
            a.className = 'page-link'; a.href = '#'; a.textContent = label;
            if (!disabled && !active) a.addEventListener('click', e => { e.preventDefault(); loadExpenses(target); });
            li.appendChild(a); return li;
        };

        ctrl.appendChild(mk('«', page - 1, page <= 1, false));
        for (let p = Math.max(1, page - 2); p <= Math.min(totalPages, page + 2); p++) ctrl.appendChild(mk(p, p, false, p === page));
        ctrl.appendChild(mk('»', page + 1, page >= totalPages, false));
    }

    // ── Filters ───────────────────────────────────────────────────────
    document.getElementById('filterCategory').addEventListener('change', function () {
        state.categoryId = parseInt(this.value, 10) || 0;
        loadExpenses(1);
    });
    document.getElementById('filterFrom').addEventListener('change', function () {
        state.from = this.value;
        loadExpenses(1);
    });
    document.getElementById('filterTo').addEventListener('change', function () {
        state.to = this.value;
        loadExpenses(1);
    });
    document.getElementById('resetFilterBtn').addEventListener('click', () => {
        state.categoryId = 0;
        state.from = monthStartStr();
        state.to   = todayStr();
        document.getElementById('filterCategory').value = '0';
        document.getElementById('filterFrom').value = state.from;
        document.getElementById('filterTo').value   = state.to;
        loadExpenses(1);
    });

    // ── Add/Edit form helpers ───────────────────────────────────────────
    function resetForm() {
        document.getElementById('expenseForm').reset();
        document.getElementById('expenseId').value = '0';
        document.getElementById('formAlert').classList.add('d-none');
        document.getElementById('date_of_expenses').value = todayStr();
        document.getElementById('deleteExpenseBtn').classList.add('d-none');
        document.getElementById('expenseModalLabel').innerHTML = '<i class="bi bi-plus-circle-fill me-1"></i> Add Expense';
        document.getElementById('saveExpenseBtn').innerHTML =
            '<span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span><i class="bi bi-check-lg me-1"></i> Add Expense';
    }

    document.getElementById('btnAddExpense').addEventListener('click', resetForm);
    document.getElementById('btnAddExpenseMobile').addEventListener('click', resetForm);

    function openEdit(id) {
        fetch('expenses.php?action=get&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { showToast(res.message || 'Failed to load.', true); return; }
                resetForm();
                const e = res.data;
                document.getElementById('expenseModalLabel').innerHTML = '<i class="bi bi-pencil-fill me-1"></i> Edit Expense';
                document.getElementById('saveExpenseBtn').innerHTML =
                    '<span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span><i class="bi bi-check-lg me-1"></i> Save Expense';
                document.getElementById('expenseId').value              = e.id;
                document.getElementById('expense_category_id').value    = e.expense_category_id;
                document.getElementById('date_of_expenses').value       = e.date_of_expenses;
                document.getElementById('amount').value                 = e.amount;
                document.getElementById('details').value                = e.details || '';
                document.getElementById('deleteExpenseBtn').classList.remove('d-none');
                document.getElementById('deleteExpenseBtn').dataset.id = e.id;
                expenseModal.show();
            })
            .catch(() => showToast('Network error.', true));
    }

    function handleListClick(e) {
        const eBtn = e.target.closest('.btn-edit');
        if (eBtn) openEdit(eBtn.dataset.id);
    }
    document.getElementById('expensesTableBody').addEventListener('click', handleListClick);
    document.getElementById('expenseListMobile').addEventListener('click', handleListClick);

    // ── Delete ────────────────────────────────────────────────────────
    document.getElementById('deleteExpenseBtn').addEventListener('click', function () {
        const id = this.dataset.id;
        if (!id) return;
        if (!confirm('Delete this expense? This cannot be undone.')) return;

        fetch('expenses.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'delete', id }),
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                expenseModal.hide();
                showToast(res.message || 'Deleted.', false);
                loadExpenses(state.page);
                loadChart(document.getElementById('chartYear').value);
            } else {
                showToast(res.message || 'Failed to delete.', true);
            }
        })
        .catch(() => showToast('Network error.', true));
    });

    // ── Save submit ────────────────────────────────────────────────────
    document.getElementById('expenseForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('formAlert');
        const saveBtn  = document.getElementById('saveExpenseBtn');

        alertBox.classList.add('d-none');
        saveBtn.disabled = true;
        const spinner = document.getElementById('saveSpinner');
        if (spinner) spinner.classList.remove('d-none');

        fetch('expenses.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(this),
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                expenseModal.hide();
                showToast(res.message || 'Saved.', false);
                loadExpenses(state.page);
                loadChart(document.getElementById('chartYear').value);
            } else {
                alertBox.textContent = res.message || 'Failed to save.';
                alertBox.classList.remove('d-none');
            }
        })
        .catch(() => {
            alertBox.textContent = 'Network error. Please try again.';
            alertBox.classList.remove('d-none');
        })
        .finally(() => {
            saveBtn.disabled = false;
            const sp = document.getElementById('saveSpinner');
            if (sp) sp.classList.add('d-none');
        });
    });

    // ── Init ──────────────────────────────────────────────────────────
    populateYearSelect();
    loadCategories().then(() => {
        loadExpenses(1);
        loadChart(document.getElementById('chartYear').value);
    });
})();
</script>
</body>
</html>