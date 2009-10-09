<?PHP
// $Rev$
// TODO: phpdoc
// TODO: Logically organize methods.
class GridSet extends PersistableContainer implements Iterator {
	const ADJECTIVE_FIRST = 'first';
	const ADJECTIVE_NEXT  = 'next';

	const ORDER_ASCENDING  = 'ASCENDING';
	const ORDER_DESCENDING = 'DESCENDING';
	const ORDER_RANDOM     = 'RANDOM';

	private $_type = null;
	private $_grids = array();

	private $_totalGridCount = -1;
	private $_pageSize = -1;
	private $_gridOffset = 0;
	private $_sort = array();

	private $_sourceGrids = array();
	private $_sourceGridAlias = false;
	private $_sourceCriteria = null;


	public function GridSet(
		Torpor $torpor,
		$gridType = null,
		$sourceContent = null,
		$sourceGridAlias = null
	){
		parent::__construct( $torpor );
		if( !empty( $gridType ) ){
			$this->setType( $gridType );
		}
		if( !empty( $sourceContent ) ){
			if( $sourceContent instanceof Grid ){
				$this->setSourceGrid( $sourceContent, $sourceGridAlias );
			} else if( $sourceContent instanceof Criteria ){
				$this->setSourceCriteria( $sourceContent );
			}
		}
		$this->setPageSize( $this->Torpor()->pageSize() );
	}

	public function getPageSize(){ return( $this->_pageSize ); }
	public function setPageSize( $pageSize ){ return( $this->_pageSize = max( (int)$pageSize, -1 ) ); }

	public function getPageOffset(){
		return(
			(
				$this->getPageSize() > 0
				? floor( $this->getGridOffset() / $this->getPageSize() )
				: 0
			)
		);
	}
	public function setPageOffset( $pageOffset ){
		return(
			$this->setGridOffset( ( $this->getPageSize() >= 0 ? max( (int)$pageOffset, 0 ) * $this->getPageSize() : 0 ) )
		);
	}

	public function getGridOffset(){ return( $this->_gridOffset ); }
	public function setGridOffset( $gridOffset ){ return( $this->_gridOffset = max( (int)$gridOffset, 0 ) ); }

	public function getNumberOfPages(){
		$pageCount = -1;
		if( $this->getPageSize() < 0 ){
			$pageCount = 1;
		} else {
			if( $this->isLoaded() ){
				$pageCount = ceil( $this->getTotalGridCount() / $this->getPageSize() );
			} else if( $this->getGridCount() > 0 ){
				$pageCount = ceil( $this->getGridCount() / $this->getPageSize() );
			}
		}
		return( $pageCount );
	}

	public function resetSort(){
		$this->_sort = array();
		return( true );
	}

	public function sortBy( $gridName, $columnName = null, $order = self::ORDER_ASCENDING ){
		$gridType = $this->gridType();
		if( $gridName instanceof Column ){
			list( $gridName, $columnName ) = array( $gridName->Grid(), $gridName );
			if( in_array( $columnName, self::getValidSortOrders() ) ){
				$order = $columnName;
			}
		} else if(
			!$this->Torpor()->supportedGrid( $gridName )
			&& !is_null( $gridType )
			&& isset( $this->Torpor()->$gridType()->$gridName )
		){
			if( in_array( $columnName, self::getValidSortOrders() ) ){
				$order = $columnName;
			}
			list( $gridName, $columnName ) = array( $gridType, $gridName );
		}
		$gridName = $this->Torpor()->containerKeyName( $gridName );
		$columnName = $this->Torpor()->containerKeyName( $columnName );
		if( empty( $gridName ) || empty( $columnName ) ){
			$this->throwException( 'Invalid grid ("'.$gridName.'") or column ("'.$columnName.'")' );
		}
		if( !in_array( $order, self::getValidSortOrders() ) ){
			$this->throwException( 'Unrecognized sort order "'.$order.'" requested' );
		}
		$this->_sort[] = array( $gridName, $columnName, $order );
		return( $this );
	}

	public function getSortOrder(){
		return( $this->_sort );
	}

	public function getSourceGrid( $gridName ){
		$gridName = $this->Torpor()->containerKeyName( $gridName );
		$return = false;
		$grids = $this->getSourceGrids();
		if( isset( $grids{ $gridName } ) ){
			$return = $grids{ $gridName };
		}
		return( $return );
	}
	public function getSourceGrids(){ return( $this->_sourceGrids ); }

	public function getSourceCriteria(){ return( $this->getCriteria() ); }
	public function getCriteria(){ return( $this->_sourceCriteria ); }

