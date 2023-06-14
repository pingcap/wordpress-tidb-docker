#!/bin/sh

sudo docker compose up -d

sleep 5 # wait for wordpress to start

sudo docker compose exec wordpress sh -c "sh /var/www/html/apply-patch.sh"