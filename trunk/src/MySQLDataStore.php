<?PHP
// $Rev$
// TODO: Create a PHP 5.3+ version MySQLiDataStore() extension which uses mysqli
class MySQLDataStore implements DataStore {
	private $_connection = null;
	private $_host = 'localhost';
	private $_user = null;
	private $_password = null;
	private $_database = null;
	private $_torpor = null;

	private $_writable = false;

	public static $_publishing = array();
	public static $_parsing = array();

	protected function MySQLDataStore(){}

	//*********************************
	//*  DataStore Interface Methods  *
	//*********************************
	public static function createInstance( Torpor $torpor ){
		$dataStore = new MySQLDataStore();
		$dataStore->setTorpor( $torpor );
		return( $dataStore );
	}

	public function initialize( $writeEnabled, array $settings ){
		// TODO: Evaluate settings.
		if( $writeEnabled ){
			$this->_writable = true;
		}
		foreach( $settings as $key => $value ){
			switch( strtolower( $key ) ){
				case 'host':
					$this->setHost( $value );
					break;
				case 'user':
				case 'username':
					$this->setUser( $value );
					break;
				case 'pass':
				case 'password':
					$this->setPassword( $value );
					break;
				case 'db':
				case 'database':
					$this->setDatabase( $value );
					break;
			}
		}
		return( true );
	}

