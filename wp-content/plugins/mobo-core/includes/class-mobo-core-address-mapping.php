<?php
/**
 * Mobo address mapping cache and WooCommerce checkout select fields.
 *
 * MoboCore is the central source. Customer WordPress sites pull the cached
 * country/state/city mapping from MoboCore on a weekly cadence and then use local
 * cached IDs during checkout/order submission.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mobo_Core_Address_Mapping {

	/**
	 * Request-local cache for WooCommerce/Persian WooCommerce city candidates.
	 *
	 * @var array|null
	 */
	private $local_city_candidates_cache = null;

	/**
	 * Request-local cache for the bundled Iranian city dataset.
	 *
	 * @var array|null
	 */
	private $bundled_iran_city_groups_cache = null;

	/**
	 * Bootstrap hooks.
	 *
	 * @return void
	 */
	public function init() {
		/*
		 * Automatic Mobo order submission needs Mobo location IDs, but checkout
		 * country/state/city fields should remain owned by WooCommerce or Persian
		 * WooCommerce. The plugin stores a manual, admin-approved mapping from
		 * local checkout values to Mobo IDs and uses it when the order is created.
		 */
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->ensure_mapping_for_auto_order();

		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_location_meta' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'save_store_api_order_location_meta' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'save_store_api_processed_order_location_meta' ), 5, 1 );
	}

	/**
	 * Automatic order submission requires local Mobo country/state/city IDs.
	 * Try to populate the cache automatically when checkout ownership is enabled.
	 *
	 * @return void
	 */
	private function ensure_mapping_for_auto_order() {
		$mapping = $this->get_mapping();
		$counts  = $this->get_counts_from_mapping( $mapping );
		if ( ! empty( $counts['countries'] ) && ! empty( $counts['states'] ) && ! empty( $counts['cities'] ) ) {
			$this->maybe_sync_if_due( 'auto-order-due', false );
			return;
		}

		$last_attempt = absint( get_option( 'mobo_core_address_mapping_last_attempt_at', 0 ) );
		if ( $last_attempt > 0 && ( time() - $last_attempt ) < HOUR_IN_SECONDS ) {
			return;
		}

		$this->sync_now( 'auto-order-required', true );
	}

	/**
	 * Sync from MoboCore if the weekly interval is due.
	 *
	 * @param string $source Source name.
	 * @param bool   $force Force sync.
	 * @return array
	 */
	public function maybe_sync_if_due( $source = 'cron', $force = false ) {
		if ( ! $this->is_enabled() ) {
			return array( 'success' => true, 'status' => 'disabled', 'message' => 'Address mapping is disabled.' );
		}

		$interval_days = Mobo_Core_Settings::get_int( 'mobo_core_address_mapping_sync_interval_days', 7, 1, 30 );
		$last_sync     = absint( get_option( 'mobo_core_address_mapping_last_success_at', 0 ) );

		if ( ! $force && $last_sync > 0 && ( time() - $last_sync ) < ( $interval_days * DAY_IN_SECONDS ) ) {
			return array(
				'success'    => true,
				'status'     => 'fresh',
				'lastSyncAt' => $last_sync,
				'message'    => 'Address mapping cache is fresh.',
			);
		}

		return $this->sync_now( $source, $force );
	}

	/**
	 * Force sync from MoboCore.
	 *
	 * @param string $source Source name.
	 * @param bool   $force Force flag forwarded to MoboCore.
	 * @return array
	 */
	public function sync_now( $source = 'manual', $force = true ) {
		update_option( 'mobo_core_address_mapping_last_attempt_at', time(), false );

		$api    = new Mobo_Core_API_Client();
		$result = $api->get_address_mapping( $force );

		if ( is_wp_error( $result ) ) {
			update_option( 'mobo_core_address_mapping_last_error', $result->get_error_message(), false );
			return array(
				'success' => false,
				'status'  => 'failed',
				'message' => $result->get_error_message(),
			);
		}

		$normalized = $this->normalize_mapping_payload( $result );

		if ( empty( $normalized['countries'] ) || empty( $normalized['states'] ) || empty( $normalized['cities'] ) ) {
			$message = 'MoboCore address mapping payload is empty or incomplete.';
			update_option( 'mobo_core_address_mapping_last_error', $message, false );
			return array(
				'success' => false,
				'status'  => 'invalid',
				'message' => $message,
				'counts'  => $this->get_counts_from_mapping( $normalized ),
			);
		}

		$normalized['syncedAt'] = time();
		$normalized['source']   = sanitize_key( (string) $source );

		update_option( 'mobo_core_address_mapping_data', $normalized, false );
		update_option( 'mobo_core_address_mapping_last_success_at', time(), false );
		delete_option( 'mobo_core_address_mapping_last_error' );

		$city_assets = null;
		if ( class_exists( 'Mobo_Core_City_Assets' ) ) {
			$generator = new Mobo_Core_City_Assets();
			$city_assets = $generator->generate( $normalized, $this->get_manual_mapping(), $source );
		}

		return array(
			'success'    => ! is_wp_error( $city_assets ),
			'status'     => is_wp_error( $city_assets ) ? 'mapping-ok-city-assets-failed' : 'ok',
			'counts'     => $this->get_counts_from_mapping( $normalized ),
			'cityAssets' => is_wp_error( $city_assets ) ? array( 'success' => false, 'message' => $city_assets->get_error_message() ) : $city_assets,
			'message'    => is_wp_error( $city_assets ) ? 'Address mapping synced, but Mobo city assets could not be generated: ' . $city_assets->get_error_message() : 'Address mapping and Mobo city assets synced from MoboCore.',
		);
	}

	/**
	 * Replace WooCommerce country/state/city fields with Mobo ID selects.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public function filter_checkout_fields( $fields ) {
		if ( ! $this->is_checkout_mapping_active() ) {
			return $fields;
		}

		$mapping = $this->get_mapping();
		if ( empty( $mapping['countries'] ) ) {
			return $fields;
		}

		foreach ( array( 'billing', 'shipping' ) as $group ) {
			if ( ! isset( $fields[ $group ] ) || ! is_array( $fields[ $group ] ) ) {
				continue;
			}

			$country_key = $group . '_country';
			$state_key   = $group . '_state';
			$city_key    = $group . '_city';

			$fields[ $group ][ $country_key ] = $this->build_select_field(
				isset( $fields[ $group ][ $country_key ] ) ? $fields[ $group ][ $country_key ] : array(),
				'کشور',
				$this->options_from_rows( $mapping['countries'] ),
				array( 'mobo-location-country', 'mobo-location-country-' . $group ),
				40
			);

			$fields[ $group ][ $state_key ] = $this->build_select_field(
				isset( $fields[ $group ][ $state_key ] ) ? $fields[ $group ][ $state_key ] : array(),
				'استان',
				array( '' => 'ابتدا کشور را انتخاب کنید' ),
				array( 'mobo-location-state', 'mobo-location-state-' . $group ),
				50
			);

			$fields[ $group ][ $city_key ] = $this->build_select_field(
				isset( $fields[ $group ][ $city_key ] ) ? $fields[ $group ][ $city_key ] : array(),
				'شهر',
				array( '' => 'ابتدا استان را انتخاب کنید' ),
				array( 'mobo-location-city', 'mobo-location-city-' . $group ),
				60
			);

			if ( isset( $fields[ $group ][ $group . '_address_1' ] ) ) {
				$fields[ $group ][ $group . '_address_1' ]['priority'] = 70;
			}
			if ( isset( $fields[ $group ][ $group . '_address_2' ] ) ) {
				$fields[ $group ][ $group . '_address_2' ]['priority'] = 80;
			}
			if ( isset( $fields[ $group ][ $group . '_postcode' ] ) ) {
				$fields[ $group ][ $group . '_postcode' ]['priority'] = 90;
			}
		}

		return $fields;
	}

	/**
	 * Render dependency JS for checkout country/state/city selects.
	 *
	 * @return void
	 */
	public function render_checkout_mapping_script() {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() || ! $this->is_checkout_mapping_active() ) {
			return;
		}

		$mapping = $this->get_mapping();
		if ( empty( $mapping['countries'] ) ) {
			return;
		}

		$states_by_country = array();
		foreach ( isset( $mapping['states'] ) && is_array( $mapping['states'] ) ? $mapping['states'] : array() as $state ) {
			$parent = isset( $state['countryId'] ) ? (string) absint( $state['countryId'] ) : '';
			$id     = isset( $state['id'] ) ? (string) absint( $state['id'] ) : '';
			$name   = isset( $state['name'] ) ? sanitize_text_field( (string) $state['name'] ) : '';
			if ( '' === $parent || '' === $id || '' === $name ) {
				continue;
			}
			if ( ! isset( $states_by_country[ $parent ] ) ) {
				$states_by_country[ $parent ] = array();
			}
			$states_by_country[ $parent ][] = array( 'id' => $id, 'name' => $name );
		}

		$cities_by_state = array();
		foreach ( isset( $mapping['cities'] ) && is_array( $mapping['cities'] ) ? $mapping['cities'] : array() as $city ) {
			$parent = isset( $city['stateId'] ) ? (string) absint( $city['stateId'] ) : '';
			$id     = isset( $city['id'] ) ? (string) absint( $city['id'] ) : '';
			$name   = isset( $city['name'] ) ? sanitize_text_field( (string) $city['name'] ) : '';
			if ( '' === $parent || '' === $id || '' === $name ) {
				continue;
			}
			if ( ! isset( $cities_by_state[ $parent ] ) ) {
				$cities_by_state[ $parent ] = array();
			}
			$cities_by_state[ $parent ][] = array( 'id' => $id, 'name' => $name );
		}

		$data = array(
			'statesByCountry' => $states_by_country,
			'citiesByState'    => $cities_by_state,
			'i18n'             => array(
				'selectCountry' => 'کشور را انتخاب کنید',
				'selectState'   => 'استان را انتخاب کنید',
				'selectCity'    => 'شهر را انتخاب کنید',
				'noState'       => 'استانی برای این کشور پیدا نشد',
				'noCity'        => 'شهری برای این استان پیدا نشد',
			),
		);
		?>
		<script>
		(function() {
			var data = <?php echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
			var groups = ['billing', 'shipping'];

			function toStringValue(value) {
				return value === null || typeof value === 'undefined' ? '' : String(value);
			}

			function getSelect(group, type) {
				return document.getElementById(group + '_' + type);
			}

			function getSelectedText(select) {
				if (!select || select.selectedIndex < 0 || !select.options || !select.options[select.selectedIndex]) {
					return '';
				}
				return (select.options[select.selectedIndex].textContent || '').replace(/\s+/g, ' ').trim();
			}

			function optionExists(select, value) {
				if (!select || !select.options) return false;
				return Array.prototype.some.call(select.options, function(opt) {
					return opt.value === value;
				});
			}

			function findValueByText(rows, text) {
				if (!text || !rows || !rows.length) return '';
				var normalizedText = String(text).replace(/\s+/g, ' ').trim();
				var found = '';
				rows.some(function(row) {
					var rowName = row && row.name ? String(row.name).replace(/\s+/g, ' ').trim() : '';
					if (rowName === normalizedText) {
						found = toStringValue(row.id);
						return true;
					}
					return false;
				});
				return found;
			}

			function refreshSelect2(select) {
				if (!select || !window.jQuery) return;
				window.jQuery(select).trigger('change.select2');
			}

			function fill(select, rows, placeholder, emptyText) {
				if (!select) return '';

				var currentValue = toStringValue(select.value || '');
				var currentText = getSelectedText(select);

				select.innerHTML = '';

				var first = document.createElement('option');
				first.value = '';
				first.textContent = placeholder;
				select.appendChild(first);

				if (!rows || !rows.length) {
					if (emptyText) {
						var empty = document.createElement('option');
						empty.value = '';
						empty.textContent = emptyText;
						select.appendChild(empty);
					}
					select.value = '';
					refreshSelect2(select);
					return '';
				}

				rows.forEach(function(row) {
					var option = document.createElement('option');
					option.value = toStringValue(row.id);
					option.textContent = row.name;
					select.appendChild(option);
				});

				if (currentValue && optionExists(select, currentValue)) {
					select.value = currentValue;
				} else {
					var matchedValue = findValueByText(rows, currentText);
					select.value = matchedValue && optionExists(select, matchedValue) ? matchedValue : '';
				}

				refreshSelect2(select);
				return toStringValue(select.value || '');
			}

			function updateGroup(group) {
				var country = getSelect(group, 'country');
				var state = getSelect(group, 'state');
				var city = getSelect(group, 'city');
				if (!country || !state || !city) return;

				var states = data.statesByCountry[toStringValue(country.value || '')] || [];
				var selectedState = fill(state, states, data.i18n.selectState, data.i18n.noState);
				var cities = data.citiesByState[selectedState] || [];
				fill(city, cities, data.i18n.selectCity, data.i18n.noCity);
			}

			function updateCity(group) {
				var state = getSelect(group, 'state');
				var city = getSelect(group, 'city');
				if (!state || !city) return;

				fill(city, data.citiesByState[toStringValue(state.value || '')] || [], data.i18n.selectCity, data.i18n.noCity);
			}

			function bindNativeEvents() {
				groups.forEach(function(group) {
					var country = getSelect(group, 'country');
					var state = getSelect(group, 'state');

					if (country && !country.getAttribute('data-mobo-location-bound')) {
						country.setAttribute('data-mobo-location-bound', '1');
						country.addEventListener('change', function() { updateGroup(group); });
					}

					if (state && !state.getAttribute('data-mobo-location-bound')) {
						state.setAttribute('data-mobo-location-bound', '1');
						state.addEventListener('change', function() { updateCity(group); });
					}
				});
			}

			function bindJQueryEvents() {
				if (!window.jQuery) return;

				window.jQuery(document.body)
					.off('change.moboLocationCountry', '.mobo-location-country')
					.on('change.moboLocationCountry', '.mobo-location-country', function() {
						var id = this.id || '';
						if (id.indexOf('billing_') === 0) updateGroup('billing');
						if (id.indexOf('shipping_') === 0) updateGroup('shipping');
					})
					.off('change.moboLocationState', '.mobo-location-state')
					.on('change.moboLocationState', '.mobo-location-state', function() {
						var id = this.id || '';
						if (id.indexOf('billing_') === 0) updateCity('billing');
						if (id.indexOf('shipping_') === 0) updateCity('shipping');
					});
			}

			function boot() {
				bindNativeEvents();
				bindJQueryEvents();
				groups.forEach(function(group) {
					updateGroup(group);
				});
			}

			boot();
			setTimeout(boot, 50);
			setTimeout(boot, 250);

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', boot);
			}

			if (window.jQuery) {
				window.jQuery(document.body).on('init_checkout updated_checkout wc_fragments_refreshed', function() {
					setTimeout(boot, 0);
				});
			}
		})();
		</script>
		<?php
	}


	/**
	 * Legacy compatibility hook. Checkout values are intentionally not changed
	 * unless Mobo owns checkout fields through automatic order submission.
	 *
	 * @param mixed  $value Checkout value.
	 * @param string $input Checkout input key.
	 * @return mixed
	 */
	public function filter_checkout_value( $value, $input ) {
		return $value;
	}

	/**
	 * Legacy compatibility hook. Mobo must not alter WooCommerce checkout review
	 * destination when automatic order submission is disabled.
	 *
	 * @param string $post_data Serialized checkout data.
	 * @return void
	 */
	public function normalize_checkout_review_destination( $post_data ) {
		return;
	}

	/**
	 * Legacy compatibility hook. Mobo must not alter shipping packages unless it
	 * owns checkout address fields.
	 *
	 * @param array $packages Cart shipping packages.
	 * @return array
	 */
	public function normalize_cart_shipping_packages( $packages ) {
		return $packages;
	}

	/**
	 * Save selected Mobo address IDs and labels on the WooCommerce order.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data Checkout data.
	 * @return void
	 */
	public function save_order_location_meta( $order, $data ) {
		if ( ! $this->is_enabled() || ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		foreach ( array( 'billing', 'shipping' ) as $group ) {
			$values = $this->get_checkout_group_values( $order, is_array( $data ) ? $data : array(), $group );
			$resolved = $this->resolve_values_to_mobo_location( $values['country'], $values['state'], $values['city'] );

			if ( is_wp_error( $resolved ) ) {
				$order->update_meta_data( '_mobo_' . $group . '_location_mapping_error', $resolved->get_error_message() );
				continue;
			}

			$this->write_resolved_location_meta( $order, $group, $resolved );
			$order->delete_meta_data( '_mobo_' . $group . '_location_mapping_error' );
		}
	}


	/**
	 * Persist Mobo location metadata for Checkout Block / Store API orders.
	 *
	 * @param WC_Order        $order Order object.
	 * @param WP_REST_Request $request Store API request.
	 * @return void
	 */
	public function save_store_api_order_location_meta( $order, $request ) {
		$data = array();
		if ( is_object( $request ) && method_exists( $request, 'get_json_params' ) ) {
			$params = $request->get_json_params();
			if ( is_array( $params ) ) {
				$data = $this->flatten_store_api_address_data( $params );
			}
		}

		$this->save_order_location_meta( $order, $data );
	}

	/**
	 * Final Store API fallback after WooCommerce has persisted the order address.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function save_store_api_processed_order_location_meta( $order ) {
		$this->save_order_location_meta( $order, array() );
		if ( is_object( $order ) && method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * Convert Store API billing_address/shipping_address arrays to classic keys.
	 *
	 * @param array $params Request parameters.
	 * @return array
	 */
	private function flatten_store_api_address_data( $params ) {
		$out = array();
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			$source_key = $group . '_address';
			$address = isset( $params[ $source_key ] ) && is_array( $params[ $source_key ] ) ? $params[ $source_key ] : array();
			foreach ( array( 'country', 'state', 'city' ) as $field ) {
				if ( isset( $address[ $field ] ) && '' !== trim( (string) $address[ $field ] ) ) {
					$out[ $group . '_' . $field ] = sanitize_text_field( (string) $address[ $field ] );
				}
			}
		}
		return $out;
	}

	/**
	 * Get status for admin UI.
	 *
	 * @return array
	 */
	public function get_status() {
		$mapping = $this->get_mapping();
		return array(
			'enabled'                => $this->is_enabled(),
			'checkoutActive'         => $this->is_checkout_mapping_active(),
			'checkoutMode'           => $this->is_enabled() ? 'mobo-city-assets' : 'disabled',
			'orderSubmissionEnabled' => Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' ),
			'lastAttemptAt'          => absint( get_option( 'mobo_core_address_mapping_last_attempt_at', 0 ) ),
			'lastSuccessAt'          => absint( get_option( 'mobo_core_address_mapping_last_success_at', 0 ) ),
			'lastError'              => (string) get_option( 'mobo_core_address_mapping_last_error', '' ),
			'counts'                 => $this->get_counts_from_mapping( $mapping ),
			'manualMapping'          => $this->get_manual_mapping_status(),
			'cityAssets'             => class_exists( 'Mobo_Core_City_Assets' ) ? ( new Mobo_Core_City_Assets() )->get_status() : array(),
		);
	}


	/**
	 * Public access to the cached Mobo location payload.
	 *
	 * @return array
	 */
	public function get_cached_mapping() {
		return $this->get_mapping();
	}

	/**
	 * Get admin-approved manual mapping from local WooCommerce values to Mobo IDs.
	 *
	 * @return array
	 */
	public function get_manual_mapping() {
		$mapping = get_option( 'mobo_core_address_manual_mapping', array() );
		if ( ! is_array( $mapping ) ) {
			$mapping = array();
		}

		foreach ( array( 'countries', 'states', 'cities' ) as $bucket ) {
			if ( ! isset( $mapping[ $bucket ] ) || ! is_array( $mapping[ $bucket ] ) ) {
				$mapping[ $bucket ] = array();
			}
		}

		return $mapping;
	}

	/**
	 * Save manual address mapping from the checkout settings form.
	 *
	 * @param array $post Raw POST array.
	 * @return array
	 */
	public function save_manual_mapping_from_post( $post ) {
		$current = $this->get_manual_mapping();
		$out = array(
			'countries' => array(),
			'states'    => array(),
			/* City rows are submitted one province at a time in the admin UI. */
			'cities'    => isset( $current['cities'] ) && is_array( $current['cities'] ) ? $current['cities'] : array(),
			'updatedAt' => time(),
		);

		if ( isset( $post['mobo_address_map_country'] ) && is_array( $post['mobo_address_map_country'] ) ) {
			foreach ( wp_unslash( $post['mobo_address_map_country'] ) as $local_country => $mobo_id ) {
				$local_country = strtoupper( sanitize_text_field( (string) $local_country ) );
				$mobo_id = absint( $mobo_id );
				if ( '' !== $local_country && $mobo_id > 0 ) {
					$out['countries'][ $local_country ] = $mobo_id;
				}
			}
		}

		if ( isset( $post['mobo_address_map_state'] ) && is_array( $post['mobo_address_map_state'] ) ) {
			foreach ( wp_unslash( $post['mobo_address_map_state'] ) as $raw_key => $mobo_id ) {
				$key = $this->normalize_manual_state_key( (string) $raw_key );
				$mobo_id = absint( $mobo_id );
				if ( '' !== $key && $mobo_id > 0 ) {
					$out['states'][ $key ] = $mobo_id;
				}
			}
		}

		if ( isset( $post['mobo_address_map_city_id'] ) && is_array( $post['mobo_address_map_city_id'] ) ) {
			$raw_ids       = wp_unslash( $post['mobo_address_map_city_id'] );
			$raw_countries = isset( $post['mobo_address_map_city_country'] ) && is_array( $post['mobo_address_map_city_country'] ) ? wp_unslash( $post['mobo_address_map_city_country'] ) : array();
			$raw_states    = isset( $post['mobo_address_map_city_state'] ) && is_array( $post['mobo_address_map_city_state'] ) ? wp_unslash( $post['mobo_address_map_city_state'] ) : array();
			$raw_names     = isset( $post['mobo_address_map_city_name'] ) && is_array( $post['mobo_address_map_city_name'] ) ? wp_unslash( $post['mobo_address_map_city_name'] ) : array();
			$scope_country = isset( $post['mobo_address_map_city_scope_country'] ) ? strtoupper( sanitize_text_field( (string) wp_unslash( $post['mobo_address_map_city_scope_country'] ) ) ) : '';
			$scope_state   = isset( $post['mobo_address_map_city_scope_state'] ) ? sanitize_text_field( (string) wp_unslash( $post['mobo_address_map_city_scope_state'] ) ) : '';

			/* Remove only the currently displayed province before applying its submitted rows. */
			if ( '' !== $scope_country && '' !== $scope_state ) {
				foreach ( $out['cities'] as $stored_key => $entry ) {
					$parts = explode( '|', (string) $stored_key, 3 );
					$entry_country = is_array( $entry ) && isset( $entry['country'] ) ? strtoupper( sanitize_text_field( (string) $entry['country'] ) ) : ( isset( $parts[0] ) ? strtoupper( sanitize_text_field( (string) $parts[0] ) ) : '' );
					$entry_state   = is_array( $entry ) && isset( $entry['state'] ) ? sanitize_text_field( (string) $entry['state'] ) : ( isset( $parts[1] ) ? sanitize_text_field( (string) $parts[1] ) : '' );
					if ( $entry_country === $scope_country && $this->resolve_local_state_code( $scope_country, $entry_state ) === $this->resolve_local_state_code( $scope_country, $scope_state ) ) {
						unset( $out['cities'][ $stored_key ] );
					}
				}
			}

			foreach ( $raw_ids as $row_key => $mobo_id ) {
				$mobo_id = absint( $mobo_id );
				$decoded = $this->decode_manual_city_row_key( $row_key );
				$country = isset( $raw_countries[ $row_key ] ) ? strtoupper( sanitize_text_field( (string) $raw_countries[ $row_key ] ) ) : $decoded['country'];
				$state   = isset( $raw_states[ $row_key ] ) ? sanitize_text_field( (string) $raw_states[ $row_key ] ) : $decoded['state'];
				$name    = isset( $raw_names[ $row_key ] ) ? sanitize_text_field( (string) $raw_names[ $row_key ] ) : $decoded['name'];
				$key     = $this->build_manual_city_key( $country, $state, $name );

				if ( '' !== $key && $mobo_id > 0 ) {
					$out['cities'][ $key ] = array(
						'id'      => $mobo_id,
						'country' => $country,
						'state'   => $state,
						'name'    => $name,
					);
				}
			}
		}

		update_option( 'mobo_core_address_manual_mapping', $out, false );

		if ( class_exists( 'Mobo_Core_City_Assets' ) ) {
			$generator = new Mobo_Core_City_Assets();
			$generator->generate( $this->get_mapping(), $out, 'manual-state-map-save' );
		}

		return $out;
	}

	/**
	 * Get local WooCommerce/Persian-WooCommerce address candidates for admin UI.
	 *
	 * @return array
	 */
	public function get_local_location_candidates() {
		$countries = $this->get_woocommerce_mapping_country_candidates();

		$states = array();
		foreach ( $countries as $country ) {
			$country_code = isset( $country['code'] ) ? strtoupper( sanitize_text_field( (string) $country['code'] ) ) : '';
			if ( '' === $country_code ) {
				continue;
			}

			foreach ( $this->get_woocommerce_states( $country_code ) as $code => $name ) {
				$code = sanitize_text_field( (string) $code );
				if ( '' === $code ) {
					continue;
				}
				$states[] = array(
					'country' => $country_code,
					'code'    => $code,
					'name'    => sanitize_text_field( (string) $name ),
				);
			}
		}

		return array(
			'countries' => $countries,
			'states'    => $states,
			/* Manual city mapping was retired in 10.31.54. */
			'cities'    => array(),
		);
	}

	/**
	 * Get manual mapping status counts for the admin UI.
	 *
	 * @return array
	 */
	public function get_manual_mapping_status() {
		$manual = $this->get_manual_mapping();
		$local  = $this->get_local_location_candidates();

		$city_status = class_exists( 'Mobo_Core_City_Assets' ) ? ( new Mobo_Core_City_Assets() )->get_status() : array();
		$cached = $this->get_mapping();

		return array(
			'countriesTotal'  => count( $local['countries'] ),
			'countriesMapped' => count( $manual['countries'] ),
			'statesTotal'     => count( $local['states'] ),
			'statesMapped'    => count( $manual['states'] ),
			'citiesTotal'     => isset( $cached['cities'] ) && is_array( $cached['cities'] ) ? count( $cached['cities'] ) : 0,
			'citiesMapped'    => isset( $city_status['cities'] ) ? absint( $city_status['cities'] ) : 0,
			'cityAssetsReady' => ! empty( $city_status['ready'] ),
			'updatedAt'       => isset( $manual['updatedAt'] ) ? absint( $manual['updatedAt'] ) : 0,
		);
	}

	/**
	 * Resolve a WC order group to Mobo IDs without relying on checkout field ownership.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $group billing|shipping.
	 * @return array|WP_Error
	 */
	public function resolve_order_group( $order, $group = 'billing' ) {
		$values = $this->get_order_group_values( $order, $group );
		return $this->resolve_values_to_mobo_location( $values['country'], $values['state'], $values['city'] );
	}


	/**
	 * Resolve local country/state values to a Mobo state ID using the approved manual mapping.
	 * This is intentionally state-only; it is used by checkout shipping rules before the city is needed.
	 *
	 * @param mixed $country Local WooCommerce country code or Mobo country ID.
	 * @param mixed $state Local WooCommerce state code/name or Mobo state ID.
	 * @return int
	 */
	public function resolve_state_to_mobo_id( $country, $state ) {
		$mapping = $this->get_mapping();
		$manual  = $this->get_manual_mapping();

		if ( is_numeric( $state ) && $this->find_location_row( $mapping, 'state', absint( $state ) ) ) {
			return absint( $state );
		}

		$country_id = 0;
		$country_code = strtoupper( sanitize_text_field( (string) $country ) );
		if ( is_numeric( $country ) && $this->find_location_row( $mapping, 'country', absint( $country ) ) ) {
			$country_id = absint( $country );
			$country_code = $this->find_local_country_code_by_mobo_id( $country_id );
		}

		if ( '' === $country_code ) {
			$country_code = 'IR';
		}

		$state_key = $this->build_manual_state_key( $country_code, (string) $state );
		return isset( $manual['states'][ $state_key ] ) ? absint( $manual['states'][ $state_key ] ) : 0;
	}

	/**
	 * Check whether a resolved Mobo state ID is Tehran using the cached Mobo mapping data.
	 *
	 * @param int $mobo_state_id Mobo state ID.
	 * @return bool
	 */
	public function is_mobo_state_tehran( $mobo_state_id ) {
		$mobo_state_id = absint( $mobo_state_id );
		if ( $mobo_state_id <= 0 ) {
			return false;
		}

		$row = $this->find_location_row( $this->get_mapping(), 'state', $mobo_state_id );
		$name = isset( $row['name'] ) ? (string) $row['name'] : '';
		$normalized = $this->normalize_address_token( $name );

		return 'تهران' === $normalized || 'tehran' === $normalized || false !== strpos( $normalized, 'تهران' );
	}

	/**
	 * Resolve local country/state/city values to Mobo IDs using the manual map.
	 * Numeric Mobo IDs are accepted only when the corresponding cached row exists.
	 *
	 * @param mixed $country Country value.
	 * @param mixed $state State value.
	 * @param mixed $city City value.
	 * @return array|WP_Error
	 */
	public function resolve_values_to_mobo_location( $country, $state, $city ) {
		$mapping = $this->get_mapping();
		$manual  = $this->get_manual_mapping();

		$country_id   = 0;
		$state_id     = 0;
		$city_id      = 0;
		$country_code = $this->resolve_local_country_code( $country );
		$state_code   = $this->resolve_local_state_code( $country_code, $state );
		$city_name    = sanitize_text_field( (string) $city );

		if ( is_numeric( $country ) && $this->find_location_row( $mapping, 'country', absint( $country ) ) ) {
			$country_id = absint( $country );
			if ( '' === $country_code ) {
				$country_code = $this->find_local_country_code_by_mobo_id( $country_id );
			}
		} elseif ( '' !== $country_code ) {
			$country_id = isset( $manual['countries'][ $country_code ] ) ? absint( $manual['countries'][ $country_code ] ) : 0;
		}

		if ( '' === $country_code ) {
			$country_code = 'IR';
		}

		if ( is_numeric( $state ) && $this->find_location_row( $mapping, 'state', absint( $state ) ) ) {
			$state_id = absint( $state );
		} else {
			$state_key = $this->build_manual_state_key( $country_code, $state_code );
			$state_id  = isset( $manual['states'][ $state_key ] ) ? absint( $manual['states'][ $state_key ] ) : 0;
		}

		if ( is_numeric( $city ) ) {
			$numeric_city = absint( $city );
			$city_row = $this->find_location_row( $mapping, 'city', $numeric_city );
			if ( $city_row && $state_id > 0 && isset( $city_row['stateId'] ) && absint( $city_row['stateId'] ) === $state_id ) {
				$city_id = $numeric_city;
			} elseif ( $state_id > 0 ) {
				/* Legacy Persian WooCommerce numeric city code from old orders. */
				$legacy_city_name = $this->resolve_local_city_name( $country_code, $state_code, $city );
				$city_id = $this->find_mobo_city_id_by_name( $mapping, $state_id, $legacy_city_name );
				if ( $city_id > 0 ) {
					$city_name = $legacy_city_name;
				}
			}
		} elseif ( $state_id > 0 ) {
			$city_id = $this->find_mobo_city_id_by_name( $mapping, $state_id, $city_name );
		}

		$missing = array();
		if ( $country_id <= 0 ) {
			$missing[] = 'کشور «' . ( '' !== trim( (string) $country ) ? sanitize_text_field( (string) $country ) : 'خالی' ) . '»';
		}
		if ( $state_id <= 0 ) {
			$missing[] = 'استان «' . ( '' !== trim( (string) $state ) ? sanitize_text_field( (string) $state ) : 'خالی' ) . '»';
		}
		if ( $city_id <= 0 ) {
			$missing[] = 'شهر «' . ( '' !== trim( (string) $city ) ? sanitize_text_field( (string) $city ) : 'خالی' ) . '»';
		}

		if ( ! empty( $missing ) ) {
			$city_assets_ready = class_exists( 'Mobo_Core_City_Assets' ) && ( new Mobo_Core_City_Assets() )->is_ready();
			return new WP_Error(
				'mobo_core_location_resolution_failed',
				'آدرس سفارش به شناسه‌های موبو تبدیل نشد. موارد نامعتبر: ' . implode( '، ', $missing ) . '. ' . ( $city_assets_ready ? 'نگاشت کشور و استان را بررسی کنید؛ شهر باید مستقیما با شناسه موبو از Checkout ارسال شود.' : 'فایل شهرهای موبو آماده نیست؛ داده آدرس را از MoboCore بروزرسانی و فایل شهرها را دوباره تولید کنید.' ),
				array(
					'country'    => sanitize_text_field( (string) $country ),
					'state'      => sanitize_text_field( (string) $state ),
					'city'       => sanitize_text_field( (string) $city ),
					'countryKey' => $country_code,
					'stateKey'   => $state_code,
					'cityKey'    => $city_name,
					'cityAssetsReady' => $city_assets_ready,
				)
			);
		}

		$city_row = $this->find_location_row( $mapping, 'city', $city_id );

		return array(
			'countryId'   => $country_id,
			'stateId'     => $state_id,
			'cityId'      => $city_id,
			'countryName' => $this->find_location_name( $mapping, 'country', $country_id ),
			'stateName'   => $this->find_location_name( $mapping, 'state', $state_id ),
			'cityName'    => $this->find_location_name( $mapping, 'city', $city_id ),
			'latitude'    => isset( $city_row['latitude'] ) ? $city_row['latitude'] : null,
			'longitude'   => isset( $city_row['longitude'] ) ? $city_row['longitude'] : null,
		);
	}


	/**
	 * Match a legacy/text city value directly against Mobo cities in the
	 * resolved state. New checkout submissions normally send the numeric Mobo ID.
	 *
	 * @param array  $mapping Cached Mobo mapping.
	 * @param int    $state_id Resolved Mobo state ID.
	 * @param string $city_name City label.
	 * @return int
	 */
	private function find_mobo_city_id_by_name( $mapping, $state_id, $city_name ) {
		$state_id = absint( $state_id );
		$target = $this->normalize_address_token( $city_name );
		if ( $state_id <= 0 || '' === $target ) {
			return 0;
		}

		$exact = array();
		foreach ( isset( $mapping['cities'] ) && is_array( $mapping['cities'] ) ? $mapping['cities'] : array() as $row ) {
			$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$row_state = isset( $row['stateId'] ) ? absint( $row['stateId'] ) : 0;
			$name = isset( $row['name'] ) ? (string) $row['name'] : '';
			if ( $id <= 0 || $row_state !== $state_id ) {
				continue;
			}
			if ( $this->normalize_address_token( $name ) === $target ) {
				$exact[] = $id;
			}
		}

		return 1 === count( $exact ) ? absint( reset( $exact ) ) : 0;
	}

	/**
	 * Resolve a WooCommerce country value to its canonical local code.
	 *
	 * @param mixed $country Country value.
	 * @return string
	 */
	private function resolve_local_country_code( $country ) {
		if ( is_numeric( $country ) ) {
			return $this->find_local_country_code_by_mobo_id( absint( $country ) );
		}

		$value = trim( (string) $country );
		if ( '' === $value ) {
			return 'IR';
		}

		$upper = strtoupper( $value );
		$countries = $this->get_woocommerce_countries();
		if ( isset( $countries[ $upper ] ) ) {
			return $upper;
		}

		$matched = $this->find_woocommerce_country_code_by_name( $value );
		return '' !== $matched ? strtoupper( $matched ) : $upper;
	}

	/**
	 * Resolve WooCommerce state codes and labels to the canonical local code.
	 *
	 * @param string $country_code Country code.
	 * @param mixed  $state State code or label.
	 * @return string
	 */
	private function resolve_local_state_code( $country_code, $state ) {
		$value = trim( (string) $state );
		if ( '' === $value ) {
			return '';
		}

		$states = $this->get_woocommerce_states( $country_code );
		if ( isset( $states[ $value ] ) ) {
			return (string) $value;
		}

		$upper = strtoupper( $value );
		if ( isset( $states[ $upper ] ) ) {
			return $upper;
		}

		$target = $this->normalize_address_token( $value );
		foreach ( $states as $code => $name ) {
			if ( $this->normalize_address_token( $name ) === $target ) {
				return (string) $code;
			}
		}

		if ( 'IR' === strtoupper( (string) $country_code ) ) {
			$alias_code = $this->resolve_bundled_iran_state_alias( $upper, $states );
			if ( '' !== $alias_code ) {
				return $alias_code;
			}
		}

		return $value;
	}

	/**
	 * Resolve a local city ID/code/label to the city label used by manual mapping.
	 *
	 * @param string $country_code Country code.
	 * @param string $state_code State code.
	 * @param mixed  $city City value.
	 * @return string
	 */
	private function resolve_local_city_name( $country_code, $state_code, $city ) {
		$value = sanitize_text_field( (string) $city );
		if ( '' === trim( $value ) ) {
			return '';
		}

		/* Persian WooCommerce stores the visible Persian city label as the field value. */
		if ( ! is_numeric( $value ) && preg_match( '/[\x{0600}-\x{06FF}]/u', $value ) ) {
			return $value;
		}

		$target = $this->normalize_address_token( $value );
		foreach ( $this->get_local_city_candidates() as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$candidate_country = isset( $candidate['country'] ) ? strtoupper( (string) $candidate['country'] ) : 'IR';
			$candidate_state   = isset( $candidate['state'] ) ? (string) $candidate['state'] : '';
			$candidate_name    = isset( $candidate['name'] ) ? (string) $candidate['name'] : '';
			$candidate_value   = isset( $candidate['value'] ) ? (string) $candidate['value'] : '';
			if ( $candidate_country !== strtoupper( $country_code ) || $this->resolve_local_state_code( $country_code, $candidate_state ) !== $state_code ) {
				continue;
			}
			if ( $this->normalize_address_token( $candidate_name ) === $target || ( '' !== $candidate_value && $this->normalize_address_token( $candidate_value ) === $target ) ) {
				return $candidate_name;
			}
		}

		return $value;
	}

	/**
	 * Persist a resolved location on an existing order during retry/preflight.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $group billing|shipping.
	 * @param array    $resolved Resolved location.
	 * @return void
	 */
	public function write_order_group_location_meta( $order, $group, $resolved ) {
		$group = 'shipping' === $group ? 'shipping' : 'billing';
		if ( ! is_object( $order ) || ! is_array( $resolved ) ) {
			return;
		}
		$this->write_resolved_location_meta( $order, $group, $resolved );
		$order->delete_meta_data( '_mobo_' . $group . '_location_mapping_error' );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	private function write_resolved_location_meta( $order, $group, $resolved ) {
		$prefix = '_mobo_' . $group . '_';
		$order->update_meta_data( $prefix . 'country_id', absint( $resolved['countryId'] ) );
		$order->update_meta_data( $prefix . 'country_name', sanitize_text_field( (string) $resolved['countryName'] ) );
		$order->update_meta_data( $prefix . 'state_id', absint( $resolved['stateId'] ) );
		$order->update_meta_data( $prefix . 'state_name', sanitize_text_field( (string) $resolved['stateName'] ) );
		$order->update_meta_data( $prefix . 'city_id', absint( $resolved['cityId'] ) );
		$order->update_meta_data( $prefix . 'city_name', sanitize_text_field( (string) $resolved['cityName'] ) );

		if ( null !== $resolved['latitude'] ) {
			$order->update_meta_data( $prefix . 'city_latitude', $resolved['latitude'] );
		}
		if ( null !== $resolved['longitude'] ) {
			$order->update_meta_data( $prefix . 'city_longitude', $resolved['longitude'] );
		}
	}

	private function get_checkout_group_values( $order, $data, $group ) {
		$values = array( 'country' => '', 'state' => '', 'city' => '' );
		foreach ( array( 'country', 'state', 'city' ) as $type ) {
			$key = $group . '_' . $type;
			if ( isset( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] ) ) {
				$values[ $type ] = sanitize_text_field( (string) $data[ $key ] );
			} elseif ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout request lifecycle verifies the request.
				$values[ $type ] = sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}

		if ( '' === $values['country'] || '' === $values['state'] || '' === $values['city'] ) {
			$fallback = $this->get_order_group_values( $order, $group );
			foreach ( $values as $type => $value ) {
				if ( '' === $value && isset( $fallback[ $type ] ) ) {
					$values[ $type ] = $fallback[ $type ];
				}
			}
		}

		return $values;
	}

	private function get_order_group_values( $order, $group ) {
		$country_method = 'shipping' === $group ? 'get_shipping_country' : 'get_billing_country';
		$state_method   = 'shipping' === $group ? 'get_shipping_state' : 'get_billing_state';
		$city_method    = 'shipping' === $group ? 'get_shipping_city' : 'get_billing_city';

		return array(
			'country' => is_object( $order ) && method_exists( $order, $country_method ) ? sanitize_text_field( (string) $order->$country_method() ) : '',
			'state'   => is_object( $order ) && method_exists( $order, $state_method ) ? sanitize_text_field( (string) $order->$state_method() ) : '',
			'city'    => is_object( $order ) && method_exists( $order, $city_method ) ? sanitize_text_field( (string) $order->$city_method() ) : '',
		);
	}

	private function get_woocommerce_mapping_country_candidates() {
		$all_countries = $this->get_woocommerce_countries();
		$scoped_countries = $this->get_woocommerce_shipping_countries();
		if ( empty( $scoped_countries ) ) {
			$scoped_countries = $all_countries;
		}

		$show_all = Mobo_Core_Settings::enabled( 'mobo_core_address_mapping_show_all_countries', '0' );
		$is_all_countries = ! empty( $all_countries ) && count( $scoped_countries ) >= max( 1, count( $all_countries ) - 1 );

		if ( $is_all_countries && ! $show_all ) {
			$base_code = strtoupper( $this->get_woocommerce_default_country() );
			$limited = array();
			if ( isset( $all_countries[ $base_code ] ) ) {
				$limited[ $base_code ] = $all_countries[ $base_code ];
			}
			if ( 'IR' !== $base_code && isset( $all_countries['IR'] ) ) {
				$limited['IR'] = $all_countries['IR'];
			}
			$scoped_countries = ! empty( $limited ) ? $limited : $scoped_countries;
		}

		$countries = array();
		foreach ( $scoped_countries as $code => $name ) {
			$code = strtoupper( sanitize_text_field( (string) $code ) );
			if ( '' === $code ) {
				continue;
			}
			$countries[] = array(
				'code' => $code,
				'name' => sanitize_text_field( (string) $name ),
				'isDefaultScope' => $is_all_countries && ! $show_all ? 1 : 0,
			);
		}

		usort( $countries, array( $this, 'sort_iran_first' ) );
		return $countries;
	}

	private function get_local_city_candidates() {
		if ( is_array( $this->local_city_candidates_cache ) ) {
			return $this->local_city_candidates_cache;
		}

		$cities = array();
		$states = $this->get_woocommerce_states( 'IR' );
		$state_lookup = array();
		foreach ( $states as $code => $name ) {
			$state_lookup[ $this->normalize_persian_label( (string) $code ) ] = (string) $code;
			$state_lookup[ $this->normalize_persian_label( (string) $name ) ] = (string) $code;
		}

		/*
		 * Legacy compatibility source only. Current checkout city options are
		 * generated from authoritative Mobo city IDs by Mobo_Core_City_Assets.
		 * This bundled dataset is retained to resolve old Persian WooCommerce
		 * numeric city codes already stored on historical orders.
		 */
		$this->append_bundled_iran_city_candidates( $states, $cities );

		/* Keep non-database legacy providers only as a compatibility fallback. */
		if ( empty( $cities ) ) {
			$this->append_persian_woocommerce_function_city_candidates( $states, $cities );

			$option_names = array( 'PW_Cities', 'PW_Iran_Cities', 'persian_woocommerce_cities', 'woocommerce_iran_cities', 'pwoo_cities', 'iran_cities' );
			foreach ( $option_names as $option_name ) {
				$value = get_option( $option_name, null );
				if ( is_array( $value ) && ! empty( $value ) ) {
					$this->extract_city_candidates_from_value( $value, '', $state_lookup, $cities );
				}
			}

			foreach ( array( 'iran_cities', 'persian_woocommerce_cities', 'pwoo_cities' ) as $global_name ) {
				if ( isset( $GLOBALS[ $global_name ] ) && is_array( $GLOBALS[ $global_name ] ) ) {
					$this->extract_city_candidates_from_value( $GLOBALS[ $global_name ], '', $state_lookup, $cities );
				}
			}
		}

		$cities = apply_filters( 'mobo_core_local_city_candidates', $cities, $states );
		if ( ! is_array( $cities ) ) {
			$cities = array();
		}

		$unique = array();
		foreach ( $cities as $city ) {
			if ( ! is_array( $city ) ) {
				continue;
			}
			$country = isset( $city['country'] ) ? strtoupper( sanitize_text_field( (string) $city['country'] ) ) : 'IR';
			$state   = isset( $city['state'] ) ? sanitize_text_field( (string) $city['state'] ) : '';
			$name    = isset( $city['name'] ) ? sanitize_text_field( (string) $city['name'] ) : '';
			if ( '' === $state || '' === $name ) {
				continue;
			}
			$value = isset( $city['value'] ) ? sanitize_text_field( (string) $city['value'] ) : '';
			$key = $this->build_manual_city_key( $country, $state, $name );
			$unique[ $key ] = array( 'country' => $country, 'state' => $state, 'name' => $name, 'value' => $value );
		}

		uasort( $unique, array( $this, 'sort_city_candidates' ) );
		$this->local_city_candidates_cache = array_values( $unique );
		return $this->local_city_candidates_cache;
	}

	/**
	 * Load the bundled city dataset once per request.
	 *
	 * @return array
	 */
	private function get_bundled_iran_city_groups() {
		if ( is_array( $this->bundled_iran_city_groups_cache ) ) {
			return $this->bundled_iran_city_groups_cache;
		}

		$file = defined( 'MOBO_CORE_PLUGIN_DIR' ) ? MOBO_CORE_PLUGIN_DIR . 'includes/data/iran-cities.php' : '';
		if ( '' === $file || ! is_readable( $file ) ) {
			$this->bundled_iran_city_groups_cache = array();
			return $this->bundled_iran_city_groups_cache;
		}

		$groups = include $file;
		$this->bundled_iran_city_groups_cache = is_array( $groups ) ? $groups : array();
		return $this->bundled_iran_city_groups_cache;
	}

	/**
	 * Convert old/new Persian WooCommerce province aliases to the active code.
	 *
	 * @param string $value State code.
	 * @param array  $states Active WooCommerce states.
	 * @return string
	 */
	private function resolve_bundled_iran_state_alias( $value, $states ) {
		$value = strtoupper( sanitize_text_field( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}

		foreach ( $this->get_bundled_iran_city_groups() as $group ) {
			$aliases = is_array( $group ) && isset( $group['aliases'] ) && is_array( $group['aliases'] ) ? $group['aliases'] : array();
			$normalized_aliases = array();
			foreach ( $aliases as $alias ) {
				$alias = strtoupper( sanitize_text_field( (string) $alias ) );
				if ( '' !== $alias ) {
					$normalized_aliases[] = $alias;
				}
			}
			if ( ! in_array( $value, $normalized_aliases, true ) ) {
				continue;
			}

			foreach ( $normalized_aliases as $alias ) {
				if ( isset( $states[ $alias ] ) ) {
					return $alias;
				}
			}

			return ! empty( $normalized_aliases ) ? (string) reset( $normalized_aliases ) : '';
		}

		return '';
	}

	/**
	 * Append cities from the plugin-bundled Iranian city dataset.
	 *
	 * @param array $states WooCommerce IR states.
	 * @param array $out Output candidates.
	 * @return void
	 */
	private function append_bundled_iran_city_candidates( $states, &$out ) {
		$groups = $this->get_bundled_iran_city_groups();
		if ( empty( $groups ) ) {
			return;
		}

		$state_codes = array();
		foreach ( is_array( $states ) ? $states : array() as $state_code => $state_name ) {
			$state_codes[ strtoupper( sanitize_text_field( (string) $state_code ) ) ] = true;
		}

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$aliases = isset( $group['aliases'] ) && is_array( $group['aliases'] ) ? $group['aliases'] : array();
			$state_code = '';
			foreach ( $aliases as $alias ) {
				$alias = strtoupper( sanitize_text_field( (string) $alias ) );
				if ( '' !== $alias && isset( $state_codes[ $alias ] ) ) {
					$state_code = $alias;
					break;
				}
			}

			if ( '' === $state_code && ! empty( $aliases ) ) {
				$state_code = strtoupper( sanitize_text_field( (string) reset( $aliases ) ) );
			}
			if ( '' === $state_code ) {
				continue;
			}

			$rows = isset( $group['cities'] ) && is_array( $group['cities'] ) ? $group['cities'] : array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$name  = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
				$value = isset( $row['value'] ) ? sanitize_text_field( (string) $row['value'] ) : '';
				if ( '' === trim( $name ) ) {
					continue;
				}

				$out[] = array(
					'country' => 'IR',
					'state'   => $state_code,
					'name'    => $name,
					'value'   => $value,
				);
			}
		}
	}

	/**
	 * Append cities exposed by Persian WooCommerce's public city provider.
	 *
	 * @param array $states WooCommerce IR states.
	 * @param array $out Output candidates.
	 * @return void
	 */
	private function append_persian_woocommerce_function_city_candidates( $states, &$out ) {
		if ( ! function_exists( 'get_cities_by_hannanstd' ) || ! is_array( $states ) ) {
			return;
		}

		foreach ( $states as $state_code => $state_name ) {
			$state_code = sanitize_text_field( (string) $state_code );
			if ( '' === $state_code ) {
				continue;
			}

			try {
				$rows = get_cities_by_hannanstd( $state_code );
			} catch ( Throwable $exception ) {
				continue;
			}

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $city_value => $city_label ) {
				if ( is_array( $city_label ) ) {
					$city_name = isset( $city_label['name'] ) ? $city_label['name'] : ( isset( $city_label['city'] ) ? $city_label['city'] : ( isset( $city_label['title'] ) ? $city_label['title'] : '' ) );
				} else {
					$city_name = is_scalar( $city_label ) ? $city_label : '';
				}

				$city_name = sanitize_text_field( (string) $city_name );
				if ( '' === trim( $city_name ) ) {
					continue;
				}

				$out[] = array(
					'country' => 'IR',
					'state'   => $state_code,
					'name'    => $city_name,
					'value'   => is_scalar( $city_value ) ? sanitize_text_field( (string) $city_value ) : $city_name,
				);
			}
		}
	}

	private function extract_city_candidates_from_value( $value, $parent_key, $state_lookup, &$out ) {
		if ( ! is_array( $value ) ) {
			return;
		}

		foreach ( $value as $key => $item ) {
			$key_string = is_scalar( $key ) ? (string) $key : '';
			$state_code = '';
			if ( '' !== $parent_key && isset( $state_lookup[ $this->normalize_persian_label( $parent_key ) ] ) ) {
				$state_code = $state_lookup[ $this->normalize_persian_label( $parent_key ) ];
			}
			if ( '' === $state_code && '' !== $key_string && isset( $state_lookup[ $this->normalize_persian_label( $key_string ) ] ) ) {
				$state_code = $state_lookup[ $this->normalize_persian_label( $key_string ) ];
			}

			if ( is_array( $item ) ) {
				if ( isset( $item['name'] ) || isset( $item['city'] ) || isset( $item['title'] ) ) {
					$name = isset( $item['name'] ) ? $item['name'] : ( isset( $item['city'] ) ? $item['city'] : $item['title'] );
					$state_raw = isset( $item['state'] ) ? $item['state'] : ( isset( $item['province'] ) ? $item['province'] : $state_code );
					$state_final = isset( $state_lookup[ $this->normalize_persian_label( (string) $state_raw ) ] ) ? $state_lookup[ $this->normalize_persian_label( (string) $state_raw ) ] : $state_code;
					if ( '' !== $state_final && '' !== trim( (string) $name ) ) {
						$city_value = isset( $item['id'] ) ? $item['id'] : ( isset( $item['code'] ) ? $item['code'] : ( isset( $item['value'] ) ? $item['value'] : $key_string ) );
						$out[] = array( 'country' => 'IR', 'state' => $state_final, 'name' => sanitize_text_field( (string) $name ), 'value' => sanitize_text_field( (string) $city_value ) );
					}
				} else {
					$this->extract_city_candidates_from_value( $item, '' !== $state_code ? $state_code : $key_string, $state_lookup, $out );
				}
			} elseif ( is_scalar( $item ) ) {
				$name = sanitize_text_field( (string) $item );
				if ( '' !== $state_code && '' !== $name ) {
					$out[] = array( 'country' => 'IR', 'state' => $state_code, 'name' => $name, 'value' => sanitize_text_field( $key_string ) );
				}
			}
		}
	}

	/**
	 * Decode the compact URL-safe city row key used by the admin form.
	 *
	 * @param mixed $row_key Encoded country|state|city tuple.
	 * @return array
	 */
	private function decode_manual_city_row_key( $row_key ) {
		$out = array( 'country' => '', 'state' => '', 'name' => '' );
		$encoded = sanitize_text_field( (string) $row_key );
		if ( '' === $encoded ) {
			return $out;
		}

		$base64 = strtr( $encoded, '-_', '+/' );
		$padding = strlen( $base64 ) % 4;
		if ( $padding > 0 ) {
			$base64 .= str_repeat( '=', 4 - $padding );
		}

		$decoded = base64_decode( $base64, true );
		if ( ! is_string( $decoded ) || '' === $decoded ) {
			return $out;
		}

		$parts = explode( '|', $decoded, 3 );
		if ( count( $parts ) !== 3 ) {
			return $out;
		}

		$out['country'] = strtoupper( sanitize_text_field( (string) $parts[0] ) );
		$out['state']   = sanitize_text_field( (string) $parts[1] );
		$out['name']    = sanitize_text_field( (string) $parts[2] );
		return $out;
	}

	private function build_manual_state_key( $country, $state ) {
		$country = strtoupper( sanitize_text_field( (string) $country ) );
		$state   = sanitize_text_field( (string) $state );
		if ( '' === $country || '' === $state ) {
			return '';
		}
		return $country . '|' . $state;
	}

	private function normalize_manual_state_key( $key ) {
		$parts = explode( '|', (string) $key );
		if ( count( $parts ) < 2 ) {
			return '';
		}
		return $this->build_manual_state_key( $parts[0], $parts[1] );
	}

	private function build_manual_city_key( $country, $state, $city ) {
		$country = strtoupper( sanitize_text_field( (string) $country ) );
		$state   = sanitize_text_field( (string) $state );
		$city    = $this->normalize_persian_label( (string) $city );
		if ( '' === $country || '' === $state || '' === $city ) {
			return '';
		}
		return $country . '|' . $state . '|' . $city;
	}

	/**
	 * Resolve a city from current or legacy manual-map keys.
	 *
	 * Older versions could save city rows with the province label or another
	 * local state representation. Exact lookup remains first, then canonical
	 * country/state/city matching keeps those approved mappings usable.
	 *
	 * @param array  $cities Manual city mapping bucket.
	 * @param string $country Country code.
	 * @param string $state State code or label.
	 * @param string $city City label.
	 * @return int
	 */
	private function find_manual_city_id( $cities, $country, $state, $city ) {
		if ( ! is_array( $cities ) || empty( $cities ) ) {
			return 0;
		}

		$country = strtoupper( sanitize_text_field( (string) $country ) );
		$state   = $this->resolve_local_state_code( $country, $state );
		$city    = $this->normalize_address_token( $city );
		$exact_key = $this->build_manual_city_key( $country, $state, $city );

		if ( '' !== $exact_key && isset( $cities[ $exact_key ] ) ) {
			$entry = $cities[ $exact_key ];
			return is_array( $entry ) && isset( $entry['id'] ) ? absint( $entry['id'] ) : absint( $entry );
		}

		foreach ( $cities as $stored_key => $entry ) {
			$parts = explode( '|', (string) $stored_key, 3 );
			$entry_country = is_array( $entry ) && isset( $entry['country'] ) ? (string) $entry['country'] : ( isset( $parts[0] ) ? $parts[0] : '' );
			$entry_state   = is_array( $entry ) && isset( $entry['state'] ) ? (string) $entry['state'] : ( isset( $parts[1] ) ? $parts[1] : '' );
			$entry_city    = is_array( $entry ) && isset( $entry['name'] ) ? (string) $entry['name'] : ( isset( $parts[2] ) ? $parts[2] : '' );
			$entry_id      = is_array( $entry ) && isset( $entry['id'] ) ? absint( $entry['id'] ) : absint( $entry );

			if ( $entry_id <= 0 || strtoupper( sanitize_text_field( $entry_country ) ) !== $country ) {
				continue;
			}

			$entry_state = $this->resolve_local_state_code( $country, $entry_state );
			if ( $entry_state !== $state || $this->normalize_address_token( $entry_city ) !== $city ) {
				continue;
			}

			return $entry_id;
		}

		return 0;
	}

	private function find_local_country_code_by_mobo_id( $mobo_id ) {
		$mobo_id = absint( $mobo_id );
		if ( $mobo_id <= 0 ) {
			return '';
		}
		foreach ( $this->get_manual_mapping()['countries'] as $code => $id ) {
			if ( absint( $id ) === $mobo_id ) {
				return (string) $code;
			}
		}
		return '';
	}

	private function sort_iran_first( $a, $b ) {
		$ac = isset( $a['code'] ) ? (string) $a['code'] : '';
		$bc = isset( $b['code'] ) ? (string) $b['code'] : '';
		if ( 'IR' === $ac ) {
			return -1;
		}
		if ( 'IR' === $bc ) {
			return 1;
		}
		return strcasecmp( isset( $a['name'] ) ? (string) $a['name'] : '', isset( $b['name'] ) ? (string) $b['name'] : '' );
	}

	private function sort_city_candidates( $a, $b ) {
		$as = isset( $a['state'] ) ? (string) $a['state'] : '';
		$bs = isset( $b['state'] ) ? (string) $b['state'] : '';
		if ( $as === $bs ) {
			return strcasecmp( isset( $a['name'] ) ? (string) $a['name'] : '', isset( $b['name'] ) ? (string) $b['name'] : '' );
		}
		return strcasecmp( $as, $bs );
	}

	private function is_enabled() {
		return Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' );
	}

	private function is_checkout_mapping_active() {
		/* Checkout fields remain native WooCommerce/Persian-WooCommerce fields. */
		return false;
	}

	private function should_normalize_woocommerce_destination() {
		return false;
	}

	private function normalize_woocommerce_country_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( is_numeric( $value ) ) {
			$country_name = $this->find_location_name( $this->get_mapping(), 'country', absint( $value ) );
			$matched      = $this->find_woocommerce_country_code_by_name( $country_name );
			return '' !== $matched ? $matched : $this->get_woocommerce_default_country();
		}

		$upper = strtoupper( $value );
		$countries = $this->get_woocommerce_countries();
		if ( isset( $countries[ $upper ] ) ) {
			return $upper;
		}

		$matched = $this->find_woocommerce_country_code_by_name( $value );
		return '' !== $matched ? $matched : $value;
	}

	private function normalize_woocommerce_state_value( $value, $country ) {
		$value   = trim( (string) $value );
		$country = $this->normalize_woocommerce_country_value( $country );
		if ( '' === $value ) {
			return '';
		}

		if ( is_numeric( $value ) ) {
			$code = $this->find_woocommerce_state_code_by_mobo_state_id( absint( $value ), $country );
			return '' !== $code ? $code : '';
		}

		$states = $this->get_woocommerce_states( $country );
		if ( isset( $states[ $value ] ) ) {
			return $value;
		}

		$upper = strtoupper( $value );
		if ( isset( $states[ $upper ] ) ) {
			return $upper;
		}

		$target = $this->normalize_persian_label( $value );
		foreach ( $states as $code => $name ) {
			if ( $this->normalize_persian_label( (string) $name ) === $target ) {
				return (string) $code;
			}
		}

		return $value;
	}

	private function normalize_woocommerce_city_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( is_numeric( $value ) ) {
			$name = $this->find_location_name( $this->get_mapping(), 'city', absint( $value ) );
			return '' !== $name ? $name : $value;
		}

		return sanitize_text_field( $value );
	}

	private function get_woocommerce_countries() {
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_object( WC()->countries ) && method_exists( WC()->countries, 'get_countries' ) ) {
			$countries = WC()->countries->get_countries();
			return is_array( $countries ) ? $countries : array();
		}
		return array();
	}

	private function get_woocommerce_shipping_countries() {
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_object( WC()->countries ) ) {
			if ( method_exists( WC()->countries, 'get_shipping_countries' ) ) {
				$countries = WC()->countries->get_shipping_countries();
				if ( is_array( $countries ) && ! empty( $countries ) ) {
					return $countries;
				}
			}
			if ( method_exists( WC()->countries, 'get_allowed_countries' ) ) {
				$countries = WC()->countries->get_allowed_countries();
				return is_array( $countries ) ? $countries : array();
			}
		}
		return array();
	}

	private function get_woocommerce_states( $country ) {
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_object( WC()->countries ) && method_exists( WC()->countries, 'get_states' ) ) {
			$states = WC()->countries->get_states( $country );
			return is_array( $states ) ? $states : array();
		}
		return array();
	}

	private function find_woocommerce_country_code_by_name( $country_name ) {
		$country_name = trim( (string) $country_name );
		if ( '' === $country_name ) {
			return '';
		}

		$target = $this->normalize_persian_label( $country_name );
		foreach ( $this->get_woocommerce_countries() as $code => $name ) {
			if ( $this->normalize_persian_label( (string) $name ) === $target ) {
				return (string) $code;
			}
		}

		return '';
	}

	private function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( '1', 'yes', 'true', 'on' ), true );
	}

	private function get_woocommerce_default_country() {
		$country = 'IR';
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_object( WC()->countries ) && method_exists( WC()->countries, 'get_base_country' ) ) {
			$base = (string) WC()->countries->get_base_country();
			if ( '' !== $base ) {
				$country = $base;
			}
		}
		return $country;
	}

	private function find_woocommerce_state_code_by_mobo_state_id( $mobo_state_id, $country ) {
		$mobo_state_id = absint( $mobo_state_id );
		if ( $mobo_state_id <= 0 || ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->countries ) || ! is_object( WC()->countries ) || ! method_exists( WC()->countries, 'get_states' ) ) {
			return '';
		}

		$state_name = $this->find_location_name( $this->get_mapping(), 'state', $mobo_state_id );
		if ( '' === $state_name ) {
			return '';
		}

		$states = WC()->countries->get_states( $country );
		if ( ! is_array( $states ) || empty( $states ) ) {
			return '';
		}

		$target = $this->normalize_persian_label( $state_name );
		foreach ( $states as $code => $name ) {
			if ( $this->normalize_persian_label( (string) $name ) === $target ) {
				return (string) $code;
			}
		}

		return '';
	}


	private function normalize_address_token( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = str_replace( array( 'ي', 'ك', 'ة', 'ۀ', 'ـ' ), array( 'ی', 'ک', 'ه', 'ه', '' ), $value );
		$value = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $value );
		$value = preg_replace( '/[\x{200C}\x{200D}\x{00A0}]/u', ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		return null === $value ? '' : trim( $value );
	}

	private function normalize_persian_label( $value ) {
		$value = trim( (string) $value );
		$value = str_replace( array( 'ي', 'ك', 'ة', 'ۀ', 'ـ' ), array( 'ی', 'ک', 'ه', 'ه', '' ), $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		return null === $value ? '' : $value;
	}

	private function get_mapping() {
		$mapping = get_option( 'mobo_core_address_mapping_data', array() );
		return is_array( $mapping ) ? $mapping : array();
	}

	private function normalize_mapping_payload( $payload ) {
		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			$payload = $payload['data'];
		}

		$out = array(
			'countries' => array(),
			'states'    => array(),
			'cities'    => array(),
			'version'   => isset( $payload['version'] ) ? sanitize_text_field( (string) $payload['version'] ) : '',
		);

		foreach ( array( 'countries', 'states', 'cities' ) as $type ) {
			$rows = isset( $payload[ $type ] ) && is_array( $payload[ $type ] ) ? $payload[ $type ] : array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$id   = $this->first_int( $row, array( 'id', 'moboId', 'mobo_id', 'value' ) );
				$name = $this->first_string( $row, array( 'name', 'title', 'label', 'text' ) );
				if ( $id <= 0 || '' === $name ) {
					continue;
				}

				$normalized = array( 'id' => $id, 'name' => $name );
				if ( 'countries' === $type ) {
					$iso_code = strtoupper( $this->first_string( $row, array( 'isoCode', 'iso_code', 'iso', 'code', 'countryCode', 'country_code', 'alpha2', 'alpha_2' ) ) );
					if ( 2 === strlen( $iso_code ) ) {
						$normalized['isoCode'] = $iso_code;
					}
				}

				$latitude   = $this->first_float( $row, array( 'latitude', 'lat' ) );
				$longitude  = $this->first_float( $row, array( 'longitude', 'lng', 'lon' ) );
				if ( null !== $latitude ) {
					$normalized['latitude'] = $latitude;
				}
				if ( null !== $longitude ) {
					$normalized['longitude'] = $longitude;
				}
				if ( 'states' === $type ) {
					$normalized['countryId'] = $this->first_int( $row, array( 'countryId', 'country_id', 'parentId', 'parent_id' ) );
					if ( $normalized['countryId'] <= 0 ) {
						continue;
					}
				}
				if ( 'cities' === $type ) {
					$normalized['stateId'] = $this->first_int( $row, array( 'stateId', 'state_id', 'parentId', 'parent_id' ) );
					if ( $normalized['stateId'] <= 0 ) {
						continue;
					}
				}
				$out[ $type ][] = $normalized;
			}
		}

		return $out;
	}

	private function first_int( $row, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
				return absint( $row[ $key ] );
			}
		}
		return 0;
	}

	private function first_string( $row, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
				return sanitize_text_field( (string) $row[ $key ] );
			}
		}
		return '';
	}

	private function first_float( $row, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
				return (float) $row[ $key ];
			}
		}
		return null;
	}

	private function get_counts_from_mapping( $mapping ) {
		return array(
			'countries' => isset( $mapping['countries'] ) && is_array( $mapping['countries'] ) ? count( $mapping['countries'] ) : 0,
			'states'    => isset( $mapping['states'] ) && is_array( $mapping['states'] ) ? count( $mapping['states'] ) : 0,
			'cities'    => isset( $mapping['cities'] ) && is_array( $mapping['cities'] ) ? count( $mapping['cities'] ) : 0,
		);
	}

	private function build_select_field( $existing, $label, $options, $classes, $priority = null ) {
		$field = is_array( $existing ) ? $existing : array();
		$field['type']     = 'select';
		$field['label']    = $label;
		$field['required'] = true;
		$field['options']  = $options;
		$field['class']    = isset( $field['class'] ) && is_array( $field['class'] ) ? $field['class'] : array( 'form-row-wide' );
		$field['input_class'] = isset( $field['input_class'] ) && is_array( $field['input_class'] ) ? $field['input_class'] : array();
		if ( null !== $priority ) {
			$field['priority'] = absint( $priority );
		}

		foreach ( $classes as $class ) {
			$field['input_class'][] = sanitize_html_class( $class );
		}

		return $field;
	}

	private function options_from_rows( $rows ) {
		$options = array( '' => 'انتخاب کنید' );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id   = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
			if ( $id > 0 && '' !== $name ) {
				$options[ (string) $id ] = $name;
			}
		}
		return $options;
	}

	private function find_location_name( $mapping, $type, $id ) {
		$row = $this->find_location_row( $mapping, $type, $id );
		return isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
	}

	private function find_location_row( $mapping, $type, $id ) {
		$list_key = 'country' === $type ? 'countries' : ( 'city' === $type ? 'cities' : 'states' );
		foreach ( isset( $mapping[ $list_key ] ) && is_array( $mapping[ $list_key ] ) ? $mapping[ $list_key ] : array() as $row ) {
			if ( isset( $row['id'] ) && absint( $row['id'] ) === absint( $id ) ) {
				return is_array( $row ) ? $row : array();
			}
		}
		return array();
	}
}
