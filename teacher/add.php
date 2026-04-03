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

function h($v) {
    return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8");
}

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return isset($row["len"]) && $row["len"] !== null;
}

$teacherTable = "TEACHER";
$hasEmail = colExists($conn, "dbo.$teacherTable", "email") || colExists($conn, $teacherTable, "email");
$hasPhone = colExists($conn, "dbo.$teacherTable", "phone") || colExists($conn, $teacherTable, "phone");
$hasDesignation = colExists($conn, "dbo.$teacherTable", "designation") || colExists($conn, $teacherTable, "designation");
$hasQualification = colExists($conn, "dbo.$teacherTable", "qualification") || colExists($conn, $teacherTable, "qualification");
$hasHireDate = colExists($conn, "dbo.$teacherTable", "hire_date") || colExists($conn, $teacherTable, "hire_date");

$error = "";
$values = [
    "name" => "",
    "email" => "",
    "phone" => "",
    "dept_id" => "",
    "designation" => "",
    "qualification" => "",
    "hire_date" => ""
];

$departments = [];
$ds = sqlsrv_query($conn, "SELECT dept_id, name FROM dbo.DEPARTMENT ORDER BY name ASC");
if ($ds !== false) {
    while ($r = sqlsrv_fetch_array($ds, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $r;
    }
    sqlsrv_free_stmt($ds);
}

$designationOptions = [
    "Professor",
    "Associate Professor",
    "Assistant Professor",
    "Lecturer",
    "Senior Lecturer"
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $values["name"] = trim($_POST["name"] ?? "");
    $values["email"] = trim($_POST["email"] ?? "");
    $values["phone"] = trim($_POST["phone"] ?? "");
    $values["dept_id"] = trim($_POST["dept_id"] ?? "");
    $values["designation"] = trim($_POST["designation"] ?? "");
    $values["qualification"] = trim($_POST["qualification"] ?? "");
    $values["hire_date"] = trim($_POST["hire_date"] ?? "");

    if ($values["name"] === "" || $values["dept_id"] === "" || ($hasHireDate && $values["hire_date"] === "")) {
        $error = $hasHireDate
            ? "Name, department and joining date are required."
            : "Name and department are required.";
    } else {
        $cols = ["name", "dept_id"];
        $qs = ["?", "?"];
        $params = [
            $values["name"],
            (int)$values["dept_id"]
        ];

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

        if ($hasDesignation) {
            $cols[] = "designation";
            $qs[] = "?";
            $params[] = ($values["designation"] !== "" ? $values["designation"] : null);
        }

        if ($hasQualification) {
            $cols[] = "qualification";
            $qs[] = "?";
            $params[] = ($values["qualification"] !== "" ? $values["qualification"] : null);
        }

        if ($hasHireDate) {
            $cols[] = "hire_date";
            $qs[] = "?";
            $params[] = ($values["hire_date"] !== "" ? $values["hire_date"] : null);
        }

        $sql = "INSERT INTO dbo.TEACHER (" . implode(", ", $cols) . ")
                VALUES (" . implode(", ", $qs) . ")";

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Add Teacher</title>
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
        <h1>Add Teacher</h1>
      </div>

      <?php if ($error !== ""): ?>
        <div class="alert-err"><?php echo h($error); ?></div>
      <?php endif; ?>

      <div class="card form-card">
        <form method="post" action="">
          <div class="form-grid">
            <div class="field">
              <label>Teacher ID</label>
              <input class="readonly-input" value="Auto generated" disabled />
            </div>

            <div class="field">
              <label for="name">Full Name *</label>
              <input
                id="name"
                name="name"
                value="<?php echo h($values["name"]); ?>"
                placeholder="Zahid Hasan"
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
                placeholder="zahid.cse@uni.edu"
                <?php echo $hasEmail ? "" : "disabled"; ?>
              />
            </div>

            <div class="field">
              <label for="phone">Phone Number</label>
              <input
                id="phone"
                name="phone"
                value="<?php echo h($values["phone"]); ?>"
                placeholder="+880 234 567 8900"
                <?php echo $hasPhone ? "" : "disabled"; ?>
              />
            </div>

            <div class="field">
              <label for="dept_id">Department *</label>
              <select id="dept_id" name="dept_id" required>
                <option value="">Select Department</option>
                <?php foreach ($departments as $d): ?>
                  <option
                    value="<?php echo (int)$d["dept_id"]; ?>"
                    <?php echo ($values["dept_id"] !== "" && (int)$values["dept_id"] === (int)$d["dept_id"]) ? "selected" : ""; ?>
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
                placeholder="Ph.D. in Computer Science"
                <?php echo $hasQualification ? "" : "disabled"; ?>
              />
            </div>

            <div class="field">
              <label for="hire_date">Joining Date *</label>
              <input
                id="hire_date"
                name="hire_date"
                type="date"
                value="<?php echo h($values["hire_date"]); ?>"
                <?php echo $hasHireDate ? "required" : "disabled"; ?>
              />
            </div>
          </div>

          <div class="form-actions">
            <button class="btn btn-primary" type="submit">Add Teacher</button>
            <a class="btn" href="list.php">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
</body>
</html>
