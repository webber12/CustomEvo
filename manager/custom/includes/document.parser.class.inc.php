<?php
/**
 *	MODx Document Parser
 *	Function: This class contains the main document parsing functions
 *
 */
class DocumentParser extends DocumentParserOriginal{
    function __construct() {
        parent::DocumentParser();
        $this->documentListing = array();
    }

    function cleanDocumentIdentifier($qOrig) {
        (!empty($qOrig)) or $qOrig = $this->config['site_start'];
        $q= ltrim($qOrig,'/');
        $sql = $this->db->query("SELECT id FROM ".$this->getFullTableName("site_content")." WHERE uri='/".$this->db->escape($q)."'");
        if($this->db->getRecordCount($sql)==1){
            $this->documentMethod= 'id';
            $sql=$this->db->getRow($sql);
            if ($this->config['use_alias_path'] == 1) {
                $q = rtrim($q,"/");
                $this->virtualDir = dirname($q);
                $this->virtualDir = ($this->virtualDir == '.' ? '' : $this->virtualDir);
            } else {
                $this->virtualDir= '';
            }

            return $sql['id'];
        }else{
            $this->sendErrorPage();
        }
        return parent::cleanDocumentIdentifier($qOrig);
    }
    function mergeChunkContent($content) {
        $replace= array ();
        $matches= array ();
        if (preg_match_all('~{{(.*?)}}~', $content, $matches)) {
            $settingsCount= count($matches[1]);
            for ($i= 0; $i < $settingsCount; $i++) {
                $replace[$i] = $this->getChunk($matches[1][$i]);
            }
            $content= str_replace($matches[0], $replace, $content);
        }
        return $content;
    }

    function rewriteUrls($documentSource) {
        if ($this->config['friendly_urls'] == 1) {
            $in= '!\[\~([0-9]+)\~\]!is';
            preg_match_all($in,$documentSource,$match);
            $replace = $this->replaceURL(array_unique($match[1]));
            $documentSource = str_replace($replace['tag'],$replace['link'],$documentSource);
            if(preg_match("!\[\~([0-9]+)\~\]!is",$documentSource)){
                $documentSource = $this->rewriteUrls($documentSource);
            }
        } else {
            $in= '!\[\~([0-9]+)\~\]!is';
            $out= "index.php?id=" . '\1';
            $documentSource= preg_replace($in, $out, $documentSource);
        }
        return $documentSource;
    }

    private function replaceURL($match){
        $out = array('link'=>array(),'tag'=>array());
        if(!empty($match)){
            $sql = $this->db->query("SELECT id,uri,type,content FROM ".$this->getFullTableName("site_content")." WHERE id in(".implode(",", $match).")");
            $sql = $this->db->makeArray($sql);
            $match = array_reverse($match);
            foreach($sql as $item){
                $out['link'][]  = $item['type']=='reference' ? $item['content'] : $item['uri'];
                $out['tag'][] = "[~{$item['id']}~]";
                unset($match[$item['id']]);
            }
            if(!empty($match)){
                $error = $this->config['error_page'] ? $this->config['error_page'] : $this->config['site_start'];
                $error = $this->makeUrl($error);
                foreach($match as $item){
                    $out['link'][]  = $error;
                    $out['tag'][] = "[~{$item}~]";
                }
            }
        }
        return $out;
    }

