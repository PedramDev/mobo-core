<?php
/**
 * Support access admin UI: "پشتیبانی" tab, admin notices and admin-post handlers.
 *
 * Every mutating action requires manage_options + its own nonce. The support
 * user can only be created/re-enabled here (tab button or notice button).
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Support_Admin {

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_init', array( 'Mobo_Core_Support_Access', 'maybe_expire' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_action( 'admin_post_mobo_core_support_grant', array( __CLASS__, 'handle_grant' ) );
		add_action( 'admin_post_mobo_core_support_notice_grant', array( __CLASS__, 'handle_notice_grant' ) );
		add_action( 'admin_post_mobo_core_support_decline', array( __CLASS__, 'handle_decline' ) );
		add_action( 'admin_post_mobo_core_support_revoke', array( __CLASS__, 'handle_revoke' ) );
		add_action( 'admin_post_mobo_core_support_check_source', array( __CLASS__, 'handle_check_source' ) );
		add_action( 'admin_post_mobo_core_support_hosting', array( __CLASS__, 'handle_hosting' ) );
		add_action( 'admin_post_mobo_core_support_dismiss_source', array( __CLASS__, 'handle_dismiss_source' ) );
		Mobo_Core_Diagnostics_Admin::register_hooks();
	}

	/* =====================================================================
	 * Handlers.
	 * =================================================================== */

	/**
	 * Capability + nonce gate.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the support tab with a message.
	 *
	 * @param string $message Message.
	 * @param string $type    success|warning|error.
	 * @return void
	 */
	private static function back( $message, $type = 'success' ) {
		wp_safe_redirect(
			Mobo_Core_Support_Access::support_tab_url(
				array(
					'mobo_message' => rawurlencode( sanitize_text_field( (string) $message ) ),
					'mobo_type'    => sanitize_key( $type ),
				)
			)
		);
		exit;
	}

	/**
	 * @return string
	 */
	private static function actor() {
		$user = wp_get_current_user();
		return $user && $user->exists() ? (string) $user->user_login : '';
	}

	/** @return void */
	public static function handle_grant() {
		self::guard( 'mobo_core_support_grant' );
		$days   = isset( $_POST['mobo_support_days'] ) ? absint( wp_unslash( $_POST['mobo_support_days'] ) ) : Mobo_Core_Support_Access::DEFAULT_DAYS;
		$result = Mobo_Core_Support_Access::grant( $days, self::actor() );
		if ( is_wp_error( $result ) ) {
			self::back( $result->get_error_message(), 'error' );
		}
		self::back( sprintf( 'دسترسی پشتیبانی موبو برای %d روز فعال شد. هیچ رمز عبوری نمایش داده یا ارسال نشد.', absint( $result['days'] ) ) );
	}

	/** @return void */
	public static function handle_notice_grant() {
		self::guard( 'mobo_core_support_notice_grant' );
		$result = Mobo_Core_Support_Access::grant( Mobo_Core_Support_Access::DEFAULT_DAYS, self::actor() );
		if ( is_wp_error( $result ) ) {
			self::back( $result->get_error_message(), 'error' );
		}
		self::back( sprintf( 'دسترسی پشتیبانی موبو برای %d روز فعال شد.', absint( $result['days'] ) ) );
	}

	/** @return void */
	public static function handle_decline() {
		self::guard( 'mobo_core_support_decline' );
		Mobo_Core_Support_Access::mark_requests_handled( 'declined' );
		Mobo_Core_Support_Access::log_event( 'request_declined', array( 'requestedBy' => self::actor() ) );
		self::back( 'درخواست دسترسی پشتیبانی رد شد.', 'warning' );
	}

	/** @return void */
	public static function handle_revoke() {
		self::guard( 'mobo_core_support_revoke' );
		Mobo_Core_Support_Access::revoke( 'revoked', self::actor() );
		self::back( 'دسترسی پشتیبانی لغو شد؛ رمز کاربر پشتیبانی تغییر کرد، نقش مدیر حذف شد و نشست‌های آن بسته شد.' );
	}

	/** @return void */
	public static function handle_check_source() {
		self::guard( 'mobo_core_support_check_source' );
		$state  = Mobo_Core_Support_Access::run_source_check( 'manual' );
		$status = isset( $state['status'] ) ? (string) $state['status'] : 'unknown';
		self::back( Mobo_Core_Support_Access::source_status_message( $status ), 'ok' === $status ? 'success' : 'warning' );
	}

	/** @return void */
	public static function handle_hosting() {
		self::guard( 'mobo_core_support_hosting' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the password must be sent byte-exact; it is sealed, never stored or echoed. Other fields are validated in validate_hosting_input().
		$input = array(
			'panelType' => isset( $_POST['mobo_support_panel_type'] ) ? sanitize_key( wp_unslash( $_POST['mobo_support_panel_type'] ) ) : '',
			'panelUrl'  => isset( $_POST['mobo_support_panel_url'] ) ? trim( (string) wp_unslash( $_POST['mobo_support_panel_url'] ) ) : '',
			'username'  => isset( $_POST['mobo_support_panel_username'] ) ? sanitize_text_field( wp_unslash( $_POST['mobo_support_panel_username'] ) ) : '',
			'password'  => isset( $_POST['mobo_support_panel_password'] ) ? (string) wp_unslash( $_POST['mobo_support_panel_password'] ) : '',
		);
		// phpcs:enable
		$probe = ! empty( $_POST['mobo_support_panel_probe'] );
		unset( $_POST['mobo_support_panel_password'], $_REQUEST['mobo_support_panel_password'] );

		$result = Mobo_Core_Support_Access::submit_hosting_access( $input, $probe );
		$input  = null;
		if ( is_wp_error( $result ) ) {
			self::back( $result->get_error_message(), 'error' );
		}
		$message = 'اطلاعات دسترسی هاست به‌صورت رمزنگاری‌شده برای پشتیبانی موبو ارسال شد. رمز عبور در وردپرس ذخیره نشد.';
		if ( false === $result['panelReachable'] ) {
			$message .= ' هشدار: آدرس پنل از سرور سایت در دسترس نبود؛ درستی آدرس و پورت را بررسی کنید.';
		}
		self::back( $message, false === $result['panelReachable'] ? 'warning' : 'success' );
	}

	/** @return void */
	public static function handle_dismiss_source() {
		self::guard( 'mobo_core_support_dismiss_source' );
		$state = Mobo_Core_Support_Access::get_source_state();
		update_user_meta( get_current_user_id(), Mobo_Core_Support_Access::USER_META_SOURCE_DISMISSED, self::source_failure_key( $state ) );
		$redirect = wp_get_referer();
		wp_safe_redirect( $redirect ? $redirect : admin_url() );
		exit;
	}

	/**
	 * Distinct failure key (status + check time of first occurrence is not needed;
	 * a status change re-shows the notice).
	 *
	 * @param array $state Source state.
	 * @return string
	 */
	private static function source_failure_key( $state ) {
		return isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) . ':' . sanitize_key( (string) ( isset( $state['fingerprint'] ) ? substr( (string) $state['fingerprint'], 0, 12 ) : '' ) ) : '';
	}

	/* =====================================================================
	 * Admin notices.
	 * =================================================================== */

	/** @return void */
	public static function render_admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$pending = Mobo_Core_Support_Access::get_pending_requests();
		if ( ! empty( $pending ) ) {
			$latest = end( $pending );
			?>
			<div class="notice notice-warning mobo-support-request-notice" dir="rtl" style="text-align:right">
				<p><strong>پشتیبانی موبو برای بررسی مشکل سایت شما درخواست دسترسی کرده است</strong></p>
				<?php if ( ! empty( $latest['message'] ) ) : ?>
					<p>پیام پشتیبانی: <?php echo esc_html( (string) $latest['message'] ); ?></p>
				<?php endif; ?>
				<p>با زدن «اجازه دسترسی» یک کاربر مدیر جداگانه به نام «پشتیبانی موبو» برای <?php echo esc_html( (string) Mobo_Core_Support_Access::DEFAULT_DAYS ); ?> روز فعال می‌شود. هیچ رمز عبوری رد و بدل نمی‌شود و هر زمان از زبانه پشتیبانی قابل لغو است.</p>
				<div style="margin:0 0 10px">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="mobo_core_support_notice_grant">
						<?php wp_nonce_field( 'mobo_core_support_notice_grant' ); ?>
						<button type="submit" class="button button-primary">اجازه دسترسی</button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="mobo_core_support_decline">
						<?php wp_nonce_field( 'mobo_core_support_decline' ); ?>
						<button type="submit" class="button">رد درخواست</button>
					</form>
				</div>
			</div>
			<?php
		}

		$source = Mobo_Core_Support_Access::get_source_state();
		$status = isset( $source['status'] ) ? (string) $source['status'] : '';
		if ( in_array( $status, Mobo_Core_Support_Access::SOURCE_BAD_STATUSES, true ) ) {
			$key       = self::source_failure_key( $source );
			$dismissed = (string) get_user_meta( get_current_user_id(), Mobo_Core_Support_Access::USER_META_SOURCE_DISMISSED, true );
			if ( $key !== $dismissed ) {
				$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=mobo_core_support_dismiss_source' ), 'mobo_core_support_dismiss_source' );
				?>
				<div class="notice notice-error mobo-support-source-notice" dir="rtl" style="text-align:right">
					<p><strong>موبو نتوانست با حساب منبع شما وارد شود.</strong> <?php echo esc_html( Mobo_Core_Support_Access::source_status_message( $status ) ); ?></p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( Mobo_Core_Support_Access::source_settings_url() ); ?>">اصلاح</a>
						<a class="button" href="<?php echo esc_url( $dismiss_url ); ?>">بستن این پیام</a>
					</p>
				</div>
				<?php
			}
		}
	}

	/* =====================================================================
	 * Tab.
	 * =================================================================== */

	/**
	 * Local date.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private static function date_label( $timestamp ) {
		$timestamp = absint( $timestamp );
		if ( $timestamp <= 0 ) {
			return '—';
		}
		if ( class_exists( 'Mobo_Core_Iran_Date' ) ) {
			return Mobo_Core_Iran_Date::format( 'Y/m/d H:i', $timestamp );
		}
		return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i', $timestamp ) : gmdate( 'Y-m-d H:i', $timestamp );
	}

	/**
	 * Status box.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @return void
	 */
	private static function box( $label, $value ) {
		?>
		<div class="mobo-status-box"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( (string) $value ); ?></strong></div>
		<?php
	}

	/**
	 * Render the "پشتیبانی" tab.
	 *
	 * @return void
	 */
	public static function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		Mobo_Core_Support_Access::maybe_expire();

		$state    = Mobo_Core_Support_Access::get_state();
		$status   = Mobo_Core_Support_Access::get_status();
		$labels   = array(
			'granted'     => 'فعال',
			'not_granted' => 'داده نشده',
			'expired'     => 'منقضی شده',
			'revoked'     => 'لغو شده',
		);
		$badge    = 'granted' === $status ? 'success' : ( 'expired' === $status ? 'warning' : 'info' );
		$pending  = Mobo_Core_Support_Access::get_pending_requests();
		$source   = Mobo_Core_Support_Access::health_source_account();
		$src_raw  = Mobo_Core_Support_Access::get_source_state();
		$hosting  = Mobo_Core_Support_Access::get_hosting_state();
		$h_health = Mobo_Core_Support_Access::health_hosting_access();
		$ready    = Mobo_Core_Support_Access::health_readiness();
		$log      = Mobo_Core_Support_Access::get_log();
		$post_url = admin_url( 'admin-post.php' );
		$days_now = isset( $state['days'] ) ? Mobo_Core_Support_Access::clamp_days( $state['days'] ) : Mobo_Core_Support_Access::DEFAULT_DAYS;
		$openssl  = function_exists( 'openssl_public_encrypt' );
		$events   = array(
			'grant'               => 'اعطای دسترسی',
			'revoke'              => 'لغو دسترسی',
			'expire'              => 'انقضای خودکار',
			'link_created'        => 'ساخت لینک ورود',
			'link_refused'        => 'رد ساخت لینک (دسترسی فعال نیست)',
			'login_success'       => 'ورود موفق پشتیبانی',
			'login_failure'       => 'ورود ناموفق',
			'request_received'    => 'درخواست دسترسی از پشتیبانی',
			'request_declined'    => 'رد درخواست',
			'hosting_submitted'   => 'ارسال اطلاعات هاست',
			'hosting_send_failed' => 'ارسال ناموفق اطلاعات هاست',
		);
		$source_labels = array(
			'ok'                  => 'سالم',
			'invalid_credentials' => 'نام کاربری/رمز اشتباه',
			'locked'              => 'حساب قفل/محدود',
			'unreachable'         => 'منبع در دسترس نیست',
			'not_configured'      => 'تنظیم نشده',
			'unknown'             => 'نامشخص',
		);
		?>
		<div class="mobo-card mobo-ux-primary-card">
			<div class="mobo-admin-card-heading">
				<div>
					<h2 class="mobo-admin-section-title">دسترسی پشتیبانی بدون رمز عبور</h2>
					<p>با یک کلیک، کاربر مدیر جداگانه «پشتیبانی موبو» برای مدت محدود فعال می‌شود. پشتیبانی فقط با لینک ورود یک‌بارمصرف و کوتاه‌مدت وارد می‌شود و هیچ رمزی رد و بدل نمی‌شود.</p>
				</div>
				<span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ); ?></span>
			</div>
			<div class="mobo-ux-summary-grid mobo-ux-summary-grid--3">
				<?php self::box( 'وضعیت دسترسی', isset( $labels[ $status ] ) ? $labels[ $status ] : $status ); ?>
				<?php self::box( 'کاربر پشتیبانی', ! empty( $state['login'] ) ? (string) $state['login'] : '—' ); ?>
				<?php self::box( 'زمان اعطا', self::date_label( isset( $state['grantedAt'] ) ? $state['grantedAt'] : 0 ) ); ?>
				<?php self::box( 'پایان دسترسی', 'granted' === $status ? self::date_label( isset( $state['expiresAt'] ) ? $state['expiresAt'] : 0 ) : '—' ); ?>
				<?php self::box( 'درخواست در انتظار', empty( $pending ) ? 'ندارد' : count( $pending ) . ' مورد' ); ?>
			</div>

			<?php foreach ( $pending as $request ) : ?>
				<div class="mobo-message mobo-message-warning">
					<strong>درخواست پشتیبانی (<?php echo esc_html( self::date_label( isset( $request['at'] ) ? $request['at'] : 0 ) ); ?>):</strong>
					<?php echo esc_html( ! empty( $request['message'] ) ? (string) $request['message'] : 'بدون پیام' ); ?>
					<?php if ( ! empty( $request['requestedBy'] ) ) : ?> — <?php echo esc_html( (string) $request['requestedBy'] ); ?><?php endif; ?>
				</div>
			<?php endforeach; ?>

			<div class="mobo-fields-grid">
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="mobo-field">
					<input type="hidden" name="action" value="mobo_core_support_grant">
					<?php wp_nonce_field( 'mobo_core_support_grant' ); ?>
					<label for="mobo_support_days">مدت دسترسی (روز، ۱ تا ۳۰)</label>
					<input type="number" id="mobo_support_days" name="mobo_support_days" min="<?php echo esc_attr( (string) Mobo_Core_Support_Access::MIN_DAYS ); ?>" max="<?php echo esc_attr( (string) Mobo_Core_Support_Access::MAX_DAYS ); ?>" value="<?php echo esc_attr( (string) $days_now ); ?>" dir="ltr">
					<button type="submit" class="mobo-btn mobo-btn-primary"><?php echo 'granted' === $status ? 'تمدید دسترسی پشتیبانی' : 'اعطای دسترسی پشتیبانی'; ?></button>
					<div class="mobo-help">پس از پایان مدت، رمز کاربر پشتیبانی به‌صورت تصادفی عوض می‌شود، نقش مدیر حذف و نشست‌هایش بسته می‌شود. کاربر حذف نمی‌شود تا نویسندگی نوشته‌ها حفظ شود.</div>
				</form>
				<?php if ( 'granted' === $status ) : ?>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="mobo-field">
						<input type="hidden" name="action" value="mobo_core_support_revoke">
						<?php wp_nonce_field( 'mobo_core_support_revoke' ); ?>
						<label>لغو فوری</label>
						<button type="submit" class="mobo-btn mobo-btn-light" onclick="return confirm('دسترسی پشتیبانی همین حالا لغو شود؟');">لغو دسترسی پشتیبانی</button>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<div class="mobo-card">
			<div class="mobo-admin-card-heading"><div><h2 class="mobo-admin-section-title">آمادگی برای پشتیبانی</h2><p>مواردی که قبل از بررسی مشکل باید درست باشند. همین فهرست برای پشتیبانی موبو هم ارسال می‌شود.</p></div></div>
			<ul class="mobo-support-readiness" style="list-style:none;margin:0;padding:0">
				<?php foreach ( $ready as $item ) : ?>
					<li style="display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-bottom:1px solid #f0f0f1">
						<span class="dashicons <?php echo ! empty( $item['ok'] ) ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" style="color:<?php echo ! empty( $item['ok'] ) ? '#008a20' : '#d63638'; ?>" aria-hidden="true"></span>
						<span><?php echo esc_html( (string) $item['message'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="mobo-card">
			<div class="mobo-admin-card-heading">
				<div><h2 class="mobo-admin-section-title">حساب منبع (موبو)</h2><p>با همان نام کاربری و رمز تنظیمات سفارش موبو یک ورود آزمایشی انجام می‌شود (حداکثر هر ۶ ساعت یک بار خودکار، بدون تلاش مجدد). اگر نام کاربری یا رمز اشتباه باشد، تا اصلاح آن دوباره امتحان نمی‌شود تا حساب شما قفل نشود.</p></div>
				<span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( 'ok' === $source['status'] ? 'success' : ( in_array( $source['status'], Mobo_Core_Support_Access::SOURCE_BAD_STATUSES, true ) ? 'danger' : 'info' ) ); ?>"><?php echo esc_html( isset( $source_labels[ $source['status'] ] ) ? $source_labels[ $source['status'] ] : $source['status'] ); ?></span>
			</div>
			<div class="mobo-ux-summary-grid mobo-ux-summary-grid--3">
				<?php self::box( 'وضعیت', isset( $source_labels[ $source['status'] ] ) ? $source_labels[ $source['status'] ] : $source['status'] ); ?>
				<?php self::box( 'آخرین بررسی', self::date_label( isset( $src_raw['checkedAt'] ) ? $src_raw['checkedAt'] : 0 ) ); ?>
				<?php self::box( 'موجودی کیف پول', null === $source['walletLow'] ? 'نامشخص' : ( $source['walletLow'] ? 'کمتر از حد هشدار' : 'کافی' ) ); ?>
			</div>
			<div class="mobo-message <?php echo 'ok' === $source['status'] ? 'mobo-message-success' : 'mobo-message-warning'; ?>"><?php echo esc_html( $source['message'] ); ?></div>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline">
				<input type="hidden" name="action" value="mobo_core_support_check_source">
				<?php wp_nonce_field( 'mobo_core_support_check_source' ); ?>
				<button type="submit" class="mobo-btn mobo-btn-primary">بررسی ورود به حساب منبع</button>
			</form>
			<a class="mobo-btn mobo-btn-light" href="<?php echo esc_url( Mobo_Core_Support_Access::source_settings_url() ); ?>">اصلاح نام کاربری/رمز منبع</a>
		</div>

		<div class="mobo-card">
			<div class="mobo-admin-card-heading">
				<div><h2 class="mobo-admin-section-title">دسترسی پنل هاست (اختیاری)</h2><p>اگر پشتیبانی به پنل هاست نیاز دارد، اطلاعات را اینجا وارد کنید. رمز عبور روی همین سرور با کلید عمومی پشتیبانی موبو رمزنگاری و فقط برای پشتیبانی ارسال می‌شود و در وردپرس ذخیره نمی‌شود.</p></div>
				<span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( 'provided' === $h_health['status'] ? 'success' : ( 'send_failed' === $h_health['status'] ? 'danger' : 'info' ) ); ?>"><?php echo esc_html( 'provided' === $h_health['status'] ? 'ارسال شده' : ( 'send_failed' === $h_health['status'] ? 'ارسال ناموفق' : 'ارسال نشده' ) ); ?></span>
			</div>
			<?php if ( ! empty( $hosting['submittedAt'] ) ) : ?>
				<div class="mobo-message mobo-message-info">آخرین ارسال موفق: <?php echo esc_html( self::date_label( $hosting['submittedAt'] ) ); ?> — <?php echo esc_html( isset( $hosting['panelUrl'] ) ? (string) $hosting['panelUrl'] : '' ); ?> (<?php echo esc_html( isset( $hosting['username'] ) ? (string) $hosting['username'] : '' ); ?>)</div>
			<?php endif; ?>
			<?php if ( ! $openssl ) : ?>
				<div class="mobo-message mobo-message-error">افزونه OpenSSL روی PHP این هاست فعال نیست؛ امکان ارسال امن رمز عبور وجود ندارد. از هاستینگ بخواهید ext-openssl را فعال کند.</div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="mobo_core_support_hosting">
				<?php wp_nonce_field( 'mobo_core_support_hosting' ); ?>
				<div class="mobo-fields-grid">
					<div class="mobo-field">
						<label for="mobo_support_panel_type">نوع پنل</label>
						<select id="mobo_support_panel_type" name="mobo_support_panel_type" required>
							<?php foreach ( Mobo_Core_Support_Access::panel_types() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( isset( $hosting['panelType'] ) ? (string) $hosting['panelType'] : 'cpanel', $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="mobo-field">
						<label for="mobo_support_panel_url">آدرس پنل</label>
						<input type="url" id="mobo_support_panel_url" name="mobo_support_panel_url" dir="ltr" required placeholder="https://example.com:2083" value="<?php echo esc_attr( isset( $hosting['panelUrl'] ) ? (string) $hosting['panelUrl'] : '' ); ?>">
					</div>
					<div class="mobo-field">
						<label for="mobo_support_panel_username">نام کاربری پنل</label>
						<input type="text" id="mobo_support_panel_username" name="mobo_support_panel_username" dir="ltr" required autocomplete="off" spellcheck="false" autocapitalize="none" value="<?php echo esc_attr( isset( $hosting['username'] ) ? (string) $hosting['username'] : '' ); ?>">
					</div>
					<div class="mobo-field">
						<label for="mobo_support_panel_password">رمز عبور پنل</label>
						<input type="password" id="mobo_support_panel_password" name="mobo_support_panel_password" dir="ltr" required autocomplete="new-password" spellcheck="false" autocapitalize="none">
					</div>
					<div class="mobo-field mobo-field-full">
						<label><input type="checkbox" name="mobo_support_panel_probe" value="1" checked> قبل از ارسال، در دسترس بودن آدرس پنل از سرور سایت بررسی شود (بدون ورود به پنل)</label>
					</div>
				</div>
				<button type="submit" class="mobo-btn mobo-btn-primary" <?php disabled( ! $openssl ); ?>>ارسال امن برای پشتیبانی موبو</button>
			</form>
		</div>

		<div class="mobo-card">
			<div class="mobo-admin-card-heading"><div><h2 class="mobo-admin-section-title">گزارش دسترسی پشتیبانی</h2><p>۱۰۰ رویداد آخر (زمان، درخواست‌دهنده و IP).</p></div></div>
			<?php if ( empty( $log ) ) : ?>
				<div class="mobo-message mobo-message-info">هنوز رویدادی ثبت نشده است.</div>
			<?php else : ?>
				<div class="mobo-table-wrap">
					<table class="widefat striped">
						<thead><tr><th>زمان</th><th>رویداد</th><th>درخواست‌دهنده</th><th>IP</th><th>جزئیات</th></tr></thead>
						<tbody>
							<?php foreach ( $log as $entry ) : ?>
								<?php $event = isset( $entry['event'] ) ? (string) $entry['event'] : ''; ?>
								<tr>
									<td><?php echo esc_html( self::date_label( isset( $entry['at'] ) ? $entry['at'] : 0 ) ); ?></td>
									<td><?php echo esc_html( isset( $events[ $event ] ) ? $events[ $event ] : $event ); ?></td>
									<td dir="ltr"><?php echo esc_html( isset( $entry['requestedBy'] ) ? (string) $entry['requestedBy'] : '' ); ?></td>
									<td dir="ltr"><?php echo esc_html( isset( $entry['ip'] ) ? (string) $entry['ip'] : '' ); ?></td>
									<td dir="ltr"><?php echo esc_html( isset( $entry['detail'] ) ? (string) $entry['detail'] : '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<?php Mobo_Core_Diagnostics_Admin::render_section(); ?>
		<?php
	}
}
