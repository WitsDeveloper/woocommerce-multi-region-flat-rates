<?php

/**
 * Plugin Name: Multi Region Flat Rates for WooCommerce
 * Plugin URI: http://www.witstechnologies.co.ke/projects/wp-plugins/woocommerce-multi-region-flat-rates
 * Description: This plugin allows you to set multiple region flat rates per Country on WooCommerce. The rates are managed under Delivery Areas and the shipping method is activated under WooCommerce shipping settings.
 * Version: 1.0
 * Requires at least: 5.2
 * Requires PHP: 7.2
 * Author: Sammy Waweru
 * Author URI: http://www.witstechnologies.co.ke
 * License: GPLv3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wmrfr
 **/


if (!defined('ABSPATH')) exit; // Exit if accessed directly

define('WMRFR_LINK', plugin_dir_url(__FILE__));
define('WMRFR_PATH', plugin_dir_path(__FILE__));

/**
 * Check if WooCommerce is active
 */
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

 function wmrfr_shipping_method_init() {
  if (!class_exists('WC_Multi_Region_Flat_Rates')) {
   class WC_Multi_Region_Flat_Rates extends WC_Shipping_Method {
    /**
     * Constructor for your shipping class
     *
     * @access public
     * @return void
     */
    public function __construct() {
     $this->id                 = 'multi_region_flat_rates'; // Unique ID for this shipping method
     $this->method_title       = __('Multi Region Flat Rates');  // Title shown in admin
     $this->method_description = __('This plugin allows you to set multiple region flat rates per Country on WooCommerce.'); // Description shown in admin

     $this->title              = "Multi Region Flat Rates"; // Title as seen on shipping methods

     $this->init();
    }

    /**
     * Init your settings
     *
     * @access public
     * @return void
     */
    function init() {
     // Load the settings API
     $this->init_form_fields(); // This is part of the settings API. Override the method to add your own settings
     $this->init_settings(); // This is part of the settings API. Loads settings you previously init.

     $this->enabled  = $this->get_option('enabled');
     $this->title  = $this->get_option('title');
     $this->tax_status = $this->get_option('tax_status');

     // Save settings page
     add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    /* The form */
    function init_form_fields() {
     $this->form_fields = array(
      'enabled' => array(
       'title'   => __('Enable', 'wmrfr'),
       'type'   => 'checkbox',
       'label'   => __('Enable this shipping method', 'wmrfr'),
       'default'  => 'no',
       'desc_tip'  => true
      ),
      'title' => array(
       'title'   => __('Method Title', 'wmrfr'),
       'type'   => 'text',
       'description' => __('This controls the title which the user sees during checkout.', 'wmrfr'),
       'default'  => __('Flat Rate per Country/Region', 'wmrfr'),
       'desc_tip'  => true
      ),
      'tax_status' => array(
       'title'   => __('Tax Status', 'wmrfr'),
       'type'   => 'select',
       'description' => '',
       'default'  => 'taxable',
       'options'  => array(
        'taxable' => __('Taxable', 'wmrfr'),
        'none'  => __('None', 'wmrfr'),
       ),
       'desc_tip'  => true
      )
     );
    }

    /**
     * calculate_shipping function.
     *
     * @access public
     * @param mixed $package
     * @return void
     */
    public function calculate_shipping($package = array()) {
     global $wpdb, $woocommerce;

     $cust_shipping_country = $woocommerce->customer->get_shipping_country();
     $cust_shipping_address = $woocommerce->customer->get_shipping_address();
     $cust_shipping_state = $woocommerce->customer->get_shipping_state();
     $cust_shipping_city = $woocommerce->customer->get_shipping_city();

     if (!empty($cust_shipping_country) && !empty($cust_shipping_address) && !empty($cust_shipping_city)) {
      $fetch_by_country =  $wpdb->get_results($wpdb->prepare("SELECT `post_id` FROM `" . $wpdb->postmeta . "` WHERE `meta_key` = %s AND `meta_value` = %s", 'region_country', $cust_shipping_country));
      if (!empty($fetch_by_country) && isset($fetch_by_country)) {
       foreach ($fetch_by_country as $meta) {
        $postID = intval($meta->post_id);
        //var_dump($metaID);								
        // We have two conditions to capture
        // 1. Options where delivery applies to all towns in a given country								
        $fetch_by_all = $wpdb->get_results($wpdb->prepare("SELECT `post_id` FROM `" . $wpdb->postmeta . "` WHERE `post_id` = %d AND `meta_key` = %s AND `meta_value` = %s", $postID, 'region_destination', '*'));
        //var_dump($fetch_by_all);
        if (!empty($fetch_by_all) && isset($fetch_by_all)) {
         foreach ($fetch_by_all as $all) {
          $get_post_array[] = $all->post_id;
         }
        }

        // 2. Options for city filled in by customer
        $fetch_by_city = $wpdb->get_results($wpdb->prepare("SELECT `post_id` FROM `" . $wpdb->postmeta . "` WHERE `post_id` = %d AND `meta_key` = %s AND `meta_value` = %s", $postID, 'region_destination', $cust_shipping_city));
        //var_dump($fetch_by_city);							
        if (!empty($fetch_by_city) && isset($fetch_by_city)) {
         foreach ($fetch_by_city as $cities) {
          $get_post_array[] = $cities->post_id;
         }
        }
       }
      }
     }

     //reset $postID for use down here
     $postID = 0;
     //var_dump($get_post_array);
     // We only need unique Post IDs
     $unique_post_array = array_unique($get_post_array);

     // If we have unique post IDs returned, loop as you add their rates to array
     if (!empty($unique_post_array)) {
      foreach ($unique_post_array as $postID) {
       $postID = intval($postID);
       $region_carrier = get_post_meta($postID, 'region_carrier', true);
       $region_days = get_post_meta($postID, 'region_days', true);
       $region_rate_door = get_post_meta($postID, 'region_rate_door', true);
       $region_rate_pickup = get_post_meta($postID, 'region_rate_pickup', true);

       if (!empty($region_rate_door) && $region_rate_door > 0) {

        $rate = array(
         'id'  => $postID,
         'label' => $region_carrier . " - To Door - " . $region_days . " day(s) - Shipping",
         'cost'  => $region_rate_door,
         'calc_tax' => 'per_order'
        );
        // Register the rate (to door)
        $this->add_rate($rate);
       }

       if (!empty($region_rate_pickup) && $region_rate_pickup > 0) {

        $rate = array(
         'id'  => $postID . "0",
         'label' => $region_carrier . " - Pickup - " . $region_days . " day(s) - Shipping",
         'cost'  => $region_rate_pickup,
         'calc_tax' => 'per_order'
        );
        // Register the rate (pickup)
        $this->add_rate($rate);
       }
      }
     } else {
      $is_available = false;
      return apply_filters('woocommerce_shipping_' . $this->id . '_is_available', $is_available, $package);
     }
    }
   }
  }
 }

 add_action('woocommerce_shipping_init', 'wmrfr_shipping_method_init');

 function add_multi_region_flat_rates($methods) {
  $methods['multi_region_flat_rates'] = 'WC_Multi_Region_Flat_Rates';
  return $methods;
 }

 add_filter('woocommerce_shipping_methods', 'add_multi_region_flat_rates');

 /**
  * Register a new Post Type
  *
  * Register new post type 
  */
 function wmrfr_register_post_type() {
  $singular = "Delivery Area";
  $plural = "Delivery Areas";

  $labels = array(
   'name'      => $plural,
   'singular_name'   => $singular,
   'all_items'    => 'All ' . $plural,
   'add_name'     => 'Add New',
   'add_new_item'    => 'Add New ' . $singular,
   'edit'      => 'Edit',
   'edit_item'    => 'Edit ' . $singular,
   'new_item'     => 'New ' . $singular,
   'view'      => 'View ' . $singular,
   'view_item'    => 'View ' . $singular,
   'search_term'    => 'Search ' . $plural,
   'parent'     => 'Parent ' . $singular,
   'not_found'    => 'No ' . $plural . ' found',
   'not_found_in_trash'  => 'No ' . $plural . ' found in Trash',

  );
  $args = array(
   'labels'    => $labels,
   'description'   => __('This allows you to manage the shipping rates for use with ' . $plural . '. Only works with WooCommerce.', 'wmrfr'),
   'public'             => true,
   'publicly_queryable' => true,
   'show_ui'    => true,
   'show_in_nav_menus'  => false,
   'show_in_menu'   => true,
   'query_var'    => true,
   'rewrite'    => array('slug' => 'wc-regional-rates'),
   'capability_type'  => 'post',
   'menu_position'   => 50,
   'menu_icon'    => 'dashicons-location-alt',
   'supports'    => array('title')

  );

  register_post_type('wc-regional-rates', $args);
 }
 add_action('init', 'wmrfr_register_post_type');

 /**
  * Add a custom metabox
  *
  * Create metabox to hold fields
  */
 function wmrfr_add_custom_metabox() {
  add_meta_box(
   'wmrfr_meta',
   'Delivery Area',
   'wmrfr_meta_callback',
   'wc-regional-rates',
   'normal',
   'high'
  );
 }
 add_action('add_meta_boxes', 'wmrfr_add_custom_metabox');

 /**
  * Prints the box content.
  * 
  * @param WP_Post $post The object for the current post/page.
  */
 function wmrfr_meta_callback($post) {
  global $woocommerce;

  //wp_nonce_field('wmrfr_138cj347cs1ps8', 'wmrfr_nonce_data');
  $meta_values = get_post_meta($post->ID);

  $region_carrier = isset($meta_values['region_carrier'][0]) ? $meta_values['region_carrier'][0] : '';
  $region_country = isset($meta_values['region_country'][0]) ? $meta_values['region_country'][0] : '';
  $region_days = isset($meta_values['region_days'][0]) ? $meta_values['region_days'][0] : '';
  $region_destination = isset($meta_values['region_destination'][0]) ? $meta_values['region_destination'][0] : '*';
  $region_branch = isset($meta_values['region_branch'][0]) ? $meta_values['region_branch'][0] : '';
  $region_rate_door = isset($meta_values['region_rate_door'][0]) ? $meta_values['region_rate_door'][0] : 0;
  $region_rate_pickup = isset($meta_values['region_rate_pickup'][0]) ? $meta_values['region_rate_pickup'][0] : 0;
  $region_contact = isset($meta_values['region_contact'][0]) ? $meta_values['region_contact'][0] : '';
  $region_delivery_method = isset($meta_values['region_delivery_method'][0]) ? $meta_values['region_delivery_method'][0] : '';
?>
  <div class="settings-tab metaboxes-tab">
   <div class="meta-row" style="padding:0 12px;">
    <div class="the-metabox text region_carrier clearfix">
     <label for="region_carrier" class="row-region_carrier"><?php _e('Carrier', 'wmrfr'); ?></label>
     <p><input type="text" name="region_carrier" id="region_carrier" value="<?php echo esc_attr($region_carrier); ?>"></p>
    </div>

    <div class="the-metabox text region_country clearfix">
     <label for="region_country" class="row-region_country"><?php _e('Country', 'wmrfr'); ?></label>
     <?php
     woocommerce_form_field(
      'shipping_country',
      array(
       'type'   => 'country',
       'class'         => array('chosen_select'),
       'default'      => esc_attr($region_country),
      ),
      esc_attr($region_country)
     ); ?>
    </div>

    <div class="the-metabox number region_days clearfix">
     <label for="region_days" class="row-region_days"><?php _e('Delivery Days', 'wmrfr'); ?></label>
     <p><input type="text" name="region_days" id="region_days" placeholder="<?php _e('Example: 7 - 10', 'wmrfr'); ?>" value="<?php echo esc_attr($region_days); ?>"></p>
    </div>

    <div class="the-metabox text region_destination clearfix">
     <label for="region_destination" class="row-region_destination"><?php _e('Destination (* for all regions in selected country)', 'wmrfr'); ?></label>
     <p><input type="text" name="region_destination" id="region_destination" placeholder="<?php _e('Example: * for all', 'wmrfr'); ?>" value="<?php echo esc_attr($region_destination); ?>"></p>
    </div>

    <div class="the-metabox text region_branch clearfix">
     <label for="region_branch" class="row-region_branch"><?php _e('Branch (warehouse)', 'wmrfr'); ?></label>
     <p><input type="text" name="region_branch" id="region_branch" value="<?php echo esc_attr($region_branch); ?>"></p>
    </div>

    <div class="the-metabox number region_rate_door clearfix">
     <label for="region_rate_door" class="row-region_rate_door"><?php _e('Rate to Door', 'wmrfr'); ?></label>
     <p><input type="number" step="any" min="-1" name="region_rate_door" id="region_rate_door" value="<?php echo esc_attr($region_rate_door); ?>"></p>
    </div>

    <div class="the-metabox number region_rate_pickup clearfix">
     <label for="region_rate_pickup" class="row-region_rate_pickup"><?php _e('Rate to Pickup', 'wmrfr'); ?></label>
     <p><input type="number" step="any" min="-1" name="region_rate_pickup" id="region_rate_pickup" value="<?php echo esc_attr($region_rate_pickup); ?>"></p>
    </div>

    <div class="the-metabox text region_contact clearfix">
     <label for="region_contact" class="row-region_contact"><?php _e('Region Contact', 'wmrfr'); ?></label>
     <p><input type="text" name="region_contact" id="region_contact" value="<?php echo esc_attr($region_contact); ?>"></p>
    </div>

    <div class="the-metabox text region_delivery_method clearfix">
     <label for="region_delivery_method" class="row-region_delivery_method"><?php _e('Delivery Method', 'wmrfr'); ?></label>
     <p><input type="text" name="region_delivery_method" id="region_delivery_method" value="<?php echo esc_attr($region_delivery_method); ?>"></p>
    </div>

   </div>
  </div>
<?php
 }

 /**
  * When the post is saved, saves our custom data.
  *
  * @param int $post_id The ID of the post being saved.
  */
 function wmrfr_save_meta_box_data($post_id) {
  // Check save status
  $is_autosave = wp_is_post_autosave($post_id);
  $is_revision = wp_is_post_revision($post_id);

  // If this is an autosave, our form has not been submitted, so we don't want to do anything.
  if ($is_autosave || $is_revision) {
   return;
  }

  // Check the user's permissions.
  /*
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		*/

  // Make sure that it is set.
  if (!isset($_POST['region_carrier'])) {
   return;
  }

  /* OK, it's safe for us to save the data now. */
  update_post_meta($post_id, 'region_carrier', sanitize_text_field($_POST['region_carrier']));
  update_post_meta($post_id, 'region_country', sanitize_text_field($_POST['shipping_country']));
  update_post_meta($post_id, 'region_days', sanitize_text_field($_POST['region_days']));
  update_post_meta($post_id, 'region_destination', sanitize_text_field($_POST['region_destination']));
  update_post_meta($post_id, 'region_branch', sanitize_text_field($_POST['region_branch']));
  update_post_meta($post_id, 'region_rate_door', sanitize_text_field($_POST['region_rate_door']));
  update_post_meta($post_id, 'region_rate_pickup', sanitize_text_field($_POST['region_rate_pickup']));
  update_post_meta($post_id, 'region_contact', sanitize_text_field($_POST['region_contact']));
  update_post_meta($post_id, 'region_delivery_method', sanitize_text_field($_POST['region_delivery_method']));
 }
 add_action('save_post', 'wmrfr_save_meta_box_data');

 /**
  * Add new columns to the post table
  *
  * @param Array $columns - Current columns on the list post
  */
 function wmrfr_add_new_columns($columns) {

  $date = $columns['date'];
  unset($columns['date']);
  $columns["region_carrier"] = "Carrier";
  $columns["region_country"] = "Country";
  $columns["region_days"] = "Days";
  $columns["region_destination"] = "Destination";
  $columns["region_branch"] = "Branch";
  $columns["region_rate_door"] = "Rate to Door";
  $columns["region_rate_pickup"] = "Rate to Pickup";
  $columns["region_contact"] = "Region Contact";
  $columns["region_delivery_method"] = "Delivery Method";

  return $columns;
 }
 add_filter('manage_edit-wc-regional-rates_columns', 'wmrfr_add_new_columns');
 add_filter('manage_edit-wc-regional-rates_sortable_columns', 'wmrfr_add_new_columns');

 /**
  * Apply Sorting
  *
  * The ASC and DESC part of ORDER BY is handled automatically
  *
  */
 function wmrfr_sort_metabox($vars) {
  if (array_key_exists('orderby', $vars)) {
   /*
		   if('Carrier' == $vars['orderby']) {
				$vars['orderby'] = 'meta_value';
				$vars['meta_key'] = 'region_carrier';
		   }
		   */

   switch ($vars['orderby']) {
    case 'Carrier':
     $vars['orderby'] = 'meta_value';
     $vars['meta_key'] = 'region_carrier';
     break;
    case 'Country':
     $vars['orderby'] = 'meta_value';
     $vars['meta_key'] = 'region_country';
     break;
    case 'Days':
     $vars['orderby'] = 'meta_value';
     $vars['meta_key'] = 'region_days';
     break;
    case 'Destination':
     $vars['orderby'] = 'meta_value';
     $vars['meta_key'] = 'region_destination';
     break;
    case 'Branch':
     $vars['orderby'] = 'meta_value';
     $vars['meta_key'] = 'region_branch';
     break;
    case 'RatetoDoor':
     $vars['orderby'] = 'meta_value';
     $vars['meta_key_num'] = 'region_rate_door';
     break;
    case 'RatetoPickup':
     $vars['orderby'] = 'meta_value';
     $vars['meta_key_num'] = 'region_rate_pickup';
     break;
    case 'RegionContact':
     $vars['orderby'] = 'meta_value';
     $vars['meta_key'] = 'region_contact';
     break;
    case 'TrackType':
     $vars['orderby'] = 'meta_value';
     $vars['meta_key'] = 'region_delivery_method';
     break;
   }
  }

  return $vars;
 }
 add_filter('request', 'wmrfr_sort_metabox');

 /**
  * Display data in new columns
  *
  * @param  $column Current column
  *
  * @return Data for the column
  */
 function wmrfr_custom_columns($column) {
  global $post;

  switch ($column) {
   case 'region_carrier':
    echo get_post_meta($post->ID, 'region_carrier', true);
    break;
   case 'region_country':
    $coutryCode = get_post_meta($post->ID, 'region_country', true);
    echo $coutryCode;
    break;
   case 'region_days':
    echo get_post_meta($post->ID, 'region_days', true);
    break;
   case 'region_destination':
    echo get_post_meta($post->ID, 'region_destination', true);
    break;
   case 'region_branch':
    echo get_post_meta($post->ID, 'region_branch', true);
    break;
   case 'region_rate_door':
    $region_rate_door = get_post_meta($post->ID, 'region_rate_door', true);
    echo (float)$region_rate_door;
    break;
   case 'region_rate_pickup':
    $region_rate_pickup = get_post_meta($post->ID, 'region_rate_pickup', true);
    echo (float)$region_rate_pickup;
    break;
   case 'region_contact':
    echo get_post_meta($post->ID, 'region_contact', true);
    break;
   case 'region_delivery_method':
    echo get_post_meta($post->ID, 'region_delivery_method', true);
    break;
  }
 }
 add_action('manage_wc-regional-rates_posts_custom_column', 'wmrfr_custom_columns');
}
