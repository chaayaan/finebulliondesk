<?php
/**
 * index.php
 * FineBullion Desk — Authentication page
 *
 * NOTE: This page does NOT include auth.php (auth.php requires login and
 * would redirect back here, causing a loop). It starts its own session
 * and just checks manually whether the user is already logged in.
 */

session_start();
require_once __DIR__ . '/config.php';

// Already logged in? Skip straight to the app.
if (isset($_SESSION['user_id'])) {
    header('Location: customers.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $stmt = mysqli_prepare($conn, 'SELECT id, password FROM users WHERE username = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            header('Location: customers.php');
            exit;
        } else {
            $error = 'Incorrect username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — FineBullion Desk</title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        html, body {
            height: 100%;
        }

        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
            padding: 2.5rem 2.25rem 2rem;
        }

        .brand-icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: linear-gradient(135deg, #c9973a 0%, #e6b84a 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #fff;
            margin-bottom: 1rem;
            flex-shrink: 0;
        }

        .brand-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1a1a1a;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }

        .brand-sub {
            font-size: 0.8rem;
            color: #8a8f98;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-weight: 500;
        }

        .divider {
            border: none;
            border-top: 1px solid #eaecef;
            margin: 1.5rem 0;
        }

        .form-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.3rem;
        }

        .input-group-text {
            background: #f8f9fa;
            border-right: none;
            color: #8a8f98;
        }

        .form-control {
            border-left: none;
            padding-left: 0;
        }

        .form-control:focus {
            box-shadow: none;
            border-color: #c9973a;
        }

        .btn-toggle-pw {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-left: none;
            color: #8a8f98;
            padding: 0 0.75rem;
            cursor: pointer;
            border-radius: 0 6px 6px 0;
            transition: color 0.15s;
        }

        .btn-toggle-pw:hover {
            color: #495057;
        }

        .btn-signin {
            background: linear-gradient(135deg, #c9973a 0%, #d4a847 100%);
            border: none;
            color: #fff;
            font-weight: 600;
            font-size: 0.95rem;
            padding: 0.6rem 1.25rem;
            border-radius: 8px;
            letter-spacing: 0.01em;
            transition: opacity 0.15s, transform 0.1s;
            width: 100%;
        }

        .btn-signin:hover:not(:disabled) {
            opacity: 0.92;
            transform: translateY(-1px);
            color: #fff;
        }

        .btn-signin:active:not(:disabled) {
            transform: translateY(0);
        }

        .btn-signin:disabled {
            opacity: 0.7;
        }

        .alert-login {
            font-size: 0.875rem;
            border-radius: 8px;
        }

        .login-footer {
            text-align: center;
            font-size: 0.78rem;
            color: #b0b5bf;
            margin-top: 1.5rem;
        }

        @media (max-width: 480px) {
            .login-card {
                border-radius: 0;
                box-shadow: none;
                padding: 2rem 1.25rem 1.5rem;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<div class="login-card">

    <div class="d-flex align-items-center gap-3 mb-1">
        <div class="brand-icon">
            <i class="bi bi-gem"></i>
        </div>
        <div>
            <div class="brand-name">FineBullion Desk</div>
            <div class="brand-sub">Management System</div>
        </div>
    </div>

    <hr class="divider">


    <?php if ($error !== null): ?>
        <div class="alert alert-danger alert-login d-flex align-items-center gap-2 py-2 mb-3" role="alert">
            <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" action="index.php" id="loginForm" novalidate>

        <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person"></i></span>
                <input
                    type="text"
                    class="form-control"
                    id="username"
                    name="username"
                    placeholder="Enter your username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                    autocomplete="username"
                    autofocus
                    required
                >
            </div>
        </div>

        <div class="mb-4">
            <label for="password" class="form-label">Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input
                    type="password"
                    class="form-control"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    required
                >
                <button type="button" class="btn-toggle-pw" id="togglePw" aria-label="Show or hide password" tabindex="-1">
                    <i class="bi bi-eye" id="togglePwIcon"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn btn-signin" id="submitBtn">
            <span id="submitLabel">
                <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
            </span>
            <span id="submitSpinner" class="d-none">
                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                Signing in…
            </span>
        </button>
    </form>

    <div class="login-footer">
        &copy; <?= date('Y') ?> FineBullion Desk. All rights reserved.
    </div>

</div>

<script>
    const pwInput   = document.getElementById('password');
    const toggleBtn = document.getElementById('togglePw');
    const toggleIcon = document.getElementById('togglePwIcon');

    toggleBtn.addEventListener('click', function () {
        const isHidden = pwInput.type === 'password';
        pwInput.type = isHidden ? 'text' : 'password';
        toggleIcon.className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
        toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });

    document.getElementById('loginForm').addEventListener('submit', function () {
        const btn     = document.getElementById('submitBtn');
        const label   = document.getElementById('submitLabel');
        const spinner = document.getElementById('submitSpinner');
        btn.disabled  = true;
        label.classList.add('d-none');
        spinner.classList.remove('d-none');
    });
</script>

</body>
</html>