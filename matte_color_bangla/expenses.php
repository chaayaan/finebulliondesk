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
        $search     = trim($_GET['search'] ?? '');
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
        if ($search !== '') {
            $where[]  = '(e.details LIKE ? OR c.category LIKE ?)';
            $like     = '%' . $search . '%';
            $types   .= 'ss';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);

        // Count
        $cntSql  = "SELECT COUNT(*) FROM expenses e
                    JOIN expense_categories c ON c.id = e.expense_category_id
                    WHERE $whereSql";
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
        $sumSql  = "SELECT COALESCE(SUM(amount),0) FROM expenses e
                    JOIN expense_categories c ON c.id = e.expense_category_id
                    WHERE $whereSql";
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
        if ($id <= 0) json_out(['success' => false, 'message' => 'ভুল আইডি।'], 400);

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

        if (!$row) json_out(['success' => false, 'message' => 'খরচ পাওয়া যায়নি।'], 404);
        json_out(['success' => true, 'data' => $row]);
    }

    // ---- SAVE (add or update) ----------------------------------------------
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id           = (int)($_POST['id'] ?? 0);
        $categoryId   = (int)($_POST['expense_category_id'] ?? 0);
        $details      = trim($_POST['details'] ?? '') ?: null;
        $amount       = (float)($_POST['amount'] ?? 0);
        $dateExpenses = trim($_POST['date_of_expenses'] ?? '');

        if ($categoryId <= 0) json_out(['success' => false, 'message' => 'ক্যাটাগরি আবশ্যক।'], 422);
        if ($amount <= 0)     json_out(['success' => false, 'message' => 'পরিমাণ অবশ্যই ০ এর বেশি হতে হবে।'], 422);
        if ($dateExpenses === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateExpenses)) {
            json_out(['success' => false, 'message' => 'সঠিক খরচের তারিখ আবশ্যক।'], 422);
        }

        // Validate category exists
        $catCheck = mysqli_prepare($conn, "SELECT id FROM expense_categories WHERE id = ?");
        mysqli_stmt_bind_param($catCheck, 'i', $categoryId);
        mysqli_stmt_execute($catCheck);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($catCheck))) {
            json_out(['success' => false, 'message' => 'ভুল ক্যাটাগরি।'], 422);
        }

        if ($id > 0) {
            $stmt = mysqli_prepare($conn,
                "UPDATE expenses SET expense_category_id=?, details=?, amount=?, date_of_expenses=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'isdsi', $categoryId, $details, $amount, $dateExpenses, $id);
            mysqli_stmt_execute($stmt);

            json_out(['success' => true, 'message' => 'খরচ আপডেট হয়েছে।', 'id' => $id]);
        } else {
            $userId = $currentUser['id'];
            $stmt   = mysqli_prepare($conn,
                "INSERT INTO expenses (expense_category_id, details, amount, date_of_expenses, created_by)
                 VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'isdsi', $categoryId, $details, $amount, $dateExpenses, $userId);
            mysqli_stmt_execute($stmt);
            $newId = (int)mysqli_insert_id($conn);

            json_out(['success' => true, 'message' => 'খরচ যোগ হয়েছে।', 'id' => $newId]);
        }
    }

    // ---- DELETE -------------------------------------------------------------
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'ভুল আইডি।'], 400);

        $stmt = mysqli_prepare($conn, "DELETE FROM expenses WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);

        json_out(['success' => true, 'message' => 'খরচ ডিলিট হয়েছে।']);
    }

    json_out(['success' => false, 'message' => 'অজানা অ্যাকশন।'], 400);
}
?>
<!DOCTYPE html>
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>খরচের হিসাব — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
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

