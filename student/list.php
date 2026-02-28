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

$search = trim($_GET["q"] ?? "");
$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;

$perPage = 5;
$offset = ($page - 1) * $perPage;

$where = "1=1";
$params = [];

if ($search !== "") {
    $where .= " AND (s.$nameCol LIKE ? ";
    $params[] = "%" . $search . "%";

    if ($hasEmail) { $where .= " OR s.email LIKE ? "; $params[] = "%" . $search . "%"; }
    if ($hasStudentCode) { $where .= " OR s.student_code LIKE ? "; $params[] = "%" . $search . "%"; }
    if ($hasRegistrationNo) { $where .= " OR s.registration_no LIKE ? "; $params[] = "%" . $search . "%"; }

    $where .= ")";
}

$countSql = "
    SELECT COUNT(*) AS total
    FROM $studentTable s
    LEFT JOIN $deptTable d ON d.dept_id = s.$deptFkCol
    WHERE $where
";
$countStmt = sqlsrv_query($conn, $countSql, $params);
$totalRows = 0;
if ($countStmt) {
    $r = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $totalRows = (int)($r["total"] ?? 0);
}
$totalPages = (int)ceil(max(1, $totalRows) / $perPage);

$cols = "s.student_id, s.$nameCol AS student_name, d.name AS dept_name";
if ($hasEmail) $cols .= ", s.email";
if ($hasSemester) $cols .= ", s.semester";
if ($hasStudentCode) $cols .= ", s.student_code";
if ($hasRegistrationNo) $cols .= ", s.registration_no";

$listSql = "
    SELECT $cols
    FROM $studentTable s
    LEFT JOIN $deptTable d ON d.dept_id = s.$deptFkCol
    WHERE $where
    ORDER BY s.student_id DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";
$params2 = array_merge($params, [$offset, $perPage]);
$stmt = sqlsrv_query($conn, $listSql, $params2);

$rows = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
}

function studentLabel($row, $hasStudentCode, $hasRegistrationNo) {
    if ($hasStudentCode) {
        $sc = (string)($row["student_code"] ?? "");
        if ($sc !== "") return $sc;
    }
    if ($hasRegistrationNo) {
        $rn = (string)($row["registration_no"] ?? "");
        if ($rn !== "") return $rn;
    }
    $id = (int)($row["student_id"] ?? 0);
    return "S" . str_pad((string)$id, 7, "0", STR_PAD_LEFT);
}

function pageUrl($p, $q) {
    $qs = [];
    if ($q !== "") $qs["q"] = $q;
    $qs["page"] = $p;
    return "list.php?" . http_build_query($qs);
}

