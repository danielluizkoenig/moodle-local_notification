<?php
namespace local_notification\task;

defined('MOODLE_INTERNAL') || die();

class send_popup_notification_task extends \core\task\adhoc_task {
    public function execute() {
        $data = $this->get_custom_data();
        
        $eventdata = new \core\message\message();
        $eventdata->component         = 'local_notification';
        $eventdata->name             = 'notification';
        $eventdata->userfrom         = $data->userfrom;
        $eventdata->userto           = $data->userto;
        $eventdata->subject          = $data->subject;
        $eventdata->fullmessage      = $data->fullmessage;
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml  = $data->fullmessagehtml;
        $eventdata->smallmessage     = $data->subject;
        $eventdata->notification     = 1;
        $eventdata->contexturlname = $data->contexturlname;
        $eventdata->contexturl = $data->contexturl;

        message_send($eventdata);
    }
}