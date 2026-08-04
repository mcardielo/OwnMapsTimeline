<?php
/**
 * PlaceDetector — DBSCAN + geofence crossing for stay-point detection.
 *
 * 1. DBSCAN clusters points spatially (grid-indexed for performance)
 * 2. Each cluster becomes a "place" with centroide + radius
 * 3. Visits are determined by geofence crossing: points entering/leaving
 *    the place's radius in temporal order
 * 4. Duration = first point outside - first point inside
 *
 * Configurable params per user (from places_settings table).
 */

declare(strict_types=1);

class PlaceDetector
{
    // Default params (used when no settings exist)
    private const DEFAULT_EPSILON            = 50;    // meters
    private const DEFAULT_MIN_VISITS        = 2;
    private const DEFAULT_MIN_DURATION      = 1200;  // seconds (20 min)
    private const DEFAULT_MIN_POINTS_VISIT  = 5;
    private const DEFAULT_MERGE_DISTANCE     = 70;    // meters
    private const DEFAULT_MAX_RADIUS        = 100;   // meters — clusters bigger than this are routes, not places
    private const DEFAULT_MERGE_GAP          = 600;   // seconds (10 min) — merge visits separated by less than this
    private const OVERLAP_SECONDS           = 86400; // 1 day

    /**
     * Load detection settings for a user (or defaults).
     */
    public static function getSettings(int $userId): array
    {
        $row = Database::queryOne('SELECT * FROM places_settings WHERE user_id = ?', [$userId]);
        if ($row) {
            return [
                'epsilon'           => (float) $row['epsilon'],
                'min_visits'        => (int) $row['min_visits'],
                'min_duration'      => (int) $row['min_duration'],
                'min_points_visit'  => (int) $row['min_points_per_visit'],
                'merge_distance'    => (float) $row['merge_distance'],
                'max_radius'        => (float) ($row['max_radius'] ?? self::DEFAULT_MAX_RADIUS),
                'merge_gap'         => (int) ($row['merge_gap'] ?? self::DEFAULT_MERGE_GAP),
            ];
        }
        return [
            'epsilon'           => self::DEFAULT_EPSILON,
            'min_visits'        => self::DEFAULT_MIN_VISITS,
            'min_duration'      => self::DEFAULT_MIN_DURATION,
            'min_points_visit'  => self::DEFAULT_MIN_POINTS_VISIT,
            'merge_distance'    => self::DEFAULT_MERGE_DISTANCE,
            'max_radius'        => self::DEFAULT_MAX_RADIUS,
            'merge_gap'         => self::DEFAULT_MERGE_GAP,
        ];
    }

    /**
     * Save detection settings for a user.
     */
    public static function saveSettings(int $userId, array $params): void
    {
        $type = getenv('DB_TYPE') ?: 'sqlite';
        $sql = $type === 'mysql'
            ? 'INSERT INTO places_settings (user_id, epsilon, min_visits, min_duration, min_points_per_visit, merge_distance, max_radius, merge_gap) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE epsilon=VALUES(epsilon), min_visits=VALUES(min_visits), min_duration=VALUES(min_duration), min_points_per_visit=VALUES(min_points_per_visit), merge_distance=VALUES(merge_distance), max_radius=VALUES(max_radius), merge_gap=VALUES(merge_gap), updated_at=CURRENT_TIMESTAMP'
            : 'INSERT OR REPLACE INTO places_settings (user_id, epsilon, min_visits, min_duration, min_points_per_visit, merge_distance, max_radius, merge_gap) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

        Database::execute($sql, [
            $userId,
            (float) ($params['epsilon'] ?? self::DEFAULT_EPSILON),
            (int) ($params['min_visits'] ?? self::DEFAULT_MIN_VISITS),
            (int) ($params['min_duration'] ?? self::DEFAULT_MIN_DURATION),
            (int) ($params['min_points_visit'] ?? self::DEFAULT_MIN_POINTS_VISIT),
            (float) ($params['merge_distance'] ?? self::DEFAULT_MERGE_DISTANCE),
            (float) ($params['max_radius'] ?? self::DEFAULT_MAX_RADIUS),
            (int) ($params['merge_gap'] ?? self::DEFAULT_MERGE_GAP),
        ]);
    }

