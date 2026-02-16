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
 * Provides subcourse task tests.
 *
 * @package     mod_subcourse
 * @copyright   David Mudrák <david@moodle.com>
 * @author      Renaat Debleu <info@eWallah.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_subcourse;
use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Provides subcourse task tests.
 *
 * @copyright David Mudrák <david@moodle.com>
 * @author    Renaat Debleu <info@eWallah.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\mod_subcourse\task\fetch_grades::class)]
#[CoversClass(\mod_subcourse\task\check_completed_refcourses::class)]
#[CoversClass(\mod_subcourse\observers::class)]
#[CoversClass(\mod_subcourse\event\subcourse_grades_fetched::class)]
#[CoversClass(\mod_subcourse\event\course_module_instance_list_viewed::class)]
final class task_test extends \advanced_testcase {
    /**
     * Tests initial setup.
     */
    protected function setUp(): void {
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
            'completionview' => 1.,
        ]);

        $generator->create_module('subcourse', [
            'course' => $metacourse->id,
            'refcourse' => 0,
            'completioncourse' => 1,
            'completion' => 1,
        ]);

        $generator->create_module('subcourse', [
            'course' => $metacourse->id,
            'refcourse' => 99999,
            'completioncourse' => 0,
        ]);

        $generator->create_module('subcourse', [
            'course' => $metacourse->id,
            'refcourse' => $refcourse->id,
            'completioncourse' => 1,
            'completion' => 0,
        ]);

        $ccompletion = new \completion_completion(['course' => $refcourse->id, 'userid' => $student1->id]);
        $ccompletion->mark_complete();
        $ccompletion = new \completion_completion(['course' => $metacourse->id, 'userid' => $student2->id]);
        $ccompletion->mark_complete();
        rebuild_course_cache($refcourse->id, true);
        rebuild_course_cache($metacourse->id, true);
    }

    /**
     * Test fetch grades task.
     */
    public function test_subcourse_fetch_grades(): void {
        $task = new \mod_subcourse\task\fetch_grades();
        $this->assertEquals('Fetch subcourse grades', $task->get_name());
        ob_start();
        $task->execute();
        $out = ob_get_clean();
        $this->assertStringContainsString(' ok', $out);

        $courses = get_courses();
        foreach ($courses as $course) {
            delete_course($course, false);
        }
        ob_start();
        $task->execute();
        $out = ob_get_clean();
        $this->assertEquals('', $out);
    }

    /**
     * Test fetch grades task.
     */
    public function test_subcourse_check_completed(): void {
        global $CFG;
        $task = new \mod_subcourse\task\check_completed_refcourses();
        $this->assertEquals('Check referenced courses completion', $task->get_name());
        ob_start();
        $task->execute();
        $out = ob_get_clean();
        $this->assertStringContainsString(' skipped', $out);

        $CFG->enablecompletion = COMPLETION_DISABLED;
        ob_start();
        $task->execute();
        $out = ob_get_clean();
        $this->assertStringContainsString('Completion tracking not enabled on this site', $out);
    }

    /**
     * Test events.
     */
    public function test_events(): void {
        global $CFG;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $event = \mod_subcourse\event\subcourse_grades_fetched::create([
            'objectid' => $course->id,
            'context' => $context,
            'other' => ['refcourse' => 1],
        ]);
        $event->trigger();
        $this->assertEquals('Grades fetched', $event->get_name());
        $this->assertStringContainsString('mod/subcourse/view.php', $event->get_url());
        $this->assertStringContainsString('fetched grades from the course with id', $event->get_description());

        $event = \mod_subcourse\event\course_module_instance_list_viewed::create([
            'context' => $context,
            'other' => ['refcourse' => 1],
        ]);
        $event->trigger();
    }
}
