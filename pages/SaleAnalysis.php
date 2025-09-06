<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/db.php';
$conn = get_db_connection();

// Check if user is admin
$isAdmin = (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin');

// Check if Excel export is requested
if (isset($_GET['export'])) {
    exportToExcel();
    exit;
}

// Initialize filters
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$searchTerm = $_GET['search'] ?? '';

// Build WHERE clause
$whereClauses = ["h.STATUS = 'Close'"];
$params = array();

// Role-based filtering
if (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) !== 'admin') {
    if (!empty($_SESSION['USER']['BRANCH_ID'])) {
        $whereClauses[] = 'h.BRANCHID = ?';
        $params[] = $_SESSION['USER']['BRANCH_ID'];
    } else {
        $whereClauses[] = '1 = 0'; // Show nothing if non-admin with no branch
    }
}

// Date filtering
if (!empty($startDate)) {
    $whereClauses[] = 'CONVERT(date, h.INVOICEDATE) >= ?';
    $params[] = $startDate;
}
if (!empty($endDate)) {
    $whereClauses[] = 'CONVERT(date, h.INVOICEDATE) <= ?';
    $params[] = $endDate;
}

// Search filtering
if (!empty($searchTerm)) {
    $whereClauses[] = 'b.BRANCHNAME LIKE ?';
    $params[] = '%' . $searchTerm . '%';
}

$where = implode(' AND ', $whereClauses);

// Modified query to include branch name
$sql = "
    SELECT 
        b.BRANCHNAME,
        h.BRANCHCODE,
        SUM(h.TAX) AS TOTAL_TAX,
        SUM(h.SERVICECHARGES) AS TOTAL_SERVICE,
        SUM(h.FOODCHARGES) AS TOTAL_FOOD,
        SUM(h.NETAMOUNT) AS TOTAL_NET
    FROM tblSalesInvoiceHeader h
    JOIN tblBranches b ON h.BRANCHID = b.BRANCHID
    WHERE $where
    GROUP BY b.BRANCHNAME, h.BRANCHCODE
    ORDER BY b.BRANCHNAME
";

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

$branches = array();
$grandTotal = ['TAX' => 0, 'SERVICE' => 0, 'FOOD' => 0, 'NET' => 0];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $branches[] = $row;
    // Remove these lines to prevent double totaling
    // $grandTotal['TAX'] += $row['TOTAL_TAX'];
    // $grandTotal['SERVICE'] += $row['TOTAL_SERVICE'];
    // $grandTotal['FOOD'] += $row['TOTAL_FOOD'];
    // $grandTotal['NET'] += $row['TOTAL_NET'];
}
sqlsrv_free_stmt($stmt);

// Calculate totals separately
$totalsQuery = "
    SELECT 
        SUM(h.TAX) AS GRAND_TAX,
        SUM(h.SERVICECHARGES) AS GRAND_SERVICE,
        SUM(h.FOODCHARGES) AS GRAND_FOOD,
        SUM(h.NETAMOUNT) AS GRAND_NET
    FROM tblSalesInvoiceHeader h
    JOIN tblBranches b ON h.BRANCHID = b.BRANCHID
    WHERE $where
";

