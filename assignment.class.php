<?php

/**
 * Flash-based online audio recorder which records in memory and then POSTs the file as if uploaded manually
 *
 * By Paul Nicholls.  Based on code for upload single assignment type and Bruce Webster's "Direct Audio Recorder"
 */

global $CFG;

class assignment_onlineaudio extends assignment_base {

    function print_student_answer($userid, $return=false){
        return '<div class="files">'.$this->print_user_files($userid,true).'</div>';
    }

    function assignment_onlineaudio($cmid='staticonly', $assignment=NULL, $cm=NULL, $course=NULL) {
        parent::assignment_base($cmid, $assignment, $cm, $course);
        $this->type = 'onlineaudio';
    }


    function view() {
        global $USER;

        $context = get_context_instance(CONTEXT_MODULE,$this->cm->id);
        require_capability('mod/assignment:view', $context);

        add_to_log($this->course->id, "assignment", "view", "view.php?id={$this->cm->id}", $this->assignment->id, $this->cm->id);

        $this->view_header();

        $this->view_intro();

        $this->view_dates();

        $filelist='';
        $filecount = $this->count_user_files($USER->id);
        if ($submission = $this->get_submission()) {
            if ($submission->timemarked) {
                $this->view_feedback();
            }
            if ($filecount) {$filelist=$this->print_user_files($USER->id, true);
            }
        }
        
        $upload_form='';
        if (has_capability('mod/assignment:submit', $context)  && $this->isopen() && (!$filecount || $this->assignment->resubmit || !$submission->timemarked)) {
            $upload_form=$this->upload_form();
        }

        if($filelist || $upload_form) {
            print_simple_box($upload_form.$filelist, 'center');
        }
        $this->view_footer();
    }

    /**
     * Shows the recording + upload form
     */
    function upload_form() {
        global $CFG,$USER;

        $url='type/onlineaudio/assets/recorder.swf?gateway='.$CFG->wwwroot.'/mod/assignment/type/onlineaudio/upload.php';

        $flashvars='&id='.$this->cm->id.'&sesskey='.$USER->sesskey;

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

        $style = ($this->assignment->var1)?' style="width:250px;float:left"':'';
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

        $maxbytes = $this->assignment->maxbytes == 0 ? $this->course->maxbytes : $this->assignment->maxbytes;
        $strmaxsize = get_string('maxsize', '', display_size($maxbytes));

        if($this->assignment->var1) { // allow manual upload
            echo '<div id="manualuploadform" style="float:left;clear:right;"><h3>'.$struploadafile.'</h3>';
            echo '<form enctype="multipart/form-data" method="post" '.
                 "action=\"$CFG->wwwroot/mod/assignment/type/onlineaudio/upload.php\">";
            echo '<div style="border:1px solid #000;padding:3px;">';
            echo '<input type="hidden" name="id" value="'.$this->cm->id.'" />';
            echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
            require_once($CFG->libdir.'/uploadlib.php');
            upload_print_form_fragment(1,array('newfile'),null,false,null,0,$this->assignment->maxbytes,false);
            echo '<input type="submit" name="save" value="'.get_string('uploadthisfile').'" />';
            echo '<p style="margin-bottom:0;"><b>Note: </b> Only mp3, wma or wav files can be uploaded.  '."($strmaxsize)</p>";
            echo '</div>';
            echo '</form>';
            echo '</div><br clear="all" />';
        }
    }

