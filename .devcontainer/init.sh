#!/bin/bash

docker compose -f .devcontainer/docker-compose.yml up -d
composer install
