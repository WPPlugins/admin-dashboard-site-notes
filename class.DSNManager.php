<?php

/* TODO for 1.4:
- make new checkboxes save to db
- add new locations/actions to functions that check that
- add support for new textfield stuff to location/action checker
- remove javascript for "everywhere" checkbox and have it check that in db again
- clean up table with headers and organize in a way that looks better
- rewrite bit with all the get_and_save_checkbox to just loop through _POST and check anything starting with our prefix
- finish the hide title on shortcode and hide title on widget
*/

define( 'DSNMANAGER_TEXTDOMAIN', 'dsnmanager' );
class DSNManager {
	private $shortcode = "sitenote"; // if changed, also change in dashboard-site-notes.php
	private $plugin_version = "1.4.0";
	private $plugin_id = 'dsnmanager';
	private $plugin_stylesheet_id = 'dsnmanager_css';
	private $plugin_script_id = 'dsnmanager_js';
	private $exclude_types = array('nav_menu_item');
	private $post_types = array();
	private $post_type_name = 'dsn_note';
	private $post_type_name_cap = 'dsn_notes'; // for defining capabilities
	private $custom_field_prefix = '_dsn_'; // the _ hides it from dropdowns
	private $nonce_id = '_dsn_nonce'; 
	private $custom; // custom field cache for a post (used when editing a note)
	private $capabilities;
	private $options; // plugin options cache
	private $plugin_base;
	private $note_cache;
	private $allowed_tags = array('div', 'p','br','b','strong','i','em','a','u','h1','h2','h3','h4','h5','h6');
	
	private static $dsnmanager;
	
	function __construct( $base='' ) {
		if(self::$dsnmanager) {
			return self::$dsnmanager;
		}
		
		$this->init($base);
		self::$dsnmanager = $this;
		return $this;
	}
	
	private function init($base) {
		// add translation support
		load_plugin_textdomain(DSNMANAGER_TEXTDOMAIN, PLUGINDIR . 'admin-dashboard-site-notes/languages', 'admin-dashboard-site-notes/languages' );
		
		// define all of the capabilities even though at this point there's just one capability used
		$this->capabilities = array(
			'publish_posts' => 'publish_'.$this->post_type_name_cap,
			'edit_posts' => 'edit_'.$this->post_type_name_cap,
			'edit_others_posts' => 'edit_others_'.$this->post_type_name_cap,
			'delete_posts' => 'delete_'.$this->post_type_name_cap,
			'delete_others_posts' => 'delete_others_'.$this->post_type_name_cap,
			'read_private_posts' => 'read_private_'.$this->post_type_name_cap,
			'edit_post' => 'edit_'.$this->post_type_name,
			'delete_post' => 'delete_'.$this->post_type_name,
			'read_post' => 'read_'.$this->post_type_name,
		);
		
		// cache our options before doing anything, since everything else depends on them
		$this->options = $this->get_all_options();
			
		// check if we need to run any upgrade scripts and if so, do it
		$this->handle_upgrades();
		
		// base name should be plugin_basename(__FILE__) to check when WP is referring to this plugin, but since this is an include, we need to have it passed in by the actual file
		$this->plugin_base = $base;
		
		// create the site note content type
		if(!defined('DSN_DISABLE_CHANGES')) {
			$this->add_content_type();
		}
		
		// add hooks and filters
		add_filter('plugin_action_links',array(&$this,'extra_plugin_links_primary'),10,2);
		add_filter('plugin_row_meta',array(&$this,'extra_plugin_links_secondary'),10,2);
		add_action('all_admin_notices',array(&$this,'all_admin_notices'));
		add_action('admin_init',array(&$this,'admin_init'));
		add_filter('dsn_sanitize_title',array(&$this,'_sanitize_title'),99);
		add_filter('dsn_sanitize',array($this,'_sanitize'),99);
		add_filter('dsn_author_name',array($this,'_author_name'),99,2);
		if($this->options['support_author']) {
			add_filter('dsn_contextual_help_content',array($this,'_append_author_name'),99,2);
			add_filter('dsn_admin_notice_content',array($this,'_append_author_name'),99,2); // TODO
			add_filter('dsn_widget_content',array($this,'_append_author_name'),99,2); // TODO
		}
		// add styles/scripts
		add_action('admin_enqueue_scripts',array($this,'enqueue_includes'));
		
		// add dashboard widget notes if there are any
		if($this->has_dashboard_notes()) {
			add_action('wp_dashboard_setup', array($this,'setup_dashboard'));
		}
		// add instruction manual page if entries exist
		if($this->has_instruction_notes()) {
			add_action( 'admin_menu', array($this,'admin_menu') );
		}
		// add the options page if this user can manage the options
		if($this->user_has_admin()) {
			if(!defined('DSN_DISABLE_CHANGES')) {
				add_action('admin_menu', array($this,'add_config_menu'));
				add_action('admin_init', array($this,'register_settings'));
			}
		}
		
		// check if we can add contextual help (added in WP v3.3) and if so, add that action
		$wp_version = get_bloginfo('version');
		if( version_compare($wp_version, '3.3.0') >= 0) {
			add_action('current_screen',array($this,'current_screen'),999);
		}
		
	}
	public function get_author_name($author_id) {
		// display_name cannot be trusted; a user can set it to literally any string they want that doesn't have html in it, such as someone else's name
		return esc_html(sprintf(__("%s (%s)"), get_the_author_meta('display_name',$author_id), get_the_author_meta('user_login',$author_id)));
	}
	
	public function _append_author_name($content, $post) {
		$author_name = esc_html(apply_filters('dsn_author_name', $this->get_author_name($post->post_author), $post->post_author));
		$author_info = "<div class='author'>{$author_name}</div>";
			
		return $content . $author_info;
	}
	
	public function _author_name($name, $id) {
		return esc_html(sprintf(__("- %s"), $name));
	}
	
	private function handle_upgrades() {
		global $wpdb;
		$prefix = $this->custom_field_prefix;
		
		// if there's no version set in the database, it's a pre-1.3 version, so run the "upgrade" code and then create the version
		if(!isset($this->options['version']) || version_compare($this->options['version'], '1.3', '<')) {
			require_once('upgrades/update-1.3.php');
		}
		// updates for v1.4.  basically just cleans up meta table from 1.3 disaster.
		if(version_compare($this->options['version'], '1.4', '<')) {
			require_once('upgrades/update-1.4.php');
		}
	}
	
