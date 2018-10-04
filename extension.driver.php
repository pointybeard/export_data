<?php

if(!file_exists(__DIR__ . '/vendor/autoload.php')) {
    throw new Exception(sprintf(
        "Could not find composer autoload file %s. Did you run `composer update` in %s?",
        __DIR__ . '/vendor/autoload.php',
        __DIR__
    ));
}

require_once __DIR__ . '/vendor/autoload.php';

Class extension_export_data extends Extension
{

    const EXPORT_TYPE_SQL = 'sql';
    const EXPORT_TYPE_JSON = 'json';

    public function getSubscribedDelegates()
    {
        return [
            [
              'page'     => '/publish/',
              'delegate' => 'AddCustomActions',
              'callback' => 'appendWithSelected'
            ],

            [
              'page'     => '/publish/',
              'delegate' => 'CustomActions',
              'callback' => 'checkWithSelected'
            ]
        ];
    }

    public function appendWithSelected($context) {
        array_splice($context['options'], 2, 0, [[
            "label" => "Export Data",
            "options" => [
                ['extension-export-data-sql', false, __('SQL')],
                ['extension-export-data-json', false, __('JSON')],
            ]
        ]]);
    }

    public function checkWithSelected($context) {

      if(!isset($_POST['with-selected']) || !preg_match("@^extension-export-data-(json|sql)$@", $_POST['with-selected'], $matches)) {
        return;
      }

      $type = $matches[1];

      $sql = $this->export($type, $context['checked']);

      header('Content-Type: application/octet-stream');
      header("Content-Transfer-Encoding: Binary");
      header(sprintf(
          'Content-disposition: attachment; filename="export_data-%s.%s',
          date('Ymd_His'), $type
      ));
      print $sql;
      exit;
    }

    private function buildSQL(array $entries) {

        $deleteQueries = [
            "-- DELETE FROM `tbl_entries` WHERE `id` IN (%1\$s);"
        ];

        $entryIds = [];

        $sql = "-- *** `tbl_entries` ***" . PHP_EOL . "INSERT INTO `tbl_entries` (`id`, `section_id`, `author_id`, `modification_author_id`, `creation_date`, `creation_date_gmt`, `modification_date`, `modification_date_gmt`) VALUES " . PHP_EOL;

        foreach($entries as $e) {
            $entryIds[] = $e->id;
            $string = NULL;
            foreach($e as $field => $value) {
                if($field == 'data') continue;
                $string .= "'{$value}', ";
            }
            $string = trim($string, ", ");

            $sql .= "({$string}), ". PHP_EOL;
        }
        $sql = trim($sql, ", \r\n") . ";" . PHP_EOL;

        foreach($entries as $e) {
            $sql .= PHP_EOL . "-- *** Entry {$e->id} ***" . PHP_EOL;

            foreach($e->data as $d) {
                if(!isset($deleteQueries[$d->field_id])) {
                    $deleteQueries[$d->field_id] = sprintf(
                        "-- DELETE FROM `tbl_entries_data_%s` WHERE `entry_id` IN",
                        $d->field_id
                    ) .  "(%1\$s);";
                }

                $sql .= "INSERT INTO `tbl_entries_data_{$d->field_id}` " . "(`id`, ";

                $string = NULL;
                foreach($d as $field => $value) {
                    if($field == 'field_id') continue;
                    $string .= "'{$value}', ";
                    $sql .= "`{$field}`, ";
                }
                $string = trim($string, ", ");
                $sql = trim($sql, ", ");
                $sql .= ") VALUES (NULL, $string);" . PHP_EOL;
            }
        }

        $deleteQuery = sprintf($deleteQuery, implode(",", $entryIds));

        return
            "-- Delete Existing Entries (optional)" . PHP_EOL .
            sprintf(
                implode(PHP_EOL, $deleteQueries),
                implode(',', $entryIds)
            ) . PHP_EOL . PHP_EOL .
            $sql
        ;
    }

    public function export($type, array $ids) {

        $sqlResult = NULL;

        $db = \SymphonyPDO\Loader::instance();

        // Build the entry table insert
        $result = (object)[
          "entries" => [],
        ];

        $query = $db->prepare(sprintf(
            "SELECT `section_id` FROM `tbl_entries` WHERE `id` IN (%s) LIMIT 1",
            implode(',', $ids)
        ));
        $query->execute();
        $sectionId = (int)$query->fetchColumn();

        // Find the fields
        $query = $db->prepare("SELECT * FROM `tbl_fields` WHERE `parent_section` = :section");
        $query->execute([':section' => $sectionId]);
        $fields = $query->fetchAll(\PDO::FETCH_OBJ);

        $query = $db->prepare(sprintf("SELECT * FROM `tbl_entries` WHERE `id` IN (%s)", implode(',', $ids)));
        $query->execute();
        foreach($query->fetchAll(\PDO::FETCH_OBJ) as $row) {
            $row->data = [];
            foreach($fields as $f) {
                $query = $db->prepare(sprintf("SELECT * FROM `tbl_entries_data_%s` WHERE `entry_id` = :id", $f->id));
                $query->execute([":id" => $row->id]);
                foreach($query->fetchAll(\PDO::FETCH_OBJ) as $d) {
                    unset($d->id);
                    $d->field_id = $f->id;
                    $row->data[] = $d;
                }
            }

            $result->entries[] = $row;
        }

        if($type == self::EXPORT_TYPE_JSON) {
            return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return $this->buildSQL($result->entries);
    }

}
