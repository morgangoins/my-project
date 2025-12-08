<?php
/**
 * Inventory API (JSON) with Filtering Support
 *
 * Serves data from the SQLite database at /root/www/db/inventory.sqlite. The
 * host PHP runtime doesn't have the SQLite extension enabled, so we call the
 * sqlite3 CLI with JSON output and map the rows to the existing API shape.
 *
 * Query Parameters:
 *   - stock          : Get single vehicle by stock number (fast path)
 *   - trim[]         : Filter by trim level (multi-select)
 *   - package[]      : Filter by equipment group package (multi-select)
 *   - engine[]       : Filter by engine (multi-select, partial match)
 *   - drivetrain[]   : Filter by drivetrain (multi-select)
 *   - body_style[]   : Filter by cab/body style (multi-select)
 *   - color[]        : Filter by exterior color (multi-select, partial match)
 *   - equipment[]    : Filter by optional equipment keywords (multi-select)
 *   - price_min      : Minimum price
 *   - price_max      : Maximum price
 *   - sort           : Sort option (price_asc, price_desc, trim_asc, stock_asc)
 *   - facets         : If "1", include filter facet counts in response
 *   - lite           : If "1", return minimal fields for list view (faster)
 *   - page           : Page number for pagination (default: 1)
 *   - per_page       : Items per page (default: 24, max: 9999)
 */

$dbPath = __DIR__ . '/../db/inventory.sqlite';

// FAST PATH: Single vehicle lookup by stock number
if (isset($_GET['stock']) && !empty($_GET['stock'])) {
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=300');
    
    $stock = $_GET['stock'];
    
    if (!file_exists($dbPath)) {
        echo json_encode(['error' => 'Database not found']);
        exit;
    }
    
    // Helper function for SQL escaping
    function escapeStockSql($str) {
        return str_replace("'", "''", $str);
    }
    
    $escapedStock = escapeStockSql($stock);
    
    $query = <<<SQL
SELECT
    vin, stock, photo_urls, vehicle_link, vehicle_type,
    year, make, model, trim, paint, interior_color, interior_material,
    drivetrain, body_style, truck_body_style, rear_axle_config,
    engine, transmission_type, fuel, mpg, msrp, total_vehicle,
    equipment_group, optional, standard,
    bed_length, towing_capacity, payload_capacity,
    cargo_volume, ground_clearance, horsepower, torque
FROM vehicles_all
WHERE stock = '{$escapedStock}' OR vin = '{$escapedStock}'
LIMIT 1;
SQL;

    $cmd = 'sqlite3 -json ' . escapeshellarg($dbPath) . ' ' . escapeshellarg($query);
    $output = shell_exec($cmd);
    $rows = json_decode($output, true);
    
    if (!is_array($rows) || empty($rows)) {
        echo json_encode(['error' => 'Vehicle not found', 'stock' => $stock]);
        exit;
    }
    
    $row = $rows[0];
    
    // Parse photo URLs
    $photo = '';
    $photoInterior = '';
    $urls = [];
    if (!empty($row['photo_urls'])) {
        $urls = array_values(array_filter(array_map('trim', explode(',', $row['photo_urls']))));
        if (isset($urls[0])) $photo = $urls[0];
        if (isset($urls[9])) $photoInterior = $urls[9];
        elseif (isset($urls[1])) $photoInterior = $urls[1];
    }
    
    // Parse body style
    $bodyStyle = !empty($row['truck_body_style']) ? $row['truck_body_style'] : ($row['body_style'] ?? '');
    
    // Parse optional equipment
    $optionalEquipment = [];
    if (!empty($row['optional'])) {
        $parsed = json_decode($row['optional'], true);
        if (is_array($parsed)) $optionalEquipment = $parsed;
    }
    
    // Parse standard equipment
    $standardEquipment = [];
    if (!empty($row['standard'])) {
        $parsed = json_decode($row['standard'], true);
        if (is_array($parsed)) $standardEquipment = $parsed;
    }
    
    $vehicle = [
        'vin' => $row['vin'] ?? '',
        'stock' => $row['stock'] ?? '',
        'year' => $row['year'] ?? '',
        'make' => $row['make'] ?? '',
        'model' => $row['model'] ?? '',
        'trim' => $row['trim'] ?? '',
        'exterior' => $row['paint'] ?? '',
        'interior_color' => $row['interior_color'] ?? '',
        'interior_material' => $row['interior_material'] ?? '',
        'msrp' => $row['msrp'] ?? '',
        'retail_price' => $row['total_vehicle'] ?? '',
        'sale_price' => $row['msrp'] ?? '',
        'engine' => $row['engine'] ?? '',
        'drive_line' => $row['drivetrain'] ?? '',
        'body_style' => $bodyStyle,
        'transmission' => $row['transmission_type'] ?? '',
        'fuel_type' => $row['fuel'] ?? '',
        'fuel_economy' => $row['mpg'] ?? '',
        'condition' => 'New',
        'photo' => $photo,
        'photo_interior' => $photoInterior,
        'photo_urls' => $urls,
        'equipment_pkg' => $row['equipment_group'] ?? '',
        'vehicle_link' => $row['vehicle_link'] ?? '',
        'optional_equipment' => $optionalEquipment,
        'standard_equipment' => $standardEquipment,
        'vehicle_type' => $row['vehicle_type'] ?? '',
        'rear_axle_config' => $row['rear_axle_config'] ?? '',
        'bed_length' => $row['bed_length'] ?? '',
        'towing_capacity' => $row['towing_capacity'] ?? '',
        'payload_capacity' => $row['payload_capacity'] ?? '',
        'cargo_volume' => $row['cargo_volume'] ?? '',
        'ground_clearance' => $row['ground_clearance'] ?? '',
        'horsepower' => $row['horsepower'] ?? '',
        'torque' => $row['torque'] ?? '',
    ];
    
    echo json_encode(['vehicle' => $vehicle]);
    exit;
}

