<?php
/*
Plugin Name: Gluon Firmware List Shortcode
Plugin URI: https://github.com/freifunk-westpfalz/freifunk-versions
Description: Defines shortcodes to display Gluon Firmware versions
Version: 0.4dev
Author: Stephan Platz
Author URI: http://paalsteek.de/
Licence: 2-clause BSD

This is a derivate of the following plugin

Origin Plugin Name: Freifunk Hamburg Firmware List Shortcode
Origin Plugin URI: http://mschuette.name/
Origin Description: Defines shortcodes to display Freifunk Hamburg Firmware versions
Origin Version: 0.4dev
Origin Author: Martin Schuette
Origin Author URI: http://mschuette.name/
Origin Licence: 2-clause BSD
*/

define( 'GLUON_STABLE_BASEDIR', 'http://firmware.freifunk-fulda.de/stable/current/' );
define( 'GLUON_CACHETIME', 15 );

/* gets metadata from URL, handles caching */
function gluon_getmanifest( $basedir ) {
	// Caching
	if ( WP_DEBUG || ( false === ( $manifest = get_transient( 'gluon_manifest' ) ) ) ) {
		$manifest      = array();
		$url           = $basedir . 'sysupgrade/stable.manifest';
		$http_response = wp_remote_get( $url );  // TODO: error handling
		if ( is_array($http_response) && $http_response['response']['code'] == 200 ) {
			$input         = wp_remote_retrieve_body( $http_response );
			foreach ( explode( "\n", $input ) as $line ) {
				$ret = sscanf( $line, '%s %s %s %s', $hw, $sw_ver, $hash, $filename );
				if ( $ret === 4 ) {
					if ( preg_match( '/^(.*)-v(\d+)$/', $hw, $matches ) ) {
						$hw     = $matches[1];
						$hw_ver = $matches[2];
					} else {
						$hw_ver = '1';
					}
					$manifest[$hw][$hw_ver] = $filename;
				}
			}

			$cachetime = GLUON_CACHETIME * MINUTE_IN_SECONDS;
			set_transient( 'gluon_manifest', $manifest, $cachetime );
		}
	}
	return $manifest;
}

/* gets latest version from first manifest line */
function gluon_getlatest( $basedir ) {
	// Caching
	if ( false === ( $sw_ver = get_transient( 'gluon_latestversion' ) ) ) {
		$sw_ver = 'unknown';
		$input  = wp_remote_retrieve_body( wp_remote_get( $basedir . 'sysupgrade/stable.manifest' ) );
		foreach ( explode( "\n", $input ) as $line ) {
			$ret = sscanf( $line, '%s %s %s %s', $hw, $sw_ver, $hash, $filename );
			if ( $ret === 4 ) {
				// break processing on first matching line
				$cachetime = GLUON_CACHETIME * MINUTE_IN_SECONDS;
				set_transient( 'gluon_latestversion', $sw_ver, $cachetime );
				break;
			}
		}
	}
	return $sw_ver;
}

