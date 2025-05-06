<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['submission_id']) || !isset($input['status'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit;
    }

    $submission_id = $input['submission_id'];
    $status = $input['status'];
    $validated_by = $input['validated_by'] ?? null;
    $validated_at = $input['validated_at'] ?? null;
    $rejection_reason = $input['rejection_reason'] ?? null;
    $user_id = $input['user_id'] ?? null;
    $eco_points = isset($input['eco_points']) ? (int)$input['eco_points'] : 0;
    $trees_planted = isset($input['trees_planted']) ? (int)$input['trees_planted'] : 0;

    if (!in_array($status, ['approved', 'rejected'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        exit;
    }

    $pdo->beginTransaction();

    // Update submission status
    $stmt = $pdo->prepare("
        UPDATE submissions 
        SET status = :status, 
            validated_by = :validated_by, 
            validated_at = :validated_at, 
            rejection_reason = :rejection_reason 
        WHERE submission_id = :submission_id
    ");
    $stmt->execute([
        ':status' => $status,
        ':validated_by' => $validated_by,
        ':validated_at' => $validated_at,
        ':rejection_reason' => $rejection_reason,
        ':submission_id' => $submission_id
    ]);

    // If approved, update user's eco_points and trees_planted
    if ($status === 'approved' && $user_id && $eco_points > 0) {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET eco_points = eco_points + :eco_points, 
                trees_planted = trees_planted + :trees_planted 
            WHERE user_id = :user_id
        ");
        $stmt->execute([
            ':eco_points' => $eco_points,
            ':trees_planted' => $trees_planted,
            ':user_id' => $user_id
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>