#!/usr/bin/env bash
set -euo pipefail

db="${1:-postgres}"
[ "$#" -gt 0 ] && shift

case "$db" in
	mariadb | postgres) ;;
	*)
		echo "usage: lane.sh [mariadb|postgres] [phpunit args]" >&2
		exit 2
		;;
esac

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose=(docker compose -f "$here/compose.yml" --profile "$db")

stop() {
	"${compose[@]}" stop > /dev/null 2>&1 || true
}
trap stop EXIT

"${compose[@]}" run --rm "drupal-$db" test "$@"
