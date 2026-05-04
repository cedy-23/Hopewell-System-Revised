<?php
session_start();


$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->query("SET time_zone = '+08:00'");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error'] ?? null;
$warning = $_SESSION['warning'] ?? null;

// Clear them so they only appear once
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['warning']);

require 'send_mail.php';
require 'send_credentials.php';


// Fetch pending count for sidebar
$pending_count = $conn->query("SELECT COUNT(*) AS total FROM users WHERE status = 'pending'")->fetch_assoc()['total'];

// Helper function to format duration like manager dashboard
function formatDuration($start, $end) {
    if (!$end) return '-';
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    if ($end_ts < $start_ts) return '-';
    $diff = $end_ts - $start_ts;
    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    if ($hours > 0) {
        return "{$hours}h {$minutes}m";
    } else {
        return "{$minutes}m";
    }
}

// -------------------- Handle ticket deletion --------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_ticket_id'])) {
    $ticket_id = intval($_POST['delete_ticket_id']);
    $stmt = $conn->prepare("DELETE FROM tickets WHERE ticket_id = ?");
    $stmt->bind_param('i', $ticket_id);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_dashboard.php");
    exit();
}

// -------------------- Handle user deletion --------------------
if (!empty($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_user_id'])) {
    $user_id = intval($_POST['delete_user_id']);

    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['success'] = "User deleted successfully.";

    header("Location: admin_dashboard.php?page=users");
    exit();
}



// -------------------- Handle user role and position update --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['edit_user_id'])
    && !isset($_POST['delete_user_id'])) {

    $user_id = intval($_POST['edit_user_id']);
    $name    = trim($_POST['new_name']);
    $email   = trim($_POST['new_email']);
    $role    = $_POST['new_role'];
    $pos     = trim($_POST['new_position']);
    $dept    = intval($_POST['new_department_id']);

    if ($role === 'user') {
        $role = 'staff';
    }

    if (in_array($role, ['admin','manager_head','support_staff','staff'])) {
        $stmt = $conn->prepare("
            UPDATE users
            SET name=?, email=?, role=?, position=?, department_id=?
            WHERE user_id=?
        ");
        $stmt->bind_param(
            "ssssii",
            $name, $email, $role, $pos, $dept, $user_id
        );
        $stmt->execute();
        $stmt->close();
    }
    
    $_SESSION['success'] = "User Updated successfully.";

    header("Location: admin_dashboard.php?page=users");
    exit();
}


// -------------------- Handle add department --------------------

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_department'])) {

    $dept_name = trim($_POST['department_name'] ?? '');

    if ($dept_name !== '') {

        // ===============================
        // 1. CHECK FOR DUPLICATE NAME
        // ===============================
        $dup_stmt = $conn->prepare("SELECT department_id FROM departments WHERE department_name = ?");
        $dup_stmt->bind_param('s', $dept_name);
        $dup_stmt->execute();
        $dup_stmt->store_result();

        if ($dup_stmt->num_rows > 0) {
            $_SESSION['error'] = "Department '$dept_name' already exists!";
            $dup_stmt->close();
            header("Location: admin_dashboard.php?page=departments");
            exit();
        }
        $dup_stmt->close();

        // ===============================
        // 2. GENERATE department_code (001, 002, ...)
        // ===============================
        $result = $conn->query("SELECT department_code FROM departments ORDER BY department_code DESC LIMIT 1");

        if ($result && $row = $result->fetch_assoc()) {
            $last_code = (int)$row['department_code'];
            $new_code = str_pad($last_code + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $new_code = '001';
        }

        // ===============================
        // 3. GENERATE dept_code (SHORT NAME)
        // ===============================
        // Example:
        // Human Resources -> HR
        // Information Technology -> IT
        // Finance -> FIN

        $words = explode(' ', $dept_name);

        if (count($words) > 1) {
            // Get first letter of each word
            $dept_code = '';
            foreach ($words as $word) {
                $dept_code .= strtoupper($word[0]);
            }
        } else {
            // Single word → first 3 letters
            $dept_code = strtoupper(substr($dept_name, 0, 3));
        }

        // Limit to 5 chars max (optional safety)
        $dept_code = substr($dept_code, 0, 5);

        // ===============================
        // 4. CHECK DUPLICATE dept_code
        // ===============================
        $dup_code_stmt = $conn->prepare("SELECT department_id FROM departments WHERE dept_code = ?");
        $dup_code_stmt->bind_param('s', $dept_code);
        $dup_code_stmt->execute();
        $dup_code_stmt->store_result();

        if ($dup_code_stmt->num_rows > 0) {
            // If duplicate, add number (HR1, HR2...)
            $counter = 1;
            $base_code = $dept_code;

            do {
                $new_dept_code = $base_code . $counter;
                $check_stmt = $conn->prepare("SELECT department_id FROM departments WHERE dept_code = ?");
                $check_stmt->bind_param('s', $new_dept_code);
                $check_stmt->execute();
                $check_stmt->store_result();

                $exists = $check_stmt->num_rows > 0;
                $check_stmt->close();

                $counter++;
            } while ($exists);

            $dept_code = $new_dept_code;
        }
        $dup_code_stmt->close();

        // ===============================
        // 5. INSERT DATA
        // ===============================
        $stmt = $conn->prepare("
            INSERT INTO departments (department_code, department_name, dept_code) 
            VALUES (?, ?, ?)
        ");

        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }

        $stmt->bind_param('sss', $new_code, $dept_name, $dept_code);

        if ($stmt->execute()) {
            $_SESSION['success'] = "Department '$dept_name' added successfully!";
        } else {
            $_SESSION['error'] = "Failed to add department: " . $stmt->error;
        }

        $stmt->close();

    } else {
        $_SESSION['error'] = "Department name cannot be empty!";
    }

    header("Location: admin_dashboard.php?page=departments");
    exit();
}

// -------------------- Handle delete department --------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_department_id'])) {
    $dept_id = intval($_POST['delete_department_id']);

    // Check if any users belong to this department
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE department_id = ?");
    $stmt->bind_param('i', $dept_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = $res->fetch_assoc()['cnt'] ?? 0;
    $stmt->close();

    if ($count > 0) {
        // Cannot delete, show error
        $_SESSION['error'] = "Cannot delete department because there are still employees assigned to it.";
    } else {
        // Safe to delete
        $stmt = $conn->prepare("DELETE FROM departments WHERE department_id = ?");
        $stmt->bind_param('i', $dept_id);

        if ($stmt->execute()) {
            $_SESSION['success'] = "Department deleted successfully!";
        } else {
            $_SESSION['error'] = "Failed to delete department: " . $stmt->error;
        }

        $stmt->close();
    }

    header("Location: admin_dashboard.php?page=departments");
    exit();
}

// -------------------- Handle search --------------------
$search = "";
if (isset($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
}

// Pagination variables for ticket history
$page = isset($_GET['page_num']) ? intval($_GET['page_num']) : 1;
$limit = 10; // Tickets per page
$offset = ($page - 1) * $limit;

// Fetch departments for dropdowns
$departments = $conn->query("SELECT * FROM departments");

// --------------------- AUTO GENERATE EMPLOYEE ID ---------------------
function generateEmployeeId(mysqli $conn): string
{
    $year = date('Y');

    $stmt = $conn->prepare("
        SELECT employee_id
        FROM users
        WHERE employee_id COLLATE utf8mb4_general_ci 
              LIKE CONCAT('EMP-', ?, '-%') COLLATE utf8mb4_general_ci
        ORDER BY employee_id DESC
        LIMIT 1
    ");

    $stmt->bind_param("s", $year);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $last = (int) substr($row['employee_id'], -5);
        $next = $last + 1;
    } else {
        $next = 1;
    }

    return sprintf("EMP-%s-%05d", $year, $next);
}


// --------------------- AUTO PASSWORD BY ROLE ---------------------
function generateRolePassword(string $role): string
{
    switch (strtolower($role)) {
        case 'staff':
            return 'Staff12345';

        case 'manager_head':
            return 'Manager12345';

        case 'support_staff':
            return 'Support12345';

        case 'admin':
            return 'Admin12345';

        default:
            return 'Default12345';
    }
}


// --------------------- EMAIL VALIDATION ---------------------
function isValidAllowedEmail(string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $allowedDomains = [
        'gmail.com',
        'yahoo.com',
        'outlook.com',
        'hotmail.com'
    ];

    $domain = strtolower(substr(strrchr($email, "@"), 1));
    return in_array($domain, $allowedDomains, true);
}


// --------------------- NAME VALIDATION ---------------------
function isValidEmployeeName($name)
{
    $name = trim($name);

    if (!preg_match('/^[A-Za-z\s]+$/', $name)) {
        return false;
    }

    $words = preg_split('/\s+/', $name);

    if (count($words) < 2) {
        return false;
    }

    foreach ($words as $word) {
        if (strlen($word) < 2) return false;
        if (!preg_match('/[aeiouAEIOU]/', $word)) return false;

        $letters = count_chars($word, 1);
        foreach ($letters as $count) {
            if ($count / strlen($word) > 0.7) return false;
        }
    }

    return true;
}


require __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;


// =============================================================
// ===================== EXCEL UPLOAD ==========================
// =============================================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['upload_excel']) &&
    isset($_FILES['excel']) &&
    $_FILES['excel']['error'] === UPLOAD_ERR_OK
) {
    $error = '';
    $added = 0;
    $skipped = 0;
    $emailSent = 0;

    $spreadsheet = IOFactory::load($_FILES['excel']['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();
    unset($rows[0]); // remove header

    foreach ($rows as $row) {

        $name          = trim($row[0] ?? '');
        $position      = trim($row[1] ?? '');
        $department_id = (int) ($row[2] ?? 0);
        $email         = trim($row[3] ?? '');
        $role          = trim($row[4] ?? 'staff');

        if ($name === '' || $department_id === 0) {
            $skipped++;
            continue;
        }

        // Validate department
        $dept = $conn->prepare("SELECT department_id FROM departments WHERE department_id=?");
        $dept->bind_param("i", $department_id);
        $dept->execute();
        if ($dept->get_result()->num_rows === 0) {
            $error = "❌ Invalid department ID: $department_id";
            break;
        }

        if (!isValidEmployeeName($name)) {
            $error = "❌ Invalid name: $name";
            break;
        }

        if ($email !== '' && !isValidAllowedEmail($email)) {
            $error = "❌ Invalid email: $email";
            break;
        }

        if ($email !== '') {
            $emailCheck = $conn->prepare("SELECT email FROM users WHERE email=?");
            $emailCheck->bind_param("s", $email);
            $emailCheck->execute();
            if ($emailCheck->get_result()->num_rows > 0) {
                $skipped++;
                continue;
            }
        }

        $employee_id  = generateEmployeeId($conn);
        $passwordRaw  = generateRolePassword($role);
        $passwordHash = password_hash($passwordRaw, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            INSERT INTO users
            (employee_id, name, position, department_id, email, password, role, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
        ");

        $stmt->bind_param(
            "sssisss",
            $employee_id,
            $name,
            $position,
            $department_id,
            $email,
            $passwordHash,
            $role
        );

if (!$stmt->execute()) {
    $error = "❌ Insert failed: {$stmt->error}";
    break;
}

$user_id = $conn->insert_id; // ✅ GET AUTO-INCREMENT ID

        if ($email !== '' && sendEmployeeCredentials($conn, $email, $user_id, $employee_id, $passwordRaw)) {
            $emailSent++;
        }

        $added++;
    }

    if ($error === '') {
        $success = "✅ Upload completed<br>Added: <b>$added</b><br>Skipped: <b>$skipped</b>";
    }
}


// =============================================================
// ===================== MANUAL ADD ============================
// =============================================================
if (isset($_POST['add_employee'])) {

    $name       = trim($_POST['name'] ?? '');
    $position   = trim($_POST['position'] ?? '');
    $department = (int) ($_POST['department_id'] ?? 0);
    $email      = trim($_POST['email'] ?? '');
    $role       = trim($_POST['role'] ?? 'staff');

    if ($name === '' || $email === '' || $department === 0) {
        $error = "❌ Please fill in all required fields.";
        goto message;
    }

    if (!isValidAllowedEmail($email)) {
        $error = "❌ Invalid email.";
        goto message;
    }

    if (!isValidEmployeeName($name)) {
        $error = "❌ Invalid name.";
        goto message;
    }

    $checkName = $conn->prepare("SELECT name FROM users WHERE name=?");
    $checkName->bind_param("s", $name);
    $checkName->execute();
    if ($checkName->get_result()->num_rows > 0) {
        $error = "❌ Name already exists.";
        goto message;
    }

    $checkEmail = $conn->prepare("SELECT email FROM users WHERE email=?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    if ($checkEmail->get_result()->num_rows > 0) {
        $error = "❌ Email already exists.";
        goto message;
    }

    $employee_id  = generateEmployeeId($conn);
    $passwordRaw  = generateRolePassword($role);
    $passwordHash = password_hash($passwordRaw, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        INSERT INTO users
        (employee_id, name, position, department_id, email, password, role, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
    ");

    $stmt->bind_param(
        "sssisss",
        $employee_id,
        $name,
        $position,
        $department,
        $email,
        $passwordHash,
        $role
    );

if ($stmt->execute()) {

    $user_id = $conn->insert_id; // ✅ GET NEW USER ID

    sendEmployeeCredentials($conn, $email, $user_id, $passwordRaw, $employee_id);

    $success = "✅ Employee added successfully!";
} else {
        $error = "❌ Database error.";
    }

    message:
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

    <title>Admin Dashboard</title>
    <style>
/* ================= Body ================= */
body {
    margin: 0;
    font-family: Arial, sans-serif;
    overflow-y: scroll;
    /* Shift right para di matakpan ng sidebar */
    margin-left: 260px; /* same width as sidebar */
}

/* ================= Sidebar ================= */
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


/* ================= Main Content ================= */
.main-content {
    padding: 20px;
    min-height: 100vh;
    overflow-y: scroll;
    
}


/* Sidebar Header */
.sidebar h2 {
    margin: 10 0 20px 0;
    text-align: center;
    font-size: 25px;
    color: #333333;
}

/* Pending Users */
.pending-count {
    text-align: center;
    font-size: 16px;
    color: #ffd700; /* gold */
    margin-bottom: 20px;
}

.menu-link {
    display: block;
    width: 90%;
    padding: 17px;
    margin: 15px 0;
    background: #065f46; /* dark green default */
    color: white;
    text-decoration: none;
    border-radius: 6px;
    font-size: 18px;
    transition: background 0.3s ease;
    text-align: center;
}

.menu-link:hover {
    background: #047857;
}

/* Active page highlight */
.menu-link.active {
    background: #10b981; /* light green */
}


/* Logout Button */
.logout-btn {
    width: 100%;
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
}

.logout-btn:hover {
    background: #b91c1c; /* darker red on hover */
}


        .summary-cards { display: flex; gap: 20px; margin-bottom: 20px; }
        .summary-card { flex: 1; background: white; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .summary-card h3 { margin: 0 0 10px 0; color: #333; }
        .summary-card p { font-size: 24px; font-weight: bold; color: #065f46; margin: 0; }
table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 20px;
  background: white;
  border-radius: 10px;
  overflow: hidden; /* para gumana ang rounded corners */
}

th, td {
  padding: 12px;
  border: 1px solid #ddd;
  text-align: left;
  vertical-align: top;
}

th {
  background: #065f46;
  color: white;
}

.search-bar form {
    display: flex;
    align-items: center;
    gap: 8px;
}

.search-bar input[type="text"] {
    padding: 8px;
    width: 305px;
}

.search-bar button {
    padding: 8px 14px;
    width: auto;          /* ⭐ important */
    flex: 0 0 auto;       /* ⭐ prevent stretching */
    white-space: nowrap; /* prevent text wrap */
}


        .action-btn { padding: 4px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .approve { background: #28a745; color: white; }
        .declined { background: #dc3545; color: white; }
        .delete-btn { background: #dc3545; color: white; }
        .edit-btn { background: #007bff; color: white; }
        select, input[type=text] { padding: 6px; border-radius: 4px; border: 1px solid #ccc; width: 100%; box-sizing: border-box; }
        form.inline-form { display: inline-block; margin: 0; }
        .duration-cell { white-space: nowrap; }
        .success-msg { color: green; background: #d4edda; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .pagination { text-align: center; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; margin: 0 2px; border: 1px solid #ddd; text-decoration: none; color: #065f46; }
        .pagination a:hover { background: #10b981; }
        .pagination .current { background: #065f46; color: white; }
        
        .content {
    flex-grow: 1;
    padding: 70px;
    background: #f4f4f4;
    overflow-y: auto;

    /* default: border lang */
    margin-left: 8px;
    transition: margin-left 0.35s ease;
}

/* Filters section */
.filters input[type="text"] {
    width: 250px;/* fixed width */
    padding: 10px;
}

.filters button {
    width: auto;    /* fit content */
    flex: 0 0 auto; /* prevent stretching */
}

.user_buttons button {
    width: auto;    /* fit content */
    flex: 0 0 auto; /* prevent stretching */
}

/* Table improvements */
#active-accounts-table {
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

#active-accounts-table th {
    position: sticky;
    top: 0;
    z-index: 1;
}

/* Search button */
#apply-filters {
    background-color: #6c757d;
    color: #fff;
}

#apply-filters:hover {
    background-color: #495057;
}

/* Reset button */
#reset-filters {
    background-color: #6c757d;
    color: #fff;
}

#reset-filters:hover {
    background-color: #495057;
}
/* Reset button */
#add-filters {
    background-color: #065f46;
    color: #fff;
}

#add-filters:hover {
    background-color: #10b981;
}

@media (max-width: 768px) {
    .sidebar {
        display: none;
    }

    .content {
        margin-left: 0;
    }
}

        /* Modal styles */
        #editUserModal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0; top: 0; width: 100%; height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        #editUserModal .modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            width: 400px;
            box-shadow: 0 0 10px rgba(0,0,0,0.25);
            position: relative;
        }
        #editUserModal h3 {
            margin-top: 0;
            margin-bottom: 15px;
            text-align: center;
        }
        #editUserModal label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
        }
        #editUserModal select, #editUserModal input[type=text] {
                width: 100%;
                padding: 8px;
                margin-top: 5px;
                border-radius: 6px;
                border: 1px solid #ccc;
                box-sizing: border-box;
            }
            #editUserModal .modal-buttons {
                margin-top: 20px;
                text-align: center;
            }
            #editUserModal button {
                padding: 10px 20px;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                margin: 0 8px;
            }
            #editUserModal .save-btn {
                background-color: #28a745;
                color: white;
            }
            #editUserModal .save-btn:hover {
                background-color: #218838;
            }
            #editUserModal .cancel-btn {
                background-color: #6c757d;
                color: white;
            }
            #editUserModal .cancel-btn:hover {
                background-color: #5a6268;
            }
            #editUserModal .delete-btn {
                background-color: #dc3545;
                color: white;
            }
            #editUserModal .delete-btn:hover {
                background-color: #c82333;
            }
