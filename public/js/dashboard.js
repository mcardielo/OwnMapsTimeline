/**
 * dashboard.js — Map, filters, playback for the main dashboard view.
 * Requires: Leaflet (loaded via CDN in <head>), app.js, tz-lookup.js
 */

// ── Sidebar & filter controls ──────────────────────────────────────────────

function disableAutoRefresh() {
    document.getElementById('autoRefresh').checked = false;
    if (window._stopRefresh) window._stopRefresh();
}

function onTzChange() {
    var el = document.getElementById('tzSelect');
    setTimezone(el.value);
    applyFilters();
}

/** Populate the timezone dropdown with zones detected from points.
 *  If the saved timezone differs from the current one, reformats the date
 *  inputs and triggers a refetch so the API query uses the correct zone. */
function populateTimezones(points) {
    var tzEl = document.getElementById('tzSelect');
    if (!tzEl || typeof tzlookup === 'undefined') return;

    // Load saved timezone preference (if any)
    var savedTz = null;
    try { savedTz = localStorage.getItem('ot_selected_tz'); } catch (e) {}

    var detected = {};
    // Sample up to 500 points to detect zones (for performance)
    var step = points.length > 500 ? Math.ceil(points.length / 500) : 1;
    for (var i = 0; i < points.length; i += step) {
        var p = points[i];
        try {
            var tz = tzlookup(p.lat, p.lon);
            if (tz) detected[tz] = true;
        } catch (e) {}
    }
    var zones = Object.keys(detected).sort();

    // Always include browser timezone
    var browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    if (!detected[browserTz]) zones.unshift(browserTz);

    // If saved tz exists and is among detected zones, use it; otherwise use browser tz
    var activeTz = (savedTz && zones.indexOf(savedTz) !== -1) ? savedTz : browserTz;

    var prevTz = selectedTZ;
    setTimezone(activeTz);

    var html = '';
    for (var z = 0; z < zones.length; z++) {
        var selected = zones[z] === activeTz ? ' selected' : '';
        html += '<option value="' + zones[z] + '"' + selected + '>' + zones[z] + '</option>';
    }
    tzEl.innerHTML = html;
    tzEl.value = activeTz;

    if (activeTz !== prevTz) {
        applyFilters();
    }
}

function onDeviceChange() {
    var val = document.getElementById('deviceFilter').value;
    try { localStorage.setItem('ot_selected_device', val); } catch (e) {}
    document.getElementById('tagFilter').value = '';
    if (window._mapLoadData) window._mapLoadData(true);
    // Reload places filtered by selected device
    if (document.getElementById('showPlaces') && document.getElementById('showPlaces').checked) {
        loadPlaces();
    }
}

async function onTagChange() {
    var tag = document.getElementById('tagFilter').value;
    // When selecting a tag, auto-set date pickers to the tag's range
    if (tag) {
        var deviceId = document.getElementById('deviceFilter').value;
        var url = '/api/locations?tag=' + encodeURIComponent(tag) + '&limit=1';
        if (deviceId !== 'all') url += '&device_id=' + deviceId;
        try {
            var resp = await fetch(url);
            var data = await resp.json();
            if (data.tag_range && data.tag_range.min_tst) {
                document.getElementById('timeFrom').value = tstToLocalDatetime(data.tag_range.min_tst);
                document.getElementById('timeTo').value = tstToLocalDatetime(data.tag_range.max_tst);
            }
        } catch (e) {}
    }
    applyFilters();
}

function resetFilters() {
    document.getElementById('tagFilter').value = '';
    var epoch = Date.now();
    var browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    var todayStr = fmtInTz(epoch, browserTz).split('T')[0];
    document.getElementById('timeFrom').value = todayStr + 'T00:00';
    document.getElementById('timeTo').value   = todayStr + 'T23:59';
    document.getElementById('autoRefresh').checked = true;
    if (window._mapLoadData) window._mapLoadData(true);
    if (window._resetRefresh) window._resetRefresh();
}

function setQuickRange(hours) {
    var n = Date.now();
    document.getElementById('timeFrom').value = fmtInTz(n - hours * 3600 * 1000);
    document.getElementById('timeTo').value = fmtInTz(n);
    applyFilters();
}

function applyFilters() {
    if (window._mapLoadData) window._mapLoadData(true);
    disableAutoRefresh();
}

