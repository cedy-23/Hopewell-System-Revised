<?php
session_start();
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->query("SET time_zone = '+08:00'");
$error = "";

// Handle login
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $employee_id = trim($_POST['employee_id'] ?? '');
    $password    = trim($_POST['password'] ?? '');
    $remember    = isset($_POST['remember']);

    if ($employee_id !== '' && $password !== '') {

$stmt = $conn->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.position,
        u.profile_picture,
        u.role,
        u.password,
        u.status,
        u.department_id,              -- ✅ ADD THIS
        d.department_name
    FROM users u
    LEFT JOIN departments d 
        ON u.department_id = d.department_id
    WHERE u.employee_id = ?
    LIMIT 1
");

        $stmt->bind_param("s", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {

            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {

                if ($user['status'] === 'active') {

$_SESSION['user_id']         = $user['user_id'];
$_SESSION['role']            = $user['role'];
$_SESSION['name']            = $user['name'];
$_SESSION['email']           = $user['email'];
$_SESSION['position']        = $user['position'];
$_SESSION['department_name'] = $user['department_name'];
$_SESSION['department_id'] = $user['department_id'];
$_SESSION['profile_picture'] = $user['profile_picture'];


                    // REMEMBER ME (30 DAYS)
                    if ($remember) {
                        setcookie('user_id', $user['user_id'], time() + (30 * 24 * 60 * 60), "/");
                        setcookie('role', $user['role'], time() + (30 * 24 * 60 * 60), "/");
                        setcookie('user_name', $user['name'], time() + (30 * 24 * 60 * 60), "/");
                    }

                    // ROLE-BASED REDIRECT
                    if ($user['role'] === 'admin') {
                        header("Location: admin_dashboard.php");
                    } elseif ($user['role'] === 'support_staff') {
                        header("Location: manager_dashboard.php");
                    } elseif ($user['role'] === 'manager_head') {
                        header("Location: manager_head_dashboard.php");
                    } else {
                        header("Location: staff_dashboard.php");
                    }
                    exit();

                } elseif ($user['status'] === 'pending') {
                    $error = "⏳ Your account is awaiting admin approval.";
                } elseif ($user['status'] === 'declined') {
                    $error = "❌ Your account was declined. Please contact MIS.";
                } else {
                    $error = "⚠️ Account inactive. Contact MIS.";
                }

            } else {
                $error = "❌ Invalid Employee ID or Password.";
            }

        } else {
            $error = "❌ Invalid Employee ID or Password.";
        }

        $stmt->close();

    } else {
        $error = "❌ Please enter both Employee ID and Password.";
    }
}
?>
    
<?php if (!empty($error)): ?>
<div id="floatingMessage"><?= $error ?></div>
<script>
    setTimeout(() => {
        const msg = document.getElementById('floatingMessage');
        msg.style.opacity = "0";
        msg.style.transition = "opacity 0.5s ease";
        setTimeout(() => msg.remove(), 500);
    }, 1500);
</script>
<?php endif; ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login - Ticketing System</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="style.css">
  <style>
  body {
    background: url('background.png') no-repeat center center/cover;
}

/*LOG IN STYLE */
body {
    margin: 0;
    padding: 0;
    height: 100vh;
    font-family: Arial, sans-serif;
    display: flex;
    justify-content: center;
    align-items: center;
}

.login-container {
    background: rgba(255, 255, 255, 0.95);
    padding: 28px 26px;
    border-radius: 10px;
    text-align: center;
    width: 100%;
    min-height: 250px;
    max-width: 650px;
    margin: 0 10px;
    box-shadow: 0 3px 15px rgba(0,0,0,0.2);
}

.login-container img {
    max-width: 150px;
    margin-bottom: 10px;
}

.login-container h2 {
    margin: 8px 0 15px;
    color: #2d572c;
    font-size: 18px;
    font-weight: bold;
}

.login-container input {
    width: 100%;
    padding: 14px;
    margin: 8px 0;
    border: 1px solid #ccc;
    border-radius: 5px;
    font-size: 15px;
    box-sizing: border-box;
}

input[type="password"],
input[type="text"] {
    width: 100%;
    padding: 12px;
    margin: 8px 0;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
}

.login-container button {
    width: 100%;
    padding: 15px;
    background: #17984b;
    color: #fff;
    border: none;
    border-radius: 5px;
    font-size: 16px;
    cursor: pointer;
    margin-top: 9px;
}

.login-container button:hover {
    background: #137c3b;
}

.login-container a {
    display: block;
    margin-top: 10px;
    color: #17984b;
    text-decoration: none;
    font-size: 12px;
}

.login-container a:hover {
    text-decoration: underline;
}

#floatingMessage {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    background-color: #f8d7da;
    color: #721c24;
    padding: 20px 0;
    font-size: 22px;
    font-weight: bold;
    text-align: center;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    font-family: Arial, sans-serif;
    z-index: 9999;
    opacity: 1;
    transition: opacity 0.5s ease;
}



.password-hidden {
    -webkit-text-security: disc;
}

.toggle-password {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
}

    #caps-warning {
        display: none;
        color: orange;
        font-size: 12px;
        text-align: left;
        margin-top: -3px;
        margin-bottom: 6px;
    }

@media (max-width: 480px) {
    .login-container {
        max-width: 320px;
        padding: 28px 26px;
        margin: 5px;
        width: 75%;
    }

    .login-container h2 {
        font-size: 16px;
    }
}

.show-pass {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 8px;
    font-size: 14px;
    margin: 10px 5px 13px;
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
    margin: 8px 0;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
}
</style>
</head>
<body>

<div class="login-container">
    <img src="logo.png" alt="HSC Logo">
    <h2>Ticketing System</h2>

    <form method="POST" action="">
        <input type="text" name="employee_id" placeholder="Employee ID" required>

        <div class="password-container">
            <input type="password" class="password-field" name="password" id="password" placeholder="Password" required>

        </div>

        <div id="caps-warning">⚠️ Caps Lock is ON</div>
    <label class="show-pass">
        <input type="checkbox" onclick="togglePasswords()"> Show password
    </label>
        <button type="submit">Log in</button>
    </form>

    <a href="forgot_password.php">Forgot password?</a>
</div>

<script>
function togglePasswords() {
    const pass1 = document.getElementById("password");
    if (pass1.type === "password") {
        pass1.type = "text";

    } else {
        pass1.type = "password";

    }
}
</script>

</body>
</html>