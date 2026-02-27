<?php
$serverName = "DESKTOP-GLIUEAG\\SQLEXPRESS"; 
$connectionOptions = [
    "Database" => "university_db",
    "Uid" => "sa",
    "PWD" => "123456",   
    "LoginTimeout" => 5,
    "Encrypt" => 0,
    "TrustServerCertificate" => 1
];

$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    die(print_r(sqlsrv_errors(), true));
}
?>