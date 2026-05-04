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

$assigned_staff_id = intval($_SESSION['user_id']); // ✅ FIXED

$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;


// ⏱️ FORMAT DURATION
function formatDuration($start, $end) {
    if (!$end) return '-';
    $diff = strtotime($end) - strtotime($start);
    if ($diff < 0) return '-';

    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);

    return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
}


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
        t.created_at, 
        t.ended_at,
        t.decline_reason,
        t.accept_attempts,
        t.accepted_at,
        t.original_assigned_staff_id,
        t.assigned_staff_id,
        t.attachment,

        CASE 
            WHEN t.status = 'in_progress'
                 AND t.original_assigned_staff_id IS NOT NULL
            THEN 'reopened'
            ELSE t.status
        END AS computed_status

    FROM tickets t
    JOIN departments d ON t.department_id = d.department_id
    JOIN users us ON t.user_id = us.user_id

    WHERE t.assigned_staff_id = ?
    AND t.status = 'in_progress'

    ORDER BY t.created_at ASC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param('iii', $assigned_staff_id, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();


// ------------------------------
// 🧾 RENDER
// ------------------------------
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {

        $status = $row['computed_status'];
        $statusClass = "status-" . strtolower($status);

        $display_id = !empty($row['control_number'])
            ? htmlspecialchars($row['control_number'])
            : $row['ticket_id'];

        echo "<tr>";

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
                    echo "<span class='muted'>File not found</span><br>";
                }
            }
        } else {
            echo "<span class='muted'>No file</span>";
        }

        echo "</td>";

        // 🎯 ACTIONS
        echo "<td>";

        if ($status === 'in_progress') {

 echo "<form method='POST' style='display:inline;' onsubmit='return confirmCancelWithReason(this)'>
        <input type='hidden' name='ticket_id' value='" . intval($row['ticket_id']) . "'>

        <!-- REQUIRED: hidden input -->
        <input type='hidden' name='decline_reason'>

        <button type='submit' name='ticket_action' value='cancel' class='action-btn cancel-btn'>
            Withdraw
        </button>
      </form>";

            $disableEscalate = ($row['accept_attempts'] < 3);
            $disabledAttr = $disableEscalate ? "disabled" : "";

           echo "<form method='POST' style='display:inline;' onsubmit='return confirmEscalate(this)'>
        <input type='hidden' name='ticket_id' value='" . intval($row['ticket_id']) . "'>

        <!-- REQUIRED -->
        <input type='hidden' name='escalate_reason'>

        <button type='submit'
            name='ticket_action'
            value='escalate'
            class='action-btn escalate-btn'
            {$disabledAttr}>
            Escalate
        </button>
      </form>";
        } else {
            echo "-";
        }

        echo "</td>";

        echo "<td>" . htmlspecialchars($row['decline_reason']) . "</td>";

        echo "</tr>";
    }
} else {
    echo "<tr><td colspan='9'>No assigned tickets.</td></tr>";
}


// ------------------------------
// 📄 PAGINATION (FIXED)
// ------------------------------
$total_stmt = $conn->prepare("
    SELECT COUNT(*) as cnt 
    FROM tickets 
    WHERE assigned_staff_id = ?
    AND status = 'in_progress'
");

$total_stmt->bind_param('i', $assigned_staff_id);
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