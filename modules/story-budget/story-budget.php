<?php
/**
 * This class displays a budgeting system for an editorial desk's publishing workflow.
 *
 * @author sbressler
 * TODO: Review inline TODOs
 * TODO: Fix any bugs with collapsing postbox divs and floating columns
 */
class ef_story_budget {
	
	var $taxonomy_used = 'category';
	
	var $module;
	
	var $num_columns = 0;
	
	var $max_num_columns;
	
	var $no_matching_posts = true;
	
	var $terms = array();
	
	const screen_width_percent = 98;
	
	const screen_id = 'dashboard_page_story-budget';
	
	const usermeta_key_prefix = 'ef_story_budget_';
	
	const default_num_columns = 1;
	
	/**
	 * Register the module with Edit Flow but don't do anything else
	 */
	function __construct() {
	
		global $edit_flow;
		
		$module_url = $edit_flow->helpers->get_module_url( __FILE__ );
		// Register the module with Edit Flow
		// @todo default options for the story budget
		$args = array(
			'title' => __( 'Story Budget', 'edit-flow' ),
			'short_description' => __( 'View the status of all your content at a glance.', 'edit-flow' ),
			'extended_description' => __( 'Use the story budget to see how content on your site is progressing. Filter by specific categories or date ranges to see details about each post in progress.', 'edit-flow' ),
			'module_url' => $module_url,
			'img_url' => $module_url . 'lib/story_budget_s128.png',
			'slug' => 'story-budget',
			'default_options' => array(
				'enabled' => 'on',
			),
			'configure_page_cb' => false,
			'autoload' => false,
		);
		$this->module = $edit_flow->register_module( 'story_budget', $args );
	
	}
	
