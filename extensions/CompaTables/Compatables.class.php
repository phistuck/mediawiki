<?php

class Compatables {
	/** @var array (unique ID => string) */
	public static $items = array(); // used by closure

	/**
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 */
	public static function renderCompaTables( $input, array $args, Parser $parser ) {
		global $wgCompatablesUseESI, $wgUseTidy, $wgAlwaysUseTidy;

		$data = self::getCompatablesJson();
		$args['feature'] = isset( $args['feature'] ) ? $args['feature'] : '';
		$args['format'] = isset( $args['format'] ) ? $args['format'] : '';

		$out = '';
		if ( $input != '' ) {
			$out .= '<p class="compat-label">' . $input . '</p>';
		}

		$table = self::generateCompaTable( $data, $args );
		if ( ( $wgUseTidy && $parser->getOptions()->getTidy() ) || $wgAlwaysUseTidy ) {
			$table = MWTidy::tidy( $table );
		}

		if ( $wgCompatablesUseESI ) {
			// @TODO: this breaks in ESI level if $url ends up http for https views
			$url = SpecialPage::getTitleFor( 'Compatables' )->getFullUrl( array(
				'feature' => $args['feature'], 'format' => $args['format'], 'foresi' => 1 ) );
			$url = wfExpandUrl( $url, PROTO_INTERNAL );
			// @TODO: if the JSON file is always updated the same day of the week, one
			// could do some math here to avoid IMS GETs from CDN.
			// @TODO: Varnish does not support TTL here :/
			$ttl = 3600; // revalidate TTL

			// @TODO: Varnish does not support <esi:try> nor alt fallback URLs
			// (https://www.varnish-cache.org/docs/3.0/tutorial/esi.html)
			$out .= self::getUniqPlaceholder( // protect from Tidy
				"\n<!--esi\n" .
				Xml::element( 'esi:include', array( 'src' => $url, 'ttl' => $ttl ) ) . "\n" .
				"-->\n" .
				"<esi:remove>\n" .
				$table . "\n" . // fallback if no ESI interpreter is around
				"</esi:remove>\n"
			);

			/*
			$out .= self::getUniqPlaceholder( // protect from Tidy
				"\n<esi:try>\n" .
				"<esi:attempt>\n" .
				Xml::element( 'esi:include', array( 'src' => $url, 'ttl' => $ttl ) ) . "\n" .
				"</esi:attempt>\n" .
				"<esi:except>\n" .
				// If this ends up with an error *or* no ESI interpreter is active, this
				// will still show (though perhaps be stale) and the <esi> tags won't render.
				// If the special page works and ESI is running, it will strip this out.
				"<!-- Error: Special:Compatables or ESI is not available; used fallback! -->\n" .
				$table . "\n" .
				"</esi:except>\n" .
				"</esi:try>\n"
			);
			*/
		} else {
			$out .= $table;
			$parser->getOutput()->updateCacheExpiry( 6*3600 ); // worse cache hit rate
		}

		return $out;
	}

	/**
	 * Get a uniq marker for $text that is safe from Tidy
	 * @param string $text
	 */
	private static function getUniqPlaceholder( $text ) {
		$id = wfRandomString( 32 );
		self::$items[$id] = $text;
		return "<!-- UNIQ-Compatables:$id:selbatapmoC-QINU -->";
	}

	/**
	 * Unstrip the esi tags now that Tidy finished (which clobbers ESI tags)
	 * @param string $out
	 * @param string $text
	 */
	public static function onParserAfterTidy( &$out, &$text ) {
		if ( count( self::$items ) ) {
			# Find all hidden content and restore to normal...
			# (e.g. "<!-- UNIQ-Compatables:0cf806d86f00bef17b5035d9b8c3d00e:selbatapmoC-QINU -->")
			$text = preg_replace_callback(
				"/<!-- UNIQ-Compatables:([a-f0-9]{32}):selbatapmoC-QINU -->/m",
				function( $m ) {
					return isset( Compatables::$items[$m[1]] ) ? Compatables::$items[$m[1]] : '';
				},
				$text
			);
		}
		return true;
	}

	/**
	 * Nuke the uniq value to avoid hitting the preg_replace() above for no reason
	 * @param Parser $parser
	 */
	public static function onParserClearState( Parser $parser ) {
		self::$items = array();
		return true;
	}

	/**
	 * @return array
	 */
	public static function getCompatablesJson() {
		global $wgCompatablesJsonFileUrl;

		$json_url = $wgCompatablesJsonFileUrl;
		$req = MWHttpRequest::factory( $json_url, array( 'method' => 'GET' ) );
		$status = $req->execute();
		if ( $status->isOK() ) {
			$data = FormatJSON::decode( $req->getContent(), true );
			if ( !$data ) {
				throw new MWException( "Unable to parse json file at {$json_url}." );
			}
			return $data;
		} else {
			throw new MWException( "Unable to GET json file at {$json_url}." );
		}
	}

	/**
	 * @return string
	 */
	public static function getCompatablesJsonTimestamp() {
		global $wgCompatablesJsonFileUrl;

		$json_url = $wgCompatablesJsonFileUrl;
		$req = MWHttpRequest::factory( $json_url, array( 'method' => 'HEAD' ) );
		$status = $req->execute();
		if ( $status->isOK() ) {
			return $req->getResponseHeader( 'Last-Modified' );
		} else {
			throw new MWException( "Unable to HEAD json file at {$json_url}." );
		}
	}

