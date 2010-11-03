<?php

/**
 * Ways to test and play with this class:
 * 1) Create a new term at by selecting Editorial Metadata from the Edit Flow settings
 * 2) Edit an existing term (slug, description, etc.)
 * 3) Create a post and assign metadata to it
 * 4) Look at the list of terms again - the count should go up!
 * 5) Play with adding more metadata to a post
 * 6) Clear the metadata for a single term in a post and watch the count go down!
 * 6) Delete a term and note the metadata disappears from posts
 * 7) Re-add the term (same slug) and the metadata returns!
 * 
 * A bunch of TODOs
 * TODO: Fully document this class.
 * TODO: Add ability for drag-drop of metadata terms?
 * TODO: Add ability to specify "due date" in settings based on one of the date metadata fields? Then the calendar could use that again.
 */
class EF_Editorial_Metadata {

	/**
	 * The name of the taxonomy we're going to register for editorial metadata. This could be a
	 * const, but then it would be harder to use in PHP strings, so we'll keep it as a variable.
	 */
	var $metadata_taxonomy;
	var $metadata_postmeta_key;
	var $metadata_string;
	var $screen_id;
	
	/**
	 * A cache of the last metadata type that was seen or used. This is used to persist state between the
	 * pre_edit_term and edited_term methods below.
	 */
	var $metadata_type_cache;
	
	var $metadata_slug_cache;

	const description = 'desc';
	const metadata_type_key = 'type';
	
	function __construct() {
		$this->metadata_taxonomy = 'ef_editorial_meta';
		$this->screen_id = "edit-{$this->metadata_taxonomy}";
		$this->metadata_postmeta_key = "_{$this->metadata_taxonomy}";
		$this->metadata_string = __( 'Metadata Type', 'edit-flow' );
		
		add_action( 'init', array( &$this, 'register_taxonomy' ) );
		add_action( 'admin_init', array( &$this, 'handle_post_metaboxes' ) );
		add_action( 'admin_init', array( &$this, 'metadata_taxonomy_display_hooks' ) );
		
		// Load necessary scripts and stylesheets
		add_action( 'admin_enqueue_scripts', array( &$this, 'add_admin_scripts' ) );
	}
	
	function metadata_taxonomy_display_hooks() {
		global $pagenow;
		
		if ( $pagenow == 'edit-tags.php' ) {
			// Specify a particular ordering of rows for the post metadata taxonomy page 
			add_filter( "get_terms_orderby", array( &$this, 'order_metadata_rows' ), 10, 2 );
			
			// Insert and remove some fields when adding or removing terms from the post metadata taxonomy edit page
			add_action( "{$this->metadata_taxonomy}_add_form_fields", array( &$this, "add_form_fields" ) );
			add_action( "{$this->metadata_taxonomy}_edit_form_fields", array( &$this,"edit_form_fields" ), 10, 2 );
		}
		
		// Adding a term happens via admin-ajax.php, so make sure we copy the metadata_type into description then too
		if ( $pagenow == 'edit-tags.php' || $pagenow == 'admin-ajax.php' ) {
			// Edit the columns for the post metadata taxonomy page (remove the description, add the post metadata type)
			add_filter( "manage_edit-{$this->metadata_taxonomy}_columns", array( &$this, "edit_column_headers" ) );
			add_filter( "manage_{$this->metadata_taxonomy}_custom_column", array( &$this, "add_custom_columns" ), 10, 3 );
			
			add_filter( "pre_{$this->metadata_taxonomy}_description", array( &$this, "insert_metadata_into_description_field" ) );
			
			// Enforce that a metadata slug cannot be change once the term is created
			// We could use edit_{$taxonomy}, but then the value returned by AJAX call on quick edits would still be changed
			add_action( "edit_terms", array( &$this, "pre_edit_term" ) );
			add_action( "edited_terms", array( &$this, "edited_term" ) );
			
			// Enforce that a metadata type cannot be changed once the term is created
			add_action( "edit_term_taxonomy", array( &$this, "pre_edit_term_taxonomy" ), 10, 2);
			add_action( "edited_term_taxonomy", array( &$this, "edited_term_taxonomy" ), 10, 2);
		}
	}
	
