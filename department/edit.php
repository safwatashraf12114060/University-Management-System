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

$deptTable = "DEPARTMENT";

$codeCandidates = ["department_code", "dept_code", "code"];
$headCandidates = ["department_head", "dept_head", "head"];

$codeCol = null;
foreach ($codeCandidates as $c) {
    if (colExists($conn, "dbo.$deptTable", $c) || colExists($conn, $deptTable, $c)) { $codeCol = $c; break; }
}

$headCol = null;
foreach ($headCandidates as $c) {
    if (colExists($conn, "dbo.$deptTable", $c) || colExists($conn, $deptTable, $c)) { $headCol = $c; break; }
}

$dept_id = (int)($_GET["dept_id"] ?? 0);
if ($dept_id <= 0) { header("Location: list.php"); exit(); }

$cols = "dept_id, name";
if ($codeCol !== null) $cols .= ", $codeCol AS dept_code";
if ($headCol !== null) $cols .= ", $headCol AS dept_head";

$stmt = sqlsrv_query($conn, "SELECT $cols FROM $deptTable WHERE dept_id = ?", [$dept_id]);
$row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
if (!$row) { header("Location: list.php"); exit(); }

$error = "";
$values = [
  "dept_name" => (string)($row["name"] ?? ""),
  "dept_code" => (string)($row["dept_code"] ?? ""),
  "dept_head" => (string)($row["dept_head"] ?? "")
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $values["dept_name"] = trim($_POST["dept_name"] ?? "");
    $values["dept_code"] = trim($_POST["dept_code"] ?? "");
    $values["dept_head"] = trim($_POST["dept_head"] ?? "");

    if ($values["dept_name"] === "") {
        $error = "Department name is required.";
    } else {
        $sets = ["name = ?"];
        $params = [$values["dept_name"]];

        if ($codeCol !== null) { $sets[] = "$codeCol = ?"; $params[] = ($values["dept_code"] !== "" ? $values["dept_code"] : null); }
        if ($headCol !== null) { $sets[] = "$headCol = ?"; $params[] = ($values["dept_head"] !== "" ? $values["dept_head"] : null); }

        $params[] = $dept_id;
        $sql = "UPDATE $deptTable SET " . implode(", ", $sets) . " WHERE dept_id = ?";
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
  <title>Edit Department</title>
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
    label{display:block;font-weight:900;font-size:13px;margin:0 0 6px;}
    input{width:100%;padding:12px;border:1px solid #d0d4e3;border-radius:10px;outline:none;background:#fff;}
    input:focus{border-color:var(--primary);}
    .stack{display:flex;flex-direction:column;gap:14px;}
    .actions{display:flex;gap:12px;margin-top:16px;}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 16px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--text);text-decoration:none;font-weight:900;cursor:pointer;}
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
    .alert{margin-bottom:12px;padding:10px 12px;border-radius:10px;background:#ffe8e8;border:1px solid #ffb3b3;color:#8a0000;font-weight:800;font-size:14px;}
    @media(max-width:860px){.sidebar{display:none;}}
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
      <a class="back" href="list.php">← Back to Departments</a>
      <h1>Edit Department</h1>

      <div class="card">
        <?php if ($error !== ""): ?>
          <div class="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" action="">
          <div class="stack">
            <div>
              <label>Department Name *</label>
              <input name="dept_name" value="<?php echo htmlspecialchars($values["dept_name"]); ?>" required />
            </div>

            <div>
              <label>Department Code *</label>
              <input name="dept_code" value="<?php echo htmlspecialchars($values["dept_code"]); ?>" />
            </div>

            <div>
              <label>Department Head *</label>
              <input name="dept_head" value="<?php echo htmlspecialchars($values["dept_head"]); ?>" />
            </div>
          </div>

          <div class="actions">
            <button class="btn btn-primary" type="submit">Update Department</button>
            <a class="btn" href="list.php">Cancel</a>
          </div>
        </form>

      </div>
    </div>
  </main>
</div>
</body>
</html>