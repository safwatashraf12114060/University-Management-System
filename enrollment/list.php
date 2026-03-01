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

if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
}

function fmtTime($t) {
    if ($t instanceof DateTime) return $t->format("H:i");
    if (is_string($t) && $t !== "") return substr($t, 0, 5);
    return "";
}

$name = $_SESSION["name"] ?? "User";

$semester = trim($_GET["semester"] ?? "");
$year = trim($_GET["year"] ?? "");
$q = trim($_GET["q"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = (int)($_GET["per_page"] ?? 10);
if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 10;

$success = (int)($_GET["success"] ?? 0);
$errorMsg = "";
$okMsg = "";

if ($success === 1) $okMsg = "Enrollment created successfully.";
if ($success === 2) $okMsg = "Enrollment deleted successfully.";

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "delete") {
    $token = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $errorMsg = "Invalid request token.";
    } else {
        $enrollmentId = (int)($_POST["enrollment_id"] ?? 0);
        if ($enrollmentId <= 0) {
            $errorMsg = "Invalid enrollment id.";
        } else {
            $delSql = "DELETE FROM ENROLLMENT WHERE enrollment_id = ?";
            $delStmt = sqlsrv_query($conn, $delSql, [$enrollmentId]);
            if ($delStmt !== false) {
                header("Location: list.php?success=2");
                exit();
            }
        }
    }
}

$params = [];
$where = "1=1";

if ($semester !== "") {
    $where .= " AND e.semester = ?";
    $params[] = $semester;
}
if ($year !== "") {
    $where .= " AND e.year = ?";
    $params[] = (int)$year;
}

if ($q !== "") {
    $where .= " AND (
        s.student_id LIKE ? OR
        s.name LIKE ? OR
        c.course_name LIKE ? OR
        CONVERT(VARCHAR(50), c.course_id) LIKE ?
    )";
    $like = "%" . $q . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$countSql = "
    SELECT COUNT(*) AS total_rows
    FROM ENROLLMENT e
    JOIN STUDENT s ON s.student_id = e.student_id
    JOIN COURSE c ON c.course_id = e.course_id
    WHERE $where
";
$countStmt = sqlsrv_query($conn, $countSql, $params);

$totalRows = 0;
if ($countStmt !== false) {
    $countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $totalRows = (int)($countRow["total_rows"] ?? 0);
    sqlsrv_free_stmt($countStmt);
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;

$sql = "
    SELECT
      e.enrollment_id,
      e.semester,
      e.year,
      e.enrollment_date,
      s.student_id AS student_code,
      s.name AS student_name,
      c.course_id,
      c.course_name,
      c.credit_hours,
      c.schedule_day,
      c.start_time,
      c.end_time
    FROM ENROLLMENT e
    JOIN STUDENT s ON s.student_id = e.student_id
    JOIN COURSE c ON c.course_id = e.course_id
    WHERE $where
    ORDER BY e.year DESC, e.semester DESC, s.name ASC, c.course_name ASC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$paramsPaged = array_merge($params, [$offset, $perPage]);
$stmt = sqlsrv_query($conn, $sql, $paramsPaged);

$rows = [];
if ($stmt !== false) {
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $r;
    }
    sqlsrv_free_stmt($stmt);
}

