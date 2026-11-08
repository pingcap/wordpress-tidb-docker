FROM wordpress:7.1.4-php8.3-apache@sha256:12ec50e21cb81a5ea964669d434578b7da4a101a45aef83f5f1c2227bcd1b058 AS wordpress

# Bake the reviewed, checksum-verified shim into the image. A missing submodule
# fails the build; neither the host checkout nor executable code is writable.
COPY wordpress-tidb-compatibility-plugin/tidb-compatibility/tidb-compatibility.php /tmp/tidb-compatibility.php
COPY wordpress-tidb-compatibility-plugin/LICENSE /usr/share/doc/tidb-compatibility/LICENSE
RUN echo 'aa234d6c8fe89d1c9fea7804d9099d03c5ed4dea106015cb24131d04291fe0dc  /tmp/tidb-compatibility.php' | sha256sum -c - \
    && cp -a /usr/src/wordpress /opt/wordpress \
    && mkdir -p /opt/wordpress/wp-content/mu-plugins /opt/wordpress/wp-content/uploads \
    && mv /tmp/tidb-compatibility.php /opt/wordpress/wp-content/mu-plugins/tidb-compatibility.php \
    && chown -R root:root /opt/wordpress \
    && chown www-data:www-data /opt/wordpress/wp-content/uploads \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && a2dissite 000-default \
    && a2dismod -f autoindex \
    && a2disconf remoteip \
    && a2dismod remoteip \
    && a2enmod headers \
    && mkdir -p /var/lib/wordpress-security \
    && chown 33:33 /var/lib/wordpress-security \
    && chmod 0700 /var/lib/wordpress-security \
    && rm -f /opt/wordpress/wp-config-docker.php
COPY config/apache.conf /etc/apache2/sites-enabled/wordpress.conf
COPY config/php.ini /usr/local/etc/php/conf.d/security.ini
COPY config/wp-config.php /opt/wordpress/wp-config.php
COPY config/db.php /opt/wordpress/wp-content/db.php
COPY config/security-audit.php /opt/wordpress/wp-content/mu-plugins/security-audit.php
COPY config/settings.php config/db-connection.php config/healthcheck.php config/audit.php /opt/security/
COPY config/security-storage.php config/login-limits.php /opt/security/
COPY scripts/entrypoint.sh /usr/local/bin/security-entrypoint
RUN chmod 0555 /usr/local/bin/security-entrypoint \
    && printf 'ok\n' > /opt/wordpress/healthz.txt
WORKDIR /opt/wordpress
USER 33:33
ENTRYPOINT ["security-entrypoint"]
CMD ["apache2-foreground"]

FROM caddy:2.11.7-alpine@sha256:e70b164da81ef698b3ad19af799ea6086ce6d9e48ed4079eb9fe26f6fbf14ee5 AS proxy
COPY config/Caddyfile /etc/caddy/Caddyfile
RUN setcap -r /usr/bin/caddy \
    && mkdir -p /data /config \
    && chown -R 1000:1000 /data /config
USER 1000:1000
