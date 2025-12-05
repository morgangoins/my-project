<?php
/**
 * Inventory API (JSON)
 *
 * Serves data from the SQLite database at /root/www/db/inventory.sqlite. The
 * host PHP runtime doesn't have the SQLite extension enabled, so we call the
 * sqlite3 CLI with JSON output and map the rows to the existing API shape.
 */
header('Content-Type: application/json');

$dbPath = __DIR__ . '/../db/inventory.sqlite';

if (!file_exists($dbPath)) {
    echo json_encode(['vehicles' => [], 'debug' => ['dbPath' => $dbPath, 'error' => 'Database not found']]);
    exit;
}

$query = <<<'SQL'
SELECT
    vin,
    stock,
    photo_urls,
    vehicle_link,
    year,
    make,
    model,
    trim,
    paint,
    interior_color,
    drivetrain,
    body_style,
    truck_body_style,
    rear_axle_config,
    engine,
    transmission_type,
    fuel,
    mpg,
    msrp,
    total_vehicle
FROM vehicles
ORDER BY stock;
SQL;

// Execute the query via the sqlite3 CLI (-json outputs a JSON array of rows).
$cmd = 'sqlite3 -json ' . escapeshellarg($dbPath) . ' ' . escapeshellarg($query);
$output = shell_exec($cmd);

if ($output === null) {
    echo json_encode(['vehicles' => [], 'debug' => ['dbPath' => $dbPath, 'error' => 'sqlite3 command failed']]);
    exit;
}

$rows = json_decode($output, true);
if (!is_array($rows)) {
    echo json_encode(['vehicles' => [], 'debug' => ['dbPath' => $dbPath, 'error' => 'Invalid JSON from sqlite3']]);
    exit;
}

$vehicles = [];

foreach ($rows as $row) {
    $photo = '';
    $photoInterior = '';
    $urls = [];

    if (!empty($row['photo_urls'])) {
        $urls = array_values(array_filter(array_map('trim', explode(',', $row['photo_urls']))));
        if (isset($urls[0])) {
            $photo = $urls[0];
        }
        if (isset($urls[9])) {
            $photoInterior = $urls[9];
        } elseif (isset($urls[1])) {
            $photoInterior = $urls[1];
        }
    }

    $bodyStyle = '';
    if (!empty($row['body_style'])) {
        $bodyStyle = $row['body_style'];
    } elseif (!empty($row['truck_body_style'])) {
        $bodyStyle = $row['truck_body_style'];
    }

    $vehicles[] = [
        'vin' => $row['vin'] ?? '',
        'year' => $row['year'] ?? '',
        'make' => $row['make'] ?? '',
        'model' => $row['model'] ?? '',
        'trim' => $row['trim'] ?? '',
        'exterior' => $row['paint'] ?? '',
        'interior' => $row['interior_color'] ?? '',
        'odometer' => '', // not tracked in the current DB
        'msrp' => $row['msrp'] ?? '',
        // Use MSRP as a fallback sale price so the UI continues to show a value.
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
        'inventory_date' => '',
        'fuel_economy' => $row['mpg'] ?? '',
        'city_mpg' => '',
        'highway_mpg' => '',
        'vehicle_link' => $row['vehicle_link'] ?? '',
        'photo' => $photo,
        'photo_interior' => $photoInterior,
        'photo_urls' => $urls,
    ];
}

$dbTimestamp = filemtime($dbPath);
$timestampDisplay = null;
$timestampRaw = $dbTimestamp ? date('Ymd-His', $dbTimestamp) : null;
if ($dbTimestamp) {
    $dt = new DateTime('@' . $dbTimestamp);
    $dt->setTimezone(new DateTimeZone('America/Los_Angeles'));
    $timestampDisplay = $dt->format('M j, Y g:i a T');
}

$debug = [
    'dbPath' => $dbPath,
    'rowCount' => count($vehicles),
    'timestampRaw' => $timestampRaw,
    'timestampDisplay' => $timestampDisplay,
];

echo json_encode([
    'vehicles' => $vehicles,
    'debug' => $debug,
    'lastUpdated' => $timestampDisplay ?: $timestampRaw,
]);
