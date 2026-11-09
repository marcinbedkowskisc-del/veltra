# VELTRA 2.0 — Stage 2F — Implementation 3D
## Raport domknięcia niezależnych wymagań RAW_MATERIALS

**Wynik:** **PARTIAL / BLOCKED**  
**RAW_MATERIALS:** **NOT_READY**  
**Zakres 3D:** przegląd i weryfikacja niezależnych wymagań K3; bez zmian kodu aplikacji i bez migracji.  
**Produkcja / Implementation 3C:** niezaimplementowane; nie wznowiono 3C ani nie rozpoczęto prac nad Production.

## 1. Źródła i ograniczenia przygotowania

Przejrzano bieżący kod, lokalną macierz wpływu K3 i zintegrowany projekt K2/K3, ADR-001, raporty Stage 1D/1E, Implementacji 1/2, raporty 3A/3B i preflight 3C. Odczytano także wskazany dokument Google Drive **„VELTRA 2.0 — SNAPSHOT — Zaleznosc RAW 3C od modulu PRODUKCJA — 2026-11-09”**. Snapshot potwierdza brak zatwierdzonego w VELTRA 2.0 cyklu partii i wymaga, aby przyszły moduł Production utrwalał niezmienny odnośnik do zatwierdzonej rewizji RAW.

Snapshot wskazuje, że historyczny `VELTRA_SOURCE_03_MODULES.md` opisuje moduły Production i Production Planner poprzedniego systemu, ale nie jest zgodą na ich przeniesienie 1:1. Historycznego systemu ani jego kodu nie audytowano w tej pracy.

Osobny, pierwotny plik źródłowy decyzji K3.1–K3.21 nie znajduje się w checkoutcie. Macierz poniżej opiera się na lokalnym `VELTRA_STAGE_2F_RAW_K3_TECHNICAL_IMPACT.md`, `VELTRA_STAGE_2F_K2_K3_INTEGRATED_TECHNICAL_DESIGN.md`, wskazanym snapshotcie zależności oraz bieżącym kodzie. Nierozstrzygnięte szczegóły pozostawiono jako blokady, zamiast przyjmować niezatwierdzone reguły.

## 2. Macierz K3.1–K3.21

