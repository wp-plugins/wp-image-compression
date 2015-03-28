<?php
/**
 * ################################################################################
 * WPIMAGE ADMIN/SETTINGS UI
 * ################################################################################
 */

// register the plugin settings menu
add_action('admin_menu', 'wpimages_create_menu');
add_action( 'network_admin_menu', 'wpimages_register_network' );
add_filter("plugin_action_links_wpimage/wp-image-compression.php", 'wpimages_settings_link' );

// activation hooks
// TODO: custom table is not removed because de-activating one site shouldn't affect the entire server
register_activation_hook('wp-image-compression/wp-image-compression.php', 'wpimages_maybe_created_custom_table' );
// add_action('plugins_loaded', 'wpimages_maybe_created_custom_table');
// register_deactivation_hook('wp-image-compression/wp-image-compression.php', ...);
// register_uninstall_hook('wp-image-compression/wp-image-compression.php', 'wpimages_maybe_remove_custom_table');

// settings cache
$_wpimages_multisite_settings = null;

/**
 * Settings link that appears on the plugins overview page
 * @param array $links
 * @return array
 */
function wpimages_settings_link($links) {
	$links[] = '<a href="'. get_admin_url(null, 'options-general.php?page='.__FILE__) .'">Settings</a>';
	return $links;
}

/**
 * Create the settings menu item in the WordPress admin navigation and
 * link it to the plugin settings page
 */
function wpimages_create_menu()
{
	// create new menu for site configuration
	//add_options_page(__('Wp image converter Plugin Settings','wpimage'), 'Wp image converter', 'administrator', __FILE__, 'wpimages_settings_page');

	// call register settings function
	add_action( 'admin_init', 'wpimages_register_settings' );
}

// TODO: legacy code to support previous MU version... ???
// if ( dm_site_admin() && version_compare( $wp_version, '3.0.9', '<=' ) ) {
// 	if ( version_compare( $wp_version, '3.0.1', '<=' ) ) {
// 		add_submenu_page('wpmu-admin.php', __( 'Domain Mapping', 'wordpress-mu-domain-mapping' ), __( 'Domain Mapping', 'wordpress-mu-domain-mapping'), 'manage_options', 'dm_admin_page', 'dm_admin_page');
// 		add_submenu_page('wpmu-admin.php', __( 'Domains', 'wordpress-mu-domain-mapping' ), __( 'Domains', 'wordpress-mu-domain-mapping'), 'manage_options', 'dm_domains_admin', 'dm_domains_admin');
// 	} else {
// 		add_submenu_page('ms-admin.php', __( 'Domain Mapping', 'wordpress-mu-domain-mapping' ), 'Domain Mapping', 'manage_options', 'dm_admin_page', 'dm_admin_page');
// 		add_submenu_page('ms-admin.php', __( 'Domains', 'wordpress-mu-domain-mapping' ), 'Domains', 'manage_options', 'dm_domains_admin', 'dm_domains_admin');
// 	}
// }
// add_action( 'admin_menu', 'dm_add_pages' );

/**
 * Returns the name of the custom multi-site settings table.
 * this will be the same table regardless of the blog
 */
function wpimages_get_custom_table_name()
{
	global $wpdb;

	// passing in zero seems to return $wpdb->base_prefix, which is not public
	return $wpdb->get_blog_prefix(0) . "wpimage";
}

/**
 * Return true if the multi-site settings table exists
 * @return bool
 */
function wpimages_multisite_table_exists()
{
	global $wpdb;
	$table_name = wpimages_get_custom_table_name();
	return $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name;
}

/**
* Return true if the multi-site settings table exists
* @return bool
*/
function wpimages_multisite_table_schema_version()
{
	// if the table doesn't exist then there is no schema to report
	if (!wpimages_multisite_table_exists()) return '0';

	global $wpdb;
	$version = $wpdb->get_var('select data from ' . wpimages_get_custom_table_name() . " where setting = 'schema'");

	if (!$version) $version = '1.0'; // this is a legacy version 1.0 installation

	return $version;

}

/**
 * Returns the default network settings in the case where they are not
 * defined in the database, or multi-site is not enabled
 * @return stdClass
 */
