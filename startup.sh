#!/usr/bin/env bash
set -euo pipefail

PROJECT_NAME="strata"
SITE_DIR="/tmp/drupal-$PROJECT_NAME"
SITE_NAME="Drupal $PROJECT_NAME Test"
SRC_PATH="$(realpath .)"
COMPOSE_FILE="$SRC_PATH/docker/compose.yml"

# minio, from docker/compose.yml
S3_ENDPOINT="http://host.docker.internal:9000"
S3_ENDPOINT_HOST="http://127.0.0.1:9000"
S3_BUCKET="strata-backups"
S3_REGION="us-east-1"
S3_KEY_ID="strata"
S3_SECRET="stratatest"

usage() {
	cat << 'USAGE'
Usage: ./startup.sh [command] [options]

Build the playground (the default when no command is given):

  ./startup.sh [--db=mariadb|postgres|sqlite] [--fresh] [--no-s3]

  --db=       database ddev runs; defaults to mariadb
  --fresh     delete the existing site and rebuild it from scratch
  --no-s3     skip the minio container and use the local filesystem provider

Drive it. Each command needs the site to exist already.

  traffic     generate traffic that looks like a site somebody uses, and seal it
              --scale=small|medium|large|huge|<n>   how many subjects; default small
              --rewrites=<n>                        edits per subject; default 2

  measure     what the store holds, what it cost, and what a month of it would cost

  rollback    plan a rollback and print the manifest; --apply performs it
              --to=<commit|HEAD~n>                  target; default HEAD~1
              --apply                               write it back

  meltdown    break the store on purpose
              --kind=delete|corrupt|truncate|anchor|ref|dictionary|everything
              --share=<0-100>                       how much of it; default 50

  heal        run the repair ladder as far as it may go on its own
              --apply                               actually repair rather than plan

  attack      try what an attacker would try and print what each probe gets
              --kind=keys|tamper|foreign|xxe|webhook|flood|permissions|everything

  scenario    all of the above in order, so the whole story is one command
              --scale=small|medium|large            passed to the traffic act

Nothing here is reversible and none of it belongs anywhere near a real site.
USAGE
}

DB="mariadb"
FRESH=0
USE_S3=1
COMMAND="build"
SCALE="small"
REWRITES="2"
KIND=""
SHARE="50"
TARGET="HEAD~1"
APPLY=0

if [ "$#" -gt 0 ]; then
	case "$1" in
		traffic | measure | rollback | meltdown | heal | attack | scenario)
			COMMAND="$1"
			shift
			;;
	esac
fi

for arg in "$@"; do
	case "$arg" in
		--db=*) DB="${arg#*=}" ;;
		--fresh) FRESH=1 ;;
		--no-s3) USE_S3=0 ;;
		--scale=*) SCALE="${arg#*=}" ;;
		--rewrites=*) REWRITES="${arg#*=}" ;;
		--kind=*) KIND="${arg#*=}" ;;
		--share=*) SHARE="${arg#*=}" ;;
		--to=*) TARGET="${arg#*=}" ;;
		--apply) APPLY=1 ;;
		-h | --help)
			usage
			exit 0
			;;
		*)
			echo ">>> Unknown option: $arg"
			usage
			exit 1
			;;
	esac
done

case "$DB" in
	mariadb) DDEV_DB="mariadb:10.11" ;;
	postgres) DDEV_DB="postgres:16" ;;
	sqlite) DDEV_DB="" ;;
	*)
		echo ">>> Unknown database: $DB"
		exit 1
		;;
esac

need() {
	command -v "$1" > /dev/null 2>&1 || {
		echo ">>> $1 is required but not installed"
		exit 1
	}
}

need ddev

#region Commands

# absolute inside the container: drush resolves a relative script path against its own root
SCRIPT_PATH="/var/www/html/web/modules/custom/$PROJECT_NAME/scripts"

# a demo script, run inside the container against the throwaway site
run_script() {
	local script="$1"
	shift

	if [ ! -d "$SITE_DIR" ]; then
		echo ">>> No site at $SITE_DIR yet. Run ./startup.sh first."
		exit 1
	fi

	cd "$SITE_DIR"
	ddev start > /dev/null
	ddev drush php:script "$script.php" --script-path="$SCRIPT_PATH" -- "$@"
}

