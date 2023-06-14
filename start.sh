#!/bin/sh

docker-compose up -d

sleep 5 # wait for wordpress to start

docker compose exec wordpress sh -c "sh /var/www/html/apply-patch.sh"