// Check for static cache file (generated periodically for speed)
$cacheFileFull = __DIR__ . '/../db/inventory_cache.json';
$cacheFileLite = __DIR__ . '/../db/inventory_cache_lite.json';
$useLiteMode = isset($_GET['lite']) && $_GET['lite'] === '1';
$hasFilters = !empty($_GET['trim']) || !empty($_GET['package']) || !empty($_GET['engine']) || 
              !empty($_GET['drivetrain']) || !empty($_GET['body_style']) || !empty($_GET['color']) ||
              !empty($_GET['equipment']) || !empty($_GET['price_min']) || !empty($_GET['price_max']);

// Pagination parameters
// Allow large page sizes for clients (like the SPA) that do client-side
// pagination, but keep a reasonable upper bound to avoid runaway queries.
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? min(9999, max(1, (int)$_GET['per_page'])) : 24;

// Set caching headers - inventory changes roughly daily
$dbMtime = file_exists($dbPath) ? filemtime($dbPath) : time();
$etag = '"' . md5($dbMtime . ($useLiteMode ? '-lite' : '') . "-p$page-$perPage") . '"';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300'); // Cache for 5 minutes
header('ETag: ' . $etag);

// Check If-None-Match for 304 response
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

// FAST PATH: Serve from pre-generated cache if no filters applied
$cacheFile = $useLiteMode ? $cacheFileLite : $cacheFileFull;
if (!$hasFilters && file_exists($cacheFile) && filemtime($cacheFile) >= $dbMtime) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if ($cached && isset($cached['vehicles'])) {
        $allVehicles = $cached['vehicles'];
        $totalCount = count($allVehicles);
        
        // Apply pagination
        $offset = ($page - 1) * $perPage;
        $paginatedVehicles = array_slice($allVehicles, $offset, $perPage);
        
        $cached['vehicles'] = $paginatedVehicles;
        $cached['totalCount'] = $totalCount;
        $cached['page'] = $page;
        $cached['perPage'] = $perPage;
        $cached['totalPages'] = ceil($totalCount / $perPage);
        $cached['servedFromCache'] = true;
        
        echo json_encode($cached);
        exit;
    }
}

$filtersPath = __DIR__ . '/filters.json';

if (!file_exists($dbPath)) {
    echo json_encode(['vehicles' => [], 'debug' => ['dbPath' => $dbPath, 'error' => 'Database not found']]);
    exit;
}

