<?php
// delete_users.php
// Connect to the database
$conn = new mysqli(
    "localhost", 
    "u248040635_cedy", 
    "(April_23_2004)!!!!", 
    "u248040635_ticketsystem"
);
$conn->query("SET time_zone = '+08:00'");

// Check connection
if ($conn->connect_error) {
    die(json_encode([
        'success' => false,
        'message' => "Connection failed: " . $conn->connect_error
    ]));
}

// Set response type to JSON
header('Content-Type: application/json');

// Allow only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid request method.'
    ]);
    exit;
}

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['user_ids']) || !is_array($input['user_ids']) || empty($input['user_ids'])) {
    echo json_encode([
        'success' => false, 
        'message' => 'No users selected.'
    ]);
    exit;
}

// Sanitize user IDs (ensure integers only)
$user_ids = array_map('intval', $input['user_ids']);

// Debug log: show which IDs will be deleted
error_log("Deleting users: " . implode(', ', $user_ids));

// Prepare SQL
$ids_str = implode(',', $user_ids);
$sql = "DELETE FROM users WHERE user_id IN ($ids_str) AND status='active'";

// Execute deletion
if ($conn->query($sql)) {
    error_log("Deleted users successfully: " . implode(', ', $user_ids));
    echo json_encode(['success' => true]);
} else {
    error_log("Delete failed: " . $conn->error);
    echo json_encode([
        'success' => false, 
        'message' => $conn->error
    ]);
}

// Close the connection
$conn->close();
?>
