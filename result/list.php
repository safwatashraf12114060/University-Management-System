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
$departmentFilter = $reportData["department_id"];
$courseFilter = $reportData["course_id"];
$semesterFilter = $reportData["semester"];
$yearFilter = $reportData["year"];
$hasEnrollYear = $reportData["has_year"];
$departmentOptions = $reportData["department_options"];
$courseOptions = $reportData["course_options"];
$semesterOptions = $reportData["semester_options"];
$yearOptions = $reportData["year_options"];
$allRows = $reportData["rows"];

$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = (int)($_GET["per_page"] ?? 5);
if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 5;

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
  <style>
    .result-toolbar{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      align-items:center;
      width:100%;
      margin-bottom:14px;
    }
    .result-toolbar .search,
    .result-toolbar select,
    .result-toolbar .btn{
      height:46px;
    }
    .result-toolbar .search{
      flex:1 1 420px;
      min-width:320px;
      margin-bottom:0;
    }
    .result-toolbar select{
      flex:0 0 auto;
      width:auto;
    }
    .result-toolbar select[name="department_id"]{
      width:170px;
    }
    .result-toolbar select[name="course_id"]{
      width:170px;
    }
    .result-toolbar select[name="semester"]{
      width:130px;
    }
    .result-toolbar select[name="year"]{
      width:110px;
    }
    .result-toolbar select[name="per_page"]{
      width:96px;
      min-width:96px;
    }
    .result-toolbar .btn{
      flex:0 0 112px;
      width:112px;
      white-space:nowrap;
      justify-content:center;
    }
    .live-results table thead th,
    .live-results table tbody td{
      text-align:center;
      vertical-align:middle;
    }
    .live-results .actions{
      justify-content:center;
    }
    @media (max-width: 700px){
      .result-toolbar{
        flex-direction:column;
        align-items:stretch;
      }
      .result-toolbar .search,
      .result-toolbar select,
      .result-toolbar .btn{
        width:100%;
      }
    }
  </style>
</head>
<body class="list-page">
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
        <form method="get" action="list.php" class="result-toolbar">
          <div class="search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <circle cx="11" cy="11" r="7" stroke="#64748b" stroke-width="2"/>
              <path d="M20 20l-3.5-3.5" stroke="#64748b" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search by course code, student, grade..." />
          </div>

          <select name="department_id" aria-label="Filter by department" onchange="this.form.submit()">
            <option value="">Departments</option>
            <?php foreach ($departmentOptions as $dept): ?>
              <?php $deptId = (int)($dept["dept_id"] ?? 0); ?>
              <option value="<?php echo $deptId; ?>" <?php echo ((string)$departmentFilter === (string)$deptId) ? 'selected' : ''; ?>>
                <?php echo h($dept["department_name"] ?? ""); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="course_id" aria-label="Filter by course" onchange="this.form.submit()">
            <option value="">Courses</option>
            <?php foreach ($courseOptions as $course): ?>
              <?php
                $filterCourseId = (int)($course["course_id"] ?? 0);
                $filterCourseCode = trim((string)($course["course_code"] ?? ""));
                $filterCourseName = (string)($course["course_name"] ?? "");
              ?>
              <option value="<?php echo $filterCourseId; ?>" <?php echo ((string)$courseFilter === (string)$filterCourseId) ? 'selected' : ''; ?>>
                <?php echo h(($filterCourseCode !== "" ? $filterCourseCode . " - " : "") . $filterCourseName); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="semester" aria-label="Filter by semester" onchange="this.form.submit()">
            <option value="">Semesters</option>
            <?php foreach ($semesterOptions as $opt): ?>
              <option value="<?php echo h($opt); ?>" <?php echo ((string)$semesterFilter === (string)$opt) ? 'selected' : ''; ?>>
                <?php echo h($opt); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <?php if ($hasEnrollYear): ?>
            <select name="year" aria-label="Filter by year" onchange="this.form.submit()">
              <option value="">Years</option>
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

          <button class="btn btn-primary" type="submit">Apply</button>
          <a class="btn" href="list.php">Reset</a>
        </form>

        <div class="live-results">
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
                <th>Actions</th>
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
                  <td>
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
    </div>
  </main>
</div>
</body>
</html>
