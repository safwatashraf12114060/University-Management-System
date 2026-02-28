<?php
session_start();

$isLoggedIn = isset($_SESSION["user_id"]);
$name = $_SESSION["name"] ?? "User";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>University Management System</title>
  <style>
    :root{
      --bg: #f4f6fb;
      --card: #ffffff;
      --text: #0f172a;
      --muted: #475569;
      --primary: #2f3cff;
      --border: #e5e7eb;
      --shadow: 0 10px 25px rgba(0,0,0,0.08);
      --shadow2: 0 8px 22px rgba(0,0,0,0.06);
      --radius: 14px;
    }
    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family: Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
    }
    .nav{
      background: var(--card);
      border-bottom: 1px solid var(--border);
      box-shadow: 0 4px 14px rgba(0,0,0,0.04);
    }
    .nav .wrap{
      max-width: 1100px;
      margin: 0 auto;
      padding: 14px 18px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 14px;
    }
    .brand{
      display:flex;
      align-items:center;
      gap: 10px;
      font-weight: 800;
      letter-spacing: 0.2px;
      font-size: 18px;
      color: var(--text);
      text-decoration:none;
    }
    .brand svg{ width: 26px; height: 26px; }
    .nav-actions{
      display:flex;
      align-items:center;
      gap: 14px;
    }
    .link{
      color: var(--text);
      text-decoration:none;
      font-weight: 600;
      opacity: .9;
    }
    .link:hover{ opacity: 1; }

    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding: 10px 16px;
      border-radius: 10px;
      border: 1px solid transparent;
      text-decoration:none;
      font-weight: 700;
      cursor:pointer;
      transition: 0.15s ease;
      user-select:none;
    }
    .btn-primary{
      background: var(--primary);
      color: #fff;
      box-shadow: 0 10px 18px rgba(47,60,255,0.18);
    }
    .btn-primary:hover{ filter: brightness(0.98); transform: translateY(-1px); }
    .btn-outline{
      background: #fff;
      color: var(--text);
      border-color: var(--border);
    }
    .btn-outline:hover{ transform: translateY(-1px); }

    .hero{
      max-width: 1100px;
      margin: 0 auto;
      padding: 70px 18px 24px;
      text-align:center;
    }
    .hero h1{
      margin: 0 0 12px;
      font-size: 46px;
      line-height: 1.05;
      letter-spacing: -0.8px;
    }
    .hero p{
      margin: 0 auto 26px;
      max-width: 740px;
      font-size: 18px;
      color: var(--muted);
    }
    .cta{
      display:flex;
      justify-content:center;
      gap: 14px;
      flex-wrap: wrap;
      margin-top: 10px;
    }

    .features{
      max-width: 1100px;
      margin: 0 auto;
      padding: 28px 18px 70px;
      display:grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 22px;
    }
    .card{
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow2);
      padding: 22px;
      text-align:left;
    }
    .icon{
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: rgba(47,60,255,0.10);
      display:flex;
      align-items:center;
      justify-content:center;
      margin-bottom: 14px;
    }
    .card h3{
      margin: 0 0 8px;
      font-size: 18px;
    }
    .card p{
      margin: 0;
      color: var(--muted);
      line-height: 1.5;
      font-size: 14px;
    }

    .welcome{
      display:inline-block;
      margin-top: 14px;
      padding: 8px 12px;
      border-radius: 999px;
      background: rgba(15, 23, 42, 0.06);
      color: rgba(15, 23, 42, 0.75);
      font-weight: 700;
      font-size: 13px;
    }

    @media (max-width: 980px){
      .hero h1{ font-size: 38px; }
      .features{ grid-template-columns: 1fr; }
      .nav-actions{ gap: 10px; }
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
        <?php if (!$isLoggedIn): ?>
          <a class="link" href="login.php">Login</a>
          <a class="btn btn-primary" href="register.php">Register</a>
        <?php else: ?>
          <a class="link" href="index.php">Dashboard</a>
          <a class="btn btn-primary" href="logout.php">Logout</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <section class="hero">
    <h1>University Management System</h1>
    <p>Streamline academic operations with a simple, secure, and centralized management solution.</p>

    <div class="cta">
      <?php if (!$isLoggedIn): ?>
        <a class="btn btn-primary" href="login.php">Get Started</a>
        <a class="btn btn-outline" href="register.php">Register</a>
      <?php else: ?>
        <a class="btn btn-primary" href="index.php">Go to Dashboard</a>
        <a class="btn btn-outline" href="logout.php">Logout</a>
      <?php endif; ?>
    </div>

    <?php if ($isLoggedIn): ?>
      <div class="welcome">Signed in as <?php echo htmlspecialchars($name); ?></div>
    <?php endif; ?>
  </section>

  <section class="features">
    <div class="card">
      <div class="icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
          <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" stroke="#2f3cff" stroke-width="2" stroke-linecap="round"/>
          <circle cx="9" cy="7" r="4" stroke="#2f3cff" stroke-width="2"/>
          <path d="M20 8v6" stroke="#2f3cff" stroke-width="2" stroke-linecap="round"/>
          <path d="M23 11h-6" stroke="#2f3cff" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </div>
      <h3>Student Management</h3>
      <p>Manage student records, enrollments, and academic progress efficiently.</p>
    </div>

    <div class="card">
      <div class="icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
          <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" stroke="#2f3cff" stroke-width="2" stroke-linecap="round"/>
          <path d="M4 4v15.5" stroke="#2f3cff" stroke-width="2" stroke-linecap="round"/>
          <path d="M20 22V6a2 2 0 0 0-2-2H6.5A2.5 2.5 0 0 0 4 6.5" stroke="#2f3cff" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </div>
      <h3>Course Management</h3>
      <p>Create and manage courses, assign teachers, and track enrollments.</p>
    </div>

    <div class="card">
      <div class="icon" aria-hidden="true">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
          <path d="M12 2l7 4v6c0 5-3 9-7 10-4-1-7-5-7-10V6l7-4Z" stroke="#2f3cff" stroke-width="2" stroke-linejoin="round"/>
          <path d="M9 12l2 2 4-4" stroke="#2f3cff" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </div>
      <h3>Results & Grading</h3>
      <p>Record grades, generate transcripts, and track academic performance.</p>
    </div>
  </section>

</body>
</html>