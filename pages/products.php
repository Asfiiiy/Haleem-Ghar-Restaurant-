<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/db.php';
$conn = get_db_connection();

// Check if user is admin

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userType = strtolower($_SESSION['USER']['TYPE'] ?? '');

$isAdmin = ($userType === 'admin'); // ✅ Define $isAdmin

if (!$isAdmin) {
    header('Location: ../pages/dashboard.php');
    exit;
}


// Initialize search parameter
$searchTerm = $_GET['search'] ?? '';
$searchTerm = trim($searchTerm);

// Handle product deletion
if (isset($_GET['delete_id'])) {
    $productId = $_GET['delete_id'];
    
    // Start transaction
    sqlsrv_begin_transaction($conn);
    
    try {
        // Delete from related tables first (if any)
        // Delete from tblPricelistDetail (if exists)
        $pricelistSql = "DELETE FROM tblPricelistDetail WHERE ITEMID = ?";
        $pricelistStmt = sqlsrv_query($conn, $pricelistSql, [$productId]);
        
        // Delete from tblSalesInvoiceDetail (if exists)
        $salesDetailSql = "DELETE FROM tblSalesInvoiceDetail WHERE ITEMID = ?";
        $salesDetailStmt = sqlsrv_query($conn, $salesDetailSql, [$productId]);
        
        // Finally delete from tblProducts
        $productSql = "DELETE FROM tblProducts WHERE PRODUCTID = ?";
        $productStmt = sqlsrv_query($conn, $productSql, [$productId]);
        
        if ($productStmt !== false) {
            sqlsrv_commit($conn);
            $_SESSION['flash_message'] = 'Product and all related data deleted successfully';
    } else {
            sqlsrv_rollback($conn);
        $_SESSION['flash_error'] = 'Error deleting product: ' . print_r(sqlsrv_errors(), true);
        }
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['flash_error'] = 'Error deleting product: ' . $e->getMessage();
    }
    
    header('Location: products.php');
    exit;
}

// Build optimized query with search functionality and performance hints
$params = array();
$sql = "
    SELECT 
        p.PRODUCTID,
        p.PRODUCTCODE,
        p.NAME AS PRODUCTNAME,
        p.PRICE,
        p.CATEGORYID,
        p.PRODUCTTYPE,
        pc.CATEGORYNAME,
        pc.CATEGORYCODE,
        p.STATUS
    FROM tblProducts p WITH (NOLOCK, READUNCOMMITTED)
    LEFT JOIN tblProductCategory pc WITH (NOLOCK) ON p.CATEGORYID = pc.CATEGORYID";

// Add search condition if search term is provided
if (!empty($searchTerm)) {
    $sql .= " WHERE p.NAME LIKE ? OR p.PRODUCTCODE LIKE ?";
    $searchParam = '%' . $searchTerm . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$sql .= " ORDER BY p.NAME";

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

$products = array();
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $products[] = $row;
}
sqlsrv_free_stmt($stmt);

// Get total product count for animation
$totalProducts = count($products);

