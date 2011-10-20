<?php  // $Id: upload.php,v 1.26 2006/08/08 22:09:56 skodak Exp $

    require_once("../../../../config.php");
    require_once("../../lib.php");
    require_once(dirname(__FILE__).'/simpleupload_form.php');

    global $DB;

    $id = optional_param('id', 0, PARAM_INT);  // Course module ID
    $a  = optional_param('a', 0, PARAM_INT);   // Assignment ID

    if ($id) {
        if (! $cm = get_coursemodule_from_id('assignment', $id)) {
            error("Course Module ID was incorrect");
        }

        if (! $assignment = $DB->get_record("assignment", array("id"=>$cm->instance))) {
            error("assignment ID was incorrect");
        }

        if (! $course = $DB->get_record("course", array("id"=>$assignment->course))) {
            error("Course is misconfigured");
        }
    } else {
        if (!$assignment = $DB->get_record("assignment", array("id"=>$a))) {
            error("Course module is incorrect");
        }
        if (! $course = $DB->get_record("course", array("id"=>$assignment->course))) {
            error("Course is misconfigured");
        }
        if (! $cm = get_coursemodule_from_instance("assignment", $assignment->id, $course->id)) {
            error("Course Module ID was incorrect");
        }
    }

    require_login($course->id, false, $cm);

/// Load up the required assignment code
    require($CFG->dirroot.'/mod/assignment/type/'.$assignment->assignmenttype.'/assignment.class.php');

    $assignmentclass = 'assignment_'.$assignment->assignmenttype;
    $assignmentinstance = new $assignmentclass($cm->id, $assignment, $cm, $course);

    $mform = new mod_assignment_onlineaudioupload_form();
    if ($mform->is_cancelled()) {
        redirect(new moodle_url('/mod/assignment/view.php', array('id'=>$cm->id)));
    } else {
        $assignmentinstance->simple_upload_file($mform);
    }