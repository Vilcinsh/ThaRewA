<?php
require __DIR__ . '/config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::initSession();

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$deviceCode = bin2hex(random_bytes(16));

db()->prepare("
    INSERT INTO device_auth_requests (device_code, expires_at)
    VALUES (?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))
")->execute([$deviceCode]);


$error = $_GET['error'] ?? null;
?>

<!DOCTYPE html>
<html lang="lv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ThatRewAnim</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>

        :root {
            --primary-red: #dc2626;
            --dark-red: #991b1b;
            --bg-dark: #0a0a0a;
            --bg-card: #171717;
            --text-primary: #ffffff;
            --text-secondary: #a3a3a3;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg-dark);
            color: var(--text-primary);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, #000000 0%, #1a0000 50%, #2d0000 100%);
            opacity: 0.95;
            z-index: -2;
        }

        body::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at center, transparent 0%, #000000 70%);
            z-index: -1;
        }

        .landing-content {
            text-align: center;
            max-width: 600px;
            padding: 40px;
            z-index: 1;
        }

        .logo {
            font-size: 56px;
            font-weight: 800;
            color: var(--primary-red);
            margin-bottom: 20px;
            letter-spacing: -2px;
        }

        .logo span { color: var(--text-primary); }

        .tagline {
            font-size: 30px;
            font-weight: 600;
            margin: 40px 0 50px;
            color: #e5e5e5;
            line-height: 1.4;
        }

        .invite-form { max-width: 440px; margin: 0 auto; }

        .invite-input {
            width: 100%;
            padding: 6px 8px;
            background: var(--bg-card);
            border: 2px solid transparent;
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 16px;
            outline: none;
            transition: all 0.4s ease;
            margin-bottom: 24px;
        }

        .invite-input:focus {
            border-color: var(--primary-red);
            box-shadow: 0 0 30px rgba(220, 38, 38, 0.4);
        }

        .invite-btn {
            width: 100%;
            padding: 8px;
            background: linear-gradient(135deg, var(--primary-red), var(--dark-red));
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.4s ease;
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.35);
            margin-bottom: 16px;
        }

        .invite-btn:hover {
            box-shadow: 0 15px 40px rgba(220, 38, 38, 0.5);
        }

        .error-message {
            background: rgba(220, 38, 38, 0.15);
            border: 1px solid var(--primary-red);
            color: #ff6b6b;
            padding: 16px 24px;
            border-radius: 12px;
            margin-top: 20px;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            animation: shake 0.6s ease;
        }

        .error-message i { font-size: 20px; }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-8px); }
            20%, 40%, 60%, 80% { transform: translateX(8px); }
        }

        .note {
            font-size: 14px;
            color: var(--text-secondary);
            margin-top: 80px;
        }

        /* Animācijas */
        .landing-content > * {
            opacity: 0;
            animation: fadeInUp 1s ease forwards;
        }

        .logo { animation-delay: 0.3s; }
        .tagline { animation-delay: 0.6s; }
        .invite-form { animation-delay: 0.9s; }
        .note { animation-delay: 1.2s; }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ===== REMEMBER ME CHECKBOX ===== */

        .remember-me {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            user-select: none;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .remember-me input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .checkbox-ui {
            width: 22px;
            height: 22px;
            border-radius: 6px;
            background: var(--bg-card);
            border: 2px solid rgba(255,255,255,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s ease;
        }

        .checkbox-ui::after {
            content: '';
            width: 6px;
            height: 12px;
            border-right: 3px solid white;
            border-bottom: 3px solid white;
            transform: rotate(45deg) scale(0);
            transition: transform 0.2s ease;
        }

        .remember-me input:checked + .checkbox-ui {
            background: linear-gradient(135deg, var(--primary-red), var(--dark-red));
            border-color: var(--primary-red);
            box-shadow: 0 0 15px rgba(220,38,38,0.4);
        }

        .remember-me input:checked + .checkbox-ui::after {
            transform: rotate(45deg) scale(1);
        }

        .checkbox-text {
            font-weight: 500;
        }


        .remember-me:hover .checkbox-ui {
            border-color: var(--primary-red);
        }

        .remember-me input:focus + .checkbox-ui {
            outline: 2px solid var(--primary-red);
            outline-offset: 2px;
        }
    
    </style>
<div class="landing-content">
    <h1 class="logo">ThatRew<span>Anim</span></h1>

    <p class="tagline">Tu man ticēsi, ja teikšu, ka šeit nekā nav?</p>

    <form class="invite-form" method="POST" action="/modules/user/auth/login.php">
        <input
            type="email"
            name="email"
            class="invite-input"
            placeholder="E-pasts"
            required
            autocomplete="email"
        >

        <input
            type="password"
            name="password"
            class="invite-input"
            placeholder="Parole"
            required
            autocomplete="current-password"
        >

        <button type="submit" class="invite-btn">
            Ienākt
        </button>
        <label class="remember-me">
            <input type="checkbox" name="remember" value="1">
            <span class="checkbox-ui"></span>
            <span class="checkbox-text">Remember me</span>
        </label>

    </form>

    <?php if ($error === 'invalid'): ?>
        <div class="error-message">
            <i class="fas fa-lock"></i>
            Nepareizs e-pasts vai parole
        </div>
    <?php endif; ?>

    <p class="note">Privāta piekļuve • Tikai lietotājiem</p>
</div>
<script>
setInterval(async () => {
  const r = await fetch('/api/device-auth-status.php?code=<?= $deviceCode ?>');
  const data = await r.json();

  if (data.authenticated) {
    window.location.href = '/tv';
  }
}, 3000);


</script>
</body>
</html>