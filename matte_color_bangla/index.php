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
    header('Location: gold_exchange_inventory.php');
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
            header('Location: gold_exchange_inventory.php');
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

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --navy: #2F4156;
            --teal: #567C8D;
            --sky: #C8D9E6;
            --beige: #F5EFEB;
            --white: #FFFFFF;
            --text-secondary: #567C8D;
            --border-default: #C8D9E6;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--beige);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .login-card {
            width: 100%;
            max-width: 380px;
            background: #ffffff;
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(47, 65, 86, 0.16);
        }

        /* ---------- Header: wavy gold banner with logo ---------- */
        .card-header {
            position: relative;
            background: var(--navy);
            padding: 2.1rem 1.5rem 3.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 108px;
        }

        .card-header svg.wave {
            position: absolute;
            left: 0;
            bottom: -1px;
            width: 100%;
            height: 34px;
            display: block;
        }

        /* Real logo image — falls back to text wordmark via onerror in JS below */
        #brandLogoImg {
            max-height: auto;
            max-width: 300px;
            width: auto;
            display: block;
            position: relative;
            z-index: 2;
        }

        .brand-fallback {
            display: none;
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            color: #ffffff;
            text-shadow: 0 1px 3px rgba(0,0,0,0.12);
            position: relative;
            z-index: 2;
        }

        .brand-fallback.show-fallback {
            display: block;
        }

        /* ---------- Body ---------- */
        .card-body {
            padding: 0.5rem 2rem 2.25rem;
        }

        .login-heading {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            color: var(--navy);
            margin: 0.25rem 0 1.9rem;
        }

        .field {
            margin-bottom: 1.5rem;
        }

        .field label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 0.45rem;
        }

        .underline-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .underline-input-wrap input {
            width: 100%;
            border: none;
            border-bottom: 1.5px solid var(--border-default);
            background: transparent;
            padding: 0.35rem 1.8rem 0.55rem 0.05rem;
            font-size: 1rem;
            color: var(--navy);
            outline: none;
            transition: border-color 0.15s;
        }

        .underline-input-wrap input::placeholder {
            color: #a9bccb;
        }

        .underline-input-wrap input:focus {
            border-bottom-color: var(--teal);
        }

        .underline-input-wrap i.field-icon {
            position: absolute;
            right: 0.1rem;
            color: var(--text-secondary);
            font-size: 0.95rem;
            cursor: default;
        }

        .btn-toggle-pw {
            position: absolute;
            right: 0;
            background: transparent;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 0.2rem;
            display: flex;
            align-items: center;
        }

        .btn-toggle-pw:hover {
            color: var(--navy);
        }

        .forgot-row {
            text-align: right;
            margin-top: 0.5rem;
        }

        .forgot-link {
            font-size: 0.8rem;
            color: var(--navy);
            text-decoration: none;
            font-weight: 600;
        }

        .forgot-link:hover {
            text-decoration: underline;
        }

        .btn-signin {
            display: block;
            width: 100%;
            margin-top: 1.9rem;
            background: var(--navy);
            border: none;
            color: #fff;
            font-weight: 700;
            font-size: 0.95rem;
            letter-spacing: 0.06em;
            padding: 0.85rem 1.25rem;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 10px 22px rgba(47, 65, 86, 0.28);
            transition: background 0.15s, transform 0.1s;
        }

        .btn-signin:hover:not(:disabled) {
            background: var(--teal);
            transform: translateY(-1px);
        }

        .btn-signin:active:not(:disabled) {
            transform: translateY(0);
        }

        .btn-signin:disabled {
            opacity: 0.7;
            cursor: default;
        }

        .signup-row {
            text-align: center;
            margin-top: 1.2rem;
            font-size: 0.82rem;
            color: var(--text-secondary);
        }

        .signup-row a {
            color: var(--navy);
            font-weight: 700;
            text-decoration: none;
        }

        .signup-row a:hover {
            text-decoration: underline;
        }

        .alert-login {
            font-size: 0.82rem;
            border-radius: 10px;
            background: #FBECEC;
            border: 1px solid #E9C6C6;
            color: #A6434B;
            padding: 0.6rem 0.85rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .login-footer {
            text-align: center;
            font-size: 0.72rem;
            color: var(--text-secondary);
            margin-top: 1.6rem;
        }
    </style>
</head>
<body>

<div class="login-card">

    <div class="card-header">
        <!-- Real logo: point src at your uploaded logo file. Falls back automatically if missing. -->
        <img id="brandLogoImg" src="finebullion desk logo.png" alt="FineBullion Desk">
        <div class="brand-fallback" id="brandFallback">FINE BULLION DESK</div>

        <svg class="wave" viewBox="0 0 400 34" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M0,10 C80,34 160,0 240,14 C300,24 350,6 400,16 L400,34 L0,34 Z" fill="#ffffff"></path>
        </svg>
    </div>

    <div class="card-body">

        <h1 class="login-heading">LOGIN</h1>

        <?php if ($error !== null): ?>
            <div class="alert-login" role="alert">
                <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php" id="loginForm" novalidate>

            <div class="field">
                <label for="username">Username</label>
                <div class="underline-input-wrap">
                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Your Name"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        autocomplete="username"
                        autofocus
                        required
                    >
                </div>
            </div>

            <div class="field mb-0">
                <label for="password">Password</label>
                <div class="underline-input-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        required
                    >
                    <button type="button" class="btn-toggle-pw" id="togglePw" aria-label="Show or hide password" tabindex="-1">
                        <i class="bi bi-eye" id="togglePwIcon"></i>
                    </button>
                </div>
                <!-- <div class="forgot-row">
                    <a href="#" class="forgot-link">Forget your password?</a>
                </div> -->
            </div>

            <button type="submit" class="btn-signin" id="submitBtn">
                <span id="submitLabel">SIGN IN</span>
                <!-- <span id="submitSpinner" class="d-none">
                    <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                    Signing in…
                </span> -->
            </button>
        </form>

        <div class="login-footer">
            &copy; <?= date('Y') ?> FineBullion Desk. All rights reserved.
        </div>

    </div>
</div>

<script>
    // Logo fallback: if assets/logo.png fails to load (or hasn't been uploaded yet),
    // hide the <img> and show the text wordmark fallback instead.
    (function () {
        const img = document.getElementById('brandLogoImg');
        const fallback = document.getElementById('brandFallback');
        img.addEventListener('error', function () {
            img.style.display = 'none';
            fallback.classList.add('show-fallback');
        }, { once: true });
    })();

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