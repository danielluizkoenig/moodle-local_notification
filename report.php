<?php
/**
 * Relatório de notificações por curso
 *
 * @package    local_notification
 * @copyright  2025
 * @author     SENAI Soluções Digitais - SC <sd-tribo-ava@sc.senai.br>
 */

require_once('../../config.php');
require_login();
require_once($CFG->libdir . '/tablelib.php');
use flexible_table;
$context = context_system::instance();
require_capability('local/notification:view', $context);

$userid = $USER->id;
$courseid = optional_param('courseid', 0, PARAM_INT);
$notificationid = optional_param('notificationid', 0, PARAM_INT);

$PAGE->set_url('/local/notification/report.php');
$PAGE->set_title(get_string('report_title', 'local_notification'));
$PAGE->set_heading(get_string('report_title', 'local_notification'));

echo $OUTPUT->header();

// Formulário de filtros
echo html_writer::start_tag('form', array('method' => 'get'));
echo html_writer::start_tag('div', array('class' => 'row mb-3'));

// Filtro por curso
echo html_writer::start_tag('div', array('class' => 'col-md-5'));
echo html_writer::tag('label', get_string('course'), array('for' => 'courseid'));


$is_siteadmin = is_siteadmin($userid);
$courses = [];

if ($is_siteadmin) {
    // Admin sees all courses
    $notifications = $DB->get_records_sql('SELECT DISTINCT course_id FROM {notification} n JOIN {course} c ON c.id = n.course_id WHERE status = 1 AND c.visible = 1');
    foreach ($notifications as $notification) {
        $courses[] = $notification->course_id;
    }
    $course_ids = $courses;
} else {
    // Regular user
    $user_courses = enrol_get_users_courses($userid, true, null);
    $course_ids = array_keys($user_courses);
}
if (!empty($course_ids)) {
    list($insql, $inparams) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
    $courses = $DB->get_records_sql("SELECT DISTINCT c.id, c.fullname FROM {course} c JOIN {notification} n ON c.id = n.course_id WHERE c.id $insql ORDER BY c.fullname", $inparams);
} else {
    $courses = array();
}
$course_options = array(0 => get_string('all'));
foreach ($courses as $course) {
    $course_options[$course->id] = $course->fullname;
}
echo html_writer::select($course_options, 'courseid', $courseid, false, array('class' => 'form-control'));
echo html_writer::end_tag('div');

// Filtro por notificação
echo html_writer::start_tag('div', array('class' => 'col-md-5'));
echo html_writer::tag('label', get_string('notification', 'local_notification'), array('for' => 'notificationid'));

// Filtrar notificações por curso selecionado
if ($courseid > 0) {
    $notifications = $DB->get_records_sql("SELECT id, subject FROM {notification} WHERE course_id = ? ORDER BY subject", [$courseid]);
} else {
    $notifications = array();
}

$notification_options = array(0 => get_string('all'));
foreach ($notifications as $notification) {
    $notification_options[$notification->id] = $notification->subject;
}
echo html_writer::select($notification_options, 'notificationid', $notificationid, false, array('class' => 'form-control'));
echo html_writer::end_tag('div');
echo html_writer::start_tag('div', array('class' => 'col-md-2'));
echo html_writer::tag('button', get_string('filter'), array('type' => 'submit', 'class' => 'btn btn-primary'));
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::end_tag('form');

// Consulta dos dados
if (empty($course_ids)) {
    $records = array();
} else {
    list($course_insql, $course_inparams) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'course');
    
    $sql = "SELECT n.id, n.subject, n.status, n.course_id, c.fullname as course_name, 
                   nq.description as notification_type,
                   COUNT(nh.id) as total_sent,
                   MAX(nh.delivered) as last_sent
            FROM {notification} n
            JOIN {course} c ON c.id = n.course_id
            LEFT JOIN {notification_query} nq ON nq.id = n.notification_query_id
            LEFT JOIN {notification_history} nh ON n.id = nh.notification_id
            WHERE c.id $course_insql";
    
    $params = $course_inparams;

    if ($courseid > 0) {
        $sql .= " AND n.course_id = :courseid";
        $params['courseid'] = $courseid;
    }
    if ($notificationid > 0) {
        $sql .= " AND n.id = :notificationid";
        $params['notificationid'] = $notificationid;
    }
    
    $sql .= " GROUP BY n.id, n.subject, n.status, n.course_id, c.fullname, nq.description";
    
    // Ordenação padrão
    $sort = optional_param('tsort', 'course', PARAM_ALPHA);
    $dir = optional_param('tdir', 'ASC', PARAM_ALPHA);
    
    $order_map = [
        'course' => 'c.fullname',
        'subject' => 'n.subject', 
        'type' => 'nq.description',
        'status' => 'n.status',
        'total_sent' => 'COUNT(nh.id)',
        'last_sent' => 'MAX(nh.delivered)'
    ];
    
    $order_field = isset($order_map[$sort]) ? $order_map[$sort] : 'c.fullname';
    $sql .= " ORDER BY {$order_field} {$dir}";
    
    $records = $DB->get_records_sql($sql, $params);
}

// Tabela de resultados
if (!empty($records)) {
    $table = new flexible_table('notification-report');
    $table->define_columns(array('course', 'subject', 'type', 'status', 'total_sent', 'last_sent', 'details'));
    $table->define_headers(array(
        get_string('course'),
        get_string('n_subject', 'local_notification'),
        get_string('n_type', 'local_notification'),
        get_string('status'),
        get_string('total_sent', 'local_notification'),
        get_string('last_sent', 'local_notification'),
        get_string('details', 'local_notification')
    ));
    
    $table->sortable(true, 'course');
    $table->no_sorting('details');
    $table->define_baseurl($PAGE->url);
    $table->setup();
    
    foreach ($records as $record) {
        $status = $record->status ? get_string('active') : get_string('inactive');
        $last_sent = $record->last_sent ? userdate($record->last_sent) : '-';
        
        $details_link = html_writer::link(
            new moodle_url('/local/notification/report_details.php', array('id' => $record->id)),
            get_string('details', 'local_notification'),
            array('class' => 'btn btn-sm btn-info')
        );
        
        $course_link = html_writer::link(
            new moodle_url('/course/view.php', array('id' => $record->course_id)),
            $record->course_name
        );
        
        $table->add_data(array(
            $course_link,
            $record->subject,
            $record->notification_type ?: '-',
            $status,
            $record->total_sent,
            $last_sent,
            $details_link
        ));
    }
    
    $table->finish_output();
} else {
    echo $OUTPUT->notification(get_string('no_data', 'local_notification'), 'info');
}

echo $OUTPUT->footer();