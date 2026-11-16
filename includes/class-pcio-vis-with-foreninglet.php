<?php
/**
 * ForeningLet member reconciliation using the core repositories.
 *
 * @package PCIO_VIS_With_ForeningLet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reconciles external members while protecting local privileged accounts. */
class PCIO_VIS_With_ForeningLet {
	const OPT_LOCK           = 'pcio_vis_foreninglet_lock';
	const USER_MEMBER_NUMBER = '_pcio_vis_foreninglet_member_number';

	/**
	 * Run the registered sync job and return its persisted status.
	 *
	 * @param array $job Core sync registry row.
	 * @return string
	 */
	public static function run_sync( array $job ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The core executor passes its registry row to every callable.
		$lock = (int) get_option( self::OPT_LOCK, 0 );
		if ( $lock && $lock < time() - HOUR_IN_SECONDS ) {
			delete_option( self::OPT_LOCK );
		}
		if ( ! add_option( self::OPT_LOCK, time(), '', false ) ) {
			return __( 'Error: a ForeningLet sync is already running. Retry later.', 'pcio-vis-with-foreninglet' );
		}
		try {
			$members = PCIO_VIS_With_ForeningLet_Source::fetch();
			if ( is_wp_error( $members ) ) {
				return __( 'Error:', 'pcio-vis-with-foreninglet' ) . ' ' . $members->get_error_message();
			}
			return self::reconcile( $members );
		} finally {
			delete_option( self::OPT_LOCK );
		}
	}

	/**
	 * Whether this WordPress account must never be modified by the sync.
	 *
	 * @param \WP_User|false $user WordPress account.
	 * @return bool
	 */
	private static function is_administrator( $user ): bool {
		return $user && ( in_array( 'administrator', (array) $user->roles, true ) || is_super_admin( (int) $user->ID ) );
	}

	/**
	 * Resolve the complete import plan before writing, rejecting ambiguous links.
	 *
	 * @param array $members External members indexed by member number.
	 * @param array $users WordPress accounts indexed by ID.
	 * @return array|\WP_Error
	 */
	private static function plan( array $members, array $users ) {
		$by_number      = array();
		$by_email       = array();
		$user_ids       = array();
		$imported_users = array();
		foreach ( $users as $uid => $user ) {
			$user_ids[ strtolower( trim( $user->user_email ) ) ][] = $uid;
			$number = (int) get_user_meta( $uid, self::USER_MEMBER_NUMBER, true );
			if ( $number > 0 ) {
				$imported_users[ $number ][] = $uid;
			}
		}
		$linked_users = array();
		foreach ( PCIO_VIS_DB::get_all() as $existing ) {
			$by_number[ (int) $existing['member_number'] ][]        = $existing;
			$by_email[ strtolower( trim( $existing['email'] ) ) ][] = $existing;
			if ( (int) $existing['wp_user_id'] ) {
				$linked_users[ (int) $existing['wp_user_id'] ] = true;
			}
		}
		// Alias accounts already act as a member and must not be claimed as the
		// primary ForeningLet account for a different member.
		foreach ( $users as $uid => $user ) {
			if ( class_exists( 'PCIO_VIS_Member_Aliases_DB' ) && PCIO_VIS_Member_Aliases_DB::get_by_wp_user( (int) $uid ) ) {
				$linked_users[ (int) $uid ] = true;
			}
		}
		$email_counts = array();
		foreach ( $members as $data ) {
			$email_counts[ $data['email'] ] = ( $email_counts[ $data['email'] ] ?? 0 ) + 1;
		}
		$plan          = array();
		$claimed       = array();
		$claimed_users = array();
		foreach ( $members as $data ) {
			$email   = $data['email'];
			$matches = $by_number[ $data['member_number'] ] ?? array();
			// Email is only a migration fallback when it identifies exactly one person.
			if ( ! $matches && '' !== $email && 1 === $email_counts[ $email ] && 1 === count( $by_email[ $email ] ?? array() ) ) {
				$matches = $by_email[ $email ];
			}
			$existing = $matches ? reset( $matches ) : null;
			$uid      = $existing ? (int) $existing['wp_user_id'] : 0;
			if ( count( $matches ) > 1 ) {
				return new WP_Error(
					'foreninglet_conflict',
					sprintf(
						/* translators: 1: ForeningLet member number, 2: comma-separated Vis member record IDs. */
						__( 'Member number %1$d is used by multiple Vis member records (IDs: %2$s). Resolve the duplicate member numbers before syncing. No memberships were changed.', 'pcio-vis-with-foreninglet' ),
						(int) $data['member_number'],
						implode( ', ', array_map( 'intval', array_column( $matches, 'id' ) ) )
					)
				);
			}
			if ( $existing && isset( $claimed[ (int) $existing['id'] ] ) ) {
				return new WP_Error(
					'foreninglet_conflict',
					sprintf(
						/* translators: 1: ForeningLet member number, 2: Vis member record ID, 3: ForeningLet member number that matched the record first. */
						__( 'Member number %1$d matches Vis member record %2$d, which is already matched by ForeningLet member number %3$d. Resolve the conflicting member numbers before syncing. No memberships were changed.', 'pcio-vis-with-foreninglet' ),
						(int) $data['member_number'],
						(int) $existing['id'],
						$claimed[ (int) $existing['id'] ]
					)
				);
			}
			$uid          = isset( $users[ $uid ] ) ? $uid : 0;
			$imported_ids = $imported_users[ $data['member_number'] ] ?? array();
			if ( ! $uid && count( $imported_ids ) > 1 ) {
				return new WP_Error(
					'foreninglet_account_conflict',
					sprintf(
						/* translators: 1: ForeningLet member number, 2: comma-separated WordPress account IDs. */
						__( 'Member number %1$d is stored on multiple WordPress accounts (IDs: %2$s). Resolve the account links before syncing.', 'pcio-vis-with-foreninglet' ),
						(int) $data['member_number'],
						implode( ', ', array_map( 'intval', $imported_ids ) )
					)
				);
			}
			if ( ! $uid && 1 === count( $imported_ids ) ) {
				$uid = $imported_ids[0];
			}
			if ( ! $uid && '' !== $email && 1 === $email_counts[ $email ] && 1 === count( $user_ids[ $email ] ?? array() ) ) {
				$email_id = $user_ids[ $email ][0];
				if ( ! isset( $linked_users[ $email_id ] ) ) {
					$uid = $email_id;
				}
			}
			if ( $uid && isset( $claimed_users[ $uid ] ) ) {
				return new WP_Error(
					'foreninglet_user_conflict',
					sprintf(
						/* translators: 1: ForeningLet member number, 2: WordPress account ID, 3: ForeningLet member number that matched the account first. */
						__( 'Member number %1$d resolves to WordPress account %2$d, which is already matched by ForeningLet member number %3$d. Resolve the member links before syncing. No memberships were changed.', 'pcio-vis-with-foreninglet' ),
						(int) $data['member_number'],
						(int) $uid,
						$claimed_users[ $uid ]
					)
				);
			}
			if ( $uid ) {
				$claimed_users[ $uid ] = (int) $data['member_number'];
			}
			if ( $existing ) {
				$claimed[ (int) $existing['id'] ] = (int) $data['member_number'];
			}
			$plan[] = array(
				'data'     => $data,
				'existing' => $existing,
				'user_id'  => $uid,
			);
		}
		return $plan;
	}

