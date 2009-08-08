<?PHP
// TODO: Create a PHP 5.3+ version MySQLiDataStore() extension which uses mysqli
class MySQLDataStore implements DataStore {
	private $_connection = null;
	private $_host = 'localhost';
	private $_user = null;
	private $_password = null;
	private $_database = null;
	private $_salt = null;

	public static $_publishing = array();

	public function MySQLDataStore( $settings = null ){
		if( is_array( $settings ) ){
			$this->initialize( $settings );
		}
	}

	public function initialize( array $settings ){
		// TODO: create a new salt
		// TODO: Evaluate settings.
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
			throw( new Exception( 'Recursion in dependent object publish or horribly improbable timing collision in duplicate publish attempts' ) );
		} else {
			array_push( self::$_publishing, $grid );
		}

		// TODO:
		// 1. Check if grid is new vs. existing
		// 2. See if there are any defined commands corresponding
		//    the the target action
		// 3. Execute 2 or dynamically create & execute SQL
		if( $grid->isReadOnly() ){
			throw( new Exception( 'Cannot publish Read Only grid' ) );
		}
		if( !$grid->canPublish() && !$grid->publishDependencies() ){
			throw( new Exception( 'Cannot publish grid: required data members not set' ) );
		}

		// Dynamic query construction
		$sql = 'REPLACE INTO `'.$this->escape( $grid->Torpor()->dataNameForGrid( $grid ) ).'` SET';
		$commands = array();
		foreach( $grid->Columns() as $column ){
			if( $column->isDirty() || $force ){
				$commands[] = $this->columnEqualsData( $column );
			}
		}
		if( count( $commands ) < 1 ){
			if( $force ){
				throw( new Exception( 'Cannot force publish grid: no data members found' ) );
			}
		} else {
			$sql.= ' '.implode( ',', $commands );
			if( $grid->isLoaded() || $grid->canLoad() ){
				$clauses = $this->makeClauses( $grid );
				if( count( $clauses ) < 1 ){
					throw( new Exception( 'Cannot publish grid: no identifying criteria' ) );
				}
				$sql.= ' WHERE '.implode( ' AND ', $clauses );
			}
			var_dump( $sql );
			// TODO: How to determine, based on keys that are generatedOnPublish, which ones
			// will actually ve available?
			// TODO: Retrieve any generatedOnPublish data
			// TODO: Propagate any MySQL warnings and modifications to the data
			// TODO: Set the actual $grid as isLoaded with corresponding data
			$return = true; // Dependent on the success of the actual query.
		}
		unset( self::$_publishing[ array_search( $grid, self::$_publishing, true ) ] );
		return( $return );
	}

	public function Load( Grid $grid, $refresh = false ){
		$return = false;
		if( !$grid->canLoad() ){
			if( $refresh ){
				throw( new Exception( 'Cannot load grid: no identifying criteria' ) );
			}
		} else if( !$grid->isLoaded() || $refresh ){
			// TODO: Look for Load commands from /TorporConfig/Grids/Grid/Commands/*
			$commands = array();
			foreach( $grid->Columns() as $column ){
				$commands[] = $column->getDataName().' AS \''.$this->escape( $column->_getObjName() ).'\'';
			}
			$clauses = $this->makeClauses( $grid );
			if( count( $clauses ) < 1 ){
				throw( new Exception( 'Cannot publish grid: no identifying criteria' ) );
			}
			$sql = 'SELECT '.implode( ', ', $commands )
				.' FROM `'.$this->escape( $grid->Torpor()->dataNameForGrid( $grid ) ).'`'
				.' WHERE '.implode( ' AND ', $clauses );
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
							new Exception( 'Could not change users on MySQL connection: '.mysql_error() )
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
					throw( new Exception( 'Could not change database to '.$database.': '.mysql_error() ) );
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
				throw( new Exception( 'Could not connect to MySQL using supplied credentials: '.mysql_error() ) );
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
			throw( new Exception( 'Connection argument is not a valid resource' ) );
		}
		return( $this->_connection = $connection );
	}
	protected function getConnection(){
		if( !$this->isConnected() ){ $this->connect(); }
		return( $this->_connection );
	}
	protected function escape( $arg ){
		return(
			preg_replace( '/`/', '',
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

	public function autoQuoteColumn( Column $column ){
		$return = null;
		if( $column->hasData() ){
			$return = (
				self::isQuotedType( $column )
				? '\''.$this->escape( $column->getPersistData() ).'\''
				: $column->getPersistData()
			);
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
