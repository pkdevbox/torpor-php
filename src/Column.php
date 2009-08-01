<?PHP
// $Rev$
// TODO: phpdoc
// TODO: Callbacks - function hooks easily overridden by inheriting classes
// that provide a clear set of opportunities, like "initialize()", "beforeSetData()",
// "afterSetData()", "beforeGetData()", "afterGetData()", "beforePublish()",
// etc.  Maybe not those exactly, but something along those lines that allows
// a developer to interfere with the stream without having to plug into the
// parent::setData() interface all the time (something easier for them to
// meddle with).
class Column extends PersistableContainer
{
	const TYPE_BINARY           = 'BINARY';
	const TYPE_BOOL             = 'BOOL';
	const TYPE_CHAR             = 'CHAR';
	const TYPE_CLASS            = 'CLASS';
	const TYPE_DATE             = 'DATE';
	const TYPE_DATETIME         = 'DATETIME';
	const TYPE_DOUBLE           = self::TYPE_FLOAT;
	const TYPE_FLOAT            = 'FLOAT';
	const TYPE_INTEGER          = 'INTEGER';
	const TYPE_INT              = self::TYPE_INTEGER;
	const TYPE_NUMBER           = self::TYPE_FLOAT;
	const TYPE_TEXT             = 'TEXT';
	const TYPE_TIME             = 'TIME';
	const TYPE_UNSIGNED_INTEGER = 'UNSIGNED';
	const TYPE_UNSIGNED_INT     = self::TYPE_UNSIGNED_INTEGER;
	const TYPE_UNSIGNED         = self::TYPE_UNSIGNED_INT;
	const TYPE_VARCHAR          = 'VARCHAR';

	// Utilities
	const REGEX_DATE = '\d{4}([,\.\-\/]?\d{2}){2}';
	const REGEX_TIME = '[01][0-9]([,:\-][0-5][0-9]){2}';

	const ATTRIBUTE_GENERATED_ON_PUBLISH = 'generatedOnPublish';

	private $_grid; // Grid object to which this belongs.

	private $_type = self::TYPE_VARCHAR;
	private $_encoding = null;
	private $_length = -1;
	private $_precision = 0;
	private $_isNullable = true;
	private $_isReadOnly = false;
	private $_generatedOnPublish = false;
	private $_attributes = array();

	private $_linkedColumn = null;
	private $_linkedColumnContinual = true;

	private $_originalData = null;
	private $_data = null;

	public function Column( Torpor $torpor, $name = null, SimpleXMLElement $xmlDef = null ){
		parent::__construct( $torpor, $name );
		if( $xmlDef instanceof SimpleXMLElement ){
			$this->initialize( $xmlDef );
		}
	}

	public function initialize( SimpleXMLElement $xmlDef ){
		// TODO: Loaded primary keys should be read only?
		// TODO: Build a local attributes interface in order to keep settings conveniently
		// accessible to inheriting classes.
		$this->_attributes = array();
		$type = Torpor::makeKeyName( (string)$xmlDef->attributes()->type );
		if( !in_array( $type, $this->getValidTypes() ) ){
			$this->throwException( 'Unrecognized type "'.$type.'"' );
		}
		$this->setType( $type );
		switch( $type ){
			case self::TYPE_BINARY:
				if( (int)$xmlDef->attributes()->length ){
					$this->setMaxLength( (int)$xmlDef->attributes()->length );
				}
				break;
			case self::TYPE_BOOL:
				break;
			case self::TYPE_CLASS:
				break;
			case self::TYPE_DATE:
			case self::TYPE_DATETIME:
			case self::TYPE_TIME:
				break;
			case self::TYPE_FLOAT:
			case self::TYPE_INTEGER:
			case self::TYPE_UNSIGNED_INTEGER:
				if( (int)$xmlDef->attributes()->precision ){
					$this->setMaxLength( (int)$xmlDef->attributes()->precision );
				}
				if( (int)$xmlDef->attributes()->length ){
					$this->setMaxLength( (int)$xmlDef->attributes()->length );
				}
				break;
			case self::TYPE_CHAR:
			case self::TYPE_VARCHAR:
			case self::TYPE_TEXT:
				if( (string)$xmlDef->attributes()->encoding ){
					$this->setEncoding( (string)$xmlDef->attributes()->encoding );
				}
				if( (int)$xmlDef->attributes()->length ){
					$this->setMaxLength( (int)$xmlDef->attributes()->length );
				}
				break;
		}

		if( (string)$xmlDef->attributes()->generatedOnPublish == Torpor::VALUE_TRUE ){
			$this->setGeneratedOnPublish();
		}
		// TODO: options for navigating attributes
		foreach( $xmlDef->attributes() as $key => $val ){
			$this->_attributes[ $key ] = (string)$val;
		}
	}

