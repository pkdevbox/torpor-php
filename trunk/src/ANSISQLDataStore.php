<?PHP
// $Rev$

// Note that this class does NOT implement the DataStore interface on its own.
// It intentionally leaves gaps which the extending class will need to define
// in order to be compliant.  This is necessary only because some methods
// required by the DataStore interface cannot be cleanly implemented in an
// abstract base class without resorting to hacks (createInstance, for example,
// being a static method, has no idea what class it actually belongs to, and
// get_called_class() is not available prior to PHP 5.3.0; we aim for
// compatibility, and don't want to resort to introspecting the debug_backtrace,
// so it is left undone for now; passing the savings on to you).

// TODO: Finalize public / protected / private
abstract class ANSISQLDataStore {

	private $_torpor = null;
	private $_parameters = array();

	private $_writable = false;

	public static $_publishing = array();
	public static $_parsing = array();

	protected $_criteriaHandlerMap = array();
	protected $_concatOperator = '||';
	protected $asColumnOperator = 'AS';
	protected $asTableOperator = 'AS';

	protected function __construct(){}

	//*********************************
	//*  DataStore Interface Methods  *
	//*********************************
	public function initialize( $writeEnabled, array $settings ){
		// TODO: Evaluate settings.
		if( $writeEnabled ){
			$this->_writable = true;
		}
		$this->_parameters = $settings;
		// Look for setters corresponding with incoming parameter names and
		// invoke them appropriately.
		$methods = array_combine( array_map( 'strtolower', get_class_methods( $this ) ), get_class_methods( $this ) );
		foreach( $settings as $key => $value ){
			$setMethodKey = 'set'.strtolower( $key );
			if( array_key_exists( $setMethodKey, $methods ) ){
				call_user_func( array( $this, $methods{ $setMethodKey } ), $value );
			}
		}
		return( true );
	}

	// No support for generic bind variables using mysql interfaces; the mysqli
	// implementation for 5.3.0 does; use that if you can.
	public function Delete( Grid $grid ){
		$return = false;
		if( !$grid->canLoad() ){
			$this->throwException( 'Cannot delete '.$grid->_getObjName().' grid: no identifying criteria' );
		}
		$commands = array();
		if( $gridCommands = $grid->Torpor()->gridCommands( $grid, PersistenceCommand::TYPE_DELETE ) ){
			foreach( $gridCommands as $command ){
				$this->checkCommandType( $command );
				$commands[] = $command;
			}
		}
		$grid->OnBeforeDelete();
		if( count( $commands ) > 0 ){
			foreach( $commands as $command ){
				$result = $this->query( $this->parseCommand( $command, $grid ) );
				if( $result === true || is_resource( $result ) ){
					if( ( $affected = ( is_resource( $result ) ? $this->affected_rows( $result ) : 1 ) ) != 1 ){
						trigger_error( 'Successful delete command execution affected '.$affected.' rows, expected 1', E_USER_WARNING );
						if( $affected >= 1 ){
							$grid->UnLoad( false );
							$return = true;
						}
					}
				} else if( $result === false ){
					$this->throwException( $grid->_getObjName().' delete command failed: '.$this->error() );
				} else {
					$this->throwException( 'Boolean return expected from '.$grid->_getObjName().' delete command, got '.gettype( $result ) );
				}
			}
		} else {
			if( !( $result = $this->query( $this->generateDeleteSQL( $grid ) ) ) ){
				$this->throwException( $grid->_getObjName().' delete failed: '.$this->error() );
			} else {
				if( $this->affected_rows( $result ) != 1 ){
					trigger_error( 'No '.$grid->_getObjName().' records deleted', E_USER_WARNING );
				} else {
					if( $this->getTorpor()->isCacheEnabled() ){
						$this->getTorpor()->Cache()->purgeGrid( $grid );
					}
					$grid->UnLoad( false );
					$return = true;
				}
			}
		}
		if( $return ){
			$grid->OnDelete();
		}
		return( $return );
	}

	public function Execute( PersistenceCommand $command, $returnAs = null ){
		$this->checkCommandType( $command );
		$grids = array_slice( func_get_args(), 2 );
		$command = clone( $command );
		for( $i = 0; $i < count( $grids ); $i++ ){
			$final = ( $i == ( count( $grids ) - 1 ) ? true : false );
			if( !( $grids[$i] instanceof Grid ) ){
				$this->throwException( 'Non-grid argument passed to Execute' );
			}
			$command->setCommand( $this->parseCommand( $command, $grids[$i], $final ) );
		}
		$return = false;
		$result = $this->query( $command->getCommand() );
		if( is_resource( $result ) ){
			if( $returnAs instanceof Grid ){
				$gridLoad = $this->fetch_assoc( $result );
				if( $gridLoad === false ){
					trigger_error( 'No rows returned from executed command', E_USER_WARNING );
				} else {
					$return = $this->LoadGridArray( $returnAs, $gridLoad );
					if( $this->fetch_assoc( $result ) !== false ){
						trigger_error( 'Multiple rows returned, only using the first to populate '.$grid->_getObjName().' grid', E_USER_WARNING );
					}
				}
			} elseif( $returnAs instanceof GridSet ){
				$return = 0;
				$newCommand = Torpor::OPERATION_NEW.$returnAs->gridType();
				while( $dataRow = $this->fetch_assoc( $result ) ){
					$return++;
					$grid = $returnAs->Torpor()->$newCommand();
					$this->LoadGridArray( $grid, $dataRow );
					$returnAs->addGrid( $grid );
				}
				if( $return == 0 ){
					trigger_error( 'No rows returned from executed command', E_USER_WARNING );
				}
			} else {
				$this->throwException(
					'Unknown return type requested (expected one of Grid or GridSet, got '.
					( is_object( $returnAs ) ? get_class( $returnAs ) : gettype( $returnAs ) ).')'
				);
			}
		} else if( $result === true ){
			if( is_object( $returnAs ) ){
				trigger_error( 'Expected return type passed, but no rows returned from command', E_USER_WARNING );
			}
			$return = true;
		} else if( $result === false ){
			$this->throwException( 'Command exectution failed: '.$this->error() );
		}
		return( $return );
	}

