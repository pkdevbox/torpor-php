<?PHP
// $Rev$
interface DataStore {
	public function initialize( Torpor $torpor, array $settings );
	public function Publish( Grid $grid, $force = false );
	public function Delete( Grid $grid );
	public function Load( Grid $grid, $refresh = false );
	public function LoadFromCriteria( Grid $grid, Criteria $criteria, $refresh = false );
	// Sets have their own criteria.
	public function LoadSet( GridSet $gridSet, $refresh = false );
}
?>
