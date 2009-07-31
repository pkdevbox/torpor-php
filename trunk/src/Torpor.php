<?PHP
// $Rev$
// This should be redundant with lazy loading, but redunance
// in some cases is better than broken in others.
require_once( 'PersistableContainer.php' );
require_once( 'Grid.php' );
require_once( 'Column.php' );
require_once( 'Criteria.php' );

// TODO: phpdoc
// TODO: Review all singleton vs. object context interfaces and make
// certain that there are no object context cases that will revert to
// the singleton, which would thoroughly confuse the user and be a very
// nasty bug to sort out.
// TODO: CLEANUP: Order the methods according to some logical paradigm.
class Torpor {
	const VERSION = 0.1;

	// Options
	const OPTION_OVERWRITE_ON_LOAD  = 'OverwriteOnLoad';
	const DEFAULT_OVERWRITE_ON_LOAD = false;

	const OPTION_GRID_CLASS  = 'DefaultGridClass';
	const DEFAULT_GRID_CLASS = 'Grid';

	const OPTION_COLUMN_CLASS  = 'DefaultColumnClass';
	const DEFAULT_COLUMN_CLASS = 'Column';

	const VALUE_TRUE = 'true';
	const VALUE_NONE = 'none';
	const VALUE_SEPARATOR = '|';

	// Utility
	// These are all the characters which will be stripped.
	const REGEX_KEYNAME = '[^A-Za-z0-9]';

	// Internal use only
	const ARKEY_GRIDS        = 'grids';
	const ARKEY_GRID_CLASSES = 'grid_classes';
	const ARKEY_COLUMNS      = 'columns';
	const ARKEY_KEYS         = 'keys';
	const ARKEY_UNIQUEKEYS   = 'uniquekeys';
	const ARKEY_REFERENCES   = 'references';
	const ARKEY_OPTIONS      = 'options';

