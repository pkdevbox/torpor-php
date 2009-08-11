<?PHP
// $Rev$
// This should be redundant with lazy loading, but redunance
// in some cases is better than broken in others.
require_once( 'PersistableContainer.php' );
require_once( 'Grid.php' );
require_once( 'Column.php' );
require_once( 'Criteria.php' );
require_once( 'GridSet.php' );
require_once( 'PersistenceCommand.php' );
require_once( 'DataStore.php' );
require_once( 'MySQLDataStore.php' ); // TODO: just-in-time require_once() ?

// TODO: phpdoc
// TODO: CLEANUP: Order the methods according to some logical paradigm.
// TODO: Documentation: Need good use case demonstration of linked objects,
// e.g.:
//   $user = Torpor()->newUser();
//   $order = $user->newOrder();
//   $orderItemOne = $order->newItem();
//   $orderItemTwo = $order->newItem();
// right:
//   $orderItemOne->publish(); // This will properly cascade up the tree.
//   $orderItemTwo->publish();
// wrong:
//   $user->publish();  // Will only publish user.  Everything else still
//                      // requires manual assistance.
// TODO: create a TorporDebug version which by default uses <obj>Debug
// extensions to Grid, Column, GridSet, etc., (and/or one can simply
// override the default object type for each level in the hierarchy),
// with these debug objects essentially providing a timestamped debug
// log via trigger_error indicating when each function call is initiated.
// Extensive error_reporting will need to be configured to pick up on all
// the E_USER_NOTICEs that it generates, which means that it's unlikely
// to affect production but can, with a good default error handler, be
// switched on in many contexts in order to provide extremely good
// insight and performance characteristics.  Not recommended to leave
// it on though, given the amount of string concatenation to be done, etc.
// Oh, and these just wrap all the calls in trigger_error() notices,
// then pass everything else up the chain via parent::X( $args ).
// May be able to provide much of this by overriding __call(), and then
// following it up with parent::__call( $func, $args ); but only maybe.
// That wouldn't work for all defined functions, so this will need to
// be carefully considered.
class Torpor {
	const VERSION = 0.1;
	const DEFAULT_CONFIG_FILE = 'TorporConfig.xml';
	const DEFAULT_CONFIG_SCHEMA = 'TorporConfig.xsd';

	// Options
	const OPTION_COLUMN_CLASS  = 'ColumnClass';
	const DEFAULT_COLUMN_CLASS = 'Column';

	const OPTION_DEBUG = 'Debug';
	const DEFAULT_DEBUG = false;

	const OPTION_GRID_CLASS  = 'GridClass';
	const DEFAULT_GRID_CLASS = 'Grid';

	const OPTION_LINK_UNPUBLISHED_REFERENCE_COLUMNS = 'LinkUnpublishedReferenceColumns';
	const DEFAULT_LINK_UNPUBLISHED_REFERENCE_COLUMNS = true;

	const OPTION_OVERWRITE_ON_LOAD  = 'OverwriteOnLoad';
	const DEFAULT_OVERWRITE_ON_LOAD = false;

	const OPTION_PERPETUATE_AUTO_LINKS = 'PerpetuateAutoLinks';
	const DEFAULT_PERPETUATE_AUTO_LINKS = false;

	// Value constants
	const VALUE_TRUE   = 'true';
	const VALUE_FALSE  = 'false';
	const VALUE_NONE   = 'none';
	const VALUE_GLOBAL = 'global';
	const VALUE_ID     = 'id';
	const VALUE_SEPARATOR = '|';

	// Utility
	// These are all the characters which will be stripped.
	const REGEX_KEYNAME = '[^A-Za-z0-9]';

	// Internal use only
	const ARKEY_DATATYPEMAP  = 'datatype_map';
	const ARKEY_COLUMNS      = 'columns';
	const ARKEY_GRIDS        = 'grids';
	const ARKEY_GRID_CLASSES = 'grid_classes';
	const ARKEY_KEYS         = 'keys';
	const ARKEY_UNIQUEKEYS   = 'uniquekeys';
	const ARKEY_REFERENCES   = 'references';
	const ARKEY_OPTIONS      = 'options';

	const COMMON_OPERATION_LENGTH = 3;
	const OPERATION_ADD = 'add';
	const OPERATION_CAN = 'can';
	const OPERATION_GET = 'get';
	const OPERATION_IS  = 'is';
	const OPERATION_MAP = 'map';
	const OPERATION_NEW = 'new';
	const OPERATION_SET = 'set';
	const OPERATION_GET_SET = 'set';
	const OPERATION_MOD_FROM = 'from';
	const OPERATION_MOD_BY   = 'by';
	const OPERATION_MOD_VIA  = 'via';
	const OPERATION_GET_CRITERIA = 'criteria';

	// TODO: Need a classification or prefix for argument enum/constants?
	// Where no parsing or assembly is required, sticking with int for
	// enum values in order to keep comparisons more performant.  Every
	// little bit helps.
	const ALL_KEYS_FLAT   = 0;
	const ALL_KEYS_DEEP   = 1;
	const NON_ALIAS_KEYS  = 2;
	const ALIAS_KEYS_ONLY = 3;

	private static $instance;
	private $_readDataStore;
	private $_writeDataStore;
	private $_config = array();
	private $_isInitialized = false;

	private $_prototypeCache = array();
	private $_cachedCalls = array();

	private $_warnings = array();
	private $_errors = array();

	public function Torpor(){
	}

	// TODO: should Instance be applied as a reserved word, in order to make this play nice?
	// Or, should we simply look at whether or not the invocation was genuinely static, and
	// if called in object context instead go ahead and look for a grid of type Instance?
	// However, since there's no other predicate in the name, we should be OK - since the
	// anticipated use of any getGrid call will be followed by qualifiers for some kind -
	// FromID, from another record, etc; unless we introduce the premise of getX( id ) as
	// an alias for getXFromId( id ), but in which case we can still screen for that behavior
	// by looking at the number of arguments.  None of this is material in any way though
	// unless there is a grid by name of Instance among the ranks.
	public static function getInstance(){
		if( !isset( self::$instance ) ){
			self::$instance = new Torpor();
		}
		return( self::$instance );
	}

	public function isInitialized(){
		return(
			(
				isset( $this ) && $this instanceof self
				? $this->_isInitialized
				: self::getInstance()->isInitialized()
			)
		);
	}

	protected function setInitialized( $bool = true ){ return( $this->_isInitialized = ( $bool ? true : false ) ); }
	protected function checkInitialized(){
		if( !$this->isInitialized() && !$this->initialize() ){
			$this->throwException( get_class( $this ).' not initialized, cannot continue' );
		}
	}