/* ---- Page Header ---- */
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
    gap: 0.2rem;
    min-width: 0;
}
.page-header .header-right {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.page-header h1, .page-header h4 {
    color: var(--text-on-navy);
    margin: 0;
    font-size: 22px;
    font-weight: 700;
}
.page-header small, .page-header .subtitle {
    color: rgba(255, 255, 255, 0.78);
    font-size: 12.5px;
    font-weight: 500;
}

.header-action-btn {
    background: var(--navy);
    border: 1.5px solid #ffffff;
    color: #ffffff;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    padding: 0.45rem 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    white-space: nowrap;
}
.header-action-btn:hover, .header-action-btn:focus {
    background: var(--teal);
    border-color: #ffffff;
    color: #ffffff;
}
.header-action-btn i, .header-action-btn svg {
    color: #ffffff;
}

.page-inset {
    padding: 1.25rem 1.5rem;
}

/* ---- Buttons ---- */
.btn-primary {
    background: var(--navy);
    border: 1.5px solid var(--navy);
    color: #ffffff;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    padding: 0.55rem 1.1rem;
}
.btn-primary:hover, .btn-primary:focus {
    background: var(--teal);
    border-color: var(--teal);
    color: #ffffff;
}

.btn-secondary {
    background: #ffffff;
    border: 1.5px solid var(--border-default);
    color: var(--navy);
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    padding: 0.55rem 1.1rem;
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
    font-size: 14px;
}
.btn-outline:hover {
    background: var(--sky);
    color: var(--navy);
    border-color: var(--navy);
}

.btn-danger {
    background: #ffffff;
    border: 1.5px solid var(--danger);
    color: var(--danger);
    border-radius: 8px;
    font-weight: 600;
}
.btn-danger:hover {
    background: var(--danger);
    color: #ffffff;
}

.btn-icon-round {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--sky);
    color: var(--navy);
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* ---- Cards ---- */
.card, .sc-card {
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 14px;
    box-shadow: var(--shadow);
    padding: 0;
    overflow: hidden;
}

.sc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid var(--border-default);
}
.sc-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
}
.sc-icon {
    width: 34px;
    height: 34px;
    min-width: 34px;
    border-radius: 10px;
    background: var(--sky);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    color: var(--navy);
}
.section-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-secondary);
    margin: 0;
}
.sc-header-icon {
    color: var(--text-secondary);
    font-size: 1.1rem;
}

/* ---- Summary Stat Bar ---- */
.stat-bar {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
}
.stat-cell {
    padding: 1rem 1.1rem;
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 12px;
    border-right: 1px solid var(--border-default);
    background: var(--bg-card);
}
.stat-cell:last-child {
    border-right: none;
}
.stat-cell .s-icon {
    width: 34px;
    height: 34px;
    min-width: 34px;
    border-radius: 10px;
    background: var(--sky);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    color: var(--navy);
}
.stat-cell .s-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.stat-cell .s-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.stat-cell .s-value {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
}
.stat-cell.stat-emphasis {
    background: var(--beige);
}

/* ---- Chart Card ---- */
.chart-card {
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 14px;
    box-shadow: var(--shadow);
    padding: 1.1rem;
}
.chart-legend {
    display: flex;
    gap: 1.25rem;
    flex-wrap: wrap;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-secondary);
}
.chart-legend .dot {
    width: 10px;
    height: 10px;
    border-radius: 3px;
    display: inline-block;
    margin-right: 6px;
}
.chart-wrap {
    position: relative;
    height: 260px;
    width: 100%;
}

/* ---- Card Headers & Sections ---- */
.card-header-block {
    padding: 1rem 1.1rem;
    border-bottom: 1px solid var(--border-default);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.card-header-block .section-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* ---- Form Controls ---- */
.form-control, .form-select, textarea {
    background: #ffffff;
    border: 1.5px solid var(--border-default);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    padding: 0.55rem 0.75rem;
    transition: border-color .15s, box-shadow .15s;
}
.form-control::placeholder {
    color: var(--text-secondary);
    opacity: 0.7;
}
.form-control:focus, .form-select:focus, textarea:focus {
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

/* ---- Filter Bar ---- */
.filter-bar {
    background: var(--beige);
    border-bottom: 1px solid var(--border-default);
    padding: 0.85rem 1.1rem;
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    align-items: flex-end;
}
.filter-bar .filter-field {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

/* ---- Total Strip ---- */
.total-strip {
    background: var(--bg-hover);
    color: var(--text-primary);
    font-weight: 600;
    font-size: 13.5px;
    padding: 0.65rem 1.1rem;
    border-bottom: 1px solid var(--border-default);
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem;
}

/* ---- Mobile Item Rows ---- */
.expense-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border-default);
}
.expense-row:last-child {
    border-bottom: none;
}
.expense-row:hover {
    background: var(--bg-hover);
}
.expense-row .er-cat-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}
.expense-row .er-info {
    flex: 1;
    min-width: 0;
}
.expense-row .er-cat {
    font-weight: 600;
    font-size: 14px;
    color: var(--text-primary);
}
.expense-row .er-details {
    font-size: 12.5px;
    color: var(--text-secondary);
}
.expense-row .er-date {
    font-size: 12px;
    color: var(--text-secondary);
    white-space: nowrap;
}
.expense-row .er-amount {
    font-weight: 700;
    color: var(--navy);
    font-size: 14px;
    white-space: nowrap;
}
.er-actions {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
}

