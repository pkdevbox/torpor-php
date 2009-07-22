<?PHP
// $Rev$
// TODO: phpdoc
class PersistableContainer
{
	protected $_name;
	protected $_isLoaded = false;
	protected $_isDirty = false;
	protected $_torpor;

	public function PersistableContainer(
		Torpor $torpor,
		$name = null
	){
		$this->_setTorpor( $torpor );
		if( !is_null( $name ) ){ $this->_setObjName( $name ); }
	}

	public function _getObjName(){ return( $this->_name ); }
	public function _setObjName( $name ){ return( $this->_name = Torpor::makeKeyName( $name ) ); }

	public function isLoaded(){ return( $this->_isLoaded ); }
	protected function _setLoaded( $bool = true ){ return( $this->_isLoaded = ( $bool ? true : false ) ); }

	public function isDirty(){ return( $this->_isDirty ); }
	protected function _setDirty( $bool = true ){ return( $this->_isDirty = ( $bool ? true : false ) ); }

	public function Torpor(){ return( $this->_getTorpor() ); }
	public function _getTorpor(){ return( $this->_torpor ); }
	public function _setTorpor( Torpor $torpor ){ return( $this->_torpor = $torpor ); }
}
?>
