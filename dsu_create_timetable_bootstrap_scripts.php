<?php
//*****************************************************************************
// run a script in the background
//******************************************************************************/
//#Defining session specific variables below:
$academic_session_id = "24"; //ID for the Spring 2019 Academic Session
$department_XML_as_file = "/var/www/dsu_coreapp/semester_wise_timetable_scripts/1_departments.xml";
$subject_area_XML_as_file = "/var/www/dsu_coreapp/semester_wise_timetable_scripts/2_subject_areas.xml";
$save_academic_area_XML_as_file = "/var/www/dsu_coreapp/semester_wise_timetable_scripts/3_academic_areas.xml";
$academic_classification_XML_as_file = "/var/www/dsu_coreapp/semester_wise_timetable_scripts/4_academic_classifications.xml";
$major_XML_as_file = "/var/www/dsu_coreapp/semester_wise_timetable_scripts/5_majors.xml";
$room_XML_as_file = "/var/www/dsu_coreapp/semester_wise_timetable_scripts/6_rooms.xml";
$save_offering_XML_as_file = "/var/www/dsu_coreapp/semester_wise_timetable_scripts/7_offering.xml";

$row = 1;
$x = 25;
$y = 67;
//#end of block
ini_set('include_path', '.:/var/www/includes:/var/www/dsu_coreapp/lib/zend/library:/usr/lib/php5/20090626/:/var/www/dsu_coreapp');
ini_set('server_name', 'localhost');
ini_set('doc_root', '/var/www');
ini_set('language', 'en-gb');
ini_set('server_admin', 'root@localhost');
ini_set('max_execution_time', 30000);
set_time_limit(30000);

$stdout = '../logs/timetable_scripts_creation_log_file.html';
require 'std.batch.inc';
batchInit(__FILE__);
require_once('classes/courses.class.inc');
require_once('classes/templatecourses.class.inc');
require_once('classes/sections.class.inc');
require_once('classes/rooms.class.inc');
require_once('classes/course_section_allocation.class.inc');
require_once('classes/section_room_allocation.class.inc');
require_once('classes/course_room_allocation.class.inc');
require_once('classes/teachers.class.inc');
require_once('classes/course_additional_teacher_allocation.class.inc');
$courses = array();
// Cleanup any existing files
unlink($save_offering_XML_as_file);
unlink($save_academic_area_XML_as_file);
unlink($academic_classification_XML_as_file);
unlink($major_XML_as_file);
unlink($department_XML_as_file);
unlink($subject_area_XML_as_file);
unlink($room_XML_as_file);
//End of block
// Helper Functions
class SimpleXMLExtended extends SimpleXMLElement
{
    public function addCData($cdata_text, $nodeName)
    {
        $element = dom_import_simplexml($this);

        $newElement = $element->ownerDocument->createElement($nodeName);
        $parentElement = $element->parentNode;
        $parentElement->insertBefore($newElement, $element);
        $parentElement->removeChild($element);
        $newElement->appendChild($newElement->ownerDocument->createCDATASection($cdata_text));
    }
}

function compareOnSectionTitle($a, $b)
{
    return strcmp(trim($a[3]), trim($b[3]));
}

//End of Block

//Creating file handles
$offeringXML = "<offerings campus='blank' year='2010' term='Fall' ></offerings>";
$offeringXMLDocHandle = new SimpleXMLElement($offeringXML);

$academicAreaXML = "<academicAreas campus='blank' year='2010' term='Fall' ></academicAreas>";
$academicAreaXMLDocHandle = new SimpleXMLElement($academicAreaXML);
$academicClassificationXML = "<academicClassifications campus='blank' year='2010' term='Fall' ></academicClassifications>";
$academicClassificationXMLDocHandle = new SimpleXMLElement($academicClassificationXML);

$majorXML = "<posMajors campus='blank' year='2010' term='Fall' ></posMajors>";
$majorXMLDocHandle = new SimpleXMLElement($majorXML);
$departmentXML = "<departments campus='blank' year='2010' term='Fall' ></departments>";
$departmentXMLDocHandle = new SimpleXMLElement($departmentXML);