    function getDocumentObject($method, $identifier) {
        if($method == 'alias') {
            $identifier = $this->cleanDocumentIdentifier($identifier);
            $method = $this->documentMethod;
        }
        $tblsc= $this->getFullTableName("site_content");
        $tbldg= $this->getFullTableName("document_groups");

        // allow alias to be full path
		/*
        if($method == 'alias') {
            $identifier = $this->cleanDocumentIdentifier($identifier);
            $method = $this->documentMethod;
        }
		*/
        if($method == 'alias' && $this->config['use_alias_path'] && array_key_exists($identifier, $this->documentListing)) {
            $method = 'id';
            $identifier = $this->documentListing[$identifier];
        }
        // get document groups for current user
        if ($docgrp= $this->getUserDocGroups())
            $docgrp= implode(",", $docgrp);
        // get document
        $access= ($this->isFrontend() ? "sc.privateweb=0" : "1='" . $_SESSION['mgrRole'] . "' OR sc.privatemgr=0") .
         (!$docgrp ? "" : " OR dg.document_group IN ($docgrp)");
        $sql= "SELECT sc.*
              FROM $tblsc sc
              LEFT JOIN $tbldg dg ON dg.document = sc.id
              WHERE sc." . $method . " = '" . $identifier . "'
              AND ($access) LIMIT 1;";
        $result= $this->db->query($sql);
        $rowCount= $this->db->getRecordCount($result);
        if ($rowCount < 1) {
            if ($this->config['unauthorized_page']) {
                // method may still be alias, while identifier is not full path alias, e.g. id not found above
                if ($method === 'alias') {
                    $q = "SELECT dg.id FROM $tbldg dg, $tblsc sc WHERE dg.document = sc.id AND sc.alias = '{$identifier}' LIMIT 1;";
                } else {
                    $q = "SELECT id FROM $tbldg WHERE document = '{$identifier}' LIMIT 1;";
                }
                // check if file is not public
                $secrs= $this->db->query($q);
                if ($secrs)
                    $seclimit= $this->db->getRecordCount($secrs);
            }
            if ($seclimit > 0) {
                // match found but not publicly accessible, send the visitor to the unauthorized_page
                $this->sendUnauthorizedPage();
                exit; // stop here
            } else {
                $this->sendErrorPage();
                exit;
            }
        }

        # this is now the document :) #
        $documentObject= $this->db->getRow($result);
        if ($documentObject['template']) {
            // load TVs and merge with document - Orig by Apodigm - Docvars
			/********************** 
			это слишком тормозит при большом количестве ресурсов поэтому перепишем
			************************************************************
            $sql= "SELECT tv.*, IF(tvc.value!='',tvc.value,tv.default_text) as value ";
            $sql .= "FROM " . $this->getFullTableName("site_tmplvars") . " tv ";
            $sql .= "INNER JOIN " . $this->getFullTableName("site_tmplvar_templates")." tvtpl ON tvtpl.tmplvarid = tv.id ";
            $sql .= "LEFT JOIN " . $this->getFullTableName("site_tmplvar_contentvalues")." tvc ON tvc.tmplvarid=tv.id AND tvc.contentid = '" . $documentObject['id'] . "' ";
            $sql .= "WHERE tvtpl.templateid = '" . $documentObject['template'] . "'";
            $rs= $this->db->query($sql);
            $rowCount= $this->db->getRecordCount($rs);
            if ($rowCount > 0) {
                for ($i= 0; $i < $rowCount; $i++) {
                    $row= $this->db->getRow($rs);
                    $tmplvars[$row['name']]= array (
                        $row['name'],
                        $row['value'],
                        $row['display'],
                        $row['display_params'],
                        $row['type']
                    );
                }
                $documentObject= array_merge($documentObject, $tmplvars);
            }
			********************************************/
			
			$TvIDsStr='';
			$tvsq="SELECT `tvs`.`id`,`tvs`.`name`,`tvs`.`default_text` as `value`,`tvs`.`display`,`tvs`.`display_params`,`tvs`.`type`";
			$tvsq.="FROM ".$this->getFullTableName("site_tmplvars")." tvs,".$this->getFullTableName("site_tmplvar_templates")." tvtmpl ";
			$tvsq.="WHERE `tvs`.`id`=`tvtmpl`.`tmplvarid` AND `tvtmpl`.`templateid`=".$documentObject['template'];
			$tvsq.=" ORDER BY `tvtmpl`.`rank` ASC";
			$query=$this->db->query($tvsq);
			while($row=$this->db->getRow($query)){
				$tmpTvs[$row['id']]['name']=$row['name'];
				$tmpTvs[$row['id']]['value']=$row['value'];
				$tmpTvs[$row['id']]['display']=$row['display'];
				$tmpTvs[$row['id']]['display_params']=$row['display_params'];
				$tmpTvs[$row['id']]['type']=$row['type'];
				$TvIDsStr.=$row['id'].',';
			}
			
			if($TvIDsStr!=''){
				$TvIDsStr=substr($TvIDsStr,0,-1);
				$tvsq2="SELECT tmplvarid,value FROM ".$this->getFullTableName("site_tmplvar_contentvalues")." WHERE tmplvarid IN (".$TvIDsStr.") AND contentid = '" . $documentObject['id'] . "' LIMIT 0,".count($tmpTvs);
				$query2=$this->db->query($tvsq2);
				$rs=$this->db->getRecordCount($query2);
				while($row2=$this->db->getRow($query2)){
					if(isset($tmpTvs[$row2['tmplvarid']]['value'])&&$row2['value']!=''){
						$tmpTvs[$row2['tmplvarid']]['value']=$row2['value'];
					}
				}
			}
			
			if(isset($tmpTvs)&&is_array($tmpTvs)){
				foreach($tmpTvs as $k=>$v){
					$tmplvars[$v['name']]=array(
						$v['name'],
                        $v['value'],
                        $v['display'],
                        $v['display_params'],
                        $v['type']
					);
				}
				
				$documentObject= array_merge($documentObject, $tmplvars);
			}
			
			
			
        }
        return $documentObject;
    }

