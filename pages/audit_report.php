
<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/db.php';

$conn = get_db_connection();




// Initialize filters with sanitization
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$refresh_data = isset($_GET['refresh']) ? true : false;

// Function to format product values with specific decimal places
function formatProductValue($productName, $value) {
    // Products that need 3 decimal places
    $threeDecimalProducts = [
        'NIHARI GOSHAT IN KG',
        'CHICKEN KABAB KG',
        'BEEF KABAB IN KG'
    ];
    
    // Products that need 2 decimal places
    $twoDecimalProducts = [
        'NEHARI GARABI IN KG',
        'BRAIN MASALAH',
        'CHICKEN BOTI HALF',
        'CHICKEN MALAI BOTI',
        'BEEF TIKA PLATE',
        'WHITE HANDI HALF',
        'BROWN HANDI HALF',
        'KARAHI HALF',
        'ROAST',
        'SAJJI'
    ];
    
    // Check if product needs specific formatting
    if (in_array(strtoupper(trim($productName)), $threeDecimalProducts)) {
        return number_format($value, 3);
    } elseif (in_array(strtoupper(trim($productName)), $twoDecimalProducts)) {
        return number_format($value, 2);
    } else {
        // Default formatting (no decimals for other products)
        return number_format($value);
    }
}

// Validate dates
if (!empty($start_date) && !DateTime::createFromFormat('Y-m-d', $start_date)) {
    $start_date = '';
}
if (!empty($end_date) && !DateTime::createFromFormat('Y-m-d', $end_date)) {
    $end_date = '';
}

// Get branches based on user role (with caching)
$isAdmin = (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin');
$userBranchId = $_SESSION['USER']['BRANCH_ID'] ?? 0;
$cache_key = 'branches_' . md5(serialize([$refresh_data, $isAdmin, $userBranchId]));

if ($refresh_data || !isset($_SESSION[$cache_key])) {
    if ($isAdmin) {
        // Admin sees all branches
        $branchQuery = "SELECT BRANCHID, BRANCHCODE, BRANCHNAME FROM tblBranches ORDER BY BRANCHNAME";
        $stmtBranches = sqlsrv_query($conn, $branchQuery);
    } else {
        // Non-admin sees only their branch
        if (!empty($userBranchId)) {
            $branchQuery = "SELECT BRANCHID, BRANCHCODE, BRANCHNAME FROM tblBranches WHERE BRANCHID = ? ORDER BY BRANCHNAME";
            $stmtBranches = sqlsrv_query($conn, $branchQuery, [$userBranchId]);
        } else {
            // No branch assigned, return empty array
            $stmtBranches = false;
        }
    }
    
    if ($stmtBranches === false) {
        if (!$isAdmin && empty($userBranchId)) {
            $branches = array(); // Empty for non-admin with no branch
        } else {
            die(print_r(sqlsrv_errors(), true));
        }
    } else {
        $branches = array();
        while ($row = sqlsrv_fetch_array($stmtBranches, SQLSRV_FETCH_ASSOC)) {
            $branches[] = $row;
        }
        sqlsrv_free_stmt($stmtBranches);
    }
    $_SESSION[$cache_key] = $branches;
}
$allBranches = $_SESSION[$cache_key];

// Prepare parameters for query
$params = [];
$whereConditions = [];

// Add date filtering
if (!empty($start_date)) {
    $whereConditions[] = "CAST(h.INVOICEDATE AS DATE) >= CAST(? AS DATE)";
    $params[] = $start_date;
}
if (!empty($end_date)) {
    $whereConditions[] = "CAST(h.INVOICEDATE AS DATE) <= CAST(? AS DATE)";
    $params[] = $end_date;
}

// Add status filter for closed orders
$whereConditions[] = "h.STATUS = 'Close'";

// Add role-based filtering for branches
if (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) !== 'admin') {
    if (!empty($_SESSION['USER']['BRANCH_ID'])) {
        $whereConditions[] = "d.BRANCHID = ?";
        $params[] = $_SESSION['USER']['BRANCH_ID'];
    } else {
        $whereConditions[] = "1 = 0"; // Non-admin with no branch sees nothing
    }
}

// Build WHERE clause
$whereClause = empty($whereConditions) ? "" : "WHERE " . implode(" AND ", $whereConditions);

