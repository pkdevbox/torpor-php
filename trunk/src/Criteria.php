<?PHP // $Rev$
// TODO: phpdoc
class Criteria {
	// Modifier
	const NOT = 'NOT';
	const TYPE_BETWEEN     = 'BETWEEN';
	const TYPE_CONTAINS    = 'CONTAINS';
	const TYPE_CUSTOM      = 'CUSTOM';
	const TYPE_ENDSWITH    = 'ENDSWITH';
	const TYPE_EQUALS      = 'EQUALS';
	const TYPE_GREATERTHAN = 'GREATHERTHAN';
	const TYPE_IN          = 'IN';
	const TYPE_IN_SET      = 'IN_SET';
	const TYPE_LESSTHAN    = 'LESSTHAN';
	const TYPE_MATCHES     = self::TYPE_EQUALS;
	const TYPE_PATTERN     = 'PATTERN';
	const TYPE_STARTSWITH  = 'STARTSWITH';

	private $_not = false;
	private $_gridName;
	private $_columnName;
	private $_type;
	private $_custom;
	private $_args = array();

	public function Criteria( $gridName = null, $columnName = null, $type = null ){
		if( !empty( $gridName ) ){ $this->setGridName( $gridName ); }
		if( !empty( $columnName ) ){ $this->setcolumnName( $columnName ); }
		if( !empty( $type ){ $this->setType( $type ); }
		// WARNING: Magic Number 3
		if( func_num_args() > 3 && !is_null( $this->getType() ) ){
			// TODO: Need a better name thaN "processArgs" that should better
			// reflect the conditionals being passed in (and their potentially
			// mixed flags and settings) and be publicly accessible.
			// TYPE_BETWEEN takes 2-3 args: Low, High, Inclusive
			// TYPE_CONTAINS takes 1-2 args: string, case-sensitive
			// Ditto for ENDSWITH, EQUALS, IN, and STARTSWITH (and the alias MATCHES)
			// TYPE_CUSTOM takes 1 arg: value.
			$this->processArgs( array_slice( func_get_args(), 3 ) );
		}
	}

	public function getGridName(){ return( $this->_gridName ); }
	public function setGridName( $gridName ){
		return( $this->_gridName = Torpor::containerKeyName( $gridName ) );
	}

	public function getColumnName(){ return( $this->_columnName ); }
	public function setColumnName( $columnName ){
		return( $this->_columnName = Torpor::containerKeyName( $columnName ) );
	}

	public function getType(){ return( $this->_type ); }
	public function setType( $type ){
		if( strpos( $type, self::NOT ) === 0 ){
			$this->_not = true;
			$type = substr( $type, strlen( self::NOT ) );
		} else {
			$this->_not = false;
		}
		if( !in_array( $this->getValidTypes ) ){
			throw( new TorporException( '"'.$type.'" is not a valid type' ) );
		}
		return( $this->_type = $type );
	}

	public function getCustom(){ eturn( $this->getType() == self::TYPE_CUSTOM ? $this->_custom : null ); }
	public function setCustom( $custom ){
		$return = false;
		if( !empty( $custom ) ){
			$this->setType( self::TYPE_CUSTOM );
			$return = $this->_custom = $custom;
		}
		return( $return );
	}

	public static function getValidTypes(){
		return( 
			array(
					self::TYPE_BETWEEN,
					self::TYPE_CONTAINS,
					self::TYPE_CUSTOM,
					self::TYPE_ENDSWITH,
					self::TYPE_EQUALS,
					self::TYPE_GREATERTHAN,
					self::TYPE_IN,
					self::TYPE_IN_SET,
					self::TYPE_LESSTHAN,
					self::TYPE_MATCHES,
					self::TYPE_PATTERN,
					self::TYPE_STARTSWITH
				)
			);
		}
	}
?>
