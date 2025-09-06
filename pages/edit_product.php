<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/db.php';
$conn = get_db_connection();

// Admin check
if (strtolower($_SESSION['USER']['TYPE'] ?? '') !== 'admin') {
    header('Location: ../pages/dashboard.php');
    exit;
}

// Get product ID
$productId = $_GET['id'] ?? 0;

// Fetch product data
$product = [];
$stmt = sqlsrv_query($conn, "SELECT * FROM tblProducts WHERE PRODUCTID = ?", [$productId]);
if ($stmt) {
    $product = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
}

// Fetch categories
$categories = [];
$catStmt = sqlsrv_query($conn, "SELECT CATEGORYID, CATEGORYNAME FROM tblProductCategory ORDER BY CATEGORYNAME");
while ($catRow = sqlsrv_fetch_array($catStmt, SQLSRV_FETCH_ASSOC)) {
    $categories[] = $catRow;
}
?>
<!-- Similar to add modal but pre-populated -->