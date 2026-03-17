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
    sqlsrv_free_stmt($countStmt);
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
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
    sqlsrv_free_stmt($stmt);
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
  <title>Departments</title>
  <link rel="stylesheet" href="../assets/app.css">
</head>
<body>
<div class="layout">

  <?php renderSidebar("departments", "../"); ?>

  <main class="content">
    <?php renderTopbar($name, "", "../logout.php", false); ?>

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
              <th style="width:130px;">Department Code</th>
              <th style="width:280px;">Department Name</th>
              <th style="width:170px;">Department Head</th>
              <th style="width:150px; text-align:right;">Actions</th>
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