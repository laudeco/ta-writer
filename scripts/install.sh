#!/usr/bin/env sh
# Installs TA Writer: the PHP image, the dependencies, and the first author of the list.
#
# Interactive: the script asks for the author (defaults: git user.name and user.email).
# Non interactive: set AUTHOR_NAME and AUTHOR_EMAIL (AUTHOR_USERNAME is optional).
# Running it again never adds a second author: the author is added only while the list is empty.
set -eu

cd "$(dirname "$0")/.."

echo "==> Building the PHP image"
make build

echo "==> Installing the PHP dependencies"
make composer-install

default_name=$(git config user.name 2>/dev/null || true)
default_email=$(git config user.email 2>/dev/null || true)

name=${AUTHOR_NAME:-}
email=${AUTHOR_EMAIL:-}
username=${AUTHOR_USERNAME:-}

if [ -z "$name" ] && [ -z "$email" ] && [ -t 0 ]; then
    printf 'Name of the first author [%s]: ' "$default_name"
    read -r name
    printf 'Email [%s]: ' "$default_email"
    read -r email
    printf 'Login name, optional: '
    read -r username
    name=${name:-$default_name}
    email=${email:-$default_email}
fi

echo "==> Creating the first author"
if [ -z "$name" ] || [ -z "$email" ]; then
    echo "No author given (set AUTHOR_NAME and AUTHOR_EMAIL, or run the script in a terminal): add one later with"
    echo '    make console c="app:author:add \"Name\" name@example.com"'
else
    make console c="app:author:add \"$name\" \"$email\" --username=\"$username\" --if-empty"
fi

echo "==> Done. Start the application with: make up   (http://127.0.0.1:8080)"
