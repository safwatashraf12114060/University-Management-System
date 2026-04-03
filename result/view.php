<?php
session_start();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../partials/layout.php";
require_once __DIR__ . "/transcript_common.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

$resultId = (int)($_GET["id"] ?? 0);
$studentId = (int)($_GET["student_id"] ?? 0);
if ($studentId <= 0) {
    $studentId = transcriptResolveStudentId($conn, $resultId);
}
if ($studentId <= 0) {
    header("Location: list.php");
    exit();
}

$transcript = loadTranscriptData($conn, $studentId);
if (!$transcript) {
    header("Location: list.php");
    exit();
}

$displayName = $_SESSION["user_name"]
    ?? $_SESSION["name"]
    ?? $_SESSION["username"]
    ?? $_SESSION["email"]
    ?? "User";
$student = $transcript["student"];
$downloadUrl = "download_pdf.php?student_id=" . urlencode((string)$studentId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Student Transcript</title>
  <link rel="stylesheet" href="../assets/app.css">
  <style>
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
    .student-meta{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:16px 24px;
      margin-bottom:24px;
    }
    .meta-item{
      padding:8px 0;
    }
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
    .term-block + .term-block{
      margin-top:30px;
      padding-top:30px;
      border-top:1px solid #e5e7eb;
    }
    .term-head{
      display:flex;
      justify-content:space-between;
      gap:16px;
      align-items:center;
      margin-bottom:14px;
      flex-wrap:wrap;
    }
    .term-head h3{margin:0;font-size:20px;letter-spacing:-0.2px;}
    .gpa-pill{
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
    .gpa-pill strong{color:#3b48f5;font-size:16px;}
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
    .summary-grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:18px;
      margin-top:28px;
      padding-top:26px;
      border-top:1px solid #e5e7eb;
    }
    .summary-card{
      text-align:right;
    }
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
    .empty-box{
      padding:18px;
      border-radius:14px;
      background:#f8fafc;
      border:1px dashed #cbd5e1;
      color:#64748b;
      font-weight:800;
    }
    @media (max-width: 860px){
      .transcript-card{padding:22px 18px;}
      .student-meta{grid-template-columns:1fr;}
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
      <a class="back-link" href="list.php">&#8592; Back to Results</a>

      <div class="transcript-header">
        <h1>Student Transcript</h1>
        <a class="btn btn-primary download-btn" href="<?php echo transcriptH($downloadUrl); ?>">
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
          <p>Official Academic Transcript</p>
        </div>

        <div class="student-meta">
          <div class="meta-item">
            <div class="meta-label">Student ID</div>
            <div class="meta-value"><?php echo transcriptH($transcript["student_code"]); ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Student Name</div>
            <div class="meta-value"><?php echo transcriptH($student["student_name"] ?? "-"); ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Department</div>
            <div class="meta-value"><?php echo transcriptH($student["department_name"] ?? "-"); ?></div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Email</div>
            <div class="meta-value"><?php echo transcriptH(($student["email"] ?? "") !== "" ? $student["email"] : "-"); ?></div>
          </div>
        </div>

        <?php if (count($transcript["terms"]) === 0): ?>
          <div class="empty-box">No transcript data found for this student.</div>
        <?php else: ?>
          <?php foreach ($transcript["terms"] as $term): ?>
            <div class="term-block">
              <div class="term-head">
                <h3>
                  <?php echo transcriptH($term["label"]); ?>
                  <?php if (!empty($term["is_current"])): ?>
                    <span style="color:#64748b;font-size:15px;">(Current)</span>
                  <?php endif; ?>
                </h3>
                <div class="gpa-pill">
                  Semester GPA:
                  <strong><?php echo transcriptH(transcriptFormatNumber($term["semester_gpa"])); ?></strong>
                </div>
              </div>

              <table class="transcript-table">
                <thead>
                  <tr>
                    <th>Course Code</th>
                    <th>Course Name</th>
                    <th class="num">Credits</th>
                    <th class="num">Marks</th>
                    <th class="num">Grade</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($term["courses"] as $course): ?>
                    <tr>
                      <td><?php echo transcriptH($course["course_code"]); ?></td>
                      <td><?php echo transcriptH($course["course_name"]); ?></td>
                      <td class="num"><?php echo transcriptH(transcriptFormatNumber($course["credits"], 0)); ?></td>
                      <td class="num"><?php echo $course["marks"] !== null ? transcriptH(transcriptFormatNumber($course["marks"], 0) . "/100") : "-"; ?></td>
                      <td class="num">
                        <span class="grade-chip"><?php echo transcriptH($course["grade"] !== "" ? $course["grade"] : "-"); ?></span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endforeach; ?>

          <div class="summary-grid">
            <div class="summary-card">
              <div class="k">Total Credits Earned</div>
              <div class="v"><?php echo transcriptH(transcriptFormatNumber($transcript["total_credits_earned"], 0)); ?></div>
            </div>
            <div class="summary-card">
              <div class="k">Cumulative GPA (CGPA)</div>
              <div class="v primary"><?php echo transcriptH(transcriptFormatNumber($transcript["cgpa"])); ?></div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
</body>
</html>
