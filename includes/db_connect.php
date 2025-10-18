<?php


$servername = "localhost";
$username = "u343300215_root";        
$password = "Ss20161084@";            
$dbname = "u343300215_hose28"; 

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("數據庫連接失敗: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>