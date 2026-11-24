# Stage 6A.1 — zgodność frontend/backend/queue

## Wynik: BLOCKED — brak zatwierdzonej rewizji z poprawkami

Rewizja bazowa `3006659240b3a6bdba5b6c0b7b2583b99d21fbe6` (`main`) zawiera 61 migracji oraz parser obsługujący rozszerzone kody jednostek RAW, w przeciwieństwie do obecnego frontendu Stage. Nie jest jednak zatwierdzonym kandydatem obejmującym wszystkie poprawki: wykryto błędy kontraktu i poprawiono je lokalnie, bez commita. Testowany zestaw to **czyste źródła tej rewizji plus osiem wskazanych niżej zmodyfikowanych plików**, a nie czysty commit. Nie przygotowano obrazów wydania ani nie wyznaczono nowej zatwierdzonej rewizji.

Nie wykonano migracji, zapisów do bazy, wdrożenia ani zmian kontenerów Stage, wolumenów, uprawnień lub aktywacji. Nie wykonano commita ani pusha. Wcześniejsze zmiany hardeningu PostgreSQL, Compose, README i formatowania PHP pozostawiono bez zmian i nie włączono do testowanej kopii źródeł.

## Kontrakt RAW

- Migracja `2026_11_17_000051_create_raw_material_unit_dictionary.php` tworzy słownik z `mg`, `g`, `kg`, `ml`, `l`, `pc` i dopuszcza inne kody według reguły `^[a-z][a-z0-9_]{0,23}$`. Backend sprawdza dodatkowo istnienie i możliwość użycia definicji, wymiar oraz typ przeliczenia. Dla ceny `NOMINAL_UNIT` dopuszcza jednostkę rewizji `BASE` albo `METRIC` w tym samym wymiarze, a nie `MATERIAL_SPECIFIC`. `PACKAGE` nie przyjmuje jednostki ceny.
- Parser frontendu z HEAD już akceptuje kody słownikowe zamiast starej sztywnej listy. Testy po poprawkach obejmują wszystkie sześć kodów początkowych, `mg_custom`, kod 24-znakowy, brak kodu w rewizji, błędny format, niezgodny wymiar i `MATERIAL_SPECIFIC`. Sprawdzają też poprawne `PACKAGE` oraz odrzucenie `PACKAGE` z jednostką. Nie dowodzi to wyczerpania wszystkich stanów bazy.
- Backend może zwrócić nieopublikowaną cenę rewizji `LEGACY_BASE_UNIT` z jednostką bazową. HEAD odrzucał tę prawidłową odpowiedź. Poprawiony frontend pokazuje ją jako `NOMINAL_UNIT` o tej samej kwocie i jednostce, po sprawdzeniu zgodności z jednostką bazową i wymogów rewizji. Niezmieniona cena nie jest wysyłana ponownie przy edycji innych danych. Wysłanie zmienionej ceny używa dopuszczalnego wejściowego `NOMINAL_UNIT`; testy nie dowodzą zachowania każdego historycznego rekordu.
- Walidacja formularza sprawdza teraz obecność jednostek bazowej, ceny i przeliczeń w przekazanym słowniku, również gdy jest pusty. Podgląd nie jest wywoływany z błędną jednostką bazową. Formularz tworzenia czeka na słownik; jeśli domyślna jednostka ceny jest archiwalna lub nieobecna, wybiera aktywną jednostkę bazową. Automatyczna korekta nie powoduje fałszywego ostrzeżenia o niezapisanych zmianach. Uprawnienie do zachowania archiwalnej jednostki w edycji nadal rozstrzyga backend.
- **Ograniczenia:** formularz nie udostępnia niezależnego wyboru dowolnej jednostki ceny: przy tworzeniu wybiera ją automatycznie zależnie od jednostki bazowej. Testy parsera nie oznaczają więc, że użytkownik może wprowadzić każdą kombinację dozwoloną przez API. Ponadto `pricingInputForEditor` może pobrać ostatnie zdarzenie ceny z innej rewizji, podczas gdy parser wymaga, by jednostka ceny była w jednostkach bieżącej rewizji. Potencjalny przypadek usunięcia takiej jednostki z kolejnej rewizji wymaga testu integracyjnego na osobno autoryzowanej bazie testowej i rozstrzygnięcia kontraktu; nie uznano go za potwierdzony stan Stage ani nie osłabiono kontroli parsera. Pełna zgodność end-to-end nie została wykazana.

## Zmiany źródłowe