function wpimages_get_default_multisite_settings()
{
	$data = new stdClass();
	$data->wpimages_override_site = false;
	$data->wpimages_max_height = WPIMAGE_DEFAULT_MAX_HEIGHT;
	$data->wpimages_max_width = WPIMAGE_DEFAULT_MAX_WIDTH;
	$data->wpimages_max_height_library = WPIMAGE_DEFAULT_MAX_HEIGHT;
	$data->wpimages_max_width_library = WPIMAGE_DEFAULT_MAX_WIDTH;
	$data->wpimages_max_height_other = WPIMAGE_DEFAULT_MAX_HEIGHT;
	$data->wpimages_max_width_other = WPIMAGE_DEFAULT_MAX_WIDTH;
	$data->wpimages_bmp_to_jpg = WPIMAGE_DEFAULT_BMP_TO_JPG;
	$data->wpimages_png_to_jpg = WPIMAGE_DEFAULT_PNG_TO_JPG;
	$data->wpimages_quality = WPIMAGE_DEFAULT_QUALITY;
	return $data;
}


/**
 * On activation create the multisite database table if necessary.  this is
 * called when the plugin is activated as well as when it is automatically
 * updated.
 *
 * @param bool set to true to force the query to run in the case of an upgrade
 */
function wpimages_maybe_created_custom_table()
{
	// if not a multi-site no need to do any custom table lookups
	if ( (!function_exists("is_multisite")) || (!is_multisite()) ) return;

	global $wpdb;

	$schema = wpimages_multisite_table_schema_version();
	$table_name = wpimages_get_custom_table_name();

	if ($schema == '0')
	{
		// this is an initial database setup
		$sql = "CREATE TABLE IF NOT EXISTS " . $table_name . " (
					  setting varchar(55),
					  data text NOT NULL,
					  PRIMARY KEY (setting)
					);";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
		$data = wpimages_get_default_multisite_settings();

		// add the rows to the database
		$data = wpimages_get_default_multisite_settings();
		$wpdb->insert( $table_name, array( 'setting' => 'multisite', 'data' => maybe_serialize($data) ) );
		$wpdb->insert( $table_name, array( 'setting' => 'schema', 'data' => WPIMAGE_SCHEMA_VERSION ) );
	}

	if ($schema != WPIMAGE_SCHEMA_VERSION)
	{
		// this is a schema update.  for the moment there is only one schema update available, from 1.0 to 1.1
		if ($schema == '1.0')
		{
			// update from version 1.0 to 1.1
			$wpdb->insert( $table_name, array( 'setting' => 'schema', 'data' => WPIMAGE_SCHEMA_VERSION ) );
			$update1 = "ALTER TABLE " . $table_name . " CHANGE COLUMN data data TEXT NOT NULL;";
			$wpdb->query($update1);
		}
		else
		{
			// @todo we don't have this yet
			$wpdb->update(
				$table_name,
				array('data' =>  WPIMAGE_SCHEMA_VERSION),
				array('setting' => 'schema')
			);
		}

	}


}

/**
 * Register the network settings page
 */
function wpimages_register_network()
{
	add_submenu_page('settings.php', __('Wp image resizer Network Settings','wpimage'), 'Wp image resizer', 'manage_options', 'wpimages_network', 'wpimages_network_settings');
}

/**
 * display the form for the multi-site settings page
 */
