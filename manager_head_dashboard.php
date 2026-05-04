<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager_head') {
    header("Location: index.php");
    exit();
}

$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->query("SET time_zone = '+08:00'");
$user_id = intval($_SESSION['user_id']);

// Fetch department_id from users table
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

// Fetch department name
$dept_stmt = $conn->prepare("SELECT department_name FROM departments WHERE department_id = ?");
$dept_name = 'Unknown Department';
if ($dept_stmt !== false) {
    $dept_stmt->bind_param('i', $department_id);
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    if ($dept_result->num_rows > 0) {
        $dept_row = $dept_result->fetch_assoc();
        $dept_name = $dept_row['department_name']; // fixed column name
    }
    $dept_stmt->close();
}


$user_name = $_SESSION['name'] ?? 'Manager Head';

$success = '';
$error = '';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// -------------------- Handle AJAX Assign to Manager --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'assign_manager') {
    header('Content-Type: application/json; charset=utf-8');

    $ticket_id  = intval($_POST['ticket_id'] ?? 0);
    $manager_id = intval($_POST['manager_id'] ?? 0);

    if ($ticket_id <= 0 || $manager_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid input.']);
        exit();
    }

    // Verify ticket exists
    $stmt = $conn->prepare("
        SELECT t.ticket_id, t.control_number, t.status, t.manager_id 
        FROM tickets t 
        WHERE t.ticket_id = ?
    ");
    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param('i', $ticket_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Ticket not found.']);
        exit();
    }
    $ticket = $res->fetch_assoc();
    $stmt->close();

    // Check if ticket is assigned to manager_head (preserve previous logic for reassignment)
    if ($ticket['manager_id'] !== null) {
        // Fetch role of current assigned user
        $role_stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
        if ($role_stmt === false) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit();
        }
        $role_stmt->bind_param('i', $ticket['manager_id']);
        $role_stmt->execute();
        $role_res = $role_stmt->get_result();
        if ($role_res->num_rows === 0) {
            $role_stmt->close();
            echo json_encode(['success' => false, 'message' => 'Current assigned user not found.']);
            exit();
        }
        $role_row = $role_res->fetch_assoc();
        $current_role = $role_row['role'];
        $role_stmt->close();

        // If assigned to manager_head, allow reassignment to manager or support staff (preserve workflow)
        if ($current_role === 'manager_head') {
            // Verify new assignee is role 'manager' or 'support_staff' and active (extended for support staff if needed)
            $mstmt = $conn->prepare("
                SELECT user_id, name, role 
                FROM users 
                WHERE user_id = ? AND status = 'active' AND (role = 'manager' OR role = 'support_staff')
            ");
            if ($mstmt === false) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                exit();
            }
            $mstmt->bind_param('i', $manager_id);
            $mstmt->execute();
            $mres = $mstmt->get_result();
            if ($mres->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid support staff selected.']);
                exit();
            }
            $assignee = $mres->fetch_assoc();
            $mstmt->close();

            // Assign to the selected user (manager or support)
            $ustmt = $conn->prepare("UPDATE tickets SET manager_id = ?, assigned_at = NOW() WHERE ticket_id = ?");
            if ($ustmt === false) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                exit();
            }
            $ustmt->bind_param('ii', $manager_id, $ticket_id);
            if (!$ustmt->execute()) {
                echo json_encode(['success' => false, 'message' => 'Failed to assign ticket.']);
                exit();
            }
            $ustmt->close();

            // Log action (preserve previous logging)
            $assignee_type = ($assignee['role'] === 'support_staff') ? 'Manager' : 'Support Staff';
            $action = "Reassigned ticket " . $ticket['control_number'] . " from Manager Head to " . $assignee_type . " " . $assignee['name'];
            $lstmt = $conn->prepare("INSERT INTO audit_log (user_id, action, log_time) VALUES (?, ?, NOW())");
            if ($lstmt !== false) {
                $lstmt->bind_param('is', $user_id, $action);
                $lstmt->execute();
                $lstmt->close();
            }

            echo json_encode(['success' => true, 'message' => 'Ticket reassigned to ' . $assignee_type . ' ' . $assignee['name'] . ' successfully!']);
            exit();
        } else {
            // If not assigned to head, do not allow reassignment (preserve logic)
            echo json_encode(['success' => false, 'message' => 'Ticket is already assigned and cannot be reassigned.']);
            exit();
        }
    } else {
        // Ticket is unassigned, verify assignee (manager or support staff)
        $mstmt = $conn->prepare("
            SELECT user_id, name, role 
            FROM users 
            WHERE user_id = ? AND status = 'active' AND (role = 'manager' OR role = 'support_staff') AND department_id = ?
        ");
        if ($mstmt === false) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit();
        }
        $mstmt->bind_param('ii', $manager_id, $department_id);
        $mstmt->execute();
        $mres = $mstmt->get_result();
        if ($mres->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid support staff selected.']);
            exit();
        }
        $assignee = $mres->fetch_assoc();
        $mstmt->close();

        // Assign to the selected user
        $ustmt = $conn->prepare("UPDATE tickets SET manager_id = ? WHERE ticket_id = ?");
        if ($ustmt === false) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit();
        }
        $ustmt->bind_param('ii', $manager_id, $ticket_id);
        if (!$ustmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Failed to assign ticket.']);
            exit();
        }
        $ustmt->close();

        // Log action
        $assignee_type = ($assignee['role'] === 'support_staff') ? 'Manager' : 'Support Staff';
        $action = "Assigned ticket " . $ticket['control_number'] . " to " . $assignee_type . " " . $assignee['name'];
        $lstmt = $conn->prepare("INSERT INTO audit_log (user_id, action, log_time) VALUES (?, ?, NOW())");
        if ($lstmt !== false) {
            $lstmt->bind_param('is', $user_id, $action);
            $lstmt->execute();
            $lstmt->close();
        }

        echo json_encode(['success' => true, 'message' => 'Ticket assigned to ' . $assignee_type . ' ' . $assignee['name'] . ' successfully!']);
        exit();
    }
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

// ------------------- Handle AJAX Load Tickets --------------------

