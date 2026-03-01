<?php
session_start();
require_once __DIR__ . "/../db.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
}

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return isset($row["len"]) && $row["len"] !== null;
}

/* Tables (keep consistent with list.php using dbo.) */
$courseTable  = "dbo.COURSE";
$deptTable    = "dbo.DEPARTMENT";
$teacherTable = "dbo.TEACHER";

/* Accept both course_id and id */
$course_id = (int)($_GET["course_id"] ?? ($_GET["id"] ?? 0));
if ($course_id <= 0) {
    header("Location: list.php");
    exit();
}

/* Detect columns */
$hasCourseCode = colExists($conn, $courseTable, "course_code");
$hasDesc = colExists($conn, $courseTable, "description");
$hasTeacherId = colExists($conn, $courseTable, "teacher_id");

$hasTitle = colExists($conn, $courseTable, "title");
$titleCol = $hasTitle ? "title" : (colExists($conn, $courseTable, "course_name") ? "course_name" : "name");

/* Department dropdown columns */
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

/* Teacher dropdown columns */
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

/* Load departments */
$departments = [];
$ds = sqlsrv_query($conn, "SELECT $deptIdCol AS dept_id, $deptNameCol AS dept_name FROM $deptTable ORDER BY $deptNameCol ASC");
if ($ds) {
    while ($r = sqlsrv_fetch_array($ds, SQLSRV_FETCH_ASSOC)) $departments[] = $r;
    sqlsrv_free_stmt($ds);
}

/* Check if teacher table exists properly */
$teachers = [];
$teacherTableExists = false;
$chk = sqlsrv_query($conn, "SELECT 1 AS ok FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='TEACHER'");
if ($chk) {
    $teacherTableExists = (bool)sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($chk);
}

if ($teacherTableExists && $hasTeacherId) {
    $tq = sqlsrv_query($conn, "SELECT $teacherIdCol AS teacher_id, $teacherNameCol AS teacher_name FROM $teacherTable ORDER BY $teacherNameCol ASC");
    if ($tq) {
        while ($tr = sqlsrv_fetch_array($tq, SQLSRV_FETCH_ASSOC)) $teachers[] = $tr;
        sqlsrv_free_stmt($tq);
    }
}

/* Load course row */
$cols = "course_id, $titleCol AS course_name, dept_id, credit_hours";
if ($hasCourseCode) $cols .= ", course_code";
if ($hasTeacherId) $cols .= ", teacher_id";
if ($hasDesc) $cols .= ", description";

$stmt = sqlsrv_query($conn, "SELECT $cols FROM $courseTable WHERE course_id = ?", [$course_id]);
$row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
if ($stmt) sqlsrv_free_stmt($stmt);

if (!$row) {
    header("Location: list.php");
    exit();
}

$error = "";
$values = [
    "course_code" => $hasCourseCode ? (string)($row["course_code"] ?? "") : "",
    "course_name" => (string)($row["course_name"] ?? ""),
    "credit_hours" => (string)($row["credit_hours"] ?? ""),
    "dept_id" => (string)($row["dept_id"] ?? ""),
    "teacher_id" => $hasTeacherId ? (string)($row["teacher_id"] ?? "") : "",
    "description" => $hasDesc ? (string)($row["description"] ?? "") : "",
];

$creditOptions = [1, 1.5, 2, 3, 4];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $values["course_code"] = trim($_POST["course_code"] ?? "");
    $values["course_name"] = trim($_POST["course_name"] ?? "");
    $values["credit_hours"] = trim($_POST["credit_hours"] ?? "");
    $values["dept_id"] = trim($_POST["dept_id"] ?? "");
    $values["teacher_id"] = trim($_POST["teacher_id"] ?? "");
    $values["description"] = trim($_POST["description"] ?? "");

    if ($values["course_name"] === "" || $values["credit_hours"] === "" || $values["dept_id"] === "") {
        $error = "Course name, credits, and department are required.";
    } else {
        $sets = ["$titleCol = ?", "credit_hours = ?", "dept_id = ?"];
        $params = [$values["course_name"], (float)$values["credit_hours"], (int)$values["dept_id"]];

        if ($hasCourseCode) { $sets[] = "course_code = ?"; $params[] = ($values["course_code"] !== "" ? $values["course_code"] : null); }
        if ($hasTeacherId) { $sets[] = "teacher_id = ?"; $params[] = ($values["teacher_id"] !== "" ? (int)$values["teacher_id"] : null); }
        if ($hasDesc) { $sets[] = "description = ?"; $params[] = ($values["description"] !== "" ? $values["description"] : null); }

        $params[] = $course_id;

        $sql = "UPDATE $courseTable SET " . implode(", ", $sets) . " WHERE course_id = ?";
        $up = sqlsrv_query($conn, $sql, $params);

        if ($up) {
            header("Location: list.php");
            exit();
        }
        $error = "Update failed.";
    }
}

