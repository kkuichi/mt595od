<?php
defined('MOODLE_INTERNAL') || die();

function block_aitutor_get_ai_config(): array
{
    $configpath = '/var/www/moodle/secure/openai_config.php';

    if (!file_exists($configpath)) {
        return ['error' => 'Chyba: konfiguračný súbor AI neexistuje.'];
    }

    $config = require($configpath);

    if (empty($config['primary']['apikey']) || empty($config['primary']['provider']) || empty($config['primary']['model'])) {
        return ['error' => 'Chyba: primárna AI konfigurácia nie je správne nastavená.'];
    }

    return $config;
}

function block_aitutor_pdftotext_available(): bool
{
    $path = trim((string)shell_exec('command -v pdftotext 2>/dev/null'));
    return $path !== '';
}


function block_aitutor_extract_text_from_pdf_file(stored_file $file): string
{
    if (!block_aitutor_pdftotext_available()) {
        return '';
    }

    $tempdir = make_request_directory();
    $sourcepath = $tempdir . '/' . uniqid('aitutor_', true) . '.pdf';
    $targetpath = $tempdir . '/' . uniqid('aitutor_txt_', true) . '.txt';

    $file->copy_content_to($sourcepath);

    $cmd = 'pdftotext -layout -nopgbrk ' . escapeshellarg($sourcepath) . ' ' . escapeshellarg($targetpath) . ' 2>/dev/null';
    shell_exec($cmd);

    if (!file_exists($targetpath)) {
        return '';
    }

    $text = (string)file_get_contents($targetpath);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    return $text;
}

function block_aitutor_normalize_text(string $text): string
{
    $text = core_text::strtolower($text);
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function block_aitutor_split_text_into_chunks(string $text, int $chunkchars = 1200, int $overlap = 200): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $chunks = [];
    $length = core_text::strlen($text);
    $start = 0;

    while ($start < $length) {
        $chunk = core_text::substr($text, $start, $chunkchars);
        $chunk = trim($chunk);

        if ($chunk !== '') {
            $chunks[] = $chunk;
        }

        if (($start + $chunkchars) >= $length) {
            break;
        }

        $start += ($chunkchars - $overlap);
        if ($start < 0) {
            break;
        }
    }

    return $chunks;
}

function block_aitutor_score_chunk(string $question, string $chunk, string $filename = ''): int
{
    $questionnorm = block_aitutor_normalize_text($question);
    $chunknorm = block_aitutor_normalize_text($chunk . ' ' . $filename);

    if ($questionnorm === '' || $chunknorm === '') {
        return 0;
    }

    $score = 0;
    $words = array_unique(explode(' ', $questionnorm));

    foreach ($words as $word) {
        if (core_text::strlen($word) < 3) {
            continue;
        }

        if (strpos($chunknorm, $word) !== false) {
            $score += 3;
        }
    }

    if (strpos($chunknorm, $questionnorm) !== false) {
        $score += 10;
    }

    return $score;
}