$subjectAreaXML = "<subjectAreas campus='blank' year='2010' term='Fall' ></subjectAreas>";
$subjectAreaXMLDocHandle = new SimpleXMLElement($subjectAreaXML);
$roomXML = "<buildingsRooms campus='blank' year='2010' term='Fall' ></buildingsRooms>";
$roomXMLDocHandle = new SimpleXMLElement($roomXML);
//end of block
//Creating database handles
$course_dbobject = &RDCsingleton::getInstance('courses');
$section_dbobject = &RDCsingleton::getInstance('sections');
$room_dbobject = &RDCsingleton::getInstance('rooms');
$course_section_allocation_dbobject = &RDCsingleton::getInstance('course_section_allocation');
$section_room_allocation_dbobject = &RDCsingleton::getInstance('section_room_allocation');
$course_room_allocation_dbobject = &RDCsingleton::getInstance('course_room_allocation');
$template_courses_dbobject = &RDCsingleton::getInstance('templatecourses');
$teacher_dbobject = &RDCsingleton::getInstance('teachers');
$course_additional_teacher_allocation_dbobject = &RDCsingleton::getInstance('course_additional_teacher_allocation');
//End of block
$building = $roomXMLDocHandle->addChild('building');
$building->addAttribute('externalId', 'DSU');
$building->addAttribute('abbreviation', 'DSU');
$building->addAttribute('locationX', $x);
$building->addAttribute('locationY', $y);
$building->addAttribute('name', 'DHA Suffa University');

$offeringID = 1;
$firstOfferingIDOfGivenSection = "";
$courseID = 1;
$classID = 1;
$suffixID = 1;
$oldClassAndSection = "";
$oldCourseSubject = "";
$currentSubjectArea = "";
$isNewDepartment = false;
$offering = NULL;
$course = NULL;

$programmingLabCourses = array(); //This is currently empty. I have to populate it with data from DA.
$labsArray = array();
$roomArray = array();
$sectionToRoomArray = array();
$master_data_set = array();
$courses = $course_dbobject->getData("academicsessionid='{$academic_session_id}' and teacherid is not null ");