	public function Load( Grid $grid, $refresh = false ){
		$return = false;
		if( !$grid->canLoad() ){
			if( $refresh ){
				$this->throwException( 'Cannot load '.$grid->_getObjName().' grid: no identifying criteria' );
			}
		} else if( !$grid->isLoaded() || $refresh ){
			if(
				!$refresh
				&& $this->getTorpor()->isCacheEnabled()
				&& $cachedGrid = $this->getTorpor()->Cache()->fetchGrid( $grid )
			){
				$this->LoadGridArray( $grid, $cachedGrid, false );
				return( $grid->isLoaded() );
			}
			$commands = $grid->Torpor()->gridCommands( $grid, PersistenceCommand::TYPE_LOAD );
			if( count( $commands ) > 0 ){
				if( count( $commands ) > 1 ){
					trigger_error( 'Multiple '.PersistenceCommand::TYPE_LOAD.' persistence commands defined, but only the first will be used', E_USER_WARNING );
				}
				// This is why we haven't attempted to retrieve the command array via reference;
				// that, and an array of object references really isn't that expensive.
				$command = array_shift( $commands );
				$this->checkCommand( $grid->Torpor(), $command );
				list( $result, $rowCount ) = $this->selectAndCount( $this->parseCommand( $command, $grid ), 1 );
				if( is_resource( $result ) ){
					if( $rowCount > 0 ){
						if( $rowCount > 1 ){
							trigger_error( 'Too many results returned from command; only using first row', E_USER_WARNING );
						}
						$this->LoadGridArray( $grid, $this->fetch_assoc( $result ) );
					}
				} else {
					if( $result === true ){
						$this->throwException( 'Load command for '.$grid->_getObjName().' grid executed successfully but did not return a result set (even an empty one)' );
					} else {
						$this->throwException( 'Load command for '.$grid->_getObjName().' grid failed: '.$this->error() );
					}
				}
			} else {
				$this->LoadGridFromQuery(
					$grid,
					$this->generateSelectSQL( $grid ),
					1
				);
			}
		}
		return( $grid->isLoaded() );
	}

	public function LoadFromCriteria( Grid $grid, CriteriaBase $criteria, $refresh = false ){
		if( !$grid->isLoaded() || $refresh ){
			$sql = $this->CriteriaToSQL( $grid, $criteria );
			$this->LoadGridFromQuery( $grid, $sql, 1 );
		}
		return( $grid->isLoaded() );
	}

	public function LoadSet( GridSet $gridSet, $refresh = false ){
		$return = false;
		if( !$gridSet->canLoad() ){
			return( $return );
		}
		if( $gridSet->isLoaded() && !$refresh ){
			return( $gridSet->isLoaded() );
		}
		$gridType = $gridSet->gridType();
		$finalCriteriaSet = $this->GridSetToCriteria( $gridSet );

		$sql = '';
		if( count( $finalCriteriaSet->getCriteria() ) >= 1 ){
			$sql = $this->CriteriaToSQL( $gridType, $finalCriteriaSet, $gridSet->getSortOrder() );
		} else {
			$sql = $this->generateSelectSQL( $gridSet->Torpor()->dataNameForGrid( $gridType ), $gridSet->getSortOrder() );
		}

		list( $result, $count ) = $this->selectAndCount(
			$sql,
			( $gridSet->getPageSize() >= 0 ? $gridSet->getPageSize() : null ),
			( $gridSet->getGridOffset() >= 0 ? $gridSet->getGridOffset() : null )
		);
		if( $result === false ){
			$this->throwException( 'Load of '.$gridType.' grid set failed: '.$this->error() );
		} else if( $result === true ){
			$this->throwException( 'Load command for '.$gridType.' grid set executed successfully but did not produce a result set (even a zero row result set)' );
		} else if( is_resource( $result ) ){
			if( $count > 0 ){
				$newCommand = Torpor::OPERATION_NEW.$gridType;
				while( $dataRow = $this->fetch_assoc( $result ) ){
					$loadedGrid = $this->getTorpor()->$newCommand();
					$this->LoadGridArray( $loadedGrid, $dataRow );
					$gridSet->addGrid( $loadedGrid, false );
				}
				$gridSet->setTotalGridCount( $count );
				$return = $gridSet->setLoaded();
			}
		} else {
			$this->throwException( 'Cannot handle query return type: '.gettype( $result ) );
		}
		return( $return );
	}

	public function Publish( Grid $grid, $force = false ){
		if( !$this->isWritable() ){
			$this->throwException( 'Read only data store, no publishing permitted' );
		}
		$published = false;
		if( !$grid->isDirty() && !$force ){
			// No reason to continue - bail early, it's cheaper.
			return( $published );
		}

		if( in_array( $grid, self::$_publishing, true ) ){
			$this->throwException( 'Recursion in dependent object publish or horribly improbable timing collision in duplicate publish attempts' );
		} else {
			array_push( self::$_publishing, $grid );
		}

		if( $grid->isReadOnly() ){
			$this->throwException( 'Cannot publish read only '.$grid->_getObjName().' grid' );
		}
		if( !$grid->canPublish() ){
			if( $grid->Torpor()->publishDependencies() ){
				$grid->publishDependencies( $force );
			}
			if( !$grid->canPublish() ){
				$this->throwException( 'Cannot publish '.$grid->_getObjName().' grid: required data members not set' );
			}
		}

		$new = ( $grid->isLoaded() ? false : true );
		$commands = array();
		if( $gridCommands = $grid->Torpor()->gridCommands( $grid, PersistenceCommand::TYPE_PUBLISH ) ){
			$context = ( $new ? PersistenceCommand::CONTEXT_NEW : PersistenceCommand::CONTEXT_EXISTING );
			foreach( $gridCommands as $command ){
				$this->checkCommandType( $command );
				if( $command->getContext() == $context || $command->getContext() == PersistenceCommand::CONTEXT_ALL ){
					$commands[] = $command;
				}
			}
		}
		$grid->OnBeforePublish();
		if( count( $commands ) > 0 ){
			foreach( $commands as $command ){
				list( $result, $count ) = $this->selectAndCount( $this->parseCommand( $command, $grid ) );
				if( is_resource( $result ) ){
					if( $count > 1 ){
						trigger_error( 'Too many results returned from command; only using first row', E_USER_WARNING );
					}
					$this->LoadGridArray( $grid, $this->fetch_assoc( $result ) );
				} else if( $result === true ){
					$published = true;
				} else if( $result === false ){
					$this->throwException( $grid->_getObjName().' publish command failed: '.$this->error() );
				}
			}
		} else {
			// TODO: Establish a list of possible $command->getCommandType() parameters
			// which may allow for both the execution of defined commands and a typical insert;
			// useful for function based GUID or sequence introspection for generatedOnPublish()
			// fields, but without the need to write insert/update statements as well.

			$declarations = array();

			if( $new ){
				$this->preInsert( $grid );
			}
			foreach( $grid->ColumnNames() as $columnName ){
				if(
					$force
					|| $grid->Column( $columnName )->isDirty()
					|| $grid->Column( $columnName )->isLinked()
					|| $grid->Torpor()->publishAllColumns()
				){
					$declarations{
						$this->escapeDataName( $grid->Column( $columnName )->getDataName() )
					} = $this->autoQuoteColumn( $grid->Column( $columnName ), $new );
				}
			}
			if( count( $declarations ) < 1 ){
				if( $force ){
					$this->throwException( 'Cannot force publish '.$grid->_getObjName().' grid: no data members found' );
				}
			} else {
				if(
					$result = $this->query(
						( $new
							? $this->generateInsertSQL( $grid, $declarations )
							: $this->generateUpdateSQL( $grid, $declarations )
						)
					)
				){
					if( $new ){
						if( $this->affected_rows( $result ) != 1 ){
							$this->throwException( $grid->_getObjName().' insert failed' );
						}
						$published = true;
					} else {
						// The affected rows might be 0 if we've published a record with zero changes.
						$published = true;
					}
				} else {
					$this->throwException( 'Publish failed: '.$this->error() );
				}
			}
		}
		if( $published ){
			$this->postInsert( $grid );
			if( !$grid->canLoad() ){
				// We've successfully published, but have no way of finding the record we inserted.
				$this->throwException( 'Successful publish but no identifying criteria returned; '.$grid->_getObjName().' grid cannot continue' );
			}
			$grid->onPublish();
			if( $grid->Torpor()->reloadAfterPublish() ){
				// TODO: Need to reset the grid so the only values it contains are the known
				// keys, so it invokes a just-in-time fetch (but only if it canLoad() after all
				// of the above).  This is more resource intensive than just setting it to a
				// loaded status and !dirty, but far more accurate.
				if( $this->getTorpor()->isCacheEnabled() ){
					$this->getTorpor()->Cache()->purgeGrid( $grid );
				}
				$grid->UnLoad();
			} else {
				// Causes all of the internal values to be reset to their current version,
				// as well as setting a new reset point.  Dump all fields, and do not attempt
				// to load.
				$this->LoadGridArray( $grid, $grid->dumpArray( true, false ), true, true, false );
			}
		}
		unset( self::$_publishing[ array_search( $grid, self::$_publishing, true ) ] );
		return( $published );
	}

