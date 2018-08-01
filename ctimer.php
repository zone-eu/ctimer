<?php
/**
 * Group file change times by inode ctime
 *
 * @author  : Peeter Marvet (peeter@zone.ee)
 * Date: 01.08.2018
 * Time: 10:12
 * @version 1.3.1
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL
 *
 * v.1.3.1
 * - case-insensitive ignores (mostly for jpg/JPG)
 * v.1.3
 * - fixed problem with last timeframe not listed
 * - removed shebang (was bad idea...   )
 * - directory iterator detects non-openable directories, reports
 * - directory iterator filters during recursion, not after traversing un-necessary places
 * - ignores and errors shown on output
 * - json export includes errors, includes and basepath
 * - tested from php5.2 up (no JSON_PRETTY_PRINT in php < 5.4)
 * v.1.2.4
 * - added support for cli - saving as json, chmod +x, shebang
 * - json saved with JSON_PRETTY_PRINT
 * v.1.2.3
 * - ignored path fragments as array
 * - ignores and basepath as more visible globals
 * - changed grouping to work with 900sec intervals
 * - refactoring can still wait on backburner
 * v.1.2.2
 * - random suffix that works on old PHPs
 * v.1.2.1
 * - fixed previous fix to actually show the .json download link
 * - added GPL license @ link to clarify usage rights
 * v.1.2
 * - fixed json creation
 * - added possibility to read from json files
 * - this "quick hack" is becoming quite messy - but it works and saves lives, so refactoring can wait
 * v.1.1
 * - added auto-renamer
 * v1.0
 * - initial version
 */

// although these files could contain malware we are ignoring them for ease of spotting real trouble
$ignored_extensions = array( 'jpg', 'png', 'gif', 'pdf', 'gz', 'jpeg', 'mp3', 'mp4', 'doc', 'docx', 'xls', 'xlsx' );

//$ignored_extensions = array_merge( $ignored_extensions, array( 'txt', 'csv', 'js', 'css', 'log' ) );

// these locations could be technically used for malware storage, but can be mostly ignored for clarity
$ignored_paths = array(
	'/.git', // well
	'/media/product/cache',
	'/var/cache',
	'/var/session',
	'/var/minifycache',
	'/var/report', // magento 1.x
	'/wp-content/cache', // wp
	'/administrator/cache', // joomla
	'./cache',
	//'./stats',
	//'./logs',
);

// ctimer is usually launched from the root of website - provide relative path to check something else
$base_path = '.';

$errors = array();

if ( php_sapi_name() === 'cli' ) {

	$file_ctimes_grouped = get_grouped_ctimes();

	$prefix = ! empty( $argv[1] ) ? preg_replace( '~[^a-z0-9-_]+~i', '', str_replace( '.', '_', strtolower( $argv[1] ) ) ) : substr( md5( rand() ), 0, 8 );

	$filename = $prefix . '_' . date( "Y-m-d_H-i" ) . '_ctimer.json';
	file_put_contents( $filename, generate_json( $file_ctimes_grouped ) );

	echo "Ignored extensions: " . implode( ', ', $ignored_extensions ) . PHP_EOL;
	echo "Ignored paths: " . implode( ', ', $ignored_paths ) . PHP_EOL . PHP_EOL;
	echo "File change times saved to $filename" . PHP_EOL . PHP_EOL;

	exit();
}

if ( basename( __FILE__, '.php' ) === 'ctimer' ) {

	$new_name = 'ctimer_' . substr( md5( rand() ), 0, 8 ) . '.php';

	rename( basename( __FILE__ ), $new_name );

	header( "HTTP/1.0 404 Not Found" );
	echo "<pre>I should not be available on predictable address - so I renamed myself, new name is: <a href='$new_name'>$new_name</a>" . PHP_EOL . "It might be good idea to bookmark it for future use :-)</pre>";
	die();

}

$local_jsons = get_stored_results();

