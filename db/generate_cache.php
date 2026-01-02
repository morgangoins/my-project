#!/usr/bin/env php
<?php
//
// Static Cache Generator
// 
// Generates pre-built JSON cache files for instant page loads.
// Run this whenever the inventory database is updated:
//   php /root/www/db/generate_cache.php
// 
// Or add to cron (every 5 min): 
//   */5 * * * * php /root/www/db/generate_cache.php
//
// Features:
//   - Pre-computes facet counts for filters
//   - Normalizes data values (colors, engines, drivetrains)
//   - Generates both full and lite cache versions
//   - Strips empty values to reduce payload size
//

$dbPath = __DIR__ . '/inventory.sqlite';
$cacheDir = __DIR__;

if (!file_exists($dbPath)) {
    echo "Error: Database not found at $dbPath\n";
    exit(1);
}

$dbMtime = filemtime($dbPath);
$cacheFile = $cacheDir . '/inventory_cache.json';
$cacheLiteFile = $cacheDir . '/inventory_cache_lite.json';

// Check if cache is already up to date
if (file_exists($cacheFile) && file_exists($cacheLiteFile) && filemtime($cacheFile) >= $dbMtime && filemtime($cacheLiteFile) >= $dbMtime) {
    echo "Cache is already up to date (db: " . date('Y-m-d H:i:s', $dbMtime) . ")\n";
    exit(0);
}

echo "Generating cache for database updated at " . date('Y-m-d H:i:s', $dbMtime) . "\n";

// ============================================================================
// NORMALIZATION FUNCTIONS
// These ensure consistent values across the application
// ============================================================================

function normalizePaintColor($paint, $model = '') {
    $paint = trim($paint);
    if (!$paint) return '';
    $lower = strtolower($paint);

    // Common color normalizations - check specific patterns before general ones
    if (stripos($lower, 'rapid red') !== false) return 'Rapid Red';
    if (stripos($lower, 'star white') !== false) return 'Star White';
    if (stripos($lower, 'space white') !== false) return 'Space White';
    if (stripos($lower, 'agate black') !== false) return 'Agate Black';
    if (stripos($lower, 'antimatter blue') !== false) return 'Antimatter Blue';
    if (stripos($lower, 'glacier gray') !== false) return 'Glacier Gray';
    if (stripos($lower, 'carbonized gray') !== false) return 'Carbonized Gray Metallic';
    if (stripos($lower, 'marsh gray') !== false) return 'Marsh Gray';
    if (stripos($lower, 'azure gray') !== false) return 'Azure Gray';
    // Special handling for Gray Metallic - distinguish based on model
    if (stripos($lower, 'gray metallic') !== false) {
        // F-150 vehicles with Gray Metallic should be Glacier Gray
        // Super Duty vehicles with Gray Metallic should be Carbonized Gray Metallic
        if (stripos(strtolower($model), 'f-150') !== false) {
            return 'Glacier Gray';
        } else {
            return 'Carbonized Gray Metallic';
        }
    }
    if (stripos($lower, 'iconic silver') !== false) return 'Iconic Silver';
    if (stripos($lower, 'oxford white') !== false) return 'Oxford White';
    if (stripos($lower, 'atlas blue') !== false) return 'Atlas Blue';
    if (stripos($lower, 'shadow black') !== false) return 'Shadow Black';
    if (stripos($lower, 'velocity blue') !== false) return 'Velocity Blue';
    if (stripos($lower, 'vapor blue') !== false) return 'Vapor Blue';
    if (stripos($lower, 'azure gray') !== false) return 'Azure Gray';
    if (stripos($lower, 'desert sand') !== false) return 'Desert Sand';
    if (stripos($lower, 'avalanche') !== false) return 'Avalanche';
    if (stripos($lower, 'ruby red') !== false) return 'Ruby Red';
    if (stripos($lower, 'argon blue') !== false) return 'Argon Blue';
    if (stripos($lower, 'shelter green') !== false) return 'Shelter Green';
    if (stripos($lower, 'eruption green') !== false) return 'Eruption Green';

    return $paint;
}