| Wymaganie | Status | Bieżące dowody i uzasadnienie |
|---|---|---|
| **K3.1** Jeden katalog surowców zarządzany przez główną kuchnię centralną | **ALREADY IMPLEMENTED** | Katalog jest organizacyjny, bez kopii per odbiorca. `RawMaterialAuthorization` i `RawMaterialPermission::requiresPrimaryCatalogContext()` ograniczają mutacje katalogowe do primary; import katalogu dodatkowo sprawdza kontekst primary. |
| **K3.2** Restauracje widzą wyłącznie jawnie udostępnione surowce | **ALREADY IMPLEMENTED** | `RawMaterialLocationSharePolicy` wymaga aktywnego share; katalog, szczegóły, rewizje, dokumenty, importy/eksporty i zasoby handlowe ponownie autoryzują materiał. Potwierdza to test API udziału lokalizacyjnego. |
| **K3.3** Tylko pierwsza kuchnia centralna zarządza katalogiem | **ALREADY IMPLEMENTED** | Trwałe oznaczenie primary i jego ograniczenia są w migracji `000038`; kontekst primary jest sprawdzany przez warstwę autoryzacji RAW. |
| **K3.4** Pozostałe kuchnie centralne widzą wyłącznie udostępnione surowce | **NOT VERIFIED** | Polityka traktuje każdy kontekst nie-primary jako odbiorcę, ale dedykowany test RAW sprawdza restauracje, nie drugą kuchnię centralną. Nie rozszerzano funkcjonalności na podstawie samego wnioskowania ze wspólnej ścieżki. |
| **K3.5** Propagacja zatwierdzonych rewizji bez ponownej akceptacji; szkice niewidoczne odbiorcom | **ALREADY IMPLEMENTED** | Odbiorcy otrzymują bieżącą zatwierdzoną rewizję; historia szkiców i rewizji oczekujących pozostaje po stronie primary. Pokryte testami rewizji i API Stage 2D. |
| **K3.6** Cofnięcie blokuje nowe użycie, nie usuwa historii | **ALREADY IMPLEMENTED** | Test sprawdza odrzucenie kolejnych odczytów i downloadów po revoke, zachowanie share/revision/audytu oraz odmowę bezpośredniego SQL od runtime (`42501`). |
| **K3.7** Zachowanie zatwierdzonych receptur i blokowanie nowych operacji z przyczyną | **BLOCKED BY PRODUCTION** | Brak zatwierdzonego w VELTRA 2.0 kontraktu receptur/operacji zależnych od RAW. Nie dodano endpointów produkcyjnych ani zastępczej domeny. |
| **K3.8** Ponowne udostępnienie usuwa tylko blokadę wynikającą z braku share | **BLOCKED BY PRODUCTION** | Bieżący RAW nie modeluje blokad operacji ani ich przyczyn. Bez właściciela Production nie można odróżnić blokady share od safety/HOLD ani bezpiecznie określić skutku ponownego udostępnienia. |
| **K3.9** Zarządzanie udziałami z poziomu surowca i lokalizacji, także zbiorczo | **BLOCKED BY OWNER DECISION** | Obecne API obsługuje pojedynczy udział z perspektywy surowca (`GET/POST/DELETE /{material}/shares`). Brakuje widoku lokalizacyjnego i operacji zbiorczej. Lokalny projekt wskazuje nierozstrzygnięte transakcyjne i audytowe skutki partial success. |
| **K3.10** Wynik częściowy per cel, lecz brak uprawnienia odrzuca całe żądanie | **BLOCKED BY OWNER DECISION** | Brak kontraktu bulk. Wymaga decyzji właściciela, czy zatwierdzone elementy utrwalać osobno, czy w transakcji zbiorczej, oraz jak audytować odrzucone cele bez ujawniania niedostępnych lokalizacji. |
| **K3.11** Domyślny brak udostępnienia | **ALREADY IMPLEMENTED** | Brak aktywnego share daje odmowę w `canConsume`; test potwierdza pusty wynik API przed nadaniem udziału. |
| **K3.12** Pierwsze udostępnienie dopiero po pierwszym zatwierdzeniu | **ALREADY IMPLEMENTED** | Tworzenie udziału wymaga aktywnego materiału z zatwierdzoną rewizją; test odrzuca materiał oczekujący i dopuszcza odwiązanie od materiału archiwalnego. |
| **K3.13** Archiwizacja zachowuje share, ale zawiesza jego efekt | **ALREADY IMPLEMENTED** | Polityka odbiorcy wymaga aktywnego materiału; archive/restore nie usuwa historycznego share. Testy lifecycle i udziałów pokrywają archiwizację oraz historię. |
| **K3.14** Wyłączenie modułu zachowuje share, a ponowne włączenie respektuje bramki | **ALREADY IMPLEMENTED** | Dostępność RAW jest sprawdzana przy każdym żądaniu; brak stanu lokalizacji oznacza wyłączenie, a `NOT_READY` uniemożliwia aktywację. Konfiguracja modułu nie kasuje udziałów. |
| **K3.15** Zarządzanie udziałami wyłącznie przez ADMINISTRATOR | **ALREADY IMPLEMENTED** | Administracja share sprawdza rolę `ADMINISTRATOR`; test API odrzuca nieuprawnionego aktora. Nie dodano nowej roli ani obejścia SUPER ADMIN. |
| **K3.16** Primary jest trwale oznaczone i niezmienne | **ALREADY IMPLEMENTED** | Migracja `2026_11_07_000038_enforce_immutable_primary_central_kitchen.php` i testy primary potwierdzają trwały znacznik, ograniczenia SQL i fail-closed dla nieustalonej instalacji. |
| **K3.17** Zakaz wyłączenia primary, gdy RAW działa | **ALREADY IMPLEMENTED** | Migracja `2026_11_08_000040_keep_primary_central_kitchen_active.php` oraz `InstallationService::updateLocation()` blokują dezaktywację primary bez względu na stan RAW. To ograniczenie jest silniejsze niż minimum K3.17; nie osłabiano go. |
| **K3.18** Kontrolowane, audytowane zakończenie RAW po analizie zależności | **BLOCKED BY OWNER DECISION** | Brak zatwierdzonego workflow wycofania, stanów wniosku, kontroli zależności i określonych skutków. Nie dodano spekulacyjnych endpointów ani wyjątków. |
| **K3.19** Inicjator i zatwierdzający to różni administratorzy | **BLOCKED BY OWNER DECISION** | Nie istnieje workflow wycofania, do którego można bezpiecznie przypisać rozdział obowiązków i blokadę samozatwierdzenia. |
| **K3.20** Bez drugiego administratora trzeba go ustanowić; bez samozatwierdzenia | **BLOCKED BY OWNER DECISION** | Istnieją zabezpieczenia ostatniego administratora, ale nie zastępują one odrębnego, dwuosobowego procesu wycofania RAW. |
| **K3.21** Kontynuacja partii na zamrożonej rewizji, z nadrzędną blokadą safety | **BLOCKED BY PRODUCTION** | Brakuje trwałej tożsamości partii, momentu startu i zamrożonej rewizji. Snapshot wymaga osobnej decyzji właściciela bezpieczeństwa o źródle blokady, stanie HOLD i warunkach zwolnienia; nie daje automatycznej zgody na kontynuację. |

