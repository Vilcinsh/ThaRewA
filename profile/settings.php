<?php
require __DIR__ . '/../config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();

$user = Auth::user();
$userId = $user['id'];

/* Load settings */
$stmt = db()->prepare("SELECT * FROM user_settings WHERE user_id = ?");
$stmt->execute([$userId]);
$settings = $stmt->fetch();

if (!$settings) {
    // safety fallback (should exist, but just in case)
    $settings = [
        'theme' => 'dark',
        'autoplay' => 1,
        'preferred_language' => 'sub',
        'show_spoilers' => 0,
    ];
}

$headerType = 'profile';
require BASE_PATH . '/modules/header.php';
?>

<link rel="stylesheet" href="/assets/css/profile.css">
<link rel="stylesheet" href="/assets/css/style.css">

<main class="profile-container">

    <h1>Settings</h1>

    <form method="POST" action="/settings_save.php" class="settings-form">

        <!-- THEME -->
        <div class="settings-group">
            <label>Theme</label>
            <select name="theme">
                <option value="dark" <?= $settings['theme'] === 'dark' ? 'selected' : '' ?>>Dark</option>
                <option value="light" disabled>Light (soon)</option>
            </select>
        </div>

        <!-- AUTOPLAY -->
        <div class="settings-group">
            <label>
                <input type="checkbox" name="autoplay" <?= $settings['autoplay'] ? 'checked' : '' ?>>
                Autoplay next episode
            </label>
        </div>

        <!-- LANGUAGE -->
        <div class="settings-group">
            <label>Preferred language</label>
            <select name="preferred_language">
                <option value="sub" <?= $settings['preferred_language'] === 'sub' ? 'selected' : '' ?>>Sub</option>
                <option value="dub" <?= $settings['preferred_language'] === 'dub' ? 'selected' : '' ?>>Dub</option>
                <option value="raw" <?= $settings['preferred_language'] === 'raw' ? 'selected' : '' ?>>Raw</option>
            </select>
        </div>

        <!-- SPOILERS -->
        <div class="settings-group">
            <label>
                <input type="checkbox" name="show_spoilers" <?= $settings['show_spoilers'] ? 'checked' : '' ?>>
                Show spoilers
            </label>
        </div>

        <button type="submit" class="btn-primary">Save settings</button>
    </form>

</main>
