#!/usr/bin/env bash
set -euo pipefail

case "${E2E_DB:-mariadb}" in
	mariadb) db_url='mysql://root:strata@mariadb:3306/strata' ;;
	postgres) db_url='pgsql://postgres:strata@postgres:5432/strata' ;;
	*)
		echo "E2E_DB must be mariadb or postgres, not ${E2E_DB}" >&2
		exit 2
		;;
esac

modules='node field text filter user key strata strata_s3 strata_azure strata_gcs strata_b2 strata_files strata_notify strata_ui redis strata_redis'

install_site() {
	cd /srv/drupal
	rm -f web/sites/default/settings.php web/sites/default/services.yml
	mkdir -p web/sites/default/files
	chmod -R u+w web/sites/default
	drush site:install minimal \
		--db-url="$db_url" \
		--site-name="Strata E2E" \
		--account-name=admin \
		--account-pass=admin \
		--yes
	drush pm:install $modules --yes
	cat >> web/sites/default/settings.php << 'PHP'
$settings['hash_salt'] = 'strata-e2e';
$settings['file_private_path'] = '/srv/drupal/private';
$settings['trusted_host_patterns'] = ['.*'];
$settings['redis.connection']['interface'] = 'PhpRedis';
$settings['redis.connection']['host'] = 'redis';
PHP
	mkdir -p /srv/drupal/private
}

run_tests() {
	cd /strata
	mkdir -p build/e2e
	exec php -d memory_limit=2G /srv/drupal/vendor/bin/phpunit -c phpunit.e2e.xml "$@"
}

command="${1:-test}"
[ "$#" -gt 0 ] && shift

case "$command" in
	install) install_site ;;
	test)
		install_site
		run_tests "$@"
		;;
	run) run_tests "$@" ;;
	shell) exec bash ;;
	*)
		echo "usage: strata-e2e [install|test|run|shell] [phpunit args]" >&2
		exit 2
		;;
esac
