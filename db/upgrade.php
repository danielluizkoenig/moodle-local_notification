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
 * Local notification upgrade.
 *
 * @package    local_notification
 * @copyright  2017 Frédéric Massart
 * @author     Frédéric Massart <fred@branchup.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_notification\local_notification;

defined('MOODLE_INTERNAL') || die();

/**
 * Local notification upgrade function.
 *
 * @param int $oldversion Old version.
 * @return true
 */
function xmldb_local_notification_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();
    
    if ($oldversion < 2025102909) {
        $table = new xmldb_table('notification');
        $field = new xmldb_field('days_after', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        upgrade_plugin_savepoint(true, 2025102909, 'local', 'notification');
    }
    
    return true;
}
