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

if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
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

function buildQuery(array $override = []) {
    $q = $_GET;
    foreach ($override as $k => $v) {
        if ($v === null || $v === "") {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    $qs = http_build_query($q);
    return $qs ? ("?" . $qs) : "";
}

$name = $_SESSION["name"] ?? "User";

$studentTable = "dbo.STUDENT";
$courseTable = "dbo.COURSE";
$deptTable = "dbo.DEPARTMENT";
$enrollTable = "dbo.ENROLLMENT";

$studentNameCol = "name";
if (colExists($conn, $studentTable, "student_name")) $studentNameCol = "student_name";
if (colExists($conn, $studentTable, "full_name")) $studentNameCol = "full_name";

$deptNameCol = "name";
if (colExists($conn, $deptTable, "department_name")) $deptNameCol = "department_name";
if (colExists($conn, $deptTable, "dept_name")) $deptNameCol = "dept_name";

$courseNameCol = "course_name";
if (colExists($conn, $courseTable, "name")) $courseNameCol = "name";
if (colExists($conn, $courseTable, "title")) $courseNameCol = "title";

$courseCodeCol = null;
foreach (["course_code", "code"] as $c) {
    if (colExists($conn, $courseTable, $c)) {
        $courseCodeCol = $c;
        break;
    }
}

$creditCol = "credit_hours";
if (colExists($conn, $courseTable, "credits")) $creditCol = "credits";
if (colExists($conn, $courseTable, "credit")) $creditCol = "credit";

$hasYear = colExists($conn, $enrollTable, "year");
$semesterIsNumeric = true;
$semTypeStmt = sqlsrv_query($conn, "
    SELECT DATA_TYPE AS type_name
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'ENROLLMENT' AND COLUMN_NAME = 'semester'
");
if ($semTypeStmt !== false) {
    $semTypeRow = sqlsrv_fetch_array($semTypeStmt, SQLSRV_FETCH_ASSOC);
    $semesterIsNumeric = in_array(strtolower((string)($semTypeRow["type_name"] ?? "")), ["int", "smallint", "tinyint", "bigint"], true);
    sqlsrv_free_stmt($semTypeStmt);
}

$q = trim($_GET["q"] ?? "");
$term = trim($_GET["term"] ?? "");
$year = trim($_GET["year"] ?? "");
$studentSemester = trim($_GET["student_semester"] ?? "");
$departmentId = trim($_GET["department_id"] ?? "");
$courseId = trim($_GET["course_id"] ?? "");
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
            $delSql = "DELETE FROM $enrollTable WHERE enrollment_id = ?";
            $delStmt = sqlsrv_query($conn, $delSql, [$enrollmentId]);
            if ($delStmt !== false) {
                $redirect = "list.php" . buildQuery(["success" => 2]);
                header("Location: " . $redirect);
                exit();
            } else {
                $errorMsg = "Delete failed.";
            }
        }
    }
}

$params = [];
$where = "1=1";

if ($term !== "") {
    $where .= " AND e.semester = ?";
    $params[] = $semesterIsNumeric ? (int)$term : $term;
}
if ($year !== "" && $hasYear) {
    $where .= " AND e.year = ?";
    $params[] = (int)$year;
}
if ($studentSemester !== "") {
    $where .= " AND s.semester = ?";
    $params[] = (int)$studentSemester;
}
if ($departmentId !== "") {
    $where .= " AND s.dept_id = ?";
    $params[] = (int)$departmentId;
}
if ($courseId !== "") {
    $where .= " AND e.course_id = ?";
    $params[] = (int)$courseId;
}

if ($q !== "") {
    $where .= " AND (
        CONVERT(VARCHAR(50), s.student_id) LIKE ? OR
        s.$studentNameCol LIKE ? OR
        d.$deptNameCol LIKE ? OR
        " . ($courseCodeCol ? "c.$courseCodeCol LIKE ? OR" : "") . "
        c.$courseNameCol LIKE ?
    )";

    $like = "%" . $q . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    if ($courseCodeCol) $params[] = $like;
    $params[] = $like;
}

$departments = [];
$deptFilterSql = "
    SELECT d.dept_id, d.$deptNameCol AS department_name
    FROM $deptTable d
    ORDER BY d.$deptNameCol ASC
