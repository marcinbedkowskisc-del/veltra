# VELTRA 2.0 — Stage 3E-B.3.1

## Status końcowy: PASS

Zaimplementowano atomową, zbiorczą archiwizację materiałów RAW w trybie
**ALL-OR-NOTHING**. Walidacja wszystkich wskazanych pozycji odbywa się w jednej
podpisanej transakcji, przed pierwszą zmianą lifecycle. Każdy błąd walidacji,
autoryzacji lub zapisu przerywa operację i wycofuje wszystkie zmiany oraz
zdarzenia audytowe. Nie wykonano frontendowej implementacji, deploymentu,
aktywacji modułu, commit ani push.

## Zmienione pliki

1. `backend/app/Domain/RawMaterials/RawMaterialService.php`
2. `backend/app/Http/Controllers/Api/V1/RawMaterialController.php`
3. `backend/app/Http/Requests/RawMaterialBulkArchiveRequest.php` — nowy
4. `backend/routes/api.php`
5. `backend/tests/Concerns/RawDocumentsStage3eB3Tests.php`
6. `backend/tests/Support/concurrent_raw_edit.php`
7. `backend/README.md`
8. `VELTRA_STAGE_3E_B31_RAW_ATOMIC_BULK_ARCHIVE_REPORT.md` — ten raport

### Migracje

Nie utworzono ani nie zmieniono migracji. Zmiana korzysta z istniejących tabel,
guardów i `edit_version`; nie wymaga zmiany schematu.

## Kontrakt API

Wszystkie endpointy dziedziczą istniejące middleware RAW: sesję, uwierzytelnienie,
tożsamość, kontekst operacyjny, limit żądań i bramkę dostępności modułu.

### Walidacja bez mutacji

`POST /api/v1/raw-materials/bulk-archive/validation`

```json
{
  "items": [
    {"id": "material-uuid", "version": "edit-version-uuid"}
  ]
}
```

Zachowano istniejący kontrakt `items: [{id, version}]`. Pomyślna walidacja
zwraca `validated_count`, `mutation_available: true`,
`mutation_performed: false` i `policy_status: "ALL_OR_NOTHING"`. Nie rezerwuje
stanu; endpoint mutacji ponawia wszystkie kontrole.

### Mutacja

`POST /api/v1/raw-materials/bulk-archive`

Żądanie ma ten sam `items` oraz wymagane `reason` (3–500 znaków). Lista musi
zawierać od 1 do 100 różnych UUID materiałów, każdy z obserwowaną wersją B.2.

Sukces zwraca `data.operation_id`, `data.archived_count` i `data.materials[]`
z `id`, `lifecycle_state` i nową `version`.

Odpowiedzi błędów zachowują istniejące znaczenie:

- **422** — pusta, zduplikowana, nadmierna lub błędnie sformatowana lista/wartość;
- **428** — brak wersji;
- **409** — nieaktualna wersja (`RAW_MATERIAL_VERSION_CONFLICT`) albo materiał
  niekwalifikujący się do archiwizacji (`RAW_MATERIAL_ARCHIVE_INVALID`);
- **403** — brak skutecznego prawa lub właściwego kontekstu;
- **404** — materiał niedostępny w organizacji aktora, bez ujawniania jego
  tożsamości.

## Transakcja, blokady i współbieżność

1. Ustanowienie istniejącego podpisanego scope `raw-material.mutate`.
2. Transakcja obejmująca całą operację.
3. Blokada organizacji.
4. Ponowna autoryzacja w transakcji.
5. Blokady wszystkich istniejących materiałów w deterministycznej kolejności UUID.
6. Sprawdzenie obecności pełnego zbioru, wersji i warunków lifecycle dla każdej
   pozycji.
7. Dopiero po pomyślnej walidacji całości — zmiany lifecycle i audyt każdej
   pozycji.
8. Commit wyłącznie po powodzeniu wszystkich zapisów.

Zachowano warunki pojedynczej archiwizacji: kwalifikują się wyłącznie materiały
`ACTIVE` lub `BLOCKED`, bez rewizji `working` i `pending`. Archiwizacja ustawia
`ARCHIVED`, zachowując istniejące rewizje, dokumenty, ceny i relacje handlowe.
Nie jest to formalne wycofanie RAW i nie oferuje automatycznego Undo.

Wersja każdego materiału jest porównywana pod blokadą przez istniejący
`RawMaterialVersion::assertCurrent`. Brak wersji daje 428, błędny format 422,
a niezgodność 409. Istniejący trigger B.2 nadaje nową wersję każdemu
zarchiwizowanemu materiałowi.

