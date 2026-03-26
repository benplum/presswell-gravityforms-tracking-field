=== Presswell Tracking Signal Relay ===
Contributors: presswell, benplum
Tags: gravity forms, contact form 7, forminator, formidable forms, attribution, utm, tracking, hidden field, marketing, click id
Requires at least: 6.1
Tested up to: 6.5
Stable tag: trunk
License: GNU General Public License v2.0 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Capture UTM and click tracking parameters across a visitor session.

== Description ==

Presswell Tracking Signal Relay captures attribution parameters and stores them with supported form plugin submissions.

Supported Form Plugins:

* Gravity Forms
* Contact Form 7
* Forminator
* Formidable Forms

**Features**

* Adds a Tracking field in Gravity Forms under Advanced Fields
* Injects and stores tracking data for Forminator custom forms
* Injects and stores tracking data for Contact Form 7 forms
* Injects and stores tracking data for Formidable forms
* Captures common attribution parameters and stores them with submissions
* Tracks `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `gclid`, `fbclid`, `msclkid`, `ttclid`, `landing_page`, `landing_query`, and `referrer`
* Persists values in browser localStorage for the visitor session (1-hour TTL by default)
* Automatically populates hidden field inputs when forms render

***Session Attribution Storage***

The plugin reads query parameters from the current URL, merges them with stored values, and keeps them available for later form views during the same visitor session.

***Form Entry Integration***

When a supported form renders, hidden inputs are generated and populated automatically so data is stored with the form entry without custom template code.

= Documentation =

**pwtsr_tracking_keys( $keys, $context )**

* **$keys** (array) (required) - Ordered list of tracking keys that should be captured and stored with each entry.
* **$context** (string) (required) - Adapter context (`core`, `gravityforms`, `contactform7`, `forminator`, or `formidable`).
* Return an indexed array of string keys.
* These values are used to build hidden inputs and entry-detail output.

Example:

`
add_filter( 'pwtsr_tracking_keys', function( $keys, $context ) {
    if ( 'gravityforms' !== $context ) {
        return $keys;
    }
    $keys[] = 'custom_param';
    $keys[] = 'utm_id';
    return $keys;
}, 10, 2 );
`

**pwtsr_tracking_ttl( $ttl, $context )**

* **$ttl** (int) (required) - Session storage lifetime in seconds. Default is `3600` (1 hour).
* **$context** (string) (required) - Adapter context (`core`, `gravityforms`, `contactform7`, `forminator`, or `formidable`).
* Return a positive integer. Invalid values automatically fall back to the default TTL.

Example:

`
add_filter( 'pwtsr_tracking_ttl', function( $ttl, $context ) {
    if ( 'core' !== $context ) {
        return $ttl;
    }
    return DAY_IN_SECONDS * 7;
}, 10, 2 );
`

**pwtsr_storage_key( $storage_key, $context )**

* **$storage_key** (string) (required) - Browser storage key used for the attribution payload.
* **$context** (string) (required) - Adapter context (`core`, `gravityforms`, `contactform7`, `forminator`, or `formidable`).
* Return a non-empty string.

**Contact Form 7 Mail Output**

Use the special mail tag below inside Contact Form 7 mail templates to print transceiver values:

`
[_pwsr_transceiver]
`

The tag is also added to Contact Form 7's Mail panel "you can use these mail-tags" suggestion list.

An alias is also supported:

`
[pwsr_transceiver]
`

Each tag outputs non-empty values as key/value lines.

**Default Tracking Keys**

* `utm_source`
* `utm_medium`
* `utm_campaign`
* `utm_content`
* `utm_term`
* `gclid`
* `fbclid`
* `msclkid`
* `ttclid`
* `landing_page`
* `landing_query`
* `referrer`

== Installation ==

Install via the WordPress plugin installer or manually upload the folder to `wp-content/plugins/`.

1. Activate the plugin.
2. If using Gravity Forms, edit a form and add the **Tracking** field from *Advanced Fields*.
3. If using Forminator, publish a custom form (tracking inputs are injected automatically).
4. If using Contact Form 7, publish any form (tracking inputs are injected automatically).
5. If using Formidable, publish a form (tracking inputs are injected automatically).
6. Send traffic with UTM/click parameters.

== Frequently Asked Questions ==

= What parameters are tracked by default? =

UTM parameters (`utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`), common ad click IDs (`gclid`, `fbclid`, `msclkid`, `ttclid`), plus `landing_page`, `landing_query`, and `referrer`.

= Does this require custom JavaScript in my theme? =

No. The plugin injects and populates tracking inputs automatically when supported forms render.

= How long is attribution data stored? =

By default, one hour. You can change the TTL with the `pwtsr_tracking_ttl` filter.

= Can I track additional custom parameters? =

Yes. Use the `pwtsr_tracking_keys` filter to add or remove keys.

= Why isn't my form plugin supported? =

We plan to support additional form ecosystems, but each integration requires reliable extension points for hidden field rendering, submission lifecycle hooks, entry persistence, and token/merge-tag resolution. Some form plugins do not expose these capabilities consistently enough to deliver a production-ready adapter.

== Screenshots ==

1. Gravity Forms editor showing the Tracking field in Advanced Fields
2. Tracking field settings and hidden input mapping
3. Gravity Forms entry details with captured attribution values

== Changelog ==

= 1.2.0 =
* Added Contact Form 7 adapter and submission sanitization.
* Added adapter-aware script localization context handling.

= 1.1.0 =
* Renamed plugin to Presswell Tracking Signal Relay.
* Added adapter architecture for Gravity Forms and Forminator.
* Added centralized constants and service layers.
* Standardized filter API under `presswell_tracking_signal_relay_*` hooks.

= 1.0.0 =
* Initial release.
