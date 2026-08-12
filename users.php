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
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Management — FineBullion Desk</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
body { background: #f4f6f5; font-family: "Segoe UI", system-ui, sans-serif; font-size: 0.92rem; }
.user-thumb  { width:40px; height:40px; object-fit:cover; border-radius:50%; border:2px solid #e4e9e6; }
.user-avatar-lg { width:90px; height:90px; object-fit:cover; border-radius:50%; border:2px solid #dee2e6; }
.photo-preview-sm { width:80px; height:80px; object-fit:cover; border-radius:8px; border:1px solid #dee2e6; }
.badge-admin    { background:#0B412A; color:#fff; font-size:.72rem; border-radius:20px; padding:.28em .75em; }
.badge-employee { background:#6c757d; color:#fff; font-size:.72rem; border-radius:20px; padding:.28em .75em; }
</style>
</head>
<body>

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="page-content">
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0"><i class="bi bi-person-gear me-2"></i>User Management</h4>
            <small class="text-muted">FineBullion Desk</small>
        </div>
        <div class="d-flex gap-2">
            <a href="customers.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Customers</a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" id="btnAddUser">
                <i class="bi bi-person-plus-fill me-1"></i> Add User
            </button>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-list-ul me-1"></i> Users</span>
            <div class="input-group" style="max-width:260px;">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" id="searchInput" class="form-control" placeholder="Search username…">
                <button class="btn btn-outline-secondary" id="clearSearchBtn"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:55px;">Photo</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th style="width:140px;">Created</th>
                            <th style="width:100px;" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
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
<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="userForm" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title" id="userModalLabel"><i class="bi bi-person-plus-fill me-1"></i> Add User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="formAlert" class="alert alert-danger d-none"></div>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="userId" value="0">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Username <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="username" name="username" maxlength="100">
            </div>
            <div class="col-12">
              <label class="form-label">Role <span class="text-danger">*</span></label>
              <select class="form-select" id="role" name="role">
                <option value="">Select role…</option>
                <option value="admin">Admin</option>
                <option value="employee">Employee</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">
                Password <span class="text-danger" id="pwRequired">*</span>
                <small class="text-muted" id="pwOptional" style="display:none;">(leave blank to keep current)</small>
              </label>
              <div class="input-group">
                <input type="password" class="form-control" id="password" name="password">
                <button type="button" class="btn btn-outline-secondary" id="togglePwBtn"><i class="bi bi-eye" id="togglePwIco"></i></button>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">
                Confirm Password <span class="text-danger" id="cfRequired">*</span>
              </label>
              <input type="password" class="form-control" id="confirm_password" name="confirm_password">
            </div>
            <div class="col-md-7">
              <label class="form-label">Profile Photo</label>
              <input type="file" class="form-control" id="photoInput" name="photo" accept="image/*">
            </div>
            <div class="col-md-5 d-flex align-items-end">
              <img id="photoPreview" src="" alt="Preview" class="photo-preview-sm d-none">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="saveBtn">
            <span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span>
            <span id="saveLabel"><i class="bi bi-check-lg me-1"></i> Save User</span>
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
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-lines-fill me-1"></i> User Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <img id="viewPhoto" src="" alt="Photo" class="user-avatar-lg mb-3">
        <h5 id="viewUsername" class="mb-1">-</h5>
        <div id="viewRoleBadge" class="mb-3">-</div>
        <table class="table table-borderless table-sm text-start">
          <tr><th style="width:120px;">Created</th><td id="viewCreated">-</td></tr>
          <tr><th>Last Updated</th><td id="viewUpdated">-</td></tr>
        </table>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="editFromViewBtn"><i class="bi bi-pencil-fill me-1"></i> Edit</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
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
    const state = { page: 1, search: '', debounce: null };

    const userModal = new bootstrap.Modal(document.getElementById('userModal'));
    const viewModal = new bootstrap.Modal(document.getElementById('viewUserModal'));
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
            '<circle cx="12" cy="12" r="12" fill="%23e9ecef"/>' +
            '<circle cx="12" cy="9" r="4" fill="%23adb5bd"/>' +
            '<path d="M4 20c0-4 4-6 8-6s8 2 8 6" fill="%23adb5bd"/></svg>'
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
        const tbody = document.getElementById('usersTableBody');
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">' +
            '<span class="spinner-border spinner-border-sm me-2"></span>Loading…</td></tr>';

        const params = new URLSearchParams({ action: 'list', page: state.page, search: state.search });
        fetch('users.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">Failed to load.</td></tr>'; return; }
                renderTable(res.data);
                renderPagination(res.page, res.totalPages, res.totalRows, res.data.length);
            })
            .catch(() => { tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">Network error.</td></tr>'; });
    }

    function renderTable(rows) {
        const tbody = document.getElementById('usersTableBody');
        if (!rows || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No users found.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(u => `
            <tr>
                <td><img src="${esc(photoUrl(u.photo_path))}" class="user-thumb" alt="" onerror="this.src='${avatarSvg()}'"></td>
                <td>${esc(u.username)}</td>
                <td>${roleBadge(u.role)}</td>
                <td><small>${esc(fmtDate(u.created_at))}</small></td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-secondary me-1 btn-view" data-id="${u.id}" title="View"><i class="bi bi-eye-fill"></i></button>
                    <button class="btn btn-sm btn-outline-primary btn-edit" data-id="${u.id}" title="Edit"><i class="bi bi-pencil-fill"></i></button>
                </td>
            </tr>`).join('');
    }

    function renderPagination(page, totalPages, totalRows, onPage) {
        const info = document.getElementById('paginationInfo');
        const ctrl = document.getElementById('paginationControls');
        const start = totalRows === 0 ? 0 : (page - 1) * 10 + 1;
        const end   = (page - 1) * 10 + onPage;
        info.textContent = `Showing ${start}–${end} of ${totalRows} user${totalRows !== 1 ? 's' : ''}`;
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
        document.getElementById('userModalLabel').innerHTML = '<i class="bi bi-person-plus-fill me-1"></i> Add User';
        document.getElementById('pwRequired').style.display  = '';
        document.getElementById('pwOptional').style.display  = 'none';
        document.getElementById('cfRequired').style.display  = '';
    }

    document.getElementById('btnAddUser').addEventListener('click', resetForm);

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
                if (!res.success) { showToast(res.message || 'Failed to load.', true); return; }
                resetForm();
                const u = res.data;
                document.getElementById('userModalLabel').innerHTML = '<i class="bi bi-pencil-fill me-1"></i> Edit User';
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
            .catch(() => showToast('Network error.', true));
    }

    function openView(id) {
        fetch('users.php?action=get&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { showToast(res.message || 'Failed to load.', true); return; }
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
            .catch(() => showToast('Network error.', true));
    }

    document.getElementById('editFromViewBtn').addEventListener('click', () => {
        if (!viewedId) return;
        viewModal.hide();
        setTimeout(() => openEdit(viewedId), 200);
    });

    document.getElementById('usersTableBody').addEventListener('click', e => {
        const vBtn = e.target.closest('.btn-view');
        const eBtn = e.target.closest('.btn-edit');
        if (vBtn) openView(vBtn.dataset.id);
        if (eBtn) openEdit(eBtn.dataset.id);
    });

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
                showToast(res.message || 'Saved.', false);
                loadUsers(state.page);
            } else {
                alertEl.textContent = res.message || 'Failed to save.';
                alertEl.classList.remove('d-none');
            }
        })
        .catch(() => {
            alertEl.textContent = 'Network error. Please try again.';
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