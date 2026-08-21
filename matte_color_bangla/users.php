<?php
/**
 * users.php
 * FineBullion Desk — User Management (Admin only)
 */

require_once __DIR__ . '/auth.php';
require_role('admin');
require_once __DIR__ . '/upload_helper.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

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
// Photo upload
// -----------------------------------------------------------------------
define('USER_UPLOAD_DIR',    __DIR__ . '/uploads/users/');
define('USER_UPLOAD_WEBDIR', 'uploads/users/');

function handle_user_photo_upload(array $file): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) return null;

    if (!is_dir(USER_UPLOAD_DIR)) mkdir(USER_UPLOAD_DIR, 0755, true);

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $filename = uniqid('user_') . '.' . $ext;
    $dest     = USER_UPLOAD_DIR . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return USER_UPLOAD_WEBDIR . $filename;
    }
    return null;
}

function delete_user_photo(?string $path): void
{
    if (!$path) return;
    $full = __DIR__ . '/' . $path;
    if (file_exists($full)) @unlink($full);
}

// -----------------------------------------------------------------------
// AJAX actions
// -----------------------------------------------------------------------
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($isAjax || $action !== null) {

    // ---- LIST ----------------------------------------------------------
    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $search  = trim($_GET['search'] ?? '');
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 10;
        $offset  = ($page - 1) * $perPage;

        if ($search !== '') {
            $like = '%' . $search . '%';

            $cntStmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM users WHERE username LIKE ?");
            mysqli_stmt_bind_param($cntStmt, 's', $like);
            mysqli_stmt_execute($cntStmt);
            mysqli_stmt_bind_result($cntStmt, $total);
            mysqli_stmt_fetch($cntStmt);
            mysqli_stmt_close($cntStmt);

            $stmt = mysqli_prepare($conn,
                "SELECT id, username, role, photo_path, created_at, updated_at
                 FROM users WHERE username LIKE ?
                 ORDER BY created_at DESC LIMIT ? OFFSET ?");
            mysqli_stmt_bind_param($stmt, 'sii', $like, $perPage, $offset);
        } else {
            $cntStmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM users");
            mysqli_stmt_execute($cntStmt);
            mysqli_stmt_bind_result($cntStmt, $total);
            mysqli_stmt_fetch($cntStmt);
            mysqli_stmt_close($cntStmt);

            $stmt = mysqli_prepare($conn,
                "SELECT id, username, role, photo_path, created_at, updated_at
                 FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?");
            mysqli_stmt_bind_param($stmt, 'ii', $perPage, $offset);
        }

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $rows   = [];
        while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;

        json_out([
            'success'    => true,
            'data'       => $rows,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalRows'  => (int)$total,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
        ]);
    }

    // ---- GET single ----------------------------------------------------
    if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['success' => false, 'message' => 'Invalid ID.'], 400);

        $stmt = mysqli_prepare($conn,
            "SELECT id, username, role, photo_path, created_at, updated_at FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$row) json_out(['success' => false, 'message' => 'User not found.'], 404);
        json_out(['success' => true, 'data' => $row]);
    }

    // ---- SAVE (add or update) ------------------------------------------
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id       = (int)($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $role     = trim($_POST['role'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($username === '' || !in_array($role, ['admin', 'employee'], true)) {
            json_out(['success' => false, 'message' => 'Username and role are required.'], 422);
        }

        // Password required on create, optional on edit
        if ($id === 0) {
            if ($password === '') json_out(['success' => false, 'message' => 'Password is required.'], 422);
            if ($password !== $confirm) json_out(['success' => false, 'message' => 'Passwords do not match.'], 422);
        } elseif ($password !== '' && $password !== $confirm) {
            json_out(['success' => false, 'message' => 'Passwords do not match.'], 422);
        }

        $photoPath = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoPath = handle_user_photo_upload($_FILES['photo']);
        }

        if ($id > 0) {
            // Fetch existing
            $ex = mysqli_prepare($conn, "SELECT password, photo_path FROM users WHERE id = ?");
            mysqli_stmt_bind_param($ex, 'i', $id);
            mysqli_stmt_execute($ex);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($ex));
            if (!$existing) json_out(['success' => false, 'message' => 'User not found.'], 404);

            $finalPhoto = $photoPath ?? $existing['photo_path'];
            $finalHash  = $password !== ''
                        ? password_hash($password, PASSWORD_DEFAULT)
                        : $existing['password'];

            $stmt = mysqli_prepare($conn,
                "UPDATE users SET username=?, role=?, password=?, photo_path=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'ssssi', $username, $role, $finalHash, $finalPhoto, $id);
            mysqli_stmt_execute($stmt);

            if ($photoPath && $existing['photo_path']) delete_user_photo($existing['photo_path']);

            // Refresh session if editing own record
            if ($id === (int)$_SESSION['user_id']) {
                $_SESSION['username'] = $username;
                $_SESSION['role']     = $role;
            }

            json_out(['success' => true, 'message' => 'User updated.', 'id' => $id]);
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn,
                "INSERT INTO users (username, password, role, photo_path) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'ssss', $username, $hash, $role, $photoPath);

            if (!mysqli_stmt_execute($stmt)) {
                json_out(['success' => false, 'message' => 'Username already taken or DB error.'], 422);
            }

            $newId = (int)mysqli_insert_id($conn);
            json_out(['success' => true, 'message' => 'User created.', 'id' => $newId]);
        }
    }

    json_out(['success' => false, 'message' => 'Unknown action.'], 400);
}
?>
<!DOCTYPE html>
<html lang="bn" translate="no">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ইউজার ম্যানেজমেন্ট — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --navy:           #2F4156;
    --teal:           #567C8D;
    --sky:            #C8D9E6;
    --beige:          #F5EFEB;
    --white:          #FFFFFF;

    --text-primary:   #2F4156;
    --text-secondary: #567C8D;
    --text-on-navy:   #FFFFFF;
    --border-default: #C8D9E6;
    --border-strong:  #567C8D;
    --bg-app:         #F5EFEB;
    --bg-card:        #FFFFFF;
    --bg-hover:       #EAF1F6;
    --success:        #3D7A5C;
    --danger:         #A6434B;
    --shadow:         0 2px 8px rgba(47, 65, 86, 0.08);
}

