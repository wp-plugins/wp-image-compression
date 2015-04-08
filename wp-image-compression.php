<?php
//error_reporting(0);
/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * Dashboard. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              http://webplugins.co.uk
 * @since             1.0.0
 * @package           Wp_Image_Compression
 *
 * @wordpress-plugin
 * Plugin Name:       Wp image compression
 * Plugin URI:        http://pigeonhut.com
 * Description:       This is a short description of what the plugin does. It's displayed in the WordPress dashboard.
 * Version:           1.0.2
 * Author:            Jody Nesbitt (WebPlugins)
 * Author URI:        http://webplugins.co.uk
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wp-image-compression
 * Domain Path:       /languages
 */
define('WPIMAGE_VERSION', '2.3.2');
define('WPIMAGE_SCHEMA_VERSION', '1.1');

define('WPIMAGE_DEFAULT_MAX_WIDTH', 1024);
define('WPIMAGE_DEFAULT_MAX_HEIGHT', 1024);
define('WPIMAGE_DEFAULT_BMP_TO_JPG', 1);
define('WPIMAGE_DEFAULT_PNG_TO_JPG', 0);
define('WPIMAGE_DEFAULT_QUALITY', 90);

define('WPIMAGE_SOURCE_POST', 1);
define('WPIMAGE_SOURCE_LIBRARY', 2);
define('WPIMAGE_SOURCE_OTHER', 4);

define('WPIMAGE_AJAX_MAX_RECORDS', 250);
/**
 * Load Translations
 */
load_plugin_textdomain('wp-image-compression', false, 'wp-image-compression/languages/');

/**
 * import supporting libraries
 */
include_once(plugin_dir_path(__FILE__) . 'libs/utils.php');
include_once(plugin_dir_path(__FILE__) . 'settings.php');
include_once(plugin_dir_path(__FILE__) . 'ajax.php');
if (!class_exists('NMRichReviewsAdminHelper')) {
    require_once(plugin_dir_path(__FILE__) . 'lib/admin-view-helper-functions.php');
}

/**
 * Fired with the WordPress upload dialog is displayed
 */

/**
 * Inspects the request and determines where the upload came from
 * @return WPIMAGE_SOURCE_POST | WPIMAGE_SOURCE_LIBRARY | WPIMAGE_SOURCE_OTHER
 */
function wpimages_get_source() {

    $id = array_key_exists('post_id', $_REQUEST) ? $_REQUEST['post_id'] : '';
    $action = array_key_exists('action', $_REQUEST) ? $_REQUEST['action'] : '';

    // a post_id indicates image is attached to a post
    if ($id > 0)
        return WPIMAGE_SOURCE_POST;

    // post_id of 0 is 3.x otherwise use the action parameter
    if ($id === 0 || $action == 'upload-attachment')
        return WPIMAGE_SOURCE_LIBRARY;

    // we don't know where this one came from but $_REQUEST['_wp_http_referer'] may contain info
    return WPIMAGE_SOURCE_OTHER;
}

/**
 * Given the source, returns the max width/height
 *
 * @example:  list($w,$h) = wpimages_get_max_width_height(WPIMAGE_SOURCE_LIBRARY);
 * @param int WPIMAGE_SOURCE_POST | WPIMAGE_SOURCE_LIBRARY | WPIMAGE_SOURCE_OTHER
 */
function wpimages_get_max_width_height($source) {
    $w = wpimages_get_option('wpimages_max_width', WPIMAGE_DEFAULT_MAX_WIDTH);
    $h = wpimages_get_option('wpimages_max_height', WPIMAGE_DEFAULT_MAX_HEIGHT);

    switch ($source) {
        case WPIMAGE_SOURCE_POST:
            break;
        case WPIMAGE_SOURCE_LIBRARY:
            $w = wpimages_get_option('wpimages_max_width_library', $w);
            $h = wpimages_get_option('wpimages_max_height_library', $h);
            break;
        default:
            $w = wpimages_get_option('wpimages_max_width_other', $w);
            $h = wpimages_get_option('wpimages_max_height_other', $h);
            break;
    }

    return array($w, $h);
}

/**
 * Handler after a file has been uploaded.  If the file is an image, check the size
 * to see if it is too big and, if so, resize and overwrite the original
 * @param Array $params
 */
