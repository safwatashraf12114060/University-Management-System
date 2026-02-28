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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email.";
    } else {
        $sql = "SELECT id, name, email, password FROM users WHERE email = ?";
        $stmt = sqlsrv_query($conn, $sql, [$email]);

        if ($stmt && sqlsrv_has_rows($stmt)) {
            $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

            if ($user && password_verify($password, $user["password"])) {
                $_SESSION["user_id"] = $user["id"];
                $_SESSION["name"] = $user["name"];
                $_SESSION["email"] = $user["email"];

                header("Location: index.php");
                exit();
            } else {
                $error = "Invalid email or password.";
            }
        } else {
            $error = "Invalid email or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login</title>
  <style>
    :root{
      --bg:#f4f6fb;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#475569;
      --primary:#2f3cff;
      --border:#e5e7eb;
      --shadow:0 10px 25px rgba(0,0,0,0.08);
      --radius:14px;
    }
    *{box-sizing:border-box;}
    body{
      margin:0;
      font-family: Arial, sans-serif;
      background:var(--bg);
      color:var(--text);
    }
    .nav{
      background:var(--card);
      border-bottom:1px solid var(--border);
      box-shadow:0 4px 14px rgba(0,0,0,0.04);
    }
    .nav .wrap{
      max-width:1100px;
      margin:0 auto;
      padding:14px 18px;
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
      font-size:18px;
      color:var(--text);
      text-decoration:none;
    }
    .brand svg{width:26px;height:26px;}
    .nav-actions{
      display:flex;
      align-items:center;
      gap:14px;
    }
    .link{
      color:var(--text);
      text-decoration:none;
      font-weight:600;
      opacity:.9;
    }
    .link:hover{opacity:1;}
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
      min-height:calc(100vh - 60px);
      display:flex;
      align-items:center;
      justify-content:center;
      padding:30px 18px;
    }
    .card{
      width:520px;
      max-width:100%;
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:28px;
    }
    .icon{
      width:54px;
      height:54px;
      border-radius:16px;
      background:rgba(47,60,255,0.10);
      display:flex;
      align-items:center;
      justify-content:center;
      margin:0 auto 10px;
    }
    h2{
      margin:8px 0 6px;
      text-align:center;
      font-size:26px;
      letter-spacing:-0.2px;
    }
    .sub{
      text-align:center;
      color:var(--muted);
      margin:0 0 18px;
    }
    label{
      display:block;
      margin:12px 0 6px;
      font-size:14px;
      color:var(--text);
      font-weight:700;
    }
    input{
      width:100%;
      padding:12px 12px;
      border:1px solid #d0d4e3;
      border-radius:10px;
      outline:none;
      background:#fff;
    }
    input:focus{border-color:var(--primary);}
    .btn-full{
      width:100%;
      margin-top:16px;
      padding:12px 14px;
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
      margin:0 0 12px;
      padding:10px 12px;
      border-radius:10px;
      background:#ffe8e8;
      border:1px solid #ffb3b3;
      color:#8a0000;
      font-weight:700;
      font-size:14px;
    }
    .footer-link{
      text-align:center;
      margin-top:16px;
      color:var(--muted);
      font-size:14px;
    }
    .footer-link a{
      color:var(--primary);
      text-decoration:none;
      font-weight:800;
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
        <a class="link" href="login.php">Login</a>
        <a class="btn btn-primary" href="register.php">Register</a>
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

      <h2>Welcome Back</h2>
      <p class="sub">Sign in to your account to continue</p>

      <?php if ($error !== ""): ?>
        <div class="alert"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <form method="post" action="">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" placeholder="Enter your email" required />

        <label for="password">Password</label>
        <input id="password" name="password" type="password" placeholder="Enter your password" required />

        <button class="btn-full" type="submit">Login</button>
      </form>

      <div class="footer-link">
        Don't have an account? <a href="register.php">Register</a>
      </div>
    </div>
  </div>

</body>
</html>