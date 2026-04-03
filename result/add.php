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
    return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
}

function addSqlsrvError($baseMsg, $debug) {
    if (!$debug) return $baseMsg;
    $e = sqlsrv_errors();
    return $baseMsg . "\n" . print_r($e, true);
}

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return isset($row["len"]) && $row["len"] !== null;
}

function calcGrade($marks) {
    $m = (float)$marks;
    if ($m >= 90) return "A";
    if ($m >= 85) return "A-";
    if ($m >= 80) return "B+";
    if ($m >= 75) return "B";
    if ($m >= 70) return "B-";
    if ($m >= 65) return "C+";
    if ($m >= 60) return "C";
    if ($m >= 55) return "C-";
    if ($m >= 50) return "D";
    return "F";
}

function normalizeScalar($value) {
    if (is_object($value) && method_exists($value, "format")) {
        return $value->format("Y-m-d H:i:s");
    }
    return trim((string)$value);
}

$displayName = $_SESSION["user_name"]
    ?? $_SESSION["name"]
    ?? $_SESSION["username"]
    ?? $_SESSION["email"]
    ?? "User";

$studentNameCol = colExists($conn, "dbo.STUDENT", "student_name") ? "student_name" : (colExists($conn, "dbo.STUDENT", "name") ? "name" : "full_name");
$courseNameCol = colExists($conn, "dbo.COURSE", "course_name") ? "course_name" : (colExists($conn, "dbo.COURSE", "title") ? "title" : "name");
$courseCodeCol = null;
foreach (["course_code", "code"] as $candidate) {
    if (colExists($conn, "dbo.COURSE", $candidate)) {
        $courseCodeCol = $candidate;
        break;
    }
}