	public function isGeneratedOnPublish(){ return( $this->_generatedOnPublish ); }
	public function setGeneratedOnPublish( $bool = true ){
		return( $this->_generatedOnPublish = ( $bool ? true : false ) );
	}

	public function Grid(){ return( $this->getGrid() ); }
	public function getTable(){ return( $this->getGrid() ); }
	public function getGrid(){ return( $this->_grid ); }
	public function __call( $func, $args ){
		if( $this->Torpor()->makeKeyName( $func ) == $this->getGrid()->_getObjName() ){
			return( $this->getGrid() );
		} else {
			$this->throwException( 'Unrecognized method "'.$func.'" on Column '.$this->_getObjName().' in Grid '.$this->getGrid()->_getObjName() );
		}
	}
	// Should only be used in the context of Grid::addColumn( $this ), in order
	// to keep back references accurate.  Mess with this at your own peril.
	public function setGrid( Grid $grid = null ){ return( $this->_grid = $grid ); }

	public static function getValidTypes(){
		return(
			array(
				self::TYPE_BINARY,
				self::TYPE_BOOL,
				self::TYPE_CHAR,
				self::TYPE_CLASS,
				self::TYPE_DATE,
				self::TYPE_DATETIME,
				self::TYPE_FLOAT,
				self::TYPE_INTEGER,
				self::TYPE_TEXT,
				self::TYPE_TIME,
				self::TYPE_UNSIGNED_INTEGER,
				self::TYPE_VARCHAR
			)
		);
	}
	public function getType(){ return( $this->_type ); }
	public function setType( $type ){
		if( !in_array( $type, $this->getValidTypes() ) ){
			$this->throwException( $type.' is not a valid type' );
		}
		// TODO: Need to validate existing contents during conversion, throw
		// conversion warnings as necessary.
		$this->_type = $type;
	}

	public function getEncoding(){ return( $this->_encoding ); }
	public function setEncoding( $encoding ){
		// TODO: Not yet supported, need an encoding scheme.
		$this->_encoding = $encoding;
	}

	public function getMaxLength(){ return( $this->_length ); }
	public function setMaxLength( $length ){
		if( !( (int)$length ) ){
			$this->throwException( 'Length must be a non-zero integer' );
		}
		$this->_length = (int)$length;
	}

	public function getPrecision(){ return( $this->_precision ); }
	public function setPrecision( $precision ){
		if(
			!in_array(
				$this->getType(),
				array(
					self::TYPE_FLOAT,
					self::TYPE_INT,
					self::TYPE_UNSIGNED
				)
			)
		){
			trigger_error( 'Precision is only valid in numeric operations', E_USER_WARNING );
		}
		$this->_precision = (int)$precision;
	}

	public function isNullable(){ return( $this->_isNullable ); }
	public function setNullable( $bool = true ){ return( $this->_isNullable = ( $bool ? true : false ) ); }

	public function isReadOnly(){ return( $this->_isReadOnly ); }
	public function setReadOnly( $bool = true ){ return( $this->_isReadOnly = ( $bool ? true : false ) ); }

