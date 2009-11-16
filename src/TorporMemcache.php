<?PHP
// $Rev$
class TorporMemcache implements TorporCache {
	private static $_instance;
	private $_torpor;
	private $_memcache;
	private $_compression = false;
	private $_compression_threshold = -1;
	private $_ttl = 10800; // Default to 3 hours.

	private function __construct( Torpor $torpor ){
		$this->_torpor = $torpor;
		$this->_memcache = new Memcache();
	}

	public static function createInstance( Torpor $torpor ){
		if( !( self::$_instance instanceof TorporMemcache ) ){
			self::$_instance = new TorporMemcache( $torpor );
		}
		return( self::$_instance );
	}

	public function initialize( array $settings ){
		$servers = array();
		foreach( $settings as $key => $value ){
			$key = strtolower( $key );
			if( preg_match( '/^server_/', $key ) ){
				$key = preg_replace( '/^server_(.*)$/', '$1', $key );
				if( !is_numeric( $value ) ){
					throw( new TorporException( 'Unrecognized value "'.$value.'" in server/port mapping for "'.$key.'"' ) );
				}
				$servers{ $key } = $value; // Port
			} else {
				switch( $key ){
					case 'ttl':
						$this->setTTL( $value );
						break;
					case 'compression':
						$this->setCompression( $value );
						break;
					case 'compression_threshold':
						$this->setCompressionThreshold( $value );
						break;
					default:
						trigger_error( 'Unrecognized option "'.$key.'"', E_USER_WARNING );
						break;
				}
			}
		}
		foreach( $servers as $host => $port ){
			$this->_memcache->addServer( $host, $port );
		}
		if( $this->getCompressionThreshold() > 0 ){
			$this->_memcache->setCompressThreshold( $this->getCompressionThreshold() );
		}
	}

	public function writeGrid( Grid $grid ){
		if( !$grid->isLoaded() ){
			$this->Torpor()->throwException( $this->Torpor()->containerKeyName( $grid ).' grid not loaded (can only cache loaded grids)' );
		}

		return(
			$this->_memcache->set(
				$this->makeGridKey( $grid ),
				$grid->dumpArray( true ),
				( $this->getCompression() ? MEMCACHE_COMPRESSED : null ),
				$this->getTTL()
			)
		);
	}
	public function fetchGrid( Grid $grid ){
		return( $this->_memcache->get( $this->makeGridKey( $grid ) ) );
	}
	public function hasGrid( Grid $grid ){
		return( (bool)$this->fetchGrid( $grid ) );
	}
	public function purgeGrid( Grid $grid ){
		return( $this->_memcache->delete( $this->makeGridKey( $grid ) ) );
	}

	private function Torpor(){ return( $this->_torpor ); }
	private function makeGridKey( Grid $grid ){
		// 1. Get all keys for the grid
		// 2. Concatenate them together in order
		//    2.1 All values are prefixed w/-, separated by _
		//    2.2 Genuine nulls are NOT prefixed w/-
		$cacheKey = array();
		$keys = $this->Torpor()->primaryKeyForGrid( $grid );
		if( $keys ){
			if( !is_array( $keys ) ){
				$keys = array( $keys );
			}
		} else {
			$keys = $this->Torpor()->allKeysForGrid( $grid );
		}
		if( $keys ){
			foreach( $keys as $key ){
				$cacheKey[] = ( $grid->Column( $key )->hasData() ? $grid->Column( $key )->getData() : null );
			}
		} else {
			$this->Torpor()->throwException( 'Insufficient keys to cache '.$this->Torpor()->containerKeyName( $grid ).' grid' );
		}
		return( $this->Torpor()->containerKeyName( $grid ).'_'.serialize( $cacheKey ) );
	}

	// Settings and utilities
	public function getTTL(){ return( $this->_ttl ); }
	public function setTTL( $ttl ){ return( $this->_ttl = (int)$ttl ); }

	public function getCompression(){ return( $this->_compression ); }
	public function setCompression( $bool = true ){ return( $this->_compression = ( $bool ? true : false ) ); }

	public function getCompressionThreshold(){ return( $this->_compression_threshold ); }
	public function setCompressionThreshold( $threshold ){
		if( $this->_memcache instanceof Memcache ){
			$this->_memcache->setCompressThreshold( (int)$threshold );
		}
		return( $this->_compression_threshold = (int)$threshold ); }
}
?>
