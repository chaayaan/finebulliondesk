<?php
/**
 * customers.php
 * FineBullion Desk — Customer Management
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/upload_helper.php';

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

    // ---- LIST ----------------------------------------------------------
    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $search  = trim($_GET['search'] ?? '');
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        if ($search !== '') {
            $like = '%' . $search . '%';

            $cntStmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM customers WHERE name LIKE ? OR phone LIKE ? OR nid LIKE ?");
            mysqli_stmt_bind_param($cntStmt, 'sss', $like, $like, $like);
            mysqli_stmt_execute($cntStmt);
            mysqli_stmt_bind_result($cntStmt, $total);
            mysqli_stmt_fetch($cntStmt);
            mysqli_stmt_close($cntStmt);

            $stmt = mysqli_prepare($conn,
                "SELECT id, name, phone, address, email, nid, photo_path, created_at
                 FROM customers
                 WHERE name LIKE ? OR phone LIKE ? OR nid LIKE ?
                 ORDER BY created_at DESC
                 LIMIT ? OFFSET ?");
            mysqli_stmt_bind_param($stmt, 'sssii', $like, $like, $like, $perPage, $offset);
        } else {
            $cntStmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM customers");
            mysqli_stmt_execute($cntStmt);
            mysqli_stmt_bind_result($cntStmt, $total);
            mysqli_stmt_fetch($cntStmt);
            mysqli_stmt_close($cntStmt);

            $stmt = mysqli_prepare($conn,
                "SELECT id, name, phone, address, email, nid, photo_path, created_at
                 FROM customers
                 ORDER BY created_at DESC
                 LIMIT ? OFFSET ?");
            mysqli_stmt_bind_param($stmt, 'ii', $perPage, $offset);
        }

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $rows   = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }

        json_out([
            'success'    => true,
            'data'       => $rows,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalRows'  => (int)$total,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
        ]);
    }

    // ---- GET single ------------------------------------------------------
    if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'আইডি সঠিক নয়।'], 400);

        $stmt = mysqli_prepare($conn,
            "SELECT c.id, c.name, c.phone, c.address, c.email, c.nid, c.note, c.photo_path,
                    c.created_at, c.updated_at, u.username AS created_by_username
             FROM customers c
             LEFT JOIN users u ON u.id = c.created_by
             WHERE c.id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$row) json_out(['success' => false, 'message' => 'কাস্টমার পাওয়া যায়নি।'], 404);
        json_out(['success' => true, 'data' => $row]);
    }

    // ---- SAVE (add or update) --------------------------------------------
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '') ?: null;
        $email   = trim($_POST['email'] ?? '') ?: null;
        $nid     = trim($_POST['nid'] ?? '') ?: null;
        $note    = trim($_POST['note'] ?? '') ?: null;

        if ($name === '' || $phone === '') {
            json_out(['success' => false, 'message' => 'নাম এবং ফোন নম্বর আবশ্যক।'], 422);
        }

        $photoPath = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoPath = handle_customer_photo_upload($_FILES['photo']);
        }

        if ($id > 0) {
            // Fetch existing photo
            $ex = mysqli_prepare($conn, "SELECT photo_path FROM customers WHERE id = ?");
            mysqli_stmt_bind_param($ex, 'i', $id);
            mysqli_stmt_execute($ex);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($ex));
            if (!$existing) json_out(['success' => false, 'message' => 'কাস্টমার পাওয়া যায়নি।'], 404);

            $finalPhoto = $photoPath ?? $existing['photo_path'];

            $stmt = mysqli_prepare($conn,
                "UPDATE customers SET name=?, phone=?, address=?, email=?, nid=?, note=?, photo_path=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'sssssssi', $name, $phone, $address, $email, $nid, $note, $finalPhoto, $id);
            mysqli_stmt_execute($stmt);

            if ($photoPath && $existing['photo_path']) {
                delete_customer_photo($existing['photo_path']);
            }

            json_out(['success' => true, 'message' => 'কাস্টমার আপডেট হয়েছে।', 'id' => $id]);
        } else {
            $userId = $currentUser['id'];
            $stmt   = mysqli_prepare($conn,
                "INSERT INTO customers (name, phone, address, email, nid, note, photo_path, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'sssssssi', $name, $phone, $address, $email, $nid, $note, $photoPath, $userId);
            mysqli_stmt_execute($stmt);
            $newId = (int)mysqli_insert_id($conn);

            json_out(['success' => true, 'message' => 'কাস্টমার যোগ করা হয়েছে।', 'id' => $newId]);
        }
    }

    json_out(['success' => false, 'message' => 'অজানা অ্যাকশন।'], 400);
}
?>
<!DOCTYPE html>
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>কাস্টমার ম্যানেজমেন্ট — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Bengali:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ── Design Tokens ─────────────────────────────────────────────────── */
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
    --bg-hover: rgba(200, 217, 230, 0.35);
    --success: #3D7A5C;
    --danger: #A6434B;
    --shadow: 0 2px 8px rgba(47, 65, 86, 0.08);

    /* Legacy compatibility aliases for header/nav templates */
    --gold-deep: #2F4156;
    --gold-mid: #567C8D;
    --gold-light: #C8D9E6;
    --ivory: #F5EFEB;
    --bronze-text: #2F4156;
    --muted: #567C8D;
    --hairline: #C8D9E6;
    --fb-green: #2F4156;
    --fb-gold: #567C8D;
}

