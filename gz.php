<?php
/**
 * gz.php - GZIP compression with mod_rewrite and PHP
 * 
 * --
 * Copyright (c) 2012 Florian Höch <florian.hoech@gmx.de>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 * --
 * 
 * @package     gz.php
 * @link        https://github.com/fhoech/gz.php
 * @author      Florian Höch <florian.hoech@gmx.de>
 * @copyright   2012 Florian Höch <florian.hoech@gmx.de>
 * @license     http://opensource.org/licenses/mit-license.php MIT License
 * @version     $Id$
 */

@include dirname(__FILE__) . '/gz.config.inc.php';
if (!defined('BASE')) define('BASE', dirname(__FILE__));
if (!defined('DEFAULT_FILENAME')) define('DEFAULT_FILENAME', 'index.html');
if (!defined('CACHE')) define('CACHE', BASE . '/gz-cache');
if (!defined('CHARSET')) define('CHARSET', 'utf-8');
if (!defined('PHP_IN_FILENAME_WORKAROUND')) define('PHP_IN_FILENAME_WORKAROUND', true);

function get_content_type($file) {
    // Determine Content-Type based on file extension
    $info = pathinfo($file);
    $content_types = array('css' => 'text/css',
                           'htm' => 'text/html',
                           'html' => 'text/html',
                           'gif' => 'image/gif',
                           'ico' => 'image/x-icon',
                           'jpg' => 'image/jpeg',
                           'jpeg' => 'image/jpeg',
                           'js' => 'application/javascript',
                           'json' => 'application/json',
                           'png' => 'image/png',
                           'svg' => 'image/svg+xml',
                           'txt' => 'text/plain',
                           'xml' => 'application/xml',
                           'xsl' => 'application/xslt+xml');
    if (empty($content_types[$info['extension']]))
        return NULL;
    return $content_types[$info['extension']];
}

function get_error_type($type) {
    switch ($type) {
        case E_WARNING:
            return 'Warning';
        case E_NOTICE:
            return 'Notice';
        default:
            return 'Error';
    }
}

function errordocument($status, $message) {
	header('Cache-Control: no-cache, must-revalidate, max-age=0');
	header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
    header('Pragma: no-cache');
	$errors = array(400 => 'Bad Request',
					401 => 'Unauthorized',
					403 => 'Forbidden',
					404 => 'Not Found',
					405 => 'Method Not Allowed',
					416 => 'Requested Range Not Satisfiable',
					500 => 'Internal Server Error',
					503 => 'Service Unavailable');
    header($_SERVER['SERVER_PROTOCOL'] . ' ' . $status . ' ' . $errors[$status]);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8" />
    </head>
    <body>
        <header><h1><?php echo $status . ' ' . $errors[$status]; ?></h1></header>
        <p><?php echo htmlspecialchars($message, ENT_COMPAT, 'UTF-8'); ?></p>
    </body>
</html>
    <?php
    die();
}

function get_redirect_envvar($name) {
    $prefix = 'REDIRECT_';
    foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, strlen($prefix)) == $prefix) {
            if (substr($key, -strlen($name)) == $name)
                return $value;
        }
    }
    if (array_key_exists($name, $_SERVER)) return $_SERVER[$name];
    return null;
}

function replace_with_base64_data_url($match) {
    global $file;
    // 1 = 'url("' or "url('"
    // 2 = '"' or "'"
    // 3 = URI
    // 4 = '")' or "')"
    $img = dirname($file) . '/' . $match[3];
    $content_type = get_content_type($match[3]);
    switch ($content_type) {
        case 'image/gif':
        case 'image/x-icon':
        case 'image/png':
        case 'image/svg+xml':
            break;
        default:
            unset($content_type);
    }
    if (!empty($content_type)) {
        $b64 = @base64_encode(@file_get_contents($img));
        // IE 8 has a 32KB limit for data URLs
        $use_b64 = !empty($b64) && strlen($b64) < 1024 * 32;
        if ($use_b64) {
            $match[1] = rtrim($match[1], '"\'') . '"';
            $match[4] = '"' . ltrim($match[4], '"\'');
        }
    }
    return $match[1] . ($use_b64
                        ? 'data:' . $content_type . ';base64,' . $b64
                        : $match[3]) . $match[4];
}

function send_php_error_header($last_error=null, $custom_message=null) {
    if (!defined('DEBUG') || !DEBUG) return;
    if ($last_error === null) $last_error = error_get_last();
    else if (is_a($last_error, Exception))
        $last_error = array('type' => E_ERROR,
                            'message' => get_class($last_error) . ': ' . $last_error -> getMessage(),
                            'line' => $last_error -> getLine(),
                            'file' => $last_error -> getFile());
    header('X-PHP-' . get_error_type($last_error['type']) . ': ' .
           $last_error['message'] . ($custom_message != null ? ' ' . $custom_message : '') . ' in ' . $last_error['file'] .
           ' on line ' . $last_error['line'], false);
}