	function pre_edit_term( $term_id ) {
		$term = get_term( $term_id, $this->metadata_taxonomy );
		if ( !is_null( $term ) ) {
			// We'll only get a non-null result if we're editing a editorial_meta term (since that's the taxonomy we pass above)
			$this->metadata_slug_cache = $term->slug;
		}
	}
	
	function edited_term( $term_id ) {
		global $wpdb;
		$term = get_term( $term_id, $this->metadata_taxonomy );
		if ( !is_null( $term ) ) {
			// As above, we'll only get a non-null result if we're editing a editorial_meta term (since that's the taxonomy we pass above)
			// Switch back to the cached slug before the attempted update
			$wpdb->update( $wpdb->terms, array( 'slug' => $this->metadata_slug_cache ), compact( 'term_id' ) );
		}
	}
	
	function pre_edit_term_taxonomy( $tt_id, $taxonomy ) {
		if ( $taxonomy === $this->metadata_taxonomy ) {
			global $wpdb;
			
			// TODO: Is get_row the right function to use? Can this be done with a $wpdb function rather than a custom query?
			$desc = $wpdb->get_row( $wpdb->prepare( "SELECT description FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d AND taxonomy = %s", $tt_id, $taxonomy ) )->description;
			$this->metadata_type_cache = $this->get_unserialized_value( $desc, self::metadata_type_key );
		}
	}
	
	function edited_term_taxonomy( $tt_id, $taxonomy ) {
		if ( $taxonomy === $this->metadata_taxonomy ) {
			global $wpdb;
			
			// Get newly saved metadata type
			// TODO: Same as above - can this be done better?
			$serialized_description = $wpdb->get_row( $wpdb->prepare( "SELECT description FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d AND taxonomy = %s", $tt_id, $taxonomy ) )->description;
			
			// If the new type is different from the old type, we need to revert
			if ( $this->metadata_type_cache !== $this->get_unserialized_value( $serialized_description, self::metadata_type_key ) ) {
				$metadata_description = $this->get_unserialized_value( $serialized_description, self::description );
				$updated_serialized_description = $this->get_serialized_description( $metadata_description, $this->metadata_type_cache );
				
				// Revert term type back to old type
				$wpdb->update( $wpdb->term_taxonomy, array( 'description' => $updated_serialized_description ), array( 'term_taxonomy_id' => $tt_id ) );
			} else {
				// Metadata type hasn't changed, so do nothing
			}
		}
		$this->metadata_type_cache = NULL;
	}
	
	function insert_metadata_into_description_field( $description ) {
		$field_prefix = $this->metadata_taxonomy . '_';
		$metadata_type = isset( $_POST[$field_prefix . self::metadata_type_key] ) ? $_POST[$field_prefix . self::metadata_type_key] : '';
		if ( isset( $_POST[$field_prefix . self::description] ) ) {
			$metadata_description = $_POST[$field_prefix . self::description];
		} else if ( $_POST['action'] == 'add-tag' ) {
			// If the posted metadata description is empty, use the given description
			// This code path is executed when adding a term, but should not be executed when editing a term
			$metadata_description = $description;
		} else if ( $_POST['action'] == 'inline-save-tax' ) {
			// This code path is executing when quick editing a term, in which case we have a slashed version of the current description
			$metadata_description = $this->get_unserialized_value( $description, self::description );
		}
		return $this->get_serialized_description( $metadata_description, $metadata_type );
	}
	
	function get_serialized_description( $metadata_description, $metadata_type ) {
		// Escape any special characters (', ", <, >, &)		
		$metadata_description = esc_attr( $metadata_description );
		return addslashes( serialize( array( self::description			=> $metadata_description,
		                                     self::metadata_type_key	=> $metadata_type,
		                                    )
		                             ) );
	}
	
