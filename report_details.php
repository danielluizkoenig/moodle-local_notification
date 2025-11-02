<?php
/**
 * Detalhes do relatório de notificações
 *
 * @package    local_notification
 * @copyright  2025
 * @author     SENAI Soluções Digitais - SC <sd-tribo-ava@sc.senai.br>
 */

require_once('../../config.php');
require_login();

$notificationid = required_param('id', PARAM_INT);
$datefrom_str = optional_param('datefrom', '', PARAM_TEXT);
$dateto_str = optional_param('dateto', '', PARAM_TEXT);
$datefrom = $datefrom_str ? strtotime($datefrom_str) : 0;
$dateto = $dateto_str ? strtotime($dateto_str) : 0;
$accessed = optional_param('accessed', '', PARAM_ALPHA);
$sort = optional_param('sort', 'delivered', PARAM_ALPHA);
$dir = optional_param('dir', 'desc', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 100;

$PAGE->set_url('/local/notification/report_details.php', array('id' => $notificationid, 'datefrom' => $datefrom_str, 'dateto' => $dateto_str, 'accessed' => $accessed, 'sort' => $sort, 'dir' => $dir, 'page' => $page));
$PAGE->set_title(get_string('report_details_title', 'local_notification'));
$PAGE->set_heading(get_string('report_details_title', 'local_notification'));

echo $OUTPUT->header();

// Buscar dados da notificação
$notification = $DB->get_record_sql(
    "SELECT n.*, c.fullname as course_name, nq.description as type_name
     FROM {notification} n
     JOIN {course} c ON c.id = n.course_id
     LEFT JOIN {notification_query} nq ON nq.id = n.notification_query_id
     WHERE n.id = ?", [$notificationid]
);

if (!$notification) {
    echo $OUTPUT->notification(get_string('notification_not_found', 'local_notification'), 'error');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('h3', $notification->subject);
echo html_writer::tag('p', get_string('course') . ': ' . $notification->course_name);
echo html_writer::tag('p', get_string('n_type', 'local_notification') . ': ' . $notification->type_name);

// Formulário de filtros
echo html_writer::start_tag('form', array('method' => 'get', 'class' => 'mb-3'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'id', 'value' => $notificationid));
echo html_writer::start_tag('div', array('class' => 'row'));

// Filtro data início
echo html_writer::start_tag('div', array('class' => 'col-md-3'));
echo html_writer::tag('label', get_string('date_from', 'local_notification'));
echo html_writer::empty_tag('input', array(
    'type' => 'date',
    'name' => 'datefrom',
    'value' => $datefrom_str,
    'class' => 'form-control'
));
echo html_writer::end_tag('div');

// Filtro data fim
echo html_writer::start_tag('div', array('class' => 'col-md-3'));
echo html_writer::tag('label', get_string('date_to', 'local_notification'));
echo html_writer::empty_tag('input', array(
    'type' => 'date',
    'name' => 'dateto',
    'value' => $dateto_str,
    'class' => 'form-control'
));
echo html_writer::end_tag('div');

// Filtro acesso
echo html_writer::start_tag('div', array('class' => 'col-md-3'));
echo html_writer::tag('label', get_string('access_filter', 'local_notification'));
echo html_writer::select(
    array('' => get_string('all'), 'yes' => get_string('accessed', 'local_notification'), 'no' => get_string('not_accessed', 'local_notification')),
    'accessed',
    $accessed,
    false,
    array('class' => 'form-control')
);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', array('class' => 'col-md-3 d-flex align-items-end'));
echo html_writer::tag('button', get_string('filter'), array('type' => 'submit', 'class' => 'btn btn-primary'));
echo html_writer::end_tag('div');

echo html_writer::end_tag('div');
echo html_writer::end_tag('form');

// Buscar histórico de envios com dados de acesso
$sql = "SELECT nh.user_id, nh.delivered, nh.timeaccess, u.firstname, u.lastname, u.email
        FROM {notification_history} nh
        JOIN {user} u ON u.id = nh.user_id
        WHERE nh.notification_id = ?";

$params = [$notificationid];

if ($datefrom > 0) {
    $sql .= " AND nh.delivered >= ?";
    $params[] = $datefrom;
}

if ($dateto > 0) {
    $sql .= " AND nh.delivered <= ?";
    $params[] = $dateto + 86399; // Final do dia
}

if ($accessed === 'yes') {
    $sql .= " AND nh.timeaccess IS NOT NULL";
} elseif ($accessed === 'no') {
    $sql .= " AND nh.timeaccess IS NULL";
}

// Contar total de registros
$count_sql = str_replace('SELECT nh.user_id, nh.delivered, nh.timeaccess, u.firstname, u.lastname, u.email', 'SELECT COUNT(*)', $sql);
$total_count = $DB->count_records_sql($count_sql, $params);

// Ordenação
if ($sort === 'daysdiff') {
    $sql .= " ORDER BY (nh.timeaccess - nh.delivered) " . (($dir === 'asc') ? 'ASC' : 'DESC');
} else {
    $order_field = ($sort === 'timeaccess') ? 'nh.timeaccess' : 'nh.delivered';
    $order_dir = ($dir === 'asc') ? 'ASC' : 'DESC';
    $sql .= " ORDER BY $order_field $order_dir";
}


// Buscar registros com paginação
$records = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

// Botões de exportação
if (!empty($records)) {
    $export_params = array(
        'id' => $notificationid,
        'datefrom' => $datefrom_str,
        'dateto' => $dateto_str,
        'accessed' => $accessed,
        'sort' => $sort,
        'dir' => $dir
    );
    
    echo html_writer::start_tag('div', array('class' => 'mb-3'));
    echo html_writer::link(
        new moodle_url('/local/notification/export.php', array_merge($export_params, array('format' => 'csv'))),
        get_string('export_csv', 'local_notification'),
        array('class' => 'btn btn-success me-2')
    );
    echo html_writer::link(
        new moodle_url('/local/notification/export.php', array_merge($export_params, array('format' => 'excel'))),
        get_string('export_excel', 'local_notification'),
        array('class' => 'btn btn-success')
    );
    echo html_writer::end_tag('div');
    
    $table = new html_table();
    // Cabeçalhos com links de ordenação
    $base_params = array(
        'id' => $notificationid,
        'datefrom' => $datefrom_str,
        'dateto' => $dateto_str,
        'accessed' => $accessed
    );
    
    $sent_sort_dir = ($sort === 'delivered' && $dir === 'desc') ? 'asc' : 'desc';
    $access_sort_dir = ($sort === 'timeaccess' && $dir === 'desc') ? 'asc' : 'desc';
    $days_sort_dir = ($sort === 'daysdiff' && $dir === 'desc') ? 'asc' : 'desc';
    
    $sent_link = html_writer::link(
        new moodle_url('/local/notification/report_details.php', array_merge($base_params, array('sort' => 'delivered', 'dir' => $sent_sort_dir))),
        get_string('sent_date', 'local_notification') . ' ' . ($sort === 'delivered' ? ($dir === 'desc' ? '↓' : '↑') : '')
    );
    
    $access_link = html_writer::link(
        new moodle_url('/local/notification/report_details.php', array_merge($base_params, array('sort' => 'timeaccess', 'dir' => $access_sort_dir))),
        get_string('first_access_after', 'local_notification') . ' ' . ($sort === 'timeaccess' ? ($dir === 'desc' ? '↓' : '↑') : '')
    );
    
    $days_link = html_writer::link(
        new moodle_url('/local/notification/report_details.php', array_merge($base_params, array('sort' => 'daysdiff', 'dir' => $days_sort_dir))),
        get_string('days_difference', 'local_notification') . ' ' . ($sort === 'daysdiff' ? ($dir === 'desc' ? '↓' : '↑') : '')
    );
    
    $table->head = array(
        get_string('fullname'),
        get_string('email'),
        $sent_link,
        $access_link,
        $days_link
    );
    
    foreach ($records as $record) {
        $student_name = fullname($record);
        $sent_date = userdate($record->delivered);
        $first_access = $record->timeaccess ? userdate($record->timeaccess) : '-';
        
        // Calcular diferença em dias
        $days_diff = '-';
        if ($record->timeaccess) {
            $diff_seconds = $record->timeaccess - $record->delivered;
            $days_diff = floor($diff_seconds / 86400);
        }
        
        // Link para perfil do usuário no curso
        $profile_link = html_writer::link(
            new moodle_url('/user/view.php', array('id' => $record->user_id, 'course' => $notification->course_id)),
            $student_name
        );
        
        $table->data[] = array(
            $profile_link,
            $record->email,
            $sent_date,
            $first_access,
            $days_diff
        );
    }
    
    echo html_writer::table($table);
    
    // Paginação
    $baseurl = new moodle_url('/local/notification/report_details.php', array(
        'id' => $notificationid,
        'datefrom' => $datefrom_str,
        'dateto' => $dateto_str,
        'accessed' => $accessed,
        'sort' => $sort,
        'dir' => $dir
    ));
    
    echo $OUTPUT->paging_bar($total_count, $page, $perpage, $baseurl);
} else {
    echo $OUTPUT->notification(get_string('no_students_found', 'local_notification'), 'info');
}

echo html_writer::link(
    new moodle_url('/local/notification/report.php'),
    get_string('back_to_report', 'local_notification'),
    array('class' => 'btn btn-secondary mt-3')
);

echo $OUTPUT->footer();