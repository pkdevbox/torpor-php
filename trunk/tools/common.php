<?PHP
// $Rev$
// error_reporting( E_ALL | E_STRICT );
error_reporting( E_ALL | E_STRICT );

function parseOptions( array $defaults = null ){
	global $argv;
	$args = array();
	foreach( preg_split( '/(^-+|\s-+|\s\/)/', join( ' ', array_slice( $argv, 1 ) ) ) as $argset ){
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
		if( array_key_exists( $key, $args ) ){
			if( !is_array( $args{ $key } ) ){
				$args{ $key } = array( $args{ $key } );
			}
			$args{ $key }[] = $value;
		} else {
			$args{ $key } = $value;
		}
	}

	$keys = array_keys( $args );
	$args['help'] = (
		in_array( array( '?', 'help', '?' ), $keys )
		? true
		: false
	);
	
	if( is_array( $defaults ) ){
		foreach( $defaults as $key => $value ){
			if( !array_key_exists( $key, $args ) ){
				$args{ $key } = $value;
			}
		}
	}

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

class TorporConfigColumn {
	public $class = null;
	public $dataName = '';
	public $default = null;
	public $encoding = null;
	public $generatedOnPublish = null;
	public $length = null;
	public $name = null;
	public $nullable = true;
	public $precision = null;
	public $readOnly = null;
	public $type = '';

	public function formatColumn( $indent = "\t\t\t\t", $newLine = "\n" ){
		$columnText = $indent.'<Column';
		foreach( get_object_vars( $this ) as $varName => $value ){
			if( is_null( $value ) ){ continue; }
			if( is_bool( $value ) ){
				$value = ( $value ? 'true' : 'false' );
			}
			$columnText.= ' '.$varName.'="'.htmlentities( $value ).'"';
		}
		$columnText.= '/>'.$newLine;
		return( $columnText );
	}
}

?>
