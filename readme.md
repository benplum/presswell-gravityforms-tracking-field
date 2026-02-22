# Presswell Tracking Field for Gravity Forms

Lightweight add-on that adds a hidden field to Gravity Forms for capturing UTM, click IDs, landing page, and referrer values. The field stores parameters for the duration of a visitor session using `localStorage`, ensuring every form submission carries attribution metadata.

## Features

- New "Tracking" field under **Advanced Fields** in the Gravity Forms editor.
- Captures standard marketing parameters plus landing page and referrer.
    - `utm_source`
    - `utm_medium`
    - `utm_campaign`
    - `utm_content`
    - `utm_term`
    - `gclid`
    - `fbclid`
    - `msclkid`
    - `ttclid`
    - `landing_page`
    - `landing_query`
    - `referrer`
- Auto-populates hidden inputs on render without requiring manual shortcodes or theme edits.
- Persists query parameters and derived values across the session via `localStorage` (1-hour TTL by default, adjustable via `presswell_gf_tracking_ttl`).
- Fully filterable tracking key list via `presswell_gf_tracking_keys`.

## Usage

1. Upload the plugin directory to `wp-content/plugins/` and activate **Presswell Tracking Field for Gravity Forms**.
2. Edit a Gravity Form, open the **Advanced Fields** panel, and add the **Tracking** field.
3. Publish the form. Hidden inputs are injected automatically and populated on page load.
4. Append UTM or click tracking params to your landing URLs; submissions will include those values in the entry data.

## Customization

Track additional params using the `presswell_gf_tracking_keys` filter.

```
add_filter( 'presswell_gf_tracking_keys', function( $keys ) {
    $keys[] = 'custom_param';
    return $keys;
} );
```

Adjust the persistence window with `presswell_gf_tracking_ttl` (seconds).

```
add_filter( 'presswell_gf_tracking_ttl', function() {
    return DAY_IN_SECONDS * 7; // keep tracking data for 7 days
} );
```

## Requirements

- WordPress 6.0+
- Gravity Forms 2.6+
