<?php
/**
 * "سلامت داده" admin tab: scan status, per-class counts, dry-run preview with
 * exact affected IDs, confirmed apply, batch history with undo, and AJAX
 * auto-continue while the page is open (each request runs one bounded slice).
 *
 * Every state change goes through admin-post/admin-ajax with manage_options and
 * a nonce. Rendering is read-only.
 *
 * PHP 7.4 compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mobo_Core_Data_Health_Admin {

	const NONCE      = 'mobo_core_data_health';
	const TAB        = 'data-health';
	const PAGE_LIMIT = 100;

	/** @return void */
	public static function register_hooks() {
		add_action( 'admin_init', array( 'Mobo_Core_Data_Health', 'ensure_schedule' ) );
		add_action( 'admin_post_mobo_core_data_health_scan', array( __CLASS__, 'handle_scan' ) );
		add_action( 'admin_post_mobo_core_data_health_stop', array( __CLASS__, 'handle_stop' ) );
		add_action( 'admin_post_mobo_core_data_health_resume', array( __CLASS__, 'handle_resume' ) );
		add_action( 'admin_post_mobo_core_data_health_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_mobo_core_data_health_undo', array( __CLASS__, 'handle_undo' ) );
		add_action( 'admin_post_mobo_core_data_health_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'wp_ajax_mobo_core_data_health_step', array( __CLASS__, 'ajax_step' ) );
	}

	/** @return string */
	public static function tab_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'mobo-core', 'tab' => self::TAB ), is_array( $args ) ? $args : array() ), admin_url( 'admin.php' ) );
	}

	/** @return void */
	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'mobo-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
	}

	/** @return void */
	private static function back( $message, $type = 'success', $extra = array() ) {
		wp_safe_redirect(
			self::tab_url(
				array_merge(
					array(
						'mobo_message' => rawurlencode( sanitize_text_field( (string) $message ) ),
						'mobo_type'    => sanitize_key( $type ),
					),
					$extra
				)
			)
		);
		exit;
	}

	/** @return string */
	private static function actor() {
		$user = wp_get_current_user();
		return $user && $user->exists() ? (string) $user->user_login : 'admin';
	}

	/** @return void */
	public static function handle_scan() {
		self::guard();
		$state = Mobo_Core_Data_Health::start_scan( 'admin' );
		if ( is_wp_error( $state ) ) {
			self::back( $state->get_error_message(), 'warning' );
		}
		Mobo_Core_Data_Health::run_slice( 8.0 );
		self::back( 'بررسی فقط‌خواندنی داده شروع شد. تا وقتی این صفحه باز است خودکار ادامه می‌یابد؛ در پس‌زمینه هم با Cron ادامه پیدا می‌کند.' );
	}

	/** @return void */
	public static function handle_stop() {
		self::guard();
		Mobo_Core_Data_Health::request_stop();
		self::back( 'متوقف شد. نتایج تا همین نقطه حفظ شده و با «ادامه» از همان‌جا ادامه می‌یابد.' );
	}

	/** @return void */
	public static function handle_resume() {
		self::guard();
		Mobo_Core_Data_Health::resume();
		Mobo_Core_Data_Health::run_slice( 8.0 );
		self::back( 'ادامه داده شد.' );
	}

	/** @return void */
	public static function handle_settings() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		update_option( Mobo_Core_Data_Health::AUTO_OPTION, empty( $_POST['auto_repair'] ) ? '0' : '1', false );
		update_option( Mobo_Core_Data_Health::DAILY_OPTION, empty( $_POST['daily_scan'] ) ? '0' : '1', false );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		self::back( 'تنظیمات سلامت داده ذخیره شد.' );
	}

	/** @return void */
	public static function handle_apply() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$detector = isset( $_POST['detector'] ) ? sanitize_key( wp_unslash( $_POST['detector'] ) ) : '';
		$confirm  = ! empty( $_POST['confirm_reviewed'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $confirm ) {
			self::back( 'برای اعمال، تأیید بررسی فهرست لازم است.', 'warning', array( 'dh_preview' => $detector ) );
		}
		$defs = Mobo_Core_Data_Health_Detectors::definitions();
		if ( ! isset( $defs[ $detector ] ) || 'c' === $defs[ $detector ]['class'] ) {
			self::back( 'این مورد ترمیم خودکار ندارد.', 'warning' );
		}
		$job = Mobo_Core_Data_Health::start_apply( array( $detector ), array( $defs[ $detector ]['class'] ), self::actor() );
		if ( is_wp_error( $job ) ) {
			self::back( $job->get_error_message(), 'warning', array( 'dh_preview' => $detector ) );
		}
		Mobo_Core_Data_Health::run_slice( 8.0 );
		self::back( 'ترمیم با پشتیبان‌گیری شروع شد. هر مورد پیش از تغییر دوباره بررسی می‌شود و قابل بازگردانی است.' );
	}

	/** @return void */
	public static function handle_undo() {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$batch = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';
		$job   = Mobo_Core_Data_Health::start_undo( $batch, self::actor() );
		if ( is_wp_error( $job ) ) {
			self::back( $job->get_error_message(), 'warning' );
		}
		Mobo_Core_Data_Health::run_slice( 8.0 );
		self::back( 'بازگردانی شروع شد. مواردی که بعد از ترمیم تغییر کرده‌اند دست نمی‌خورند و به‌عنوان «تعارض» گزارش می‌شوند.' );
	}

	/** One bounded slice for the open admin page. @return void */
	public static function ajax_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
		$out = Mobo_Core_Data_Health::run_slice( 8.0 );
		$sum = Mobo_Core_Data_Health::health_summary();
		wp_send_json_success(
			array(
				'scanStatus'  => $out['state']['status'],
				'jobStatus'   => $out['job']['status'],
				'progressPct' => $sum['progressPct'],
				'running'     => 'running' === $out['state']['status'] || 'running' === $out['job']['status'],
				'label'       => self::progress_label( $out['state'], $out['job'] ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                          */
	/* ------------------------------------------------------------------ */

	/** @return array<string,string> */
	private static function class_labels() {
		return array(
			'a' => 'خودکار و امن',
			'b' => 'با تأیید مدیر',
			'c' => 'فقط گزارش / پشتیبانی',
		);
	}

	/** @return array<string,string> */
	private static function group_labels() {
		return array(
			'identity' => 'شناسه و نگاشت محصولات موبو',
			'topology' => 'ساختار محصول و تنوع',
			'index'    => 'جدول‌های کمکی ووکامرس',
			'media'    => 'تصاویر',
			'price'    => 'قیمت و موجودی',
			'text'     => 'متن خراب‌شده',
			'state'    => 'وضعیت عملیات و Migration',
		);
	}

	/** @return array<string,string> */
	private static function status_labels() {
		return array(
			'idle'     => 'هنوز بررسی نشده',
			'running'  => 'در حال اجرا',
			'complete' => 'کامل',
			'stopped'  => 'متوقف',
			'failed'   => 'ناموفق',
			'blocked'  => 'منتظر (عملیات دیگری در جریان است)',
			'applied'  => 'اعمال شد',
			'undone'   => 'بازگردانده شد',
			'undoing'  => 'در حال بازگردانی',
			'partially_undone' => 'بخشی بازگردانده شد',
		);
	}

	/** @return string */
	private static function fa_number( $n ) {
		$s = function_exists( 'number_format_i18n' ) ? number_format_i18n( (float) $n ) : (string) $n;
		return class_exists( 'Mobo_Core_Job_Diagnostics' ) ? Mobo_Core_Job_Diagnostics::persian_digits( $s ) : $s;
	}

	/** @return string */
	private static function date_label( $ts ) {
		$ts = absint( $ts );
		if ( $ts <= 0 ) {
			return '—';
		}
		if ( class_exists( 'Mobo_Core_Iran_Date' ) && method_exists( 'Mobo_Core_Iran_Date', 'format' ) ) {
			return (string) Mobo_Core_Iran_Date::format( 'Y/m/d H:i', $ts );
		}
		return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d H:i', $ts ) : gmdate( 'Y-m-d H:i', $ts );
	}

	/** @return string */
	private static function progress_label( array $state, array $job ) {
		if ( 'running' === $job['status'] ) {
			return sprintf( '%s: %s اعمال، %s بازگردانی، %s رد، %s تعارض', 'undo' === $job['type'] ? 'بازگردانی' : 'ترمیم', self::fa_number( $job['applied'] ), self::fa_number( $job['undone'] ), self::fa_number( $job['skipped'] ), self::fa_number( $job['conflicts'] ) );
		}
		if ( 'running' === $state['status'] ) {
			$defs  = Mobo_Core_Data_Health_Detectors::definitions();
			$ids   = array_keys( $defs );
			$index = absint( $state['index'] );
			$name  = 'purge' === $state['phase'] ? 'آماده‌سازی' : ( isset( $ids[ $index ] ) ? $defs[ $ids[ $index ] ]['label'] : '' );
			return sprintf( 'بررسی %s از %s: %s', self::fa_number( min( $index + 1, count( $ids ) ) ), self::fa_number( count( $ids ) ), $name );
		}
		return '';
	}

	/** @return void */
	private static function box( $label, $value ) {
		?>
		<div class="mobo-status-box"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( (string) $value ); ?></strong></div>
		<?php
	}

	/** @return void */
	private static function post_button( $action, $label, $class = 'mobo-btn', $fields = array(), $confirm = '' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:4px 0 4px 6px;"<?php echo '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : ''; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/** Render the tab. @return void */
	public static function render_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state    = Mobo_Core_Data_Health::get_state();
		$job      = Mobo_Core_Data_Health::get_job();
		$summary  = Mobo_Core_Data_Health::health_summary();
		$defs     = Mobo_Core_Data_Health_Detectors::definitions();
		$statuses = self::status_labels();
		$running  = 'running' === $state['status'] || 'running' === $job['status'];
		$badge    = 'complete' === $state['status'] ? 'success' : ( $running ? 'info' : ( 'idle' === $state['status'] ? 'warning' : 'warning' ) );
		$blocked  = Mobo_Core_Data_Health::repairs_blocked_reason();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$preview  = isset( $_GET['dh_preview'] ) ? sanitize_key( wp_unslash( $_GET['dh_preview'] ) ) : '';
		?>
		<div class="mobo-card mobo-ux-primary-card" data-mobo-data-health="1">
			<div class="mobo-admin-card-heading">
				<div>
					<h2 class="mobo-admin-section-title">سلامت داده محصولات</h2>
					<p>بررسی فقط‌خواندنی داده‌های خراب‌شده از نسخه‌های قدیمی یا قطعی‌های قبلی (تکراری‌ها، تنوع‌های یتیم، نگاشت شکسته، جدول‌های کمکی ووکامرس و…). هیچ محصول یا تصویری حذف نمی‌شود؛ هر ترمیم پیش از تغییر پشتیبان می‌گیرد و قابل بازگردانی است.</p>
				</div>
				<span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( isset( $statuses[ $state['status'] ] ) ? $statuses[ $state['status'] ] : $state['status'] ); ?></span>
			</div>
			<div class="mobo-ux-summary-grid mobo-ux-summary-grid--5">
				<?php self::box( 'آخرین بررسی کامل', self::date_label( $state['lastCompleteAt'] ) ); ?>
				<?php self::box( 'پیشرفت بررسی', self::fa_number( $summary['progressPct'] ) . '٪' ); ?>
				<?php self::box( 'قابل ترمیم خودکار', self::fa_number( $summary['byRepairClass']['automatic'] ) ); ?>
				<?php self::box( 'نیازمند تأیید مدیر', self::fa_number( $summary['byRepairClass']['adminConfirm'] ) ); ?>
				<?php self::box( 'فقط گزارش', self::fa_number( $summary['byRepairClass']['reportOnly'] ) ); ?>
			</div>
			<p data-mobo-dh-progress="1" style="margin:8px 0;"><?php echo esc_html( self::progress_label( $state, $job ) ); ?></p>
			<?php if ( ! Mobo_Core_Data_Health_Store::is_ready() && 'idle' !== $state['status'] ) : ?>
				<div class="mobo-message mobo-message-warning">جدول نتایج/پشتیبان روی این هاست ساخته نشد؛ شمارش انجام می‌شود ولی هیچ ترمیمی بدون پشتیبان اجرا نمی‌شود.</div>
			<?php endif; ?>
			<?php if ( '' !== (string) $state['lastError'] ) : ?>
				<div class="mobo-message mobo-message-warning"><?php echo esc_html( (string) $state['lastError'] ); ?></div>
			<?php endif; ?>
			<div>
				<?php if ( 'running' === $state['status'] ) : ?>
					<?php self::post_button( 'mobo_core_data_health_stop', 'توقف' ); ?>
				<?php elseif ( 'stopped' === $state['status'] || 'failed' === $state['status'] ) : ?>
					<?php self::post_button( 'mobo_core_data_health_resume', 'ادامه بررسی', 'mobo-btn mobo-btn-primary' ); ?>
					<?php self::post_button( 'mobo_core_data_health_scan', 'شروع دوباره' ); ?>
				<?php else : ?>
					<?php self::post_button( 'mobo_core_data_health_scan', 'idle' === $state['status'] ? 'شروع بررسی' : 'بررسی دوباره', 'mobo-btn mobo-btn-primary' ); ?>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( 'idle' !== $job['status'] && '' !== $job['type'] ) : ?>
			<div class="mobo-card">
				<div class="mobo-admin-card-heading">
					<div>
						<h2 class="mobo-admin-section-title"><?php echo esc_html( 'undo' === $job['type'] ? 'آخرین بازگردانی' : ( 'auto' === $job['actor'] ? 'آخرین ترمیم خودکار' : 'آخرین ترمیم' ) ); ?></h2>
						<p><?php echo esc_html( 'دسته ' . $job['batchId'] ); ?></p>
					</div>
					<span class="mobo-admin-badge mobo-admin-badge--<?php echo esc_attr( 'complete' === $job['status'] ? 'success' : ( 'running' === $job['status'] ? 'info' : 'warning' ) ); ?>"><?php echo esc_html( isset( $statuses[ $job['status'] ] ) ? $statuses[ $job['status'] ] : $job['status'] ); ?></span>
				</div>
				<div class="mobo-ux-summary-grid mobo-ux-summary-grid--5">
					<?php self::box( 'اعمال‌شده', self::fa_number( $job['applied'] ) ); ?>
					<?php self::box( 'بازگردانده', self::fa_number( $job['undone'] ) ); ?>
					<?php self::box( 'رد (دیگر لازم نبود)', self::fa_number( $job['skipped'] ) ); ?>
					<?php self::box( 'تعارض', self::fa_number( $job['conflicts'] ) ); ?>
					<?php self::box( 'ناموفق', self::fa_number( $job['failed'] ) ); ?>
				</div>
				<?php if ( '' !== (string) $job['lastError'] ) : ?>
					<div class="mobo-message mobo-message-warning"><?php echo esc_html( (string) $job['lastError'] ); ?></div>
				<?php endif; ?>
				<?php if ( in_array( $job['status'], array( 'stopped', 'blocked', 'failed' ), true ) ) : ?>
					<?php self::post_button( 'mobo_core_data_health_resume', 'ادامه', 'mobo-btn mobo-btn-primary' ); ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php
		if ( '' !== $preview && isset( $defs[ $preview ] ) ) {
			self::render_preview( $preview, $defs[ $preview ], $state, $blocked );
		}
		self::render_detectors( $defs, $state, $blocked );
		self::render_batches();
		self::render_settings_and_help();
		self::render_script( $running );
	}

	/** @return void */
	private static function render_detectors( array $defs, array $state, $blocked ) {
		$classes = self::class_labels();
		$groups  = self::group_labels();
		$by      = array();
		foreach ( $defs as $id => $def ) {
			$by[ $def['group'] ][ $id ] = $def;
		}
		?>
		<div class="mobo-card">
			<h2 class="mobo-admin-section-title">نتیجه بررسی</h2>
			<?php if ( '' !== $blocked ) : ?>
				<div class="mobo-message mobo-message-warning"><?php echo esc_html( 'ترمیم فعلاً ممکن نیست: ' . $blocked ); ?></div>
			<?php endif; ?>
			<div class="mobo-table-wrap">
				<table class="widefat striped">
					<thead><tr><th>مشکل</th><th>تعداد</th><th>نوع ترمیم</th><th>راه حل</th><th>اقدام</th></tr></thead>
					<tbody>
					<?php foreach ( $by as $group => $items ) : ?>
						<tr><td colspan="5"><strong><?php echo esc_html( isset( $groups[ $group ] ) ? $groups[ $group ] : $group ); ?></strong></td></tr>
						<?php
						foreach ( $items as $id => $def ) :
							$d     = isset( $state['detectors'][ $id ] ) ? $state['detectors'][ $id ] : null;
							$count = is_array( $d ) ? absint( $d['count'] ) : 0;
							$open  = is_array( $d ) && 'c' !== $def['class'] ? Mobo_Core_Data_Health_Store::count_findings( (string) $state['lastCompleteScan'], $id, 'open', $def['class'] ) : 0;
							?>
							<tr>
								<td><?php echo esc_html( $def['label'] ); ?><?php if ( is_array( $d ) && '' !== (string) $d['error'] ) : ?><br><small style="color:#b32d2e;"><?php echo esc_html( 'خطا: ' . $d['error'] ); ?></small><?php endif; ?><?php if ( is_array( $d ) && '' !== (string) $d['note'] ) : ?><br><small><?php echo esc_html( (string) $d['note'] ); ?></small><?php endif; ?></td>
								<td><?php echo esc_html( is_array( $d ) ? self::fa_number( $count ) : '—' ); ?><?php if ( is_array( $d ) && absint( $d['groups'] ) > 0 ) : ?><br><small><?php echo esc_html( self::fa_number( $d['groups'] ) . ' گروه' ); ?></small><?php endif; ?></td>
								<td><?php echo esc_html( $classes[ $def['class'] ] ); ?><?php if ( is_array( $d ) && 'c' !== $def['class'] && absint( $d['byClass']['c'] ) > 0 ) : ?><br><small><?php echo esc_html( self::fa_number( $d['byClass']['c'] ) . ' مورد مبهم فقط گزارش می‌شود' ); ?></small><?php endif; ?></td>
								<td><small><?php echo esc_html( $def['fix'] ); ?></small></td>
								<td>
									<?php if ( $count > 0 && is_array( $d ) ) : ?>
										<a class="mobo-btn" href="<?php echo esc_url( self::tab_url( array( 'dh_preview' => $id ) ) ); ?>"><?php echo esc_html( 'b' === $def['class'] && $open > 0 ? 'پیش‌نمایش و تأیید (' . self::fa_number( $open ) . ')' : 'فهرست' ); ?></a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/** @return string Short object label (title / type) for the preview list. */
	private static function object_label( $id ) {
		$id = absint( $id );
		if ( $id <= 0 || ! function_exists( 'get_post' ) ) {
			return '';
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return '(وجود ندارد)';
		}
		return wp_html_excerpt( (string) $post->post_title, 70, '…' ) . ' — ' . (string) $post->post_type . '/' . (string) $post->post_status;
	}

	/** @return void */
	private static function render_preview( $id, array $def, array $state, $blocked ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$after   = isset( $_GET['dh_after'] ) ? absint( $_GET['dh_after'] ) : 0;
		$scan    = (string) $state['lastCompleteScan'];
		$rows    = Mobo_Core_Data_Health_Store::findings_page( $scan, $id, $after, self::PAGE_LIMIT, '' );
		$open    = 'c' === $def['class'] ? 0 : Mobo_Core_Data_Health_Store::count_findings( $scan, $id, 'open', $def['class'] );
		$classes = self::class_labels();
		?>
		<div class="mobo-card" id="mobo-dh-preview">
			<div class="mobo-admin-card-heading">
				<div>
					<h2 class="mobo-admin-section-title"><?php echo esc_html( 'پیش‌نمایش (اجرای آزمایشی): ' . $def['label'] ); ?></h2>
					<p><?php echo esc_html( $def['fix'] ); ?></p>
				</div>
				<a class="mobo-btn" href="<?php echo esc_url( self::tab_url() ); ?>">بستن</a>
			</div>
			<?php if ( false === $rows ) : ?>
				<div class="mobo-message mobo-message-warning">فهرست جزئیات در دسترس نیست (جدول نتایج ساخته نشده است).</div>
			<?php else : ?>
				<div class="mobo-table-wrap">
					<table class="widefat striped">
						<thead><tr><th>شناسه</th><th>شیء</th><th>نسخه اصلی</th><th>دلیل</th><th>نوع</th><th>وضعیت</th></tr></thead>
						<tbody>
						<?php
						$last = $after;
						foreach ( $rows as $row ) :
							$last   = absint( $row['id'] );
							$detail = json_decode( (string) $row['detail'], true );
							$detail = is_array( $detail ) ? $detail : array();
							$oid    = absint( $row['object_id'] );
							?>
							<tr>
								<td><?php if ( $oid > 0 && function_exists( 'get_edit_post_link' ) && get_edit_post_link( $oid ) ) : ?><a href="<?php echo esc_url( (string) get_edit_post_link( $oid ) ); ?>"><?php echo esc_html( '#' . $oid ); ?></a><?php else : ?><?php echo esc_html( $oid > 0 ? '#' . $oid : (string) $row['object_ref'] ); ?><?php endif; ?></td>
								<td><?php echo esc_html( self::object_label( $oid ) ); ?></td>
								<td><?php echo esc_html( absint( $row['canonical_id'] ) > 0 ? '#' . absint( $row['canonical_id'] ) . ' ' . self::object_label( absint( $row['canonical_id'] ) ) : '—' ); ?></td>
								<td><small><?php echo esc_html( isset( $detail['reason'] ) ? (string) $detail['reason'] : '' ); ?></small></td>
								<td><?php echo esc_html( isset( $classes[ $row['repair_class'] ] ) ? $classes[ $row['repair_class'] ] : $row['repair_class'] ); ?></td>
								<td><?php echo esc_html( (string) $row['status'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php if ( count( $rows ) >= self::PAGE_LIMIT ) : ?>
					<p><a class="mobo-btn" href="<?php echo esc_url( self::tab_url( array( 'dh_preview' => $id, 'dh_after' => $last ) ) . '#mobo-dh-preview' ); ?>">صفحه بعد</a></p>
				<?php endif; ?>
				<?php if ( 'b' === $def['class'] && $open > 0 ) : ?>
					<?php if ( '' !== $blocked ) : ?>
						<div class="mobo-message mobo-message-warning"><?php echo esc_html( $blocked ); ?></div>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( 'ترمیم روی موارد «با تأیید مدیر» این فهرست اجرا شود؟ هر مورد پیش از تغییر دوباره بررسی و پشتیبان‌گیری می‌شود و قابل بازگردانی است.' ) ); ?>);">
							<input type="hidden" name="action" value="mobo_core_data_health_apply">
							<input type="hidden" name="detector" value="<?php echo esc_attr( $id ); ?>">
							<?php wp_nonce_field( self::NONCE ); ?>
							<label><input type="checkbox" name="confirm_reviewed" value="1"> فهرست بالا را بررسی کردم؛ ترمیم روی <?php echo esc_html( self::fa_number( $open ) ); ?> مورد اجرا شود (موارد «فقط گزارش» دست نمی‌خورند).</label>
							<p><button type="submit" class="mobo-btn mobo-btn-primary">اعمال با پشتیبان‌گیری</button></p>
						</form>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @return void */
	private static function render_batches() {
		$batches  = Mobo_Core_Data_Health::get_batches();
		$statuses = self::status_labels();
		$defs     = Mobo_Core_Data_Health_Detectors::definitions();
		?>
		<div class="mobo-card">
			<h2 class="mobo-admin-section-title">تاریخچه ترمیم و بازگردانی</h2>
			<?php if ( empty( $batches ) ) : ?>
				<p>هنوز ترمیمی انجام نشده است.</p>
			<?php else : ?>
				<div class="mobo-table-wrap">
					<table class="widefat striped">
						<thead><tr><th>زمان</th><th>نوع</th><th>موارد</th><th>اعمال</th><th>بازگردانی</th><th>تعارض</th><th>وضعیت</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $batches as $batch ) : ?>
							<?php
							$labels = array();
							foreach ( isset( $batch['detectors'] ) ? (array) $batch['detectors'] : array() as $d ) {
								$labels[] = isset( $defs[ $d ] ) ? $defs[ $d ]['label'] : $d;
							}
							$status = isset( $batch['status'] ) ? (string) $batch['status'] : '';
							?>
							<tr>
								<td><?php echo esc_html( self::date_label( isset( $batch['createdAt'] ) ? $batch['createdAt'] : 0 ) ); ?></td>
								<td><?php echo esc_html( 'auto' === ( isset( $batch['type'] ) ? $batch['type'] : '' ) ? 'خودکار' : 'مدیر: ' . ( isset( $batch['actor'] ) ? $batch['actor'] : '' ) ); ?></td>
								<td><small><?php echo esc_html( implode( '، ', $labels ) ); ?></small></td>
								<td><?php echo esc_html( self::fa_number( isset( $batch['applied'] ) ? $batch['applied'] : 0 ) ); ?></td>
								<td><?php echo esc_html( self::fa_number( isset( $batch['undone'] ) ? $batch['undone'] : 0 ) ); ?></td>
								<td><?php echo esc_html( self::fa_number( isset( $batch['conflicts'] ) ? $batch['conflicts'] : 0 ) ); ?></td>
								<td><?php echo esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status ); ?></td>
								<td>
									<?php if ( in_array( $status, array( 'applied', 'partially_undone', 'failed' ), true ) && absint( isset( $batch['applied'] ) ? $batch['applied'] : 0 ) > 0 ) : ?>
										<?php self::post_button( 'mobo_core_data_health_undo', 'بازگردانی', 'mobo-btn', array( 'batch_id' => (string) $batch['id'] ), 'همه تغییرات این دسته به حالت قبل برگردد؟ مواردی که بعد از ترمیم تغییر کرده‌اند دست نمی‌خورند.' ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @return void */
	private static function render_settings_and_help() {
		?>
		<div class="mobo-card">
			<h2 class="mobo-admin-section-title">تنظیمات و راهنما</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mobo_core_data_health_settings">
				<?php wp_nonce_field( self::NONCE ); ?>
				<p><label><input type="checkbox" name="daily_scan" value="1" <?php checked( Mobo_Core_Data_Health::daily_scan_enabled() ); ?>> بررسی روزانه در پس‌زمینه (فقط‌خواندنی، در بخش‌های کوتاه)</label></p>
				<p><label><input type="checkbox" name="auto_repair" value="1" <?php checked( Mobo_Core_Data_Health::auto_repair_enabled() ); ?>> ترمیم خودکار موارد «خودکار و امن» بعد از هر بررسی کامل (با پشتیبان و قابل بازگردانی)</label></p>
				<p><button type="submit" class="mobo-btn">ذخیره</button></p>
			</form>
			<ul style="list-style:disc;margin-right:20px;">
				<li><strong>خودکار و امن:</strong> فقط ردیف‌های کمکی که به چیزی اشاره نمی‌کنند (اتصال دسته به محصول حذف‌شده، ردیف جدول جست‌وجوی ووکامرس، نگاشت شکسته) یا بازسازی جدول جست‌وجو از روی خود محصول. هیچ چیزی در فروشگاه پنهان یا حذف نمی‌شود.</li>
				<li><strong>با تأیید مدیر:</strong> فهرست دقیق شناسه‌ها را ببینید، تأیید کنید؛ هر مورد پیش از تغییر دوباره بررسی می‌شود. کپی تکراری محصول ناموجود و پنهان از فهرست می‌شود (نشانی و سئو حفظ می‌شود)، تنوع تکراری غیرفعال و ناموجود می‌شود. هیچ محصول، تنوع یا تصویری حذف نمی‌شود.</li>
				<li><strong>بازگردانی:</strong> از «تاریخچه» هر دسته را برگردانید. اگر بعد از ترمیم، شما یا همگام‌سازی آن مورد را تغییر داده باشید، دست نمی‌خورد و «تعارض» گزارش می‌شود.</li>
				<li><strong>فقط گزارش:</strong> این موارد به داده منبع یا تصمیم پشتیبانی نیاز دارند (Repair، تبدیل utf8mb4، دریافت دوباره تصویر). شمارش آن‌ها برای پشتیبانی موبو هم ارسال می‌شود (فقط تعداد، بدون اطلاعات محصول یا مشتری).</li>
			</ul>
		</div>
		<?php
	}

	/** @return void */
	private static function render_script( $running ) {
		if ( ! $running ) {
			return;
		}
		$data = array(
			'url'   => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::NONCE ),
		);
		?>
		<script>
		(function () {
			var cfg = <?php echo wp_json_encode( $data ); ?>;
			var label = document.querySelector('[data-mobo-dh-progress]');
			var failures = 0;
			function step() {
				var body = new FormData();
				body.append('action', 'mobo_core_data_health_step');
				body.append('nonce', cfg.nonce);
				fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						failures = 0;
						if (!res || !res.success) { return; }
						if (label && res.data.label) { label.textContent = res.data.label; }
						if (res.data.running) { setTimeout(step, 1500); } else { window.location.reload(); }
					})
					.catch(function () { failures++; if (failures < 5) { setTimeout(step, 5000); } });
			}
			setTimeout(step, 1000);
		})();
		</script>
		<?php
	}
}
