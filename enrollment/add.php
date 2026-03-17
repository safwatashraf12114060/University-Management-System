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

$studentTable = "dbo.STUDENT";
$courseTable  = "dbo.COURSE";
$deptTable    = "dbo.DEPARTMENT";
$enrollTable  = "dbo.ENROLLMENT";

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

$MAX_CREDITS = 18;

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

        $courseIds = [];
        foreach ($values["course_ids"] as $cid) {
            $cidInt = (int)$cid;
            if ($cidInt > 0) $courseIds[] = $cidInt;
        }
        $courseIds = array_values(array_unique($courseIds));

        if ($studentId <= 0) {
            $errorMsg = "Student is required.";
        } elseif ($values["term"] === "") {
            $errorMsg = "Term is required.";
        } elseif ($year < 2000 || $year > 2100) {
            $errorMsg = "Valid year is required.";
        } elseif (count($courseIds) === 0) {
            $errorMsg = "Please select at least one course.";
        } else {
            $selectedCredits = 0;
            $courseCreditMap = [];
            foreach ($courses as $c) {
                $cid = (int)($c["course_id"] ?? 0);
                $cr = (float)($c["credit_hours"] ?? 0);
                if ($cid > 0) $courseCreditMap[$cid] = $cr;
            }
            foreach ($courseIds as $cid) $selectedCredits += (float)($courseCreditMap[$cid] ?? 0);

            if ($selectedCredits > $MAX_CREDITS) {
                $errorMsg = "Selected credits exceed maximum ($MAX_CREDITS).";
            } else {
                $insertSql = "
                    INSERT INTO $enrollTable (student_id, course_id, semester, year, enrollment_date)
                    VALUES (?, ?, ?, ?, ?)
                ";

                $today = date("Y-m-d");
                $connOk = true;

                $existsSql = "
                    SELECT TOP 1 1 AS ok
                    FROM $enrollTable
                    WHERE student_id = ? AND course_id = ? AND semester = ? AND year = ?
                ";

                foreach ($courseIds as $cid) {
                    $ex = sqlsrv_query($conn, $existsSql, [$studentId, $cid, $values["term"], $year]);
                    $already = false;
                    if ($ex !== false) {
                        $row = sqlsrv_fetch_array($ex, SQLSRV_FETCH_ASSOC);
                        $already = $row ? true : false;
                        sqlsrv_free_stmt($ex);
                    }

                    if ($already) {
                        continue;
                    }

                    $ins = sqlsrv_query($conn, $insertSql, [$studentId, $cid, $values["term"], $year, $today]);
                    if ($ins === false) {
                        $connOk = false;
                        $errorMsg = "Insert failed.";
                        if ($debug) {
                            $errorMsg .= " " . print_r(sqlsrv_errors(), true);
                        }
                        break;
                    }
                    sqlsrv_free_stmt($ins);
                }

                if ($connOk && $errorMsg === "") {
                    header("Location: list.php?success=1");
                    exit();
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
                <label>Term *</label>
                <select name="term" required>
                  <option value="">Select Term</option>
                  <?php foreach ($termOptions as $opt): ?>
                    <option value="<?php echo h($opt); ?>" <?php echo ($values["term"] === $opt) ? "selected" : ""; ?>>
                      <?php echo h($opt); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label>Year *</label>
                <input type="number" name="year" placeholder="e.g., 2025" value="<?php echo h($values["year"]); ?>" required min="2000" max="2100" />
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
                      <div class="course-item courseRow" data-dept-id="<?php echo $cdept; ?>">
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
            <div style="font-weight:950;"><?php echo (int)$MAX_CREDITS; ?></div>
          </div>

          <div class="sum-row">
            <div style="color:var(--muted);font-weight:900;">Remaining</div>
            <div id="remCredits" style="font-weight:950;color:#16a34a;"><?php echo (int)$MAX_CREDITS; ?></div>
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
  const MAX = <?php echo (int)$MAX_CREDITS; ?>;
  const studentSelect = document.getElementById("student_id");
  const courseRows = Array.from(document.querySelectorAll(".courseRow"));
  const checks = Array.from(document.querySelectorAll(".courseCheck"));
  const cur = document.getElementById("curCredits");
  const rem = document.getElementById("remCredits");
  const pct = document.getElementById("usagePct");
  const bar = document.getElementById("usageBar");
  const warn = document.getElementById("warnBox");
  const studentSemesterDisplay = document.getElementById("studentSemesterDisplay");

  function filterCoursesByStudentDept(){
    const selected = studentSelect.options[studentSelect.selectedIndex];
    const deptId = selected ? selected.getAttribute("data-dept-id") : "";
    const studentSemester = selected ? selected.getAttribute("data-student-semester") : "";
    studentSemesterDisplay.value = studentSemester ? ("Semester " + studentSemester) : "";

    courseRows.forEach(row => {
      const rowDeptId = row.getAttribute("data-dept-id") || "";
      const checkbox = row.querySelector(".courseCheck");
      if (!deptId || rowDeptId === deptId) {
        row.style.display = "";
      } else {
        row.style.display = "none";
        if (checkbox) checkbox.checked = false;
      }
    });

    calc();
  }

  function calc(){
    let total = 0;
    checks.forEach(ch => {
      const row = ch.closest(".courseRow");
      if (row && row.style.display !== "none" && ch.checked) {
        total += parseFloat(ch.getAttribute("data-credits") || "0");
      }
    });

    cur.textContent = total;
    const remaining = MAX - total;
    rem.textContent = remaining;
    rem.style.color = remaining < 0 ? "#dc2626" : "#16a34a";

    const usage = MAX === 0 ? 0 : Math.round((total / MAX) * 100);
    pct.textContent = usage + "%";
    bar.style.width = Math.max(0, Math.min(100, usage)) + "%";

    warn.style.display = total > MAX ? "block" : "none";
  }

  if (studentSelect) {
    studentSelect.addEventListener("change", filterCoursesByStudentDept);
    filterCoursesByStudentDept();
  }

  checks.forEach(ch => ch.addEventListener("change", calc));
  calc();
})();
</script>
</body>
</html>