<?php
/*
* @version 0.2 (wizard)
*/
if (isset($this->owner) && isset($this->owner->name) && $this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$sortby_optimizerdata = "CLASS_NAME, OBJECT_NAME, PROPERTY_NAME";
$out['SORTBY'] = $sortby_optimizerdata;

// SEARCH RESULTS
$res = SQLSelect("SELECT * FROM optimizerdata ORDER BY " . $sortby_optimizerdata);

if (is_array($res) && isset($res[0]['ID'])) {
    $out['RESULT'] = $res;
}
