<?php
/*
Include this file in other plugins' main PHP file:

	$tcp_f = __DIR__ . '/tcp.php';
	if (file_exists($tcp_f)) {
		require_once $tcp_f;
	}
	if (is_admin() && class_exists('TCP_updater')) {
		new TCP_updater($plugin_id, $info_url);
	}
	if (is_admin() && class_exists('TCP_Menu') && method_exists('TCP_Menu', 'add_submenu')) {
		TCP_Menu::add_submenu([
			'plugin_id' => '',
			'page_title' => '',
			'menu_title' => '',
			'menu_slug' => '',
			'function' => [$this, 'create_admin_page'], // optional
			'position' => null, // optional
		]);
	}

 */
defined('ABSPATH') or exit;

/**
 * Changelogs
 *
 * 20220325 - merge tcp-util.php, tcp-menu.php & updater.php into tcp.php, MYSQL_FORMAT added to WP_DateTime
 * 20220323 - changed to TCP_updater, add br2nl(), console_log(), attach_console_log()
 * 20220125 - define Reso_WP_updater class only when is_admin()
 * 20220124 - Update include code & json[banner][low|high] checking
 * 20220121 - change to check current version is lower, instead of different
 * 20220117 - Support set dbm & dbm_key through /wp-admin/plugins.php page
 * 20220113 - info_url become required parameter, json[sections] checking
 * 20220105 - info.json & plugin zip all use cdn.700tb.com
 * 20220104 - Support custom info_url
 * 20211207 - Initial release
 */

//------------------------------------------------------------------------------
// tcp-util.php
//------------------------------------------------------------------------------

if (!function_exists('is_plugin_active')) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if (!function_exists('str_contains')) {
	function str_contains($haystack, $needle) {
		return strpos($haystack, $needle) !== false;
	}
}

if (!function_exists('str_starts_with')) {
	function str_starts_with($haystack, $needle) {
		return substr($haystack, 0, strlen($needle)) === $needle;
	}
}

if (!function_exists('str_ends_with')) {
	function str_ends_with($haystack, $needle) {
		$length = strlen($needle);
		if ($length == 0) {
			return true;
		}
		return substr($haystack, -$length) === $needle;
	}
}

if (!function_exists('split_by_lines')) {
	/**
	 * @param string $string
	 * @return array
	 */
	function split_by_lines($string) {
		return preg_split('/\r\n|\r|\n/', $string);
	}
}

if (!function_exists('debug_log')) {
	/**
	 * E.g:
	 *   debug_log('message');
	 *   debug_log('message, var={1}', $var);
	 *   debug_log('message, var1={1}, var2={2}', $var1, $var2);
	 */
	function debug_log() {
		$n = func_num_args();
		if ($n == 0) {
			return;
		}
		$msg = func_get_arg(0);
		if ($n > 1) {
			for ($i = 1; $i < $n; $i++) {
				$var = var_export(func_get_arg($i), true);
				$var = str_replace(["\r", "\n"], ' ', $var);
				$msg = str_replace('{'. $i .'}', $var, $msg);
			}
		}
		error_log($msg);
	}
}

if (!function_exists('qm_log')) {
	/**
	 * E.g:
	 *   qm_log('message');
	 *   qm_log('message, var={1}', $var);
	 *   qm_log('message, var1={1}, var2={2}', $var1, $var2);
	 */
	function qm_log() {
		$n = func_num_args();
		$has_qm = class_exists('QueryMonitor') && isset($GLOBALS['wp_query']) && is_admin_bar_showing();
		if ($n == 0 || !$has_qm) {
			return;
		}
		$msg = func_get_arg(0);
		if ($n > 1) {
			for ($i = 1; $i < $n; $i++) {
				$var = var_export(func_get_arg($i), true);
				$msg = str_replace('{'. $i .'}', $var, $msg);
			}
		}
		do_action('qm/debug', $msg);
	}
}

if (!function_exists('console_log')) {
	function console_log() {
		$n = func_num_args();
		if ($n == 0) {
			return;
		}
		$msg = func_get_arg(0);
		if ($n > 1) {
			for ($i = 1; $i < $n; $i++) {
				$var = var_export(func_get_arg($i), true);
				$var = str_replace(["\r", "\n"], ' ', $var);
				$msg = str_replace('{'. $i .'}', $var, $msg);
			}
		}
		$logs = get_option('tcp_util_console_log');
		if (!is_array($logs)) {
			$logs[] = date('Y-m-d H:i:s') .' - '. $msg;
		}
		update_option('tcp_util_console_log', $logs, false);
	}
}

