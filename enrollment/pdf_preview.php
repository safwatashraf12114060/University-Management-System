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

$studentDeptFkCol = "dept_id";
if (colExists($conn, $studentTable, "department_id")) $studentDeptFkCol = "department_id";

$deptIdCol = "dept_id";
if (colExists($conn, $deptTable, "department_id")) $deptIdCol = "department_id";

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
    $where .= " AND s.$studentDeptFkCol = ?";
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
    LEFT JOIN $deptTable d ON d.$deptIdCol = s.$studentDeptFkCol
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

$selectedDepartmentName = "";
if ($departmentId !== "") {
    $deptStmt = sqlsrv_query(
        $conn,
        "SELECT $deptNameCol AS department_name FROM $deptTable WHERE $deptIdCol = ?",
        [(int)$departmentId]
    );
    if ($deptStmt !== false) {
        $deptRow = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC);
        $selectedDepartmentName = (string)($deptRow["department_name"] ?? "");
        sqlsrv_free_stmt($deptStmt);
    }
}

$selectedCourseLabel = "";
if ($courseId !== "") {
    $courseStmt = sqlsrv_query(
        $conn,
        "
        SELECT
          " . ($courseCodeCol ? "$courseCodeCol" : "CONVERT(VARCHAR(50), course_id)") . " AS course_code,
          $courseNameCol AS course_name
        FROM $courseTable
        WHERE course_id = ?
        ",
        [(int)$courseId]
    );
    if ($courseStmt !== false) {
        $courseRow = sqlsrv_fetch_array($courseStmt, SQLSRV_FETCH_ASSOC);
        $code = trim((string)($courseRow["course_code"] ?? ""));
        $name = (string)($courseRow["course_name"] ?? "");
        $selectedCourseLabel = ($code !== "" ? $code . " - " : "") . $name;
        sqlsrv_free_stmt($courseStmt);
    }
}

$uniqueStudents = [];
$totalCredits = 0.0;
foreach ($rows as $row) {
    $studentKey = (string)($row["student_id"] ?? "");
    if ($studentKey !== "") {
        $uniqueStudents[$studentKey] = true;
    }
    $totalCredits += (float)($row["credit_hours"] ?? 0);
}

$totalCreditsText = rtrim(rtrim(number_format($totalCredits, 1, ".", ""), "0"), ".");
if ($totalCreditsText === "") $totalCreditsText = "0";

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
    .back-link{
      display:inline-flex;
      align-items:center;
      gap:8px;
      font-weight:900;
      margin-bottom:14px;
      text-decoration:none;
      color:var(--text);
    }
    .transcript-header{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:16px;
      flex-wrap:wrap;
      margin-bottom:18px;
    }
    .transcript-header h1{
      margin:0;
      font-size:48px;
      line-height:1.05;
      letter-spacing:-1px;
    }
    .download-btn{
      display:inline-flex;
      align-items:center;
      gap:10px;
      background:#3b48f5;
      color:#fff;
      border-color:#3b48f5;
    }
    .download-btn svg path{stroke:#fff;}
    .preview-wrap{display:none;}
    .transcript-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:18px;
      box-shadow:0 10px 30px rgba(15,23,42,0.06);
      padding:28px 32px;
    }
    .transcript-top{
      text-align:center;
      margin-bottom:24px;
    }
    .transcript-top h2{
      margin:0;
      font-size:28px;
      letter-spacing:-0.5px;
    }
    .transcript-top p{
      margin:6px 0 0;
      color:#64748b;
      font-size:16px;
    }
    .meta-grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:16px 24px;
      margin-bottom:24px;
    }
    .meta-item{
      padding:8px 0;
    }
    .meta-label{
      color:#64748b;
      font-size:14px;
      margin-bottom:4px;
    }
    .meta-value{
      font-weight:900;
      font-size:16px;
      color:#0f172a;
      word-break:break-word;
    }
    .divider{
      height:1px;
      background:#e5e7eb;
      margin:20px 0 26px;
    }
    .section-head{
      display:flex;
      justify-content:space-between;
      gap:16px;
      align-items:center;
      margin-bottom:14px;
      flex-wrap:wrap;
    }
    .section-head h3{
      margin:0;
      font-size:20px;
      letter-spacing:-0.2px;
    }
    .info-pill{
      display:inline-flex;
      align-items:center;
      gap:6px;
      background:#f8fafc;
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:10px 14px;
      font-weight:800;
      color:#334155;
    }
    .info-pill strong{
      color:#3b48f5;
      font-size:16px;
    }
    .transcript-table{
      width:100%;
      border-collapse:collapse;
    }
    .transcript-table th,
    .transcript-table td{
      text-align:left;
      padding:14px 12px;
      border-bottom:1px solid #e5e7eb;
      vertical-align:middle;
    }
    .transcript-table th{
      color:#334155;
      font-size:14px;
      font-weight:900;
      white-space:nowrap;
    }
    .transcript-table td{
      color:#0f172a;
      font-weight:700;
    }
    .transcript-table td.num,
    .transcript-table th.num{
      text-align:right;
      white-space:nowrap;
    }
    .empty-box{
      padding:18px;
      border-radius:14px;
      background:#f8fafc;
      border:1px dashed #cbd5e1;
      color:#64748b;
      font-weight:800;
    }
    .summary-grid{
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:18px;
      margin-top:28px;
      padding-top:26px;
      border-top:1px solid #e5e7eb;
    }
    .summary-card{
      text-align:right;
    }
    .summary-card .k{
      color:#475569;
      font-size:14px;
      margin-bottom:6px;
    }
    .summary-card .v{
      font-size:42px;
      line-height:1;
      font-weight:900;
      color:#0f172a;
    }
    .summary-card .v.primary{
      color:#3b48f5;
    }
    @media (max-width: 860px){
      .transcript-card{padding:22px 18px;}
      .meta-grid{grid-template-columns:1fr;}
      .summary-grid{grid-template-columns:1fr;}
      .summary-card{text-align:left;}
      .transcript-header h1{font-size:38px;}
      .transcript-table{display:block;overflow:auto;}
    }
  </style>
