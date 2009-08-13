<?PHP
// $Rev$
// TODO: Create a PHP 5.3+ version MySQLiDataStore() extension which uses mysqli
class MySQLDataStore implements DataStore {
	private $_connection = null;
	private $_host = 'localhost';
	private $_user = null;
	private $_password = null;
	private $_database = null;
	private $_salt = null;
	private $_torpor = null;

	public static $_publishing = array();

	public function MySQLDataStore( Torpor $torpor = null, array $settings = null ){
		if( $torpor instanceof Torpor && is_array( $settings ) ){
			$this->initialize( $torpor, $settings );
		}
	}

	public function initialize( Torpor $torpor, array $settings ){
		// TODO: create a new salt
		// TODO: Evaluate settings.
		$this->setTorpor( $torpor );
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
		// TODO: Return success of initialization
	}

	public function Publish( Grid $grid, $force = false ){
		$return = false; // Indicates whether we have published anything.
		if( !$grid->isDirty() && !$force ){
			// No reason to continue - bail early, it's cheaper.
			return( $return );
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
			throw( new TorporException( 'Cannot publish Read Only grid' ) );
		}
		if( !$grid->canPublish() && !$grid->publishDependencies() ){
			throw( new TorporException( 'Cannot publish grid: required data members not set' ) );
		}

		$new = ( $grid->isLoaded() ? false : true );
		$commands = array();
		if( $gridCommands = $grid->Torpor()->gridCommands( $grid, PersistenceCommand::TYPE_PUBLISH ) ){
			$context = ( $new ? PersistenceCommand::CONTEXT_NEW : PersistenceCommand::CONTEXT_EXISTING );
			foreach( $gridCommands as $command ){
				if( $command->getContext() == $context || $command->getContext() == PersistenceCommand::CONTEXT_ALL ){
					$commands[] = $command;
				}
			}
		}
		if( count( $commands ) > 0 ){
			foreach( $commands as $command ){
				$result = mysql_query( $this->parseCommand( $command, $grid ), $this->getConnection() );
				// TODO: Examine return $result for type bool, look for affected rows, and generally 
				// match up keys or otherwise determine the auto_increment keys for the current $grid
			}
		} else {
			// Dynamic query construction
			$sql = ( $new ? 'UPDATE' : 'INSERT INTO' )
				.' '.$this->gridTableName( $grid ).' SET';
			$declarations = array();
			foreach( $grid->Columns() as $column ){
				if( $column->isDirty() || $force ){
					$declarations[] = $this->columnEqualsData( $column );
				}
			}
			if( count( $declarations ) < 1 ){
				if( $force ){
					throw( new TorporException( 'Cannot force publish grid: no data members found' ) );
				}
			} else {
				$sql.= ' '.implode( ',', $declarations );
				if( $grid->isLoaded() || $grid->canLoad() ){
					$clauses = $this->makeClauses( $grid );
					if( count( $clauses ) < 1 ){
						throw( new TorporException( 'Cannot publish grid: no identifying criteria' ) );
					}
					$sql.= ' WHERE '.implode( ' AND ', $clauses );
				}
				var_dump( $sql );
				if( mysql_query( $sql, $this->getConnection() ) ){
					// LAST_INSERT_ID() on BIGINT types fail on translation into PHP long, so this
					// will be fetched manually rather than via mysql_insert_id().  Also, this paradigm
					// is only supported for a single column, and will automatically fall to the first
					// generatedOnPublish() column in the primary key for this grid.
					if( !$grid->isLoaded() ){
						if( mysql_affected_rows( $this->getConnection() ) != 1 ){
							throw( new TorporException( 'Insert failed' ) );
						}
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
							$result = mysql_query( 'SELECT LAST_INSERT_ID()', $this->getConnection() );
							if( !$result || mysql_num_rows( $result ) != 1 ){
								throw( new TorporException( 'Attempt to fetch last insert id value failed: '.mysql_error( $this->getConnection() ) ) );
							}
							$dataRow = mysql_fetch_row( $result );
							$foundKeyColumn->setData( array_shift( $dataRow ) );
						}
					}
					// TODO: Need to reset the grid so the only values it contains are the known
					// keys, so it invokes a just-in-time fetch (but only if it canLoad() after all
					// of the above).  This is more resource intensive than just setting it to a
					// loaded status and !dirty, but far more accurate.
				} else {
					throw( new TorporException( 'Publish failed: '.mysql_error( $this->getConnection() ) ) );
				}
				// TODO: How to determine, based on keys that are generatedOnPublish, which ones
				// will actually be available?
				// TODO: Retrieve any generatedOnPublish data
				// TODO: Propagate any MySQL warnings and modifications to the data
				// TODO: Set the actual $grid as isLoaded with corresponding data
				$return = true; // Dependent on the success of the actual query.
			}
		}
		unset( self::$_publishing[ array_search( $grid, self::$_publishing, true ) ] );
		return( $return );
	}

	public function Delete( Grid $grid ){
		$return = false;
		$clauses = $this->makeClauses( $grid );
		if( !$grid->canLoad() || count( $clauses ) < 1 ){
			throw( new TorporException( 'Cannot publish grid: no identifying criteria' ) );
		}
		$sql = 'DELETE FROM '.$this->gridTableName( $grid )
			.' WHERE '.implode( ' AND ', $clauses )
			.' LIMIT 1';
		if( !mysql_query( $sql, $this->getConnection() ) ){
			throw( new Exception( 'Delete failed: '.mysql_error( $this->getConnection() ) ) );
		} else {
			if( mysql_affected_rows( $this->getConnection() ) !== 1 ){
				trigger_error( 'No records deleted', E_USER_WARNING );
			} else {
				$return = true;
			}
		}
		return( $return );
	}

	public function Load( Grid $grid, $refresh = false ){
		$return = false;
		if( !$grid->canLoad() ){
			if( $refresh ){
				throw( new TorporException( 'Cannot load grid: no identifying criteria' ) );
			}
		} else if( !$grid->isLoaded() || $refresh ){
			// TODO: Look for Load commands from /TorporConfig/Grids/Grid/Commands/*
			$declarations = array();
			foreach( $grid->Columns() as $column ){
				$declarations[] = $column->getDataName().' AS \''.$this->escape( $column->_getObjName() ).'\'';
			}
			$clauses = $this->makeClauses( $grid );
			if( count( $clauses ) < 1 ){
				throw( new TorporException( 'Cannot publish grid: no identifying criteria' ) );
			}
			$sql = 'SELECT '.implode( ', ', $declarations )
				.' FROM '.$this->gridTableName( $grid )
				.' WHERE '.implode( ' AND ', $clauses );
			// TODO: Execute SQL, generate a named array, and send that to the grid.
			var_dump( $sql );
		}
		return( $return );
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

	public function LoadFromCriteria( Grid $grid, Criteria $criteria, $refresh = false ){
	}

	public function LoadSet( GridSet $gridSet, $refresh = false ){
		// TODO: Look for limit offsets.
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

	public function setTorpor( Torpor $torpor ){ return( $this->_torpor = $torpor ); }
	public function getTorpor(){ return( $this->_torpor ); }

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

	// No support for generic bind variables using mysql interfaces; the mysqli
	// implementation for 5.3.0 does; use that if you can.
	public function parseCommand( PersistenceCommand $command, Grid $grid ){
		$commandText = $command->getCommand();
		$placeholder = $command->getPlaceholder();
		if( empty( $commandText ) ){
			throw( new TorporException( 'Invalid command text (empty)' ) );
		}
		foreach( $command->getParameters() as $parameter ){
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
				throw( new TorporException( 'Unknown parameter "'.$parameter.'" (no matching column on grid '.$grid->_getObjName() ) );
			}
			if( !empty( $parameterPlaceholder ) ){
				if( strpos( $commandText, $parameterPlaceholder ) === false ){
					throw( new TorporException( 'Named placeholder "'.$parameterPlaceholder.'" not found in command: '.$commandText ) );
				}
				// Replace all instances.
				$commandText = str_replace(
					$parameterPlaceholder,
					$this->autoQuoteColumn( $grid->Column( $parameter ) ),
					$commandText
				);
			} else if( !empty( $placeholder ) ){
				if( strpos( $commandText, $placeholder ) === false ){
					throw( new TorporException( 'Placeholder "'.$placeholder.'" not found in command: '.$commandText ) );
				}
				// Replace only a single instance.
				$commandText = substr_replace(
					$commandText,
					$this->autoQuoteColumn( $grid->Column( $parameter ) ),
					strpos( $commandText, $placeholder ),
					strlen( $placeholder )
				);
			} else {
				throw( new TorporException( 'Empty bind variable, cannot parse '.$parameter.' into command: '.$commandText ) );
			}
		}
		if( !empty( $placeholder ) && strpos( $commandText, $placeholder ) !== false ){
			throw( new TorporException( 'Un-parsed placeholders found in command: '.$commandText ) );
		}
		return( $commandText );
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
