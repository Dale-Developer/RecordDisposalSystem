<?php
$host="localhost";
$user= "root";
$password= "";
$db="lspu_record_disposal_system_db";
$conn = new mysqli($host,$user,$password,$db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>