function wpimages_network_settings()
{
	wpimages_settings_css();

	echo '
		<div class="wrap">
		<div id="icon-options-general" class="icon32"><br></div>
		<h2>'.__('Wp image resizer Network Settings','wpimage').'</h2>
		';

	// we only want to update if the form has been submitted
	if (isset($_REQUEST['update_settings']))
	{
		wpimages_network_settings_update();
		echo "<div class='updated settings-error'><p><strong>".__("Wp image resizer network settings saved.",'wpimage')."</strong></p></div>";
	}

	wpimages_settings_banner();

	$settings = wpimages_get_multisite_settings();

	?>

	<form method="post" action="settings.php?page=wpimages_network">
	<input type="hidden" name="update_settings" value="1" />

	<table class="form-table">
	<tr valign="top">
	<th scope="row"><?php _e("Global Settings Override",'wpimage'); ?></th>
	<td>
		<select name="wpimages_override_site">
			<option value="0" <?php if ($settings->wpimages_override_site == '0') echo "selected='selected'" ?> ><?php _e("Allow each site to configure Wp image converter settings",'wpimage'); ?></option>
			<option value="1" <?php if ($settings->wpimages_override_site == '1') echo "selected='selected'" ?> ><?php _e("Use global Wp image Converter settings (below) for all sites",'wpimage'); ?></option>
		</select>
	</td>
	</tr>

	<tr valign="top">
	<th scope="row"><?php _e("Images uploaded within a Page/Post",'wpimage');?></th>
	<td>
		Fit within <input name="wpimages_max_width" value="<?php echo $settings->wpimages_max_width ?>" style="width: 50px;" />
		x <input name="wpimages_max_height" value="<?php echo $settings->wpimages_max_height ?>" style="width: 50px;" /> pixels width/height <?php _e(" (or enter 0 to disable)",'wpimage'); ?>
	</td>
	</tr>

	<tr valign="top">
	<th scope="row"><?php _e("Images uploaded directly to the Media Library",'wpimage'); ?></th>
	<td>
		Fit within <input name="wpimages_max_width_library" value="<?php echo $settings->wpimages_max_width_library ?>" style="width: 50px;" />
		x <input name="wpimages_max_height_library" value="<?php echo $settings->wpimages_max_height_library ?>" style="width: 50px;" /> pixels width/height <?php _e(" (or enter 0 to disable)",'wpimage'); ?>
	</td>
	</tr>

	<tr valign="top">
	<th scope="row"><?php _e("Images uploaded elsewhere (Theme headers, backgrounds, logos, etc)",'wpimage'); ?></th>
	<td>
		Fit within <input name="wpimages_max_width_other" value="<?php echo $settings->wpimages_max_width_other ?>" style="width: 50px;" />
		x <input name="wpimages_max_height_other" value="<?php echo $settings->wpimages_max_height_other ?>" style="width: 50px;" /> pixels width/height <?php _e(" (or enter 0 to disable)",'wpimage'); ?>
	</td>
	</tr>

	<tr valign="top">
	<th scope="row"><?php _e("Convert BMP to JPG",'wpimage'); ?></th>
	<td><select name="wpimages_bmp_to_jpg">
		<option value="1" <?php if ($settings->wpimages_bmp_to_jpg == '1') echo "selected='selected'" ?> ><?php _e("Yes",'wpimage'); ?></option>
		<option value="0" <?php if ($settings->wpimages_bmp_to_jpg == '0') echo "selected='selected'" ?> ><?php _e("No",'wpimage'); ?></option>
	</select></td>
	</tr>

	<tr valign="top">
	<th scope="row"><?php _e("Convert PNG to JPG",'wpimage'); ?></th>
	<td><select name="wpimages_png_to_jpg">
		<option value="1" <?php if ($settings->wpimages_png_to_jpg == '1') echo "selected='selected'" ?> ><?php _e("Yes",'wpimage'); ?></option>
		<option value="0" <?php if ($settings->wpimages_png_to_jpg == '0') echo "selected='selected'" ?> ><?php _e("No",'wpimage'); ?></option>
	</select></td>
	</tr>

	<tr valign="top">
	<th scope="row"><?php _e("JPG Quality",'wpimage'); ?></th>
		<td><select name="wpimages_quality">
			<?php
			$q = $settings->wpimages_quality;

			for ($x = 10; $x <= 100; $x = $x + 10)
			{
				echo "<option". ($q == $x ? " selected='selected'" : "") .">$x</option>";
			}
			?>
		</select><?php _e(" (WordPress default is 90)",'wpimage'); ?></td>
	</tr>

	</table>

	<p class="submit"><input type="submit" class="button-primary" value="<?php _e("Update Settings",'wpimage'); ?>" /></p>

	</form>
	<?php

	echo '</div>';
}

/**
 * Process the form, update the network settings
 * and clear the cached settings
 */
function wpimages_network_settings_update()
{
	global $wpdb;
	global $_wpimages_multisite_settings;

	// ensure that the custom table is created when the user updates network settings
	// this is not ideal but it's better than checking for this table existance
	// on every page load
	wpimages_maybe_created_custom_table();

	$table_name = wpimages_get_custom_table_name();

	$data = new stdClass();
	$data->wpimages_override_site = $_REQUEST['wpimages_override_site'] == 1;
	$data->wpimages_max_height = $_REQUEST['wpimages_max_height'];
	$data->wpimages_max_width = $_REQUEST['wpimages_max_width'];
	$data->wpimages_max_height_library = $_REQUEST['wpimages_max_height_library'];
	$data->wpimages_max_width_library = $_REQUEST['wpimages_max_width_library'];
	$data->wpimages_max_height_other = $_REQUEST['wpimages_max_height_other'];
	$data->wpimages_max_width_other = $_REQUEST['wpimages_max_width_other'];
	$data->wpimages_bmp_to_jpg = $_REQUEST['wpimages_bmp_to_jpg'] == 1;
	$data->wpimages_png_to_jpg = $_REQUEST['wpimages_png_to_jpg'] == 1;
	$data->wpimages_quality = $_REQUEST['wpimages_quality'];

	$wpdb->update(
		$table_name,
		array('data' =>  maybe_serialize($data)),
		array('setting' => 'multisite')
	);

	// clear the cache
	$_wpimages_multisite_settings = null;
}

