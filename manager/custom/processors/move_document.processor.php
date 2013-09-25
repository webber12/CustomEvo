<?php
if(IN_MANAGER_MODE!="true") die("<b>INCLUDE_ORDERING_ERROR</b><br /><br />Please use the MODx Content Manager instead of accessing this file directly.");
if(!$modx->hasPermission('edit_document')) {
	$e->setError(3);
	$e->dumpError();
}

if(!function_exists("makeNewUri")){
    function makeNewUri($idSQL,$oldParentId){
        if($idSQL>0){
            global $modx;
            $table = $modx->getFullTableName("site_content");
            $q = $modx->db->query("SELECT parent FROM ".$table." WHERE id={$idSQL}");
            if($modx->db->getRecordCount($q)==1){
                $q = $modx->db->getRow($q);
                if($q['parent']&&$q['parent']!=0){
                    $modx->db->update(array('uri'=>$modx->makeUrl($q['parent'])),$table,'id='.$q['parent']);
                }
                $modx->db->update(array('uri'=>$modx->makeUrl($idSQL)),$table,'id='.$idSQL);
				$modx->db->update(array('uri'=>$modx->makeUrl($oldParentId)),$table,'id='.$oldParentId);
            }
        }
    }
}

if(!function_exists("resetOldUri")){
    function resetOldUri($idSQL,$newParent){
        global $modx;
        $table = $modx->getFullTableName("site_content");
        $modx->db->update(array('uri'=>''),$table,'id ='.$idSQL);
        if($newParent!=0){
            $modx->db->update(array('uri'=>''),$table,'id ='.$newParent);
        }
    }
}

if(!function_exists("resetOldParentUri")){
    function resetOldParentUri($idSQL){
        global $modx;
        $table = $modx->getFullTableName("site_content");
        $modx->db->update(array('uri'=>''),$table,'id ='.$idSQL);
    }
}

if(!function_exists("replaceChildsUri")){
    function replaceChildsUri($idSQL,$oldUri,$newUri){
        global $modx;
        $table = $modx->getFullTableName("site_content");
		$query="UPDATE $table SET `uri`= REPLACE(`uri`, '".$oldUri."', '".$newUri."') WHERE `uri` LIKE '".$oldUri."%'";
        $q=$modx->db->query($query);
    }
}

// ok, two things to check.
// first, document cannot be moved to itself
// second, new parent must be a folder. If not, set it to folder.
if($_REQUEST['id']==$_REQUEST['new_parent']) {
		$e->setError(600);
		$e->dumpError();
}
if($_REQUEST['id']=="") {
		$e->setError(601);
		$e->dumpError();
}
if($_REQUEST['new_parent']=="") {
		$e->setError(602);
		$e->dumpError();
}

$sql = "SELECT parent FROM $dbase.`".$table_prefix."site_content` WHERE id=".$_REQUEST['id'].";";
$rs = $modx->db->query($sql);
if(!$rs){
	echo "An error occured while attempting to find the document's current parent.";
}

$row = $modx->db->getRow($rs);
$oldparent = $row['parent'];
$newParentID = $_REQUEST['new_parent'];

// check user has permission to move document to chosen location

if ($use_udperms == 1) {
if ($oldparent != $newParentID) {
		include_once MODX_MANAGER_PATH . "processors/user_documents_permissions.class.php";
		$udperms = new udperms();
		$udperms->user = $modx->getLoginUserID();
		$udperms->document = $newParentID;
		$udperms->role = $_SESSION['mgrRole'];

		 if (!$udperms->checkPermissions()) {
		 include ("header.inc.php");
		 ?><script type="text/javascript">parent.tree.ca = '';</script>
		 <br /><br /><div class="sectionHeader"><?php echo $_lang['access_permissions']; ?></div><div class="sectionBody">
        <p><?php echo $_lang['access_permission_parent_denied']; ?></p>
        <?php
        include ("footer.inc.php");
        exit;
		 }
	}
}


//$children= allChildren($_REQUEST['id']);
$parents=$modx->getParentIds($newParentID);

//if (!array_search($newParentID, $children)) {
if (!array_search($_REQUEST['id'], $parents)) {

	$sql = "UPDATE $dbase.`".$table_prefix."site_content` SET isfolder=1 WHERE id=".$_REQUEST['new_parent'].";";
	$rs = $modx->db->query($sql);
	if(!$rs){
		echo "An error occured while attempting to change the new parent to a folder.";
	}

	$sql = "UPDATE $dbase.`".$table_prefix."site_content` SET parent=".$_REQUEST['new_parent'].", editedby=".$modx->getLoginUserID().", editedon=".time()." WHERE id=".$_REQUEST['id'].";";
	$rs = $modx->db->query($sql);
	if(!$rs){
		echo "An error occured while attempting to move the document to the new parent.";
	}

	// finished moving the document, now check to see if the old_parent should no longer be a folder.
	$sql = "SELECT count(*) FROM $dbase.`".$table_prefix."site_content` WHERE parent=$oldparent;";
	$rs = $modx->db->query($sql);
	if(!$rs){
		echo "An error occured while attempting to find the old parents' children.";
	}
	$row = $modx->db->getRow($rs);
	$limit = $row['count(*)'];

	if(!$limit>0) {
		$sql = "UPDATE $dbase.`".$table_prefix."site_content` SET isfolder=0 WHERE id=$oldparent;";
		$rs = $modx->db->query($sql);
		if(!$rs){
			echo "An error occured while attempting to change the old parent to a regular document.";
		}
	}
	
	
	$content_table=$modx->getFullTableName("site_content");
	//получаем старый uri для документа пока не переписали его
	$oldUri=$modx->db->getValue($modx->db->query("SELECT uri FROM $content_table WHERE id=".$_REQUEST['id']." LIMIT 0,1"));
	//сначала обнуляем uri документа и нового родителя, потом uri старого родителя
    resetOldUri($_REQUEST['id'],$_REQUEST['new_parent']);
	resetOldParentUri($oldparent);
    //устанавливаем новые uri для документа, нового родителя и старого родителя (вдруг он перестал быть папкой?)
    makeNewUri($_REQUEST['id'],$oldparent);
    //заменяем uri у всех "детей"
	$newUri=$modx->db->getValue($modx->db->query("SELECT uri FROM $content_table WHERE id=".$_REQUEST['id']." LIMIT 0,1"));
	replaceChildsUri($_REQUEST['id'],$oldUri,$newUri);
	
			
	// empty cache & sync site
	include_once MODX_BASE_PATH ."manager/processors/cache_sync.class.processor.php";
	$sync = new synccache();
	$sync->setCachepath(MODX_BASE_PATH . "assets/cache/");
	$sync->setReport(false);
	$sync->emptyCache(); // first empty the cache
	$header="Location: index.php?r=1&id=$id&a=7";
	header($header);
} else {
	echo "You cannot move a document to a child document!";
}
?>