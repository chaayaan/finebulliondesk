<?php
/**
 * config.php
 * FineBullion Desk - Shared configuration
 *
 * Just the database connection. Auth, CSRF, and validation
 * will be added separately later.
 */

// ---------------------------------------------------------------------
// Database configuration
// ---------------------------------------------------------------------
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'finebullion_desk');
define('DB_USER', 'root');   // <-- change in production
define('DB_PASS', '');       // <-- change in production

// ---------------------------------------------------------------------
// mysqli connection
// ---------------------------------------------------------------------
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$conn) {
    die('Database connection failed: ' . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8mb4');