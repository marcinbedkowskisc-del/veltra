# VELTRA 2.0 — Stage 3E-B.2

## 1. Status: PARTIAL

Wykonano lokalne kontrakty bezpiecznej edycji, kontroli wersji, kompletności, dostępnych akcji oraz ograniczonej historii i metadanych aktualizacji.

**Fizyczne usuwanie szkiców: BLOCKED** — zgodnie z nakazem zatrzymania tej podczęści przy strukturalnej przeszkodzie. Nie osłabiono ochrony historii i nie utworzono pozornie działającego DELETE endpointu. Szczegóły w sekcji 6.

**RAW_MATERIALS pozostaje NOT_READY.** Nie wykonano wdrożenia, aktywacji, migracji instalacji, commit/push, frontendu, B.3, Production ani formalnego retirement. Zachowano zastany working tree B.1 i migrację 042.

## 2. Zgody, źródła i zgodność

Przed implementacją przedstawiono zbiorczy plan operacji i uzyskano potwierdzenie zakresu B.2. Wyjaśniono, że potwierdzenie nie zastępuje natywnych zabezpieczeń środowiska; nie wyłączano zabezpieczeń ani nie używano trybu obchodzenia zgód.

Odczytano:

- [100 zatwierdzonych decyzji UX](https://docs.google.com/document/d/1NECl8Hqq_auHzST6kJ-rovgSuMm30JhbxFWpx7ZIamM/edit) i [specyfikację uporządkowaną](https://docs.google.com/document/d/1cQ_-fPrz5jXzrJvaaAxiXQGbjHqBvrMBOESbYKa0KQk/edit), przez eksport tekstowy.
- [Zaakceptowany raport B.1](https://drive.google.com/file/d/1kYq4Zj_Q6XFmUxGukPuAeqnS5kDfvuNd/view) i [raport kompatybilności](https://drive.google.com/file/d/1tUfpPa_zYgSe1j3C1UVe04pEFSDhJDfN/view), przez pobranie z Drive.
- Bieżący kod RBAC, dostępności modułów 1E, ADR-001, primary central kitchen, SUPER ADMIN, RAW 3A–3D.1, B.1, dokumentów, podpisanych mutacji oraz audytu.

Źródła były dostępne; nie zastępowano brakujących treści domysłami. Starsze zapisy o częściowym bulk sharing i drugim administratorze nie zostały przywrócone. Formalnego retirement nie implementowano.

## 3. Kontrakty API i współbieżność

### Nowe odczyty

Wszystkie poniższe trasy pozostają w istniejącej grupie RAW, z sesją SPA, tożsamością, kontekstem lokalizacji, limitem żądań i bramką dostępności modułu.

| Trasa | Kontrakt |
|---|---|
| `GET /api/v1/raw-materials/{material}/editor` | Bieżąca rewizja working → pending → approved, ilości B.1, deklaracja alergenów, warianty przechowywania, nieprzezroczyste `version` |
| `GET /api/v1/raw-materials/{material}/completion` | Stan, `revision_id`, dozwolone `version`, dwie liczby problemów, ich szczegóły i autoryzowane akcje |
| `POST /api/v1/raw-materials/{material}/validation/{section?}` | Walidacja niezapisanych danych bez mutacji; sekcje `basic` (domyślna), `allergens`, `storage` |
| `GET /api/v1/raw-materials/{material}/history` | Bezpieczna projekcja istniejących zdarzeń, z paginacją |

Editor wymaga VIEW oraz istniejącego prawa wglądu w szkice: EDIT lub AUDIT_VIEW, w primary. Nie rozszerzono dostępu do szkiców samą rolą administratora, uprawnieniem SUBMIT, ani udostępnieniem bieżącej zatwierdzonej rewizji odbiorcy.

Odpowiedź edytora pomija `attested_by` i `attested_at` w deklaracji alergenów. Formularz nie potrzebuje tych metadanych audytowych; sam EDIT nie uprawnia do ujawnienia autora atestu.

Kartoteka zachowuje dotychczasową zatwierdzoną rewizję. Nie zwraca tokena edycji, gdy prezentuje starszą zatwierdzoną rewizję zamiast aktualnej working/pending; edytor ma pobierać spójny zestaw danych i token przez `/editor`. Odbiorcy nie otrzymują tokena edycji.

### Obowiązkowy warunek zapisu

Zmodyfikowano istniejące trasy:

- `PATCH /{material}/draft`;
- `PUT /{material}/draft/allergen-declaration`;
- `PUT /{material}/draft/storage-variants`;
- `POST /{material}/submit`, `/approve`, `/return`, `/block`, `/unblock`, `/archive`, `/restore`.

Wymagają `If-Match: <odczytane version>`. Akceptowany jest UUID bez cudzysłowów oraz postać `"uuid"`. Odpowiedzi: **428** przy braku, **422** przy błędnym formacie, **409 RAW_MATERIAL_VERSION_CONFLICT** przy starej wersji. Konflikt nie zwraca aktualnego tokena, rewizji, szkicu, kwot ani danych lokalizacji. Komunikat wskazuje wczytanie aktualnej wersji z ostrzeżeniem o utracie lokalnych zmian. Nie dodano merge ani automatycznego nadpisywania.

Usługi domenowe wymagają analogicznego `expectedVersion`; pominięcie argumentu oznacza odmowę, nie zgodnościowy bypass. Istniejące testy i worker zatwierdzania jawnie odczytują wersję przed zapisem.

**Granica transakcji:** istniejący podpisany scope → transakcja → blokada organizacji → ponowna autoryzacja → blokada materiału → sprawdzenie wersji → istniejące warunki lifecycle → zapis rewizji/lifecycle i audytu → commit. Stara wersja nie tworzy rewizji ani zdarzenia zaakceptowanej zmiany.

Migracja **043** dodaje `edit_version uuid` i bazodanowe generowanie nowego UUID przy każdym UPDATE materiału. To zapobiega również problemowi ABA, np. ACTIVE → BLOCKED → ACTIVE. Token nie jest wyliczany wyłącznie z identyfikatora rewizji ani czasu.

Generator wersji wykonuje się po dotychczasowych guardach żądanych zmian. Zachowano rygor podpisanego odblokowania i dowód audytowy tej samej transakcji; nie dopisano wyjątku osłabiającego porównywanie danych w guardzie UNBLOCK.

Tworzenie materiału i import nowych rekordów nie edytują istniejącej rewizji. Rezerwacje SKU, własne preconditions zdarzeń cen i all-or-nothing sharing zachowują kontrakty B.1/3D.1. Powiązania dokumentów pozostają append-only i wskazują jawną rewizję; nie nadpisują treści rewizji RAW.

## 4. Kompletność i akcje

Raport zawiera:

`lifecycle_state`, `revision_id`, `version`, `error_count`, `errors`, `missing_requirement_count`, `missing_requirements`, `eligible_for_next_action`, `available_actions`.

Każdy problem ma stabilne `code`, `field`, `section` i polskie `message`. Nie ma procentu ani punktacji kompletności.

- Błędy zapisu są oddzielone od braków zatwierdzania. Niezapisane dane podstawowe używają tych samych reguł requestu i walidacji domenowej co zapis; walidacja alergenów i przechowywania korzysta z istniejących reguł oraz callbacków `after`.
- Sekcje mają odpowiednio body danych podstawowych, deklaracji alergenów albo `{variants: [...]}` i wymagają właściwego grantu edycji.
- Preview nie zapisuje danych i nie reklamuje akcji mutacji niezapisanej rewizji. Stan nieedytowalny jest raportowany jako błąd zapisu.
- Zapisany niekompletny DRAFT jest poprawnym stanem. Istniejące przekazanie do weryfikacji może nastąpić przed uzupełnieniem alergenów; samo zatwierdzenie nadal wymaga prawidłowej deklaracji/atestu.
- Mechanizm raportowania warunków zatwierdzania i faktyczne zatwierdzanie mają wspólne sprawdzenie deklaracji oraz hasha rewizji.
- Nie dodano obowiązkowej ceny, dokumentów, wariantów przechowywania ani ilości, których dotychczasowe zatwierdzanie nie wymagało.
- Akcje wynikają ze stanu, primary, skutecznych grantów z istniejącą delegacją, bramek modułu oraz uprawnień potrzebnych do zachowania już obecnych danych rewizji. Odblokowanie wymaga także APPROVE.
- `eligible_for_next_action` dotyczy dostępnego przejścia lifecycle, nie samego uprawnienia edycji. Odczyt nie wykonuje przejścia; każda mutacja niezależnie ponownie sprawdza własne warunki.
- Odbiorca otrzymuje raport wyłącznie dla aktualnej zatwierdzonej udostępnionej rewizji, bez working/pending, wersji edycji i akcji centralnego katalogu.

Naprawiono ściśle powiązaną ścieżkę przywracania: nowa rewizja po restore zachowuje nominalną i użytkową ilość B.1 zamiast je gubić.

## 5. Historia i ostatnia aktualizacja

`/history` wymaga VIEW + AUDIT_VIEW. `page>=1`, `per_page=25|50|100|200`, domyślnie 50. Odpowiedź ma `data` i metadane zakresu/stron. Sortowanie: `occurred_at DESC`, następnie `id DESC`.

Nie utworzono nowego silnika audytu. Projekcja czyta istniejące append-only źródła:

- zdarzenia utworzenia, rewizji, przekazania, zwrotu, zatwierdzenia, blokady, odblokowania, archiwizacji i przywracania RAW;
- w primary z AUDIT_VIEW: zdarzenia jawnych udostępnień i powiązania dokumentów RAW, bez ujawniania dokumentów powiązanych z rewizją produktu dostawcy;
- w primary z AUDIT_VIEW **i odrębnym REFERENCE_PRICE_VIEW**: zdarzenia ręcznych cen oraz zmian cen utworzonych przez import CSV. Nie ujawnia się kwot ani źródeł ofert/zakupów.

Pojedynczy wpis zawiera UUID, kod, czas i kanoniczny UUID/imię/nazwisko aktora. Nie zawiera surowych metadanych, e-maila, request body, powodów bezpieczeństwa, kwot, plików, kluczy storage, hashy plików ani tokenów.

Odbiorca z AUDIT_VIEW widzi jedynie zdarzenie zatwierdzenia **aktualnej** udostępnionej rewizji. Historia szkiców, cudze lokalizacje, ceny i dokumenty nie rozszerzają tego zakresu. Brak lub odebranie udostępnienia daje bezpieczne 404.

Kartoteka ma `last_update: {occurred_at, actor}` oraz dozwolone `history_url`. Dane pochodzą z tej samej bezpiecznej projekcji. Bez AUDIT_VIEW aktor i link są ukryte; primary z EDIT może otrzymać czas zmiany widocznej rewizji. Zdarzenie niezmienionej ceny jest próbą audytową, nie ostatnią faktyczną aktualizacją.

To minimalna historia materiałowa, nie eksport wszystkich wewnętrznych logów komercyjnych i bezpieczeństwa. Odrębne historie ofert, zakupów, kursów i wewnętrzne wyniki skanowania pozostają w dotychczasowych chronionych mechanizmach.

## 6. Dokładny bloker usuwania szkiców

W [migracji 011](backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php):

1. `raw_materials_preserve_master()` bezwarunkowo odrzuca DELETE mastera (początek funkcji, linia 303).
2. `raw_materials_revision_immutable()` i trigger `raw_material_revisions_immutable` odrzucają UPDATE/DELETE każdej rewizji, nie tylko zatwierdzonej.
3. `raw_materials_child_immutable()` odrzuca usuwanie aliasów i jednostek rewizji.
4. Rewizja ma RESTRICT FK do materiału; każdemu poprawnemu DRAFT towarzyszy rewizja robocza, a dzieci rewizji również mają chronione powiązania.

Dodatkowe chronione zależności obejmują historię zatwierdzania, udostępnienia, dokumenty, relacje komercyjne i importy; B.1 zachowuje także trwałe assignmenty i historię rezerwacji SKU. Samo sprawdzenie approved_revision_id IS NULL nie daje prawa fizycznego usunięcia.

**Zatrzymano tę podczęść zgodnie z sekcją 7 zlecenia.** Nie usunięto guardów, FK, audytu, assignmentów ani rezerwacji. Nie dodano DELETE endpointu, nowego grantu ani dostępnej akcji delete. API nie udaje sukcesu.

Testy potwierdzają utrzymanie odmowy także pod prawidłowym podpisanym scope dla szkicu, rewizji oraz materiałów wcześniej zatwierdzonych i zarchiwizowanych; dowody i numery pozostają. **Nie wykonano testu udanego usunięcia, wyścigu zależność/usunięcie ani audytu udanego usunięcia**, ponieważ autoryzowany wariant bez osłabienia obecnego schematu nie istnieje. Nie oznaczono tych scenariuszy jako PASS.

## 7. Dokładne pliki zmienione w B.2

### Istniejące

1. [backend/README.md](backend/README.md).
2. [RawMaterialService.php](backend/app/Domain/RawMaterials/RawMaterialService.php).
3. [RawMaterialController.php](backend/app/Http/Controllers/Api/V1/RawMaterialController.php).
4. [api.php](backend/routes/api.php).
5. [AuthorizationTestCase.php](backend/tests/AuthorizationTestCase.php).
6. [RawMaterialsTest.php](backend/tests/Feature/RawMaterialsTest.php).
7. [RawMaterialStage2dApiTest.php](backend/tests/Feature/RawMaterialStage2dApiTest.php).
8. [RawMaterialRevisionDataModelTest.php](backend/tests/Feature/RawMaterialRevisionDataModelTest.php).
9. [RawMaterialDocumentStorageTest.php](backend/tests/Feature/RawMaterialDocumentStorageTest.php).
10. [RawMaterialCompatibilityAssessmentTest.php](backend/tests/Feature/RawMaterialCompatibilityAssessmentTest.php).
11. [RawCatalogStage3eB1Tests.php](backend/tests/Concerns/RawCatalogStage3eB1Tests.php) — jawne preconditions przy istniejących mutacjach, bez usuwania testów B.1.
12. [concurrent_raw_material_revision.php](backend/tests/Support/concurrent_raw_material_revision.php) — worker obserwuje wersję przed zatwierdzeniem.

### Nowe

13. [RawMaterialVersion.php](backend/app/Domain/RawMaterials/RawMaterialVersion.php).
14. [RawMaterialHistoryService.php](backend/app/Domain/RawMaterials/RawMaterialHistoryService.php).
15. [RawMaterialHistoryRequest.php](backend/app/Http/Requests/RawMaterialHistoryRequest.php).
16. [Migracja 043](backend/database/migrations/2026_11_09_000043_add_raw_material_edit_versions.php) — jedyna migracja B.2, forward-only.
17. [RawEditingStage3eB2Tests.php](backend/tests/Concerns/RawEditingStage3eB2Tests.php) — 16 nowych testów.
18. [concurrent_raw_edit.php](backend/tests/Support/concurrent_raw_edit.php).
19. [compose.stage3e-b2-test.yaml](compose.stage3e-b2-test.yaml).
20. [Niniejszy raport](VELTRA_STAGE_3E_B2_RAW_EDIT_COMPLETION_AUDIT_REPORT.md).

Pozostałe zastane zmiany B.1, migracja 042, raport B.1 oraz raport kompatybilności nie zostały cofnięte ani nadpisane.

## 8. Testy i środowisko

Dedykowany projekt `veltra-stage3e-b2-test`, PostgreSQL **17.11**, baza `veltra_testing`, prywatna sieć `internal=true`, brak opublikowanych portów bazy, własne wolumeny storage/cache oraz dane PostgreSQL/Redis w tmpfs. Compose dziedziczy wyłącznie narzędziową konfigurację B.1; nie korzysta z kontenerów ani danych instalacji.

Zweryfikowano role `veltra_app` i `veltra_migrator`: bez SUPERUSER, CREATEDB i CREATEROLE. Runtime nie ma CREATE w public ani TRUNCATE organizations; właścicielem tabel jest migrator. Workery wieloprocesowe sprawdzają nazwę izolowanej bazy i działają jako ograniczony runtime. Readiness jest zmieniane wyłącznie przez istniejące fixtures testowe.

Migracje 001–043 wykonano na nowej izolowanej bazie; po poprawce kolejności generatora wersji odtworzono wyłącznie zasoby B.2 i migracje od zera.

| Sprawdzenie | Wynik |
|---|---|
| B.1 + 16 nowych testów B.2 + wcześniejszy wyścig zatwierdzeń | **33 PASS, 406 asercji**, 62.01 s |
| Końcowa pełna regresja backendu | **322 PASS, 3271 asercji**, 361.23 s; 0 błędów, 0 porażek, 0 pominiętych |
| Laravel Pint, cały backend | **PASS, 278 plików** |
| `git diff --check` | **PASS** |

Nowe testy obejmują dwóch aktorów, stale edit bez nadpisania i audytu zmiany, HTTP 428/422/409, wszystkie 10 ścieżek rewizji/lifecycle, błędy kontra braki, walidację niezapisanych alergenów/przechowywania, NOT_READY, ABA, zachowanie ilości po restore, wieloprocesowe revise/revise, revise/submit, revise/archive i approve/return, historię/aktora/czas, deterministyczną paginację, cenę ręczną i importowaną, izolację odbiorcy, brak przecieków oraz zachowanie blokad DELETE.

Pierwsze przebiegi ujawniły błędy fixture i odczytu struktury audytu cen, poprawione przed końcowymi testami. Test lifecycle ujawnił konflikt wczesnego generowania wersji z rygorystycznym guardem UNBLOCK; poprawiono kolejność triggera bez zmiany guardów. Pierwsza pełna regresja miała 318 PASS i 1 FAIL: stary test równoległych zatwierdzeń oczekiwał dawnego APPROVAL_INVALID zamiast nowego VERSION_CONFLICT. Zaktualizowano oczekiwanie zgodnie z nowym kontraktem. Po końcowym ograniczeniu metadanych atestu w edytorze ponowiono zarówno testy celowane, jak i pełną regresję; powyższe wyniki dotyczą finalnych źródeł.

Wykonywane komendy:

```sh
TEST_ENV=/home/veltra/.copilot/session-state/5dcc2b87-76d2-4e26-9865-f279b40e7535/files/stage3e-b1-test.env
docker compose --env-file "$TEST_ENV" -f compose.stage3e-b2-test.yaml exec -T runner sh -c 'DB_USERNAME="$APP_DB_MIGRATION_USERNAME" DB_PASSWORD="$APP_DB_MIGRATION_PASSWORD" php artisan migrate --force'
docker compose --env-file "$TEST_ENV" -f compose.stage3e-b2-test.yaml exec -T runner php artisan test --compact --do-not-cache-result --filter='stage3e_b2|stage3e_b1|revision_approval_and_recipient' --log-junit /tmp/stage3e-b2-focused.xml
docker compose --env-file "$TEST_ENV" -f compose.stage3e-b2-test.yaml exec -T runner php artisan test --compact --do-not-cache-result --log-junit /tmp/stage3e-b2-full.xml
docker compose --env-file "$TEST_ENV" -f compose.stage3e-b2-test.yaml exec -T --user "$(id -u):$(id -g)" runner vendor/bin/pint --test
git diff --check
```

Plik env zawiera wyłącznie wcześniej utworzone syntetyczne dane testowe i znajduje się poza repozytorium. Nie kopiowano sekretów instalacji.

Po zakończeniu testów zachowano logi i oba raporty JUnit w katalogu sesji, następnie wykonano `down --volumes` wyłącznie dla `compose.stage3e-b2-test.yaml`. Usunięto cztery kontenery projektu B.2, dwa wolumeny storage/cache i sieć isolated. Filtry etykiety projektu potwierdziły brak pozostawionych kontenerów, wolumenów i sieci B.2. Nie zmieniano zasobów instalacji.

## 9. Ograniczenia i dostarczenie

- Status całości pozostaje **PARTIAL** z powodu blokera fizycznego usuwania. Nie jest to deklaracja pełnej gotowości RAW ani osobny pełny audyt bezpieczeństwa.
- B.3, frontend, nowe workflow, Production, frozen revisions, formal retirement, wdrożenie i aktywacja pozostają niewykonane.
- Testy i cleanup dedykowanego środowiska B.2 zakończono. Pozostawiono lokalny kod, dokumentację, raport oraz dowody testowe; nie uruchomiono kolejnego etapu.

Raport źródłowy:

`/home/veltra/projekty/veltra/VELTRA_STAGE_3E_B2_RAW_EDIT_COMPLETION_AUDIT_REPORT.md`

Rozpoznano WSL `Ubuntu`; odpowiadająca ścieżka UNC do tego samego pliku, bez kopiowania danych instalacji:

`\\wsl.localhost\Ubuntu\home\veltra\projekty\veltra\VELTRA_STAGE_3E_B2_RAW_EDIT_COMPLETION_AUDIT_REPORT.md`

Folder docelowy: [RAPORTY_COPILOTA](https://drive.google.com/drive/folders/1I2r4uTKcpl69E3dXOru18VOLi74N613t). Ścieżkę UNC potwierdzono przez `wslpath -w`. Jedyna próba wskazania rzeczywistego pliku przez przeglądarkę Windows została odrzucona: `ERR_UNC_HOST_NOT_ALLOWED: UNC host 'wsl.localhost' access is not allowed`. Wyczyszczono wybór pliku; nie ponawiano błędnej ścieżki i nie obchodzono ograniczenia dostępu.

**Upload niewykonany.** Nie ma zweryfikowanej kopii tego raportu B.2 na Drive. Kompletny raport pozostaje pod powyższą bezwzględną ścieżką Linux; nie utworzono redundantnego raportu ani kopii Windows.

**STOP po B.2.**
