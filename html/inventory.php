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

// ----------------------------------------------------------------------------
// Hardening: this endpoint must ALWAYS return valid JSON.
// In some environments PHP warnings/notices are displayed; if they leak into the
// response body they break `response.json()` in the frontend and the homepage
// appears "blank". We therefore suppress display and log server-side instead.
// ----------------------------------------------------------------------------

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_reporting', (string)E_ALL);

$logDir = __DIR__ . '/../var/logs';
if (is_dir($logDir) && is_writable($logDir)) {
    @ini_set('error_log', $logDir . '/inventory_api.log');
}

set_error_handler(function ($severity, $message, $file, $line) {
    // Log and suppress non-fatal errors so they don't corrupt JSON output.
    // Let fatal errors fall through to PHP's default handling.
    if (!(error_reporting() & $severity)) return true;
    $payload = [
        'ts' => date('c'),
        'severity' => $severity,
        'message' => $message,
        'file' => $file,
        'line' => $line,
    ];
    error_log('[inventory.php] ' . json_encode($payload));
    return true;
});

// Normalization functions for facet processing
function normalizePaintColor($paint) {
    $paint = trim($paint);
    $lower = strtolower($paint);

    // Common color normalizations
    if (stripos($lower, 'rapid red') !== false) return 'Rapid Red';
    if (stripos($lower, 'star white') !== false) return 'Star White';
    if (stripos($lower, 'space white') !== false) return 'Space White';
    if (stripos($lower, 'agate black') !== false) return 'Agate Black';
    if (stripos($lower, 'antimatter blue') !== false) return 'Antimatter Blue';
    if (stripos($lower, 'carbonized gray') !== false) return 'Carbonized Gray';
    if (stripos($lower, 'iconic silver') !== false) return 'Iconic Silver';
    if (stripos($lower, 'oxford white') !== false) return 'Oxford White';
    if (stripos($lower, 'marsh gray') !== false) return 'Marsh Gray';
    if (stripos($lower, 'atlas blue') !== false) return 'Atlas Blue';

    // Return original if no normalization matches
    return $paint;
}

function normalizeEngine($engine) {
    $engine = trim($engine);
    $lower = strtolower($engine);
    // Normalize separators so "v-6" and "v6" behave the same.
    $lower = str_replace(['-', '–', '—'], '', $lower);

    // Engine normalizations - F-150/Lightning
    if (stripos($lower, '2.7l') !== false && stripos($lower, 'v6') !== false) return '2.7L';
    if (stripos($lower, '2.7l') !== false && stripos($lower, 'ecoboost') !== false) return '2.7L';
    if (stripos($lower, '3.5l') !== false && stripos($lower, 'v6') !== false && stripos($lower, 'ecoboost') !== false) return '3.5L V6 EcoBoost';
    if (stripos($lower, '3.5l') !== false && stripos($lower, 'powerboost') !== false) return 'PowerBoost';
    if (stripos($lower, '3.5l') !== false && stripos($lower, 'high-output') !== false) return 'High Output';
    // Some sources only provide "3.5L V-6 cyl" without the EcoBoost marker.
    if (stripos($lower, '3.5l') !== false && stripos($lower, 'v6') !== false && stripos($lower, 'powerboost') === false) return '3.5L V6 EcoBoost';
    if (stripos($lower, '5.0l') !== false && stripos($lower, 'v8') !== false) return '5.0L';
    if (stripos($lower, 'dual emotor') !== false) return 'High Output'; // Lightning

    // Super Duty engines
    if (stripos($lower, '6.7l') !== false && stripos($lower, 'high output') !== false) return '6.7L High Output Power Stroke V8';
    if (stripos($lower, '6.7l') !== false) return '6.7L Power Stroke V8';
    if (stripos($lower, '6.8l') !== false) return '6.8L V8 Gas';
    if (stripos($lower, '7.3l') !== false) return '7.3L V8 Gas';

    // Explorer/Expedition engines
    if (stripos($lower, '2.3l') !== false) return '2.3L EcoBoost I4';
    if (stripos($lower, '3.0l') !== false) return '3.0L EcoBoost V6';

    // Bronco engines
    if (stripos($lower, '2.3l') !== false && !stripos($lower, 'maverick')) return '2.3L EcoBoost I4';
    if (stripos($lower, '2.7l') !== false && !stripos($lower, 'maverick')) return '2.7L EcoBoost V6';
    if (stripos($lower, '3.0l') !== false && !stripos($lower, 'maverick')) return '3.0L EcoBoost V6';

    // Bronco Sport engines
    if (stripos($lower, '1.5l') !== false) return '1.5L I3';

    // Maverick engines (specific patterns)
    if (stripos($lower, '2.0l ecoboost engine') !== false) return '2.0L EcoBoost Engine';
    if (stripos($lower, '2.5l hybrid engine') !== false) return '2.5L Hybrid Engine';

    // General 2.0L engines (Bronco Sport, Edge, Escape, etc.)
    if (stripos($lower, '2.0l') !== false) return '2.0L EcoBoost I4';

    // Mustang engines
    if (stripos($lower, '5.0l') !== false && stripos($lower, 'v8') !== false) return '5.0L V8';

    // Transit engines
    if (stripos($lower, '3.5l') !== false && stripos($lower, 'v6') !== false) return '3.5L V6';

    // Mach-E engines
    if (stripos($lower, 'extended range') !== false) return 'Extended Range Battery';

    return $engine;
}

