#!/bin/bash

docker compose build
docker compose up -d
composer install
