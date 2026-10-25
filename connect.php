<?php
// definisi nilai parameter server
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "db5a";

//create connection
//$conn = mysqli_connect("localhost", "root", "");
$conn = mysqli_connect($servername, $username, $password, $dbname);

//check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
mysqli_set_charset($conn, "utf8mb4");
//echo "Connected Succesfully";

?>