function normalizeDrivetrain($drivetrain) {
    $drivetrain = trim($drivetrain);
    $lower = strtolower($drivetrain);

    if (stripos($lower, '4x2') !== false || stripos($lower, 'rwd') !== false) return '4x2';
    if (stripos($lower, '4x4') !== false || stripos($lower, '4wd') !== false) return '4x4';
    if (stripos($lower, 'awd') !== false) return 'AWD';

    return $drivetrain;
}

function normalizeBodyStyle($bodyStyle) {
    $bodyStyle = trim($bodyStyle);
    $lower = strtolower($bodyStyle);

    if (stripos($lower, 'regular cab') !== false) return 'Regular Cab';
    if (stripos($lower, 'super cab') !== false) return 'Super Cab';
    if (stripos($lower, 'supercrew') !== false) return 'SuperCrew';
    if (stripos($lower, 'supercab') !== false) return 'Super Cab'; // Handle variations

    return $bodyStyle;
}

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
    pricing,
    equipment_group,
    optional,
    standard,
    axle_ratio,
    axle_ratio_type,
    wheelbase,
    engine_displacement,
    cylinder_count,
    transmission_speeds,
    final_assembly_plant,
    method_of_transport,
    special_order
FROM vehicles_all
WHERE stock = '{$escapedStock}' OR vin = '{$escapedStock}'
LIMIT 1;
SQL;

    $cmd = 'sqlite3 -json ' . escapeshellarg($dbPath) . ' ' . escapeshellarg($query) . ' 2>&1';
    $output = shell_exec($cmd);
    $rows = null;
    $trimOut = trim((string)$output);
    if ($trimOut === '') {
        $rows = [];
    } else {
        $rows = json_decode($output, true);
        if (!is_array($rows)) {
            error_log('[inventory.php] sqlite3 output was not valid JSON for stock lookup: ' . substr($trimOut, 0, 500));
            $rows = [];
        }
    }
    
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

    // Parse pricing data from nested JSON
    $pricingData = [];
    if (!empty($row['pricing'])) {
        $parsed = json_decode($row['pricing'], true);
        if (is_array($parsed)) $pricingData = $parsed;
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
        'msrp' => $pricingData['msrp'] ?? $row['msrp'] ?? '',
        'retail_price' => $pricingData['retail_price'] ?? $pricingData['total_vehicle'] ?? '',
        'sale_price' => $pricingData['sale_price'] ?? $pricingData['retail_price'] ?? $pricingData['total_vehicle'] ?? '',
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
        'pricing_breakdown' => $pricingData,  // Full pricing data for detailed breakdown
    ];
    
    echo json_encode(['vehicle' => $vehicle]);
    exit;
}

