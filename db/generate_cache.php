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
if (file_exists($cacheFile) && filemtime($cacheFile) >= $dbMtime) {
    echo "Cache is already up to date (db: " . date('Y-m-d H:i:s', $dbMtime) . ")\n";
    exit(0);
}

echo "Generating cache for database updated at " . date('Y-m-d H:i:s', $dbMtime) . "\n";

$query = <<<SQL
SELECT
    vin,
    stock,
    photo_urls,
    vehicle_link,
    vehicle_type,
    year,
    make,
    model,
    trim,
    paint,
    interior_color,
    interior_material,
    drivetrain,
    body_style,
    truck_body_style,
    rear_axle_config,
    engine,
    transmission_type,
    fuel,
    mpg,
    msrp,
    total_vehicle,
    equipment_group,
    optional,
    standard,
    bed_length,
    towing_capacity,
    payload_capacity,
    cargo_volume,
    ground_clearance,
    horsepower,
    torque
FROM vehicles_all
ORDER BY stock ASC;
SQL;

$cmd = 'sqlite3 -json ' . escapeshellarg($dbPath) . ' ' . escapeshellarg($query);
$output = shell_exec($cmd);
$rows = json_decode($output, true) ?: [];

echo "Processing " . count($rows) . " vehicles...\n";

$vehiclesFull = [];
$vehiclesLite = [];

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

    $bodyStyle = $row['truck_body_style'] ?: ($row['body_style'] ?? '');
    
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

    // Full vehicle record
    $vehicleFull = [
        'vin' => $row['vin'] ?? '',
        'vehicle_type' => $row['vehicle_type'] ?? '',
        'year' => $row['year'] ?? '',
        'make' => $row['make'] ?? '',
        'model' => $row['model'] ?? '',
        'trim' => $row['trim'] ?? '',
        'exterior' => $row['paint'] ?? '',
        'interior' => $row['interior_color'] ?? '',
        'interior_color' => $row['interior_color'] ?? '',
        'interior_material' => $row['interior_material'] ?? '',
        'msrp' => $row['msrp'] ?? '',
        'sale_price' => $row['msrp'] ?? '',
        'retail_price' => $row['total_vehicle'] ?? '',
        'stock' => $row['stock'] ?? '',
        'engine' => $row['engine'] ?? '',
        'transmission' => $row['transmission_type'] ?? '',
        'drive_line' => $row['drivetrain'] ?? '',
        'body_style' => $bodyStyle,
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
        'bed_length' => $row['bed_length'] ?? '',
        'towing_capacity' => $row['towing_capacity'] ?? '',
        'payload_capacity' => $row['payload_capacity'] ?? '',
        'cargo_volume' => $row['cargo_volume'] ?? '',
        'ground_clearance' => $row['ground_clearance'] ?? '',
        'horsepower' => $row['horsepower'] ?? '',
        'torque' => $row['torque'] ?? '',
    ];
    $vehiclesFull[] = $vehicleFull;

    // Lite vehicle record (for list view)
    $vehiclesLite[] = [
        'vin' => $row['vin'] ?? '',
        'year' => $row['year'] ?? '',
        'make' => $row['make'] ?? '',
        'model' => $row['model'] ?? '',
        'trim' => $row['trim'] ?? '',
        'exterior' => $row['paint'] ?? '',
        'interior_color' => $row['interior_color'] ?? '',
        'interior_material' => $row['interior_material'] ?? '',
        'msrp' => $row['msrp'] ?? '',
        'retail_price' => $row['total_vehicle'] ?? '',
        'stock' => $row['stock'] ?? '',
        'engine' => $row['engine'] ?? '',
        'drive_line' => $row['drivetrain'] ?? '',
        'body_style' => $bodyStyle,
        'condition' => 'New',
        'photo' => $photo,
        'photo_interior' => $photoInterior,
        'equipment_pkg' => $row['equipment_group'] ?? '',
        'optional_equipment' => $optionalEquipment,
    ];
}

$dt = new DateTime('@' . $dbMtime);
$dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
$timestampDisplay = $dt->format('M j, Y g:i a T');

$responseFull = [
    'vehicles' => $vehiclesFull,
    'lastUpdated' => $timestampDisplay,
    'totalCount' => count($vehiclesFull),
    'cached' => true,
    'cacheTime' => date('c'),
];

$responseLite = [
    'vehicles' => $vehiclesLite,
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

echo "Generated cache files:\n";
echo "  Full: $cacheFile (" . number_format($fullSize) . " bytes)\n";
echo "  Lite: $cacheLiteFile (" . number_format($liteSize) . " bytes)\n";
echo "Done!\n";