	public static function Version(){ return( self::VERSION ); }

	public function initialize( $config = null ){
		$instance = null;
		if( isset( $this ) && $this instanceof self ){
			// Called in object context
			$instance = $this;
		} else {
			// Called in static context
			$instance = self::getInstance();
		}

		$xml = '';
		// TODO: Load files (from names) directly into SimpleXML via provided loading
		// routines, rather than slurping contents into memory first.
		if( !empty( $config ) ){
			if( is_resource( $config ) ){
				$xml = stream_get_contents( $config );
			} else if( $config instanceof DOMNode ){
				$xml = simplexml_import_dom( $config );
			} else if( $config instanceof SimpleXMLElement ){
				$xml = $config;
			} else if( is_string( $config ) ){
				if( preg_match( '/^[a-z]+:\/{2,3}[a-zA-Z0-9_\.]$/', $config ) ){
					// Looks like a URI
					$config = fopen( $config, 'r' );
					$xml = stream_get_contents( $config );
				} else if( file_exists( $config ) && is_readable( $config ) ){
					$xml = simplexml_load_file( $config );
				} else if( $configFile = $this->getFileInPath( $config ) ){
					$xml = simplexml_load_file( $configFile );
				} else {
					$xml = $config;
				}
			}
		} else {
			if( $defaultConfig = $this->getFileInPath( self::DEFAULT_CONFIG_FILE ) ){
				$config = file_get_contents( $defaultConfig );
			}
		}
		if( empty( $xml ) ){
			$instance->throwException( 'No configuration found, cannot initialize' );
		}
		return( $instance->processConfig( $xml ) );
	}

	public static function defaultOptions(){
		return(
			array(
				self::OPTION_COLUMN_CLASS => self::DEFAULT_COLUMN_CLASS,
				self::OPTION_DEBUG => self::DEFAULT_DEBUG,
				self::OPTION_GRID_CLASS => self::DEFAULT_GRID_CLASS,
				self::OPTION_LINK_UNPUBLISHED_REFERENCE_COLUMNS => self::DEFAULT_LINK_UNPUBLISHED_REFERENCE_COLUMNS,
				self::OPTION_OVERWRITE_ON_LOAD => self::DEFAULT_OVERWRITE_ON_LOAD,
				self::OPTION_PERPETUATE_AUTO_LINKS => self::DEFAULT_PERPETUATE_AUTO_LINKS
			)
		);
	}