- `frontend/src/rawMaterials/api.ts`: poprawne przyjęcie historycznej ceny bazowej i odrzucenie jednostki przy cenie opakowania.
- `frontend/src/rawMaterials/create.ts`, `RawMaterialPricingFields.tsx`, `RawMaterialCreateForm.tsx`: kontrola rzeczywistego słownika, blokada wysłania podczas ładowania, bezpieczny dobór aktywnej jednostki ceny i zachowanie stanu niezapisanych zmian.
- `frontend/src/rawMaterials/api.test.ts`, `create.test.ts`, `RawMaterialCreateForm.test.tsx`, `RawMaterialEditForm.test.tsx`: regresje kontraktu, ładowania słownika, jednostki archiwalnej i niezmienionej ceny. Istniejące testy formularza oczekują teraz na załadowanie słownika.

Przed poprawkami testy reprodukowały odrzucenie `LEGACY_BASE_UNIT`, akceptację nieznanych jednostek przy pustym słowniku, aktywny przycisk podczas ładowania, użycie archiwalnego domyślnego `kg` oraz akceptację jednostki przy `PACKAGE`. Test ujawnił także fałszywe ostrzeżenie o niezapisanych zmianach po pierwszej wersji poprawki; zostało naprawione.

## Testy i ograniczenia

Testy uruchomiono w kopii `git archive HEAD` w przestrzeni sesji; osiem zmienionych plików porównano bajtowo z worktree. Wykorzystano istniejące `node_modules` i `vendor`, nie instalowano pakietów z sieci. Dla Alpine skopiowano wyłącznie brakujące lokalne binaria `@rolldown/binding-linux-x64-musl`, `@oxlint/binding-linux-x64-musl` i `lightningcss-linux-x64-musl` z istniejącego frontendu, po sprawdzeniu zgodności ich wersji z odpowiednimi zależnościami kopii. Cały lockfile obrazu frontendu różni się od HEAD, więc nie traktowano jego zależności jako kompletnego środowiska wydania.

| Kontrola | Wynik |
|---|---|
| Frontend, Node 24.22.2 Alpine, `npm test` | **PASS: 32 pliki, 312 testów.** Izolowany kontener `--network none`, bez wolumenów Stage. |
| Frontend, `npm run lint` | **PASS**, zero błędów; jedno ostrzeżenie `react(only-export-components)` w istniejącym `visualPreview.tsx`. |
| Frontend, `npm run build` | **PASS**, w tym `tsc -b` i build Vite. Ostrzeżenie o chunku ponad 500 kB. Wynik w katalogu tymczasowym nie jest obrazem wydania. |
| Backend, PHP 8.4.27 / PHPUnit 12.5.42 | **PASS: 3 testy, 22 asercje** w jednorazowym, lokalnym kontenerze bez sieci. Tymczasowe testy używały czystego kodu HEAD, mocków DB i sprawdzały reguły `RawMaterialDataRequest`, `RawMaterialPricingService::assertInputUnit` oraz odpowiedź edytora dla oczekującej ceny `LEGACY_BASE_UNIT`. Nie było połączenia z bazą. |
| Backendowe testy feature / PostgreSQL / pełne API | **NIE WYKONANO.** `backend/tests/TestCase.php` wymaga disposable PostgreSQL, migracji i zapisów fixture; ograniczenia zadania ich zabraniają, także na bazie testowej. Mocki nie sprawdzają SQL, triggerów, uprawnień, poprawności przeliczeń PostgreSQL ani prawdziwego endpointu. |
| Obrazy i zależności wydania | **NIE ZBUDOWANO i nie zweryfikowano** nowych obrazów, pełnego `npm ci`/`composer install` w buildzie ani zgodności frontend/backend/queue w uruchomionych nowych kontenerach. |

Komendy końcowe: `npm test && npm run lint && npm run build` w kontenerze z lokalnego `node:24-alpine` (`sha256:8a32c8cf649a49915a66e81e7a609223c708d0adabf288ee3f4077d765542f85`), `--network none`, ograniczenia CPU/pamięci/procesów, z kopią źródeł frontend i backend; oraz `php vendor/bin/phpunit --do-not-cache-result tests/Unit/Stage6a1PricingContractTest.php` w lokalnym obrazie PHP/backendu `sha256:a2ba484f1e44442bb2ee55ad5553490e3d9aafca7820d6b2ace0ae1ec1043356`, `--network none`, z odczytywaną kopią kodu i testami tymczasowymi. Nie były to kontenery Stage. Początkowa próba na Alpine wykazała brak natywnych zależności; inna próba montowała tylko frontend i nie znalazła fixture JSON z katalogu backend. Po uzupełnieniu zgodnych lokalnych binariów i zamontowaniu obu katalogów powtórzono pełny zestaw z wynikiem wyżej. Wcześniejszy pełny przebieg na hoście Node 26 miał jednorazowy błąd testu fokusu poza zmienionym formularzem; ten test przeszedł osobno i w kolejnych pełnych przebiegach.

