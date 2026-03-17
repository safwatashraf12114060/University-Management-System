<?php
session_start();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../partials/layout.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

function h($v) {
    return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8");
}

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return isset($row["len"]) && $row["len"] !== null;
}

$courseTable  = "dbo.COURSE";
$deptTable    = "dbo.DEPARTMENT";
$teacherTable = "dbo.TEACHER";

/* ---------- COURSE columns ---------- */
$courseIdCol = null;
foreach (["course_id", "id"] as $c) {
    if (colExists($conn, $courseTable, $c)) { $courseIdCol = $c; break; }
}
if ($courseIdCol === null) $courseIdCol = "course_id";

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

/* ---------- DEPARTMENT columns ---------- */
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

/* ---------- TEACHER columns ---------- */
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

$teacherDeptFkCol = null;
foreach (["dept_id", "department_id"] as $c) {
    if (colExists($conn, $teacherTable, $c)) { $teacherDeptFkCol = $c; break; }
}

/* ---------- filters ---------- */
$search = trim($_GET["q"] ?? "");
$deptFilter = trim($_GET["dept_id"] ?? "");
$teacherFilter = trim($_GET["teacher_id"] ?? "");
$creditFilter = trim($_GET["credit"] ?? "");

$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;

$perPage = 5;
$offset = ($page - 1) * $perPage;

/* ---------- dropdown data ---------- */
$departments = [];
if ($courseDeptFkCol !== null) {
    $deptSql = "SELECT $deptIdCol AS dept_id, $deptNameCol AS dept_name FROM $deptTable ORDER BY $deptNameCol ASC";
    $deptStmt = sqlsrv_query($conn, $deptSql);
    if ($deptStmt) {
        while ($r = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC)) {
            $departments[] = $r;
        }
        sqlsrv_free_stmt($deptStmt);
    }
}

$teachers = [];
if ($courseTeacherFkCol !== null) {
    $teacherSql = "SELECT $teacherIdCol AS teacher_id, $teacherNameCol AS teacher_name FROM $teacherTable";
    $teacherParams = [];

    if ($deptFilter !== "" && $teacherDeptFkCol !== null) {
        $teacherSql .= " WHERE $teacherDeptFkCol = ?";
        $teacherParams[] = (int)$deptFilter;
    }

    $teacherSql .= " ORDER BY $teacherNameCol ASC";

    $teacherStmt = sqlsrv_query($conn, $teacherSql, $teacherParams);
    if ($teacherStmt) {
        while ($r = sqlsrv_fetch_array($teacherStmt, SQLSRV_FETCH_ASSOC)) {
            $teachers[] = $r;
        }
        sqlsrv_free_stmt($teacherStmt);
    }
}

/* ---------- query ---------- */
$where = "1=1";
$params = [];

$joinDept = ($courseDeptFkCol !== null)
    ? "LEFT JOIN $deptTable d ON d.$deptIdCol = c.$courseDeptFkCol"
    : "";

$joinTeacher = ($courseTeacherFkCol !== null)
    ? "LEFT JOIN $teacherTable t ON t.$teacherIdCol = c.$courseTeacherFkCol"
    : "";

if ($search !== "") {
    $searchParts = [];
    if ($courseCodeCol !== null) $searchParts[] = "c.$courseCodeCol LIKE ?";
    $searchParts[] = "c.$courseNameCol LIKE ?";
    if ($courseDeptFkCol !== null) $searchParts[] = "d.$deptNameCol LIKE ?";
    if ($courseTeacherFkCol !== null) $searchParts[] = "t.$teacherNameCol LIKE ?";

    $where .= " AND (" . implode(" OR ", $searchParts) . ")";
    $like = "%" . $search . "%";
    for ($i = 0; $i < count($searchParts); $i++) {
        $params[] = $like;
    }
}

if ($deptFilter !== "" && $courseDeptFkCol !== null) {
    $where .= " AND c.$courseDeptFkCol = ?";
    $params[] = (int)$deptFilter;
}