</head>
<body>
<div class="layout">
  <?php renderSidebar("enrollments", "../"); ?>

  <main class="content">
    <?php renderTopbar($displayName, "", "../logout.php", false); ?>

    <div class="page">
      <a class="back-link" href="list.php<?php echo h(buildQuery()); ?>">&#8592; Back to Enrollments</a>

      <div class="transcript-header">
        <h1>Enrollment Report</h1>
        <a class="btn btn-primary download-btn" href="<?php echo h($downloadUrl); ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 4v10" stroke-width="2" stroke-linecap="round"/>
            <path d="M8 10l4 4 4-4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M4 18h16" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Download PDF
        </a>
      </div>

      <div class="transcript-card">
        <div class="transcript-top">
          <h2>University Management System</h2>
          <p>Official Enrollment Report</p>
        </div>

        <div class="meta-grid">
          <div class="meta-item">
            <div class="meta-label">Generated On</div>
            <div class="meta-value"><?php echo h($generatedOn); ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Search</div>
            <div class="meta-value"><?php echo h($q !== "" ? $q : "-"); ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Department</div>
            <div class="meta-value"><?php echo h($selectedDepartmentName !== "" ? $selectedDepartmentName : "-"); ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Course</div>
            <div class="meta-value"><?php echo h($selectedCourseLabel !== "" ? $selectedCourseLabel : "-"); ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Semester</div>
            <div class="meta-value"><?php echo h($studentSemester !== "" ? $studentSemester : "-"); ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Term / Year</div>
            <div class="meta-value"><?php echo h(($term !== "" ? $term : "-") . " / " . ($year !== "" ? $year : "-")); ?></div>
          </div>
        </div>

        <div class="divider"></div>

        <div class="section-head">
          <h3>Enrollment Entries</h3>
          <div class="info-pill">
            Total Rows:
            <strong><?php echo h((string)count($rows)); ?></strong>
          </div>
        </div>

        <?php if (!$rows): ?>
          <div class="empty-box">No enrollments found for the selected filters.</div>
        <?php else: ?>
          <table class="transcript-table">
            <thead>
              <tr>
                <th>Student ID</th>
                <th>Student Name</th>
                <th>Department</th>
                <th>Course Code</th>
                <th>Course Name</th>
                <th class="num">Credits</th>
                <th>Term</th>
                <th class="num">Year</th>
                <th class="num">Semester</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?php echo h($r["student_id"] ?? ""); ?></td>
                  <td><?php echo h($r["student_name"] ?? ""); ?></td>
                  <td><?php echo h($r["department_name"] ?? "-"); ?></td>
                  <td><?php echo h($r["course_code"] ?? ""); ?></td>
                  <td><?php echo h($r["course_name"] ?? ""); ?></td>
                  <td class="num"><?php echo h($r["credit_hours"] ?? "0"); ?></td>
                  <td><?php echo h($r["term"] ?? "-"); ?></td>
                  <td class="num"><?php echo h($r["year"] ?? "-"); ?></td>
                  <td class="num"><?php echo h($r["student_semester"] ?? "-"); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="summary-grid">
            <div class="summary-card">
              <div class="k">Total Enrollments</div>
              <div class="v"><?php echo h((string)count($rows)); ?></div>
            </div>
            <div class="summary-card">
              <div class="k">Unique Students</div>
              <div class="v"><?php echo h((string)count($uniqueStudents)); ?></div>
            </div>
            <div class="summary-card">
              <div class="k">Total Credits</div>
              <div class="v primary"><?php echo h($totalCreditsText); ?></div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

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
