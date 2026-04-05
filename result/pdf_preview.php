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
$departmentId = $data["department_id"];
$courseId = $data["course_id"];
$semester = $data["semester"];
$year = $data["year"];
$hasYear = $data["has_year"];
$departmentOptions = $data["department_options"];
$courseOptions = $data["course_options"];
$generatedOn = date("d M Y, h:i A");
$downloadUrl = "download_report_pdf.php" . resultReportBuildQuery($_GET);
$displayName = $_SESSION["user_name"]
    ?? $_SESSION["name"]
    ?? $_SESSION["username"]
    ?? $_SESSION["email"]
    ?? "User";

$selectedDepartmentName = "";
foreach ($departmentOptions as $departmentOption) {
    if ((string)($departmentOption["dept_id"] ?? "") === (string)$departmentId) {
        $selectedDepartmentName = (string)($departmentOption["department_name"] ?? "");
        break;
    }
}

$selectedCourseLabel = "";
foreach ($courseOptions as $courseOption) {
    if ((string)($courseOption["course_id"] ?? "") === (string)$courseId) {
        $code = trim((string)($courseOption["course_code"] ?? ""));
        $name = (string)($courseOption["course_name"] ?? "");
        $selectedCourseLabel = ($code !== "" ? $code . " - " : "") . $name;
        break;
    }
}

$uniqueStudents = [];
$marksTotal = 0.0;
$marksCount = 0;
foreach ($rows as $row) {
    $studentKey = (string)($row["student_code"] ?? "");
    if ($studentKey !== "") {
        $uniqueStudents[$studentKey] = true;
    }
    if ($row["marks"] !== null && $row["marks"] !== "") {
        $marksTotal += (float)$row["marks"];
        $marksCount++;
    }
}

