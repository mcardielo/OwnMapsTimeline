/**
 * dashboard.js — Map, filters, playback for the main dashboard view.
 * Requires: Leaflet (loaded via CDN in <head>), app.js
 */

// ── Sidebar & filter controls ──────────────────────────────────────────────

function disableAutoRefresh() {
    document.getElementById('autoRefresh').checked = false;
    if (window._stopRefresh) window._stopRefresh();
}

function onDeviceChange() {
    var val = document.getElementById('deviceFilter').value;
    try { localStorage.setItem('ot_selected_device', val); } catch (e) {}
    if (window._mapLoadData) window._mapLoadData(true);
}

function resetFilters() {
    var now = new Date();
    document.getElementById('timeFrom').value = fmtLocalDatetime(new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0));
    document.getElementById('timeTo').value   = fmtLocalDatetime(new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 0));
    document.getElementById('autoRefresh').checked = true;
    if (window._mapLoadData) window._mapLoadData(true);
    if (window._resetRefresh) window._resetRefresh();
}

function setQuickRange(hours) {
    var n = new Date();
    document.getElementById('timeFrom').value = fmtLocalDatetime(new Date(n.getTime() - hours * 3600 * 1000));
    document.getElementById('timeTo').value = fmtLocalDatetime(n);
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
    var fromDate = new Date(fromEl.value);
    var toDate = new Date(toEl.value);
    if (isNaN(fromDate.getTime()) || isNaN(toDate.getTime())) return;

    fromDate.setDate(fromDate.getDate() + delta);
    toDate.setDate(toDate.getDate() + delta);

    var span = toDate.getTime() - fromDate.getTime();
    if (fromEl.min && fromDate < new Date(fromEl.min)) {
        fromDate = new Date(fromEl.min);
        toDate = new Date(fromDate.getTime() + span);
    }
    if (toEl.max && toDate > new Date(toEl.max)) {
        toDate = new Date(toEl.max);
        fromDate = new Date(toDate.getTime() - span);
    }

    fromEl.value = fmtLocalDatetime(fromDate);
    toEl.value = fmtLocalDatetime(toDate);
    applyFilters();
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

(function () {
    var map = L.map('map', { zoomControl: true }).setView([20, 0], 2);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://osm.org/copyright">OSM</a>',
        maxZoom: 19
    }).addTo(map);

    var markers = L.featureGroup().addTo(map);
    var accuracyCircles = L.featureGroup().addTo(map);
    var speedSegments = L.featureGroup().addTo(map);
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

    /** Map velocity (km/h) to a color: green → yellow → red */
    function speedToColor(vel) {
        var v = Math.max(0, Math.min(120, Number(vel) || 0));
        var ratio = v / 120;  // 0=green, 0.5=yellow, 1=red
        var r, g, b;
        if (ratio < 0.5) {
            // green → yellow
            var s = ratio * 2;
            r = Math.round(255 * s);
            g = 255;
            b = 0;
        } else {
            // yellow → red
            var s = (ratio - 0.5) * 2;
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
        var dt = new Date(p.tst * 1000).toLocaleString();
        var html = '<b>' + dt + '</b>';
        html += '<br>Latitude: ' + Number(p.lat).toFixed(6);
        html += '<br>Longitude: ' + Number(p.lon).toFixed(6);
        if (p.acc != null) html += '<br>Accuracy: ' + Number(p.acc).toFixed(1) + ' m';
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
        var fromEl = document.getElementById('timeFrom');
        var toEl = document.getElementById('timeTo');
        var url = '/api/locations?';
        if (deviceId !== 'all') url += 'device_id=' + deviceId + '&';
        if (fromEl.value) url += 'from=' + Math.floor(new Date(fromEl.value).getTime() / 1000) + '&';
        if (toEl.value) url += 'to=' + Math.floor(new Date(toEl.value).getTime() / 1000) + '&';

        try {
            var resp = await fetch(url);
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            var data = await resp.json();

            markers.clearLayers();
            accuracyCircles.clearLayers();
            speedSegments.clearLayers();
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
                var d = new Date(ts * 1000);
                var pad = function (n) { return String(n).padStart(2, '0'); };
                return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
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

    // ── Playback engine ────────────────────────────────────────────────────

    function updatePlaybackProgress() {
        if (pbState === 'idle') return;
        var cur = pbCurrentTst || pbStartTst;
        var fmtTime = function (ts) {
            var d = new Date(ts * 1000);
            var pad = function (n) { return String(n).padStart(2, '0'); };
            return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
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
    var now = new Date();
    var todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0);
    var todayEnd   = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59, 0);
    document.getElementById('timeFrom').value = fmtLocalDatetime(todayStart);
    document.getElementById('timeTo').value   = fmtLocalDatetime(todayEnd);

    try {
        var saved = localStorage.getItem('ot_selected_device');
        if (saved && document.querySelector('#deviceFilter option[value="' + saved + '"]')) {
            document.getElementById('deviceFilter').value = saved;
        }
    } catch (e) {}

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
})();