	/**
	 * Initialize the rest of the stuff in the class if the module is active
	 */
	function init() {
	
		$this->max_num_columns = apply_filters( 'ef_story_budget_max_num_columns', 3 );
		
		include_once( EDIT_FLOW_ROOT . '/common/php/' . 'screen-options.php' );
		if ( function_exists( 'add_screen_options_panel' ) )
			add_screen_options_panel( self::usermeta_key_prefix . 'screen_columns', __( 'Screen Layout', 'edit-flow' ), array( &$this, 'print_column_prefs' ), self::screen_id, array( &$this, 'save_column_prefs' ), true );
		
		// Register the columns of data appearing on every term. This is hooked into admin_init
		// so other Edit Flow modules can register their filters if needed
		add_action( 'admin_init', array( &$this, 'register_term_columns' ) );
		
		add_action( 'admin_menu', array( &$this, 'action_admin_menu' ) );
		// Load necessary scripts and stylesheets
		add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'action_enqueue_admin_styles' ) );
		
	}
	
	/**
	 * Give users the appropriate permissions to view the story budget the first time the module is loaded
	 *
	 * @since 0.7
	 */
	function install() {

		$story_budget_roles = array(
			'administrator' => array( 'ef_view_story_budget' ),
			'editor' =>        array( 'ef_view_story_budget' ),
			'author' =>        array( 'ef_view_story_budget' ),
			'contributor' =>   array( 'ef_view_story_budget' )
		);
		foreach( $story_budget_roles as $role => $caps ) {
			ef_add_caps_to_role( $role, $caps );
		}
	}
	
	/**
	 * Include the story budget link in the admin menu.
	 *
	 * @uses add_submenu_page()
	 */
	function action_admin_menu() {
		global $edit_flow;
		add_submenu_page( 'index.php', __('Story Budget', 'edit-flow'), __('Story Budget', 'edit-flow'), apply_filters( 'ef_view_story_budget_cap', 'ef_view_story_budget' ), $this->module->slug, array( &$edit_flow->story_budget, 'story_budget') );
	}
	
	/**
	 * Enqueue necessary admin scripts only on the story budget page.
	 *
	 * @uses enqueue_admin_script()
	 */
	function enqueue_admin_scripts() {
		global $current_screen, $edit_flow;
		
		if ( $current_screen->id != self::screen_id )
			return;
		
		$edit_flow->helpers->enqueue_datepicker_resources();
		wp_enqueue_script( 'edit_flow-story_budget', EDIT_FLOW_URL . 'modules/story-budget/lib/story-budget.js', array( 'edit_flow-date_picker' ), EDIT_FLOW_VERSION, true );
	}
	
	/**
	 * Enqueue a screen and print stylesheet for the story budget.
	 */
	function action_enqueue_admin_styles() {
		global $current_screen;
		
		if ( $current_screen->id != self::screen_id )
			return;
		
		wp_enqueue_style( 'edit_flow-story_budget-styles', EDIT_FLOW_URL . 'modules/story-budget/lib/story-budget.css', false, EDIT_FLOW_VERSION, 'screen' );
		wp_enqueue_style( 'edit_flow-story_budget-print-styles', EDIT_FLOW_URL . 'modules/story-budget/lib/story-budget-print.css', false, EDIT_FLOW_VERSION, 'print' );
	}
	
	/**
	 * Register the columns of information that appear for each term module.
	 * Modeled after how WP_List_Table works, but focused on hooks instead of OOP extending
	 *
	 * @since 0.7
	 */
	function register_term_columns() {
		
		$term_columns = array(
			'title' => __( 'Title', 'edit-flow' ),
			'status' => __( 'Status', 'edit-flow' ),
			'author' => __( 'Author', 'edit-flow' ),
			'post_date' => __( 'Post Date', 'edit-flow' ),
			'post_modified' => __( 'Last Modified', 'edit-flow' ),
		);
		
		$term_columns = apply_filters( 'ef_story_budget_term_columns', $term_columns );
		$this->term_columns = $term_columns;
	}	
	
	/**
	 * ??
	 */
	function get_num_columns() {
		global $edit_flow;
		if ( empty( $this->num_columns ) ) {
			$current_user = wp_get_current_user();
			$this->num_columns = $edit_flow->helpers->get_user_meta( $current_user->ID, self::usermeta_key_prefix . 'screen_columns', true );
			// If usermeta didn't have a value already, use a default value and insert into DB
			if ( empty( $this->num_columns ) ) {
				$this->num_columns = self::default_num_columns;
				$this->save_column_prefs( array( self::usermeta_key_prefix . 'screen_columns' => $this->num_columns ) );
			}
		}
		return $this->num_columns;
	}
	
	function print_column_prefs() {
		$return_val = __( 'Number of Columns: ', 'edit-flow' );
		for ( $i = 1; $i <= $this->max_num_columns; ++$i ) {
			$return_val .= "<label><input type='radio' name='" . self::usermeta_key_prefix . "screen_columns' value='$i' " . checked($this->get_num_columns(), $i, false) . " /> $i</label>\n";
		}
		return $return_val;
	}
	
	/**
	 * Save the current user's preference for number of columns.
	 */
	function save_column_prefs( $posted_fields ) {
		global $edit_flow;
		$key = self::usermeta_key_prefix . 'screen_columns';
		$this->num_columns = (int) $posted_fields[ $key ];
		
		$current_user = wp_get_current_user();
		$edit_flow->helpers->update_user_meta( $current_user->ID, $key, $this->num_columns );
	}

	/**
	 * Create the story budget view. This calls lots of other methods to do its work. This will
	 * ouput any messages, create the table navigation, then print the columns based on
	 * get_num_columns(), which will in turn print the stories themselves.
	 */
	function story_budget() {
		
		// Update the current user's filters with the variables set in $_GET
		$user_filters = $this->update_user_filters();
		
		$cat = $this->combine_get_with_user_filter( $user_filters, 'cat' );
		if ( !empty( $cat ) ) {
			$terms = array();
			$terms[] = get_term( $cat, $this->taxonomy_used );
		} else {
			// Get all of the terms from the taxonomy, regardless whether there are published posts
			$args = array(
				'orderby' => 'name',
				'order' => 'asc',
				'hide_empty' => 0,
				'parent' => 0,
			);
			$terms = get_terms( $this->taxonomy_used, $args );
		}
		$this->terms = apply_filters( 'ef_story_budget_filter_terms', $terms ); // allow for reordering or any other filtering of terms
		
		?>
		<div class="wrap" id="ef-story-budget-wrap">
			<?php $this->print_messages(); ?>
			<?php $this->table_navigation(); ?>
			<div id="dashboard-widgets-wrap">
				<div id="dashboard-widgets" class="metabox-holder">
				<?php
					$this->print_column( $this->terms );
				?>
				</div>
			</div><!-- /dashboard-widgets -->
			<?php $this->matching_posts_messages(); ?>
		</div><!-- /wrap -->
		<?php
	}

	/**
	 * Get posts by term and any matching filters
	 * TODO: Get this to actually work
	 */
	function get_matching_posts_by_term_and_filters( $term ) {
		global $wpdb, $edit_flow;
		
		$user_filters = $this->get_user_filters();
		
		// TODO: clean up this query, make it work with an eventual setup_postdata() call
		$query = "SELECT * FROM "/*$wpdb->users, */ . "$wpdb->posts 
					JOIN $wpdb->term_relationships
						ON $wpdb->posts.ID = $wpdb->term_relationships.object_id
					WHERE ";
		
		$post_where = '';		
		
		// Only show approved statuses if we aren't filtering (post_status isn't set or it's 0 or empty), otherwise filter to status
		$post_status = $this->combine_get_with_user_filter( $user_filters, 'post_status' );
		$post_statuses = $edit_flow->helpers->get_post_statuses();
		if ( !empty( $post_status ) ) {
			if ( $post_status == 'unpublish' ) {
				$post_where .= "($wpdb->posts.post_status IN (";
				foreach( $post_statuses as $status ) {
					$post_where .= $wpdb->prepare( "%s, ", $status->slug );
				}
				$post_where = rtrim( $post_where, ', ' );
				if ( apply_filters( 'ef_show_scheduled_as_unpublished', false ) ) {
					$post_where .= ", 'future'";
				}
				$post_where .= ')) ';
			} else {
				$post_where .= $wpdb->prepare( "$wpdb->posts.post_status = %s ", $post_status );
			}
		} else {
			$post_where .= "($wpdb->posts.post_status IN ('publish', 'future'";
			foreach( $post_statuses as $status ) {
				$post_where .= $wpdb->prepare( ", %s", $status->slug );
			}
			$post_where .= ')) ';
		}
		
		// Filter by post_author if it's set
		$post_author = $this->combine_get_with_user_filter( $user_filters, 'post_author' );
		if ( !empty( $post_author ) ) {
			$post_where .= $wpdb->prepare( "AND $wpdb->posts.post_author = %s ", (int) $post_author );
		}
		
		// Filter by start date if it's set
		$start_date = $this->combine_get_with_user_filter( $user_filters, 'start_date' );
		if ( !empty( $start_date ) ) {
			// strtotime basically handles turning any date format we give to the function into a valid timestamp
			// so we don't really care what date string format is used on the page, as long as it makes sense
			$mysql_time = date( 'Y-m-d', strtotime( $start_date ) );
			$post_where .= $wpdb->prepare( "AND ($wpdb->posts.post_date >= %s) ", $mysql_time );
		}
		
		// Filter by end date if it's set
		$end_date = $this->combine_get_with_user_filter( $user_filters, 'end_date' );
		if ( !empty( $end_date) ) {
			$mysql_time = date( 'Y-m-d', strtotime( $end_date ) );
			$post_where .= $wpdb->prepare( "AND ($wpdb->posts.post_date <= %s) ", $mysql_time );
		}
	
		// Limit results to the given category where type is 'post'
		$post_where .= $wpdb->prepare( "AND $wpdb->term_relationships.term_taxonomy_id = %d ", $term->term_taxonomy_id );
		$post_where .= "AND $wpdb->posts.post_type = 'post' ";
		
		// Limit the number of results per category
		$default_query_limit_number = 10;
		$query_limit_number = apply_filters( 'ef_story_budget_query_limit', $default_query_limit_number );
		// Don't allow filtering the limit below 0
		if ( $query_limit_number < 0 ) {
			$query_limit_number = $default_query_limit_number;
		}
		$query_limit = $wpdb->prepare( 'LIMIT %d ', $query_limit_number );
		
		$query .= apply_filters( 'ef_story_budget_query_where', $post_where );
		$query .= apply_filters( 'ef_story_budget_order_by', 'ORDER BY post_modified DESC ' );
		$query .= $query_limit;
		$query .= ';';
		
		return $wpdb->get_results( $query );
	}
	
	function combine_get_with_user_filter( $user_filters, $param ) {
		if ( !isset( $user_filters[$param] ) ) {
			return $this->filter_get_param( $param );
		} else {
			return $user_filters[$param];
		}
	}
	
	/**
	 * Prints a single column in the story budget.
	 *
	 * @param int $col_num The column which we're going to print.
	 * @param array $terms The terms to print in this column.
	 */
	function print_column( $terms ) {
		// If printing fewer than get_num_columns() terms, only print that many columns
		$num_columns = $this->get_num_columns();
		?>
		<div class="postbox-container">
			<div class="meta-box-sortables">
			<?php
				// for ($i = $col_num; $i < count($terms); $i += $num_columns)
				for ($i = 0; $i < count($terms); $i++)
					$this->print_term( $terms[$i] );
			?>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Prints the stories in a single term in the story budget.
	 *
	 * @param object $term The term to print.
	 */
	function print_term( $term ) {
		global $wpdb;
		$posts = $this->get_matching_posts_by_term_and_filters( $term );
		if ( !empty( $posts ) ) :
			// Don't display the message for $no_matching_posts
			$this->no_matching_posts = false;
			
	?>
	<div class="postbox" style='width: <?php echo self::screen_width_percent / $this->get_num_columns(); ?>%'>
		<div class="handlediv" title="<?php _e( 'Click to toggle', 'edit-flow' ); ?>"><br /></div>
		<h3 class='hndle'><span><?php echo $term->name; ?></span></h3>
		<div class="inside">
			<table class="widefat post fixed story-budget" cellspacing="0">
				<thead>
					<tr>
						<?php foreach( (array)$this->term_columns as $key => $name ): ?>
						<th scope="col" id="<?php echo esc_attr( sanitize_key( $key ) ); ?>" class="manage-column column-<?php echo esc_attr( sanitize_key( $key ) ); ?>" ><?php echo esc_html( $name ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tfoot></tfoot>
				<tbody>
				<?php
					foreach ($posts as $post)
						$this->print_post($post, $term);
				?>
				</tbody>
			</table>
		</div>
	</div>
	<?php
		endif;
	}
	
	/**
	 * Prints a single post within a term in the story budget.
	 *
	 * @param object $post The post to print.
	 * @param object $parent_term The top-level term to which this post belongs.
	 */
	function print_post( $post, $parent_term ) {
		global $edit_flow;
		?>
			<tr id='post-<?php echo $post->ID; ?>' class='alternate' valign="top">
				<?php foreach( (array)$this->term_columns as $key => $name ) {
					echo '<td>';
					if ( method_exists( &$this, 'term_column_' . $key ) ) {
						$method = 'term_column_' . $key;
						echo $this->$method( $post, $parent_term );
					} else {
						echo $this->term_column_default( $post, $key, $parent_term );
					}
					echo '</td>';
				} ?>
			</tr>
		<?php
	}
	
	/**
	 * Default callback for producing the HTML for a term column's single post value
	 * Includes a filter other modules can hook into
	 *
	 * @since 0.7
	 * 
	 * @param object $post The post we're displaying
	 * @param string $column_name Name of the column, as registered with register_term_columns
	 * @param object $parent_term The parent term for the term column
	 * @return string $output Output value for the term column
	 */
	function term_column_default( $post, $column_name, $parent_term ) {
		global $edit_flow;
		
		// Hook for other modules to get into
		$column_value = null;
		$column_value = apply_filters( 'ef_story_budget_term_column_value', $column_name, $post, $parent_term );
		if ( !is_null( $column_value ) )
			return $column_value;
			
		switch( $column_name ) {
			case 'status':
				$status_name = $edit_flow->helpers->get_post_status_friendly_name( $post->post_status );
				return $status_name;
				break;
			case 'author':
				$post_author = get_userdata( $post->post_author );
				return $post_author->display_name;
				break;
			case 'post_date':
				$output = get_the_time( get_option( 'date_format' ), $post->ID ) . '<br />';
				$output .= get_the_time( get_option( 'time_format' ), $post->ID );
				return $output;
				break;
			case 'post_modified':
				$modified_time_gmt = strtotime( $post->post_modified_gmt );
				return $edit_flow->helpers->timesince( $modified_time_gmt );
				break;
			default:
				break;
		}
		
	}
	
	/**
	 * Prepare the data for the title term column
	 *
	 * @since 0.7
	 */
	function term_column_title( $post, $parent_term ) {
		
		$post_title = _draft_or_post_title( $post->ID );
		
		$post_type_object = get_post_type_object( $post->post_type );
		if ( current_user_can( $post_type_object->cap->edit_post, $post->ID ) )
			$output = '<strong><a href="' . get_edit_post_link( $post->ID ) . '">' . esc_html( $post_title ) . '</a></strong>'; 
		else
			$output = '<strong>' . esc_html( $post_title ) . '</strong>';
		
		// Edit or Trash or View
		$output .= '<div class="row-actions">';
		if ( current_user_can( $post_type_object->cap->edit_post, $post->ID ) )
			$output .= '<span class="edit"><a title="' . __( 'Edit this post', 'edit-flow' ) . '" href="' . get_edit_post_link( $post->ID ) . '">' . __( 'Edit', 'edit-flow' ) . '</a> | </span>';
		if ( EMPTY_TRASH_DAYS > 0 && current_user_can( $post_type_object->cap->delete_post, $post->ID ) )
			$output .= '<span class="trash"><a class="submitdelete" title="' . __( 'Move this item to the Trash', 'edit-flow' ) . '" href="' . get_delete_post_link( $post->ID ) . '">' . __( 'Trash', 'edit-flow' ) . '</a> |</span>';
		$output .= '<span class="view"><a href="' . get_permalink( $post->ID ) . '" title="' . esc_attr( sprintf( __( 'View &#8220;%s&#8221;', 'edit-flow' ), $post_title ) ) . '" rel="permalink">' . __( 'View', 'edit-flow' ) . '</a></span>';
		$output .= '</div>';
		return $output;
		
	}
	
	function print_messages() {
	?>
		<div id="ef-story-budget-title"><!-- Story Budget Title -->
			<?php echo '<img src="' . esc_url( $this->module->img_url ) . '" class="module-icon icon32" />'; ?>
			<h2><?php _e( 'Story Budget', 'edit-flow' ); ?></h2>
		</div><!-- /Story Budget Title -->
	
	<?php
		if ( isset($_GET['trashed']) || isset($_GET['untrashed']) ) {

			echo '<div id="trashed-message" class="updated"><p>';
			
			// Following mostly stolen from edit.php
			
			if ( isset( $_GET['trashed'] ) && (int) $_GET['trashed'] ) {
				printf( _n( 'Item moved to the trash.', '%s items moved to the trash.', $_GET['trashed'] ), number_format_i18n( $_GET['trashed'] ) );
				$ids = isset($_GET['ids']) ? $_GET['ids'] : 0;
				echo ' <a href="' . esc_url( wp_nonce_url( "edit.php?post_type=post&doaction=undo&action=untrash&ids=$ids", "bulk-posts" ) ) . '">' . __( 'Undo', 'edit-flow' ) . '</a><br />';
				unset($_GET['trashed']);
			}

			if ( isset($_GET['untrashed'] ) && (int) $_GET['untrashed'] ) {
				printf( _n( 'Item restored from the Trash.', '%s items restored from the Trash.', $_GET['untrashed'] ), number_format_i18n( $_GET['untrashed'] ) );
				unset($_GET['undeleted']);
			}
			
			echo '</p></div>';
		}
	}
	
	/**
	 * Print the table navigation and filter controls, using the current user's filters if any are set.
	 */
	function table_navigation() {
		global $edit_flow;
		$post_statuses = $edit_flow->helpers->get_post_statuses();
		$user_filters = $this->get_user_filters();
	?>
	<div class="tablenav" id="ef-story-budget-tablenav">
		<div class="alignleft actions">
			<form method="GET" style="float: left;">
				<input type="hidden" name="page" value="story-budget"/>
				<select id="post_status" name="post_status"><!-- Status selectors -->
					<option value=""><?php _e( 'View all statuses', 'edit-flow' ); ?></option>
					<?php
						foreach ( $post_statuses as $post_status ) {
							echo "<option value='$post_status->slug' " . selected( $post_status->slug, $user_filters['post_status'] ) . ">$post_status->name</option>";
						}
						echo "<option value='future'" . selected('future', $user_filters['post_status']) . ">" . __( 'Scheduled', 'edit-flow' ) . "</option>";
						echo "<option value='unpublish'" . selected('unpublish', $user_filters['post_status']) . ">" . __( 'Unpublished', 'edit-flow' ) . "</option>";
						echo "<option value='publish'" . selected('publish', $user_filters['post_status']) . ">" . __( 'Published', 'edit-flow' ) . "</option>";
					?>
				</select>

				<?php
					// Borrowed from wp-admin/edit.php
					if ( taxonomy_exists('category') ) {
						$category_dropdown_args = array(
							'show_option_all' => __( 'View all categories', 'edit-flow' ),
							'hide_empty' => 0,
							'hierarchical' => 1,
							'show_count' => 0,
							'orderby' => 'name',
							'selected' => $user_filters['cat']
							);
						wp_dropdown_categories( $category_dropdown_args );
					}
					
					// TODO: Consider getting rid of this dropdown? The Edit Posts page doesn't have it and only allows filtering by user by clicking on their name. Should we do the same here?
					$user_dropdown_args = array(
						'show_option_all' => __( 'View all users', 'edit-flow' ),
						'name'     => 'post_author',
						'selected' => $user_filters['post_author']
						);
					wp_dropdown_users( $user_dropdown_args );
				?>
				
				<label for="start_date"><?php _e( 'From:', 'edit-flow' ); ?> </label>
				<input id='start_date' name='start_date' type='text' class="date-pick" value="<?php echo $user_filters['start_date']; ?>" autocomplete="off" />
				<label for="end_date"><?php _e( 'To:', 'edit-flow' ); ?> </label>
				<input id='end_date' name='end_date' type='text' size='20' class="date-pick" value="<?php echo $user_filters['end_date']; ?>" autocomplete="off" />
				<input type="submit" id="post-query-submit" value="<?php _e( 'Filter', 'edit-flow' ); ?>" class="button-primary button" />
			</form>
			<form method="GET" style="float: left;">
				<input type="hidden" name="page" value="story-budget"/>
				<input type="hidden" name="post_status" value=""/>
				<input type="hidden" name="cat" value=""/>
				<input type="hidden" name="post_author" value=""/>
				<input type="hidden" name="start_date" value=""/>
				<input type="hidden" name="end_date" value=""/>
				<input type="submit" id="post-query-clear" value="<?php _e( 'Reset', 'edit-flow' ); ?>" class="button-secondary button" />
			</form>
		</div><!-- /alignleft actions -->
		
		<p class="print-box" style="float:right; margin-right: 30px;"><!-- Print link -->
			<a href="#" id="print_link"><?php _e( 'Print', 'edit-flow' ); ?></a>
		</p>
		<div class="clear"></div>
		
	</div><!-- /tablenav -->
	<?php
	}
	
	/**
	 * Display any messages after displaying all the story budget boxes. This will likely be for messages when no
	 * stories are found to match the current filters.
	 */
	function matching_posts_messages() {
		if ( $this->no_matching_posts ) { ?>
		<style type="text/css">
			/* Apparently the meta-box-sortables class has a minimum height of 300px. Not good with nothing inside them! */
			.postbox-container .meta-box-sortables { min-height: 0; }
			.print-box { display: none; }
		</style>
		<div id="noposts-message" class="ef-updated"><p><?php _e( 'There are currently no matching posts.', 'edit-flow' ); ?></p></div>
		<?php
		}
	}
	
	/**
	 * Update the current user's filters for story budget display with the filters in $_GET. The filters
	 * in $_GET take precedence over the current users filters if they exist.
	 */
	function update_user_filters() {
		global $edit_flow;
		$current_user = wp_get_current_user();
		
		$user_filters = array(
								'post_status' 	=> $this->filter_get_param( 'post_status' ),
								'cat' 			=> $this->filter_get_param( 'cat' ),
								'post_author' 	=> $this->filter_get_param( 'post_author' ),
								'start_date' 	=> $this->filter_get_param( 'start_date' ),
								'end_date' 		=> $this->filter_get_param( 'end_date' )
							  );
		
		$current_user_filters = array();
		$current_user_filters = $edit_flow->helpers->get_user_meta( $current_user->ID, self::usermeta_key_prefix . 'filters', true );
		
		// If any of the $_GET vars are missing, then use the current user filter
		foreach ( $user_filters as $key => $value ) {
			if ( is_null( $value ) && !empty( $current_user_filters[$key] ) ) {
				$user_filters[$key] = $current_user_filters[$key];
			}
		}
		
		$edit_flow->helpers->update_user_meta( $current_user->ID, self::usermeta_key_prefix . 'filters', $user_filters );
		return $user_filters;
	}
	
	/**
	 * Get the filters for the current user for the story budget display, or insert the default
	 * filters if not already set.
	 * 
	 * @return array The filters for the current user, or the default filters if the current user has none.
	 */
	function get_user_filters() {
		global $edit_flow;
		$current_user = wp_get_current_user();
		$user_filters = array();
		$user_filters = $edit_flow->helpers->get_user_meta( $current_user->ID, self::usermeta_key_prefix . 'filters', true );
		
		// If usermeta didn't have filters already, insert defaults into DB
		if ( empty( $user_filters ) )
			$user_filters = $this->update_user_filters();
		return $user_filters;
	}
	
	/**
	 *
	 * @param string $param The parameter to look for in $_GET
	 * @return null if the parameter is not set in $_GET, empty string if the parameter is empty in $_GET,
	 *		   or a sanitized version of the parameter from $_GET if set and not empty
	 */
	function filter_get_param( $param ) {
		// Sure, this could be done in one line. But we're cooler than that: let's make it more readable!
		if ( !isset( $_GET[$param] ) ) {
			return null;
		} else if ( empty( $_GET[$param] ) ) {
			return '';
		}
		
		// TODO: is this the correct sanitization/secure enough?
		return htmlspecialchars( $_GET[$param] );
	}
	
} // End class EF_Story_Budget
