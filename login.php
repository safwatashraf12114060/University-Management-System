<?php
session_start();
require "db.php";

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (isset($_SESSION["user_id"])) {
  header("Location: home.php");
  exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $email = trim($_POST["email"] ?? "");
  $pass  = $_POST["password"] ?? "";

  $sql = "SELECT id, name, email, password FROM users WHERE email = ?";
  $stmt = sqlsrv_query($conn, $sql, [$email]);

  if ($stmt && sqlsrv_has_rows($stmt)) {
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row && password_verify($pass, $row["password"])) {
      $_SESSION["user_id"] = $row["id"];
      $_SESSION["name"] = $row["name"];
      $_SESSION["email"] = $row["email"];
      header("Location: home.php");
      exit();
    }
    $error = "Password Error।";
  } else {
    $error = "User not found।";
  }
}

$title = "Login";
include "partials/header.php";
?>
<div class="container">
  <div class="card">
    <h2>Login</h2>

    <?php if ($error): ?>
      <div class="alert"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post">
      <label>Email</label>
      <input type="email" name="email" required>

      <label>Password</label>
      <input type="password" name="password" required>

      <button class="btn" type="submit">Login</button>
    </form>

    <div class="link">
      new account? <a href="register.php">Register</a>
    </div>
  </div>
</div>
<?php include "partials/footer.php"; ?>