function normalizeEngine($engine) {
    $engine = trim($engine);
    if (!$engine) return '';
    $lower = strtolower($engine);
    // Normalize separators so "v-6" and "v6" behave the same.
    $lower = str_replace(['-', '–', '—'], '', $lower);

    // F-150/Lightning engines
    if (stripos($lower, '2.7l') !== false && stripos($lower, 'v6') !== false) return '2.7L EcoBoost V6';
    if (stripos($lower, '2.7l') !== false && stripos($lower, 'ecoboost') !== false) return '2.7L EcoBoost V6';
    if (stripos($lower, '3.5l') !== false && stripos($lower, 'powerboost') !== false) return '3.5L PowerBoost Hybrid';
    if (stripos($lower, '3.5l') !== false && stripos($lower, 'high-output') !== false) return '3.5L High Output EcoBoost';
    if (stripos($lower, '3.5l') !== false && stripos($lower, 'high output') !== false) return '3.5L High Output EcoBoost';
    if (stripos($lower, '3.5l') !== false && stripos($lower, 'v6') !== false && stripos($lower, 'ecoboost') !== false) return '3.5L EcoBoost V6';
    if (stripos($lower, '3.5l') !== false && stripos($lower, 'v6') !== false && stripos($lower, 'powerboost') === false) return '3.5L EcoBoost V6';
    if (stripos($lower, '5.0l') !== false && stripos($lower, 'v8') !== false) return '5.0L V8';
    if (stripos($lower, 'dual emotor') !== false) return 'Electric Motor';

    // Super Duty engines
    if (stripos($lower, '6.7l') !== false && stripos($lower, 'high output') !== false) return '6.7L High Output Power Stroke V8';
    if (stripos($lower, '6.7l') !== false) return '6.7L Power Stroke V8';
    if (stripos($lower, '6.8l') !== false) return '6.8L V8 Gas';
    if (stripos($lower, '7.3l') !== false) return '7.3L V8 Gas';

    // Explorer/Expedition/Bronco/Escape engines
    if (stripos($lower, '2.3l') !== false) return '2.3L EcoBoost I4';
    if (stripos($lower, '3.0l') !== false) return '3.0L EcoBoost V6';
    if (stripos($lower, '2.0l') !== false) return '2.0L EcoBoost I4';
    if (stripos($lower, '1.5l') !== false) return '1.5L EcoBoost I3';

    // Maverick engines
    if (stripos($lower, '2.5l') !== false) return '2.5L Hybrid I4';

    // Mach-E / Electric
    if (stripos($lower, 'extended range') !== false) return 'Extended Range Battery';
    if (stripos($lower, 'electric') !== false) return 'Electric Motor';

    return $engine;
}

function normalizeDrivetrain($drivetrain) {
    $drivetrain = trim($drivetrain);
    if (!$drivetrain) return '';
    $lower = strtolower($drivetrain);

    if (stripos($lower, '4x2') !== false) return '4x2';
    if (stripos($lower, 'rwd') !== false) return 'RWD';
    if (stripos($lower, '4x4') !== false) return '4x4';
    if (stripos($lower, '4wd') !== false) return '4WD';
    if (stripos($lower, 'awd') !== false) return 'AWD';
    if (stripos($lower, 'fwd') !== false) return 'FWD';

    return $drivetrain;
}

function normalizeBodyStyle($bodyStyle) {
    $bodyStyle = trim($bodyStyle);
    if (!$bodyStyle) return '';
    $lower = strtolower($bodyStyle);

    if (stripos($lower, 'regular cab') !== false) return 'Regular Cab';
    if (stripos($lower, 'super cab') !== false) return 'Super Cab';
    if (stripos($lower, 'supercrew') !== false) return 'SuperCrew';
    if (stripos($lower, 'supercab') !== false) return 'Super Cab';
    if (stripos($lower, 'crew cab') !== false) return 'Crew Cab';

    return $bodyStyle;
}

// ============================================================================
// PRICING EXTRACTION
// Centralized pricing logic used throughout the application
// ============================================================================

function extractPricing($pricingJson, $msrp = null) {
    $data = [];
    if (!empty($pricingJson)) {
        $parsed = json_decode($pricingJson, true);
        if (is_array($parsed)) $data = $parsed;
    }
    
    $result = [
        'msrp' => $data['msrp'] ?? $msrp ?? '',
        'dealer_discount' => $data['dealer_discount'] ?? '',
        'sale_price' => $data['sale_price'] ?? '',
        'factory_rebates' => $data['factory_rebates'] ?? '',
        'retail_price' => $data['retail_price'] ?? $data['total_vehicle'] ?? '',
    ];
    
    // Filter out empty values
    return array_filter($result, fn($v) => $v !== '' && $v !== null);
}

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

