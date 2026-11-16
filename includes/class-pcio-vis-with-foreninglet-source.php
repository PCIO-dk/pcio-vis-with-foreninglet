<?php
/**
 * ForeningLet API client and member normalization.
 *
 * @package PCIO_VIS_With_ForeningLet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Fetches and validates the complete ForeningLet membership list. */
class PCIO_VIS_With_ForeningLet_Source {
	const OPT_HOST     = 'pcio_vis_foreninglet_host';
	const OPT_USERPWD  = 'pcio_vis_foreninglet_userpwd';
	const DEFAULT_HOST = 'https://foreninglet.dk/api/members?version=1';

	/**
	 * Validate the complete-list endpoint before transmitting credentials.
	 *
	 * @param string $host API URL.
	 * @return bool
	 */
	public static function valid_host( string $host ): bool {
		$parts = wp_parse_url( $host );
		return is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? '' )
			&& 'foreninglet.dk' === strtolower( $parts['host'] ?? '' )
			&& '/api/members' === rtrim( $parts['path'] ?? '', '/' )
			&& 'version=1' === ( $parts['query'] ?? '' )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ! isset( $parts['fragment'] )
			&& ( ! isset( $parts['port'] ) || 443 === $parts['port'] );
	}

	/**
	 * Validate HTTP Basic authentication without altering the password.
	 *
	 * @param string $userpwd Username and password separated by a colon.
	 * @return bool
	 */
	public static function valid_userpwd( string $userpwd ): bool {
		$parts = explode( ':', $userpwd, 2 );
		return 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1]
			&& 0 === preg_match( '/[\x00-\x1f\x7f]/', $userpwd );
	}

	/**
	 * Fetch and validate the entire response before any membership changes.
	 *
	 * @return array|\WP_Error Normalized members, indexed by member number, or an error.
	 */
	public static function fetch() {
		$host    = (string) get_option( self::OPT_HOST, self::DEFAULT_HOST );
		$userpwd = (string) get_option( self::OPT_USERPWD, '' );
		if ( ! self::valid_host( $host ) || ! self::valid_userpwd( $userpwd ) ) {
			return new WP_Error( 'foreninglet_settings', __( 'Configure a valid ForeningLet host and username:password under Vis > ForeningLet.', 'pcio-vis-with-foreninglet' ) );
		}

		$response = wp_safe_remote_get(
			$host,
			array(
				'timeout'     => 60,
				'redirection' => 0,
				'headers'     => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication requires Base64; no executable code is encoded.
					'Authorization' => 'Basic ' . base64_encode( $userpwd ),
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			// Transport errors can contain the URL; do not persist credentials or response bodies.
			return new WP_Error( 'foreninglet_transport', __( 'Could not connect to ForeningLet. No memberships were revoked.', 'pcio-vis-with-foreninglet' ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error(
				'foreninglet_http',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'ForeningLet returned HTTP %d. No memberships were revoked.', 'pcio-vis-with-foreninglet' ),
					$status
				)
			);
		}

		$rows = json_decode( wp_remote_retrieve_body( $response ) );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $rows ) ) {
			return new WP_Error( 'foreninglet_json', __( 'ForeningLet did not return a complete JSON member array. No memberships were revoked.', 'pcio-vis-with-foreninglet' ) );
		}
		return self::normalize( $rows );
	}

	/**
	 * Deduplicate by member number, retaining separate people with shared emails.
	 *
	 * @param array $rows Decoded API records.
	 * @return array|\WP_Error
	 */
	private static function normalize( array $rows ) {
		$candidates = array();
		foreach ( $rows as $index => $row ) {
			$member = self::normalize_member( $row );
			if ( is_wp_error( $member ) ) {
				$number = is_object( $row ) ? ( get_object_vars( $row )['MemberNumber'] ?? null ) : null;
				$number = is_scalar( $number ) ? trim( (string) $number ) : '';
				$number = ctype_digit( $number ) && strlen( $number ) <= 10
					? $number : __( 'unavailable', 'pcio-vis-with-foreninglet' );
				return new WP_Error(
					'foreninglet_member',
					sprintf(
						/* translators: 1: one-based API record position, 2: member number or "unavailable", 3: field validation failure. */
						__( 'ForeningLet record %1$d (MemberNumber: %2$s): %3$s The sync was cancelled before changing memberships.', 'pcio-vis-with-foreninglet' ),
						$index + 1,
						$number,
						$member->get_error_message()
					)
				);
			}
			$candidates[] = $member;
		}
		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				$balance_order = $a['balance'] <=> $b['balance'];
				if ( 0 !== $balance_order ) {
					return $balance_order;
				}
				$number_order = $a['member_number'] <=> $b['member_number'];
				return 0 !== $number_order ? $number_order : strcmp( $a['email'], $b['email'] );
			}
		);
		$members = array();
		foreach ( $candidates as $member ) {
			if ( isset( $members[ $member['member_number'] ] ) ) {
				continue;
			}
			unset( $member['balance'] );
			$members[ $member['member_number'] ] = $member;
		}
		return $members;
	}

	/**
	 * Map only fields supported by the core member model.
	 *
	 * @param mixed $row Decoded API record.
	 * @return array|\WP_Error
	 */
	private static function normalize_member( $row ) {
		if ( ! is_object( $row ) ) {
			return self::field_error( __( 'Record', 'pcio-vis-with-foreninglet' ), __( 'expected a JSON object.', 'pcio-vis-with-foreninglet' ) );
		}
		foreach ( array( 'MemberId', 'MemberNumber', 'FirstName', 'LastName' ) as $field ) {
			if ( ! property_exists( $row, $field ) ) {
				return self::field_error( $field, __( 'required field is missing.', 'pcio-vis-with-foreninglet' ) );
			}
			if ( ! isset( $row->$field ) || ! is_scalar( $row->$field ) ) {
				return self::field_error( $field, __( 'expected a non-null scalar value.', 'pcio-vis-with-foreninglet' ) );
			}
		}
		foreach ( array( 'Email', 'Phone', 'Mobile', 'EnrollmentDate', 'SaldoPaymentDeadline' ) as $field ) {
			if ( isset( $row->$field ) && ! is_scalar( $row->$field ) ) {
				return self::field_error( $field, __( 'expected a scalar value or null.', 'pcio-vis-with-foreninglet' ) );
			}
		}
		// The API's PascalCase keys are external field names, not local identifiers.
		$fields = get_object_vars( $row );
		$email  = strtolower( trim( (string) ( $fields['Email'] ?? '' ) ) );
		$number = trim( (string) $fields['MemberNumber'] );
		$name   = sanitize_text_field( trim( $fields['FirstName'] . ' ' . $fields['LastName'] ) );
		if ( '' === trim( (string) $fields['MemberId'] ) ) {
			return self::field_error( 'MemberId', __( 'required identifier is empty.', 'pcio-vis-with-foreninglet' ) );
		}
		if ( ! ctype_digit( $number ) || (int) $number < 1 || (int) $number > 2147483647 ) {
			return self::field_error( 'MemberNumber', __( 'expected a whole number between 1 and 2147483647.', 'pcio-vis-with-foreninglet' ) );
		}
		if ( '' === $name ) {
			return self::field_error( 'FirstName/LastName', __( 'combined name is empty after sanitization.', 'pcio-vis-with-foreninglet' ) );
		}
		if ( '' !== $email && ( ! is_email( $email ) || sanitize_email( $email ) !== $email ) ) {
			return self::field_error( 'Email', __( 'expected a valid single email address that does not require sanitization.', 'pcio-vis-with-foreninglet' ) );
		}
		$balance = $fields['SaldoPaymentDeadline'] ?? 0;
		if ( ! is_numeric( $balance ) || ! is_finite( (float) $balance ) ) {
			return self::field_error( 'SaldoPaymentDeadline', __( 'expected a finite numeric balance.', 'pcio-vis-with-foreninglet' ) );
		}
		$phone  = trim( (string) ( $fields['Phone'] ?? '' ) );
		$phone  = '' !== $phone ? $phone : (string) ( $fields['Mobile'] ?? '' );
		$member = array(
			'member_number' => (int) $number,
			'name'          => $name,
			'email'         => $email,
			'phone'         => sanitize_text_field( $phone ),
			'balance'       => (float) $balance,
		);
		$date   = trim( (string) ( $fields['EnrollmentDate'] ?? '' ) );
		if ( '' !== $date ) {
			$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
			if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
				return self::field_error( 'EnrollmentDate', __( 'expected a valid calendar date in YYYY-MM-DD format or an empty value.', 'pcio-vis-with-foreninglet' ) );
			}
			$member['join_date'] = $date;
		}
		return $member;
	}

	/**
	 * Describe a validation failure without disclosing the field's raw value.
	 *
	 * @param string $field API field name.
	 * @param string $reason Translated validation requirement.
	 * @return \WP_Error
	 */
	private static function field_error( string $field, string $reason ): WP_Error {
		return new WP_Error(
			'foreninglet_member',
			sprintf(
				/* translators: 1: API field name, 2: validation requirement. */
				__( '%1$s: %2$s', 'pcio-vis-with-foreninglet' ),
				$field,
				$reason
			)
		);
	}
}
