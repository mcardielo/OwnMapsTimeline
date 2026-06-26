# Fat container: PHP-FPM 8.3 Alpine + Nginx + Supervisor
FROM php:8.3-fpm-alpine

LABEL org.opencontainers.image.title="OwnMapsTimeline"
LABEL org.opencontainers.image.description="A OwnTracks companion server and Google Maps Timeline replacement. Receive GPS location data via webhook, visualize your tracks on an interactive map, and manage multiple users and devices, all in a single Docker container."

# Install system deps + nginx + supervisor
RUN apk add --no-cache --virtual .build-deps \
    sqlite-dev \
    oniguruma-dev \
    && apk add --no-cache \
    nginx \
    supervisor \
    tzdata \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    pdo_sqlite \
    opcache \
    mbstring \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

# Create app structure
RUN mkdir -p /app/data /app/public/assets /app/src/config /app/src/controllers /app/src/views /run/nginx /var/log/supervisor

# Copy app code
COPY . /app
WORKDIR /app

# Nginx config — replace default server block, keep main nginx.conf intact
RUN rm -f /etc/nginx/http.d/default.conf 2>/dev/null; true
COPY nginx.conf /etc/nginx/http.d/default.conf

# PHP config (timezone placeholder — resolved at runtime via entrypoint)
RUN { \
    echo "upload_max_filesize = 16M"; \
    echo "post_max_size = 16M"; \
    echo "date.timezone = __TZ_PLACEHOLDER__"; \
    echo "memory_limit = 128M"; \
    echo "max_execution_time = 60"; \
    echo "opcache.enable = 1"; \
    echo "opcache.memory_consumption = 64"; \
    echo "opcache.max_accelerated_files = 4000"; \
    } > /usr/local/etc/php/conf.d/owntracks.ini

# PHP-FPM pool config: more workers + ondemand for stability
RUN { \
    echo '[www]'; \
    echo 'pm = ondemand'; \
    echo 'pm.max_children = 20'; \
    echo 'pm.process_idle_timeout = 30s'; \
    echo 'pm.max_requests = 500'; \
    echo 'request_terminate_timeout = 60s'; \
    } > /usr/local/etc/php-fpm.d/zz-custom.conf

# Supervisor config
COPY supervisord.conf /etc/supervisord.conf

# Permissions
RUN chown -R www-data:www-data /app /run/nginx /var/log/supervisor

# Entrypoint: resolves timezone at runtime
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80
VOLUME ["/app/data"]

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
