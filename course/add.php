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

$debug = ((int)($_GET["debug"] ?? 0) === 1);

function h($v) {
    return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8");
}

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return isset($row["len"]) && $row["len"] !== null;
}

function sqlErrText() {
    $errs = sqlsrv_errors();
    if (!$errs) return "Unknown SQL error";
    $lines = [];
    foreach ($errs as $e) {
        $lines[] = ($e["SQLSTATE"] ?? "") . " | " . ($e["code"] ?? "") . " | " . ($e["message"] ?? "");
    }
    return implode("\n", $lines);
}

function addSqlsrvError($baseMsg, $debug) {
    if (!$debug) return $baseMsg;
    return $baseMsg . "\n" . sqlErrText();
}

/* ---------------------------
   Detect table/column names
---------------------------- */
$deptTable = "dbo.DEPARTMENT";
$teacherTable = "dbo.TEACHER";
$courseTable = "dbo.COURSE";

/* Department id + name columns */
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

/* Teacher id + name columns */
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

/* Course columns */
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

$descCol = null;
foreach (["description", "details", "course_description"] as $c) {
    if (colExists($conn, $courseTable, $c)) { $descCol = $c; break; }
}

/* ---------------------------
   Load dropdown data
---------------------------- */
$departments = [];
$dSql = "SELECT $deptIdCol AS dept_id, $deptNameCol AS dept_name FROM $deptTable ORDER BY $deptNameCol ASC";
$dSt = sqlsrv_query($conn, $dSql);
if ($dSt !== false) {
    while ($r = sqlsrv_fetch_array($dSt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $r;
    }
    sqlsrv_free_stmt($dSt);
}

$teachers = [];
$tSql = "SELECT $teacherIdCol AS teacher_id, $teacherNameCol AS teacher_name FROM $teacherTable ORDER BY $teacherNameCol ASC";
$tSt = sqlsrv_query($conn, $tSql);
if ($tSt !== false) {
    while ($r = sqlsrv_fetch_array($tSt, SQLSRV_FETCH_ASSOC)) {
        $teachers[] = $r;
    }
    sqlsrv_free_stmt($tSt);
}

/* ---------------------------
   Handle form submit
---------------------------- */
$error = "";

$values = [
    "course_code"  => trim((string)($_POST["course_code"] ?? "")),
    "course_name"  => trim((string)($_POST["course_name"] ?? "")),
    "credit_hours" => trim((string)($_POST["credit_hours"] ?? "")),
    "dept_id"      => trim((string)($_POST["dept_id"] ?? "")),
    "teacher_id"   => trim((string)($_POST["teacher_id"] ?? "")),
    "description"  => trim((string)($_POST["description"] ?? "")),
];

$creditOptions = [1, 1.5, 2, 3, 4, 5];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid request token.";
    } else {
        if ($courseCodeCol !== null && $values["course_code"] === "") {
            $error = "Course code is required.";
        } elseif ($values["course_name"] === "") {
            $error = "Course name is required.";
        } elseif ($creditCol !== null && $values["credit_hours"] === "") {
            $error = "Credits is required.";
        } elseif ($courseDeptFkCol !== null && $values["dept_id"] === "") {
            $error = "Department is required.";
        } elseif ($courseTeacherFkCol !== null && $values["teacher_id"] === "") {
            $error = "Teacher is required.";
        } else {
            if ($courseCodeCol !== null) {
                $dupSql = "SELECT TOP 1 1 AS ok FROM $courseTable WHERE $courseCodeCol = ?";
                $dupSt = sqlsrv_query($conn, $dupSql, [$values["course_code"]]);

                if ($dupSt === false) {
                    $error = addSqlsrvError("Query failed.", $debug);
                } else {
                    $dupRow = sqlsrv_fetch_array($dupSt, SQLSRV_FETCH_ASSOC);
                    sqlsrv_free_stmt($dupSt);
                    if ($dupRow) {
                        $error = "Course code already exists.";
                    }
                }
            }

            if ($error === "") {
                $cols = [];
                $qs = [];
                $params = [];

                if ($courseCodeCol !== null) {
                    $cols[] = $courseCodeCol;
                    $qs[] = "?";
                    $params[] = $values["course_code"];
                }

                $cols[] = $courseNameCol;
                $qs[] = "?";
                $params[] = $values["course_name"];

                if ($creditCol !== null) {
                    $cols[] = $creditCol;
                    $qs[] = "?";
                    $params[] = (float)$values["credit_hours"];
                }

                if ($courseDeptFkCol !== null) {
                    $cols[] = $courseDeptFkCol;
                    $qs[] = "?";
                    $params[] = (int)$values["dept_id"];
                }

                if ($courseTeacherFkCol !== null) {
                    $cols[] = $courseTeacherFkCol;
                    $qs[] = "?";
                    $params[] = (int)$values["teacher_id"];
                }

                if ($descCol !== null) {
                    $cols[] = $descCol;
                    $qs[] = "?";
                    $params[] = ($values["description"] !== "" ? $values["description"] : null);
                }

                $insSql = "INSERT INTO $courseTable (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $qs) . ")";
                $insSt = sqlsrv_query($conn, $insSql, $params);

                if ($insSt === false) {
                    $error = addSqlsrvError("Insert failed.", $debug);
                } else {
                    sqlsrv_free_stmt($insSt);
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
  <title>Add Course</title>
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
        <h1>Add Course</h1>
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
                <label for="course_code">Course Code *</label>
                <input
                  id="course_code"
                  name="course_code"
                  value="<?php echo h($values["course_code"]); ?>"
                  placeholder="e.g., CS401"
                  required
                />
              </div>
            <?php endif; ?>

            <div class="field">
              <label for="course_name">Course Name *</label>
              <input
                id="course_name"
                name="course_name"
                value="<?php echo h($values["course_name"]); ?>"
                placeholder="e.g., Database Management Systems"
                required
              />
            </div>

            <?php if ($creditCol !== null): ?>
              <div class="field">
                <label for="credit_hours">Credits *</label>
                <select id="credit_hours" name="credit_hours" required>
                  <option value="">Select Credits</option>
                  <?php foreach ($creditOptions as $cr): ?>
                    <option value="<?php echo h($cr); ?>" <?php echo ((string)$values["credit_hours"] === (string)$cr) ? "selected" : ""; ?>>
                      <?php echo h($cr); ?> Credits
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <?php if ($courseDeptFkCol !== null): ?>
              <div class="field">
                <label for="dept_id">Department *</label>
                <select id="dept_id" name="dept_id" required>
                  <option value="">Select Department</option>
                  <?php foreach ($departments as $d): ?>
                    <?php $did = (int)($d["dept_id"] ?? 0); ?>
                    <option value="<?php echo $did; ?>" <?php echo ((string)$values["dept_id"] === (string)$did) ? "selected" : ""; ?>>
                      <?php echo h($d["dept_name"] ?? ""); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <?php if ($courseTeacherFkCol !== null): ?>
              <div class="field full">
                <label for="teacher_id">Assign Teacher *</label>
                <select id="teacher_id" name="teacher_id" required>
                  <option value="">Select Teacher</option>
                  <?php foreach ($teachers as $t): ?>
                    <?php $tid = (int)($t["teacher_id"] ?? 0); ?>
                    <option value="<?php echo $tid; ?>" <?php echo ((string)$values["teacher_id"] === (string)$tid) ? "selected" : ""; ?>>
                      <?php echo h($t["teacher_name"] ?? ""); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <?php if ($descCol !== null): ?>
              <div class="field full">
                <label for="description">Course Description</label>
                <textarea
                  id="description"
                  name="description"
                  placeholder="Enter course description (optional)"
                ><?php echo h($values["description"]); ?></textarea>
              </div>
            <?php endif; ?>
          </div>

          <div class="form-actions">
            <button class="btn btn-primary" type="submit">Add Course</button>
            <a class="btn" href="list.php">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
</body>
</html>