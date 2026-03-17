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

require_once __DIR__ . "/../vendor/autoload.php";

use Dompdf\Dompdf;
use Dompdf\Options;

function h($v) {
    return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8");
}

$studentTable = "dbo.STUDENT";
$courseTable = "dbo.COURSE";
$deptTable = "dbo.DEPARTMENT";
$enrollTable = "dbo.ENROLLMENT";

$q = trim($_GET["q"] ?? "");
$term = trim($_GET["term"] ?? "");
$year = trim($_GET["year"] ?? "");
$studentSemester = trim($_GET["student_semester"] ?? "");

$params = [];
$where = "1=1";

if ($term !== "") {
    $where .= " AND e.semester = ?";
    $params[] = $term;
}
if ($year !== "") {
    $where .= " AND e.year = ?";
    $params[] = (int)$year;
}
if ($studentSemester !== "") {
    $where .= " AND s.semester = ?";
    $params[] = (int)$studentSemester;
}
if ($q !== "") {
    $where .= " AND (
        CONVERT(VARCHAR(50), s.student_id) LIKE ? OR
        s.name LIKE ? OR
        d.name LIKE ? OR
        c.course_code LIKE ? OR
        c.course_name LIKE ?
    )";
    $like = "%" . $q . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql = "
    SELECT
      s.student_id,
      s.name AS student_name,
      d.name AS department_name,
      c.course_code,
      c.course_name,
      c.credit_hours,
      e.semester AS term,
      e.year,
      s.semester AS student_semester
    FROM $enrollTable e
    JOIN $studentTable s ON s.student_id = e.student_id
    JOIN $courseTable c ON c.course_id = e.course_id
    LEFT JOIN $deptTable d ON d.dept_id = s.dept_id
    WHERE $where
    ORDER BY e.year DESC, e.semester DESC, s.name ASC, c.course_name ASC
";

$stmt = sqlsrv_query($conn, $sql, $params);
$rows = [];
if ($stmt !== false) {
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $r;
    }
    sqlsrv_free_stmt($stmt);
}

$generatedOn = date("d M Y, h:i A");

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    body{
      font-family: DejaVu Sans, sans-serif;
      font-size: 12px;
      color: #111827;
    }
    h1{
      margin: 0 0 5px;
      font-size: 24px;
    }
    h2{
      margin: 0 0 16px;
      font-size: 16px;
      color: #475569;
    }
    .meta{
      margin-bottom: 14px;
    }
    .meta p{
      margin: 4px 0;
    }
    table{
      width: 100%;
      border-collapse: collapse;
      margin-top: 12px;
    }
    th, td{
      border: 1px solid #d1d5db;
      padding: 8px;
      text-align: left;
      vertical-align: top;
      font-size: 11px;
    }
    th{
      background: #f3f4f6;
    }
    .footer{
      margin-top: 14px;
      font-weight: bold;
    }
  </style>
</head>
<body>
  <h1>University Management System</h1>
  <h2>Enrollment Report</h2>

  <div class="meta">
    <p><strong>Generated on:</strong> <?php echo h($generatedOn); ?></p>
    <p><strong>Search:</strong> <?php echo h($q !== "" ? $q : "All"); ?></p>
    <p><strong>Term:</strong> <?php echo h($term !== "" ? $term : "All"); ?></p>
    <p><strong>Year:</strong> <?php echo h($year !== "" ? $year : "All"); ?></p>
    <p><strong>Student Semester:</strong> <?php echo h($studentSemester !== "" ? $studentSemester : "All"); ?></p>
    <p><strong>Credits:</strong> All</p>
  </div>

  <table>
    <thead>
      <tr>
        <th>Student ID</th>
        <th>Student Name</th>
        <th>Department</th>
        <th>Course Code</th>
        <th>Course Name</th>
        <th>Credits</th>
        <th>Term</th>
        <th>Year</th>
        <th>Student Semester</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="9">No enrollments found.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php echo h($r["student_id"] ?? ""); ?></td>
            <td><?php echo h($r["student_name"] ?? ""); ?></td>
            <td><?php echo h($r["department_name"] ?? ""); ?></td>
            <td><?php echo h($r["course_code"] ?? ""); ?></td>
            <td><?php echo h($r["course_name"] ?? ""); ?></td>
            <td><?php echo h($r["credit_hours"] ?? ""); ?></td>
            <td><?php echo h($r["term"] ?? ""); ?></td>
            <td><?php echo h($r["year"] ?? ""); ?></td>
            <td><?php echo h($r["student_semester"] ?? ""); ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="footer">
    Total records: <?php echo count($rows); ?>
  </div>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream("enrollment_report.pdf", ["Attachment" => true]);
exit();