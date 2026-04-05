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

$teacher_id = (int)($_GET["teacher_id"] ?? 0);
if ($teacher_id <= 0) {
    header("Location: list.php");
    exit();
}

$teacherLabel = umsFetchActivityEntityLabel(
    $conn,
    "dbo.TEACHER",
    "teacher_id",
    $teacher_id,
    ["teacher_name", "name", "full_name"]
);

$del = sqlsrv_query($conn, "DELETE FROM TEACHER WHERE teacher_id = ?", [$teacher_id]);

if ($del === false) {
    umsSetFlash("teachers", "error", umsFriendlyDbMessage("delete", "teacher", sqlsrv_errors(SQLSRV_ERR_ERRORS)));
} else {
    sqlsrv_free_stmt($del);
    $teacherMessage = $teacherLabel !== ""
        ? "Teacher '" . $teacherLabel . "' was deleted."
        : "Teacher ID " . $teacher_id . " was deleted.";
    umsLogActivity($conn, "teacher_delete", $teacherMessage);
    umsSetFlash("teachers", "success", "Teacher deleted successfully.");
}

header("Location: list.php");
exit();
