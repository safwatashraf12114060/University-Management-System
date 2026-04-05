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

function colExists($conn, $table, $column) {
    $stmt = sqlsrv_query($conn, "SELECT COL_LENGTH(?, ?) AS len", [$table, $column]);
    if ($stmt === false) return false;

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return isset($row["len"]) && $row["len"] !== null;
}

$courseTable = "dbo.COURSE";

$courseIdCol = colExists($conn, $courseTable, "course_id") ? "course_id" : "id";

$courseId = (int)($_GET["id"] ?? ($_GET["course_id"] ?? 0));
if ($courseId <= 0) {
    header("Location: list.php");
    exit();
}

$courseLabel = umsFetchActivityEntityLabel(
    $conn,
    $courseTable,
    $courseIdCol,
    $courseId,
    ["course_name", "title", "name", "course_code", "code"]
);

$stmt = sqlsrv_query($conn, "DELETE FROM $courseTable WHERE $courseIdCol = ?", [$courseId]);

if ($stmt === false) {
    umsSetFlash("courses", "error", umsFriendlyDbMessage("delete", "course", sqlsrv_errors(SQLSRV_ERR_ERRORS)));
} else {
    sqlsrv_free_stmt($stmt);
    $courseMessage = $courseLabel !== ""
        ? "Course '" . $courseLabel . "' was deleted."
        : "Course ID " . $courseId . " was deleted.";
    umsLogActivity($conn, "course_delete", $courseMessage);
    umsSetFlash("courses", "success", "Course deleted successfully.");
}

header("Location: list.php");
exit();
