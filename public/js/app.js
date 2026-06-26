/**
 * app.js — Shared helpers for my-owntracks-frontend
 */

/** Format a Date object to datetime-local value (YYYY-MM-DDTHH:mm) */
function fmtLocalDatetime(d) {
    var pad = function(n) { return String(n).padStart(2, '0'); };
    return d.getFullYear() + '-' +
        pad(d.getMonth() + 1) + '-' +
        pad(d.getDate()) + 'T' +
        pad(d.getHours()) + ':' +
        pad(d.getMinutes());
}

/** Convert a Unix timestamp (seconds) to datetime-local string */
function tstToLocalDatetime(tst) {
    return fmtLocalDatetime(new Date(tst * 1000));
}

/** HTML-escape a string to prevent XSS */
function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
