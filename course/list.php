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

$debug = ((int)($_GET["debug"] ?? 0) === 1);

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
}

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return isset($row["len"]) && $row["len"] !== null;
}

function sqlErrText() {
    $errs = sqlsrv_errors();
    if (!$errs) return "Unknown SQL error";
    $lines = [];
    foreach ($errs as $e) {
        $lines[] = ($e["SQLSTATE"] ?? "") . " | " . ($e["code"] ?? "") . " | " . ($e["message"] ?? "");
    }
    return implode("\n", $lines);
}

function addSqlsrvError($baseMsg, $debug) {
    if (!$debug) return $baseMsg;
    return $baseMsg . "\n" . sqlErrText();
}

$courseTable  = "dbo.COURSE";
$deptTable    = "dbo.DEPARTMENT";
$teacherTable = "dbo.TEACHER";

$courseIdCol = colExists($conn, $courseTable, "course_id") ? "course_id" : "id";

$courseCodeCol = null;
foreach (["course_code", "code"] as $c) {
    if (colExists($conn, $courseTable, $c)) { $courseCodeCol = $c; break; }
}

$courseNameCol = null;
foreach (["course_name", "name", "title"] as $c) {
    if (colExists($conn, $courseTable, $c)) { $courseNameCol = $c; break; }
}
if ($courseNameCol === null) $courseNameCol = "course_name";

$creditCol = null;
foreach (["credit_hours", "credits", "credit"] as $c) {
    if (colExists($conn, $courseTable, $c)) { $creditCol = $c; break; }
}

$courseDeptFkCol = null;
foreach (["dept_id", "department_id"] as $c) {
    if (colExists($conn, $courseTable, $c)) { $courseDeptFkCol = $c; break; }
}

$courseTeacherFkCol = null;
foreach (["teacher_id"] as $c) {
    if (colExists($conn, $courseTable, $c)) { $courseTeacherFkCol = $c; break; }
}

$deptIdCol = null;
foreach (["dept_id", "department_id", "id"] as $c) {
    if (colExists($conn, $deptTable, $c)) { $deptIdCol = $c; break; }
}
if ($deptIdCol === null) $deptIdCol = "dept_id";

$deptNameCol = null;
foreach (["name", "department_name", "dept_name"] as $c) {
    if (colExists($conn, $deptTable, $c)) { $deptNameCol = $c; break; }
}
if ($deptNameCol === null) $deptNameCol = "name";

$teacherIdCol = null;
foreach (["teacher_id", "id"] as $c) {
    if (colExists($conn, $teacherTable, $c)) { $teacherIdCol = $c; break; }
}
if ($teacherIdCol === null) $teacherIdCol = "teacher_id";

$teacherNameCol = null;
foreach (["name", "teacher_name", "full_name"] as $c) {
    if (colExists($conn, $teacherTable, $c)) { $teacherNameCol = $c; break; }
}
if ($teacherNameCol === null) $teacherNameCol = "name";

$q = trim($_GET["q"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = (int)($_GET["per_page"] ?? 10);
if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 10;

$success = (int)($_GET["success"] ?? 0);
$okMsg = "";
$errorMsg = "";

if ($success === 1) $okMsg = "Course added successfully.";
if ($success === 2) $okMsg = "Course deleted successfully.";

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "delete") {
    $token = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $errorMsg = "Invalid request token.";
    } else {
        $courseId = (int)($_POST["course_id"] ?? 0);
        if ($courseId <= 0) {
            $errorMsg = "Invalid course id.";
        } else {
            $delSql = "DELETE FROM $courseTable WHERE $courseIdCol = ?";
            $delSt = sqlsrv_query($conn, $delSql, [$courseId]);
            if ($delSt === false) {
                $errorMsg = addSqlsrvError("Delete failed.", $debug);
            } else {
                header("Location: list.php?success=2");
                exit();
            }
        }
    }
}

$params = [];
$where = "1=1";

$searchCols = [];
if ($courseCodeCol) $searchCols[] = "c.$courseCodeCol LIKE ?";
$searchCols[] = "c.$courseNameCol LIKE ?";
if ($courseDeptFkCol) $searchCols[] = "d.$deptNameCol LIKE ?";
if ($courseTeacherFkCol) $searchCols[] = "t.$teacherNameCol LIKE ?";