if (isset($_GET['action']) && $_GET['action'] === 'load_tickets') {

    header('Content-Type: text/html; charset=utf-8');

    $page = max(1, intval($_GET['page'] ?? 1));

    $limit = 10;
    $offset = ($page - 1) * $limit;

    /*
    ===============================
    WHERE Clause (Pending Only)
    ===============================
    */

$where = "t.department_id = ? 
          AND t.status = 'pending' 
          AND t.escalated = 0";

$params = [$department_id];
$types = 'i';

    /*
    ===============================
    Main Query
    ===============================
    */

    $full_query = "
        SELECT t.*, 
               COALESCE(u.name, 'Unknown') AS submitted_by, 
               s.name AS assigned_manager,
               LEFT(COALESCE(t.description, t.issue, ''), 100) AS short_desc,
               COALESCE(t.description, t.issue, '') AS full_desc
        FROM tickets t 
        LEFT JOIN users u ON t.user_id = u.user_id
        LEFT JOIN users s ON t.manager_id = s.user_id 
        WHERE $where 
        ORDER BY t.created_at ASC 
        LIMIT ? OFFSET ?
    ";

    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($full_query);

    if (!$stmt) {
        echo '<tr><td colspan="7">Database error.</td></tr>';
        exit();
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    /*
    ===============================
    Render Table Rows
    ===============================
    */

    $html = '';

    if ($res->num_rows === 0) {
        $html = '<tr><td colspan="7">No tickets found.</td></tr>';
    } else {

        while ($row = $res->fetch_assoc()) {

            $issue_full = nl2br(htmlspecialchars($row['issue'] ?: 'No issue description.'));
            $issue_short = strlen($row['issue'] ?? '') > 40
                ? htmlspecialchars(substr($row['issue'], 0, 40)) . '...'
                : htmlspecialchars($row['issue'] ?? '');

            $status_class = 'status-' . str_replace('_', '-', strtolower($row['status']));
            $status_html = '<span class="status-pill ' . $status_class . '">'
                . ucwords(str_replace('_', ' ', $row['status']))
                . '</span>';

            $created_at = date('Y-m-d H:i:s', strtotime($row['created_at']));

            $duration_tooltip = '';

            if (!empty($row['accepted_at'])) {
                $duration_tooltip .= 'Start (Accepted): '
                    . date('Y-m-d H:i:s', strtotime($row['accepted_at'])) . '<br>';
            }

            if (!empty($row['ended_at'])) {
                $duration_tooltip .= 'End: '
                    . date('Y-m-d H:i:s', strtotime($row['ended_at'])) . '<br>';
            }

            if (!empty($row['closed_at'])) {
                $duration_tooltip .= 'Closed: '
                    . date('Y-m-d H:i:s', strtotime($row['closed_at'])) . '<br>';
            }

            if ($row['status'] === 'completed' && !empty($row['closed_at'])) {

                $closed_time = strtotime($row['closed_at']);
                $create_time = strtotime($row['created_at']);
                $duration = $closed_time - $create_time;

                $days = floor($duration / 86400);
                $hours = floor(($duration % 86400) / 3600);
                $mins = floor(($duration % 3600) / 60);

                $tooltip_text = '';

                if ($days > 0) $tooltip_text .= $days . 'd ';
                if ($hours > 0) $tooltip_text .= $hours . 'h ';
                $tooltip_text .= $mins . 'm';

                $duration_tooltip .= 'Total Duration: ' . trim($tooltip_text);
            }

            $created_at_html = $created_at;

            if (!empty($duration_tooltip)) {
                $created_at_html =
                    '<span class="tooltip">'
                    . $created_at .
                    '<span class="tooltiptext">'
                    . $duration_tooltip .
                    '</span></span>';
            }

            /*
            Assignment Display Logic
            */

            if ($row['status'] === 'pending') {
                $assign_html = '--';
            } else {
                $assign_html = empty($row['manager_id'])
                    ? '--'
                    : 'Assigned to ' . htmlspecialchars($row['assigned_manager'] ?? 'Unknown');
            }

            $html .= '
            <tr>
                <td>' . htmlspecialchars($row['control_number']) . '</td>
                <td>' . htmlspecialchars($row['title']) . '</td>
                <td>
                   ' . htmlspecialchars($issue_full) . '
                </td>
                <td>' . $status_html . '</td>
                <td>' . htmlspecialchars($row['submitted_by']) . '</td>
                <td>' . $created_at_html . '</td>
                <td>' . $assign_html . '</td>
            </tr>';
        }

        $stmt->close();
    }

$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;

/*
===============================
Count Pending Tickets First
===============================
*/

$count_where = "department_id = ? AND status = 'pending'";
$count_params = [$department_id];
$count_types = 'i';

$cstmt = $conn->prepare("
    SELECT COUNT(*) AS cnt 
    FROM tickets 
    WHERE $count_where
");

$total = 0;

if ($cstmt) {
    $cstmt->bind_param($count_types, ...$count_params);
    $cstmt->execute();
    $total = $cstmt->get_result()->fetch_assoc()['cnt'] ?? 0;
    $cstmt->close();
}

$pages = max(1, ceil($total / $limit));

/* Fix page BEFORE offset */
$page = min(max(1, $page), $pages);

/* NOW compute offset */
$offset = ($page - 1) * $limit;

    /*
    Pagination Rendering
    */

    $pages = max(1, ceil($total / $limit));
    $page = min(max(1, $page), $pages);

    $pag_html = '<div style="text-align:center; padding:10px;">';

    if ($page > 1) {
        $pag_html .= '<button class="page-btn" data-page="' . ($page - 1) . '">Previous</button> ';
    }

    for ($i = 1; $i <= $pages; $i++) {

        $active = ($i == $page)
            ? ' style="background:#007BFF;color:white;"'
            : '';

        $pag_html .= '<button class="page-btn" data-page="' . $i . '"' . $active . '>'
            . $i .
            '</button> ';
    }

    if ($page < $pages) {
        $pag_html .= '<button class="page-btn" data-page="' . ($page + 1) . '">Next</button>';
    }

    $pag_html .= '</div>';

    echo $html . '<!--PAGINATION-->' . $pag_html;
    exit();
}

// -------------------- Handle AJAX Load Manager Tickets for Card --------------------
if (isset($_GET['action']) && $_GET['action'] === 'load_manager_tickets') {

    header('Content-Type: text/html; charset=utf-8');

    $manager_id_filter = intval($_GET['manager_id'] ?? 0);
    $status_filter = $_GET['status'] ?? 'all';

    if ($manager_id_filter === 0) {
        echo '<p>Invalid manager ID.</p>';
        exit();
    }

    // Base condition
    $where = "manager_id = ?";
    $params = [$manager_id_filter];
    $types = 'i';

    // Allowed statuses
    $allowed_statuses = ['in_progress', 'closed', 'resolved', 'all'];

    if (!in_array($status_filter, $allowed_statuses)) {
        $status_filter = 'all';
    }

    // Status filtering logic
    if ($status_filter !== 'all') {
        $where .= " AND status = ?";
        $params[] = $status_filter;
        $types .= 's';
    } else {
        // Show total of in_progress + closed + resolved
        $where .= " AND status IN ('in_progress','closed','resolved')";
    }

    $query = "SELECT ticket_id, control_number, title, status 
              FROM tickets 
              WHERE $where 
              ORDER BY created_at ASC";

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        echo '<p>Database error: ' . $conn->error . '</p>';
        exit();
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $html = '<ul>';

    if ($res->num_rows === 0) {
        $html .= '<li>No ' . htmlspecialchars($status_filter) . ' tickets found.</li>';
    } else {
        while ($row = $res->fetch_assoc()) {

            $status_class = 'status-' . str_replace('_', '-', strtolower($row['status']));

            $html .= '<li>'
                . htmlspecialchars($row['control_number'])
                . ' - '
                . htmlspecialchars($row['title'])
                . ' <span class="status-pill ' . $status_class . '">'
                . ucwords(str_replace('_', ' ', $row['status']))
                . '</span>'
                . '</li>';
        }
    }

    $html .= '</ul>';
    echo $html;
    exit();
}


//==================TICKET HISTORY=============================
if (isset($_GET['action']) && $_GET['action'] === 'load_history') {

    header('Content-Type: text/html; charset=utf-8');

    $status_filt = ['resolved', 'closed'];

    $pag = max(1, intval($_GET['page'] ?? 1));
    $limi = 10;
    $offse = ($pag - 1) * $limi;

    if (!isset($department_id)) {
        echo '<tr><td colspan="7">Invalid department.</td></tr>';
        exit();
    }

    /*
    WHERE Clause
    */

    $placeholders = implode(',', array_fill(0, count($status_filt), '?'));
    $where = "t.department_id = ? AND t.status IN ($placeholders)";

    $param = array_merge([$department_id], $status_filt);
    $type = 'i' . str_repeat('s', count($status_filt));

    /*
    Ticket Query
    */

    $query = "
        SELECT t.*, 
               COALESCE(u.name, 'Unknown') AS submitted_by, 
               s.name AS assigned_manager,
               LEFT(COALESCE(t.description, t.issue, ''), 100) AS short_desc
        FROM tickets t 
        LEFT JOIN users u ON t.user_id = u.user_id
        LEFT JOIN users s ON t.manager_id = s.user_id 
        WHERE $where 
        ORDER BY t.created_at ASC
        LIMIT ? OFFSET ?
    ";

    $param[] = $limi;
    $param[] = $offse;

    $type .= 'ii';

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        echo '<tr><td colspan="7">Database error.</td></tr>';
        exit();
    }

    $stmt->bind_param($type, ...$param);
    $stmt->execute();
    $res = $stmt->get_result();

    $html = '';

    if ($res->num_rows === 0) {
        $html = '<tr><td colspan="7">No tickets found.</td></tr>';
    } else {

        while ($row = $res->fetch_assoc()) {

            $issue_short = '';

            if (!empty($row['issue'])) {
                $issue_short = mb_strlen($row['issue']) > 40
                    ? htmlspecialchars(mb_substr($row['issue'], 0, 40)) . '...'
                    : htmlspecialchars($row['issue']);
            }

            $status_class = 'status-' . str_replace('_', '-', strtolower($row['status']));

$status_html = '
    <span class="status-pill ' . $status_class . '">
        ' . ucfirst($row['status']) . '
    </span>
';
$assign = '--';

if (in_array($row['status'], ['resolved', 'closed'])) {
    $assign = empty($row['manager_id'])
        ? '--'
        : 'Assigned to ' . htmlspecialchars($row['assigned_manager'] ?? 'Unknown');
}

            $created_at = date('Y-m-d H:i:s', strtotime($row['created_at']));

            $html .= "
            <tr>
                <td>" . htmlspecialchars($row['control_number']) . "</td>
                <td>" . htmlspecialchars($row['title']) . "</td>
                <td>{$issue_short}</td>
                <td>{$status_html}</td>
                <td>" . htmlspecialchars($row['submitted_by']) . "</td>
                <td>{$created_at}</td>
                <td> {$assign}</td>
            </tr>";
        }

        $stmt->close();
    }

    /*
    Pagination Count
    */

    $count_placeholders = implode(',', array_fill(0, count($status_filt), '?'));

    $count_query = "
        SELECT COUNT(*) AS cnt 
        FROM tickets 
        WHERE department_id = ? 
        AND status IN ($count_placeholders)
    ";

    $count_params = array_merge([$department_id], $status_filt);
    $count_types = 'i' . str_repeat('s', count($status_filt));

    $cstmt = $conn->prepare($count_query);

    $tota = 0;

    if ($cstmt) {
        $cstmt->bind_param($count_types, ...$count_params);
        $cstmt->execute();
        $tota = $cstmt->get_result()->fetch_assoc()['cnt'] ?? 0;
        $cstmt->close();
    }

    /*
    Pagination HTML
    */

    $page = ceil($tota / $limi);

    $pag_html = '<div style="text-align:center; padding:10px;">';

    if ($page > 1) {
        $pag_html .= '<button class="page-btn" data-page="'.($page-1).'">Previous</button> ';
    }

    for ($i = 1; $i <= $page; $i++) {

        $active = ($i == $page)
            ? ' style="background:#065f46;color:white;"'
            : '';

        $pag_html .= '<button class="page-btn" data-page="'.$i.'"'.$active.'>'.$i.'</button> ';
    }

    if ($page < $page) {
        $pag_html .= '<button class="page-btn" data-page="'.($page+1).'">Next</button>';
    }

    $pag_html .= '</div>';

    echo $html . '<!--PAGINATION-->' . $pag_html;
    exit();
}

//---------------------------- LOAD IN_PROGRESS TICKETS-------------

if (isset($_GET['action']) && $_GET['action'] === 'load_in_progress') {

    header('Content-Type: text/html; charset=utf-8');

    /*
    ===============================
    Configuration
    ===============================
    */

    $status_prog = ['in_progress'];

    $page_prog = max(1, intval($_GET['page'] ?? 1));
    $limit_prog = 10;
    $offset_prog = ($page_prog - 1) * $limit_prog;

    /*
    ===============================
    Department Validation
    ===============================
    */

    if (empty($department_id)) {
        echo '<tr><td colspan="7">Invalid department.</td></tr>';
        exit;
    }

    /*
    ===============================
    WHERE Clause Builder
    ===============================
    */

    $placeholders_prog = implode(',', array_fill(0, count($status_prog), '?'));

    $where_prog = "t.department_id = ? AND t.status IN ($placeholders_prog)";

    $params_prog = array_merge([$department_id], $status_prog);
    $types_prog = 'i' . str_repeat('s', count($status_prog));

    /*
    ===============================
    Ticket Query
    ===============================
    */

    $query_prog = "
        SELECT 
            t.control_number,
            t.title,
            t.issue,
            t.queue_number,
            t.description,
            t.created_at,
            t.assigned_staff_id,
            t.manager_id,

            COALESCE(u.name, 'Unknown') AS submitted_by_prog,
            COALESCE(s.name, '') AS assigned_manager_prog

        FROM tickets t

        LEFT JOIN users u ON u.user_id = t.user_id
        LEFT JOIN users s ON s.user_id = t.manager_id

        WHERE $where_prog

        ORDER BY t.queue_number ASC

        LIMIT ? OFFSET ?
    ";

    $params_prog[] = $limit_prog;
    $params_prog[] = $offset_prog;

    $types_prog .= 'ii';

    $stmt_prog = $conn->prepare($query_prog);

    if (!$stmt_prog) {
        echo '<tr><td colspan="7">Database error.</td></tr>';
        exit;
    }

    $stmt_prog->bind_param($types_prog, ...$params_prog);
    $stmt_prog->execute();

    $res_prog = $stmt_prog->get_result();

    /*
    ===============================
    Table HTML Builder
    ===============================
    */

    $html_prog = '';

    if ($res_prog->num_rows === 0) {

        $html_prog = '<tr><td colspan="7">No in-progress tickets found.</td></tr>';

    } else {

        while ($row = $res_prog->fetch_assoc()) {

            /*
            Short Issue Preview
            */

            $issue_short = '';

            if (!empty($row['issue'])) {

                $issue_short = mb_strlen($row['issue']) > 40
                    ? htmlspecialchars(mb_substr($row['issue'], 0, 40)) . '...'
                    : htmlspecialchars($row['issue']);
            }

            /*
            Status Pill
            */

            $status_html = '
                <span class="status-pill status-in-progress">
                    In Progress
                </span>
            ';

            /*
            Assignment Display
            */

            $assign_text = '--';

            if (!empty($row['assigned_manager_prog'])) {
                $assign_text = 'Assigned to ' . htmlspecialchars($row['assigned_manager_prog']);
            }

            /*
            Created Date
            */

            $created_at = !empty($row['created_at'])
                ? date('Y-m-d H:i:s', strtotime($row['created_at']))
                : '';

            /*
            Row HTML
            */

            $html_prog .= "
            <tr>
                <td>" . htmlspecialchars($row['queue_number']) . "</td>
                <td>" . htmlspecialchars($row['control_number']) . "</td>
                <td>" . htmlspecialchars($row['title']) . "</td>
                <td>{$issue_short}</td>
                <td>{$status_html}</td>
                <td>" . htmlspecialchars($row['submitted_by_prog']) . "</td>
                <td>{$created_at}</td>
                <td>{$assign_text}</td>
            </tr>";
        }
    }

    $stmt_prog->close();

    /*
    ===============================
    Pagination Count
    ===============================
    */

    $count_placeholders = implode(',', array_fill(0, count($status_prog), '?'));

    $count_sql = "
        SELECT COUNT(*) AS cnt_prog
        FROM tickets
        WHERE department_id = ?
        AND status IN ($count_placeholders)
    ";

    $count_params = array_merge([$department_id], $status_prog);
    $count_types = 'i' . str_repeat('s', count($status_prog));

    $cstmt_prog = $conn->prepare($count_sql);

    $total_prog = 0;

    if ($cstmt_prog) {

        $cstmt_prog->bind_param($count_types, ...$count_params);
        $cstmt_prog->execute();

        $total_prog = $cstmt_prog
            ->get_result()
            ->fetch_assoc()['cnt_prog'] ?? 0;

        $cstmt_prog->close();
    }

    /*
    ===============================
    Pagination HTML
    ===============================
    */

    $total_pages_prog = ceil($total_prog / $limit_prog);

    $pag_html_prog = '<div style="text-align:center; padding:10px;">';

    if ($page_prog > 1) {
        $pag_html_prog .= '<button class="page-btn-prog" data-page="'.($page_prog-1).'">Previous</button> ';
    }

    for ($i = 1; $i <= $total_pages_prog; $i++) {

        $active_style = ($i == $page_prog)
            ? ' style="background:#065f46;color:white;"'
            : '';

        $pag_html_prog .= '<button class="page-btn-prog" data-page="'.$i.'"'.$active_style.'>'.$i.'</button> ';
    }

    if ($page_prog < $total_pages_prog) {
        $pag_html_prog .= '<button class="page-btn-prog" data-page="'.($page_prog+1).'">Next</button>';
    }

    $pag_html_prog .= '</div>';

    /*
    ===============================
    Output
    ===============================
    */

    echo $html_prog . '<!--PAGINATION-->' . $pag_html_prog;

    exit();
}


//====================Ticket Action =====================
$success = '';
if (!empty($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['ticket_action']) && isset($_POST['ticket_id'])) {

    $ticket_id = intval($_POST['ticket_id']);
    $action = $_POST['ticket_action'];

    // Fetch ticket details
    $stmt = $conn->prepare("SELECT ticket_id, control_number, status, department_id, manager_id 
                            FROM tickets WHERE ticket_id = ?");
    $stmt->bind_param('i', $ticket_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $ticket = $res->fetch_assoc() ?? null;
    $stmt->close();

    if (!$ticket) {
        $_SESSION['error_message'] = "Ticket not found.";
        header("Location: manager_head_dashboard.php");
        exit();
    }

    $status = $ticket['status'];
    $control_number = $ticket['control_number'];
    $department_id = $ticket['department_id'];
    $manager_id_ticket = $ticket['manager_id'];

    // --------------------------- HANDLE ACTIONS ---------------------------
    if ($status === 'closed') {
        $_SESSION['error_message'] = "Ticket is already closed.";
        header("Location: manager_head_dashboard.php");
        exit();
    }

    switch ($action) {

        case "accept":

            $conn->begin_transaction();

            try {

                $stmtLock = $conn->prepare("
                    SELECT MAX(queue_number) AS max_queue 
                    FROM tickets 
                    WHERE department_id=? 
                    AND queue_number IS NOT NULL
                    FOR UPDATE
                ");

                $stmtLock->bind_param('i', $department_id);
                $stmtLock->execute();

                $resLock = $stmtLock->get_result();
                $rowLock = $resLock->fetch_assoc();

                $next_queue = ($rowLock['max_queue'] ?? 0) + 1;

                $stmtLock->close();


                // Update ticket
                $stmtUpdate = $conn->prepare("
                    UPDATE tickets 
                    SET status='in_progress',
                        accepted_at=NOW(),
                        queue_number=?
                    WHERE ticket_id=?
                ");

                $stmtUpdate->bind_param('ii', $next_queue, $ticket_id);
                $stmtUpdate->execute();
                $stmtUpdate->close();


                $conn->commit();

                $display_id = $control_number ?? $ticket_id;
                $_SESSION['success_message'] =
                    "Ticket #$display_id accepted with Queue #$next_queue for Department #$department_id.";

            } catch (Exception $e) {

                $conn->rollback();

                $_SESSION['error_message'] =
                    "Failed to accept ticket: " . $e->getMessage();
            }

        break;

        default:
            $_SESSION['error_message'] = "Invalid action.";
        break;
    }

    header("Location: manager_head_dashboard.php");
    exit();
}


// -------------------- Fetch Data for Assign Tickets Section --------------------
// Start Assign Tickets Section Replacement
$available_managers = [];
$pending_tickets = [];

// Fetch available managers (role: manager, active, same department)
$manager_stmt = $conn->prepare("
    SELECT user_id, name, position, email 
    FROM users 
    WHERE department_id = ? AND status = 'active' AND role = 'support_staff' 
    ORDER BY name
");
if ($manager_stmt !== false) {
    $manager_stmt->bind_param('i', $department_id);
    $manager_stmt->execute();
    $manager_result = $manager_stmt->get_result();
    while ($row = $manager_result->fetch_assoc()) {
        $available_managers[] = $row;
    }
    $manager_stmt->close();
}

// FIX: Updated query for pending tickets to include those assigned to the manager head
$ticket_stmt = $conn->prepare("
    SELECT ticket_id, control_number, title, LEFT(COALESCE(description, issue, ''), 50) AS preview 
    FROM tickets 
    WHERE department_id = ? AND status = 'pending' AND (manager_id IS NULL OR manager_id = ?)
    ORDER BY created_at ASC
");
if ($ticket_stmt === false) {
    die("Database error: " . $conn->error);
}
$ticket_stmt->bind_param('ii', $department_id, $user_id); // Bind $user_id here
$ticket_stmt->execute();
$ticket_result = $ticket_stmt->get_result();
$pending_tickets = []; // Re-initialize to ensure it's always an array
while ($row = $ticket_result->fetch_assoc()) {
    $preview = strlen($row['preview']) > 50 ? substr($row['preview'], 0, 50) . '...' : $row['preview'];
    $pending_tickets[] = [
        'ticket_id' => intval($row['ticket_id']),
        'control_number' => $row['control_number'],
        'title' => $row['title'],
        'preview' => $preview
    ];
}
$ticket_stmt->close();


// Build assigned tickets per manager (for display)
$assigned_tickets_per_manager = [];
$m_tstmt = $conn->prepare("SELECT ticket_id, control_number, title, status, manager_id FROM tickets WHERE department_id = ? AND manager_id IS NOT NULL ORDER BY created_at ASC");
if ($m_tstmt !== false) {
    $m_tstmt->bind_param('i', $department_id);
    $m_tstmt->execute();
    $m_tres = $m_tstmt->get_result();
    while ($mt = $m_tres->fetch_assoc()) {
        $mid = intval($mt['manager_id']);
        if (!isset($assigned_tickets_per_manager[$mid])) $assigned_tickets_per_manager[$mid] = [];
        $assigned_tickets_per_manager[$mid][] = $mt;
    }
    $m_tstmt->close();
}
// End Assign Tickets Section Replacement

// -------------------- Summary Data --------------------
// Start Summary Section Replacement
$summary_data = [
    'Total' => 0,
    'Pending' => 0,
    'In Progress' => 0,
    'Resolved' => 0,
    'Closed' => 0,
    'Declined' => 0,
    'Cancelled' => 0,
    'Postponed' => 0,
];

$statuses = ['pending', 'in_progress', 'resolved', 'closed', 'declined', 'cancelled', 'postponed'];
foreach ($statuses as $status) {
    $cstmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tickets WHERE department_id = ? AND status = ?");
    if ($cstmt !== false) {
        $cstmt->bind_param('is', $department_id, $status);
        $cstmt->execute();
        $summary_data[ucwords(str_replace('_', ' ', $status))] = $cstmt->get_result()->fetch_assoc()['cnt'];
        $cstmt->close();
    }
}
$summary_data['Total'] = array_sum(array_slice($summary_data, 1));

// Fetch total ratings for managers (assuming 'rating' field in users table)
// This query needs to sum up average ratings of managers in the department, not a single 'rating' field in users.
// Assuming ticket_feedback table stores ratings for tickets, and tickets are assigned to managers.
$total_department_avg_rating = 0;
$total_managers_with_ratings = 0;

$ratings_query = "
    SELECT AVG(tf.rating) AS avg_manager_rating
    FROM ticket_feedback tf
    JOIN tickets t ON tf.ticket_id = t.ticket_id
    JOIN users u ON t.manager_id = u.user_id
    WHERE u.department_id = ? AND u.role = 'support_staff'
    GROUP BY u.user_id
";
$ratings_stmt = $conn->prepare($ratings_query);
if ($ratings_stmt !== false) {
    $ratings_stmt->bind_param('i', $department_id);
    $ratings_stmt->execute();
    $ratings_result = $ratings_stmt->get_result();
    $sum_of_avg_ratings = 0;
    while ($row = $ratings_result->fetch_assoc()) {
        $sum_of_avg_ratings += $row['avg_manager_rating'];
        $total_managers_with_ratings++;
    }
    if ($total_managers_with_ratings > 0) {
        $total_department_avg_rating = $sum_of_avg_ratings / $total_managers_with_ratings;
    }
    $ratings_stmt->close();
}


$max_value = max(array_values($summary_data));
$scale_factor = $max_value > 0 ? 150 / $max_value : 1;

// Bar statuses (removed Resolved and Postponed)
$bar_statuses = ['Pending', 'In Progress', 'Resolved'];
// End Summary Section Replacement

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Manager Head Dashboard</title>
<style>
/* Base Layout */
body {
    margin: 0;
    font-family: Arial, sans-serif;
    overflow-x: hidden;
    /* Shift right para di matakpan ng sidebar */
    margin-left: 300px;
    
}

.sidebar {
    position: fixed;       /* laging nakafixed sa left */
    top: 0;
    left: 0;
    width: 260px;
    height: 100vh;
    background: #e5e7eb;  
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 20px;
    box-shadow: 2px 0 5px rgba(0,0,0,0.2);
    z-index: 1000;
}


/* Profile Section */
.profile {
  text-align: center;
  margin-bottom: 30px;
}
.avatar {
    width: 80px; 
    height: 80px; 
    background: #048c28; 
    border-radius: 50%; 
    margin: 0 auto 10px; 
    border: 3px solid #000;
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

/* Menu */
.menu {
  flex-grow: 1;
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
    margin-top: 60px;
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


/* Logout Button (desktop default) */
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
    margin-bottom: 50px;  /* mas maliit para itaas pa */
    transition: background 0.3s ease;
    margin-left: 10px;
}
.logout-btn:hover {
  background: #cc0000;
}

.content {
    flex-grow: 1;
    padding: 20px;

    /* default: border lang */
    margin-left: 8px;
    transition: margin-left 0.35s ease;
}
/* Tables */
/* Table with rounded corners */
table.ticket-table {
  width: 100%;
  border-collapse: separate; /* important for border-radius to work */
  border-spacing: 0;         /* remove gaps between cells */
  margin-top: 20px;
  background: #eeeae3;
  border-radius: 12px;
  overflow: hidden;          /* ensures corners are clipped */
}

/* Table cells */
th,
td {
  padding: 10px;
  border: 1px solid #ddd;
  text-align: left;
  word-wrap: break-word;
  vertical-align: top;
  font-size: 12px;
}

/* Table header */
th {
  background: #065f46;
  color: white;
  font-weight: bold;
  text-align: center;
}
/* Adjust the width of the 4th column (Title column) */
table.ticket-table th:nth-child(4),
table.ticket-table td:nth-child(4) {
    width: 100px; /* change to desired width */
    max-width: 100px; /* optional: prevent it from growing too much */
}
table.ticket-table th:nth-child(5),
table.ticket-table td:nth-child(5) {
    width: 90px; /* change to desired width */
    max-width: 90px; /* optional: prevent it from growing too much */
}
table.ticket-table th:nth-child(6),
table.ticket-table td:nth-child(6) {
    width: 90px; /* change to desired width */
    max-width: 90px; /* optional: prevent it from growing too much */
}

/* Forms & Filters */
.form-group {
  margin-bottom: 15px;
}
label {
  display: block;
  margin-bottom: 6px;
}
select,
#status-filter {
  padding: 8px;
  border-radius: 6px;
  border: 1px solid #ccc;
}
.filter-group {
  margin-bottom: 20px;
}

/* Messages */
.success {
  color: green;
  font-weight: bold;
  padding: 10px;
  background: #d4edda;
  border: 1px solid #c3e6cb;
  border-radius: 4px;
  margin-bottom: 10px;
}
.error {
  color: red;
  font-weight: bold;
  padding: 10px;
  background: #f8d7da;
  border: 1px solid #f5c6cb;
  border-radius: 4px;
  margin-bottom: 10px;
}

/* Status Pills */
.status-pill {
  padding: 4px 8px;
  border-radius: 10px;
  color: white;
  font-size: 12px;
}
.status-pending { background: gray; }
.status-cancelled { background: #8e44ad; }
.status-in-progress { background: orange; }
.status-resolved { background: green; }
.status-closed { background: blue; }
.status-declined { background: red; }
.status-postponed { background: #ffc107; color: black; }
.status-reopened { background:#007BFF; }

/* Details/Summary */
details summary {
  cursor: pointer;
  font-weight: bold;
  color: #007BFF;
  list-style: none;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
details summary::-webkit-details-marker { display: none; }
details summary::after {

  margin-left: 8px;
  transition: transform 0.2s;
}
details[open] summary::after { transform: rotate(180deg); }
details[open] summary { color: #0056b3; }

.issue-box {
  max-height: 120px;
  overflow-y: auto;
  padding: 6px;
  border: 1px solid #ccc;
  background: #fafafa;
  border-radius: 6px;
  margin-top: 5px;
  white-space: pre-line;
}

/* Assignment */
.assign-select,
.assign-btn {
  padding: 5px;
  margin: 2px;
  border-radius: 4px;
  border: 1px solid #065f46;
}
.assign-btn {
  background: #065f46;
  color: white;
  border: none;
  cursor: pointer;
}
.assign-btn:hover { background: #0056b3; }

/* Summary Container */
#summary-container {
  max-width: 1100px;
  margin: 10px auto;
  padding: 10px;
}

/* Rating summary box */
.rating-summary {
  background: #fff3cd;
  border: 2px solid #ffd900ff;
  padding: 15px;
  border-radius: 10px;
  margin: 10px auto 1px auto;
  max-width: 1100px;
  text-align: center;
  box-shadow: 0 5px 6px rgba(252, 255, 94, 0.39);
}
.rating-summary h3 {
  margin: 0 0 6px;
  font-size: 20px;
}
.rating-chip {
  font-size: 20px;
  font-weight: bold;
  color: #856404;
}
.small-note {
  font-size: 12px;
  color: #666;
}

/* Total tickets */
.total-card {
  background: #e0f0ff;
  padding: 15px 25px;
  border-radius: 12px;
  border: 1px solid #b0d4ff;
  font-size: 20px;
  font-weight: bold;
  box-shadow: 0 4px 8px rgba(37, 139, 109, 0.78);
  margin: 2px auto;
  text-align: center;
  max-width: 1100px;
}

/* Cards Grid */
.cards-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
  margin-top: 15px;
  align-items: end;
}

/* Cards */
.card {
  background: #f5f5f5;
  border-radius: 12px;
  padding-top: 25px;
  box-shadow: 0 4px 8px rgba(97, 97, 97, 0.78);
  display: flex;
  flex-direction: column;
  position: relative;
  align-items: center;
  justify-content: flex-end;
  text-align: center;
  height: 320px;
}
.card h3 {
  margin: 0;
  font-size: 16px;
  position: absolute;
  top: 10px;
  left: 50%;
  transform: translateX(-50%);
}

/* Growing Bars */
.bar {
  width: 60%;
  border-radius: 6px 6px 0 0;
  color: white;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  font-weight: bold;
  transition: height 2s ease;
  position: relative;
  bottom: 0;
  margin-top: 30px;
}
.bar-label {
  position: absolute;
  top: -20px;
  font-size: 14px;
  color: black;
  font-weight: bold;
}
.bar-pending { background: gray; }
.bar-in-progress { background: orange; }
.bar-closed { background: blue; }
.bar-resolved { background: green; }
.bar-declined { background: red; }
.bar-cancelled { background: #8e44ad; }

.accept-btn { 
    background:#065f46; 
    color: white;
}

.accept-btn:hover { 
    background:#1e7e34; 
}

/* Ratings */
.rating-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 8px;
  background: #f0f0f0;
  color: #333;
  font-weight: bold;
  font-size: 13px;
  vertical-align: middle;
}
.rating-stars {
  color: #f1c40f;
  margin-left: 6px;
  font-size: 14px;
  vertical-align: middle;
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
.duration-btn:hover { background-color: #cce4ff; }

/* Tooltip */
.tooltip { position: relative; display: inline-block; }
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
.tooltip:hover .tooltiptext { visibility: visible; opacity: 1; }

/* Reason Title */
.reason-title {
  font-weight: bold;
  margin-top: 8px;
  margin-bottom: 4px;
  color: #333;
}

/* Pagination */
.page-btn {
  margin: 2px;
  padding: 5px 10px;
  border: 1px solid #065f46;
  background: #065f46;
  cursor: pointer;
  border-radius: 4px;
}
.page-btn:hover { background: #10b981; }

.page-btn-prog {
  margin: 2px;
  padding: 5px 10px;
  border: 1px solid #065f46;
  background: #065f46;
  cursor: pointer;
  border-radius: 4px;
}
.page-btn-prog:hover { background: #10b981; }

/* Modal */
.modal-backdrop {
  position: fixed;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: rgba(0,0,0,0.5);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.modal {
  width: 420px;
  background: #fff;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 6px 20px rgba(0,0,0,0.2);
  max-width: 90vw;
}
.modal h3 { margin-top: 0; }
.stars {
  display: flex;
  gap: 6px;
  margin: 10px 0 15px;
  flex-wrap: wrap;
  justify-content: center;
}
.star-btn {
  font-size: 22px;
  padding: 6px 8px;
  border-radius: 6px;
  border: 1px solid #ccc;
  cursor: pointer;
  background: #f7f7f7;
}
.star-btn.active {
  background: #ffd742;
  border-color: #e0a800;
}
textarea.rating-comments {
  width: 100%;
  min-height: 80px;
  border-radius: 6px;
  border: 1px solid #ccc;
  padding: 8px;
  resize: vertical;
}
.modal-actions {
  margin-top: 12px;
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}
.btn {
  padding: 8px 12px;
  border-radius: 6px;
  border: none;
  cursor: pointer;
}
.btn-primary { background: #007bff; color: white; }
.btn-secondary { background: #6c757d; color: white; }

/* Topbar (mobile) */
.topbar {
  display: none;
  align-items: center;
  gap: 6px;
  background: #2c2c2c;
  color: white;
  padding: 6px 8px;
  position: fixed;
  top: 0; left: 0; right: 0;
  z-index: 1000;
  min-height: 40px;
  border-bottom-left-radius: 10px;
  border-bottom-right-radius: 10px;
}
.topbar h2 {
  margin-top: 35px;
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
  width: 26%;
}
.topbar button:hover,
.topbar button.active { background: #666; }
.topbar .logout-btn svg {
  fill: white;
  width: 18px;
  height: 18px;
}

/* Manager Card specific styles */
.manager-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    margin-bottom: 15px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    width: 78%;       /* ensures it grows with content */
}

.manager-card summary {
    padding: 15px;
    background: #f0f0f0;
    border-bottom: 1px solid #eee;
    font-size: 1.1em;
    font-weight: bold;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    list-style: none; /* Remove default arrow */

}

.manager-card summary::-webkit-details-marker {
    display: none;
}

.manager-card summary::after {
    margin-left: 10px;
    transition: transform 0.2s;
}

.manager-card[open] summary::after {
    transform: rotate(180deg);
}

.manager-card .card-body {
    padding: 15px;
}

.manager-card .card-body ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.manager-card .card-body ul li {
    padding: 5px 0;
    border-bottom: 1px dotted #eee;
}

.manager-card .card-body ul li:last-child {
    border-bottom: none;
}

.manager-card .ticket-list-container {
    max-height: 200px; /* Limit height for scrollable list */
    overflow-y: auto;
    border: 1px solid #eee;
    padding: 10px;
    margin-top: 10px;
    background: #fdfdfd;
}

.manager-card .status-filter-buttons {
    display: flex;
    gap: 5px;
    margin-top: 10px;
    flex-wrap: wrap;
}

.manager-card .status-filter-buttons button {
    padding: 5px 10px;
    border: 1px solid #ccc;
    border-radius: 5px;
    background: #f9f9f9;
    cursor: pointer;
    font-size: 0.9em;
}

.manager-card .status-filter-buttons button.active {
    background: #007BFF;
    color: white;
    border-color: #007BFF;
}

/* Mobile overrides */
@media (max-width: 800px) {
  body { padding-top: 40px; flex-direction: column; }
  .sidebar { display: none; }
  .topbar { display: flex; }
  .topbar h2 { display: none; }
  .content { padding: 10px; }
  table.ticket-table { display: none; }
  .cards-container { display: block !important; margin-top: 20px; }
  .ticket-card { margin-bottom: 16px; }
  .card-header { padding: 12px; font-size: 14px; }
  .card-body { padding: 12px; }
  .card-field { margin-bottom: 8px; display: block; }
  .card-field label { display: block; font-weight: bold; margin-bottom: 4px; color: #333; }
  .assign-btn, .assign-select { width: 100%; margin-bottom: 4px; }
  .cards-grid { grid-template-columns: repeat(2,1fr); gap: 10px; }
  .card { height: 180px; padding: 10px; }
  .bar { width: 80%; }
  .form-row { flex-direction: column; align-items: stretch; }
  .staff-info { grid-template-columns: 1fr; }
  .assigned-ticket { font-size: 12px; }
  .rating-summary {margin: 10px auto 1px auto;}
  .total-card {margin: 0px auto;}

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

/* Desktop-only */
@media (min-width: 800px) {
  .cards-container { display: none; }
}

/* Animation */
@keyframes growBar {
  from { height: 0; }
  to { height: var(--bar-height, 100px); }
}

.supp {
    max-width: 1200px;
    width: 200%;       /* optional but common */
    display: flex;
    margin: 0 auto;    /* centers horizontally */
    height: auto;
}


/* Optional: responsive for smaller screens */
@media (max-width: 800px) {
    .summ, .ticks, .supp {
        padding-top: 70px;   /* slightly more top space on mobile */
        margin: 0 10px;
    }
} 

@media (max-width: 768px) {
    .sidebar {
        display: none;
    }

}


/* ================= PROFILE DASHBOARD ================= */

.profile-wrapper {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    margin-top: 40px;
}

/* BIG DASHBOARD CARD */
.profile-box {
    background: #2f2f2f;
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
  top: 46%;
  left: 61%;

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

#profile-section {
    display: none;
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
    left: 50%;
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

.export-btn {
    display: flex;
    width: auto;          /* prevent full width */
    align-self: flex-start;
    gap: 8px;

    background: #065f46;
    color: #fff;
    font-weight: 600;
    font-size: 15px;

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

function showPage(page) {
    const sections = ["summary", "tickets", "assign","history", "profile-section"];

    sections.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = (id === page) ? "block" : "none";
    });

    // remove active from menu buttons
    document.querySelectorAll(".menu button, .sidebar-bottom button")
        .forEach(btn => btn.classList.remove("active"));

    // set active button
    const btn = document.getElementById(page + "-btn");
    if (btn) btn.classList.add("active");

    // animations / loaders
    if (page === 'summary') {
        document.querySelectorAll('#summary-container .bar').forEach(bar => {
            const h = bar.dataset.height;
            bar.style.height = '0px';
            requestAnimationFrame(() => bar.style.height = h);
        });
    } 
    else if (page === 'tickets') {
        loadTickets(currentPage, currentStatus);
        loadInProgressTickets(currentPageProg);
        loadEscalatedTickets(escalatedCurrentPage);
    } 
        else if (page === 'history') {
        loadHistoryTickets(historyPage, historyStatus);
    } 
    else if (page === 'assign') {
        document.querySelectorAll('.manager-card').forEach(card => {
            loadManagerTicketsForCard(card.dataset.managerId, 'all');
        });
    }
}



    let currentPage = 1;
    let currentStatus = 'all';

function loadTickets(page = 1) {
    currentPage = page;

    const tbody = document.getElementById('tickets-body');
    const cardsContainer = document.getElementById('tickets-cards');

    if (tbody) tbody.innerHTML = '<tr><td colspan="7">Loading...</td></tr>';
    if (cardsContainer) cardsContainer.innerHTML = '';

    const url = `?action=load_tickets&page=${page}`; // removed status

    fetch(url)
        .then(res => res.text())
        .then(html => {
            const parts = html.split('<!--PAGINATION-->');
            if (tbody) tbody.innerHTML = parts[0] || '<tr><td colspan="7">No data received.</td></tr>';

            if (parts.length > 1) {
                document.getElementById('pagination').innerHTML = parts[1];
            }

            if (isMobile()) {
                convertTableToCards('tickets-body', 'tickets-cards');
            }
        })
        .catch(() => {
            if (tbody) tbody.innerHTML = '<tr><td colspan="7">Error loading tickets.</td></tr>';
        });
}
    
let historyPage = 1;
let historyStatus = 'all';

function loadHistoryTickets(page = 1, status = 'all') {

    historyPage = page;
    historyStatus = status;

    const tbody = document.getElementById('history-body');
    const cardsContainer = document.getElementById('history-cards');
    const paginationContainer = document.getElementById('pagination');

    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="7">Loading...</td></tr>';
    }

    if (cardsContainer) {
        cardsContainer.innerHTML = '';
    }

    const url = `?action=load_history&page=${page}&status=${encodeURIComponent(status)}`;

    fetch(url)
        .then(res => {
            if (!res.ok) throw new Error('Network response not ok');
            return res.text();
        })
        .then(html => {

            const parts = html.split('<!--PAGINATION-->');

            if (tbody) {
                tbody.innerHTML = parts[0] || '<tr><td colspan="7">No data received.</td></tr>';
            }

            if (paginationContainer && parts.length > 1) {
                paginationContainer.innerHTML = parts[1];
            }

            if (isMobile && typeof isMobile === 'function' && isMobile()) {
                convertTableToCards('history-body', 'history-cards');
            }

        })
        
}

let currentPageProg = 1;

function loadInProgressTickets(page = 1) {
    currentPageProg = page;

    const tbody = document.getElementById('inprogress-body');

    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="7">Loading...</td></tr>';
    }

    const url = `?action=load_in_progress&page=${page}`;

    fetch(url)
        .then(res => res.text())
        .then(html => {

            const parts = html.split('<!--PAGINATION-->');

            if (tbody) {
                tbody.innerHTML = parts[0] || '<tr><td colspan="7">No data received.</td></tr>';
            }

            if (parts.length > 1) {
                const paginationContainer = document.getElementById('pagination-prog');
                if (paginationContainer) {
                    paginationContainer.innerHTML = parts[1];
                }
            }

            // Mobile card conversion (if you use this function)
            if (isMobile()) {
                convertTableToCards('inprogress-body', 'inprogress-cards');
            }

        })
        .catch(() => {
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="7">Error loading tickets.</td></tr>';
            }
        });
}

let escalatedCurrentPage = 1;

function loadEscalatedTickets(page = 1) {
    escalatedCurrentPage = page;

    const tbody = document.getElementById('escalated-body');

    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="8">Loading...</td></tr>';
    }

    fetch('load_escalated_tickets.php?page=' + page)
        .then(res => res.text())
        .then(html => {
            tbody.innerHTML = html;

            if (isMobile()) {
                convertTableToCards('escalated-body','escalated-cards',true);
            }
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="8">Failed to load tickets.</td></tr>';
        });
}
    function changeFilter(status) {
        currentStatus = status;
        loadTickets(1, status);
        document.getElementById('status-filter').value = status;
    }

    function assignManagerTicket(ticketId) {
        const select = document.getElementById('manager_' + ticketId);
        if (!select) return;
        const staffId = select.value;
        if (!staffId) {
            alert('Please select a staff member.');
            return;
        }

        if (!confirm('Assign this ticket to the selected support staff?')) {
            return;
        }

        const form = new FormData();
        form.append('ajax_action', 'assign_manager');
        form.append('ticket_id', ticketId);
        form.append('manager_id', staffId);

        fetch(location.href, { method: 'POST', body: form })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    // FIX: Reload Tickets section instead of redirecting to Assign
                    showPage('tickets'); 
                } else {
                    alert(data.message || 'Failed to assign ticket.');
                }
            })
            .catch(err => {
                console.error('Assign error:', err);
                alert('Error assigning ticket.');
            });
    }

    // Function for assigning from manager card
    function assignToManagerFromCard(selectId, managerId) {
        const select = document.getElementById(selectId);
        if (!select) return;
        const ticketId = select.value;
        if (!ticketId) {
            alert('Please select a ticket.');
            return;
        }

        if (!confirm('Assign this ticket to the selected support staff?')) {
            return;
        }

        const form = new FormData();
        form.append('ajax_action', 'assign_manager');
        form.append('ticket_id', ticketId);
        form.append('manager_id', managerId);

        fetch(location.href, { method: 'POST', body: form })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    // FIX: Reload Tickets section instead of full page reload
                    showPage('tickets'); 
                    // Also reload the assign section to update pending tickets list and manager's assigned tickets
                    showPage('assign');
                } else {
                    alert(data.message || 'Failed to assign ticket to support staff.');
                }
            })
            .catch(err => {
                console.error('Assign staff error:', err);
                alert('Error assigning ticket to support staff.');
            });
    }
    
    

    function isMobile() {
        return window.innerWidth <= 768;
    }

    // Handle pagination clicks
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('page-btn')) {
            const page = parseInt(e.target.dataset.page);
            if (!isNaN(page)) {
                loadTickets(page, currentStatus);
            }
        }
    });

    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('page-btn')) {
            const page = parseInt(e.target.dataset.page);
            if (!isNaN(page)) {
                loadHistoryTickets(page, historyStatus);
            }
        }
    });
    // Handle window resize
    window.addEventListener('resize', function() {
        if (isMobile() && document.getElementById('tickets') && document.getElementById('tickets').style.display === 'block') {
            convertTableToCards('tickets-body', 'tickets-cards');
        }
    });
    
        window.addEventListener('resize', function() {
        if (isMobile() && document.getElementById('tickets') && document.getElementById('history').style.display === 'block') {
            convertTableToCards('history-body', 'history-cards');
        }
    });

    // Initialize
    window.onload = () => {
        showPage('summary');
        // Set initial filter
        const filterSelect = document.getElementById('status-filter');
        if (filterSelect) filterSelect.value = 'all';
    };

    // Convert table rows to cards for mobile view
    function convertTableToCards(tbodyId, containerId) {
        const tbody = document.getElementById(tbodyId);
        const container = document.getElementById(containerId);
        if (!tbody || !container) return;

        container.innerHTML = '';
        const rows = tbody.querySelectorAll('tr');
        rows.forEach(row => {
            if (row.children.length < 7) return; // skip invalid rows
            const controlNumber = row.children[0].textContent.trim();
            const subject = row.children[1].innerHTML.trim();
            const issue = row.children[2].innerHTML.trim();
            const status = row.children[3].innerHTML.trim();
            const submittedBy = row.children[4].textContent.trim();
            const createdAt = row.children[5].textContent.trim(); // Changed to textContent
            const assign = row.children[6].innerHTML.trim();

            const card = document.createElement('div');
            card.className = 'ticket-card';
            card.style.background = 'white';
            card.style.borderRadius = '12px';
            card.style.padding = '12px';
            card.style.marginBottom = '16px';
            card.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';

            card.innerHTML = `
                <div class="card-field"><strong>Control Number:</strong> ${controlNumber}</div>
                <div class="card-field"><strong>Subject:</strong> ${subject}</div>
                <div class="card-field"><strong>Issue:</strong> ${issue}</div>
                <div class="card-field"><strong>Status:</strong> ${status}</div>
                <div class="card-field"><strong>Submitted By:</strong> ${submittedBy}</div>
                <div class="card-field"><strong>Created At:</strong> ${createdAt}</div>
                <div class="card-field"><strong>Assign Support:</strong> ${assign}</div>
            `;
            container.appendChild(card);
        });
    }

    // Function to load tickets for a specific manager card
// Function to load tickets for a specific manager card
function loadManagerTicketsForCard(managerId, status) {

    // Allowed display statuses + "all"
    const allowedStatuses = ['in_progress', 'closed', 'resolved', 'all'];

    // Default fallback
    if (!allowedStatuses.includes(status)) {
        status = 'all';
    }

    const ticketListContainer = document.getElementById(`manager-tickets-${managerId}`);
    if (!ticketListContainer) return;

    ticketListContainer.innerHTML = '<p>Loading tickets...</p>';

    // Update active filter buttons
    document.querySelectorAll(`#manager-card-${managerId} .status-filter-buttons button`)
        .forEach(btn => {
            if (btn.dataset.status === status) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

    fetch(`?action=load_manager_tickets&manager_id=${encodeURIComponent(managerId)}&status=${encodeURIComponent(status)}`)
        .then(res => res.text())
        .then(html => {
            ticketListContainer.innerHTML = html;
        })
        .catch(err => {
            ticketListContainer.innerHTML = '<p>Error loading tickets for this support staff.</p>';
            console.error('Error loading tickets:', err);
        });
}

function confirmAccept(event,form){
    if(event.submitter && event.submitter.value==="accept"){ return confirm("Are you sure you want to accept this ticket?"); }
    return true;
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

</head>
<body>
    <?php if (!empty($success)): ?>
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
<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div>
        <div class="profile">
             <img 
        src="pics/<?= htmlspecialchars($_SESSION['profile_picture'] ?? 'default.png') ?>" 
        class="avatar"
      >
            <h2><?php echo htmlspecialchars($_SESSION['name']); ?></h2>
            <p>Manager Head</p>
        </div>
        <div class="menu">
           <button id="summary-btn" onclick="showPage('summary', this);" class="active">Summary</button>
           <button id="tickets-btn" onclick="showPage('tickets', this);">Ticket Updates</button>
           <button id="history-btn" onclick="showPage('history', this);">Ticket History</button>
           <button id="assign-btn" onclick="showPage('assign', this);">Staff Supports</button>
        </div>
    </div>
<div class="sidebar-bottom">
  <button id="profile-section-btn"
          class="profile-btn"
          onclick="showPage('profile-section'); closeSidebar();">
    Profile
  </button>
</div>

    <button class="logout-btn" onclick="if(confirm('Are you sure you want to logout?')) window.location.href='logout.php';" aria-label="Logout">
        Logout
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
        <div class="summ" id="summary" style="display:block;">
            <div class="rating-summary">
                <h3>⭐ Department Performance</h3>
                <?php if ($total_managers_with_ratings > 0): ?>
                    <p class="rating-chip"><?= number_format($total_department_avg_rating, 1) ?>/5 <span class="small-note">(Average of <?= $total_managers_with_ratings ?> managers)</span></p>
                <?php else: ?>
                    <p>No Support Staff ratings yet in this department.</p>
                <?php endif; ?>
            </div>
            <div id="summary-container">
                <div class="total-card">Total Tickets: <?= intval($summary_data['Total']) ?></div>
                <div class="cards-grid">
                    <?php foreach ($bar_statuses as $status): 
                        $value = intval($summary_data[$status] ?? 0);
                        $bar_height = $value * $scale_factor;
                        $class_key = strtolower(str_replace(' ', '-', $status));
                    ?>
                    <div class="card">
                        <h3><?= $status ?></h3>
                        <div class="bar bar-<?= $class_key ?>" data-height="<?= $bar_height ?>px" style="height: 0px;">
                            <span class="bar-label"><?= $value ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                </div>
            </div>
        </div>

 <!-- Tickets Page -->
<div class="ticks" id="tickets" style="display:none;">

    <h2>Pending Tickets</h2>

    <!-- Pending Tickets Table -->
    <table class="ticket-table">
        <thead>
            <tr>
                <th>Control Number</th>
                <th>Subject</th>
                <th>Issue</th>
                <th>Status</th>
                <th>Submitted By</th>
                <th>Created At</th>
                <th>Assign Support</th>
            </tr>
        </thead>
        <tbody id="tickets-body">
            <tr>
                <td colspan="7">Loading tickets...</td>
            </tr>
        </tbody>
    </table>
<div id="tickets-cards" class="cards-container"></div>
    <div id="pagination"></div><br>

    <hr>
<br>
    <h2>In Progress Tickets</h2>

    <!-- In Progress Tickets Table -->
    <table class="ticket-table">
        <thead>
            <tr>
                <th>Queue Number</th>
                <th>Control Number</th>
                <th>Subject</th>
                <th>Issue</th>
                <th>Status</th>
                <th>Submitted By</th>
                <th>Updated At</th>
                <th>Support Assigned</th>
            </tr>
        </thead>
        <tbody id="inprogress-body">
            <tr>
                <td colspan="7">Loading tickets...</td>
            </tr>
        </tbody>
    </table>
    <div id="inprogress-cards" class="cards-container"></div>
    <div id="pagination"></div> <br><br>
    
        <hr>
    <h2>Escalated Tickets</h2>

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
        <tbody id="escalated-body">
            <tr>
                <td colspan="7">Loading tickets...</td>
            </tr>
        </tbody>
    </table>
    <div id="escalated-cards" class="cards-container"></div>
    <div id="pagination"></div>
</div>
</div>

        
            <div class="hist" id="history" style="display:none;">
            <h2> Tickets History</h2>
                <form action="export_tickets_mhead.php" method="POST" style="margin-bottom:15px;">
    <button type="submit" class="export-btn">
        📥 Download Tickets
    </button>
            <table class="ticket-table">
                <thead>
                    <tr>
                        <th>Control Number</th>
                        <th>Subject</th>
                        <th>Issue</th>
                        <th>Status</th>
                        <th>Submitted By</th>
                        <th>Created At</th>
                        <th>Assign Support</th>
                    </tr>
                </thead>
                <tbody id="history-body">
                    <tr><td colspan="8">Loading tickets...</td></tr>
                </tbody>
            </table>
            <div id="history-cards" class="cards-container"></div>
            <div id="pagination"></div>
        </div>

        <!-- Staff Support Page -->
<div class="supp" id="assign" style="display:none;">
    <h2><?= htmlspecialchars($dept_name); ?> Staff Supports</h2>

    <div class="assign-section">
        <?php if (empty($available_managers)): ?>
            <p>No available support staff found in this department.</p>
        <?php else: ?>

        <?php foreach ($available_managers as $manager): ?>

            <?php
            $mid = intval($manager['user_id']);
            $assigned = $assigned_tickets_per_manager[$mid] ?? [];

            // Initialize counters
            $counts = [
                'all' => 0,
                'in_progress' => 0,
                'resolved' => 0,
                'closed' => 0,
                'pending' => 0,
                'declined' => 0,
                'cancelled' => 0,
            ];

            // Count tickets but exclude pending
            foreach ($assigned as $t) {
                $status = strtolower(trim($t['status']));

                if ($status === 'pending') {
                    continue;
                }
                if ($status === 'cancelled') {
                    continue;
                }

                $counts['all']++;

                if (isset($counts[$status])) {
                    $counts[$status]++;
                }
            }


                        // Fetch manager's average rating
                        $manager_avg_rating = 0;
                        $manager_total_feedbacks = 0;
                        $mgr_rating_stmt = $conn->prepare("
                            SELECT AVG(tf.rating) AS avg_rating, COUNT(tf.feedback_id) AS total_feedbacks
                            FROM ticket_feedback tf
                            JOIN tickets t ON tf.ticket_id = t.ticket_id
                            WHERE t.manager_id = ?
                        ");
                        if ($mgr_rating_stmt !== false) {
                            $mgr_rating_stmt->bind_param('i', $mid);
                            $mgr_rating_stmt->execute();
                            $mgr_rating_result = $mgr_rating_stmt->get_result()->fetch_assoc();
                            $manager_avg_rating = $mgr_rating_result['avg_rating'] ?? 0;
                            $manager_total_feedbacks = (int)($mgr_rating_result['total_feedbacks'] ?? 0);
                            $mgr_rating_stmt->close();
                        }
                        ?>
                        <details class="manager-card" data-manager-id="<?= $mid; ?>" id="manager-card-<?= $mid; ?>" open>
                            <summary>
                                <span style="flex-grow: 1;"><?= htmlspecialchars($manager['name']); ?></span>
                                <small style="margin-left: 10px;"><?= htmlspecialchars($manager['position']); ?></small>
                            </summary>
                            <div class="card-body">
                                <div style="margin-bottom: 12px;">
                                    
                                    
                                </div>
                                <div style="margin-bottom: 12px;">
                                    <strong>Support Rating:</strong>
                                    <?php if ($manager_total_feedbacks > 0): ?>
                                        <span class="rating-badge">
                                            <?= number_format($manager_avg_rating, 1); ?>
                                            <span class="rating-stars">
                                                <?php
                                                $fullStars = floor($manager_avg_rating);
                                                $halfStar = ($manager_avg_rating - $fullStars) >= 0.5;
                                                for ($i = 0; $i < $fullStars; $i++) echo '★';
                                                if ($halfStar) echo '☆';
                                                for ($i = $fullStars + $halfStar; $i < 5; $i++) echo '☆';
                                                ?>
                                            </span>
                                            <span class="small-note">(<?= $manager_total_feedbacks ?> feedbacks)</span>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999; font-style: italic;">No rating available</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <strong>Ticket Summary:</strong>
                                    <div class="status-filter-buttons">
                                        <button type="button" data-status="all"
    onclick="loadManagerTicketsForCard(<?= $mid; ?>, 'all')" 
    class="active">
    All (<?= $counts['all']; ?>)
</button>

<button type="button" data-status="closed"
    onclick="loadManagerTicketsForCard(<?= $mid; ?>, 'closed')">
    Closed (<?= $counts['closed']; ?>)
</button>

<button type="button" data-status="in_progress"
    onclick="loadManagerTicketsForCard(<?= $mid; ?>, 'in_progress')">
    In Progress (<?= $counts['in_progress']; ?>)
</button>

<button type="button" data-status="resolved"
    onclick="loadManagerTicketsForCard(<?= $mid; ?>, 'resolved')">
    Resolved (<?= $counts['resolved']; ?>)
</button>
                                    </div>
                                    <div id="manager-tickets-<?= $mid; ?>" class="ticket-list-container">
                                        <!-- Tickets will be loaded here via AJAX -->
                                    </div>
                                </div>
                            </div>
                        </details>                    
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="issueModal" class="mod" style="
    display: none;
    position: fixed !important;
    top: 0; /* Explicitly set to ensure full coverage from top */
    left: 0; /* Explicitly set to ensure full coverage from left */
    width: 100vw;
    height: 100vh;
    background: rgba(0,0,0,0.5); /* Semi-transparent black overlay covering entire screen */
    z-index: 999999 !important;
    align-items: center;
    justify-content: center;
">
    <!-- Rest of your modal content here -->
    <div id="modalBox" style="
        background: white; 
        padding: 20px; 
        border-radius: 12px; 
        width: 100%;
        max-width: 480px;
        max-height: 80%;
        overflow-y: auto;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        animation: fadeIn 0.2s ease-in-out;
    ">
        <h3 style="margin-top:0;">Issue Details</h3>
        <p id="issueContent" style="white-space:pre-wrap; line-height:1.5;"></p>
        <button id="closeIssue" style="
            margin-top: 15px;
            width: 100%;
            padding: 10px 15px;
            background: #333; 
            color: white;
            border: none;
            border-radius: 6px; 
            cursor: pointer;
            font-size: 15px;
        ">Close</button>
    </div>
</div>
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        body { font-family: Arial, sans-serif; padding: 20px; }
    </style>
</body>
</html>
<?php
$conn->close();
?>