	//******************************************************
	//*  Common setup routines and *DataStore abstraction  *
	//******************************************************
	protected function setTorpor( Torpor $torpor ){ return( $this->_torpor = $torpor ); }
	public function getTorpor(){ return( $this->_torpor ); }

	public function isWritable(){ return( $this->_writable ); }
	public function getParameter( $parameterName ){
		return( array_key_exists( $parameterName, $this->_parameters ) ? $this->_parameters{ $parameterName } : null );
	}
	public function setParameter( $parameterName, $value ){
		return( $this->_parameters{ $parameterName } = $value );
	}

	public function throwException( $msg ){
		if( !is_null( $torpor = $this->getTorpor() ) ){
			$torpor->throwException( $msg );
		} else {
			throw( new TorporException( $msg ) );
		}
	}

	abstract public function query( $query, array $bindVariables = null );
	abstract public function error( $resource = null );
	abstract public function affected_rows( $resource = null );
	abstract public function fetch_row( $resource );
	abstract public function fetch_array( $resource );
	abstract public function fetch_assoc( $resource );

	//*********************************************
	//*  SQL generation & management abstraction  *
	//*********************************************
	protected function preInsert( Grid $grid ){}
	protected function postInsert( Grid $grid ){}
	public function generateInsertSQL( Grid $grid, array $pairs = null ){
		if( !is_array( $pairs ) ){
			$pairs = array();
			// Note: loss of information about 'FORCE'
			foreach( $grid->ColumnNames() as $columnName ){
				if(
					$grid->Column( $columnName )->isDirty()
					|| $grid->Column( $columnName )->isLinked()
					|| $grid->Torpor()->publishAllColumns()
				){
					$pairs{
						$this->escapeDataName( $grid->Column( $columnName )->getDataName() )
					} = $this->autoQuoteColumn( $grid->Column( $columnName ) );
				}
			}
		}
		return(
			'INSERT INTO '.$this->gridTableName( $grid ).' ( '
			.implode( ', ', array_keys( $pairs ) ).' ) VALUES ( '
			.implode( ', ', array_values( $pairs ) ).' )'
		);
	}
	public function generateUpdateSQL( Grid $grid, array $pairs = null ){
		if( !is_array( $pairs ) ){
			$pairs = array();
			// Note: loss of information about 'FORCE'
			foreach( $grid->ColumnNames() as $columnName ){
				if(
					$grid->Column( $columnName )->isDirty()
					|| $grid->Column( $columnName )->isLinked()
					|| $grid->Torpor()->publishAllColumns()
				){
					$pairs{
						$this->escapeDataName( $grid->Column( $columnName )->getDataName() )
					} = $this->autoQuoteColumn( $grid->Column( $columnName ) );
				}
			}
		}
		$sql = 'UPDATE '.$this->gridTableName( $grid ).' SET ';
		$setClauses = array();
		foreach( $pairs as $columnName => $value ){
			$setClauses[] = $columnName.' = '.$value;
		}
		$sql.= implode( ', ', $setClauses );

		$clauses = $this->makeClauses( $grid );
		if( count( $clauses ) < 1 ){
			$this->throwException( 'Cannot publish '.$grid->_getObjName().' grid: no identifying criteria' );
		}
		$sql.= ' WHERE '.implode( ' AND ', $clauses );
		return( $sql );
	}
	public function generateDeleteSQL( Grid $grid ){
		return(
			'DELETE FROM '.$this->gridTableName( $grid )
			.' WHERE '.implode( ' AND ', $this->makeClauses( $grid ) )
		);
	}
	public function generateSelectSQL( $grid, array $orderBy = null ){ // Single grid only; criteria are used for larger sets.
		$clauses = array();
		$declarations = array();
		$from = null;
		if( $grid instanceof Grid ){
			$from = $this->gridTableName( $grid );
			foreach( $grid->ColumnNames() as $columnName ){
				$declarations[] = $this->escapeDataName( $grid->Column( $columnName )->getDataName() )
				.' '.$this->asColumnOperator.' '
				.$this->escapeDataNameAlias( $grid->Column( $columnName )->_getObjName() );
			}
			$clauses = $this->makeClauses( $grid );
		} else {
			$from = $this->escapeDataName( $grid );
			foreach( $this->getTorpor()->$grid() as $columnName ){
				$declarations[] = $from.'.'
					.$this->escapeDataName( $this->getTorpor()->dataNameForColumn( $grid, $columnName ) )
					.' '.$this->asColumnOperator.' '
					.$this->escapeDataNameAlias( $columnName );
			}
		}
		$fake_criteria = new CriteriaSet();
		$order_by = '';
		if( is_array( $orderBy ) && count( $orderBy ) > 0 ){
			$orderClauses = array();
			foreach( $orderBy as $sortSpec ){
				list( $gridName, $columnName, $order ) = $sortSpec;
				$fake_criteria->addCriteria(
					new CriteriaNotEquals(
						$gridName,
						$columnName,
						null
					)
				);

				if( $order == GridSet::ORDER_RANDOM ){
					$orderClauses[] = 'RAND()';
				} else {
					$orderClauses[] = $this->escapeDataName( $gridName )
						.'.'.$this->escapeDataName( $this->getTorpor()->dataNameForColumn( $gridName, $columnName ) )
						.' '.( $order == GridSet::ORDER_DESCENDING ? 'DESC' : 'ASC' );
				}
			}
			$order_by.= ' ORDER BY '.implode( ', ', $orderClauses );
		}
		$sql = 'SELECT DISTINCT '.implode( ', ', $declarations ).' FROM '.$this->CriteriaToJoinClause( $this->getTorpor()->containerKeyName( $grid ), $fake_criteria );
		if( count( $clauses ) > 0 ){ $sql.= ' WHERE '.implode( ' AND ', $clauses ); }
		return( $sql.$order_by );
	}