body {
    background: var(--bg-app);
    font-family: 'Inter', 'Noto Sans Bengali', system-ui, -apple-system, sans-serif;
    color: var(--text-primary);
}

/* ---- Page Header ---- */
.page-header {
    background: var(--navy) !important;
    color: var(--text-on-navy) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: wrap !important;
    gap: 1rem !important;
    padding: 1rem 1.75rem !important;
    margin: 0 !important;
    border-radius: 0 0 16px 16px !important;
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
.page-header h1, .page-header h4 {
    color: var(--text-on-navy) !important;
    margin: 0;
    font-weight: 700;
    font-size: 22px;
}
.page-header small, .page-header .subtitle {
    color: rgba(255, 255, 255, .78) !important;
    font-size: 13px;
    font-weight: 500;
}

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
    transition: background 0.15s, border-color 0.15s;
}
.header-action-btn:hover, .header-action-btn:focus {
    background: var(--teal);
    border-color: #fff;
    color: #fff;
}
.header-action-btn i, .header-action-btn svg { color: #fff; }

/* Inset spacing for content below header */
.page-inset { padding: 0 1.5rem; margin-top: 20px; }

/* ---- Buttons ---- */
.btn-primary, .btn-gold, .btn-fb-primary {
    background: var(--navy) !important;
    border: 1.5px solid var(--navy) !important;
    color: #fff !important;
    border-radius: 8px !important;
    font-weight: 600 !important;
    font-size: 14px;
    padding: .55rem 1.1rem;
}
.btn-primary:hover, .btn-primary:focus,
.btn-gold:hover, .btn-gold:focus,
.btn-fb-primary:hover, .btn-fb-primary:focus {
    background: var(--teal) !important;
    border-color: var(--teal) !important;
    color: #fff !important;
}

.btn-secondary, .btn-fb-secondary {
    background: #fff !important;
    border: 1.5px solid var(--border-default) !important;
    color: var(--navy) !important;
    border-radius: 8px !important;
    font-weight: 600 !important;
    font-size: 14px;
    padding: .55rem 1.1rem;
}
.btn-secondary:hover, .btn-fb-secondary:hover {
    background: var(--bg-hover) !important;
    border-color: var(--teal) !important;
    color: var(--navy) !important;
}

.btn-outline-secondary, .btn-outline-primary {
    background: transparent !important;
    border: 1.5px solid var(--teal) !important;
    color: var(--teal) !important;
    border-radius: 8px !important;
    font-weight: 600 !important;
}
.btn-outline-secondary:hover, .btn-outline-primary:hover {
    background: var(--sky) !important;
    color: var(--navy) !important;
    border-color: var(--navy) !important;
}

.btn-danger {
    background: #fff !important;
    border: 1.5px solid var(--danger) !important;
    color: var(--danger) !important;
    border-radius: 8px !important;
    font-weight: 600 !important;
}
.btn-danger:hover {
    background: var(--danger) !important;
    color: #fff !important;
}

/* Micro Icon Steppers / Actions */
.btn-icon-round {
    width: 34px; height: 34px; border-radius: 50%;
    background: var(--sky); color: var(--navy);
    border: none; display: flex; align-items: center; justify-content: center;
}

/* ---- Cards ---- */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border-default);
    border-radius: 14px;
    box-shadow: var(--shadow);
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
    padding: .75rem 1.1rem;
}

