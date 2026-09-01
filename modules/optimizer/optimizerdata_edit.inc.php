<?php
/*
* @version 0.2 (wizard)
*/
if (isset($this->owner) && isset($this->owner->name) && $this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$table_name = 'optimizerdata';
$id = (int)$id;

$rec = array();
if ($id) {
    $rec = SQLSelectOne("SELECT * FROM $table_name WHERE ID = " . $id);
    if (!is_array($rec)) {
        $rec = array();
    }
}

if ($this->mode == 'update') {
    $ok = 1;

    $rec['CLASS_NAME'] = trim(gr('class_name'));
    $rec['OBJECT_NAME'] = trim(gr('object_name'));
    $rec['PROPERTY_NAME'] = trim(gr('property_name'));
    $rec['KEEP'] = (int)gr('keep');

    $optimize = gr('optimize');
    if (!in_array($optimize, array('', 'avg', 'max', 'min', 'sum', 'last'), true)) {
        $optimize = '';
    }
    $rec['OPTIMIZE'] = $optimize;
    if ($rec['PROPERTY_NAME'] === '') {
        $ok = 0;
    }

    if ($ok) {
        if (isset($rec['ID']) && $rec['ID']) {
            SQLUpdate($table_name, $rec);
        } else {
            SQLExec("DELETE FROM $table_name
                      WHERE CLASS_NAME = '" . DBSafe($rec['CLASS_NAME']) . "'
                        AND OBJECT_NAME = '" . DBSafe($rec['OBJECT_NAME']) . "'
                        AND PROPERTY_NAME = '" . DBSafe($rec['PROPERTY_NAME']) . "'");
            $new_rec = 1;
            $rec['ID'] = SQLInsert($table_name, $rec);
        }
        $out['OK'] = 1;
    } else {
        $out['ERR'] = 1;
        $out['ERR_PROPERTY_NAME'] = 1;
    }
}

if (!isset($rec['ID'])) {
    // pre-fill a new rule from the "Add" link of the analysis table
    if (!isset($rec['CLASS_NAME'])) {
        $rec['CLASS_NAME'] = trim(gr('class_name'));
    }
    if (!isset($rec['OBJECT_NAME'])) {
        $rec['OBJECT_NAME'] = trim(gr('object_name'));
    }
    if (!isset($rec['PROPERTY_NAME'])) {
        $rec['PROPERTY_NAME'] = trim(gr('property_name'));
    }
    if (!isset($rec['KEEP'])) {
        $rec['KEEP'] = '';
    }
    if (!isset($rec['OPTIMIZE'])) {
        $rec['OPTIMIZE'] = 'avg';
    }
    $rec['ID'] = '';
}

foreach ($rec as $k => $v) {
    if (!is_array($v)) {
        $rec[$k] = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

outHash($rec, $out);
