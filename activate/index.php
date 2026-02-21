<?php
require __DIR__ . '/../config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();

$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = strtoupper(trim($_POST['code'] ?? ''));
    
    // Remove any spaces or extra dashes user might have added
    $code = preg_replace('/[^A-Z0-9\-]/', '', $code);
    
    // If user entered without dash (e.g. "ZLGKBQFY"), add it
    if (strlen($code) === 8 && strpos($code, '-') === false) {
        $code = substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }

    // First check if code exists at all
    $checkStmt = db()->prepare("
        SELECT id, device_id, expires_at, approved_at 
        FROM device_auth_requests 
        WHERE device_code = ?
    ");
    $checkStmt->execute([$code]);
    $existing = $checkStmt->fetch();
    
    if (!$existing) {
        $error = 'Code not found. Please check and try again.';
    } elseif ($existing['approved_at']) {
        $error = 'This code has already been used.';
    } elseif (strtotime($existing['expires_at']) < time()) {
        $error = 'Code has expired. Please get a new code on your TV.';
    } else {
        // Code is valid - approve it
        $stmt = db()->prepare("
            UPDATE device_auth_requests
            SET user_id = ?, approved_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([Auth::id(), $existing['id']]);

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activate TV - Rew</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #0a0a0a;
            color: white;
            font-family: system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .box {
            background: #171717;
            padding: 48px;
            border-radius: 16px;
            width: 100%;
            max-width: 440px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
        }
        h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }
        .subtitle {
            color: #888;
            margin-bottom: 32px;
        }
        input {
            width: 100%;
            padding: 16px;
            font-size: 24px;
            text-align: center;
            letter-spacing: 8px;
            text-transform: uppercase;
            background: #222;
            border: 2px solid #333;
            border-radius: 8px;
            color: white;
            margin-bottom: 16px;
            outline: none;
            transition: border-color 0.2s;
        }
        input:focus {
            border-color: #e50914;
        }
        input::placeholder {
            letter-spacing: 4px;
            color: #555;
        }
        button {
            width: 100%;
            padding: 16px;
            font-size: 18px;
            font-weight: 600;
            background: #e50914;
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            transition: background 0.2s;
        }
        button:hover {
            background: #f40612;
        }
        .error {
            margin-top: 20px;
            padding: 12px;
            background: rgba(255,107,107,0.1);
            border: 1px solid #ff6b6b;
            border-radius: 8px;
            color: #ff6b6b;
        }
        .success {
            margin-top: 20px;
            padding: 16px;
            background: rgba(74,222,128,0.1);
            border: 1px solid #4ade80;
            border-radius: 8px;
            color: #4ade80;
        }
        .success h2 {
            font-size: 20px;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <div class="box">
        <h1>Activate TV</h1>
        <p class="subtitle">Enter the code shown on your TV</p>

        <?php if ($success): ?>
            <div class="success">
                <h2>✓ TV Connected!</h2>
                <p>You can now close this page.</p>
            </div>
        <?php else: ?>
            <form method="POST" autocomplete="off">
                <input name="code" placeholder="XXXX-XXXX" maxlength="9" required autofocus>
                <button type="submit">Connect TV</button>
            </form>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
    </div>
</body>
</html>