// Load filter definitions
$filterConfig = [];
if (file_exists($filtersPath)) {
    $filterConfig = json_decode(file_get_contents($filtersPath), true) ?: [];
}

// Parse query parameters
$filters = [
    'trim' => isset($_GET['trim']) ? (array)$_GET['trim'] : [],
    'package' => isset($_GET['package']) ? (array)$_GET['package'] : [],
    'engine' => isset($_GET['engine']) ? (array)$_GET['engine'] : [],
    'drivetrain' => isset($_GET['drivetrain']) ? (array)$_GET['drivetrain'] : [],
    'body_style' => isset($_GET['body_style']) ? (array)$_GET['body_style'] : [],
    'color' => isset($_GET['color']) ? (array)$_GET['color'] : [],
    'equipment' => isset($_GET['equipment']) ? (array)$_GET['equipment'] : [],
];
$priceMin = isset($_GET['price_min']) ? (float)$_GET['price_min'] : null;
$priceMax = isset($_GET['price_max']) ? (float)$_GET['price_max'] : null;
$sortOption = isset($_GET['sort']) ? $_GET['sort'] : 'stock_asc';
$includeFacets = isset($_GET['facets']) && $_GET['facets'] === '1';

// Helper function to escape SQL strings (since SQLite3 class may not be available)
function escapeSql($str) {
    return str_replace("'", "''", $str);
}

// Build WHERE clauses
$whereClauses = [];

// Trim filter
if (!empty($filters['trim'])) {
    $placeholders = [];
    foreach ($filters['trim'] as $v) {
        $placeholders[] = "trim LIKE '%" . escapeSql($v) . "%'";
    }
    $whereClauses[] = '(' . implode(' OR ', $placeholders) . ')';
}

// Package (equipment group) filter
if (!empty($filters['package'])) {
    $placeholders = [];
    foreach ($filters['package'] as $v) {
        $placeholders[] = "equipment_group = '" . escapeSql($v) . "'";
    }
    $whereClauses[] = '(' . implode(' OR ', $placeholders) . ')';
}

// Engine filter (partial match)
if (!empty($filters['engine'])) {
    $placeholders = [];
    foreach ($filters['engine'] as $v) {
        $placeholders[] = "engine LIKE '%" . escapeSql($v) . "%'";
    }
    $whereClauses[] = '(' . implode(' OR ', $placeholders) . ')';
}

// Drivetrain filter
if (!empty($filters['drivetrain'])) {
    $placeholders = [];
    foreach ($filters['drivetrain'] as $v) {
        $placeholders[] = "drivetrain = '" . escapeSql($v) . "'";
    }
    $whereClauses[] = '(' . implode(' OR ', $placeholders) . ')';
}

// Body style filter - handle variations like "SuperCrew" vs "Super Crew", "SuperCab" vs "Super Cab"
if (!empty($filters['body_style'])) {
    $placeholders = [];
    foreach ($filters['body_style'] as $v) {
        $escaped = escapeSql($v);
        // Also match the version without spaces (e.g., "Super Cab" also matches "SuperCab")
        $noSpaces = escapeSql(str_replace(' ', '', $v));
        // Also match with space inserted (e.g., "SuperCrew" also matches "Super Crew")
        $withSpace = escapeSql(preg_replace('/([a-z])([A-Z])/', '$1 $2', $v));
        $placeholders[] = "(truck_body_style LIKE '%" . $escaped . "%' OR truck_body_style LIKE '%" . $noSpaces . "%' OR truck_body_style LIKE '%" . $withSpace . "%')";
    }
    $whereClauses[] = '(' . implode(' OR ', $placeholders) . ')';
}

// Color filter (partial match on paint field)
if (!empty($filters['color'])) {
    $placeholders = [];
    foreach ($filters['color'] as $v) {
        $placeholders[] = "paint LIKE '%" . escapeSql($v) . "%'";
    }
    $whereClauses[] = '(' . implode(' OR ', $placeholders) . ')';
}

