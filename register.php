<?php
session_start();
require "db.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name  = trim($_POST["name"]);
    $email = trim($_POST["email"]);
    $pass  = $_POST["password"];

    $hashed = password_hash($pass, PASSWORD_DEFAULT);

    $sql = "INSERT INTO users (name, email, password) VALUES (?, ?, ?)";
    $params = [$name, $email, $hashed];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt) {
        header("Location: login.php");
        exit();
    } else {
        echo "Registration failed: ";
        print_r(sqlsrv_errors());
    }
}
?>

<form method="post">
    <input name="name" required placeholder="Name">
    <input type="email" name="email" required placeholder="Email">
    <input type="password" name="password" required placeholder="Password">
    <button type="submit">Register</button>
</form>