### Rozstrzygnięcie zakresu

Nie znaleziono bezpiecznego, niezależnego od Production wymagania z brakującą i jednoznacznie zatwierdzoną semantyką, które wymagałoby zmiany aplikacji w 3D. Pozostałe niezależne granice 3A/3B są obecne; K3.4 pozostaje **NOT VERIFIED**, a K3.9–3.10, K3.18–3.20 wymagają decyzji właściciela. Nie dodano testów ani funkcji wyłącznie dla wzrostu pokrycia.

## 3. Zmiany i integracje

- **Kod aplikacji:** bez zmian.
- **Testy trwałe:** bez zmian.
- **Migracje:** brak nowych migracji; nie zmieniono istniejących.
- **Integracje produkcyjne:** brak. Nie stworzono partii, zużycia, endpointu startu, kontynuacji ani zapisu historycznej rewizji.
- **RAW routes:** `backend/routes/api.php` grupuje endpointy pod `auth:sanctum`, aktywną sesją, walidacją tożsamości, kontekstem lokalizacji i `RequireRawMaterialsAvailability`. `ConfiguredModuleAvailability` odrzuca moduł niegotowy lub wyłączony. Testy używają wyłącznie istniejących fixture’ów testowych READY; katalog produkcyjny RAW pozostał `NOT_READY`.
- **Udostępnianie i rewizje:** ponowne użyto istniejących `RawMaterialLocationSharePolicy`, `RawMaterialLocationShareAdministration`, `RawMaterialAuthorization` i resolvera bieżącej zatwierdzonej rewizji. Nie ma kopii katalogu per lokalizacja.
- **Dokumenty i handel:** ścieżki share są obecne w `RawMaterialDocumentService`, `RawMaterialCommercialService`, `RawMaterialCompatibilityAssessmentService` oraz workflow importu/eksportu i downloadu. Granty finansowe pozostają oddzielone; share sam nie nadaje `FOODCOST_VIEW`, `PURCHASE_PRICES_VIEW` ani RAW-owych grantów cenowych.
- **Primary/SUPER ADMIN:** utrzymano istniejący model sześciu ról, trwały primary, ochronę primary przed dezaktywacją i oddzielny status SUPER ADMIN. Nie dodano siódmej roli ani wyjątku.

## 4. Weryfikacja wykonana

Wszystkie testy uruchomiono w osobnym projekcie Compose `veltra-stage2f-implementation3d`, z wewnętrzną siecią `internal=true` i własnymi wolumenami. PostgreSQL `17-alpine`, Redis i ClamAV były usługami tego projektu; kontenery instalacyjne nie były zależnościami ani celem poleceń.

