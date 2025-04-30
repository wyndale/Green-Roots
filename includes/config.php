<?php
// Database configuration
$host = 'localhost';
$dbname = 'greenroots_db';
$username = 'root'; // Default XAMPP username
$password = ')l6..O[btkCXpBWd';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // echo "Connected successfully";
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    die();
}
?>