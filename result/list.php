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

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
}

$displayName = $_SESSION["user_name"]
    ?? $_SESSION["name"]
    ?? $_SESSION["username"]
    ?? $_SESSION["email"]
    ?? "User";

$q = trim($_GET["q"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = (int)($_GET["per_page"] ?? 10);
if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 10;

$okMsg = "";
$success = (int)($_GET["success"] ?? 0);
if ($success === 1) $okMsg = "Result added successfully.";

$params = [];
$where = "1=1";

if ($q !== "") {
    $where .= " AND (
        s.student_id LIKE ? OR
        s.name LIKE ? OR
        c.course_code LIKE ? OR
        c.course_name LIKE ? OR
        r.grade LIKE ?
    )";
    $like = "%" . $q . "%";
    $params = [$like, $like, $like, $like, $like];
}

$countSql = "
    SELECT COUNT(*) AS total_rows
    FROM RESULT r
    JOIN ENROLLMENT e ON e.enrollment_id = r.enrollment_id
    JOIN STUDENT s ON s.student_id = e.student_id
    JOIN COURSE c ON c.course_id = e.course_id
    WHERE $where
";
$countSt = sqlsrv_query($conn, $countSql, $params);

$totalRows = 0;
if ($countSt !== false) {
    $row = sqlsrv_fetch_array($countSt, SQLSRV_FETCH_ASSOC);
    $totalRows = (int)($row["total_rows"] ?? 0);
    sqlsrv_free_stmt($countSt);
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$listSql = "
    SELECT
        r.result_id,
        r.marks,
        r.grade,
        e.semester,
        e.year,
        s.student_id AS student_code,
        s.name AS student_name,
        c.course_code,
        c.course_name
    FROM RESULT r
    JOIN ENROLLMENT e ON e.enrollment_id = r.enrollment_id
    JOIN STUDENT s ON s.student_id = e.student_id
    JOIN COURSE c ON c.course_id = e.course_id
    WHERE $where
    ORDER BY e.year DESC, e.semester DESC, s.name ASC, c.course_code ASC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";
$paramsPaged = array_merge($params, [$offset, $perPage]);
$listSt = sqlsrv_query($conn, $listSql, $paramsPaged);

$rows = [];
if ($listSt !== false) {
    while ($r = sqlsrv_fetch_array($listSt, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
    sqlsrv_free_stmt($listSt);
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

function gradeClass($g) {
    $g = strtoupper(trim((string)$g));
    if ($g === "A" || $g === "A+" || $g === "A-") return "gA";
    if ($g === "B+" || $g === "B" || $g === "B-") return "gB";
    if ($g === "C+" || $g === "C" || $g === "C-") return "gC";
    if ($g === "D") return "gD";
    if ($g === "F") return "gF";
    return "gX";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Results - UMS</title>
  <style>
    :root{--bg:#f4f6fb;--card:#ffffff;--text:#0f172a;--muted:#64748b;--primary:#2f3cff;--border:#e5e7eb;--shadow2:0 8px 22px rgba(0,0,0,0.06);--radius:14px;--sidebar:#ffffff;--danger:#ef4444;}
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
    .nav a.active svg path,.nav a.active svg rect,.nav a.active svg circle{stroke:#fff;}

    .content{flex:1;display:flex;flex-direction:column;min-width:0;}
    .topbar{height:64px;background:var(--card);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 18px;}
    .logout{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);font-weight:800;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:#fff;}
    .page{padding:22px 22px 36px;max-width:1200px;width:100%;}
    .header{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin: 8px 0 14px;}
    h1{margin:0;font-size:34px;letter-spacing:-0.6px;}
    .btn{display:inline-flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--text);text-decoration:none;font-weight:900;}
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff;}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow2);padding:16px;}

    .msg-ok{
      margin-bottom:12px;padding:12px 14px;border-radius:12px;
      background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:900;
    }

    .toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
    .search{flex:1;min-width:280px;display:flex;align-items:center;gap:10px;border:1px solid #d0d4e3;background:#fff;border-radius:12px;padding:10px 12px;}
    .search input{border:0;outline:0;width:100%;font-size:14px;}
    select{
      padding:10px 12px;border:1px solid var(--border);border-radius:12px;
      outline:none;background:#fff;font-weight:800;font-size:14px;
    }
    select:focus{border-color:var(--primary);}
    .btn:hover{transform: translateY(-1px);}

    table{width:100%;border-collapse:collapse;}
    thead th{text-align:left;font-size:13px;color:var(--muted);padding:12px 10px;border-bottom:1px solid var(--border);font-weight:900;white-space:nowrap;}
    tbody td{padding:14px 10px;border-bottom:1px solid #f0f2f7;font-weight:800;vertical-align:top;font-size:14px;}
    tbody tr:hover{background:#fafbff;}

    .badge{
      display:inline-flex;align-items:center;justify-content:center;
      padding:6px 12px;border-radius:999px;font-weight:900;font-size:12px;
      border:1px solid rgba(15,23,42,0.08);
    }
    .gA{background:#eef2ff;color:#2f3cff;}
    .gB{background:#ecfeff;color:#0e7490;}
    .gC{background:#fef3c7;color:#92400e;}
    .gD{background:#ffe4e6;color:#9f1239;}
    .gF{background:#fee2e2;color:#991b1b;}
    .gX{background:#e5e7eb;color:#111827;}

    .actions{display:flex;gap:12px;justify-content:flex-end;}
    .icon-btn{width:34px;height:34px;border-radius:10px;border:1px solid var(--border);display:inline-flex;align-items:center;justify-content:center;background:#fff;text-decoration:none;}
    .icon-btn:hover{transform: translateY(-1px);}

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

      <a class="active" href="list.php">
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
      <div style="font-weight:900;"><?php echo h($displayName); ?></div>
      <a class="logout" href="../logout.php">Logout</a>
    </div>

    <div class="page">
      <div class="header">
        <h1>Results</h1>
        <a class="btn btn-primary" href="add.php"><span style="font-size:18px;line-height:0;">＋</span> Add Result</a>
      </div>

      <?php if ($okMsg !== ""): ?><div class="msg-ok"><?php echo h($okMsg); ?></div><?php endif; ?>

      <div class="card">
        <form method="get" action="list.php" class="toolbar">
          <div class="search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="11" cy="11" r="7" stroke="#64748b" stroke-width="2"/>
              <path d="M20 20l-3.5-3.5" stroke="#64748b" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search results..." />
          </div>

          <select name="per_page" aria-label="Rows per page">
            <option value="5" <?php echo $perPage===5?'selected':''; ?>>5</option>
            <option value="10" <?php echo $perPage===10?'selected':''; ?>>10</option>
            <option value="20" <?php echo $perPage===20?'selected':''; ?>>20</option>
            <option value="50" <?php echo $perPage===50?'selected':''; ?>>50</option>
          </select>

          <button class="btn btn-primary" type="submit">Search</button>
          <a class="btn" href="list.php">Reset</a>
        </form>

        <div style="overflow:auto;">
          <table>
            <thead>
              <tr>
                <th>Student ID</th>
                <th>Student Name</th>
                <th>Course Code</th>
                <th>Course Name</th>
                <th>Marks</th>
                <th>Grade</th>
                <th>Semester</th>
                <th style="text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (count($rows) === 0): ?>
              <tr><td colspan="8" class="muted">No results found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php
                  $term = trim(((string)($r["semester"] ?? "")) . " " . ((string)($r["year"] ?? "")));
                  $marks = (string)($r["marks"] ?? "");
                  $grade = (string)($r["grade"] ?? "");
                  $rid = (int)($r["result_id"] ?? 0);
                ?>
                <tr>
                  <td><?php echo h($r["student_code"] ?? ""); ?></td>
                  <td><?php echo h($r["student_name"] ?? ""); ?></td>
                  <td><?php echo h($r["course_code"] ?? ""); ?></td>
                  <td><?php echo h($r["course_name"] ?? ""); ?></td>
                  <td><?php echo h($marks !== "" ? ($marks . "/100") : "-"); ?></td>
                  <td><span class="badge <?php echo h(gradeClass($grade)); ?>"><?php echo h($grade !== "" ? $grade : "-"); ?></span></td>
                  <td><?php echo h($term !== "" ? $term : "-"); ?></td>
                  <td style="text-align:right;">
                    <div class="actions">
                      <a class="icon-btn" href="view.php?id=<?php echo h($rid); ?>" title="View">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                          <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" stroke="#0f172a" stroke-width="2"/>
                          <circle cx="12" cy="12" r="3" stroke="#0f172a" stroke-width="2"/>
                        </svg>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="footer">
          <div class="muted">
            <?php echo "Showing " . h($showFrom) . " to " . h($showTo) . " of " . h($totalRows) . " results"; ?>
          </div>

          <div class="pager">
            <?php $prevDisabled = $page <= 1; $nextDisabled = $page >= $totalPages; ?>

            <a href="list.php<?php echo h(buildQuery(["page" => max(1, $page - 1)])); ?>"
               style="<?php echo $prevDisabled ? 'pointer-events:none;opacity:0.5;' : ''; ?>">
              Previous
            </a>

            <?php
              $start = max(1, $page - 2);
              $end = min($totalPages, $page + 2);
              for ($p = $start; $p <= $end; $p++):
            ?>
              <a class="<?php echo $p === $page ? "active" : ""; ?>"
                 href="list.php<?php echo h(buildQuery(["page" => $p])); ?>">
                <?php echo h($p); ?>
              </a>
            <?php endfor; ?>

            <a href="list.php<?php echo h(buildQuery(["page" => min($totalPages, $page + 1)])); ?>"
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