// Build the main query with ALL formulas calculated in SQL
$query = "
    SELECT 
        b.BRANCHID,
        b.BRANCHCODE,
        b.BRANCHNAME,
        
        -- KITCHEN Category
        -- CHICKEN HALEEM PLATE
        SUM(CASE WHEN d.ITEMCODE = 'PRD-1' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-7' THEN d.QUANTITY ELSE 0 END) * 4)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-8' THEN d.QUANTITY ELSE 0 END) * 3)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-86' THEN d.QUANTITY ELSE 0 END) AS CHICKEN_HALEEM_PLATE,
        
        -- BEEF HALEEM PLATE
        SUM(CASE WHEN d.ITEMCODE = 'PRD-6' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-9' THEN d.QUANTITY ELSE 0 END) * 4)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-10' THEN d.QUANTITY ELSE 0 END) * 3)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-85' THEN d.QUANTITY ELSE 0 END) AS BEEF_HALEEM_PLATE,
        
        -- CHICKEN ACHAR PLATE
        SUM(CASE WHEN d.ITEMCODE = 'PRD-17' THEN d.QUANTITY ELSE 0 END) AS CHICKEN_ACHAR_PLATE,
        
        -- BEEF ACHAR/BIRYANI PLATE
        SUM(CASE WHEN d.ITEMCODE = 'PRD-18' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-27' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-26' THEN d.QUANTITY ELSE 0 END) AS BEEF_ACHAR_BIRYANI_PLATE,
        
        -- NIHARI GOSHAT IN KG
        (SUM(CASE WHEN d.ITEMCODE = 'PRD-11' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-13' THEN d.QUANTITY ELSE 0 END) / 2)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-127' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-129' THEN d.QUANTITY ELSE 0 END) / 2)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-15' THEN d.QUANTITY ELSE 0 END)) / 14 AS NIHARI_GOSHAT_KG,
        
        -- NEHARI GARABI IN KG
        SUM(CASE WHEN d.ITEMCODE = 'PRD-11' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-13' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-14' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-16' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-127' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-129' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-15' THEN d.QUANTITY ELSE 0 END) AS NEHARI_GARABI_KG,
        
        -- BIRYANI CHAWAL IN KG
        (SUM(CASE WHEN d.ITEMCODE = 'PRD-23' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-24' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-25' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-26' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-27' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-28' THEN d.QUANTITY ELSE 0 END)) / 2 AS BIRYANI_CHAWAL_KG,
        
        -- BIRYANI CHICKEN PIECE
        SUM(CASE WHEN d.ITEMCODE = 'PRD-23' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-20' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-28' THEN d.QUANTITY ELSE 0 END) * 2)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-12' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-14' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-25' THEN d.QUANTITY ELSE 0 END) * 2) AS BIRYANI_CHICKEN_PIECE,
        
        -- CHANA
        SUM(CASE WHEN d.ITEMCODE = 'PRD-20' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-21' THEN d.QUANTITY ELSE 0 END) AS CHANA,
        
        -- BRAIN MASALAH
        SUM(CASE WHEN d.ITEMCODE = 'PRD-19' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-16' THEN d.QUANTITY ELSE 0 END) / 2) AS BRAIN_MASALAH,
        
       -- SHAMI KABAB (Corrected)
       (SUM(CASE WHEN d.ITEMCODE = 'PRD-25' THEN d.QUANTITY ELSE 0 END) * 2)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-26' THEN d.QUANTITY ELSE 0 END) * 2)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-31' THEN d.QUANTITY ELSE 0 END)  -- ADDED PRD-31
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-32' THEN d.QUANTITY ELSE 0 END)  -- ADDED PRD-32
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-34' THEN d.QUANTITY ELSE 0 END) * 5) AS SHAMI_KABAB,  -- ADDED PRD-34 * 5
        
        -- BAR-B-QUE Category
        -- CHICKEN TIKA
        SUM(CASE WHEN d.ITEMCODE = 'PRD-33' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-48' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-49' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-50' THEN d.QUANTITY ELSE 0 END) AS CHICKEN_TIKA,
        
        -- CHICKEN BOTI HALF
        (SUM(CASE WHEN d.ITEMCODE = 'PRD-51' THEN d.QUANTITY ELSE 0 END) * 2)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-52' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-53' THEN d.QUANTITY ELSE 0 END) / 2)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-35' THEN d.QUANTITY ELSE 0 END) * 2)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-36' THEN d.QUANTITY ELSE 0 END) AS CHICKEN_BOTI_HALF,
        
        -- CHICKEN MALAI BOTI
        SUM(CASE WHEN d.ITEMCODE = 'PRD-54' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-55' THEN d.QUANTITY ELSE 0 END) / 2)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-35' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-36' THEN d.QUANTITY ELSE 0 END) / 2) AS CHICKEN_MALAI_BOTI,
        
        -- BEEF TIKA PLATE
        ((SUM(CASE WHEN d.ITEMCODE = 'PRD-46' THEN d.QUANTITY ELSE 0 END) * 4)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-60' THEN d.QUANTITY ELSE 0 END) * 4)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-61' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-35' THEN d.QUANTITY ELSE 0 END) * 4)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-36' THEN d.QUANTITY ELSE 0 END) * 2)) / 4 AS BEEF_TIKA_PLATE,
        
        -- CHIKEN KABAB KG
        ((SUM(CASE WHEN d.ITEMCODE = 'PRD-56' THEN d.QUANTITY ELSE 0 END) * 4)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-57' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-35' THEN d.QUANTITY ELSE 0 END) * 4)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-36' THEN d.QUANTITY ELSE 0 END) * 2)) / 14 AS CHIKEN_KABAB_KG,
        
        -- BEEF KABAB IN KG
        (SUM(CASE WHEN d.ITEMCODE = 'PRD-59' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-58' THEN d.QUANTITY ELSE 0 END) * 4)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-47' THEN d.QUANTITY ELSE 0 END) * 4)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-35' THEN d.QUANTITY ELSE 0 END) * 4)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-36' THEN d.QUANTITY ELSE 0 END) * 2)) / 14 AS BEEF_KABAB_KG,
        
        -- BATAIR
        SUM(CASE WHEN d.ITEMCODE = 'PRD-63' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-62' THEN d.QUANTITY ELSE 0 END) * 3)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-35' THEN d.QUANTITY ELSE 0 END) * 4)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-36' THEN d.QUANTITY ELSE 0 END) * 2) AS BATAIR,
        
        -- COLD DRINKS AND HANDIES Category
        -- 1 Litter
        SUM(CASE WHEN d.ITEMCODE = 'PRD-88' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-333' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-334' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-207' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-208' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-338' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-339' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-36' THEN d.QUANTITY ELSE 0 END) AS LITTER_1,
        
        -- 1.5 Litter
        SUM(CASE WHEN d.ITEMCODE = 'PRD-89' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-35' THEN d.QUANTITY ELSE 0 END) AS LITTER_1_5,
        
        -- 2.25 Jumbo
        SUM(CASE WHEN d.ITEMCODE = 'PRD-90' THEN d.QUANTITY ELSE 0 END) AS JUMBO_2_25,
        
        -- 500ML
        SUM(CASE WHEN d.ITEMCODE = 'PRD-92' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-331' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-332' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-205' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-206' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-335' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-336' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-337' THEN d.QUANTITY ELSE 0 END) AS ML_500,
        
        -- Can
        SUM(CASE WHEN d.ITEMCODE = 'PRD-91' THEN d.QUANTITY ELSE 0 END) AS CAN,
        
        -- Fresh Lime
        SUM(CASE WHEN d.ITEMCODE = 'PRD-305' THEN d.QUANTITY ELSE 0 END) AS FRESH_LIME,
        
        -- M Water large
        SUM(CASE WHEN d.ITEMCODE = 'PRD-95' THEN d.QUANTITY ELSE 0 END) AS M_WATER_LARGE,
        
        -- M Water Small
        SUM(CASE WHEN d.ITEMCODE = 'PRD-96' THEN d.QUANTITY ELSE 0 END) AS M_WATER_SMALL,
        
        -- Regular
        SUM(CASE WHEN d.ITEMCODE = 'PRD-87' THEN d.QUANTITY ELSE 0 END) AS REGULAR,
        
        -- Slice Juice
        SUM(CASE WHEN d.ITEMCODE = 'PRD-94' THEN d.QUANTITY ELSE 0 END) AS SLICE_JUICE,
        
        -- Sting
        SUM(CASE WHEN d.ITEMCODE = 'PRD-93' THEN d.QUANTITY ELSE 0 END) AS STING,
        
        -- KHEER
        SUM(CASE WHEN d.ITEMCODE = 'PRD-108' THEN d.QUANTITY ELSE 0 END) AS KHEER,
        
        -- WHITE HANDI HALF
        (SUM(CASE WHEN d.ITEMCODE = 'PRD-37' THEN d.QUANTITY ELSE 0 END) * 2)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-38' THEN d.QUANTITY ELSE 0 END) AS WHITE_HANDI_HALF,
        
        -- BROWN HANDI HALF
        (SUM(CASE WHEN d.ITEMCODE = 'PRD-39' THEN d.QUANTITY ELSE 0 END) * 2)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-40' THEN d.QUANTITY ELSE 0 END) AS BROWN_HANDI_HALF,
        
        -- KARAHI HALF
        (SUM(CASE WHEN d.ITEMCODE = 'PRD-44' THEN d.QUANTITY ELSE 0 END) * 2)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-45' THEN d.QUANTITY ELSE 0 END) AS KARAHI_HALF,
        
        -- ROAST
        SUM(CASE WHEN d.ITEMCODE = 'PRD-42' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-43' THEN d.QUANTITY ELSE 0 END) / 2)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-35' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-36' THEN d.QUANTITY ELSE 0 END) / 2) AS ROAST,
        
        -- SAJJI
        SUM(CASE WHEN d.ITEMCODE = 'PRD-68' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-69' THEN d.QUANTITY ELSE 0 END) / 2) AS SAJJI,
        
        -- PLATER FULL
        SUM(CASE WHEN d.ITEMCODE = 'PRD-35' THEN d.QUANTITY ELSE 0 END) AS PLATER_FULL,
        
        -- PLATER HALF
        SUM(CASE WHEN d.ITEMCODE = 'PRD-36' THEN d.QUANTITY ELSE 0 END) AS PLATER_HALF,
        
        -- PULAO Category
        -- BEEF/KABLI PULAO
        SUM(CASE WHEN d.ITEMCODE = 'PRD-29' THEN d.QUANTITY ELSE 0 END) AS BEEF_KABLI_PULAO,
        
        -- CHICKEN PULAO
        SUM(CASE WHEN d.ITEMCODE = 'PRD-33' THEN d.QUANTITY ELSE 0 END) AS CHICKEN_PULAO,
        
        -- PLANE PULAO
        SUM(CASE WHEN d.ITEMCODE = 'PRD-30' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-35' THEN d.QUANTITY ELSE 0 END) * 4)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-36' THEN d.QUANTITY ELSE 0 END) * 2) AS PLANE_PULAO,
        
        -- SHAMI PULAO
        SUM(CASE WHEN d.ITEMCODE = 'PRD-31' THEN d.QUANTITY ELSE 0 END) AS SHAMI_PULAO,
        
        -- NASHTA Category
        -- CHANA
        SUM(CASE WHEN d.ITEMCODE = 'PRD-117' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-118' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-119' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-120' THEN d.QUANTITY ELSE 0 END) AS NASHTA_CHANA,
        
        -- HALWA
        SUM(CASE WHEN d.ITEMCODE = 'PRD-121' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-122' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-123' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-124' THEN d.QUANTITY ELSE 0 END) AS HALWA,
        
        -- ALO BOUJIA
        SUM(CASE WHEN d.ITEMCODE = 'PRD-125' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-126' THEN d.QUANTITY ELSE 0 END) AS ALO_BOUJIA,
        
        -- PORI
        SUM(CASE WHEN d.ITEMCODE = 'PRD-116' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-132' THEN d.QUANTITY ELSE 0 END) AS PORI,
        
        -- ROGHNI NAN
        SUM(CASE WHEN d.ITEMCODE = 'PRD-130' THEN d.QUANTITY ELSE 0 END) AS ROGHNI_NAN,
        
        -- TEA
        SUM(CASE WHEN d.ITEMCODE = 'PRD-100' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-131' THEN d.QUANTITY ELSE 0 END) AS TEA,
        
        -- CHIENIES Category
        -- BEEF CHILI DRY
        SUM(CASE WHEN d.ITEMCODE = 'PRD-77' THEN d.QUANTITY ELSE 0 END) AS BEEF_CHILI_DRY,
        
        -- RICE
        SUM(CASE WHEN d.ITEMCODE = 'PRD-71' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-72' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-73' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-75' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-76' THEN d.QUANTITY ELSE 0 END) AS RICE,
        
        -- SOUPS
        SUM(CASE WHEN d.ITEMCODE = 'PRD-79' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-80' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-81' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-82' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-83' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-84' THEN d.QUANTITY ELSE 0 END) AS SOUPS,
        
        -- CHOWMIN
        SUM(CASE WHEN d.ITEMCODE = 'PRD-74' THEN d.QUANTITY ELSE 0 END) AS CHOWMIN,
        
        -- FINGER FISH
        SUM(CASE WHEN d.ITEMCODE = 'PRD-306' THEN d.QUANTITY ELSE 0 END) AS FINGER_FISH,
        
        -- JELFREZY
        SUM(CASE WHEN d.ITEMCODE = 'PRD-307' THEN d.QUANTITY ELSE 0 END) AS JELFREZY,
        
        -- CHICKEN GENGER
        SUM(CASE WHEN d.ITEMCODE = 'PRD-308' THEN d.QUANTITY ELSE 0 END) AS CHICKEN_GENGER,
        
        -- OTHERS Category
        -- NAN
        SUM(CASE WHEN d.ITEMCODE = 'PRD-106' THEN d.QUANTITY ELSE 0 END) AS NAN,
        
        -- ROGHNI NAN
        SUM(CASE WHEN d.ITEMCODE = 'PRD-107' THEN d.QUANTITY ELSE 0 END) AS ROGHNI_NAN_OTHERS,
        
        -- RAITA
        SUM(CASE WHEN d.ITEMCODE = 'PRD-103' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-26' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-25' THEN d.QUANTITY ELSE 0 END) AS RAITA,
        
        -- SALAD
        SUM(CASE WHEN d.ITEMCODE = 'PRD-102' THEN d.QUANTITY ELSE 0 END) AS SALAD,
        
        -- RASIAN SALAD
        SUM(CASE WHEN d.ITEMCODE = 'PRD-304' THEN d.QUANTITY ELSE 0 END) AS RASIAN_SALAD,
        
        -- NESTLE RAITA
        SUM(CASE WHEN d.ITEMCODE = 'PRD-104' THEN d.QUANTITY ELSE 0 END) AS NESTLE_RAITA,
        
        -- NESTLE DAHI
        SUM(CASE WHEN d.ITEMCODE = 'PRD-105' THEN d.QUANTITY ELSE 0 END) AS NESTLE_DAHI,
        
        -- COFFI (Fixed value)
        64 AS COFFI,
        
        -- DELIEVERY CHARGES
        SUM(CASE WHEN d.ITEMCODE = 'PRD-113' THEN d.QUANTITY ELSE 0 END) AS DELIEVERY_CHARGES,
        
        -- ICE CREAM
        SUM(CASE WHEN d.ITEMCODE = 'PRD-97' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-98' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-99' THEN d.QUANTITY ELSE 0 END) AS ICE_CREAM,
        
        -- LASSI
        SUM(CASE WHEN d.ITEMCODE = 'PRD-309' THEN d.QUANTITY ELSE 0 END) AS LASSI,
        
        -- FAST FOOD Category
        -- PIZZA
        SUM(CASE WHEN d.ITEMCODE = 'PRD-165' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-166' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-167' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-168' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-173' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-174' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-175' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-176' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-181' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-182' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-184' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-186' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-189' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-190' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-191' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-192' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-205' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-206' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-207' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-208' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-323' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-324' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-325' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-326' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-327' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-328' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-329' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-330' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-335' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-336' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-337' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-338' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-339' THEN d.QUANTITY ELSE 0 END) * 2) AS PIZZA,
        
        -- BURGERS
        SUM(CASE WHEN d.ITEMCODE = 'PRD-184' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-139' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-136' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-319' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-331' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-332' THEN d.QUANTITY ELSE 0 END) * 2)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-333' THEN d.QUANTITY ELSE 0 END) * 3)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-334' THEN d.QUANTITY ELSE 0 END) * 4)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-339' THEN d.QUANTITY ELSE 0 END) * 5) AS BURGERS,
        
        -- CHICKEN PIECES
        SUM(CASE WHEN d.ITEMCODE = 'PRD-141' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-142' THEN d.QUANTITY ELSE 0 END) * 3)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-339' THEN d.QUANTITY ELSE 0 END) * 5) AS CHICKEN_PIECES,
        
        -- HOT WINGS
        (SUM(CASE WHEN d.ITEMCODE = 'PRD-144' THEN d.QUANTITY ELSE 0 END) * 5)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-339' THEN d.QUANTITY ELSE 0 END) * 6) AS HOT_WINGS,
        
        -- MAGUTS
        SUM(CASE WHEN d.ITEMCODE = 'PRD-158' THEN d.QUANTITY ELSE 0 END) * 6 AS MAGUTS,
        
        -- FRIES
        SUM(CASE WHEN d.ITEMCODE = 'PRD-322' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-320' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-321' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-262' THEN d.QUANTITY ELSE 0 END)
        + (SUM(CASE WHEN d.ITEMCODE = 'PRD-339' THEN d.QUANTITY ELSE 0 END) * 6) AS FRIES,
        
        -- SHAWARMA
        SUM(CASE WHEN d.ITEMCODE = 'PRD-154' THEN d.QUANTITY ELSE 0 END) AS SHAWARMA,
        
        -- ROLL
        SUM(CASE WHEN d.ITEMCODE = 'PRD-152' THEN d.QUANTITY ELSE 0 END)
        + SUM(CASE WHEN d.ITEMCODE = 'PRD-157' THEN d.QUANTITY ELSE 0 END) AS ROLL,
        
        -- BIRTH DAY DEAL
        SUM(CASE WHEN d.ITEMCODE = 'PRD-339' THEN d.QUANTITY ELSE 0 END) AS BIRTH_DAY_DEAL
        
    FROM tblSalesInvoiceDetail d
    INNER JOIN tblSalesInvoiceHeader h ON d.INVOICEID = h.INVOICEID
    INNER JOIN tblBranches b ON d.BRANCHID = b.BRANCHID
    $whereClause
    GROUP BY b.BRANCHID, b.BRANCHCODE, b.BRANCHNAME
    ORDER BY b.BRANCHNAME
";

try {
    $stmtSales = sqlsrv_query($conn, $query, $params);
    if ($stmtSales === false) {
        die(print_r(sqlsrv_errors(), true));
    }
    $salesData = array();
    while ($row = sqlsrv_fetch_array($stmtSales, SQLSRV_FETCH_ASSOC)) {
        $salesData[] = $row;
    }
    sqlsrv_free_stmt($stmtSales);
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Organize the data for display
$productReport = [
    'KITCHEN' => [],
    'BAR-B-QUE' => [],
    'COLD DRINKS AND HANDIES' => [],
    'PULAO' => [],
    'NASHTA' => [],
    'CHIENIES' => [],
    'OTHERS' => [],
    'FAST FOOD' => []
];

$branchTotals = array_fill_keys(array_column($allBranches, 'BRANCHCODE'), 0);
$grandTotal = 0;

// Define product formulas for display
$productFormulas = [
    'KITCHEN' => [
        'CHICKEN HALEEM PLATE' => 'PRD-1 + (PRD-7 * 4) + (PRD-8 * 3) + PRD-86',
        'BEEF HALEEM PLATE' => 'PRD-6 + (PRD-9 * 4) + (PRD-10 * 3) + PRD-85',
        'CHICKEN ACHAR PLATE' => 'PRD-17',
        'BEEF ACHAR/BIRYANI PLATE' => 'PRD-18 + PRD-27 + PRD-26',
        'NIHARI GOSHAT IN KG' => '(PRD-11 + (PRD-13 / 2) + PRD-127 + (PRD-129 / 2) + PRD-16 + PRD-15) / 14',
        'NEHARI GARABI IN KG' => 'PRD-11 + PRD-13 + PRD-14 + PRD-16 + PRD-127 + PRD-129 + PRD-15',
        'BIRYANI CHAWAL IN KG' => '(PRD-23 + PRD-24 + PRD-25 + PRD-26 + PRD-27 + PRD-28) / 2',
        'BIRYANI CHICKEN PIECE' => 'PRD-23 + PRD-20 + (PRD-28 * 2) + PRD-12 + PRD-14 + (PRD-25 * 2)',
        'CHANA' => 'PRD-20 + PRD-21',
        'BRAIN MASALAH' => 'PRD-19 + (PRD-16 / 2)',
        'SHAMI KABAB' => '(PRD-25 * 2) + (PRD-26 * 2) + PRD-31 + PRD-32 + (PRD-34 * 5)',
    ],
    'BAR-B-QUE' => [
        'CHICKEN TIKA' => 'PRD-33 + PRD-48 + PRD-49 + PRD-50',
        'CHICKEN BOTI HALF' => '(PRD-51 * 2) + PRD-52 + (PRD-53 / 2) + (PRD-35 * 2) + PRD-36',
        'CHICKEN MALAI BOTI' => 'PRD-54 + (PRD-55 / 2) + PRD-35 + (PRD-36 / 2)',
        'BEEF TIKA PLATE' => '((PRD-46 * 4) + (PRD-60 * 4) + PRD-61 + (PRD-35 * 4) + (PRD-36 * 2)) / 4',
        'CHIKEN KABAB KG' => '((PRD-56 * 4) + PRD-57 + (PRD-35 * 4) + (PRD-36 * 2)) / 14',
        'BEEF KABAB IN KG' => '(PRD-59 + (PRD-58 * 4) + (PRD-47 * 4) + (PRD-35 * 4) + (PRD-36 * 2)) / 14',
        'BATAIR' => 'PRD-63 + (PRD-62 * 3) + (PRD-35 * 4) + (PRD-36 * 2)'
    ],
    'COLD DRINKS AND HANDIES' => [
        '1 Litter' => 'PRD-88 + PRD-333 + PRD-334 + PRD-207 + PRD-208 + PRD-338 + PRD-339 + PRD-36',
        '1.5 Litter' => 'PRD-89 + PRD-35',
        '2.25 Jumbo' => 'PRD-90',
        '500ML' => 'PRD-92 + PRD-331 + PRD-332 + PRD-205 + PRD-206 + PRD-335 + PRD-336 + PRD-337',
        'Can' => 'PRD-91',
        'Fresh Lime' => 'PRD-305',
        'M Water large' => 'PRD-95',
        'M Water Small' => 'PRD-96',
        'Regular' => 'PRD-87',
        'Slice Juice' => 'PRD-94',
        'Sting' => 'PRD-93',
        'KHEER' => 'PRD-108',
        'WHITE HANDI HALF' => '(PRD-37 * 2) + PRD-38',
        'BROWN HANDI HALF' => '(PRD-39 * 2) + PRD-40',
        'KARAHI HALF' => '(PRD-44 * 2) + PRD-45',
        'ROAST' => 'PRD-42 + (PRD-43 / 2) + PRD-35 + (PRD-36 / 2)',
        'SAJJI' => 'PRD-68 + (PRD-69 / 2)',
        'PLATER FULL' => 'PRD-35',
        'PLATER HALF' => 'PRD-36'
    ],
    'PULAO' => [
        'BEEF/KABLI PULAO' => 'PRD-29',
        'CHICKEN PULAO' => 'PRD-33',
        'PLANE PULAO' => 'PRD-30 + (PRD-35 * 4) + (PRD-36 * 2)',
        'SHAMI PULAO' => 'PRD-31'
    ],
    'NASHTA' => [
        'CHANA' => 'PRD-117 + PRD-118 + PRD-119 + PRD-120',
        'HALWA' => 'PRD-121 + PRD-122 + PRD-123 + PRD-124',
        'ALO BOUJIA' => 'PRD-125 + PRD-126',
        'PORI' => 'PRD-116 + PRD-132',
        'ROGHNI NAN' => 'PRD-130',
        'TEA' => 'PRD-100 + PRD-131'
    ],
    'CHIENIES' => [
        'BEEF CHILI DRY' => 'PRD-77',
        'RICE' => 'PRD-71 + PRD-72 + PRD-73 + PRD-75 + PRD-76',
        'SOUPS' => 'PRD-79 + PRD-80 + PRD-81 + PRD-82 + PRD-83 + PRD-84',
        'CHOWMIN' => 'PRD-74',
        'FINGER FISH' => 'PRD-306',
        'JELFREZY' => 'PRD-307',
        'CHICKEN GENGER' => 'PRD-308'
    ],
    'OTHERS' => [
        'NAN' => 'PRD-106',
        'ROGHNI NAN' => 'PRD-107',
        'RAITA' => 'PRD-103 + PRD-26 + PRD-25',
        'SALAD' => 'PRD-102',
        'RASIAN SALAD' => 'PRD-304',
        'NESTLE RAITA' => 'PRD-104',
        'NESTLE DAHI' => 'PRD-105',
        'COFFI' => '64',
        'DELIEVERY CHARGES' => 'PRD-113',
        'ICE CREAM' => 'PRD-97 + PRD-98 + PRD-99',
        'LASSI' => 'PRD-309'
    ],
    'FAST FOOD' => [
        'PIZZA' => 'PRD-165 + PRD-166 + PRD-167 + PRD-168 + PRD-173 + PRD-174 + PRD-175 + PRD-176 + PRD-181 + PRD-182 + PRD-184 + PRD-186 + PRD-189 + PRD-190 + PRD-191 + PRD-192 + PRD-205 + PRD-206 + PRD-207 + PRD-208 + PRD-323 + PRD-324 + PRD-325 + PRD-326 + PRD-327 + PRD-328 + PRD-329 + PRD-330 + PRD-335 + PRD-336 + PRD-337 + PRD-338 + (PRD-339 * 2)',
        'BURGERS' => 'PRD-184 + PRD-139 + PRD-136 + PRD-319 + PRD-331 + (PRD-332 * 2) + (PRD-333 * 3) + (PRD-334 * 4) + (PRD-339 * 5)',
        'CHICKEN PIECES' => 'PRD-141 + (PRD-142 * 3) + (PRD-339 * 5)',
        'HOT WINGS' => '(PRD-144 * 5) + (PRD-339 * 6)',
        'MAGUTS' => 'PRD-158 * 6',
        'FRIES' => 'PRD-322 + PRD-320 + PRD-321 + PRD-262 + (PRD-339 * 6)',
        'SHAWARMA' => 'PRD-154',
        'ROLL' => 'PRD-152 + PRD-157',
        'BIRTH DAY DEAL' => 'PRD-339'
    ]
];

// Map the SQL results to our product categories
foreach ($salesData as $branchData) {
    $branchCode = $branchData['BRANCHCODE'];
    
    // KITCHEN
    $productReport['KITCHEN']['CHICKEN HALEEM PLATE'][$branchCode] = $branchData['CHICKEN_HALEEM_PLATE'] ?? 0;
    $productReport['KITCHEN']['BEEF HALEEM PLATE'][$branchCode] = $branchData['BEEF_HALEEM_PLATE'] ?? 0;
    $productReport['KITCHEN']['CHICKEN ACHAR PLATE'][$branchCode] = $branchData['CHICKEN_ACHAR_PLATE'] ?? 0;
    $productReport['KITCHEN']['BEEF ACHAR/BIRYANI PLATE'][$branchCode] = $branchData['BEEF_ACHAR_BIRYANI_PLATE'] ?? 0;
    $productReport['KITCHEN']['NIHARI GOSHAT IN KG'][$branchCode] = $branchData['NIHARI_GOSHAT_KG'] ?? 0;
    $productReport['KITCHEN']['NEHARI GARABI IN KG'][$branchCode] = $branchData['NEHARI_GARABI_KG'] ?? 0;
    $productReport['KITCHEN']['BIRYANI CHAWAL IN KG'][$branchCode] = $branchData['BIRYANI_CHAWAL_KG'] ?? 0;
    $productReport['KITCHEN']['BIRYANI CHICKEN PIECE'][$branchCode] = $branchData['BIRYANI_CHICKEN_PIECE'] ?? 0;
    $productReport['KITCHEN']['CHANA'][$branchCode] = $branchData['CHANA'] ?? 0;
    $productReport['KITCHEN']['BRAIN MASALAH'][$branchCode] = $branchData['BRAIN_MASALAH'] ?? 0;
    $productReport['KITCHEN']['SHAMI KABAB'][$branchCode] = $branchData['SHAMI_KABAB'] ?? 0;
    
    // BAR-B-QUE
    $productReport['BAR-B-QUE']['CHICKEN TIKA'][$branchCode] = $branchData['CHICKEN_TIKA'] ?? 0;
    $productReport['BAR-B-QUE']['CHICKEN BOTI HALF'][$branchCode] = $branchData['CHICKEN_BOTI_HALF'] ?? 0;
    $productReport['BAR-B-QUE']['CHICKEN MALAI BOTI'][$branchCode] = $branchData['CHICKEN_MALAI_BOTI'] ?? 0;
    $productReport['BAR-B-QUE']['BEEF TIKA PLATE'][$branchCode] = $branchData['BEEF_TIKA_PLATE'] ?? 0;
    $productReport['BAR-B-QUE']['CHIKEN KABAB KG'][$branchCode] = $branchData['CHIKEN_KABAB_KG'] ?? 0;
    $productReport['BAR-B-QUE']['BEEF KABAB IN KG'][$branchCode] = $branchData['BEEF_KABAB_KG'] ?? 0;
    $productReport['BAR-B-QUE']['BATAIR'][$branchCode] = $branchData['BATAIR'] ?? 0;
    
    // COLD DRINKS AND HANDIES
    $productReport['COLD DRINKS AND HANDIES']['1 Litter'][$branchCode] = $branchData['LITTER_1'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['1.5 Litter'][$branchCode] = $branchData['LITTER_1_5'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['2.25 Jumbo'][$branchCode] = $branchData['JUMBO_2_25'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['500ML'][$branchCode] = $branchData['ML_500'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['Can'][$branchCode] = $branchData['CAN'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['Fresh Lime'][$branchCode] = $branchData['FRESH_LIME'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['M Water large'][$branchCode] = $branchData['M_WATER_LARGE'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['M Water Small'][$branchCode] = $branchData['M_WATER_SMALL'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['Regular'][$branchCode] = $branchData['REGULAR'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['Slice Juice'][$branchCode] = $branchData['SLICE_JUICE'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['Sting'][$branchCode] = $branchData['STING'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['KHEER'][$branchCode] = $branchData['KHEER'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['WHITE HANDI HALF'][$branchCode] = $branchData['WHITE_HANDI_HALF'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['BROWN HANDI HALF'][$branchCode] = $branchData['BROWN_HANDI_HALF'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['KARAHI HALF'][$branchCode] = $branchData['KARAHI_HALF'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['ROAST'][$branchCode] = $branchData['ROAST'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['SAJJI'][$branchCode] = $branchData['SAJJI'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['PLATER FULL'][$branchCode] = $branchData['PLATER_FULL'] ?? 0;
    $productReport['COLD DRINKS AND HANDIES']['PLATER HALF'][$branchCode] = $branchData['PLATER_HALF'] ?? 0;
    
    // PULAO
    $productReport['PULAO']['BEEF/KABLI PULAO'][$branchCode] = $branchData['BEEF_KABLI_PULAO'] ?? 0;
    $productReport['PULAO']['CHICKEN PULAO'][$branchCode] = $branchData['CHICKEN_PULAO'] ?? 0;
    $productReport['PULAO']['PLANE PULAO'][$branchCode] = $branchData['PLANE_PULAO'] ?? 0;
        $productReport['PULAO']['SHAMI PULAO'][$branchCode] = $branchData['SHAMI_PULAO'] ?? 0;
        
        // NASHTA
        $productReport['NASHTA']['CHANA'][$branchCode] = $branchData['NASHTA_CHANA'] ?? 0;
        $productReport['NASHTA']['HALWA'][$branchCode] = $branchData['HALWA'] ?? 0;
        $productReport['NASHTA']['ALO BOUJIA'][$branchCode] = $branchData['ALO_BOUJIA'] ?? 0;
        $productReport['NASHTA']['PORI'][$branchCode] = $branchData['PORI'] ?? 0;
        $productReport['NASHTA']['ROGHNI NAN'][$branchCode] = $branchData['ROGHNI_NAN'] ?? 0;
        $productReport['NASHTA']['TEA'][$branchCode] = $branchData['TEA'] ?? 0;
        
        // CHIENIES
        $productReport['CHIENIES']['BEEF CHILI DRY'][$branchCode] = $branchData['BEEF_CHILI_DRY'] ?? 0;
        $productReport['CHIENIES']['RICE'][$branchCode] = $branchData['RICE'] ?? 0;
        $productReport['CHIENIES']['SOUPS'][$branchCode] = $branchData['SOUPS'] ?? 0;
        $productReport['CHIENIES']['CHOWMIN'][$branchCode] = $branchData['CHOWMIN'] ?? 0;
        $productReport['CHIENIES']['FINGER FISH'][$branchCode] = $branchData['FINGER_FISH'] ?? 0;
        $productReport['CHIENIES']['JELFREZY'][$branchCode] = $branchData['JELFREZY'] ?? 0;
        $productReport['CHIENIES']['CHICKEN GENGER'][$branchCode] = $branchData['CHICKEN_GENGER'] ?? 0;
        
        // OTHERS
        $productReport['OTHERS']['NAN'][$branchCode] = $branchData['NAN'] ?? 0;
        $productReport['OTHERS']['ROGHNI NAN'][$branchCode] = $branchData['ROGHNI_NAN_OTHERS'] ?? 0;
        $productReport['OTHERS']['RAITA'][$branchCode] = $branchData['RAITA'] ?? 0;
        $productReport['OTHERS']['SALAD'][$branchCode] = $branchData['SALAD'] ?? 0;
        $productReport['OTHERS']['RASIAN SALAD'][$branchCode] = $branchData['RASIAN_SALAD'] ?? 0;
        $productReport['OTHERS']['NESTLE RAITA'][$branchCode] = $branchData['NESTLE_RAITA'] ?? 0;
        $productReport['OTHERS']['NESTLE DAHI'][$branchCode] = $branchData['NESTLE_DAHI'] ?? 0;
        $productReport['OTHERS']['COFFI'][$branchCode] = $branchData['COFFI'] ?? 0;
        $productReport['OTHERS']['DELIEVERY CHARGES'][$branchCode] = $branchData['DELIEVERY_CHARGES'] ?? 0;
        $productReport['OTHERS']['ICE CREAM'][$branchCode] = $branchData['ICE_CREAM'] ?? 0;
        $productReport['OTHERS']['LASSI'][$branchCode] = $branchData['LASSI'] ?? 0;
        
        // FAST FOOD
        $productReport['FAST FOOD']['PIZZA'][$branchCode] = $branchData['PIZZA'] ?? 0;
        $productReport['FAST FOOD']['BURGERS'][$branchCode] = $branchData['BURGERS'] ?? 0;
        $productReport['FAST FOOD']['CHICKEN PIECES'][$branchCode] = $branchData['CHICKEN_PIECES'] ?? 0;
        $productReport['FAST FOOD']['HOT WINGS'][$branchCode] = $branchData['HOT_WINGS'] ?? 0;
        $productReport['FAST FOOD']['MAGUTS'][$branchCode] = $branchData['MAGUTS'] ?? 0;
        $productReport['FAST FOOD']['FRIES'][$branchCode] = $branchData['FRIES'] ?? 0;
        $productReport['FAST FOOD']['SHAWARMA'][$branchCode] = $branchData['SHAWARMA'] ?? 0;
        $productReport['FAST FOOD']['ROLL'][$branchCode] = $branchData['ROLL'] ?? 0;
        $productReport['FAST FOOD']['BIRTH DAY DEAL'][$branchCode] = $branchData['BIRTH_DAY_DEAL'] ?? 0;
        
        // Calculate branch totals
        foreach ($productReport as $category => $products) {
            foreach ($products as $product => $branches) {
                $branchTotals[$branchCode] += $branches[$branchCode] ?? 0;
                $grandTotal += $branches[$branchCode] ?? 0;
            }
        }
    }
    
    // Apply category filter if selected
    $categoryFilter = isset($_GET['category_filter']) ? trim($_GET['category_filter']) : '';
    if (!empty($categoryFilter) && isset($productReport[$categoryFilter])) {
        $filteredProductReport = [];
        $filteredProductReport[$categoryFilter] = $productReport[$categoryFilter];
        $productReport = $filteredProductReport;
        
        // Recalculate totals for filtered category only
        $branchTotals = [];
        $grandTotal = 0;
        foreach ($productReport as $category => $products) {
            foreach ($products as $product => $branches) {
                foreach ($allBranches as $branch) {
                    $branchCode = $branch['BRANCHCODE'];
                    $branchTotals[$branchCode] = ($branchTotals[$branchCode] ?? 0) + ($branches[$branchCode] ?? 0);
                    $grandTotal += $branches[$branchCode] ?? 0;
                }
            }
        }
    }
    
    // Export to Excel functionality
    if (isset($_GET['export']) && $_GET['export'] == 'excel') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="Audit_Report_' . date('Ymd') . '.csv"');
        header('Cache-Control: max-age=0');
        
        // Create CSV content
        $output = fopen('php://output', 'w');
        
        // Add BOM for Excel UTF-8 compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Check user role for different export formats
        $isAdmin = isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin';
        
        if ($isAdmin) {
            // Admin export - includes all branch columns plus total column
            $headers = array('Category', 'Product');
            foreach ($allBranches as $branch) {
                $headers[] = $branch['BRANCHNAME'];
            }
            $headers[] = 'Total'; // Add total column
            
            fputcsv($output, $headers);
            
            // Product data rows with all branch columns plus total
            foreach ($productReport as $category => $products) {
                foreach ($products as $product => $branches) {
                    $row = array($category, $product);
                    $productTotal = 0;
                    
                    foreach ($allBranches as $branch) {
                        $value = $branches[$branch['BRANCHCODE']] ?? 0;
                        $row[] = formatProductValue($product, $value);
                        $productTotal += $value;
                    }
                    
                    $row[] = formatProductValue($product, $productTotal); // Add product total
                    fputcsv($output, $row);
                }
            }
            
            // Branch totals row (smart totals based on filter)
            $totalRow = array('', 'Totals');
            foreach ($allBranches as $branch) {
                $totalRow[] = $branchTotals[$branch['BRANCHCODE']] ?? 0;
            }
            $totalRow[] = $grandTotal; // Add grand total (smart based on filter)
            fputcsv($output, $totalRow);
            
        } else {
            // Non-admin export - only total column
            $headers = array('Category', 'Product', 'Total');
            fputcsv($output, $headers);
            
            // Product data rows with only totals
            foreach ($productReport as $category => $products) {
                foreach ($products as $product => $branches) {
                    $productTotal = 0;
                    foreach ($allBranches as $branch) {
                        $productTotal += $branches[$branch['BRANCHCODE']] ?? 0;
                    }
                    
                    $row = array(
                        $category,
                        $product,
                        formatProductValue($product, $productTotal)
                    );
                    
                    fputcsv($output, $row);
                }
            }
            
            // Totals row (smart totals based on filter)
            $totalRow = array('', 'Totals', $grandTotal);
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
   Category Vise Report
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
  
  <!-- Custom CSS for compact filters -->
  <style>
    /* Compact filter styling */
    .compact-filter .form-control,
    .compact-filter .form-select {
      font-size: 0.875rem;
      padding: 0.375rem 0.5rem;
      border-radius: 0.375rem;
      border: 1px solid #d1d5db;
    }
    
    .compact-filter .form-label {
      font-size: 0.75rem;
      font-weight: 500;
      color: #6b7280;
      margin-bottom: 0.25rem;
    }
    
    .compact-filter .btn {
      font-size: 0.75rem;
      padding: 0.375rem 0.75rem;
      border-radius: 0.375rem;
      font-weight: 500;
    }
    
    .compact-filter .btn-sm {
      padding: 0.25rem 0.5rem;
      font-size: 0.75rem;
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
      .compact-filter .col-sm-2 {
        flex: 0 0 auto;
        width: auto;
      }
      .compact-filter .col-sm-3 {
        flex: 0 0 auto;
        width: auto;
      }
      .compact-filter .col-sm-5 {
        flex: 1;
        min-width: 120px;
      }
    }
    
    @media (max-width: 576px) {
      .compact-filter .form-control,
      .compact-filter .form-select {
        font-size: 0.8rem;
        padding: 0.25rem 0.375rem;
      }
      .compact-filter .btn {
        font-size: 0.7rem;
        padding: 0.25rem 0.5rem;
      }
    }
  </style>
  
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
           <a class="nav-link" href="../pages/category_vise_report.php">
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
          <a class="nav-link active" href="../pages/audit_report.php">
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
             <li class="breadcrumb-item text-sm text-dark active" aria-current="page">Audit Report</li>
           </ol>
           <h6 class="font-weight-bolder mb-0">Audit Report</h6>
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
                    <span class="d-sm-inline d-none">Sign Out (<?= htmlspecialchars($_SESSION['USER']['NAME'] ?? 'User') ?>)</span>
                    <span class="d-sm-none d-inline">Sign Out</span>
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
    
    <!-- HTML and display code -->
    <div class="container-fluid py-4">
      <div class="row">
        <div class="col-12">
          <div class="card mb-4">
            <div class="card-header pb-0">
              <div class="d-flex justify-content-between align-items-center">
                <h6>Product Sales Report</h6>
                <div>
                  <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" 
                     class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel me-1"></i> Export to Excel
                  </a>
                </div>
              </div>
              <p class="text-sm mb-0">Showing calculated product quantities based on formulas</p>
              
              <!-- Category Filter Dropdown -->
              <div class="mt-2">
                <form method="get" class="row g-1 align-items-end compact-filter">
                  <input type="hidden" name="page" value="product_report">
                  <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                  <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                  
                  <div class="col-12 col-sm-2 col-md-1">
                    <label class="form-label mb-1 small text-muted">Category:</label>
                  </div>
                  
                  <div class="col-12 col-sm-5 col-md-3">
                    <select name="category_filter" class="form-select form-select-sm py-1">
                      <option value="">All Categories</option>
                      <option value="KITCHEN" <?= (isset($_GET['category_filter']) && $_GET['category_filter'] === 'KITCHEN') ? 'selected' : '' ?>>KITCHEN</option>
                      <option value="BAR-B-QUE" <?= (isset($_GET['category_filter']) && $_GET['category_filter'] === 'BAR-B-QUE') ? 'selected' : '' ?>>BAR-B-QUE</option>
                      <option value="COLD DRINKS AND HANDIES" <?= (isset($_GET['category_filter']) && $_GET['category_filter'] === 'COLD DRINKS AND HANDIES') ? 'selected' : '' ?>>COLD DRINKS AND HANDIES</option>
                      <option value="PULAO" <?= (isset($_GET['category_filter']) && $_GET['category_filter'] === 'PULAO') ? 'selected' : '' ?>>PULAO</option>
                      <option value="NASHTA" <?= (isset($_GET['category_filter']) && $_GET['category_filter'] === 'NASHTA') ? 'selected' : '' ?>>NASHTA</option>
                      <option value="CHIENIES" <?= (isset($_GET['category_filter']) && $_GET['category_filter'] === 'CHIENIES') ? 'selected' : '' ?>>CHIENIES</option>
                      <option value="OTHERS" <?= (isset($_GET['category_filter']) && $_GET['category_filter'] === 'OTHERS') ? 'selected' : '' ?>>OTHERS</option>
                      <option value="FAST FOOD" <?= (isset($_GET['category_filter']) && $_GET['category_filter'] === 'FAST FOOD') ? 'selected' : '' ?>>FAST FOOD</option>
                    </select>
                  </div>
                  
                  <div class="col-12 col-sm-2 col-md-1">
                    <button type="submit" class="btn btn-primary btn-sm py-1 px-2 w-100">Filter</button>
                  </div>
                  
                  <?php if (!empty($_GET['category_filter'])): ?>
                    <div class="col-12 col-sm-3 col-md-1">
                      <a href="?<?= http_build_query(array_diff_key($_GET, ['category_filter' => ''])) ?>" class="btn btn-outline-secondary btn-sm py-1 px-2 w-100">Clear</a>
                    </div>
                  <?php endif; ?>
                </form>
              </div>
            </div>
            
            <!-- Filter Form -->
            <div class="card-body pt-0">
              <form method="get" class="row g-2 align-items-end compact-filter">
                <input type="hidden" name="page" value="product_report">
                <div class="col-12 col-sm-3 col-md-2">
                  <label class="form-label mb-1 small text-muted">From Date</label>
                  <input type="date" class="form-control form-control-sm py-1" name="start_date" 
                         value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="col-12 col-sm-3 col-md-2">
                  <label class="form-label mb-1 small text-muted">To Date</label>
                  <input type="date" class="form-control form-control-sm py-1" name="end_date" 
                         value="<?= htmlspecialchars($end_date) ?>">
                </div>
                <div class="col-12 col-sm-2 col-md-1">
                  <button type="submit" class="btn btn-primary btn-sm py-1 px-2 w-100">Filter</button>
                </div>
              </form>
              
              <?php if (!empty($start_date) || !empty($end_date)): ?>
              <div class="alert alert-info mt-3 mb-0">
                <strong>Filters:</strong>
                <?= !empty($start_date) ? 'From: ' . htmlspecialchars($start_date) . ' ' : '' ?>
                <?= !empty($end_date) ? 'To: ' . htmlspecialchars($end_date) : '' ?>
              </div>
              <?php endif; ?>
            </div>
            
            <!-- Data Table -->
            <div class="card-body px-0 pt-0 pb-2">
              <div class="table-responsive p-0">
                <table class="table align-items-center mb-0" style="border-collapse: separate; border-spacing: 0;">
                                     <thead>
                     <tr>
                       <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Category</th>
                       <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Product</th>
                       <?php if (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin'): ?>
                         <?php foreach ($allBranches as $branch): ?>
                           <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">
                             <?= htmlspecialchars($branch['BRANCHNAME']) ?>
                           </th>
                         <?php endforeach; ?>
                       <?php endif; ?>
                       <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Total</th>
                     </tr>
                   </thead>
                  <tbody>
                                         <?php if (empty($salesData)): ?>
                       <tr>
                         <td colspan="<?= (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin') ? (count($allBranches) + 3) : 3 ?>" class="text-center">
                           <p class="text-xs font-weight-bold mb-0">No sales data found for the selected criteria</p>
                         </td>
                       </tr>
                     <?php else: ?>
                       <?php foreach ($productReport as $category => $products): ?>
                         <tr>
                           <td colspan="<?= (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin') ? (count($allBranches) + 3) : 3 ?>" style="background-color: #f8f9fa; font-weight: bold;">
                             <?= htmlspecialchars($category) ?>
                           </td>
                         </tr>
                         <?php foreach ($products as $product => $branches): ?>
                           <tr>
                             <td style="border-bottom: 1px solid #dee2e6;"></td>
                             <td style="border-bottom: 1px solid #dee2e6;">
                               <div class="d-flex px-2 py-1">
                                 <div class="d-flex flex-column justify-content-center">
                                   <h6 class="mb-0 text-sm"><?= htmlspecialchars($product) ?></h6>
                                 </div>
                               </div>
                             </td>
                            <?php if (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin'): ?>
                              <?php foreach ($allBranches as $branch): ?>
                                <td style="border-bottom: 1px solid #dee2e6;" class="text-center">
                                  <p class="text-xs font-weight-bold mb-0"><?= formatProductValue($product, $branches[$branch['BRANCHCODE']] ?? 0) ?></p>
                                </td>
                              <?php endforeach; ?>
                            <?php endif; ?>
                            <td style="border-bottom: 1px solid #dee2e6;" class="text-center">
                              <p class="text-xs font-weight-bold mb-0">
                                <?php
                                $productTotal = 0;
                                foreach ($allBranches as $branch) {
                                    $productTotal += $branches[$branch['BRANCHCODE']] ?? 0;
                                }
                                echo formatProductValue($product, $productTotal);
                                ?>
                              </p>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
                      
                                             <!-- Totals Row -->
                       <tr>
                         <td class="font-weight-bolder" style="border-bottom: 1px solid #dee2e6;"></td>
                         <td class="font-weight-bolder" style="border-bottom: 1px solid #dee2e6;">Totals</td>
                         <?php if (isset($_SESSION['USER']['TYPE']) && strtolower($_SESSION['USER']['TYPE']) === 'admin'): ?>
                           <?php foreach ($allBranches as $branch): ?>
                             <td class="font-weight-bolder text-center" style="border-bottom: 1px solid #dee2e6;">
                               <?= number_format($branchTotals[$branch['BRANCHCODE']] ?? 0) ?>
                             </td>
                           <?php endforeach; ?>
                         <?php endif; ?>
                         <td class="font-weight-bolder text-center" style="border-bottom: 1px solid #dee2e6;">
                           <?= number_format($grandTotal) ?>
                         </td>
                       </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              
              <!-- Report Metadata -->
              <div class="text-end text-muted text-xs mt-3">
                Report generated: <?= date('Y-m-d H:i:s') ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <script>
    // Auto-set end date to today if not set
    document.addEventListener('DOMContentLoaded', function() {
        if (!document.querySelector('input[name="end_date"]').value) {
            document.querySelector('input[name="end_date"]').valueAsDate = new Date();
        }
    });
    </script>

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