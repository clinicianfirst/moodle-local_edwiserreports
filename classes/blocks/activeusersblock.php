<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * Reports abstract block will define here to which will extend for each repoers blocks
 *
 * @package     local_edwiserreports
 * @copyright   2019 wisdmlabs <support@wisdmlabs.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edwiserreports;

defined('MOODLE_INTERNAL') or die;
use stdClass;
use moodle_url;
use cache;
use html_writer;
use html_table;
use core_user;

require_once($CFG->dirroot . '/local/edwiserreports/classes/block_base.php');

class activeusersblock extends block_base {
    // Get the first site access data.
    public $firstaccess;

    // Current time.
    public $timenow;

    // Active users block labels.
    public $labels;

    // No. of labels for active users.
    public $xlabelcount;

    // Cache.
    public $cache;

    /**
     * Preapre layout for each block
     */
    public function get_layout() {
        global $CFG;

        // Layout related data.
        $this->layout->id = 'activeusersblock';
        $this->layout->name = get_string('activeusersheader', 'local_edwiserreports');
        $this->layout->info = get_string('activeusersblocktitlehelp', 'local_edwiserreports');
        $this->layout->morelink = new moodle_url($CFG->wwwroot . "/local/edwiserreports/activeusers.php");
        $this->layout->hasdownloadlink = true;
        $this->layout->filters = $this->get_activeusers_filter();

        // Selected default filters.
        $this->layout->filter = 'weekly';
        $this->layout->cohortid = '0';

        // Block related data.
        $this->block = new stdClass();
        $this->block->displaytype = 'line-chart';

        // Add block view in layout.
        $this->layout->blockview = $this->render_block('activeusersblock', $this->block);

        // Set block edit capabilities.
        $this->set_block_edit_capabilities($this->layout->id);

        // Return blocks layout.
        return $this->layout;
    }

    /**
     * Prepare active users block filters
     */
    public function get_activeusers_filter() {
        // Add last updated text in header.
        $lastupdatetext = html_writer::start_tag('small', array(
            'id' => 'updated-time',
            'class' => 'font-size-12'
        ));
        $lastupdatetext .= get_string('lastupdate', 'local_edwiserreports');
        $lastupdatetext .= html_writer::tag('i', '', array(
            'class' => 'refresh fa fa-refresh px-1',
            'data-toggle' => 'tooltip',
            'title' => get_string('refresh', 'local_edwiserreports')
        ));
        $lastupdatetext .= html_writer::end_tag('small');

        // Prepare filter HTML for active users block.
        $filterhtml = html_writer::start_tag('form', array("action" => "javascript:void(0)"));
        $filterhtml .= html_writer::start_tag('div', array('class' => 'd-flex mt-1'));
        $filterhtml .= html_writer::tag('button', get_string('lastweek', 'local_edwiserreports'), array(
            'type' => 'button',
            'class' => 'btn btn-sm dropdown-toggle',
            'data-toggle' => 'dropdown',
            'id' => 'filter-dropdown',
            'aria-expanded' => 'false'
        ));
        $filterhtml .= html_writer::start_tag('div', array(
            'class' => 'dropdown-menu',
            'aria-labelledby' => 'filter-dropdown',
            'role' => 'menu'
        ));
        $filterhtml .= html_writer::tag('div', '', array(
            'id' => 'activeUser-calendar',
            'class' => 'dropdown-calendar'
        ));
        $filterhtml .= html_writer::start_tag('div', array('class' => 'dropdown-body'));

        // Prepare filter link.
        $datefilter = html_writer::empty_tag('input', array(
            'class' => 'dropdown-item border-0 custom p-0',
            'id' => 'flatpickrCalender',
            'placeholder' => get_string('custom', 'local_edwiserreports'),
            'data-input'
        ));
        $filteropt = array(
            'weekly' => array(
                'name' => get_string('lastweek', 'local_edwiserreports'),
                'value' => 'weekly',
                'classes' => ''
            ),
            'monthly' => array(
                'name' => get_string('lastmonth', 'local_edwiserreports'),
                'value' => 'monthly',
                'classes' => ''
            ),
            'yearly' => array(
                'name' => get_string('lastyear', 'local_edwiserreports'),
                'value' => 'yearly',
                'classes' => ''
            ),
            'custom' => array(
                'name' => $datefilter,
                'value' => 'custom',
                'classes' => 'custom'
            )
        );

        // Prepare dropdown items for active users filter.
        foreach ($filteropt as $key => $value) {
            $filterhtml .= html_writer::link('javascript:void(0)', $value['name'], array(
                'class' => 'dropdown-item',
                'role' => 'menuitem',
                'value' => $value['value']
            ));
        }

        // End tags.
        $filterhtml .= html_writer::end_tag('div');
        $filterhtml .= html_writer::end_tag('div');
        $filterhtml .= html_writer::end_tag('div');
        $filterhtml .= html_writer::end_tag('form');

        // Create filter for active users block.
        $filters = html_writer::start_tag('div');
        $filters .= html_writer::tag('div', $lastupdatetext);
        $filters .= html_writer::start_tag('div');
        $filters .= $filterhtml;
        $filters .= html_writer::end_tag('div');
        $filters .= html_writer::end_tag('div');

        return $filters;
    }

