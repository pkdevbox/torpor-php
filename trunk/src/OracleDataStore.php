<?PHP
// $Rev$
class OracleDataStore extends ANSISQLDataStore implements DataStore {
	private $_connection = null;
	private $_schema = null;
	private $_user = null;
	private $_password = null;
	private $_charSet = null;

	private $_statementCache = array();

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
				$this->throwException( 'Could not connect to MySQL using supplied credentials: '.mysql_error() );
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
			$this->_statementCache{ $query } = oci_parse( $query );
			$statement = $this->_statementCache{ $query };
			if( count( $bindVariables ) > 0 ){
				foreach( $bindVariables as $bindName => $variable ){
					oci_bind_by_name( $statment, $bindName, $variable );
				}
			}
		} else {
			$statement = oci_parse( $query );
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
	}
}

?>
