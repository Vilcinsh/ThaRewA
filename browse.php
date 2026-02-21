<?php
require __DIR__ . '/config/config.php';
require BASE_PATH . '/core/Auth.php';

Auth::requireAuth();

$headerType = 'browse';
require __DIR__ . '/modules/header.php';
?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://rew.vissnavslikti.lv/assets/css/style.css" type="text/css">
    <link rel="stylesheet" href="https://rew.vissnavslikti.lv/assets/css/browse.css" type="text/css">

    <div class="browse-container">
        <!-- Category Pills -->
        <div class="category-nav">
            <button class="category-pill active" data-section="all">
                <i class="fas fa-globe"></i> All
            </button>
            <button class="category-pill" data-section="recent">
                <i class="fas fa-clock"></i> Recent Episodes
            </button>
            <button class="category-pill" data-section="completed">
                <i class="fas fa-check"></i> Completed
            </button>
            <button class="category-pill" data-section="trending">
                <i class="fas fa-fire"></i> Trending
            </button>
            <button class="category-pill" data-section="top-rated">
                <i class="fas fa-star"></i> Top Rated
            </button>
            <button class="category-pill" data-section="popular">
                <i class="fas fa-heart"></i> Popular
            </button>
        </div>
        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-header">
                <h3 class="filter-title">
                    <i class="fas fa-filter"></i> Filters
                </h3>
                <button class="filter-toggle" onclick="toggleFilters()">
                    <i class="fas fa-chevron-up" id="filterIcon"></i>
                </button>
            </div>

            <div class="filter-content" id="filterContent">

                <!-- Filter Controls -->
                <div class="filter-controls">
                    <select class="filter-select" id="typeFilter">
                        <option value="">All Types</option>
                        <option value="tv">TV Series</option>
                        <option value="movie">Movie</option>
                        <option value="ova">OVA</option>
                        <option value="ona">ONA</option>
                        <option value="special">Special</option>
                    </select>

                    <select class="filter-select" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="finished">Finished</option>
                        <option value="ongoing">Ongoing</option>
                        <option value="upcoming">Upcoming</option>
                    </select>

                    <select class="filter-select" id="sortFilter">
                        <option value="">Default Sort</option>
                        <option value="title">Title A-Z</option>
                        <option value="newest">Newest</option>
                        <option value="score">Top Rated</option>
                    </select>
                </div>

                <!-- Alphabet -->
                <div class="alphabet-section">
                    <div class="alphabet-grid">
                        <button class="letter-btn all active" data-letter="all">ALL</button>
                        <button class="letter-btn" data-letter="other">#</button>
                        <?php foreach(range('A', 'Z') as $letter): ?>
                            <button class="letter-btn" data-letter="<?= strtolower($letter) ?>"><?= $letter ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Genres -->
                <div class="genre-section">
                    <div class="genre-grid">
                        <button class="genre-tag" data-genre="action">Action</button>
                        <button class="genre-tag" data-genre="adventure">Adventure</button>
                        <button class="genre-tag" data-genre="comedy">Comedy</button>
                        <button class="genre-tag" data-genre="drama">Drama</button>
                        <button class="genre-tag" data-genre="fantasy">Fantasy</button>
                        <button class="genre-tag" data-genre="horror">Horror</button>
                        <button class="genre-tag" data-genre="isekai">Isekai</button>
                        <button class="genre-tag" data-genre="mecha">Mecha</button>
                        <button class="genre-tag" data-genre="mystery">Mystery</button>
                        <button class="genre-tag" data-genre="psychological">Psychological</button>
                        <button class="genre-tag" data-genre="romance">Romance</button>
                        <button class="genre-tag" data-genre="sci-fi">Sci-Fi</button>
                        <button class="genre-tag" data-genre="slice-of-life">Slice of Life</button>
                        <button class="genre-tag" data-genre="sports">Sports</button>
                        <button class="genre-tag" data-genre="supernatural">Supernatural</button>
                        <button class="genre-tag" data-genre="thriller">Thriller</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- View Controls -->
        <div class="view-controls" id="viewControls" style="display: none;">
            <div class="results-count">
                Found <strong id="totalResults">0</strong> results
            </div>
            <div class="view-options">
                <div class="view-toggle">
                    <button class="view-btn active" data-view="grid">
                        <i class="fas fa-th"></i>
                    </button>
                    <button class="view-btn" data-view="list">
                        <i class="fas fa-list"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Content Container -->
        <div id="contentContainer">
            <div class="loading-state">
                <div class="loader"></div>
                <div class="loading-text">Loading anime...</div>
            </div>
        </div>

        <!-- Pagination -->
        <div class="pagination" id="pagination"></div>
    </div>

    <script>        
        const API_BASE = 'https://thatrew.vercel.app/api/v2/hianime';
        const PROXY = '/proxy.php?url=';
        
        const state = {
            currentSection: 'all',
            currentPage: 1,
            totalPages: 1,
            currentView: 'grid',
            searchQuery: '',
            selectedGenre: '',
            selectedLetter: 'all',
            filters: {
                type: '',
                status: '',
                sort: ''
            },
            animeData: [],
            isLoading: false
        };

        document.addEventListener('DOMContentLoaded', function() {
            initializeEventListeners();
            loadSection('all');
        });

        function initializeEventListeners() {
            // Category pills
            document.querySelectorAll('.category-pill').forEach(pill => {
                pill.addEventListener('click', function() {
                    document.querySelectorAll('.category-pill').forEach(p => p.classList.remove('active'));
                    this.classList.add('active');
                    
                    resetFilters();
                    state.currentSection = this.dataset.section;
                    state.currentPage = 1;
                    loadSection(state.currentSection);
                });
            });

            // View toggle
            document.querySelectorAll('.view-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    state.currentView = this.dataset.view;
                    renderContent();
                });
            });

            // Letter buttons
            document.querySelectorAll('.letter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.letter-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    state.selectedLetter = this.dataset.letter;
                    state.currentPage = 1;
                    
                    if (state.selectedLetter === 'all') {
                        loadSection(state.currentSection);
                    } else {
                        loadAZList(state.selectedLetter);
                    }
                });
            });

            // Genre tags
            document.querySelectorAll('.genre-tag').forEach(tag => {
                tag.addEventListener('click', function() {
                    document.querySelectorAll('.genre-tag').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    state.selectedGenre = this.dataset.genre;
                    state.currentPage = 1;
                    loadGenre(state.selectedGenre);
                });
            });

            // Search
            document.getElementById('searchInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    performSearch();
                }
            });

            // Filters
            document.getElementById('typeFilter').addEventListener('change', updateFilters);
            document.getElementById('statusFilter').addEventListener('change', updateFilters);
            document.getElementById('sortFilter').addEventListener('change', updateFilters);
        }

        function toggleFilters() {
            const content = document.getElementById('filterContent');
            const icon = document.getElementById('filterIcon');
            
            content.classList.toggle('collapsed');
            icon.className = content.classList.contains('collapsed') 
                ? 'fas fa-chevron-down' 
                : 'fas fa-chevron-up';
        }

        function resetFilters() {
            state.searchQuery = '';
            state.selectedGenre = '';
            state.selectedLetter = 'all';
            state.filters = { type: '', status: '', sort: '' };
            
            document.getElementById('searchInput').value = '';
            document.getElementById('typeFilter').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('sortFilter').value = '';
            
            document.querySelectorAll('.genre-tag').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.letter-btn').forEach(b => b.classList.remove('active'));
            document.querySelector('.letter-btn[data-letter="all"]').classList.add('active');
        }

        function updateFilters() {
            state.filters.type = document.getElementById('typeFilter').value;
            state.filters.status = document.getElementById('statusFilter').value;
            state.filters.sort = document.getElementById('sortFilter').value;
            state.currentPage = 1;

            // STATUS FILTER HAS PRIORITY
            if (state.filters.status) {
                loadByStatus(state.filters.status);
                return;
            }

            if (state.selectedGenre) {
                loadGenre(state.selectedGenre);
            } else if (state.selectedLetter !== 'all') {
                loadAZList(state.selectedLetter);
            } else if (state.searchQuery) {
                loadSearch(state.searchQuery);
            } else {
                loadSection(state.currentSection);
            }
        }

        async function loadByStatus(status) {
            if (state.isLoading) return;
            state.isLoading = true;
            showLoading();

            const map = {
                finished: 'completed',
                ongoing: 'recently-updated',
                upcoming: 'upcoming'
            };

            try {
                const category = map[status];
                if (!category) return;

                const endpoint = `${API_BASE}/category/${category}?page=${state.currentPage}`;
                const response = await fetch(PROXY + encodeURIComponent(endpoint));
                const data = await response.json();

                if (data.status === 200 && data.data) {
                    state.animeData = data.data.animes || [];
                    state.totalPages = data.data.totalPages || 1;

                    sortByNewest(state.animeData);

                    renderContent();
                    renderPagination();
                    updateResultsInfo();
                }
            } catch (e) {
                showError('Failed to load status');
            } finally {
                state.isLoading = false;
            }
        }

        function sortByNewest(list) {
            list.sort((a, b) => {
                const da = new Date(a.aired || a.releaseDate || 0);
                const db = new Date(b.aired || b.releaseDate || 0);
                return db - da;
            });
        }


        function performSearch() {
            const query = document.getElementById('searchInput').value.trim();
            if (!query) return;
            
            state.searchQuery = query;
            state.currentPage = 1;
            loadSearch(query);
        }