    /**
     * Constructor
     * @param [string] $filter Range selector
     */
    public function generate_labels($filter) {
        global $DB;

        // Set current time.
        $this->timenow = time();
        // Set cache for active users block.
        $this->cache = cache::make('local_edwiserreports', 'activeusers');
        // Get logs from cache.
        if (!$record = $this->cache->get("activeusers-first-log")) {
            $sql = "SELECT id, userid, timecreated
                FROM {logstore_standard_log}
                ORDER BY timecreated ASC LIMIT 1";
            $record = $DB->get_record_sql($sql);

            // Set cache if log is not available.
            $this->cache->set("activeusers-first-log", $record);
        }

        $cachekey = "activeusers-labels-" . $filter;
        if ($record) {
            // Getting first access of the site.
            $this->firstaccess = $record->timecreated;
            switch ($filter) {
                case LOCAL_SITEREPORT_ALL:
                    // Calculate the days for all active users data.
                    $days = ceil(($this->timenow - $this->firstaccess) / LOCAL_SITEREPORT_ONEDAY);
                    $this->xlabelcount = $days;
                    break;
                case LOCAL_SITEREPORT_MONTHLY:
                    // Monthly days.
                    $this->xlabelcount = LOCAL_SITEREPORT_MONTHLY_DAYS;
                    break;
                case LOCAL_SITEREPORT_YEARLY:
                    // Yearly days.
                    $this->xlabelcount = LOCAL_SITEREPORT_YEARLY_DAYS;
                    break;
                case LOCAL_SITEREPORT_WEEKLY:
                    // Weekly days.
                    $this->xlabelcount = LOCAL_SITEREPORT_WEEKLY_DAYS;
                    break;
                default:
                    // Explode dates from custom date filter.
                    $dates = explode(" to ", $filter);
                    if (count($dates) == 2) {
                        $startdate = strtotime($dates[0]." 00:00:00");
                        $enddate = strtotime($dates[1]." 23:59:59");
                    }

                    // If it has correct startdat and end date then count xlabel.
                    if (isset($startdate) && isset($enddate)) {
                        $days = ceil($enddate - $startdate) / LOCAL_SITEREPORT_ONEDAY;
                        $this->xlabelcount = $days;
                        $this->timenow = $enddate;
                    } else {
                        $this->xlabelcount = LOCAL_SITEREPORT_WEEKLY_DAYS; // Default one week.
                    }
            }
        } else {
            // If no record fonud then current time is first access time.
            $this->firstaccess = $this->timenow;
        }

        // Get labels from cache if exist.
        if ($this->cache->get($cachekey)) {
            $this->labels = $this->cache->get($cachekey);
        } else {
            // Get all lables.
            for ($i = 0; $i < $this->xlabelcount; $i++) {
                $time = $this->timenow - $i * LOCAL_SITEREPORT_ONEDAY;
                $this->labels[] = date("d M y", $time);
            }

            // If cache is not set then set cache for labels.
            if (isset($cachekey)) {
                $this->cache->set($cachekey, $this->labels);
            }
        }

        // Reverse the labels to show in graph from right to left.
        $this->labels = array_reverse($this->labels);
    }