function toggleAutoRefresh() {
    if (document.getElementById('autoRefresh').checked) {
        if (window._resetRefresh) window._resetRefresh();
    } else {
        if (window._stopRefresh) window._stopRefresh();
    }
}

function shiftDay(delta) {
    var fromEl = document.getElementById('timeFrom');
    var toEl = document.getElementById('timeTo');
    if (!fromEl.value || !toEl.value) return;

    // Parse the date parts from the datetime-local string directly
    // and shift by delta days, preserving the time portion
    fromEl.value = shiftDateStr(fromEl.value, delta);
    toEl.value = shiftDateStr(toEl.value, delta);
    applyFilters();
}

/** Shift the date portion of a YYYY-MM-DDTHH:mm string by delta days, keeping time fixed */
function shiftDateStr(str, delta) {
    var parts = str.split('T');
    var dp = parts[0].split('-');
    var d = new Date(parseInt(dp[0], 10), parseInt(dp[1], 10) - 1, parseInt(dp[2], 10));
    d.setDate(d.getDate() + delta);
    var pad = function(n) { return String(n).padStart(2, '0'); };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + parts[1];
}

function toggleSidebar() {
    var sb = document.getElementById('sidebar');
    var btn = document.getElementById('sbToggle');
    var collapsed = sb.classList.toggle('collapsed');
    btn.textContent = collapsed ? '◀' : '▶';
    try { localStorage.setItem('ot_sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) {}
    setTimeout(function() { if (typeof map !== 'undefined') map.invalidateSize(); }, 250);
}

// ── Map & playback engine ──────────────────────────────────────────────────

var map;
(function () {
    map = L.map('map', { zoomControl: true }).setView([20, 0], 2);

    // Base layers
    var osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://osm.org/copyright">OSM</a>',
        maxZoom: 19
    }).addTo(map);

    var satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: '&copy; Esri, Maxar, Earthstar Geographics',
        maxZoom: 19
    });

    window._baseLayers = { street: osmLayer, satellite: satelliteLayer };
    window._toggleSatellite = function() {
        var checked = document.getElementById('satelliteView').checked;
        if (checked) {
            map.removeLayer(window._baseLayers.street);
            map.addLayer(window._baseLayers.satellite);
        } else {
            map.removeLayer(window._baseLayers.satellite);
            map.addLayer(window._baseLayers.street);
        }
    };

    var markers = L.featureGroup().addTo(map);
    var accuracyCircles = L.featureGroup().addTo(map);
    var speedSegments = L.featureGroup().addTo(map);
    var poiMarkers = L.featureGroup().addTo(map);
    var placeMarkers = L.featureGroup().addTo(map);
    var polylines = [];
    var currentBounds = null;
    var isLoading = false;

    // ── Playback state ─────────────────────────────────────────────────────
    var pbState = 'idle';
    var pbPoints = [];
    var pbSpeed = 100;
    var pbStartWall = null;
    var pbStartTst = null;
    var pbEndTst = null;
    var pbCurrentTst = null;
    var pbLastIdx = 0;
    var pbRafId = null;
    var pbMarker = null;
    var pbTrail = null;
    var pbRemaining = null;
    var PB_COLOR = '#ef4444';

    var DEVICE_COLORS = [
        '#3b82f6', '#ef4444', '#22c55e', '#f59e0b', '#8b5cf6',
        '#ec4899', '#06b6d4', '#84cc16', '#f97316', '#6366f1'
    ];

    /** Custom icon for POI markers */
    var poiIcon = L.divIcon({
        className: 'poi-marker',
        html: '<div class="poi-marker-inner">📍</div>',
        iconSize: [36, 36],
        iconAnchor: [18, 18],
        popupAnchor: [0, -18]
    });

    /** Map velocity (km/h) to a color: blue(0) → green(20) → yellow(60) → red(120) */
    function speedToColor(vel) {
        var v = Math.max(0, Math.min(120, Number(vel) || 0));
        var r, g, b;
        if (v <= 20) {
            // sky blue(70,170,255) → green(0,255,0)
            var s = v / 20;
            r = Math.round(70 * (1 - s));
            g = Math.round(170 + 85 * s);
            b = Math.round(255 * (1 - s));
        } else if (v <= 60) {
            // green(0,255,0) → yellow(255,255,0)
            var s = (v - 20) / 40;
            r = Math.round(255 * s);
            g = 255;
            b = 0;
        } else {
            // yellow(255,255,0) → red(255,0,0)
            var s = (v - 60) / 60;
            r = 255;
            g = Math.round(255 * (1 - s));
            b = 0;
        }
        return 'rgb(' + r + ',' + g + ',' + b + ')';
    }

    function showLoading() {
        isLoading = true;
        document.getElementById('loading').style.display = 'inline-block';
        var btn = document.getElementById('applyFilters');
        btn.disabled = true;
        btn.classList.add('opacity-50');
    }

    function hideLoading() {
        isLoading = false;
        document.getElementById('loading').style.display = 'none';
        var btn = document.getElementById('applyFilters');
        btn.disabled = false;
        btn.classList.remove('opacity-50');
    }

    function popupContent(p) {
        // Use the timezone of the point's own coordinates for display
        var pointTz = (typeof tzlookup !== 'undefined') ? tzlookup(p.lat, p.lon) : selectedTZ;
        var dt = fmtTzDisplay(p.tst, { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: pointTz });
        var html = '<b>' + dt + '</b>';
        var isPoi = !!p.poi;

        // POI: show name + image at the top
        if (isPoi) {
            html += '<div style="margin-top:4px;font-weight:bold;color:#d97706">📍 ' + escapeHtml(p.poi) + '</div>';
            if (p.poi_imagename && p.id) {
                html += '<div style="margin:4px 0"><img src="/api/poi-image?id=' + p.id + '" style="max-width:100%;max-height:200px;border-radius:4px" alt="POI image" /></div>';
            }
        }

        html += '<br>Latitude: ' + Number(p.lat).toFixed(6);
        html += '<br>Longitude: ' + Number(p.lon).toFixed(6);
        if (p.acc != null) html += '<br>Accuracy: ' + Number(p.acc).toFixed(1) + ' m';

        // POI popup: minimal (lat/lon/acc/device only)
        if (isPoi) {
            if (p.device_name) html += '<br>Device: ' + escapeHtml(p.device_name);
            return html;
        }

        if (p.alt != null) html += '<br>Altitude: ' + Math.round(p.alt) + ' m';
        if (p.vac != null) html += '<br>Vertical accuracy: ' + Math.round(p.vac) + ' m';
        if (p.vel != null) html += '<br>Velocity: ' + Number(p.vel).toFixed(0) + ' km/h';
        if (p.batt != null) html += '<br>Battery: ' + p.batt + '%';
        if (p.bs != null) {
            var bsLabels = { 0: 'unknown', 1: 'unplugged', 2: 'charging', 3: 'full' };
            html += '<br>Battery status: ' + (bsLabels[p.bs] || p.bs);
        }
        if (p.conn) {
            var connLabels = { w: 'WiFi', o: 'offline', m: 'mobile' };
            html += '<br>Connectivity: ' + (connLabels[p.conn] || p.conn);
        }
        if (p.t) {
            var tLabels = { p: 'ping', c: 'circular region', b: 'beacon region', r: 'report response', u: 'manual publish', t: 'timer', v: 'frequent locations' };
            html += '<br>Trigger: ' + (tLabels[p.t] || p.t);
        }
        if (p.tag) html += '<br>Tag: ' + escapeHtml(p.tag);
        if (p.device_name) html += '<br>Device: ' + escapeHtml(p.device_name);
        return html;
    }

    async function loadData(fitBounds, showSpinner) {
        if (isLoading) return;
        if (pbState === 'playing' || pbState === 'paused') return;
        if (showSpinner !== false) showLoading();

        var deviceId = document.getElementById('deviceFilter').value;
        var tag = document.getElementById('tagFilter').value;
        var fromEl = document.getElementById('timeFrom');
        var toEl = document.getElementById('timeTo');
        var url = '/api/locations?';
        if (deviceId !== 'all') url += 'device_id=' + deviceId + '&';
        if (tag) url += 'tag=' + encodeURIComponent(tag) + '&';
        if (fromEl.value) url += 'from=' + Math.floor(parseDatetimeLocal(fromEl.value).getTime() / 1000) + '&';
        if (toEl.value) url += 'to=' + Math.floor(parseDatetimeLocal(toEl.value).getTime() / 1000) + '&';

        try {
            var resp = await fetch(url);
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            var data = await resp.json();

            // Populate tag dropdown
            var tagSel = document.getElementById('tagFilter');
            var tags = data.tags || [];
            var currentTag = tagSel.value;
            var existing = [];
            for (var ti = 1; ti < tagSel.options.length; ti++) existing.push(tagSel.options[ti].value);
            var needsUpdate = tags.length !== existing.length || !tags.every(function(t, i) { return t === existing[i]; });
            if (needsUpdate) {
                tagSel.innerHTML = '<option value="">🏷️ All tags</option>';
                tags.forEach(function(t) {
                    var opt = document.createElement('option');
                    opt.value = t;
                    opt.textContent = '🏷️ ' + t;
                    tagSel.appendChild(opt);
                });
            }
            tagSel.value = currentTag || '';

            markers.clearLayers();
            accuracyCircles.clearLayers();
            speedSegments.clearLayers();
            poiMarkers.clearLayers();
            polylines.forEach(function (pl) { map.removeLayer(pl); });
            polylines = [];

            var pts = data.points || [];
            if (pts.length === 0) {
                document.getElementById('pointCount').textContent = '0 puntos';
                document.getElementById('dateRange').textContent = '';
                document.getElementById('lastSeen').textContent = 'no data';
                document.getElementById('legend').innerHTML = '';
                if (showSpinner !== false) hideLoading();
                return;
            }

            // Detect timezones from point coordinates
            populateTimezones(pts);

            pts.sort(function (a, b) { return a.tst - b.tst; });

            // Cache for playback (single device only)
            var selDevice = document.getElementById('deviceFilter').value;
            if (selDevice !== 'all' && pts.length > 1) {
                pbPoints = pts.slice();
            } else {
                pbPoints = [];
            }

            // Group by device
            var byDevice = {};
            pts.forEach(function (p) {
                var did = p.device_id || p.tid || 'unknown';
                if (!byDevice[did]) byDevice[did] = { name: p.device_name || p.tid, tid: p.tid, points: [] };
                byDevice[did].points.push(p);
            });

            var devIds = Object.keys(byDevice);
            var allLatlngs = [];
            var legendHtml = '';

            devIds.forEach(function (did, i) {
                var dev = byDevice[did];
                var firstPoint = dev.points[0];
                var color = (firstPoint && firstPoint.color) || DEVICE_COLORS[i % DEVICE_COLORS.length];
                var dps = dev.points;

                var lastP = dps[dps.length - 1];
                var battInfo = (lastP && lastP.batt != null) ? ' \uD83D\uDD0B' + lastP.batt + '%' : '';
                legendHtml += '<span style="display:inline-flex;align-items:center;gap:2px;margin-right:8px"><span style="width:10px;height:10px;border-radius:50%;background:' + color + ';display:inline-block"></span>' + escapeHtml(dev.name) + battInfo + '</span>';

                var latlngs = dps.map(function (p) { return [p.lat, p.lon]; });
                allLatlngs = allLatlngs.concat(latlngs);
                var pl = L.polyline(latlngs, { color: color, weight: 3, opacity: 0.7, smoothFactor: 1 }).addTo(map);
                polylines.push(pl);

                if (dps.length > 0) {
                    L.circleMarker([dps[0].lat, dps[0].lon], {
                        radius: 7, color: color, fillColor: color, fillOpacity: 1, weight: 2
                    }).addTo(markers).bindPopup(popupContent(dps[0]));
                }

                if (dps.length > 1) {
                    var last = dps[dps.length - 1];
                    L.circleMarker([last.lat, last.lon], {
                        radius: 9, color: color, fillColor: '#fff', fillOpacity: 1, weight: 2.5
                    }).addTo(markers).bindPopup(popupContent(last));
                }

                if (dps.length > 2) {
                    dps.slice(1, -1).forEach(function (p) {
                        L.circleMarker([p.lat, p.lon], {
                            radius: 4, color: color, fillColor: color, fillOpacity: 0.5, weight: 1
                        }).addTo(markers).bindPopup(popupContent(p));
                    });
                }

                // Accuracy circles (non-interactive so popups work)
                dps.forEach(function (p) {
                    if (p.acc && p.acc > 0) {
                        L.circle([p.lat, p.lon], {
                            radius: Number(p.acc),
                            color: color,
                            fillColor: color,
                            fillOpacity: 0.12,
                            weight: 1,
                            opacity: 0.25,
                            interactive: false
                        }).addTo(accuracyCircles);
                    }
                });

                // Speed segments
                for (var si = 1; si < dps.length; si++) {
                    var prev = dps[si - 1];
                    var curr = dps[si];
                    if (curr.vel != null) {
                        L.polyline([[prev.lat, prev.lon], [curr.lat, curr.lon]], {
                            color: speedToColor(curr.vel),
                            weight: 6,
                            opacity: 0.7,
                            interactive: false
                        }).addTo(speedSegments);
                    }
                }
            });

            // POI markers
            if (data.pois && data.pois.length > 0) {
                data.pois.forEach(function (p) {
                    L.marker([p.lat, p.lon], {
                        icon: poiIcon
                    }).addTo(poiMarkers).bindPopup(popupContent(p));
                });
            }

            document.getElementById('legend').innerHTML = legendHtml;

            if (fitBounds || !currentBounds) {
                if (allLatlngs.length > 0) {
                    var bounds = L.latLngBounds(allLatlngs);
                    if (bounds.isValid()) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
                }
                currentBounds = true;
            }

            if (data.range && data.range.min_tst && data.range.max_tst) {
                var fEl = document.getElementById('timeFrom');
                var tEl = document.getElementById('timeTo');
                fEl.min = tstToLocalDatetime(data.range.min_tst);
                fEl.max = tstToLocalDatetime(data.range.max_tst);
                tEl.min = tstToLocalDatetime(data.range.min_tst);
                tEl.max = tstToLocalDatetime(data.range.max_tst);
            } else {
                var fEl2 = document.getElementById('timeFrom');
                var tEl2 = document.getElementById('timeTo');
                fEl2.removeAttribute('min');
                fEl2.removeAttribute('max');
                tEl2.removeAttribute('min');
                tEl2.removeAttribute('max');
            }

            // Point count
            var origCount = data.original_count || pts.length;
            if (origCount > pts.length) {
                document.getElementById('pointCount').textContent = 'mostrando ' + pts.length.toLocaleString() + ' de ' + origCount.toLocaleString() + ' puntos';
            } else {
                document.getElementById('pointCount').textContent = pts.length.toLocaleString() + ' puntos';
            }

            // Date range
            var fmt = function (ts) {
                return fmtTzDisplay(ts, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false });
            };
            document.getElementById('dateRange').textContent = fmt(pts[0].tst) + ' → ' + fmt(pts[pts.length - 1].tst);

            var lastTst = pts[pts.length - 1].tst;
            var diffMin = Math.round((Date.now() / 1000 - lastTst) / 60);
            var agoStr = diffMin < 1 ? 'just now' : diffMin < 60 ? diffMin + 'm ago' : Math.round(diffMin / 60) + 'h ' + (diffMin % 60) + 'm ago';
            document.getElementById('lastSeen').textContent = agoStr;

        } catch (err) {
            console.error('Map load error:', err);
            document.getElementById('pointCount').textContent = 'error';
            document.getElementById('lastSeen').textContent = err.message || 'fetch failed';
        }
        if (showSpinner !== false) hideLoading();

        var playBtn = document.getElementById('playBtn');
        var selDev = document.getElementById('deviceFilter').value;
        playBtn.style.display = (selDev !== 'all' && pbPoints.length > 1 && pbState === 'idle') ? '' : 'none';
    }

    window._mapLoadData = function (fit) { return loadData(fit, true); };
    window._resetRefresh = function () { return scheduleRefresh(); };
    window._stopRefresh = function () { clearTimeout(refreshTimer); refreshTimer = null; };
    window._toggleAccuracy = function () {
        var on = document.getElementById('showAccuracy').checked;
        try { localStorage.setItem('ot_show_accuracy', on ? '1' : '0'); } catch (e) {}
        if (on) {
            map.addLayer(accuracyCircles);
        } else {
            map.removeLayer(accuracyCircles);
        }
    };
    window._toggleSpeed = function () {
        var on = document.getElementById('showSpeed').checked;
        try { localStorage.setItem('ot_show_speed', on ? '1' : '0'); } catch (e) {}
        var legend = document.getElementById('speedLegend');
        if (on) {
            map.addLayer(speedSegments);
            if (legend) legend.classList.remove('hidden');
        } else {
            map.removeLayer(speedSegments);
            if (legend) legend.classList.add('hidden');
        }
    };
    window._togglePois = function () {
        var on = document.getElementById('showPois').checked;
        try { localStorage.setItem('ot_show_pois', on ? '1' : '0'); } catch (e) {}
        if (on) {
            map.addLayer(poiMarkers);
        } else {
            map.removeLayer(poiMarkers);
        }
    };
    window._togglePlaces = function () {
        var on = document.getElementById('showPlaces').checked;
        try { localStorage.setItem('ot_show_places', on ? '1' : '0'); } catch (e) {}
        if (on) {
            map.addLayer(placeMarkers);
            loadPlaces();
        } else {
            map.removeLayer(placeMarkers);
        }
    };

    /** Fetch and render place markers on the map */
    async function loadPlaces() {
        try {
            var devSel = document.getElementById('deviceFilter').value;
            var placesUrl = '/api/places';
            if (devSel && devSel !== 'all') placesUrl += '?device_id=' + encodeURIComponent(devSel);
            var resp = await fetch(placesUrl);
            if (!resp.ok) return;
            var data = await resp.json();
            if (!data.ok || !data.places) return;

            placeMarkers.clearLayers();

            data.places.forEach(function (p) {
                var lat = Number(p.lat);
                var lon = Number(p.lon);
                var radius = Math.max(15, Number(p.radius) || 30);
                var name = p.name ? escapeHtml(p.name) : 'Unnamed place';
                var visits = p.visit_count || 0;
                var totalTime = p.total_time || 0;
                var lastSeen = p.last_seen ? fmtTzDisplay(p.last_seen, { year: 'numeric', month: 'short', day: '2-digit' }) : '';

                // Circle marker (green, semi-transparent)
                var circle = L.circle([lat, lon], {
                    radius: radius,
                    color: '#16a34a',
                    fillColor: '#22c55e',
                    fillOpacity: 0.15,
                    weight: 2,
                    opacity: 0.6
                });

                // Label with name if named
                if (p.name) {
                    circle.bindTooltip(name, { permanent: true, direction: 'top', className: 'place-label', offset: [0, -5] });
                }

                var popupHtml = '<b><span style="color:#16a34a">📍 ' + name + '</span></b><br>';
                popupHtml += visits + ' visits<br>';
                if (totalTime > 0) {
                    var h = Math.floor(totalTime / 3600);
                    var m = Math.round((totalTime % 3600) / 60);
                    popupHtml += 'Total time: ' + (h > 0 ? h + 'h ' : '') + m + 'm<br>';
                }
                if (lastSeen) popupHtml += 'Last visit: ' + lastSeen + '<br>';
                popupHtml += 'Lat: ' + lat.toFixed(5) + ', Lon: ' + lon.toFixed(5) + '<br>';
                popupHtml += '~' + Math.round(radius) + 'm radius<br>';
                popupHtml += '<a href="/places/' + p.id + '" style="color:#2563eb">View details \u2192</a>';

                circle.addTo(placeMarkers).bindPopup(popupHtml);
            });
        } catch (e) {
            console.error('Places load error:', e);
        }
    }

    // ── Playback engine ────────────────────────────────────────────────────

    function updatePlaybackProgress() {
        if (pbState === 'idle') return;
        var cur = pbCurrentTst || pbStartTst;
        var fmtTime = function (ts) {
            return fmtTzDisplay(ts, { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
        };
        var total = pbEndTst - pbStartTst;
        var pct = total > 0 ? Math.round((cur - pbStartTst) / total * 100) : 0;
        document.getElementById('playbackProgress').textContent = pct + '% ' + fmtTime(cur) + ' / ' + fmtTime(pbEndTst);
    }

    function playbackTick() {
        if (pbState !== 'playing') return;
        pbCurrentTst = pbStartTst + (Date.now() - pbStartWall) * pbSpeed / 1000;
        if (pbCurrentTst >= pbEndTst) {
            pbCurrentTst = pbEndTst;
            updatePlaybackProgress();
            // Place marker at last point
            var lastPt = pbPoints[pbPoints.length - 1];
            pbMarker.setLatLng([lastPt.lat, lastPt.lon]);
            pbMarker.setPopupContent(popupContent(lastPt));
            // Fill trail completely
            pbTrail.setLatLngs(pbPoints.map(function (pt) { return [pt.lat, pt.lon]; }));
            pbRemaining.setLatLngs([]);
            window._stopPlayback();
            return;
        }

        // Forward scan from pbLastIdx to find bracketing points
        var i = pbLastIdx;
        while (i < pbPoints.length - 1 && pbPoints[i + 1].tst <= pbCurrentTst) { i++; }
        pbLastIdx = i;

        var p1 = pbPoints[i];
        var p2 = pbPoints[i + 1];
        var t1 = p1.tst;
        var t2 = p2.tst;
        var fraction = t2 > t1 ? (pbCurrentTst - t1) / (t2 - t1) : 0;
        var lat = Number(p1.lat) + (Number(p2.lat) - Number(p1.lat)) * fraction;
        var lon = Number(p1.lon) + (Number(p2.lon) - Number(p1.lon)) * fraction;

        pbMarker.setLatLng([lat, lon]);
        // Popup shows current point info (closest point ahead)
        pbMarker.setPopupContent(popupContent(fraction < 0.5 ? p1 : p2));

        // Trail: all points up to current index inclusive, plus interpolated end
        var trailLls = pbPoints.slice(0, i + 1).map(function (pt) { return [pt.lat, pt.lon]; });
        trailLls.push([lat, lon]);
        pbTrail.setLatLngs(trailLls);

        // Remaining: interpolated start plus all future points
        var remainLls = [[lat, lon]];
        pbPoints.slice(i + 1).forEach(function (pt) { remainLls.push([pt.lat, pt.lon]); });
        pbRemaining.setLatLngs(remainLls);

        updatePlaybackProgress();
        pbRafId = requestAnimationFrame(playbackTick);
    }

    function startPlayback() {
        if (pbState !== 'idle') return;
        if (pbPoints.length < 2) return;
        pbState = 'paused';
        pbStartTst = pbPoints[0].tst;
        pbEndTst = pbPoints[pbPoints.length - 1].tst;

        markers.clearLayers();
        accuracyCircles.clearLayers();
        speedSegments.clearLayers();
        document.getElementById('showSpeed').checked = false;
        try { localStorage.setItem('ot_show_speed', '0'); } catch (e) {}
        document.getElementById('speedLegend').classList.add('hidden');
        poiMarkers.clearLayers();
        polylines.forEach(function (pl) { map.removeLayer(pl); });
        polylines = [];

        document.getElementById('playbackBar').style.display = 'flex';
        document.getElementById('sidebar').classList.add('disabled');
        document.getElementById('playBtn').style.display = 'none';
        window._stopRefresh();

        var allLatlngs = pbPoints.map(function (pt) { return [pt.lat, pt.lon]; });
        pbRemaining = L.polyline(allLatlngs, {
            color: '#9ca3af', weight: 3, opacity: 0.35, smoothFactor: 1
        }).addTo(map);

        pbTrail = L.polyline([], {
            color: PB_COLOR, weight: 4, opacity: 0.9, smoothFactor: 1
        }).addTo(map);

        pbMarker = L.circleMarker([pbPoints[0].lat, pbPoints[0].lon], {
            radius: 9, color: '#fff', fillColor: PB_COLOR, fillOpacity: 1, weight: 3
        }).addTo(map).bindPopup(popupContent(pbPoints[0]));

        var bounds = L.latLngBounds(allLatlngs);
        if (bounds.isValid()) map.fitBounds(bounds, { padding: [50, 50], maxZoom: 17 });

        pbCurrentTst = pbStartTst;
        updatePlaybackProgress();
        window._togglePlayPause();
    }

    function togglePlayPause() {
        if (pbState === 'playing') {
            pbState = 'paused';
            cancelAnimationFrame(pbRafId);
            pbRafId = null;
            document.getElementById('playPauseBtn').textContent = '▶';
        } else if (pbState === 'paused') {
            pbState = 'playing';
            pbStartWall = Date.now() - (pbCurrentTst - pbStartTst) / pbSpeed * 1000;
            pbLastIdx = 0;
            document.getElementById('playPauseBtn').textContent = '⏸';
            playbackTick();
        }
    }

    function stopPlayback() {
        var wasPlaying = pbState !== 'idle';
        pbState = 'idle';
        cancelAnimationFrame(pbRafId);
        pbRafId = null;
        pbCurrentTst = null;
        pbLastIdx = 0;

        if (pbMarker) { map.removeLayer(pbMarker); pbMarker = null; }
        if (pbTrail)  { map.removeLayer(pbTrail);  pbTrail = null; }
        if (pbRemaining) { map.removeLayer(pbRemaining); pbRemaining = null; }

        document.getElementById('playbackBar').style.display = 'none';
        document.getElementById('sidebar').classList.remove('disabled');
        document.getElementById('playPauseBtn').textContent = '▶';

        if (wasPlaying) {
            loadData(true, true);
            if (document.getElementById('autoRefresh').checked) {
                scheduleRefresh();
            }
        }
    }

    function setPlaybackSpeed(val) {
        pbSpeed = parseInt(val) || 1;
        // If playing, resync wall clock so speed change is immediate
        if (pbState === 'playing') {
            pbStartWall = Date.now() - (pbCurrentTst - pbStartTst) / pbSpeed * 1000;
        }
    }

    window._startPlayback = startPlayback;
    window._stopPlayback = stopPlayback;
    window._togglePlayPause = togglePlayPause;
    window._setPlaybackSpeed = setPlaybackSpeed;

    // ── Auto-refresh ───────────────────────────────────────────────────────
    var refreshTimer = null;
    function scheduleRefresh() {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(function () {
            loadData(false, false);
            scheduleRefresh();
        }, 30000);
    }

    // ── Init ───────────────────────────────────────────────────────────────
    // Always start with browser timezone for the initial date range
    // (saved timezone is applied later when points are loaded)
    selectedTZ = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    var epoch = Date.now();
    var todayStr = fmtInTz(epoch).split('T')[0];
    document.getElementById('timeFrom').value = todayStr + 'T00:00';
    document.getElementById('timeTo').value   = todayStr + 'T23:59';

    try {
        var saved = localStorage.getItem('ot_selected_device');
        if (saved && document.querySelector('#deviceFilter option[value="' + saved + '"]')) {
            document.getElementById('deviceFilter').value = saved;
        }
    } catch (e) {}

    // Apply URL date params before initial load
    var _urlParams = new URLSearchParams(window.location.search);
    if (_urlParams.get('from')) {
        document.getElementById('timeFrom').value = _urlParams.get('from');
        disableAutoRefresh();
    }
    if (_urlParams.get('to')) {
        document.getElementById('timeTo').value = _urlParams.get('to');
    }

    loadData(true, true);
    scheduleRefresh();

    var savedCollapsed = localStorage.getItem('ot_sidebar_collapsed');
    if ((savedCollapsed === null && window.innerWidth < 500) || savedCollapsed === '1') {
        document.getElementById('sidebar').classList.add('collapsed');
        document.getElementById('sbToggle').textContent = '◀';
    }

    // Restore accuracy toggle
    try {
        var showAcc = localStorage.getItem('ot_show_accuracy');
        if (showAcc === '1') {
            document.getElementById('showAccuracy').checked = true;
            map.addLayer(accuracyCircles);
        } else {
            map.removeLayer(accuracyCircles);
        }
    } catch (e) {}

    // Restore speed toggle
    try {
        var showSpd = localStorage.getItem('ot_show_speed');
        var legend = document.getElementById('speedLegend');
        if (showSpd === '1') {
            document.getElementById('showSpeed').checked = true;
            map.addLayer(speedSegments);
            if (legend) legend.classList.remove('hidden');
        } else {
            map.removeLayer(speedSegments);
            if (legend) legend.classList.add('hidden');
        }
    } catch (e) {}

    // Restore POI toggle (default: off)
    try {
        var showPoi = localStorage.getItem('ot_show_pois');
        if (showPoi === '1') {
            document.getElementById('showPois').checked = true;
            map.addLayer(poiMarkers);
        } else {
            map.removeLayer(poiMarkers);
        }
    } catch (e) {}

    // Restore Places toggle (default: off)
    try {
        var showPlc = localStorage.getItem('ot_show_places');
        if (showPlc === '1') {
            document.getElementById('showPlaces').checked = true;
            map.addLayer(placeMarkers);
            loadPlaces();
        } else {
            map.removeLayer(placeMarkers);
        }
    } catch (e) {}

    // Handle ?zoom=place&id=123 URL parameter
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('zoom') === 'place' && urlParams.get('id')) {
        var zoomPlaceId = urlParams.get('id');
        fetch('/api/places').then(function(r) { return r.json(); }).then(function(data) {
            if (!data.ok || !data.places) return;
            var place = data.places.find(function(p) { return String(p.id) === String(zoomPlaceId); });
            if (place) {
                map.setView([Number(place.lat), Number(place.lon)], 17);
                // Auto-enable places layer if not already on
                if (!document.getElementById('showPlaces').checked) {
                    document.getElementById('showPlaces').checked = true;
                    map.addLayer(placeMarkers);
                    loadPlaces();
                }
            }
        }).catch(function(e) { console.error('Zoom to place error:', e); });
    }
})();
