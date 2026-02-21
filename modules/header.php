<?php
$user = Auth::user();

$headerType  = $headerType  ?? 'home';
$headerTitle = $headerTitle ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>ThatRewAnim - Premium Anime Streaming</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://rew.vissnavslikti.lv/assets/css/header.css">
</head>
<body>

<header class="header">
    <div class="header-content">

        <a href="https://rew.vissnavslikti.lv" class="logo">
            REW CREW<span>ANIME</span>
        </a>
            <div class="search-container">
                <div class="search-box">
                    <input type="text" class="search-input" id="searchInput" placeholder="Search anime, genre, year...">
                    <button class="search-btn" onclick="searchAnime()">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <div class="search-dropdown" id="searchDropdown"></div>
            </div>
        <?php if ($headerType === 'home'): ?>
            <nav class="nav-menu">
                <a href="https://rew.vissnavslikti.lv" class="nav-link active">Home</a>
                <a href="https://rew.vissnavslikti.lv/browse.php" class='nav-link'>Browse</a>
            </nav>
        <?php else: ?>
            <a href="https://rew.vissnavslikti.lv" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Home</span>
            </a>
        <?php endif; ?>
        
        
        <!-- <button type="button" class="lucky-btn" id="btnLucky">
            <i class="fas fa-dice"></i>
            I’m Feeling Lucky
        </button> -->

        <div class="header-user">
            <button class="user-btn" id="userMenuBtn">
                <img
                    src="<?= $user['avatar'] ?: '/assets/img/avatar-default.png' ?>"
                    alt="Avatar"
                    class="user-avatar"
                >
                <span class="user-name"><?= htmlspecialchars($user['display_name'] ?: $user['username']) ?></span>
                <i class="fas fa-chevron-down"></i>
            </button>

            <div class="user-dropdown" id="userDropdown">
                <a href="https://rew.vissnavslikti.lv/profile/profile.php">
                    <i class="fas fa-user"></i> Profile
                </a>

                <a href="https://rew.vissnavslikti.lv/profile/settings.php">
                    <i class="fas fa-cog"></i> Settings
                </a>

                <?php if (Auth::isAdmin()): ?>
                    <a href="/admin">
                        <i class="fas fa-shield-alt"></i> Admin Panel
                    </a>
                <?php endif; ?>

                <div class="dropdown-divider"></div>

                <a href="/modules/user/auth/logout.php" class="logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </div>
</header>

    <script src="https://rew.vissnavslikti.lv/assets/js/search.js"></script>

<script>
(function () {
    const btn = document.getElementById('userMenuBtn');
    const dropdown = document.getElementById('userDropdown');

    if (!btn || !dropdown) return;

    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('show');
    });

    document.addEventListener('click', () => {
        dropdown.classList.remove('show');
    });

    dropdown.addEventListener('click', (e) => {
        e.stopPropagation();
    });
})();
</script>