    /**
     * Get active user, enrolment, completion
     * @param  object $params date filter to get data
     * @return stdClass active users graph data
     */
    public function get_data($params = false) {
        // Get data from params.
        $filter = isset($params->filter) ? $params->filter : false;
        $cohortid = isset($params->cohortid) ? $params->cohortid : false;

        // Generate active users data label.
        $this->generate_labels($filter);

        // Get cache key.
        $cachekey = "activeusers-response" . $filter . "-";

        if ($cohortid) {
            $cachekey .= $cohortid;
        } else {
            $cachekey .= "all";
        }

        // If response is in cache then return from cache.
        if (!$response = $this->cache->get($cachekey)) {
            $response = new stdClass();
            $response->data = new stdClass();

            $response->data->activeUsers = $this->get_active_users($filter, $cohortid);
            $response->data->enrolments = $this->get_enrolments($filter, $cohortid);
            $response->data->completionRate = $this->get_course_completionrate($filter, $cohortid);
            $response->labels = $this->labels;

            // Set response in cache.
            $this->cache->set($cachekey, $response);
        }

        return $response;
    }

    /**
     * Get header for export data actvive users
     * @return [array] Array of headers of exportable data
     */
    public static function get_header() {
        $header = array(
            get_string("date", "local_edwiserreports"),
            get_string("noofactiveusers", "local_edwiserreports"),
            get_string("noofenrolledusers", "local_edwiserreports"),
            get_string("noofcompletedusers", "local_edwiserreports"),
        );

        return $header;
    }

    /**
     * Get header for export data actvive users individual page
     * @return [array] Array of headers of exportable data
     */
    public static function get_header_report() {
        $header = array(
            get_string("date", "local_edwiserreports"),
            get_string("fullname", "local_edwiserreports"),
            get_string("email", "local_edwiserreports"),
            get_string("coursename", "local_edwiserreports"),
            get_string("status", "local_edwiserreports"),
        );

        return $header;
    }

    /**
     * Get popup modal header by action
     * @param  [string] $action Action name
     * @return [array] Table header
     */
    public static function get_modal_table_header($action) {
        switch($action) {
            case 'completions':
                // Return table header.
                $str = array(
                    get_string("fullname", "local_edwiserreports"),
                    get_string("email", "local_edwiserreports"),
                    get_string("coursename", "local_edwiserreports")
                );
                break;
            case 'enrolments':
                // Return table header.
                $str = array(
                    get_string("fullname", "local_edwiserreports"),
                    get_string("email", "local_edwiserreports"),
                    get_string("coursename", "local_edwiserreports")
                );
                break;
            default:
                // Return table header.
                $str = array(
                    get_string("fullname", "local_edwiserreports"),
                    get_string("email", "local_edwiserreports")
                );
        }

        return $str;
    }

    /**
     * Create users list table for active users block
     * @param [string] $filter Time filter to get users for this day
     * @param [string] $action Get users list for this action
     * @param [int] $cohortid Get users list for this action
     * @return [array] Array of users data fields (Full Name, Email)
     */
    public static function get_userslist_table($filter, $action, $cohortid) {
        // Make cache.
        $cache = cache::make('local_edwiserreports', 'activeusers');
        // Get values from cache if it is set.
        $cachekey = "userslist-" . $filter . "-" . $action . "-" . "-" . $cohortid;
        if (!$table = $cache->get($cachekey)) {
            $table = new html_table();

            // Get table header.
            $table->head = self::get_modal_table_header($action);

            // Set table attributes.
            $table->attributes = array (
                "class" => "modal-table table",
                "style" => "min-width: 100%;"
            );

            // Get Users data.
            $data = self::get_userslist($filter, $action, $cohortid);

            // Set table cell.
            if (!empty($data)) {
                $table->data = $data;
            }

            // Set cache for users list.
            $cache->set($cachekey, $table);
        }

        return html_writer::table($table);
    }