/* ---- Total Count Strip ---- */
.total-strip {
    background: var(--sky);
    color: var(--navy);
    font-weight: 700;
    font-size: 12.5px;
    padding: 0.5rem 1.1rem;
    border-bottom: 1px solid var(--border-default);
}

/* ---- Table ---- */
.table thead th {
    background: var(--beige) !important;
    color: var(--text-secondary) !important;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    border-bottom: 1.5px solid var(--border-default) !important;
    padding: .65rem .75rem;
}
.table tbody td {
    padding: .65rem .75rem;
    border-bottom: 1px solid var(--border-default);
    font-size: 13.5px;
    font-weight: 500;
    color: var(--text-primary);
    vertical-align: middle;
}
.table tbody tr:hover td {
    background: var(--bg-hover) !important;
}

/* ---- User Avatars ---- */
.user-avatar {
    width: 42px; height: 42px; object-fit: cover; border-radius: 8px;
    border: 1px solid var(--border-default); flex-shrink: 0;
}
.user-avatar-lg { width: 120px; height: 120px; object-fit: cover; border-radius: 12px; border: 1px solid var(--border-default); }
.photo-preview { width: 100px; height: 100px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border-default); }
.user-photo-thumb { width: 40px; height: 40px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border-default); }

/* ---- Role Badges / Status Chips ---- */
.chip-status {
    display: inline-block;
    padding: 2px 10px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 999px;
    text-transform: uppercase;
}
.badge-admin    { background: var(--navy); color: var(--text-on-navy); }
.badge-employee { background: #EAF3EE; color: var(--success); }

/* ---- Mobile Card-Row List ---- */
.user-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border-default);
    text-decoration: none;
    color: inherit;
    transition: background 0.12s ease;
}
.user-row:last-child { border-bottom: none; }
.user-row:hover { background: var(--bg-hover); }

.user-row .ur-info { flex: 1; min-width: 0; }
.user-row .ur-name { font-weight: 700; font-size: 14px; color: var(--text-primary); margin-bottom: 2px; }