	private function dump($val) {
		echo "<div style='padding:40px'>";
		var_dump($val);
		echo "</div>";
	}
	public function current_screen() {
		$screen = get_current_screen();
		$args = array('format'=>'contextual_help');
		$posts = $this->get_notes($args);
		if(count($posts)) {
			foreach($posts as $post) {
				$c = apply_filters('dsn_contextual_help_content',$this->get_content($post),$post);
				$c = "<div class='dsn dsn-help'><div class='note'><div class='content'>{$c}</div></div></div>";
				$t = $this->sanitize_title($post->post_title, $post->post_author);
				$id = $post->ID;
				$screen->add_help_tab( array(
					'id'      => "dsn-postid-{$id}",
					'title'   => apply_filters('dsn_contextual_help_title',$t,$post),
					'content' => apply_filters('dsn_contextual_help_notice',$c,$post),
				) );
			}
		}
	}
	
	public function shortcode($atts=array(), $content='') {
		extract( shortcode_atts( array(
			'id' => '',
			'depth' => 99,
			'excerpt' => false,
			'content' => true,
			'title' => true,
		), $atts ) );
		
		// make sure this note actually allows use in a shortcode
		if($id && get_post_meta($id, '_dsn_shortcodable', true) == true) {
			$post = get_post($id);
			if($post) {
				$c = $this->note_with_children($post,'',0,!$excerpt,$depth);
			}
		}
		
		return apply_filters('dsn_shortcode_notice',$c);
	}
	
	public function enqueue_includes() {
		wp_register_style($this->plugin_stylesheet_id, plugins_url('admin-dashboard-site-notes/admin-styles-1.4.0.css'));
		wp_enqueue_style($this->plugin_stylesheet_id);
		wp_register_script($this->plugin_script_id, plugins_url('admin-dashboard-site-notes/admin-scripts.js'), array('jquery') );
		wp_enqueue_script($this->plugin_script_id);
	}
	
	// check if user is allowed to configure the plugin
	// only super admins are allowed, unless DSN_ADMIN_CONFIG is true, in which case any admin is allowed
	private function user_has_admin() {
		$is_admin = is_super_admin() || (defined('DSN_ADMIN_CONFIG') && current_user_can('manage_options'));
		return apply_filters('dsn_has_admin', $is_admin);
	}
	
	// get all wordpress roles, allowing them to be filtered by other plugins
	private function get_roles() {
		global $wp_roles;
		if(!$wp_roles) {
			return array();
		}
		$all_roles = $wp_roles->roles;
		$editable_roles = apply_filters('editable_roles', $all_roles);
		return $editable_roles;
	}
	
	// add the content type to the admin navigation
	public function admin_menu() {
		add_dashboard_page($this->options['manual_title'], $this->options['manual_nav'], 'read', $this->plugin_id, array($this,'admin_page'));
	}
	
	// echo the instruction manual
	public function admin_page() {
		echo "<div id='dsn_instructions' class='wrap'>";
		echo "<h2>" . $this->options['manual_title'] . "</h2>";
		
		$args = array('format'=>'instruction_manual');
		$posts = $this->get_notes($args);
		
		// generate table of contents
		$output = '';
		foreach($posts as $post) {
			if($post->post_parent == 0) {
				$output .= $this->index_with_children($post);
			}
		}
		echo "<div class='instruction_index'>";
		echo "<h3>" . esc_html__('Table of Contents', DSNMANAGER_TEXTDOMAIN) . "</h3>";
		echo $output;
		echo "</div>";
		
		// generate instructions
		$output = '';
		foreach($posts as $post) {
			if($post->post_parent == 0) {
				$output .= $this->note_with_children($post, 'instruction_manual', 0, true);
			}
		}
		echo "<div class='instruction_content'>";
		echo $output;
		echo "</div>";
		
		echo "</div>";
	}
	
	public function has_instruction_notes() {
		$args = array('format'=>'instruction_manual');
		$posts = $this->get_notes($args);
		if(count($posts)) {
			return true;
		}
		return false;
	}
	public function has_dashboard_notes() {
		$args = array('format'=>'dashboard_widget');
		$posts = $this->get_notes($args);
		if(count($posts)) {
			return true;
		}
		return false;
	}
	public function setup_dashboard() {
		wp_add_dashboard_widget('dsn_dashboard' , $this->options['dashboard_title'],  array($this,'dsn_dashboard'));
	}
	public function dsn_dashboard() {
		$args = array('format'=>'dashboard_widget');
		$posts = $this->get_notes($args);
		$output = '';
		foreach($posts as $post) {
			if($post->post_parent == 0) {
				$output .= $this->note_with_children($post,'dashboard_widget');
			}
		}
		echo $output;
	}
	
	public function get_notes_by_parent($which_parent, $format) {
		$args = array('format'=>$format);
		$posts = $this->get_notes($args);
		$children = array();
		if(empty($posts)) {
			return;
		}
		foreach($posts as $post) {
			if($post->post_parent == $which_parent) {
				$children[] = $post;
			}
		}
		return $children;
	}
	
	// recursively get the linked post title and its children
	public function index_with_children($post,$depth=0) {
		if($depth > 64) { // sanity check
			return esc_html__("Error: note output aborted, hierarchy too deep (>64)", DSNMANAGER_TEXTDOMAIN);
		}
		$type = ' parent ';
		if($depth > 0) {
			$type = ' child ';
		}
		$output = "<ul class='index depth-{$depth} {$type}'>";
		$id = $post->ID;
		$t = $this->sanitize_title($post->post_title, $post->post_author);
		$children = $this->get_notes_by_parent($id, 'instruction_manual');
		$child_output = '';
		if(count($children)) {
			foreach($children as $child) {
				$child_output .= $this->index_with_children($child,$depth + 1);
			}
		}
		
		$output .= "
		<li class='dsn dashboard_index depth-{$depth}'>
			<a href='#note_{$id}'><h4>{$t}</h4></a>
			{$child_output}
		</li>";
		$output .= '</ul>';
		return $output;
	}
	
