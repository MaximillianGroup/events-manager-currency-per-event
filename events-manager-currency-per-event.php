<?php
/**
 * Plugin Name: Events Manager - Currency Per Event
 * Plugin URI: http://www.andyplace.co.uk
 * Description: Plugin for Events Manager that allows the ticket currency to be configered per event.
 * Version: 1.4
 * Author: Andy Place
 * Author URI: http://www.andyplace.co.uk
 * License: GPL2
 */

// Hook the function to the plugins_loaded action
add_action('plugins_loaded', 'check_em_currencies_active');

// Function to check if Events Manager is active and if not, display an error notice
function check_em_currencies_active() {
    // Check if the function em_get_currencies exists
    if (!function_exists('em_get_currencies')) {
        // If not, hook the display_em_currency_error_notice function to the admin_notices action
        add_action('admin_notices', 'display_em_currency_error_notice');
    }
}

// Function to display an error notice if Events Manager is not active
function display_em_currency_error_notice() {
    // Define the error message
    $message = __('Please ensure Events Manager is enabled for the Currencies per Event plugin to work.', 'em-pro');
    // Output the error message within a div with the 'error' class
    echo '<div class="error"> <p>' . $message . '</p></div>';
}

/**
 * Add metabox to revents page editor that allows us to configure the currency
 */
