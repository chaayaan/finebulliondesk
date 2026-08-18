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
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>খরচের হিসাব — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
:root {
    --gold-deep: #c9973a;
    --gold-mid: #dcb04a;
    --gold-light: #e9cd7d;
    --ivory: #fbf8f2;
    --bronze-text: #3a2f1a;
    --muted: #9a8f76;
    --hairline: #ecdfb8;

    --status-paid-bg: #1b5238;      /* Deep Emerald (Paid / Impure / Loss) */
    --status-paid-light: #eaf4ee;
    --status-due-bg: #93292c;       /* Deep Ruby (Due / Pure / Outflow) */
    --status-due-light: #fbeceb;
    --status-total-bg: #b88328;     /* Rich Gold (Totals / Net Output) */
    --status-total-light: #fdf6e2;

    /* ---- FineBullion Desk summary-card spec tokens ---- */
    --sc-bg: #F8F7F3;
    --sc-card: #FFFFFF;
    --sc-gold: #C9972B;
    --sc-gold-dark: #A97816;
    --sc-gold-light: #FBF5E7;
    --sc-text: #252525;
    --sc-text-2: #77736A;
    --sc-border: #ECE8DF;
    --sc-due-bg: #FDF0F0;
    --sc-due-text: #B33A3A;
    --sc-success: #246047;
}
body {
    background: var(--ivory);
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--bronze-text);
}

