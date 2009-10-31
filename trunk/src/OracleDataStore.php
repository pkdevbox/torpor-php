<?PHP
// $Rev$
require_once( 'ANSISQLDataStore.php' );
class OracleDataStore extends ANSISQLDataStore implements DataStore {
	private $_connection = null;
	private $_schema = null;
	private $_user = null;
	private $_password = null;
	private $_charSet = null;

	private $_statementCache = array();

	protected $asColumnOperator = 'AS';
	protected $asTableOperator = '';

	protected function OracleDataStore(){}

	//*********************************
	//*  DataStore Interface Methods  *
	//*********************************
	public static function createInstance( Torpor $torpor ){
		$dataStore = new OracleDataStore();
		$dataStore->setTorpor( $torpor );
		return( $dataStore );
	}

	//***********************************
	//*  Setting & Connection Routines  *
	//***********************************
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

	protected function getSchema(){ return( $this->_schema ); }
	public function setSchema( $schema = null ){
		$schema = ( empty( $schema ) ? null : $schema );
		$return = false;
		if( $schema !== $this->getSchema() ){
			if( $this->isConnected() ){ $this->disconnect(); }
			$return = $this->_schema = $schema;
		}
		return( $return );
	}

	protected function isConnected(){ return( is_resource( $this->_connection ) ); }
	protected function connect( $reconnect = false ){
		if( !$this->isConnected() || $reconnect ){
			$connection = oci_pconnect(
				$this->getUser(),
				$this->getPassword(),
				$this->getSchema(),
				$this->getCharSet()
			);
			if( !$connection ){
				$this->throwException( 'Could not connect to Oracle using supplied credentials: '.mysql_error() );
			}
			$this->setConnection( $connection );
			$this->query( 'ALTER SESSION SET NLS_DATE_FORMAT = \'YYYY-MM-DD HH24:MI:SS\'' );
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

	public function getCharSet(){ return( $this->getParameter( 'character_set' ) ); }
	protected function setCharSet( $charSet ){ return( $this->setParameter( 'character_set', $charSet ) ); }

	public function getPrefetch(){ return( ( is_null( $this->getParameter( 'prefetch' ) ) ? 500 : $this->getParameter( 'prefetch' ) ) ); }
	protected function setPrefetch( $prefetch ){ return( $this->setParameter( 'prefetch', $prefetch ) ); }

	//*********************************
	//*  Database interface routines  *
	//*********************************
	public function query( $query, array $bindVariables = null ){
		$statment = null;
		if( !array_key_exists( $query, $this->_statementCache ) && is_array( $bindVariables ) ){
			// To keep memory constraints down, only cache those statements which can be used
			// with bind variables.
			$this->_statementCache{ $query } = oci_parse( $this->getConnection(), $query );
			$statement = $this->_statementCache{ $query };
			if( count( $bindVariables ) > 0 ){
				foreach( $bindVariables as $bindName => $variable ){
					oci_bind_by_name( $statment, $bindName, $variable );
				}
			}
		} else {
			$statement = oci_parse( $this->getConnection(), $query );
		}
		oci_set_prefetch( $statement, $this->getPrefetch() );
		oci_execute( $statement );
		if( !preg_match( '/^\s*select/i', $query ) ){
			$this->commit();
		}
		return( $statement );
	}
	// TODO: determine, within commit, whether or not we are at a point in the transaction that we
	// really want to.
	public function commit( $resource = null ){ return( oci_commit( ( is_resource( $resource ) ? $resource : $this->getConnection() ) ) ); }
	public function error( $resource = null ){ return( implode( ' :: ', oci_error( ( is_resource( $resource ) ? $resource : $this->getConnection() ) ) ) ); }
	public function affected_rows( $resource = null ){ return( oci_num_rows( ( is_resource( $resource ) ? $resource : $this->getConnection() ) ) ); }
	public function fetch_row( $resource ){ return( $this->lobscan( oci_fetch_row( $resource ) ) ); }
	public function fetch_array( $resource ){ return( $this->lobscan( oci_fetch_array( $resource ) ) ); }
	public function fetch_assoc( $resource ){ return( $this->lobScan( oci_fetch_assoc( $resource ) ) ); }

	public function lobScan( $row ){
		$lobObj = 'OCI-Lob';
		if( is_object( $row ) ){
			foreach( get_object_vars( $row ) as $key => $value ){
				if( $value instanceof $lobObj ){
					$obj->{$key} = $value->read( $value->size() );
				}
			}
		} else if( is_array( $row ) ){
			foreach( $row as $key => $value ){
				if( $value instanceof $lobObj ){
					$row{ $key } = $value->read( $value->size() );
				}
			}
		}
		return( $row );
	}

	//*********************************
	//*  ANSISQLDataStore Extensions  *
	//*********************************
	public function preInsert( Grid $grid ){
		// TODO:
		// Use a global table sequence name algorithm as
		// a fallback (something like table_sequence_suffix,
		// table_sequence_pattern [regex to derive from table],
		// etc.)
		if(
			$grid->parameterExists( 'sequence' )
			&& (
				!$grid->parameterExists( 'sequence_order' )
				|| (
					$grid->parameterExists( 'sequence_order' )
					&& $grid->parameterGet( 'sequence_order' ) == 'before'
				)
			)
		){
			$this->scanForInsertId( $grid, $grid->parameterGet( 'sequence' ) );
		}
	}
	protected function selectAndCount( $selectStatement, $limit = null, $offset = null ){
		$count = null;
		if( strpos( $selectStatement, 'SELECT' ) === 0 ){
			$countStatement = preg_replace( '/^SELECT.*?FROM/', 'SELECT COUNT( 1 ) AS "__COUNT" FROM', $selectStatement, 1 );
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
				$orderBy = ( strrpos( $selectStatement, 'ORDER BY' ) !== false ? substr( $selectStatement, strrpos( $selectStatement, 'ORDER BY' ) ) : 'ORDER BY ROWNUM' );
				$selectStatement = preg_replace( '/\bFROM\b/', ', ROW_NUMBER() OVER ( '.$orderBy.' ) AS "__ROWNUM" FROM', $selectStatement, 1 );
				// Order has already been applied by windowing function
				$selectStatement = preg_replace( '/ FROM (.*) ORDER BY .*?$/', ' FROM $1', $selectStatement );
				$selectStatement = 'SELECT * FROM ( '.$selectStatement.' ) WHERE "__ROWNUM" BETWEEN '.(int)$offset.' AND '.( (int)$offset + (int)$limit );
			}
		}
		return( array( $this->query( $selectStatement ), $count ) );
	}
	public function postInsert( Grid $grid ){
		if(
			$grid->parameterExists( 'sequence' )
			&& $grid->parameterExists( 'sequence_order' )
			&& $grid->parameterGet( 'sequence_order' ) == 'after'
		){
			$this->scanForInsertId( $grid, $grid->parameterGet( 'sequence' ), false );
		}
	}

	//************************************
	//*  Utility and supporting methods  *
	//************************************
	public function escape( $arg, $quote = false, $quoteChar = '\'' ){
		$arg = preg_replace( '/\'/', '\'\'', $arg );
		return(
			( $quote ? $quoteChar : '' ).$arg.( $quote ? $quoteChar : '' )
		);
	}
	public function escapeDataName( $dataName ){
		return( '"'.preg_replace( '/[^A-Za-z0-9\-_ ]/', '', $dataName ).'"' );
	}
	public function escapeDataNameAlias( $dataName ){ return( $this->escapeDataName( $dataName ) ); }

	protected function scanForInsertId( Grid $grid, $sequence, $next = true ){
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
				$result = $this->query(
					'SELECT '
					.$this->escapeDataName( $sequence )
					.'.'.$this->escapeDataName( ( $next ? 'NEXTVAL' : 'CURRVAL' ) )
					.' '.$this->asColumnOperator
					.' '.$this->escapeDataNameAlias( 'LAST_INSERT_ID' )
					.' FROM '.$this->escapeDataName( 'DUAL' )
				);
				if( !is_resource( $result ) ){
					$this->throwException( 'Attempt to fetch last insert id value failed: '.$this->error() );
				}
				$dataRow = $this->fetch_row( $result );
				$foundKeyColumn->setData( array_shift( $dataRow ) );
			}
		}
	}
}

?>
