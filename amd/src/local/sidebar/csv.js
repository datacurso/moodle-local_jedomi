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
 * CSV download of the tables of the Jedomi answers.
 *
 * @module     local_jedomi/local/sidebar/csv
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {cfg} from './state';

// Text of a table cell as shown in the data: links export only their text, without icon or type label.
const csvCellText = (cell) => {
    const copy = cell.cloneNode(true);
    copy.querySelectorAll('a').forEach(link => {
        const label = link.querySelector('[data-link-text]');
        link.replaceWith(document.createTextNode(label ? label.textContent : link.textContent));
    });
    return copy.textContent.trim();
};

// A quoted CSV cell; a value starting with a formula character is exported as text.
const csvCell = (value) => {
    const safe = /^[=+\-@\t\r]/.test(value) ? `'${value}` : value;
    return '"' + safe.replace(/"/g, '""') + '"';
};

// Download the table of the CSV button btn as a CSV file.
export const downloadTableCsv = (btn) => {
    const tableWrap = btn.closest('.jedomi-table-wrap');
    const tbl = tableWrap && tableWrap.querySelector('table');
    if (!tbl) {
        return;
    }
    const rows = [...tbl.querySelectorAll('tr')].map(tr =>
        [...tr.querySelectorAll('th,td')].map(c => csvCell(csvCellText(c))).join(',')
    );
    const blob = new Blob(['﻿' + rows.join('\n')], {type: 'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${cfg.strings?.export_csv_filename || 'jedomi_data'}.csv`;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => {
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }, 500);
};