	const OPERATION_CAN = 'can';
	const OPERATION_GET = 'get';
	const OPERATION_NEW = 'new';
	const OPERATION_SET = 'set';
	const OPERATION_GET_SET = 'set';
	const OPERATION_MOD_FROM = 'from';
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
	private $_dataEngine;
	private $_xmlConfig = null;
	private $_config = array();
	private $_isInitialized = false;

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
		if( !$this->isInitialized() ){
			$this->throwException( get_class( $this ).' not initialized, cannot continue' );
		}
	}

	public static function Version(){ return( self::VERSION ); }

	public function initialize( $config ){
		$instance = null;
		if( isset( $this ) && $this instanceof self ){
			// Called in object context
			$instance = $this;
		} else {
			// Called in static context
			$instance = self::getInstance();
		}
		// Test if it's a file name...
		// Test if it's a URL...
		// Test if it's a config type...
		// Test if it's XML...
		$xml = $config;
		return( $instance->processConfig( $xml ) );
	}

	public static function defaultOptions(){
		return(
			array(
				self::OPTION_OVERWRITE_ON_LOAD => self::DEFAULT_OVERWRITE_ON_LOAD,
				self::OPTION_GRID_CLASS        => self::DEFAULT_GRID_CLASS,
				self::OPTION_COLUMN_CLASS      => self::DEFAULT_COLUMN_CLASS
			)
		);
	}

	public function processConfig( $xml ){
		$return = false;
		// TODO: Test against XSD; possibly throw or propagate any of those errors
		// as an exception?
		// if( !Torpor::isValidConfig( $xml ) ) {
		//	throw( new Exception( 'Invalid configuration xml' ) );
		// }
		$this->_xmlConfig = $xml;
		$xmlObj = simplexml_load_string( $xml );
		$options = self::defaultOptions();
		$grids = array();
		$gridClasses = array();
		$columns = array();
		$keys = array();
		$uniqueKeys = array();
		$references = array();

		// TODO:
		// 1. Parse Options
		// 2. Set up data store
		foreach( $xmlObj->Grids->Grid as $xmlGrid ){
			$gridName = $this->xmlObjKeyName( $xmlGrid );
			if( !$gridName ){
				$this->throwException( 'Could not extract a suitable grid name' );
			}
			if( isset( $grids{ $gridName } ) ){
				$this->throwException( 'Duplicate grid name '.$gridName );
			}
			$grids{ $gridName } = (string)$xmlGrid->attributes()->dataName;
			$columns{ $gridName } = array();
			$keys{ $gridName } = array();
			$uniqueKeys{ $gridName } = array();
			$references{ $gridName } = array();
			if( $className = (string)$xmlGrid->attributes()->class ){
				if( $className == self::VALUE_NONE ){
					$className = self::DEFAULT_GRID_CLASS;
				} else {
					if( !class_exists( $className ) ){
						$this->throwException( 'Undefined class "'.$className.'" requested for grid '.$gridName );
					}
					if(
						$className != self::DEFAULT_GRID_CLASS
						&& !in_array( self::DEFAULT_GRID_CLASS, class_parents( $className ) )
					){
						trigger_error(
							'Requested class "'.$className.'" does not appear to inherit from '
							.self::DEFAULT_GRID_CLASS.' for grid '.$gridName
							.' - I hope you know what you\'re doing', E_USER_WARNING
						);
					}
				}
				$gridClasses{ $gridName } = $className;
			}

			foreach( $xmlGrid->Columns->Column as $xmlColumn ){
				$columnName = $this->xmlObjKeyName( $xmlColumn );
				if( !$columnName ){
					$this->throwException( 'Could not extract a suitable column name' );
				}
				if( isset( $columns{ $gridName }{ $columnName } ) ){
					$this->throwException( 'Duplicate column name '.$columnName.' on grid '.$gridName );
				}
				if( $this->makeKeyName( (string)$xmlColumn->attributes()->type ) == Column::TYPE_CLASS ){
					$className = (string)$xmlColumn->attributes()->class;
					if( $className != self::VALUE_NONE ){
						if( !class_exists( $className ) ){
							$this->throwException( 'Undefined class "'.$className.'" requested for column '.$columnName.' on grid '.$gridName );
						}
						if(
							$className != self::DEFAULT_COLUMN_CLASS 
							&& !in_array( self::DEFAULT_COLUMN_CLASS, class_parents( $className ) )
						){
							trigger_error(
								'Requested class "'.$className.'" does not appear to inherit from '
								.self::DEFAULT_COLUMN_CLASS.' for column '.$columnName.' on grid '.$gridName
								.' - I hope you know what you\'re doing', E_USER_WARNING
							);
						}
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
					$targetColumnName = $this->makeKeyName( (string)$xmlKey->attributes()->referenceColumn );
					$references{ $gridName }{ $targetGrid }{ $columnName } = $targetColumnName;
				}
			}
			$this->cacheCall( 'new'.$gridName, '_newGrid', $gridName );
		}

		// TODO: Where/how to cache this, as identified by the resource initially fed to
		// us (in order to rapidly reload on successive iterations)?
		$this->_config = array(
			self::ARKEY_OPTIONS      => $options,
			self::ARKEY_GRIDS        => $grids,
			self::ARKEY_GRID_CLASSES => $gridClasses,
			self::ARKEY_COLUMNS      => $columns,
			self::ARKEY_KEYS         => $keys,
			self::ARKEY_UNIQUEKEYS   => $uniqueKeys,
			self::ARKEY_REFERENCES   => $references
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
	protected function _getOptions(){ return( $this->_getInitX( self::ARKEY_OPTIONS ) ); }
	protected function _getGrids(){ return( $this->_getInitX( self::ARKEY_GRIDS ) ); }
	protected function _getGridClasses( $grid = null ){ return( $this->_getInitX( self::ARKEY_GRID_CLASSES, $grid ) ); }
	protected function _getColumns( $grid = null ){ return( $this->_getInitX( self::ARKEY_COLUMNS, $grid ) ); }
	protected function _getKeys( $grid = null ){ return( $this->_getInitX( self::ARKEY_KEYS, $grid ) ); }
	protected function _getUniqueKeys( $grid = null ){ return( $this->_getInitX( self::ARKEY_UNIQUEKEYS, $grid ) ); }
	protected function _getReferences( $grid = null ){ return( $this->_getInitX( self::ARKEY_REFERENCES, $grid ) ); }
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

	// Aliases
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
			foreach( $this->_getAllGridKeys( $targetGridName ) as $keys ){
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
		$xmlColumn = $columns{ $columnName };
		// TODO: Need to fall back in this pattern:
		// 1. Column class definition
		// 2. Grid data type class definition
		// 3. Global data type class definition
		// 4. Global column class definition
		// 5. Default column class definition
		$className = $this->options( self::OPTION_COLUMN_CLASS );
		if( $this->makeKeyName( (string)$xmlColumn->attributes()->type ) == Column::TYPE_CLASS ){
			if( $xmlColumn->attributes()->class == self::VALUE_NONE ){
				$className = self::DEFAULT_COLUMN_CLASS;
			} else {
				$className = (string)$xmlColumn->attributes()->class;
			}
		} else {
			// TODO: Continue fallback to as-yet-undefined XML as specified above.
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

	public function _getGridFromId( $gridName ){
		// TODO: any _getGridFromX pattern needs to return bool(false) if
		// the item cannot be loaded?
		// Variable arguments for the ID based on variable numuber of key fields
		// TODO: Look in the internal cache first... or is the internal cache only
		// for use during Load() operations?
	}

	public function _getGridFromUnique( $gridName ){
		// Variable arguments for the ID based on variable numuber of key fields
	}

	public function _getGridFromRecord( $gridName, Grid $record ){
		// TODO: create the GridSet object which allows for the following:
		// 1. Stores a series of Grids which can be iterated over.
		// 2. Allows access by PK (variable argument list corresponding to the number
		//    of members in the object OK)
		// 3. Stores the criteria by which it was selected, and...
		// 4. ...if the criteria corresponds to a back-reference, such as keys in this
		//    Set are positively correlated with a foreign key, then it should be
		//    possible to addRecord() or otherwise push() a record onto the set and
		//    immediately inherit that defining criteria.  Similarly, and perhaps making
		//    direct use of these same features, be able to do a newRecord() call that
		//    creates and adds one for us, returning the related record.
		// 5. Has an addressing scheme (or at least method of iteration) that allows
		//    for the access and manipulation of added records which do not as yet have
		//    a primary key.
		// NOTE: As of this writing, the PHP::ArrayAccess interface is insufficiently
		// mature to be useful, due to its inability to return offsetGet() calls by
		// reference.  Otherwise 'twould be the bomb.
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
				$targetGrid->$targetColumnName = $record->$sourceColumnName;
			}
		}
		if( !$targetGrid->canLoad() ){
			$targetGrid = false;
		}
		return( $targetGrid );
	}

	public function _getGridFromCriteria( $gridName, Criteria $criteria ){
	}

	public function _getGridSetFromRecord( $gridName, Grid $record, Criteria $criteria = null ){
		// TODO: Ensure $criteria is properly passed through from the Grid->get<Grid>Set( $criteria )
		// calls in order to AND them together when retrieving corresponding grid records.
		var_dump( 'getting grid set of type '.$gridName.' from type '.$record->_getObjName() );
	}

	public function _getGridSetFromAliasRecord( $gridName, $alias, Grid $record ){
		var_dump( 'getting grid set of type '.$gridName.' from type '.$record->_getObjName().' as '.$alias );
	}

	public function _getGridSetFromCriteria( $gridName, Grid $record ){
	}

	public function _newGrid( $gridName ){
		$gridName = $this->makeKeyName( $gridName );
		$this->checkSupportedGrid( $gridName );
		// TODO: Issues with deep clonging, basically the only
		// way to do it is to iteratively serialize and deserialize
		// everything in order to clone the columns and not the
		// column references, and that's pretty unlikely to be
		// more performant than newly creating it.
		// if( !$this->cachedPrototype( $gridName ) ){
			$class = $this->gridClass( $gridName );
			$grid = new $class( $this, $gridName );
			foreach( $this->_getColumns( $gridName ) as $columnName => $xmlColumn ){
				$class = $this->columnClass( $gridName, $columnName );
				$grid->addColumn( new $class( $this, $columnName, $xmlColumn ) );
			}
			return( $grid );
			// $this->cachePrototype( $grid );
		// }
		// return( clone $this->cachedPrototype( $gridName ) );
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

	// Guts
	// TODO: in PHP 5.3.0 there's a __callStatic() method we'll take advantage of (when that
	// becomes the standard version available) that will help flesh out the singleton
	// pattern by calling getInstance() and returning the resulting __call map.
	public function __call( $func, $args ){
		$this->checkInitialized();
		$func = strtolower( $this->makeKeyName( $func ) );
		if( $this->cachedCall( $func ) ){
			return( $this->callCachedCall( $func, $args ) );
		}
		$operation = substr( $func, 0, 3 );
		$funcRemainder = substr( $func, 3 );
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
					$this->cacheCall( $func, '_newGrid', $funcRemainder );
					$return = $this->_newGrid( $funcRemainder );
				}
				break;
			case self::OPERATION_GET:
				// Get a Grid instance from...
				$targetGridType = null;
				$sourceGridType = null;
				if( $this->supportedGrid( $funcRemainder ) ){
					// Assume we're getting it from either an ID or a unique constraint
				}
				if( stripos( $funcRemainder, self::OPERATION_MOD_FROM ) ){
					list( $source, $target ) = explode( self::OPERATION_MOD_FROM, $funcRemainder );
					// TODO: Leavning off here...
					print_r( $source );
					print_r( $target );
				}
				// A record of unknown type
				// Another Record of known type
				// Primary Key
				// Unique Key
				break;
			default:
				$this->throwException( 'Unrecognized function "'.$func.'" requested on '.get_class( $this ) );
				break;
		}
		return( $return );
	}

	// Cache functions
	// TODO: Grid instance cache, mechanisms to flush or override
	// TODO: Unsaved object cache and dependency tree for related
	// but unpublished grids that need to cascade, with recursion
	// checking on the cascading publish routines.

	public function can( $callName ){
		return( $this->cachedCall( $callName ) );
	}
	protected function cachedCall( $callName ){
		return( in_array( $this->makeKeyName( $callName ), array_keys( $this->_cachedCalls ) ) );
	}
	protected function cacheCall( $callName, $mappedCall ){
		// WARNING: Expeditious use of magic number corresponding
		// to the number of known arguments in caching this call
		$this->_cachedCalls{ $this->makeKeyName( $callName ) } = array(
			$mappedCall => array_splice( func_get_args(), 2 )
		);
	}
	protected function callCachedCall( $callName, $newArgs ){
		$callName = $this->makeKeyName( $callName );
		if( !$this->cachedCall( $callName ) ){
			$this->throwException( 'Unknown cached call "'.$callName.'"' );
		}
		$call = array_shift( array_keys( $this->_cachedCalls{ $callName } ) );
		// WARNING: Magic numbers (again) used to splice off the number of known arguments to get
		// the number of unknown arguments.
		$args = array_merge( $this->_cachedCalls{ $callName }{ $call }, $newArgs );
		return( call_user_func_array( array( $this, $call ), $args ) );
	}

	protected function cachedPrototype( $gridName ){
		$gridName = $this->gridKeyName( $gridName );
		return( $this->_prototypeCache{ $gridName } );
	}
	// Warning: These 2 names 
	protected function cachePrototype( Grid $grid ){
		if( $this->cachedPrototype( $grid ) ){
			trigger_error( 'Overwriting existing prototype cache for grid '.$grid->_getObjName(), E_USER_WARNING );
		}
		return( $this->_prototypeCache{ $grid->_getObjName() } = $grid );
	}

	// TODO: Figure out the actual error handling mechanisms.
	public function addWarningString( $warning ){ array_push( $this->_warnings, $warning ); }
	public function warnings(){ return( $this->_warnings ); }
	public function nextWarning(){ return( array_shift( $this->_warnings ) ); }
	public function addErrorString( $error ){ array_push( $this->_errors, $error ); }
	public function errors(){ return( $this->_errors ); }
	public function nextError(){ return( array_shift( $this->_errors ) ); }

	// Utility Functions
	public static function throwException( $msg = 'An unkown error has occurred' ){
		throw( new TorporException( $msg ) );
	}

	public static function stringContainsKeywordSubstring( $name ){
		$return = false;
		$name = strtolower( $name );
		if( substr_count( $name, self::OPERATION_MOD_FROM ) ){
			$return = true;
		} else if( substr( $name, ( -1 * strlen( self::OPERATION_GET_SET ) ) ) == self::OPERATION_GET_SET ){
			$return = true;
		} else if( substr( $name, ( -1 * strlen( self::OPERATION_GET_CRITERIA ) ) ) == self::OPERATION_GET_CRITERIA ){
			$return = true;
		}
		return( $return );
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
}

class TorporException extends Exception {}

function Torpor(){
	return( Torpor::getInstance() );
}

?>