/* ---- page header bar ---- */
.exchange-header {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: nowrap !important;
    gap: 1rem !important;
    min-height: 60px !important;
    max-height: 80px !important;
    padding: 0.85rem 1.75rem !important;
    margin: 0 !important;
    width: 100% !important;
    border-radius: 0 0 20px 20px !important;
}
.exchange-header h4, .exchange-header h4 i { color: #ffffff !important; }
.exchange-header small { color: rgba(255,255,255,0.85) !important; }
.exchange-header .header-title-block { min-width: 0; }
.exchange-header .header-actions { display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; }

.page-inset { padding: 0 1.5rem; }

/* ---- pill buttons ---- */
.btn-fb-primary, .btn-gold {
    background: var(--gold-deep);
    border: 1.5px solid var(--gold-deep);
    color: #ffffff !important;
    font-weight: 700;
    border-radius: 999px;
}
.btn-fb-primary:hover, .btn-gold:hover { opacity: 0.92; background: var(--gold-deep); border-color: var(--gold-deep); color: #ffffff !important; }

.btn-fb-secondary {
    background: #ffffff;
    border: 1.5px solid var(--hairline);
    color: var(--muted);
    font-weight: 600;
    border-radius: 999px;
}
.btn-fb-secondary:hover { background: #fdf7ec; border-color: var(--hairline); color: var(--bronze-text); }

.btn-outline-danger {
    background: #ffffff;
    border: 1.5px solid var(--status-due-bg);
    color: var(--status-due-bg);
    font-weight: 600;
    border-radius: 999px;
}
.btn-outline-danger:hover { background: var(--status-due-bg); border-color: var(--status-due-bg); color: #ffffff; }

.btn-outline-secondary {
    background: #ffffff;
    border: 1.5px solid var(--hairline);
    color: var(--muted);
    font-weight: 600;
    border-radius: 999px;
}
.btn-outline-secondary:hover { background: #fdf7ec; border-color: var(--hairline); color: var(--bronze-text); }

.btn-outline-primary {
    background: #ffffff;
    border: 1.5px solid var(--gold-deep);
    color: var(--gold-deep);
    font-weight: 600;
    border-radius: 999px;
}
.btn-outline-primary:hover { background: var(--gold-deep); border-color: var(--gold-deep); color: #ffffff; }

/* ---- FineBullion Desk summary-card spec (§2–§5) ---- */
.section-block { margin-bottom: 10px; }

.sc-card {
    background: var(--sc-card);
    border: 1px solid var(--sc-border);
    border-radius: 14px;
    box-shadow: 0 2px 8px rgba(37, 37, 37, 0.03);
    overflow: hidden;
}

.sc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 13px 18px;
    border-bottom: 1px solid var(--sc-border);
}
.sc-header-left { display: flex; align-items: center; gap: 10px; }
.sc-icon {
    width: 32px; height: 32px; min-width: 32px;
    border-radius: 50%;
    background: var(--sc-gold-light);
    border: 1px solid var(--sc-border);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.9rem;
    color: var(--sc-gold);
}
.section-label {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--sc-gold-dark);
    margin: 0;
}
.sc-header-icon { color: var(--sc-gold); font-size: 1rem; opacity: 0.8; }

/* ---- stat bar / metric cells ---- */
.stat-bar { display: grid; grid-template-columns: repeat(2, 1fr); }

.stat-cell {
    padding: 14px 16px;
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 10px;
    border-right: 1px solid var(--sc-border);
    background: var(--sc-card);
}
.stat-cell:last-child { border-right: none; }

.stat-cell .s-icon {
    width: 32px; height: 32px; min-width: 32px;
    border-radius: 50%;
    background: rgba(201, 151, 43, 0.09);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.88rem;
    color: var(--sc-gold-dark);
}
.stat-cell .s-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.stat-cell .s-label {
    font-size: 10.5px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.03em;
    color: var(--sc-text-2);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.stat-cell .s-value {
    font-size: 16px; font-weight: 800; letter-spacing: 0.01em;
    color: var(--sc-text);
    white-space: nowrap;
}

/* Emphasis: headline output/amount metric */
.stat-cell.stat-emphasis { background: var(--sc-gold-light); }
.stat-cell.stat-emphasis .s-value { color: var(--sc-gold-dark); }
.stat-cell.stat-emphasis .s-icon { background: #ffffff; }

/* Due / outstanding metric */
.stat-cell.stat-due { background: var(--sc-due-bg); }
.stat-cell.stat-due .s-value { color: var(--sc-due-text); }
.stat-cell.stat-due .s-icon { background: #ffffff; color: var(--sc-due-text); }

/* ---- chart card ---- */
.chart-card {
    background: #ffffff;
    border: none;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(180,140,50,0.12);
    padding: 1rem 1.25rem 0.5rem;
}
.chart-legend { display:flex; gap:1.25rem; flex-wrap:wrap; font-size:0.85rem; font-weight:600; margin-bottom:0.5rem; color: var(--bronze-text); }
.chart-legend .dot { width:11px; height:11px; border-radius:50%; display:inline-block; margin-right:6px; }
.chart-wrap { position: relative; height: 260px; width: 100%; }

/* ---- total strip ---- */
.total-strip {
    background: var(--status-paid-light);
    color: var(--status-paid-bg);
    font-weight: 600;
    font-size: 0.85rem;
    padding: 0.55rem 1rem;
    border-bottom: 1px solid var(--hairline);
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.35rem;
}

/* ---- filter bar ---- */
.filter-bar { background:#ffffff; border-bottom:1px solid var(--hairline); padding:0.75rem 1rem; display:flex; gap:0.6rem; flex-wrap:wrap; align-items:end; }
.filter-bar .filter-field { display:flex; flex-direction:column; gap:0.2rem; }
.filter-bar label { font-size:0.72rem; color: var(--muted); font-weight:600; margin-bottom:0; }
.filter-bar .form-select, .filter-bar .form-control { font-size:0.85rem; }

/* ---- expense list row (mobile card-style) ---- */
.expense-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.7rem 1rem;
    border-bottom: 1px solid var(--hairline);
}
.expense-row:last-child { border-bottom: none; }
.expense-row:hover { background: #fdf7ec; }
.expense-row .er-cat-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.expense-row .er-info { flex: 1; min-width: 0; }
.expense-row .er-cat { font-weight: 600; font-size: 0.9rem; color: var(--bronze-text); }
.expense-row .er-details { font-size: 0.78rem; color: var(--muted); }
.expense-row .er-date { font-size: 0.72rem; color: var(--muted); white-space: nowrap; }
.expense-row .er-amount { font-weight: 700; color: var(--status-total-bg); font-size: 0.92rem; white-space: nowrap; }
.er-actions { display: flex; gap: 4px; flex-shrink: 0; }
.er-actions .btn {
    width: 32px; height: 32px; padding: 0; display: inline-flex;
    align-items: center; justify-content: center; border-radius: 999px; font-size: 0.85rem;
}

/* ---- desktop table ---- */
.table { --bs-table-hover-bg: #fdf7ec; }
.table thead.table-light th, .table thead th {
    background: var(--ivory) !important;
    color: var(--muted);
    text-transform: uppercase;
    font-size: 0.72rem;
    letter-spacing: 0.04em;
    border-color: var(--hairline) !important;
    font-weight: 700;
}
.table td { border-color: var(--hairline) !important; vertical-align: middle; }
.table tbody tr:hover { background: #fdf7ec; }
.cat-badge { display:inline-flex; align-items:center; gap:6px; font-weight:600; font-size:0.82rem; color: var(--bronze-text); }
.cat-badge .dot { width:9px; height:9px; border-radius:50%; }
.amount-cell { font-weight: 700; color: var(--status-total-bg); }

/* ---- cards ---- */
.card { background:#ffffff; border:none; border-radius:18px; box-shadow:0 10px 30px rgba(180,140,50,0.12); }
.card-header { background: var(--ivory) !important; border-bottom:1px solid var(--hairline); border-radius:18px 18px 0 0 !important; color: var(--bronze-text); }
.card-footer { background:#ffffff !important; border-top:1px solid var(--hairline); border-radius: 0 0 18px 18px !important; }

/* ---- inputs ---- */
.form-control, .form-select, .input-group-text {
    border: 1.5px solid var(--hairline);
    border-radius: 10px;
    color: var(--bronze-text);
    background: #ffffff;
}
.form-control:focus, .form-select:focus { border-color: var(--gold-deep); box-shadow: 0 0 0 0.15rem rgba(201,151,58,0.18); }

/* ---- modal form styling ---- */
.modal-content { border-radius: 18px; overflow: hidden; border: none; }
.modal-header.fb-modal-header {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%);
    color: #fff;
}
.modal-header.fb-modal-header .modal-title { color: #ffffff; font-weight: 800; }
.modal-header.fb-modal-header .btn-close { filter: brightness(0) invert(1); }
.form-label .text-danger { font-weight: 700; }

/* ---- alerts ---- */
.alert-danger { background: var(--status-due-light); color: var(--status-due-bg); border: none; border-radius: 12px; }
.alert-success { background: var(--status-paid-light); color: var(--status-paid-bg); border: none; border-radius: 12px; }

.field-row { display: flex; align-items: center; gap: 0.75rem; }
.field-row .field-label { flex: 0 0 130px; max-width: 130px; margin-bottom: 0; padding-right: 0.25rem; color: var(--bronze-text); }
.field-row .field-input { flex: 1 1 auto; min-width: 0; }
@media (max-width: 575.98px) {
    .field-row { align-items: flex-start; }
    .field-row .field-label { flex-basis: 92px; max-width: 92px; font-size: 0.85rem; padding-top: 0.4rem; }
}

/* ---- FineBullion Desk summary-card spec: tablet 768–992px (§5) ---- */
@media (min-width: 768px) and (max-width: 991.98px) {
    .stat-bar { grid-template-columns: repeat(2, 1fr); }
    .stat-cell { border-right: 1px solid var(--sc-border); border-bottom: 1px solid var(--sc-border); }
    .stat-bar .stat-cell:nth-child(2n) { border-right: none; }
    .stat-bar .stat-cell:nth-last-child(-n+2) { border-bottom: none; }
}

/* ---------------------------------------------------------------
   Mobile compaction
--------------------------------------------------------------- */
@media (max-width: 767.98px) {
    .page-inset { padding: 0 0.8rem 1rem; }

    .exchange-header {
        min-height: 60px !important;
        max-height: 70px !important;
        padding: 0.75rem 1rem !important;
        border-radius: 0 0 16px 16px !important;
        justify-content: space-between !important;
    }
    .exchange-header h4 { font-size: 1rem; margin-bottom: 0; }
    .exchange-header small { display: none; }

    /* Chart mobile adjustment */
    .chart-card { padding: 0.75rem 0.6rem 0.4rem; border-radius: 14px; }
    .chart-legend { font-size: 0.68rem; gap: 0.6rem; }
    .chart-legend .dot { width: 8px; height: 8px; margin-right: 4px; }
    #chartYear { font-size: 0.78rem; padding: 0.25rem 0.5rem; }
    .chart-wrap { height: 220px; width: 100%; }

    /* ---- FineBullion Desk summary-card spec: mobile <768px (§5) ---- */
    .stat-bar { grid-template-columns: repeat(2, 1fr); }
    .stat-cell {
        padding: 10px 11px; gap: 8px;
        border-right: 1px solid var(--sc-border);
        border-bottom: 1px solid var(--sc-border);
    }
    .stat-bar .stat-cell:nth-child(2n) { border-right: none; }
    .stat-bar .stat-cell:nth-last-child(-n+2) { border-bottom: none; }
    .stat-cell .s-icon { width: 26px; height: 26px; min-width: 26px; font-size: 0.72rem; }
    .stat-cell .s-label { font-size: 9.5px; }
    .stat-cell .s-value { font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .sc-card { border-radius: 12px; }
    .sc-header { padding: 10px 12px; }
    .sc-icon { width: 26px; height: 26px; font-size: 0.78rem; }
    .section-label { font-size: 11.5px; letter-spacing: 0.03em; }
    .sc-header-icon { font-size: 0.88rem; }
    .section-block { margin-bottom: 8px; }

    .card { border-radius: 14px; }

    /* ---- Expenses header: title + search on one line ---- */
    .card-header { flex-wrap: nowrap !important; padding: 0.6rem 0.7rem; gap: 0.5rem !important; }
    .card-header .fw-semibold { font-size: 0.95rem; white-space: nowrap; flex-shrink: 0; }
    .card-header .input-group { max-width: none !important; flex: 1 1 auto; min-width: 0; }
    .card-header .input-group-text { padding: 0.3rem 0.5rem; }
    .card-header #searchInput { font-size: 0.8rem; padding: 0.3rem 0.5rem; min-width: 0; }
    .card-header #clearSearchBtn { padding: 0.3rem 0.55rem; }

    /* ---- Filter bar: Category on row 1, From/To/Reset on row 2 (matches ref image) ---- */
    .filter-bar { padding: 0.6rem 0.7rem; gap: 0.5rem 0.4rem; flex-wrap: wrap; }
    .filter-bar .filter-field { flex-direction: row; align-items: center; gap: 0.4rem; min-width: 0; }
    .filter-bar .filter-field label {
        display: block; font-size: 0.72rem; font-weight: 700; color: var(--bronze-text);
        white-space: nowrap; flex-shrink: 0;
    }
    .filter-bar .filter-field-reset label { display: none; }

    /* row 1: Category takes the full line */
    .filter-bar .filter-field-category { flex: 1 1 100%; order: 1; }
    .filter-bar select#filterCategory { flex: 1 1 auto; min-width: 0; }

    /* row 2: From, To, Reset share the line */
    .filter-bar .filter-field-from { flex: 1 1 0; min-width: 0; order: 2; }
    .filter-bar .filter-field-to { flex: 1 1 0; min-width: 0; order: 3; }
    .filter-bar .filter-field-reset { flex: 0 0 auto; order: 4; }

    .filter-bar .form-select, .filter-bar .form-control { font-size: 0.78rem; padding: 0.3rem 0.45rem; min-width: 0; }
    .filter-bar input#filterFrom, .filter-bar input#filterTo { flex: 1 1 auto; min-width: 0; font-size: 0.72rem; padding: 0.3rem 0.35rem; }
    .filter-bar #resetFilterBtn { flex: 0 0 auto; width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 0.85rem; }

    .total-strip { font-size: 0.78rem; padding: 0.45rem 0.85rem; }

    .expense-row { padding: 0.6rem 0.75rem; gap: 0.5rem; border-radius: 12px; }
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
<div class="container-fluid px-0">
    <div class="exchange-header">
        <div class="header-title-block">
            <h4 class="mb-0">
                <i class="bi bi-wallet2 me-2"></i>
                <span class="d-none d-md-inline">খরচের হিসাব</span>
                <span class="d-md-none">খরচ</span>
            </h4>
            <small>FineBullion Desk</small>
        </div>
        <div class="header-actions">
            <button type="button" class="btn btn-fb-primary btn-sm d-none d-md-inline-flex" data-bs-toggle="modal" data-bs-target="#expenseModal" id="btnAddExpense">
                <i class="bi bi-plus-lg me-1"></i> খরচ যোগ করুন
            </button>
            <button type="button" class="btn btn-fb-primary btn-sm d-md-none" data-bs-toggle="modal" data-bs-target="#expenseModal" id="btnAddExpenseMobile">
                <i class="bi bi-plus-lg me-1"></i><span>যোগ করুন</span>
            </button>
        </div>
    </div>
</div>

<div class="page-inset py-4">

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

    <!-- Summary card -->
    <div class="section-block">
        <div class="sc-card">
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
    </div>


    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-list-ul me-1"></i> খরচ তালিকা</span>
            <div class="input-group" style="max-width:300px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control" placeholder="বিস্তারিত খুঁজুন…">
                <button class="btn btn-outline-secondary" id="clearSearchBtn"><i class="bi bi-x-lg"></i></button>
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
                <button class="btn btn-sm btn-fb-secondary" id="resetFilterBtn" title="ফিল্টার রিসেট করুন">
                    <i class="bi bi-arrow-counterclockwise d-md-none"></i>
                    <i class="bi bi-arrow-counterclockwise me-1 d-none d-md-inline"></i><span class="d-none d-md-inline">রিসেট</span>
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
                <table class="table table-hover align-middle mb-0">
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
          <button type="button" class="btn btn-outline-danger me-auto d-none" id="deleteExpenseBtn"><i class="bi bi-trash-fill me-1"></i> ডিলিট</button>
          <button type="button" class="btn btn-fb-secondary" data-bs-dismiss="modal">বাতিল</button>
          <button type="submit" class="btn btn-fb-primary" id="saveExpenseBtn">
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
                borderRadius: 0,
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
                        },
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: { color: '#eef0f3', borderDash: [3, 3] },
                        ticks: {
                            font: { size: isMobile ? 8.5 : 12 },
                            maxTicksLimit: isMobile ? 5 : 8,
                            callback: v => isMobile
                                ? (v >= 1000 ? (v / 1000) + 'k' : v)
                                : v.toLocaleString(),
                        },
                    },
                },
            },
        });
    }

    // Re-render on breakpoint crossing so bar sizing stays correct after rotation/resize
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
                // Update table total strip
                document.getElementById('totalCount').textContent = res.totalRows;
                document.getElementById('totalSum').textContent   = fmtAmount(res.sumAmount);

                // Update summary cards
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
                    <button class="btn btn-sm btn-outline-primary btn-edit" data-id="${e.id}" title="এডিট"><i class="bi bi-pencil-fill"></i></button>
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
                        <button class="btn btn-outline-primary btn-edit" data-id="${e.id}" title="এডিট"><i class="bi bi-pencil-fill"></i></button>
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