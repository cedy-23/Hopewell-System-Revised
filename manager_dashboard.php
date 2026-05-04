<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'support_staff') {
    header("Location: index.php");
    exit();
}

$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->query("SET time_zone = '+08:00'");
$manager_id = intval($_SESSION['user_id']);
$manager_name = $_SESSION['name'] ?? 'Support Staff';

$success = '';
$error = '';
$show_rating_ticket_id = null;
$show_rating_manager_name = null;

// -------------------- Handle AJAX Rating Submit --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'submit_rating') {
    header('Content-Type: application/json; charset=utf-8');

    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $rating = intval($_POST['rating'] ?? 0);
    $reason = !empty($_POST['reason']) ? trim($_POST['reason']) : null;
    $comments = !empty($_POST['comments']) ? trim($_POST['comments']) : null;
    $satisfied = isset($_POST['satisfied']) ? trim($_POST['satisfied']) : null;

    if ($ticket_id <= 0 || $rating < 1 || $rating > 5 || !in_array($satisfied, ['0', '1'], true) || empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'Invalid input. Please fill all required fields correctly.']);
        exit();
    }

    $stmt = $conn->prepare("SELECT ticket_id, control_number, status FROM tickets WHERE ticket_id = ? AND user_id = ?");
    $stmt->bind_param('ii', $ticket_id, $manager_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Ticket not found or not permitted.']);
        exit();
    }
    $row = $res->fetch_assoc();
    if ($row['status'] !== 'closed') {
        echo json_encode(['success' => false, 'message' => 'Ticket is not closed yet.']);
        exit();
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT feedback_id FROM ticket_feedback WHERE ticket_id = ?");
    $stmt->bind_param('i', $ticket_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Feedback already submitted for this ticket.']);
        exit();
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO ticket_feedback (ticket_id, satisfied, rating, reason_title, comments) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('iiiss', $ticket_id, $satisfied, $rating, $reason, $comments);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Thank you for your feedback!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save feedback.']);
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

// -------------------- Handle Ticket Submission --------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['submit_ticket'])) {
    $department_id = intval($_POST['department_id']);
    $target_manager_id = intval($_POST['manager_id']);
    $title = trim($_POST['title'] ?? '');
    $issue = trim($_POST['issue'] ?? '');

    if ($target_manager_id === $manager_id) {
        $error = "You cannot send a ticket to yourself.";
    } else {
        $stmt = $conn->prepare("INSERT INTO tickets (user_id, department_id, manager_id, title, issue, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->bind_param('iiiss', $manager_id, $department_id, $target_manager_id, $title, $issue);
        if ($stmt->execute()) {
            $new_ticket_id = $conn->insert_id;
            $stmt_get = $conn->prepare("SELECT control_number FROM tickets WHERE ticket_id = ?");
            $stmt_get->bind_param('i', $new_ticket_id);
            $stmt_get->execute();
            $res_get = $stmt_get->get_result();
            $row_get = $res_get->fetch_assoc();
            $display_id = $row_get['control_number'] ?? $new_ticket_id;
            $_SESSION['success_message'] = "Ticket submitted successfully!";
            $stmt_get->close();
            
            header("Location: manager_dashboard.php"); // redirect
            exit();
        } else {
            $_SESSION['error_message'] = "Failed to submit ticket.";
        }
        $stmt->close();
    }
}
$success = '';
if (!empty($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}


// -------------------- Handle Ticket Actions --------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['ticket_action'], $_POST['ticket_id'])) {

    $ticket_id = intval($_POST['ticket_id']);
    $action = $_POST['ticket_action'];

    // =========================
    // FETCH TICKET
    // =========================
    $stmt = $conn->prepare("
        SELECT ticket_id, control_number, status, department_id, manager_id
        FROM tickets
        WHERE ticket_id = ?
    ");
    $stmt->bind_param('i', $ticket_id);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ticket) {
        $_SESSION['error_message'] = "Ticket not found.";
        header("Location: manager_dashboard.php");
        exit();
    }

    if ($ticket['status'] === 'closed') {
        $_SESSION['error_message'] = "Ticket already closed.";
        header("Location: manager_dashboard.php");
        exit();
    }

    $control_number = $ticket['control_number'];
    $department_id  = $ticket['department_id'];
    $user_id        = $_SESSION['user_id'];

    // =========================
    // HANDLE ACTIONS
    // =========================
    switch ($action) {

        // ============================================================
        // ACCEPT
        // ============================================================
        case "accept":

            $conn->begin_transaction();

            try {

                // LOCK ticket
                $stmt = $conn->prepare("
                    SELECT status, department_id
                    FROM tickets
                    WHERE ticket_id = ?
                    FOR UPDATE
                ");
                $stmt->bind_param("i", $ticket_id);
                $stmt->execute();
                $t = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$t || $t['status'] !== 'pending') {
                    throw new Exception("Ticket is no longer available.");
                }

                // BLOCK if already withdrawn
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as cnt
                    FROM ticket_withdrawals
                    WHERE ticket_id = ? AND user_id = ?
                ");
                $stmt->bind_param("ii", $ticket_id, $user_id);
                $stmt->execute();
                $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                if ($cnt > 0) {
                    throw new Exception("You already withdrew this ticket.");
                }

                // LIMIT CHECK (max 3)
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as total
                    FROM tickets
                    WHERE assigned_staff_id = ? AND status = 'in_progress'
                    FOR UPDATE
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $active = $stmt->get_result()->fetch_assoc()['total'];
                $stmt->close();

                if ($active >= 3) {
                    throw new Exception("Max 3 active tickets reached.");
                }

                // GET NEXT QUEUE (per department)
                $stmt = $conn->prepare("
                    SELECT MAX(queue_number) as max_q
                    FROM tickets
                    WHERE department_id = ?
                    FOR UPDATE
                ");
                $stmt->bind_param("i", $department_id);
                $stmt->execute();
                $next_queue = ($stmt->get_result()->fetch_assoc()['max_q'] ?? 0) + 1;
                $stmt->close();

                // UPDATE
                $stmt = $conn->prepare("
                    UPDATE tickets
                    SET status = 'in_progress',
                        accepted_at = NOW(),
                        queue_number = ?,
                        assigned_staff_id = ?,
                        accept_attempts = accept_attempts + 1
                    WHERE ticket_id = ?
                ");
                $stmt->bind_param("iii", $next_queue, $user_id, $ticket_id);
                $stmt->execute();

                if ($stmt->affected_rows === 0) {
                    throw new Exception("Accept failed.");
                }

                $stmt->close();
                $conn->commit();

                $_SESSION['success_message'] =
                    "Ticket #{$control_number} accepted. Queue #{$next_queue}.";

            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message'] = $e->getMessage();
            }

        break;

        // ============================================================
        // CANCEL / WITHDRAW
        // ============================================================
        case "cancel":

            $reason = trim($_POST['decline_reason'] ?? '');

            if ($reason === '') {
                $_SESSION['error_message'] = "Reason is required.";
                break;
            }

            $conn->begin_transaction();

            try {

                // LOCK
                $stmt = $conn->prepare("
                    SELECT queue_number, department_id
                    FROM tickets
                    WHERE ticket_id = ?
                    FOR UPDATE
                ");
                $stmt->bind_param("i", $ticket_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) {
                    throw new Exception("Ticket not found.");
                }

                $current_queue = $row['queue_number'];
                $dept_id = $row['department_id'];

                // SHIFT QUEUE (FIXED: per department)
                if ($current_queue !== null) {
                    $stmt = $conn->prepare("
                        UPDATE tickets
                        SET queue_number = queue_number - 1
                        WHERE department_id = ? AND queue_number > ?
                    ");
                    $stmt->bind_param("ii", $dept_id, $current_queue);
                    $stmt->execute();
                    $stmt->close();
                }

                // INSERT withdrawal
                $stmt = $conn->prepare("
                    INSERT INTO ticket_withdrawals (ticket_id, user_id)
                    VALUES (?, ?)
                ");
                $stmt->bind_param("ii", $ticket_id, $user_id);
                $stmt->execute();
                $stmt->close();

                // UPDATE ticket
                $stmt = $conn->prepare("
                    UPDATE tickets
                    SET status = 'pending',
                        decline_reason = ?,
                        queue_number = NULL
                    WHERE ticket_id = ?
                ");
                $stmt->bind_param("si", $reason, $ticket_id);
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                $_SESSION['success_message'] =
                    "Ticket #{$control_number} returned to queue.";

            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message'] = $e->getMessage();
            }

        break;

        // ============================================================
        // ESCALATE
        // ============================================================
      case "escalate":

    $reason = trim($_POST['escalate_reason'] ?? '');

    if ($reason === '') {
        $_SESSION['error_message'] = "Escalation reason required.";
        break;
    }

    $conn->begin_transaction();

    try {

        // =========================
        // LOCK + GET TICKET
        // =========================
        $stmt = $conn->prepare("
            SELECT accept_attempts, escalated, queue_number, department_id
            FROM tickets
            WHERE ticket_id = ?
            FOR UPDATE
        ");
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
        $t = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$t) {
            throw new Exception("Ticket not found.");
        }

        if ($t['accept_attempts'] < 3) {
            throw new Exception("Escalation not allowed (insufficient attempts).");
        }

        if ($t['escalated'] == 1) {
            throw new Exception("Ticket is already escalated.");
        }

        $dept_id = $t['department_id'];
        $current_queue = $t['queue_number'];

        // =========================
        // SHIFT QUEUE (SAFE)
        // =========================
        if (!is_null($current_queue)) {

            $stmt = $conn->prepare("
                UPDATE tickets
                SET queue_number = queue_number - 1
                WHERE department_id = ?
                  AND queue_number > ?
            ");
            $stmt->bind_param("ii", $dept_id, $current_queue);
            $stmt->execute();
            $stmt->close();
        }

        // =========================
        // GET MANAGER HEAD (FIXED COLUMN)
        // =========================
        $stmt = $conn->prepare("
            SELECT user_id
            FROM users
            WHERE role = 'manager_head'
            LIMIT 1
        ");
        $stmt->execute();
        $head = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$head) {
            throw new Exception("Manager head not found.");
        }

        $manager_head_id = $head['user_id'];

        // =========================
        // UPDATE TICKET
        // =========================
        $stmt = $conn->prepare("
            UPDATE tickets
            SET manager_id = ?,
                escalated = 1,
                escalate_reason = ?,
                escalated_at = NOW(),
                status = 'pending',
                queue_number = NULL
            WHERE ticket_id = ?
        ");

        $stmt->bind_param(
            "isi",
            $manager_head_id,
            $reason,
            $ticket_id
        );

        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            throw new Exception("Failed to escalate ticket.");
        }

        $stmt->close();

        $conn->commit();

        $_SESSION['success_message'] = "Ticket escalated successfully.";

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = $e->getMessage();
    }

break;

        default:
            $_SESSION['error_message'] = "Invalid action.";
        break;
    }

    header("Location: manager_dashboard.php");
    exit();
}

// -------------------- Fetch Department (Current Only) --------------------

$stmt_dept = $conn->prepare("SELECT department_id FROM users WHERE user_id = ?");
if ($stmt_dept === false) {
    die("Database error: " . $conn->error);
}
$stmt_dept->bind_param('i', $user_id);
$stmt_dept->execute();
$result_dept = $stmt_dept->get_result();
if ($result_dept->num_rows === 0) {
    die("User  not found.");
}
$row_dept = $result_dept->fetch_assoc();
$department_id = intval($row_dept['department_id'] ?? 0);
$stmt_dept->close();

if ($department_id === 0) {
    die("No department assigned to user.");
}

$departments = $conn->query("
    SELECT department_id, department_name 
    FROM departments 
    ORDER BY department_name
");

if (!$departments) {
    die("Failed to load departments: " . $conn->error);
}



// -------------------- Summary Data --------------------
$summary_data = [
    'Total' => 0,
    'Pending' => 0,
    'In Progress' => 0,
    'Resolved' => 0,
    'Closed' => 0,
    'Postponed' => 0,
];

$statuses = ['pending', 'in_progress', 'resolved', 'closed', 'declined', 'cancelled', 'postponed'];

foreach ($statuses as $status) {

    $cstmt = $conn->prepare("
        SELECT COUNT(*) as cnt 
        FROM tickets 
        WHERE status = ? 
        AND department_id = ?
    ");

    if ($cstmt !== false) {

        $cstmt->bind_param('si', $status, $department_id);
        $cstmt->execute();

        $summary_data[ucwords(str_replace('_', ' ', $status))] =
            $cstmt->get_result()->fetch_assoc()['cnt'] ?? 0;

        $cstmt->close();
    }
}



// Total tickets
$summary_data['Total'] = array_sum(array_slice($summary_data, 1));

// Calculate scale factor (for bar height)
$max_value = max(array_values($summary_data));
$scale_factor = $max_value > 0 ? 150 / $max_value : 1;

// Statuses to display bars for
$bar_statuses = ['Pending', 'In Progress', 'Resolved'];

$rating_stmt = $conn->prepare("
    SELECT AVG(tf.rating) AS avg_rating, COUNT(tf.feedback_id) AS total_ratings
    FROM ticket_feedback tf
    JOIN tickets t ON tf.ticket_id = t.ticket_id
    WHERE t.manager_id = ?
");
$rating_stmt->bind_param('i', $manager_id);
$rating_stmt->execute();
$rating_result = $rating_stmt->get_result()->fetch_assoc();
$avg_rating = $rating_result['avg_rating'] ?? null;
$total_ratings = (int)($rating_result['total_ratings'] ?? 0);
$rating_stmt->close();


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Support Staff</title>
<style>
body {
    margin: 0;
    font-family: Arial, sans-serif;
    overflow-y: scroll;
    /* Shift right para di matakpan ng sidebar */
    margin-left: 260px; /* same width as sidebar */
}

.profile { 
    text-align:center; 
    margin-bottom:30px; 
}

.avatar { 
    width: 80px; 
    height: 80px; 
    background: #048c28; 
    border-radius: 50%; 
    margin: 0 auto 10px; 
    border: 3px solid #000; /* black border */
}

.profile h2 { 
    margin:5px 0 0; 
    font-size:18px; 
    cursor:pointer; 
    color:black;
}

.profile p { 
    font-size:14px; 
    color:black; 
    margin:0; 
}

.content {
    flex-grow: 1;
    padding: 55px;

    /* default: border lang */
    margin-left: 8px;
    transition: margin-left 0.35s ease;
}

.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 260px;
    height: 100vh;
    background: #e5e7eb;   /* light gray */
    color: #1e293b;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 20px;
    box-shadow: 2px 0 5px rgba(0,0,0,0.2);
    z-index: 1000;
}

@media (max-width: 768px) {

    /* BODY RESET */
    body {
        margin-left: 0 !important;

        overflow-x: hidden;
    }

    /* TOPBAR */
    .topbar {
        display: flex;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 60px;

        background: linear-gradient(135deg, #065f46, #047857);
        align-items: center;
        padding: 0 12px;
        z-index: 3000;
    }

    .menu-toggle {
        font-size: 24px;
        color: white;
        background: none;
        border: none;
        cursor: pointer;
    }

    /* TOPBAR BUTTONS */
    .topbar-buttons {
        display: flex;
        gap: 8px;
        margin-left: 5px;
        flex: 1;
        overflow-x: auto;
    }

    .topbar-buttons button {
        white-space: nowrap;
        font-size: 12px;
        padding: 6px 12px;
        border-radius: 20px;
        border: 1px solid rgba(255,255,255,0.3);
        background: rgba(255,255,255,0.12);
        color: white;
    }

    /* SIDEBAR */
    .sidebar {
        position: fixed;
        top: 0;
        left: -280px;
        width: 260px;
        height: 100vh;
        background: #e5e7eb;
        transition: 0.3s ease;
        z-index: 2500;
    }

    .sidebar.active {
        left: 0;
    }

    /* OVERLAY */
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 2400;
    }

    .sidebar-overlay.active {
        display: block;
    }

    /* TABLE FIX (IMPORTANT!) */
    table.ticket-table {
        width: 100%;
        display: block;
        overflow-x: auto;
    }
}

.menu { 
    flex-grow:1; 
}

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
    margin-bottom: 40px;  /* mas maliit para itaas pa */
    transition: background 0.3s ease;
    margin-left: 10px;
}

.logout-btn:hover { 
    background:#cc0000; 
}


/* Tables */
table.ticket-table {
    width: 100%;
    border-collapse: separate;   /* change from collapse */
    border-spacing: 0;           /* keeps borders tight */
    background: white;
    margin-left: 15px;
    border-radius: 10px;         /* radius here */
    overflow: hidden;            /* makes corners visible */
}

th, td {
    padding: 10px;
    border: 1px solid #ddd;
    text-align: left;
    word-wrap: break-word;
    vertical-align: top;
    font-size: 12px;
}

th {
    background: #065f46;
    color: white;
}

td {
    background: #f5f5f5;
}


#assigned-tickets h2, #my-tickets h2 {
    /* ADJUSTMENT: Reduce left margin since content is now padded; original 150px was excessive */
    margin-left: 20px; /* Small left margin for alignment within padded area */
}

/* Mobile-specific adjustments */
@media (max-width: 800px) {
    .Aticks, .Mticks {
        margin-top: 20px !important;   /* ensure it overrides desktop margin */
        margin-left: 0 !important;     /* reset any desktop left margin */
        margin-right: 0 !important;    /* optional, reset right margin */
        padding-left: 15px;            /* keep some padding inside container */
        padding-right: 15px;
    }

    .Aticks h2, .Mticks h2 {
        margin-left: 0 !important;     /* align heading to left edge of container */
    }
}

/* Forms */
.form-group { 
    margin-bottom:15px; 
    /* ADJUSTMENT: Reduce left margin to avoid excessive space in padded content */
    margin-left: 20px; 
    margin-right: 100px; 
}

.form-group-sub { 
    margin-bottom:15px; 
    /* ADJUSTMENT: Reduce left margin to avoid excessive space in padded content */
    margin-left: 30px; 
    margin-right: 100px; 
}

label { 
    display:block; 
    margin-bottom:6px; 
}

input[type=text], select, textarea { 
    width:100%; 
    padding:8px; 
    border-radius:6px; 
    border:1px solid #ccc; 
}

button.submit-btn { 
    padding:10px 15px; 
    width:30%; 
    background:#065f46; 
    color:white; 
    border:none; 
    border-radius:6px; 
    cursor:pointer; 
    /* ADJUSTMENT: Adjust left margin to align with form-group */
    margin-left:30px; 
}

button.submit-btn:hover { 
    background:#047857; 
}

small { 
    font-size: 15px;
}

/* Tickets */
.success { 
    color:green; 
    font-weight:bold; 
}

.error { 
    color:red; 
    font-weight:bold; 
}

.issue-box { 
    max-height:150px; 
    overflow-y:auto; 
    padding:10px; 
    background:#fafafa; 
    border:1px solid #ddd; 
    border-radius:6px; 
}

.action-btn { 
    padding:8px 12px; 
    margin:2px; 
    border:none; 
    border-radius:6px;
    cursor:pointer; 
    color:white; 
}

.accept-btn { 
    background:#065f46; 
}

.accept-btn:hover { 
    background:#1e7e34; 
}

.cancel-btn { 
    background:red; 
}

.cancel-btn:hover { 
    background:#d3d3d3; 
}

.escalate-btn { 
    background:red; 
}

.escalate-btn:hover { 
    background: #ffcccc; 
}

.close-btn { 
    background:green; 
    color:white; 
    padding:6px 12px; 
    border:none; 
    border-radius:6px; 
    cursor:pointer; 
}

.close-btn:hover { 
    background:darkgreen; 
}

.confirm-btn { 
    background:#065f46; 
    color:white; 
    padding:6px 12px; 
    border:none; 
    border-radius:6px; 
    cursor:pointer; 
}

.confirm-btn:hover { 
    background:darkgreen; 
}

.status-pill { 
    padding:4px 8px; 
    border-radius:12px; 
    color:white; 
    font-weight:bold; 
    text-transform:capitalize; 
    font-size:10px; 
}

.status-pending { 
    background:gray; 
}

.status-in_progress { 
    background:orange; 
}

.status-closed { 
    background:blue; 
}

.status-resolved { 
    background:green; 
}

.status-declined { 
    background:red; 
}

.status-cancelled { 
    background:#8e44ad; 
}

.status-reopened { background:#007BFF; }



/* Summary Container */
#summary-container {
    max-width: 900px;
    margin: 10px auto;
    padding: 10px;
}

/* Rating summary box */
.rating-summary {
    background: #fff3cd;
    border: 2px solid #ffd900ff;
    padding: 13px;
    border-radius: 10px;
    margin: 10px auto 1px auto;
    max-width: 900px;
    text-align: center;
    box-shadow: 0 10px 20px rgba(252, 255, 94, 0.39);
    height: 100px;
}

.rating-summary h3 {
    margin: 0 0 6px;
    font-size: 30px;
}

.rating-chip {
    font-size: 20px;
    font-weight: bold;
    color: #856404;
}

.small-note {
    font-size: 20px;
    color: #666;
}

/* Total tickets */
.total-card {
    background: #e0f0ff;
    padding: 15px 25px;
    border-radius: 12px;
    border: 1px solid #b0d4ff;
    font-size: 30px;
    font-weight: bold;
    box-shadow: 0 4px 8px rgba(37, 139, 109, 0.78);
    margin: 2px auto 10px auto;
    text-align: center;
    height: 30px;
}

/* Cards Grid */
.cards-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-top: 40px;
    align-items: end;
}

/* Cards */
.card {
    background: #f5f5f5;
    border-radius: 12px;
    padding-top: 15px;
    box-shadow: 0 4px 8px rgba(97, 97, 97, 0.3);
    display: flex;
    flex-direction: column;
    position: relative;
    align-items: center;
    justify-content: flex-end;
    text-align: center;
    height: 270px;
}

.card h3 {
    margin: 0;
    font-size: 16px;
    position: absolute;
    top: 10px;
    left: 50%;
    transform: translateX(-50%);
}

/* Bars */
.bar {
    width: 60%;
    border-radius: 6px 6px 0 0;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    font-weight: bold;
    transition: height 1.5s ease;
    position: relative;
    bottom: 0;
    margin-top: 30px;
}

/* Bar labels */
.bar-label {
    position: absolute;
    top: -20px;
    font-size: 14px;
    color: black;
    font-weight: bold;
}

/* Colors */
.bar.bar-pending { background-color: gray !important; }
.bar.bar-in-progress { background-color: orange !important; }
.bar.bar-closed { background-color: blue !important; }
.bar.bar-resolved { background-color: green !important; }
.bar.bar-declined { background-color: red !important; }
.bar.bar-cancelled { background-color: #8e44ad !important; }


/* Summary Cards for simple display */
.summary-card {
    background: white;
    border-radius: 12px;
    padding: 15px;
    box-shadow: 0 4px 8px rgba(97, 97, 97, 0.78);
    text-align: center;
    height: 300px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.summary-card h4 {
    margin: 0 0 10px;
    font-size: 16px;
}

.summary-number {
    font-size: 110px;
    font-weight: 900;
    color: #048c28;
    margin: 0;
    line-height: 1;
    text-shadow: 2px 2px 6px rgba(0,0,0,0.2);
}


/* per-ticket rating badge */
.rating-badge { 
    display:inline-block; 
    padding:4px 8px; 
    border-radius:8px; 
    background:#f0f0f0; 
    color:#333; 
    font-weight:bold; 
    font-size:13px; 
}

.rating-stars { 
    color: #f1c40f; 
    margin-left:6px; 
}

/* Duration button */
.duration-btn {
    font-size: 10px;
    margin-left: 6px;
    padding: 2px 6px;
    cursor: pointer;
    border-radius: 4px;
    border: 1px solid #007BFF;
    background: #e7f1ff;
    color: #007BFF;
    transition: background-color 0.3s ease;
}

.duration-btn:hover {
    background-color: #cce4ff;
}

/* Tooltip style */
.tooltip {
    position: relative;
    display: inline-block;
}

.tooltip .tooltiptext {
    visibility: hidden;
    width: 180px;
    background-color: #333;
    color: #fff;
    text-align: left;
    border-radius: 6px;
    padding: 8px;
    position: absolute;
    z-index: 1;
    bottom: 125%;
    left: 50%;
    margin-left: -90px;
    opacity: 0;
    transition: opacity 0.3s;
    font-size: 12px;
    white-space: nowrap;
}

.tooltip:hover .tooltiptext {
    visibility: visible;
    opacity: 1;
}

/* Reason title style */
.reason-title {
    font-weight: bold;
    margin-top: 8px;
    margin-bottom: 4px;
    color: #333;
}

/* Pagination buttons */
.page-btn {
  margin: 2px;
  padding: 5px 10px;
  border: 1px solid #065f46;
  background: white;
  cursor: pointer;
  border-radius: 4px;
}

.page-btn:hover {
    background: #047857;
}

/* Rating modal */
.modal-backdrop { 
    position:fixed; 
    top:0; 
    left:0; 
    width:100%; 
    height:100%; 
    background:rgba(0,0,0,0.5);
    display:none; 
    align-items:center; 
    justify-content:center; 
    z-index:1000; 
}

.modal { 
    width:420px; 
    background:#fff; 
    border-radius:8px; 
    padding:20px; 
    box-shadow:0 6px 20px rgba(0,0,0,0.2); 
    max-width:90vw; 
}

.modal h3 { 
    margin-top:0; 
}

.stars { 
    display:flex; 
    gap:6px; 
    margin:10px 0 15px; 
    flex-wrap:wrap; 
    justify-content:center; 
}

.star-btn { 
    font-size:22px; 
    padding:6px 8px; 
    border-radius:6px; 
    border:1px solid #ccc; 
    cursor:pointer; 
    background:#f7f7f7; 
}

.star-btn.active { 
    background:#065f46; 
    border-color:#065f46; 
}

textarea.rating-comments { 
    width:100%; 
    min-height:80px; 
    border-radius:6px; 
    border:1px solid #ccc; 
    padding:8px; 
    resize:vertical; 
}

.modal-actions { 
    margin-top:12px; 
    display:flex; 
    gap:8px; 
    justify-content:flex-end; 
}

.btn { 
    padding:8px 12px; 
    border-radius:6px; 
    border:none; 
    cursor:pointer; 
}

.btn-primary { 
    background:#065f46; 
    color:white; 
}

.btn-secondary { 
    background:#065f46; 
    color:white; 
}

/* Mobile overrides */
@media (max-width: 700px) {
    body { 
        padding-top:20px; 
        flex-direction:column;
    }
    
    .sidebar { 
        display:none; 
    }
    
    .topbar { 
        display:flex; 
    }
    
    .content { 
        padding:10px; 
        /* ADJUSTMENT: Remove left padding on mobile since sidebar is hidden */
        padding-left: 10px; 
    }
    
    table.ticket-table { 
        display: none; 
    }
    
    .cards-container { 
        display: block !important; 
        margin-top: 20px; 
    }
    
    .ticket-card { 
        margin-bottom: 16px; 
    }
    
    .card-header { 
        padding: 12px; 
        font-size: 14px; 
    }
    
    .card-body { 
        padding: 12px; 
    }
    
    .card-field { 
        margin-bottom: 8px; 
        display: block; 
    }
    
    .card-field label { 
        display: block; 
        font-weight: bold; 
        margin-bottom: 4px; 
        color: #333; 
    }
    
    .action-btn { 
        width: 100%; 
        margin-bottom: 4px; 
    }
    
    .modal { 
        width:90vw; 
        padding:15px; 
    }
    
    .stars { 
        justify-content:center; 
    }
    
    .cards-grid { 
        grid-template-columns: repeat(2, 1fr); 
        gap:10px; 
    }
    
    .rating-summary {
        margin: 10px auto 1px auto;
    }
    
    .card { 
        height: 180px; 
        padding: 10px; 
    }


  /* Smaller logout button on mobile */
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

}

#resolved-ticket h2{
    margin-left: 18px;
}

/* Mobile styles */
@media (max-width: 768px) {
    #submit-ticket {
        width: 90%;   /* take most of the screen */
        height: 50%;
        font-size: 20px;     /* slightly smaller text */
        margin: 15px auto;   /* lower top margin for mobile */
        padding: 12px 12px 25px 12px; /* slightly smaller padding */
    }
}



/* Desktop defaults */
@media (min-width: 800px) {
    .cards-container { display: none; }
}

/* Ticket Cards Styles */
.ticket-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 16px;
    overflow: hidden;
}

.ticket-card details { display: block; }

.ticket-card summary {
    list-style: none;
    cursor: pointer;
}

.card-header {
    padding: 12px;
    background: #f8f9fa;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: bold;
    color: #333;
}

.card-header::-webkit-details-marker {
    display: none;
}

.card-body {
    padding: 12px;
}

.card-field {
    display: flex;
    margin-bottom: 8px;
    align-items: flex-start;
}

.card-field label {
    font-weight: bold;
    min-width: 120px;
    color: #555;
    margin-right: 8px;
}

.card-field .status-pill {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    color: white;
    font-weight: bold;
    text-transform: capitalize;
    font-size: 13px;
}

.subject-preview {
    font-style: italic;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}


@media (max-width: 800px) {
    body {
        min-height: 50vh;
        background-color: #f0f0f0; /* light gray for mobile */
        margin: 0; /* space for fixed topbar if you have one */
    }

</style>
<style>
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
    margin-top: 50px;
    margin-left: 10px;
}
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
    margin-top: 30px;
}

/* BIG DASHBOARD CARD */
.profile-box {
    background: linear-gradient(145deg, #2b2b2b, #1f1f1f);
    color: #fff;

    width: 520px;        /* MAS MALAKI */
    max-width: 92%;
    transform: translateY(-40px);


    padding: 55px 50px;
    

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
.profile-modal {
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
.profile-modal-box {
  position: absolute;
  top: 44%;
  left: 60%;

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
.profile-modal-close {
  position: absolute;
  top: 12px;
  right: 15px;
  font-size: 22px;
  cursor: pointer;
  color: #666;
}

.profile-modal-close:hover {
  color: #000;
}

/* ===== MODAL TITLE ===== */
.profile-modal-box h3 {
  margin-bottom: 20px;
  font-size: 18px;
  text-align: center;
  font-weight: 600;
}

/* ===== FORM LAYOUT ===== */
.profile-modal-box form {
  display: flex;
  flex-direction: column;
  margin-right: 30px;
  gap: 12px;
}

/* ===== INPUTS & SELECT ===== */
.profile-modal-box input,
.profile-modal-box select {
  width: 100%;
  height: 42px;              /* ✅ same height */
  padding: 10px 12px;
  border-radius: 6px;
  border: 1px solid #ccc;
  font-size: 14px;
  box-sizing: border-box; /* ✅ important */
  margin-left: 15px;
}

.profile-modal-box input:focus,
.profile-modal-box select:focus {
  outline: none;
  border-color: #333;
}

/* ===== FILE INPUT ===== */
.profile-modal-box input[type="file"] {
  padding: 10px;
  font-size: 13px;
}

/* ===== BUTTON ===== */
.profile-modal-box button {
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

.profile-modal-box button:hover {
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
    left: 53%;
    transform: translateX(-50%) translateY(-10px);

    min-width: 260px;
    padding: 20px 25px;
    border-radius: 8px;
    color: #fff;
    font-size: 25px;
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

.export-btn {
    display: flex;
    width: auto;          /* prevent full width */
    align-self: flex-start;
    gap: 8px;

    background: #065f46;
    color: #fff;
    font-weight: 600;
    font-size: 15px;
    margin-top: 30px;
    margin-left: 20px;

    padding: 10px 18px;
    border: none;
    border-radius: 6px;

    cursor: pointer;
    transition: all 0.25s ease;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
}

.export-btn:hover {
    background: linear-gradient(135deg, #218838, #1e7e34);
    transform: translateY(-1px);
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.2);
}

.export-btn:active {
    transform: translateY(0);
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
}

.export-btn:focus {
    outline: none;
}
.doc-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    background: #eef3ff;
    color: #2b4eff;
    border-radius: 4px;
    font-size: 13px;
    text-decoration: none;
}

.doc-link:hover {
    background: #dfe6ff;
}

.muted {
    color: #888;
    font-size: 13px;
}

.decline-reason-box{
    margin-top:6px;
    padding:4px;
    background:#ffe6e6;
    border-radius:6px;
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
}

/* Show the checkbox normally */
#it-support-group input[type="radio"] {
    display: inline-block;
}

input[type="password"],
input[type="text"] {
    width: 100%;
    padding: 12px;
    margin-left: 14px;
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

.action-btn:disabled {
    background: #ccc !important;
    color: #666 !important;
    cursor: not-allowed !important;
    opacity: 0.6;
}

.action-btn:disabled:hover {
    background: #ccc !important;
}

/* =========================
   MOBILE RESPONSIVE FIX (FINAL)
========================= */
@media (max-width: 768px) {

    /* REMOVE SIDEBAR SHIFT */
    body {
        margin-left: 0 !important;
        padding: 0;
        overflow-x: hidden;
        padding-top: 40px;
    }

    /* CONTENT FIT */
    .content {
        margin-left: 0 !important;
        padding: 12px !important;
    }

    /* SIDEBAR HIDE */
    .sidebar {
        display: none !important;
    }

    /* TABLE FIX */
    table.ticket-table {
        width: 100% !important;
        margin-left: 0 !important;
        font-size: 12px;
        overflow-x: auto;
        display: block;
    }

    th, td {
        font-size: 11px;
        padding: 6px;
    }

    /* FORMS */
    .form-group,
    .form-group-sub {
        margin-left: 0 !important;
        margin-right: 0 !important;
    }

    input[type=text],
    select,
    textarea {
        font-size: 14px;
    }

    /* BUTTONS */
    .action-btn {
        width: 100%;
        margin-bottom: 6px;
        font-size: 13px;
    }

    /* PROFILE BOX */
    .profile-box {
        width: 95% !important;
        padding: 25px !important;
        transform: none !important;
    }

    .profile-pic {
        width: 120px !important;
        height: 120px !important;
    }

    /* CARDS */
    .cards-grid {
        grid-template-columns: 1fr !important;
        gap: 10px;
    }

    .summary-card {
        height: auto;
        padding: 15px;
    }

    .summary-number {
        font-size: 60px !important;
    }

    /* MODAL */
    .profile-modal-box {
        left: 50% !important;
        width: 90% !important;
        padding: 20px !important;
    }

    /* EXPORT BUTTON */
    .export-btn {
        width: 100% !important;
        margin-left: 0 !important;
        justify-content: center;
    }

    /* TOAST */
    .toast {
        width: 90%;
        font-size: 16px;
        left: 50%;
    }

    /* H2 ALIGNMENT FIX */
    h2 {
        margin-left: 0 !important;
        text-align: center;
    }
}

@media (max-width: 768px) {

    .topbar {
        display: flex;
        position: fixed;
        top: 0;
        left: 0;

        width: 100%;
        height: 60px;

        background: linear-gradient(135deg, #065f46, #047857);

        align-items: center;
        padding: 0 12px;

        z-index: 3000;
    }

    .menu-toggle {
        display: block;
        font-size: 26px;

        background: none;
        border: none;

        color: white;
        cursor: pointer;
    }

    /* ================= LEFT-ALIGNED DROPDOWN ================= */
    .topbar-buttons {
        display: none;
        flex-direction: column;

        position: absolute;
        top: 60px;
        left: 0;

        width: 85%;   /* ✅ may space sa right side */

        background: #047857;

        padding: 12px;
        gap: 10px;

        box-shadow: 0 10px 20px rgba(0,0,0,0.25);

        align-items: flex-start; /* ✅ left align items */
        border-right: 4px solid rgba(255,255,255,0.15);
    }

    .topbar-buttons.active {
        display: flex;
    }

    /* ================= BUTTON STYLE ================= */
    .topbar-buttons button {
        width: 100%;        /* fill menu width */
        text-align: left;   /* ✅ LEFT TEXT */

        padding: 12px 14px;
        border-radius: 10px;

        background: rgba(255,255,255,0.12);
        border: 1px solid rgba(255,255,255,0.2);

        color: white;
        font-size: 14px;
        font-weight: 500;

        cursor: pointer;

        transition: 0.2s ease;
    }

    .topbar-buttons button:hover {
        background: rgba(255,255,255,0.25);
        transform: translateX(4px); /* ✅ subtle slide effect */
    }

    .topbar-buttons button:active {
        transform: scale(0.97);
    }
}

/* ================= DESKTOP (HIDE EVERYTHING) ================= */
@media (min-width: 769px) {

    .topbar {
        display: none !important;
    }

    .menu-toggle {
        display: none !important;
    }

    .topbar-buttons {
        display: none !important;
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
    
function showPage(page, btn) {

  const sections = [
    "summary",
    "assigned-tickets",
    "my-tickets",
    "resolved-ticket",
    "profile-section"
  ];

  // show selected section
  sections.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = (id === page) ? "block" : "none";
  });

  // remove active from all buttons
  document.querySelectorAll(".menu button, .sidebar-bottom button")
    .forEach(b => b.classList.remove("active"));

  // set active button
  if (btn) {
    btn.classList.add("active");
  } else {
    // fallback: auto set summary button active
    const summaryBtn = document.getElementById("summary-btn");
    if (summaryBtn) summaryBtn.classList.add("active");
  }

  // loaders
  if (page === 'assigned-tickets') {
    loadAssignedTickets(assignedCurrentPage);
  } 
  else if (page === 'my-tickets') {
    loadMyTickets(myCurrentPage);
  }
    else if (page === 'resolved-ticket') {
    loadResolvedTickets(resolvedPage);
  }
}


// 🔹 AUTO LOAD SUMMARY ON LOGIN / REFRESH
window.addEventListener("load", function () {
  showPage('summary');
});

function closeTicket(ticketId) {
    if (!confirm('Are you sure you want to close this ticket?')) return;

    const form = new FormData();
    form.append('close_ticket', '1');
    form.append('ticket_id', ticketId);

    fetch(location.href, { method: 'POST', body: form })
        .then(res => res.json())
        .then(data => {
            showToast(data.message, data.success);

            if (data.success) {
                loadTickets(currentPage); // refresh ticket list
            }
        })
        .catch(err => {
            console.error(err);
            showToast('Error closing ticket.', false);
        });
}

// ---------------------------
// Floating message helper
// ---------------------------
function showToast(message, success = true) {
    const msg = document.createElement('div');
    msg.textContent = message;

    Object.assign(msg.style, {
        position: 'fixed',
        top: '25px',
        left: '30%',
        transform: 'translateX(-50%)',
        width: 'auto',
        maxWidth: '500px',
        padding: '20px 30px',
        borderRadius: '10px',
        fontSize: '18px',
        fontWeight: 'bold',
        boxShadow: '0 6px 20px rgba(0,0,0,0.25)',
        textAlign: 'center',
        transition: 'opacity 0.5s ease',
        opacity: '1',
        zIndex: '9999',
        backgroundColor: success ? '#d4edda' : '#f8d7da',
        color: success ? '#155724' : '#721c24',
    });

    document.body.appendChild(msg);

    // Fade out after 2 seconds
    setTimeout(() => {
        msg.style.opacity = '0';
        setTimeout(() => msg.remove(), 500); // remove after transition
    }, 2000);
}

function confirmCancel(event,form){
    if(event.submitter && event.submitter.value==="cancel"){ return confirm("Are you sure you want to decline this ticket?"); }
    return true;
}
function confirmAccept(event,form){
    if(event.submitter && event.submitter.value==="accept"){ return confirm("Are you sure you want to accept this ticket?"); }
    return true;
}
function confirmClose(event,form){
    if(event.submitter && event.submitter.value==="close"){ return confirm("Are you sure you want to close this ticket?"); }
    return true;
}
function confirmEscalate(form){

    let reason = prompt("Enter escalation reason:");

    if (reason === null) return false;

    reason = reason.trim();

    if (reason === "") {
        alert("Escalation reason is required.");
        return false;
    }

    const input = form.querySelector('input[name="escalate_reason"]');

    if (!input) {
        alert("Error: escalate_reason input not found.");
        return false;
    }

    input.value = reason;

    return true; // ✅ submit immediately
}

function loadManagers(departmentId){
    const mgrSelect=document.getElementById('manager_id');
    mgrSelect.innerHTML='<option value="">-- Select Support Staff --</option>';
    if(!departmentId) return;
    fetch('load_managers.php?department_id='+departmentId+'&exclude_id=<?= $manager_id ?>')
    .then(res=>res.text())
    .then(html=>{ mgrSelect.innerHTML=html; });
}

// Function to check if mobile
function isMobile() {
    return window.innerWidth <= 768;
}

// Helper functions for card conversion
function extractSubjectPreview(subjectHtml) {
    // Extract the summary text from details if present
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = subjectHtml;
    const summary = tempDiv.querySelector('summary');
    if (summary) {
        let preview = summary.textContent.trim();
        if (preview.length > 50) {
            preview = preview.substring(0, 50) + '...';
        }
        return preview;
    }
    // Fallback to plain text
    let preview = tempDiv.textContent.trim();
    if (preview.length > 50) {
        preview = preview.substring(0, 50) + '...';
    }
    return preview;
}

function getStatusClass(statusHtml) {
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = statusHtml;
    const pill = tempDiv.querySelector('.status-pill');
    if (pill) {
        return pill.className.replace('status-pill', '').trim();
    }
    // Fallback based on text
    const statusText = tempDiv.textContent.toLowerCase().trim();
    if (statusText.includes('pending')) return 'status-pending';
    if (statusText.includes('in progress')) return 'status-in_progress';
    if (statusText.includes('closed')) return 'status-closed';
    if (statusText.includes('resolved')) return 'status-resolved';
    if (statusText.includes('declined')) return 'status-declined';
    if (statusText.includes('cancelled')) return 'status-cancelled';
    return '';
}

function statusText(statusHtml) {
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = statusHtml;
    return tempDiv.textContent.trim();
}

// Convert table to cards
function convertTableToCards(tbodyId, cardsContainerId, isAssigned) {
    const tbody = document.getElementById(tbodyId);
    const cardsContainer = document.getElementById(cardsContainerId);
    if (!tbody || !cardsContainer) return;

    cardsContainer.innerHTML = '';
    const rows = tbody.querySelectorAll('tr');
    let colCount = isAssigned ? 9 : 10;

    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length < colCount || cells[0].textContent.trim() === 'Loading...' || cells[0].textContent.trim() === 'No tickets found.') {
            // Handle loading or empty
            const card = document.createElement('div');
            card.className = 'ticket-card';
            card.innerHTML = cells[0].innerHTML;
            cardsContainer.appendChild(card);
            return;
        }

        let id = cells[0].textContent.trim();
        let dept = cells[1].textContent.trim();
        let fromTo = cells[2].textContent.trim();
        let subjectHtml = cells[3].innerHTML;
        let issueHtml = cells[4].innerHTML;
        let statusHtml  = isAssigned ? cells[5].innerHTML : cells[6].innerHTML;
        let documentsHtml = isAssigned ? cells[6].innerHTML : cells[7].innerHTML;
        let actionHtml    = isAssigned ? cells[7].innerHTML : cells[8].innerHTML;

        let createdAtHtml = isAssigned ? '' : cells[5].innerHTML;

        const card = document.createElement('details');
        card.className = 'ticket-card';
        card.open = false; // Collapsed by default on mobile

        const summary = document.createElement('summary');
        summary.className = 'card-header';
        summary.innerHTML = `
            <div>
                <strong>ID: ${id}</strong> | Subject: <span class="subject-preview">${extractSubjectPreview(subjectHtml)}</span> | Status: <span class="status-pill ${getStatusClass(statusHtml)}">${statusText(statusHtml)}</span>
            </div>
            <div style="font-size: 12px;">▼</div>
        `;

        const body = document.createElement('div');
        body.className = 'card-body';
        body.innerHTML = `
            <div class="card-field">
                <label>Department:</label>
                <span>${dept}</span>
            </div>
            <div class="card-field">
                <label>${isAssigned ? 'From' : 'To Manager'}:</label>
                <span>${fromTo}</span>
            </div>
            ${isAssigned ? '' : `
            <div class="card-field">
                <label>Created At:</label>
                <span>${createdAtHtml}</span>
            </div>
            `}
            <div class="card-field">
                <label>Subject:</label>
                <div>${subjectHtml}</div>
            </div>
            <div class="card-field">
                <label>Issue:</label>
                <div>${issueHtml}</div>
            </div>
            <div class="card-field">
                <label>Status:</label>
                <span class="status-pill ${getStatusClass(statusHtml)}">${statusText(statusHtml)}</span>
            </div>
            <div class="card-field">
                <label>Documents:</label>
                <div class="doc-container">
                ${documentsHtml}
            </div>
            </div>
            <div class="card-field">
                <label>Action:</label>
                <div style="display: flex; flex-wrap: wrap; gap: 4px;">${actionHtml}</div>
            </div>
        `;

        card.appendChild(summary);
        card.appendChild(body);
        cardsContainer.appendChild(card);
    });
}

// AJAX Pagination for Assigned Tickets
let assignedCurrentPage = 1;

function loadAssignedTickets(page = 1) {
    assignedCurrentPage = page;
    const tbody = document.getElementById('assigned-body');
    const cardsContainer = document.getElementById('assigned-cards');

    tbody.innerHTML = '<tr><td colspan="8">Loading...</td></tr>';
    if (cardsContainer) cardsContainer.innerHTML = '';

    fetch('load_assigned_tickets.php?page=' + page)
        .then(res => res.text())
        .then(html => {
            tbody.innerHTML = html;

            // ⏱ ensure DOM is fully painted
            setTimeout(() => {
                updateAcceptButtons();
            }, 0);

if (isMobile()) {
    requestAnimationFrame(() => {
        convertTableToCards('assigned-body', 'assigned-cards', true);
    });
}
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="8">Failed to load tickets.</td></tr>';
        });
}

function confirmCancelWithReason(form) {
    let reason = prompt("Please provide a reason for withdrawing this ticket:");

    if (reason === null) return false;

    reason = reason.trim();

    if (reason === "") {
        alert("You must provide a reason to withdraw the ticket.");
        return false;
    }

    const input = form.querySelector('input[name="decline_reason"]');

    if (!input) {
        alert("Error: hidden input not found.");
        return false;
    }

    input.value = reason;

    return true;
}
// Function to confirm accepting a ticket
function confirmAccept(event, form) {
    if (!confirm('Are you sure you want to accept the ticket?')) {
        event.preventDefault(); // Prevent form submission if user cancels
        return false;
    }
    return true; // Allow form submission if confirmed
}


function confirmClose(event, form) {
    if (!confirm('Are you sure you want to close the ticket?')) {
        event.preventDefault(); // Prevent form submission if user cancels
        return false;
    }
    return true; // Allow form submission if confirmed
}


// AJAX Pagination for My Tickets
let myCurrentPage = 1;
function loadMyTickets(page = 1) {
    myCurrentPage = page;
    const tbody = document.getElementById('my-body');
    const cardsContainer = document.getElementById('my-cards');
    tbody.innerHTML = '<tr><td colspan="8">Loading...</td></tr>';
    if (cardsContainer) cardsContainer.innerHTML = '';

    fetch('load_my_supporttickets.php?page=' + page)
        .then(res => res.text())
        .then(html => {
            tbody.innerHTML = html;
            if (isMobile()) {
                convertTableToCards('my-body', 'my-cards', false);
            }
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="8">Failed to load tickets.</td></tr>';
            if (isMobile()) {
                convertTableToCards('my-body', 'my-cards', false);
            }
        });
}

let resolvedPage = 1;
function loadResolvedTickets(page = 1) {
    resolvedPage = page;
    const tbody = document.getElementById('res-body');
    const cardsContainer = document.getElementById('res-cards');
    tbody.innerHTML = '<tr><td colspan="8">Loading...</td></tr>';
    if (cardsContainer) cardsContainer.innerHTML = '';

    fetch('load_my_resolvedtickets.php?page=' + page)
        .then(res => res.text())
        .then(html => {
            tbody.innerHTML = html;
            if (isMobile()) {
                convertTableToCards('res-body', 'res-cards', false);
            }
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="8">Failed to load tickets.</td></tr>';
            if (isMobile()) {
                convertTableToCards('res-body', 'res-cards', false);
            }
        });
}


// Handle pagination clicks (assuming page-btns are in the loaded HTML or added separately)
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('page-btn')) {
        const page = parseInt(e.target.dataset.page);
        if (!isNaN(page)) {
            if (e.target.classList.contains('assigned')) {
                loadAssignedTickets(page);
            } else if (e.target.classList.contains('my')) {
                loadMyTickets(page);
            }
        }
    }
});

// Handle window resize to re-convert if needed
window.addEventListener('resize', function() {
    if (isMobile()) {
        // Re-convert if sections are visible
        if (document.getElementById('assigned-tickets').style.display === 'block') {
            convertTableToCards('assigned-body', 'assigned-cards', true);
        }
        if (document.getElementById('my-tickets').style.display === 'block') {
            convertTableToCards('my-body', 'my-cards', false);
        }
                if (document.getElementById('resolved-ticket').style.display === 'block') {
            convertTableToCards('res-body', 'res-cards', false);
        }
    }
});

// Rating modal logic
let selectedRating = 0;
function openRatingModal(ticketId, handlingManagerName) {
    selectedRating = 0;
    document.getElementById('rating-ticket-id').value = ticketId;
    document.getElementById('rating-handling-manager-name').textContent = handlingManagerName || 'the handling support staff';
    document.querySelectorAll('.star-btn').forEach(btn => btn.classList.remove('active'));
    document.getElementById('rating-comments').value = '';
    document.getElementById('satisfied-hidden').value = '0';
    // Clear reason dropdown
    const reasonSelect = document.getElementById('rating-reason');
    reasonSelect.innerHTML = '<option value="">-- Select a reason --</option>';
    document.getElementById('rating-modal-backdrop').style.display = 'flex';
}
function closeRatingModal() {
    document.getElementById('rating-modal-backdrop').style.display = 'none';
}
function pickRating(n, btn) {
    selectedRating = n;
    document.querySelectorAll('.star-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    updateReasonsBasedOnRating();
}

function updateReasonsBasedOnRating() {
    const rating = selectedRating;
    const reasonSelect = document.getElementById("rating-reason");
    reasonSelect.innerHTML = '<option value="">-- Select a reason --</option>';
    let satisfied = 0;

    if (rating >= 3 && rating <= 5) {
        satisfied = 1;
        ["Recovered quickly", "Issue fully resolved", "Communication was clear"]
            .forEach(r => {
                let opt = document.createElement("option");
                opt.value = r;
                opt.textContent = r;
                reasonSelect.appendChild(opt);
            });
    } else if (rating >= 1 && rating <= 2) {
        satisfied = 0;
        ["Took too long", "Not fully resolved", "Communication unclear"]
            .forEach(r => {
                let opt = document.createElement("option");
                opt.value = r;
                opt.textContent = r;
                reasonSelect.appendChild(opt);
            });
    }
    // Update hidden satisfied value
    document.getElementById('satisfied-hidden').value = satisfied;
}

async function submitRatingAjax() {
    const ticketId = document.getElementById('rating-ticket-id').value;
    const comments = document.getElementById('rating-comments').value.trim();
    const rating = selectedRating;
    const satisfied = document.getElementById('satisfied-hidden').value;
    const reason = document.getElementById('rating-reason').value;

    if (!rating || rating < 1 || rating > 5) {
        alert('Please choose a rating (1-5).');
        return;
    }
    if (reason === '') {
        alert('Please select a reason.');
        return;
    }

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
            const msg = document.createElement('div');
            msg.className = 'success';
            msg.textContent = data.message || 'Thank you for your feedback!';
            document.querySelector('.content').prepend(msg);
            setTimeout(() => { if (msg) msg.remove(); }, 4000);
            closeRatingModal();
            // Reload the current section to update ratings
            if (document.getElementById('my-tickets').style.display === 'block') {loadMyTickets(myCurrentPage);
            }
        } else {
            alert(data.message || 'Failed to save feedback.');
        }
    } catch (e) {
        console.error(e);
        alert('Error submitting feedback.');
    }
}

window.onload = ()=>showPage('summary');

// Load initial tickets if needed (but sections start hidden)
document.addEventListener('DOMContentLoaded', () => {
    // If server requested to show the rating modal, trigger it.
    <?php if (!empty($show_rating_ticket_id)): ?>
        openRatingModal(<?= json_encode($show_rating_ticket_id) ?>, <?= json_encode($show_rating_manager_name) ?>);
    <?php endif; ?>
});
</script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const messages = document.querySelectorAll('.floating-message');
    messages.forEach(msg => {
        setTimeout(() => {
            msg.classList.add('fade-out');
            setTimeout(() => msg.remove(), 600); // remove after fade
        }, 4000); // 4 seconds visible
    });
});

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
function openProfileModal(id) {
  document.querySelectorAll('.profile-modal').forEach(m => {
    m.style.display = 'none';
  });
  document.getElementById(id).style.display = 'block';
}

function closeProfileModal(id) {
  document.getElementById(id).style.display = 'none';
}

window.onclick = function (e) {
  document.querySelectorAll('.profile-modal').forEach(m => {
    if (e.target === m) m.style.display = 'none';
  });
};
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
<script>
function toggleSidebar() {
    document.getElementById("sidebar").classList.toggle("active");
    document.querySelector(".sidebar-overlay").classList.toggle("active");
}

function closeSidebar() {
    document.getElementById("sidebar").classList.remove("active");
    document.querySelector(".sidebar-overlay").classList.remove("active");
}
function toggleTopbarMenu() {
    document.querySelector(".topbar-buttons").classList.toggle("active");
}
function closeTopbarMenu() {
    document.querySelector(".topbar-buttons").classList.remove("active");
}
</script>


</head>
<body>
<?php if (!empty($success)): ?>
<div id="floatingMessage" style="
position: fixed;
top: 25px;
left: 550px; /* Adjusted to position the box to the right of a typical left sidebar (assuming ~250px width; adjust as needed) */
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

<?php if (!empty($error)): ?>
<div id="floatingMessage" style="
position: fixed;
top: 25px;
left: 450px; /* Adjusted to position the box to the right of a typical left sidebar (assuming ~250px width; adjust as needed) */
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

<?php


if (isset($_SESSION['error_message'])):
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']); // clear after reading
?>
<div id="floatingMessage" style="
    position: fixed;
    top: 25px;
    left: 55%;
    transform: translateX(-50%);
    width: auto;
    max-width: 500px;
    background-color: #f8d7da; /* red-ish for error */
    color: #721c24;
    padding: 22px 35px;
    border-radius: 10px;
    font-size: 20px;
    font-weight: bold;
    box-shadow: 0 6px 20px rgba(0,0,0,0.25);
    z-index: 9999;
    transition: opacity 0.5s ease;
    text-align: center;
    opacity: 1;
">
    <?= htmlspecialchars($error) ?>
</div>

<script>
// Auto-hide after 5 seconds
setTimeout(() => {
    const msg = document.getElementById('floatingMessage');
    if (msg) {
        msg.style.opacity = '0';
        setTimeout(() => msg.remove(), 500); // remove from DOM after fade-out
    }
}, 4000);
</script>
<?php endif; ?>
<div class="topbar">

    <!-- ☰ HAMBURGER -->
    <button class="menu-toggle" onclick="toggleTopbarMenu()">
        ☰
    </button>

    <div class="topbar-buttons">

        <button onclick="showPage('summary', this); closeTopbarMenu();">
            Summary
        </button>

        <button onclick="showPage('assigned-tickets', this); closeTopbarMenu();">
            Pending
        </button>

        <button onclick="showPage('my-tickets', this); closeTopbarMenu();">
            To-Do
        </button>

        <button onclick="showPage('resolved-ticket', this); closeTopbarMenu();">
            History
        </button>

        <!-- PROFILE -->
        <button onclick="showPage('profile-section', this); closeTopbarMenu();">
            Profile
        </button>

        <!-- LOGOUT -->
        <button onclick="if(confirm('Are you sure you want to logout?')) window.location.href='logout.php'; closeTopbarMenu();">
            Logout
        </button>

    </div>
</div>

<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div>
        <div class="profile">
              <img 
        src="pics/<?= htmlspecialchars($_SESSION['profile_picture'] ?? 'default.png')  ?>" 
        class="avatar"
      >
            <h2><?php echo htmlspecialchars($_SESSION['name']); ?></h2>
            <p>Support Staff</p>
        </div>

        <div class="menu">
           <button id="summary-btn" class = "active" onclick="showPage('summary', this); closeSidebar();">Summary</button>
           <button id="assigned-tickets-btn" onclick="showPage('assigned-tickets', this); closeSidebar();">Pending Tickets</button>
           <button id="my-tickets-btn" onclick="showPage('my-tickets', this); closeSidebar();">To-Do Tickets</button>
           <button id="submit-ticket-btn" onclick="showPage('resolved-ticket', this); closeSidebar();">Ticket History</button>
        </div>
    </div>
        <div class="sidebar-bottom">
    <button class = "profile-btn" onclick="showPage('profile-section', this); closeSidebar();">Profile</button>
</div>

    <button class="logout-btn" onclick="return confirm('Are you sure you want to logout?') ? window.location.href='logout.php' : false;">
        <span>Logout</span>
    </button>
</div>
 
<div class="content"> 

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
<button onclick="openProfileModal('profileUpdateModal')">Edit Profile</button>
<button onclick="openProfileModal('profilePasswordModal')">Change Password</button>

        </button>
      </div>

    </div>

  </div>
  <!-- UPDATE PROFILE MODAL -->
<div id="profileUpdateModal" class="profile-modal">
  <div class="profile-modal-box">
    <span class="profile-modal-close" onclick="closeProfileModal('profileUpdateModal')">&times;</span>
    <h3>Update Profile</h3>

    <form method="POST" enctype="multipart/form-data">
      <input type="text" name="name"
        value="<?= htmlspecialchars($_SESSION['name'] ?? '') ?>" required>

      <input type="text" name="position"
        value="<?= htmlspecialchars($_SESSION['position'] ?? '') ?>" required>

      <select name="department_id" required>
        <option value="">-- Select Department --</option>
        <?php 
        $departments->data_seek(0);
        while ($row = $departments->fetch_assoc()) : ?>
          <option value="<?= (int)$row['department_id'] ?>"
            <?= ($row['department_id'] == ($_SESSION['department_id'] ?? 0)) ? 'selected' : '' ?>>
            <?= htmlspecialchars($row['department_name']) ?>
          </option>
        <?php endwhile; ?>
      </select>

      <input type="email" name="email"
        value="<?= htmlspecialchars($_SESSION['email'] ?? '') ?>" required>

      <input type="file" name="avatar" accept="image/*">

      <button type="submit" name="update_profile">Save Changes</button>
    </form>
  </div>
</div>

<!-- CHANGE PASSWORD MODAL -->
<div id="profilePasswordModal" class="profile-modal">
  <div class="profile-modal-box">
    <span class="profile-modal-close" onclick="closeProfileModal('profilePasswordModal')">&times;</span>
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
</div>
    <?php endif; ?>

<!-- Summary Page -->
<div class="smmry">
<div id="summary" style="display:none;">
    <!-- Manager Rating Summary -->
    <div class="rating-summary">
        <h3>⭐ Your Performance</h3>
        <?php if ($total_ratings > 0): ?>
            <p class="rating-chip"><?= number_format($avg_rating, 1) ?>/5 <span class="small-note">(<?= $total_ratings ?> feedbacks)</span></p>
        <?php else: ?>
            <p>No ratings yet.</p>
        <?php endif; ?>
    </div>

 <div id="summary-container">
    <div class="total-card">Total Tickets: <?= intval($summary_data['Total']) ?></div>

    <div class="cards-grid">
        <?php foreach ($bar_statuses as $status): 
            $value = intval($summary_data[$status] ?? 0);
            $bar_height = $value * $scale_factor;
            // replace spaces & underscores with dash for class name
            $class_key = strtolower(str_replace([' ', '_'], '-', $status));
        ?>
        <div class="card">
            <h3><?= $status ?></h3>
            <div class="bar bar-<?= $class_key ?>" 
                 data-height="<?= $bar_height ?>px" 
                 style="height: 0px;">
                <span class="bar-label"><?= $value ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<script>
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll('.bar').forEach(bar => {
        const height = bar.dataset.height;
        setTimeout(() => {
            bar.style.height = height;
        }, 200);
    });
});
</script>


</div>
</div>

<div class="Aticks">
<!-- Assigned Tickets -->
<div id="assigned-tickets" style="display:none;">
    <h2>Pending Tickets</h2>

    <table class="ticket-table">
        <thead>
            <tr>
                <th>Ticket No.</th>
                <th>Department</th>
                <th>Sender Name</th>
                <th>Subject</th>
                <th>Issue</th>
                <th>Status</th>
                <th>Documents</th>
                <th>Action</th>
            </tr>
        </thead>

        <tbody id="assigned-body">
            <!-- AJAX will populate this -->
        </tbody>
    </table>

    <!-- Mobile Card View -->
    <div id="assigned-cards" class="cards-container"></div>

</div>
</form>
<script>
function updateAcceptButtons() {
    const rows = document.querySelectorAll("#assigned-body tr");

    const metaRow = document.querySelector("#ticket-meta");

    const active = metaRow ? Number(metaRow.dataset.active) : 0;
    const maxAllowed = metaRow ? Number(metaRow.dataset.max) : 3;

    const limitReached = active >= maxAllowed;

    let enabledCount = 0;
    const maxEnable = 2;

    rows.forEach((row) => {
        const button = row.querySelector(".accept-btn");
        if (!button) return;

        const isWithdrawn = row.dataset.withdrawn === "1";

        button.disabled = true;
        button.title = "";

        if (isWithdrawn) {
            button.title = "You already withdrew this ticket";
            return;
        }

        if (limitReached) {
            button.title = "Max 3 active tickets reached";
            return;
        }

        if (enabledCount < maxEnable) {
            button.disabled = false;
            enabledCount++;
        } else {
            button.title = "Only first 2 selectable";
        }
    });
}
</script>
</div>

<div class="Mticks">
<!-- My Tickets -->
<div id="my-tickets" style="display:none;">
    <h2>My Tickets</h2>
    <table class="ticket-table">
        <thead>
            <tr>
                <th>Ticket No.</th>
                <th>Department</th>
                <th>Sender Name</th>
                <th>Subject</th>
                <th>Issue</th>
                <th>Status</th>
                <th>Documents</th>
                <th>Action</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody id="my-body">
            <tr><td colspan="9">Loading...</td></tr>
        </tbody>
    </table>
    <div id="my-cards" class="cards-container"></div>
</div>
</div>

<div class="Sticks">
<!-- Submit Ticket -->
<div id="resolved-ticket" style="display:none;">
    <h2>Ticket History</h2>
        <form action="export_tickets_supp.php" method="POST">
    <button type="submit" class="export-btn" style = "margin-bottom: 20px;">
        📥 Download Tickets
    </button>
</form>
    <table class="ticket-table">
        <thead>
            <tr>
                <th>Ticket No.</th>
                <th>Department</th>
                <th>Sender Name</th>
                <th>Subject</th>
                <th>Issue</th>
                <th>Status</th>
                <th>Rating</th>
                <th>Documents</th>
            </tr>
        </thead>
        <tbody id="res-body">
            <tr><td colspan="9">Loading...</td></tr>
        </tbody>
    </table>
    <div id="res-cards" class="cards-container"></div>
    
</div>
</div>
</div>
<!-- Rating Modal (hidden by default) --> <div id="rating-modal-backdrop" class="modal-backdrop" style="display:none;"> <div class="modal" role="dialog" aria-modal="true" aria-labelledby="rating-title"> <h3 id="rating-title"> Rate your experience with <span id="rating-handling-manager-name"></span> </h3> <p class="small-note"> Please choose 1–5 stars. Reasons will appear based on your rating (3-5 stars for satisfied, 1-2 for not satisfied). </p>
    <!-- Star Rating -->
    <div class="stars" aria-label="Rating">
        <button type="button" class="star-btn" onclick="pickRating(1, this)">1 ★</button>
        <button type="button" class="star-btn" onclick="pickRating(2, this)">2 ★</button>
        <button type="button" class="star-btn" onclick="pickRating(3, this)">3 ★</button>
        <button type="button" class="star-btn" onclick="pickRating(4, this)">4 ★</button>
        <button type="button" class="star-btn" onclick="pickRating(5, this)">5 ★</button>
    </div>

    <!-- Hidden Satisfied Value -->
    <input type="hidden" id="satisfied-hidden" value="0">

    <!-- Reason Dropdown -->
    <div class="form-group">
        <label for="rating-reason">Reason:</label>
        <select id="rating-reason" required>
            <option value="">-- Select a reason (after choosing stars) --</option>
        </select>
    </div>

    <!-- Optional Comment -->
    <div class="form-group">
        <label for="rating-comments">Optional Comments:</label>
        <textarea id="rating-comments" class="rating-comments" placeholder="Optional comments..."></textarea>
    </div>

    <!-- Hidden Ticket ID -->
    <input type="hidden" id="rating-ticket-id" value="">

    <!-- Submit -->
    <div class="modal-actions">

        <button type="button" class="btn btn-primary" onclick="submitRatingAjax()">Submit Rating</button>
    </div>
</div>
</div> </body> </html> <?php $conn->close();
?>