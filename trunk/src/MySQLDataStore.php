<?PHP
// $Rev$
// TODO: Create a PHP 5.3+ version MySQLiDataStore() extension which uses mysqli
require_once( 'ANSISQLDataStore.php' );
class MySQLDataStore extends ANSISQLDataStore implements DataStore {
	private $_connection = null;
	private $_host = 'localhost';
	private $_user = null;
	private $_password = null;
	private $_database = null;
	private $_affected_rows = 0;

	protected function MySQLDataStore(){}

	protected $_criteriaHandlerMap = array(
		Criteria::TYPE_CONTAINS   => 'criteriaContains',
		Criteria::TYPE_ENDSWITH   => 'criteriaEndsWith',
		Criteria::TYPE_EQUALS     => 'criteriaEquals',
		Criteria::TYPE_IN         => 'criteriaIn',
		Criteria::TYPE_PATTERN    => 'criteriaPattern',
		Criteria::TYPE_STARTSWITH => 'criteriaStartsWith'
	);

	//*********************************
	//*  DataStore Interface Methods  *
	//*********************************
	public static function createInstance( Torpor $torpor ){
		$dataStore = new MySQLDataStore();
		$dataStore->setTorpor( $torpor );
		return( $dataStore );
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
			$connection = mysql_pconnect(
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
				try
				{
					$this->query(
						'SET NAMES '.$this->escape( $character_set, true )
						.(
							$this->getParameter( 'collation' )
							? ' COLLATE '.$this->escape( $this->getParameter( 'collation' ), true )
							: ''
						)
					);
				} catch( TorporException $e ){
					// When using mysql_pconnect, it's not uncommon to encounter a connection
					// that still has an error on the register, since having encountered one
					// would have caused the prior thread to throw an exception.  Since MySQL
					// only clears errors and warnings when a successful operation is
					// undertaken on a *table*, and the above query is not acting on a table,
					// we at most want to turn this into a mild warning.  This will allow
					// further activities to take place on the same connection and flush out
					// the error.  Or if we've encountered a real problem and the connection
					// has gone away then the next query will blow up too, but at least that
					// one isn't being trapped.
					trigger_error( 'Stale error encountered in persistent MySQL connection?: '.$e->getMessage(), E_USER_NOTICE );
				}
			}
		}
	}

	//*********************************
	//*  Database interface routines  *
	//*********************************
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
	public function fetch_row( $resource ){ return( mysql_fetch_row( $resource ) ); }
	public function fetch_array( $resource ){ return( mysql_fetch_array( $resource ) ); }
	public function fetch_assoc( $resource ){ return( mysql_fetch_assoc( $resource ) ); }

	//*********************************
	//*  ANSISQLDataStore Extensions  *
	//*********************************
	// public function preInsert( Grid $grid ){}
	protected function postInsert( Grid $grid ){
		return( $this->scanForInsertId( $grid ) );
	}
	protected function selectAndCount( $selectStatement, $limit = null, $offset = null ){
		$count = null;
		if( strpos( $selectStatement, 'SELECT' ) === 0 ){
			if( !strpos( $selectStatement, 'LIMIT' ) && !is_null( $limit ) ){
				$selectStatement.= ' LIMIT '.(int)$limit.( !is_null( $offset ) ? ' OFFSET '.(int)$offset : '' );
			}
			if( !strpos( $selectStatement, 'SQL_CALC_FOUND_ROWS' ) ){
				$selectStatement = preg_replace( '/^SELECT /', 'SELECT SQL_CALC_FOUND_ROWS ', $selectStatement, 1 );
			}
			$result = $this->query( $selectStatement );
			$count = $this->getFoundRows();
		} else {
			$result = $this->query( $selectStatement );
		}
		return( array( $result, $count ) );
	}
	/*
	public function generateInsertSQL( Grid $grid, array $pairs = null ){}
	public function generateUpdateSQL( Grid $grid, array $pairs = null ){}
	public function generateDeleteSQL( Grid $grid ){}
	public function generateSelectSQL( Grid $grid, array $orderBy = null ){}
	*/
	public function num_rows( $resource ){ return( mysql_num_rows( $resource ) ); }

	//******************************
	//*  Custom Criteria Handlers  *
	//******************************
	protected function criteriaContains( $sourceGridName, Criteria $criteria, $column ){
		list( $target ) = $criteria->getArguments();
		if( $criteria->isColumnTarget() ){
			$target = 'CONCAT( \'%\', '.$this->ColumnNameToSQL( $sourceGridName, array_shift( $target ), array_shift( $target ) ).', \'%\' )';
		} else {
			$target = '\'%'.$this->escape( $target ).'%\'';
		}
		// TODO: Case sensitivity assumes latin1 character set.  This needs to be adapted
		// to the character set of the target field(s)!
		$sql = ( $criteria->isCaseSensitive() ? 'BINARY ' : '' )
			.$column
			.( $criteria->isNegated() ? ' NOT' : '' )
			.' LIKE '.$target;
		return( $sql );
	}
	protected function criteriaEndsWith( $sourceGridName, Criteria $critera, $column ){
		list( $target ) = $criteria->getArguments();
		if( $criteria->isColumnTarget() ){
			$target = 'CONCAT( \'%\', '.$this->ColumnNameToSQL( $sourceGridName, array_shift( $target ), array_shift( $target ) ).' )';
		} else {
			$target = '\'%'.$this->escape( $target ).'\'';
		}
		$sql = ( $criteria->isCaseSensitive() ? 'BINARY ' : '' )
			.$column
			.( $criteria->isNegated() ? ' NOT ' : '' )
			.' LIKE '.$target;
		return( $sql );
	}
	protected function criteriaEquals( $sourceGridName, Criteria $criteria, $column ){
		list( $target ) = $criteria->getArguments();
		if( $criteria->isColumnTarget() ){
			$target = $this->ColumnNameToSQL( $sourceGridName, array_shift( $target ), array_shift( $target ) );
		} else if( !is_null( $target ) ){
			$target = $this->escape( $target, true );
		}
		$sql = ( $criteria->isCaseSensitive() ? 'BINARY ' : '' ).$column.' ';
		if( is_null( $target ) ){
			$sql.= 'IS'.( $criteria->isNegated() ? ' NOT' : '' ).' NULL';
		} else {
			$sql.= ( $criteria->isNegated() ? '!' : '' ).'= '.$target;
		}
		return( $sql );
	}
	protected function criteriaIn( $sourceGridName, Criteria $criteria, $column ){
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
		if( !count( $sqlArgs ) ){
			$this->throwException( 'Nothing in IN criteria, cannot continue' );
		}
		$sql = ( $criteria->isCaseSensitive() ? 'BINARY ' : '' ).$column
			.( $criteria->isNegated() ? ' NOT' : '' )
			.' IN ( '.( implode( ', ', $sqlArgs ) ).' )';
		return( $sql );
	}
	protected function criteriaStartsWith( $sourceGridName, Criteria $criteria, $column ){
		list( $target ) = $criteria->getArguments();
		if( $criteria->isColumnTarget() ){
			$target = 'CONCAT( '.$this->ColumnNameToSQL( $sourceGridName, array_shift( $target ), array_shift( $target ) ).', \'%\' )';
		} else {
			$target = '\''.$this->escape( $target ).'%\'';
		}
			; // TODO: Dynamic binary encoding enforcement
		$sql = ( $criteria->isCaseSensitive() ? 'BINARY ' : '' )
			.$column
			.( $criteria->isNegated() ? ' NOT ' : '' )
			.' LIKE '.$target;
		return( $sql );
	}
	protected function criteriaPattern( $sourceGridName, Criteria $criteria, $column ){
		list( $regex ) = $criteria->getArguments();
		$sql = ( $criteria->isCaseSensitive() ? 'BINARY ' : '' )
				.$column
				.( $criteria->isNegated() ? ' NOT' : '' )
				.' REGEXP '.$this->escape( $regex, true );
		return( $sql );
	}

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
	public function escapeDataNameAlias( $dataName ){ return( $this->escapeDataName( $dataName ) ); }

	protected function scanForInsertId( Grid $grid ){
		if( !$grid->isLoaded() ){
			$primaryKey = $grid->primaryKey();
			$foundKeyColumn = false;
			if( is_array( $primaryKey ) ){
				foreach( $primaryKey as $keyColumn ){
					if( !$keyColumn->isGeneratedOnPublish() || $keyColumn->hasData() )
					{
						continue;
					}
					$foundKeyColumn = $keyColumn;
					break;
				}
			} else if(
				$primaryKey instanceof Column
				&& $primaryKey->isGeneratedOnPublish()
				&& !$primaryKey->hasData()
			){
				$foundKeyColumn = $primaryKey;
			}
			if(
				$foundKeyColumn
				&& in_array(
					// AUTO_INCREMENT (or anything that sets LAST_INSERT_ID() values) can
					// only be numeric in MySQL.  If you have a non-numeric auto-generated
					// primary key field, it will need to be retrieved using other means
					// (either by having a unique key configuration populated within the grid,
					// or by generating that key before sending to the database).
					$foundKeyColumn->getType(),
					array(
						Column::TYPE_FLOAT,
						Column::TYPE_INTEGER,
						Column::TYPE_UNSIGNED_INTEGER
					)
				)
			){
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