function stripEmpty($arr) {
    // Recursively strip empty values from arrays
    $result = [];
    foreach ($arr as $key => $value) {
        if (is_array($value)) {
            $value = stripEmpty($value);
            if (!empty($value)) {
                $result[$key] = $value;
            }
        } elseif ($value !== '' && $value !== null) {
            $result[$key] = $value;
        }
    }
    return $result;
}

function normalizeModel($model) {
    $model = trim($model);
    $lower = strtolower($model);
    
    if (stripos($lower, 'f-150 lightning') !== false || stripos($lower, 'f150 lightning') !== false) return 'f150-lightning';
    if (stripos($lower, 'f-150') !== false || stripos($lower, 'f150') !== false) return 'f150';
    if (stripos($lower, 'f-250') !== false || stripos($lower, 'f-350') !== false || 
        stripos($lower, 'f-450') !== false || stripos($lower, 'f-550') !== false ||
        stripos($lower, 'super duty') !== false) return 'super-duty';
    if (stripos($lower, 'bronco sport') !== false) return 'bronco-sport';
    if (stripos($lower, 'bronco') !== false) return 'bronco';
    if (stripos($lower, 'mustang mach-e') !== false || stripos($lower, 'mach-e') !== false) return 'mustang-mach-e';
    if (stripos($lower, 'mustang') !== false || $lower === 'gt') return 'mustang';
    if (stripos($lower, 'explorer') !== false) return 'explorer';
    if (stripos($lower, 'expedition') !== false) return 'expedition';
    if (stripos($lower, 'escape') !== false) return 'escape';
    if (stripos($lower, 'ranger') !== false) return 'ranger';
    if (stripos($lower, 'maverick') !== false) return 'maverick';
    if (stripos($lower, 'transit') !== false || stripos($lower, 'e-transit') !== false) return 'transit';
    
    return strtolower(str_replace(' ', '-', $model));
}

// ============================================================================
// MAIN CACHE GENERATION
// ============================================================================

// Dynamically build the SELECT list based on actual columns in vehicles_all.
$pragmaCmd = 'sqlite3 ' . escapeshellarg($dbPath) . ' "PRAGMA table_info(vehicles_all);"';
$schemaOutput = shell_exec($pragmaCmd);
$availableCols = [];
if ($schemaOutput !== null) {
    foreach (explode("\n", trim($schemaOutput)) as $line) {
        if ($line === '') continue;
        $parts = explode('|', $line);
        if (isset($parts[1])) {
            $availableCols[] = $parts[1];
        }
    }
}

if (empty($availableCols)) {
    echo "Error: Could not read vehicles_all schema.\n";
    exit(1);
}

$desiredColumns = [
    'vin',
    'stock',
    'photo_urls',
    'vehicle_link',
    'created_at',
    'vehicle_type',
    'year',
    'make',
    'model',
    'trim',
    'paint',
    'interior_color',
    'interior_material',
    'drivetrain',
    'body_style',
    'truck_body_style',
    'rear_axle_config',
    'engine',
    'transmission_type',
    'fuel',
    'mpg',
    'msrp',
    'total_vehicle',
    'pricing',
    'equipment_group',
    'optional',
    'standard',
    'axle_ratio',
    'axle_ratio_type',
    'wheelbase',
    'engine_displacement',
    'cylinder_count',
    'transmission_speeds',
    'final_assembly_plant',
    'method_of_transport',
    'special_order',
    'bed_length',
    'towing_capacity',
    'towing_conventional_lbs',
    'payload_capacity',
    'payload_capacity_lbs',
    'cargo_volume',
    'ground_clearance',
    'horsepower',
    'torque',
];

$selectParts = [];
$missingCols = [];
foreach ($desiredColumns as $col) {
    if (in_array($col, $availableCols, true)) {
        $selectParts[] = $col;
    } else {
        // Keep JSON shape stable with empty string fallback
        $selectParts[] = "'' AS {$col}";
        $missingCols[] = $col;
    }
}

if (!empty($missingCols)) {
    echo "Warning: Missing columns in vehicles_all, filling with empty strings: " . implode(', ', $missingCols) . "\n";
}

$selectList = "    " . implode(",\n    ", $selectParts);
$query = <<<SQL
SELECT
$selectList
FROM vehicles_all
ORDER BY stock ASC;
SQL;

$cmd = 'sqlite3 -json ' . escapeshellarg($dbPath) . ' ' . escapeshellarg($query);
$output = shell_exec($cmd);
$rows = json_decode($output, true) ?: [];

echo "Processing " . count($rows) . " vehicles...\n";

$vehiclesFull = [];
$vehiclesLite = [];

