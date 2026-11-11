#!/usr/bin/env bash

set -ex

if [ -z "$1" ]; then
    echo "Please specify the Laravel version to install"
    exit 1
fi

echo "Installing Laravel version $1"

# This is not required for CI, but it allows to test the script locally
function cleanup {
    echo "Cleaning up"
    mv composer.origin.json composer.json
}

function install-specified-laravel-version {
    local laravel_version=$1
    cp composer.json composer.origin.json
    rm composer.lock || true
    rm -Rf vendor || true
    sed -i 's/\^11.0 || \^12.0/\^'$laravel_version'/g' composer.json
    # Composer refuses to install Laravel versions with published security advisories
    composer config policy.advisories.block false
    composer install --ignore-platform-req=php
}

trap cleanup EXIT

install-specified-laravel-version $1 