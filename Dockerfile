FROM dunglas/frankenphp:php8.4-bookworm

# v3 - MySQL
RUN install-php-extensions mysqli pdo_mysql mbstring curl gd zip opcache

# Session storage
RUN mkdir -p /tmp/php_sessions && chmod 1777 /tmp/php_sessions && \
    echo "session.save_path = /tmp/php_sessions" > /usr/local/etc/php/conf.d/sessions.ini

# Upload limits — PHP's defaults are 2 MB per file / 8 MB per request, which rejects most phone photos and
# X-rays even though the UI allows up to 10 MB. post_max_size must be bigger than upload_max_filesize.
RUN printf "upload_max_filesize=12M\npost_max_size=14M\nmemory_limit=256M\n" > /usr/local/etc/php/conf.d/uploads.ini

# OPcache — eliminates PHP re-parsing on every request
RUN echo "[opcache]"                                       >  /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.enable=1"                               >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.memory_consumption=128"                 >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.interned_strings_buffer=16"             >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.max_accelerated_files=10000"            >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.validate_timestamps=0"                  >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.revalidate_freq=0"                      >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.fast_shutdown=1"                        >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.enable_cli=0"                           >> /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /app
COPY . .

# Download Bootstrap assets at build time
RUN mkdir -p assets/css/fonts assets/js && \
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" -o assets/css/bootstrap.min.css && \
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" -o assets/js/bootstrap.bundle.min.js && \
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" -o assets/css/bootstrap-icons.css && \
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/fonts/bootstrap-icons.woff2" -o assets/css/fonts/bootstrap-icons.woff2 && \
    curl -sL "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/fonts/bootstrap-icons.woff" -o assets/css/fonts/bootstrap-icons.woff

# Use FrankenPHP (multi-threaded, production-grade) instead of php -S
# Use Caddyfile for gzip + cache headers
COPY Caddyfile /app/Caddyfile
ENTRYPOINT ["frankenphp", "run", "--config", "/app/Caddyfile"]
RUN rm -rf database/ docs/
