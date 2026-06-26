<?php if ($step === 'upload'): ?>
<div class="max-w-4xl mx-auto px-4 py-8">
    <h2 class="text-2xl font-bold mb-2">📂 Import GPX</h2>
    <p class="text-gray-500 text-sm mb-6">Import location data from a GPX file</p>

    <?php if ($error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-4 text-sm rounded"><?= View::esc($error) ?></div>
    <?php endif; ?>
    <?php if (($success ?? false) || ($_SESSION['import_success'] ?? false)): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-3 mb-4 text-sm rounded"><?= View::esc($success ?? $_SESSION['import_success']) ?></div>
        <?php unset($_SESSION['import_success']); ?>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <form method="POST" action="/import/preview" enctype="multipart/form-data">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">GPX File</label>
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-blue-500 transition cursor-pointer" id="dropZone">
                    <input type="file" name="gpx_file" id="fileInput" accept=".gpx,.xml" required
                        class="hidden" onchange="updateFileName(this)">
                    <div id="dropContent">
                        <p class="text-4xl mb-2">📁</p>
                        <p class="text-gray-500 text-sm">Click to select or drag &amp; drop a GPX file</p>
                        <p class="text-gray-400 text-xs mt-1">.gpx or .xml</p>
                    </div>
                    <div id="selectedFile" class="hidden">
                        <p class="text-2xl mb-1">✅</p>
                        <p class="text-blue-600 font-medium text-sm" id="fileName"></p>
                        <p class="text-gray-400 text-xs mt-1">Click to change file</p>
                    </div>
                </div>
                <p class="text-gray-400 text-xs mt-2">
                    Supported: GPX 1.1 tracks and waypoints with timestamps.
                    Timestamps are assumed to be UTC.
                </p>
            </div>
            <button type="submit"
                class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 transition text-sm font-medium">
                Parse &amp; Preview
            </button>
        </form>
    </div>

    <div class="mt-8">
        <a href="/dashboard" class="text-blue-600 hover:underline text-sm">← Back to Dashboard</a>
    </div>
</div>

<script>
function updateFileName(input) {
    if (input.files.length > 0) {
        document.getElementById('dropContent').classList.add('hidden');
        document.getElementById('selectedFile').classList.remove('hidden');
        document.getElementById('fileName').textContent = input.files[0].name;
    }
}
document.getElementById('dropZone').addEventListener('click', function(e) {
    if (e.target.tagName !== 'BUTTON') {
        document.getElementById('fileInput').click();
    }
});
document.getElementById('dropZone').addEventListener('dragover', function(e) {
    e.preventDefault();
    this.classList.add('border-blue-500', 'bg-blue-50');
});
document.getElementById('dropZone').addEventListener('dragleave', function() {
    this.classList.remove('border-blue-500', 'bg-blue-50');
});
document.getElementById('dropZone').addEventListener('drop', function(e) {
    e.preventDefault();
    this.classList.remove('border-blue-500', 'bg-blue-50');
    document.getElementById('fileInput').files = e.dataTransfer.files;
    updateFileName(document.getElementById('fileInput'));
});
</script>

<?php elseif ($step === 'preview'): ?>
<div class="max-w-4xl mx-auto px-4 py-8">
    <h2 class="text-2xl font-bold mb-2">📂 Preview Import</h2>
    <p class="text-gray-500 text-sm mb-6">Review before importing into your device</p>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <p class="text-xs text-gray-500 uppercase mb-1">File</p>
            <p class="font-medium text-sm"><?= View::esc($fileName) ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <p class="text-xs text-gray-500 uppercase mb-1">Points</p>
            <p class="font-medium text-sm"><?= number_format($total) ?> track points</p>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <p class="text-xs text-gray-500 uppercase mb-1">Date Range</p>
            <p class="font-medium text-sm"><?= View::esc($startTime) ?> → <?= View::esc($endTime) ?></p>
        </div>
    </div>

    <!-- Import Form -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold mb-4">⚙️ Import Settings</h3>
        <form method="POST" action="/import/execute">
            <!-- Device selector -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Link to Device</label>
                <select name="device_id" required
                    class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Select a device...</option>
                    <?php foreach ($devices as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= View::esc($d['name']) ?> (<?= View::esc($d['tid']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <p class="text-gray-400 text-xs mt-1">
                    <?php if (empty($devices)): ?>
                        ⚠️ No devices found. <a href="/devices" class="text-blue-600 underline">Create a device first</a>.
                    <?php else: ?>
                        The imported points will be linked to this device
                    <?php endif; ?>
                </p>
            </div>

            <!-- Timezone offset -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Timezone Offset
                    <span class="text-gray-400 font-normal">(GPX times are UTC)</span>
                </label>
                <div class="flex items-center gap-2">
                    <input type="number" name="tz_offset" value="<?= $detectedOffset ?>" step="0.5" min="-12" max="14"
                        class="w-24 px-3 py-2 border border-gray-300 rounded text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <span class="text-sm text-gray-600">hours</span>
                </div>
                <p class="text-gray-400 text-xs mt-1">
                    Detected: UTC<?= $detectedOffset >= 0 ? '+' : '' ?><?= $detectedOffset ?>h
                    (your TZ: <?= View::esc(getenv('TZ') ?: 'UTC') ?>).
                    Adjust if the GPX was recorded in a different timezone.
                </p>
            </div>

            <div class="flex gap-3">
                <button type="submit"
                    class="bg-green-600 text-white px-6 py-2 rounded-md hover:bg-green-700 transition text-sm font-medium">
                    ✅ Import <?= number_format($total) ?> Points
                </button>
                <a href="/import"
                    class="border border-gray-300 text-gray-600 px-6 py-2 rounded-md hover:bg-gray-50 transition text-sm font-medium inline-block">
                    Cancel
                </a>
            </div>
        </form>
    </div>

    <!-- Points preview -->
    <div class="mt-6 bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="bg-gray-50 border-b border-gray-200 px-4 py-2">
            <span class="text-sm font-medium text-gray-600">📍 Points Preview</span>
            <span class="text-xs text-gray-400 ml-2">(showing first 20 of <?= number_format($total) ?>)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-3 py-1.5 font-medium text-gray-500">#</th>
                        <th class="text-left px-3 py-1.5 font-medium text-gray-500">Time (UTC)</th>
                        <th class="text-left px-3 py-1.5 font-medium text-gray-500">Lat</th>
                        <th class="text-left px-3 py-1.5 font-medium text-gray-500">Lon</th>
                        <th class="text-left px-3 py-1.5 font-medium text-gray-500">Ele (m)</th>
                        <th class="text-left px-3 py-1.5 font-medium text-gray-500">Acc (m)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($points, 0, 20) as $i => $pt): ?>
                    <tr class="border-b border-gray-50">
                        <td class="px-3 py-1 text-gray-400"><?= $i + 1 ?></td>
                        <td class="px-3 py-1 font-mono text-gray-600"><?= View::esc($pt['time']) ?></td>
                        <td class="px-3 py-1 font-mono"><?= number_format($pt['lat'], 6) ?></td>
                        <td class="px-3 py-1 font-mono"><?= number_format($pt['lon'], 6) ?></td>
                        <td class="px-3 py-1"><?= $pt['ele'] ?: '—' ?></td>
                        <td class="px-3 py-1"><?= $pt['acc'] ? round($pt['acc'], 1) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
