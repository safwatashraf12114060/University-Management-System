<?php
session_start();
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../partials/feedback.php";
require_once __DIR__ . "/../partials/activity_log.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

$student_id = (int)($_GET["student_id"] ?? 0);
if ($student_id <= 0) {
    header("Location: list.php");
    exit();
}

$studentLabel = umsFetchActivityEntityLabel(
    $conn,
    "dbo.STUDENT",
    "student_id",
    $student_id,
    ["student_name", "name", "full_name"]
);

$stmt = sqlsrv_query($conn, "DELETE FROM STUDENT WHERE student_id = ?", [$student_id]);

if ($stmt === false) {
    umsSetFlash("students", "error", umsFriendlyDbMessage("delete", "student", sqlsrv_errors(SQLSRV_ERR_ERRORS)));
} else {
    sqlsrv_free_stmt($stmt);
    $studentMessage = $studentLabel !== ""
        ? "Student '" . $studentLabel . "' was deleted."
        : "Student ID " . $student_id . " was deleted.";
    umsLogActivity($conn, "student_delete", $studentMessage);
    umsSetFlash("students", "success", "Student deleted successfully.");
}

header("Location: list.php");
exit();
