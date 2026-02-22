<?php
require __DIR__ . '/config/config.php';
require BASE_PATH . '/core/Guard.php';

Auth::initSession();

if (!Auth::check()) {
    header('Location: landing.php');
    exit;
}

$animeId       = $_GET['id'] ?? '';
$episodeNumber = isset($_GET['ep']) ? (int)$_GET['ep'] : 1;

if (empty($animeId)) {
    header('Location: index.php');
    exit;
}

$stmt = db()->prepare("
    SELECT autoplay, skip_intro, skip_outro, preferred_language
    FROM user_settings
    WHERE user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$userSettings = $stmt->fetch() ?: [];

$headerType = 'watch';
require __DIR__ . '/modules/header.php';
?>

<script id="watch-config" type="application/json"><?= json_encode([
    'API_BASE'          => 'https://api.rewcrew.lv/api/v2/hianime',
    'KODIK_API_BASE'    => 'https://kodik.rewcrew.lv',
    'PROXY_BASE'        => 'https://corsproxy.rewcrew.lv/proxy',
    'ANISKIP_API'       => 'https://api.aniskip.com/v2/skip-times',
    'MERGE_API'         => 'https://api.rewcrew.lv/api/v2/merge',
    'animeId'           => $animeId,
    'currentEpisode'    => $episodeNumber,
    'autoplay'          => (int)($userSettings['autoplay']            ?? 1),
    'autoSkipIntro'     => (int)($userSettings['skip_intro']          ?? 1),
    'autoSkipOutro'     => (int)($userSettings['skip_outro']          ?? 0),
    'preferredLanguage' => $userSettings['preferred_language']         ?? 'auto',
], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
<link  rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link  rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css">
<script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>

<link rel="stylesheet" href="/assets/css/watch.css">
<link rel="stylesheet" href="/assets/css/watch.patch.css"> 

<div class="watch-container">
  <div class="main-content">
    <div class="video-section">
      <div class="video-wrapper">
        <div id="videoContainer">
          <video id="videoPlayer" class="video-player" preload="none" playsinline></video>

          <button class="skip-button" id="skipIntro" style="z-index:9999;" onclick="skipIntro()">Skip Intro <i class="fas fa-forward"></i></button>
          <button class="skip-button" id="skipOutro" style="z-index:9999;" onclick="skipOutro()">Skip Outro <i class="fas fa-forward"></i></button>

          <button class="player-overlay-btn" id="btnEpisodes" type="button" aria-label="Episodes" title="Episodes">
            <i class="fas fa-list"></i>
          </button>

          <button class="player-overlay-btn" id="btnSubtitles" type="button" aria-label="Subtitles" title="Subtitles" style="right: 60px;">
            <i class="fas fa-closed-captioning"></i>
          </button>

          <div class="fs-episodes" id="fsEpisodes" aria-hidden="true">
            <div class="fs-episodes-header">
              <span>Episodes</span>
              <button type="button" class="fs-episodes-close" id="fsEpisodesClose" aria-label="Close">&times;</button>
            </div>
            <div class="fs-episodes-grid" id="fsEpisodesGrid"></div>
          </div>

          <div class="fs-episodes" id="fsSubtitles" aria-hidden="true" style="max-width: 300px;">
            <div class="fs-episodes-header">
              <span>Subtitles</span>
              <button type="button" class="fs-episodes-close" id="fsSubtitlesClose" aria-label="Close">&times;</button>
            </div>
            <div class="fs-episodes-grid" id="fsSubtitlesGrid" style="grid-template-columns: 1fr;">
              <button type="button" class="ep active" data-sub="off">Off</button>
            </div>
          </div>

          <div class="player-ui" id="playerUI" aria-hidden="false">
            <div class="next-episode-overlay" id="nextEpisodeOverlay">
                <div class="next-episode-box">
                  <p>Next episode in <span id="nextCountdown">10</span></p>
                  <div class="next-actions">
                    <button onclick="playNextEpisode()">Play Now</button>
                    <button onclick="cancelNextEpisode()">Cancel</button>
                  </div>
                </div>
              </div>

              <div class="resume-overlay" id="resumeOverlay">
                <div class="resume-box">
                  <p>Resume from <strong id="resumeTime"></strong>?</p>
                  <div class="resume-actions">
                    <button onclick="resumePlayback()">Resume</button>
                    <button onclick="dismissResume()">Start Over</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="video-info">
          <h1 class="anime-title"  id="animeTitle">Loading...</h1>
          <p  class="episode-title" id="episodeTitle">
            Episode <?= htmlspecialchars((string)$episodeNumber) ?>
          </p>
        </div>

        <div class="servers">
          <div class="servers-header">
            <p class="servers-title">Select Server</p>
            <div class="quality-indicator"><span class="quality-badge" id="qualityBadge">AUTO</span></div>
          </div>
          <div class="server-grid" id="serverButtons">
            <div style="grid-column: 1/-1; text-align: center; color: var(--text-secondary); padding: 20px;">Loading servers...</div>
          </div>
        </div>

        <div class="nav-controls">
          <button class="nav-btn" id="prevBtn" onclick="changeEpisode(-1)">
            <i class="fas fa-chevron-left"></i> Previous
          </button>
          <button class="nav-btn" id="nextBtn" onclick="changeEpisode(1)">
            Next <i class="fas fa-chevron-right"></i>
          </button>
        </div>
      </div>
    </div>

    <aside class="sidebar">
      <div class="episodes-section">
        <div class="episodes-header">
          <span class="episodes-title">Episodes</span>
          <span class="episode-count" id="episodeCount">0 Episodes</span>
        </div>
        <div class="episodes-container">
          <div class="episodes-grid" id="episodesList">
            <div style="grid-column: 1/-1; text-align: center; color: var(--text-secondary);">Loading episodes...</div>
          </div>
        </div>
      </div>

      <div class="anime-details">
        <div class="details-header">Information</div>
        <div class="details-content">
          <div class="details-grid" id="animeDetails">
            <div class="detail-item"><span class="detail-label">Loading...</span><span class="detail-value">...</span></div>
          </div>
          <p class="synopsis" id="animeSynopsis">Loading synopsis...</p>
        </div>
      </div>

      <div id="screenshotsSection" class="screenshots-section" style="display:none;">
        <div class="details-header">Screenshots</div>
        <div class="screenshots-grid" id="screenshotsGrid"></div>
      </div>
    </aside>
  </div>

  <div id="screenshotModal" class="screenshot-modal" onclick="closeScreenshotModal()">
    <span class="close-modal">&times;</span>
    <img id="screenshotModalImage" class="screenshot-modal-content">
  </div>
  <div class="error-toast" id="errorToast"></div>
</div>

<script src="/assets/js/watch.js"></script>
<?php require __DIR__ . '/modules/footer.php'; ?>