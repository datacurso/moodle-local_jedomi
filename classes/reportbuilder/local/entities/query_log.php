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

namespace local_jedomi\reportbuilder\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;
use local_jedomi\agent\query_profiler;
use stdClass;

/**
 * Profiled query entity for the reports of the Query profiler tab.
 *
 * Backed by {local_jedomi_query_log}: one row for each SQL query run by an agent, an agent preview, the web chat,
 * Telegram or the companion web service.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class query_log extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'local_jedomi_query_log',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity_query_log', 'local_jedomi');
    }

    /**
     * Initialise the entity, adding all columns and filters.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }

        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter)->add_condition($filter);
        }

        return $this;
    }

    /**
     * Readable origin of a query and the link to its agent, if any.
     *
     * @param int $agentid Agent of the query; 0 for a chat channel or the preview of a new agent.
     * @param string $channel Channel of the query, one of query_profiler::CHANNELS.
     * @return array Label and URL (empty when there is no agent to open).
     */
    public static function origin(int $agentid, string $channel): array {
        $url = $agentid > 0
            ? (new \moodle_url('/local/jedomi/admin_settings.php', ['tab' => 'agents', 'edit_agent' => $agentid]))->out(false)
            : '';
        switch ($channel) {
            case 'preview':
                return $agentid > 0
                    ? [get_string('query_profiler_origin_preview_agent', 'local_jedomi', $agentid), $url]
                    : [get_string('query_profiler_origin_preview', 'local_jedomi'), ''];
            case 'web':
            case 'telegram':
            case 'companion':
                return [get_string('query_profiler_origin_' . $channel, 'local_jedomi'), ''];
            default:
                return [get_string('agent_number', 'local_jedomi', $agentid), $url];
        }
    }

    /**
     * A duration as shown in the reports, in red when asked.
     *
     * @param float $durationms Duration in milliseconds.
     * @param bool $highlight Whether to show it in red.
     * @return string HTML.
     */
    public static function format_duration(float $durationms, bool $highlight): string {
        $text = get_string('query_profiler_ms', 'local_jedomi', format_float($durationms, 2));
        return \html_writer::span($text, 'text-nowrap' . ($highlight ? ' text-danger' : ''));
    }

    /**
     * Returns the list of all available columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $tablealias = $this->get_table_alias('local_jedomi_query_log');

        $columns = [];

        // Origin column: the channel, with a link to the agent of the query.
        $columns[] = (new column(
            'origin',
            new lang_string('query_profiler_origin', 'local_jedomi'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$tablealias}.channel, {$tablealias}.agentid")
            ->set_is_sortable(true)
            ->add_callback(static function ($value, stdClass $row): string {
                [$label, $url] = self::origin((int) $row->agentid, (string) $value);
                return $url === ''
                    ? \html_writer::span(s($label), 'badge badge-secondary bg-secondary')
                    : \html_writer::link($url, s($label), ['class' => 'badge badge-info bg-info']);
            });

        // Query column.
        $columns[] = (new column(
            'query',
            new lang_string('query_profiler_sort_query', 'local_jedomi'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$tablealias}.query")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                return \html_writer::tag('code', s(shorten_text((string) $value, 120)), ['class' => 'local_jedomi-admin-break']);
            });

        // Duration column: in red at or above the slow query threshold, the same queries the slow indicator counts.
        $columns[] = (new column(
            'duration',
            new lang_string('query_profiler_sort_duration', 'local_jedomi'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_FLOAT)
            ->add_field("{$tablealias}.duration_ms")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                $duration = (float) $value;
                return self::format_duration($duration, $duration >= query_profiler::slow_threshold_ms());
            });

        // Rows column.
        $columns[] = (new column(
            'rowcount',
            new lang_string('query_profiler_rows', 'local_jedomi'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$tablealias}.rowcount")
            ->set_is_sortable(true);

        // Date column.
        $columns[] = (new column(
            'timecreated',
            new lang_string('query_profiler_sort_time', 'local_jedomi'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$tablealias}.timecreated")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                return userdate((int) $value, get_string('strftimedatetime', 'core_langconfig'));
            });

        return $columns;
    }

    /**
     * Returns the list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $tablealias = $this->get_table_alias('local_jedomi_query_log');

        $filters = [];

        // Channel filter.
        $filters[] = (new filter(
            select::class,
            'channel',
            new lang_string('query_profiler_channel', 'local_jedomi'),
            $this->get_entity_name(),
            "{$tablealias}.channel"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                $options = [];
                foreach (query_profiler::CHANNELS as $channel) {
                    $options[$channel] = get_string('query_profiler_origin_' . $channel, 'local_jedomi');
                }
                return $options;
            });

        // Duration filter, in milliseconds: "at least" is the minimum duration.
        $filters[] = (new filter(
            number::class,
            'duration',
            new lang_string('query_profiler_min_ms', 'local_jedomi'),
            $this->get_entity_name(),
            "{$tablealias}.duration_ms"
        ))
            ->add_joins($this->get_joins())
            ->set_limited_operators([
                number::ANY_VALUE,
                number::EQUAL_OR_GREATER_THAN,
                number::EQUAL_OR_LESS_THAN,
                number::RANGE,
            ]);

        // Query filter.
        $filters[] = (new filter(
            text::class,
            'query',
            new lang_string('query_profiler_sort_query', 'local_jedomi'),
            $this->get_entity_name(),
            "{$tablealias}.query"
        ))
            ->add_joins($this->get_joins());

        // Date filter.
        $filters[] = (new filter(
            date::class,
            'timecreated',
            new lang_string('query_profiler_sort_time', 'local_jedomi'),
            $this->get_entity_name(),
            "{$tablealias}.timecreated"
        ))
            ->add_joins($this->get_joins())
            ->set_limited_operators([
                date::DATE_ANY,
                date::DATE_RANGE,
                date::DATE_PREVIOUS,
                date::DATE_CURRENT,
            ]);

        return $filters;
    }
}
