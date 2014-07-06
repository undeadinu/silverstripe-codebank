<?php
class SnippetHierarchy extends DataExtension {
    protected $markedNodes;
    
    protected $markingFilter;
    
    /**
     * @var Int
     */
    protected $_cache_numChildren;
    
    /**
     * True if this DataObject is marked.
     * @var boolean
     */
    protected static $marked=array();
    
    /**
     * True if this DataObject is expanded.
     * @var boolean
     */
    protected static $expanded=array();
    
    /**
     * True if this DataObject is opened.
     * @var boolean
     */
    protected static $treeOpened=array();
    
    
    /**
     * Validate the owner object - check for existence of infinite loops.
     */
    public function validate(ValidationResult $validationResult) {
        if (!$this->owner->ID) return; // The object is new, won't be looping.
        if (!$this->owner->LanguageID) return; // The object has no parent, won't be looping.
        if (!$this->owner->isChanged('LanguageID')) return; // The parent has not changed, skip the check for performance reasons.

        // Walk the hierarchy upwards until we reach the top, or until we reach the originating node again.
        $node=$this->owner;
        while($node) {
            if ($node->LanguageID==$this->owner->ID) {
                // Hierarchy is looping.
                $validationResult->error(
                    _t(
                        'Hierarchy.InfiniteLoopNotAllowed',
                        'Infinite loop found within the "{type}" hierarchy. Please change the parent to resolve this',
                        'First argument is the class that makes up the hierarchy.',
                        array('type' => $this->owner->class)
                    ),
                    'INFINITE_LOOP'
                );
                break;
            }
            $node=$node->LanguageID ? $node->Language() : null;
        }

        // At this point the $validationResult contains the response.
    }
    
    /**
     * Return an array of this page and its ancestors, ordered item -> root.
     * @return array
     */
    public function parentStack() {
        $p=$this->owner;
        
        while($p) {
            $stack[]=$p;
            if($p->FolderID && $p->FolderID>0) {
                $folder=$p->Folder();
                if(!empty($folder) && $folder!==false && $folder->ID!=0) {
                    $p=$folder;
                }
            }else {
                $p=$p->LanguageID ? $p->Language() : null;
            }
        }
        
        return $stack;
    }
    
    /**
     * Get the parent of this class.
     * @return DataObject
     */
    public function getParent($filter='') {
        return $this->owner->Language();
    }
    
    /**
     * Return all the parents of this class in a set ordered from the lowest to highest parent.
     *
     * @return SS_List
     */
    public function getAncestors() {
        $ancestors=new ArrayList();
        $object   =$this->owner;
        
        while($object=$object->Language()) {
            $ancestors->push($object);
        }
        
        return $ancestors;
    }
    
    /**
     * Get the next node in the tree of the type. If there is no instance of the className descended from this node,
     * then search the parents.
     * @param string $className Class name of the node to find.
     * @param string|int $root ID/ClassName of the node to limit the search to
     * @param DataObject afterNode Used for recursive calls to this function
     * @return DataObject
     */
    public function naturalNext( $className=null, $root=0, $afterNode=null ) {
        // If this node is not the node we are searching from, then we can possibly return this
        // node as a solution
        if($afterNode && $afterNode->ID != $this->owner->ID) {
            if(!$className || ($className && $this->owner->class == $className)) {
                return $this->owner;
            }
        }
            
        $nextNode=null;
        $baseClass=ClassInfo::baseDataClass($this->owner->class);
        
        $children=DataObject::get(ClassInfo::baseDataClass($this->owner->class), "\"$baseClass\".\"LanguageID\"={$this->owner->ID}" . ( ( $afterNode ) ? " AND \"Sort\" > " . sprintf( '%d', $afterNode->Sort ) : "" ), '"Sort" ASC');
        
        // Try all the siblings of this node after the given node
        /*if( $siblings=DataObject::get( ClassInfo::baseDataClass($this->owner->class), "\"LanguageID\"={$this->owner->LanguageID}" . ( $afterNode ) ? "\"Sort\" > {$afterNode->Sort}" : "" , '\"Sort\" ASC' ) )
            $searchNodes->merge( $siblings );*/
        
        if($children) {
            foreach($children as $node) {
                if($nextNode=$node->naturalNext($className, $node->ID, $this->owner)) {
                    break;
                }
            }
            
            if($nextNode) {
                return $nextNode;
            }
        }
        
        // if this is not an instance of the root class or has the root id, search the parent
        if(!(is_numeric($root) && $root == $this->owner->ID || $root == $this->owner->class) && ($parent=$this->owner->Language())) {
            return $parent->naturalNext( $className, $root, $this->owner );
        }
        
        return null;
    }
    