	public function processConfig( $xml ){
		$return = false;
		$xmlObj = ( $xml instanceof SimpleXMLElement ? $xml : simplexml_load_string( $xml ) );

		if( !$this->getFileInPath( self::DEFAULT_CONFIG_SCHEMA ) ){
			$this->throwException( 'Schema document '.self::DEFAULT_CONFIG_SCHEMA.' not found in path' );
		}

		dom_import_simplexml( $xmlObj )->ownerDocument->schemaValidate(
			$this->getFileInPath( self::DEFAULT_CONFIG_SCHEMA )
		) or $this->throwException( 'Invalid configuration (failed schema check)' );

		$options = self::defaultOptions();
		$dataTypeMap = array(
			self::OPTION_GRID_CLASS => array(),
			self::VALUE_GLOBAL => array()
		);
		$grids = array();
		$gridClasses = array();
		$columns = array();
		$keys = array();
		$uniqueKeys = array();
		$references = array();

		if( isset( $xmlObj->Options ) ){
			foreach( $xmlObj->Options->children() as $option ){
				switch( $option->getName() ){
					case self::OPTION_COLUMN_CLASS:
						$className = (string)$option;
						$this->checkColumnClass( $className );
						$options{ self::OPTION_COLUMN_CLASS } = $className;
						break;
					case self::OPTION_DEBUG:
						$options{ self::OPTION_DEBUG } = ( (string)$option == self::VALUE_TRUE ? true : false );
						break;
					case self::OPTION_GRID_CLASS:
						$className = (string)$option;
						$this->checkGridClass( $className );
						$options{ self::OPTION_GRID_CLASS } = $className;
						break;
					case self::OPTION_LINK_UNPUBLISHED_REFERENCE_COLUMNS:
						$options{ self::OPTION_LINK_UNPUBLISHED_REFERENCE_COLUMNS } = ( (string)$option == self::VALUE_TRUE ? true : false );
						break;
					case self::OPTION_OVERWRITE_ON_LOAD:
						$options{ self::OPTION_OVERWRITE_ON_LOAD } = ( (string)$option == self::VALUE_TRUE ? true : false );
						break;
					case self::OPTION_PERPETUATE_AUTO_LINKS:
						$options{ self::OPTION_PERPETUATE_AUTO_LINKS } = ( (string)$option == self::VALUE_TRUE ? true : false );
						break;
					case 'DataTypeMap':
						$dataTypeMap{ self::VALUE_GLOBAL } = $this->parseDataTypeMap( $option );
						break;
					default:
						$this->throwException( 'Unrecognized option "'.$option->getName().'"' );
						break;
				}
			}
		}

		if( isset( $xmlObj->Repository ) ){
			// LEAVING OFF HERE:
			// Need good common mechanism for instantiating
			// any of the different data store types,
			// and parsing their Parameters for instantiation.
			// TODO: Encrypted parameter support.
			$readDataStore = null;
			$writeDataStore = null;
			if( isset( $xmlObj->Repository->DataStore ) ){
			} else {
			}
		}

		// TODO:
		// 2. Set up data store
		foreach( $xmlObj->Grids->Grid as $xmlGrid ){
			$gridName = $this->xmlObjKeyName( $xmlGrid );
			if( !$gridName ){
				$this->throwException( 'Could not extract a suitable grid name' );
			}
			if( isset( $grids{ $gridName } ) ){
				$this->throwException( 'Duplicate grid name '.$gridName );
			}
			// TODO: Auto-pruning or just-in-time generation, so as not to have empty
			// arrays all over the place?
			$grids{ $gridName } = (string)$xmlGrid->attributes()->dataName;
			$columns{ $gridName } = array();
			$keys{ $gridName } = array();
			$uniqueKeys{ $gridName } = array();
			$references{ $gridName } = array();
			if( $className = (string)$xmlGrid->attributes()->class ){
				if( $className == self::VALUE_NONE ){
					$className = self::DEFAULT_GRID_CLASS;
				} else {
				}
				$gridClasses{ $gridName } = $className;
			}

			// TODO: Parse Commands
			// $commands{ $gridName } = array();

			if( isset( $xmlGrid->DataTypeMap ) ){
				$dataTypeMap{ self::DEFAULT_GRID_CLASS }{ $gridName } = $this->parseDataTypeMap( $xmlGrid->DataTypeMap );
			}

			foreach( $xmlGrid->Columns->Column as $xmlColumn ){
				$columnName = $this->xmlObjKeyName( $xmlColumn );
				if( !$columnName ){
					$this->throwException( 'Could not extract a suitable column name' );
				}
				if( isset( $columns{ $gridName }{ $columnName } ) ){
					$this->throwException( 'Duplicate column name '.$columnName.' on grid '.$gridName );
				}
				if( (string)$xmlColumn->attributes()->class ){
					$className = (string)$xmlColumn->attributes()->class;
					if( $className != self::VALUE_NONE ){
						$this->checkColumnClass( $className );
					}
				}
				$columns{ $gridName }{ $columnName } = $xmlColumn; // We want this one to hang around.
			}

			if( $xmlGrid->Keys->Primary ){
				$keyArray = array();
				foreach( $xmlGrid->Keys->Primary->Key as $xmlKey ){
					$keyName = $this->makeKeyName( (string)$xmlKey->attributes()->column );
					if( !$keyName ){
						$this->throwException( 'Invalid key name '.(string)$xmlKey->attributes()->column.' in '.$gridName );
					}
					$keyArray[] = $keyName;
				}
				if( count( $keyArray ) <= 0 ){
					$this->throwException( 'No suitable primary keys defined for grid '.$gridName );
				} else if( count( $keyArray ) == 1 ){
					// One key and only one key, which is nice
					$keys{ $gridName } = array_shift( $keyArray );
				} else {
					$keys{ $gridName } = $keyArray;
				}
			}

			if( $xmlGrid->Keys->Unique ){
				foreach( $xmlGrid->Keys->Unique as $xmlUnique ){
					$keyArray = array();
					foreach( $xmlUnique->Key as $xmlKey ){
						$keyName = $this->makeKeyName( (string)$xmlKey->attributes()->column );
						if( !$keyName ){
							$this->throwException( 'Invalid key name '.(string)$xmlKey->attributes()->column.' in '.$gridName );
						}
						$keyArray[] = $keyName;
					}
					if( count( $keyArray ) <= 0 ){
						$this->throwException( 'No suitable unique keys defined for grid '.$gridName );
					} else if( count( $keyArray ) == 1 ){
						// One key and only one key, which is nice
						$uniqueKeys{ $gridName }[] = array_shift( $keyArray );
					} else {
						$uniqueKeys{ $gridName }[] = $keyArray;
					}
				}
			}
			if(
				count( $keys{ $gridName } ) == 0
				&& count( $uniqueKeys{ $gridName } ) == 0
			){
				trigger_error( 'No primary or unique keys defined for grid '.$gridName.', object update will be impossible', E_USER_WARNING );
			}

			if( $xmlGrid->Keys->Foreign ){
				foreach( $xmlGrid->Keys->Foreign->Key as $xmlKey ){
					$targetGrid = $this->makeKeyName( (string)$xmlKey->attributes()->referenceGrid );
					if( !$targetGrid ){
						$this->throwException( 'Invalid referenceGrid '.(string)$xmlKey->attributes()->referenceGrid.' in foreign key under grid '.$gridName );
					}
					if( $xmlKey->attributes()->referenceGridAlias ){
						$alias = $this->makeKeyName( (string)$xmlKey->attributes()->referenceGridAlias );
						$targetGrid.= self::VALUE_SEPARATOR.$alias;
					}
					$columnName = $this->makeKeyName( (string)$xmlKey->attributes()->column );
					if( !$columnName || !isset( $columns{ $gridName }{ $columnName } ) ){
						$this->throwException( 'Invalid column reference '.(string)$xmlKey->attributes()->column.' in foreign key under grid '.$gridName );
					}
					if(
						!isset( $references{ $gridName }{ $targetGrid } )
						|| !is_array( $references{ $gridName }{ $targetGrid } )
					){
						$references{ $gridName }{ $targetGrid } = array();
					}
					if( isset( $references{ $gridName }{ $targetGrid }{ $columnName } ) ){
						$this->throwException( 'Duplicate column reference '.$columnName.' in foreign key under grid '.$gridName );
					}
					$targetColumnName = (
						isset( $xmlKey->attributes()->referenceColumn )
						? $this->makeKeyName( (string)$xmlKey->attributes()->referenceColumn )
						: $columnName
					);
					$references{ $gridName }{ $targetGrid }{ $columnName } = $targetColumnName;
				}
			}
			// TODO: More XML options, build out Commands
			$this->cacheCall( 'new'.$gridName, '_newGrid', $gridName );
		}

		// TODO: Where/how to cache this, as identified by the resource initially fed to
		// us (in order to rapidly reload on successive iterations)?
		$this->_config = array(
			self::ARKEY_COLUMNS      => $columns,
			self::ARKEY_DATATYPEMAP  => $dataTypeMap,
			self::ARKEY_GRIDS        => $grids,
			self::ARKEY_GRID_CLASSES => $gridClasses,
			self::ARKEY_KEYS         => $keys,
			self::ARKEY_OPTIONS      => $options,
			self::ARKEY_REFERENCES   => $references,
			self::ARKEY_UNIQUEKEYS   => $uniqueKeys
		);
		$this->setInitialized();

		// Now use those values to finalize the relationships.
		try {
			$this->initializeKeys();
		} catch( TorporException $e ){
			$this->setInitialized( false );
			throw( $e );
		}

		// TODO: Now that we have all tables scanned, make sure that all foreign references
		// correspond to known table types.
		// TODO: retun should only be true if:
		// 1. We have 1 or more grid containing 1 or more columns
		// 2. We have a data store properly instantiated.
		$return = true;

		return( $return );
	}

