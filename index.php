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
 * The gradebook planstate report
 *
 * @package   gradereport_planstate
 * @copyright 2021 David Herney - cirano
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once '../../../config.php';
require_once $CFG->libdir.'/gradelib.php';
require_once $CFG->dirroot.'/grade/lib.php';
require_once $CFG->dirroot.'/grade/report/planstate/lib.php';

$courseid = optional_param('id', SITEID, PARAM_INT);
$userid   = optional_param('userid', $USER->id, PARAM_INT);

$PAGE->set_url(new moodle_url('/grade/report/planstate/index.php', array('id' => $courseid, 'userid' => $userid)));

if (!$course = $DB->get_record('course', array('id' => $courseid))) {
    print_error('invalidcourseid');
}
require_login(null, false);
$PAGE->set_course($course);

$context = context_course::instance($course->id);
$systemcontext = context_system::instance();
$personalcontext = null;

// If we are accessing the page from a site context then ignore this check.
if ($courseid != SITEID) {
    require_capability('gradereport/planstate:view', $context);
}

if (empty($userid)) {
    require_capability('moodle/grade:viewall', $context);

} else {
    if (!$DB->get_record('user', array('id'=>$userid, 'deleted'=>0)) or isguestuser($userid)) {
        print_error('invaliduserid');
    }
    $personalcontext = context_user::instance($userid);
}

if (isset($personalcontext) && $courseid == SITEID) {
    $PAGE->set_context($personalcontext);
} else {
    $PAGE->set_context($context);
}
if ($userid == $USER->id) {
    $settings = $PAGE->settingsnav->find('mygrades', null);
    $settings->make_active();
} else if ($courseid != SITEID && $userid) {
    // Show some other navbar thing.
    $user = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);
    $PAGE->navigation->extend_for_user($user);
}

$access = grade_report_planstate::check_access($systemcontext, $context, $personalcontext, $course, $userid);

if (!$access) {
    // no access to grades!
    print_error('nopermissiontoviewgrades', 'error',  $CFG->wwwroot.'/course/view.php?id='.$courseid);
}

/// return tracking object
$gpr = new grade_plugin_return(array('type'=>'report', 'plugin'=>'planstate', 'courseid'=>$course->id, 'userid'=>$userid));

/// last selected report session tracking
if (!isset($USER->grade_last_report)) {
    $USER->grade_last_report = array();
}
$USER->grade_last_report[$course->id] = 'planstate';

// First make sure we have proper final grades.
grade_regrade_final_grades_if_required($course);


$categories = $DB->get_records('course_categories', array('visible' => 1), 'sortorder ASC');
$hascourses = false;

$idcategories = explode(',', $CFG->grade_report_planstate_idcategories);

$availablecategories = array();
foreach ($idcategories as $oneid) {
    $oneid = trim($oneid);
    if (!empty($oneid)) {
        $availablecategories[] = (int)$oneid;
    }
}

if (count($availablecategories) == 0) {
    $availablecategories = null;
}

foreach ($categories as $category) {

    if ($availablecategories && !in_array($category->id, $availablecategories)) {
        continue;
    }

    // Create a report instance
    $report = new grade_report_planstate($userid, $gpr, $context, $category->id);

    if (!empty($report->studentcourseids)) {

        if (!$hascourses) {
            if ($courseid == SITEID) {
                $PAGE->set_pagelayout('standard');
                $header = get_string('grades', 'grades') . ' - ' . fullname($report->user);
                $PAGE->set_title($header);
                $PAGE->set_heading(fullname($report->user));
                echo $OUTPUT->header();
                echo html_writer::tag('h3', get_string('coursesiamtaking', 'grades'));
            } else {
                print_grade_page_head($courseid, 'report', 'planstate', get_string('pluginname', 'gradereport_planstate')
                . ' - ' . fullname($report->user));
            }
            $hascourses = true;
        }

        echo html_writer::empty_tag('hr');
        echo html_writer::tag('h4', $category->name);

        // If the course id matches the site id then we don't have a course context to work with.
        // Display a standard page.
        if ($courseid == SITEID) {
            if ($USER->id != $report->user->id) {
                $PAGE->navigation->extend_for_user($report->user);
                if ($node = $PAGE->settingsnav->get('userviewingsettings'.$report->user->id)) {
                    $node->forceopen = true;
                }
            } else if ($node = $PAGE->settingsnav->get('usercurrentsettings', navigation_node::TYPE_CONTAINER)) {
                $node->forceopen = true;
            }
        }

        if ($report->fill_table(false, true)) {
            echo $report->print_table(true);
        }

    }
}

if (!$hascourses) {
    $PAGE->set_pagelayout('standard');
    $header = get_string('grades', 'grades') . ' - ' . fullname($report->user);
    $PAGE->set_title($header);
    $PAGE->set_heading(fullname($report->user));
    echo $OUTPUT->header();
    // We have no report to show the user. Let them know something.
    echo $OUTPUT->notification(get_string('noreports', 'grades'), 'notifymessage');
}


grade_report_planstate::viewed($context, $courseid, $userid);

echo $OUTPUT->footer();
