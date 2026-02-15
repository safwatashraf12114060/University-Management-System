<?php
session_start();

if(!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}
?>

<h1>Welcome to University Management System</h1>
<h3>Hello, <?php echo $_SESSION['user']; ?></h3>

<a href="logout.php">Logout</a>
