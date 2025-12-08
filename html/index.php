<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory</title>
    <!-- Preload critical resources for faster load -->
    <link rel="preconnect" href="https://rsms.me" crossorigin>
    <link rel="preconnect" href="https://pictures.dealer.com" crossorigin>
    <link rel="preload" href="inventory.php?lite=1&per_page=9999" as="fetch" crossorigin>
    <link href="https://rsms.me/inter/inter.css" rel="stylesheet">
    <style>
        :root { --bg: #f5f5f5; --text: #171a20; --muted: #5c5e62; --border: #e4e4e4; --accent: #3e6ae1; --accent-light: #e8f0fe; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; height: 100%; font-family: Inter, system-ui, sans-serif; background: var(--bg); color: var(--text); }
        body { display: flex; }
        .page { min-height: 100vh; width: 100%; display: flex; flex-direction: column; }
        .header { padding: 24px 48px 16px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: flex-end; }
        .header-title { font-size: 32px; font-weight: 500; cursor: pointer; transition: color 0.2s; }
        .header-title:hover { color: var(--accent); }
        .header-subtitle { font-size: 14px; color: var(--muted); margin-top: 4px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
        .header-right.hidden { display: none; }
        .results-count { font-size: 14px; color: var(--muted); }
        .results-count strong { color: var(--text); font-weight: 600; }
        .sort { font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 8px; }
        .sort-select { border: none; background: transparent; font: inherit; color: var(--text); padding: 4px 0; cursor: pointer; }
        .main { flex: 1; display: flex; padding: 24px 48px 48px; gap: 32px; align-items: flex-start; }
        .filters { width: 280px; flex-shrink: 0; padding-right: 8px; }
        .filters.hidden { display: none; }
        .filter-section { margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid var(--border); }
        .filter-section:last-child { border-bottom: none; }
        .filter-title { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }
        .filter-clear { font-size: 11px; color: var(--accent); cursor: pointer; text-transform: none; letter-spacing: normal; font-weight: 500; }
        .filter-clear:hover { text-decoration: underline; }
        .filter-option { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; font-size: 14px; cursor: pointer; }
        .filter-option input { accent-color: var(--accent); cursor: pointer; width: 16px; height: 16px; }
        .filter-option-label { flex: 1; }
        .filter-option-desc { font-size: 11px; color: var(--muted); display: block; }
        .filter-options-scroll { max-height: 200px; overflow-y: auto; }
        .price-range-inputs { display: flex; gap: 8px; align-items: center; padding: 4px 0; }
        .price-input { width: 80px; padding: 5px 8px; border: 1px solid var(--border); border-radius: 4px; font: inherit; font-size: 12px; }
        .price-input:focus { outline: none; border-color: var(--accent); }
        .price-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .price-input { -moz-appearance: textfield; }
        .active-filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .active-filter-tag { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: var(--accent-light); border-radius: 999px; font-size: 12px; color: var(--accent); }
        .active-filter-tag button { background: none; border: none; padding: 0; cursor: pointer; color: inherit; font-size: 14px; line-height: 1; }
        .clear-all-filters { font-size: 12px; color: var(--accent); cursor: pointer; padding: 4px 10px; }
        .clear-all-filters:hover { text-decoration: underline; }
        .inventory { flex: 1; min-width: 0; }
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; }
        .card { background: #fff; border-radius: 12px; padding: 16px 16px 20px; box-shadow: 0 8px 20px rgba(0,0,0,0.04); border: 1px solid #e7e7e7; display: flex; flex-direction: column; gap: 12px; cursor: pointer; transition: box-shadow 0.2s, transform 0.2s; }
        .card:hover { box-shadow: 0 12px 28px rgba(0,0,0,0.08); transform: translateY(-2px); }
        .card-image-placeholder { background: #f2f2f2; border-radius: 12px 12px 0 0; margin: -16px -16px 12px -16px; overflow: hidden; display: flex; align-items: center; justify-content: center; color: #b0b3b8; font-size: 13px; height: 200px; position: relative; }
        .in-transit-badge { position: absolute; top: 12px; left: 12px; background: var(--accent); color: #fff; font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.05em; box-shadow: 0 2px 4px rgba(0,0,0,0.15); }
        .card-image-placeholder img { display: block; width: 100%; height: 100%; object-fit: cover; object-position: bottom center; }
        .card-title { font-size: 16px; font-weight: 500; margin-bottom: 4px; }
        .card-subtitle { font-size: 13px; color: var(--muted); }
        .card-meta { font-size: 12px; color: var(--muted); display: flex; flex-direction: column; gap: 2px; }
        .card-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
        .tag { font-size: 11px; padding: 2px 8px; border-radius: 999px; background: #f5f5f5; border: 1px solid #e4e4e4; color: var(--muted); }
        .tag-equipment { background: var(--accent-light); border-color: #c5d9fc; color: var(--accent); }
        .card-footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 4px; }
        .price { font-size: 14px; font-weight: 500; }
        .action { font-size: 12px; color: var(--accent); cursor: pointer; }
        .action:hover { text-decoration: underline; }
        .loading, .no-results { text-align: center; padding: 48px; color: var(--muted); }
        /* Vehicle Detail View */
        .vehicle-detail { display: none; flex-direction: column; min-height: 100vh; }
        .vehicle-detail.active { display: flex; }
        .vehicle-detail .back-button { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; color: var(--muted); cursor: pointer; padding: 8px 0; margin-bottom: 8px; transition: color 0.2s; }
        .vehicle-detail .back-button:hover { color: var(--accent); }
        .vehicle-detail .back-button svg { width: 16px; height: 16px; }
        .detail-layout { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 32px; align-items: start; }
        .detail-media { position: sticky; top: 24px; min-width: 0; }
        .detail-hero { background: var(--bg); border-radius: 12px; overflow: hidden; position: relative; box-shadow: 0 4px 20px rgba(0,0,0,0.06); aspect-ratio: 4/3; max-height: calc(100vh - 160px); }
        .detail-hero img { width: 100%; height: 100%; object-fit: contain; object-position: center; transition: opacity 0.2s; display: block; }
        .detail-hero-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 40px; height: 40px; border-radius: 50%; border: none; background: rgba(255,255,255,0.9); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.15); transition: background 0.2s, transform 0.2s; font-size: 18px; z-index: 2; }
        .detail-hero-nav:hover { background: #fff; transform: translateY(-50%) scale(1.05); }
        .detail-hero-nav.prev { left: 16px; }
        .detail-hero-nav.next { right: 16px; }
        .detail-thumbnails { display: flex; gap: 8px; margin-top: 12px; overflow-x: auto; padding-bottom: 8px; max-width: 100%; }
        .detail-thumbnails::-webkit-scrollbar { height: 4px; }
        .detail-thumbnails::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
        .detail-thumb { width: 60px; height: 45px; border-radius: 6px; overflow: hidden; cursor: pointer; border: 2px solid transparent; transition: border-color 0.2s, opacity 0.2s; flex-shrink: 0; opacity: 0.6; }
        .detail-thumb:hover, .detail-thumb.active { opacity: 1; border-color: var(--accent); }
        .detail-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .detail-panel { padding: 0; max-height: calc(100vh - 80px); overflow-y: auto; }
        .detail-panel::-webkit-scrollbar { width: 4px; }
        .detail-panel::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
        .detail-title { font-size: clamp(20px, 2vw, 26px); font-weight: 600; margin-bottom: 4px; line-height: 1.2; }
        .detail-subtitle { font-size: 13px; color: var(--muted); margin-bottom: 12px; }
        .detail-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 20px; }
        .detail-tags .tag { font-size: 11px; padding: 4px 10px; }
        .detail-price-section { background: #fff; border-radius: 10px; padding: 16px; margin-bottom: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        .detail-price { font-size: clamp(24px, 2.5vw, 32px); font-weight: 600; margin-bottom: 2px; }
        .detail-price-note { font-size: 12px; color: var(--muted); }
        .detail-specs { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 16px; }
        .detail-spec { background: #fff; border-radius: 8px; padding: 10px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
        .detail-spec-label { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px; }
        .detail-spec-value { font-size: 12px; font-weight: 500; }
        .detail-cta { display: flex; gap: 10px; margin-bottom: 16px; }
        .detail-cta button { flex: 1; padding: 12px 16px; border-radius: 8px; border: none; font-size: 13px; font-weight: 500; cursor: pointer; transition: background 0.2s, transform 0.2s; }
        .detail-cta button:active { transform: scale(0.98); }
        .detail-cta .primary { background: var(--accent); color: #fff; }
        .detail-cta .primary:hover { background: #3458c7; }
        .detail-cta .secondary { background: #fff; color: var(--text); border: 1px solid var(--border); }
        .detail-cta .secondary:hover { background: #f8f8f8; }
        .detail-equipment { background: #fff; border-radius: 10px; padding: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        .detail-equipment-title { font-size: 13px; font-weight: 600; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
        .detail-equipment-title svg { width: 14px; height: 14px; transition: transform 0.2s; }
        .detail-equipment-title.collapsed svg { transform: rotate(-90deg); }
        .detail-equipment-list { display: flex; flex-direction: column; gap: 4px; max-height: 180px; overflow-y: auto; }
        .detail-equipment-list.collapsed { display: none; }
        .detail-equipment-item { font-size: 12px; color: var(--muted); padding: 3px 0; border-bottom: 1px solid #f0f0f0; }
        .detail-equipment-item:last-child { border-bottom: none; }
        @media (max-width: 1000px) {
            .detail-layout { grid-template-columns: 1fr; gap: 24px; }
            .detail-media { position: static; }
            .detail-hero { aspect-ratio: 16/9; max-height: 50vh; }
            .detail-panel { max-height: none; overflow-y: visible; }
            .detail-specs { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
        }
        @media (max-width: 600px) {
            .detail-hero { aspect-ratio: 4/3; max-height: 40vh; }
            .detail-specs { grid-template-columns: 1fr 1fr; }
            .detail-cta { flex-direction: column; }
        }
        /* Page transitions */
        .page-content { transition: opacity 0.15s ease-out; }
        .page-content.fade-out { opacity: 0; }
        .scroll-sentinel { height: 1px; grid-column: 1 / -1; }
        .load-more { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 32px; grid-column: 1 / -1; color: var(--muted); font-size: 14px; }
        .loading-spinner { display: inline-block; width: 20px; height: 20px; border: 2px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        /* Homepage model cards */
        .homepage-main { justify-content: center; }
        .homepage-main .inventory { max-width: 1400px; width: 100%; }
        .homepage-main .cards-grid { display: block; }
        .homepage-main .loading { display: flex; align-items: center; justify-content: center; gap: 12px; }
        .homepage-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; }
        .model-card { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e7e7e7; cursor: pointer; transition: box-shadow 0.3s, transform 0.3s; position: relative; }
        .model-card:hover, .model-card:focus { box-shadow: 0 12px 32px rgba(0,0,0,0.12); transform: translateY(-3px); outline: none; }
        .model-card-image { height: 140px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); overflow: hidden; position: relative; }
        .model-card-image img { width: 100%; height: 100%; object-fit: cover; object-position: center; transition: transform 0.4s; }
        .model-card:hover .model-card-image img { transform: scale(1.05); }
        .model-card-content { padding: 16px; text-align: center; }
        .model-card-title { font-size: 18px; font-weight: 600; margin-bottom: 4px; color: var(--text); }
        .model-card-count { font-size: 13px; color: var(--muted); }
        .model-card-hotkey { position: absolute; top: 8px; right: 8px; background: rgba(0,0,0,0.7); color: #fff; font-size: 11px; font-weight: 600; padding: 4px 8px; border-radius: 4px; font-family: monospace; text-transform: uppercase; }
        .model-card:hover .model-card-hotkey { background: var(--accent); }
        @media (max-width: 900px) {
            .header { padding: 16px; flex-direction: column; align-items: flex-start; gap: 12px; }
            .main { padding: 16px; flex-direction: column; }
            .main.homepage-main { padding: 24px 16px; }
            .filters { width: 100%; position: static; max-height: none; display: flex; gap: 16px; overflow-x: auto; padding-bottom: 16px; border-bottom: 1px solid var(--border); }
            .filter-section { min-width: 180px; flex-shrink: 0; margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
            .homepage-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .model-card-image { height: 100px; }
            .model-card-content { padding: 12px; }
            .model-card-title { font-size: 14px; }
            .model-card-count { font-size: 11px; }
            .model-card-hotkey { display: none; }
        }
    </style>
</head>
<body>
    <div class="page">
        <header class="header">
            <div>
                <div class="header-title" id="header-title">Inventory</div>
                <div class="header-subtitle" id="last-updated"></div>
            </div>
            <div class="header-right hidden" id="header-right">
                <div class="results-count" id="results-count"></div>
                <div class="sort"><span>Sort:</span>
                    <select class="sort-select" id="sort-select">
                        <option value="random">Random</option>
                        <option value="price_asc">Price: Low to High</option>
                        <option value="price_desc">Price: High to Low</option>
                        <option value="trim_asc">Trim Level</option>
                    </select>
                </div>
            </div>
        </header>
        <!-- Vehicle Detail View -->
        <div class="vehicle-detail" id="vehicle-detail">
            <div class="main page-content">
                <section class="inventory" style="max-width: 1400px; margin: 0 auto; width: 100%;">
                    <div class="back-button" id="detail-back">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                        Back to inventory
                    </div>
                    <div class="detail-layout">
                        <div class="detail-media">
                            <div class="detail-hero" id="detail-hero">
                                <img src="" alt="" id="detail-hero-img">
                                <button class="detail-hero-nav prev" id="detail-prev">‹</button>
                                <button class="detail-hero-nav next" id="detail-next">›</button>
                            </div>
                            <div class="detail-thumbnails" id="detail-thumbnails"></div>
                        </div>
                        <div class="detail-panel">
                            <div class="detail-title" id="detail-title">Loading...</div>
                            <div class="detail-subtitle" id="detail-subtitle"></div>
                            <div class="detail-tags" id="detail-tags"></div>
                            <div class="detail-price-section">
                                <div class="detail-price" id="detail-price"></div>
                                <div class="detail-price-note" id="detail-price-note">Dealer price</div>
                            </div>
                            <div class="detail-specs" id="detail-specs"></div>
                            <div class="detail-cta">
                                <button class="primary" id="detail-contact">Contact Dealer</button>
                                <button class="secondary" id="detail-sticker">Window Sticker</button>
                            </div>
                            <div class="detail-equipment" id="detail-equipment-section">
                                <div class="detail-equipment-title" id="detail-equipment-toggle">
                                    Optional Equipment
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                                </div>
                                <div class="detail-equipment-list" id="detail-equipment"></div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
        <!-- Main Inventory View -->
        <div class="main homepage-main page-content" id="main-content">
            <aside class="filters hidden" id="filters-sidebar">
                <div class="filter-section" id="model-section" style="display:none;">
                    <div class="filter-title">Model</div>
                    <label class="filter-option"><input type="radio" name="model" value="f150"><span>F-150</span></label>
                    <label class="filter-option"><input type="radio" name="model" value="super-duty"><span>Super Duty</span></label>
                    <label class="filter-option"><input type="radio" name="model" value="ranger"><span>Ranger</span></label>
                    <label class="filter-option"><input type="radio" name="model" value="maverick"><span>Maverick</span></label>
                    <label class="filter-option"><input type="radio" name="model" value="bronco"><span>Bronco</span></label>
                    <label class="filter-option"><input type="radio" name="model" value="bronco-sport"><span>Bronco Sport</span></label>
                    <label class="filter-option"><input type="radio" name="model" value="explorer"><span>Explorer</span></label>
                    <label class="filter-option"><input type="radio" name="model" value="expedition"><span>Expedition</span></label>
                    <label class="filter-option"><input type="radio" name="model" value="escape"><span>Escape</span></label>
                    <label class="filter-option"><input type="radio" name="model" value="mustang"><span>Mustang</span></label>
                    <label class="filter-option"><input type="radio" name="model" value="mustang-mach-e"><span>Mustang Mach-E</span></label>
                    <label class="filter-option"><input type="radio" name="model" value="f150-lightning"><span>F-150 Lightning</span></label>
                    <label class="filter-option"><input type="radio" name="model" value="transit"><span>Transit</span></label>
                </div>
                <div class="filter-section" id="year-section">
                    <div class="filter-title">Year <span class="filter-clear" id="clear-year" style="display:none;">Clear</span></div>
                    <div id="year-options"></div>
                </div>
                <div class="filter-section" id="trim-section" style="display:none;">
                    <div class="filter-title">Trim <span class="filter-clear" id="clear-trim">Clear</span></div>
                    <div id="trim-options"></div>
                </div>
                <div class="filter-section" id="engine-section" style="display:none;">
                    <div class="filter-title">Engine <span class="filter-clear" id="clear-engine">Clear</span></div>
                    <div id="engine-options"></div>
                </div>
                <div class="filter-section" id="drivetrain-section" style="display:none;">
                    <div class="filter-title">Drivetrain <span class="filter-clear" id="clear-drivetrain">Clear</span></div>
                    <div id="drivetrain-options"></div>
                </div>
                <div class="filter-section" id="body-style-section" style="display:none;">
                    <div class="filter-title">Cab Style <span class="filter-clear" id="clear-body-style">Clear</span></div>
                    <div id="body-style-options"></div>
                </div>
                <div class="filter-section" id="equipment-section" style="display:none;">
                    <div class="filter-title">Optional Equipment <span class="filter-clear" id="clear-equipment">Clear</span></div>
                    <div id="equipment-options" class="filter-options-scroll"></div>
                </div>
                <div class="filter-section" id="color-section" style="display:none;">
                    <div class="filter-title">Exterior Color <span class="filter-clear" id="clear-color">Clear</span></div>
                    <div id="color-options" class="filter-options-scroll"></div>
                </div>
                <div class="filter-section" id="price-section" style="display:none;">
                    <div class="filter-title">Price Range</div>
                    <div class="price-range-inputs">
                        <input type="number" class="price-input" id="price-min" placeholder="Min" step="1000">
                        <span style="color:var(--muted)">–</span>
                        <input type="number" class="price-input" id="price-max" placeholder="Max" step="1000">
                    </div>
                </div>
                <div class="filter-section" id="package-section" style="display:none;">
                    <div class="filter-title">Package <span class="filter-clear" id="clear-package">Clear</span></div>
                    <div id="package-options"></div>
                </div>
            </aside>
            <section class="inventory">
                <div class="active-filters" id="active-filters"></div>
                <div class="cards-grid" id="cards-grid"><div class="loading"><span class="loading-spinner"></span> Loading models...</div></div>
            </section>
        </div>
    </div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let vehicles = [];
    let filteredVehicles = [];
    let displayedCount = 0;
    const ITEMS_PER_PAGE = 24;
    let isHomepage = true; // Start on homepage
    let selectedFilters = { model: null, year: [], trim: [], package: [], engine: [], drivetrain: [], body_style: [], equipment: [], color: [], price_min: null, price_max: null };
    const grid = document.getElementById('cards-grid');
    const resultsCount = document.getElementById('results-count');
    const sortSelect = document.getElementById('sort-select');
    const activeFiltersContainer = document.getElementById('active-filters');
    const priceMin = document.getElementById('price-min');
    const priceMax = document.getElementById('price-max');
    const headerTitle = document.getElementById('header-title');
    const headerRight = document.getElementById('header-right');
    const filtersSidebar = document.getElementById('filters-sidebar');
    const mainContent = document.getElementById('main-content');

    // URL State Management
    function updateURL(replace = false) {
        const url = new URL(window.location);
        
        // Clear all existing params first
        url.search = '';
        
        // Only add params if not on homepage
        if (!isHomepage && selectedFilters.model) {
            url.searchParams.set('model', selectedFilters.model);
            
            // Add array filters (only if they have values)
            ['year', 'trim', 'package', 'engine', 'drivetrain', 'body_style', 'equipment', 'color'].forEach(key => {
                if (selectedFilters[key] && selectedFilters[key].length > 0) {
                    url.searchParams.set(key, selectedFilters[key].join(','));
                }
            });
            
            // Add price filters
            if (selectedFilters.price_min) url.searchParams.set('price_min', selectedFilters.price_min);
            if (selectedFilters.price_max) url.searchParams.set('price_max', selectedFilters.price_max);
        }
        
        // Use replaceState for filter changes, pushState for model changes
        if (replace) {
            history.replaceState({ filters: selectedFilters, isHomepage }, '', url);
        } else {
            history.pushState({ filters: selectedFilters, isHomepage }, '', url);
        }
    }
    
    function parseURLState() {
        const params = new URLSearchParams(window.location.search);
        const state = {
            model: params.get('model') || null,
            year: params.get('year') ? params.get('year').split(',') : [],
            trim: params.get('trim') ? params.get('trim').split(',') : [],
            package: params.get('package') ? params.get('package').split(',') : [],
            engine: params.get('engine') ? params.get('engine').split(',') : [],
            drivetrain: params.get('drivetrain') ? params.get('drivetrain').split(',') : [],
            body_style: params.get('body_style') ? params.get('body_style').split(',') : [],
            equipment: params.get('equipment') ? params.get('equipment').split(',') : [],
            color: params.get('color') ? params.get('color').split(',') : [],
            price_min: params.get('price_min') ? parseFloat(params.get('price_min')) : null,
            price_max: params.get('price_max') ? parseFloat(params.get('price_max')) : null
        };
        return state;
    }
    
    function restoreStateFromURL(pushHistory = false) {
        const urlState = parseURLState();
        
        if (urlState.model) {
            // Restore to inventory view with filters
            isHomepage = false;
            selectedFilters.model = urlState.model;
            selectedFilters.year = urlState.year;
            selectedFilters.trim = urlState.trim;
            selectedFilters.package = urlState.package;
            selectedFilters.engine = urlState.engine;
            selectedFilters.drivetrain = urlState.drivetrain;
            selectedFilters.body_style = urlState.body_style;
            selectedFilters.equipment = urlState.equipment;
            selectedFilters.color = urlState.color;
            selectedFilters.price_min = urlState.price_min;
            selectedFilters.price_max = urlState.price_max;
            
            // Update UI state
            headerRight.classList.remove('hidden');
            filtersSidebar.classList.remove('hidden');
            mainContent.classList.remove('homepage-main');
            
            // Check the corresponding radio button
            const radio = document.querySelector('input[name="model"][value="' + urlState.model + '"]');
            if (radio) radio.checked = true;
            
            // Update price inputs
            priceMin.value = urlState.price_min || '';
            priceMax.value = urlState.price_max || '';
            
            updateFilterVisibility();
            updateYearOptions();
            
            // Update year clear button visibility
            document.getElementById('clear-year').style.display = selectedFilters.year.length ? 'inline' : 'none';
            
            applyFiltersNoURLUpdate();
        } else {
            // Restore to homepage
            renderHomepageNoURLUpdate();
        }
    }

    // Model configuration for homepage with hotkeys
    const modelConfig = [
        { value: 'f150', label: 'F-150', displayName: 'F-150', hotkey: 'F' },
        { value: 'super-duty', label: 'Super Duty', displayName: 'Super Duty', hotkey: 'D' },
        { value: 'explorer', label: 'Explorer', displayName: 'Explorer', hotkey: 'E' },
        { value: 'maverick', label: 'Maverick', displayName: 'Maverick', hotkey: 'M' },
        { value: 'mustang-mach-e', label: 'Mach-E', displayName: 'Mustang Mach-E', hotkey: 'K' },
        { value: 'bronco', label: 'Bronco', displayName: 'Bronco', hotkey: 'B' },
        { value: 'bronco-sport', label: 'Bronco Sport', displayName: 'Bronco Sport', hotkey: 'S' },
        { value: 'mustang', label: 'Mustang', displayName: 'Mustang', hotkey: 'T' },
        { value: 'expedition', label: 'Expedition', displayName: 'Expedition', hotkey: 'X' },
        { value: 'ranger', label: 'Ranger', displayName: 'Ranger', hotkey: 'R' },
        { value: 'escape', label: 'Escape', displayName: 'Escape', hotkey: 'P' },
        { value: 'f150-lightning', label: 'F-150 Lightning', displayName: 'F-150 Lightning', hotkey: 'L' },
        { value: 'transit', label: 'Transit', displayName: 'Transit', hotkey: 'N' }
    ];

    // Function to render the homepage with model cards (internal, no URL update)
    function renderHomepageNoURLUpdate() {
        isHomepage = true;
        selectedFilters.model = null;
        // Clear all filters
        ['year', 'trim', 'package', 'engine', 'drivetrain', 'body_style', 'equipment', 'color'].forEach(k => selectedFilters[k] = []);
        selectedFilters.price_min = null;
        selectedFilters.price_max = null;
        priceMin.value = '';
        priceMax.value = '';
        
        // Update UI state
        headerRight.classList.add('hidden');
        filtersSidebar.classList.add('hidden');
        mainContent.classList.add('homepage-main');
        activeFiltersContainer.innerHTML = '';
        
        // Uncheck all model radio buttons
        document.querySelectorAll('input[name="model"]').forEach(r => r.checked = false);
        
        // Build homepage content
        grid.innerHTML = '';
        
        // Create model cards container
        const homepageGrid = document.createElement('div');
        homepageGrid.className = 'homepage-grid';
        
        modelConfig.forEach(model => {
            const modelVehicles = vehicles.filter(v => normalizeModel(v.model) === model.value);
            if (modelVehicles.length === 0) return; // Skip models with no inventory
            
            // Get a random vehicle from this model for the image
            const randomVehicle = modelVehicles[Math.floor(Math.random() * modelVehicles.length)];
            
            const card = document.createElement('div');
            card.className = 'model-card';
            card.tabIndex = 0;
            card.onclick = () => selectModel(model.value);
            card.onkeydown = (e) => { if (e.key === 'Enter') selectModel(model.value); };
            
            const imageDiv = document.createElement('div');
            imageDiv.className = 'model-card-image';
            if (randomVehicle.photo) {
                const img = document.createElement('img');
                img.src = randomVehicle.photo;
                img.alt = model.displayName;
                img.loading = 'lazy';
                imageDiv.appendChild(img);
            }
            
            // Add hotkey badge
            if (model.hotkey) {
                const hotkey = document.createElement('div');
                hotkey.className = 'model-card-hotkey';
                hotkey.textContent = model.hotkey;
                imageDiv.appendChild(hotkey);
            }
            
            const content = document.createElement('div');
            content.className = 'model-card-content';
            
            const title = document.createElement('div');
            title.className = 'model-card-title';
            title.textContent = model.displayName;
            
            const count = document.createElement('div');
            count.className = 'model-card-count';
            count.textContent = modelVehicles.length + ' vehicle' + (modelVehicles.length !== 1 ? 's' : '') + ' available';
            
            content.appendChild(title);
            content.appendChild(count);
            card.appendChild(imageDiv);
            card.appendChild(content);
            homepageGrid.appendChild(card);
        });
        
        grid.appendChild(homepageGrid);
    }
    
    // Function to render the homepage (with URL update)
    function renderHomepage() {
        renderHomepageNoURLUpdate();
        updateURL();
    }
    
    // Function to select a model and show inventory
    function selectModel(modelValue) {
        isHomepage = false;
        selectedFilters.model = modelValue;
        
        // Update UI state
        headerRight.classList.remove('hidden');
        filtersSidebar.classList.remove('hidden');
        mainContent.classList.remove('homepage-main');
        
        // Check the corresponding radio button
        const radio = document.querySelector('input[name="model"][value="' + modelValue + '"]');
        if (radio) radio.checked = true;
        
        // Clear other filters when switching models
        ['trim', 'package', 'engine', 'drivetrain', 'body_style', 'equipment', 'color'].forEach(k => selectedFilters[k] = []);
        selectedFilters.price_min = null; 
        selectedFilters.price_max = null; 
        priceMin.value = ''; 
        priceMax.value = '';
        
        updateFilterVisibility();
        applyFiltersNoURLUpdate();
        updateURL(); // Push new history entry for model selection
    }
    
    // Header title click handler to return to homepage
    headerTitle.onclick = () => {
        if (!isHomepage && vehicles.length > 0) {
            renderHomepage();
        }
    };
    
    // Keyboard hotkey handler for model selection
    document.addEventListener('keydown', (e) => {
        // Don't handle hotkeys when typing in an input
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
        
        const key = e.key.toUpperCase();
        
        // H to return to homepage from inventory view
        if (key === 'H' && !isHomepage && vehicles.length > 0) {
            renderHomepage();
            return;
        }
        
        // Model hotkeys only work on homepage
        if (!isHomepage) return;
        
        const model = modelConfig.find(m => m.hotkey === key);
        if (model) {
            // Check if this model has inventory
            const modelVehicles = vehicles.filter(v => normalizeModel(v.model) === model.value);
            if (modelVehicles.length > 0) {
                selectModel(model.value);
            }
        }
    });
    
    // Handle browser back/forward navigation
    window.addEventListener('popstate', (e) => {
        if (vehicles.length === 0) return; // Data not loaded yet
        restoreStateFromURL();
    });

    const f150Config = {
        trims: [
            { value: 'XL', label: 'XL' },
            { value: 'STX', label: 'STX' },
            { value: 'XLT', label: 'XLT' },
            { value: 'Lariat', label: 'Lariat' },
            { value: 'King Ranch', label: 'King Ranch' },
            { value: 'Platinum', label: 'Platinum' },
            { value: 'Tremor', label: 'Tremor' },
            { value: 'Raptor', label: 'Raptor' }
        ],
        packages: {
            'XL': [{ value: '101A', label: '101A' }, { value: '103A', label: '103A' }],
            'STX': [{ value: '200A', label: '200A' }, { value: '200B', label: '200B' }, { value: '201A', label: '201A' }],
            'XLT': [{ value: '300A', label: '300A' }, { value: '301A', label: '301A' }, { value: '302A', label: '302A' }, { value: '303A', label: '303A' }],
            'Lariat': [{ value: '500A', label: '500A' }, { value: '501A', label: '501A' }, { value: '502A', label: '502A' }],
            'King Ranch': [{ value: '601A', label: '601A' }],
            'Platinum': [{ value: '701A', label: '701A' }, { value: '702A', label: '702A' }, { value: '703A', label: '703A' }],
            'Tremor': [{ value: '401A', label: '401A' }, { value: '402A', label: '402A' }],
            'Raptor': [{ value: '801A', label: '801A' }, { value: '802A', label: '802A' }, { value: '803A', label: '803A' }]
        },
        engines: [
            { value: '2.7L', label: '2.7L EcoBoost V6' },
            { value: '3.5L V6 EcoBoost', label: '3.5L EcoBoost V6' },
            { value: 'PowerBoost', label: '3.5L PowerBoost Hybrid' },
            { value: '5.0L', label: '5.0L V8' },
            { value: 'High Output', label: '3.5L High-Output (Raptor)' }
        ],
        drivetrains: [{ value: '4x2', label: '4x2 (RWD)' }, { value: '4x4', label: '4x4 (4WD)' }],
        bodyStyles: [{ value: 'Regular Cab', label: 'Regular Cab' }, { value: 'Super Cab', label: 'Super Cab' }, { value: 'SuperCrew', label: 'SuperCrew' }],
        equipment: [
            { value: 'bedliner', label: 'Spray-In Bedliner', keywords: ['bedliner', 'tough bed', 'spray-in'] },
            { value: 'running_boards', label: 'Running Boards', keywords: ['running board', 'step bar'] },
            { value: 'moonroof', label: 'Moonroof', keywords: ['moonroof', 'sunroof', 'twin panel'] },
            { value: 'black_appearance', label: 'Black Appearance Pkg', keywords: ['black appearance'] },
            { value: 'fx4', label: 'FX4 Off-Road Pkg', keywords: ['fx4', 'off-road'] },
            { value: 'tow_pkg', label: 'Trailer Tow Pkg', keywords: ['trailer tow', 'max tow'] },
            { value: '360_camera', label: '360 Camera', keywords: ['360', 'camera'] },
            { value: 'bluecruise', label: 'BlueCruise', keywords: ['bluecruise'] },
            { value: 'tonneau', label: 'Tonneau Cover', keywords: ['tonneau'] },
            { value: 'pro_power', label: 'Pro Power Onboard', keywords: ['pro power'] }
        ],
        colors: [
            { value: 'Agate Black', label: 'Agate Black' }, { value: 'Antimatter Blue', label: 'Antimatter Blue' },
            { value: 'Carbonized Gray', label: 'Carbonized Gray' }, { value: 'Iconic Silver', label: 'Iconic Silver' },
            { value: 'Oxford White', label: 'Oxford White' }, { value: 'Rapid Red', label: 'Rapid Red' },
            { value: 'Star White', label: 'Star White' }, { value: 'Space White', label: 'Space White' },
            { value: 'Marsh Gray', label: 'Marsh Gray' }, { value: 'Atlas Blue', label: 'Atlas Blue' }
        ]
    };

    function getColorsWithInventory() {
        // Start with standard colors
        const colors = [...f150Config.colors];
        // Get F-150 vehicles from inventory
        const f150s = vehicles.filter(v => normalizeModel(v.model) === 'f150');
        // Collect unique exterior colors from inventory
        const inventoryColors = new Set();
        f150s.forEach(v => {
            if (v.exterior) inventoryColors.add(v.exterior.trim());
        });
        // Add any colors from inventory that don't match standard colors
        inventoryColors.forEach(invColor => {
            const invLower = invColor.toLowerCase();
            const isStandard = colors.some(c => invLower.includes(c.value.toLowerCase()) || c.value.toLowerCase().includes(invLower));
            if (!isStandard) {
                colors.push({ value: invColor, label: invColor });
            }
        });
        return colors;
    }

    function normalizeModel(model) {
        if (!model) return '';
        const m = String(model).toLowerCase();
        if (m.includes('f-150 lightning') || m.includes('f150 lightning')) return 'f150-lightning';
        if (m.includes('mustang mach-e') || m.includes('mach-e')) return 'mustang-mach-e';
        if (m.includes('bronco sport')) return 'bronco-sport';
        if (m.includes('f-150') || m.includes('f150')) return 'f150';
        if (m.includes('super duty') || m.includes('f-250') || m.includes('f-350') || m.includes('f-450')) return 'super-duty';
        if (m.includes('ranger')) return 'ranger';
        if (m.includes('maverick')) return 'maverick';
        if (m.includes('expedition')) return 'expedition';
        if (m.includes('escape')) return 'escape';
        if (m.includes('transit')) return 'transit';
        if (m.includes('bronco')) return 'bronco';
        if (m.includes('explorer')) return 'explorer';
        if (m.includes('mustang')) return 'mustang';
        return m.trim();
    }

    function formatMoney(value) {
        if (!value) return '';
        const num = parseFloat(String(value).replace(/[^0-9.]/g, ''));
        if (!isFinite(num)) return '';
        return num.toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
    }

    function getPrice(v) {
        const raw = v.retail_price || v.msrp || '';
        const num = parseFloat(String(raw).replace(/[^0-9.]/g, ''));
        return isFinite(num) ? num : Infinity;
    }

    function renderCheckboxOptions(container, options, filterKey, showDesc) {
        container.innerHTML = '';
        options.forEach(opt => {
            const label = document.createElement('label');
            label.className = 'filter-option';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.value = opt.value;
            input.checked = selectedFilters[filterKey].includes(opt.value);
            input.addEventListener('change', () => {
                if (input.checked) {
                    if (!selectedFilters[filterKey].includes(opt.value)) selectedFilters[filterKey].push(opt.value);
                } else {
                    selectedFilters[filterKey] = selectedFilters[filterKey].filter(v => v !== opt.value);
                }
                if (filterKey === 'trim') updateFilterVisibility();
                applyFilters();
            });
            const span = document.createElement('span');
            span.className = 'filter-option-label';
            span.textContent = opt.label;
            if (showDesc && opt.description) {
                const desc = document.createElement('span');
                desc.className = 'filter-option-desc';
                desc.textContent = opt.description;
                span.appendChild(desc);
            }
            label.appendChild(input);
            label.appendChild(span);
            container.appendChild(label);
        });
    }

    function updatePackageOptions() {
        let pkgs = [];
        if (selectedFilters.trim.length === 0) {
            Object.values(f150Config.packages).forEach(p => pkgs = pkgs.concat(p));
        } else {
            selectedFilters.trim.forEach(t => { if (f150Config.packages[t]) pkgs = pkgs.concat(f150Config.packages[t]); });
        }
        pkgs = pkgs.filter((p, i, arr) => arr.findIndex(x => x.value === p.value) === i);
        selectedFilters.package = selectedFilters.package.filter(p => pkgs.some(x => x.value === p));
        renderCheckboxOptions(document.getElementById('package-options'), pkgs, 'package');
    }

    function updateFilterVisibility() {
        // If on homepage, hide all filter sections
        if (isHomepage || selectedFilters.model === null) {
            ['trim', 'engine', 'drivetrain', 'body-style', 'equipment', 'color', 'price', 'package'].forEach(id => {
                document.getElementById(id + '-section').style.display = 'none';
            });
            return;
        }
        
        const isF150 = selectedFilters.model === 'f150';
        const hasTrimSelected = selectedFilters.trim.length > 0;
        // Show main filters when F-150 is selected
        ['trim', 'engine', 'drivetrain', 'body-style', 'equipment', 'color', 'price'].forEach(id => {
            document.getElementById(id + '-section').style.display = isF150 ? 'block' : 'none';
        });
        // Only show package filter when a trim is selected
        document.getElementById('package-section').style.display = (isF150 && hasTrimSelected) ? 'block' : 'none';
        if (isF150) {
            renderCheckboxOptions(document.getElementById('trim-options'), f150Config.trims, 'trim');
            updatePackageOptions();
            renderCheckboxOptions(document.getElementById('engine-options'), f150Config.engines, 'engine');
            renderCheckboxOptions(document.getElementById('drivetrain-options'), f150Config.drivetrains, 'drivetrain');
            renderCheckboxOptions(document.getElementById('body-style-options'), f150Config.bodyStyles, 'body_style');
            renderCheckboxOptions(document.getElementById('equipment-options'), f150Config.equipment, 'equipment');
            // Get all colors including any from inventory not in standard list
            const allColors = getColorsWithInventory();
            renderCheckboxOptions(document.getElementById('color-options'), allColors, 'color');
        }
    }

    function clearFilter(key) {
        selectedFilters[key] = [];
        if (key === 'trim') {
            selectedFilters.package = []; // Clear package when trim is cleared
        }
        updateFilterVisibility();
        applyFilters();
    }

    function renderActiveFilters() {
        activeFiltersContainer.innerHTML = '';
        
        // Add "Back to all models" link when viewing a specific model
        if (selectedFilters.model && !isHomepage) {
            const modelLabel = modelConfig.find(m => m.value === selectedFilters.model)?.displayName || selectedFilters.model;
            const backLink = document.createElement('span');
            backLink.className = 'active-filter-tag';
            backLink.innerHTML = 'Model: ' + modelLabel + ' <button>&times;</button>';
            backLink.querySelector('button').onclick = () => renderHomepage();
            activeFiltersContainer.appendChild(backLink);
        }
        
        const labels = { year: 'Year', trim: 'Trim', package: 'Pkg', engine: 'Engine', drivetrain: 'Drive', body_style: 'Cab', equipment: 'Equip', color: 'Color' };
        let hasFilters = false;
        Object.keys(labels).forEach(key => {
            selectedFilters[key].forEach(val => {
                hasFilters = true;
                const tag = document.createElement('span');
                tag.className = 'active-filter-tag';
                let label = val;
                if (key === 'equipment') { const found = f150Config.equipment.find(e => e.value === val); if (found) label = found.label; }
                tag.innerHTML = labels[key] + ': ' + label + ' <button>&times;</button>';
                tag.querySelector('button').onclick = () => { 
                    selectedFilters[key] = selectedFilters[key].filter(v => v !== val); 
                    if (key === 'year') { updateYearOptions(); document.getElementById('clear-year').style.display = selectedFilters.year.length ? 'inline' : 'none'; }
                    updateFilterVisibility(); 
                    applyFilters(); 
                };
                activeFiltersContainer.appendChild(tag);
            });
        });
        if (selectedFilters.price_min || selectedFilters.price_max) {
            hasFilters = true;
            const tag = document.createElement('span');
            tag.className = 'active-filter-tag';
            tag.innerHTML = 'Price: ' + (formatMoney(selectedFilters.price_min) || 'Any') + ' - ' + (formatMoney(selectedFilters.price_max) || 'Any') + ' <button>&times;</button>';
            tag.querySelector('button').onclick = () => { selectedFilters.price_min = null; selectedFilters.price_max = null; priceMin.value = ''; priceMax.value = ''; applyFilters(); };
            activeFiltersContainer.appendChild(tag);
        }
        if (hasFilters) {
            const clear = document.createElement('span');
            clear.className = 'clear-all-filters';
            clear.textContent = 'Clear all';
            clear.onclick = () => {
                ['trim', 'package', 'engine', 'drivetrain', 'body_style', 'equipment', 'color'].forEach(k => selectedFilters[k] = []);
                selectedFilters.price_min = null; selectedFilters.price_max = null; priceMin.value = ''; priceMax.value = '';
                updateFilterVisibility(); applyFilters();
            };
            activeFiltersContainer.appendChild(clear);
        }
    }

    function updateYearOptions() {
        const years = [...new Set(vehicles.map(v => v.year).filter(Boolean))].sort((a, b) => b - a);
        const container = document.getElementById('year-options');
        container.innerHTML = '';
        years.forEach(year => {
            const label = document.createElement('label');
            label.className = 'filter-option';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.value = year;
            input.checked = selectedFilters.year.includes(String(year));
            input.addEventListener('change', () => {
                const yearStr = String(year);
                if (input.checked) {
                    if (!selectedFilters.year.includes(yearStr)) selectedFilters.year.push(yearStr);
                } else {
                    selectedFilters.year = selectedFilters.year.filter(y => y !== yearStr);
                }
                document.getElementById('clear-year').style.display = selectedFilters.year.length ? 'inline' : 'none';
                applyFilters();
            });
            const span = document.createElement('span');
            span.textContent = year;
            label.appendChild(input);
            label.appendChild(span);
            container.appendChild(label);
        });
    }

    // Internal filter application (no URL update)
    function applyFiltersNoURLUpdate() {
        // If on homepage, don't filter - just show homepage
        if (isHomepage || selectedFilters.model === null) {
            renderHomepageNoURLUpdate();
            return;
        }
        
        filteredVehicles = vehicles.filter(v => {
            if (selectedFilters.year.length && !selectedFilters.year.includes(String(v.year))) return false;
            if (normalizeModel(v.model) !== selectedFilters.model) return false;
            if (selectedFilters.model === 'f150') {
                if (selectedFilters.trim.length && !selectedFilters.trim.some(t => (v.trim || '').toLowerCase().includes(t.toLowerCase()))) return false;
                if (selectedFilters.package.length && !selectedFilters.package.includes(v.equipment_pkg)) return false;
                if (selectedFilters.engine.length && !selectedFilters.engine.some(e => (v.engine || '').toLowerCase().includes(e.toLowerCase()))) return false;
                if (selectedFilters.drivetrain.length && !selectedFilters.drivetrain.some(d => (v.drive_line || '').toLowerCase().includes(d.toLowerCase()))) return false;
                if (selectedFilters.body_style.length) {
                    const vBodyNorm = (v.body_style || '').toLowerCase().replace(/\s+/g, '');
                    if (!selectedFilters.body_style.some(b => {
                        const bNorm = b.toLowerCase().replace(/\s+/g, '');
                        return vBodyNorm.includes(bNorm) || bNorm.includes(vBodyNorm);
                    })) return false;
                }
                if (selectedFilters.color.length && !selectedFilters.color.some(c => (v.exterior || '').toLowerCase().includes(c.toLowerCase()))) return false;
                if (selectedFilters.equipment.length) {
                    const optText = JSON.stringify(v.optional_equipment || []).toLowerCase();
                    if (!selectedFilters.equipment.every(eq => { const cfg = f150Config.equipment.find(e => e.value === eq); return cfg && cfg.keywords.some(kw => optText.includes(kw.toLowerCase())); })) return false;
                }
                const price = getPrice(v);
                if (selectedFilters.price_min && price < selectedFilters.price_min) return false;
                if (selectedFilters.price_max && price > selectedFilters.price_max) return false;
            }
            return true;
        });
        const sort = sortSelect.value;
        if (sort === 'random') {
            // Fisher-Yates shuffle for random order
            for (let i = filteredVehicles.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [filteredVehicles[i], filteredVehicles[j]] = [filteredVehicles[j], filteredVehicles[i]];
            }
        } else {
            filteredVehicles.sort((a, b) => {
                if (sort === 'price_asc') return getPrice(a) - getPrice(b);
                if (sort === 'price_desc') return getPrice(b) - getPrice(a);
                if (sort === 'trim_asc') return (a.trim || '').localeCompare(b.trim || '');
                return 0;
            });
        }
        resultsCount.innerHTML = '<strong>' + filteredVehicles.length + '</strong> vehicles';
        displayedCount = 0;
        renderCards(true);
        renderActiveFilters();
    }
    
    // Apply filters with URL update (replaceState for filter changes)
    function applyFilters() {
        applyFiltersNoURLUpdate();
        // Use replaceState for filter changes to avoid cluttering history
        if (!isHomepage && selectedFilters.model) {
            updateURL(true);
        }
    }

    function loadMore() {
        renderCards(false);
    }

    function createCard(v) {
        const card = document.createElement('article');
        card.className = 'card';
        const img = document.createElement('div');
        img.className = 'card-image-placeholder';
        if (v.photo) {
            const imgEl = document.createElement('img');
            imgEl.src = v.photo;
            imgEl.alt = [v.year, v.model].filter(Boolean).join(' ');
            imgEl.loading = 'lazy';
            img.appendChild(imgEl);
            if (v.photo_interior && v.photo_interior !== v.photo) {
                img.onmouseenter = () => imgEl.src = v.photo_interior;
                img.onmouseleave = () => imgEl.src = v.photo;
            }
        } else { img.textContent = 'No Image'; }
        if (!v.stock) {
            const badge = document.createElement('span');
            badge.className = 'in-transit-badge';
            badge.textContent = 'In Transit';
            img.appendChild(badge);
        }
        const titleDiv = document.createElement('div');
        const title = document.createElement('div');
        title.className = 'card-title';
        title.textContent = [v.year, v.model, v.trim].filter(Boolean).join(' ');
        const sub = document.createElement('div');
        sub.className = 'card-subtitle';
        sub.textContent = [(v.condition || 'New'), v.stock, v.equipment_pkg].filter(Boolean).join(' · ');
        titleDiv.appendChild(title);
        titleDiv.appendChild(sub);
        const meta = document.createElement('div');
        meta.className = 'card-meta';
        const tags = document.createElement('div');
        tags.className = 'card-tags';
        // Build interior tag from interior_color + interior_material
        const interiorTag = [v.interior_color, v.interior_material].filter(Boolean).join(' ');
        [v.exterior, v.engine, v.drive_line, v.body_style, interiorTag].filter(Boolean).forEach(t => { const tag = document.createElement('span'); tag.className = 'tag'; tag.textContent = t; tags.appendChild(tag); });
        if (selectedFilters.equipment.length && v.optional_equipment) {
            const optText = JSON.stringify(v.optional_equipment).toLowerCase();
            selectedFilters.equipment.forEach(eq => {
                const cfg = f150Config.equipment.find(e => e.value === eq);
                if (cfg && cfg.keywords.some(kw => optText.includes(kw.toLowerCase()))) {
                    const tag = document.createElement('span');
                    tag.className = 'tag tag-equipment';
                    tag.textContent = cfg.label;
                    tags.appendChild(tag);
                }
            });
        }
        meta.appendChild(tags);
        const footer = document.createElement('div');
        footer.className = 'card-footer';
        const price = document.createElement('div');
        price.className = 'price';
        price.textContent = formatMoney(v.retail_price || v.msrp) || 'Contact for price';
        const action = document.createElement('div');
        action.className = 'action';
        action.textContent = 'View details';
        footer.appendChild(price);
        footer.appendChild(action);
        card.appendChild(img);
        card.appendChild(titleDiv);
        card.appendChild(meta);
        card.appendChild(footer);
        // SPA navigation - pass cached vehicle data for instant render
        if (v.stock) card.onclick = () => navigateToVehicle(v.stock, v);
        return card;
    }

    // ==================== VEHICLE DETAIL VIEW ====================
    const vehicleDetail = document.getElementById('vehicle-detail');
    const detailHeroImg = document.getElementById('detail-hero-img');
    const detailThumbnails = document.getElementById('detail-thumbnails');
    const detailTitle = document.getElementById('detail-title');
    const detailSubtitle = document.getElementById('detail-subtitle');
    const detailTags = document.getElementById('detail-tags');
    const detailPrice = document.getElementById('detail-price');
    const detailPriceNote = document.getElementById('detail-price-note');
    const detailSpecs = document.getElementById('detail-specs');
    const detailEquipment = document.getElementById('detail-equipment');
    const detailBack = document.getElementById('detail-back');
    const detailContact = document.getElementById('detail-contact');
    const detailSticker = document.getElementById('detail-sticker');
    const detailPrev = document.getElementById('detail-prev');
    const detailNext = document.getElementById('detail-next');
    const detailEquipmentToggle = document.getElementById('detail-equipment-toggle');
    const detailEquipmentSection = document.getElementById('detail-equipment-section');
    
    let currentDetailPhotos = [];
    let currentDetailPhotoIndex = 0;
    let currentVehicleData = null;
    let previousScrollPosition = 0;

    function showDetailPhoto(index) {
        if (!currentDetailPhotos.length) return;
        currentDetailPhotoIndex = (index + currentDetailPhotos.length) % currentDetailPhotos.length;
        detailHeroImg.src = currentDetailPhotos[currentDetailPhotoIndex];
        // Update thumbnail active state
        detailThumbnails.querySelectorAll('.detail-thumb').forEach((thumb, i) => {
            thumb.classList.toggle('active', i === currentDetailPhotoIndex);
        });
    }

    detailPrev.onclick = () => showDetailPhoto(currentDetailPhotoIndex - 1);
    detailNext.onclick = () => showDetailPhoto(currentDetailPhotoIndex + 1);
    
    // Keyboard navigation for photos
    document.addEventListener('keydown', (e) => {
        if (!vehicleDetail.classList.contains('active')) return;
        if (e.key === 'ArrowLeft') showDetailPhoto(currentDetailPhotoIndex - 1);
        if (e.key === 'ArrowRight') showDetailPhoto(currentDetailPhotoIndex + 1);
        if (e.key === 'Escape') navigateBack();
    });

    // Equipment toggle
    detailEquipmentToggle.onclick = () => {
        detailEquipmentToggle.classList.toggle('collapsed');
        detailEquipment.classList.toggle('collapsed');
    };

    function renderVehicleDetail(v) {
        currentVehicleData = v;
        
        // Title
        detailTitle.textContent = [v.year, v.model, v.trim].filter(Boolean).join(' ');
        detailSubtitle.textContent = [v.stock ? 'Stock #' + v.stock : '', v.vin].filter(Boolean).join(' · ');
        
        // Tags
        detailTags.innerHTML = '';
        const tagValues = [v.exterior, v.engine, v.drive_line, v.body_style, v.transmission, v.fuel_type].filter(Boolean);
        tagValues.forEach(t => {
            const tag = document.createElement('span');
            tag.className = 'tag';
            tag.textContent = t;
            detailTags.appendChild(tag);
        });
        
        // Price
        const priceVal = v.retail_price || v.msrp;
        detailPrice.textContent = formatMoney(priceVal) || 'Contact for price';
        if (v.msrp && v.retail_price && v.msrp !== v.retail_price) {
            detailPriceNote.textContent = 'MSRP ' + formatMoney(v.msrp);
        } else {
            detailPriceNote.textContent = 'Dealer price';
        }
        
        // Photos
        currentDetailPhotos = [];
        if (Array.isArray(v.photo_urls) && v.photo_urls.length) {
            currentDetailPhotos = v.photo_urls;
        } else if (v.photo) {
            currentDetailPhotos = [v.photo];
            if (v.photo_interior && v.photo_interior !== v.photo) {
                currentDetailPhotos.push(v.photo_interior);
            }
        }
        
        // Show/hide nav arrows
        const showArrows = currentDetailPhotos.length > 1;
        detailPrev.style.display = showArrows ? 'flex' : 'none';
        detailNext.style.display = showArrows ? 'flex' : 'none';
        
        // Thumbnails
        detailThumbnails.innerHTML = '';
        if (currentDetailPhotos.length > 1) {
            currentDetailPhotos.forEach((url, i) => {
                const thumb = document.createElement('div');
                thumb.className = 'detail-thumb' + (i === 0 ? ' active' : '');
                const img = document.createElement('img');
                img.src = url;
                img.alt = 'Photo ' + (i + 1);
                img.loading = 'lazy';
                thumb.appendChild(img);
                thumb.onclick = () => showDetailPhoto(i);
                detailThumbnails.appendChild(thumb);
            });
        }
        
        if (currentDetailPhotos.length) {
            showDetailPhoto(0);
        } else {
            detailHeroImg.src = '';
            detailHeroImg.alt = 'No image available';
        }
        
        // Specs grid
        detailSpecs.innerHTML = '';
        const specs = [
            ['Drivetrain', v.drive_line],
            ['Engine', v.engine],
            ['Transmission', v.transmission],
            ['Fuel Type', v.fuel_type],
            ['Fuel Economy', v.fuel_economy],
            ['Exterior', v.exterior],
            ['Interior', [v.interior_color, v.interior_material].filter(Boolean).join(' ')],
            ['Body Style', v.body_style],
            ['Package', v.equipment_pkg],
            ['Bed Length', v.bed_length],
            ['Towing Capacity', v.towing_capacity],
            ['Payload', v.payload_capacity],
            ['Cargo Volume', v.cargo_volume],
            ['Ground Clearance', v.ground_clearance],
            ['Horsepower', v.horsepower],
            ['Torque', v.torque],
        ];
        specs.forEach(([label, value]) => {
            if (!value) return;
            const spec = document.createElement('div');
            spec.className = 'detail-spec';
            spec.innerHTML = '<div class="detail-spec-label">' + label + '</div><div class="detail-spec-value">' + value + '</div>';
            detailSpecs.appendChild(spec);
        });
        
        // Optional equipment
        const equipment = v.optional_equipment || [];
        if (equipment.length) {
            detailEquipmentSection.style.display = 'block';
            detailEquipment.innerHTML = '';
            equipment.forEach(item => {
                const div = document.createElement('div');
                div.className = 'detail-equipment-item';
                div.textContent = typeof item === 'string' ? item : (item.name || item.description || JSON.stringify(item));
                detailEquipment.appendChild(div);
            });
        } else {
            detailEquipmentSection.style.display = 'none';
        }
        
        // CTA buttons
        detailContact.onclick = () => {
            if (v.vehicle_link) window.open(v.vehicle_link, '_blank');
        };
        detailSticker.onclick = () => {
            // Ford window sticker URL pattern
            if (v.vin) window.open('https://www.windowsticker.forddirect.com/windowsticker.pdf?vin=' + v.vin, '_blank');
        };
    }

    function showVehicleDetail() {
        previousScrollPosition = window.scrollY;
        vehicleDetail.classList.add('active');
        mainContent.style.display = 'none';
        document.querySelector('.header').style.display = 'none';
        window.scrollTo(0, 0);
    }

    function hideVehicleDetail() {
        vehicleDetail.classList.remove('active');
        mainContent.style.display = 'flex';
        document.querySelector('.header').style.display = 'flex';
        window.scrollTo(0, previousScrollPosition);
    }

    function navigateToVehicle(stock, cachedData = null) {
        // Update URL
        history.pushState({ view: 'vehicle', stock: stock }, '', '/' + stock);
        
        // Show detail view
        showVehicleDetail();
        
        // If we have cached data from the list, render immediately
        if (cachedData) {
            renderVehicleDetail(cachedData);
            // Also fetch full data in background for complete info
            fetch('inventory.php?stock=' + encodeURIComponent(stock))
                .then(r => r.json())
                .then(data => {
                    if (data.vehicle) renderVehicleDetail(data.vehicle);
                })
                .catch(e => console.warn('Background fetch failed', e));
        } else {
            // Fetch vehicle data
            detailTitle.textContent = 'Loading...';
            fetch('inventory.php?stock=' + encodeURIComponent(stock))
                .then(r => r.json())
                .then(data => {
                    if (data.vehicle) {
                        renderVehicleDetail(data.vehicle);
                    } else {
                        detailTitle.textContent = 'Vehicle not found';
                        detailSubtitle.textContent = 'This vehicle may no longer be available.';
                    }
                })
                .catch(e => {
                    console.error('Failed to load vehicle', e);
                    detailTitle.textContent = 'Error loading vehicle';
                    detailSubtitle.textContent = 'Please try again.';
                });
        }
    }

    function navigateBack() {
        // Go back to previous inventory state
        hideVehicleDetail();
        
        // Update URL to inventory
        const url = new URL(window.location);
        url.pathname = '/';
        if (selectedFilters.model) {
            url.searchParams.set('model', selectedFilters.model);
        }
        history.pushState({ view: 'inventory' }, '', url);
    }

    detailBack.onclick = navigateBack;

    // Handle browser back/forward
    window.addEventListener('popstate', (e) => {
        const path = window.location.pathname;
        
        if (path === '/' || path === '/index.php' || path === '') {
            // Back to inventory
            hideVehicleDetail();
            restoreStateFromURL();
        } else if (path.length > 1) {
            // Vehicle detail - extract stock from path
            const stock = path.substring(1);
            if (stock && !stock.includes('.')) {
                showVehicleDetail();
                // Try to find in cached vehicles first
                const cached = vehicles.find(v => v.stock === stock);
                if (cached) {
                    renderVehicleDetail(cached);
                }
                // Fetch full data
                fetch('inventory.php?stock=' + encodeURIComponent(stock))
                    .then(r => r.json())
                    .then(data => {
                        if (data.vehicle) renderVehicleDetail(data.vehicle);
                    })
                    .catch(e => console.error('Failed to load vehicle', e));
            }
        }
    });

    // Check initial URL for direct vehicle link
    function checkInitialRoute() {
        const path = window.location.pathname;
        if (path.length > 1 && !path.includes('.')) {
            const stock = path.substring(1);
            // This looks like a vehicle stock number
            navigateToVehicle(stock);
            return true;
        }
        return false;
    }

    // Infinite scroll observer
    let scrollObserver = null;
    let isLoading = false;
    
    function setupInfiniteScroll() {
        if (scrollObserver) scrollObserver.disconnect();
        
        scrollObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !isLoading && displayedCount < filteredVehicles.length) {
                    loadMoreCards();
                }
            });
        }, { rootMargin: '200px' }); // Start loading 200px before reaching the sentinel
        
        const sentinel = grid.querySelector('.scroll-sentinel');
        if (sentinel) scrollObserver.observe(sentinel);
    }
    
    function loadMoreCards() {
        if (isLoading || displayedCount >= filteredVehicles.length) return;
        isLoading = true;
        
        // Show loading indicator
        const loadingDiv = grid.querySelector('.load-more');
        if (loadingDiv) {
            loadingDiv.innerHTML = '<span class="loading-spinner"></span> Loading more...';
        }
        
        // Small delay for smooth UX
        setTimeout(() => {
            const sentinel = grid.querySelector('.scroll-sentinel');
            const loadMore = grid.querySelector('.load-more');
            if (sentinel) sentinel.remove();
            if (loadMore) loadMore.remove();
            
            const nextBatch = filteredVehicles.slice(displayedCount, displayedCount + ITEMS_PER_PAGE);
            nextBatch.forEach(v => grid.appendChild(createCard(v)));
            displayedCount += nextBatch.length;
            
            // Add sentinel and loading indicator if more items
            if (displayedCount < filteredVehicles.length) {
                const remaining = filteredVehicles.length - displayedCount;
                const loadMoreDiv = document.createElement('div');
                loadMoreDiv.className = 'load-more';
                loadMoreDiv.innerHTML = '<span class="loading-spinner"></span> ' + remaining + ' more vehicles...';
                grid.appendChild(loadMoreDiv);
                
                const newSentinel = document.createElement('div');
                newSentinel.className = 'scroll-sentinel';
                grid.appendChild(newSentinel);
                scrollObserver.observe(newSentinel);
            }
            
            isLoading = false;
        }, 100);
    }

    function renderCards(reset) {
        if (reset) {
            grid.innerHTML = '';
            displayedCount = 0;
            isLoading = false;
        }
        
        if (!filteredVehicles.length) { 
            grid.innerHTML = '<div class="no-results">No vehicles match your filters.</div>'; 
            return; 
        }
        
        // Add first batch of cards
        const nextBatch = filteredVehicles.slice(displayedCount, displayedCount + ITEMS_PER_PAGE);
        nextBatch.forEach(v => grid.appendChild(createCard(v)));
        displayedCount += nextBatch.length;
        
        // Add sentinel and loading indicator for infinite scroll
        if (displayedCount < filteredVehicles.length) {
            const remaining = filteredVehicles.length - displayedCount;
            const loadMoreDiv = document.createElement('div');
            loadMoreDiv.className = 'load-more';
            loadMoreDiv.innerHTML = '<span class="loading-spinner"></span> ' + remaining + ' more vehicles...';
            grid.appendChild(loadMoreDiv);
            
            const sentinel = document.createElement('div');
            sentinel.className = 'scroll-sentinel';
            grid.appendChild(sentinel);
            
            setupInfiniteScroll();
        }
    }

    document.querySelectorAll('input[name="model"]').forEach(r => {
        r.onchange = e => {
            selectModel(e.target.value);
        };
    });

    sortSelect.onchange = applyFilters;
    let priceTimeout;
    priceMin.oninput = () => { clearTimeout(priceTimeout); priceTimeout = setTimeout(() => { selectedFilters.price_min = priceMin.value ? parseFloat(priceMin.value) : null; applyFilters(); }, 500); };
    priceMax.oninput = () => { clearTimeout(priceTimeout); priceTimeout = setTimeout(() => { selectedFilters.price_max = priceMax.value ? parseFloat(priceMax.value) : null; applyFilters(); }, 500); };

    document.getElementById('clear-year').onclick = () => { clearFilter('year'); updateYearOptions(); document.getElementById('clear-year').style.display = 'none'; };
    document.getElementById('clear-trim').onclick = () => clearFilter('trim');
    document.getElementById('clear-package').onclick = () => clearFilter('package');
    document.getElementById('clear-engine').onclick = () => clearFilter('engine');
    document.getElementById('clear-drivetrain').onclick = () => clearFilter('drivetrain');
    document.getElementById('clear-body-style').onclick = () => clearFilter('body_style');
    document.getElementById('clear-equipment').onclick = () => clearFilter('equipment');
    document.getElementById('clear-color').onclick = () => clearFilter('color');

    // Load all vehicles for client-side filtering, then restore state from URL or show homepage
    fetch('inventory.php?lite=1&per_page=9999')
        .then(r => r.json())
        .then(data => {
            vehicles = data.vehicles || [];
            const upd = document.getElementById('last-updated');
            if (upd && data.lastUpdated) upd.textContent = 'Updated ' + data.lastUpdated;
            updateYearOptions();
            
            // Check if we're on a direct vehicle link first
            if (!checkInitialRoute()) {
                // Not a vehicle link, check for inventory filters or show homepage
                const urlState = parseURLState();
                if (urlState.model) {
                    restoreStateFromURL();
                } else {
                    renderHomepageNoURLUpdate(); // Don't push to history on initial load
                }
            }
        })
        .catch(e => { console.error(e); grid.innerHTML = '<div class="no-results">Unable to load inventory.</div>'; });
});
</script>
</body>
</html>