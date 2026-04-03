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

$debug = (int)($_GET["debug"] ?? 0) === 1;

function h($v) {
    return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8");
}

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return isset($row["len"]) && $row["len"] !== null;
}

function sqlErrorText() {
    $errs = sqlsrv_errors();
    if (!$errs) return "Unknown SQL error";
    $parts = [];
    foreach ($errs as $e) $parts[] = trim((string)($e["message"] ?? ""));
    return implode(" | ", $parts);
}

function fetchOneAssoc($conn, $sql, array $params = []) {
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return $row ?: null;
}

function normalizeGrade($grade) {
    return strtoupper(trim((string)$grade));
}

function isPassingGrade($grade) {
    $grade = normalizeGrade($grade);
    return $grade !== "" && $grade !== "F";
}

$studentTable = "dbo.STUDENT";
$courseTable  = "dbo.COURSE";
$deptTable    = "dbo.DEPARTMENT";
$enrollTable  = "dbo.ENROLLMENT";
$resultTable  = "dbo.RESULT";

$studentNameCol = "name";
if (colExists($conn, $studentTable, "student_name")) $studentNameCol = "student_name";
if (colExists($conn, $studentTable, "full_name")) $studentNameCol = "full_name";

$courseNameCol = "course_name";
if (colExists($conn, $courseTable, "name")) $courseNameCol = "name";
if (colExists($conn, $courseTable, "title")) $courseNameCol = "title";

$courseCodeCol = null;
foreach (["course_code", "code"] as $cand) {
    if (colExists($conn, $courseTable, $cand)) { $courseCodeCol = $cand; break; }
}

$creditCol = "credit_hours";
if (colExists($conn, $courseTable, "credits")) $creditCol = "credits";
if (colExists($conn, $courseTable, "credit")) $creditCol = "credit";

