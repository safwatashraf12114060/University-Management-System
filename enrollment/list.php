<?php
session_start();
require_once __DIR__ . "/../db.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$debug = (int)($_GET["debug"] ?? 0) === 1;

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
}

function fmtTime($t) {
    if ($t instanceof DateTime) return $t->format("H:i");
    if (is_string($t) && $t !== "") return substr($t, 0, 5);
    return "";
}

$semester = trim($_GET["semester"] ?? "");
$year = trim($_GET["year"] ?? "");
$q = trim($_GET["q"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = (int)($_GET["per_page"] ?? 10);
if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 10;

$success = (int)($_GET["success"] ?? 0);
$errorMsg = "";
$okMsg = "";

if ($success === 1) $okMsg = "Enrollment created successfully.";
if ($success === 2) $okMsg = "Enrollment deleted successfully.";

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "delete") {
    $token = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $errorMsg = "Invalid request token.";
    } else {
        $enrollmentId = (int)($_POST["enrollment_id"] ?? 0);
        if ($enrollmentId <= 0) {
            $errorMsg = "Invalid enrollment id.";
        } else {
            $delSql = "DELETE FROM ENROLLMENT WHERE enrollment_id = ?";
            $delStmt = sqlsrv_query($conn, $delSql, [$enrollmentId]);
            if ($delStmt === false) {
                $errorMsg = "Delete failed.";
                if ($debug) {
                    $errorMsg .= " " . print_r(sqlsrv_errors(), true);
                }
            } else {
                header("Location: list.php?success=2");
                exit();
            }
        }
    }
}

$params = [];
$where = "1=1";

if ($semester !== "") {
    $where .= " AND e.semester = ?";
    $params[] = $semester;
}
if ($year !== "") {
    $where .= " AND e.year = ?";
    $params[] = (int)$year;
}