.user-row .ur-meta { text-align: right; flex-shrink: 0; }
.user-row .ur-date { font-size: 12.5px; color: var(--text-secondary); white-space: nowrap; }

.ur-actions { display: flex; gap: 6px; flex-shrink: 0; }
.ur-actions .btn {
    width: 32px; height: 32px; padding: 0; display: inline-flex;
    align-items: center; justify-content: center; border-radius: 8px; font-size: 0.85rem;
}

/* Action buttons in table */
.btn-actions { display: flex; gap: 6px; justify-content: center; }
.btn-actions .btn { border-radius: 8px; font-size: 0.8rem; }

/* ---- Search bar ---- */
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
}
.search-wrap .form-control:focus {
    box-shadow: none;
    border-color: var(--teal);
}
.search-wrap .btn-outline-secondary {
    border: 1.5px solid var(--border-default) !important;
    border-left: none !important;
    border-radius: 0 8px 8px 0 !important;
    color: var(--text-secondary) !important;
    background: #fff !important;
}
.search-wrap .btn-outline-secondary:hover {
    background: var(--bg-hover) !important;
    color: var(--navy) !important;
}

/* ---- Pagination ---- */
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
.page-link:focus { box-shadow: 0 0 0 3px rgba(86,124,141,.15); }

/* ---- Modal ---- */
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
.modal-body { padding: 1.25rem; background: var(--bg-card); }
.modal-footer { padding: .85rem 1.25rem; background: var(--beige); border-top: 1px solid var(--border-default); }

/* ---- Form Controls ---- */
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

label, .form-label {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: .03em;
    margin-bottom: .3rem;
}
.form-label .text-danger { color: var(--danger) !important; font-weight: 700; }

/* Input Group Button override */
.input-group .btn-outline-secondary {
    border: 1.5px solid var(--border-default) !important;
    border-left: none !important;
    border-radius: 0 8px 8px 0 !important;
}

/* Field Row Layout */
.field-row { display: flex; align-items: center; gap: 0.75rem; }
.field-row .field-label { flex: 0 0 150px; max-width: 150px; margin-bottom: 0; padding-right: 0.25rem; }
.field-row .field-input { flex: 1 1 auto; min-width: 0; }
.field-row .field-input .d-flex { flex-wrap: wrap; }
@media (max-width: 575.98px) {
    .field-row { align-items: flex-start; flex-direction: column; gap: .25rem; }
    .field-row .field-label { flex-basis: auto; max-width: 100%; }
}

/* Toast */
.toast { border-radius: 8px !important; font-size: 13px; font-weight: 600; box-shadow: var(--shadow); }

