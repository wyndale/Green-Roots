<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

try {
    $barangay_id = $_GET['barangay_id'] ?? null;
    if (!$barangay_id) {
        echo json_encode(['error' => 'Barangay ID is required']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT ps.planting_site_id, ps.latitude, ps.longitude, ps.updated_at, b.name AS barangay_name 
        FROM planting_sites ps 
        JOIN barangays b ON ps.barangay_id = b.barangay_id 
        WHERE ps.barangay_id = :barangay_id 
        ORDER BY ps.updated_at DESC 
        LIMIT 1
    ");
    $stmt->execute(['barangay_id' => $barangay_id]);
    $planting_site = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$planting_site) {
        echo json_encode(['error' => 'No planting site data available for this barangay']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode($planting_site);
} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Unexpected error: ' . $e->getMessage()]);
}