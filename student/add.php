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
    $sql = "SELECT COL_LENGTH(?, ?) AS len";
    $stmt = sqlsrv_query($conn, $sql, [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return isset($row["len"]) && $row["len"] !== null;
}

$studentTable = "STUDENT";
$deptTable = "DEPARTMENT";
$studentTableDbo = "dbo." . $studentTable;
$deptTableDbo = "dbo." . $deptTable;

/* Detect STUDENT name column */
$nameCandidates = ["student_name", "name", "full_name"];
$nameCol = null;
foreach ($nameCandidates as $c) {
    if (colExists($conn, $studentTableDbo, $c) || colExists($conn, $studentTable, $c)) {
        $nameCol = $c;
        break;
    }
}
if ($nameCol === null) $nameCol = "name";

/* Detect dept FK column */
$deptFkCandidates = ["dept_id", "department_id"];
$deptFkCol = null;
foreach ($deptFkCandidates as $c) {
    if (colExists($conn, $studentTableDbo, $c) || colExists($conn, $studentTable, $c)) {
        $deptFkCol = $c;
        break;
    }
}
if ($deptFkCol === null) $deptFkCol = "dept_id";

/* Optional columns */
$hasEmail = colExists($conn, $studentTableDbo, "email") || colExists($conn, $studentTable, "email");
$hasPhone = colExists($conn, $studentTableDbo, "phone") || colExists($conn, $studentTable, "phone");
$hasAddress = colExists($conn, $studentTableDbo, "address") || colExists($conn, $studentTable, "address");
$hasGender = colExists($conn, $studentTableDbo, "gender") || colExists($conn, $studentTable, "gender");

$hasDob = (
    colExists($conn, $studentTableDbo, "dob") || colExists($conn, $studentTable, "dob") ||
    colExists($conn, $studentTableDbo, "date_of_birth") || colExists($conn, $studentTable, "date_of_birth")
);
$dobCol = (colExists($conn, $studentTableDbo, "dob") || colExists($conn, $studentTable, "dob")) ? "dob" : "date_of_birth";

$hasSemester = colExists($conn, $studentTableDbo, "semester") || colExists($conn, $studentTable, "semester");
$hasEnrollmentDate = colExists($conn, $studentTableDbo, "enrollment_date") || colExists($conn, $studentTable, "enrollment_date");

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
    1 => "1st",
    2 => "2nd",
    3 => "3rd",
    4 => "4th",
    5 => "5th",
    6 => "6th",
    7 => "7th",
    8 => "8th"
];

$error = "";
$values = [
    "student_code" => "",
    "registration_no" => "",
    "name" => "",
    "email" => "",
    "phone" => "",
    "dob" => "",
    "gender" => "",
    "address" => "",
    "dept_id" => "",
    "semester" => ""
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
        $cols = [$nameCol, $deptFkCol];
        $qs = ["?", "?"];
        $params = [
            $values["name"],
            (int)$values["dept_id"]
        ];

        if ($hasSemester) {
            $cols[] = "semester";
            $qs[] = "?";
            $params[] = ($values["semester"] !== "" ? (int)$values["semester"] : null);
        }

        if ($hasEnrollmentDate) {
            $cols[] = "enrollment_date";
            $qs[] = "?";
            $params[] = date("Y-m-d");
        }

        if ($hasEmail) {
            $cols[] = "email";
            $qs[] = "?";
            $params[] = ($values["email"] !== "" ? $values["email"] : null);
        }

        if ($hasPhone) {
            $cols[] = "phone";
            $qs[] = "?";
            $params[] = ($values["phone"] !== "" ? $values["phone"] : null);
        }

        if ($hasAddress) {
            $cols[] = "address";
            $qs[] = "?";
            $params[] = ($values["address"] !== "" ? $values["address"] : null);
        }

        if ($hasGender) {
            $cols[] = "gender";
            $qs[] = "?";
            $params[] = ($values["gender"] !== "" ? $values["gender"] : null);
        }

        if ($hasDob) {
            $cols[] = $dobCol;
            $qs[] = "?";
            $params[] = ($values["dob"] !== "" ? $values["dob"] : null);
        }

        $sql = "INSERT INTO $studentTableDbo (" . implode(",", $cols) . ") VALUES (" . implode(",", $qs) . ")";
        $st = sqlsrv_query($conn, $sql, $params);

        if ($st === false) {
            $errs = sqlsrv_errors();
            $error = "Insert failed: " . ($errs ? $errs[0]["message"] : "Unknown SQL error");
        } else {
            sqlsrv_free_stmt($st);
            header("Location: list.php");
            exit();
        }
    }
}

$name = $_SESSION["name"] ?? "User";
$email = $_SESSION["email"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Add Student</title>
  <link rel="stylesheet" href="../assets/app.css">
  <style>

    .form-card{
    width:100%;
    }
    .form-grid{
      display:grid;
      grid-template-columns:repeat(2, minmax(0, 1fr));
      gap:16px;
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
        <h1>Add Student</h1>
      </div>

      <?php if ($error !== ""): ?>
        <div class="alert-err"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <div class="card form-card">
        <form method="post" action="">
          <div class="form-grid">
            <div class="field">
              <label>Student ID *</label>
              <input class="readonly-input" value="Auto generated" disabled />
            </div>

            <div class="field">
              <label>Full Name *</label>
              <input
                name="name"
                value="<?php echo htmlspecialchars($values["name"]); ?>"
                placeholder="Safwat Ashraf Nabil"
                required
              />
            </div>

            <div class="field">
              <label>Email</label>
              <input
                type="email"
                name="email"
                value="<?php echo htmlspecialchars($values["email"]); ?>"
                placeholder="safwat@example.com"
              />
            </div>

            <div class="field">
              <label>Phone Number</label>
              <input
                name="phone"
                value="<?php echo htmlspecialchars($values["phone"]); ?>"
                placeholder="+880 123 456 789 "
              />
            </div>

            <div class="field">
              <label>Date of Birth</label>
              <input
                type="date"
                name="dob"
                value="<?php echo htmlspecialchars($values["dob"]); ?>"
              />
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
              <textarea name="address" placeholder="Enter full address"><?php echo htmlspecialchars($values["address"]); ?></textarea>
            </div>

            <div class="field">
              <label>Department *</label>
              <select name="dept_id" required>
                <option value="">Select Department</option>
                <?php foreach ($departments as $d): ?>
                  <option
                    value="<?php echo (int)$d["dept_id"]; ?>"
                    <?php echo ((string)$values["dept_id"] === (string)$d["dept_id"]) ? "selected" : ""; ?>
                  >
                    <?php echo htmlspecialchars((string)$d["name"]); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>Semester</label>
              <select name="semester">
                <option value="">Select Semester</option>
                <?php foreach ($semesterOptions as $num => $label): ?>
                  <option
                    value="<?php echo (int)$num; ?>"
                    <?php echo ((string)$values["semester"] === (string)$num) ? "selected" : ""; ?>
                  >
                    <?php echo htmlspecialchars($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-actions">
            <button class="btn btn-primary" type="submit">Add Student</button>
            <a class="btn" href="list.php">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
</body>
</html>