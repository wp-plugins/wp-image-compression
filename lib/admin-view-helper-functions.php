<?php

class NMRichReviewsAdminHelper {

	public static function render_header($title, $echo = TRUE) {
		global $file;
		$plugin_data = get_plugin_data( $file);
		$output = '';
		$output .= '<h1>' . $plugin_data['Name'] . '</h1>';
		if ($echo) {
			echo $output;
		} else {
			return $output;
		}
	}

	public static function render_sidebar() {
		?>
        <?php

		NMRichReviewsAdminHelper::render_postbox_open('Need Help');
		NMRichReviewsAdminHelper::insert_website_link();
		NMRichReviewsAdminHelper::render_postbox_close();

        NMRichReviewsAdminHelper::render_postbox_open('Review Us');
        NMRichReviewsAdminHelper::insert_review_us();
        NMRichReviewsAdminHelper::render_postbox_close();

        NMRichReviewsAdminHelper::render_postbox_open('Nuanced Media');
        NMRichReviewsAdminHelper::render_nm_logos();
        NMRichReviewsAdminHelper::render_postbox_close();

	}

    public static function render_nm_logos() {
        ?>
            <div class="nm-logo one-fourth">
                <a href="http://nuancedmedia.com/" target="_blank">
                    <img src="http://nuancedmedia.com/wp-content/uploads/2014/04/nm-logo-black.png" />
                </a>
            </div>
            <div class="nm-social-media-links-container three-fourths">
                <div class="nm-social-media-link nm-facebook-link">

                </div>
                <div class="nm-social-media-link nm-google-plus-link">
                    <script>(function(d, s, id) {
                        var js, fjs = d.getElementsByTagName(s)[0];
                        if (d.getElementById(id)) {return;}
                        js = d.createElement(s); js.id = id;
                        js.src = "//connect.facebook.net/en_US/all.js#xfbml=1";
                        fjs.parentNode.insertBefore(js, fjs);
                    }(document, 'script', 'facebook-jssdk'));</script>
                    <div id="wp-meetup-social">
                        <div class="fb-like" data-href="https://www.facebook.com/NuancedMedia" data-send="false" data-layout="button_count" data-width="100" data-show-faces="true"></div><br><br>
                        <g:plusone annotation="inline" width="216" href="http://nuancedmedia.com/"></g:plusone><br>
                        <!-- Place this tag where you want the +1 button to render -->
                        <script type="text/javascript" src="https://apis.google.com/js/plusone.js"></script>
                    </div>
                </div>
            </div>
            <div class="clear"></div>
			<div class="google-plus-container" style="width: 100%; overflow: hidden;">
				<!-- Google Plus Link -->
				<script src="https://apis.google.com/js/platform.js" async defer></script>
				<div class="g-page" data-href="//plus.google.com/u/0/103543858548099057697" data-rel="publisher"></div>
			</div>
            <div class="nm-plugin-links">
                <?php NMRichReviewsAdminHelper::render_postbox_open('Rich Reviews Links') ?>
                <ul class="wp-meetup-link-list">
                    <li><a href="http://wordpress.org/extend/plugins/rich-reviews/" target="_blank">Wordpress.org Plugin Directory listing</a></li>
                    <li><a href="http://nuancedmedia.com/wordpress-rich-reviews-plugin/" target="_blank">Rich Reviews Plugin homepage</a></li>
                </ul>
                <?php NMRichReviewsAdminHelper::render_postbox_close(); ?>
            </div>
        <?php
    }

    public static function insert_review_us() {
        ?>
            <div class="review-us">
            <p>Tell us your opinion of the plugin. We are continuously working to improve your experience with the Rich Reviews and we can do that better if we know what you like and dislike. Let us know on the Wordpress <a href="http://wordpress.org/support/view/plugin-reviews/rich-reviews">Review Page</a>. </p>
            </div>
        <?php
    }



	public static function render_tabs($echo = TRUE) {
		/*
		 * key value pairs of the form:
		 * 'admin_page_slug' => 'Tab Label'
		 * where admin_page_slug is from
		 * the add_menu_page or add_submenu_page
		 */
		$tabs = array(
			'rich_reviews_settings_main' => 'Dashboard',
            'fp_admin_pending_reviews_page' => 'Pending Reviews',
            'fp_admin_approved_reviews_page' => 'Approved Reviews',
			'fp_admin_options_page' => 'Options',
			'fp_admin_add_edit' => 'Add Review',
		);

		// what page did we request?
		$current_slug = '';
		if (isset($_GET['page'])) {
			$current_slug = $_GET['page'];
		}

		// render all the tabs
		$output = '';
		$output .= '<div class="tabs-container">';
		foreach ($tabs as $slug => $label) {
			$output .= '<div class="tab ' . ($slug == $current_slug ? 'active' : '') . '">';
			$output .= '<a href="' . admin_url('admin.php?page='.$slug) . '">' . $label . '</a>';
			$output .= '</div>';
		}
		$output .= '</div>'; // end .tabs-container

		if ($echo) {
			echo $output;
		} else {
			return $output;
		}
	}

	public static function render_postbox_open($title = '') {
		?>
		<div class="postbox">
			<div class="handlediv" title="Click to toggle"><br/></div>
			<h3 class="hndle"><span><?php echo $title; ?></span></h3>
			<div class="inside">
		<?php
	}

	public static function render_postbox_close() {
		echo '</div>'; // end .inside
		echo '</div>'; // end .postbox
	}

	public static function render_container_open($extra_class = '', $echo = TRUE) {
		$output = '';
		$output .= '<div class="metabox-holder ' . $extra_class . '">';
		$output .= '  <div class="postbox-container wps-postbox-container">';
		$output .= '    <div class="meta-box-sortables ui-sortable">';

		if ($echo) {
			echo $output;
		} else {
			return $output;
		}
	}

	public static function render_container_close($echo = TRUE) {
		$output = '';
		$output .= '</div>'; // end .ui-sortable
		$output .= '</div>'; // end .nm-postbox-container
		$output .= '</div>'; // end .metabox-holder

		if ($echo) {
			echo $output;
		} else {
			return $output;
		}
	}

	public static function render_checkbox($name, $val_option, $key) {
		$checked = '';
		if ($val_option) {
			if (isset($val_option[$key]) && $val_option[$key] == TRUE) {
				$checked = 'checked';
			}
		}
		echo '<input type="checkbox" name="' . $name .'" value="checked" ' . $checked . '/>';
	}


	public static function insert_website_link() {
		?>
			<div class="website-link">
			<a href="http://plugins.nuancedmedia.com/wordpress-reviews-plugin/" target="_BLANK"><button style="padding: 13px; background-color: #9f06c6; border-radius: 5px; color: #ffffff; border: none; width: 100%;">Visit Plugins Website</button></a>
			<a href="http://plugins.nuancedmedia.com/installation-assistance/" target="_BLANK"><button style="padding: 13px; background-color: #069fc6; border-radius: 5px; color: #ffffff; border: none; width: 100%; margin-top: 13px;">Get Installation Assistance</button></a>
			</div>
		<?php
	}
}