if ( ! empty( $local_jsons ) && empty( $_GET['json'] ) && ! isset( $_GET['live'] ) ) {
	$html = generate_filepicker_html( $local_jsons );
} else {

	if ( ! isset( $_GET['json'] ) ) {

		$file_ctimes_grouped = get_grouped_ctimes();
		$html                = generate_ctimes_html( $file_ctimes_grouped );

	} else if ( empty( $_GET['json'] ) ) {

		$filename = preg_replace( '~[^a-z0-9-_]+~i', '', str_replace( '.', '_', strtolower( $_SERVER['SERVER_NAME'] ) ) ) . '_' . date( "Y-m-d_H-i" ) . '_ctimer.json';
		header( 'Content-type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . basename( $filename ) . '"' );
		header( 'Content-Transfer-Encoding: binary' );

		$file_ctimes_grouped = get_grouped_ctimes();

		$json = array(
			'ctimes'             => $file_ctimes_grouped,
			'ignored_extensions' => $ignored_extensions,
			'ignored_paths'      => $ignored_paths,
			'errors'             => $errors,
			'base_path'          => realpath( $base_path ),
		);

		echo generate_json( $file_ctimes_grouped );
		die();

	} else {

		$filename = basename( $_GET['json'] );

		if ( file_exists( $filename ) ) {

			$file = file_get_contents( $filename );
			$json = json_decode( $file, true );

			if ( isset( $json['ctimes'] ) ) {

				$file_ctimes_grouped = &$json['ctimes'];
				$errors              = $json['errors'];
				$ignored_extensions  = $json['ignored_extensions'];
				$ignored_paths       = $json['ignored_paths'];

			} else {

				$file_ctimes_grouped = &$json;

			}

			$html = generate_ctimes_html( $file_ctimes_grouped );

		} else {

			header( "HTTP/1.0 404 Not Found" );
			echo "Requested .json not found";
			die();

		}
	}
}

function generate_json( $file_ctimes_grouped ) {

	global $ignored_extensions, $ignored_paths, $base_path, $errors;

	$json = array(
		'ctimes'             => $file_ctimes_grouped,
		'ignored_extensions' => $ignored_extensions,
		'ignored_paths'      => $ignored_paths,
		'errors'             => $errors,
		'base_path'          => realpath( $base_path ),
	);

	if ( phpversion() >= '5.4' ) {
		return json_encode( $json, JSON_PRETTY_PRINT );
	} else if ( phpversion() >= '5.2' ) {
		return json_encode( $json );
	} else { // vintage technology detected, fallback to 20th century imminent
		return var_export( $json, true );
	}
}

function get_grouped_ctimes() {

	global $ignored_extensions, $ignored_paths, $base_path, $errors;

	$file_ctimes = array();

	$directory = new RecursiveDirectoryIterator ( $base_path );
	$filter    = new CtimerRecursiveFilterIterator( $directory, $ignored_extensions, $ignored_paths, $errors );
	$iterator  = new RecursiveIteratorIterator ( $filter, RecursiveIteratorIterator::SELF_FIRST ); //, RecursiveIteratorIterator::CATCH_GET_CHILD

	foreach ( $iterator as $x ) {

		if ( $x->isFile() ) {
			$filename                = $x->getPathname();
			$ctime                   = filectime( $filename );
			$file_ctimes[ $ctime ][] = array( "name" => $filename, 'ctime' => $ctime );
		}

	}

	$file_ctimes_grouped = array();

	ksort( $file_ctimes );

	$index = null;
	$group = array();

	foreach ( $file_ctimes as $key => $value ) {
		if ( is_null( $index ) ) {
			$index = $key;
		}

		if ( $key > $index + 900 ) {
			$file_ctimes_grouped[ $index ] = $group;
			$group                         = $value;
			$index                         = $key;
		} else {
			$group = array_merge( $group, $value );
		}
	}

	if ( ! empty( $group ) ) {
		$file_ctimes_grouped[ $index ] = $group;
	}

	unset( $file_ctimes );

	krsort( $file_ctimes_grouped );

	return $file_ctimes_grouped;
}

function generate_ctimes_html( $file_ctimes_grouped ) {

	global $ignored_extensions, $ignored_paths, $errors;

	$html     = "";
	$panel_id = 0;

	foreach ( $file_ctimes_grouped as $time => $files ) {

		usort( $files, 'name_sort' );

		$i = 0;

		$fragment      = "";
		$fragment_date = "";

		foreach ( $files as $file ) {

			$file_date = date( "Y-m-d H:i", $file['ctime'] );
			$fragment  .= $file_date . ' - ' . $file['name'] . PHP_EOL;

			if ( empty( $fragment_date ) || $file_date < $fragment_date ) {
				$fragment_date = $file_date;
			}
			$i ++;
		}

		if ( $i < 50 ) {
			$collapse_class = "collapse in";
		} else {
			$collapse_class = "collapse";
		}

		$html .= '<div class="panel panel-default">';

		$html .= "<div class=\"panel-heading\" role=\"tab\" id=\"heading_$panel_id\">";
		// add data-parent="#accordion" to have other panels collapse when opening (presumably not desired here)
		$html .= "<h4 class=\"panel-title\"><a role=\"button\" data-toggle=\"collapse\" href=\"#collapse_$panel_id\" aria-expanded=\"true\" aria-controls=\"collapse_$panel_id\">$fragment_date - <strong>$i files changed</strong></a></h4>";
		$html .= "</div>";

		$html .= "<div id=\"collapse_$panel_id\" class=\"panel-collapse $collapse_class\" role=\"tabpanel\" aria-labelledby=\"heading_$panel_id\">";
		$html .= '<div class="panel-body">';
		$html .= "<pre>$fragment</pre>";
		$html .= "</div>";
		$html .= "</div>";

		$html .= "</div>";

		$panel_id ++;
	}

	if ( ! isset( $_GET['json'] ) ) {
		$optional = '
				<p>If you want to store the result for future (forensic) use,
					<a href="?json">download it as .json</a>.</p>
		';
	} else {
		$optional = '
				<p><a href="?">Check another stored file?</a></p>
		';
	}

	$optional .= '
                <p class="small"><strong>Ignored extensions:</strong> ' . implode( ', ', $ignored_extensions ) . '. <strong>Ignored paths:</strong> ' . implode( ', ', $ignored_paths ) . '.</p>
            ';

	if ( ! empty( $errors ) ) {
		$optional .= '
                <p class="alert alert-warning" role="alert"><strong>Inaccessible folders:</strong> ' . implode( ', ', $errors ) . '.</p>
            ';
	}

	$html = '
				<h1>What has changed?</h1>
				<p>This script groups files by inode <code>ctime</code> - unlike <code>mtime</code> that
					can be changed to whatever user pleases, this is set by kernel. Meaning we can trust it
					(if we trust kernel, of course :-). Some paths may be excluded (cache?), please check the code.
					If you want to keep this script in server, please give it some random name - to make discovery by
					malicious scanners difficult (and also, please check the code - if kept on server it is very easy to
					include malware in excludes).
				</p>' . $optional . ' 
				<div class="panel-group" id="accordion" role="tablist" aria-multiselectable="true">
					' . $html . ' 
				</div>
				';

	return $html;
}

function name_sort( $a, $b ) {
	if ( $a['name'] > $b['name'] ) {
		return + 1;
	} else {
		return - 1;
	}
}

function get_stored_results() {
	$stored_results = array();
	$files          = array_diff( scandir( '.' ), array( '..', '.' ) );

	foreach ( $files as $file ) {
		if ( strpos( $file, '_ctimer.json' ) !== false ) {
			$stored_results[] = $file;
		}
	}

	return $stored_results;
}


function generate_filepicker_html( $local_jsons ) {
	$html = '';
	foreach ( $local_jsons as $json ) {
		$html .= "<li><a href='?json=$json'>$json</a></li>";
	}

	$html = '
		<h1>Select stored results file for viewing</h1>
		<p>You have launched me in folder with files, that look like stored results of my previouss runs.
		I bet you want to parse one of these?</p>
		<ul>
			' . $html . '
		</ul>
	';

	return $html;
}

class CtimerRecursiveFilterIterator extends RecursiveFilterIterator {

	protected $ignored_extensions;
	protected $ignored_paths;
	protected $errors;

	public function __construct( RecursiveIterator $recursiveIter, $ignored_extensions, $ignored_paths, &$errors ) {

		$this->ignored_extensions = $ignored_extensions;
		$this->ignored_paths      = $ignored_paths;
		$this->errors             = &$errors;
		parent::__construct( $recursiveIter );

	}

	public function getChildren() {

		return new self( $this->getInnerIterator()->getChildren(), $this->ignored_extensions, $this->ignored_paths, $this->errors );

	}

	public function accept() {

		$pathname  = $this->current()->getPathname();
		$extension = pathinfo( $pathname, PATHINFO_EXTENSION );

		if ( $this->current()->isDir() ) {

			if ( ! is_readable( $pathname ) ) {
				$this->errors[] = $pathname;

				return false;
			}
			foreach ( $this->ignored_paths as $ignored_path ) {
				if ( strpos( $pathname, strtolower( $ignored_path ) ) !== false ) {
					return false;
				}
			}
		} else if ( $this->current()->isFile() && in_array( strtolower( $extension ), $this->ignored_extensions ) ) {
			return false;
		}

		return true;
	}

}

?>
<html>
<head>
    <title>File change times - from ctime</title>
    <link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css"
          crossorigin="anonymous">
    <script src="//ajax.googleapis.com/ajax/libs/jquery/3.1.0/jquery.min.js"></script>
    <script src="//maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
			<?= $html ?>
        </div>
    </div>
</div>

</body>
</html>