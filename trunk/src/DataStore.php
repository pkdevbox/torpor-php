<?PHP
// $Rev$
interface DataStore {
	public static function createInstance( Torpor $torpor );
	public function initialize( $writeEnabled, array $settings );
	public function Delete( Grid $grid );
	public function Execute( PersistenceCommand $command, $returnAs = null /*, $grid1, ... */ );
	public function Load( Grid $grid, $refresh = false );
	public function LoadFromCriteria( Grid $grid, Criteria $criteria, $refresh = false );
	public function LoadSet( GridSet $gridSet, $refresh = false );
	public function Publish( Grid $grid, $force = false );
}
?>
