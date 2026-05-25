<?php
defined('MOODLE_INTERNAL') || die();

// Načítanie pomocných funkcií z lib.php.
// V tomto súbore je hlavná logika AI tutora.

require_once(__DIR__ . '/lib.php');

class block_aitutor extends block_base
{
    // Nastavenie názvu pluginu
    public function init()
    {
        $this->title = get_string('pluginname', 'block_aitutor');
    }


    // Celý obsah bloku, ktorý používateľ vidí v kurze.
    public function get_content()
    {
        global $COURSE;
        // Ak už bol obsah bloku raz vytvorený, Moodle ho znovu negeneruje.
        if ($this->content !== null) {
            return $this->content;
        }


        $this->content = new stdClass();

        // Získanie ID aktuálneho kurzu.
        $courseid = $COURSE->id;

        // Načítanie otázky od používateľa, ak bola odoslaná.
        $question = optional_param('aitutor_question', '', PARAM_TEXT);

        // Načítanie PDF materiálov a príprava pre AI.
        $pdfinfo = block_aitutor_get_course_pdf_context($courseid, 12000, $question);

        // Vytvorenie unikátnych ID pre formulár, chat a ďalšie HTML prvky.
        // ID obsahujú číslo inštancie bloku, aby sa prvky navzájom nemiešali.
        $formid = 'aitutor-form-' . $this->instance->id;
        $textareaid = 'aitutor-question-' . $this->instance->id;
        $chatid = 'aitutor-chat-' . $this->instance->id;
        $loaderid = 'aitutor-loader-' . $this->instance->id;
        $submitid = 'aitutor-submit-' . $this->instance->id;
        $diagnosticsid = 'aitutor-diagnostics-' . $this->instance->id;
        $usedfilesid = 'aitutor-usedfiles-' . $this->instance->id;

        // URL adresa súboru ajax.php, kam sa bude posielať otázka používateľa.
        $ajaxurl = new moodle_url('/blocks/aitutor/ajax.php');

        // Celý vizuálny obsah bloku.
        $html = '';

        $html .= html_writer::start_tag('div', [
            'style' => 'font-family: Arial, sans-serif;'
        ]);

        $html .= html_writer::tag(
            'div',
            'Môžeš sa pýtať otázky k materiálom v tomto kurze.',
            ['style' => 'font-size:14px;color:#555;margin-bottom:10px;line-height:1.4;']
        );

        // Chatovacie okno, kde sa zobrazujú otázky a odpovede.
        $html .= html_writer::start_tag('div', [
            'id' => $chatid,
            'style' => 'background:#fafafa;border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin-bottom:12px;max-height:320px;overflow-y:auto;'
        ]);

        // Správa zobrazená pred prvou otázkou.
        $html .= html_writer::tag(
            'div',
            'Zatiaľ tu neprebehla žiadna otázka.',
            [
                'id' => $chatid . '-empty',
                'style' => 'font-size:13px;color:#777;'
            ]
        );

        $html .= html_writer::end_tag('div');
        // Správa zobrazená počas čakania.
        $html .= html_writer::tag(
            'div',
            'AI Tutor pripravuje odpoveď',
            [
                'id' => $loaderid,
                'style' => 'display:none;margin-bottom:10px;background:#eff6ff;color:#1e3a8a;padding:9px 10px;border-radius:8px;font-size:13px;'
            ]
        );


        // Formulár, cez ktorý študent zadáva otázku pre AI tutora.
        $html .= html_writer::start_tag('form', [
            'method' => 'post',
            'action' => '#',
            'id' => $formid,
            'style' => 'margin-top:8px;'
        ]);


        $html .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey()
        ]);


        $html .= html_writer::tag('textarea', s($question), [
            'name' => 'aitutor_question',
            'id' => $textareaid,
            'placeholder' => 'Napíš otázku pre AI tutora...',
            'rows' => 3,
            'style' => 'width:100%;padding:10px;border:1px solid #d1d5db;border-radius:10px;box-sizing:border-box;resize:vertical;font-family:Arial,sans-serif;font-size:14px;line-height:1.4;'
        ]);


        $html .= html_writer::empty_tag('input', [
            'type' => 'submit',
            'id' => $submitid,
            'value' => 'Odoslať',
            'style' => 'margin-top:8px;width:100%;padding:10px;background:#2563eb;color:white;border:none;border-radius:10px;font-weight:bold;cursor:pointer;'
        ]);

        $html .= html_writer::end_tag('form');

        $html .= html_writer::tag(
            'div',
            'Tip: stlač Enter pre odoslanie, Shift + Enter pre nový riadok.',
            ['style' => 'font-size:12px;color:#666;margin-top:8px;']
        );

        // Zobrazenie zoznamu PDF materiálov nájdených v aktuálnom kurze.
        $html .= html_writer::tag(
            'div',
            html_writer::tag('strong', 'Materiály v kurze') .
                (!empty($pdfinfo['files']) ? '' : html_writer::tag('div', 'Žiadne PDF neboli nájdené.', ['style' => 'margin-top:6px;color:#777;'])),
            ['style' => 'margin-top:14px;font-size:13px;color:#333;']
        );

        // Ak sú v kurze nájdené PDF súbory, vypíšu sa ich názvy.
        if (!empty($pdfinfo['files'])) {
            foreach ($pdfinfo['files'] as $filename) {
                $html .= html_writer::tag(
                    'div',
                    '📄 ' . s($filename),
                    ['style' => 'background:#f9fafb;border:1px solid #e5e7eb;padding:8px 10px;border-radius:8px;margin-top:6px;font-size:13px;']
                );
            }
        }


        $contextstatus = $pdfinfo['context'] !== ''
            ? 'Textový kontext z PDF bol úspešne pripravený.'
            : 'Textový kontext z PDF zatiaľ nie je dostupný.';

        $html .= html_writer::tag(
            'div',
            $contextstatus,
            ['style' => 'margin-top:12px;background:#eff6ff;padding:9px 10px;border-radius:8px;font-size:12px;color:#1e3a8a;']
        );



        $availablefilestext = !empty($pdfinfo['files'])
            ? implode(', ', $pdfinfo['files'])
            : 'Žiadne PDF neboli nájdené.';

        // Zobrazenie, ktoré PDF sú dostupné a ktoré boli použité pri odpovedi.
        $html .= html_writer::start_tag('div', [
            'id' => $diagnosticsid,
            'style' => 'margin-top:10px;background:#fff7ed;padding:9px 10px;border-radius:8px;font-size:12px;color:#9a3412;'
        ]);

        $html .= html_writer::tag('strong', 'Diagnostika');
        $html .= html_writer::tag('div', '• Dostupné PDF: ' . s($availablefilestext), ['style' => 'margin-top:4px;']);
        $html .= html_writer::tag(
            'div',
            '• Použité PDF pri odpovedi: zatiaľ nebola položená otázka.',
            [
                'id' => $usedfilesid,
                'style' => 'margin-top:4px;'
            ]
        );

        $html .= html_writer::end_tag('div');


        // JavaScript zabezpečuje odosielanie otázky bez obnovenia stránky.
        // Používa AJAX požiadavku na súbor ajax.php a následne zobrazí odpoveď v chate.

        $script = <<<JS
