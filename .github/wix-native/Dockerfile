ARG PHP_BASE
FROM ${PHP_BASE}

# The official CLI image already supplies curl, DOM, mbstring, PDO SQLite,
# POSIX and the PHP build toolchain. No application or credentials in this image.
RUN docker-php-ext-install -j1 sockets \
    && php -r 'exit(extension_loaded("sockets") && curl_version()["version"] === "7.88.1" ? 0 : 1);'

WORKDIR /work
USER 1000:1000
