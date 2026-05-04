<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') { header("Location: index.php"); exit(); } 

$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem"); 
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error); 
$conn->query("SET time_zone = '+08:00'");

if (!isset($_GET['ticket_id'])) {
    echo json_encode(['error' => 'Ticket ID missing']);
    exit;
}
header('Content-Type: application/json');

$ticketId = intval($_GET['ticket_id']);

$sql = "
    SELECT 
        t.control_number,
        t.created_at,
        t.issue,
        t.assigned_at,
        t.accepted_at,
        t.resolved_at,
        t.closed_at,
        t.ended_at,
        t.status,
        t.decline_reason,
        u.name AS user_name,      -- ticket owner
        m.name AS manager_name    -- support staff / handler
    FROM tickets t
    LEFT JOIN users u 
        ON t.user_id = u.user_id
    LEFT JOIN users m 
        ON t.manager_id = m.user_id
    WHERE t.ticket_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $ticketId);
$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        'control_number'      => $row['control_number'],
        'created_at'          => $row['created_at'],
        'assigned_at'         => $row['assigned_at'],
        'accepted_at'         => $row['accepted_at'],
        'resolved_at'         => $row['resolved_at'],
        'issue'               => $row['issue'],
        'closed_at'           => $row['closed_at'],
        'ended_at'            => $row['ended_at'],          // for cancelled / declined only
        'status'              => $row['status'],
        'decline_reason'      => $row['decline_reason'],
        'support_staff_name'  => $row['manager_name']
    ]);
} else {
    echo json_encode([
        'error' => 'Ticket not found'
    ]);
}

?>