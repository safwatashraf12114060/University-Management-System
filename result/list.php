<?php
session_start();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../partials/layout.php";
require_once __DIR__ . "/report_common.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

function h($v) {
    return resultReportH($v);
}

$displayName = $_SESSION["user_name"]
    ?? $_SESSION["name"]
    ?? $_SESSION["username"]
    ?? $_SESSION["email"]
    ?? "User";

$reportData = resultReportFetchData($conn, $_GET);
$q = $reportData["q"];
$semesterFilter = $reportData["semester"];
$yearFilter = $reportData["year"];
$hasEnrollYear = $reportData["has_year"];
$semesterOptions = $reportData["semester_options"];
$yearOptions = $reportData["year_options"];
$allRows = $reportData["rows"];

$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = (int)($_GET["per_page"] ?? 10);
if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 10;

$okMsg = "";
$success = (int)($_GET["success"] ?? 0);
if ($success === 1) $okMsg = "Result added successfully.";

$totalRows = count($allRows);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$rows = array_slice($allRows, $offset, $perPage);

function buildQuery(array $override = []) {
    return resultReportBuildQuery($_GET, $override);
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
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
          <a class="btn" href="pdf_preview.php<?php echo h(buildQuery(["page" => null])); ?>">View Report</a>
          <a class="btn btn-primary" href="add.php"><span style="font-size:18px;line-height:0;">+</span> Add Result</a>
        </div>
      </div>

      <?php if ($okMsg !== ""): ?><div class="msg-ok"><?php echo h($okMsg); ?></div><?php endif; ?>

      <div class="card">
        <form method="get" action="list.php" class="toolbar">
          <div class="search" style="flex:1;min-width:280px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="11" cy="11" r="7" stroke="#64748b" stroke-width="2"/>
              <path d="M20 20l-3.5-3.5" stroke="#64748b" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search by course code, student, grade..." />
          </div>

          <select name="semester" aria-label="Filter by semester">
            <option value="">All Semesters</option>
            <?php foreach ($semesterOptions as $opt): ?>
              <option value="<?php echo h($opt); ?>" <?php echo ((string)$semesterFilter === (string)$opt) ? 'selected' : ''; ?>>
                <?php echo h($opt); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <?php if ($hasEnrollYear): ?>
            <select name="year" aria-label="Filter by year">
              <option value="">All Years</option>
              <?php foreach ($yearOptions as $opt): ?>
                <option value="<?php echo h($opt); ?>" <?php echo ((string)$yearFilter === (string)$opt) ? 'selected' : ''; ?>>
                  <?php echo h($opt); ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>

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
                      <a class="icon-btn" href="view.php?id=<?php echo h($rid); ?>" title="View Student Transcript">
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
