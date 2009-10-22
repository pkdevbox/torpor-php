<?PHP
// $Rev$
require_once( 'ANSISQLDataStore.php' );
// TODO: Special handling for LOB/CLOB interfaces?
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
			$this->query( 'SET ESCAPE \\' );
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

	public function getCharSet(){ return( $this->getParameter( 'characterSet' ) ); }
	protected function setCharSet( $charSet ){ return( $this->setParameter( 'characterSet', $charSet ) ); }

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
		return( $statement );
	}
	public function error( $resource = null ){ return( implode( ' :: ', oci_error( ( $resource ? $resource : $this->getConnection() ) ) ) ); }
	public function affected_rows( $resource = null ){ return( oci_num_rows( ( is_resource( $resource ) ? $resouce : $this->getConnection() ) ) ); }
	public function fetch_row( $resource ){ return( oci_fetch_row( $resource ) ); }
	public function fetch_array( $resource ){ return( oci_fetch_array( $resource ) ); }
	public function fetch_assoc( $resource ){ return( oci_fetch_assoc( $resource ) ); }

	//*********************************
	//*  ANSISQLDataStore Extensions  *
	//*********************************
	public function preInsert( Grid $grid ){
		// Need to look for anything that can get us the ID ahead of time: automatic sequence fetch and populate,
		// derived either from parameters passed globally to this DataStore or identified specifically on the
		// grid in question (checking grid first, then falling back to $this, then throwing an exception)
		// Have an option for special: call a function instead of executing a sequence (thus can grab GUID, etc.)
		$this->throwException( 'Need to determine sequence scheme!' );
	}
	protected function selectAndCount( $selectStatement, $limit = null, $offset = null ){
		$count = null;
		if( strpos( $selectStatement, 'SELECT' ) === 0 ){
			$countStatement = preg_replace( '/^SELECT.*?FROM/', 'SELECT COUNT( 1 ) AS "__COUNT" FROM', $selectStatement, 1 );
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
				$selectStatement = 'SELECT * FROM ( '.$selectStatement.' ) WHERE "__ROWNUM" BETWEEN '.(int)$offset.' AND '.( (int)$offset + (int)$limit );
			}
		}
		return( array( $this->query( $selectStatement ), $count ) );
	}

	//************************************
	//*  Utility and supporting methods  *
	//************************************
	public function escape( $arg, $quote = false, $quoteChar = '\'' ){
		$arg = preg_replace( '/\'/', '\'\'', $arg );
		$arg = preg_replace( '/&/', '\\&', $arg );
		return(
			( $quote ? $quoteChar : '' ).$arg.( $quote ? $quoteChar : '' )
		);
	}
	public function escapeDataName( $dataName ){
		return( '"'.preg_replace( '/[^A-Za-z0-9\-_ ]/', '', $dataName ).'"' );
	}
	public function escapeDataNameAlias( $dataName ){ return( $this->escapeDataName( $dataName ) ); }
}

?>