	protected function initializeKeys(){
		// Review all specified keys and confirm target grid and columns exist, and determine
		// of these keys which ones provide discrete mappings (complete overlap with one or more
		// of the unique constraints [primary or otherwise] on the target table, such that definitive
		// identification is assured)
		foreach( array_keys( $this->_getGrids() ) as $gridName ){
			$references = $this->_getReferences( $gridName );
			if( is_array( $references ) && count( $references ) > 0 ){
				foreach( $references as $targetGrid => $columnPairs ){
					$alias = false;
					if( strpos( $targetGrid, self::VALUE_SEPARATOR ) ){
						list( $targetGrid, $alias ) = explode( self::VALUE_SEPARATOR, $targetGrid );
					}
					if( is_null( $this->_getGrids( $targetGrid ) ) ){
						$this->throwException( 'Unknown grid "'.$targetGrid.'" in key references from grid '.$gridName );
					}
					// TODO: Keep the exceptions, because the intialize routines are the place to check for them.
					// Otherwise, complete replace this with the canReference routines.
					foreach( $columnPairs as $columnName => $targetColumnName ){
						$columns = $this->_getColumns( $targetGrid );
						if( !is_array( $columns ) || !isset( $columns{ $targetColumnName } ) ){
							$this->throwException( 'Unknown reference column "'.$targetColumnName.'" in key reference from '.$gridName.' on column '.$columnName );
						}
					}
					if( $this->canReference( $gridName, $targetGrid, ( $alias ? $alias : self::VALUE_NONE ) ) ){
						if( $alias ){
							// Put'em back together.
							$targetGrid = $targetGrid.self::VALUE_SEPARATOR.$alias;
							$this->cacheCall(
								self::OPERATION_GET.$alias.self::OPERATION_MOD_FROM.$gridName,
								'_getGridFromRecord', // TODO: Const?
								$targetGrid
							);
							$this->cacheCall(
								self::OPERATION_GET.$gridName.self::OPERATION_GET_SET
								.self::OPERATION_MOD_FROM.$alias,
								'_getGridSetFromAliasRecord',
								$gridName,
								$alias
							);
							$this->cacheCall(
								self::OPERATION_NEW.$gridName.self::OPERATION_MOD_FROM.$alias,
								'_newGridFromAliasRecord',
								$gridName,
								$alias
							);
						} else {
							$this->cacheCall(
								self::OPERATION_GET.$targetGrid.self::OPERATION_MOD_FROM.$gridName,
								'_getGridFromRecord', // TODO: Const?
								$targetGrid
							);
							$this->cacheCall(
								self::OPERATION_GET.$gridName.self::OPERATION_GET_SET
								.self::OPERATION_MOD_FROM.$targetGrid,
								'_getGridSetFromRecord',
								$gridName
							);
							$this->cacheCall(
								self::OPERATION_NEW.$gridName.self::OPERATION_MOD_FROM.$targetGrid,
								'_newGridFromRecord',
								$gridName
							);
						}
					} else {
						trigger_error( 'No complete reference key / unique key combination from '.$gridName.' to '.$targetGrid, E_USER_WARNING );
					}
				}
			}
		}
	}

	private function _getInitX( $x, $gridName = null ){
		$this->checkInitialized();
		if( $gridName ){
			$gridName = $this->gridKeyName( $gridName );
			return(
				(
					isset( $this->_config{ $x }{ $gridName } )
					? $this->_config{ $x }{ $gridName }
					: null
				)
			);
		}
		return( $this->_config{ $x } );
	}
	protected function _getColumns( $grid = null ){ return( $this->_getInitX( self::ARKEY_COLUMNS, $grid ) ); }
	protected function _getDataTypeMap( $grid = null ){
		$dataTypeMap = $this->_getInitX( self::ARKEY_DATATYPEMAP );
		if( !empty( $grid ) ){
			$grid = $this->gridKeyName( $grid );
			if( isset( $dataTypeMap{ self::DEFAULT_GRID_CLASS }{ $grid } ) ){
				$dataTypeMap = $dataTypeMap{ self::DEFAULT_GRID_CLASS }{ $grid };
			} else {
				$dataTypeMap = array();
			}
		} else {
			$dataTypeMap = $dataTypeMap{ self::VALUE_GLOBAL };
		}
		return( $dataTypeMap );
	}
	protected function _getGrids( $grid = null ){ return( $this->_getInitX( self::ARKEY_GRIDS, $grid ) ); }
	protected function _getGridClasses( $grid = null ){ return( $this->_getInitX( self::ARKEY_GRID_CLASSES, $grid ) ); }
	protected function _getKeys( $grid = null ){ return( $this->_getInitX( self::ARKEY_KEYS, $grid ) ); }
	protected function _getOptions(){ return( $this->_getInitX( self::ARKEY_OPTIONS ) ); }
	protected function _getReferences( $grid = null ){ return( $this->_getInitX( self::ARKEY_REFERENCES, $grid ) ); }
	protected function _getUniqueKeys( $grid = null ){ return( $this->_getInitX( self::ARKEY_UNIQUEKEYS, $grid ) ); }

	public function ReadDataStore( DataStore $dataStore = null ){
		if( !is_null( $dataStore ) ){
			$this->_readDataStore = $dataStore;
		}
		return( $this->_readDataStore );
	}
	public function WriteDataStore( DataStore $dataStore = null ){
		if( !is_null( $dataStore ) ){
			$this->_writeDataStore = $dataStore;
		}
		return( $this->_writeDataStore );
	}

	// Aliases
	protected function _getAllGridKeys( $gridName ){
		$gridName = $this->gridKeyName( $gridName );
		if( !$this->supportedGrid( $gridName ) ){
			// This should only ever be called internall, so we shouldn't even hit this.
			$this->throwException( 'Unknown grid "'.$gridName.'" requested in key collection fetch' );
		}
		$key_sets = array();
		if( $temp_keys = $this->_getKeys( $gridName ) ){
			$key_sets[] = $temp_keys;
		}
		if( $temp_keys = $this->_getUniqueKeys( $gridName ) ){
			$key_sets = array_merge( $key_sets, $temp_keys );
		}
		return( ( count( $key_sets ) > 0 ? $key_sets : false ) );
	}

	public function dataNameForGrid( $gridName ){
		return( $this->_getGrids( $gridName ) );
	}
	public function primaryKeyForGrid( $gridName ){ return( $this->_getKeys( $gridName ) ); }
	public function allKeysForGrid( $gridName ){ return( $this->_getAllGridKeys( $gridName ) ); }
	protected function _setOption( $optionName, $setting ){
		return( $this->_config{ self::ARKEY_OPTIONS }{ $optionName } = $setting );
	}

