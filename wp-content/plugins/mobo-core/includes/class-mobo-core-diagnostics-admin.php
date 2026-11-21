<?php
/**
 * «عیب‌یابی» section of the Support tab.
 *
 * Shows the scrubbed plugin error log (filter by level / correlation id), a
 * «کپی گزارش کامل عیب‌یابی» button with the same bundle the Backend receives
 * from POST /support/diagnostics, and a clear-log action (manage_options +
 * nonce).
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Diagnostics_Admin {

	const CLEAR_ACTION = 'mobo_core_diagnostics_clear';

	/** @return void */
	public static function register_hooks() {
		add_action( 'admin_post_' . self::CLEAR_ACTION, array( __CLASS__, 'handle_clear' ) );
	}

	/**
	 * Clear the error log and recent events.
	 *
	 * @return void
	 */
	public static function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::CLEAR_ACTION );
		Mobo_Core_Error_Log::clear();
		wp_safe_redirect(
			Mobo_Core_Support_Access::support_tab_url(
				array(
					'mobo_message' => rawurlencode( 'گزارش خطاهای افزونه پاک شد.' ),
					'mobo_type'    => 'success',
				)
			) . '#mobo-diagnostics'
		);
		exit;
	}

	/**
	 * Level labels.
	 *
	 * @return array<string,string>
	 */
	private static function level_labels() {
		return array(
			'fatal'   => 'خطای مرگبار',
			'error'   => 'خطا',
			'warning' => 'هشدار',
			'notice'  => 'اطلاع',
		);
	}

	/**
	 * Render the section (inside the Support tab).
	 *
	 * @return void
	 */
	public static function render_section() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Read-only filters of an admin listing; no state changes.
		$level = isset( $_GET['mobo_diag_level'] ) ? sanitize_key( wp_unslash( $_GET['mobo_diag_level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cid   = isset( $_GET['mobo_diag_cid'] ) ? preg_replace( '/[^A-Za-z0-9_.:\-]/', '', (string) wp_unslash( $_GET['mobo_diag_cid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$labels = self::level_labels();
		$level  = isset( $labels[ $level ] ) ? $level : '';
		$errors = Mobo_Core_Error_Log::recent( 0, $level, (string) $cid, 100 );
		$summary = Mobo_Core_Error_Log::summary( class_exists( 'Mobo_Core_Environment_Probe' ) ? Mobo_Core_Environment_Probe::impact_summary() : array() );
		$bundle = '';
		try {
			$bundle = wp_json_encode( Mobo_Core_Diagnostics_Bundle::build( Mobo_Core_Diagnostics_Bundle::SECTIONS, Mobo_Core_Diagnostics_Bundle::DEFAULT_SINCE_MINUTES ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		} catch ( Throwable $e ) {
			$bundle = '';
		}
		$tab_url = Mobo_Core_Support_Access::support_tab_url( array() );
		$query   = (string) wp_parse_url( $tab_url, PHP_URL_QUERY );
		parse_str( $query, $hidden );
		?>
		<div class="mobo-card" id="mobo-diagnostics">
			<div class="mobo-admin-card-heading">
				<div>
					<h2 class="mobo-admin-section-title">عیب‌یابی</h2>
					<p>خطاهای ثبت‌شده خود افزونه (حداکثر ۳۰۰ مورد اخیر، تکراری‌ها با شمارنده). اطلاعات محرمانه مثل توکن، رمز عبور، کوکی و مشخصات مشتری قبل از ذخیره حذف یا پوشانده می‌شوند. همین گزارش در صورت نیاز توسط پشتیبانی موبو از راه دور دریافت می‌شود.</p>
				</div>
				<span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( $summary['errors24h'] > 0 ? 'warning' : 'success' ); ?>"><?php echo esc_html( sprintf( '%d خطا در ۲۴ ساعت', absint( $summary['errors24h'] ) ) ); ?></span>
			</div>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="mobo-fields-grid">
				<?php foreach ( (array) $hidden as $key => $value ) : ?>
					<?php if ( is_scalar( $value ) && ! in_array( (string) $key, array( 'mobo_diag_level', 'mobo_diag_cid', 'mobo_message', 'mobo_type' ), true ) ) : ?>
						<input type="hidden" name="<?php echo esc_attr( (string) $key ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
					<?php endif; ?>
				<?php endforeach; ?>
				<div class="mobo-field">
					<label for="mobo_diag_level">سطح</label>
					<select id="mobo_diag_level" name="mobo_diag_level">
						<option value="">همه</option>
						<?php foreach ( $labels as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $level, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="mobo-field">
					<label for="mobo_diag_cid">شناسه پیگیری (Correlation)</label>
					<input type="text" id="mobo_diag_cid" name="mobo_diag_cid" dir="ltr" value="<?php echo esc_attr( (string) $cid ); ?>" placeholder="order:123 / webhook:… / sync:…">
				</div>
				<div class="mobo-field">
					<label>&nbsp;</label>
					<button type="submit" class="mobo-btn mobo-btn-light">فیلتر</button>
				</div>
			</form>

			<?php if ( empty( $errors ) ) : ?>
				<div class="mobo-message mobo-message-success">خطایی از افزونه ثبت نشده است.</div>
			<?php else : ?>
				<div class="mobo-table-wrap">
					<table class="widefat striped">
						<thead><tr><th>آخرین وقوع</th><th>سطح</th><th>کد</th><th>پیام</th><th>محل</th><th>شناسه پیگیری</th><th>تعداد</th></tr></thead>
						<tbody>
							<?php foreach ( $errors as $error ) : ?>
								<?php $seen = $error['lastSeen'] ? (int) ( class_exists( 'Mobo_Core_Environment' ) ? Mobo_Core_Environment::utc_strtotime( (string) $error['lastSeen'] ) : strtotime( (string) $error['lastSeen'] ) ) : 0; ?>
								<tr>
									<td><?php echo esc_html( $seen > 0 && class_exists( 'Mobo_Core_Iran_Date' ) ? Mobo_Core_Iran_Date::format( 'Y/m/d H:i', $seen ) : (string) $error['lastSeen'] ); ?></td>
									<td><?php echo esc_html( isset( $labels[ $error['level'] ] ) ? $labels[ $error['level'] ] : $error['level'] ); ?></td>
									<td dir="ltr"><code><?php echo esc_html( $error['code'] ); ?></code></td>
									<td dir="auto"><?php echo esc_html( $error['message'] ); ?></td>
									<td dir="ltr"><?php echo esc_html( '' !== $error['file'] ? $error['file'] . ':' . $error['line'] : '—' ); ?></td>
									<td dir="ltr"><?php if ( '' !== $error['correlationId'] ) : ?><a href="<?php echo esc_url( add_query_arg( array( 'mobo_diag_cid' => rawurlencode( $error['correlationId'] ) ), $tab_url ) . '#mobo-diagnostics' ); ?>"><?php echo esc_html( $error['correlationId'] ); ?></a><?php else : ?>—<?php endif; ?></td>
									<td><?php echo esc_html( (string) absint( $error['count'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<div class="mobo-actions">
				<?php if ( is_string( $bundle ) && '' !== $bundle ) : ?>
					<button type="button" class="mobo-btn mobo-btn-primary" data-mobo-copy-target="mobo-diagnostics-bundle">کپی گزارش کامل عیب‌یابی</button>
					<span class="mobo-help" data-mobo-copy-status="mobo-diagnostics-bundle"></span>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_ACTION ); ?>">
					<?php wp_nonce_field( self::CLEAR_ACTION ); ?>
					<button type="submit" class="mobo-btn mobo-btn-light" onclick="return confirm('گزارش خطاهای افزونه پاک شود؟');">پاک کردن گزارش خطا</button>
				</form>
			</div>
			<?php if ( is_string( $bundle ) && '' !== $bundle ) : ?>
				<textarea id="mobo-diagnostics-bundle" readonly hidden aria-hidden="true"><?php echo esc_textarea( $bundle ); ?></textarea>
			<?php endif; ?>
		</div>
		<?php
	}
}