// Optional equipment filter (search in both optional and standard JSON blobs)
if (!empty($filters['equipment'])) {
    // Get keywords from filter config
    $equipmentOptions = $filterConfig['filters']['optional_equipment']['options'] ?? [];
    $keywordMap = [];
    foreach ($equipmentOptions as $opt) {
        $keywordMap[$opt['value']] = $opt['keywords'] ?? [$opt['value']];
    }
    
    $equipmentClauses = [];
    foreach ($filters['equipment'] as $equipVal) {
        $keywords = $keywordMap[$equipVal] ?? [$equipVal];
        $keywordClauses = [];
        foreach ($keywords as $kw) {
            $escaped = escapeSql(strtolower($kw));
            // Search both optional and standard columns for equipment keywords
            $keywordClauses[] = "(LOWER(optional) LIKE '%" . $escaped . "%' OR LOWER(standard) LIKE '%" . $escaped . "%')";
        }
        $equipmentClauses[] = '(' . implode(' OR ', $keywordClauses) . ')';
    }
    // All selected equipment must be present (AND logic)
    $whereClauses[] = '(' . implode(' AND ', $equipmentClauses) . ')';
}

// Price range filter
if ($priceMin !== null) {
    $whereClauses[] = "total_vehicle >= " . $priceMin;
}
if ($priceMax !== null) {
    $whereClauses[] = "total_vehicle <= " . $priceMax;
}

// Build WHERE string
$whereSQL = '';
if (!empty($whereClauses)) {
    $whereSQL = 'WHERE ' . implode(' AND ', $whereClauses);
}

// Build ORDER BY
$orderSQL = 'ORDER BY stock ASC';
switch ($sortOption) {
    case 'price_asc':
        $orderSQL = 'ORDER BY total_vehicle ASC NULLS LAST';
        break;
    case 'price_desc':
        $orderSQL = 'ORDER BY total_vehicle DESC NULLS LAST';
        break;
    case 'trim_asc':
        $orderSQL = 'ORDER BY trim ASC';
        break;
    case 'stock_asc':
    default:
        $orderSQL = 'ORDER BY stock ASC';
        break;
}

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
    -- Type-specific fields
    bed_length,
    towing_capacity,
    payload_capacity,
    cargo_volume,
    ground_clearance,
    horsepower,
    torque
FROM vehicles_all
{$whereSQL}
{$orderSQL};
SQL;

// Execute the query via the sqlite3 CLI (-json outputs a JSON array of rows).
$cmd = 'sqlite3 -json ' . escapeshellarg($dbPath) . ' ' . escapeshellarg($query);
$output = shell_exec($cmd);

if ($output === null) {
    echo json_encode(['vehicles' => [], 'debug' => ['dbPath' => $dbPath, 'error' => 'sqlite3 command failed', 'query' => $query]]);
    exit;
}

$rows = json_decode($output, true);
if (!is_array($rows)) {
    // Empty result returns empty string, not empty array
    $rows = [];
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
    // Prefer truck_body_style (cab style) over body_style (vehicle type like "Truck")
    if (!empty($row['truck_body_style'])) {
        $bodyStyle = $row['truck_body_style'];
    } elseif (!empty($row['body_style'])) {
        $bodyStyle = $row['body_style'];
    }

    // Parse optional equipment JSON if present
    $optionalEquipment = [];
    if (!empty($row['optional'])) {
        $parsed = json_decode($row['optional'], true);
        if (is_array($parsed)) {
            $optionalEquipment = $parsed;
        }
    }

    // Build vehicle record - lite mode omits large fields for faster list loading
    $vehicle = [
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
    ];
    
    // Include full data only when not in lite mode
    if (!$useLiteMode) {
        $vehicle['vehicle_type'] = $row['vehicle_type'] ?? '';
        $vehicle['interior'] = $row['interior_color'] ?? '';
        $vehicle['odometer'] = '';
        $vehicle['sale_price'] = $row['msrp'] ?? '';
        $vehicle['transmission'] = $row['transmission_type'] ?? '';
        $vehicle['fuel_type'] = $row['fuel'] ?? '';
        $vehicle['rear_axle_config'] = $row['rear_axle_config'] ?? '';
        $vehicle['inventory_date'] = '';
        $vehicle['fuel_economy'] = $row['mpg'] ?? '';
        $vehicle['city_mpg'] = '';
        $vehicle['highway_mpg'] = '';
        $vehicle['vehicle_link'] = $row['vehicle_link'] ?? '';
        $vehicle['photo_urls'] = $urls;  // Large array - only in full mode
        $vehicle['optional_equipment'] = $optionalEquipment;  // Large JSON - only in full mode
        // Truck-specific fields
        $vehicle['bed_length'] = $row['bed_length'] ?? '';
        $vehicle['towing_capacity'] = $row['towing_capacity'] ?? '';
        $vehicle['payload_capacity'] = $row['payload_capacity'] ?? '';
        // SUV-specific fields
        $vehicle['cargo_volume'] = $row['cargo_volume'] ?? '';
        $vehicle['ground_clearance'] = $row['ground_clearance'] ?? '';
        // Coupe-specific fields
        $vehicle['horsepower'] = $row['horsepower'] ?? '';
        $vehicle['torque'] = $row['torque'] ?? '';
    } else {
        // In lite mode, include minimal optional_equipment for filter matching
        $vehicle['optional_equipment'] = $optionalEquipment;
    }
    
    $vehicles[] = $vehicle;
}