	public function canReference( $sourceGridName, $targetGridName, $specificAlias = false ){
		$sourceGridName = $this->gridKeyName( $sourceGridName );
		$targetGridName = $this->gridKeyName( $targetGridName );
		if( $specificAlias && $specificAlias != self::VALUE_NONE ){
			$specificAlias = $this->gridKeyName( $specificAlias );
		}
		$return = false;
		if( $this->can(
			self::OPERATION_GET.(
				$specificAlias && $specificAlias != self::VALUE_NONE
				? $specificAlias
				: $targetGridName
			).self::OPERATION_MOD_FROM.$sourceGridName )
		){
			$return = true;
		} else {
			// Set up a temporary key array containing all known keys of the target grid
			// as $filled_keys[ key_set ][ $keyName ] = false.
			$filled_keys = array();
			foreach( $this->allKeysForGrid( $targetGridName ) as $keys ){
				if( is_array( $keys ) ){
					// Not using array_fill_keys due to target compatibility of PHP 5.1.x
					foreach( $keys as $key ){
						$filled_keys[] = (
							is_array( $key )
							? array_combine( $key, array_fill( 0, count( $key ), false ) )
							: array( $key => false )
						);
					}
				} else {
					$filled_keys[] = array( $keys => false );
				}
			}
			// Retrieve all reference keys between source and target
			$referenceKeys = $this->referenceKeysBetween( $sourceGridName, $targetGridName );
			foreach( $referenceKeys as $sourceColumnName => $targetColumnName ){
				if(
					is_array( $targetColumnName )
				){
					// $sourceColumnName is actual an Alias name, look deeper into the relationship
					if( !$specificAlias || $specificAlias == $sourceColumnName ){
						foreach( $targetColumnName as $aliasedSourceColumnName => $aliasedTargetColumnName ){
							foreach( $filled_keys as $index => $keySet ){
								if( isset( $keySet{ $aliasedTargetColumnName } ) ){
									$filled_keys[ $index ]{ $aliasedTargetColumnName } = true;
								}
							}
						}
					}
				} else if( !$specificAlias || $specificAlias == self::VALUE_NONE ){
					foreach( $filled_keys as $index => $keyset ){
						if( isset( $filled_keys[ $index ]{ $targetColumnName } ) ){
							$filled_keys[ $index ]{ $targetColumnName } = true;
						}
					}
				}
			}

			foreach( $filled_keys as $keyset ){
				if( !in_array( false, $keyset, true ) ){
					$return = true;
					break;
				}
			}
		}
		return( $return );
	}
	public function canBeReferencedBy( $targetGridName, $sourceGridName, $specificAlias = false ){
		return( $this->canReference( $sourceGridName, $targetGridName, $specificAlias ) );
	}

	// TODO: Should we return all non-alias references, all references
	// regardless, and if so do we include alias designations?
	public function referenceKeysBetween( $sourceGridName, $targetGridName, $keyTypes = self::ALL_KEYS_DEEP ){
		$sourceGridName = $this->gridKeyName( $sourceGridName );
		$targetGridName = $this->gridKeyName( $targetGridName );
		$returnKeys = array();
		$sourceGridReferenceKeys = $this->_getReferences( $sourceGridName );
		if( $keyTypes <= self::NON_ALIAS_KEYS ){
			if(
				is_array( $sourceGridReferenceKeys )
				&& isset( $sourceGridReferenceKeys{ $targetGridName } )
			){
				foreach( $sourceGridReferenceKeys{ $targetGridName } as $sourceColumnName => $targetColumnName ){
					if( in_array( $targetColumnName, array_values( $returnKeys ) ) ){
						trigger_error( 'Multiple source keys reference identical target columns between '.$sourceGridName.' and '.$targetGridName, E_USER_WARNING );
					}
					$returnKeys{ $sourceColumnName } = $targetColumnName;
				}
			}
		}
		if( $keyTypes != self::NON_ALIAS_KEYS ){
			foreach( array_keys( $sourceGridReferenceKeys ) as $referenceGridKey ){
				// Valid check since we want it to be > 0; separator as the first
				// character is invalid, even through it's in the string.
				if( strpos( $referenceGridKey, self::VALUE_SEPARATOR ) ){
					list( $referredGridName, $alias ) = explode( self::VALUE_SEPARATOR, $referenceGridKey );
					if( $referredGridName == $targetGridName ){
						foreach( $sourceGridReferenceKeys{ $referenceGridKey } as $sourceColumnName => $targetColumnName ){
							if( $keyTypes == self::ALL_KEYS_FLAT ){
								if( in_array( $targetColumnName, array_values( $returnKeys ) ) ){
									trigger_error( 'Multiple source keys reference identical target columns between '.$sourceGridName.' and '.$targetGridName, E_USER_WARNING );
								}
								$returnKeys{ $sourceColumnName } = $targetColumnName;
							} else {
								if( isset( $returnKeys{ $alias } ) ){
									if( !is_array( $returnKeys{ $alias } ) ){
										$this->throwException( 'Key conflict between named keys and referred grid alias as "'.$alias.'" between '.$sourceGridName.' and '.$targetGridName );
									}
								} else {
									$returnKeys{ $alias } = array();
								}
								$returnKeys{ $alias }{ $sourceColumnName } = $targetColumnName;
							}
						}
					}
				}
			}
		}
		return( ( count( $returnKeys ) > 0 ? $returnKeys : false ) );
	}
	public function aliasReferenceKeysBetween( $sourceGridName, $targetGridName, $specificAlias = false ){
		$referenceKeys = $this->referenceKeysBetween( $sourceGridName, $targetGridName, self::ALIAS_KEYS_ONLY );
		if( $specificAlias ){
			$specificAlias = $this->gridKeyName( $specificAlias );
			if( !isset( $referenceKeys{ $specificAlias } ) ){
				$this->throwException( 'Unsupported or unrecognized alias "'.$specificAlias.'" reference requested between '.$this->gridKeyName( $sourceGridName ).' and '.$this->gridKeyName( $targetGridName ) );
			}
			$referenceKeys = $referenceKeys{ $specificAlias };
		}
		return( $referenceKeys );
	}
	public function aliasReferenceNames( $sourceGridName, $targetGridName ){
		$references = $this->aliasReferenceKeysBetween( $sourceGridName, $targetGridName );
		if( is_array( $references ) ){
			$references = array_keys( $references );
		}
		return( $references );
	}

	// Option interfaces
	public function options( $optionName = null ){
		$options = $this->_getOptions();
		if( !is_null( $optionName ) ){
			// WARNING: Magic number.  However, this is important since we need to
			// know if a value has been provided, when that value may be null, and
			// it exists as the second of 2 optional arguments.
			if( func_num_args() > 1 ){
				$setting = func_get_arg( 1 );
				$this->_setOption( $optionName, $setting );
			}
			$options = $options{ $optionName };
		}
		return( $options );
	}
	public function overwriteOnLoad(){ return( $this->options( self::OPTION_OVERWRITE_ON_LOAD ) ); }
	public function linkUnpublishedReferenceColumns(){
		return( $this->options( self::OPTION_LINK_UNPUBLISHED_REFERENCE_COLUMNS ) );
	}
	public function perpetuateAutoLinks(){
		return( $this->options( self::OPTION_PERPETUATE_AUTO_LINKS ) );
	}

