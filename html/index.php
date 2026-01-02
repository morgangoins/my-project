<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory</title>
    <meta name="description" content="Morgan Goins shares Fairfield Ford inventory, selling F-150 trucks across Northern California while learning to code and documenting each build update.">
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
        .stock-search { margin-top: 10px; display: flex; gap: 8px; align-items: center; }
        .stock-search input { width: 240px; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; font: inherit; font-size: 14px; }
        .stock-search input:focus { outline: none; border-color: var(--accent); box-shadow: 0 4px 10px rgba(0,0,0,0.06); }
        .qr-scan-button { width: 36px; height: 36px; border: 1px solid var(--border); border-radius: 8px; background: white; cursor: pointer; display: none; align-items: center; justify-content: center; font-size: 16px; transition: all 0.2s; }
        .qr-scan-button:hover { border-color: var(--accent); background: var(--accent-light, rgba(59, 130, 246, 0.05)); }
        .qr-scan-button:active { transform: scale(0.95); }
        .stock-search-hint { font-size: 12px; color: var(--muted); margin-top: 4px; min-height: 14px; }
        .header-right { display: flex; align-items: center; gap: 16px; }
        .header-right.hidden { display: none; }
        .bookmarks-button {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: white;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: border-color 0.2s, transform 0.2s, background 0.2s;
            padding: 0;
            position: relative;
        }
        .bookmarks-button svg {
            width: 22px;
            height: 22px;
            stroke-width: 1.6;
            stroke-linejoin: round;
            stroke-linecap: round;
        }
        .bookmarks-button:hover {
            border-color: var(--accent);
            transform: translateY(-1px);
        }
        .bookmarks-button.active,
        .bookmarks-button.has-count {
            border-color: var(--accent);
        }
        .bookmarks-button .bookmark-count {
            position: absolute;
            top: -4px;
            right: -4px;
            min-width: 18px;
            height: 18px;
            border-radius: 999px;
            background: #ef4444;
            color: #fff;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
            box-shadow: 0 0 0 2px white;
        }
        .bookmarks-clear-button {
            margin-left: 12px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: white;
            padding: 8px 16px;
            font-size: 14px;
            color: var(--text);
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }
        .bookmarks-clear-button:hover {
            border-color: var(--accent);
        }
        .bookmarks-clear-button.hidden {
            display: none;
        }
        .view-toggle-button {
            border: 1px solid var(--border);
            border-radius: 999px;
            background: white;
            padding: 8px 14px;
            font-size: 14px;
            color: var(--text);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: border-color 0.2s, background 0.2s, transform 0.2s;
            user-select: none;
        }
        .view-toggle-button:hover {
            border-color: var(--accent);
            transform: translateY(-1px);
        }
        .view-toggle-button.active {
            border-color: var(--accent);
            background: var(--accent-light);
        }
        .view-toggle-button:active {
            transform: translateY(0);
        }
        #view-toggle-icon {
            width: 18px;
            height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            background: #f3f4f6;
            color: #374151;
            letter-spacing: 0.02em;
        }
        .view-toggle-button.active #view-toggle-icon {
            background: rgba(62, 106, 225, 0.15);
            color: var(--accent);
        }
        .view-toggle-button.hidden {
            display: none;
        }
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
        .filter-option-excluded .filter-option-label { text-decoration: line-through; opacity: 0.6; }
        .filter-option-excluded .filter-option-count { text-decoration: none; }
        .filter-option-count { font-size: 12px; color: var(--muted); margin-left: 4px; }
        .filter-option-desc { font-size: 11px; color: var(--muted); display: block; }
        .filter-options-scroll { max-height: 200px; overflow-y: auto; }
        .price-range-inputs { display: flex; gap: 8px; align-items: center; padding: 4px 0; }
        .price-input { width: 80px; padding: 5px 8px; border: 1px solid var(--border); border-radius: 4px; font: inherit; font-size: 12px; }
        .price-input:focus { outline: none; border-color: var(--accent); }
        .price-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .price-input { -moz-appearance: textfield; }
        .active-filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .active-filter-tag { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: var(--accent-light); border: 1px solid #c7ddff; border-radius: 999px; font-size: 12px; color: var(--accent); }
        .active-filter-tag button { background: none; border: none; padding: 0; cursor: pointer; color: inherit; font-size: 14px; line-height: 1; }
        .exclude-filter-tag { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .clear-all-filters { font-size: 12px; color: var(--accent); cursor: pointer; padding: 4px 10px; }
        .clear-all-filters:hover { text-decoration: underline; }
        .inventory { flex: 1; min-width: 0; }
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; }
        .card { background: #fff; border-radius: 12px; padding: 16px 16px 20px; box-shadow: 0 8px 20px rgba(0,0,0,0.04); border: 1px solid #e7e7e7; display: flex; flex-direction: column; gap: 12px; cursor: pointer; transition: box-shadow 0.2s, transform 0.2s; }
        .card:hover { box-shadow: 0 12px 28px rgba(0,0,0,0.08); transform: translateY(-2px); }
        .card-image-placeholder { background: #f2f2f2; border-radius: 12px 12px 0 0; margin: -16px -16px 12px -16px; overflow: hidden; display: flex; align-items: center; justify-content: center; color: #b0b3b8; font-size: 13px; height: 200px; position: relative; }
        .in-transit-badge { position: absolute; top: 12px; left: 12px; background: var(--accent); color: #fff; font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.05em; box-shadow: 0 2px 4px rgba(0,0,0,0.15); }
        .card-image-placeholder img { display: block; width: 100%; height: 100%; object-fit: cover; object-position: bottom center; transition: opacity 0.3s ease; position: absolute; top: 0; left: 0; }
        .card-image-placeholder img.interior { opacity: 0; }
        .card-image-placeholder:hover img.exterior { opacity: 0; }
        .card-image-placeholder:hover img.interior { opacity: 1; }
        .bookmark-button { position: absolute; top: 12px; right: 12px; width: 32px; height: 32px; border: none; border-radius: 50%; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(8px); color: #666; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; font-size: 16px; z-index: 3; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .bookmark-button:hover { background: rgba(255, 255, 255, 1); color: #333; transform: scale(1.1); }
        .bookmark-button.bookmarked { color: #e11d48; }
        .bookmark-button.bookmarked:hover { color: #dc2626; }
        .card-title { font-size: 16px; font-weight: 500; margin-bottom: 4px; }
        .card-subtitle { font-size: 13px; color: var(--muted); }
        .card-meta { font-size: 12px; color: var(--muted); display: flex; flex-direction: column; gap: 2px; }
        .card-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
        .tag { font-size: 11px; padding: 2px 8px; border-radius: 999px; background: #f5f5f5; border: 1px solid #e4e4e4; color: var(--muted); transition: box-shadow 0.2s, transform 0.2s, background 0.2s, border-color 0.2s, color 0.2s; }
        .tag-equipment { background: var(--accent-light); border-color: #c5d9fc; color: var(--accent); }
        .tag-clickable { cursor: pointer; }
        .tag-clickable:hover { box-shadow: 0 4px 10px rgba(0,0,0,0.08); transform: translateY(-1px); }
        .tag-filter-active { background: var(--accent-light); border-color: #c5d9fc; color: var(--accent); box-shadow: 0 4px 10px rgba(0,0,0,0.08); transform: translateY(-1px); }
        .card-footer { display: flex; justify-content: flex-start; align-items: flex-end; margin-top: 4px; }
        .price { font-size: 14px; font-weight: 500; }
        .loading, .no-results { text-align: center; padding: 48px; color: var(--muted); }
        /* Vehicle Detail View */
        .vehicle-detail { display: none !important; flex-direction: column; min-height: 100vh; }
        .vehicle-detail.active { display: flex !important; }
        .vehicle-detail .back-button { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; color: var(--muted); cursor: pointer; padding: 8px 0; margin-bottom: 8px; transition: color 0.2s; }
        .vehicle-detail .back-button:hover { color: var(--accent); }
        .vehicle-detail .back-button svg { width: 16px; height: 16px; }
        .detail-layout { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 32px; align-items: start; }
        .detail-media { position: sticky; top: 24px; min-width: 0; }
        .detail-hero { background: var(--bg); border-radius: 12px; overflow: hidden; position: relative; box-shadow: 0 4px 20px rgba(0,0,0,0.06); aspect-ratio: 4/3; max-height: calc(100vh - 160px); }
        .detail-hero img { width: 100%; height: 100%; object-fit: contain; object-position: center; transition: opacity 0.2s; display: block; }
        .detail-hero-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 42px; height: 42px; border-radius: 8px; border: none; background: rgba(255,255,255,0.5); color: var(--text); display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.03); transition: all 0.2s; font-size: 20px; font-weight: 500; z-index: 2; touch-action: manipulation; user-select: none; }
        .detail-hero-nav:hover { background: rgba(255,255,255,0.7); color: var(--accent); transform: translateY(-50%) scale(1.02); box-shadow: 0 2px 6px rgba(0,0,0,0.06); }
        .detail-hero-nav.prev { left: 16px; }
        .detail-hero-nav.next { right: 16px; }
        .detail-bookmark-button { position: absolute; top: 12px; right: 12px; width: 44px; height: 44px; border-radius: 12px; border: 1px solid rgba(0,0,0,0.15); background: rgba(255,255,255,0.95); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; color: #4b5563; box-shadow: 0 3px 12px rgba(0,0,0,0.08); z-index: 3; }
        .detail-bookmark-button svg { width: 20px; height: 20px; stroke-width: 1.6; }
        .detail-bookmark-button:hover { border-color: var(--accent); box-shadow: 0 6px 20px rgba(0,0,0,0.15); }
        .detail-bookmark-button.bookmarked { color: #ef4444; background: rgba(255,255,255,0.95); border-color: rgba(239,68,68,0.5); box-shadow: 0 6px 20px rgba(239,68,68,0.28); }
        .detail-thumbnails { display: flex; gap: 8px; margin-top: 12px; overflow-x: auto; padding-bottom: 8px; max-width: 100%; }
        .detail-thumbnails::-webkit-scrollbar { height: 4px; }
        .detail-thumbnails::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
        .detail-thumb { width: 60px; height: 45px; border-radius: 6px; overflow: hidden; cursor: pointer; border: 2px solid transparent; transition: border-color 0.2s, opacity 0.2s; flex-shrink: 0; opacity: 0.6; }
        .detail-thumb:hover, .detail-thumb.active { opacity: 1; border-color: var(--accent); }
        .detail-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .detail-panel { padding: 0; }
        .detail-title { font-size: clamp(20px, 2vw, 26px); font-weight: 600; margin-bottom: 4px; line-height: 1.2; }
        .detail-subtitle { font-size: 13px; color: var(--muted); margin-bottom: 12px; }
        .vin-container.vin-glow .vin-clickable,
        .vin-prefix:hover { background-color: #e3f2fd; border-radius: 2px; }
        .vin-last8:hover { background-color: #e3f2fd; border-radius: 2px; }
        .vin-clickable { cursor: pointer; user-select: none; transition: background-color 0.2s; }
        .stock-clickable { cursor: pointer; user-select: none; transition: background-color 0.2s; }
        .stock-clickable:hover { background-color: #e3f2fd; border-radius: 2px; }
        .detail-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 20px; }
        .detail-tags .tag { font-size: 11px; padding: 4px 10px; }
        .detail-price-section { background: #fff; border-radius: 10px; padding: 16px; margin-bottom: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        .detail-price-breakdown { display: flex; flex-direction: column; gap: 8px; }
        .price-line { display: flex; justify-content: space-between; align-items: center; padding: 4px 0; }
        .price-label { font-size: 14px; color: var(--text); }
        .price-value { font-size: 14px; font-weight: 500; }
        .discount-line .price-label,
        .discount-line .price-value { color: #22c55e; } /* green for discounts */
        .final-price .price-label,
        .final-price .price-value { font-weight: 600; font-size: 16px; }
        .detail-specs { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 0px; }
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
        .detail-equipment { background: #fff; border-radius: 10px; padding: 16px; margin-bottom: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        .detail-equipment-title { font-size: 13px; font-weight: 600; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
        .detail-equipment-title svg { width: 14px; height: 14px; transition: transform 0.2s; }
        .detail-equipment-title.collapsed svg { transform: rotate(-90deg); }
        .detail-equipment-list { display: flex; flex-direction: column; gap: 4px; }
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
        .stock-search { width: 100%; }
        .stock-search input { flex: 1; width: 100%; }
        .qr-scan-button { display: flex; }
        }
        .comparison-table-container {
            overflow-x: auto;
            width: 100%;
            margin: 20px 0;
        }
        .comparison-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            min-width: 800px;
        }
        .comparison-table th,
        .comparison-table td {
            padding: 12px 16px;
            text-align: left;
            border: 1px solid #e0e0e0;
            vertical-align: top;
        }
        .comparison-label-cell {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--text);
            width: 150px;
            min-width: 150px;
            position: sticky;
            left: 0;
            z-index: 2;
            box-shadow: 2px 0 4px rgba(0,0,0,0.1);
        }
        .comparison-data-cell {
            min-width: 200px;
            background: white;
        }
        .comparison-data-cell.empty-cell {
            color: #999;
            font-style: italic;
        }
        .comparison-data-cell.no-equipment {
            color: #999;
            font-style: italic;
        }
        .comparison-vehicle-photo {
            width: 180px;
            height: 120px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #ddd;
        }
        .comparison-equipment-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .comparison-equipment-item {
            font-size: 13px;
            color: var(--muted);
            padding: 2px 0;
            line-height: 1.3;
        }
        .comparison-equipment-item:not(:last-child)::after {
            content: '';
            display: block;
            height: 1px;
            background: #f0f0f0;
            margin-top: 4px;
        }
        /* Header row styling */
        .comparison-table tbody tr:first-child .comparison-label-cell {
            background: #e9ecef;
            font-weight: 700;
            font-size: 16px;
        }
        .comparison-table tbody tr:first-child .comparison-data-cell {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 15px;
        }
        /* Photo row styling */
        .comparison-table tbody tr:nth-child(2) {
            background: #fafafa;
        }
        .comparison-table tbody tr:nth-child(2) .comparison-label-cell {
            background: #f8f9fa;
        }
        /* Alternate row colors for better readability */
        .comparison-table tbody tr:nth-child(odd) .comparison-data-cell {
            background: #ffffff;
        }
        .comparison-table tbody tr:nth-child(even) .comparison-data-cell {
            background: #f9f9f9;
        }
        /* Hover effect */
        .comparison-table tbody tr:hover .comparison-data-cell {
            background: #e3f2fd;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/@zxing/library@0.20.0/umd/index.min.js"></script>
</head>
<body>
    <div class="page">
        <header class="header">
            <div>
                <div class="header-title" id="header-title">Inventory</div>
                <div class="header-subtitle" id="last-updated"></div>
                <form class="stock-search" id="stock-search" autocomplete="off">
                    <input type="text" id="stock-search-input" placeholder="Search by stock #" aria-label="Search by stock number">
                    <button type="button" id="qr-scan-button" class="qr-scan-button" title="Scan QR Code or VIN Barcode" aria-label="Scan QR Code or VIN Barcode">
                        📷
                    </button>
                </form>
                <div class="stock-search-hint" id="stock-search-hint"></div>
            </div>
            <div class="header-right hidden" id="header-right">
                <button class="bookmarks-button" id="bookmarks-button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>
                    </svg>
                </button>
                <button class="bookmarks-clear-button hidden" id="clear-bookmarks-button">Clear</button>
                <button type="button" class="view-toggle-button hidden" id="view-toggle-button">
                    <span id="view-toggle-icon">C</span>
                    <span id="view-toggle-text">Table</span>
                </button>
                <div class="results-count" id="results-count"></div>
                <div class="sort" id="sort-container" style="display: none;"><span>Sort:</span>
                    <select class="sort-select" id="sort-select">
                        <option value="random">Random</option>
                        <option value="price_asc">Price: Low to High</option>
                        <option value="price_desc">Price: High to Low</option>
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
                                <button class="detail-bookmark-button" id="detail-bookmark-button" aria-label="Bookmark vehicle"></button>
                                <button class="detail-hero-nav prev" id="detail-prev">←</button>
                                <button class="detail-hero-nav next" id="detail-next">→</button>
                            </div>
                            <div class="detail-thumbnails" id="detail-thumbnails"></div>
                        </div>
                        <div class="detail-panel">
                            <div class="detail-title" id="detail-title">Loading...</div>
                            <div class="detail-subtitle" id="detail-subtitle"></div>
                            <div class="detail-tags" id="detail-tags"></div>
                            <div class="detail-price-section">
                                <div class="detail-price-breakdown" id="detail-price-breakdown">
                                    <div class="price-line">
                                        <span class="price-label">MSRP</span>
                                        <span class="price-value" id="msrp-price"></span>
                                    </div>
                                    <div class="price-line discount-line">
                                        <span class="price-label">Dealer Discount</span>
                                        <span class="price-value" id="dealer-discount"></span>
                                    </div>
                                    <div class="price-line">
                                        <span class="price-label">Sale Price</span>
                                        <span class="price-value" id="sale-price"></span>
                                    </div>
                                    <div class="price-line discount-line">
                                        <span class="price-label">Factory Rebates</span>
                                        <span class="price-value" id="factory-rebates"></span>
                                    </div>
                                    <div class="price-line final-price">
                                        <span class="price-label">Retail Price</span>
                                        <span class="price-value" id="retail-price"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="detail-cta">
                                <button class="primary" id="detail-contact">Contact Dealer</button>
                                <button class="secondary" id="detail-sticker">Window Sticker</button>
                                <button class="secondary" id="detail-invoice">Invoice</button>
                            </div>
                            <div class="detail-specs" id="detail-specs"></div>
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
                <div class="filter-section" id="series-section" style="display:none;">
                    <div class="filter-title">Series <span class="filter-clear" id="clear-series">Clear</span></div>
                    <div id="series-options"></div>
                </div>
                <div class="filter-section" id="trim-section" style="display:none;">
                    <div class="filter-title">Trim <span class="filter-clear" id="clear-trim">Clear</span></div>
                    <div id="trim-options"></div>
                </div>
                <div class="filter-section" id="engine-section" style="display:none;">
                    <div class="filter-title">Engine <span class="filter-clear" id="clear-engine">Clear</span></div>
                    <div id="engine-options"></div>
                </div>
                <div class="filter-section" id="wheelbase-section" style="display:none;">
                    <div class="filter-title">Wheelbase <span class="filter-clear" id="clear-wheelbase">Clear</span></div>
                    <div id="wheelbase-options"></div>
                </div>
                <div class="filter-section" id="drivetrain-section" style="display:none;">
                    <div class="filter-title">Drivetrain <span class="filter-clear" id="clear-drivetrain">Clear</span></div>
                    <div id="drivetrain-options"></div>
                </div>
                <div class="filter-section" id="axle-section" style="display:none;">
                    <div class="filter-title">Rear Axle <span class="filter-clear" id="clear-axle">Clear</span></div>
                    <div id="axle-options"></div>
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
                <div class="filter-section" id="package-section" style="display:none;">
                    <div class="filter-title">Package <span class="filter-clear" id="clear-package">Clear</span></div>
                    <div id="package-options"></div>
                </div>
                <div class="filter-section" id="status-section" style="display:none;">
                    <div class="filter-title">Status <span class="filter-clear" id="clear-status">Clear</span></div>
                    <div id="status-options"></div>
                </div>
            </aside>
            <section class="inventory">
                <div class="active-filters" id="active-filters"></div>
                <div class="cards-grid" id="cards-grid"></div>
            </section>
        </div>
    </div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Ensure vehicle detail view is hidden initially
    const initialVehicleDetail = document.querySelector('.vehicle-detail');
    if (initialVehicleDetail) {
        initialVehicleDetail.classList.remove('active');
        initialVehicleDetail.style.display = 'none';
    }

    let vehicles = [];
    let facetCounts = {};
    let filteredVehicles = [];
    let displayedCount = 0;
    let bookmarkViewMode = 'cards'; // 'cards' or 'table'
    const ITEMS_PER_PAGE = 24;
    let isHomepage = true; // Start on homepage
    window.currentView = 'inventory';
    let selectedFilters = { model: null, year: [], series: [], trim: [], package: [], engine: [], wheelbase: [], drivetrain: [], body_style: [], rear_axle: [], equipment: [], color: [], status: [] };
    let excludeFilters = { model: null, year: [], series: [], trim: [], package: [], engine: [], wheelbase: [], drivetrain: [], body_style: [], rear_axle: [], equipment: [], color: [], status: [] };
    const grid = document.getElementById('cards-grid');
    const resultsCount = document.getElementById('results-count');
    const sortSelect = document.getElementById('sort-select');
    const activeFiltersContainer = document.getElementById('active-filters');
    const headerTitle = document.getElementById('header-title');
    const headerRight = document.getElementById('header-right');
    const filtersSidebar = document.getElementById('filters-sidebar');
    const mainContent = document.getElementById('main-content');
    const stockSearchForm = document.getElementById('stock-search');
    const stockSearchInput = document.getElementById('stock-search-input');
    const stockSearchHint = document.getElementById('stock-search-hint');
    const hasDetailView = !!document.getElementById('vehicle-detail');

    // URL State Management
    function updateURL(replace = false) {
        const url = new URL(window.location);
        
        // Clear all existing params first
        url.search = '';
        
        // Only add params if not on homepage
        if (!isHomepage && selectedFilters.model) {
            url.searchParams.set('model', selectedFilters.model);
            
            // Add array filters (only if they have values)
            ['year', 'series', 'trim', 'package', 'engine', 'wheelbase', 'drivetrain', 'body_style', 'rear_axle', 'equipment', 'color', 'status'].forEach(key => {
                if (selectedFilters[key] && selectedFilters[key].length > 0) {
                    url.searchParams.set(key, selectedFilters[key].join(','));
                }
            });

            // Add exclude filters
            ['year', 'series', 'trim', 'package', 'engine', 'wheelbase', 'drivetrain', 'body_style', 'rear_axle', 'equipment', 'color', 'status'].forEach(key => {
                if (excludeFilters[key] && excludeFilters[key].length > 0) {
                    url.searchParams.set(`exclude_${key}`, excludeFilters[key].join(','));
                }
            });
        }
        
        // Use replaceState for filter changes, pushState for model changes
        if (replace) {
            history.replaceState({ filters: selectedFilters, excludeFilters: excludeFilters, isHomepage }, '', url);
        } else {
            history.pushState({ filters: selectedFilters, excludeFilters: excludeFilters, isHomepage }, '', url);
        }
    }
    
    function parseURLState() {
        const params = new URLSearchParams(window.location.search);
        const state = {
            model: params.get('model') || null,
            year: params.get('year') ? params.get('year').split(',') : [],
            series: params.get('series') ? params.get('series').split(',') : [],
            trim: params.get('trim') ? params.get('trim').split(',') : [],
            package: params.get('package') ? params.get('package').split(',') : [],
            engine: params.get('engine') ? params.get('engine').split(',') : [],
            wheelbase: params.get('wheelbase') ? params.get('wheelbase').split(',') : [],
            drivetrain: params.get('drivetrain') ? params.get('drivetrain').split(',') : [],
            body_style: params.get('body_style') ? params.get('body_style').split(',') : [],
            rear_axle: params.get('rear_axle') ? params.get('rear_axle').split(',') : [],
            equipment: params.get('equipment') ? params.get('equipment').split(',') : [],
            color: params.get('color') ? params.get('color').split(',') : [],
            status: params.get('status') ? params.get('status').split(',') : [],
            // Exclude filters
            exclude_year: params.get('exclude_year') ? params.get('exclude_year').split(',') : [],
            exclude_series: params.get('exclude_series') ? params.get('exclude_series').split(',') : [],
            exclude_trim: params.get('exclude_trim') ? params.get('exclude_trim').split(',') : [],
            exclude_package: params.get('exclude_package') ? params.get('exclude_package').split(',') : [],
            exclude_engine: params.get('exclude_engine') ? params.get('exclude_engine').split(',') : [],
            exclude_wheelbase: params.get('exclude_wheelbase') ? params.get('exclude_wheelbase').split(',') : [],
            exclude_drivetrain: params.get('exclude_drivetrain') ? params.get('exclude_drivetrain').split(',') : [],
            exclude_body_style: params.get('exclude_body_style') ? params.get('exclude_body_style').split(',') : [],
            exclude_rear_axle: params.get('exclude_rear_axle') ? params.get('exclude_rear_axle').split(',') : [],
            exclude_equipment: params.get('exclude_equipment') ? params.get('exclude_equipment').split(',') : [],
            exclude_color: params.get('exclude_color') ? params.get('exclude_color').split(',') : [],
            exclude_status: params.get('exclude_status') ? params.get('exclude_status').split(',') : []
        };
        return state;
    }
    
    function restoreStateFromURL(pushHistory = false) {
        try {
            const urlState = parseURLState();
            const historyState = history.state;

            if (urlState.model || (historyState && historyState.filters)) {
                // Restore to inventory view with filters
                isHomepage = false;
                updateSortVisibility();

                // Use history state if available, otherwise URL state
                const state = historyState && historyState.filters ? historyState : urlState;

                selectedFilters.model = (state.filters && state.filters.model) || urlState.model;
                selectedFilters.year = (state.filters && state.filters.year) || urlState.year;
                selectedFilters.series = (state.filters && state.filters.series) || urlState.series;
                selectedFilters.trim = (state.filters && state.filters.trim) || urlState.trim;
                selectedFilters.package = (state.filters && state.filters.package) || urlState.package;
                selectedFilters.engine = (state.filters && state.filters.engine) || urlState.engine;
                selectedFilters.drivetrain = (state.filters && state.filters.drivetrain) || urlState.drivetrain;
                selectedFilters.body_style = (state.filters && state.filters.body_style) || urlState.body_style;
                selectedFilters.rear_axle = (state.filters && state.filters.rear_axle) || urlState.rear_axle;
                selectedFilters.equipment = (state.filters && state.filters.equipment) || urlState.equipment;
                selectedFilters.color = (state.filters && state.filters.color) || urlState.color;
                selectedFilters.status = (state.filters && state.filters.status) || urlState.status;

                // Restore exclude filters
                if (state.excludeFilters) {
                    excludeFilters.year = state.excludeFilters.year;
                    excludeFilters.series = state.excludeFilters.series;
                    excludeFilters.trim = state.excludeFilters.trim;
                    excludeFilters.package = state.excludeFilters.package;
                    excludeFilters.engine = state.excludeFilters.engine;
                    excludeFilters.drivetrain = state.excludeFilters.drivetrain;
                    excludeFilters.body_style = state.excludeFilters.body_style;
                    excludeFilters.rear_axle = state.excludeFilters.rear_axle;
                    excludeFilters.equipment = state.excludeFilters.equipment;
                    excludeFilters.color = state.excludeFilters.color;
                    excludeFilters.status = state.excludeFilters.status;
                } else {
                    // Fallback to URL state for exclude filters
                    excludeFilters.year = urlState.exclude_year;
                    excludeFilters.series = urlState.exclude_series;
                    excludeFilters.trim = urlState.exclude_trim;
                    excludeFilters.package = urlState.exclude_package;
                    excludeFilters.engine = urlState.exclude_engine;
                    excludeFilters.drivetrain = urlState.exclude_drivetrain;
                    excludeFilters.body_style = urlState.exclude_body_style;
                    excludeFilters.rear_axle = urlState.exclude_rear_axle;
                    excludeFilters.equipment = urlState.exclude_equipment;
                    excludeFilters.color = urlState.exclude_color;
                    excludeFilters.status = urlState.exclude_status;
                }
                
                // Update UI state
                headerRight.classList.remove('hidden');
                filtersSidebar.classList.remove('hidden');
                mainContent.classList.remove('homepage-main');
                setStockSearchVisible(false);
                if (stockSearchForm) stockSearchForm.style.display = 'none';
                if (stockSearchHint) stockSearchHint.style.display = 'none';
                
                // Check the corresponding radio button
                const radio = document.querySelector('input[name="model"][value="' + urlState.model + '"]');
                if (radio) radio.checked = true;

                updateFilterVisibility();
                updateYearOptions();
                
                // Update year clear button visibility
                const clearYearBtn = document.getElementById('clear-year');
                if (clearYearBtn) clearYearBtn.style.display = (selectedFilters.year && selectedFilters.year.length) ? 'inline' : 'none';
                
                applyFiltersNoURLUpdate();
            } else {
                // Restore to homepage
                renderHomepageNoURLUpdate();
            }
        } catch (error) {
            console.error('Error in restoreStateFromURL:', error);
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
    function updateSortVisibility() {
        const sortContainer = document.getElementById('sort-container');
        if (sortContainer) {
            sortContainer.style.display = (!isHomepage && selectedFilters.model) ? 'flex' : 'none';
        }
    }

    function renderHomepageNoURLUpdate() {
        isHomepage = true;
        selectedFilters.model = null;
        updateSortVisibility();
        // Clear all filters
        ['year', 'series', 'trim', 'package', 'engine', 'wheelbase', 'drivetrain', 'body_style', 'equipment', 'color', 'status'].forEach(k => selectedFilters[k] = []);
        // Clear all exclude filters
        ['year', 'series', 'trim', 'package', 'engine', 'wheelbase', 'drivetrain', 'body_style', 'equipment', 'color', 'status'].forEach(k => excludeFilters[k] = []);
        
        // Update UI state - keep header actions visible for bookmarks access
        headerRight.classList.remove('hidden');
        filtersSidebar.classList.add('hidden');
        mainContent.classList.add('homepage-main');
        setStockSearchVisible(true);
        activeFiltersContainer.innerHTML = '';
        const bookmarksBtn = document.getElementById('bookmarks-button');
        if (bookmarksBtn) {
            bookmarksBtn.classList.remove('active');
        }
        
        // Uncheck all model radio buttons
        document.querySelectorAll('input[name="model"]').forEach(r => r.checked = false);
        
        // Build homepage content
        grid.innerHTML = '';

        // Create model cards container
        const homepageGrid = document.createElement('div');
        homepageGrid.className = 'homepage-grid';
        console.log('Created homepageGrid, grid element:', grid);
        
        modelConfig.forEach(model => {
            const modelVehicles = vehicles.filter(v => normalizeModel(v.model) === model.value);
            console.log('Model:', model.value, 'vehicles found:', modelVehicles.length);
            // Filter to only vehicles with actual images (not placeholders)
            const vehiclesWithImages = modelVehicles.filter(v => {
                // Check if photo_urls (or fallback to photo) contains actual vehicle photos (not placeholders)
                let urls = v.photo_urls;
                if ((!urls || (Array.isArray(urls) && urls.length === 0)) && v.photo) {
                    urls = [v.photo];
                }
                if (typeof urls === 'string') {
                    urls = urls.split(',').map(u => u.trim()).filter(u => u);
                }

                if (Array.isArray(urls) && urls.length > 0) {
                    const realPhotos = urls.filter(url => {
                        if (!url) return false;
                        // Only exclude true placeholder images
                        if (url.includes('unavailable_stockphoto.png')) return false;
                        // Keep legitimate photos including dealer images and color swatches
                        return true;
                    });
                    return realPhotos.length > 0;
                }
                // If we reach here and there is at least a single photo string, keep it
                return !!v.photo;
            });
            console.log('Model:', model.value, 'vehicles with images:', vehiclesWithImages.length);
            if (vehiclesWithImages.length === 0) return;

            // Get a random vehicle from this model for the image (only from vehicles with images)
            const randomVehicle = vehiclesWithImages[Math.floor(Math.random() * vehiclesWithImages.length)];
            
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
            count.textContent = modelVehicles.length + ' available';
            
            content.appendChild(title);
            content.appendChild(count);
            card.appendChild(imageDiv);
            card.appendChild(content);
            homepageGrid.appendChild(card);
        });

        grid.appendChild(homepageGrid);
        console.log('Appended homepageGrid to grid, grid children:', grid.children.length);
    }
    
    // Function to render the homepage (with URL update)
    function renderHomepage() {
        // Reset bookmark view state
        window.currentView = 'inventory';
        renderHomepageNoURLUpdate();
        updateBookmarksButton();
        updateURL();
    }
    
    // Function to select a model and show inventory
    function selectModel(modelValue) {
        try {
            isHomepage = false;
            selectedFilters.model = modelValue;
            
            // Update UI state
            headerRight.classList.remove('hidden');
            filtersSidebar.classList.remove('hidden');
            mainContent.classList.remove('homepage-main');
            setStockSearchVisible(false);
            
            // Clear the grid immediately to prevent showing previous model/homepage cards
            grid.innerHTML = '<div style="text-align: center; padding: 48px; color: var(--muted);">Loading ' + modelValue.toUpperCase() + ' vehicles...</div>';
            
            // Check the corresponding radio button
            const radio = document.querySelector('input[name="model"][value="' + modelValue + '"]');
            if (radio) radio.checked = true;
            
            // Clear other filters when switching models
            ['series', 'trim', 'package', 'engine', 'wheelbase', 'drivetrain', 'body_style', 'equipment', 'color', 'status'].forEach(k => selectedFilters[k] = []);

            updateSortVisibility();
            updateFilterVisibility();
            applyFiltersNoURLUpdate();
            updateURL(); // Push new history entry for model selection
        } catch (error) {
            console.error('Error in selectModel:', error);
            // Fallback: if something fails, try to at least show the vehicles without full filter UI
            try {
                applyFiltersNoURLUpdate();
                updateURL();
            } catch (innerError) {
                console.error('Deep failure in selectModel:', innerError);
                renderHomepage();
            }
        }
    }
    
    // Header title click handler to return to homepage
    headerTitle.onclick = () => {
        if (!isHomepage && vehicles.length > 0) {
            renderHomepage();
        }
    };

    // Bookmarks button handler
    const bookmarksButton = document.getElementById('bookmarks-button');
    if (bookmarksButton) {
        bookmarksButton.onclick = () => {
            showBookmarkedVehicles();
        };
    }
    const clearBookmarksButton = document.getElementById('clear-bookmarks-button');
    if (clearBookmarksButton) {
        clearBookmarksButton.onclick = clearAllBookmarks;
    }

    // View toggle button handler
    const viewToggleButton = document.getElementById('view-toggle-button');
    if (viewToggleButton) {
        viewToggleButton.onclick = (e) => {
            if (e && typeof e.preventDefault === 'function') e.preventDefault();
            if (e && typeof e.stopPropagation === 'function') e.stopPropagation();

            // If we're not in bookmark view, first enter it (otherwise we have no grid to toggle).
            if (window.currentView !== 'bookmarks') {
                showBookmarkedVehicles();
                return;
            }

            // Toggle cards/table INSIDE bookmark view (do not call showBookmarkedVehicles, it toggles views).
            bookmarkViewMode = bookmarkViewMode === 'cards' ? 'table' : 'cards';
            updateViewToggleButton();
            renderBookmarkedVehiclesGrid();
        };
    }

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
            const modelVehicles = vehicles.filter(v => getVehicleNormalizedModel(v) === model.value);
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

    const statusOptions = [
        { value: 'in-stock', label: 'In Stock' },
        { value: 'in-transit', label: 'In Transit' }
    ];

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
            { value: '2.7L EcoBoost V6', label: '2.7L EcoBoost V6' },
            { value: '3.5L V6 EcoBoost', label: '3.5L EcoBoost V6' },
            { value: 'PowerBoost', label: '3.5L PowerBoost Hybrid' },
            { value: '5.0L V8', label: '5.0L V8' },
            { value: 'High Output', label: '3.5L High-Output (Raptor)' }
        ],
        drivetrains: [{ value: '4x2', label: '4x2 (RWD)' }, { value: '4x4', label: '4x4 (4WD)' }],
        bodyStyles: [{ value: 'Regular Cab', label: 'Regular Cab' }, { value: 'Super Cab', label: 'Super Cab' }, { value: 'SuperCrew', label: 'SuperCrew' }],
        equipment: [
            { value: 'bedliner', label: 'Spray-In Bedliner', keywords: ['bedliner', 'tough bed', 'spray-in'] },
            { value: 'running_boards', label: 'Running Boards', keywords: ['running board', 'step bar', 'power running'] },
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

    const superDutyConfig = {
        series: [
            { value: 'F-250', label: 'F-250' },
            { value: 'F-350', label: 'F-350' },
            { value: 'F-450', label: 'F-450' },
            { value: 'Chassis', label: 'Chassis' }
        ],
        trims: [
            { value: 'XL', label: 'XL' },
            { value: 'XLT', label: 'XLT' },
            { value: 'Lariat', label: 'Lariat' },
            { value: 'King Ranch', label: 'King Ranch' },
            { value: 'Platinum', label: 'Platinum' }
        ],
        packages: {
            'XL': ['600A','610A','620A','670A'].map(v => ({ value: v, label: v })),
            'XLT': ['603A'].map(v => ({ value: v, label: v })),
            'Lariat': ['608A'].map(v => ({ value: v, label: v })),
            'King Ranch': ['700A','710A'].map(v => ({ value: v, label: v })),
            'Platinum': ['703A','713A'].map(v => ({ value: v, label: v }))
        },
        engines: [
            { value: '6.8L V8 Gas', label: '6.8L V8 Gas' },
            { value: '7.3L V8 Gas', label: '7.3L V8 Gas' },
            { value: '6.7L Power Stroke V8', label: '6.7L Power Stroke V8 Diesel' },
            { value: '6.7L High Output Power Stroke V8', label: '6.7L HO Power Stroke V8 Diesel' }
        ],
        wheelbases: [
            { value: '141.6', label: '141.6"' },
            { value: '159.8', label: '159.8"' },
            { value: '164.2', label: '164.2"' },
            { value: '176.0', label: '176.0"' }
        ],
        drivetrains: [
            { value: '4x2', label: '4x2' },
            { value: '4x4', label: '4x4' }
        ],
        bodyStyles: [
            { value: 'Regular Cab', label: 'Regular Cab' },
            { value: 'Super Cab', label: 'Super Cab' },
            { value: 'Crew Cab', label: 'Crew Cab' }
        ],
        equipment: [
            { value: 'tremor_off_road', label: 'Tremor Off-Road Package', keywords: ['tremor', '17y'] },
            { value: 'black_appearance', label: 'Black Appearance Package', keywords: ['black appearance', '17l'] },
            { value: 'chrome_package', label: 'Chrome Package', keywords: ['chrome package', '17c'] },
            { value: 'moonroof', label: 'Moonroof', keywords: ['moonroof'] },
            { value: 'lariat_ultimate', label: 'Lariat Ultimate Package', keywords: ['lariat ultimate', '96u'] },
            { value: 'pro_power', label: 'Pro Power Onboard', keywords: ['pro power', '2kw'] },
            { value: 'fifth_wheel_prep', label: '5th Wheel', keywords: ['5th wheel', 'gooseneck', '53w'] },
            { value: 'copilot_assist', label: 'Co-Pilot360 Assist 2.0', keywords: ['co-pilot360 assist', 'copilot360 assist', 'lane centering'] },
            { value: 'tough_bed', label: 'Tough Bed Bedliner', keywords: ['tough bed', 'bedliner', '85s'] },
            { value: 'trailer_tow', label: 'Trailer Tow / Smart Hitch', keywords: ['smart hitch', 'onboard scales', 'trailer tow'] }
        ],
        colors: [
            { value: 'Agate Black', label: 'Agate Black' },
            { value: 'Antimatter Blue', label: 'Antimatter Blue' },
            { value: 'Argon Blue', label: 'Argon Blue' },
            { value: 'Avalanche', label: 'Avalanche' },
            { value: 'Carbonized Gray Metallic', label: 'Carbonized Gray Metallic' },
            { value: 'Glacier Gray', label: 'Glacier Gray' },
            { value: 'Oxford White', label: 'Oxford White' },
            { value: 'Ruby Red', label: 'Ruby Red' },
            { value: 'Star White', label: 'Star White' }
        ]
    };

    const explorerConfig = {
        trims: [
            { value: 'Active', label: 'Active' },
            { value: 'ST-Line', label: 'ST-Line' },
            { value: 'ST', label: 'ST' },
            { value: 'Platinum', label: 'Platinum' },
            { value: 'Tremor', label: 'Tremor' }
        ],
        packages: {
            'Active': [{ value: '200A', label: '200A' }],
            'ST-Line': [{ value: '300A', label: '300A' }],
            'ST': [{ value: '400A', label: '400A' }],
            'Platinum': [{ value: '600A', label: '600A' }]
        },
        engines: [
            { value: '2.3L EcoBoost I4', label: '2.3L EcoBoost I4' },
            { value: '3.0L EcoBoost V6', label: '3.0L EcoBoost V6' }
        ],
        drivetrains: [
            { value: 'RWD', label: 'RWD' },
            { value: '4WD', label: '4WD' }
        ],
        bodyStyles: [
            { value: 'SUV', label: 'SUV' }
        ],
        equipment: [
            { value: 'bluecruise', label: 'BlueCruise', keywords: ['bluecruise', 'hands-free', 'active drive'] },
            { value: 'copilot_assist_plus', label: 'Co-Pilot360 Assist+', keywords: ['co-pilot360 assist plus', 'copilot assist+', 'lane centering', 'adaptive cruise'] },
            { value: 'copilot_assist_2_0', label: 'Co-Pilot360 Assist 2.0', keywords: ['co-pilot360 assist 2.0', 'predictive speed', 'reverse brake assist'] },
            { value: '360_camera', label: '360-Degree Camera', keywords: ['360 camera', 'surround view', 'split view'] },
            { value: 'class_iv_tow', label: 'Class IV Tow Package', keywords: ['class iv tow', 'trailer tow', 'hitch', 'tow package'] },
            { value: 'moonroof', label: 'Twin-Panel Moonroof', keywords: ['moonroof', 'sunroof', 'twin panel', 'panoramic'] },
            { value: 'black_roof', label: 'Black Painted Roof', keywords: ['black painted roof', 'contrast roof', 'black roof'] }
        ],
        colors: [
            { value: 'Agate Black', label: 'Agate Black' },
            { value: 'Carbonized Gray', label: 'Carbonized Gray' },
            { value: 'Oxford White', label: 'Oxford White' },
            { value: 'Star White', label: 'Star White' },
            { value: 'Rapid Red', label: 'Rapid Red' },
            { value: 'Vapor Blue', label: 'Vapor Blue' },
            { value: 'Space White', label: 'Space White' }
        ]
    };

    const maverickConfig = {
        trims: [
            { value: 'XL', label: 'XL' },
            { value: 'XLT', label: 'XLT' },
            { value: 'Lobo', label: 'Lobo' },
            { value: 'Lariat', label: 'Lariat' },
            { value: 'Tremor', label: 'Tremor' }
        ],
        packages: {
            'XL': [{ value: '100A', label: '100A' }, { value: '101A', label: '101A' }, { value: '102A', label: '102A' }],
            'XLT': [{ value: '300A', label: '300A' }, { value: '301A', label: '301A' }, { value: '302A', label: '302A' }],
            'Lariat': [{ value: '501A', label: '501A' }, { value: '502A', label: '502A' }],
            'Tremor': [{ value: '602A', label: '602A' }],
            'Lobo': [{ value: '402A', label: '402A' }, { value: '702A', label: '702A' }]
        },
        engines: [
            { value: '2.0L EcoBoost I4', label: '2.0L EcoBoost I4' },
            { value: '2.5L Hybrid I4', label: '2.5L Hybrid I4' }
        ],
        drivetrains: [
            { value: 'FWD', label: 'FWD' },
            { value: 'AWD', label: 'AWD' }
        ],
        bodyStyles: [],
        equipment: [
            { value: 'fx4', label: 'FX4 Off-Road Package', keywords: ['fx4', 'off-road', 'skid plate'] },
            { value: 'tow_pkg', label: '4K Tow Package', keywords: ['trailer tow', 'tow package'] },
            { value: 'black_appearance', label: 'Black Appearance Pkg', keywords: ['black appearance'] },
            { value: 'xlt_luxury', label: 'XLT Luxury Package', keywords: ['xlt luxury', 'luxury package', 'premium'] }
        ],
        colors: [
            { value: 'Oxford White', label: 'Oxford White' },
            { value: 'Shadow Black', label: 'Shadow Black' },
            { value: 'Carbonized Gray', label: 'Carbonized Gray' },
            { value: 'Eruption Green', label: 'Eruption Green' },
            { value: 'Azure Gray', label: 'Azure Gray' },
            { value: 'Desert Sand', label: 'Desert Sand' },
            { value: 'Ruby Red', label: 'Ruby Red' },
            { value: 'Velocity Blue', label: 'Velocity Blue' },
            { value: 'Space White', label: 'Space White' }
        ]
    };

    const machEConfig = {
        trims: [
            { value: 'Select', label: 'Select' },
            { value: 'Premium', label: 'Premium' },
            { value: 'GT', label: 'GT' }
        ],
        packages: {
            'Select': [{ value: '100A', label: '100A' }],
            'Premium': [{ value: '300A', label: '300A' }],
            'GT': [{ value: '400A', label: '400A' }]
        },
        engines: [
            { value: 'Extended Range Battery', label: 'Extended Range' }
        ],
        drivetrains: [
            { value: 'RWD', label: 'RWD' },
            { value: 'AWD', label: 'AWD' }
        ],
        bodyStyles: [],
        equipment: [
            { value: 'bluecruise', label: 'BlueCruise', keywords: ['bluecruise', 'hands-free'] },
            { value: 'panoramic_roof', label: 'Panoramic Fixed-Glass', keywords: ['panoramic', 'fixed-glass', 'roof', 'sunroof'] },
            { value: 'fast_charging', label: 'Fast Charging Adapter', keywords: ['fast charging', 'charging adapter', 'dc fast charge'] },
            { value: 'sport_appearance', label: 'Sport Appearance Package', keywords: ['sport appearance', 'sport package', 'appearance package'] }
        ],
        colors: [
            { value: 'Shadow Black', label: 'Shadow Black' },
            { value: 'Star White', label: 'Star White' },
            { value: 'Grabber Yellow', label: 'Grabber Yellow' },
            { value: 'Molten Magenta', label: 'Molten Magenta' },
            { value: 'Velocity Blue', label: 'Velocity Blue' },
            { value: 'Eruption Green', label: 'Eruption Green' },
            { value: 'Glacier Gray', label: 'Glacier Gray' },
            { value: 'Desert Sand', label: 'Desert Sand' },
            { value: 'Grabber Blue', label: 'Grabber Blue' },
            { value: 'Rapid Red', label: 'Rapid Red' },
            { value: 'Vapor Blue', label: 'Vapor Blue' }
        ]
    };

    const broncoConfig = {
        trims: [
            { value: 'Base', label: 'Base' },
            { value: 'Big Bend', label: 'Big Bend' },
            { value: 'Outer Banks', label: 'Outer Banks' },
            { value: 'Badlands', label: 'Badlands' },
            { value: 'Stroppe Edition', label: 'Stroppe Edition' },
            { value: 'Raptor', label: 'Raptor' },
            { value: 'Heritage Edition', label: 'Heritage Edition' }
        ],
        packages: {
            'Base': [{ value: '101A', label: '101A' }],
            'Big Bend': [{ value: '221A', label: '221A' }, { value: '222A', label: '222A' }],
            'Outer Banks': [{ value: '312A', label: '312A' }, { value: '314A', label: '314A' }],
            'Badlands': [{ value: '331A', label: '331A' }, { value: '332A', label: '332A' }, { value: '334A', label: '334A' }],
            'Raptor': [{ value: '374A', label: '374A' }],
            'Heritage Edition': [{ value: '564A', label: '564A' }],
            'Stroppe Edition': [{ value: '662A', label: '662A' }]
        },
        engines: [
            { value: '2.3L EcoBoost I4', label: '2.3L EcoBoost I4' },
            { value: '2.7L EcoBoost V6', label: '2.7L EcoBoost V6' },
            { value: '3.0L EcoBoost V6', label: '3.0L EcoBoost V6' }
        ],
        bodyStyles: [],
        equipment: [
            { value: 'black_appearance', label: 'Black Appearance Package', keywords: ['black appearance'] },
            { value: 'copilot_assist', label: 'Co-Pilot360', keywords: ['co-pilot360', 'copilot'] },
            { value: 'tow_pkg', label: 'Trailer Tow Package', keywords: ['trailer tow', 'tow package'] },
            { value: '360_camera', label: '360-Degree Camera', keywords: ['360 camera', 'surround view'] },
            { value: 'sound_deadening', label: 'Sound Deadening', keywords: ['sound deadening', 'sound insulation'] },
            { value: 'soft_top', label: 'Soft Top', keywords: ['soft top', 'convertible top', 'fabric top'] },
            { value: 'hard_top_painted', label: 'Hard Top, Painted', keywords: ['hard top, painted', 'hard top painted'] },
            { value: 'hard_top_molded', label: 'Hard Top, Molded-in-Color', keywords: ['molded-in-color', 'molded in color'] }
        ],
        colors: [
            { value: 'Oxford White', label: 'Oxford White' },
            { value: 'Shadow Black', label: 'Shadow Black' },
            { value: 'Carbonized Gray', label: 'Carbonized Gray' },
            { value: 'Eruption Green', label: 'Eruption Green' },
            { value: 'Azure Gray', label: 'Azure Gray' },
            { value: 'Velocity Blue', label: 'Velocity Blue' },
            { value: 'Ruby Red', label: 'Ruby Red' },
            { value: "Marsh Gray", label: "Marsh Gray" },
            { value: "Desert Sand", label: "Desert Sand" },
            { value: "Robin's Egg Blue", label: "Robin's Egg Blue" },
            { value: 'Shelter Green', label: 'Shelter Green' }
        ]
    };

    const broncoSportConfig = {
        trims: [
            { value: 'Big Bend', label: 'Big Bend' },
            { value: 'Heritage', label: 'Heritage' },
            { value: 'Outer Banks', label: 'Outer Banks' },
            { value: 'Badlands', label: 'Badlands' }
        ],
        packages: {
            'Big Bend': [{ value: '200A', label: '200A' }, { value: '250A', label: '250A' }],
            'Heritage': [{ value: '300A', label: '300A' }],
            'Outer Banks': [{ value: '400A', label: '400A' }, { value: '401A', label: '401A' }],
            'Badlands': [{ value: '500A', label: '500A' }]
        },
        engines: [
            { value: '1.5L EcoBoost I3', label: '1.5L EcoBoost I3' },
            { value: '2.0L EcoBoost I4', label: '2.0L EcoBoost I4' }
        ],
        drivetrains: [],
        bodyStyles: [],
        equipment: [
            { value: 'tow_pkg', label: 'Trailer Tow Package', keywords: ['trailer tow', 'tow package'] },
            { value: 'copilot_assist', label: 'Co-Pilot360 Assist', keywords: ['co-pilot360', 'copilot', 'lane centering'] },
            { value: '360_camera', label: '360-Degree Camera', keywords: ['360 camera', '360-degree', 'surround view'] }
        ],
        colors: [
            { value: 'Oxford White', label: 'Oxford White' },
            { value: 'Shadow Black', label: 'Shadow Black' },
            { value: 'Carbonized Gray', label: 'Carbonized Gray' },
            { value: 'Eruption Green', label: 'Eruption Green' },
            { value: 'Azure Gray', label: 'Azure Gray' },
            { value: 'Desert Sand', label: 'Desert Sand' },
            { value: 'Velocity Blue', label: 'Velocity Blue' },
            { value: 'Ruby Red', label: 'Ruby Red' },
            { value: "Robin's Egg Blue", label: "Robin's Egg Blue" }
        ]
    };

    const mustangConfig = {
        trims: [
            { value: 'EcoBoost', label: 'EcoBoost' },
            { value: 'EcoBoost Premium', label: 'EcoBoost Premium' },
            { value: 'GT', label: 'GT' },
            { value: 'GT Premium', label: 'GT Premium' },
            { value: 'Dark Horse', label: 'Dark Horse' },
            { value: 'Dark Horse Premium', label: 'Dark Horse Premium' }
        ],
        packages: {
            'EcoBoost': [{ value: '100A', label: '100A' }, { value: '101A', label: '101A' }],
            'EcoBoost Premium': [{ value: '200A', label: '200A' }, { value: '201A', label: '201A' }],
            'GT': [{ value: '300A', label: '300A' }, { value: '301A', label: '301A' }],
            'GT Premium': [{ value: '400A', label: '400A' }, { value: '401A', label: '401A' }],
            'Dark Horse': [{ value: '600A', label: '600A' }, { value: '700A', label: '700A' }],
            'Dark Horse Premium': [{ value: '800A', label: '800A' }, { value: '801A', label: '801A' }]
        },
        engines: [
            { value: '2.3L EcoBoost I4', label: '2.3L EcoBoost I4' },
            { value: '5.0L V8', label: '5.0L V8' }
        ],
        drivetrains: [],
        bodyStyles: [],
        equipment: [
            { value: 'performance_pkg', label: 'Performance Package', keywords: ['performance package', 'pp'] },
            { value: 'magneride', label: 'MagneRide', keywords: ['magneride', 'adaptive suspension'] },
            { value: 'copilot_assist', label: 'Co-Pilot360 Assist', keywords: ['co-pilot360', 'copilot'] }
        ],
        colors: [
            { value: 'Shadow Black', label: 'Shadow Black' },
            { value: 'Oxford White', label: 'Oxford White' },
            { value: 'Carbonized Gray', label: 'Carbonized Gray' },
            { value: 'Iconic Silver', label: 'Iconic Silver' },
            { value: 'Race Red', label: 'Race Red' },
            { value: 'Grabber Blue', label: 'Grabber Blue' },
            { value: 'Vapor Blue', label: 'Vapor Blue' },
            { value: 'Intense Lime Yellow', label: 'Intense Lime Yellow' },
            { value: 'Molten Magenta', label: 'Molten Magenta' },
            { value: 'Wimbledon White', label: 'Wimbledon White' },
            { value: 'Blue Ember', label: 'Blue Ember' }
        ]
    };

    const expeditionConfig = {
        trims: [
            { value: 'XL', label: 'XL' },
            { value: 'Active', label: 'Active' },
            { value: 'Tremor', label: 'Tremor' },
            { value: 'Platinum', label: 'Platinum' },
            { value: 'King Ranch', label: 'King Ranch' }
        ],
        packages: {
            'XL': [{ value: '100A', label: '100A' }],
            'Active': [{ value: '200A', label: '200A' }],
            'Tremor': [{ value: '300A', label: '300A' }],
            'Platinum': [{ value: '600A', label: '600A' }],
            'King Ranch': [{ value: '400A', label: '400A' }]
        },
        engines: [
            { value: '3.5L EcoBoost V6', label: '3.5L EcoBoost V6' }
        ],
        drivetrains: [
            { value: 'RWD', label: 'RWD' },
            { value: '4x4', label: '4x4' }
        ],
        bodyStyles: [],
        equipment: [
            { value: 'max_tow', label: 'Heavy-Duty Tow', keywords: ['max tow', 'heavy duty trailer'] },
            { value: 'copilot_assist', label: 'Co-Pilot360 Assist', keywords: ['co-pilot360', 'copilot'] },
            { value: '360_camera', label: '360-Degree Camera', keywords: ['360 camera', 'surround view'] }
        ],
        colors: [
            { value: 'Oxford White', label: 'Oxford White' },
            { value: 'Agate Black', label: 'Agate Black' },
            { value: 'Star White', label: 'Star White' },
            { value: 'Dark Matter Gray', label: 'Dark Matter Gray' },
            { value: 'Glacier Gray', label: 'Glacier Gray' },
            { value: 'Space Silver', label: 'Space Silver' },
            { value: 'Stone Blue', label: 'Stone Blue' },
            { value: 'Wild Green', label: 'Wild Green' }
        ]
    };

    const rangerConfig = {
        trims: [
            { value: 'XL', label: 'XL' },
            { value: 'XLT', label: 'XLT' },
            { value: 'Lariat', label: 'Lariat' },
            { value: 'Raptor', label: 'Raptor' }
        ],
        packages: {
            'XL': [{ value: '100A', label: '100A' }],
            'XLT': [{ value: '200A', label: '200A' }, { value: '201A', label: '201A' }],
            'Lariat': [{ value: '300A', label: '300A' }],
            'Raptor': [{ value: '500A', label: '500A' }]
        },
        engines: [
            { value: '2.3L EcoBoost I4', label: '2.3L EcoBoost I4' },
            { value: '2.7L EcoBoost V6', label: '2.7L EcoBoost V6' },
            { value: '3.0L EcoBoost V6', label: '3.0L EcoBoost V6' }
        ],
        drivetrains: [
            { value: '4x2', label: '4x2' },
            { value: '4x4', label: '4x4' }
        ],
        bodyStyles: [],
        equipment: [
            { value: 'fx4', label: 'FX4 Off-Road Package', keywords: ['fx4', 'off-road'] },
            { value: 'tow_pkg', label: 'Trailer Tow Package', keywords: ['trailer tow', 'tow package'] },
            { value: '360_camera', label: '360-Degree Camera', keywords: ['360 camera', 'surround view'] }
        ],
        colors: [
            { value: 'Oxford White', label: 'Oxford White' },
            { value: 'Shadow Black', label: 'Shadow Black' },
            { value: 'Carbonized Gray', label: 'Carbonized Gray' },
            { value: 'Velocity Blue', label: 'Velocity Blue' },
            { value: 'Hot Pepper Red', label: 'Hot Pepper Red' },
            { value: 'Azure Gray', label: 'Azure Gray' },
            { value: 'Cactus Gray', label: 'Cactus Gray' }
        ]
    };

    const escapeConfig = {
        trims: [
            { value: 'Active', label: 'Active' },
            { value: 'ST-Line', label: 'ST-Line' },
            { value: 'ST-Line Select', label: 'ST-Line Select' },
            { value: 'ST-Line Elite', label: 'ST-Line Elite' },
            { value: 'Platinum', label: 'Platinum' },
            { value: 'PHEV', label: 'PHEV' }
        ],
        packages: {
            'Active': [{ value: '200A', label: '200A' }],
            'ST-Line': [{ value: '300A', label: '300A' }],
            'ST-Line Select': [{ value: '301A', label: '301A' }],
            'ST-Line Elite': [{ value: '400A', label: '400A' }, { value: '401A', label: '401A' }],
            'Platinum': [{ value: '500A', label: '500A' }, { value: '501A', label: '501A' }],
            'PHEV': [{ value: '600A', label: '600A' }, { value: '601A', label: '601A' }, { value: '700A', label: '700A' }]
        },
        engines: [
            { value: '1.5L EcoBoost I3', label: '1.5L EcoBoost I3' },
            { value: '2.0L EcoBoost I4', label: '2.0L EcoBoost I4' },
            { value: '2.5L Hybrid I4', label: '2.5L Hybrid I4' },
            { value: '2.5L PHEV I4', label: '2.5L PHEV I4' }
        ],
        drivetrains: [
            { value: 'FWD', label: 'FWD' },
            { value: 'AWD', label: 'AWD' }
        ],
        bodyStyles: [],
        equipment: [
            { value: 'copilot_assist', label: 'Co-Pilot360 Assist', keywords: ['co-pilot360', 'copilot'] },
            { value: 'tow_pkg', label: 'Trailer Tow Package', keywords: ['trailer tow', 'tow package'] },
            { value: '360_camera', label: '360-Degree Camera', keywords: ['360 camera', 'surround view'] }
        ],
        colors: [
            { value: 'Agate Black', label: 'Agate Black' },
            { value: 'Carbonized Gray', label: 'Carbonized Gray' },
            { value: 'Oxford White', label: 'Oxford White' },
            { value: 'Star White', label: 'Star White' },
            { value: 'Rapid Red', label: 'Rapid Red' },
            { value: 'Vapor Blue', label: 'Vapor Blue' },
            { value: 'Space Silver', label: 'Space Silver' }
        ]
    };

    const f150LightningConfig = {
        trims: [
            { value: 'Pro', label: 'Pro' },
            { value: 'XLT', label: 'XLT' },
            { value: 'Flash', label: 'Flash' },
            { value: 'Lariat', label: 'Lariat' },
            { value: 'Platinum', label: 'Platinum' }
        ],
        packages: {
            'Pro': [{ value: '110A', label: '110A' }],
            'XLT': [{ value: '311A', label: '311A' }, { value: '312A', label: '312A' }],
            'Flash': [{ value: '400A', label: '400A' }],
            'Lariat': [{ value: '510A', label: '510A' }, { value: '511A', label: '511A' }],
            'Platinum': [{ value: '710A', label: '710A' }]
        },
        engines: [
            { value: 'Standard Range', label: 'Standard Range' },
            { value: 'Extended Range', label: 'Extended Range' }
        ],
        drivetrains: [
            { value: '4x4', label: '4x4' }
        ],
        bodyStyles: [],
        equipment: [
            { value: 'bluecruise', label: 'BlueCruise', keywords: ['bluecruise', 'hands-free'] },
            { value: 'pro_power', label: 'Pro Power Onboard', keywords: ['pro power'] },
            { value: '360_camera', label: '360-Degree Camera', keywords: ['360 camera', 'surround view'] }
        ],
        colors: [
            { value: 'Oxford White', label: 'Oxford White' },
            { value: 'Agate Black', label: 'Agate Black' },
            { value: 'Antimatter Blue', label: 'Antimatter Blue' },
            { value: 'Carbonized Gray', label: 'Carbonized Gray' },
            { value: 'Iconic Silver', label: 'Iconic Silver' },
            { value: 'Rapid Red', label: 'Rapid Red' },
            { value: 'Space White', label: 'Space White' },
            { value: 'Star White', label: 'Star White' }
        ]
    };

    const transitConfig = {
        trims: [
            { value: 'Transit', label: 'Transit' },
            { value: 'E-Transit', label: 'E-Transit' }
        ],
        packages: {
            'Transit': [{ value: '101A', label: '101A' }],
            'E-Transit': [{ value: '101A', label: '101A' }]
        },
        engines: [
            { value: '3.5L V6', label: '3.5L V6' },
            { value: 'Electric', label: 'Electric' }
        ],
        drivetrains: [
            { value: 'RWD', label: 'RWD' },
            { value: 'AWD', label: 'AWD' }
        ],
        bodyStyles: [],
        equipment: [
            { value: 'tow_pkg', label: 'Trailer Tow Package', keywords: ['trailer tow', 'tow package'] },
            { value: '360_camera', label: '360-Degree Camera', keywords: ['360 camera', 'surround view'] }
        ],
        colors: [
            { value: 'Oxford White', label: 'Oxford White' },
            { value: 'Agate Black', label: 'Agate Black' },
            { value: 'Carbonized Gray', label: 'Carbonized Gray' },
            { value: 'Iconic Silver', label: 'Iconic Silver' }
        ]
    };

    function shouldUseCaseInsensitiveTrimMatching(modelValue) {
        // Models that have trim variations that require case-insensitive matching
        // or partial string matching (e.g., "GT Fastback" should match "GT")
        const caseInsensitiveModels = [
            'mustang-mach-e',
            'maverick',
            'bronco',
            'escape',
            'explorer',
            'mustang'
        ];
        return caseInsensitiveModels.includes(modelValue);
    }

    function normalizePackageCode(packageText) {
        if (!packageText || typeof packageText !== 'string') return '';
        // Look for patterns like "Equipment Group 101A" or "Order Code 600A" and extract just the code
        const match = packageText.match(/(?:Equipment Group|Order Code)\s+([0-9A-Z]+(?:\s+[0-9A-Z]+)*)/i);
        if (match) {
            return match[1]; // Use just the code part like "101A"
        } else {
            // Fallback: try to extract just the code from the first part
            const firstPart = packageText.split(',')[0].trim();
            const codeMatch = firstPart.match(/([0-9A-Z]+(?:\s+[0-9A-Z]+)*)/);
            if (codeMatch && codeMatch[1].length < 10) { // Only use if reasonably short
                return codeMatch[1];
            }
        }
        return packageText; // Return original if no code found
    }

    function getModelConfig(modelValue) {
        if (modelValue === 'f150') return f150Config;
        if (modelValue === 'super-duty') return superDutyConfig;
        if (modelValue === 'explorer') return explorerConfig;
        if (modelValue === 'maverick') return maverickConfig;
        if (modelValue === 'mustang-mach-e') return machEConfig;
        if (modelValue === 'bronco') return broncoConfig;
        if (modelValue === 'bronco-sport') return broncoSportConfig;
        if (modelValue === 'mustang') return mustangConfig;
        if (modelValue === 'expedition') return expeditionConfig;
        if (modelValue === 'ranger') return rangerConfig;
        if (modelValue === 'escape') return escapeConfig;
        if (modelValue === 'f150-lightning') return f150LightningConfig;
        if (modelValue === 'transit') return transitConfig;
        return null;
    }

    function getColorsWithInventory(modelValue) {
        const cfg = getModelConfig(modelValue);
        if (!cfg) return [];
        const baseColors = cfg.colors ? [...cfg.colors] : [];
        const modelVehicles = vehicles.filter(v => getVehicleNormalizedModel(v) === modelValue);
        const inventoryColors = new Set();
        modelVehicles.forEach(v => {
            if (v.exterior) inventoryColors.add(v.exterior.trim());
        });
        inventoryColors.forEach(invColor => {
            const invLower = invColor.toLowerCase();
            const isStandard = baseColors.some(c => invLower.includes(c.value.toLowerCase()) || c.value.toLowerCase().includes(invLower));
            if (!isStandard) {
                baseColors.push({ value: invColor, label: invColor });
            }
        });
        return baseColors;
    }

    function normalizePaintColorFrontend(paint) {
        if (!paint) return '';
        const lower = paint.toLowerCase().trim();

        // Common color normalizations
        if (lower.includes('rapid red')) return 'Rapid Red';
        if (lower.includes('star white')) return 'Star White';
        if (lower.includes('space white')) return 'Space White';
        if (lower.includes('agate black')) return 'Agate Black';
        if (lower.includes('antimatter blue')) return 'Antimatter Blue';
        if (lower.includes('carbonized gray')) return 'Carbonized Gray';
        if (lower.includes('iconic silver')) return 'Iconic Silver';
        if (lower.includes('oxford white')) return 'Oxford White';
        if (lower.includes('marsh gray')) return 'Marsh Gray';
        if (lower.includes('atlas blue')) return 'Atlas Blue';

        // Return original if no normalization matches
        return paint;
    }

    function normalizeEngineFrontend(engine) {
        if (!engine) return '';
        const lower = String(engine).toLowerCase().trim();
        // Normalize separators so "V-6" and "V6" behave the same.
        const cleaned = lower.replace(/[–—-]/g, '').replace(/\s+/g, ' ');
        const compact = cleaned.replace(/\s+/g, '');

        // Engine normalizations - F-150/Lightning
        if (compact.includes('2.7l') && compact.includes('v6')) return '2.7L EcoBoost V6';
        if (compact.includes('2.7l') && compact.includes('ecoboost')) return '2.7L EcoBoost V6';

        // PowerBoost must remain distinct from the normal 3.5L EcoBoost
        if (compact.includes('powerboost') || (compact.includes('3.5l') && compact.includes('hybrid'))) return 'PowerBoost';

        // 3.5L V6 (often shown as "3.5L V-6 cyl") should map to the EcoBoost filter bucket
        if (compact.includes('3.5l') && compact.includes('v6') && compact.includes('highoutput')) return 'High Output';
        if (compact.includes('3.5l') && compact.includes('v6') && compact.includes('ecoboost')) return '3.5L V6 EcoBoost';
        if (compact.includes('3.5l') && compact.includes('v6')) return '3.5L V6 EcoBoost';

        if (compact.includes('5.0l') && compact.includes('v8')) return '5.0L V8';

        // Lightning/EV
        if (compact.includes('dualemotor')) return 'High Output';

        // Maverick engines
        if (compact.includes('2.5l')) return '2.5L Hybrid I4';
        if (compact.includes('2.0l')) return '2.0L EcoBoost I4';

        // Super Duty engines
        if (compact.includes('6.7l') && compact.includes('highoutput')) return '6.7L High Output Power Stroke V8';
        if (compact.includes('6.7l')) return '6.7L Power Stroke V8';
        if (compact.includes('6.8l')) return '6.8L V8 Gas';
        if (compact.includes('7.3l')) return '7.3L V8 Gas';

        // Explorer/Expedition engines
        if (compact.includes('2.3l')) return '2.3L EcoBoost I4';
        if (compact.includes('3.0l')) return '3.0L EcoBoost V6';

        // Bronco engines
        if (compact.includes('2.3l') && !compact.includes('maverick')) return '2.3L EcoBoost I4';
        if (compact.includes('2.7l') && !compact.includes('maverick')) return '2.7L EcoBoost V6';
        if (compact.includes('3.0l') && !compact.includes('maverick')) return '3.0L EcoBoost V6';

        // Bronco Sport engines
        if (compact.includes('1.5l')) return '1.5L EcoBoost I3';

        // Maverick engines (specific patterns removed - handled by general rules above)

        // General 2.0L engines (Bronco Sport, Edge, Escape, etc.)
        if (compact.includes('2.0l')) return '2.0L EcoBoost I4';

        // Mustang engines
        if (compact.includes('5.0l') && compact.includes('v8')) return '5.0L V8';

        // Transit engines
        if (compact.includes('3.5l') && compact.includes('v6')) return '3.5L V6';

        // Mach-E engines
        if (compact.includes('extendedrange')) return 'Extended Range Battery';

        return String(engine);
    }

    function normalizeDrivetrainFrontend(drivetrain) {
        if (!drivetrain) return '';
        const lower = drivetrain.toLowerCase().trim();

        // Match backend normalization in generate_cache.php
        if (lower.includes('4x2')) return '4x2';
        if (lower.includes('rwd')) return 'RWD';
        if (lower.includes('4x4')) return '4x4';
        if (lower.includes('4wd')) return '4WD';
        if (lower.includes('awd')) return 'AWD';
        if (lower.includes('fwd')) return 'FWD';

        return drivetrain;
    }

    function normalizeBodyStyleFrontend(bodyStyle) {
        if (!bodyStyle) return '';
        const lower = bodyStyle.toLowerCase().trim();

        if (lower.includes('regular cab')) return 'Regular Cab';
        if (lower.includes('super cab')) return 'Super Cab';
        if (lower.includes('supercrew')) return 'SuperCrew';
        if (lower.includes('supercab')) return 'Super Cab'; // Handle variations

        return bodyStyle;
    }

    // Get the normalized model value - uses pre-computed value from cache when available
    function getVehicleNormalizedModel(v) {
        // Use pre-computed value from cache if available
        if (v.normalized_model) return v.normalized_model;
        // Fallback to computing it (for data that wasn't cached)
        return normalizeModel(v.model);
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

    function formatFuelEconomy(value) {
        if (value === null || value === undefined || value === '') return '';

        // Try to parse JSON objects/arrays delivered as strings.
        let data = value;
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (/^[{[]/.test(trimmed)) {
                try {
                    data = JSON.parse(trimmed);
                } catch {
                    // fall through with original string
                    data = value;
                }
            }
        }

        // Numeric or numeric-ish values
        const toNum = (v) => {
            if (v === null || v === undefined) return null;
            const n = Number(v);
            return Number.isFinite(n) ? n : null;
        };

        // Handle objects with city/highway/combined keys
        if (data && typeof data === 'object' && !Array.isArray(data)) {
            let city = toNum(data.city ?? data.city_mpg ?? data.cityMileage);
            let hwy = toNum(data.highway ?? data.hwy ?? data.highway_mpg);
            let combo = toNum(data.combined ?? data.comb ?? data.combined_mpg ?? data.avg);

            // Validate and correct MPG data for resiliency
            // Combined should be between city and highway (if both exist)
            if (city !== null && hwy !== null && combo !== null) {
                // If combined is outside the expected range, it might be mislabeled
                if (combo < city || combo > hwy) {
                    // Try to detect common mislabeling patterns
                    if (combo < city && hwy > city) {
                        // Combined is lower than city - might be swapped
                        // Check if swapping would make more sense
                        const avg = Math.round((city + hwy) / 2);
                        if (Math.abs(combo - avg) > Math.abs(city - avg)) {
                            // Swap city and combined
                            [city, combo] = [combo, city];
                        }
                    }
                }
            }

            // If we only have combined and one other value, ensure logical consistency
            if (combo !== null && city !== null && hwy === null) {
                // Only city and combined - combined should be >= city
                if (combo < city) {
                    // Swap them
                    [city, combo] = [combo, city];
                }
            }
            if (combo !== null && hwy !== null && city === null) {
                // Only highway and combined - combined should be <= highway
                if (combo > hwy) {
                    // Swap them
                    [hwy, combo] = [combo, hwy];
                }
            }

            // Format as city/highway/combined MPG
            const mpgValues = [];
            if (city !== null) mpgValues.push(city);
            if (hwy !== null) mpgValues.push(hwy);
            if (combo !== null && mpgValues.length < 3) mpgValues.push(combo);

            if (mpgValues.length >= 2) {
                return mpgValues.join('/') + ' MPG';
            } else if (mpgValues.length === 1) {
                return mpgValues[0] + ' MPG';
            }
            // If we have an object but no valid MPG values, return empty
            return '';
        }

        // Handle arrays of numbers/strings
        if (Array.isArray(data)) {
            const nums = data.map(toNum).filter((n) => n !== null);
            if (nums.length) return nums.join('/') + ' MPG';
            // If we have an array but no valid numbers, return empty
            return '';
        }

        // Plain number
        const numVal = toNum(data);
        if (numVal !== null) return `${numVal} MPG`;

        // If we can't extract valid MPG data, return empty string
        return '';
    }

    // ==================== BOOKMARK FUNCTIONS ====================
        const BOOKMARK_KEY = 'ford_inventory_bookmarks';
    const BOOKMARK_ICON = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" width="18" height="18">
            <path d="M12 21s10-7.5 10-13.5C22 4.46 19.54 2 16.5 2 14.77 2 13.09 2.81 12 4.09 10.91 2.81 9.23 2 7.5 2 4.46 2 2 4.46 2 7.5 2 13.5 12 21 12 21z"/>
        </svg>
    `;

    function getBookmarks() {
        try {
            const raw = localStorage.getItem(BOOKMARK_KEY);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            console.warn('Error loading bookmarks:', e);
            return [];
        }
    }

    function saveBookmarks(bookmarks) {
        try {
            localStorage.setItem(BOOKMARK_KEY, JSON.stringify(bookmarks));
        } catch (e) {
            console.warn('Error saving bookmarks:', e);
        }
    }

    function isBookmarked(vin) {
        return getBookmarks().includes(vin);
    }

    function toggleBookmark(vin) {
        const bookmarks = getBookmarks();
        const idx = bookmarks.indexOf(vin);
        if (idx >= 0) {
            bookmarks.splice(idx, 1);
        } else {
            bookmarks.push(vin);
        }
        saveBookmarks(bookmarks);
        updateBookmarksButton();
    }

    function updateBookmarkButton(button, vin) {
        if (!button) return;
        if (isBookmarked(vin)) {
            button.classList.add('bookmarked');
            button.title = 'Remove from bookmarks';
        } else {
            button.classList.remove('bookmarked');
            button.title = 'Add to bookmarks';
        }
    }

    function updateBookmarksButton() {
        const bookmarksBtn = document.getElementById('bookmarks-button');
        if (!bookmarksBtn) return;
        const count = getBookmarks().length;
        const label = count > 0 ? `Bookmarks (${count})` : 'Bookmarks';
        const countSpan = count > 0 ? `<span class="bookmark-count">${count}</span>` : '';
        bookmarksBtn.innerHTML = `${BOOKMARK_ICON}${countSpan}`;
        bookmarksBtn.setAttribute('aria-label', label);
        bookmarksBtn.title = label;
        bookmarksBtn.classList.toggle('has-count', count > 0);
        bookmarksBtn.classList.toggle('active', window.currentView === 'bookmarks');

        const clearBookmarksBtn = document.getElementById('clear-bookmarks-button');
        if (clearBookmarksBtn) {
            if (window.currentView === 'bookmarks' && count > 0) {
                clearBookmarksBtn.classList.remove('hidden');
            } else {
                clearBookmarksBtn.classList.add('hidden');
            }
        }
    }

    function updateViewToggleButton() {
        const toggleBtn = document.getElementById('view-toggle-button');
        const toggleIcon = document.getElementById('view-toggle-icon');
        const toggleText = document.getElementById('view-toggle-text');
        if (!toggleBtn || !toggleIcon || !toggleText) return;

        // Only update if button is visible (in bookmark view)
        if (!toggleBtn.classList.contains('hidden')) {
            const isTableMode = bookmarkViewMode === 'table';
            toggleIcon.textContent = isTableMode ? 'T' : 'C';
            toggleText.textContent = isTableMode ? 'Cards' : 'Table';
            toggleBtn.classList.toggle('active', isTableMode);
            toggleBtn.title = isTableMode ? 'Switch to card view' : 'Switch to table view';
        }
    }

    function clearAllBookmarks() {
        if (!confirm('Remove all bookmarks?')) return;
        localStorage.removeItem(BOOKMARK_KEY);
        updateBookmarksButton();
        if (window.currentView === 'bookmarks') {
            renderHomepage();
        }
    }

    function showBookmarkedVehicles() {
        const bookmarks = getBookmarks();
        if (bookmarks.length === 0) {
            alert('No bookmarked vehicles yet. Click the ♥ button on vehicle cards to bookmark them.');
            return;
        }

        const wasShowingBookmarks = window.currentView === 'bookmarks';
        window.currentView = wasShowingBookmarks ? 'inventory' : 'bookmarks';
        isHomepage = false;
        updateSortVisibility();

        if (wasShowingBookmarks) {
            // Hide view toggle button and reset view mode when exiting bookmark view
            const viewToggleButton = document.getElementById('view-toggle-button');
            if (viewToggleButton) {
                viewToggleButton.classList.add('hidden');
            }
            bookmarkViewMode = 'cards'; // Reset to cards view
            renderHomepage();
            updateBookmarksButton();
            return;
        }

        headerRight.classList.remove('hidden');
        filtersSidebar.classList.add('hidden');
        mainContent.classList.remove('homepage-main');
        setStockSearchVisible(true);

        // Show view toggle button in bookmark view
        const viewToggleButton = document.getElementById('view-toggle-button');
        if (viewToggleButton) {
            viewToggleButton.classList.remove('hidden');
            updateViewToggleButton(); // Sync button state with current bookmarkViewMode
        }

        renderBookmarkedVehiclesGrid();
    }

    // Initialize bookmarks button once helpers are defined
    updateBookmarksButton();

    function renderBookmarkedVehiclesGrid() {
        const bookmarks = getBookmarks();
        const bookmarkedVehicles = vehicles.filter(vehicle => bookmarks.includes(vehicle.vin));
        filteredVehicles = bookmarkedVehicles;
        displayedCount = 0;
        grid.innerHTML = '';

        if (!filteredVehicles.length) {
            grid.innerHTML = '<div class="no-results">No bookmarked vehicles yet.</div>';
        } else {
            if (bookmarkViewMode === 'table') {
                renderTableView();
            } else {
                renderCards(true);
            }
        }

        if (resultsCount) {
            resultsCount.innerHTML = `<strong>${filteredVehicles.length}</strong> vehicles`;
        }

        activeFiltersContainer.innerHTML = '';
        updateBookmarksButton();
    }

    function setStockSearchVisible(show) {
        if (stockSearchForm) stockSearchForm.style.display = show ? 'flex' : 'none';
        if (stockSearchHint) {
            stockSearchHint.style.display = show ? 'block' : 'none';
            if (!show) stockSearchHint.textContent = '';
        }
    }

    function handleStockSearch(event) {
        if (event && typeof event.preventDefault === 'function') event.preventDefault();
        if (!stockSearchInput) return;
        const term = stockSearchInput.value.trim();
        if (!term) return;

        if (stockSearchHint) stockSearchHint.textContent = '';
        const target = 'https://morgangoins.org/' + encodeURIComponent(term);
        window.location.href = target;
    }

    function getPrice(v) {
        const raw = v.retail_price || '';
        const num = parseFloat(String(raw).replace(/[^0-9.]/g, ''));
        return isFinite(num) ? num : Infinity;
    }

    function calculateDynamicFacets(vehicleSet, model) {
        const facets = {
            trim: {}, package: {}, engine: {}, wheelbase: {}, drivetrain: {}, body_style: {}, equipment: {}, color: {}, status: {}, year: {}, series: {}
        };

        // Ensure we have a valid vehicle set
        if (!Array.isArray(vehicleSet) || vehicleSet.length === 0) {
            console.warn('calculateDynamicFacets: Invalid or empty vehicle set provided');
            return facets;
        }

        // Calculate counts based on provided vehicle set
        let processedCount = 0;
        vehicleSet.forEach(vehicle => {
            if (!vehicle || typeof vehicle !== 'object') return;

            if (vehicle.year) {
                const yearStr = String(vehicle.year);
                facets.year[yearStr] = (facets.year[yearStr] || 0) + 1;
            }
            if (vehicle.trim && typeof vehicle.trim === 'string') {
                let trimKey = vehicle.trim;
                // For Explorer vehicles, group "Active 100A" under "Active" for facet counts
                if (model === 'explorer' && trimKey.toLowerCase().includes('active')) {
                    trimKey = 'Active';
                }
                // For models that use case-insensitive trim matching, normalize the trim key to match config
                if (shouldUseCaseInsensitiveTrimMatching(model)) {
                    const cfg = getModelConfig(model);
                    if (cfg && cfg.trims) {
                        // Find the config trim that matches this vehicle trim
                        let matchingTrim = cfg.trims.find(t =>
                            t.value.toLowerCase() === trimKey.toLowerCase()
                        );

                        // If no exact match, try removing " Fastback" suffix
                        if (!matchingTrim && trimKey.toLowerCase().endsWith(' fastback')) {
                            const baseTrim = trimKey.slice(0, -9).trim(); // Remove " Fastback"
                            matchingTrim = cfg.trims.find(t =>
                                t.value.toLowerCase() === baseTrim.toLowerCase()
                            );
                        }

                        // As fallback, use includes logic (but this should be rare)
                        if (!matchingTrim) {
                            matchingTrim = cfg.trims.find(t =>
                                trimKey.toLowerCase().includes(t.value.toLowerCase())
                            );
                        }

                        if (matchingTrim) {
                            trimKey = matchingTrim.value;
                        }
                    }
                }
                facets.trim[trimKey] = (facets.trim[trimKey] || 0) + 1;
            }
            if (vehicle.equipment_pkg && typeof vehicle.equipment_pkg === 'string') {
                const normalizedPackage = normalizePackageCode(vehicle.equipment_pkg);
                if (normalizedPackage) {
                    facets.package[normalizedPackage] = (facets.package[normalizedPackage] || 0) + 1;
                }
            }
            if (vehicle.engine) {
                // Normalize the vehicle engine using the same logic as filtering
                const normalizedVehicleEngine = normalizeEngineFrontend(String(vehicle.engine));

                // Use the normalized engine for facet counting
                if (normalizedVehicleEngine) {
                    facets.engine[normalizedVehicleEngine] = (facets.engine[normalizedVehicleEngine] || 0) + 1;
                }
            }
            if (vehicle.wheelbase && vehicle.wheelbase !== '') {
                const wheelbaseValue = parseFloat(vehicle.wheelbase);
                const wheelbaseKey = wheelbaseValue.toFixed(1);
                facets.wheelbase[wheelbaseKey] = (facets.wheelbase[wheelbaseKey] || 0) + 1;
            }
            if (vehicle.drive_line) {
                // Cache already stores normalized drivetrain values - use them directly
                facets.drivetrain[vehicle.drive_line] = (facets.drivetrain[vehicle.drive_line] || 0) + 1;
            }
            if (vehicle.body_style) {
                const normalizedBodyStyle = normalizeBodyStyleFrontend(String(vehicle.body_style));
                if (normalizedBodyStyle) {
                    facets.body_style[normalizedBodyStyle] = (facets.body_style[normalizedBodyStyle] || 0) + 1;
                }
            }
            if (vehicle.exterior) {
                const normalizedColor = normalizePaintColorFrontend(String(vehicle.exterior));
                if (normalizedColor) {
                    facets.color[normalizedColor] = (facets.color[normalizedColor] || 0) + 1;
                }
            }

            // Calculate status (in-stock vs in-transit)
            const vehicleStatus = vehicle.stock ? 'in-stock' : 'in-transit';
            facets.status[vehicleStatus] = (facets.status[vehicleStatus] || 0) + 1;

            // Count series based on model field (normalized)
            if (vehicle.model && typeof vehicle.model === 'string') {
                const normalizedModel = String(vehicle.model).trim();
                if (normalizedModel.toLowerCase().includes('chassis')) {
                    // Count chassis vehicles under the "Chassis" series
                    facets.series['Chassis'] = (facets.series['Chassis'] || 0) + 1;
                } else if (normalizedModel === 'Super Duty' && vehicle.trim && String(vehicle.trim).includes('F-250')) {
                    // Count Super Duty vehicles with F-250 trims under F-250
                    facets.series['F-250'] = (facets.series['F-250'] || 0) + 1;
                } else {
                    // Count regular series vehicles under their exact model name
                    facets.series[normalizedModel] = (facets.series[normalizedModel] || 0) + 1;
                }
            }

            // For equipment, we can't count here because equipment options vary by model
            // The counts will be calculated dynamically in renderCheckboxOptions based on model config
            processedCount++;
        });

        console.log(`Facet calculation: Processed ${processedCount} vehicles, generated facets for ${Object.keys(facets).length} categories`);

        return facets;
    }

    function getModelVehicles() {
        if (!selectedFilters.model) {
            console.warn('getModelVehicles: No model selected');
            return [];
        }
        if (!Array.isArray(vehicles)) {
            console.warn('getModelVehicles: vehicles is not an array');
            return [];
        }

        const modelVehicles = vehicles.filter(v => v && v.model && getVehicleNormalizedModel(v) === selectedFilters.model);

        if (modelVehicles.length === 0) {
            console.warn('getModelVehicles: No vehicles found for model', selectedFilters.model, 'from', vehicles.length, 'total vehicles');
        }

        return modelVehicles;
    }

    function getFilteredVehiclesExcluding(filterKey) {
        // Get model vehicles first
        const modelVehicles = getModelVehicles();

        // Apply all filters EXCEPT the specified filterKey
        return modelVehicles.filter(v => {
            // Always check model and status (except when calculating series facets, show totals)
            const vehicleStatus = v.stock ? 'in-stock' : 'in-transit';
            if (filterKey !== 'series' && selectedFilters.status.length && !selectedFilters.status.includes(vehicleStatus)) return false;

            const cfg = getModelConfig(selectedFilters.model);

            if (cfg) {
                    // Apply series filter unless it's the excluded filter
                // Also exclude status filtering for series facets to show total counts
                if (filterKey !== 'series' && selectedFilters.series && selectedFilters.series.length > 0) {
                    const vehicleModel = String(v.model || '').trim();
                    const vehicleTrim = String(v.trim || '').trim();
                    let effectiveModel = vehicleModel;

                    // Handle Super Duty vehicles with F-series trims
                    if (vehicleModel === 'Super Duty' && vehicleTrim.includes('F-250')) {
                        effectiveModel = 'F-250';
                    }

                    if (effectiveModel) {
                        const matchesSeries = selectedFilters.series.some(seriesValue => {
                            const normalizedSeries = String(seriesValue).trim();
                            if (normalizedSeries === 'Chassis') {
                                // Chassis series matches any vehicle with "Chassis" in the model name
                                return effectiveModel.toLowerCase().includes('chassis');
                            } else {
                                // Regular series match exactly and exclude chassis vehicles
                                return effectiveModel.toLowerCase() === normalizedSeries.toLowerCase() &&
                                       !effectiveModel.toLowerCase().includes('chassis');
                            }
                        });
                        if (!matchesSeries) return false;
                    } else {
                        return false;
                    }
                }

                // Apply trim filter unless it's the excluded filter
                if (filterKey !== 'trim' && selectedFilters.trim.length) {
                    const vTrim = v.trim || '';
                    // For models with special trim matching, normalize the vehicle trim to config value
                    let normalizedVehicleTrim = vTrim.toLowerCase();
                    if (shouldUseCaseInsensitiveTrimMatching(selectedFilters.model)) {
                        const cfg = getModelConfig(selectedFilters.model);
                        if (cfg && cfg.trims) {
                            // Apply same normalization logic as facet calculation
                            let matchingTrim = cfg.trims.find(t =>
                                t.value.toLowerCase() === vTrim.toLowerCase()
                            );

                            if (!matchingTrim && vTrim.toLowerCase().endsWith(' fastback')) {
                                const baseTrim = vTrim.slice(0, -9).trim();
                                matchingTrim = cfg.trims.find(t =>
                                    t.value.toLowerCase() === baseTrim.toLowerCase()
                                );
                            }

                            if (!matchingTrim) {
                                matchingTrim = cfg.trims.find(t =>
                                    vTrim.toLowerCase().includes(t.value.toLowerCase())
                                );
                            }

                            if (matchingTrim) {
                                normalizedVehicleTrim = matchingTrim.value.toLowerCase();
                            }
                        }
                    }

                    const matchesTrim = selectedFilters.trim.some(t => {
                        // For Explorer, handle specific trim variants
                        if (selectedFilters.model === 'explorer') {
                            if (t === 'Active' && (vTrim === 'Active' || vTrim === 'Active 100A')) {
                                return true;
                            }
                            return vTrim === t;
                        }
                        // For models with case-insensitive matching
                        if (shouldUseCaseInsensitiveTrimMatching(selectedFilters.model)) {
                            return normalizedVehicleTrim === t.toLowerCase();
                        }
                        return vTrim === t;
                    });
                    if (!matchesTrim) return false;
                }

                // Apply package filter unless it's the excluded filter
                if (filterKey !== 'package' && selectedFilters.package.length) {
                    const vehiclePackageCode = normalizePackageCode(v.equipment_pkg || '');
                    if (!selectedFilters.package.includes(vehiclePackageCode)) return false;
                }

                // Apply engine filter unless it's the excluded filter
                if (filterKey !== 'engine' && selectedFilters.engine.length) {
                    const vehicleEngine = normalizeEngineFrontend(v.engine || '');
                    let engineMatches = false;

                    for (const selectedEngine of selectedFilters.engine) {
                        // Check for exact match first
                        if (vehicleEngine.toLowerCase() === selectedEngine.toLowerCase()) {
                            engineMatches = true;
                            break;
                        }

                        // Check for normalized match
                        const normalizedSelected = normalizeEngineFrontend(selectedEngine);
                        if (normalizedSelected.toLowerCase() === vehicleEngine.toLowerCase()) {
                            engineMatches = true;
                            break;
                        }

                        // Fallback to substring matching for EVs and complex descriptions
                        const powertrainText = [v.engine, v.battery, v.battery_capacity].filter(Boolean).join(' ').toLowerCase();
                        if (powertrainText.includes(selectedEngine.toLowerCase())) {
                            engineMatches = true;
                            break;
                        }
                    }

                    if (!engineMatches) return false;
                }

                // Apply wheelbase filter unless it's the excluded filter
                if (filterKey !== 'wheelbase' && selectedFilters.wheelbase.length) {
                    let vehicleWheelbase = v.wheelbase;
                    if (vehicleWheelbase === null || vehicleWheelbase === undefined || vehicleWheelbase === '') {
                        return false; // No wheelbase data, exclude from filtered results
                    }
                    // Convert to numbers for comparison to handle precision issues
                    const vehicleWbNum = parseFloat(vehicleWheelbase);
                    const hasMatchingWheelbase = selectedFilters.wheelbase.some(selectedWb => {
                        const selectedWbNum = parseFloat(selectedWb);
                        return Math.abs(vehicleWbNum - selectedWbNum) < 0.01; // Allow small precision differences
                    });
                    if (!hasMatchingWheelbase) {
                        return false;
                    }
                }

                // Apply drivetrain filter unless it's the excluded filter
                if (filterKey !== 'drivetrain' && selectedFilters.drivetrain.length) {
                    const vehicleDrivetrain = normalizeDrivetrainFrontend(v.drive_line || '');
                    let drivetrainMatches = false;

                    for (const selectedDrivetrain of selectedFilters.drivetrain) {
                        // Check for exact match first
                        if (vehicleDrivetrain.toLowerCase() === selectedDrivetrain.toLowerCase()) {
                            drivetrainMatches = true;
                            break;
                        }

                        // Check for normalized match
                        const normalizedSelected = normalizeDrivetrainFrontend(selectedDrivetrain);
                        if (normalizedSelected.toLowerCase() === vehicleDrivetrain.toLowerCase()) {
                            drivetrainMatches = true;
                            break;
                        }

                        // Fallback to substring matching
                        const driveText = (v.drive_line || '').toLowerCase();
                        if (driveText.includes(selectedDrivetrain.toLowerCase())) {
                            drivetrainMatches = true;
                            break;
                        }
                    }

                    if (!drivetrainMatches) return false;
                }

                // Apply body_style filter unless it's the excluded filter
                if (filterKey !== 'body_style' && selectedFilters.body_style.length) {
                    const vehicleBodyStyle = normalizeBodyStyleFrontend(v.body_style || '');
                    let bodyStyleMatches = false;

                    for (const selectedBodyStyle of selectedFilters.body_style) {
                        // Check for exact match first
                        if (vehicleBodyStyle.toLowerCase() === selectedBodyStyle.toLowerCase()) {
                            bodyStyleMatches = true;
                            break;
                        }

                        // Check for normalized match
                        const normalizedSelected = normalizeBodyStyleFrontend(selectedBodyStyle);
                        if (normalizedSelected.toLowerCase() === vehicleBodyStyle.toLowerCase()) {
                            bodyStyleMatches = true;
                            break;
                        }

                        // Fallback to substring matching
                        const bodyText = (v.body_style || '').toLowerCase();
                        if (bodyText.includes(selectedBodyStyle.toLowerCase())) {
                            bodyStyleMatches = true;
                            break;
                        }
                    }

                    if (!bodyStyleMatches) return false;
                }

                // Apply equipment filter unless it's the excluded filter
                if (filterKey !== 'equipment' && selectedFilters.equipment.length) {
                    // Extract equipment descriptions for filtering
                    const equipmentDescriptions = (v.optional_equipment || []).map(item =>
                        typeof item === 'object' && item.description ? item.description.toLowerCase() :
                        typeof item === 'string' ? item.toLowerCase() : ''
                    ).join(' ');
                    const optText = equipmentDescriptions;

                    const hasRunningBoardsByTrimOrPkg = () => {
                        const isF150TargetYears = ['2025', '2026'].includes(String(v.year)) && getVehicleNormalizedModel(v) === 'f150';
                        if (!isF150TargetYears) return false;
                        const pkg = String(v.equipment_pkg || '').toUpperCase();
                        const pkgMatch = ['201A', '301A', '302A', '303A', '501A', '502A'].includes(pkg);
                        const trim = (v.trim || '').toLowerCase();
                        const trimMatch = ['king ranch', 'platinum', 'tremor', 'raptor'].some(t => trim.includes(t));
                        return pkgMatch || trimMatch;
                    };

                    if (!selectedFilters.equipment.every(eq => {
                        const optionCfg = (cfg.equipment || []).find(e => e.value === eq);
                        const keywordsMatch = optionCfg && optionCfg.keywords.some(kw => optText.includes(kw.toLowerCase()));
                        if (eq === 'running_boards' && hasRunningBoardsByTrimOrPkg()) return true;
                        return keywordsMatch;
                    })) return false;
                }

                // Apply color filter unless it's the excluded filter
                if (filterKey !== 'color' && selectedFilters.color.length) {
                    const vehicleColor = (v.exterior || '').trim();
                    let colorMatches = false;

                    for (const selectedColor of selectedFilters.color) {
                        // Check for exact match first
                        if (vehicleColor.toLowerCase() === selectedColor.toLowerCase()) {
                            colorMatches = true;
                            break;
                        }

                        // Check for normalized color match
                        const normalizedVehicleColor = normalizePaintColorFrontend(vehicleColor);
                        if (normalizedVehicleColor.toLowerCase() === selectedColor.toLowerCase()) {
                            colorMatches = true;
                            break;
                        }

                        // Check for variation matches using color mapping if available
                        if (window.colorMapping) {
                            const canonicalColors = window.colorMapping.canonical_colors || {};
                            for (const [canonical, data] of Object.entries(canonicalColors)) {
                                const variations = data.variations || [];
                                // If selected color is a variation or canonical name, check if vehicle color matches any variation
                                if (variations.includes(selectedColor) || canonical === selectedColor) {
                                    if (variations.includes(vehicleColor) || canonical === vehicleColor) {
                                        colorMatches = true;
                                        break;
                                    }
                                }
                            }
                            if (colorMatches) break;
                        } else {
                            // Fallback to partial matching if no color mapping available
                            if (vehicleColor.toLowerCase().includes(selectedColor.toLowerCase())) {
                                colorMatches = true;
                                break;
                            }
                        }
                    }

                    if (!colorMatches) return false;
                }
            }

            // Apply year filter unless it's the excluded filter
            if (filterKey !== 'year' && selectedFilters.year.length && !selectedFilters.year.includes(String(v.year))) return false;

            // Apply exclude filters (always applied, regardless of filterKey)
            if (excludeFilters.year.length && excludeFilters.year.includes(String(v.year))) return false;
            if (excludeFilters.status.length && excludeFilters.status.includes(vehicleStatus)) return false;

            if (cfg) {
                // Apply exclude series filter
                if (excludeFilters.series.length > 0) {
                    const vehicleModel = String(v.model || '').trim();
                    const vehicleTrim = String(v.trim || '').trim();
                    let effectiveModel = vehicleModel;

                    // Handle Super Duty vehicles with F-series trims
                    if (vehicleModel === 'Super Duty' && vehicleTrim.includes('F-250')) {
                        effectiveModel = 'F-250';
                    }

                    if (effectiveModel) {
                        const excludedSeries = excludeFilters.series.some(seriesValue => {
                            const normalizedSeries = String(seriesValue).trim();
                            if (normalizedSeries === 'Chassis') {
                                // Chassis series matches any vehicle with "Chassis" in the model name
                                return effectiveModel.toLowerCase().includes('chassis');
                            } else {
                                // Regular series match exactly and exclude chassis vehicles
                                return effectiveModel.toLowerCase() === normalizedSeries.toLowerCase() &&
                                       !effectiveModel.toLowerCase().includes('chassis');
                            }
                        });
                        if (excludedSeries) return false;
                    } else {
                        // If vehicle has no effective model and series exclude filters are active, exclude it
                        return false;
                    }
                }

                // Apply exclude trim filter
                if (excludeFilters.trim.length) {
                    const vTrim = v.trim || '';
                    // For models with special trim matching, normalize the vehicle trim to config value
                    let normalizedVehicleTrim = vTrim.toLowerCase();
                    if (shouldUseCaseInsensitiveTrimMatching(selectedFilters.model)) {
                        const cfg = getModelConfig(selectedFilters.model);
                        if (cfg && cfg.trims) {
                            // Apply same normalization logic as facet calculation
                            let matchingTrim = cfg.trims.find(t =>
                                t.value.toLowerCase() === vTrim.toLowerCase()
                            );

                            if (!matchingTrim && vTrim.toLowerCase().endsWith(' fastback')) {
                                const baseTrim = vTrim.slice(0, -9).trim();
                                matchingTrim = cfg.trims.find(t =>
                                    t.value.toLowerCase() === baseTrim.toLowerCase()
                                );
                            }

                            if (!matchingTrim) {
                                matchingTrim = cfg.trims.find(t =>
                                    vTrim.toLowerCase().includes(t.value.toLowerCase())
                                );
                            }

                            if (matchingTrim) {
                                normalizedVehicleTrim = matchingTrim.value.toLowerCase();
                            }
                        }
                    }

                    const excludedTrim = excludeFilters.trim.some(t => {
                        const tgt = t.toLowerCase();
                        if (shouldUseCaseInsensitiveTrimMatching(selectedFilters.model)) {
                            return normalizedVehicleTrim === tgt;
                        }
                        return vTrim.toLowerCase() === tgt;
                    });
                    if (excludedTrim) return false;
                }

                // Apply exclude package filter
                if (excludeFilters.package.length) {
                    const vehiclePackageCode = normalizePackageCode(v.equipment_pkg || '');
                    if (excludeFilters.package.includes(vehiclePackageCode)) return false;
                }

                // Apply exclude engine filter
                if (excludeFilters.engine.length) {
                    const vehicleEngine = normalizeEngineFrontend(v.engine || '');
                    let engineExcluded = false;

                    for (const excludedEngine of excludeFilters.engine) {
                        // Check for exact match first
                        if (vehicleEngine.toLowerCase() === excludedEngine.toLowerCase()) {
                            engineExcluded = true;
                            break;
                        }

                        // Check for normalized match
                        const normalizedExcluded = normalizeEngineFrontend(excludedEngine);
                        if (normalizedExcluded.toLowerCase() === vehicleEngine.toLowerCase()) {
                            engineExcluded = true;
                            break;
                        }

                        // Fallback to substring matching for EVs and complex descriptions
                        const powertrainText = [v.engine, v.battery, v.battery_capacity].filter(Boolean).join(' ').toLowerCase();
                        if (powertrainText.includes(excludedEngine.toLowerCase())) {
                            engineExcluded = true;
                            break;
                        }
                    }

                    if (engineExcluded) return false;
                }

                // Apply exclude wheelbase filter
                if (excludeFilters.wheelbase.length) {
                    let vehicleWheelbase = v.wheelbase;
                    if (vehicleWheelbase !== null && vehicleWheelbase !== undefined && vehicleWheelbase !== '') {
                        // Convert to numbers for comparison to handle precision issues
                        const vehicleWbNum = parseFloat(vehicleWheelbase);
                        const hasExcludedWheelbase = excludeFilters.wheelbase.some(excludedWb => {
                            const excludedWbNum = parseFloat(excludedWb);
                            return Math.abs(vehicleWbNum - excludedWbNum) < 0.01; // Allow small precision differences
                        });
                        if (hasExcludedWheelbase) {
                            return false;
                        }
                    }
                }

                // Apply exclude drivetrain filter
                if (excludeFilters.drivetrain.length) {
                    const vehicleDrivetrain = normalizeDrivetrainFrontend(v.drive_line || '');
                    let drivetrainExcluded = false;

                    for (const excludedDrivetrain of excludeFilters.drivetrain) {
                        // Check for exact match first
                        if (vehicleDrivetrain.toLowerCase() === excludedDrivetrain.toLowerCase()) {
                            drivetrainExcluded = true;
                            break;
                        }

                        // Check for normalized match
                        const normalizedExcluded = normalizeDrivetrainFrontend(excludedDrivetrain);
                        if (normalizedExcluded.toLowerCase() === vehicleDrivetrain.toLowerCase()) {
                            drivetrainExcluded = true;
                            break;
                        }

                        // Fallback to substring matching
                        const driveText = (v.drive_line || '').toLowerCase();
                        if (driveText.includes(excludedDrivetrain.toLowerCase())) {
                            drivetrainExcluded = true;
                            break;
                        }
                    }

                    if (drivetrainExcluded) return false;
                }

                // Apply exclude body_style filter
                if (excludeFilters.body_style.length) {
                    const vehicleBodyStyle = normalizeBodyStyleFrontend(v.body_style || '');
                    let bodyStyleExcluded = false;

                    for (const excludedBodyStyle of excludeFilters.body_style) {
                        // Check for exact match first
                        if (vehicleBodyStyle.toLowerCase() === excludedBodyStyle.toLowerCase()) {
                            bodyStyleExcluded = true;
                            break;
                        }

                        // Check for normalized match
                        const normalizedExcluded = normalizeBodyStyleFrontend(excludedBodyStyle);
                        if (normalizedExcluded.toLowerCase() === vehicleBodyStyle.toLowerCase()) {
                            bodyStyleExcluded = true;
                            break;
                        }

                        // Fallback to substring matching
                        const bodyText = (v.body_style || '').toLowerCase();
                        if (bodyText.includes(excludedBodyStyle.toLowerCase())) {
                            bodyStyleExcluded = true;
                            break;
                        }
                    }

                    if (bodyStyleExcluded) return false;
                }

                // Apply exclude equipment filter
                if (excludeFilters.equipment.length) {
                    // Extract equipment descriptions for filtering
                    const equipmentDescriptions = (v.optional_equipment || []).map(item =>
                        typeof item === 'object' && item.description ? item.description.toLowerCase() :
                        typeof item === 'string' ? item.toLowerCase() : ''
                    ).join(' ');
                    const optText = equipmentDescriptions;

                    const hasRunningBoardsByTrimOrPkg = () => {
                        const isF150TargetYears = ['2025', '2026'].includes(String(v.year)) && getVehicleNormalizedModel(v) === 'f150';
                        if (!isF150TargetYears) return false;
                        const pkg = String(v.equipment_pkg || '').toUpperCase();
                        const pkgMatch = ['201A', '301A', '302A', '303A', '501A', '502A'].includes(pkg);
                        const trim = (v.trim || '').toLowerCase();
                        const trimMatch = ['king ranch', 'platinum', 'tremor', 'raptor'].some(t => trim.includes(t));
                        return pkgMatch || trimMatch;
                    };

                    // Check if any excluded equipment matches
                    const hasExcludedEquipment = excludeFilters.equipment.some(eq => {
                        const optionCfg = (cfg.equipment || []).find(e => e.value === eq);
                        const keywordsMatch = optionCfg && optionCfg.keywords.some(kw => optText.includes(kw.toLowerCase()));
                        if (eq === 'running_boards' && hasRunningBoardsByTrimOrPkg()) return true;
                        return keywordsMatch;
                    });
                    if (hasExcludedEquipment) return false;
                }

                // Apply exclude color filter
                if (excludeFilters.color.length) {
                    const vehicleColor = (v.exterior || '').trim();
                    let colorExcluded = false;

                    for (const excludedColor of excludeFilters.color) {
                        // Check for exact match first
                        if (vehicleColor.toLowerCase() === excludedColor.toLowerCase()) {
                            colorExcluded = true;
                            break;
                        }

                        // Check for normalized color match
                        const normalizedVehicleColor = normalizePaintColorFrontend(vehicleColor);
                        if (normalizedVehicleColor.toLowerCase() === excludedColor.toLowerCase()) {
                            colorExcluded = true;
                            break;
                        }

                        // Check for variation matches using color mapping if available
                        if (window.colorMapping) {
                            const canonicalColors = window.colorMapping.canonical_colors || {};
                            for (const [canonical, data] of Object.entries(canonicalColors)) {
                                const variations = data.variations || [];
                                // If excluded color is a variation or canonical name, check if vehicle color matches any variation
                                if (variations.includes(excludedColor) || canonical === excludedColor) {
                                    if (variations.includes(vehicleColor) || canonical === vehicleColor) {
                                        colorExcluded = true;
                                        break;
                                    }
                                }
                            }
                            if (colorExcluded) break;
                        } else {
                            // Fallback to partial matching if no color mapping available
                            if (vehicleColor.toLowerCase().includes(excludedColor.toLowerCase())) {
                                colorExcluded = true;
                                break;
                            }
                        }
                    }

                    if (colorExcluded) return false;
                }
            }

            return true;
        });
    }

    function renderCheckboxOptions(container, options, filterKey, showDesc) {
        if (!container) return;
        container.innerHTML = '';
        
        // Ensure options is an array
        if (!options || !Array.isArray(options)) {
            options = [];
        }

        // Update the filter title (remove count display)
        const sectionId = filterKey.replace('_', '-') + '-section';
        const titleEl = document.querySelector('#' + sectionId + ' .filter-title');
        if (titleEl) {
            // Get the base title text (first word before any count)
            const titleWords = titleEl.textContent.trim().split(' ');
            const baseTitle = titleWords[0]; // e.g., "Trim", "Engine", etc.

            // Clear existing content and rebuild without count
            titleEl.innerHTML = baseTitle;

            // Add the clear button if it exists
            const clearButtonId = 'clear-' + filterKey.replace('_', '-');
            const existingClear = document.getElementById(clearButtonId);
            if (existingClear) {
                titleEl.appendChild(existingClear);
            }
        }

        // For facet calculation, use vehicles filtered by all OTHER filters (excluding this filter type)
        // This allows users to see available options in other filters even when some options are selected
        let vehicleSet = getFilteredVehiclesExcluding(filterKey);

        // Ensure we have a valid vehicle set
        if (!vehicleSet || vehicleSet.length === 0) {
            console.warn('Facet calculation: Empty vehicle set, using model vehicles as fallback', {
                filterKey,
                model: selectedFilters.model,
                totalVehicles: vehicles ? vehicles.length : 0
            });
            vehicleSet = getModelVehicles();
        }

        // Final fallback - if we still don't have vehicles, create empty facets
        if (!vehicleSet || vehicleSet.length === 0) {
            console.error('Facet calculation: No vehicles available for facet calculation', {
                filterKey,
                model: selectedFilters.model
            });
            vehicleSet = [];
        }

        let currentFacets = calculateDynamicFacets(vehicleSet, selectedFilters.model);

        // For excluded options, calculate original counts (before exclude filters) so users can see what they were excluding
        let originalFacets = null;
        if (excludeFilters[filterKey] && excludeFilters[filterKey].length > 0) {
            // Temporarily clear exclude filters for this filterKey to get original counts
            const savedExcludeFilters = [...excludeFilters[filterKey]];
            excludeFilters[filterKey] = [];
            const originalVehicleSet = getFilteredVehiclesExcluding(filterKey);
            if (originalVehicleSet && originalVehicleSet.length > 0) {
                originalFacets = calculateDynamicFacets(originalVehicleSet, selectedFilters.model);
            }
            // Restore exclude filters
            excludeFilters[filterKey] = savedExcludeFilters;
        }

        // Special handling for equipment: calculate counts based on keywords matching
        if (filterKey === 'equipment' && options.length > 0) {
            currentFacets = currentFacets || {};
            currentFacets.equipment = currentFacets.equipment || {};

            // Calculate equipment counts by checking keyword matches
            options.forEach(opt => {
                let count = 0;
                vehicleSet.forEach(vehicle => {
                    if (vehicle.optional_equipment && Array.isArray(vehicle.optional_equipment)) {
                        // Extract equipment descriptions
                        const descriptions = vehicle.optional_equipment.map(item =>
                            typeof item === 'object' && item.description ? item.description.toLowerCase() :
                            typeof item === 'string' ? item.toLowerCase() : ''
                        ).join(' ');

                        // Check if any keywords match
                        const hasMatch = opt.keywords && opt.keywords.some(kw =>
                            descriptions.includes(kw.toLowerCase())
                        );
                        if (hasMatch) count++;
                    }
                });
                currentFacets.equipment[opt.value] = count;
            });
        }

        // Fallback to static facets if dynamic calculation fails
        if (!currentFacets || Object.keys(currentFacets).length === 0) {
            console.warn('Using fallback static facets for', filterKey, '- dynamic calculation returned empty');
            currentFacets = facetCounts && facetCounts[filterKey] ? { [filterKey]: facetCounts[filterKey] } : {};
        }

        options.forEach(opt => {
            const label = document.createElement('label');
            const isExcluded = excludeFilters[filterKey].includes(opt.value);
            const isIncluded = selectedFilters[filterKey].includes(opt.value);
            label.className = 'filter-option' + (isExcluded ? ' filter-option-excluded' : '');
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.value = opt.value;
            input.checked = isIncluded || isExcluded; // Show checked if in either include or exclude mode

            let skipNextChange = false;

            const toggleExclude = () => {
                // Always remove from include filters when entering exclude mode (case-insensitive)
                selectedFilters[filterKey] = selectedFilters[filterKey].filter(v => v.toLowerCase() !== String(opt.value).toLowerCase());

                if (excludeFilters[filterKey].includes(opt.value)) {
                    // Remove from exclude filters
                    excludeFilters[filterKey] = excludeFilters[filterKey].filter(v => v !== opt.value);
                    label.classList.remove('filter-option-excluded');
                } else {
                    // Add to exclude filters
                    excludeFilters[filterKey].push(opt.value);
                    label.classList.add('filter-option-excluded');
                }

                // Ensure the checkbox visual matches the exclude state (unchecked but crossed out)
                skipNextChange = true;
                input.checked = false;

                updateFilterVisibility();
                applyFilters();
            };

            // Single click: toggle include/exclude
            input.addEventListener('change', () => {
                if (skipNextChange) {
                    skipNextChange = false;
                    return;
                }

                // If currently excluded, a user click to check it should remove the exclude and add include.
                const wasExcluded = excludeFilters[filterKey].includes(opt.value);
                if (wasExcluded && input.checked) {
                    excludeFilters[filterKey] = excludeFilters[filterKey].filter(v => v !== opt.value);
                    label.classList.remove('filter-option-excluded');
                } else if (wasExcluded && !input.checked) {
                    // Ignore uncheck while excluded (handled by double-click handler)
                    input.checked = false;
                    return;
                }

                if (input.checked) {
                    // If checked, add to include filters
                    if (!selectedFilters[filterKey].includes(opt.value)) {
                        selectedFilters[filterKey].push(opt.value);
                    }
                } else {
                    // If unchecked, remove from include filters
                    selectedFilters[filterKey] = selectedFilters[filterKey].filter(v => v.toLowerCase() !== String(opt.value).toLowerCase());
                }
                updateFilterVisibility();
                applyFilters();
            });

            // Double click: toggle exclude mode
            label.addEventListener('dblclick', (e) => {
                e.preventDefault();
                e.stopPropagation();
                toggleExclude();
            });

            // Support double click on the checkbox itself as well
            input.addEventListener('dblclick', (e) => {
                e.preventDefault();
                e.stopPropagation();
                toggleExclude();
            });

            const span = document.createElement('span');
            span.className = 'filter-option-label';
            // For excluded options, use the original count (before exclusion) so users can see what they were excluding
            let count;
            if (isExcluded && originalFacets && originalFacets[filterKey] && typeof originalFacets[filterKey][opt.value] === 'number') {
                count = originalFacets[filterKey][opt.value];
            } else if (currentFacets && currentFacets[filterKey] && typeof currentFacets[filterKey][opt.value] === 'number') {
                count = currentFacets[filterKey][opt.value];
            } else {
                count = 0;
            }
            span.innerHTML = opt.label + '<span class="filter-option-count">' + count + '</span>';
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
            ['year', 'series', 'trim', 'engine', 'drivetrain', 'status', 'body-style', 'equipment', 'color', 'package'].forEach(id => {
                document.getElementById(id + '-section').style.display = 'none';
            });
            return;
        }
        
        const model = selectedFilters.model;
        const isF150 = model === 'f150';
        const isSuperDuty = model === 'super-duty';
        const isExplorer = model === 'explorer';
        const isMaverick = model === 'maverick';
        const isMachE = model === 'mustang-mach-e';
        const isBronco = model === 'bronco';
        const isBroncoSport = model === 'bronco-sport';
        const isMustang = model === 'mustang';
        const isExpedition = model === 'expedition';
        const isRanger = model === 'ranger';
        const isEscape = model === 'escape';
        const isF150Lightning = model === 'f150-lightning';
        const isTransit = model === 'transit';
        const hasTrimSelected = selectedFilters.trim.length > 0;

        const showFilters = isF150 || isSuperDuty || isExplorer || isMaverick || isMachE || isBronco || isBroncoSport || isMustang || isExpedition || isRanger || isEscape || isF150Lightning || isTransit;

        // Always show year section when a model is selected
        document.getElementById('year-section').style.display = 'block';
        updateYearOptions();

        // Show series section for Super Duty
        document.getElementById('series-section').style.display = isSuperDuty ? 'block' : 'none';

        ['trim', 'engine', 'wheelbase', 'drivetrain', 'status', 'equipment', 'color'].forEach(id => {
            document.getElementById(id + '-section').style.display = showFilters ? 'block' : 'none';
        });
        // Package section: require trim selection for these models
        const shouldShowPackage = (isF150 && hasTrimSelected) || (isSuperDuty && hasTrimSelected) || (isExplorer && hasTrimSelected) || (isMaverick && hasTrimSelected) || (isMachE && hasTrimSelected) || (isBronco && hasTrimSelected) || (isBroncoSport && hasTrimSelected) || (isMustang && hasTrimSelected) || (isExpedition && hasTrimSelected) || (isRanger && hasTrimSelected) || (isEscape && hasTrimSelected) || (isF150Lightning && hasTrimSelected) || (isTransit && hasTrimSelected);
        document.getElementById('package-section').style.display = shouldShowPackage ? 'block' : 'none';
        // Rename Engine section to Battery for Mach-E
        const engineTitle = document.querySelector('#engine-section .filter-title');
        if (engineTitle) {
            engineTitle.childNodes[0].nodeValue = isMachE ? 'Battery ' : 'Engine ';
        }

        if (showFilters) {
            const cfg = getModelConfig(model);
            if (!cfg) return; // Should not happen if showFilters is true

            const wheelbaseSection = document.getElementById('wheelbase-section');
            if (wheelbaseSection) wheelbaseSection.style.display = (cfg.wheelbases && cfg.wheelbases.length) ? 'block' : 'none';
            const drivetrainSection = document.getElementById('drivetrain-section');
            if (drivetrainSection) drivetrainSection.style.display = (cfg.drivetrains && cfg.drivetrains.length) ? 'block' : 'none';
            
            renderCheckboxOptions(document.getElementById('trim-options'), cfg.trims || [], 'trim');

            // Render series options for Super Duty
            if (cfg.series) {
                renderCheckboxOptions(document.getElementById('series-options'), cfg.series, 'series');
            }

            // Packages filtered by selected trims if any
            let pkgs = [];
            const packages = cfg.packages || {};
            if (selectedFilters.trim.length === 0) {
                Object.values(packages).forEach(p => pkgs = pkgs.concat(p));
            } else {
                selectedFilters.trim.forEach(t => { if (packages[t]) pkgs = pkgs.concat(packages[t]); });
            }
            pkgs = pkgs.filter((p, i, arr) => arr.findIndex(x => x.value === p.value) === i);
            selectedFilters.package = selectedFilters.package.filter(p => pkgs.some(x => x.value === p));
            renderCheckboxOptions(document.getElementById('package-options'), pkgs, 'package');

            renderCheckboxOptions(document.getElementById('engine-options'), cfg.engines || [], 'engine');
            renderCheckboxOptions(document.getElementById('wheelbase-options'), cfg.wheelbases || [], 'wheelbase');
            renderCheckboxOptions(document.getElementById('drivetrain-options'), cfg.drivetrains || [], 'drivetrain');
            renderCheckboxOptions(document.getElementById('status-options'), statusOptions || [], 'status');
            renderCheckboxOptions(document.getElementById('body-style-options'), cfg.bodyStyles || [], 'body_style');
            renderCheckboxOptions(document.getElementById('equipment-options'), cfg.equipment || [], 'equipment');
            const allColors = getColorsWithInventory(model);
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
        
        // Helper to create a tag with text + close button without relying on innerHTML
        const createTag = (text, className, onRemove) => {
            const tag = document.createElement('span');
            tag.className = className;

            const labelSpan = document.createElement('span');
            labelSpan.textContent = text;
            tag.appendChild(labelSpan);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = '×';
            btn.onclick = onRemove;
            tag.appendChild(btn);

            return tag;
        };

        // Add "Back to all models" link when viewing a specific model
        if (selectedFilters.model && !isHomepage) {
            const modelLabel = modelConfig.find(m => m.value === selectedFilters.model)?.displayName || selectedFilters.model;
            const backLink = createTag('Model: ' + modelLabel, 'active-filter-tag', () => renderHomepage());
            activeFiltersContainer.appendChild(backLink);
        }
        
        // Labels for active filter tags - must include ALL filter types that support exclude filters
        // If a filter type supports exclude filters but is missing from this object, exclude tags won't be shown
        const labels = { year: 'Year', series: 'Series', trim: 'Trim', package: 'Pkg', engine: selectedFilters.model === 'mustang-mach-e' ? 'Battery' : 'Engine', wheelbase: 'Wheelbase', drivetrain: 'Drive', body_style: 'Body Style', rear_axle: 'Rear Axle', equipment: 'Equip', color: 'Color', status: 'Status' };
        let hasFilters = false;
        Object.keys(labels).forEach(key => {
            selectedFilters[key].forEach(val => {
                hasFilters = true;
                let label = val;
                if (key === 'equipment') {
                    const cfg = getModelConfig(selectedFilters.model)?.equipment || [];
                    const found = cfg.find(e => e.value === val);
                    if (found) label = found.label;
                }
                const tag = createTag(
                    `${labels[key]}: ${label}`,
                    'active-filter-tag',
                    () => {
                        selectedFilters[key] = selectedFilters[key].filter(v => v !== val);
                        if (key === 'year') { updateYearOptions(); document.getElementById('clear-year').style.display = selectedFilters.year.length ? 'inline' : 'none'; }
                        updateFilterVisibility();
                        applyFilters();
                    }
                );
                activeFiltersContainer.appendChild(tag);
            });
            // Add exclude filters with different styling
            excludeFilters[key].forEach(val => {
                hasFilters = true;
                let label = val;
                if (key === 'equipment') {
                    const cfg = getModelConfig(selectedFilters.model)?.equipment || [];
                    const found = cfg.find(e => e.value === val);
                    if (found) label = found.label;
                }
                const tag = createTag(
                    `${labels[key]}: ${label}`,
                    'active-filter-tag exclude-filter-tag',
                    () => {
                        excludeFilters[key] = excludeFilters[key].filter(v => v !== val);
                        updateFilterVisibility();
                        applyFilters();
                    }
                );
                activeFiltersContainer.appendChild(tag);
            });
        });
        if (hasFilters) {
            const clear = document.createElement('span');
            clear.className = 'clear-all-filters';
            clear.textContent = 'Clear all';
            clear.onclick = () => {
                ['year', 'series', 'trim', 'package', 'engine', 'wheelbase', 'drivetrain', 'body_style', 'rear_axle', 'equipment', 'color', 'status'].forEach(k => {
                    selectedFilters[k] = [];
                    excludeFilters[k] = [];
                });
                updateFilterVisibility(); applyFilters();
            };
            activeFiltersContainer.appendChild(clear);
        }
    }

      function updateYearOptions() {
          try {
              // Check if vehicles data is loaded
              if (!Array.isArray(vehicles) || vehicles.length === 0) {
                  console.warn('updateYearOptions: Vehicles data not loaded yet, skipping year options update');
                  return;
              }

              // Always show all years available for the selected model, even if count is 0 under current filters
              const modelVehicles = getModelVehicles();
              if (!modelVehicles || modelVehicles.length === 0) {
                  console.warn('updateYearOptions: No model vehicles found, skipping year options update');
                  return;
              }

              const allYears = [...new Set(modelVehicles.map(v => v.year).filter(Boolean))].sort((a, b) => b - a);
              if (allYears.length === 0) {
                  console.warn('updateYearOptions: No years found in model vehicles, skipping update');
                  return;
              }

              const yearOptions = allYears.map(year => ({
                  value: year.toString(),
                  label: year.toString()
              }));

              const yearContainer = document.getElementById('year-options');
              if (!yearContainer) {
                  console.error('updateYearOptions: year-options container not found');
                  return;
              }

              renderCheckboxOptions(yearContainer, yearOptions, 'year');
          } catch (error) {
              console.error('updateYearOptions: Error updating year options', error);
              // Don't leave the year options in a broken state - try to restore basic options
              try {
                  const yearContainer = document.getElementById('year-options');
                  if (yearContainer && selectedFilters.model) {
                      // Fallback: show known years for the model
                      const knownYears = {
                          'f150': [2026, 2025],
                          'super-duty': [2025, 2024],
                          'explorer': [2025],
                          'maverick': [2025, 2024],
                          'escape': [2025],
                          'bronco': [2025, 2024],
                          'bronco-sport': [2025, 2024],
                          'mustang': [2025, 2024],
                          'expedition': [2025],
                          'ranger': [2025],
                          'mustang-mach-e': [2025, 2024],
                          'f150-lightning': [2025, 2024],
                          'transit': [2025, 2024]
                      };
                      const fallbackYears = knownYears[selectedFilters.model] || [2025];
                      const fallbackOptions = fallbackYears.map(year => ({
                          value: year.toString(),
                          label: year.toString()
                      }));
                      renderCheckboxOptions(yearContainer, fallbackOptions, 'year');
                  }
              } catch (fallbackError) {
                  console.error('updateYearOptions: Fallback also failed', fallbackError);
              }
          }
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
            if (excludeFilters.year.length && excludeFilters.year.includes(String(v.year))) return false;
            if (getVehicleNormalizedModel(v) !== selectedFilters.model) return false;
            const vehicleStatus = v.stock ? 'in-stock' : 'in-transit';
            if (selectedFilters.status.length && !selectedFilters.status.includes(vehicleStatus)) return false;
            if (excludeFilters.status.length && excludeFilters.status.includes(vehicleStatus)) return false;

            const cfg = getModelConfig(selectedFilters.model);

            if (cfg) {
                // Apply series filter for Super Duty (only when series are selected)
                if (selectedFilters.series && selectedFilters.series.length > 0) {
                    const vehicleModel = String(v.model || '').trim();
                    const vehicleTrim = String(v.trim || '').trim();
                    let effectiveModel = vehicleModel;

                    // Handle Super Duty vehicles with F-series trims
                    if (vehicleModel === 'Super Duty' && vehicleTrim.includes('F-250')) {
                        effectiveModel = 'F-250';
                    }

                    if (effectiveModel) {
                        // Check if effective model matches any selected series
                        const matchesSeries = selectedFilters.series.some(seriesValue => {
                            const normalizedSeries = String(seriesValue).trim();
                            if (normalizedSeries === 'Chassis') {
                                // Chassis series matches any vehicle with "Chassis" in the model name
                                return effectiveModel.toLowerCase().includes('chassis');
                            } else {
                                // Regular series match exactly and exclude chassis vehicles
                                return effectiveModel.toLowerCase() === normalizedSeries.toLowerCase() &&
                                       !effectiveModel.toLowerCase().includes('chassis');
                            }
                        });
                        if (!matchesSeries) {
                            return false;
                        }

                        // Check exclude series filter
                        const excludedSeries = excludeFilters.series.some(seriesValue => {
                            const normalizedSeries = String(seriesValue).trim();
                            if (normalizedSeries === 'Chassis') {
                                // Chassis series matches any vehicle with "Chassis" in the model name
                                return effectiveModel.toLowerCase().includes('chassis');
                            } else {
                                // Regular series match exactly and exclude chassis vehicles
                                return effectiveModel.toLowerCase() === normalizedSeries.toLowerCase() &&
                                       !effectiveModel.toLowerCase().includes('chassis');
                            }
                        });
                        if (excludedSeries) {
                            return false;
                        }
                    } else {
                        // If vehicle has no effective model, exclude it when series filter is active
                        return false;
                    }
                }

                const vTrim = (v.trim || '').toLowerCase();
                if (selectedFilters.trim.length) {
                    // For models with special trim matching, normalize the vehicle trim to config value
                    let normalizedVehicleTrim = vTrim;
                    if (shouldUseCaseInsensitiveTrimMatching(selectedFilters.model)) {
                        const cfg = getModelConfig(selectedFilters.model);
                        if (cfg && cfg.trims) {
                            // Apply same normalization logic as facet calculation
                            let matchingTrim = cfg.trims.find(t =>
                                t.value.toLowerCase() === vTrim
                            );

                            if (!matchingTrim && vTrim.endsWith(' fastback')) {
                                const baseTrim = vTrim.slice(0, -9).trim();
                                matchingTrim = cfg.trims.find(t =>
                                    t.value.toLowerCase() === baseTrim
                                );
                            }

                            if (!matchingTrim) {
                                matchingTrim = cfg.trims.find(t =>
                                    vTrim.includes(t.value.toLowerCase())
                                );
                            }

                            if (matchingTrim) {
                                normalizedVehicleTrim = matchingTrim.value.toLowerCase();
                            }
                        }
                    }

                    const matchesTrim = selectedFilters.trim.some(t => {
                        const tgt = t.toLowerCase();
                        if (shouldUseCaseInsensitiveTrimMatching(selectedFilters.model)) {
                            return normalizedVehicleTrim === tgt;
                        }
                        return vTrim === tgt;
                    });
                    if (!matchesTrim) return false;
                }
                // Check exclude trim filter
                if (excludeFilters.trim.length) {
                    const excludedTrim = excludeFilters.trim.some(t => {
                        const tgt = t.toLowerCase();
                        if (shouldUseCaseInsensitiveTrimMatching(selectedFilters.model)) {
                            return normalizedVehicleTrim === tgt;
                        }
                        return vTrim === tgt;
                    });
                    if (excludedTrim) return false;
                }
                if (selectedFilters.package.length) {
                    const vehiclePackageCode = normalizePackageCode(v.equipment_pkg || '');
                    if (!selectedFilters.package.includes(vehiclePackageCode)) return false;
                }
                if (excludeFilters.package.length) {
                    const vehiclePackageCode = normalizePackageCode(v.equipment_pkg || '');
                    if (excludeFilters.package.includes(vehiclePackageCode)) return false;
                }
                // Engine filtering with normalization
                if (selectedFilters.engine.length) {
                    const vehicleEngine = normalizeEngineFrontend(v.engine || '');
                    let engineMatches = false;

                    for (const selectedEngine of selectedFilters.engine) {
                        // Check for exact match first
                        if (vehicleEngine.toLowerCase() === selectedEngine.toLowerCase()) {
                            engineMatches = true;
                            break;
                        }

                        // Check for normalized match
                        const normalizedSelected = normalizeEngineFrontend(selectedEngine);
                        if (normalizedSelected.toLowerCase() === vehicleEngine.toLowerCase()) {
                            engineMatches = true;
                            break;
                        }

                        // Fallback to substring matching for EVs and complex descriptions
                        const powertrainText = [v.engine, v.battery, v.battery_capacity].filter(Boolean).join(' ').toLowerCase();
                        if (powertrainText.includes(selectedEngine.toLowerCase())) {
                            engineMatches = true;
                            break;
                        }
                    }

                    if (!engineMatches) return false;
                }

                // Check exclude engine filter
                if (excludeFilters.engine.length) {
                    const vehicleEngine = normalizeEngineFrontend(v.engine || '');
                    let engineExcluded = false;

                    for (const excludedEngine of excludeFilters.engine) {
                        // Check for exact match first
                        if (vehicleEngine.toLowerCase() === excludedEngine.toLowerCase()) {
                            engineExcluded = true;
                            break;
                        }

                        // Check for normalized match
                        const normalizedExcluded = normalizeEngineFrontend(excludedEngine);
                        if (normalizedExcluded.toLowerCase() === vehicleEngine.toLowerCase()) {
                            engineExcluded = true;
                            break;
                        }

                        // Fallback to substring matching for EVs and complex descriptions
                        const powertrainText = [v.engine, v.battery, v.battery_capacity].filter(Boolean).join(' ').toLowerCase();
                        if (powertrainText.includes(excludedEngine.toLowerCase())) {
                            engineExcluded = true;
                            break;
                        }
                    }

                    if (engineExcluded) return false;
                }

                // Wheelbase filtering
                if (selectedFilters.wheelbase.length) {
                    let vehicleWheelbase = v.wheelbase;
                    if (vehicleWheelbase === null || vehicleWheelbase === undefined || vehicleWheelbase === '') {
                        return false; // No wheelbase data, exclude from filtered results
                    }
                    // Convert to numbers for comparison to handle precision issues
                    const vehicleWbNum = parseFloat(vehicleWheelbase);
                    const hasMatchingWheelbase = selectedFilters.wheelbase.some(selectedWb => {
                        const selectedWbNum = parseFloat(selectedWb);
                        return Math.abs(vehicleWbNum - selectedWbNum) < 0.01; // Allow small precision differences
                    });
                    if (!hasMatchingWheelbase) {
                        return false;
                    }
                }

                // Check exclude wheelbase filter
                if (excludeFilters.wheelbase.length) {
                    let vehicleWheelbase = v.wheelbase;
                    if (vehicleWheelbase !== null && vehicleWheelbase !== undefined && vehicleWheelbase !== '') {
                        const vehicleWbNum = parseFloat(vehicleWheelbase);
                        const hasExcludedWheelbase = excludeFilters.wheelbase.some(excludedWb => {
                            const excludedWbNum = parseFloat(excludedWb);
                            return Math.abs(vehicleWbNum - excludedWbNum) < 0.01; // Allow small precision differences
                        });
                        if (hasExcludedWheelbase) {
                            return false;
                        }
                    }
                }

                // Drivetrain filtering with normalization
                if (selectedFilters.drivetrain.length) {
                    const vehicleDrivetrain = normalizeDrivetrainFrontend(v.drive_line || '');
                    let drivetrainMatches = false;

                    for (const selectedDrivetrain of selectedFilters.drivetrain) {
                        // Check for exact match first
                        if (vehicleDrivetrain.toLowerCase() === selectedDrivetrain.toLowerCase()) {
                            drivetrainMatches = true;
                            break;
                        }

                        // Check for normalized match
                        const normalizedSelected = normalizeDrivetrainFrontend(selectedDrivetrain);
                        if (normalizedSelected.toLowerCase() === vehicleDrivetrain.toLowerCase()) {
                            drivetrainMatches = true;
                            break;
                        }

                        // Fallback to substring matching
                        const driveText = (v.drive_line || '').toLowerCase();
                        if (driveText.includes(selectedDrivetrain.toLowerCase())) {
                            drivetrainMatches = true;
                            break;
                        }
                    }

                    if (!drivetrainMatches) return false;
                }

                // Check exclude drivetrain filter
                if (excludeFilters.drivetrain.length) {
                    const vehicleDrivetrain = normalizeDrivetrainFrontend(v.drive_line || '');
                    let drivetrainExcluded = false;

                    for (const excludedDrivetrain of excludeFilters.drivetrain) {
                        // Check for exact match first
                        if (vehicleDrivetrain.toLowerCase() === excludedDrivetrain.toLowerCase()) {
                            drivetrainExcluded = true;
                            break;
                        }

                        // Check for normalized match
                        const normalizedExcluded = normalizeDrivetrainFrontend(excludedDrivetrain);
                        if (normalizedExcluded.toLowerCase() === vehicleDrivetrain.toLowerCase()) {
                            drivetrainExcluded = true;
                            break;
                        }

                        // Fallback to substring matching
                        const driveText = (v.drive_line || '').toLowerCase();
                        if (driveText.includes(excludedDrivetrain.toLowerCase())) {
                            drivetrainExcluded = true;
                            break;
                        }
                    }

                    if (drivetrainExcluded) return false;
                }

                if (selectedFilters.body_style.length) {
                    const vehicleBodyStyle = normalizeBodyStyleFrontend(v.body_style || '');
                    let bodyStyleMatches = false;

                    for (const selectedBodyStyle of selectedFilters.body_style) {
                        // Check for exact match first
                        if (vehicleBodyStyle.toLowerCase() === selectedBodyStyle.toLowerCase()) {
                            bodyStyleMatches = true;
                            break;
                        }

                        // Check for normalized match
                        const normalizedSelected = normalizeBodyStyleFrontend(selectedBodyStyle);
                        if (normalizedSelected.toLowerCase() === vehicleBodyStyle.toLowerCase()) {
                            bodyStyleMatches = true;
                            break;
                        }
                    }

                    if (!bodyStyleMatches) return false;
                }

                // Check exclude body_style filter
                if (excludeFilters.body_style.length) {
                    const vehicleBodyStyle = normalizeBodyStyleFrontend(v.body_style || '');
                    let bodyStyleExcluded = false;

                    for (const excludedBodyStyle of excludeFilters.body_style) {
                        // Check for exact match first
                        if (vehicleBodyStyle.toLowerCase() === excludedBodyStyle.toLowerCase()) {
                            bodyStyleExcluded = true;
                            break;
                        }

                        // Check for normalized match
                        const normalizedExcluded = normalizeBodyStyleFrontend(excludedBodyStyle);
                        if (normalizedExcluded.toLowerCase() === vehicleBodyStyle.toLowerCase()) {
                            bodyStyleExcluded = true;
                            break;
                        }
                    }

                    if (bodyStyleExcluded) return false;
                }

                if (selectedFilters.color.length) {
                    const vehicleColor = (v.exterior || '').trim();
                    let colorMatches = false;

                    for (const selectedColor of selectedFilters.color) {
                        // Check for exact match first
                        if (vehicleColor.toLowerCase() === selectedColor.toLowerCase()) {
                            colorMatches = true;
                            break;
                        }

                        // Check for normalized color match
                        const normalizedVehicleColor = normalizePaintColorFrontend(vehicleColor);
                        if (normalizedVehicleColor.toLowerCase() === selectedColor.toLowerCase()) {
                            colorMatches = true;
                            break;
                        }

                        // Check for variation matches using color mapping if available
                        if (window.colorMapping) {
                            const canonicalColors = window.colorMapping.canonical_colors || {};
                            for (const [canonical, data] of Object.entries(canonicalColors)) {
                                const variations = data.variations || [];
                                // If selected color is a variation or canonical name, check if vehicle color matches any variation
                                if (variations.includes(selectedColor) || canonical === selectedColor) {
                                    if (variations.includes(vehicleColor) || canonical === vehicleColor) {
                                        colorMatches = true;
                                        break;
                                    }
                                }
                            }
                            if (colorMatches) break;
                        } else {
                            // Fallback to partial matching if no color mapping available
                            if (vehicleColor.toLowerCase().includes(selectedColor.toLowerCase())) {
                                colorMatches = true;
                                break;
                            }
                        }
                    }

                    if (!colorMatches) return false;
                }

                // Check exclude color filter
                if (excludeFilters.color.length) {
                    const vehicleColor = (v.exterior || '').trim();
                    let colorExcluded = false;

                    for (const excludedColor of excludeFilters.color) {
                        // Check for exact match first
                        if (vehicleColor.toLowerCase() === excludedColor.toLowerCase()) {
                            colorExcluded = true;
                            break;
                        }

                        // Check for normalized color match
                        const normalizedVehicleColor = normalizePaintColorFrontend(vehicleColor);
                        if (normalizedVehicleColor.toLowerCase() === excludedColor.toLowerCase()) {
                            colorExcluded = true;
                            break;
                        }

                        // Check for variation matches using color mapping if available
                        if (window.colorMapping) {
                            const canonicalColors = window.colorMapping.canonical_colors || {};
                            for (const [canonical, data] of Object.entries(canonicalColors)) {
                                const variations = data.variations || [];
                                // If excluded color is a variation or canonical name, check if vehicle color matches any variation
                                if (variations.includes(excludedColor) || canonical === excludedColor) {
                                    if (variations.includes(vehicleColor) || canonical === vehicleColor) {
                                        colorExcluded = true;
                                        break;
                                    }
                                }
                            }
                            if (colorExcluded) break;
                        } else {
                            // Fallback to partial matching if no color mapping available
                            if (vehicleColor.toLowerCase().includes(excludedColor.toLowerCase())) {
                                colorExcluded = true;
                                break;
                            }
                        }
                    }

                    if (colorExcluded) return false;
                }

                if (selectedFilters.equipment.length) {
                    // Extract equipment descriptions for filtering
                    const equipmentDescriptions = (v.optional_equipment || []).map(item =>
                        typeof item === 'object' && item.description ? item.description.toLowerCase() :
                        typeof item === 'string' ? item.toLowerCase() : ''
                    ).join(' ');
                    const optText = equipmentDescriptions;
                    const hasRunningBoardsByTrimOrPkg = () => {
                        const isF150TargetYears = ['2025', '2026'].includes(String(v.year)) && getVehicleNormalizedModel(v) === 'f150';
                        if (!isF150TargetYears) return false;
                        const pkg = String(v.equipment_pkg || '').toUpperCase();
                        const pkgMatch = ['201A', '301A', '302A', '303A', '501A', '502A'].includes(pkg);
                        const trim = (v.trim || '').toLowerCase();
                        const trimMatch = ['king ranch', 'platinum', 'tremor', 'raptor'].some(t => trim.includes(t));
                        return pkgMatch || trimMatch;
                    };
                    if (!selectedFilters.equipment.every(eq => {
                        const optionCfg = (cfg.equipment || []).find(e => e.value === eq);
                        const keywordsMatch = optionCfg && optionCfg.keywords.some(kw => optText.includes(kw.toLowerCase()));
                        if (eq === 'running_boards' && hasRunningBoardsByTrimOrPkg()) return true;
                        return keywordsMatch;
                    })) return false;
                }

                // Check exclude equipment filter
                if (excludeFilters.equipment.length) {
                    // Extract equipment descriptions for filtering
                    const equipmentDescriptions = (v.optional_equipment || []).map(item =>
                        typeof item === 'object' && item.description ? item.description.toLowerCase() :
                        typeof item === 'string' ? item.toLowerCase() : ''
                    ).join(' ');
                    const optText = equipmentDescriptions;
                    const hasRunningBoardsByTrimOrPkg = () => {
                        const isF150TargetYears = ['2025', '2026'].includes(String(v.year)) && getVehicleNormalizedModel(v) === 'f150';
                        if (!isF150TargetYears) return false;
                        const pkg = String(v.equipment_pkg || '').toUpperCase();
                        const pkgMatch = ['201A', '301A', '302A', '303A', '501A', '502A'].includes(pkg);
                        const trim = (v.trim || '').toLowerCase();
                        const trimMatch = ['king ranch', 'platinum', 'tremor', 'raptor'].some(t => trim.includes(t));
                        return pkgMatch || trimMatch;
                    };
                    // Check if any excluded equipment matches
                    const hasExcludedEquipment = excludeFilters.equipment.some(eq => {
                        const optionCfg = (cfg.equipment || []).find(e => e.value === eq);
                        const keywordsMatch = optionCfg && optionCfg.keywords.some(kw => optText.includes(kw.toLowerCase()));
                        if (eq === 'running_boards' && hasRunningBoardsByTrimOrPkg()) return true;
                        return keywordsMatch;
                    });
                    if (hasExcludedEquipment) return false;
                }
            }
            return true;
        });
        const sort = sortSelect.value;
        if (sort === 'random') {
            // Helper function to check if vehicle has images
            function hasImages(vehicle) {
                // Check if photo_urls contains actual vehicle photos (not placeholder images)
                let urls = vehicle.photo_urls;
                if (typeof urls === 'string') {
                    // Handle case where photo_urls might be a string
                    urls = urls.split(',').map(u => u.trim()).filter(u => u);
                }

                if (Array.isArray(urls) && urls.length > 0) {
                    // Filter out placeholder/unavailable images
                    const realPhotos = urls.filter(url => {
                        if (!url) return false;
                        // Exclude various placeholder image patterns
                        if (url.includes('unavailable_stockphoto.png')) return false;
                        if (url.includes('autodata/us/color/')) return false;
                        if (url.includes('fd-FD_DEALERSERVICES_IMAGES/')) return false;
                        if (url.includes('fd-DIG_IMAGES/')) return false;
                        return true;
                    });
                    return realPhotos.length > 0;
                }
                return false;
            }

            // Separate in-stock and in-transit vehicles
            const inStockVehicles = filteredVehicles.filter(v => v.stock);
            const inTransitVehicles = filteredVehicles.filter(v => !v.stock);

            // Further separate each group by image availability
            const inStockWithImages = inStockVehicles.filter(hasImages);
            const inStockWithoutImages = inStockVehicles.filter(v => !hasImages(v));
            const inTransitWithImages = inTransitVehicles.filter(hasImages);
            const inTransitWithoutImages = inTransitVehicles.filter(v => !hasImages(v));

            // Shuffle each group separately using Fisher-Yates
            function shuffleArray(array) {
                for (let i = array.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [array[i], array[j]] = [array[j], array[i]];
                }
                return array;
            }

            // Combine: in-stock with images, in-stock without images, in-transit with images, in-transit without images
            filteredVehicles = [
                ...shuffleArray(inStockWithImages),
                ...shuffleArray(inStockWithoutImages),
                ...shuffleArray(inTransitWithImages),
                ...shuffleArray(inTransitWithoutImages)
            ];
        } else {
            filteredVehicles.sort((a, b) => {
                if (sort === 'price_asc') return getPrice(a) - getPrice(b);
                if (sort === 'price_desc') return getPrice(b) - getPrice(a);
                return 0;
            });
        }
        resultsCount.innerHTML = '<strong>' + filteredVehicles.length + '</strong> vehicles';
        displayedCount = 0;
        updateFilterVisibility(); // Update filter counts
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

        // Add bookmark button
        const bookmarkBtn = document.createElement('button');
        bookmarkBtn.className = 'bookmark-button';
        bookmarkBtn.innerHTML = '♥';
        bookmarkBtn.onclick = (e) => {
            e.stopPropagation(); // Don't trigger card click
            toggleBookmark(v.vin);
            updateBookmarkButton(bookmarkBtn, v.vin);
            if (window.currentView === 'bookmarks' && !isBookmarked(v.vin)) {
                filteredVehicles = filteredVehicles.filter(item => item.vin !== v.vin);
                displayedCount = Math.max(displayedCount - 1, 0);
                if (filteredVehicles.length === 0) {
                    grid.innerHTML = '<div class="no-results">No bookmarked vehicles yet.</div>';
                }
                if (resultsCount) {
                    resultsCount.innerHTML = `<strong>${filteredVehicles.length}</strong> vehicles`;
                }
                card.remove(); // Immediately reflect removal in bookmarks view
            }
        };
        updateBookmarkButton(bookmarkBtn, v.vin); // Set initial state
        img.appendChild(bookmarkBtn);

        if (v.photo) {
            // Create exterior image
            const exteriorImg = document.createElement('img');
            exteriorImg.src = v.photo;
            exteriorImg.alt = [v.year, v.model].filter(Boolean).join(' ');
            exteriorImg.loading = 'lazy';
            exteriorImg.className = 'exterior';
            img.appendChild(exteriorImg);

            if (v.photo_interior && v.photo_interior !== v.photo) {
                // Create interior image (preload it)
                const interiorImg = document.createElement('img');
                interiorImg.src = v.photo_interior;
                interiorImg.alt = [v.year, v.model, 'interior'].filter(Boolean).join(' ');
                interiorImg.loading = 'lazy';
                interiorImg.className = 'interior';
                img.appendChild(interiorImg);
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

        // Extract equipment group code from equipment_pkg
        let equipmentCode = '';
        if (v.equipment_pkg) {
            // Look for patterns like "Equipment Group 101A" or "Order Code 600A" and extract just the code
            const match = v.equipment_pkg.match(/(?:Equipment Group|Order Code)\s+([0-9A-Z]+(?:\s+[0-9A-Z]+)*)/i);
            if (match) {
                equipmentCode = match[1]; // Use just the code part like "101A"
            } else {
                // Fallback: try to extract just the code from the first part
                const firstPart = v.equipment_pkg.split(',')[0].trim();
                const codeMatch = firstPart.match(/([0-9A-Z]+(?:\s+[0-9A-Z]+)*)/);
                if (codeMatch && codeMatch[1].length < 10) { // Only use if reasonably short
                    equipmentCode = codeMatch[1];
                }
            }
        }

        // Create subtitle with clickable stock number
        const subParts = [];
        if (v.condition || 'New') subParts.push(v.condition || 'New');
        if (equipmentCode) subParts.push(equipmentCode);

        sub.innerHTML = subParts.join(' · ');
        if (sub.innerHTML) sub.innerHTML += ' · ';

        if (v.stock) {
            const stockSpan = document.createElement('span');
            stockSpan.className = 'stock-clickable';
            stockSpan.textContent = v.stock;
            stockSpan.dataset.copy = v.stock;
            sub.appendChild(stockSpan);
        }
        titleDiv.appendChild(title);
        titleDiv.appendChild(sub);
        const meta = document.createElement('div');
        meta.className = 'card-meta';
        const tags = document.createElement('div');
        tags.className = 'card-tags';
        // Build interior tag from interior_color + interior_material
        const interiorTag = [v.interior_color, v.interior_material].filter(Boolean).join(' ');

        function addFilterTag(label, filterKey, rawFilterValue) {
            if (!label) return;
            const tag = document.createElement('span');
            tag.className = 'tag';

            let filterValue = rawFilterValue;
            let displayLabel = label;

            // Normalize engine tag values to known filter options so checkboxes match.
            if (filterKey === 'engine' && typeof rawFilterValue === 'string' && rawFilterValue.length) {
                // Prefer current selected model; fall back to the card's model.
                const modelForConfig = selectedFilters.model || getVehicleNormalizedModel(v);
                const cfg = getModelConfig(modelForConfig);
                const options = (cfg && cfg.engines) ? cfg.engines : [];
                const rawLower = rawFilterValue.toLowerCase();
                const normalized = normalizeEngineFrontend(rawFilterValue);
                const normalizedLower = String(normalized || '').toLowerCase();

                // Prefer matching by canonical normalized value first.
                let match = options.find(opt => String(opt.value || '').toLowerCase() === normalizedLower);
                if (!match) {
                    match = options.find(opt => {
                        const valLower = String(opt.value || '').toLowerCase();
                        const labelLower = String(opt.label || '').toLowerCase();
                        return rawLower.includes(valLower) || valLower.includes(rawLower) || rawLower.includes(labelLower) || labelLower.includes(rawLower) ||
                               normalizedLower.includes(valLower) || valLower.includes(normalizedLower);
                    });
                }

                if (match) {
                    filterValue = match.value;
                    displayLabel = match.label || label;
                }
            }

            if (filterKey && typeof filterValue === 'string' && filterValue.length) {
                tag.classList.add('tag-clickable');

                const arr = selectedFilters[filterKey] || [];
                if (arr.includes(filterValue)) {
                    tag.classList.add('tag-filter-active');
                }

                tag.onclick = (e) => {
                    e.stopPropagation(); // Don't trigger card click (detail view)

                    let current = selectedFilters[filterKey] || [];
                    if (current.includes(filterValue)) {
                        current = current.filter(v => v !== filterValue);
                    } else {
                        current = current.concat([filterValue]);
                    }
                    selectedFilters[filterKey] = current;

                    // Keep the filter panel in sync (checkbox states)
                    updateFilterVisibility();
                    applyFilters();
                };
            }

            tag.textContent = displayLabel;
            tags.appendChild(tag);
        }

        // Exclude body_style from tag chips; it was not helpful on the card
        // Map tags to existing filters so clicks can add/remove filters.
        if (v.exterior) addFilterTag(v.exterior, 'color', v.exterior);
        if (v.engine) addFilterTag(v.engine, 'engine', v.engine);
        if (v.drive_line) addFilterTag(v.drive_line, 'drivetrain', v.drive_line);
        if (interiorTag) addFilterTag(interiorTag, null, null); // visual only, no filter
        if (selectedFilters.equipment.length && v.optional_equipment) {
            const optText = JSON.stringify(v.optional_equipment).toLowerCase();
            selectedFilters.equipment.forEach(eq => {
                // Use the correct model config for equipment lookup
                const modelForConfig = selectedFilters.model || getVehicleNormalizedModel(v);
                const modelCfg = getModelConfig(modelForConfig);
                const cfg = modelCfg && modelCfg.equipment ? modelCfg.equipment.find(e => e.value === eq) : null;
                if (cfg && cfg.keywords.some(kw => optText.includes(kw.toLowerCase()))) {
                    const tag = document.createElement('span');
                    tag.className = 'tag tag-equipment tag-filter-active';
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
        price.textContent = formatMoney(v.retail_price) || formatMoney(v.msrp) || 'Contact for price';
        footer.appendChild(price);
        card.appendChild(img);
        card.appendChild(titleDiv);
        card.appendChild(meta);
        card.appendChild(footer);

        // SPA navigation - pass cached vehicle data for instant render
        if (v.stock) {
            if (hasDetailView) {
                card.onclick = () => navigateToVehicle(v.stock, v);
            } else if (v.vehicle_link) {
                card.onclick = () => window.open(v.vehicle_link, '_blank');
            }
        }
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
    const detailInvoice = document.getElementById('detail-invoice');
    const detailPrev = document.getElementById('detail-prev');
    const detailNext = document.getElementById('detail-next');
    const detailEquipmentToggle = document.getElementById('detail-equipment-toggle');
    const detailEquipmentSection = document.getElementById('detail-equipment-section');
    const detailBookmarkButton = document.getElementById('detail-bookmark-button');
    
    let currentDetailPhotos = [];
    let currentDetailPhotoIndex = 0;
    let currentVehicleData = null;
    let previousScrollPosition = 0;

    function showDetailPhoto(index) {
        if (!hasDetailView) return;
        if (!currentDetailPhotos.length) return;
        currentDetailPhotoIndex = (index + currentDetailPhotos.length) % currentDetailPhotos.length;
        detailHeroImg.src = currentDetailPhotos[currentDetailPhotoIndex];
        // Update thumbnail active state
        detailThumbnails.querySelectorAll('.detail-thumb').forEach((thumb, i) => {
            thumb.classList.toggle('active', i === currentDetailPhotoIndex);
        });
    }

    if (detailPrev) detailPrev.onclick = () => showDetailPhoto(currentDetailPhotoIndex - 1);
    if (detailNext) detailNext.onclick = () => showDetailPhoto(currentDetailPhotoIndex + 1);
    
    // Keyboard navigation for photos
    document.addEventListener('keydown', (e) => {
        if (!hasDetailView || !vehicleDetail || !vehicleDetail.classList.contains('active')) return;
        if (e.key === 'ArrowLeft') showDetailPhoto(currentDetailPhotoIndex - 1);
        if (e.key === 'ArrowRight') showDetailPhoto(currentDetailPhotoIndex + 1);
        if (e.key === 'Escape') navigateBack();
    });

    // Equipment toggle
    if (hasDetailView && detailEquipmentToggle && detailEquipment) {
        detailEquipmentToggle.onclick = () => {
            detailEquipmentToggle.classList.toggle('collapsed');
            detailEquipment.classList.toggle('collapsed');
        };
    }

    if (detailBookmarkButton) {
        detailBookmarkButton.innerHTML = BOOKMARK_ICON;
        detailBookmarkButton.onclick = (e) => {
            e.stopPropagation();
            if (!currentVehicleData || !currentVehicleData.vin) return;
            toggleBookmark(currentVehicleData.vin);
            updateBookmarkButton(detailBookmarkButton, currentVehicleData.vin);
        };
    }

    function renderVehicleDetail(v) {
        if (!hasDetailView) return;
        currentVehicleData = v;
        
        if (detailBookmarkButton) {
            updateBookmarkButton(detailBookmarkButton, v.vin);
        }
        // Title
        detailTitle.textContent = [v.year, v.model, v.trim].filter(Boolean).join(' ');
        if (v.vin) {
            const vinPrefix = v.vin.slice(0, -8);
            const vinLast8 = v.vin.slice(-8);
            detailSubtitle.innerHTML = 'VIN: <span class="vin-container"><span class="vin-clickable vin-prefix" data-copy="' + v.vin + '">' + vinPrefix + '</span><span class="vin-clickable vin-last8" data-copy="' + vinLast8 + '"><strong>' + vinLast8 + '</strong></span></span>';
        } else {
            detailSubtitle.innerHTML = '';
        }
        
        // Tags
        detailTags.innerHTML = '';
        const interior = [v.interior_color, v.interior_material].filter(Boolean).join(' ');
        const fuelEconomy = formatFuelEconomy(v.fuel_economy);
        const tagValues = [v.exterior, v.engine, v.drive_line, v.transmission, v.fuel_type, interior, fuelEconomy].filter(Boolean);
        tagValues.forEach(t => {
            const tag = document.createElement('span');
            tag.className = 'tag';
            tag.textContent = t;
            detailTags.appendChild(tag);
        });
        
        // Price breakdown
        const msrpPrice = document.getElementById('msrp-price');
        const dealerDiscount = document.getElementById('dealer-discount');
        const dealerDiscountLine = dealerDiscount.closest('.price-line');
        const salePrice = document.getElementById('sale-price');
        const salePriceLine = salePrice.closest('.price-line');
        const factoryRebates = document.getElementById('factory-rebates');
        const factoryRebatesLine = factoryRebates.closest('.price-line');
        const retailPrice = document.getElementById('retail-price');

        // Get pricing breakdown from vehicle data
        const pricingBreakdown = v.pricing_breakdown || {};

        msrpPrice.textContent = formatMoney(pricingBreakdown.msrp || pricingBreakdown.base_price || v.msrp) || 'Contact for price';

        // Only show dealer discount line if there's an actual discount
        const hasDealerDiscount = pricingBreakdown.dealer_discount || pricingBreakdown.discount;
        const hasFactoryRebates = pricingBreakdown.factory_rebates;

        if (hasDealerDiscount) {
            dealerDiscount.textContent = '-' + formatMoney(pricingBreakdown.dealer_discount || pricingBreakdown.discount);
            dealerDiscountLine.style.display = 'flex';

            // Only show sale price if there are also factory rebates (otherwise it's redundant with retail price)
            if (hasFactoryRebates) {
                salePrice.textContent = formatMoney(pricingBreakdown.sale_price || pricingBreakdown.total_vehicle || v.sale_price || v.retail_price) || 'Contact for price';
                salePriceLine.style.display = 'flex';
            } else {
                salePriceLine.style.display = 'none';
            }
        } else {
            dealerDiscountLine.style.display = 'none';
            salePriceLine.style.display = 'none';
        }

        // Only show factory rebates line if there are actual rebates
        if (hasFactoryRebates) {
            factoryRebates.textContent = '-' + formatMoney(pricingBreakdown.factory_rebates);
            factoryRebatesLine.style.display = 'flex';
        } else {
            factoryRebatesLine.style.display = 'none';
        }

        retailPrice.textContent = formatMoney(pricingBreakdown.retail_price || pricingBreakdown.total_vehicle || v.retail_price) || 'Contact for price';
        
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
        
        // Specs grid - removed per user request
        
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
        detailInvoice.onclick = () => {
            // Ford invoice URL pattern
            if (v.vin) window.open('https://fordvisions.dealerconnection.com/vinv/GetInvoice.aspx?v=' + v.vin, '_blank');
        };

        // VIN hover functionality
        const vinContainer = detailSubtitle.querySelector('.vin-container');
        const vinPrefix = detailSubtitle.querySelector('.vin-prefix');
        if (vinPrefix) {
            vinPrefix.addEventListener('mouseenter', () => {
                vinContainer.classList.add('vin-glow');
            });
            vinPrefix.addEventListener('mouseleave', () => {
                vinContainer.classList.remove('vin-glow');
            });
        }

        // VIN click to copy functionality
        let isShowingFeedback = false;
        const vinSpans = detailSubtitle.querySelectorAll('.vin-clickable');
        vinSpans.forEach(span => {
            span.onclick = async () => {
                if (isShowingFeedback) return; // Prevent multiple clicks during feedback

                const textToCopy = span.dataset.copy;
                const isStockNumber = span.classList.contains('vin-last8');
                try {
                    await navigator.clipboard.writeText(textToCopy);
                    isShowingFeedback = true;

                    const message = isStockNumber ? 'Stock # Copied!' : 'VIN Copied!';
                    const feedbackSpan = document.createElement('span');
                    feedbackSpan.style.cssText = 'background-color: #e8f0fe; color: #3e6ae1; padding: 2px 4px; border-radius: 2px; position: absolute; top: 0; left: 0; right: 0; text-align: center;';
                    feedbackSpan.textContent = message;

                    // Hide original spans and show feedback
                    vinContainer.style.position = 'relative';
                    const originalSpans = vinContainer.querySelectorAll('.vin-clickable');
                    originalSpans.forEach(s => s.style.opacity = '0');
                    vinContainer.appendChild(feedbackSpan);

                    setTimeout(() => {
                        // Restore original content
                        vinContainer.removeChild(feedbackSpan);
                        originalSpans.forEach(s => s.style.opacity = '1');
                        vinContainer.style.position = '';
                        isShowingFeedback = false;
                    }, 1000);
                } catch (err) {
                    console.error('Failed to copy: ', err);
                }
            };
        });
    }

    function showVehicleDetail() {
        if (!hasDetailView) return;
        previousScrollPosition = window.scrollY;
        vehicleDetail.classList.add('active');
        mainContent.style.display = 'none';
        document.querySelector('.header').style.display = 'none';
        window.scrollTo(0, 0);
    }

    function hideVehicleDetail() {
        if (!hasDetailView) return;
        vehicleDetail.classList.remove('active');
        mainContent.style.display = 'flex';
        document.querySelector('.header').style.display = 'flex';
        window.scrollTo(0, previousScrollPosition);
    }

    function navigateToVehicle(stock, cachedData = null) {
        if (!hasDetailView) {
            if (cachedData && cachedData.vehicle_link) {
                window.open(cachedData.vehicle_link, '_blank');
            }
            return;
        }
        setStockSearchVisible(false);
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
        // Return to inventory UI (homepage or filtered list)
        hideVehicleDetail();

        if (selectedFilters.model) {
            isHomepage = false;
            updateSortVisibility();
            applyFiltersNoURLUpdate();
        } else {
            isHomepage = true;
            updateSortVisibility();
            renderHomepageNoURLUpdate();
        }

        // Update URL to inventory (preserve model if we have one)
        const url = new URL(window.location);
        url.pathname = '/';
        if (selectedFilters.model) {
            url.searchParams.set('model', selectedFilters.model);
        }
        history.pushState({ view: 'inventory', filters: selectedFilters, isHomepage }, '', url);
    }

    if (hasDetailView && detailBack) detailBack.onclick = navigateBack;

    // Handle browser back/forward
    window.addEventListener('popstate', (e) => {
        if (!hasDetailView) return;
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
        console.log('checkInitialRoute: path =', path);
        // Always show homepage for root path
        if (path === '/' || path === '' || path === '/index.php') {
            console.log('checkInitialRoute: showing homepage');
            // Ensure vehicle detail is hidden
            if (hasDetailView) {
                const detailEl = document.querySelector('.vehicle-detail');
                if (detailEl) detailEl.classList.remove('active');
            }
            return false;
        }
        if (path.length > 1 && !path.includes('.')) {
            const stock = path.substring(1);
            console.log('checkInitialRoute: navigating to vehicle', stock);
            // This looks like a vehicle stock number
            navigateToVehicle(stock);
            return true;
        }
        console.log('checkInitialRoute: no match, returning false');
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
            setupStockClickHandlers();
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

    function renderTableView() {
        grid.innerHTML = '';

        if (!filteredVehicles.length) {
            grid.innerHTML = '<div class="no-results">No bookmarked vehicles yet.</div>';
            return;
        }

        // Create container for the comparison table
        const tableContainer = document.createElement('div');
        tableContainer.className = 'comparison-table-container';

        // Create the comparison table
        const table = document.createElement('table');
        table.className = 'comparison-table';

        // Create table body
        const tbody = document.createElement('tbody');

        // Define the rows we want to show
        const rows = [
            { label: 'Vehicle', getValue: (v) => `${v.year} ${v.make} ${v.model}` },
            { label: 'Photo', getValue: (v) => '', isImage: true },
            { label: 'Trim', getValue: (v) => v.trim || '' },
            { label: 'Body Style', getValue: (v) => v.body_style || '' },
            { label: 'Engine', getValue: (v) => v.engine || '' },
            { label: 'Drivetrain', getValue: (v) => v.drive_line || '' },
            { label: 'Wheelbase', getValue: (v) => v.wheelbase ? `${v.wheelbase}"` : '' },
            { label: 'Exterior Color', getValue: (v) => v.exterior || '' },
            { label: 'Package', getValue: (v) => v.equipment_pkg || '' },
            { label: 'MSRP', getValue: (v) => formatMoney(v.msrp) || '' },
            { label: 'Retail Price', getValue: (v) => formatMoney(v.retail_price) || '' },
            { label: 'Stock #', getValue: (v) => v.stock || '' },
            { label: 'Optional Equipment', getValue: (v) => v.optional_equipment || [], isEquipment: true }
        ];

        rows.forEach(row => {
            const tr = document.createElement('tr');

            // Add label cell (first column)
            const labelCell = document.createElement('th');
            labelCell.className = 'comparison-label-cell';
            labelCell.textContent = row.label;
            tr.appendChild(labelCell);

            // Add data cells for each vehicle
            filteredVehicles.forEach(vehicle => {
                const dataCell = document.createElement('td');
                dataCell.className = 'comparison-data-cell';

                if (row.isImage) {
                    // Special handling for photo row
                    const img = document.createElement('img');
                    img.src = vehicle.photo || '/placeholder-vehicle.jpg';
                    img.alt = `${vehicle.year} ${vehicle.make} ${vehicle.model}`;
                    img.loading = 'lazy';
                    img.className = 'comparison-vehicle-photo';
                    dataCell.appendChild(img);
                } else if (row.isEquipment) {
                    // Special handling for equipment
                    const equipment = row.getValue(vehicle);
                    if (Array.isArray(equipment) && equipment.length > 0) {
                        const equipmentList = document.createElement('div');
                        equipmentList.className = 'comparison-equipment-list';

                        equipment.forEach(item => {
                            const equipmentItem = document.createElement('div');
                            equipmentItem.className = 'comparison-equipment-item';
                            equipmentItem.textContent = typeof item === 'string' ? item : (item.name || item.description || JSON.stringify(item));
                            equipmentList.appendChild(equipmentItem);
                        });

                        dataCell.appendChild(equipmentList);
                    } else {
                        dataCell.textContent = 'None';
                        dataCell.classList.add('no-equipment');
                    }
                } else {
                    // Regular data cell
                    const value = row.getValue(vehicle);
                    dataCell.textContent = value || '-';
                    if (!value) {
                        dataCell.classList.add('empty-cell');
                    }
                }

                tr.appendChild(dataCell);
            });

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        tableContainer.appendChild(table);
        grid.appendChild(tableContainer);
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
        setupStockClickHandlers();
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

    // Stock number click-to-copy functionality
    function setupStockClickHandlers() {
        let isShowingStockFeedback = false;
        const stockSpans = document.querySelectorAll('.stock-clickable');
        stockSpans.forEach(span => {
            span.onclick = async (e) => {
                e.stopPropagation(); // Prevent card click navigation
                if (isShowingStockFeedback) return;

                const textToCopy = span.dataset.copy;
                try {
                    await navigator.clipboard.writeText(textToCopy);
                    isShowingStockFeedback = true;

                    const originalText = span.textContent;
                    span.textContent = 'Copied!';
                    span.style.backgroundColor = '#e8f0fe';
                    span.style.color = '#3e6ae1';
                    span.style.borderRadius = '2px';
                    span.style.padding = '2px 4px';

                    setTimeout(() => {
                        span.textContent = originalText;
                        span.style.backgroundColor = '';
                        span.style.color = '';
                        span.style.borderRadius = '';
                        span.style.padding = '';
                        isShowingStockFeedback = false;
                    }, 1000);
                } catch (err) {
                    console.error('Failed to copy stock number: ', err);
                }
            };
        });
    }

    document.querySelectorAll('input[name="model"]').forEach(r => {
        r.onchange = e => {
            selectModel(e.target.value);
        };
    });

    sortSelect.onchange = applyFilters;

    document.getElementById('clear-year').onclick = () => { clearFilter('year'); updateYearOptions(); document.getElementById('clear-year').style.display = 'none'; };
    document.getElementById('clear-trim').onclick = () => clearFilter('trim');
    document.getElementById('clear-series').onclick = () => clearFilter('series');
    document.getElementById('clear-package').onclick = () => clearFilter('package');
    document.getElementById('clear-engine').onclick = () => clearFilter('engine');
    document.getElementById('clear-wheelbase').onclick = () => clearFilter('wheelbase');
    document.getElementById('clear-drivetrain').onclick = () => clearFilter('drivetrain');
    document.getElementById('clear-status').onclick = () => clearFilter('status');
    document.getElementById('clear-body-style').onclick = () => clearFilter('body_style');
    document.getElementById('clear-equipment').onclick = () => clearFilter('equipment');
    document.getElementById('clear-color').onclick = () => clearFilter('color');
    if (stockSearchForm) stockSearchForm.addEventListener('submit', handleStockSearch);
    if (stockSearchInput) stockSearchInput.addEventListener('input', () => { if (stockSearchHint) stockSearchHint.textContent = ''; });

    // Load all vehicles for client-side filtering, then restore state from URL or show homepage
    // Uses lite cache for faster initial load - facets are pre-computed in cache
    console.log('Starting data fetch');
    fetch('inventory.php?lite=1&per_page=9999')
        .then(r => r.json())
        .then(data => {
            console.log('Data fetch completed, data:', data);
            vehicles = data.vehicles || [];
            // Use pre-computed facets from cache (generated by generate_cache.php)
            facetCounts = data.facets || {};
            const upd = document.getElementById('last-updated');
            if (upd && data.lastUpdated) upd.textContent = 'Updated ' + data.lastUpdated;

            // Load color mapping for better color filtering, but never block boot/render on it.
            return fetch('../db/color_mapping.json')
                .then(r => (r && r.ok) ? r.json() : {})
                .then(colorData => { window.colorMapping = colorData || {}; })
                .catch(() => { window.colorMapping = {}; })
                .finally(() => {
                    // Check if we're on a direct vehicle link first
                    if (!checkInitialRoute()) {
                        // Not a vehicle link, check for inventory filters or show homepage
                        const urlState = parseURLState();
                        console.log('Data loaded, urlState:', urlState);
                        if (urlState.model) {
                            console.log('Restoring state from URL for model:', urlState.model);
                            restoreStateFromURL();
                        } else {
                            console.log('Rendering homepage');
                            renderHomepageNoURLUpdate(); // Don't push to history on initial load
                        }
                    }
                });
        })
        .catch(e => {
            console.error(e);
            grid.innerHTML = '<div class="no-results">Unable to load inventory. Please refresh and try again.</div>';
        });

    // QR Code and Barcode scanning functionality
    let scanning = false;
    let scanOverlay = null;
    let codeReader = null;

    document.getElementById('qr-scan-button').addEventListener('click', startQRScan);

    function startQRScan() {
        if (scanning) {
            stopQRScan();
            return;
        }

        scanning = true;

        // Create overlay
        scanOverlay = document.createElement('div');
        scanOverlay.id = 'qr-scan-overlay';
        scanOverlay.innerHTML = `
            <div class="qr-scan-modal">
                <div class="qr-scan-header">
                    <h3>Scan QR Code or VIN Barcode</h3>
                    <button class="qr-scan-close" id="qr-scan-close">&times;</button>
                </div>
                <div class="qr-scan-content">
                    <video id="qr-video" autoplay playsinline></video>
                    <div class="qr-scan-instructions">
                        Point your camera at a QR code or VIN barcode containing a stock number or VIN
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(scanOverlay);

        // Add modal styles
        const modalStyle = document.createElement('style');
        modalStyle.textContent = `
            #qr-scan-overlay {
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.8); z-index: 10000; display: flex;
                align-items: center; justify-content: center;
            }
            .qr-scan-modal {
                background: white; border-radius: 12px; width: 90%; max-width: 500px;
                box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
            }
            .qr-scan-header {
                display: flex; justify-content: space-between; align-items: center;
                padding: 20px 24px 0; margin-bottom: 16px;
            }
            .qr-scan-header h3 { margin: 0; font-size: 20px; font-weight: 600; }
            .qr-scan-close {
                background: none; border: none; font-size: 28px; cursor: pointer;
                color: var(--muted); padding: 0; width: 32px; height: 32px;
                display: flex; align-items: center; justify-content: center;
            }
            .qr-scan-close:hover { color: var(--text); }
            .qr-scan-content { padding: 0 24px 24px; text-align: center; }
            #qr-video {
                width: 100%; max-width: 400px; border-radius: 8px;
                border: 2px solid var(--border);
            }
            .qr-scan-instructions {
                margin-top: 16px; font-size: 14px; color: var(--muted);
            }
        `;
        document.head.appendChild(modalStyle);

        document.getElementById('qr-scan-close').addEventListener('click', stopQRScan);

        const videoElement = document.getElementById('qr-video');

        if (typeof ZXing === 'undefined' || typeof ZXing.BrowserMultiFormatReader !== 'function') {
            console.error('ZXing library not loaded properly');
            alert('Barcode scanning library failed to load. Please refresh the page.');
            stopQRScan();
            return;
        }

        const hints = new Map();
        hints.set(ZXing.DecodeHintType.POSSIBLE_FORMATS, [
            ZXing.BarcodeFormat.QR_CODE,
            ZXing.BarcodeFormat.CODE_128,
            ZXing.BarcodeFormat.CODE_39,
            ZXing.BarcodeFormat.CODE_93,
            ZXing.BarcodeFormat.ITF
        ]);
        hints.set(ZXing.DecodeHintType.TRY_HARDER, false);
        hints.set(ZXing.DecodeHintType.ALSO_INVERTED, false);

        codeReader = new ZXing.BrowserMultiFormatReader();

        codeReader.decodeFromVideoDevice(
            null,
            'qr-video',
            (result, err) => {
                if (result && result.text) {
                    applyScannedValue(result.text);
                } else if (err && !(err instanceof ZXing.NotFoundException)) {
                    console.warn('ZXing scan error', err);
                }
            },
            hints,
            { videoConstraints: { facingMode: 'environment', width: { ideal: 640 }, height: { ideal: 480 } } }
        ).catch(err => {
            if (result && result.text) {
                applyScannedValue(result.text);
            } else if (err && !(err instanceof ZXing.NotFoundException)) {
                console.warn('ZXing scan error', err);
            }
        }, hints).catch(err => {
            console.error('Camera access denied:', err);
            alert('Camera access is required to scan QR codes and barcodes. Please allow camera access and try again.');
            stopQRScan();
        });

        function applyScannedValue(data) {
            const trimmed = data.trim();
            if (!trimmed || trimmed.length < 8) return;

            let stockNumber = '';
            if (trimmed.length === 17 && /^[A-HJ-NPR-Z0-9]{17}$/i.test(trimmed)) {
                stockNumber = trimmed.slice(-8);
            } else if (trimmed.length >= 8) {
                stockNumber = trimmed.slice(-8);
            }

            if (stockNumber) {
                stopQRScan();
                if (stockSearchInput) {
                    stockSearchInput.value = stockNumber;
                    stockSearchInput.focus();
                    setTimeout(() => {
                        handleStockSearch({ preventDefault: () => {} });
                    }, 100);
                }
            }
        }
    }

    function stopQRScan() {
        scanning = false;

        if (codeReader) {
            codeReader.reset();
            codeReader = null;
        }

        if (scanOverlay) {
            document.body.removeChild(scanOverlay);
            scanOverlay = null;
        }
    }
});
</script>
</body>
</html>