.modal {
  display:none;
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.5);
}
.modal-content {
  background:#fff;
  width:440px;
  margin:5% auto;
  padding:20px;
  border-radius:8px;
}

.modaladd {
  display: none;
  position: fixed;
  z-index: 9999;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.5);
}

.modal-contentadd {
  background: #fff;
  width: 500px;
  max-width: 95%;
  margin: 5% auto;
  padding: 20px;
  border-radius: 8px;
}

.close {
  float:right;
  cursor:pointer;
  font-size:22px;
}
 select, button {
  width:100%;
  margin:8px 0;
  padding:8px;
}
input {
      width:95%;
  margin:8px 0;
  padding:8px;
}

}
.tab-buttons button {
  width:50%;
}
.active {
  background:#007bff;
  color:#fff;
}
.template-link {
  display:block;
  margin:10px 0;
}
.employee-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.employee-header h2 {
    margin-bottom: 8px;
}
.add-btn {
    width: auto !important;   /* ⬅️ pinaka-importante */
    display: inline-flex;     /* ⬅️ para di mag full width */
    align-items: center;
    gap: 4px;

    padding: 6px 12px;
    font-size: 13px;

    background: #2c7be5;
    color: #fff;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    font-size: 12px;
    border-radius: 4px;
    text-decoration: none;
    cursor: pointer;
    border: none;
}

