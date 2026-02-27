<?php
// header.php শুধু HTML layout
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="assets/style.css">
  <title><?php echo isset($title) ? htmlspecialchars($title) : "University System"; ?></title>
</head>
<body>
<div class="topbar">
  <div class="wrap">
    <div>University Management System</div>
    <div>
      <?php if (isset($_SESSION["user_id"])): ?>
        <a href="home.php">Home</a> &nbsp; | &nbsp;
        <a href="logout.php">Logout</a>
      <?php else: ?>
        <a href="login.php">Login</a> &nbsp; | &nbsp;
        <a href="register.php">Register</a>
      <?php endif; ?>
    </div>
  </div>
</div>