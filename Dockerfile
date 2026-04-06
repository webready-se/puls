FROM php:8.2-cli-alpine

RUN apk add --no-cache --virtual .build-deps sqlite-dev \
    && docker-php-ext-install pdo_sqlite \
    && apk del .build-deps \
    && apk add --no-cache sqlite-libs curl

WORKDIR /app

COPY public/ public/
COPY config.php puls .env.example ./
RUN mkdir -p data

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s \
    CMD curl -sf http://localhost:8080/?health || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