    /**
     * Mark a segment of the tree, by calling mark().
     * The method performs a breadth-first traversal until the number of nodes is more than minCount.
     * This is used to get a limited number of tree nodes to show in the CMS initially.
     *
     * This method returns the number of nodes marked.  After this method is called other methods
     * can check isExpanded() and isMarked() on individual nodes.
     *
     * @param int $minNodeCount The minimum amount of nodes to mark.
     * @return int The actual number of nodes marked.
     */
    public function markPartialTree($minNodeCount=30, $context=null, $childrenMethod="AllChildrenIncludingDeleted", $numChildrenMethod="numChildren") {
        if(!is_numeric($minNodeCount)) $minNodeCount=30;

        $this->markedNodes=array($this->owner->ClassName.'_'.$this->owner->ID=>$this->owner);
        $this->owner->markUnexpanded();

        // foreach can't handle an ever-growing $nodes list
        while(list($id, $node)=each($this->markedNodes)) {
            $this->markChildren($node, $context, $childrenMethod, $numChildrenMethod);
            if($minNodeCount && sizeof($this->markedNodes)>=$minNodeCount) {
                break;
            }
        }
        
        return sizeof($this->markedNodes);
    }
    
    /**
     * Mark all children of the given node that match the marking filter.
     * @param DataObject $node Parent node.
     */
    public function markChildren($node, $context=null, $childrenMethod='AllChildrenIncludingDeleted', $numChildrenMethod='numChildren') {
        if($node->hasMethod($childrenMethod)) {
            $children=$node->$childrenMethod($context);
        }else {
            user_error(sprintf("Can't find the method '%s' on class '%s' for getting tree children", $childrenMethod, get_class($node)), E_USER_ERROR);
        }
        
        $node->markExpanded();
        if($children) {
            foreach($children as $child) {
                if(!$this->markingFilter || $this->markingFilterMatches($child)) {
                    if($child->$numChildrenMethod()) {
                        $child->markUnexpanded();
                    }else {
                        $child->markExpanded();
                    }
                    
                    $this->markedNodes[$child->ClassName.'_'.$child->ID]=$child;
                }
            }
        }
    }
    
    /**
     * Mark the children of the DataObject with the given ID.
     * @param int $id ID of parent node.
     * @param boolean $open If this is true, mark the parent node as opened.
     */
    public function markById($id, $open=false, $className=null) {
        if(isset($this->markedNodes[$className.'_'.$id])) {
            $this->markChildren($this->markedNodes[$className.'_'.$id]);
            if($open) {
                $this->markedNodes[$className.'_'.$id]->markOpened();
            }
            
            return true;
        } else {
            return false;
        }
    }
    
    /**
     * Expose the given object in the tree, by marking this page and all it ancestors.
     * @param DataObject $childObj
     */
    public function markToExpose($childObj) {
        if(is_object($childObj)){
            $stack=array_reverse($childObj->parentStack());
            foreach($stack as $stackItem) {
                $this->markById($stackItem->ID, true, $stackItem->ClassName);
            }
        }
    }
    
    /**
     * Return the number of direct children.
     * By default, values are cached after the first invocation.
     * Can be augumented by {@link augmentNumChildrenCountQuery()}.
     *
     * @param Boolean $cache
     * @return int
     */
    public function numChildren($cache=true) {
        // Build the cache for this class if it doesn't exist.
        if(!$cache || !is_numeric($this->_cache_numChildren)) {
            if($this->owner instanceof SnippetLanguage) {
                $this->_cache_numChildren=(int)$this->owner->Snippets()->filter('FolderID', 0)->Count() + (int)$this->owner->Folders()->Count();
            }else if($this->owner instanceof SnippetFolder) {
                $this->_cache_numChildren=(int)$this->owner->Snippets()->Count() + (int)$this->owner->Folders()->Count();
            }else {
                $this->_cache_numChildren=0;
            }
        }

        // If theres no value in the cache, it just means that it doesn't have any children.
        return $this->_cache_numChildren;
    }
    
