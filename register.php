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
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name  = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $pass  = $_POST["password"] ?? "";

    if ($name === "" || $email === "" || $pass === "") {
        $error = "Fillup all data properly.";
    } else {

        // Duplicate email check
        $checkSql = "SELECT id FROM users WHERE email = ?";
        $checkStmt = sqlsrv_query($conn, $checkSql, [$email]);

        if ($checkStmt && sqlsrv_has_rows($checkStmt)) {
            $error = "Email already exists।";
        } else {

            $hashed = password_hash($pass, PASSWORD_DEFAULT);

            $sql = "INSERT INTO users (name, email, password) VALUES (?, ?, ?)";
            $stmt = sqlsrv_query($conn, $sql, [$name, $email, $hashed]);

            if ($stmt) {
                $success = "Registration successful! Login ";
            } else {
                $error = "Registration failed.";
            }
        }
    }
}

$title = "Register";
include "partials/header.php";
?>

<div class="container">
  <div class="card">
    <h2>Register</h2>

    <?php if ($error): ?>
        <div class="alert"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div style="background:#e6ffed; border:1px solid #a3f0c1; padding:10px; border-radius:8px; margin-bottom:12px; color:#056b2d;">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <label>Name</label>
        <input type="text" name="name" required>

        <label>Email</label>
        <input type="email" name="email" required>

        <label>Password</label>
        <input type="password" name="password" required>

        <button class="btn" type="submit">Register</button>
    </form>

    <div class="link">
        already have an accoount? <a href="login.php">Login</a>
    </div>
  </div>
</div>

<?php include "partials/footer.php"; ?>