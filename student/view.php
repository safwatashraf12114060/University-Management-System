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

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return isset($row["len"]) && $row["len"] !== null;
}

$student_id = (int)($_GET["student_id"] ?? 0);
if ($student_id <= 0) { header("Location: list.php"); exit(); }

$studentTable = "STUDENT";
$deptTable = "DEPARTMENT";

$hasStudentName = colExists($conn, "dbo.$studentTable", "student_name") || colExists($conn, $studentTable, "student_name");
$hasName = colExists($conn, "dbo.$studentTable", "name") || colExists($conn, $studentTable, "name");
$nameCol = $hasStudentName ? "student_name" : ($hasName ? "name" : "student_name");

$hasEmail = colExists($conn, "dbo.$studentTable", "email") || colExists($conn, $studentTable, "email");
$hasPhone = colExists($conn, "dbo.$studentTable", "phone") || colExists($conn, $studentTable, "phone");
$hasAddress = colExists($conn, "dbo.$studentTable", "address") || colExists($conn, $studentTable, "address");
$hasSemester = colExists($conn, "dbo.$studentTable", "semester") || colExists($conn, $studentTable, "semester");

$hasDeptId = colExists($conn, "dbo.$studentTable", "dept_id") || colExists($conn, $studentTable, "dept_id");
$hasDepartmentId = colExists($conn, "dbo.$studentTable", "department_id") || colExists($conn, $studentTable, "department_id");
$deptFkCol = $hasDeptId ? "dept_id" : ($hasDepartmentId ? "department_id" : "dept_id");

$cols = "s.student_id, s.$nameCol AS student_name, d.name AS dept_name";
if ($hasEmail) $cols .= ", s.email";
if ($hasPhone) $cols .= ", s.phone";
if ($hasAddress) $cols .= ", s.address";
if ($hasSemester) $cols .= ", s.semester";

$sql = "
  SELECT $cols
  FROM $studentTable s
  LEFT JOIN $deptTable d ON d.dept_id = s.$deptFkCol
  WHERE s.student_id = ?
";
$stmt = sqlsrv_query($conn, $sql, [$student_id]);
$row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
if (!$row) { header("Location: list.php"); exit(); }

function v($x){ return htmlspecialchars((string)($x ?? "")); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Student Details</title>
  <style>
    :root{--bg:#f4f6fb;--card:#ffffff;--text:#0f172a;--muted:#64748b;--primary:#2f3cff;--border:#e5e7eb;--shadow2:0 8px 22px rgba(0,0,0,0.06);--radius:14px;}
    *{box-sizing:border-box;}
    body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);}
    .wrap{max-width:980px;margin:22px auto;padding:0 18px;}
    .top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
    a.back{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:900;}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 16px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--text);text-decoration:none;font-weight:900;}
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
    h1{margin:10px 0 14px;font-size:34px;letter-spacing:-0.6px;}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow2);padding:18px;}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    .item{border:1px solid #eef0f6;border-radius:12px;padding:12px;}
    .k{color:var(--muted);font-weight:900;font-size:12px;margin-bottom:6px;}
    .val{font-weight:900;}
    @media(max-width:760px){.grid{grid-template-columns:1fr;}}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <a class="back" href="list.php">← Back to Students</a>
      <div style="display:flex;gap:10px;">
        <a class="btn" href="edit.php?student_id=<?php echo (int)$row["student_id"]; ?>">Edit</a>
        <a class="btn btn-primary" href="list.php">Done</a>
      </div>
    </div>

    <h1>Student Details</h1>

    <div class="card">
      <div class="grid">
        <div class="item"><div class="k">Student ID</div><div class="val"><?php echo v("S".str_pad((string)$row["student_id"],7,"0",STR_PAD_LEFT)); ?></div></div>
        <div class="item"><div class="k">Name</div><div class="val"><?php echo v($row["student_name"]); ?></div></div>
        <div class="item"><div class="k">Email</div><div class="val"><?php echo v($row["email"] ?? "-"); ?></div></div>
        <div class="item"><div class="k">Phone</div><div class="val"><?php echo v($row["phone"] ?? "-"); ?></div></div>
        <div class="item"><div class="k">Department</div><div class="val"><?php echo v($row["dept_name"] ?? "-"); ?></div></div>
        <div class="item"><div class="k">Semester</div><div class="val"><?php echo v($row["semester"] ?? "-"); ?></div></div>
        <div class="item" style="grid-column:1/-1;"><div class="k">Address</div><div class="val"><?php echo v($row["address"] ?? "-"); ?></div></div>
      </div>
    </div>
  </div>
</body>
</html>