async function loadSection(section) {
    if (state.isLoading) return;
    state.isLoading = true;
    
    showLoading();
    
    try {
        const sectionMap = {
            'all': 'most-popular',
            'recent': 'recently-updated',  // Maps to recent episodes
            'completed': 'completed',       // Maps to completed anime
            'trending': 'trending',         // Maps to trending
            'top-rated': 'top-10',         // Maps to top rated
            'popular': 'most-popular'      // Maps to most popular
        };
        
        const categoryName = sectionMap[section] || 'most-popular';
        const endpoint = `${API_BASE}/category/${categoryName}?page=${state.currentPage}`;
        
        const response = await fetch(PROXY + encodeURIComponent(endpoint));
        const data = await response.json();
        
        if (data.status === 200 && data.data) {
            state.animeData = data.data.animes || [];
            state.totalPages = data.data.totalPages || 1;
            
            renderContent();
            renderPagination();
            updateResultsInfo();
            
            const newUrl = new URL(window.location);
            newUrl.searchParams.set('section', section);
            window.history.replaceState({}, '', newUrl);
        }
    } catch (error) {
        showError('Failed to load anime');
    } finally {
        state.isLoading = false;
    }
}

document.querySelectorAll('.category-pill').forEach(pill => {
    pill.addEventListener('click', function() {
        document.querySelectorAll('.category-pill').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        
        resetFilters();
        state.currentSection = this.dataset.section;
        state.currentPage = 1;
        
        const newUrl = new URL(window.location);
        newUrl.searchParams.set('section', state.currentSection);
        window.history.pushState({}, '', newUrl);
        
        loadSection(state.currentSection);
    });
});

        async function loadAZList(letter) {
            if (state.isLoading) return;
            state.isLoading = true;
            
            showLoading();
            
            try {
                const endpoint = `${API_BASE}/azlist/${letter}?page=${state.currentPage}`;
                const response = await fetch(PROXY + encodeURIComponent(endpoint));
                const data = await response.json();
                
                if (data.status === 200 && data.data) {
                    state.animeData = data.data.animes || [];
                    state.totalPages = data.data.totalPages || 1;
                    
                    renderContent();
                    renderPagination();
                    updateResultsInfo();
                }
            } catch (error) {
                showError('Failed to load anime');
            } finally {
                state.isLoading = false;
            }
        }

        async function loadGenre(genre) {
            if (state.isLoading) return;
            state.isLoading = true;
            
            showLoading();
            
            try {
                const endpoint = `${API_BASE}/genre/${genre}?page=${state.currentPage}`;
                const response = await fetch(PROXY + encodeURIComponent(endpoint));
                const data = await response.json();
                
                if (data.status === 200 && data.data) {
                    state.animeData = data.data.animes || [];
                    state.totalPages = data.data.totalPages || 1;
                    
                    renderContent();
                    renderPagination();
                    updateResultsInfo();
                }
            } catch (error) {
                showError('Failed to load anime');
            } finally {
                state.isLoading = false;
            }
        }

        async function loadSearch(query) {
            if (state.isLoading) return;
            state.isLoading = true;
            
            showLoading();
            
            try {
                const endpoint = `${API_BASE}/search?q=${encodeURIComponent(query)}&page=${state.currentPage}`;
                const response = await fetch(PROXY + encodeURIComponent(endpoint));
                const data = await response.json();
                
                if (data.status === 200 && data.data) {
                    state.animeData = data.data.animes || [];
                    state.totalPages = data.data.totalPages || 1;
                    
                    renderContent();
                    renderPagination();
                    updateResultsInfo();
                }
            } catch (error) {
                showError('Failed to search');
            } finally {
                state.isLoading = false;
            }
        }

        function renderContent() {
            const container = document.getElementById('contentContainer');
            
            if (!state.animeData || state.animeData.length === 0) {
                showEmpty();
                return;
            }
            
            if (state.currentView === 'grid') {
                renderGridView(container);
            } else {
                renderListView(container);
            }
        }

        function renderGridView(container) {
            const html = `
                <div class="anime-grid">
                    ${state.animeData.map(anime => {
                        const episodeCount = anime.episodes?.sub || anime.episodes?.dub || '?';
                        return `
                            <a href="watch.php?id=${encodeURIComponent(anime.id)}" class="anime-card">
                                <div class="anime-poster">
                                    <img src="${anime.poster || '/assets/images/no-poster.jpg'}" 
                                         alt="${anime.name || anime.jname}"
                                         loading="lazy">
                                    <div class="anime-overlay"></div>
                                    <div class="anime-badges">
                                        ${anime.type ? `<span class="type-badge">${anime.type}</span>` : ''}
                                        ${anime.rating ? `<span class="rating-badge">${anime.rating}</span>` : ''}
                                    </div>
                                    ${anime.episodes ? `<span class="episode-count">EP ${episodeCount}</span>` : ''}
                                </div>
                                <div class="anime-info">
                                    <div class="anime-title">${anime.name || anime.jname}</div>
                                    ${anime.duration ? `<div class="anime-meta">${anime.duration}</div>` : ''}
                                </div>
                            </a>
                        `;
                    }).join('')}
                </div>
            `;
            
            container.innerHTML = html;
        }

        function renderListView(container) {
            const html = `
                <div class="anime-list">
                    ${state.animeData.map(anime => {
                        const episodeCount = anime.episodes?.sub || anime.episodes?.dub || '?';
                        return `
                            <a href="watch.php?id=${encodeURIComponent(anime.id)}" class="anime-list-item">
                                <div class="list-poster">
                                    <img src="${anime.poster || '/assets/images/no-poster.jpg'}" 
                                         alt="${anime.name || anime.jname}"
                                         loading="lazy">
                                </div>
                                <div class="list-content">
                                    <div class="list-title">${anime.name || anime.jname}</div>
                                    <div class="list-meta">
                                        ${anime.type ? `
                                            <span class="meta-item">
                                                <i class="fas fa-tv"></i> ${anime.type}
                                            </span>
                                        ` : ''}
                                        ${anime.episodes ? `
                                            <span class="meta-item">
                                                <i class="fas fa-play"></i> ${episodeCount} Episodes
                                            </span>
                                        ` : ''}
                                        ${anime.duration ? `
                                            <span class="meta-item">
                                                <i class="fas fa-clock"></i> ${anime.duration}
                                            </span>
                                        ` : ''}
                                        ${anime.rating ? `
                                            <span class="meta-item">
                                                <i class="fas fa-exclamation-triangle"></i> ${anime.rating}
                                            </span>
                                        ` : ''}
                                    </div>
                                    ${anime.jname && anime.jname !== anime.name ? `
                                        <div class="list-desc">${anime.jname}</div>
                                    ` : ''}
                                </div>
                            </a>
                        `;
                    }).join('')}
                </div>
            `;
            
            container.innerHTML = html;
        }

        function renderPagination() {
            const pagination = document.getElementById('pagination');
            
            if (state.totalPages <= 1) {
                pagination.innerHTML = '';
                return;
            }
            
            let html = '';
            
            html += `<button class="page-btn" ${state.currentPage === 1 ? 'disabled' : ''} 
                     onclick="goToPage(${state.currentPage - 1})">
                        <i class="fas fa-chevron-left"></i>
                     </button>`;
            
            const maxVisible = 5;
            let start = Math.max(1, state.currentPage - 2);
            let end = Math.min(state.totalPages, start + maxVisible - 1);
            
            if (start > 1) {
                html += `<button class="page-btn" onclick="goToPage(1)">1</button>`;
                if (start > 2) html += `<span class="page-ellipsis">...</span>`;
            }
            
            for (let i = start; i <= end; i++) {
                html += `<button class="page-btn ${i === state.currentPage ? 'active' : ''}" 
                         onclick="goToPage(${i})">${i}</button>`;
            }
            
            if (end < state.totalPages) {
                if (end < state.totalPages - 1) html += `<span class="page-ellipsis">...</span>`;
                html += `<button class="page-btn" onclick="goToPage(${state.totalPages})">${state.totalPages}</button>`;
            }
            
            html += `<button class="page-btn" ${state.currentPage === state.totalPages ? 'disabled' : ''} 
                     onclick="goToPage(${state.currentPage + 1})">
                        <i class="fas fa-chevron-right"></i>
                     </button>`;
            
            pagination.innerHTML = html;
        }

        function goToPage(page) {
            if (page < 1 || page > state.totalPages || page === state.currentPage) return;
            
            state.currentPage = page;
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            if (state.searchQuery) {
                loadSearch(state.searchQuery);
            } else if (state.selectedGenre) {
                loadGenre(state.selectedGenre);
            } else if (state.selectedLetter !== 'all') {
                loadAZList(state.selectedLetter);
            } else {
                loadSection(state.currentSection);
            }
        }

        function updateResultsInfo() {
            const viewControls = document.getElementById('viewControls');
            const totalResults = document.getElementById('totalResults');
            
            if (state.animeData.length > 0) {
                viewControls.style.display = 'flex';
                totalResults.textContent = state.animeData.length * state.totalPages;
            } else {
                viewControls.style.display = 'none';
            }
        }

        function showLoading() {
            document.getElementById('contentContainer').innerHTML = `
                <div class="loading-state">
                    <div class="loader"></div>
                    <div class="loading-text">Loading anime...</div>
                </div>
            `;
        }

        function showEmpty() {
            document.getElementById('contentContainer').innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <div class="empty-title">No anime found</div>
                    <div class="empty-desc">Try adjusting your filters or search</div>
                </div>
            `;
            document.getElementById('viewControls').style.display = 'none';
        }

        function showError(message) {
            document.getElementById('contentContainer').innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="empty-title">Error</div>
                    <div class="empty-desc">${message}</div>
                </div>
            `;
            document.getElementById('viewControls').style.display = 'none';
        }

        const urlParams = new URLSearchParams(window.location.search);
const sectionParam = urlParams.get('section');

document.addEventListener('DOMContentLoaded', function() {
    initializeEventListeners();
    
    if (sectionParam) {
        const targetPill = document.querySelector(`.category-pill[data-section="${sectionParam}"]`);
        if (targetPill) {
            document.querySelectorAll('.category-pill').forEach(p => p.classList.remove('active'));
            targetPill.classList.add('active');
            loadSection(sectionParam);
        } else {
            loadSection('all');
        }
    } else {
        loadSection('all');
    }
});
    </script>


<?php require __DIR__ . '/modules/footer.php'; ?>