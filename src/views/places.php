<?php
/** Helper functions for places views */
function _fmtDuration(int $seconds): string {
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return round($seconds / 60) . 'm';
    if ($seconds < 86400) return round($seconds / 3600, 1) . 'h';
    return round($seconds / 86400, 1) . 'd';
}
function _fmtDate(int $tst): string {
    return date('M j, Y', $tst);
}
function _fmtDateTime(int $tst): string {
    return date('M j, Y, H:i', $tst);
}

$detecting = ($detectStatus['status'] ?? 'idle') === 'running';
$detectMessage = $detectStatus['message'] ?? '';
$detectFound = $detectStatus['places_found'] ?? 0;
$hasPlaces = !empty($named) || !empty($unnamed);
$isIdle = ($detectStatus['status'] ?? 'idle') === 'idle';
?>

<div class="max-w-[1200px] mx-auto px-4 py-8">
    <div class="flex items-center justify-between mb-2">
        <h2 class="text-2xl font-bold">📍 Places</h2>
        <div class="flex items-center gap-3">
            <button onclick="openAddPlaceModal()" class="bg-blue-100 hover:bg-blue-200 text-blue-700 px-3 py-1.5 rounded text-sm font-medium transition">
                ➕ Add place
            </button>
            <?php if ($hasPlaces && !$detecting): ?>
            <button onclick="triggerIncremental()" class="bg-green-100 hover:bg-green-200 text-green-700 px-3 py-1.5 rounded text-sm font-medium transition">
                🔍 Detect new
            </button>
            <?php endif; ?>
            <button onclick="openSettingsModal()" class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-3 py-1.5 rounded text-sm font-medium transition">
                ⚙️ Settings
            </button>
            <a href="/dashboard" class="text-blue-600 hover:underline text-sm">← Back to map</a>
        </div>
    </div>
    <p class="text-gray-500 text-sm mb-6">Automatically detected places from your location history</p>

    <!-- Detection status banner -->
    <?php if ($detecting): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 flex items-center gap-3" id="detectingBanner">
        <div class="loading-spinner" style="border-top-color:#2563eb"></div>
        <div class="flex-1">
            <div class="text-blue-700 font-medium">Detecting places...</div>
            <div class="text-blue-600 text-sm" id="detectMessage"><?= View::esc($detectMessage) ?> — <?= (int) $detectFound ?> places found so far</div>
        </div>
    </div>
    <script>
        // Auto-refresh while detecting
        (function() {
            var banner = document.getElementById('detectingBanner');
            if (!banner) return;
            var checkInterval = setInterval(function() {
                fetch('/api/places/status').then(function(r) { return r.json(); }).then(function(data) {
                    if (data.ok && data.status) {
                        var msg = document.getElementById('detectMessage');
                        if (data.status.status === 'done') {
                            clearInterval(checkInterval);
                            window.location.reload();
                        } else if (data.status.status === 'running') {
                            if (msg) {
                                var progress = data.status.progress ? ' (' + data.status.progress + '%)' : '';
                                msg.textContent = (data.status.message || 'Detecting...') + progress;
                            }
                        } else if (data.status.status === 'error') {
                            clearInterval(checkInterval);
                            if (msg) msg.textContent = 'Error: ' + (data.status.message || 'Unknown error');
                            msg.style.color = '#dc2626';
                        }
                    }
                }).catch(function(e) {});
            }, 3000);
        })();
    </script>
    <?php elseif (($detectStatus['status'] ?? 'idle') === 'done'): ?>
    <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-6 text-sm text-green-700" id="doneBanner">
        ✅ Detection complete — <?= (int) $detectFound ?> places found. 
        <a href="#" onclick="triggerDetect(); return false;" class="text-blue-600 hover:underline">Run again</a>
    </div>
    <?php elseif (($detectStatus['status'] ?? 'idle') === 'error'): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-6 text-sm text-red-700">
        ❌ Detection error: <?= View::esc($detectStatus['message'] ?? 'Unknown') ?>
        <a href="#" onclick="triggerDetect(); return false;" class="text-blue-600 hover:underline ml-2">Try again</a>
    </div>
    <?php endif; ?>

    <!-- Empty state: no places and not detecting -->
    <?php if (!$hasPlaces && !$detecting): ?>
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
            <p class="text-yellow-700">No places detected yet.</p>
            <p class="text-yellow-600 text-sm mt-2">Places are detected automatically from your location history using DBSCAN clustering.</p>
            <button onclick="triggerDetect()" class="mt-4 bg-blue-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-blue-700 transition">
                🔍 Detect places now
            </button>
        </div>
    
    <?php endif; ?>

    <!-- Named places -->
    <?php if (!empty($named)): ?>
    <h2 class="text-lg font-semibold mb-3 text-gray-700">📍 My Places (<?= count($named) ?>)</h2>
    <div class="grid gap-3 mb-8">
        <?php foreach ($named as $p): ?>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 flex items-center justify-between hover:shadow-md transition">
            <div class="flex-1">
                <a href="/places/<?= (int) $p['id'] ?>" class="font-semibold text-blue-600 hover:underline">
                    <?= View::esc($p['name']) ?>
                </a>
                <div class="text-xs text-gray-500 mt-1">
                    <?= (int) $p['visit_count'] ?> visits ·
                    <?= _fmtDuration((int) $p['total_time']) ?> total ·
                    Last: <?= _fmtDate((int) $p['last_seen']) ?>
                </div>
                <div class="text-xs text-gray-400 mt-0.5">
                    <?= View::esc($p['device_name'] ?? '') ?> ·
                    <?= number_format((float) $p['lat'], 5) ?>, <?= number_format((float) $p['lon'], 5) ?> ·
                    ~<?= number_format((float) $p['radius'], 0) ?>m radius
                </div>
            </div>
            <div class="flex gap-2 ml-3">
                <a href="/places/<?= (int) $p['id'] ?>" class="inline-flex items-center gap-1 bg-blue-50 hover:bg-blue-100 text-blue-600 text-xs font-medium px-2.5 py-1 rounded transition">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    View
                </a>
                <form method="POST" action="/places/delete" onsubmit="return confirm('Delete this place?')">
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button class="inline-flex items-center gap-1 bg-red-50 hover:bg-red-100 text-red-600 text-xs font-medium px-2.5 py-1 rounded transition">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        Delete
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Unnamed places (detected) -->
    <?php if (!empty($unnamed)): ?>
    <h2 class="text-lg font-semibold mb-3 text-gray-700">🔍 Detected Places (<?= count($unnamed) ?>)</h2>
    <div class="text-xs text-gray-400 mb-3">Sorted by visit count. Click a place to name it or see details.</div>
    <div class="grid gap-3">
        <?php foreach ($unnamed as $p): ?>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 flex items-center justify-between hover:shadow-md transition">
            <div class="flex-1">
                <a href="/places/<?= (int) $p['id'] ?>" class="font-semibold text-gray-700 hover:text-blue-600 hover:underline">
                    Unnamed place
                </a>
                <div class="text-xs text-gray-500 mt-1">
                    <?= (int) $p['visit_count'] ?> visits ·
                    <?= _fmtDuration((int) $p['total_time']) ?> total ·
                    Last: <?= _fmtDate((int) $p['last_seen']) ?>
                </div>
                <div class="text-xs text-gray-400 mt-0.5">
                    <?= View::esc($p['device_name'] ?? '') ?> ·
                    <?= number_format((float) $p['lat'], 5) ?>, <?= number_format((float) $p['lon'], 5) ?> ·
                    ~<?= number_format((float) $p['radius'], 0) ?>m radius
                </div>
            </div>
            <div class="flex gap-2 ml-3">
                <a href="/places/<?= (int) $p['id'] ?>" class="inline-flex items-center gap-1 bg-blue-50 hover:bg-blue-100 text-blue-600 text-xs font-medium px-2.5 py-1 rounded transition">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    View
                </a>
                <form method="POST" action="/places/delete" onsubmit="return confirm('Delete this place?')">
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button class="inline-flex items-center gap-1 bg-red-50 hover:bg-red-100 text-red-600 text-xs font-medium px-2.5 py-1 rounded transition">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        Delete
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Cron log (collapsible) -->
    <div class="mt-8">
        <button onclick="toggleCronLog()" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
            <span id="cron-toggle-icon">▶</span> Cron detection log
        </button>
        <div id="cron-log-content" class="hidden mt-2 bg-gray-50 rounded border border-gray-200 p-3 text-xs font-mono text-gray-600 max-h-48 overflow-y-auto">
            Loading...
        </div>
    </div>

    <!-- Settings modal -->
    <div id="settingsModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40" onclick="if(event.target===this)closeSettingsModal()">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between p-5 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-700">⚙️ Detection Settings</h2>
                <button onclick="closeSettingsModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <div class="p-5">
                <p class="text-xs text-gray-400 mb-4">Adjust how places are detected from your location data. Changes apply on next detection.</p>
                <form method="POST" action="/places/settings" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Epsilon (meters)</label>
                        <input type="number" name="epsilon" value="<?= $settings['epsilon'] ?>" min="5" max="200" step="5"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-400 mt-1">Max distance between points to group them as the same place. Lower = more precise, Higher = groups wider areas.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Min visits</label>
                        <input type="number" name="min_visits" value="<?= $settings['min_visits'] ?>" min="1" max="50"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-400 mt-1">Minimum number of visits for a place to be kept. Higher = only frequent places are shown.</p>
                        <p class="text-xs text-blue-400 mt-0.5">⚠️ Only affects new detections. Existing places are not removed on incremental detect. Run re-detect to apply this filter to existing unnamed places.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Min duration (minutes)</label>
                        <input type="number" name="min_duration" value="<?= round($settings['min_duration'] / 60) ?>" min="1" max="120"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-400 mt-1">Minimum time spent for a visit to count. Shorter stops are ignored.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Min points per visit</label>
                        <input type="number" name="min_points_visit" value="<?= $settings['min_points_visit'] ?>" min="1" max="20"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-400 mt-1">Minimum points inside the place radius to count as a visit. Prevents false visits from passing by.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Merge distance (meters)</label>
                        <input type="number" name="merge_distance" value="<?= $settings['merge_distance'] ?>" min="10" max="500" step="10"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-400 mt-1">Max distance to merge nearby places into one. Places closer than this are combined.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Max radius (meters)</label>
                        <input type="number" name="max_radius" value="<?= $settings['max_radius'] ?>" min="20" max="1000" step="10"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-400 mt-1">Clusters bigger than this are discarded as routes (e.g. driving with frequent reports). Lower = stricter, only small stay points detected.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Merge gap (minutes)</label>
                        <input type="number" name="merge_gap" value="<?= round($settings['merge_gap'] / 60) ?>" min="0" max="60" step="1"
                            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-400 mt-1">Merge visits separated by less than this time. Prevents GPS jitter from splitting one visit into multiple. 0 = no merge.</p>
                    </div>
                    <div class="sm:col-span-2 border-t border-gray-200 pt-4 mt-4 flex items-center justify-between">
                        <button type="button" onclick="triggerDetect('redetect')" class="bg-red-100 hover:bg-red-200 text-red-700 px-4 py-2 rounded text-sm font-medium transition">
                            🔄 <?= $hasPlaces ? 'Re-detect (delete unnamed & re-run)' : 'Detect places' ?>
                        </button>
                        <div class="flex gap-2">
                            <button type="button" onclick="closeSettingsModal()" class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2 rounded text-sm font-medium transition">
                                Cancel
                            </button>
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-blue-700 transition">
                                💾 Save settings
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Add place modal -->
<div id="addPlaceModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40" onclick="if(event.target===this)closeAddPlaceModal()">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
        <div class="flex items-center justify-between p-5 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-700">➕ Add Place Manually</h2>
            <button onclick="closeAddPlaceModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="/places/create" class="p-5 space-y-4" onsubmit="return validateAddPlace()">
            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Device *</label>
                <select name="device_id" required class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="" disabled selected>Select a device…</option>
                    <?php foreach ($devices as $d): ?>
                        <option value="<?= (int) $d['id'] ?>"><?= View::esc($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Latitude *</label>
                    <input type="number" name="lat" step="0.00001" min="-90" max="90" required
                           placeholder="e.g. 19.43260"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Longitude *</label>
                    <input type="number" name="lon" step="0.00001" min="-180" max="180" required
                           placeholder="e.g. -99.13320"
                           class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Radius (meters) *</label>
                <input type="number" name="radius" value="50" min="10" max="1000" step="5" required
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <p class="text-xs text-gray-400 mt-1">The system will search for visits within this radius.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-600 mb-1">Name (optional)</label>
                <input type="text" name="name" placeholder="e.g. Home, Office, Gym…"
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="flex gap-2 justify-end pt-2">
                <button type="button" onclick="closeAddPlaceModal()" class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2 rounded text-sm font-medium transition">Cancel</button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-blue-700 transition">Create place</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddPlaceModal() {
    var modal = document.getElementById('addPlaceModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeAddPlaceModal() {
    var modal = document.getElementById('addPlaceModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
function validateAddPlace() {
    var lat = document.querySelector('#addPlaceModal input[name="lat"]').value;
    var lon = document.querySelector('#addPlaceModal input[name="lon"]').value;
    var dev = document.querySelector('#addPlaceModal select[name="device_id"]').value;
    if (!lat || !lon || !dev) {
        alert('Please fill in device, latitude, and longitude.');
        return false;
    }
    return true;
}

function openSettingsModal() {
    var modal = document.getElementById('settingsModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeSettingsModal() {
    var modal = document.getElementById('settingsModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
function triggerIncremental() {
    fetch('/api/places/detect', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'mode=incremental'
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                window.location.reload();
            } else {
                alert('Failed to start detection: ' + (data.message || data.error || 'Unknown error'));
            }
        })
        .catch(function(e) {
            alert('Error starting detection: ' + e.message);
        });
}

function triggerDetect(mode) {
    mode = mode || 'redetect';
    var hasPlaces = <?= $hasPlaces ? 'true' : 'false' ?>;
    if (mode === 'redetect' && hasPlaces && !confirm('⚠️ This will delete unnamed places and re-run detection from scratch.\n\nNamed places will be preserved and updated with new data.\n\nContinue?')) {
        return;
    }
    fetch('/api/places/detect', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'mode=' + mode
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                window.location.reload();
            } else {
                alert('Failed to start detection: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(function(e) {
            alert('Error starting detection: ' + e.message);
        });
}

function toggleCronLog() {
    var content = document.getElementById('cron-log-content');
    var icon = document.getElementById('cron-toggle-icon');
    if (content.classList.contains('hidden')) {
        content.classList.remove('hidden');
        icon.textContent = '▼';
        loadCronLog();
    } else {
        content.classList.add('hidden');
        icon.textContent = '▶';
    }
}

function loadCronLog() {
    fetch('/api/places/cron-log')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var el = document.getElementById('cron-log-content');
            if (!data.ok || !data.entries || data.entries.length === 0) {
                el.textContent = 'No cron log entries yet.';
                return;
            }
            el.innerHTML = data.entries.map(function(e) {
                return '<div>' + e + '</div>';
            }).join('');
        })
        .catch(function(e) {
            document.getElementById('cron-log-content').textContent = 'Error loading log: ' + e.message;
        });
}
</script>