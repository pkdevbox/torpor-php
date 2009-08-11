<?PHP
// $Rev$
class PersistenceCommand {
	const TYPE_LOAD    = 'LOAD';
	const TYPE_PUBLISH = 'PUBLISH';
	const CONTEXT_NEW      = 'NEW';
	const CONTEXT_EXISTING = 'EXISTING';
	const CONTEXT_ALL      = 'ALL';

	private $_type = null;
	private $_context = null;
	private $_command = null;
	private $_commandType = null;
	private $_placeholder = '?';
	private $_parameters = array();

	public function PersistenceCommand(
		$type = null,
		$context = null,
		$command = null,
		$placeholder = null,
		$commandType = null
	){
		if( !empty( $type ) ){ $this->setType( $type ); }
		if( !empty( $context ) ){ $this->setContext( $context ); }
		if( !empty( $command ) ){ $this->setCommand( $command ); }
		if( !empty( $placeholder ) ){ $this->setPlaceholder( $placeholder ); }
		if( !empty( $commandType ) ){ $this->setCommandType( $commandType ); }
	}

	public function getType(){ return( $this->_type ); }
	public function setType( $type, $context = null ){
		$return = false;
		switch( $type ){
			case self::TYPE_LOAD:
				$this->setContext( null );
			case self::TYPE_PUBLISH:
				$return = $this->_type = $type;
				break;
			default:
				throw( new TorporException( 'Unrecognized type "'.$type.'"' ) );
				break;
		}
		return( $return );
	}

	public function getContext(){ return( $this->_context ); }
	public function setContext( $context ){
		$return = false;
		if( $this->getType() != self::TYPE_PUBLISH && !empty( $context ) ){
			trigger_error( 'Context inapplicable for non '.self::TYPE_PUBLISH.' command type, ignoring setContext()', E_USER_WARNING );
		} else {
			if( empty( $context ) ){
				if (
					is_null( $this->getType() )
					|| $this->getType() != self::TYPE_PUBLISH
				){
					$this->_type = null;
					$return = true;
				} else {
					throw( new TorporException( 'Context must be non-empty for '.self::TYPE_PUBLISH.' command type' ) );
				}
			} else {
				switch( $context ){
					case self::CONTEXT_NEW:
					case self::CONTEXT_EXISTING:
					case self::CONTEXT_BOTH:
						$return = $this->_context = $context;
						break;
					default:
						throw( new TorporException( 'Unrecognized context "'.$context.'"' ) );
						break;
				}
			}
		}
		return( $return );
	}

	public function getCommand(){ return( $this->_command ); }
	public function setCommand( $command ){ return( $this->_command = $command ); }

	public function getPlaceholder(){ return( $this->_placeholder ); }
	public function setPlaceholder( $placeholder ){ return( $this->_placeholder = $placeholder ); }

	public function getCommandType(){ return( $this->_commandType ); }
	public function setCommandType( $commandType ){ return( $this->_commandType = $commandType ); }

	public function getParameters(){ return( $this->_parameters ); }
	public function addParameter( $parameterName, $placeholder = null ){
		$paramterName = Torpor::makeKeyName( $parameterName );
		if( empty( $parameterName ) ){ throw( new TorporException( 'Invalid parameterName' ) ); }
		$this->_parameters[] = $parameterName.( !empty( $placeholder ) ? Torpor::VALUE_SEPARATOR.$placeholder : '' );
		return( count( $this->_parameters ) );
	}
}
?>
