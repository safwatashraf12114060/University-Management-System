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

$data = resultReportFetchData($conn, $_GET);
$rows = $data["rows"];
$q = $data["q"];
$semester = $data["semester"];
$year = $data["year"];
$hasYear = $data["has_year"];
$generatedOn = date("d M Y, h:i A");
$downloadUrl = "download_report_pdf.php" . resultReportBuildQuery($_GET);
$displayName = $_SESSION["user_name"]
    ?? $_SESSION["name"]
    ?? $_SESSION["username"]
    ?? $_SESSION["email"]
    ?? "User";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Result PDF Preview</title>
  <link rel="stylesheet" href="../assets/app.css">
  <style>
    .preview-wrap{max-width:1200px;margin:0 auto;padding:24px;}
    .preview-actions{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
    .report-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:24px;box-shadow:0 8px 22px rgba(0,0,0,.06);}
    .report-head h1{margin:0 0 4px;font-size:30px;}
    .report-head h2{margin:0;font-size:18px;color:#475569;font-weight:800;}
    .meta-block{margin-top:18px;display:grid;grid-template-columns:1fr 1fr;gap:18px;}
    .meta-box{border:1px solid #e5e7eb;border-radius:12px;padding:14px;}
    .meta-box h3{margin:0 0 10px;font-size:14px;}
    .meta-box p{margin:6px 0;color:#334155;font-weight:700;}
    .report-table{margin-top:18px;overflow:auto;}
    .report-footer{margin-top:18px;font-weight:900;color:#334155;}
    @media (max-width:800px){.meta-block{grid-template-columns:1fr;}}
  </style>
</head>
<body>
<div class="layout">
  <?php renderSidebar("results", "../"); ?>
  <main class="content">
    <?php renderTopbar($displayName, "", "../logout.php", false); ?>
    <div class="preview-wrap">
      <div class="preview-actions">
        <a class="btn" href="list.php<?php echo resultReportH(resultReportBuildQuery($_GET)); ?>">&#8592; Back to Results</a>
        <a class="btn btn-primary" href="<?php echo resultReportH($downloadUrl); ?>">Download PDF</a>
      </div>

      <div class="report-card">
        <div class="report-head">
          <h1>University Management System</h1>
          <h2>Result Report</h2>
        </div>

        <div class="meta-block">
          <div class="meta-box">
            <h3>Generated Info</h3>
            <p>Generated on: <?php echo resultReportH($generatedOn); ?></p>
            <p>Total records: <?php echo resultReportH(count($rows)); ?></p>
          </div>
          <div class="meta-box">
            <h3>Applied Filters</h3>
            <p>Search: <?php echo resultReportH($q !== "" ? $q : "All"); ?></p>
            <p>Semester: <?php echo resultReportH($semester !== "" ? $semester : "All"); ?></p>
            <?php if ($hasYear): ?><p>Year: <?php echo resultReportH($year !== "" ? $year : "All"); ?></p><?php endif; ?>
          </div>
        </div>

        <div class="report-table">
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
              </tr>
            </thead>
            <tbody>
              <?php if (count($rows) === 0): ?>
                <tr><td colspan="7" class="muted">No results found for the selected filters.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <?php $term = trim(((string)($r["semester"] ?? "")) . " " . ((string)($r["year"] ?? ""))); ?>
                  <tr>
                    <td><?php echo resultReportH($r["student_code"] ?? ""); ?></td>
                    <td><?php echo resultReportH($r["student_name"] ?? ""); ?></td>
                    <td><?php echo resultReportH($r["course_code"] ?? ""); ?></td>
                    <td><?php echo resultReportH($r["course_name"] ?? ""); ?></td>
                    <td><?php echo resultReportH(($r["marks"] ?? "") !== "" ? ((string)$r["marks"] . "/100") : "-"); ?></td>
                    <td><?php echo resultReportH($r["grade"] ?? "-"); ?></td>
                    <td><?php echo resultReportH($term !== "" ? $term : "-"); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="report-footer">Filtered result report ready for download.</div>
      </div>
    </div>
  </main>
</div>
</body>
</html>
