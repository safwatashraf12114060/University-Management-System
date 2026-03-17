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

function tableExists($conn, $schemaDotTable) {
    $stmt = sqlsrv_query($conn, "SELECT OBJECT_ID(?) AS oid", [$schemaDotTable]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return isset($row["oid"]) && $row["oid"] !== null;
}

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return isset($row["len"]) && $row["len"] !== null;
}

function h($v) {
    return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8");
}

function fmtDateValue($v) {
    if ($v instanceof DateTime) return $v->format("M d, Y");
    if (is_string($v) && trim($v) !== "") {
        $ts = strtotime($v);
        return $ts ? date("M d, Y", $ts) : $v;
    }
    return "-";
}

function gradeBadgeClass($grade) {
    $g = strtoupper(trim((string)$grade));
    if (in_array($g, ["A+", "A", "A-"], true)) return "gA";
    if (in_array($g, ["B+", "B", "B-"], true)) return "gB";
    if (in_array($g, ["C+", "C", "C-"], true)) return "gC";
    if ($g === "D") return "gD";
    if ($g === "F") return "gF";
    return "gX";
}

function gradePoint($grade) {
    $map = [
        "A+" => 4.00, "A" => 4.00, "A-" => 3.75,
        "B+" => 3.50, "B" => 3.00, "B-" => 2.75,
        "C+" => 2.50, "C" => 2.25, "C-" => 2.00,
        "D" => 1.00, "F" => 0.00
    ];
    $g = strtoupper(trim((string)$grade));
    return $map[$g] ?? null;
}

$student_id = (int)($_GET["student_id"] ?? 0);
if ($student_id <= 0) {
    header("Location: list.php");
    exit();
}

$studentTable = "STUDENT";
$deptTable = "DEPARTMENT";

$studentTableDbo = "dbo." . $studentTable;
$deptTableDbo = "dbo." . $deptTable;

$hasStudentName = colExists($conn, $studentTableDbo, "student_name") || colExists($conn, $studentTable, "student_name");
$hasName = colExists($conn, $studentTableDbo, "name") || colExists($conn, $studentTable, "name");
$nameCol = $hasStudentName ? "student_name" : ($hasName ? "name" : "student_name");

$hasEmail = colExists($conn, $studentTableDbo, "email") || colExists($conn, $studentTable, "email");
$hasPhone = colExists($conn, $studentTableDbo, "phone") || colExists($conn, $studentTable, "phone");
$hasAddress = colExists($conn, $studentTableDbo, "address") || colExists($conn, $studentTable, "address");
$hasSemester = colExists($conn, $studentTableDbo, "semester") || colExists($conn, $studentTable, "semester");
$hasGender = colExists($conn, $studentTableDbo, "gender") || colExists($conn, $studentTable, "gender");

$hasDob = (
    colExists($conn, $studentTableDbo, "dob") || colExists($conn, $studentTable, "dob") ||
    colExists($conn, $studentTableDbo, "date_of_birth") || colExists($conn, $studentTable, "date_of_birth")
);
$dobCol = (colExists($conn, $studentTableDbo, "dob") || colExists($conn, $studentTable, "dob")) ? "dob" : "date_of_birth";

$hasDeptId = colExists($conn, $studentTableDbo, "dept_id") || colExists($conn, $studentTable, "dept_id");
$hasDepartmentId = colExists($conn, $studentTableDbo, "department_id") || colExists($conn, $studentTable, "department_id");
$deptFkCol = $hasDeptId ? "dept_id" : ($hasDepartmentId ? "department_id" : "dept_id");

$hasStudentCode = colExists($conn, $studentTableDbo, "student_code") || colExists($conn, $studentTable, "student_code");
$hasRegistrationNo = colExists($conn, $studentTableDbo, "registration_no") || colExists($conn, $studentTable, "registration_no");

$cols = [
    "s.student_id",
    "s.$nameCol AS student_name",
    "d.name AS dept_name"
];
if ($hasEmail) $cols[] = "s.email";
if ($hasPhone) $cols[] = "s.phone";
if ($hasAddress) $cols[] = "s.address";
if ($hasSemester) $cols[] = "s.semester";
if ($hasGender) $cols[] = "s.gender";
if ($hasDob) $cols[] = "s.$dobCol AS dob";
if ($hasStudentCode) $cols[] = "s.student_code";
if ($hasRegistrationNo) $cols[] = "s.registration_no";

$sql = "
    SELECT " . implode(", ", $cols) . "
    FROM $studentTable s
    LEFT JOIN $deptTable d ON d.dept_id = s.$deptFkCol
    WHERE s.student_id = ?
