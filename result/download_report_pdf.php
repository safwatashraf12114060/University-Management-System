<?php
session_start();
require_once __DIR__ . "/../db.php";
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

$pages = [];
$pageLines = [];
$pageLines[] = "University Management System";
$pageLines[] = "Result Report";
$pageLines[] = "Generated on: " . $generatedOn;
$pageLines[] = "Search: " . ($q !== "" ? $q : "All");
$pageLines[] = "Semester: " . ($semester !== "" ? $semester : "All");
if ($hasYear) $pageLines[] = "Year: " . ($year !== "" ? $year : "All");
$pageLines[] = str_repeat("-", 104);
$pageLines[] = sprintf(
    "%-8s %-18s %-10s %-22s %-8s %-8s %-16s",
    "Stu ID",
    "Student Name",
    "Code",
    "Course Name",
    "Marks",
    "Grade",
    "Semester"
);
$pageLines[] = str_repeat("-", 104);

foreach ($rows as $r) {
    $term = trim(((string)($r["semester"] ?? "")) . " " . ((string)($r["year"] ?? "")));
    $pageLines[] = sprintf(
        "%-8s %-18s %-10s %-22s %-8s %-8s %-16s",
        substr((string)($r["student_code"] ?? ""), 0, 8),
        substr((string)($r["student_name"] ?? ""), 0, 18),
        substr((string)($r["course_code"] ?? ""), 0, 10),
        substr((string)($r["course_name"] ?? ""), 0, 22),
        substr(((string)($r["marks"] ?? "")) . "/100", 0, 8),
        substr((string)($r["grade"] ?? ""), 0, 8),
        substr($term, 0, 16)
    );

    if (count($pageLines) >= 42) {
        $pages[] = $pageLines;
        $pageLines = ["Result Report (continued)", str_repeat("-", 104)];
    }
}

$pageLines[] = str_repeat("-", 104);
$pageLines[] = "Total records: " . count($rows);
$pages[] = $pageLines;

$pdf = resultReportBuildSimplePdf($pages);

header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=\"result_report.pdf\"");
header("Content-Length: " . strlen($pdf));
echo $pdf;
exit();
