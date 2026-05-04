<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') { header("Location: index.php"); exit(); } 

$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem"); 
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error); 

// set session timezone to Philippine time
$conn->query("SET time_zone = '+08:00'");

$error = '';


$user_id   = intval($_SESSION['user_id']);
$user_name = $_SESSION['name'] ?? 'Staff';

$success = '';
$error   = '';
$notfunctioning = '';
$show_rating_ticket_id = null; // trigger rating modal after close (for non-AJAX cases) - not used in AJAX flow
/* ================= FLASH MESSAGE ================= */
// ---------------------------
// Handle Ticket Close (AJAX)
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_ticket'])) {

    // session_start() is assumed to already exist at the top of the page
    $user_id = $_SESSION['user_id'] ?? null;

    if (!$user_id) {
        echo json_encode([
            'success' => false,
            'message' => 'User not logged in.'
        ]);
        exit;
    }

    $ticket_id = intval($_POST['ticket_id']);
    error_log("Close ticket attempt: ticket_id=$ticket_id, user_id=$user_id");

    // ---------------------------
    // FETCH TICKET (USER)
    // ---------------------------
    $stmt_fetch = $conn->prepare("
        SELECT assigned_staff_id,
               original_assigned_staff_id,
               department_id
        FROM tickets
        WHERE ticket_id = ?
          AND status = 'in_progress'
          AND user_id = ?
    ");
    $stmt_fetch->bind_param('ii', $ticket_id, $user_id);
    $stmt_fetch->execute();
    $result = $stmt_fetch->get_result();

    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Ticket not found or not allowed to close.'
        ]);
        exit;
    }

    $ticket = $result->fetch_assoc();
    $stmt_fetch->close();

    $assigned_staff_id = $ticket['assigned_staff_id'];
    $original_staff_id = $ticket['original_assigned_staff_id'];
    $department_id     = $ticket['department_id'];

    // ---------------------------
    // START TRANSACTION
    // ---------------------------
    $conn->begin_transaction();

    try {
        // Save original staff on first close
        $extra_sql = '';
        if ($original_staff_id === null && $assigned_staff_id !== null) {
            $extra_sql = ', original_assigned_staff_id = ?';
        }

        // ---------------------------
        // CLOSE TICKET
        // ---------------------------
        $sql = "
            UPDATE tickets
            SET status = 'closed',
                ended_at = NOW(), closed_at =NOW(),
                queue_number = NULL
                $extra_sql
            WHERE ticket_id = ?
              AND status = 'in_progress'
              AND user_id = ?
        ";

        $stmt = $conn->prepare($sql);

        if ($extra_sql) {
            // 3 placeholders: original_assigned_staff_id, ticket_id, user_id
            $stmt->bind_param(
                'iii',
                $assigned_staff_id,
                $ticket_id,
                $user_id
            );
        } else {
            // 2 placeholders: ticket_id, user_id
            $stmt->bind_param(
                'ii',
                $ticket_id,
                $user_id
            );
        }

        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            throw new Exception('No rows updated.');
        }

        $stmt->close();

        // ---------------------------
        // REORDER QUEUE
        // ---------------------------
        $conn->query("SET @row := 0");

        $stmtReorder = $conn->prepare("
            UPDATE tickets t
            JOIN (
                SELECT ticket_id, (@row := @row + 1) AS new_q
                FROM tickets
                WHERE department_id = ?
                  AND status = 'in_progress'
                ORDER BY queue_number ASC, created_at ASC
            ) q ON t.ticket_id = q.ticket_id
            SET t.queue_number = q.new_q
        ");
        $stmtReorder->bind_param('i', $department_id);
        $stmtReorder->execute();
        $stmtReorder->close();

        // ---------------------------
        // CHECK FEEDBACK
        // ---------------------------
        $stmt2 = $conn->prepare("
            SELECT feedback_id
            FROM ticket_feedback
            WHERE ticket_id = ?
        ");
        $stmt2->bind_param('i', $ticket_id);
        $stmt2->execute();
        $feedback_exists = ($stmt2->get_result()->num_rows > 0);
        $stmt2->close();

        // ---------------------------
        // GET MANAGER NAME
        // ---------------------------
        $stmt3 = $conn->prepare("
            SELECT u.name
            FROM tickets t
            JOIN users u ON t.manager_id = u.user_id
            WHERE t.ticket_id = ?
        ");
        $stmt3->bind_param('i', $ticket_id);
        $stmt3->execute();
        $row = $stmt3->get_result()->fetch_assoc();
        $manager_name = $row['name'] ?? '';
        $stmt3->close();

        // ---------------------------
        // COMMIT TRANSACTION
        // ---------------------------
        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Ticket successfully closed.',
            'show_rating_ticket_id' => $feedback_exists ? null : $ticket_id,
            'manager_name' => $manager_name
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        error_log('Close ticket error: ' . $e->getMessage());

        echo json_encode([
            'success' => false,
            'message' => 'Failed to close ticket.'
        ]);
    }

    exit;
}


// ---------------------------
// Handle AJAX rating submit
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'submit_rating') {
    header('Content-Type: application/json; charset=utf-8');

    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $rating    = intval($_POST['rating'] ?? 0);
    $reason    = trim($_POST['reason'] ?? '');
    $comments  = trim($_POST['comments'] ?? '');
    $satisfied = trim($_POST['satisfied'] ?? '');

    if ($ticket_id <= 0 || $rating < 1 || $rating > 5 || !in_array($satisfied, ['0','1'], true) || empty($reason) || empty($comments)) {
        echo json_encode(['success'=>false,'message'=>'Invalid input.']);
        exit();
    }

    // Check ticket exists & belongs to user
    $stmt = $conn->prepare("SELECT status, assigned_staff_id, original_assigned_staff_id FROM tickets WHERE ticket_id = ? AND user_id = ?");
    $stmt->bind_param('ii', $ticket_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        echo json_encode(['success'=>false,'message'=>'Ticket not found.']);
        exit();
    }
    $row = $res->fetch_assoc();
    if ($row['status'] !== 'closed') {
        echo json_encode(['success'=>false,'message'=>'Ticket is not closed yet.']);
        exit();
    }
    $rated_staff_id = $row['original_assigned_staff_id'] ?? $row['assigned_staff_id'];
    $stmt->close();

    // Check if feedback exists
    $stmt = $conn->prepare("SELECT feedback_id FROM ticket_feedback WHERE ticket_id = ?");
    $stmt->bind_param('i', $ticket_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        echo json_encode(['success'=>false,'message'=>'Feedback already submitted.']);
        exit();
    }
    $stmt->close();

    // Insert feedback
    $stmt = $conn->prepare("INSERT INTO ticket_feedback (ticket_id, satisfied, rating, reason_title, comments, rated_staff_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iiissi', $ticket_id, $satisfied, $rating, $reason, $comments, $rated_staff_id);

    if ($stmt->execute()) {
        // Update ticket to resolved
        $update = $conn->prepare("UPDATE tickets SET status = 'resolved', resolved_at = NOW() WHERE ticket_id = ?");
        $update->bind_param('i', $ticket_id);
        $update->execute();
        $update->close();

        echo json_encode(['success'=>true,'message'=>'Feedback submitted! Thank you!.']);
    } else {
        error_log("Feedback insert failed: ".$stmt->error);
        echo json_encode(['success'=>false,'message'=>'Failed to save feedback.']);
    }
    $stmt->close();
    exit();
}


/* ================= UPDATE PROFILE ================= */
$errored = '';
$successful = '';

$user_id = $_SESSION['user_id'] ?? 0;
if ($user_id === 0) die("Unauthorized");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    $name          = trim($_POST['name'] ?? '');
    $position      = trim($_POST['position'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $department_id = intval($_POST['department_id'] ?? 0);

    if ($name === '' || $position === '' || $email === '' || $department_id === 0) {
        $errored = "All fields are required.";
    }

    $profile_picture = $_SESSION['profile_picture'] ?? 'default.png';

    if ($errored === '' && !empty($_FILES['avatar']['name'])) {

        if ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
            $errored = "Image must be below 2MB.";
        } else {

            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (!in_array($ext, $allowed)) {
                $errored = "Invalid image format.";
            } else {

                if ($profile_picture !== 'default.png') {
                    @unlink("pics/" . $profile_picture);
                }

                $profile_picture = time() . "_" . $user_id . "." . $ext;
                move_uploaded_file($_FILES['avatar']['tmp_name'], "pics/" . $profile_picture);
            }
        }
    }

    if ($errored === '') {

        $stmt = $conn->prepare("
            UPDATE users 
            SET name=?, position=?, department_id=?, email=?, profile_picture=?
            WHERE user_id=?
        ");
        $stmt->bind_param("ssissi", $name, $position, $department_id, $email, $profile_picture, $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT department_name FROM departments WHERE department_id = ?");
        $stmt->bind_param("i", $department_id);
        $stmt->execute();
        $stmt->bind_result($dept_name);
        $stmt->fetch();
        $stmt->close();

        $_SESSION['name'] = $name;
        $_SESSION['position'] = $position;
        $_SESSION['department_id'] = $department_id;
        $_SESSION['department_name'] = $dept_name;
        $_SESSION['email'] = $email;
        $_SESSION['profile_picture'] = $profile_picture;

        $successful = "Profile updated successfully.";
    }
}

/* ================= CHANGE PASSWORD ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {

    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($current === '' || $new === '' || $confirm === '') {
        $errored = "All password fields are required.";

    } elseif ($new !== $confirm) {
        $errored = "Passwords do not match.";

    } else {

        $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($hashed);

        if (!$stmt->fetch()) {
            $errored = "User account not found.";
        }

        $stmt->close();

        if ($errored === '' && !password_verify($current, $hashed)) {
            $errored = "Current password is incorrect.";
        }

        if ($errored === '') {

            $newHash = password_hash($new, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->bind_param("si", $newHash, $user_id);
            $stmt->execute();
            $stmt->close();

            $successful = "Password updated successfully.";
        }
    }
}

// ---------------------------
// Handle Ticket Reopen (AJAX)
// ---------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['reopen_ticket'])) {
    header('Content-Type: application/json; charset=utf-8');

    $ticket_id = intval($_POST['ticket_id']);

    // Check if the ticket belongs to the current user and is completed or resolved
    $stmt = $conn->prepare("SELECT status, ended_at, department_id, assigned_staff_id, original_assigned_staff_id FROM tickets WHERE ticket_id = ? AND user_id = ?");
    $stmt->bind_param('ii', $ticket_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Ticket not found or not permitted to reopen.']);
        exit();
    }
    $ticket_data = $res->fetch_assoc();
    $stmt->close();

    if (!in_array($ticket_data['status'], ['closed', 'resolved'])) {
        echo json_encode(['success' => false, 'message' => 'Only closed or resolved tickets can be reopened.']);
        exit();
    }

    // Check if within 1-year reopen window
    $reopen_window_date = date('Y-m-d H:i:s', strtotime('-1 year'));
    if ($ticket_data['ended_at'] < $reopen_window_date) {
        echo json_encode(['success' => false, 'message' => 'Ticket cannot be reopened after 1 year from closure.']);
        exit();
    }

    // Update ticket with the specified logic
    $stmt = $conn->prepare("UPDATE tickets 
                            SET 
                              status = 'pending',
                              original_assigned_staff_id = IFNULL(original_assigned_staff_id, assigned_staff_id),
                              assigned_staff_id = NULL,
                              manager_id = NULL,
                              ended_at = NULL,
                              updated_at = NOW()
                            WHERE ticket_id = ?");
    $stmt->bind_param('i', $ticket_id);

    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        // Insert audit log
        $stmt_audit = $conn->prepare("INSERT INTO audit_log (user_id, action) VALUES (?, 'Staff reopened ticket for reassessment.')");
        $stmt_audit->bind_param('i', $user_id);
        $stmt_audit->execute();
        $stmt_audit->close();

        echo json_encode(['success' => true, 'message' => 'Ticket reopened successfully and reassigned to Manager Head.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to reopen ticket.']);
    }
    $stmt->close();
    exit();
}


// ---------------------------
// Handle Ticket Cancel 
// ---------------------------
$notfunctioning = '';
$succeeded = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ticket_id'])) {

    header('Content-Type: application/json');

    $ticket_id = intval($_POST['ticket_id']);

    // 1️⃣ Check ticket
    $check = $conn->prepare("
        SELECT status
        FROM tickets
        WHERE ticket_id = ?
        LIMIT 1
    ");
    $check->bind_param("i", $ticket_id);
    $check->execute();
    $result = $check->get_result();
    $ticket = $result->fetch_assoc();
    $check->close();

    if (!$ticket) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Ticket not found.'
        ]);
        exit;
    }

    if ($ticket['status'] !== 'pending') {
        echo json_encode([
            'status' => 'error',
            'message' => 'Only pending tickets can be cancelled.'
        ]);
        exit;
    }

    // 2️⃣ Cancel ticket
    $stmt = $conn->prepare("
        UPDATE tickets
        SET status = 'cancelled',
            ended_at = NOW()
        WHERE ticket_id = ?
    ");
    $stmt->bind_param("i", $ticket_id);

    if ($stmt->execute()) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Ticket has been cancelled successfully.'
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to cancel ticket. Please try again.'
        ]);
    }

    $stmt->close();
    exit;
}

// Your existing ticket submission logic
// ---------------------------
// Handle Ticket Submission
// ---------------------------
$notfunctioning = '';
$succeeded = '';
$user_id = $_SESSION['user_id'] ?? 0; // logged-in user
$error = "";

// Handle ticket submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket'])) {

    $department_id = (int)($_POST['department_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $issue = trim($_POST['issue'] ?? '');

    /* ================= SUPPORT TYPE ================= */
    $support_type = '';

    if (isset($_POST['support_type'])) {
        if (is_array($_POST['support_type'])) {
            $support_type = trim($_POST['support_type'][0]);
        } else {
            $support_type = trim($_POST['support_type']);
        }
    }

    $allowed_support_types = ['Hardware', 'Software', 'Network'];

    if ($department_id === 1) {
        if (empty($support_type)) {
            $notfunctioning = "Please select a type of IT Issue.";
        } elseif (!in_array($support_type, $allowed_support_types, true)) {
            $notfunctioning = "Invalid IT Issue type.";
        }
    } else {
        // Non-IT tickets: clear support_type safely
        $support_type = '';
    }

    /* ================= FILE UPLOAD ================= */
    $attachment_name = '';

    if (empty($notfunctioning) && !empty($_FILES['attachment']['name'])) {

        $allowed_ext = ['jpg','jpeg','png','pdf','doc','docx','xls','xlsx','ppt','pptx'];
        $file_name = $_FILES['attachment']['name'];
        $file_tmp = $_FILES['attachment']['tmp_name'];
        $file_size = $_FILES['attachment']['size'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_ext)) {
            $notfunctioning = "Invalid file type.";
        } elseif ($file_size > 5 * 1024 * 1024) {
            $notfunctioning = "File too large. Max 5MB.";
        } else {
            if (!is_dir("uploads")) {
                mkdir("uploads", 0777, true);
            }

            $attachment_name = "ticket_" . time() . "_" . rand(1000,9999) . "." . $ext;

            if (!move_uploaded_file($file_tmp, "uploads/" . $attachment_name)) {
                $notfunctioning = "Failed to upload file.";
                $attachment_name = '';
            }
        }
    }

    /* ================= DUPLICATE CHECK ================= */
    if (empty($notfunctioning)) {
        $stmt_dup = $conn->prepare(
            "SELECT COUNT(*) 
             FROM tickets 
             WHERE user_id=? AND title=? AND issue=? 
             AND created_at > NOW() - INTERVAL 1 MINUTE"
        );
        $stmt_dup->bind_param('iss', $user_id, $title, $issue);
        $stmt_dup->execute();
        $stmt_dup->bind_result($dup_count);
        $stmt_dup->fetch();
        $stmt_dup->close();

        if ($dup_count > 0) {
            $notfunctioning = "Duplicate detected — please wait before resubmitting.";
        }
    }

    /* ================= GET MANAGER ================= */
    if (empty($notfunctioning)) {
        $stmt_mgr = $conn->prepare(
            "SELECT user_id 
             FROM users 
             WHERE role='support_staff' AND department_id=? 
             LIMIT 1"
        );
        $stmt_mgr->bind_param('i', $department_id);
        $stmt_mgr->execute();
        $stmt_mgr->bind_result($manager_id);
        $stmt_mgr->fetch();
        $stmt_mgr->close();

        if (empty($manager_id)) {
            $notfunctioning = "No manager head found for the selected department.";
        }
    }

    /* ================= CONTROL NUMBER GENERATION ================= */
    if (empty($notfunctioning)) {

        // Get department code
        $stmt_dept = $conn->prepare(
            "SELECT dept_code FROM departments WHERE department_id=?"
        );
        $stmt_dept->bind_param('i', $department_id);
        $stmt_dept->execute();
        $res_dept = $stmt_dept->get_result();
        $dept = $res_dept->fetch_assoc();
        $stmt_dept->close();

        if (!$dept || empty($dept['dept_code'])) {
            $notfunctioning = "Department code not found.";
        } else {
            $dept_code = strtoupper($dept['dept_code']);
            $year = date('Y');

            // Get last control number for this department + year
$stmt_last = $conn->prepare("
    SELECT control_number
    FROM tickets
    WHERE control_number COLLATE utf8mb4_general_ci
          LIKE CONCAT(?, '-', ?, '-%') COLLATE utf8mb4_general_ci
    ORDER BY CAST(SUBSTRING_INDEX(control_number, '-', -1) AS UNSIGNED) DESC
    LIMIT 1
");

            $stmt_last->bind_param('ss', $dept_code, $year);
            $stmt_last->execute();
            $res_last = $stmt_last->get_result();

            if ($row_last = $res_last->fetch_assoc()) {
                $last_seq = (int) substr($row_last['control_number'], -4);
                $next_seq = $last_seq + 1;
            } else {
                $next_seq = 1;
            }

            $stmt_last->close();

            $control_number = $dept_code . '-' . $year . '-' . str_pad($next_seq, 4, '0', STR_PAD_LEFT);
        }
    }

    /* ================= INSERT TICKET ================= */
    if (empty($notfunctioning)) {

        $stmt = $conn->prepare(
            "INSERT INTO tickets 
            (control_number, user_id, department_id, manager_id, title, issue, support_type, attachment, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())"
        );

        $stmt->bind_param(
            'siiissss',
            $control_number,
            $user_id,
            $department_id,
            $manager_id,
            $title,
            $issue,
            $support_type,
            $attachment_name
        );

        if ($stmt->execute()) {
            $_SESSION['succeeded'] = "Ticket {$control_number} submitted successfully!";
            header("Location: staff_dashboard.php");
            exit();
        } else {
            $notfunctioning = "Failed to submit ticket.";
        }

        $stmt->close();
    }
}


$succeeded = '';
$notfunctioning = $notfunctioning ?? '';

if (!empty($_SESSION['succeeded'])) {
    $succeeded = $_SESSION['succeeded'];
    unset($_SESSION['succeeded']);
}

// ---------------------------
// Fetch Departments
// ---------------------------
$departments = $conn->query("SELECT * FROM departments");

// ---------------------------
// Fetch Department Summary (system-wide)
// ---------------------------
$sql_summary = "
SELECT 
    d.department_id,
    d.department_name,
    SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS opened_count
FROM departments d
LEFT JOIN tickets t ON d.department_id = t.department_id
GROUP BY d.department_id
ORDER BY d.department_name
";
$departmentsSummary = [];
$summary_result = $conn->query($sql_summary);
while ($row = $summary_result->fetch_assoc()) {
    $departmentsSummary[] = $row;
}

// ---------------------------
// Fetch All Tickets for JS (for initial modal trigger if needed)
// ---------------------------
// This query is for the JS allTickets array, which is used for the rating modal.
// It still includes manager_name for the modal's display.
$all_tickets_stmt = $conn->prepare("SELECT t.ticket_id, t.control_number, d.department_name, u.name AS manager_name, 
                                          t.title, t.issue, t.status, t.created_at, t.ended_at, t.original_assigned_staff_id, t.accepted_at,
                                          CASE 
                                            WHEN t.status = 'pending' 
                                                 AND t.original_assigned_staff_id IS NOT NULL 
                                                 AND t.accepted_at IS NOT NULL 
                                            THEN 'reopened'
                                            ELSE t.status
                                          END AS computed_status
                                   FROM tickets t
                                   JOIN departments d ON t.department_id = d.department_id
                                   JOIN users u ON t.manager_id = u.user_id
                                   WHERE t.user_id = ?
                                   
                                   ORDER BY t.created_at ASC");
$all_tickets_stmt->bind_param('i', $user_id);
$all_tickets_stmt->execute();
$all_tickets_result = $all_tickets_stmt->get_result();
$allTickets = [];
while ($row = $all_tickets_result->fetch_assoc()) {
    $allTickets[] = $row;
}
$all_tickets_stmt->close();



// ---------------------------
// AJAX Handler for Pagination (outputs HTML fragments for table, cards, and pagination)
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: text/html; charset=utf-8');

    $limit = 10;
    $page  = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

    // Get total tickets for user
    $stmt_total = $conn->prepare("SELECT COUNT(*) as total FROM tickets WHERE user_id = ?");
    $stmt_total->bind_param('i', $user_id);
    $stmt_total->execute();
    $total_result = $stmt_total->get_result()->fetch_assoc();
    $total_tickets = intval($total_result['total']);
    $stmt_total->close();

    $total_pages = ceil($total_tickets / $limit);

    // Adjust page if invalid
    if ($total_tickets == 0) {
        $page = 1;
    } else {
        $page = max(1, min($page, $total_pages));
    }
    $offset = ($page - 1) * $limit;

    // Fetch tickets for current page with computed_status
$my_tickets_stmt = $conn->prepare("
    SELECT 
        t.ticket_id,
        t.control_number,
        d.department_name,
        u.name AS manager_name,
        t.title,
        t.issue,
        t.status,
        t.created_at,
        t.ended_at,
        t.original_assigned_staff_id,
        t.accepted_at,
        CASE 
            WHEN t.status = 'pending' 
                 AND t.original_assigned_staff_id IS NOT NULL 
                 AND t.accepted_at IS NOT NULL 
            THEN 'reopened'
            ELSE t.status
        END AS computed_status
    FROM tickets t
    JOIN departments d ON t.department_id = d.department_id
    JOIN users u ON t.manager_id = u.user_id
    WHERE t.user_id = ?
    ORDER BY CAST(SUBSTRING_INDEX(t.control_number, '-', -1) AS UNSIGNED) ASC
    LIMIT ? OFFSET ?
");

    $my_tickets_stmt->bind_param('iii', $user_id, $limit, $offset);
    $my_tickets_stmt->execute();
    $my_tickets = $my_tickets_stmt->get_result();

// Output Desktop Table
echo '<div class="desktop-table">';
echo '<table>';

echo '
<thead>
<tr>
    <th>Ticket No.</th>
    <th>Department</th>
    <th>Current Handler</th>
    <th>Subject</th>
    <th>Status</th>
    <th>Created At</th>
    <th>Action</th>
    <th>Progress</th>
</tr>
</thead>
<tbody>
';

if ($my_tickets->num_rows > 0) {

    while ($row = $my_tickets->fetch_assoc()) {

        $ticket_id   = intval($row['ticket_id']);
        $display_id  = !empty($row['control_number']) ? $row['control_number'] : $ticket_id;

        $status_raw  = strtolower($row['computed_status']);
        $statusClass = "status-" . str_replace(" ", "_", $status_raw);
        $statusText  = ucfirst(str_replace('_', ' ', $status_raw));

        $action_button = '--';

        /* ================= ACTION LOGIC ================= */

        // PENDING → CANCEL
        if ($status_raw === 'pending') {
            $action_button = '
                <button class="cancel-btn"
                    onclick="cancelTicket(' . $ticket_id . ')">
                    Cancel
                </button>
            ';
        }
        
        elseif ($status_raw === 'in_progress') {
            $action_button = '
                <button class="confirm-btn"
                    onclick="closeTicket(' . $ticket_id . ')">
                    Close
                </button>
            ';
        }

        // COMPLETED → RATE
        elseif ($status_raw === 'closed') {
            $action_button = '
                <button class="rate-btn"
                    onclick="openRatingModal(' . $ticket_id . ')">
                    Rate
                </button>
            ';
        }
        

        // COMPLETED / RESOLVED → REOPEN (within 1 year)
        elseif ($status_raw === 'resolved') {

    $reopen_window_date = date('Y-m-d H:i:s', strtotime('-1 year'));

    if (!empty($row['ended_at']) && $row['ended_at'] >= $reopen_window_date) {
        $action_button = '
            <button class="reopen-btn" onclick="reopenTicket(' . $ticket_id . ')">
                Reopen
            </button>
        ';
    } else {
        $action_button = '<span class="text-muted">Expired</span>';
    }
}


        // CANCELLED → NO ACTION
        elseif ($status_raw === 'cancelled') {
            $action_button = '--';
        }

        /* ================= OUTPUT ROW ================= */

        
echo '
<tr>
    <td>' . htmlspecialchars($display_id) . '</td>
    <td>' . htmlspecialchars($row['department_name']) . '</td>
    <td>' . htmlspecialchars($row['manager_name']) . '</td>
    <td>' . htmlspecialchars($row['title']) . '</td>
    <td>
        <span class="status-pill ' . $statusClass . '">
            ' . $statusText . '
        </span>
    </td>
    <td>' . htmlspecialchars($row['created_at']) . '</td>
    <td>' . $action_button . '</td>
    <td>
        <a href="javascript:void(0);" 
           onclick="viewTicketUpdates(' . $ticket_id . ')" 
           class="view-link">
           View
        </a>
    </td>
</tr>
';
    }

} else {
    echo '<tr><td colspan="7">No tickets found.</td></tr>';
}

echo '</tbody></table></div>';

    // Output Mobile Cards
    echo '<div class="mobile-table">';
    if ($my_tickets->num_rows > 0) {
        $my_tickets->data_seek(0); // Reset for mobile
        while ($row = $my_tickets->fetch_assoc()) {
            $display_id = !empty($row['control_number']) ? $row['control_number'] : intval($row['ticket_id']);
            $statusClass = "status-" .str_replace(" ", "_", strtolower($row['computed_status']));
            $statusDisplay = ucfirst(str_replace('_', ' ', $row['computed_status']));

            $action_button = '<span style="color:#666; font-size:13px;">--</span>'; // Default to N/A

            // Determine action button based on computed_status
            if ($row['computed_status'] === 'in_progress') {
                $action_button = '<button class="confirm-btn" onclick="closeTicket(' . intval($row['ticket_id']) . ')">Close Ticket</button>';
            } elseif (in_array($row['computed_status'], ['closed', 'resolved'])) {
                $reopen_window_date = date('Y-m-d H:i:s', strtotime('-1 year'));
                if ($row['ended_at'] >= $reopen_window_date) {
                    $action_button = '<button class="reopen-btn" onclick="reopenTicket(' . intval($row['ticket_id']) . ')">Reopen Ticket</button>';
                } else {
                    $action_button = '<span style="color:#666; font-size:13px;">completed (Expired)</span>';
                }
            }
            // For 'pending', 'reopened', or other statuses, it will remain '--' as per previous logic.

            echo '<details class="ticket-card">';
            echo '<summary><span>Ticket #' . htmlspecialchars($display_id) . ' - ' . htmlspecialchars($row['title']) . '</span><span style="font-size:12px; color:#666;">Click to expand</span></summary>';
            echo '<div class="card-content">';
            echo '<div class="ticket-field"><strong>Department:</strong> <span>' . htmlspecialchars($row['department_name']) . '</span></div>';
            echo '<div class="ticket-field"><strong>Manager:</strong> <span>' . htmlspecialchars($row['manager_name']) . '</span></div>';
            echo '<div class="ticket-field"><strong>Status:</strong> <span class="status-pill ' . $statusClass . '">' . $statusDisplay . '</span></div>';
            echo '<div class="ticket-field"><strong>Created At:</strong> <span>' . htmlspecialchars($row['created_at']) . '</span></div>';
            echo '<div class="ticket-field"><strong>Issue:</strong> <span>' . htmlspecialchars(substr($row['issue'], 0, 100)) . '...</span></div>';
            echo '<div class="ticket-action">';
            echo $action_button;
            echo '</div>';
            echo '</div></details>';
        }
    } else {
        echo '<div style="text-align:center; padding:20px; color:#666; background:white; border-radius:8px;">No tickets found.</div>';
    }
    echo '</div>';

    // Output Pagination (with buttons for AJAX)
    echo '<div class="pagination">';
    if ($total_pages > 1) {
        // Previous button
        if ($page > 1) {
            echo '<button type="button" class="page-btn" data-page="' . ($page - 1) . '">&laquo; Prev</button>';
        }
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i == $page) {
                echo '<span class="current">' . $i . '</span>';
            } else {
                echo '<button type="button" class="page-btn" data-page="' . $i . '">' . $i . '</button>';
            }
        }
        // Next button
        if ($page < $total_pages) {
            echo '<button type="button" class="page-btn" data-page="' . ($page + 1) . '">Next &raquo;</button>';
        }
    }
    echo '</div>';

    $my_tickets_stmt->close();
    exit();
}

$users_id = $_SESSION['user_id'];
$query = $conn->prepare("
    SELECT 
        t.ticket_id,
        t.control_number,
        u.name AS name,
        t.status,
        t.queue_number,
        t.accepted_at,
        d.department_name
    FROM tickets t
    LEFT JOIN users u ON t.user_id = u.user_id
    LEFT JOIN departments d ON t.department_id = d.department_id
    WHERE t.queue_number IS NOT NULL
    AND t.user_id = ?
    ORDER BY t.queue_number ASC
");
$query->bind_param("i", $user_id);
$query->execute();
$tickets = $query->get_result()->fetch_all(MYSQLI_ASSOC);


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Staff Dashboard</title>
<style>
    /* STAFF DASHBOARD STYLE */
body {
    margin: 0;
    font-family: Arial, sans-serif;
    overflow-y: scroll;
    /* Shift right para di matakpan ng sidebar */
    margin-left: 210px; /* same width as sidebar */
}
.sidebar {
    position: fixed;       /* laging nakafixed sa left */
    top: 0;
    left: 0;
    width: 260px;
    height: 100vh;
    background: #e5e7eb;   /* dirty white / off-white */
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 20px;
    box-shadow: 2px 0 5px rgba(0,0,0,0.2);
    z-index: 1000;
}

.profile { text-align:center; margin-bottom:30px; }
.avatar { width:80px; height:80px; border-radius:50%; margin:0 auto 10px; }
.profile h2 { margin:5px 0 0; font-size:18px; }
.profile p { font-size:14px; color: black; margin:0; }
.menu { flex-grow:1; }
.menu button {
    display: block;
    width: 90%;
    padding: 17px;
    margin: 15px 0;
    margin-left: 10px;
    background: #065f46; /* dark green default */
    color: white;
    text-decoration: none;
    border-radius: 6px;
    font-size: 18px;
    transition: background 0.3s ease;
    text-align: center;
}

.menu button:hover {
    background: #047857;
}

/* Active page highlight */
.menu button.active, .sidebar-bottom button.active {
    background: #10b981; /* light green */
}

.logout-btn {
    width: 90%;
    padding: 15px;
    background: #ef4444; /* bright red */
    border: none;
    color: white;
    border-radius: 6px;
    cursor: pointer;
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 30px;  /* mas maliit para itaas pa */
    transition: background 0.3s ease;
    margin-left: 10px;
}
.logout-btn:hover { background:#cc0000; }
.sum{flex-grow:1; font-size:25px; padding: 40px;}
.sub{flex-grow:1; padding-left: 60px; padding-right: 60px; font-size:23px;}
.my{flex-grow:1; padding-left: 60px; padding-right: 60px; font-size:18px;}
table { width:100%; border-collapse:collapse; margin-top:20px; background:white; border-radius: 10px; }
th, td { padding:12px; border:1px solid #ddd; text-align:left; word-wrap:break-word; }
th { background:#034c07; color:white;  }
.form-group { margin-bottom:15px; margin-left:50px; margin-right:70px;}
label { display:block; margin-bottom:6px; }
input[type="text"], select { width:100%; padding:8px; border-radius:6px; border:1px solid #ccc; }
textarea { width:97%; padding:8px; border-radius:6px; border:1px solid #ccc; }
button.submit-btn { padding:10px 10px; margin-left:50px; background:#065f46; width:20%; font-size:15px; color:white; border:none; border-radius:6px; cursor:pointer; }
button.submit-btn:hover { background:#10b981; }
.success { color:green; font-weight:bold; }
.error { color:red; font-weight:bold; }
.status-pill { padding:4px 10px; border-radius:12px; color:white; font-weight:bold; text-transform:capitalize; font-size:13px; }
.status-pending { background:gray; }
.status-in_progress { background:orange;}
.status-closed { background:blue; }
.status-resolved { background:green; }
.status-declined { background:red; }
.status-reopened { background:#007BFF; }
.confirm-btn { background:#065f46; color:white; padding:6px 12px; border:none; border-radius:6px; cursor:pointer; }
.confirm-btn:hover { background:darkgreen; }
.rate-btn { background:#065f46; color:white; padding:6px 12px; border:none; border-radius:6px; cursor:pointer; }
.rate-btn:hover { background:darkgreen; }
.reopen-btn { background:#007BFF; color:white; padding:6px 12px; border:none; border-radius:6px; cursor:pointer; }
.reopen-btn:hover { background:#0056b3; }


/* Rating modal */
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    display: none;

    /* CENTERING */
    align-items: center;
    justify-content: center;

    /* IMPORTANT */
    z-index: 99998;
}
.modal {
    width: 420px;
    max-width: 90%;
    background: #fff;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.25);

    max-height: 80vh;
    overflow-y: auto;

    /* CRITICAL */
    position: relative;
    z-index: 99999;
}
.modal .close {
  position: absolute;
  top: 12px;
  right: 15px;
  font-size: 22px;
  cursor: pointer;
  color: #666;
}

.modal .close:hover {
  color: #000;
}
.modal h3 { margin-top:0; }
.stars { display:flex;gap:6px; margin:10px 0 15px; }
.star-btn { font-size:22px; padding:6px 8px; border-radius:6px; border:1px solid #ccc; cursor:pointer; background:#f7f7f7; color:#333; }
.star-btn.active { background:#065f46; border-color:#065f46; color:white; }
textarea.rating-comments { width:95%; min-height:80px; border-radius:6px; border:1px solid #ccc; padding:8px; resize:vertical;}
.modal-actions { margin-top:12px; display:flex; gap:8px; justify-content:flex-end; }
.btn { padding:8px 12px; border-radius:6px; border:none; cursor:pointer; background: #065f46;}
.btn-primary { background:#065f46; color:white; }
.btn-secondary { background:#065f46; color:white; }
.small-note { font-size:13px; color:#555; margin-top:8px; }
.reason-section { margin-top:10px; margin-bottom: 10px;}
.reason-section label { display:block; margin-bottom:6px; }

/* Pagination */
.pagination { text-align: center; margin: 20px 0; }
.pagination button.page-btn, .pagination span { display: inline-block; padding: 8px 12px; margin: 0 4px; background: white; border: 1px solid #065f46; text-decoration: none; border-radius: 4px; color: black; cursor: pointer; }
.pagination button.page-btn:hover { background: #065f46; color: white; }
.pagination .current { background: #065f46; color: white; font-weight: bold; border: none; }

/* Topbar (hidden by default, visible only on mobile) */
.topbar {
  display: none;
  align-items: center;
  gap: 4px;
  background: #2c2c2c;
  color: white;
  padding: 6px 8px;
  position: fixed;
  top: 0; left: 0; right: 0;
  z-index: 500;
  min-height: 40px;
  border-bottom-left-radius: 6px;
  border-bottom-right-radius: 6px;
}
.topbar h2 {
  margin: 0;
  font-size: 14px;
  font-weight: bold;
  margin-right: 6px;
  flex-grow: 1;
}
.topbar button {
  padding: 5px 9px;
  font-size: 13px;
  background: #444;
  border: none;
  border-radius: 4px;
  color: white;
  cursor: pointer;
  min-height: 35px;
  flex: 1;
  max-width: 120px;
}
.topbar button:hover,
.topbar button.active { background: #666; }
  .topbar .logout-btn svg {
  fill: white;
  width: 18px;
  height: 18px;
}
  .topbar .logout-btn {
    background: #ff4d4d;
    font-size: 12px;
    padding: 4px 6px;
    margin-left: auto;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 20%;
  }
  .topbar .logout-btn:hover { background: #cc0000; }

/* Desktop table (default) */
.desktop-table { display: block; width: 100%; }
.mobile-table { display: none; }


/* Mobile Card Styles */
.ticket-card { 
    background: white; 
    border: 1px solid #ddd; 
    border-radius: 8px; 
    margin-bottom: 15px; 
    overflow: hidden; 
    box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
    transition: box-shadow 0.3s ease; 
}
.ticket-card:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
.ticket-card summary { 
    padding: 15px; 
    background: #f8f9fa; 
    cursor: pointer; 
    font-weight: bold; 
    color: #007BFF; 
    border-bottom: 1px solid #ddd; 
    list-style: none; 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
}
.ticket-card summary::-webkit-details-marker { display: none; }
.ticket-card details[open] summary { background: #e9ecef; }
.ticket-card .card-content { padding: 15px; }
.ticket-field { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14px; }
.ticket-field strong { color: #555; flex: 0 0 40%; }
.ticket-field span { flex: 1; text-align: right; }
.ticket-action { text-align: right; margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; }

.form-group small {
    font-size: 15px;
}

#submit-ticket {
    background: #f5f5f5;
    border-radius: 12px;
    padding: 10px 20px;
    padding-bottom: 40px;
    margin: 0 auto 30px auto; /* dagdag top margin para mas mataas sa screen */
    width: 700px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

#submit-ticket h2 {
    margin-left:50px;
}

/*My Ticket Dashboard Styles*/
#my-tickets {
    width: 100%;
    background: white;
    border-radius: 10px;
    padding: 30px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

#my-tickets td{
    font-size: 15px;
    margin-right: 20px;
    border-radius: 10px;
}

/* Only affects table inside .desktop-table */
.desktop-table table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    border-radius: 10px;
    overflow: hidden;  /* para gumana ang border-radius */
    background: #eeeae3; /* medyo dirty white */
    box-shadow: 0 2px 8px rgba(0,0,0,0.1); /* optional shadow */
}

/* Table headers */
.desktop-table table th {
    background: #065f46; /* light green header */
    color: white;
    padding: 10px;
    text-align: center;
    font-size: 16px;
}

/* Table cells */
.desktop-table table td {
    padding: 10px;
    border-bottom: 1px solid #ddd;
}
/* Target only status pills inside your desktop table */
.desktop-table .status-pill {
    font-size: 0.65rem;  /* maliit na font, adjust ayon sa gusto mo */
    padding: 2px 6px;    /* konting padding para hindi masyadong malaki */
    display: inline-block;
    border-radius: 12px; /* retain pill shape */
}

/* Status pills */
.desktop-table .status-pill {
    font-size: 0.75rem;   /* maliit na font */
    padding: 2px 6px;     /* compact padding */
    line-height: 1;
    border-radius: 12px;
    display: inline-block;
}

/* Action buttons (Cancel, Close, Rate, Reopen) */
.desktop-table td button {
    font-size: 0.75rem;   /* maliit na font */
    padding: 3px 6px;     /* mas compact */
    border-radius: 6px;   /* maliit na rounded corners */
    min-width: 50px;      /* optional, para hindi masyadong maliit */
}


.status-in_progress { 
    font-size: 10px;
    
}

/* Disabled button styling */
#rating-submit-btn:disabled {
    background-color: #ccc;   /* light gray background */
    color: #666;              /* dark gray text */
    cursor: not-allowed;      /* show "no" cursor */
    opacity: 0.6;             /* slightly transparent */
}

/* Enabled button styling (optional for clarity) */
#rating-submit-btn:not(:disabled) {
    background-color: #28a745; /* green */
    color: #fff;
    cursor: pointer;
    transition: background 0.2s;
}

#rating-submit-btn:not(:disabled):hover {
    background-color: #218838; /* slightly darker green on hover */
}


/* Summary Dashboard Styles */
#summary-section {
    width: 90%;
    background:  #f5f5f0;
    padding: 40px;          /* bigger padding around the whole section */
    border-radius: 16px;    /* slightly bigger corners */
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);  /* stronger shadow */
    margin-bottom: 30px;
    margin-left: 40px;
}
#summary-section h2 {
    margin-top: 0;
    color: #333;
    font-size: 28px; /* increased from 24px */
    text-align: center;
    margin-bottom: 20px;
}
.toggle-group {
    display: flex;
    justify-content: center;
    margin-bottom: 20px;
    gap: 10px;
}
.toggle-btn {
    padding: 12px 24px; /* slightly bigger */
    border: 2px solid #065f46;
    background: white;
    color: #666;
    border-radius: 25px;
    cursor: pointer;
    font-size: 18px; /* increased from 16px */
    transition: all 0.3s ease;
}
.toggle-btn.active,
.toggle-btn:hover {
    background: #065f46;
    color: white;
    border-color: #065f46;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    max-width: 1200px;
    margin: 0 auto;

}
.summary-card {
    background-color: #eeeae3;
    border-radius: 8px;
    padding: 30px; /* slightly bigger padding */
    text-align: center;
    box-shadow: 2px 4px 6px rgba(0,0,0,0.1);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}
.summary-card h3 {
    margin: 0 0 12px;
    font-size: 20px; /* increased from 18px */
    color: #333;
}
.summary-card .count {
    font-size: 36px; /* increased from 32px */
    font-weight: bold;
    color: #065f46;
    margin: 12px 0;
}
.summary-card .label {
    color: #666;
    font-size: 16px; /* increased from 14px */
}

.queue-tracker-card,
.department-summary-card {
    background-color: #fff;      /* white background */
    border-radius: 10px;
    padding: 30px;
    margin-bottom: 20px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.queue-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 50px;
    border-radius: 10px;
}

.queue-table th {
    border: 1px solid black; /* black borders for all cells */
    padding: 10px;
    text-align: center;
    color: white;
    font-size: 18px;

}
.queue-table td {
    border: 1px solid black; /* black borders for all cells */
    padding: 8px;
    text-align: left;
    color: black;
    font-size: 14px;
    background-color: #eeeae3;
}

.queue-table th {
    background: #065f46;
    font-weight: bold;
}

.queue-table tbody tr:nth-child(even) {
    background: #fafafa;
    
}

.queue-table tbody tr:nth-child(even) {
    background: #fafafa;
}

/* Queueing Number column (first column) */
.queue-table tbody td:nth-child(1) {
    color: #065f46;
    font-weight: bold;
}

/* Status column (fourth column) */
.queue-table tbody td:nth-child(5) {
    color: white;
    padding: 5px 10px; /* make it look like a pill */
    text-align: center;
}
.status-cell { 
    border-radius: 14px;           /* rounded corners */
    padding: 4px 10px;            /* spacing inside the badge */
    text-align: center;           /* center the text */
    display: inline-block;        /* makes the border hug the text */
    background-color: #f0f0f0;    /* optional light background */
    margin: 0 auto;   
    background: orange;
}

@media (max-width: 768px) {
    .sidebar {
        display: none;
    }

    .content {
        margin-left: 0;
    }
}
.cancel-btn {
    background: #e74c3c;
    color: #fff;
    border: none;
    padding: 6px 12px;
    border-radius: 5px;
    cursor: pointer;
}

.status-cancelled {
    background: #8e44ad;
    color: #fff;
}

.sum{
    margin-left: 30px;
}

.profile-btn {    
    width:90%; 
    padding:15px; 
    background:#065f46; 
    border:none; 
    color:white; 
    border-radius:6px; 
    cursor:pointer; 
    font-size:16px; 
    font-weight:bold;
    margin-top: 90px;
    margin-left: 10px; }
.profile-btn:hover { 
    background:#047857; 
}

/* PROFILE DARK BOX */
.profile-box {
    background: #2f2f2f;        /* dark gray */
    color: #fff;
    max-width: 420px;
    padding: 35px 30px;
    border-radius: 14px;
}

/* BIG PROFILE IMAGE */
.profile-pic {
    width: 160px;
    height: 160px;
    border-radius: 50%;
    object-fit: cover;

    border: 6px solid #555;     /* border */
    margin-bottom: 15px;
}

/* ================= PROFILE DASHBOARD ================= */

.profile-wrapper {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    margin-top: 90px;
    margin-left: 30px;
}

/* BIG DASHBOARD CARD */
.profile-box {
    background: linear-gradient(145deg, #2b2b2b, #1f1f1f);
    color: #fff;

    width: 520px;        /* MAS MALAKI */
    max-width: 92%;
    transform: translateY(-40px);


    padding: 55px 50px;
    border-radius: 22px;

    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;


}

/* BIG PROFILE IMAGE */
.profile-pic {
    width: 200px;
    height: 200px;

    border-radius: 50%;
    object-fit: cover;

    border: 8px solid #555;
    margin-bottom: 20px;
}

/* NAME */
.profile-box h2 {
    font-size: 26px;
    margin-bottom: 6px;
}

.profile-box p {
    font-size: 15px;
    margin-bottom: 6px;
}

.profile-box small {
    font-size: 14px;
    margin-bottom: 30px;
}


/* BUTTON AREA */
.profile-card {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

/* BUTTONS */
.profile-card button {
    font-size: 16px;
    padding: 15px;
    border-radius: 10px;
}


button {
    padding: 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
}

.danger {
    background: #c0392b;
    color: #fff;
}

.msg {
    text-align: center;
    margin-bottom: 10px;
    color: green;
}

.err {
    color: red;
}

/* ===== MODAL OVERLAY ===== */
.modalprofile {
  display: none;
  position: fixed;
  top: 0;
  left: 0;

  width: 100vw;
  min-height: 100vh;

  background: rgba(0, 0, 0, 0.6);
  z-index: 99999;
}


/* ===== MODAL BOX ===== */
.modal-content {
  position: absolute;
  top: 45%;
  left: 58%;

  transform: translate(-50%, -50%);

  background: #fff;
  width: 100%;
  max-width: 420px;
  padding: 40px;
  border-radius: 10px;

  box-shadow: 0 15px 40px rgba(0,0,0,0.3);
}



/* ===== MODAL ANIMATION ===== */
@keyframes modalFade {
  from {
    opacity: 0;
    transform: scale(0.95);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}

/* ===== CLOSE BUTTON ===== */
.modal-content .close {
  position: absolute;
  top: 12px;
  right: 15px;
  font-size: 22px;
  cursor: pointer;
  color: #666;
}

.modal-content .close:hover {
  color: #000;
}

/* ===== MODAL TITLE ===== */
.modal-content h3 {
  margin-bottom: 20px;
  font-size: 18px;
  text-align: center;
  font-weight: 600;
}

/* ===== FORM LAYOUT ===== */
.modal-content form {
  display: flex;
  flex-direction: column;
  margin-right: 30px;
  gap: 12px;
}

/* ===== INPUTS & SELECT ===== */
.modal-content input,
.modal-content select {
  width: 100%;
  height: 42px;              /* ✅ same height */
  padding: 10px 12px;
  border-radius: 6px;
  border: 1px solid #ccc;
  font-size: 14px;
  box-sizing: border-box; /* ✅ important */
  margin-left: 15px;
}

.modal-content input:focus,
.modal-content select:focus {
  outline: none;
  border-color: #333;
}

/* ===== FILE INPUT ===== */
.modal-content input[type="file"] {
  padding: 10px;
  font-size: 13px;
}

/* ===== BUTTON ===== */
.modal-content button {
  margin-top: 10px;
  padding: 10px;
  margin-left: 30px;
  border: none;
  border-radius: 6px;
  background: #000;
  color: #fff;
  font-size: 14px;
  cursor: pointer;
  transition: 0.2s;
}

.modal-content button:hover {
  background: #333;
}

@keyframes fadeUp {
    from {
        opacity: 0;
        transform: translateY(15px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.toast {
    position: fixed;
    top: 30px;
    left: 55%;
    transform: translateX(-50%) translateY(-10px);

    min-width: 260px;
    padding: 20px 25px;
    border-radius: 8px;
    color: #fff;
    font-size: 17px;
    z-index: 9999;
    text-align: center;

    opacity: 0;
    animation: toastIn 0.4s ease-out forwards,
               toastOut 0.4s ease-in 1.2s forwards;
}

/* SUCCESS & ERROR */
.toast.success {
    background: #16a34a;
}

.toast.error {
    background: #dc2626;
}

/* SMOOTH FADE + SLIDE */
@keyframes toastIn {
    from {
        opacity: 0;
        transform: translateX(-50%) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }
}

@keyframes toastOut {
    from {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }
    to {
        opacity: 0;
        transform: translateX(-50%) translateY(-20px);
    }
}

@keyframes fadeOut {
    to { opacity: 0; transform: translateX(40px); }
}

/* Big box around the whole checkbox group */
#it-support-group {
    display: flex;
    flex-direction: column; /* stack checkboxes vertically */
    gap: 10px; /* space between checkboxes */
    margin-top: 10px;
    border: 2px solid #ccc; /* outer box border */
    border-radius: 10px; /* rounded corners */
    padding: 15px; /* space inside the box */
    background-color: #f9f9f9; /* optional light background */
}

/* Each label */
#it-support-group label {
    display: flex;
    align-items: center;
    gap: 8px; /* space between checkbox and text */
    cursor: pointer;
    font-weight: 500;
    font-size: 18px; /* minimized text size */
}

/* Show the checkbox normally */
#it-support-group input[type="radio"] {
    display: inline-block;
}

.modaled {
  display: none; /* hidden by default */
  position: fixed;
  z-index: 1000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  min-height: 100vh; /* ensure it fills viewport */
  overflow: auto;
  background-color: rgba(0, 0, 0, 0.5);

  /* flex centering */
  justify-content: center;
  align-items: center;
  padding: 20px;
}


/* Modal content box */
.modal-contentet {
  background-color: #fff;
  padding: 30px 25px;
  border-radius: 12px;
  width: 70%;
  max-width: 650px;
  
  /* Dynamic height */
  max-height: 80vh;  /* at most 80% of viewport height */
  overflow-y: auto;  /* scroll if content too long */
  
  box-shadow: 0 10px 35px rgba(0, 0, 0, 0.4);
  position: relative;
  animation: fadeIn 0.3s ease; /* subtle entrance animation */
}

/* Close button */
.close {
  color: #aaa;
  position: absolute;
  top: 15px;
  right: 20px;
  font-size: 26px;
  font-weight: bold;
  cursor: pointer;
  transition: color 0.2s;
}

.close:hover {
  color: #000;
}

/* Separator lines for each update */
.update {
  padding: 10px 0;
  border-bottom: 1px solid #ccc;
  word-wrap: break-word;
}

.update:last-child {
  border-bottom: none;
}

/* Optional: fade-in animation for modal */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-20px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Responsive for small screens */
@media (max-width: 600px) {
  .modal-contentet {
    width: 95%;
    padding: 20px;
  }
}

input[type="password"],
input[type="text"] {
    width: 100%;
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
}

.toggle-password {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
}

.show-pass {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 8px;
    font-size: 14px;
    margin-left: 16px;
    width: 100%;
}

.show-pass input {
    margin: 0;
    width: auto;
}

.show-pass label {
    cursor: pointer;
}


.password-field {
    width: 100%;
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
}

/* =========================
   TABLET & MOBILE FIXES
========================= */

@media (max-width: 1024px) {
    body {
        margin-left: 0;
    }

    .sidebar {
        display: none;
    }

    .content {
        margin-left: 0 !important;
        width: 100%;
    }

    #submit-ticket,
    #summary-section,
    #my-tickets,
    .queue-tracker-card,
    .department-summary-card {
        width: 95% !important;
        margin-left: auto !important;
        margin-right: auto !important;
    }
}

/* =========================
   TABLET
========================= */
@media (max-width: 768px) {

    /* SIDEBAR HIDE */
    .sidebar {
        display: none;
    }

    body {
        margin-left: 0;
    }

    /* TOPBAR SHOW */
    .topbar {
        display: flex;
    }

    /* TEXT SIZES */
    .sum,
    .sub,
    .my {
        font-size: 18px;
        padding: 20px;
        margin-left: 0;
    }

    /* TABLE RESPONSIVE */
    .desktop-table {
        display: none;
    }

    .mobile-table {
        display: block;
    }

    table {
        font-size: 12px;
    }

    th, td {
        padding: 8px;
    }

    /* FORMS */
    .form-group {
        margin-left: 10px;
        margin-right: 10px;
    }

    textarea {
        width: 100%;
    }

    button.submit-btn {
        width: 100%;
        margin-left: 0;
    }

    /* LARGE CONTAINERS */
    #submit-ticket {
        width: 95%;
        padding: 15px;
    }

    #summary-section {
        margin-left: 0;
        width: 95%;
        padding: 20px;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }

    /* PROFILE */
    .profile-wrapper {
        margin-left: 0;
    }

    .profile-box {
        width: 95%;
        padding: 30px;
    }

    .profile-pic {
        width: 140px;
        height: 140px;
    }

    /* MODAL */
    .modal-content,
    .modal {
        width: 95%;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
    }
}

/* =========================
   MOBILE SMALL (phones)
========================= */
@media (max-width: 480px) {

    body {
        font-size: 14px;
    }

    /* HEADINGS */
    .sum {
        font-size: 16px;
    }

    /* TABLE STACK FIX */
    .ticket-field {
        flex-direction: column;
        text-align: left;
        gap: 4px;
    }

    .ticket-field span {
        text-align: left;
    }

    /* BUTTONS */
    button,
    .confirm-btn,
    .rate-btn,
    .reopen-btn {
        width: 100%;
        font-size: 14px;
    }

    /* PROFILE */
    .profile-box {
        padding: 20px;
    }

    .profile-pic {
        width: 120px;
        height: 120px;
    }

    /* TOAST */
    .toast {
        width: 90%;
        font-size: 14px;
    }

    /* MODALS */
    .modal-content,
    .modal-contentet {
        width: 95%;
        padding: 20px;
    }

    /* LOGIN / FORMS */
    input[type="text"],
    input[type="password"],
    select,
    textarea {
        font-size: 13px;
    }
}
/* =========================
   GLOBAL MOBILE SAFETY FIX
========================= */
* {
    box-sizing: border-box;
}

body {
    overflow-x: hidden;
}

/* =========================
   TABLET & BELOW (UNIFIED)
========================= */
@media (max-width: 1024px) {

    /* REMOVE SIDEBAR LAYOUT SHIFT */
    body {
        margin: 0;
    }

    .sidebar {
        display: none;
    }

    .content {
        width: 100%;
        margin: 0 !important;
        padding: 15px;
    }

    /* FULL WIDTH CONTAINERS */
    #submit-ticket,
    #summary-section,
    #my-tickets,
    .queue-tracker-card,
    .department-summary-card {
        width: 100% !important;
        max-width: 100%;
        margin: 10px auto !important;
        padding: 15px;
    }

    /* TEXT BLOCKS */
    .sum, .sub, .my {
        padding: 15px;
        font-size: 18px;
    }
}

/* =========================
   TABLET (768px)
========================= */
@media (max-width: 768px) {

    .topbar {
        display: flex;
    }

    /* FORCE STACK LAYOUT */
    .summary-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    /* TABLE FIX (IMPORTANT) */
    .desktop-table {
        display: none;
    }

    .mobile-table {
        display: block;
    }

    table {
        width: 100%;
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }

    th, td {
        padding: 10px;
        font-size: 13px;
    }

    /* FORMS */
    .form-group {
        margin: 10px 0;
    }

    input, select, textarea {
        width: 100%;
    }

    button.submit-btn {
        width: 100%;
        margin: 10px 0;
    }

    /* PROFILE */
    .profile-wrapper {
        margin: 0;
        padding: 10px;
    }

    .profile-box {
        width: 100%;
        padding: 25px;
    }

    .profile-pic {
        width: 130px;
        height: 130px;
    }

    /* MODAL FIX */
    .modal-content,
    .modal {
        width: 95%;
        max-height: 85vh;
        overflow-y: auto;

        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
    }
}

/* =========================
   MOBILE (480px)
========================= */
@media (max-width: 480px) {

    body {
        font-size: 14px;
    }

    /* HEADINGS */
    .sum {
        font-size: 15px;
    }

    /* FORCE STACK EVERYTHING */
    .ticket-field {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }

    .ticket-field span {
        text-align: left;
    }

    /* BUTTONS FULL WIDTH */
    button,
    .confirm-btn,
    .rate-btn,
    .reopen-btn,
    .cancel-btn {
        width: 100%;
        font-size: 13px;
        padding: 10px;
    }

    /* CARDS CLEAN LAYOUT */
    .ticket-card summary {
        font-size: 13px;
    }

    /* PROFILE SMALLER */
    .profile-box {
        padding: 18px;
    }

    .profile-pic {
        width: 110px;
        height: 110px;
    }

    /* TOAST FIX */
    .toast {
        width: 90%;
        font-size: 13px;
        padding: 15px;
    }

    /* INPUTS */
    input, select, textarea {
        font-size: 13px;
    }

    /* SUMMARY TEXT */
    .summary-card .count {
        font-size: 28px;
    }
}

/* =========================
   ULTRA SMALL (360px)
========================= */
@media (max-width: 360px) {

    .profile-box {
        padding: 15px;
    }

    .profile-pic {
        width: 90px;
        height: 90px;
    }

    .summary-card {
        padding: 15px;
    }

    .summary-card .count {
        font-size: 24px;
    }
}


/* =========================
   GLOBAL MOBILE SAFETY FIX
========================= */
* {
    box-sizing: border-box;
}

body {
    overflow-x: hidden;
}

/* =========================
   TABLET & BELOW (UNIFIED)
========================= */
@media (max-width: 1024px) {

    /* REMOVE SIDEBAR LAYOUT SHIFT */
    body {
        margin: 0;
    }

    .sidebar {
        display: none;
    }

    .content {
        width: 100%;
        margin: 0 !important;
        padding: 15px;
    }

    /* FULL WIDTH CONTAINERS */
    #submit-ticket,
    #summary-section,
    #my-tickets,
    .queue-tracker-card,
    .department-summary-card {
        width: 100% !important;
        max-width: 100%;
        margin: 10px auto !important;
        padding: 15px;
    }

    /* TEXT BLOCKS */
    .sum, .sub, .my {
        padding: 15px;
        font-size: 18px;
    }
}

/* =========================
   TABLET (768px)
========================= */
@media (max-width: 768px) {

    .topbar {
        display: flex;
    }

    /* FORCE STACK LAYOUT */
    .summary-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    /* TABLE FIX (IMPORTANT) */
    .desktop-table {
        display: none;
    }

    .mobile-table {
        display: block;
    }

    table {
        width: 100%;
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }

    th, td {
        padding: 10px;
        font-size: 13px;
    }

    /* FORMS */
    .form-group {
        margin: 10px 0;
    }

    input, select, textarea {
        width: 100%;
    }

    button.submit-btn {
        width: 100%;
        margin: 10px 0;
    }

    /* PROFILE */
    .profile-wrapper {
        margin: 0;
        padding: 10px;
    }

    .profile-box {
        width: 100%;
        padding: 25px;
    }

    .profile-pic {
        width: 130px;
        height: 130px;
    }

    /* MODAL FIX */
    .modal-content,
    .modal {
        width: 95%;
        max-height: 85vh;
        overflow-y: auto;

        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
    }
}

/* =========================
   MOBILE (480px)
========================= */
@media (max-width: 480px) {

    body {
        font-size: 14px;
    }

    /* HEADINGS */
    .sum {
        font-size: 15px;
    }

    /* FORCE STACK EVERYTHING */
    .ticket-field {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }

    .ticket-field span {
        text-align: left;
    }

    /* BUTTONS FULL WIDTH */
    button,
    .confirm-btn,
    .rate-btn,
    .reopen-btn,
    .cancel-btn {
        width: 100%;
        font-size: 13px;
        padding: 10px;
    }

    /* CARDS CLEAN LAYOUT */
    .ticket-card summary {
        font-size: 13px;
    }

    /* PROFILE SMALLER */
    .profile-box {
        padding: 18px;
    }

    .profile-pic {
        width: 110px;
        height: 110px;
    }

    /* TOAST FIX */
    .toast {
        width: 90%;
        font-size: 13px;
        padding: 15px;
    }

    /* INPUTS */
    input, select, textarea {
        font-size: 13px;
    }

    /* SUMMARY TEXT */
    .summary-card .count {
        font-size: 28px;
    }
}

/* =========================
   ULTRA SMALL (360px)
========================= */
@media (max-width: 360px) {

    .profile-box {
        padding: 15px;
    }

    .profile-pic {
        width: 90px;
        height: 90px;
    }

    .summary-card {
        padding: 15px;
    }

    .summary-card .count {
        font-size: 24px;
    }
}
</style>
<script>
    
function togglePasswords() {
    const passwords = document.querySelectorAll(".password-field");

    passwords.forEach(field => {
        if (field.type === "password") {
            field.type = "text";
        } else {
            field.type = "password";
        }
    });
}

function viewTicketUpdates(ticketId) {
    fetch(`get_ticket_updates.php?ticket_id=${ticketId}`)
        .then(res => res.json())
        .then(data => {

            if (!data || data.error) {
                alert('No updates available.');
                return;
            }

            let updates = [];

            // 1️⃣ Submitted
            if (data.created_at) {
                updates.push(`${data.created_at}:<br>- Ticket #${data.control_number} was submitted successfully with issue of "${data.issue}"`);
            }

            // 2️⃣ Assigned
            if (data.support_staff_name && data.assigned_at) {
                updates.push(`${data.assigned_at}:<br>- Ticket was assigned to ${data.support_staff_name}`);
            }

            // 3️⃣ Accepted
            if (data.accepted_at && data.support_staff_name) {
                updates.push(`${data.accepted_at}:<br>- ${data.support_staff_name} accepted your request`);
            }

            // 4️⃣ Declined
            if (data.status === 'declined' && data.ended_at) {
                let message = `${data.ended_at}:<br>- Ticket was declined`;
                if (data.decline_reason) message += ` due to "${data.decline_reason}"`;
                updates.push(message);
            }

            // 5️⃣ Cancelled
            if (data.status === 'cancelled' && data.ended_at) {
                updates.push(`${data.ended_at}:<br>- Ticket was cancelled`);
            }

            // 6️⃣ Closed
            if (data.closed_at) {
                updates.push(`${data.closed_at}:<br>- Ticket #${data.control_number} was closed successfully`);
            }

            // 7️⃣ Resolved
            if (data.resolved_at) {
                updates.push(`${data.resolved_at}:<br>- Ticket #${data.control_number} was resolved successfully`);
            }

            // Render updates into modal with CSS-based lines
            const ticketUpdatesDiv = document.getElementById('ticketUpdates');
            ticketUpdatesDiv.innerHTML = updates
                .map(u => `<div class="update">${u}</div>`)
                .join('');

            // Show modal
           const modal = document.getElementById('ticketModal');

            // Show modal (centered)
            modal.style.display = 'flex';
            
            // Close button
            const closeBtn = modal.querySelector('.close');
            closeBtn.onclick = () => {
                modal.style.display = 'none';
            };
            
            // Close if user clicks outside modal content
            window.onclick = (e) => {
                if (e.target === modal) modal.style.display = 'none';
            };

            // Close if user clicks outside modal
            window.onclick = (e) => {
                if (e.target === modal) modal.style.display = 'none';
            };
        })
        .catch(err => {
            console.error('Error fetching ticket updates:', err);
            alert('Failed to load ticket updates.');
        });
}



// Close ticket via AJAX (desktop & mobile)
function closeTicket(ticketId, managerName) {
    if (!confirm('Are you sure you want to close this ticket?')) return;

    const form = new FormData();
    form.append('close_ticket', '1');
    form.append('ticket_id', ticketId);

    fetch(location.href, { method: 'POST', body: form })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showFloatingMessage(data.message || 'Ticket closed successfully!', 'success');

                // 👉 OPEN RATING MODAL AFTER SUCCESS
                openRatingModal(ticketId, managerName);

                // Optional: wag muna mag reload para hindi mawala modal
                // loadTickets(currentPage);

            } else {
                showFloatingMessage(data.message || 'Failed to close ticket.', 'error');
                loadTickets(currentPage);
            }
        })
        .catch(err => {
            console.error('Close ticket error:', err);
            showFloatingMessage('Failed to close ticket.', 'error');
            loadTickets(currentPage);
        });
}


    
function cancelTicket(ticketId) {

    if (!confirm("Are you sure you want to cancel this ticket?")) return;

    fetch(window.location.href, {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: "ticket_id=" + encodeURIComponent(ticketId)
    })
    .then(res => res.json())
    .then(data => {

        showFloatingMessage(data.message, data.status);

        if (data.status === "success") {
            setTimeout(() => {
                location.reload();
            }, 1500);
        }
    })
    .catch(() => {
        showFloatingMessage("Something went wrong.", "error");
    });
}
// Floating message helper
function showFloatingMessage(message, type) {
    const box = document.getElementById('floatingMessage');
    box.textContent = message;
    box.className = type;
    box.style.display = 'block';

    setTimeout(() => {
        box.style.display = 'none';
    }, 3000);
}

// Call this when the page loads
window.onload = function () {
    showPage('summary');
};

        // All tickets data for potential use (e.g., initial modal trigger)
        const allTickets = <?php echo json_encode($allTickets); ?>;

        // Department Summary data
        const departmentsSummary = <?php echo json_encode($departmentsSummary); ?>;

        // Render summary function
        function renderSummary(view = 'pending') {
            const grid = document.getElementById('summary-grid');
           grid.innerHTML = '';

            departmentsSummary.forEach(dept => {
                const count = view === 'pending' ? dept.pending_count : dept.opened_count;
                const label = view === 'pending' ? 'Pending Tickets' : 'In Progress Tickets';

                const card = document.createElement('div');
                card.className = 'summary-card';
                card.innerHTML = `
                    <h3>${dept.department_name}</h3>
                    <div class="count">${count || 0}</div>
                    <div class="label">${label}</div>
                `;
                grid.appendChild(card);
            });
        }
    // Initial load
        window.addEventListener('DOMContentLoaded', () => {
            // Render initial summary (default: pending) - even if hidden, it prepares the DOM
            renderSummary('pending');

            // Set initial active page to Submit Ticket
            showPage('submit', null);

            // If URL has page param, set currentPage
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('page')) {
                currentPage = parseInt(urlParams.get('page')) || 1;
            }

            // If server requested to show the rating modal (non-AJAX case), trigger it
            <?php if (!empty($show_rating_ticket_id)): ?>
                const tid = <?= json_encode($show_rating_ticket_id) ?>;
                let managerName = 'the manager';
                const ticket = allTickets.find(t => t.ticket_id == tid);
                if (ticket) {
                    managerName = ticket.manager_name;
                }
                openRatingModal(<?= json_encode($show_rating_ticket_id) ?>, managerName);
            <?php endif; ?>
        });

        function loadQueueingTracker(tickets) {
    const tbody = document.querySelector("#queue-tracker-table tbody");
    tbody.innerHTML = ""; // Reset rows

    tickets
        .filter(t => t.queue_number !== null)// Only tickets with queue #
        .sort((a, b) => a.queue_number - b.queue_number) // Order by queue
        .forEach(ticket => {
            const row = document.createElement("tr");
            row.innerHTML = `
                <td>${ticket.queue_number}</td>
                <td>${ticket.control_number ?? '-'}</td>
                <td>${ticket.department_name ?? '-'}</td> <!-- department -->
                <td>${ticket.name}</td>
                <td><span class="status-cell">${ticket.status}</span></td>
                <td>${ticket.accepted_at ?? '-'}</td>
            `;
            tbody.appendChild(row);
        });
}
    const tickets = <?= json_encode($tickets); ?>;
    
    /* FUNCTION FOR STAFF DASHBOARD */
let currentPage = 1;

function showPage(page, btn) {
    // Show/hide sections
    document.getElementById("submit-ticket").style.display =
        (page === 'submit') ? "block" : "none";

    document.getElementById("my-tickets").style.display =
        (page === 'mytickets') ? "block" : "none";

    document.getElementById("summary-section").style.display =
        (page === 'summary') ? "block" : "none";

    document.getElementById("profile-section").style.display =
        (page === 'profile') ? "block" : "none";

    // Remove active from all buttons except summaryBtn if page isn't summary
    let allButtons = document.querySelectorAll(".menu button, .sidebar-bottom button");

    allButtons.forEach(b => {
        // Keep summaryBtn active if we're on summary
        if (page !== 'summary' || b.id !== "summaryBtn") {
            b.classList.remove("active");
        }
    });

    // Add active to the clicked button (if any)
    if (btn) {
        btn.classList.add("active");
    }

    // Load tickets only when viewing My Tickets
    if (page === 'mytickets') {
        loadTickets(currentPage);
    }
}

// ✅ AUTO ACTIVE SUMMARY ON LOAD
window.addEventListener("DOMContentLoaded", function () {
    const summaryBtn = document.getElementById("summaryBtn");
    if (summaryBtn) {
        summaryBtn.classList.add("active"); // set summary as active by default
        showPage('summary', summaryBtn);
    }
});


        // Toggle summary view
        function toggleSummary(view) {
            const pendingBtn = document.getElementById('toggle-pending');
            const openedBtn = document.getElementById('toggle-opened');

            if (view === 'pending') {
                pendingBtn.classList.add('active');
                openedBtn.classList.remove('active');
            } else {
                pendingBtn.classList.remove('active');
                openedBtn.classList.add('active');
            }

            renderSummary(view);
        }

        // Load tickets via AJAX
        function loadTickets(page = 1) {
            currentPage = page;
            const section = document.getElementById('tickets-section');
            section.innerHTML = '<p style="text-align:center; padding:20px; color:#666;">Loading tickets...</p>';

            const url = new URL(window.location.href);
            url.searchParams.set('ajax', '1');
            url.searchParams.set('page', page);

            fetch(url.toString())
                .then(res => res.text())
                .then(html => {
                    section.innerHTML = html;
                })
                .catch(err => {
                    console.error(err);
                    section.innerHTML = '<p style="color:red; text-align:center; padding:20px;">Failed to load tickets.</p>';
                });
        }

        // Event delegation for pagination clicks
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('page-btn')) {
                e.preventDefault();
                const page = parseInt(e.target.dataset.page);
                if (page) {
                    loadTickets(page);
                }
            }
        });



let selectedRating = 0;

function openRatingModal(ticketId, managerName) {
    selectedRating = 0;
    document.getElementById('rating-ticket-id').value = ticketId;
    document.getElementById('rating-manager-name').textContent = managerName || 'the manager';
    document.querySelectorAll('.star-btn').forEach(btn => btn.classList.remove('active'));
    document.getElementById('rating-comments').value = '';
    document.getElementById('satisfied-hidden').value = '0';
    document.getElementById('rating-reason').innerHTML = '<option value="">-- Select a reason --</option>';

    // Disable submit initially
    document.getElementById('rating-submit-btn').disabled = true;

    document.getElementById('rating-modal-backdrop').style.display = 'flex';
}

// Close modal
function closeRatingModal() {
    document.getElementById('rating-modal-backdrop').style.display = 'none';
}

// Pick a star rating
function pickRating(n) {
    selectedRating = n;
    const stars = document.querySelectorAll('.star-btn');
    stars.forEach(b => b.classList.remove('active'));
    for (let i = 0; i < n; i++) {
        stars[i].classList.add('active');
    }
    updateReasonsBasedOnRating();
    updateSubmitButtonState();
}

// Populate reasons based on rating
function updateReasonsBasedOnRating() {
    const rating = selectedRating;
    const reasonSelect = document.getElementById("rating-reason");
    reasonSelect.innerHTML = '<option value="">-- Select a reason (after choosing stars) --</option>';
    let satisfied = 0;

    if (rating >= 3) {
        satisfied = 1;
        ["Recovered quickly", "Issue fully resolved", "Communication was clear"].forEach(r => {
            let opt = document.createElement("option");
            opt.value = r;
            opt.textContent = r;
            reasonSelect.appendChild(opt);
        });
    } else if (rating >= 1) {
        satisfied = 0;
        ["Took too long", "Not fully resolved", "Communication unclear"].forEach(r => {
            let opt = document.createElement("option");
            opt.value = r;
            opt.textContent = r;
            reasonSelect.appendChild(opt);
        });
    }

    document.getElementById('satisfied-hidden').value = satisfied;
}

// Enable submit only if all fields filled
function updateSubmitButtonState() {
    const reason = document.getElementById('rating-reason').value;
    const comments = document.getElementById('rating-comments').value.trim();
    const submitBtn = document.getElementById('rating-submit-btn');

    if (submitBtn) {
        submitBtn.disabled = !(selectedRating > 0 && reason !== '' && comments !== '');
    }
}

// Wait until DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('rating-reason').addEventListener('change', updateSubmitButtonState);
    document.getElementById('rating-comments').addEventListener('input', updateSubmitButtonState);
});

window.submitRatingAjax = async function() {
    const ticketId = document.getElementById('rating-ticket-id').value;
    const comments = document.getElementById('rating-comments').value.trim();
    const rating = selectedRating;
    const satisfied = document.getElementById('satisfied-hidden').value;
    const reason = document.getElementById('rating-reason').value;

    if (!rating || rating < 1 || rating > 5) { alert('Please choose a rating (1-5).'); return; }
    if (reason === '') { alert('Please select a reason.'); return; }
    if (comments === '') { alert('Please add comments.'); return; }

    const form = new FormData();
    form.append('ajax_action', 'submit_rating');
    form.append('ticket_id', ticketId);
    form.append('rating', rating);
    form.append('comments', comments);
    form.append('satisfied', satisfied);
    form.append('reason', reason);

    try {
        const resp = await fetch(location.href, { method: 'POST', body: form });
        const data = await resp.json();
        if (data.success) {
            showFloatingMessage(data.message || 'Thank you for your feedback!', 'success');
            closeRatingModal();
            if (document.getElementById('my-tickets').style.display !== 'none') {
                loadTickets(currentPage);
            }
        } else {
            showFloatingMessage(data.message || 'Failed to save feedback.', 'error');
        }
    } catch (e) {
        console.error(e);
        showFloatingMessage('Error submitting feedback.', 'error');
    }
};


        // Reopen ticket via AJAX
function reopenTicket(ticketId) {
    if (!confirm('Are you sure you want to reopen this ticket? It will be reassigned to the Manager Head.')) return;

    const form = new FormData();
    form.append('reopen_ticket', '1');
    form.append('ticket_id', ticketId);

    fetch(location.href, { method: 'POST', body: form })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showFloatingMessage(data.message || 'Ticket reopened successfully!', 'success');
                loadTickets(currentPage);
            } else {
                showFloatingMessage(data.message || 'Failed to reopen ticket.', 'error');
                loadTickets(currentPage);
            }
        })
        .catch(err => {
            console.error(err);
            showFloatingMessage('Error reopening ticket.', 'error');
            loadTickets(currentPage);
        });
}
// ---------------------------
// Floating message function
// ---------------------------
function showFloatingMessage(message, type = 'success') {
    const existing = document.getElementById('floatingMessage');
    if (existing) existing.remove();

    const div = document.createElement('div');
    div.id = 'floatingMessage';
    div.textContent = message;
    // Force styles with !important
    div.style.cssText = `
        position: fixed !important;
        top: 30px !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
        padding: 30px 50px !important;
        border-radius: 20px !important;
        font-size: 24px !important;
        font-weight: bold !important;
        text-align: center !important;
        z-index: 9999 !important;
        min-width: 350px !important;
        box-shadow: 0 8px 25px rgba(0,0,0,0.25) !important;
        opacity: 1 !important;
        transition: opacity 0.5s, transform 0.5s !important;
        background-color: ${type === 'success' ? '#d4edda' : '#f8d7da'} !important;
        color: ${type === 'success' ? '#155724' : '#721c24'} !important;
    `;
    document.body.appendChild(div);

    setTimeout(() => {
        div.style.opacity = '0';
        div.style.transform += ' translateY(-20px)';
        setTimeout(() => div.remove(), 500);
    }, 3000);
}
        // Toggle IT support type field based on department selection
document.addEventListener('change', function (e) {
    if (e.target.matches('select[name="department_id"]')) {
        const itSupportGroup = document.getElementById('it-support-group');
        if (!itSupportGroup) return;

        if (e.target.value === '1') {
            itSupportGroup.style.display = 'block';
        } else {
            itSupportGroup.style.display = 'none';

            // Clear checkboxes
            const checkboxes = itSupportGroup.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = false);
        }
    }
});


    

document.addEventListener("DOMContentLoaded", () => {
    loadQueueingTracker(tickets);
});

function floatingmessageshow() {
    const container = document.getElementById('floatMessage');
    if (!container) return;

    const success = container.dataset.succeeded;
    const error   = container.dataset.notfunctioning;

    if (!success && !error) return;

    const message = success || error;
    const bgColor = success ? '#28a745' : '#dc3545';

    const toast = document.createElement('div');
    toast.textContent = message;

   toast.style.cssText = `
    position: fixed;
    top: 30px;
    left: 50%;
    transform: translateX(-50%) scale(0.95);
    background: ${bgColor};
    color: #fff;
    padding: 22px 40px;
    border-radius: 14px;
    font-size: 20px;
    font-weight: 700;
    min-width: 320px;
    max-width: 600px;
    text-align: center;
    z-index: 9999;
    box-shadow: 0 14px 35px rgba(0,0,0,0.35);
    opacity: 0;
    transition: all 0.4s ease;
`;


    document.body.appendChild(toast);

    // Animate in
    setTimeout(() => {
        toast.style.opacity = '1';
        toast.style.top = '40px';
    }, 100);

    // Auto hide
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.top = '20px';
        setTimeout(() => toast.remove(), 400);
    }, 3000);
}

// Auto-trigger on page load
document.addEventListener('DOMContentLoaded', floatingmessageshow);

function openModal(id) {
  document.querySelectorAll('.modal').forEach(m => {
    m.style.display = 'none';
  });

  document.getElementById(id).style.display = 'block';
}

function closeModal(id) {
  document.getElementById(id).style.display = 'none';
}

    
</script>

<script>
  // --- SETTINGS ---
  const idleTimeLimit = 15 * 1000; // 5 seconds (in milliseconds) for testing
  let idleTimer;

  // --- FUNCTION TO REFRESH ---
  function refreshPage() {
    location.reload(); // o pwede rin fetch ng data via AJAX
  }

  // --- RESET TIMER WHEN USER IS ACTIVE ---
  function resetIdleTimer() {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(refreshPage, idleTimeLimit);
  }

  // --- EVENTS THAT COUNT AS ACTIVITY ---
  ['mousemove', 'keydown', 'scroll', 'click', 'touchstart'].forEach(event => {
    document.addEventListener(event, resetIdleTimer, false);
  });

  // --- START THE TIMER ---
  resetIdleTimer();
</script>

</head>
<body>
<?php if (!empty($succeeded) || !empty($notfunctioning)): ?>
<div id="floatMessage"
     data-succeeded="<?= htmlspecialchars($succeeded) ?>"
     data-notfunctioning="<?= htmlspecialchars($notfunctioning) ?>">
</div>
<?php endif; ?>


<?php if (!empty($success)): ?>
    <div class="floating-message success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="floating-message error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (!empty($success)): ?>
<div id="floatingMessage" style="
position: fixed;
top: 25px;
left: 700px; /* Adjusted to position the box to the right of a typical left sidebar (assuming ~250px width; adjust as needed) */
width: auto;
max-width: 500px; /* bigger box */
background-color: #d4edda;
color: #155724;
padding: 22px 35px; /* bigger padding */
border-radius: 10px;
font-size: 20px; /* bigger font */
font-weight: bold;
box-shadow: 0 6px 20px rgba(0,0,0,0.25);
z-index: 9999;
transition: opacity 0.5s ease;
text-align: center;

">
    <?= htmlspecialchars($success) ?>
</div>
<script>
setTimeout(() => {
    const msg = document.getElementById("floatingMessage");
    if (msg) {
        msg.style.opacity = "0";
        setTimeout(() => msg.remove(), 500);
    }
}, 2000);
</script>
<?php endif; ?>

<?php if (!empty($successful)): ?>
  <div class="toast success"><?= htmlspecialchars($successful) ?></div>
<?php endif; ?>

<?php if (!empty($errored)): ?>
  <div class="toast error"><?= htmlspecialchars($errored) ?></div>
<?php endif; ?>



<div class="sidebar" id="sidebar">
    <div>
        <div class="profile">
                  <img 
        src="pics/<?= htmlspecialchars($_SESSION['profile_picture'] ?? 'default.png') ?>" 
        class="avatar"
      >
            <h2><?php echo htmlspecialchars($_SESSION['name']); ?></h2>
            <p>Staff Dashboard</p>
        </div>

    <div class="menu">
        <button id="summaryBtn" onclick="showPage('summary', this); closeSidebar();">Department Summary</button>
        <button onclick="showPage('submit', this); closeSidebar();">Submit Ticket</button>
        <button onclick="showPage('mytickets', this); closeSidebar();">My Tickets</button>
        </div>
    </div>
    <div class="sidebar-bottom">
    <button class = "profile-btn" onclick="showPage('profile', this); closeSidebar();">Profile</button>
</div>
    <button class="logout-btn" onclick="return confirm('Are you sure you want to logout?') ? window.location.href='logout.php' : false;">
        <span>Logout</span>
    </button>
</div>


<!-- UPDATE PROFILE MODAL -->
<div id="updateModal" class="modalprofile">
  <div class="modal-content">
    <span class="close" onclick="closeModal('updateModal')">&times;</span>
    <h3>Update Profile</h3>

    <form method="POST" enctype="multipart/form-data">

      <input
        type="text"
        name="name"
        value="<?= htmlspecialchars($_SESSION['name'] ?? '') ?>"
        required
      >

      <input
        type="text"
        name="position"
        value="<?= htmlspecialchars($_SESSION['position'] ?? '') ?>"
        required
      >

      <select name="department_id" required>
        <option value="">-- Select Department --</option>
        <?php 
        $departments->data_seek(0);
        while ($row = $departments->fetch_assoc()) : ?>
          <option 
            value="<?= (int)$row['department_id'] ?>"
            <?= ($row['department_id'] == ($_SESSION['department_id'] ?? 0)) ? 'selected' : '' ?>
          >
            <?= htmlspecialchars($row['department_name']) ?>
          </option>
        <?php endwhile; ?>
      </select>

      <input
        type="email"
        name="email"
        value="<?= htmlspecialchars($_SESSION['email'] ?? '') ?>"
        required
      >

      <input type="file" name="avatar" accept="image/*">

      <button type="submit" name="update_profile">
        Save Changes
      </button>
    </form>
  </div>
</div>

<!-- ================= CHANGE PASSWORD MODAL ================= -->
<div id="passwordModal" class="modalprofile">
  <div class="modal-content">
    <span class="close" onclick="closeModal('passwordModal')">&times;</span>
    <h3>Change Password</h3>

   <form method="POST">
  <input 
    type="password" 
    name="current_password" 
    placeholder="Current Password" 
    required
    class="password-field"
  >

  <input 
    type="password" 
    name="new_password" 
    placeholder="New Password" 
    required
    class="password-field"
  >

  <input 
    type="password" 
    name="confirm_password" 
    placeholder="Confirm Password" 
    required
    class="password-field"
  >
  
  <label class="show-pass">
      <input type="checkbox" onclick="togglePasswords()"> Show password
  </label>

  <button type="submit" name="change_password">
    Update Password
  </button>
</form>

  </div>
</div>

<script>
function openModal(id){document.getElementById(id).style.display='block'}
function closeModal(id){document.getElementById(id).style.display='none'}
window.onclick=e=>{
  document.querySelectorAll('.modal').forEach(m=>{
    if(e.target===m)m.style.display='none'
  })
}
</script>
</div>

<div class = "content">
    <?php if (isset($_SESSION['user_id'])): ?>
    <div id="profile-section">
   <div class="profile-wrapper">

    <div class="profile-box">

      <img 
        src="pics/<?= htmlspecialchars($_SESSION['profile_picture'] ?? 'default.png') ?>" 
        class="profile-pic"
      >

      <h2><?= htmlspecialchars($_SESSION['name'] ?? '') ?></h2>

      <p>
        <?= htmlspecialchars($_SESSION['position'] ?? '') ?> • 
        <?= htmlspecialchars($_SESSION['department_name'] ?? '') ?>
      </p>

      <small><?= htmlspecialchars($_SESSION['email'] ?? '') ?></small>

      <div class="profile-card">
        <button onclick="openModal('updateModal')">Update Profile</button>
        <button onclick="openModal('passwordModal')">Change Password</button>
        </button>
      </div>

    </div>

  </div>
</div>
    <?php endif; ?>
    <div class="sum">
        <!-- Department Ticket Summary Section (now wrapped and hidden initially) -->
        <div id="summary-section" style="display:none;">
        <div id="queue-tracker-section" class="queue-tracker-card">
            
    <h2 class="queue-title">Queueing Tracker</h2>
    <div class="table-wrapper">
    <table id="queue-tracker-table" class="queue-table" style="border-radius: 10px;">
        <thead>
            <tr>
                <th>Queueing Number</th>
                <th>Control Number</th>
                <th>Department</th>
                <th>Name</th>
                <th>Status</th>
                <th>Accepted At</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    </div>
</div>
         <div class="department-summary-card">
         <h2>Department Ticket Summary</h2>
            <div class="toggle-group">
                <button id="toggle-pending" class="toggle-btn active" onclick="toggleSummary('pending')">Pending Tickets</button>
                <button id="toggle-opened" class="toggle-btn" onclick="toggleSummary('opened')">In Progress Tickets</button>
            </div>
            <div id="summary-grid" class="summary-grid">
                <!-- Cards will be rendered here by JS -->
            </div>
        </div>
    </div>
    <div class = "sub">
        <div id="submit-ticket" style="display:block;">
            <h2>Submit Ticket</h2>
<form method="POST" enctype="multipart/form-data">
    <div class="form-group">
        <label>Department</label>
        <select name="department_id" required>
            <option value="">-- Select Department --</option>
            <?php 
            $departments->data_seek(0);
            while ($row = $departments->fetch_assoc()) { ?>
                <option value="<?php echo intval($row['department_id']); ?>">
                    <?php echo htmlspecialchars($row['department_name']); ?>
                </option>
            <?php } ?>
        </select>
    </div>

    <div class="form-group">
        <label>Subject</label>
        <input type="text" name="title" required>
    </div>

    <div class="form-group">
        <label>Issue</label>
        <textarea name="issue" rows="4" required></textarea>
    </div>

<div class="form-group" id="it-support-group" style="display:none;">
    <label>Type of IT Issue</label>
    <div>
        <label><input type="radio" name="support_type" value="Hardware"> <span>Hardware</span></label>
        <label><input type="radio" name="support_type" value="Software"> <span>Software</span></label>
        <label><input type="radio" name="support_type" value="Network"><span> Network</span></label>
    </div>
</div>


<div class="form-group">
    <label>Attach File (Optional)</label>
    <input type="file" name="attachment" 
        accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx">
    <br><small>You may upload a photo or document. (Max 5MB)</small>
</div>


    <button type="submit" name="submit_ticket" class="submit-btn">Submit</button>
</form>
        </div>
    </div>
    
    <div class = "my">
        <div id="my-tickets" style="display:none;">
            <h2>My Tickets</h2>
            <div id="tickets-section">
                <p style="text-align:center; padding:20px; color:#666;">Loading tickets...</p>
            </div>
<div id="ticketModal" class="modaled">
  <div class="modal-contentet">
    <span class="close">&times;</span>
    <div id="ticketUpdates"></div>
  </div>
</div>


        </div>
    </div>
</div>
<!-- Rating Modal -->
<div id="rating-modal-backdrop" class="modal-backdrop" style="display:none;">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="rating-title">
        <span class="close" onclick="closeRatingModal()">&times;</span>
        <h3 id="rating-title">
            Rate your experience with <span id="rating-manager-name">the manager</span>
        </h3>
        <p class="small-note">
            Please choose 1–5 stars. Reasons will appear based on your rating (3-5 stars for satisfied, 1-2 for not satisfied).
        </p>

        <!-- Star Rating -->
        <div class="stars" aria-label="Rating">
            <button type="button" class="star-btn" onclick="pickRating(1)">1 ★</button>
            <button type="button" class="star-btn" onclick="pickRating(2)">2 ★</button>
            <button type="button" class="star-btn" onclick="pickRating(3)">3 ★</button>
            <button type="button" class="star-btn" onclick="pickRating(4)">4 ★</button>
            <button type="button" class="star-btn" onclick="pickRating(5)">5 ★</button>
        </div>

        <!-- Hidden Satisfied Value -->
        <input type="hidden" id="satisfied-hidden" value="0">

        <!-- Reason Dropdown -->
        <div class="reason-section">
            <label for="rating-reason">Reason:</label>
            <select id="rating-reason" required>
                <option value="">-- Select a reason (after choosing stars) --</option>
            </select>
        </div>

        <!-- Optional Comment -->
        <textarea id="rating-comments" class="rating-comments" placeholder="Comments..."></textarea>
        <script>
             const textarea = document.getElementById('rating-comments');

               textarea.addEventListener('input', () => {
    // Remove anything that is not a letter or space
                textarea.value = textarea.value.replace(/[^a-zA-Z\s]/g, '');
              });
        </script>
        <!-- Hidden Ticket ID -->
        <input type="hidden" id="rating-ticket-id" value="">

        <!-- Submit -->
        <div class="modal-actions">
            <button id="rating-submit-btn" type="button" class="btn btn-primary" onclick="submitRatingAjax()" disabled>Submit Rating</button>

        </div>
    </div>
</div>

<?php $conn->close(); ?>
</body>
</html>