";
$stmt = sqlsrv_query($conn, $sql, [$student_id]);
$student = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;

if (!$student) {
    header("Location: list.php");
    exit();
}

$studentCode = "";
if ($hasStudentCode && !empty($student["student_code"])) {
    $studentCode = (string)$student["student_code"];
} elseif ($hasRegistrationNo && !empty($student["registration_no"])) {
    $studentCode = (string)$student["registration_no"];
} else {
    $studentCode = "S" . str_pad((string)$student["student_id"], 7, "0", STR_PAD_LEFT);
}

/* ---------- Optional academic data ---------- */
$enrolledCourses = [];
$currentCredits = 0.0;
$totalCredits = 0.0;
$gpa = null;

$hasEnrollmentTable = tableExists($conn, "dbo.ENROLLMENT") || tableExists($conn, "ENROLLMENT");
$hasCourseTable = tableExists($conn, "dbo.COURSE") || tableExists($conn, "COURSE");
$hasResultTable = tableExists($conn, "dbo.RESULT") || tableExists($conn, "RESULT");

if ($hasEnrollmentTable && $hasCourseTable) {
    $enrollmentTable = tableExists($conn, "dbo.ENROLLMENT") ? "dbo.ENROLLMENT" : "ENROLLMENT";
    $courseTable = tableExists($conn, "dbo.COURSE") ? "dbo.COURSE" : "COURSE";
    $resultTable = $hasResultTable ? (tableExists($conn, "dbo.RESULT") ? "dbo.RESULT" : "RESULT") : null;

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

    $enrollmentIdCol = colExists($conn, $enrollmentTable, "enrollment_id") ? "enrollment_id" : "id";
    $enrollmentStudentCol = colExists($conn, $enrollmentTable, "student_id") ? "student_id" : "student_id";
    $enrollmentCourseCol = colExists($conn, $enrollmentTable, "course_id") ? "course_id" : "course_id";

    $hasEnrollmentSemester = colExists($conn, $enrollmentTable, "semester");
    $hasEnrollmentYear = colExists($conn, $enrollmentTable, "year");

    $resultJoin = "";
    $resultCols = "";
    if ($resultTable !== null && colExists($conn, $resultTable, "enrollment_id")) {
        $resultJoin = "LEFT JOIN $resultTable r ON r.enrollment_id = e.$enrollmentIdCol";
        if (colExists($conn, $resultTable, "grade")) $resultCols .= ", r.grade";
        if (colExists($conn, $resultTable, "marks")) $resultCols .= ", r.marks";
    }

    $acadCols = [
        "c.$courseIdCol AS course_id",
        ($courseCodeCol ? "c.$courseCodeCol AS course_code" : "CAST(c.$courseIdCol AS VARCHAR(50)) AS course_code"),
        "c.$courseNameCol AS course_name"
    ];
    if ($creditCol) $acadCols[] = "c.$creditCol AS credit_hours";
    if ($hasEnrollmentSemester) $acadCols[] = "e.semester";
    if ($hasEnrollmentYear) $acadCols[] = "e.year";

    $acadSql = "
        SELECT " . implode(", ", $acadCols) . $resultCols . "
        FROM $enrollmentTable e
        JOIN $courseTable c ON c.$courseIdCol = e.$enrollmentCourseCol
        $resultJoin
        WHERE e.$enrollmentStudentCol = ?
        ORDER BY " . ($hasEnrollmentYear ? "e.year DESC," : "") . ($hasEnrollmentSemester ? " e.semester DESC," : "") . " c.$courseNameCol ASC
    ";

    $acadStmt = sqlsrv_query($conn, $acadSql, [$student_id]);
    if ($acadStmt !== false) {
        $totalQualityPoints = 0.0;
        $gpaCreditHours = 0.0;

        while ($r = sqlsrv_fetch_array($acadStmt, SQLSRV_FETCH_ASSOC)) {
            $credits = isset($r["credit_hours"]) ? (float)$r["credit_hours"] : 0.0;
            $grade = (string)($r["grade"] ?? "");

            $enrolledCourses[] = $r;
            $currentCredits += $credits;
            $totalCredits += $credits;

            $gp = gradePoint($grade);
            if ($gp !== null && $credits > 0) {
                $totalQualityPoints += ($gp * $credits);
                $gpaCreditHours += $credits;
            }
        }

        if ($gpaCreditHours > 0) {
            $gpa = round($totalQualityPoints / $gpaCreditHours, 2);
        }

        sqlsrv_free_stmt($acadStmt);
    }
}