// Fetch categories for dropdown (optimized)
$categories = array();
$catStmt = sqlsrv_query($conn, "SELECT CATEGORYID, CATEGORYNAME FROM tblProductCategory WITH (NOLOCK) ORDER BY CATEGORYNAME");
if ($catStmt !== false) {
while ($catRow = sqlsrv_fetch_array($catStmt, SQLSRV_FETCH_ASSOC)) {
    $categories[] = $catRow;
}
sqlsrv_free_stmt($catStmt);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="apple-touch-icon" sizes="76x76" href="../assets/img/apple-icon.png">
  <link rel="icon" type="image/png" href="../assets/img/favicon.png">
  <title>
    Product Management
  </title>
  <!--     Fonts and icons     -->
  <link href="https://fonts.googleapis.com/css?family=Inter:300,400,500,600,700,800" rel="stylesheet" />
  <!-- Nucleo Icons -->
  <link href="https://demos.creative-tim.com/soft-ui-dashboard/assets/css/nucleo-icons.css" rel="stylesheet" />
  <link href="https://demos.creative-tim.com/soft-ui-dashboard/assets/css/nucleo-svg.css" rel="stylesheet" />
  <!-- Font Awesome Icons -->
  <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
  <!-- CSS Files -->
  <link id="pagestyle" href="../assets/css/soft-ui-dashboard.css?v=1.1.0" rel="stylesheet" />
  <style>
    body {
        background-color: #f8fafc;
    }
    .card {
        border: none;
    }
    .bg-soft-light {
        background-color: #f9fafc;
    }
    .input-group-text {
        transition: all 0.3s ease;
    }
    .input-group:focus-within .input-group-text {
        color: #667eea;
    }
    .icon-shape {
        transition: all 0.3s ease;
    }
    tr:hover .icon-shape {
        background: linear-gradient(135deg, rgba(118,75,162,0.2) 0%, rgba(118,75,162,0.3) 100%) !important;
    }
    .table tbody tr:hover td {
        background-color: #f8fafd;
    }
    .status-active {
        color: #198754;
        background-color: rgba(25,135,84,0.1);
    }
    .status-inactive {
        color: #dc3545;
        background-color: rgba(220,53,69,0.1);
    }
    
    /* Performance optimizations */
    .table {
        table-layout: fixed;
        width: 100%;
    }
    
    /* Search highlighting */
    .search-highlight {
        background-color: yellow;
        font-weight: bold;
    }
    
    /* Loading spinner */
    .loading-spinner {
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 9999;
    }
    
    /* Optimize button animations */
    .btn {
        will-change: transform;
        backface-visibility: hidden;
    }
    
    /* Mobile Responsive Styles */
    @media (max-width: 768px) {
      /* Navbar adjustments */
      .navbar-nav {
        flex-direction: column;
        width: 100%;
      }
      
      /* Search input responsive */
      .input-group {
        width: 100%;
        margin-bottom: 10px;
      }
      
      /* Header adjustments */
      .card-header {
        padding: 15px !important;
      }
      
      .card-header .d-flex {
        flex-direction: column;
        gap: 15px;
      }
      
      /* Product counter on mobile */
      .card-header h5 {
        font-size: 1.1rem;
      }
      
      .card-header p {
        font-size: 0.85rem;
        margin-bottom: 0;
      }
      
      /* Button adjustments */
      .btn-sm {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
      }
      
      /* Table responsive */
      .table-responsive {
        border-radius: 0.5rem;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
      }
      
      .table td, .table th {
        padding: 0.5rem;
        font-size: 0.8rem;
      }
      
      /* Allow product names to wrap on tablets */
      .table td:first-child {
        white-space: normal;
        min-width: 150px;
      }
      
      .table td:first-child h6 {
        white-space: normal;
        word-wrap: break-word;
        line-height: 1.3;
        font-size: 0.85rem;
      }
      
      /* Keep other columns compact */
      .table td:not(:first-child) {
        white-space: nowrap;
      }
      
      /* Action buttons on mobile */
      .btn-sm.me-2 {
        margin-right: 0.25rem !important;
        margin-bottom: 0.25rem;
      }
      
      /* Modal adjustments */
      .modal-dialog {
        margin: 10px;
        max-width: calc(100% - 20px);
      }
      
      .modal-content .row {
        margin: 0;
      }
      
      .modal-content .col-md-6 {
        padding: 0 0.5rem;
        margin-bottom: 1rem;
      }
    }
    
    @media (max-width: 576px) {
      /* Extra small screens */
      .container-fluid {
        padding-left: 10px;
        padding-right: 10px;
      }
      
      /* Sidebar adjustments */
      .sidenav {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
      }
      
      .sidenav.show {
        transform: translateX(0);
      }
      
      /* Main content full width on mobile */
      .main-content {
        margin-left: 0 !important;
        width: 100% !important;
      }
      
      /* Card adjustments */
      .card {
        margin: 0.5rem 0;
        border-radius: 0.75rem;
      }
      
      /* Table cells even smaller */
      .table td, .table th {
        padding: 0.3rem;
        font-size: 0.75rem;
      }
      
      /* Product name display on very small screens */
      .table td:first-child {
        min-width: 140px;
        max-width: 200px;
      }
      
      .table td:first-child h6 {
        white-space: normal !important;
        word-wrap: break-word;
        overflow-wrap: break-word;
        line-height: 1.2;
        margin-bottom: 0;
        font-size: 0.8rem;
      }
      
      .table td:first-child p {
        font-size: 0.65rem;
        margin-bottom: 0;
        color: #6c757d;
      }
      
      /* Hide product icons on mobile screens for better space usage */
      .table td:first-child .icon-shape {
        display: none;
      }
      
      /* Remove left margin when icon is hidden */
      .table td:first-child .product-name-container {
        margin-left: 0;
      }
      
      /* Status badges smaller */
      .status-active, .status-inactive {
        font-size: 0.7rem;
        padding: 0.2rem 0.4rem;
      }
      
      /* Action buttons stack vertically on very small screens */
      .d-flex.justify-content-center {
        flex-direction: column;
        gap: 0.25rem;
      }
      
      .d-flex.justify-content-center .btn {
        width: 100%;
        margin: 0;
      }
    }
    
    /* Horizontal scroll indicator */
    .table-responsive::-webkit-scrollbar {
      height: 6px;
    }
    
    .table-responsive::-webkit-scrollbar-track {
      background: #f1f1f1;
      border-radius: 3px;
    }
    
    .table-responsive::-webkit-scrollbar-thumb {
      background: #c1c1c1;
      border-radius: 3px;
    }
    
    .table-responsive::-webkit-scrollbar-thumb:hover {
      background: #a8a8a8;
    }
    
    /* Enhanced product name display */
    .product-name-container {
      position: relative;
    }
    
    .product-name-container h6 {
      margin-bottom: 2px;
    }
    
    /* Tooltip for long product names */
    .product-name-tooltip {
      position: absolute;
      background: #333;
      color: white;
      padding: 8px 12px;
      border-radius: 4px;
      font-size: 0.8rem;
      z-index: 1000;
      white-space: normal;
      max-width: 250px;
      word-wrap: break-word;
      display: none;
      top: 100%;
      left: 0;
      margin-top: 5px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    
    .product-name-tooltip::before {
      content: '';
      position: absolute;
      top: -6px;
      left: 15px;
      border-left: 6px solid transparent;
      border-right: 6px solid transparent;
      border-bottom: 6px solid #333;
    }
    
    @media (max-width: 480px) {
      /* Extra responsive for very small screens */
      .table {
        min-width: 600px;
      }
      
      .table td:first-child {
        min-width: 120px;
        max-width: 160px;
      }
      
      .table td:first-child h6 {
        font-size: 0.75rem;
        line-height: 1.1;
      }
      
      .table td:first-child p {
        font-size: 0.6rem;
      }
      
      /* Hide product icons on very small screens */
      .table td:first-child .icon-shape {
        display: none;
      }
      
      /* Adjust product name container when icon is hidden */
      .table td:first-child .d-flex {
        padding-left: 0;
      }
    }
    
    /* Trash Animation Styles */
    .trash-animation-container {
      position: relative;
      width: 100px;
      height: 100px;
      margin: 0 auto;
    }
    
    .trash-can {
      position: relative;
      width: 60px;
      height: 70px;
      margin: 0 auto;
      animation: trashShake 2s infinite ease-in-out;
    }
    
    .trash-lid {
      width: 50px;
      height: 8px;
      background: #dc3545;
      border-radius: 4px;
      position: absolute;
      top: -4px;
      left: 5px;
      z-index: 2;
    }
    
    .trash-lid::before {
      content: '';
      width: 20px;
      height: 6px;
      background: #dc3545;
      border-radius: 3px;
      position: absolute;
      top: -8px;
      left: 15px;
    }
    
    .trash-body {
      width: 45px;
      height: 60px;
      background: #dc3545;
      border-radius: 0 0 8px 8px;
      position: absolute;
      top: 8px;
      left: 7.5px;
    }
    
    .trash-line {
      width: 3px;
      height: 40px;
      background: rgba(255, 255, 255, 0.8);
      position: absolute;
      top: 8px;
      border-radius: 2px;
    }
    
    .trash-line:nth-child(1) {
      left: 12px;
    }
    
    .trash-line:nth-child(2) {
      left: 21px;
    }
    
    .trash-line:nth-child(3) {
      left: 30px;
    }
    
    .delete-item {
      position: absolute;
      top: 25px;
      left: 50%;
      transform: translateX(-50%);
      font-size: 20px;
      color: #ffc107;
      animation: itemFall 2s infinite ease-in-out;
      opacity: 0;
    }
    
    @keyframes trashShake {
      0%, 50%, 100% {
        transform: rotate(0deg);
      }
      25% {
        transform: rotate(-3deg);
      }
      75% {
        transform: rotate(3deg);
      }
    }
    
    @keyframes itemFall {
      0% {
        opacity: 1;
        transform: translateX(-50%) translateY(-20px) scale(1);
      }
      50% {
        opacity: 0.7;
        transform: translateX(-50%) translateY(10px) scale(0.8);
      }
      100% {
        opacity: 0;
        transform: translateX(-50%) translateY(30px) scale(0.5);
      }
    }
    
    /* Modal animations */
    .modal.fade .modal-dialog {
      transform: scale(0.8);
      transition: transform 0.3s ease-out;
    }
    
    .modal.show .modal-dialog {
      transform: scale(1);
    }
    
    /* Delete button hover effect */
    #confirmDeleteBtn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
      transition: all 0.3s ease;
    }
  </style>
</head>

<body class="g-sidenav-show bg-gray-100">
  <!-- Sidebar -->
  <aside class="sidenav navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-3 " id="sidenav-main">
    <div class="sidenav-header">
      <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
      <a class="navbar-brand m-0" href="dashboard.php">
        <img src="../assets/img/logo-ct-dark.png" class="navbar-brand-img h-100" alt="main_logo">
        <span class="ms-1 font-weight-bold">
          <?php 
          // Display the username from the USER array if logged in, otherwise show default text
          echo isset($_SESSION['USER']['NAME']) ? htmlspecialchars($_SESSION['USER']['NAME']) : '';
          ?>
        </span>
      </a>
    </div>
    <hr class="horizontal dark mt-0">
    <div class="collapse navbar-collapse  w-auto " id="sidenav-collapse-main">
      <ul class="navbar-nav">
        <li class="nav-item">
          <a class="nav-link  " href="../pages/dashboard.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <svg width="12px" height="12px" viewBox="0 0 45 40" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                <title>shop </title>
                <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                  <g transform="translate(-1716.000000, -439.000000)" fill="#FFFFFF" fill-rule="nonzero">
                    <g transform="translate(1716.000000, 291.000000)">
                      <g transform="translate(0.000000, 148.000000)">
                        <path class="color-background opacity-6" d="M46.7199583,10.7414583 L40.8449583,0.949791667 C40.4909749,0.360605034 39.8540131,0 39.1666667,0 L7.83333333,0 C7.1459869,0 6.50902508,0.360605034 6.15504167,0.949791667 L0.280041667,10.7414583 C0.0969176761,11.0460037 -1.23209662e-05,11.3946378 -1.23209662e-05,11.75 C-0.00758042603,16.0663731 3.48367543,19.5725301 7.80004167,19.5833333 L7.81570833,19.5833333 C9.75003686,19.5882688 11.6168794,18.8726691 13.0522917,17.5760417 C16.0171492,20.2556967 20.5292675,20.2556967 23.494125,17.5760417 C26.4604562,20.2616016 30.9794188,20.2616016 33.94575,17.5760417 C36.2421905,19.6477597 39.5441143,20.1708521 42.3684437,18.9103691 C45.1927731,17.649886 47.0084685,14.8428276 47.0000295,11.75 C47.0000295,11.3946378 46.9030823,11.0460037 46.7199583,10.7414583 Z"></path>
                        <path class="color-background" d="M39.198,22.4912623 C37.3776246,22.4928106 35.5817531,22.0149171 33.951625,21.0951667 L33.92225,21.1107282 C31.1430221,22.6838032 27.9255001,22.9318916 24.9844167,21.7998837 C24.4750389,21.605469 23.9777983,21.3722567 23.4960833,21.1018359 L23.4745417,21.1129513 C20.6961809,22.6871153 17.4786145,22.9344611 14.5386667,21.7998837 C14.029926,21.6054643 13.533337,21.3722507 13.0522917,21.1018359 C11.4250962,22.0190609 9.63246555,22.4947009 7.81570833,22.4912623 C7.16510551,22.4842162 6.51607673,22.4173045 5.875,22.2911849 L5.875,44.7220845 C5.875,45.9498589 6.7517757,46.9451667 7.83333333,46.9451667 L19.5833333,46.9451667 L19.5833333,33.6066734 L27.4166667,33.6066734 L27.4166667,46.9451667 L39.1666667,46.9451667 C40.2482243,46.9451667 41.125,45.9498589 41.125,44.7220845 L41.125,22.2822926 C40.4887822,22.4116582 39.8442868,22.4815492 39.198,22.4912623 Z"></path>
                      </g>
                    </g>
                  </g>
                </g>
              </svg>
            </div>
            <span class="nav-link-text ms-1">Dashboard</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link  " href="../pages/today_sales.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <svg width="12px" height="12px" viewBox="0 0 42 42" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                <title>office</title>
                <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                  <g transform="translate(-1869.000000, -293.000000)" fill="#FFFFFF" fill-rule="nonzero">
                    <g transform="translate(1716.000000, 291.000000)">
                      <g id="office" transform="translate(153.000000, 2.000000)">
                        <path class="color-background opacity-6" d="M12.25,17.5 L8.75,17.5 L8.75,1.75 C8.75,0.78225 9.53225,0 10.5,0 L31.5,0 C32.46775,0 33.25,0.78225 33.25,1.75 L33.25,12.25 L29.75,12.25 L29.75,3.5 L12.25,3.5 L12.25,17.5 Z"></path>
                        <path class="color-background" d="M40.25,14 L24.5,14 C23.53225,14 22.75,14.78225 22.75,15.75 L22.75,38.5 L19.25,38.5 L19.25,22.75 C19.25,21.78225 18.46775,21 17.5,21 L1.75,21 C0.78225,21 0,21.78225 0,22.75 L0,40.25 C0,41.21775 0.78225,42 1.75,42 L40.25,42 C41.21775,42 42,41.21775 42,40.25 L42,15.75 C42,14.78225 41.21775,14 40.25,14 Z M12.25,36.75 L7,36.75 L7,33.25 L12.25,33.25 L12.25,36.75 Z M12.25,29.75 L7,29.75 L7,26.25 L12.25,26.25 L12.25,29.75 Z M35,36.75 L29.75,36.75 L29.75,33.25 L35,33.25 L35,36.75 Z M35,29.75 L29.75,29.75 L29.75,26.25 L35,26.25 L35,29.75 Z M35,22.75 L29.75,22.75 L29.75,19.25 L35,19.25 L35,22.75 Z"></path>
                      </g>
                    </g>
                  </g>
                </g>
              </svg>
            </div>
            <span class="nav-link-text ms-1">Today Sale Report</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link  " href="../pages/SaleAnalysis.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <svg width="12px" height="12px" viewBox="0 0 42 42" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                <title>office</title>
                <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                  <g transform="translate(-1869.000000, -293.000000)" fill="#FFFFFF" fill-rule="nonzero">
                    <g transform="translate(1716.000000, 291.000000)">
                      <g id="office" transform="translate(153.000000, 2.000000)">
                        <path class="color-background opacity-6" d="M12.25,17.5 L8.75,17.5 L8.75,1.75 C8.75,0.78225 9.53225,0 10.5,0 L31.5,0 C32.46775,0 33.25,0.78225 33.25,1.75 L33.25,12.25 L29.75,12.25 L29.75,3.5 L12.25,3.5 L12.25,17.5 Z"></path>
                        <path class="color-background" d="M40.25,14 L24.5,14 C23.53225,14 22.75,14.78225 22.75,15.75 L22.75,38.5 L19.25,38.5 L19.25,22.75 C19.25,21.78225 18.46775,21 17.5,21 L1.75,21 C0.78225,21 0,21.78225 0,22.75 L0,40.25 C0,41.21775 0.78225,42 1.75,42 L40.25,42 C41.21775,42 42,41.21775 42,40.25 L42,15.75 C42,14.78225 41.21775,14 40.25,14 Z M12.25,36.75 L7,36.75 L7,33.25 L12.25,33.25 L12.25,36.75 Z M12.25,29.75 L7,29.75 L7,26.25 L12.25,26.25 L12.25,29.75 Z M35,36.75 L29.75,36.75 L29.75,33.25 L35,33.25 L35,36.75 Z M35,29.75 L29.75,29.75 L29.75,26.25 L35,26.25 L35,29.75 Z M35,22.75 L29.75,22.75 L29.75,19.25 L35,19.25 L35,22.75 Z"></path>
                      </g>
                    </g>
                  </g>
                </g>
              </svg>
            </div>
            <span class="nav-link-text ms-1">Sale Analysis Report</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link  " href="../pages/itemvisereport.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <svg width="12px" height="12px" viewBox="0 0 43 36" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                <title>credit-card</title>
                <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                  <g transform="translate(-2169.000000, -745.000000)" fill="#FFFFFF" fill-rule="nonzero">
                    <g transform="translate(1716.000000, 291.000000)">
                      <g transform="translate(453.000000, 454.000000)">
                        <path class="color-background opacity-6" d="M43,10.7482083 L43,3.58333333 C43,1.60354167 41.3964583,0 39.4166667,0 L3.58333333,0 C1.60354167,0 0,1.60354167 0,3.58333333 L0,10.7482083 L43,10.7482083 Z"></path>
                        <path class="color-background" d="M0,16.125 L0,32.25 C0,34.2297917 1.60354167,35.8333333 3.58333333,35.8333333 L39.4166667,35.8333333 C41.3964583,35.8333333 43,34.2297917 43,32.25 L43,16.125 L0,16.125 Z M19.7083333,26.875 L7.16666667,26.875 L7.16666667,23.2916667 L19.7083333,23.2916667 L19.7083333,26.875 Z M35.8333333,26.875 L28.6666667,26.875 L28.6666667,23.2916667 L35.8333333,23.2916667 L35.8333333,26.875 Z"></path>
                      </g>
                    </g>
                  </g>
                </g>
              </svg>
            </div>
            <span class="nav-link-text ms-1">Item Vise Report</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link  " href="../pages/category_vise_report.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <svg width="12px" height="12px" viewBox="0 0 42 42" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                <title>box-3d-50</title>
                <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                  <g transform="translate(-2319.000000, -291.000000)" fill="#FFFFFF" fill-rule="nonzero">
                    <g transform="translate(1716.000000, 291.000000)">
                      <g transform="translate(603.000000, 0.000000)">
                        <path class="color-background" d="M22.7597136,19.3090182 L38.8987031,11.2395234 C39.3926816,10.9925342 39.592906,10.3918611 39.3459167,9.89788265 C39.249157,9.70436312 39.0922432,9.5474453 38.8987261,9.45068056 L20.2741875,0.1378125 L20.2741875,0.1378125 C19.905375,-0.04725 19.469625,-0.04725 19.0995,0.1378125 L3.1011696,8.13815822 C2.60720568,8.38517662 2.40701679,8.98586148 2.6540352,9.4798254 C2.75080129,9.67332903 2.90771305,9.83023153 3.10122239,9.9269862 L21.8652864,19.3090182 C22.1468139,19.4497819 22.4781861,19.4497819 22.7597136,19.3090182 Z"></path>
                        <path class="color-background opacity-6" d="M23.625,22.429159 L23.625,39.8805372 C23.625,40.4328219 24.0727153,40.8805372 24.625,40.8805372 C24.7802551,40.8805372 24.9333778,40.8443874 25.0722402,40.7749511 L41.2741875,32.673375 L41.2741875,32.673375 C41.719125,32.4515625 42,31.9974375 42,31.5 L42,14.241659 C42,13.6893742 41.5522847,13.241659 41,13.241659 C40.8447549,13.241659 40.6916418,13.2778041 40.5527864,13.3472318 L24.1777864,21.5347318 C23.8390024,21.7041238 23.625,22.0503869 23.625,22.429159 Z"></path>
                        <path class="color-background opacity-6" d="M20.4472136,21.5347318 L1.4472136,12.0347318 C0.953235098,11.7877425 0.352562058,11.9879669 0.105572809,12.4819454 C0.0361450918,12.6208008 6.47121774e-16,12.7739139 0,12.929159 L0,30.1875 L0,30.1875 C0,30.6849375 0.280875,31.1390625 0.7258125,31.3621875 L19.5528096,40.7750766 C20.0467945,41.0220531 20.6474623,40.8218132 20.8944388,40.3278283 C20.963859,40.1889789 21,40.0358742 21,39.8806379 L21,22.429159 C21,22.0503869 20.7859976,21.7041238 20.4472136,21.5347318 Z"></path>
                      </g>
                    </g>
                  </g>
                </g>
              </svg>
            </div>
            <span class="nav-link-text ms-1">Category Vise Report</span>
          </a>
        </li>
         <li class="nav-item">
          <a class="nav-link" href="../pages/audit_report.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <svg width="12px" height="12px" viewBox="0 0 42 42" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                <title>box-3d-50</title>
                <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                  <g transform="translate(-2319.000000, -291.000000)" fill="#FFFFFF" fill-rule="nonzero">
                    <g transform="translate(1716.000000, 291.000000)">
                      <g transform="translate(603.000000, 0.000000)">
                        <path class="color-background" d="M22.7597136,19.3090182 L38.8987031,11.2395234 C39.3926816,10.9925342 39.592906,10.3918611 39.3459167,9.89788265 C39.249157,9.70436312 39.0922432,9.5474453 38.8987261,9.45068056 L20.2741875,0.1378125 L20.2741875,0.1378125 C19.905375,-0.04725 19.469625,-0.04725 19.0995,0.1378125 L3.1011696,8.13815822 C2.60720568,8.38517662 2.40701679,8.98586148 2.6540352,9.4798254 C2.75080129,9.67332903 2.90771305,9.83023153 3.10122239,9.9269862 L21.8652864,19.3090182 C22.1468139,19.4497819 22.4781861,19.4497819 22.7597136,19.3090182 Z"></path>
                        <path class="color-background opacity-6" d="M23.625,22.429159 L23.625,39.8805372 C23.625,40.4328219 24.0727153,40.8805372 24.625,40.8805372 C24.7802551,40.8805372 24.9333778,40.8443874 25.0722402,40.7749511 L41.2741875,32.673375 L41.2741875,32.673375 C41.719125,32.4515625 42,31.9974375 42,31.5 L42,14.241659 C42,13.6893742 41.5522847,13.241659 41,13.241659 C40.8447549,13.241659 40.6916418,13.2778041 40.5527864,13.3472318 L24.1777864,21.5347318 C23.8390024,21.7041238 23.625,22.0503869 23.625,22.429159 Z"></path>
                        <path class="color-background opacity-6" d="M20.4472136,21.5347318 L1.4472136,12.0347318 C0.953235098,11.7877425 0.352562058,11.9879669 0.105572809,12.4819454 C0.0361450918,12.6208008 6.47121774e-16,12.7739139 0,12.929159 L0,30.1875 L0,30.1875 C0,30.6849375 0.280875,31.1390625 0.7258125,31.3621875 L19.5528096,40.7750766 C20.0467945,41.0220531 20.6474623,40.8218132 20.8944388,40.3278283 C20.963859,40.1889789 21,40.0358742 21,39.8806379 L21,22.429159 C21,22.0503869 20.7859976,21.7041238 20.4472136,21.5347318 Z"></path>
                      </g>
                    </g>
                  </g>
                </g>
              </svg>
            </div>
            <span class="nav-link-text ms-1">Audit Report</span>
          </a>
        </li>
        <?php if ($isAdmin): ?>
        <li class="nav-item">
          <a class="nav-link active" href="../pages/products.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <svg width="12px" height="12px" viewBox="0 0 40 44" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                <title>document</title>
                <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                  <g transform="translate(-1870.000000, -591.000000)" fill="#FFFFFF" fill-rule="nonzero">
                    <g transform="translate(1716.000000, 291.000000)">
                      <g transform="translate(154.000000, 300.000000)">
                        <path class="color-background opacity-6" d="M40,40 L36.3636364,40 L36.3636364,3.63636364 L5.45454545,3.63636364 L5.45454545,0 L38.1818182,0 C39.1854545,0 40,0.814545455 40,1.81818182 L40,40 Z"></path>
                        <path class="color-background" d="M30.9090909,7.27272727 L1.81818182,7.27272727 C0.814545455,7.27272727 0,8.08727273 0,9.09090909 L0,41.8181818 C0,42.8218182 0.814545455,43.6363636 1.81818182,43.6363636 L30.9090909,43.6363636 C31.9127273,43.6363636 32.7272727,42.8218182 32.7272727,41.8181818 L32.7272727,9.09090909 C32.7272727,8.08727273 31.9127273,7.27272727 30.9090909,7.27272727 Z M18.1818182,34.5454545 L7.27272727,34.5454545 L7.27272727,30.9090909 L18.1818182,30.9090909 L18.1818182,34.5454545 Z M25.4545455,27.2727273 L7.27272727,27.2727273 L7.27272727,23.6363636 L25.4545455,23.6363636 L25.4545455,27.2727273 Z M25.4545455,20 L7.27272727,20 L7.27272727,16.3636364 L25.4545455,16.3636364 L25.4545455,20 Z"></path>
                      </g>
                    </g>
                  </g>
                </g>
              </svg>
            </div>
            <span class="nav-link-text ms-1">Product Management</span>
          </a>
        </li>
        <?php endif; ?>
      </ul>
    </div>
  </aside>

  <main class="main-content position-relative max-height-vh-100 h-100 border-radius-lg ">
    <!-- Navbar -->
    <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
      <div class="container-fluid py-1 px-3">
        <nav aria-label="breadcrumb">
          <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
            <li class="breadcrumb-item text-sm"><a class="opacity-5 text-dark" href="javascript:;">Admin</a></li>
            <li class="breadcrumb-item text-sm text-dark active" aria-current="page">Product Management</li>
          </ol>
        </nav>
        <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
          <div class="ms-md-auto pe-md-3 d-flex align-items-center">
            <div class="input-group">
              <span class="input-group-text text-body"><i class="fas fa-search" aria-hidden="true"></i></span>
              <input type="text" class="form-control" name="search" placeholder="Search products..." value="<?= htmlspecialchars($searchTerm) ?>" id="searchInput">
            </div>
          </div>
          <ul class="navbar-nav justify-content-end">
            <li class="nav-item d-flex align-items-center">
              <a href="logout.php" class="nav-link text-body font-weight-bold px-0">
                <i class="fa fa-sign-out-alt me-sm-1"></i>
                <span class="d-sm-inline d-none">Sign Out (<?= htmlspecialchars($_SESSION['USER']['NAME'] ?? 'User') ?>)</span>
              </a>
            </li>
            <li class="nav-item d-xl-none ps-3 d-flex align-items-center">
              <a href="javascript:;" class="nav-link text-body p-0" id="iconNavbarSidenav" onclick="toggleSidebar()">
                <div class="sidenav-toggler-inner">
                  <i class="sidenav-toggler-line"></i>
                  <i class="sidenav-toggler-line"></i>
                  <i class="sidenav-toggler-line"></i>
                </div>
              </a>
            </li>
            <li class="nav-item px-3 d-flex align-items-center">
              <a href="javascript:;" class="nav-link text-body p-0">
                <i class="fa fa-cog fixed-plugin-button-nav cursor-pointer"></i>
              </a>
            </li>
          </ul>
        </div>
      </div>
    </nav>
    <!-- End Navbar -->

    <div class="container-fluid py-4">
      <div class="row">
        <div class="col-12">
          <div class="card mb-4 border-0" style="box-shadow: 0 10px 30px rgba(0,0,0,0.05); border-radius: 16px; overflow: hidden;">
            <!-- Card Header -->
            <div class="card-header pb-0 pt-4" style="background: linear-gradient(135deg,rgb(143, 104, 5) 0%,rgb(234, 196, 99) 100%);">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <h5 class="mb-0 text-white">
                    <i class="fas fa-boxes me-2"></i>Product Management
                  </h5>
                  <p class="text-sm text-white-50 mb-2 mt-1">
                    Manage your restaurant's products - 
                    <?php if (!empty($searchTerm)): ?>
                      Found <span id="productCounter" class="fw-bold">0</span> products for "<?= htmlspecialchars($searchTerm) ?>"
                    <?php else: ?>
                      Total Products: <span id="productCounter" class="fw-bold">0</span>
                    <?php endif; ?>
                  </p>
                </div>
                <div>
                  <button class="btn btn-sm btn-light bg-white text-dark d-flex align-items-center shadow-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="fas fa-plus me-2"></i> Add New Product
                  </button>
                </div>
              </div>
                </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header bg-danger text-white border-0">
            <h5 class="modal-title text-white" id="deleteConfirmModalLabel">
              <i class="fas fa-exclamation-triangle me-2"></i>Confirm Deletion
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body text-center py-4">
            <!-- Animated Trash Icon -->
            <div class="trash-animation-container mb-4">
              <div class="trash-can">
                <div class="trash-lid"></div>
                <div class="trash-body">
                  <div class="trash-line"></div>
                  <div class="trash-line"></div>
                  <div class="trash-line"></div>
                </div>
              </div>
              <div class="delete-item">
                <i class="fas fa-cube"></i>
              </div>
            </div>
            
            <h4 class="text-danger mb-3">Are you sure?</h4>
            <p class="text-muted mb-1">You are about to delete:</p>
            <p class="fw-bold text-dark mb-3" id="deleteProductName">Product Name</p>
            <div class="alert alert-warning border-0" style="background-color: #dc3545;">
              <i class="fas fa-info-circle me-2 text-white"></i>
              <small class="text-white">This action cannot be undone. All related data will also be permanently deleted.</small>
            </div>
          </div>
          <div class="modal-footer border-0 justify-content-center pb-4">
            <button type="button" class="btn btn-secondary me-3 px-4" data-bs-dismiss="modal">
              <i class="fas fa-times me-2"></i>Cancel
            </button>
            <button type="button" class="btn btn-danger px-4" id="confirmDeleteBtn" onclick="executeDelete()">
              <span class="btn-text">
                <i class="fas fa-trash me-2"></i>Yes, Delete
              </span>
              <div class="spinner-border spinner-border-sm d-none" role="status">
                <span class="visually-hidden">Deleting...</span>
              </div>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash_message'])): ?>
              <div class="alert alert-success alert-dismissible fade show m-4" role="alert">
                <?= $_SESSION['flash_message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
              <?php unset($_SESSION['flash_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['flash_error'])): ?>
              <div class="alert alert-danger alert-dismissible fade show m-4" role="alert">
                <?= $_SESSION['flash_error'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
              <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <!-- Data Table -->
            <div class="card-body px-0 pt-0 pb-4">
          <div class="table-responsive" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
            <table class="table align-items-center mb-0" style="min-width: 700px;">
                  <thead>
                    <tr style="background-color: #f8fafd;">
                      <th class="text-uppercase text-secondary text-xs font-weight-bolder ps-4">Product</th>
                      <th class="text-uppercase text-secondary text-xs font-weight-bolder">Code</th>
                      <th class="text-uppercase text-secondary text-xs font-weight-bolder">Category</th>
                      <th class="text-uppercase text-secondary text-xs font-weight-bolder text-center">Price</th>
                      <th class="text-uppercase text-secondary text-xs font-weight-bolder text-center">Status</th>
                      <th class="text-uppercase text-secondary text-xs font-weight-bolder text-center pe-4">Actions</th>
                    </tr>
                    <tr>
                      <td colspan="6" class="p-0">
                        <div style="height: 2px; background: linear-gradient(90deg, rgba(102,126,234,0.1) 0%, rgba(102,126,234,0.5) 50%, rgba(102,126,234,0.1) 100%);"></div>
                      </td>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($products as $index => $product): ?>
                    <tr>
                      <td class="ps-4">
                        <div class="d-flex align-items-center py-3">
                          <div class="icon icon-shape icon-sm me-3 shadow text-center" style="background: linear-gradient(135deg, rgba(118,75,162,0.1) 0%, rgba(118,75,162,0.2) 100%); border-radius: 12px; width: 40px; height: 40px; line-height: 40px;">
                            <i class="fas fa-utensils text-primary" style="font-size: 0.9rem;"></i>
                          </div>
                          <div class="product-name-container">
                            <h6 class="mb-0 text-sm font-weight-bold" title="<?= htmlspecialchars($product['PRODUCTNAME']) ?>">
                              <?= htmlspecialchars($product['PRODUCTNAME']) ?>
                            </h6>
                            <p class="text-xs text-muted mb-0">ID: <?= htmlspecialchars($product['PRODUCTID']) ?></p>
                            <?php if (strlen($product['PRODUCTNAME']) > 20): ?>
                            <div class="product-name-tooltip">
                              <?= htmlspecialchars($product['PRODUCTNAME']) ?>
                            </div>
                            <?php endif; ?>
                          </div>
                        </div>
                      </td>
                      <td>
                        <span class="text-xs font-weight-bold px-3 py-2 rounded d-inline-block" style="background-color: rgba(108,117,125,0.1);">
                          <?= htmlspecialchars($product['PRODUCTCODE']) ?>
                        </span>
                      </td>
                      <td>
                        <span class="text-xs font-weight-bold px-3 py-2 rounded d-inline-block" style="background-color: rgba(13,110,253,0.1);">
                          <?= htmlspecialchars($product['CATEGORYNAME'] ?? 'N/A') ?>
                        </span>
                      </td>
                      <td class="text-center">
                        <span class="text-xs font-weight-bold px-3 py-2 rounded d-inline-block" style="background-color: rgba(25,135,84,0.1);">
                          <?= number_format($product['PRICE'] ?? 0, 2) ?>
                        </span>
                      </td>
                      <td class="text-center">
                        <span class="text-xs font-weight-bold px-3 py-2 rounded d-inline-block <?= $product['STATUS'] == 1 ? 'status-active' : 'status-inactive' ?>">
                          <?= $product['STATUS'] == 1 ? 'Active' : 'Inactive' ?>
                        </span>
                      </td>
                      <td class="text-center pe-4">
                        <div class="d-flex justify-content-center">
                          <button class="btn btn-sm btn-outline-primary me-2 px-3 shadow-sm" onclick="editProduct(<?= $product['PRODUCTID'] ?>, '<?= htmlspecialchars($product['PRODUCTNAME']) ?>', '<?= htmlspecialchars($product['PRODUCTCODE']) ?>', <?= $product['CATEGORYID'] ?? 0 ?>, <?= $product['PRICE'] ?? 0 ?>, '<?= htmlspecialchars($product['PRODUCTTYPE'] ?? 'Food') ?>', <?= $product['STATUS'] ?? 1 ?>)" data-bs-toggle="modal" data-bs-target="#editProductModal">
                            <i class="fas fa-edit me-1"></i> Edit
                          </button>
                          <button class="btn btn-sm btn-outline-danger px-3 shadow-sm" onclick="confirmDelete(<?= $product['PRODUCTID'] ?>, '<?= htmlspecialchars($product['PRODUCTNAME']) ?>')" data-bs-toggle="modal" data-bs-target="#deleteConfirmModal">
                            <i class="fas fa-trash me-1"></i> Delete
                          </button>
                        </div>
                      </td>
                    </tr>
                    
                    <?php if ($index < count($products) - 1): ?>
                    <tr>
                      <td colspan="6" class="p-0">
                        <div class="d-flex justify-content-center">
                          <div style="height: 1px; background: linear-gradient(90deg, rgba(118,75,162,0) 0%, rgba(118,75,162,0.2) 50%, rgba(118,75,162,0) 100%); width: 90%; margin: 4px 0;"></div>
                        </div>
                      </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header" style="background: linear-gradient(135deg,rgb(143, 104, 5) 0%,rgb(234, 196, 99) 100%);">
            <h5 class="modal-title text-white" id="addProductModalLabel">Add New Product</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="save_product.php" method="POST">
            <div class="modal-body">
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">Product Name</label>
                  <input type="text" class="form-control" name="name" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">Product Code</label>
                  <input type="text" class="form-control" name="product_code" required>
                </div>
              </div>
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">Category</label>
                  <select class="form-select" name="category_id" required>
                    <option value="">Select Category</option>
                    <?php foreach ($categories as $category): ?>
                      <option value="<?= $category['CATEGORYID'] ?>"><?= htmlspecialchars($category['CATEGORYNAME']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">Price</label>
                  <input type="number" step="0.01" class="form-control" name="price" required>
                </div>
              </div>
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">Product Type</label>
                  <select class="form-select" name="product_type" required>
                    <option value="Both Branch and Kitchen">Both Branch and Kitchen</option>
                    <option value="Branch">Branch</option>
                    <option value="Kitchen">Kitchen</option>
                  </select>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">Status</label>
                  <select class="form-select" name="status" required>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-primary">Save Product</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Edit Product Modal -->
    <div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header" style="background: linear-gradient(135deg,rgb(143, 104, 5) 0%,rgb(234, 196, 99) 100%);">
            <h5 class="modal-title text-white" id="editProductModalLabel">Edit Product</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="update_product.php" method="POST" id="editProductForm">
            <input type="hidden" id="edit_product_id" name="product_id">
            <div class="modal-body">
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">Product Name</label>
                  <input type="text" class="form-control" id="edit_name" name="name" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">Product Code</label>
                  <input type="text" class="form-control" id="edit_product_code" name="product_code" required>
                </div>
              </div>
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">Category</label>
                  <select class="form-select" id="edit_category_id" name="category_id" required>
                    <option value="">Select Category</option>
                    <?php foreach ($categories as $category): ?>
                      <option value="<?= $category['CATEGORYID'] ?>"><?= htmlspecialchars($category['CATEGORYNAME']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">Price</label>
                  <input type="number" step="0.01" class="form-control" id="edit_price" name="price" required>
                </div>
              </div>
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">Product Type</label>
                  <select class="form-select" id="edit_product_type" name="product_type" required>
                    <option value="Both Branch and Kitchen">Both Branch and Kitchen</option>
                    <option value="Branch">Branch</option>
                    <option value="Kitchen">Kitchen</option>
                  </select>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">Status</label>
                  <select class="form-select" id="edit_status" name="status" required>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-primary" id="updateProductBtn">
                <span class="btn-text">Update Product</span>
                <div class="spinner-border spinner-border-sm d-none" role="status">
                  <span class="visually-hidden">Loading...</span>
                </div>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <footer class="footer pt-3">
      <div class="container-fluid">
        <div class="row align-items-center justify-content-lg-between">
          <div class="col-lg-6 mb-lg-0 mb-4">
            <div class="copyright text-center text-sm text-muted text-lg-start">
              © <script>
                document.write(new Date().getFullYear())
              </script>,
              made <i class="fa fa-heart"></i> by
              <a href="" class="font-weight-bold" target="_blank">Asfand Yar</a>
              for a Halem Ghar
            </div>
          </div>
        </div>
      </div>
    </footer>
  </main>

  <!--   Core JS Files   -->
  <script src="../assets/js/core/popper.min.js"></script>
  <script src="../assets/js/core/bootstrap.min.js"></script>
  <script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
  <script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>
  <script>
    var win = navigator.platform.indexOf('Win') > -1;
    if (win && document.querySelector('#sidenav-scrollbar')) {
      var options = {
        damping: '0.5'
      }
      Scrollbar.init(document.querySelector('#sidenav-scrollbar'), options);
    }

    // Function to populate edit modal with product data
    function editProduct(productId, name, productCode, categoryId, price, productType, status) {
      document.getElementById('edit_product_id').value = productId;
      document.getElementById('edit_name').value = name;
      document.getElementById('edit_product_code').value = productCode;
      document.getElementById('edit_category_id').value = categoryId;
      document.getElementById('edit_price').value = price;
      document.getElementById('edit_product_type').value = productType;
      document.getElementById('edit_status').value = status;
    }
    
    // Global variable to store delete product ID
    let deleteProductId = null;
    
    // Function to confirm product deletion
    function confirmDelete(productId, productName) {
      deleteProductId = productId;
      document.getElementById('deleteProductName').textContent = productName;
      
      // Reset delete button state
      const deleteBtn = document.getElementById('confirmDeleteBtn');
      const btnText = deleteBtn.querySelector('.btn-text');
      const spinner = deleteBtn.querySelector('.spinner-border');
      
      deleteBtn.disabled = false;
      btnText.style.display = 'inline';
      spinner.classList.add('d-none');
    }
    
    // Function to execute the deletion
    function executeDelete() {
      if (!deleteProductId) return;
      
      const deleteBtn = document.getElementById('confirmDeleteBtn');
      const btnText = deleteBtn.querySelector('.btn-text');
      const spinner = deleteBtn.querySelector('.spinner-border');
      
      // Show loading state
      deleteBtn.disabled = true;
      btnText.style.display = 'none';
      spinner.classList.remove('d-none');
      
      // Add extra animation to trash can
      const trashCan = document.querySelector('.trash-can');
      trashCan.style.animation = 'trashShake 0.5s infinite';
      
      // Redirect to delete URL
      setTimeout(() => {
        window.location.href = `?delete_id=${deleteProductId}`;
      }, 1000); // 1 second delay for animation effect
    }

    // Animated counter function
    function animateCounter(targetValue) {
      const counter = document.getElementById('productCounter');
      let current = 0;
      const increment = Math.ceil(targetValue / 50); // Animation speed
      const timer = setInterval(() => {
        current += increment;
        if (current >= targetValue) {
          current = targetValue;
          clearInterval(timer);
        }
        counter.textContent = current;
      }, 30); // Update every 30ms
    }

    // AJAX form submission for faster updates
    function setupAjaxForms() {
      const editForm = document.getElementById('editProductForm');
      if (editForm) {
        editForm.addEventListener('submit', function(e) {
          e.preventDefault();
          
          const updateBtn = document.getElementById('updateProductBtn');
          const btnText = updateBtn.querySelector('.btn-text');
          const spinner = updateBtn.querySelector('.spinner-border');
          
          // Show loading state
          updateBtn.disabled = true;
          btnText.textContent = 'Updating...';
          spinner.classList.remove('d-none');
          
          const formData = new FormData(editForm);
          
          fetch('update_product.php', {
            method: 'POST',
            body: formData
          })
          .then(response => response.text())
          .then(data => {
            // Check if it's a redirect (successful update)
            if (data.includes('Location:') || data.trim() === '') {
              // Success - close modal and refresh page efficiently
              const modal = bootstrap.Modal.getInstance(document.getElementById('editProductModal'));
              modal.hide();
              
              // Show success message
              showFlashMessage('Product updated successfully!', 'success');
              
              // Refresh page after short delay
              setTimeout(() => {
                window.location.reload();
              }, 1000);
            } else {
              throw new Error('Update failed');
            }
          })
          .catch(error => {
            console.error('Error:', error);
            showFlashMessage('Error updating product. Please try again.', 'error');
          })
          .finally(() => {
            // Reset button state
            updateBtn.disabled = false;
            btnText.textContent = 'Update Product';
            spinner.classList.add('d-none');
          });
        });
      }
    }
    
    // Flash message system
    function showFlashMessage(message, type) {
      const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
      const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible fade show" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;">
          ${message}
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      `;
      document.body.insertAdjacentHTML('beforeend', alertHtml);
      
      // Auto-remove after 3 seconds
      setTimeout(() => {
        const alert = document.querySelector('.alert');
        if (alert) {
          alert.remove();
        }
      }, 3000);
    }

    // Start counter animation when page loads
    document.addEventListener('DOMContentLoaded', function() {
      animateCounter(<?= $totalProducts ?>);
      
      // Initialize live search
      initializeLiveSearch();
      
      // Setup AJAX forms
      setupAjaxForms();
    });

    // Live search functionality
    function initializeLiveSearch() {
      const searchInput = document.getElementById('searchInput');
      let searchTimeout;
      
      if (searchInput) {
        searchInput.addEventListener('input', function() {
          clearTimeout(searchTimeout);
          const searchValue = this.value.trim();
          
          // Debounce search to avoid too many requests
          searchTimeout = setTimeout(function() {
            if (searchValue.length >= 2 || searchValue.length === 0) {
              // Auto-submit search after 500ms delay
              if (searchValue !== '<?= htmlspecialchars($searchTerm) ?>') {
                window.location.href = 'products.php' + (searchValue ? '?search=' + encodeURIComponent(searchValue) : '');
              }
            }
          }, 500);
        });
        
        // Enable Enter key search
        searchInput.addEventListener('keypress', function(e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            this.form.submit();
          }
        });
      }
    }

    // Optimize table rendering
    function optimizeTableDisplay() {
      const table = document.querySelector('.table tbody');
      if (table && table.children.length > 50) {
        // Add virtual scrolling for large datasets
        table.style.maxHeight = '600px';
        table.style.overflowY = 'auto';
      }
    }

    // Call optimization after page load
    window.addEventListener('load', optimizeTableDisplay);

    // Mobile sidebar toggle
    function toggleSidebar() {
      const sidebar = document.getElementById('sidenav-main');
      const body = document.body;
      
      if (sidebar) {
        sidebar.classList.toggle('show');
        
        // Add overlay for mobile
        if (sidebar.classList.contains('show')) {
          const overlay = document.createElement('div');
          overlay.className = 'sidebar-overlay';
          overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1040;
          `;
          overlay.onclick = () => {
            sidebar.classList.remove('show');
            overlay.remove();
          };
          body.appendChild(overlay);
        } else {
          const overlay = document.querySelector('.sidebar-overlay');
          if (overlay) overlay.remove();
        }
      }
    }

    // Handle responsive behavior
    function handleResponsive() {
      const windowWidth = window.innerWidth;
      const sidebar = document.getElementById('sidenav-main');
      const overlay = document.querySelector('.sidebar-overlay');
      
      if (windowWidth > 1200) {
        // Desktop view - show sidebar normally
        if (sidebar) sidebar.classList.remove('show');
        if (overlay) overlay.remove();
      }
    }

    // Listen for window resize
    window.addEventListener('resize', handleResponsive);
    window.addEventListener('load', handleResponsive);

    // Enhanced tooltip functionality for long product names
    function initializeProductTooltips() {
      const productContainers = document.querySelectorAll('.product-name-container');
      
      productContainers.forEach(container => {
        const tooltip = container.querySelector('.product-name-tooltip');
        if (tooltip) {
          let timeoutId;
          
          container.addEventListener('mouseenter', () => {
            timeoutId = setTimeout(() => {
              tooltip.style.display = 'block';
            }, 500);
          });
          
          container.addEventListener('mouseleave', () => {
            clearTimeout(timeoutId);
            tooltip.style.display = 'none';
          });
          
          // Touch devices
          container.addEventListener('touchstart', () => {
            tooltip.style.display = tooltip.style.display === 'block' ? 'none' : 'block';
          });
        }
      });
    }

    // Initialize tooltips after page load
    window.addEventListener('load', initializeProductTooltips);
  </script>
  <!-- Control Center for Soft Dashboard: parallax effects, scripts for the example pages etc -->
  <script src="../assets/js/soft-ui-dashboard.min.js?v=1.1.0"></script>
</body>
</html>