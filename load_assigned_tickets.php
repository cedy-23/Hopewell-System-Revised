<?php
session_start();

// 🔒 AUTH CHECK
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['support_staff', 'manager_head'])) {
    exit('Access denied');
}

// 🧩 DB CONNECTION
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->query("SET time_zone = '+08:00'");

$manager_id = (int) $_SESSION['user_id'];
$limit = 10;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$stmtDept = $conn->prepare("
    SELECT department_id 
    FROM users 
    WHERE user_id = ?
");
$stmtDept->bind_param("i", $manager_id);
$stmtDept->execute();
$department_id = $stmtDept->get_result()->fetch_assoc()['department_id'];
$stmtDept->close();


// ==============================
// 🔥 ACTIVE TICKETS COUNT (LIMIT SOURCE)
// ==============================
$stmtActive = $conn->prepare("
    SELECT COUNT(*) AS active_count
    FROM tickets
    WHERE assigned_staff_id = ?
    AND status = 'in_progress'
");
$stmtActive->bind_param("i", $assigned_staff_id);
$stmtActive->execute();
$activeResult = $stmtActive->get_result()->fetch_assoc();
$current_in_progress = (int)$activeResult['active_count'];
$stmtActive->close();


// ==============================
// 🔥 GET WITHDRAWN TICKETS
// ==============================
$withdraw_stmt = $conn->prepare("
    SELECT ticket_id 
    FROM ticket_withdrawals 
    WHERE user_id = ?
");
$withdraw_stmt->bind_param("i", $manager_id);
$withdraw_stmt->execute();
$withdraw_result = $withdraw_stmt->get_result();

$withdrawnTickets = [];
while ($w = $withdraw_result->fetch_assoc()) {
    $withdrawnTickets[] = (int)$w['ticket_id'];
}
$withdraw_stmt->close();


// ==============================
// 🎯 PASS TO JS
// ==============================
echo "<script>
const ACTIVE_ACCEPTED_COUNT = $current_in_progress;
const MAX_ALLOWED = 3;
</script>";


// ------------------------------
// 🎫 FETCH ASSIGNED TICKETS
// ------------------------------
$query = "
    SELECT 
        t.ticket_id, 
        t.control_number, 
        d.department_name, 
        us.name AS sender_name, 
        t.title, 
        t.issue, 
        t.status,
        t.attachment,
        t.original_assigned_staff_id,

        CASE 
            WHEN t.status = 'pending'
                 AND t.original_assigned_staff_id IS NOT NULL
            THEN 'reopened'
            ELSE t.status
        END AS computed_status

    FROM tickets t
    JOIN departments d ON t.department_id = d.department_id
    JOIN users us ON t.user_id = us.user_id

    WHERE t.department_id = ?
    AND t.status = 'pending'
        AND escalated = 0

    ORDER BY t.control_number ASC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param('iii', $department_id, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();


// ------------------------------
// 🧾 RENDER TABLE
// ------------------------------
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {

        $ticketId = (int)$row['ticket_id'];
        $status = $row['computed_status'];
        $statusClass = "status-" . strtolower($status);

        $display_id = !empty($row['control_number'])
            ? htmlspecialchars($row['control_number'])
            : $ticketId;

        // 🔥 CHECK IF WITHDRAWN
        $isWithdrawnByUser = in_array($ticketId, $withdrawnTickets);
        echo "<tr id='ticket-meta'
        data-active='$current_in_progress'
        data-max='3'
        style='display:none'></tr>";

        echo "<tr 
                data-ticket-id='{$ticketId}'
                data-withdrawn='" . ($isWithdrawnByUser ? 1 : 0) . "'
              >";

        echo "<td>{$display_id}</td>";
        echo "<td>" . htmlspecialchars($row['department_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['sender_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['title']) . "</td>";
        echo "<td>" . htmlspecialchars($row['issue']) . "</td>";

        echo "<td>
                <span class='status-pill {$statusClass}'>" . ucfirst($status) . "</span>
              </td>";

        // 📎 ATTACHMENT
        echo "<td>";

        if (!empty($row['attachment'])) {
            $files = explode(',', $row['attachment']);

            foreach ($files as $file) {
                $file = basename(trim($file));
                $filePath = __DIR__ . "/uploads/{$file}";

                if (file_exists($filePath)) {
                    echo "<a href='/uploads/" . htmlspecialchars($file) . "' target='_blank'>📎 View</a><br>";
                } else {
                    echo "<span class='muted'>Not found</span><br>";
                }
            }
        } else {
            echo "<span class='muted'>No file</span>";
        }

        echo "</td>";

        // 🎯 ACTION BUTTON
        echo "<td>";

        if ($status === 'pending') {

            echo "<form method='POST' style='display:inline;' onsubmit='return confirmAccept(event,this);'>
                    <input type='hidden' name='ticket_id' value='{$ticketId}'>

                    <button type='submit'
                        name='ticket_action'
                        value='accept'
                        class='action-btn accept-btn'>
                        Accept
                    </button>
                  </form>";

        } elseif ($status === 'reopened') {

            echo "<form method='POST' style='display:inline;'>

                    <input type='hidden' name='ticket_id' value='{$ticketId}'>

                    <button type='submit' name='ticket_action' value='reassign_same_staff' class='action-btn'>
                        Reassign
                    </button>

                    <button type='submit' name='ticket_action' value='reopen_direct' class='action-btn'>
                        Reopen
                    </button>

                  </form>";

        } else {
            echo "-";
        }

        echo "</td>";
        echo "</tr>";
    }
} else {
    echo "<tr><td colspan='10'>No assigned tickets.</td></tr>";
}


// ------------------------------
// 📄 PAGINATION
// ------------------------------
$total_stmt = $conn->prepare("
    SELECT COUNT(*) as cnt 
    FROM tickets 
    WHERE manager_id = ?
    AND status = 'pending'
");
$total_stmt->bind_param('i', $manager_id);
$total_stmt->execute();
$total_count = $total_stmt->get_result()->fetch_assoc()['cnt'];
$total_stmt->close();

$total_pages = ceil($total_count / $limit);

if ($total_pages > 1) {
    echo "<tr><td colspan='8' style='text-align:center;'>";

    for ($p = 1; $p <= $total_pages; $p++) {
        echo "<button class='page-btn assigned' data-page='$p'>$p</button> ";
    }

    echo "</td></tr>";
}

$stmt->close();
$conn->close();
?>