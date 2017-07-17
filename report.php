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
 * This file defines the quiz downloadsubmissions report class.
 *
 * @package   quiz_downloadsubmissions
 * @copyright 2017 IIT, Bombay
 * @author	  Kashmira Nagwekar
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport.php');
require_once($CFG->dirroot . '/mod/quiz/report/downloadsubmissions/downloadsubmissions_options.php');
require_once($CFG->dirroot . '/mod/quiz/report/downloadsubmissions/downloadsubmissions_form.php');
require_once($CFG->dirroot . '/mod/quiz/report/downloadsubmissions/ds_last_responses_table.php');
require_once($CFG->dirroot . '/mod/quiz/report/downloadsubmissions/ds_first_or_all_responses_table.php');

/**
 * Quiz report subclass for the downloadsubmissions report.
 *
 * This report lists some combination of
 *  * what question each student saw (this makes sense if random questions were used).
 *  * the response they gave,
 *  * and what the right answer is.
 *
 * Like the overview report, there are options for showing students with/without
 * attempts, and for deleting selected attempts.
 *
 * @copyright 1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_downloadsubmissions_report extends quiz_attempts_report {

	public function display($quiz, $cm, $course) {
        global $OUTPUT, $DB;

        list($currentgroup, $studentsjoins, $groupstudentsjoins, $allowedjoins) = $this->init(
                'downloadsubmissions', 'quiz_downloadsubmissions_settings_form', $quiz, $cm, $course);

        $options = new quiz_downloadsubmissions_options('downloadsubmissions', $quiz, $cm, $course);

        if ($fromform = $this->form->get_data()) {
            $options->process_settings_from_form($fromform);
        } else {
        	$options->process_settings_from_params();	// for checkboxes required for selection
        }

        // Download file submissions for essay questions.
        // Currently, returns essay submissions for last attempt only
        if ($data = $this->form->get_data()) {
        	if (!empty($data->downloadsubmissions)) {
        		$users_attempts = $this->get_users_attempts($quiz, $course);
        		$this->download_essay_submissions($quiz, $cm, $course, $users_attempts);
        	}
        }

        $this->form->set_data($options->get_initial_form_data());

        // Load the required questions.
        $questions = quiz_report_get_significant_questions($quiz);

        // Prepare for downloading, if applicable.
        $courseshortname = format_string($course->shortname, true,
                array('context' => context_course::instance($course->id)));
        if ($options->whichtries === question_attempt::LAST_TRY) {
            $tableclassname = 'quiz_ds_last_responses_table';
        } else {
            $tableclassname = 'quiz_ds_first_or_all_responses_table';
        }

        $table = new $tableclassname($quiz, $this->context, $this->qmsubselect,
                $options, $groupstudentsjoins, $studentsjoins, $questions, $options->get_url());
        $filename = quiz_report_download_filename(get_string('responsesfilename', 'quiz_downloadsubmissions'),
                $courseshortname, $quiz->name);
        $table->is_downloading($options->download, $filename,
                $courseshortname . ' ' . format_string($quiz->name, true));

        if ($table->is_downloading()) {
            raise_memory_limit(MEMORY_EXTRA);
        }

        $this->hasgroupstudents = false;
        if (!empty($groupstudentsjoins->joins)) {
            $sql = "SELECT DISTINCT u.id
                    FROM {user} u
                    $groupstudentsjoins->joins
                    WHERE $groupstudentsjoins->wheres";
            $this->hasgroupstudents = $DB->record_exists_sql($sql, $groupstudentsjoins->params);
        }

        $hasstudents = false;
        if (!empty($studentsjoins->joins)) {
            $sql = "SELECT DISTINCT u.id
                    FROM {user} u
                    $studentsjoins->joins
                    WHERE $studentsjoins->wheres";
            $hasstudents = $DB->record_exists_sql($sql, $studentsjoins->params);
        }
        if ($options->attempts == self::ALL_WITH) {
            // This option is only available to users who can access all groups in
            // groups mode, so setting allowed to empty (which means all quiz attempts
            // are accessible, is not a security problem.
            $allowedjoins = new \core\dml\sql_join();
        }

        // Start output.
        if (!$table->is_downloading()) {
            // Only print headers if not asked to download data.
            $this->print_header_and_tabs($cm, $course, $quiz, $this->mode);
        }

        if ($groupmode = groups_get_activity_groupmode($cm)) {
            // Groups are being used, so output the group selector if we are not downloading.
        	if (!$table->is_downloading()) {
                groups_print_activity_menu($cm, $options->get_url());
            }
        }

        // Print information on the number of existing attempts.
        if (!$table->is_downloading()) {
            // Do not print notices when downloading.
            if ($strattemptnum = quiz_num_attempt_summary($quiz, $cm, true, $currentgroup)) {
                echo '<div class="quizattemptcounts">' . $strattemptnum . '</div>';
            }
        }

        $hasquestions = quiz_has_questions($quiz->id);
        if (!$table->is_downloading()) {
            if (!$hasquestions) {
                echo quiz_no_questions_message($quiz, $cm, $this->context);
            } else if (!$hasstudents) {
                echo $OUTPUT->notification(get_string('nostudentsyet'));
            } else if ($currentgroup && !$this->hasgroupstudents) {
                echo $OUTPUT->notification(get_string('nostudentsingroup'));
            }

            // Print the display options.
            $this->form->display();
        }

        $hasstudents = $hasstudents && (!$currentgroup || $this->hasgroupstudents);

        if ($hasquestions && ($hasstudents || $options->attempts == self::ALL_WITH)) {

            list($fields, $from, $where, $params) = $table->base_sql($allowedjoins);

            $table->set_count_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);

            $table->set_sql($fields, $from, $where, $params);

            if (!$table->is_downloading()) {
                // Print information on the grading method.
                if ($strattempthighlight = quiz_report_highlighting_grading_method(
                        $quiz, $this->qmsubselect, $options->onlygraded)) {
                    echo '<div class="quizattemptcounts">' . $strattempthighlight . '</div>';
                }
            }

            // Define table columns.
            $columns = array();
            $headers = array();

            if (!$table->is_downloading() && $options->checkboxcolumn) {
                $columns[] = 'checkbox';
                $headers[] = null;
            }

            $this->add_user_columns($table, $columns, $headers);
            $this->add_state_column($columns, $headers);

            if ($table->is_downloading()) {
                $this->add_time_columns($columns, $headers);
            }

            $this->add_grade_columns($quiz, $options->usercanseegrades, $columns, $headers);

            foreach ($questions as $id => $question) {
                if ($options->showqtext) {
                    $columns[] = 'question' . $id;
                    $headers[] = get_string('questionx', 'question', $question->number);
                }
                if ($options->showresponses) {
                    $columns[] = 'response' . $id;
                    $headers[] = get_string('responsex', 'quiz_downloadsubmissions', $question->number);

                }
                if ($options->showright) {
                    $columns[] = 'right' . $id;
                    $headers[] = get_string('rightanswerx', 'quiz_downloadsubmissions', $question->number);
                }
            }

            $table->define_columns($columns);
            $table->define_headers($headers);
            $table->sortable(true, 'uniqueid');

            // Set up the table.
            $table->define_baseurl($options->get_url());

            $this->configure_user_columns($table);

            $table->no_sorting('feedbacktext');
            $table->column_class('sumgrades', 'bold');

            $table->set_attribute('id', 'responses');

            $table->collapsible(true);

            $table->out($options->pagesize, true);
        }

        return true;
    }

    public function get_users_attempts($quiz, $course){
    	global $DB;

    	$sql1 = "SELECT DISTINCT CONCAT(u.id, '#', COALESCE(qta.id, 0)) AS uniqueid,
        (CASE WHEN (quiza.state = 'finished' AND NOT EXISTS (
        		SELECT 1 FROM {quiz_attempts} qa2
        		WHERE qa2.quiz = quiza.quiz AND
        		qa2.userid = quiza.userid AND
        		qa2.state = 'finished' AND (
        				COALESCE(qa2.sumgrades, 0) > COALESCE(quiza.sumgrades, 0) OR
        				(COALESCE(qa2.sumgrades, 0) = COALESCE(quiza.sumgrades, 0) AND qa2.attempt < quiza.attempt)
        				))) THEN 1 ELSE 0 END) AS gradedattempt,
        				quiza.uniqueid AS usageid,
        				quiza.id AS attempt,
    					quiza.attempt AS userattempt,
        				u.id AS userid,
        				u.username AS username,
        				u.idnumber,u.firstnamephonetic,u.lastnamephonetic,u.middlename,u.alternatename,u.firstname,u.lastname,
        				u.picture,
        				u.imagealt,
        				u.institution,
        				u.department,
        				u.email,
    					qta.id as qaid,
        				qta.questionid as questionid,
        				qta.slot as slot,
        				quiza.state,
        				quiza.sumgrades,
        				quiza.timefinish,
        				quiza.timestart,
        CASE WHEN quiza.timefinish = 0 THEN null
        WHEN quiza.timefinish > quiza.timestart THEN quiza.timefinish - quiza.timestart
        ELSE 0 END AS duration

        FROM
        {user} u
        LEFT JOIN {quiz_attempts} quiza ON
        quiza.userid = u.id AND quiza.quiz = $quiz->id
        JOIN {question_attempts} qta ON
        qta.questionusageid = quiza.id
        JOIN {user_enrolments} ej1_ue ON ej1_ue.userid = u.id
        JOIN {enrol} ej1_e ON (ej1_e.id = ej1_ue.enrolid AND ej1_e.courseid = $course->id)

        WHERE
        quiza.preview = 0 AND quiza.id IS NOT NULL AND 1 = 1 AND u.deleted = 0";
    	$users_attempts = $DB->get_records_sql($sql1);

    	return $users_attempts;
    }

    /**
     * Download a zip file of essay submissions.
     *
     * @param object $quiz
     * @param cm $cm
     * @param course $course
     * @param array $students_attempts Array of student's attempts to download essay submissions in a zip file
     * @return string - If an error occurs, this will contain the error notification.
     */
    protected function download_essay_submissions($quiz, $cm, $course, $students_attempts) {
    	global $CFG, $DB, $PAGE, $OUTPUT;

    	// More efficient to load this here.
    	require_once($CFG->libdir.'/filelib.php');

    	// Increase the server timeout to handle the creation and sending of large zip files.
    	core_php_time_limit::raise();

    	// Build a list of files to zip.
    	$filesforzipping = array();
    	$fs = get_file_storage();

    	// Construct the zip file name.
    	$filename = clean_filename($course->fullname . '-' .
    			$quiz->name . '-' .
//     			$groupname.
    			$cm->id . '.zip');

    	// Get all the files for each student.
    	foreach ($students_attempts as $student) {
    		$userid = $student->userid;
    		$questionid = 'Q'.$student->questionid;
    		$prefix1 = str_replace('_', ' ', $questionid);
    		$prefix2 = '';
    		if(!empty($student->idnumber)) {
    			$prefix2 .= $student->idnumber;
    		} else {
    			$prefix2 .= $student->username;
    		}
    		$prefix2 .= ' - ' . str_replace('_', ' ', fullname($student));

    		$questionattemptid = $student->qaid;
    		$dm = new question_engine_data_mapper();
    		$qa = $dm->load_question_attempt($questionattemptid);

    		if($qa->get_question()->get_type_name() == 'essay') {
				$files = $qa->get_last_qt_files('attachments', '28');
	    		foreach ($files as $zipfilepath => $file) {
// 	    			$attemptid = 'Attempt'.$student->userattempt;
	    			$zipfilename = $file->get_filename();
	    			$prefixedfilename = clean_filename($prefix1 .
	    			    	'/' .
	    					$prefix2);
	    			$pathfilename = $prefix1 . '/' . $prefix2 . $file->get_filepath() . $zipfilename;
	    			$pathfilename = clean_param($pathfilename, PARAM_PATH);
	    			$filesforzipping[$pathfilename] = $file;
	    		}
    		}
    	}

    	$result = '';
    	if (count($filesforzipping) == 0) {
    		$result.= $OUTPUT->notification(get_string('nosubmission', 'quiz_downloadsubmissions'));
    	} else if ($zipfile = $this->pack_files($filesforzipping)) {
    		// Send file and delete after sending.
    		send_temp_file($zipfile, $filename);
    		// We will not get here - send_temp_file calls exit.
    	}

    	return $result;
    }

    /**
     * Generate zip file from array of given files.
     *
     * @param array $filesforzipping - array of files to pass into archive_to_pathname.
     *                                 This array is indexed by the final file name and each
     *                                 element in the array is an instance of a stored_file object.
     * @return path of temp file - note this returned file does
     *         not have a .zip extension - it is a temp file.
     */
    public function pack_files($filesforzipping) {
    	global $CFG;
    	// Create path for new zip file.
    	$tempzip = tempnam($CFG->tempdir . '/', 'quiz_essay_qt_attachments_');

    	// Zip files.
    	$zipper = new zip_packer();
    	if ($zipper->archive_to_pathname($filesforzipping, $tempzip)) {
    		return $tempzip;
    	}
    	return false;
    }
}