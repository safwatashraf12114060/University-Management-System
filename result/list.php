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
    return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
}

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return isset($row["len"]) && $row["len"] !== null;
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

$courseNameCol = colExists($conn, "dbo.COURSE", "course_name") ? "course_name" : (colExists($conn, "dbo.COURSE", "title") ? "title" : "name");
$courseCodeCol = null;
foreach (["course_code", "code"] as $candidate) {
    if (colExists($conn, "dbo.COURSE", $candidate)) {
        $courseCodeCol = $candidate;
        break;
    }
}
$hasEnrollYear = colExists($conn, "dbo.ENROLLMENT", "year");

$params = [];
$where = "1=1";

if ($q !== "") {
    $where .= " AND (
        s.student_id LIKE ? OR
        s.name LIKE ? OR
        " . ($courseCodeCol !== null ? "c.$courseCodeCol LIKE ? OR" : "") . "
        c.$courseNameCol LIKE ? OR
        r.grade LIKE ?
    )";
    $like = "%" . $q . "%";
    $params = [$like, $like];
    if ($courseCodeCol !== null) $params[] = $like;
    $params[] = $like;
    $params[] = $like;
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
        " . ($hasEnrollYear ? "e.year," : "NULL AS year,") . "
        s.student_id AS student_code,
        s.name AS student_name,
        " . ($courseCodeCol !== null ? "c.$courseCodeCol AS course_code," : "CONVERT(VARCHAR(50), c.course_id) AS course_code,") . "
        c.$courseNameCol AS course_name
    FROM RESULT r
    JOIN ENROLLMENT e ON e.enrollment_id = r.enrollment_id
    JOIN STUDENT s ON s.student_id = e.student_id
    JOIN COURSE c ON c.course_id = e.course_id
    WHERE $where
    ORDER BY " . ($hasEnrollYear ? "e.year DESC," : "") . " e.semester DESC, s.name ASC, " . ($courseCodeCol !== null ? "c.$courseCodeCol" : "c.$courseNameCol") . " ASC
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
  <link rel="stylesheet" href="../assets/app.css">
</head>
<body>
<div class="layout">

  <?php renderSidebar("results", "../"); ?>

  <main class="content">
    <?php renderTopbar($displayName, "", "../logout.php", false); ?>

    <div class="page">
      <div class="header">
        <h1>Results</h1>
        <a class="btn btn-primary" href="add.php"><span style="font-size:18px;line-height:0;">＋</span> Add Result</a>
      </div>

      <?php if ($okMsg !== ""): ?><div class="msg-ok"><?php echo h($okMsg); ?></div><?php endif; ?>

      <div class="card">
        <form method="get" action="list.php" class="toolbar">
          <div class="search" style="flex:1;min-width:280px;">
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
