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
    return isset($row["len"]) && $row["len"] !== null;
}

function h($v) {
    return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8");
}

function dateValue($d) {
    if ($d instanceof DateTime) return $d->format("Y-m-d");
    if (is_string($d)) return $d;
    return "";
}

$hasEmail = colExists($conn, "dbo.TEACHER", "email") || colExists($conn, "TEACHER", "email");
$hasPhone = colExists($conn, "dbo.TEACHER", "phone") || colExists($conn, "TEACHER", "phone");
$hasDesignation = colExists($conn, "dbo.TEACHER", "designation") || colExists($conn, "TEACHER", "designation");
$hasQualification = colExists($conn, "dbo.TEACHER", "qualification") || colExists($conn, "TEACHER", "qualification");
$hasTeacherCode = colExists($conn, "dbo.TEACHER", "teacher_code") || colExists($conn, "TEACHER", "teacher_code");
$hasHireDate = colExists($conn, "dbo.TEACHER", "hire_date") || colExists($conn, "TEACHER", "hire_date");

$teacher_id = (int)($_GET["teacher_id"] ?? 0);
if ($teacher_id <= 0) {
    header("Location: list.php");
    exit();
}

$departments = [];
$ds = sqlsrv_query($conn, "SELECT dept_id, name FROM DEPARTMENT ORDER BY name ASC");
if ($ds) {
    while ($r = sqlsrv_fetch_array($ds, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $r;
    }
}

$designationOptions = [
    "Professor",
    "Associate Professor",
    "Assistant Professor",
    "Lecturer",
    "Senior Lecturer"
];

$cols = "teacher_id, name, dept_id";
if ($hasTeacherCode) $cols .= ", teacher_code";
if ($hasEmail) $cols .= ", email";
if ($hasPhone) $cols .= ", phone";
if ($hasDesignation) $cols .= ", designation";
if ($hasQualification) $cols .= ", qualification";
if ($hasHireDate) $cols .= ", hire_date";

$stmt = sqlsrv_query($conn, "SELECT $cols FROM TEACHER WHERE teacher_id = ?", [$teacher_id]);
$row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;

if (!$row) {
    header("Location: list.php");
    exit();
}

$error = "";
$values = [
    "teacher_code" => $hasTeacherCode ? (string)($row["teacher_code"] ?? "") : "",
    "name" => (string)($row["name"] ?? ""),
    "email" => $hasEmail ? (string)($row["email"] ?? "") : "",
    "phone" => $hasPhone ? (string)($row["phone"] ?? "") : "",
    "dept_id" => (string)($row["dept_id"] ?? ""),
    "designation" => $hasDesignation ? (string)($row["designation"] ?? "") : "",
    "qualification" => $hasQualification ? (string)($row["qualification"] ?? "") : "",
    "hire_date" => $hasHireDate ? dateValue($row["hire_date"] ?? "") : ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $values["teacher_code"] = trim($_POST["teacher_code"] ?? "");
    $values["name"] = trim($_POST["name"] ?? "");
    $values["email"] = trim($_POST["email"] ?? "");
    $values["phone"] = trim($_POST["phone"] ?? "");
    $values["dept_id"] = trim($_POST["dept_id"] ?? "");
    $values["designation"] = trim($_POST["designation"] ?? "");
    $values["qualification"] = trim($_POST["qualification"] ?? "");
    $values["hire_date"] = trim($_POST["hire_date"] ?? "");

    if ($values["name"] === "" || $values["dept_id"] === "") {
        $error = "Name and department are required.";
    } else {
        $sets = ["name = ?", "dept_id = ?"];
        $params = [$values["name"], (int)$values["dept_id"]];

        if ($hasTeacherCode) {
            $sets[] = "teacher_code = ?";
            $params[] = ($values["teacher_code"] !== "" ? $values["teacher_code"] : null);
        }

        if ($hasEmail) {
            $sets[] = "email = ?";
            $params[] = ($values["email"] !== "" ? $values["email"] : null);
        }

        if ($hasPhone) {
            $sets[] = "phone = ?";
            $params[] = ($values["phone"] !== "" ? $values["phone"] : null);
        }

        if ($hasDesignation) {
            $sets[] = "designation = ?";
            $params[] = ($values["designation"] !== "" ? $values["designation"] : null);
        }

        if ($hasQualification) {
            $sets[] = "qualification = ?";
            $params[] = ($values["qualification"] !== "" ? $values["qualification"] : null);
        }

        if ($hasHireDate) {
            $sets[] = "hire_date = ?";
            $params[] = ($values["hire_date"] !== "" ? $values["hire_date"] : null);
        }

        $params[] = $teacher_id;

        $sql = "UPDATE TEACHER SET " . implode(", ", $sets) . " WHERE teacher_id = ?";
        $up = sqlsrv_query($conn, $sql, $params);

        if ($up) {
            header("Location: list.php");
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
  <title>Edit Teacher</title>
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

  <?php renderSidebar("teachers", "../"); ?>

  <main class="content">
    <?php renderTopbar($name, "", "../logout.php", false); ?>

    <div class="page">
      <a class="back-link" href="list.php">← Back to Teachers</a>

      <div class="header">
        <h1>Edit Teacher</h1>
      </div>

      <?php if ($error !== ""): ?>
        <div class="alert-err"><?php echo h($error); ?></div>
      <?php endif; ?>

      <div class="card form-card">
        <form method="post" action="">
          <div class="form-grid">
            <div class="field">
              <?php if ($hasTeacherCode): ?>
                <label for="teacher_code">Teacher ID</label>
                <input
                  id="teacher_code"
                  name="teacher_code"
                  value="<?php echo h($values["teacher_code"]); ?>"
                  placeholder="e.g., T001"
                />
              <?php else: ?>
                <label>Teacher ID</label>
                <input
                  class="readonly-input"
                  value="<?php echo h("T" . str_pad((string)$teacher_id, 3, "0", STR_PAD_LEFT)); ?>"
                  disabled
                />
              <?php endif; ?>
            </div>

            <div class="field">
              <label for="name">Full Name *</label>
              <input
                id="name"
                name="name"
                value="<?php echo h($values["name"]); ?>"
                required
              />
            </div>

            <div class="field">
              <label for="email">Email</label>
              <input
                id="email"
                type="email"
                name="email"
                value="<?php echo h($values["email"]); ?>"
              />
            </div>

            <div class="field">
              <label for="phone">Phone Number</label>
              <input
                id="phone"
                name="phone"
                value="<?php echo h($values["phone"]); ?>"
              />
            </div>

            <div class="field">
              <label for="dept_id">Department *</label>
              <select id="dept_id" name="dept_id" required>
                <option value="">Select Department</option>
                <?php foreach ($departments as $d): ?>
                  <option
                    value="<?php echo (int)$d["dept_id"]; ?>"
                    <?php echo ((int)$values["dept_id"] === (int)$d["dept_id"]) ? "selected" : ""; ?>
                  >
                    <?php echo h($d["name"]); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="designation">Designation</label>
              <select id="designation" name="designation">
                <option value="">Select Designation</option>
                <?php foreach ($designationOptions as $opt): ?>
                  <option
                    value="<?php echo h($opt); ?>"
                    <?php echo ($values["designation"] === $opt) ? "selected" : ""; ?>
                  >
                    <?php echo h($opt); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="qualification">Qualification</label>
              <input
                id="qualification"
                name="qualification"
                value="<?php echo h($values["qualification"]); ?>"
              />
            </div>

            <div class="field">
              <label for="hire_date">Joining Date</label>
              <input
                id="hire_date"
                name="hire_date"
                type="date"
                value="<?php echo h($values["hire_date"]); ?>"
              />
            </div>
          </div>

          <div class="form-actions">
            <button class="btn btn-primary" type="submit">Update Teacher</button>
            <a class="btn" href="list.php">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
</body>
</html>