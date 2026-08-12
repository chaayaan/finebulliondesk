<?php
/**
 * auth.php
 * FineBullion Desk — Centralized authentication & authorization
 *
 * Include this ONE file at the very top of every protected page instead of
 * manually calling session_start() + config.php + the login-check block on
 * every file. It will:
 *
 *   1. Start the session (safely — won't double-start if already active)
 *   2. Load config.php (DB connection: $conn)
 *   3. Redirect to login.php if the user isn't authenticated
 *   4. Load the current user's fresh record from the DB into $currentUser
 *   5. Provide require_role() for pages restricted to specific roles
 *
 * USAGE — plain page (any logged-in user):
 *
 *   <?php
 *   require_once __DIR__ . '/auth.php';
 *   ?>
 *   <!DOCTYPE html> ...
 *
 * USAGE — admin-only page (e.g. users.php):
 *
 *   <?php
 *   require_once __DIR__ . '/auth.php';
 *   require_role('admin');
 *   ?>
 *
 * USAGE — AJAX endpoint that should return JSON instead of redirecting:
 *
 *   <?php
 *   require_once __DIR__ . '/auth.php';
 *   require_role('admin', true); // true = respond with JSON 403 instead of redirect
 *
 * After include, these are available:
 *   $conn         mysqli connection (from config.php)
 *   $currentUser  ['id', 'username', 'role', 'photo_path'] array of the logged-in user
 *   is_admin()    bool helper
 *   require_role($role, $isAjax = false)  enforce a specific role
 */

// -----------------------------------------------------------------------
// 1. Session
// -----------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------
// 2. Database connection
// -----------------------------------------------------------------------
require_once __DIR__ . '/config.php';

// -----------------------------------------------------------------------
// 3. Require login
// -----------------------------------------------------------------------
function auth_is_ajax_request(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function auth_deny(string $message, int $status = 401): void
{
    if (auth_is_ajax_request()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    auth_deny('Please sign in to continue.');
}

// -----------------------------------------------------------------------
// 4. Load current user (fresh from DB each request)
// -----------------------------------------------------------------------
$currentUser = null;

$stmt = mysqli_prepare($conn, 'SELECT id, username, role, photo_path FROM users WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$currentUser = mysqli_fetch_assoc($result);

// The session points to a user that no longer exists (deleted account, etc.)
// — treat as logged out rather than trusting stale session data.
if (!$currentUser) {
    $_SESSION = [];
    session_destroy();
    auth_deny('Your session is no longer valid. Please sign in again.');
}

// Keep session role/username in sync with the DB in case they changed
// (e.g. an admin edited this user's role in another tab).
$_SESSION['username'] = $currentUser['username'];
$_SESSION['role']     = $currentUser['role'];

// Also expose as $navUser so navbar.php can reuse this data without
// hitting the database a second time.
$navUser = $currentUser;

// -----------------------------------------------------------------------
// 5. Authorization helpers
// -----------------------------------------------------------------------
function is_admin(): bool
{
    global $currentUser;
    return isset($currentUser['role']) && $currentUser['role'] === 'admin';
}

/**
 * Restrict the current page to one or more roles.
 * Call after require_once auth.php.
 *
 *   require_role('admin');
 *   require_role(['admin', 'employee']);
 *
 * @param string|array $roles  Single role or list of allowed roles.
 * @param bool $isAjax         If true, always respond with JSON 403 instead
 *                              of redirecting (use in AJAX-only endpoints).
 */
function require_role($roles, bool $isAjax = false): void
{
    global $currentUser;

    $allowed = is_array($roles) ? $roles : [$roles];

    if (!isset($currentUser['role']) || !in_array($currentUser['role'], $allowed, true)) {
        if ($isAjax || auth_is_ajax_request()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'You do not have permission to access this.']);
            exit;
        }
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Access Denied</title></head>'
           . '<body style="font-family:sans-serif;text-align:center;padding:4rem;">'
           . '<h2>403 — Access Denied</h2>'
           . '<p>You do not have permission to view this page.</p>'
           . '<p><a href="customers.php">Return to Dashboard</a></p>'
           . '</body></html>';
        exit;
    }
}