$displayName = $_SESSION["name"] ?? "User";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Edit Course</title>
  <style>
    :root{--bg:#f4f6fb;--card:#ffffff;--text:#0f172a;--muted:#64748b;--primary:#2f3cff;--border:#e5e7eb;--shadow2:0 8px 22px rgba(0,0,0,0.06);--radius:14px;--sidebar:#ffffff;--danger:#ef4444;}
    *{box-sizing:border-box;}
    body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);}
    a{color:inherit;text-decoration:none;}
    .layout{display:flex;min-height:100vh;}
    .sidebar{width:260px;background:var(--sidebar);border-right:1px solid var(--border);padding:18px 14px;}
    .brand{font-weight:900;letter-spacing:.4px;font-size:18px;padding:10px 10px 16px;}
    .nav{display:flex;flex-direction:column;gap:6px;margin-top:6px;}
    .nav a{display:flex;align-items:center;gap:12px;padding:12px 12px;border-radius:12px;color:var(--text);text-decoration:none;font-weight:700;opacity:.92;}
    .nav a:hover{background:rgba(47,60,255,0.07);opacity:1;}
    .nav a.active{background:var(--primary);color:#fff;box-shadow:0 10px 18px rgba(47,60,255,0.18);}
    .content{flex:1;display:flex;flex-direction:column;min-width:0;}
    .topbar{height:64px;background:var(--card);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 18px;}
    .logout{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:800;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:#fff;}
    .page{padding:22px 22px 36px;max-width:1200px;width:100%;}
    .back{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:900;margin-bottom:12px;}
    h1{margin:6px 0 14px;font-size:40px;letter-spacing:-0.9px;}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow2);padding:18px;max-width:920px;}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 18px;}
    label{display:block;font-weight:900;font-size:13px;margin:0 0 8px;color:#111827;}
    input,select,textarea{width:100%;padding:12px 12px;border:1px solid #d0d4e3;border-radius:10px;outline:none;background:#fff;font-weight:800;font-size:14px;}
    textarea{min-height:120px;resize:vertical;}
    input:focus,select:focus,textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(47,60,255,0.10);}
    .full{grid-column:1 / -1;}
    .actions{display:flex;gap:12px;margin-top:18px;}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--text);text-decoration:none;font-weight:900;cursor:pointer;min-width:160px;}
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
    .btn-primary:hover{filter:brightness(0.98);}
    .btn:hover{transform:translateY(-1px);}
    .alert{margin-bottom:12px;padding:12px 14px;border-radius:12px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:900;font-size:14px;white-space:pre-wrap;}
    @media(max-width:980px){.sidebar{display:none;}.grid{grid-template-columns:1fr;}h1{font-size:34px;}.btn{min-width:140px;}}
  </style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand">UMS</div>
    <nav class="nav">
      <a href="../index.php">Dashboard</a>
      <a href="../student/list.php">Students</a>
      <a href="../teacher/list.php">Teachers</a>
      <a href="../department/list.php">Departments</a>
      <a class="active" href="list.php">Courses</a>
      <a href="../enrollment/list.php">Enrollments</a>
      <a href="../result/list.php">Results</a>
    </nav>
  </aside>

  <main class="content">
    <div class="topbar">
      <div style="font-weight:900;"><?php echo h($displayName); ?></div>
      <a class="logout" href="../logout.php">Logout</a>
    </div>

    <div class="page">
      <a class="back" href="list.php">← Back to Courses</a>
      <h1>Edit Course</h1>

      <div class="card">
        <?php if ($error !== ""): ?>
          <div class="alert"><?php echo h($error); ?></div>
        <?php endif; ?>

        <form method="post" action="">
          <div class="grid">
            <div>
              <label>Course Code <?php echo $hasCourseCode ? "*" : ""; ?></label>
              <input name="course_code" value="<?php echo h($values["course_code"]); ?>" <?php echo $hasCourseCode ? "required" : ""; ?> placeholder="e.g., CS401" />
            </div>

            <div>
              <label>Course Name *</label>
              <input name="course_name" value="<?php echo h($values["course_name"]); ?>" required placeholder="e.g., Database Management Systems" />
            </div>

            <div>
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

            <div>
              <label>Department *</label>
              <select name="dept_id" required>
                <option value="">Select Department</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?php echo (int)$d["dept_id"]; ?>" <?php echo ((int)$values["dept_id"] === (int)$d["dept_id"]) ? "selected" : ""; ?>>
                    <?php echo h($d["dept_name"]); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="full">
              <label>Assign Teacher <?php echo ($hasTeacherId ? "*" : ""); ?></label>
              <?php if ($hasTeacherId && count($teachers) > 0): ?>
                <select name="teacher_id" <?php echo $hasTeacherId ? "required" : ""; ?>>
                  <option value="">Select Teacher</option>
                  <?php foreach ($teachers as $t): ?>
                    <option value="<?php echo (int)$t["teacher_id"]; ?>" <?php echo ((string)$values["teacher_id"] === (string)$t["teacher_id"]) ? "selected" : ""; ?>>
                      <?php echo h($t["teacher_name"]); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input value="Not required (no FK column)" disabled />
                <input type="hidden" name="teacher_id" value="">
              <?php endif; ?>
            </div>

            <div class="full">
              <label>Course Description</label>
              <?php if ($hasDesc): ?>
                <textarea name="description" placeholder="Course description..."><?php echo h($values["description"]); ?></textarea>
              <?php else: ?>
                <textarea disabled>Not in DB</textarea>
              <?php endif; ?>
            </div>
          </div>

          <div class="actions">
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