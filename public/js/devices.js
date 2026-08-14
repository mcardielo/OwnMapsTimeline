/**
 * devices.js — Remote config management for the devices view.
 * Requires: app.js
 */

var _configDebounce = {};
var _colorDebounce = {};

function toggleConfig(deviceId) {
    var panel = document.getElementById('config-' + deviceId);
    panel.classList.toggle('hidden');
}

function onConfigChange(deviceId, webhookUrl, deviceName) {
    updateConfigLink(deviceId, webhookUrl, deviceName);

    if (_configDebounce[deviceId]) clearTimeout(_configDebounce[deviceId]);
    _configDebounce[deviceId] = setTimeout(function () {
        saveConfig(deviceId);
    }, 800);
}

function saveConfig(deviceId) {
    var panel = document.getElementById('config-' + deviceId);
    if (!panel) return;

    var config = buildConfigFromPanel(panel);

    // Persist +follow state so the reference config includes it for drift validation
    var followEl = panel.querySelector('[data-param="follow"]');
    if (followEl) {
        config['follow'] = followEl.checked;
    }

    var statusEl = document.getElementById('save-status-' + deviceId);
    if (statusEl) { statusEl.textContent = 'Saving…'; statusEl.classList.remove('opacity-0'); }

    fetch('/devices/config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            device_id: String(deviceId),
            config_json: JSON.stringify(config)
        })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.status === 'ok') {
            if (statusEl) { statusEl.textContent = '✓ Saved'; }
            setTimeout(function () {
                if (statusEl) statusEl.classList.add('opacity-0');
            }, 1500);
        } else {
            if (statusEl) { statusEl.textContent = '✗ ' + (data.error || 'Error'); statusEl.className = statusEl.className.replace('text-green-600', 'text-red-600'); }
        }
    })
    .catch(function (err) {
        if (statusEl) { statusEl.textContent = '✗ ' + err.message; statusEl.className = statusEl.className.replace('text-green-600', 'text-red-600'); }
    });
}

function updateConfigLink(deviceId, webhookUrl, deviceName) {
    var form = document.getElementById('config-' + deviceId);
    if (!form) return;

    var config = buildConfigFromPanel(form);
    // Add fields needed for the .otrc file but not in the panel
    config['_type'] = 'configuration';
    config['mode'] = 3;
    config['url'] = webhookUrl;
    config['tid'] = '';
    config['deviceId'] = deviceName;
    config['auth'] = false;
    config['usePassword'] = false;
    config['password'] = '';
    config['extendedData'] = true;
    config['cmd'] = true;
    config['sub'] = true;

    // Handle +follow waypoint (non-config param)
    var followEl = form.querySelector('[data-param="follow"]');
    var addFollow = followEl ? followEl.checked : true;
    if (addFollow) {
        config['waypoints'] = [{
            'rad': 50,
            'tst': Math.floor(Date.now() / 1000),
            '_type': 'waypoint',
            'rid': Math.random().toString(16).slice(2, 8),
            'lon': 0,
            'lat': 0,
            'desc': '+follow'
        }];
    }

    try {
        var json = JSON.stringify(config, null, 2);
        var b64 = btoa(unescape(encodeURIComponent(json)));
        var deepLink = 'owntracks:///config?inline=' + encodeURIComponent(b64);
        var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&t=' + Date.now() + '&data=' + encodeURIComponent(deepLink);

        var linkEl = document.getElementById('link-' + deviceId);
        if (linkEl) linkEl.href = deepLink;

        var qrEl = document.getElementById('qr-' + deviceId);
        if (qrEl) {
            qrEl.src = '';
            requestAnimationFrame(function () { qrEl.src = qrUrl; });
        }
    } catch (e) {
        console.error('updateConfigLink error:', e);
    }
}

function onDeviceColorChange(input) {
    var deviceId = input.dataset.device;
    var color = input.value;

    if (_colorDebounce[deviceId]) clearTimeout(_colorDebounce[deviceId]);
    _colorDebounce[deviceId] = setTimeout(function () {
        fetch('/devices/color', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ device_id: String(deviceId), color: color })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.status !== 'ok') {
                console.error('Color save failed:', data.error);
            }
        })
        .catch(function (err) {
            console.error('Color save error:', err);
        });
    }, 400);
}

/** Build a config object from the panel's data-param inputs */
function buildConfigFromPanel(panel) {
    var config = {
        'positions': 1000,
        'maxHistory': 0,
        'ranging': true,
        'locked': false,
        'monitoring': 2,
        'days': -1,
        'allowRemoteLocation': true,
        'adapt': 10,
        'locatorInterval': 60,
        'locatorDisplacement': 100,
        'downgrade': 20,
        'ignoreStaleLocations': 0,
        'ignoreInaccurateLocations': 50
    };

    panel.querySelectorAll('[data-param]').forEach(function (el) {
        var key = el.dataset.param;
        if (key === 'follow') return;  // handled separately
        var value;
        if (el.type === 'checkbox') {
            value = el.checked;
        } else if (el.tagName === 'SELECT') {
            value = parseInt(el.value, 10);
        } else {
            var n = parseInt(el.value, 10);
            value = isNaN(n) ? el.value : n;
        }
        if (key in config) {
            config[key] = value;
        }
    });

    return config;
}

// ── Share helpers ──────────────────────────────────────────────────────────

function confirmShare(deviceId) {
    var input = document.getElementById('share-input-' + deviceId);
    var username = input.value.trim();
    if (!username) return false;
    return confirm('Share this device with "' + username + '"?');
}

var _shareColorDebounce = {};
var _shareNameDebounce = {};

function onShareColorChange(input) {
    var deviceId = input.dataset.shareDevice;
    var color = input.value;

    if (_shareColorDebounce[deviceId]) clearTimeout(_shareColorDebounce[deviceId]);
    _shareColorDebounce[deviceId] = setTimeout(function () {
        fetch('/devices/share-color', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ device_id: String(deviceId), color: color })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.status !== 'ok') {
                console.error('Share color save failed:', data.error);
            }
        })
        .catch(function (err) {
            console.error('Share color save error:', err);
        });
    }, 400);
}

function onShareNameChange(input) {
    var deviceId = input.dataset.shareDevice;
    var name = input.value.trim();

    if (_shareNameDebounce[deviceId]) clearTimeout(_shareNameDebounce[deviceId]);
    _shareNameDebounce[deviceId] = setTimeout(function () {
        fetch('/devices/share-color', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ device_id: String(deviceId), custom_name: name })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.status !== 'ok') {
                console.error('Share name save failed:', data.error);
            }
        })
        .catch(function (err) {
            console.error('Share name save error:', err);
        });
    }, 800);
}