if (!function_exists('attach_console_log')) {
	function attach_console_log() {
		static $attached = false;
		if (!$attached) {
			$attached = true;
			add_filter('query_vars', function($vars) {
				$vars[] = 'tcp_util_console_log';
				$vars[] = 'tcp_util_console_log_reset';
				return $vars;
			});
			add_action('parse_request', function($wp) {
				if (array_key_exists('tcp_util_console_log', $wp->query_vars)) {
					$logs = get_option('tcp_util_console_log');
					echo '<pre>';
					if (is_array($logs)) {
						foreach (array_reverse($logs) as $line) {
							echo $line . "\r\n";
						}
					}
					echo '</pre>';
					exit;
				}
				if (array_key_exists('tcp_util_console_log_reset', $wp->query_vars)) {
					delete_option('tcp_util_console_log');
					exit;
				}
			});
		}
	}
}

if (!function_exists('wp_sanitize_text_field')) {
	/**
	 * Do sanitize_text_field() on a string & check minimum and maximum string length.
	 * If min length > 0 and string length is less, return empty string.
	 * If string length > max length, return string characters up to max length.
	 *
	 * @param string $str
	 * @param int $min_len
	 * @param int $max_len
	 * @return string
	 */
	function wp_sanitize_text_field($str, $min_len, $max_len) {
		$str = sanitize_text_field($str);
		if ($min_len > 0 && strlen($str) < $min_len) {
			$str = '';
		}
		if (strlen($str) > $max_len) {
			$str = substr($str, 0, $max_len);
		}
		return $str;
	}
}

if (!function_exists('wp_sanitize_textarea_field')) {
	/**
	 * Do sanitize_textarea_field() on a string & check minimum and maximum string length.
	 * If min length > 0 and string length is less, return empty string.
	 * If string length > max length, return string characters up to max length.
	 *
	 * @param string $str
	 * @param int $min_len
	 * @param int $max_len
	 * @return string
	 */
	function wp_sanitize_textarea_field($str, $min_len, $max_len) {
		$str = sanitize_textarea_field($str);
		if ($min_len > 0 && strlen($str) < $min_len) {
			$str = '';
		}
		if (strlen($str) > $max_len) {
			$str = substr($str, 0, $max_len);
		}
		return $str;
	}
}

// https://www.php.net/manual/en/function.nl2br.php#115182
if (!function_exists('br2nl')) {
	function br2nl($string) {
		return preg_replace('/\<br(\s*)?\/?\>/i', PHP_EOL, $string);
	}
}

if (!class_exists('WP_DateTimeZone')) {
	class WP_DateTimeZone extends DateTimeZone {

		public function __construct() {
			$tz = get_option('timezone_string');
			if (empty($tz)) {
				$offset = get_option('gmt_offset');
				$hours = (int) $offset;
				$minutes = abs(($offset - (int) $offset) * 60);
				$tz = sprintf('%+03d:%02d', $hours, $minutes);
			}
			parent::__construct($tz);
		}

	}
}