| Weryfikacja | Wynik |
|---|---|
| Świeże migracje do bazy testowej `veltra_testing` | **PASS**, 41/41; ostatnia `2026_11_08_000041_create_raw_material_location_shares`. |
| Tożsamość bazy i role | **PASS**; zapytanie połączone jako `veltra_migrator` wskazało bazę `veltra_testing`, `rolsuper=false` i właściciela tabel `veltra_migrator`. Obie role `veltra_app` i `veltra_migrator` mają `rolsuper=false`, `rolcreatedb=false`, `rolcreaterole=false`, `rolbypassrls=false`. |
| Testy skupione share/API/finanse/NOT_READY/SQL/współbieżność | **PASS**, 9 testów, 143 asercje. |
| Pełna regresja backendu | **PASS**, 284 testy, 2 823 asercje, 316,13 s. |
| Pint | **PASS**, 264 pliki, tryb `--test`. |
| PHP lint (`app`, `tests`, `database`, `routes`, `lang`) | **PASS**, `php -l` dla każdego pliku PHP. |
| `git diff --check` | **PASS**. |
| Upgrade z 041 | **N/D** — nie dodano migracji po 041. |

Dowody SQL i współbieżności:

- Test `test_explicit_location_share_gates_raw_material_access_and_preserves_revocation_history` wykonał bezpośredni `UPDATE` udziału jako runtime i potwierdził odmowę PostgreSQL `42501`; rekord pozostał aktywny do autoryzowanego revoke. Test potwierdził też zachowanie rewizji i audytu oraz odmowę wcześniejszego export downloadu po revoke.
- `RawMaterialPermissionBootstrapTest` łączy się jawnie jako `veltra_app` i weryfikuje ograniczone uprawnienia do tabel operacji uprawnień.
- `test_concurrent_share_creation_and_revocation_are_serialized` uruchamia dwa osobne procesy PHP przeciwko prawdziwemu PostgreSQL, sprawdza oczekiwanie na blokadę w `pg_stat_activity` i potwierdza serializację utworzenia/cofnięcia udziału.
- Testy aktualnej rewizji i jej zatwierdzania przeszły w pełnej regresji. Współbieżność startu partii z revoke/approval jest **NOT EXECUTABLE / BLOCKED**, ponieważ nie istnieje zatwierdzony lifecycle partii.

Pierwsze przebiegi ujawniły braki wyłącznie w efemerycznej konfiguracji testowej (syntetyczny klucz podpisu, domeny Sanctum, Redis/ClamAV i zmienne połączenia). Uzupełniono je w tymczasowym runnerze; nie zmieniano aplikacji ani manifestów zależności. Końcowy pełny przebieg zakończył się wynikiem podanym powyżej.

## 5. Blokady, ryzyka i decyzje właścicieli

1. **Production:** właściciel domeny Production musi zatwierdzić kontrakt startu, identyfikator partii, dokładny snapshot `organization_id`/`location_id`/`material_id`/`approved_revision_id`, atomowość z revoke i approval, kontynuację po zwykłym revoke i audyt.
2. **Bezpieczeństwo żywności:** właściciel bezpieczeństwa musi wskazać źródło safety block, jego wpływ na trwającą partię, HOLD i warunki zwolnienia. Do tego czasu system ma pozostać fail-closed; nie dodano recall, quarantine, HACCP release ani override.
3. **Zarządzanie zbiorcze:** właściciel RAW musi rozstrzygnąć transakcje i audyt częściowego wyniku dla K3.9–3.10 oraz kontrakt obu kierunków API.
4. **Formalne wycofanie:** właściciel RAW/organizacji musi określić workflow, skutki, kontrolę zależności i audyt, po czym zatwierdzić dwuosobową autoryzację K3.18–3.20. SUPER ADMIN nie może omijać reguły dwóch odrębnych administratorów.
5. **K3.4:** dodać dedykowaną weryfikację API dla drugiej kuchni centralnej przed uznaniem wymogu za zweryfikowany.

## 6. Status końcowy i bezpieczeństwo środowiska

- **RAW_MATERIALS = NOT_READY**; nie włączono ani nie wdrożono modułu.
- **Implementation 3C = PARTIAL / BLOCKED**; brak Production pozostaje jawną zależnością, nie zastąpiono jej tabelami RAW.
- **Brak commit/push/deployment** i brak uruchamiania migracji na instalacji.
- Instalacyjna baza, kontenery, wolumeny i konfiguracja nie były modyfikowane. Wszystkie migracje i testy dotyczyły wyłącznie odizolowanego projektu testowego.
