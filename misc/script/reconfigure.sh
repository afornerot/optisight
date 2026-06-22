#!/bin/bash
set -eo pipefail

# Se positionner sur la racine du projet
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
cd ${DIR}
cd ../..
DIR=$(pwd)

bin/console cache:clear
bin/console d:s:u --force
bin/console app:init

mkdir -p var/log
bin/console okvpn:cron --demand -vvv > var/log/cron.log &

exec $@