$totalsStmt = sqlsrv_query($conn, $totalsQuery, $params);
if ($totalsStmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

if ($totalsRow = sqlsrv_fetch_array($totalsStmt, SQLSRV_FETCH_ASSOC)) {
    $grandTotal = [
        'TAX' => $totalsRow['GRAND_TAX'],
        'SERVICE' => $totalsRow['GRAND_SERVICE'],
        'FOOD' => $totalsRow['GRAND_FOOD'],
        'NET' => $totalsRow['GRAND_NET']
    ];
}
sqlsrv_free_stmt($totalsStmt);

function exportToExcel() {
    global $conn, $where, $params, $startDate, $endDate, $searchTerm;
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Sales_Report_'.date('Ymd').'.csv"');
    header('Cache-Control: max-age=0');
    
    // Build WHERE clause properly for export
    $whereClauses = ["h.STATUS = 'Close'"];
    $exportParams = array();

    // Role-based filtering
    if (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) !== 'admin') {
        if (!empty($_SESSION['USER']['BRANCH_ID'])) {
            $whereClauses[] = 'h.BRANCHID = ?';
            $exportParams[] = $_SESSION['USER']['BRANCH_ID'];
        } else {
            $whereClauses[] = '1 = 0'; // Show nothing if non-admin with no branch
        }
    }

    // Date filtering
    if (!empty($startDate)) {
        $whereClauses[] = 'CONVERT(date, h.INVOICEDATE) >= ?';
        $exportParams[] = $startDate;
    }
    if (!empty($endDate)) {
        $whereClauses[] = 'CONVERT(date, h.INVOICEDATE) <= ?';
        $exportParams[] = $endDate;
    }

    // Search filtering
    if (!empty($searchTerm)) {
        $whereClauses[] = 'b.BRANCHNAME LIKE ?';
        $exportParams[] = '%' . $searchTerm . '%';
    }

    $whereClause = implode(' AND ', $whereClauses);
    
    // Same query as above for export
    $sql = "
        SELECT 
            b.BRANCHNAME,
            h.BRANCHCODE,
            SUM(h.TAX) AS TOTAL_TAX,
            SUM(h.SERVICECHARGES) AS TOTAL_SERVICE,
            SUM(h.FOODCHARGES) AS TOTAL_FOOD,
            SUM(h.NETAMOUNT) AS TOTAL_NET
        FROM tblSalesInvoiceHeader h
        JOIN tblBranches b ON h.BRANCHID = b.BRANCHID
        WHERE $whereClause
        GROUP BY b.BRANCHNAME, h.BRANCHCODE
        ORDER BY b.BRANCHNAME
    ";
    
    $stmt = sqlsrv_query($conn, $sql, $exportParams);
    if ($stmt === false) {
        die(print_r(sqlsrv_errors(), true));
    }
    
    $branches = array();
    $grandTotal = ['TAX' => 0, 'SERVICE' => 0, 'FOOD' => 0, 'NET' => 0];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $branches[] = $row;
    }
    
    // Get totals separately for export
    $totalsQuery = "
        SELECT 
            SUM(h.TAX) AS GRAND_TAX,
            SUM(h.SERVICECHARGES) AS GRAND_SERVICE,
            SUM(h.FOODCHARGES) AS GRAND_FOOD,
            SUM(h.NETAMOUNT) AS GRAND_NET
        FROM tblSalesInvoiceHeader h
        JOIN tblBranches b ON h.BRANCHID = b.BRANCHID
        WHERE $whereClause
    ";
    
    $totalsStmt = sqlsrv_query($conn, $totalsQuery, $exportParams);
    if ($totalsStmt === false) {
        die(print_r(sqlsrv_errors(), true));
    }
    
    if ($totalsRow = sqlsrv_fetch_array($totalsStmt, SQLSRV_FETCH_ASSOC)) {
        $grandTotal = [
            'TAX' => $totalsRow['GRAND_TAX'],
            'SERVICE' => $totalsRow['GRAND_SERVICE'],
            'FOOD' => $totalsRow['GRAND_FOOD'],
            'NET' => $totalsRow['GRAND_NET']
        ];
    }
    
    sqlsrv_free_stmt($stmt);
    sqlsrv_free_stmt($totalsStmt);
    
    // Create CSV content
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header row
    fputcsv($output, array('Branch', 'Tax', 'Service', 'Food', 'Net Amount'));
    
    // Data rows
    foreach ($branches as $row) {
        fputcsv($output, array(
            $row['BRANCHNAME'],
            number_format($row['TOTAL_TAX'], 2),
            number_format($row['TOTAL_SERVICE'], 2),
            number_format($row['TOTAL_FOOD'], 2),
            number_format($row['TOTAL_NET'], 2)
        ));
    }
    
    // Total row
    fputcsv($output, array(
        'Total',
        number_format($grandTotal['TAX'], 2),
        number_format($grandTotal['SERVICE'], 2),
        number_format($grandTotal['FOOD'], 2),
        number_format($grandTotal['NET'], 2)
    ));
    
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
    Sale Analysis 
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
</style>

