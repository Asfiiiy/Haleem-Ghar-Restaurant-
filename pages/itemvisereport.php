<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/db.php';
$conn = get_db_connection();

// Check if user is admin
$isAdmin = (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin');

// Initialize filters with proper sanitization
$selected_product = $_GET['product'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$selected_product_name = '';

// Format dates for display (keeping original format for query)
$display_start_date = $start_date ? date('m/d/Y', strtotime($start_date)) : '';
$display_end_date = $end_date ? date('m/d/Y', strtotime($end_date)) : '';

// Get all branches with proper error handling
$allBranches = [];
$branchQuery = "SELECT BRANCHID, BRANCHCODE, BRANCHNAME FROM tblBranches ORDER BY BRANCHNAME";
$stmtBranches = sqlsrv_query($conn, $branchQuery);
if ($stmtBranches === false) {
    die(print_r(sqlsrv_errors(), true));
}
while ($row = sqlsrv_fetch_array($stmtBranches, SQLSRV_FETCH_ASSOC)) {
    $allBranches[] = $row;
}
sqlsrv_free_stmt($stmtBranches);

// Get all products with proper error handling
$allProducts = [];
$productQuery = "SELECT PRODUCTID, NAME FROM tblProducts ORDER BY NAME";
$stmtProducts = sqlsrv_query($conn, $productQuery);
if ($stmtProducts === false) {
    die(print_r(sqlsrv_errors(), true));
}
while ($row = sqlsrv_fetch_array($stmtProducts, SQLSRV_FETCH_ASSOC)) {
    $allProducts[] = $row;
    
    // Get selected product name if filtered
    if ($selected_product && $row['PRODUCTID'] == $selected_product) {
        $selected_product_name = $row['NAME'];
    }
}
sqlsrv_free_stmt($stmtProducts);

// Initialize parameters array for new query structure
$params = [];

// Main query to fetch ALL products (including those with no sales) with proper table aliases
$query = "
    SELECT 
        p.PRODUCTID,
        p.NAME AS PRODUCTNAME,
        b.BRANCHCODE,
        b.BRANCHNAME,
        ISNULL(SUM(CASE WHEN h.STATUS = 'Close' THEN d.QUANTITY ELSE 0 END), 0) AS TOTAL_QTY,
        ISNULL(SUM(CASE WHEN h.STATUS = 'Close' THEN d.AMOUNT ELSE 0 END), 0) AS TOTAL_AMOUNT
    FROM tblProducts p
    CROSS JOIN tblBranches b
    LEFT JOIN tblSalesInvoiceDetail d ON p.PRODUCTID = d.ITEMID
    LEFT JOIN tblSalesInvoiceHeader h ON d.INVOICEID = h.INVOICEID AND h.BRANCHID = b.BRANCHID";

// Add role-based filtering for branches
if (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) !== 'admin') {
    if (!empty($_SESSION['USER']['BRANCH_ID'])) {
        $query .= " AND b.BRANCHID = ?";
        $params[] = $_SESSION['USER']['BRANCH_ID'];
    } else {
        $query .= " AND 1 = 0"; // Non-admin with no branch sees nothing
    }
}

// Add WHERE clause before adding conditions
$query .= " WHERE 1=1";

// Add date filtering
if (!empty($start_date)) {
    $query .= " AND (h.INVOICEID IS NULL OR CAST(h.INVOICEDATE AS DATE) >= CAST(? AS DATE))";
    $params[] = $start_date;
}
if (!empty($end_date)) {
    $query .= " AND (h.INVOICEID IS NULL OR CAST(h.INVOICEDATE AS DATE) <= CAST(? AS DATE))";
    $params[] = $end_date;
}

// Add status filter for closed orders
$query .= " AND (h.INVOICEID IS NULL OR h.STATUS = 'Close')";

// Add product filtering
if (!empty($selected_product)) {
    $query .= " AND p.PRODUCTID = ?";
    $params[] = $selected_product;
}

$query .= "
    GROUP BY p.PRODUCTID, p.NAME, b.BRANCHCODE, b.BRANCHNAME
    ORDER BY p.NAME, b.BRANCHNAME
";

$salesData = [];
// Always pass $params, even if empty
$stmtSales = sqlsrv_query($conn, $query, $params);
if ($stmtSales === false) {
    die(print_r(sqlsrv_errors(), true));
}

while ($row = sqlsrv_fetch_array($stmtSales, SQLSRV_FETCH_ASSOC)) {
    $salesData[] = $row;
}
sqlsrv_free_stmt($stmtSales);

// Process data into report format
$productReport = [];
$branchTotals = [];
$grandTotal = ['QTY' => 0, 'AMOUNT' => 0];

// Determine visible branches based on user role
$visibleBranches = $allBranches; // Default to all branches for admin
$userBranch = null;

if (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) !== 'admin') {
    if (!empty($_SESSION['USER']['BRANCH_ID'])) {
        // For non-admin users, show only their branch
        $userBranchId = $_SESSION['USER']['BRANCH_ID'];
        $visibleBranches = array_filter($allBranches, function($branch) use ($userBranchId) {
            return $branch['BRANCHID'] == $userBranchId;
        });
        
        // Get the user's branch details
        foreach ($allBranches as $branch) {
            if ($branch['BRANCHID'] == $userBranchId) {
                $userBranch = $branch;
                break;
            }
        }
    } else {
        $visibleBranches = []; // No branch assigned
    }
}

