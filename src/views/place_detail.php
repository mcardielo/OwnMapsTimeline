<?php
/** Helper functions for place detail view */
function _fmtDuration2(int $seconds): string {
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return round($seconds / 60) . 'm';
    if ($seconds < 86400) return round($seconds / 3600, 1) . 'h';
    return round($seconds / 86400, 1) . 'd';
}
function _fmtDate2(int $tst): string {
    return date('M j, Y', $tst);
}
function _fmtDateTime2(int $tst): string {
    return date('M j, Y, H:i', $tst);
}
?>

<div class="max-w-[1200px] mx-auto px-4 py-8">
    <!-- Flash message -->
    <?php $flashMsg = $_GET['msg'] ?? ''; if ($flashMsg): ?>
    <div class="mb-4 rounded-lg p-3 text-sm <?= str_starts_with($flashMsg, '✅') ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-yellow-50 border border-yellow-200 text-yellow-700' ?>">
        <?= View::esc($flashMsg) ?>
    </div>
    <?php endif; ?>

    <!-- Empty visits notice for manual places -->
    <?php if ((int) $place['visit_count'] === 0 && (int) $place['first_seen'] === 0): ?>
    <div class="mb-4 bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-700">
        📍 This place was created manually and has no visits recorded yet.
        Try adjusting the radius below to search for nearby visits.
    </div>
    <?php endif; ?>

    <div class="flex items-center justify-between mb-2">
        <h2 class="text-2xl font-bold">
            <?= $place['name'] ? View::esc($place['name']) : 'Unnamed Place' ?>
        </h2>
        <a href="/places" class="text-blue-600 hover:underline text-sm">← All places</a>
    </div>
    <p class="text-gray-500 text-sm mb-6">
        <?= View::esc($place['device_name'] ?? '') ?> ·
        <?= number_format((float) $place['lat'], 5) ?>, <?= number_format((float) $place['lon'], 5) ?>
        · ~<?= number_format((float) $place['radius'], 0) ?>m radius
    </p>

    <!-- Stats -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 text-center">
            <div class="text-2xl font-bold text-blue-600"><?= (int) $place['visit_count'] ?></div>
            <div class="text-xs text-gray-500 mt-1">Visits</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 text-center">
            <div class="text-2xl font-bold text-green-600"><?= _fmtDuration2((int) $place['total_time']) ?></div>
            <div class="text-xs text-gray-500 mt-1">Total time</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 text-center">
            <div class="text-lg font-bold text-gray-600"><?= _fmtDate2((int) $place['first_seen']) ?></div>
            <div class="text-xs text-gray-500 mt-1">First visit</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 text-center">
            <div class="text-lg font-bold text-gray-600"><?= _fmtDate2((int) $place['last_seen']) ?></div>
            <div class="text-xs text-gray-500 mt-1">Last visit</div>
        </div>
    </div>

    <!-- Rename + Radius form -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">Place settings</h2>

        <!-- Name -->
        <form method="POST" action="/places/rename" class="flex gap-2 mb-3">
            <input type="hidden" name="id" value="<?= (int) $place['id'] ?>">
            <input type="hidden" name="redirect" value="/places/<?= (int) $place['id'] ?>">
            <input type="text" name="name" value="<?= View::esc($place['name'] ?? '') ?>"
                   placeholder="e.g. Home, Office, Gym..."
                   class="flex-1 border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            <button class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-blue-700 transition">
                Save name
            </button>
        </form>

        <!-- Radius -->
        <form method="POST" action="/places/recalculate" class="flex gap-2 items-end" onsubmit="return confirmRecalculate()">
            <input type="hidden" name="id" value="<?= (int) $place['id'] ?>">
            <div class="flex-1">
                <label class="block text-xs text-gray-500 mb-1">Radius (meters)</label>
                <input type="number" name="radius" value="<?= number_format((float) $place['radius'], 0) ?>" min="10" max="500" step="5"
                       class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <button class="bg-green-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-green-700 transition whitespace-nowrap">
                🔄 Recalculate
            </button>
        </form>
        <p class="text-xs text-gray-400 mt-2">Changing the radius recalculates all visits with the new boundary. Visit count and total time will update accordingly.</p>
    </div>

    <!-- Visit history -->
    <h2 class="text-lg font-semibold mb-3 text-gray-700">Visit History (<?= count($visits) ?>)</h2>
    <?php if (empty($visits)): ?>
        <p class="text-gray-400 text-sm">No visit data available.</p>
    <?php else: ?>
    <div class="space-y-2">
        <?php foreach (array_reverse($visits) as $v): ?>
        <?php
            $dateStr = date('Y-m-d', (int) $v['start_tst']);
            $fromParam = $dateStr . 'T00:00';
            $toParam = $dateStr . 'T23:59';
            $mapUrl = "/dashboard?from=" . urlencode($fromParam) . "&to=" . urlencode($toParam) . "&zoom=place&id=" . (int) $place['id'];
        ?>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 flex items-center justify-between hover:shadow-md transition">
            <div class="flex-1">
                <div class="font-medium text-sm text-gray-700">
                    <?= _fmtDateTime2((int) $v['start_tst']) ?> → <?= date('H:i', (int) $v['end_tst']) ?>
                </div>
                <div class="text-xs text-gray-500 mt-0.5">
                    <?= _fmtDuration2((int) $v['duration']) ?> ·
                    <?= (int) $v['point_count'] ?> points ·
                    <?= View::esc($v['device_name'] ?? '') ?>
                </div>
            </div>
            <a href="<?= View::esc($mapUrl) ?>"
               class="ml-3 text-blue-500 hover:text-blue-700 text-sm whitespace-nowrap">
                🗺️ View on map
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Delete button -->
    <div class="mt-8">
        <form method="POST" action="/places/delete" onsubmit="return confirm('Delete this place? This cannot be undone.')">
            <input type="hidden" name="id" value="<?= (int) $place['id'] ?>">
            <button class="text-red-500 hover:text-red-700 text-sm">🗑 Delete this place</button>
        </form>
    </div>
</div>

<script>
function confirmRecalculate() {
    return confirm('Recalculate visits with the new radius? This may take a few seconds.');
}
</script>