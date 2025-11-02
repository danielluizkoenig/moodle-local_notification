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
 * Course notification form.
 *
 * @package    local_notification
 * @copyright  2025 SENAI Soluções Digitais - SC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_notification;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/local/notification/locallib.php');

class form_course extends \moodleform
{
    public $action;
    public $notification;
    private $context;
    private $courseid;
    private $maxFilesize = 15728640;

    function __construct($actionurl, $action, $notification)
    {
        global $USER;

        $this->action = $action;
        $this->notification = $notification;
        $this->courseid = $actionurl->get_param('id');
        $this->context = \context_user::instance($USER->id);
        parent::__construct($actionurl);
    }

    private function check_edit_mode($mform)
    {
        $content = '';
        if ($this->action == 'edit') {
            $this->data_edit_preprocessing();
            $mform->setDefault('subject', $this->notification->subject);
            $mform->setDefault('notification_query_id', $this->notification->notification_query_id);
            $mform->setDefault('time', $this->notification->time);
            $content = $this->notification->content;
        }
        return $content;
    }
    private function add_validations($mform)
    {
        $mform->addRule('subject', get_string('required'), 'required');
        $mform->addRule('content', get_string('required'), 'required');
        $mform->addRule('notification_query_id', get_string('required'), 'required');

        if ($mform->getElementValue('notification_query_id') != 5) {
            $mform->addRule('time', get_string('required'), 'required');
        }

        $mform->addRule('time', get_string('numbers_and_symbols_only', local_notification::PLUGINNAME), 'regex', '/^(?=.*\d)[\d\/,]+$/', 'client');
        $mform->addRule('time', get_string('future_date', local_notification::PLUGINNAME), 'callback', function ($value) {
            $dates = explode(',', $value);
            foreach ($dates as $date) {
                if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
                    return true;
                }
                $selected_date = strtotime(str_replace('/', '-', $date));
                $current_date = strtotime('today');
                if ($selected_date < $current_date) {
                    return false;
                }
            }
            return true;
        });

