<?php
session_start();
require "db.php";

/**
 * Cache disable (logout এর পরে back দিলেও যেন protected page cache থেকে না আসে)
 */
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

/**
 * যদি আগেই logged in থাকে, সরাসরি home এ পাঠাও
 */
if (isset($_SESSION["user_id"])) {
    header("Location: home.php");
    exit();
}

$error = "";

/**
 * Login submit হলে
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $pass  = $_POST["password"] ?? "";

    if ($email === "" || $pass === "") {
        $error = "Enter Your Email and Password.";
    } else {

        $sql = "SELECT id, name, email, password FROM users WHERE email = ?";
        $params = [$email];

        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt === false) {
            $error = "Database error: " . print_r(sqlsrv_errors(), true);
        } else if (!sqlsrv_has_rows($stmt)) {
            $error = "User খুঁজে পাওয়া যায়নি।";
        } else {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

            // password verify
            if ($row && password_verify($pass, $row["password"])) {
                $_SESSION["user_id"] = $row["id"];
                $_SESSION["name"]    = $row["name"];
                $_SESSION["email"]   = $row["email"];

                header("Location: home.php");
                exit();
            } else {
                $error = "Wrong Password!";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login</title>
</head>
<body>
    <h2>Login</h2>

    <?php if ($error !== ""): ?>
        <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form method="post" action="">
        <label>Email:</label><br>
        <input type="email" name="email" required><br><br>

        <label>Password:</label><br>
        <input type="password" name="password" required><br><br>

        <button type="submit">Login</button>
    </form>

    <p>নতুন account? <a href="register.php">Register</a></p>
</body>
</html>