#!/bin/sh

# Wait for wordpress to be ready
sleep 5

# Apply patch
cat /var/www/html/patch.php >> /var/www/html/wp-content/themes/twentytwentyone/functions.php
cat /var/www/html/patch.php >> /var/www/html/wp-content/themes/twentytwentytwo/functions.php