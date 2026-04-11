<?php
/**
 * Social Best-Time Suggestion Endpoint
 * GET /api/social-best-time.php?platforms=facebook,instagram
 *
 * Returns top 3 recommended UTC post windows based on follower activity data
 * cached in social_connections.follower_activity_json.
 * Falls back to global best-practice defaults if no data is available.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/social.php';

header('Content-Type: application/json');

$user = getCurrentUser();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
$userId = (int)($user['id'] ?? 0);

$db = getDB();
AyrshareClient::migrate($db);

$platformsParam = trim($_GET['platforms'] ?? '');
$platforms      = $platformsParam !== ''
    ? array_filter(array_map('trim', explode(',', $platformsParam)))
    : ['facebook', 'instagram'];

// Global best-practice posting windows (UTC) by platform
// Format: [label, ISO-pattern for "next occurrence", day_of_week, hour_utc]
$globalDefaults = [
    'facebook'  => [
        ['label' => 'Wednesday 9am',  'day' => 3, 'hour' => 9,  'minute' => 0],
        ['label' => 'Tuesday 1pm',    'day' => 2, 'hour' => 13, 'minute' => 0],
        ['label' => 'Thursday 8am',   'day' => 4, 'hour' => 8,  'minute' => 0],
    ],
    'instagram' => [
        ['label' => 'Wednesday 11am', 'day' => 3, 'hour' => 11, 'minute' => 0],
        ['label' => 'Friday 10am',    'day' => 5, 'hour' => 10, 'minute' => 0],
        ['label' => 'Tuesday 2pm',    'day' => 2, 'hour' => 14, 'minute' => 0],
    ],
    'linkedin'  => [
        ['label' => 'Tuesday 8am',    'day' => 2, 'hour' => 8,  'minute' => 0],
        ['label' => 'Wednesday 10am', 'day' => 3, 'hour' => 10, 'minute' => 0],
        ['label' => 'Thursday 9am',   'day' => 4, 'hour' => 9,  'minute' => 0],
    ],
    'twitter'   => [
        ['label' => 'Wednesday 9am',  'day' => 3, 'hour' => 9,  'minute' => 0],
        ['label' => 'Friday 9am',     'day' => 5, 'hour' => 9,  'minute' => 0],
        ['label' => 'Monday 8am',     'day' => 1, 'hour' => 8,  'minute' => 0],
    ],
    'tiktok'    => [
        ['label' => 'Tuesday 7pm',    'day' => 2, 'hour' => 19, 'minute' => 0],
        ['label' => 'Thursday 8pm',   'day' => 4, 'hour' => 20, 'minute' => 0],
        ['label' => 'Saturday 11am',  'day' => 6, 'hour' => 11, 'minute' => 0],
    ],
    'pinterest' => [
        ['label' => 'Saturday 8pm',   'day' => 6, 'hour' => 20, 'minute' => 0],
        ['label' => 'Friday 3pm',     'day' => 5, 'hour' => 15, 'minute' => 0],
        ['label' => 'Sunday 9pm',     'day' => 0, 'hour' => 21, 'minute' => 0],
    ],
    'youtube'   => [
        ['label' => 'Saturday 11am',  'day' => 6, 'hour' => 11, 'minute' => 0],
        ['label' => 'Sunday noon',    'day' => 0, 'hour' => 12, 'minute' => 0],
        ['label' => 'Friday 4pm',     'day' => 5, 'hour' => 16, 'minute' => 0],
    ],
];

// ── Load user follower activity data ────────────────────────────────────────
$connStmt = $db->prepare("SELECT follower_activity_json, activity_updated_at FROM social_connections WHERE user_id=?");
$connStmt->execute([$userId]);
$conn = $connStmt->fetch();

$activityData = [];
if ($conn && $conn['follower_activity_json']) {
    $activityData = json_decode($conn['follower_activity_json'], true) ?: [];
}

// ── Build suggestions ──────────────────────────────────────────────────────
function nextOccurrenceOf(int $dayOfWeek, int $hour, int $minute): string
{
    $now     = new \DateTime('now', new \DateTimeZone('UTC'));
    $nowDay  = (int)$now->format('w'); // 0=Sun
    $diff    = ($dayOfWeek - $nowDay + 7) % 7;
    if ($diff === 0) {
        $nowH = (int)$now->format('G');
        $nowM = (int)$now->format('i');
        if ($hour < $nowH || ($hour === $nowH && $minute <= $nowM)) {
            $diff = 7;
        }
    }
    $target = clone $now;
    $target->modify("+{$diff} days");
    $target->setTime($hour, $minute, 0);
    return $target->format(\DateTime::ATOM);
}

$suggestions = [];

foreach ($platforms as $platform) {
    $windows = [];

    if (!empty($activityData[$platform])) {
        // User has personal follower activity — derive top windows
        $hourlyData = $activityData[$platform]['hourly'] ?? [];
        arsort($hourlyData);
        $topHours = array_slice(array_keys($hourlyData), 0, 3, true);
        foreach ($topHours as $h) {
            $h = (int)$h;
            // Pick next Tuesday/Wednesday/Thursday at this hour
            $day   = [1 => 3, 2 => 2, 3 => 4][count($windows) + 1] ?? 3;
            $label = sprintf('Your peak: %02d:00 UTC', $h);
            $windows[] = [
                'label'        => $label,
                'scheduled_at' => nextOccurrenceOf($day, $h, 0),
                'source'       => 'follower_data',
            ];
        }
    }

    // Fill remaining from global defaults
    $defaults = $globalDefaults[$platform] ?? $globalDefaults['facebook'];
    foreach ($defaults as $d) {
        if (count($windows) >= 3) break;
        $windows[] = [
            'label'        => $platform . ': ' . $d['label'] . ' (best practice)',
            'scheduled_at' => nextOccurrenceOf($d['day'], $d['hour'], $d['minute']),
            'source'       => 'global_default',
        ];
    }

    $suggestions[$platform] = array_slice($windows, 0, 3);
}

echo json_encode([
    'success'     => true,
    'suggestions' => $suggestions,
    'has_personal_data' => !empty($activityData),
]);
