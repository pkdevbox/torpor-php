<?PHP
// $Rev$
require_once( 'ANSISQLDataStore.php' );
class MSSQLDataStore extends ANSISQLDataStore implements DataStore {
	private $_connection = null;
	private $_host = null;
	private $_user = null;
	private $_password = null;
	private $_database = null;
	private $_affected_rows = 0;

	protected $asColumnOperator = 'AS';
	protected $asTableOperator = 'AS';

	protected function MSSQLDataStore(){}

	//*********************************
	//*  DataStore Interface Methods  *
	//*********************************
	public static function createInstance( Torpor $torpor ){
		$dataStore = new MSSQLDataStore();
		$dataStore->setTorpor( $torpor );
		return( $dataStore );
	}

	//***********************************
	//*  Setting & Connection Routines  *
	//***********************************
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
			if( $this->isConnected() ){ $this->disconnect(); }
			$return = $this->_user = $user;
		}
		return( $return );
	}

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
			$connection = mssql_pconnect(
				$this->getHost(),
				$this->getUser(),
				$this->getPassword(),
				$reconnect
			);
			if( !$connection ){
				$this->throwException( 'Could not connect to SQL Server using supplied credentials: '.mssql_get_last_message() );
			}
			$this->setConnection( $connection );
			if( $this->getDatabase() ){
				$this->selectDatabase( $this->getDatabase() );
			}
		}
		return( $this->isConnected() );
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
	protected function disconnect(){
		return( $this->_connection = null );
	}

	protected function selectDatabase( $database ){
		if( $this->isConnected() ){
			if( !mssql_select_db( $database, $this->getConnection() ) ){
				$this->throwException( 'Could not change database to '.$database.': '.$this->error() );
			}
		}
	}
	//*********************************
	//*  Database interface routines  *
	//*********************************
	public function query( $query, array $bindVariables = null ){
		$query = preg_replace( '/\bDISTINCT\b/', '', $query );
		// $bindVariables not supported in MSSQL for non-stored procedures.
		$result = mssql_query( $query, $this->getConnection() );
		return( $result );
	}
	public function error( $resource = null ){ return( mssql_get_last_message() ); }
	public function affected_rows( $resource = null ){ return( mssql_rows_affected( ( is_resource( $resource ) ? $resouce : $this->getConnection() ) ) ); }
	public function fetch_row( $resource ){ return( mssql_fetch_row( $resource ) ); }
	public function fetch_array( $resource ){ return( mssql_fetch_array( $resource ) ); }
	public function fetch_assoc( $resource ){ return( mssql_fetch_assoc( $resource ) ); }

	//*********************************
	//*  ANSISQLDataStore Extensions  *
	//*********************************
	protected function selectAndCount( $selectStatement, $limit = null, $offset = null ){
		$count = null;
		if( strpos( $selectStatement, 'SELECT' ) === 0 ){
			$countStatement = preg_replace( '/^SELECT.*?FROM/', 'SELECT COUNT( 1 ) AS [__COUNT] FROM', $selectStatement, 1 );
			$countStatement = preg_replace( '/ ORDER BY .*$/', '', $countStatement );
			$countResult = $this->query( $countStatement );
			if( $countResult ){
				$countRow = $this->fetch_row( $countResult );
				$count = (int)array_shift( $countRow );
			}
			if( !is_null( $limit ) ){
				// This is a simplistic process, working on the first FROM and the last WHERE.  There
				// are many advanced SQL conditions under which this might break, but which are not
				// currently generated by Torpor; hopefully any such custom SQL knows what's good for
				// itself.
				$orderBy = ( strrpos( $selectStatement, 'ORDER BY' ) !== false ? substr( $selectStatement, strrpos( $selectStatement, 'ORDER BY' ) ) : 'ORDER BY NEWID()' );
				$selectStatement = preg_replace( '/\bFROM\b/', ', ROW_NUMBER() OVER ( '.$orderBy.' ) AS [__ROWNUM] FROM', $selectStatement, 1 );
				// Order has already been applied by windowing function
				$selectStatement = preg_replace( '/ FROM (.*) ORDER BY .*?$/', ' FROM $1', $selectStatement );
				$selectStatement = 'SELECT * FROM ( '.$selectStatement.' ) AS [derived_table] WHERE [__ROWNUM] BETWEEN '.( (int)$offset ?(int)$offset + 1 : (int)$offset ).' AND '.( (int)$offset + (int)$limit ); 
			}
		}
		return( array( $this->query( $selectStatement ), $count ) );
	}

	protected function postInsert( Grid $grid ){
		return( $this->scanForInsertId( $grid ) );
	}

	//************************************
	//*  Utility and supporting methods  *
	//************************************
	public function escape( $arg, $quote = false, $quoteChar = '\'' ){
		$arg = preg_replace( '/\'/', '\'\'', $arg );
		return( ( $quote ? $quoteChar : '' ).$arg.( $quote ? $quoteChar : '' ) );
	}
	public function escapeDataName( $dataName ){
		return( '['.$this->escape( $dataName ).']' );
	}
	public function escapeDataNameAlias( $dataName ){ return( $this->escapeDataName( $dataName ) ); }

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
				$result = $this->query( 'SELECT SCOPE_IDENTITY() AS [LAST_INSERT_ID]' );
				if( !$result || mssql_num_rows( $result ) != 1 ){
					$this->throwException( 'Attempt to fetch last insert id value failed: '.$this->error() );
				}
				$dataRow = $this->fetch_row( $result );
				$foundKeyColumn->setData( array_shift( $dataRow ) );
			}
		}
	}
}

?>
