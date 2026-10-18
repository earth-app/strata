FROM php:8.4-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
	COMPOSER_ALLOW_SUPERUSER=1 \
	COMPOSER_MEMORY_LIMIT=-1 \
	COMPOSER_NO_INTERACTION=1

RUN apt-get update \
	&& apt-get install -y --no-install-recommends \
		ca-certificates curl git unzip zstd brotli \
		libzstd-dev libbrotli-dev libpq-dev libzip-dev libsodium-dev libpng-dev libjpeg-dev \
		libfreetype-dev default-mysql-client postgresql-client \
	&& rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
	&& docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql zip gd opcache sodium pcntl bcmath

RUN yes '' | pecl install redis \
	&& yes '' | pecl install zstd \
	&& yes '' | pecl install brotli \
	&& docker-php-ext-enable redis zstd brotli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/e2e/php.ini /usr/local/etc/php/conf.d/zz-strata-e2e.ini

ARG DRUPAL_CONSTRAINT=^11.2

WORKDIR /srv

RUN composer create-project "drupal/recommended-project:${DRUPAL_CONSTRAINT}" drupal --no-install

WORKDIR /srv/drupal

COPY composer.json /strata/composer.json

RUN composer config repositories.strata '{"type":"path","url":"/strata","options":{"symlink":true}}' \
	&& composer config minimum-stability dev \
	&& composer config prefer-stable true \
	&& composer config --no-plugins allow-plugins.composer/installers true \
	&& composer config --no-plugins allow-plugins.drupal/core-composer-scaffold true \
	&& composer config --no-plugins allow-plugins.drupal/core-project-message true \
	&& composer config --no-plugins allow-plugins.php-http/discovery true \
	&& composer require "earth-app/strata:@dev" drush/drush drupal/redis "phpunit/phpunit:^11.0 || ^12.0" \
		--no-progress

ENV PATH="/srv/drupal/vendor/bin:${PATH}" \
	DRUPAL_ROOT=/srv/drupal/web

RUN printf '#!/usr/bin/env bash\nexec /strata/docker/e2e/run.sh "$@"\n' > /usr/local/bin/strata-e2e \
	&& chmod +x /usr/local/bin/strata-e2e

WORKDIR /strata

ENTRYPOINT ["strata-e2e"]
