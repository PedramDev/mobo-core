<?php
/**
 * Generate and serve the Persian WooCommerce compatible Iranian city script
 * from the authoritative Mobo address cache.
 *
 * The generated city option value is the real Mobo city ID. This removes the
 * city-to-city manual mapping layer while keeping Persian WooCommerce's
 * checkout field behaviour and public Persian_Woo_iranCities() contract.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_City_Assets {

	const STATUS_OPTION = 'mobo_core_city_assets_status';
	const JS_FILENAME = 'iran_cities.js';
	const MIN_JS_FILENAME = 'iran_cities.min.js';
	const ASSET_SCHEMA_VERSION = 3;
	const PUBLIC_UPLOAD_DIRNAME = 'mobo-core-public';

	/**
	 * WordPress filesystem instance used for generated public assets.
	 *
	 * @var WP_Filesystem_Base|null
	 */
	private $filesystem = null;

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'persian_woo_iran_cities', array( $this, 'filter_persian_woo_iran_cities_url' ), PHP_INT_MAX );
		add_action( 'wp_enqueue_scripts', array( $this, 'replace_persian_woocommerce_city_script' ), PHP_INT_MAX );
		add_filter( 'script_loader_tag', array( $this, 'force_synchronous_city_script' ), PHP_INT_MAX, 3 );
		add_action( 'wp_loaded', array( $this, 'maybe_generate_from_cache' ), 30 );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
	}

	/**
	 * Generate assets when cached Mobo data changed or files are missing.
	 *
	 * @return void
	 */
	public function maybe_generate_from_cache() {
		if ( ! class_exists( 'Mobo_Core_Settings' ) || ! Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' ) ) {
			return;
		}

		$mapping = get_option( 'mobo_core_address_mapping_data', array() );
		$manual  = get_option( 'mobo_core_address_manual_mapping', array() );
		if ( ! is_array( $mapping ) || empty( $mapping['cities'] ) || empty( $mapping['states'] ) ) {
			return;
		}

		$status = $this->get_status();
		$source_hash = $this->build_source_hash( $mapping, is_array( $manual ) ? $manual : array() );
		if ( ! empty( $status['ready'] ) && isset( $status['sourceHash'] ) && hash_equals( (string) $status['sourceHash'], $source_hash ) && $this->files_are_readable() ) {
			return;
		}

		$this->generate( $mapping, is_array( $manual ) ? $manual : array(), 'cache-refresh' );
	}

	/**
	 * Build both readable and minified city scripts from Mobo data.
	 *
	 * @param array  $mapping Cached Mobo location payload.
	 * @param array  $manual Manual country/state mapping.
	 * @param string $source Generation source.
	 * @return array|WP_Error
	 */
	public function generate( $mapping, $manual = array(), $source = 'manual' ) {
		$mapping = is_array( $mapping ) ? $mapping : array();
		$manual  = is_array( $manual ) ? $manual : array();

		$groups = $this->build_city_groups( $mapping, $manual );
		if ( is_wp_error( $groups ) ) {
			$this->store_error( $groups->get_error_message(), $source );
			return $groups;
		}

		$assets = $this->get_asset_locations();
		if ( is_wp_error( $assets ) ) {
			$this->store_error( $assets->get_error_message(), $source );
			return $assets;
		}

		if ( ! wp_mkdir_p( $assets['dir'] ) ) {
			$error = new WP_Error( 'mobo_core_city_asset_dir_failed', 'دایرکتوری عمومی فایل شهرهای موبو قابل ایجاد نیست: ' . $assets['dir'] );
			$this->store_error( $error->get_error_message(), $source );
			return $error;
		}

		if ( ! $this->ensure_public_asset_directory( $assets['dir'] ) ) {
			$error = new WP_Error( 'mobo_core_city_asset_security_failed', 'آماده‌سازی دایرکتوری عمومی فایل شهرهای موبو ناموفق بود: ' . $assets['dir'] );
			$this->store_error( $error->get_error_message(), $source );
			return $error;
		}

		$readable = $this->render_readable_js( $groups['groups'] );
		$minified = $this->render_minified_js( $groups['groups'] );
		if ( ! $this->javascript_contains_public_contract( $readable ) || ! $this->javascript_contains_public_contract( $minified ) ) {
			$error = new WP_Error( 'mobo_core_city_asset_render_failed', 'خروجی JavaScript شهرها فاقد تابع سراسری Persian_Woo_iranCities است.' );
			$this->store_error( $error->get_error_message(), $source );
			return $error;
		}

		if ( ! $this->atomic_write( $assets['jsPath'], $readable ) || ! $this->atomic_write( $assets['minPath'], $minified ) ) {
			$error = new WP_Error( 'mobo_core_city_asset_write_failed', 'نوشتن فایل‌های iran_cities.js و iran_cities.min.js در uploads ناموفق بود.' );
			$this->store_error( $error->get_error_message(), $source );
			return $error;
		}

		$status = array(
			'ready'       => true,
			'generatedAt' => time(),
			'source'      => sanitize_key( (string) $source ),
			'sourceHash'  => $this->build_source_hash( $mapping, $manual ),
			'assetHash'   => hash( 'sha256', $minified ),
			'schemaVersion' => self::ASSET_SCHEMA_VERSION,
			'countryId'   => absint( $groups['countryId'] ),
			'states'      => count( $groups['groups'] ),
			'cities'      => absint( $groups['cityCount'] ),
			'unmappedStates' => $groups['unmappedStates'],
			'jsPath'      => $assets['jsPath'],
			'minPath'     => $assets['minPath'],
			'jsUrl'       => $assets['jsUrl'],
			'minUrl'      => $assets['minUrl'],
			'lastError'   => '',
		);
		update_option( self::STATUS_OPTION, $status, false );

		return $status;
	}

	/**
	 * Return generated asset state.
	 *
	 * @return array
	 */
	public function get_status() {
		$status = get_option( self::STATUS_OPTION, array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}

		$assets = $this->get_asset_locations();
		if ( ! is_wp_error( $assets ) ) {
			$status['jsPath']  = $assets['jsPath'];
			$status['minPath'] = $assets['minPath'];
			$status['jsUrl']   = $assets['jsUrl'];
			$status['minUrl']  = $assets['minUrl'];
		}
		$status['ready'] = ! empty( $status['ready'] ) && $this->files_are_readable();
		return $status;
	}

	/**
	 * Whether a valid generated script is available.
	 *
	 * @return bool
	 */
	public function is_ready() {
		$status = $this->get_status();
		return ! empty( $status['ready'] ) && ! empty( $status['cities'] );
	}

	/**
	 * Replace Persian WooCommerce's city source URL through its own filter.
	 *
	 * @param string $url Original URL.
	 * @return string
	 */
	public function filter_persian_woo_iran_cities_url( $url ) {
		if ( ! $this->is_replacement_enabled() ) {
			return $url;
		}
		$asset_url = $this->get_runtime_asset_url();
		return '' !== $asset_url ? $asset_url : $url;
	}

	/**
	 * Ensure our script wins even when Persian WooCommerce re-registers the handle.
	 *
	 * @return void
	 */
	public function replace_persian_woocommerce_city_script() {
		if ( ! $this->is_replacement_enabled() || ! $this->is_address_page() ) {
			return;
		}

		$asset_url = $this->get_runtime_asset_url();
		if ( '' === $asset_url ) {
			return;
		}

		$status  = $this->get_status();
		$version = isset( $status['assetHash'] ) && '' !== (string) $status['assetHash'] ? substr( (string) $status['assetHash'], 0, 12 ) : ( defined( 'MOBO_CORE_VERSION' ) ? MOBO_CORE_VERSION : null );
		$scripts = wp_scripts();

		/*
		 * Do not deregister the Persian WooCommerce handle. Other parts of that
		 * plugin may already have attached inline code, dependencies, translation
		 * data, or footer-group metadata to it. Mutating only src/version keeps the
		 * original execution order and prevents the consumer from running before
		 * Persian_Woo_iranCities() exists.
		 */
		if ( isset( $scripts->registered['pw-iran-cities'] ) ) {
			$scripts->registered['pw-iran-cities']->src = $asset_url;
			$scripts->registered['pw-iran-cities']->ver = $version;
			if ( isset( $scripts->registered['pw-iran-cities']->extra ) && is_array( $scripts->registered['pw-iran-cities']->extra ) ) {
				unset( $scripts->registered['pw-iran-cities']->extra['strategy'], $scripts->registered['pw-iran-cities']->extra['async'], $scripts->registered['pw-iran-cities']->extra['defer'] );
			}
		} else {
			wp_register_script( 'pw-iran-cities', $asset_url, array(), $version, true );
		}

		wp_enqueue_script( 'pw-iran-cities' );
	}

	/**
	 * Keep the city provider synchronous. Checkout scripts call the global
	 * function immediately and some optimization plugins add defer/async to
	 * standalone assets, which creates a race and the Persian WooCommerce
	 * "function not found" message.
	 *
	 * @param string $tag Script tag HTML.
	 * @param string $handle WordPress script handle.
	 * @param string $src Script URL.
	 * @return string
	 */
	public function force_synchronous_city_script( $tag, $handle, $src = '' ) {
		if ( 'pw-iran-cities' !== $handle || ! $this->is_replacement_enabled() ) {
			return $tag;
		}

		$tag = preg_replace( '/\s+(?:async|defer)(?:=(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/i', '', (string) $tag );
		$tag = preg_replace( '/\s+type=(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $tag );
		if ( is_string( $tag ) && false === stripos( $tag, 'data-mobo-city-provider=' ) ) {
			$tag = preg_replace( '/<script\b/i', '<script type="text/javascript" data-cfasync="false" data-mobo-city-provider="1"', $tag, 1 );
		}
		return is_string( $tag ) ? $tag : '';
	}

	/**
	 * Show a defensive warning when automatic submission is enabled but city
	 * assets could not be generated. The original Persian WooCommerce asset is
	 * left untouched in that failure mode so checkout itself is not broken.
	 *
	 * @return void
	 */
	public function render_admin_notice() {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! class_exists( 'Mobo_Core_Settings' ) || ! Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' ) ) {
			return;
		}

		$status = $this->get_status();
		if ( ! empty( $status['ready'] ) ) {
			return;
		}

		$message = isset( $status['lastError'] ) && '' !== trim( (string) $status['lastError'] )
			? (string) $status['lastError']
			: 'فایل شهرهای موبو هنوز ساخته نشده است. از تب اعتبارسنجی خرید، کشور/استان/شهر را از MoboCore بروزرسانی کنید.';

		echo '<div class="notice notice-error"><p><strong>Mobo Core:</strong> ' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Build switch groups indexed by WooCommerce province code.
	 *
	 * @param array $mapping Mobo location mapping.
	 * @param array $manual Manual mapping.
	 * @return array|WP_Error
	 */
	private function build_city_groups( $mapping, $manual ) {
		$countries = isset( $mapping['countries'] ) && is_array( $mapping['countries'] ) ? $mapping['countries'] : array();
		$states    = isset( $mapping['states'] ) && is_array( $mapping['states'] ) ? $mapping['states'] : array();
		$cities    = isset( $mapping['cities'] ) && is_array( $mapping['cities'] ) ? $mapping['cities'] : array();

		$iran_id = $this->find_iran_country_id( $countries );
		if ( $iran_id <= 0 ) {
			return new WP_Error( 'mobo_core_iran_country_missing', 'کشور ایران در داده آدرس دریافتی از موبو پیدا نشد.' );
		}

		$mobo_states = array();
		foreach ( $states as $state ) {
			$id = isset( $state['id'] ) ? absint( $state['id'] ) : 0;
			$country_id = isset( $state['countryId'] ) ? absint( $state['countryId'] ) : 0;
			$name = isset( $state['name'] ) ? sanitize_text_field( (string) $state['name'] ) : '';
			if ( $id > 0 && $country_id === $iran_id && '' !== $name ) {
				$mobo_states[ $id ] = $name;
			}
		}
		if ( empty( $mobo_states ) ) {
			return new WP_Error( 'mobo_core_iran_states_missing', 'استان‌های ایران در داده آدرس دریافتی از موبو پیدا نشد.' );
		}

		$cities_by_state = array();
		foreach ( $cities as $city ) {
			$id = isset( $city['id'] ) ? absint( $city['id'] ) : 0;
			$state_id = isset( $city['stateId'] ) ? absint( $city['stateId'] ) : 0;
			$name = isset( $city['name'] ) ? sanitize_text_field( (string) $city['name'] ) : '';
			if ( $id <= 0 || $state_id <= 0 || '' === $name || ! isset( $mobo_states[ $state_id ] ) ) {
				continue;
			}
			if ( ! isset( $cities_by_state[ $state_id ] ) ) {
				$cities_by_state[ $state_id ] = array();
			}
			$cities_by_state[ $state_id ][ $id ] = array( 'id' => $id, 'name' => $name );
		}

		$local_states = $this->get_local_iran_states();
		$manual_states = isset( $manual['states'] ) && is_array( $manual['states'] ) ? $manual['states'] : array();
		$aliases = $this->get_state_alias_groups();
		$groups = array();
		$unmapped = array();
		$city_count = 0;

		foreach ( $local_states as $local_code => $local_name ) {
			$case_aliases = $this->resolve_aliases_for_state( $aliases, $local_code, $local_name );
			if ( ! in_array( $local_code, $case_aliases, true ) ) {
				array_unshift( $case_aliases, $local_code );
			}

			$state_id = 0;
			foreach ( array_values( array_unique( array_map( 'strtoupper', $case_aliases ) ) ) as $manual_code ) {
				$manual_key = 'IR|' . $manual_code;
				if ( isset( $manual_states[ $manual_key ] ) ) {
					$state_id = absint( $manual_states[ $manual_key ] );
					if ( $state_id > 0 ) {
						break;
					}
				}
			}
			if ( $state_id <= 0 || ! isset( $mobo_states[ $state_id ] ) ) {
				$state_id = $this->find_mobo_state_id_by_name( $mobo_states, $local_name );
			}
			if ( $state_id <= 0 || empty( $cities_by_state[ $state_id ] ) ) {
				$unmapped[] = array( 'code' => $local_code, 'name' => $local_name );
				continue;
			}

			$rows = array_values( $cities_by_state[ $state_id ] );
			usort( $rows, array( $this, 'sort_city_rows' ) );
			$case_aliases = array_values( array_unique( array_filter( array_map( 'strtoupper', $case_aliases ) ) ) );

			$groups[] = array(
				'code'      => $local_code,
				'name'      => $local_name,
				'aliases'   => $case_aliases,
				'moboStateId' => $state_id,
				'cities'    => $rows,
			);
			$city_count += count( $rows );
		}

		if ( empty( $groups ) || $city_count <= 0 ) {
			return new WP_Error( 'mobo_core_city_asset_empty', 'هیچ استان محلی به استان موبو متصل نشد؛ ابتدا نگاشت استان‌ها را ذخیره کنید.' );
		}

		return array(
			'countryId'      => $iran_id,
			'groups'         => $groups,
			'cityCount'      => $city_count,
			'unmappedStates' => $unmapped,
		);
	}

	/**
	 * Read active WooCommerce Iran states, with bundled aliases as fallback.
	 *
	 * @return array
	 */
	private function get_local_iran_states() {
		$states = array();
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_object( WC()->countries ) && method_exists( WC()->countries, 'get_states' ) ) {
			$rows = WC()->countries->get_states( 'IR' );
			if ( is_array( $rows ) ) {
				foreach ( $rows as $code => $name ) {
					$code = strtoupper( sanitize_text_field( (string) $code ) );
					$name = sanitize_text_field( (string) $name );
					if ( '' !== $code && '' !== $name ) {
						$states[ $code ] = $name;
					}
				}
			}
		}

		if ( ! empty( $states ) ) {
			return $states;
		}

		foreach ( $this->get_state_alias_groups() as $group ) {
			$aliases = isset( $group['aliases'] ) && is_array( $group['aliases'] ) ? $group['aliases'] : array();
			$name = isset( $group['name'] ) ? sanitize_text_field( (string) $group['name'] ) : '';
			$code = ! empty( $aliases ) ? strtoupper( sanitize_text_field( (string) reset( $aliases ) ) ) : '';
			if ( '' !== $code && '' !== $name ) {
				$states[ $code ] = $name;
			}
		}
		return $states;
	}

	/**
	 * The old bundled file is used only for province aliases, not city IDs.
	 * City rows always come from Mobo.
	 *
	 * @return array
	 */
	private function get_state_alias_groups() {
		$file = defined( 'MOBO_CORE_PLUGIN_DIR' ) ? MOBO_CORE_PLUGIN_DIR . 'includes/data/iran-cities.php' : '';
		if ( '' === $file || ! is_readable( $file ) ) {
			return array();
		}
		$rows = include $file;
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$groups = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$groups[] = array(
				'name'    => isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '',
				'aliases' => isset( $row['aliases'] ) && is_array( $row['aliases'] ) ? $row['aliases'] : array(),
			);
		}
		return $groups;
	}

	private function resolve_aliases_for_state( $groups, $code, $name ) {
		$code = strtoupper( sanitize_text_field( (string) $code ) );
		$target = $this->normalize_label( $name );
		foreach ( $groups as $group ) {
			$aliases = isset( $group['aliases'] ) && is_array( $group['aliases'] ) ? array_map( 'strtoupper', $group['aliases'] ) : array();
			$group_name = isset( $group['name'] ) ? $this->normalize_label( $group['name'] ) : '';
			if ( in_array( $code, $aliases, true ) || ( '' !== $target && $group_name === $target ) ) {
				return $aliases;
			}
		}
		return array( $code );
	}

	private function find_iran_country_id( $countries ) {
		foreach ( $countries as $country ) {
			$id = isset( $country['id'] ) ? absint( $country['id'] ) : 0;
			$iso = isset( $country['isoCode'] ) ? strtoupper( sanitize_text_field( (string) $country['isoCode'] ) ) : '';
			$name = isset( $country['name'] ) ? $this->normalize_label( $country['name'] ) : '';
			if ( $id > 0 && ( 'IR' === $iso || 'ایران' === $name || 'iran' === strtolower( $name ) ) ) {
				return $id;
			}
		}
		return 0;
	}

	private function find_mobo_state_id_by_name( $mobo_states, $local_name ) {
		$target = $this->normalize_label( $local_name );
		foreach ( $mobo_states as $id => $name ) {
			if ( $this->normalize_label( $name ) === $target ) {
				return absint( $id );
			}
		}
		return 0;
	}

	private function normalize_label( $value ) {
		$value = trim( (string) $value );
		$value = str_replace( array( 'ي', 'ك', 'ة', 'ۀ', 'ـ' ), array( 'ی', 'ک', 'ه', 'ه', '' ), $value );
		$value = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $value );
		$value = preg_replace( '/[\x{200C}\x{200D}\x{00A0}]/u', ' ', $value );
		$value = preg_replace( '/^(استان|شهر|شهرستان)\s+/u', '', $value );
		$value = preg_replace( '/\s+(استان|شهر|شهرستان)$/u', '', $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		return null === $value ? '' : trim( $value );
	}

	private function sort_city_rows( $a, $b ) {
		$left = isset( $a['name'] ) ? $this->normalize_label( $a['name'] ) : '';
		$right = isset( $b['name'] ) ? $this->normalize_label( $b['name'] ) : '';
		return strcmp( $left, $right );
	}

	private function render_readable_js( $groups ) {
		$payload = $this->build_javascript_payload( $groups );
		$data_json = wp_json_encode( $payload['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$alias_json = wp_json_encode( $payload['aliases'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( false === $data_json || false === $alias_json ) {
			return '';
		}

		$lines = array();
		$lines[] = '/**';
		$lines[] = ' * Generated by Mobo Core from the current Mobo address cache.';
		$lines[] = ' * City option values are authoritative Mobo city IDs.';
		$lines[] = ' * Do not edit manually; sync address data to regenerate.';
		$lines[] = ' */';
		$lines[] = 'var Mobo_Core_Iran_Cities = ' . $data_json . ';';
		$lines[] = 'var Mobo_Core_Iran_City_Aliases = ' . $alias_json . ';';
		$lines[] = '';
		$lines[] = 'function Persian_Woo_iranCities(province) {';
		$lines[] = "	var requested = String(province || '').toUpperCase();";
		$lines[] = "	var canonical = Mobo_Core_Iran_City_Aliases[requested] || requested;";
		$lines[] = "	var source = Mobo_Core_Iran_Cities[canonical] || [];";
		$lines[] = "	var cities = [];";
		$lines[] = "	var index;";
		$lines[] = "	for (index = 0; index < source.length; index += 1) {";
		$lines[] = "		cities[index + 1] = [source[index][0], String(source[index][1])];";
		$lines[] = "	}";
		$lines[] = "	if (!source.length) {";
		$lines[] = "		cities[1] = ['لطفا استان خود را انتخاب کنید', '0'];";
		$lines[] = "	}";
		$lines[] = "	return cities;";
		$lines[] = '}';
		$lines[] = '';
		$lines[] = 'window.Persian_Woo_iranCities = Persian_Woo_iranCities;';
		$lines[] = 'window.Mobo_Core_Iran_Cities_Ready = true;';
		$lines[] = '';
		return implode( "\n", $lines );
	}

	private function render_minified_js( $groups ) {
		$payload = $this->build_javascript_payload( $groups );
		$data_json = wp_json_encode( $payload['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$alias_json = wp_json_encode( $payload['aliases'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( false === $data_json || false === $alias_json ) {
			return '';
		}

		return 'var Mobo_Core_Iran_Cities=' . $data_json . ',Mobo_Core_Iran_City_Aliases=' . $alias_json . ';function Persian_Woo_iranCities(p){var r=String(p||"").toUpperCase(),k=Mobo_Core_Iran_City_Aliases[r]||r,s=Mobo_Core_Iran_Cities[k]||[],c=[],i;for(i=0;i<s.length;i+=1)c[i+1]=[s[i][0],String(s[i][1])];return s.length||(c[1]=["لطفا استان خود را انتخاب کنید","0"]),c}window.Persian_Woo_iranCities=Persian_Woo_iranCities;window.Mobo_Core_Iran_Cities_Ready=true;';
	}

	private function build_javascript_payload( $groups ) {
		$data = array();
		$aliases = array();
		foreach ( $groups as $group ) {
			$canonical = isset( $group['code'] ) ? strtoupper( sanitize_key( (string) $group['code'] ) ) : '';
			if ( '' === $canonical ) {
				continue;
			}
			$data[ $canonical ] = array();
			foreach ( $group['cities'] as $city ) {
				$name = isset( $city['name'] ) ? sanitize_text_field( (string) $city['name'] ) : '';
				$id = isset( $city['id'] ) ? absint( $city['id'] ) : 0;
				if ( '' !== $name && $id > 0 ) {
					$data[ $canonical ][] = array( $name, (string) $id );
				}
			}
			$aliases[ $canonical ] = $canonical;
			foreach ( $group['aliases'] as $alias ) {
				$alias = strtoupper( sanitize_key( (string) $alias ) );
				if ( '' !== $alias ) {
					$aliases[ $alias ] = $canonical;
				}
			}
		}
		return array( 'data' => $data, 'aliases' => $aliases );
	}

	private function get_runtime_asset_url() {
		$status = $this->get_status();
		if ( empty( $status['ready'] ) ) {
			return '';
		}
		$debug = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		$url = $debug ? ( isset( $status['jsUrl'] ) ? (string) $status['jsUrl'] : '' ) : ( isset( $status['minUrl'] ) ? (string) $status['minUrl'] : '' );
		return esc_url_raw( $url );
	}

	private function is_replacement_enabled() {
		return class_exists( 'Mobo_Core_Settings' ) && Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' );
	}

	private function is_address_page() {
		$is_checkout = function_exists( 'is_checkout' ) && is_checkout();
		$is_edit_address = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'edit-address' );
		return $is_checkout || $is_edit_address;
	}

	private function get_asset_locations() {
		$upload = wp_upload_dir( null, false );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'mobo_core_upload_dir_error', (string) $upload['error'] );
		}
		if ( empty( $upload['basedir'] ) || empty( $upload['baseurl'] ) ) {
			return new WP_Error( 'mobo_core_upload_dir_missing', 'مسیر uploads وردپرس برای فایل شهرهای موبو قابل تشخیص نیست.' );
		}
		/*
		 * Generated checkout JavaScript is intentionally stored outside
		 * MOBO_CORE_DATA_DIR. That directory contains private webhook fallback
		 * data and is protected by a recursive Deny/Require all denied rule.
		 * Keeping public browser assets under that private tree causes Apache
		 * to return 403 and Persian WooCommerce to report that the city
		 * function cannot be found.
		 */
		$dir = trailingslashit( (string) $upload['basedir'] ) . self::PUBLIC_UPLOAD_DIRNAME . '/assets/';
		$url = trailingslashit( (string) $upload['baseurl'] ) . self::PUBLIC_UPLOAD_DIRNAME . '/assets/';
		return array(
			'dir'     => $dir,
			'jsPath'  => $dir . self::JS_FILENAME,
			'minPath' => $dir . self::MIN_JS_FILENAME,
			'jsUrl'   => $url . self::JS_FILENAME,
			'minUrl'  => $url . self::MIN_JS_FILENAME,
		);
	}

	/**
	 * Prepare a public, non-executable uploads directory for generated JS.
	 *
	 * The private uploads/mobo-core directory remains protected. This sibling
	 * directory exposes only static assets and disables directory indexes and
	 * script execution where Apache honours .htaccess files.
	 *
	 * @param string $dir Public asset directory.
	 * @return bool
	 */
	private function ensure_public_asset_directory( $dir ) {
		$dir = trailingslashit( (string) $dir );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$filesystem = $this->get_filesystem();
		if ( ! $filesystem ) {
			return false;
		}

		$index = $dir . 'index.html';
		if ( ! $filesystem->exists( $index ) && ! $filesystem->put_contents( $index, '', FS_CHMOD_FILE ) ) {
			return false;
		}

		$htaccess = $dir . '.htaccess';
		$rules = "# BEGIN Mobo Core public assets\n"
			. "Options -Indexes\n"
			. "<IfModule mod_mime.c>\n"
			. "    AddType application/javascript .js\n"
			. "</IfModule>\n"
			. "<IfModule mod_headers.c>\n"
			. "    Header set X-Content-Type-Options \"nosniff\"\n"
			. "</IfModule>\n"
			. "<FilesMatch \"\\.(?:php|phtml|phar|cgi|pl|py|sh|bash)$\">\n"
			. "    <IfModule mod_authz_core.c>\n"
			. "        Require all denied\n"
			. "    </IfModule>\n"
			. "    <IfModule !mod_authz_core.c>\n"
			. "        Order allow,deny\n"
			. "        Deny from all\n"
			. "    </IfModule>\n"
			. "</FilesMatch>\n"
			. "# END Mobo Core public assets\n";

		$current = $filesystem->exists( $htaccess ) ? $filesystem->get_contents( $htaccess ) : false;
		if ( ! is_string( $current ) || $current !== $rules ) {
			if ( ! $filesystem->put_contents( $htaccess, $rules, FS_CHMOD_FILE ) ) {
				return false;
			}
		}

		return true;
	}

	private function files_are_readable() {
		$assets = $this->get_asset_locations();
		if ( is_wp_error( $assets ) ) {
			return false;
		}

		$filesystem = $this->get_filesystem();
		if ( ! $filesystem ) {
			return false;
		}

		if ( ! $filesystem->exists( $assets['jsPath'] ) || ! $filesystem->exists( $assets['minPath'] ) ) {
			return false;
		}
		if ( $filesystem->size( $assets['jsPath'] ) <= 100 || $filesystem->size( $assets['minPath'] ) <= 100 ) {
			return false;
		}

		$readable_tail = $this->read_file_tail( $assets['jsPath'], 8192 );
		$minified_tail = $this->read_file_tail( $assets['minPath'], 8192 );
		return $this->javascript_contains_public_contract( $readable_tail ) && $this->javascript_contains_public_contract( $minified_tail );
	}

	private function read_file_tail( $path, $bytes ) {
		$filesystem = $this->get_filesystem();
		if ( ! $filesystem || ! $filesystem->exists( $path ) ) {
			return '';
		}

		$contents = $filesystem->get_contents( $path );
		if ( ! is_string( $contents ) ) {
			return '';
		}

		$bytes = max( 1, absint( $bytes ) );
		return strlen( $contents ) > $bytes ? substr( $contents, -$bytes ) : $contents;
	}

	private function javascript_contains_public_contract( $javascript ) {
		if ( ! is_string( $javascript ) || strlen( $javascript ) < 100 ) {
			return false;
		}
		$has_function = false !== strpos( $javascript, 'function Persian_Woo_iranCities(' );
		$has_export = false !== strpos( $javascript, 'window.Persian_Woo_iranCities=Persian_Woo_iranCities' )
			|| false !== strpos( $javascript, 'window.Persian_Woo_iranCities = Persian_Woo_iranCities' );
		$has_schema_marker = false !== strpos( $javascript, 'Mobo_Core_Iran_Cities_Ready' );
		return $has_function && $has_export && $has_schema_marker;
	}

	private function atomic_write( $path, $contents ) {
		$filesystem = $this->get_filesystem();
		if ( ! $filesystem ) {
			return false;
		}

		$dir = dirname( $path );
		$tmp = trailingslashit( $dir ) . '.mobo-city-' . wp_generate_uuid4() . '.tmp';
		if ( ! $filesystem->put_contents( $tmp, $contents, FS_CHMOD_FILE ) ) {
			return false;
		}

		if ( $filesystem->size( $tmp ) !== strlen( $contents ) ) {
			$filesystem->delete( $tmp, false, 'f' );
			return false;
		}

		if ( ! $filesystem->move( $tmp, $path, true ) ) {
			$filesystem->delete( $tmp, false, 'f' );
			return false;
		}

		$filesystem->chmod( $path, FS_CHMOD_FILE );
		return true;
	}

	/**
	 * Return a filesystem implementation without requiring an admin credential form.
	 *
	 * Generated assets are written only inside the writable uploads directory. The
	 * direct adapter is therefore a safe fallback when the global abstraction was
	 * not initialized in the current frontend/cron request.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private function get_filesystem() {
		if ( is_object( $this->filesystem ) ) {
			return $this->filesystem;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;

		if ( function_exists( 'WP_Filesystem' ) && WP_Filesystem() && is_object( $wp_filesystem ) ) {
			$this->filesystem = $wp_filesystem;
			return $this->filesystem;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$this->filesystem = new WP_Filesystem_Direct( false );
		return $this->filesystem;
	}

	private function build_source_hash( $mapping, $manual ) {
		$countries = isset( $mapping['countries'] ) && is_array( $mapping['countries'] ) ? $mapping['countries'] : array();
		$states    = isset( $mapping['states'] ) && is_array( $mapping['states'] ) ? $mapping['states'] : array();
		$cities    = isset( $mapping['cities'] ) && is_array( $mapping['cities'] ) ? $mapping['cities'] : array();
		$first_city = ! empty( $cities ) && is_array( reset( $cities ) ) ? reset( $cities ) : array();
		$last_city  = ! empty( $cities ) && is_array( end( $cities ) ) ? end( $cities ) : array();

		$payload = array(
			'assetSchema'     => self::ASSET_SCHEMA_VERSION,
			'version'          => isset( $mapping['version'] ) ? (string) $mapping['version'] : '',
			'syncedAt'         => isset( $mapping['syncedAt'] ) ? absint( $mapping['syncedAt'] ) : 0,
			'countryCount'     => count( $countries ),
			'stateCount'       => count( $states ),
			'cityCount'        => count( $cities ),
			'firstCityId'      => isset( $first_city['id'] ) ? absint( $first_city['id'] ) : 0,
			'lastCityId'       => isset( $last_city['id'] ) ? absint( $last_city['id'] ) : 0,
			'manualUpdatedAt'  => isset( $manual['updatedAt'] ) ? absint( $manual['updatedAt'] ) : 0,
			'manualCountries'  => isset( $manual['countries'] ) ? $manual['countries'] : array(),
			'manualStates'     => isset( $manual['states'] ) ? $manual['states'] : array(),
		);
		return hash( 'sha256', wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	private function store_error( $message, $source ) {
		$status = $this->get_status();
		$status['ready'] = false;
		$status['lastError'] = sanitize_text_field( (string) $message );
		$status['lastAttemptAt'] = time();
		$status['source'] = sanitize_key( (string) $source );
		update_option( self::STATUS_OPTION, $status, false );
	}
}
