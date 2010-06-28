<?php
// Handles all current and future upgrades for edit_flow
function edit_flow_upgrade( $from ) {
	if( !$from || $from < 0.1 ) edit_flow_upgrade_01();
	if( $from < 0.3 ) edit_flow_upgrade_03();
}

// Upgrade to 0.1
function edit_flow_upgrade_01() {
	global $edit_flow;
	
	// Create default statuses
	$default_terms = array( 
		array( 'term' => 'Draft', 'args' => array( 'slug' => 'draft', 'description' => 'Post is simply a draft', ) ),
		array( 'term' => 'Pending Review', 'args' => array( 'slug' => 'pending', 'description' => 'The post needs to be reviewed by an Editor', ) ),
		array( 'term' => 'Pitch', 'args' => array( 'slug' => 'pitch', 'description' => 'Post idea proposed', ) ),
		array( 'term' => 'Assigned', 'args' => array( 'slug' => 'assigned', 'description' => 'The post has been assigned to a writer' ) ),
		array( 'term' => 'Waiting for Feedback', 'args' => array( 'slug' => 'waiting-for-feedback', 'description' => 'The post has been sent to the editor, and is waiting on feedback' ) ) 
	);
	
	// Okay, now add the default statuses to the db if they don't already exist 
	foreach($default_terms as $term) {
		if(!is_term($term['term'])) $edit_flow->custom_status->add_custom_status( $term['term'], $term['args'] );
	}
	
	update_option($edit_flow->get_plugin_option_fullname('version'), '0.1');
}

// Upgrade to 0.3
function edit_flow_upgrade_03 () {
	global $wp_roles, $edit_flow;

	if ( ! isset( $wp_roles ) )
		$wp_roles = new WP_Roles();

	// Add necessary capabilities to allow management of usergroups and post subscriptions
	// edit_post_subscriptions - administrator + editor
	// edit_usergroups - adminstrator
	if( $wp_roles->is_role('administrator') ) {
		$admin_role =& get_role('administrator');
		$admin_role->add_cap('edit_post_subscriptions');
		$admin_role->add_cap('edit_usergroups');
	}
	if( $wp_roles->is_role('administrator') ) {	
		$editor_role =& get_role('editor');
		$editor_role->add_cap('edit_post_subscriptions');
	}
	
	$default_usergroups = array( 
		array( 'slug' => 'ef_copy-editors', 'args' => array( 'name' => 'Copy Editors', 'description' => 'The ones who correct stuff.' ) ),
		array( 'slug' => 'ef_photographers', 'args' => array( 'name' => 'Photographers', 'description' => 'The ones who take pretty pictures.' ) ),
		
		array( 'slug' => 'ef_reporters', 'args' => array( 'name' => 'Reporters', 'description' => 'The ones who write stuff.' ) ),
		array( 'slug' => 'ef_section-editors', 'args' => array( 'name' => 'Section Editors', 'description' => 'The ones who tell others what to do and generally just boss them around.' ) ),
		array( 'slug' => 'ef_web-team', 'args' => array( 'name' => 'Web Team', 'description' => 'The ones you call when your computer starts doing that weird thing.' ) ),
		array( 'slug' => 'ef_sales-team', 'args' => array( 'name' => 'Sales Team', 'description' => 'Yeah, they technically pay our salaries. But we still don\'t like them.' ) ),
	);
	
	// Okay, now add the default statuses to the db if they don't already exist 
	foreach($default_usergroups as $usergroup) {
		if( !is_term($usergroup['slug'], $edit_flow->notifications->following_usergroups_taxonomy) ) {
			ef_add_usergroup( $usergroup['slug'], $usergroup['args'] );
		}
	}
	update_option($edit_flow->get_plugin_option_fullname('version'), '0.3');
}