	protected function selectAndCount( $selectStatement, $limit = null, $offset = null ){
		// This method is expected to retrieve a count of available records in a way most efficient to
		// each implementing DataStore implemmentation, and to return the results of that executions as
		// well as the returned count.
		// This is an inefficient but ANSI compliant means of performing the function in 2 passes.  The
		// regex is non greedy, assuming that and sub-selects (other occurrances of "FROM") will appear only
		// in the restrictive clauses, as is the torpor fashion; this may break on custom queries, but those
		// are also less likely to be traveling this path.  You have been warned.
		// Also note that there is no ANSI compliant way or performing the limit and offset - those will be
		// left to the implementing classes.  So why not make this an abstract class, instead of providing
		// only half the functionality?  Because it makes sense to have a template, and to be able to
		// explain the caveats and limitations to the implementing developer using a real "living" example.
		$count = null;
		if( strpos( $selectStatement, 'SELECT' ) === 0 ){
			$countStatement = preg_replace(
				'/^SELECT.*?FROM/',
				'SELECT COUNT( 1 ) '.$this->asColumnOperator.' '.$this->escapeDataName( 'THECOUNT' ).' FROM',
				$selectStatement,
				1
			);
			$countStatement = preg_replace( '/ ORDER BY .*$/', '', $countStatement );
			$countResult = $this->query( $countStatement );
			if( is_resource( $countResult ) ){
				$countRow = $this->fetch_row( $countResult );
				$count = (int)array_shift( $countRow );
			}
		}
		return( array( $this->query( $selectStatement ), $count ) );
	}

	//************************************
	//*  Utility and supporting methods  *
	//************************************
	public function cacheGrid( Grid $grid ){
		if( $this->getTorpor()->isCacheEnabled() ){
			$this->getTorpor()->Cache()->writeGrid( $grid );
		}
	}

	public function LoadGridFromQuery( Grid $grid, $sql, $expectedRows = -1 ){
		list( $result, $totalCount ) = $this->selectAndCount( $sql, 1 );
		if( $result === false ){
			$this->throwException( 'Load of '.$grid->_getObjName().' grid failed: '.$this->error() );
		} else if( $result === true ){
			$this->throwException( 'Load of '.$grid->_getObjName().' grid executed successfully but did not produce a result set (even a zero row result set)' );
		} else if( is_resource( $result ) ){
			if( $totalCount > 0 ){
				if( $expectedRows > 0 && $totalCount != $expectedRows ){
					trigger_error(
						'Wrong number of results returned in '.$grid->_getObjName()
						.' load (expected '.$expectedRows.', got '.$totalCount
						.'; only '.min( $expectedRows, $totalCount ).' will be used)', E_USER_WARNING
					);
				}
				$this->LoadGridArray( $grid, $this->fetch_assoc( $result ) );
			}
		} else {
			$this->throwException( 'Cannot handle query return type: '.gettype( $result ) );
		}
	}

	public function LoadGridArray( $grid, $array, $doCache = true, $setLoaded = true, $fromDataStore = true ){
		$return = $grid->LoadFromArray( $array, $setLoaded, $fromDataStore );
		if( $doCache ){ $this->cacheGrid( $grid ); }
		$grid->OnLoad();
		return( $return );
	}

	abstract public function escape( $arg, $quote = false, $quoteChar = '\'' );
	abstract public function escapeDataName( $dataName );
	abstract public function escapeDataNameAlias( $dataNameAlias );

	// TODO: Need to abstract the quoting routines and/or characters so DB specific implementations can
	// handle them correctly.
	public function ColumnNameToSQL( $sourceGridName, $gridName, $columnName, $validateReference = true ){
		$gridName = $this->getTorpor()->containerKeyName( $gridName );
		$gridAlias = $gridName;
		// TODO: Will likely need to abstract these routines, for use in assembling
		// join criteria if not finding a navigable path between 2 grids in any
		// general context.  We have one advantage here that sourceGridName, in the
		// use of MySQLDataStore, should not ever be an alias (but this is something
		// which must be considered in a more generic implementation).
		if( $validateReference ){
			if( !is_null( $referenceGridName = $this->getTorpor()->referenceAliasGrid( $sourceGridName, $gridName ) ) ){
				$gridName = $referenceGridName;
			}
			if(
				!$this->getTorpor()->canReference( $sourceGridName, $gridName, ( $gridName == $gridAlias ? false : $gridAlias ) )
				&& !$this->getTorpor()->canBeReferencedBy( $sourceGridName, $gridName )
			){
				$this->throwException( 'No reference path between '.$this->getTorpor()->containerKeyName( $sourceGridName ).' and '.$gridAlias );
			}
		}
		if( !$this->getTorpor()->$gridName()->hasColumn( $columnName ) ){
			$this->throwException( 'Unsupported column in Criteria: '.$columnName );
		}
		return(
			$this->escapeDataName( $gridAlias ).'.'
			.$this->escapeDataName( $this->getTorpor()->dataNameForColumn( $gridName, $columnName ) )
		);
	}

	public function GridSetToCriteria( GridSet $gridSet ){
		$gridType = $gridSet->gridType();
		$finalCriteriaSet = new CriteriaAndSet();
		foreach( $gridSet->getSourceGrids() as $alias => $targetGrid ){
			$targetGridName = $this->getTorpor()->containerKeyName( $targetGrid );
			if(
				!$this->getTorpor()->canReference(
					$gridType,
					$targetGridName,
					(
						$alias == $targetGridName
						? false
						: $alias
					)
				)
			){
				$this->throwException( 'No reference path between '.$gridType.' and '.$targetGridName.' (as '.$alias.')' );
			}
			$keys = array();
			if( $alias != $targetGridName ){
				$keys = $this->getTorpor()->aliasReferenceKeysBetween( $gridType, $targetGridName, $alias );
			} else {
				$keys = $this->getTorpor()->referenceKeysBetween( $gridType, $targetGridName, Torpor::NON_ALIAS_KEYS );
			}
			if( !is_array( $keys ) || count( $keys ) < 1 ){
				$this->throwException( 'Insufficient keys found between '.$gridType.' and '.$targetGridName.' (as '.$alias.')' );
			}
			foreach( $keys as $sourceKeyName => $referenceKeyName ){
				if( !$targetGrid->Column( $referenceKeyName )->hasData() ){
					if( !$targetGrid->isLoaded() && $targetGrid->canLoad() ){ $targetGrid->Load(); }
					if( !$targetGrid->Column( $referenceKeyName )->hasData() ){
						$this->throwException( 'Cannot establish mapping between '.$gridType.' and '.$targetGridName.'.'.$referenceKeyName.' (no data)' );
					}
				}
				$finalCriteriaSet->addCriteria(
					new CriteriaEquals(
						$alias,
						$referenceKeyName,
						$targetGrid->Column( $referenceKeyName )->getPersistData()
					)
				);
			}
		}

		if( ( $criteria = $gridSet->getSourceCriteria() ) instanceof CriteriaBase ){
			$finalCriteriaSet->addCriteria( $criteria );
		}
		return( $finalCriteriaSet );
	}

