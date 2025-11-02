<?php

/**
 * Local notification plugin.
 *
 * @package    local_notification
 * @copyright  2025
 * @author     SENAI Soluções Digitais - SC <sd-tribo-ava@sc.senai.br>
 */

namespace local_notification\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use local_notification\local_notification;
use DateTime;
use Exception;
use stdClass;
use core_user;

require_once $CFG->dirroot . '/message/lib.php';

class notification_task extends scheduled_task
{

    private $enable_email = false;
    private $enable_popup = false;

    public function get_name()
    {
        return get_string('pluginname', local_notification::PLUGINNAME);
    }

    public function replaceMsgVars($name, $email, $content)
    {
        return str_replace("%%ALUNO_NOME%%", $name, str_replace("%%ALUNO_EMAIL%%", $email, $content));
    }

    public function iterateNotification($notification)
    {
        global $CFG;
        $dispatch = new local_notification();
        $courseName = $dispatch->get_course_name($notification->course_id);

        if (!is_numeric($notification->course_id) || $notification->course_id <= 0) {
            throw new Exception('ID de curso inválido: ' . $notification->course_id);
        }

        $notification->descriptionHTML = '<a href="' . $CFG->wwwroot . '/course/view.php?id=' .
            $notification->course_id . '">' . $courseName . PHP_EOL . PHP_EOL . '</a><br>' . $notification->content;
        $time_value = $this->process_time_value($notification->time);
        mtrace('Processing course ' . $notification->course_id . ' -> ' . $time_value . ' days. Notification id:' . $notification->id);
        $notification->query = str_replace(
            ["%%COURSEID%%", "%%TIME%%"],
            [$notification->course_id, $time_value],
            $notification->query
        );
        return $notification;
    }

    public function process_time_value($time_value)
    {
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $time_value)) {
            $date = DateTime::createFromFormat('d/m/Y', $time_value);
            if ($date === false) {
                throw new Exception('Invalid format to date: %%TIME%%: ' . $time_value);
            }
            $now = new DateTime();
            $interval = $now->diff($date);
            if ($interval->invert == 0) {
                throw new Exception('The date %%TIME%% not be future: ' . $time_value);
            }
            return $interval->days;
        }
        if (preg_match('/^\d+(,\d+)*$/', $time_value)) {
            return $time_value;
        }
        throw new Exception('Invalid value for %%TIME%%: ' . $time_value);
    }

    public function constructMsg($u, $notification)
    {
        $name = $u->nome_completo;
        $msg = new stdClass();
        $msg->notificationid = $notification->id;
        $msg->subject  = $this->replaceMsgVars($name, $u->email, $notification->subject);
        $msg->bodyhtml = $this->replaceMsgVars($name, $u->email, $notification->descriptionHTML);
        $msg->bodytext = strip_tags($msg->bodyhtml);
        return $msg;
    }

    public function sendNotification($u, $notification, $msg, $total)
    {
        $from = core_user::get_noreply_user();

        if ($this->enable_email) {
            $u->mailformat = 1;
            //mtrace('Sending email to ' . $u->email . ' (ID: ' . $u->id . ')');
            email_to_user(
                $u,
                $from,
                $msg->subject,
                html_to_text($msg->bodyhtml),
                $msg->bodyhtml,
                null,
                null,
                true
            );
        }

        if ($this->enable_popup) {
            // Create adhoc task
            $task = new \local_notification\task\send_popup_notification_task();
            $dispatch = new local_notification();
            $courseName = $dispatch->get_course_name($notification->course_id);
            
            $taskdata = new \stdClass();
            $taskdata->userfrom = $from;
            $taskdata->userto = $u->id;
            $taskdata->subject = html_to_text($msg->subject);
            $taskdata->fullmessage = html_to_text($msg->bodyhtml);
            $taskdata->fullmessagehtml = $msg->bodyhtml;
            $taskdata->contexturl = (new \moodle_url('/course/view.php', ['id' => $notification->course_id]))->out(false);
            $taskdata->contexturlname = $courseName;
            $taskdata->notification = 1;
    
            $task->set_custom_data($taskdata);
            
            // Queue the task
            \core\task\manager::queue_adhoc_task($task);
        }

        if ($this->enable_email || $this->enable_popup) {
            $dispatch = new local_notification();
            $dispatch->store_deliveredtime($u->id, $notification->id);
            $total++;
        }
        return $total;
    }

    public function processNotification($notification, $total)
    {
        global $DB;

        $today_start = strtotime('today midnight');
        $today_end = strtotime('tomorrow midnight') - 1;

        if ($query = $DB->get_records_sql($notification->query)) {


            foreach ($query as $u) {
               $msg = $this->constructMsg($u, $notification);
               $total = $this->sendNotification($u, $notification, $msg, $total);
            }
        }
        return $total;
    }

    public function getNotifications()
    {
        global $DB;
        $notifications = "SELECT n.id, n.time, n.subject, n.content, nq.description, nq.query, n.course_id
                    FROM {notification} n
                    JOIN {notification_query} nq ON nq.id = n.notification_query_id
                    WHERE status=1";

                    
        $total = 0;
        if (count($notifications = $DB->get_records_sql($notifications)) > 0) {
            foreach ($notifications as $notification) {
                try {
                    $notification = $this->iterateNotification($notification);
                    $total = $this->processNotification($notification, $total);
                } catch (Exception $e) {
                    mtrace('Error processing notification ID ' . $notification->id . ': ' . $e->getMessage());
                }
            }
        }
        return $total;
    }

    public function execute()
    {
        $this->enable_email = get_config(local_notification::PLUGINNAME, 'enableemail');
        $this->enable_popup = get_config(local_notification::PLUGINNAME, 'enablepopup');
        if ($this->enable_email || $this->enable_popup) {
            $start_time = time();
            $totalSend = $this->getNotifications();
            mtrace('Total notifications sended: ' . $totalSend);
            mtrace('Total time: ' . (time() - $start_time) . ' second(s)');
        } else {
            mtrace('No send type configured. Check plugin settings: admin/settings.php?section=local_notification');
        }
    }
}