    /**
     * Get users list data for active users block
     * @param [string] $filter Time filter to get users for this day
     * @param [string] $action Get users list for this action
     * @param [int] $cohortid Cohort Id
     * @return [string] HTML table string of users list
     * Columns are (Full Name, Email)
     */
    public static function get_userslist($filter, $action, $cohortid = false) {
        global $DB;
        // If cohort ID is there then add cohort filter in sqlquery.
        $sqlcohort = "";
        $cohortcondition = "";
        if ($cohortid) {
            $sqlcohort .= " JOIN {cohort_members} cm
                   ON cm.userid = l.relateduserid";
            $cohortcondition = "AND cm.cohortid = :cohortid";
            $params["cohortid"] = $cohortid;
        }
        // Based on action prepare query.
        switch($action) {
            case "activeusers":
                $sql = "SELECT DISTINCT l.userid as relateduserid
                   FROM {logstore_standard_log} l $sqlcohort
                   WHERE l.timecreated >= :starttime
                   AND l.timecreated < :endtime
                   AND l.action = :action
                   AND l.userid > 1 $cohortcondition";
                $params["action"] = 'viewed';
                break;
            case "enrolments":
                $sql = "SELECT l.id, l.userid, l.relateduserid, l.courseid
                   FROM {logstore_standard_log} l $sqlcohort
                   WHERE l.timecreated >= :starttime
                   AND l.timecreated < :endtime
                   AND l.eventname = :eventname $cohortcondition";
                $params["eventname"] = '\core\event\user_enrolment_created';
                break;
            case "completions";
                $sqlcohort = "";
                if ($cohortid) {
                    $sqlcohort .= " JOIN {cohort_members} cm
                           ON cm.userid = l.userid";
                    $params["cohortid"] = $cohortid;
                }
                $sql = "SELECT CONCAT(l.userid, '-', l.courseid) as id, l.userid as relateduserid, l.courseid as courseid
                   FROM {edwreports_course_progress} l $sqlcohort
                   WHERE l.completiontime IS NOT NULL
                   AND l.completiontime >= :starttime
                   AND l.completiontime < :endtime";
        }

        $params["starttime"] = $filter;
        $params["endtime"] = $filter + LOCAL_SITEREPORT_ONEDAY;
        $data = array();
        $records = $DB->get_records_sql($sql, $params);
        if (!empty($records)) {
            foreach ($records as $record) {
                $user = core_user::get_user($record->relateduserid);
                $userdata = new stdClass();
                $userdata->username = fullname($user);
                $userdata->useremail = $user->email;
                if ($action == "completions" || $action == "enrolments") {
                    $course = get_course($record->courseid);
                    $userdata->coursename = $course->fullname;
                }
                $data[] = array_values((array)$userdata);
            }
        }

        return $data;
    }

    /**
     * Get all active users
     * @param [string] $filter Duration String
     * @param [int] $cohortid Cohort ID
     * @return array Array of all active users based
     */
    public function get_active_users($filter, $cohortid) {
        global $DB;

        $starttime = $this->timenow - ($this->xlabelcount * LOCAL_SITEREPORT_ONEDAY);
        $params = array(
            "starttime" => $starttime,
            "endtime" => $this->timenow,
            "action" => "viewed"
        );
        // Query to get activeusers from logs.
        $cachekey = "activeusers-activeusers-" . $filter . "-all";
        $cohortjoin = "";
        $cohortcondition = "";
        if ($cohortid) {
            $cachekey = "activeusers-activeusers-" . $filter . "-" . $cohortid;
            $cohortjoin = "JOIN {cohort_members} cm ON l.userid = cm.userid";
            $cohortcondition = "AND cm.cohortid = :cohortid";
            $params["cohortid"] = $cohortid;
        }
        $sql = "SELECT
            CONCAT(
                DAY(FROM_UNIXTIME(l.timecreated)), '-',
                MONTH(FROM_UNIXTIME(l.timecreated)), '-',
                YEAR(FROM_UNIXTIME(l.timecreated))
            ) USERDATE,
            COUNT( DISTINCT l.userid ) as usercount
            FROM {logstore_standard_log} l "
            . $cohortjoin .
            " WHERE l.action = :action "
            . $cohortcondition .
            " AND l.timecreated >= :starttime
            AND l.timecreated < :endtime
            AND l.userid > 1
            GROUP BY YEAR(FROM_UNIXTIME(l.timecreated)),
            MONTH(FROM_UNIXTIME(l.timecreated)),
            DAY(FROM_UNIXTIME(l.timecreated)), USERDATE";

        // Get active users data from cache.
        if (!$activeusers = $this->cache->get($cachekey)) {
            // Get Logs to generate active users data.
            $activeusers = array();
            $logs = $DB->get_records_sql($sql, $params);

            // Get active users for every day.
            for ($i = 0; $i < $this->xlabelcount; $i++) {
                $time = $this->timenow - $i * LOCAL_SITEREPORT_ONEDAY;
                $logkey = date("j-n-Y", $time);
                if (isset($logs[$logkey])) {
                    $activeusers[] = $logs[$logkey]->usercount;
                } else {
                    $activeusers[] = 0;
                }
            }

            // If not set the set cache.
            $this->cache->set($cachekey, $activeusers);
        }

        /* Reverse the array because the graph take
        value from left to right */
        return array_reverse($activeusers);
    }

