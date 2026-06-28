<?php
/**
 * Public payment report page handler.
 *
 * Intercepts requests to /olama-payment-report/{prefix}.{raw_hex},
 * validates the token, logs the view, and renders the Arabic RTL report.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Public_Report {

	/** @var Olama_Messages_Plugin */
	private $plugin;

	public function __construct( Olama_Messages_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function init() {
		add_filter( 'redirect_canonical', array( $this, 'preserve_short_url' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_render_report' ) );
	}

	/** Keep the compact no-trailing-slash SMS URL from gaining a redirect. */
	public function preserve_short_url( $redirect_url, $requested_url ) {
		return get_query_var( 'olama_short_code', '' ) ? false : $redirect_url;
	}

	// ─── Route handler ───────────────────────────────────────────────────────

	public function maybe_render_report() {
		$url_token = get_query_var( 'olama_payment_token', '' );
		$short_code = get_query_var( 'olama_short_code', '' );
		if ( ! $url_token && ! $short_code ) {
			return;
		}

		// Validate either the legacy one-time raw URL or the first-party short code.
		$token_svc = $this->plugin->tokens();
		$token     = $short_code
			? $this->plugin->short_links()->resolve_short_code( $short_code )
			: $token_svc->validate_token( $url_token );

		if ( ! $token ) {
			$this->render_invalid();
			exit;
		}

		// Load family report.
		$provider = $this->plugin->provider();
		$report   = $provider->get_family_payment_report(
			$token['family_id'],
			$token['study_year'] ?? ''
		);

		if ( ! $report ) {
			$this->render_invalid();
			exit;
		}

		// Log view (after successful validation so we don't count invalid attempts).
		$token_svc->log_view( (int) $token['id'], (int) $token['family_id'] );

		// Render the report.
		$this->render_report( $report, $token );
		exit;
	}

	// ─── Renderers ───────────────────────────────────────────────────────────

	/** Render the Arabic RTL report page. */
	private function render_report( array $report, array $token ) {
		$school_name          = esc_html( get_option( 'olama_msg_school_name', 'أكاديمية علماء المستقبل' ) );
		$contact_phone        = esc_html( get_option( 'olama_msg_contact_phone', '' ) );
		$payment_instructions = wp_kses_post( get_option( 'olama_msg_payment_instructions', '' ) );

		$sponsor_name = esc_html( $report['sponsor_name'] );
		$study_year   = esc_html( $report['study_year'] );
		$fin        = isset( $report['financial'] ) && is_array( $report['financial'] ) ? $report['financial'] : $report;
		$currency   = esc_html( $fin['currency'] ?? 'JOD' );

		$balance     = null;
		$monthly_due = null;
		$fin_ok      = (bool) ( $fin['financial_available'] ?? false );

		$raw_balance = $fin['balance'] ?? null;
		$raw_monthly = $fin['monthly_due'] ?? null;

		$balance_status_text = '';
		if ( $fin_ok && $raw_balance !== null ) {
			if ( is_numeric( $raw_balance ) ) {
				$balance = number_format( (float) $raw_balance, 3 );
				$raw_balance_val = (float) $raw_balance;
				if ( $raw_balance_val > 0 ) {
					$balance_status_text = 'مستحق';
				} elseif ( $raw_balance_val < 0 ) {
					$balance_status_text = 'رصيد دائن / لصالحكم';
				} else {
					$balance_status_text = 'لا يوجد رصيد مستحق';
				}
			}
		}

		if ( $fin_ok && $raw_monthly !== null ) {
			if ( is_numeric( $raw_monthly ) ) {
				$monthly_due = number_format( (float) $raw_monthly, 3 );
			}
		}

		$students  = is_array( $report['students'] )  ? $report['students']  : array();
		$due_items = isset( $fin['due_items'] ) && is_array( $fin['due_items'] ) ? $fin['due_items'] : array();
		$last_pmt  = $fin['last_payment'] ?? null;

		?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo $school_name; ?> — كشف الرصيد</title>
	<link rel="stylesheet" href="<?php echo esc_url( OLAMA_MSG_URL . 'assets/public-report.css' ); ?>?v=<?php echo esc_attr( OLAMA_MSG_VERSION ); ?>">
</head>
<body class="olama-report-body">

<div class="olama-report-container">

	<!-- Header -->
	<header class="olama-report-header">
		<div class="olama-report-school-name"><?php echo $school_name; ?></div>
		<div class="olama-report-doc-title">كشف الرصيد والمستحقات</div>
		<?php if ( $study_year ) : ?>
			<div class="olama-report-year">العام الدراسي: <?php echo $study_year; ?></div>
		<?php endif; ?>
	</header>

	<!-- Parent Info -->
	<section class="olama-report-section">
		<h2 class="olama-report-section__title">بيانات ولي الأمر</h2>
		<div class="olama-report-info-grid">
			<div class="olama-report-info-row">
				<span class="olama-report-info-label">الاسم</span>
				<span class="olama-report-info-value"><?php echo $sponsor_name ?: '—'; ?></span>
			</div>
		</div>
	</section>

	<!-- Students -->
	<?php if ( $students ) : ?>
	<section class="olama-report-section">
		<h2 class="olama-report-section__title">الطلاب المسجلون</h2>
		<ul class="olama-report-students-list">
			<?php foreach ( $students as $student ) :
				if ( is_array( $student ) ) {
					$s_name    = esc_html( $student['name'] ?? $student['student_name'] ?? '' );
					$s_class   = esc_html( $student['class_name']   ?? '' );
					$s_section = esc_html( $student['section_name'] ?? '' );
					$s_year    = esc_html( $student['study_year']   ?? '' );
				} else {
					$s_name    = esc_html( $student );
					$s_class   = '';
					$s_section = '';
					$s_year    = '';
				}
			?>
			<li class="olama-report-student-item">
				<span class="olama-report-student-name"><?php echo $s_name; ?></span>
				<?php if ( $s_class || $s_section ) : ?>
					<span class="olama-report-student-meta">
						<?php echo $s_class; ?><?php echo $s_section ? ' — ' . $s_section : ''; ?>
					</span>
				<?php endif; ?>
			</li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php endif; ?>

	<!-- Balance -->
	<section class="olama-report-section olama-report-section--balance">
		<h2 class="olama-report-section__title">الرصيد المالي</h2>

		<?php if ( ! $fin_ok ) : ?>
			<!-- Financial data unavailable: show Arabic notice, NOT zeros -->
			<div class="olama-report-financial-unavailable">
				<div class="olama-report-fin-icon">⚠️</div>
				<p class="olama-report-fin-msg">
					ولي الأمر المحترم،<br>
					تم إنشاء رابط المطالبة بنجاح، ولكن تفاصيل الرصيد المالي غير متوفرة حالياً.<br>
					يرجى التواصل مع إدارة المدرسة لمعرفة الرصيد المطلوب.
				</p>
			</div>
		<?php else : ?>
			<div class="olama-report-balance-grid">
				<div class="olama-report-balance-card">
					<div class="olama-report-balance-card__label">
						الرصيد الحالي
						<?php if ( $balance_status_text ) : ?>
							<span class="olama-report-balance-status-text" style="font-size: 0.85em; opacity: 0.85; margin-right: 4px;">(<?php echo esc_html( $balance_status_text ); ?>)</span>
						<?php endif; ?>
					</div>
					<?php if ( $balance !== null ) : ?>
						<div class="olama-report-balance-card__amount <?php echo ( $raw_balance !== null && (float) $raw_balance > 0 ) ? 'olama-report-balance-card__amount--due' : ''; ?>">
							<?php echo esc_html( $balance ); ?> <small><?php echo $currency; ?></small>
						</div>
					<?php else : ?>
						<div class="olama-report-balance-card__unavailable">
							<?php echo esc_html( Olama_Messages_Core_Provider::FINANCIAL_ARABIC_NOTICE ); ?>
						</div>
					<?php endif; ?>
				</div>
				<div class="olama-report-balance-card">
					<div class="olama-report-balance-card__label">القسط الشهري المطلوب</div>
					<?php if ( $monthly_due !== null ) : ?>
						<div class="olama-report-balance-card__amount">
							<?php echo esc_html( $monthly_due ); ?> <small><?php echo $currency; ?></small>
						</div>
					<?php else : ?>
						<div class="olama-report-balance-card__unavailable">
							<?php echo esc_html( Olama_Messages_Core_Provider::FINANCIAL_ARABIC_NOTICE ); ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	</section>

	<!-- Due Items -->
	<?php if ( $due_items ) : ?>
	<section class="olama-report-section">
		<h2 class="olama-report-section__title">تفاصيل المستحقات</h2>
		<table class="olama-report-table">
			<thead>
				<tr>
					<th>التاريخ</th>
					<th>البيان</th>
					<th>المبلغ</th>
					<th>الحالة</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $due_items as $item ) : ?>
				<?php
					$item_date = $item['date'] ?? ( $item['payment_date'] ?? ( $item['installment_date'] ?? ( $item['due_date'] ?? '' ) ) );
				?>
				<tr>
					<td><?php echo esc_html( $item_date ?: '—' ); ?></td>
					<td><?php echo esc_html( $item['title'] ?? ( $item['description'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( number_format( (float) ( $item['amount'] ?? 0 ), 3 ) ); ?> <?php echo $currency; ?></td>
					<td><?php echo esc_html( $item['status'] ?? '' ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php endif; ?>

	<!-- Last Payment -->
	<?php if ( $last_pmt ) : ?>
	<section class="olama-report-section">
		<h2 class="olama-report-section__title">آخر دفعة</h2>
		<div class="olama-report-info-grid">
			<?php $lp = $last_pmt; ?>
			<?php if ( isset( $lp['date'] ) ) : ?>
			<div class="olama-report-info-row">
				<span class="olama-report-info-label">التاريخ</span>
				<span class="olama-report-info-value"><?php echo esc_html( $lp['date'] ); ?></span>
			</div>
			<?php endif; ?>
			<?php if ( isset( $lp['amount'] ) ) : ?>
			<div class="olama-report-info-row">
				<span class="olama-report-info-label">المبلغ</span>
				<span class="olama-report-info-value"><?php echo esc_html( number_format( (float) $lp['amount'], 3 ) ); ?> <?php echo $currency; ?></span>
			</div>
			<?php endif; ?>
		</div>
	</section>
	<?php endif; ?>

	<!-- Payment Instructions -->
	<?php if ( $payment_instructions ) : ?>
	<section class="olama-report-section olama-report-section--instructions">
		<h2 class="olama-report-section__title">تعليمات الدفع</h2>
		<div class="olama-report-instructions"><?php echo $payment_instructions; ?></div>
	</section>
	<?php endif; ?>

	<!-- Contact -->
	<?php if ( $contact_phone ) : ?>
	<section class="olama-report-section">
		<h2 class="olama-report-section__title">للتواصل والاستفسار</h2>
		<div class="olama-report-contact">
			<a href="tel:<?php echo esc_attr( $contact_phone ); ?>" class="olama-report-phone-link">
				📞 <?php echo $contact_phone; ?>
			</a>
		</div>
	</section>
	<?php endif; ?>

	<!-- Footer -->
	<footer class="olama-report-footer">
		<button onclick="window.print()" class="olama-report-print-btn" id="olama-report-print-btn">
			🖨 طباعة الكشف
		</button>
		<p class="olama-report-footer-note"><?php echo $school_name; ?></p>
	</footer>

</div><!-- /.olama-report-container -->

</body>
</html>
		<?php
	}

	/** Render the "invalid token" page. */
	private function render_invalid() {
		$school_name = esc_html( get_option( 'olama_msg_school_name', 'أكاديمية علماء المستقبل' ) );
		$contact     = esc_html( get_option( 'olama_msg_contact_phone', '' ) );

		status_header( 404 );
		?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo $school_name; ?> — رابط غير صالح</title>
	<link rel="stylesheet" href="<?php echo esc_url( OLAMA_MSG_URL . 'assets/public-report.css' ); ?>?v=<?php echo esc_attr( OLAMA_MSG_VERSION ); ?>">
</head>
<body class="olama-report-body olama-report-body--invalid">
<div class="olama-report-container olama-report-container--invalid">
	<div class="olama-report-invalid-box">
		<div class="olama-report-invalid-icon">⚠</div>
		<h1 class="olama-report-invalid-title">الرابط غير صالح أو منتهي الصلاحية.</h1>
		<p class="olama-report-invalid-msg">يرجى التواصل مع إدارة المدرسة.</p>
		<?php if ( $contact ) : ?>
			<a href="tel:<?php echo esc_attr( $contact ); ?>" class="olama-report-phone-link olama-report-phone-link--large">
				📞 <?php echo $contact; ?>
			</a>
		<?php endif; ?>
		<p class="olama-report-school-footer"><?php echo $school_name; ?></p>
	</div>
</div>
</body>
</html>
		<?php
	}
}
