<?PHP
// $Rev$
// TODO: Create a PHP 5.3+ version MySQLiDataStore() extension which uses mysqli

// [ ] selectAndCount()
//    [ ] MySQL implementation
//    [ ] Wired-up as appropriate
// [ ] criteriaHandlerMap

require_once( 'ANSISQLDataStore.php' );
class MySQLDataStore extends ANSISQLDataStore implements DataStore {

	private $_connection = null;
	private $_host = 'localhost';
	private $_user = null;
	private $_password = null;
	private $_database = null;
	private $_torpor = null;
	private $_parameters = array();

	private $_writable = false;
	private $_affected_rows = 0;

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
						$this->throwException( 'Could not change users on MySQL connection: '.$this->error() );
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
		return( $this->_database );
	}
	public function setDatabase( $database = null ){
		$database = ( empty( $database ) ? null : $database );
		$return = false;
		if( $database !== $this->getDatabase() ){
			$this->selectDatabase( $database );
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
			$this->setConnection( $connection );
			if( $this->getDatabase() ){
				$this->selectDatabase( $this->getDatabase() );
			}
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

	protected function selectDatabase( $database ){
		if( $this->isConnected() ){
			if( !mysql_select_db( $database, $this->getConnection() ) ){
				$this->throwException( 'Could not change database to '.$database.': '.$this->error() );
			}
			if( $character_set = $this->getParameter( 'character_set' ) ){
				$this->query(
					'SET NAMES '.$this->escape( $character_set, true )
					.(
						$this->getParameter( 'collation' )
						? ' COLLATE '.$this->escape( $this->getParameter( 'collation' ), true )
						: ''
					)
				);
			}
		}
	}

	//********************************
	//*  Database interface routines *
	//********************************
	public function query( $query, array $bindVariables = null ){
		// $bindVariables not supported in MySQL.
		$result = mysql_query( $query, $this->getConnection() );
		$this->_affected_rows = mysql_affected_rows( $this->getConnection() );
		$warning = mysql_query( 'SHOW WARNINGS', $this->getConnection() );
		if( is_resource( $warning ) && $this->num_rows( $warning ) ){
			while( $dataRow = $this->fetch_assoc( $warning ) ){
				$string = implode( ' :: ', $dataRow );
				if( $dataRow{ 'Level' } == 'Error' ){
					$this->throwException( $string );
				} else {
					trigger_error( $string, E_USER_WARNING );
				}
			}
		}
		return( $result );
	}
	public function error( $resource = null ){ return( mysql_error( ( $resource ? $resource : $this->getConnection() ) ) ); }
	public function affected_rows( $resource = null ){ return( ( is_resource( $resource ) ? mysql_affected_rows( $resource ) : $this->_affected_rows ) ); }
	public function num_rows( $resource ){ return( mysql_num_rows( $resource ) ); }
	public function fetch_row( $resource ){ return( mysql_fetch_row( $resource ) ); }
	public function fetch_array( $resource ){ return( mysql_fetch_array( $resource ) ); }
	public function fetch_assoc( $resource ){ return( mysql_fetch_assoc( $resource ) ); }

	//*********************************
	//*  ANSISQLDataStore Extensions  *
	//*********************************
	// public function preInsert( Grid $grid ){}
	public function postInsert( Grid $grid ){
		return( $this->scanForInsertId( $grid ) );
	}
	public function selectAndCount( $selectStatement, $expected = null, $limit = null, $offset = null ){
	}
	/*
	public function generateInsertSQL( Grid $grid, array $pairs = null ){}
	public function generateUpdateSQL( Grid $grid, array $pairs = null ){}
	public function generateDeleteSQL( Grid $grid ){}
	public function generateSelectSQL( Grid $grid ){}
	*/

	//************************************
	//*  Utility and supporting methods  *
	//************************************
	public function getFoundRows(){
		$row = $this->fetch_array( $this->query( 'SELECT FOUND_ROWS()' ) );
		return( (int)array_shift( $row ) );
	}

	public function escape( $arg, $quote = false, $quoteChar = '\'' ){
		return(
			( $quote ? $quoteChar : '' ).preg_replace( '/`/', '\\`',
				mysql_real_escape_string( $arg, $this->getConnection() )
			).( $quote ? $quoteChar : '' )
		);
	}
	public function escapeDataName( $dataName ){
		return( $this->escape( $dataName, true, '`' ) );
	}
	public function escapeDataNameAlias( $dataName ){ return( $this->escapeDataName ); }

	protected function scanForInsertId( Grid $grid ){
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
				$result = $this->query( 'SELECT LAST_INSERT_ID()' );
				if( !$result || $this->num_rows( $result ) != 1 ){
					$this->throwException( 'Attempt to fetch last insert id value failed: '.$this->error() );
				}
				$dataRow = $this->fetch_row( $result );
				$foundKeyColumn->setData( array_shift( $dataRow ) );
			}
		}
	}
}
?>