function main() {
    global $file, $excludes;
    // Get (redirected) request URI
    $redirect_request_uri = get_redirect_envvar('REQUEST_URI');
    // Get file path by stripping query parameters from the request URI
    if (!empty($redirect_request_uri))
        $file_path = preg_replace('/\/?(?:\?.*)?$/', '', $redirect_request_uri);

    // If the path is empty, either use DEFAULT_FILENAME if defined, or exit
    if (empty($file_path)) {
        if (defined('DEFAULT_FILENAME')) $file_path = '/' . DEFAULT_FILENAME;
        else errordocument(403, 'No file path given.');
    }
    
    if (defined('REWRITEBASE')) $file_path = preg_replace('/^' . preg_quote(REWRITEBASE, '/') . '/', '/', $file_path);

    $file = BASE . $file_path;
    $outfile = CACHE . $file_path;

    // Handle timestamp versioning
    if (!file_exists($file)) $file = preg_replace('/^(.+?)\.\d+\.(js|css|png|jpg|gif)$/', '$1.$2', $file);

    if (!file_exists($file)) errordocument(404, 'The file "' . $file_path . '" does not exist.');
    
    // Determine Content-Type based on file extension
    $content_type = get_content_type($file);
    if ($content_type == NULL) errordocument(403, 'Unsupported file type: ' . $file);

    $mtime = filemtime($file);

    // Vary max-age and expiration headers based on content type
    switch ($content_type) {
        case 'image/gif':
        case 'image/jpeg':
        case 'image/png':
        case 'image/svg+xml':
            // Max-age for images: 31 days
            $maxage = 60 * 60 * 24 * 31;
            break;
        default:
            // Max-age for everything else: 7 days
            $maxage = 60 * 60 * 24 * 7;
    }

    // Send vary and cache control headers
    header('Vary: Accept-Encoding');
    header('Cache-Control: max-age=' . $maxage);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $maxage) . ' GMT');

    // If the user agent sent a IF_MODIFIED_SINCE header, check if the file
    // has been modified. If it hasn't, send '304 Not Modified' header & exit
    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) &&
        $mtime <= strtotime(preg_replace('/;.*$/', '', $_SERVER['HTTP_IF_MODIFIED_SINCE']))) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified', true, 304);
        exit;
    }

    // If the user agent accepts GZIP encoding, store a compressed version of
    // the file (<filename>.gz)
    $gz = (!empty($_SERVER['HTTP_ACCEPT_ENCODING']) &&
           in_array('gzip', preg_split('/\s*,\s*/',
                                       $_SERVER['HTTP_ACCEPT_ENCODING'])));

    // Only write the compressed version if it does not yet exist or the
    // original file has changed
    // Special case: Some servers always return a file as text/html when
    // the filename contains the string '.php.', in that case we do *not*
    // store a gz compressed version because it will be served as text/html
    // if accessed directly (or indirectly via mod_rewrite) on such servers
    $php_in_filename_workaround = (strpos($file, '.php.') !== false &&
                                   defined('PHP_IN_FILENAME_WORKAROUND') &&
                                   PHP_IN_FILENAME_WORKAROUND);
    $outfile .= ($gz && !$php_in_filename_workaround ? '.gz' : '.min');
    if (!file_exists($outfile) || filemtime($outfile) < $mtime) {
        $buffer = file_get_contents($file);
        if (preg_match_all('/<!--#include file="([^"]+)" -->/',
                           $buffer, $matches, PREG_SET_ORDER)) {
            // Includes
            $path = dirname($file);
            foreach ($matches as $set) {
                $include = $set[1];
                if (realpath($set[1]) != $set[1])
                    $include = $path . '/' . $set[1];
                if (file_exists($include)) {
                    $tmp = file_get_contents($include);
                    if ($content_type == 'text/css') {
                        // Make sure url() paths are correct for CSS files 
                        // included from subfolders
                        $include_path = dirname($set[1]);
                        // Protect URLs beginning with '/', absolute URLs and
                        // data URLs
                        $tmp = preg_replace('/(\burl\(\s*[\'"]?)(data:|http:|https:|\/)/',
                                            "$1\0$2", $tmp);
                        $tmp = preg_replace('/(\burl\(\s*([\'"]?))([^\0]+?)(\2\))/',
                                            '$1' . $include_path . '/$3$4',
                                            $tmp);
                        $tmp = preg_replace('/(\burl\(\s*[\'"]?)\0/', "$1",
                                            $tmp);
                    }
                    if ($content_type == 'application/javascript')
                        $tmp .= ';';
                    $buffer = str_replace($set[0], $tmp, $buffer);
                }
            }
        }
        // Minify CSS and JS if the filename does not contain 'min.<ext>'
        if (defined('MINIFY') && MINIFY &&
            (empty($excludes) || !in_array($file_path, $excludes))) switch ($content_type) {
            case 'text/css':
                if (strpos($file, 'min.css') === false) {
                    require_once('cssmin.php');
                    $filters = array('ImportImports'                 => false,
                                     'RemoveComments'                => true, 
                                     'RemoveEmptyRulesets'           => true,
                                     'RemoveEmptyAtBlocks'           => true,
                                     'ConvertLevel3AtKeyframes'      => false,
                                     'ConvertLevel3Properties'       => false,
                                     'Variables'                     => true,
                                     'RemoveLastDelarationSemiColon' => true);
                    $plugins = array('Variables'                     => true,
                                     'ConvertFontWeight'             => false,
                                     'ConvertHslColors'              => false,
                                     'ConvertRgbColors'              => false,
                                     'ConvertNamedColors'            => false,
                                     'CompressColorValues'           => true,
                                     'CompressUnitValues'            => false,
                                     'CompressExpressionValues'      => false);
                    try {
                        $buffer = CssMin::minify($buffer, $filters, $plugins);
                    }
                    catch (Exception $exception) {
                        send_php_error_header($exception);
                    }
                    file_put_contents(__DIR__ . '/.gz.php.log', $file_path . "\n", FILE_APPEND);
                }
                if (defined('EMBED_GRAPHICS_IN_CSS') && EMBED_GRAPHICS_IN_CSS) {
                    // Protect URLs beginning with '/', absolute URLs and
                    // data URLs
                    $buffer = preg_replace('/(\burl\(\s*[\'"]?)(data:|http:|https:|\/)/',
                                          "$1\0$2", $buffer);
                    $buffer = preg_replace_callback('/(\burl\(\s*([\'"]?))([^\0]+?)(\2\))/',
                                                    'replace_with_base64_data_url',
                                                    $buffer);
                    $buffer = preg_replace('/(\burl\(\s*[\'"]?)\0/', "$1",
                                           $buffer);
                }
                break;
            case 'application/javascript':
                if (strpos($file, 'min.js') === false) {
                    // Protect conditional compilation comments
                    $buffer = preg_replace('~/\*@cc_on([\S\s]*?)@\*/~',
                                           ";'@cc_on@';$1;'@cc_off@';", $buffer);
                    require_once('jsmin.php');
                    try {
                        $buffer = JSMin::minify($buffer);
                    }
                    catch (Exception $exception) {
                        send_php_error_header($exception);
                    }
                    $buffer = str_replace(";'@cc_on@';", "/*@cc_on\n", $buffer);
                    $buffer = str_replace(";'@cc_off@';", "\n@*/", $buffer);
                    file_put_contents(__DIR__ . '/.gz.php.log', $file_path . "\n", FILE_APPEND);
                }
                break;
        }
        if ($gz && !$php_in_filename_workaround) {
            $outbuffer = gzencode($buffer);
            if ($outbuffer === false) send_php_error_header();
            else $buffer = &$outbuffer;
        }
        $outdir = dirname($outfile);
        if (!is_dir($outdir) && !@mkdir($outdir, 0755, true)) send_php_error_header(null, $outdir);
        else if (@file_put_contents($outfile, $buffer) === false) send_php_error_header();
        else if (!@chmod($outfile, 0644) || !@touch($outfile, $mtime)) send_php_error_header();
    }
    else $buffer = NULL;

    // Send compression headers
    if ($gz && !$php_in_filename_workaround) header('Content-Encoding: gzip');

    // Send remaining headers
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    if (in_array($content_type, array('application/javascript',
                                      'application/json',
                                      'application/xml',
                                      'image/svg+xml',
                                      'text/css',
                                      'text/html',
                                      'text/plain')))
        $content_type .= '; charset=' . CHARSET;
    header('Content-Type: ' . $content_type);
    if ($buffer !== NULL) {
        header('Content-Length: ' . strlen($buffer));
    }
    else {
        header('Content-Length: ' . filesize($outfile));
        header('X-Last-Modified: ' . gmdate('D, d M Y H:i:s',
               filemtime($outfile)) . ' GMT');
    }
    header('X-Original-Filename: ' . substr($file, strlen(BASE)));
    header('X-Filename: ' . substr($outfile, strlen(BASE)));

    // If the request method isn't HEAD, send the file contents
    if ($_SERVER['REQUEST_METHOD'] != 'HEAD') {
        if ($buffer !== NULL) echo $buffer;
        else readfile($outfile);
    }
}

main();

?>