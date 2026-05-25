<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_sesskey();

$courseid = required_param('courseid', PARAM_INT);
$question = required_param('question', PARAM_TEXT);

header('Content-Type: application/json; charset=utf-8');

$question = trim($question);

if ($question === '') {
    echo json_encode([
        'ok' => false,
        'error' => 'Otázka je prázdna.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdfinfo = block_aitutor_get_course_pdf_context($courseid, 24000, $question);
    $answer = block_aitutor_call_ai($question, $pdfinfo['context']);

    echo json_encode([
        'ok' => true,
        'answer' => $answer,
        'files' => $pdfinfo['files'],
        'usedfiles' => $pdfinfo['usedfiles'],
        'diagnostics' => $pdfinfo['diagnostics'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

exit;
