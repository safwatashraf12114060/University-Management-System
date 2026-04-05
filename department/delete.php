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

$dept_id = (int)($_GET["dept_id"] ?? 0);
if ($dept_id <= 0) {
    header("Location: list.php");
    exit();
}

$departmentLabel = umsFetchActivityEntityLabel(
    $conn,
    "dbo.DEPARTMENT",
    "dept_id",
    $dept_id,
    ["department_name", "dept_name", "name"]
);

$stmt = sqlsrv_query($conn, "DELETE FROM DEPARTMENT WHERE dept_id = ?", [$dept_id]);

if ($stmt === false) {
    $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
    $message = umsFriendlyDbMessage("delete", "department", $errors);
    umsSetFlash("departments", "error", $message);
} else {
    sqlsrv_free_stmt($stmt);
    $departmentMessage = $departmentLabel !== ""
        ? "Department '" . $departmentLabel . "' was deleted."
        : "Department ID " . $dept_id . " was deleted.";
    umsLogActivity($conn, "department_delete", $departmentMessage);
    umsSetFlash("departments", "success", "Department deleted successfully.");
}

header("Location: list.php");
exit();
