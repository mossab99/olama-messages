<?php
/**
 * Build Google Contacts CSV exports from the Olama Core phone book.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Phone_Book_Exporter {

	/**
	 * Google Contacts CSV columns, kept in the same order as Google's template.
	 */
	const HEADERS = array(
		'Name',
		'Given Name',
		'Additional Name',
		'Family Name',
		'Yomi Name',
		'Given Name Yomi',
		'Additional Name Yomi',
		'Family Name Yomi',
		'Name Prefix',
		'Name Suffix',
		'Initials',
		'Nickname',
		'Short Name',
		'Maiden Name',
		'Birthday',
		'Gender',
		'Location',
		'Billing Information',
		'Directory Server',
		'Mileage',
		'Occupation',
		'Hobby',
		'Sensitivity',
		'Priority',
		'Subject',
		'Notes',
		'Language',
		'Photo',
		'Group Membership',
		'Phone 1 - Type',
		'Phone 1 - Value',
		'Phone 2 - Type',
		'Phone 2 - Value',
		'Organization 1 - Type',
		'Organization 1 - Name',
		'Organization 1 - Yomi Name',
		'Organization 1 - Title',
		'Organization 1 - Department',
		'Organization 1 - Symbol',
		'Organization 1 - Location',
		'Organization 1 - Job Description',
	);

	/** @var Olama_Messages_Core_Provider */
	private $provider;

	/** @var Olama_Messages_Transportation_Service */
	private $transportation;

	public function __construct( $provider, $transportation ) {
		$this->provider       = $provider;
		$this->transportation = $transportation;
	}

	/**
	 * Build contacts for one year, optionally adding families found only in a
	 * second year. The primary (newer) year's data wins on duplicate family IDs.
	 */
	public function build_contacts( $primary_year, $merge_year = '' ) {
		$primary_year = trim( (string) $primary_year );
		$merge_year   = trim( (string) $merge_year );

		if ( '' === $primary_year ) {
			throw new InvalidArgumentException( 'A primary study year is required.' );
		}

		$contacts = $this->contacts_for_year( $primary_year, "\u{062C}\u{062F}\u{064A}\u{062F}", false );
		$by_id    = array();

		foreach ( $contacts as $contact ) {
			$by_id[ $contact['family_id'] ] = $contact;
		}

		if ( '' !== $merge_year && $merge_year !== $primary_year ) {
			foreach ( $this->contacts_for_year( $merge_year, "\u{0642}\u{062F}\u{064A}\u{0645}", false ) as $older_contact ) {
				$family_id = $older_contact['family_id'];
				if ( ! isset( $by_id[ $family_id ] ) ) {
					$by_id[ $family_id ] = $older_contact;
					continue;
				}

				// Preserve the current-year name, but recover a
				// missing parent phone from the previous year's family record.
				foreach ( array( 'mother_mobile', 'father_mobile' ) as $field ) {
					if ( '' === $by_id[ $family_id ][ $field ] && '' !== $older_contact[ $field ] ) {
						$by_id[ $family_id ][ $field ] = $older_contact[ $field ];
					}
				}
			}
		}

		$contacts = array_values( $by_id );
		usort(
			$contacts,
			static function ( $left, $right ) {
				return strnatcasecmp( (string) $left['family_id'], (string) $right['family_id'] );
			}
		);

		return $contacts;
	}

	/**
	 * Build the current-year Active School Google Contacts list. Unlike the
	 * legacy export, this deliberately does not prefix names with a year or
	 * merge another year's families.
	 */
	public function build_active_school_contacts( $study_year ) {
		return $this->contacts_for_year( trim( (string) $study_year ), '', true );
	}

	/**
	 * Return student rows grouped by grade and section for the WhatsApp book.
	 */
	public function build_active_school_grade_sections( $study_year ) {
		$groups = array();
		foreach ( $this->families_for_year( trim( (string) $study_year ) ) as $family ) {
			$mother = $this->phone( $family['mother_mobile'] ?? '' );
			foreach ( (array) ( $family['student_rows'] ?? array() ) as $student ) {
				if ( ! is_array( $student ) ) {
					continue;
				}
				$name    = $this->clean_text( $student['student_name'] ?? $student['name'] ?? '' );
				$grade   = $this->academic_label( $student['class_name'] ?? '', array( 'الصف', 'صف' ) );
				$section = $this->academic_label( $student['section_name'] ?? '', array( 'الشعبة', 'شعبة' ) );
				$grade   = preg_replace( '/\s+(?:أ|ا)?ساسي$/u', '', $grade );
				if ( '' === $name || '' === $grade ) {
					continue;
				}
				$key = $grade . "\x00" . $section;
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'grade'   => $grade,
						'section' => $section,
						'rows'    => array(),
					);
				}
				$groups[ $key ]['rows'][] = array( $name, $grade, $mother );
			}
		}

		usort(
			$groups,
			static function ( $left, $right ) {
				return strnatcasecmp( $left['grade'] . ' ' . $left['section'], $right['grade'] . ' ' . $right['section'] );
			}
		);
		return array_values( $groups );
	}

	/**
	 * Convert built contacts to a UTF-8 Google Contacts CSV.
	 */
	public function to_csv( array $contacts ) {
		$stream = fopen( 'php://temp', 'w+' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Could not create the CSV stream.' );
		}

		fputcsv( $stream, self::HEADERS, ',', '"', '\\' );
		foreach ( $contacts as $contact ) {
			$row = array_fill( 0, count( self::HEADERS ), '' );
			$row[0]  = (string) $contact['name'];
			$row[29] = '' !== $contact['mother_mobile'] ? 'Mobile' : '';
			$row[30] = $this->spreadsheet_phone( $contact['mother_mobile'] );
			$row[31] = '' !== $contact['father_mobile'] ? 'Mobile' : '';
			$row[32] = $this->spreadsheet_phone( $contact['father_mobile'] );
			fputcsv( $stream, $row, ',', '"', '\\' );
		}

		rewind( $stream );
		$csv = stream_get_contents( $stream );
		fclose( $stream );

		if ( false === $csv ) {
			throw new RuntimeException( 'Could not read the generated CSV.' );
		}

		return $csv;
	}

	/**
	 * Create a small, dependency-free XLSX workbook with one sheet per
	 * grade/section. Phones are written as strings so leading zeroes survive.
	 */
	public function to_xlsx( array $groups ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( 'The PHP Zip extension is required for Excel exports.' );
		}
		$files = array(
			'[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $this->sheet_content_types( count( $groups ) ) . '</Types>',
			'_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
			'xl/_rels/workbook.xml.rels' => $this->workbook_relationships( count( $groups ) ),
		);
		$sheet_names = array();
		$used_names  = array();
		foreach ( $groups as $index => $group ) {
			$name = $this->sheet_name( $group['grade'] . ( '' !== $group['section'] ? ' - ' . $group['section'] : '' ), $used_names );
			$sheet_names[] = $name;
			$files[ 'xl/worksheets/sheet' . ( $index + 1 ) . '.xml' ] = $this->worksheet_xml( $group );
		}
		$files['xl/workbook.xml'] = $this->workbook_xml( $sheet_names );

		$path = wp_tempnam( 'olama-active-school-' );
		$zip  = new ZipArchive();
		if ( ! $path || true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Could not create the Excel workbook.' );
		}
		foreach ( $files as $name => $content ) {
			$zip->addFromString( $name, $content );
		}
		$zip->close();
		$data = file_get_contents( $path );
		@unlink( $path );
		if ( false === $data ) {
			throw new RuntimeException( 'Could not read the generated Excel workbook.' );
		}
		return $data;
	}

	private function worksheet_xml( array $group ) {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView rightToLeft="1"/></sheetViews><cols><col min="1" max="1" width="34" customWidth="1"/><col min="2" max="2" width="16" customWidth="1"/><col min="3" max="3" width="20" customWidth="1"/></cols><sheetData>';
		$xml .= '<row r="1"><c r="A1" t="inlineStr"><is><t>اسم الطالب الكامل</t></is></c><c r="B1" t="inlineStr"><is><t>الصف</t></is></c><c r="C1" t="inlineStr"><is><t>رقم هاتف الأم</t></is></c></row>';
		foreach ( $group['rows'] as $row_index => $row ) {
			$r = $row_index + 2;
			$xml .= '<row r="' . $r . '">';
			foreach ( $row as $column => $value ) {
				$cell = chr( 65 + $column ) . $r;
				$xml .= '<c r="' . $cell . '" t="inlineStr"><is><t>' . $this->xml_text( $value ) . '</t></is></c>';
			}
			$xml .= '</row>';
		}
		return $xml . '</sheetData><autoFilter ref="A1:C' . max( 1, count( $group['rows'] ) + 1 ) . '"/></worksheet>';
	}

	private function workbook_xml( array $sheet_names ) {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
		foreach ( $sheet_names as $index => $name ) {
			$xml .= '<sheet name="' . $this->xml_text( $name ) . '" sheetId="' . ( $index + 1 ) . '" r:id="rId' . ( $index + 1 ) . '"/>';
		}
		return $xml . '</sheets></workbook>';
	}

	private function workbook_relationships( $count ) {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		for ( $i = 1; $i <= $count; $i++ ) {
			$xml .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
		}
		return $xml . '</Relationships>';
	}

	private function sheet_content_types( $count ) {
		$xml = '';
		for ( $i = 1; $i <= $count; $i++ ) {
			$xml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		return $xml;
	}

	private function sheet_name( $name, array &$used ) {
		$name = preg_replace( '~[\\\\/:*?\\[\\]]~u', '-', $this->clean_text( $name ) );
		$name = trim( (string) $name, " .'" );
		$name = '' !== $name ? $name : 'Grade';
		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 31, 'UTF-8' ) : substr( $name, 0, 31 );
		$base = $name;
		$suffix = 2;
		while ( in_array( $name, $used, true ) ) {
			$tail = ' (' . $suffix++ . ')';
			$name = ( function_exists( 'mb_substr' ) ? mb_substr( $base, 0, 31 - mb_strlen( $tail, 'UTF-8' ), 'UTF-8' ) : substr( $base, 0, 31 - strlen( $tail ) ) ) . $tail;
		}
		$used[] = $name;
		return $name;
	}

	private function xml_text( $value ) {
		return htmlspecialchars( (string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
	}

	private function contacts_for_year( $study_year, $year_label, $include_school_details = false ) {
		$families       = $this->families_for_year( $study_year );
		$transportation = $this->transportation_for_year( $study_year );
		$contacts       = array();

		foreach ( $families as $family ) {
			$family_id = trim( (string) ( $family['family_id'] ?? $family['oracle_family_id'] ?? '' ) );
			if ( '' === $family_id ) {
				continue;
			}

			$students = array();
			foreach ( (array) ( $family['student_rows'] ?? array() ) as $student ) {
				$student_text = $this->student_text( $student );
				if ( '' !== $student_text ) {
					$students[] = $student_text;
				}
			}

			$sponsor = $this->clean_text( $family['father_name'] ?? '' );
			if ( '' === $sponsor ) {
				$sponsor = $this->clean_text( $family['sponsor_name'] ?? '' );
			}
			$parts   = array_filter(
				array_merge(
					array( 'عائلة', $family_id, $sponsor ),
					$students,
					array( $this->transport_text( $transportation[ $family_id ] ?? array() ) )
				),
				static function ( $value ) {
					return '' !== $value;
				}
			);

			$name_parts = $include_school_details ? array_merge( array( $year_label ), $parts ) : array( $year_label, 'عائلة', $family_id, $sponsor );
			$contacts[] = array(
				'family_id'     => $family_id,
				'name'          => implode( ' ', array_filter( $name_parts )),
				'mother_mobile' => $this->phone( $family['mother_mobile'] ?? '' ),
				'father_mobile' => $this->phone( $family['father_mobile'] ?? '' ),
				'study_year'    => $study_year,
			);
		}

		return $contacts;
	}

	private function families_for_year( $study_year ) {
		$items  = array();
		$offset = 0;
		$limit  = 200;

		do {
			$result = $this->provider->get_phone_book(
				$study_year,
				array(
					'limit'  => $limit,
					'offset' => $offset,
				)
			);

			if ( 'olama_core' !== ( $result['data_source'] ?? '' ) ) {
				throw new RuntimeException( 'Olama Core phone-book data is unavailable.' );
			}

			$page = array_values( (array) ( $result['items'] ?? array() ) );
			$items = array_merge( $items, $page );
			$total = (int) ( $result['total'] ?? count( $items ) );
			$offset += count( $page );
		} while ( $page && $offset < $total );

		return $items;
	}

	private function transportation_for_year( $study_year ) {
		if ( method_exists( $this->transportation, 'is_available' ) && ! $this->transportation->is_available() ) {
			throw new RuntimeException( 'Olama Core transportation data is unavailable.' );
		}

		$by_family = array();
		$offset    = 0;
		$limit     = 200;

		do {
			$result = $this->transportation->get_bulk_recipients(
				$study_year,
				array(
					'limit'  => $limit,
					'offset' => $offset,
				)
			);
			if ( false === $result || ! is_array( $result ) ) {
				throw new RuntimeException( 'Olama Core transportation data is unavailable.' );
			}

			$page = array_values( (array) ( $result['recipients'] ?? array() ) );
			foreach ( $page as $family ) {
				$family_id = trim( (string) ( $family['family_id'] ?? $family['oracle_family_id'] ?? '' ) );
				if ( '' !== $family_id ) {
					$by_family[ $family_id ] = array_values( (array) ( $family['matching_students'] ?? array() ) );
				}
			}

			$total = (int) ( $result['count'] ?? count( $by_family ) );
			$offset += count( $page );
		} while ( $page && $offset < $total );

		return $by_family;
	}

	private function student_text( $student ) {
		if ( ! is_array( $student ) ) {
			return '';
		}

		$name = $this->first_name( $student['student_name'] ?? $student['name'] ?? '' );
		if ( '' === $name ) {
			return '';
		}

		$class   = $this->academic_label( $student['class_name'] ?? '', array( 'الصف', 'صف' ) );
		$class   = preg_replace( '/\s+(?:أ|ا)?ساسي$/u', '', $class );
		$section = $this->academic_label( $student['section_name'] ?? '', array( 'الشعبة', 'شعبة' ) );

		return implode( ' ', array_filter( array( $name, $class, $section ) ) );
	}

	private function transport_text( array $rows ) {
		$morning = '';
		$evening = '';

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$row_morning = $this->bus_label(
				$row['arrival_bus_name'] ?? '',
				$row['arrival_bus'] ?? ''
			);
			$row_evening = $this->bus_label(
				$row['departure_bus_name'] ?? '',
				$row['departure_bus'] ?? ''
			);
			if ( '' !== $row_morning || '' !== $row_evening ) {
				$morning = $row_morning;
				$evening = $row_evening;
				break;
			}
		}

		if ( '' === $morning && '' === $evening ) {
			return 'مشي';
		}

		// A single synchronized bus is enough for the family; use it for the
		// missing session, which matches the school's usual routing convention.
		$morning = '' !== $morning ? $morning : $evening;
		$evening = '' !== $evening ? $evening : $morning;

		return 'نقل ' . $morning . ' ' . $evening;
	}

	private function bus_label( $name, $id = '' ) {
		$name = $this->clean_text( $name );
		$id   = $this->clean_text( $id );

		foreach ( array( $name, $id ) as $value ) {
			if ( preg_match( '/[0-9٠-٩]+/u', $value, $matches ) ) {
				if ( '' !== trim( $matches[0], '0٠' ) ) {
					return $matches[0];
				}
			}
		}

		$unknown = array( '', '-', '0', '٠', 'غير محدد', 'غير معروف', 'لا يوجد' );
		if ( ! in_array( $id, $unknown, true ) ) {
			return $id;
		}
		if ( ! in_array( $name, $unknown, true ) ) {
			return preg_replace( '/^(?:ال)?(?:باص|حافلة)\s*(?:رقم)?\s*/u', '', $name );
		}
		return '';
	}

	private function first_name( $value ) {
		$value = $this->clean_text( $value );
		if ( '' === $value ) {
			return '';
		}

		$parts = preg_split( '/\s+/u', $value );
		return (string) ( $parts[0] ?? '' );
	}

	private function academic_label( $value, array $prefixes ) {
		$value = $this->clean_text( $value );
		foreach ( $prefixes as $prefix ) {
			$value = preg_replace( '/^' . preg_quote( $prefix, '/' ) . '\s+/u', '', $value );
		}
		return trim( (string) $value );
	}

	private function phone( $value ) {
		return preg_replace( '/[^0-9+]/', '', (string) $value );
	}

	/**
	 * Keep local numbers as text when the Google Contacts CSV is opened in
	 * Google Sheets, which otherwise removes a leading zero from numeric cells.
	 */
	private function spreadsheet_phone( $value ) {
		$value = $this->phone( $value );
		return '' === $value ? '' : '="' . $value . '"';
	}

	private function clean_text( $value ) {
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
	}
}
