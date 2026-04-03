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

function buildQuery(array $override = []) {
    $q = $_GET;
    foreach ($override as $k => $v) {
        if ($v === null || $v === "") unset($q[$k]);
        else $q[$k] = $v;
    }
    $qs = http_build_query($q);
    return $qs ? ("?" . $qs) : "";
}

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

$sql = "
    SELECT
      s.student_id,
      s.$studentNameCol AS student_name,
      d.$deptNameCol AS department_name,
      " . ($courseCodeCol ? "c.$courseCodeCol" : "CONVERT(VARCHAR(50), c.course_id)") . " AS course_code,
      c.$courseNameCol AS course_name,
      c.$creditCol AS credit_hours,
      e.semester AS term,
      " . ($hasYear ? "e.year" : "NULL AS year") . ",
      s.semester AS student_semester
    FROM $enrollTable e
    JOIN $studentTable s ON s.student_id = e.student_id
    JOIN $courseTable c ON c.course_id = e.course_id
    LEFT JOIN $deptTable d ON d.dept_id = s.dept_id
    WHERE $where
    ORDER BY " . ($hasYear ? "e.year DESC," : "") . " e.semester DESC, s.$studentNameCol ASC, c.$courseNameCol ASC
";

$stmt = sqlsrv_query($conn, $sql, $params);
$rows = [];
if ($stmt !== false) {
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $r;
    }
    sqlsrv_free_stmt($stmt);
}

$generatedOn = date("d M Y, h:i A");
$downloadUrl = "download_pdf.php" . buildQuery();
$displayName = $_SESSION["name"] ?? "User";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Enrollment PDF Preview</title>
  <link rel="stylesheet" href="../assets/app.css">
  <style>
    .preview-wrap{
      max-width:1200px;
      margin:0 auto;
      padding:24px;
    }
    .preview-actions{
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      margin-bottom:18px;
    }
    .report-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:14px;
      padding:24px;
      box-shadow:0 8px 22px rgba(0,0,0,.06);
    }
    .report-head h1{
      margin:0 0 4px;
      font-size:30px;
    }
    .report-head h2{
      margin:0;
      font-size:18px;
      color:#475569;
      font-weight:800;
    }
    .meta-block{
      margin-top:18px;
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:18px;
    }
    .meta-box{
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:14px;
    }
    .meta-box h3{
      margin:0 0 10px;
      font-size:14px;
    }
    .meta-box p{
      margin:6px 0;
      color:#334155;
      font-weight:700;
    }
    .report-table{
      margin-top:18px;
      overflow:auto;
    }
    .report-footer{
      margin-top:18px;
      font-weight:900;
      color:#334155;
    }
    @media (max-width:800px){
      .meta-block{
        grid-template-columns:1fr;
      }
    }
  </style>
</head>
<body>
<div class="layout">
  <?php renderSidebar("enrollments", "../"); ?>

  <main class="content">
    <?php renderTopbar($displayName, "", "../logout.php", false); ?>

  <div class="preview-wrap">
    <div class="preview-actions">
      <a class="btn" href="list.php<?php echo h(buildQuery()); ?>">← Back to Enrollments</a>
      <a class="btn btn-primary" href="<?php echo h($downloadUrl); ?>">Download PDF</a>
    </div>

    <div class="report-card">
      <div class="report-head">
        <h1>University Management System</h1>
        <h2>Enrollment Report</h2>
      </div>

      <div class="meta-block">
        <div class="meta-box">
          <h3>Generated Info</h3>
          <p>Generated on: <?php echo h($generatedOn); ?></p>
        </div>

        <div class="meta-box">
          <h3>Applied Filters</h3>
          <p>Search: <?php echo h($q !== "" ? $q : "All"); ?></p>
          <p>Term: <?php echo h($term !== "" ? $term : "All"); ?></p>
          <p>Year: <?php echo h($year !== "" ? $year : "All"); ?></p>
          <p>Student Semester: <?php echo h($studentSemester !== "" ? $studentSemester : "All"); ?></p>
          <p>Credits: All</p>
        </div>
      </div>

      <div class="report-table">
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
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr>
                <td colspan="9" class="muted">No enrollments found.</td>
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
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="report-footer">
        Total records: <?php echo count($rows); ?>
      </div>
    </div>
  </div>
  </main>
</div>
</body>
</html>
