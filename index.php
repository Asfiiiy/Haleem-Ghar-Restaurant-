<?php
session_start();
require_once 'includes/db.php';

$error = '';

// Fetch all active branch supervisors
$conn = get_db_connection();
$query = "
    SELECT u.*, b.BRANCHNAME 
    FROM [dbo].[tblUser] u
    LEFT JOIN [dbo].[tblBranches] b ON u.BRANCHID = b.BRANCHID
    WHERE u.USERTYPE = 'supervisor' 
    AND (u.SYNCH = 'Y' OR u.SYNCH IS NULL)
    ORDER BY b.BRANCHNAME, u.NAME
";
$result = sqlsrv_query($conn, $query);
$users = [];
if ($result) {
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        $users[] = $row;
    }
} else {
    // Handle error
    $error = "Failed to fetch users: " . print_r(sqlsrv_errors(), true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginType = $_POST['login_type'] ?? 'supervisor';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $userId = $_POST['user'] ?? '';

    try {
        if ($loginType === 'admin') {
            // Admin login
            $query = "
                SELECT * FROM [dbo].[tblUser]
                WHERE NAME = ? 
                AND PASSWORD = ? 
                AND (USERTYPE = 'admin' OR USERTYPE = 'Admin')
                AND (SYNCH = 'Y' OR SYNCH IS NULL)
            ";
            $params = array($username, $password);
        } else {
            // Supervisor login
            $query = "
                SELECT u.*, b.BRANCHNAME 
                FROM [dbo].[tblUser] u
                LEFT JOIN [dbo].[tblBranches] b ON u.BRANCHID = b.BRANCHID
                WHERE u.USERID = ? 
                AND u.PASSWORD = ? 
                AND u.USERTYPE = 'supervisor'
                AND (u.SYNCH = 'Y' OR u.SYNCH IS NULL)
            ";
            $params = array($userId, $password);
        }

        $stmt = sqlsrv_query($conn, $query, $params);
        
        if ($stmt === false) {
            $error = "Database error: " . print_r(sqlsrv_errors(), true);
        } else {
            $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            
            if ($user) {
                $_SESSION['USER'] = [
                    'ID' => $user['USERID'],
                    'NAME' => $user['NAME'],
                    'TYPE' => strtolower($user['USERTYPE']),
                    'BRANCH_ID' => $user['BRANCHID'] ?? null,
                    'BRANCH_NAME' => $user['BRANCHNAME'] ?? null
                ];
                
                header("Location: pages/dashboard.php");
                exit;
            } else {
                $error = "Invalid credentials";
            }
        }
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        $error = "System error occurred";
    }
}

$showAdminLogin = isset($_GET['admin']) && $_GET['admin'] === 'true';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Login | Haleem Ghar</title>
  <!-- Fonts and icons -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="assets/css/nucleo-icons.css" rel="stylesheet" />
  <link href="assets/css/nucleo-svg.css" rel="stylesheet" />
  <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
  <!-- Main CSS -->
  <link id="pagestyle" href="assets/css/soft-ui-dashboard.css?v=1.1.0" rel="stylesheet" />
  <style>
    :root {
      --primary-color: #6c5ce7;
      --primary-light: #a29bfe;
      --dark-color: #2d3436;
      --light-color: #f5f6fa;
      --success-color: #00b894;
      --accent-color: #ff7675;
    }
    
    body {
      font-family: 'Poppins', sans-serif;
      background-color: var(--light-color);
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      margin: 0;
      background-image: radial-gradient(circle at 10% 20%, rgba(108, 92, 231, 0.1) 0%, rgba(108, 92, 231, 0.05) 90%);
    }
    
    .login-container {
      width: 100%;
      max-width: 420px;
      margin: 20px;
    }
    
    .login-card {
      background: white;
      border-radius: 16px;
      box-shadow: 0 8px 32px rgba(31, 38, 135, 0.15);
      overflow: hidden;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    
    .login-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 12px 40px rgba(31, 38, 135, 0.2);
    }
    
    .login-header {
      padding: 1.5rem 2rem 0;
      text-align: center;
      position: relative;
    }
    
    .login-animation {
      width: 120px;
      height: 120px;
      margin: 0 auto;
      display: block;
      object-fit: contain;
    }
    
    .login-title {
      color: var(--dark-color);
      font-weight: 600;
      margin: 0.5rem 0 0.2rem;
      font-size: 1.5rem;
    }
    
    .login-subtitle {
      color: #636e72;
      font-size: 0.9rem;
      margin-bottom: 1rem;
    }
    
    .login-body {
      padding: 1rem 2rem 2rem;
    }
    
    .form-group {
      margin-bottom: 1.5rem;
      position: relative;
    }
    
    .form-label {
      display: block;
      margin-bottom: 0.5rem;
      color: var(--dark-color);
      font-weight: 500;
      font-size: 0.9rem;
    }
    
    .form-control {
      width: 100%;
      padding: 12px 16px;
      border: 1px solid #dfe6e9;
      border-radius: 8px;
      font-size: 0.95rem;
      transition: all 0.3s ease;
      background-color: #f8f9fa;
    }
    
    .form-control:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.2);
      background-color: white;
      outline: none;
    }
    
    .input-icon {
      position: absolute;
      right: 15px;
      top: 38px;
      color: #b2bec3;
    }
    
    .btn-login {
      width: 100%;
      padding: 12px;
      border: none;
      border-radius: 8px;
      background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
      color: white;
      font-weight: 600;
      font-size: 1rem;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 6px rgba(108, 92, 231, 0.3);
      margin-top: 0.5rem;
    }
    
    .btn-login:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 12px rgba(108, 92, 231, 0.4);
    }
    
    .alert-danger {
      background-color: #ffecec;
      color:rgb(253, 253, 253);
      padding: 12px;
      border-radius: 8px;
      margin-bottom: 1.5rem;
      font-size: 0.9rem;
      border-left: 4px solid #d63031;
    }
    
    .footer-links {
      text-align: center;
      margin-top: 1.5rem;
      font-size: 0.85rem;
    }
    
    .footer-links a {
      color: var(--primary-color);
      text-decoration: none;
      transition: color 0.3s ease;
    }
    
    .footer-links a:hover {
      color: var(--dark-color);
      text-decoration: underline;
    }
    
    .brand-text {
      color: var(--accent-color);
      font-weight: 700;
    }
    
    @media (max-width: 576px) {
      .login-card {
        border-radius: 12px;
      }
      
      .login-header {
        padding: 1rem 1.5rem 0;
      }
      
      .login-body {
        padding: 1rem 1.5rem 1.5rem;
      }
      
      .login-animation {
        width: 100px;
        height: 100px;
      }
    }
    .login-type-toggle {
      text-align: center;
      margin-bottom: 1rem;
    }
    .login-type-toggle a {
      color: var(--primary-color);
      cursor: pointer;
      text-decoration: none;
    }
    .login-type-toggle a:hover {
      text-decoration: underline;
    }
    .hidden {
      display: none;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="login-card">
      <div class="login-header">
        <img src="assets/img/login.gif" alt="Cooking Animation" class="login-animation">
        <h2 class="login-title">Welcome to <span class="brand-text">Haleem Ghar</span></h2>
        <p class="login-subtitle">
          <?= $showAdminLogin ? 'Admin Login' : 'Supervisor Login' ?>
        </p>
      </div>
      
      <div class="login-body">
        <div class="login-type-toggle">
          <?php if ($showAdminLogin): ?>
            <a onclick="window.location.href='index.php'">← Back to Supervisor Login</a>
          <?php else: ?>
            <a onclick="window.location.href='index.php?admin=true'">Admin Login →</a>
          <?php endif; ?>
        </div>

        <form method="POST">
          <input type="hidden" name="login_type" value="<?= $showAdminLogin ? 'admin' : 'supervisor' ?>">
          
          <?php if ($showAdminLogin): ?>
            <!-- Admin Login Form -->
            <div class="form-group">
              <label class="form-label">Admin Username</label>
              <input type="text" name="username" class="form-control" required autofocus>
              <i class="fas fa-user-shield input-icon"></i>
            </div>
          <?php else: ?>
            <!-- Supervisor Login Form -->
            <div class="form-group">
              <label class="form-label">Supervisor Account</label>
              <select name="user" class="form-control" required>
                <option value="">-- Select Supervisor --</option>
                <?php foreach ($users as $user): ?>
                  <option value="<?= htmlspecialchars($user['USERID']) ?>">
                    <?= htmlspecialchars(($user['BRANCHNAME'] ?? 'No Branch') . ' - ' . $user['NAME']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <i class="fas fa-user-tie input-icon"></i>
            </div>
          <?php endif; ?>
          
          <div class="form-group">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
            <i class="fas fa-lock input-icon"></i>
          </div>
          
          <?php if ($error): ?>
            <div class="alert-danger"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>
          
          <button type="submit" class="btn-login">Sign In</button>
        </form>
        
        <div class="footer-links">
          <a href="#">Forgot password?</a> • <a href="#">Need help?</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Core JS Files -->
  <script src="assets/js/core/popper.min.js"></script>
  <script src="assets/js/core/bootstrap.min.js"></script>
</body>
</html>