$name = $_SESSION["name"] ?? "User";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Student Details</title>
  <link rel="stylesheet" href="../assets/app.css">
  <style>
    .student-view-grid{
      display:grid;
      grid-template-columns:minmax(0, 2.1fr) minmax(300px, 1fr);
      gap:20px;
      align-items:start;
    }
    .student-view-left,
    .student-view-right{
      display:flex;
      flex-direction:column;
      gap:20px;
      min-width:0;
    }
    .back-link{
      display:inline-flex;
      align-items:center;
      gap:8px;
      font-weight:900;
      margin-bottom:14px;
      text-decoration:none;
      color:var(--text);
    }
    .section-title{
      margin:0 0 16px;
      font-size:22px;
      font-weight:900;
      letter-spacing:-0.3px;
    }
    .action-bar{
      display:flex;
      gap:12px;
      align-items:center;
      flex-wrap:wrap;
    }
    .detail-grid{
      display:grid;
      grid-template-columns:repeat(2, minmax(0, 1fr));
      gap:18px 26px;
    }
    .detail-item{
      display:flex;
      gap:12px;
      align-items:flex-start;
      min-width:0;
    }
    .detail-icon{
      width:20px;
      height:20px;
      flex:0 0 auto;
      margin-top:2px;
      color:#94a3b8;
    }
    .detail-content{
      min-width:0;
    }
    .detail-label{
      font-size:13px;
      color:var(--muted);
      font-weight:800;
      margin-bottom:4px;
    }
    .detail-value{
      font-size:16px;
      font-weight:800;
      color:var(--text);
      word-break:break-word;
    }
    .mini-list{
      display:flex;
      flex-direction:column;
      gap:14px;
    }
    .mini-row{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
    }
    .mini-key{
      color:var(--muted);
      font-size:13px;
      font-weight:800;
    }
    .mini-value{
      color:var(--text);
      font-size:17px;
      font-weight:900;
      text-align:right;
    }
    .mini-value.primary{
      color:var(--primary);
    }
    .course-table-wrap{
      overflow:auto;
    }
    .course-table td,
    .course-table th{
      padding:14px 16px;
    }
    .empty-box{
      padding:14px 4px 4px;
      color:var(--muted);
      font-weight:700;
    }
    @media (max-width:1100px){
      .student-view-grid{
        grid-template-columns:1fr;
      }
    }
    @media (max-width:760px){
      .detail-grid{
        grid-template-columns:1fr;
      }
      .action-bar{
        width:100%;
      }
    }
  </style>
