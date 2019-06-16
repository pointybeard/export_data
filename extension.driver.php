<?php

declare(strict_types=1);

if (!file_exists(__DIR__.'/vendor/autoload.php')) {
    throw new Exception(sprintf(
        'Could not find composer autoload file %s. Did you run `composer update` in %s?',
        __DIR__.'/vendor/autoload.php',
        __DIR__
    ));
}

require_once __DIR__.'/vendor/autoload.php';

use pointybeard\Symphony\Extensions\ExportSectionData;

// This file is included automatically in the composer autoloader, however,
// Symphony might try to include it again which would cause a fatal error.
// Check if the class already exists before declaring it again.
if (!class_exists('\\Extension_Export_Data')) {
    class Extension_Export_Data extends Extension
    {
        const EXPORT_TYPE_SQL = 'sql';
        const EXPORT_TYPE_JSON = 'json';

        public function getSubscribedDelegates(): array
        {
            return [
                [
                  'page' => '/publish/',
                  'delegate' => 'AddCustomActions',
                  'callback' => 'appendWithSelected',
                ],

                [
                  'page' => '/publish/',
                  'delegate' => 'CustomActions',
                  'callback' => 'checkWithSelected',
                ],
            ];
        }

        public function appendWithSelected(array &$context): void
        {
            array_splice($context['options'], 2, 0, [[
                'label' => 'Export Data',
                'options' => [
                    ['extension-export-data-sql', false, __('SQL')],
                    ['extension-export-data-json', false, __('JSON')],
                ],
            ]]);
        }

        public function checkWithSelected(array &$context): void
        {
            if (!isset($_POST['with-selected']) || !preg_match('@^extension-export-data-(json|sql)$@', $_POST['with-selected'], $matches)) {
                return;
            }

            $type = $matches[1];

            $output = $this->export($type, $context['checked']);

            header('Content-Type: application/octet-stream');
            header('Content-Transfer-Encoding: Binary');
            header(sprintf(
                'Content-disposition: attachment; filename="export_data-%s.%s',
                date('Ymd_His'),
                $type
          ));
            echo $output;
            exit;
        }

        private function __toJSON(array $entries): string
        {
            $fieldsToUnset = ['author_id', 'section_id', 'id', 'modification_author_id'];
            for ($ii = 0; $ii < count($entries); ++$ii) {
                foreach ($fieldsToUnset as $f) {
                    unset($entries[$ii]->$f);
                }

                for ($kk = 0; $kk < count($entries[$ii]->data); ++$kk) {
                    unset($entries[$ii]->data[$kk]->entry_id);
                }
            }

            return json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        private function buildInsert(string $table, array $data, array $exclude = [], array $numericFields = [
            'id',
            '.+_id',
            'sortorder',
            'parent_section',
        ], array $nullFields = [
            'date', 'relation_id', 'handle', 'value',
        ]): string
        {
            $insert = new ExportSectionData\Insert(
                $table,
                $exclude,
                $numericFields,
                $nullFields
            );

            $sql = '';
            $first = true;
            foreach ($data as $fields) {
                $i = clone $insert;

                foreach ($fields as $name => $value) {
                    $i->$name = $value;
                }

                if ($first) {
                    $sql = sprintf(
                        "INSERT INTO `%s` (%s) VALUES\n\t(%s)",
                        $table,
                        $insert->names(),
                        $insert->values()
                    );
                    $first = false;
                    continue;
                }

                $sql .= sprintf(", \n\t(%s)", $insert->values());
            }

            return "{$sql};";
        }

        private function __toSQL(array $entries): string
        {
            $tables = [
                'tbl_entries',
            ];

            $entryIds = [];
            foreach ($entries as $e) {
                $entryIds[] = $e->id;
            }

            $sql = '-- *** `tbl_entries` ***'.PHP_EOL;
            $sql .= $this->buildInsert(
                'tbl_entries',
                $entries,
                ['data', 'section_handle']
            ).PHP_EOL;

            // Entry Data
            foreach ($entries as $e) {
                $sql .= PHP_EOL."-- *** Entry {$e->id} ***".PHP_EOL;

                foreach ($e->data as $d) {
                    if (!in_array("tbl_entries_data_{$d->field_id}", $tables)) {
                        $tables[] = "tbl_entries_data_{$d->field_id}";
                    }

                    $sql .= $this->buildInsert(
                        "tbl_entries_data_{$d->field_id}",
                        [$d],
                        ['field_id'],
                        ['.+_id'],
                        ['id', 'date', 'relation_id']
                    ).PHP_EOL;
                }
            }

            $entryIds = implode(',', $entryIds);
            $deleteQueries = '';
            foreach ($tables as $t) {
                $deleteQueries .= sprintf(
                    '-- DELETE FROM `%s` WHERE `entry_id` IN (%s);',
                    $t,
                    $entryIds
                ).PHP_EOL;
            }

            $sql = sprintf(
                '-- ****************************************************
-- Export Data
--
-- Generated At: %s
-- ****************************************************

-- Uncomment below to delete existing entries (optional)

%s
',
                date(DATE_RFC2822),
                $deleteQueries
            ).PHP_EOL.PHP_EOL.$sql;

            return $sql;
        }

        public function export(string $type, array $ids): string
        {
            $sqlResult = null;

            $db = \SymphonyPDO\Loader::instance();

            // Build the entry table insert
            $result = (object) [
              'entries' => [],
            ];

            $query = $db->prepare(sprintf(
                'SELECT e.section_id
                FROM `tbl_entries` as `e`
                WHERE e.id IN (%s)
                LIMIT 1',
                implode(',', $ids)
            ));
            $query->execute();
            $sectionId = (int) $query->fetchColumn();

            // Find the fields
            $query = $db->prepare('SELECT * FROM `tbl_fields` WHERE `parent_section` = :section');
            $query->execute([':section' => $sectionId]);
            $fields = $query->fetchAll(\PDO::FETCH_OBJ);

            $query = $db->prepare(
                sprintf(
                    'SELECT e.*, s.handle as `section_handle`
                FROM `tbl_entries` as `e`
                INNER JOIN `tbl_sections` as `s` ON e.section_id = s.id
                WHERE e.id IN (%s)',
                    implode(',', $ids)
            )
            );

            $query->execute();
            foreach ($query->fetchAll(\PDO::FETCH_OBJ) as $row) {
                $row->data = [];
                foreach ($fields as $f) {
                    $query = $db->prepare(sprintf('SELECT * FROM `tbl_entries_data_%s` WHERE `entry_id` = :id', $f->id));
                $query->execute([':id' => $row->id]);
                foreach ($query->fetchAll(\PDO::FETCH_OBJ) as $d) {
                    unset($d->id);
                    $d->field_id = $f->id;
                    $row->data[] = $d;
                }
            }

            $result->entries[] = $row;
        }

        if (self::EXPORT_TYPE_JSON == $type) {
            return $this->__toJSON($result->entries);
        } else {
            return $this->__toSQL($result->entries);
        }
    }
}
}
