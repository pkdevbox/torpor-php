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

	protected function MySQLDataStore(){}

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
	// TODO: Should custom commands be supported for Delete()?
	public function Delete( Grid $grid ){
		$return = false;
		$clauses = $this->makeClauses( $grid );
		if( !$grid->canLoad() || count( $clauses ) < 1 ){
			throw( new TorporException( 'Cannot publish '.$grid->_getObjName().' grid: no identifying criteria' ) );
		}
		$sql = 'DELETE FROM '.$this->gridTableName( $grid )
			.' WHERE '.implode( ' AND ', $clauses )
			.' LIMIT 1';
		if( !mysql_query( $sql, $this->getConnection() ) ){
			throw( new TorporException( $grid->_getObjName().' delete failed: '.mysql_error( $this->getConnection() ) ) );
		} else {
			if( mysql_affected_rows( $this->getConnection() ) !== 1 ){
				trigger_error( 'No '.$grid->_getObjName().' records deleted', E_USER_WARNING );
			} else {
				$grid->UnLoad( false );
				$return = true;
			}
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
				throw( new TorporException( 'Non-grid argument passed to Execute' ) );
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
			} elseif( $returnAs instanceof GridSet ){
				$return = 0;
				while( $dataRow = mysql_fetch_assoc( $result ) ){
					$returnAs++;
					$grid = $returnAs->Torpor()->_newGrid( $returnAs->gridType() );
					$grid->LoadFromArray( $dataRow, true, true );
					$returnAs->add( $grid );
				}
			} else {
				throw(
					new TorporException(
						'Unknown return type requested (expected one of Grid or GridSet, got '.
						( is_object( $returnAs ) ? get_class( $returnAs ) : gettype( $returnAs ) ).')'
					)
				);
			}
		} else if( $result === true ){
			if( is_object( $returnAs ) ){
				trigger_error( 'Expected return type passed, but no rows returned from command', E_USER_WARNING );
			}
			$return = true;
		} else if( $result === false ){
			throw( new TorporException( 'Command exectution failed: '.mysql_error( $this->getConnection() ) ) );
		}
		return( $return );
	}

	public function Load( Grid $grid, $refresh = false ){
		$return = false;
		if( !$grid->canLoad() ){
			if( $refresh ){
				throw( new TorporException( 'Cannot load '.$grid->_getObjName().' grid: no identifying criteria' ) );
			}
		} else if( !$grid->isLoaded() || $refresh ){
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
					}
				} else {
					if( $result === true ){
						throw( new TorporException( 'Load command for '.$grid->_getObjName().' grid executed successfully but did not return a result set (even an empty one)' ) );
					} else {
						throw( new TorporException( 'Load command for '.$grid->_getObjName().' grid failed: '.mysql_error( $this->getConnection() ) ) );
					}
				}
			} else {
				$declarations = array();
				foreach( $grid->ColumnNames() as $columnName ){
					$declarations[] = $grid->Column( $columnName )->getDataName().' AS \''.$this->escape( $grid->Column( $columnName )->_getObjName() ).'\'';
				}
				$clauses = $this->makeClauses( $grid );
				if( count( $clauses ) < 1 ){
					throw( new TorporException( 'Cannot publish '.$grid->_getObjName().' grid: no identifying criteria' ) );
				}
				$sql = 'SELECT '.implode( ', ', $declarations )
					.' FROM '.$this->gridTableName( $grid )
					.' WHERE '.implode( ' AND ', $clauses );
				$result = mysql_query( $sql, $this->getConnection() );
				if( $result === false ){
					throw( new TorpoException( 'Load of '.$grid->_getObjName().' grid failed: '.mysql_error( $this->_getConnection() ) ) );
				} else if( is_resource( $result ) ){
					$rowCount = mysql_num_rows( $result );
					if( $rowCount == 1 ){
						$grid->LoadFromArray( mysql_fetch_assoc( $result ), true, true );
					} else if( $rowCount > 1 ){
						throw( new TorporException( 'Wrong number of results returned in '.$grid->_getObjName().' load (got '.$rowCount.', expected 1)' ) );
					}
				}
			}
		}
		return( $grid->isLoaded() );
	}


	public function LoadFromCriteria( Grid $grid, Criteria $criteria, $refresh = false ){
	}

	public function LoadSet( GridSet $gridSet, $refresh = false ){
		// TODO: Look for limit offsets.
	}

	public function Publish( Grid $grid, $force = false ){
		if( !$this->isWritable() ){
			throw( new TorporException( 'Read only data store, no publishing permitted' ) );
		}
		$published = false;
		if( !$grid->isDirty() && !$force ){
			// No reason to continue - bail early, it's cheaper.
			return( $published );
		}

		if( in_array( $grid, self::$_publishing, true ) ){
			throw( new TorporException( 'Recursion in dependent object publish or horribly improbable timing collision in duplicate publish attempts' ) );
		} else {
			array_push( self::$_publishing, $grid );
		}

		// TODO:
		// 1. Check if grid is new vs. existing
		// 2. See if there are any defined commands corresponding
		//    the the target action.  When parsing command, return errors
		//    if the parameter name doesn't correllate to any of $grid's $columns
		// 3. Execute 2 or dynamically create & execute SQL
		if( $grid->isReadOnly() ){
			throw( new TorporException( 'Cannot publish read only '.$grid->_getObjName().' grid' ) );
		}
		if( !$grid->canPublish() ){
			if( $grid->Torpor()->publishCascade() ){
				$grid->publishDependencies( $force );
			}
			if( !$grid->canPublish() ){
				throw( new TorporException( 'Cannot publish '.$grid->_getObjName().' grid: required data members not set' ) );
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
		if( count( $commands ) > 0 ){
			foreach( $commands as $command ){
				$result = mysql_query( $this->parseCommand( $command, $grid ), $this->getConnection() );
				if( is_resource( $result ) ){
					if( mysql_num_rows( $result ) > 1 ){
						trigger_error( 'Too many results returned from command; only using first row', E_USER_WARNING );
					}
					$grid->LoadFromArray( mysql_fetch_assoc( $result ), true, true );
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
					throw( new TorporException( $grid->_getObjName().' publish command failed: '.mysql_error( $this->getConnection() ) ) );
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
					throw( new TorporException( 'Cannot force publish '.$grid->_getObjName().' grid: no data members found' ) );
				}
			} else {
				$sql.= ' '.implode( ',', $declarations );
				if( $grid->isLoaded() || $grid->canLoad() ){
					$clauses = $this->makeClauses( $grid );
					if( count( $clauses ) < 1 ){
						throw( new TorporException( 'Cannot publish '.$grid->_getObjName().' grid: no identifying criteria' ) );
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
							throw( new TorporException( $grid->_getObjName().' insert failed' ) );
						}
						$published = true;
						$this->scanForInsertId( $grid );
					} else {
						// The affected rows might be 0 if we've published a record with zero changes.
						$published = true;
					}
				} else {
					throw( new TorporException( 'Publish failed: '.mysql_error( $this->getConnection() ) ) );
				}
			}
		}
		if( $published ){
			if( !$grid->canLoad() ){
				// We've successfully published, but have no way of finding the record we inserted.
				$this->throwException( 'Successful publish but no identifying criteria returned; '.$grid->_getObjName().' grid cannot continue' );
			}
			if( $grid->Torpor()->reloadAfterPublish() ){
				// TODO: Need to reset the grid so the only values it contains are the known
				// keys, so it invokes a just-in-time fetch (but only if it canLoad() after all
				// of the above).  This is more resource intensive than just setting it to a
				// loaded status and !dirty, but far more accurate.
				$grid->UnLoad();
			} else {
				// Causes all of the internal values to be reset to their current version,
				// as well as setting a new reset point.  Dump all fields, and do not attempt
				// to load.
				$grid->LoadFromArray( $grid->dumpArray( true, false ), true );
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
						throw(
							new TorporException( 'Could not change users on MySQL connection: '.mysql_error( $this->getConnection() ) )
						);
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
					throw( new TorporException( 'Could not change database to '.$database.': '.mysql_error( $this->getConnection() ) ) );
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
				throw( new TorporException( 'Could not connect to MySQL using supplied credentials: '.mysql_error() ) );
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
			throw( new TorporException( 'Connection argument is not a valid resource' ) );
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

	//************************************
	//*  Utility and supporting methods  *
	//************************************
	protected function escape( $arg ){
		return(
			preg_replace( '/`/', '\\`',
				mysql_real_escape_string( $arg, $this->getConnection() )
			)
		);
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
				throw( new TorporException( 'Unrecognized command "'.$firstWord.'" requested' ) );
				break;
		}
		if(
			!$this->isWritable()
			&& (
				$writeCommand
				|| $command->getType() == PersistenceCommand::TYPE_PUBLISH
			)
		){
			throw( new TorporException( 'Write command ('.$firstWord.') requested on an unwritable data store' ) );
		}
		if( !$this->getTorpor()->permitDDL() && $ddl ){
			throw( new TorporException( 'Data Defintion Language is not permitted by current settings' ) );
		}
		return( true );
	}

	public function parseCommand( PersistenceCommand $command, Grid $grid, $final = true ){
		$commandText = $command->getCommand();
		$placeholder = $command->getPlaceholder();
		if( empty( $commandText ) ){
			throw( new TorporException( 'Invalid command text (empty)' ) );
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
				throw( new TorporException( 'Unknown parameter "'.$parameter.'" (no matching column on '.$grid->_getObjName().' grid' ) );
			}
			if( !empty( $parameterPlaceholder ) ){
				if( strpos( $commandText, $parameterPlaceholder ) === false ){
					throw( new TorporException( 'Named placeholder "'.$parameterPlaceholder.'" not found in '.$grid->_getObjName().' grid command: '.$commandText ) );
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
					throw( new TorporException( 'Placeholder "'.$placeholder.'" not found in '.$grid->_getObjName().' grid command: '.$commandText ) );
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
				throw( new TorporException( 'Empty bind variable, cannot parse '.$parameter.' into '.$grid->_getObjName().' grid command: '.$commandText ) );
			}
		}
		if( $final && !empty( $placeholder ) && strpos( $commandText, $placeholder ) !== false ){
			throw( new TorporException( 'Un-parsed placeholders found in '.$grid->_getObjName().' grid command: '.$commandText ) );
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
					throw( new TorporException( 'Attempt to fetch last insert id value failed: '.mysql_error( $this->getConnection() ) ) );
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
