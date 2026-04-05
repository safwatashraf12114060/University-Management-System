<?php
session_start();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../partials/layout.php";
require_once __DIR__ . "/../partials/feedback.php";
require_once __DIR__ . "/../partials/activity_log.php";

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

$courseTable  = "dbo.COURSE";
$deptTable    = "dbo.DEPARTMENT";
$teacherTable = "dbo.TEACHER";

/* ---------- COURSE columns ---------- */
$courseIdCol = null;
foreach (["course_id", "id"] as $c) {
    if (colExists($conn, $courseTable, $c)) {
        $courseIdCol = $c;
        break;
    }
}
if ($courseIdCol === null) $courseIdCol = "course_id";

$courseCodeCol = null;
foreach (["course_code", "code"] as $c) {
    if (colExists($conn, $courseTable, $c)) {
        $courseCodeCol = $c;
        break;
    }
}

$courseNameCol = null;
foreach (["course_name", "title", "name"] as $c) {
    if (colExists($conn, $courseTable, $c)) {
        $courseNameCol = $c;
        break;
    }
}
if ($courseNameCol === null) $courseNameCol = "course_name";

$creditCol = null;
foreach (["credit_hours", "credits", "credit"] as $c) {
    if (colExists($conn, $courseTable, $c)) {
        $creditCol = $c;
        break;
    }
}
if ($creditCol === null) $creditCol = "credit_hours";

$courseDeptFkCol = null;
foreach (["dept_id", "department_id"] as $c) {
    if (colExists($conn, $courseTable, $c)) {
        $courseDeptFkCol = $c;
        break;
    }
}
if ($courseDeptFkCol === null) $courseDeptFkCol = "dept_id";

$courseTeacherFkCol = null;
foreach (["teacher_id"] as $c) {
    if (colExists($conn, $courseTable, $c)) {
        $courseTeacherFkCol = $c;
        break;
    }
}

$coursePrereqCol = null;
foreach (["prerequisite_course_id", "prerequisite_id", "prereq_course_id"] as $c) {
    if (colExists($conn, $courseTable, $c)) {
        $coursePrereqCol = $c;
        break;
    }
}

$descCol = null;
foreach (["description", "course_description"] as $c) {
    if (colExists($conn, $courseTable, $c)) {
        $descCol = $c;
        break;
    }
}

/* ---------- DEPARTMENT columns ---------- */
$deptIdCol = null;
foreach (["dept_id", "department_id", "id"] as $c) {
    if (colExists($conn, $deptTable, $c)) {
        $deptIdCol = $c;
        break;
    }
}
if ($deptIdCol === null) $deptIdCol = "dept_id";

$deptNameCol = null;
foreach (["name", "department_name", "dept_name"] as $c) {
    if (colExists($conn, $deptTable, $c)) {
        $deptNameCol = $c;
        break;
    }
}
if ($deptNameCol === null) $deptNameCol = "name";

/* ---------- TEACHER columns ---------- */
$teacherIdCol = null;
foreach (["teacher_id", "id"] as $c) {
    if (colExists($conn, $teacherTable, $c)) {
        $teacherIdCol = $c;
        break;
    }
}
if ($teacherIdCol === null) $teacherIdCol = "teacher_id";

$teacherDeptFkCol = null;
foreach (["dept_id", "department_id"] as $c) {
    if (colExists($conn, $teacherTable, $c)) {
        $teacherDeptFkCol = $c;
        break;
    }
}

$teacherNameCol = null;
foreach (["name", "teacher_name", "full_name"] as $c) {
    if (colExists($conn, $teacherTable, $c)) {
        $teacherNameCol = $c;
        break;
    }
}
if ($teacherNameCol === null) $teacherNameCol = "name";

/* ---------- course id ---------- */
$course_id = (int)($_GET["course_id"] ?? ($_GET["id"] ?? 0));
if ($course_id <= 0) {
    header("Location: list.php");
    exit();
}

/* ---------- departments ---------- */
$departments = [];
$deptSql = "SELECT $deptIdCol AS dept_id, $deptNameCol AS dept_name FROM $deptTable ORDER BY $deptNameCol ASC";
$deptStmt = sqlsrv_query($conn, $deptSql);
if ($deptStmt) {
    while ($r = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $r;
    }
    sqlsrv_free_stmt($deptStmt);
}

