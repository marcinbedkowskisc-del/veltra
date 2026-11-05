# VELTRA 2.0 — Stage 2D.5 — Raport integracji cyklu życia i regresji

**Data weryfikacji:** 2026-11-05  
**Tryb:** DEVELOPMENT ONLY  
**Werdykt:** integracja zakresu Stage 2D.1–2D.4B zweryfikowana; bez potwierdzonych braków wymagających zmian kodu aplikacji  
**RAW_MATERIALS:** `NOT_READY`; bramka gotowości bez zmian  
**VSEF:** bez zmian  
**Stage 2C:** status bez zmian; 17 zastrzeżonych testów nieuruchomionych

## Zakres i ustalenia

Przejrzano cykl rewizji surowców, dane alergenowe i wersje słownika, warianty przechowywania, prywatne wersje dokumentów, append-only wyniki ClamAV oraz oceny zgodności dostawców.

| Obszar | Wynik weryfikacji |
|---|---|
| Tworzenie i edycja szkicu | Zmiany tworzą nową rewizję roboczą zamiast modyfikować poprzednią; edycja rewizji oczekującej na akceptację jest blokowana. |
| Zgłoszenie i zatwierdzenie | Zgłoszenie przenosi rewizję roboczą do oczekującej. Zatwierdzenie wymaga jawnego stanu `DECLARED` oraz zgodności hasha z kanonicznym odczytem bazy. `UNKNOWN` nie przechodzi zatwierdzenia. |
| Alergeny i słownik | `UNKNOWN`, `DECLARED` i `NO_KNOWN_ALLERGENS` są rozróżniane. `NO_KNOWN_ALLERGENS` wymaga jawnego atestu z opublikowaną wersją słownika; lista deklarowana wymaga co najmniej jednego wpisu. `DIRECT` i `CROSS_CONTACT_POTENTIAL` zachowują odrębne wartości. |
| Warianty przechowywania | Warianty są przypięte do rewizji; PostgreSQL egzekwuje spójność zakresów temperatury i wilgotności, unikalność kodu oraz najwyżej jeden wariant domyślny. |
| Dokumenty i skany | Wersje i powiązania dokumentów są niezmienne. Wyniki skanów są append-only i wiążą dokładny SHA-256; tylko najnowszy wynik `CLEAN` dla tej wersji może być dowodem. `PENDING`, `INFECTED` i `ERROR` pozostawiają dowód zablokowany. |
| Oceny zgodności | Decyzje `COMPATIBLE`/`INCOMPATIBLE` wymagają jawnego uprawnienia, dokładnych rewizji, atestu z opublikowanego słownika i dowodu `CLEAN`. Historia oceny, audyt i idempotencja są zapisywane atomowo. |
| Unieważnianie historycznych ocen | Zmiana rewizji produktu lub surowca, robocza/oczekująca rewizja surowca albo utrata aktualnego wyniku `CLEAN` powoduje bieżący status `PENDING`; starsze oceny pozostają zachowane jako historyczne. |
| Hash v1/v2 | Historyczne rewizje `content_hash_version=1` są nadal weryfikowane pierwotnym algorytmem. Hash v2 obejmuje deklarację alergenową, atest, warianty przechowywania i dane podstawowe; testy potwierdzają jego odtworzenie i zachowanie danych przy tworzeniu kolejnej rewizji. |
| Izolacja organizacji | Zapytania domenowe są ograniczone do organizacji aktora, a klucze obce i triggery egzekwują zgodność zakresu organizacyjnego. Testy izolacji przeszły. |
| Audyt biznesowy | Mutacje rewizji i ocen oraz ich audyt są transakcyjne. Wstrzyknięte błędy zapisu audytu wycofują także zmianę domenową i rekord idempotencji. |

Dodano test integracyjny sprawdzający pełny przebieg: utworzenie oceny, utworzenie i zatwierdzenie nowej rewizji z inną deklaracją alergenową, utworzenie rewizji produktu wiążącej nową rewizję surowca oraz pozostawienie starej oceny jako historycznej przy bieżącym statusie `PENDING`.

Nie znaleziono brakującej logiki aplikacyjnej wymagającej dalszej integracji w zatwierdzonym zakresie. Nie dodano migracji ani nowych modułów.

## Alergeny — jawne znaczenie braku danych

Brak atestu albo stan `UNKNOWN` nie jest mapowany na `NO_KNOWN_ALLERGENS`. Zatwierdzenie rewizji z `UNKNOWN` kończy się walidacyjnym błędem `RAW_MATERIAL_ALLERGEN_DECLARATION_REQUIRED`. Jawny brak znanych alergenów jest osobnym atestem przypiętym do opublikowanej wersji słownika, a ocena zgodności nie ma kompletnego kontekstu, dopóki nie istnieje dopuszczony atest dla dokładnej bieżącej rewizji.