// Initialize facet counters
$facets = [
    'model' => [],
    'year' => [],
    'trim' => [],
    'package' => [],
    'engine' => [],
    'wheelbase' => [],
    'drivetrain' => [],
    'body_style' => [],
    'color' => [],
];

foreach ($rows as $row) {
    $photo = '';
    $photoInterior = '';
    $urls = [];

    if (!empty($row['photo_urls'])) {
        $urls = array_values(array_filter(array_map('trim', explode(',', $row['photo_urls']))));
        if (isset($urls[0])) $photo = $urls[0];
        if (isset($urls[9])) $photoInterior = $urls[9];
        elseif (isset($urls[1])) $photoInterior = $urls[1];
    }

    // Normalize values during cache generation
    $normalizedModel = normalizeModel($row['model'] ?? '');
    $normalizedEngine = normalizeEngine($row['engine'] ?? '');
    $normalizedDrivetrain = normalizeDrivetrain($row['drivetrain'] ?? '');
    $normalizedColor = normalizePaintColor($row['paint'] ?? '', $row['model'] ?? '');
    $normalizedBodyStyle = normalizeBodyStyle($row['truck_body_style'] ?: ($row['body_style'] ?? ''));
    
    // Extract pricing using centralized function
    $pricingData = extractPricing($row['pricing'] ?? '', $row['msrp'] ?? '');
    
    $optionalEquipment = [];
    if (!empty($row['optional'])) {
        $parsed = json_decode($row['optional'], true);
        if (is_array($parsed)) $optionalEquipment = $parsed;
    }
    
    $standardEquipment = [];
    if (!empty($row['standard'])) {
        $parsed = json_decode($row['standard'], true);
        if (is_array($parsed)) $standardEquipment = $parsed;
    }

    // Update facet counts (using normalized values)
    if ($normalizedModel) {
        $facets['model'][$normalizedModel] = ($facets['model'][$normalizedModel] ?? 0) + 1;
    }
    if ($row['year']) {
        $facets['year'][$row['year']] = ($facets['year'][$row['year']] ?? 0) + 1;
    }
    if ($row['trim']) {
        $facets['trim'][$row['trim']] = ($facets['trim'][$row['trim']] ?? 0) + 1;
    }
    if ($row['equipment_group']) {
        $facets['package'][$row['equipment_group']] = ($facets['package'][$row['equipment_group']] ?? 0) + 1;
    }
    if ($normalizedEngine) {
        $facets['engine'][$normalizedEngine] = ($facets['engine'][$normalizedEngine] ?? 0) + 1;
    }
    $wheelbase = $row['wheelbase'] ?? '';
    if ($wheelbase !== '' && $wheelbase !== null && is_numeric($wheelbase)) {
        $wheelbaseKey = number_format((float)$wheelbase, 1);
        $facets['wheelbase'][$wheelbaseKey] = ($facets['wheelbase'][$wheelbaseKey] ?? 0) + 1;
    }
    if ($normalizedDrivetrain) {
        $facets['drivetrain'][$normalizedDrivetrain] = ($facets['drivetrain'][$normalizedDrivetrain] ?? 0) + 1;
    }
    if ($normalizedBodyStyle) {
        $facets['body_style'][$normalizedBodyStyle] = ($facets['body_style'][$normalizedBodyStyle] ?? 0) + 1;
    }
    if ($normalizedColor) {
        $facets['color'][$normalizedColor] = ($facets['color'][$normalizedColor] ?? 0) + 1;
    }

    // Full vehicle record with all data
    $vehicleFull = stripEmpty([
        'vin' => $row['vin'] ?? '',
        'stock' => $row['stock'] ?? '',
        'created_at' => $row['created_at'] ?? '',
        'vehicle_type' => $row['vehicle_type'] ?? '',
        'normalized_model' => $normalizedModel,
        'year' => $row['year'] ?? '',
        'make' => $row['make'] ?? '',
        'model' => $row['model'] ?? '',
        'trim' => $row['trim'] ?? '',
        'exterior' => $normalizedColor,
        'exterior_raw' => $row['paint'] ?? '',
        'interior_color' => $row['interior_color'] ?? '',
        'interior_material' => $row['interior_material'] ?? '',
        'engine' => $normalizedEngine,
        'engine_raw' => $row['engine'] ?? '',
        'transmission' => $row['transmission_type'] ?? '',
        'drive_line' => $normalizedDrivetrain,
        'drivetrain_raw' => $row['drivetrain'] ?? '',
        'body_style' => $normalizedBodyStyle,
        'fuel_type' => $row['fuel'] ?? '',
        'rear_axle_config' => $row['rear_axle_config'] ?? '',
        'condition' => 'New',
        'fuel_economy' => $row['mpg'] ?? '',
        'vehicle_link' => $row['vehicle_link'] ?? '',
        'photo' => $photo,
        'photo_interior' => $photoInterior,
        'photo_urls' => $urls,
        'equipment_pkg' => $row['equipment_group'] ?? '',
        'optional_equipment' => $optionalEquipment,
        'standard_equipment' => $standardEquipment,
        // Pricing
        'msrp' => $pricingData['msrp'] ?? '',
        'sale_price' => $pricingData['sale_price'] ?? $pricingData['retail_price'] ?? '',
        'retail_price' => $pricingData['retail_price'] ?? '',
        'pricing_breakdown' => $pricingData,
        // Truck-specific
        'bed_length' => $row['bed_length'] ?? '',
        'towing_capacity' => $row['towing_capacity'] ?? $row['towing_conventional_lbs'] ?? '',
        'payload_capacity' => $row['payload_capacity'] ?? $row['payload_capacity_lbs'] ?? '',
        'wheelbase' => $row['wheelbase'] ? number_format((float)$row['wheelbase'], 1) : '',
        'axle_ratio' => $row['axle_ratio'] ?? '',
        // SUV-specific
        'cargo_volume' => $row['cargo_volume'] ?? '',
        'ground_clearance' => $row['ground_clearance'] ?? '',
        // Coupe-specific
        'horsepower' => $row['horsepower'] ?? '',
        'torque' => $row['torque'] ?? '',
        // Meta
        'method_of_transport' => $row['method_of_transport'] ?? '',
        'special_order' => $row['special_order'] ?? '',
    ]);
    $vehiclesFull[] = $vehicleFull;

    // Lite vehicle record - only fields needed for card display
    // This significantly reduces payload for list views
    $vehiclesLite[] = stripEmpty([
        'vin' => $row['vin'] ?? '',
        'stock' => $row['stock'] ?? '',
        'normalized_model' => $normalizedModel,
        'year' => $row['year'] ?? '',
        'make' => $row['make'] ?? '',
        'model' => $row['model'] ?? '',
        'trim' => $row['trim'] ?? '',
        'exterior' => $normalizedColor,
        'engine' => $normalizedEngine,
        'drive_line' => $normalizedDrivetrain,
        'body_style' => $normalizedBodyStyle,
        'msrp' => $pricingData['msrp'] ?? '',
        'retail_price' => $pricingData['retail_price'] ?? '',
        'photo' => $photo,
        'photo_interior' => $photoInterior,
        'equipment_pkg' => $row['equipment_group'] ?? '',
        'optional_equipment' => $optionalEquipment,
        'wheelbase' => $row['wheelbase'] ? number_format((float)$row['wheelbase'], 1) : '',
        'method_of_transport' => $row['method_of_transport'] ?? '',
    ]);
}

