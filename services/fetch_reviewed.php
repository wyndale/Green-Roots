<?php
session_start();
require_once '../includes/config.php';

// Restrict access to eco_validator role only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'eco_validator') {
    if (isset($_SESSION['user_id']) && ($_SESSION['role'] === 'user' || $_SESSION['role'] === 'admin')) {
        header('Location: ../access/access_denied.php');
        exit;
    }
    header('Location: ../views/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
try {
    $barangay_id = $_GET['barangay_id'] ?? null;
    $status = $_GET['status'] ?? 'all';
    $search = $_GET['search'] ?? '';
    if (!$barangay_id) {
        echo json_encode(['error' => 'Barangay ID is required']);
        exit;
    }

    $query = "
        SELECT s.submission_id, s.user_id, s.trees_planted, s.photo_data, s.latitude, s.longitude, s.submitted_at, s.status,
               s.submission_notes, s.flagged, s.rejection_reason, u.username
        FROM submissions s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.barangay_id = :barangay_id AND s.status IN ('approved', 'rejected')
        AND (:search = '' OR u.username LIKE :search OR u.email LIKE :search)
    ";
    if ($status !== 'all') {
        $query .= " AND s.status = :status";
    }
    $query .= " GROUP BY s.submission_id ORDER BY s.submitted_at DESC";
    
    $stmt = $pdo->prepare($query);
    $params = [
        ':barangay_id' => $barangay_id,
        ':search' => $search ? "%$search%" : ''
    ];
    if ($status !== 'all') {
        $params[':status'] = $status;
    }
    $stmt->execute($params);
    $reviewed_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $base_points_per_tree = 50;
    foreach ($reviewed_submissions as &$submission) {
        $total_base_points = $submission['trees_planted'] * $base_points_per_tree;
        $buffer_multiplier = 1.2;
        $reward_multiplier = 1.1;
        $buffered_points = $total_base_points * $buffer_multiplier;
        $eco_points = $buffered_points * $reward_multiplier;
        $submission['eco_points'] = round($eco_points);
        if ($submission['photo_data']) {
            $submission['photo_data'] = base64_encode($submission['photo_data']);
        }
    }

    header('Content-Type: application/json');
    echo json_encode($reviewed_submissions);
} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Unexpected error: ' . $e->getMessage()]);
}
?>