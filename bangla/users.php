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
    --gold-deep:   #c9973a;
    --gold-mid:    #dcb04a;
    --gold-light:  #e9cd7d;
    --ivory:       #fbf8f2;
    --bronze-text: #3a2f1a;
    --muted:       #9a8f76;
    --hairline:    #ecdfb8;

    --status-paid-bg: #1b5238;
    --status-paid-light: #eaf4ee;
    --status-due-bg: #93292c;
    --status-due-light: #fbeceb;
    --status-total-bg: #b88328;
    --status-total-light: #fdf6e2;
}
body { background: var(--ivory); font-family: 'Inter', system-ui, -apple-system, sans-serif; color: var(--bronze-text); }

/* ---- page header bar — scoped strictly to .list-header and its children
   so this block is completely self-contained and immune to overrides from
   navbar.php or any external stylesheet. Full width, flush to the
   viewport top with no top/side margin. ---- */
.list-header,
.list-header.mb-4 {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%) !important;
    color: #ffffff !important;
    min-height: 60px !important;
    max-height: 80px !important;
    padding: 0.85rem 1.75rem !important;
    margin: 0 !important;
    top: 0;
    width: 100% !important;
    max-width: 100% !important;
    position: relative;
    border-top-left-radius: 0 !important;
    border-top-right-radius: 0 !important;
    border-bottom-left-radius: 20px !important;
    border-bottom-right-radius: 20px !important;
    box-shadow: 0 6px 24px rgba(201, 151, 58, 0.22);
    box-sizing: border-box;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: nowrap !important;
    gap: 1rem !important;
    overflow: hidden;
}
.list-header h4 { color: #ffffff !important; font-weight: 800; letter-spacing: 0.02em; margin-bottom: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.list-header small { color: rgba(255,255,255,0.8) !important; }
.list-header i { color: #ffffff !important; }
.list-header .btn-outline-light {
    background: rgba(255,255,255,0.18);
    border: 1.5px solid rgba(255,255,255,0.5);
    color: #ffffff;
    border-radius: 999px;
    font-weight: 600;
}
.list-header .btn-outline-light:hover {
    background: rgba(255,255,255,0.28);
    border-color: #ffffff;
    color: #ffffff;
}

/* Inset spacing for content below the flush full-width header */
.page-inset { padding: 0 1.5rem; margin-top: 20px; }

/* ---- primary / secondary buttons ---- */
.btn-gold, .btn-fb-primary {
    background: var(--gold-deep);
    border: 1.5px solid var(--gold-deep);
    color: #ffffff;
    font-weight: 700;
    border-radius: 999px;
}
.btn-gold:hover, .btn-gold:focus,
.btn-fb-primary:hover, .btn-fb-primary:focus { background: var(--gold-deep); border-color: var(--gold-deep); color: #ffffff; opacity: 0.92; }

.btn-secondary, .btn-fb-secondary {
    background: #ffffff;
    border: 1.5px solid var(--hairline);
    color: var(--muted);
    font-weight: 600;
    border-radius: 999px;
}
.btn-secondary:hover, .btn-fb-secondary:hover { background: #fdf7ec; border-color: var(--hairline); color: var(--bronze-text); }

.btn-outline-secondary { color: var(--muted); border-color: var(--hairline); }
.btn-outline-secondary:hover { background: var(--ivory); color: var(--bronze-text); border-color: var(--gold-mid); }
.btn-outline-primary { color: var(--gold-deep); border-color: var(--hairline); }
.btn-outline-primary:hover { background: #fdf6e3; color: var(--gold-deep); border-color: var(--gold-mid); }

/* ---- cards ---- */
.card {
    background: #ffffff;
    border: none;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(180, 140, 50, 0.12);
}
.card-header {
    background: #ffffff !important;
    border-bottom: 1px solid var(--hairline);
    border-radius: 18px 18px 0 0 !important;
    color: var(--bronze-text);
}
.card-header .fw-semibold { color: var(--bronze-text); font-weight: 700; font-size: 0.9rem; }
.card-footer {
    background: #ffffff !important;
    border-top: 1px solid var(--hairline);
    border-radius: 0 0 18px 18px !important;
}

/* ---- total count strip ---- */
.total-strip {
    background: linear-gradient(90deg, #fdf6e3, var(--ivory));
    color: var(--gold-deep);
    font-weight: 700;
    font-size: 0.82rem;
    padding: 0.5rem 1.25rem;
    border-bottom: 1px solid var(--hairline);
    letter-spacing: 0.01em;
}

/* ---- table ---- */
.table thead th {
    background: var(--ivory);
    color: var(--muted);
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    border-bottom: 1px solid var(--hairline);
}
.table tbody td { border-bottom: 1px solid #f5ede0; vertical-align: middle; color: var(--bronze-text); }
.table tbody tr:last-child td { border-bottom: none; }
.table-hover tbody tr:hover td { background: #fdf7ec; }

/* ---- user avatar ---- */
.user-avatar {
    width: 44px; height: 44px; object-fit: cover; border-radius: 50%;
    border: 2px solid var(--hairline); flex-shrink: 0;
}
.user-avatar-lg { width: 130px; height: 130px; object-fit: cover; border-radius: 14px; border: 2px solid var(--hairline); }
.photo-preview { width: 110px; height: 110px; object-fit: cover; border-radius: 10px; border: 2px solid var(--hairline); }
.user-photo-thumb { width: 42px; height: 42px; object-fit: cover; border-radius: 50%; border: 2px solid var(--hairline); }

/* ---- role badges (jewel-tone) ---- */
.badge-admin    { background: var(--status-total-bg); color: #ffffff; font-size: .72rem; font-weight: 600; border-radius: 999px; padding: .28em .85em; }
.badge-employee { background: var(--status-paid-bg);  color: #ffffff; font-size: .72rem; font-weight: 600; border-radius: 999px; padding: .28em .85em; }

/* ---- user list row (mobile card-like) ---- */
.user-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.7rem 1rem;
    border-bottom: 1px solid #f5ede0;
    text-decoration: none;
    color: inherit;
    transition: background 0.12s ease;
}
.user-row:last-child { border-bottom: none; }
.user-row:hover { background: #fdf7ec; }

.user-row .ur-info { flex: 1; min-width: 0; }
.user-row .ur-name { font-weight: 700; font-size: 0.92rem; color: var(--bronze-text); margin-bottom: 2px; }

.user-row .ur-meta { text-align: right; flex-shrink: 0; }
.user-row .ur-date { font-size: 0.72rem; color: #c4b89a; white-space: nowrap; }

.ur-actions { display: flex; gap: 4px; flex-shrink: 0; }
.ur-actions .btn {
    width: 32px; height: 32px; padding: 0; display: inline-flex;
    align-items: center; justify-content: center; border-radius: 8px; font-size: 0.85rem;
    border-color: var(--hairline);
}

/* ---- action buttons in table ---- */
.btn-actions { display: flex; gap: 4px; justify-content: center; }
.btn-actions .btn { border-radius: 8px; font-size: 0.8rem; border-color: var(--hairline); }

/* ---- search bar ---- */
.search-wrap .input-group-text { background: #ffffff; border: 1.5px solid var(--hairline); border-right: none; border-radius: 999px 0 0 999px; color: var(--muted); }
.search-wrap .form-control { border: 1.5px solid var(--hairline); border-left: none; border-right: none; font-size: 0.85rem; color: var(--bronze-text); }
.search-wrap .form-control:focus { box-shadow: none; border-color: var(--gold-deep); }
.search-wrap .form-control::placeholder { color: var(--muted); }
.search-wrap .btn-outline-secondary { border: 1.5px solid var(--hairline); border-left: none; border-radius: 0 999px 999px 0; color: var(--muted); background: #ffffff; }

/* ---- pagination ---- */
.page-link { color: var(--gold-deep); border-color: var(--hairline); border-radius: 8px !important; margin: 0 2px; font-size: 0.8rem; font-weight: 600; }
.page-item.active .page-link { background: var(--gold-deep); border-color: var(--gold-deep); color: #ffffff; }
.page-link:focus { box-shadow: 0 0 0 0.2rem rgba(201, 151, 58, 0.25); }

/* ---- modal ---- */
.modal-content { border: none; border-radius: 20px; overflow: hidden; box-shadow: 0 20px 60px rgba(58, 47, 26, 0.18); }
.modal-header.fb-modal-header {
    background: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold-mid) 55%, var(--gold-light) 100%);
    color: #ffffff;
    padding: 1.1rem 1.5rem;
    border-bottom: none;
}
.modal-header.fb-modal-header .modal-title { font-weight: 700; color: #ffffff; font-size: 1rem; }
.modal-header.fb-modal-header .btn-close { filter: brightness(0) invert(1); opacity: 0.85; }
.modal-body { padding: 1.5rem; background: #ffffff; }
.modal-footer { padding: 1rem 1.5rem; background: var(--ivory); border-top: 1px solid var(--hairline); }

/* ---- form controls ---- */
.form-control, .form-select {
    border: 1.5px solid var(--hairline);
    border-radius: 10px;
    font-size: 0.88rem;
    color: var(--bronze-text);
    background: #ffffff;
    padding: 0.55rem 0.85rem;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.form-control:focus, .form-select:focus {
    border-color: var(--gold-deep);
    box-shadow: 0 0 0 0.2rem rgba(201, 151, 58, 0.18);
    outline: none;
}
.form-control::placeholder { color: #c9bca0; }
.form-label .text-danger { color: var(--gold-deep) !important; font-weight: 800; }

/* ---- field row layout ---- */
.field-row { display: flex; align-items: center; gap: 0.75rem; }
.field-row .field-label { flex: 0 0 150px; max-width: 150px; margin-bottom: 0; padding-right: 0.25rem; font-size: 0.82rem; font-weight: 600; color: var(--muted); }
.field-row .field-input { flex: 1 1 auto; min-width: 0; }
.field-row .field-input .d-flex { flex-wrap: wrap; }
@media (max-width: 575.98px) {
    .field-row { align-items: flex-start; }
    .field-row .field-label { flex-basis: 100px; max-width: 100px; font-size: 0.78rem; padding-top: 0.45rem; }
}

/* ---- toast ---- */
.toast { border-radius: 14px !important; font-size: 0.85rem; font-weight: 600; }

/* ---- amber alert ---- */
.alert-fb { background: #fdf1e0; border: 1px solid #f1cf8e; color: #7a5417; border-radius: 12px; font-size: 0.85rem; }

/* ---------------------------------------------------------------
   Mobile compaction
--------------------------------------------------------------- */
@media (max-width: 767.98px) {
    .page-content .container-fluid { padding: 0 0 1rem; }
    .page-inset { padding: 0 0.8rem; }

    .list-header {
        min-height: 60px !important;
        max-height: 70px !important;
        padding: 0.75rem 1rem !important;
        border-radius: 0 0 16px 16px !important;
        margin-bottom: 1rem !important;
    }
    .list-header h4 { font-size: 0.95rem; margin-bottom: 0; }
    .list-header small { display: none; }

    .card { border-radius: 14px !important; }
    .card-header { padding: 0.65rem 0.85rem; border-radius: 14px 14px 0 0 !important; }
    .card-header .fw-semibold { font-size: 0.82rem; }
    .card-header .input-group { max-width: 100% !important; width: 100%; }

    .total-strip { font-size: 0.76rem; padding: 0.4rem 0.9rem; }

    .user-row { padding: 0.6rem 0.85rem; gap: 0.55rem; }
    .user-avatar { width: 38px; height: 38px; }
    .user-row .ur-name { font-size: 0.85rem; }
    .ur-actions .btn { width: 28px; height: 28px; font-size: 0.75rem; }

    .card-footer { padding: 0.5rem 0.85rem; border-radius: 0 0 14px 14px !important; }
    .card-footer small { font-size: 0.7rem; }
    .pagination-sm .page-link { padding: 0.25rem 0.5rem; font-size: 0.73rem; }

    .modal-content { border-radius: 16px; }
    .modal-body { padding: 1rem; }
    .modal-footer { padding: 0.75rem 1rem; }
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid px-0">

    <!-- ================================================================
         PAGE HEADER — full width, flush to viewport edges, no top/side margin
    ================================================================ -->
    <div class="list-header mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4 class="mb-0">
                <i class="bi bi-person-gear me-2"></i>
                <span class="d-none d-md-inline">ইউজার ম্যানেজমেন্ট</span>
                <span class="d-md-none">ইউজারগণ</span>
            </h4>
            <small>FineBullion Desk</small>
        </div>
        <div class="d-none d-md-flex gap-2">
            <a href="customers.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>কাস্টমারসমূহ</a>
            <button type="button" class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#userModal" id="btnAddUser">
                <i class="bi bi-plus-lg me-1"></i> ইউজার যোগ করুন
            </button>
        </div>
        <button type="button" class="btn btn-gold btn-sm d-md-none" data-bs-toggle="modal" data-bs-target="#userModal" id="btnAddUserMobile">
            <i class="bi bi-plus-lg me-1"></i><span>যোগ করুন</span>
        </button>
    </div>

    <div class="page-inset">

    <div class="card shadow-sm">
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
                    <thead class="table-light">
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
          <div id="formAlert" class="alert alert-danger d-none"></div>
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
                <small class="text-muted d-block" id="pwOptional" style="display:none;">(পরিবর্তন না করতে চাইলে ফাঁকা রাখুন)</small>
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
          <button type="submit" class="btn btn-gold" id="saveBtn">
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
        <h5 id="viewUsername" class="mb-1">-</h5>
        <div id="viewRoleBadge" class="mb-3">-</div>
        <table class="table table-borderless table-sm text-start">
          <tr><th style="width:120px;">তৈরির তারিখ</th><td id="viewCreated">-</td></tr>
          <tr><th>সর্বশেষ আপডেট</th><td id="viewUpdated">-</td></tr>
        </table>
      </div>
      <div class="modal-footer">
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
            '<circle cx="12" cy="12" r="12" fill="%23eaf5ee"/>' +
            '<circle cx="12" cy="9" r="4" fill="%230B412A" fill-opacity="0.55"/>' +
            '<path d="M4 20c0-4 4-6 8-6s8 2 8 6" fill="%230B412A" fill-opacity="0.55"/></svg>'
        );
    }

    function photoUrl(p) { return p || avatarSvg(); }

    function fmtDate(s) {
        if (!s) return '-';
        const d = new Date(s.replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
    }

    function roleBadge(role) {
        if (role === 'admin')    return '<span class="badge-admin">Admin</span>';
        if (role === 'employee') return '<span class="badge-employee">Employee</span>';
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

    // ── Mobile card-row list (matches customer list style) ──────────────
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