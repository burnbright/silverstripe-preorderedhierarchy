<?php
/**
 * 
 * Introduces pre-ordering to heirartical dataobjects for fast retrieval of large chunks of the tree.
 * 
 * @author Jeremy Shipman <jeremy [at] burnbright.co.nz>
 * 
 */


class PreorderedHierarchy extends Hierarchy{
	
	protected static $creating_order = null; 
	
	function extraStatics($class = null){
		return array(
			'db' => array(
				'LHS' => 'Int',
				'RHS' => 'Int'
			),
			'indexes' =>array(
				//'LHS', 'RHS' //not working during dev/build (perhaps just for innodb tables??)
			),
			'has_one' => array(
				// TODO this method is called *both* statically and on an instance
				"Parent" => ($class) ? $class : $this->owner->class
			)
		);
	}
	
	/**
	 * Get an entire branch of the tree in one query.
	 */
	public function ChildBranch($showAll = false,$filter = null,$sort="",$join = "",$limit = ""){
		if($this->owner->db('ShowInMenus')) {
			$extraFilter = ($showAll) ? '' : " AND \"ShowInMenus\"=1";
		} else {
			$extraFilter = '';
		}
		
		$baseClass = ClassInfo::baseDataClass($this->owner->class);
		
		$filter = ($filter) ? " AND $filter" : "";
		
		$staged = DataObject::get($baseClass, "\"{$baseClass}\".\"LHS\" > ".(int)$this->owner->LHS." AND \"{$baseClass}\".\"RHS\" < ".(int)$this->owner->RHS 
			." AND \"{$baseClass}\".\"ID\" != " . (int)$this->owner->ID
			.$filter. $extraFilter, $sort,$join,$limit);
			
		if(!$staged) $staged = new DataObjectSet();
		$this->owner->extend("augmentStageChildren", $staged, $showAll);
		return $staged;
		
	}
	
	function requireDefaultRecords(){
		//only need to pre-order if pre-order doesn't exist yet
		//TODO: allow forcing a re-preording
		if(DataObject::get($this->owner->class,"\"RHS\" = 0 OR \"RHS\" IS NULL OR \"LHS\" = 0 OR \"LHS\" IS NULL")){ 
			$this->create_preorderings();
			//TODO: output the number of objects updated
		}
	}
	
	
	function create_preorderings(){
		$rootnodes = DataObject::get($this->owner->class,"\"ParentID\" = 0 OR \"ParentID\" IS NULL");
		
		self::$creating_order = true;
		
		$count = 1; //prevents the same orderings beigng used on different trees
		if($rootnodes){
			foreach($rootnodes as $node){
				$count = $node->rebuildTree($count);
			}
		}
		self::$creating_order = false;
	}
	
	/**
	 * Recursively sets LHS and RHS values for each node in the tree.
	 */
	function rebuildTree($left = 1){
		
		$this->owner->LHS = $left;
		$right = $left + 1;
		
		$children = $this->owner->AllChildren();
		foreach($children as $child){
			$right = $child->rebuildTree($right);
		}		
		
		$this->owner->RHS = $right;
		$this->owner->write(); //TODO: this needs to write to live site also
		
		return $right + 1;
	}
	
	/**
	 * Update the tree
	 * 
	 */
	function onBeforeWrite(){
		
		//TODO: make sure this doesn't run during create_preorderings()
		
		//New node in the tree
		if(!$this->owner->ID && !self::$creating_order){
			$parentrhs = $this->owner->Parent()->RHS;
			
			
			$baseClass = ClassInfo::baseDataClass($this->owner->class);
			// update left and right values for all nodes
			DB::query("UPDATE \"{$baseClass}\" SET \"RHS\" = \"RHS\" + 2 WHERE \"RHS\" >= $parentrhs");
			DB::query("UPDATE \"{$baseClass}\" SET \"LHS\" = \"LHS\" + 2 WHERE \"LHS\" >= $parentrhs");
			//TODO: update other stages also??
			//I think it might be best to update both the stage and live site
			
			//or perhaps the orderings can be different for live/stage
			
			
			//set left and right values for this node
			$this->owner->LHS = $parentrhs;
			$this->owner->RHS = $parentrhs + 1;
		}
		
		
		
	}
	
	function onAfterWrite(){
		if($this->owner->isChanged('ParentID')){
			$this->create_preorderings();
			
			//make gap for incoming nodes by incrementing right, and everyting greater by the total number of incoming nodes?
			//update moved values
			//$this->rebuildTree($this->owner->LHS);
		}
		
	}
	
}


?>