	public function CriteriaToSQL( $sourceGridName, CriteriaBase $criteria, array $orderBy = null ){
		$sourceGridName = $this->getTorpor()->containerKeyName( $sourceGridName );
		$escapedSourceGridName = $this->escapeDataName( $sourceGridName );
		$declarations = array();
		foreach( $this->getTorpor()->$sourceGridName() as $columnName ){
			$declarations[] = $escapedSourceGridName.'.'
				.$this->escapeDataName( $this->getTorpor()->dataNameForColumn( $sourceGridName, $columnName ) )
				.' '.$this->asColumnOperator.' '
				.$this->escapeDataNameAlias( $columnName );
		}
		$fake_criteria = clone $criteria;
		// In order to ensure any remote (joined) table sort conditions are valid, they need to be part of the
		// join clause.  Easiest way to do that is to make sure they show up in the criteria collection, even
		// if artificially.
		$order_by = '';
		if( is_array( $orderBy ) && count( $orderBy ) > 0 ){
			$orderClauses = array();
			foreach( $orderBy as $sortSpec ){
				list( $gridName, $columnName, $order ) = $sortSpec;
				$fake_criteria->addCriteria(
					new CriteriaNotEquals(
						$gridName,
						$columnName,
						null
					)
				);
					
				if( $order == GridSet::ORDER_RANDOM ){
					$orderClauses[] = 'RAND()';
				} else {
					$orderClauses[] = $this->escapeDataName( $gridName )
						.'.'.$this->escapeDataName( $this->getTorpor()->dataNameForColumn( $gridName, $columnName ) )
						.' '.( $order == GridSet::ORDER_DESCENDING ? 'DESC' : 'ASC' );
				}
			}
			$order_by.= ' ORDER BY '.implode( ', ', $orderClauses );
		}
		$sql = 'SELECT DISTINCT '.implode( ', ', $declarations )
			.' FROM '.$this->CriteriaToJoinClause( $sourceGridName, $fake_criteria ).' '
			.$this->CriteriaToConditions( $sourceGridName, $criteria );
		return( $sql.$order_by );
	}

