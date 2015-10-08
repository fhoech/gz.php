<?php

define('CHARSET', 'utf-8');

# Set the default filename here if not using index.html
# define('DEFAULT_FILENAME', 'index.html');

# Adjust this if gz.php is not stored in the root of your site.
define('BASE', dirname(__FILE__));

# Where to store cached files. Note that the request URI (without query string)
# is always appended.
# Option 1 (default): Store cached files in dedicated cache folder.
define('CACHE', BASE . '/gz-cache');
# Option 2: Store cached files next to original.
# define('CACHE', BASE);

# Some servers always return a file as text/html when
# the filename contains the string '.php.', in that case it is *not*
# a good idea to store a gz compressed version because it will be served as
# text/html if accessed directly (or indirectly via mod_rewrite) on such servers.
# This enables a work-around to not cache such files compressed.
define('PHP_IN_FILENAME_WORKAROUND', true);

define('EMBED_GRAPHICS_IN_CSS', false);
define('MINIFY', true);

# Debug mode.
# Send any PHP errors related to encoding, filesystem or minification as X-PHP-* header
define('DEBUG', false);

?>