    /**
     * Returns the children of this DataObject as an XHTML UL. This will be called recursively on each child,
     * so if they have children they will be displayed as a UL inside a LI.
     * @param string $attributes Attributes to add to the UL.
     * @param string|callable $titleEval PHP code to evaluate to start each child - this should include '<li>'
     * @param string $extraArg Extra arguments that will be passed on to children, for if they overload this function.
     * @param boolean $limitToMarked Display only marked children.
     * @param string $childrenMethod The name of the method used to get children from each object
     * @param boolean $rootCall Set to true for this first call, and then to false for calls inside the recursion. You should not change this.
     * @param int $minNodeCount
     * @return string
     */
    public function getChildrenAsUL($attributes="", $titleEval='"<li>" . $child->Title', $extraArg=null, $limitToMarked=false, $childrenMethod="AllChildrenIncludingDeleted", $numChildrenMethod="numChildren", $rootCall=true, $minNodeCount=30) {
        if($limitToMarked && $rootCall) {
            $this->markingFinished($numChildrenMethod);
        }
    
        if($this->owner->hasMethod($childrenMethod)) {
            $children=$this->owner->$childrenMethod($extraArg);
        } else {
            user_error(sprintf("Can't find the method '%s' on class '%s' for getting tree children",
                    $childrenMethod, get_class($this->owner)), E_USER_ERROR);
        }
    
        if($children) {
            if($attributes) {
                $attributes=" $attributes";
            }
                
            $output="<ul$attributes>\n";
    
            foreach($children as $child) {
                if(!$limitToMarked || $child->isMarked()) {
                    $foundAChild=true;
                    $output .= (is_callable($titleEval)) ? $titleEval($child) : eval("return $titleEval;");
                    $output .= "\n" .
                            $child->getChildrenAsUL("", $titleEval, $extraArg, $limitToMarked, $childrenMethod, $numChildrenMethod, false, $minNodeCount) . "</li>\n";
                }
            }
                
            $output .= "</ul>\n";
        }
    
        if(isset($foundAChild) && $foundAChild) {
            return $output;
        }
    }
    
    /**
     * Filter the marking to only those object with $node->$parameterName=$parameterValue
     * @param string $parameterName The parameter on each node to check when marking.
     * @param mixed $parameterValue The value the parameter must be to be marked.
     */
    public function setMarkingFilter($parameterName, $parameterValue) {
        $this->markingFilter=array(
                "parameter" => $parameterName,
                "value" => $parameterValue
        );
    }
    
    /**
     * Filter the marking to only those where the function returns true.
     * The node in question will be passed to the function.
     * @param string $funcName The function name.
     */
    public function setMarkingFilterFunction($funcName) {
        $this->markingFilter=array(
                "func" => $funcName,
        );
    }
    
    /**
     * Returns true if the marking filter matches on the given node.
     * @param DataObject $node Node to check.
     * @return boolean
     */
    public function markingFilterMatches($node) {
        if(!$this->markingFilter) {
            return true;
        }
    
        if(isset($this->markingFilter['parameter']) && $parameterName=$this->markingFilter['parameter']) {
            if(is_array($this->markingFilter['value'])){
                $ret=false;
                foreach($this->markingFilter['value'] as $value) {
                    $ret=$ret||$node->$parameterName==$value;
                    if($ret == true) {
                        break;
                    }
                }
                return $ret;
            } else {
                return ($node->$parameterName == $this->markingFilter['value']);
            }
        } else if ($func=$this->markingFilter['func']) {
            return call_user_func($func, $node);
        }
    }
    