foreach ($courses as $course) {
    //For the course to be valid it must be assigned to a section. We first check to see if this is true:
    $course_section_allocation_dbobject = NULL;
    $course_section_allocation_dbobject = &RDCsingleton::getInstance('course_section_allocation');
    $sections = $course_section_allocation_dbobject->getData("courseid='{$course['course_id']}'");

    foreach ($sections as $section) {
        $data_record = array();
        $courseTitle = "";
        $courseCode = "";
        $creditHours = "";
        //We are assuming in the first iteration that there is one section per course:
        $course_section = "";
        $list_of_instructors = array();
        $course_instructor_userid = "";
        $instructorFirstName = "";
        $instructorLastName = "";
        $template_courses_dbobject = NULL;
        $template_courses_dbobject = &RDCsingleton::getInstance('templatecourses');
        $template_course = $template_courses_dbobject->getData("templatecourse_id='{$course['templatecourseid']}'");
        $courseTitle = $course['course_title'];
        //$courseTitle = $template_course[0]['templatecourse_short_title'];
        $courseCode = $template_course[0]['templatecourse_code'];
        $creditHours = $template_course[0]['templatecourse_credithours'];
        $course_section = $section['section_title'];

        $course_additional_teacher_allocation_dbobject = NULL;
        $course_additional_teacher_allocation_dbobject = &RDCsingleton::getInstance('course_additional_teacher_allocation');
        $teachers = $course_additional_teacher_allocation_dbobject->getData("courseid='{$course['course_id']}'");

        foreach ($teachers as $teacher_instance) {
            $teacher_dbobject = NULL;
            $teacher_dbobject = &RDCsingleton::getInstance('teachers');
            $teacher = $teacher_dbobject->getData("teacher_id='{$teacher_instance['teacherid']}'");

            $course_instructor_userid = $teacher[0]['teacher_id'];
            $instructorFirstName = $teacher[0]['teacher_firstname'];
            if ($teacher[0]['teacher_middlename'] != "") {
                $instructorFirstName .= " " . $teacher[0]['teacher_middlename'];
            }
            $instructorLastName = $teacher[0]['teacher_lastname'];

            $instructor = array();
            $instructor[0] = $course_instructor_userid;
            $instructor[1] = $instructorFirstName;
            $instructor[2] = $instructorLastName;
            array_push($list_of_instructors, $instructor);
        }

        $data_record[0] = $courseTitle;
        $data_record[1] = $courseCode;
        $data_record[2] = $creditHours;
        $data_record[3] = $course_section;
        $data_record[4] = $list_of_instructors;
        //$data_record[4] = $course_instructor_userid;
        //$data_record[5] = $instructorFirstName;
        //$data_record[6] = $instructorLastName;
        $is_lab = $template_course[0]['templatecourse_islab'];
        $data_record[7] = $is_lab;

        //OK
        $sec_temp_dbobject = NULL;
        $sec_temp_dbobject = RDCsingleton::getInstance('sections');
        $sec_temp_detail_record = $sec_temp_dbobject->getData("section_title='{$course_section}'");
        $data_record[8] = $sec_temp_detail_record[0]['deptid'];

        //A section has to be defined for the course in order for the course to be processed.
        if ($course_section != "" && strcmp($data_record[8], "4") == 0) {
            array_push($master_data_set, $data_record);
        }

        foreach ($sections as $section) {
            $section_dbobject = NULL;
            $section_dbobject = &RDCsingleton::getInstance('sections');
            $section_detail_record = $section_dbobject->getData("section_id='{$section['sectionid']}'");
            if (!isset($sectionToRoomArray[$section_detail_record[0]['section_title']])) {
                $sectionToRoomArray[$section_detail_record[0]['section_title']] = array();
            }
            $section_room_allocation_dbobject = NULL;
            $section_room_allocation_dbobject = &RDCsingleton::getInstance('section_room_allocation');
            $section_to_room_records = $section_room_allocation_dbobject->getData("sectionid='{$section['sectionid']}'");
            foreach ($section_to_room_records as $section_to_room_record) {
                $room_dbobject = NULL;
                $room_dbobject = &RDCsingleton::getInstance('rooms');
                $room_detail_record = $room_dbobject->getData("id='{$section_to_room_record['roomid']}'");
                //array_push($sectionToRoomArray[$section_detail_record[0]['section_title']], $room_detail_record[0]['room_number']);
                if (!in_array($room_detail_record[0]['room_number'], $sectionToRoomArray[$section_detail_record[0]['section_title']], true)) {
                    array_push($sectionToRoomArray[$section_detail_record[0]['section_title']], $room_detail_record[0]['room_number']);
                }
            }

            //Start of block
            //1: We check if the current course record is a lab course record.
            //2: Then we check for the lab rooms assigned to this lab course.
            //3: Finally, we assign the lab room in (2) to the section in (1)
            if ($is_lab == TRUE) {
                $course_room_allocation_dbobject = NULL;
                $course_room_allocation_dbobject = &RDCsingleton::getInstance('course_room_allocation');
                $rooms_assigned_to_course = $course_room_allocation_dbobject->getData("templatecourseid='{$course['templatecourseid']}'");
                foreach ($rooms_assigned_to_course as $lab_room) {
                    $room_dbobject = NULL;
                    $room_dbobject = &RDCsingleton::getInstance('rooms');
                    $room_detail_record = $room_dbobject->getData("id='{$lab_room['roomid']}'");
                    //array_push($sectionToRoomArray[$section_detail_record[0]['section_title']], $room_detail_record[0]['room_number']);
                    if (!in_array($room_detail_record[0]['room_number'], $sectionToRoomArray[$section_detail_record[0]['section_title']], true)) {
                        array_push($sectionToRoomArray[$section_detail_record[0]['section_title']], $room_detail_record[0]['room_number']);
                    }
                }
            }
            //End of block
        }
    }
}

$room_dbobject = NULL;
$room_dbobject = &RDCsingleton::getInstance('rooms');
$labs = $room_dbobject->getData("is_lab=1");
foreach ($labs as $lab) {
    array_push($labsArray, $lab['room_number']);
}

usort($master_data_set, "compareOnSectionTitle");