	// TODO: Need protection from recursion, and possibly a setting to govern
	// behavior - either return false as soon as the recursion is detected, or
	// throw an exception.
	public function hasData( $localOnly = false ){
		$return = false;
		if( $this->isLinked() && !$localOnly ){
			$return = $this->getLinkedColumn()->hasData();
		} else {
			$return = ( $this->isLoaded() || $this->isDirty() );
		}
		return( $return );
	}

	public function getOriginalData(){ return( $this->_originalData ); }
	protected function setOriginalData( $data ){ return( $this->_originalData = $data ); }

	public function __toString(){ return( $this->getData() ); }
	public function getData( $localOnly = false ){
		if( $this->isLinked() && !$localOnly ){
			// TODO: What to do if the linked column no longer exists?
			if( $this->getLinkedColumn()->hasData() ){
				$this->setData( $this->getLinkedColumn()->getData() );
				if( !$this->isLinked( true ) ){
					$this->destroyLink();
				}
			}
		} else {
			// TODO: WARNING: What to do in the case that Grid is already
			// loaded?  Should we care?  This answer determines whether or
			// not we throw an exception (if grid is loaded and we're not),
			// and/or whether we call Load() with refresh set to true.
			// Only load in the event that we don't already contain data?
			if(
				!$this->isLoaded()
				&& !$this->isDirty()
				&& $this->getGrid() instanceof Grid
			){
				$this->getGrid()->Load();
			}
		}
		return( $this->_data );
	}

	public function Load( $refresh = false ){
		$return = false;
		if( !$this->isLoaded() || $refresh ){
			$return = $this->getGrid()->Load( $refresh );
		}
		return( $return );
	}
	// This should ONLY EVER be used by Grids loading data.  If PHP had
	// support for Friend classes, this would be marked as protected.  As it
	// is here, this is volatile since it sets stateful data and bypasses
	// constraint checking (since we expect data coming straight from the
	// store to be compliant with the constraints, which come from the store
	// definition.
	public function setLoadData( $data ){
		$this->_setLoaded();
		$this->_data = $data;
		if( $this->asClass() ){
			$this->setClassData( $data );
		}
	}

	// If $continual is set to false, as soon as valid data has been
	// retrieved from the linked column the link is severed.
	public function linkToColumn( Column $column, $continual = true ){
		$this->_linkedColumnContinual = $continual;
		return( $this->_linkedColumn = $column );
	}

	public function severLink(){ return( $this->destroyLink() ); }
	public function destroyLink(){
		$return = false;
		if( $this->isLinked() ){
			$this->_linkedColumn = null;
			$return = true;
		}
		return( $return );
	}

	public function isLinked( $continualCheck = false ){
		$return = false;
		if( $continualCheck ){
			if( $this->isLinked() && $this->_linkedColumnContinual ){
				$return = true;
			}
		} else {
			$return = ( !is_null( $this->_linkedColumn ) && $this->_linkedColumn instanceof Column ? true : false );
		}
		return( $return );
	}

	public function getLinkedColumn(){
		$return = false;
		if( $this->isLinked() ){
			$return = $this->_linkedColumn;
		}
		return( $return );
	}

