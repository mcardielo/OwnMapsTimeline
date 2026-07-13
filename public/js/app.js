/**
 * app.js — Shared helpers for my-owntracks-frontend
 */

/** Selected timezone (IANA). Default: browser timezone. */
var selectedTZ = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';

/** Set the active timezone for all date formatting/parsing */
function setTimezone(tz) {
    selectedTZ = tz;
    try { localStorage.setItem('ot_selected_tz', tz); } catch (e) {}
}

/** Load saved timezone from localStorage */
function loadTimezone() {
    try {
        var saved = localStorage.getItem('ot_selected_tz');
        if (saved) selectedTZ = saved;
    } catch (e) {}
}

/** Format an epoch (ms) to datetime-local string (YYYY-MM-DDTHH:mm) in the given timezone.
 *  If tz is omitted, uses selectedTZ. */
function fmtInTz(epochMs, tz) {
    tz = tz || selectedTZ;
    var parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: tz,
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hour12: false
    }).formatToParts(new Date(epochMs));

    var y, mo, d, h, mi;
    for (var i = 0; i < parts.length; i++) {
        if (parts[i].type === 'year') y = parts[i].value;
        else if (parts[i].type === 'month') mo = parts[i].value;
        else if (parts[i].type === 'day') d = parts[i].value;
        else if (parts[i].type === 'hour') h = parts[i].value;
        else if (parts[i].type === 'minute') mi = parts[i].value;
    }
    // Intl with hour12:false can return "24" for midnight in some environments
    if (h === '24') h = '00';
    return y + '-' + mo + '-' + d + 'T' + h + ':' + mi;
}

/** Parse a datetime-local string (YYYY-MM-DDTHH:mm) interpreted in the given timezone,
 *  returning a Date object (epoch). If tz is omitted, uses selectedTZ. */
function parseInTz(str, tz) {
    if (!str) return new Date(NaN);
    tz = tz || selectedTZ;
    var parts = str.split('T');
    var dp = parts[0].split('-');
    var tp = parts[1] ? parts[1].split(':') : [0, 0];
    var y = parseInt(dp[0], 10);
    var mo = parseInt(dp[1], 10) - 1;
    var d = parseInt(dp[2], 10);
    var h = parseInt(tp[0], 10) || 0;
    var mi = parseInt(tp[1], 10) || 0;

    // Strategy: find the UTC offset for this tz at the given local time.
    // 1. Create a "naive" UTC date from the parts (as if the local string were UTC)
    var naive = Date.UTC(y, mo, d, h, mi, 0);
    // 2. Format that UTC epoch in the target tz to see what local time it represents there
    var check = new Intl.DateTimeFormat('en-CA', {
        timeZone: tz,
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hour12: false
    }).formatToParts(new Date(naive));
    var cy, cmo, cd, ch, cmi;
    for (var i = 0; i < check.length; i++) {
        if (check[i].type === 'year') cy = parseInt(check[i].value, 10);
        else if (check[i].type === 'month') cmo = parseInt(check[i].value, 10) - 1;
        else if (check[i].type === 'day') cd = parseInt(check[i].value, 10);
        else if (check[i].type === 'hour') ch = parseInt(check[i].value, 10);
        else if (check[i].type === 'minute') cmi = parseInt(check[i].value, 10);
    }
    if (ch === 24) ch = 0;
    // 3. actualLocal = the UTC epoch that, when formatted in tz, gives the same
    //    date/time as the input string. The offset between naive and actualLocal
    //    tells us how far the tz is from UTC.
    //    If tz is BEHIND UTC (e.g. Mexico City -6), naive formatted in tz shows
    //    a time 6h EARLIER than the input. So actualLocal < naive, offset > 0.
    //    The correct UTC epoch for the input local time = naive + offset
    //    (because the local event happened LATER in UTC than naive suggests)
    var actualLocal = Date.UTC(cy, cmo, cd, ch, cmi, 0);
    var offset = naive - actualLocal;
    return new Date(naive + offset);
}

// ── Legacy wrappers (use selectedTZ) ──────────────────────────────────────

/** Format an epoch (ms) to datetime-local string using selectedTZ */
function fmtLocalDatetime(d) {
    if (d instanceof Date) d = d.getTime();
    return fmtInTz(d);
}

/** Parse a datetime-local string using selectedTZ */
function parseDatetimeLocal(str) {
    return parseInTz(str);
}

/** Convert a Unix timestamp (seconds) to datetime-local string using selectedTZ */
function tstToLocalDatetime(tst) {
    return fmtInTz(tst * 1000);
}

/** Format a Unix timestamp (seconds) for display in selectedTZ (human readable) */
function fmtTzDisplay(tst, opts) {
    opts = opts || { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false };
    return new Intl.DateTimeFormat(undefined, Object.assign({ timeZone: selectedTZ }, opts)).format(new Date(tst * 1000));
}

/** HTML-escape a string to prevent XSS */
function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
