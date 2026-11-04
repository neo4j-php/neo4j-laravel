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
    local major="${laravel_version%%.*}"

    cp composer.json composer.origin.json
    rm composer.lock || true
    rm -Rf vendor || true

    # Pin illuminate/* to the requested major (supports ^11.0 || ^12.0 || ^13.0).
    sed -i -E 's/"illuminate\/(database|support)": "[^"]+"/"illuminate\/\1": "^'"${laravel_version}"'"/g' composer.json

    case "${major}" in
        11)
            # Laravel 11 is EOL; Composer 2.9+ blocks every 11.x release due to
            # unpatched security advisories. Write both legacy and current keys
            # into composer.json so CI works across Composer versions.
            php -r '
                $json = json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR);
                $json["config"]["audit"]["block-insecure"] = false;
                $json["config"]["policy"]["advisories"]["block"] = false;
                file_put_contents("composer.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            '
            sed -i -E 's/"orchestra\/testbench": "[^"]+"/"orchestra\/testbench": "^9.0"/' composer.json
            sed -i -E 's/"phpunit\/phpunit": "[^"]+"/"phpunit\/phpunit": "^10.5"/' composer.json
            ;;
        12)
            sed -i -E 's/"orchestra\/testbench": "[^"]+"/"orchestra\/testbench": "^10.0"/' composer.json
            sed -i -E 's/"phpunit\/phpunit": "[^"]+"/"phpunit\/phpunit": "^11.5"/' composer.json
            ;;
        13)
            sed -i -E 's/"orchestra\/testbench": "[^"]+"/"orchestra\/testbench": "^11.0"/' composer.json
            sed -i -E 's/"phpunit\/phpunit": "[^"]+"/"phpunit\/phpunit": "^11.5"/' composer.json
            ;;
        *)
            echo "Unsupported Laravel major version: ${major}"
            exit 1
            ;;
    esac

    # CI jobs only run PHPUnit. Psalm's Laravel plugin is not multi-major yet and
    # blocks illuminate ^12/^13 resolution, so drop it for the test install.
    composer remove --dev vimeo/psalm psalm/plugin-laravel --no-update --no-interaction

    composer update --ignore-platform-req=php --with-all-dependencies
}

trap cleanup EXIT

install-specified-laravel-version "$1"
