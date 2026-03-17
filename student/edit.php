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

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return isset($row["len"]) && $row["len"] !== null;
}

function h($v) {
    return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8");
}

function dateValue($d) {
    if ($d instanceof DateTime) return $d->format("Y-m-d");
    if (is_string($d)) {
        $ts = strtotime($d);
        return $ts ? date("Y-m-d", $ts) : $d;
    }
    return "";
}

$studentTable = "STUDENT";
$deptTable = "DEPARTMENT";

$studentTableDbo = "dbo." . $studentTable;
$deptTableDbo = "dbo." . $deptTable;

$hasStudentName = colExists($conn, $studentTableDbo, "student_name") || colExists($conn, $studentTable, "student_name");
$hasName = colExists($conn, $studentTableDbo, "name") || colExists($conn, $studentTable, "name");
$nameCol = $hasStudentName ? "student_name" : ($hasName ? "name" : "student_name");

$hasEmail = colExists($conn, $studentTableDbo, "email") || colExists($conn, $studentTable, "email");
$hasPhone = colExists($conn, $studentTableDbo, "phone") || colExists($conn, $studentTable, "phone");
$hasAddress = colExists($conn, $studentTableDbo, "address") || colExists($conn, $studentTable, "address");
$hasSemester = colExists($conn, $studentTableDbo, "semester") || colExists($conn, $studentTable, "semester");

$hasDeptId = colExists($conn, $studentTableDbo, "dept_id") || colExists($conn, $studentTable, "dept_id");
$hasDepartmentId = colExists($conn, $studentTableDbo, "department_id") || colExists($conn, $studentTable, "department_id");
$deptFkCol = $hasDeptId ? "dept_id" : ($hasDepartmentId ? "department_id" : "dept_id");

$hasStudentCode = colExists($conn, $studentTableDbo, "student_code") || colExists($conn, $studentTable, "student_code");
$hasRegistrationNo = colExists($conn, $studentTableDbo, "registration_no") || colExists($conn, $studentTable, "registration_no");

$hasDob = (
    colExists($conn, $studentTableDbo, "dob") || colExists($conn, $studentTable, "dob") ||
    colExists($conn, $studentTableDbo, "date_of_birth") || colExists($conn, $studentTable, "date_of_birth")
);
$dobCol = (colExists($conn, $studentTableDbo, "dob") || colExists($conn, $studentTable, "dob")) ? "dob" : "date_of_birth";

$hasGender = colExists($conn, $studentTableDbo, "gender") || colExists($conn, $studentTable, "gender");

$student_id = (int)($_GET["student_id"] ?? 0);
if ($student_id <= 0) {
    header("Location: list.php");
    exit();
}

/* Load departments */
$departments = [];
$ds = sqlsrv_query($conn, "SELECT dept_id, name FROM $deptTableDbo ORDER BY name ASC");
if ($ds !== false) {
    while ($r = sqlsrv_fetch_array($ds, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $r;
    }
    sqlsrv_free_stmt($ds);
}

/* Semester options */
$semesterOptions = [
    "1" => "1st",
    "2" => "2nd",
    "3" => "3rd",
    "4" => "4th",
    "5" => "5th",
    "6" => "6th",
    "7" => "7th",
    "8" => "8th"
];

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
if (!$row) {
    header("Location: list.php");
    exit();
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

        if ($hasStudentCode) {
            $sets[] = "student_code = ?";
            $params[] = ($values["student_code"] !== "" ? $values["student_code"] : null);
        }

        if ($hasRegistrationNo) {
            $sets[] = "registration_no = ?";
            $params[] = ($values["registration_no"] !== "" ? $values["registration_no"] : null);
        }

        if ($hasEmail) {
            $sets[] = "email = ?";
            $params[] = ($values["email"] !== "" ? $values["email"] : null);
        }

        if ($hasPhone) {
            $sets[] = "phone = ?";
            $params[] = ($values["phone"] !== "" ? $values["phone"] : null);
        }

        if ($hasAddress) {
            $sets[] = "address = ?";
            $params[] = ($values["address"] !== "" ? $values["address"] : null);
        }

        if ($hasSemester) {
            $sets[] = "semester = ?";
            $params[] = ($values["semester"] !== "" ? $values["semester"] : null);
        }

        if ($hasDob) {
            $sets[] = "$dobCol = ?";
            $params[] = ($values["dob"] !== "" ? $values["dob"] : null);
        }

        if ($hasGender) {
            $sets[] = "gender = ?";
            $params[] = ($values["gender"] !== "" ? $values["gender"] : null);
        }

        $params[] = $student_id;

        $sql = "UPDATE $studentTable SET " . implode(", ", $sets) . " WHERE student_id = ?";
        $up = sqlsrv_query($conn, $sql, $params);

        if ($up) {
            header("Location: view.php?student_id=" . $student_id);
            exit();
        }

        $errs = sqlsrv_errors();
        $error = "Update failed: " . ($errs ? $errs[0]["message"] : "Unknown SQL error");
    }
}

