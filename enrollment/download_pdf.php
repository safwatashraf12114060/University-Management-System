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
    return htmlspecialchars((string)($v ?? ""), ENT_QUOTES, "UTF-8");
}

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return isset($row["len"]) && $row["len"] !== null;
}

function pdfEscape($text) {
    $text = (string)$text;
    $text = str_replace("\\", "\\\\", $text);
    $text = str_replace("(", "\\(", $text);
    $text = str_replace(")", "\\)", $text);
    return preg_replace('/[^\x20-\x7E]/', '?', $text);
}

function buildSimplePdf(array $pages) {
    $objects = [];

    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";

    $pageIds = [];
    $contentIds = [];
    $fontId = 3;
    $nextId = 4;

    foreach ($pages as $page) {
        $contentIds[] = $nextId++;
        $pageIds[] = $nextId++;
    }

    $kids = [];
    foreach ($pageIds as $pageId) {
        $kids[] = $pageId . " 0 R";
    }
    $objects[] = "<< /Type /Pages /Kids [" . implode(" ", $kids) . "] /Count " . count($pageIds) . " >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>";

    foreach ($pages as $index => $pageLines) {
        $content = "BT\n/F1 9 Tf\n";
        $y = 560;
        foreach ($pageLines as $line) {
            $content .= "1 0 0 1 24 " . $y . " Tm (" . pdfEscape($line) . ") Tj\n";
            $y -= 12;
        }
        $content .= "ET";

        $stream = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $objects[] = $stream;

        $pageObject = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents " . $contentIds[$index] . " 0 R >>";
        $objects[] = $pageObject;
    }

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

    return $pdf;
}

$studentTable = "dbo.STUDENT";
$courseTable = "dbo.COURSE";
$deptTable = "dbo.DEPARTMENT";
$enrollTable = "dbo.ENROLLMENT";

$studentNameCol = "name";
if (colExists($conn, $studentTable, "student_name")) $studentNameCol = "student_name";
if (colExists($conn, $studentTable, "full_name")) $studentNameCol = "full_name";

$deptNameCol = "name";
if (colExists($conn, $deptTable, "department_name")) $deptNameCol = "department_name";
if (colExists($conn, $deptTable, "dept_name")) $deptNameCol = "dept_name";

$courseNameCol = "course_name";
if (colExists($conn, $courseTable, "name")) $courseNameCol = "name";
if (colExists($conn, $courseTable, "title")) $courseNameCol = "title";

$courseCodeCol = null;
foreach (["course_code", "code"] as $c) {
    if (colExists($conn, $courseTable, $c)) {
        $courseCodeCol = $c;
        break;
    }
}

$creditCol = "credit_hours";
if (colExists($conn, $courseTable, "credits")) $creditCol = "credits";
if (colExists($conn, $courseTable, "credit")) $creditCol = "credit";

$hasYear = colExists($conn, $enrollTable, "year");
$semesterIsNumeric = true;
$semTypeStmt = sqlsrv_query($conn, "
    SELECT DATA_TYPE AS type_name
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'ENROLLMENT' AND COLUMN_NAME = 'semester'
");
if ($semTypeStmt !== false) {
    $semTypeRow = sqlsrv_fetch_array($semTypeStmt, SQLSRV_FETCH_ASSOC);
    $semesterIsNumeric = in_array(strtolower((string)($semTypeRow["type_name"] ?? "")), ["int", "smallint", "tinyint", "bigint"], true);
    sqlsrv_free_stmt($semTypeStmt);
}

$q = trim($_GET["q"] ?? "");
$term = trim($_GET["term"] ?? "");
$year = trim($_GET["year"] ?? "");
$studentSemester = trim($_GET["student_semester"] ?? "");

$params = [];
$where = "1=1";

if ($term !== "") {
    $where .= " AND e.semester = ?";
    $params[] = $semesterIsNumeric ? (int)$term : $term;
}
if ($year !== "" && $hasYear) {
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
        s.$studentNameCol LIKE ? OR
        d.$deptNameCol LIKE ? OR
        " . ($courseCodeCol ? "c.$courseCodeCol LIKE ? OR" : "") . "
        c.$courseNameCol LIKE ?
    )";
    $like = "%" . $q . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    if ($courseCodeCol) $params[] = $like;
    $params[] = $like;
}

$sql = "
    SELECT
      s.student_id,
      s.$studentNameCol AS student_name,
      d.$deptNameCol AS department_name,
      " . ($courseCodeCol ? "c.$courseCodeCol" : "CONVERT(VARCHAR(50), c.course_id)") . " AS course_code,
      c.$courseNameCol AS course_name,
      c.$creditCol AS credit_hours,
      e.semester AS term,
      " . ($hasYear ? "e.year" : "NULL AS year") . ",
      s.semester AS student_semester
    FROM $enrollTable e
    JOIN $studentTable s ON s.student_id = e.student_id
    JOIN $courseTable c ON c.course_id = e.course_id
    LEFT JOIN $deptTable d ON d.dept_id = s.dept_id
    WHERE $where
    ORDER BY " . ($hasYear ? "e.year DESC," : "") . " e.semester DESC, s.$studentNameCol ASC, c.$courseNameCol ASC
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
$pages = [];
$pageLines = [];

$pageLines[] = "University Management System";
$pageLines[] = "Enrollment Report";
$pageLines[] = "Generated on: " . $generatedOn;
$pageLines[] = "Search: " . ($q !== "" ? $q : "All");
$pageLines[] = ($semesterIsNumeric ? "Semester: " : "Term: ") . ($term !== "" ? $term : "All");
$pageLines[] = "Year: " . ($year !== "" ? $year : "All");
$pageLines[] = "Student Semester: " . ($studentSemester !== "" ? $studentSemester : "All");
$pageLines[] = str_repeat("-", 108);
$pageLines[] = sprintf(
    "%-8s %-20s %-16s %-10s %-20s %-4s %-8s %-6s %-6s",
    "Stu ID",
    "Student Name",
    "Department",
    "Code",
    "Course Name",
    "Cr",
    "Term",
    "Year",
    "Sem"
);
$pageLines[] = str_repeat("-", 108);

foreach ($rows as $r) {
    $line = sprintf(
        "%-8s %-20s %-16s %-10s %-20s %-4s %-8s %-6s %-6s",
        substr((string)($r["student_id"] ?? ""), 0, 8),
        substr((string)($r["student_name"] ?? ""), 0, 20),
        substr((string)($r["department_name"] ?? ""), 0, 16),
        substr((string)($r["course_code"] ?? ""), 0, 10),
        substr((string)($r["course_name"] ?? ""), 0, 20),
        substr((string)($r["credit_hours"] ?? ""), 0, 4),
        substr((string)($r["term"] ?? ""), 0, 8),
        substr((string)($r["year"] ?? ""), 0, 6),
        substr((string)($r["student_semester"] ?? ""), 0, 6)
    );
    $pageLines[] = $line;

    if (count($pageLines) >= 42) {
        $pages[] = $pageLines;
        $pageLines = ["Enrollment Report (continued)", str_repeat("-", 108)];
    }
}

$pageLines[] = str_repeat("-", 108);
$pageLines[] = "Total records: " . count($rows);
$pages[] = $pageLines;

$pdf = buildSimplePdf($pages);

header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=\"enrollment_report.pdf\"");
header("Content-Length: " . strlen($pdf));
echo $pdf;
exit();