if ($q !== "" && count($searchCols) > 0) {
    $where .= " AND (" . implode(" OR ", $searchCols) . ")";
    $like = "%" . $q . "%";
    for ($i = 0; $i < count($searchCols); $i++) $params[] = $like;
}

$joinDept    = ($courseDeptFkCol !== null) ? "LEFT JOIN $deptTable d ON d.$deptIdCol = c.$courseDeptFkCol" : "";
$joinTeacher = ($courseTeacherFkCol !== null) ? "LEFT JOIN $teacherTable t ON t.$teacherIdCol = c.$courseTeacherFkCol" : "";

$countSql = "
    SELECT COUNT(*) AS total_rows
    FROM $courseTable c
    $joinDept
    $joinTeacher
    WHERE $where
";
$countSt = sqlsrv_query($conn, $countSql, $params);

$totalRows = 0;
if ($countSt !== false) {
    $row = sqlsrv_fetch_array($countSt, SQLSRV_FETCH_ASSOC);
    $totalRows = (int)($row["total_rows"] ?? 0);
    sqlsrv_free_stmt($countSt);
} else {
    $errorMsg = addSqlsrvError("Query failed.", $debug);
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$selectCols = [];
$selectCols[] = "c.$courseIdCol AS course_id";
if ($courseCodeCol) $selectCols[] = "c.$courseCodeCol AS course_code";
$selectCols[] = "c.$courseNameCol AS course_name";
if ($creditCol) $selectCols[] = "c.$creditCol AS credit_hours";
if ($courseDeptFkCol) $selectCols[] = "d.$deptNameCol AS department_name";
if ($courseTeacherFkCol) $selectCols[] = "t.$teacherNameCol AS teacher_name";

$listSql = "
    SELECT " . implode(", ", $selectCols) . "
    FROM $courseTable c
    $joinDept
    $joinTeacher
    WHERE $where
    ORDER BY " . ($courseCodeCol ? "c.$courseCodeCol" : "c.$courseIdCol") . " ASC, c.$courseNameCol ASC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$paramsPaged = array_merge($params, [$offset, $perPage]);
$listSt = sqlsrv_query($conn, $listSql, $paramsPaged);

$rows = [];
if ($listSt !== false) {
    while ($r = sqlsrv_fetch_array($listSt, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
    sqlsrv_free_stmt($listSt);
} else {
    $errorMsg = addSqlsrvError("Query failed.", $debug);
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

$displayName = $_SESSION["name"] ?? "User";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Courses - UMS</title>
  <style>
    :root{
      --bg:#f5f7fb;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#64748b;
      --primary:#2f3cff;
      --border:#e5e7eb;
      --shadow2:0 8px 22px rgba(0,0,0,0.06);
      --radius:14px;
      --danger:#ef4444;
      --sidebar:#ffffff;
    }
    *{box-sizing:border-box;}
    body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);}
    a{color:inherit;text-decoration:none;}
    .layout{display:flex;min-height:100vh;}
    .sidebar{width:260px;background:var(--sidebar);border-right:1px solid var(--border);padding:18px 14px;}
    .brand{font-weight:900;letter-spacing:.4px;font-size:18px;padding:10px 10px 16px;}
    .nav{display:flex;flex-direction:column;gap:6px;margin-top:6px;}
    .nav a{
      display:flex;align-items:center;gap:12px;
      padding:12px 12px;border-radius:12px;
      color:var(--text);text-decoration:none;font-weight:700;opacity:.92;
    }
    .nav a:hover{background:rgba(47,60,255,0.07);opacity:1;}
    .nav a.active{background:var(--primary);color:#fff;box-shadow:0 10px 18px rgba(47,60,255,0.18);opacity:1;}
    .nav svg{width:18px;height:18px;flex:0 0 auto;}
    .nav a.active svg path,
    .nav a.active svg rect,
    .nav a.active svg circle{stroke:#fff;}
    .content{flex:1;display:flex;flex-direction:column;min-width:0;}
    .topbar{
      height:64px;background:var(--card);border-bottom:1px solid var(--border);
      display:flex;align-items:center;justify-content:space-between;padding:0 18px;
    }
    .userbox{font-weight:900;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .logout{
      display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:800;
      padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:#fff;
    }
    .logout:hover{transform:translateY(-1px);}
    .page{padding:22px 22px 36px;max-width:1200px;width:100%;}
    .page-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
    h1{margin:6px 0 0;font-size:34px;letter-spacing:-0.6px;}
    .btn{
      display:inline-flex;align-items:center;justify-content:center;gap:10px;
      padding:12px 16px;border-radius:12px;border:1px solid var(--border);
      background:#fff;color:var(--text);text-decoration:none;font-weight:900;cursor:pointer;
    }
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
    .btn-primary:hover{filter:brightness(.97);}
    .card{
      background:var(--card);border:1px solid var(--border);border-radius:var(--radius);
      box-shadow:var(--shadow2);padding:18px;
    }
    .alert-ok{
      margin-bottom:12px;padding:10px 12px;border-radius:10px;
      background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:800;font-size:14px;
    }
    .alert-err{
      margin-bottom:12px;padding:10px 12px;border-radius:10px;
      background:#ffe8e8;border:1px solid #ffb3b3;color:#8a0000;font-weight:800;font-size:14px;
      white-space:pre-wrap;
    }
    .toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
    .search{
      flex:1;min-width:280px;display:flex;align-items:center;gap:10px;
      border:1px solid var(--border);background:#fff;border-radius:12px;padding:10px 12px;
    }
    .search input{border:none;outline:none;flex:1;font-size:14px;}
    select{
      padding:10px 12px;border:1px solid var(--border);border-radius:12px;outline:none;background:#fff;font-weight:800;
    }
    select:focus{border-color:var(--primary);}
    table{width:100%;border-collapse:separate;border-spacing:0;overflow:hidden;border-radius:14px;}
    thead th{
      text-align:left;font-size:14px;padding:14px 14px;background:#ffffff;border-bottom:1px solid var(--border);
      font-weight:900;color:#111827;
    }
    tbody td{padding:14px 14px;border-bottom:1px solid #eef2f7;font-size:14px;color:#0f172a;}
    tbody tr:hover{background:#fafbff;}
    .actions{display:flex;align-items:center;gap:10px;}
    .icon-btn{
      border:1px solid var(--border);
      background:#fff;
      cursor:pointer;
      padding:8px;
      border-radius:10px;
      display:inline-flex;align-items:center;justify-content:center;
    }
    .icon-btn:hover{background:#f3f4f6;}
    .icon-btn svg{width:18px;height:18px;}
    .danger{color:var(--danger);}
    .meta{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:12px 2px 0;}
    .muted{color:var(--muted);font-weight:800;}
    .pager{display:flex;align-items:center;gap:8px;}
    .page-btn{
      min-width:40px;height:36px;border-radius:10px;border:1px solid var(--border);
      background:#fff;font-weight:900;display:inline-flex;align-items:center;justify-content:center;padding:0 12px;
    }
    .page-btn.active{background:var(--primary);border-color:var(--primary);color:#fff;}
    @media(max-width:860px){
      .sidebar{display:none;}
      .toolbar{gap:10px;}
    }
  </style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand">UMS</div>
    <nav class="nav">
      <a href="../index.php">
        <svg viewBox="0 0 24 24" fill="none">
          <rect x="3" y="3" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="14" y="3" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="3" y="14" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="14" y="14" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
        </svg>
        Dashboard
      </a>

      <a href="../student/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <circle cx="9" cy="7" r="4" stroke="#0f172a" stroke-width="2"/>
          <path d="M17 11c2.2 0 4 1.8 4 4v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M1 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Students
      </a>

      <a href="../teacher/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="7" r="4" stroke="#0f172a" stroke-width="2"/>
          <path d="M4 21v-2a8 8 0 0 1 16 0v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Teachers
      </a>

      <a href="../department/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M4 21V8l8-5 8 5v13" stroke="#0f172a" stroke-width="2" stroke-linejoin="round"/>
          <path d="M9 21v-6h6v6" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Departments
      </a>

      <a class="active" href="list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M4 4v15.5" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M20 22V6a2 2 0 0 0-2-2H6.5A2.5 2.5 0 0 0 4 6.5" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Courses
      </a>

      <a href="../enrollment/list.php">
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

      <a href="../result/list.php">
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
      <div class="userbox"><?php echo h($displayName); ?></div>
      <a class="logout" href="../logout.php" title="Logout">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M10 17l5-5-5-5" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M15 12H3" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M21 3v18" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Logout
      </a>
    </div>

    <div class="page">
      <div class="page-head">
        <h1>Courses</h1>
        <a class="btn btn-primary" href="add.php">
          <span style="font-size:18px;line-height:0;">+</span>
          Add Course
        </a>
      </div>

      <?php if ($okMsg !== ""): ?>
        <div class="alert-ok"><?php echo h($okMsg); ?></div>
      <?php endif; ?>
      <?php if ($errorMsg !== ""): ?>
        <div class="alert-err"><?php echo h($errorMsg); ?></div>
      <?php endif; ?>

      <div class="card">
        <form method="get" action="list.php" class="toolbar">
          <div class="search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="11" cy="11" r="7" stroke="#64748b" stroke-width="2"/>
              <path d="M21 21l-4.3-4.3" stroke="#64748b" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search courses..." />
          </div>

          <select name="per_page" aria-label="Rows per page">
            <option value="5"  <?php echo $perPage===5?'selected':''; ?>>5</option>
            <option value="10" <?php echo $perPage===10?'selected':''; ?>>10</option>
            <option value="20" <?php echo $perPage===20?'selected':''; ?>>20</option>
            <option value="50" <?php echo $perPage===50?'selected':''; ?>>50</option>
          </select>

          <button class="btn btn-primary" type="submit">Search</button>
          <a class="btn" href="list.php">Reset</a>
        </form>

        <div style="overflow:auto;">
          <table>
            <thead>
              <tr>
                <th>Course Code</th>
                <th>Course Name</th>
                <th>Credits</th>
                <th>Department</th>
                <th>Assigned Teacher</th>
                <th style="text-align:left;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($rows) === 0): ?>
                <tr>
                  <td colspan="6" class="muted">No courses found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <?php
                    $courseId = (int)($r["course_id"] ?? 0);
                    $code = (string)($r["course_code"] ?? "");
                    if ($code === "") $code = (string)$courseId;
                    $cname = (string)($r["course_name"] ?? "");
                    $credits = (string)($r["credit_hours"] ?? "");
                    $dept = (string)($r["department_name"] ?? "-");
                    $teacher = (string)($r["teacher_name"] ?? "-");
                  ?>
                  <tr>
                    <td><strong><?php echo h($code); ?></strong></td>
                    <td><strong><?php echo h($cname); ?></strong></td>
                    <td><?php echo h($credits); ?></td>
                    <td><?php echo h($dept !== "" ? $dept : "-"); ?></td>
                    <td><?php echo h($teacher !== "" ? $teacher : "-"); ?></td>
                    <td>
                      <div class="actions">
                        <a class="icon-btn" href="edit.php?id=<?php echo h($courseId); ?>" title="Edit" aria-label="Edit">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 20h9"/>
                            <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>
                          </svg>
                        </a>

                        <form method="post" action="list.php" onsubmit="return confirm('Delete this course?');" style="margin:0;">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">
                          <input type="hidden" name="course_id" value="<?php echo h($courseId); ?>">
                          <button class="icon-btn danger" type="submit" title="Delete" aria-label="Delete">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
            <?php echo "Showing " . h($showFrom) . " to " . h($showTo) . " of " . h($totalRows) . " courses"; ?>
          </div>

          <div class="pager">
            <?php $prevDisabled = $page <= 1; $nextDisabled = $page >= $totalPages; ?>

            <a class="page-btn" href="list.php<?php echo h(buildQuery(["page" => max(1, $page - 1)])); ?>"
               style="<?php echo $prevDisabled ? 'pointer-events:none;opacity:0.5;' : ''; ?>">
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

            <a class="page-btn" href="list.php<?php echo h(buildQuery(["page" => min($totalPages, $page + 1)])); ?>"
               style="<?php echo $nextDisabled ? 'pointer-events:none;opacity:0.5;' : ''; ?>">
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