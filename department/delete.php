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

$dept_id = (int)($_GET["dept_id"] ?? 0);
if ($dept_id <= 0) {
    header("Location: list.php");
    exit();
}

sqlsrv_query($conn, "DELETE FROM DEPARTMENT WHERE dept_id = ?", [$dept_id]);

header("Location: list.php");
exit();