case "$COMMAND" in
	traffic)
		run_script traffic "$SCALE" "$REWRITES"
		exit 0
		;;
	measure)
		run_script measure
		exit 0
		;;
	rollback)
		if [ "$APPLY" = "1" ]; then
			run_script rollback "$TARGET" apply
		else
			run_script rollback "$TARGET" plan
		fi
		exit 0
		;;
	meltdown)
		run_script meltdown "${KIND:-corrupt}" "$SHARE"
		exit 0
		;;
	heal)
		if [ "$APPLY" = "1" ]; then
			run_script heal apply
		else
			run_script heal plan
		fi
		exit 0
		;;
	attack)
		run_script attack "${KIND:-everything}"
		exit 0
		;;
	scenario)
		run_script scenario "$SCALE"
		exit 0
		;;
esac

#endregion

need composer

if [ "$FRESH" = "1" ] && [ -d "$SITE_DIR" ]; then
	echo ">>> Removing the existing site at $SITE_DIR"
	(cd "$SITE_DIR" && ddev delete -Oy > /dev/null 2>&1) || true
	rm -rf "$SITE_DIR"
fi

#region S3

if [ "$USE_S3" = "1" ]; then
	need docker

	echo ">>> Starting minio from $COMPOSE_FILE"

	if ! docker info > /dev/null 2>&1; then
		echo ">>> Docker is not running. Start Docker Desktop and re-run."
		exit 1
	fi

	docker compose -f "$COMPOSE_FILE" up -d --wait
	echo ">>> minio ready: $S3_ENDPOINT_HOST (console http://127.0.0.1:9001, strata / stratatest)"
fi

#endregion

#region Site

copy_module() {
	mkdir -p "web/modules/custom/$PROJECT_NAME"

	find "$SRC_PATH" -maxdepth 1 \
		\( -name "*.php" \
		-o -name "*.yml" \
		-o -name "*.yaml" \
		-o -name "*.info" \
		-o -name "*.module" \
		-o -name "*.install" \
		-o -name "*.inc" \
		-o -name "*.json" \) \
		-exec cp {} "web/modules/custom/$PROJECT_NAME/" \;

	for dir in src modules config stubs templates scripts; do
		[ -d "$SRC_PATH/$dir" ] && cp -r "$SRC_PATH/$dir" "web/modules/custom/$PROJECT_NAME/"
	done

	# composer.json in the module directory makes the site's composer try to resolve it
	rm -f "web/modules/custom/$PROJECT_NAME/composer.json"
	rm -f "web/modules/custom/$PROJECT_NAME/package.json"
}

if [ ! -d "$SITE_DIR" ]; then
	echo ">>> Creating a new Drupal site in $SITE_DIR"
	echo ">>> Using module at $SRC_PATH"

	composer create-project --no-interaction drupal/recommended-project "$SITE_DIR"

	cd "$SITE_DIR"
	composer require --no-interaction drush/drush drupal/key

	copy_module

	echo ">>> Configuring ddev for Drupal 11 ($DB)"

	if [ -n "$DDEV_DB" ]; then
		ddev config --project-type=drupal11 --docroot=web \
			--project-name="$PROJECT_NAME" --host-webserver-port=8788 --database="$DDEV_DB"
	else
		ddev config --project-type=drupal11 --docroot=web \
			--project-name="$PROJECT_NAME" --host-webserver-port=8788
	fi

	ddev start

	ddev drush -y site:install minimal \
		--account-name=admin \
		--account-pass=admin \
		--site-name="$SITE_NAME"

	ddev drush -y en key
	ddev drush cr
	ddev drush -y en "$PROJECT_NAME"
else
	echo ">>> Reusing the site at $SITE_DIR"
	echo ">>> Using module at $SRC_PATH"

	cd "$SITE_DIR"
	ddev start > /dev/null

	# uninstall first so a changed hook_schema is reinstalled rather than drifting
	ddev drush -y pmu "$PROJECT_NAME" > /dev/null 2>&1 || true

	copy_module

	ddev drush cr
	ddev drush -y updb
	ddev drush -y en "$PROJECT_NAME"
fi

#endregion

#region Configure

echo
echo ">>> Configuring $PROJECT_NAME"

# a 32-byte key, hex encoded; regenerated per run so a stale bucket cannot be read by accident
ENCRYPTION_KEY="$(openssl rand -hex 32)"

ddev drush -y key:save strata_encryption \
	--label='Strata encryption key' \
	--key-type=encryption \
	--key-provider=config \
	--key-provider-settings="{\"key_value\":\"$ENCRYPTION_KEY\"}" > /dev/null 2>&1 \
	|| echo ">>> key:save unavailable; set the key by hand at /admin/config/system/keys"