foreach ($master_data_set as $data_record) {
    $courseTitle = $data_record[0];
    $courseCode = $data_record[1];
    $creditHours = $data_record[2];
    $course_section = $data_record[3];
    $list_of_instructors = $data_record[4];
    //$course_instructor_userid = $data_record[4];
    //$instructorFirstName = $data_record[5];
    //$instructorLastName = $data_record[6];
    $isLab = $data_record[7];
    $dptID = $data_record[8];

    $pos = strpos($courseCode, "-");
    if ($pos === false) {
        $courseSubject = "BA(H)";
        $courseNumber = substr($courseCode, 5);
    } else {
        $courseCodeArray = explode("-", $courseCode);
        $courseSubject = $courseCodeArray[0];
        $courseNumber = $courseCodeArray[1];
    }

    if ($course_section != $oldClassAndSection) {
        $firstOfferingIDOfGivenSection = $offeringID;
        if ($totalCreditHoursForGivenClassAndSection != 0) {
            $sectionToRoomArray[$oldClassAndSection][1] = $totalCreditHoursForGivenClassAndSection;
            $totalCreditHoursForGivenClassAndSection = 0;
        }
        $oldClassAndSection = $course_section;
        $isNewDepartment = true;
        $academicArea = $academicAreaXMLDocHandle->addChild('academicArea');
        $academicArea->addAttribute('externalId', $course_section);
        $academicArea->addAttribute('abbreviation', $course_section);
        $academicArea->addAttribute('shortTitle', $course_section);
        $academicArea->addAttribute('longTitle', $course_section);
        $academicClassification = $academicClassificationXMLDocHandle->addChild('academicClassification');
        $academicClassification->addAttribute('externalId', $course_section);
        $academicClassification->addAttribute('code', $course_section);
        $academicClassification->addAttribute('name', $course_section);

        $major = $majorXMLDocHandle->addChild('posMajor');
        $major->addAttribute('externalId', $course_section);
        $major->addAttribute('code', $course_section);
        $major->addAttribute('name', $course_section);
        $major->addAttribute('academicArea', $course_section);

        $department = $departmentXMLDocHandle->addChild('department');
        $department->addAttribute('externalId', $course_section);
        $department->addAttribute('abbreviation', $course_section);
        $department->addAttribute('name', $course_section);
        $department->addAttribute('deptCode', $course_section);
    } else {
        $isNewDepartment = false;
    }
    $totalCreditHoursForGivenClassAndSection += $creditHours;
    if (($courseSubject != $oldCourseSubject && $isNewDepartment == false) || $isNewDepartment == true) {
        $oldCourseSubject = $courseSubject;
        $subjectArea = $subjectAreaXMLDocHandle->addChild('subjectArea');
        $subjectArea->addAttribute('externalId', $course_section . ':' . $firstOfferingIDOfGivenSection . ':' . $courseSubject);
        $subjectArea->addAttribute('abbreviation', $firstOfferingIDOfGivenSection . ':' . $courseSubject);
        $subjectArea->addAttribute('shortTitle', $course_section);
        $subjectArea->addAttribute('longTitle', $course_section);
        $subjectArea->addAttribute('department', $course_section);
        $currentSubjectArea = $firstOfferingIDOfGivenSection . ':' . $courseSubject;
    }
    $offering = $offeringXMLDocHandle->addChild('offering');
    $offering->addAttribute('id', $offeringID);
    $offeringID = $offeringID + 1;
    $offering->addAttribute('offered', 'true');
    $offering->addAttribute('action', 'insert');
    $course = $offering->addChild('course');
    $course->addAttribute('id', $courseID);
    $course->addAttribute('subject', $currentSubjectArea);
    $course->addAttribute('courseNbr', $courseNumber);
    $course->addAttribute('controlling', 'true');
    $course->addAttribute('title', $courseTitle);

    $courseCredit = $course->addChild('courseCredit');
    $courseCredit->addAttribute('creditType', 'equivalent');
    $courseCredit->addAttribute('creditUnitType', 'semesterHours');
    $courseCredit->addAttribute('creditFormat', 'fixedUnit');
    $courseCredit->addAttribute('fixedCredit', $creditHours);

    $courseID = $courseID + 1;
    //each row will correspond to a new configuration
    $configuration = $offering->addChild('config');
    $configuration->addAttribute('name', $course_section);
    if ($isLab == false) {
        $configuration->addAttribute('limit', $creditHours * 10);
    } else {
        $configuration->addAttribute('limit', $creditHours * 2);
    }
    $subpart = $configuration->addChild('subpart');
    if ($isLab == false) {
        $subpart->addAttribute('type', 'Lec');
        $subpart->addAttribute('suffix', '');
        $subpart->addAttribute('minPerWeek', '60');
    } else {
        $subpart->addAttribute('type', 'Lab');
        $subpart->addAttribute('suffix', '');
        /*if(in_array($courseCode, $programmingLabCourses))
                 {
        $subpart->addAttribute('minPerWeek', '120');
                    }
        else
                    {
        $subpart->addAttribute('minPerWeek', '180');
                    }*/
        // OK
        //fputs(STDOUT, "I came Here\n". $dptID. "\n" . $section['sectionid']  . "\n"); exit();
        $subpart->addAttribute('minPerWeek', '180');
    }
    $suffixID = 1;
    for ($classCount = 0; $classCount < $creditHours; $classCount++) {
        $class = $configuration->addChild('class');
        $class->addAttribute('id', $classID);
        $classID = $classID + 1;
        if ($isLab == false) {
            $class->addAttribute('type', 'Lec');
            $class->addAttribute('limit', '10');
        } else {
            $class->addAttribute('type', 'Lab');
            $class->addAttribute('limit', '2');
        }
        $class->addAttribute('suffix', $suffixID);
        $suffixID = $suffixID + 1;

        foreach ($list_of_instructors as $instructor_instance) {
            $instructor = $class->addChild('instructor');
            $instructor->addAttribute('id', $instructor_instance[0]);
            $instructor->addAttribute('share', '100');
            $instructor->addAttribute('lead', 'true');
            $instructor->addAttribute('fname', $instructor_instance[1]);
            $instructor->addAttribute('lname', $instructor_instance[2]);
        }
    }
    foreach ($sectionToRoomArray[$course_section] as $roomInstance) {
        if (!isset($roomArray[$roomInstance][0])) {
            $roomArray[$roomInstance][0] = $creditHours;
        } else {
            $roomArray[$roomInstance][0] += $creditHours;
        }

        if (!isset($roomArray[$roomInstance][1])) {
            $roomArray[$roomInstance][1] = array();
            array_push($roomArray[$roomInstance][1], $course_section);
        } else {
            if (!in_array($course_section, $roomArray[$roomInstance][1])) {
                array_push($roomArray[$roomInstance][1], $course_section);
            }
        }
    }
}
//Creating the Room XML
foreach ($roomArray as $key => $value) {
    $room = $building->addChild('room');
    $room->addAttribute('externalId', $key);
    $room->addAttribute('roomNumber', $key);
    $room->addAttribute('locationX', $x);
    $y += 0.0001;
    $room->addAttribute('locationY', $y);
    $room->addAttribute('instructional', 'True');
    $room->addAttribute('roomClassification', 'classroom');
    if (in_array($key, $labsArray)) {
        $room->addAttribute('scheduledRoomType', 'computingLab');
        $room->addAttribute('capacity', '2');
    } else {
        $room->addAttribute('scheduledRoomType', 'genClassroom');
        $room->addAttribute('capacity', '10');
    }
    $roomDepartments = $room->addChild('roomDepartments');
    foreach ($roomArray[$key][1] as $sectionInstance) {
        $assignedDepartment = $roomDepartments->addChild('assigned');
        $assignedDepartment->addAttribute('departmentNumber', $sectionInstance);
        $assignedDepartment->addAttribute('percent', round(($sectionToRoomArray[$sectionInstance][1] / $roomArray[$key][0]) * 100));

        $schedulingDepartment = $roomDepartments->addChild('scheduling');
        $schedulingDepartment->addAttribute('departmentNumber', $sectionInstance);
        $schedulingDepartment->addAttribute('percent', round(($sectionToRoomArray[$sectionInstance][1] / $roomArray[$key][0]) * 100));
    }
}

//Closing File Handles
$offeringXMLDocHandle->saveXML($save_offering_XML_as_file);
$academicAreaXMLDocHandle->saveXML($save_academic_area_XML_as_file);
$academicClassificationXMLDocHandle->saveXML($academic_classification_XML_as_file);
$majorXMLDocHandle->saveXML($major_XML_as_file);
$departmentXMLDocHandle->saveXML($department_XML_as_file);
$subjectAreaXMLDocHandle->saveXML($subject_area_XML_as_file);
$roomXMLDocHandle->saveXML($room_XML_as_file);
//End of Block

batchEnd();
