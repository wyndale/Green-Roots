<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

$level = $_GET['level'] ?? '';
$parent = $_GET['parent'] ?? '';
$parentType = $_GET['parentType'] ?? 'province';

try {
    if ($level === 'regions') {
        $stmt = $pdo->query("SELECT DISTINCT region FROM barangays ORDER BY region");
        $data = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($data);
    } elseif ($level === 'provinces') {
        $stmt = $pdo->prepare("SELECT DISTINCT province FROM barangays WHERE region = ? AND province != '' ORDER BY province");
        $stmt->execute([$parent]);
        $data = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($data);
    } elseif ($level === 'cities') {
        if ($parentType === 'region') {
            $stmt = $pdo->prepare("SELECT DISTINCT city FROM barangays WHERE region = ? AND province = '' ORDER BY city");
            $stmt->execute([$parent]);
        } else {
            $stmt = $pdo->prepare("SELECT DISTINCT city FROM barangays WHERE province = ? ORDER BY city");
            $stmt->execute([$parent]);
        }
        $data = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($data);
    } elseif ($level === 'barangays') {
        $stmt = $pdo->prepare("SELECT barangay_id, name FROM barangays WHERE city = ? ORDER BY name");
        $stmt->execute([$parent]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($data);
    } else {
        echo json_encode(['error' => 'Invalid request']);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error']);
}
?>