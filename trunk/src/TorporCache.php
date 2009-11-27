<?PHP
// $Rev$
interface TorporCache {
	public static function createInstance( Torpor $torpor );
	public function initialize( array $settings );
	public function writeGrid( Grid $grid );
	public function fetchGrid( Grid $grid );
	public function hasGrid( Grid $grid );
	public function purgeGrid( Grid $grid );
}

class ThreadCache implements TorporCache {
	private static $_instance;
	private $_torpor;
	private $_cache = array();

	private function __construct( Torpor $torpor ){
		$this->_torpor = $torpor;
	}

	public static function createInstance( Torpor $torpor ){
		if( !( self::$_instance instanceof ThreadCache ) ){
			self::$_instance = new ThreadCache( $torpor );
		}
		return( self::$_instance );
	}

	public function initialize( array $settings ){}

	public function writeGrid( Grid $grid ){
		if( !$grid->isLoaded() ){
			$this->Torpor()->throwException( $this->Torpor()->containerKeyName( $grid ).' grid not loaded (can only cache loaded grids)' );
		}
		if( !isset( $this->_cache{ $this->Torpor()->containerKeyName( $grid ) } ) ){
			$this->_cache{ $this->Torpor()->containerKeyName( $grid ) } = array();
		}
		$this->_cache{ $this->Torpor()->containerKeyName( $grid ) }{ $this->makeGridKey( $grid ) } = $grid->dumpArray( true );
	}
	public function fetchGrid( Grid $grid ){
		$return = null;
		if( $this->hasGrid( $grid ) ){
			$return = $this->_cache{ $this->Torpor()->containerKeyName( $grid ) }{ $this->makeGridKey( $grid ) };
		}
		return( $return );
	}
	public function hasGrid( Grid $grid ){
		return(
			isset( $this->_cache{ $this->Torpor()->containerKeyName( $grid ) } )
			&& isset( $this->_cache{ $this->Torpor()->containerKeyName( $grid ) }{ $this->makeGridKey( $grid ) } )
		);
	}
	public function purgeGrid( Grid $grid ){
		$key = $this->makeGridKey( $grid );
		if( $this->hasGrid( $grid ) ){
			unset( $this->_cache{ $this->Torpor()->containerKeyName( $grid ) }{ $key } );
		}
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
		return( serialize( $cacheKey ) );
	}
}

class SessionCache extends ThreadCache {
	public function initialize( array $settings ){
		if( !session_id() ){
			if( headers_sent() ){
				$this->Torpor()->throwExecption( 'Cannot start session for cache management' );
			}
			if( !session_start() ){
				$this->Torpor()->throwException( 'Session registration failed, cannot continue' );
			}
		}
	}
	public function writeGrid( Grid $grid ){
		if( !$grid->isLoaded() ){
			$this->Torpor()->throwException( $this->Torpor()->containerKeyName( $grid ).' grid not loaded (can only cache loaded grids)' );
		}
		if( !isset( $_SESSION{ $this->Torpor()->containerKeyName( $grid ) } ) ){
			$_SESSION{ $this->Torpor()->containerKeyName( $grid ) } = array();
		}
		$_SESSION{ $this->Torpor()->containerKeyName( $grid ) }{ $this->makeGridKey( $grid ) } = $grid->dumpArray( true );
	}
	public function fetchGrid( Grid $grid ){
		$return = null;
		if( $this->hasGrid( $grid ) ){
			$return = $_SESSION{ $this->Torpor()->containerKeyName( $grid ) }{ $this->makeGridKey( $grid ) };
		}
		return( $return );
	}
	public function hasGrid( Grid $grid ){
		return(
			isset( $_SESSION{ $this->Torpor()->containerKeyName( $grid ) } )
			&& isset( $_SESSION{ $this->Torpor()->containerKeyName( $grid ) }{ $this->makeGridKey( $grid ) } )
		);
	}
	public function purgeGrid( Grid $grid ){
		$key = $this->makeGridKey( $grid );
		if( $this->hasGrid( $grid ) ){
			unset( $_SESSION{ $this->Torpor()->containerKeyName( $grid ) }{ $key } );
		}
	}
}
?>