$hasEnrollYear = colExists($conn, "dbo.ENROLLMENT", "year");
$semesterCol = colExists($conn, "dbo.ENROLLMENT", "semester_name") ? "semester_name" : "semester";
$semesterIsNumeric = true;
$semTypeStmt = sqlsrv_query($conn, "
    SELECT DATA_TYPE AS type_name
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'ENROLLMENT' AND COLUMN_NAME = ?
", [$semesterCol]);
if ($semTypeStmt !== false) {
    $semTypeRow = sqlsrv_fetch_array($semTypeStmt, SQLSRV_FETCH_ASSOC);
    $semesterIsNumeric = in_array(strtolower((string)($semTypeRow["type_name"] ?? "")), ["int", "smallint", "tinyint", "bigint"], true);
    sqlsrv_free_stmt($semTypeStmt);
}

$error = "";
$student_id = trim((string)($_POST["student_id"] ?? ""));
$course_id = (int)($_POST["course_id"] ?? 0);
$semester = trim((string)($_POST["semester"] ?? ""));
$year = $hasEnrollYear ? (int)($_POST["year"] ?? 0) : 0;
$marks = trim((string)($_POST["marks"] ?? ""));

$enrollmentRows = [];
$semesterOptions = [];
$yearOptions = [];
$studentsByTerm = [];
$coursesByStudentTerm = [];

$enrollmentSql = "
    SELECT
        e.enrollment_id,
        e.student_id,
        e.course_id,
        e.$semesterCol AS semester_value,
        " . ($hasEnrollYear ? "e.year AS year_value," : "NULL AS year_value,") . "
        s.$studentNameCol AS student_name,
        " . ($courseCodeCol !== null ? "c.$courseCodeCol AS course_code," : "CONVERT(VARCHAR(50), c.course_id) AS course_code,") . "
        c.$courseNameCol AS course_name
    FROM ENROLLMENT e
    JOIN STUDENT s ON s.student_id = e.student_id
    JOIN COURSE c ON c.course_id = e.course_id
    ORDER BY " . ($hasEnrollYear ? "e.year DESC," : "") . " e.$semesterCol DESC, s.$studentNameCol ASC, " . ($courseCodeCol !== null ? "c.$courseCodeCol ASC" : "c.$courseNameCol ASC");
$enrollmentStmt = sqlsrv_query($conn, $enrollmentSql);
if ($enrollmentStmt !== false) {
    while ($row = sqlsrv_fetch_array($enrollmentStmt, SQLSRV_FETCH_ASSOC)) {
        $semesterValue = normalizeScalar($row["semester_value"] ?? "");
        $yearValue = $hasEnrollYear ? trim((string)($row["year_value"] ?? "")) : "";
        $studentIdValue = trim((string)($row["student_id"] ?? ""));
        $courseIdValue = (int)($row["course_id"] ?? 0);
        $termKey = $semesterValue . "|" . $yearValue;

        $enrollmentRows[] = [
            "enrollment_id" => (int)($row["enrollment_id"] ?? 0),
            "semester" => $semesterValue,
            "year" => $yearValue,
            "student_id" => $studentIdValue,
            "student_name" => (string)($row["student_name"] ?? ""),
            "course_id" => $courseIdValue,
            "course_code" => (string)($row["course_code"] ?? ""),
            "course_name" => (string)($row["course_name"] ?? ""),
        ];

        if ($semesterValue !== "") $semesterOptions[$semesterValue] = true;
        if ($hasEnrollYear && $yearValue !== "") $yearOptions[$yearValue] = true;

        if ($studentIdValue !== "" && !isset($studentsByTerm[$termKey][$studentIdValue])) {
            $studentsByTerm[$termKey][$studentIdValue] = [
                "student_id" => $studentIdValue,
                "student_name" => (string)($row["student_name"] ?? ""),
            ];
        }

        if ($studentIdValue !== "" && $courseIdValue > 0 && !isset($coursesByStudentTerm[$termKey][$studentIdValue][$courseIdValue])) {
            $coursesByStudentTerm[$termKey][$studentIdValue][$courseIdValue] = [
                "course_id" => $courseIdValue,
                "course_code" => (string)($row["course_code"] ?? ""),
                "course_name" => (string)($row["course_name"] ?? ""),
            ];
        }
    }
    sqlsrv_free_stmt($enrollmentStmt);
}

$semesterOptions = array_keys($semesterOptions);
$yearOptions = array_keys($yearOptions);
if ($semesterIsNumeric) {
    usort($semesterOptions, static function ($a, $b) {
        return (int)$a <=> (int)$b;
    });
} else {
    natcasesort($semesterOptions);
    $semesterOptions = array_values($semesterOptions);
}
rsort($yearOptions, SORT_NUMERIC);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid request token.";
    } else {
        if ($semester === "" || ($hasEnrollYear && $year <= 0) || $student_id === "" || $course_id <= 0 || $marks === "") {
            $error = "All required fields must be filled.";
        } else {
            $m = (float)$marks;
            if ($m < 0 || $m > 100) {
                $error = "Marks must be between 0 and 100.";
            }
        }

        if ($error === "") {
            $termKey = $semester . "|" . ($hasEnrollYear ? (string)$year : "");
            if (!isset($studentsByTerm[$termKey][$student_id])) {
                $error = "Selected student is not enrolled in the chosen semester and year.";
            } elseif (!isset($coursesByStudentTerm[$termKey][$student_id][$course_id])) {
                $error = "Selected course is not enrolled by this student in the chosen semester and year.";
            }
        }

        if ($error === "") {
            $enSql = "
                SELECT TOP 1 e.enrollment_id
                FROM ENROLLMENT e
                WHERE e.student_id = ? AND e.course_id = ? AND e.$semesterCol = ?"
                . ($hasEnrollYear ? " AND e.year = ?" : "") . "
                ORDER BY e.enrollment_id DESC
            ";
            $semesterValue = $semesterIsNumeric ? (int)$semester : $semester;
            $enParams = [$student_id, $course_id, $semesterValue];
            if ($hasEnrollYear) $enParams[] = $year;
            $enSt = sqlsrv_query($conn, $enSql, $enParams);
            $enRow = $enSt ? sqlsrv_fetch_array($enSt, SQLSRV_FETCH_ASSOC) : null;
            $enrollmentId = (int)($enRow["enrollment_id"] ?? 0);
            if ($enSt !== false) sqlsrv_free_stmt($enSt);

            if ($enrollmentId <= 0) {
                $error = "Enrollment not found for the selected student, course, and semester.";
            } else {
                $dupSql = "SELECT TOP 1 result_id FROM RESULT WHERE enrollment_id = ?";
                $dupSt = sqlsrv_query($conn, $dupSql, [$enrollmentId]);
                $alreadyExists = $dupSt !== false && sqlsrv_fetch_array($dupSt, SQLSRV_FETCH_ASSOC);
                if ($dupSt !== false) sqlsrv_free_stmt($dupSt);

                if ($alreadyExists) {
                    $error = "Result already exists for this enrollment.";
                } else {
                    $grade = calcGrade((float)$marks);
                    $insSql = "INSERT INTO RESULT (enrollment_id, marks, grade) VALUES (?, ?, ?)";
                    $insSt = sqlsrv_query($conn, $insSql, [$enrollmentId, (float)$marks, $grade]);

                    if ($insSt === false) {
                        $error = addSqlsrvError("Insert failed.", $debug);
                    } else {
                        header("Location: list.php?success=1");
                        exit();
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Add Result</title>
  <link rel="stylesheet" href="../assets/app.css">
  <style>
    .result-card{max-width:920px;}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    .full{grid-column:1 / -1;}
    .alert-err{margin-bottom:14px;padding:12px 14px;border-radius:12px;background:#fee2e2;border:1px solid #fecaca;color:#991b1b;font-weight:800;white-space:pre-wrap;}
    .marks-field input,
    .grade-field input{min-height:56px;font-size:16px;}
    .scale{margin-top:14px;border:1px solid #c7d2fe;background:#eff6ff;border-radius:16px;padding:16px;}
    .scale h3{margin:0 0 10px;font-size:18px;font-weight:900;}
    .scale-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;color:#0f172a;font-weight:800;}
    @media (max-width:860px){
      .form-grid{grid-template-columns:1fr;}
      .scale-grid{grid-template-columns:repeat(2,1fr);}
    }
  </style>
</head>
<body>
<div class="layout">
  <?php renderSidebar("results", "../"); ?>

  <main class="content">
    <?php renderTopbar($displayName, "", "../logout.php", false); ?>

    <div class="page">
      <a class="back-link" href="list.php">&#8592; Back to Results</a>
      <div class="header">
        <h1>Add Result</h1>
      </div>

      <?php if ($error !== ""): ?>
        <div class="alert-err"><?php echo h($error); ?></div>
      <?php endif; ?>

      <div class="card result-card">
        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">

          <div class="form-grid">
            <div>
              <label for="semester">Semester *</label>
              <select id="semester" name="semester" required>
                <option value=""><?php echo $semesterIsNumeric ? "Select Semester" : "Select Term"; ?></option>
                <?php foreach ($semesterOptions as $opt): ?>
                  <option value="<?php echo h($opt); ?>" <?php echo ($semester === (string)$opt) ? "selected" : ""; ?>>
                    <?php echo h($opt); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="year">Year <?php echo $hasEnrollYear ? "*" : ""; ?></label>
              <?php if ($hasEnrollYear): ?>
                <select id="year" name="year" required>
                  <option value="">Select Year</option>
                  <?php foreach ($yearOptions as $opt): ?>
                    <option value="<?php echo h($opt); ?>" <?php echo ((string)$year === (string)$opt) ? "selected" : ""; ?>>
                      <?php echo h($opt); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input id="year" name="year" type="text" value="N/A" disabled>
              <?php endif; ?>
            </div>

            <div>
              <label for="student_id">Student *</label>
              <select id="student_id" name="student_id" required disabled>
                <option value="">Select semester and year first</option>
              </select>
            </div>

            <div>
              <label for="course_id">Course *</label>
              <select id="course_id" name="course_id" required disabled>
                <option value="">Select student first</option>
              </select>
            </div>

            <div class="full marks-field">
              <label for="marks">Total Marks (out of 100) *</label>
              <input id="marks" name="marks" type="number" min="0" max="100" step="0.01" placeholder="e.g., 85" value="<?php echo h($marks); ?>" required>
            </div>

            <div class="full grade-field">
              <label for="grade">Grade (Auto-calculated)</label>
              <input id="grade" type="text" value="" placeholder="Grade will appear here" disabled>
            </div>

            <div class="full scale">
              <h3>Grading Scale</h3>
              <div class="scale-grid">
                <div>A: 90-100</div>
                <div>A-: 85-89</div>
                <div>B+: 80-84</div>
                <div>B: 75-79</div>
                <div>B-: 70-74</div>
                <div>C+: 65-69</div>
                <div>C: 60-64</div>
                <div>C-: 55-59</div>
                <div>D: 50-54</div>
                <div>F: Below 50</div>
              </div>
              <div class="muted" style="margin-top:10px;">Grade is calculated automatically from marks.</div>
            </div>
          </div>

          <div class="form-actions" style="margin-top:16px;">
            <button class="btn btn-primary" type="submit">Add Result</button>
            <a class="btn" href="list.php">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>

<script>
(function(){
  const HAS_YEAR = <?php echo $hasEnrollYear ? "true" : "false"; ?>;
  const enrollmentRows = <?php echo json_encode($enrollmentRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || [];
  const selectedStudentId = <?php echo json_encode($student_id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const selectedCourseId = <?php echo json_encode((string)$course_id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  const semesterEl = document.getElementById("semester");
  const yearEl = document.getElementById("year");
  const studentEl = document.getElementById("student_id");
  const courseEl = document.getElementById("course_id");
  const marksEl = document.getElementById("marks");
  const gradeEl = document.getElementById("grade");

  function calcGrade(m){
    const x = Number(m);
    if (!Number.isFinite(x)) return "";
    if (x >= 90) return "A";
    if (x >= 85) return "A-";
    if (x >= 80) return "B+";
    if (x >= 75) return "B";
    if (x >= 70) return "B-";
    if (x >= 65) return "C+";
    if (x >= 60) return "C";
    if (x >= 55) return "C-";
    if (x >= 50) return "D";
    return "F";
  }

  function setOptions(selectEl, items, placeholder, selectedValue){
    selectEl.innerHTML = "";
    const first = document.createElement("option");
    first.value = "";
    first.textContent = placeholder;
    selectEl.appendChild(first);

    items.forEach(function(item){
      const opt = document.createElement("option");
      opt.value = String(item.value);
      opt.textContent = item.label;
      if (String(item.value) === String(selectedValue)) {
        opt.selected = true;
      }
      selectEl.appendChild(opt);
    });

    selectEl.disabled = items.length === 0;
    if (items.length === 0) {
      selectEl.value = "";
    }
  }

  function getTermRows(){
    const semesterValue = semesterEl.value.trim();
    const yearValue = HAS_YEAR ? String(yearEl.value || "").trim() : "";
    if (!semesterValue || (HAS_YEAR && !yearValue)) return [];
    return enrollmentRows.filter(function(row){
      return String(row.semester) === semesterValue && String(row.year || "") === yearValue;
    });
  }

  function renderStudents(preserveSelection){
    const rows = getTermRows();
    const map = new Map();

    rows.forEach(function(row){
      if (!map.has(String(row.student_id))) {
        map.set(String(row.student_id), {
          value: String(row.student_id),
          label: String(row.student_id) + " - " + String(row.student_name || "")
        });
      }
    });

    const items = Array.from(map.values()).sort(function(a, b){
      return a.label.localeCompare(b.label);
    });
    const preferred = preserveSelection ? studentEl.value || selectedStudentId : "";
    setOptions(studentEl, items, rows.length ? "Select Student" : "No students found", preferred);
  }

  function renderCourses(preserveSelection){
    const semesterValue = semesterEl.value.trim();
    const yearValue = HAS_YEAR ? String(yearEl.value || "").trim() : "";
    const studentValue = String(studentEl.value || "").trim();

    if (!semesterValue || (HAS_YEAR && !yearValue)) {
      setOptions(courseEl, [], "Select semester and year first", "");
      return;
    }
    if (!studentValue) {
      setOptions(courseEl, [], "Select student first", "");
      return;
    }

    const map = new Map();
    enrollmentRows.forEach(function(row){
      if (String(row.semester) !== semesterValue) return;
      if (String(row.year || "") !== yearValue) return;
      if (String(row.student_id) !== studentValue) return;
      if (!map.has(String(row.course_id))) {
        const prefix = row.course_code ? String(row.course_code) + " - " : "";
        map.set(String(row.course_id), {
          value: String(row.course_id),
          label: prefix + String(row.course_name || "")
        });
      }
    });

    const items = Array.from(map.values()).sort(function(a, b){
      return a.label.localeCompare(b.label);
    });
    const preferred = preserveSelection ? courseEl.value || selectedCourseId : "";
    setOptions(courseEl, items, items.length ? "Select Course" : "No enrolled courses found", preferred);
  }

  function renderNote(){
    if (!document.getElementById("selectionNote")) {
      return;
    }
    const noteEl = document.getElementById("selectionNote");
    const semesterValue = semesterEl.value.trim();
    const yearValue = HAS_YEAR ? String(yearEl.value || "").trim() : "";
    const studentValue = String(studentEl.value || "").trim();

    if (!semesterValue || (HAS_YEAR && !yearValue)) {
      noteEl.textContent = "Semester and year select kore student list load korun.";
      return;
    }
    if (!studentEl.disabled && !studentValue) {
      noteEl.textContent = "Ei semester/year-e enrolled student list theke student select korun.";
      return;
    }
    if (!courseEl.disabled && !courseEl.value) {
      noteEl.textContent = "Selected student je course-gula enroll korse, ekhon oi course theke ekta select korun.";
      return;
    }
    noteEl.textContent = "Form ready. Marks dile grade automatically calculate hobe.";
  }

  function refreshAll(preserveStudent, preserveCourse){
    renderStudents(preserveStudent);
    renderCourses(preserveCourse);
    renderNote();
  }

  semesterEl.addEventListener("change", function(){
    studentEl.value = "";
    courseEl.value = "";
    refreshAll(false, false);
  });

  if (HAS_YEAR) {
    yearEl.addEventListener("change", function(){
      studentEl.value = "";
      courseEl.value = "";
      refreshAll(false, false);
    });
  }

  studentEl.addEventListener("change", function(){
    courseEl.value = "";
    renderCourses(false);
    renderNote();
  });

  marksEl.addEventListener("input", function(){
    gradeEl.value = marksEl.value === "" ? "" : calcGrade(marksEl.value);
  });

  refreshAll(true, true);
  gradeEl.value = marksEl.value === "" ? "" : calcGrade(marksEl.value);
})();
</script>
</body>
</html>