if ( ! shortcode_exists( 'gluon_latestversion' ) ) {
	add_shortcode( 'gluon_latestversion', 'gluon_shortcode_latestversion' );
}
// Example:
// [gluon_latestversion]
function gluon_shortcode_latestversion( $atts, $content, $name ) {
	$sw_ver = gluon_getlatest( GLUON_STABLE_BASEDIR );
	$outstr = "<span class=\"ff $name\">$sw_ver</span>";
	return $outstr;
}
if ( ! shortcode_exists( 'gluon_versions' ) ) {
	add_shortcode( 'gluon_versions', 'gluon_shortcode_versions' );
}
// Example:
// [gluon_versions]
// [gluon_versions grep="ubiquiti"]
function gluon_shortcode_versions( $atts, $content, $name ) {
	$manifest = gluon_getmanifest( GLUON_STABLE_BASEDIR );

	$outstr  = "<div class=\"ff $name\">";
	$outstr .= '<table><tr><th>Modell</th><th>Erstinstallation</th><th>Aktualisierung</th></tr>';

	# optionally filter output by given substring
	if ( is_array( $atts )
		&& array_key_exists( 'grep', $atts )
		&& ! empty( $atts['grep'] ) ) {
		$grep = $atts['grep'];
	} else {
		$grep = false;
	}

	ksort($manifest);

	foreach ( $manifest as $hw => $versions ) {
		// filter
		if ( $grep && ( false === strpos( $hw, $grep ) ) ) {
			continue;
		}
		$hw = trim(gluon_beautify_hw_name( $hw, $grep ));

		$ddgIcon = '<img src="/wp-content/uploads/2015/05/ddg_icon_20x20.png" />';
                $ddgSearch = preg_replace('!(\ |/)[a-zA-Z0-9].*!','',$hw);
                $ddgLink = "https://duckduckgo.com/?q=" . $ddgSearch . "&ia=products&kl=de-de";

		$outstr .= sprintf( "\n<tr><td><a href=\"%s\" target=\"_BLANK\">%s</a> %s</td>",$ddgLink, $ddgIcon, $hw);

		// factory versions
		$hw_ver_links = array();
		foreach ( $versions as $hw_ver => $filename ) {
			$filename = str_replace( '-sysupgrade', '', $filename );
			$hw_ver_links[] = sprintf(
				'<a href="%s%s">v%s</a>',
				GLUON_STABLE_BASEDIR.'factory/',
				$filename, $hw_ver
			);
		}
		$outstr .= '<td>Hardware Version ' . join( ', ', $hw_ver_links ) . '</td>';

		// sysupgrade versions
		$hw_ver_links = array();
		foreach ( $versions as $hw_ver => $filename ) {
			$hw_ver_links[] = sprintf(
				'<a href="%s%s">v%s</a>',
				GLUON_STABLE_BASEDIR.'sysupgrade/',
				$filename, $hw_ver
			);
		}
		$outstr .= '<td>Hardware Version ' . join( ', ', $hw_ver_links ) . '</td>';

		$outstr .= '</tr>';
	}

	$outstr .= '</table>';
	$outstr .= '</div>';
	// $outstr .= '<pre>'.print_r( $manifest, true ).'</pre>';
	return $outstr;
}

// some crude rules to add capitalization and whitespace to the
// hardware model name.
// set $discard_vendor to strip the vendor name
// (used for single-vendor lists, e.g. $discard_vendor = 'tp-link' )
function gluon_beautify_hw_name( $hw, $discard_vendor = '' ) {
	if ( ! strncmp( $hw, 'tp-link', 7 ) ) {
		if ( $discard_vendor ) $hw = str_replace( $discard_vendor, '', $hw );
		$hw = strtoupper( $hw );
		$hw = str_replace( '-', ' ', $hw );
		$hw = str_replace( 'TP LINK ', 'TP-Link ', $hw );
		$hw = str_replace( ' TL ', ' TL-', $hw );
		$hw = str_replace( 'N ND', 'N/ND', $hw );
	} elseif ( ! strncmp( $hw, 'ubiquiti', 8 ) ) {
		if ( $discard_vendor ) $hw = str_replace( $discard_vendor, '', $hw );
		$hw = str_replace( 'bullet-m', 'bullet-m / nanostation-loco-m', $hw );
		$hw = str_replace( '-m', ' M2', $hw );
		$hw = str_replace( '-', ' ', $hw );
		$hw = ucwords( $hw );
	} elseif ( ! strncmp( $hw, 'd-link', 6 ) ) {
		if ( $discard_vendor ) $hw = str_replace( $discard_vendor, '', $hw );
		$hw = strtoupper( $hw );
		$hw = str_replace( '-', ' ', $hw );
		$hw = str_replace( 'D LINK ', 'D-Link ', $hw );
		$hw = str_replace( ' DIR ', ' DIR-', $hw );
	} elseif ( ! strncmp( $hw, 'linksys', 7 ) ) {
		if ( $discard_vendor ) $hw = str_replace( $discard_vendor, '', $hw );
		$hw = strtoupper( $hw );
		$hw = str_replace( '-', ' ', $hw );
		$hw = str_replace( 'LINKSYS', 'Linksys ', $hw );
		$hw = str_replace( ' WRT', ' WRT-', $hw );
	} elseif ( ! strncmp( $hw, 'buffalo', 7 ) ) {
		if ( $discard_vendor ) $hw = str_replace( $discard_vendor, '', $hw );
		$hw = strtoupper( $hw );
		$hw = str_replace('BUFFALO', 'Buffalo ', $hw );
		$hw = str_replace( 'HP-AG300H-WZR-600DHP', 'HP-AG300H & WZR-600DHP', $hw );
		$hw = str_replace( '-WZR', 'WZR', $hw );
	}
	return $hw;
}