/* ---------- teachers ---------- */
$teachers = [];
if ($courseTeacherFkCol !== null) {
    $teacherSql = "SELECT $teacherIdCol AS teacher_id, $teacherNameCol AS teacher_name"
        . ($teacherDeptFkCol !== null ? ", $teacherDeptFkCol AS dept_id" : "")
        . " FROM $teacherTable ORDER BY $teacherNameCol ASC";
    $teacherStmt = sqlsrv_query($conn, $teacherSql);
    if ($teacherStmt) {
        while ($tr = sqlsrv_fetch_array($teacherStmt, SQLSRV_FETCH_ASSOC)) {
            $teachers[] = $tr;
        }
        sqlsrv_free_stmt($teacherStmt);
    }
}

$prerequisiteCourses = [];
if ($coursePrereqCol !== null) {
    $prSql = "SELECT c.$courseIdCol AS course_id, "
        . ($courseCodeCol !== null ? "c.$courseCodeCol AS course_code, " : "CONVERT(VARCHAR(50), c.$courseIdCol) AS course_code, ")
        . "c.$courseNameCol AS course_name"
        . ($courseDeptFkCol !== null ? ", c.$courseDeptFkCol AS dept_id" : "")
        . " FROM $courseTable c WHERE c.$courseIdCol <> ? ORDER BY "
        . ($courseCodeCol !== null ? "c.$courseCodeCol ASC" : "c.$courseNameCol ASC");
    $prStmt = sqlsrv_query($conn, $prSql, [$course_id]);
    if ($prStmt) {
        while ($pr = sqlsrv_fetch_array($prStmt, SQLSRV_FETCH_ASSOC)) {
            $prerequisiteCourses[] = $pr;
        }
        sqlsrv_free_stmt($prStmt);
    }
}

/* ---------- load course ---------- */
$selectCols = [];
$selectCols[] = "$courseIdCol AS course_id";
if ($courseCodeCol !== null) $selectCols[] = "$courseCodeCol AS course_code";
$selectCols[] = "$courseNameCol AS course_name";
$selectCols[] = "$creditCol AS credit_hours";
$selectCols[] = "$courseDeptFkCol AS dept_id";
if ($courseTeacherFkCol !== null) $selectCols[] = "$courseTeacherFkCol AS teacher_id";
if ($coursePrereqCol !== null) $selectCols[] = "$coursePrereqCol AS prerequisite_course_id";
if ($descCol !== null) $selectCols[] = "$descCol AS description";

$courseSql = "SELECT " . implode(", ", $selectCols) . " FROM $courseTable WHERE $courseIdCol = ?";
$courseStmt = sqlsrv_query($conn, $courseSql, [$course_id]);
$row = $courseStmt ? sqlsrv_fetch_array($courseStmt, SQLSRV_FETCH_ASSOC) : null;
if ($courseStmt) sqlsrv_free_stmt($courseStmt);

if (!$row) {
    header("Location: list.php");
    exit();
}

$error = "";
$values = [
    "course_code"  => (string)($row["course_code"] ?? ""),
    "course_name"  => (string)($row["course_name"] ?? ""),
    "credit_hours" => (string)($row["credit_hours"] ?? ""),
    "dept_id"      => (string)($row["dept_id"] ?? ""),
    "teacher_id"   => (string)($row["teacher_id"] ?? ""),
    "prerequisite_course_id" => (string)($row["prerequisite_course_id"] ?? ""),
    "description"  => (string)($row["description"] ?? "")
];

