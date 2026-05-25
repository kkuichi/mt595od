# AI Tutor pre Moodle

Tento projekt je vlastný Moodle blok, ktorý som vytvorila ako súčasť práce.  
Cieľom bolo pridať do Moodle jednoduchého AI tutora, ktorý vie odpovedať na otázky študenta na základe materiálov v kurze.

## Vytvorenie prostredia

Moodle bol nainštalovaný na Linux serveri (Ubuntu).  
Pri inštalácii som najprv skúšala klonovanie z Git repozitára, ale finálne som použila oficiálny balík Moodle (verzia 5.1), ktorý som stiahla a rozbalila.

Následne som:
- nastavila webový server (Apache)
- vytvorila databázu v MariaDB
- nastavila priečinok moodledata
- nakonfigurovala prístupové práva

Po základnej inštalácii som Moodle upravovala priamo na serveri a pridávala vlastný plugin.

## Ako to funguje

Blok sa zobrazí v kurze a študent môže napísať otázku.  
Plugin následne prejde PDF materiály v kurze, vytiahne z nich text a vyberie relevantné časti podľa otázky.

Tieto časti sa pošlú spolu s otázkou do AI modelu, ktorý vygeneruje odpoveď.  
Výsledok sa zobrazí priamo v bloku bez reloadu stránky.

## Hlavné časti riešenia

- načítanie PDF súborov z kurzu
- extrakcia textu pomocou nástroja pdftotext
- rozdelenie textu na menšie časti (chunks)
- jednoduché skórovanie relevantnosti podľa otázky
- výber najrelevantnejších častí (RAG prístup)
- volanie AI modelu (Groq alebo Gemini)
- zobrazenie odpovede v Moodle bloku pomocou AJAX

## Štruktúra pluginu

- `block_aitutor.php` – hlavná časť bloku a používateľské rozhranie
- `ajax.php` – spracovanie otázky na serveri
- `lib.php` – logika práce s PDF a komunikácia s AI
- `version.php` – informácie o plugine
- `db/access.php` – definícia práv
- `lang/en/block_aitutor.php` – jazykové reťazce

## Použité technológie

- PHP (Moodle API)
- JavaScript (fetch, AJAX)
- AI API (Groq, Gemini)
- pdftotext

## Inštalácia

1. Skopírovať priečinok `aitutor` do:
   `moodle/blocks/`
2. Prihlásiť sa do Moodle ako admin
3. Spustiť aktualizáciu databázy (Site administration → Notifications)
4. Pridať blok do kurzu

## Poznámky

Plugin bol vytvorený pre Moodle verziu 5.x.  
Minimálna požadovaná verzia je definovaná v súbore version.php (2024042200).

AI konfigurácia (API kľúče) je uložená mimo webového adresára v priečinku `/secure` kvôli bezpečnosti.
