<?php

header('Access-Control-Allow-Origin: *');

global $original_post_data;
$original_post_data = $_POST;

require_once("../../../wp-load.php");

function initializeManager() {
    $manager = \Bright\MigrationManager::get_instance(
	array(
	    'migration_table_name' => 'equaliteach_results_schemaversion',
	    'log_func' => function($obj,$description=null) {
		$bright = \Bright\brightClass()::getInstance();
		$bright->log($obj,$description);
	    },
	    'execute_sql_func' => function($sql) {
		$bright = \Bright\brightClass()::getInstance();
		return $bright->execute_sql($sql);
	    }
    ));
    return $manager;
}

$manager = initializeManager();

$manager->add_patch('20231011150001',<<<EOF
 create table equaliteach_submissions (
  `id` INT NOT NULL AUTO_INCREMENT,
  `submission` TEXT not null,
  `learner_id` varchar(255) NOT NULL,
  `title` varchar(255)       not null,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ID)
);
EOF
);

$manager->add_patch('20231011150002',<<<EOF
create table equaliteach_quiz_results(
  `id` INT NOT NULL AUTO_INCREMENT,
  `equaliteach_submission_id` INT NOT NULL,
  `question_number` integer not null,
  `question_text` varchar(255),
  `user_answer`  varchar(255),
  `correct_answer`  varchar(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ID),
  FOREIGN KEY (equaliteach_submission_id) REFERENCES equaliteach_submissions(id)
);
EOF
);

$manager->add_patch('20231011150003',<<<EOF
  alter table equaliteach_quiz_results add correct boolean;
EOF
);


$manager->add_patch('20231011150004',<<<EOF
  alter table equaliteach_quiz_results add question_type varchar(64) not null;
EOF
);

$manager->add_patch('20231011150005',<<<EOF
  alter table equaliteach_quiz_results add error text;
EOF
);



$manager->migrate_database();

function writeToDatabase($quizResults) {
    global $wpdb;
    global $original_post_data;
    $bright = \Bright\brightClass()::getInstance();

    $result = Array();
    $result['questions'] = array();

    $questions = $quizResults->detailResult->questions;
    $question_no = 0;
    
    foreach ($questions as $question) {
      $class = get_class($question);


      if ($class == "WordBankQuestion") {
        foreach ($question->details->items as $item) {
          $this_q = array();

          $this_q['question_type'] = $class;
          $this_q['question_text'] = $question->direction;
          $this_q['correct'] = $item->correct;
          $this_q['user_answer'] = $item->userAnswer;
          $this_q['correct_answer'] = $item->getValue();

          $result['questions'][$question_no] = $this_q;
          $question_no++;
        }
      } else if ($class == "MultipleChoiceQuestion") {
        /* if ($question->isGraded()) { */
        /* error_log("no handler for graded multiple choice questions"); */
        /* } else { */
        $this_q = array();
        $this_q['question_type'] = $class;    
        $this_q['correct'] = false;
        $this_q['question_text'] = $question->direction;
        $this_q['user_answer'] = $question->userAnswer;
        $this_q['correct_answer'] = $item->correctAnswer;

        $result['questions'][$question_no] = $this_q;
        $question_no++;

        /* } */
      } else if ($class == "TypeInQuestion"){
        $this_q = array();
        $this_q['question_type'] = $class;    
		$this_q['correct'] = false;
		$this_q['question_text'] = $question->direction;
		$this_q['user_answer'] = $question->userAnswer;
		$this_q['correct_answer'] = $item->correctAnswer;

		$result['questions'][$question_no] = $this_q;
		$question_no++;
      } else {
        $this_q = array();
        $this_q['question_type'] = $class;    
        try {
          $this_q['correct'] = false;
          $this_q['question_text'] = $question->direction;
          $this_q['user_answer'] = $question->userAnswer;
          $this_q['correct_answer'] = $item->correctAnswer;
        } 
        catch (Exception $e) {
          error_log($e);

          $this_q['error'] = $e->getMessage();

          echo "Error: " . $e->getMessage();
        }
        $result['questions'][$question_no] = $this_q;
        $question_no++;
      } 
    }
    
    $wpdb->insert('equaliteach_submissions', array(
	'submission' => serialize($original_post_data),
	'learner_id' => $original_post_data['sid'],
	'title' => $quizResults->quizTitle
    ));

    $submission_id = $wpdb->insert_id;

    $question_no=0;

    foreach($result['questions'] as $question) {
      $wpdb->insert('equaliteach_quiz_results', array(
	    'equaliteach_submission_id' => $submission_id,
	    'question_number' => $question_no++,
	    'question_text' => $question['question_text'],
        'question_type' => $question['question_type'],
	    'user_answer' => $question['user_answer'],
        'error' => $question['error'],
	    'correct_answer' => $question['correct_answer'],
	    'correct' => $question['correct']
      ));
    }
}


if (!isset($_GET['debug'])) {
  if ($_SERVER['REQUEST_METHOD'] != 'POST')
    {
      echo "POST request expected";
      return;
    }
}

error_reporting(E_ALL && E_WARNING && E_NOTICE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'includes/common.inc.php';

if (isset($_GET['debug'])) {
  $original_post_data = unserialize(file_get_contents(dirname(__FILE__) . '/' . $_GET['debug'] ));
}

$requestParameters = RequestParametersParser::getRequestParameters($original_post_data, !empty($HTTP_RAW_POST_DATA) ? $HTTP_RAW_POST_DATA : null);
_log($requestParameters);

try
{
    $quizResults = new QuizResults();
    $quizResults->InitFromRequest($requestParameters);

    writeToDatabase($quizResults);
    
    $generator = QuizReportFactory::CreateGenerator($quizResults, $requestParameters);
    $report = $generator->createReport();

    $dateTime = date('Y-m-d_H-i-s');
    $resultFilename = dirname(__FILE__) . "/result/quiz_result_{$dateTime}.txt";
    @file_put_contents($resultFilename, $report);


    echo "OK";
}
catch (Exception $e)
{
    error_log($e);

    echo "Error: " . $e->getMessage();
}

function _log($requestParameters)
{
    global $original_post_data;
    
    $logFilename = dirname(__FILE__) . '/log/quiz_results.log';
    $event       = array('ts' => date('Y-m-d H:i:s'), 'request_parameters' => $requestParameters, 'ts_' => time());

    $logMessage  = json_encode($event);
    $logMessage .= ',' . PHP_EOL;
    @file_put_contents($logFilename, $logMessage, FILE_APPEND);

    $fp = fopen(dirname(__FILE__) . '/log/pp' . date('Y-m-d H:i:s') . '.txt', 'w+');
    fwrite($fp, serialize($original_post_data));
    fclose($fp);        
}
