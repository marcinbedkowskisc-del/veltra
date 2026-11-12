# Stage 6A.2-FIX — PARTIAL

Implementacja i testy PASS; wysylka aktywacji oczekuje na osobiste potwierdzenie wlasciciela.

## Zmiany i zabezpieczenia

- [RecoverStage6a1Identity.php](backend/app/Console/Commands/RecoverStage6a1Identity.php): komenda `veltra:recover-stage6a1-identity`, bez endpointu HTTP i bez nowego systemu hasel. Normalny bootstrap i `AccountLinks` pozostaly bez zmian.
- Sprawdza CLI/TTY, `local`, kontener, root-owned plik poswiadczenia `0444` z terminem waznosci, hostname, rzeczywiste parametry polaczenia PostgreSQL, OID, adres serwera, organizacje/tozsamosc i lokalny SMTP. Samo `APP_ENV` lub nazwa bazy nie wystarcza. Administrator hosta potwierdza izolacje przez metadane Docker; plik jest jego jawnym poswiadczeniem, nie niezaleznym dowodem kryptograficznym.
- Odmawia po aktywacji, zakonczeniu bootstrapu, przy dodatkowych kontach, lokalizacjach, przypisaniach, grantach, danych RAW, wlaczonych modulach lub audycie wskazujacym na eksploatacje.
- Po wpisaniu przez wlasciciela `ODZYSKAJ STAGE6A1` ponownie sprawdza stan pod blokada organizacji. Istniejacy `AccountLinks` zastepuje link, a `SecurityMutation` zapisuje `initial_identity.local_recovery`. Zachowano limity wysylek.
- Transakcja obejmuje zastapienie tokenu i audyt. Przy awarii SMTP zmiany sa wycofywane, poprzedni stan pozostaje, a odrzucenie jest audytowane osobno. SMTP nie jest transakcyjny: przy awarii commit/niejednoznacznym potwierdzeniu moze dotrzec wiadomosc z niewaznym linkiem. Komenda nie raportuje wtedy sukcesu; wymagane sprawdzenie stanu i ponowienie.
- Bez ustawiania hasla, zmian RBAC, migracji, resetu bazy i zmian gotowosci modulow. Nie ujawniano rzeczywistych sekretow ani zawartosci wiadomosci.
- [README.md](README.md) opisuje granice procedury, poswiadczenie i uruchomienie. Zachowano wczesniejsza zmiane README z Stage 6A.1.

## Weryfikacja

W jednorazowym runnerze `backend-test` projektu `veltra-stage6a1` zamontowano biezace katalogi `backend/app` i `backend/tests` tylko do odczytu. Baza testow: odrebna `veltra_testing`, nie baza aplikacji. Bez migracji.

```text
php artisan test tests/Feature/Stage6a1IdentityRecoveryTest.php tests/Feature/AccountSecurityTest.php tests/Feature/IdentityAuthenticationTest.php tests/Feature/ModuleConfigurationTest.php tests/Feature/RawMaterialCapabilitiesApiTest.php
70 passed (614 assertions), 53.90 s

vendor/bin/pint --test app/Console/Commands/RecoverStage6a1Identity.php tests/Feature/Stage6a1IdentityRecoveryTest.php
PASS, 2 pliki
```

Dziesiec nowych testow obejmuje odzyskanie, odmowy, stary/nowy token, audyt, brak ujawnienia sekretow, potwierdzenie, `--check`, ponowienia, limity, zmiane stanu przed wykonaniem oraz wycofanie zmian przy awarii SMTP. Metadane attestation/polaczenia Stage 6A.1 sa symulowane w testach; odczyty i zapisy aplikacyjne sa wykonywane na prawdziwym testowym PostgreSQL. Sprawdzono rowniez dokladny niesekretny komunikat logowania awarii SMTP. Poczatkowe bledy fixture i asercji testowych poprawiono; finalny zestaw w calosci PASS.

Realny `--check` w `veltra-stage6a1-backend-1`: PASS, bez zapisu i wysylki. Frontend i API health: HTTP 200. Mailpit nadal zawiera jedna dotychczasowa wiadomosc; nowej aktywacji nie wyslano. Nie testowano logowania wlasciciela ani CRUD RAW. Frontend bez zmian; jego testow/build nie ponawiano w tej poprawce.

## Uruchomienie przez wlasciciela

```bash
docker exec -it veltra-stage6a1-backend-1 php artisan veltra:recover-stage6a1-identity
```

Wpisz `ODZYSKAJ STAGE6A1`. Po komunikacie sukcesu otworz samodzielnie `http://localhost:8026/`, uzyj najnowszej wiadomosci i ustaw wlasne haslo. Nastepnie zaloguj sie pod `http://localhost:5174/`. Nie uzywaj starego ujawnionego linku. Nie udostepniaj hasla ani linku asystentowi.

Poswiadczenie z obecnej weryfikacji jest wazne do **2026-11-12 22:20:42 UTC (23:20:42 CET)**. Po wygasnieciu lub zmianie kontenera/adresow wymagana jest ponowna weryfikacja izolacji. Po aktywacji nalezy usunac z tego kontenera wylacznie `/run/veltra-stage6a1-recovery.json`. Kod komendy jest zapisany w repozytorium i udostepniony w istniejacym lokalnym kontenerze; nie przebudowywano obrazow. Odtworzenie kontenera wymaga ponownego udostepnienia kodu i poswiadczenia.

## Git, zasoby i granice

HEAD pozostaje `b432ddeebfd2fcf081099f689f57eaf6606adbf1`. Zachowano wszystkie zastane zmiany: README, init-env, visual-preview-receiver, raporty Stage 5/6 oraz katalogi artifacts/references. Nowe pliki tej poprawki: komenda, testy i ten raport. Jednorazowe kontenery walidacji usuniete przez `--rm`; uslugi lokalnej aplikacji pozostaly uruchomione. Poswiadczenie w lokalnym backendzie pozostawiono na czas potwierdzenia przez wlasciciela.

`RAW_MATERIALS = NOT_READY`. Bez commitu, pushu, deploymentu, Google Drive i operacji na innych instalacjach.