	// recursively get the post and its children
	public function note_with_children($post,$format,$depth=0,$full_post=false,$max_depth=5) {
		$output = "<ul class='notes depth-{$depth}'>";
		$id = $post->ID;
		$t = $this->sanitize_title($post->post_title, $post->post_author);
		$c = $this->get_content($post,$full_post);
		
		$child_output = '';
		if($depth < $max_depth && $id > 0) {
			$children = $this->get_notes_by_parent($id, $format);
			if(count($children)) {
				foreach($children as $child) {
					$child_output .= $this->note_with_children($child,$format,$depth + 1,$full_post);
				}
			}
		}
		
		$c = apply_filters('dsn_widget_content',$c, $post);
		
		$output .= "
		<li class='dsn site_note depth-{$depth}'>
			<a name='note_{$id}'></a><h4>{$t}</h4>
			<div class='content'>{$c}</div>
			{$child_output}
		</li>";
		$output .= '</ul>';
		return $output;
	}
	
	// returns the current custom post type if applicable, or false if not
	public function current_post_type() {
		global $pagenow;
		if($pagenow == 'revision.php') {
			return 'revision';
		}
		else if(isset($_GET['post_type'])) {
			return $_GET['post_type'];
		}
		else if(isset($_GET['post'])) {
			return get_post_type($_GET['post']);
		}
		
		
		$post_pages = array('edit.php','post-new.php','post.php');
		if(in_array($pagenow,$post_pages)) {
			return 'post';
		}
		
		$attachment_pages = array('upload.php','media-new.php','media.php');
		if(in_array($pagenow,$attachment_pages)) {
			return 'attachment';
		}
		
		$revision_pages = array('revision.php');
		if(in_array($pagenow,$revision_pages)) {
			return 'revision';
		}
		
		return '';
	}
	// returns the current action
	public function current_action() {
		global $pagenow;
		switch($pagenow) {
			case 'index.php':
				if(isset($_GET['page'])) {
					if($_GET['page']==$this->plugin_id) {
						return 'instruction_manual';
					}
					break;
				}
				return 'dashboard';
			case 'upload.php':
				return 'search';
			case 'edit.php':
				return 'search';
			case 'media-new.php':
				return 'new';
			case 'post-new.php':
				return 'new';
			case 'media.php':
				return 'edit';
			case 'post.php':
				return 'edit';
			case 'revision.php':
				if(isset($_GET['action']) && $_GET['action'] == 'diff') {
					return 'diff';
				}
				return 'view';
		}
		return '';
	}
	
