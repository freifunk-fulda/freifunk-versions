<?php
/*
Plugin Name: Freifunk Hamburg Firmware List Shortcode
Plugin URI: http://mschuette.name/
Description: Defines shortcodes to display Freifunk Hamburg Firmware versions
Version: 0.4
Author: Martin Schuette
Author URI: http://mschuette.name/
Licence: 2-clause BSD
*/

define('FF_HH_STABLE_BASEDIR', 'http://updates.hamburg.freifunk.net/stable/');
define('FF_HH_CACHETIME', 15);

/* gets metadata from URL, handles caching */
function ff_hh_getmanifest ($basedir) {
    // Caching
    if ( WP_DEBUG || ( false === ( $manifest = get_transient( "ff_hh_manifest" ) ) ) ) {
        $manifest      = array();
        $url           = $basedir . 'sysupgrade/manifest';
        $http_response = wp_remote_get( $url );  // TODO: error handling
        $input         = wp_remote_retrieve_body( $http_response );
        foreach ( explode("\n", $input) as $line ) {
            $ret = sscanf($line, '%s %s %s %s', $hw, $sw_ver, $hash, $filename);
            if ($ret === 4) {
                if (preg_match('/^(.*)-v(\d+)$/', $hw, $matches)) {
                    $hw     = $matches[1];
                    $hw_ver = $matches[2];
                } else {
                    $hw_ver = '1';
                }
                $manifest[$hw][$hw_ver] = $filename;
            }
        }

        $cachetime = FF_HH_CACHETIME * MINUTE_IN_SECONDS;
        set_transient( "ff_hh_manifest", $manifest, $cachetime );
    }
    return $manifest;
}

/* gets latest version from first manifest line */
function ff_hh_getlatest ($basedir) {
    // Caching
    if ( false === ( $sw_ver = get_transient( "ff_hh_latestversion" ) ) ) {
        $sw_ver = 'unknown';
        $input  = wp_remote_retrieve_body( wp_remote_get($basedir . 'sysupgrade/manifest') );
        foreach ( explode("\n", $input) as $line ) {
            $ret = sscanf($line, '%s %s %s %s', $hw, $sw_ver, $hash, $filename);
            if ($ret === 4) {
                // break processing on first matching line
                $cachetime = FF_HH_CACHETIME * MINUTE_IN_SECONDS;
                set_transient( "ff_hh_latestversion", $sw_ver, $cachetime );
                break;
            }
        }
    }
    return $sw_ver;
}

if ( ! shortcode_exists( 'ff_hh_latestversion' ) ) {
    add_shortcode( 'ff_hh_latestversion',    'ff_hh_shortcode_latestversion');
}
// Example:
// [ff_hh_latestversion]
function ff_hh_shortcode_latestversion( $atts, $content, $name ) {
    $sw_ver = ff_hh_getlatest(FF_HH_STABLE_BASEDIR);
    $outstr = "<span class=\"ff $name\">$sw_ver</span>";
    return $outstr;
}
if ( ! shortcode_exists( 'ff_hh_versions' ) ) {
    add_shortcode( 'ff_hh_versions',    'ff_hh_shortcode_versions');
}
// Example:
// [ff_hh_versions]
// [ff_hh_versions grep="ubiquiti"]
function ff_hh_shortcode_versions( $atts, $content, $name ) {
    $manifest = ff_hh_getmanifest(FF_HH_STABLE_BASEDIR);

    $outstr = "<div class=\"ff $name\">";
    $outstr .= '<table><tr><th>Modell</th><th>Erstinstallation</th><th>Aktualisierung</th></tr>';

    # optionally filter output by given substring
    if (is_array($atts)
        && array_key_exists('grep', $atts)
        && !empty($atts['grep'])) {
        $grep = $atts['grep'];
    } else {
        $grep = false;
    }

    foreach ($manifest as $hw => $versions) {
        // filter
        if ($grep && (false === strpos($hw, $grep))) {
            continue;
        }
        // beautify HW model names
        if (!strncmp($hw, 'tp-link', 7)) {
            if ($grep) $hw = str_replace($grep, '', $hw);
            $hw = strtoupper($hw);
            $hw = str_replace('-', ' ', $hw);
            $hw = str_replace('TP LINK TL ', 'TP-Link TL-', $hw);
        } elseif (!strncmp($hw, 'ubiquiti', 8)) {
            if ($grep) $hw = str_replace($grep, '', $hw);
            $hw = str_replace('bullet-m', 'bullet-m / nanostation-loco-m', $hw);
            $hw = str_replace('-m', ' M2', $hw);
            $hw = str_replace('-', ' ', $hw);
            $hw = ucwords($hw);
        } elseif (!strncmp($hw, 'd-link', 6)) {
            if ($grep) $hw = str_replace($grep, '', $hw);
            $hw = str_replace('-', ' ', $hw);
            $hw = str_replace('d link dir ', 'D-Link DIR-', $hw);
        }
        $outstr .= sprintf("\n<tr><td>%s</td>", $hw);

        // factory versions
        $hw_ver_links = array();
        foreach ($versions as $hw_ver => $filename) {
            $filename = str_replace('-sysupgrade', '', $filename);
            $hw_ver_links[] = sprintf('<a href="%s%s">%s.x</a>',
                FF_HH_STABLE_BASEDIR.'factory/', $filename, $hw_ver);
        }
        $outstr .= '<td>Hardware Version ' . join(', ', $hw_ver_links) . '</td>';

        // sysupgrade versions
        $hw_ver_links = array();
        foreach ($versions as $hw_ver => $filename) {
            $hw_ver_links[] = sprintf('<a href="%s%s">%s.x</a>',
                FF_HH_STABLE_BASEDIR.'sysupgrade/', $filename, $hw_ver);
        }
        $outstr .= '<td>Hardware Version ' . join(', ', $hw_ver_links) . '</td>';

        $outstr .= '</tr>';
    }

    $outstr .= '</table>';
    $outstr .= '</div>';
    // $outstr .= '<pre>'.print_r($manifest, true).'</pre>';
    return $outstr;
}