	public function gridClass( $gridName ){
		if( is_object( $gridName ) ){
			// What're you asking me for?
			return( get_class( $gridName ) );
		}
		return(
			(
				!is_null( $this->_getGridClasses( $gridName ) )
				? $this->_getGridClasses( $gridName )
				: $this->options( self::OPTION_GRID_CLASS )
			)
		);
	}
	public function columnClass( $gridName, $columnName ){
		$gridName = $this->gridKeyName( $gridName );
		if( is_object( $columnName ) ){
			return( get_class( $columnName ) );
		}
		$columnName = $this->makeKeyName( $columnName );
		$columns = $this->_getColumns( $gridName );
		if( !$columns{ $columnName } ){
			$this->throwException( 'Unrecognized column "'.$columnName.'" for grid '.$gridName.' requested in columnClass' );
		}
		$className = $this->options( self::OPTION_COLUMN_CLASS );
		$xmlColumn = $columns{ $columnName };
		if( isset( $xmlColumn->attributes()->class ) ){
			$className = (string)$xmlColumn->attributes()->class;
		} else {
			$type = strtoupper( (string)$xmlColumn->attributes()->type );
			$gridTypeMap = $this->_getDataTypeMap( $gridName );
			if( count( $gridTypeMap ) > 0 && isset( $gridTypeMap{ $type } ) ){
				$className = $gridTypeMape{ $type };
			} else {
				$dataTypeMap = $this->_getDataTypeMap();
				if( count( $dataTypeMap ) > 0 && isset( $dataTypeMap{ $type } ) ){
					$className = $dataTypeMap{ $type };
				}
			}
		}
		if( $className == self::VALUE_NONE ){
			$className = $this->options( self::OPTION_COLUMN_CLASS );
		}
		return( $className );
	}

	// Uses dbEngine to tanslate $table into however
	// the record needs to be stored.
	public function publishGrid( Grid $grid ){ return( $this->persistGrid( $grid ) ); }
	public function persistGrid( Grid $grid ){
	}

	public function supportedGrid( $gridName ){
		$this->checkInitialized();
		$gridName = $this->makeKeyName( $gridName );
		return( in_array( $gridName, array_keys( $this->_getGrids() ) ) );
	}
	protected function checkSupportedGrid( $gridName ){
		if( !$this->supportedGrid( $gridName ) ){
			$this->throwException( 'Unknown or unsupported grid "'.$gridName.'" requested' );
		}
	}

	public function _getGridById( $gridName ){
		$this->checkSupportedGrid( $gridName );
		$grid = $this->_newGrid( $gridName );
		$primaryKey = $this->primaryKeyForGrid( $gridName );
		$keyArgs = array_slice( func_get_args(), 1 );
		if( !is_array( $keyArgs ) || count( $keyArgs ) < 1 ){
			$this->throwException( 'No keys provided to _getGridById' );
		}
		if( !is_array( $primaryKey ) ){
			if( count( $keyArgs ) > 1 ){
				trigger_error( 'Wrong number of arguments to _getGridById ('.count( $keyArgs ).' were given, only using 1)', E_USER_WARNING );
			}
			$grid->Column( $primaryKey)->setData( array_shift( $keyArgs ) );
		} else {
			if( count( $keyArgs ) != count( $primaryKey ) ){
				if( count( $keyArgs ) > count( $primaryKey ) ){
					trigger_error( 'Wrong number of arguments to _getGridById ('.count( $keyArgs ).' were given, only using 1)', E_USER_WARNING );
				} else {
					$this->throwException( 'Wrong number of arguments to _getGridById - need '.count( $primaryKey ).', got '.count( $keyArgs ) );
				}
			}
			foreach( $primaryKey as $keyName ){
				$grid->Column( $keyName )->setData( array_shift( $keyArgs ) );
			}
		}
		if( !$grid->canLoad() ){
			$grid = false;
		}
		return( $grid );
	}

	public function _getGridFromRecord( $gridName, Grid $record ){
		// TODO: create the GridSet object which allows for the following:
		//X1. Stores a series of Grids which can be iterated over.
		// 2. Allows access by PK (variable argument list corresponding to the number
		//    of members in the object OK)
		//X3. Stores the criteria by which it was selected, and...
		//X4. ...if the criteria corresponds to a back-reference, such as keys in this
		//    Set are positively correlated with a foreign key, then it should be
		//    possible to addRecord() or otherwise push() a record onto the set and
		//    immediately inherit that defining criteria.  Similarly, and perhaps making
		//    direct use of these same features, be able to do a newRecord() call that
		//    creates and adds one for us, returning the related record.
		//X5. Has an addressing scheme (or at least method of iteration) that allows
		//    for the access and manipulation of added records which do not as yet have
		//    a primary key.
		//
		// TODO: Need a Load overwriter or otherwise quick-population scheme for Grids
		// in order to push the data onto them rather than wait for it to be requested,
		// such that we can load in bulk off of a single call to the database instead
		// of making the very common mistake of selecting all the IDs, and then whence
		// iterative over the set having to do a just-in-time fetch for every record
		// contained therein making it a very expensive operation.  It is possible
		// that this behavior may in some instances be desired, so it should be both a
		// configuration setting (global to the Torpor instance) and *possibly*
		// support local override.  Otherwise, since we're already talking to the
		// database, we might as well slurp the whole bunch, yeah?

		$alias = false;
		if( !( $gridName instanceof Grid ) && strpos( $gridName, self::VALUE_SEPARATOR ) ){
			list( $gridName, $alias ) = explode( self::VALUE_SEPARATOR, $gridName );
			$alias = $this->makeKeyName( $alias );
		}
		$gridName = $this->gridKeyName( $gridName );
		if( !$this->canReference( $record, $gridName, $alias ) ){
			$this->throwException( $record->_getObjName().' can not reference '.$gridName.( $alias ? ' as '.$alias : '' ) );
		}
		$targetGrid = $this->_newGrid( $gridName );
		$references = (
			$alias !== false
			? $this->aliasReferenceKeysBetween( $record, $gridName, $alias )
			: $this->referenceKeysBetween( $record, $gridName, self::NON_ALIAS_KEYS )
		);
		if( !$record->isLoaded() ){
			$record->Load();
		}
		foreach( $references as $sourceColumnName => $targetColumnName ){
			if( $record->Column( $sourceColumnName )->hasData() ){
				$targetGrid->Column( $targetColumnName )->setData( $record->Column( $sourceColumnName )->getData() );
			}
		}
		if( !$targetGrid->canLoad() ){
			$targetGrid = false;
		}
		return( $targetGrid );
	}

	public function _getGridFromCriteria( $gridName, Criteria $criteria ){
		// TODO: This.
	}

