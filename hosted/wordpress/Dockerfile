# BosqueGrande hosted edition: WordPress (Apache + PHP 8.3) with the plugin,
# the hosting must-use plugin, and hardened PHP settings baked in.
# The build context is the hosted/ folder (see docker-compose.yml).
# Pin WP_IMAGE to a digest in production, for example
#   WP_IMAGE=wordpress:6.8-php8.3-apache@sha256:... in .env
ARG WP_IMAGE=wordpress:php8.3-apache
FROM ${WP_IMAGE}

# wp-cli for provisioning and the cron loop.
RUN curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
 && curl -fsSL -o /tmp/wp-cli.sha512 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar.sha512 \
 && echo "$(cat /tmp/wp-cli.sha512)  /usr/local/bin/wp" | sha512sum -c - \
 && chmod 0755 /usr/local/bin/wp && rm /tmp/wp-cli.sha512

# Real client addresses from the Caddy proxy, for login throttling and rate
# limits. The zz- prefix makes this load after Debian's security.conf.
RUN a2enmod remoteip headers \
 && printf '%s\n' \
    'RemoteIPHeader X-Forwarded-For' \
    'RemoteIPInternalProxy 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16' \
    'ServerTokens Prod' \
    'ServerSignature Off' \
    'TraceEnable Off' \
    > /etc/apache2/conf-available/zz-bosquegrande.conf \
 && a2enconf zz-bosquegrande

COPY wordpress/php-hardening.ini /usr/local/etc/php/conf.d/zz-bosquegrande.ini
COPY wordpress/wp-config-hosted.php /etc/bosquegrande/wp-config-hosted.php
COPY wordpress/htaccess-multisite /usr/src/wordpress/.htaccess
COPY wordpress/bosquegrande-start /usr/local/bin/bosquegrande-start
COPY mu-plugins/ /usr/src/wordpress/wp-content/mu-plugins/
COPY plugin/bosquegrande/ /usr/src/wordpress/wp-content/plugins/bosquegrande/

RUN rm -rf /usr/src/wordpress/wp-content/plugins/akismet /usr/src/wordpress/wp-content/plugins/hello.php \
 && install -d -o www-data -g www-data -m 0700 /var/lib/bosquegrande /var/lib/bosquegrande/keyrings /var/lib/bosquegrande/private \
 && chmod 0755 /usr/local/bin/bosquegrande-start

CMD ["bosquegrande-start"]
