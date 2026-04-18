FROM wordpress:6.7-php8.3-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    cron \
    curl \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# HeliosDB-Nano binary (from pre-built image)
COPY --from=heliosdb-nano:mysql /usr/local/bin/heliosdb-nano /usr/local/bin/heliosdb-nano

# Copy WAIpress plugin
COPY . /usr/src/wordpress/wp-content/plugins/waipress/

# HeliosDB-Nano is wire-compatible with MySQL, so the stock wpdb layer works
# unmodified -- no db.php drop-in required.

# System cron for reliable WP-Cron execution
RUN echo '* * * * * www-data curl -sf http://localhost/wp-cron.php?doing_wp_cron > /dev/null 2>&1' \
    > /etc/cron.d/waipress-cron \
    && chmod 0644 /etc/cron.d/waipress-cron

# Disable WP's built-in cron (system cron handles it)
RUN echo "define('DISABLE_WP_CRON', true);" >> /usr/src/wordpress/wp-config-docker.php

# Auto-activate the plugin on first install
RUN echo "define('WAIPRESS_AUTO_ACTIVATE', true);" >> /usr/src/wordpress/wp-config-docker.php

# Custom entrypoint
COPY docker/entrypoint.sh /usr/local/bin/waipress-entrypoint.sh
RUN chmod +x /usr/local/bin/waipress-entrypoint.sh

# Health check
COPY docker/healthcheck.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/healthcheck.sh
HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=30s \
    CMD /usr/local/bin/healthcheck.sh

# Data directories
RUN mkdir -p /data

# Set permissions
RUN chown -R www-data:www-data /usr/src/wordpress/wp-content/

EXPOSE 80

ENTRYPOINT ["waipress-entrypoint.sh"]
CMD ["apache2-foreground"]