$averageMarks = $marksCount > 0 ? number_format($marksTotal / $marksCount, 1, ".", "") : "0";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Result PDF Preview</title>
  <link rel="stylesheet" href="../assets/app.css">
  <style>
    .back-link{
      display:inline-flex;
      align-items:center;
      gap:8px;
      font-weight:900;
      margin-bottom:14px;
      text-decoration:none;
      color:var(--text);
    }
    .transcript-header{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:16px;
      margin-bottom:18px;
      flex-wrap:wrap;
    }
    .transcript-header h1{margin:0;font-size:48px;line-height:1.05;letter-spacing:-1px;}
    .download-btn{
      display:inline-flex;
      align-items:center;
      gap:10px;
      background:#3b48f5;
      color:#fff;
      border-color:#3b48f5;
    }
    .download-btn svg path{stroke:#fff;}
    .transcript-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:18px;
      box-shadow:0 10px 30px rgba(15,23,42,0.06);
      padding:28px 32px;
    }
    .transcript-top{text-align:center;margin-bottom:24px;}
    .transcript-top h2{margin:0;font-size:28px;letter-spacing:-0.5px;}
    .transcript-top p{margin:6px 0 0;color:#64748b;font-size:16px;}
    .meta-grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:16px 24px;
      margin-bottom:24px;
    }
    .meta-item{padding:8px 0;}
    .meta-label{
      color:#64748b;
      font-size:14px;
      margin-bottom:4px;
    }
    .meta-value{
      font-weight:900;
      font-size:16px;
      color:#0f172a;
      word-break:break-word;
    }
    .divider{
      height:1px;
      background:#e5e7eb;
      margin:20px 0 26px;
    }
    .section-head{
      display:flex;
      justify-content:space-between;
      gap:16px;
      align-items:center;
      margin-bottom:14px;
      flex-wrap:wrap;
    }
    .section-head h3{margin:0;font-size:20px;letter-spacing:-0.2px;}
    .info-pill{
      display:inline-flex;
      align-items:center;
      gap:6px;
      background:#f8fafc;
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:10px 14px;
      font-weight:800;
      color:#334155;
    }
    .info-pill strong{color:#3b48f5;font-size:16px;}
    .transcript-table{
      width:100%;
      border-collapse:collapse;
    }
    .transcript-table th,
    .transcript-table td{
      text-align:left;
      padding:14px 12px;
      border-bottom:1px solid #e5e7eb;
      vertical-align:middle;
    }
    .transcript-table th{
      color:#334155;
      font-size:14px;
      font-weight:900;
      white-space:nowrap;
    }
    .transcript-table td{
      color:#0f172a;
      font-weight:700;
    }
    .transcript-table td.num,
    .transcript-table th.num{
      text-align:right;
      white-space:nowrap;
    }
    .grade-chip{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:36px;
      height:36px;
      border-radius:10px;
      background:#eef2ff;
      color:#3b48f5;
      font-weight:900;
      padding:0 10px;
    }
    .empty-box{
      padding:18px;
      border-radius:14px;
      background:#f8fafc;
      border:1px dashed #cbd5e1;
      color:#64748b;
      font-weight:800;
    }
    .summary-grid{
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:18px;
      margin-top:28px;
      padding-top:26px;
      border-top:1px solid #e5e7eb;
    }
    .summary-card{text-align:right;}
    .summary-card .k{
      color:#475569;
      font-size:14px;
      margin-bottom:6px;
    }
    .summary-card .v{
      font-size:42px;
      line-height:1;
      font-weight:900;
      color:#0f172a;
    }
    .summary-card .v.primary{color:#3b48f5;}
    @media (max-width: 860px){
      .transcript-card{padding:22px 18px;}
      .meta-grid{grid-template-columns:1fr;}
      .summary-grid{grid-template-columns:1fr;}
      .summary-card{text-align:left;}
      .transcript-header h1{font-size:38px;}
      .transcript-table{display:block;overflow:auto;}
    }
  </style>
</head>
<body>
<div class="layout">
  <?php renderSidebar("results", "../"); ?>
  <main class="content">
    <?php renderTopbar($displayName, "", "../logout.php", false); ?>
    <div class="page">
      <a class="back-link" href="list.php<?php echo resultReportH(resultReportBuildQuery($_GET)); ?>">&#8592; Back to Results</a>

      <div class="transcript-header">
        <h1>Result Report</h1>
        <a class="btn btn-primary download-btn" href="<?php echo resultReportH($downloadUrl); ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M12 4v10" stroke-width="2" stroke-linecap="round"/>
            <path d="M8 10l4 4 4-4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M4 18h16" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Download PDF
        </a>
      </div>

      <div class="transcript-card">
        <div class="transcript-top">
          <h2>University Management System</h2>
          <p>Official Result Report</p>
        </div>

        <div class="meta-grid">
          <div class="meta-item">
            <div class="meta-label">Generated On</div>
            <div class="meta-value"><?php echo resultReportH($generatedOn); ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Search</div>
            <div class="meta-value"><?php echo resultReportH($q !== "" ? $q : "-"); ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Department</div>
            <div class="meta-value"><?php echo resultReportH($selectedDepartmentName !== "" ? $selectedDepartmentName : "-"); ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Course</div>
            <div class="meta-value"><?php echo resultReportH($selectedCourseLabel !== "" ? $selectedCourseLabel : "-"); ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Semester</div>
            <div class="meta-value"><?php echo resultReportH($semester !== "" ? $semester : "-"); ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Year</div>
            <div class="meta-value"><?php echo resultReportH($hasYear ? ($year !== "" ? $year : "-") : "-"); ?></div>
          </div>
        </div>

        <div class="divider"></div>

        <div class="section-head">
          <h3>Result Entries</h3>
          <div class="info-pill">
            Total Rows:
            <strong><?php echo resultReportH((string)count($rows)); ?></strong>
          </div>
        </div>

        <?php if (count($rows) === 0): ?>
          <div class="empty-box">No results found for the selected filters.</div>
        <?php else: ?>
          <table class="transcript-table">
            <thead>
              <tr>
                <th>Student ID</th>
                <th>Student Name</th>
                <th>Course Code</th>
                <th>Course Name</th>
                <th class="num">Marks</th>
                <th class="num">Grade</th>
                <th>Semester</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <?php $term = trim(((string)($r["semester"] ?? "")) . " " . ((string)($r["year"] ?? ""))); ?>
                <tr>
                  <td><?php echo resultReportH($r["student_code"] ?? ""); ?></td>
                  <td><?php echo resultReportH($r["student_name"] ?? ""); ?></td>
                  <td><?php echo resultReportH($r["course_code"] ?? ""); ?></td>
                  <td><?php echo resultReportH($r["course_name"] ?? ""); ?></td>
                  <td class="num"><?php echo resultReportH(($r["marks"] ?? "") !== "" ? ((string)$r["marks"] . "/100") : "-"); ?></td>
                  <td class="num"><span class="grade-chip"><?php echo resultReportH($r["grade"] ?? "-"); ?></span></td>
                  <td><?php echo resultReportH($term !== "" ? $term : "-"); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="summary-grid">
            <div class="summary-card">
              <div class="k">Total Results</div>
              <div class="v"><?php echo resultReportH((string)count($rows)); ?></div>
            </div>
            <div class="summary-card">
              <div class="k">Unique Students</div>
              <div class="v"><?php echo resultReportH((string)count($uniqueStudents)); ?></div>
            </div>
            <div class="summary-card">
              <div class="k">Average Marks</div>
              <div class="v primary"><?php echo resultReportH($averageMarks); ?></div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
</body>
</html>
