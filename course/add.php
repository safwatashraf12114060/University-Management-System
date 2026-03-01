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

if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$debug = ((int)($_GET["debug"] ?? 0) === 1);

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
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
foreach (["description", "details"] as $c) {
    if (colExists($conn, $courseTable, $c)) { $descCol = $c; break; }
}

/* ---------------------------
   Load dropdown data
---------------------------- */
$departments = [];
$dSql = "SELECT $deptIdCol AS dept_id, $deptNameCol AS dept_name FROM $deptTable ORDER BY $deptNameCol ASC";
$dSt = sqlsrv_query($conn, $dSql);
if ($dSt !== false) {
    while ($r = sqlsrv_fetch_array($dSt, SQLSRV_FETCH_ASSOC)) $departments[] = $r;
    sqlsrv_free_stmt($dSt);
}

$teachers = [];
$tSql = "SELECT $teacherIdCol AS teacher_id, $teacherNameCol AS teacher_name FROM $teacherTable ORDER BY $teacherNameCol ASC";
$tSt = sqlsrv_query($conn, $tSql);
if ($tSt !== false) {
    while ($r = sqlsrv_fetch_array($tSt, SQLSRV_FETCH_ASSOC)) $teachers[] = $r;
    sqlsrv_free_stmt($tSt);
}

/* ---------------------------
   Handle form submit
---------------------------- */
$error = "";