$enrollSemesterCol = colExists($conn, $enrollTable, "semester_name") ? "semester_name" : "semester";
$hasYear = colExists($conn, $enrollTable, "year");
$hasEnrollmentDate = colExists($conn, $enrollTable, "enrollment_date");
$semesterIsNumeric = true;
$semTypeStmt = sqlsrv_query($conn, "
    SELECT DATA_TYPE AS type_name
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'ENROLLMENT' AND COLUMN_NAME = ?
", [$enrollSemesterCol]);
if ($semTypeStmt !== false) {
    $semTypeRow = sqlsrv_fetch_array($semTypeStmt, SQLSRV_FETCH_ASSOC);
    $semesterIsNumeric = in_array(strtolower((string)($semTypeRow["type_name"] ?? "")), ["int", "smallint", "tinyint", "bigint"], true);
    sqlsrv_free_stmt($semTypeStmt);
}

$gradeCol = colExists($conn, $resultTable, "grade") ? "grade" : null;
$gpaCol = colExists($conn, $resultTable, "gpa") ? "gpa" : null;
$coursePrereqCol = null;
foreach (["prerequisite_course_id", "prerequisite_id", "prereq_course_id"] as $cand) {
    if (colExists($conn, $courseTable, $cand)) { $coursePrereqCol = $cand; break; }
}
$courseSeatLimitCol = null;
foreach (["seat_limit", "capacity", "max_students"] as $cand) {
    if (colExists($conn, $courseTable, $cand)) { $courseSeatLimitCol = $cand; break; }
}

$MIN_CREDITS = 12;
$DEFAULT_MAX_CREDITS = 18;
$currentMaxCredits = $DEFAULT_MAX_CREDITS;

$students = [];
$studentSql = "
    SELECT
        s.student_id,
        s.$studentNameCol AS student_name,
        s.dept_id,
        s.semester AS student_semester,
        d.name AS dept_name
    FROM $studentTable s
    LEFT JOIN $deptTable d ON d.dept_id = s.dept_id
    ORDER BY s.$studentNameCol ASC
";
$st = sqlsrv_query($conn, $studentSql);
if ($st !== false) {
    while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $students[] = $r;
    sqlsrv_free_stmt($st);
}

$courses = [];
$courseSelectCols = "
    c.course_id,
    c.$courseNameCol AS course_name,
    c.$creditCol AS credit_hours,
    c.dept_id,
    " . ($courseCodeCol !== null ? "c.$courseCodeCol AS course_code" : "CONVERT(VARCHAR(50), c.course_id) AS course_code") . "
";
$cs = sqlsrv_query($conn, "SELECT $courseSelectCols FROM $courseTable c ORDER BY " . ($courseCodeCol ? "c.$courseCodeCol ASC" : "c.$courseNameCol ASC"));
if ($cs !== false) {
    while ($r = sqlsrv_fetch_array($cs, SQLSRV_FETCH_ASSOC)) $courses[] = $r;
    sqlsrv_free_stmt($cs);
}

$studentMaxCreditMap = [];
foreach ($students as $studentRow) {
    $sid = (int)($studentRow["student_id"] ?? 0);
    if ($sid <= 0) continue;

    $maxCredits = $DEFAULT_MAX_CREDITS;
    if ($gpaCol !== null) {
        $gpaSql = "
            SELECT AVG(CAST(r.$gpaCol AS float)) AS cgpa
            FROM $resultTable r
            WHERE r.$gpaCol IS NOT NULL
              AND r.enrollment_id IN (
                  SELECT e.enrollment_id FROM $enrollTable e WHERE e.student_id = ?
              )";
        $gpaRow = fetchOneAssoc($conn, $gpaSql, [$sid]);
        $studentGpa = ($gpaRow && $gpaRow["cgpa"] !== null) ? (float)$gpaRow["cgpa"] : null;
        if ($studentGpa !== null) {
            if ($studentGpa < 2.00) $maxCredits = 12;
            elseif ($studentGpa < 3.00) $maxCredits = 15;
        }
    }

    $studentMaxCreditMap[$sid] = $maxCredits;
}

$existingEnrollmentMap = [];
$enrollmentLoadSql = "
    SELECT
        e.student_id,
        e.course_id,
        e.$enrollSemesterCol AS semester_value,"
        . ($hasYear ? " e.year AS year_value," : " NULL AS year_value,") . "
        CAST(c.$creditCol AS float) AS credit_hours
    FROM $enrollTable e
    JOIN $courseTable c ON c.course_id = e.course_id
";
$enrollLoadStmt = sqlsrv_query($conn, $enrollmentLoadSql);
if ($enrollLoadStmt !== false) {
    while ($row = sqlsrv_fetch_array($enrollLoadStmt, SQLSRV_FETCH_ASSOC)) {
        $sid = (int)($row["student_id"] ?? 0);
        $cid = (int)($row["course_id"] ?? 0);
        if ($sid <= 0 || $cid <= 0) continue;

        $semesterValueRaw = $row["semester_value"] ?? "";
        $semesterKeyPart = is_object($semesterValueRaw) && method_exists($semesterValueRaw, "format")
            ? $semesterValueRaw->format("Y-m-d H:i:s")
            : trim((string)$semesterValueRaw);
        $yearKeyPart = trim((string)($row["year_value"] ?? ""));
        $entryKey = $semesterKeyPart . "|" . $yearKeyPart;

        if (!isset($existingEnrollmentMap[$sid][$entryKey])) {
            $existingEnrollmentMap[$sid][$entryKey] = [
                "currentCredits" => 0.0,
                "courseIds" => []
            ];
        }

        $existingEnrollmentMap[$sid][$entryKey]["currentCredits"] += (float)($row["credit_hours"] ?? 0);
        $existingEnrollmentMap[$sid][$entryKey]["courseIds"][] = $cid;
    }
    sqlsrv_free_stmt($enrollLoadStmt);
}

$errorMsg = "";
$okMsg = "";

$values = [
    "student_id" => "",
    "term" => "",
    "year" => date("Y"),
    "course_ids" => []
];

$termOptions = ["Spring", "Summer", "Fall"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $errorMsg = "Invalid request token.";
    } else {
        $values["student_id"] = trim((string)($_POST["student_id"] ?? ""));
        $values["term"] = trim((string)($_POST["term"] ?? ""));
        $values["year"] = trim((string)($_POST["year"] ?? ""));
        $values["course_ids"] = $_POST["course_ids"] ?? [];
        if (!is_array($values["course_ids"])) $values["course_ids"] = [];

        $studentId = (int)$values["student_id"];
        $year = (int)$values["year"];

        $selectedStudent = null;
        foreach ($students as $s) {
            if ((int)($s["student_id"] ?? 0) === $studentId) {
                $selectedStudent = $s;
                break;
            }
        }

        $courseIds = [];
        foreach ($values["course_ids"] as $cid) {
            $cidInt = (int)$cid;
            if ($cidInt > 0) $courseIds[] = $cidInt;
        }
        $courseIds = array_values(array_unique($courseIds));

        $semesterValue = $semesterIsNumeric
            ? (int)($selectedStudent["student_semester"] ?? 0)
            : $values["term"];

        if ($studentId <= 0) {
            $errorMsg = "Student is required.";
        } elseif (!$selectedStudent) {
            $errorMsg = "Selected student was not found.";
        } elseif (!$semesterIsNumeric && $values["term"] === "") {
            $errorMsg = "Semester name is required.";
        } elseif ($hasYear && ($year < 2000 || $year > 2100)) {
            $errorMsg = "Valid year is required.";
        } elseif ($semesterIsNumeric && $semesterValue <= 0) {
            $errorMsg = "Selected student does not have a valid semester.";
        } elseif (count($courseIds) === 0) {
            $errorMsg = "Please select at least one course.";
        } else {
            $courseInfoMap = [];
            foreach ($courses as $c) {
                $cid = (int)($c["course_id"] ?? 0);
                if ($cid > 0) $courseInfoMap[$cid] = $c;
            }

            $missingCourse = false;
            $departmentMismatch = [];
            $selectedCredits = 0.0;
            foreach ($courseIds as $cid) {
                if (!isset($courseInfoMap[$cid])) {
                    $missingCourse = true;
                    break;
                }
                $courseRow = $courseInfoMap[$cid];
                $selectedCredits += (float)($courseRow["credit_hours"] ?? 0);
                if ((int)($courseRow["dept_id"] ?? 0) !== (int)($selectedStudent["dept_id"] ?? 0)) {
                    $departmentMismatch[] = (string)($courseRow["course_name"] ?? ("Course #" . $cid));
                }
            }

            if ($missingCourse) {
                $errorMsg = "One or more selected courses were not found.";
            } elseif ($departmentMismatch) {
                $errorMsg = "Department restriction failed. These courses do not belong to the student's department: " . implode(", ", $departmentMismatch) . ".";
            } else {
                $currentCreditSql = "
                    SELECT ISNULL(SUM(CAST(c.$creditCol AS float)), 0) AS current_credits
                    FROM $enrollTable e
                    JOIN $courseTable c ON c.course_id = e.course_id
                    WHERE e.student_id = ? AND e.$enrollSemesterCol = ?"
                    . ($hasYear ? " AND e.year = ?" : "");
                $currentCreditParams = [$studentId, $semesterValue];
                if ($hasYear) $currentCreditParams[] = $year;
                $currentCreditRow = fetchOneAssoc($conn, $currentCreditSql, $currentCreditParams);
                $currentCredits = (float)($currentCreditRow["current_credits"] ?? 0);

                $studentGpa = null;
                if ($gpaCol !== null) {
                    $gpaSql = "
                        SELECT AVG(CAST(r.$gpaCol AS float)) AS cgpa
                        FROM $resultTable r
                        WHERE r.$gpaCol IS NOT NULL
                          AND r.enrollment_id IN (
                              SELECT e.enrollment_id FROM $enrollTable e WHERE e.student_id = ?
                          )";
                    $gpaRow = fetchOneAssoc($conn, $gpaSql, [$studentId]);
                    if ($gpaRow && $gpaRow["cgpa"] !== null) $studentGpa = (float)$gpaRow["cgpa"];
                }

                $currentMaxCredits = $DEFAULT_MAX_CREDITS;
                if ($studentGpa !== null) {
                    if ($studentGpa < 2.00) $currentMaxCredits = 12;
                    elseif ($studentGpa < 3.00) $currentMaxCredits = 15;
                }

                $projectedCredits = $currentCredits + $selectedCredits;
                if ($projectedCredits > $currentMaxCredits) {
                    $errorMsg = "Credit limit exceeded. Current credits: $currentCredits, selected credits: $selectedCredits, maximum allowed: $currentMaxCredits.";
                } elseif ($currentCredits <= 0 && $selectedCredits < $MIN_CREDITS) {
                    $errorMsg = "Minimum credit requirement not met. Select at least $MIN_CREDITS credits for a regular semester.";
                } else {
                    $duplicateCourses = [];
                    $passedCourses = [];
                    $prerequisiteErrors = [];
                    $capacityErrors = [];

                    foreach ($courseIds as $cid) {
                        $courseLabel = (string)($courseInfoMap[$cid]["course_name"] ?? ("Course #" . $cid));

                        $dupSql = "
                            SELECT TOP 1 1 AS ok
                            FROM $enrollTable
                            WHERE student_id = ? AND course_id = ? AND $enrollSemesterCol = ?"
                            . ($hasYear ? " AND year = ?" : "");
                        $dupParams = [$studentId, $cid, $semesterValue];
                        if ($hasYear) $dupParams[] = $year;
                        if (fetchOneAssoc($conn, $dupSql, $dupParams)) {
                            $duplicateCourses[] = $courseLabel;
                        }

                        if ($gradeCol !== null) {
                            $passSql = "
                                SELECT TOP 1 r.$gradeCol AS grade
                                FROM $resultTable r
                                JOIN $enrollTable e ON e.enrollment_id = r.enrollment_id
                                WHERE e.student_id = ? AND e.course_id = ?
                                ORDER BY e.enrollment_id DESC";
                            $passRow = fetchOneAssoc($conn, $passSql, [$studentId, $cid]);
                            if ($passRow && isPassingGrade($passRow["grade"] ?? "")) {
                                $passedCourses[] = $courseLabel;
                            }
                        }

                        if ($coursePrereqCol !== null && $gradeCol !== null) {
                            $prereqRow = fetchOneAssoc($conn, "SELECT $coursePrereqCol AS prereq_id FROM $courseTable WHERE course_id = ?", [$cid]);
                            $prereqId = (int)($prereqRow["prereq_id"] ?? 0);
                            if ($prereqId > 0) {
                                $prereqPassSql = "
                                    SELECT TOP 1 r.$gradeCol AS grade
                                    FROM $resultTable r
                                    JOIN $enrollTable e ON e.enrollment_id = r.enrollment_id
                                    WHERE e.student_id = ? AND e.course_id = ?
                                    ORDER BY e.enrollment_id DESC";
                                $prereqPassRow = fetchOneAssoc($conn, $prereqPassSql, [$studentId, $prereqId]);
                                if (!$prereqPassRow || !isPassingGrade($prereqPassRow["grade"] ?? "")) {
                                    $prerequisiteErrors[] = $courseLabel;
                                }
                            }
                        }

                        if ($courseSeatLimitCol !== null) {
                            $seatInfo = fetchOneAssoc($conn, "SELECT $courseSeatLimitCol AS seat_limit FROM $courseTable WHERE course_id = ?", [$cid]);
                            $seatLimit = (int)($seatInfo["seat_limit"] ?? 0);
                            if ($seatLimit > 0) {
                                $seatSql = "
                                    SELECT COUNT(*) AS seat_count
                                    FROM $enrollTable
                                    WHERE course_id = ? AND $enrollSemesterCol = ?"
                                    . ($hasYear ? " AND year = ?" : "");
                                $seatParams = [$cid, $semesterValue];
                                if ($hasYear) $seatParams[] = $year;
                                $seatRow = fetchOneAssoc($conn, $seatSql, $seatParams);
                                if ((int)($seatRow["seat_count"] ?? 0) >= $seatLimit) {
                                    $capacityErrors[] = $courseLabel;
                                }
                            }
                        }
                    }

                    if ($duplicateCourses) {
                        $errorMsg = "Prevent duplicate enrollment: already enrolled in " . implode(", ", $duplicateCourses) . ".";
                    } elseif ($passedCourses) {
                        $errorMsg = "Already passed course check failed for: " . implode(", ", $passedCourses) . ".";
                    } elseif ($prerequisiteErrors) {
                        $errorMsg = "Prerequisite check failed for: " . implode(", ", $prerequisiteErrors) . ".";
                    } elseif ($capacityErrors) {
                        $errorMsg = "Seat limit is full for: " . implode(", ", $capacityErrors) . ".";
                    } else {
                        $insertCols = ["student_id", "course_id", $enrollSemesterCol];
                        $insertQs = ["?", "?", "?"];
                        if ($hasYear) {
                            $insertCols[] = "year";
                            $insertQs[] = "?";
                        }
                        if ($hasEnrollmentDate) {
                            $insertCols[] = "enrollment_date";
                            $insertQs[] = "?";
                        }

                        $insertSql = "
                            INSERT INTO $enrollTable (" . implode(", ", $insertCols) . ")
                            VALUES (" . implode(", ", $insertQs) . ")
                        ";

                        if (!sqlsrv_begin_transaction($conn)) {
                            $errorMsg = "Could not start enrollment transaction.";
                        } else {
                            $allInserted = true;
                            foreach ($courseIds as $cid) {
                                $insertParams = [$studentId, $cid, $semesterValue];
                                if ($hasYear) $insertParams[] = $year;
                                if ($hasEnrollmentDate) $insertParams[] = date("Y-m-d");

                                $ins = sqlsrv_query($conn, $insertSql, $insertParams);
                                if ($ins === false) {
                                    $allInserted = false;
                                    $errorMsg = "Enrollment insert failed" . ($debug ? ": " . sqlErrorText() : ".");
                                    break;
                                }
                                sqlsrv_free_stmt($ins);
                            }

                            if ($allInserted) {
                                sqlsrv_commit($conn);
                                header("Location: list.php?success=1");
                                exit();
                            }

                            sqlsrv_rollback($conn);
                        }
                    }
                }
            }
        }
    }
}

