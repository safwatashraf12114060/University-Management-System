<?php
$serverName = "DESKTOP-DRIA144\\SQLEXPRESS";

$connectionOptions = array(
    "Database" => "university_db",
    "Uid" => "sa",
    "PWD" => "123456",   // sa password যা তুমি set করেছো
    "TrustServerCertificate" => true
);

$conn = sqlsrv_connect($serverName, $connectionOptions);

if($conn === false) {
    die(print_r(sqlsrv_errors(), true));
} else {
    echo "Connected Successfully!";
}
?>