if ($teacherFilter !== "" && $courseTeacherFkCol !== null) {
    $where .= " AND c.$courseTeacherFkCol = ?";
    $params[] = (int)$teacherFilter;
}

if ($creditFilter !== "" && $creditCol !== null) {
    $where .= " AND CAST(c.$creditCol AS VARCHAR(20)) = ?";
    $params[] = $creditFilter;
}

/* ---------- count ---------- */
$countSql = "
    SELECT COUNT(*) AS total
    FROM $courseTable c
    $joinDept
    $joinTeacher
    WHERE $where
";
$countStmt = sqlsrv_query($conn, $countSql, $params);
$totalRows = 0;

if ($countStmt) {
    $r = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $totalRows = (int)($r["total"] ?? 0);
    sqlsrv_free_stmt($countStmt);
}

$totalPages = (int)ceil(max(1, $totalRows) / $perPage);

/* ---------- list ---------- */
$cols = [];
$cols[] = "c.$courseIdCol AS course_id";
if ($courseCodeCol !== null) $cols[] = "c.$courseCodeCol AS course_code";
$cols[] = "c.$courseNameCol AS course_name";
if ($creditCol !== null) $cols[] = "c.$creditCol AS credit_hours";
if ($courseDeptFkCol !== null) $cols[] = "d.$deptNameCol AS department_name";
if ($courseTeacherFkCol !== null) $cols[] = "t.$teacherNameCol AS teacher_name";

$orderBy = ($courseIdCol !== null) ? "c.$courseIdCol DESC" : "c.$courseNameCol ASC";

$listSql = "
    SELECT " . implode(", ", $cols) . "
    FROM $courseTable c
    $joinDept
    $joinTeacher
    WHERE $where
    ORDER BY $orderBy
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";
$params2 = array_merge($params, [$offset, $perPage]);

$stmt = sqlsrv_query($conn, $listSql, $params2);
$rows = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
}

function pageUrl($p, $q, $deptId, $teacherId, $credit) {
    $qs = [];
    if ($q !== "") $qs["q"] = $q;
    if ($deptId !== "") $qs["dept_id"] = $deptId;
    if ($teacherId !== "") $qs["teacher_id"] = $teacherId;
    if ($credit !== "") $qs["credit"] = $credit;
    $qs["page"] = $p;
    return "list.php?" . http_build_query($qs);
}

$name = $_SESSION["name"] ?? "User";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Courses</title>
  <link rel="stylesheet" href="../assets/app.css">
  <style>
    .filter-row{
      display:grid;
      grid-template-columns: 1.4fr 1fr 1fr 1fr auto auto;
      gap:12px;
      align-items:stretch;
      margin-bottom:16px;
    }
    .filter-row .search{
      margin:0;
    }
    .filter-row select,
    .filter-row .btn{
      height:44px;
    }
    .filter-row select{
      min-width:0;
      width:100%;
      padding:0 12px;
      border:1px solid #dbe1ea;
      border-radius:12px;
      background:#fff;
      color:var(--text);
      font:inherit;
      outline:none;
    }
    .filter-row .btn{
      min-width:110px;
      justify-content:center;
      white-space:nowrap;
    }
    @media (max-width: 1100px){
      .filter-row{
        grid-template-columns:1fr 1fr 1fr;
      }
    }
    @media (max-width: 700px){
      .filter-row{
        grid-template-columns:1fr;
      }
    }
  </style>
