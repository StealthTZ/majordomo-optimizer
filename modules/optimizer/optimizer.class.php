<?php
/**
 * Optimizer
 *
 * History data optimizer for MajorDoMo.
 *
 * @package project
 * @author Wizard <sergejey@gmail.com>
 * @copyright http://majordomo.smartliving.ru/ (c)
 * @version 0.2 (PHP 8 compatibility & bugfix release)
 */
//
//
class optimizer extends module
{
    /**
     * Default module configuration.
     * @var array
     */
    private $defaultConfig = array(
        'START_DAILY' => 1,
        'START_TIME' => 3,
        'AUTO_OPTIMIZE' => 10000,
        'KEEP_CACHED' => 30,
    );

    /**
     * Progress reporter: callable($event, array $data) or null.
     * While it is set, the module reports structured events instead of
     * flooding the page with dprint() output. NULL keeps the old CLI behaviour.
     * @var callable|null
     */
    public $progress = null;

    /**
     * Tables that have to be defragmented once the whole run is over.
     * OPTIMIZE TABLE rebuilds the whole table, so it must never be issued
     * inside the aggregation loop.
     * @var array
     */
    private $tablesToOptimize = array();

    /**
     * optimizer
     *
     * Module class constructor
     *
     * @access public
     */
    public function __construct()
    {
        $this->name = "optimizer";
        $this->title = "Optimizer";
        $this->module_category = "<#LANG_SECTION_SYSTEM#>";
        // parent::__construct() only contains the PHP4-constructor shim,
        // there is nothing to inherit here.
        $this->checkInstalled();
    }

    /**
     * saveParams
     *
     * Saving module parameters
     *
     * @access public
     */
    function saveParams($data = 0)
    {
        $p = array();
        if (isset($this->id)) {
            $p["id"] = $this->id;
        }
        if (isset($this->view_mode)) {
            $p["view_mode"] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $p["edit_mode"] = $this->edit_mode;
        }
        if (isset($this->tab)) {
            $p["tab"] = $this->tab;
        }
        return parent::saveParams($p);
    }

    /**
     * getParams
     *
     * Getting module parameters from query string
     *
     * @access public
     */
    function getParams()
    {
        global $id;
        global $mode;
        global $view_mode;
        global $edit_mode;
        global $tab;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
    }

    /**
     * getConfig
     *
     * Loads module configuration and makes sure it is always a complete array.
     * Prevents "undefined array key" warnings under PHP 8 and guarantees sane
     * defaults even if the stored configuration is missing or corrupted.
     *
     * @access public
     * @return array
     */
    public function getConfig()
    {
        parent::getConfig();

        if (!is_array($this->config)) {
            $this->config = array();
        }

        foreach ($this->defaultConfig as $key => $value) {
            if (!isset($this->config[$key])) {
                $this->config[$key] = $value;
            }
        }

        return $this->config;
    }

    /**
     * Run
     *
     * Description
     *
     * @access public
     */
    function run()
    {
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (isset($this->owner) && isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner) && isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['TAB'] = $this->tab;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }

    /**
     * BackEnd
     *
     * Module backend
     *
     * @access public
     */
    function admin(&$out)
    {
        $this->getConfig();
        $out['START_DAILY'] = (int)$this->config['START_DAILY'];
        $out['START_TIME'] = (int)$this->config['START_TIME'];
        $out['AUTO_OPTIMIZE'] = (int)$this->config['AUTO_OPTIMIZE'];
        $out['KEEP_CACHED'] = (int)$this->config['KEEP_CACHED'];

        if ($this->view_mode == 'update_settings') {
            $start_time = (int)gr('start_time');
            if ($start_time < 0 || $start_time > 23) {
                $start_time = 3;
            }
            $this->config['START_TIME'] = $start_time;
            $this->config['START_DAILY'] = (int)gr('start_daily');
            $this->config['KEEP_CACHED'] = max(0, (int)gr('keep_cached'));
            $this->config['AUTO_OPTIMIZE'] = max(0, (int)gr('auto_optimize'));

            $this->saveConfig();
            $this->redirect("?");
        }

        if (gr('analyze')) {
            $this->analyze($out, (int)$this->config['AUTO_OPTIMIZE'], 0);
        }

        if (isset($this->data_source) && !gr('data_source')) {
            $out['SET_DATASOURCE'] = 1;
        }
        if (!isset($this->data_source) || $this->data_source == 'optimizerdata' || $this->data_source == '') {
            if ($this->view_mode == '' || $this->view_mode == 'search_optimizerdata') {
                $this->search_optimizerdata($out);
            }
            if ($this->view_mode == 'edit_optimizerdata') {
                $this->edit_optimizerdata($out, $this->id);
            }
            if ($this->view_mode == 'delete_optimizerdata') {
                $this->delete_optimizerdata($this->id);
                $this->redirect("?");
            }
        }
    }