	public function _getGridSetFromRecord( $gridName, Grid $sourceGrid, Criteria $criteria = null ){
		// TODO: Ensure $criteria is properly passed through from the Grid->get<Grid>Set( $criteria )
		// calls in order to AND them together when retrieving corresponding grid records.
		$gridSet = $this->_newGridSet( $gridName, $sourceGrid );
		if( $criteria instanceof Criteria ){
			$gridSet->setSourceCriteria( $criteria );
		}
		return( $gridSet );
	}

	public function _getGridSetFromAliasRecord( $gridName, $alias, Grid $sourceGrid, Criteria $criteria = null ){
		$gridSet = $this->_newGridSet( $gridName, $sourceGrid, $alias );
		if( $criteria instanceof Criteria ){
			$gridSet->setSourceCriteria( $criteria );
		}
		return( $gridSet );
	}

	public function _getGridSetFromCriteria( $gridName, Criteria $criteria ){
		return( $this->_newGridSet( $gridName, $criteria ) );
	}

	public function _newGrid( $gridName ){
		$this->checkSupportedGrid( $gridName );
		$gridName = $this->gridKeyName( $gridName );
		// TODO: provide cloning of prototypes, and add special notes
		// to the documentation in Extending Base Classes about the
		// necessity of overriding this (and still calling parent::__clone)
		// on object inheriting Grid which contain their own references.
		if( !$this->cachedPrototype( $gridName ) ){
			$class = $this->gridClass( $gridName );
			$grid = new $class( $this, $gridName );
			foreach( $this->_getColumns( $gridName ) as $columnName => $xmlColumn ){
				$class = $this->columnClass( $gridName, $columnName );
				$grid->addColumn( new $class( $this, $columnName, $xmlColumn, $grid ) );
			}
			$this->cachePrototype( $grid );
		}
		return( clone $this->cachedPrototype( $gridName ) );
	}

	// Works by finding the relationship between the target and source, verifying that
	// the foreign key reference in the source record hasData(), that sufficient references
	// exist between the foreign key references and either the primary or other unique key(s)
	// of the target table, and instantiates a grid of the target type pre-populated with the
	// identified key data.
	public function _newGridFromRecord( $gridName, Grid $record, $alias = false ){
		if( !$this->canReference( $gridName, $record, $alias ) ){
			$this->throwException( $record->_getObjName().' can not reference '.$gridName.( $alias ? ' as '.$alias : '' ) );
		}
		$targetGrid = $this->_newGrid( $this->gridKeyName( $gridName ) );
		$setCommand = self::OPERATION_SET.( $alias ? $alias : $record->_getObjName() );
		$targetGrid->$setCommand( $record );
		return( $targetGrid );
	}

	// The argument order is such as it is on this method in order to allow for cached calls
	// to be established containing fixed strings for the firts two arguments, which is
	// imperitive for the nature of the invocation.  Seeing as how all the other factory
	// components exis in the generic mechanisms, it also makes sense to gently extend that
	// behavior rather than re-implement here.
	public function _newGridFromAliasRecord( $gridName, $alias, Grid $record ){
		return( $this->_newGridFromRecord( $gridName, $record, $alias ) );
	}

	public function _newGridFromCriteria( $gridName, Criteria $criteria ){
	}

	public function _newGridSet( $gridName = null, $sourceContent = null, $alias = false ){
		return( new GridSet( $this, $gridName, $sourceContent, $alias ) );
	}

	// Guts
	// TODO: in PHP 5.3.0 there's a __callStatic() method we'll take advantage of (when that
	// becomes the standard version available) that will help flesh out the singleton
	// pattern by calling getInstance() and returning the resulting __call map.
	public function __call( $function, $arguments ){
		$this->checkInitialized();
		$function = strtolower( $this->makeKeyName( $function ) );
		if( $this->cachedCall( $function ) ){
			return( $this->callCachedCall( $function, $arguments ) );
		}
		$operation = $this->detectOperation( $function );
		$funcRemainder = strtolower( substr( $function, strlen( $operation ) ) );
		$return = null;
		switch( $operation ){
			case self::OPERATION_CAN:
				$return = $this->can( $funcRemainder );
				break;
			case self::OPERATION_NEW:
				$targetGridType = null;
				if( $this->supportedGrid( $funcRemainder ) ){
					// WARNING: Magic string '_newGrid' rather than constant or verified
					// contents.  TODO: Fix this.
					$this->cacheCall( $function, '_newGrid', $funcRemainder );
					$return = $this->_newGrid( $funcRemainder );
				} else if(
					substr(
						$funcRemainder,
						( -1 * strlen( self::OPERATION_GET_SET ) )
					) == self::OPERATION_GET_SET
				){
					$targetGridType = substr( $funcRemainder, 0, ( -1 * strlen( self::OPERATION_GET_SET ) ) );
					if( $this->supportedGrid( $targetGridType ) ){
						$return = $this->_newGridSet(
							$targetGridType,
							array_shift( $arguments ),
							array_shift( $arguments )
						);
					} else {
						$this->throwException( 'Unrecognized or unsupported grid type "'.$targetGridType.'" requested' );
					}
				// TODO: support "new<Grid>Set<From><X>" ?  The pattern is implicitly supported
				// in the generics, but would require extra parsing here.
				} else {
					$this->throwException( 'Unrecognized or unsupported method/grid "'.$function.'"' );
				}
				break;
			case self::OPERATION_GET:
				if( $this->supportedGrid( $funcRemainder ) ){
					// Assume we're getting it from either an ID or a unique constraint
					array_unshift( $arguments, $funcRemainder );
					$return = call_user_func_array( array( $this, '_getGridById' ), $arguments );
				} else {
					$byId = self::OPERATION_MOD_BY.self::VALUE_ID;
					if(
						(
							strpos( $funcRemainder, $byId ) + strlen( $byId )
						) == strlen( $funcRemainder )
					){
						$targetGridType = substr( $funcRemainder, 0, strpos( $funcRemainder, $byId ) );
						array_unshift( $arguments, $targetGridType );
						$return = call_user_func_array( array( $this, '_getGridById' ), $arguments );
					} else if( strpos( $funcRemainder, self::OPERATION_MOD_FROM ) ){
						// TODO: Any known relationship should already be supported and known via
						// the cachedCall map, which we've prepopulated (and ostensibly will maintain
						// during any dynamic supported object modification).  So should this even
						// be possible?
						// Get a Grid instance from...
						$targetGridType = null;
						$sourceGridType = null;

						list( $source, $target ) = explode( self::OPERATION_MOD_FROM, $funcRemainder );
						print_r( $source );
						print_r( $target );

						// A record of unknown type
						// Another Record of known type
					}
				}
				break;
			default:
				if( $this->supportedGrid( $function ) ){
					$columns = array_keys( $this->_getColumns( $function ) );
					$return = (object)array_combine( $columns, $columns );
				} else {
					$this->throwException( 'Unrecognized function "'.$function.'" requested on '.get_class( $this ) );
				}
				break;
		}
		return( $return );
	}

