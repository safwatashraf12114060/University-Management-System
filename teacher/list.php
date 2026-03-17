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

$hasEmail = colExists($conn, "dbo.TEACHER", "email") || colExists($conn, "TEACHER", "email");
$hasPhone = colExists($conn, "dbo.TEACHER", "phone") || colExists($conn, "TEACHER", "phone");
$hasDesignation = colExists($conn, "dbo.TEACHER", "designation") || colExists($conn, "TEACHER", "designation");
$hasTeacherCode = colExists($conn, "dbo.TEACHER", "teacher_code") || colExists($conn, "TEACHER", "teacher_code");

$search = trim($_GET["q"] ?? "");
$deptFilter = trim($_GET["dept_id"] ?? "");
$designationFilter = trim($_GET["designation"] ?? "");
$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;

$perPage = 5;
$offset = ($page - 1) * $perPage;

/* department options */
$departments = [];
$deptStmt = sqlsrv_query($conn, "SELECT dept_id, name FROM DEPARTMENT ORDER BY name ASC");
if ($deptStmt) {
    while ($d = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $d;
    }
}

/* designation options */
$designationOptions = [];
if ($hasDesignation) {
    $desStmt = sqlsrv_query($conn, "
        SELECT DISTINCT designation
        FROM TEACHER
        WHERE designation IS NOT NULL AND LTRIM(RTRIM(designation)) <> ''
        ORDER BY designation ASC
    ");
    if ($desStmt) {
        while ($d = sqlsrv_fetch_array($desStmt, SQLSRV_FETCH_ASSOC)) {
            $designationOptions[] = (string)$d["designation"];
        }
    }
}

$where = "1=1";
$params = [];

if ($search !== "") {
    $where .= " AND (t.name LIKE ? ";
    $params[] = "%" . $search . "%";

    if ($hasEmail) {
        $where .= " OR t.email LIKE ? ";
        $params[] = "%" . $search . "%";
    }

    if ($hasTeacherCode) {
        $where .= " OR t.teacher_code LIKE ? ";
        $params[] = "%" . $search . "%";
    }

    $where .= ")";
}

if ($deptFilter !== "") {
    $where .= " AND t.dept_id = ? ";
    $params[] = (int)$deptFilter;
}

if ($designationFilter !== "" && $hasDesignation) {
    $where .= " AND t.designation = ? ";
    $params[] = $designationFilter;
}

$countSql = "
    SELECT COUNT(*) AS total
    FROM TEACHER t
    JOIN DEPARTMENT d ON d.dept_id = t.dept_id
    WHERE $where
";
$countStmt = sqlsrv_query($conn, $countSql, $params);
$totalRows = 0;
if ($countStmt) {
    $r = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $totalRows = (int)($r["total"] ?? 0);
}
$totalPages = (int)ceil(max(1, $totalRows) / $perPage);

$cols = "t.teacher_id, t.name, t.dept_id, t.hire_date, d.name AS dept_name";
if ($hasEmail) $cols .= ", t.email";
if ($hasPhone) $cols .= ", t.phone";
if ($hasDesignation) $cols .= ", t.designation";
if ($hasTeacherCode) $cols .= ", t.teacher_code";

$listSql = "
    SELECT $cols
    FROM TEACHER t
    JOIN DEPARTMENT d ON d.dept_id = t.dept_id
    WHERE $where
    ORDER BY t.teacher_id DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";
$params2 = array_merge($params, [$offset, $perPage]);
$stmt = sqlsrv_query($conn, $listSql, $params2);

$rows = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
}

function teacherLabel($row, $hasTeacherCode) {
    if ($hasTeacherCode) {
        $tc = (string)($row["teacher_code"] ?? "");
        if ($tc !== "") return $tc;
    }
    $id = (int)($row["teacher_id"] ?? 0);
    return "T" . str_pad((string)$id, 3, "0", STR_PAD_LEFT);
}

