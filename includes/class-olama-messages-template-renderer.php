<?php
/**
 * SMS / message template renderer.
 *
 * Renders Arabic message templates with variable substitution and
 * validates placeholders and calculates GSM-7 or Unicode SMS parts.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Template_Renderer {

	const UNICODE_SINGLE_PART = 70;
	const UNICODE_MULTIPART_PART = 67;
	const GSM7_SINGLE_PART = 160;
	const GSM7_MULTIPART_PART = 153;

	/** GSM 03.38 default and extension tables. */
	const GSM7_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\x1BÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
	const GSM7_EXTENSION = "^{}\\[~]|€";

	/** Supported operator-facing placeholders. */
	const SUPPORTED_PLACEHOLDERS = 'sponsor_name,family_id,students,balance,monthly_due,monthly_due_source,payment_link,study_year,school_name,contact_phone';

	/** Default Arabic payment-reminder template (used when financial data IS available). */
	const DEFAULT_TEMPLATE = "ولي الأمر المحترم،\nنذكركم بوجود رصيد مستحق بقيمة {balance} د.أ.\nيمكنكم مراجعة تفاصيل المطالبة من الرابط التالي:\n{payment_link}\nأكاديمية علماء المستقبل";

	/**
	 * Alternate template used during Phase 1 when financial data is NOT available.
	 * Must NOT include {balance} or {monthly_due} placeholders since those values
	 * are null/unknown and must not be shown to parents as zeros.
	 */
	const DEFAULT_TEMPLATE_NO_FINANCE = "ولي الأمر المحترم،\nيمكنكم مراجعة بيانات الطالب/الأسرة من الرابط التالي:\n{payment_link}\nللاستفسار عن الرصيد المالي يرجى التواصل مع إدارة المدرسة.\nأكاديمية علماء المستقبل";

	// ─── Render ──────────────────────────────────────────────────────────────

	/**
	 * Render a template string with the provided variables.
	 *
	 * Supported placeholders:
	 *   {sponsor_name}  {family_id}  {students}  {balance}
	 *   {monthly_due}   {payment_link}  {study_year}  {school_name}
	 *
	 * IMPORTANT: balance and monthly_due default to 'غير متوفر' (unavailable),
	 * NOT '0'. Zero means "no debt" which is factually wrong when financial data
	 * is absent. Callers must only pass real numeric values when financial_available=true.
	 *
	 * @param  string $template   Raw template with {placeholder} tokens.
	 * @param  array  $vars       Associative array of variable values.
	 * @return string             Rendered text.
	 */
	public function render_sms( $template, array $vars = array() ) {
		$defaults = array(
			'sponsor_name' => '',
			'family_id'    => '',
			'students'     => '',
			// 'غير متوفر' = unavailable. Never default to '0' (means no debt).
			'balance'            => 'غير متوفر',
			'monthly_due'        => 'غير متوفر',
			'monthly_due_source' => 'غير متوفر',
			'payment_link'       => '',
			'study_year'   => '',
			'school_name'  => get_option( 'olama_msg_school_name', 'أكاديمية علماء المستقبل' ),
		);

		$vars = array_merge( $defaults, $vars );

		// If students is an array, join it.
		if ( is_array( $vars['students'] ) ) {
			$vars['students'] = implode( '، ', $vars['students'] );
		}

		$search  = array();
		$replace = array();
		foreach ( $vars as $key => $value ) {
			$search[]  = '{' . $key . '}';
			$replace[] = (string) $value;
		}

		return str_replace( $search, $replace, $template );
	}

	/**
	 * Render a template for a campaign preview/queue, replacing {payment_link}
	 * with the safe placeholder {{PAYMENT_LINK}} instead of a real URL.
	 *
	 * @param  string $template   Raw template.
	 * @param  array  $vars       Variables (excluding payment_link, as it will be overwritten).
	 * @return string             Rendered text with {{PAYMENT_LINK}}.
	 */
	public function render_campaign_sms( $template, array $vars = array() ) {
		$vars['payment_link'] = '{{PAYMENT_LINK}}';
		return $this->render_sms( $template, $vars );
	}

	/**
	 * Check if a template string contains financial placeholders.
	 *
	 * Used by the admin to show a warning when financial_available=false but
	 * the custom template still includes {balance} or {monthly_due}.
	 *
	 * @param  string $template
	 * @return bool
	 */
	public function template_has_financial_vars( $template ) {
		return strpos( $template, '{balance}' ) !== false
			|| strpos( $template, '{monthly_due}' ) !== false;
	}

	/**
	 * Return unsupported placeholders found in a message body.
	 */
	public function unknown_placeholders( $template ) {
		preg_match_all( '/\{([a-zA-Z0-9_]+)\}/', (string) $template, $matches );
		$supported = explode( ',', self::SUPPORTED_PLACEHOLDERS );
		$unknown = array();
		foreach ( array_unique( $matches[1] ?? array() ) as $placeholder ) {
			if ( ! in_array( $placeholder, $supported, true ) ) {
				$unknown[] = $placeholder;
			}
		}
		return $unknown;
	}

	public function validate_placeholders( $template ) {
		$unknown = $this->unknown_placeholders( $template );
		return array(
			'valid'   => empty( $unknown ),
			'unknown' => $unknown,
		);
	}


	/**
	 * Count the number of Unicode SMS parts for a given text.
	 *
	 * Arabic uses UCS-2: 70 characters per part.
	 *
	 * @param  string $text
	 * @return int
	 */
	public function count_sms_parts( $text ) {
		$info = $this->sms_info( $text );
		return $info['sms_parts'];
	}

	/**
	 * Return character count and estimated parts.
	 *
	 * @param  string $text
	 * @return array { char_count: int, sms_parts: int, chars_per_part: int }
	 */
	public function sms_info( $text ) {
		$char_count = (int) mb_strlen( $text, 'UTF-8' );
		$encoding = 'gsm7';
		$encoded_units = 0;

		foreach ( preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $character ) {
			if ( false !== mb_strpos( self::GSM7_BASIC, $character, 0, 'UTF-8' ) ) {
				$encoded_units++;
			} elseif ( false !== mb_strpos( self::GSM7_EXTENSION, $character, 0, 'UTF-8' ) ) {
				$encoded_units += 2;
			} else {
				$encoding = 'unicode';
				$encoded_units = $char_count;
				break;
			}
		}

		$single_limit = 'gsm7' === $encoding ? self::GSM7_SINGLE_PART : self::UNICODE_SINGLE_PART;
		$multipart_limit = 'gsm7' === $encoding ? self::GSM7_MULTIPART_PART : self::UNICODE_MULTIPART_PART;
		$sms_parts = 0;
		if ( $encoded_units > 0 ) {
			$sms_parts = $encoded_units <= $single_limit ? 1 : (int) ceil( $encoded_units / $multipart_limit );
		}

		return array(
			'encoding'          => $encoding,
			'char_count'        => $char_count,
			'encoded_units'     => $encoded_units,
			'sms_parts'         => $sms_parts,
			'single_part_limit' => $single_limit,
			'multipart_limit'   => $multipart_limit,
			'chars_per_part'    => $encoded_units <= $single_limit ? $single_limit : $multipart_limit,
		);
	}

	/**
	 * Return the default Arabic template.
	 *
	 * @return string
	 */
	public function get_default_template() {
		return self::DEFAULT_TEMPLATE;
	}

	/**
	 * Return the saved custom template or the appropriate default.
	 *
	 * @param  bool $financial_available  Pass false (Phase 1 default) to get
	 *                                    the no-finance alternate template.
	 * @return string
	 */
	public function get_active_template( $financial_available = false ) {
		$saved = get_option( 'olama_msg_sms_template', '' );
		if ( $saved ) {
			return $saved;
		}
		// When financial data is unavailable, use the safe alternate template
		// that does not include {balance} or {monthly_due}.
		return $financial_available ? self::DEFAULT_TEMPLATE : self::DEFAULT_TEMPLATE_NO_FINANCE;
	}
}
