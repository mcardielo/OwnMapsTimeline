#!/bin/sh
set -e

# Timezone: use TZ env var, fallback to UTC
TIMEZONE="${TZ:-UTC}"
echo ">>> Setting timezone to: ${TIMEZONE}"

# System timezone
if [ -f "/usr/share/zoneinfo/${TIMEZONE}" ]; then
    ln -sf "/usr/share/zoneinfo/${TIMEZONE}" /etc/localtime
    echo "${TIMEZONE}" > /etc/timezone
else
    echo ">>> WARNING: timezone '${TIMEZONE}' not found in zoneinfo, falling back to UTC"
    ln -sf /usr/share/zoneinfo/UTC /etc/localtime
    echo "UTC" > /etc/timezone
    TIMEZONE="UTC"
fi

# PHP timezone — replace placeholder in owntracks.ini
INI_FILE="/usr/local/etc/php/conf.d/owntracks.ini"
if [ -f "$INI_FILE" ]; then
    sed -i "s|__TZ_PLACEHOLDER__|${TIMEZONE}|g" "$INI_FILE"
    echo ">>> PHP date.timezone set to: ${TIMEZONE}"
fi

# Pass TZ to PHP-FPM pool environment (so PHP can use it)
FPM_POOL="/usr/local/etc/php-fpm.d/zz-custom.conf"
if [ -f "$FPM_POOL" ]; then
    if ! grep -q "env\[TZ\]" "$FPM_POOL"; then
        echo "env[TZ] = ${TIMEZONE}" >> "$FPM_POOL"
    fi
fi

exec "$@"
