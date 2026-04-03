<?php
session_start();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/transcript_common.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

function pdfEscapeText($text) {
    $text = (string)$text;
    $text = str_replace("\\", "\\\\", $text);
    $text = str_replace("(", "\\(", $text);
    $text = str_replace(")", "\\)", $text);
    return preg_replace('/[^\x20-\x7E]/', '?', $text);
}

function buildPdfPages(array $pages) {
    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";

    $pageIds = [];
    $contentIds = [];
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
        $y = 800;
        foreach ($pageLines as $line) {
            $content .= "1 0 0 1 36 " . $y . " Tm (" . pdfEscapeText($line) . ") Tj\n";
            $y -= 14;
        }
        $content .= "ET";

        $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents " . $contentIds[$index] . " 0 R >>";
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

$studentId = (int)($_GET["student_id"] ?? 0);
if ($studentId <= 0) {
    $studentId = transcriptResolveStudentId($conn, (int)($_GET["id"] ?? 0));
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

$student = $transcript["student"];
$lines = [];
$lines[] = "University Management System";
$lines[] = "Official Academic Transcript";
$lines[] = "";
$lines[] = "Student ID: " . $transcript["student_code"];
$lines[] = "Student Name: " . (string)($student["student_name"] ?? "-");
$lines[] = "Department: " . (string)($student["department_name"] ?? "-");
$lines[] = "Email: " . (string)(($student["email"] ?? "") !== "" ? $student["email"] : "-");
$lines[] = str_repeat("-", 68);

foreach ($transcript["terms"] as $term) {
    $lines[] = $term["label"] . (!empty($term["is_current"]) ? " (Current)" : "");
    $lines[] = "Semester GPA: " . transcriptFormatNumber($term["semester_gpa"]);
    $lines[] = str_pad("Course Code", 14)
        . str_pad("Course Name", 28)
        . str_pad("Credits", 8)
        . str_pad("Marks", 8)
        . "Grade";

    foreach ($term["courses"] as $course) {
        $courseName = substr((string)$course["course_name"], 0, 27);
        $marks = $course["marks"] !== null ? transcriptFormatNumber($course["marks"], 0) . "/100" : "-";
        $lines[] = str_pad((string)$course["course_code"], 14)
            . str_pad($courseName, 28)
            . str_pad(transcriptFormatNumber($course["credits"], 0), 8)
            . str_pad($marks, 8)
            . (string)($course["grade"] !== "" ? $course["grade"] : "-");
    }

    $lines[] = "";
}

$lines[] = str_repeat("-", 68);
$lines[] = "Total Credits Earned: " . transcriptFormatNumber($transcript["total_credits_earned"], 0);
$lines[] = "Cumulative GPA (CGPA): " . transcriptFormatNumber($transcript["cgpa"]);

$pages = [];
$chunkSize = 52;
for ($i = 0; $i < count($lines); $i += $chunkSize) {
    $pages[] = array_slice($lines, $i, $chunkSize);
}

$pdf = buildPdfPages($pages);
$filename = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$transcript["student_code"]) . "_transcript.pdf";

header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
header("Content-Length: " . strlen($pdf));
echo $pdf;
exit();