	public function CriteriaHandlerTYPE( $sourceGridName, Criteria $criteria, $column ){ /* return( $finishedSQLexpression ); */ }
	public function CriteriaToConditions( $sourceGridName, CriteriaBase $criteria, $clause = 'WHERE' ){
		// TODO: Check for recursion
		$sourceGridName = $this->getTorpor()->containerKeyName( $sourceGridName );
		if( !$this->getTorpor()->supportedGrid( $sourceGridName ) ){
			$this->throwException( 'Unrecognized grid "'.$sourceGridName.'" requested' );
		}
		$sql = '';
		if( $criteria instanceof Criteria ){
			if( !$criteria->validate() ){
				$this->throwException( 'Criteria not valid, cannot continue' );
			}

			$sql.= $this->ColumnNameToSQL(
				$sourceGridName,
				$criteria->getGridName(),
				$criteria->getColumnName(),
				(
					$criteria->getGridName() == $this->getTorpor()->makeKeyName( $sourceGridName )
					? false
					: true
				)
			);
			
			if( array_key_exists( $criteria->getBaseType(), $this->_criteriaHandlerMap ) ){
				$handler = $this->_criteriaHandlerMap{ $criteria->getBaseType() };
				$sql = $this->$handler( $sourceGridName, $criteria, $sql );
			} else {
				switch( $criteria->getBaseType() ){
					case Criteria::TYPE_BETWEEN:
						list( $lowRange, $highRange ) = $criteria->getArguments();
						if( $criteria->isColumnTarget() ){
							$lowRange = $this->ColumnNameToSQL( $sourceGridName, array_shift( $lowRange ), array_shift( $lowRange ) );
							$highRange = $this->ColumnNameToSQL( $sourceGridName, array_shift( $highRange ), array_shift( $highRange ) );
						} else {
							$lowRange = $this->escape( $lowRange, true );
							$highRange = $this->escape( $highRange, true );
						}
						if( $criteria->isInclusive() ){
							$sql.= ( $criteria->isNegated() ? ' NOT' : '' ).' BETWEEN '.$lowRange.' AND '.$highRange;
						} else {
							$sql = ( $criteria->isNegated() ? 'NOT ' : '' ).'( '.$sql.' > '.$lowRange.' AND '.$sql.' < '.$highRange.' )';
						}
						break;
					case Criteria::TYPE_BITAND:
					case Criteria::TYPE_BITOR:
					case Criteria::TYPE_BITXOR:
						break;
					case Criteria::TYPE_CONTAINS:
						list( $target ) = $criteria->getArguments();
						if( $criteria->isColumnTarget() ){
							$target = '\'%\' '.$this->_concatOperator.' '.$this->ColumnNameToSQL( $sourceGridName, array_shift( $target ), array_shift( $target ) ).' '.$this->_concatOperator.' \'%\'';
						} else {
							$target = '\'%'.$this->escape( $target ).'%\'';
						}
						if( $criteria->isCaseInSensitive() ){
							$sql = 'UPPER( '.$sql.' )';
							$target = 'UPPER( '.$target.' )';
						}
						$sql.= ( $criteria->isNegated() ? ' NOT' : '' ).' LIKE '.$target;
						break;
					case Criteria::TYPE_ENDSWITH:
						list( $target ) = $criteria->getArguments();
						if( $criteria->isColumnTarget() ){
							$target = 'CONCAT( \'%\', '.$this->ColumnNameToSQL( $sourceGridName, array_shift( $target ), array_shift( $target ) ).' )';
						} else {
							$target = '\'%'.$this->escape( $target ).'\'';
						}
						if( $criteria->isCaseInSensitive() ){
							$sql = 'UPPER( '.$sql.' )';
							$target = 'UPPER( '.$target.' )';
						}
						$sql.= ' '.( $criteria->isNegated() ? 'NOT ' : '' ).'LIKE '.$target;
						break;
					case Criteria::TYPE_EQUALS:
						list( $target ) = $criteria->getArguments();
						if( $criteria->isColumnTarget() ){
							$target = $this->ColumnNameToSQL( $sourceGridName, array_shift( $target ), array_shift( $target ) );
						} else if( !is_null( $target ) ){
							$target = $this->escape( $target, true );
						}
						if( is_null( $target ) ){
							$sql.= ' IS'.( $criteria->isNegated() ? ' NOT' : '' ).' NULL';
						} else {
							if( $criteria->isCaseInSensitive() ){
								$sql = 'UPPER( '.$sql.' )';
								$target = 'UPPER( '.$target.' )';
							}
							$sql.= ' '.( $criteria->isNegated() ? '!' : '' ).'= '.$target;
						}
						break;
					case Criteria::TYPE_GREATERTHAN:
						list( $target ) = $criteria->getArguments();
						if( $criteria->isColumnTarget() ){
							$target = $this->ColumnNameToSQL( $sourceGridName, array_shift( $target ), array_shift( $target ) );
						} else {
							$target = $this->escape( $target, true );
						}
						$sql = ( $criteria->isNegated() ? ' NOT ' : '' ).$sql.' >'.( $criteria->isInclusive() ? '=' : '' ).' '.$target;
						break;
					case Criteria::TYPE_IN:
						$args = $criteria->getArguments();
						$sqlArgs = array();
						if( $criteria->isColumnTarget() ){
							foreach( $args as $arg ){
								$sqlArgs[] = $this->ColumnNameToSQL( $sourceGridName, array_shift( $arg ), array_shift( $arg ) );
							}
						} else {
							foreach( $args as $arg ){
								$sqlArgs[] = $this->escape( $arg, true );
							}
						}
						if( $criteria->isCaseInsensitive() ){
							foreach( $sqlArgs as $key => $arg ){
								$sqlArgs[ $key ] = 'UPPER( '.$arg.' )';
							}
						}
						if( !count( $sqlArgs ) ){
							$this->throwException( 'Nothing in IN criteria, cannot continue' );
						}
						$sql.= ( $criteria->isNegated() ? ' NOT' : '' ).' IN ( '.( implode( ', ', $sqlArgs ) ).' )';
						break;
					case Criteria::TYPE_LESSTHAN:
						list( $target ) = $criteria->getArguments();
						if( $criteria->isColumnTarget() ){
							$target = $this->ColumnNameToSQL( $sourceGridName, array_shift( $target ), array_shift( $target ) );
						} else {
							$target = $this->escape( $target, true );
						}
						$sql = ( $criteria->isNegateD() ? ' NOT ' : '' ).$sql.' <'.( $criteria->isInclusive() ? '=' : '' ).' '.$target;
						break;
					case Criteria::TYPE_PATTERN:
						list( $regex ) = $criteria->getArguments();
						$sql.= ' '.( $criteria->isNegated() ? 'NOT ' : '' ).'REGEXP '.$this->escape( $regex, true );
						break;
					case Criteria::TYPE_STARTSWITH:
						list( $target ) = $criteria->getArguments();
						if( $criteria->isColumnTarget() ){
							$target = $this->ColumnNameToSQL( $sourceGridName, array_shift( $target ), array_shift( $target ) ).' '.$this->_concatOperator.' \'%\'';
						} else {
							$target = '\''.$this->escape( $target ).'%\'';
						}
						if( $criteria->isCaseInsensitive() ){
							$sql = 'UPPER( '.$sql.' )';
							$target = 'UPPER( '.$target.' )';
						}
						$sql.= ' '.( $criteria->isNegated() ? 'NOT ' : '' ).'LIKE '.$target;
						break;
					case Criteria::TYPE_CUSTOM:
						trigger_error( 'Custom un-parsed SQL passed to database', E_USER_NOTICE );
						if( $criteria->isNegated() ){
							trigger_error( 'Ignoring criteria negation for '.$criteria->getType().' criteria', E_USER_WARNING );
						}
						if( $criteria->isColumnTarget() ){
							trigger_error( 'Ignoring column target setting for '.$criteria->getType().' criteria', E_USER_WARNING );
						}
						if( count( $criteria->getArguments() ) > 0 ){
							trigger_error( 'Ignoring arguments for '.$criteria->getType().' criteria', E_USER_WARNING );
						}
						$sql = $criteria->getCustom();
						break;
					case Criteria::TYPE_IN_SET:
						// TODO: Extract/Create the SQL used to populate a GridSet,
						// and use that as a sub-select inclusion/exclusion, eg. $grid.$column [NOT] IN ( SELECT ... )
						// Need to specify which column of the SET pertains to this column...
						list( $gridSet ) = $criteria->getArguments();
						if( !$gridSet->canLoad() && $gridSet->isEmpty() ){
							$this->throwException( 'GridSet lacks valid content or criteria, cannot continue' );
						}

						$criteriaGridType = $criteria->getGridName();
						if( !$this->getTorpor()->supportedGrid( $criteriaGridType )){
							$criteriaGridType = $this->getTorpor()->referenceAliasGrid( $sourceGridName, $criteriaGridType );
							if( is_null( $criteriaGridType ) ){
								$this->throwException( 'No reference path between '.$sourceGridName.' and '.$criteria->getGridName().' in '.$criteria->getType().' criteria' );
							}
						}

						// The targetColumnName needs to correspond to the relationship from the
						// criteria column to the gridSet.  We know the originating column, so we
						// only need to find the key relationship to which that belongs.
						// In the event that the key relationship is not immediately apparent:
						// 1. Check to see if grid references criteria
						// 2. Check to see if grid is the same as sourceGridName 
						$targetColumName = null;
						$referenceKeys = array();
						if( $this->getTorpor()->canReference( $criteriaGridType, $gridSet->gridType() ) ){
							$referenceKeys = $this->getTorpor()->referenceKeysBetween( $criteriaGridType, $gridSet->gridType() );
						} else if( $this->getTorpor()->canBeReferencedBy( $criteriaGridType, $gridSet->gridType() ) ){
							$inverseReferenceKeys = $this->getTorpor()->referenceKeysBetween( $gridSet->gridType(), $criteriaGridType );
							foreach( $inverseReferenceKeys as $context => $keys ){
								$referenceKeys{ $context } = array();
								foreach( $keys as $thatColumn => $thisColumn ){
									$referenceKeys{ $context }{ $thisColumn } = $thatColumn;
								}
							}
						} else if( $criteriaGridType == $gridSet->gridType() ){
							$referenceKeys = array( 'AUTOLOGICAL' => array( $criteria->getColumnName() => $criteria->getColumnName() ) );
						} else {
							$this->throwException( 'No reference path between '.$sourceGridName.' and '.$criteria->getGridName().' in '.$criteria->getType().' criteria' );
						}

						foreach( $referenceKeys as $context => $keys ){
							if( count( $keys ) != 1 ){ continue; }
							if( isset( $keys{ $criteria->getColumnName() } ) ){
								$targetColumnName = $keys{ $criteria->getColumnName() };
								break;
							}
						}
						if( is_null( $targetColumnName ) ){
							$this->throwException( 'Could not determine ideal column to column map between '.$criteriaGridType.' and '.$gridSet->gridType().' in '.$criteria->getType().' criteria' );
						}

						if( $criteria->isExclusive() && $gridSet->isEmpty() ){
							$gridSet->Load();
							if( $gridSet->isEmpty() ){
								// Exclusive criteria failed to yield any results; using it as a subselect
								// would also yield zero results; therefor we need to return either a boolean
								// TRUE or FALSE condition based on whether or not we have been negated.
								// If we must be IN an Empty collection, return false.
								// If we must NOT be in an Empty collection return true.
								// This is the most performant way of doing this, even if it is a little obscure.
								$sql = ( $criteria->isNegated() ? '1 = 1' : '1 != 1' );
								break;
							}
						}

						$inclusiveSQL = null;
						$loadedSQL = null;
						if( $criteria->isInclusive() ){
							// TODO: Special handling for an empty criteria gridSet w/o pagination:
							// Implicitly convert to a left outer join and use (NOT) IS NULL here?
							$gridSetCriteria = $this->GridSetToCriteria( $gridSet );
							$inclusiveSQL = 'SELECT DISTINCT '.$this->escapeDataName( $this->getTorpor()->dataNameForGrid( $gridSet->gridType() ) )
								.'.'.$this->escapeDataName( $this->getTorpor()->dataNameForColumn( $gridSet->gridType(), $targetColumnName ) )
								.' FROM '.$this->CriteriaToJoinClause( $gridSet->gridType(), $gridSetCriteria )
								.( count( $gridSetCriteria->getCriteria() ) > 0 ? ' '.$this->CriteriaToConditions( $gridSet->gridType(), $gridSetCriteria ) : '' );
						} else {
							if( !$gridSet->isLoaded() && $gridSet->isEmpty() ){
								$gridSet->Load();
							}
						}
						if( !$gridSet->isEmpty() ){
							$columnValues = array();
							for( $i = 0; $i < $gridSet->getGridCount( false, false ); $i++ ){
								$columnValues[] = $this->escape( $gridSet->getGrid( $i )->Column( $criteria->getColumnName() )->getPersistData(), true );
							}
							$loadedSQL = implode( ', ', $columnValues );
						}

						if( is_null( $inclusiveSQL ) && is_null( $loadedSQL ) ){
							$this->throwException( 'Could not construct valid clauses for '.$criteria->getType().' criteria using provided '.$gridSet->gridType().' grid set' );
						} else if( !is_null( $inclusiveSQL ) && !is_null( $loadedSQL ) ){
							$sql = '( '
								.'( '
									.$sql.( $criteria->isNegated() ? ' NOT' : '' ).' ( '.$inclusiveSQL.' )'
									.' ) AND ( '
									.$sql.( $criteria->isNegated() ? ' NOT' : '' ).' ( '.$loadedSQL.' )'
								.' )'
							.' )';
						} else {
							$sql.= ( $criteria->isNegated() ? ' NOT' : '' ).' IN ( '
								.( !is_null( $inclusiveSQL ) ? $inclusiveSQL : $loadedSQL ).' )';
						}
						break;
					default:
						$this->throwException( 'Unsupported criteria requested: '.$criteria->getType() );
						break;
				}
			}
		} else if( $criteria instanceof CriteriaSet ){
			$clauses = array();
			foreach( $criteria as $criterion ){
				$clauses[] = $this->CriteriaToConditions( $sourceGridName, $criterion, '' );
			}
			if( count( $clauses ) > 1 ){
				$sql.= '( '.implode( ' '.$criteria->getType().' ', $clauses ).' )';
			} else {
				$sql.= array_shift( $clauses );
			}
		}
		return( ( !empty( $clause ) ? $clause.' ' : '' ).$sql );
	}