function em_curr_adding_custom_meta_boxes( $post ) {

	add_meta_box(
		'em-event-currency',
		__( 'Currency' ),
		'render_curency_meta_box',
		'event',
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes_event', 'em_curr_adding_custom_meta_boxes', 10, 2 );


/**
 * Render metabox with currency options. The list was the same as those included with
 * Events Manager at the time of writing.
 * Note, this option is disabled when in Multiple Bookings mode
 */
function render_currency_meta_box() {
    global $post;

    // Get the list of currencies
    $currencies = em_get_currencies();

    // Check if multiple bookings mode is enabled
    if (get_option('dbem_multiple_bookings', 0)) {
        // If enabled, display a message and return
        echo('Currencies cannot be set per event when multiple bookings mode is enabled.');
        return;
    }

    // Get the currency value for the current event
    $curr_value = get_post_meta($post->ID, 'star_em_event_currency', true);

    // Output HTML content directly within PHP
    echo '<p><strong>';
    echo __('Default Currency', 'textdomain') . ': ' . esc_html(get_option('dbem_bookings_currency', 'USD'));
    echo '</strong></p>';
    echo '<p>';
    echo __('The currency for all events is configured under Events -> Settings -> Bookings -> Pricing Options.', 'textdomain');
    echo __('If you want this event to use a different currency to the above, select from the list below.', 'textdomain');
    echo '</p>';

    // Output currency dropdown
    echo '<select name="dbem_bookings_currency">';
    echo '<option value="">Use Default</option>';
    foreach ($currencies->names as $key => $currency) {
        echo '<option value="' . $key . '" ' . ($curr_value == $key ? 'selected="selected"' : '') . '>' . $currency . '</option>';
    }
    echo '</select>';
}

/**
 * Hook into front end event submission form and add currency fields
 */
function em_curr_front_event_form_footer() {
    global $post;

    // Get the list of currencies
    $currencies = em_get_currencies();

    // Check if multiple bookings mode is enabled
    if (get_option('dbem_multiple_bookings', 0)) {
        return;
    }

    // Get the currency value for the current event
    $curr_value = get_post_meta($post->ID, 'star_em_event_currency', true);

    // Get the default currency
    $default_currency = esc_html(get_option('dbem_bookings_currency', 'USD'));

    // Output HTML content directly within PHP
    echo '<h3>' . __('Event Currency', 'textdomain') . '</h3>';
    echo '<select name="dbem_bookings_currency">';
    echo '<option>' . $default_currency . ' - ' . $currencies->names[$default_currency] . '</option>';
    echo '<option disabled>------------------</option>';
    foreach ($currencies->names as $key => $currency) {
        echo '<option value="' . $key . '">' . $key . ' - ' . $currency . '</option>';
    }
    echo '</select>';
}
add_action('em_front_event_form_footer', 'em_curr_front_event_form_footer');


/**
 * Save currency option setting
 */
function em_curr_save_post($post_id, $post) {
    // Verify this came from our screen and with proper authorization,
    // because save_post can be triggered at other times
    $edit_event_nonce = isset($_POST['_emnonce']) ? $_POST['_emnonce'] : '';
    $wp_nonce = isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : '';

    if (!wp_verify_nonce($edit_event_nonce, 'edit_event') && !wp_verify_nonce($wp_nonce, 'wpnonce_event_save')) {
        return $post_id;
    }

    // Check if the user has the proper capabilities to edit the post
    if (!current_user_can('edit_post', $post_id)) {
        return $post_id;
    }

    // Skip saving data if post type is a revision
    if ($post->post_type == 'revision') {
        return $post_id;
    }

    // Check if the currency option is set in the POST data
    if (isset($_POST['dbem_bookings_currency'])) {
        // Update the post meta with the selected currency
        update_post_meta($post_id, 'star_em_event_currency', $_POST['dbem_bookings_currency']);
    } else {
        // If the currency option is not set, delete the post meta
        delete_post_meta($post_id, 'star_em_event_currency');
    }
}
add_action('save_post', 'em_curr_save_post', 10, 2);



/************ Modify Ticket price display ************/

/*
 * We can't access ticket info in the format price hook, so we need to store
 * globally the currency that is to be converted from an earlier hook where we can
 * see the event details
 */
$modify_currency = false; // Dubious global var that we use to get round filters limitation


/**
 * Hook into EM_Ticket->get_price() and detect if the event currency is non standard
 * Store the currency value into our global var if requried.
 */
function em_curr_ticket_get_price( $ticket_price, $EM_Ticket ) {
	global $modify_currency;

	$EM_Event = $EM_Ticket->get_event();

	// Does this event have a custom currency?
	if( get_post_meta( $EM_Event->post_id, 'star_em_event_currency', true ) ) {
		// If so we set this to our global $modify_currency var for use later on
		$modify_currency = get_post_meta( $EM_Event->post_id, 'star_em_event_currency', true );
	}
	return $ticket_price;
}
add_filter('em_ticket_get_price','em_curr_ticket_get_price', 10, 2);

/**
 * Hook into EM_Ticket_Booking->get_spaces() and detect if the event currency is non standard
 * Store the currency value into our global var if requried.
 * We use get_spaces as get_price doesn't have a hook we can use. A bit of a hack, but serve the
 * purpose for items like #_BOOKINGSUMMARY in event emails
 */
function em_curr_em_booking_get_spaces( $ticket_booking_spaces, $EM_Object ) {
	global $modify_currency;

	if( get_class( $EM_Object ) == "EM_Ticket_Booking" ) {
	  $EM_Event = $EM_Object->get_ticket()->get_event();

		// Does this event have a custom currency?
		if( get_post_meta( $EM_Event->post_id, 'star_em_event_currency', true ) ) {
			// If so we set this to our global $modify_currency var for use later on
			$modify_currency = get_post_meta( $EM_Event->post_id, 'star_em_event_currency', true );
		}
	}
	return $ticket_booking_spaces;
}
add_filter('em_booking_get_spaces','em_curr_em_booking_get_spaces', 10, 2);


/**
 * Hook into Events Manager's em_get_currency_formatted function
 * Modify currency symbol if determined previously that this needs changing
 */
function em_curr_get_currency_formatted($formatted_price, $price, $currency, $format) {
    global $modify_currency;

    // Check if currency modification is needed
    if ($modify_currency) {
        // Replace placeholders in the format string with currency symbol and formatted price
        $formatted_price = str_replace('@', em_get_currency_symbol(true, $modify_currency), $format);
        $formatted_price = str_replace('#', number_format($price, 2, get_option('dbem_bookings_currency_decimal_point', '.'), get_option('dbem_bookings_currency_thousands_sep', ',')), $formatted_price);
    }

    return $formatted_price;
}

// Add filter to modify currency formatting
add_filter('em_get_currency_formatted', 'em_curr_get_currency_formatted', 10, 4);

/************** Modify currency in booking admin ********************/

/**
 * Add our custom column for the total with the correct currency to the column template
 */
function em_curr_bookings_table_cols_template($cols_template) {
    if (is_admin()) {
        if (isset($cols_template['booking_price'])) {
            unset($cols_template['booking_price']);
        }
        $cols_template['booking_currency_price'] = 'Total';
    }
    return $cols_template;
}
add_filter('em_bookings_table_cols_template', 'em_curr_bookings_table_cols_template', 10, 1);


/**
 * Ensure that our custom column is actually included where required
 */
function em_curr_bookings_table($EM_Bookings_Table) {
    if (!in_array('booking_currency_price', $EM_Bookings_Table->cols)) {
        // Add the custom column to the table columns
        $EM_Bookings_Table->cols[] = 'booking_currency_price';

        // Reorder array so actions are at the end
        if (($key = array_search('actions', $EM_Bookings_Table->cols)) !== false) {
            unset($EM_Bookings_Table->cols[$key]);
            $EM_Bookings_Table->cols[] = 'actions';
        }
    }
}
// Hook into em_bookings_table action with a priority of 20 to ensure it runs after the table is created
add_action('em_bookings_table', 'em_curr_bookings_table', 20, 1);


/**
 * Deal with displaying the output of the total with correct currency in our custom column
 */
function em_curr_bookings_table_rows_col_booking_currency_price($val, $EM_Booking, $EM_Bookings_Table) {
    $EM_Event = $EM_Booking->get_event();

    if (get_post_meta($EM_Event->post_id, 'star_em_event_currency', true)) {
        $price = $EM_Booking->get_price(false);
        $currency = get_post_meta($EM_Event->post_id, 'star_em_event_currency', true);
        $format = get_option('dbem_bookings_currency_format', '@#');
        $formatted_price = str_replace('@', em_get_currency_symbol(true, $currency), $format);
        $formatted_price = str_replace('#', number_format($price, 2, get_option('dbem_bookings_currency_decimal_point', '.'), get_option('dbem_bookings_currency_thousands_sep', ',')), $formatted_price);
    } else {
        $formatted_price = $EM_Booking->get_price(true);
    }
    return $formatted_price;
}
// Hook into em_bookings_table_rows_col_booking_currency_price filter
add_filter('em_bookings_table_rows_col_booking_currency_price', 'em_curr_bookings_table_rows_col_booking_currency_price', 10, 3);

/************** Modify Currency for Payment Gateways ****************/

/**
 * Hook into Sage Pay gateway and modify the currency if set on the event
 */
function em_curr_gateway_sage_get_currency($currency, $EM_Booking) {
    // Skip if multi bookings is enabled
    if (get_option('dbem_multiple_bookings') == 1) {
        return $currency;
    }

    $EM_Event = $EM_Booking->get_event();
    if (get_post_meta($EM_Event->post_id, 'star_em_event_currency', true)) {
        $currency = get_post_meta($EM_Event->post_id, 'star_em_event_currency', true);
    }

    return $currency;
}
add_filter('em_gateway_sage_get_currency', 'em_curr_gateway_sage_get_currency', 10, 2);


/**
 * Hook into PayPal vars and modify currency if set on the event
 */
function em_curr_gateway_paypal_get_paypal_vars($paypal_vars, $EM_Booking, $EM_PayPal_Gateway) {
    // Skip if multi bookings is enabled
    if (get_option('dbem_multiple_bookings') == 1) {
        return $paypal_vars;
    }

    $EM_Event = $EM_Booking->get_event();
    if (get_post_meta($EM_Event->post_id, 'star_em_event_currency', true)) {
        $paypal_vars['currency_code'] = get_post_meta($EM_Event->post_id, 'star_em_event_currency', true);
    }

    return $paypal_vars;
}
add_filter('em_gateway_paypal_get_paypal_vars', 'em_curr_gateway_paypal_get_paypal_vars', 10, 3);


/**
 * Hook into PayPal Chained payments and set currency if configured for the booking event
 */
function em_curr_gateway_paypal_chained_paypal_request_data($paypal_request_data, $EM_Booking) {
    // Skip if multi bookings is enabled
    if (get_option('dbem_multiple_bookings') == 1) {
        return $paypal_request_data;
    }

    $EM_Event = $EM_Booking->get_event();
    if (get_post_meta($EM_Event->post_id, 'star_em_event_currency', true)) {
        $paypal_request_data['PayRequestFields']['CurrencyCode']
            = get_post_meta($EM_Event->post_id, 'star_em_event_currency', true);
    }

    return $paypal_request_data;
}
add_filter('em_gateway_paypal_chained_paypal_request_data', 'em_curr_gateway_paypal_chained_paypal_request_data', 10, 2);
