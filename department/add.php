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

$error = "";
$values = [
    "dept_name" => "",
    "dept_code" => "",
    "dept_head" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $values["dept_name"] = trim($_POST["dept_name"] ?? "");
    $values["dept_code"] = trim($_POST["dept_code"] ?? "");
    $values["dept_head"] = trim($_POST["dept_head"] ?? "");

    if ($values["dept_name"] === "") {
        $error = "Department name is required.";
    } else {
        $sql = "INSERT INTO dbo.DEPARTMENT (name, dept_code, dept_head) VALUES (?, ?, ?)";
        $params = [
            $values["dept_name"],
            ($values["dept_code"] !== "" ? $values["dept_code"] : null),
            ($values["dept_head"] !== "" ? $values["dept_head"] : null)
        ];

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
  <title>Add Department</title>
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

  <?php renderSidebar("departments", "../"); ?>

  <main class="content">
    <?php renderTopbar($name, "", "../logout.php", false); ?>

    <div class="page">
      <a class="back-link" href="list.php">← Back to Departments</a>

      <div class="header">
        <h1>Add Department</h1>
      </div>

      <?php if ($error !== ""): ?>
        <div class="alert-err"><?php echo h($error); ?></div>
      <?php endif; ?>

      <div class="card form-card">
        <form method="post" action="">
          <div class="form-grid">
            <div class="field">
              <label>Department Name *</label>
              <input
                name="dept_name"
                value="<?php echo h($values["dept_name"]); ?>"
                placeholder="Computer Science & Engineering"
                required
              />
            </div>

            <div class="field">
              <label>Department Code</label>
              <input
                name="dept_code"
                value="<?php echo h($values["dept_code"]); ?>"
                placeholder="CSE"
              />
            </div>

            <div class="field full">
              <label>Department Head</label>
              <input
                name="dept_head"
                value="<?php echo h($values["dept_head"]); ?>"
                placeholder=" Dr. Shamim Akter"
              />
            </div>
          </div>

          <div class="form-actions">
            <button class="btn btn-primary" type="submit">Add Department</button>
            <a class="btn" href="list.php">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
</body>
</html>