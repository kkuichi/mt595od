# block_aitutor – AI Tutor pre Moodle

Vlastný blok plugin pre Moodle vytvorený ako súčasť bakalárskej práce 
na TUKE FEI KKUI. Plugin umožňuje študentom klásť otázky k PDF 
materiálom kurzu a dostávať odpovede generované AI modelom 
na základe princípu RAG (Retrieval-Augmented Generation).

## Systémové požiadavky

- Moodle 5.x
- PHP 8.1+
- Linux server (Ubuntu)
- pdftotext (`sudo apt install poppler-utils`)
- Prístup k Gemini API alebo Groq API

## Inštalácia

1. Skopírovať priečinok `aitutor` do `moodle/blocks/`
2. Prihlásiť sa do Moodle ako administrátor
3. Prejsť do Site administration → Notifications a spustiť aktualizáciu databázy
4. Pridať blok do kurzu cez režim úprav

## Konfigurácia AI

Vytvoriť súbor `/var/www/moodle/secure/openai_config.php` 
mimo webového adresára s nasledujúcou štruktúrou:

```php
<?php
return [
    'primary' => [
        'provider' => 'gemini',
        'apikey'   => 'VAS_API_KLUC',
        'model'    => 'gemini-1.5-flash',
    ],
    'fallback' => [
        'provider' => 'groq',
        'apikey'   => 'VAS_API_KLUC',
        'model'    => 'llama3-8b-8192',
    ],
];
```

## Štruktúra pluginu

| Súbor | Popis |
|-------|-------|
| `block_aitutor.php` | Hlavná trieda bloku, HTML rozhranie, JavaScript |
| `ajax.php` | Príjem AJAX požiadaviek, overenie session, orchestrácia |
| `lib.php` | Extrakcia PDF, RAG algoritmus, volanie AI API |
| `version.php` | Verzia a metadáta pluginu |
| `db/access.php` | Definícia prístupových práv |
| `lang/en/block_aitutor.php` | Jazykové reťazce |

## Kľúčové parametre RAG algoritmu

- Veľkosť chunku: 1 400 znakov
- Prekrytie chunkov: 250 znakov
- Maximálny počet chunkov v kontexte: 12
- Maximálny počet znakov kontextu: 24 000
- Temperature modelu: 0,3
