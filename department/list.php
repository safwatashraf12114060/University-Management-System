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

$search = trim($_GET["q"] ?? "");
$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;

$perPage = 5;
$offset = ($page - 1) * $perPage;

$where = "1=1";
$params = [];

if ($search !== "") {
    $where .= " AND (name LIKE ? ";
    $params[] = "%" . $search . "%";
    if ($codeCol !== null) { $where .= " OR $codeCol LIKE ? "; $params[] = "%" . $search . "%"; }
    if ($headCol !== null) { $where .= " OR $headCol LIKE ? "; $params[] = "%" . $search . "%"; }
    $where .= ")";
}

$countSql = "SELECT COUNT(*) AS total FROM $deptTable WHERE $where";
$countStmt = sqlsrv_query($conn, $countSql, $params);
$totalRows = 0;
if ($countStmt) {
    $r = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $totalRows = (int)($r["total"] ?? 0);
}
$totalPages = (int)ceil(max(1, $totalRows) / $perPage);

$cols = "dept_id, name";
if ($codeCol !== null) $cols .= ", $codeCol AS dept_code";
if ($headCol !== null) $cols .= ", $headCol AS dept_head";

$listSql = "
  SELECT $cols
  FROM $deptTable
  WHERE $where
  ORDER BY dept_id DESC
  OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";
$params2 = array_merge($params, [$offset, $perPage]);

$stmt = sqlsrv_query($conn, $listSql, $params2);
$rows = [];
if ($stmt) while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;

function pageUrl($p, $q) {
    $qs = [];
    if ($q !== "") $qs["q"] = $q;
    $qs["page"] = $p;
    return "list.php?" . http_build_query($qs);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Departments</title>
  <style>
    :root{--bg:#f4f6fb;--card:#ffffff;--text:#0f172a;--muted:#64748b;--primary:#2f3cff;--border:#e5e7eb;--shadow2:0 8px 22px rgba(0,0,0,0.06);--radius:14px;--sidebar:#ffffff;--danger:#ef4444;}
    *{box-sizing:border-box;}
    body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);}
    .layout{display:flex;min-height:100vh;}
    .sidebar{width:260px;background:var(--sidebar);border-right:1px solid var(--border);padding:18px 14px;}
    .brand{font-weight:900;letter-spacing:.4px;font-size:18px;padding:10px 10px 16px;}
    .nav{display:flex;flex-direction:column;gap:6px;margin-top:6px;}
    .nav a{display:flex;align-items:center;gap:12px;padding:12px 12px;border-radius:12px;color:var(--text);text-decoration:none;font-weight:700;opacity:.92;}
    .nav a:hover{background:rgba(47,60,255,0.07);opacity:1;}
    .nav a.active{background:var(--primary);color:#fff;box-shadow:0 10px 18px rgba(47,60,255,0.18);}
    .content{flex:1;display:flex;flex-direction:column;min-width:0;}
    .topbar{height:64px;background:var(--card);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 18px;}
    .logout{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:800;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:#fff;}
    .page{padding:22px 22px 36px;max-width:1200px;width:100%;}
    .header{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin: 8px 0 14px;}
    h1{margin:0;font-size:34px;letter-spacing:-0.6px;}
    .btn{display:inline-flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--text);text-decoration:none;font-weight:900;}
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow2);padding:16px;}
    .search{display:flex;align-items:center;gap:10px;border:1px solid #d0d4e3;background:#fff;border-radius:12px;padding:10px 12px;margin-bottom:12px;}
    .search input{border:0;outline:0;width:100%;font-size:14px;}
    table{width:100%;border-collapse:collapse;}
    thead th{text-align:left;font-size:13px;color:var(--muted);padding:12px 10px;border-bottom:1px solid var(--border);font-weight:900;}
    tbody td{padding:14px 10px;border-bottom:1px solid #f0f2f7;font-weight:800;vertical-align:top;}
    .actions{display:flex;gap:12px;justify-content:flex-end;}
    .icon-btn{width:34px;height:34px;border-radius:10px;border:1px solid var(--border);display:inline-flex;align-items:center;justify-content:center;background:#fff;text-decoration:none;}
    .icon-btn:hover{transform: translateY(-1px);}
    .icon-edit svg{stroke: var(--primary);}
    .icon-del svg{stroke: var(--danger);}
    .footer{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding-top:12px;}
    .pager{display:flex;gap:8px;align-items:center;}
    .pager a{text-decoration:none;font-weight:900;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:#fff;color:var(--text);}
    .pager a.active{background:var(--primary);border-color:var(--primary);color:#fff;}
    .muted{color:var(--muted);font-weight:800;font-size:13px;}
    @media(max-width:860px){.sidebar{display:none;}}
  </style>
</head>
<body>
<div class="layout">

  <aside class="sidebar">
    <div class="brand">UMS</div>
    <nav class="nav">
      <a href="../index.php">Dashboard</a>
      <a href="../student/list.php">Students</a>
      <a href="../teacher/list.php">Teachers</a>
      <a class="active" href="list.php">Departments</a>
      <a href="../course/list.php">Courses</a>
      <a href="../enrollment/list.php">Enrollments</a>
      <a href="../result/list.php">Results</a>
    </nav>
  </aside>

  <main class="content">
    <div class="topbar">
      <div style="font-weight:900;"><?php echo htmlspecialchars($_SESSION["name"] ?? "User"); ?></div>
      <a class="logout" href="../logout.php">Logout</a>
    </div>

    <div class="page">
      <div class="header">
        <h1>Departments</h1>
        <a class="btn btn-primary" href="add.php"><span style="font-size:18px;line-height:0;">＋</span> Add Department</a>
      </div>

      <div class="card">
        <form class="search" method="get" action="">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="11" cy="11" r="7" stroke="#64748b" stroke-width="2"/>
            <path d="M20 20l-3.5-3.5" stroke="#64748b" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search departments..." />
        </form>

        <table>
          <thead>
            <tr>
              <th style="width:180px;">Department Code</th>
              <th>Department Name</th>
              <th style="width:260px;">Department Head</th>
              <th style="width:170px; text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($rows) === 0): ?>
              <tr><td colspan="4" class="muted">No departments found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $d): ?>
                <?php
                  $id = (int)$d["dept_id"];
                  $code = (string)($d["dept_code"] ?? "—");
                  $head = (string)($d["dept_head"] ?? "—");
                ?>
                <tr>
                  <td><?php echo htmlspecialchars($code); ?></td>
                  <td><?php echo htmlspecialchars((string)$d["name"]); ?></td>
                  <td><?php echo htmlspecialchars($head); ?></td>
                  <td style="text-align:right;">
                    <div class="actions">
                      <a class="icon-btn icon-edit" href="edit.php?dept_id=<?php echo $id; ?>" title="Edit">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                          <path d="M12 20h9" stroke-width="2" stroke-linecap="round"/>
                          <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z" stroke-width="2" stroke-linejoin="round"/>
                        </svg>
                      </a>
                      <a class="icon-btn icon-del" href="delete.php?dept_id=<?php echo $id; ?>" title="Delete"
                         onclick="return confirm('Delete this department?');">
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
          <div class="muted">Showing <?php echo min($perPage, max(0, $totalRows - $offset)); ?> of <?php echo $totalRows; ?> departments</div>
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