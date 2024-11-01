<?php
/**
 * Plugin Name: myBB Integration by TheCartPress
 * Plugin URI:
 * Description: Auto create myBB user when user create account in WordPress, and it will also auto login to myBB whenever user login in WordPress.
 * Version: 1.3.0
 * Stable tag: 1.3.0
 * Requires PHP: 5.6
 * Requires at least: 5.5
 * Tested up to: 5.8
 * Author: TCP Team
 * Author URI: https://www.thecartpress.com
 * WC tested up to: 5.9.0
 */
defined('ABSPATH') or exit;

class TCP_mybb_registration {

	const TCP_REGISTER_MYBB_USER_ADMIN_PAGE = 'tcp-mybb-registration-admin';
	const ENABLE_DEBUG = false;
	const PLUGIN_ID = 'tcp-user-registration-for-mybb';

	var $_premium_installed;
	var $_premium_active;

	function __construct() {
		$tcp_f = __DIR__ . '/tcp.php';
		if (file_exists($tcp_f)) {
			require_once $tcp_f;
		}
		if (is_admin() && class_exists('TCP_Menu')) {
			if (method_exists('TCP_Menu', 'add_submenu')) {
				TCP_Menu::add_submenu([
					'plugin_id' => self::PLUGIN_ID,
					'page_title' => __("myBB Integration", "tcp-mybb-registration"),
					'menu_title' => __("myBB Integration", "tcp-mybb-registration"),
					'menu_slug' => self::TCP_REGISTER_MYBB_USER_ADMIN_PAGE,
					'function' => [$this, 'tcp_mybb_registration_display_options'],
				]);
			} else {
				add_action('admin_menu', [$this, 'tcp_mybb_registration_menu'], 20);
			}
		}
		add_action('admin_init', [$this, 'tcp_mybb_registration_init']);
		add_action('admin_post_tcp_wp_sync_mybb_user', [$this, 'tcp_wp_sync_mybb_user']);
		add_action('admin_post_tcp_wp_sync_mybb_clear_error_users', [$this, 'tcp_wp_sync_mybb_clear_error_users']);
		add_action('user_register', [$this, 'tcp_mybb_registration']);
		add_action('wp_login', [$this, 'tcp_mybb_registration_login']);
		add_action('wp_logout', [$this, 'tcp_mybb_registration_logout']);
		add_action('admin_notices', [$this, 'admin_notices']);
		add_action('tcp_cron_remaining_mybb_user', [$this, 'tcp_wp_sync_mybb_user']);
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'tcp_mybb_registration_settings_links']);
		register_activation_hook(__FILE__, [$this, 'tcp_mybb_registration_active']);
		register_deactivation_hook(__FILE__, [$this, 'tcp_mybb_registration_deactivate']);
	}

	// show success notice in user edit profile page after reset credit/due
	function admin_notices() {
		$message = '';
		if (!empty($message)) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo esc_attr($message); ?></p>
				<button type="button" class="notice-dismiss">
					<span class="screen-reader-text"><?php _e('Dismiss this notice.', 'tcp-mybb-registration'); ?></span>
				</button>
			</div>
			<?php
		} else {
			$notice = get_transient('tcp_mybb_registration_notice');
			if (is_array($notice) && isset($notice['status'], $notice['message'])) {
				?>
				<div class="notice notice-<?php echo $notice['status']; ?> is-dismissible">
					<p><?php echo $notice['message']; ?></p>
					<button type="button" class="notice-dismiss">
						<span class="screen-reader-text"><?php _e('Dismiss this notice', 'tcp-mybb-registration'); ?></span>
					</button>
				</div>
				<?php
				delete_transient('tcp_mybb_registration_notice');
			}
		}
	}

	function tcp_mybb_registration_settings_links($links) {
		$plugin_links = [
			'<a href="' . esc_url(admin_url('/admin.php?page=' . self::TCP_REGISTER_MYBB_USER_ADMIN_PAGE)) . '">' . __('Settings', 'textdomain') . '</a>'
		];
		return array_merge($plugin_links, $links);
	}

	function tcp_mybb_registration_active() {
		add_option('tcpmybbregister_tableprefix', "mybb_"); // , "Table Prefix");
		add_option('tcpmybbregister_mybbadminemail', ""); // , "Mybb Admin Email");
		add_option('tcpmybbregister_cookie_path', "/mybb/"); // , "Mybb Cookies Path");
		add_option('tcpmybbregister_mybbemailcontent', "Hi %mybb_username%, \r\n\nYour account for %wp_title% forum has been auto created. You can proceed to login in %mybb_link%. \r\n\nUsername: %mybb_username% \r\nPassword: %mybb_password% \r\n\nRegards,\r\n%wp_title%");
		add_option('tcpmybbregister_mybbemailsubject', "%wp_title% Forum Account Creation");
	}

	function tcp_mybb_registration_init() {
		register_setting('tcpmybbregister_opt', 'tcpmybbregister_tableprefix');
		register_setting('tcpmybbregister_opt', 'tcpmybbregister_mybbadminemail');
		register_setting('tcpmybbregister_opt', 'tcpmybbregister_cookie_path');
		register_setting('tcpmybbregister_opt', 'tcpmybbregister_mybbemailcontent');
		register_setting('tcpmybbregister_opt', 'tcpmybbregister_mybbemailsubject');
	}

	function tcp_mybb_registration_deactivate() {
		delete_option('tcpmybbregister_tableprefix');
		delete_option('tcpmybbregister_mybbadminemail');
		delete_option('tcpmybbregister_cookie_path');
		delete_option('tcpmybbregister_mybbemailcontent');
		delete_option('tcpmybbregister_mybbemailsubject');
		delete_option('tcpmybbregister_new_user_notified');
		wp_clear_scheduled_hook('tcp_cron_remaining_mybb_user', [123]);
	}

	function tcp_mybb_registration_menu() {
		add_submenu_page('thecartpress',
			__("myBB Integration", "tcp-mybb-registration"),
			__("myBB Integration", "tcp-mybb-registration"),
			'manage_options',
			self::TCP_REGISTER_MYBB_USER_ADMIN_PAGE,
			array($this, "tcp_mybb_registration_display_options"));
	}

	function tcp_mybb_get_next_cron_time($cron_name) {
		foreach (_get_cron_array() as $timestamp => $crons) {
			if (in_array($cron_name, array_keys($crons))) {
				return get_date_from_gmt(date('Y-m-d H:i:s', $timestamp), get_option('date_format') . ' - ' . get_option('time_format'));
			}
		}
		return false;
	}

	function tcp_mybb_seconds_to_time($seconds) {
		$dtF = new \DateTime('@0');
		$dtT = new \DateTime("@$seconds");
		return $dtF->diff($dtT)->format('%h hours, %i minutes and %s seconds');
	}

	function tcp_mybb_registration_display_options() {
		$page_url = admin_url('admin.php?page=' . self::TCP_REGISTER_MYBB_USER_ADMIN_PAGE);
		$current_url = $page_url;
		$tab = 'settings';
		if (isset($_GET['tab']) && (sanitize_title($_GET['tab']) == 'premium' || sanitize_title($_GET['tab']) == 'log')) {
			$tab = sanitize_title($_GET['tab']);
			$current_url = add_query_arg('tab', $tab, $current_url);
		}
		$premium_installed = in_array('tcp-user-registration-for-mybb-premium/tcp-user-registration-for-mybb-premium.php', apply_filters('active_plugins', get_option('active_plugins')));
		$users_failed = get_option('tcp_mybb_duplicate_users', []);
		$next_schedule_time = $this->tcp_mybb_get_next_cron_time('tcp_cron_remaining_mybb_user');
		$email_failed = get_option("tcp_mybb_send_email_failed", []);
		if (!$this->premium_active() && $next_schedule_time) {
			wp_clear_scheduled_hook('tcp_cron_remaining_mybb_user', [123]);
			$next_schedule_time = false;
		}

		$markdown_supported = (class_exists('WPCom_Markdown')) ?
			__("Markdown is supported.", "tcp-mybb-registration") :
			__("Markdown is <b>not</b> supported. In order to support Markdown, you will need to <a href=" . admin_url('/admin.php?page=jetpack#/writing') . ">activate the Markdown syntax</a> in Jetpack's settings.", "tcp-mybb-registration");
		?>
		<div class="wrap">
			<h2><?php _e("myBB User Integration", "tcp-mybb-registration") ?></h2>
		<?php if ($premium_installed) { ?>
			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url($page_url); ?>"<?php echo $tab == 'settings' ? ' class="current"' : ''; ?>><?php _e('Settings', 'wcjsonsync'); ?></a> |
				</li>
				<li>
					<a href="<?php echo esc_url(add_query_arg('tab', 'log', $page_url)); ?>"<?php echo $tab == 'log' ? ' class="current"' : ''; ?>><?php _e('Log', 'wcjsonsync'); ?></a> |
				</li>
				<li>
					<a href="<?php echo esc_url(add_query_arg('tab', 'premium', $page_url)); ?>"<?php echo $tab == 'premium' ? ' class="current"' : ''; ?>><?php _e('Premium', 'wcjsonsync'); ?></a>
				</li>
			</ul>
		<?php } else { ?>
			<ul class="subsubsub">
				<li>
					<a href="<?php echo esc_url($page_url); ?>"<?php echo $tab == 'settings' ? ' class="current"' : ''; ?>><?php _e('Settings', 'wcjsonsync'); ?></a> |
				</li>
				<li>
					<a href="<?php echo esc_url(add_query_arg('tab', 'log', $page_url)); ?>"<?php echo $tab == 'log' ? ' class="current"' : ''; ?>><?php _e('Log', 'wcjsonsync'); ?></a>
				</li>
			</ul>

		<?php } ?>
		<div class="clear"></div>
		<?php if ($tab == 'settings'): ?>
			<?php settings_errors(); ?>
				<form method="post" action="options.php">
			<?php settings_fields('tcpmybbregister_opt'); ?>
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><label><?php _e("Mybb Table Prefix", "tcp-mybb-registration"); ?></label></th>
							<td><input type="text" name="tcpmybbregister_tableprefix" value="<?php echo get_option('tcpmybbregister_tableprefix'); ?>" /> </td>
						</tr>
						<tr valign="top">
							<th scope="row"><label><?php _e("Mybb Cookie Path (eg: /mybb/)", "tcp-mybb-registration"); ?></label></th>
							<td><input type="text" name="tcpmybbregister_cookie_path" value="<?php echo get_option('tcpmybbregister_cookie_path'); ?>" /> </td>
						</tr>
						<tr valign="top">
							<th scope="row"><label><?php _e("From email (Sending MyBB user new password)", "tcp-mybb-registration"); ?></label></th>
							<td>
								<input type="text" name="tcpmybbregister_mybbadminemail" value="<?php echo get_option('tcpmybbregister_mybbadminemail'); ?>" size="30"/>
								<span class="description" style ="display:block"><?php _e("Format: <code>Name &lt;email@domain.com&gt;</code>", "tcp-mybb-registration") ?>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row"><label><?php _e("Email Subject", "tcp-mybb-registration"); ?></label></th>
							<td>
								<p><input type="text" name="tcpmybbregister_mybbemailsubject" value="<?php echo get_option('tcpmybbregister_mybbemailsubject'); ?>" size="50"/> </p>
								<p><span class="description" style ="display:block"><?php _e("Placeholders: <code>%mybb_link%</code>, <code>%mybb_username%</code>, <code>%mybb_password%</code>, <code>%wp_title%</code>", "tcp-mybb-registration") ?></span></p>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row"><label><?php _e("Email Message", "tcp-mybb-registration"); ?></label></th>
							<td>
								<textarea name="tcpmybbregister_mybbemailcontent" class="large-text" rows="8" /><?php echo get_option('tcpmybbregister_mybbemailcontent'); ?></textarea>
								<p><span class="description" style ="display:block"><?php _e("Placeholders: <code>%mybb_link%</code>, <code>%mybb_username%</code>, <code>%mybb_password%</code>, <code>%wp_title%</code>", "tcp-mybb-registration") ?></span></p>
								<p><span class="description" style ="display:block"><?php echo $markdown_supported ?></span></p>
							</td>
						</tr>
					</table>
			<?php
			submit_button();
			?>
				</form>
					<?php if ($this->premium_active() && ($next_schedule_time)): ?>
						<?php do_action('tcp_mybb_cron_status'); ?>
					<?php endif; ?>
				<p><form action="<?php echo esc_attr('admin-post.php'); ?>" method="post">
					<input type="hidden" name="action" value="tcp_wp_sync_mybb_user">
					<input type="submit" value="
				<?php if ($this->premium_active()): ?>
									 Sync All Users to MyBB
			<?php else: ?>
									 Sync first 20 Users to MyBB
					<?php endif; ?>" class="button button-primary"
					<?php if (!$next_schedule_time): ?>
								 <?php else: ?>
									 disabled
								 <?php endif; ?>>
				</form></p>
							 <?php elseif ($tab == 'premium'): ?>
								 <?php do_action('tcp_mybb_registeration_premium_setting'); ?>
							 <?php elseif ($tab == 'log'): ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label><?php _e("User fail to sync to mybb (Username or email exist)", "tcp-mybb-registration"); ?></label></th>
					<td>
			<?php
			foreach ($users_failed as $user_id_failed) {
				$wp_user = get_user_by('id', $user_id_failed);
				echo '<li>' . htmlspecialchars($wp_user->user_login) . ' (' . $user_id_failed . ') - ' . htmlspecialchars($wp_user->user_email) . '<br>';
			}
			?>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row"><label><?php _e("Send email failed", "tcp-mybb-registration"); ?></label></th>
					<td>
			<?php
			foreach ($email_failed as $user_email_failed) {
				echo '<li>' . htmlspecialchars($user_email_failed) . '<br>';
			}
			?>
					</td>
				</tr>
			</table>
			<p><form action="<?php echo esc_attr('admin-post.php'); ?>" method="post">
				<input type="hidden" name="action" value="tcp_wp_sync_mybb_clear_error_users">
				<input type="submit" value="Clear Error Log" class="button button-primary">
			</form></p>
		<?php endif; ?>
		</div>
		<?php
	}

	function tcp_mybb_registration_login($user_login) {
		if ($this->tcp_mybb_table_exist()) {
			$user_obj = get_user_by('login', $user_login);
			$this->tcp_mybb_registration_user($user_obj->ID);
		}
	}

	function tcp_mybb_registration($user_id = 1) {
		if ($this->tcp_mybb_table_exist()) {
			$this->tcp_mybb_registration_user($user_id);
		}
	}

	function tcp_wp_sync_mybb_clear_error_users() {
		delete_option('tcp_mybb_duplicate_users');
		delete_option('tcp_mybb_send_email_failed');
		$url = admin_url('admin.php?page=' . self::TCP_REGISTER_MYBB_USER_ADMIN_PAGE . '&tab=log');
		wp_redirect($url);
	}

	function tcp_mybb_registration_user($user_id = 1, $is_sync = false) {
		global $wpdb;
		$usergroup = 2;
		$wp_user = get_user_by('id', $user_id);
		$ms_username = htmlspecialchars($wp_user->user_login);
		$ms_email = htmlspecialchars($wp_user->user_email);
		$ms_password = $this->tcp_mybb_random_str(10);
		$wp_title = get_bloginfo('name');

		if (isset($_POST['password'])) {
			$ms_password = $_POST['password'];
		}

		$query_mybb_bburl = $wpdb->get_results("SELECT * FROM " . get_option('tcpmybbregister_tableprefix') . "settings WHERE `name`='bburl'");
		$webroot = '';
		if (!empty($query_mybb_bburl)) {
			if (substr($query_mybb_bburl[0]->value, -1) == '/') {
				$webroot = substr($query_mybb_bburl[0]->value, 0, -1);
			} else {
				$webroot = $query_mybb_bburl[0]->value;
			}
		}

		$user_failed = get_option('tcp_mybb_duplicate_users');
		if (!$user_failed) {
			$user_failed = array();
		}

		$query = $wpdb->get_results("SELECT username FROM " . get_option('tcpmybbregister_tableprefix') . "users WHERE `username`='{$ms_username}' OR `email`='{$ms_email}'");
		if (!isset($_POST['password']) && $is_sync) {
			if (!empty($query)) {
				if (!in_array($user_id, $user_failed, true)) {
					array_push($user_failed, $user_id);
				}
				update_option("tcp_mybb_duplicate_users", $user_failed);
			}
		}

		if (empty($query)) {
			/*			 * **************************Hash password****************************** */
			$salt = $this->tcp_mybb_generate_salt();
			$loginkey = $this->generate_loginkey();
			$hashed_password = $this->tcp_mybb_salt_password(md5($ms_password), $salt);
			/*			 * **************************Hash password****************************** */
			$regdate = time();
			$query = $wpdb->query("INSERT INTO " . get_option('tcpmybbregister_tableprefix') . "users (username,password,salt,loginkey,email,receivepms,allownotices,pmnotify,usergroup,regdate)
            VALUES('$ms_username','$hashed_password','$salt','$loginkey','$ms_email',1,1,1,2,'$regdate')");
			/*			 * **************************Send email***************************** */
			$placeholders = [
				'%mybb_link%' => $webroot,
				'%wp_title%' => $wp_title,
				'%mybb_username%' => $ms_username,
				'%mybb_password%' => $ms_password,
			];

			$emailmessage = str_replace(array_keys($placeholders), array_values($placeholders), get_option('tcpmybbregister_mybbemailcontent'));
			if (class_exists('WPCom_Markdown') && $emailmessage) {
				$emailmessage = WPCom_Markdown::get_instance()->get_parser()->transform($emailmessage);
			} else {
				$emailmessage = htmlentities($emailmessage);
			}

			$emailmessage = nl2br($emailmessage);
			$emailto = $ms_email;
			$emailheaders[] = 'From: ' . get_option('tcpmybbregister_mybbadminemail');
			$emailsubject = str_replace(array_keys($placeholders), array_values($placeholders), get_option('tcpmybbregister_mybbemailsubject'));
			$emailheaders[] = 'Content-Type: text/html; charset=UTF-8';

			$result = wp_mail($emailto, $emailsubject, $emailmessage, $emailheaders);
			if (!$result) {
				$email_failed = get_option('tcp_mybb_send_email_failed');
				if (!$email_failed) {
					$email_failed = array();
				}
				array_push($email_failed, $ms_email . ' ' . $ms_password);
				update_option("tcp_mybb_send_email_failed", $email_failed);
			}
		}

		if (!$is_sync) {
			$mybb_user = $wpdb->get_results("SELECT uid, loginkey FROM " . get_option('tcpmybbregister_tableprefix') . "users WHERE `email`='{$ms_email}'");
			$expiry = strtotime('+12 month');
			if (!empty($mybb_user)) {
				// create mybbuser cookie
				$mybbuser_value = $mybb_user[0]->uid . '_' . $mybb_user[0]->loginkey;
				$mybb_host = "";
				if (isset($webroot) && !empty($webroot)) {
					$mybb_host = $query_mybb_bburl[0]->value;
				} else {
					$mybb_host = $_SERVER['HTTP_HOST'];
				}

				if (isset($mybb_host)) {
					$mybb_host = parse_url($mybb_host)["host"];
					setcookie('mybbuser', $mybbuser_value, $expiry, get_option('tcpmybbregister_cookie_path'), $mybb_host);
					// create sid cookie
					$mybb_session = $wpdb->get_results("SELECT sid FROM " . get_option('tcpmybbregister_tableprefix') . "sessions WHERE `uid`='{$mybb_user[0]->uid}'");
					if (!empty($mybb_session)) {
						setcookie('sid', $mybb_session[0]->sid, $expiry, get_option('tcpmybbregister_cookie_path'), $mybb_host);
					}
				}
			}
		}
	}

	function sendEmail() {

	}

	function tcp_mybb_registration_logout() {
		//logout mybb account
		setcookie('mybbuser', '', time() - 3600, get_option('tcpmybbregister_cookie_path'));
		setcookie('sid', '', time() - 3600, get_option('tcpmybbregister_cookie_path'));
	}

	function tcp_wp_registration_strip_subdomain($host) {
		return preg_replace("/.*?([^\.]+)(\.((com?\.\w+)|\w+))$/i", '\1\2', $host);
	}

	function tcp_mybb_get_users() {
		$total_email_per_hour = 100;
		$cron_users = get_option('tcpmybbregister_new_user_notified');
		if (!$cron_users || empty($cron_users)) {
			$args = array(
				'fields' => array('ID')
			);
			$cron_users = get_users($args);
		}

		if (!$this->premium_active()) {
			$notify_users = array_slice($cron_users, 0, 20);
			$cron_users = [];
		} else if (count($cron_users) > $total_email_per_hour) {
			$notify_users = array_splice($cron_users, 0, $total_email_per_hour);
		} else {
			$notify_users = array_splice($cron_users, 0, count($cron_users));
		}

		update_option('tcpmybbregister_new_user_notified', $cron_users);

		return $notify_users;
	}

	function tcp_mybb_table_exist() {
		global $wpdb;
		$table_name = get_option('tcpmybbregister_tableprefix') . 'users';
		return ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name);
	}

	function tcp_wp_sync_mybb_user($arg1 = false) {
		if (!$this->tcp_mybb_table_exist()) {
			$notice = [
				'status' => 'error',
				'message' => __('Missing mybb user table. Do you set your mybb table prefix correctly in settings page?', 'wc-term-credit')
			];
			set_transient('tcp_mybb_registration_notice', $notice, 15);
			$url = admin_url('/admin.php?page=' . self::TCP_REGISTER_MYBB_USER_ADMIN_PAGE);
			wp_redirect($url);
			return;
		}

		$users = $this->tcp_mybb_get_users();

		if ($this->premium_active() && get_option('tcpmybbregister_new_user_notified') && !empty(get_option('tcpmybbregister_new_user_notified'))) {
			do_action('tcp_mybb_schedule_sync_event');
		}

		foreach ($users as $user) {
			$this->tcp_mybb_registration_user($user->ID, true);
		}

		if (!$arg1) {
			$notice = [
				'status' => 'success',
				'message' => __('Sync success. Check log for more details.', 'wc-term-credit')
			];
			set_transient('tcp_mybb_registration_notice', $notice, 15);
			$url = admin_url('/admin.php?page=' . self::TCP_REGISTER_MYBB_USER_ADMIN_PAGE);
			wp_redirect($url);
		}
	}

	function tcp_mybb_random_str($length = 8, $complex = false) {
		$set = array_merge(range(0, 9), range('A', 'Z'), range('a', 'z'));
		$str = array();

		if ($complex == true) {
			$str[] = $set[$this->tcp_mybb_my_rand(0, 9)];
			$str[] = $set[$this->tcp_mybb_my_rand(10, 35)];
			$str[] = $set[$this->tcp_mybb_my_rand(36, 61)];
			$length -= 3;
		}

		for ($i = 0; $i < $length; ++$i) {
			$str[] = $set[$this->tcp_mybb_my_rand(0, 61)];
		}
		shuffle($str);

		return implode($str);
	}

	function tcp_mybb_my_rand($min = 0, $max = PHP_INT_MAX) {
		// backward compatibility
		if ($min === null || $max === null || $max < $min) {
			$min = 0;
			$max = PHP_INT_MAX;
		}

		if (version_compare(PHP_VERSION, '7.0', '>=')) {
			try {
				$result = random_int($min, $max);
			} catch (Exception $e) {

			}

			if (isset($result)) {
				return $result;
			}
		}
		$seed = secure_seed_rng();
		$distance = $max - $min;
		return $min + floor($distance * ($seed / PHP_INT_MAX));
	}

	function tcp_mybb_generate_salt() {
		$possible = '0123456789abcdefghijklmnopqrstuvwxyz';
		$newsalt = '';
		$i = 0;
		while ($i < 8) {
			$newsalt .= substr($possible, mt_rand(0, strlen($possible) - 1), 1);
			$i++;
		}
		return $newsalt;
	}

	function tcp_mybb_salt_password($password, $salt) {
		return md5(md5($salt) . $password);
	}

	function generate_loginkey() {
		return $this->tcp_mybb_random_str(50);
	}

	function premium_installed() {
		if (is_null($this->_premium_installed)) {
			$this->_premium_installed = in_array('tcp-user-registration-for-mybb-premium/tcp-user-registration-for-mybb-premium.php', apply_filters('active_plugins', get_option('active_plugins')));
		}
		return $this->_premium_installed;
	}

	function premium_active() {
		if (is_null($this->_premium_active)) {
			$premium_info = get_option('tcp_mybb_registration_premium_info', []);
			$this->_premium_active = false;
			if ($this->premium_installed() && !empty($premium_info) && isset($premium_info['premium']) && $premium_info['premium']) {
				if (isset($premium_info['expiry'])) {
					$now = $this->create_datetime();
					$expiry = $this->create_datetime($premium_info['expiry']);
					if (!empty($expiry) && $now <= $expiry) {
						$this->_premium_active = true;
					}
				} else {
					$this->_premium_active = true; // premium without expiry
				}
			}
		}
		return $this->_premium_active;
	}

	/// https://wordpress.stackexchange.com/a/283094
	function get_timezone() {
		// return 'Asia/Kuala_Lumpur';
		$tz = get_option('timezone_string');
		if (!empty($tz)) {
			return $tz;
		}
		$offset = get_option('gmt_offset');
		$hours = (int) $offset;
		$minutes = abs(($offset - (int) $offset) * 60);
		return sprintf('%+03d:%02d', $hours, $minutes);
	}

	function create_datetime($timestamp = 0) {
		$d = new DateTime();
		$tz = $this->get_timezone();
		if (!empty($tz)) {
			$dtz = new DateTimeZone($tz);
			$d->setTimezone($dtz);
		}
		if (!empty($timestamp)) {
			$d->setTimestamp($timestamp);
		}
		return $d;
	}

}

$GLOBALS['tcp_mybb_registration'] = new TCP_mybb_registration();