Testy współbieżności obejmują nakładające się żądania z odwróconą kolejnością
wejścia, wyścig z edycją oraz wyścig z zatwierdzaniem. Wspólna blokada
organizacji serializuje mutacje, a kolejność blokad materiałów pozostaje
deterministyczna.

## Autoryzacja, audyt i ponowienia

W transakcji ponownie sprawdzane jest istniejące skuteczne uprawnienie
`RAW_MATERIALS_ARCHIVE`, aktywność aktora/organizacji/lokalizacji, członkostwo,
dostępność modułu i kontekst primary central kitchen. Udostępnienie materiału
odbiorcy pozostaje wyłącznie do odczytu i nie daje prawa archiwizacji.
Selekcja jest ograniczona zapytaniem do organizacji aktora.

Każdy zarchiwizowany materiał otrzymuje kanoniczne zdarzenie `ARCHIVED`.
Zdarzenia operacji mają wspólne `operation_id` i zapisują się w tej samej
transakcji co zmiany. Test wstrzykuje błąd po wcześniejszej mutacji i audycie;
potwierdza rollback obu stanów oraz wszystkich zdarzeń `ARCHIVED`.

Istniejąca pojedyncza archiwizacja nie korzysta z cache idempotencji i nie
wprowadzono konkurencyjnego mechanizmu. Ponowienie ze starą wersją kończy się
konfliktem wersji; użycie bieżącej wersji już zarchiwizowanego materiału
odrzuca warunek lifecycle. Żadne z tych żądań nie dopisuje kolejnego zdarzenia.
Nagłówek idempotencji nie jest używany do odtwarzania poprzedniej odpowiedzi.

## Testy i kontrole jakości

Testy wykonano na **PostgreSQL 17.11** w osobnym projekcie Docker Compose
`veltra-b31-isolated`, z wewnętrzną siecią, bazą testową i ograniczoną rolą
runtime `veltra_app`. Migracje 042, 043 i 044 uruchomiono wyłącznie w tej
izolowanej bazie; nie zmieniano ich.

- Testy celowane `stage3e_b31`: **13 passed, 112 assertions**.
- Pełna regresja backendu: **359 passed, 3600 assertions**.
- Laravel Pint (`--test`): **PASS, 286 plików**.
- `git diff --check`: **PASS**.

Pokryto sukces dla dwóch i kilku materiałów, brak częściowego commitu przy
pojedynczej niekwalifikującej się pozycji, starej wersji lub niedostępnym
materiale, błędne/duplikowane/puste/przekraczające limit listy, brak i błędny
format wersji, rollback po wstrzykniętym błędzie zapisu, ponowienie bez
duplikowania audytu, odbiorcę z read-only share, zachowanie historii dokumentu,
rewizji i ceny, a także scenariusze współbieżne.

Pełna regresja obejmuje istniejące kontrakty dokumentów i eksportów CSV,
wersjonowanie B.2, ceny/SKU B.1, udostępnianie 3D.1 oraz fail-closed
`RAW_MATERIALS`.

## Granice i pozostałe blokery

- Fizyczne usuwanie szkiców nadal pozostaje zablokowane zgodnie z B.2; nie
  osłabiono ochrony historii ani nie dodano hard-delete.
- `RAW_MATERIALS` nadal ma status **NOT_READY**. Testowe fixture gotowości
  dotyczyły wyłącznie izolowanych testów; nie aktywowano modułu.
- Nie zmieniano zasobów instalacyjnych. Dedykowane kontenery, sieć i wolumeny
  `veltra-b31-isolated` usunięto po testach; syntetyczny plik środowiskowy
  również usunięto. Działające kontenery projektu `veltra` pozostały uruchomione.
- Tytuły wskazanych Google Docs były widoczne, ale ich treść nie została
  udostępniona jako tekst; próba eksportu zakończyła się HTTP 400. W raporcie
  nie deklaruję przeczytania tych dokumentów. Wykorzystano wiążący kontrakt
  dostarczony w zadaniu oraz istniejące lokalne raporty B.1, B.2, B.3
  i zgodności backendu.
- W tym runtime nie ma obsługiwanej, uwierzytelnionej ścieżki uploadu i
  weryfikacji pliku w Google Drive. Raport pozostaje lokalnie; nie deklaruję
  przesłania do Drive.