function block_aitutor_get_course_pdf_context(int $courseid, int $maxchars = 24000, string $question = ''): array
{
    $result = [
        'context' => '',
        'files' => [],
        'usedfiles' => [],
        'diagnostics' => []
    ];

    $modinfo = get_fast_modinfo($courseid);
    $fs = get_file_storage();
    $allchunks = [];

    if (!block_aitutor_pdftotext_available()) {
        $result['diagnostics'][] = 'Na serveri nie je dostupný nástroj pdftotext.';
        return $result;
    }

    foreach ($modinfo->cms as $cm) {
        if (!$cm->uservisible) {
            continue;
        }

        $context = context_module::instance($cm->id);
        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'filename', false);

        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }

            $filename = $file->get_filename();

            if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
                continue;
            }

            if (!in_array($filename, $result['files'], true)) {
                $result['files'][] = $filename;
            }

            $text = block_aitutor_extract_text_from_pdf_file($file);

            if ($text === '') {
                $result['diagnostics'][] = 'Nepodarilo sa načítať text z PDF: ' . $filename;
                continue;
            }

            $chunks = block_aitutor_split_text_into_chunks($text, 1400, 250);

            foreach ($chunks as $index => $chunk) {
                $score = trim($question) === ''
                    ? 1
                    : block_aitutor_score_chunk($question, $chunk, $filename);

                $allchunks[] = [
                    'filename' => $filename,
                    'text' => $chunk,
                    'score' => $score,
                    'index' => $index,
                ];
            }
        }
    }

    if (empty($allchunks)) {
        $result['diagnostics'][] = 'Nepodarilo sa vytvoriť textový kontext z PDF materiálov.';
        return $result;
    }

    // Keď ešte nie je otázka, priprav len všeobecný kontext zo všetkých PDF
    // a diagnostika ukáže všetky dostupné materiály.
    if (trim($question) === '') {
        $selectedparts = [];
        $totalchars = 0;
        $usedfiles = [];

        // Zober prvý chunk z každého PDF.
        foreach ($allchunks as $item) {
            if ($item['index'] !== 0) {
                continue;
            }

            $part = "[Súbor: {$item['filename']}]\n{$item['text']}\n\n";
            $partlen = core_text::strlen($part);

            if (($totalchars + $partlen) > $maxchars) {
                continue;
            }

            $selectedparts[] = $part;
            $totalchars += $partlen;
            $usedfiles[$item['filename']] = true;
        }

        // Doplň ďalšie chunky, ak sa ešte zmestia.
        foreach ($allchunks as $item) {
            $part = "[Súbor: {$item['filename']}]\n{$item['text']}\n\n";
            $partlen = core_text::strlen($part);

            if (($totalchars + $partlen) > $maxchars) {
                continue;
            }

            if ($item['index'] === 0) {
                continue;
            }

            $selectedparts[] = $part;
            $totalchars += $partlen;
            $usedfiles[$item['filename']] = true;

            if ($totalchars >= $maxchars) {
                break;
            }
        }

        $result['context'] = trim(implode("\n", $selectedparts));
        $result['usedfiles'] = array_keys($usedfiles);
        $result['diagnostics'][] = 'Dostupné PDF: ' . implode(', ', $result['files']);
        $result['diagnostics'][] = 'Použité PDF pri odpovedi: zatiaľ nebola položená otázka.';

        return $result;
    }

    // Keď je otázka, použije sa RAG.
    usort($allchunks, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    $selectedparts = [];
    $totalchars = 0;
    $usedfiles = [];
    $usedchunks = [];

    // Najprv zober najlepší relevantný chunk z každého PDF.
    $bestperfile = [];
    foreach ($allchunks as $item) {
        $filename = $item['filename'];

        if (!isset($bestperfile[$filename]) && $item['score'] > 0) {
            $bestperfile[$filename] = $item;
        }
    }

    foreach ($bestperfile as $item) {
        $part = "[Súbor: {$item['filename']}]\n{$item['text']}\n\n";
        $partlen = core_text::strlen($part);
        $uniqueid = md5($item['filename'] . '|' . $item['index']);

        if (($totalchars + $partlen) > $maxchars) {
            continue;
        }

        $selectedparts[] = $part;
        $totalchars += $partlen;
        $usedfiles[$item['filename']] = true;
        $usedchunks[$uniqueid] = true;
    }

    // Doplň ďalšie najlepšie chunky bez duplicít.
    foreach ($allchunks as $item) {
        $part = "[Súbor: {$item['filename']}]\n{$item['text']}\n\n";
        $partlen = core_text::strlen($part);
        $uniqueid = md5($item['filename'] . '|' . $item['index']);

        if (isset($usedchunks[$uniqueid])) {
            continue;
        }

        if (($totalchars + $partlen) > $maxchars) {
            continue;
        }

        $selectedparts[] = $part;
        $totalchars += $partlen;
        $usedfiles[$item['filename']] = true;
        $usedchunks[$uniqueid] = true;

        if (count($selectedparts) >= 12) {
            break;
        }
    }

    if (empty($selectedparts)) {
        // Fallback: ak nič neskórovalo, zober top chunky celkovo.
        foreach ($allchunks as $item) {
            $part = "[Súbor: {$item['filename']}]\n{$item['text']}\n\n";
            $partlen = core_text::strlen($part);

            if (($totalchars + $partlen) > $maxchars) {
                continue;
            }

            $selectedparts[] = $part;
            $totalchars += $partlen;
            $usedfiles[$item['filename']] = true;

            if (count($selectedparts) >= 10) {
                break;
            }
        }
    }

    if (empty($selectedparts)) {
        $result['diagnostics'][] = 'Nepodarilo sa vybrať relevantný kontext z PDF materiálov.';
        return $result;
    }

    $result['context'] = trim(implode("\n", $selectedparts));
    $result['usedfiles'] = array_keys($usedfiles);
    $result['diagnostics'][] = 'Dostupné PDF: ' . implode(', ', $result['files']);
    $result['diagnostics'][] = 'Použité PDF pri odpovedi: ' . implode(', ', $result['usedfiles']);

    return $result;
}