$creditOptions = [1, 1.5, 2, 3, 4];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid request token.";
    } else {
        $values["course_code"] = trim($_POST["course_code"] ?? "");
        $values["course_name"] = trim($_POST["course_name"] ?? "");
        $values["credit_hours"] = trim($_POST["credit_hours"] ?? "");
        $values["dept_id"] = trim($_POST["dept_id"] ?? "");
        $values["teacher_id"] = trim($_POST["teacher_id"] ?? "");
        $values["prerequisite_course_id"] = trim($_POST["prerequisite_course_id"] ?? "");
        $values["description"] = trim($_POST["description"] ?? "");

        if ($values["course_name"] === "" || $values["credit_hours"] === "" || $values["dept_id"] === "") {
            $error = "Course name, credits, and department are required.";
        } elseif ($coursePrereqCol !== null && $values["prerequisite_course_id"] !== "" && (int)$values["prerequisite_course_id"] === $course_id) {
            $error = "A course cannot be its own prerequisite.";
        } elseif ($courseTeacherFkCol !== null && $teacherDeptFkCol !== null && $values["teacher_id"] !== "" && $values["dept_id"] !== "") {
            $teacherMatchesDept = false;
            foreach ($teachers as $teacher) {
                if ((string)($teacher["teacher_id"] ?? "") === $values["teacher_id"]) {
                    $teacherMatchesDept = ((string)($teacher["dept_id"] ?? "") === $values["dept_id"]);
                    break;
                }
            }
            if (!$teacherMatchesDept) {
                $error = "Please select a teacher from the chosen department.";
            }
        } elseif ($coursePrereqCol !== null && $values["prerequisite_course_id"] !== "") {
            $prerequisiteMatchesDept = true;
            if ($courseDeptFkCol !== null && $values["dept_id"] !== "") {
                $prerequisiteMatchesDept = false;
                foreach ($prerequisiteCourses as $pr) {
                    if ((string)($pr["course_id"] ?? "") === $values["prerequisite_course_id"]) {
                        $prerequisiteMatchesDept = ((string)($pr["dept_id"] ?? "") === $values["dept_id"]);
                        break;
                    }
                }
            }
            if (!$prerequisiteMatchesDept) {
                $error = "Please select a prerequisite course from the chosen department.";
            }
        } else {
            $sets = [];
            $params = [];

            $sets[] = "$courseNameCol = ?";
            $params[] = $values["course_name"];

            $sets[] = "$creditCol = ?";
            $params[] = (float)$values["credit_hours"];

            $sets[] = "$courseDeptFkCol = ?";
            $params[] = (int)$values["dept_id"];

            if ($courseCodeCol !== null) {
                $sets[] = "$courseCodeCol = ?";
                $params[] = ($values["course_code"] !== "" ? $values["course_code"] : null);
            }

            if ($courseTeacherFkCol !== null) {
                $sets[] = "$courseTeacherFkCol = ?";
                $params[] = ($values["teacher_id"] !== "" ? (int)$values["teacher_id"] : null);
            }

            if ($coursePrereqCol !== null) {
                $sets[] = "$coursePrereqCol = ?";
                $params[] = ($values["prerequisite_course_id"] !== "" ? (int)$values["prerequisite_course_id"] : null);
            }

            if ($descCol !== null) {
                $sets[] = "$descCol = ?";
                $params[] = ($values["description"] !== "" ? $values["description"] : null);
            }

            $params[] = $course_id;

            $updateSql = "UPDATE $courseTable SET " . implode(", ", $sets) . " WHERE $courseIdCol = ?";
            $up = sqlsrv_query($conn, $updateSql, $params);

            if ($up) {
                $courseLabel = trim((string)($values["course_name"] ?? ""));
                $courseCode = trim((string)($values["course_code"] ?? ""));
                if ($courseCode !== "" && $courseLabel !== "") {
                    $courseLabel = $courseCode . " - " . $courseLabel;
                }
                $courseMessage = $courseLabel !== ""
                    ? "Course '" . $courseLabel . "' was updated."
                    : "Course details were updated.";
                umsLogActivity($conn, "course_update", $courseMessage);
                umsSetFlash("courses", "success", "Course updated successfully.");
                header("Location: list.php");
                exit();
            }

            $error = umsFriendlyDbMessage("update", "course", sqlsrv_errors(SQLSRV_ERR_ERRORS));
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
  <title>Edit Course</title>
  <link rel="stylesheet" href="../assets/app.css">
  <style>
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
    .field textarea{
      min-height:110px;
      resize:vertical;
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
    @media (max-width:860px){
      .form-grid{
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
      <a class="back-link" href="list.php">← Back to Courses</a>

      <div class="header">
        <h1>Edit Course</h1>
      </div>

      <?php if ($error !== ""): ?>
        <div class="alert-err"><?php echo h($error); ?></div>
      <?php endif; ?>

      <div class="card form-card">
        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">

          <div class="form-grid">
            <?php if ($courseCodeCol !== null): ?>
              <div class="field">
                <label>Course Code</label>
                <input
                  name="course_code"
                  value="<?php echo h($values["course_code"]); ?>"
                  placeholder="e.g., CS401"
                />
              </div>
            <?php endif; ?>

            <div class="field">
              <label>Course Name *</label>
              <input
                name="course_name"
                value="<?php echo h($values["course_name"]); ?>"
                required
                placeholder="e.g., Database Management Systems"
              />
            </div>

            <div class="field">
              <label>Credits *</label>
              <select name="credit_hours" required>
                <option value="">Select Credits</option>
                <?php foreach ($creditOptions as $c): ?>
                  <option value="<?php echo h($c); ?>" <?php echo ((string)$values["credit_hours"] === (string)$c) ? "selected" : ""; ?>>
                    <?php echo h($c) . " Credits"; ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>Department *</label>
              <select name="dept_id" required>
                <option value="">Select Department</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?php echo (int)$d["dept_id"]; ?>" <?php echo ((string)$values["dept_id"] === (string)$d["dept_id"]) ? "selected" : ""; ?>>
                    <?php echo h($d["dept_name"]); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field full">
              <label>Assign Teacher</label>
              <?php if ($courseTeacherFkCol !== null && count($teachers) > 0): ?>
                <select id="teacher_id" name="teacher_id">
                  <option value="">Select Teacher</option>
                  <?php foreach ($teachers as $t): ?>
                    <option
                      value="<?php echo (int)$t["teacher_id"]; ?>"
                      data-dept-id="<?php echo h((string)($t["dept_id"] ?? "")); ?>"
                      <?php echo ((string)$values["teacher_id"] === (string)$t["teacher_id"]) ? "selected" : ""; ?>
                    >
                      <?php echo h($t["teacher_name"]); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input value="Not available" disabled />
                <input type="hidden" name="teacher_id" value="">
              <?php endif; ?>
            </div>

            <?php if ($coursePrereqCol !== null): ?>
              <div class="field full">
                <label>Prerequisite Course</label>
                <select id="prerequisite_course_id" name="prerequisite_course_id">
                  <option value="">No prerequisite</option>
                  <?php foreach ($prerequisiteCourses as $pr): ?>
                    <?php $prId = (int)($pr["course_id"] ?? 0); ?>
                    <option
                      value="<?php echo $prId; ?>"
                      data-dept-id="<?php echo h((string)($pr["dept_id"] ?? "")); ?>"
                      <?php echo ((string)$values["prerequisite_course_id"] === (string)$prId) ? "selected" : ""; ?>
                    >
                      <?php echo h(($pr["course_code"] ?? "") . " - " . ($pr["course_name"] ?? "")); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <?php if ($descCol !== null): ?>
              <div class="field full">
                <label>Course Description</label>
                <textarea name="description" placeholder="Course description..."><?php echo h($values["description"]); ?></textarea>
              </div>
            <?php endif; ?>
          </div>

          <div class="form-actions">
            <button class="btn btn-primary" type="submit">Update Course</button>
            <a class="btn" href="list.php">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
<script>
  (function () {
    var departmentEl = document.querySelector('select[name="dept_id"]');
    var teacherEl = document.getElementById("teacher_id");
    var prerequisiteEl = document.getElementById("prerequisite_course_id");

    function findOptionByValue(selectEl, value) {
      if (!selectEl || value === "") return null;
      for (var i = 0; i < selectEl.options.length; i++) {
        if (String(selectEl.options[i].value || "") === value) {
          return selectEl.options[i];
        }
      }
      return null;
    }

    function filterSelect(selectEl, emptyLabel, keepAllWhenNoDept) {
      if (!selectEl) return;

      var selectedDept = departmentEl ? String(departmentEl.value || "") : "";
      var currentValue = String(selectEl.value || "");
      var hasVisibleOption = false;

      Array.prototype.slice.call(selectEl.options).forEach(function (option, index) {
        if (index === 0) return;

        var optionDept = String(option.getAttribute("data-dept-id") || "");
        var shouldShow = selectedDept === ""
          ? !!keepAllWhenNoDept
          : optionDept === selectedDept;

        option.hidden = !shouldShow;
        option.disabled = !shouldShow;

        if (shouldShow) {
          hasVisibleOption = true;
        }
      });

      selectEl.disabled = selectedDept === "" ? !keepAllWhenNoDept : !hasVisibleOption;
      selectEl.options[0].text = emptyLabel;

      if (currentValue !== "") {
        var selectedOption = findOptionByValue(selectEl, currentValue);
        if (!selectedOption || selectedOption.hidden || selectedOption.disabled) {
          selectEl.value = "";
        }
      }
    }

    function refreshDependentFilters() {
      filterSelect(teacherEl, "Select Teacher", false);
      filterSelect(prerequisiteEl, "No prerequisite", true);
    }

    if (departmentEl) {
      departmentEl.addEventListener("change", refreshDependentFilters);
      refreshDependentFilters();
    }
  })();
</script>
</body>
</html>
