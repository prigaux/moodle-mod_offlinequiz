<?php
// This file is for Moodle - http://moodle.org/
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
 * The cron script called by a separate cron job to take load from the user frontend
 *
 * @package       mod
 * @subpackage    offlinequiz
 * @author        Juergen Zimmer
 * @copyright     2012 The University of Vienna
 * @since         Moodle 2.2+
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 **/

if (!defined('CLI_SCRIPT')) {
    define('CLI_SCRIPT', true);
}

define("OFFLINEQUIZ_MAX_CRON_JOBS", "5");

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->dirroot . '/mod/offlinequiz/evallib.php');
require_once($CFG->dirroot . '/mod/offlinequiz/lib.php');

function offlinequiz_evaluation_cron($jobid = 0) {
    global $CFG, $DB;

    raise_memory_limit(MEMORY_EXTRA);

    $runningjobs = $DB->count_records_sql("SELECT COUNT(*) FROM {offlinequiz_queue} WHERE status = 'processing'", array());

    if ($runningjobs >= OFFLINEQUIZ_MAX_CRON_JOBS) {
        echo date('Y-m-d-H:i') . ": Too many jobs running! Exiting!";
        return;
    }

    // TODO do this properly. Just for testing
    $transaction = $DB->start_delegated_transaction();

    $sql = "SELECT * FROM {offlinequiz_queue} WHERE status = 'new'";
    $params = array();
    if ($jobid) {
        $sql .= ' AND id = :jobid ';
        $params['jobid'] = $jobid;
    }
    $sql .= " ORDER BY id ASC";

    $job = false;
    if ($jobs = $DB->get_records_sql($sql, $params)) {
        $job = array_shift($jobs);
        $job->status = 'processing';
        $DB->set_field('offlinequiz_queue', 'status', 'processing', array('id' => $job->id));
        $job->timestart = time();
        $DB->set_field('offlinequiz_queue', 'timestart', $job->timestart, array('id' => $job->id));
    }

    $transaction->allow_commit();

    if (!$job) {
        echo "nothing to do!\n";
        return;
    }

    // TODO
    if (!$offlinequiz = $DB->get_record('offlinequiz', array('id' => $job->offlinequizid))) {
        $DB->set_field('offlinequiz_queue', 'status', 'error', array('id' => $job->id));
        return;
    }
    if (!$course = $DB->get_record('course', array('id' => $offlinequiz->course))) {
        print_error('nocourse', 'offlinequiz', $CFG->wwwroot . '/course/view.php?id=' . $COURSE->id, array('course' => $offlinequiz->course,
                'offlinequiz' => $offlinequiz->id));
    }

    if (!$cm = get_coursemodule_from_instance("offlinequiz", $offlinequiz->id, $course->id)) {
        $DB->set_field('offlinequiz_queue', 'status', 'error', array('id' => $job->id));
        print_error('cmmissing', 'offlinequiz', $CFG->wwwroot . '/course/view.php?id=' . $COURSE->id, $offlinequiz->id);
    }
    // TODO
    if (!$context = context_module::instance($cm->id)) {
        $DB->set_field('offlinequiz_queue', 'status', 'error', array('id' => $job->id));
    }
    $coursecontext = context_course::instance($course->id);

    offlinequiz_load_useridentification();

    $jobdata = $DB->get_records_sql("
            SELECT *
              FROM {offlinequiz_queue_data}
             WHERE queueid = :queueid
               AND status = 'new'",
            array('queueid' => $job->id));

    if (!$groups = $DB->get_records('offlinequiz_groups', array('offlinequizid' => $offlinequiz->id), 'number', '*', 0, $offlinequiz->numgroups)) {
        $DB->set_field('offlinequiz_queue', 'status', 'error', array('id' => $job->id));
        // TODO
        echo "missing groups";
        return;
    }

    list($maxquestions, $maxanswers, $formtype, $questionsperpage) =  offlinequiz_get_question_numbers($offlinequiz, $groups);

    $dirname = '';
    $doubleentry = 0;
    foreach ($jobdata as $data) {
        $starttime = time();

        $DB->set_field('offlinequiz_queue_data', 'status', 'processing', array('id' => $data->id));

        // we remember the directory name to be able to remove it later
        if (empty($dirname)) {
            $path_parts = pathinfo($data->filename);
            $dirname = $path_parts['dirname'];
        }

        set_time_limit(120);

        try {
            // Create a new scanner for every page.
            $scanner = new offlinequiz_page_scanner($offlinequiz, $context->id, $maxquestions, $maxanswers);

            // Try to load the image file.
            echo $job->id . ' evaluating ' . $data->filename . "\n";
            $scannedpage = $scanner->load_image($data->filename);
            echo $job->id . ' image loaded ' . $scannedpage->filename . "\n";
            $scannedpage->offlinequizid = $offlinequiz->id;

            // If we could load the image file, the status is 'ok', so we can check the page for errors.
            if ($scannedpage->status == 'ok') {
                // we autorotate so check_scanned_page will return a potentially new scanner and the scannedpage
                list($scanner, $scannedpage) = offlinequiz_check_scanned_page($offlinequiz, $scanner, $scannedpage, $job->importuserid, $coursecontext, true);
            } else {
                if (property_exists($scannedpage, 'id') && !empty($scannedpage->id)) {
                    $DB->update_record('offlinequiz_scanned_pages', $scannedpage);
                } else {
                    $scannedpage->id = $DB->insert_record('offlinequiz_scanned_pages', $scannedpage);
                }
            }
            echo $job->id . ' scannedpage id ' . $scannedpage->id . "\n";

            // if the status is still 'ok', we can process the answers. This potentially submits the page and
            // checks whether the result for a student is complete
            if ($scannedpage->status == 'ok') {
                // we can process the answers and submit them if possible
                $scannedpage = offlinequiz_process_scanned_page($offlinequiz, $scanner, $scannedpage, $job->importuserid, $questionsperpage, $coursecontext, true);
                echo $job->id . ' processed answers for ' . $scannedpage->id . "\n";
            } else if ($scannedpage->status == 'error' && $scannedpage->error == 'resultexists') {
                // already process the answers but don't submit them.
                $scannedpage = offlinequiz_process_scanned_page($offlinequiz, $scanner, $scannedpage, $job->importuserid, $questionsperpage, $coursecontext, false);

                // compare the old and the new result wrt. the choices
                $scannedpage = offlinequiz_check_different_result($scannedpage);
            }

            if ($scannedpage->status == 'ok' || $scannedpage->status == 'submitted'
                    || $scannedpage->status == 'suspended' || $scannedpage->error == 'missingpages') {
                // mark the file as processed
                $data->status = 'processed';
                $DB->set_field('offlinequiz_queue_data', 'status', 'processed', array('id' => $data->id));
            } else {
                $data->status = 'error';
                $DB->set_field('offlinequiz_queue_data', 'status', 'error', array('id' => $data->id));
                $DB->set_field('offlinequiz_queue_data', 'error', $scannedpage->error, array('id' => $data->id));
            }
            if ($scannedpage->error == 'doublepage') {
                $doubleentry++;
            }
        } catch (Exception $e) {
            echo $job->id . ' ' . $e->getMessage() . "\n";
            $DB->set_field('offlinequiz_queue_data', 'status', 'error', array('id' => $data->id));
            $DB->set_field('offlinequiz_queue_data', 'error', 'couldnotgrab', array('id' => $data->id));
            $scannedpage->status = 'error';
            $scannedpage->error = 'couldnotgrab';
            if ($scannedpage->id) {
                $DB->update_record('offlinequiz_scanned_pages', $scannedpage);
            } else {
                $DB->insert_record('offlinequiz_scanned_pages', $scannedpage);
            }
        }
    }
    offlinequiz_update_grades($offlinequiz);

    $job->timefinish = time();
    $DB->set_field('offlinequiz_queue', 'timefinish', $job->timefinish, array('id' => $job->id));
    $job->status = 'finished';
    $DB->set_field('offlinequiz_queue', 'status', 'finished', array('id' => $job->id));

    echo date('Y-m-d-H:i') . ": Import queue with id $job->id imported.\n\n";

    if ($user = $DB->get_record('user',  array('id' =>$job->importuserid))) {
        $mailtext = get_string('importisfinished', 'offlinequiz', format_text($offlinequiz->name, FORMAT_PLAIN));

        // how many pages have been imported successfully
        $countsql = "SELECT COUNT(id)
                       FROM {offlinequiz_queue_data}
                      WHERE queueid = :queueid
                        AND status = 'processed'";
        $params = array('queueid' => $job->id);

        $mailtext .= "\n\n". get_string('importnumberpages', 'offlinequiz', $DB->count_records_sql($countsql, $params));

        // how many pages have an error
        $countsql = "SELECT COUNT(id)
                       FROM {offlinequiz_queue_data}
                      WHERE queueid = :queueid
                        AND status = 'error'";

        $mailtext .= "\n". get_string('importnumberverify', 'offlinequiz', $DB->count_records_sql($countsql, $params));

        $mailtext .= "\n". get_string('importnumberexisting', 'offlinequiz', $doubleentry);

        $linkoverview = "$CFG->wwwroot/mod/offlinequiz/report.php?q={$job->offlinequizid}&mode=overview";
        $mailtext .= "\n\n". get_string('importlinkresults', 'offlinequiz', $linkoverview);

        $linkupload = "$CFG->wwwroot/mod/offlinequiz/report.php?q={$job->offlinequizid}&mode=rimport";
        $mailtext .= "\n". get_string('importlinkverify', 'offlinequiz', $linkupload);

        $mailtext .= "\n\n". get_string('importtimestart', 'offlinequiz', userdate($job->timestart));
        $mailtext .= "\n". get_string('importtimefinish', 'offlinequiz', userdate($job->timefinish));

        email_to_user($user, $CFG->noreplyaddress, get_string('importmailsubject', 'offlinequiz'), $mailtext);
    }
    echo "removing dir " . $dirname . "\n";
    remove_dir($dirname);
}

require_once($CFG->libdir . '/clilib.php');
list($options, $unrecognized) = cli_get_params(array('cli'=>false), array('h'=>'help'));

if (array_key_exists('cli', $options) && $options['cli']) {
    echo date('Y-m-d-H:i') . ': ';
    offlinequiz_evaluation_cron();
    echo 'done.\n';
    die();
}