/* ---- Desktop Table ---- */
.table {
    margin-bottom: 0;
}
thead th {
    background: var(--beige) !important;
    color: var(--text-secondary) !important;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    border-bottom: 1.5px solid var(--border-default) !important;
    padding: 0.65rem 0.75rem;
}
tbody td {
    padding: 0.65rem 0.75rem;
    border-bottom: 1px solid var(--border-default) !important;
    font-size: 13.5px;
    color: var(--text-primary);
}
tbody tr:hover {
    background: var(--bg-hover) !important;
}
.cat-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    font-size: 13px;
    color: var(--text-primary);
}
.cat-badge .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
.amount-cell {
    font-weight: 700;
    color: var(--navy);
}

.card-footer {
    background: var(--bg-card) !important;
    border-top: 1px solid var(--border-default) !important;
    padding: 0.75rem 1.1rem;
}

/* ---- Modals ---- */
.modal-content {
    border-radius: 14px;
    border: 1px solid var(--border-default);
    box-shadow: var(--shadow);
    overflow: hidden;
}
.modal-header.fb-modal-header {
    background: var(--navy);
    color: var(--text-on-navy);
    padding: 1rem 1.25rem;
    border-bottom: none;
}
.modal-header.fb-modal-header .modal-title {
    color: var(--text-on-navy);
    font-weight: 700;
    font-size: 16px;
}
.modal-header.fb-modal-header .btn-close {
    filter: brightness(0) invert(1);
}
.modal-body {
    padding: 1.25rem;
}
.modal-footer {
    padding: 0.85rem 1.25rem;
    border-top: 1px solid var(--border-default);
    background: var(--beige);
}

/* ---- Alerts & Chips ---- */
.alert-danger {
    background: #FBECEC;
    color: var(--danger);
    border: 1px solid rgba(166, 67, 75, 0.2);
    border-radius: 8px;
    font-size: 13.5px;
}

.field-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.field-row .field-label {
    flex: 0 0 110px;
    max-width: 110px;
    margin-bottom: 0;
}
.field-row .field-input {
    flex: 1 1 auto;
    min-width: 0;
}

/* Pagination Adjustments */
.pagination .page-link {
    border-color: var(--border-default);
    color: var(--navy);
}
.pagination .page-item.active .page-link {
    background: var(--navy);
    border-color: var(--navy);
    color: #ffffff;
}

