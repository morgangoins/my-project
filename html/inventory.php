<?php
header('Content-Type: application/json');

$directory = __DIR__ . '/../scraper';
$pattern = $directory . '/*.csv';
$files = glob($pattern);
$debug = [
    'directory' => $directory,
    'pattern' => $pattern,
    'fileCount' => $files ? count($files) : 0,
];

if (!$files) {
    echo json_encode(['vehicles' => [], 'debug' => $debug]);
    exit;
}

usort($files, function ($a, $b) {
    return filemtime($b) <=> filemtime($a);
});

$latestFile = $files[0];

// Derive timestamp from filename like inventoryNew-20251201-133814.csv
$basename = basename($latestFile);
$timestampRaw = null;
$timestampDisplay = null;
if (preg_match('/inventoryNew-(\d{8}-\d{6})\.csv$/', $basename, $m)) {
    $timestampRaw = $m[1];
    $dt = DateTime::createFromFormat('Ymd-His', $timestampRaw, new DateTimeZone('America/Los_Angeles'));
    if ($dt instanceof DateTime) {
        $timestampDisplay = $dt->format('M j, Y g:i a T');
    }
}

$handle = fopen($latestFile, 'r');

if (!$handle) {
    echo json_encode(['vehicles' => [], 'debug' => $debug]);
    exit;
}

$headers = fgetcsv($handle);
$vehicles = [];

while (($row = fgetcsv($handle)) !== false) {
    if (!$row || count($row) !== count($headers)) {
        continue;
    }

    $data = array_combine($headers, $row);

    $photo = '';
    $photoInterior = '';
    if (!empty($data['Photo URLs'])) {
        $urls = explode(',', $data['Photo URLs']);
        if (!empty($urls[0])) {
            $photo = trim($urls[0]);
        }
        if (!empty($urls[9])) {
            $photoInterior = trim($urls[9]);
        }
    }

    $vehicles[] = [
        'vin' => $data['VIN'] ?? '',
        'year' => $data['Year'] ?? '',
        'make' => $data['Make'] ?? '',
        'model' => $data['Model'] ?? '',
        'trim' => $data['Trim'] ?? '',
        'exterior' => $data['Exterior Color'] ?? '',
        'interior' => $data['Interior Color'] ?? '',
        'odometer' => $data['Odometer'] ?? '',
        'msrp' => $data['MSRP'] ?? '',
        'sale_price' => $data['Sale Price'] ?? '',
        'retail_price' => $data['Retail Price'] ?? '',
        'stock' => $data['Stock Number'] ?? '',
        'engine' => $data['Engine'] ?? '',
        'transmission' => $data['Transmission'] ?? '',
        'drive_line' => $data['Drive Line'] ?? '',
        'body_style' => $data['Body Style'] ?? '',
        'fuel_type' => $data['Fuel Type'] ?? '',
        'condition' => $data['Condition'] ?? '',
        'inventory_date' => $data['Inventory Date'] ?? '',
        'fuel_economy' => $data['Fuel Economy'] ?? '',
        'city_mpg' => $data['City Fuel Economy'] ?? '',
        'highway_mpg' => $data['Highway Fuel Economy'] ?? '',
        'vehicle_link' => $data['Vehicle Link'] ?? '',
        'photo' => $photo,
        'photo_interior' => $photoInterior,
        'photo_urls' => array_values(array_filter(array_map('trim', $urls))),
    ];
}

fclose($handle);

$debug['latestFile'] = $latestFile;
$debug['timestampRaw'] = $timestampRaw;
$debug['timestampDisplay'] = $timestampDisplay;

echo json_encode([
    'vehicles' => $vehicles,
    'debug' => $debug,
    'lastUpdated' => $timestampDisplay ?: $timestampRaw
]);

