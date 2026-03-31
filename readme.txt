=== Presswell Tracking Signal Relay ===
Contributors: presswell, benplum
Tags: attribution, utm, tracking, forms, lead-generation
Requires at least: 6.1
Tested up to: 6.9
Stable tag: 1.0.0
License: GNU General Public License v2.0 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Capture attribution query parameters and relay them into supported WordPress form submissions.

== Description ==

Presswell Tracking Signal Relay captures attribution parameters and stores them with supported form submissions. It reads query parameters from the current URL, merges them with previously stored values, and keeps them available for later form views in the same session.

**Supported Form Plugins**

* Gravity Forms
* WPForms
* Fluent Forms
* Contact Form 7
* Forminator
* Formidable Forms

**Features**

* Captures common attribution parameters and stores them with submissions
* Tracks `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `gclid`, `fbclid`, `msclkid`, `ttclid`, `landing_page`, `landing_query`, and `referrer`
* Persists values in browser localStorage with a 1-hour TTL by default
* Automatically populates hidden tracking fields when supported forms render
* Supports per-site custom parameters from plugin settings

= Documentation =

***Capture Methods***

* Gravity Forms: Custom field type (`presswell_transceiver`) that users add from the builder.
* WPForms: Custom field type (`presswell_transceiver`) that users add from the builder.
* Fluent Forms: Custom field type (`presswell_transceiver`) that users add from the builder.
* Contact Form 7: HTML injection. Hidden tracking inputs are appended during form render.
* Forminator: HTML injection. Hidden tracking inputs are appended during form render.
* Formidable Forms: Custom field type (`presswell_transceiver`) that users add from the builder.

**pwtsr_tracking_keys( $keys, $context )**

* **$keys** (array) (required) - Ordered list of tracking keys that should be captured and stored with each entry.
* **$context** (string) (required) - Adapter context (`core`, `gravityforms`, `wpforms`, `fluentforms`, `contactform7`, `forminator`, or `formidable`).
* Return an indexed array of string keys.
* These values are used to build hidden inputs and entry-detail output.

Example:

    add_filter( 'pwtsr_tracking_keys', function( $keys, $context ) {
        if ( 'gravityforms' !== $context ) {
            return $keys;
        }
        $keys[] = 'custom_param';
        $keys[] = 'utm_id';
        return $keys;
    }, 10, 2 );

**pwtsr_tracking_ttl( $ttl, $context )**

* **$ttl** (int) (required) - Session storage lifetime in seconds. Default is `3600` (1 hour).
* **$context** (string) (required) - Adapter context (`core`, `gravityforms`, `wpforms`, `fluentforms`, `contactform7`, `forminator`, or `formidable`).
* Return a positive integer. Invalid values automatically fall back to the default TTL.

Example:

    add_filter( 'pwtsr_tracking_ttl', function( $ttl, $context ) {
        if ( 'core' !== $context ) {
            return $ttl;
        }
        return DAY_IN_SECONDS * 7;
    }, 10, 2 );

**pwtsr_storage_key( $storage_key, $context )**

* **$storage_key** (string) (required) - Browser storage key used for the attribution payload.
* **$context** (string) (required) - Adapter context (`core`, `gravityforms`, `wpforms`, `fluentforms`, `contactform7`, `forminator`, or `formidable`).
* Return a non-empty string.

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

**Settings and Debug Display**

Use *Settings -> Tracking Signal Relay* to:

* Add custom query parameter keys.
* Enable Debug Display for logged-in editors/admins.
* Inspect tracking field wrappers on the frontend while validating form output.

== Installation ==

Install via the WordPress plugin installer or manually upload the folder to `wp-content/plugins/`.

1. Activate the plugin.
2. If using Gravity Forms, edit a form and add the **Tracking** field from *Advanced Fields*.
3. If using WPForms, edit a form and add the **Tracking** field.
4. If using Fluent Forms, edit a form and add the **Tracking** field.
5. If using Formidable Forms, edit a form and add the **Tracking** field.
6. If using Contact Form 7, publish any form (tracking inputs are injected automatically).
7. If using Forminator, publish a custom form (tracking inputs are injected automatically).
8. Send traffic with UTM/click parameters.

== Frequently Asked Questions ==

= What parameters are tracked by default? =

UTM parameters (`utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`), common ad click IDs (`gclid`, `fbclid`, `msclkid`, `ttclid`), plus `landing_page`, `landing_query`, and `referrer`.

= Does this require custom JavaScript in my theme? =

No. The plugin injects and populates tracking inputs automatically when supported forms render. 

= How long is attribution data stored? =

By default, one hour. You can change the TTL with the `pwtsr_tracking_ttl` filter.

= Can I track additional custom parameters? =

Yes. Use the `pwtsr_tracking_keys` filter or add custom parameters in *Settings -> Tracking Signal Relay*.

= Why isn't my form plugin supported? =

We plan to support additional form ecosystems, but each integration requires reliable extension points for hidden field rendering, submission lifecycle hooks, entry persistence, and token/merge-tag resolution. Some form plugins do not expose these capabilities consistently enough to deliver a production-ready adapter. We are also prioritizing support for plugins with transparent licensing, complete core functionality, and a respectful user experience.

== Privacy ==

Tracking values are stored in browser localStorage and submitted only through supported form entries on your site. This plugin does not send attribution data to third-party services by default.

== Screenshots ==

1. Form builder view with the Tracking field available.
2. Frontend debug display showing populated tracking fields.
3. Entry details view with captured attribution values.

== Changelog ==

= 1.0.0 =
* First public release.