	// note that if action is passed in, content type will not be appended
	public function get_current_loc() {
		$on_content_type = $this->current_post_type();
		$on_action = $this->current_action();
		$loc = "loc_";
		$loc .= $on_action;
		if($on_content_type) {
			$loc .= "_" . $on_content_type;
		}
		return $loc;
	}
	// return notes filtered by args['format']
	public function get_notes($args=array()) {
		global $wpdb, $current_user;
		if(!isset($current_user->caps)) {
			return;
		}
		
		if(!isset($args['format'])) {
			$format = '';
		}
		else {
			$format = $args['format'];
		}
		
		if(isset($this->note_cache['format'])) {
			return $this->note_cache['format'];
		}
		
		$pre = $this->custom_field_prefix;
		
		// set up where statement for selecting correct format of note
		switch($format) {
			case 'instruction_manual':
				$where_note = "wppm.meta_key = '{$pre}instruction_manual' AND wppm.meta_value = '1'";
				break;
			case 'dashboard_widget':
				$where_note = "wppm.meta_key = '{$pre}dashboard_widget' AND wppm.meta_value = '1'";
				break;
			default:
				$where_note = "( (wppm.meta_key = '%s' AND wppm.meta_value = '1') OR (wppm.meta_key = '{$pre}loc_everywhere' AND wppm.meta_value = '1') )";
				break;
		}
		
		// set up the subquery for role checking
		$wheres_arr = array();
		$roles = $current_user->caps;
		foreach($roles as $role_name=>$role_arr) {
			$role = $pre . 'role_' . $wpdb->escape($role_name);
			$wheres_arr[] = " (meta_key = '{$role}' AND meta_value = '1') ";
		}
		$where_str = implode(" OR ", $wheres_arr);
		if(!strlen($where_str)) {
			return;
		}
		$role_query = " SELECT post_id FROM {$wpdb->postmeta} wppm_roles WHERE {$where_str}";
		
		$post_type_name = $this->post_type_name;
		$which_location = $this->get_current_loc();
		$sql = $wpdb->prepare("
			SELECT 	wppm.*,
					wpp.*,
					wppm_admin_notice.meta_value admin_notice,
					wppm_contextual_help.meta_value contextual_help
			FROM {$wpdb->postmeta} wppm
			LEFT JOIN {$wpdb->posts} wpp ON wpp.id  = wppm.post_id
			LEFT JOIN {$wpdb->postmeta} wppm_admin_notice ON wppm_admin_notice.post_id = wppm.post_id AND wppm_admin_notice.meta_key = '{$pre}admin_notice'
			LEFT JOIN {$wpdb->postmeta} wppm_contextual_help ON wppm_contextual_help.post_id = wppm.post_id AND wppm_contextual_help.meta_key = '{$pre}contextual_help'
			WHERE
				wpp.post_status = 'publish'
				AND wpp.post_type = '%s'
				AND	{$where_note}
				AND wpp.id IN ( {$role_query} )
			GROUP BY wpp.id
			ORDER BY wpp.menu_order ASC, wpp.post_title ASC
			",  $post_type_name, $pre . $which_location);
		$res = $wpdb->get_results($sql);
		// if default format, seperate it into admin_notice and contextual_help caches
		if( ($format=='' || $format=='admin_notice' || $format=='contextual_help') ) {
			$admin_notice = array();
			$contextual_help = array();
			if(!empty($res)) {
				foreach($res as $note) {
					if(isset($note->admin_notice) && (bool)$note->admin_notice) {
						$admin_notice[] = $note;
					}
					if(isset($note->contextual_help) && (bool)$note->contextual_help) {
						$contextual_help[] = $note;
					}
				}
			}
			$this->note_cache['admin_notice'] = $admin_notice;
			$this->note_cache['contextual_help'] = $contextual_help;
		}
		else {
			$this->note_cache[$format] = $res;
		}
		return apply_filters('dsn_get_notes', $this->note_cache[$format]);
	}
	
	private function remove_attr($content) {
		$content = preg_replace('/<([a-z]+)[^>]*>/i', '<\1>', $content);
		return $content;
	}
	
	// TODO: check if this user can bypass sanitization
	private function bypasses_sanitization($user_id) {
		// also check !$this->options['rolesanitize_administrator']
		return false;
	}
	
	public function _sanitize_title($content) {
		return esc_html($content, ENT_QUOTES);
	}
	public function sanitize_title($content, $author_id) {
		if($this->bypasses_sanitization($author_id)) {
			$title = $content;
		}
		else {
			$title = apply_filters('dsn_sanitize_title',$content);
		}
		return apply_filters('dsn_title',$title);
	}
	public function _sanitize($content) {
		$allowed_tag_str = "<" . implode('><',$this->allowed_tags) . ">";
		$content = strip_tags($this->remove_attr( $content ), $allowed_tag_str);
		return $content;
	}
	public function sanitize($content, $author_id) {
		// TODO: check if author_id is an admin
		if($this->bypasses_sanitization($author_id)) {
			$new = $content;
		}
		else {
			$new = apply_filters('dsn_sanitize',$content);
		}
		return $new;
	}
	
	// apply our internal options to content and return what we actually want
	public function get_content($post,$full_post=false) {
		$c = '';
		$post_type = $this->current_post_type();
		// TODO: content filter on attachment pages prepends a thumbnail for some reason.
		// too much effort to find out why right now, so just don't apply content filter on attachment pages
		$is_attachment = ($post_type == "attachment");
		
		if($this->options['support_excerpt']) {
			$c = $post->post_excerpt;
			if($this->options['use_excerpt_filter'] && !$is_attachment) {
				$c = apply_filters('the_content', $c);
				$c = str_replace(']]>', ']]&gt;', $c);
			}
		}
		if(!strlen(trim($c)) || $full_post) {
			$c = $post->post_content;
			if($this->options['use_content_filter'] && !$is_attachment) {
				$c = apply_filters('the_content', $c);
				$c = str_replace(']]>', ']]&gt;', $c);
			}
		}
		return $this->sanitize($c, $post->post_author);
	}
	
	public function get_note_meta($post_id, $key, $single=false) {
		return get_post_meta($post_id,$this->custom_field_prefix . $key,$single);
	}
	// Called on hook 'all_admin_notices'
	public function all_admin_notices() {
		// on the dashboard we print a pretty widget, not a notice
		if($this->current_action() == 'dashboard' 
			|| $this->current_action() == 'instruction_manual' 
			|| $this->current_action() == '') {
			return;
		}
		$args = array('format'=>'admin_notice');
		$posts = $this->get_notes($args);
		$output = '';
		if(count($posts)) {
			$g = $this->options['use_grouping'];
			if($g) $output .= "<div class='updated dsn'>";
			foreach($posts as $post) {
				$new_output = '';
				$hide_t = $this->get_note_meta($post->ID, 'hide_title', true);
				if($hide_t) $t = '';
				else {
					$title = $this->sanitize_title($post->post_title, $post->post_author);
					$t = "<div class='title'>{$title}</div>";
				}
				$c = apply_filters('dsn_admin_notice_content',$this->get_content($post),$post);
				if($g) $new_output .= "<div class='note'>{$t}<div class='content'>{$c}</div></div>";
				else $new_output .= "<div class='updated dsn note'><div class='title'>{$t}</div><div class='content'>{$c}</div></div>";
				$output .= apply_filters('dsn_admin_notice_html',$new_output,$post);
			}
			if($g) $output .= "</div>";
		}
		echo apply_filters('dsn_admin_notices',$output);
	}
	
	// Called on wordpress hook 'admin_init'
	public function admin_init() {
		add_meta_box('display-location-div', esc_html__('Note Options', DSNMANAGER_TEXTDOMAIN),  array($this,'display_info_metabox'), 'dsn_note', 'normal', 'low');
		add_action('save_post', array($this,'save_meta'));
		$types = get_post_types();
		foreach($types as $type=>$type_obj) {
			if(!in_array($type,$this->exclude_types)) {
				$this->post_types[$type] = get_post_type_object($type);
			}
		}
	}
	
	// save all of our meta fields
	public function save_meta($post_id) {
		// prevent wp from killing our custom fields during autosave by making sure our fields are getting submitted
		if (!isset($_POST[$this->nonce_id])) {
			return $post_id;
		}
		// double-check to make sure these fields only get saved on dsn_notes
		if( !isset($_POST['post_type']) || $_POST['post_type'] != $this->post_type_name ) {
			return $post_id;
		}
		// check nonce
		$nonce_id = $this->nonce_id;
		if(!wp_verify_nonce($_POST[$nonce_id],$nonce_id)) {
			return $post_id;
		}
		
		if(is_array($_POST) && count($_POST)) {
			/* TODO: instead of manually putting these both here and in meta box, come up with method for just doing it all in one place. just need to duplicate checkboxes as hidden fields with value 0(done), and then just accept any fields we find that start with our prefix */
			// format/types:
			$this->check_and_save_checkbox('loc_everywhere',$post_id);
			$this->check_and_save_checkbox('dashboard_widget',$post_id);
			$this->check_and_save_checkbox('instruction_manual',$post_id);
			$this->check_and_save_checkbox('contextual_help',$post_id);
			$this->check_and_save_checkbox('admin_notice',$post_id);
			// miscellaneous options:
			$this->check_and_save_checkbox('hide_title',$post_id);
			$this->check_and_save_checkbox('hide_title_widget',$post_id);
			$this->check_and_save_checkbox('hide_title_shortcode',$post_id);
			$this->check_and_save_checkbox('shortcodable',$post_id);
			// locations:
			foreach($this->post_types as $type=>$type_obj) {
				if($type =='revision') {
					$this->check_and_save_checkbox("loc_view_".$type,$post_id);
					$this->check_and_save_checkbox("loc_diff_".$type,$post_id);
				}
				else {
					$this->check_and_save_checkbox("loc_edit_".$type,$post_id);
					$this->check_and_save_checkbox("loc_new_".$type,$post_id);
					$this->check_and_save_checkbox("loc_search_".$type,$post_id);
					$this->check_and_save_checkbox("loc_all_".$type,$post_id);
				}
			}
			// roles:
			global $wp_roles;
			$roles = $wp_roles->roles;
			foreach($roles as $role_name=>$role_arr) {
				$this->check_and_save_checkbox("role_".$role_name,$post_id);
			}
		}
	}
	
	// saves post data from checkboxes
	public function check_and_save_checkbox($key,$post_id = null) {
		if(!$post_id) {
			global $post;
			$post_id = $post->ID;
		}
		$key = $this->custom_field_prefix . $key;
		if(isset($_POST[$key])) {
			update_post_meta($post_id, $key, 1);
		}
		else {
			// cut down on the number of useless meta keys we create in the db by never actually creating
			// a negative... just set them to false if they've previously been created
			if(get_post_meta($post_id, $key, true)) {
				update_post_meta($post_id, $key, 0);
			}
		}
	}
	private function get_checkbox($key, $msg, $class='', $default_checked=false) {
		$checked = '';
		$key = $this->custom_field_prefix . $key;
		if((isset($this->custom[$key][0]) && $this->custom[$key][0] == 1) || (!isset($this->custom[$key][0]) && $default_checked)) {
			$checked = " checked='checked' ";
		}
		$class = esc_html($class);
		$key = esc_html($key);
		$msg = esc_html($msg);
		$ret = "<div class='checkbox {$class}'>";
		$ret .= "<input id='{$key}_hidden' name='{$key}' class='{$class}' type='hidden' value='0' />";
		$ret .= "<input id='{$key}' name='{$key}' class='{$class}' type='checkbox' {$checked} value='{$key}' />";
		$ret .=  "<label for='{$key}'>{$msg}</label>";
		$ret .=  "</div>";
		return $ret;
	}
	
	private function get_textfield($key, $msg, $class='') {
		$checked = '';
		$key = $this->custom_field_prefix . $key;
		$key = esc_html($key);
		$class = esc_html($class);
		$msg = esc_html($msg);
		$value = esc_html($this->custom[$key][0]);
		$ret = "<div class='textfield {$class}'>";
		$ret .= "<input id='{$key}' name='{$key}' class='{$class}' type='text' value='{$value}' />";
		$ret .=  "<label for='{$key}'>{$msg}</label>";
		$ret .=  "</div>";
		return $ret;
	}
	
	public function display_info_metabox() {
		global $post;
		// cache all of this posts custom fields
		$this->custom = get_post_custom($post->ID);
			
		$output = '';
		
		$nonce_id = $this->nonce_id;
		$nonce = wp_create_nonce($nonce_id);
		$output .= "<input type='hidden' value='{$nonce}' id='{$nonce_id}' name='{$nonce_id}' />";
			
		$output .= "<div class='dsn_meta_box'>"; // open #dsn_meta_box
		
		// add role options
		$roles = $this->get_roles();
		$output .= "<div class='roles' id='dsn_meta_roles'>";
		$output .= "<h4>" . esc_html__("Show for the following roles:", DSNMANAGER_TEXTDOMAIN) . "</h4>";
        foreach($roles as $role_name=>$role_arr) {
			$output .= "<div class='role meta_item'>";
			$output .= $this->get_checkbox("role_".$role_name,translate_user_role($role_arr['name']));
			$output .= "</div>";
		}
		$output .= "</div>";
		
		// add misc options
		$output .= "<div id='dsn_meta_other'>"; // open #dsn_meta_other
		$output .= "<h4>" . esc_html__("Miscellaneous Options:", DSNMANAGER_TEXTDOMAIN) . "</h4>";
		
		$output .= "<div class='meta_item dsn_hide_title'>";
		$output .= $this->get_checkbox("hide_title",esc_html__("Hide the title of this post in admin notices", DSNMANAGER_TEXTDOMAIN));
		$output .= "</div>";
		
		$output .= "<div class='meta_item dsn_hide_title_widget'>";
		$output .= $this->get_checkbox("hide_title_widget",esc_html__("Hide the title of this post in widget", DSNMANAGER_TEXTDOMAIN));
		$output .= "</div>";
		
		$output .= "<div class='meta_item dsn_hide_title_shortcode'>";
		$output .= $this->get_checkbox("hide_title_shortcode",esc_html__("Hide the title of this post in shortcode", DSNMANAGER_TEXTDOMAIN));
		$output .= "</div>";
		
		if(defined('DSN_SHORTCODE') && strlen(DSN_SHORTCODE)) {
			$sc = DSN_SHORTCODE;
		}
		else {
			$sc = $this->shortcode;
		}
		
		$output .= "<div class='meta_item dsn_shortcode'>";
		$sn = "[" . htmlentities($sc,ENT_QUOTES) . "]";
		if(isset($_GET['post']) && (int)$_GET['post']) {
			$sn = sprintf("[" . htmlentities($sc,ENT_QUOTES) . " id='%d']", htmlentities((int)$_GET['post'],ENT_QUOTES));
		}
		$output .= $this->get_checkbox("shortcodable",esc_html(sprintf(__("Allow use in shortcode %s"), $sn, DSNMANAGER_TEXTDOMAIN)));
		$output .= "</div>";
		
		$output .= "</div>"; // close #dsn_meta_other
		
		// add format options
		$output .= "<div class='formats' id='dsn_meta_formats'>";
		$output .= "<h4>" . esc_html__("Display as these types of note:", DSNMANAGER_TEXTDOMAIN) . "</h4>";
		$output .= "
		<div class='meta_item'>" . $this->get_checkbox("contextual_help",esc_html__("Help tab (WP3.3+)", DSNMANAGER_TEXTDOMAIN),'child_check',true) . "</div>
		<div class='meta_item'>" . $this->get_checkbox("admin_notice",esc_html__("Admin notice", DSNMANAGER_TEXTDOMAIN),'child_check',true) . "</div>
		<div class='meta_item'>" . $this->get_checkbox("dashboard_widget",esc_html__("Dashboard widget", DSNMANAGER_TEXTDOMAIN),'child_check',true) . "</div>
		<div class='meta_item'>" . $this->get_checkbox("instruction_manual",esc_html__("Instruction manual", DSNMANAGER_TEXTDOMAIN),'child_check',true) . "</div>
		";
		$output .= "</div>";
		
		// add post type/page combinations table
		$output .= "<div class='dsn_options' id='dsn_meta_locations'>"; // open #dsn_meta_locations
		
		$ct = 0;
		$output .= "<table id='dsn_note_options' width='100%'>";
		// add 'everywhere' checkbox
		if($ct++ % 2 == 0) $class = ' even ';
		else $class = ' odd ';
		$output .= "<tr class='{$class} '>
		<td colspan='4'><h4>" . esc_html__("Display help tab and/or admin notice on these pages:", DSNMANAGER_TEXTDOMAIN) . "</h4></td>
		<td>" . $this->get_checkbox("loc_everywhere",esc_html__("Everywhere", DSNMANAGER_TEXTDOMAIN),'master_check') . "</td>
		</tr>";
		
		// get all taxonomies
		$args=array(
		  'public'   => true,
		//  '_builtin' => false
		); 
		$op = 'objects'; // or objects
		$operator = 'and'; // 'and' or 'or'
		$taxonomies=get_taxonomies($args,$op,$operator);
			
		foreach($this->post_types as $type=>$type_obj) {
			// we'll add the special case for revision at the end instead to make it look better
			if($type_obj->name != 'revision') {
				$tax_array = array();
				foreach($taxonomies as $taxonomy) {
					foreach($taxonomy->object_type as $ot) {
						if($ot == $type_obj->name) {
							$tax_array[$taxonomy->name] = $taxonomy->label;
						}
					}
				}
				
				$output .= $this->display_row("{$type_obj->name}", $ct, 
					array(
						'edit'=>__('Edit'),
						'new'=>__('New'),
						'search'=>__('Search'),
						'tax'=>$tax_array,
					)
				);
			}
		}
		// add links
		$output .= $this->display_row("links", $ct, array('edit'=>__('Edit'),'new'=>__('New'),'search'=>__('Search'),'tax_category'=>__('Categories')));
		// add revision
		$output .= $this->display_row("revision", $ct, array('view'=>__('View'),'diff'=>__('Compare')));
		// add comments
		$output .= $this->display_row("comments", $ct, array('edit'=>__('Edit'),'search'=>__('Search')));
		// add dashboard location
		$output .= $this->display_row("dashboard", $ct, array('view'=>__('Dashboard')));
		// add "plugins" items
		$output .= $this->display_row("plugins", $ct, array('edit'=>__('Edit'),'new'=>__('Install'),'search'=>__('Installed')));
		// add "user" items
		$output .= $this->display_row("links", $ct, array('edit'=>__('Profile'),'new'=>__('New'),'search'=>__('Search')));
		
		// add "appearance" items
		$output .= $this->display_row("appearance", $ct, array('editor'=>__('Editor'),'widgets'=>__('Widgets'),'menus'=>__('Menus'),'themes'=>__('Themes')));
		// add "appearance - theme" subpage textfield
		$output .= $this->display_row("themes.php", $ct, array('page'=>'?page='),true);
		
		// add "tools" items
		$output .= $this->display_row("tools", $ct, array('search'=>__('Available Tools'),'import'=>__('Import'),'export'=>__('Export')));
		// add "tools" subpage textfield
		$output .= $this->display_row("tools.php", $ct, array('page'=>'?page='),true);
		
		// add "settings" items
		$output .= $this->display_row("settings", $ct, array('general'=>__('General'),'writing'=>__('Writing'),'reading'=>__('Reading'),'discussion'=>__('Discussion')));
		$output .= $this->display_row("settings", $ct, array('media'=>__('Media'),'privacy'=>__('Privacy'),'permalinks'=>__('Permalinks')));
		// add "settings" subpage textfield
		$output .= $this->display_row("options-general.php", $ct, array('page'=>'?page='),true);
		
		// add any other pages that can be added to the admin
		$output .= $this->display_row("admin.php", $ct, array('page'=>'?page='),true);
		
		$output .= "</table>";
		$output .= "</div></div>"; // close #dsn_meta_locations, close #dsn_meta_box
		
		echo $output;
	}
	
	public function display_row($name, &$current_count, $pages, $textfield=false) {
		$return = '';
		$total_columns = 5;
		$current_columns = 0;
		
		if($current_count++ % 2 == 0) $class = ' even ';
		else $class = ' odd ';
		
		$return .= "<tr class='{$class} '>
		<td class='label'>" . esc_html(sprintf(__("%s:"),$name)) . "</td>";
		$current_columns++;
		
		foreach($pages as $page=>$page_name) {
			if($current_columns++ < $total_columns) {
				if($textfield) {	
					$current_columns += 3;
					$return .= "<td colspan='4'>" . $this->get_textfield("loc_{$page}_{$name}",$page_name) . "</td>";
				}
				else {
					if(is_array($page_name)) {
						$return .= "<td>";
						foreach($page_name as $p_id=>$p_name) {
							$p_id = esc_html($p_id);
							$return .= $this->get_checkbox("loc_{$page}_{$name}_{$p_id}",$p_name,'child_check');
						}
						$return .= "</td>";
					}
					else {
						$return .= "<td>" . $this->get_checkbox("loc_{$page}_{$name}",$page_name,'child_check') . "</td>";
					}
				}
			}
		}
		
		while($current_columns++ < $total_columns) {
			$return .= "<td>&nbsp;</td>";
		}
		$return .= "</tr>";
		return $return;
	}
	
	// Create the site note content type
	public function add_content_type() {
		// Not sure if esc_html__() is needed. Does WP escape the names already? If so, switch to __()
		$labels =  array(
			'name' => esc_html__( 'Site Notes' , DSNMANAGER_TEXTDOMAIN),
			'singular_name' => esc_html__( 'Site Note' , DSNMANAGER_TEXTDOMAIN),
			'add_new_item' => esc_html__( 'Add Site Note' , DSNMANAGER_TEXTDOMAIN),
			'edit_item' => esc_html__( 'Edit Site Note' , DSNMANAGER_TEXTDOMAIN),
			'new_item' => esc_html__( 'New Site Note' , DSNMANAGER_TEXTDOMAIN),
			'view_item' => esc_html__( 'View Site Note' , DSNMANAGER_TEXTDOMAIN)
		);
		$supports = array('title','editor','page-attributes','hierarchy');
		if($this->options['support_excerpt']) {
			$supports[] = 'excerpt';
		}
		if($this->options['support_customfields']) {
			$supports[] = 'custom-fields';
		}
		if($this->options['support_revisions']) {
			$supports[] = 'revisions';
		}
		$args = array(
			'labels' => $labels,
			'public' => false,
			'publicly_queryable' => false,
			'show_ui' => true,
			'show_in_menu' => true,
			'hierarchical' => true,
			'page-attributes' => true,
			'revisions' => true,
			'supports' => $supports,
			'capability_type'=>$this->post_type_name_cap,
			'capabilities'=>$this->capabilities,
			'description' => esc_html__('Add helpful notes for site admins', DSNMANAGER_TEXTDOMAIN),
		);
		register_post_type( $this->post_type_name,$args);
	}
	
	/////////
	//
	// OPTIONS PAGE SECTION
	//
	/////////

	// return all options, but also, if there were any defaults that weren't
	// already found in the db, update the options to include those new entries.
	// That ensures that we don't have to constantly use isset when working with
	// options, and also allows brand new options added in new versions to have
	// defaults set.
	private function get_all_options() {
		$name = $this->plugin_id;
		$options = get_option($name);
		// set defaults
		$defaults = array(
			//'support_thumbnail' => true,
			'support_author' => true, 
			'support_customfields' => false,
			'support_revisions' => false,
			'support_excerpt' => true,
			'use_excerpt_filter' => false,
			'use_content_filter' => true,
			'use_grouping' => false,
			'remove_default_help' => false,
			'dashboard_title' => esc_html__('Admin Guide', DSNMANAGER_TEXTDOMAIN),
			'manual_title' => esc_html__("Site Instruction Manual", DSNMANAGER_TEXTDOMAIN),
			'manual_nav' => esc_html__("Site Instructions", DSNMANAGER_TEXTDOMAIN),
			'version'=>false,
			'rolesanitize_administrator'=>true,
		);
		$roles = $this->get_roles();
		foreach($roles as $role=>$role_obj) {
			$defaults['role_'.$role] = false;
			// default administrators to true, and add their capabilities
			if($role=='administrator') {
				$defaults['role_administrator'] = true;
				if(!isset($options['role_administrator'])) {
					global $wp_roles;
					$wp_roles->add_cap($role, $this->post_type_name_cap,true);
					foreach($this->capabilities as $c=>$val) {
						$wp_roles->add_cap($role, $val, true);
					}
				}
			}
		}

		$changed = false;
		foreach($defaults as $name=>$value) {
			if( !isset($options[$name]) ) {
				$options[$name] = $value;
				$changed = true;
			}
		}
		if($changed) {
			update_option($name,$options);
		}
		
		$options = apply_filters('dsn_options', $options);
		return $options;
	}
	
	// register settings for options page
	function register_settings(){
		register_setting( $this->plugin_id, $this->plugin_id, array($this,'plugin_options_validate') );
		$section = 'plugin_main';
		add_settings_section($section, esc_html__('General Settings', DSNMANAGER_TEXTDOMAIN), array($this,'settings_section_description'), $this->plugin_id);
		
		$section_roles = 'plugin_roles';
		add_settings_section($section_roles, esc_html__('Permissions', DSNMANAGER_TEXTDOMAIN), array($this,'settings_section_roles_description'), $this->plugin_id);
		
		$cb_check = array($this,'input_checkbox');
		$cb_text = array($this,'input_textfield');
		
		$roles = $this->get_roles();
		foreach($roles as $role=>$role_arr) {
			add_settings_field('role_' . $role, translate_user_role($role_arr['name']), $cb_check, $this->plugin_id, $section_roles, array('id'=>'role_'.$role));
		}
		add_settings_field('rolesanitize_administrator', esc_html(sprintf(__('Sanitize %s html'), translate_user_role('administrator'))), $cb_check, $this->plugin_id, $section_roles, array('id'=>'rolesanitize_administrator'));
		// TODO: use capabilities instead
		/*foreach($roles as $role=>$role_arr) {
			add_settings_field('rolesanitize_' . $role, sprintf(esc_html__('Sanitize %s html'), translate_user_role($role_arr['name'])), $cb_check, $this->plugin_id, $section_roles, array('id'=>'rolesanitize_'.$role));
		}*/
		
		add_settings_field('support_author',esc_html__('Add author name to notes', DSNMANAGER_TEXTDOMAIN), $cb_check, $this->plugin_id, $section, array('id'=>'support_author'));
		add_settings_field('support_customfields',esc_html__('Add custom field support', DSNMANAGER_TEXTDOMAIN), $cb_check, $this->plugin_id, $section, array('id'=>'support_customfields'));
		add_settings_field('support_revisions',esc_html__('Add revision support', DSNMANAGER_TEXTDOMAIN), $cb_check, $this->plugin_id, $section, array('id'=>'support_revisions'));
		add_settings_field('support_excerpt',esc_html__('Add excerpt support', DSNMANAGER_TEXTDOMAIN), $cb_check, $this->plugin_id, $section, array('id'=>'support_excerpt'));
		add_settings_field('use_excerpt_filter',esc_html__('Use content filter on excerpts', DSNMANAGER_TEXTDOMAIN), $cb_check, $this->plugin_id, $section, array('id'=>'use_excerpt_filter'));
		add_settings_field('use_content_filter',esc_html__('Use content filter on full notes', DSNMANAGER_TEXTDOMAIN), $cb_check, $this->plugin_id, $section, array('id'=>'use_content_filter'));
		add_settings_field('use_grouping',esc_html__('Group notes into one box', DSNMANAGER_TEXTDOMAIN), $cb_check, $this->plugin_id, $section, array('id'=>'use_grouping'));
		//add_settings_field('remove_default_help',esc_html__('Remove all default WordPress help tab items', DSNMANAGER_TEXTDOMAIN), $cb_check, $this->plugin_id, $section, array('id'=>'remove_default_help'));
		add_settings_field('dashboard_title', esc_html__('Dashboard widget title', DSNMANAGER_TEXTDOMAIN), $cb_text, $this->plugin_id, $section, array('id'=>'dashboard_title'));
		add_settings_field('manual_title', esc_html__('Instruction manual page title', DSNMANAGER_TEXTDOMAIN), $cb_text, $this->plugin_id, $section, array('id'=>'manual_title'));
		add_settings_field('manual_nav', esc_html__('Instruction manual nav title', DSNMANAGER_TEXTDOMAIN), $cb_text, $this->plugin_id, $section, array('id'=>'manual_nav'));
	}
	function input_checkbox($args) {
		$id = $args['id'];
		$name = $this->plugin_id;
		$checked = ' ';
		if(isset($this->options[$id])) {
			$checked = ($this->options[$id] ? " checked='checked' " : ' ');
		}
		echo "<input id='dsn_{$id}' name='{$name}[{$id}]' type='hidden' {$checked} value='0' />";
		echo "<input id='dsn_{$id}' name='{$name}[{$id}]' type='checkbox' {$checked} value='1' />";
	}
	function input_textfield($args, $default='') {
		$id = $args['id'];
		$name = $this->plugin_id;
		$value = esc_html($default);
		if(isset($this->options[$id])) {
			$value = esc_html($this->options[$id]);
		}
		echo "<input id='dsn_{$id}' name='{$name}[{$id}]' size='40' type='text' value='{$value}' />";
	}
	function settings_section_description() {
		echo '';
	}
	function settings_section_roles_description() {
		echo "<p>" . esc_html__('Assign the roles that can create and edit notes. Super-admins can always create and edit notes.', DSNMANAGER_TEXTDOMAIN) . "</p>";
		$tag_html = implode('&gt;, &lt;', $this->allowed_tags);
		echo sprintf("<p>" . esc_html__("Notes are sanitized by stripping all tag attributes and only allowing the following tags: %s.  To remove sanitizing from administrator notes, uncheck the 'sanitize administrator html' checkbox.  However, if users can change the author_id of a site note, it should remain checked.", DSNMANAGER_TEXTDOMAIN) . "</p>","&lt;{$tag_html}&gt;");
	}
	
	function plugin_options_validate($input) {
		$options = get_option($this->plugin_id);
		if(is_array($input)) {
			foreach($input as $key=>$val) {
				$options[$key] = $val;
			}
			
			// for each role, give/remove the edit capability
			// TODO: this should really go in a 'save options' hook, but it works fine here for now
			global $wp_roles;
			$roles = $this->get_roles();
			foreach($roles as $role=>$role_obj) {
				if(isset($input['role_'.$role]) && $input['role_'.$role]) {
					$wp_roles->add_cap($role, $this->post_type_name_cap,true);
					foreach($this->capabilities as $c=>$val) {
						$wp_roles->add_cap($role, $val, true);
					}
				}
				else {
					$wp_roles->add_cap($role, $this->post_type_name_cap,false);
					foreach($this->capabilities as $c=>$val) {
						$wp_roles->add_cap($role, $val, false);
					}
				}
			}
		}
		$options = apply_filters('dsn_save_options', $options);
		return $options;
	}
	
	// add the config page to the admin navigation under 'settings'
	function add_config_menu() {
		$admin_page_title = esc_html__("Site Notes Configuration", DSNMANAGER_TEXTDOMAIN);
		$admin_nav_title = esc_html__("Site Notes", DSNMANAGER_TEXTDOMAIN);
		add_options_page(esc_html($admin_page_title), esc_html($admin_nav_title), 'manage_options', $this->plugin_id, array($this,'options_page'));
	}
	// admin options page
	function options_page() {
		if(!$this->user_has_admin()) {
			wp_die(esc_html__("You don't have permission to access this page.", DSNMANAGER_TEXTDOMAIN));
		}
		$admin_page_title = esc_html__("Site Notes Configuration", DSNMANAGER_TEXTDOMAIN);
		?>
		<div id='dsn-settings'>
		<h2><?php echo $admin_page_title; ?></h2>
		<?php
		/* TODO: find out why the "settings saved" text is only sometimes generated by wordpress */
		if ( isset ($_REQUEST['settings-updated']) && ($_REQUEST['settings-updated'] ) ) {
			echo '<div id="message" class="updated fade">';
			echo '<p><strong>' . __('Settings saved.', DSNMANAGER_TEXTDOMAIN) . '</strong></p>';
			echo '</div>';
		}
		?>
		<form action="options.php" method="post">
		<?php settings_fields( $this->plugin_id ); ?>
		<?php do_settings_sections($this->plugin_id ); ?>
		<input name="Submit" type="submit" value="<?php esc_attr_e('Save Changes', DSNMANAGER_TEXTDOMAIN); ?>" />
		</form></div>
		<?php
	}
	/////////
	//
	// END OPTIONS PAGE SECTION
	// 
	/////////
	
	public function extra_plugin_links_primary($data, $page) {
		if ( $page == $this->plugin_base ) {
			$settings_url = "options-general.php?page=" . $this->plugin_id;
			$data = array_merge($data,array(
				sprintf('<a href="%s">%s</a>',$settings_url, esc_html__('Settings', ANYPARENT_TEXTDOMAIN)),
			));
		}
		return $data;
	}
	public function extra_plugin_links_secondary($data, $page) {
		if ( $page == $this->plugin_base ) {
			$settings_url = "options-general.php?page=" . $this->plugin_id;
			$flattr_url = "http://flattr.com/thing/379485/Dashboard-Site-Notes";
			$paypal_url = "https://www.paypal.com/cgi-bin/webscr?business=donate@innerdvations.com&cmd=_donations&currency_code=EUR&item_name=Donation%20for%20Dashboard%20Site%20Notes%20plugin";
			$data = array_merge($data,array(
				sprintf('<a href="%s" target="_blank">%s</a>',$flattr_url, esc_html__('Flattr', DSNMANAGER_TEXTDOMAIN)),
				sprintf('<a href="%s" target="_blank">%s</a>',$paypal_url, esc_html__('Donate', DSNMANAGER_TEXTDOMAIN)),
				sprintf('<a href="%s">%s</a>',$settings_url, esc_html__('Settings', ANYPARENT_TEXTDOMAIN)),
			));
		}
		return $data;
	}
}