    function executeParser() {
        error_reporting(0);
        if (version_compare(phpversion(), "5.0.0", ">="))
            set_error_handler(array (
                & $this,
                "phpError"
            ), E_ALL);
        else
            set_error_handler(array (
                & $this,
                "phpError"
            ));

        $this->db->connect();

        // get the settings
        if (empty ($this->config)) {
            $this->getSettings();
        }

        // IIS friendly url fix
        if ($this->config['friendly_urls'] == 1 && strpos($_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS') !== false) {
            $url= $_SERVER['QUERY_STRING'];
            $err= substr($url, 0, 3);
            if ($err == '404' || $err == '405') {
                $k= array_keys($_GET);
                unset ($_GET[$k[0]]);
                unset ($_REQUEST[$k[0]]); // remove 404,405 entry
                $_SERVER['QUERY_STRING']= $qp['query'];
                $qp= parse_url(str_replace($this->config['site_url'], '', substr($url, 4)));
                if (!empty ($qp['query'])) {
                    parse_str($qp['query'], $qv);
                    foreach ($qv as $n => $v)
                        $_REQUEST[$n]= $_GET[$n]= $v;
                }
                $_SERVER['PHP_SELF']= $this->config['base_url'] . $qp['path'];
                $_REQUEST['q']= $_GET['q']= $qp['path'];
            }
        }

        // check site settings
        if (!$this->checkSiteStatus()) {
            header('HTTP/1.0 503 Service Unavailable');
            if (!$this->config['site_unavailable_page']) {
                // display offline message
                $this->documentContent= $this->config['site_unavailable_message'];
                $this->outputContent();
                exit; // stop processing here, as the site's offline
            } else {
                // setup offline page document settings
                $this->documentMethod= "id";
                $this->documentIdentifier= $this->config['site_unavailable_page'];
            }
        } else {
            // make sure the cache doesn't need updating
            $this->checkPublishStatus();

            // find out which document we need to display
            $this->documentMethod= $this->getDocumentMethod();
            $this->documentIdentifier= $this->getDocumentIdentifier($this->documentMethod);
        }

        if ($this->documentMethod == "none") {
            $this->documentMethod= "id"; // now we know the site_start, change the none method to id
        }
        if ($this->documentMethod == "alias") {
            $this->documentIdentifier= $this->cleanDocumentIdentifier($this->documentIdentifier);
        }

        // invoke OnWebPageInit event
        $this->invokeEvent("OnWebPageInit");

        // invoke OnLogPageView event
        if ($this->config['track_visitors'] == 1) {
            $this->invokeEvent("OnLogPageHit");
        }

        $this->prepareResponse();
    }

    private function _loadParent($id, $height){
        $parents = array();
        $q=$this->db->query("SELECT alias,parent,id FROM ".$this->getFullTableName("site_content")." WHERE id=".(int)$id);
        if($this->db->getRecordCount($q)==1){
            $q = $this->db->getRow($q);
            $alias = ($q['alias']=='') ? $q['id'] : $q['alias'];
            $parents[$alias] = $q['id'];
            if($height>0 && $q['parent']>0){
                $data=$this->_loadParent($q['parent'],$height--);
                foreach($data as $key=>$val){
                    $parents[$key] = $val;
                }
            }
        }
        return $parents;
    }

    function getParentIds($id, $height= 10) {
        $parents = $this->_loadParent($id,$height);
        //@see: http://stackoverflow.com/questions/1028668/get-first-key-in-a-possibly-associative-array
        reset($parents);
        unset($parents[key($parents)]);
        return $parents;
    }

    private function _loadChildIds($id){
        $sql = $this->db->query("SELECT id,isfolder FROM ".$this->getFullTableName("site_content")." WHERE parent=".$id);
        $sql = $this->db->makeArray($sql);
        foreach($sql as $item){
            $this->documentMap[$id][] = $item['id'];
            if($item['isfolder']){
                $this->_loadChildIds($item['id']);
            }
        }
    }

    function getChildIds($id, $depth= 10, $children= array ()) {
        if(empty($this->documentMap)){
            $this->_loadChildIds(0);
        }
        // Get all the children for this parent node
        if (isset($this->documentMap[$id])) {
            $depth--;

            foreach ($$this->documentMap[$id] as $childId) {
				$tmp=$this->getAliasListing($childId);
                $pkey = (strlen($tmp['path']) ? "{$tmp['path']}/" : '') . $tmp['alias'];
                if (!strlen($pkey)) $pkey = "{$childId}";
                $children[$pkey] = $childId;

                if ($depth) {
                    $children += $this->getChildIds($childId, $depth);
                }
            }
        }
        return $children;
    }
    function makeUrl($id, $alias= '', $args= '', $scheme= '') {
        $url= '';
        $virtualDir= '';
        $f_url_prefix = $this->config['friendly_url_prefix'];
        $f_url_suffix = $this->config['friendly_url_suffix'];
        if (!is_numeric($id)) {
            $this->messageQuit('`' . $id . '` is not numeric and may not be passed to makeUrl()');
        }
        if ($args != '' && $this->config['friendly_urls'] == 1) {

            // add ? to $args if missing
            $c= substr($args, 0, 1);
            if (strpos($f_url_prefix, '?') === false) {
                if ($c == '&')
                    $args= '?' . substr($args, 1);
                elseif ($c != '?') $args= '?' . $args;
            } else {
                if ($c == '?')
                    $args= '&' . substr($args, 1);
                elseif ($c != '&') $args= '&' . $args;
            }
        }
        elseif ($args != '') {

            // add & to $args if missing
            $c= substr($args, 0, 1);
            if ($c == '?')
                $args= '&' . substr($args, 1);
            elseif ($c != '&') $args= '&' . $args;
        }
        if($this->config['site_start']==$id){
            $url = '';
        }
        elseif ($this->config['friendly_urls'] == 1 && $alias != '') {
            $url= $f_url_prefix . $alias . $f_url_suffix . $args;
        }
        elseif ($this->config['friendly_urls'] == 1 && $alias == '') {
            $alias= $id;
            if ($this->config['friendly_alias_urls'] == 1) {
                $al= $this->getAliasListing($id);
                if($al['isfolder']===1 && $this->config['make_folders']==='1') $f_url_suffix = '/';
                $alPath= !empty ($al['path']) ? $al['path'] . '/' : '';
                if ($al && $al['alias']) $alias= $al['alias'];
            }
            $alias= $alPath . $f_url_prefix . $alias . $f_url_suffix;
            $url= $alias . $args;
        } else {
            $url= 'index.php?id=' . $id . $args;
        }

        $host= $this->config['base_url'];
        // check if scheme argument has been set
        if ($scheme != '') {
            // for backward compatibility - check if the desired scheme is different than the current scheme
            if (is_numeric($scheme) && $scheme != $_SERVER['HTTPS']) {
                $scheme= ($_SERVER['HTTPS'] ? 'http' : 'https');
            }

            // to-do: check to make sure that $site_url incudes the url :port (e.g. :8080)
            $host= $scheme == 'full' ? $this->config['site_url'] : $scheme . '://' . $_SERVER['HTTP_HOST'] . $host;
        }
        if ($this->config['xhtml_urls']) {
            return preg_replace("/&(?!amp;)/","&amp;", $host . $virtualDir . $url);
        } else {
            return $host . $virtualDir . $url;
        }
    }
    function getAliasListing($id){
        if(isset($this->aliasListing[$id])){
            $out = $this->aliasListing[$id];
        }else{
            $q = $this->db->query("SELECT uri,id,alias,isfolder,parent FROM ".$this->getFullTableName("site_content")." WHERE id=".(int)$id);
            if($this->db->getRecordCount($q)=='1'){
                $q = $this->db->getRow($q);
                $this->aliasListing[$id] =  array(
                    'id' => (int)$q['id'],
                    'alias' => $q['alias']=='' ? $q['id'] : $q['alias'],
                    'parent' => (int)$q['parent'],
                    'isfolder' => (int)$q['isfolder'],
                );

                if(empty($q['uri'])){
                    if($this->aliasListing[$id]['parent']>0){
                        $tmp = $this->getAliasListing($this->aliasListing[$id]['parent']);
                        $this->aliasListing[$id]['path'] = $tmp['path'] . (($tmp['parent']>0) ? '/' : '') .$tmp['alias'];
                    }
                }else{
                    $this->aliasListing[$id]['path'] = trim(str_replace("\\","/",dirname($q['uri'])),'/');
                }

                $out = $this->aliasListing[$id];
            }
        }
        return $out;
    }

    function runSnippet($snippetName, $params= array ()) {
        if (isset ($this->snippetCache[$snippetName])) {
            $snippet= $this->snippetCache[$snippetName];
            $properties= $this->snippetCache[$snippetName . "Props"];
        } else { // not in cache so let's check the db
            $sql= "SELECT ss.`name`, ss.`snippet`, ss.`properties`, sm.properties as `sharedproperties` FROM " . $this->getFullTableName("site_snippets") . " as ss LEFT JOIN ".$this->getFullTableName('site_modules')." as sm on sm.guid=ss.moduleguid WHERE ss.`name`='" . $this->db->escape($snippetName) . "';";
            $result= $this->db->query($sql);
            if ($this->db->getRecordCount($result) == 1) {
                $row= $this->db->getRow($result);
                $snippet =  $this->snippetCache[$snippetName]= $row['snippet'];
                $properties = $this->snippetCache[$snippetName . "Props"]= $row['properties']." ".$row['sharedproperties'];
            } else {
                $snippet= $this->snippetCache[$snippetName]= "return false;";
                $properties = $this->snippetCache[$snippetName . "Props"]= '';
            }
        }
        // load default params/properties
        $parameters= $this->parseProperties($properties);
        $parameters= array_merge($parameters, $params);
        // run snippet
        return $this->evalSnippet($snippet, $parameters);
    }
    function getChunk($chunkName) {
            $out = null;
            if (isset ($this->chunkCache[$chunkName])) {
                $out = $this->chunkCache[$chunkName];
            } else {
                $sql= "SELECT `snippet` FROM " . $this->getFullTableName("site_htmlsnippets") . " WHERE " . $this->getFullTableName("site_htmlsnippets") . ".`name`='" . $this->db->escape($chunkName) . "';";
                $result= $this->db->query($sql);
                $limit= $this->db->getRecordCount($result);
                if ($limit == 1) {
                    $row= $this->db->getRow($result);
                    $out = $this->chunkCache[$chunkName]= $row['snippet'];
                }
            }
            return $out;
    }

}
?>