</head>
<body>
<div class="layout">

  <?php renderSidebar("students", "../"); ?>

  <main class="content">
    <?php renderTopbar($name, "", "../logout.php", false); ?>

    <div class="page">
      <a class="back-link" href="list.php">← Back to Students</a>

      <div class="header">
        <h1>Student Details</h1>
        <div class="action-bar">
          <a class="btn" href="../result/list.php">View Results</a>
          <a class="btn btn-primary" href="edit.php?student_id=<?php echo (int)$student["student_id"]; ?>">Edit Student</a>
        </div>
      </div>

      <div class="student-view-grid">
        <div class="student-view-left">
          <section class="card">
            <h2 class="section-title">Personal Information</h2>

            <div class="detail-grid">
              <div class="detail-item">
                <svg class="detail-icon" viewBox="0 0 24 24" fill="none">
                  <circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/>
                  <path d="M4 20a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <div class="detail-content">
                  <div class="detail-label">Full Name</div>
                  <div class="detail-value"><?php echo h($student["student_name"]); ?></div>
                </div>
              </div>

              <div class="detail-item">
                <svg class="detail-icon" viewBox="0 0 24 24" fill="none">
                  <path d="M4 6h16v12H4z" stroke="currentColor" stroke-width="2"/>
                  <path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <div class="detail-content">
                  <div class="detail-label">Email</div>
                  <div class="detail-value"><?php echo h(($student["email"] ?? "") !== "" ? $student["email"] : "-"); ?></div>
                </div>
              </div>

              <div class="detail-item">
                <svg class="detail-icon" viewBox="0 0 24 24" fill="none">
                  <path d="M6 4h4l2 5-2 1a16 16 0 0 0 4 4l1-2 5 2v4a2 2 0 0 1-2 2C9.16 20 4 14.84 4 8a2 2 0 0 1 2-2z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                </svg>
                <div class="detail-content">
                  <div class="detail-label">Phone</div>
                  <div class="detail-value"><?php echo h(($student["phone"] ?? "") !== "" ? $student["phone"] : "-"); ?></div>
                </div>
              </div>

              <div class="detail-item">
                <svg class="detail-icon" viewBox="0 0 24 24" fill="none">
                  <rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="2"/>
                  <path d="M8 3v4M16 3v4M4 10h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <div class="detail-content">
                  <div class="detail-label">Date of Birth</div>
                  <div class="detail-value"><?php echo $hasDob ? h(fmtDateValue($student["dob"] ?? null)) : "-"; ?></div>
                </div>
              </div>

              <div class="detail-item">
                <svg class="detail-icon" viewBox="0 0 24 24" fill="none">
                  <circle cx="12" cy="7" r="3" stroke="currentColor" stroke-width="2"/>
                  <path d="M12 10v10M8 14h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <div class="detail-content">
                  <div class="detail-label">Gender</div>
                  <div class="detail-value"><?php echo h(($student["gender"] ?? "") !== "" ? $student["gender"] : "-"); ?></div>
                </div>
              </div>

              <div class="detail-item">
                <svg class="detail-icon" viewBox="0 0 24 24" fill="none">
                  <path d="M12 21s7-4.35 7-11a7 7 0 1 0-14 0c0 6.65 7 11 7 11z" stroke="currentColor" stroke-width="2"/>
                  <circle cx="12" cy="10" r="2.5" stroke="currentColor" stroke-width="2"/>
                </svg>
                <div class="detail-content">
                  <div class="detail-label">Address</div>
                  <div class="detail-value"><?php echo h(($student["address"] ?? "") !== "" ? $student["address"] : "-"); ?></div>
                </div>
              </div>
            </div>
          </section>

          <section class="card">
            <h2 class="section-title">Enrolled Courses</h2>

            <?php if (count($enrolledCourses) === 0): ?>
              <div class="empty-box">No enrolled courses found for this student.</div>
            <?php else: ?>
              <div class="course-table-wrap">
                <table class="course-table">
                  <thead>
                    <tr>
                      <th>Course Code</th>
                      <th>Course Name</th>
                      <th>Credits</th>
                      <th>Grade</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($enrolledCourses as $course): ?>
                      <?php
                        $credits = isset($course["credit_hours"]) ? (float)$course["credit_hours"] : 0;
                        $grade = (string)($course["grade"] ?? "");
                      ?>
                      <tr>
                        <td><strong><?php echo h($course["course_code"] ?? "-"); ?></strong></td>
                        <td><?php echo h($course["course_name"] ?? "-"); ?></td>
                        <td><?php echo h($credits > 0 ? rtrim(rtrim(number_format($credits, 2), "0"), ".") : "-"); ?></td>
                        <td>
                          <?php if ($grade !== ""): ?>
                            <span class="badge <?php echo h(gradeBadgeClass($grade)); ?>"><?php echo h($grade); ?></span>
                          <?php else: ?>
                            <span class="muted">-</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>
        </div>

        <div class="student-view-right">
          <section class="card">
            <h2 class="section-title">Academic Info</h2>

            <div class="mini-list">
              <div class="mini-row">
                <div class="mini-key">Student ID</div>
                <div class="mini-value"><?php echo h($studentCode); ?></div>
              </div>

              <div class="mini-row">
                <div class="mini-key">Department</div>
                <div class="mini-value"><?php echo h(($student["dept_name"] ?? "") !== "" ? $student["dept_name"] : "-"); ?></div>
              </div>

              <div class="mini-row">
                <div class="mini-key">Current Semester</div>
                <div class="mini-value"><?php echo h(($student["semester"] ?? "") !== "" ? $student["semester"] : "-"); ?></div>
              </div>
            </div>
          </section>

          <section class="card">
            <h2 class="section-title">Credit Summary</h2>

            <div class="mini-list">
              <div class="mini-row">
                <div class="mini-key">Current Credits</div>
                <div class="mini-value"><?php echo h($currentCredits > 0 ? rtrim(rtrim(number_format($currentCredits, 2), "0"), ".") : "0"); ?></div>
              </div>

              <div class="mini-row">
                <div class="mini-key">Total Credits</div>
                <div class="mini-value"><?php echo h($totalCredits > 0 ? rtrim(rtrim(number_format($totalCredits, 2), "0"), ".") : "0"); ?></div>
              </div>

              <div class="mini-row">
                <div class="mini-key">GPA</div>
                <div class="mini-value primary"><?php echo h($gpa !== null ? number_format($gpa, 2) : "-"); ?></div>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>
  </main>
</div>
</body>
</html>