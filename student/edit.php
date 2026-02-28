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

$hasStudentCode = colExists($conn, "dbo.$studentTable", "student_code") || colExists($conn, $studentTable, "student_code");
$hasRegistrationNo = colExists($conn, "dbo.$studentTable", "registration_no") || colExists($conn, $studentTable, "registration_no");
$hasDob = colExists($conn, "dbo.$studentTable", "dob") || colExists($conn, $studentTable, "dob") || colExists($conn, "dbo.$studentTable", "date_of_birth") || colExists($conn, $studentTable, "date_of_birth");
$dobCol = colExists($conn, "dbo.$studentTable", "dob") || colExists($conn, $studentTable, "dob") ? "dob" : "date_of_birth";
$hasGender = colExists($conn, "dbo.$studentTable", "gender") || colExists($conn, $studentTable, "gender");

$student_id = (int)($_GET["student_id"] ?? 0);
if ($student_id <= 0) { header("Location: list.php"); exit(); }

$departments = [];
$ds = sqlsrv_query($conn, "SELECT dept_id, name FROM $deptTable ORDER BY name ASC");
if ($ds) while ($r = sqlsrv_fetch_array($ds, SQLSRV_FETCH_ASSOC)) $departments[] = $r;

$semesterOptions = ["1st","2nd","3rd","4th","5th","6th","7th","8th"];

$cols = "student_id, $nameCol AS student_name, $deptFkCol AS dept_id";
if ($hasEmail) $cols .= ", email";
if ($hasPhone) $cols .= ", phone";
if ($hasAddress) $cols .= ", address";
if ($hasSemester) $cols .= ", semester";
if ($hasStudentCode) $cols .= ", student_code";
if ($hasRegistrationNo) $cols .= ", registration_no";
if ($hasDob) $cols .= ", $dobCol AS dob";
if ($hasGender) $cols .= ", gender";

$stmt = sqlsrv_query($conn, "SELECT $cols FROM $studentTable WHERE student_id = ?", [$student_id]);
$row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
if (!$row) { header("Location: list.php"); exit(); }

function dateValue($d) {
    if ($d instanceof DateTime) return $d->format("Y-m-d");
    if (is_string($d)) return $d;
    return "";
}