/**
 * Return the multi-site settings as a standard class.  If the settings are not
 * defined in the database or multi-site is not enabled then the default settings
 * are returned.  This is cached so it only loads once per page load, unless
 * wpimages_network_settings_update is called.
 * @return stdClass
 */
function wpimages_get_multisite_settings()
{
	global $_wpimages_multisite_settings;
	$result = null;

	if (!$_wpimages_multisite_settings)
	{
		if (function_exists("is_multisite") && is_multisite())
		{
			global $wpdb;

			$result = $wpdb->get_var('select data from ' . wpimages_get_custom_table_name() . " where setting = 'multisite'");
		}

		// if there's no results, return the defaults instead
		$_wpimages_multisite_settings = $result
			? unserialize($result)
			: wpimages_get_default_multisite_settings();

		// this is for backwards compatibility
		if ($_wpimages_multisite_settings->wpimages_max_height_library == '')
		{
			$_wpimages_multisite_settings->wpimages_max_height_library = $_wpimages_multisite_settings->wpimages_max_height;
			$_wpimages_multisite_settings->wpimages_max_width_library = $_wpimages_multisite_settings->wpimages_max_width;
			$_wpimages_multisite_settings->wpimages_max_height_other = $_wpimages_multisite_settings->wpimages_max_height;
			$_wpimages_multisite_settings->wpimages_max_width_other = $_wpimages_multisite_settings->wpimages_max_width;
		}

	}

	return $_wpimages_multisite_settings;
}

/**
 * Gets the option setting for the given key, first checking to see if it has been
 * set globally for multi-site.  Otherwise checking the site options.
 * @param string $key
 * @param string $ifnull value to use if the requested option returns null
 */
function wpimages_get_option($key,$ifnull)
{
	$result = null;

	$settings = wpimages_get_multisite_settings();

	if ($settings->wpimages_override_site)
	{
		$result = $settings->$key;
		if ($result == null) $result = $ifnull;
	}
	else
	{
		$result = get_option($key,$ifnull);
	}

	return $result;

}

/**
 * Register the configuration settings that the plugin will use
 */
function wpimages_register_settings()
{
	//register our settings
	register_setting( 'wpimage-settings-group', 'wpimages_max_height' );
	register_setting( 'wpimage-settings-group', 'wpimages_max_width' );
	register_setting( 'wpimage-settings-group', 'wpimages_max_height_library' );
	register_setting( 'wpimage-settings-group', 'wpimages_max_width_library' );
	register_setting( 'wpimage-settings-group', 'wpimages_max_height_other' );
	register_setting( 'wpimage-settings-group', 'wpimages_max_width_other' );
	register_setting( 'wpimage-settings-group', 'wpimages_bmp_to_jpg' );
	register_setting( 'wpimage-settings-group', 'wpimages_png_to_jpg' );
	register_setting( 'wpimage-settings-group', 'wpimages_quality' );
}

/**
 * Helper function to render css styles for the settings forms
 * for both site and network settings page
 */
function wpimages_settings_css()
{
	echo "
	<style>
	#wpimages_header
	{
		border: solid 1px #c6c6c6;
		margin: 12px 2px 8px 2px;
		padding: 20px;
		background-color: #e1e1e1;
	}
		#wpimages_header h4
		{
		margin: 0px 0px 0px 0px;
		}
		#wpimages_header tr
		{
		vertical-align: top;
		}

		.wpimages_section_header
		{
		border: solid 1px #c6c6c6;
		margin: 12px 2px 8px 2px;
		padding: 20px;
		background-color: #e1e1e1;
		}

	</style>";
}

/**
 * Helper function to render the settings banner
 * for both site and network settings page
 */
