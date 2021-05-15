<?php
/**
 * Group file change times by inode ctime
 *
 * @author  : Peeter Marvet (peeter@zone.ee)
 * Date: 15.05.2021
 * @version 1.7.0
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL
 *
 * v.1.7.0
 * - download highlighted files if running live (e.g not displaying json). MAYhem workshop special edition :)
 * v.1.6.3
 * - opening large groups that might have malicious files by default was not a good life choice
 * v.1.6.2
 * - for some reason all example.com_*.yara.log files were merged, now using only latest (by file mtime)
 * v.1.6.1
 * - include file size and md5 (for smaller php files), added is_readable check in 1.6.1
 * - support whitelist.json in form ['somemd5hash' => true,] to mute files with known good hash
 * - support example.com_*.yara.log (paths used, strings ignored) from Yara scans, to highlight found malware
 * v.1.5.2
 * - cognizant cli argument to ignore all ignores
 * v.1.5.1
 * - error message if json_decode fails
 * - tweaks to ctimer_remote (use builtin, /usr/bin/env and do not trust path)
 * - output time with seconds
 * v.1.5
 * - Bootstrap 4.5.2
 * - host in json
 * - select files and download list with paths
 * - remove shebang - our own default conf is without buffering...
 * - add hostname to json & output, move echo to third parameter
 * v.1.4
 * - add #!/usr/bin/env php to support running directly
 * - use getcwd() as cli basepath, not __DIR__
 * - avoid running as root
 * - add second parameter for json filename prefix or echo
 * - Bootstrap 3.4.1
 * v.1.3.2
 * - added JSON_PARTIAL_OUTPUT_ON_ERROR to avoid failure on malfromed UTF8 etc
 * - added support for $base_bath as 1st CLI argument
 * - use realpath() for $base_path to avoid confusing '../..' bases
 * - ... and show always as ./something e.g relative to basepath
 * - $ignored_paths are checked against path with trailing slash (so /logs/ works for files inside /logs folder
 * - changed $ignored_paths default list, added $ignored_extensions to default ignored extensions
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
$ignored_extensions = array(
	'jpg',
	'png',
	'gif',
	'pdf',
	'gz',
	'jpeg',
	'mp3',
	'mp4',
	'doc',
	'docx',
	'xls',
	'xlsx',
	'log'
);

//$ignored_extensions = array_merge( $ignored_extensions, array( 'txt', 'csv', 'js', 'css' ) );

// these locations could be technically used for malware storage, but can be mostly ignored for clarity
$ignored_paths = array(
	'/.git', // well
	'/media/product/cache/',
	'/var/cache/',
	'/var/session/',
	'/var/minifycache/',
	'/var/report/', // magento 1.x
	'/wp-content/cache/', // wp
	'/wp-content/uploads/cache/wpml/twig/', // wpml
	'/administrator/cache/', // joomla
	// '/cache',
	// zone.eu specific locations outside docroots
	'/stats/',
	'/logs/',
	'/phpini/',
);

// ctimer is usually launched from the root of website - provide relative path to check something else

$base_path = realpath( '.' );

$allow_download = false;

$errors = array();

if ( php_sapi_name() === 'cli' ) {

	if ( function_exists( 'posix_geteuid' ) && posix_geteuid() === 0 ) {
		die( "Cowardly refusing to run as root :(" . PHP_EOL );
	}

	if ( ! empty( $argv[1] ) ) {
		if ( is_readable( $argv[1] ) ) {
			$base_path = realpath( $argv[1] );
		} else {
			die( "{$argv[1]} is not readable." . PHP_EOL );
		}
	} else {
		$base_path = getcwd();
	}

	// preferably second parameter - but could be path or random
	$prefix = ! empty( $argv[2] ) ? $argv[2] : ( ! empty( $argv[1] ) ? $argv[1] : substr( md5( rand() ), 0, 8 ) );

	// we need better processing of cli arguments
	if ( in_array( 'cognizant', $argv ) ) {
		$ignored_extensions = array();
		$ignored_paths      = array();
	}

	$ctimes_grouped = get_grouped_ctimes();

	if ( ! empty( $argv[3] ) && $argv[3] === 'echo' ) {

		echo generate_json( $ctimes_grouped, $prefix );

	} else {

		$prefix = preg_replace( '~[^a-z0-9-_]+~i', '', strtolower( $prefix ) );

		$filename = $prefix . '_' . date( "Y-m-d_H-i" ) . '_ctimer.json';

		file_put_contents( $filename, generate_json( $ctimes_grouped, $prefix ) );

		echo "Ignored extensions: " . implode( ', ', $ignored_extensions ) . PHP_EOL;
		echo "Ignored paths: " . implode( ', ', $ignored_paths ) . PHP_EOL . PHP_EOL;
		echo "File change times saved to $filename" . PHP_EOL . PHP_EOL;

	}

	exit();
} else {

	if ( filectime( __FILE__ ) < time() - 60 * 60 * 24 ) {

		unlink( __FILE__ );

		header( "HTTP/1.0 404 Not Found" );

		echo "<pre>I should not be forgotten on server - so I removed myself from the equation.</pre>";
		die();
	}

	if ( basename( $_SERVER["SCRIPT_FILENAME"], '.php' ) === 'ctimer' ) {

		$new_name = 'ctimer_' . substr( md5( rand() ), 0, 8 ) . '.php';

		rename( basename( __FILE__ ), $new_name );

		header( "HTTP/1.0 404 Not Found" );
		echo "<pre>I should not be available on predictable address - so I renamed myself, new name is: <a href='$new_name'>$new_name</a>" . PHP_EOL . "It might be good idea to bookmark it for future use :-)</pre>";
		die();

	}
}

$local_jsons = get_stored_results();

if ( ! empty( $local_jsons ) && empty( $_GET['json'] ) && ! isset( $_GET['live'] ) ) {
	$html = generate_filepicker_html( $local_jsons );
} else {

	if ( $allow_download === true && ! empty( $_POST["files"] ) ) {

		$files = explode( "\n", $_POST["files"] );

		ini_set( 'open_basedir', $base_path );

		$zip      = new ZipArchive();
		$zip_name = $_SERVER['SERVER_NAME'] . '_sample_' . date( 'Y-m-d_H-i' ) . '.zip';
		$zip->open( $base_path . '/' . $zip_name, ZipArchive::CREATE );

		$contents = array();

		foreach ( $files as $file ) {

			$file = trim( $file );

			if ( ! empty( $file ) ) {

				$file = realpath( $file );

				$contents[] = $file;

				if ( is_readable( $file ) && is_file( $file ) && basename( $file ) !== 'wp-config.php' ) {

					$filename_parts = pathinfo( $file );
					$uniqname       = $filename_parts['filename'] . '_' . substr( md5_file( $file ), 0, 8 );

					if ( $filename_parts['basename'] === '.htaccess' ) {
						$uniqname = $filename_parts['basename'] . $uniqname . '.txt'; // for easier opening / previewing
					} else {
						if ( ! empty( $filename_parts['extension'] ) ) {
							$uniqname .= '.' . $filename_parts['extension'];
						}
					}

					$zip->addFile( $file, $uniqname );
				}
			}
		}

		$zip->addFromString( '_meta/paths.txt', implode( "\n", $contents ) );

		$zip->close();

		header( 'Content-Type: application/zip' );
		header( 'Content-disposition: attachment; filename=' . $zip_name );
		header( 'Content-Length: ' . filesize( $base_path . '/' . $zip_name ) );
		readfile( $base_path . '/' . $zip_name );

		//unlink( $base_path . '/' . $zip_name );

		die();
	}

	if ( ! isset( $_GET['json'] ) ) {

		$host                = $_SERVER['SERVER_NAME'];
		$file_ctimes_grouped = get_grouped_ctimes();
		$html                = generate_ctimes_html( $file_ctimes_grouped );

	} else if ( empty( $_GET['json'] ) ) {

		$filename = preg_replace( '~[^a-z0-9-_]+~i', '', str_replace( '.', '_', strtolower( $_SERVER['SERVER_NAME'] ) ) ) . '_' . date( "Y-m-d_H-i" ) . '_ctimer.json';
		header( 'Content-type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . basename( $filename ) . '"' );
		header( 'Content-Transfer-Encoding: binary' );

		$file_ctimes_grouped = get_grouped_ctimes();

		echo generate_json( $file_ctimes_grouped );
		die();

	} else {

		$filename = basename( $_GET['json'] );

		if ( file_exists( $filename ) ) {

			$file = file_get_contents( $filename );
			$json = json_decode( $file, true );

			if ( is_null( $json ) ) {
				header( "HTTP/1.0 500 Internal Server Error" );
				echo "JSON decode failed";
				die();
			}

			if ( isset( $json['ctimes'] ) ) {

				$file_ctimes_grouped = &$json['ctimes'];
				$errors              = $json['errors'];
				$ignored_extensions  = $json['ignored_extensions'];
				$ignored_paths       = $json['ignored_paths'];
				$base_path           = $json['base_path'];
				$host                = ! empty( $json['host'] ) ? $json['host'] : 'unknown';

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

function generate_json( $file_ctimes_grouped, $host = null ) {

	global $ignored_extensions, $ignored_paths, $base_path, $errors;

	if ( is_null( $host ) ) {
		$host = $_SERVER['SERVER_NAME'];
	}

	$json = array(
		'ctimes'             => $file_ctimes_grouped,
		'ignored_extensions' => $ignored_extensions,
		'ignored_paths'      => $ignored_paths,
		'errors'             => $errors,
		'base_path'          => $base_path,
		'host'               => $host,
		'cognizant'          => ( empty( $ignored_extensions ) && empty( $ignored_paths ) ) ? true : false,
	);

	if ( phpversion() >= '5.4' ) {
		return json_encode( $json, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR );
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
			$filename = $x->getPathname();
			$ctime    = filectime( $filename );
			$size     = filesize( $filename );

			if ( $size !== false
			     && $size < 524288
			     && (
				     in_array( pathinfo( $filename, PATHINFO_EXTENSION ), array(
					     'php',
					     'inc',
					     'txt',
					     'json',
					     'css',
					     'scss',
					     'js',
					     'po',
					     'mo'
				     ) )
				     || preg_match( '(wp-admin|wp-includes)', $filename ) === 1
			     )
			     && is_readable( $filename )
			) {
				$md5 = md5_file( $filename );
			} else {
				$md5 = '';
			}

			$file_ctimes[ $ctime ][] = array(
				"name"  => str_replace( $base_path, '.', $filename ),
				'ctime' => $ctime,
				'size'  => $size,
				'md5'   => $md5
			);
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

function yara_file( $line ) {

	if ( empty( trim( $line ) ) || stripos( $line, '0x' ) === 0 ) {
		return false;
	}

	return true;
}


function generate_ctimes_html( $file_ctimes_grouped ) {

	global $ignored_extensions, $ignored_paths, $base_path, $host, $errors, $allow_download;

	if ( is_readable( 'whitelist.json' ) ) {
		$whitelist = json_decode( file_get_contents( 'whitelist.json' ), true );
	} else {
		$whitelist = [];
	}

	$yara_scans = glob( "{$host}_*.yara.log", GLOB_BRACE );
	usort( $yara_scans, function ( $a, $b ) {
		return filemtime( $a ) - filemtime( $b );
	} );

	$blacklist = [];

	if ( ! empty( $yara_scans ) ) {
		$yara_scan = array_pop( $yara_scans );
		$lines     = array_filter( explode( "\n", file_get_contents( $yara_scan ) ), 'yara_file' );
		foreach ( $lines as $line ) {
			$parts = explode( ' ', $line, 2 );
			// giving /-terminated path to yara causes // in output
			$path = str_replace( '//', '/', $parts[1] );
			$path = str_replace( $base_path, '.', $path );

			if ( ! isset( $blacklist[ $path ][ $parts[0] ] ) ) {
				$blacklist[ $path ][ $parts[0] ] = true;
			}
		}
	}

	//var_dump($blacklist); die();

	$html           = "";
	$panel_id       = 0;
	$all_detections = [];

	foreach ( $file_ctimes_grouped as $time => $files ) {

		usort( $files, 'name_sort' );

		$i = 0;

		$fragment         = "";
		$fragment_date    = "";
		$all_clean        = true; // all files in group have been whitelisted
		$some_bad         = false; // some files in group have been detected
		$group_detections = [];

		foreach ( $files as $file ) {

			$detections = '';


			if ( ! empty( $whitelist ) && ! empty( $file['md5'] ) && isset( $whitelist[ $file['md5'] ] ) ) {
				$file_class = 'path text-secondary';
			} else {
				$file_class = 'path';
				$all_clean  = false;

				if ( ! empty( $blacklist ) && isset( $blacklist[ $file['name'] ] ) ) {
					$some_bad         = true;
					$file_class       = 'path text-danger';
					$detections_names = array_keys( $blacklist[ $file['name'] ] );
					$detections       = '<strong>ZARA:</strong> ' . implode( ', ', $detections_names );
					$group_detections = array_merge( $group_detections, $detections_names );

				}

			}

			$file_date = date( "Y-m-d H:i:s", $file['ctime'] );
			$fragment  .= $file_date . ' - <span class="' . $file_class . '">' . ltrim( $file['name'], './' ) . '</span> ' . $detections . PHP_EOL;

			if ( empty( $fragment_date ) || $file_date < $fragment_date ) {
				$fragment_date = $file_date;
			}
			$i ++;
		}

		if ( ( $i >= 50 || $all_clean ) ) {
			$collapse_class = "collapse";
		} else {
			$collapse_class = "collapse show";
		}

		$html .= '<div class="card mb-1">';

		$html .= "<div class=\"card-header\" role=\"tab\" id=\"heading_$panel_id\">";
		// add data-parent="#accordion" to have other panels collapse when opening (presumably not desired here)
		$html .= "<a class=\"text-dark\" role=\"button\" data-toggle=\"collapse\" href=\"#collapse_$panel_id\" aria-expanded=\"true\" aria-controls=\"collapse_$panel_id\">$fragment_date - <strong>$i files changed</strong></a>";
		if ( count( $group_detections ) > 0 ) {
			$html .= ' <strong>ZARA:</strong> ' . implode( ', ', array_unique( $detections_names ) );
		}
		$html .= "</div>";

		$html .= "<div id=\"collapse_$panel_id\" class=\"panel-collapse $collapse_class\" role=\"tabpanel\" aria-labelledby=\"heading_$panel_id\">";
		$html .= '<div class="card-body">';
		$html .= "<pre class='mb-0'>$fragment</pre>";
		$html .= "</div>";
		$html .= "</div>";

		$html .= "</div>";

		$panel_id ++;
		$all_detections = array_merge( $all_detections, $group_detections );
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
                <p class="small"><strong>Ignored extensions:</strong> ' . implode( ', ', $ignored_extensions ) . '<br><strong>Ignored paths:</strong> ' . implode( ', ', $ignored_paths ) . '</p>
                <p class="small"><strong>Host:</strong> ' . $host . '<br>
                <strong>Base path:</strong> ' . $base_path . '</p>
            ';

	if ( ! empty( $errors ) ) {
		$optional .= '
                <p class="alert alert-warning" role="alert"><strong>Inaccessible folders:</strong> ' . implode( ', ', $errors ) . '</p>
            ';
	}

	if ( ! empty( $all_detections ) ) {
		$optional .= '
                <p class="alert alert-warning" role="alert"><strong>Matched ZARA rules:</strong> ' . implode( ', ', array_unique( $all_detections ) ) . '</p>
            ';
	}

	if ( $allow_download ) {
		$downloader = '<button class="btn btn-secondary files" type="submit">Download files!</button>';
	} else {
		$downloader = '<button class="btn btn-secondary list">Get the list!</button>';
	}

	$downloader = '<span class="download d-none">' . $downloader . '</span>';

	$html = '
                <form id="fetch" method="POST"><input type="hidden" id="files" name="files" value="">
				<h1 class="pt-3">What has changed? ' . $downloader . '</h1>
				<p>This script groups files by inode <code>ctime</code> - unlike <code>mtime</code> that
					can be changed to whatever user pleases, this is set by kernel. Meaning we can trust it
					(if we trust kernel, of course :-). Some paths may be excluded (cache?), please check the code.
					If you want to keep this script in server, please give it some random name - to make discovery by
					malicious scanners difficult (and also, please check the code - if kept on server it is very easy to
					include malware in excludes).
				</p>' . $optional . '
				<div id="accordion" role="tablist" aria-multiselectable="true">
					' . $html . ' 
				</div>
				<p>' . $downloader . '</p>
                </form>
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
				if ( strpos( $pathname . '/', strtolower( $ignored_path ) ) !== false ) {
					return false;
				}
			}
		} else if ( $this->current()->isFile() && in_array( strtolower( $extension ), $this->ignored_extensions ) ) {
			return false;
		}

		return true;
	}

}

?><!DOCTYPE html>
<html lang="en">
<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"
          integrity="sha384-JcKb8q3iqJ61gNV9KGb8thSsNjpSL0n8PARn9HuZOnIxN0hoP+VmmDGMN5t9UJ0Z" crossorigin="anonymous">

    <title>File change times - from ctime</title>
    <style>
        .ioc {
            color: #fff;
            background-color: #e83e8c;
        }
    </style>

</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col">
			<?= $html ?>
        </div>
    </div>
</div>
<!-- jQuery first, then Popper.js, then Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"
        integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj"
        crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"
        integrity="sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN"
        crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"
        integrity="sha384-B4gt1jrGC7Jh4AgTPSdUtOBvfO8shuf57BaghqFfPlYxofvL8/KUEfYiJOMMV+rV"
        crossorigin="anonymous"></script>
<script>
    $(document).ready(function () {

        var base_path = "<?=  $base_path . "/" ?>";
        var host = "<?=  $host ?>"

        $(".path").on("click", function () {
            $(this).toggleClass("ioc");
            $('.download').removeClass('d-none');
        });

        $(".download button.list").on("click", function () {

            var files = "";

            $('.ioc').each(function () {
                files += base_path + $(this).text() + "\n";
            });

            download(host, files);
        });

        $(".download button.files").on("click", function () {

            var files = "";

            $('.ioc').each(function () {
                files += base_path + $(this).text() + "\n";
            });

            $('#files').val(files);
        });

        function download(filename, text) {

            // https://ourcodeworld.com/articles/read/189/how-to-create-a-file-and-generate-a-download-with-javascript-in-the-browser-without-a-server

            var element = document.createElement('a');
            element.setAttribute('href', 'data:text/plain;charset=utf-8,' + encodeURIComponent(text));
            element.setAttribute('download', filename);

            element.style.display = 'none';
            document.body.appendChild(element);

            element.click();

            document.body.removeChild(element);
        }

    });
</script>
</body>
</html>