/* ── Base ──────────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }

body {
    background: var(--bg-app);
    font-family: 'Inter', 'Noto Sans Bengali', system-ui, -apple-system, sans-serif;
    color: var(--text-primary);
}

/* ── Page Header Bar ───────────────────────────────────────────────── */
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
.page-header .header-left { display: flex; flex-direction: column; gap: .2rem; min-width: 0; }
.page-header .header-right { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }

.page-header h1, .page-header h4 { color: var(--text-on-navy); margin: 0; font-weight: 700; font-size: 22px; }
.page-header small, .page-header .subtitle,
.page-header .header-meta { color: rgba(255, 255, 255, .78); font-size: 13px; font-weight: 500; }

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
}
.header-action-btn:hover, .header-action-btn:focus {
    background: var(--teal);
    border-color: #fff;
    color: #fff;
}
.header-action-btn svg, .header-action-btn i { color: #fff; }

/* ── Buttons ────────────────────────────────────────────────────────── */
.btn-primary, .btn-gold {
    background: var(--navy);
    border: 1.5px solid var(--navy);
    color: #fff;
    border-radius: 8px;
    font-weight: 600;
    padding: .55rem 1.1rem;
    font-size: 14px;
    box-shadow: none;
    transition: background 0.15s, border-color 0.15s;
}
.btn-primary:hover, .btn-primary:focus,
.btn-gold:hover, .btn-gold:focus, .btn-gold:active {
    background: var(--teal);
    border-color: var(--teal);
    color: #fff;
    box-shadow: none;
    transform: none;
}

.btn-secondary {
    background: #fff;
    border: 1.5px solid var(--border-default);
    color: var(--navy);
    border-radius: 8px;
    font-weight: 600;
    padding: .55rem 1.1rem;
    font-size: 14px;
}
.btn-secondary:hover {
    background: var(--bg-hover);
    border-color: var(--teal);
    color: var(--navy);
}

.btn-outline-secondary, .btn-outline-primary, .btn-outline-success {
    background: transparent;
    border: 1.5px solid var(--teal);
    color: var(--teal);
    border-radius: 8px;
    font-weight: 600;
}
.btn-outline-secondary:hover, .btn-outline-primary:hover, .btn-outline-success:hover {
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
}
.btn-danger:hover {
    background: var(--danger);
    color: #fff;
}

/* ── Main Cards ─────────────────────────────────────────────────────── */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 14px !important;
    box-shadow: var(--shadow);
    padding: 0;
}
.card-header {
    background: var(--bg-card) !important;
    border-bottom: 1px solid var(--border-default);
    border-radius: 14px 14px 0 0 !important;
    padding: 1rem 1.1rem;
}
.card-header .fw-semibold {
    color: var(--text-primary);
    font-weight: 700;
    font-size: 16px;
}
.card-footer {
    background: var(--bg-card) !important;
    border-top: 1px solid var(--border-default);
    border-radius: 0 0 14px 14px !important;
    padding: .85rem 1.1rem;
}