function wpimages_settings_banner()
{
	// register the scripts that are used by the bulk resizer
	wp_register_script( 'my_plugin_script', plugins_url('/wp-image-compression/scripts/wp-image-compression.js?v='.WPIMAGE_VERSION), array('jquery'));
	wp_enqueue_script( 'my_plugin_script' );
	
	/*echo '
	<div id="wpimages_header" style="float: left;">
		<a href="http://verysimple.com/products/wpimage/"><img alt="Wp image resizer" src="' . plugins_url() . '/wpimage/images/wpimage.png" style="float: right; margin-left: 15px;"/></a>

		<h4>'.__("Wp image resizer automatically resizes insanely huge image uploads",'wpimage').'</h4>'.

		__("<p>Wp image resizer automaticaly reduces the size of images that are larger than the specified maximum and replaces the original
		with one of a more \"sane\" size.  Site contributors don\'t need to concern themselves with manually scaling images
		and can upload them directly from their camera or phone.</p>

		<p>The resolution of modern cameras is larger than necessary for typical web display.
		The average computer screen is not big enough to display a 3 megapixel camera-phone image at full resolution.
		WordPress does a good job of creating scaled-down copies which can be used, however the original images
		are permanently stored, taking up disk quota and, if used on a page, create a poor viewer experience.</p>

		<p>This plugin is designed for sites where high-resolution images are not necessary and/or site contributors
		do not want (or understand how) to deal with scaling images.  This plugin should not be used on
		sites for which original, high-resolution images must be stored.</p>

		<p>Be sure to save back-ups of your full-sized images if you wish to keep them.</p>",'wpimage') .

		sprintf( __("<p>Wp image resizer Version %s by %s </p>",'wpimage'),WPIMAGE_VERSION ,'<a href="http://verysimple.com/">Jason Hinkle</a>') .
	'</div>
	<br style="clear:both" />';*/
}

/**
 * Render the settings page by writing directly to stdout.  if multi-site is enabled
 * and wpimages_override_site is true, then display a notice message that settings
 * are not editable instead of the settings form
 */
function wpimages_settings_page()
{
	wpimages_settings_css();

	?>
	<div class="wrap">
	<div id="icon-options-general" class="icon32"><br></div>
	<h2><?php _e("Wp image converter Settings",'wpimage'); ?></h2>
	<?php

	wpimages_settings_banner();

	$settings = wpimages_get_multisite_settings();

	if ($settings->wpimages_override_site)
	{
		wpimages_settings_page_notice();
	}
	else
	{
		wpimages_settings_page_form();
	}

	?>

	<!--<h2 style="margin-top: 0px;"><?php /*_e("Bulk Resize Images",'wpimage');*/ ?></h2>

	<div id="wpimages_header">
	<?php /*_e('<p>If you have existing images that were uploaded prior to installing Wp image resizer, you may resize them
	all in bulk to recover disk space.  To begin, click the "Search Images" button to search all existing
	attachments for images that are larger than the configured limit.</p>
	<p>Limitations: For performance reasons a maximum of ' . WPIMAGE_AJAX_MAX_RECORDS . ' images will be returned at one time.  Bitmap
	image types are not supported and will not appear in the search results.</p>','wpimage');*/ ?>
	</div>

	<div style="border: solid 1px #ff6666; background-color: #ffbbbb; padding: 8px;">
		<h4><?php /*_e('WARNING: BULK RESIZE WILL ALTER YOUR ORIGINAL IMAGES AND CANNOT BE UNDONE!','wpimage');*/ ?></h4>
		
		<p><?php /*_e('It is <strong>HIGHLY</strong> recommended that you backup 
		your wp-content/uploads folder before proceeding.  You will have a chance to preview and select the images to convert.
		It is also recommended that you initially select only 1 or 2 images and verify that everything is ok before
		processing your entire library.  You have been warned!','wpimage');*/ ?></p>
	</div>

	<p class="submit" id="wpimage-examine-button">
		<button class="button-primary" onclick="wpimages_load_images('wpimages_image_list');"><?php /*_e('Search Images...','wpimage');*/ ?></button>
	</p>
	<div id='wpimages_image_list'></div>-->
	<?php


	/*echo '</div>';*/

}

/**
 * Multi-user config file exists so display a notice
 */
function wpimages_settings_page_notice()
{
	?>
	<div class="updated settings-error">
	<p><strong><?php _e("Wp image converter settings have been configured by the server administrator. There are no site-specific settings available.",'wpimage'); ?></strong></p>
	</div>

	<?php
}

