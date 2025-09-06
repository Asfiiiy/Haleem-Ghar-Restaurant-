<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/db.php';
$conn = get_db_connection();

// Admin check
if (strtolower($_SESSION['USER']['TYPE'] ?? '') !== 'admin') {
    header('Location: ../pages/dashboard.php');
    exit;
}

// Start transaction for better performance
sqlsrv_begin_transaction($conn);

try {
    $productId = $_POST['product_id'];
    $categoryId = $_POST['category_id'];
    
    // Get category code in the same transaction (optimized single query)
    $categoryStmt = sqlsrv_query($conn, "SELECT CATEGORYCODE FROM tblProductCategory WITH (NOLOCK) WHERE CATEGORYID = ?", [$categoryId]);
    $categoryData = sqlsrv_fetch_array($categoryStmt, SQLSRV_FETCH_ASSOC);
    $categoryCode = $categoryData['CATEGORYCODE'] ?? '';
    sqlsrv_free_stmt($categoryStmt);
    
    // Optimized update with minimal columns
    $sql = "UPDATE tblProducts WITH (ROWLOCK) SET 
            NAME = ?, 
            PRODUCTCODE = ?, 
            CATEGORYID = ?, 
            CATEGORYCODE = ?, 
            PRICE = ?, 
            PRODUCTTYPE = ?, 
            STATUS = ?, 
            UPDATIONUSERID = ?, 
            UPDATEDATETIME = ? 
            WHERE PRODUCTID = ?";
    
    $params = [
        $_POST['name'],
        $_POST['product_code'],
        $categoryId,
        $categoryCode,
        $_POST['price'],
        $_POST['product_type'],
        $_POST['status'],
        $_SESSION['USER']['ID'] ?? 1,
        date('Y-m-d H:i:s'),
        $productId
    ];
    
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    
    if (sqlsrv_execute($stmt)) {
        sqlsrv_commit($conn);
        $_SESSION['flash_message'] = 'Product updated successfully!';
    } else {
        sqlsrv_rollback($conn);
        $_SESSION['flash_error'] = 'Error updating product: ' . print_r(sqlsrv_errors(), true);
    }
    
    sqlsrv_free_stmt($stmt);
    
} catch (Exception $e) {
    sqlsrv_rollback($conn);
    $_SESSION['flash_error'] = 'Error updating product: ' . $e->getMessage();
}

header('Location: products.php');
exit;
?>