$name = $_SESSION["name"] ?? "User";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Edit Student</title>
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
    .readonly-input{
      background:#f8fafc !important;
      color:var(--muted);
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

  <?php renderSidebar("students", "../"); ?>

  <main class="content">
    <?php renderTopbar($name, "", "../logout.php", false); ?>

    <div class="page">
      <a class="back-link" href="list.php">← Back to Students</a>

      <div class="header">
        <h1>Edit Student</h1>
      </div>

      <?php if ($error !== ""): ?>
        <div class="alert-err"><?php echo h($error); ?></div>
      <?php endif; ?>

      <div class="card form-card">
        <form method="post" action="">
          <div class="form-grid">
            <div class="field">
              <label>Student ID</label>
              <?php if ($hasStudentCode): ?>
                <input name="student_code" value="<?php echo h($values["student_code"]); ?>" />
              <?php elseif ($hasRegistrationNo): ?>
                <input name="registration_no" value="<?php echo h($values["registration_no"]); ?>" />
              <?php else: ?>
                <input class="readonly-input" value="<?php echo h("S" . str_pad((string)$student_id, 7, "0", STR_PAD_LEFT)); ?>" disabled />
              <?php endif; ?>
            </div>

            <div class="field">
              <label>Full Name *</label>
              <input name="name" value="<?php echo h($values["name"]); ?>" required />
            </div>

            <div class="field">
              <label>Email</label>
              <input type="email" name="email" value="<?php echo h($values["email"]); ?>" />
            </div>

            <div class="field">
              <label>Phone Number</label>
              <input name="phone" value="<?php echo h($values["phone"]); ?>" />
            </div>

            <div class="field">
              <label>Date of Birth</label>
              <input type="date" name="dob" value="<?php echo h($values["dob"]); ?>" />
            </div>

            <div class="field">
              <label>Gender</label>
              <select name="gender">
                <option value="">Select Gender</option>
                <option value="Male" <?php echo ($values["gender"] === "Male") ? "selected" : ""; ?>>Male</option>
                <option value="Female" <?php echo ($values["gender"] === "Female") ? "selected" : ""; ?>>Female</option>
                <option value="Other" <?php echo ($values["gender"] === "Other") ? "selected" : ""; ?>>Other</option>
              </select>
            </div>

            <div class="field full">
              <label>Address</label>
              <textarea name="address"><?php echo h($values["address"]); ?></textarea>
            </div>

            <div class="field">
              <label>Department *</label>
              <select name="dept_id" required>
                <option value="">Select Department</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?php echo (int)$d["dept_id"]; ?>" <?php echo ((string)$values["dept_id"] === (string)$d["dept_id"]) ? "selected" : ""; ?>>
                    <?php echo h($d["name"]); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>Semester</label>
              <select name="semester">
                <option value="">Select Semester</option>
                <?php foreach ($semesterOptions as $num => $label): ?>
                  <option value="<?php echo h($num); ?>" <?php echo ((string)$values["semester"] === (string)$num || (string)$values["semester"] === $label) ? "selected" : ""; ?>>
                    <?php echo h($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-actions">
            <button class="btn btn-primary" type="submit">Update Student</button>
            <a class="btn" href="view.php?student_id=<?php echo (int)$student_id; ?>">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
</body>
</html>