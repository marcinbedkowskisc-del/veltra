# Stage 6A.1 — przegląd kontraktu RAW i testy integracyjne

## Werdykt

**Testowany zestaw zmian przechodzi kontrole kontraktu i ukierunkowane testy na rzeczywistym PostgreSQL, ale nie jest jeszcze zatwierdzoną rewizją wydania.** Osiem wcześniejszych zmian frontendu jest akceptowalnych jako część zestawu, po uwzględnieniu dodatkowej poprawki kontraktu między rewizjami opisanej poniżej. Samych ośmiu plików nie należy wydawać bez tej poprawki backendu i formularza.

Bazą jest `main`, `3006659240b3a6bdba5b6c0b7b2583b99d21fbe6`; testowano kopię tej rewizji z wybranymi zmianami, nie czysty commit ani obrazy wydania. Właściciel wybrał zachowanie historycznej podstawy ceny i wymóg świadomej decyzji, gdy nie można jej użyć w bieżącej rewizji.

## Przegląd ośmiu wcześniejszych plików

| Plik | Ocena |
|---|---|
| `frontend/src/rawMaterials/RawMaterialCreateForm.tsx` | Akceptowalny: czeka na słownik i nie wybiera archiwalnej jednostki jako nowej ceny. |
| `frontend/src/rawMaterials/RawMaterialCreateForm.test.tsx` | Akceptowalny: sprawdza ładowanie i archiwalną jednostkę. |
| `frontend/src/rawMaterials/RawMaterialEditForm.test.tsx` | Akceptowalny: sprawdza niewysyłanie niezmienionej ceny; rozszerzony o scenariusz międzyrewizyjny. |
| `frontend/src/rawMaterials/RawMaterialPricingFields.tsx` | Akceptowalny: podgląd zależy od poprawnej jednostki bazowej. |
| `frontend/src/rawMaterials/api.ts` | Akceptowalny po dodatkowej poprawce: `PACKAGE` bez jednostki, `NOMINAL_UNIT` oraz jawny `LEGACY_BASE_UNIT`; zachowana ścisła walidacja zwykłych jednostek. |
| `frontend/src/rawMaterials/api.test.ts` | Akceptowalny: poprawne kody słownika, nieprawidłowe kody, wymiary, typy i ceny; rozszerzony o pochodzenie jednostki. |
| `frontend/src/rawMaterials/create.ts` | Akceptowalny: waliduje obecność jednostek w słowniku, również pustym. Ostateczne dopuszczenie jednostki i archiwalnego przypisania pozostaje po stronie backendu. |
| `frontend/src/rawMaterials/create.test.ts` | Akceptowalny: regresje brakujących jednostek i słownika. |

Testy obejmują `mg`, `g`, `kg`, `ml`, `l`, `pc`, przykładową jednostkę rozszerzoną oraz przypadki błędnego wymiaru, `MATERIAL_SPECIFIC`, brakującej jednostki i nieprawidłowego kodu. Nie dowodzą wszystkich możliwych kombinacji danych. Formularz nadal nie oferuje niezależnego wyboru dowolnej jednostki ceny; nie rozszerzano tej funkcji.

## Potwierdzony defekt i poprawka

Na syntetycznym materiale zatwierdzono cenę `10/kg`, po czym utworzono rewizję bazową `g` bez `kg`, bez zmiany ilości. Oryginalny endpoint zwrócił `NOMINAL_UNIT` i `kg`, natomiast jednostek bieżącej rewizji była tylko `g`; poprzedni parser słusznie odrzucał tę odpowiedź. Przed poprawką test oczekujący innej reprezentacji wykazał niezgodność, ale automatycznej zamiany na `PACKAGE` **nie zastosowano**: zmieniłaby znaczenie ceny przy późniejszej zmianie ilości.