    /**
     * Ensure marked nodes that have children are also marked expanded.
     * Call this after marking but before iterating over the tree.
     */
    protected function markingFinished($numChildrenMethod="numChildren") {
        // Mark childless nodes as expanded.
        if($this->markedNodes) {
            foreach($this->markedNodes as $id => $node) {
                if(!$node->isExpanded() && !$node->$numChildrenMethod()) {
                    $node->markExpanded();
                }
            }
        }
    }
    
    /**
     * Return CSS classes of 'unexpanded', 'closed', both, or neither, depending on
     * the marking of this DataObject.
     */
    public function markingClasses() {
        $classes='';
        if(!$this->isExpanded()) {
            $classes .= " unexpanded jstree-closed";
        }
        if($this->isTreeOpened()) {
            if($this->numChildren() > 0) $classes .= " jstree-open";
        } else {
            $classes .= " closed";
        }
        return $classes;
    }
    
    /**
     * Return the IDs of all the marked nodes
     */
    public function markedNodeIDs() {
        return array_keys($this->markedNodes);
    }
    
    /**
     * Mark this DataObject as expanded.
     */
    public function markExpanded() {
        self::$marked[ClassInfo::baseDataClass($this->owner->class)][$this->owner->ID]=true;
        self::$expanded[ClassInfo::baseDataClass($this->owner->class)][$this->owner->ID]=true;
    }
    
    /**
     * Mark this DataObject as unexpanded.
     */
    public function markUnexpanded() {
        self::$marked[ClassInfo::baseDataClass($this->owner->class)][$this->owner->ID]=true;
        self::$expanded[ClassInfo::baseDataClass($this->owner->class)][$this->owner->ID]=false;
    }
    
    /**
     * Mark this DataObject's tree as opened.
     */
    public function markOpened() {
        self::$marked[ClassInfo::baseDataClass($this->owner->class)][$this->owner->ID]=true;
        self::$treeOpened[ClassInfo::baseDataClass($this->owner->class)][$this->owner->ID]=true;
    }
    
    /**
     * Check if this DataObject is marked.
     * @return boolean
     */
    public function isMarked() {
        $baseClass=ClassInfo::baseDataClass($this->owner->class);
        $id=$this->owner->ID;
        return isset(self::$marked[$baseClass][$id]) ? self::$marked[$baseClass][$id] : false;
    }
    
    /**
     * Check if this DataObject is expanded.
     * @return boolean
     */
    public function isExpanded() {
        $baseClass=ClassInfo::baseDataClass($this->owner->class);
        $id=$this->owner->ID;
        return isset(self::$expanded[$baseClass][$id]) ? self::$expanded[$baseClass][$id] : false;
    }
    
    /**
     * Check if this DataObject's tree is opened.
     */
    public function isTreeOpened() {
        $baseClass=ClassInfo::baseDataClass($this->owner->class);
        $id=$this->owner->ID;
        return isset(self::$treeOpened[$baseClass][$id]) ? self::$treeOpened[$baseClass][$id] : false;
    }
    
    /**
     * Return a partial tree as an HTML UL.
     */
    public function partialTreeAsUL($minCount=50) {
        $children=$this->owner->AllChildren();
        if($children) {
            if($attributes) $attributes=" $attributes";
            $output="<ul$attributes>\n";
    
            foreach($children as $child) {
                $output .= eval("return $titleEval;") . "\n" .
                        $child->getChildrenAsUL("", $titleEval, $extraArg) . "</li>\n";
            }
            $output .= "</ul>\n";
        }
        return $output;
    }
    
    /**
     * Get a list of this DataObject's and all it's descendants IDs.
     * @return int
     */
    public function getDescendantIDList() {
        $idList=array();
        $this->loadDescendantIDListInto($idList);
        return $idList;
    }
    
    /**
     * Get a list of this DataObject's and all it's descendants ID, and put it in $idList.
     * @var array $idList Array to put results in.
     */
    public function loadDescendantIDListInto(&$idList) {
        if($children=$this->AllChildren()) {
            foreach($children as $child) {
                if(in_array($child->ID, $idList)) {
                    continue;
                }
                $idList[]=$child->ID;
                $ext=$child->getExtensionInstance('Hierarchy');
                $ext->setOwner($child);
                $ext->loadDescendantIDListInto($idList);
                $ext->clearOwner();
            }
        }
    }
    
