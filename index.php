<?php
session_start();
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/partials/layout.php";

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

function tableExists($conn, $schemaDotTable) {
    $stmt = sqlsrv_query($conn, "SELECT OBJECT_ID(?) AS oid", [$schemaDotTable]);
    if ($stmt === false) return false;

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return isset($row["oid"]) && $row["oid"] !== null;
}

function countRows($conn, $table) {
    $sql = "SELECT COUNT(*) AS total FROM $table";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) return 0;

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return (int)($row["total"] ?? 0);
}

function resolveTable($conn, $baseName) {
    $dbo = "dbo." . $baseName;
    if (tableExists($conn, $dbo)) return $dbo;
    if (tableExists($conn, $baseName)) return $baseName;
    return $dbo;
}

$studentTable = resolveTable($conn, "STUDENT");
$teacherTable = resolveTable($conn, "TEACHER");
$courseTable = resolveTable($conn, "COURSE");
$enrollmentTable = resolveTable($conn, "ENROLLMENT");

$totalStudents = countRows($conn, $studentTable);
$totalTeachers = countRows($conn, $teacherTable);
$totalCourses = countRows($conn, $courseTable);
$totalEnrollments = countRows($conn, $enrollmentTable);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard</title>
  <link rel="stylesheet" href="assets/app.css">

  <script>
    (function () {
      function lock() {
        history.pushState(null, "", location.href);
      }

      lock();

      window.addEventListener("popstate", function () {
        lock();
      });

      window.addEventListener("pageshow", function (event) {
        if (event.persisted) {
          window.location.reload();
        }
      });
    })();
  </script>
</head>
<body>

<div class="layout">
  <?php renderSidebar("dashboard", ""); ?>

  <main class="content">
    <?php renderTopbar($name, $email, "logout.php", true); ?>

    <div class="page">
      <h1 style="margin:8px 0 18px;">Dashboard Overview</h1>

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