(function() {
   
    var form = document.getElementById("$formid");
    var textarea = document.getElementById("$textareaid");
    var chat = document.getElementById("$chatid");
    var empty = document.getElementById("{$chatid}-empty");
    var loader = document.getElementById("$loaderid");
    var submitBtn = document.getElementById("$submitid");
var usedFilesBox = document.getElementById("$usedfilesid");
var ajaxUrl = "{$ajaxurl->out(false)}";
    var sesskey = "{$GLOBALS['USER']->sesskey}";
    var courseid = "{$courseid}";

    
    var dots = 0;
    var loaderInterval = null;
    var isLoading = false;
    var currentRequestId = 0;

    if (!form || !textarea || !chat || !loader || !submitBtn) {
        return;
    }

   // Ošetrenie.
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function nl2br(text) {
        return escapeHtml(text).replace(/\\n/g, '<br>');
    }

    // Pridanie novej správy do chatovacieho okna.
    function appendMessage(label, content, bg) {
        var box = document.createElement('div');
        box.style.background = bg;
        box.style.borderRadius = '10px';
        box.style.padding = '10px';
        box.style.marginBottom = '10px';

        var title = document.createElement('div');
        title.style.fontSize = '12px';
        title.style.fontWeight = 'bold';
        title.style.color = '#1f2937';
        title.style.marginBottom = '4px';
        title.textContent = label;

        var body = document.createElement('div');
        body.style.whiteSpace = 'pre-wrap';
        body.style.lineHeight = '1.5';
        body.innerHTML = nl2br(content);

        box.appendChild(title);
        box.appendChild(body);
        chat.appendChild(box);
        chat.scrollTop = chat.scrollHeight;
    }

    // Spustenie animácie počas čakania na odpoveď AI.
    function startLoader() {
        loader.style.display = 'block';
        loader.textContent = 'AI Tutor pripravuje odpoveď';
        dots = 0;

        if (loaderInterval) {
            clearInterval(loaderInterval);
        }

        loaderInterval = setInterval(function() {
            dots = (dots + 1) % 4;
            loader.textContent = 'AI Tutor pripravuje odpoveď' + '.'.repeat(dots);
        }, 500);
    }

 
    function stopLoader() {
        loader.style.display = 'none';
        if (loaderInterval) {
            clearInterval(loaderInterval);
            loaderInterval = null;
        }
    }

    
    // Otázka sa odošle cez AJAX namiesto klasického obnovenia stránky.
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        if (isLoading) {
            return;
        }

        var question = textarea.value.trim();
        if (!question) {
            return;
        }

        if (empty) {
            empty.remove();
            empty = null;
        }

        appendMessage('Ty', question, '#eef2ff');

        isLoading = true;
        currentRequestId++;
        var requestId = currentRequestId;

        startLoader();
        submitBtn.disabled = true;
        textarea.disabled = true;

        var params = new URLSearchParams();
        params.append('courseid', courseid);
        params.append('question', question);
        params.append('sesskey', sesskey);
        params.append('_ts', Date.now().toString());
        params.append('_rid', requestId.toString());

        // Odoslanie otázky na serverový súbor ajax.php.
        fetch(ajaxUrl, {
            method: 'POST',
            cache: 'no-store',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Cache-Control': 'no-cache'
            },
            body: params.toString()
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        // Spracovanie odpovede zo servera vo formáte JSON.
        .then(function(data) {
            if (requestId !== currentRequestId) {
                return;
            }

            stopLoader();
            isLoading = false;
            submitBtn.disabled = false;
            textarea.disabled = false;
            textarea.value = '';
            textarea.focus();

            if (!data.ok) {
                appendMessage('AI Tutor', data.error || 'Nepodarilo sa získať odpoveď.', '#fef2f2');
                return;
            }

if (usedFilesBox) {
    if (data.usedfiles && data.usedfiles.length) {
        usedFilesBox.textContent = '• Použité PDF pri odpovedi: ' + data.usedfiles.join(', ');
    } else {
        usedFilesBox.textContent = '• Použité PDF pri odpovedi: nenašli sa relevantné PDF.';
    }
}
            appendMessage('AI Tutor', data.answer || '', '#ecfdf5');
        })
        // Ak zlyhá komunikácia so serverom.
        .catch(function(error) {
            if (requestId !== currentRequestId) {
                return;
            }

            stopLoader();
            isLoading = false;
            submitBtn.disabled = false;
            textarea.disabled = false;
            textarea.focus();

            appendMessage('AI Tutor', 'Nastala chyba pri komunikácii so serverom: ' + error.message, '#fef2f2');
        });
    });

    // Umožní odoslať otázku klávesom Enter.
    // Shift + Enter slúži na nový riadok.
    textarea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (!isLoading) {
                form.requestSubmit();
            }
        }
    });
})();
JS;

        $html .= html_writer::tag('script', $script);

        $html .= html_writer::end_tag('div');

        $this->content->text = $html;
        $this->content->footer = '';

        return $this->content;
    }

    public function applicable_formats()
    {
        return [
            'course-view' => true,
            'site-index' => false,
            'my' => false
        ];
    }

    public function has_config()
    {
        return false;
    }

    public function instance_allow_multiple()
    {
        return false;
    }
}
