<?php
session_start();
include 'db.php';

if(isset($_POST['login'])) {

    $email = $_POST['email'];
    $password = $_POST['password'];

    $sql = "SELECT * FROM users WHERE email = ?";
    $params = array($email);
    $stmt = sqlsrv_query($conn, $sql, $params);

    if($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {

        if(password_verify($password, $row['password'])) {
            $_SESSION['user'] = $row['name'];
            header("Location: home.php");
        } else {
            echo "Wrong Password!";
        }
    } else {
        echo "User not found!";
    }
}
?>

<h2>Login</h2>
<form method="POST">
    Email: <input type="email" name="email" required><br><br>
    Password: <input type="password" name="password" required><br><br>
    <button type="submit" name="login">Login</button>
</form>
