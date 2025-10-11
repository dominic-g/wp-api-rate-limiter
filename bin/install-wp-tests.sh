#!/bin/bash
# This script is from the WordPress Core project, adapted for plugin testing.
# It sets up a temporary WordPress installation for unit testing.

WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress/}

DB_NAME=$1
DB_USER=$2
DB_PASSWORD=$3
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}

echo "Setting up WordPress test environment..."

if [ ! -d "$WP_TESTS_DIR" ]; then
    echo "Cloning wordpress-tests-lib..."
    git clone --depth 1 -b master https://github.com/WordPress/wordpress-develop.git "$WP_TESTS_DIR"
else
    echo "wordpress-tests-lib already exists."
fi

# The wordpress-tests-lib needs a wp-config.php
echo "Creating wp-tests-config.php..."
cat > "$WP_TESTS_DIR/wp-tests-config.php" << EOF
<?php
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASSWORD' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'WP_TESTS_EMAIL', 'test@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_PATH', '$WP_CORE_DIR' );
define( 'WP_DEBUG', true );
define( 'ABSPATH', '$WP_TESTS_DIR/' );
\$table_prefix = 'wptests_';
require_once '$WP_TESTS_DIR/includes/functions.php';

function _set_latest_wordpress_version_constant() {
    if ( ! defined( 'WP_VERSION' ) ) {
        define( 'WP_VERSION', '6.6.1' ); // Or specify a version like '6.0.0'
    }
}
_set_latest_wordpress_version_constant();
require_once '$WP_TESTS_DIR/includes/bootstrap.php';
EOF

# Make the script executable
chmod +x bin/install-wp-tests.sh

# Now run the script. Example:
# From plugin root: ./bin/install-wp-tests.sh wordpress_test_db root '' localhost latest
# Adjust database name, user, password as per your local setup.
# This will download WordPress core into /tmp/wordpress/ and wordpress-tests-lib into /tmp/wordpress-tests-lib/


# ./bin/install-wp-tests.sh wordpress wordpress 'password' localhost latest