";
$deptFilterStmt = sqlsrv_query($conn, $deptFilterSql);
if ($deptFilterStmt !== false) {
    while ($r = sqlsrv_fetch_array($deptFilterStmt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $r;
    }
    sqlsrv_free_stmt($deptFilterStmt);
}

$coursesForFilter = [];
$courseFilterSql = "
    SELECT
        c.course_id,
        " . ($courseCodeCol ? "c.$courseCodeCol" : "CONVERT(VARCHAR(50), c.course_id)") . " AS course_code,
        c.$courseNameCol AS course_name
    FROM $courseTable c
    ORDER BY " . ($courseCodeCol ? "c.$courseCodeCol ASC," : "") . " c.$courseNameCol ASC
";
$courseFilterStmt = sqlsrv_query($conn, $courseFilterSql);
if ($courseFilterStmt !== false) {
    while ($r = sqlsrv_fetch_array($courseFilterStmt, SQLSRV_FETCH_ASSOC)) {
        $coursesForFilter[] = $r;
    }
    sqlsrv_free_stmt($courseFilterStmt);
}

$countSql = "
    SELECT COUNT(*) AS total_rows
    FROM $enrollTable e
    JOIN $studentTable s ON s.student_id = e.student_id
    JOIN $courseTable c ON c.course_id = e.course_id
    LEFT JOIN $deptTable d ON d.dept_id = s.dept_id
    WHERE $where
";
$countStmt = sqlsrv_query($conn, $countSql, $params);

$totalRows = 0;
if ($countStmt !== false) {
    $countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $totalRows = (int)($countRow["total_rows"] ?? 0);
    sqlsrv_free_stmt($countStmt);
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = "
    SELECT
      e.enrollment_id,
      e.semester AS term,
      " . ($hasYear ? "e.year" : "NULL AS year") . ",
      s.student_id,
      s.$studentNameCol AS student_name,
      s.semester AS student_semester,
      d.$deptNameCol AS department_name,
      c.course_id,
      " . ($courseCodeCol ? "c.$courseCodeCol" : "CONVERT(VARCHAR(50), c.course_id)") . " AS course_code,
      c.$courseNameCol AS course_name,
      c.$creditCol AS credit_hours
    FROM $enrollTable e
    JOIN $studentTable s ON s.student_id = e.student_id
    JOIN $courseTable c ON c.course_id = e.course_id
    LEFT JOIN $deptTable d ON d.dept_id = s.dept_id
    WHERE $where
    ORDER BY " . ($hasYear ? "e.year DESC," : "") . " e.semester DESC, s.$studentNameCol ASC, c.$courseNameCol ASC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$paramsPaged = array_merge($params, [$offset, $perPage]);
$stmt = sqlsrv_query($conn, $sql, $paramsPaged);

$rows = [];
if ($stmt !== false) {
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $r;
    }
    sqlsrv_free_stmt($stmt);
}

$showFrom = $totalRows === 0 ? 0 : ($offset + 1);
$showTo = min($offset + $perPage, $totalRows);

$pdfPreviewUrl = "pdf_preview.php" . buildQuery(["page" => null, "success" => null]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Enrollments - UMS</title>
  <link rel="stylesheet" href="../assets/app.css">
  <style>
    .page-actions{
      display:flex;
      gap:12px;
      align-items:center;
      flex-wrap:wrap;
    }
    .enroll-toolbar{
      display:flex;
      flex-wrap:nowrap;
      gap:12px;
      align-items:center;
      margin-bottom:16px;
      overflow-x:auto;
      padding-bottom:4px;
      scrollbar-width:thin;
    }
    .enroll-toolbar .search,
    .enroll-toolbar select,
    .enroll-toolbar input,
    .enroll-toolbar .btn{
      height:44px;
      flex:0 0 auto;
    }
    .enroll-toolbar .search{
      width:170px;
      min-width:170px;
      transition:width .22s ease,min-width .22s ease,box-shadow .22s ease;
    }
    .enroll-toolbar input,
    .enroll-toolbar select{
      width:118px;
      min-width:118px;
      padding:0 12px;
      border:1px solid #dbe1ea;
      border-radius:12px;
      background:#fff;
      color:var(--text);
      outline:none;
      font:inherit;
      transition:width .22s ease,min-width .22s ease,box-shadow .22s ease,border-color .22s ease;
    }
    .enroll-toolbar .btn{
      white-space:nowrap;
      justify-content:center;
    }
    .enroll-toolbar .search:hover,
    .enroll-toolbar .search:focus-within{
      width:280px;
      min-width:280px;
    }
    .enroll-toolbar input:hover,
    .enroll-toolbar input:focus,
    .enroll-toolbar select:hover,
    .enroll-toolbar select:focus{
      width:210px;
      min-width:210px;
      border-color:#94a3b8;
      box-shadow:0 10px 24px rgba(15,23,42,.10);
    }
    .enroll-toolbar select[name="per_page"]{
      width:84px;
      min-width:84px;
    }
    .enroll-toolbar .btn{
      min-width:92px;
    }
    @media (max-width: 900px){
      .enroll-toolbar{
        gap:10px;
      }
    }
  </style>
</head>
<body>
<div class="layout">
  <?php renderSidebar("enrollments", "../"); ?>

  <main class="content">
    <?php renderTopbar($name, "", "../logout.php", false); ?>

    <div class="page">
      <div class="page-head">
        <h1>Enrollments</h1>
        <div class="page-actions">
          <a class="btn btn-primary" href="<?php echo h($pdfPreviewUrl); ?>" title="View PDF">
            View PDF
          </a>
          <a class="btn btn-primary" href="add.php">
            <span style="font-size:18px;line-height:0;">+</span>
            Enroll Student
          </a>
        </div>
      </div>

      <?php if ($okMsg !== ""): ?>
        <div class="alert-ok"><?php echo h($okMsg); ?></div>
      <?php endif; ?>

      <?php if ($errorMsg !== ""): ?>
        <div class="alert-err"><?php echo h($errorMsg); ?></div>
      <?php endif; ?>

      <div class="card">
        <form method="get" action="list.php" class="enroll-toolbar">
          <div class="search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="11" cy="11" r="7" stroke="#64748b" stroke-width="2"></circle>
              <path d="M21 21l-4.3-4.3" stroke="#64748b" stroke-width="2" stroke-linecap="round"></path>
            </svg>
            <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search enrollments..." />
          </div>

          <select name="term">
            <option value=""><?php echo $semesterIsNumeric ? "Semester" : "Term (Spring/Fall)"; ?></option>
            <?php foreach (($semesterIsNumeric ? range(1, 8) : ["Spring", "Summer", "Fall"]) as $t): ?>
              <option value="<?php echo h($t); ?>" <?php echo $term === $t ? "selected" : ""; ?>>
                <?php echo h($t); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <input type="number" name="year" value="<?php echo h($year); ?>" placeholder="Year (e.g., 2025)" min="2000" max="2100" <?php echo $hasYear ? "" : "disabled"; ?> />

          <select name="student_semester">
            <option value="">Student Semester</option>
            <?php for ($i = 1; $i <= 8; $i++): ?>
              <option value="<?php echo $i; ?>" <?php echo (string)$studentSemester === (string)$i ? "selected" : ""; ?>>
                <?php echo $i; ?>
              </option>
            <?php endfor; ?>
          </select>

          <select name="department_id">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept): ?>
              <?php $did = (int)($dept["dept_id"] ?? 0); ?>
              <option value="<?php echo $did; ?>" <?php echo (string)$departmentId === (string)$did ? "selected" : ""; ?>>
                <?php echo h($dept["department_name"] ?? ""); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="course_id">
            <option value="">All Courses</option>
            <?php foreach ($coursesForFilter as $course): ?>
              <?php
                $filterCourseId = (int)($course["course_id"] ?? 0);
                $filterCourseCode = trim((string)($course["course_code"] ?? ""));
                $filterCourseName = (string)($course["course_name"] ?? "");
              ?>
              <option value="<?php echo $filterCourseId; ?>" <?php echo (string)$courseId === (string)$filterCourseId ? "selected" : ""; ?>>
                <?php echo h(($filterCourseCode !== "" ? $filterCourseCode . " - " : "") . $filterCourseName); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="per_page">
            <option value="5" <?php echo $perPage===5?'selected':''; ?>>5</option>
            <option value="10" <?php echo $perPage===10?'selected':''; ?>>10</option>
            <option value="20" <?php echo $perPage===20?'selected':''; ?>>20</option>
            <option value="50" <?php echo $perPage===50?'selected':''; ?>>50</option>
          </select>

          <button class="btn btn-primary" type="submit">Apply</button>
          <a class="btn" href="list.php">Reset</a>
        </form>

        <div style="overflow:auto;">
          <table>
            <thead>
              <tr>
                <th>Student ID</th>
                <th>Student Name</th>
                <th>Department</th>
                <th>Course Code</th>
                <th>Course Name</th>
                <th>Credits</th>
                <th>Term</th>
                <th>Year</th>
                <th>Student Semester</th>
                <th style="text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($rows) === 0): ?>
                <tr>
                  <td colspan="10" class="muted">No enrollments found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <td><?php echo h($r["student_id"] ?? ""); ?></td>
                    <td><?php echo h($r["student_name"] ?? ""); ?></td>
                    <td><?php echo h($r["department_name"] ?? ""); ?></td>
                    <td><?php echo h($r["course_code"] ?? ""); ?></td>
                    <td><?php echo h($r["course_name"] ?? ""); ?></td>
                    <td><?php echo h($r["credit_hours"] ?? ""); ?></td>
                    <td><?php echo h($r["term"] ?? ""); ?></td>
                    <td><?php echo h($r["year"] ?? ""); ?></td>
                    <td><?php echo h($r["student_semester"] ?? ""); ?></td>
                    <td style="text-align:right;">
                      <div class="actions">
                        <form method="post" action="list.php<?php echo h(buildQuery()); ?>" onsubmit="return confirm('Delete this enrollment?');" style="margin:0;">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">
                          <input type="hidden" name="enrollment_id" value="<?php echo h($r["enrollment_id"] ?? ""); ?>">
                          <button class="icon-btn danger" type="submit" title="Delete">
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
            <?php echo "Showing " . h($showFrom) . " to " . h($showTo) . " of " . h($totalRows) . " enrollments"; ?>
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
              <a class="page-btn <?php echo $p === $page ? 'active' : ''; ?>"
                 href="list.php<?php echo h(buildQuery(["page" => $p])); ?>">
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