     /**
     * Handle uploaded file
     */
    function upload_file() {
        global $CFG, $USER;

        require_capability('mod/assignment:submit', get_context_instance(CONTEXT_MODULE, $this->cm->id));

        $this->view_header(get_string('upload'));

        $filecount = $this->count_user_files($USER->id);
        $submission = $this->get_submission($USER->id);
        if ($this->isopen() && (!$filecount || $this->assignment->resubmit || !$submission->timemarked)) {
            if ($submission = $this->get_submission($USER->id)) {
                //TODO: change later to ">= 0", to prevent resubmission when graded 0
                if (($submission->grade > 0) and !$this->assignment->resubmit) {
                    notify(get_string('alreadygraded', 'assignment'));
                }
            }

            $dir = $this->file_area_name($USER->id);

            require_once($CFG->dirroot.'/lib/uploadlib.php');
            //$um = new upload_manager('newfile',false,true,$this->course,false,$this->assignment->maxbytes);

            $ext = substr(strrchr($_FILES["newfile"]["name"], '.'), 1);

            if (!preg_match('/^(mp3|wav|wma)$/i',$ext)) {
                notify(get_string('filetypeerror', 'assignment_onlineaudio'));
                print_continue('../../view.php?id=' . $this->cm->id);
                $this->view_footer();

                return;
            }

            $destination_path=$this->file_area($USER->id)."/";
            $temp_name=basename($_FILES["newfile"]["name"],".$ext"); // We want to clean the file's base name only

            // Run param_clean here with PARAM_FILE so that we end up with a name that other parts of Moodle
            // (download script, deletion, etc) will handle properly.  Remove leading/trailing dots too.
            $temp_name=trim(clean_param($temp_name, PARAM_FILE),".");
            $newfile_name=$temp_name.".$ext";
            // check for filename already existing and add suffix #.
            $n=1;
            while(file_exists($destination_path.$newfile_name)) {$newfile_name=$temp_name.'_'.$n++.".$ext";}

            //if ($um->process_file_uploads($dir) and confirm_sesskey()) {
            if (move_uploaded_file($_FILES["newfile"]['tmp_name'], $destination_path.'/'.$newfile_name)) {
                if ($submission) {
                    $submission->timemodified = time();
                    $submission->numfiles++;
                    $submission->submissioncomment = addslashes($submission->submissioncomment);
                    unset($submission->data1);  // Don't need to update this.
                    unset($submission->data2);  // Don't need to update this.
                    if (update_record("assignment_submissions", $submission)) {
                        add_to_log($this->course->id, 'assignment', 'upload',
                                'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
                        $submission = $this->get_submission($USER->id);
                        $this->update_grade($submission);
                        $this->email_teachers($submission);
                        print_heading(get_string('uploadedfile'));
                    } else {
                        notify(get_string("uploadfailnoupdate", "assignment"));
                    }
                } else {
                    $newsubmission = $this->prepare_new_submission($USER->id);
                    $newsubmission->timemodified = time();
                    $newsubmission->numfiles = 1;
                    if (insert_record('assignment_submissions', $newsubmission)) {
                        add_to_log($this->course->id, 'assignment', 'upload',
                                'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
                        $submission = $this->get_submission($USER->id);
                        $this->update_grade($submission);
                        $this->email_teachers($newsubmission);
                        print_heading(get_string('uploadedfile'));
                    } else {
                        notify(get_string("uploadnotregistered", "assignment", $newfile_name) );
                    }
                }
            }
        } else {
            notify(get_string("uploaderror", "assignment")); //submitting not allowed!
        }

        print_continue('../../view.php?id='.$this->cm->id);

        $this->view_footer();
    }

    function setup_elements(&$mform) {
        global $CFG, $COURSE;

        $ynoptions = array( 0 => get_string('no'), 1 => get_string('yes'));

        $mform->addElement('select', 'resubmit', get_string("allowresubmit", "assignment"), $ynoptions);
        $mform->setHelpButton('resubmit', array('resubmit', get_string('allowresubmit', 'assignment'), 'assignment'));
        $mform->setDefault('resubmit', 0);

        $mform->addElement('select', 'emailteachers', get_string("emailteachers", "assignment"), $ynoptions);
        $mform->setHelpButton('emailteachers', array('emailteachers', get_string('emailteachers', 'assignment'), 'assignment'));
        $mform->setDefault('emailteachers', 0);

        $mform->addElement('select', 'var1', get_string("allowupload", "assignment_onlineaudio"), $ynoptions);
        $mform->setHelpButton('var1', array('allowupload', get_string('allowuploadhelp', 'assignment_onlineaudio'), 'assignment_onlineaudio'));
        $mform->setDefault('var1', 1);

        $filenameoptions = array( 0 => get_string("nodefaultname", "assignment_onlineaudio"), 1 => get_string("defaultname1", "assignment_onlineaudio"), 2 =>get_string("defaultname2", "assignment_onlineaudio"));
        $mform->addElement('select', 'var2', get_string("defaultname", "assignment_onlineaudio"), $filenameoptions);
        $mform->setHelpButton('var2', array('defaultname', get_string('defaultnamehelp', 'assignment_onlineaudio'), 'assignment_onlineaudio'));
        $mform->setDefault('var2', 0);

        $mform->addElement('select', 'var3', get_string("allownameoverride", "assignment_onlineaudio"), $ynoptions);
        $mform->setHelpButton('var3', array('allownameoverride', get_string('allownameoverridehelp', 'assignment_onlineaudio'), 'assignment_onlineaudio'));
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


    function print_user_files($userid=0, $return=false,$mode='') {
        global $CFG, $USER;

        if (!$userid) {
            if (!isloggedin()) {
                return '';
            }
            $userid = $USER->id;
        }
    
        $filearea = $this->file_area_name($userid);

        $output = '';

        $submission = $this->get_submission($userid);

        $candelete = $this->can_delete_files($submission);
        $strdelete   = get_string('delete');

        if ($basedir = $this->file_area($userid)) {
            if ($files = get_directory_list($basedir)) {
                require_once($CFG->libdir . '/filelib.php');

                foreach ($files as $key => $file) {
                    $icon = mimeinfo('icon', $file);
                    $ffurl = get_file_url("$filearea/$file");

                    //died right here
                    //require_once($ffurl);
                    $output .= '<img src="'.$CFG->pixpath.'/f/'.$icon.'" class="icon" alt="'.$icon.'" />'.
                            '<a href="'.$ffurl.'" >'.$file.'</a>';

                    if ($candelete) {
                        $delurl = "$CFG->wwwroot/mod/assignment/delete.php?id={$this->cm->id}&amp;file=$file&amp;userid={$submission->userid}" . ($mode ? '&mode=submissions' : '');

                        $output .= '<a href="' . $delurl . '">&nbsp;'
                                . '<img title="' . $strdelete . '" src="' . $CFG->pixpath . '/t/delete.gif" class="iconsmall" alt="" /></a> ';
                    }
                    $output .='<br/>';

                }
            }
        }

        $output = '<div class="recorder_files">'.$output.'</div>';

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
        global $CFG;

        $file     = required_param('file', PARAM_FILE);
        $userid   = required_param('userid', PARAM_INT);
        $confirm  = optional_param('confirm', 0, PARAM_BOOL);
        $mode     = optional_param('mode', '', PARAM_ALPHA);

        require_login($this->course->id, false, $this->cm);

        if (empty($mode)) {
            $urlreturn = 'view.php';
            $optionsreturn = array('id'=>$this->cm->id);
            $returnurl = 'view.php?id='.$this->cm->id;
        } else {
            $urlreturn = 'submissions.php';
            $optionsreturn = array('id'=>$this->cm->id, 'mode'=>$mode, 'userid'=>$userid);
            $returnurl = "submissions.php?id={$this->cm->id}&amp;mode=$mode&amp;userid=$userid";
        }

        if (!$submission = $this->get_submission($userid) // incorrect submission
          or !$this->can_delete_files($submission)) {     // can not delete
            $this->view_header(get_string('delete'));
            notify(get_string('cannotdeletefiles', 'assignment'));
            print_continue($returnurl);
            $this->view_footer();
            die;
        }
        $dir = $this->file_area_name($userid);

        if (!data_submitted('nomatch') or !$confirm or !confirm_sesskey()) {
            $optionsyes = array ('id'=>$this->cm->id, 'file'=>$file, 'userid'=>$userid, 'confirm'=>1, 'sesskey'=>sesskey(), 'mode'=>$mode,  'sesskey'=>sesskey());
            if (empty($mode)) {
                $this->view_header(get_string('delete'));
            } else {
                print_header(get_string('delete'));
            }
            print_heading(get_string('delete'));
            notice_yesno(get_string('confirmdeletefile', 'assignment', $file), 'delete.php', $urlreturn, $optionsyes, $optionsreturn, 'post', 'get');
            if (empty($mode)) {
                $this->view_footer();
            } else {
                print_footer('none');
            }
            die;
        }

        $filepath = $CFG->dataroot.'/'.$dir.'/'.$file;
        if (file_exists($filepath)) {
            if (@unlink($filepath)) {
                $updated = new object();
                $updated->id = $submission->id;
                $updated->timemodified = time();
                if (update_record('assignment_submissions', $updated)) {
                    add_to_log($this->course->id, 'assignment', 'upload', //TODO: add delete action to log
                            'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
                    $submission = $this->get_submission($userid);
                    $this->update_grade($submission);
                }
                redirect($returnurl);
            }
        }

        // print delete error
        if (empty($mode)) {
            $this->view_header(get_string('delete'));
        } else {
            print_header(get_string('delete'));
        }
        notify(get_string('deletefilefailed', 'assignment'));
        print_continue($returnurl);
        if (empty($mode)) {
            $this->view_footer();
        } else {
            print_footer('none');
        }
        die;
    }
}
?>