## Zgodność z oczekującymi migracjami

Poniższa kolejność i opis bazują na analizie migracji i odczycie czystego HEAD; **nie są wynikiem wykonania migracji ani testu integracyjnego nowego wydania**. Ostatni zapisany stan bazy: 49 zastosowanych, 12 oczekujących. Nie powtarzano zapytania do bazy w tym zadaniu.

| Kolejność | Migracja | Znaczenie dla synchronizacji |
|---|---|---|
| 1 | `2026_11_17_000050_add_raw_material_category_description_and_archive.php` | Metadane i archiwizacja kategorii RAW. |
| 2 | `2026_11_17_000051_create_raw_material_unit_dictionary.php` | Słownik, ograniczenia i uprawnienia DB jednostek RAW; konieczny dla nowego kontraktu. |
| 3 | `2026_11_17_000052_create_international_allergen_dictionaries.php` | Słowniki alergenów i inicjalizacja danych referencyjnych osadzona w migracji. |
| 4 | `2026_11_18_000052_create_supplier_directory.php` | Rozszerzenie dostawców i backfill; potencjalnie wymaga właściwego klucza aplikacji. |
| 5 | `2026_11_18_000053_create_installation_precautionary_allergens.php` | Zależy od kanonicznego słownika z poprzedniej migracji alergenów. |
| 6 | `2026_11_19_000053_preserve_actor_when_revoking_membership_permissions.php` | Zmiana funkcji audytu przyszłych odwołań; bez nadawania grantów użytkownikom. |
| 7 | `2026_11_19_000054_create_boss_location_restriction_revisions.php` | Tabela ograniczeń BOSS. |
| 8 | `2026_11_19_000055_extend_boss_visibility_policy.php` | Rozszerzenie tabeli z `000054`; bez aktywacji BOSS. |
| 9 | `2026_11_20_000056_create_organization_capability_state.php` | Tabele stanu i zdarzeń capability; bez automatycznej aktywacji. |
| 10 | `2026_11_21_000057_add_raw_material_revision_supplier_snapshots.php` | Nowe snapshoty dostawców; brak backfillu historycznych przypisań. |
| 11 | `2026_11_22_000058_add_user_avatar_state.php` | Stan avatara istniejących użytkowników z domyślną wartością. |
| 12 | `2026_11_23_000059_retire_raw_material_supplier_compatibility_workflow.php` | Usuwa dwa widoki i ogranicza DB DML starego workflow. |

W `backend/app` i `backend/routes` czystego HEAD nie znaleziono odwołań do dwóch usuwanych widoków ani do tabeli wycofywanego workflow. Stary obraz backendu i queue `sha256:a2ba484f1e44442bb2ee55ad5553490e3d9aafca7820d6b2ace0ae1ec1043356` używa tych widoków i nie może bezpiecznie pozostać w użyciu po `000059`. Brak odwołań w przeszukanym kodzie nie zastępuje testu działającego obrazu. Szczegóły obiektów, danych i ryzyk każdego kroku: [raport luki migracyjnej](./VELTRA_STAGE6A1_MIGRATION_GAP_REPORT.md).

## Pozostałe blokady i ryzyka