function block_aitutor_build_messages(string $question, string $contexttext = ''): array
{
    $systemprompt = 'Si AI tutor pre univerzitný Moodle kurz. Odpovedaj vždy po slovensky, spisovne, zrozumiteľne a vecne.
Nepoužívaj Markdown (žiadne *, # ani **).
Používaj len obyčajný text a jednoduché odrážky pomocou pomlčiek (-).
Ak je poskytnutý kontext zo študijných materiálov, odpovedaj primárne z neho.
Ak odpoveď nie je jasne obsiahnutá v materiáloch, otvorene to povedz a nevymýšľaj si.
Ak je to vhodné, vysvetľuj pedagogicky a krok po kroku.';

    $usermessage = $question;

    if (trim($contexttext) !== '') {
        $usermessage =
            "Použi nasledujúce študijné materiály ako hlavný zdroj odpovede.\n\n" .
            "KONTEXT Z PDF:\n" .
            $contexttext . "\n\n" .
            "OTÁZKA ŠTUDENTA:\n" .
            $question;
    }

    return [
        ['role' => 'system', 'content' => $systemprompt],
        ['role' => 'user', 'content' => $usermessage],
    ];
}

function block_aitutor_call_provider(array $providerconfig, string $question, string $contexttext = ''): array
{
    $provider = $providerconfig['provider'] ?? '';
    $apikey = $providerconfig['apikey'] ?? '';
    $model = $providerconfig['model'] ?? '';

    if (empty($provider) || empty($apikey) || empty($model)) {
        return [
            'ok' => false,
            'retryable' => false,
            'status' => 0,
            'answer' => '',
            'error' => 'Neúplná konfigurácia AI providera.',
        ];
    }

    $messages = block_aitutor_build_messages($question, $contexttext);

    if ($provider === 'groq') {
        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apikey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [
                'ok' => false,
                'retryable' => true,
                'status' => 0,
                'answer' => '',
                'error' => 'Groq cURL chyba: ' . $error,
            ];
        }

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpcode === 200 && isset($data['choices'][0]['message']['content'])) {
            return [
                'ok' => true,
                'retryable' => false,
                'status' => 200,
                'answer' => trim($data['choices'][0]['message']['content']),
                'error' => '',
            ];
        }

        return [
            'ok' => false,
            'retryable' => in_array($httpcode, [0, 429, 500, 502, 503, 504], true),
            'status' => $httpcode,
            'answer' => '',
            'error' => 'Groq HTTP chyba: ' . $httpcode . '. Odpoveď servera: ' . $response,
        ];
    }

    if ($provider === 'gemini') {
        $parts = [];

        foreach ($messages as $message) {
            $prefix = $message['role'] === 'system' ? "SYSTÉMOVÁ INŠTRUKCIA:\n" : "OTÁZKA / KONTEXT:\n";
            $parts[] = ['text' => $prefix . $message['content']];
        }

        $payload = [
            'contents' => [
                [
                    'parts' => $parts
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.3,
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apikey);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [
                'ok' => false,
                'retryable' => true,
                'status' => 0,
                'answer' => '',
                'error' => 'Gemini cURL chyba: ' . $error,
            ];
        }

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpcode === 200 && !empty($data['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'ok' => true,
                'retryable' => false,
                'status' => 200,
                'answer' => trim($data['candidates'][0]['content']['parts'][0]['text']),
                'error' => '',
            ];
        }

        return [
            'ok' => false,
            'retryable' => in_array($httpcode, [0, 429, 500, 502, 503, 504], true),
            'status' => $httpcode,
            'answer' => '',
            'error' => 'Gemini HTTP chyba: ' . $httpcode . '. Odpoveď servera: ' . $response,
        ];
    }

    return [
        'ok' => false,
        'retryable' => false,
        'status' => 0,
        'answer' => '',
        'error' => 'Nepodporovaný provider: ' . $provider,
    ];
}

function block_aitutor_call_ai(string $question, string $contexttext = ''): string
{
    $config = block_aitutor_get_ai_config();

    if (!empty($config['error'])) {
        return $config['error'];
    }

    $primary = $config['primary'];
    $fallback = $config['fallback'] ?? null;

    $primaryresult = block_aitutor_call_provider($primary, $question, $contexttext);

    if ($primaryresult['ok']) {
        return $primaryresult['answer'];
    }

    if (!empty($fallback) && !empty($primaryresult['retryable'])) {
        $fallbackresult = block_aitutor_call_provider($fallback, $question, $contexttext);

        if ($fallbackresult['ok']) {
            return $fallbackresult['answer'];
        }

        return 'Primárny model zlyhal: ' . $primaryresult['error'] . ' Záložný model zlyhal: ' . $fallbackresult['error'];
    }

    return $primaryresult['error'];
}