/* ---------------------------------------------------------------
   Mobile Breakpoints
--------------------------------------------------------------- */
@media (max-width: 576px) {
    .page-header {
        padding: 0.85rem 1.1rem;
        border-radius: 0 0 14px 14px;
    }
    .page-header h1, .page-header h4 {
        font-size: 18px;
    }
    .page-header small {
        display: none;
    }
    .page-inset {
        padding: 1rem;
    }
    .header-action-btn {
        padding: 0.4rem 0.75rem;
        font-size: 12.5px;
    }

    .form-control, .form-select, textarea {
        padding: 0.6rem 0.8rem;
        font-size: 16px;
    }

    .stat-bar {
        grid-template-columns: repeat(2, 1fr);
    }
    .stat-cell {
        padding: 0.75rem;
        gap: 8px;
    }
    .stat-cell .s-icon {
        width: 30px;
        height: 30px;
        min-width: 30px;
        font-size: 0.85rem;
    }
    .stat-cell .s-label {
        font-size: 10px;
    }
    .stat-cell .s-value {
        font-size: 13.5px;
    }

    .filter-bar {
        padding: 0.75rem;
        gap: 0.5rem;
    }
    .filter-bar .filter-field-category {
        flex: 1 1 100%;
    }
    .filter-bar .filter-field-from, .filter-bar .filter-field-to {
        flex: 1 1 calc(50% - 0.25rem);
    }
    .filter-bar .filter-field-reset {
        flex: 1 1 100%;
    }
    .filter-bar .filter-field-reset button {
        width: 100%;
    }

    .field-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
    }
    .field-row .field-label {
        flex: none;
        max-width: 100%;
    }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0">
    <div class="page-header">
        <div class="header-left">
            <h4>
                <i class="bi bi-wallet2 me-2"></i>
                <span class="d-none d-md-inline">খরচের হিসাব</span>
                <span class="d-md-none">খরচ</span>
            </h4>
            <small>FineBullion Desk</small>
        </div>
        <div class="header-right">
            <button type="button" class="header-action-btn d-none d-md-inline-flex" data-bs-toggle="modal" data-bs-target="#expenseModal" id="btnAddExpense">
                <i class="bi bi-plus-lg"></i> খরচ যোগ করুন
            </button>
            <button type="button" class="header-action-btn d-md-none" data-bs-toggle="modal" data-bs-target="#expenseModal" id="btnAddExpenseMobile">
                <i class="bi bi-plus-lg"></i><span>যোগ করুন</span>
            </button>
        </div>
    </div>
</div>