if (!class_exists('WP_DateTime')) {
	class WP_DateTime extends DateTime {
		const MYSQL_FORMAT = 'Y-m-d H:i:s';

		/**
		 * DateTime instance that's in WordPress timezone (General > Settings > Timezone)
		 *
		 * E.g:
		 *   new WP_DateTime(1634298308); // unix timestamp
		 *   new WP_DateTime(new DateTime()); // existing DateTime instance
		 *   new WP_DateTime('Y-m-d', '2021-10-15'); // formatted datetime string
		 *   new WP_DateTime(WP_DateTime::MYSQL_FORMAT, '2021-10-15 14:20:50'); // mysql's `datetime` formatted datetime string
		 *   new WP_DateTime(WP_DateTime::MYSQL_FORMAT, '2022-03-25 16:20:00', false); // formatted datetime string already in intended timezone
		 *
		 * @param int|DateTime|string $dt_ts_or_fmt
		 * @param string $formatted_time
		 * @param bool $autoset_timezone
		 */
		public function __construct($dt_ts_or_fmt = 0, $formatted_time = '', $autoset_timezone = true) {
			if (is_string($dt_ts_or_fmt) && !empty($formatted_time)) {
				$dt_ts_or_fmt = DateTime::createFromFormat($dt_ts_or_fmt, $formatted_time);
			}
			parent::__construct();
			if ($dt_ts_or_fmt instanceof DateTime) {
				$this->setTimestamp($dt_ts_or_fmt->getTimestamp());
			} else if (is_int($dt_ts_or_fmt) && !empty($dt_ts_or_fmt)) {
				$this->setTimestamp($dt_ts_or_fmt);
			}
			if ($autoset_timezone) {
				$this->setTimezone(new WP_DateTimeZone());
			}
		}

		public function __toString() {
			return $this->format();
		}

		/**
		 * E.g:
		 *   $dt->format();
		 *   $dt->format(true);
		 *   $dt->format('Y-m-d');
		 *   $dt->format(WP_DateTime::MYSQL_FORMAT); // mysql datetime field
		 *
		 * @param bool|string $fmt_or_date_only
		 * @return string
		 */
		public function format($fmt_or_date_only = false) {
			if (is_bool($fmt_or_date_only)) {
				$date_fmt = get_option('date_format');
				if ($fmt_or_date_only) {
					return $this->format($date_fmt);
				} else {
					$time_fmt = get_option('time_format');
					return $this->format($date_fmt .', '. $time_fmt);
				}
			}
			return parent::format($fmt_or_date_only);
		}

		/**
		 * @return WP_DateTime
		 */
		public function gmt() {
			$dt = $this;
			$dt->setTimezone(new DateTimeZone('GMT'));
			return $dt;
		}

	}
}

//------------------------------------------------------------------------------
// tcp-menu.php
//------------------------------------------------------------------------------

