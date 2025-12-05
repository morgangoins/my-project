<?php
$stock = isset($_GET['stock']) ? htmlspecialchars($_GET['stock']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Detail<?php echo $stock ? " - " . $stock : ""; ?></title>
    <link href="https://rsms.me/inter/inter.css" rel="stylesheet">
    <style>
        :root {
            --bg: #f5f5f5;
            --text: #171a20;
            --muted: #5c5e62;
            --border: #e4e4e4;
            --accent: #3e6ae1;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #ffffff;
            color: var(--text);
        }

        .main {
            flex: 1;
            display: flex;
            gap: 40px;
            padding: 20px 40px 40px;
            justify-content: space-between;
        }

        .media {
            flex: 3;
            position: sticky;
            top: 0;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hero-image {
            width: 100%;
            max-width: 900px;
            max-height: 80vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f5f5;
            border-radius: 20px;
            overflow: hidden;
            position: relative;
        }

        .hero-image img {
            width: 100%;
            height: 100%;
            object-fit: contain; /* ensure full image is visible without cropping */
        }

        .hero-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 32px;
            height: 32px;
            border-radius: 999px;
            border: none;
            background: rgba(23, 26, 32, 0.6);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .hero-arrow--left {
            left: 16px;
        }

        .hero-arrow--right {
            right: 16px;
        }

        .hero-arrow:hover {
            background: rgba(23, 26, 32, 0.8);
        }

        .panel {
            flex: 2;
            max-width: 420px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-left: auto;
            padding-top: 40px; /* push title down for better visual balance */
        }

        .title {
            font-size: 28px;
            font-weight: 500;
            text-align: center;
        }

        .subtitle {
            font-size: 14px;
            color: var(--muted);
            text-align: center;
        }

        .pill-row {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .pill {
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid var(--border);
            color: var(--muted);
            background: #fafafa;
        }

        .payment-tabs {
            display: flex;
            gap: 32px;
            margin-top: 24px;
            border-bottom: 1px solid var(--border);
            padding: 0 8px 4px;
            justify-content: center;
            width: fit-content;
            margin-left: auto;
            margin-right: auto;
        }

        .payment-tab {
            flex: 0 0 auto;
            text-align: center;
            font-size: 13px;
            padding: 0 0 8px;
            border: none;
            border-radius: 0;
            background: transparent;
            cursor: pointer;
            color: var(--muted);
        }

        .payment-tab--active {
            color: var(--text);
            font-weight: 500;
            border-bottom: 2px solid var(--text);
        }

        .price-block {
            margin-top: 16px;
            font-size: 24px;
            font-weight: 500;
        }

        .price-block span {
            display: block;
            font-size: 12px;
            color: var(--muted);
            margin-top: 4px;
        }

        .specs-grid {
            margin-top: 20px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px 16px;
            font-size: 13px;
        }

        .spec-label {
            color: var(--muted);
        }

        .spec-value {
            font-weight: 500;
        }

        .cta-button {
            margin-top: 24px;
            align-self: flex-start;
            padding: 10px 32px;
            border-radius: 999px;
            border: none;
            background: var(--accent);
            color: #ffffff;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
        }

        .cta-button:hover {
            background: #3458c7;
        }

        .disclaimer {
            margin-top: 8px;
            font-size: 11px;
            color: var(--muted);
        }

        @media (max-width: 900px) {
            .main {
                flex-direction: column;
                align-items: stretch;
            }

            .media {
                position: static;
                height: auto;
                margin-bottom: 24px;
            }

            .panel {
                max-width: 100%;
                margin-left: 0;
                padding-top: 24px;
            }
        }
    </style>
</head>
<body>
<div class="page" id="vehicle-page" data-stock="<?php echo $stock; ?>">
    <main class="main">
        <section class="media">
            <div class="hero-image" id="hero-image">
                <button class="hero-arrow hero-arrow--left" id="hero-prev" aria-label="Previous photo">&#10094;</button>
                <img src="" alt="" id="hero-img">
                <button class="hero-arrow hero-arrow--right" id="hero-next" aria-label="Next photo">&#10095;</button>
            </div>
        </section>

        <aside class="panel">
            <div class="title" id="vehicle-title">Loading...</div>
            <div class="subtitle" id="vehicle-subtitle"></div>
            <div class="pill-row" id="pill-row"></div>

            <div class="payment-tabs">
                <button class="payment-tab payment-tab--active" data-mode="cash">Cash</button>
                <button class="payment-tab" data-mode="lease">Lease</button>
                <button class="payment-tab" data-mode="finance">Finance</button>
            </div>

            <div class="price-block" id="price-block">
                <span>Loading pricing...</span>
            </div>

            <div class="specs-grid" id="specs-grid"></div>

            <button class="cta-button" id="cta-button">Contact dealer</button>
            <div class="disclaimer" id="disclaimer"></div>
        </aside>
    </main>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const container = document.getElementById('vehicle-page');
        const stock = container ? container.dataset.stock : '';

        // Update URL to pretty /{stock} without breaking existing routing
        if (stock && !window.location.pathname.endsWith('/' + stock)) {
            try {
                history.replaceState({}, '', '/' + stock);
            } catch (e) {
                console.warn('Could not update URL', e);
            }
        }

        const heroImg = document.getElementById('hero-img');
        const heroImage = document.getElementById('hero-image');
        const prevBtn = document.getElementById('hero-prev');
        const nextBtn = document.getElementById('hero-next');
        const titleEl = document.getElementById('vehicle-title');
        const subtitleEl = document.getElementById('vehicle-subtitle');
        const pillRow = document.getElementById('pill-row');
        const priceBlock = document.getElementById('price-block');
        const specsGrid = document.getElementById('specs-grid');
        const disclaimerEl = document.getElementById('disclaimer');
        const ctaButton = document.getElementById('cta-button');
        const paymentTabs = document.querySelectorAll('.payment-tab');
        let photos = [];
        let currentPhotoIndex = 0;

        if (!stock) {
            titleEl.textContent = 'Vehicle not found';
            subtitleEl.textContent = 'Missing stock number.';
            heroImage.style.display = 'none';
            return;
        }

        function formatMoney(value) {
            if (!value) return '';
            const cleaned = String(value).replace(/[^0-9.]/g, '');
            const num = parseFloat(cleaned);
            if (!isFinite(num)) return value;
            return num.toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
        }

        function showPhoto(index) {
            if (!photos.length) return;
            currentPhotoIndex = (index + photos.length) % photos.length;
            heroImg.src = photos[currentPhotoIndex];
        }

        function renderVehicle(vehicle) {
            const yearPart = vehicle.year ? vehicle.year + ' ' : '';
            const modelPart = vehicle.model || '';
            const trimPart = vehicle.trim ? ' ' + vehicle.trim : '';
            const title = yearPart + modelPart + trimPart;
            titleEl.textContent = title;
            subtitleEl.textContent = vehicle.drive_line || '';

            pillRow.innerHTML = '';
            [
                vehicle.body_style,
                vehicle.engine,
                vehicle.transmission,
                vehicle.exterior,
                vehicle.fuel_type
            ].forEach(function (value) {
                if (!value) return;
                const span = document.createElement('span');
                span.className = 'pill';
                span.textContent = value;
                pillRow.appendChild(span);
            });

            // Build full photo set: prefer complete list from CSV if present
            photos = [];
            if (Array.isArray(vehicle.photo_urls) && vehicle.photo_urls.length) {
                photos = vehicle.photo_urls.slice();
            } else {
                if (vehicle.photo) photos.push(vehicle.photo);
                if (vehicle.photo_interior && vehicle.photo_interior !== vehicle.photo) {
                    photos.push(vehicle.photo_interior);
                }
            }

            if (photos.length) {
                showPhoto(0);
                if (prevBtn && nextBtn) {
                    const showArrows = photos.length > 1;
                    prevBtn.style.display = showArrows ? 'flex' : 'none';
                    nextBtn.style.display = showArrows ? 'flex' : 'none';
                }
            } else {
                heroImg.alt = 'Vehicle image';
            }

            function updatePrice(mode) {
                const sale = formatMoney(vehicle.sale_price);
                const msrp = formatMoney(vehicle.msrp);
                const retail = formatMoney(vehicle.retail_price);
                let main = sale || msrp || retail || 'See dealer';
                let sub = '';

                if (mode === 'cash') {
                    sub = msrp && sale && msrp !== sale
                        ? 'MSRP ' + msrp + ', dealer price shown'
                        : 'Estimated purchase price';
                } else if (mode === 'lease') {
                    sub = 'Illustrative lease scenario based on dealer pricing.';
                } else if (mode === 'finance') {
                    sub = 'Illustrative finance scenario based on dealer pricing.';
                }

                priceBlock.innerHTML = main + '<span>' + sub + '</span>';
            }

            paymentTabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    paymentTabs.forEach(function (t) {
                        t.classList.toggle('payment-tab--active', t === tab);
                    });
                    updatePrice(tab.dataset.mode);
                });
            });

            if (prevBtn && nextBtn) {
                prevBtn.addEventListener('click', function () {
                    showPhoto(currentPhotoIndex - 1);
                });
                nextBtn.addEventListener('click', function () {
                    showPhoto(currentPhotoIndex + 1);
                });
            }

            updatePrice('cash');

            specsGrid.innerHTML = '';
            const specs = [
                ['Drivetrain', vehicle.drive_line],
                ['Fuel economy', vehicle.fuel_economy || (vehicle.city_mpg && vehicle.highway_mpg ? vehicle.city_mpg + '/' + vehicle.highway_mpg : '')],
                ['Inventory date', vehicle.inventory_date],
            ];

            specs.forEach(function ([label, value]) {
                if (!value) return;
                const labelEl = document.createElement('div');
                labelEl.className = 'spec-label';
                labelEl.textContent = label;
                const valueEl = document.createElement('div');
                valueEl.className = 'spec-value';
                valueEl.textContent = value;
                specsGrid.appendChild(labelEl);
                specsGrid.appendChild(valueEl);
            });

            disclaimerEl.textContent = 'All pricing and availability subject to change. Contact dealer for the most current information.';

            if (vehicle.vehicle_link) {
                ctaButton.addEventListener('click', function () {
                    window.open(vehicle.vehicle_link, '_blank');
                });
            }
        }

        fetch('inventory.php')
            .then(function (response) { return response.json(); })
            .then(function (data) {
                const list = (data && data.vehicles) ? data.vehicles : [];
                const vehicle = list.find(function (v) {
                    return v.stock === stock || v.vin === stock;
                });
                if (!vehicle) {
                    heroImage.style.display = 'none';
                    titleEl.textContent = 'Vehicle not found';
                    subtitleEl.textContent = 'This stock number is not in the current inventory.';
                    return;
                }
                renderVehicle(vehicle);
            })
            .catch(function (error) {
                console.error('Failed to load vehicle', error);
                heroImage.style.display = 'none';
                titleEl.textContent = 'Unable to load vehicle';
                subtitleEl.textContent = 'Please try again later.';
            });
    });
</script>
</body>
</html>


