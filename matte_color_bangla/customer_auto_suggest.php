<?php
/**
 * customer_auto_suggest.php
 * FineBullion Desk — Centralized customer autocomplete + quick "add new customer"
 *
 * ONE file, TWO roles:
 *
 *  1) AJAX API (called directly by the browser)
 *       GET  customer_auto_suggest.php?action=search&q=Chayan[&limit=10]
 *       POST customer_auto_suggest.php?action=add   (name, phone, address)
 *
 *  2) Include file (used by gold_sale.php / gold_buy.php / gold_exchange.php)
 *       require_once __DIR__ . '/customer_auto_suggest.php';
 *       ...
 *       <?php customer_suggest_render(); ?>     // inside the customer card body
 *
 * The widget keeps the same element ids the pages already use
 * (#customerId, #customerSearch, ...) so each page's save code that reads
 * #customerId keeps working unchanged.
 */

require_once __DIR__ . '/auth.php';

// =======================================================================
// Include mode: only define the render function, then stop.
// API mode (file requested directly): handled below.
// =======================================================================
$__csIsDirect = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);

if (!function_exists('customer_suggest_render')) {

    /**
     * Prints the search box, selected-customer card, "add new customer"
     * modal, CSS and JS. Call it once per page, inside the customer card body.
     *
     * @param array $opts  placeholder (string), minChars (int)
     */
    function customer_suggest_render(array $opts = []): void
    {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;

        $placeholder = $opts['placeholder'] ?? 'নাম বা ফোন নম্বর দিয়ে খুঁজুন';
        $minChars    = max(1, (int)($opts['minChars'] ?? 2));
        $endpoint    = basename(__FILE__);
        ?>
<style>
/* ---- Customer auto-suggest (shared) ---- */
.cs-results {
    position: absolute; z-index: 30; width: 100%;
    background: #fff; border: 1.5px solid var(--border-default, #C8D9E6);
    border-radius: 8px; box-shadow: var(--shadow, 0 2px 8px rgba(47,65,86,.08));
    max-height: 300px; overflow-y: auto; display: none;
}
.cs-item {
    cursor: pointer; padding: .55rem .9rem;
    border-bottom: 1px solid var(--border-default, #C8D9E6);
}
.cs-item:hover, .cs-item.active { background: var(--bg-hover, rgba(200,217,230,.35)); }
.cs-avatar {
    width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex-shrink: 0;
    border: 1.5px solid var(--border-default, #C8D9E6);
}
.cs-avatar-fb {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    background: var(--navy, #2F4156); color: #fff; font-size: .8rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}
.cs-add-row {
    cursor: pointer; padding: .6rem .9rem; font-weight: 600;
    color: var(--teal, #567C8D); background: var(--beige, #F5EFEB);
    display: flex; align-items: center; gap: .5rem;
}
.cs-add-row:hover { background: var(--bg-hover, rgba(200,217,230,.35)); }
.cs-msg { padding: .6rem .9rem; font-size: .85rem; color: var(--teal, #567C8D); }
.cs-msg.err { color: var(--danger, #A6434B); }
.cs-selected {
    border: 1.5px solid var(--teal, #567C8D); background: var(--sky, #C8D9E6);
    border-radius: 8px; padding: .75rem 1rem; display: none;
    align-items: center; justify-content: space-between; gap: .75rem;
}
.cs-selected .cs-new-badge {
    font-size: .7rem; background: var(--success, #3D7A5C); color: #fff;
    border-radius: 999px; padding: .1rem .5rem; margin-left: .4rem; vertical-align: middle;
}
.cs-clear {
    border: 0; background: transparent; color: var(--danger, #A6434B);
    font-size: 1.05rem; line-height: 1; padding: .35rem .5rem; border-radius: 6px; cursor: pointer;
}
.cs-clear:hover { background: rgba(166,67,75,.1); }

/* modal (no Bootstrap JS needed) */
.cs-backdrop {
    position: fixed; inset: 0; background: rgba(47,65,86,.45); z-index: 2000;
    display: none; align-items: center; justify-content: center; padding: 1rem;
}
.cs-backdrop.open { display: flex; }
.cs-modal {
    background: #fff; border-radius: 12px; width: 100%; max-width: 440px;
    box-shadow: 0 10px 30px rgba(47,65,86,.25); overflow: hidden;
}
.cs-modal-head {
    padding: .9rem 1.1rem; font-weight: 700; color: #fff; background: var(--navy, #2F4156);
    display: flex; justify-content: space-between; align-items: center;
}
.cs-modal-head button { background: none; border: 0; color: #fff; font-size: 1.2rem; cursor: pointer; }
.cs-modal-body { padding: 1.1rem; }
.cs-modal-body label { font-size: .85rem; font-weight: 600; margin-bottom: .25rem; display: block; }
.cs-modal-foot { padding: .8rem 1.1rem; display: flex; justify-content: flex-end; gap: .5rem; background: var(--beige, #F5EFEB); }
.cs-form-error { color: var(--danger, #A6434B); font-size: .85rem; margin-top: .5rem; display: none; }
</style>

<div class="position-relative">
    <input type="text" class="form-control" id="customerSearch" autocomplete="off"
           placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') ?>">
    <div class="cs-results" id="customerResults"></div>
</div>
<input type="hidden" id="customerId" name="customer_id">

<div class="cs-selected mt-3" id="selectedCustomerCard">
    <div>
        <div class="fw-semibold"><span id="selectedCustomerName">—</span><span class="cs-new-badge" id="selectedCustomerNew" style="display:none">নতুন</span></div>
        <small class="text-secondary" id="selectedCustomerPhone">—</small>
    </div>
    <button type="button" class="cs-clear" id="btnClearCustomer" title="মুছে ফেলুন">✕</button>
</div>

<!-- Quick add customer modal -->
<div class="cs-backdrop" id="csModalBackdrop" aria-hidden="true">
    <div class="cs-modal" role="dialog" aria-modal="true" aria-labelledby="csModalTitle">
        <div class="cs-modal-head">
            <span id="csModalTitle">নতুন কাস্টমার যোগ করুন</span>
            <button type="button" id="csModalClose" aria-label="বন্ধ করুন">✕</button>
        </div>
        <div class="cs-modal-body">
            <div class="mb-3">
                <label for="csNewName">নাম <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="csNewName" maxlength="150" autocomplete="off">
            </div>
            <div class="mb-3">
                <label for="csNewPhone">ফোন নম্বর <span class="text-danger">*</span></label>
                <input type="tel" class="form-control" id="csNewPhone" maxlength="20" autocomplete="off">
            </div>
            <div class="mb-1">
                <label for="csNewAddress">ঠিকানা (ঐচ্ছিক)</label>
                <input type="text" class="form-control" id="csNewAddress" maxlength="255" autocomplete="off">
            </div>
            <div class="cs-form-error" id="csFormError"></div>
        </div>
        <div class="cs-modal-foot">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="csModalCancel">বাতিল</button>
            <button type="button" class="btn btn-sm text-white" style="background:var(--navy,#2F4156)" id="csModalSave">সংরক্ষণ করুন</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const ENDPOINT  = <?= json_encode($endpoint) ?>;
    const MIN_CHARS = <?= (int)$minChars ?>;

    const $search   = document.getElementById('customerSearch');
    const $results  = document.getElementById('customerResults');
    const $idInput  = document.getElementById('customerId');
    const $card     = document.getElementById('selectedCustomerCard');
    const $name     = document.getElementById('selectedCustomerName');
    const $phone    = document.getElementById('selectedCustomerPhone');
    const $newBadge = document.getElementById('selectedCustomerNew');
    const $clearBtn = document.getElementById('btnClearCustomer');

    const $backdrop = document.getElementById('csModalBackdrop');
    const $nName    = document.getElementById('csNewName');
    const $nPhone   = document.getElementById('csNewPhone');
    const $nAddr    = document.getElementById('csNewAddress');
    const $nErr     = document.getElementById('csFormError');
    const $nSave    = document.getElementById('csModalSave');

    let timer = null;
    let seq   = 0;        // ignore out-of-order responses
    let lastQuery = '';
    let saving = false;

    function esc(s) {
        return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
                              .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ---------- selection ---------- */
    function select(c, isNew) {
        $idInput.value      = c.id;
        $name.textContent   = c.name;
        $phone.textContent  = c.phone || '';
        $newBadge.style.display = isNew ? 'inline-block' : 'none';
        $card.style.display = 'flex';
        $search.value = '';
        hideResults();
        document.dispatchEvent(new CustomEvent('customer:selected', { detail: c }));
    }
    function clear() {
        $idInput.value = '';
        $card.style.display = 'none';
        document.dispatchEvent(new CustomEvent('customer:cleared'));
    }
    function hideResults() { $results.style.display = 'none'; $results.innerHTML = ''; }

    /* ---------- search ---------- */
    function addRowHtml() {
        return '<div class="cs-add-row" data-act="add">＋ নতুন কাস্টমার যোগ করুন</div>';
    }

    $search.addEventListener('input', function () {
        const q = this.value.trim();
        lastQuery = q;
        clearTimeout(timer);
        if (q.length < MIN_CHARS) { hideResults(); return; }

        timer = setTimeout(async () => {
            const my = ++seq;
            try {
                const res  = await fetch(ENDPOINT + '?action=search&q=' + encodeURIComponent(q),
                                         { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                if (my !== seq) return;               // stale response
                if (!data.success) throw new Error(data.message || 'fail');

                const list = data.data || [];
                if (list.length === 0) {
                    $results.innerHTML = '<div class="cs-msg">কোনো কাস্টমার পাওয়া যায়নি।</div>' + addRowHtml();
                } else {
                    $results.innerHTML = list.map(c => `
                        <div class="cs-item d-flex align-items-center gap-2" data-act="pick"
                             data-id="${c.id}" data-name="${esc(c.name)}" data-phone="${esc(c.phone)}">
                            ${c.photo_path
                                ? `<img src="${esc(c.photo_path)}" class="cs-avatar" alt="">`
                                : `<div class="cs-avatar-fb">${esc((c.name || '?').charAt(0).toUpperCase())}</div>`}
                            <div class="flex-grow-1">
                                <div class="fw-semibold">${esc(c.name)}</div>
                                <small class="text-secondary">${esc(c.phone)}${c.address ? ' · ' + esc(c.address) : ''}</small>
                            </div>
                        </div>`).join('') + addRowHtml();
                }
                $results.style.display = 'block';
            } catch (err) {
                if (my !== seq) return;
                $results.innerHTML = '<div class="cs-msg err">অনুসন্ধান ব্যর্থ হয়েছে।</div>';
                $results.style.display = 'block';
            }
        }, 300);
    });

    $search.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') hideResults();
    });

    $results.addEventListener('click', function (e) {
        const el = e.target.closest('[data-act]');
        if (!el) return;
        if (el.dataset.act === 'pick') {
            select({ id: el.dataset.id, name: el.dataset.name, phone: el.dataset.phone }, false);
        } else if (el.dataset.act === 'add') {
            openModal(lastQuery);
        }
    });

    $clearBtn.addEventListener('click', clear);

    document.addEventListener('click', function (e) {
        if (!e.target.closest('#customerSearch') && !e.target.closest('#customerResults')) {
            $results.style.display = 'none';
        }
    });

    /* ---------- add-new modal ---------- */
    function openModal(prefill) {
        const looksLikePhone = /^[\d+\-\s()]+$/.test(prefill || '');
        $nName.value  = looksLikePhone ? '' : (prefill || '');
        $nPhone.value = looksLikePhone ? (prefill || '') : '';
        $nAddr.value  = '';
        $nErr.style.display = 'none';
        $backdrop.classList.add('open');
        $backdrop.setAttribute('aria-hidden', 'false');
        hideResults();
        setTimeout(() => (looksLikePhone ? $nName : ($nPhone.value ? $nSave : $nPhone)).focus(), 50);
    }
    function closeModal() {
        $backdrop.classList.remove('open');
        $backdrop.setAttribute('aria-hidden', 'true');
    }
    function showErr(msg) { $nErr.textContent = msg; $nErr.style.display = 'block'; }

    async function saveNew() {
        if (saving) return;
        const name  = $nName.value.trim();
        const phone = $nPhone.value.trim();
        if (!name || !phone) { showErr('নাম এবং ফোন নম্বর আবশ্যক।'); return; }
        if (!/^[0-9+\-\s()]{6,20}$/.test(phone)) { showErr('ফোন নম্বর সঠিক নয়।'); return; }

        saving = true; $nSave.disabled = true; $nErr.style.display = 'none';
        try {
            const fd = new FormData();
            fd.append('name', name);
            fd.append('phone', phone);
            fd.append('address', $nAddr.value.trim());

            const res  = await fetch(ENDPOINT + '?action=add', {
                method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            if (!data.success) { showErr(data.message || 'সংরক্ষণ ব্যর্থ হয়েছে।'); return; }

            closeModal();
            select(data.customer, !data.exists);   // auto-choose for this sale/buy/exchange
        } catch (err) {
            showErr('সংরক্ষণ ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
        } finally {
            saving = false; $nSave.disabled = false;
        }
    }

    document.getElementById('csModalSave').addEventListener('click', saveNew);
    document.getElementById('csModalCancel').addEventListener('click', closeModal);
    document.getElementById('csModalClose').addEventListener('click', closeModal);
    $backdrop.addEventListener('mousedown', e => { if (e.target === $backdrop) closeModal(); });
    document.addEventListener('keydown', e => {
        if (!$backdrop.classList.contains('open')) return;
        if (e.key === 'Escape') closeModal();
        if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); saveNew(); }
    });

    // Small public API for the host page
    window.CustomerSuggest = {
        getId: () => $idInput.value,
        select: select,
        clear: clear
    };
})();
</script>
        <?php
    }
}

// =======================================================================
// Include mode ends here.
// =======================================================================
if (!$__csIsDirect) {
    return;
}

// =======================================================================
// API mode
// =======================================================================
header('Content-Type: application/json; charset=utf-8');

function cs_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'search';

try {

    // ---------------- SEARCH ----------------
    if ($action === 'search' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $query = trim($_GET['q'] ?? '');
        if ($query === '') {
            cs_json(['success' => true, 'count' => 0, 'data' => []]);
        }

        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        if ($limit <= 0 || $limit > 25) $limit = 10;

        $like   = '%' . $query . '%';
        $starts = $query . '%';

        $sql = "SELECT id, name, phone, address, photo_path
                FROM customers
                WHERE name LIKE ? OR phone LIKE ?
                ORDER BY
                    CASE
                        WHEN phone = ? THEN 0
                        WHEN name  = ? THEN 0
                        WHEN phone LIKE ? THEN 1
                        WHEN name  LIKE ? THEN 1
                        ELSE 2
                    END,
                    name ASC
                LIMIT ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ssssssi', $like, $like, $query, $query, $starts, $starts, $limit);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = [
                'id'         => (int)$row['id'],
                'name'       => $row['name'],
                'phone'      => $row['phone'],
                'address'    => $row['address'],
                'photo_path' => $row['photo_path'],
            ];
        }
        cs_json(['success' => true, 'count' => count($data), 'data' => $data]);
    }

    // ---------------- ADD (quick add) ----------------
    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '') ?: null;

        if ($name === '' || $phone === '') {
            cs_json(['success' => false, 'message' => 'নাম এবং ফোন নম্বর আবশ্যক।'], 422);
        }
        if (!preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) {
            cs_json(['success' => false, 'message' => 'ফোন নম্বর সঠিক নয়।'], 422);
        }
        if (mb_strlen($name) > 150) {
            cs_json(['success' => false, 'message' => 'নাম অনেক বড়।'], 422);
        }

        // Same phone already exists? -> reuse that customer instead of duplicating.
        $dup = mysqli_prepare($conn, "SELECT id, name, phone, address FROM customers WHERE phone = ? LIMIT 1");
        mysqli_stmt_bind_param($dup, 's', $phone);
        mysqli_stmt_execute($dup);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($dup));
        if ($existing) {
            cs_json([
                'success'  => true,
                'exists'   => true,
                'message'  => 'এই ফোন নম্বরে কাস্টমার আগে থেকেই আছে, তাকেই নির্বাচন করা হয়েছে।',
                'customer' => [
                    'id'      => (int)$existing['id'],
                    'name'    => $existing['name'],
                    'phone'   => $existing['phone'],
                    'address' => $existing['address'],
                ],
            ]);
        }

        $userId = $currentUser['id'];
        $stmt = mysqli_prepare($conn,
            "INSERT INTO customers (name, phone, address, created_by) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'sssi', $name, $phone, $address, $userId);
        mysqli_stmt_execute($stmt);
        $newId = (int)mysqli_insert_id($conn);

        cs_json([
            'success'  => true,
            'exists'   => false,
            'message'  => 'কাস্টমার যোগ করা হয়েছে।',
            'customer' => [
                'id'      => $newId,
                'name'    => $name,
                'phone'   => $phone,
                'address' => $address,
            ],
        ]);
    }

    cs_json(['success' => false, 'message' => 'অজানা অ্যাকশন।'], 400);

} catch (Throwable $e) {
    cs_json(['success' => false, 'message' => 'সার্ভার ত্রুটি।'], 500);
}
