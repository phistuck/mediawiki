<?php
/**
 * CompaTables - this extension adds a browser compatability table to articles based on arguments
 *
 * To activate this extension, add the following into your LocalSettings.php file:
 * require_once('$IP/extensions/CompaTables/compatables.php');
 *
 * @ingroup Extensions
 * @author Doug Schepers <schepers@w3.org>
 * @author Aaron Schulz <aschulz4587@gmail.com>
 * @version 1.0
 * @link https://www.mediawiki.org/wiki/Extension:CompaTables Documentation
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

/**
 * Protect against register_globals vulnerabilities.
 * This line must be present before any global variable is referenced.
 */
if ( !defined( 'MEDIAWIKI' ) ) {
	echo( "This is an extension to the MediaWiki package and cannot be run standalone.\n" );
	die( -1 );
}

// Extension credits that will show up on Special:Version
$wgExtensionCredits['parserHook'][] = array(
	'name'           => 'CompaTables',
	'version'        => '1.0',
	'author'         => array( 'Doug Schepers', 'Aaron Schulz' ),
	'url'            => 'https://www.mediawiki.org/wiki/Extension:CompaTables',
	'descriptionmsg' => 'compatablesmessage',
	'description'    => 'Adds browser compatability table to article based on arguments'
);

require( __DIR__ . '/Compatables.config.php' );

# Main i18n file and special page alias file
$wgExtensionMessagesFiles['Compatables'] = __DIR__. "/Compatables.i18n.php";
$wgExtensionMessagesFiles['CompatablesAliases'] = __DIR__ . "/Compatables.alias.php";

$wgAutoloadClasses['Compatables'] = __DIR__ . '/Compatables.class.php';
$wgAutoloadClasses['SpecialCompatables'] = __DIR__ . '/SpecialCompatables.php';
$wgSpecialPages['Compatables'] = 'SpecialCompatables';

$wgHooks['PageRenderingHash'][] = function( &$confstr ) {
	global $wgCompatablesUseESI;

	if ( $wgCompatablesUseESI ) {
		$confstr .= "!esi=1"; // check for version of page cache with "esi" key
	}

	return true;
};

$wgHooks['ParserFirstCallInit'][] = function( Parser &$parser ) {
	$parser->setHook( 'compatability', 'Compatables::renderCompaTables' );
	return true;
};

$wgHooks['ParserAfterTidy'][] = 'Compatables::onParserAfterTidy';
$wgHooks['ParserClearState'][] = 'Compatables::onParserClearState';