</style>
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
          <a class="nav-link  active" href="../pages/SaleAnalysis.php">
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
            <li class="breadcrumb-item text-sm text-dark active" aria-current="page">Sale Analysis Report</li>
          </ol>
          <!-- <h6 class="font-weight-bolder mb-0">Tables</h6> -->
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
    <!-- End Navbar -->
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4 border-0" style="box-shadow: 0 10px 30px rgba(0,0,0,0.05); border-radius: 16px; overflow: hidden;">
                <!-- Card Header with Gradient Background -->
                 <div class="card-header pb-0 pt-4" style="background: linear-gradient(135deg,rgb(143, 104, 5) 0%,rgb(234, 196, 99) 100%);">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0 text-white">
                                <i class="fas fa-chart-pie me-2"></i>Sales Performance Dashboard
                            </h5>
                            <p class="text-sm text-white-50 mb-2 mt-1">Detailed analysis of closed orders</p>
                        </div>
                        <div>
                            <a href="?export=excel&start_date=<?= htmlspecialchars($startDate) ?>&end_date=<?= htmlspecialchars($endDate) ?>&search=<?= htmlspecialchars($searchTerm) ?>" 
                               class="btn btn-sm btn-light bg-white text-dark d-flex align-items-center shadow-sm">
                               <i class="fas fa-file-export me-2"></i> Export Report
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Filter Section with Elegant Divider -->
                <div class="px-4 pt-4">
                    <div class="position-relative py-3">
                        <div class="position-absolute top-0 start-0 end-0" style="height: 1px; background: linear-gradient(90deg, rgba(118,75,162,0.1) 0%, rgba(118,75,162,0.3) 50%, rgba(118,75,162,0.1) 100%);"></div>
                    </div>
                    <div class="p-3 bg-soft-light rounded-3" style="background-color: #f9fafc;">
                        <form method="get" class="row g-3">
                            <input type="hidden" name="page" value="sales_analysis">
                            <div class="col-md-3">
                                <label class="form-label text-sm text-muted mb-1">From Date</label>
                                <div class="input-group input-group-sm shadow-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-calendar-alt text-muted"></i></span>
                                    <input type="date" class="form-control shadow-none border-start-0" name="start_date" 
                                           value="<?= htmlspecialchars($startDate) ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-sm text-muted mb-1">To Date</label>
                                <div class="input-group input-group-sm shadow-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-calendar-alt text-muted"></i></span>
                                    <input type="date" class="form-control shadow-none border-start-0" name="end_date" 
                                           value="<?= htmlspecialchars($endDate) ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-sm text-muted mb-1">Search Branch</label>
                                <div class="input-group input-group-sm shadow-sm">
                                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                                    <input type="text" class="form-control shadow-none border-start-0" name="search" 
                                           value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Search branch...">
                                </div>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-primary me-2 px-3 shadow-sm d-flex align-items-center">
                                    <i class="fas fa-sliders-h me-2"></i> Filter
                                </button>
                                <a href="?page=sales_analysis" class="btn btn-sm btn-outline-secondary px-3 shadow-sm">
                                    <i class="fas fa-undo me-1"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Date range info with decorative divider -->
                <?php if (!empty($startDate) || !empty($endDate)): ?>
                <div class="px-4">
                    <div class="position-relative py-3">
                        <div class="position-absolute top-0 start-0 end-0" style="height: 1px; background: linear-gradient(90deg, rgba(118,75,162,0.1) 0%, rgba(118,75,162,0.3) 50%, rgba(118,75,162,0.1) 100%);"></div>
                    </div>
                    <div class="alert alert-light alert-dismissible fade show mb-3 p-3" role="alert" style="background-color: #f8f9fa; border-left: 3px solid #667eea;">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <div class="d-flex align-items-center">
                            <i class="fas fa-info-circle me-3 text-primary" style="font-size: 1.25rem;"></i>
                            <div>
                                <h6 class="alert-heading mb-1 text-dark">Date Range Applied</h6>
                                <p class="mb-0 text-sm text-muted">
                                    Showing data <?= !empty($startDate) ? 'from <strong class="text-dark">'.htmlspecialchars($startDate).'</strong>' : '' ?>
                                    <?= (!empty($startDate) && !empty($endDate)) ? ' to ' : '' ?>
                                    <?= !empty($endDate) ? 'to <strong class="text-dark">'.htmlspecialchars($endDate).'</strong>' : '' ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Data Table with Premium Styling -->
                <div class="card-body px-0 pt-0 pb-4">
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr style="background-color: #f8fafd;">
                                    <th class="text-uppercase text-secondary text-xs font-weight-bolder ps-4" style="letter-spacing: 0.5px;">Branch</th>
                                    <th class="text-uppercase text-secondary text-xs font-weight-bolder text-center" style="letter-spacing: 0.5px;">Tax</th>
                                    <th class="text-uppercase text-secondary text-xs font-weight-bolder text-center" style="letter-spacing: 0.5px;">Service Charges</th>
                                    <th class="text-uppercase text-secondary text-xs font-weight-bolder text-center" style="letter-spacing: 0.5px;">Food Charges</th>
                                    <th class="text-uppercase text-secondary text-xs font-weight-bolder text-center pe-4" style="letter-spacing: 0.5px;">Net Amount</th>
                                </tr>
                                <tr>
                                    <td colspan="5" class="p-0">
                                        <div style="height: 2px; background: linear-gradient(90deg, rgba(102,126,234,0.1) 0%, rgba(102,126,234,0.5) 50%, rgba(102,126,234,0.1) 100%);"></div>
                                    </td>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($branches as $index => $row): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center py-3">
                                            <div class="icon icon-shape icon-sm me-3 shadow text-center" style="background: linear-gradient(135deg, rgba(118,75,162,0.1) 0%, rgba(118,75,162,0.2) 100%); border-radius: 12px; width: 40px; height: 40px; line-height: 40px;">
                                                <i class="fas fa-store text-primary" style="font-size: 0.9rem;"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-0 text-sm font-weight-bold"><?= htmlspecialchars($row['BRANCHNAME']) ?></h6>
                                                <p class="text-xs text-muted mb-0"><?= htmlspecialchars($row['BRANCHCODE']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="text-xs font-weight-bold px-3 py-2 rounded d-inline-block" style="background-color: rgba(108,117,125,0.1); min-width: 90px;">
                                            <?= number_format($row['TOTAL_TAX'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="text-xs font-weight-bold px-3 py-2 rounded d-inline-block" style="background-color: rgba(13,110,253,0.1); min-width: 90px;">
                                            <?= number_format($row['TOTAL_SERVICE'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="text-xs font-weight-bold px-3 py-2 rounded d-inline-block" style="background-color: rgba(25,135,84,0.1); min-width: 90px;">
                                            <?= number_format($row['TOTAL_FOOD'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="text-center pe-4">
                                        <span class="text-xs font-weight-bold px-3 py-2 rounded d-inline-block" style="background: linear-gradient(135deg, rgba(25,135,84,0.2) 0%, rgba(25,135,84,0.3) 100%); color: #198754; min-width: 100px;">
                                            <?= number_format($row['TOTAL_NET'], 2) ?>
                                        </span>
                                    </td>
                                </tr>
                                
                                <!-- Elegant Section Divider -->
                                <?php if ($index < count($branches) - 1): ?>
                                <tr>
                                    <td colspan="5" class="p-0">
                                        <div class="d-flex justify-content-center">
                                            <div style="height: 1px; background: linear-gradient(90deg, rgba(118,75,162,0) 0%, rgba(118,75,162,0.2) 50%, rgba(118,75,162,0) 100%); width: 90%; margin: 4px 0;"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5" class="p-0">
                                        <div style="height: 2px; background: linear-gradient(90deg, rgba(102,126,234,0.1) 0%, rgba(102,126,234,0.5) 50%, rgba(102,126,234,0.1) 100%);"></div>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="ps-4 font-weight-bold text-dark py-3">Grand Total</td>
                                    <td class="text-center font-weight-bold py-3" style="color: #6c757d;"><?= number_format($grandTotal['TAX'], 2) ?></td>
                                    <td class="text-center font-weight-bold py-3" style="color: #0d6efd;"><?= number_format($grandTotal['SERVICE'], 2) ?></td>
                                    <td class="text-center font-weight-bold py-3" style="color: #198754;"><?= number_format($grandTotal['FOOD'], 2) ?></td>
                                    <td class="text-center pe-4 font-weight-bold py-3" style="color: #198754;"><?= number_format($grandTotal['NET'], 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>





     
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
              <!-- <div class="col-lg-6">
                <ul class="nav nav-footer justify-content-center justify-content-lg-end">
                  <li class="nav-item">
                    <a href="https://www.creative-tim.com" class="nav-link text-muted" target="_blank">Creative Tim</a>
                  </li>
                  <li class="nav-item">
                    <a href="https://www.creative-tim.com/presentation" class="nav-link text-muted" target="_blank">About Us</a>
                  </li>
                  <li class="nav-item">
                    <a href="https://www.creative-tim.com/blog" class="nav-link text-muted" target="_blank">Blog</a>
                  </li>
                  <li class="nav-item">
                    <a href="https://www.creative-tim.com/license" class="nav-link pe-0 text-muted" target="_blank">License</a>
                  </li>
                </ul>
              </div> -->
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