<div class="page-inset">

    <!-- CHART -->
    <div class="chart-card mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div class="chart-legend" id="chartLegend"></div>
            <select id="chartYear" class="form-select form-select-sm" style="width:auto;"></select>
        </div>
        <div class="chart-wrap">
            <canvas id="expenseChart"></canvas>
        </div>
    </div>

    <!-- Summary card -->
    <div class="sc-card mb-4">
        <div class="sc-header">
            <div class="sc-header-left">
                <div class="sc-icon"><i class="bi bi-wallet2"></i></div>
                <p class="section-label">খরচের সারাংশ</p>
            </div>
            <i class="bi bi-cash-coin sc-header-icon"></i>
        </div>
        <div class="stat-bar" id="statBar">
            <div class="stat-cell">
                <div class="s-icon"><i class="bi bi-receipt"></i></div>
                <div class="s-text">
                    <span class="s-label">মোট লেনদেন</span>
                    <span class="s-value" id="summaryTotalCount">0</span>
                </div>
            </div>
            <div class="stat-cell stat-emphasis">
                <div class="s-icon"><i class="bi bi-cash-stack"></i></div>
                <div class="s-text">
                    <span class="s-label">মোট খরচ</span>
                    <span class="s-value" id="summaryTotalSum">৳0</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header-block">
            <h2 class="section-title"><i class="bi bi-list-ul"></i> খরচ তালিকা</h2>
            <div class="input-group" style="max-width:300px;">
                <span class="input-group-text background-white border-end-0" style="background:#fff; border-color:var(--border-default);"><i class="bi bi-search text-secondary"></i></span>
                <input type="text" id="searchInput" class="form-control border-start-0 ps-0" placeholder="বিস্তারিত খুঁজুন…">
                <button class="btn btn-secondary border-start-0" type="button" id="clearSearchBtn"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>

        <div class="filter-bar">
            <div class="filter-field filter-field-category">
                <label for="filterCategory">ক্যাটাগরি</label>
                <select id="filterCategory" class="form-select form-select-sm" style="min-width:150px;">
                    <option value="0">সব</option>
                </select>
            </div>
            <div class="filter-field filter-field-from">
                <label for="filterFrom">শুরু</label>
                <input type="date" id="filterFrom" class="form-control form-control-sm">
            </div>
            <div class="filter-field filter-field-to">
                <label for="filterTo">শেষ</label>
                <input type="date" id="filterTo" class="form-control form-control-sm">
            </div>
            <div class="filter-field filter-field-reset">
                <label class="d-none d-md-block">&nbsp;</label>
                <button class="btn btn-sm btn-secondary" id="resetFilterBtn" title="ফিল্টার রিসেট করুন">
                    <i class="bi bi-arrow-counterclockwise me-1"></i><span>রিসেট</span>
                </button>
            </div>
        </div>

        <div class="total-strip">
            <span>মোট : <span id="totalCount">0</span> টি খরচ</span>
            <span>সমষ্টি : <span id="totalSum">৳0</span></span>
        </div>

        <div class="card-body p-0">

            <!-- ============ MOBILE LIST (card rows) ============ -->
            <div id="expenseListMobile" class="d-md-none">
                <div class="text-center py-4 text-muted">
                    <span class="spinner-border spinner-border-sm me-2"></span>লোড হচ্ছে…
                </div>
            </div>

            <!-- ============ DESKTOP TABLE ============ -->
            <div class="table-responsive d-none d-md-block">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th style="width:120px;">তারিখ</th>
                            <th style="width:150px;">ক্যাটাগরি</th>
                            <th>বিস্তারিত</th>
                            <th style="width:120px;">পরিমাণ</th>
                            <th style="width:100px;" class="text-center">অ্যাকশন</th>
                        </tr>
                    </thead>
                    <tbody id="expensesTableBody">
                        <tr><td colspan="5" class="text-center py-4 text-muted">লোড হচ্ছে…</td></tr>
                    </tbody>
                </table>
            </div>

        </div>
        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
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
          <h5 class="modal-title" id="expenseModalLabel"><i class="bi bi-plus-circle-fill me-1"></i> খরচ যোগ করুন</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="formAlert" class="alert alert-danger d-none"></div>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="expenseId" value="0">
          <div class="row g-3">
            <div class="col-12 field-row">
              <label class="form-label field-label">ক্যাটাগরি <span class="text-danger">*</span></label>
              <div class="field-input">
                <select class="form-select" id="expense_category_id" name="expense_category_id" required>
                  <option value="">ক্যাটাগরি নির্বাচন করুন…</option>
                </select>
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">তারিখ <span class="text-danger">*</span></label>
              <div class="field-input">
                <input type="date" class="form-control" id="date_of_expenses" name="date_of_expenses" required>
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">পরিমাণ <span class="text-danger">*</span></label>
              <div class="field-input">
                <input type="number" step="0.01" min="0.01" class="form-control" id="amount" name="amount" required>
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">বিস্তারিত</label>
              <div class="field-input">
                <textarea class="form-control" id="details" name="details" rows="2"></textarea>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-danger me-auto d-none" id="deleteExpenseBtn"><i class="bi bi-trash-fill me-1"></i> ডিলিট</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button>
          <button type="submit" class="btn btn-primary" id="saveExpenseBtn">
            <span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span>
            <i class="bi bi-check-lg me-1"></i> খরচ যোগ করুন
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
      <div class="toast-body" id="appToastBody">বার্তা</div>
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
        search: '',
        from: '',
        to: '',
        categories: [],
    };
    
    // Updated chart palette matching system theme
    const CAT_COLORS = ['#2F4156', '#567C8D', '#C8D9E6', '#3D7A5C', '#A6434B', '#8E44AD', '#D68910'];

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

    function localDateStr(d) {
        const y  = d.getFullYear();
        const m  = String(d.getMonth() + 1).padStart(2, '0');
        const dy = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${dy}`;
    }
    function todayStr() {
        return localDateStr(new Date());
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
                filterSel.innerHTML = '<option value="0">সব</option>' +
                    res.data.map(c => `<option value="${c.id}">${esc(c.category)}</option>`).join('');
                formSel.innerHTML = '<option value="">ক্যাটাগরি নির্বাচন করুন…</option>' +
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

        const isMobile = window.innerWidth < 768;

        const datasets = cats.map((cat, i) => {
            const data = months.map((_, mIdx) => {
                const found = rows.find(r => Number(r.m) === mIdx + 1 && r.category === cat);
                return found ? Number(found.total) : 0;
            });
            return {
                label: cat,
                data,
                backgroundColor: CAT_COLORS[i % CAT_COLORS.length],
                borderRadius: 4,
                maxBarThickness: isMobile ? 16 : 34,
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
                layout: { padding: { right: isMobile ? 4 : 0 } },
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        ticks: {
                            font: { size: isMobile ? 8.5 : 11 },
                            maxRotation: isMobile ? 60 : 0,
                            minRotation: isMobile ? 60 : 0,
                            autoSkip: false,
                            color: '#567C8D'
                        },
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: { color: '#C8D9E6', borderDash: [3, 3] },
                        ticks: {
                            font: { size: isMobile ? 8.5 : 12 },
                            maxTicksLimit: isMobile ? 5 : 8,
                            color: '#567C8D',
                            callback: v => isMobile
                                ? (v >= 1000 ? (v / 1000) + 'k' : v)
                                : v.toLocaleString(),
                        },
                    },
                },
            },
        });
    }

    let lastIsMobile = window.innerWidth < 768;
    window.addEventListener('resize', () => {
        const nowMobile = window.innerWidth < 768;
        if (nowMobile !== lastIsMobile) {
            lastIsMobile = nowMobile;
            loadChart(document.getElementById('chartYear').value);
        }
    });

    document.getElementById('chartYear').addEventListener('change', function () {
        loadChart(this.value);
    });

    // ── Load list ──────────────────────────────────────────────────────
    function loadExpenses(page) {
        state.page = page || 1;

        const mobileList = document.getElementById('expenseListMobile');
        const tbody = document.getElementById('expensesTableBody');
        mobileList.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>লোড হচ্ছে…</div>';
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">' +
            '<span class="spinner-border spinner-border-sm me-2"></span>লোড হচ্ছে…</td></tr>';

        const params = new URLSearchParams({
            action: 'list',
            page: state.page,
            category_id: state.categoryId,
            search: state.search,
            from: state.from,
            to: state.to,
        });
        fetch('expenses.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">লোড করতে ব্যর্থ হয়েছে।</td></tr>';
                    mobileList.innerHTML = '<div class="text-center py-4 text-danger">লোড করতে ব্যর্থ হয়েছে।</div>';
                    return;
                }
                document.getElementById('totalCount').textContent = res.totalRows;
                document.getElementById('totalSum').textContent   = fmtAmount(res.sumAmount);

                const summaryCount = document.getElementById('summaryTotalCount');
                const summarySum   = document.getElementById('summaryTotalSum');
                if (summaryCount) summaryCount.textContent = res.totalRows;
                if (summarySum)   summarySum.textContent   = fmtAmount(res.sumAmount);

                document.getElementById('filterFrom').value = res.from;
                document.getElementById('filterTo').value   = res.to;
                state.from = res.from;
                state.to   = res.to;

                renderTable(res.data);
                renderMobileList(res.data);
                renderPagination(res.page, res.totalPages, res.totalRows, res.data.length);
            })
            .catch(() => {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">নেটওয়ার্ক সমস্যা হয়েছে।</td></tr>';
                mobileList.innerHTML = '<div class="text-center py-4 text-danger">নেটওয়ার্ক সমস্যা হয়েছে।</div>';
            });
    }

    // ── Desktop table ─────────────────────────────────────────────────
    function renderTable(rows) {
        const tbody = document.getElementById('expensesTableBody');
        if (!rows || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">কোনো খরচ পাওয়া যায়নি।</td></tr>';
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
                    <button class="btn btn-sm btn-outline btn-edit" data-id="${e.id}" title="এডিট"><i class="bi bi-pencil-fill"></i></button>
                </td>
            </tr>`).join('');
    }

    // ── Mobile card-row list ─────────────────────────────────────────
    function renderMobileList(rows) {
        const wrap = document.getElementById('expenseListMobile');
        if (!rows || !rows.length) {
            wrap.innerHTML = '<div class="text-center py-4 text-muted">কোনো খরচ পাওয়া যায়নি।</div>';
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
                        <button class="btn btn-sm btn-outline btn-edit" data-id="${e.id}" title="এডিট"><i class="bi bi-pencil-fill"></i></button>
                    </div>
                </div>
            </div>`).join('');
    }

    function renderPagination(page, totalPages, totalRows, onPage) {
        const info  = document.getElementById('paginationInfo');
        const ctrl  = document.getElementById('paginationControls');
        const start = totalRows === 0 ? 0 : (page - 1) * 20 + 1;
        const end   = (page - 1) * 20 + onPage;
        info.textContent = `${totalRows} টি খরচের মধ্যে ${start}–${end} দেখানো হচ্ছে`;
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

    // ── Search ────────────────────────────────────────────────────────
    let searchTimer = null;
    document.getElementById('searchInput').addEventListener('input', function () {
        clearTimeout(searchTimer);
        const val = this.value;
        searchTimer = setTimeout(() => { state.search = val.trim(); loadExpenses(1); }, 350);
    });
    document.getElementById('clearSearchBtn').addEventListener('click', () => {
        document.getElementById('searchInput').value = '';
        state.search = '';
        loadExpenses(1);
    });

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
        state.search = '';
        state.from = monthStartStr();
        state.to   = todayStr();
        document.getElementById('filterCategory').value = '0';
        document.getElementById('searchInput').value = '';
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
        document.getElementById('expenseModalLabel').innerHTML = '<i class="bi bi-plus-circle-fill me-1"></i> খরচ যোগ করুন';
        document.getElementById('saveExpenseBtn').innerHTML =
            '<span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span><i class="bi bi-check-lg me-1"></i> খরচ যোগ করুন';
    }

    document.getElementById('btnAddExpense').addEventListener('click', resetForm);
    document.getElementById('btnAddExpenseMobile').addEventListener('click', resetForm);

    function openEdit(id) {
        fetch('expenses.php?action=get&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { showToast(res.message || 'লোড করতে ব্যর্থ হয়েছে।', true); return; }
                resetForm();
                const e = res.data;
                document.getElementById('expenseModalLabel').innerHTML = '<i class="bi bi-pencil-fill me-1"></i> খরচ এডিট করুন';
                document.getElementById('saveExpenseBtn').innerHTML =
                    '<span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span><i class="bi bi-check-lg me-1"></i> সংরক্ষণ করুন';
                document.getElementById('expenseId').value              = e.id;
                document.getElementById('expense_category_id').value    = e.expense_category_id;
                document.getElementById('date_of_expenses').value       = e.date_of_expenses;
                document.getElementById('amount').value                 = e.amount;
                document.getElementById('details').value                = e.details || '';
                document.getElementById('deleteExpenseBtn').classList.remove('d-none');
                document.getElementById('deleteExpenseBtn').dataset.id = e.id;
                expenseModal.show();
            })
            .catch(() => showToast('নেটওয়ার্ক সমস্যা হয়েছে।', true));
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
        if (!confirm('আপনি কি এই খরচটি ডিলিট করতে চান? এটি আর ফিরিয়ে আনা যাবে না।')) return;

        fetch('expenses.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'delete', id }),
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                expenseModal.hide();
                showToast(res.message || 'ডিলিট হয়েছে।', false);
                loadExpenses(state.page);
                loadChart(document.getElementById('chartYear').value);
            } else {
                showToast(res.message || 'ডিলিট করতে ব্যর্থ হয়েছে।', true);
            }
        })
        .catch(() => showToast('নেটওয়ার্ক সমস্যা হয়েছে।', true));
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
                showToast(res.message || 'সংরক্ষণ হয়েছে।', false);
                loadExpenses(state.page);
                loadChart(document.getElementById('chartYear').value);
            } else {
                alertBox.textContent = res.message || 'সংরক্ষণ করতে ব্যর্থ হয়েছে।';
                alertBox.classList.remove('d-none');
            }
        })
        .catch(() => {
            alertBox.textContent = 'নেটওয়ার্ক সমস্যা হয়েছে। আবার চেষ্টা করুন।';
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