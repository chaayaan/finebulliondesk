<?php
/**
 * upload_helper.php
 * Basic image upload handling for customer/user photos.
 */

define('CUSTOMER_UPLOAD_DIR', __DIR__ . '/uploads/customers/');
define('CUSTOMER_UPLOAD_WEBDIR', 'uploads/customers/');

/**
 * Move an uploaded customer photo. Returns the relative web path, or null.
 */
function handle_customer_photo_upload($file)
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (!is_dir(CUSTOMER_UPLOAD_DIR)) {
        mkdir(CUSTOMER_UPLOAD_DIR, 0755, true);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = 'jpg';
    }

    $filename = uniqid('customer_') . '.' . $ext;
    $destination = CUSTOMER_UPLOAD_DIR . $filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return CUSTOMER_UPLOAD_WEBDIR . $filename;
    }

    return null;
}

/**
 * Delete a previously stored customer photo file.
 */
function delete_customer_photo($relativePath)
{
    if (!$relativePath) {
        return;
    }
    $full = __DIR__ . '/' . $relativePath;
    if (file_exists($full)) {
        @unlink($full);
    }
}