ddev drush -y config:set strata.settings enabled 1
ddev drush -y config:set strata.settings site_id "$PROJECT_NAME-local"
ddev drush -y config:set strata.settings key strata_encryption
ddev drush -y config:set strata.settings flush.max_ops 5

if [ "$USE_S3" = "1" ]; then
	ddev drush -y en strata_s3 > /dev/null 2>&1 || true
	ddev drush -y config:set strata.settings provider s3
	ddev drush -y config:set strata.settings s3.endpoint "$S3_ENDPOINT"
	ddev drush -y config:set strata.settings s3.bucket "$S3_BUCKET"
	ddev drush -y config:set strata.settings s3.region "$S3_REGION"
	ddev drush -y config:set strata.settings s3.access_key_id "$S3_KEY_ID"
	ddev drush -y config:set strata.settings s3.secret_access_key "$S3_SECRET"
	ddev drush -y config:set strata.settings s3.path_style 1
else
	ddev drush -y config:set strata.settings provider local

	# a real directory rather than private://, which only exists once file_private_path is set
	ddev drush -y config:set strata.settings local_path '/var/www/html/private/strata'
	ddev exec mkdir -p /var/www/html/private/strata
fi

ddev drush cr

#endregion

#region Verify

echo
echo ">>> Exercising the pipeline"

ddev drush status --field=drupal-version

# a real capture: create a node type and a node, then force a flush
ddev drush -y php:eval '
$type = \Drupal\node\Entity\NodeType::load("page");
if (!$type) {
  \Drupal\node\Entity\NodeType::create(["type" => "page", "name" => "Page"])->save();
}
$node = \Drupal\node\Entity\Node::create(["type" => "page", "title" => "Strata smoke " . date("H:i:s")]);
$node->save();
print "captured node " . $node->id() . "\n";
' 2> /dev/null || echo ">>> node module not enabled; capturing a user instead"

ddev drush -y php:eval '
$user = \Drupal\user\Entity\User::create([
  "name" => "strata-smoke-" . time(),
  "mail" => "smoke@example.com",
  "status" => 1,
]);
$user->save();
print "captured user " . $user->id() . "\n";
'

echo
echo ">>> Flushing"
ddev drush -y php:eval '
$result = \Drupal::service("strata.engine")->flusher()->flush(true);
print $result->summary() . "\n";
if ($result->ran) {
  print "  segment: " . $result->segment . "\n";
  print "  commit:  " . $result->commit . "\n";
}
'

echo
echo ">>> What the store holds"
ddev drush -y php:eval '
$provider = \Drupal::service("strata.engine")->provider();
$total = 0;
$bytes = 0;
foreach (["frames/", "packs/", "segments/", "trees/", "commits/", "refs/"] as $prefix) {
  $page = $provider->list($prefix, NULL, 1000);
  printf("  %-12s %3d objects, %8d bytes\n", $prefix, count($page), $page->bytes());
  $total += count($page);
  $bytes += $page->bytes();
}
printf("  %-12s %3d objects, %8d bytes\n", "TOTAL", $total, $bytes);
'

#endregion

echo
echo ">>> Ready"
echo "Site:        https://${PROJECT_NAME}.ddev.site"
echo "             http://127.0.0.1:8788"
echo "Admin:       admin / admin"
echo "Project dir: $SITE_DIR"

if [ "$USE_S3" = "1" ]; then
	echo "MinIO API:   $S3_ENDPOINT_HOST"
	echo "MinIO UI:    http://127.0.0.1:9001  (strata / stratatest)"
	echo "Bucket:      $S3_BUCKET"
fi

cat << 'NEXT'

Drive it:
  ./startup.sh traffic --scale=medium      # a site somebody uses, sealed as it goes
  ./startup.sh measure                     # what that cost, and what a month would cost
  ./startup.sh rollback --to=HEAD~1        # the manifest a rollback would write
  ./startup.sh rollback --to=HEAD~1 --apply
  ./startup.sh meltdown --kind=everything  # break it on purpose
  ./startup.sh heal --apply                # rebuild what can be rebuilt
  ./startup.sh attack                      # probe the controls
  ./startup.sh scenario --scale=medium     # all of it, in order

By hand:
  cd /tmp/drupal-strata
  ddev drush strata:status
  ddev drush strata:verify
  ddev drush strata:list
  ddev ssh                                 # a shell inside the web container
  ddev logs -f                             # follow the web server

Stop:
  cd /tmp/drupal-strata && ddev stop
  docker compose -f docker/compose.yml down          # leaves the bucket contents
  docker compose -f docker/compose.yml down -v       # wipes the bucket too
NEXT