	function add_form_fields($taxonomy) {
	?>
		<div class="form-field">
			<label for="<?php echo $this->metadata_taxonomy . '_' . self::metadata_type_key;; ?>"><?php echo $this->metadata_string; ?></label>
			<?php $this->get_select_html(0); ?>
			<p>Choose which type of metadata you would like to create.</p>
		</div>
		
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				// Inform users with text added to the deletion JS confirm dialog that their postmeta isn't going
				// anywhere but they have to re-add the deleted term if they want to see it
				
				// TODO: make this localizable!
				var msg = "\n\nAny metadata for this term will remain but will not be visible unless this term is re-added.";
				commonL10n.warnDelete += msg; // This is the string in the DOM shown on deletion
				
				<?php if ( isset($_GET['message']) && ( $msg = (int) $_GET['message'] ) && ( $msg === 2 || $msg === 6 ) ) : ?>
					var msgSingleTerm = "Any metadata for the deleted term will remain but will not be visible unless the term is re-added.";
					var msgMultipleTerms = "Any metadata for the deleted terms will remain but will not be visible unless the terms are re-added.";
					<?php if ( $msg === 2 ) : ?>
						var msg = msgSingleTerm;
					<?php elseif ( $msg === 6 ) : ?>
						var msg = msgMultipleTerms;
					<?php endif; ?>
					jQuery("#message p").append(" " + msg);
				<?php endif; ?>
			});
		</script>
	<?php
	}
	
	function edit_form_fields( $term, $taxonomy ) {
		// We need to add a new textarea for description that is just like the default one but that contains the right name, ID, and content
		// The default one would have ugly serialized data in it.
		$field_prefix = $this->metadata_taxonomy . '_';
		$metadata_types = $this->get_supported_metadata_types();
		$type = $this->get_metadata_type( $term );
		// For some reason the description's HTML is encoded when we get it as an object
		$description = $this->get_unserialized_value( $term->description, self::description );
		?>
		<tr class="form-field form-required">
			<th scope="row" valign="top"><label for="<?php echo $field_prefix . self::description; ?>"><?php _ex('Description', 'Taxonomy Description'); ?></label></th>
			<td>
				<textarea rows="5" cols="40" name="<?php echo $field_prefix . self::description; ?>" id="<?php echo $field_prefix . self::description; ?>"><?php
						echo $description;
					?></textarea><br />
				<span class="description">The description is not prominent by default, however some themes may show it.</span>
			</td>
		</tr>
		<?php
		// People could try to change the value of the hidden field below (the metadata type), but they'd get nowhere as
		// pre_edit_term and edited_term will stop them in their tracks!
		?>
		<tr class="form-field">
			<th scope="row" valign="top"><?php _e('Type', 'edit-flow'); ?></th>
			<td>
				<input type="text" disabled="disabled" value="<?php echo $metadata_types[$type]; ?>" /><br />
				<span class="description">The metadata type cannot be changed once created.</span>
			</td>
		</tr>
		<input type="hidden" name="<?php echo $this->metadata_taxonomy . '_' . self::metadata_type_key; ?>" value="<?php echo $type; ?>" />
	<?php
	}
	
	function get_select_html( $description ) {
		$current_metadata_type = $this->get_metadata_type( $description );
		$metadata_types = $this->get_supported_metadata_types();
		?>
		<select id="<?php echo $this->metadata_taxonomy . '_' . self::metadata_type_key; ?>" name="<?php echo $this->metadata_taxonomy . '_' . self::metadata_type_key; ?>">
		<?php foreach ( $metadata_types as $metadata_type => $metadata_type_name ) : ?>
			<option value="<?php echo $metadata_type; ?>" <?php selected( $metadata_type, $current_metadata_type ); ?>><?php echo $metadata_type_name; ?></option>
		<?php endforeach; ?>
		</select>
	<?php
	}
	
	function get_supported_metadata_types() {
		return array( 'checkbox' 	=> __('Checkbox', 'edit-flow'),
					  'date' 		=> __('Date', 'edit-flow'),
					  'location' 	=> __('Location', 'edit-flow'),
					  'paragraph' 	=> __('Paragraph', 'edit-flow'),
					  'text' 		=> __('Text', 'edit-flow'),
					  'user'		=> __('User', 'edit-flow'),
					);
	}
	
	function edit_column_headers( $column_headers ) {
		// TODO: implement this using array_diff or array_unshift or something better?
		$new_headers = array();
		// Don't display the 'slug' column
		unset( $column_headers['slug'] );
		foreach ( $column_headers as $column_name => $column_display_name ) {
			if ( $column_name == 'description' ) {
				// Put the new columns in the place of description
				$new_headers[self::metadata_type_key] = $this->metadata_string;
				$new_headers[self::description] = 'Description';
			} else {
				$new_headers[$column_name] = $column_display_name;
			}
		}
		
		return $new_headers;
	}
	
	function add_custom_columns( $empty_string, $column_name, $term_id ) {
		// Get the full description from the DB and unserialize into an array
		$term = $this->get_editorial_metadata_term( (int) $term_id );
		$term_description = maybe_unserialize( $term->description );
		
		// Display the information from the DB for this row to the user for our custom columns
		if ( $column_name == self::metadata_type_key ) {
			// Return the display (pretty) type for the metadata. e.g. Location instead of location
			$metadata_types = $this->get_supported_metadata_types();
			return $metadata_types[$term_description[self::metadata_type_key]];
		} else if ( $column_name == self::description ) {
			return stripslashes( $term_description[self::description] );
		}
	}
	
	function add_admin_scripts() {
		global $current_screen;
	//	if ( $current_screen->id == 'post' ) {
			wp_enqueue_script('edit_flow-date-lib', EDIT_FLOW_URL . 'js/lib/date.js', array(), false, true);
			wp_enqueue_script('edit_flow-date_picker-lib', EDIT_FLOW_URL . 'js/lib/jquery.datePicker.js', array( 'jquery' ), false, true);
			?>
			<script type="text/javascript">
				Date.firstDayOfWeek = <?php echo get_option( 'start_of_week' ); ?>;
			</script>
			<?php
			wp_enqueue_script('edit_flow-date_picker', EDIT_FLOW_URL . 'js/ef_date.js', array( 'edit_flow-date_picker-lib', 'edit_flow-date-lib' ), false, true);
		// }
		
		// Either editing the taxonomy or a specific term
		if ( $current_screen->id == $this->screen_id ) {
			wp_enqueue_script('edit_flow-editorial_metadata', EDIT_FLOW_URL . 'js/ef_editorial_metadata.js', array( 'jquery' ), false, true);
		}
		
		if ( $current_screen->id == 'post' ) {
			wp_enqueue_style('edit_flow-datepicker-styles', EDIT_FLOW_URL . 'css/datepicker-editflow.css', false, false, 'all');
			wp_enqueue_style('edit_flow-editorial_metadata-styles', EDIT_FLOW_URL . 'css/ef_editorial_metadata.css', false, false, 'all');
		}
	}
	
	/**
	 * Gets the metadata type described by this term, stored in the term itself. Usually stored in $term->description.
	 *
	 * @param object|string|int term Term from which to get the metadata object (object or term_id) or the metadata type itself.
	 */
	function get_metadata_type($term) {
		$metadata_type = '';
		if ( is_object( $term ) ) {
			$metadata_type = $term->description;
		} else if ( is_int( $term ) && $term > 0 ) {
			$metadata_type = get_term_by( 'term_id', $term->term_id, $this->metadata_taxonomy )->description;
		} else {
			$metadata_type = $term;
		}
		return $this->get_unserialized_value( $metadata_type, self::metadata_type_key );
	}
	
	function get_unserialized_value( $string_to_unserialize, $key ) {
		$string_to_unserialize = htmlspecialchars_decode( $string_to_unserialize );
		$unserialized_array = maybe_unserialize( $string_to_unserialize );
		if ( is_array( $unserialized_array ) ) {
			return stripslashes( $unserialized_array[$key] );
		} else {
			return $string_to_unserialize;
		}
	}
	
	function order_metadata_rows($orderby, $args) {
		global $current_screen;
		
		// TODO: add following check in other methods (if possible)
		if ( $current_screen->id == "edit-{$this->metadata_taxonomy}" ) // only sort by description when editing metadata
			return apply_filters( 'ef_editorial_metadata_sort_order', 'name' );
		else // TODO: is this needed if the orderby filter were only added on the metadata screen? (it isn't now, it's on all edit-tags screens, but maybe it could be)
			return $orderby;
	}
	
	// -------------------------
	// Register the post metadata taxonomy and add some default terms
	// -------------------------
	
	function register_taxonomy() {
		register_taxonomy( $this->metadata_taxonomy, array( 'post' ),
			array(
				'public' => false,
				'labels' => array(
					'name' => _x( 'Editorial Metadata', 'taxonomy general name' ),
					'singular_name' => _x( 'Editorial Metadata', 'taxonomy singular name' ),
						'search_items' => __( 'Search Editorial Metadata', 'edit-flow' ),
						'popular_items' => __( 'Popular Editorial Metadata', 'edit-flow' ),
						'all_items' => __( 'All Editorial Metadata', 'edit-flow' ),
						'edit_item' => __( 'Edit Editorial Metadata', 'edit-flow' ),
						'update_item' => __( 'Update Editorial Metadata', 'edit-flow' ),
						'add_new_item' => __( 'Add New Editorial Metadata', 'edit-flow' ),
						'new_item_name' => __( 'New Editorial Metadata', 'edit-flow' ),
					)
			)
		);		
	}
	
	// -------------------------
	// Post metabox stuff:
	// -------------------------
	
	function handle_post_metaboxes() {
		if ( function_exists( 'add_meta_box' ) ) {
			// TODO: Side or normal default placement for the meta_box? Looks good in either...
			add_meta_box( $this->metadata_taxonomy, __( 'Editorial Metadata', 'edit-flow' ), array( &$this, 'display_meta_box' ), 'post', 'side' );
			add_action( 'save_post', array(&$this, 'save_meta_box'), 10, 2 );
		}
	}
	
	function display_meta_box( $post ) {
		echo "<div id='{$this->metadata_taxonomy}_meta_box'>";
		// Add nonce for verification upon save
		echo "<input type='hidden' name='{$this->metadata_taxonomy}_nonce' value='" . wp_create_nonce(__FILE__) . "' />";
	
		$terms = $this->get_editorial_metadata_terms();
		foreach ( $terms as $term ) {
			$postmeta_key = $this->get_postmeta_key( $term );
			$current_metadata = esc_attr( $this->get_postmeta_value( $term, $post->ID ) );
			$type = $this->get_metadata_type( $term );
			$description = $this->get_unserialized_value( $term->description, self::description );
			$description_span = "<span class='description'>$description</span>";
			echo "<div class='{$this->metadata_taxonomy} {$this->metadata_taxonomy}_$type'>";
			switch( $type ) {
				case "date":
					echo "<label for='$postmeta_key'>{$term->name}</label>";
					echo "<label for='$postmeta_key'>$description_span</label>";
					echo "<input id='$postmeta_key' name='$postmeta_key' type='text' class='date-pick' value='$current_metadata' />";
					break;
				case "location":
					echo "<label for='$postmeta_key'>{$term->name}$description_span</label>";
					echo "<input id='$postmeta_key' name='$postmeta_key' type='text' value='$current_metadata' />";
					if ( !empty( $current_metadata ) )
						echo "<div><a href='http://maps.google.com/?q={$current_metadata}&t=m' target='_blank'>" . sprintf( __( 'View &#8220;%s&#8221; on Google Maps', 'edit-flow' ), $current_metadata ) . "</a></div>";
					break;
				case "text":
					echo "<label for='$postmeta_key'>{$term->name}$description_span</label>";
					echo "<input id='$postmeta_key' name='$postmeta_key' type='text' value='$current_metadata' />";
					break;
				case "paragraph":
					echo "<label for='$postmeta_key'>{$term->name}$description_span</label>";
					echo "<textarea id='$postmeta_key' name='$postmeta_key'>$current_metadata</textarea>";
					break;
				case "checkbox":
					echo "<label for='$postmeta_key'>{$term->name}$description_span</label>";
					echo "<input id='$postmeta_key' name='$postmeta_key' type='checkbox' value='1' " . checked($current_metadata, 1, false) . " />";
					break;
				case "user": 
					echo "<label for='$postmeta_key'>{$term->name}$description_span</label>";
					$user_dropdown_args = array( 
							'show_option_all' => __( '-- Select a user below --', 'edit-flow' ), 
							'name'     => $postmeta_key,
							'selected' => $current_metadata 
						); 
					wp_dropdown_users( $user_dropdown_args );
					break;
				default:
					echo "<p>This editorial metadata type is not yet supported</p>";
			}
			echo "</div>";
			echo "<div class='clear'></div>";
		} // Done iterating through metadata terms
		echo "</div>";
	}
	
	function save_meta_box( $id, $post ) {
		// Authentication checks: make sure data came from our meta box and that the current user is allowed to edit the post
		// TODO: switch to using check_admin_referrer? See core (e.g. edit.php) for usage
		if ( isset( $_POST[$this->metadata_taxonomy . "_nonce"] )
			&& !wp_verify_nonce( $_POST[$this->metadata_taxonomy . "_nonce"], __FILE__ )
			|| !current_user_can( 'edit_post', $id )
			|| defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE
			|| $post->post_type != 'post' ) {
			return $id;
		}
		
		// Authentication passed, let's save the data		
		$terms = $this->get_editorial_metadata_terms();
		$term_slugs = array();
				
		foreach ( $terms as $term ) {
			// Setup the key for this editorial metadata term (same as what's in $_POST)
			$key = $this->get_postmeta_key( $term );
			
			// Get the current editorial metadata
			// TODO: do we care about the current_metadata at all?
			//$current_metadata = get_post_meta( $id, $key, true );
			
			$new_metadata = isset( $_POST[$key] ) ? $_POST[$key] : '';
			
			if ( empty ( $new_metadata ) ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, $new_metadata );
				
				// Add the slugs of the terms with non-empty new metadata to an array
				$term_slugs[] = $term->slug;
			}
		}
		
		// Relate the post to the terms used and taxonomy type (wp_term_relationships table).
		// This will allow us to update and display the count of metadata in posts in use per term.
		// TODO: Core only correlates posts with terms if the post_status is publish. Do we care what it is?
		if ( $post->post_status === 'publish' ) {
			wp_set_object_terms( $id, $term_slugs, $this->metadata_taxonomy );
		}
	}
	
	function get_postmeta_key( $term ) {
		$key = $this->metadata_postmeta_key;
		$type = $this->get_metadata_type( $term );
		$prefix = "{$key}_{$type}";
		return "{$prefix}_" . ( is_object( $term ) ? $term->slug : $term );
	}
	
	
	/**
	 * Returns the value for the given metadata
	 *
	 * @param object|string|int term The term object, slug or ID for the metadata field term
	 * @param int post_id The ID of the post
	 */
	function get_postmeta_value( $term, $post_id ) {
		if( ! is_object( $term ) )
			$term = $this->get_editorial_metadata_term( $term );
		$postmeta_key = $this->get_postmeta_key( $term );
		return get_metadata( 'post', $post_id, $postmeta_key, true );
	}
	
	function get_editorial_metadata_terms() {
		return get_terms( $this->metadata_taxonomy, array(
				'orderby'	 => apply_filters( 'ef_editorial_metadata_term_order', 'name' ),
				'hide_empty' => false
			)
		);
	}
	
	/**
	 * Returns a term for single metadata field
	 *
	 * @param int|string field The slug or ID for the metadata field term to return 
	 */
	function get_editorial_metadata_term( $field ) {
		if( is_int( $field ) ) {
			$term = get_term_by( 'id', $field, $this->metadata_taxonomy );
		} elseif( is_string( $field ) ) {
			$term = get_term_by( 'slug', $field, $this->metadata_taxonomy );
		}
		
		if( ! $term || is_wp_error( $term ) )
			return false;
		
		return $term;
	}
	
} // END EF_Editorial_Metadata class