/* ── Search Bar ─────────────────────────────────────────────────────── */
.search-wrap .input-group-text {
    background: #fff;
    border: 1.5px solid var(--border-default);
    border-right: none;
    border-radius: 8px 0 0 8px;
    color: var(--text-secondary);
}
.search-wrap .form-control {
    border: 1.5px solid var(--border-default);
    border-left: none;
    border-right: none;
    border-radius: 0;
    font-size: 14px;
    color: var(--text-primary);
}
.search-wrap .form-control:focus {
    box-shadow: none;
    border-color: var(--teal);
}
.search-wrap .form-control::placeholder { color: var(--text-secondary); opacity: 0.7; }
.search-wrap .btn-outline-secondary {
    border: 1.5px solid var(--border-default);
    border-left: none;
    border-radius: 0 8px 8px 0;
    color: var(--text-secondary);
    background: #fff;
}
.search-wrap .btn-outline-secondary:hover { background: var(--bg-hover); color: var(--text-primary); }

/* ── Total Count Strip ──────────────────────────────────────────────── */
.total-strip {
    background: var(--bg-hover);
    border-bottom: 1px solid var(--border-default);
    color: var(--text-primary);
    font-weight: 700;
    font-size: 12.5px;
    padding: 0.5rem 1.1rem;
}

/* ── Table ──────────────────────────────────────────────────────────── */
.table thead th {
    background: var(--beige);
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    border-bottom: 1.5px solid var(--border-default);
    padding: .65rem .75rem;
}
.table tbody td {
    padding: .65rem .75rem;
    border-bottom: 1px solid var(--border-default);
    font-size: 13.5px;
    color: var(--text-primary);
    vertical-align: middle;
}
.table tbody tr:last-child td { border-bottom: none; }
.table-hover tbody tr:hover { background: var(--bg-hover); }

/* ── Customer Avatar ────────────────────────────────────────────────── */
.customer-avatar {
    width: 44px; height: 44px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--border-default);
    flex-shrink: 0;
}
.customer-photo-thumb {
    width: 40px; height: 40px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--border-default);
}
.view-photo {
    width: 130px; height: 130px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--border-default);
}
.photo-preview {
    width: 110px; height: 110px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--border-default);
}

/* ── Mobile Customer Row ─────────────────────────────────────────────── */
.customer-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1.1rem;
    border-bottom: 1px solid var(--border-default);
    text-decoration: none;
    color: inherit;
    transition: background 0.12s ease;
}
.customer-row:last-child { border-bottom: none; }
.customer-row:hover { background: var(--bg-hover); }

