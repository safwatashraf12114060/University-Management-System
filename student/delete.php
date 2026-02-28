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

$student_id = (int)($_GET["student_id"] ?? 0);
if ($student_id <= 0) {
    header("Location: list.php");
    exit();
}

sqlsrv_query($conn, "DELETE FROM STUDENT WHERE student_id = ?", [$student_id]);

header("Location: list.php");
exit();