$name = $_SESSION["name"] ?? "User";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Enroll Student</title>
  <link rel="stylesheet" href="../assets/app.css">
  <style>
    .enroll-wrap{
      display:grid;
      grid-template-columns:1fr 340px;
      gap:18px;
      align-items:start;
    }
    .form-card{
      width:100%;
    }
    .form-grid{
      display:grid;
      grid-template-columns:repeat(2, 1fr);
      gap:20px;
    }
    .field{
      display:flex;
      flex-direction:column;
      gap:6px;
    }
    .field.full{
      grid-column:1 / -1;
    }
    .field label{
      font-size:13px;
      font-weight:900;
      color:var(--text);
    }
    .field input,
    .field select,
    .field textarea{
      width:100%;
      padding:12px;
      border:1px solid #d0d4e3;
      border-radius:10px;
      outline:none;
      background:#fff;
      font:inherit;
      color:var(--text);
    }
    .field input:focus,
    .field select:focus,
    .field textarea:focus{
      border-color:var(--primary);
    }
    .form-actions{
      display:flex;
      gap:12px;
      align-items:center;
      margin-top:18px;
      flex-wrap:wrap;
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
    .courses{
      margin-top:6px;
      border:1px solid var(--border);
      border-radius:12px;
      padding:12px;
      max-height:360px;
      overflow:auto;
      background:#fff;
    }
    .course-item{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      padding:10px;
      border-radius:10px;
    }
    .course-item:hover{
      background:#f7f8ff;
    }
    .course-left{
      display:flex;
      gap:10px;
      align-items:flex-start;
    }
    .course-left input{
      width:auto;
      margin-top:3px;
    }
    .course-title{
      font-weight:900;
    }
    .course-sub{
      color:var(--muted);
      font-weight:800;
      font-size:13px;
      margin-top:2px;
    }
    .pill{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:6px 10px;
      border-radius:999px;
      background:rgba(47,60,255,0.10);
      color:var(--primary);
      font-weight:900;
      font-size:12px;
      white-space:nowrap;
    }
    .summary-title{
      font-weight:950;
      font-size:22px;
      margin:0 0 10px;
    }
    .sum-row{
      display:flex;
      justify-content:space-between;
      align-items:center;
      padding:12px 0;
      border-top:1px solid var(--border);
      font-weight:900;
    }
    .sum-row:first-of-type{
      border-top:0;
    }
    .bar{
      height:10px;
      border-radius:999px;
      background:#e5e7eb;
      overflow:hidden;
      width:100%;
    }
    .bar > div{
      height:100%;
      background:var(--primary);
      width:0%;
    }
    @media (max-width:980px){
      .enroll-wrap{
        grid-template-columns:1fr;
      }
    }
    @media (max-width:860px){
      .form-grid{
        grid-template-columns:1fr;
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
      <a class="back-link" href="list.php">← Back to Enrollments</a>

      <div class="header">
        <h1>Enroll Student in Courses</h1>
      </div>

      <?php if ($okMsg !== ""): ?>
        <div class="alert-ok"><?php echo h($okMsg); ?></div>
      <?php endif; ?>
      <?php if ($errorMsg !== ""): ?>
        <div class="alert-err"><?php echo h($errorMsg); ?></div>
      <?php endif; ?>

      <div class="enroll-wrap">
        <div class="card form-card">
          <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">

            <div class="form-grid">
              <div class="field">
                <label>Student *</label>
                <select id="student_id" name="student_id" required>
                  <option value="">Select Student</option>
                  <?php foreach ($students as $s): ?>
                    <?php
                      $sid = (int)($s["student_id"] ?? 0);
                      $sdept = (int)($s["dept_id"] ?? 0);
                      $ssem = (int)($s["student_semester"] ?? 0);
                    ?>
                    <option
                      value="<?php echo $sid; ?>"
                      data-dept-id="<?php echo $sdept; ?>"
                      data-student-semester="<?php echo $ssem; ?>"
                      <?php echo ((string)$values["student_id"] === (string)$sid) ? "selected" : ""; ?>
                    >
                      <?php echo h($sid . " - " . ($s["student_name"] ?? "")); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label><?php echo $semesterIsNumeric ? "Semester Session" : "Semester Name"; ?> *</label>
                <select id="term" name="term" <?php echo $semesterIsNumeric ? "disabled" : "required"; ?>>
                  <option value=""><?php echo $semesterIsNumeric ? "Auto from student semester" : "Select Term"; ?></option>
                  <?php foreach ($termOptions as $opt): ?>
                    <option value="<?php echo h($opt); ?>" <?php echo ($values["term"] === $opt) ? "selected" : ""; ?>>
                      <?php echo h($opt); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label>Year *</label>
                <input id="year" type="number" name="year" placeholder="e.g., 2025" value="<?php echo h($values["year"]); ?>" <?php echo $hasYear ? "required" : "disabled"; ?> min="2000" max="2100" />
              </div>

              <div class="field">
                <label>Student Semester</label>
                <input type="text" id="studentSemesterDisplay" value="" readonly />
              </div>

              <div class="field full">
                <label>Select Courses *</label>

                <div class="courses" id="courseBox">
                  <?php if (count($courses) === 0): ?>
                    <div style="color:#64748b;font-weight:900;">No courses found.</div>
                  <?php else: ?>
                    <?php foreach ($courses as $c): ?>
                      <?php
                        $cid = (int)($c["course_id"] ?? 0);
                        $cname = (string)($c["course_name"] ?? "");
                        $ccode = (string)($c["course_code"] ?? "");
                        $cr = (float)($c["credit_hours"] ?? 0);
                        $cdept = (int)($c["dept_id"] ?? 0);
                        $checked = in_array((string)$cid, array_map("strval", $values["course_ids"]), true);
                      ?>
                      <div class="course-item courseRow" data-dept-id="<?php echo $cdept; ?>" data-course-id="<?php echo $cid; ?>">
                        <div class="course-left">
                          <input
                            class="courseCheck"
                            type="checkbox"
                            name="course_ids[]"
                            value="<?php echo $cid; ?>"
                            data-credits="<?php echo h($cr); ?>"
                            <?php echo $checked ? "checked" : ""; ?>
                          />
                          <div>
                            <div class="course-title">
                              <?php echo h(($ccode !== "" ? ($ccode . " - ") : "") . $cname); ?>
                            </div>
                            <div class="course-sub"><?php echo h("Credits: " . $cr); ?></div>
                          </div>
                        </div>
                        <span class="pill"><?php echo h($cr . " cr"); ?></span>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="form-actions">
              <button class="btn btn-primary" type="submit">Enroll Student</button>
              <a class="btn" href="list.php">Cancel</a>
            </div>
          </form>
        </div>

        <div class="card">
          <div class="summary-title">Credit Summary</div>

          <div class="sum-row">
            <div style="color:var(--muted);font-weight:900;">Current Credits</div>
            <div id="curCredits" style="font-weight:950;">0</div>
          </div>

          <div class="sum-row">
            <div style="color:var(--muted);font-weight:900;">Maximum Credits</div>
            <div id="maxCredits" style="font-weight:950;"><?php echo (int)$currentMaxCredits; ?></div>
          </div>

          <div class="sum-row">
            <div style="color:var(--muted);font-weight:900;">Minimum Credits</div>
            <div style="font-weight:950;"><?php echo (int)$MIN_CREDITS; ?></div>
          </div>

          <div class="sum-row">
            <div style="color:var(--muted);font-weight:900;">Remaining</div>
            <div id="remCredits" style="font-weight:950;color:#16a34a;"><?php echo (int)$currentMaxCredits; ?></div>
          </div>

          <div class="sum-row" style="align-items:flex-start;flex-direction:column;gap:10px;">
            <div style="width:100%;display:flex;justify-content:space-between;align-items:center;">
              <div style="color:var(--muted);font-weight:900;">Credit Usage</div>
              <div id="usagePct" style="font-weight:950;">0%</div>
            </div>
            <div class="bar">
              <div id="usageBar"></div>
            </div>
          </div>

          <div id="warnBox" class="alert-err" style="display:none;margin-top:12px;">
            Selected credits exceed maximum.
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
(function(){
  const DEFAULT_MAX = <?php echo (int)$DEFAULT_MAX_CREDITS; ?>;
  const SEMESTER_IS_NUMERIC = <?php echo $semesterIsNumeric ? "true" : "false"; ?>;
  const HAS_YEAR = <?php echo $hasYear ? "true" : "false"; ?>;
  const enrollmentMap = <?php echo json_encode($existingEnrollmentMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};
  const studentMaxCreditsMap = <?php echo json_encode($studentMaxCreditMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};
  const studentSelect = document.getElementById("student_id");
  const termSelect = document.getElementById("term");
  const yearInput = document.getElementById("year");
  const courseRows = Array.from(document.querySelectorAll(".courseRow"));
  const checks = Array.from(document.querySelectorAll(".courseCheck"));
  const cur = document.getElementById("curCredits");
  const max = document.getElementById("maxCredits");
  const rem = document.getElementById("remCredits");
  const pct = document.getElementById("usagePct");
  const bar = document.getElementById("usageBar");
  const warn = document.getElementById("warnBox");
  const studentSemesterDisplay = document.getElementById("studentSemesterDisplay");

  function getSelectedStudentOption(){
    return studentSelect ? studentSelect.options[studentSelect.selectedIndex] : null;
  }

  function getSemesterValue(selected){
    if (!selected) return "";
    if (SEMESTER_IS_NUMERIC) {
      return selected.getAttribute("data-student-semester") || "";
    }
    return termSelect ? termSelect.value.trim() : "";
  }

  function getYearValue(){
    if (!HAS_YEAR || !yearInput) return "";
    return yearInput.value.trim();
  }

  function getEnrollmentKey(selected){
    return getSemesterValue(selected) + "|" + getYearValue();
  }

  function getCurrentEnrollmentInfo(){
    const selected = getSelectedStudentOption();
    const studentId = selected ? (selected.value || "") : "";
    if (!studentId) {
      return { currentCredits: 0, courseIds: [] };
    }

    const key = getEnrollmentKey(selected);
    const studentEntries = enrollmentMap[studentId] || {};
    const currentEntry = studentEntries[key] || { currentCredits: 0, courseIds: [] };
    return {
      currentCredits: parseFloat(currentEntry.currentCredits || 0),
      courseIds: Array.isArray(currentEntry.courseIds) ? currentEntry.courseIds.map(String) : []
    };
  }

  function getCurrentMaxCredits(){
    const selected = getSelectedStudentOption();
    const studentId = selected ? (selected.value || "") : "";
    if (!studentId) return DEFAULT_MAX;
    return parseFloat(studentMaxCreditsMap[studentId] || DEFAULT_MAX);
  }

  function filterCoursesByStudentDept(){
    const selected = getSelectedStudentOption();
    const deptId = selected ? selected.getAttribute("data-dept-id") : "";
    const studentSemester = selected ? selected.getAttribute("data-student-semester") : "";
    const currentEnrollment = getCurrentEnrollmentInfo();
    const alreadyEnrolled = new Set(currentEnrollment.courseIds);
    studentSemesterDisplay.value = studentSemester ? ("Semester " + studentSemester) : "";

    courseRows.forEach(row => {
      const rowDeptId = row.getAttribute("data-dept-id") || "";
      const rowCourseId = row.getAttribute("data-course-id") || "";
      const checkbox = row.querySelector(".courseCheck");
      const allowedByDepartment = !deptId || rowDeptId === deptId;
      const alreadyTakenThisSemester = alreadyEnrolled.has(String(rowCourseId));

      if (allowedByDepartment && !alreadyTakenThisSemester) {
        row.style.display = "";
      } else {
        row.style.display = "none";
        if (checkbox) checkbox.checked = false;
      }
    });

    calc();
  }

  function calc(){
    const currentEnrollment = getCurrentEnrollmentInfo();
    const currentMax = getCurrentMaxCredits();
    let selectedTotal = 0;

    checks.forEach(ch => {
      const row = ch.closest(".courseRow");
      if (row && row.style.display !== "none" && ch.checked) {
        selectedTotal += parseFloat(ch.getAttribute("data-credits") || "0");
      }
    });

    const total = currentEnrollment.currentCredits + selectedTotal;
    cur.textContent = total.toFixed(1).replace(/\.0$/, "");
    max.textContent = currentMax.toFixed(1).replace(/\.0$/, "");

    const remaining = currentMax - total;
    rem.textContent = remaining.toFixed(1).replace(/\.0$/, "");
    rem.style.color = remaining < 0 ? "#dc2626" : "#16a34a";

    const usage = currentMax === 0 ? 0 : Math.round((total / currentMax) * 100);
    pct.textContent = usage + "%";
    bar.style.width = Math.max(0, Math.min(100, usage)) + "%";

    warn.textContent = "Selected credits exceed maximum.";
    warn.style.display = total > currentMax ? "block" : "none";
  }

  if (studentSelect) {
    studentSelect.addEventListener("change", filterCoursesByStudentDept);
    filterCoursesByStudentDept();
  }

  if (termSelect) {
    termSelect.addEventListener("change", filterCoursesByStudentDept);
  }

  if (yearInput) {
    yearInput.addEventListener("input", filterCoursesByStudentDept);
    yearInput.addEventListener("change", filterCoursesByStudentDept);
  }

  checks.forEach(ch => ch.addEventListener("change", calc));
  calc();
})();
</script>
</body>
</html>