    /**
     * Run place detection for a user (hybrid: only new points since last analysis).
     */
    public static function detect(int $userId): array
    {
        $settings = self::getSettings($userId);
        $epsilon = $settings['epsilon'];
        $minVisits = $settings['min_visits'];
        $minDuration = $settings['min_duration'];
        $minPointsVisit = $settings['min_points_visit'];
        $mergeDistance = $settings['merge_distance'];
        $maxRadius = $settings['max_radius'];

        // Get all devices for this user
        $devices = Database::query(
            'SELECT id, name FROM devices WHERE user_id = ? ORDER BY id ASC',
            [$userId]
        );

        $allPlaces = [];

        foreach ($devices as $device) {
            $deviceId = (int) $device['id'];

            // Read last analysis timestamp for this device
            $meta = Database::queryOne(
                'SELECT last_analyzed_at FROM places_meta WHERE user_id = ? AND device_id = ?',
                [$userId, $deviceId]
            );
            $lastAnalyzed = $meta ? (int) $meta['last_analyzed_at'] : 0;
            $overlapFrom = max(0, $lastAnalyzed - self::OVERLAP_SECONDS);

            // Fetch locations for THIS DEVICE only
            $points = Database::query(
                'SELECT l.id, l.lat, l.lon, l.tst, l.acc, d.id AS device_id, d.name AS device_name
                 FROM locations l
                 JOIN devices d ON l.device_id = d.id
                 WHERE d.user_id = ? AND d.id = ? AND l.tst >= ? AND l.lat IS NOT NULL AND l.lon IS NOT NULL
                 ORDER BY l.tst ASC',
                [$userId, $deviceId, $overlapFrom]
            );

            if (count($points) < 5) {
                self::updateMeta($userId, $deviceId, time());
                continue;
            }

            // Run DBSCAN (no-chain: clusters defined by proximity to origin point)
            $clusters = self::dbscan($points, $epsilon, 5);

            // Each cluster becomes a candidate place
            $candidatePlaces = [];
            foreach ($clusters as $cluster) {
                if (count($cluster) < 5) continue;

                $centroid = self::centroid($cluster);
                $radius = self::clusterRadiusP90($cluster, $centroid);

                if ($radius > $maxRadius) continue;

                $firstTst = (int) $cluster[0]['tst'] - 3600;
                $lastTst = (int) $cluster[count($cluster) - 1]['tst'] + 3600;
                $visits = self::geofenceVisitsAll($points, $centroid, $radius, $minPointsVisit, $firstTst, $lastTst, $settings['merge_gap']);

                $validVisits = array_filter($visits, function ($v) use ($minDuration) {
                    return $v['duration'] >= $minDuration;
                });

                if (count($validVisits) < $minVisits) continue;

                $totalTime = array_sum(array_column($validVisits, 'duration'));
                $firstSeen = min(array_column($validVisits, 'start_tst'));
                $lastSeen = max(array_column($validVisits, 'end_tst'));

                $candidatePlaces[] = [
                    'device_id'   => $deviceId,
                    'device_name' => $device['name'],
                    'lat'         => $centroid['lat'],
                    'lon'         => $centroid['lon'],
                    'radius'      => max($radius, 15),
                    'visit_count' => count($validVisits),
                    'total_time'  => $totalTime,
                    'first_seen'  => $firstSeen,
                    'last_seen'   => $lastSeen,
                ];
            }

            // Merge candidate places with existing ones (same device only)
            $existingPlaces = self::getExistingPlaces($userId, $deviceId);

            foreach ($candidatePlaces as $cand) {
                $merged = false;
                foreach ($existingPlaces as &$place) {
                    $dist = self::haversine($cand['lat'], $cand['lon'], $place['lat'], $place['lon']);
                    if ($dist <= $mergeDistance) {
                        // Update centroid + radius with the candidate's points
                        // (keep the place geometry accurate as new points arrive)
                        $allDevicePoints = Database::query(
                            'SELECT l.lat, l.lon FROM locations l WHERE l.device_id = ? AND l.lat IS NOT NULL AND l.lon IS NOT NULL AND l.tst >= ? AND l.tst <= ?',
                            [$deviceId, (int) $place['first_seen'] - 3600, max((int) $cand['last_seen'], (int) $place['last_seen']) + 3600]
                        );
                        $placePoints = [];
                        $checkRadius = max((float) $place['radius'], $cand['radius']);
                        foreach ($allDevicePoints as $ap) {
                            $d = self::haversine((float) $place['lat'], (float) $place['lon'], (float) $ap['lat'], (float) $ap['lon']);
                            if ($d <= $checkRadius) {
                                $placePoints[] = $ap;
                            }
                        }
                        if (count($placePoints) >= 5) {
                            $newCentroid = self::centroid($placePoints);
                            $newRadius = self::clusterRadiusP90($placePoints, $newCentroid);
                            $place['lat'] = $newCentroid['lat'];
                            $place['lon'] = $newCentroid['lon'];
                            $place['radius'] = max($newRadius, 15);
                        } else {
                            $place['radius'] = max($place['radius'], $cand['radius']);
                        }

                        // Recalculate visit stats from ALL device points (not
                        // just the candidate's window). This ensures visit_count,
                        // total_time, first_seen and last_seen are always
                        // accurate regardless of the cluster's time window.
                        $realVisits = self::getVisits((int) $place['id'], $userId);
                        $place['visit_count'] = count($realVisits);
                        $place['total_time']  = array_sum(array_column($realVisits, 'duration'));
                        if (count($realVisits) > 0) {
                            $place['first_seen'] = min(array_column($realVisits, 'start_tst'));
                            $place['last_seen']  = max(array_column($realVisits, 'end_tst'));
                        }

                        $place['updated_at'] = date('Y-m-d H:i:s');
                        self::savePlace($place);
                        $merged = true;
                        break;
                    }
                }
                unset($place);

                if (!$merged) {
                    $id = Database::insert(
                        'INSERT INTO places (user_id, device_id, name, lat, lon, radius, visit_count, total_time, first_seen, last_seen)
                         VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)',
                        [$userId, $deviceId, $cand['lat'], $cand['lon'], $cand['radius'], $cand['visit_count'], $cand['total_time'], $cand['first_seen'], $cand['last_seen']]
                    );
                    $cand['id'] = (int) $id;
                    $cand['name'] = null;
                    $existingPlaces[] = $cand;
                }
            }

            self::updateMeta($userId, $deviceId, time());

            // Collect places from this device
            foreach ($existingPlaces as $p) {
                $allPlaces[] = $p;
            }
        }