	// Returns bool to indicate whether data has changed.
	public function setData( $data ){
		// TODO: Need a way to set data on an unloaded object without
		// causing it to getData(), in the event that we're working on
		// a new object and have nothing to actually fetch.
		// TODO: Loaded primary keys should be read only
		if( $this->isReadOnly() ){
			$this->throwException( $this->_getObjName().' is Read Only' );
		}
		$return = false;
			// Do all necessary validation, warnings, and conversion.
		if( is_null( $data ) ){
			if( !$this->isNullable() ){
				$this->throwException( $this->getDBName().' is not nullable' );
			}
		} else {
			switch( $this->getType() ){
				// TODO: Type-specific validation
				case self::TYPE_BINARY:
					// TODO: Need binary save maxLength checking.
					break;
				case self::TYPE_BOOL:
					$data = ( $data ? true : false );
					break;
				case self::TYPE_CLASS: break; // No checking on class data.
				case self::TYPE_DATE:
					if( !preg_match( $data, '/^'.self::REGEX_DATE.'$/' ) ){
						$this->throwException( 'Invalid date specified "'.$data.'"' );
					}
					break;
				case self::TYPE_DATETIME:
					if( !preg_match( $data, '/^'.self::REGEX_DATE.'\s?'.self::REGEX_TIME.'$/' ) ){
						$this->throwException( 'Invalid datetime specified "'.$data.'"' );
					}
					break;
				case self::TYPE_TIME:
					if( !preg_match( $data, '/^'.self::REGEX_TIME.'$/' ) ){
						$this->throwException( 'Invalid time specified "'.$data.'"' );
					}
					break;
				case self::TYPE_FLOAT:
					if( !is_numeric( $data ) ){ $this->throwException( 'Non-numeric data passed to float' ); }
					$newData = (float)round( $data, $this->getPrecision() );
					if( ( $newData - (float)$data ) != 0 ){
						trigger_error( 'Truncating float data to precision '.$this->getPrecision(), E_USER_WARNING );
					}
					$data = $newData;
					break;
				case self::TYPE_UNSIGNED:
					if( (int)$data < 0 ){
						$this->throwException( 'Unsigned integer must be greater than or equal to zero' );
					}
				case self::TYPE_INT:
					if( !is_numeric( $data ) ){ $this->throwException( 'Non-numeric data passed to integer' ); }
					$newData = (int)round( $data, $this->getPrecision() );
					if( ( $newData - (int)$data ) != 0 ){
						trigger_error( 'Truncating int data to precision '.$this->getPrecision() );
					}
					// TODO: Look at length for no. of bytes in this int, and truncation to that size.
					$data = $newData;
					break;
				case self::TYPE_CHAR:
				case self::TYPE_TEXT:
				case self::TYPE_VARCHAR:
					// TODO: Warning should be based on character encoding and data type and be binary safe.
					if( strlen( $data ) > $this->getMaxLength() ){
						trigger_error( 'Character data truncated', E_USER_WARNING );
						$data = substr( $data, 0, $this->getMaxLength() );
					}
					break;
				default:
					// TODO: Throw an error?  How could we ever actually get here?
					break;
			}
		}

		// Necessary to directly access the member variable, since there's a chance
		// we're already in a getData() call doing just-in-time population and don't
		// want to fall into an infinite loop.
		if(
			$this->isLoaded()
			&& !$this->isDirty()
		){
			$this->setOriginalData( $this->_data );
		}

		if( $data == $this->getData( true ) ){
			// If we're setting a null value, even though it's identical to what's
			// already there it's now because of our intent rather than our convenience
			// that the data is as it is.  Thus it is only appropriate to indicate that
			// we have set the current state, even though the data hasn't changed (this
			// is especially useful for unique key column combinations of which one or
			// more members may be null and still valid, whereupon the data will not
			// yet have loaded but we still need to set ourselves as updated so the
			// hasData() will trigger correctly in the introspection done via the
			// containing Grid object when assembling conditionals to do fetch from the
			// data store - savvy?).
			if( is_null( $data ) && is_null( $this->_data ) && !$this->isLoaded() ){
				$this->_setDirty();
				$return = true;
			}
		} else {
			// Keep internal copy
			$this->_data = $data;
			// Indicate that we have changed since loading.
			$this->_setDirty();
			$return = true;
		}
		return( $return );
	}

	public function Reset(){
		$return = false;
		if( $this->isLoaded() && $this->isDirty() ){
			$this->setData( $this->getOriginalData() );
			$this->_setDirty( false );
			$return = true;
		} 
		return( $return );
	}
}
?>