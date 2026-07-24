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

		$contacts = $this->contacts_for_year( $primary_year );
		$by_id    = array();

		foreach ( $contacts as $contact ) {
			$by_id[ $contact['family_id'] ] = $contact;
		}

		if ( '' !== $merge_year && $merge_year !== $primary_year ) {
			foreach ( $this->contacts_for_year( $merge_year ) as $older_contact ) {
				$family_id = $older_contact['family_id'];
				if ( ! isset( $by_id[ $family_id ] ) ) {
					$by_id[ $family_id ] = $older_contact;
					continue;
				}

				// Preserve the current-year name and students, but recover a
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
			$row[30] = (string) $contact['mother_mobile'];
			$row[31] = '' !== $contact['father_mobile'] ? 'Mobile' : '';
			$row[32] = (string) $contact['father_mobile'];
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

	private function contacts_for_year( $study_year ) {
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

			$contacts[] = array(
				'family_id'     => $family_id,
				'name'          => implode( ' ', $parts ),
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
			$result = $this->provider->get_recipients_preview(
				array(
					'target_type' => 'general',
					'study_year'  => $study_year,
					'limit'       => $limit,
					'offset'      => $offset,
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

	private function clean_text( $value ) {
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
	}
}
