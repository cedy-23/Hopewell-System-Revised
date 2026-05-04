<?php
session_start();

$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->query("SET time_zone = '+08:00'");
$message = "";
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if (empty($token)) {
    $message = "Invalid or missing password reset token.";
} else {
    // Validate token from database
    $stmt = $conn->prepare("
        SELECT prt.user_id, prt.expires_at, u.name 
        FROM password_reset_tokens prt
        JOIN users u ON prt.user_id = u.user_id
        WHERE prt.token = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $data = $result->fetch_assoc();
        $user_id = $data['user_id'];
        $expires = strtotime($data['expires_at']);
        $now = time();

        if ($now > $expires) {
            $message = "⚠️ This password reset link has expired. Please request a new one.";
        } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
            $new_pass = $_POST['new_password'];
            $confirm_pass = $_POST['confirm_password'];

            if ($new_pass === $confirm_pass && strlen($new_pass) >= 8) {
                $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);

                // Update user's password
                $update = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $update->bind_param("si", $hashed_pass, $user_id);
                $update->execute();

                // Delete token after successful reset
                $conn->query("DELETE FROM password_reset_tokens WHERE user_id = $user_id");

                $message = "✅ Your password has been reset successfully! You can now log in.";
            } else {
                $message = "❌ Passwords do not match or must be at least 8 characters long.";
            }
        }
    } else {
        $message = "❌ Invalid or expired token. Please request a new password reset link.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reset Password - MIS Ticketing System</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
      body {
          background: url('background.png') no-repeat center center/cover;
      }
      body {
    display: flex;
    justify-content: center;   /* center horizontally */
    align-items: center;       /* center vertically */
    min-height: 100vh;         /* full viewport height */  
    margin: 0;
    font-family: Arial, sans-serif;
}

      /* RESET PASSWORD STYLE */
    .container {
          background: rgba(255, 255, 255, 0.95);
          padding: 18px 20px;
          border-radius: 10px;
          text-align: center;
          width: 100%;
          max-width: 550px;
          box-shadow: 0 3px 15px rgba(0,0,0,0.2);
      }

      img {
          max-width: 150px;
          margin-bottom: 10px;
      }

      h2 {
          margin: 8px 0 15px;
          color: #2d572c;
          font-size: 18px;
          font-weight: bold;
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


      button {
          width: 95%;
          padding: 10px;
          background: #17984b;
          color: #fff;
          border: none;
          border-radius: 5px;
          font-size: 14px;
          cursor: pointer;
          margin-top: 9px;
      }

      button:hover {
          background: #137c3b;
      }

      a {
          display: block;
          margin-top: 10px;
          color: #17984b;
          text-decoration: none;
          font-size: 12px;
      }

      a:hover {
          text-decoration: underline;
      }

      .message {
          margin-top: 10px;
          font-size: 13px;
          color: red;
      }

.show-pass {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    margin: 8px 0 12px;
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
  <div class="container">
      <img src="logo.png" alt="Logo">
      <h2>Reset Password</h2>

      <?php if (!empty($message)) { echo "<p class='message'>$message</p>"; } ?>

      <?php if (isset($data) && strtotime($data['expires_at']) > time()): ?>
<form method="POST">
    <input type="password" class="password-field" id="new_password" name="new_password" placeholder="Enter new password" required minlength="8">
    
    <input type="password" class="password-field" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required minlength="8">

    <label class="show-pass">
        <input type="checkbox" onclick="togglePasswords()"> Show password
    </label>

    <button type="submit">Reset Password</button>
</form>

<script>
function togglePasswords() {
    const pass1 = document.getElementById("new_password");
    const pass2 = document.getElementById("confirm_password");

    if (pass1.type === "password") {
        pass1.type = "text";
        pass2.type = "text";
    } else {
        pass1.type = "password";
        pass2.type = "password";
    }
}
</script>

      <?php endif; ?>

      <a href="index.php">Back to Login</a>
  </div>
</body>
</html>
