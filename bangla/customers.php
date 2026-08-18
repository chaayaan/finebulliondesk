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
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>কাস্টমার ম্যানেজমেন্ট — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── Design Tokens ─────────────────────────────────────────────────── */
:root {
    --gold-deep:   #c9973a;
    --gold-mid:    #dcb04a;
    --gold-light:  #e9cd7d;
    --ivory:       #fbf8f2;
    --bronze-text: #3a2f1a;
    --muted:       #9a8f76;
    --hairline:    #ecdfb8;

    /* Legacy aliases kept for PHP partials (navbar, etc.) */
    --fb-green: #0B412A;
    --fb-gold:  #DCAD41;
}

/* ── Base ──────────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }

body {
    background: var(--ivory);
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--bronze-text);
}

/* ── Page Header Bar ───────────────────────────────────────────────── */
.list-header {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%);
    color: #fff;
    padding: 1.4rem 1.75rem;
    margin-top: 0;
    position: relative;
    border-top-left-radius: 0;
    border-top-right-radius: 0;
    border-bottom-left-radius: 20px;
    border-bottom-right-radius: 20px;
    box-shadow: 0 6px 24px rgba(201, 151, 58, 0.22);
}
.list-header h4 { color: #fff; font-weight: 800; letter-spacing: 0.02em; }
.list-header small { color: rgba(255,255,255,0.80); font-size: 0.8rem; }
.list-header i { color: #fff; }

/* ── Buttons ────────────────────────────────────────────────────────── */
.btn-gold {
    background: var(--gold-deep);
    border: none;
    color: #fff;
    font-weight: 700;
    border-radius: 999px;
    box-shadow: 0 6px 18px rgba(201, 151, 58, 0.30);
    transition: background 0.15s, transform 0.1s, box-shadow 0.15s;
}
.btn-gold:hover { background: #b8872e; transform: translateY(-1px); color: #fff; box-shadow: 0 8px 22px rgba(201, 151, 58, 0.38); }
.btn-gold:active { transform: translateY(0); color: #fff; background: #a87828; }

/* ── Main Card ──────────────────────────────────────────────────────── */
.card {
    background: #fff;
    border: 1px solid var(--hairline);
    border-radius: 18px !important;
    box-shadow: 0 10px 30px rgba(180, 140, 50, 0.12);
}
.card-header {
    background: #fff !important;
    border-bottom: 1px solid var(--hairline);
    border-radius: 18px 18px 0 0 !important;
    padding: 1rem 1.25rem;
}
.card-header .fw-semibold {
    color: var(--bronze-text);
    font-weight: 700;
    font-size: 0.9rem;
}
.card-footer {
    background: #fff !important;
    border-top: 1px solid var(--hairline);
    border-radius: 0 0 18px 18px !important;
    padding: 0.75rem 1.25rem;
}

/* ── Search Bar ─────────────────────────────────────────────────────── */
.search-wrap .input-group-text {
    background: #fff;
    border: 1.5px solid var(--hairline);
    border-right: none;
    border-radius: 999px 0 0 999px;
    color: var(--muted);
}
.search-wrap .form-control {
    border: 1.5px solid var(--hairline);
    border-left: none;
    border-right: none;
    font-size: 0.85rem;
    color: var(--bronze-text);
}
.search-wrap .form-control:focus {
    box-shadow: none;
    border-color: var(--gold-deep);
}
.search-wrap .form-control::placeholder { color: var(--muted); }
.search-wrap .btn-outline-secondary {
    border: 1.5px solid var(--hairline);
    border-left: none;
    border-radius: 0 999px 999px 0;
    color: var(--muted);
    background: #fff;
}
.search-wrap .btn-outline-secondary:hover { background: var(--ivory); color: var(--bronze-text); }

/* ── Total Count Strip ──────────────────────────────────────────────── */
.total-strip {
    background: linear-gradient(90deg, #fdf6e3, var(--ivory));
    border-bottom: 1px solid var(--hairline);
    color: var(--gold-deep);
    font-weight: 700;
    font-size: 0.82rem;
    padding: 0.5rem 1.25rem;
    letter-spacing: 0.01em;
}

/* ── Table ──────────────────────────────────────────────────────────── */
.table thead th {
    background: var(--ivory);
    color: var(--muted);
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    border-bottom: 1px solid var(--hairline);
    padding: 0.7rem 0.9rem;
}
.table tbody td {
    border-bottom: 1px solid #f5ede0;
    vertical-align: middle;
    padding: 0.7rem 0.9rem;
    font-size: 0.86rem;
    color: var(--bronze-text);
}
.table tbody tr:last-child td { border-bottom: none; }
.table-hover tbody tr:hover td { background: #fdf7ec; }

/* ── Customer Avatar ────────────────────────────────────────────────── */
.customer-avatar {
    width: 44px; height: 44px;
    object-fit: cover;
    border-radius: 50%;
    border: 2px solid var(--hairline);
    flex-shrink: 0;
}
.customer-photo-thumb {
    width: 40px; height: 40px;
    object-fit: cover;
    border-radius: 50%;
    border: 2px solid var(--hairline);
}
.view-photo {
    width: 130px; height: 130px;
    object-fit: cover;
    border-radius: 14px;
    border: 2px solid var(--hairline);
}
.photo-preview {
    width: 110px; height: 110px;
    object-fit: cover;
    border-radius: 10px;
    border: 2px solid var(--hairline);
}

/* ── Mobile Customer Row ─────────────────────────────────────────────── */
.customer-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1.1rem;
    border-bottom: 1px solid #f5ede0;
    text-decoration: none;
    color: inherit;
    transition: background 0.12s ease;
}
.customer-row:last-child { border-bottom: none; }
.customer-row:hover { background: #fdf7ec; }

.customer-row .cr-info { flex: 1; min-width: 0; }
.customer-row .cr-name { font-weight: 700; font-size: 0.9rem; color: var(--bronze-text); margin-bottom: 1px; }
.customer-row .cr-phone { font-size: 0.78rem; color: var(--muted); }
.customer-row .cr-address { font-size: 0.75rem; color: #b8a98a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.customer-row .cr-meta { text-align: right; flex-shrink: 0; }
.customer-row .cr-date { font-size: 0.7rem; color: #c4b89a; white-space: nowrap; margin-bottom: 4px; }

.cr-actions { display: flex; gap: 4px; flex-shrink: 0; justify-content: flex-end; }
.cr-actions .btn {
    width: 30px; height: 30px; padding: 0;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 8px; font-size: 0.82rem;
    border-color: var(--hairline);
}

/* ── Action Buttons in Table ────────────────────────────────────────── */
.btn-actions { display: flex; gap: 4px; justify-content: center; }
.btn-actions .btn {
    border-radius: 8px;
    font-size: 0.8rem;
    border-color: var(--hairline);
}
.btn-outline-secondary { color: var(--muted); border-color: var(--hairline); }
.btn-outline-secondary:hover { background: var(--ivory); color: var(--bronze-text); border-color: var(--gold-mid); }
.btn-outline-primary { color: var(--gold-deep); border-color: var(--hairline); }
.btn-outline-primary:hover { background: #fdf6e3; color: var(--gold-deep); border-color: var(--gold-mid); }
.btn-outline-success { color: var(--gold-deep); border-color: var(--hairline); }
.btn-outline-success:hover { background: #fdf6e3; color: var(--gold-deep); border-color: var(--gold-mid); }

/* ── Pagination ──────────────────────────────────────────────────────── */
.page-link {
    color: var(--gold-deep);
    border-color: var(--hairline);
    border-radius: 8px !important;
    margin: 0 2px;
    font-size: 0.8rem;
    font-weight: 600;
}
.page-item.active .page-link {
    background: var(--gold-deep);
    border-color: var(--gold-deep);
    color: #fff;
}
.page-link:focus { box-shadow: 0 0 0 0.2rem rgba(201, 151, 58, 0.25); }

/* ── Modal ───────────────────────────────────────────────────────────── */
.modal-content {
    border: none;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(58, 47, 26, 0.18);
}
.modal-header.fb-modal-header {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%);
    color: #fff;
    padding: 1.1rem 1.5rem;
    border-bottom: none;
}
.modal-header.fb-modal-header .modal-title { font-weight: 700; color: #fff; font-size: 1rem; }
.modal-header.fb-modal-header .btn-close { filter: brightness(0) invert(1); opacity: 0.85; }
.modal-body { padding: 1.5rem; background: #fff; }
.modal-footer {
    padding: 1rem 1.5rem;
    background: var(--ivory);
    border-top: 1px solid var(--hairline);
}

/* ── Form Controls ───────────────────────────────────────────────────── */
.form-control, .form-select {
    border: 1.5px solid var(--hairline);
    border-radius: 10px;
    font-size: 0.88rem;
    color: var(--bronze-text);
    background: #fff;
    padding: 0.55rem 0.85rem;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.form-control:focus, .form-select:focus {
    border-color: var(--gold-deep);
    box-shadow: 0 0 0 0.2rem rgba(201, 151, 58, 0.18);
    outline: none;
}
.form-control::placeholder { color: #c9bca0; }
textarea.form-control { resize: vertical; }

/* ── Field Row Layout ────────────────────────────────────────────────── */
.field-row { display: flex; align-items: center; gap: 0.75rem; }
.field-row .field-label {
    flex: 0 0 130px; max-width: 130px;
    margin-bottom: 0; padding-right: 0.25rem;
    font-size: 0.82rem; font-weight: 600; color: var(--muted);
}
.field-row .field-label .text-danger { color: var(--gold-deep) !important; font-weight: 800; }
.field-row .field-input { flex: 1 1 auto; min-width: 0; }
.field-row .field-input .d-flex { flex-wrap: wrap; }

/* ── View Modal Table ─────────────────────────────────────────────────── */
.view-detail-table th {
    font-size: 0.78rem; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.04em;
    padding: 0.55rem 0.75rem; width: 130px;
    border-bottom: 1px solid #f5ede0;
}
.view-detail-table td {
    font-size: 0.88rem; color: var(--bronze-text);
    padding: 0.55rem 0.75rem;
    border-bottom: 1px solid #f5ede0;
}
.view-detail-table tr:last-child th,
.view-detail-table tr:last-child td { border-bottom: none; }

/* ── Toast ───────────────────────────────────────────────────────────── */
.toast {
    border-radius: 14px !important;
    font-size: 0.85rem;
    font-weight: 600;
}

/* ── Cancel Button ───────────────────────────────────────────────────── */
.btn-secondary {
    background: #fff;
    border: 1.5px solid var(--hairline);
    color: var(--muted);
    border-radius: 999px;
    font-weight: 600;
}
.btn-secondary:hover { background: var(--ivory); color: var(--bronze-text); border-color: var(--gold-mid); }

/* ── Mobile ──────────────────────────────────────────────────────────── */
@media (max-width: 767.98px) {
    .page-content .container-fluid { padding: 0 0 1rem; }

    .list-header {
        padding: 1rem 1rem;
        border-bottom-left-radius: 16px;
        border-bottom-right-radius: 16px;
        margin-bottom: 1rem !important;
    }
    .list-header h4 { font-size: 1rem; margin-bottom: 0; }
    .list-header h4 i { display: inline; }
    .list-header small { display: none; }

    .card { border-radius: 14px !important; margin: 0 0.5rem; }
    .card-header { padding: 0.65rem 0.85rem; border-radius: 14px 14px 0 0 !important; }
    .card-header .fw-semibold { font-size: 0.82rem; }
    .card-header .input-group { max-width: 100% !important; width: 100%; }

    .total-strip { font-size: 0.76rem; padding: 0.4rem 0.9rem; }

    .customer-row { padding: 0.6rem 0.85rem; gap: 0.55rem; }
    .customer-avatar { width: 38px; height: 38px; }
    .customer-row .cr-name { font-size: 0.85rem; }
    .customer-row .cr-phone, .customer-row .cr-address { font-size: 0.73rem; }
    .cr-actions .btn { width: 28px; height: 28px; font-size: 0.75rem; }

    .card-footer { padding: 0.5rem 0.85rem; border-radius: 0 0 14px 14px !important; }
    .card-footer small { font-size: 0.7rem; }
    .pagination-sm .page-link { padding: 0.25rem 0.5rem; font-size: 0.73rem; }

    .field-row { align-items: flex-start; }
    .field-row .field-label { flex-basis: 90px; max-width: 90px; font-size: 0.78rem; padding-top: 0.45rem; }

    .modal-content { border-radius: 16px; }
    .modal-body { padding: 1rem; }
    .modal-footer { padding: 0.75rem 1rem; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0 pb-4">

    <!-- ── Page Header ── -->
    <div class="list-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4 class="mb-0">
                <i class="bi bi-people-fill me-2"></i>
                <span class="d-none d-md-inline">কাস্টমার ম্যানেজমেন্ট</span>
                <span class="d-md-none">কাস্টমার</span>
            </h4>
            <small>FineBullion Desk</small>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <?php if (is_admin()): ?>
            <a href="users.php" class="btn btn-sm" style="background:rgba(255,255,255,0.18);border:1.5px solid rgba(255,255,255,0.5);color:#fff;border-radius:999px;font-weight:600;">
                <i class="bi bi-person-gear me-1"></i><span class="d-none d-sm-inline">ইউজার</span>
            </a>
            <?php endif; ?>
            <button type="button" class="btn btn-gold btn-sm px-3" data-bs-toggle="modal" data-bs-target="#customerModal" id="btnAddCustomer">
                <i class="bi bi-plus-lg me-1"></i> কাস্টমার যোগ করুন
            </button>
            <!-- Mobile uses same button — no separate icon-only button -->
            <button type="button" class="btn btn-gold btn-sm px-3 d-md-none d-none" data-bs-toggle="modal" data-bs-target="#customerModal" id="btnAddCustomerMobile">
                <i class="bi bi-plus-lg me-1"></i> যোগ করুন
            </button>
        </div>
    </div>

    <div class="px-md-3 px-2">

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-list-ul me-1"></i> কাস্টমার লিস্ট</span>
            <div class="input-group search-wrap" style="max-width:300px;">
                <span class="input-group-text"><i class="bi bi-search text-muted"></i></span>
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
                    <thead class="table-light">
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
          <div id="formAlert" class="alert d-none" style="background:#fdf1e0;border:1px solid #f1cf8e;color:#7a5417;border-radius:12px;font-size:0.85rem;"></div>
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
          <button type="submit" class="btn btn-gold" id="saveCustomerBtn">
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
            <table class="table table-borderless table-sm mb-0">
              <tbody>
                <tr><th style="width:130px;">নাম</th><td id="viewName">-</td></tr>
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
        <button type="button" class="btn btn-gold" id="editFromViewBtn"><i class="bi bi-pencil-fill me-1"></i> এডিট</button>
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
            '<circle cx="12" cy="12" r="12" fill="%23fdf6e3"/>' +
            '<circle cx="12" cy="9" r="4" fill="%23c9973a" fill-opacity="0.60"/>' +
            '<path d="M4 20c0-4 4-6 8-6s8 2 8 6" fill="%23c9973a" fill-opacity="0.50"/></svg>'
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

    // ── Mobile card-row list (image-1 style) ────────────────────────────
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