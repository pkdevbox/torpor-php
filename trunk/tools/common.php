<?PHP
// $Rev$
// error_reporting( E_ALL | E_STRICT );
error_reporting( E_ALL | E_STRICT );

function parseOptions( array $defaults = null ){
	$args = ( is_array( $defaults ) ? $defaults : array() );
	foreach( preg_split( '/\s*[-\/]+/', join( ' ', array_slice( $GLOBALS['argv'], 1 ) ) ) as $argset ){
		$key = null;
		$value = null;
		if( preg_match( '/[\s=]+/', $argset ) ){
			$parts = preg_split( '/[\s=]+/', $argset );
			$key = strtolower( $parts[0] );
			$value = join( ' ', array_slice( $parts, 1 ) );
		} else {
			$key = strtolower( $argset );
			$value = true;
		}
		$args{ $key } = $value;
	}

	$keys = array_keys( $args );
	$args['help'] = (
		in_array( array( '?', 'help', '?' ), $keys )
		? true
		: false
	);

	return( $args );
}

function printHeader( $file = null ){
	fwrite( ( is_null( $file ) ? STDOUT : $file ), <<<XMLHEADER
<trpr:TorporConfig
	xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	xsi:schemaLocation="http://www.tricornersoftware.com/Products/Torpor/Config/0.1 TorporConfig.xsd"
	xmlns:trpr="http://www.tricornersoftware.com/Products/Torpor/Config/0.1"
	version="0.1">
	<Options/>

XMLHEADER
	);
}

function printContents( $contents, $file = null ){
	fwrite( ( is_null( $file ) ? STDOUT : $file ), $contents );
}

function printFooter( $file = null ){
	fwrite( ( is_null( $file ) ? STDOUT : $file ), <<<XMLFOOTER
	</Grids>
</trpr:TorporConfig>

XMLFOOTER
	);
}

function validate( $infile ){
	$dom = new DOMDocument();
	$dom->load( $infile ) or die( "Could not open $infile" );
	return( $dom->schemaValidate( 'TorporConfig.xsd' ) );
}

?>