        return $allPlaces;
    }

    /**
     * Geofence crossing: determine visits by tracking when points enter/leave
     * the place's radius. Points are already sorted by tst.
     *
     * A visit = 2+ consecutive points inside the radius, ending when a point
     * falls outside. Duration = first point outside - first point inside.
     *
     * @return array of visits: [{start_tst, end_tst, duration, point_count, device_name}]
     */
    /**
     * Geofence crossing on ALL user points to detect entry/exit between visits.
     * Points are sorted by tst. A point inside the radius starts/continues a
     * visit. A point outside closes it (duration = outside - first inside).
     */
    private static function geofenceVisitsAll(array $allPoints, array $centroid, float $radius, int $minPoints, int $fromTst = 0, int $toTst = 0, int $mergeGap = 0): array
    {
        $lat = $centroid['lat'];
        $lon = $centroid['lon'];
        $visits = [];

        $inVisit = false;
        $visitStart = null;
        $visitPoints = 0;
        $visitDevice = null;

        foreach ($allPoints as $p) {
            $tst = (int) $p['tst'];

            // Skip points outside the time window
            if ($fromTst > 0 && $tst < $fromTst) continue;
            if ($toTst > 0 && $tst > $toTst) {
                // Past the time window — close any open visit
                if ($inVisit && $visitPoints >= $minPoints) {
                    $visits[] = [
                        'start_tst'   => $visitStart,
                        'end_tst'     => $tst,
                        'duration'    => $tst - $visitStart,
                        'point_count' => $visitPoints,
                        'device_name' => $visitDevice,
                    ];
                }
                $inVisit = false;
                $visitStart = null;
                $visitPoints = 0;
                break; // points are sorted by tst, no need to continue
            }

            $dist = self::haversine($lat, $lon, (float) $p['lat'], (float) $p['lon']);
            $inside = ($dist <= $radius);

            if ($inside && !$inVisit) {
                $inVisit = true;
                $visitStart = $tst;
                $visitPoints = 1;
                $visitDevice = $p['device_name'] ?? '';
            } elseif ($inside && $inVisit) {
                $visitPoints++;
            } elseif (!$inside && $inVisit) {
                $inVisit = false;
                if ($visitPoints >= $minPoints) {
                    $visits[] = [
                        'start_tst'   => $visitStart,
                        'end_tst'     => $tst,
                        'duration'    => $tst - $visitStart,
                        'point_count' => $visitPoints,
                        'device_name' => $visitDevice,
                    ];
                }
                $visitStart = null;
                $visitPoints = 0;
            }
        }

        if ($inVisit && $visitPoints >= $minPoints) {
            // Find last point in the time window
            $lastTst = $toTst > 0 ? $toTst : (int) $allPoints[count($allPoints) - 1]['tst'];
            $visits[] = [
                'start_tst'   => $visitStart,
                'end_tst'     => $lastTst,
                'duration'    => $lastTst - $visitStart,
                'point_count' => $visitPoints,
                'device_name' => $visitDevice,
            ];
        }

        return self::mergeVisits($visits, $mergeGap);
    }

    /**
     * Get all places for a user.
     */
    public static function getExistingPlaces(int $userId, ?int $deviceId = null): array
    {
        if ($deviceId !== null) {
            return Database::query(
                'SELECT p.*, d.name AS device_name FROM places p JOIN devices d ON p.device_id = d.id WHERE p.user_id = ? AND p.device_id = ? ORDER BY p.visit_count DESC, p.last_seen DESC',
                [$userId, $deviceId]
            );
        }
        return Database::query(
            'SELECT p.*, d.name AS device_name FROM places p JOIN devices d ON p.device_id = d.id WHERE p.user_id = ? ORDER BY p.visit_count DESC, p.last_seen DESC',
            [$userId]
        );
    }

    /**
     * Get a single place by ID (must belong to user).
     */
    public static function getPlace(int $placeId, int $userId): ?array
    {
        return Database::queryOne(
            'SELECT * FROM places WHERE id = ? AND user_id = ?',
            [$placeId, $userId]
        );
    }

    /**
     * Get all visits for a place using geofence crossing on ALL user's points.
     */
    public static function getVisits(int $placeId, int $userId): array
    {
        $place = self::getPlace($placeId, $userId);
        if (!$place) return [];

        $settings = self::getSettings($userId);
        $radius = (float) $place['radius'];
        $lat = (float) $place['lat'];
        $lon = (float) $place['lon'];
        $minPoints = $settings['min_points_visit'];
        $minDuration = (int) $settings['min_duration'];
        $mergeGap = (int) $settings['merge_gap'];

        // Get points for THIS DEVICE only (places are per-device)
        $deviceId = (int) $place['device_id'];
        $points = Database::query(
            'SELECT l.id, l.lat, l.lon, l.tst, l.acc, d.id AS device_id, d.name AS device_name
             FROM locations l
             JOIN devices d ON l.device_id = d.id
             WHERE d.user_id = ? AND d.id = ? AND l.lat IS NOT NULL AND l.lon IS NOT NULL
             ORDER BY l.tst ASC',
            [$userId, $deviceId]
        );

        // Collect ALL visits with minPoints filter (no duration filter yet —
        // merge first, then filter by duration on merged results)
        $rawVisits = [];
        $inVisit = false;
        $visitStart = null;
        $visitPoints = 0;
        $visitDevice = null;

        foreach ($points as $p) {
            $dist = self::haversine($lat, $lon, (float) $p['lat'], (float) $p['lon']);
            $inside = ($dist <= $radius);

            if ($inside && !$inVisit) {
                $inVisit = true;
                $visitStart = (int) $p['tst'];
                $visitPoints = 1;
                $visitDevice = $p['device_name'] ?? '';
            } elseif ($inside && $inVisit) {
                $visitPoints++;
            } elseif (!$inside && $inVisit) {
                $inVisit = false;
                if ($visitPoints >= $minPoints) {
                    $rawVisits[] = [
                        'start_tst'   => $visitStart,
                        'end_tst'     => (int) $p['tst'],
                        'duration'    => (int) $p['tst'] - $visitStart,
                        'point_count' => $visitPoints,
                        'device_name' => $visitDevice,
                        'device_id'   => $p['device_id'],
                    ];
                }
                $visitStart = null;
                $visitPoints = 0;
            }
        }

        if ($inVisit && $visitPoints >= $minPoints) {
            $lastTst = (int) $points[count($points) - 1]['tst'];
            $rawVisits[] = [
                'start_tst'   => $visitStart,
                'end_tst'     => $lastTst,
                'duration'    => $lastTst - $visitStart,
                'point_count' => $visitPoints,
                'device_name' => $visitDevice,
                'device_id'   => null,
            ];
        }

        // Merge visits separated by less than merge_gap
        $merged = self::mergeVisits($rawVisits, $mergeGap);

        // Now filter by min_duration on merged results
        $visits = array_filter($merged, function ($v) use ($minDuration) {
            return $v['duration'] >= $minDuration;
        });

        return array_values($visits);
    }

    /**
     * Find visits for a manual place (given lat/lon/radius/deviceId directly,
     * without needing a place row first).
     *
     * Uses the same geofence crossing logic as getVisits(), but with relaxed
     * filters: min_visits=1, min_points_visit=1 (any visit counts).
     *
     * @return array ['visits' => [...], 'visit_count' => int, 'total_time' => int, 'first_seen' => int, 'last_seen' => int]
     */
    public static function findVisitsForManualPlace(int $userId, int $deviceId, float $lat, float $lon, float $radius): array
    {
        $settings = self::getSettings($userId);
        $minPoints = $settings['min_points_visit'];
        $minDuration = (int) $settings['min_duration'];
        $mergeGap = (int) $settings['merge_gap'];

        // Get all points for this device
        $points = Database::query(
            'SELECT l.id, l.lat, l.lon, l.tst, l.acc, d.id AS device_id, d.name AS device_name
             FROM locations l
             JOIN devices d ON l.device_id = d.id
             WHERE d.user_id = ? AND d.id = ? AND l.lat IS NOT NULL AND l.lon IS NOT NULL
             ORDER BY l.tst ASC',
            [$userId, $deviceId]
        );

        $rawVisits = [];
        $inVisit = false;
        $visitStart = null;
        $visitPoints = 0;
        $visitDevice = '';

        foreach ($points as $p) {
            $dist = self::haversine($lat, $lon, (float) $p['lat'], (float) $p['lon']);
            $inside = ($dist <= $radius);

            if ($inside && !$inVisit) {
                $inVisit = true;
                $visitStart = (int) $p['tst'];
                $visitPoints = 1;
                $visitDevice = $p['device_name'] ?? '';
            } elseif ($inside && $inVisit) {
                $visitPoints++;
            } elseif (!$inside && $inVisit) {
                $inVisit = false;
                if ($visitPoints >= $minPoints) {
                    $rawVisits[] = [
                        'start_tst'   => $visitStart,
                        'end_tst'     => (int) $p['tst'],
                        'duration'    => (int) $p['tst'] - $visitStart,
                        'point_count' => $visitPoints,
                        'device_name' => $visitDevice,
                        'device_id'   => $p['device_id'],
                    ];
                }
                $visitStart = null;
                $visitPoints = 0;
            }
        }

        // Close open visit at end of data
        if ($inVisit && $visitPoints >= $minPoints) {
            $lastTst = (int) $points[count($points) - 1]['tst'];
            $rawVisits[] = [
                'start_tst'   => $visitStart,
                'end_tst'     => $lastTst,
                'duration'    => $lastTst - $visitStart,
                'point_count' => $visitPoints,
                'device_name' => $visitDevice,
                'device_id'   => null,
            ];
        }

        // Merge visits separated by less than merge_gap
        $merged = self::mergeVisits($rawVisits, $mergeGap);

        // For manual places: apply min_duration filter but NOT min_visits
        // (even 1 visit is enough for a manually defined place)
        $visits = array_filter($merged, function ($v) use ($minDuration) {
            return $v['duration'] >= $minDuration;
        });

        $visits = array_values($visits);
        $visitCount = count($visits);
        $totalTime = array_sum(array_column($visits, 'duration'));

        $firstSeen = $visitCount > 0 ? min(array_column($visits, 'start_tst')) : 0;
        $lastSeen  = $visitCount > 0 ? max(array_column($visits, 'end_tst')) : 0;

        return [
            'visits'      => $visits,
            'visit_count' => $visitCount,
            'total_time'  => $totalTime,
            'first_seen'  => $firstSeen,
            'last_seen'   => $lastSeen,
        ];
    }

    public static function updateVisitCount(int $placeId, int $count): void
    {
        Database::execute(
            'UPDATE places SET visit_count = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$count, $placeId]
        );
    }

    /**
     * Recalculate centroid and radius for a place based on all device points
     * within the given radius of the current center.
     * Returns ['lat' => float, 'lon' => float, 'radius' => float] or null.
     */
    public static function recalculateCentroid(int $placeId, int $userId, float $radius): ?array
    {
        $place = self::getPlace($placeId, $userId);
        if (!$place) return null;

        $deviceId = (int) $place['device_id'];
        $lat = (float) $place['lat'];
        $lon = (float) $place['lon'];

        // Get all points of this device within the new radius
        $points = Database::query(
            'SELECT l.lat, l.lon FROM locations l
             WHERE l.device_id = ? AND l.lat IS NOT NULL AND l.lon IS NOT NULL
             ORDER BY l.tst ASC',
            [$deviceId]
        );

        $placePoints = [];
        foreach ($points as $p) {
            $d = self::haversine($lat, $lon, (float) $p['lat'], (float) $p['lon']);
            if ($d <= $radius) {
                $placePoints[] = $p;
            }
        }

        if (count($placePoints) < 5) return null;

        $centroid = self::centroid($placePoints);
        $newRadius = self::clusterRadiusP90($placePoints, $centroid);

        return [
            'lat' => $centroid['lat'],
            'lon' => $centroid['lon'],
            'radius' => max($newRadius, 15),
        ];
    }

    // ── DBSCAN with spatial grid ────────────────────────────────────────────

    private static $progressCallback = null;
    private static $progressTotal = 0;
    private static $progressProcessed = 0;
    private static $progressClusters = 0;

    public static function setProgressCallback(callable $cb): void
    {
        self::$progressCallback = $cb;
    }

    private static function reportProgress(): void
    {
        if (self::$progressCallback) {
            (self::$progressCallback)(self::$progressProcessed, self::$progressTotal, self::$progressClusters);
        }
    }

    private static function dbscan(array $points, float $epsilonMeters, int $minPts): array
    {
        $n = count($points);
        if ($n < $minPts) return [];

        self::$progressTotal = $n;
        self::$progressProcessed = 0;
        self::$progressClusters = 0;

        // Build spatial grid for O(n) neighbor lookups
        $cellSize = $epsilonMeters / 111000.0;
        $grid = [];
        for ($i = 0; $i < $n; $i++) {
            $lat = (float) $points[$i]['lat'];
            $lon = (float) $points[$i]['lon'];
            $gx = (int) floor(($lat + 90.0) / $cellSize);
            $gy = (int) floor(($lon + 180.0) / $cellSize);
            $key = $gx . ',' . $gy;
            if (!isset($grid[$key])) $grid[$key] = [];
            $grid[$key][] = $i;
        }

        $visited = array_fill(0, $n, false);
        $assigned  = array_fill(0, $n, false);
        $clusters = [];

        $reportEvery = max(1, (int) floor($n / 20));

        for ($i = 0; $i < $n; $i++) {
            self::$progressProcessed = $i + 1;

            if ($visited[$i]) continue;
            $visited[$i] = true;

            // Find all points within epsilon of point i (neighbors)
            $neighbors = self::gridRegionQuery($points, $i, $epsilonMeters, $grid, $cellSize);

            if (count($neighbors) < $minPts) {
                // Not enough neighbors = noise
                if ($i % $reportEvery === 0) self::reportProgress();
                continue;
            }

            // Point i is a CORE point. Start a cluster.
            // Collect ALL points within epsilon of the ORIGIN point i,
            // plus points within epsilon of those neighbors that are
            // ALSO within epsilon of the origin. No chain expansion.
            $cluster = [$points[$i]];
            $assigned[$i] = true;

            $origLat = (float) $points[$i]['lat'];
            $origLon = (float) $points[$i]['lon'];

            foreach ($neighbors as $nbIdx) {
                if ($assigned[$nbIdx]) continue;

                // This neighbor is within epsilon of origin — add to cluster
                $cluster[] = $points[$nbIdx];
                $assigned[$nbIdx] = true;
                $visited[$nbIdx] = true;

                // Check this neighbor's neighbors, but ONLY add those
                // that are also within epsilon of the ORIGIN point i
                $nbNeighbors = self::gridRegionQuery($points, $nbIdx, $epsilonMeters, $grid, $cellSize);
                foreach ($nbNeighbors as $nnIdx) {
                    if ($assigned[$nnIdx]) continue;

                    // Only add if within epsilon of the ORIGIN, not just of the neighbor
                    $distToOrigin = self::haversine($origLat, $origLon, (float) $points[$nnIdx]['lat'], (float) $points[$nnIdx]['lon']);
                    if ($distToOrigin <= $epsilonMeters) {
                        $cluster[] = $points[$nnIdx];
                        $assigned[$nnIdx] = true;
                        $visited[$nnIdx] = true;
                    }
                }
            }

            $clusters[] = $cluster;
            self::$progressClusters++;

            if ($i % $reportEvery === 0) self::reportProgress();
        }

        self::reportProgress();
        return $clusters;
    }

    private static function gridRegionQuery(array $points, int $i, float $epsilonMeters, array $grid, float $cellSize): array
    {
        $neighbors = [];
        $p1 = $points[$i];
        $lat = (float) $p1['lat'];
        $lon = (float) $p1['lon'];
        $gx = (int) floor(($lat + 90.0) / $cellSize);
        $gy = (int) floor(($lon + 180.0) / $cellSize);

        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                $key = ($gx + $dx) . ',' . ($gy + $dy);
                if (!isset($grid[$key])) continue;

                foreach ($grid[$key] as $j) {
                    if ($j === $i) continue;
                    $p2 = $points[$j];
                    $dist = self::haversine($lat, $lon, (float) $p2['lat'], (float) $p2['lon']);
                    if ($dist <= $epsilonMeters) {
                        $neighbors[] = $j;
                    }
                }
            }
        }

        return $neighbors;
    }

    // ── Cluster helpers ─────────────────────────────────────────────────────

    private static function centroid(array $cluster): array
    {
        $sumLat = 0;
        $sumLon = 0;
        foreach ($cluster as $p) {
            $sumLat += (float) $p['lat'];
            $sumLon += (float) $p['lon'];
        }
        $count = count($cluster);
        return ['lat' => $sumLat / $count, 'lon' => $sumLon / $count];
    }

    private static function clusterRadius(array $cluster, array $centroid): float
    {
        $maxDist = 0;
        foreach ($cluster as $p) {
            $dist = self::haversine($centroid['lat'], $centroid['lon'], (float) $p['lat'], (float) $p['lon']);
            if ($dist > $maxDist) $maxDist = $dist;
        }
        return round($maxDist, 1);
    }

    /**
     * Percentile 90 of distances from centroid.
     * More robust than max — a few outlier points won't inflate the radius.
     */
    private static function clusterRadiusP90(array $cluster, array $centroid): float
    {
        $distances = [];
        foreach ($cluster as $p) {
            $distances[] = self::haversine($centroid['lat'], $centroid['lon'], (float) $p['lat'], (float) $p['lon']);
        }
        sort($distances);
        $idx = (int) floor(count($distances) * 0.9);
        if ($idx >= count($distances)) $idx = count($distances) - 1;
        return round($distances[$idx], 1);
    }

    private static function savePlace(array $place): void
    {
        Database::execute(
            'UPDATE places SET lat = ?, lon = ?, radius = ?, visit_count = ?, total_time = ?, first_seen = ?, last_seen = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$place['lat'], $place['lon'], $place['radius'], $place['visit_count'], $place['total_time'], $place['first_seen'], $place['last_seen'], $place['id']]
        );
    }

    /**
     * Merge visits that are separated by less than merge_gap seconds.
     * If two consecutive visits have a gap < merge_gap, they become one visit
     * (start_tst = first visit start, end_tst = second visit end, points summed).
     */
    private static function mergeVisits(array $visits, int $mergeGap): array
    {
        if (count($visits) < 2 || $mergeGap <= 0) return $visits;

        // Sort by start_tst just in case
        usort($visits, function ($a, $b) {
            return $a['start_tst'] <=> $b['start_tst'];
        });

        $merged = [$visits[0]];
        for ($i = 1; $i < count($visits); $i++) {
            $last = &$merged[count($merged) - 1];
            $gap = $visits[$i]['start_tst'] - $last['end_tst'];
            if ($gap < $mergeGap) {
                // Merge: extend end, sum points, keep first device
                $last['end_tst'] = $visits[$i]['end_tst'];
                $last['duration'] = $last['end_tst'] - $last['start_tst'];
                $last['point_count'] += $visits[$i]['point_count'];
            } else {
                $merged[] = $visits[$i];
            }
            unset($last);
        }
        return $merged;
    }

    private static function updateMeta(int $userId, int $deviceId, int $timestamp): void
    {
        $type = getenv('DB_TYPE') ?: 'sqlite';
        if ($type === 'mysql') {
            Database::execute(
                'INSERT INTO places_meta (user_id, device_id, last_analyzed_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_analyzed_at = VALUES(last_analyzed_at)',
                [$userId, $deviceId, $timestamp]
            );
        } else {
            Database::execute(
                'INSERT OR REPLACE INTO places_meta (user_id, device_id, last_analyzed_at) VALUES (?, ?, ?)',
                [$userId, $deviceId, $timestamp]
            );
        }
    }

    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}