.customer-row .cr-info { flex: 1; min-width: 0; }
.customer-row .cr-name { font-weight: 700; font-size: 14px; color: var(--text-primary); margin-bottom: 1px; }
.customer-row .cr-phone { font-size: 12.5px; color: var(--text-secondary); }
.customer-row .cr-address { font-size: 12px; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.customer-row .cr-meta { text-align: right; flex-shrink: 0; }
.customer-row .cr-date { font-size: 12px; color: var(--text-secondary); white-space: nowrap; margin-bottom: 4px; }

.cr-actions { display: flex; gap: 4px; flex-shrink: 0; justify-content: flex-end; }
.cr-actions .btn {
    width: 32px; height: 32px; padding: 0;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 8px; font-size: 13px;
    border-color: var(--teal);
}

/* ── Action Buttons in Table ────────────────────────────────────────── */
.btn-actions { display: flex; gap: 4px; justify-content: center; }
.btn-actions .btn {
    border-radius: 8px;
    font-size: 13px;
    border-color: var(--teal);
}

/* ── Pagination ──────────────────────────────────────────────────────── */
.page-link {
    color: var(--navy);
    border-color: var(--border-default);
    border-radius: 8px !important;
    margin: 0 2px;
    font-size: 13px;
    font-weight: 600;
}
.page-item.active .page-link {
    background: var(--navy);
    border-color: var(--navy);
    color: #fff;
}
.page-link:hover {
    background: var(--sky);
    color: var(--navy);
    border-color: var(--border-default);
}
.page-link:focus { box-shadow: 0 0 0 3px rgba(86, 124, 141, 0.15); }

/* ── Modal ───────────────────────────────────────────────────────────── */
.modal-content {
    border: 1px solid var(--border-default);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: var(--shadow);
}
.modal-header.fb-modal-header {
    background: var(--navy);
    color: #fff;
    padding: 1rem 1.25rem;
    border-bottom: none;
}
.modal-header.fb-modal-header .modal-title { font-weight: 700; color: #fff; font-size: 16px; }
.modal-header.fb-modal-header .btn-close { filter: brightness(0) invert(1); opacity: 0.85; }
.modal-body { padding: 1.25rem; background: #fff; }
.modal-footer {
    padding: 0.85rem 1.25rem;
    background: var(--beige);
    border-top: 1px solid var(--border-default);
}

/* ── Form Controls ───────────────────────────────────────────────────── */
.form-control, .form-select, textarea {
    background: #fff;
    border: 1.5px solid var(--border-default);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 14px;
    padding: .55rem .75rem;
    transition: border-color .15s, box-shadow .15s;
}
.form-control::placeholder { color: var(--text-secondary); opacity: .7; }

.form-control:focus, .form-select:focus, textarea:focus {
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(86, 124, 141, .15);
    outline: none;
}
textarea.form-control { resize: vertical; }

label, .form-label {
    font-size: 12.5px; font-weight: 700; color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: .03em; margin-bottom: .3rem;
}

/* ── Field Row Layout ────────────────────────────────────────────────── */
.field-row { display: flex; align-items: center; gap: 0.75rem; }
.field-row .field-label {
    flex: 0 0 130px; max-width: 130px;
    margin-bottom: 0; padding-right: 0.25rem;
    font-size: 12.5px; font-weight: 700; color: var(--text-secondary);
}
.field-row .field-label .text-danger { color: var(--danger) !important; font-weight: 700; }
.field-row .field-input { flex: 1 1 auto; min-width: 0; }
.field-row .field-input .d-flex { flex-wrap: wrap; }

/* ── View Modal Table ─────────────────────────────────────────────────── */
.view-detail-table th {
    font-size: 12px; font-weight: 700; color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: 0.04em;
    padding: 0.55rem 0.75rem; width: 130px;
    border-bottom: 1px solid var(--border-default);
}
.view-detail-table td {
    font-size: 13.5px; color: var(--text-primary);
    padding: 0.55rem 0.75rem;
    border-bottom: 1px solid var(--border-default);
}
.view-detail-table tr:last-child th,
.view-detail-table tr:last-child td { border-bottom: none; }

/* ── Toast ───────────────────────────────────────────────────────────── */
.toast {
    border-radius: 8px !important;
    font-size: 13.5px;
    font-weight: 600;
}

/* ── Mobile ──────────────────────────────────────────────────────────── */
@media (max-width: 576px) {
    .page-content .container-fluid { padding: 0 0 1rem; }

    .page-header {
        padding: .85rem 1.1rem;
        border-radius: 0 0 14px 14px;
        margin-bottom: 1rem !important;
    }
    .page-header h4 { font-size: 18px; margin-bottom: 0; }
    .page-header small { display: none; }

    .card { border-radius: 14px !important; margin: 0 0.5rem; }
    .card-header { padding: .85rem; border-radius: 14px 14px 0 0 !important; }
    .card-header .fw-semibold { font-size: 15px; }
    .card-header .input-group { max-width: 100% !important; width: 100%; }

    .total-strip { font-size: 12px; padding: 0.4rem 0.85rem; }

    .customer-row { padding: 0.6rem 0.85rem; gap: 0.55rem; }
    .customer-avatar { width: 38px; height: 38px; }
    .customer-row .cr-name { font-size: 13.5px; }
    .customer-row .cr-phone, .customer-row .cr-address { font-size: 12px; }
    .cr-actions .btn { width: 32px; height: 32px; font-size: 12px; }

    .card-footer { padding: .75rem .85rem; border-radius: 0 0 14px 14px !important; }
    .card-footer small { font-size: 12px; }
    .pagination-sm .page-link { padding: 0.25rem 0.5rem; font-size: 12px; }

    .field-row { align-items: flex-start; }
    .field-row .field-label { flex-basis: 90px; max-width: 90px; font-size: 12px; padding-top: 0.45rem; }

    .modal-content { border-radius: 14px; }
    .modal-body { padding: 1rem; }
    .modal-footer { padding: 0.75rem 1rem; }

    .form-control, .form-select {
        font-size: 16px;
        padding: .6rem .8rem;
    }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0 pb-4">

    <!-- ── Page Header ── -->
    <div class="page-header mb-4">
        <div class="header-left">
            <h4 class="mb-0">
                <i class="bi bi-people-fill me-2"></i>
                <span class="d-none d-md-inline">কাস্টমার ম্যানেজমেন্ট</span>
                <span class="d-md-none">কাস্টমার</span>
            </h4>
            <small>FineBullion Desk</small>
        </div>
        <div class="header-right">
            <button type="button" class="header-action-btn" data-bs-toggle="modal" data-bs-target="#customerModal" id="btnAddCustomer">
                <i class="bi bi-plus-lg me-1"></i> কাস্টমার যোগ করুন
            </button>
            <button type="button" class="header-action-btn d-md-none d-none" data-bs-toggle="modal" data-bs-target="#customerModal" id="btnAddCustomerMobile">
                <i class="bi bi-plus-lg me-1"></i> যোগ করুন
            </button>
        </div>
    </div>

    <div class="px-md-3 px-2">

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-list-ul me-1"></i> কাস্টমার লিস্ট</span>
            <div class="input-group search-wrap" style="max-width:300px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control" placeholder="নাম বা ফোন নম্বর দিয়ে খুঁজুন">
                <button class="btn btn-outline-secondary" id="clearSearchBtn"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>

        <div class="total-strip">
            মোট : <span id="totalCount">0</span> জন কাস্টমার
        </div>

        <div class="card-body p-0">

            <!-- ============ MOBILE LIST (card rows) ============ -->
            <div id="customerListMobile" class="d-md-none">
                <div class="text-center py-4 text-muted">
                    <span class="spinner-border spinner-border-sm me-2"></span>লোড হচ্ছে…
                </div>
            </div>

            <!-- ============ DESKTOP TABLE ============ -->
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:55px;">ছবি</th>
                            <th>নাম</th>
                            <th>ফোন</th>
                            <th>ঠিকানা</th>
                            <th>ইমেইল</th>
                            <th>NID</th>
                            <th style="width:130px;">যোগ করা হয়েছে</th>
                            <th style="width:130px;" class="text-center">অ্যাকশন</th>
                        </tr>
                    </thead>
                    <tbody id="customersTableBody">
                        <tr><td colspan="8" class="text-center py-4 text-muted">লোড হচ্ছে…</td></tr>
                    </tbody>
                </table>
            </div>

        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted" id="paginationInfo">&nbsp;</small>
            <nav><ul class="pagination pagination-sm mb-0" id="paginationControls"></ul></nav>
        </div>
    </div>
    </div><!-- /px wrapper -->
</div><!-- /container-fluid -->
</div><!-- /page-content -->

<!-- ADD / EDIT MODAL -->
<div class="modal fade" id="customerModal" tabindex="-1" aria-labelledby="customerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="customerForm" enctype="multipart/form-data">
        <div class="modal-header fb-modal-header">
          <h5 class="modal-title" id="customerModalLabel"><i class="bi bi-person-plus-fill me-1"></i> কাস্টমার যোগ করুন</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="formAlert" class="alert d-none" style="background:#FBECEC;border:1px solid var(--danger);color:var(--danger);border-radius:8px;font-size:13px;"></div>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="customerId" value="0">
          <div class="row g-3">
            <div class="col-12 field-row">
              <label class="form-label field-label">নাম <span class="text-danger">*</span></label>
              <div class="field-input">
                <input type="text" class="form-control" id="name" name="name" maxlength="150">
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">ফোন <span class="text-danger">*</span></label>
              <div class="field-input">
                <input type="text" class="form-control" id="phone" name="phone" maxlength="20">
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">ঠিকানা <span class="text-danger">*</span></label>
              <div class="field-input">
                <input type="text" class="form-control" id="address" name="address" maxlength="255">
              </div>
            </div>
            <div class="col-md-6 field-row">
              <label class="form-label field-label">NID</label>
              <div class="field-input">
                <input type="text" class="form-control" id="nid" name="nid" maxlength="30">
              </div>
            </div>
            <div class="col-md-6 field-row">
              <label class="form-label field-label">ইমেইল</label>
              <div class="field-input">
                <input type="email" class="form-control" id="email" name="email" maxlength="150">
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">নোট</label>
              <div class="field-input">
                <textarea class="form-control" id="note" name="note" rows="2"></textarea>
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">ছবি</label>
              <div class="field-input d-flex align-items-center gap-3">
                <input type="file" class="form-control" id="photo" name="photo" accept="image/*">
                <img id="photoPreview" src="" alt="Preview" class="photo-preview d-none">
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button>
          <button type="submit" class="btn btn-primary" id="saveCustomerBtn">
            <span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span>
            <i class="bi bi-check-lg me-1"></i> কাস্টমার যোগ করুন
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- VIEW MODAL -->
<div class="modal fade" id="viewCustomerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header fb-modal-header">
        <h5 class="modal-title"><i class="bi bi-person-lines-fill me-1"></i> কাস্টমার বিস্তারিত</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-3 text-center">
            <img id="viewPhoto" src="" alt="Photo" class="view-photo mb-2">
          </div>
          <div class="col-md-9">
            <table class="table table-borderless table-sm mb-0 view-detail-table">
              <tbody>
                <tr><th>নাম</th><td id="viewName">-</td></tr>
                <tr><th>ফোন</th><td id="viewPhone">-</td></tr>
                <tr><th>ঠিকানা</th><td id="viewAddress">-</td></tr>
                <tr><th>ইমেইল</th><td id="viewEmail">-</td></tr>
                <tr><th>NID</th><td id="viewNid">-</td></tr>
                <tr><th>নোট</th><td id="viewNote">-</td></tr>
                <tr><th>যোগ করেছেন</th><td id="viewCreatedBy">-</td></tr>
                <tr><th>যোগ করার তারিখ</th><td id="viewCreatedAt">-</td></tr>
                <tr><th>সর্বশেষ আপডেট</th><td id="viewUpdatedAt">-</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <a href="#" class="btn btn-outline-secondary" id="historyFromViewBtn"><i class="bi bi-clock-history me-1"></i> হিস্ট্রি</a>
        <button type="button" class="btn btn-primary" id="editFromViewBtn"><i class="bi bi-pencil-fill me-1"></i> এডিট</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">বন্ধ করুন</button>
      </div>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
  <div id="appToast" class="toast align-items-center text-white border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body" id="appToastBody">মেসেজ</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const state = { page: 1, search: '', debounce: null };

    const customerModal = new bootstrap.Modal(document.getElementById('customerModal'));
    const viewModal     = new bootstrap.Modal(document.getElementById('viewCustomerModal'));
    const appToastEl    = document.getElementById('appToast');
    const appToast      = new bootstrap.Toast(appToastEl, { delay: 3000 });
    let viewedId        = null;

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

    function avatarSvg() {
        return 'data:image/svg+xml;utf8,' + encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">' +
            '<circle cx="12" cy="12" r="12" fill="%23C8D9E6"/>' +
            '<circle cx="12" cy="9" r="4" fill="%232F4156" fill-opacity="0.60"/>' +
            '<path d="M4 20c0-4 4-6 8-6s8 2 8 6" fill="%232F4156" fill-opacity="0.50"/></svg>'
        );
    }

    function photoUrl(p) { return p || avatarSvg(); }

    function fmtDate(s) {
        if (!s) return '-';
        const d = new Date(s.replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
    }

    // ── Load list ──────────────────────────────────────────────────────
    function loadCustomers(page) {
        state.page = page || 1;

        const mobileList = document.getElementById('customerListMobile');
        const tbody = document.getElementById('customersTableBody');
        mobileList.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>লোড হচ্ছে…</div>';
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">' +
            '<span class="spinner-border spinner-border-sm me-2"></span>লোড হচ্ছে…</td></tr>';

        const params = new URLSearchParams({ action: 'list', page: state.page, search: state.search });
        fetch('customers.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-danger">লোড করতে ব্যর্থ হয়েছে।</td></tr>';
                    mobileList.innerHTML = '<div class="text-center py-4 text-danger">লোড করতে ব্যর্থ হয়েছে।</div>';
                    return;
                }
                document.getElementById('totalCount').textContent = res.totalRows;
                renderTable(res.data);
                renderMobileList(res.data);
                renderPagination(res.page, res.totalPages, res.totalRows, res.data.length);
            })
            .catch(() => {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-danger">নেটওয়ার্ক এরর।</td></tr>';
                mobileList.innerHTML = '<div class="text-center py-4 text-danger">নেটওয়ার্ক এরর।</div>';
            });
    }

    // ── Desktop table ─────────────────────────────────────────────────
    function renderTable(rows) {
        const tbody = document.getElementById('customersTableBody');
        if (!rows || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">কোনো কাস্টমার পাওয়া যায়নি।</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(c => `
            <tr>
                <td><img src="${esc(photoUrl(c.photo_path))}" class="customer-photo-thumb" alt=""></td>
                <td>${esc(c.name)}</td>
                <td>${esc(c.phone)}</td>
                <td>${esc(c.address || '-')}</td>
                <td>${esc(c.email || '-')}</td>
                <td>${esc(c.nid || '-')}</td>
                <td><small>${esc(fmtDate(c.created_at))}</small></td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-secondary me-1 btn-view" data-id="${c.id}" title="দেখুন"><i class="bi bi-eye-fill"></i></button>
                    <button class="btn btn-sm btn-outline-primary me-1 btn-edit" data-id="${c.id}" title="এডিট"><i class="bi bi-pencil-fill"></i></button>
                    <a href="customer_history.php?customer_id=${c.id}" class="btn btn-sm btn-outline-secondary" title="হিস্ট্রি"><i class="bi bi-clock-history"></i></a>
                </td>
            </tr>`).join('');
    }

    // ── Mobile card-row list ───────────────────────────────────────────
    function renderMobileList(rows) {
        const wrap = document.getElementById('customerListMobile');
        if (!rows || !rows.length) {
            wrap.innerHTML = '<div class="text-center py-4 text-muted">কোনো কাস্টমার পাওয়া যায়নি।</div>';
            return;
        }
        wrap.innerHTML = rows.map(c => `
            <div class="customer-row">
                <img src="${esc(photoUrl(c.photo_path))}" class="customer-avatar" alt="">
                <div class="cr-info">
                    <div class="cr-name">${esc(c.name)}</div>
                    <div class="cr-phone">${esc(c.phone)}</div>
                    ${c.address ? `<div class="cr-address">${esc(c.address)}</div>` : ''}
                </div>
                <div class="cr-meta">
                    <div class="cr-date mb-1">${esc(fmtDate(c.created_at))}</div>
                    <div class="cr-actions">
                        <button class="btn btn-outline-secondary btn-view" data-id="${c.id}" title="দেখুন"><i class="bi bi-eye-fill"></i></button>
                        <button class="btn btn-outline-primary btn-edit" data-id="${c.id}" title="এডিট"><i class="bi bi-pencil-fill"></i></button>
                        <a href="customer_history.php?customer_id=${c.id}" class="btn btn-outline-secondary" title="হিস্ট্রি"><i class="bi bi-clock-history"></i></a>
                    </div>
                </div>
            </div>`).join('');
    }

    function renderPagination(page, totalPages, totalRows, onPage) {
        const info  = document.getElementById('paginationInfo');
        const ctrl  = document.getElementById('paginationControls');
        const start = totalRows === 0 ? 0 : (page - 1) * 20 + 1;
        const end   = (page - 1) * 20 + onPage;
        info.textContent = `${totalRows} জনের মধ্যে ${start}–${end} দেখানো হচ্ছে`;
        ctrl.innerHTML = '';
        if (totalPages <= 1) return;

        const mk = (label, target, disabled, active) => {
            const li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            const a = document.createElement('a');
            a.className = 'page-link'; a.href = '#'; a.textContent = label;
            if (!disabled && !active) a.addEventListener('click', e => { e.preventDefault(); loadCustomers(target); });
            li.appendChild(a); return li;
        };

        ctrl.appendChild(mk('«', page - 1, page <= 1, false));
        for (let p = Math.max(1, page - 2); p <= Math.min(totalPages, page + 2); p++) ctrl.appendChild(mk(p, p, false, p === page));
        ctrl.appendChild(mk('»', page + 1, page >= totalPages, false));
    }

    // ── Search ─────────────────────────────────────────────────────────
    document.getElementById('searchInput').addEventListener('input', function () {
        clearTimeout(state.debounce);
        const v = this.value;
        state.debounce = setTimeout(() => { state.search = v.trim(); loadCustomers(1); }, 350);
    });
    document.getElementById('clearSearchBtn').addEventListener('click', () => {
        document.getElementById('searchInput').value = '';
        state.search = '';
        loadCustomers(1);
    });

    // ── Add/Edit form helpers ───────────────────────────────────────────
    function resetForm() {
        document.getElementById('customerForm').reset();
        document.getElementById('customerId').value = '0';
        document.getElementById('formAlert').classList.add('d-none');
        document.getElementById('photoPreview').classList.add('d-none');
        document.getElementById('photoPreview').src = '';
        document.getElementById('customerModalLabel').innerHTML = '<i class="bi bi-person-plus-fill me-1"></i> কাস্টমার যোগ করুন';
        document.getElementById('saveCustomerBtn').innerHTML =
            '<span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span><i class="bi bi-check-lg me-1"></i> কাস্টমার যোগ করুন';
    }

    document.getElementById('btnAddCustomer').addEventListener('click', resetForm);
    document.getElementById('btnAddCustomerMobile').addEventListener('click', resetForm);

    document.getElementById('photo').addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) { document.getElementById('photoPreview').classList.add('d-none'); return; }
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('photoPreview').src = e.target.result;
            document.getElementById('photoPreview').classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    });

    function openEdit(id) {
        fetch('customers.php?action=get&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { showToast(res.message || 'লোড করতে ব্যর্থ হয়েছে।', true); return; }
                resetForm();
                const c = res.data;
                document.getElementById('customerModalLabel').innerHTML = '<i class="bi bi-pencil-fill me-1"></i> কাস্টমার এডিট করুন';
                document.getElementById('saveCustomerBtn').innerHTML =
                    '<span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span><i class="bi bi-check-lg me-1"></i> সংরক্ষণ করুন';
                document.getElementById('customerId').value        = c.id;
                document.getElementById('name').value             = c.name || '';
                document.getElementById('phone').value            = c.phone || '';
                document.getElementById('address').value          = c.address || '';
                document.getElementById('email').value            = c.email || '';
                document.getElementById('nid').value              = c.nid || '';
                document.getElementById('note').value             = c.note || '';
                if (c.photo_path) {
                    document.getElementById('photoPreview').src = c.photo_path;
                    document.getElementById('photoPreview').classList.remove('d-none');
                }
                customerModal.show();
            })
            .catch(() => showToast('নেটওয়ার্ক এরর।', true));
    }

    function openView(id) {
        fetch('customers.php?action=get&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { showToast(res.message || 'লোড করতে ব্যর্থ হয়েছে।', true); return; }
                const c = res.data;
                viewedId = c.id;
                document.getElementById('viewPhoto').src             = photoUrl(c.photo_path);
                document.getElementById('viewName').textContent      = c.name || '-';
                document.getElementById('viewPhone').textContent     = c.phone || '-';
                document.getElementById('viewAddress').textContent   = c.address || '-';
                document.getElementById('viewEmail').textContent     = c.email || '-';
                document.getElementById('viewNid').textContent       = c.nid || '-';
                document.getElementById('viewNote').textContent      = c.note || '-';
                document.getElementById('viewCreatedBy').textContent = c.created_by_username || '-';
                document.getElementById('viewCreatedAt').textContent = fmtDate(c.created_at);
                document.getElementById('viewUpdatedAt').textContent = fmtDate(c.updated_at);
                document.getElementById('historyFromViewBtn').href   = 'customer_history.php?customer_id=' + c.id;
                viewModal.show();
            })
            .catch(() => showToast('নেটওয়ার্ক এরর।', true));
    }

    document.getElementById('editFromViewBtn').addEventListener('click', () => {
        if (!viewedId) return;
        viewModal.hide();
        setTimeout(() => openEdit(viewedId), 200);
    });

    // Delegate clicks for both desktop table and mobile list
    function handleListClick(e) {
        const vBtn = e.target.closest('.btn-view');
        const eBtn = e.target.closest('.btn-edit');
        if (vBtn) openView(vBtn.dataset.id);
        if (eBtn) openEdit(eBtn.dataset.id);
    }
    document.getElementById('customersTableBody').addEventListener('click', handleListClick);
    document.getElementById('customerListMobile').addEventListener('click', handleListClick);

    // ── Save submit ────────────────────────────────────────────────────
    document.getElementById('customerForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const alert   = document.getElementById('formAlert');
        const saveBtn = document.getElementById('saveCustomerBtn');
        const spinner = document.getElementById('saveSpinner');

        alert.classList.add('d-none');
        saveBtn.disabled = true;
        spinner.classList.remove('d-none');

        fetch('customers.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(this),
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                customerModal.hide();
                showToast(res.message || 'সংরক্ষণ করা হয়েছে।', false);
                loadCustomers(state.page);
            } else {
                alert.textContent = res.message || 'সংরক্ষণ করতে ব্যর্থ হয়েছে।';
                alert.classList.remove('d-none');
            }
        })
        .catch(() => {
            alert.textContent = 'নেটওয়ার্ক এরর। আবার চেষ্টা করুন।';
            alert.classList.remove('d-none');
        })
        .finally(() => {
            saveBtn.disabled = false;
            document.getElementById('saveSpinner').classList.add('d-none');
        });
    });

    loadCustomers(1);
})();
</script>
</body>
</html>