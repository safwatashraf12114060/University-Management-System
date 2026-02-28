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
    while ($r = sqlsrv_fetch_array($ds, SQLSRV_FETCH_ASSOC)) $departments[] = $r;
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

function dateValue($d) {
    if ($d instanceof DateTime) return $d->format("Y-m-d");
    if (is_string($d)) return $d;
    return "";
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

        if ($hasTeacherCode) { $sets[]="teacher_code = ?"; $params[] = ($values["teacher_code"] !== "" ? $values["teacher_code"] : null); }
        if ($hasEmail) { $sets[]="email = ?"; $params[] = ($values["email"] !== "" ? $values["email"] : null); }
        if ($hasPhone) { $sets[]="phone = ?"; $params[] = ($values["phone"] !== "" ? $values["phone"] : null); }
        if ($hasDesignation) { $sets[]="designation = ?"; $params[] = ($values["designation"] !== "" ? $values["designation"] : null); }
        if ($hasQualification) { $sets[]="qualification = ?"; $params[] = ($values["qualification"] !== "" ? $values["qualification"] : null); }
        if ($hasHireDate) { $sets[]="hire_date = ?"; $params[] = ($values["hire_date"] !== "" ? $values["hire_date"] : null); }

        $params[] = $teacher_id;

        $sql = "UPDATE TEACHER SET " . implode(", ", $sets) . " WHERE teacher_id = ?";
        $up = sqlsrv_query($conn, $sql, $params);

        if ($up) {
            header("Location: list.php");
            exit();
        }
        $error = "Update failed.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Edit Teacher</title>
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
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow2);padding:18px;max-width:860px;}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    label{display:block;font-weight:900;font-size:13px;margin:0 0 6px;}
    input,select{width:100%;padding:12px;border:1px solid #d0d4e3;border-radius:10px;outline:none;background:#fff;}
    input:focus,select:focus{border-color:var(--primary);}
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
      <a class="active" href="list.php">
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
      <a href="../course/list.php">
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
      <div style="font-weight:900;"><?php echo htmlspecialchars($_SESSION["name"] ?? "User"); ?></div>
      <a class="logout" href="../logout.php">Logout</a>
    </div>

    <div class="page">
      <a class="back" href="list.php">← Back to Teachers</a>
      <h1>Edit Teacher</h1>

      <div class="card">
        <?php if ($error !== ""): ?>
          <div class="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" action="">
          <div class="grid">
            <?php if ($hasTeacherCode): ?>
            <div>
              <label for="teacher_code">Teacher ID *</label>
              <input id="teacher_code" name="teacher_code" value="<?php echo htmlspecialchars($values["teacher_code"]); ?>" placeholder="e.g., T001" />
            </div>
            <?php else: ?>
            <div>
              <label>Teacher ID</label>
              <input value="<?php echo htmlspecialchars("T" . str_pad((string)$teacher_id, 3, "0", STR_PAD_LEFT)); ?>" disabled />
            </div>
            <?php endif; ?>

            <div>
              <label for="name">Full Name *</label>
              <input id="name" name="name" value="<?php echo htmlspecialchars($values["name"]); ?>" required />
            </div>

            <div>
              <label for="email">Email<?php echo $hasEmail ? " *" : ""; ?></label>
              <input id="email" name="email" value="<?php echo htmlspecialchars($values["email"]); ?>" <?php echo $hasEmail ? "required" : ""; ?> />
            </div>

            <div>
              <label for="phone">Phone Number<?php echo $hasPhone ? " *" : ""; ?></label>
              <input id="phone" name="phone" value="<?php echo htmlspecialchars($values["phone"]); ?>" <?php echo $hasPhone ? "required" : ""; ?> />
            </div>

            <div>
              <label for="dept_id">Department *</label>
              <select id="dept_id" name="dept_id" required>
                <option value="">Select Department</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?php echo (int)$d["dept_id"]; ?>" <?php echo ((int)$values["dept_id"] === (int)$d["dept_id"]) ? "selected" : ""; ?>>
                    <?php echo htmlspecialchars((string)$d["name"]); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="designation">Designation<?php echo $hasDesignation ? " *" : ""; ?></label>
              <select id="designation" name="designation" <?php echo $hasDesignation ? "required" : ""; ?>>
                <option value="">Select Designation</option>
                <?php foreach ($designationOptions as $opt): ?>
                  <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($values["designation"] === $opt) ? "selected" : ""; ?>>
                    <?php echo htmlspecialchars($opt); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="qualification">Qualification<?php echo $hasQualification ? " *" : ""; ?></label>
              <input id="qualification" name="qualification" value="<?php echo htmlspecialchars($values["qualification"]); ?>" <?php echo $hasQualification ? "required" : ""; ?> />
            </div>

            <div>
              <label for="hire_date">Joining Date<?php echo $hasHireDate ? " *" : ""; ?></label>
              <input id="hire_date" name="hire_date" type="date" value="<?php echo htmlspecialchars($values["hire_date"]); ?>" <?php echo $hasHireDate ? "required" : ""; ?> />
            </div>
          </div>

          <div class="actions">
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