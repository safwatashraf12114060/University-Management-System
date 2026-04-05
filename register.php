<?php
session_start();
require "db.php";

// Cache prevention
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// If already logged in, go to dashboard
if (isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm = $_POST["confirm_password"] ?? "";

    if ($name === "" || $email === "" || $password === "" || $confirm === "") {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $checkSql = "SELECT id FROM users WHERE email = ?";
        $checkStmt = sqlsrv_query($conn, $checkSql, [$email]);

        if ($checkStmt && sqlsrv_has_rows($checkStmt)) {
            $error = "Email is already registered.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            $insertSql = "INSERT INTO users (name, email, password) VALUES (?, ?, ?)";
            $insertStmt = sqlsrv_query($conn, $insertSql, [$name, $email, $hashed]);

            if ($insertStmt) {
                header("Location: login.php");
                exit();
            } else {
                $error = "Registration failed. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Register</title>
  <style>
    :root{
      --bg:linear-gradient(180deg, #f4f6fb 0%, #eef2ff 100%);
      --card:#ffffff;
      --text:#0f172a;
      --muted:#475569;
      --primary:#2f3cff;
      --border:#e5e7eb;
      --shadow:0 8px 22px rgba(0,0,0,0.06);
      --radius:14px;
    }
    *{box-sizing:border-box;}
    body{
      margin:0;
      font-family: "Times New Roman", Times, serif;
      font-size:18px;
      line-height:1.6;
      background:var(--bg);
      color:var(--text);
      min-height:100vh;
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }
    .nav{
      background:var(--card);
      border-bottom:1px solid var(--border);
      box-shadow:0 4px 14px rgba(0,0,0,0.04);
      flex:0 0 auto;
    }
    .nav .wrap{
      width:100%;
      max-width:none;
      margin:0;
      padding:10px 32px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:14px;
    }
    .brand{
      display:flex;
      align-items:center;
      gap:10px;
      font-weight:800;
      letter-spacing:.2px;
      font-size:20px;
      color:var(--text);
      text-decoration:none;
    }
    .brand svg{width:26px;height:26px;}
    .nav-actions{
      display:flex;
      align-items:center;
      gap:14px;
    }
    .nav-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:10px 16px;
      border-radius:10px;
      border:1px solid var(--border);
      background:#fff;
      color:var(--text);
      text-decoration:none;
      font-weight:700;
      transition:.15s ease;
    }
    .nav-btn:hover{transform:translateY(-1px);background:#f8fafc;}
    .nav-btn-active{
      background:var(--primary);
      border-color:var(--primary);
      color:#fff;
      box-shadow:0 10px 18px rgba(47,60,255,0.18);
    }
    .nav-btn-active:hover{background:var(--primary);}
    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:10px 16px;
      border-radius:10px;
      border:1px solid transparent;
      text-decoration:none;
      font-weight:700;
      cursor:pointer;
      transition:.15s ease;
      user-select:none;
    }
    .btn-primary{
      background:var(--primary);
      color:#fff;
      box-shadow:0 10px 18px rgba(47,60,255,0.18);
    }
    .btn-primary:hover{filter:brightness(.98);transform:translateY(-1px);}

    .center{
      flex:1;
      min-height:0;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:8px 32px 10px;
    }
    .card{
      width:520px;
      max-width:100%;
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:22px 24px;
    }
    .icon{
      width:46px;
      height:46px;
      border-radius:14px;
      background:rgba(47,60,255,0.10);
      display:flex;
      align-items:center;
      justify-content:center;
      margin:0 auto 6px;
    }
    h2{
      margin:4px 0;
      text-align:center;
      font-size:30px;
      letter-spacing:-0.2px;
    }
    .sub{
      text-align:center;
      color:var(--muted);
      margin:0 0 12px;
    }
    label{
      display:block;
      margin:10px 0 5px;
      font-size:16px;
      color:var(--text);
      font-weight:700;
    }
    input{
      width:100%;
      padding:10px 12px;
      border:1px solid #d0d4e3;
      border-radius:10px;
      outline:none;
      background:#fff;
    }
    input:focus{border-color:var(--primary);}
    .btn-full{
      width:100%;
      margin-top:12px;
      padding:11px 14px;
      border-radius:10px;
      border:0;
      background:var(--primary);
      color:#fff;
      font-weight:800;
      cursor:pointer;
      transition:.15s ease;
    }
    .btn-full:hover{filter:brightness(.98);transform:translateY(-1px);}

    .alert{
      margin:0 0 10px;
      padding:8px 10px;
      border-radius:8px;
      background:#ffe8e8;
      border:1px solid #ffb3b3;
      color:#8a0000;
      font-weight:700;
      font-size:18px;
      line-height:1.4;
      transition:opacity .25s ease, transform .25s ease, max-height .25s ease, margin .25s ease, padding .25s ease;
    }
    .flash-hide{
      opacity:0;
      transform:translateY(-6px);
      max-height:0;
      margin:0;
      padding-top:0;
      padding-bottom:0;
      overflow:hidden;
      pointer-events:none;
    }
    .footer-link{
      text-align:center;
      margin-top:10px;
      color:var(--muted);
      font-size:16px;
    }
    .footer-link a{
      color:var(--primary);
      text-decoration:none;
      font-weight:800;
    }
    @media (max-width:980px){
      .nav .wrap,
      .center{ padding-left:18px; padding-right:18px; }
    }
    @media (max-height:900px){
      .card{
        transform:scale(0.92);
        transform-origin:top center;
      }
    }
  </style>
</head>
<body>

  <div class="nav">
    <div class="wrap">
      <a class="brand" href="home.php">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M12 3 2 8l10 5 10-5-10-5Z" stroke="#2f3cff" stroke-width="2" stroke-linejoin="round"/>
          <path d="M6 10v6c0 1.1 2.7 2 6 2s6-.9 6-2v-6" stroke="#2f3cff" stroke-width="2" stroke-linecap="round"/>
        </svg>
        University Management
      </a>

      <div class="nav-actions">
        <a class="nav-btn" href="home.php">Home</a>
        <a class="nav-btn" href="login.php">Login</a>
        <a class="nav-btn nav-btn-active" href="register.php">Register</a>
      </div>
    </div>
  </div>

  <div class="center">
    <div class="card">
      <div class="icon" aria-hidden="true">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
          <path d="M12 3 2 8l10 5 10-5-10-5Z" stroke="#2f3cff" stroke-width="2" stroke-linejoin="round"/>
          <path d="M6 10v6c0 1.1 2.7 2 6 2s6-.9 6-2v-6" stroke="#2f3cff" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </div>

      <h2>Create Account</h2>
      <p class="sub">Sign up to get started with UMS</p>

      <?php if ($error !== ""): ?>
        <div class="alert"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <form method="post" action="">
        <label for="name">Full Name</label>
        <input id="name" name="name" type="text" placeholder="Enter your full name" required />

        <label for="email">Email</label>
        <input id="email" name="email" type="email" placeholder="Enter your email" required />

        <label for="password">Password</label>
        <input id="password" name="password" type="password" placeholder="Create a password" required />

        <label for="confirm_password">Confirm Password</label>
        <input id="confirm_password" name="confirm_password" type="password" placeholder="Confirm your password" required />

        <button class="btn-full" type="submit">Register</button>
      </form>

      <div class="footer-link">
        Already have an account? <a href="login.php">Login</a>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener("DOMContentLoaded", function () {
      document.querySelectorAll(".alert").forEach(function (messageEl) {
        window.setTimeout(function () {
          messageEl.classList.add("flash-hide");
          window.setTimeout(function () {
            if (messageEl.parentNode) {
              messageEl.parentNode.removeChild(messageEl);
            }
          }, 300);
        }, 5000);
      });
    });
  </script>

</body>
</html>