	public function CriteriaToJoinClause( $sourceGridName, CriteriaBase $criteria ){
		$sourceGridName = $this->getTorpor()->containerKeyName( $sourceGridName );
		// 1. Get a complete list of all table/column combinations by all criteria
		//    inside of CriteriaBase, by flattening any nesting.
		// 2. Validate references between here and there
		// 3. Find Key relationships.
		//    3.1 For this references that, left join
		//    3.2 For that references this, left OUTER join

		$sql = $this->escapeDataName( $this->getTorpor()->dataNameForGrid( $sourceGridName ) )
			.' '.$this->asTableOperator.' '
			.$this->escapeDataNameAlias( $sourceGridName );

		// Flatten all criteria
		$allCriteria = array();
		if( $criteria instanceof CriteriaSet ){
			$incomingCriteria = $criteria->getCriteria();
			while( $criterion = array_shift( $incomingCriteria ) ){
				if( $criterion instanceof CriteriaSet ){
					$incomingCriteria = array_merge( $criterion->getCriteria(), $incomingCriteria );
					continue;
				} else if( $criterion instanceof Criteria ){
					$allCriteria[] = $criterion;
				}
			}
		} else {
			$allCriteria[] = $criteria;
		}

		$allReferencedGrids = array();
		foreach( $allCriteria as $criterion ){
			$allReferencedGrids[] = $criterion->getGridName();
			if( $criterion->isColumnTarget() ){
				foreach( $criterion->getArguments() as $gridColumnDefinition ){
					$allReferenceGrids[] = array_shift( $gridColumnDefinition );
				}
			}
		}

		$allReferencedGrids = array_unique( $allReferencedGrids );
		foreach( $allReferencedGrids as $referencedGrid ){
			if( $referencedGrid == $sourceGridName ){ continue; }
			$referenceKeys = array();
			$referenceAlias = $referencedGrid;
			$outerJoin = false;
			if( $this->getTorpor()->referenceAliasGrid( $sourceGridName, $referencedGrid ) ){
				$referencedGrid = $this->getTorpor()->referenceAliasGrid( $sourceGridName, $referencedGrid );
			}
			if(
				$this->getTorpor()->canReference(
					$sourceGridName,
					$referencedGrid,
					( $referenceAlias == $referencedGrid ? false : $referenceAlias )
				)
			){
				$referenceKeys = (
					$referenceAlias == $referencedGrid
					? $this->getTorpor()->referenceKeysBetween( $sourceGridName, $referencedGrid, Torpor::NON_ALIAS_KEYS )
					: $this->getTorpor()->aliasReferenceKeysBetween( $sourceGridName, $referencedGrid, $referenceAlias )
				);
			} else if( $this->getTorpor()->canBeReferencedBy( $sourceGridName, $referencedGrid ) ){
				$outerJoin = true;
				$tempReferenceKeys = $this->getTorpor()->referenceKeysBetween( $referencedGrid, $sourceGridName, Torpor::NON_ALIAS_KEYS );
				if( is_array( $tempReferenceKeys ) && count( $tempReferenceKeys ) >= 1 ){
					$referenceKeys = array_flip( $tempReferenceKeys );
				} else {
					trigger_error( 'No direct keys found, falling back to alias keys from '.$referencedGrid.' to '.$sourceGridName, E_USER_WARNING );
					$tempReferenceKeys = $this->getTorpor()->referenceKeysBetween( $referencedGrid, $sourceGridName );
					if( count( $tempReferenceKeys ) > 1 ){
						$this->throwException( 'Multiple alias relationships found from '.$referencedGrid.' to '.$sourceGridName.', cannot continue under ambiguous criteria' );
					}
					foreach( $tempReferenceKeys as $alias => $keys ){
						$referenceKeys = array_flip( $keys );
						if( count( $referenceKeys ) != count( $keys ) ){
							$this->throwException( 'Duplicate referenceColumn in alias keys' );
						}
						break;
					}
				}
			} else {
				$this->throwException( 'No reference path between '.$sourceGridName.' and '.$referenceAlias );
			}
			if( !is_array( $referenceKeys ) || count( $referenceKeys ) < 1 ){
				$this->throwException( 'No reference keys found between '.$sourceGridName.' and '.$referenceAlias );
			}
			$onConditions = array();
			foreach( $referenceKeys as $columnName => $referencedColumnName ){
				$onConditions[] = $this->escapeDataName( $referenceAlias ).'.'
					.$this->escapeDataName( $this->getTorpor()->dataNameForColumn( $referencedGrid, $referencedColumnName ) ).' = '
					.$this->escapeDataName( $sourceGridName ).'.'
					.$this->escapeDataName( $this->getTorpor()->dataNameForColumn( $sourceGridName, $columnName ) );
			}
			
			$sql.= ' LEFT '.( $outerJoin ? 'OUTER ' : '' ).'JOIN '
				.$this->escapeDataName( $this->getTorpor()->dataNameForGrid( $referencedGrid ) )
				.' '.$this->asTableOperator.' '
				.$this->escapeDataNameAlias( $referenceAlias )
				.' ON '.implode( ' AND ', $onConditions );
		}

		return( $sql );
	}