	public function setSourceGrid( Grid $grid, $alias = false, $cascadeMap = true ){
		// New logic:
		// Be able to set a source grid at any time, with automatic cascading.
		// This will take place so long as either A) the type has not yet been
		// established, or B) the established type canReference( $grid );

		$existingGrid = false;
		if( $alias ){
			$alias = $this->Torpor()->makeKeyName( $alias );
		}
		if(
			!is_null( $this->gridType() )
			|| ( $this->Torpor()->canReference( $this->gridType(), $grid, $alias ) )
		){
			$existingGrid = $this->getSourceGrid( ( $alias ? $alias : $grid ) );
			if( $existingGrid !== $grid ){
				$this->_sourceGrids{ ( $alias ? $alias : $grid->_getObjName() ) } = $grid;
				if( $this->getGridCount() > 0 && $cascadeMap ){
					$this->mapAll( $grid, $alias );
				}
			}
		} else {
			$this->throwException(
				'Cannot set new source grid '.$grid->_getObjName()
				.'; no reference between '.$this->gridType().' and '
				.$grid->_getObjName().( $alias ? ' as '.$alias : '' )
			);
		}
		return( true );
	}

	public function setSourceCriteria( CriteriaBase $criteria ){ return( $this->setCriteria( $criteria ) ); }
	public function setCriteria( CriteriaBase $criteria ){
		if(
			!is_null( $this->getSourceCriteria() )
			&& $this->getSourceCriteria() !== $criteria
			&& $this->getGridCount() > 0
		){
			$this->throwExcetion( 'Cannot set new source criteria; grids already added to set.' );
		}
		$this->_sourceCriteria = $criteria;
		return( true );
	}

	public function addCriteria( CriteriaBase $criteria ){
		if( is_null( $this->getCriteria() ) ){
			$this->setCriteria( new CriteriaAndSet() );
		} else if( !$this->getCriteria() instanceof CriteriaAndSet ){
			$this->setCriteria( new CriteriaAndSet( $this->getCriteria() ) );
		}
		$this->getCriteria()->addCriteria( $criteria );
	}

	public function canLoad(){
		return( !is_null( $this->gridType() ) );
	}

	public function Load( $refresh = false, $reset = false ){
		if( !$this->canLoad() && $refresh ){
			$this->throwException( 'Cannot load '.$this->gridType().' grid set: insufficient criteria (need type, and 1 or more of source grid or criteria)' );
		}
		$return = $this->isLoaded();
		if( $this->canLoad() && ( !$this->isLoaded() || $refresh ) ){
			if(
				( $this->isLoaded() || $this->getGridCount() > 0 )
				&& $reset
			){
				trigger_error( 'Truncating existing record list for '.$this->gridType().' grid set in Load', E_USER_WARNING );
				// TODO: Determine the reference counts for objects?:
				// If this is the only place where these are referenced, the objects should be
				// destroyed and unset prior to truncating this array, otherwise they'll hang
				// around taking up memory.
				$this->_grids = array();
				$this->_setLoaded( false );
			}
			$return = $this->Torpor()->Load( $this, $refresh );
		}
		return( $return );
	}


	public function LoadNextPage(){
		if( !$this->isLoaded() ){
			return( $this->Load() );
		}
		$return = false;
		if( $this->getPageSize() >= 0 ){
			if( $this->getGridOffset() < ( $this->getPageSize() * $this->getNumberOfPages() ) ){
				$this->setGridOffset( $this->getGridOffset() + $this->getPageSize() );
				$currentCount = $this->getGridCount();
				if( $this->Load( true ) && $this->getGridCount() > $currentCount ){
					$return = true;
				}
			}
		}
		return( $return );
	}

	public function setLoaded( $bool = true ){ return( $this->_setLoaded( $bool ) ); }

	public function Publish( $force = false ){
		$publishCount = 0;
		for( $i = 0; $i < $this->getGridCount(); $i++ ){
			if( $this->getGrid( $i )->Publish( $force ) ){
				$publishCount++;
			}
		}
		return( $publishCount );
	}

	// TODO: Need an option to pass the delete into the data store
	// to delete everything (not just currently loaded)?
	public function Delete(){
		$deleteCount = 0;
		for( $i = 0; $i < $this->getGridCount(); $i++ ){
			if( $this->getGrid( $i )->Delete() ){
				$deleteCount++;
			}
		}
		return( $deleteCount );
	}

