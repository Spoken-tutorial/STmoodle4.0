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
 * This page shows all course enrolment options for current user.
 *
 * @package    core_enrol
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../config.php');
require_once("$CFG->libdir/formslib.php");

$id = required_param('id', PARAM_INT);
$returnurl = optional_param('returnurl', 0, PARAM_LOCALURL);

if (!isloggedin()) {
    $referer = get_local_referer();
    if (empty($referer)) {
        // A user that is not logged in has arrived directly on this page,
        // they should be redirected to the course page they are trying to enrol on after logging in.
        $SESSION->wantsurl = "$CFG->wwwroot/course/view.php?id=$id";
    }
    // do not use require_login here because we are usually coming from it,
    // it would also mess up the SESSION->wantsurl
    redirect(get_login_url());
}

$course = $DB->get_record('course', array('id'=>$id), '*', MUST_EXIST);
$context = context_course::instance($course->id, MUST_EXIST);

// Everybody is enrolled on the frontpage
if ($course->id == SITEID) {
    redirect("$CFG->wwwroot/");
}

if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', context_course::instance($course->id))) {
    print_error('coursehidden');
}

$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_url('/enrol/index.php', array('id'=>$course->id));
$PAGE->set_secondary_navigation(false);
$PAGE->add_body_class('limitedwidth');

// do not allow enrols when in login-as session
if (\core\session\manager::is_loggedinas() and $USER->loginascontext->contextlevel == CONTEXT_COURSE) {
    print_error('loginasnoenrol', '', $CFG->wwwroot.'/course/view.php?id='.$USER->loginascontext->instanceid);
}

// Check if user has access to the category where the course is located.
if (!core_course_category::can_view_course_info($course) && !is_enrolled($context, $USER, '', true)) {
    print_error('coursehidden', '', $CFG->wwwroot . '/');
}

// get all enrol forms available in this course
$enrols = enrol_get_plugins(true);
$enrolinstances = enrol_get_instances($course->id, true);
$forms = array();
foreach($enrolinstances as $instance) {
    if (!isset($enrols[$instance->enrol])) {
        continue;
    }
    $form = $enrols[$instance->enrol]->enrol_page_hook($instance);
    if ($form) {
        $forms[$instance->id] = $form;
    }
}

// Check if user already enrolled
if (is_enrolled($context, $USER, '', true)) {
    if (!empty($SESSION->wantsurl)) {
        $destination = $SESSION->wantsurl;
        unset($SESSION->wantsurl);
    } else {
        $destination = "$CFG->wwwroot/course/view.php?id=$course->id";
    }
    redirect($destination);   // Bye!
}

/* verify test attendance before allowing course enrollment */
include_once($CFG->dirroot . '/mod/quiz/spoken-config.php');
$attendance_marked = false;

// add quiz context checking to ensure attendance is verified for the specific quiz rather than granting broad course enrollment
$quizid = optional_param('quizid', 0, PARAM_INT);
error_log(
    "[" . date("Y-m-d H:i:s") . "] " .
    __FILE__ . ":" . __LINE__ .
    " SPKDEBUG: enrol/index.php optional_param quizid=" . $quizid . ", wantsurl=" . ($SESSION->wantsurl ?? 'empty') . PHP_EOL,
    3,
    "/var/log/moodle-debug/debug.log"
);
if (!$quizid && !empty($SESSION->wantsurl)) {
    $parsed_url = parse_url($SESSION->wantsurl);
    $path = $parsed_url['path'] ?? '';
    // Only resolve quiz module if wantsurl is actually pointing to a quiz
    if (strpos($path, '/mod/quiz/') !== false && !empty($parsed_url['query'])) {
        parse_str($parsed_url['query'], $query_params);
        $cmid = !empty($query_params['cmid']) ? (int)$query_params['cmid'] : (!empty($query_params['id']) ? (int)$query_params['id'] : 0);
        if ($cmid) {
            if ($cm = get_coursemodule_from_id('quiz', $cmid)) {
                $quizid = (int)$cm->instance;
            }
        } else if (!empty($query_params['q'])) {
            $quizid = (int)$query_params['q'];
        }
    }
}
error_log(
    "[" . date("Y-m-d H:i:s") . "] " .
    __FILE__ . ":" . __LINE__ .
    " SPKDEBUG: enrol/index.php resolved quiz context. quizid=" . $quizid . PHP_EOL,
    3,
    "/var/log/moodle-debug/debug.log"
);

/*
// Original attendance check (commented out for reference):
$att_sql = "SELECT id FROM events_testattendance WHERE mdluser_id = ? AND mdlcourse_id = ? AND status >= 1";
if (isset($mysqli) && $stmt = $mysqli->prepare($att_sql)) {
    $stmt->bind_param("ii", $USER->id, $course->id);
    $stmt->execute();
    $att_res = $stmt->get_result();
    $attendance_marked = ($att_res && $att_res->num_rows > 0);
    $stmt->close();
}
*/