	public function __get( $name ){
		$this->checkSupportedGrid( $name );
		return( $this->makeKeyName( $name ) );
	}


	// Cache functions
	public function can( $callName ){
		return( $this->cachedCall( $callName ) );
	}
	protected function cachedCall( $callName ){
		return( isset( $this->_cachedCalls{ $this->makeKeyName( $callName ) } ) );
	}
	protected function cacheCall( $callName, $mappedCall ){
		// WARNING: Expeditious use of magic number corresponding
		// to the number of known arguments in caching this call
		$this->_cachedCalls{ $this->makeKeyName( $callName ) } = array(
			$mappedCall => array_slice( func_get_args(), 2 )
		);
	}
	protected function callCachedCall( $callName, $newArgs ){
		$callName = $this->makeKeyName( $callName );
		if( !$this->cachedCall( $callName ) ){
			$this->throwException( 'Unknown cached call "'.$callName.'"' );
		}
		// 2 step process to play nice with E_STRICT
		list( $call ) = array_keys( $this->_cachedCalls{ $callName } );
		// WARNING: Magic numbers (again) used to splice off the number of known arguments to get
		// the number of unknown arguments.
		$args = array_merge( $this->_cachedCalls{ $callName }{ $call }, $newArgs );
		return( call_user_func_array( array( $this, $call ), $args ) );
	}

	protected function cachedPrototype( $gridName ){
		$gridName = $this->gridKeyName( $gridName );
		return( ( isset( $this->_prototypeCache{ $gridName } ) ? $this->_prototypeCache{ $gridName } : false ) );
	}
	// Warning: These 2 names differ only by a single character.
	protected function cachePrototype( Grid $grid ){
		if( $this->cachedPrototype( $grid ) ){
			trigger_error( 'Overwriting existing prototype cache for grid '.$grid->_getObjName(), E_USER_WARNING );
		}
		return( $this->_prototypeCache{ $grid->_getObjName() } = $grid );
	}

	//***********************
	//*  Utility Functions  *
	//***********************
	public static function throwException( $msg = 'An unkown error has occurred' ){
		throw( new TorporException( $msg ) );
	}

	// Takes a Grid or (string) argument and either retrieves or
	// sanitizes to return the key name version of the same.
	public static function gridKeyName( $gridName ){
		if( $gridName instanceof Grid ){
			$gridName = $gridName->_getObjName();
		} else {
			$gridName = self::makeKeyName( $gridName );
		}
		return( $gridName );
	}

	public static function makeKeyName( $name ){
		$keyName = strtoupper( preg_replace( '/'.self::REGEX_KEYNAME.'/', '', $name ) );
		if( preg_match( '/^\d/', $keyName ) ){
			self::throwException( 'First character of key names musb be alphabetical' );
		}
		return( $keyName );
	}

	public static function xmlObjKeyName( SimpleXMLElement $xmlObj ){
		$name = self::makeKeyName( (string)$xmlObj->attributes()->name );
		if( !$name ){ $name = self::makeKeyName( (string)$xmlObj->attributes()->dataName ); }
		return( $name );
	}

	public static function checkClass( $className, $gridColumn ){
		if( empty( $className ) || !class_exists( $className ) ){
			self::throwException( 'Undefined '.( $gridColumn ? 'grid' : 'column' ).' class "'.$className.'" requested' );
		}
		if(
			(
				$gridColumn
				&& (
					$className != self::DEFAULT_GRID_CLASS
					&& !in_array( self::DEFAULT_GRID_CLASS, class_parents( $className ) )
				)
			) || (
				!$gridColumn
				&& (
					$className != self::DEFAULT_COLUMN_CLASS
					&& !in_array( self::DEFAULT_COLUMN_CLASS, class_parents( $className ) )
				)
			)
		){
			trigger_error(
				'Requested '.( $gridClass ? 'grid' : 'column' ).' class "'.$className.'" does not appear to inherit from '
				.( $gridClass ? self::DEFAULT_GRID_CLASS : self::DEFAULT_COLUMN_CLASS )
				.' - I hope you know what you\'re doing', E_USER_WARNING
			);
		}
		return( true );
	}
	public static function checkColumnClass( $className ){ return( self::checkClass( $className, false ) ); }
	public static function checkGridClass( $className ){ return( self::checkClass( $className, true ) ); }

	public static function parseDataTypeMap( SimpleXMLElement $dataTypeMap ){
		$mapArray = array();
		foreach( $dataTypeMap->children() as $map ){
			$type = strtoupper( (string)$map->attributes()->type );
			if( !in_array( $type, Column::getValidTypes() ) ){
				$this->throwException( 'Unrecognized column type "'.$type.'"' );
			}
			$className = (string)$map->attributes()->class;
			self::checkColumnClass( $className );
			$mapArray{ $type } = $className;
		}
		return( $mapArray );
	}

	public static function detectOperation( $functionName, $compound = false ){
		$functionName = strtolower( self::makeKeyName( $functionName ) );
		$operation = false;
		if( strpos( $functionName, self::OPERATION_IS ) === 0 ){
			$operation = self::OPERATION_IS;
		} else {
			$operationPrefix = substr( $functionName, 0, self::COMMON_OPERATION_LENGTH );
			if(
				in_array(
					$operationPrefix,
					array(
						self::OPERATION_ADD,
						self::OPERATION_CAN,
						self::OPERATION_GET,
						self::OPERATION_MAP,
						self::OPERATION_NEW,
						self::OPERATION_SET
					)
				)
			){
				$operation = $operationPrefix;
			}
		}
		return( $operation );
	}

	public static function getFileInPath( $fileName ){
		$return = false;
		$paths = explode( PATH_SEPARATOR, get_include_path() );
		foreach( $paths as $path ){
			if( empty( $path ) ){ continue; }
			$testFileName = $path.'/'.$fileName;
			if( file_exists( $testFileName ) ){
				if( !is_readable( $testFileName ) ){
					trigger_error( 'Found file "'.$fileName.'", but can\'t read it.', E_USER_WARNING );
				}
				$return = $testFileName;
				break;
			}
		}
		return( $return );
	}
}

class TorporException extends Exception {}

function Torpor(){
	return( Torpor::getInstance() );
}

?>