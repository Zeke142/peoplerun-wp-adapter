ARG DEBIAN_IMAGE_DIGEST
FROM debian@${DEBIAN_IMAGE_DIGEST}
ARG WORDPRESS_URL
ARG WORDPRESS_SHA256
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    mariadb-server mariadb-client php-cli php-mysql php-curl php-xml php-mbstring php-zip \
    python3 openssl curl ca-certificates unzip && rm -rf /var/lib/apt/lists/*
RUN curl -fLsS "$WORDPRESS_URL" -o /tmp/wordpress.tar.gz \
    && echo "$WORDPRESS_SHA256  /tmp/wordpress.tar.gz" | sha256sum -c - \
    && mkdir -p /srv && tar -xzf /tmp/wordpress.tar.gz -C /srv \
    && test -f /srv/wordpress/wp-load.php && rm /tmp/wordpress.tar.gz
COPY acceptance-inputs/o01-v7.zip /opt/o01/harness.zip
COPY o01-v7-runtime/launch.sh /opt/o01/launch.sh
COPY o01-v7-runtime/bootstrap-wp.php /opt/o01/bootstrap-wp.php