W konsekwencji testy usług domenowych wykonywane z gotowym katalogiem modułów w fixture nie oznaczają włączenia `RAW_MATERIALS` w normalnym środowisku. Test HTTP potwierdza odpowiedź **503** przy bramce niegotowego modułu. Nie raportuje się udanej biznesowej operacji HTTP w konfiguracji `NOT_READY`.

## Wyniki testów

Testy wykonano na odizolowanym środowisku z disposable PostgreSQL 17, Redis 7.4 i ClamAV:

- sieć Docker ma `internal=true` i zawiera wyłącznie dedykowane kontenery testowe;
- PostgreSQL i Redis korzystają z `tmpfs`; nie publikują portów ani nie używają trwałych wolumenów danych;
- runner nie ma mountów; konfiguracja testów wskazuje bazę `veltra_testing` i rozdziela role `veltra_app` oraz `veltra_migrator`;
- ClamAV jest dostępny wyłącznie w tej izolowanej sieci;
- wszystkie 32 migracje, w tym 032, są zastosowane w disposable bazie. Kontenery aplikacyjne, ich bazy i wolumeny nie były modyfikowane ani restartowane.

| Weryfikacja | Wynik |
|---|---:|
| Pełna lista testów przed wykluczeniem | 216 |
| Zastrzeżone testy Stage 2C wyłączone | dokładnie 17 |
| Dozwolona pełna regresja | **199 testów, 1624 asercje — PASS** |
| PHPUnit failures/errors w końcowym przebiegu | **0** |
| Pominięte testy w końcowym przebiegu | **0** |
| Rozszerzony test Stage 2D.4B | **6 testów, 71 asercji — PASS** |
| Pint `--test` i PHP lint zmienionego testu | **PASS** |
| `git diff --check` | **PASS** |

17 wykluczeń składały się z 15 testów `RawMaterialPermissionBootstrapTest` oraz dokładnie:

1. `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available`
2. `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql`

Listę sprawdzono przed uruchomieniem: 216 wszystkich testów minus te 17 daje 199 uruchomionych. Nie uruchomiono ani nie rekonstruowano zastrzeżonych scenariuszy.

### Nieudane przebiegi pośrednie

Podczas rozwijania nowego testu integracyjnego dwa wczesne uruchomienia pojedynczego przypadku zakończyły się po jednym niepowodzeniu każde: asercje fixture odwołały się do nieistniejącego klucza `data` w lokalnej zmiennej historii. Poprawiono wyłącznie odwołania testowe, a rozszerzony test przeszedł następnie w całości (**6/71**). Nie wykryto błędu aplikacji. Końcowy pełny przebieg zakończył się wynikiem **199/1624**, bez niepowodzeń, błędów PHPUnit ani pominięć.

Log testowy zawiera oczekiwane wpisy dla wymuszonych niedostępności skanera oraz negatywnych żądań kończących się „nie znaleziono zasobu”. Są to zamierzone scenariusze testowe; nie odpowiadają błędom PHPUnit. Nie stwierdzono nieoczekiwanych awarii usług lub migracji.

## Granice zakresu i pozostałe wymagania

Nie oznaczono jako ukończonych ani nie zaimplementowano wymagań odłożonych na przyszłość:

- jednego międzynarodowego katalogu alergenów;
- listy krytycznych alergenów na poziomie całego zakładu;
- automatycznego propagowania alergenów do etykiet;
- zatwierdzonego kontraktu CSV / Stage 2E.

Nie zmieniono `RAW_MATERIALS` ani `VSEF`, nie rozpoczęto Stage 2E, nie wdrożono aplikacji i nie zmieniono statusu Stage 2C. Nie wykonano commitów ani pushy.

## Pliki zmienione w Stage 2D.5

- `backend/tests/Feature/RawMaterialCompatibilityAssessmentTest.php` — rozszerzenie testu o zatwierdzenie nowej rewizji surowca i ponowne powiązanie rewizji dostawcy.
- `VELTRA_STAGE_2D5_LIFECYCLE_INTEGRATION_REPORT.md` — niniejszy raport.

**Wniosek:** zaimplementowane kontrakty Stage 2D.1–2D.4B przechodzą dozwoloną integracyjną regresję. W tym zakresie nie potwierdzono braków wymagających zmian kodu aplikacji ani pozostałych defektów.
