<?php
session_start();
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/partials/layout.php";
require_once __DIR__ . "/partials/activity_log.php";

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

function h($v) {
    return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8");
}

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
$departmentTable = resolveTable($conn, "DEPARTMENT");
$enrollmentTable = resolveTable($conn, "ENROLLMENT");

$totalStudents = countRows($conn, $studentTable);
$totalTeachers = countRows($conn, $teacherTable);
$totalCourses = countRows($conn, $courseTable);
$totalDepartments = countRows($conn, $departmentTable);
$totalEnrollments = countRows($conn, $enrollmentTable);
$activities = umsFetchRecentActivities($conn, 5);
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
<body class="dashboard-page">

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
          <div class="icon" aria-hidden="true" style="background: rgba(139,92,246,0.14);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
              <path d="M3 8.5 12 4l9 4.5" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M5 10v6.5C5 17.9 8.1 20 12 20s7-2.1 7-3.5V10" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M9 12.5v2.5" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round"/>
              <path d="M15 12.5v2.5" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </div>
          <div class="label">Total Departments</div>
          <div class="value"><?php echo number_format($totalDepartments); ?></div>
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
          <div class="icon" aria-hidden="true" style="background: rgba(236,72,153,0.14);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
              <rect x="6" y="3" width="12" height="18" rx="2" stroke="#ec4899" stroke-width="2"/>
              <path d="M9 7h6" stroke="#ec4899" stroke-width="2" stroke-linecap="round"/>
              <path d="M9 11h6" stroke="#ec4899" stroke-width="2" stroke-linecap="round"/>
              <path d="M9 15h6" stroke="#ec4899" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </div>
          <div class="label">Total Enrollments</div>
          <div class="value"><?php echo number_format($totalEnrollments); ?></div>
        </div>
      </section>

      <section class="panel">
        <h2>Recent Activity</h2>
        <div class="activity">
          <?php if (count($activities) === 0): ?>
            <div class="activity-item">
              <div class="left">
                <div class="dot"></div>
                <div class="activity-text">No recent activity found.</div>
              </div>
            </div>
          <?php else: ?>
            <?php foreach ($activities as $activity): ?>
              <div class="activity-item">
                <div class="left">
                  <div class="dot" style="background:<?php echo h(umsActivityDotColor($activity["activity_type"] ?? "")); ?>;"></div>
                  <div class="activity-text"><?php echo h($activity["message"] ?? ""); ?></div>
                </div>
                <div class="time"><?php echo h(umsActivityTimeText($activity["created_at"] ?? "")); ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>

    </div>
  </main>
</div>

</body>
</html>
