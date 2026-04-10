<?php
/**
 * Live USD/NGN exchange rate endpoint.
 * Returns market rate + admin hidden markup applied to USD-accepting gateways.
 * Caches for 5 minutes; rate-limited to 30 req/min per user.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');

$user = getCurrentUser();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Rate limit: 30 req/min per user
if (session_status() === PHP_SESSION_NONE) session_start();
$now = time();
$_SESSION['er_req_times'] = array_filter(
    $_SESSION['er_req_times'] ?? [],
    fn($t) => ($now - $t) < 60
);
if (count($_SESSION['er_req_times']) >= 30) {
    echo json_encode(['success' => false, 'message' => 'Rate limited. Please wait.']);
    exit;
}
$_SESSION['er_req_times'][] = $now;

$db = getDB();

// Admin FX markup: hidden charge per USD (N10–N100); users never see this
$fxMarkup = 0.0;
try {
    $row = $db->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='fx_markup_ngn'"
    )->fetchColumn();
    $fxMarkup = max(0.0, min(100.0, (float)($row ?: 0)));
} catch (\Exception $e) {}

// ── Cache ─────────────────────────────────────────────────────────────────
$cacheDir  = __DIR__ . '/../storage';
$cacheFile = $cacheDir . '/fx_rate_cache.json';
$cacheTtl  = 300; // 5 minutes

$rate    = null;
$cacheTs = 0;
$source  = 'cache';

if (file_exists($cacheFile)) {
    $cached = json_decode(@file_get_contents($cacheFile), true);
    if ($cached && isset($cached['rate'], $cached['ts']) && ($now - $cached['ts']) < $cacheTtl) {
        $rate    = (float)$cached['rate'];
        $cacheTs = (int)$cached['ts'];
    }
}

// ── Fetch live rate if cache stale ────────────────────────────────────────
if ($rate === null) {
    $urls = [
        'https://open.er-api.com/v6/latest/USD',
        'https://api.exchangerate-api.com/v4/latest/USD',
    ];
    foreach ($urls as $url) {
        $ctx  = stream_context_create([
            'http' => [
                'timeout'      => 6,
                'ignore_errors' => true,
                'user_agent'   => 'Mozilla/5.0 (compatible; ExchangeRateFetcher/1.0)',
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp) {
            $data = json_decode($resp, true);
            $r    = (float)($data['rates']['NGN'] ?? 0);
            if ($r > 100) { // Sanity check: NGN/USD should be > 100
                $rate    = $r;
                $cacheTs = $now;
                $source  = 'live';
                if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
                file_put_contents(
                    $cacheFile,
                    json_encode(['rate' => $rate, 'ts' => $now]),
                    LOCK_EX
                );
                break;
            }
        }
    }
}

if ($rate === null) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to fetch live exchange rate. Please try again in a moment.',
    ]);
    exit;
}

// display_rate = rate users see & are charged (includes hidden markup)
$displayRate = $rate + $fxMarkup;
$currSym     = currencySymbol();

echo json_encode([
    'success'      => true,
    'display_rate' => round($displayRate, 4),      // NGN per $1 USD (used for all calculations)
    'source'       => $source,
    'cache_age'    => $now - $cacheTs,              // seconds since last live fetch
    'timestamp'    => $now,
    'formatted'    => $currSym . number_format($displayRate, 2) . ' per $1 USD',
]);
