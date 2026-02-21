<?php
require __DIR__ . '/config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();

$headerType = 'home';
require __DIR__ . '/modules/header.php';
?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://rew.vissnavslikti.lv/assets/css/style.css" type="text/css">

    <div class="container">
        <!-- HERO -->
        <div class="hero-slider" id="heroSlider">
            <div class="slider-container" id="sliderContainer"></div>
            <div class="slider-controls">
                <button class="slider-btn" onclick="prevSlide()">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button class="slider-btn" onclick="nextSlide()">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            <div class="slider-dots" id="sliderDots"></div>
        </div>

        <section class="section" id="continue-watch">
            <div class="section-header">
                <h2 class="section-title">Continue Watching</h2>
                <!-- <a href="browse.php?section=recent" class="view-all-btn">
                    View All <i class="fas fa-arrow-right"></i>
                </a> -->
            </div>

            <div class="anime-carousel">
                <button class="carousel-nav prev" data-target="continueCarousel">
                    <i class="fas fa-chevron-left"></i>
                </button>

                <div class="carousel-container" id="continueCarousel">
                    <div class="loading"><div class="loading-spinner"></div></div>
                </div>

                <button class="carousel-nav next" data-target="continueCarousel">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </section>

        <!-- Recent Episodes -->
        <section class="section" id="recent">
            <div class="section-header">
                <h2 class="section-title">Recent Episodes</h2>
                <a href="browse.php?section=recent" class="view-all-btn">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="anime-carousel">
                <button class="carousel-nav prev" data-target="recentCarousel">
                    <i class="fas fa-chevron-left"></i>
                </button>

                <div class="carousel-container" id="recentCarousel">
                    <div class="loading"><div class="loading-spinner"></div></div>
                </div>

                <button class="carousel-nav next" data-target="recentCarousel">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </section>

        <!-- Newly Completed Section -->
        <section class="section" id="completed">
            <div class="section-header">
                <h2 class="section-title">Newly Completed</h2>
                <div class="section-actions">
                    <!-- View all page for completed -->
                    <a href="browse.php?section=completed" class="view-all-btn">
                        View All <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="anime-carousel" id="completedCarousel">
                <button class="carousel-nav prev" data-target="completedContainer">
                    <i class="fas fa-chevron-left"></i>
                </button>

                <div class="carousel-container" id="completedContainer">
                    <div class="loading"><div class="loading-spinner"></div></div>
                </div>

                <button class="carousel-nav next" data-target="completedContainer">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>

            <div class="anime-grid" id="completedGrid" style="display:none;"></div>
        </section>

        <!-- Trending Section -->
        <section class="section" id="trending">
            <div class="section-header">
                <h2 class="section-title">Trending Now</h2>
                <a href="browse.php?section=trending" class="view-all-btn">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="anime-carousel">
                <button class="carousel-nav prev" data-target="trendingCarousel">
                    <i class="fas fa-chevron-left"></i>
                </button>

                <div class="carousel-container" id="trendingCarousel">
                    <div class="loading"><div class="loading-spinner"></div></div>
                </div>

                <button class="carousel-nav next" data-target="trendingCarousel">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </section>

        <!-- Top Rated -->
        <section class="section" id="top-rated">
            <div class="section-header">
                <h2 class="section-title">Top Rated</h2>
                <a href="browse.php?section=top-rated" class="view-all-btn">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="anime-carousel">
                <button class="carousel-nav prev" data-target="topRatedCarousel">
                    <i class="fas fa-chevron-left"></i>
                </button>

                <div class="carousel-container" id="topRatedCarousel">
                    <div class="loading"><div class="loading-spinner"></div></div>
                </div>

                <button class="carousel-nav next" data-target="topRatedCarousel">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </section>
    </div>


    <script src="https://rew.vissnavslikti.lv/assets/js/home.js"></script>

    <?php require __DIR__ . '/modules/footer.php'; ?>