	/**
	 * @param array $data Array from compatibility JSON file
	 * @param array $args
	 * @return string
	 */
	public static function generateCompaTable( array $data, array $args ) {
		// extracting data for feature
		$feature = isset( $data['data'][ $args['feature'] ] )
			? $data['data'][ $args['feature'] ]
			: null;
		$stats = $feature['stats'];
		$format = $args['format'];

		// initialize information for both tables
		$devices = array(
			array(
				'title' => 'Desktop',
				'thead' => '<thead><tr><th>Feature</th><th>Chrome</th><th>Firefox</th><th>Internet Explorer</th><th>Opera</th><th>Safari</th></tr></thead>',
				'uas' => array('chrome', 'firefox', 'ie', 'opera', 'safari')
			),
			array(
				'title' => 'Mobile',
				'thead' => '<thead><tr><th>Feature</th><th>Android</th><th>BlackBerry</th><th>Chrome for mobile</th><th>Firefox Mobile</th><th>IE Mobile</th><th>Opera Mobile</th><th>Opera Mini</th><th>Safari Mobile</th></tr></thead>',
				'uas' => array('android', 'bb', 'and_chr', 'and_ff', 'ie_mob', 'op_mob', 'op_mini', 'ios_saf')
			)
		);

		$browserinfo = $data['agents'];


		//////////////////////
		// TEMPORARY!!!!
		// hardcoded value not in dataset
		//////////////////////
		$browserinfo['ie_mob']['browser'] = 'IE Mobile';


		$out = '';
			// $trace = '1 Trace:';
			// $trace .= '<p>'.implode(", ", $browserinfo );

		$allsupport = array();

		$finalitem = end($devices[0]['uas']);
		foreach ($devices as $device) {
			if ('list' == $format ) {
				$out .= '<dl class="compat-list">';
				// if ('Mobile' == $device['title'] ) {
				//     $out .= '<dd class=""><dt class="">Mobiles</dt><dd class="">';
				// }
			} else {
				$out .= '<h3>' . $device['title'] . '</h3>';
				$out .= '<table class="compat-table">';
				$out .= $device['thead'];
				$out .= '<tbody><tr><th>Basic Support</th>';
			}

			$uas = $device['uas'];
			foreach ($uas as $ua) {
				$support = 'unsupported';
				$supportclass = 'Unsupported';
				$versions = isset( $stats[ $ua ] ) ? $stats[ $ua ] : null;
				if ($versions) {
					$newvalue = '';
					$supporthistory = '';
					foreach ($versions as $v => $value) {
						if ($newvalue != $value) {
							$newvalue = $value;
							switch ($value) {
								case 'u':
									$supporthistory .= '<div>' . $v . ' <i>?</i></div>';
									$supportclass = 'Unknown';
									continue;
								case 'u p':
									$supporthistory .= '<div>' . $v . ' <i>?, polyfill available</i></div>';
									$supportclass = 'Unknown';
									continue;
								// case 'n':
								//     $supporthistory .= '<div>' . $v . ' <i>unsupported</i></div>';
								//     $supportclass = 'Unsupported';
								//     continue;
								case 'p':
									$supporthistory .= '<div>' . $v . ' <i>unsupported, polyfill available</i></div>';
									$supportclass = 'Partial';
									continue;
								case 'a':
									$supporthistory .= '<div>' . $v . ' <span class="partial-support">partial</span></div>';
									$supportclass = 'Partial';
									continue;
								case 'a x':
									$supporthistory .= '<div>' . $v . ' <span class="partial-support">partial</span><span class="prefix ' . $browserinfo[$ua]['prefix'] . '">-' . $browserinfo[$ua]['prefix'] . '</span></div>';
									$supportclass = 'Partial';
									continue;
								case 'y x':
									$supporthistory .= '<div>' . $v . ' <span class="prefix ' . $browserinfo[$ua]['prefix'] . '">-' . $browserinfo[$ua]['prefix'] . '</span></div>';
									$supportclass = 'Partial';
									continue;
								case 'y':
									$supporthistory .= '<div>' . $v . '</div>';
									$supportclass = 'Supported';
									break;
							}
						}
					}
					$support = $supporthistory;
					// $newvalue = '';
				} else {
					$support = '?';
					$supportclass = 'Unknown';
				}

				if ('list' == $format ) {
					$out .= '<dt class="' . $supportclass . ' ' . $ua . '"><span>' . $browserinfo[$ua]['browser'] . '</span></dt><dd class="' . $supportclass . '">' . $supportclass . '</dd>';
					$allsupport[] = $supportclass;
				} else {
					$out .= '<td>' . $support . '</td>';
				}
			}

			if ('list' == $format ) {
				if ('Desktop' == $device['title'] && $finalitem == $ua ) {
					$out .= '<dt class="MOBILE_SUPPORT mobiles"><span>Mobiles</span></dt><dd class="MOBILE_SUPPORT">MOBILE_SUPPORT';
				} else {
					$out .= '</dd></dl>';
				}
			} else {
				$out .= '</tr></tbody></table>';
			}
		}

		// determine overall mobile support and replace placeholder
		if ('list' == $format ) {
			$mobilesupport  = 'Unknown';
			if ( 1 == count(array_unique($allsupport)) ) {
				$mobilesupport = $allsupport[0];
			} elseif ( in_array('Supported', $allsupport) ) {
				$mobilesupport  = 'Partial';
			}

			$out = preg_replace('/MOBILE_SUPPORT/', $mobilesupport, $out);
		}

		// $out .= '<p>' . $trace . '</p>';
		return $out;
	}
}