// Initialize branch totals array with visible branches only
foreach ($visibleBranches as $branch) {
    $branchTotals[$branch['BRANCHCODE']] = [
        'NAME' => $branch['BRANCHNAME'],
        'QTY' => 0,
        'AMOUNT' => 0
    ];
}

foreach ($salesData as $item) {
    $productId = $item['PRODUCTID'];
    $branchCode = $item['BRANCHCODE'];

    if (!isset($productReport[$productId])) {
        $productReport[$productId] = [
            'NAME' => $item['PRODUCTNAME'],
            'BRANCHES' => [],
            'TOTAL_QTY' => 0,
            'TOTAL_AMOUNT' => 0
        ];
        
        // Initialize only visible branches with zero values
        foreach ($visibleBranches as $branch) {
            $productReport[$productId]['BRANCHES'][$branch['BRANCHCODE']] = [
                'QTY' => 0,
                'AMOUNT' => 0
            ];
        }
    }

    // Only process data for visible branches
    if (isset($productReport[$productId]['BRANCHES'][$branchCode])) {
        $productReport[$productId]['BRANCHES'][$branchCode]['QTY'] = $item['TOTAL_QTY'];
        $productReport[$productId]['BRANCHES'][$branchCode]['AMOUNT'] = $item['TOTAL_AMOUNT'];
        $productReport[$productId]['TOTAL_QTY'] += $item['TOTAL_QTY'];
        $productReport[$productId]['TOTAL_AMOUNT'] += $item['TOTAL_AMOUNT'];

        if (isset($branchTotals[$branchCode])) {
            $branchTotals[$branchCode]['QTY'] += $item['TOTAL_QTY'];
            $branchTotals[$branchCode]['AMOUNT'] += $item['TOTAL_AMOUNT'];
        }

        $grandTotal['QTY'] += $item['TOTAL_QTY'];
        $grandTotal['AMOUNT'] += $item['TOTAL_AMOUNT'];
    }
}

// Excel export logic
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    exportToExcel($productReport, $visibleBranches, $branchTotals, $grandTotal, $display_start_date, $display_end_date, $selected_product_name);
    exit;
}