// Check for static cache file (generated periodically for speed)
$cacheFileFull = __DIR__ . '/../db/inventory_cache.json';
$cacheFileLite = __DIR__ . '/../db/inventory_cache_lite.json';
$useLiteMode = isset($_GET['lite']) && $_GET['lite'] === '1';
$hasFilters = !empty($_GET['model']) || !empty($_GET['trim']) || !empty($_GET['package']) || !empty($_GET['engine']) ||
              !empty($_GET['wheelbase']) || !empty($_GET['drivetrain']) || !empty($_GET['body_style']) || !empty($_GET['color']) ||
              !empty($_GET['equipment']) || !empty($_GET['price_min']) || !empty($_GET['price_max']) ||
              !empty($_GET['facets']);

// Pagination parameters
// Allow large page sizes for clients (like the SPA) that do client-side
// pagination, but keep a reasonable upper bound to avoid runaway queries.
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? min(9999, max(1, (int)$_GET['per_page'])) : 24;

// Set caching headers - include query string so facet requests don't get a stale 304
$dbMtime = file_exists($dbPath) ? filemtime($dbPath) : time();
$etagSeed = $dbMtime . ($useLiteMode ? '-lite' : '') . "-p$page-$perPage-" . ($_SERVER['QUERY_STRING'] ?? '');
$etag = '"' . md5($etagSeed) . '"';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300'); // Cache for 5 minutes
header('ETag: ' . $etag);

// Check If-None-Match for 304 response
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

