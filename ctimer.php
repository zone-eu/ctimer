<?php
/**
 * Group file change times by inode ctime
 * User: petskratt (peeter@zone.ee)
 * Date: 23.08.2016
 * Time: 13:245
 * v.1.2
 * - fixed json creation
 * - added possibility to read from json files
 * - this "quick hack" is becoming quite messy - but it works and saves lives, so refactoring can wait
 * v.1.1
 * - added auto-renamer
 * v1.0
 * - initial version
 */


if ( basename( __FILE__, '.php' ) === 'ctimer' ) {

	$new_name = 'ctimer_' . substr( bin2hex( mcrypt_create_iv( 22, MCRYPT_DEV_URANDOM ) ), 0, 8 ) . '.php';

	rename( basename( __FILE__ ), $new_name );

	header( "HTTP/1.0 404 Not Found" );
	echo "I should not be available on predictable address - so I renamed myself, new name is <a href='$new_name'>$new_name</a>. It might be good idea to bookmark it for future use :-)";
	die();

}

$local_jsons = get_stored_results();

if ( ! empty( $local_jsons ) && empty( $_GET['json'] ) && ! isset( $_GET['live'] ) ) {
	$html = generate_filepicker_html( $local_jsons );
} else {

	$file_ctimes_grouped = get_grouped_ctimes();

	if ( ! isset( $_GET['json'] ) ) {
		$html = generate_ctimes_html( $file_ctimes_grouped );
	} else if ( empty( $_GET['json'] ) ) {
		$filename = preg_replace( '~[^a-z0-9-_]+~i', '', str_replace( '.', '_', strtolower( $_SERVER['SERVER_NAME'] ) ) ) . '_' . date( "Y-m-d_H-i" ) . '_ctimer.json';
		header( 'Content-type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . basename( $filename ) . '"' );
		header( 'Content-Transfer-Encoding: binary' );
		echo json_encode( $file_ctimes_grouped );
		die();
	} else {
		$filename = basename( $_GET['json'] );
		if ( file_exists( $filename ) ) {
			$json                = file_get_contents( $filename );
			$file_ctimes_grouped = json_decode( $json, true );
			$html                = generate_ctimes_html( $file_ctimes_grouped );
		} else {
			header( "HTTP/1.0 404 Not Found" );
			echo "Requested .json not found";
			die();
		}
	}
}

?>
	<html>
	<head>
		<title>File change times - from ctime</title>
		<link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" crossorigin="anonymous">
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
<?php

function get_grouped_ctimes() {
	$file_ctimes = array();

	foreach ( new RecursiveIteratorIterator ( new RecursiveDirectoryIterator ( '.' ), RecursiveIteratorIterator::CHILD_FIRST ) as $x ) {
		$ctime       = filectime( $x->getPathname() );
		$round_ctime = (int) round( $ctime, - 3 );

		if ( is_file( $x->getPathname() ) && strpos( $x->getPathname(), 'wp-content/cache' ) === false ) {
			// relative path is easier to view...
			// $file_ctimes[$round_ctime][] = [ "name" => realpath( $x->getPathname() ), 'ctime' => $ctime ];
			$file_ctimes[$round_ctime][] = array( "name" => $x->getPathname(), 'ctime' => $ctime );
		}

	}

	$file_ctimes_grouped = array();

	ksort( $file_ctimes );

	$previous = null;
	foreach ( $file_ctimes as $key => $value ) {
		if ( ! is_null( $previous ) && $key == $previous + 1000 ) {

			$file_ctimes_grouped[$key] = array_merge( $file_ctimes_grouped[$previous], $file_ctimes[$key] );

			unset ( $file_ctimes_grouped[$previous] );
		} else {
			$file_ctimes_grouped[$key] = $file_ctimes[$key];
		}

		$previous = $key;

	}

	krsort( $file_ctimes_grouped );

	return $file_ctimes_grouped;
}

function generate_ctimes_html( $file_ctimes_grouped ) {
	$html     = "";
	$panel_id = 0;

	foreach ( $file_ctimes_grouped as $time => $files ) {

		usort( $files, 'name_sort' );

		$i = 0;

		$fragment      = "";
		$fragment_date = "";

		foreach ( $files as $file ) {

			$file_date = date( "Y-m-d H:i", $file['ctime'] );
			$fragment .= $file_date . ' - ' . $file['name'] . PHP_EOL;

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

		$html .= '<div class="panel-heading" role="tab" id="heading_$panel_id">';
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

	if (!isset($_GET['json'])) {
		$optional = '
						<p>If you want to store the result for future (forensic) use,
					<a href="?json">download it as .json</a>.</p>
		';
	} else {
		$optional = '
						<p><a href="?json">Check another stored file?</a></p>
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
				</p>
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