	// No support for generic bind variables using mysql interfaces; the mysqli
	// implementation for 5.3.0 does; use that if you can.
	public function Delete( Grid $grid ){
		$return = false;
		$clauses = $this->makeClauses( $grid );
		if( !$grid->canLoad() || count( $clauses ) < 1 ){
			$this->throwException( 'Cannot delete '.$grid->_getObjName().' grid: no identifying criteria' );
		}
		// TODO: Test DELETE commands.
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
				$result = mysql_query( $this->parseCommand( $command, $grid ), $this->getConnection() );
				if( $result === true ){
					if( ( $affected = mysql_affected_rows( $this->getConnection() ) ) != 1 ){
						trigger_error( 'Successful delete command execution affected '.$affected.' rows, expected 1', E_USER_WARNING );
						if( $affected >= 1 ){
							$grid->UnLoad( false );
							$return = true;
						}
					}
				} else if( $result === false ){
					$this->throwException( $grid->_getObjName().' delete command failed: '.mysql_error( $this->getConnection() ) );
				} else {
					$this->throwException( 'Boolean return expected from '.$grid->_getObjName().' delete command, got '.gettype( $result ) );
				}
			}
		} else {
			$sql = 'DELETE FROM '.$this->gridTableName( $grid )
				.' WHERE '.implode( ' AND ', $clauses )
				.' LIMIT 1';
			if( !mysql_query( $sql, $this->getConnection() ) ){
				$this->throwException( $grid->_getObjName().' delete failed: '.mysql_error( $this->getConnection() ) );
			} else {
				if( mysql_affected_rows( $this->getConnection() ) != 1 ){
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
		$result = mysql_query( $command->getCommand(), $this->getConnection() );
		if( is_resource( $result ) ){
			$rowCount = mysql_num_rows( $result );
			if( $rowCount == 0 ){
				trigger_error( 'No rows returned from executed command', E_USER_WARNING );
				return( $return );
			}
			if( $returnAs instanceof Grid ){
				if( mysql_num_rows( $result ) > 1 ){
					trigger_error( 'Multiple rows returned, only using the first to populate '.$grid->_getObjName().' grid', E_USER_WARNING );
				}
				$return = $returnAs->LoadFromArray( mysql_fetch_assoc( $result ), true, true );
				$this->cacheGrid( $return );
				$return->OnLoad();
			} elseif( $returnAs instanceof GridSet ){
				$return = 0;
				$newCommand = Torpor::OPERATION_NEW.$returnAs->gridType();
				while( $dataRow = mysql_fetch_assoc( $result ) ){
					$returnAs++;
					$grid = $returnAs->Torpor()->$newCommand();
					// TODO: Create a local LoadGridFromArray that calls LoadFromArray, cacheGrid, and OnLoad
					$grid->LoadFromArray( $dataRow, true, true );
					$this->cacheGrid( $grid );
					$grid->OnLoad();
					$returnAs->addGrid( $grid );
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
			$this->throwException( 'Command exectution failed: '.mysql_error( $this->getConnection() ) );
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
				$grid->LoadFromArray( $cachedGrid, true, true );
				$grid->OnLoad();
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
				$result = mysql_query( $this->parseCommand( $command, $grid ), $this->getConnection() );
				if( is_resource( $result ) ){
					$rowCount = mysql_num_rows( $result );
					if( $rowCount > 0 ){
						if( $rowCount > 1 ){
							trigger_error( 'Too many results returned from command; only using first row', E_USER_WARNING );
						}
						$grid->LoadFromArray( mysql_fetch_assoc( $result ), true, true );
						$this->cacheGrid( $grid );
						$grid->OnLoad();
					}
				} else {
					if( $result === true ){
						$this->throwException( 'Load command for '.$grid->_getObjName().' grid executed successfully but did not return a result set (even an empty one)' );
					} else {
						$this->throwException( 'Load command for '.$grid->_getObjName().' grid failed: '.mysql_error( $this->getConnection() ) );
					}
				}
			} else {
				$declarations = array();
				foreach( $grid->ColumnNames() as $columnName ){
					$declarations[] = $grid->Column( $columnName )->getDataName().' AS \''.$this->escape( $grid->Column( $columnName )->_getObjName() ).'\'';
				}
				$clauses = $this->makeClauses( $grid );
				if( count( $clauses ) < 1 ){
					$this->throwException( 'Cannot publish '.$grid->_getObjName().' grid: no identifying criteria' );
				}
				// TODO: Pass an "expected" value to LoadGridFromQuery, which it will check
				// against FOUND_ROWS() and produce appropriate warnings?  Or handle all
				// of that locally?
				$this->LoadGridFromQuery(
					$grid,
					'SELECT SQL_CALC_FOUND_ROWS DISTINCT '.implode( ', ', $declarations )
					.' FROM '.$this->gridTableName( $grid )
					.' WHERE '.implode( ' AND ', $clauses )
					.' LIMIT 1',
					1
				);
			}
		}
		return( $grid->isLoaded() );
	}

	public function LoadFromCriteria( Grid $grid, CriteriaBase $criteria, $refresh = false ){
		if( !$grid->isLoaded() || $refresh ){
			$sql = $this->CriteriaToSQL( $grid, $criteria );
			if( !preg_match( '/limit\s\+\d(\s*,\s*\d)?\s*$/is', $sql ) ){
				$sql.= ' LIMIT 1';
			}
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
			$sql = $this->CriteriaToSQL( $gridType, $finalCriteriaSet );
		} else {
			$escapedGridName = $this->escape( $gridType, true, '`' );
			foreach( $this->getTorpor()->$gridType() as $columnName ){
				$declarations[] = $escapedGridName.'.'
					.$this->escape( $gridSet->Torpor()->dataNameForColumn( $gridType, $columnName ), true, '`' )
					.' AS '.$this->escape( $columnName, true, '`' );
			}
			$sql = 'SELECT SQL_CALC_FOUND_ROWS DISTINCT '.implode( ', ', $declarations )
				.' FROM '.$this->escape( $gridSet->Torpor()->dataNameForGrid( $gridType ), true, '`' )
				.' AS '.$this->escape( $gridType, true, '`' );
		}

		// TODO: break out the SQL construction for a grid set for use
		// in the IN SET and NOT IN SET criteria
		if( count( $sortOrder = $gridSet->getSortOrder() ) > 0 ){
			$orderBy = array();
			foreach( $sortOrder as $sortSpec ){
				list( $gridName, $columnName, $order ) = $sortSpec;
				$orderBy[] = $this->escape( $gridName, true, '`' )
					.'.'.$this->escape( $this->getTorpor()->dataNameForColumn( $gridName, $columnName ), true, '`' )
					.' '.( $order == GridSet::ORDER_DESCENDING ? 'DESC' : 'ASC' );
			}
			$sql.= ' ORDER BY '.implode( ', ', $orderBy );
		}

		if( $gridSet->getPageSize() >= 0 ){
			$sql.= ' LIMIT '
				.( $gridSet->getGridOffset() >= 0 ? $gridSet->getGridOffset().',' : '' )
				.( $gridSet->getPageSize() );
		}

		$result = mysql_query( $sql, $this->getConnection() );
		if( $result === false ){
			$this->throwException( 'Load of '.$gridType.' grid set failed: '.mysql_error( $this->getConnection() ) );
		} else if( $result === true ){
			$this->throwException( 'Load command for '.$gridType.' grid set executed successfully but did not produce a result set (even a zero row result set)' );
		} else if( is_resource( $result ) ){
			if( mysql_num_rows( $result ) > 0 ){
				$newCommand = Torpor::OPERATION_NEW.$gridType;
				while( $dataRow = mysql_fetch_assoc( $result ) ){
					$loadedGrid = $this->getTorpor()->$newCommand();
					$loadedGrid->LoadFromArray( $dataRow, true, true );
					$this->cacheGrid( $loadedGrid );
					$loadedGrid->OnLoad();
					$gridSet->addGrid( $loadedGrid, false );
				}
				$gridSet->setTotalGridCount( $this->getFoundRows() );
				$return = $gridSet->setLoaded();
			}
		} else {
			$this->throwException( 'Cannot handle mysql_query() return type: '.gettype( $resul ) );
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
				$result = mysql_query( $this->parseCommand( $command, $grid ), $this->getConnection() );
				if( is_resource( $result ) ){
					if( mysql_num_rows( $result ) > 1 ){
						trigger_error( 'Too many results returned from command; only using first row', E_USER_WARNING );
					}
					$grid->LoadFromArray( mysql_fetch_assoc( $result ), true, true );
					$this->cacheGrid( $grid );
					$grid->OnLoad();
				} else if( $result === true ){
					if( $new ){
						if( ( $affected = mysql_affected_rows( $this->getConnection() ) ) != 1 ){
							trigger_error( 'Successful publish command execution affected '.$affected.' rows; insert success unknown', E_USER_WARNING );
						} else {
							$published = true;
							$this->scanForInsertId( $grid );
						}
					} else {
						$published = true;
					}
				} else if( $result === false ){
					$this->throwException( $grid->_getObjName().' publish command failed: '.mysql_error( $this->getConnection() ) );
				}
			}
		} else {
			// TODO: Establish a list of possible $command->getCommandType() parameters for MySQL
			// which may allow for both the execution of defined commands and a typical insert;
			// useful for function based GUID or sequence introspection for generatedOnPublish()
			// fields, but without the need to write insert/update statements as well.
			// Dynamic query construction
			$sql = ( $new ? 'INSERT INTO' : 'UPDATE' )
				.' '.$this->gridTableName( $grid ).' SET';
			$declarations = array();
			foreach( $grid->ColumnNames() as $columnName ){
				if(
					$force
					|| $grid->Column( $columnName )->isDirty()
					|| $grid->Column( $columnName )->isLinked()
					|| $grid->Torpor()->publishAllFields()
				){
					$declarations[] = $this->columnEqualsData( $grid->Column( $columnName ) );
				}
			}
			if( count( $declarations ) < 1 ){
				if( $force ){
					$this->throwException( 'Cannot force publish '.$grid->_getObjName().' grid: no data members found' );
				}
			} else {
				$sql.= ' '.implode( ',', $declarations );
				if( $grid->isLoaded() || $grid->canLoad() ){
					$clauses = $this->makeClauses( $grid );
					if( count( $clauses ) < 1 ){
						$this->throwException( 'Cannot publish '.$grid->_getObjName().' grid: no identifying criteria' );
					}
					$sql.= ' WHERE '.implode( ' AND ', $clauses );
				}
				if( mysql_query( $sql, $this->getConnection() ) ){
					// LAST_INSERT_ID() on BIGINT types fail on translation into PHP long, so this
					// will be fetched manually rather than via mysql_insert_id().  Also, this paradigm
					// is only supported for a single column, and will automatically fall to the first
					// generatedOnPublish() column in the primary key for this grid.

					if( $new ){
						if( mysql_affected_rows( $this->getConnection() ) != 1 ){
							$this->throwException( $grid->_getObjName().' insert failed' );
						}
						$published = true;
						$this->scanForInsertId( $grid );
					} else {
						// The affected rows might be 0 if we've published a record with zero changes.
						$published = true;
					}
				} else {
					$this->throwException( 'Publish failed: '.mysql_error( $this->getConnection() ) );
				}
			}
		}
		if( $published ){
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
				$grid->LoadFromArray( $grid->dumpArray( true, false ), true );
				$this->cacheGrid( $grid );
				$grid->OnLoad();
			}
		}
		unset( self::$_publishing[ array_search( $grid, self::$_publishing, true ) ] );
		return( $published );
	}

	//***********************************
	//*  Setting & Connection Routines  *
	//***********************************

	// No internal values shall be publicly disclosed.
	protected function getHost(){ return( $this->_host ); }
	public function setHost( $host = null ){
		$host = ( empty( $host ) ? null : $host );
		$return = false;
		if( $host !== $this->getHost() ){
			if( $this->isConnected() ){ $this->disconnect(); }
			$return = $this->_host = $host;
		}
		return( $return );
	}

	protected function getUser(){ return( $this->_user ); }
	public function setUser( $user = null ){
		$user = ( empty( $user ) ? null : $user );
		$return = false;
		if( func_num_args() > 1 ){
			$this->setPassword( func_get_arg( 1 ) );
		}
		if( $user !== $this->getUser() ){
			if( $this->isConnected() ){
				if( $user !== $this->getUser() ){
					if(
						!mysql_change_user(
							$user,
							$this->getPassword(),
							$this->getDatabase(),
							$this->getConnection()
						)
					){
						$this->throwException( 'Could not change users on MySQL connection: '.mysql_error( $this->getConnection() ) );
					}
				}
			}
			$return = $this->_user = $user;
		}
		return( $return );
	}

	// TODO: Store this encrypted internally, with a rotating randomly generated salt.
	protected function getPassword(){ return( $this->_password ); }
	public function setPassword( $password = null ){
		$password = ( empty( $password ) ? null : $password );
		$return = false;
		if( $password !== $this->getPassword() ){
			if( $this->isConnected() ){ $this->disconnect(); }
			$return = $this->_password = $password;
		}
		return( $return );
	}

	protected function getDatabase(){
		if( $this->isConnected() ){
			$this->_database = mysql_db_name();
		}
		return( $this->_database );
	}
	public function setDatabase( $database = null ){
		$database = ( empty( $database ) ? null : $database );
		$return = false;
		if( $database !== $this->getDatabase() ){
			if( $this->isConnected() ){
				if( !mysql_select_db( $database, $this->getConnection() ) ){
					$this->throwException( 'Could not change database to '.$database.': '.mysql_error( $this->getConnection() ) );
				}
			}
			$return = $this->_database = $database;
		}
		return( $return );
	}

	// Internal Connection Routines
	protected function isConnected(){ return( is_resource( $this->_connection ) ); }
	protected function connect( $reconnect = false ){
		if( !$this->isConnected() || $reconnect ){
			// TODO: Use a setting to differentiate between
			// connect & pconnect
			$connection = mysql_connect(
				$this->getHost(),
				$this->getUser(),
				$this->getPassword(),
				$reconnect
			);
			if( !$connection ){
				$this->throwException( 'Could not connect to MySQL using supplied credentials: '.mysql_error() );
			}
			if( $this->getDatabase() ){
				mysql_select_db( $this->getDatabase(), $connection );
			}
			$this->setConnection( $connection );
		}
		return( $this->isConnected() );
	}
	protected function disconnect(){
		return( $this->_connection = null );
	}

	protected function setConnection( $connection ){
		if( !is_resource( $connection ) ){
			$this->throwException( 'Connection argument is not a valid resource' );
		}
		return( $this->_connection = $connection );
	}
	protected function getConnection(){
		if( !$this->isConnected() ){ $this->connect(); }
		return( $this->_connection );
	}

	protected function setTorpor( Torpor $torpor ){ return( $this->_torpor = $torpor ); }
	public function getTorpor(){ return( $this->_torpor ); }

	public function isWritable(){ return( $this->_writable ); }

	public function throwException( $msg ){
		if( !is_null( $torpor = $this->getTorpor() ) ){
			$torpor->throwException( $msg );
		} else {
			throw( new TorporException( $msg ) );
		}
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
		$result = mysql_query( $sql, $this->getConnection() );
		if( $result === false ){
			$this->throwException( 'Load of '.$grid->_getObjName().' grid failed: '.mysql_error( $this->getConnection() ) );
		} else if( is_resource( $result ) ){
			if( ( $rowCount = mysql_num_rows( $result ) ) >= 1 ){
				$grid->LoadFromArray( mysql_fetch_assoc( $result ), true, true );
				if(
					$expectedRows > 0
					&& (
						( $foundRows = $this->getFoundRows() ) !== $expectedRows
						|| $rowCount !== $expectedRows
					)
				){
					trigger_error(
						'Wrong number of results returned in '.$grid->_getObjName()
						.' load (expected '.$expectedRows.', got '.$foundRows
						.'; only '.min( $expectedRows, $rowCount ).' will be used)', E_USER_WARNING
					);
				}
				$this->cacheGrid( $grid );
				$grid->OnLoad();
			}
		}
	}

	public function getFoundRows(){
		$row = mysql_fetch_array( mysql_query( 'SELECT FOUND_ROWS()', $this->getConnection() ) );
		return( array_shift( $row ) );
	}

	public function escape( $arg, $quote = false, $quoteChar = '\'' ){
		return(
			( $quote ? $quoteChar : '' ).preg_replace( '/`/', '\\`',
				mysql_real_escape_string( $arg, $this->getConnection() )
			).( $quote ? $quoteChar : '' )
		);
	}

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
			$this->escape( $gridAlias, true, '`' ).'.'
			.$this->escape( $this->getTorpor()->dataNameForColumn( $gridName, $columnName ), true, '`' )
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

	public function CriteriaToSQL( $sourceGridName, CriteriaBase $criteria ){
		$sourceGridName = $this->getTorpor()->containerKeyName( $sourceGridName );
		$escapedSourceGridName = $this->escape( $sourceGridName, true, '`' );
		$declarations = array();
		foreach( $this->getTorpor()->$sourceGridName() as $columnName ){
			$declarations[] = $escapedSourceGridName.'.'
				.$this->escape( $this->getTorpor()->dataNameForColumn( $sourceGridName, $columnName ), true, '`' )
				.' AS '.$this->escape( $columnName, true, '`' );
		}
		return(
			'SELECT SQL_CALC_FOUND_ROWS DISTINCT '.implode( ', ', $declarations )
			.' FROM '.$this->CriteriaToJoinClause( $sourceGridName, $criteria ).' '
			.$this->CriteriaToConditions( $sourceGridName, $criteria )
		);
	}

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
				case Criteria::TYPE_CONTAINS:
					list( $target ) = $criteria->getArguments();
					if( $criteria->isColumnTarget() ){
						$target = 'CONCAT( \'%\', '.$this->ColumnNameToSQL( $sourceGridName, array_shift( $target ), array_shift( $target ) ).', \'%\' )';
					} else {
						$target = '\'%'.$this->escape( $target ).'%\'';
					}
					// TODO: Case sensitivity assumes latin1 character set.  This needs to be adapted
					// to the character set of the target field(s)!
					$sql.= ( $criteria->isNegated() ? ' NOT' : '' ).' LIKE '.$target
						.( $criteria->isCaseSensitive() ? ' COLLATE latin1_bin' : '' );
					break;
				case Criteria::TYPE_ENDSWITH:
					list( $target ) = $criteria->getArguments();
					if( $criteria->isColumnTarget() ){
						$target = 'CONCAT( \'%\', '.$this->ColumnNameToSQL( $sourceGridName, array_shift( $target ), array_shift( $target ) ).' )';
					} else {
						$target = '\'%'.$this->escape( $target ).'\'';
					}
					$sql.= ' '.( $criteria->isNegated() ? 'NOT ' : '' ).'LIKE '.$target
						.( $criteria->isCaseSensitive() ? ' COLLATE latin1_bin' : '' );
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
						$sql.= ' '.( $criteria->isNegated() ? '!' : '' ).'= '.$target
							.( $criteria->isCaseSensitive() ? ' COLLATE latin1_bin' : '' );
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
					if( $critera->isColumnTarget() ){
						foreach( $args as $arg ){
							$sqlArgs[] = $this->ColumnNameToSQL( $sourceGridName, array_shift( $arg ), array_shift( $arg ) );
						}
					} else {
						foreach( $args as $arg ){
							$sqlArgs[] = $this->escape( $arg, true );
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
					$sql.= ' '.( $criteria->isNegated() ? 'NOT ' : '' ).'REGEXP '.$this->escape( str_replace( '\\', '\\\\', $regex ), true );
					break;
				case Criteria::TYPE_STARTSWITH:
					list( $target ) = $criteria->getArguments();
					if( $criteria->isColumnTarget() ){
						$target = 'CONCAT( '.$this->ColumnNameToSQL( $sourceGridName, array_shift( $target ), array_shift( $target ) ).', \'%\' )';
					} else {
						$target = '\''.$this->escape( $target ).'%\'';
					}
					$sql.= ' '.( $criteria->isNegated() ? 'NOT ' : '' ).'LIKE '.$target
						.( $criteria->isCaseSensitive() ? ' COLLATE latin1_bin' : '' );
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
					if( !$gridSet->canLoad() && $gridSet->getGridCount() < 1 ){
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

					if( $criteria->isExclusive() && $gridSet->getGridCount() < 1 ){
						$gridSet->Load();
						if( $gridSet->getGridCount() < 1 ){
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
						$inclusiveSQL = 'SELECT DISTINCT '.$this->escape( $this->getTorpor()->dataNameForGrid( $gridSet->gridType() ), true, '`' )
							.'.'.$this->escape( $this->getTorpor()->dataNameForColumn( $gridSet->gridType(), $targetColumnName ), true, '`' )
							.' FROM '.$this->CriteriaToJoinClause( $gridSet->gridType(), $gridSetCriteria )
							.( count( $gridSetCriteria->getCriteria() ) > 0 ? ' '.$this->CriteriaToConditions( $gridSet->gridType(), $gridSetCriteria ) : '' );
					} else {
						if( !$gridSet->isLoaded() && $gridSet->getGridCount() < 1 ){
							$gridSet->Load();
						}
					}
					if( $gridSet->getGridCount() > 0 ){
						$columnValues = array();
						for( $i = 0; $i < $gridSet->getGridCount(); $i++ ){
							$columnValues = $this->escape( $gridSet->getGrid( $i )->Column( $columnName )->getPersistData(), true );
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
								.$sql.( $critiera->isNegated() ? ' NOT' : '' ).' ( '.$loadedSQL.' )'
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
		} else if( $criteria instanceof CriteriaSet ){
			$clauses = array();
			foreach( $criteria as $criterion ){
				$clauses[] = $this->CriteriaToConditions( $sourceGridName, $criterion, '' );
			}
			$sql.= '( '.implode( ' '.$criteria->getType().' ', $clauses ).' )';
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

		$sql = $this->escape( $this->getTorpor()->dataNameForGrid( $sourceGridName ), true, '`' )
			.' AS '.$this->escape( $sourceGridName, true, '`' );

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
					? $this->getTorpor()->referenceKeysBetween( $sourceGridName, $referencedGrid )
					: $this->getTorpor()->aliasReferenceKeysBetween( $sourceGridName, $referencedGrid, $referenceAlias )
				);
			} else if( $this->getTorpor()->canBeReferencedBy( $sourceGridName, $referencedGrid ) ){
				$outerJoin = true;
				$tempReferenceKeys = $this->getTorpor()->referenceKeysBetween( $referencedGrid, $sourceGridName, Torpor::NON_ALIAS_KEYS );
				if( !is_array( $tempReferenceKeys ) || count( $tempReferenceKeys ) < 1 ){
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
				$onConditions[] = $this->escape( $referenceAlias, true, '`' ).'.'
					.$this->escape( $this->getTorpor()->dataNameForColumn( $referencedGrid, $referencedColumnName ), true, '`' ).' = '
					.$this->escape( $sourceGridName, true, '`' ).'.'
					.$this->escape( $this->getTorpor()->dataNameForColumn( $sourceGridName, $columnName ), true, '`' );
			}
			
			$sql.= ' LEFT '.( $outerJoin ? 'OUTER ' : '' ).'JOIN '
				.$this->escape( $this->getTorpor()->dataNameForGrid( $referencedGrid ), true, '`' )
				.' AS '.$this->escape( $referenceAlias, true, '`' )
				.' ON '.implode( ' AND ', $onConditions );
		}

		return( $sql );
	}

	public function columnEqualsData( Column $column, $compare = false ){
		return(
			'`'.$this->escape( $column->getDataName() ).'` '
			.(
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
			case 'PREPARE':
			case 'EXECUTE':
			case 'CALL':
				trigger_error( 'Use of stored procedure prevents checking for write access/DDL; continuing at your peril...', E_USER_WARNING );
			case 'SELECT':
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

	protected function scanForInsertId( $grid ){
		if( !$grid->isLoaded() ){
			$primaryKey = $grid->primaryKey();
			$foundKeyColumn = false;
			if( is_array( $primaryKey ) ){
				foreach( $primaryKey as $keyColumn ){
					if( !$keyColumn->isGeneratedOnPublish() || $keyColumn->hasData() ){ continue; }
					$foundKeyColumn = $keyColumn;
					break;
				}
			} else if( $primaryKey->isGeneratedOnPublish() && !$primaryKey->hasData() ){
				$foundKeyColumn = $primaryKey;
			}
			if( $foundKeyColumn ){
				// Executing the query directly, since the mysql_insert_id() command casts the return
				// value to c(long), and may truncate.
				$result = mysql_query( 'SELECT LAST_INSERT_ID()', $this->getConnection() );
				if( !$result || mysql_num_rows( $result ) != 1 ){
					$this->throwException( 'Attempt to fetch last insert id value failed: '.mysql_error( $this->getConnection() ) );
				}
				$dataRow = mysql_fetch_row( $result );
				$foundKeyColumn->setData( array_shift( $dataRow ) );
			}
		}
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
		return( '`'.$this->escape( $grid->Torpor()->dataNameForGrid( $grid ) ).'`' );
	}

	public function autoQuoteColumn( Column $column ){
		$return = null;
		if( $column->hasData() ){
			if( is_null( $column->getPersistData() ) ){
				$return = 'NULL';
			} else {
				$return = (
					self::isQuotedType( $column )
					? '\''.$this->escape( $column->getPersistData() ).'\''
					: $column->getPersistData()
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
