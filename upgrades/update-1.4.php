<?php
		// updates for v1.4.  basically just cleans up meta table from 1.3 disaster.
			$this->options['version'] = '1.4';
			update_option($this->plugin_id, $this->options);
			$this->options = $this->get_all_options();
			
			// select all note ids
			$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = '{$this->post_type_name}'";
			$notes = $wpdb->get_results($sql);
			
			// temporarily rename one record for each note
			foreach($notes as $note) {
				$nid = (int)$note->ID;
				
				$sql = "UPDATE {$wpdb->postmeta} SET meta_key = '_dsn_contextual_help_tmp' WHERE meta_key = '_dsn_contextual_help' AND post_id = '{$nid}' LIMIT 1";
				$wpdb->query($sql);
				
				$sql = "UPDATE {$wpdb->postmeta} SET meta_key = '_dsn_admin_notice_tmp' WHERE meta_key = '_dsn_admin_notice' AND post_id = '{$nid}' LIMIT 1";
				$wpdb->query($sql);
			}
			
			// delete all the bad records
			$sql = "DELETE FROM wp_postmeta WHERE meta_key = '_dsn_contextual_help'";
			$wpdb->query($sql);
			
			$sql = "DELETE FROM wp_postmeta WHERE meta_key = '_dsn_admin_notice'";
			$wpdb->query($sql);
			
			// set record names back to normal
			$sql = "UPDATE {$wpdb->postmeta} SET meta_key = '_dsn_contextual_help' WHERE meta_key = '_dsn_contextual_help_tmp'";
			$wpdb->query($sql);
			
			$sql = "UPDATE {$wpdb->postmeta} SET meta_key = '_dsn_admin_notice' WHERE meta_key = '_dsn_admin_notice_tmp'";
			$wpdb->query($sql);
			