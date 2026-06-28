<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="/css/dashboard.css">

<div class="nav-bar">
    <a href="/devices" class="stat-pill hover:shadow-md transition text-blue-600">📱 Devices</a>
    <a href="/import" class="stat-pill hover:shadow-md transition text-green-600">📂 Import</a>
    <?php if ($isAdmin): ?>
    <a href="/users" class="stat-pill hover:shadow-md transition text-gray-600">👥 Users</a>
    <?php endif; ?>
    <form method="POST" action="/logout" class="inline">
        <button class="stat-pill hover:shadow-md transition text-red-500">🚪 Logout</button>
    </form>
</div>

<div class="sidebar" id="sidebar">
    <button class="sb-toggle" id="sbToggle" onclick="toggleSidebar()" title="Toggle sidebar">▶</button>
    <div class="flex items-center justify-between mb-2">
        <span class="font-semibold">🗺️ OwnTracks</span>
        <span class="text-gray-400 text-xs"><?= View::esc($username) ?></span>
    </div>
    <select id="deviceFilter" onchange="onDeviceChange()" class="w-full border border-gray-300 rounded px-2 py-1 text-sm mb-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
        <option value="all">All devices</option>
        <?php foreach ($devices as $d): ?>
            <option value="<?= $d['id'] ?>"><?= View::esc($d['name']) ?> (<?= View::esc($d['tid']) ?>)</option>
        <?php endforeach; ?>
    </select>
    <div class="mb-2">
        <div class="flex gap-2 text-xs mb-1 items-center">
            <button onclick="shiftDay(-1)" title="-1 day" class="bg-gray-100 hover:bg-gray-200 rounded px-1 py-0.5 transition">◀</button>
            <button onclick="setQuickRange(24)" class="flex-1 bg-gray-100 hover:bg-gray-200 rounded px-1 py-0.5 transition">24h</button>
            <button onclick="setQuickRange(168)" class="flex-1 bg-gray-100 hover:bg-gray-200 rounded px-1 py-0.5 transition">7d</button>
            <button onclick="setQuickRange(720)" class="flex-1 bg-gray-100 hover:bg-gray-200 rounded px-1 py-0.5 transition">30d</button>
            <button onclick="shiftDay(1)" title="+1 day" class="bg-gray-100 hover:bg-gray-200 rounded px-1 py-0.5 transition">▶</button>
        </div>
        <input type="datetime-local" id="timeFrom" onchange="disableAutoRefresh()" class="w-full border border-gray-300 rounded px-2 py-1 text-xs focus:outline-none mb-1">
        <input type="datetime-local" id="timeTo" onchange="disableAutoRefresh()" class="w-full border border-gray-300 rounded px-2 py-1 text-xs focus:outline-none mb-1">
        <button id="applyFilters" onclick="applyFilters()"
            class="w-full bg-blue-600 text-white py-1 rounded text-xs font-medium hover:bg-blue-700 transition">
            🔍 Apply Filters
        </button>
        <button onclick="resetFilters()"
            class="w-full bg-gray-200 text-gray-700 py-1 rounded text-xs font-medium hover:bg-gray-300 transition mt-1">
            🔄 Reset
        </button>
        <label class="flex items-center gap-1 text-xs text-gray-500 mt-1 cursor-pointer">
            <input type="checkbox" id="autoRefresh" onchange="toggleAutoRefresh()" class="w-3 h-3" checked>
            Auto-refresh (30s)
        </label>
        <label class="flex items-center gap-1 text-xs text-gray-500 mt-1 cursor-pointer">
            <input type="checkbox" id="showAccuracy" onchange="window._toggleAccuracy()" class="w-3 h-3">
            🎯 Show accuracy
        </label>
        <label class="flex items-center gap-1 text-xs text-gray-500 mt-1 cursor-pointer">
            <input type="checkbox" id="showSpeed" onchange="window._toggleSpeed()" class="w-3 h-3">
            🏎️ Speed overlay
        </label>
        <div id="speedLegend" class="hidden mt-1 text-xs text-gray-500">
            <div class="flex items-center gap-1">
                <span>🐢</span>
                <div class="h-2 flex-1 rounded" style="background:linear-gradient(to right, #00ff00, #ffff00, #ff0000)"></div>
                <span>🐇</span>
            </div>
            <div class="flex justify-between text-[10px] text-gray-400">
                <span>0</span><span>60 km/h</span><span>120+</span>
            </div>
        </div>
    </div>
    <div id="stats" class="text-xs text-gray-500 border-t border-gray-100 pt-2 space-y-0.5">
        <div><span id="pointCount">—</span><span id="loading" class="loading-spinner" style="display:none"></span></div>
        <div id="dateRange"></div>
        <div id="lastSeen">—</div>
        <div id="legend" class="flex flex-wrap gap-x-2 gap-y-0.5"></div>
    </div>
    <button id="playBtn" onclick="window._startPlayback()" style="display:none"
        class="w-full bg-green-600 text-white py-1 rounded text-xs font-medium hover:bg-green-700 transition mt-2">
        ▶ Play Route
    </button>
</div>

<div id="map"></div>

<div id="playbackBar" class="playback-bar">
    <button id="playPauseBtn" onclick="window._togglePlayPause()">▶</button>
    <button onclick="window._stopPlayback()" title="Stop">⏹</button>
    <span class="pb-divider"></span>
    <span id="playbackProgress">0/0 pts</span>
    <span class="pb-divider"></span>
    <span id="playbackTime">--:--</span>
    <span class="pb-divider"></span>
    <select id="playbackSpeed" onchange="window._setPlaybackSpeed(this.value)">
        <option value="1">1×</option>
        <option value="2">2×</option>
        <option value="5">5×</option>
        <option value="10">10×</option>
    </select>
</div>

<script src="/js/app.js"></script>
<script src="/js/dashboard.js"></script>