/* Alert */
.alert-fb { background: #FBECEC; border: 1px solid var(--danger); color: var(--danger); border-radius: 8px; font-size: 13.5px; }

/* ---------------------------------------------------------------
   Mobile Compaction (≤576px)
--------------------------------------------------------------- */
@media (max-width: 576px) {
    .page-inset { padding: 0 1rem; margin-top: 16px; }

    .page-header {
        padding: .85rem 1.1rem !important;
        border-radius: 0 0 14px 14px !important;
    }
    .page-header h1, .page-header h4 { font-size: 18px; }

    .card { border-radius: 14px !important; padding: .85rem; }
    .card-header { padding: .65rem .85rem; }
    .card-header .fw-semibold { font-size: 15px; }
    .card-header .input-group { max-width: 100% !important; width: 100%; }

    .total-strip { font-size: 12px; padding: 0.4rem 0.85rem; }

    .user-row { padding: 0.65rem 0.85rem; gap: 0.55rem; }
    .user-avatar { width: 36px; height: 36px; }
    .user-row .ur-name { font-size: 13.5px; }

    .card-footer { padding: 0.5rem 0.85rem; }
    .card-footer small { font-size: 12px; }

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
<div class="container-fluid px-0">

    <!-- ================================================================
         PAGE HEADER — Flat navy bar with two-part structure
    ================================================================ -->
    <div class="page-header mb-4">
        <div class="header-left">
            <h4>
                <i class="bi bi-person-gear me-2"></i>
                <span class="d-none d-md-inline">ইউজার ম্যানেজমেন্ট</span>
                <span class="d-md-none">ইউজারগণ</span>
            </h4>
            <small class="subtitle">FineBullion Desk</small>
        </div>
        <div class="header-right">
            <a href="customers.php" class="header-action-btn d-none d-md-inline-flex">
                <i class="bi bi-arrow-left"></i> কাস্টমারসমূহ
            </a>
            <button type="button" class="header-action-btn d-none d-md-inline-flex" data-bs-toggle="modal" data-bs-target="#userModal" id="btnAddUser">
                <i class="bi bi-plus-lg"></i> ইউজার যোগ করুন
            </button>
            <button type="button" class="header-action-btn d-md-none" data-bs-toggle="modal" data-bs-target="#userModal" id="btnAddUserMobile">
                <i class="bi bi-plus-lg"></i><span>যোগ করুন</span>
            </button>
        </div>
    </div>

    <div class="page-inset">

    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-list-ul me-1"></i> ইউজার তালিকা</span>
            <div class="input-group search-wrap" style="max-width:300px;">
                <span class="input-group-text"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="searchInput" class="form-control" placeholder="ইউজারনেম খুঁজুন…">
                <button class="btn btn-outline-secondary" id="clearSearchBtn"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>

        <div class="total-strip">
            মোট : <span id="totalCount">0</span> জন ইউজার
        </div>

        <div class="card-body p-0">

            <!-- ============ MOBILE LIST (card rows) ============ -->
            <div id="usersListMobile" class="d-md-none">
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
                            <th>ইউজারনেম</th>
                            <th>রোল</th>
                            <th style="width:140px;">তৈরির তারিখ</th>
                            <th style="width:100px;" class="text-center">অ্যাকশন</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <tr><td colspan="5" class="text-center py-4 text-muted">লোড হচ্ছে…</td></tr>
                    </tbody>
                </table>
            </div>

        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted" id="paginationInfo">&nbsp;</small>
            <nav><ul class="pagination pagination-sm mb-0" id="paginationControls"></ul></nav>
        </div>
    </div>
    </div><!-- /page-inset -->
</div><!-- /container-fluid -->
</div><!-- /page-content -->

<!-- ADD / EDIT MODAL -->
<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="userForm" enctype="multipart/form-data">
        <div class="modal-header fb-modal-header">
          <h5 class="modal-title" id="userModalLabel"><i class="bi bi-person-plus-fill me-1"></i> ইউজার যোগ করুন</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="formAlert" class="alert alert-danger d-none mb-3"></div>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="userId" value="0">
          <div class="row g-3">
            <div class="col-12 field-row">
              <label class="form-label field-label">ইউজারনেম <span class="text-danger">*</span></label>
              <div class="field-input">
                <input type="text" class="form-control" id="username" name="username" maxlength="100">
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">রোল <span class="text-danger">*</span></label>
              <div class="field-input">
                <select class="form-select" id="role" name="role">
                  <option value="">রোল নির্বাচন করুন…</option>
                  <option value="admin">Admin</option>
                  <option value="employee">Employee</option>
                </select>
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">
                পাসওয়ার্ড <span class="text-danger" id="pwRequired">*</span>
                <small class="text-muted d-block text-lowercase fw-normal" id="pwOptional" style="display:none;">(পরিবর্তন না করতে চাইলে ফাঁকা রাখুন)</small>
              </label>
              <div class="field-input">
                <div class="input-group">
                  <input type="password" class="form-control" id="password" name="password">
                  <button type="button" class="btn btn-outline-secondary" id="togglePwBtn"><i class="bi bi-eye" id="togglePwIco"></i></button>
                </div>
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">
                পাসওয়ার্ড নিশ্চিত করুন <span class="text-danger" id="cfRequired">*</span>
              </label>
              <div class="field-input">
                <input type="password" class="form-control" id="confirm_password" name="confirm_password">
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">ছবি</label>
              <div class="field-input d-flex align-items-center gap-3">
                <input type="file" class="form-control" id="photoInput" name="photo" accept="image/*">
                <img id="photoPreview" src="" alt="প্রিভিউ" class="photo-preview d-none">
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button>
          <button type="submit" class="btn btn-primary" id="saveBtn">
            <span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span>
            <span id="saveLabel"><i class="bi bi-check-lg me-1"></i> ইউজার যোগ করুন</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- VIEW MODAL -->
<div class="modal fade" id="viewUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header fb-modal-header">
        <h5 class="modal-title"><i class="bi bi-person-lines-fill me-1"></i> ইউজারের বিবরণ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <img id="viewPhoto" src="" alt="ছবি" class="user-avatar-lg mb-3">
        <h5 id="viewUsername" class="mb-1 text-primary fw-bold">-</h5>
        <div id="viewRoleBadge" class="mb-3">-</div>
        <table class="table table-borderless table-sm text-start">
          <tr><th style="width:120px;">তৈরির তারিখ</th><td id="viewCreated">-</td></tr>
          <tr><th>সর্বশেষ আপডেট</th><td id="viewUpdated">-</td></tr>
        </table>
      </div>
      <div class="modal-footer">
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

    const userModal  = new bootstrap.Modal(document.getElementById('userModal'));
    const viewModal  = new bootstrap.Modal(document.getElementById('viewUserModal'));
    const appToastEl = document.getElementById('appToast');
    const appToast   = new bootstrap.Toast(appToastEl, { delay: 3000 });
    let viewedId     = null;

    function esc(s) {
        return s == null ? '' : String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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
            '<circle cx="12" cy="9" r="4" fill="%232F4156" fill-opacity="0.55"/>' +
            '<path d="M4 20c0-4 4-6 8-6s8 2 8 6" fill="%232F4156" fill-opacity="0.55"/></svg>'
        );
    }

    function photoUrl(p) { return p || avatarSvg(); }

    function fmtDate(s) {
        if (!s) return '-';
        const d = new Date(s.replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
    }

    function roleBadge(role) {
        if (role === 'admin')    return '<span class="chip-status badge-admin">Admin</span>';
        if (role === 'employee') return '<span class="chip-status badge-employee">Employee</span>';
        return esc(role);
    }

    // ── Load list ──────────────────────────────────────────────────────
    function loadUsers(page) {
        state.page = page || 1;

        const mobileList = document.getElementById('usersListMobile');
        const tbody = document.getElementById('usersTableBody');
        mobileList.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>লোড হচ্ছে…</div>';
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">' +
            '<span class="spinner-border spinner-border-sm me-2"></span>লোড হচ্ছে…</td></tr>';

        const params = new URLSearchParams({ action: 'list', page: state.page, search: state.search });
        fetch('users.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">লোড করতে ব্যর্থ হয়েছে।</td></tr>';
                    mobileList.innerHTML = '<div class="text-center py-4 text-danger">লোড করতে ব্যর্থ হয়েছে।</div>';
                    return;
                }
                document.getElementById('totalCount').textContent = res.totalRows;
                renderTable(res.data);
                renderMobileList(res.data);
                renderPagination(res.page, res.totalPages, res.totalRows, res.data.length);
            })
            .catch(() => {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">নেটওয়ার্ক সমস্যা।</td></tr>';
                mobileList.innerHTML = '<div class="text-center py-4 text-danger">নেটওয়ার্ক সমস্যা।</div>';
            });
    }

    // ── Desktop table ─────────────────────────────────────────────────
    function renderTable(rows) {
        const tbody = document.getElementById('usersTableBody');
        if (!rows || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">কোনো ইউজার পাওয়া যায়নি।</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(u => `
            <tr>
                <td><img src="${esc(photoUrl(u.photo_path))}" class="user-photo-thumb" alt="" onerror="this.src='${avatarSvg()}'"></td>
                <td>${esc(u.username)}</td>
                <td>${roleBadge(u.role)}</td>
                <td><small>${esc(fmtDate(u.created_at))}</small></td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-secondary me-1 btn-view" data-id="${u.id}" title="দেখুন"><i class="bi bi-eye-fill"></i></button>
                    <button class="btn btn-sm btn-outline-primary btn-edit" data-id="${u.id}" title="এডিট"><i class="bi bi-pencil-fill"></i></button>
                </td>
            </tr>`).join('');
    }

    // ── Mobile card-row list ──────────────────────────────────────────
    function renderMobileList(rows) {
        const wrap = document.getElementById('usersListMobile');
        if (!rows || !rows.length) {
            wrap.innerHTML = '<div class="text-center py-4 text-muted">কোনো ইউজার পাওয়া যায়নি।</div>';
            return;
        }
        wrap.innerHTML = rows.map(u => `
            <div class="user-row">
                <img src="${esc(photoUrl(u.photo_path))}" class="user-avatar" alt="" onerror="this.src='${avatarSvg()}'">
                <div class="ur-info">
                    <div class="ur-name">${esc(u.username)}</div>
                    <div>${roleBadge(u.role)}</div>
                </div>
                <div class="ur-meta">
                    <div class="ur-date mb-1">${esc(fmtDate(u.created_at))}</div>
                    <div class="ur-actions">
                        <button class="btn btn-outline-secondary btn-view" data-id="${u.id}" title="দেখুন"><i class="bi bi-eye-fill"></i></button>
                        <button class="btn btn-outline-primary btn-edit" data-id="${u.id}" title="এডিট"><i class="bi bi-pencil-fill"></i></button>
                    </div>
                </div>
            </div>`).join('');
    }

    function renderPagination(page, totalPages, totalRows, onPage) {
        const info = document.getElementById('paginationInfo');
        const ctrl = document.getElementById('paginationControls');
        const start = totalRows === 0 ? 0 : (page - 1) * 10 + 1;
        const end   = (page - 1) * 10 + onPage;
        info.textContent = `মোট ${totalRows} জন ইউজারের মধ্যে ${start}–${end} দেখানো হচ্ছে`;
        ctrl.innerHTML = '';
        if (totalPages <= 1) return;

        const mk = (label, target, disabled, active) => {
            const li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
            const a = document.createElement('a');
            a.className = 'page-link'; a.href = '#'; a.textContent = label;
            if (!disabled && !active) a.addEventListener('click', e => { e.preventDefault(); loadUsers(target); });
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
        state.debounce = setTimeout(() => { state.search = v.trim(); loadUsers(1); }, 320);
    });
    document.getElementById('clearSearchBtn').addEventListener('click', () => {
        document.getElementById('searchInput').value = '';
        state.search = '';
        loadUsers(1);
    });

    // ── Form helpers ───────────────────────────────────────────────────
    function resetForm() {
        document.getElementById('userForm').reset();
        document.getElementById('userId').value = '0';
        document.getElementById('formAlert').classList.add('d-none');
        document.getElementById('photoPreview').classList.add('d-none');
        document.getElementById('photoPreview').src = '';
        document.getElementById('userModalLabel').innerHTML = '<i class="bi bi-person-plus-fill me-1"></i> ইউজার যোগ করুন';
        document.getElementById('saveLabel').innerHTML = '<i class="bi bi-check-lg me-1"></i> ইউজার যোগ করুন';
        document.getElementById('pwRequired').style.display  = '';
        document.getElementById('pwOptional').style.display  = 'none';
        document.getElementById('cfRequired').style.display  = '';
    }

    document.getElementById('btnAddUser').addEventListener('click', resetForm);
    document.getElementById('btnAddUserMobile').addEventListener('click', resetForm);

    document.getElementById('photoInput').addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) { document.getElementById('photoPreview').classList.add('d-none'); return; }
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('photoPreview').src = e.target.result;
            document.getElementById('photoPreview').classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    });

    document.getElementById('togglePwBtn').addEventListener('click', () => {
        const pw = document.getElementById('password');
        const hidden = pw.type === 'password';
        pw.type = hidden ? 'text' : 'password';
        document.getElementById('togglePwIco').className = hidden ? 'bi bi-eye-slash' : 'bi bi-eye';
    });

    function openEdit(id) {
        fetch('users.php?action=get&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { showToast(res.message || 'লোড করতে ব্যর্থ হয়েছে।', true); return; }
                resetForm();
                const u = res.data;
                document.getElementById('userModalLabel').innerHTML = '<i class="bi bi-pencil-fill me-1"></i> ইউজার এডিট করুন';
                document.getElementById('saveLabel').innerHTML = '<i class="bi bi-check-lg me-1"></i> সংরক্ষণ করুন';
                document.getElementById('userId').value   = u.id;
                document.getElementById('username').value = u.username || '';
                document.getElementById('role').value     = u.role || '';
                // Password optional on edit
                document.getElementById('pwRequired').style.display = 'none';
                document.getElementById('pwOptional').style.display = '';
                document.getElementById('cfRequired').style.display = 'none';
                if (u.photo_path) {
                    document.getElementById('photoPreview').src = u.photo_path;
                    document.getElementById('photoPreview').classList.remove('d-none');
                }
                userModal.show();
            })
            .catch(() => showToast('নেটওয়ার্ক সমস্যা।', true));
    }

    function openView(id) {
        fetch('users.php?action=get&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { showToast(res.message || 'লোড করতে ব্যর্থ হয়েছে।', true); return; }
                const u = res.data;
                viewedId = u.id;
                const img = document.getElementById('viewPhoto');
                img.src = photoUrl(u.photo_path);
                img.onerror = () => { img.src = avatarSvg(); };
                document.getElementById('viewUsername').textContent  = u.username || '-';
                document.getElementById('viewRoleBadge').innerHTML   = roleBadge(u.role);
                document.getElementById('viewCreated').textContent   = fmtDate(u.created_at);
                document.getElementById('viewUpdated').textContent   = fmtDate(u.updated_at);
                viewModal.show();
            })
            .catch(() => showToast('নেটওয়ার্ক সমস্যা।', true));
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
    document.getElementById('usersTableBody').addEventListener('click', handleListClick);
    document.getElementById('usersListMobile').addEventListener('click', handleListClick);

    // ── Save submit ────────────────────────────────────────────────────
    document.getElementById('userForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const alertEl = document.getElementById('formAlert');
        const saveBtn = document.getElementById('saveBtn');
        const spinner = document.getElementById('saveSpinner');
        const label   = document.getElementById('saveLabel');

        alertEl.classList.add('d-none');
        saveBtn.disabled = true;
        spinner.classList.remove('d-none');
        label.classList.add('d-none');

        fetch('users.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(this),
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                userModal.hide();
                showToast(res.message || 'সংরক্ষণ করা হয়েছে।', false);
                loadUsers(state.page);
            } else {
                alertEl.textContent = res.message || 'সংরক্ষণ করতে ব্যর্থ হয়েছে।';
                alertEl.classList.remove('d-none');
            }
        })
        .catch(() => {
            alertEl.textContent = 'নেটওয়ার্ক সমস্যা। আবার চেষ্টা করুন।';
            alertEl.classList.remove('d-none');
        })
        .finally(() => {
            saveBtn.disabled = false;
            spinner.classList.add('d-none');
            label.classList.remove('d-none');
        });
    });

    loadUsers(1);
})();
</script>
</body>
</html>