function buildQuery(array $override = []) {
    $q = $_GET;
    foreach ($override as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    $qs = http_build_query($q);
    return $qs ? ("?" . $qs) : "";
}

$showFrom = $totalRows === 0 ? 0 : ($offset + 1);
$showTo = min($offset + $perPage, $totalRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Enrollments - UMS</title>
  <style>
    :root{
      --bg:#f4f6fb;
      --card:#ffffff;
      --text:#0f172a;
      --muted:#64748b;
      --primary:#2f3cff;
      --border:#e5e7eb;
      --shadow2:0 8px 22px rgba(0,0,0,0.06);
      --radius:14px;
      --sidebar:#ffffff;
      --danger:#ef4444;
      --okbg:#ecfdf5;
      --okbd:#a7f3d0;
      --oktx:#065f46;
    }
    *{box-sizing:border-box;}
    body{margin:0;font-family:Arial,sans-serif;background:var(--bg);color:var(--text);}
    a{color:inherit;text-decoration:none;}
    .layout{display:flex;min-height:100vh;}
    .sidebar{width:260px;background:var(--sidebar);border-right:1px solid var(--border);padding:18px 14px;}
    .brand{font-weight:900;letter-spacing:.4px;font-size:18px;padding:10px 10px 16px;}
    .nav{display:flex;flex-direction:column;gap:6px;margin-top:6px;}
    .nav a{display:flex;align-items:center;gap:12px;padding:12px 12px;border-radius:12px;color:var(--text);text-decoration:none;font-weight:700;opacity:.92;}
    .nav a:hover{background:rgba(47,60,255,0.07);opacity:1;}
    .nav a.active{background:var(--primary);color:#fff;box-shadow:0 10px 18px rgba(47,60,255,0.18);}
    .nav svg{width:18px;height:18px;flex:0 0 auto;}
    .nav a.active svg path,
    .nav a.active svg rect,
    .nav a.active svg circle{stroke:#fff;}
    .content{flex:1;display:flex;flex-direction:column;min-width:0;}
    .topbar{height:64px;background:var(--card);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 18px;}
    .logout{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:800;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:#fff;}
    .logout svg{width:18px;height:18px;}
    .page{padding:22px 22px 36px;max-width:1200px;width:100%;}
    .page-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
    h1{margin:6px 0 0;font-size:34px;letter-spacing:-0.6px;}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;padding:12px 16px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--text);text-decoration:none;font-weight:900;cursor:pointer;}
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
    .btn-primary:hover{filter:brightness(0.98);}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow2);padding:18px;}
    .alert-ok{margin-bottom:12px;padding:10px 12px;border-radius:10px;background:var(--okbg);border:1px solid var(--okbd);color:var(--oktx);font-weight:800;font-size:14px;}
    .toolbar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:14px;}
    .search{
      flex:1;min-width:260px;display:flex;align-items:center;gap:10px;
      padding:10px 12px;border:1px solid #d0d4e3;border-radius:12px;background:#fff;
    }
    .search input{border:none;outline:none;flex:1;font-size:14px;}
    .filters{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
    .filters input, .filters select{
      padding:10px 12px;border:1px solid #d0d4e3;border-radius:12px;outline:none;background:#fff;font-weight:700;
    }
    .filters input:focus, .filters select:focus{border-color:var(--primary);}
    table{width:100%;border-collapse:separate;border-spacing:0;border-radius:14px;overflow:hidden;}
    thead th{
      text-align:left;font-size:13px;padding:14px 14px;background:#fff;border-bottom:1px solid var(--border);
      font-weight:900;color:#111827;
    }
    tbody td{padding:14px 14px;border-bottom:1px solid #eef2f7;font-size:14px;color:#0f172a;}
    tbody tr:hover{background:#fafbff;}
    .muted{color:var(--muted);font-weight:800;}
    .actions{display:flex;align-items:center;gap:10px;}
    .icon-btn{
      border:none;background:transparent;cursor:pointer;padding:6px;border-radius:10px;
      display:inline-flex;align-items:center;justify-content:center;
    }
    .icon-btn:hover{background:#f3f4f6;}
    .icon-btn svg{width:18px;height:18px;}
    .danger{color:var(--danger);}
    .meta{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px;}
    .pager{display:flex;align-items:center;gap:8px;}
    .page-btn{
      min-width:40px;height:36px;border-radius:10px;border:1px solid var(--border);
      background:#fff;font-weight:900;display:inline-flex;align-items:center;justify-content:center;padding:0 12px;
    }
    .page-btn.active{background:var(--primary);border-color:var(--primary);color:#fff;}
    @media(max-width:860px){.sidebar{display:none;}}
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

      <a href="../student/list.php">
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

      <a class="active" href="list.php">
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
      <div style="font-weight:900;"><?php echo h($name); ?></div>
      <a class="logout" href="../logout.php">Logout</a>
    </div>

    <div class="page">
      <div class="page-head">
        <h1>Enrollments</h1>
        <a class="btn btn-primary" href="add.php">
          <span style="font-size:18px;line-height:0;">+</span>
          Enroll Student
        </a>
      </div>

      <?php if ($okMsg !== ""): ?>
        <div class="alert-ok"><?php echo h($okMsg); ?></div>
      <?php endif; ?>

      <div class="card">
        <form method="get" action="list.php" class="toolbar">
          <div class="search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="11" cy="11" r="7" stroke="#64748b" stroke-width="2"></circle>
              <path d="M21 21l-4.3-4.3" stroke="#64748b" stroke-width="2" stroke-linecap="round"></path>
            </svg>
            <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search enrollments..." />
          </div>

          <div class="filters">
            <input type="text" name="semester" value="<?php echo h($semester); ?>" placeholder="Semester (e.g., Fall)" />
            <input type="number" name="year" value="<?php echo h($year); ?>" placeholder="Year (e.g., 2025)" />
            <select name="per_page">
              <option value="5" <?php echo $perPage===5?'selected':''; ?>>5</option>
              <option value="10" <?php echo $perPage===10?'selected':''; ?>>10</option>
              <option value="20" <?php echo $perPage===20?'selected':''; ?>>20</option>
              <option value="50" <?php echo $perPage===50?'selected':''; ?>>50</option>
            </select>
            <button class="btn btn-primary" type="submit">Apply</button>
            <a class="btn" href="list.php">Reset</a>
          </div>
        </form>

        <div style="overflow:auto;">
          <table>
            <thead>
              <tr>
                <th>Student ID</th>
                <th>Student Name</th>
                <th>Course Code</th>
                <th>Course Name</th>
                <th>Credits</th>
                <th>Semester</th>
                <th style="text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($rows) === 0): ?>
                <tr>
                  <td colspan="7" class="muted">No enrollments found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <?php
                    $term = trim(((string)($r["semester"] ?? "")) . " " . ((string)($r["year"] ?? "")));
                    $courseCode = (string)($r["course_id"] ?? "");
                  ?>
                  <tr>
                    <td><?php echo h($r["student_code"] ?? ""); ?></td>
                    <td><?php echo h($r["student_name"] ?? ""); ?></td>
                    <td><?php echo h($courseCode); ?></td>
                    <td><?php echo h($r["course_name"] ?? ""); ?></td>
                    <td><?php echo h($r["credit_hours"] ?? ""); ?></td>
                    <td><?php echo h($term); ?></td>
                    <td style="text-align:right;">
                      <div class="actions" style="justify-content:flex-end;">
                        <form method="post" action="list.php" onsubmit="return confirm('Delete this enrollment?');" style="margin:0;">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">
                          <input type="hidden" name="enrollment_id" value="<?php echo h($r["enrollment_id"] ?? ""); ?>">
                          <button class="icon-btn danger" type="submit" title="Delete">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                              <path d="M3 6h18"/>
                              <path d="M8 6V4h8v2"/>
                              <path d="M19 6l-1 14H6L5 6"/>
                              <path d="M10 11v6"/>
                              <path d="M14 11v6"/>
                            </svg>
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="meta">
          <div class="muted">
            <?php echo "Showing " . h($showFrom) . " to " . h($showTo) . " of " . h($totalRows) . " enrollments"; ?>
          </div>

          <div class="pager">
            <?php $prevDisabled = $page <= 1; $nextDisabled = $page >= $totalPages; ?>

            <a class="page-btn" href="list.php<?php echo h(buildQuery(["page" => max(1, $page - 1)])); ?>"
               style="<?php echo $prevDisabled ? 'pointer-events:none;opacity:0.5;' : ''; ?>">
              Previous
            </a>

            <?php
              $start = max(1, $page - 2);
              $end = min($totalPages, $page + 2);
              for ($p = $start; $p <= $end; $p++):
            ?>
              <a class="page-btn <?php echo $p === $page ? 'active' : ''; ?>"
                 href="list.php<?php echo h(buildQuery(["page" => $p])); ?>">
                <?php echo h($p); ?>
              </a>
            <?php endfor; ?>

            <a class="page-btn" href="list.php<?php echo h(buildQuery(["page" => min($totalPages, $page + 1)])); ?>"
               style="<?php echo $nextDisabled ? 'pointer-events:none;opacity:0.5;' : ''; ?>">
              Next
            </a>
          </div>
        </div>

      </div>
    </div>
  </main>
</div>
</body>
</html>