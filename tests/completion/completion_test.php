<?php
// This file is part of Moodle - https://moodle.org/
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
 * Provides subcourse custom completion tests.
 *
 * @package     mod_subcourse
 * @copyright   David Mudrák <david@moodle.com>
 * @author      Renaat Debleu <info@eWallah.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_subcourse\completion;
use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Provides subcourse task tests.
 *
 * @copyright David Mudrák <david@moodle.com>
 * @author    Renaat Debleu <info@eWallah.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_subcourse\completion\custom_completion::class)]
#[CoversClass(\mod_subcourse\event\subcourse_grades_fetched::class)]
final class completion_test extends \advanced_testcase {
    /**
     * Setup to ensure that locallib is loaded.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        parent::setUpBeforeClass();
    }

    /**
     * Tests custom completion.
     */
    public function test_custom_completion(): void {
        global $CFG;
        parent::setUp();
        $CFG->enablecompletion = COMPLETION_ENABLED;

        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $metacourse = $generator->create_course(['enablecompletion' => COMPLETION_ENABLED]);
        $refcourse = $generator->create_course(['enablecompletion' => COMPLETION_ENABLED]);

        $student1 = $generator->create_user();
        $student2 = $generator->create_user();

        $generator->enrol_user($student1->id, $metacourse->id, 'student');
        $generator->enrol_user($student1->id, $refcourse->id, 'student');
        $generator->enrol_user($student2->id, $metacourse->id, 'student');
        $generator->enrol_user($student2->id, $refcourse->id, 'student');

        // Give some grades in the referenced course.
        $gi = new \grade_item($generator->create_grade_item(['courseid' => $refcourse->id]), false);
        $gi->update_final_grade($student1->id, 90, 'test');
        $gi->update_final_grade($student2->id, 60, 'test');
        $gi->force_regrading();

        grade_regrade_final_grades($refcourse->id);

        $generator->create_module('subcourse', [
            'course' => $metacourse->id,
            'refcourse' => $refcourse->id,
            'completioncourse' => 1,
            'completion' => 2,
            'completionview' => 1,
        ]);

        $generator->create_module('subcourse', [
            'course' => $metacourse->id,
            'refcourse' => 0,
            'completioncourse' => 1,
            'completion' => 1,
        ]);

        $mod = $generator->create_module('subcourse', [
            'course' => $metacourse->id,
            'refcourse' => $refcourse->id,
            'completioncourse' => 1,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ]);

        $ccompletion = new \completion_completion(['course' => $refcourse->id, 'userid' => $student1->id]);
        $ccompletion->mark_complete();
        $ccompletion = new \completion_completion(['course' => $metacourse->id, 'userid' => $student2->id]);
        $ccompletion->mark_complete();

        $cm = \cm_info::create(get_coursemodule_from_instance('subcourse', $mod->id));
        $completion = new \completion_info($metacourse); 
        $completion->update_state($cm, COMPLETION_COMPLETE, $student2->id);

        $task = new \core\task\completion_regular_task();
        ob_start();
        $task->execute();
        sleep(1);
        $task->execute();
        \phpunit_util::run_all_adhoc_tasks();
        \phpunit_util::run_all_adhoc_tasks();
        ob_end_clean();
        
        rebuild_course_cache($refcourse->id, true);
        rebuild_course_cache($metacourse->id, true);

        $class = new custom_completion($cm, $student2->id);
        $this->assertEquals(['completionview', 'completionusegrade', 'completioncourse'], $class->get_sort_order());
        $this->assertEquals(['completioncourse' => 'Require course completed'], $class->get_custom_rule_descriptions());
        $this->assertEquals(['completioncourse'], custom_completion::get_defined_custom_rules());
        // TODO: should be true;
        $this->assertEquals(0, $class->get_state('completioncourse'));

        $mod = $generator->create_module('subcourse', [
            'course' => $metacourse->id,
            'refcourse' => 99999,
            'completioncourse' => 0,
        ]);

        $cm = \cm_info::create(get_coursemodule_from_instance('subcourse', $mod->id));
        $class = new custom_completion($cm, $student2->id);
        $this->expectExceptionMessage("error/Custom completion rule 'completioncourse' is not used by this activity.");
        $this->assertEquals(0, $class->get_state('completioncourse'));
    }
}
