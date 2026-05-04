<?php
session_start();

// 🔒 AUTH CHECK (manager_head only)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager_head') {
    exit('Access denied');
}

// 🧩 DB CONNECTION
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$conn->query("SET time_zone = '+08:00'");

$manager_id = intval($_SESSION['user_id']);

$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

/*
========================================
FETCH ESCALATED TICKETS (escalated = 1)
========================================
*/

$query = "
SELECT 
    t.ticket_id,
    t.control_number,
    d.department_name,
    us.name AS sender_name,
    t.title,
    t.issue,
    t.status,
    t.escalated,
    t.escalate_reason,
    t.created_at,
    t.ended_at,
    t.attachment

FROM tickets t
JOIN departments d ON t.department_id = d.department_id
JOIN users us ON t.user_id = us.user_id

WHERE t.manager_id = ?
AND t.escalated = 1
AND t.status = 'pending'

ORDER BY t.created_at ASC
LIMIT ? OFFSET ?
";


$stmt = $conn->prepare($query);
$stmt->bind_param("iii", $manager_id, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

/*
========================================
RENDER TABLE ROWS
========================================
*/

if ($result->num_rows > 0) {

    while ($row = $result->fetch_assoc()) {

        $status = ($row['escalated'] == 1) ? "pending" : $row['status'];
        $statusClass = "status-" . str_replace(" ", "_", strtolower($status));

        $display_id = !empty($row['control_number'])
            ? htmlspecialchars($row['control_number'])
            : intval($row['ticket_id']);

        echo "<tr>";

        echo "<td>{$display_id}</td>";
        echo "<td>" . htmlspecialchars($row['department_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['sender_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['title']) . "</td>";
        echo "<td>" . htmlspecialchars($row['issue']) . "</td>";

        echo "<td>
                <span class='status-pill {$statusClass}'>
                    " . ucfirst($status) . "
                </span>
              </td>";

        /*
        ---------------------------
        Documents
        ---------------------------
        */
        echo "<td>";

        if (!empty($row['attachment'])) {

            $files = explode(',', $row['attachment']);

            foreach ($files as $file) {

                $file = trim($file);
                $file = str_replace(["\r","\n",'../','./','\\'], '', $file);
                $file = basename($file);

                $safeFile = htmlspecialchars($file);

                $filePath = __DIR__ . "/uploads/{$file}";

                if (file_exists($filePath)) {
                    echo "<a href='/uploads/{$safeFile}' target='_blank' class='doc-link'>📎 View File</a><br>";
                } else {
                    echo "<span class='muted'>File not found</span><br>";
                }
            }

        } else {
            echo "<span class='muted'>No file</span>";
        }

        echo "</td>";

        /*
===========================
ACTION COLUMN (ACCEPT BUTTON)
===========================
*/

echo "<td>";

if ($status === 'pending') {

echo "<form method='POST' style='display:inline;' onsubmit='return confirmAccept(event,this);'>
        <input type='hidden' name='ticket_id' value='" . intval($row['ticket_id']) . "'>
        <button type='submit' name='ticket_action' value='accept' 
                class='action-btn accept-btn'>
            Accept
        </button>
      </form>";

} else {
    echo "-";
}

echo "</td>";

        echo "<td>
                " . htmlspecialchars($row['escalate_reason'], ENT_QUOTES, 'UTF-8') . "
</td>";

        echo "</tr>";
    }

} else {
    echo "<tr><td colspan='8'>No escalated tickets.</td></tr>";
}

/*
========================================
PAGINATION
========================================
*/

$total_stmt = $conn->prepare("
    SELECT COUNT(*) as cnt
    FROM tickets
    WHERE manager_id = ?
    AND escalated = 1
");

$total_stmt->bind_param("i", $manager_id);
$total_stmt->execute();

$total_count = $total_stmt->get_result()->fetch_assoc()['cnt'];
$total_stmt->close();

$total_pages = ceil($total_count / $limit);

if ($total_pages > 1) {

    echo "<tr><td colspan='8' style='text-align:center;padding:10px;background:#f9f9f9;'>";

    if ($page > 1)
        echo "<button class='page-btn escalated' data-page='".($page-1)."'>Previous</button> ";

    for ($p = 1; $p <= $total_pages; $p++) {

        $active = ($p == $page)
            ? "font-weight:bold;background:#ddd;"
            : "";

        echo "<button class='page-btn escalated'
                data-page='$p'
                style='margin:2px;padding:5px 10px;border:1px solid #ccc;background:white;cursor:pointer;$active'>
                $p
              </button> ";
    }

    if ($page < $total_pages)
        echo "<button class='page-btn escalated' data-page='".($page+1)."'>Next</button>";

    echo "</td></tr>";
}

$stmt->close();
$conn->close();
?>