<?php
session_start();

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$name  = $_SESSION["name"] ?? "User";
$email = $_SESSION["email"] ?? "";

$title = "Home";
include "partials/header.php";
?>

<div class="page">
    <div class="card">
        <h2>Welcome, <?php echo htmlspecialchars($name); ?> 🎉</h2>
        <p>Email: <?php echo htmlspecialchars($email); ?></p>
        <br>
        <p>Logged in successfully!</p>
    </div>
</div>

<?php include "partials/footer.php"; ?>