        $mform->addRule('time', get_string('non_negative_number', local_notification::PLUGINNAME), 'callback', function ($value) {
            $entries = explode(',', $value);
            foreach ($entries as $entry) {
                if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $entry)) {
                    continue; // Ignore date entries
                }
                $numbers = explode(',', $entry);
                foreach ($numbers as $number) {
                    if (!is_numeric($number) || $number < 0) {
                        return false;
                    }
                }
            }
            return true;
        });
    }

    protected function definition() {
        global $DB, $COURSE, $CFG, $PAGE;

        $mform = $this->_form;
        $content = $this->check_edit_mode($mform);

        $mform->addElement('text', 'subject', get_string('n_subject', local_notification::PLUGINNAME), ['maxlength' => 255, 'size' => 255]);
        $mform->addElement('html', get_string('htmlvars', local_notification::PLUGINNAME));
        $mform->addElement(
            'editor',
            'content',
            get_string('n_description', local_notification::PLUGINNAME),
            ['rows' => '15', 'cols' => '50', 'autosave' => 'false'],
            ['maxfiles' => EDITOR_UNLIMITED_FILES, 'maxbytes' => 1048576, 'accepted_types' => '*']
        );
        $mform->setType('content', PARAM_RAW);
        $mform->setDefault('content', ['text' => $content]);

        if (has_capability('moodle/site:config', \context_system::instance())) {
            $urlConfig = new \moodle_url('/local/notification/manage_queries.php');
            $urlConfig = \html_writer::link($urlConfig, get_string('n_manage', local_notification::PLUGINNAME));
            $configHelp = '<div class="row"><div class="col-9 offset-3 pl-5">' . $urlConfig . '</div></div>';
            $mform->addElement('html', $configHelp);
        }

        $notification_query = [];
        $notification_query[''] = '';
        $notifications = "SELECT id,description FROM {notification_query}  ORDER BY description ASC";

        foreach ($DB->get_records_sql($notifications) as $notification) {
            $notification_query[$notification->id] = $notification->description;
        }
        asort($notification_query);
        $notification_query[''] = get_string('selecttype', local_notification::PLUGINNAME);

        $mform->addElement('select', 'notification_query_id', get_string('n_typenotification', local_notification::PLUGINNAME), $notification_query);

        $mform->addElement('text', 'time', get_string('n_time', local_notification::PLUGINNAME));
        $mform->setType('time', PARAM_NOTAGS);

        $mform->addElement('html', '<style>#id_error_time{font-size: 15px; }</style>');

        $mform->addHelpButton('time', 'n_time', local_notification::PLUGINNAME);

        if ($this->action == 'edit') {
            $status = ['0' => get_string('inactive'), '1' => get_string('active')];
            $mform->addElement('select', 'status', get_string('status'), $status);
            $mform->setDefault('status', $this->notification->status);
        }

        $PAGE->requires->js_call_amd(local_notification::PLUGINNAME . '/form_control', 'init', [$COURSE->enddate]);

        $this->add_validations($mform);

        // Add before submit button
        $mform->addElement('text', 'days_after', get_string('days_after', 'local_notification'));
        $mform->setType('days_after', PARAM_INT);
        $mform->setDefault('days_after', $this->notification->days_after ?? 0);
        $mform->addHelpButton('days_after', 'days_after', 'local_notification');
        $mform->addRule('days_after', null, 'numeric', null, 'client');

        $this->add_action_buttons(true, get_string('submit'));
    }

    public function validation($data, $files)
    {
        $errors = parent::validation($data, $files);
        $total_size = 0;

        if (!empty($data['content'])) {
            $tinyText = $data['content']['text'];
            $itemid = $data['content']['itemid'];

            $files = $this->validateDraftAreaFiles($tinyText, $itemid);

            foreach ($files as $file) {
                $total_size += $file->get_filesize();
            }

            if ($total_size > $this->maxFilesize) {
                $errors['content'] = get_string('file_big_size', local_notification::PLUGINNAME);
            }
        } else {
            $errors['content'] = get_string('content_empty', local_notification::PLUGINNAME);
        }
        return $errors;
    }

    public function validateDraftAreaFiles($tinyText, $itemid)
    {
        $fs = get_file_storage();
        $files = $fs->get_area_files($this->context->id, 'user', 'draft', $itemid);

        preg_match_all(
            '/<(a|img|video|source)\s[^>]*(href|src)=["\'](https?:\/\/[^\s"\'<>]+)["\']/',
            $tinyText,
            $matches
        );
        $urls = $matches[3];
        $urlFilenames = array_map('urldecode', array_map(
            function ($url) {
                return basename(parse_url($url, PHP_URL_PATH));
            },
            $urls
        ));
        foreach ($files as $file) {
            $filename = trim($file->get_filename());
            if ($file->is_directory()) {
                continue;
            }
            if (!in_array($filename, array_map('trim', $urlFilenames))) {
                $file->delete();
            }
        }

        return $fs->get_area_files($this->context->id, 'user', 'draft', $itemid);
    }

    public function data_edit_preprocessing()
    {
        global $DB;
        $contextCourse = \context_course::instance($this->courseid);

        if (!empty($files = local_notification::getLocalNotificationFiles($this->notification->id))) {
            $fs = get_file_storage();
            $itemid = (reset($files)->itemid);

            $fs->delete_area_files($this->context->id, 'user', 'draft', $itemid);

            $permanentFiles = $fs->get_area_files(
                $contextCourse->id,
                local_notification::PLUGINNAME,
                'notification_file',
                $itemid
            );
            foreach ($permanentFiles as $file) {
                if (!$file->is_directory()) {
                    copyPermanentFileToDraftArea($this->context->id, $contextCourse->id, $file, $itemid, null);
                }
            }
            $this->notification->content = setDraftfileUrl($this->notification->content, $this->context->id);
        }
    }
}
