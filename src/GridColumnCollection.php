<?PHP
// $Rev$
class GridColumnCollection extends stdClass {
	private $_filled = false;

	function __construct( array $collection ){
		foreach( $collection as $key => $value ){
			$this->$key = $value;
		}
		$this->_filled = true;
	}

	function __get( $columnName ){
		$columnName = $this->checkColumnName( $columnName );
		return( $this->$columnName );
	}

	function __set( $columnName, $value ){
		$columnName = ( $this->_filled ? $this->checkColumnName( $columnName ) : Torpor::containerKeyName( $columnName ) );
		return( $this->$columnName = $value );
	}

	function hasColumn( $columnName ){
		return(
			in_array(
				Torpor::containerKeyName( $columnName ),
				array_keys( get_object_vars( $this ) )
			)
		);
	}

	function checkColumnName( $columnName ){
		$columnName = Torpor::containerKeyName( $columnName );
		if( !$this->hasColumn( $columnName ) ){
			throw( new TorporException( $columnName.' is not a valid member of this Grid' ) );
		}
		return( $columnName );
	}
}

class GridColumnNameCollection extends GridColumnCollection {};
class GridColumnValueCollection extends GridColumnCollection {};
?>