if ($q !== "") {
    $where .= " AND (s.student_id LIKE ? OR s.name LIKE ? OR c.course_name LIKE ? OR CONVERT(VARCHAR(50), c.course_id) LIKE ?)";
    $like = "%" . $q . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$countSql = "
    SELECT COUNT(*) AS total_rows
    FROM ENROLLMENT e
    JOIN STUDENT s ON s.student_id = e.student_id
    JOIN COURSE c ON c.course_id = e.course_id
    WHERE $where
";
$countStmt = sqlsrv_query($conn, $countSql, $params);

$totalRows = 0;
if ($countStmt !== false) {
    $countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $totalRows = (int)($countRow["total_rows"] ?? 0);
} else {
    $errorMsg = "Query failed.";
    if ($debug) $errorMsg .= " " . print_r(sqlsrv_errors(), true);
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;

$sql = "
    SELECT
      e.enrollment_id,
      e.semester,
      e.year,
      e.enrollment_date,
      s.student_id AS student_code,
      s.name AS student_name,
      c.course_id,
      c.course_name,
      c.credit_hours,
      c.schedule_day,
      c.start_time,
      c.end_time
    FROM ENROLLMENT e
    JOIN STUDENT s ON s.student_id = e.student_id
    JOIN COURSE c ON c.course_id = e.course_id
    WHERE $where
    ORDER BY e.year DESC, e.semester DESC, s.name ASC, c.course_name ASC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$paramsPaged = array_merge($params, [$offset, $perPage]);
$stmt = sqlsrv_query($conn, $sql, $paramsPaged);

$rows = [];
if ($stmt !== false) {
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $r;
    }
} else {
    $errorMsg = "Query failed.";
    if ($debug) $errorMsg .= " " . print_r(sqlsrv_errors(), true);
}

function buildQuery(array $override = []) {
    $q = $_GET;
    foreach ($override as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    $qs = http_build_query($q);
    return $qs ? ("?" . $qs) : "";
}

$showFrom = $totalRows === 0 ? 0 : ($offset + 1);
$showTo = min($offset + $perPage, $totalRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Enrollments - UMS</title>
  <style>
    :root{
      --bg:#f5f7fb;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#64748b;
      --border:#e5e7eb;
      --primary:#2f3cff;
      --primary-weak:rgba(47,60,255,0.12);
      --danger:#ef4444;
      --radius:14px;
      --shadow:0 10px 25px rgba(15,23,42,0.08);
      --sidebar:#ffffff;
      --sidebar-active:#2f3cff;
      --sidebar-active-text:#ffffff;
    }
    *{box-sizing:border-box;}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--text);}
    a{color:inherit;text-decoration:none;}
    .app{display:flex;min-height:100vh;}
    .sidebar{
      width:260px;background:var(--sidebar);border-right:1px solid var(--border);
      padding:18px 14px;position:sticky;top:0;height:100vh;
    }
    .brand{display:flex;align-items:center;gap:10px;font-weight:900;font-size:20px;padding:6px 10px;margin-bottom:14px;}
    .nav{display:flex;flex-direction:column;gap:6px;margin-top:10px;}
    .nav a{
      display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:12px;
      color:var(--text);font-weight:800;
    }
    .nav a:hover{background:#f3f4f6;}
    .nav a.active{
      background:var(--sidebar-active);
      color:var(--sidebar-active-text);
    }
    .ico{width:18px;height:18px;display:inline-block}
    .main{flex:1;display:flex;flex-direction:column;}
    .topbar{
      height:60px;background:#ffffff;border-bottom:1px solid var(--border);
      display:flex;align-items:center;justify-content:flex-end;padding:0 18px;gap:14px;
      position:sticky;top:0;z-index:5;
    }
    .logout{
      display:inline-flex;align-items:center;gap:8px;font-weight:900;color:#0f172a;
      padding:8px 10px;border-radius:10px;
    }
    .logout:hover{background:#f3f4f6;}
    .container{padding:22px 22px 28px;}
    .page-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
    .page-title{font-size:34px;font-weight:950;letter-spacing:-0.8px;margin:0;}
    .btn{
      display:inline-flex;align-items:center;justify-content:center;gap:10px;
      padding:11px 14px;border-radius:12px;border:1px solid var(--border);
      background:#fff;font-weight:900;cursor:pointer;
    }
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
    .btn-primary:hover{filter:brightness(0.97);}
    .card{
      background:var(--card);border:1px solid var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow);padding:16px;
    }
    .toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
    .search{
      flex:1;min-width:240px;display:flex;align-items:center;gap:10px;
      border:1px solid var(--border);background:#fff;border-radius:12px;padding:10px 12px;
    }
    .search input{border:none;outline:none;flex:1;font-size:14px;}
    .filters{display:flex;gap:10px;flex-wrap:wrap;}
    .filters input, .filters select{
      padding:10px 12px;border:1px solid var(--border);border-radius:12px;outline:none;background:#fff;
      font-weight:700;font-size:14px;
    }
    .filters input:focus, .filters select:focus{border-color:var(--primary);}
    table{width:100%;border-collapse:separate;border-spacing:0;overflow:hidden;border-radius:14px;}
    thead th{
      text-align:left;font-size:14px;padding:14px 14px;background:#ffffff;border-bottom:1px solid var(--border);
      font-weight:950;color:#111827;
    }
    tbody td{
      padding:14px 14px;border-bottom:1px solid #eef2f7;font-size:14px;color:#0f172a;
    }
    tbody tr:hover{background:#fafbff;}
    .pill{
      display:inline-flex;align-items:center;gap:8px;
      padding:6px 10px;border-radius:999px;background:var(--primary-weak);color:var(--primary);
      font-weight:950;font-size:12px;
    }
    .actions{display:flex;align-items:center;gap:10px;}
    .icon-btn{
      border:none;background:transparent;cursor:pointer;padding:6px;border-radius:10px;
      display:inline-flex;align-items:center;justify-content:center;
    }
    .icon-btn:hover{background:#f3f4f6;}
    .danger{color:var(--danger);}
    .meta{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:12px 2px 0;}
    .muted{color:var(--muted);font-weight:800;}
    .pager{display:flex;align-items:center;gap:8px;}
    .page-btn{
      min-width:40px;height:36px;border-radius:10px;border:1px solid var(--border);
      background:#fff;font-weight:900;display:inline-flex;align-items:center;justify-content:center;
      padding:0 12px;
    }
    .page-btn.active{background:var(--primary);border-color:var(--primary);color:#fff;}
    .msg-ok{margin-bottom:12px;padding:12px 14px;border-radius:12px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:900;}
    .msg-err{margin-bottom:12px;padding:12px 14px;border-radius:12px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:900;white-space:pre-wrap;}
    @media (max-width: 980px){
      .sidebar{display:none;}
      .container{padding:18px 14px;}
      .page-title{font-size:28px;}
    }
  </style>
</head>
<body>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">UMS</div>

      <nav class="nav">
        <a href="../index.php">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 13h8V3H3v10zM13 21h8V11h-8v10zM13 3h8v6h-8V3zM3 21h8v-6H3v6z"/>
          </svg>
          Dashboard
        </a>

        <a href="../students/list.php">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
          Students
        </a>

        <a href="../teachers/list.php">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M4 21v-2a4 4 0 0 1 3-3.87"/>
            <circle cx="12" cy="7" r="4"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            <path d="M8 3.13a4 4 0 0 0 0 7.75"/>
          </svg>
          Teachers
        </a>

        <a href="../departments/list.php">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 22h18"/>
            <path d="M7 22V8"/>
            <path d="M17 22V8"/>
            <path d="M12 2l9 6H3l9-6z"/>
          </svg>
          Departments
        </a>

        <a href="../courses/list.php">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20"/>
            <path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20"/>
            <path d="M6.5 2v20"/>
          </svg>
          Courses
        </a>

        <a class="active" href="list.php">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="4" width="18" height="18" rx="2"/>
            <path d="M7 8h10"/>
            <path d="M7 12h10"/>
            <path d="M7 16h10"/>
          </svg>
          Enrollments
        </a>

        <a href="../results/list.php">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 11l3 3L22 4"/>
            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
          </svg>
          Results
        </a>
      </nav>
    </aside>

    <main class="main">
      <div class="topbar">
        <a class="logout" href="../logout.php" title="Logout">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <path d="M16 17l5-5-5-5"/>
            <path d="M21 12H9"/>
          </svg>
          Logout
        </a>
      </div>

      <div class="container">
        <div class="page-head">
          <h1 class="page-title">Enrollments</h1>
          <a class="btn btn-primary" href="add.php">
            <span style="font-size:18px;line-height:0;">+</span>
            Enroll Student
          </a>
        </div>

        <?php if ($okMsg !== ""): ?>
          <div class="msg-ok"><?php echo h($okMsg); ?></div>
        <?php endif; ?>
        <?php if ($errorMsg !== ""): ?>
          <div class="msg-err"><?php echo h($errorMsg); ?></div>
        <?php endif; ?>

        <div class="card">
          <form method="get" action="list.php" class="toolbar">
            <div class="search">
              <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="7"/>
                <path d="M21 21l-4.3-4.3"/>
              </svg>
              <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search enrollments..." />
            </div>

            <div class="filters">
              <input type="text" name="semester" value="<?php echo h($semester); ?>" placeholder="Semester (e.g., Fall)" />
              <input type="number" name="year" value="<?php echo h($year); ?>" placeholder="Year (e.g., 2025)" />
              <select name="per_page">
                <option value="5" <?php echo $perPage===5?'selected':''; ?>>5</option>
                <option value="10" <?php echo $perPage===10?'selected':''; ?>>10</option>
                <option value="20" <?php echo $perPage===20?'selected':''; ?>>20</option>
                <option value="50" <?php echo $perPage===50?'selected':''; ?>>50</option>
              </select>
              <button class="btn btn-primary" type="submit">Apply</button>
              <a class="btn" href="list.php">Reset</a>
            </div>
          </form>

          <div style="overflow:auto;">
            <table>
              <thead>
                <tr>
                  <th>Student ID</th>
                  <th>Student Name</th>
                  <th>Course Code</th>
                  <th>Course Name</th>
                  <th>Credits</th>
                  <th>Semester</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($rows) === 0): ?>
                  <tr>
                    <td colspan="7" class="muted">No enrollments found.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($rows as $r): ?>
                    <?php
                      $term = trim(((string)($r["semester"] ?? "")) . " " . ((string)($r["year"] ?? "")));
                      $courseCode = (string)($r["course_id"] ?? "");
                    ?>
                    <tr>
                      <td><?php echo h($r["student_code"] ?? ""); ?></td>
                      <td><?php echo h($r["student_name"] ?? ""); ?></td>
                      <td><?php echo h($courseCode); ?></td>
                      <td><?php echo h($r["course_name"] ?? ""); ?></td>
                      <td><?php echo h($r["credit_hours"] ?? ""); ?></td>
                      <td><?php echo h($term); ?></td>
                      <td>
                        <div class="actions">
                          <form method="post" action="list.php" onsubmit="return confirm('Delete this enrollment?');" style="margin:0;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">
                            <input type="hidden" name="enrollment_id" value="<?php echo h($r["enrollment_id"] ?? ""); ?>">
                            <button class="icon-btn danger" type="submit" title="Delete">
                              <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 6h18"/>
                                <path d="M8 6V4h8v2"/>
                                <path d="M19 6l-1 14H6L5 6"/>
                                <path d="M10 11v6"/>
                                <path d="M14 11v6"/>
                              </svg>
                            </button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="meta">
            <div class="muted">
              <?php echo "Showing " . h($showFrom) . " to " . h($showTo) . " of " . h($totalRows) . " enrollments"; ?>
            </div>

            <div class="pager">
              <?php
                $prevDisabled = $page <= 1;
                $nextDisabled = $page >= $totalPages;
              ?>
              <a class="page-btn" href="list.php<?php echo h(buildQuery(["page" => max(1, $page - 1)])); ?>" style="<?php echo $prevDisabled ? 'pointer-events:none;opacity:0.5;' : ''; ?>">
                Previous
              </a>

              <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($p = $start; $p <= $end; $p++):
              ?>
                <a class="page-btn <?php echo $p === $page ? 'active' : ''; ?>" href="list.php<?php echo h(buildQuery(["page" => $p])); ?>">
                  <?php echo h($p); ?>
                </a>
              <?php endfor; ?>

              <a class="page-btn" href="list.php<?php echo h(buildQuery(["page" => min($totalPages, $page + 1)])); ?>" style="<?php echo $nextDisabled ? 'pointer-events:none;opacity:0.5;' : ''; ?>">
                Next
              </a>
            </div>
          </div>

        </div>
      </div>
    </main>
  </div>
</body>
</html>