$values = [
    "course_code" => trim((string)($_POST["course_code"] ?? "")),
    "course_name" => trim((string)($_POST["course_name"] ?? "")),
    "credit_hours" => (int)($_POST["credit_hours"] ?? 0),
    "dept_id" => (int)($_POST["dept_id"] ?? 0),
    "teacher_id" => (int)($_POST["teacher_id"] ?? 0),
    "description" => trim((string)($_POST["description"] ?? "")),
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Invalid request token.";
    } else {
        if ($courseNameCol && $values["course_name"] === "") {
            $error = "Course name is required.";
        } elseif ($creditCol && $values["credit_hours"] <= 0) {
            $error = "Credits is required.";
        } elseif ($courseDeptFkCol && $values["dept_id"] <= 0) {
            $error = "Department is required.";
        } elseif ($courseTeacherFkCol && $values["teacher_id"] <= 0) {
            $error = "Teacher is required.";
        } elseif ($courseCodeCol && $values["course_code"] === "") {
            $error = "Course code is required.";
        } else {
            if ($courseCodeCol) {
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

                if ($courseCodeCol) { $cols[] = $courseCodeCol; $qs[] = "?"; $params[] = $values["course_code"]; }
                $cols[] = $courseNameCol; $qs[] = "?"; $params[] = $values["course_name"];

                if ($creditCol) { $cols[] = $creditCol; $qs[] = "?"; $params[] = $values["credit_hours"]; }
                if ($courseDeptFkCol) { $cols[] = $courseDeptFkCol; $qs[] = "?"; $params[] = $values["dept_id"]; }
                if ($courseTeacherFkCol) { $cols[] = $courseTeacherFkCol; $qs[] = "?"; $params[] = $values["teacher_id"]; }

                if ($descCol) {
                    $cols[] = $descCol;
                    $qs[] = "?";
                    $params[] = ($values["description"] !== "" ? $values["description"] : null);
                }

                $insSql = "INSERT INTO $courseTable (" . implode(",", $cols) . ") VALUES (" . implode(",", $qs) . ")";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Add Course</title>
  <style>
    :root{
      --bg:#f4f6fb;--card:#ffffff;--text:#0f172a;--muted:#64748b;
      --primary:#2f3cff;--border:#e5e7eb;--shadow2:0 8px 22px rgba(0,0,0,0.06);
      --radius:14px;--sidebar:#ffffff;
    }
    *{box-sizing:border-box;}
    body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);}
    .layout{display:flex;min-height:100vh;}
    .sidebar{width:260px;background:var(--sidebar);border-right:1px solid var(--border);padding:18px 14px;}
    .brand{font-weight:900;letter-spacing:.4px;font-size:18px;padding:10px 10px 16px;}
    .nav{display:flex;flex-direction:column;gap:6px;margin-top:6px;}
    .nav a{display:flex;align-items:center;gap:12px;padding:12px 12px;border-radius:12px;color:var(--text);text-decoration:none;font-weight:700;opacity:.92;}
    .nav a:hover{background:rgba(47,60,255,0.07);opacity:1;}
    .nav a.active{background:var(--primary);color:#fff;box-shadow:0 10px 18px rgba(47,60,255,0.18);}
    .nav svg{width:18px;height:18px;flex:0 0 auto;}
    .nav a.active svg path,.nav a.active svg rect,.nav a.active svg circle{stroke:#fff;}
    .content{flex:1;display:flex;flex-direction:column;min-width:0;}
    .topbar{height:64px;background:var(--card);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 18px;}
    .logout{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:800;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:#fff;}
    .page{padding:22px 22px 36px;max-width:1200px;width:100%;}
    .back{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:900;margin-bottom:12px;}
    h1{margin:6px 0 14px;font-size:34px;letter-spacing:-0.6px;}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow2);padding:18px;max-width:920px;}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    label{display:block;font-weight:900;font-size:13px;margin:0 0 6px;}
    input,select,textarea{width:100%;padding:12px;border:1px solid #d0d4e3;border-radius:10px;outline:none;background:#fff;font-weight:800;}
    textarea{min-height:120px;resize:vertical;font-weight:700;}
    input:focus,select:focus,textarea:focus{border-color:var(--primary);}
    .full{grid-column:1 / -1;}
    .actions{display:flex;gap:12px;margin-top:16px;}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 16px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--text);text-decoration:none;font-weight:900;cursor:pointer;min-width:160px;}
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
    .alert{margin-bottom:12px;padding:10px 12px;border-radius:10px;background:#ffe8e8;border:1px solid #ffb3b3;color:#8a0000;font-weight:800;font-size:14px;white-space:pre-wrap;}
    @media(max-width:860px){.sidebar{display:none;}.grid{grid-template-columns:1fr;}.btn{min-width:0;flex:1;}}
  </style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand">UMS</div>
    <nav class="nav">
      <a href="../index.php">
        <svg viewBox="0 0 24 24" fill="none">
          <rect x="3" y="3" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="14" y="3" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="3" y="14" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
          <rect x="14" y="14" width="7" height="7" rx="2" stroke="#0f172a" stroke-width="2"/>
        </svg>
        Dashboard
      </a>

      <a href="../student/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <circle cx="9" cy="7" r="4" stroke="#0f172a" stroke-width="2"/>
          <path d="M17 11c2.2 0 4 1.8 4 4v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M1 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Students
      </a>

      <a href="../teacher/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <circle cx="12" cy="7" r="4" stroke="#0f172a" stroke-width="2"/>
          <path d="M4 21v-2a8 8 0 0 1 16 0v2" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Teachers
      </a>

      <a href="../department/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M4 21V8l8-5 8 5v13" stroke="#0f172a" stroke-width="2" stroke-linejoin="round"/>
          <path d="M9 21v-6h6v6" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Departments
      </a>

      <a class="active" href="list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M4 4v15.5" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M20 22V6a2 2 0 0 0-2-2H6.5A2.5 2.5 0 0 0 4 6.5" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Courses
      </a>

      <a href="../enrollment/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M8 6h13" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M8 12h13" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M8 18h13" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M3 6h.01" stroke="#0f172a" stroke-width="3" stroke-linecap="round"/>
          <path d="M3 12h.01" stroke="#0f172a" stroke-width="3" stroke-linecap="round"/>
          <path d="M3 18h.01" stroke="#0f172a" stroke-width="3" stroke-linecap="round"/>
        </svg>
        Enrollments
      </a>

      <a href="../result/list.php">
        <svg viewBox="0 0 24 24" fill="none">
          <path d="M7 3h10a2 2 0 0 1 2 2v16l-2-1-2 1-2-1-2 1-2-1-2 1V5a2 2 0 0 1 2-2Z" stroke="#0f172a" stroke-width="2" stroke-linejoin="round"/>
          <path d="M9 8h6" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
          <path d="M9 12h6" stroke="#0f172a" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Results
      </a>
    </nav>
  </aside>

  <main class="content">
    <div class="topbar">
      <div style="font-weight:900;"><?php echo h($_SESSION["name"] ?? "User"); ?></div>
      <a class="logout" href="../logout.php">Logout</a>
    </div>

    <div class="page">
      <a class="back" href="list.php">← Back to Courses</a>
      <h1>Add Course</h1>

      <?php if ($error !== ""): ?>
        <div class="alert"><?php echo h($error); ?></div>
      <?php endif; ?>

      <div class="card">
        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">

          <div class="grid">
            <?php if ($courseCodeCol): ?>
            <div>
              <label for="course_code">Course Code *</label>
              <input id="course_code" name="course_code" value="<?php echo h($values["course_code"]); ?>" placeholder="e.g., CS401" required />
            </div>
            <?php else: ?>
            <div>
              <label>Course Code</label>
              <input value="Auto / Not in DB" disabled />
            </div>
            <?php endif; ?>

            <div>
              <label for="course_name">Course Name *</label>
              <input id="course_name" name="course_name" value="<?php echo h($values["course_name"]); ?>" placeholder="e.g., Database Management Systems" required />
            </div>

            <?php if ($creditCol): ?>
            <div>
              <label for="credit_hours">Credits *</label>
              <select id="credit_hours" name="credit_hours" required>
                <option value="">Select Credits</option>
                <?php foreach ([1,2,3,4,5] as $cr): ?>
                  <option value="<?php echo $cr; ?>" <?php echo ($values["credit_hours"] === $cr) ? "selected" : ""; ?>><?php echo $cr; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php else: ?>
            <div>
              <label>Credits</label>
              <input value="Not in DB" disabled />
            </div>
            <?php endif; ?>

            <?php if ($courseDeptFkCol): ?>
            <div>
              <label for="dept_id">Department *</label>
              <select id="dept_id" name="dept_id" required>
                <option value="">Select Department</option>
                <?php foreach ($departments as $d): ?>
                  <?php $did = (int)($d["dept_id"] ?? 0); ?>
                  <option value="<?php echo $did; ?>" <?php echo ($values["dept_id"] === $did) ? "selected" : ""; ?>>
                    <?php echo h($d["dept_name"] ?? ""); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php else: ?>
            <div>
              <label>Department</label>
              <input value="Not required (no FK column)" disabled />
            </div>
            <?php endif; ?>

            <?php if ($courseTeacherFkCol): ?>
            <div class="full">
              <label for="teacher_id">Assign Teacher *</label>
              <select id="teacher_id" name="teacher_id" required>
                <option value="">Select Teacher</option>
                <?php foreach ($teachers as $t): ?>
                  <?php $tid = (int)($t["teacher_id"] ?? 0); ?>
                  <option value="<?php echo $tid; ?>" <?php echo ($values["teacher_id"] === $tid) ? "selected" : ""; ?>>
                    <?php echo h($t["teacher_name"] ?? ""); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php else: ?>
            <div class="full">
              <label>Assign Teacher</label>
              <input value="Not required (no FK column)" disabled />
            </div>
            <?php endif; ?>

            <?php if ($descCol): ?>
            <div class="full">
              <label for="description">Course Description</label>
              <textarea id="description" name="description" placeholder="Enter course description (optional)"><?php echo h($values["description"]); ?></textarea>
            </div>
            <?php else: ?>
            <div class="full">
              <label>Description</label>
              <input value="Not in DB" disabled />
            </div>
            <?php endif; ?>
          </div>

          <div class="actions">
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