function wpimages_handle_upload($params) {
    /* debug logging... */
    // file_put_contents ( "debug.txt" , print_r($params,1) . "\n" );
    // if "noresize" is included in the filename then we will bypass wpimage scaling
    if (strpos($params['file'], 'noresize') !== false)
        return $params;

    // if preferences specify so then we can convert an original bmp or png file into jpg
    if ($params['type'] == 'image/bmp' && wpimages_get_option('wpimages_bmp_to_jpg', WPIMAGE_DEFAULT_BMP_TO_JPG)) {
        $params = wpimages_convert_to_jpg('bmp', $params);
    }

    if ($params['type'] == 'image/png' && wpimages_get_option('wpimages_png_to_jpg', WPIMAGE_DEFAULT_PNG_TO_JPG)) {
        $params = wpimages_convert_to_jpg('png', $params);
    }

    // make sure this is a type of image that we want to convert and that it exists
    // @TODO when uploads occur via RPC the image may not exist at this location
    $oldPath = $params['file'];

    if ((!is_wp_error($params)) && file_exists($oldPath) && in_array($params['type'], array('image/png', 'image/gif', 'image/jpeg'))) {

        // figure out where the upload is coming from
        $source = wpimages_get_source();

        list($maxW, $maxH) = wpimages_get_max_width_height($source);

        list($oldW, $oldH) = getimagesize($oldPath);

        /* HACK: if getimagesize returns an incorrect value (sometimes due to bad EXIF data..?)
          $img = imagecreatefromjpeg ($oldPath);
          $oldW = imagesx ($img);
          $oldH = imagesy ($img);
          imagedestroy ($img);
          // */

        /* HACK: an animated gif may have different frame sizes.  to get the "screen" size
          $data = ''; // TODO: convert file to binary
          $header = unpack('@6/vwidth/vheight', $data );
          $oldW = $header['width'];
          $oldH = $header['width'];
          // */

        if (($oldW > $maxW && $maxW > 0) || ($oldH > $maxH && $maxH > 0)) {
            $quality = wpimages_get_option('wpimages_quality', WPIMAGE_DEFAULT_QUALITY);

            list($newW, $newH) = wp_constrain_dimensions($oldW, $oldH, $maxW, $maxH);

            // this is wordpress prior to 3.5 (image_resize deprecated as of 3.5)
            $resizeResult = wpimages_image_resize($oldPath, $newW, $newH, false, null, null, $quality);

            /* uncomment to debug error handling code: */
            // $resizeResult = new WP_Error('invalid_image', __(print_r($_REQUEST)), $oldPath);
            // regardless of success/fail we're going to remove the original upload
            unlink($oldPath);

            if (!is_wp_error($resizeResult)) {
                $newPath = $resizeResult;

                // remove original and replace with re-sized image
                rename($newPath, $oldPath);
            } else {
                // resize didn't work, likely because the image processing libraries are missing
                $params = wp_handle_upload_error($oldPath, sprintf(__("Oh Snap! Wp image resizer was unable to resize this image "
                                        . "for the following reason: '%s'
					.  If you continue to see this error message, you may need to either install missing server"
                                        . " components or disable the Wp image resizer plugin."
                                        . "  If you think you have discovered a bug, please report it on the Wp image resizer support forum.", 'wpimage'), $resizeResult->get_error_message()));
            }
        }
    }

    return $params;
}

/**
 * read in the image file from the params and then save as a new jpg file.
 * if successful, remove the original image and alter the return
 * parameters to return the new jpg instead of the original
 *
 * @param string 'bmp' or 'png'
 * @param array $params
 * @return array altered params
 */
function wpimages_convert_to_jpg($type, $params) {

    $img = null;

    if ($type == 'bmp') {
        include_once('libs/imagecreatefrombmp.php');
        $img = imagecreatefrombmp($params['file']);
    } elseif ($type == 'png') {

        if (!function_exists('imagecreatefrompng')) {
            return wp_handle_upload_error($params['file'], 'wpimages_convert_to_jpg requires gd library enabled');
        }

        $img = imagecreatefrompng($params['file']);
    } else {
        return wp_handle_upload_error($params['file'], 'Unknown image type specified in wpimages_convert_to_jpg');
    }

    // we need to change the extension from the original to .jpg so we have to ensure it will be a unique filename
    $uploads = wp_upload_dir();
    $oldFileName = basename($params['file']);
    $newFileName = basename(str_ireplace("." . $type, ".jpg", $oldFileName));
    $newFileName = wp_unique_filename($uploads['path'], $newFileName);

    $quality = wpimages_get_option('wpimages_quality', WPIMAGE_DEFAULT_QUALITY);

    if (imagejpeg($img, $uploads['path'] . '/' . $newFileName, $quality)) {
        // conversion succeeded.  remove the original bmp & remap the params
        unlink($params['file']);

        $params['file'] = $uploads['path'] . '/' . $newFileName;
        $params['url'] = $uploads['url'] . '/' . $newFileName;
        $params['type'] = 'image/jpeg';
    } else {
        unlink($params['file']);

        return wp_handle_upload_error($oldPath, __("Oh Snap! Wp image resizer was Unable to process the $type file.  "
                        . "If you continue to see this error you may need to disable the $type-To-JPG "
                        . "feature in Wp image convertor settings.", 'wpimage'));
    }

    return $params;
}

/* add filters to hook into uploads */
add_filter('wp_handle_upload', 'wpimages_handle_upload');

/* add filters/actions to customize upload page */


// TODO: if necessary to update the post data in the future...
// add_filter( 'wp_update_attachment_metadata', 'wpimages_handle_update_attachment_metadata' );


