<?php
$host = 'localhost';
$dbname = 'barbershop';
$username = 'root';
$password = '';

/* PHP timezone */
date_default_timezone_set('Asia/Manila');

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* MySQL timezone */
$conn->query("SET time_zone = '+08:00'");
?>