/**
* Render the site settings form.  This is processed by
* WordPress built-in options persistance mechanism
*/
function wpimages_settings_page_form()
{
	?>
	<form method="post" action="options.php">

	<?php settings_fields( 'wpimage-settings-group' ); ?>
		<table class="form-table">

		<tr valign="middle">
		<th scope="row"><?php _e("Images uploaded within a Page/Post",'wpimage'); ?></th>
		<td>Fit within <input type="text" style="width: 50px;" name="wpimages_max_width" value="<?php echo get_option('wpimages_max_width',WPIMAGE_DEFAULT_MAX_WIDTH); ?>" />
		x <input type="text" style="width: 50px;" name="wpimages_max_height" value="<?php echo get_option('wpimages_max_height',WPIMAGE_DEFAULT_MAX_HEIGHT); ?>" /> pixels width/height <?php _e(" (or enter 0 to disable)",'wpimage'); ?>
		</td>
		</tr>

		<tr valign="middle">
		<th scope="row"><?php _e("Images uploaded directly to the Media Library",'wpimage'); ?></th>
		<td>Fit within <input type="text" style="width: 50px;" name="wpimages_max_width_library" value="<?php echo get_option('wpimages_max_width_library',WPIMAGE_DEFAULT_MAX_WIDTH); ?>" />
		x <input type="text" style="width: 50px;" name="wpimages_max_height_library" value="<?php echo get_option('wpimages_max_height_library',WPIMAGE_DEFAULT_MAX_HEIGHT); ?>" /> pixels width/height <?php _e(" (or enter 0 to disable)",'wpimage'); ?>
		</td>
		</tr>

		<tr valign="middle">
		<th scope="row"><?php _e("Images uploaded elsewhere (Theme headers, backgrounds, logos, etc)",'wpimage'); ?></th>
		<td>Fit within <input type="text" style="width: 50px;" name="wpimages_max_width_other" value="<?php echo get_option('wpimages_max_width_other',WPIMAGE_DEFAULT_MAX_WIDTH); ?>" />
		x <input type="text" style="width: 50px;" name="wpimages_max_height_other" value="<?php echo get_option('wpimages_max_height_other',WPIMAGE_DEFAULT_MAX_HEIGHT); ?>" /> pixels width/height <?php _e(" (or enter 0 to disable)",'wpimage'); ?>
		</td>
		</tr>


		<tr valign="middle">
		<th scope="row"><?php _e("JPG image quality",'wpimage'); ?></th>
		<td><select name="wpimages_quality">
			<?php
			$q = get_option('wpimages_quality',WPIMAGE_DEFAULT_QUALITY);

			for ($x = 10; $x <= 100; $x = $x + 10)
			{
				echo "<option". ($q == $x ? " selected='selected'" : "") .">$x</option>";
			}
			?>
		</select><?php _e(" (WordPress default is 90)",'wpimage'); ?></td>
		</tr>

		<tr valign="middle">
		<th scope="row"><?php _e("Convert BMP To JPG",'wpimage'); ?></th>
		<td><select name="wpimages_bmp_to_jpg">
			<option <?php if (get_option('wpimages_bmp_to_jpg',WPIMAGE_DEFAULT_BMP_TO_JPG) == "1") {echo "selected='selected'";} ?> value="1"><?php _e("Yes",'wpimage'); ?></option>
			<option <?php if (get_option('wpimages_bmp_to_jpg',WPIMAGE_DEFAULT_BMP_TO_JPG) == "0") {echo "selected='selected'";} ?> value="0"><?php _e("No",'wpimage'); ?></option>
		</select></td>
		</tr>

		<tr valign="middle">
		<th scope="row"><?php _e("Convert PNG To JPG",'wpimage'); ?></th>
		<td><select name="wpimages_png_to_jpg">
			<option <?php if (get_option('wpimages_png_to_jpg',WPIMAGE_DEFAULT_PNG_TO_JPG) == "1") {echo "selected='selected'";} ?> value="1"><?php _e("Yes",'wpimage'); ?></option>
			<option <?php if (get_option('wpimages_png_to_jpg',WPIMAGE_DEFAULT_PNG_TO_JPG) == "0") {echo "selected='selected'";} ?> value="0"><?php _e("No",'wpimage'); ?></option>
		</select></td>
		</tr>

	</table>

	<p class="submit"><input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" /></p>

	</form>
	<?php

}

?>