- `backend/app/Domain/RawMaterials/RawMaterialService.php` dołącza `pricing_input.source_unit` tylko wtedy, gdy jednostki zdarzenia nie można użyć jako jednostki ceny bieżącej rewizji. Odczytuje ją z rewizji źródłowej i sprawdza jej typ oraz wymiar względem tej rewizji. Brak możliwego do potwierdzenia źródła powoduje jawny błąd. Dodatkowo endpoint pokazuje istniejące zdarzenie o jawnej podstawie `LEGACY_BASE_UNIT` zamiast zwracać pustą cenę.
- `frontend/src/rawMaterials/api.ts` wymaga prawidłowych danych źródłowych dla jednostki historycznej i odrzuca m.in. brak, niezgodny kod, identyfikator bieżącej rewizji, zły wymiar lub niedozwolony typ. Nie zniesiono wymagań dla ceny należącej do bieżącej rewizji.
- `frontend/src/rawMaterials/RawMaterialEditForm.tsx` pokazuje pochodzenie ceny. Edycja innych danych nie wysyła jej ponownie ani nie dodaje historycznej jednostki do nowej rewizji. Zmiana ilości lub jednostki bazowej wymaga jawnego określenia ceny; zmiana podstawy czyści pole kwoty, aby nie przenieść po cichu liczby o innym znaczeniu. Archiwalna jednostka nie jest przypisywana jako nowa cena. Podgląd nie przelicza niepotwierdzonej ceny historycznej.
- `backend/tests/Feature/RawMaterialRevisionDataModelTest.php` i dwa powyższe pliki testowe frontendu zawierają nowe regresje. Pozostałe wcześniejsze zmiany zachowano.

Test PostgreSQL sprawdza brak dodatkowego zdarzenia ceny po edycji innego pola, odrzucenie przeliczenia bez brakującej jednostki oraz jawną nową cenę `PACKAGE`. Osobno sprawdzono jednostkę ze słownika później zarchiwizowaną i jawną podstawę `LEGACY_BASE_UNIT` w zdarzeniu i rewizji roboczej. Test formularza sprawdza zablokowanie zmiany ilości, konieczność wpisania kwoty po zmianie podstawy i niewysyłanie archiwalnej jednostki. Odpowiedź HTTP i parser sprawdzono w osobnych testach z odpowiadającymi sobie przykładami, a nie w automatycznym teście przeglądarkowym z uruchomionym pełnym stosem.

Ograniczenie: bardzo stare zdarzenia bez `pricing_input_basis` i bez wartości opakowania nie posiadają pochodzenia jednostki i nie zostały objęte tymi testami; nie należy traktować tego raportu jako dowodu pełnej obsługi całej historycznej bazy. Nie sprawdzano ich występowania w Stage.

## Izolacja i wykonane testy

Docker context `default`, daemon ID `1163050e-c562-4a35-bd94-8c24de688587`. Użyto istniejącego `compose.testing.yaml` jako nowego projektu `veltra-raw-contract-b7a2817b`, syntetycznych poświadczeń i bazy `veltra_testing`. Przed migracjami sprawdzono nazwy, etykiety, sieć, porty, mounty, bazę i role. PostgreSQL 17.11 i Redis miały wyłącznie wewnętrzną sieć projektu, bez publikowanych portów i bez bind/named volumes; katalogi danych były w `tmpfs`. Runner używał wyłącznie kopii backendu w przestrzeni sesji. W tej **wyłącznie testowej** bazie wykonano 61 migracji oraz testowe fixtures. Nie użyto bazy ani kopii Stage jako fixtures.

| Kontrola | Wynik |
|---|---|
| Backend, PHP 8.4.28, PHPUnit 12.5.42, PostgreSQL 17.11 | **PASS: 7 testów, 108 asercji.** Testy obejmują `PACKAGE`, `NOMINAL_UNIT`, przeliczenia ilości, podgląd, archiwalną jednostkę, cenę z poprzedniej rewizji i `LEGACY_BASE_UNIT`. |
| Frontend, Node 26.13.0, `npm test` | **PASS: 32 pliki, 320 testów.** |
| Frontend, `npm run lint` | **PASS**, z istniejącym ostrzeżeniem `react(only-export-components)` w `visualPreview.tsx`. |
| Frontend, `npm run build` | **PASS**, w tym TypeScript; ostrzeżenie o chunku ponad 500 kB. |
| PHP Pint, dwa zmienione pliki worktree w kopii testowej | **PASS**. Czysty HEAD z samą poprawką funkcjonalną nie przechodzi Pint z powodu wcześniejszego formatowania `RawMaterialService.php`; poprawione już lokalnie formatowanie jest odrębną, wcześniej istniejącą zmianą. |
| `git diff --check` | **PASS**. |

Komenda końcowa backendu, w kopii źródeł z syntetycznym plikiem środowiska:

