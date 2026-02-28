<?php
session_start();

// Cache prevention
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// Auth guard
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$name = $_SESSION["name"] ?? "User";
$email = $_SESSION["email"] ?? "";

// Placeholder numbers (replace with DB counts in CP2)
$totalStudents = 0;
$totalTeachers = 0;
$totalCourses = 0;
$totalEnrollments = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard</title>

  <script>
    // Disable back navigation for this page
    (function () {
      function lock() {
        history.pushState(null, "", location.href);
      }

      lock();

      window.addEventListener("popstate", function () {
        lock();
      });

      // If page is restored from bfcache, force reload to re-check session
      window.addEventListener("pageshow", function (event) {
        if (event.persisted) {
          window.location.reload();
        }
      });
    })();
  </script>

  <style>
    :root{
      --bg:#f4f6fb;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#64748b;
      --primary:#2f3cff;
      --border:#e5e7eb;
      --shadow:0 10px 25px rgba(0,0,0,0.08);
      --shadow2:0 8px 22px rgba(0,0,0,0.06);
      --radius:14px;
      --sidebar:#ffffff;
    }
    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family: Arial, sans-serif;
      background:var(--bg);
      color:var(--text);
    }
    .layout{
      display:flex;
      min-height:100vh;
    }
    .sidebar{
      width:260px;
      background:var(--sidebar);
      border-right:1px solid var(--border);
      padding:18px 14px;
    }
    .brand{
      display:flex;
      align-items:center;
      gap:10px;
      font-weight:900;
      letter-spacing:.4px;
      font-size:18px;
      padding:10px 10px 16px;
    }
    .nav{
      display:flex;
      flex-direction:column;
      gap:6px;
      margin-top:6px;
    }
    .nav a{
      display:flex;
      align-items:center;
      gap:12px;
      padding:12px 12px;
      border-radius:12px;
      color:var(--text);
      text-decoration:none;
      font-weight:700;
      opacity:.92;
    }
    .nav a:hover{
      background:rgba(47,60,255,0.07);
      opacity:1;
    }
    .nav a.active{
      background:var(--primary);
      color:#fff;
      box-shadow:0 10px 18px rgba(47,60,255,0.18);
    }
    .nav svg{ width:18px; height:18px; flex:0 0 auto; }
    .nav a.active svg path,
    .nav a.active svg rect,
    .nav a.active svg circle{
      stroke:#fff;
    }

    .content{
      flex:1;
      display:flex;
      flex-direction:column;
      min-width:0;
    }
    .topbar{
      height:64px;
      background:var(--card);
      border-bottom:1px solid var(--border);
      display:flex;
      align-items:center;
      justify-content:space-between;
      padding:0 18px;
    }
    .userbox{
      display:flex;
      flex-direction:column;
      gap:2px;
      min-width:0;
    }
    .userbox .name{ font-weight:900; }
    .userbox .email{ color:var(--muted); font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:420px; }
    .logout{
      display:inline-flex;
      align-items:center;
      gap:10px;
      text-decoration:none;
      color:var(--text);
      font-weight:800;
      padding:10px 12px;
      border-radius:12px;
      border:1px solid var(--border);
      background:#fff;
    }
    .logout:hover{ transform: translateY(-1px); }

    .page{
      padding:22px 22px 36px;
      max-width:1200px;
      width:100%;
    }
    h1{
      margin: 8px 0 18px;
      font-size: 34px;
      letter-spacing: -0.6px;
    }

    .cards{
      display:grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap:18px;
      margin-bottom:18px;
    }
    .card{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow2);
      padding:18px;
      display:flex;
      flex-direction:column;
      gap:10px;
      min-height:120px;
    }
    .card .icon{
      width:44px;
      height:44px;
      border-radius:12px;
      display:flex;
      align-items:center;
      justify-content:center;
      background:rgba(47,60,255,0.10);
    }
    .card .label{ color:var(--muted); font-weight:800; font-size:13px; }
    .card .value{ font-size:34px; font-weight:900; letter-spacing:-0.6px; }

    .panel{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow2);
      padding:18px;
    }
    .panel h2{
      margin: 0 0 12px;
      font-size: 20px;
      letter-spacing: -0.2px;
    }
    .activity{
      display:flex;
      flex-direction:column;
      gap:0;
    }
    .activity-item{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:16px;
      padding:14px 0;
      border-top:1px solid var(--border);
    }
    .activity-item:first-child{ border-top:0; }
    .left{
      display:flex;
      gap:12px;
      align-items:flex-start;
      min-width:0;
    }
    .dot{
      width:10px;
      height:10px;
      border-radius:999px;
      margin-top:6px;
      background: var(--primary);
      flex:0 0 auto;
    }
    .activity-text{
      font-weight:800;
      color:var(--text);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      max-width:720px;
    }
    .time{
      color:var(--muted);
      font-weight:700;
      font-size:13px;
      white-space:nowrap;
      flex:0 0 auto;
    }

    @media (max-width: 1100px){
      .cards{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 860px){
      .sidebar{ display:none; }
      .cards{ grid-template-columns: 1fr; }
      .userbox .email{ max-width:220px; }
      .activity-text{ max-width:360px; }
    }
  </style>
</head>
<body>

<div class="layout">
  <aside class="sidebar">
    <div class="brand">UMS</div>

    <nav class="nav">
      <a class="active" href="index.php">
        <svg viewBox="0 0 24 24" fill="none">
          <rect x="3" y="3" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="14" y="3" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="3" y="14" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="14" y="14" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
        </svg>
        Dashboard
      </a>

      <a href="student/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <circle cx="9" cy="7" r="4" stroke="#0f172a" stroke-width="2"/>
          <path d="M17 11c2.2 0 4 1.8 4 4v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M1 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Students
      </a>

      <a href="teacher/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="7" r="4" stroke="#0f172a" stroke-width="2"/>
          <path d="M4 21v-2a8 8 0 0 1 16 0v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Teachers
      </a>

      <a href="department/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M4 21V8l8-5 8 5v13" stroke="#0f172a" stroke-width="2" stroke-linejoin="round"/>
          <path d="M9 21v-6h6v6" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Departments
      </a>

      <a href="course/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M4 4v15.5" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M20 22V6a2 2 0 0 0-2-2H6.5A2.5 2.5 0 0 0 4 6.5" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Courses
      </a>

      <a href="enrollment/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M8 6h13" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M8 12h13" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M8 18h13" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M3 6h.01" stroke="#0f172a" stroke-width="3" stroke-linecap="round"/>
          <path d="M3 12h.01" stroke="#0f172a" stroke-width="3" stroke-linecap="round"/>
          <path d="M3 18h.01" stroke="#0f172a" stroke-width="3" stroke-linecap="round"/>
        </svg>
        Enrollments
      </a>

      <a href="result/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M7 3h10a2 2 0 0 1 2 2v16l-2-1-2 1-2-1-2 1-2-1-2 1V5a2 2 0 0 1 2-2Z" stroke="#0f172a" stroke-width="2" stroke-linejoin="round"/>
          <path d="M9 8h6" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M9 12h6" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Results
      </a>
    </nav>
  </aside>

  <main class="content">
    <div class="topbar">
      <div class="userbox">
        <div class="name"><?php echo htmlspecialchars($name); ?></div>
        <div class="email"><?php echo htmlspecialchars($email); ?></div>
      </div>

      <a class="logout" href="logout.php">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M10 17l5-5-5-5" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M15 12H3" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M21 3v18" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Logout
      </a>
    </div>

    <div class="page">
      <h1>Dashboard Overview</h1>

      <section class="cards">
        <div class="card">
          <div class="icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
              <circle cx="9" cy="7" r="4" stroke="#2f3cff" stroke-width="2"/>
              <path d="M17 11c2.2 0 4 1.8 4 4v2" stroke="#2f3cff" stroke-width="2" stroke-linecap="round"/>
              <path d="M1 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2" stroke="#2f3cff" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </div>
          <div class="label">Total Students</div>
          <div class="value"><?php echo number_format($totalStudents); ?></div>
        </div>

        <div class="card">
          <div class="icon" aria-hidden="true" style="background: rgba(16,185,129,0.12);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
              <circle cx="12" cy="7" r="4" stroke="#10b981" stroke-width="2"/>
              <path d="M4 21v-2a8 8 0 0 1 16 0v2" stroke="#10b981" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </div>
          <div class="label">Total Teachers</div>
          <div class="value"><?php echo number_format($totalTeachers); ?></div>
        </div>

        <div class="card">
          <div class="icon" aria-hidden="true" style="background: rgba(245,158,11,0.14);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
              <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" stroke="#f59e0b" stroke-width="2" stroke-linecap="round"/>
              <path d="M4 4v15.5" stroke="#f59e0b" stroke-width="2" stroke-linecap="round"/>
              <path d="M20 22V6a2 2 0 0 0-2-2H6.5A2.5 2.5 0 0 0 4 6.5" stroke="#f59e0b" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </div>
          <div class="label">Total Courses</div>
          <div class="value"><?php echo number_format($totalCourses); ?></div>
        </div>

        <div class="card">
          <div class="icon" aria-hidden="true" style="background: rgba(139,92,246,0.14);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
              <rect x="6" y="3" width="12" height="18" rx="2" stroke="#8b5cf6" stroke-width="2"/>
              <path d="M9 7h6" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round"/>
              <path d="M9 11h6" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round"/>
              <path d="M9 15h6" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </div>
          <div class="label">Total Enrollments</div>
          <div class="value"><?php echo number_format($totalEnrollments); ?></div>
        </div>
      </section>

      <section class="panel">
        <h2>Recent Activity</h2>
        <div class="activity">
          <div class="activity-item">
            <div class="left">
              <div class="dot" style="background:#2f3cff;"></div>
              <div class="activity-text">New student enrolled in Computer Science</div>
            </div>
            <div class="time">2 hours ago</div>
          </div>

          <div class="activity-item">
            <div class="left">
              <div class="dot" style="background:#10b981;"></div>
              <div class="activity-text">Course "Database Management" updated</div>
            </div>
            <div class="time">5 hours ago</div>
          </div>

          <div class="activity-item">
            <div class="left">
              <div class="dot" style="background:#f59e0b;"></div>
              <div class="activity-text">Results published for Semester Fall 2025</div>
            </div>
            <div class="time">1 day ago</div>
          </div>

          <div class="activity-item">
            <div class="left">
              <div class="dot" style="background:#8b5cf6;"></div>
              <div class="activity-text">New teacher added to Engineering Department</div>
            </div>
            <div class="time">2 days ago</div>
          </div>
        </div>
      </section>

    </div>
  </main>
</div>

</body>
</html>