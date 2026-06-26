<div class="max-w-4xl mx-auto px-4 py-8">
    <h2 class="text-2xl font-bold mb-2">📱 Your Devices</h2>
    <p class="text-gray-500 text-sm mb-6">Manage devices that report location via OwnTracks webhook</p>

    <?php if ($error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-4 text-sm rounded"><?= View::esc($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-3 mb-4 text-sm rounded"><?= View::esc($success) ?></div>
    <?php endif; ?>

    <!-- Add Device Form -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8">
        <h3 class="text-lg font-semibold mb-4">➕ Add Device</h3>
        <form method="POST" action="/devices/create" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">Device Name</label>
                <input type="text" name="name" required placeholder="e.g. My Phone"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">Tracker ID (TID)</label>
                <input type="text" name="tid" required placeholder="e.g. mario-phone" pattern="[A-Za-z0-9_-]+"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm font-mono">
                <p class="text-gray-400 text-xs mt-1">Letters, numbers, hyphens, underscores</p>
            </div>
            <div>
                <button type="submit"
                    class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 transition text-sm font-medium">
                    Add Device
                </button>
            </div>
        </form>
    </div>

    <!-- Device List -->
    <?php if (empty($devices)): ?>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center text-gray-400">
            <p class="text-5xl mb-4">📱</p>
            <p>No devices yet. Add your first one above!</p>
        </div>
    <?php else: ?>
        <div class="space-y-4">
        <?php foreach ($devices as $device): ?>
            <?php
                $webhookUrl = "{$webhookBase}?tid={$device['tid']}&token={$device['webhook_token']}";
                // Build default .otrc config JSON for ?inline= base64 approach
                $defaultConfig = [
                    '_type'        => 'configuration',
                    'mode'         => 3,
                    'url'          => $webhookUrl,
                    'tid'          => '',
                    'deviceId'     => $device['name'],
                    'username'     => $_SESSION['username'] ?? '',
                    'auth'         => false,
                    'usePassword'  => false,
                    'password'     => '',
                    'extendedData' => true,
                    'cmd'           => true,
                    'sub'           => true,
                    'positions'    => 1000,
                    'maxHistory'   => 0,
                    'ranging'      => true,
                    'locked'       => false,
                    'monitoring'   => 2,
                    'days'         => -1,
                    'allowRemoteLocation' => true,
                    'adapt'        => 10,
                    'locatorInterval'     => 60,
                    'locatorDisplacement' => 100,
                    'downgrade'    => 20,
                    'ignoreStaleLocations' => 0,
                    'ignoreInaccurateLocations' => 50,
                    'waypoints' => [
                        ['rad' => 50, 'tst' => time(), '_type' => 'waypoint', 'rid' => substr(md5(uniqid()), 0, 6), 'lon' => 0, 'lat' => 0, 'desc' => '+follow'],
                    ],
                ];
                // Merge saved config over defaults
                $savedConfig = null;
                $savedConfigJson = $device['config_json'] ?? null;
                if ($savedConfigJson) {
                    $savedConfig = json_decode($savedConfigJson, true);
                    if ($savedConfig && is_array($savedConfig)) {
                        $defaultConfig = array_merge($defaultConfig, $savedConfig);
                    }
                }
                $configJson = json_encode($defaultConfig, JSON_UNESCAPED_SLASHES);
                $configB64  = base64_encode($configJson);
                $deepLink   = 'owntracks:///config?inline=' . urlencode($configB64);
                $qrUrl      = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=' . urlencode($deepLink);
            ?>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex-1 min-w-[250px]">
                        <div class="flex items-center gap-2 mb-2">
                            <h3 class="text-lg font-semibold"><?= View::esc($device['name']) ?></h3>
                            <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-mono"><?= View::esc($device['tid']) ?></span>
                        </div>

                        <div class="mt-3 space-y-2">
                            <div>
                                <span class="text-xs font-medium text-gray-500 uppercase">Webhook URL</span>
                                <div class="flex items-center gap-2 mt-1">
                                    <code class="flex-1 bg-gray-50 border border-gray-200 rounded px-2 py-1 text-xs text-gray-700 break-all select-all"><?= View::esc($webhookUrl) ?></code>
                                    <button onclick="navigator.clipboard.writeText('<?= View::esc($webhookUrl) ?>');this.textContent='✓'" 
                                        class="text-xs text-blue-600 hover:text-blue-800 font-medium whitespace-nowrap shrink-0">Copy</button>
                                </div>
                            </div>
                            <div class="flex items-center gap-1 text-xs text-gray-400">
                                <span>Token: <code class="select-all"><?= View::esc($device['webhook_token']) ?></code></span>
                                <button onclick="navigator.clipboard.writeText('<?= View::esc($device['webhook_token']) ?>');this.textContent='✓'"
                                    class="text-blue-500 hover:underline">Copy</button>
                            </div>
                        </div>

                        <div class="mt-3 bg-gray-50 rounded p-3 text-xs text-gray-600">
                            <p class="font-medium mb-1">📋 OwnTracks App Setup:</p>
                            <ol class="list-decimal list-inside space-y-0.5">
                                <li>Open OwnTracks → Preferences → Connection/HTTP</li>
                                <li>Mode: <strong>HTTP</strong></li>
                                <li>Copy the Webhook URL above into the <strong>URL</strong> field</li>
                                <li>Enable <strong>Report location data</strong></li>
                                <li>Tap <strong>Publish</strong> to test!</li>
                            </ol>
                        </div>

                        <!-- Remote Config Toggle -->
                        <button type="button" onclick="toggleConfig(<?= $device['id'] ?>)"
                            class="mt-2 text-xs text-purple-600 hover:text-purple-800 font-medium">
                            ⚙️ Remote Config ▾
                        </button>
                        <div id="config-<?= $device['id'] ?>" class="hidden mt-3 bg-purple-50 border border-purple-200 rounded p-3 text-xs" data-device="<?= $device['id'] ?>">
                            <div class="flex items-center justify-between mb-2">
                                <p class="font-medium text-purple-700">Advanced configuration (embedded in QR & link):</p>
                                <span id="save-status-<?= $device['id'] ?>" class="text-green-600 opacity-0 transition-opacity text-[11px]">✓ Saved</span>
                            </div>
                            <div class="grid grid-cols-2 gap-x-3 gap-y-1.5">
                                <label class="flex items-center gap-1">
                                    <span class="text-gray-600">Positions:</span>
                                    <input type="number" value="<?= (int)$defaultConfig['positions'] ?>" min="0" max="10000"
                                        class="w-16 px-1 py-0.5 border border-gray-300 rounded text-xs"
                                        data-param="positions" data-device="<?= $device['id'] ?>" oninput="onConfigChange(<?= $device['id'] ?>, '<?= View::esc(addslashes($webhookUrl)) ?>', '<?= View::esc(addslashes($device['name'])) ?>')">
                                </label>
                                <label class="flex items-center gap-1">
                                    <span class="text-gray-600">Monitoring:</span>
                                    <select class="px-1 py-0.5 border border-gray-300 rounded text-xs"
                                        data-param="monitoring" data-device="<?= $device['id'] ?>" onchange="onConfigChange(<?= $device['id'] ?>, '<?= View::esc(addslashes($webhookUrl)) ?>', '<?= View::esc(addslashes($device['name'])) ?>')">
                                        <option value="1" <?= $defaultConfig['monitoring'] == 1 ? 'selected' : '' ?>>1: Significant</option>
                                        <option value="2" <?= $defaultConfig['monitoring'] == 2 ? 'selected' : '' ?>>2: Move</option>
                                    </select>
                                </label>
                                <label class="flex items-center gap-1">
                                    <span class="text-gray-600">Adapt (min):</span>
                                    <input type="number" value="<?= (int)$defaultConfig['adapt'] ?>" min="0" max="120"
                                        class="w-16 px-1 py-0.5 border border-gray-300 rounded text-xs"
                                        data-param="adapt" data-device="<?= $device['id'] ?>" oninput="onConfigChange(<?= $device['id'] ?>, '<?= View::esc(addslashes($webhookUrl)) ?>', '<?= View::esc(addslashes($device['name'])) ?>')">
                                </label>
                                <label class="flex items-center gap-1">
                                    <span class="text-gray-600">Interval (s):</span>
                                    <input type="number" value="<?= (int)$defaultConfig['locatorInterval'] ?>" min="10" max="3600"
                                        class="w-16 px-1 py-0.5 border border-gray-300 rounded text-xs"
                                        data-param="locatorInterval" data-device="<?= $device['id'] ?>" oninput="onConfigChange(<?= $device['id'] ?>, '<?= View::esc(addslashes($webhookUrl)) ?>', '<?= View::esc(addslashes($device['name'])) ?>')">
                                </label>
                                <label class="flex items-center gap-1">
                                    <span class="text-gray-600">Displace (m):</span>
                                    <input type="number" value="<?= (int)$defaultConfig['locatorDisplacement'] ?>" min="0" max="10000"
                                        class="w-16 px-1 py-0.5 border border-gray-300 rounded text-xs"
                                        data-param="locatorDisplacement" data-device="<?= $device['id'] ?>" oninput="onConfigChange(<?= $device['id'] ?>, '<?= View::esc(addslashes($webhookUrl)) ?>', '<?= View::esc(addslashes($device['name'])) ?>')">
                                </label>
                                <label class="flex items-center gap-1">
                                    <span class="text-gray-600">Downgrade %:</span>
                                    <input type="number" value="<?= (int)$defaultConfig['downgrade'] ?>" min="0" max="100"
                                        class="w-16 px-1 py-0.5 border border-gray-300 rounded text-xs"
                                        data-param="downgrade" data-device="<?= $device['id'] ?>" oninput="onConfigChange(<?= $device['id'] ?>, '<?= View::esc(addslashes($webhookUrl)) ?>', '<?= View::esc(addslashes($device['name'])) ?>')">
                                </label>
                                <label class="flex items-center gap-1">
                                    <span class="text-gray-600">Inaccurate (m):</span>
                                    <input type="number" value="<?= (int)$defaultConfig['ignoreInaccurateLocations'] ?>" min="0" max="10000"
                                        class="w-16 px-1 py-0.5 border border-gray-300 rounded text-xs"
                                        data-param="ignoreInaccurateLocations" data-device="<?= $device['id'] ?>" oninput="onConfigChange(<?= $device['id'] ?>, '<?= View::esc(addslashes($webhookUrl)) ?>', '<?= View::esc(addslashes($device['name'])) ?>')">
                                </label>
                                <label class="flex items-center gap-1">
                                    <span class="text-gray-600">Days:</span>
                                    <input type="number" value="<?= (int)$defaultConfig['days'] ?>" min="-1" max="365"
                                        class="w-16 px-1 py-0.5 border border-gray-300 rounded text-xs"
                                        data-param="days" data-device="<?= $device['id'] ?>" oninput="onConfigChange(<?= $device['id'] ?>, '<?= View::esc(addslashes($webhookUrl)) ?>', '<?= View::esc(addslashes($device['name'])) ?>')">
                                </label>
                                <label class="flex items-center gap-1 col-span-2">
                                    <input type="checkbox" <?= !empty($defaultConfig['ranging']) ? 'checked' : '' ?> data-param="ranging" data-device="<?= $device['id'] ?>" onchange="onConfigChange(<?= $device['id'] ?>, '<?= View::esc(addslashes($webhookUrl)) ?>', '<?= View::esc(addslashes($device['name'])) ?>')">
                                    <span class="text-gray-600">Ranging (beacons)</span>
                                </label>
                                <label class="flex items-center gap-1 col-span-2">
                                    <input type="checkbox" <?= !empty($defaultConfig['locked']) ? 'checked' : '' ?> data-param="locked" data-device="<?= $device['id'] ?>" onchange="onConfigChange(<?= $device['id'] ?>, '<?= View::esc(addslashes($webhookUrl)) ?>', '<?= View::esc(addslashes($device['name'])) ?>')">
                                    <span class="text-gray-600">Locked</span>
                                </label>
                                <label class="flex items-center gap-1 col-span-2">
                                    <input type="checkbox" <?= !empty($defaultConfig['allowRemoteLocation']) ? 'checked' : '' ?> data-param="allowRemoteLocation" data-device="<?= $device['id'] ?>" onchange="onConfigChange(<?= $device['id'] ?>, '<?= View::esc(addslashes($webhookUrl)) ?>', '<?= View::esc(addslashes($device['name'])) ?>')">
                                    <span class="text-gray-600">Allow Remote Location</span>
                                </label>
                                <label class="flex items-center gap-1 col-span-2">
                                    <input type="checkbox" <?= !empty($defaultConfig['waypoints']) ? 'checked' : '' ?> data-param="follow" data-device="<?= $device['id'] ?>" onchange="onConfigChange(<?= $device['id'] ?>, '<?= View::esc(addslashes($webhookUrl)) ?>', '<?= View::esc(addslashes($device['name'])) ?>')">
                                    <span class="text-gray-600">+Follow region (auto-track)</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col items-center gap-3 shrink-0">
                        <div class="bg-white border border-gray-200 rounded p-1">
                            <img src="<?= View::esc($qrUrl) ?>" alt="QR Code" class="w-56 h-56" id="qr-<?= $device['id'] ?>">
                        </div>
                        <p class="text-xs text-gray-400">Scan to configure</p>

                        <!-- Deep Link -->
                        <a href="<?= View::esc($deepLink) ?>" id="link-<?= $device['id'] ?>"
                           class="w-full text-xs text-center bg-green-600 text-white rounded px-2 py-1.5 hover:bg-green-700 transition font-medium">
                            📲 Open in OwnTracks
                        </a>

                        <div class="flex gap-2 w-full">
                            <form method="POST" action="/devices/delete" class="flex-1"
                                onsubmit="return confirm('Delete device <?= View::esc($device['name']) ?> and all its data?')">
                                <input type="hidden" name="device_id" value="<?= $device['id'] ?>">
                                <button type="submit" class="w-full text-xs text-red-600 border border-red-200 rounded px-2 py-1 hover:bg-red-50 transition">Delete</button>
                            </form>
                            <button onclick="document.getElementById('edit-<?= $device['id'] ?>').classList.toggle('hidden')"
                                class="flex-1 text-xs text-blue-600 border border-blue-200 rounded px-2 py-1 hover:bg-blue-50 transition">Rename</button>
                        </div>

                        <form id="edit-<?= $device['id'] ?>" method="POST" action="/devices/update" class="hidden w-full flex items-center gap-1">
                            <input type="hidden" name="device_id" value="<?= $device['id'] ?>">
                            <input type="text" name="name" value="<?= View::esc($device['name']) ?>"
                                class="flex-1 px-2 py-1 border border-gray-300 rounded text-xs focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <button type="submit" class="text-xs bg-blue-600 text-white px-2 py-1 rounded hover:bg-blue-700">Save</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="mt-8 flex gap-3">
        <a href="/dashboard" class="text-blue-600 hover:underline text-sm">← Back to Dashboard</a>
        <?php if ($isAdmin): ?>
            <a href="/users" class="text-blue-600 hover:underline text-sm">Manage Users →</a>
        <?php endif; ?>
    </div>
</div>

<script src="/js/app.js"></script>
<script src="/js/devices.js"></script>