if ($quizid) {
    error_log(
        "[" . date("Y-m-d H:i:s") . "] " .
        __FILE__ . ":" . __LINE__ .
        " SPKDEBUG: enrol/index.php branch [quiz-specific]. Checking attendance for userid=" . $USER->id . ", courseid=" . $course->id . ", quizid=" . $quizid . PHP_EOL,
        3,
        "/var/log/moodle-debug/debug.log"
    );
    $att_sql = "SELECT id FROM events_testattendance WHERE mdluser_id = ? AND mdlcourse_id = ? AND mdlquiz_id = ? AND status >= 1";
    if (isset($mysqli) && $stmt = $mysqli->prepare($att_sql)) {
        $stmt->bind_param("iii", $USER->id, $course->id, $quizid);
        $stmt->execute();
        $att_res = $stmt->get_result();
        $attendance_marked = ($att_res && $att_res->num_rows > 0);
        error_log(
            "[" . date("Y-m-d H:i:s") . "] " .
            __FILE__ . ":" . __LINE__ .
            " SPKDEBUG: enrol/index.php quiz-specific query executed. num_rows=" . ($att_res ? $att_res->num_rows : 'false') . ", attendance_marked=" . ($attendance_marked ? 'true' : 'false') . PHP_EOL,
            3,
            "/var/log/moodle-debug/debug.log"
        );
        $stmt->close();
    } else {
        error_log(
            "[" . date("Y-m-d H:i:s") . "] " .
            __FILE__ . ":" . __LINE__ .
            " SPKDEBUG: enrol/index.php quiz-specific query prepare FAILED. mysqli_error=" . ($mysqli->error ?? 'none') . PHP_EOL,
            3,
            "/var/log/moodle-debug/debug.log"
        );
    }
} else {
    error_log(
        "[" . date("Y-m-d H:i:s") . "] " .
        __FILE__ . ":" . __LINE__ .
        " SPKDEBUG: enrol/index.php branch [course-only fallback]. Checking attendance for userid=" . $USER->id . ", courseid=" . $course->id . PHP_EOL,
        3,
        "/var/log/moodle-debug/debug.log"
    );
    $att_sql = "SELECT id FROM events_testattendance WHERE mdluser_id = ? AND mdlcourse_id = ? AND status >= 1";
    if (isset($mysqli) && $stmt = $mysqli->prepare($att_sql)) {
        $stmt->bind_param("ii", $USER->id, $course->id);
        $stmt->execute();
        $att_res = $stmt->get_result();
        $attendance_marked = ($att_res && $att_res->num_rows > 0);
        error_log(
            "[" . date("Y-m-d H:i:s") . "] " .
            __FILE__ . ":" . __LINE__ .
            " SPKDEBUG: enrol/index.php course-only query executed. num_rows=" . ($att_res ? $att_res->num_rows : 'false') . ", attendance_marked=" . ($attendance_marked ? 'true' : 'false') . PHP_EOL,
            3,
            "/var/log/moodle-debug/debug.log"
        );
        $stmt->close();
    } else {
        error_log(
            "[" . date("Y-m-d H:i:s") . "] " .
            __FILE__ . ":" . __LINE__ .
            " SPKDEBUG: enrol/index.php course-only query prepare FAILED. mysqli_error=" . ($mysqli->error ?? 'none') . PHP_EOL,
            3,
            "/var/log/moodle-debug/debug.log"
        );
    }
}
if ($course->id == 7 || is_siteadmin()) {
    $attendance_marked = true;
}

if (!$attendance_marked) {
    $PAGE->set_title($course->shortname);
    $PAGE->set_heading($course->fullname);
    $PAGE->navbar->add(get_string('enrolmentoptions', 'enrol'));

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('enrolmentoptions', 'enrol'));
    $courserenderer = $PAGE->get_renderer('core', 'course');
    echo $courserenderer->course_info_box($course);
    echo $OUTPUT->box('You cannot enroll for this test as your attendance is not marked.', 'generalbox alert alert-danger');
    echo $OUTPUT->footer();
    exit;
}

$PAGE->set_title($course->shortname);
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('enrolmentoptions','enrol'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('enrolmentoptions','enrol'));

$courserenderer = $PAGE->get_renderer('core', 'course');
echo $courserenderer->course_info_box($course);

//TODO: find if future enrolments present and display some info

foreach ($forms as $form) {
    echo $form;
}

if (!$forms) {
    if (isguestuser()) {
        notice(get_string('noguestaccess', 'enrol'), get_login_url());
    } else if ($returnurl) {
        notice(get_string('notenrollable', 'enrol'), $returnurl);
    } else {
        $url = get_local_referer(false);
        if (empty($url)) {
            $url = new moodle_url('/index.php');
        }
        notice(get_string('notenrollable', 'enrol'), $url);
    }
}

echo $OUTPUT->footer();
