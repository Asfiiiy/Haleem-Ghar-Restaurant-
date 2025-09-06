<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/db.php';
$conn = get_db_connection();

// Check if user is admin
if (strtolower($_SESSION['USER']['TYPE'] ?? '') !== 'admin') {
    header('Location: ../pages/dashboard.php');
    exit;
}

// Start transaction for better performance and data integrity
sqlsrv_begin_transaction($conn);

try {
    // Get category information (optimized)
    $categoryId = $_POST['category_id'];
    $categoryStmt = sqlsrv_query($conn, "SELECT CATEGORYCODE FROM tblProductCategory WITH (NOLOCK) WHERE CATEGORYID = ?", [$categoryId]);
    $categoryData = sqlsrv_fetch_array($categoryStmt, SQLSRV_FETCH_ASSOC);
    $categoryCode = $categoryData['CATEGORYCODE'] ?? '';
    sqlsrv_free_stmt($categoryStmt);

// Prepare product data with all required fields
$productData = [
    'CREATIONDATETIME' => date('Y-m-d H:i:s'),
    'CREATIONUSERID' => $_SESSION['USER']['ID'] ?? 1,
    'BRANCHID' => 1, // Auto-set to 1
    'BRANCHCODE' => 'BR-1', // Auto-set to BR-1
    'NAME' => $_POST['name'],
    'PRODUCTCODE' => $_POST['product_code'],
    'PRODUCTTYPE' => $_POST['product_type'],
    'CATEGORYID' => $categoryId,
    'CATEGORYCODE' => $categoryCode,
    'PRODUCTTAX' => 0, // Auto-set to 0
    'UNIT' => 1, // Auto-set to 1
    'QUANTITYPERUNIT' => 1, // Auto-set to 1
    'STATUS' => $_POST['status'],
    'PRICE' => $_POST['price'],
    'SYNCH' => 'Y' // Auto-set to Y
];

// Insert into tblProducts
$columns = implode(',', array_keys($productData));
$values = implode(',', array_fill(0, count($productData), '?'));
$sql = "INSERT INTO tblProducts ($columns) VALUES ($values)";

    $stmt = sqlsrv_prepare($conn, $sql, array_values($productData));
    if (sqlsrv_execute($stmt)) {
        $productId = sqlsrv_fetch_array(sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS ID"))['ID'];
        
        // Insert into default price list (optimized)
        $priceListDetail = [
            'ITEMID' => $productId,
            'ITEMCODE' => $_POST['product_code'],
            'PRICELISTID' => 1, // Default price list ID
            'PRICELISTCODE' => 'DEFAULT',
            'DINEINPRICE' => $_POST['price'],
            'DELIVERYPRICE' => $_POST['price'],
            'TAKEAWAYPRICE' => $_POST['price'],
            'BRANCHID' => 1, // Use the same auto-set value
            'BRANCHCODE' => 'BR-1', // Use the same auto-set value
            'CREATIONUSERID' => $_SESSION['USER']['ID'] ?? 1,
            'CREATIONDATETIME' => date('Y-m-d H:i:s')
        ];
        
        $columns = implode(',', array_keys($priceListDetail));
        $values = implode(',', array_fill(0, count($priceListDetail), '?'));
        $priceSql = "INSERT INTO tblPricelistDetail ($columns) VALUES ($values)";
        
        $priceStmt = sqlsrv_prepare($conn, $priceSql, array_values($priceListDetail));
        if (sqlsrv_execute($priceStmt)) {
            sqlsrv_commit($conn);
            $_SESSION['flash_message'] = 'Product added successfully!';
        } else {
            sqlsrv_rollback($conn);
            $_SESSION['flash_error'] = 'Product added but failed to update price list: ' . print_r(sqlsrv_errors(), true);
        }
        sqlsrv_free_stmt($priceStmt);
    } else {
        sqlsrv_rollback($conn);
        $_SESSION['flash_error'] = 'Error adding product: ' . print_r(sqlsrv_errors(), true);
    }
    sqlsrv_free_stmt($stmt);
    
} catch (Exception $e) {
    sqlsrv_rollback($conn);
    $_SESSION['flash_error'] = 'Error adding product: ' . $e->getMessage();
}

header('Location: products.php');
exit;
?>