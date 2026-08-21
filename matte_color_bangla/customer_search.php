<?php
/**
 * customer_search.php
 * FineBullion Desk - Customer autocomplete / suggestion API
 *
 * GET customer_search.php?q=Chayan
 */

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($query === '') {
    echo json_encode(['success' => true, 'count' => 0, 'data' => []]);
    exit;
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
if ($limit <= 0 || $limit > 25) {
    $limit = 10;
}

$like = '%' . $query . '%';
$starts = $query . '%';

$sql = "SELECT id, name, phone, address, photo_path
        FROM customers
        WHERE name LIKE ? OR phone LIKE ?
        ORDER BY
            CASE
                WHEN phone = ? THEN 0
                WHEN name = ? THEN 0
                WHEN phone LIKE ? THEN 1
                WHEN name LIKE ? THEN 1
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
        'id'         => (int) $row['id'],
        'name'       => $row['name'],
        'phone'      => $row['phone'],
        'address'    => $row['address'],
        'photo_path' => $row['photo_path'],
    ];
}

echo json_encode(['success' => true, 'count' => count($data), 'data' => $data]);