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

5) Добавляем плагин на события OnDocDuplicate, OnBeforeDocFormSave и OnDocFormSave

```php
require MODX_BASE_PATH .'assets/plugins/makeuri/makeuri.plugin.php';
```
Не забываем разместить папку makeuri с плагином из архива в папку assets/plugins


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

11) Добавляем в начало файла manager/processors/move_document.processor.php строку

```php
include_once(dirname(dirname(__FILE__)) . '/custom/processors/move_document.processor.php');
exit();
```


12) Отчищаем кеш

13) Добавляем 3 индекса unpub_date, pub_date, menuindex в таблицу site_content