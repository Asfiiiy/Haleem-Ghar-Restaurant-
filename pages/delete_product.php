<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/db.php';
$conn = get_db_connection();

// Admin check
if (strtolower($_SESSION['USER']['TYPE'] ?? '') !== 'admin') {
    header('Location: ../pages/dashboard.php');
    exit;
}

$productId = $_GET['id'] ?? 0;

// First delete from price list
sqlsrv_query($conn, "DELETE FROM tblPricelistDetail WHERE ITEMID = ?", [$productId]);

// Then delete product
if (sqlsrv_query($conn, "DELETE FROM tblProducts WHERE PRODUCTID = ?", [$productId])) {
    $_SESSION['flash_message'] = 'Product deleted successfully';
} else {
    $_SESSION['flash_error'] = 'Error deleting product';
}

header('Location: products.php');
exit;
?>