=== PCIO VIS With ForeningLet ===
Contributors: pcio
Tags: members, foreninglet, sync, membership
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: pcio-vis-member-event
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Imports ForeningLet members into PCIO VIS Member Event using a configurable cron sync job.

== Description ==

PCIO VIS With ForeningLet extends PCIO VIS Member Event with one member-import job.
Configure the connection under **Vis > ForeningLet > Config**. Manage the job,
its schedule, manual runs and last result under **Vis > Sync Jobs**.

Every member returned by the ForeningLet member-list API is considered valid,
regardless of balance. Duplicate member numbers are resolved by preferring the
lower SaldoPaymentDeadline. Different member numbers remain separate even when
they share an email address.

The importer creates or refreshes core member records using MemberNumber, FirstName,
LastName, Email, Phone (or Mobile) and EnrollmentDate. It matches existing records by
member number first. Email is used only as a fallback when it identifies a single
source member and a single existing record; shared emails never merge people.
Duplicate member numbers in the local database and conflicting account links
cancel the sync before any changes.
It preserves local addresses, volunteer flags, leave dates and existing Vis roles.
Legacy interest, balance and newsletter fields are not copied into unrelated core fields.

New members receive a linked WordPress subscriber account with a generated password
and a username containing their member number (for example, foreninglet_677).
Existing unlinked accounts are matched by email only when that email identifies a
single source member and a single unlinked WordPress account.
Shared-email members without existing links get separate accounts with the real
shared email address. The importer temporarily allows that email only during
account creation; it does not disable WordPress email checks elsewhere.
Members sharing an email must log in and request password resets by username,
since email-based lookup cannot distinguish their accounts. Anyone with access to
that shared mailbox can receive password resets for those accounts.
New accounts receive vis_member. Existing accounts keep their login, password,
profile and WordPress role.
Existing member links are retained when the ForeningLet contact email changes.
No welcome emails are sent.

After a successful import, vis_member is removed from WordPress accounts that are
not represented in the returned list, including accounts not linked to a Vis record.
WordPress administrators and multisite super administrators are never changed,
including their linked member records. Accounts with vis_volunteer never lose vis_member.
No member records or WordPress accounts are deleted.

Failed requests, unexpected payloads, malformed member records, ambiguous matches
and import failures prevent revocation. A successful empty JSON array represents
an empty membership list and revokes all unprotected memberships.

The job is installed disabled with a 24-hour frequency, following the core sync-job
convention. It makes no API calls until explicitly run or enabled after configuration.
WordPress cron requires site traffic or an external scheduler.

Do not enable another membership sync as an alternative source of truth at the same
time: the core Sync WP Users job can re-grant membership to retained former members,
and other integrations may apply different removal rules.

Deactivation disables the registry job and clears its scheduled event, retaining
settings and job history. Re-enable the job after reactivation if required.
Deletion removes this extension's options and its job when the core is active.
Imported records, accounts and membership capabilities are retained.

== Installation ==

1. Install and activate PCIO VIS Member Event.
2. Upload pcio-vis-with-foreninglet to /wp-content/plugins/ and activate it.
3. Open Vis > ForeningLet and enter the host and API credentials as username:password.
4. Save the settings. Saving credentials authorizes requests when the job is run.
5. Under Vis > Sync Jobs, run ForeningLet member sync manually and inspect the result.
6. Enable that job for automatic daily synchronization, or choose another frequency.

== Frequently Asked Questions ==

= What is the host setting? =
The complete member-list URL: https://foreninglet.dk/api/members?version=1.
Only this HTTPS ForeningLet endpoint is accepted; redirects and filtered or
paginated URLs are rejected to protect credentials and membership reconciliation.

= How are API credentials stored? =
As a non-autoloaded WordPress option, accessible through the settings page only to
users with manage_options. The password is never displayed or included in sync results.
Credentials are not encrypted at rest; protect database access and backups.
Leave the password field blank to keep it, or select Remove saved credentials to clear it.

= What if someone has no email address? =
They are skipped without stopping other imports, and the final result includes
"no email:1" (or the actual number skipped after deduplicating member numbers).
No new record or account is created for them. If their member number already has
a linked account, its existing contact data and membership are preserved.

= What if someone has an invalid email address or member number? =
A non-empty invalid email address or invalid member number still rejects the
entire import before any membership changes. Correct the source data and retry.
The sync result identifies the first failing record by its one-based position in
the API array and member number, followed by the exact field and validation
requirement. Names, email addresses, passwords and other raw field values are
never included in these diagnostics. No memberships are changed for an invalid list.

== External services ==

This plugin connects to the ForeningLet membership API, operated by ForeningLet,
only after an administrator configures API credentials and runs or enables the job.
No external requests are made during ordinary browsing or while displaying settings.

* **When:** each manual run or scheduled execution of ForeningLet member sync.
* **Where:** https://foreninglet.dk/api/members?version=1 over HTTPS.
* **What is sent:** the configured API username and password in an HTTP Basic
  authentication header, the API version query and normal HTTP request metadata
  (including the server IP address). Local member records are not uploaded.
* **What is received:** the account's member list, including member identifiers,
  names, email addresses, phone numbers, enrollment dates and balances. Supported
  contact fields are stored locally; balances are used only for deduplication.

Ensure you have authority to retrieve these personal data and that your privacy
policy covers their processing and local storage. The association is responsible
for its ForeningLet agreement and any required data processing agreement.

* Service information and contractual terms: https://web.foreninglet.dk/
* Privacy policy: https://web.foreninglet.dk/privatlivspolitik/
* Data protection information: https://web.foreninglet.dk/gdpr/

ForeningLet is a trademark of its respective owner. This plugin is an independent
PCIO integration and is not affiliated with, sponsored by or endorsed by ForeningLet.


== Screenshots ==

1. Url and credentials to access the API at ForeningLet is saved under WP Admin Vis ForeningLet

== Changelog ==

= 1.1.0 =
* Show synced ForeningLet members as active on the member Membership tab.
* Store the ForeningLet member number on linked WordPress accounts so existing users are recognised, not only newly imported ones.

= 1.0.2 =
* Screenshot updated

= 1.0.0 =
* Skip and count members without email instead of aborting the import.
* Keep shared-email members separate with member-number usernames and stable account links.
* Initial member API integration, protected capability reconciliation and Vis configuration.

== Development ==

Standalone regression tests live outside the distributable plugin at
www/pcio-vis-with-foreninglet/sync.test.php in the source repository.
Run them with PHP from the repository root. The harness is CLI-only and mocks
WordPress APIs; it is not part of the WordPress.org plugin package.

The repository's www/pcio-vis-with-foreninglet/phpcs.xml.dist enables the complete WordPress coding standard,
enforces the pcio_vis global prefix and checks the pcio-vis-with-foreninglet text
domain. With WPCS installed, run phpcs --standard=www/pcio-vis-with-foreninglet/phpcs.xml.dist
from the repository root. This development-only configuration is not included in
the deployed plugin or WordPress.org package.