1. Poprawki są niecommitowane; potrzebny przegląd i osobno zatwierdzona, identyfikowalna czysta rewizja obejmująca je. Nie wolno budować wydania bezpośrednio z obecnego worktree ani włączać wcześniejszych niepowiązanych zmian. Potrzebne są też decyzja dotycząca powyższych ograniczeń jednostek oraz wymagane testy integracyjne na osobno autoryzowanym środowisku testowym.
2. Nie odnaleziono autorytatywnego standardu VSEF. Nie potwierdzono zgodności z nim; właściciel musi udostępnić dokument albo określić jego zastosowanie.
3. Przed synchronizacją trzeba ponownie potwierdzić tożsamość Stage, 49/12 migracji, konflikty danych, wszystkie przywileje migratora, runtime i dostawcy sekretów dla migratora/backendu/queue. Wcześniej potwierdzono tylko część tych uprawnień i obecność dwóch kluczy w starym backendzie; nie potwierdzono kompletności w przyszłych kontenerach. Nie ujawniać wartości.
4. Backup `/home/veltra/backups/stage6a1/veltra-stage6a1-20261123T193945Z-b01845be954d42199ec77e8909766f7c.dump` nadal istnieje, 677466 bajtów, tryb `600`. Jego wcześniej sprawdzony SHA-256 to `1ea1369b02f4014fee09e145998990e8d1b93e861f83ef44c5c54e10d382bf16`; w tym zadaniu sprawdzono tylko metadane. Restore ma PASS w zakresie wykonanych kontroli logicznych po wyjaśnieniu 54 różnic reprezentacji, ale globalne role, ownership, ACL, pełna zawartość wierszy i wszystkie sekwencje nie zostały zweryfikowane. Przed migracją ponownie sprawdzić checksumę, aktualność kopii względem ostatnich zapisów i realną strategię recovery; w razie potrzeby uzyskać autoryzację na nową kopię i test.
5. Migracje forward-only i `000057` bez backfillu wymagają ponownego zbadania danych przy wstrzymanych zapisach. `000059` zmienia granty roli DB dotyczące wycofanego workflow, nie nadaje grantów RBAC użytkownikom. Osadzona inicjalizacja alergenów w migracji `000052` jest zmianą danych referencyjnych, nie osobnym seederem.
6. Poprzednio RAW był `READY` i włączony dla jednej aktywnej lokalizacji, BOSS `BOSS_RAW_CATALOG_READ` w HEAD ma `ready=false`, a tabel capability brakowało. Są to osobne kwestie od schematu i RBAC; nie ponawiano odczytu bazy ani nie zmieniano tych stanów. Dla ręcznych testów wymagane będzie potwierdzenie lokalizacji i rzeczywistych grantów bez samodzielnego ich nadawania.
7. Istniejące Dockerfile używają `node:24-alpine` i `php:8.4-cli-alpine`, a Compose korzysta z obrazów lokalnych. Wersje baz, zależności, brak niezamierzonych pulli oraz tożsamość przyszłych obrazów należy sprawdzić osobno. Testowana kopia nie jest zbudowanym obrazem; testy frontendu wykorzystują również fixture JSON z katalogu backend, którego sam kontekst `frontend` nie zawiera.

## Zalecany kolejny etap — wymaga odrębnej autoryzacji

1. Właściciel ocenia poprawki i ograniczenia kontraktu, dostarcza VSEF oraz zatwierdza czystą rewizję po przeglądzie. Bez tego zatrzymać przygotowanie release.
2. Z tej **samej** rewizji odtworzyć odizolowane konteksty, zweryfikować lockfile, bazy i zależności; wykonać testy integracyjne na osobno autoryzowanej disposable bazie. Dopiero potem zbudować frontend i wspólny obraz backend/migrator/queue, zapisać rewizję i niezmienne ID/digests, bez wciągania istniejących niecommitowanych zmian.
3. Dla wyłącznie lokalnego `veltra-stage6a1` ponowić pełny preflight, zweryfikować aktualny backup i możliwość recovery, dostępność sekretów i uprawnienia każdej migracji, nie naruszając izolowanego restore-testu. Zidentyfikować obecny wolumen i wszystkie kontenery.
4. W uzgodnionym oknie zatrzymać ruch i procesy backend oraz queue, pozostawić PostgreSQL i jego wolumen. Przy spełnionych warunkach uruchomić migracje w podanej kolejności zweryfikowanym runnerem, zatrzymując się na pierwszym błędzie bez automatycznego rollbacku, retry lub restore. Po błędzie zachować stan i logi oraz uzyskać osobną decyzję przed odzyskiwaniem; nie uruchamiać starego backendu po `000059`.
5. Po potwierdzeniu wszystkich 61 rekordów uruchomić przygotowane, wzajemnie zgodne backend, queue i frontend w tym samym lokalnym projekcie; sprawdzić obiekty i integralność bazy, zachowanie danych i grantów, logi, health, kontrakty RAW/dostawców oraz zgodność frontend/API. Nie tworzyć rekordów biznesowych na pokaz. Sprawdzić osobno RAW readiness, włączenie lokalizacji, BOSS readiness/aktywację i RBAC; nie aktywować ani nie zmieniać ich w ramach samej synchronizacji.

## Kontrole bieżącego środowiska

Odczyt `docker inspect` potwierdził dotychczasowe ID PostgreSQL `79317d539f35ba9186cd3b6518252be9e5eb89d847087cd0bd382ce466c2fccf` i backendu `7e3e4949cb4f44e768d74094e206fd4d9a83c0e0fa6c43439bfd6f6295f12f2d` jako `running/healthy`, frontend `b1930850c0c0af5cfcc78b44fc5edd7724eed8e5ff9de93835801aa23a8f7666` jako `running/healthy`, queue `6d8822e78986f1b51731bf368f9942298279f0d900cfe688d1c8c544223217d1` jako `running`; API `/api/health` zwróciło HTTP 200. Szablon jednej zbiorczej próby `docker inspect` zgłosił brak pola `Health` w queue; jej stan odczytano osobnym poprawnym poleceniem. Te kontrole nie zastępują przyszłego preflightu migracyjnego.
