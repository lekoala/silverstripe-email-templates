#!/bin/bash

docker compose -f .devcontainer/docker-compose.yml up -d
composer install

export SS_DATABASE_CLASS=MySQLDatabase
export SS_DATABASE_SERVER="localhost"
export SS_DATABASE_USERNAME="root"
export SS_DATABASE_PASSWORD="secret"
export SS_DATABASE_CHOOSE_NAME=true
