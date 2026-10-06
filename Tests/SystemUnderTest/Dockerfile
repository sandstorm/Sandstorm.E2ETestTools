# Development distribution of Sandstorm.E2ETestTools: a Neos base distribution with this package and the test site
# installed from path repositories. The build context is the package root (see docker-compose.base.yml).
ARG PHP_VERSION
FROM dunglas/frankenphp:1-php${PHP_VERSION}-trixie

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# reference: https://github.com/mlocati/docker-php-extension-installer
# gd: Imagine driver (see neos-root/app/Configuration/Production/E2E-SUT/Settings.yaml)
RUN install-php-extensions \
    intl \
    pdo_mysql \
    gd \
    redis

RUN apt-get update \
    && apt-get install -y git unzip mariadb-client \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

ARG USER=www-data
# Give write access to /config/caddy and /data/caddy
RUN useradd ${USER}; \
    chown -R ${USER}:${USER} /config/caddy /data/caddy

ARG NEOS_VERSION
RUN rm -rf /app \
    && composer create-project --no-interaction neos/neos-base-distribution:^${NEOS_VERSION} /app \
    && composer --working-dir=/app remove --no-interaction neos/demo

# Install this package and the test site at build time, so a container start doesn't run composer. Only the
# composer.json files are needed here; docker-compose.base.yml mounts the code over them at runtime.
# After changing a composer.json: `mise run build`.
COPY composer.json /app/DistributionPackages/Sandstorm.E2ETestTools/composer.json
COPY Tests/SystemUnderTest/DistributionPackages/Sandstorm.E2ETestTools.TestSite/composer.json /app/DistributionPackages/Sandstorm.E2ETestTools.TestSite/composer.json
RUN cd /app \
    && composer config repositories.distribution-packages '{"type":"path","url":"./DistributionPackages/*","options":{"symlink":true}}' \
    && composer require --no-interaction --with-all-dependencies sandstorm/e2etesttools:@dev sandstorm/e2etesttools-testsite:@dev

# NOTE: after create-project, which replaces /app
COPY Tests/SystemUnderTest/neos-root/ /

ARG ENTRY_POINT_FILE
COPY ${ENTRY_POINT_FILE} /entrypoint.sh

RUN mkdir -p /app/Data /app/Web/_Resources \
    && chown -R ${USER} /app \
    && chmod +x /entrypoint.sh

WORKDIR /app
USER ${USER}

ENTRYPOINT ["/entrypoint.sh"]