	public function columnEqualsData( Column $column, $compare = false ){
		return(
			$this->escapeDataName( $column->getDataName() )
			.' '.(
				$compare
				&& (
					!$column->hasData()
					|| is_null( $column->getPersistData() )
				) ? 'IS'
				: '='
			).' '
			.(
				$column->hasData()
				? $this->autoQuoteColumn( $column )
				: 'NULL'
			)
		);
	}

	protected function checkCommandType( PersistenceCommand $command ){
		$writeCommand = false;
		$ddl = false;
		$firstWord = strtoupper( preg_replace( '/^[\s\n]*([^\s]+)\s?.*$/s', '$1', $command->getCommand() ) );
		switch( $firstWord ){
			case 'BEGIN':
			case 'CALL':
			case 'EXECUTE':
			case 'PREPARE':
				trigger_error( 'Use of stored procedure prevents checking for write access/DDL; continuing at your peril...', E_USER_WARNING );
			case 'DESC':
			case 'DESCRIBE':
			case 'SELECT':
			case 'SET':
			case 'SHOW':
				break;
			case 'ALTER':
			case 'CREATE':
			case 'DROP':
			case 'RENAME':
				$ddl = true;
			case 'DELETE':
			case 'INSERT':
			case 'LOAD':
			case 'UPDATE':
			case 'REPLACE':
			case 'TRUNCATE':
				$writeCommand = true;
				break;
			default:
				$this->throwException( 'Unrecognized command "'.$firstWord.'" requested' );
				break;
		}
		if(
			!$this->isWritable()
			&& (
				$writeCommand
				|| $command->getType() == PersistenceCommand::TYPE_PUBLISH
			)
		){
			$this->throwException( 'Write command ('.$firstWord.') requested on an unwritable data store' );
		}
		if( !$this->getTorpor()->permitDDL() && $ddl ){
			$this->throwException( 'Data Defintion Language is not permitted by current settings' );
		}
		return( true );
	}

	public function parseCommand( PersistenceCommand $command, Grid $grid, $final = true ){
		$commandText = $command->getCommand();
		$placeholder = $command->getPlaceholder();
		if( empty( $commandText ) ){
			$this->throwException( 'Invalid command text (empty)' );
		}
		$parameters = &$command->getParameters();
		foreach( $parameters as $index => $parameter ){
			$parameterPlaceholder = null;
			if( strpos( $parameter, Torpor::VALUE_SEPARATOR ) ){
				// WARNING: Magic Number, splitting into at most 2 members, which allows for the possible
				// existence of VALUE_SEPARATOR in the remaining code (which we won't falsely detect, because
				// if there's a placeholder at all regardless of whether VALUE_SEPARATOR exists in it, we've
				// used that same value as a glue betwixt us and it, so this detection and split routine is
				// redundancy safe).
				list( $parameter, $parameterPlaceholder ) = explode( Torpor::VALUE_SEPARATOR, $parameter, 2 );
			}
			if( !$grid->hasColumn( $parameter ) ){
				if( !$final ){ continue; }
				$this->throwException( 'Unknown parameter "'.$parameter.'" (no matching column on '.$grid->_getObjName().' grid)' );
			}
			if( !empty( $parameterPlaceholder ) ){
				if( strpos( $commandText, $parameterPlaceholder ) === false ){
					$this->throwException( 'Named placeholder "'.$parameterPlaceholder.'" not found in '.$grid->_getObjName().' grid command: '.$commandText );
				}
				// Replace all instances.
				$commandText = str_replace(
					$parameterPlaceholder,
					$this->autoQuoteColumn( $grid->Column( $parameter ) ),
					$commandText
				);
				if( !$final ){ unset( $parameters[ $index ] ); }
			} else if( !empty( $placeholder ) ){
				if( strpos( $commandText, $placeholder ) === false ){
					$this->throwException( 'Placeholder "'.$placeholder.'" not found in '.$grid->_getObjName().' grid command: '.$commandText );
				}
				// Replace only a single instance.
				$commandText = substr_replace(
					$commandText,
					$this->autoQuoteColumn( $grid->Column( $parameter ) ),
					strpos( $commandText, $placeholder ),
					strlen( $placeholder )
				);
				if( !$final ){ unset( $parameters[ $index ] ); }
			} else {
				$this->throwException( 'Empty bind variable, cannot parse '.$parameter.' into '.$grid->_getObjName().' grid command: '.$commandText );
			}
		}
		if( $final && !empty( $placeholder ) && strpos( $commandText, $placeholder ) !== false ){
			$this->throwException( 'Un-parsed placeholders found in '.$grid->_getObjName().' grid command: '.$commandText );
		}
		return( $commandText );
	}

	protected function makeClauses( Grid $grid ){
		$clauses = array();
		foreach( $grid->Torpor()->allKeysForGrid( $grid ) as $keySet ){
			if( is_array( $keySet ) ){
				foreach( $keySet as $key ){
					if( $grid->Column( $key )->hasData() ){
						$clauses[] = $this->columnEqualsData( $grid->Column( $key ), true );
					}
				}
			} else {
				if( $grid->Column( $keySet )->hasData() ){
					$clauses[] = $this->columnEqualsData( $grid->Column( $keySet ), true );
				}
			}
		}
		return( $clauses );
	}
	public function gridTableName( Grid $grid ){
		return( $this->escapeDataName( $grid->Torpor()->dataNameForGrid( $grid ) ) );
	}

	public function autoQuoteColumn( Column $column, $localOnly = false ){
		$return = null;
		$localOnly = ( !$column->isLinked() && $localOnly );
		if( $column->hasData() ){
			if( is_null( $column->getPersistData( $localOnly ) ) ){
				$return = 'NULL';
			} else {
				$return = (
					$this->isQuotedType( $column )
					? '\''.$this->escape( $column->getPersistData( $localOnly ) ).'\''
					: $column->getPersistData( $localOnly )
				);
			}
		} else {
			$return = 'NULL';
		}
		return( $return );
	}

	public static function isQuotedType( Column $column ){
		return( in_array( $column->getType(), self::getQuotedTypes() ) );
	}

	public static function getQuotedTypes(){
		return(
			array(
				Column::TYPE_BINARY,
				Column::TYPE_BOOL,
				Column::TYPE_CHAR,
				Column::TYPE_DATE,
				Column::TYPE_DATETIME,
				Column::TYPE_TEXT,
				Column::TYPE_TIME,
				Column::TYPE_VARCHAR
			)
		);
	}
}
?>