// FAST PATH: Serve from pre-generated cache if no filters applied.
//
// Resiliency: if the DB has been updated but the cache generator hasn't run yet,
// serving the slightly-stale cache is still better than breaking the homepage.
// We therefore serve cache whenever it's present + non-empty, and mark it stale
// when its mtime is older than the DB.
$cacheFile = $useLiteMode ? $cacheFileLite : $cacheFileFull;
if (!$hasFilters && file_exists($cacheFile)) {
    $cacheMtime = @filemtime($cacheFile) ?: 0;
    $cached = json_decode(@file_get_contents($cacheFile), true);
    if ($cached && isset($cached['vehicles']) && is_array($cached['vehicles']) && count($cached['vehicles']) > 0) {
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

        $isStale = ($cacheMtime < $dbMtime);
        if ($isStale) {
            $cached['servedFromStaleCache'] = true;
            $cached['debug'] = $cached['debug'] ?? [];
            $cached['debug']['cacheMtime'] = $cacheMtime ? date('Ymd-His', $cacheMtime) : null;
            $cached['debug']['dbMtime'] = $dbMtime ? date('Ymd-His', $dbMtime) : null;
            header('X-Inventory-Cache: stale');
            // Encourage clients/CDNs to revalidate more frequently when stale.
            header('Cache-Control: public, max-age=60');
        } else {
            header('X-Inventory-Cache: fresh');
        }

        echo json_encode($cached);
        exit;
    }
    // Fallback to live query if cache is empty or malformed
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
    'wheelbase' => isset($_GET['wheelbase']) ? (array)$_GET['wheelbase'] : [],
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

// Wheelbase filter
if (!empty($filters['wheelbase'])) {
    $placeholders = [];
    foreach ($filters['wheelbase'] as $v) {
        $wheelbaseValue = floatval(trim($v));
        $placeholders[] = "ABS(wheelbase - " . $wheelbaseValue . ") < 0.01";
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

// Color filter (exact match or variation-aware matching)
if (!empty($filters['color'])) {
    $colorMappingPath = __DIR__ . '/../db/color_mapping.json';
    $colorMapping = [];
    if (file_exists($colorMappingPath)) {
        $colorMapping = json_decode(file_get_contents($colorMappingPath), true) ?: [];
    }

    $colorConditions = [];
    foreach ($filters['color'] as $selectedColor) {
        $escapedColor = escapeSql($selectedColor);

        // Check if this color has variations in the mapping
        $canonicalColors = $colorMapping['canonical_colors'] ?? [];
        $matchedVariations = [$escapedColor]; // Always include the selected color itself

        foreach ($canonicalColors as $canonical => $data) {
            $variations = $data['variations'] ?? [];
            if (in_array($selectedColor, $variations) || $canonical === $selectedColor) {
                // Add all variations of this canonical color
                foreach ($variations as $variation) {
                    $matchedVariations[] = escapeSql($variation);
                }
                break; // Found the matching canonical color
            }
        }

        // Create IN clause for all matched variations
        $colorConditions[] = "paint IN ('" . implode("','", array_unique($matchedVariations)) . "')";
    }

    if (!empty($colorConditions)) {
        $whereClauses[] = '(' . implode(' OR ', $colorConditions) . ')';
    }
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
        // 2025 F-150 trims/packages that always include running boards even if not listed in equipment JSON
        if ($equipVal === 'running_boards') {
            $runningBoardPackages = ['201A', '301A', '302A', '303A', '501A', '502A'];
            $runningBoardTrims = ['king ranch', 'platinum', 'tremor', 'raptor'];
            $packageList = implode("','", array_map('escapeSql', $runningBoardPackages));
            $trimClauses = [];
            foreach ($runningBoardTrims as $t) {
                $trimClauses[] = "LOWER(trim) = '" . escapeSql($t) . "'";
            }
            $modelClause = "(LOWER(model) LIKE '%f-150%' OR LOWER(model) LIKE '%f150%')";
            $makeClause = "LOWER(make) = 'ford'";
            $trimClauseSql = '(' . implode(' OR ', $trimClauses) . ')';
            $keywordClauses[] = "(year IN ('2025','2026') AND {$makeClause} AND {$modelClause} AND ((equipment_group IN ('{$packageList}')) OR {$trimClauseSql}))";
        }
        $equipmentClauses[] = '(' . implode(' OR ', $keywordClauses) . ')';
    }
    // All selected equipment must be present (AND logic)
    $whereClauses[] = '(' . implode(' AND ', $equipmentClauses) . ')';
}

// Price range filter
if ($priceMin !== null) {
    $whereClauses[] = "(pricing IS NOT NULL AND json_extract(pricing, '$.total_vehicle') >= " . $priceMin . ")";
}
if ($priceMax !== null) {
    $whereClauses[] = "(pricing IS NOT NULL AND json_extract(pricing, '$.total_vehicle') <= " . $priceMax . ")";
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
        $orderSQL = 'ORDER BY CASE WHEN pricing IS NULL THEN 1 ELSE 0 END, json_extract(pricing, \'$.total_vehicle\') ASC NULLS LAST';
        break;
    case 'price_desc':
        $orderSQL = 'ORDER BY CASE WHEN pricing IS NULL THEN 1 ELSE 0 END, json_extract(pricing, \'$.total_vehicle\') DESC NULLS LAST';
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
    pricing,
    equipment_group,
    optional,
    standard,
    axle_ratio,
    axle_ratio_type,
    wheelbase,
    engine_displacement,
    cylinder_count,
    transmission_speeds,
    final_assembly_plant,
    method_of_transport,
    special_order
FROM vehicles_all
{$whereSQL}
{$orderSQL};
SQL;

// Execute the query via the sqlite3 CLI (-json outputs a JSON array of rows).
$cmd = 'sqlite3 -json ' . escapeshellarg($dbPath) . ' ' . escapeshellarg($query);
$output = shell_exec($cmd . ' 2>&1');

if ($output === null) {
    echo json_encode(['vehicles' => [], 'debug' => ['dbPath' => $dbPath, 'error' => 'sqlite3 command failed', 'query' => $query]]);
    exit;
}

$trimOut = trim((string)$output);
if ($trimOut === '') {
    $rows = [];
} else {
    $rows = json_decode($output, true);
    if (!is_array($rows)) {
        // If sqlite3 printed an error, log it and continue with empty results so the API stays valid JSON.
        error_log('[inventory.php] sqlite3 output was not valid JSON for list query: ' . substr($trimOut, 0, 500));
        $rows = [];
    }
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

    // Parse standard equipment JSON if present
    $standardEquipment = [];
    if (!empty($row['standard'])) {
        $parsed = json_decode($row['standard'], true);
        if (is_array($parsed)) {
            $standardEquipment = $parsed;
        }
    }

    // Parse pricing data from nested JSON
    $pricingData = [];
    if (!empty($row['pricing'])) {
        $parsed = json_decode($row['pricing'], true);
        if (is_array($parsed)) $pricingData = $parsed;
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
        'retail_price' => $pricingData['retail_price'] ?? $pricingData['total_vehicle'] ?? '',
        'stock' => $row['stock'] ?? '',
        'engine' => $row['engine'] ?? '',
        'drive_line' => $row['drivetrain'] ?? '',
        'body_style' => $bodyStyle,
        'condition' => 'New',
        'photo' => $photo,
        'photo_interior' => $photoInterior,
        'equipment_pkg' => $row['equipment_group'] ?? '',
    ];
    
    // Include ALL data for both lite and full modes to ensure detail pages load instantly
    $vehicle['vehicle_type'] = $row['vehicle_type'] ?? '';
    $vehicle['interior'] = $row['interior_color'] ?? '';
    $vehicle['odometer'] = '';
    $vehicle['sale_price'] = $pricingData['sale_price'] ?? $pricingData['retail_price'] ?? $pricingData['total_vehicle'] ?? '';
    $vehicle['transmission'] = $row['transmission_type'] ?? '';
    $vehicle['fuel_type'] = $row['fuel'] ?? '';
    $vehicle['rear_axle_config'] = $row['rear_axle_config'] ?? '';
    $vehicle['inventory_date'] = '';
    $vehicle['fuel_economy'] = $row['mpg'] ?? '';
    $vehicle['city_mpg'] = '';
    $vehicle['highway_mpg'] = '';
    $vehicle['vehicle_link'] = $row['vehicle_link'] ?? '';
    $vehicle['photo_urls'] = $urls;  // Full photo array for detail view
    $vehicle['optional_equipment'] = $optionalEquipment;
    $vehicle['standard_equipment'] = $standardEquipment;
    // Truck-specific fields
    $vehicle['bed_length'] = $row['bed_length'] ?? '';
    $vehicle['towing_capacity'] = $row['towing_capacity'] ?? '';
    $vehicle['payload_capacity'] = $row['payload_capacity'] ?? '';
    $vehicle['wheelbase'] = $row['wheelbase'] ?? '';
    // SUV-specific fields
    $vehicle['cargo_volume'] = $row['cargo_volume'] ?? '';
    $vehicle['ground_clearance'] = $row['ground_clearance'] ?? '';
    // Coupe-specific fields
    $vehicle['horsepower'] = $row['horsepower'] ?? '';
    $vehicle['torque'] = $row['torque'] ?? '';
    $vehicle['pricing_breakdown'] = $pricingData;  // Include pricing breakdown for immediate display
    
    $vehicles[] = $vehicle;
}

// Serve facets from pre-computed cache (generated by generate_cache.php)
// This eliminates an expensive GROUP BY query on every facet request
$facets = null;
if ($includeFacets) {
    // Try to load pre-computed facets from cache
    $facetsCacheFile = __DIR__ . '/../db/inventory_cache.json';
    if (file_exists($facetsCacheFile)) {
        $cachedData = json_decode(file_get_contents($facetsCacheFile), true);
        if ($cachedData && isset($cachedData['facets'])) {
            $facets = $cachedData['facets'];
        }
    }
    
    // Fallback: compute facets if cache doesn't have them (shouldn't happen normally)
    if ($facets === null) {
        $facets = [
            'trim' => [],
            'package' => [],
            'engine' => [],
            'wheelbase' => [],
            'drivetrain' => [],
            'body_style' => [],
            'color' => [],
        ];
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
