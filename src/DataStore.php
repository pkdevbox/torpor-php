<?PHP
// $Rev$
interface DataStore {
	public function initialize( array $settings );
	public function Publish( Grid $grid, $force = false );
	public function Delete( Grid $grid );
	public function Load( Grid $grid, $refresh = false );
	public function LoadFromCriteria( Grid $grid, Criteria $criteria, $refresh = false );
	// Sets have their own criteria.
	public function LoadSet( GridSet $gridSet, $refresh = false );
	public function Execute( PersistenceCommand $command, $returnAs = null /*, $grid1, ... */ );
}
?>