function exportToExcel($productReport, $visibleBranches, $branchTotals, $grandTotal, $start_date, $end_date, $product_name) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Product_Sales_Report_'.date('Ymd_His').'.csv"');
    header('Cache-Control: max-age=0');

    // Create CSV content
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Check user role for different export formats
    $isAdmin = isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin';
    
    if ($isAdmin) {
        // Admin export - includes all branch columns (no row numbers)
        $headers = array('Product');
        foreach ($visibleBranches as $branch) {
            $headers[] = $branch['BRANCHNAME'] . ' Qty';
            $headers[] = $branch['BRANCHNAME'] . ' Amount';
        }
        $headers[] = 'Total Qty';
        $headers[] = 'Total Amount';
        
        fputcsv($output, $headers);
        
        // Product rows (no row numbers)
        foreach ($productReport as $product) {
            $row = array($product['NAME']);
            
            foreach ($visibleBranches as $branch) {
                $branchCode = $branch['BRANCHCODE'];
                $row[] = $product['BRANCHES'][$branchCode]['QTY'];
                $row[] = number_format($product['BRANCHES'][$branchCode]['AMOUNT'], 2);
            }
            
            $row[] = $product['TOTAL_QTY'];
            $row[] = number_format($product['TOTAL_AMOUNT'], 2);
            
            fputcsv($output, $row);
        }
        
        // Branch totals row
        $totalRow = array('Branch Totals');
        foreach ($visibleBranches as $branch) {
            $branchCode = $branch['BRANCHCODE'];
            $totalRow[] = $branchTotals[$branchCode]['QTY'];
            $totalRow[] = number_format($branchTotals[$branchCode]['AMOUNT'], 2);
        }
        $totalRow[] = $grandTotal['QTY'];
        $totalRow[] = number_format($grandTotal['AMOUNT'], 2);
        
        fputcsv($output, $totalRow);
        
    } else {
        // Non-admin export - only total columns (no row numbers)
        $headers = array('Product', 'Total Qty', 'Total Amount');
        fputcsv($output, $headers);
        
        // Product rows (no row numbers)
        foreach ($productReport as $product) {
            $row = array(
                $product['NAME'],
                $product['TOTAL_QTY'],
                number_format($product['TOTAL_AMOUNT'], 2)
            );
            
            fputcsv($output, $row);
        }
        
        // Totals row
        $totalRow = array('Totals', $grandTotal['QTY'], number_format($grandTotal['AMOUNT'], 2));
        fputcsv($output, $totalRow);
    }
    
    fclose($output);
    exit;
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
   Item Vise Report
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
  <!-- Nepcha Analytics (nepcha.com) -->
  <!-- Nepcha is a easy-to-use web analytics. No cookies and fully compliant with GDPR, CCPA and PECR. -->
  <script defer data-site="YOUR_DOMAIN_HERE" src="https://api.nepcha.com/js/nepcha-analytics.js"></script>
</head>

