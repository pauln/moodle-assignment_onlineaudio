<?php

/**
 * Flash-based online audio recorder which records in memory and then POSTs the file as if uploaded manually
 *
 * By Paul Nicholls.  Based on code for upload single assignment type and Bruce Webster's "Direct Audio Recorder"
 */

global $CFG;
require_once($CFG->libdir.'/weblib.php');
require_once($CFG->dirroot . '/mod/assignment/lib.php');
require_once(dirname(__FILE__).'/simpleupload_form.php');

class assignment_onlineaudio extends assignment_base {

    function print_student_answer($userid, $return=false){
        return '<div class="files">'.$this->print_user_files($userid,true).'</div>';
    }

    function assignment_onlineaudio($cmid='staticonly', $assignment=NULL, $cm=NULL, $course=NULL) {
        parent::assignment_base($cmid, $assignment, $cm, $course);
        $this->type = 'onlineaudio';
    }


    function view() {
        global $USER, $OUTPUT;

        $context = get_context_instance(CONTEXT_MODULE,$this->cm->id);
        require_capability('mod/assignment:view', $context);

        add_to_log($this->course->id, "assignment", "view", "view.php?id={$this->cm->id}", $this->assignment->id, $this->cm->id);

        $this->view_header();

        $this->view_intro();

        $this->view_dates();

        $filelist='';
        if ($submission = $this->get_submission($USER->id)) {
            if ($submission->timemarked) {
                $this->view_feedback();
            }
            $filecount = $this->count_user_files($submission->id);
            if ($filecount) {$filelist=$this->print_user_files($USER->id, true);}
        } else {
            $filecount = 0;
        }
        
        $upload_form='';
        if (has_capability('mod/assignment:submit', $context)  && $this->isopen() && (!$filecount || $this->assignment->resubmit || !$submission->timemarked)) {
            $upload_form=$this->view_upload_form();
        }

        if($filelist || $upload_form) {
            echo $OUTPUT->box($upload_form.$filelist, 'center onlineaudio');
        }
        $this->view_footer();
    }

    /**
     * Shows the recording + upload form
     */
    function view_upload_form() {
        global $CFG,$USER,$OUTPUT;

        $submission = $this->get_submission($USER->id);

        if ($this->can_upload_file($submission)) {
            echo $OUTPUT->box_start('boxaligncenter', 'onlineaudiosubmission');
            $url='type/onlineaudio/assets/recorder.swf?gateway='.$CFG->wwwroot.'/mod/assignment/type/onlineaudio/simpleupload.php';

            $flashvars='&filefield=assignment_file&id='.$this->cm->id.'&sesskey='.$USER->sesskey.'&contextid='.$this->context->id.'&userid='.$USER->id.'&_qf__mod_assignment_onlineaudioupload_form=1';

            if($this->assignment->var2) {
                $field=($this->assignment->var3)?'filename':'forcename';
                $filename=($this->assignment->var2==2)?fullname($USER):$USER->username;
                $filename.='_-_'.substr($this->assignment->name,0,20).'_-_'.$this->course->shortname.'_-_'.date('Y-m-d');
                $filename=clean_filename($filename);
                $flashvars .= "&$field=$filename";
            }

            echo '<script type="text/javascript" src="type/onlineaudio/assets/swfobject.js"></script>
                <script type="text/javascript">
                swfobject.registerObject("onlineaudiorecorder", "10.1.0", "type/onlineaudio/assets/expressInstall.swf");
                </script>';

            $style = ($this->assignment->var1)?' style="float:left"':'';
            echo '<div id="onlineaudiorecordersection"'.$style.'>
                <h3>'.get_string('makenewrecording','assignment_onlineaudio').'</h3>
                <object id="onlineaudiorecorder" classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" width="215" height="138">
                        <param name="movie" value="'.$url.$flashvars.'" />
                        <!--[if !IE]>-->
                        <object type="application/x-shockwave-flash" data="'.$url.$flashvars.'" width="215" height="138">
                        <!--<![endif]-->
                        <div>
                                <p><a href="http://www.adobe.com/go/getflashplayer"><img src="http://www.adobe.com/images/shared/download_buttons/get_flash_player.gif" alt="Get Adobe Flash player" /></a></p>
                        </div>
                        <!--[if !IE]>-->
                        </object>
                        <!--<![endif]-->
                </object></div>';

            $struploadafile = get_string("uploadafile", "assignment_onlineaudio");

            $maxbytes = get_max_upload_file_size($CFG->maxbytes, $this->course->maxbytes, $this->assignment->maxbytes);
            $strmaxsize = get_string('maxsize', '', display_size($maxbytes));

            if($this->assignment->var1) { // allow manual upload
                echo '<div id="manualuploadform" style="float:left;clear:right;"><h3>'.$struploadafile.'</h3>';
                $str = get_string('advanceduploadafile', 'assignment_onlineaudio');
                $advlink = $OUTPUT->box_start();
                $advlink .= $OUTPUT->action_link(new moodle_url('/mod/assignment/type/onlineaudio/upload.php', array('contextid'=>$this->context->id, 'userid'=>$USER->id)), $str);
                $advlink .= $OUTPUT->box_end();
                $options = array('maxbytes'=>$maxbytes, 'accepted_types'=>'*');
                $mform = new mod_assignment_onlineaudioupload_form(new moodle_url('/mod/assignment/type/onlineaudio/simpleupload.php'), array('caption'=>get_string('uploadnote', 'assignment_onlineaudio'), 'cmid'=>$this->cm->id, 'contextid'=>$this->context->id, 'userid'=>$USER->id, 'options'=>$options, 'advancedlink'=>$advlink));
                $mform->simpleupload_setMaxFileSize($maxbytes);
                if ($mform->is_cancelled()) {
                    redirect(new moodle_url('/mod/assignment/view.php', array('id'=>$this->cm->id)));
                } else if ($mform->get_data()) {
                    $this->upload($mform);
                    die();
                }
                $mform->display();
                echo '</div><br clear="all" />';
            }
            echo $OUTPUT->box_end();
        }
    }

