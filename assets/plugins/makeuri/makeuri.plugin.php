<?php
$table=$modx->getFullTableName("site_content");

if(!function_exists("makeNewUri")){
    function makeNewUri($idSQL){
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

if(!function_exists("resetOldChildsUri")){
    function resetOldChildsUri($idSQL){
        global $modx;
        $table = $modx->getFullTableName("site_content");
        $docChilds=$modx->getChildIds($idSQL);
        $childs='';
        foreach ($docChilds as $v){
            $childs.=$v.',';
        }
        $childs=substr($childs,0,-1);
        if($childs!=''){
            $modx->db->update(array('uri'=>''),$table,'id IN ('.$childs.')');
        }
    }
}

if(!function_exists("replaceChildsUri")){
    function replaceChildsUri($idSQL,$oldUri,$newUri){
        global $modx;
        $table = $modx->getFullTableName("site_content");
    /*    $docChilds=$modx->getChildIds($idSQL);
        $childs='';
        foreach ($docChilds as $v){
            $childs.=$v.',';
        }
        $childs=substr($childs,0,-1);
        if($childs!=''){*/
		//	$query="UPDATE $table SET `uri`= REPLACE(`uri`, '".$oldUri."', '".$newUri."') WHERE id IN (".$childs.") AND `uri` LIKE '".$oldUri."%'";
			$query="UPDATE $table SET `uri`= REPLACE(`uri`, '".$oldUri."', '".$newUri."') WHERE `uri` LIKE '".$oldUri."%'";
		//	echo $query;
            $q=$modx->db->query($query);
     /*   }*/
    }
}

if(!function_exists("makeNewChildsUri")){
    function makeNewChildsUri($idSQL){
        global $modx;
        $table = $modx->getFullTableName("site_content");
        $docChilds=$modx->getChildIds($idSQL,1);
        foreach ($docChilds as $v){//удаляем текущие записи в массиве, чтоб не мешали изменениям
            if(isset($modx->aliasListing[$v])){
                unset($modx->aliasListing[$v]);
            }
            $newUri=$modx->makeUrl($v);
            $modx->db->update(array('uri'=>$newUri),$table,'id ='.$v);
            makeNewChildsUri($v);
        }
    }
}


switch($modx->Event->name){
    case 'OnDocDuplicate':{
        $idSQL = isset($new_id) ? (int)$new_id : 0;
        makeNewUri($idSQL);
        break;
    }
    case 'OnBeforeDocFormSave':{
        if($modx->Event->params['mode'] == "upd"){
            //берем старые значения пока они не изменились
            $oldInfo=$modx->db->getRow($modx->db->query("SELECT parent,alias,uri FROM $table WHERE id=".$id." LIMIT 0,1"));
            $_SESSION['oldParent']=$oldInfo['parent'];
            $_SESSION['oldAlias']=$oldInfo['alias'];
            $_SESSION['oldUri']=$oldInfo['uri'];
        }
        break;
    }
    case 'OnDocFormSave':{
        $idSQL = isset($id) ? (int)$id : 0;
        $mode=$modx->Event->params['mode'];
        $newInfo=$modx->db->getRow($modx->db->query("SELECT parent,alias,uri FROM $table WHERE id=".$idSQL." LIMIT 0,1"));
		
        if(isset($_SESSION['oldAlias'])&&$_SESSION['oldAlias']!=''&&$_SESSION['oldAlias']!=$newInfo['alias']&&isset($_SESSION['oldUri'])&&$mode=='upd'){
        //меняем uri документа, дочерних, нового родителя если изменился alias
		
            //сначала обнуляем uri документа и нового родителя
            resetOldUri($idSQL,$newInfo['parent']);
            //теперь можно обнулить uri для всех детей текущего документа
            //resetOldChildsUri($idSQL);
            //устанавливаем новые uri для документа и нового родителя
            makeNewUri($idSQL);
            //устанавливаем новые uri для всех детей документа пошагово с depth=1
            //makeNewChildsUri($idSQL);
			//заменяем uri у всех "детей"
			$newUri=$modx->db->getValue($modx->db->query("SELECT uri FROM $table WHERE id=".$idSQL." LIMIT 0,1"));
			replaceChildsUri($idSQL,$_SESSION['oldUri'],$newUri);
        	//exit;
        }
        else{
            makeNewUri($idSQL);
        }
        $_SESSION['oldParent']='';
        $_SESSION['oldAlias']='';
        $_SESSION['oldUri']='';
        break;
    }
    default:{
        $idSQL = 0;
    }
}
?>