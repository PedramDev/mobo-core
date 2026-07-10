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

		return array(
			'success' => true,
			'status'  => 'ok',
			'counts'  => $this->get_counts_from_mapping( $normalized ),
			'message' => 'Address mapping synced from MoboCore.',
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
	 * Get status for admin UI.
	 *
	 * @return array
	 */
	public function get_status() {
		$mapping = $this->get_mapping();
		return array(
			'enabled'                => $this->is_enabled(),
			'checkoutActive'         => $this->is_checkout_mapping_active(),
			'checkoutMode'           => $this->is_enabled() ? 'manual-map' : 'disabled',
			'orderSubmissionEnabled' => Mobo_Core_Settings::enabled( 'mobo_core_mobo_order_submission_enabled', '0' ),
			'lastAttemptAt'          => absint( get_option( 'mobo_core_address_mapping_last_attempt_at', 0 ) ),
			'lastSuccessAt'          => absint( get_option( 'mobo_core_address_mapping_last_success_at', 0 ) ),
			'lastError'              => (string) get_option( 'mobo_core_address_mapping_last_error', '' ),
			'counts'                 => $this->get_counts_from_mapping( $mapping ),
			'manualMapping'          => $this->get_manual_mapping_status(),
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
			'cities'    => array(),
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

			foreach ( $raw_ids as $row_key => $mobo_id ) {
				$mobo_id = absint( $mobo_id );
				$country = isset( $raw_countries[ $row_key ] ) ? strtoupper( sanitize_text_field( (string) $raw_countries[ $row_key ] ) ) : '';
				$state   = isset( $raw_states[ $row_key ] ) ? sanitize_text_field( (string) $raw_states[ $row_key ] ) : '';
				$name    = isset( $raw_names[ $row_key ] ) ? sanitize_text_field( (string) $raw_names[ $row_key ] ) : '';
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

		/* Preserve manually entered city mappings when the city list source is not available. */
		if ( empty( $out['cities'] ) && ! empty( $current['cities'] ) ) {
			$out['cities'] = $current['cities'];
		}

		update_option( 'mobo_core_address_manual_mapping', $out, false );

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

		$cities = $this->get_local_city_candidates();

		return array(
			'countries' => $countries,
			'states'    => $states,
			'cities'    => $cities,
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

		return array(
			'countriesTotal'  => count( $local['countries'] ),
			'countriesMapped' => count( $manual['countries'] ),
			'statesTotal'     => count( $local['states'] ),
			'statesMapped'    => count( $manual['states'] ),
			'citiesTotal'     => count( $local['cities'] ),
			'citiesMapped'    => count( $manual['cities'] ),
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

		$country_id = 0;
		$state_id   = 0;
		$city_id    = 0;

		if ( is_numeric( $country ) && $this->find_location_row( $mapping, 'country', absint( $country ) ) ) {
			$country_id = absint( $country );
		} else {
			$country_code = strtoupper( sanitize_text_field( (string) $country ) );
			if ( '' === $country_code ) {
				$country_code = 'IR';
			}
			$country_id = isset( $manual['countries'][ $country_code ] ) ? absint( $manual['countries'][ $country_code ] ) : 0;
		}

		if ( is_numeric( $state ) && $this->find_location_row( $mapping, 'state', absint( $state ) ) ) {
			$state_id = absint( $state );
		} else {
			$country_code = strtoupper( sanitize_text_field( (string) $country ) );
			if ( '' === $country_code || is_numeric( $country ) ) {
				$country_code = $this->find_local_country_code_by_mobo_id( $country_id );
			}
			if ( '' === $country_code ) {
				$country_code = 'IR';
			}
			$state_key = $this->build_manual_state_key( $country_code, (string) $state );
			$state_id = isset( $manual['states'][ $state_key ] ) ? absint( $manual['states'][ $state_key ] ) : 0;
		}

		if ( is_numeric( $city ) && $this->find_location_row( $mapping, 'city', absint( $city ) ) ) {
			$city_id = absint( $city );
		} else {
			$country_code = strtoupper( sanitize_text_field( (string) $country ) );
			if ( '' === $country_code || is_numeric( $country ) ) {
				$country_code = $this->find_local_country_code_by_mobo_id( $country_id );
			}
			if ( '' === $country_code ) {
				$country_code = 'IR';
			}
			$city_key = $this->build_manual_city_key( $country_code, (string) $state, (string) $city );
			if ( isset( $manual['cities'][ $city_key ] ) ) {
				$city_entry = $manual['cities'][ $city_key ];
				$city_id = is_array( $city_entry ) && isset( $city_entry['id'] ) ? absint( $city_entry['id'] ) : absint( $city_entry );
			}
		}

		if ( $country_id <= 0 || $state_id <= 0 || $city_id <= 0 ) {
			return new WP_Error(
				'mobo_core_manual_location_mapping_missing',
				'نگاشت دستی کشور/استان/شهر به شناسه‌های موبو کامل نیست. ابتدا از تب اعتبارسنجی خرید، نگاشت آدرس را تکمیل و ذخیره کنید.'
			);
		}

		$city_row = $this->find_location_row( $mapping, 'city', $city_id );

		return array(
			'countryId' => $country_id,
			'stateId'   => $state_id,
			'cityId'    => $city_id,
			'countryName' => $this->find_location_name( $mapping, 'country', $country_id ),
			'stateName'   => $this->find_location_name( $mapping, 'state', $state_id ),
			'cityName'    => $this->find_location_name( $mapping, 'city', $city_id ),
			'latitude'    => isset( $city_row['latitude'] ) ? $city_row['latitude'] : null,
			'longitude'   => isset( $city_row['longitude'] ) ? $city_row['longitude'] : null,
		);
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
		$cities = array();
		$states = $this->get_woocommerce_states( 'IR' );
		$state_lookup = array();
		foreach ( $states as $code => $name ) {
			$state_lookup[ $this->normalize_persian_label( (string) $code ) ] = (string) $code;
			$state_lookup[ $this->normalize_persian_label( (string) $name ) ] = (string) $code;
		}

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
			$key = $this->build_manual_city_key( $country, $state, $name );
			$unique[ $key ] = array( 'country' => $country, 'state' => $state, 'name' => $name );
		}

		uasort( $unique, array( $this, 'sort_city_candidates' ) );
		return array_values( $unique );
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
						$out[] = array( 'country' => 'IR', 'state' => $state_final, 'name' => sanitize_text_field( (string) $name ) );
					}
				} else {
					$this->extract_city_candidates_from_value( $item, '' !== $state_code ? $state_code : $key_string, $state_lookup, $out );
				}
			} elseif ( is_scalar( $item ) ) {
				$name = sanitize_text_field( (string) $item );
				if ( '' !== $state_code && '' !== $name ) {
					$out[] = array( 'country' => 'IR', 'state' => $state_code, 'name' => $name );
				}
			}
		}
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
		$list_key = $type . 's';
		if ( 'country' === $type ) {
			$list_key = 'countries';
		}
		foreach ( isset( $mapping[ $list_key ] ) && is_array( $mapping[ $list_key ] ) ? $mapping[ $list_key ] : array() as $row ) {
			if ( isset( $row['id'] ) && absint( $row['id'] ) === absint( $id ) ) {
				return is_array( $row ) ? $row : array();
			}
		}
		return array();
	}
}
