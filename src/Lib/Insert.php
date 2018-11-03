<?php
namespace ExportSectionData\Lib;

class Insert {
    private $exclude;
    private $numeric;
    private $null;
    private $table;
    private $fields;
    
    public function __construct($table, $exclude=[], $numeric=[], $null=[]) {
        $this->table = $table;
        $this->exclude = $exclude;
        $this->numeric = $numeric;
        $this->null = $null;
        $this->fields = (object)[];
    }
    
    public function __set($name, $value) {
        $this->fields->$name = $value;
    }
    
    public function __get($name) {
        return $this->fields->$name;
    }

    public function values() {
        $values = [];
        foreach ($this->fields as $key => $value) {
            if(in_array($key, $this->exclude)) {
                continue;
            }
            // Set NULL fields
            foreach ($this->null as $pattern) {
                if (preg_match("@^{$pattern}$@i", $key) && (is_null($value) || strlen(trim($value)) <= 0)) {
                    $values[] = 'NULL';
                    continue 2;
                }
            }
            // Set Numeric fields
            foreach ($this->numeric as $pattern) {
                if (preg_match("@^{$pattern}$@i", $key)) {
                    $values[] = $value;
                    continue 2;
                }
            }

            $values[] = sprintf("'%s'", \MySQL::cleanValue($value));
        }
        return implode(', ', $values);
    }
    
    public function names() {
        $names = [];
        foreach ($this->fields as $name => $value) {
            if(in_array($name, $this->exclude)) {
                continue;
            }
            $names[] = $name;
        }
        return sprintf("`%s`", implode('`, `', $names));
    }
    
    public function table() {
        return $this->table;
    }

    public function __toString() {
        return sprintf(
            "INSERT INTO %s (%s) VALUES (%s);",
            $this->table,
            $this->names(),
            $this->values()
        );
    }
}
