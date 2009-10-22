<?PHP
// $Rev: 46 $
require_once( 'ANSISQLDataStore.php' );
class SQLiteDataStore extends ANSISQLDataStore implements DataStore {
	private $_connection = null;
	private $_file = 'localhost';
	private $_mode = '0666';
	private $_encoding = 'UTF-8';

	protected function SQLiteDataStore(){}

	//*********************************
	//*  DataStore Interface Methods  *
	//*********************************
	public static function createInstance( Torpor $torpor ){
		$dataStore = new SQLiteDataStore();
		$dataStore->setTorpor( $torpor );
		return( $dataStore );
	}

	//***********************************
	//*  Setting & Connection Routines  *
	//***********************************
	protected function getDatabase(){ return( $this->getFile() ); }
	protected function getFile(){ return( $this->_file ); }

	public function setDatabase( $database = null ){ return( $this->setFile( $database ) ); }
	public function setFile( $file = null ){
		$return = false;
		if( !empty( $file ) ){
			if( !file_exists( $file ) ){
				if( $pathFile = Torpor::getFileInPath( $file ) ){
					trigger_error( 'Specified file "'.$file.'" not found, using "'.$pathFile.'" found in include path', E_USER_WARNING );
					$file = $pathFile;
				} else {
					trigger_error( 'Specified file "'.$file.'" does not exist; attempting to create an empty SQLite database', E_USER_WARNING );
				}
			}
			if( $file !== $this->getFile() ){
				if( $this->isConnected() ){
					$this->disconnect();
				}
				$this->_file = $file;
				$return = true;
			}
		} else {
			if( $this->getFile() ){
				trigger_error( 'No file specified; maintaining prior connection.' );
			} else {
				$this->throwException( 'Cannot connect to or create empty SQLite database' );
			}
		}
		return( $return );
	}

	public function getMode(){ return( $this->_mode ); }
	public function setMode( $mode ){
		$return = false;
		if( $mode != $this->getMode() && preg_match( '/^[0-7]{3,4}$/', $mode ) ){
			if( $this->isConnectedion() ){ $this->disconnect(); } // Will want to reconnect to the file using that mode.
			$return = $this->_mode = $mode;
		}
		return( $mode );
	}

	public function getEncoding(){ return( $this->_encoding ); }
	public function setEncoding( $encoding ){
		$return = false;
		if(
			$encoding != $this->getEncoding()
			&& in_array(
				$encoding,
				array(
					'UTF-8', 
					'UTF-16',
					'UTF-16le',
					'UTF-16be'
				)
			)
		){
			$return = $this->_mode = $mode;
			if( $this->isConnected() ){
				$this->query( 'PRAGMA encoding = "'.$this->getEncoding().'"' );
			}
		}
		return( $mode );
	}

	// Internal Connection Routines
	protected function isConnected(){ return( is_resource( $this->_connection ) ); }
	protected function connect( $reconnect = false ){
		if( !$this->isConnected() || $reconnect ){
			// TODO: Use a setting to differentiate between
			// connect & pconnect
			if( $this->getFile() ){
				$connection = sqlite_popen( $this->getFile(), $this->getMode() );
			}
			if( !$connection ){
				$this->throwException( 'Could not connect to MySQL using supplied credentials: '.mysql_error() );
			}
			$this->setConnection( $connection );
			$this->query( 'PRAGMA case_sensitive_like = true' );
			if( !is_null( $this->getEncoding() ) ){
				$this->query( 'PRAGMA encoding = "'.$this->getEncoding().'"' );
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

	//*********************************
	//*  Database interface routines  *
	//*********************************
	public function query( $query, array $bindVariables = null ){
		// $bindVariables not supported in SQLite.
		return( sqlite_query( $this->getConnection(), $query ) );
	}
	public function error( $resource = null ){ return( sqlite_error_string( sqlite_last_error( ( $resource ? $resource : $this->getConnection() ) ) ) ); }
	public function affected_rows( $resource ){ return( sqlite_changes( $resource ) ); }
	public function num_rows( $resource ){ return( sqlite_num_rows( $resource ) ); }
	public function fetch_row( $resource ){ return( sqlite_fetch_array( $resource, SQLITE_NUM ) ); }
	public function fetch_array( $resource ){ return( sqlite_fetch_array( $resource, SQLITE_BOTH ) ); }
	public function fetch_assoc( $resource ){ return( sqlite_fetch_array( $resource, SQLITE_ASSOC ) ); }

	//*********************************
	//*  ANSISQLDataStore Extensions  *
	//*********************************
	protected function postInsert( Grid $grid ){
		return( $this->scanForInsertId( $grid ) );
	}
	protected function selectAndCount( $selectStatement, $limit = null, $offset = null ){
		$count = null;
		if( strpos( $selectStatement, 'SELECT' ) === 0 ){
			$countStatement = preg_replace(
				'/^SELECT.*?FROM/',
				'SELECT COUNT( 1 ) '.$this->asColumnOperator.' '.$this->escapeDataName( 'THECOUNT' ).' FROM',
				$selectStatement,
				1
			);
			$countResult = $this->query( $countStatement );
			if( is_resource($countResult ) ){
				$countRow = $this->fetch_row( $countResult );
				$count = (int)array_shift( $countRow );
			}
			if( !strpos( $selectStatement, 'LIMIT' ) && !is_null( $limit ) ){
				$selectStatement.= ' LIMIT '.(int)$limit.( !is_null( $offset ) ? ' OFFSET '.(int)$offset : '' );
			}
		}
		return( array( $this->query( $selectStatement ), $count ) );
	}
	/*
	public function generateInsertSQL( Grid $grid, array $pairs = null ){}
	public function generateUpdateSQL( Grid $grid, array $pairs = null ){}
	public function generateDeleteSQL( Grid $grid ){}
	public function generateSelectSQL( Grid $grid, array $orderBy = null ){}
	*/

	//************************************
	//*  Utility and supporting methods  *
	//************************************

	public function escape( $arg, $quote = false, $quoteChar = '\'' ){
		return(
			( $quote ? $quoteChar : '' ).sqlite_escape_string( $arg ).( $quote ? $quoteChar : '' )
		);
	}
	public function escapeDataName( $dataName ){
		return( $this->escape( $dataName, true, '"' ) );
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
			if(
				$foundKeyColumn
				&& $lastInsertId = sqlite_last_insert_rowid( $this->getConnection() )
			){
				$foundKeyColumn->setData( array_shift( $lastInsertId ) );
			}
		}
	}
}
?>
