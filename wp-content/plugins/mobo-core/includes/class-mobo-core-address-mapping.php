<?php
/**
 * Mobo address mapping cache and WooCommerce checkout select fields.
 *
 * Portal is the central source. Customer WordPress sites pull the cached
 * country/state/city mapping from Portal on a weekly cadence and then use local
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
		add_filter( 'woocommerce_checkout_fields', array( $this, 'filter_checkout_fields' ), 30 );
		add_action( 'wp_footer', array( $this, 'render_checkout_mapping_script' ), 30 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_location_meta' ), 20, 2 );
	}

	/**
	 * Sync from Portal if the weekly interval is due.
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
	 * Force sync from Portal.
	 *
	 * @param string $source Source name.
	 * @param bool   $force Force flag forwarded to Portal.
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
			$message = 'Portal address mapping payload is empty or incomplete.';
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
			'message' => 'Address mapping synced from Portal.',
		);
	}

	/**
	 * Replace WooCommerce country/state/city fields with Mobo ID selects.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public function filter_checkout_fields( $fields ) {
		if ( ! $this->is_enabled() ) {
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
				array( 'mobo-location-country', 'mobo-location-country-' . $group )
			);

			$fields[ $group ][ $state_key ] = $this->build_select_field(
				isset( $fields[ $group ][ $state_key ] ) ? $fields[ $group ][ $state_key ] : array(),
				'استان',
				array( '' => 'ابتدا کشور را انتخاب کنید' ),
				array( 'mobo-location-state', 'mobo-location-state-' . $group )
			);

			$fields[ $group ][ $city_key ] = $this->build_select_field(
				isset( $fields[ $group ][ $city_key ] ) ? $fields[ $group ][ $city_key ] : array(),
				'شهر',
				array( '' => 'ابتدا استان را انتخاب کنید' ),
				array( 'mobo-location-city', 'mobo-location-city-' . $group )
			);
		}

		return $fields;
	}

	/**
	 * Render dependency JS for checkout country/state/city selects.
	 *
	 * @return void
	 */
	public function render_checkout_mapping_script() {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() || ! $this->is_enabled() ) {
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
			function fill(select, rows, placeholder, emptyText) {
				if (!select) return;
				var current = select.value || '';
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
					return;
				}
				rows.forEach(function(row) {
					var option = document.createElement('option');
					option.value = String(row.id);
					option.textContent = row.name;
					select.appendChild(option);
				});
				if (current && Array.prototype.some.call(select.options, function(opt){ return opt.value === current; })) {
					select.value = current;
				}
			}
			function updateGroup(group) {
				var country = document.getElementById(group + '_country');
				var state = document.getElementById(group + '_state');
				var city = document.getElementById(group + '_city');
				if (!country || !state || !city) return;
				var states = data.statesByCountry[String(country.value || '')] || [];
				fill(state, states, data.i18n.selectState, data.i18n.noState);
				var cities = data.citiesByState[String(state.value || '')] || [];
				fill(city, cities, data.i18n.selectCity, data.i18n.noCity);
				if (window.jQuery) { window.jQuery(state).trigger('change.select2'); window.jQuery(city).trigger('change.select2'); }
			}
			function updateCity(group) {
				var state = document.getElementById(group + '_state');
				var city = document.getElementById(group + '_city');
				if (!state || !city) return;
				fill(city, data.citiesByState[String(state.value || '')] || [], data.i18n.selectCity, data.i18n.noCity);
				if (window.jQuery) { window.jQuery(city).trigger('change.select2'); }
			}
			['billing', 'shipping'].forEach(function(group) {
				var country = document.getElementById(group + '_country');
				var state = document.getElementById(group + '_state');
				if (country) country.addEventListener('change', function(){ updateGroup(group); });
				if (state) state.addEventListener('change', function(){ updateCity(group); });
				updateGroup(group);
			});
		})();
		</script>
		<?php
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

		$mapping = $this->get_mapping();
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			foreach ( array( 'country', 'state', 'city' ) as $type ) {
				$key   = $group . '_' . $type;
				$value = isset( $data[ $key ] ) ? absint( $data[ $key ] ) : 0;
				if ( $value <= 0 && isset( $_POST[ $key ] ) ) {
					$value = absint( wp_unslash( $_POST[ $key ] ) );
				}
				if ( $value <= 0 ) {
					continue;
				}
				$order->update_meta_data( '_mobo_' . $key . '_id', $value );
				$order->update_meta_data( '_mobo_' . $key . '_name', $this->find_location_name( $mapping, $type, $value ) );
				$location_row = $this->find_location_row( $mapping, $type, $value );
				if ( isset( $location_row['latitude'] ) ) {
					$order->update_meta_data( '_mobo_' . $key . '_latitude', $location_row['latitude'] );
				}
				if ( isset( $location_row['longitude'] ) ) {
					$order->update_meta_data( '_mobo_' . $key . '_longitude', $location_row['longitude'] );
				}
			}
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
			'enabled'       => $this->is_enabled(),
			'lastAttemptAt' => absint( get_option( 'mobo_core_address_mapping_last_attempt_at', 0 ) ),
			'lastSuccessAt' => absint( get_option( 'mobo_core_address_mapping_last_success_at', 0 ) ),
			'lastError'     => (string) get_option( 'mobo_core_address_mapping_last_error', '' ),
			'counts'        => $this->get_counts_from_mapping( $mapping ),
		);
	}

	private function is_enabled() {
		return Mobo_Core_Settings::enabled( 'mobo_core_address_mapping_enabled', '1' );
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

	private function build_select_field( $existing, $label, $options, $classes ) {
		$field = is_array( $existing ) ? $existing : array();
		$field['type']     = 'select';
		$field['label']    = $label;
		$field['required'] = true;
		$field['options']  = $options;
		$field['class']    = isset( $field['class'] ) && is_array( $field['class'] ) ? $field['class'] : array( 'form-row-wide' );
		$field['input_class'] = isset( $field['input_class'] ) && is_array( $field['input_class'] ) ? $field['input_class'] : array();

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
