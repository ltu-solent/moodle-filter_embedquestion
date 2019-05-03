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
 * Helper functions for filter_embedquestion.
 *
 * @package   filter_embedquestion
 * @copyright 2018 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_embedquestion;
defined('MOODLE_INTERNAL') || die();
use filter_embedquestion\output\error_message;


/**
 * Helper functions for filter_embedquestion.
 *
 * @copyright 2018 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class utils {

    /**
     * Display a warning notification if the filter is not enabled in this context.
     * @param \context $context the context to check.
     */
    public static function warn_if_filter_disabled(\context $context) {
        global $OUTPUT;
        if (!filter_is_enabled('embedquestion')) {
            echo $OUTPUT->notification(get_string('warningfilteroffglobally', 'filter_embedquestion'));
        } else {
            $activefilters = filter_get_active_in_context($context);
            if (!isset($activefilters['embedquestion'])) {
                echo $OUTPUT->notification(get_string('warningfilteroffhere', 'filter_embedquestion'));
            }
        }
    }

    /**
     * Display an error inside the filter iframe. Does not return.
     *
     * @param string $string language string key for the message to display.
     */
    public static function filter_error($string) {
        global $PAGE;
        $renderer = $PAGE->get_renderer('filter_embedquestion');
        echo $renderer->header();
        echo $renderer->render(new error_message('invalidtoken'));
        echo $renderer->footer();
        die;

    }

    /**
     * Checks to verify that a given usage is one we should be using.
     *
     * @param \question_usage_by_activity $quba the usage to check.
     */
    public static function verify_usage(\question_usage_by_activity $quba) {
        global $USER;

        if ($quba->get_owning_context()->instanceid != $USER->id) {
            throw new \moodle_exception('notyourattempt', 'filter_embedquestion');
        }
        if ($quba->get_owning_component() != 'filter_embedquestion') {
            throw new \moodle_exception('notyourattempt', 'filter_embedquestion');
        }
    }

    /**
     * Given any context, find the associated course from which to embed questions.
     *
     * Anywhere inside a course, that is the id of that course. Outside of
     * a particular course, it is the front page course id.
     *
     * @param \context $context the current context.
     * @return int the course id to use the question bank of.
     */
    public static function get_relevant_courseid(\context $context) {
        $coursecontext = $context->get_course_context(false);
        if ($coursecontext) {
            return $coursecontext->instanceid;
        } else {
            return SITEID;
        }
    }

    /**
     * Find a category with a given idnumber in a given context.
     *
     * @param \context $context a context.
     * @param string $idnumber the idnumber to look for.
     * @return \stdClass|false row from the question_categories table, or false if none.
     */
    public static function get_category_by_idnumber(\context $context, $categoryid) {
        global $DB;
    //     return $DB->get_record_select('question_categories',
    //             'contextid = ? AND ' . $DB->sql_like('name', '?'),
    //             [$context->id, $DB->sql_like_escape($idnumber)]);
    // }
    $record = $DB->get_records_sql("
    SELECT qc.id FROM mdl_question_categories qc

    WHERE id='$categoryid';");

    return array_column($record, 'id');

}

    /**
     * Find a question with a given idnumber in a given context.
     *
     * @param int $categoryid id of the question category to look in.
     * @param string $idnumber the idnumber to look for.
     * @return \stdClass|false row from the question table, or false if none.
     */
    public static function get_question_by_idnumber($categoryid, $questionid) {
        global $DB;

        return $DB->get_record_select('question',
                'category = ? AND ' . $DB->sql_like('id', '?') . ' AND hidden = 0 AND parent = 0',
                [$categoryid, $DB->sql_like_escape($questionid)]);
    }

    /**
     * Get a list of the question categories in a particular context that
     * contain sharable questions (and which have an idnumber set).
     *
     * The list is returned in a form suitable for using in a select menu.
     *
     * If a userid is given, then only questions created by that user
     * are considered.
     *
     * @param \context $context a context.
     * @param int $userid (optional) if set, only count questions created by this user.
     * @return array category idnumber => Category name (question count).
     */
    public static function get_categories_with_sharable_question_choices(\context $context, $userid = null) {
        global $DB;

        $params = [];
        $params[] = '%[ID:%]%';

        $creatortest = '';
        if ($userid) {
            $creatortest = 'AND q.createdby = ?';
            $params[] = $userid;
        }
        $params[] = $context->id;
        $params[] = '%[ID:%]%';

        $categories = $DB->get_records_sql("
        SELECT qc.id AS id, qc.name, COUNT(q.id) AS count

        FROM mdl_question_categories qc

        JOIN mdl_question q ON q.category = qc.id

        GROUP BY qc.id");

        $choices = ['' => get_string('choosedots')];
        foreach ($categories as $category) {

            $choices[$category->id] = get_string('nameandcount', 'filter_embedquestion',
                    ['name' => format_string($category->name), 'count' => $category->count]);
        }



        return $choices;
    }


    /**
     * Get shareable questions from a category (those which have an idnumber set).
     *
     * The list is returned in a form suitable for using in a select menu.
     *
     * If a userid is given, then only questions created by that user
     * are considered.
     *
     * @param int $categoryid id of a question category.
     * @param int $userid (optional) if set, only count questions created by this user.
     * @return array question idnumber => question name.
     */
    public static function get_sharable_question_choices($categoryid, $userid = null) {
        global $DB;

        // $params = [];
        // $params[] = $categoryid;
        // $params[] = '%[ID:%]%';
        //
        // $creatortest = '';
        // if ($userid) {
        //     $creatortest = 'AND q.createdby = ?';
        //     $params[] = $userid;
        // }

        $questions = $DB->get_records_sql("
        SELECT q.id, q.name
        FROM mdl_question q
        JOIN mdl_question_categories qc ON q.category = qc.id
        WHERE qc.id = $categoryid
        ORDER BY q.name");

        $choices = ['' => get_string('choosedots')];
        foreach ($questions as $question) {
            // if (!preg_match('~\[ID:(.*)\]~', $question->name, $matches)) {
            //     continue;
            // }

            $choices[$question->id] = format_string($question->name);
        }

        return $choices;
    }

    /**
     * Get the behaviours that can be used with this filter.
     *
     * @return array behaviour name => lang string for this behaviour name.
     */
    public static function behaviour_choices() {
        $behaviours = [];
        foreach (\question_engine::get_archetypal_behaviours() as $behaviour => $name) {
            $unusedoptions = \question_engine::get_behaviour_unused_display_options($behaviour);
            // Apologies for the double-negative here.
            // A behaviour is suitable if specific feedback is relevant during the attempt.
            if (!in_array('specificfeedback', $unusedoptions)) {
                $behaviours[$behaviour] = $name;
            }
        }
        return $behaviours;
    }
}