    /**
     * Emits a structured progress event to the registered reporter.
     *
     * @param string $event Event name
     * @param array $data Event payload
     * @access private
     */
    private function report($event, $data = array())
    {
        if ($this->progress !== null && is_callable($this->progress)) {
            call_user_func($this->progress, $event, $data);
        }
    }

    /**
     * Free-form diagnostic line.
     *
     * With a reporter attached the line goes to the detailed log pane;
     * without one it falls back to dprint(), as before.
     *
     * @param string $text Message
     * @access private
     */
    private function trace($text)
    {
        if ($this->progress !== null && is_callable($this->progress)) {
            call_user_func($this->progress, 'log', array('text' => $text));
        } else {
            dprint($text, false);
        }
    }

    /**
     * Returns the history table used for a given property value
     *
     * @param int $value_id Property value ID
     * @return string
     * @access private
     */
    private function getHistoryTable($value_id)
    {
        if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
            return createHistoryTable($value_id);
        }
        return 'phistory';
    }

    /**
     * Returns the SQL condition matching history records that no longer have
     * a valid property behind them ("unsorted" data).
     *
     * Implemented as NOT EXISTS instead of "VALUE_ID NOT IN (1,2,3,...)" so that
     * the query does not grow with the number of properties: on an installation
     * with thousands of properties the old query could exceed max_allowed_packet,
     * which made SQLSelectOne() return NULL and crashed the module under PHP 8.
     *
     * @param string $alias Table alias used in the outer query
     * @return string
     * @access private
     */
    private function orphanCondition($alias = 'phistory')
    {
        return "NOT EXISTS (SELECT 1
                              FROM pvalues p
                        INNER JOIN properties pr ON p.PROPERTY_ID = pr.ID
                             WHERE p.ID = $alias.VALUE_ID
                               AND pr.TITLE != '')";
    }

    /**
     * Base query returning all property values having a non-empty property title
     *
     * @return string
     * @access private
     */
    private function propertyValuesQuery()
    {
        return "SELECT pvalues.ID AS VALUE_ID,
                       properties.TITLE AS PTITLE,
                       classes.TITLE AS CTITLE,
                       objects.TITLE AS OTITLE
                  FROM pvalues
             LEFT JOIN objects ON pvalues.OBJECT_ID = objects.ID
             LEFT JOIN classes ON objects.CLASS_ID = classes.ID
            INNER JOIN properties ON pvalues.PROPERTY_ID = properties.ID
                 WHERE properties.TITLE != ''";
    }

    /**
     * Analyze
     *
     * Collects history usage statistics and (optionally) creates optimization
     * rules automatically.
     *
     * @param array $out Output data
     * @param int $total_limit Records threshold to report / auto-create a rule
     * @param int $auto_append Create optimization rules automatically
     * @access public
     */
    function analyze(&$out, $total_limit = 0, $auto_append = 0)
    {
        set_time_limit(0);

        $to_optimize = array();
        $records = array();

        $pvalues = SQLSelect($this->propertyValuesQuery());
        if (!is_array($pvalues)) {
            $pvalues = array();
        }

        $total = count($pvalues);
        $grand_total = 0;

        $this->report('phase', array('text' => 'History analysis', 'total' => $total));

        for ($i = 0; $i < $total; $i++) {
            $value_id = (int)$pvalues[$i]['VALUE_ID'];
            $history_table = $this->getHistoryTable($value_id);

            $this->report('scan', array(
                'index' => $i + 1,
                'total' => $total,
                'title' => $this->valueTitle($pvalues[$i]),
            ));

            $tmp = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM `$history_table` WHERE VALUE_ID = " . $value_id);
            $value_total = is_array($tmp) ? (int)$tmp['TOTAL'] : 0;

            if (!$value_total) {
                continue;
            }

            $grand_total += $value_total;
            $rec = array(
                'CLASS' => $pvalues[$i]['CTITLE'],
                'PROPERTY' => $pvalues[$i]['PTITLE'],
                'OBJECT' => $pvalues[$i]['OTITLE'],
                'TOTAL' => $value_total,
            );

            // exact match by object + property, then by class + property.
            // "=" instead of "LIKE": DbSafe() does not escape the LIKE
            // wildcards % and _, so a title containing them matched foreign rules.
            $opt_rec = SQLSelectOne("SELECT ID FROM optimizerdata
                                      WHERE PROPERTY_NAME = '" . DBSafe($pvalues[$i]['PTITLE']) . "'
                                        AND OBJECT_NAME = '" . DBSafe($pvalues[$i]['OTITLE']) . "'");
            if (!isset($opt_rec['ID'])) {
                $opt_rec = SQLSelectOne("SELECT ID FROM optimizerdata
                                          WHERE PROPERTY_NAME = '" . DBSafe($pvalues[$i]['PTITLE']) . "'
                                            AND OBJECT_NAME = ''
                                            AND CLASS_NAME = '" . DBSafe($pvalues[$i]['CTITLE']) . "'");
            }

            if (isset($opt_rec['ID'])) {
                $rec['OPTIMIZE_NOW'] = $opt_rec['ID'];
            } elseif ($total_limit > 0 && $value_total > $total_limit) {
                $rec['WARNING'] = 1;
                if ($auto_append == 1) {
                    $to_optimize[] = $rec;
                }
            }

            $records[] = $rec;
        }

        foreach ($to_optimize as $optimize_rec) {
            // do not create a duplicate rule (two objects may share a title)
            $exists = SQLSelectOne("SELECT ID FROM optimizerdata
                                     WHERE PROPERTY_NAME = '" . DBSafe($optimize_rec['PROPERTY']) . "'
                                       AND OBJECT_NAME = '" . DBSafe($optimize_rec['OBJECT']) . "'");
            if (isset($exists['ID'])) {
                continue;
            }
            $opt_rec = array();
            $opt_rec['CLASS_NAME'] = $optimize_rec['CLASS'];
            $opt_rec['OBJECT_NAME'] = $optimize_rec['OBJECT'];
            $opt_rec['PROPERTY_NAME'] = $optimize_rec['PROPERTY'];
            $opt_rec['OPTIMIZE'] = 'avg';
            SQLInsert('optimizerdata', $opt_rec);
            $this->report('rule', array(
                'title' => $optimize_rec['OBJECT'] . '.' . $optimize_rec['PROPERTY'],
                'total' => $optimize_rec['TOTAL'],
            ));
        }

        // "unsorted" records left in the shared history table
        if (!defined('SEPARATE_HISTORY_STORAGE') || SEPARATE_HISTORY_STORAGE == 0) {
            $unsortedData = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM phistory WHERE " . $this->orphanCondition('phistory'));
            $unsorted = is_array($unsortedData) ? (int)$unsortedData['TOTAL'] : 0;
            if ($unsorted > 0) {
                $records[] = array('CLASS' => 'Unknown', 'PROPERTY' => 'Unknown', 'OBJECT' => '', 'TOTAL' => $unsorted);
                $grand_total += $unsorted;
            }
        }

        usort($records, function ($a, $b) {
            if ($a['TOTAL'] == $b['TOTAL']) {
                return 0;
            }
            return ($a['TOTAL'] > $b['TOTAL']) ? -1 : 1;
        });

        $out['RECORDS'] = $records;
        $out['GRAND_TOTAL'] = $grand_total;

        $this->report('analyzed', array(
            'properties' => count($records),
            'records' => $grand_total,
            'rules_added' => count($to_optimize),
        ));
    }

    /**
     * Human readable "Object.property" label for a pvalues row
     *
     * @param array $row Row of propertyValuesQuery()
     * @return string
     * @access private
     */
    private function valueTitle($row)
    {
        $object = isset($row['OTITLE']) && $row['OTITLE'] !== null && $row['OTITLE'] !== ''
            ? $row['OTITLE'] : '?';
        $property = isset($row['PTITLE']) ? $row['PTITLE'] : '';
        return $object . '.' . $property;
    }

    /**
     * FrontEnd
     *
     * Module frontend
     *
     * @access public
     */
    function usual(&$out)
    {
        $this->admin($out);
    }

    /**
     * optimizerdata search
     *
     * @access public
     */
    function search_optimizerdata(&$out)
    {
        require(DIR_MODULES . $this->name . '/optimizerdata_search.inc.php');
    }

    /**
     * optimizerdata edit/add
     *
     * @access public
     */
    function edit_optimizerdata(&$out, $id)
    {
        require(DIR_MODULES . $this->name . '/optimizerdata_edit.inc.php');
    }

    /**
     * Removes history records that have no property behind them anymore
     *
     * @return int Number of removed records
     * @access private
     */
    private function removeUnsortedData()
    {
        if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
            return 0;
        }

        $this->report('phase', array('text' => 'Clearing orphaned history records'));
        $this->trace("Se searching for orphaned history records.");

        $tmp = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM phistory WHERE " . $this->orphanCondition('phistory'));
        $total = is_array($tmp) ? (int)$tmp['TOTAL'] : 0;

        $this->trace("Orphaned history records found: $total");

        if ($total > 0) {
            SQLExec("DELETE FROM phistory WHERE " . $this->orphanCondition('phistory'));
            $this->trace("Orphaned history records deleted");
            DebMes('Total unsorted: ' . $total . ' deleted', 'optimizer');
            $this->tablesToOptimize['phistory'] = 1;
        }

        return $total;
    }

    /**
     * optimizeAll
     *
     * Applies every optimization rule to the property history.
     *
     * @param int $id Optional optimizerdata record ID (apply a single rule)
     * @param string $object Optional object title filter
     * @param string $property Optional property title filter
     * @return int Number of removed records
     * @access public
     */
    function optimizeAll($id = 0, $object = '', $property = '')
    {
        DebMes('Starting optimization procedure', 'optimizer');
        set_time_limit(0);

        $this->getConfig();
        $this->tablesToOptimize = array();

        $id = (int)$id;

        if ($id) {
            $records = SQLSelect("SELECT * FROM optimizerdata WHERE ID = " . $id);
        } else {
            $this->removeUnsortedData();
            $records = SQLSelect("SELECT * FROM optimizerdata");
        }

        if (!is_array($records)) {
            $records = array();
        }

        $rules = array();
        $total = count($records);
        for ($i = 0; $i < $total; $i++) {
            if ($records[$i]['OBJECT_NAME'] && $records[$i]['OBJECT_NAME'] != '*') {
                $key = $records[$i]['OBJECT_NAME'] . '.' . $records[$i]['PROPERTY_NAME'];
            } elseif ($records[$i]['CLASS_NAME'] && $records[$i]['CLASS_NAME'] != '*') {
                $key = $records[$i]['CLASS_NAME'] . '.' . $records[$i]['PROPERTY_NAME'];
            } else {
                $key = $records[$i]['PROPERTY_NAME'];
            }
            $rules[$key] = array('optimize' => $records[$i]['OPTIMIZE']);
            if ((int)$records[$i]['KEEP'] > 0) {
                $rules[$key]['keep'] = (int)$records[$i]['KEEP'];
            }
        }

        if (!count($rules)) {
            DebMes('No optimization rules defined', 'optimizer');
            $this->report('warning', array('text' => 'No optimization rules defined'));
        }

        // STEP 2 -- optimize values in time
        $sqlQuery = $this->propertyValuesQuery();
        if ($object != '' && $property != '') {
            // both values come from the request, they must be escaped
            $sqlQuery .= " AND properties.TITLE = '" . DBSafe($property) . "'
                           AND objects.TITLE = '" . DBSafe($object) . "'";
        }

        $values = SQLSelect($sqlQuery);
        if (!is_array($values)) {
            $values = array();
        }

        $total_records_removed = 0;
        $total = count($values);
        $changed_properties = 0;

        $this->report('phase', array('text' => 'Optimizing history', 'total' => $total));

        for ($i = 0; $i < $total; $i++) {
            $this->report('scan', array(
                'index' => $i + 1,
                'total' => $total,
                'title' => $this->valueTitle($values[$i]),
            ));

            if ($values[$i]['CTITLE'] === null || $values[$i]['CTITLE'] === '') {
                continue;
            }

            // rule lookup: object.property -> class.property -> property
            $key = $values[$i]['OTITLE'] . '.' . $values[$i]['PTITLE'];
            if (!isset($rules[$key])) {
                $key = $values[$i]['CTITLE'] . '.' . $values[$i]['PTITLE'];
            }
            if (!isset($rules[$key])) {
                $key = $values[$i]['PTITLE'];
            }
            if (!isset($rules[$key])) {
                continue;
            }

            $rule = $rules[$key];
            $value_id = (int)$values[$i]['VALUE_ID'];
            $history_table = $this->getHistoryTable($value_id);
            $item_title = $this->valueTitle($values[$i]);

            $this->trace('Property ' . $item_title . ' (rule: ' . $key . ')');
            DebMes('Processing ' . $values[$i]['OTITLE'] . " (" . $key . ")", 'optimizer');

            $tmp = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM `$history_table` WHERE VALUE_ID = " . $value_id);
            $total_before = is_array($tmp) ? (int)$tmp['TOTAL'] : 0;
            DebMes('Before optimizing: ' . $total_before, 'optimizer');

            if ($total_before == 0) {
                continue;
            }

            $this->report('item', array(
                'index' => $i + 1,
                'total' => $total,
                'title' => $item_title,
                'rule' => $key,
                'mode' => $rule['optimize'],
                'keep' => isset($rule['keep']) ? (int)$rule['keep'] : 0,
                'before' => $total_before,
            ));

            if (isset($rule['keep'])) {
                $this->trace("  removing records older than " . (int)$rule['keep'] . " days");
                DebMes(" removing old (" . (int)$rule['keep'] . ")", 'optimizer');
                SQLExec("DELETE FROM `$history_table`
                          WHERE VALUE_ID = " . $value_id . "
                            AND TO_DAYS(NOW()) - TO_DAYS(ADDED) >= " . (int)$rule['keep']);
                $this->tablesToOptimize[$history_table] = 1;
            }

            if (!empty($rule['optimize'])) {
                $tmp = SQLSelectOne("SELECT UNIX_TIMESTAMP(ADDED) AS TS
                                       FROM `$history_table`
                                      WHERE VALUE_ID = " . $value_id . "
                                        AND ADDED IS NOT NULL
                                   ORDER BY ADDED, ID
                                      LIMIT 1");

                if (!is_array($tmp) || $tmp['TS'] === null) {
                    $this->trace("  no data, skipping");
                    continue;
                }

                $start = (int)$tmp['TS'];

                $this->trace("  period: older than a month");
                $end = time() - 30 * 24 * 60 * 60; // month and older
                $interval = 2 * 60 * 60;           // two-hours interval
                $this->optimizeHistoryData($value_id, $rule['optimize'], $interval, $start, $end);

                $this->trace("  period: older than a week");
                $start = $end + 1;
                $end = time() - 7 * 24 * 60 * 60;  // week and older
                $interval = 1 * 60 * 60;           // one-hour interval
                $this->optimizeHistoryData($value_id, $rule['optimize'], $interval, $start, $end);

                $this->trace("  period: older than a day");
                $start = $end + 1;
                $end = time() - 1 * 24 * 60 * 60;  // day and older
                $interval = 20 * 60;               // 20 minutes interval
                $this->optimizeHistoryData($value_id, $rule['optimize'], $interval, $start, $end);

                $this->trace("  period: older than an hour");
                $start = $end + 1;
                $end = time() - 1 * 60 * 60;       // 1 hour and older
                $interval = 3 * 60;                // 3 minutes interval
                $this->optimizeHistoryData($value_id, $rule['optimize'], $interval, $start, $end);
            }

            // count what is left in the SAME table the data was read from
            $tmp = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM `$history_table` WHERE VALUE_ID = " . $value_id);
            $total_after = is_array($tmp) ? (int)$tmp['TOTAL'] : 0;

            $this->trace("  total: " . $total_before . " -> " . $total_after);
            $total_records_removed += ($total_before - $total_after);
            DebMes('After optimizing: ' . $total_after, 'optimizer');

            if ($total_before != $total_after) {
                $changed_properties++;
            }

            $this->report('item_done', array(
                'index' => $i + 1,
                'total' => $total,
                'title' => $item_title,
                'mode' => $rule['optimize'],
                'before' => $total_before,
                'after' => $total_after,
                'removed' => $total_before - $total_after,
            ));
        }

        DebMes('Optimization done. Total removed: ' . $total_records_removed, 'optimizer');

        // defragment every touched table exactly once
        $this->report('phase', array(
            'text' => 'Tables defragmentation',
            'total' => count($this->tablesToOptimize),
        ));
        foreach (array_keys($this->tablesToOptimize) as $table) {
            $this->trace("Defgragmenting " . $table);
            SQLExec("OPTIMIZE TABLE `" . $table . "`");
        }
        $this->tablesToOptimize = array();

        $this->report('phase', array('text' => 'Service cleanup'));
        $this->trace("Removing old messages");
        SQLExec("DELETE FROM shouts WHERE TO_DAYS(NOW())-TO_DAYS(ADDED)>7");

        $keep_cached = (int)$this->config['KEEP_CACHED'];
        if ($keep_cached > 0) {
            $this->trace("Removing old cached files");
            $deleted = 0;
            $cache_dir = ROOT . 'cms/cached';
            if (is_dir($cache_dir)) {
                $result = array();
                getDirTree($cache_dir, $result);
                $files_total = count($result);
                for ($i = 0; $i < $files_total; $i++) {
                    if ((time() - $result[$i]['TM']) > $keep_cached * 24 * 60 * 60) {
                        $deleted++;
                        @unlink($result[$i]['FILENAME']);
                    }
                }
            }
            $this->trace("Cached files removed: " . $deleted);
        }

        $this->trace("Done. Total removed: " . $total_records_removed);

        $this->report('done', array(
            'removed' => $total_records_removed,
            'properties' => $total,
            'changed' => $changed_properties,
        ));

        return $total_records_removed;
    }

    /**
     * Aggregates one value at a given time resolution.
     *
     * @param int $valueID Property value ID
     * @param string $type Aggregation type: avg, max, min, sum, last
     * @param int $interval Aggregation interval, seconds
     * @param int $start Period begin, unix timestamp
     * @param int $end Period end, unix timestamp
     * @return int Number of removed records
     * @access public
     */
    function optimizeHistoryData($valueID, $type, $interval, $start, $end)
    {
        $totalRemoved = 0;

        $valueID = (int)$valueID;
        $interval = (int)$interval;
        $start = (int)$start;
        $end = (int)$end;

        if ($interval <= 0 || $start >= $end) {
            return 0;
        }

        $beginDate = date('Y-m-d H:i:s', $start);
        $endDate = date('Y-m-d H:i:s', $end);

        $history_table = $this->getHistoryTable($valueID);


        $tmp = SQLSelectOne("SELECT COUNT(*) AS TOTAL
                               FROM `$history_table`
                              WHERE VALUE_ID = " . $valueID . "
                                AND ADDED >= '" . $beginDate . "'
                                AND ADDED <= '" . $endDate . "'");
        $totalValues = is_array($tmp) ? (int)$tmp['TOTAL'] : 0;

        $this->trace("    records for period: " . $totalValues);

        if ($totalValues < 2) {
            return 0;
        }

        $optimalValues = (int)round(($end - $start) / $interval);

        if ($totalValues <= ($optimalValues + 50)) {
            $this->trace("    already optimal (" . $totalValues . " ~ " . $optimalValues . "), skipping");
            return 0;
        }


        $this->report('stage', array(
            'from' => $beginDate,
            'to' => $endDate,
            'interval' => $interval,
            'values' => $totalValues,
            'target' => $optimalValues,
        ));

        $tmp = SQLSelectOne("SELECT UNIX_TIMESTAMP(ADDED) AS TS
                               FROM `$history_table`
                              WHERE VALUE_ID = " . $valueID . "
                                AND ADDED >= '" . $beginDate . "'
                           ORDER BY ADDED, ID
                              LIMIT 1");
        if (!is_array($tmp) || $tmp['TS'] === null) {
            return 0;
        }
        $firstStart = (int)$tmp['TS'];

        $tmp = SQLSelectOne("SELECT UNIX_TIMESTAMP(ADDED) AS TS
                               FROM `$history_table`
                              WHERE VALUE_ID = " . $valueID . "
                                AND ADDED <= '" . $endDate . "'
                           ORDER BY ADDED DESC, ID DESC
                              LIMIT 1");
        if (!is_array($tmp) || $tmp['TS'] === null) {
            return 0;
        }
        $lastStart = (int)$tmp['TS'];

        while ($start < $end) {
            if ($start < ($firstStart - $interval) || $start > ($lastStart + $interval)) {
                $start += $interval;
                continue;
            }

            $intervalBegin = date('Y-m-d H:i:s', $start);
            $intervalEnd = date('Y-m-d H:i:s', $start + $interval);

            // ORDER BY is required: without it "the latest value of the interval"
            // is undefined and the result is not reproducible.
            $data = SQLSelect("SELECT ID, VALUE
                                 FROM `$history_table`
                                WHERE VALUE_ID = " . $valueID . "
                                  AND ADDED >= '" . $intervalBegin . "'
                                  AND ADDED <  '" . $intervalEnd . "'
                             ORDER BY ADDED, ID");

            if (!is_array($data)) {
                $data = array();
            }
            $total = count($data);

            if ($total > 1) {
                $newValue = $this->aggregateValues($data, $type);

                if ($newValue === null) {
                    // nothing sensible can be calculated - leave the data alone
                    $start += $interval;
                    continue;
                }

                SQLExec("DELETE FROM `$history_table`
                          WHERE VALUE_ID = " . $valueID . "
                            AND ADDED >= '" . $intervalBegin . "'
                            AND ADDED <  '" . $intervalEnd . "'");

                $addedDate = ($type == 'avg') ? $start + (int)($interval / 2) : $start + $interval - 1;

                $rec = array();
                $rec['VALUE_ID'] = $valueID;
                $rec['VALUE'] = $newValue;
                $rec['ADDED'] = date('Y-m-d H:i:s', $addedDate);

                SQLInsert($history_table, $rec);

                // OPTIMIZE TABLE rebuilds the whole table; it is scheduled for
                // the end of the run instead of being executed on every insert.
                $this->tablesToOptimize[$history_table] = 1;

                $totalRemoved += ($total - 1);
            }

            $start += $interval;
        }

        $this->trace("    removed: $totalRemoved");
        if ($totalRemoved > 0) {
            // one log line per stage that actually did something,
            // instead of four lines per property regardless of the result
            DebMes("Interval " . $beginDate . " .. " . $endDate
                . " (every " . $interval . "s): removed " . $totalRemoved, 'optimizer');
        }
        $this->report('stage_done', array('removed' => $totalRemoved));

        return $totalRemoved;
    }

    /**
     * Calculates the replacement value for one aggregation interval.
     *
     * Non-numeric series (states like on/off, text, etc.) are never fed into
     * arithmetic: the latest value of the interval is kept instead, so such
     * history is compacted without being destroyed.
     *
     * @param array $data Records of the interval, ordered by time
     * @param string $type Aggregation type
     * @return string|float|int|null NULL means "do not touch this interval"
     * @access private
     */
    private function aggregateValues($data, $type)
    {
        $total = count($data);
        if (!$total) {
            return null;
        }

        $values = array();
        $numeric = true;
        for ($i = 0; $i < $total; $i++) {
            $values[] = $data[$i]['VALUE'];
            if (!is_numeric($data[$i]['VALUE'])) {
                $numeric = false;
            }
        }

        if ($type == 'last' || !$numeric) {
            return $values[$total - 1];
        }

        $numbers = array_map('floatval', $values);

        if ($type == 'max' || $type == 'min') {
            $target = ($type == 'max') ? max($numbers) : min($numbers);
            $index = array_search($target, $numbers, true);
            // keep the original textual representation of the picked record
            return ($index === false) ? $target : $values[$index];
        }

        if ($type == 'sum') {
            return round(array_sum($numbers), 4);
        }

        // default: avg
        return round(array_sum($numbers) / $total, 4);
    }

    /**
     * Scheduled tasks handler
     *
     * @param string $event_name Event name
     * @param string $details Event details
     * @access public
     */
    function processSubscription($event_name, $details = '')
    {
        if ($event_name == 'HOURLY') {
            $this->getConfig();
            if ($this->config['START_DAILY'] && ((int)date('H')) == ((int)$this->config['START_TIME'])) {
                if (defined('PATH_TO_PHP')) {
                    $phpPath = PATH_TO_PHP;
                } else {
                    $phpPath = IsWindowsOS() ? '..\server\php\php.exe' : 'php';
                }
                safe_exec($phpPath . ' ' . dirname(__FILE__) . '/optimize.php');
            }
        }
    }

    /**
     * optimizerdata delete record
     *
     * @access public
     */
    function delete_optimizerdata($id)
    {
        $id = (int)$id;
        if (!$id) {
            return;
        }
        SQLExec("DELETE FROM optimizerdata WHERE ID = " . $id);
    }

    /**
     * Install
     *
     * Module installation routine
     *
     * @access public
     */
    function install($data = '')
    {
        // The module has to be registered in project_modules FIRST:
        // saveConfig() updates that very record, so saving the configuration
        // before parent::install() silently discarded all default settings.
        parent::install();

        subscribeToEvent($this->name, 'HOURLY');

        $this->getConfig();
        $this->saveConfig();
    }

    /**
     * Uninstall
     *
     * Module uninstall routine
     *
     * @access public
     */
    function uninstall()
    {
        SQLExec('DROP TABLE IF EXISTS optimizerdata');
        parent::uninstall();
    }

    /**
     * dbInstall
     *
     * Database installation routine
     *
     * @access public
     */
    function dbInstall($data = '')
    {
        /*
        optimizerdata -
        */
        $data = <<<EOD
 optimizerdata: ID int(10) unsigned NOT NULL auto_increment
 optimizerdata: CLASS_NAME varchar(255) NOT NULL DEFAULT ''
 optimizerdata: OBJECT_NAME varchar(255) NOT NULL DEFAULT ''
 optimizerdata: PROPERTY_NAME varchar(255) NOT NULL DEFAULT ''
 optimizerdata: KEEP varchar(255) NOT NULL DEFAULT ''
 optimizerdata: OPTIMIZE varchar(255) NOT NULL DEFAULT ''
 optimizerdata: LOG varchar(255) NOT NULL DEFAULT ''
EOD;
        parent::dbInstall($data);
    }
// --------------------------------------------------------------------
}
/*
*
* TW9kdWxlIGNyZWF0ZWQgRmViIDI2LCAyMDE2IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