.btn-primary {
    background: #2c7be5;
    color: #fff;
}

.btn-primary:hover {
    background: #1a68d1;
}

.btn-secondary {
    background: #e9eefb;
    color: #2c7be5;
    border: 1px solid #c6d4f5;
}

.btn-secondary:hover {
    background: #dfe7ff;
}

.toast {
    position: fixed;
    top: 20px;
    right: 20px;
    min-width: 260px;
    padding: 14px 18px;
    border-radius: 8px;
    color: #fff;
    font-size: 14px;
    z-index: 9999;
    box-shadow: 0 8px 20px rgba(0,0,0,.15);
    animation: slideIn .4s ease, fadeOut .4s ease 3.5s forwards;
}

.toast-success { background: #2ecc71; }
.toast-error   { background: #e74c3c; }
.toast-warning { background: #f39c12; }

@keyframes slideIn {
    from { transform: translateX(120%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@keyframes fadeOut {
    to { opacity: 0; transform: translateX(120%); }
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

.password-container {
    position: relative;
    width: 500px; /* adjust as needed */
}

.password-container input {
    width: 100%;
    padding-right: 40px;
    box-sizing: border-box;
    font-size: 16px;
}

.password-container .toggle-password {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #666;
    font-size: 18px;
}

.password-container .toggle-password:hover {
    color: #007bff;
}

/* Base style for all status badges */
.status {
    padding: 4px 12px;
    border-radius: 12px;       /* makes it pill-shaped */
    color: white;              /* text color */
    font-weight: bold;
    font-size: 13px;
    display: inline-block;
    text-align: center;
    border: 1px solid transparent; /* optional border */
}

/* Colors per status */
.status-pending { 
    background-color: gray; 
    border-color: darkgray;
}

.status-in_progress { 
    background-color: orange; 
    border-color: darkorange;
}

.status-closed { 
    background-color: blue; 
    border-color: darkblue;
}

.status-resolved { 
    background-color: green; 
    border-color: darkgreen;
}

.status-declined { 
    background-color: red; 
    border-color: darkred;
}

.status-reopened { 
    background-color: #007BFF; 
    border-color: #0056b3;
}

.status-cancelled {
    background-color: #8e44ad;  /* purple */
    border-color: #732d91;      /* darker purple border */
    color: #fff;                /* white text */
}

.status-reopened { background:#007BFF; }

/* Compact style only for departments table */
.compact-table {
    border-collapse: collapse;
    width: 90%;           /* table width */
    font-size: 16px;      /* text size */
    margin: 0 auto;       /* center the table horizontally */
    border-radius: 10px;  /* rounded corners */
    overflow: hidden;     /* ensures inner cells respect the radius */
    border: 1px solid #ddd; /* optional outer border */
}


.compact-table th, 
.compact-table td {
    padding: 12px 16px;        /* more space inside cells */
    vertical-align: middle;    /* keep content centered */
    border: 1px solid #ddd;    /* optional border */
}

.compact-table .action-btn {
    padding: 4px 10px;         /* small buttons */
    font-size: 14px;
}

</style>

        <script>
            
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');
    const eyeIcon = document.getElementById('eyeIcon');

    togglePassword.addEventListener('click', function() {
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            eyeIcon.classList.remove('fa-eye');
            eyeIcon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            eyeIcon.classList.remove('fa-eye-slash');
            eyeIcon.classList.add('fa-eye');
        }
    });
});



function openModal() {
  document.getElementById('employeeModal').style.display = 'block';
  showTab('manual');
}

function closeModal() {
  document.getElementById('employeeModal').style.display = 'none';
}

function showTab(tab) {
  const modal = document.getElementById('employeeModal');

  const manualForm = modal.querySelector('#add_employee');
  const excelForm  = modal.querySelector('.excel-upload-form');

  const manualBtn = document.getElementById('manualBtn');
  const excelBtn  = document.getElementById('excelBtn');

  manualForm.style.display = 'none';
  excelForm.style.display  = 'none';

  manualBtn.classList.remove('active');
  excelBtn.classList.remove('active');

  if (tab === 'manual') {
    manualForm.style.display = 'block';
    manualBtn.classList.add('active');
  } else {
    excelForm.style.display = 'block';
    excelBtn.classList.add('active');
  }
}

document.querySelector('.excel-upload-form input[type="file"]')
  .addEventListener('change', function() {
      document.querySelector('.btn-primary').disabled = !this.files.length;
  });
  

</script>

    </head>
    <body>

<?php if ($success || $error || $warning): ?>
<div id="floatingMessage" style="
position: fixed;
top: 25px;
left: 50%;
transform: translateX(-50%);
max-width: 500px;
padding: 18px 30px;
border-radius: 10px;
font-size: 18px;
font-weight: bold;
box-shadow: 0 6px 20px rgba(0,0,0,0.25);
z-index: 9999;
text-align: center;
background-color: <?= $success ? '#d4edda' : ($warning ? '#fff3cd' : '#f8d7da') ?>;
color: <?= $success ? '#155724' : ($warning ? '#856404' : '#721c24') ?>;
">
<?= $success ?: ($warning ?: $error) ?>
</div>

<script>
setTimeout(() => {
    const msg = document.getElementById("floatingMessage");
    if (msg) {
        msg.style.opacity = "0";
        setTimeout(() => msg.remove(), 500);
    }
}, 2500);
</script>
<?php endif; ?>

        <div class="sidebar">
            <div>
                <h2>Admin Dashboard</h2>
<div class="menu">
    <a href="admin_dashboard.php" class="menu-link">Ticket History</a>
    <a href="admin_dashboard.php?page=users" class="menu-link">Staff Information</a>
    <a href="admin_dashboard.php?page=departments" class="menu-link">Manage Departments</a>
</div>
<script>
window.addEventListener('DOMContentLoaded', () => {
    const links = document.querySelectorAll('.menu-link');
    links.forEach(link => {
        if (link.href === window.location.href) {
            link.classList.add('active');
        }
    });
});

</script>



            </div>
            <button class="logout-btn" onclick="return confirm('Are you sure you want to logout?') ? window.location.href='logout.php' : false;">
                Logout
            </button>
        </div>

    <?php if (!isset($_GET['page']) || $_GET['page'] === ''): ?>
        <div class="content" id="content-area">
            <h2>Ticket History</h2>

            <?php
            // Ticket Summary Cards
            $total_tickets = $conn->query("SELECT COUNT(*) as total FROM tickets")->fetch_assoc()['total'];
            $open_tickets = $conn->query("SELECT COUNT(*) as total FROM tickets WHERE status = 'in_progress'")->fetch_assoc()['total'];
            $pending_tickets = $conn->query("SELECT COUNT(*) as total FROM tickets WHERE status = 'pending'")->fetch_assoc()['total'];
            ?>
            <div class="summary-cards">
                <div class="summary-card">
                    <h3>Total Tickets</h3>
                    <p><?php echo $total_tickets; ?></p>
                </div>
                <div class="summary-card">
                    <h3>In Progress</h3>
                    <p><?php echo $open_tickets; ?></p>
                </div>
                <div class="summary-card">
                    <h3>Pending</h3>
                    <p><?php echo $pending_tickets; ?></p>
                </div>
            </div>

            <div class="search-bar">
                <form method="GET">
                    <input type="text" name="search" placeholder="Search by Name, Department, or Control Number" value="<?php echo htmlspecialchars($search); ?>">
                    <input type="hidden" name="page_num" value="1"> <!-- Reset to page 1 on search -->
                    <button type="submit">Search</button>
                    <button type="button" onclick="window.location.href='admin_dashboard.php'">Refresh</button>
                </form>
            </div>
                    <a href="export_general_report.php" style="text-decoration:none;">
    <button style="
        background: #16a34a;
        width: 40%;
        color: white;
        border: none;
        padding: 10px 18px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: 0.2s ease-in-out;
        box-shadow: 0 2px 6px rgba(0,0,0,0.15);
    "
    onmouseover="this.style.background='#15803d'"
    onmouseout="this.style.background='#16a34a'">
        Export General Report
    </button>
</a>
            <?php
            // Fetch tickets with duration and pagination
            $search_sql = "";
            if ($search !== "") {
                $search_esc = $conn->real_escape_string($search);
                $search_sql = "WHERE d.department_name LIKE '%$search_esc%' OR u.name LIKE '%$search_esc%' OR t.control_number LIKE '%$search_esc%'";
            }
            $tickets_sql = "
                SELECT t.ticket_id, t.control_number, u.name AS user_name, d.department_name, t.title,t.issue, t.priority, t.status, t.created_at, t.ended_at
                FROM tickets t
                JOIN users u ON t.user_id = u.user_id
                JOIN departments d ON t.department_id = d.department_id
                $search_sql
                ORDER BY t.created_at ASC
                LIMIT $limit OFFSET $offset
            ";
            $tickets = $conn->query($tickets_sql);

            // Get total tickets for pagination
            $total_tickets_sql = "
                SELECT COUNT(*) as total
                FROM tickets t
                JOIN users u ON t.user_id = u.user_id
                JOIN departments d ON t.department_id = d.department_id
                $search_sql
            ";
            $total_tickets_result = $conn->query($total_tickets_sql);
            $total_tickets_count = $total_tickets_result->fetch_assoc()['total'];
            $total_pages = ceil($total_tickets_count / $limit);
            ?>

            <table>
                <tr>
                    <th>Control No.</th>
                    <th>Sender</th>
                    <th>Department</th>
                    <th>Title</th>
                    <th>Issue</th>
                    <th>Status</th>
                    <th>Duration</th>
                    <th>Actions</th>
                </tr>
                <?php if ($tickets && $tickets->num_rows > 0): ?>
                    <?php while($row = $tickets->fetch_assoc()): 
                        $durationText = formatDuration($row['created_at'], $row['ended_at']);
                        $start = htmlspecialchars($row['created_at']);
                        $end = htmlspecialchars($row['ended_at'] ?? 'N/A');
                    ?>
                        <tr>
                            <td><?php echo $row ['control_number']; ?></td>
                            <td><?php echo htmlspecialchars($row['user_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['title']); ?></td>
                            <td><?php echo htmlspecialchars($row['issue']); ?></td>
<td>
    <span class="status status-<?php echo strtolower(str_replace(' ', '_', $row['status'])); ?>">
        <?php echo ucfirst(htmlspecialchars($row['status'])); ?>
    </span>
</td>


                            <td class="duration-cell">
                                <div class="tooltip">
                                    
                                    <?php if ($durationText !== '-'): ?>
                                    <button class="duration-btn" tabindex="0">⏱️
                                        <span class="tooltiptext">
                                            Start: <?php echo $start; ?><br>
                                            End: <?php echo $end; ?>
                                        </span>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this ticket?');">
                                    <input type="hidden" name="delete_ticket_id" value="<?php echo $row['ticket_id']; ?>">
                                    <button type="submit" class="action-btn delete-btn">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7">No tickets found.</td></tr>
                <?php endif; ?>
            </table>
            <form action="export_tickets.php" method="POST" style="margin-bottom:15px;">
    <button type="submit" class="export-btn">
        📥 Download Tickets
    </button>
</form>

            <!-- Pagination -->
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page_num=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>">&laquo; Previous</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page_num=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page_num=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($_GET['page'] === 'users'): ?>
        <div class="content">
            <?php 
            // Display success message if set
            if (isset($_SESSION['success'])) {
                echo '<div class="success-msg">' . htmlspecialchars($_SESSION['success']) . '</div>';
                unset($_SESSION['success']);
            }
            ?>
            
<div class="employee-header">
    </div>


<div id="employeeModal" class="modaladd">
  <div class="modal-contentadd">

    <span class="close" onclick="closeModal()">&times;</span>
    <h2>Add Employee</h2>

    <!-- Tabs -->
    <div class="tab-buttons">
      <button type="button" onclick="showTab('manual')" id="manualBtn" class="active">
        Manual Add
      </button>
      <button type="button" onclick="showTab('excel')" id="excelBtn">
        Upload Excel
      </button>
    </div>

    <!-- ================= MANUAL ADD ================= -->
<form id="add_employee" method="POST" novalidate>

  <!-- FULL NAME -->
  <input
    type="text"
    name="name"
    placeholder="Full Name"
    required
    pattern="[A-Za-z\s]+"
    title="Letters and spaces only"
    oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')"
  >

  <!-- POSITION -->
  <input
    type="text"
    name="position"
    placeholder="Position"
    required
    pattern="[A-Za-z\s]+"
    title="Letters and spaces only"
    oninput="this.value=this.value.replace(/[^A-Za-z\s]/g,'')"
  >

  <!-- DEPARTMENT -->
  <select name="department_id" required>
    <option value="">Select Department</option>
    <?php
      $deps = $conn->query("SELECT department_id, department_name FROM departments");
      while ($d = $deps->fetch_assoc()) {
          echo "<option value='{$d['department_id']}'>{$d['department_name']}</option>";
      }
    ?>
  </select>

  <!-- EMAIL -->
<input
  type="email"
  name="email"
  id="email"
  placeholder="Email (example@company.com)"
  required
>
  <!-- ROLE -->
  <select name="role" required>
    <option value="staff">Staff</option>
    <option value="support_staff">Support Staff</option>
    <option value="manager_head">Manager Head</option>
    <option value="admin">Admin</option>
  </select>

  <button type="submit" name="add_employee">Save Employee</button>
</form>

    <!-- ================= EXCEL UPLOAD ================= -->
    <form method="POST" enctype="multipart/form-data" class="excel-upload-form">

      <h3>Upload Employees via Excel</h3>

      <div class="excel-info">
        <code>Don't have template?</code>
      </div>

      <div class="excel-actions">
        <a href="download_employee_template.php" class="btn btn-secondary">
          Download Template
        </a>

        <div class="file-input-wrapper">
          <input
            type="file"
            name="excel"
            accept=".xlsx,.xls"
            required
          >
        </div>

        <button type="submit" name="upload_excel" class="btn btn-primary">
          📤 Upload Excel
        </button>
      </div>

    </form>

  </div>
</div>


           <h2>Active Accounts</h2>

<!-- Filters -->
<div class="filters">
    <input type="text" id="filter-text" placeholder="Name, Email, Role or Department">

    <button id="apply-filters">Seach</button>
    <button id="reset-filters">Reset</button>
    <button type = 'button' id="add-filters" onclick="openModal()">Add Employee</button>
</div>

<table id="active-accounts-table">
    <thead>
        <tr>
            <th> </th>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Position</th>
            <th>Department</th>
            <th>Edit</th>
        </tr>
    </thead>
    <tbody>
    <?php 
    $active_users = $conn->query("SELECT u.*, d.department_name, d.department_id
                                FROM users u 
                                LEFT JOIN departments d ON u.department_id = d.department_id 
                                WHERE u.status='active'");
    $users_js_data = [];
    if ($active_users && $active_users->num_rows > 0) {
        while($row = $active_users->fetch_assoc()) {
            $users_js_data[$row['user_id']] = [
                'user_id' => $row['user_id'],
                'role' => $row['role'],
                'position' => $row['position'],
                'department_id' => $row['department_id'] ?? '',
                'department_name' => $row['department_name'] ?? '',
                'name' => $row['name'],
                'email' => $row['email'],
            ];
        }
        $active_users->data_seek(0);

        while($row = $active_users->fetch_assoc()) { ?>
                <tr data-id="<?php echo $row['user_id']; ?>"
                data-name="<?php echo htmlspecialchars(strtolower($row['name'])); ?>"
                data-email="<?php echo htmlspecialchars(strtolower($row['email'])); ?>"
                data-role="<?php echo htmlspecialchars(strtolower($row['role'])); ?>"
                data-department="<?php echo htmlspecialchars(strtolower($row['department_name'] ?? '')); ?>">
                
                <!-- Checkbox must have correct value -->
                <td><input type="checkbox" class="user-checkbox" value="<?php echo $row['user_id']; ?>"></td>
                <td><?php echo htmlspecialchars($row['name']); ?></td>
                <td><?php echo htmlspecialchars($row['email']); ?></td>
                <td><?php echo ucfirst(htmlspecialchars($row['role'])); ?></td>
                <td><?php echo htmlspecialchars($row['position']); ?></td>
                <td><?php echo htmlspecialchars($row['department_name'] ?? 'N/A'); ?></td>
                <td>
                <button onclick="showEditModal(<?php echo $row['user_id']; ?>)" class="action-btn edit-btn">Edit</button>
                </td>
                </tr>
        <?php } 
    } else { ?>
        <tr><td colspan="7">No active accounts.</td></tr>
    <?php } ?>
    </tbody>
</table>

<!-- Batch actions -->
<div class="user_buttons">
    <button id="check-all-btn" class="select-btn">Check All</button>
    <button id="batch-delete-btn" class="delete-btn">Delete All</button>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const checkAllBtn = document.getElementById('check-all-btn');
    const batchDeleteBtn = document.getElementById('batch-delete-btn');

    if (!checkAllBtn || !batchDeleteBtn) return;

    // ---- Check All / Uncheck All ----
    checkAllBtn.addEventListener('click', function() {
        const checkboxes = document.querySelectorAll('.user-checkbox');
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);

        // Toggle all checkboxes
        checkboxes.forEach(cb => cb.checked = !allChecked);

        // Update button text
        this.textContent = allChecked ? "Check All" : "Uncheck All";
    });

    // ---- Batch Delete ----
    batchDeleteBtn.addEventListener('click', function() {
        const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
        const selectedIds = Array.from(selectedCheckboxes).map(cb => cb.value);

        if (selectedIds.length === 0) {
            alert("Please select at least one user to delete.");
            return;
        }

        if (!confirm(`Are you sure you want to delete ${selectedIds.length} user(s)? This action cannot be undone.`)) {
            return;
        }

        fetch('delete_users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_ids: selectedIds })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('Users deleted successfully!');
                selectedCheckboxes.forEach(cb => cb.closest('tr').remove());

                // Update "Check All" button if no checkboxes left
                const remaining = document.querySelectorAll('.user-checkbox');
                if (remaining.length === 0) checkAllBtn.disabled = true;
            } else {
                alert('Error deleting users: ' + data.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('An error occurred while deleting users.');
        });
    });
});

</script>

<script>
const filterText = document.getElementById('filter-text');
const filterBtn = document.getElementById('apply-filters');
const resetBtn = document.getElementById('reset-filters');
const table = document.getElementById('active-accounts-table');
const rows = table.querySelectorAll('tr[data-name]');

function filterTable() {
    const search = filterText.value.toLowerCase().trim();

    rows.forEach(row => {
        const name = row.dataset.name;
        const email = row.dataset.email;
        const role = row.dataset.role;
        const department = row.dataset.department;

        // Show if any of the fields contain the search text
        const show = name.includes(search) || email.includes(search) || role.includes(search) || department.includes(search);
        row.style.display = show ? '' : 'none';
    });
}

function resetTable() {
    filterText.value = '';
    rows.forEach(row => row.style.display = '');
}

// Event listeners
filterBtn.addEventListener('click', filterTable);
resetBtn.addEventListener('click', resetTable);
</script>

        </div>
    <?php elseif ($_GET['page'] === 'departments'): ?>
        <div class="content">
            <h2 style = "margin-left: 48px;">Manage Departments</h2>
            <?php if (!empty($error)): ?>
            <?php endif; ?>
            <form method="POST" style="margin-bottom:20px; max-width:400px; margin-left: 48px;">
                <label for="department_name">Add New Department:</label><br>
                <input type="text" name="department_name" id="department_name" required placeholder="Department Name" style="width:70%; padding:8px; margin-top:5px; margin-bottom:10px;">
                <button type="submit" name="add_department" class="action-btn approve" style="width: 70%; padding: 8px; margin-top: 5px; margin-bottom: 10px; font-size: 16px; background: #065f46;">Add Department</button>
            </form>

            <table class="compact-table">
    <tr><th>Department Name</th><th>Action</th></tr>
    <?php
    $departments_list = $conn->query("SELECT * FROM departments ORDER BY department_name ASC");
    if ($departments_list && $departments_list->num_rows > 0) {
        while($dept = $departments_list->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($dept['department_name']); ?></td>
                <td>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this department?');">
                        <input type="hidden" name="delete_department_id" value="<?php echo $dept['department_id']; ?>">
                        <button type="submit" class="action-btn delete-btn">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endwhile; 
    } else { ?>
        <tr><td colspan="2">No departments found.</td></tr>
    <?php } ?>
</table>

        </div>
    <?php endif; ?>

<!-- Edit User Modal -->
<div id="editUserModal">
    <div class="modal-content">
        <h3>Edit Staff</h3>

        <form method="POST">
            <input type="hidden" name="edit_user_id" id="edit_user_id">

            <label>Name</label>
            <input
    type="text"
    name="new_name"
    id="new_name"
    required
    oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')"
>


            <label>Email</label>
            <input type="email" name="new_email" id="new_email" required>

            <label>Role</label>
            <select name="new_role" id="new_role" required>
                <option value="staff">Staff</option>
                <option value="support_staff">Support Staff</option>
                <option value="manager_head">Manager Head</option>
                <option value="admin">Admin</option>
            </select>

            <label>Position</label>
            <input
    type="text"
    name="new_position"
    id="new_position"
    required
    oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')"
>

            <label>Department</label>
            <select name="new_department_id" id="new_department_id" required>
                <option value="">-- Select Department --</option>
                <?php
                $deps = $conn->query("SELECT * FROM departments ORDER BY department_name");
                while ($d = $deps->fetch_assoc()):
                ?>
                    <option value="<?= $d['department_id'] ?>">
                        <?= htmlspecialchars($d['department_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <div class="modal-buttons">
                <button type="submit" class="save-btn">Save</button>
                <button type="button" class="cancel-btn" onclick="hideEditModal()">Cancel</button>
                <button type="button" class="delete-btn" onclick="confirmDeleteUser()">Delete</button>
            </div>
        </form>
    </div>
</div>


<script>
    // Store users data for modal population
const usersData = <?= json_encode($users_js_data) ?>;

function showEditModal(userId) {
    const user = usersData[userId];
    if (!user) return;

    document.getElementById('edit_user_id').value = user.user_id;
    document.getElementById('new_name').value = user.name;
    document.getElementById('new_email').value = user.email;
    document.getElementById('new_role').value =
        user.role === 'user' ? 'staff' : user.role;
    document.getElementById('new_position').value = user.position || '';
    document.getElementById('new_department_id').value = user.department_id || '';

    document.getElementById('editUserModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function hideEditModal() {
    document.getElementById('editUserModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function confirmDeleteUser() {
    if (!confirm('Delete this user?')) return;

    const form = document.createElement('form');
    form.method = 'POST';

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'delete_user_id';
    input.value = document.getElementById('edit_user_id').value;

    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}
</script>


<!-- Tooltip styles and duration button styles -->
<style>
 /* ================================
   MODAL OVERLAY
================================ */
#editUserModal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    overflow-y: auto;
}

/* ================================
   MODAL CONTENT
================================ */
#editUserModal .modal-content {
    background: #ffffff;
    width: 420px;
    max-width: 95%;
    margin: 8% auto;
    padding: 25px 30px;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25);
}

/* ================================
   TITLE
================================ */
#editUserModal h3 {
    margin: 0 0 20px;
    text-align: center;
    font-size: 20px;
    font-weight: 700;
    color: #333;
}

/* ================================
   LABELS
================================ */
#editUserModal label {
    display: block;
    margin-bottom: 6px;
    font-size: 14px;
    font-weight: 600;
    color: #444;
}

/* ================================
   INPUTS & SELECT
================================ */
#editUserModal input[type="text"],
#editUserModal input[type="email"],
#editUserModal select {
    width: 100%;
    padding: 10px 12px;
    font-size: 14px;
    border-radius: 8px;
    border: 1px solid #ccc;
    box-sizing: border-box;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

/* ================================
   FOCUS EFFECT
================================ */
#editUserModal input:focus,
#editUserModal select:focus {
    border-color: #007BFF;
    outline: none;
    box-shadow: 0 0 0 2px rgba(0,123,255,0.15);
}

/* ================================
   FIELD SPACING
================================ */
#editUserModal label + input,
#editUserModal label + select {
    margin-bottom: 14px;
}

/* ================================
   BUTTON CONTAINER
================================ */
#editUserModal .modal-buttons {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

/* ================================
   BUTTONS
================================ */
#editUserModal .save-btn,
#editUserModal .cancel-btn,
#editUserModal .delete-btn {
    flex: 1;
    padding: 10px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
}

/* ================================
   BUTTON COLORS
================================ */
#editUserModal .save-btn {
    background: #007BFF;
    color: #fff;
}

#editUserModal .cancel-btn {
    background: #6c757d;
    color: #fff;
}

#editUserModal .delete-btn {
    background: #dc3545;
    color: #fff;
}

/* ================================
   HOVER EFFECTS
================================ */
#editUserModal .save-btn:hover {
    background: #0069d9;
}

#editUserModal .cancel-btn:hover {
    background: #5a6268;
}

#editUserModal .delete-btn:hover {
    background: #c82333;
}

/* ================================
   RESPONSIVE
================================ */
@media (max-width: 480px) {
    #editUserModal .modal-content {
        margin: 15% auto;
        padding: 20px;
    }
}

            </style>

        </body>
    </html>
<?php $conn->close(); ?>