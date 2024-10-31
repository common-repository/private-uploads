<?php
namespace private_uploads_cd;
define(__NAMESPACE__ . '\NS', __NAMESPACE__ . '\\'); // (need to escape \ before ')
/*
Plugin Name: Private Uploads
Plugin URI:  https://wordpress.org/plugins/private-uploads/
Description: Prevents non-logged-in users from accessing files in a private uploads folder
Version:     0.1.2
Author:      Chris Dennis
Author URI:  https://profiles.wordpress.org/chrisdennis#content-plugins
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

defined('ABSPATH') or die('Invalid invocation');

// Debug to /wp-content/debug.log (see https://codex.wordpress.org/WP_DEBUG
// and settings in wp-config.php)
if (!function_exists(NS . 'debug')) {       

    function debug (...$args) {
        if (!WP_DEBUG) {
            return;
        }
        $text = '';
        foreach ($args as $arg) {
            $text .= ' ' . print_r($arg, true);
        }
        $trace = debug_backtrace(false);
        $file = $trace[0]['file'];
        $p = strrpos($file, '/wp-content/');
        if ($p !== False) {
            $file = substr($file, $p + 12);
        }
        $line = $trace[0]['line'];
        $func = $trace[1]['function'];
        if ($func == 'include'      or
            $func == 'include_once' or
            $func == 'require'      or
            $func == 'require_once'    ) {
            $func = '';
        } else {
            $func = '(' . $func . ')'; 
        }
        error_log($file . $func . ':' . $line . $text);
    }

}

#debug('==== This is private_uploads.  Request: ', $_REQUEST);

// Hook into the init action to look for our HTTP arguments
add_action('init', NS . 'init');
function init () {
    $folder = request_value('pucd-folder');
    $file = request_value('pucd-file');
    #debug("file='$file' folder='$folder'");
    if ($file && $folder) {
        send_private_file($folder, $file);
    }
}

function request_value ($key) {
    return isset($_REQUEST[$key]) ? trim($_REQUEST[$key]) : '';
}   

// Sanitize each part of a path name 
function sanitize_dir_name ($dir) {
    #debug('checking', $dir);
    $filenames = explode('/', $dir);
    $newfilenames = [];
    foreach ($filenames as $fn) {
        $newfilenames[] = sanitize_file_name($fn);
    }
    #debug("filenames ", $filenames, ' becomes ', $newfilenames);
    return implode('/', $newfilenames);
}

function send_private_file ($folder, $file) {

    // Only return files to logged-in users
    if (!is_user_logged_in()) {
        #debug('not logged in -- 403');
        status_header('403');
        die();
    }

    // Check the inputs: both $folder and $file are either simple
    // filenames such as 'abc.jpg' or paths such as 'foo/bar/abc.jpg'
    // And strip any leading and trailing separators.
    $folder = trim(sanitize_dir_name($folder), '/');
    $file   = trim(sanitize_dir_name($file), '/');
    #debug('sanitized: ', $folder, $file);
        
    $upload = wp_upload_dir();
    // Gets (e.g.): 
    // [path]    => /var/www/wp/wp-content/uploads/2017/02  < includes subdir >
    // [url]     => http://example.com/wp-content/uploads/2017/02
    // [subdir]  => 2017/02                                 < set if using year/month directories >
    // [basedir] => /var/www/wp/wp-content/uploads          < excludes subdir >
    // [baseurl] => http://example.com/wp-content/uploads
    // [error]   =>
    #$upload = array('error' => 'test error');
    if ($upload['error']) {
        status_header(500, 'WP Upload directory error: ' . $upload['error']);
        die();
    }

    $path = $upload['basedir'] . '/' . $folder . '/' . $file;
    #debug('path = ', $path);
    if (!is_file($path)) {
        #debug('returning 404');
        status_header(404, 'File not found');
        die();
    } 

    // Add the mimetype header
    $mime = wp_check_filetype($file);  // it just looks at the extension
    $mimetype = $mime['type'];
    if (!$mimetype && function_exists('mime_content_type')) {
        #debug('looking inside the file');
        $mimetype = mime_content_type($path);  // Look inside it
    }
    if (!$mimetype) {
        $mimetype = 'application/octet-stream';
    }
    #debug('spf: mimetype ', $mimetype);
    header('Content-type: ' . $mimetype); // always send this

    // Add timing headers
    $date_format = 'D, d M Y H:i:s T';  // RFC2616 date format for HTTP
    $last_modified_unix = filemtime($path);
    $last_modified = gmdate($date_format, filemtime($path));
    $etag = md5($last_modified);
    header("Last-Modified: $last_modified");
    header('ETag: "' . $etag . '"');
    header('Expires: ' . gmdate($date_format, time() + 3600)); // an arbitrary hour from now

    // Support for cacheing
    $client_etag          = request_value('HTTP_IF_NONE_MATCH');
    $client_if_mod_since  = request_value('HTTP_IF_MODIFIED_SINCE');
    $client_if_mod_since_unix = strtotime($client_if_mod_since);
    #debug("et=$etag   cet=$client_etag");
    #debug("lm=$last_modified   cims=$client_if_mod_since");
    #debug("lmu=$last_modified_unix  cimsu=$client_if_mod_since_unix");
    if ($etag == $client_etag                            ||
        $last_modified_unix <= $client_if_mod_since_unix   ) {
        // Return 'not modified'
        #debug('returning 304');
        status_header(304);
        die();
    }

    // If we made it this far, just serve the file
    #debug('spf readfile ', $path);
    status_header(200);
    readfile($path);
	die();

} // end of send_private_file

?>