	// TODO: This needs a lot of explicit documentation as to the behaviors, and when to attempt which
	// ones.  As it currently stands (revision 14), calling set<Grid> on an object of this class, even if
	// it's of a different alias, will cause the orinal mapping to be lost (but not the object associations).
	// It is becoming increasingly apparent that the way to go with this is to allow a series of grids to be
	// associated, one per relationship type, and that this may somehow need to be mapped together with the
	// criteria storage.
	public function mapGrid( Grid $grid, $alias = false ){
		$this->setSourceGrid( $grid, $alias );
		return( $this->mapAll( $grid, $alias ) );
	}
	public function mapCriteria( Criteria $criteria ){
		// TODO: Add to top-level criteria (AND/OR/XOR/NOT?)
		return( $this->mapAll( $criteria ) );
	}
	protected function mapAll( $content, $alias = false ){
		if( $this->getGridCount() > 0 ){
			// Using old-style (!foreach) iteration in order to avoid reseting internal
			// iteration pointers.
			for( $i = 0; $i < $this->getGridCount(); $i++ ){
				$this->mapContent( $this->_grids[$i], $content, $alias );
			}
		}
		return( true );
	}
	protected function mapContent( Grid $grid, $content, $alias = false ){
		$return = false;
		if( $content instanceof Grid  ){
			$setCommand = Torpor::OPERATION_SET.( $alias ? $alias : $content->_getObjName() );
			$grid->$setCommand( $content );
			$return = true;
		} else if( $content instanceof Criteria ){
			// TODO: Add deterministic criteria (if we have any) which maps the source
			// criteria onto the incoming grid object.
			$return = true;
		} else {
			$this->throwException( 'Unable to map unrecognized content type' );
		}
		return( $return );
	}

	// Generic accessors <verb> and <verb><adjective> can be combined
	// with <verb>[<adjective>]<noun> via the __call interface, making it
	// possible to add<Grid> and getFirst<Grid>, etc.
	// Returns true if a grid is successfully added to the collection.  The only
	// ways this will not be true is if we thow an exception or if the grid already
	// exists within the collection (only detected by looking to see if it's the
	// exact same object, no other de-deplication is being done; that's up to the
	// caching engine).
	public function add( Grid $grid, $setGridCriteria = true ){
		return( $this->addGrid( $grid, $setGridCriteria ) );
	}
	public function addGrid( Grid $grid, $setGridCriteria = true ){
		if( !is_null( $this->gridType() ) && !$this->checkNoun( $grid->_getObjName() ) ){
			$this->throwException( 'Grid type mismatch, "'.$grid->_getObjName().'" cannot be added to collection of type '.$this->gridType() );
		}
		$return = false;
		if( !in_array( $grid, $this->getSourceGrids(), true ) ){
			if( $setGridCriteria ){
				foreach( $this->getSourceGrids() as $gridRelationship => $sourceGrid ){
					$this->mapContent( $grid, $sourceGrid, $gridRelationship );
				}
				if( !is_null( $this->getSourceCriteria() ) ){
					$this->mapContent( $grid, $this->getSourceCriteria() );
				}
			}
			$this->_grids[] = $grid;
			$return = true;
		}
		return( $return );
	}

	public function type(){ return( $this->gridType() ); }
	public function recordType(){ return( $this->gridType() ); }
	public function gridType(){
		if( is_null( $this->_type ) && $this->getGridCount() > 0 ){
			// First one in determines what we can hold.
			$this->setType( $this->_grids[0]->_getObjName() );
		}
		return( $this->_type );
	}
	protected function setType( $gridType ){
		$gridType = $this->Torpor()->containerKeyName( $gridType );
		if( empty( $gridType ) || !is_string( $gridType ) ){
			$this->throwException( 'Invalid grid type (empty or not a string)' );
		} else if( !$this->Torpor()->supportedGrid( $gridType ) ){
			$this->throwExcetion( 'Unkown or unsupported grid "'.$gridType.'"' );
		}
		$this->_type = $gridType;
	}

	protected function checkNoun( $noun ){
		$pass = false;
		if(
			!is_null( $noun )
			&& in_array(
				strtolower( $noun ),
				array(
					'grid',   // Used only once, right here; should they still be
					'record', // turned into constants?
					strtolower( $this->gridType() )
				)
			)
		){
			$pass = true;
		}
		return( $pass );
	}

	// These are essentially facades and aliases to the built-in iterator
	// interfaces which we've already abstracted on top of $_grids, but are
	// provided fo convenience and ease of documentation.
	public function getFirstGrid(){
		$this->rewind();
		return( $this->current() );
	}
	public function getCurrentGrid(){ return( $this->current() ); }
	public function getNextGrid(){ return( $this->next() ); }
	public function getGrid( $offset ){
		return( ( isset( $this->_grids[ (int)$offset ] ) ? $this->_grids[ (int)$offset ] : null ) );
	}

	public function setTotalGridCount( $count ){
		return( $this->_totalGridCount = (int)$count );
	}

	public function getTotalRecordCount(){ return( $this->getTotalGridCount() ); }
	public function getTotalGridCount(){ return( $this->getGridCount( true ) ); }
	public function getRecordCount(){ return( $this->getGridCount() ); }
	public function getGridCount( $total = false ){ return( ( $total ? $this->_totalGridCount : count( $this->_grids ) ) ); }