// Sort facets by count (descending)
foreach ($facets as $key => $values) {
    arsort($facets[$key]);
}

$dt = new DateTime('@' . $dbMtime);
$dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
$timestampDisplay = $dt->format('M j, Y g:i a T');

$responseFull = [
    'vehicles' => $vehiclesFull,
    'facets' => $facets,
    'lastUpdated' => $timestampDisplay,
    'totalCount' => count($vehiclesFull),
    'cached' => true,
    'cacheTime' => date('c'),
];

$responseLite = [
    'vehicles' => $vehiclesLite,
    'facets' => $facets,
    'lastUpdated' => $timestampDisplay,
    'totalCount' => count($vehiclesLite),
    'cached' => true,
    'cacheTime' => date('c'),
];

// Write cache files
file_put_contents($cacheFile, json_encode($responseFull));
file_put_contents($cacheLiteFile, json_encode($responseLite));

// Set mtime to match database
touch($cacheFile, $dbMtime);
touch($cacheLiteFile, $dbMtime);

$fullSize = filesize($cacheFile);
$liteSize = filesize($cacheLiteFile);
$reduction = round((1 - $liteSize / $fullSize) * 100, 1);

echo "Generated cache files:\n";
echo "  Full: $cacheFile (" . number_format($fullSize) . " bytes)\n";
echo "  Lite: $cacheLiteFile (" . number_format($liteSize) . " bytes, {$reduction}% smaller)\n";
echo "  Facets: " . count($facets) . " categories pre-computed\n";
echo "Done!\n";
