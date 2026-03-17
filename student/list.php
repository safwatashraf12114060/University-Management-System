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
$deptFilter = trim($_GET["dept_id"] ?? "");
$semesterFilter = trim($_GET["semester"] ?? "");
$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;

$perPage = 5;
$offset = ($page - 1) * $perPage;

/* department options load */
$departments = [];
$deptStmt = sqlsrv_query($conn, "SELECT dept_id, name FROM $deptTable ORDER BY name ASC");
if ($deptStmt) {
    while ($dept = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $dept;
    }
}

/* semester options */
$semesterOptions = [
    "1" => "1st",
    "2" => "2nd",
    "3" => "3rd",
    "4" => "4th",
    "5" => "5th",
    "6" => "6th",
    "7" => "7th",
    "8" => "8th"
];

$where = "1=1";
$params = [];

if ($search !== "") {
    $where .= " AND (s.$nameCol LIKE ? ";
    $params[] = "%" . $search . "%";

    if ($hasEmail) {
        $where .= " OR s.email LIKE ? ";
        $params[] = "%" . $search . "%";
    }

    if ($hasStudentCode) {
        $where .= " OR s.student_code LIKE ? ";
        $params[] = "%" . $search . "%";
    }

    if ($hasRegistrationNo) {
        $where .= " OR s.registration_no LIKE ? ";
        $params[] = "%" . $search . "%";
    }

    $where .= ")";
}

if ($deptFilter !== "") {
    $where .= " AND s.$deptFkCol = ? ";
    $params[] = (int)$deptFilter;
}

if ($semesterFilter !== "" && $hasSemester) {
    $where .= " AND s.semester = ? ";
    $params[] = $semesterFilter;
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
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
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

function pageUrl($p, $q, $deptId, $semester) {
    $qs = [];

    if ($q !== "") $qs["q"] = $q;
    if ($deptId !== "") $qs["dept_id"] = $deptId;
    if ($semester !== "") $qs["semester"] = $semester;

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
  <link rel="stylesheet" href="../assets/app.css">
</head>
<body>
<div class="layout">

  <?php renderSidebar("students", "../"); ?>

  <main class="content">
    <?php renderTopbar($name, "", "../logout.php", false); ?>

    <div class="page">
      <div class="header">
        <h1>Students</h1>
        <a class="btn btn-primary" href="add.php">
          <span style="font-size:18px;line-height:0;">＋</span>
          Add Student
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
              placeholder="Search students..."
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

          <select name="semester" aria-label="Filter by semester">
            <option value="">All Semesters</option>
            <?php foreach ($semesterOptions as $num => $label): ?>
              <option
                value="<?php echo h($num); ?>"
                <?php echo ((string)$semesterFilter === (string)$num || (string)$semesterFilter === $label) ? "selected" : ""; ?>
              >
                <?php echo h($label); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <button class="btn btn-primary" type="submit">Filter</button>
          <a class="btn" href="list.php">Reset</a>
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
              <tr>
                <td colspan="6" class="muted">No students found.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $s): ?>
                <?php
                  $id = (int)$s["student_id"];
                  $sid = studentLabel($s, $hasStudentCode, $hasRegistrationNo);
                  $email = $hasEmail ? (string)($s["email"] ?? "") : "";
                  $semester = $hasSemester ? (string)($s["semester"] ?? "") : "";
                ?>
                <tr>
                  <td><?php echo h($sid); ?></td>
                  <td><?php echo h($s["student_name"]); ?></td>
                  <td><?php echo h($email !== "" ? $email : "-"); ?></td>
                  <td><?php echo h($s["dept_name"] ?? "-"); ?></td>
                  <td>
                    <?php
                      if ($semester !== "" && isset($semesterOptions[(string)$semester])) {
                          echo h($semesterOptions[(string)$semester]);
                      } else {
                          echo h($semester !== "" ? $semester : "-");
                      }
                    ?>
                  </td>
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
            <a href="<?php echo h(pageUrl(max(1, $page - 1), $search, $deptFilter, $semesterFilter)); ?>">Previous</a>
            <?php
              $start = max(1, $page - 2);
              $end = min($totalPages, $page + 2);
              for ($p = $start; $p <= $end; $p++):
            ?>
              <a
                class="<?php echo $p === $page ? "active" : ""; ?>"
                href="<?php echo h(pageUrl($p, $search, $deptFilter, $semesterFilter)); ?>"
              >
                <?php echo $p; ?>
              </a>
            <?php endfor; ?>
            <a href="<?php echo h(pageUrl(min($totalPages, $page + 1), $search, $deptFilter, $semesterFilter)); ?>">Next</a>
          </div>
        </div>

      </div>
    </div>
  </main>
</div>
</body>
</html>