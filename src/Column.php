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
	const REGEX_DATE = '\d{4}[,\.\-\/]?(0[0-9]|1[0-2])[,\.\-\/]?(0[0-9]|[12][0-9]|3[01])';
	const REGEX_TIME = '([01][0-9]|2[0-3])([,:\-][0-5][0-9]){2}';

	const ATTRIBUTE_GENERATED_ON_PUBLISH = 'generatedOnPublish';

	private $_grid; // Grid object to which this belongs.

	private $_type = self::TYPE_VARCHAR;
	private $_encoding = null;
	private $_length = -1;
	private $_precision = 0;
	private $_isNullable = true;
	private $_isReadOnly = false;
	private $_generatedOnPublish = false;
	private $_dataName = null;
	private $_attributes = array();
	private $_inGetData = false; // Recursion indicator

	private $_linkedColumn = null;
	private $_linkedColumnContinual = true;

	private $_defaultData = null;
	private $_originalData = null;
	private $_data = null;

	public function Column( Torpor $torpor, $name = null, SimpleXMLElement $xmlDef = null, Grid $grid = null ){
		parent::__construct( $torpor, $name );
		if( $grid instanceof Grid ){
			$this->setGrid( $grid );
		}
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

		if( !isset( $xmlDef->attributes()->dataName ) ){
			$this->throwException( 'Required attribute dataName not found' );
		}
		$this->_dataName = (string)$xmlDef->attributes()->dataName;

		if(
			isset( $xmlDef->attributes()->generatedOnPublish )
			&& (string)$xmlDef->attributes()->generatedOnPublish == Torpor::VALUE_TRUE
		){
			$this->setGeneratedOnPublish();
		}

		if(
			isset( $xmlDef->attributes()->nullable )
			&& (string)$xmlDef->attributes()->nullable == Torpor::VALUE_FALSE
		){
			$this->setNullable( false );
		}

		if( isset( $xmlDef->attributes()->precision ) ){
			$this->setPrecision( (string)$xmlDef->attributes()->precision );
		}

		// Set any default data prior to configuring as readOnly
		if( isset( $xmlDef->attributes()->default ) ){
			// This way we reset to this for originalData, and are still considered
			// dirty
			// WARNING: validating content from the dataStore; which can throw some
			// strange warnings if the XML and the repository definitions don't agree.
			$this->_defaultData = (string)$xmlDef->attributes()->default;
			$this->_data = $this->validate( $this->_defaultData );
			$this->setOriginalData( $this->_data );
			$this->_setDirty();
		}

		if(
			isset( $xmlDef->attributes()->readOnly )
			&& (string)$xmlDef->attributes()->readOnly == Torpor::VALUE_TRUE
		){
			$this->setReadOnly();
		}
		// TODO: options for navigating attributes
		foreach( $xmlDef->attributes() as $key => $val ){
			$this->_attributes[ $key ] = (string)$val;
		}
	}

	public function getDataName(){ return( $this->_dataName ); }

	public function hasDefaultData(){ return( !is_null( $this->_defaultData ) ); }

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

	public static function dataTypes(){ return( self::getValidTypes() ); }
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
			$this->throwException( '"'.$type.'" is not a valid type' );
		}
		// TODO: Need to validate existing contents during conversion, throw
		// conversion warnings as necessary.
		$this->_type = $type;
	}

	public function getEncoding(){ return( $this->_encoding ); }
	public function setEncoding( $encoding ){
		if( !function_exists( 'mb_list_encodings' ) ){
			trigger_error( 'Multibyte String extension not available, encoding value ignored', E_USER_WARNING );
		} else if( !in_array( $encoding, mb_list_encodings() ) ){
			trigger_error( 'Requested encoding "'.$encoding.'" not recognized, will be ignored', E_USER_WARNING );
		} else {
			$this->_encoding = $encoding;
		}
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

	public function __toString(){ return( (string)$this->getData() ); }

	// To be used when persist data and PHP data don't match, such as Y/N enums or ints acting as
	// bools which require a facade.  It's up to the inheriting class to do anything useful with
	// this.
	public function getPersistData( $localOnly = false ){ return( $this->getData( $localOnly ) ); }
	public function getData( $localOnly = false ){
		// Look to see if we're already in the process of fetching data - if we are, and we
		// end up here again, it means because there's an implicit load loop happening (there are
		// only a few legitimate conditions that can generate this, such as setting data in
		// a key field that has a default value, but rather than propagating the "localOnly" flag
		// to be knowledgeable across a very large series of methods, it's cheaper and safer (even
		// if it is a hack) to detect it here.

		// Store the current value the _inGetData check.
		$wasInGetData = $this->_inGetData;
		if( $this->_inGetData )
		{
			$localOnly = true;
		} else {
			// Set to protect against recursion
			$this->_inGetData = true;
		}
		if( $this->isLinked() ){
			if( !$localOnly ){
				if( $this->getLinkedColumn()->hasData() ){
					// Setting data automatically destroys links unless it is instructed otherwise.
					$this->setData( $this->getLinkedColumn()->getData(), $this->perpetuateLink(), true );
				}
			}
		} else {
			// TODO: WARNING: What to do in the case that Grid is already
			// loaded?  Should we care?  This answer determines whether or
			// not we throw an exception (if grid is loaded and we're not),
			// and/or whether we call Load() with refresh set to true.
			// Only load in the event that we don't already contain data
			// (or the only data we contain is of the default variety)?
			if(
				!$localOnly
				&& !$this->isLoaded()
				&& (
					!$this->isDirty()
					|| (
						$this->isDirty()
						&& $this->hasDefaultData()
						&& $this->_data == $this->_defaultData
					)
				)
				&& $this->Grid() instanceof Grid
				&& $this->Grid()->canLoad()
			){
				$this->Grid()->Load();
			}
		}
		// Set this back to whatever it was before we got here.
		$this->_inGetData = $wasInGetData;
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
	public function setLoadData( $data, $fromDataStore = false ){
		$this->_setLoaded();
		$this->_setDirty( false );
		$this->setOriginalData(
			$this->_data = (
				$fromDataStore
				? $this->validatePersistData( $data )
				: $this->validate( $data )
			)
		);
	}

	public function UnLoad(){
		$this->_setLoaded( false );
		$this->setOriginalData(
			$this->_data = (
				$this->hasDefaultData()
				? $this->_defaultData
				: null
			)
		);
		$this->_setDirty( $this->hasDefaultData() );
	}

	protected function _setDirty( $bool = true ){
		$bool = ( $bool ? true : false );
		parent::_setDirty( $bool );
		if( $bool ){
			$this->Grid()->dirtyColumn();
		}
		return( $bool );
	}

	public function Publish( $force = false ){
		return( $this->Grid()->Publish( false ) );
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

	public function perpetuateLink(){ return( $this->isLinked( true ) ); }
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

	public function validatePersistData( $data ){ return( $this->validate( $data ) ); }
	public function validate( $data ){
		if( is_null( $data ) ){
			if( !$this->isNullable() ){
				$this->throwException( $this->_getObjName().' is not nullable' );
			}
		} else {
			switch( $this->getType() ){
				case self::TYPE_BINARY:
					if( $this->getMaxLength() >= 0 && strlen( $data ) > $this->getMaxLength() ){
						trigger_error( 'Binary data truncated', E_USER_WARNING );
						$data = substr( $data, 0, $this->getMaxLength() );
					}
					break;
				case self::TYPE_BOOL:
					$data = ( $data && strtolower( $data ) !== Torpor::VALUE_FALSE ? true : false );
					break;
				case self::TYPE_CLASS: break; // No checking on class data.
				case self::TYPE_DATE:
					if( !preg_match( '/^'.self::REGEX_DATE.'$/', $data ) ){
						$this->throwException( 'Invalid date specified "'.$data.'"' );
					}
					break;
				case self::TYPE_DATETIME:
					if( !preg_match( '/^'.self::REGEX_DATE.'\s?'.self::REGEX_TIME.'$/', $data ) ){
						$this->throwException( 'Invalid datetime specified "'.$data.'"' );
					}
					break;
				case self::TYPE_TIME:
					if( !preg_match( '/^'.self::REGEX_TIME.'$/', $data ) ){
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
					if( !is_null( $this->getEncoding() ) && function_exists( 'mb_convert_encoding' ) ){
						$data = mb_convert_encoding( $data, $this->getEncoding(), mb_detect_encoding( $data ) );
						if( $this->getMaxLength() >= 0 && mb_strlen( $data, $this->getEncoding() ) > $this->getMaxLength() ){
							trigger_error( 'Character data truncated', E_USER_WARNING );
							$data = mb_substr( $data, 0, $this->getMaxLength(), $this->getEncoding() );
						}
					} else {
						if( $this->getMaxLength() >= 0 && strlen( $data ) > $this->getMaxLength() ){
							trigger_error( 'Character data truncated', E_USER_WARNING );
							$data = substr( $data, 0, $this->getMaxLength() );
						}
					}
					break;
				default:
					// TODO: Throw an error?  How could we ever actually get here?
					break;
			}
		}
		return( $data );
	}

	// Returns bool to indicate whether data has changed.
	public function setData( $data, $preserveLink = false, $fromGetData = false ){
		// TODO: Need a way to set data on an unloaded object without
		// causing it to getData(), in the event that we're working on
		// a new object and have nothing to actually fetch.
		// TODO: Loaded primary keys should be read only
		if( $this->isReadOnly() ){
			$this->throwException( $this->_getObjName().' is read only' );
		}
		$return = false;
		$data = $this->validate( $data );

		if( !$fromGetData && $data == $this->getData() ){
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
			$this->_data = $data;
			if( $this->isLinked() && !$preserveLink ){
				$this->destroyLink();
			}
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
			if( !$this->hasDefaultData() ){
				$this->_setDirty( false );
			}
			$return = true;
		} 
		return( $return );
	}
}
?>
