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
    function resetOldUri($idSQL,$parent){
        global $modx;
        $table = $modx->getFullTableName("site_content");
        $modx->db->update(array('uri'=>''),$table,'id ='.$idSQL);
        if($parent!=0){
            $modx->db->update(array('uri'=>''),$table,'id ='.$parent);
        }
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
        //меняем uri документа, дочерних, родителя если изменился alias
		// и мы обновляем существующий ресурс
		
            //сначала обнуляем uri документа и родителя
            resetOldUri($idSQL,$newInfo['parent']);
            //устанавливаем новые uri для документа и родителя
            makeNewUri($idSQL);
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