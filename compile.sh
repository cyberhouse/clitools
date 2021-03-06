#!/bin/bash

set -o pipefail  # trace ERR through pipes
set -o errtrace  # trace ERR through 'time command' and other functions
set -o nounset   ## set -u : exit the script if you try to use an uninitialised variable
set -o errexit   ## set -e : exit the script if any statement returns a non-true return value

SCRIPT_DIR=$(dirname $(readlink -f "$0"))

OLD_PWD=`pwd`

## copy configs
cp "$SCRIPT_DIR/Documentation/Examples/clisync.yml"  "$SCRIPT_DIR/src/conf/"

## run composer
cd "$SCRIPT_DIR/src"
composer install --no-dev
composer dump-autoload --optimize --no-dev

## create phar
cd "$SCRIPT_DIR/"
box.phar build -c box.json

cd "$OLD_PWD"
