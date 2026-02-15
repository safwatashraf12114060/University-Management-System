<?php
include 'db.php';

if(isset($_POST['register'])) {

    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $sql = "INSERT INTO users (name, email, password) VALUES (?, ?, ?)";
    $params = array($name, $email, $password);

    $stmt = sqlsrv_query($conn, $sql, $params);

    if($stmt) {
        echo "Registration Successful!";
    } else {
        echo "Error!";
    }
}
?>

<h2>Register</h2>
<form method="POST">
    Name: <input type="text" name="name" required><br><br>
    Email: <input type="email" name="email" required><br><br>
    Password: <input type="password" name="password" required><br><br>
    <button type="submit" name="register">Register</button>
</form>

<a href="login.php">Login</a>
