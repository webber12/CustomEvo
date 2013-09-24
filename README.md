CustomEvo
=========
Оптимизация MODX Evolution для работы с большим числом документов

Установка
=========
1) Закачиваем содержимое папки manager в папку с админкой MODX Evolution

2) Добавляем в таблицу modx_site_content новую колонку uri типа varchar длинной 255

3) Запускаем скрипт
```php
$q = $modx->db->query("SELECT id FROM ".$modx->getFullTableName("site_content"));
$q = $modx->db->makeArray($q);
foreach($q as $item){
    $url = $modx->makeURL($item['id']);
    $modx->db->update(array('uri'=>$url),$modx->getFullTableName("site_content"),'id='.$item['id']);
}
```

4) Добавляем уникальный индекс колонке uri в таблице site_content

5) Добавляем плагин на события OnDocDuplicate и OnDocFormSave
```php
switch($modx->Event->name){
	case 'OnDocDuplicate':{
		$idSQL = isset($new_id) ? (int)$new_id : 0;
		break;
	}
	case 'OnDocFormSave':{
		$idSQL = isset($id) ? (int)$id : 0;
		break;
	}
	default:{
		$idSQL = 0;
	}
}
if($idSQL>0){
	$table = $modx->getFullTableName("site_content");
	$q = $modx->db->query("SELECT parent FROM ".$table." WHERE id={$idSQL}");
	if($modx->db->getRecordCount($q)==1){
		$q = $modx->db->getRow($q);
		if($q['parent']){
			$modx->db->update(array('uri'=>$modx->makeUrl($q['parent'])),$table,'id='.$q['parent']);
		}
		$modx->db->update(array('uri'=>$modx->makeUrl($idSQL)),$table,'id='.$idSQL);
	}
}
```

6) Проверяем порядок вызова плагинов и убеждаемся, что наш плагин запускается последним

7) Добавляем в файл manager/includes/document.parser.class.inc.php строку
```php
include_once(dirname(dirname(__FILE__)) . '/custom/includes/document.parser.class.inc.php');
```

8) Заменяем в файле manager/includes/document.parser.class.inc.php строку
```php
class DocumentParser{ 
```
на 
```php
class DocumentParserOriginal{
```

9) Добавляем в файл manager/processors/cache_sync.class.processor.php строку
```php
include_once(dirname(dirname(__FILE__)) . '/custom/processors/cache_sync.class.processor.php');
```

10) Заменяем в файле manager/processors/cache_sync.class.processor.php строку
```php
class synccache{
```
на
```php
class synccacheOriginal{
```

11) Отчищаем кеш

12) Добавляем 3 индекса unpub_date, pub_date, menuindex в таблицу site_content