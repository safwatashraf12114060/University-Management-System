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
    $teacherSql = "SELECT $teacherIdCol AS teacher_id, $teacherNameCol AS teacher_name FROM $teacherTable ORDER BY $teacherNameCol ASC";
    $teacherStmt = sqlsrv_query($conn, $teacherSql);
    if ($teacherStmt) {
        while ($tr = sqlsrv_fetch_array($teacherStmt, SQLSRV_FETCH_ASSOC)) {
            $teachers[] = $tr;
        }
        sqlsrv_free_stmt($teacherStmt);
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
        $values["description"] = trim($_POST["description"] ?? "");

        if ($values["course_name"] === "" || $values["credit_hours"] === "" || $values["dept_id"] === "") {
            $error = "Course name, credits, and department are required.";
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

            if ($descCol !== null) {
                $sets[] = "$descCol = ?";
                $params[] = ($values["description"] !== "" ? $values["description"] : null);
            }

            $params[] = $course_id;

            $updateSql = "UPDATE $courseTable SET " . implode(", ", $sets) . " WHERE $courseIdCol = ?";
            $up = sqlsrv_query($conn, $updateSql, $params);

            if ($up) {
                header("Location: list.php");
                exit();
            }

            $errs = sqlsrv_errors();
            $error = "Update failed: " . ($errs ? $errs[0]["message"] : "Unknown SQL error");
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
                <select name="teacher_id">
                  <option value="">Select Teacher</option>
                  <?php foreach ($teachers as $t): ?>
                    <option value="<?php echo (int)$t["teacher_id"]; ?>" <?php echo ((string)$values["teacher_id"] === (string)$t["teacher_id"]) ? "selected" : ""; ?>>
                      <?php echo h($t["teacher_name"]); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input value="Not available" disabled />
                <input type="hidden" name="teacher_id" value="">
              <?php endif; ?>
            </div>

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
</body>
</html>