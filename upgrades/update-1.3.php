<?php
			// do the version upgrade now; none of these updates are critical and if they fail we
			// don't want to bring the site to a crawl like the previous version.
			$this->options['version'] = '1.3';
			update_option($this->plugin_id, $this->options);
			$this->options = $this->get_all_options();
			
			// set instruction_manual to the opposite of instructions_exclude and rename it
			$sql = "
			UPDATE {$wpdb->postmeta} wppm
			SET meta_value = NOT meta_value, meta_key = '{$prefix}instruction_manual'
			WHERE meta_key = '{$prefix}instructions_exclude'
			";
			$updated_rows = $wpdb->query($sql);
			
			// Create "admin_notice" meta key for each existing dsn_note, since it's now required
			$sql = "
				INSERT IGNORE INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
					SELECT {$wpdb->posts}.ID, '{$prefix}admin_notice', '1'
					FROM {$wpdb->posts}
					WHERE {$wpdb->posts}.post_type = '{$this->post_type_name}'
			";
			$updated_admin_notices = $wpdb->query($sql);
			
			// Create "contextual_help" meta key for each existing dsn_note
			$sql = "
				INSERT IGNORE INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
					SELECT {$wpdb->posts}.ID, '{$prefix}contextual_help', '0'
					FROM {$wpdb->posts}
					WHERE {$wpdb->posts}.post_type = '{$this->post_type_name}'
			";
			$updated_admin_notices = $wpdb->query($sql);
			
			// rename "loc_dashboard" to "dashboard_widget" in anticipation of having a dashboard page location
			// (to allow dashboard tabs/notices instead of just a widget) 
			$sql = "
			UPDATE {$wpdb->postmeta} wppm
			SET meta_key = '{$prefix}dashboard_widget'
			WHERE meta_key = '{$prefix}loc_dashboard'
			";
			$updated_rows = $wpdb->query($sql);
			
			// delete all meta keys attached incorrectly to non-dsn_note post types
			// to minimize risk of deleting meta keys created by other plugins, we'll be as specific as possible rather than just using '_dsn_%'
			$prefix = '_dsn_'; // prefix hardcoded to _dsn_ because it was used in all previous versions
			$pre_role = $prefix . "role_"; // role meta keys
			$pre_loc = $prefix . "loc_"; // locations meta keys
			$pre_hide = $prefix . "hide_title"; // hide_title meta keys
			$pre_instr = $prefix . "instructions_exclude"; // instructions_exclude meta keys
			$sql = "
			DELETE FROM wppm
			USING {$wpdb->postmeta} AS wppm
			WHERE 
				(wppm.meta_key LIKE '{$pre_role}%' OR wppm.meta_key LIKE '{$pre_loc}%' OR wppm.meta_key LIKE '{$pre_hide}%' OR wppm.meta_key LIKE '{$pre_instr}%')
				AND NOT EXISTS (
					SELECT ID
					FROM {$wpdb->posts} AS wpp
					WHERE ID = wppm.post_id AND wpp.post_type = 'dsn_note')";
			$deleted_rows = $wpdb->query($sql);