	/**
	 * Import members, then revoke membership from absent unprotected accounts.
	 *
	 * @param array $members Validated external members indexed by member number.
	 * @return string
	 */
	private static function reconcile( array $members ): string {
		$users = array();
		foreach ( get_users( array( 'number' => -1 ) ) as $user ) {
			$users[ (int) $user->ID ] = $user;
		}
		update_meta_cache( 'user', array_keys( $users ) );
		$plan = self::plan( $members, $users );
		if ( is_wp_error( $plan ) ) {
			return __( 'Error:', 'pcio-vis-with-foreninglet' ) . ' ' . $plan->get_error_message();
		}
		$active = array();
		$counts = array(
			'created'   => 0,
			'updated'   => 0,
			'revoked'   => 0,
			'protected' => 0,
			'no_email'  => 0,
		);
		$errors = array();
		foreach ( $plan as $entry ) {
			$uid = $entry['user_id'];
			if ( '' === $entry['data']['email'] ) {
				++$counts['no_email'];
				// Still present in ForeningLet: keep any established membership and link.
				if ( $uid ) {
					$active[ $uid ] = true;
				}
				continue;
			}
			if ( self::is_administrator( $users[ $uid ] ?? false ) ) {
				++$counts['protected'];
				continue;
			}
			$result = self::import_member( $entry );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}
			$active[ $result ] = true;
			++$counts[ $entry['existing'] ? 'updated' : 'created' ];
		}
		// Never revoke against a partially applied import.
		if ( ! $errors ) {
			foreach ( $users as $uid => $user ) {
				if ( isset( $active[ $uid ] ) || ! PCIO_VIS_Caps::is_member( $uid ) ) {
					continue;
				}
				$alias = class_exists( 'PCIO_VIS_Member_Aliases_DB' )
					? PCIO_VIS_Member_Aliases_DB::get_by_wp_user( $uid )
					: null;
				if ( $alias ) {
					$primary = (int) ( PCIO_VIS_Roles_DB::get_member_role( (int) $alias['member_id'] )['wp_user_id'] ?? 0 );
					if ( $primary > 0 && isset( $active[ $primary ] ) ) {
						++$counts['protected'];
						continue;
					}
				}
				if ( self::is_administrator( $user ) || PCIO_VIS_Caps::is_volunteer( $uid ) ) {
					++$counts['protected'];
					continue;
				}
				PCIO_VIS_Caps::revoke_membership( $uid );
				if ( PCIO_VIS_Caps::is_member( $uid ) ) {
					$errors[] = __( 'A WordPress account still has vis_member after revocation. Check its role capabilities.', 'pcio-vis-with-foreninglet' );
				} else {
					++$counts['revoked'];
				}
			}
		}
		$summary = sprintf(
			/* translators: 1: created members, 2: refreshed members, 3: revoked memberships, 4: protected accounts, 5: members skipped because their email is empty. */
			__( 'ForeningLet sync: Created: %1$d | Updated: %2$d | Revoked: %3$d | Protected: %4$d | no email:%5$d', 'pcio-vis-with-foreninglet' ),
			$counts['created'],
			$counts['updated'],
			$counts['revoked'],
			$counts['protected'],
			$counts['no_email']
		);
		if ( $errors ) {
			$summary .= ' | ' . __( 'Errors (import failures prevent revocation):', 'pcio-vis-with-foreninglet' ) . ' ' . implode( '; ', array_unique( $errors ) );
		}
		return $summary;
	}

	/**
	 * Persist contact fields and link an account without replacing its Vis role.
	 *
	 * @param array $entry Planned member import.
	 * @return int|\WP_Error WordPress user ID, or an import error.
	 */
	private static function import_member( array $entry ) {
		$data     = $entry['data'];
		$existing = $entry['existing'];
		$uid      = $entry['user_id'];
		// The core update sanitizer supplies defaults: preserve fields not supplied by the API.
		$values = array_merge( $existing ?? array( 'address' => '' ), $data );
		$id     = $existing ? (int) $existing['id'] : PCIO_VIS_DB::create( $values );
		if ( ! $id || ( $existing && false === PCIO_VIS_DB::update( $id, $values ) ) ) {
			return new WP_Error( 'foreninglet_write', __( 'Could not save a ForeningLet member in Vis.', 'pcio-vis-with-foreninglet' ) );
		}
		$role = (string) ( $existing['vis_role'] ?? '' );
		$role = '' !== $role ? $role : 'member';
		if ( ! $uid ) {
			$uid = self::create_account( $data );
			if ( is_wp_error( $uid ) ) {
				return $uid;
			}
		}
		if ( ! $uid || ! PCIO_VIS_Roles_DB::set_member_role( (int) $id, $role, $uid ) ) {
			return new WP_Error( 'foreninglet_account', __( 'Could not create or link a WordPress account for a ForeningLet member.', 'pcio-vis-with-foreninglet' ) );
		}
		PCIO_VIS_Caps::grant_membership( $uid );
		update_user_meta( $uid, self::USER_MEMBER_NUMBER, $data['member_number'] );
		if ( ! PCIO_VIS_Caps::is_member( $uid ) ) {
			return new WP_Error( 'foreninglet_cap', __( 'Could not grant vis_member to a ForeningLet member.', 'pcio-vis-with-foreninglet' ) );
		}
		return $uid;
	}

	/**
	 * Create a separate subscriber, allowing only this import's shared email.
	 *
	 * @param array $data Validated member data.
	 * @return int|\WP_Error
	 */
	private static function create_account( array $data ) {
		$base  = 'foreninglet_' . $data['member_number'];
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . '_' . ( $i++ );
		}
		$allow_shared_email = static function ( $user_id, string $email ) use ( $data ) {
			return strtolower( $email ) === $data['email'] ? false : $user_id;
		};
		// The core provisioner matches by email and would merge distinct people.
		add_filter( 'email_exists', $allow_shared_email, 10, 2 );
		try {
			$parts = explode( ' ', $data['name'], 2 );
			$uid   = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $data['email'],
					'display_name' => $data['name'],
					'first_name'   => $parts[0],
					'last_name'    => $parts[1] ?? '',
					'role'         => 'subscriber',
					'user_pass'    => wp_generate_password( 24, true, true ),
					'meta_input'   => array( self::USER_MEMBER_NUMBER => $data['member_number'] ),
				)
			);
		} finally {
			remove_filter( 'email_exists', $allow_shared_email, 10 );
		}
		if ( is_wp_error( $uid ) ) {
			return new WP_Error(
				'foreninglet_account',
				sprintf(
					/* translators: 1: member number, 2: WordPress account creation error. */
					__( 'Could not create a WordPress account for member %1$d: %2$s', 'pcio-vis-with-foreninglet' ),
					$data['member_number'],
					$uid->get_error_message()
				)
			);
		}
		return (int) $uid;
	}
}
