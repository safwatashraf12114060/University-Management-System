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

sqlsrv_query($conn, "DELETE FROM $courseTable WHERE $courseIdCol = ?", [$courseId]);

header("Location: list.php");
exit();