    /**
     * Get the children for this DataObject.
     * @return SS_List
     */
    public function Children() {
        if(!(isset($this->_cache_children) && $this->_cache_children)) {
            $result=$this->owner->stageChildren(false);
            if(isset($result)) {
                $this->_cache_children=new ArrayList();
                foreach($result as $child) {
                    if($child->canView()) {
                        $this->_cache_children->push($child);
                    }
                }
            }
        }
        return $this->_cache_children;
    }
    
    /**
     * Return all children, including those 'not in menus'.
     * @return SS_List
     */
    public function AllChildren() {
        return $this->owner->stageChildren(true);
    }
    
    /**
     * Return all children, including those that have been deleted but are still in live.
     * Deleted children will be marked as "DeletedFromStage"
     * Added children will be marked as "AddedToStage"
     * Modified children will be marked as "ModifiedOnStage"
     * Everything else has "SameOnStage" set, as an indicator that this information has been looked up.
     * @return SS_List
     */
    public function AllChildrenIncludingDeleted($context=null) {
        return $this->doAllChildrenIncludingDeleted($context);
    }
    
    /**
     * @see AllChildrenIncludingDeleted
     *
     * @param unknown_type $context
     * @return SS_List
     */
    public function doAllChildrenIncludingDeleted($context=null) {
        if(!$this->owner) user_error('Hierarchy::doAllChildrenIncludingDeleted() called without $this->owner');
    
        $idxStageChildren=array();
        $idxLiveChildren=array();
    
        $baseClass=ClassInfo::baseDataClass($this->owner->class);
        if($baseClass) {
            $stageChildren=$this->owner->stageChildren(true);
    
            $this->owner->extend("augmentAllChildrenIncludingDeleted", $stageChildren, $context);
                
        }else {
            user_error("SnippetHierarchy::AllChildren() Couldn't determine base class for '{$this->owner->class}'", E_USER_ERROR);
        }
    
        return $stageChildren;
    }
    
    /**
     * Return children from the stage site
     *
     * @param showAll Inlcude all of the elements, even those not shown in the menus.
     *   (only applicable when extension is applied to {@link SiteTree}).
     * @return SS_List
     */
    public function stageChildren($showAll=false) {
        $baseClass=ClassInfo::baseDataClass($this->owner->class);
        
        if($baseClass=='SnippetPackage') {
            if($this->owner->ID==0) {
                $staged=SnippetPackage::get();
            }
        }else if($baseClass=='SnippetLanguage') {
            if($this->owner->ID==0) {
                $staged=SnippetLanguage::get();
            }else {
                $staged=ArrayList::create(array_merge($this->owner->Folders()->toArray(), $this->owner->Snippets()->filter('FolderID', 0)->toArray()));
            }
        }else if($baseClass=='SnippetFolder') {
            $staged=ArrayList::create(array_merge($this->owner->Folders()->toArray(), $this->owner->Snippets()->toArray()));
        }else {
            $staged=new ArrayList();
        }
        
            
        $this->owner->extend("augmentStageChildren", $staged, $showAll);
        return $staged;
    }
    
    /**
     * Returns a human-readable, flattened representation of the path to the object,
     * using its {@link Title()} attribute.
     *
     * @param String
     * @return String
     */
    public function getBreadcrumbs($separator=' &raquo; ') {
        $crumbs=array();
        $ancestors=array_reverse($this->owner->getAncestors()->toArray());
        foreach($ancestors as $ancestor) $crumbs[]=$ancestor->Title;
        $crumbs[]=$this->owner->Title;
        return implode($separator, $crumbs);
    }
    
    /**
     * Get the next node in the tree of the type. If there is no instance of the className descended from this node,
     * then search the parents.
     *
     * @todo Write!
     */
    public function naturalPrev( $className, $afterNode=null ) {
        return null;
    }
    
    function flushCache() {
        $this->_cache_children=null;
        $this->_cache_numChildren=null;
        self::$marked=array();
        self::$expanded=array();
        self::$treeOpened=array();
    }
    
    public static function reset() {
        self::$marked=array();
        self::$expanded=array();
        self::$treeOpened=array();
    }
}
?>