</head>
<body>
<div class="layout">

  <?php renderSidebar("courses", "../"); ?>

  <main class="content">
    <?php renderTopbar($name, "", "../logout.php", false); ?>

    <div class="page">
      <div class="header">
        <h1>Courses</h1>
        <a class="btn btn-primary" href="add.php"><span style="font-size:18px;line-height:0;">＋</span> Add Course</a>
      </div>

      <div class="card">

        <form method="get" action="" class="filter-row">
          <div class="search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="11" cy="11" r="7" stroke="#64748b" stroke-width="2"/>
              <path d="M20 20l-3.5-3.5" stroke="#64748b" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <input type="text" name="q" value="<?php echo h($search); ?>" placeholder="Search courses..." />
          </div>

          <select name="dept_id" onchange="this.form.submit()">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?php echo (int)$d["dept_id"]; ?>" <?php echo ((string)$deptFilter === (string)$d["dept_id"]) ? "selected" : ""; ?>>
                <?php echo h($d["dept_name"]); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="teacher_id">
            <option value="">All Teachers</option>
            <?php foreach ($teachers as $t): ?>
              <option value="<?php echo (int)$t["teacher_id"]; ?>" <?php echo ((string)$teacherFilter === (string)$t["teacher_id"]) ? "selected" : ""; ?>>
                <?php echo h($t["teacher_name"]); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="credit">
            <option value="">All Credits</option>
            <?php foreach ([1, 1.5, 2, 3, 4] as $c): ?>
              <option value="<?php echo h($c); ?>" <?php echo ((string)$creditFilter === (string)$c) ? "selected" : ""; ?>>
                <?php echo h($c); ?> Credits
              </option>
            <?php endforeach; ?>
          </select>

          <button class="btn btn-primary" type="submit">Search</button>
          <a class="btn" href="list.php">Reset</a>
        </form>

        <table>
          <thead>
            <tr>
              <th style="width:130px;">Course Code</th>
              <th style="width:280px;">Course Name</th>
              <th style="width:120px;">Credits</th>
              <th style="width:180px;">Department</th>
              <th style="width:180px;">Teacher</th>
              <th style="width:150px; text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($rows) === 0): ?>
              <tr><td colspan="6" class="muted">No courses found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $c): ?>
                <?php
                  $id = (int)$c["course_id"];
                  $code = (string)($c["course_code"] ?? "—");
                  $dept = (string)($c["department_name"] ?? "—");
                  $teacher = (string)($c["teacher_name"] ?? "—");
                  $credits = (string)($c["credit_hours"] ?? "—");
                ?>
                <tr>
                  <td><?php echo h($code); ?></td>
                  <td><?php echo h((string)$c["course_name"]); ?></td>
                  <td><?php echo h($credits); ?></td>
                  <td><?php echo h($dept); ?></td>
                  <td><?php echo h($teacher); ?></td>
                  <td style="text-align:right;">
                    <div class="actions">
                      <a class="icon-btn icon-edit" href="edit.php?id=<?php echo $id; ?>" title="Edit">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                          <path d="M12 20h9" stroke-width="2" stroke-linecap="round"/>
                          <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z" stroke-width="2" stroke-linejoin="round"/>
                        </svg>
                      </a>
                      <a class="icon-btn icon-del" href="delete.php?id=<?php echo $id; ?>" title="Delete"
                         onclick="return confirm('Delete this course?');">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                          <path d="M3 6h18" stroke-width="2" stroke-linecap="round"/>
                          <path d="M8 6V4h8v2" stroke-width="2" stroke-linejoin="round"/>
                          <path d="M6 6l1 16h10l1-16" stroke-width="2" stroke-linejoin="round"/>
                          <path d="M10 11v6" stroke-width="2" stroke-linecap="round"/>
                          <path d="M14 11v6" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="footer">
          <div class="muted">Showing <?php echo min($perPage, max(0, $totalRows - $offset)); ?> of <?php echo $totalRows; ?> courses</div>
          <div class="pager">
            <a href="<?php echo h(pageUrl(max(1, $page - 1), $search, $deptFilter, $teacherFilter, $creditFilter)); ?>">Previous</a>
            <?php
              $start = max(1, $page - 2);
              $end = min($totalPages, $page + 2);
              for ($p = $start; $p <= $end; $p++):
            ?>
              <a class="<?php echo $p === $page ? "active" : ""; ?>" href="<?php echo h(pageUrl($p, $search, $deptFilter, $teacherFilter, $creditFilter)); ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <a href="<?php echo h(pageUrl(min($totalPages, $page + 1), $search, $deptFilter, $teacherFilter, $creditFilter)); ?>">Next</a>
          </div>
        </div>

      </div>
    </div>
  </main>
</div>
</body>
</html>