$error = "";
$values = [
  "student_code" => $hasStudentCode ? (string)($row["student_code"] ?? "") : "",
  "registration_no" => $hasRegistrationNo ? (string)($row["registration_no"] ?? "") : "",
  "name" => (string)($row["student_name"] ?? ""),
  "email" => $hasEmail ? (string)($row["email"] ?? "") : "",
  "phone" => $hasPhone ? (string)($row["phone"] ?? "") : "",
  "dob" => $hasDob ? dateValue($row["dob"] ?? "") : "",
  "gender" => $hasGender ? (string)($row["gender"] ?? "") : "",
  "address" => $hasAddress ? (string)($row["address"] ?? "") : "",
  "dept_id" => (string)($row["dept_id"] ?? ""),
  "semester" => $hasSemester ? (string)($row["semester"] ?? "") : ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $values["student_code"] = trim($_POST["student_code"] ?? "");
    $values["registration_no"] = trim($_POST["registration_no"] ?? "");
    $values["name"] = trim($_POST["name"] ?? "");
    $values["email"] = trim($_POST["email"] ?? "");
    $values["phone"] = trim($_POST["phone"] ?? "");
    $values["dob"] = trim($_POST["dob"] ?? "");
    $values["gender"] = trim($_POST["gender"] ?? "");
    $values["address"] = trim($_POST["address"] ?? "");
    $values["dept_id"] = trim($_POST["dept_id"] ?? "");
    $values["semester"] = trim($_POST["semester"] ?? "");

    if ($values["name"] === "" || $values["dept_id"] === "") {
        $error = "Name and department are required.";
    } else {
        $sets = ["$nameCol = ?", "$deptFkCol = ?"];
        $params = [$values["name"], (int)$values["dept_id"]];

        if ($hasStudentCode) { $sets[]="student_code = ?"; $params[] = ($values["student_code"] !== "" ? $values["student_code"] : null); }
        if ($hasRegistrationNo) { $sets[]="registration_no = ?"; $params[] = ($values["registration_no"] !== "" ? $values["registration_no"] : null); }
        if ($hasEmail) { $sets[]="email = ?"; $params[] = ($values["email"] !== "" ? $values["email"] : null); }
        if ($hasPhone) { $sets[]="phone = ?"; $params[] = ($values["phone"] !== "" ? $values["phone"] : null); }
        if ($hasAddress) { $sets[]="address = ?"; $params[] = ($values["address"] !== "" ? $values["address"] : null); }
        if ($hasSemester) { $sets[]="semester = ?"; $params[] = ($values["semester"] !== "" ? $values["semester"] : null); }
        if ($hasDob) { $sets[]="$dobCol = ?"; $params[] = ($values["dob"] !== "" ? $values["dob"] : null); }
        if ($hasGender) { $sets[]="gender = ?"; $params[] = ($values["gender"] !== "" ? $values["gender"] : null); }

        $params[] = $student_id;

        $sql = "UPDATE $studentTable SET " . implode(", ", $sets) . " WHERE student_id = ?";
        $up = sqlsrv_query($conn, $sql, $params);

        if ($up) { header("Location: list.php"); exit(); }
        $error = "Update failed.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Edit Student</title>
  <style>
    :root{--bg:#f4f6fb;--card:#ffffff;--text:#0f172a;--muted:#64748b;--primary:#2f3cff;--border:#e5e7eb;--shadow2:0 8px 22px rgba(0,0,0,0.06);--radius:14px;--sidebar:#ffffff;}
    *{box-sizing:border-box;}
    body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);}
    .layout{display:flex;min-height:100vh;}
    .sidebar{width:260px;background:var(--sidebar);border-right:1px solid var(--border);padding:18px 14px;}
    .brand{font-weight:900;letter-spacing:.4px;font-size:18px;padding:10px 10px 16px;}
    .content{flex:1;display:flex;flex-direction:column;min-width:0;}
    .topbar{height:64px;background:var(--card);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 18px;}
    .logout{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:800;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:#fff;}
    .page{padding:22px 22px 36px;max-width:1200px;width:100%;}
    .back{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:900;margin-bottom:12px;}
    h1{margin:6px 0 14px;font-size:34px;letter-spacing:-0.6px;}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow2);padding:18px;max-width:860px;}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    label{display:block;font-weight:900;font-size:13px;margin:0 0 6px;}
    input,select,textarea{width:100%;padding:12px;border:1px solid #d0d4e3;border-radius:10px;outline:none;background:#fff;}
    textarea{min-height:110px;resize:vertical;}
    input:focus,select:focus,textarea:focus{border-color:var(--primary);}
    .actions{display:flex;gap:12px;margin-top:16px;}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 16px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--text);text-decoration:none;font-weight:900;cursor:pointer;}
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
    .alert{margin-bottom:12px;padding:10px 12px;border-radius:10px;background:#ffe8e8;border:1px solid #ffb3b3;color:#8a0000;font-weight:800;font-size:14px;}
    @media(max-width:860px){.sidebar{display:none;}.grid{grid-template-columns:1fr;}}
  </style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="brand">UMS</div>
  </aside>

  <main class="content">
    <div class="topbar">
      <div style="font-weight:900;"><?php echo htmlspecialchars($_SESSION["name"] ?? "User"); ?></div>
      <a class="logout" href="../logout.php">Logout</a>
    </div>

    <div class="page">
      <a class="back" href="list.php">← Back to Students</a>
      <h1>Edit Student</h1>

      <div class="card">
        <?php if ($error !== ""): ?>
          <div class="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" action="">
          <div class="grid">
            <div>
              <label>Student ID</label>
              <?php if ($hasStudentCode): ?>
                <input name="student_code" value="<?php echo htmlspecialchars($values["student_code"]); ?>" />
              <?php elseif ($hasRegistrationNo): ?>
                <input name="registration_no" value="<?php echo htmlspecialchars($values["registration_no"]); ?>" />
              <?php else: ?>
                <input value="<?php echo htmlspecialchars("S" . str_pad((string)$student_id, 7, "0", STR_PAD_LEFT)); ?>" disabled />
              <?php endif; ?>
            </div>

            <div>
              <label>Full Name *</label>
              <input name="name" value="<?php echo htmlspecialchars($values["name"]); ?>" required />
            </div>

            <div>
              <label>Email<?php echo $hasEmail ? " *" : ""; ?></label>
              <input name="email" value="<?php echo htmlspecialchars($values["email"]); ?>" <?php echo $hasEmail ? "required" : ""; ?> />
            </div>

            <div>
              <label>Phone Number<?php echo $hasPhone ? " *" : ""; ?></label>
              <input name="phone" value="<?php echo htmlspecialchars($values["phone"]); ?>" <?php echo $hasPhone ? "required" : ""; ?> />
            </div>

            <div>
              <label>Date of Birth<?php echo $hasDob ? " *" : ""; ?></label>
              <input type="date" name="dob" value="<?php echo htmlspecialchars($values["dob"]); ?>" <?php echo $hasDob ? "required" : ""; ?> />
            </div>

            <div>
              <label>Gender<?php echo $hasGender ? " *" : ""; ?></label>
              <select name="gender" <?php echo $hasGender ? "required" : ""; ?>>
                <option value="">Select Gender</option>
                <option value="Male" <?php echo ($values["gender"]==="Male")?"selected":""; ?>>Male</option>
                <option value="Female" <?php echo ($values["gender"]==="Female")?"selected":""; ?>>Female</option>
                <option value="Other" <?php echo ($values["gender"]==="Other")?"selected":""; ?>>Other</option>
              </select>
            </div>

            <div style="grid-column: 1 / -1;">
              <label>Address<?php echo $hasAddress ? " *" : ""; ?></label>
              <textarea name="address" <?php echo $hasAddress ? "required" : ""; ?>><?php echo htmlspecialchars($values["address"]); ?></textarea>
            </div>

            <div>
              <label>Department *</label>
              <select name="dept_id" required>
                <option value="">Select Department</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?php echo (int)$d["dept_id"]; ?>" <?php echo ((int)$values["dept_id"] === (int)$d["dept_id"]) ? "selected" : ""; ?>>
                    <?php echo htmlspecialchars((string)$d["name"]); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label>Semester<?php echo $hasSemester ? " *" : ""; ?></label>
              <select name="semester" <?php echo $hasSemester ? "required" : ""; ?>>
                <option value="">Select Semester</option>
                <?php foreach ($semesterOptions as $opt): ?>
                  <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($values["semester"]===$opt)?"selected":""; ?>>
                    <?php echo htmlspecialchars($opt); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="actions">
            <button class="btn btn-primary" type="submit">Update Student</button>
            <a class="btn" href="list.php">Cancel</a>
          </div>
        </form>

      </div>
    </div>
  </main>
</div>
</body>
</html>