	// Used so we can have descriptive names, such as add<Grid>, etc.
	public function __call( $function, $arguments ){
		$return = null;
		// add<Grid>
		// getFirst<Grid>
		// getNext<Grid>
		$operation = $this->Torpor()->detectOperation( $function );
		$funcRemainder = strtolower( substr( $function, strlen( $operation ) ) );
		switch( $operation ){
			case Torpor::OPERATION_ADD:
				if( !is_null( $this->gridType() ) && !$this->checkNoun( $funcRemainder ) ){
					$this->throwException( 'Unrecognized operation or incorrect grid type "'.$funcRemainder.'"' );
				}
				if( count( $arguments ) > 1 ){
					$return = 0;
					foreach( $arguments as $grid ){
						if( $this->addGrid( $grid ) ){
							$return++;
						}
					}
				} else {
					$return = $this->addGrid( array_shift( $arguments ) );
				}
				break;
			case Torpor::OPERATION_GET:
				// See if we have an adjective
				$adjective = false;
				if( strpos( $funcRemainder, self::ADJECTIVE_FIRST ) === 0 ){
					$adjective = self::ADJECTIVE_FIRST;
				} else if( strpos( $funcRemainder, self::ADJECTIVE_NEXT ) === 0 ){
					$adjective = self::ADJECTIVE_NEXT;
				}
				if( $adjective !== false ){
					$funcRemainder = substr( $funcRemainder, strlen( $adjective ) );
				}
				if( !$this->checkNoun( $funcRemainder ) ){
					$this->throwException( 'Unrecognized operation or incorrect grid type "'.$funcRemainder.'"' );
				}
				switch( $adjective ){
					case self::ADJECTIVE_FIRST:
						$return = $this->getFirstGrid();
						break;
					case self::ADJECTIVE_NEXT:
						$return = $this->getNextGrid();
						break;
					default:
						$return = $this->getCurrentGrid();
						break;
				}
				break;
			case Torpor::OPERATION_MAP: // Grouping these together because the conditionals are
			case Torpor::OPERATION_SET: // the same.  Requires extra comparison of $operation, but OK
				if( count( $arguments ) > 0 && $arguments[0] instanceof Grid ){
					$grid = array_shift( $arguments );
					$alias = false;
					if( $this->Torpor()->makeKeyName( $funcRemainder ) != $grid->_getObjName() ){
						$alias = $funcRemainder;
					} else if( count( $arguments ) > 0 ){
						$alias = array_shift( $arguments );
					}
					$this->setSourceGrid( $grid, $alias );
				} else {
					$this->throwException( 'Invalid argument to method '.$function );
				}
				break;
			default:
				$this->throwException( 'Unrecognized method "'.$function.'" requested on '.get_class( $this ) );
				break;
		}
		return( $return );
	}

	public static function getValidSortOrders(){
		return(
			array(
				self::ORDER_ASCENDING,
				self::ORDER_DESCENDING,
				self::ORDER_RANDOM
			)
		);
	}


	// Iterator interface implementation for accessing grids
	public function rewind(){
		if(
			!$this->isLoaded()
			&& $this->getGridCount() == 0
			&& $this->canLoad()
		){
			$this->Load();
		}
		reset( $this->_grids );
	}
	public function current(){ return( current( $this->_grids ) ); }
	public function key(){ return( key( $this->_grids ) ); }
	public function next(){ return( next( $this->_grids ) ); }
	public function valid(){ return( $this->current() !== false ); }
}

class TypedGridSet extends GridSet {
	// TODO: find a way to provide this functionality to both TypedGridSet
	// and TypedGrid without replicating it verbosely between classes.
	public function __construct(){
		$args = func_get_args();
		$torpor = null;
		foreach( $args as $index => $arg ){
			if( $arg instanceof Torpor ){
				$torpor = $arg;
				unset( $args[ $index ] );
				break;
			}
		}
		$this->_setTorpor( ( $torpor instanceof Torpor ? $torpor : Torpor::getInstance() ) );
		$gridTypeName = get_class( $this );
		if( $prefix = $this->Torpor()->typedGridClassesPrefix() ){
			$gridTypeName = substr( $gridTypeName, strlen( $prefix ) );
		}
		$gridTypeName = substr( $gridTypeName, 0, ( -1 * strlen( Torpor::OPERATION_GET_SET ) ) );
		if( !$this->Torpor()->supportedGrid( $gridTypeName ) ){
			$this->throwException( 'Could not resolve grid type from class name "'.get_class( $this ).'"' );
		}
		$this->setType( $gridTypeName );

		foreach( $args as $arg ){
			if( $arg instanceof CriteriaBase ){
				$this->addCriteria( $arg );
			} else if( $arg instanceof Grid ){
				$this->setSourceGrid( $arg );
			}
		}
	}
}

?>
