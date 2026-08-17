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
        if ($id <= 0) json_out(['success' => false, 'message' => 'Invalid ID.'], 400);

        $stmt = mysqli_prepare($conn,
            "SELECT c.id, c.name, c.phone, c.address, c.email, c.nid, c.note, c.photo_path,
                    c.created_at, c.updated_at, u.username AS created_by_username
             FROM customers c
             LEFT JOIN users u ON u.id = c.created_by
             WHERE c.id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$row) json_out(['success' => false, 'message' => 'Customer not found.'], 404);
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
            json_out(['success' => false, 'message' => 'Name and phone are required.'], 422);
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
            if (!$existing) json_out(['success' => false, 'message' => 'Customer not found.'], 404);

            $finalPhoto = $photoPath ?? $existing['photo_path'];

            $stmt = mysqli_prepare($conn,
                "UPDATE customers SET name=?, phone=?, address=?, email=?, nid=?, note=?, photo_path=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'sssssssi', $name, $phone, $address, $email, $nid, $note, $finalPhoto, $id);
            mysqli_stmt_execute($stmt);

            if ($photoPath && $existing['photo_path']) {
                delete_customer_photo($existing['photo_path']);
            }

            json_out(['success' => true, 'message' => 'Customer updated.', 'id' => $id]);
        } else {
            $userId = $currentUser['id'];
            $stmt   = mysqli_prepare($conn,
                "INSERT INTO customers (name, phone, address, email, nid, note, photo_path, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'sssssssi', $name, $phone, $address, $email, $nid, $note, $photoPath, $userId);
            mysqli_stmt_execute($stmt);
            $newId = (int)mysqli_insert_id($conn);

            json_out(['success' => true, 'message' => 'Customer added.', 'id' => $newId]);
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
<title>Customer Management — FineBullion Desk</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
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

/* ---- gold accent button (matches gold_exchange list) ---- */
.btn-gold { background: var(--fb-gold); border-color: var(--fb-gold); color: #1a1a1a; font-weight: 600; }
.btn-gold:hover { background: #c99a2f; border-color: #c99a2f; color: #1a1a1a; }

/* ---- total count strip ---- */
.total-strip {
    background: #eaf5ee;
    color: var(--fb-green);
    font-weight: 600;
    font-size: 0.85rem;
    padding: 0.55rem 1rem;
    border-bottom: 1px solid #e1ece5;
}

/* ---- customer avatar ---- */
.customer-avatar {
    width: 44px; height: 44px; object-fit: cover; border-radius: 50%;
    border: 2px solid #eaf5ee; flex-shrink: 0;
}
.view-photo { width: 130px; height: 130px; object-fit: cover; border-radius: 10px; border: 1px solid #dee2e6; }
.photo-preview { width: 110px; height: 110px; object-fit: cover; border-radius: 8px; border: 1px solid #dee2e6; }

/* ---- customer list row (card-like, image-1 style) ---- */
.customer-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.7rem 1rem;
    border-bottom: 1px solid #eef0f3;
    text-decoration: none;
    color: inherit;
    transition: background 0.12s ease;
}
.customer-row:last-child { border-bottom: none; }
.customer-row:hover { background: #f8faf9; }

.customer-row .cr-info { flex: 1; min-width: 0; }
.customer-row .cr-name { font-weight: 600; font-size: 0.92rem; color: #1a1a1a; margin-bottom: 1px; }
.customer-row .cr-phone { font-size: 0.8rem; color: #6c757d; }
.customer-row .cr-address { font-size: 0.78rem; color: #8a8f98; }

.customer-row .cr-meta { text-align: right; flex-shrink: 0; }
.customer-row .cr-date { font-size: 0.72rem; color: #9aa0a6; white-space: nowrap; }

.cr-actions { display: flex; gap: 4px; flex-shrink: 0; }
.cr-actions .btn {
    width: 32px; height: 32px; padding: 0; display: inline-flex;
    align-items: center; justify-content: center; border-radius: 7px; font-size: 0.85rem;
}

/* ---- desktop table (kept for md+ screens) ---- */
.customer-photo-thumb { width:42px; height:42px; object-fit:cover; border-radius:50%; border:1px solid #dee2e6; }

/* ---- search bar ---- */
.search-wrap .input-group-text { background: #fff; border-right: 0; }
.search-wrap .form-control { border-left: 0; }
.search-wrap .form-control:focus { box-shadow: none; border-color: #ced4da; }

/* ---- modal form styling ---- */
.modal-header.fb-modal-header { background: var(--fb-green); color: #fff; }
.modal-header.fb-modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
.form-label .text-danger { font-weight: 700; }
.form-control:focus { border-color: var(--fb-gold); box-shadow: 0 0 0 0.2rem rgba(220,173,65,0.18); }

/* ---- label-left / input-right field rows (add & edit customer) ---- */
.field-row { display: flex; align-items: center; gap: 0.75rem; }
.field-row .field-label { flex: 0 0 130px; max-width: 130px; margin-bottom: 0; padding-right: 0.25rem; }
.field-row .field-input { flex: 1 1 auto; min-width: 0; }
.field-row .field-input .d-flex { flex-wrap: wrap; }
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

    .card { border-radius: 10px; }
    .card-header { padding: 0.5rem 0.6rem; }
    .card-header .fw-semibold { font-size: 0.82rem; }
    .card-header .input-group { max-width: 100% !important; width: 100%; }

    .total-strip { font-size: 0.78rem; padding: 0.45rem 0.85rem; }

    .customer-row { padding: 0.6rem 0.75rem; gap: 0.6rem; }
    .customer-avatar { width: 40px; height: 40px; }
    .customer-row .cr-name { font-size: 0.86rem; }
    .customer-row .cr-phone, .customer-row .cr-address { font-size: 0.74rem; }
    .cr-actions .btn { width: 28px; height: 28px; font-size: 0.75rem; }

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
                <i class="bi bi-people-fill me-2"></i>
                <span class="d-none d-md-inline">Customer Management</span>
                <span class="d-md-none">Customer</span>
            </h4>
            <small>FineBullion Desk</small>
        </div>
        <div class="d-none d-md-flex gap-2">
            <?php if (is_admin()): ?>
            <a href="users.php" class="btn btn-outline-light btn-sm"><i class="bi bi-person-gear me-1"></i>Users</a>
            <?php endif; ?>
            <button type="button" class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#customerModal" id="btnAddCustomer">
                <i class="bi bi-plus-lg me-1"></i> Add Customer
            </button>
        </div>
        <button type="button" class="btn btn-gold btn-sm d-md-none" data-bs-toggle="modal" data-bs-target="#customerModal" id="btnAddCustomerMobile">
            <i class="bi bi-plus-lg me-1"></i><span>Add</span>
        </button>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-list-ul me-1"></i> Customer List</span>
            <div class="input-group search-wrap" style="max-width:300px;">
                <span class="input-group-text"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="searchInput" class="form-control" placeholder="Search by name or phone">
                <button class="btn btn-outline-secondary" id="clearSearchBtn"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>

        <div class="total-strip">
            Total : <span id="totalCount">0</span> customer
        </div>

        <div class="card-body p-0">

            <!-- ============ MOBILE LIST (card rows) ============ -->
            <div id="customerListMobile" class="d-md-none">
                <div class="text-center py-4 text-muted">
                    <span class="spinner-border spinner-border-sm me-2"></span>Loading…
                </div>
            </div>

            <!-- ============ DESKTOP TABLE ============ -->
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:55px;">Photo</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>Email</th>
                            <th>NID</th>
                            <th style="width:130px;">Added</th>
                            <th style="width:130px;" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="customersTableBody">
                        <tr><td colspan="8" class="text-center py-4 text-muted">Loading…</td></tr>
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
<div class="modal fade" id="customerModal" tabindex="-1" aria-labelledby="customerModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="customerForm" enctype="multipart/form-data">
        <div class="modal-header fb-modal-header">
          <h5 class="modal-title" id="customerModalLabel"><i class="bi bi-person-plus-fill me-1"></i> Add Customer</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="formAlert" class="alert alert-danger d-none"></div>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="customerId" value="0">
          <div class="row g-3">
            <div class="col-12 field-row">
              <label class="form-label field-label">Name <span class="text-danger">*</span></label>
              <div class="field-input">
                <input type="text" class="form-control" id="name" name="name" maxlength="150">
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">Phone <span class="text-danger">*</span></label>
              <div class="field-input">
                <input type="text" class="form-control" id="phone" name="phone" maxlength="20">
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">Address <span class="text-danger">*</span></label>
              <div class="field-input">
                <input type="text" class="form-control" id="address" name="address" maxlength="255">
              </div>
            </div>
            <div class="col-md-6 field-row">
              <label class="form-label field-label">Nid</label>
              <div class="field-input">
                <input type="text" class="form-control" id="nid" name="nid" maxlength="30">
              </div>
            </div>
            <div class="col-md-6 field-row">
              <label class="form-label field-label">Email</label>
              <div class="field-input">
                <input type="email" class="form-control" id="email" name="email" maxlength="150">
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">Note</label>
              <div class="field-input">
                <textarea class="form-control" id="note" name="note" rows="2"></textarea>
              </div>
            </div>
            <div class="col-12 field-row">
              <label class="form-label field-label">Photo</label>
              <div class="field-input d-flex align-items-center gap-3">
                <input type="file" class="form-control" id="photo" name="photo" accept="image/*">
                <img id="photoPreview" src="" alt="Preview" class="photo-preview d-none">
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-gold" id="saveCustomerBtn">
            <span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span>
            <i class="bi bi-check-lg me-1"></i> Add Customer
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
        <h5 class="modal-title"><i class="bi bi-person-lines-fill me-1"></i> Customer Details</h5>
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
                <tr><th style="width:130px;">Name</th><td id="viewName">-</td></tr>
                <tr><th>Phone</th><td id="viewPhone">-</td></tr>
                <tr><th>Address</th><td id="viewAddress">-</td></tr>
                <tr><th>Email</th><td id="viewEmail">-</td></tr>
                <tr><th>NID</th><td id="viewNid">-</td></tr>
                <tr><th>Note</th><td id="viewNote">-</td></tr>
                <tr><th>Added By</th><td id="viewCreatedBy">-</td></tr>
                <tr><th>Added On</th><td id="viewCreatedAt">-</td></tr>
                <tr><th>Last Updated</th><td id="viewUpdatedAt">-</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <a href="#" class="btn btn-outline-success" id="historyFromViewBtn"><i class="bi bi-clock-history me-1"></i> History</a>
        <button type="button" class="btn btn-gold" id="editFromViewBtn"><i class="bi bi-pencil-fill me-1"></i> Edit</button>
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

    // ── Load list ──────────────────────────────────────────────────────
    function loadCustomers(page) {
        state.page = page || 1;

        const mobileList = document.getElementById('customerListMobile');
        const tbody = document.getElementById('customersTableBody');
        mobileList.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">' +
            '<span class="spinner-border spinner-border-sm me-2"></span>Loading…</td></tr>';

        const params = new URLSearchParams({ action: 'list', page: state.page, search: state.search });
        fetch('customers.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-danger">Failed to load.</td></tr>';
                    mobileList.innerHTML = '<div class="text-center py-4 text-danger">Failed to load.</div>';
                    return;
                }
                document.getElementById('totalCount').textContent = res.totalRows;
                renderTable(res.data);
                renderMobileList(res.data);
                renderPagination(res.page, res.totalPages, res.totalRows, res.data.length);
            })
            .catch(() => {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-danger">Network error.</td></tr>';
                mobileList.innerHTML = '<div class="text-center py-4 text-danger">Network error.</div>';
            });
    }

    // ── Desktop table ─────────────────────────────────────────────────
    function renderTable(rows) {
        const tbody = document.getElementById('customersTableBody');
        if (!rows || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">No customers found.</td></tr>';
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
                    <button class="btn btn-sm btn-outline-secondary me-1 btn-view" data-id="${c.id}" title="View"><i class="bi bi-eye-fill"></i></button>
                    <button class="btn btn-sm btn-outline-primary me-1 btn-edit" data-id="${c.id}" title="Edit"><i class="bi bi-pencil-fill"></i></button>
                    <a href="customer_history.php?customer_id=${c.id}" class="btn btn-sm btn-outline-success" title="History"><i class="bi bi-clock-history"></i></a>
                </td>
            </tr>`).join('');
    }

    // ── Mobile card-row list (image-1 style) ────────────────────────────
    function renderMobileList(rows) {
        const wrap = document.getElementById('customerListMobile');
        if (!rows || !rows.length) {
            wrap.innerHTML = '<div class="text-center py-4 text-muted">No customers found.</div>';
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
                        <button class="btn btn-outline-secondary btn-view" data-id="${c.id}" title="View"><i class="bi bi-eye-fill"></i></button>
                        <button class="btn btn-outline-primary btn-edit" data-id="${c.id}" title="Edit"><i class="bi bi-pencil-fill"></i></button>
                        <a href="customer_history.php?customer_id=${c.id}" class="btn btn-outline-success" title="History"><i class="bi bi-clock-history"></i></a>
                    </div>
                </div>
            </div>`).join('');
    }

    function renderPagination(page, totalPages, totalRows, onPage) {
        const info  = document.getElementById('paginationInfo');
        const ctrl  = document.getElementById('paginationControls');
        const start = totalRows === 0 ? 0 : (page - 1) * 20 + 1;
        const end   = (page - 1) * 20 + onPage;
        info.textContent = `Showing ${start}–${end} of ${totalRows} customers`;
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
        document.getElementById('customerModalLabel').innerHTML = '<i class="bi bi-person-plus-fill me-1"></i> Add Customer';
        document.getElementById('saveCustomerBtn').innerHTML =
            '<span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span><i class="bi bi-check-lg me-1"></i> Add Customer';
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
                if (!res.success) { showToast(res.message || 'Failed to load.', true); return; }
                resetForm();
                const c = res.data;
                document.getElementById('customerModalLabel').innerHTML = '<i class="bi bi-pencil-fill me-1"></i> Edit Customer';
                document.getElementById('saveCustomerBtn').innerHTML =
                    '<span class="spinner-border spinner-border-sm d-none me-1" id="saveSpinner"></span><i class="bi bi-check-lg me-1"></i> Save Customer';
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
            .catch(() => showToast('Network error.', true));
    }

    function openView(id) {
        fetch('customers.php?action=get&id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { showToast(res.message || 'Failed to load.', true); return; }
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
            .catch(() => showToast('Network error.', true));
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
                showToast(res.message || 'Saved.', false);
                loadCustomers(state.page);
            } else {
                alert.textContent = res.message || 'Failed to save.';
                alert.classList.remove('d-none');
            }
        })
        .catch(() => {
            alert.textContent = 'Network error. Please try again.';
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