<?php
/**
* ################################################################################
* IMSANITY AJAX FUNCTIONS
* ################################################################################
*/

add_action('wp_ajax_wpimages_get_images', 'wpimages_get_images');
add_action('wp_ajax_wpimages_resize_image', 'wpimages_resize_image');
add_action('admin_head', 'wpimages_admin_javascript');

/**
 * Verifies that the current user has administrator permission and, if not,
 * renders a json warning and dies
 */
function wpimages_verify_permission()
{
	if (!current_user_can('administrator'))
	{
		$results = array('success'=>false,'message' => 'Administrator permission is required');
		echo json_encode($results);
		die();
	}
}


/**
 * Searches for up to 250 images that are candidates for resize and renders them
 * to the browser as a json array, then dies
 */
function wpimages_get_images()
{
	wpimages_verify_permission();

	global $wpdb;

	$query = $wpdb->prepare(
		"select
			$wpdb->posts.ID as ID,
			$wpdb->posts.guid as guid,
			$wpdb->postmeta.meta_value as file_meta
			from $wpdb->posts
			inner join $wpdb->postmeta on $wpdb->posts.ID = $wpdb->postmeta.post_id and $wpdb->postmeta.meta_key = %s
			where $wpdb->posts.post_type = %s
			and $wpdb->posts.post_mime_type like %s
			and $wpdb->posts.post_mime_type != %s",
		array('_wp_attachment_metadata', 'attachment', 'image%','image/bmp')
	);

	$images = $wpdb->get_results($query);
	$results = array();

	if ($images)
	{
		$maxW = wpimages_get_option('wpimages_max_width',WPIMAGE_DEFAULT_MAX_WIDTH);
		$maxH = wpimages_get_option('wpimages_max_height',WPIMAGE_DEFAULT_MAX_HEIGHT);
		$count = 0;

		foreach ($images as $image)
		{
			$meta = unserialize($image->file_meta);

			if ($meta['width'] > $maxW || $meta['height'] > $maxH)
			{
				$count++;

				$results[] = array(
					'id'=>$image->ID,
					'width'=>$meta['width'],
					'height'=>$meta['height'],
					'file'=>$meta['file']
				);
			}

			// make sure we only return a limited number of records so we don't overload the ajax features
			if ($count >= WPIMAGE_AJAX_MAX_RECORDS) break;
		}
	}

	echo json_encode($results);
	die(); // required by wordpress
}

/**
* Resizes the image with the given id according to the configured max width and height settings
* renders a json response indicating success/failure and dies
*/
function wpimages_resize_image()
{
	wpimages_verify_permission();

	global $wpdb;

	$id = intval( $_POST['id'] );

	if (!$id)
	{
		$results = array('success'=>false,'message' => __('Missing ID Parameter','wpimage'));
		echo json_encode($results);
		die();
	}

	// @TODO: probably doesn't need the join...?
	$query = $wpdb->prepare(
	"select
				$wpdb->posts.ID as ID,
				$wpdb->posts.guid as guid,
				$wpdb->postmeta.meta_value as file_meta
				from $wpdb->posts
				inner join $wpdb->postmeta on $wpdb->posts.ID = $wpdb->postmeta.post_id and $wpdb->postmeta.meta_key = %s
				where  $wpdb->posts.ID = %d
				and $wpdb->posts.post_type = %s
				and $wpdb->posts.post_mime_type like %s",
	array('_wp_attachment_metadata', $id, 'attachment', 'image%')
	);

	$images = $wpdb->get_results($query);

	if ($images)
	{
		$image = $images[0];
		$meta = unserialize($image->file_meta);
		$uploads = wp_upload_dir();
		$oldPath = $uploads['basedir'] . "/" . $meta['file'];

		$maxW = wpimages_get_option('wpimages_max_width',WPIMAGE_DEFAULT_MAX_WIDTH);
		$maxH = wpimages_get_option('wpimages_max_height',WPIMAGE_DEFAULT_MAX_HEIGHT);

		// method one - slow but accurate, get file size from file itself
		// list($oldW, $oldH) = getimagesize( $oldPath );
		// method two - get file size from meta, fast but resize will fail if meta is out of sync
		$oldW = $meta['width'];
		$oldH = $meta['height'];


		if (($oldW > $maxW && $maxW > 0) || ($oldH > $maxH && $maxH > 0))
		{
			$quality = wpimages_get_option('wpimages_quality',WPIMAGE_DEFAULT_QUALITY);

			list($newW, $newH) = wp_constrain_dimensions($oldW, $oldH, $maxW, $maxH);

			$resizeResult = wpimages_image_resize( $oldPath, $newW, $newH, false, null, null, $quality);
			// $resizeResult = new WP_Error('invalid_image', __('Could not read image size'), $oldPath);  // uncommend to debug fail condition

			if (!is_wp_error($resizeResult))
			{
				$newPath = $resizeResult;

				if ($newPath != $oldPath)
				{
					// remove original and replace with re-sized image
					unlink($oldPath);
					rename($newPath, $oldPath);
				}

				$meta['width'] = $newW;
				$meta['height'] = $newH;

				// @TODO replace custom query with update_post_meta
				$update_query = $wpdb->prepare(
					"update $wpdb->postmeta
						set $wpdb->postmeta.meta_value = %s
						where  $wpdb->postmeta.post_id = %d
						and $wpdb->postmeta.meta_key = %s",
					array(maybe_serialize($meta), $image->ID, '_wp_attachment_metadata')
				);

				$wpdb->query($update_query);

				$results = array('success'=>true,'id'=> $id, 'message' => sprintf(__('OK: %s','wpimage') , $oldPath) );
			}
			else
			{
				$results = array('success'=>false,'id'=> $id, 'message' => sprintf(__('ERROR: %s (%s)','wpimage'),$oldPath,htmlentities($resizeResult->get_error_message()) ) );
			}
		}
		else
		{
			$results = array('success'=>true,'id'=> $id, 'message' => sprintf(__('SKIPPED: %s (Resize not required)','wpimage') , $oldPath ) );
		}

	}
	else
	{
		$results = array('success'=>false,'id'=> $id, 'message' => sprintf(__('ERROR: (Attachment with ID of %s not found) ','wpimage') , htmlentities($id) ) );
	}

	// if there is a quota we need to reset the directory size cache so it will re-calculate
	delete_transient('dirsize_cache');
	
	echo json_encode($results);
	die(); // required by wordpress
}

/**
 * Output the javascript needed for making ajax calls into the header
 */
function wpimages_admin_javascript()
{
	// javascript is queued in settings.php wpimages_settings_banner()
}

?>