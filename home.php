<?php
session_start();

// Debug (এখন কাজ ঠিক না হওয়া পর্যন্ত ON রাখো)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Cache disable
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Auth guard
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$name  = $_SESSION["name"]  ?? "User";
$email = $_SESSION["email"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Home</title>
</head>
<body>
    <h2>Welcome <?php echo htmlspecialchars($name); ?></h2>
    <?php if ($email !== ""): ?>
        <p>Email: <?php echo htmlspecialchars($email); ?></p>
    <?php endif; ?>

    <p><a href="logout.php">Logout</a></p>
</body>
</html>
irn-mxns-fah