<body class="g-sidenav-show  bg-gray-100">
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
          <a class="nav-link  active" href="../pages/itemvisereport.php">
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
          <a class="nav-link" href="../pages/products.php">
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
            <li class="breadcrumb-item text-sm"><a class="opacity-5 text-dark" href="javascript:;">Pages</a></li>
            <li class="breadcrumb-item text-sm text-dark active" aria-current="page">Item Vise Report</li>
          </ol>
          <h6 class="font-weight-bolder mb-0">Item Vise Report</h6>
        </nav>
        <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
          <div class="ms-md-auto pe-md-3 d-flex align-items-center">
            <div class="input-group">
              <span class="input-group-text text-body"><i class="fas fa-search" aria-hidden="true"></i></span>
              <input type="text" class="form-control" placeholder="Type here...">
            </div>
          </div>
          <ul class="navbar-nav  justify-content-end">
            <li class="nav-item d-flex align-items-center">
              <!-- <a class="btn btn-outline-primary btn-sm mb-0 me-3" target="_blank" href="https://www.creative-tim.com/builder?ref=navbar-soft-ui-dashboard">Online Builder</a> -->
            </li>
           <li class="nav-item d-flex align-items-center">
                <a href="logout.php" class="nav-link text-body font-weight-bold px-0">
                    <i class="fa fa-sign-out-alt me-sm-1"></i>
                    <span class="d-sm-inline d-none">Sign Out (<?= htmlspecialchars($_SESSION['USERNAME'] ?? 'User') ?>)</span>
                </a>
            </li>
            <li class="nav-item d-xl-none ps-3 d-flex align-items-center">
              <a href="javascript:;" class="nav-link text-body p-0" id="iconNavbarSidenav">
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
            <li class="nav-item dropdown pe-2 d-flex align-items-center">
              <a href="javascript:;" class="nav-link text-body p-0" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fa fa-bell cursor-pointer"></i>
              </a>
              <ul class="dropdown-menu  dropdown-menu-end  px-2 py-3 me-sm-n4" aria-labelledby="dropdownMenuButton">
                <li class="mb-2">
                  <a class="dropdown-item border-radius-md" href="javascript:;">
                    <div class="d-flex py-1">
                      <div class="my-auto">
                        <img src="../assets/img/team-2.jpg" class="avatar avatar-sm  me-3 ">
                      </div>
                      <div class="d-flex flex-column justify-content-center">
                        <h6 class="text-sm font-weight-normal mb-1">
                          <span class="font-weight-bold">New message</span> from Laur
                        </h6>
                        <p class="text-xs text-secondary mb-0 ">
                          <i class="fa fa-clock me-1"></i>
                          13 minutes ago
                        </p>
                      </div>
                    </div>
                  </a>
                </li>
                <li class="mb-2">
                  <a class="dropdown-item border-radius-md" href="javascript:;">
                    <div class="d-flex py-1">
                      <div class="my-auto">
                        <img src="../assets/img/small-logos/logo-spotify.svg" class="avatar avatar-sm bg-gradient-dark  me-3 ">
                      </div>
                      <div class="d-flex flex-column justify-content-center">
                        <h6 class="text-sm font-weight-normal mb-1">
                          <span class="font-weight-bold">New album</span> by Travis Scott
                        </h6>
                        <p class="text-xs text-secondary mb-0 ">
                          <i class="fa fa-clock me-1"></i>
                          1 day
                        </p>
                      </div>
                    </div>
                  </a>
                </li>
                <li>
                  <a class="dropdown-item border-radius-md" href="javascript:;">
                    <div class="d-flex py-1">
                      <div class="avatar avatar-sm bg-gradient-secondary  me-3  my-auto">
                        <svg width="12px" height="12px" viewBox="0 0 43 36" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
                          <title>credit-card</title>
                          <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                            <g transform="translate(-2169.000000, -745.000000)" fill="#FFFFFF" fill-rule="nonzero">
                              <g transform="translate(1716.000000, 291.000000)">
                                <g transform="translate(453.000000, 454.000000)">
                                  <path class="color-background" d="M43,10.7482083 L43,3.58333333 C43,1.60354167 41.3964583,0 39.4166667,0 L3.58333333,0 C1.60354167,0 0,1.60354167 0,3.58333333 L0,10.7482083 L43,10.7482083 Z" opacity="0.593633743"></path>
                                  <path class="color-background" d="M0,16.125 L0,32.25 C0,34.2297917 1.60354167,35.8333333 3.58333333,35.8333333 L39.4166667,35.8333333 C41.3964583,35.8333333 43,34.2297917 43,32.25 L43,16.125 L0,16.125 Z M19.7083333,26.875 L7.16666667,26.875 L7.16666667,23.2916667 L19.7083333,23.2916667 L19.7083333,26.875 Z M35.8333333,26.875 L28.6666667,26.875 L28.6666667,23.2916667 L35.8333333,23.2916667 L35.8333333,26.875 Z"></path>
                                </g>
                              </g>
                            </g>
                          </g>
                        </svg>
                      </div>
                      <div class="d-flex flex-column justify-content-center">
                        <h6 class="text-sm font-weight-normal mb-1">
                          Payment successfully completed
                        </h6>
                        <p class="text-xs text-secondary mb-0 ">
                          <i class="fa fa-clock me-1"></i>
                          2 days
                        </p>
                      </div>
                    </div>
                  </a>
                </li>
              </ul>
            </li>
          </ul>
        </div>
      </div>
    </nav>
    <div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header pb-0">
          <div class="d-flex justify-content-between align-items-center">
            <h6>Product Sales Report</h6>
            <div>
              <a href="?page=product_report&export=excel<?= !empty($selected_product) ? '&product='.urlencode($selected_product) : '' ?><?= !empty($start_date) ? '&start_date='.urlencode($start_date) : '' ?><?= !empty($end_date) ? '&end_date='.urlencode($end_date) : '' ?>" 
                 class="btn btn-success btn-sm">
                <i class="fas fa-file-excel me-1"></i> Export to Excel
              </a>
            </div>
          </div>
          <p class="text-sm mb-0">Showing only closed orders</p>
        </div>
        
        <!-- Filter Form -->
        <div class="card-body pt-0">
          <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="product_report">
            <div class="col-md-3">
              <label class="form-label">Product</label>
              <select class="form-select form-select-sm" name="product">
                <option value="">All Products</option>
                <?php foreach ($allProducts as $product): ?>
                  <option value="<?= $product['PRODUCTID'] ?>" <?= $selected_product == $product['PRODUCTID'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($product['NAME']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
  <label class="form-label">From Date</label>
  <input type="date" class="form-control form-control-sm" name="start_date" 
         value="<?= !empty($start_date) ? htmlspecialchars($start_date) : '' ?>">
</div>
<div class="col-md-2">
  <label class="form-label">To Date</label>
  <input type="date" class="form-control form-control-sm" name="end_date" 
         value="<?= !empty($end_date) ? htmlspecialchars($end_date) : '' ?>">
</div>
            <div class="col-md-2">
              <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
            </div>
            <div class="col-md-2">
              <a href="?page=product_report" class="btn btn-outline-secondary btn-sm w-100">Clear</a>
            </div>
          </form>
          
          <?php if (!empty($start_date) || !empty($end_date) || !empty($selected_product)): ?>
<div class="alert alert-info mt-3 mb-0 py-2">
  <i class="fas fa-filter me-2"></i>
  <?php if (!empty($selected_product)): ?>
    Showing products: <strong><?= htmlspecialchars($selected_product_name) ?></strong>
    <?php if (!empty($start_date) || !empty($end_date)): ?> | <?php endif; ?>
  <?php endif; ?>
  <?php if (!empty($start_date) || !empty($end_date)): ?>
    Date range: 
    <?php if (!empty($start_date)): ?>
      <strong><?= htmlspecialchars($display_start_date) ?></strong>
      <?php if (!empty($end_date)): ?> to <strong><?= htmlspecialchars($display_end_date) ?></strong>
      <?php else: ?> onwards<?php endif; ?>
    <?php else: ?>
      up to <strong><?= htmlspecialchars($display_end_date) ?></strong>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>
        </div>
        
        <!-- Data Table -->
        <div class="card-body px-0 pt-0 pb-2">
          <div class="table-responsive p-0">
          <table class="table align-items-center mb-0">
            <thead>
                <tr>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 border-bottom">#</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 border-bottom">Product</th>
                    <?php if (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin'): ?>
                        <?php foreach ($visibleBranches as $branch): ?>
                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center border-bottom" colspan="2">
                                <?= htmlspecialchars($branch['BRANCHNAME']) ?>
                            </th>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 border-bottom">Total Qty</th>
                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 border-bottom">Total Amount</th>
                </tr>
                <tr>
                    <th class="border-bottom-0"></th>
                    <th class="border-bottom-0"></th>
                    <?php if (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin'): ?>
                        <?php foreach ($visibleBranches as $branch): ?>
                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 border-bottom">Qty</th>
                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 border-bottom">Amount</th>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <th class="border-bottom-0"></th>
                    <th class="border-bottom-0"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($productReport)): ?>
                    <tr>
                        <td colspan="<?= (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin') ? count($visibleBranches)*2 + 4 : 4 ?>" class="text-center py-4">
                            <p class="text-xs font-weight-bold mb-0">No data found for selected filters</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $rowNumber = 1;
                    foreach ($productReport as $product): ?>
                        <tr>
                            <td class="border-bottom">
                                <div class="d-flex px-2 py-1">
                                    <div class="d-flex flex-column justify-content-center">
                                        <h6 class="mb-0 text-sm font-weight-bold"><?= $rowNumber ?></h6>
                                    </div>
                                </div>
                            </td>
                            <td class="border-bottom">
                                <div class="d-flex px-2 py-1">
                                    <div class="d-flex flex-column justify-content-center">
                                        <h6 class="mb-0 text-sm"><?= htmlspecialchars($product['NAME']) ?></h6>
                                    </div>
                                </div>
                            </td>
                            <?php if (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin'): ?>
                                <?php foreach ($visibleBranches as $branch): ?>
                                    <td class="border-bottom">
                                        <p class="text-xs font-weight-bold mb-0 text-center"><?= isset($product['BRANCHES'][$branch['BRANCHCODE']]) ? number_format($product['BRANCHES'][$branch['BRANCHCODE']]['QTY']) : '0' ?></p>
                                    </td>
                                    <td class="border-bottom">
                                        <p class="text-xs font-weight-bold mb-0 text-center"><?= isset($product['BRANCHES'][$branch['BRANCHCODE']]) ? number_format($product['BRANCHES'][$branch['BRANCHCODE']]['AMOUNT'], 2) : '0.00' ?></p>
                                    </td>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <td class="border-bottom">
                                <p class="text-xs font-weight-bold mb-0 text-center"><?= number_format($product['TOTAL_QTY']) ?></p>
                            </td>
                            <td class="border-bottom">
                                <p class="text-xs font-weight-bold mb-0 text-center"><?= number_format($product['TOTAL_AMOUNT'], 2) ?></p>
                            </td>
                        </tr>
                    <?php 
                    $rowNumber++;
                    endforeach; ?>
                    <?php if (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin'): ?>
                        <tr class="bg-light">
                            <td class="font-weight-bolder border-top"></td>
                            <td class="font-weight-bolder border-top">Branch Totals</td>
                            <?php foreach ($visibleBranches as $branch): ?>
                                <td class="font-weight-bolder border-top text-center"><?= number_format($branchTotals[$branch['BRANCHCODE']]['QTY'] ?? 0) ?></td>
                                <td class="font-weight-bolder border-top text-center"><?= number_format($branchTotals[$branch['BRANCHCODE']]['AMOUNT'] ?? 0, 2) ?></td>
                            <?php endforeach; ?>
                            <td class="font-weight-bolder border-top text-center"><?= number_format($grandTotal['QTY'] ?? 0) ?></td>
                            <td class="font-weight-bolder border-top text-center"><?= number_format($grandTotal['AMOUNT'] ?? 0, 2) ?></td>
                        </tr>
                    <?php else: ?>
                        <tr class="bg-light">
                            <td class="font-weight-bolder border-top"></td>
                            <td class="font-weight-bolder border-top">Totals</td>
                            <td class="font-weight-bolder border-top text-center"><?= number_format($grandTotal['QTY'] ?? 0) ?></td>
                            <td class="font-weight-bolder border-top text-center"><?= number_format($grandTotal['AMOUNT'] ?? 0, 2) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endif; ?>
            </tbody>
            </table>
            </div>
          </div>
      </div>
    </div>
  </div>
</div>
    <!-- End Navbar -->
    <!-- <div class="container-fluid py-4"> -->
     
     
     <footer class="footer pt-3  ">
        <div class="container-fluid">
          <div class="row align-items-center justify-content-lg-between">
            <div class="col-lg-6 mb-lg-0 mb-4">
              <div class="copyright text-center text-sm text-muted text-lg-start">
                © <script>
                  document.write(new Date().getFullYear())
                </script>,
                made  <i class="fa fa-heart"></i> by
                <a href="" class="font-weight-bold" target="_blank">Asfand Yar</a>
                for a Halem Ghar
              </div>
            </div>
             
          </div>
        </div>
      </footer>
    </div>
  </main>
  <div class="fixed-plugin">
    <a class="fixed-plugin-button text-dark position-fixed px-3 py-2">
      <i class="fa fa-cog py-2"> </i>
    </a>
    <div class="card shadow-lg ">
      <div class="card-header pb-0 pt-3 ">
        <div class="float-start">
          <h5 class="mt-3 mb-0">Change Sidebar Colors</h5>
          <p>See our dashboard options.</p>
        </div>
        <div class="float-end mt-4">
          <button class="btn btn-link text-dark p-0 fixed-plugin-close-button">
            <i class="fa fa-close"></i>
          </button>
        </div>
        <!-- End Toggle Button -->
      </div>
      <hr class="horizontal dark my-1">
      <div class="card-body pt-sm-3 pt-0">
        <!-- Sidebar Backgrounds -->
        <div>
          <h6 class="mb-0">Sidebar Colors</h6>
        </div>
        <a href="javascript:void(0)" class="switch-trigger background-color">
          <div class="badge-colors my-2 text-start">
            <span class="badge filter bg-primary active" data-color="primary" onclick="sidebarColor(this)"></span>
            <span class="badge filter bg-gradient-dark" data-color="dark" onclick="sidebarColor(this)"></span>
            <span class="badge filter bg-gradient-info" data-color="info" onclick="sidebarColor(this)"></span>
            <span class="badge filter bg-gradient-success" data-color="success" onclick="sidebarColor(this)"></span>
            <span class="badge filter bg-gradient-warning" data-color="warning" onclick="sidebarColor(this)"></span>
            <span class="badge filter bg-gradient-danger" data-color="danger" onclick="sidebarColor(this)"></span>
          </div>
        </a>
        <!-- Sidenav Type -->
        <div class="mt-3">
          <h6 class="mb-0">Sidenav Type</h6>
          <p class="text-sm">Choose between 2 different sidenav types.</p>
        </div>
        <div class="d-flex">
          <button class="btn btn-primary w-100 px-3 mb-2 active" data-class="bg-transparent" onclick="sidebarType(this)">Transparent</button>
          <button class="btn btn-primary w-100 px-3 mb-2 ms-2" data-class="bg-white" onclick="sidebarType(this)">White</button>
        </div>
        <p class="text-sm d-xl-none d-block mt-2">You can change the sidenav type just on desktop view.</p>
        <!-- Navbar Fixed -->
        <div class="mt-3">
          <h6 class="mb-0">Navbar Fixed</h6>
        </div>
        <div class="form-check form-switch ps-0">
          <input class="form-check-input mt-1 ms-auto" type="checkbox" id="navbarFixed" onclick="navbarFixed(this)">
        </div>
        <hr class="horizontal dark my-sm-4">
        <!-- <a class="btn bg-gradient-dark w-100" href="https://www.creative-tim.com/product/soft-ui-dashboard">Free Download</a>
        <a class="btn btn-outline-dark w-100" href="https://www.creative-tim.com/learning-lab/bootstrap/license/soft-ui-dashboard">View documentation</a>
        <div class="w-100 text-center">
          <a class="github-button" href="https://github.com/creativetimofficial/soft-ui-dashboard" data-icon="octicon-star" data-size="large" data-show-count="true" aria-label="Star creativetimofficial/soft-ui-dashboard on GitHub">Star</a>
          <h6 class="mt-3">Thank you for sharing!</h6>
          <a href="https://twitter.com/intent/tweet?text=Check%20Soft%20UI%20Dashboard%20made%20by%20%40CreativeTim%20%23webdesign%20%23dashboard%20%23bootstrap5&amp;url=https%3A%2F%2Fwww.creative-tim.com%2Fproduct%2Fsoft-ui-dashboard" class="btn btn-dark mb-0 me-2" target="_blank">
            <i class="fab fa-twitter me-1" aria-hidden="true"></i> Tweet
          </a>
          <a href="https://www.facebook.com/sharer/sharer.php?u=https://www.creative-tim.com/product/soft-ui-dashboard" class="btn btn-dark mb-0 me-2" target="_blank">
            <i class="fab fa-facebook-square me-1" aria-hidden="true"></i> Share
          </a> -->
        </div>
      </div>
    </div>
  </div>
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
  </script>
  <!-- Github buttons -->
  <script async defer src="https://buttons.github.io/buttons.js"></script>
  <!-- Control Center for Soft Dashboard: parallax effects, scripts for the example pages etc -->
  <script src="../assets/js/soft-ui-dashboard.min.js?v=1.1.0"></script>
</body>

</html>