function pageUrl($p, $q, $deptId, $designation) {
    $qs = [];
    if ($q !== "") $qs["q"] = $q;
    if ($deptId !== "") $qs["dept_id"] = $deptId;
    if ($designation !== "") $qs["designation"] = $designation;
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
  <title>Teachers</title>
  <link rel="stylesheet" href="../assets/app.css">
</head>
<body>
<div class="layout">

  <?php renderSidebar("teachers", "../"); ?>

  <main class="content">
    <?php renderTopbar($name, "", "../logout.php", false); ?>

    <div class="page">
      <div class="header">
        <h1>Teachers</h1>
        <a class="btn btn-primary" href="add.php">
          <span style="font-size:18px;line-height:0;">＋</span>
          Add Teacher
        </a>
      </div>

      <div class="card">
        <form method="get" action="list.php" class="toolbar">
          <div class="search" style="flex:1;min-width:260px;margin-bottom:0;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="11" cy="11" r="7" stroke="#64748b" stroke-width="2"/>
              <path d="M20 20l-3.5-3.5" stroke="#64748b" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <input
              type="text"
              name="q"
              value="<?php echo h($search); ?>"
              placeholder="Search teachers..."
            />
          </div>

          <select name="dept_id" aria-label="Filter by department">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
              <option
                value="<?php echo (int)$d["dept_id"]; ?>"
                <?php echo ((string)$deptFilter === (string)$d["dept_id"]) ? "selected" : ""; ?>
              >
                <?php echo h($d["name"]); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="designation" aria-label="Filter by designation">
            <option value="">All Designations</option>
            <?php foreach ($designationOptions as $designation): ?>
              <option
                value="<?php echo h($designation); ?>"
                <?php echo ((string)$designationFilter === (string)$designation) ? "selected" : ""; ?>
              >
                <?php echo h($designation); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <button class="btn btn-primary" type="submit">Filter</button>
          <a class="btn" href="list.php">Reset</a>
        </form>

        <table>
          <thead>
            <tr>
              <th style="width:120px;">Teacher ID</th>
              <th style="width:220px;">Name</th>
              <th style="width:240px;">Email</th>
              <th style="width:220px;">Department</th>
              <th style="width:190px;">Designation</th>
              <th style="width:140px; text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($rows) === 0): ?>
              <tr><td colspan="6" class="muted">No teachers found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $t): ?>
                <?php
                  $id = (int)$t["teacher_id"];
                  $tid = teacherLabel($t, $hasTeacherCode);
                  $email = $hasEmail ? (string)($t["email"] ?? "") : "";
                  $designation = $hasDesignation ? (string)($t["designation"] ?? "") : "";
                ?>
                <tr>
                  <td><?php echo h($tid); ?></td>
                  <td><?php echo h($t["name"]); ?></td>
                  <td><?php echo h($email !== "" ? $email : "-"); ?></td>
                  <td><?php echo h($t["dept_name"]); ?></td>
                  <td><?php echo h($designation !== "" ? $designation : "-"); ?></td>
                  <td style="text-align:right;">
                    <div class="actions">
                      <a class="icon-btn icon-edit" href="edit.php?teacher_id=<?php echo $id; ?>" title="Edit">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                          <path d="M12 20h9" stroke-width="2" stroke-linecap="round"/>
                          <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z" stroke-width="2" stroke-linejoin="round"/>
                        </svg>
                      </a>

                      <a class="icon-btn icon-del" href="delete.php?teacher_id=<?php echo $id; ?>" title="Delete"
                         onclick="return confirm('Delete this teacher?');">
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
            Showing <?php echo min($perPage, max(0, $totalRows - $offset)); ?> of <?php echo $totalRows; ?> teachers
          </div>

          <div class="pager">
            <a href="<?php echo h(pageUrl(max(1, $page - 1), $search, $deptFilter, $designationFilter)); ?>">Previous</a>
            <?php
              $start = max(1, $page - 2);
              $end = min($totalPages, $page + 2);
              for ($p = $start; $p <= $end; $p++):
            ?>
              <a
                class="<?php echo $p === $page ? "active" : ""; ?>"
                href="<?php echo h(pageUrl($p, $search, $deptFilter, $designationFilter)); ?>"
              >
                <?php echo $p; ?>
              </a>
            <?php endfor; ?>
            <a href="<?php echo h(pageUrl(min($totalPages, $page + 1), $search, $deptFilter, $designationFilter)); ?>">Next</a>
          </div>
        </div>

      </div>
    </div>
  </main>
</div>
</body>
</html>