$name = $_SESSION["name"] ?? "User";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Students</title>
  <style>
    :root{
      --bg:#f4f6fb;--card:#ffffff;--text:#0f172a;--muted:#64748b;
      --primary:#2f3cff;--border:#e5e7eb;--shadow2:0 8px 22px rgba(0,0,0,0.06);
      --radius:14px;--sidebar:#ffffff;--danger:#ef4444;
    }
    *{ box-sizing:border-box; }
    body{ margin:0; font-family: Arial, sans-serif; background:var(--bg); color:var(--text); }
    .layout{ display:flex; min-height:100vh; }
    .sidebar{ width:260px; background:var(--sidebar); border-right:1px solid var(--border); padding:18px 14px; }
    .brand{ font-weight:900; letter-spacing:.4px; font-size:18px; padding:10px 10px 16px; }
    .nav{ display:flex; flex-direction:column; gap:6px; margin-top:6px; }
    .nav a{
      display:flex; align-items:center; gap:12px; padding:12px 12px; border-radius:12px;
      color:var(--text); text-decoration:none; font-weight:700; opacity:.92;
    }
    .nav a:hover{ background:rgba(47,60,255,0.07); opacity:1; }
    .nav a.active{ background:var(--primary); color:#fff; box-shadow:0 10px 18px rgba(47,60,255,0.18); }
    .nav svg{ width:18px; height:18px; flex:0 0 auto; }
    .nav a.active svg path, .nav a.active svg rect, .nav a.active svg circle{ stroke:#fff; }

    .content{ flex:1; display:flex; flex-direction:column; min-width:0; }
    .topbar{
      height:64px; background:var(--card); border-bottom:1px solid var(--border);
      display:flex; align-items:center; justify-content:space-between; padding:0 18px;
    }
    .logout{
      display:inline-flex; align-items:center; gap:10px; text-decoration:none; color:var(--text);
      font-weight:800; padding:10px 12px; border-radius:12px; border:1px solid var(--border); background:#fff;
    }

    .page{ padding:22px 22px 36px; max-width:1200px; width:100%; }
    .header{ display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin: 8px 0 14px; }
    h1{ margin:0; font-size:34px; letter-spacing:-0.6px; }
    .btn{
      display:inline-flex; align-items:center; gap:10px; padding:12px 16px; border-radius:12px;
      border:1px solid var(--border); background:#fff; color:var(--text); text-decoration:none; font-weight:900;
    }
    .btn-primary{ background:var(--primary); border-color:var(--primary); color:#fff; }
    .card{ background:var(--card); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow2); padding:16px; }
    .search{
      display:flex; align-items:center; gap:10px; border:1px solid #d0d4e3; background:#fff;
      border-radius:12px; padding:10px 12px; margin-bottom:12px;
    }
    .search input{ border:0; outline:0; width:100%; font-size:14px; }
    table{ width:100%; border-collapse:collapse; }
    thead th{
      text-align:left; font-size:13px; color:var(--muted); padding:12px 10px; border-bottom:1px solid var(--border);
      font-weight:900;
    }
    tbody td{ padding:14px 10px; border-bottom:1px solid #f0f2f7; font-weight:800; vertical-align:top; }
    .actions{ display:flex; gap:12px; justify-content:flex-end; }
    .icon-btn{
      width:34px; height:34px; border-radius:10px; border:1px solid var(--border);
      display:inline-flex; align-items:center; justify-content:center; background:#fff; text-decoration:none;
    }
    .icon-btn:hover{ transform: translateY(-1px); }
    .icon-view svg{ stroke: #0f172a; }
    .icon-edit svg{ stroke: var(--primary); }
    .icon-del svg{ stroke: var(--danger); }

    .footer{ display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; padding-top:12px; }
    .pager{ display:flex; gap:8px; align-items:center; }
    .pager a{
      text-decoration:none; font-weight:900; padding:10px 12px; border-radius:10px; border:1px solid var(--border);
      background:#fff; color:var(--text);
    }
    .pager a.active{ background:var(--primary); border-color:var(--primary); color:#fff; }
    .muted{ color:var(--muted); font-weight:800; font-size:13px; }

    @media (max-width: 860px){ .sidebar{ display:none; } }
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
      <div style="font-weight:900;"><?php echo htmlspecialchars($name); ?></div>
      <a class="logout" href="../logout.php">Logout</a>
    </div>

    <div class="page">
      <div class="header">
        <h1>Students</h1>
        <a class="btn btn-primary" href="add.php"><span style="font-size:18px;line-height:0;">＋</span> Add Student</a>
      </div>

      <div class="card">
        <form class="search" method="get" action="">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="11" cy="11" r="7" stroke="#64748b" stroke-width="2"/>
            <path d="M20 20l-3.5-3.5" stroke="#64748b" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search students..." />
        </form>

        <table>
          <thead>
            <tr>
              <th style="width:140px;">Student ID</th>
              <th style="width:220px;">Name</th>
              <th style="width:240px;">Email</th>
              <th style="width:220px;">Department</th>
              <th style="width:130px;">Semester</th>
              <th style="width:170px; text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($rows) === 0): ?>
              <tr><td colspan="6" class="muted">No students found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $s): ?>
                <?php
                  $id = (int)$s["student_id"];
                  $sid = studentLabel($s, $hasStudentCode, $hasRegistrationNo);
                  $email = $hasEmail ? (string)($s["email"] ?? "") : "";
                  $semester = $hasSemester ? (string)($s["semester"] ?? "") : "";
                ?>
                <tr>
                  <td><?php echo htmlspecialchars($sid); ?></td>
                  <td><?php echo htmlspecialchars((string)$s["student_name"]); ?></td>
                  <td><?php echo htmlspecialchars($email !== "" ? $email : "-"); ?></td>
                  <td><?php echo htmlspecialchars((string)($s["dept_name"] ?? "-")); ?></td>
                  <td><?php echo htmlspecialchars($semester !== "" ? $semester : "-"); ?></td>
                  <td style="text-align:right;">
                    <div class="actions">
                      <a class="icon-btn icon-view" href="view.php?student_id=<?php echo $id; ?>" title="View">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                          <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" stroke-width="2"/>
                          <circle cx="12" cy="12" r="3" stroke-width="2"/>
                        </svg>
                      </a>

                      <a class="icon-btn icon-edit" href="edit.php?student_id=<?php echo $id; ?>" title="Edit">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                          <path d="M12 20h9" stroke-width="2" stroke-linecap="round"/>
                          <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z" stroke-width="2" stroke-linejoin="round"/>
                        </svg>
                      </a>

                      <a class="icon-btn icon-del" href="delete.php?student_id=<?php echo $id; ?>" title="Delete"
                         onclick="return confirm('Delete this student?');">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                          <path d="M3 6h18" stroke-width="2" stroke-linecap="round"/>
                          <path d="M8 6V4h8v2" stroke-width="2" stroke-linejoin="round"/>
                          <path d="M6 6l1 16h10l1-16" stroke-width="2" stroke-linejoin="round"/>
                          <path d="M10 11v6" stroke-width="2" stroke-linecap="round"/>
                          <path d="M14 11v6" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="footer">
          <div class="muted">
            Showing <?php echo min($perPage, max(0, $totalRows - $offset)); ?> of <?php echo $totalRows; ?> students
          </div>

          <div class="pager">
            <a href="<?php echo htmlspecialchars(pageUrl(max(1, $page - 1), $search)); ?>">Previous</a>
            <?php
              $start = max(1, $page - 2);
              $end = min($totalPages, $page + 2);
              for ($p = $start; $p <= $end; $p++):
            ?>
              <a class="<?php echo $p === $page ? "active" : ""; ?>" href="<?php echo htmlspecialchars(pageUrl($p, $search)); ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <a href="<?php echo htmlspecialchars(pageUrl(min($totalPages, $page + 1), $search)); ?>">Next</a>
          </div>
        </div>

      </div>
    </div>
  </main>
</div>
</body>
</html>