    function can_upload_file($submission) {
        global $USER;

        if (is_enrolled($this->context, $USER, 'mod/assignment:submit')
          and $this->isopen()                                                 // assignment not closed yet
          and (empty($submission) or ($submission->userid == $USER->id))) {        // his/her own submission
            return true;
        } else {
            return false;
        }
    }

    function upload($mform, $options) {
        global $CFG, $USER, $DB, $OUTPUT;

        $returnurl  = new moodle_url('/mod/assignment/view.php', array('id'=>$this->cm->id));
        $submission = $this->get_submission($USER->id);

        if (!$this->can_upload_file($submission)) {
            $this->view_header(get_string('upload'));
            echo $OUTPUT->notification(get_string('uploaderror', 'assignment'));
            echo $OUTPUT->continue_button($returnurl);
            $this->view_footer();
            die;
        }

        if ($formdata = $mform->get_data()) {
            $fs = get_file_storage();
            $submission = $this->get_submission($USER->id, true); //create new submission if needed
            $fs->delete_area_files($this->context->id, 'mod_assignment', 'submission', $submission->id);
            $formdata = file_postupdate_standard_filemanager($formdata, 'files', $options, $this->context, 'mod_assignment', 'submission', $submission->id);
            $updates = new stdClass();
            $updates->id = $submission->id;
            $updates->timemodified = time();
            $DB->update_record('assignment_submissions', $updates);
            add_to_log($this->course->id, 'assignment', 'upload',
                    'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
            $this->update_grade($submission);
            $this->email_teachers($submission);

            // send files to event system
            $files = $fs->get_area_files($this->context->id, 'mod_assignment', 'submission', $submission->id);

            // Let Moodle know that assessable files were  uploaded (eg for plagiarism detection)
            $eventdata = new stdClass();
            $eventdata->modulename   = 'assignment';
            $eventdata->cmid         = $this->cm->id;
            $eventdata->itemid       = $submission->id;
            $eventdata->courseid     = $this->course->id;
            $eventdata->userid       = $USER->id;
            if ($files) {
                $eventdata->files        = $files;
            }
            events_trigger('assessable_file_uploaded', $eventdata);
            $returnurl  = new moodle_url('/mod/assignment/view.php', array('id'=>$this->cm->id));
            redirect($returnurl);
        }

        $this->view_header(get_string('upload'));
        echo $OUTPUT->notification(get_string('uploaderror', 'assignment'));
        echo $OUTPUT->continue_button($returnurl);
        $this->view_footer();
        die;
    }

    function simple_upload_file($mform) {
        global $CFG, $USER, $DB, $OUTPUT;
        $viewurl = new moodle_url('/mod/assignment/view.php', array('id'=>$this->cm->id));
        if (!is_enrolled($this->context, $USER, 'mod/assignment:submit')) {
            redirect($viewurl);
        }

        $submission = $this->get_submission($USER->id);
        $filecount = 0;
        if ($submission) {
            $filecount = $this->count_user_files($submission->id);
        }
        if ($this->isopen() && (!$filecount || $this->assignment->resubmit || !$submission->timemarked)) {
            if ($submission = $this->get_submission($USER->id)) {
                //TODO: change later to ">= 0", to prevent resubmission when graded 0
                if (($submission->grade > 0) and !$this->assignment->resubmit) {
                    redirect($viewurl, get_string('alreadygraded', 'assignment'));
                }
            }

            if ($formdata = $mform->get_data()) {
                $fs = get_file_storage();
                $submission = $this->get_submission($USER->id, true); //create new submission if needed

                if (!array_key_exists('assignment_file', $_FILES)) {
                    redirect($viewurl, get_string('uploaderror', 'assignment'), 10);
                    exit;
                }
                $filedetails = $_FILES['assignment_file'];

                $filename = $filedetails['name'];
                $filesrc = $filedetails['tmp_name'];

                if (!is_uploaded_file($filesrc)) {
                    redirect($viewurl, get_string('uploaderror', 'assignment'), 10);
                    exit;
                }
                
                $ext = substr(strrchr($filename, '.'), 1);
                if (!preg_match('/^(mp3|wav|wma)$/i',$ext)) {
                    redirect($viewurl, get_string('filetypeerror', 'assignment_onlineaudio'), 10);
                    exit;
                }
                
                $temp_name=basename($filename,".$ext"); // We want to clean the file's base name only
                // Run param_clean here with PARAM_FILE so that we end up with a name that other parts of Moodle
                // (download script, deletion, etc) will handle properly.  Remove leading/trailing dots too.
                $temp_name=trim(clean_param($temp_name, PARAM_FILE),".");
                $filename=$temp_name.".$ext";
                // check for filename already existing and add suffix #.
                $n=1;
                while($fs->file_exists($this->context->id, 'mod_assignment', 'submission', $submission->id, '/', $filename)) {
                    $filename=$temp_name.'_'.$n++.".$ext";
                }

                // Create file
                $fileinfo = array(
                      'contextid' => $this->context->id,
                      'component' => 'mod_assignment',
                      'filearea' => 'submission',
                      'itemid' => $submission->id,
                      'filepath' => '/',
                      'filename' => $filename
                      );
                if ($newfile = $fs->create_file_from_pathname($fileinfo, $filesrc)) {
                    $updates = new stdClass(); //just enough data for updating the submission
                    $updates->timemodified = time();
                    $updates->numfiles     = $filecount+1;
                    $updates->id     = $submission->id;
                    $DB->update_record('assignment_submissions', $updates);
                    add_to_log($this->course->id, 'assignment', 'upload', 'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
                    $this->update_grade($submission);
                    $this->email_teachers($submission);

                    // Let Moodle know that an assessable file was uploaded (eg for plagiarism detection)
                    $eventdata = new stdClass();
                    $eventdata->modulename   = 'assignment';
                    $eventdata->cmid         = $this->cm->id;
                    $eventdata->itemid       = $submission->id;
                    $eventdata->courseid     = $this->course->id;
                    $eventdata->userid       = $USER->id;
                    $eventdata->file         = $newfile;
                    events_trigger('assessable_file_uploaded', $eventdata);
                }

                redirect($viewurl, '', 0);
            } else {
                // Add any error messages (i.e. file too big - lang/en/moodle.php, 'uploadformlimit') to the redirect screen
                $errorStr = get_string('uploaderror', 'assignment');
                if(sizeof($mform->simpleupload_get_errors())) {
                    $errorStr .= '<br />';
                    foreach($mform->simpleupload_get_errors() as $error) {
                        $errorStr .= '<br />'.$error;
                    }
                }
                redirect($viewurl, $errorStr, 10);  //submitting not allowed!
            }
        }

        redirect($viewurl);
    }

    function setup_elements(&$mform) {
        global $CFG, $COURSE;

        $ynoptions = array( 0 => get_string('no'), 1 => get_string('yes'));

        $mform->addElement('select', 'resubmit', get_string("allowresubmit", "assignment"), $ynoptions);
        $mform->addHelpButton('resubmit', 'allowresubmit', 'assignment');
        $mform->setDefault('resubmit', 0);

        $mform->addElement('select', 'emailteachers', get_string("emailteachers", "assignment"), $ynoptions);
        $mform->addHelpButton('emailteachers', 'emailteachers', 'assignment');
        $mform->setDefault('emailteachers', 0);

        $mform->addElement('select', 'var1', get_string("allowupload", "assignment_onlineaudio"), $ynoptions);
        $mform->addHelpButton('var1', 'allowupload', 'assignment_onlineaudio');
        $mform->setDefault('var1', 1);

        $filenameoptions = array( 0 => get_string("nodefaultname", "assignment_onlineaudio"), 1 => get_string("defaultname1", "assignment_onlineaudio"), 2 =>get_string("defaultname2", "assignment_onlineaudio"));
        $mform->addElement('select', 'var2', get_string("defaultname", "assignment_onlineaudio"), $filenameoptions);
        $mform->addHelpButton('var2', 'defaultname', 'assignment_onlineaudio');
        $mform->setDefault('var2', 0);

        $mform->addElement('select', 'var3', get_string("allownameoverride", "assignment_onlineaudio"), $ynoptions);
        $mform->addHelpButton('var3', 'allownameoverride', 'assignment_onlineaudio');
        $mform->setDefault('var3', 1);

        $choices = get_max_upload_sizes($CFG->maxbytes, $COURSE->maxbytes);
        $choices[0] = get_string('courseuploadlimit') . ' ('.display_size($COURSE->maxbytes).')';
        $mform->addElement('select', 'maxbytes', get_string('maximumsize', 'assignment'), $choices);
        $mform->setDefault('maxbytes', $CFG->assignment_maxbytes);
    }
    function download_submissions() {
        global $CFG;
    
        $submissions = $this->get_submissions('','');
    
        $filesforzipping = array();
        $filesnewname = array();
        $desttemp = "";
    
        //create prefix of new filename
        $filenewname = clean_filename($this->assignment->name. "_");
        $course     = $this->course;
        $assignment = $this->assignment;
        $cm         = $this->cm;
        $context    = get_context_instance(CONTEXT_MODULE, $cm->id);
        $groupmode = groupmode($course,$cm);
        $groupid = 0;	// All users
        if($groupmode) $groupid = get_current_group($course->id, $full = false);
        $count = 0;
    
        foreach ($submissions as $submission) {
            $a_userid = $submission->userid; //get userid
            if ( (groups_is_member( $groupid,$a_userid)or !$groupmode or !$groupid)) {
                $count++;

                $a_assignid = $submission->assignment; //get name of this assignment for use in the file names.

                $a_user = get_complete_user_data("id", $a_userid); //get user

                $filearea = $this->file_area_name($a_userid);

                $desttemp = $CFG->dataroot . "/" . substr($filearea, 0, strrpos($filearea, "/")). "/temp/"; //get temp directory name

                if (!file_exists($desttemp)) { //create temp dir if it doesn't already exist.
                    mkdir($desttemp);
                }
        
                if ($basedir = $this->file_area($a_userid)) {
                    if ($files = get_directory_list($basedir)) {
                        foreach ($files as $key => $file) {
                            require_once($CFG->libdir.'/filelib.php');
        
                            //get files new name.
                            $filesforzip = $desttemp . $a_user->username . "_" . $filenewname . "_" . $file;
        
                            //get files old name
                            $fileold = $CFG->dataroot . "/" . $filearea . "/" . $file;
        
                            if (!copy($fileold, $filesforzip)) {
                                error ("failed to copy file<br>" . $filesforzip . "<br>" .$fileold);
                            }
        
                            //save file name to array for zipping.
                            $filesforzipping[] = $filesforzip;
                        }
                    }
                }
            }   
        }     // End of foreach
    
        //zip files
        $filename = "assignment.zip"; //name of new zip file.
        if ($count) zip_files($filesforzipping, $desttemp.$filename);
        // skip if no files zipped
        //delete old temp files
        foreach ($filesforzipping as $filefor) {
           unlink($filefor);
        }
    
        //send file to user.
        if (file_exists($desttemp.$filename)) {
           header ("Cache-Control: must-revalidate, post-check=0, pre-check=0");
           header ("Content-Type: application/octet-stream");
           header ("Content-Length: " . filesize($desttemp.$filename));
           header ("Content-Disposition: attachment; filename=$filename");
           readfile($desttemp.$filename);
        }
    }


    /**
     * Produces a list of links to the files uploaded by a user
     *
     * @param $userid int optional id of the user. If 0 then $USER->id is used.
     * @param $return boolean optional defaults to false. If true the list is returned rather than printed
     * @return string optional
     */
    function print_user_files($userid=0, $return=false) {
        global $CFG, $USER, $OUTPUT;

        $mode    = optional_param('mode', '', PARAM_ALPHA);
        $offset  = optional_param('offset', 0, PARAM_INT);

        if (!$userid) {
            if (!isloggedin()) {
                return '';
            }
            $userid = $USER->id;
        }

        $output = '';

        $submission = $this->get_submission($userid);
        if (!$submission) {
            return $output;
        }

        $strdelete = get_string('delete');
        $candelete = $this->can_delete_files($submission);

        $fs = get_file_storage();
        $files = $fs->get_area_files($this->context->id, 'mod_assignment', 'submission', $submission->id, "timemodified", false);
        if (!empty($files)) {
            require_once($CFG->dirroot . '/mod/assignment/locallib.php');
            if ($CFG->enableportfolios) {
                require_once($CFG->libdir.'/portfoliolib.php');
                $button = new portfolio_add_button();
            }
            foreach ($files as $file) {
                $filename = $file->get_filename();
                $filepath = $file->get_filepath();
                $mimetype = $file->get_mimetype();
                $path = file_encode_url($CFG->wwwroot.'/pluginfile.php', '/'.$this->context->id.'/mod_assignment/submission/'.$submission->id.'/'.$filename);
                $output .= '<img src="'.$OUTPUT->pix_url(file_mimetype_icon($mimetype)).'" class="icon" alt="'.$mimetype.'" />';
                // Dummy link for media filters
                $filtered = filter_text('<a href="'.$path.'" style="display:none;"> </a> ', $this->course->id);
                $filtered = preg_replace('~<a.+?</a>~','',$filtered);
                // Add a real link after the dummy one, so that we get a proper download link no matter what
                $output .= $filtered . '<a href="'.$path.'" >'.s($filename).'</a>';
                if ($candelete) {
                    $delurl  = "$CFG->wwwroot/mod/assignment/delete.php?id={$this->cm->id}&amp;path=$filepath&amp;file=$filename&amp;userid={$submission->userid}&amp;mode=$mode&amp;offset=$offset";

                    $output .= '<a href="'.$delurl.'">&nbsp;'
                              .'<img title="'.$strdelete.'" src="'.$OUTPUT->pix_url('/t/delete').'" class="iconsmall" alt="" /></a> ';
                }
                if ($CFG->enableportfolios && $this->portfolio_exportable() && has_capability('mod/assignment:exportownsubmission', $this->context)) {
                    $button->set_callback_options('assignment_portfolio_caller', array('id' => $this->cm->id, 'submissionid' => $submission->id, 'fileid' => $file->get_id()), '/mod/assignment/locallib.php');
                    $button->set_format_by_file($file);
                    $output .= $button->to_html(PORTFOLIO_ADD_ICON_LINK);
                }
                $output .= plagiarism_get_links(array('userid'=>$userid, 'file'=>$file, 'cmid'=>$this->cm->id, 'course'=>$this->course, 'assignment'=>$this->assignment));
                $output .= '<br />';
            }
            if ($CFG->enableportfolios && count($files) > 1  && $this->portfolio_exportable() && has_capability('mod/assignment:exportownsubmission', $this->context)) {
                $button->set_callback_options('assignment_portfolio_caller', array('id' => $this->cm->id, 'submissionid' => $submission->id), '/mod/assignment/locallib.php');
                $output .= '<br />'  . $button->to_html(PORTFOLIO_ADD_TEXT_LINK);
            }
        }

        $output = '<div class="files">'.$output.'</div>';

        if ($return) {
            return $output;
        }
        echo $output;
    }
    
    
    function can_delete_files($submission) {
        global $USER;

        if (has_capability('mod/assignment:grade', $this->context)) {
            return true;
        }

        if (has_capability('mod/assignment:submit', $this->context)
          and $this->isopen()                                      // assignment not closed yet
          and $USER->id == $submission->userid                      // his/her own submission
          and $submission->grade ==-1) {  // not yet graded
            return true;
        } else {
            return false;
        }
    }
    
    
    function delete() {
        global $CFG, $DB, $OUTPUT, $PAGE;

        $file     = required_param('file', PARAM_FILE);
        $userid   = required_param('userid', PARAM_INT);
        $confirm  = optional_param('confirm', 0, PARAM_BOOL);
        $mode     = optional_param('mode', '', PARAM_ALPHA);
        $offset   = optional_param('offset', 0, PARAM_INT);
        $path     = optional_param('path', '/', PARAM_PATH);

        require_login($this->course->id, false, $this->cm);

        if (empty($mode)) {
            $urlreturn = 'view.php';
            $optionsreturn = array('id'=>$this->cm->id);
            $returnurl  = new moodle_url('/mod/assignment/view.php', array('id'=>$this->cm->id));
        } else {
            $urlreturn = 'submissions.php';
            $optionsreturn = array('id'=>$this->cm->id, 'offset'=>$offset, 'mode'=>$mode, 'userid'=>$userid);
            $returnurl  = new moodle_url('/mod/assignment/submissions.php', array('id'=>$this->cm->id, 'offset'=>$offset, 'userid'=>$userid));
        }

        if (!$submission = $this->get_submission($userid) // incorrect submission
          or !$this->can_delete_files($submission)) {     // can not delete
            $this->view_header(get_string('delete'));
            echo $OUTPUT->notification(get_string('cannotdeletefiles', 'assignment'));
            echo $OUTPUT->continue_button($returnurl);
            $this->view_footer();
            die;
        }

        if (!data_submitted() or !$confirm or !confirm_sesskey()) {
            $optionsyes = array ('id'=>$this->cm->id, 'file'=>$file, 'path'=>$path, 'userid'=>$userid, 'confirm'=>1, 'sesskey'=>sesskey(), 'mode'=>$mode, 'offset'=>$offset, 'sesskey'=>sesskey());
            if (empty($mode)) {
                $this->view_header(get_string('delete'));
            } else {
                $PAGE->set_title(get_string('delete'));
                echo $OUTPUT->header();
            }
            echo $OUTPUT->heading(get_string('delete'));
            if($path=='/') {
                $filepath = $file;
            } else {
                $filepath = $path.$file;
            }
            echo $OUTPUT->confirm(get_string('confirmdeletefile', 'assignment', $filepath), new moodle_url('delete.php', $optionsyes), new moodle_url($urlreturn, $optionsreturn));
            if (empty($mode)) {
                $this->view_footer();
            } else {
                echo $OUTPUT->footer();
            }
            die;
        }

        $fs = get_file_storage();
        if ($file = $fs->get_file($this->context->id, 'mod_assignment', 'submission', $submission->id, $path, $file)) {
            $file->delete();
            $submission->timemodified = time();
            $DB->update_record('assignment_submissions', $submission);
            add_to_log($this->course->id, 'assignment', 'upload', //TODO: add delete action to log
                    'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
            $this->update_grade($submission);
        }
        redirect($returnurl);
    }

    function send_file($filearea, $args, $forcedownload, array $options=array()) {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir.'/filelib.php');

        require_login($this->course, false, $this->cm);

        if ($filearea === 'submission') {
            $submissionid = (int)array_shift($args);

            if (!$submission = $DB->get_record('assignment_submissions', array('assignment'=>$this->assignment->id, 'id'=>$submissionid))) {
                return false;
            }

            if ($USER->id != $submission->userid and !has_capability('mod/assignment:grade', $this->context)) {
                return false;
            }

            $relativepath = implode('/', $args);
            $fullpath = "/{$this->context->id}/mod_assignment/submission/$submission->id/$relativepath";

            $fs = get_file_storage();
            if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
                return false;
            }
            send_stored_file($file, 0, 0, true, $options); // download MUST be forced - security!

        } else if ($filearea === 'response') {
            $submissionid = (int)array_shift($args);

            if (!$submission = $DB->get_record('assignment_submissions', array('assignment'=>$this->assignment->id, 'id'=>$submissionid))) {
                return false;
            }

            if ($USER->id != $submission->userid and !has_capability('mod/assignment:grade', $this->context)) {
                return false;
            }

            $relativepath = implode('/', $args);
            $fullpath = "/{$this->context->id}/mod_assignment/response/$submission->id/$relativepath";

            $fs = get_file_storage();
            if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
                return false;
            }
            send_stored_file($file, 0, 0, true, $options);
        }

        return false;
    }
}
?>