    /**
     * Get all Enrolments
     * @param string $filter apply filter duration
     * @param [int] $cohortid Cohort Id
     * @return array Array of all active users based
     */
    public function get_enrolments($filter, $cohortid) {
        global $DB;

        $starttime = $this->timenow - ($this->xlabelcount * LOCAL_SITEREPORT_ONEDAY);
        $params = array(
            "starttime" => $starttime,
            "endtime" => $this->timenow,
            "eventname" => '\core\event\user_enrolment_created',
            "action" => "created"
        );

        $cachekey = "activeusers-enrolments-". $filter . "-all";
        $cohortjoin = "";
        $cohortcondition = "";
        if ($cohortid) {
            $cachekey = "activeusers-enrolments-" . $filter . "-" . $cohortid;
            $cohortjoin = "JOIN {cohort_members} cm ON l.relateduserid = cm.userid";
            $cohortcondition = "AND cm.cohortid = :cohortid";
            $params["cohortid"] = $cohortid;
        }
        $sql = "SELECT
            CONCAT(
                DAY(FROM_UNIXTIME(l.timecreated)), '-',
                MONTH(FROM_UNIXTIME(l.timecreated)), '-',
                YEAR(FROM_UNIXTIME(l.timecreated))
            ) USERDATE,
            COUNT( l.relateduserid ) as usercount
            FROM {logstore_standard_log} l "
            . $cohortjoin .
            " WHERE l.eventname = :eventname "
            . $cohortcondition .
            " AND l.action = :action
            AND l.timecreated >= :starttime
            AND l.timecreated < :endtime
            GROUP BY YEAR(FROM_UNIXTIME(l.timecreated)),
            MONTH(FROM_UNIXTIME(l.timecreated)),
            DAY(FROM_UNIXTIME(l.timecreated)), USERDATE";
        // Get data from cache if exist.
        if (!$enrolments = $this->cache->get($cachekey)) {
            // Get enrolments log.
            $logs = $DB->get_records_sql($sql, $params);
            $enrolments = array();

            // Get enrolments from every day.
            for ($i = 0; $i < $this->xlabelcount; $i++) {
                $time = $this->timenow - $i * LOCAL_SITEREPORT_ONEDAY;
                $logkey = date("j-n-Y", $time);

                if (isset($logs[$logkey])) {
                    $enrolments[] = $logs[$logkey]->usercount;
                } else {
                    $enrolments[] = 0;
                }
            }

            // Set cache ifnot exist.
            $this->cache->set($cachekey, $enrolments);
        }
        /* Reverse the array because the graph take
        value from left to right */
        return array_reverse($enrolments);
    }