// Generate facet counts if requested
$facets = null;
if ($includeFacets) {
    $facetQuery = <<<SQL
SELECT
    trim,
    equipment_group,
    engine,
    drivetrain,
    truck_body_style,
    paint,
    COUNT(*) as count
FROM vehicles_all
GROUP BY trim, equipment_group, engine, drivetrain, truck_body_style, paint;
SQL;
    
    $facetCmd = 'sqlite3 -json ' . escapeshellarg($dbPath) . ' ' . escapeshellarg($facetQuery);
    $facetOutput = shell_exec($facetCmd);
    $facetRows = json_decode($facetOutput, true) ?: [];
    
    // Aggregate facet counts
    $facets = [
        'trim' => [],
        'package' => [],
        'engine' => [],
        'drivetrain' => [],
        'body_style' => [],
        'color' => [],
    ];
    
    foreach ($facetRows as $fr) {
        $t = $fr['trim'] ?? '';
        if ($t) {
            if (!isset($facets['trim'][$t])) $facets['trim'][$t] = 0;
            $facets['trim'][$t] += $fr['count'];
        }
        
        $p = $fr['equipment_group'] ?? '';
        if ($p) {
            if (!isset($facets['package'][$p])) $facets['package'][$p] = 0;
            $facets['package'][$p] += $fr['count'];
        }
        
        $e = $fr['engine'] ?? '';
        if ($e) {
            if (!isset($facets['engine'][$e])) $facets['engine'][$e] = 0;
            $facets['engine'][$e] += $fr['count'];
        }
        
        $d = $fr['drivetrain'] ?? '';
        if ($d) {
            if (!isset($facets['drivetrain'][$d])) $facets['drivetrain'][$d] = 0;
            $facets['drivetrain'][$d] += $fr['count'];
        }
        
        $b = $fr['truck_body_style'] ?? '';
        if ($b) {
            if (!isset($facets['body_style'][$b])) $facets['body_style'][$b] = 0;
            $facets['body_style'][$b] += $fr['count'];
        }
        
        $c = $fr['paint'] ?? '';
        if ($c) {
            if (!isset($facets['color'][$c])) $facets['color'][$c] = 0;
            $facets['color'][$c] += $fr['count'];
        }
    }
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
    'appliedFilters' => array_filter($filters, function($v) { return !empty($v); }),
];

$totalCount = count($vehicles);

// Apply pagination (for filtered results)
$offset = ($page - 1) * $perPage;
$paginatedVehicles = array_slice($vehicles, $offset, $perPage);

$response = [
    'vehicles' => $paginatedVehicles,
    'debug' => $debug,
    'lastUpdated' => $timestampDisplay ?: $timestampRaw,
    'totalCount' => $totalCount,
    'page' => $page,
    'perPage' => $perPage,
    'totalPages' => ceil($totalCount / $perPage),
];

if ($facets !== null) {
    $response['facets'] = $facets;
}

if (!empty($filterConfig)) {
    $response['filterConfig'] = $filterConfig;
}

echo json_encode($response);
