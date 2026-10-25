#!/bin/bash
# Install the apache_phpfpm stack: Apache (event MPM) alone on :80/:443, no
# mod_php, every site's PHP in its own php-fpm pool. Same installer as
# apache_modphp (install-apache-modphp.sh), in its php-fpm mode.
APACHE_TEMPLATE=apache_phpfpm exec "$(dirname "$(readlink -f "$0")")/install-apache-modphp.sh" "$@"
