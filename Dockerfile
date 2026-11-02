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
    && a2enmod headers \
    && rm -f /opt/wordpress/wp-config-docker.php
COPY config/apache.conf /etc/apache2/sites-enabled/wordpress.conf
COPY config/php.ini /usr/local/etc/php/conf.d/security.ini
COPY config/wp-config.php /opt/wordpress/wp-config.php
COPY config/db.php /opt/wordpress/wp-content/db.php
COPY config/security-audit.php /opt/wordpress/wp-content/mu-plugins/security-audit.php
COPY config/settings.php config/db-connection.php config/healthcheck.php /opt/security/
COPY scripts/entrypoint.sh /usr/local/bin/security-entrypoint
RUN chmod 0555 /usr/local/bin/security-entrypoint \
    && printf 'ok\n' > /opt/wordpress/healthz.txt
WORKDIR /opt/wordpress
USER 33:33
ENTRYPOINT ["security-entrypoint"]
CMD ["apache2-foreground"]

FROM caddy:2.11.3-alpine@sha256:86deaf5e3d3408a6ccec08fbb79989783dd26e206ae10bcf78a801dc8c9ab794 AS proxy
COPY config/Caddyfile /etc/caddy/Caddyfile
RUN mkdir -p /data /config && chown -R 1000:1000 /data /config
USER 1000:1000