if (!class_exists('TCP_menu')) {

	class TCP_menu {

		/**
		 * 20220323 - add add_submenu()
		 * 20220121 - optimize class, new transient ID & data struct
		 */
		const VERSION = '20220323';
		const PLUGIN_LIST_URL = 'https://app.thecartpress.com/notice/?view=tcp_plugin_list';
		const CONTACT_URL = 'https://www.thecartpress.com/contact/?utm_source=contact&utm_medium=menu&utm_campaign=wporg';
		const WEBSITE_URL = 'https://www.thecartpress.com/?utm_source=visit&utm_medium=menu&utm_campaign=wporg';

		function __construct() {
			add_action('admin_menu', [$this, 'admin_menu'], 1);
			add_action('plugins_loaded', [$this, 'plugins_loaded']);
		}

		function plugins_loaded() {
			if (class_exists('TCPMenu')) {
				remove_action('admin_menu', [$this, 'admin_menu'], 1);
				return;
			}
			add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		}

		function enqueue_scripts() {
			wp_enqueue_style('tcp-menu', plugins_url('/assets/css/menu.css', __FILE__));
		}

		function admin_menu() {
			add_menu_page(
				'TheCartPress', // string $page_title
				'TheCartPress', // string $menu_title
				'manage_options', // string $capability
				'thecartpress', // string $menu_slug
				null,
				plugins_url('/assets/images/tcp-icon.svg', __FILE__),
				26
			);
			add_submenu_page(
				'thecartpress', // string $parent_slug,
				'TCP Plugins', // string $page_title,
				'TCP Plugins', // string $menu_title,
				'manage_options', // string $capability,
				'thecartpress', // string $menu_slug,
				[$this, 'plugins_content'], // callable $function = '',
				0 // int $position = null
			);
		}

		function plugins_content() {
			$tcp_plugins = (array) get_transient('tcp_plugins_v2');
			if (!isset($tcp_plugins['plugins'], $tcp_plugins['promote'])) {
				$tcp_plugins = [
					'plugins' => [],
					'promote' => [],
				];
				$response = wp_remote_get(self::PLUGIN_LIST_URL);
				if (wp_remote_retrieve_response_code($response) == 200) {
					$json = json_decode(wp_remote_retrieve_body($response), true);
					if (is_array($json)) {
						if (isset($json['plugins']) && is_array($json['plugins'])) {
							foreach ($json['plugins'] as $pl) {
								if ($pl['slug'] == 'tcp-fpx-payment') {
									$pl['slug'] = 'tcp-wc-fpx-gateway';
									$pl['download_link'] = str_replace('/fpx_payment_gateway', '/tcp-wc-fpx-gateway/', $pl['download_link']);
								}
								$tcp_plugins['plugins'][] = [
									'slug' => $pl['slug'],
									'name' => $pl['name'],
									'short_description' => $pl['short_description'],
									'version' => $pl['version'],
									'active_installs' => isset($pl['active_installs']) ? $pl['active_installs'] : 0,
									'icon' => $pl['icons']['1x'],
									'download_page' => $pl['download_link'],
								];
							}
						}
						if (isset($json['promote']) && is_array($json['promote'])) {
							foreach ($json['promote'] as $pr) {
								$tcp_plugins['promote'][] = [
									'promote_image' => $pr['promote_image'],
									'promote_link' => $pr['promote_link'],
								];
							}
						}
					}
					set_transient('tcp_plugins_v2', $tcp_plugins, DAY_IN_SECONDS);
				}
			}
			?>
			<div class="tcp_plugins_page wrap">
				<h1>TheCartPress</h1>
				<div class="tcp_plugins">
					<h2 class="title">TCP Plugins</h2>
					<?php

					// promote banner
					if (!empty($tcp_plugins['promote'])) {
						foreach ($tcp_plugins['promote'] as $pr) {
							echo '<div>';
							echo '<a href="'. esc_url($pr['promote_link']) .'"><img src="'. esc_url($pr['promote_image']) .'"></a>';
							echo '</div>';
						}
					}

					// wordpress plugins
					if (!empty($tcp_plugins['plugins'])) {
						echo '<div class="tcp_plugins_cards">';
						foreach ($tcp_plugins['plugins'] as $pl) {
							echo '<a class="tcp_plugins_card_container" href="'. esc_url($pl['download_page']) .'">';
							echo '<table class="tcp_plugins_card"><tr>';
							echo '<td class="tcp_plugin_icon">';
							echo '<img class="tcp_plugin_icon" src="'. esc_url($pl['icon']) .'" alt="img.png"/>';
							echo '</td>';
							echo '<td align="left">';
							echo '<strong>'. esc_html($pl['name']) .'</strong>';
							echo '<br>'. esc_html($pl['short_description']);
							echo '</td>';
							echo '</tr></table>';
							echo '</a>';
						}
						echo '</div>';
					}
					?>
				</div>

				<div class="card">
					<h2>Contact</h2>
					<p>
						Feel free to contact us via <a href="<?php echo self::CONTACT_URL; ?>" target="_blank">contact page</a>
						<br/>
						Website: <a href="<?php echo self::WEBSITE_URL; ?>" target="_blank">https://www.thecartpress.com/</a>
					</p>
				</div>
			</div>
			<?php
		}

		/**
		 * @param array $args {
		 *     Similar to add_submenu_page() parameters
		 *
		 *     @type string plugin_id
		 *     @type string page_title
		 *     @type string menu_title
		 *     @type string menu_slug
		 *     @type callable function - optional, default to generic settings page
		 *     @type int position - optional
		 * }
		 */
		static function add_submenu($args) {
			add_action('admin_menu', function() use ($args) {
				$callback = isset($args['function']) ? $args['function'] : function() use ($args) {
					if (!function_exists('get_plugin_data')) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
					}
					$f = WP_PLUGIN_DIR .'/'. $args['plugin_id'] .'/'. $args['plugin_id'] .'.php';
					$plugin = get_plugin_data($f);
					?>
					<div class="wrap">
						<h2><?php echo $plugin['Name']; ?></h2>
						<p>Version <?php echo $plugin['Version']; ?></p>
						<p><?php echo $plugin['Description']; ?></p>
					</div><?php
				};
				add_submenu_page(
					'thecartpress',
					$args['page_title'],
					$args['menu_title'],
					'manage_options',
					$args['menu_slug'],
					$callback,
					isset($args['position']) ? $args['position'] : null
				);
			}, 20);
		}

	}

	new TCP_menu();
}

//------------------------------------------------------------------------------
// updater.php
//------------------------------------------------------------------------------