```sh
docker compose --env-file ../raw-contract-test.env -f /home/veltra/projekty/veltra/compose.testing.yaml -p veltra-raw-contract-b7a2817b run --rm --no-deps --user 1000:1000 -v /home/veltra/.copilot/session-state/b7a2817b-670f-485b-af11-9f6f9e1b638c/files/raw-contract-source/backend:/var/www/html backend-test php artisan test --display-warnings --filter='test_editor_preserves_inherited_price_when_its_unit_is_not_in_the_working_revision|test_editor_identifies_archived_price_unit_from_an_earlier_revision|test_editor_exposes_pending_legacy_base_price_and_preserves_it_on_non_price_edits|test_package_and_nominal_unit_prices_normalize_to_equivalent_usable_costs|test_archived_custom_unit_keeps_raw_revision_reference_and_locks_its_definition|test_approved_quantity_changes_recalculate_both_price_modes_and_supplier_links_do_not_change_price|test_pricing_preview_uses_authoritative_normalization_and_cost_rules'
```

Wstępny przebieg backendu miał ostrzeżenia biblioteki o braku `.env` w kopii; powtórzenie z pustym plikiem środowiska w kopii, przy zmiennych dostarczonych przez runner, zakończyło się powyższym PASS bez ostrzeżeń. Jeden pośredni przebieg frontendu nie przeszedł z powodu selektora testu obejmującego również tekst błędu; po jego korekcie pełna seria przeszła. Zależności skopiowano z istniejących lokalnych instalacji, bez odtwarzania ich z lockfile i bez sieciowej instalacji. To nie jest weryfikacja odtwarzalności obrazów wydania.

## Sprzątanie i stan Stage

Po ponownej weryfikacji ID i członków sieci zatrzymano i usunięto tylko kontenery testowe PostgreSQL `a2ec52c338e5e78bce5cdc8f86f6621515b29451bc6d0db7643a3288d5d0f4cb`, Redis `d10845dfff22847d2aa3a12479a7da249512a8952c5514a699aa6fc0550d384c` oraz sieć `caffafd5721ac59d6c6d65d7c22db89a8a0876683350d773049718b43a96cbe2`. Nie było nazwanych wolumenów projektu testowego. Kopię testową i syntetyczny plik środowiska usunięto.

PostgreSQL Stage nadal `healthy` na `veltra-stage6a1_postgres-data`; backend i frontend `healthy`, queue `running`, API `/api/health` HTTP 200. Zachowany poprzedni kontener restore `75e3fbbadffbb0f5525bf120f6aa6267099c40d81f2b5d347889ffb1df2702c7` nadal działa w sieci `none`, a jego osobny wolumen istnieje. Nie zmieniano Stage, jego bazy, backupu ani wolumenów; nie wykonywano migracji Stage, wdrożenia, commita, pusha, zmian RBAC ani aktywacji.

## Granica wydania i dalsze blokady

1. Zmiany nie mają zatwierdzonej rewizji. Wyodrębnić funkcjonalną zmianę PHP od wcześniejszego formatowania tego samego pliku; nie wciągać niepowiązanych zmian `README.md`, Compose ani hardeningu PostgreSQL. Osobno przejrzeć i zatwierdzić kompletny zestaw frontend/backend/testy oraz czystą rewizję. Nie commitowano zmian w tym zadaniu.
2. Przed wydaniem sprawdzić historię cen bez informacji o podstawie i pochodzeniu oraz określić jej bezpieczną obsługę; nie zakładać, że przypadki testowe wyczerpują istniejące dane.
3. Autorytatywny standard VSEF pozostaje niedostępny; zgodności nie potwierdzono. Zbudować i sprawdzić frontend, backend, migrator i queue z tej samej zatwierdzonej rewizji, z zależnościami z lockfile i niezmiennymi identyfikatorami obrazów; wykonać test pełnego uruchomionego stosu.
4. Przed jakąkolwiek późniejszą synchronizacją, po osobnej autoryzacji, ponowić preflight tożsamości, dostępności sekretów i uprawnień, aktualności backupu, integralności danych oraz 49/12 migracji. Restore ma jedynie zakres logicznych kontroli z wcześniejszego raportu; globalne role, ownership i ACL nie zostały nim odtworzone ani w pełni zweryfikowane.
5. Migracja `000059` usuwa workflow zgodności wymagany przez stary backend. Wymaga skoordynowanego wydania frontend/backend/queue i kontrolowanego okna migracji. Bez tego oraz oceny pozostałych migracji i danych nie uruchamiać synchronizacji. RAW readiness, włączenie lokalizacji, BOSS readiness/aktywacja i RBAC pozostają osobnymi decyzjami.