    /**
     * Get all Enrolments
     * @param string $filter apply filter duration
     * @param [int] $cohortid Cohort Id
     * @return array Array of all active users based
     */
    public function get_course_completionrate($filter, $cohortid) {
        global $DB;

        $params = array();
        $cachekey = "activeusers-completionrate-" . $filter . "-all";
        $cohortjoin = "";
        $cohortcondition = "";
        if ($cohortid) {
            $cachekey = "activeusers-completionrate-" . $filter . "-" . $cohortid;
            $cohortjoin = "JOIN {cohort_members} cm ON cc.userid = cm.userid";
            $cohortcondition = "AND cm.cohortid = :cohortid";
            $params["cohortid"] = $cohortid;
        }
        $sql = "SELECT
            CONCAT(
            DAY(FROM_UNIXTIME(cc.completiontime)), '-',
            MONTH(FROM_UNIXTIME(cc.completiontime)), '-',
            YEAR(FROM_UNIXTIME(cc.completiontime))
            ) USERDATE,
            COUNT( CONCAT(cc.courseid, '-', cc.userid )) as usercount
            FROM {edwreports_course_progress} cc "
            . $cohortjoin .
            " WHERE cc.completiontime IS NOT NULL "
            . $cohortcondition .
            " GROUP BY YEAR(FROM_UNIXTIME(cc.completiontime)),
            MONTH(FROM_UNIXTIME(cc.completiontime)),
            DAY(FROM_UNIXTIME(cc.completiontime)), USERDATE";
        // Get data from cache if exist.
        if (!$completionrate = $this->cache->get($cachekey)) {
            $completionrate = array();
            $completions = $DB->get_records_sql($sql, $params);

            // Get completion for each day.
            for ($i = 0; $i < $this->xlabelcount; $i++) {
                $time = $this->timenow - $i * LOCAL_SITEREPORT_ONEDAY;
                $logkey = date("j-n-Y", $time);

                if (isset($completions[$logkey])) {
                    $completionrate[] = $completions[$logkey]->usercount;
                } else {
                    $completionrate[] = 0;
                }
            }

            // Set cache if data not exist.
            $this->cache->set($cachekey, $completionrate);
        }

        /* Reverse the array because the graph take
        value from left to right */
        return array_reverse($completionrate);
    }


    /**
     * Get Exportable data for Active Users Block
     * @param $filter [string] Filter to get data from specific range
     * @return [array] Array of exportable data
     */
    public function get_exportable_data_block($filter) {
        $cohortid = optional_param("cohortid", 0, PARAM_INT);
        // Make cache.
        $cache = cache::make('local_edwiserreports', 'activeusers');
        $cachekey = "exportabledatablock-" . $filter;

        // If exportable data is set in cache then get it from there.
        if (!$export = $cache->get($cachekey)) {
            // Get exportable data for active users block.
            $export = array();

            $obj = new self();
            $export[] = self::get_header();
            $activeusersdata = $obj->get_data((object) array("filter" => $filter));

            // Generate active users data.
            foreach ($activeusersdata->labels as $key => $lable) {
                $export[] = array(
                    $lable,
                    $activeusersdata->data->activeUsers[$key],
                    $activeusersdata->data->enrolments[$key],
                    $activeusersdata->data->completionRate[$key],
                );
            }

            // Set cache for exportable data.
            $cache->set($cachekey, $export);
        }

        return $export;
    }

    /**
     * Get Exportable data for Active Users Page
     * @param $filter [string] Filter to get data from specific range
     * @return [array] Array of exportable data
     */
    public static function get_exportable_data_report($filter) {
        // Make cache.
        $cache = cache::make('local_edwiserreports', 'activeusers');

        if (!$export = $cache->get("exportabledatareport")) {
            $export = array();

            $blockobj = new self();
            $export[] = self::get_header_report();
            $activeusersdata = $blockobj->get_data((object) array("filter" => $filter));
            foreach ($activeusersdata->labels as $key => $lable) {
                $export = array_merge($export,
                    self::get_usersdata($lable, "activeusers"),
                    self::get_usersdata($lable, "enrolments"),
                    self::get_usersdata($lable, "completions")
                );
            }

            // Set cache for exportable data.
            $cache->set("exportabledatablock", $export);
        }

        return $export;
    }

    /**
     * Get User Data for Active Users Block
     * @param [string] $lable Date for lable
     * @param [string] $action Action for getting data
     */
    public static function get_usersdata($lable, $action) {
        $usersdata = array();
        $users = self::get_userslist(strtotime($lable), $action);

        foreach ($users as $key => $user) {
            $user = array_merge(
               array($lable),
               $user
            );

            // If course is not set then skip one block for course
            // Add empty value in course header.
            if (!isset($user[3])) {
                $user = array_merge($user, array(''));
            }

            $user = array_merge($user, array(get_string($action . "_status", "local_edwiserreports")));
            $usersdata[] = $user;
        }

        return $usersdata;
    }
}