if (!class_exists('Wp_Image_compression')) {

    class Wp_Image_compression {

        private $id;
        private $compression_settings = array();
        private $thumbs_data = array();
        private $optimization_type = 'lossy';

        function __construct() {
            $plugin_dir_path = dirname(__FILE__);
            require_once( $plugin_dir_path . '/lib/wp-image-compression.php' );
            $this->compression_settings = unserialize(get_option('_wpimage_options'));
            $this->optimization_type = $this->compression_settings['api_lossy'];
            add_action('admin_menu', array(&$this, 'admin_init'));
            add_action('admin_enqueue_scripts', array(&$this, 'my_enqueue'));
            add_action('wp_ajax_wpimage_request', array(&$this, 'wpimage_media_library_ajax_callback'));
            add_action('manage_media_custom_column', array(&$this, 'fill_media_columns'), 10, 2);
            add_filter('manage_media_columns', array(&$this, 'add_media_columns'));
            add_filter('wp_generate_attachment_metadata', array(&$this, 'optimize_thumbnails'));
            add_action('add_attachment', array(&$this, 'wpimage_media_uploader_callback'));
            add_action('admin_post_submit-compression-form', array(&$this, 'saveCompressionSettings'));
        }

        /*
         *  Adds wpimage fields and settings to Settings->Media settings page
         */

        function admin_init() {
            add_menu_page(__('Compression', 'wpc'), __('Compression', 'wpc'), 'manage_options', 'wp-convertor', array(&$this, 'wpimages_settings_page_form'), '');
            //add_submenu_page('', __('Convertor', 'wpc'), __('Convertor', 'wpc'), 'manage_options', 'wp-convertor', array(&$this, 'wpimages_settings_page_form'));
            //       add_settings_section('wp_image_optimizer', 'Wp Image Optimizer', array(&$this, 'show_wp_image_optimizer'), 'media');
//
//            register_setting(
//                    'media', '_wpimage_options', array(&$this, 'validate_options')
//            );
//
//            add_settings_field(
//                    'wpicompressor_api_key', 'API Key:', array(&$this, 'show_api_key'), 'media', 'wp_image_optimizer'
//            );
//
//            add_settings_field(
//                    'wpicompressor_api_secret', 'API Secret:', array(&$this, 'show_api_secret'), 'media', 'wp_image_optimizer');
//
//            add_settings_field(
//                    'wpicompressor_lossy', 'Optimization Type:', array(&$this, 'show_lossy'), 'media', 'wp_image_optimizer'
//            );
//
//            add_settings_field(
//                    'credentials_valid', 'API status:', array(&$this, 'show_credentials_validity'), 'media', 'wp_image_optimizer'
//            );
        }

        function compressionTabs() {
            $pluginDirectory = trailingslashit(plugins_url(basename(dirname(__FILE__))));
            wp_register_style('compression-css', $pluginDirectory . 'css/compression.css');
            wp_enqueue_style('compression-css');
            $my_plugin_tabs = array(
                'wp-convertor' => 'Image Dimension',                
            );
            echo $this->admin_tabs_callmeback($my_plugin_tabs);
        }

        function callCustomCompression() {
            session_start();
            global $wpdb;
            $get_option_details = unserialize(get_option('_wpimage_options'));                        
            $verify_checked = $get_option_details['api_lossy'];
            if ($verify_checked == 'lossy') {
                $lossyCheked = 'checked="checked"';
            } else {
                $lossyCheked = '';
            }
            if ($verify_checked == 'lossless') {
                $losslessChecked = 'checked="checked"';
            } else {
                $losslessChecked = '';
            }
            if($get_option_details['api_key']!='' && $get_option_details['api_secret']){
                $status = $this->get_api_status($get_option_details['api_key'], $get_option_details['api_secret']);
            }
            $out= '
                <form id="compres" method="post" action="'.get_admin_url().'admin-post.php">  
                    <fieldset>                            
                        <input type=\'hidden\' name=\'action\' value=\'submit-compression-form\' />                       
            <div>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">API Key:</th>
                            <td>            
                                <input type="text" size="22" value="' . $get_option_details['api_key'] . '" name="_wpimage_options[api_key]" id="wpicompressor_api_key">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">API Secret:</th>
                            <td>           
                                <input type="text" size="22" value="' . $get_option_details['api_secret'] . '" name="_wpimage_options[api_secret]" id="wpicompressor_api_secret">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Optimization Type:</th>
                            <td>                                                                                                                                    
                                <input type="radio" ' . $lossyCheked . ' value="lossy" name="_wpimage_options[api_lossy]" id="wpicompressor_lossy">
                                <label for="wpicompressor_lossy">Lossy</label>
                                <input type="radio" ' . $losslessChecked . ' value="lossless" name="_wpimage_options[api_lossy]" id="wpimage_lossless" style="margin-left:10px;">
                                <label for="wpimage_lossless">Lossless</label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">API status:</th>
                            <td>';
            $url = admin_url() . 'images/';
            if ($_SESSION['status'] == 'updated') {
                $url .= 'yes.png';
                $out.= '<p class = "apiStatus">Your credentials are valid <span class = "apiValid" style = "background:url(' . "'$url') no-repeat scroll 0 3px;
            width:16px;
            height:16px;
            float:left;
            " . '"></span></p>';
            } else {
                $url .= 'no.png';
                $out.= '<p class = "apiStatus">There is a problem with your credentials <span class = "apiInvalid" style = "background:url(' . "'$url') no-repeat scroll 0 0px;
            width:16px;
            height:16px;
            float:left;
            " . '"></span></p>';
            }
            
            $out.='</td>
            </tr>
            </tbody>
            </table>
            </div>                         
            <input class="button-primary" type="submit" value="Submit" name="submit" /> <input class="button-primary" type="button" value="Back" onclick="history.go(-1);" name="back" />
            <!--<span><i>(On submitting this form, it will update the customer <b>subscription date</b> and process a <b>recurring payment</b>, please be sure before submitting)</i></span>-->
            </fieldset>
            </form>                        
           ';
           return $out;
            
        }

        function saveCompressionSettings() {
            session_start();
            global $wpdb;
            if (isset($_POST['submit'])) {
                //$settings = $this->compression_settings;
                $api_key = isset($_POST['_wpimage_options']['api_key']) ? $_POST['_wpimage_options']['api_key'] : '';
                $api_secret = isset($_POST['_wpimage_options']['api_secret']) ? $_POST['_wpimage_options']['api_secret'] : '';

                $status = $this->get_api_status($api_key, $api_secret);

                if ($status !== false && isset($status['active'])) {
                    $value = serialize($_POST['_wpimage_options']);
                    update_option('_wpimage_options', $value);
                    $_SESSION['status'] = 'updated';
                } else {
                    $_POST['_wpimage_options']['api_key'] = '';
                    $_POST['_wpimage_options']['api_secret'] = '';
                    $value = serialize($_POST['_wpimage_options']);
                    update_option('_wpimage_options', $value);
                    $_SESSION['status'] = 'failed';
                }
                wp_redirect(admin_url('admin.php?page = wp-convertor'));
            }
        }

        function wpimages_settings_page_form() {
            $this->compressionTabs();
            ?>
            <script>
                jQuery(document).ready(function() {
                    jQuery("body").addClass("wps-admin-page");
                    // binds form submission and fields to the validation engine          
                    jQuery(".wps-postbox-container .handlediv, .wps-postbox-container .hndle").on("click", function(n) {
                        return n.preventDefault(), jQuery(this).parent().toggleClass("closed");
                    });
                });
            </script>
            <div class="wrap">
                <div id="poststuff" class="metabox-holder ppw-settings">                                 
                    <div class="left-side">
                        <?php
                        NMRichReviewsAdminHelper::render_container_open('content-container');
                        NMRichReviewsAdminHelper::render_postbox_open('Image convertor settings');
                        ?>
                        <form method="post" action="options.php">

                            <?php settings_fields('wpimage-settings-group'); ?>
                            <table class="form-table">

                                <tr valign="middle">
                                    <th scope="row"><?php _e("Images uploaded within a Page/Post", 'wpimage'); ?></th>
                                    <td>Fit within <input type="text" style="width: 50px;" name="wpimages_max_width" value="<?php echo get_option('wpimages_max_width', WPIMAGE_DEFAULT_MAX_WIDTH); ?>" />
                                        x <input type="text" style="width: 50px;" name="wpimages_max_height" value="<?php echo get_option('wpimages_max_height', WPIMAGE_DEFAULT_MAX_HEIGHT); ?>" /> pixels width/height <?php _e(" (or enter 0 to disable)", 'wpimage'); ?>
                                    </td>
                                </tr>

                                <tr valign="middle">
                                    <th scope="row"><?php _e("Images uploaded directly to the Media Library", 'wpimage'); ?></th>
                                    <td>Fit within <input type="text" style="width: 50px;" name="wpimages_max_width_library" value="<?php echo get_option('wpimages_max_width_library', WPIMAGE_DEFAULT_MAX_WIDTH); ?>" />
                                        x <input type="text" style="width: 50px;" name="wpimages_max_height_library" value="<?php echo get_option('wpimages_max_height_library', WPIMAGE_DEFAULT_MAX_HEIGHT); ?>" /> pixels width/height <?php _e(" (or enter 0 to disable)", 'wpimage'); ?>
                                    </td>
                                </tr>

                                <tr valign="middle">
                                    <th scope="row"><?php _e("Images uploaded elsewhere (Theme headers, backgrounds, logos, etc)", 'wpimage'); ?></th>
                                    <td>Fit within <input type="text" style="width: 50px;" name="wpimages_max_width_other" value="<?php echo get_option('wpimages_max_width_other', WPIMAGE_DEFAULT_MAX_WIDTH); ?>" />
                                        x <input type="text" style="width: 50px;" name="wpimages_max_height_other" value="<?php echo get_option('wpimages_max_height_other', WPIMAGE_DEFAULT_MAX_HEIGHT); ?>" /> pixels width/height <?php _e(" (or enter 0 to disable)", 'wpimage'); ?>
                                    </td>
                                </tr>


                                <tr valign="middle">
                                    <th scope="row"><?php _e("JPG image quality", 'wpimage'); ?></th>
                                    <td><select name="wpimages_quality">
                                            <?php
                                            $q = get_option('wpimages_quality', WPIMAGE_DEFAULT_QUALITY);

                                            for ($x = 10; $x <= 100; $x = $x + 10) {
                                                echo "<option" . ($q == $x ? " selected='selected'" : "") . ">$x</option>";
                                            }
                                            ?>
                                        </select><?php _e(" (WordPress default is 90)", 'wpimage'); ?></td>
                                </tr>

                                <tr valign="middle">
                                    <th scope="row"><?php _e("Convert BMP To JPG", 'wpimage'); ?></th>
                                    <td><select name="wpimages_bmp_to_jpg">
                                            <option <?php
                                            if (get_option('wpimages_bmp_to_jpg', WPIMAGE_DEFAULT_BMP_TO_JPG) == "1") {
                                                echo "selected='selected'";
                                            }
                                            ?> value="1"><?php _e("Yes", 'wpimage'); ?></option>
                                            <option <?php
                                            if (get_option('wpimages_bmp_to_jpg', WPIMAGE_DEFAULT_BMP_TO_JPG) == "0") {
                                                echo "selected='selected'";
                                            }
                                            ?> value="0"><?php _e("No", 'wpimage'); ?></option>
                                        </select></td>
                                </tr>

                                <tr valign="middle">
                                    <th scope="row"><?php _e("Convert PNG To JPG", 'wpimage'); ?></th>
                                    <td><select name="wpimages_png_to_jpg">
                                            <option <?php
                                            if (get_option('wpimages_png_to_jpg', WPIMAGE_DEFAULT_PNG_TO_JPG) == "1") {
                                                echo "selected='selected'";
                                            }
                                            ?> value="1"><?php _e("Yes", 'wpimage'); ?></option>
                                            <option <?php
                                            if (get_option('wpimages_png_to_jpg', WPIMAGE_DEFAULT_PNG_TO_JPG) == "0") {
                                                echo "selected='selected'";
                                            }
                                            ?> value="0"><?php _e("No", 'wpimage'); ?></option>
                                        </select></td>
                                </tr>

                            </table>

                            <p class="submit"><input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" /></p>

                        </form>
                        <?php
                        NMRichReviewsAdminHelper::render_postbox_close();
                        NMRichReviewsAdminHelper::render_container_close();

                        NMRichReviewsAdminHelper::render_container_open('content-container');
                        NMRichReviewsAdminHelper::render_postbox_open('About');
                        $this->callback_about_us_compression();
                        NMRichReviewsAdminHelper::render_postbox_close();
                        NMRichReviewsAdminHelper::render_container_close();
                        ?>
                    </div>
                </div>
                <?php $this->displayRightCompressionText(); ?>
            </div>
            <?php
        }

        function api_settings_compression() {
            
        }

        function callback_about_us_compression() {
            $output = '<p><strong>WP Social SEO</strong> gives you the ability to quick add your Social Profiles in a compliant way so that it shows up in a google search.</p>
            <p>Specify your social profiles to Google <a href = "https://developers.google.com/webmasters/structured-data/customize/social-profiles" target = "_blank">https://developers.google.com/webmasters/structured-data/customize/social-profiles</a></p>
            <p>Use mark-up on your official website to add your social profile information to the Google Knowledge panel in some searches. Knowledge panels can prominently display your social profile information.</p>
            <p>Our other free plugins can be found at <a href = "https://profiles.wordpress.org/pigeonhut/" target = "_blank">https://profiles.wordpress.org/pigeonhut/</a> </p>
            <p>To see more about us as a company, visit <a href = "http://www.web9.co.uk" target = "_blank">http://www.web9.co.uk</a></p>
            <p>Proudly made in Belfast, Northern Ireland.</p>';
            echo $output;
        }

        function displayRightCompressionText() {
            ?>
            <div class="right-side">
                <?php
                NMRichReviewsAdminHelper::render_container_open('content-container-right');
                NMRichReviewsAdminHelper::render_postbox_open('API Settings');
                echo $this->callCustomCompression();
                NMRichReviewsAdminHelper::render_postbox_close();
                NMRichReviewsAdminHelper::render_container_close();

                NMRichReviewsAdminHelper::render_container_open('content-container-right');
                NMRichReviewsAdminHelper::render_postbox_open('Information');
                $this->compress_what_we_do();
                NMRichReviewsAdminHelper::render_postbox_close();
                NMRichReviewsAdminHelper::render_container_close();
                ?>
            </div>
            <?php
        }

        function compress_what_we_do() {
            $output = '<a href = "http://www.web9.co.uk/our-plugins/" target = "_blank"><img src = "http://www.web9.co.uk/wp-content/uploads/2014/12/web9.png" width = "340px" height = "80px" /></a>';
            $output .='<span style = "background: none repeat scroll 0 0 #FFA500;display:block;padding: 10px; color:#fff;">Want to see how else we can help your business, we have a range of <a href = "http://www.web9.co.uk/our-plugins/" target = "_blank">free plugins</a> in the WordPress repository as we believe that by giving back to the community we help to create a better product for everyone to use.</span>';
            echo $output;
        }

        function my_enqueue($hook) {
            if ($hook == 'options-media.php' || $hook == 'upload.php') {
                wp_enqueue_script('jquery');
                wp_enqueue_script('tipsy-js', plugins_url('/js/jquery.tipsy.js', __FILE__), array('jquery'));
                wp_enqueue_script('async-js', plugins_url('/js/async.js', __FILE__));
                wp_enqueue_script('ajax-script', plugins_url('/js/ajax.js', __FILE__), array('jquery'));
                wp_enqueue_style('wpimage_admin_style', plugins_url('css/admin.css', __FILE__));
                wp_enqueue_style('tipsy-style', plugins_url('css/tipsy.css', __FILE__));
                wp_enqueue_style('modal-style', plugins_url('css/jquery.modal.css', __FILE__));
                wp_localize_script('ajax-script', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
                wp_enqueue_script('modal-js', plugins_url('/js/jquery.modal.min.js', __FILE__), array('jquery'));
            }
        }

        function show_wp_image_optimizer() {
            echo '<a href = "https://wp-image.co.uk" title = "Visit wp-image.co.uk Homepage">wp-image.co.uk</a> API settings';
        }

        function get_api_status($api_key, $api_secret) {

            /*  Possible API Status Errors:
             *
             * 'Incoming request body does not contain a valid JSON object'
             * 'Incoming request body does not contain a valid auth.api_key or auth.api_secret'
             * 'Wpimage has encountered an unexpected error and cannot fulfill your request'
             * 'User not found'
             * 'API Key and API Secret mismatch'
             */

            if (!empty($api_key) && !empty($api_secret)) {
                $wpimage = new Wpimage($api_key, $api_secret);
                $status = $wpimage->status();
                return $status;
            }
            return false;
        }

        /**
         *  Handles optimizing already-uploaded images in the  Media Library
         */
        function wpimage_media_library_ajax_callback() {

            $image_id = (int) $_POST['id'];
            $type = false;
            if (isset($_POST['type'])) {
                $type = $_POST['type'];
            }

            $this->id = $image_id;

            if (wp_attachment_is_image($image_id)) {
//error_reporting(E_ALL);
//ini_set("display_errors",1);
                $image_path = get_attached_file($image_id);
//$image_path = wp_get_attachment_url($image_id);  
                $settings = $this->compression_settings;

                $api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
                $api_secret = isset($settings['api_secret']) ? $settings['api_secret'] : '';

                $status = $this->get_api_status($api_key, $api_secret);


                if ($status === false) {
                    $kv['error'] = 'There is a problem with your credentials. Please check them in the wp-image.co.uk settings section of Media Settings, and try again.';
                    update_post_meta($image_id, '_wpimage_size', $kv);
                    echo json_encode(array('error' => $kv['error']));
                    exit;
                }
                if (isset($status['active'])) {
                    
                } else {
                    echo json_encode(array('error' => 'Your API is inactive. Please visit your account settings'));
                    die();
                }

                $result = $this->optimize_image($image_path, $type);

                $kv = array();

                if ($result['success'] == true && !isset($result['error'])) {

                    $compressed_url = $result['compressed_url'];
                    $savings_percentage = (int) $result['saved_bytes'] / (int) $result['original_size'] * 100;
                    $kv['original_size'] = self::pretty_kb($result['original_size']);
                    $kv['compressed_size'] = self::pretty_kb($result['compressed_size']);
                    $kv['saved_bytes'] = self::pretty_kb($result['saved_bytes']);
                    $kv['savings_percent'] = round($savings_percentage, 2) . '%';
                    $kv['type'] = $result['type'];
                    $kv['success'] = true;
                    $kv['meta'] = wp_get_attachment_metadata($image_id);

                    if ($this->replace_image($image_path, $compressed_url)) {

                        // get metadata for thumbnails
                        $image_data = wp_get_attachment_metadata($image_id);
                        $this->optimize_thumbnails($image_data);

                        // store compressed info to DB
                        update_post_meta($image_id, '_wpimage_size', $kv);

                        // Compress thumbnails, store that data too
                        $compressed_thumbs_data = get_post_meta($image_id, '_compressed_thumbs', true);
                        if (!empty($compressed_thumbs_data)) {
                            $kv['thumbs_data'] = $compressed_thumbs_data;
                        }

                        echo json_encode($kv);
                    } else {
                        echo json_encode(array('error' => 'Could not overwrite original file. Please ensure that your files are writable by plugins.'));
                        exit;
                    }
                } else {

                    // error or no optimization
                    if (file_exists($image_path)) {

                        $kv['original_size'] = self::pretty_kb(filesize($image_path));
                        $kv['error'] = $result['error'];
                        $kv['type'] = $result['type'];

                        if ($kv['error'] == 'This image can not be optimized any further') {
                            $kv['compressed_size'] = 'No savings found';
                            $kv['no_savings'] = true;
                        }

                        update_post_meta($image_id, '_wpimage_size', $kv);
                    } else {
                        // file not found
                    }
                    echo json_encode($result);
                }
            }
            die();
        }

        /**
         *  Handles optimizing images uploaded through any of the media uploaders.
         */
        function wpimage_media_uploader_callback($image_id) {
            $this->id = $image_id;

            if (wp_attachment_is_image($image_id)) {

                $settings = $this->compression_settings;
                $type = $settings['api_lossy'];
                $image_path = get_attached_file($image_id);
                $result = $this->optimize_image($image_path, $type);

                if ($result['success'] == true && !isset($result['error'])) {

                    $compressed_url = $result['compressed_url'];
                    $savings_percentage = (int) $result['saved_bytes'] / (int) $result['original_size'] * 100;
                    $kv['original_size'] = self::pretty_kb($result['original_size']);
                    $kv['compressed_size'] = self::pretty_kb($result['compressed_size']);
                    $kv['saved_bytes'] = self::pretty_kb($result['saved_bytes']);
                    $kv['savings_percent'] = round($savings_percentage, 2) . '%';
                    $kv['type'] = $result['type'];
                    $kv['success'] = true;
                    $kv['meta'] = wp_get_attachment_metadata($image_id);

                    if ($this->replace_image($image_path, $compressed_url)) {
                        update_post_meta($image_id, '_wpimage_size', $kv);
                    } else {
                        // writing image failed
                    }
                } else {

                    // error or no optimization
                    if (file_exists($image_path)) {

                        $kv['original_size'] = self::pretty_kb(filesize($image_path));
                        $kv['error'] = $result['error'];
                        $kv['type'] = $result['type'];

                        if ($kv['error'] == 'This image can not be optimized any further') {
                            $kv['compressed_size'] = 'No savings found';
                            $kv['no_savings'] = true;
                        }

                        update_post_meta($image_id, '_wpimage_size', $kv);
                    } else {
                        // file not found
                    }
                }
            }
        }

        function show_credentials_validity() {

            $settings = $this->compression_settings;
            $api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
            $api_secret = isset($settings['api_secret']) ? $settings['api_secret'] : '';

            $status = $this->get_api_status($api_key, $api_secret);
            $url = admin_url() . 'images/';

            if ($status !== false && isset($status['active'])) {
                $url .= 'yes.png';
                echo '<p class = "apiStatus">Your credentials are valid <span class = "apiValid" style = "background:url(' . "'$url') no-repeat 0 0" . '"></span></p>';
            } else {
                $url .= 'no.png';
                echo '<p class = "apiStatus">There is a problem with your credentials <span class = "apiInvalid" style = "background:url(' . "'$url') no-repeat 0 0" . '"></span></p>';
            }
        }

        function validate_options($input) {
            $valid = array();
            $error = '';
            $valid['api_lossy'] = $input['api_lossy'];

            if (!function_exists('curl_exec')) {
                $error = 'cURL not available. Wp image compression requires cURL in order to communicate with wp-image.co.uk servers. <br /> Please ask your system administrator or host to install PHP cURL, or contact support@wp-image.co.uk for advice';
            } else {
                $status = $this->get_api_status($input['api_key'], $input['api_secret']);

                if ($status !== false) {

                    if (isset($status['active'])) {
                        if ($status['plan_name'] === 'Developers') {
                            $error = 'Developer API credentials cannot be used with this plugin.';
                        } else {
                            $valid['api_key'] = $input['api_key'];
                            $valid['api_secret'] = $input['api_secret'];
                        }
                    } else {
                        $error = 'There is a problem with your credentials. Please check them from your wp-image.co.uk account.';
                    }
                } else {
                    $error = 'Please enter a valid wp-image.co.uk API key and secret';
                }
            }

            if (!empty($error)) {
                add_settings_error(
                        'media', 'api_key_error', $error, 'error'
                );
            }
            return $valid;
        }

        function show_api_key() {
            $settings = $this->compression_settings;
            $value = isset($settings['api_key']) ? $settings['api_key'] : '';
            ?>
            <input id='wpicompressor_api_key' name='_wpimage_options[api_key]'
                   type='text' value='<?php echo esc_attr($value);
            ?>' size="50"/>
                   <?php
               }

               function show_api_secret() {
                   $settings = $this->compression_settings;
                   $value = isset($settings['api_secret']) ? $settings['api_secret'] : '';
                   ?>
            <input id='wpicompressor_api_secret' name='_wpimage_options[api_secret]'
                   type='text' value='<?php echo esc_attr($value); ?>' size="50"/>
            <?php
        }

        function show_lossy() {
            $options = get_option('_wpimage_options');
            $value = isset($options['api_lossy']) ? $options['api_lossy'] : 'lossy';

            $html = '<input type="radio" id="wpicompressor_lossy" name="_wpimage_options[api_lossy]" value="lossy"' . checked('lossy', $value, false) . '/>';
            $html .= '<label for="wpicompressor_lossy">Lossy</label>';

            $html .= '<input style="margin-left:10px;" type="radio" id="wpimage_lossless" name="_wpimage_options[api_lossy]" value="lossless"' . checked('lossless', $value, false) . '/>';
            $html .= '<label for="wpimage_lossless">Lossless</label>';

            echo $html;
        }

        function add_media_columns($columns) {
            $columns['original_size'] = 'Original Size';
            $columns['compressed_size'] = 'compressed Size';
            return $columns;
        }

        function fill_media_columns($column_name, $id) {

            $original_size = filesize(get_attached_file($id));
            $original_size = self::pretty_kb($original_size);

            $options = get_option('_wpimage_options');
            $type = isset($options['api_lossy']) ? $options['api_lossy'] : 'lossy';


            if (strcmp($column_name, 'original_size') === 0) {
                if (wp_attachment_is_image($id)) {

                    $meta = get_post_meta($id, '_wpimage_size', true);

                    if (isset($meta['original_size'])) {
                        echo $meta['original_size'];
                    } else {
                        echo $original_size;
                    }
                } else {
                    echo $original_size;
                }
            } else if (strcmp($column_name, 'compressed_size') === 0) {

                if (wp_attachment_is_image($id)) {

                    $meta = get_post_meta($id, '_wpimage_size', true);

                    // Is it optimized? Show some stats
                    if (isset($meta['compressed_size']) && empty($meta['no_savings'])) {
                        $compressed_size = $meta['compressed_size'];
                        $type = $meta['type'];
                        if($meta['savings_percent']>=0){
                        $savings_percentage = $meta['savings_percent'];
                        echo '<strong>' . $compressed_size . '</strong><br /><small>Type:&nbsp;' . $type . '</small><br /><small>Savings:&nbsp;' . $savings_percentage . '</small>';

                        $thumbs_data = get_post_meta($id, '_compressed_thumbs', true);
                        $thumbs_count = count($thumbs_data);

                        if (!empty($thumbs_data)) {
                            echo '<br /><small>' . $thumbs_count . ' thumbs optimized' . '</small>';
                        }
                        } else {
                            echo '<small>No further optimization required</small>';
                        }
                        // Were there no savings, or was there an error?
                    } else {
                        $image_url = wp_get_attachment_url($id);
                        $filename = basename($image_url);
                        echo '<div class="buttonWrap"><button data-setting="' . $type . '" type="button" class="wpimage_req" data-id="' . $id . '" id="wpimageid-' . $id . '" data-filename="' . $filename . '" data-url="' . $image_url . '">Optimize This Image</button><span class="wpimageSpinner"></span></div>';
                        if (!empty($meta['no_savings'])) {
                            echo '<div class="noSavings"><strong>No savings found</strong><br /><small>Type:&nbsp;' . $meta['type'] . '</small></div>';
                        } else if (isset($meta['error'])) {
                            $error = $meta['error'];
                            echo '<div class="wpimageErrorWrap"><a class="wpimageError" title="' . $error . '">Failed! Hover here</a></div>';
                        }
                    }
                } else {
                    echo 'n/a';
                }
            }
        }

        function replace_image($image_path, $compressed_url) {
            $rv = false;
            $ch = curl_init($compressed_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $result = curl_exec($ch);
            $rv = file_put_contents($image_path, $result);
            return $rv !== false;
        }

        function optimize_image($image_path, $type) {
            $settings = $this->compression_settings;
            $wpimage = new Wpimage($settings['api_key'], $settings['api_secret']);

            if (!empty($type)) {
                $lossy = $type === 'lossy';
            } else {
                $lossy = $settings['api_lossy'] === "lossy";
            }

            $params = array(
                "file" => $image_path,
                "wait" => true,
                "lossy" => $lossy,
                "origin" => "wp"
            );
            $data = $wpimage->upload($params);

            $data['type'] = !empty($type) ? $type : $settings['api_lossy'];

            return $data;
        }

        function optimize_thumbnails($image_data) {

            $image_id = $this->id;
            if (empty($image_id)) {
                global $wpdb;
                $post = $wpdb->get_row($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_value = %s LIMIT 1", $image_data['file']));
                $image_id = $post->post_id;
            }

            $path_parts = pathinfo($image_data['file']);

            // e.g. 04/02, for use in getting correct path or URL
            $upload_subdir = $path_parts['dirname'];

            $upload_dir = wp_upload_dir();

            // all the way up to /uploads
            $upload_base_path = $upload_dir['basedir'];
            $upload_full_path = $upload_base_path . '/' . $upload_subdir;

            $sizes = array();

            if (isset($image_data['sizes'])) {
                $sizes = $image_data['sizes'];
            }

            if (!empty($sizes)) {

                $thumb_path = '';

                $thumbs_optimized_store = array();
                $this_thumb = array();

                foreach ($sizes as $key => $size) {

                    $thumb_path = $upload_full_path . '/' . $size['file'];

                    if (file_exists($thumb_path) !== false) {

                        $result = $this->optimize_image($thumb_path, $this->optimization_type);

                        if (!empty($result) && isset($result['success']) && isset($result['compressed_url'])) {
                            $compressed_url = $result["compressed_url"];
                            if ($this->replace_image($thumb_path, $compressed_url)) {
                                $this_thumb = array('thumb' => $key, 'file' => $size['file'], 'original_size' => $result['original_size'], 'compressed_size' => $result['compressed_size'], 'type' => $this->optimization_type);
                                $thumbs_optimized_store [] = $this_thumb;
                            }
                        }
                    }
                }
            }
            if (!empty($thumbs_optimized_store)) {
                update_post_meta($image_id, '_compressed_thumbs', $thumbs_optimized_store, false);
            }
            return $image_data;
        }

        static function pretty_kb($bytes) {
            return round(( $bytes / 1024), 2) . ' kB';
        }

        function admin_tabs_callmeback($tabs, $current = NULL) {
            if (is_null($current)) {
                if (isset($_GET['page'])) {
                    $current = $_GET['page'];
                }
            }
            $content = '';
            $content .= '<h2 class="nav-tab-wrapper">';
            foreach ($tabs as $location => $tabname) {
                if ($current == $location) {
                    $class = ' nav-tab-active';
                } else {
                    $class = '';
                }
                $content .= '<a class="nav-tab' . $class . '" href="?page=' . $location . '">' . $tabname . '</a>';
            }
            $content .= '</h2>';
            return $content;
        }

    }

}
new Wp_Image_compression();