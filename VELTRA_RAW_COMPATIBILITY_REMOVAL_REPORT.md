# VELTRA 2.0 — raport usunięcia workflow zgodności RAW

## Podsumowanie

Usunięto aktywny workflow oceniania zgodności dostawcy z surowcem. RAW korzysta teraz z istniejącego bezpośredniego przypisania wiele-do-wielu: jeden surowiec może mieć wielu dostawców, a dostawca może być przypisany do wielu surowców. Formularz tworzenia i edycji RAW pozwala dodawać i usuwać przypisania.

Załączniki pozostają opcjonalne i informacyjne. Zachowano prywatne składowanie, uprawniony podgląd/pobieranie, weryfikację sum kontrolnych i skanowanie AV. Skanowanie nadal chroni pliki, ale nie jest warunkiem operacji biznesowych.

## Zmienione pliki

- `backend/app/Domain/Authorization/AuthorizationMutationScope.php` — wąski zakres pozwalający wycofać historyczny grant zgodności.
- `backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentScanService.php` — usunięto sprawdzanie CLEAN jako warunku dowodu zgodności.
- `backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentService.php` — nowe dokumenty są łączone wyłącznie jako zwykłe załączniki RAW; usunięto parametry powiązania z rewizją produktu dostawcy.
- `backend/app/Domain/RawMaterials/OrganizationRawMaterialPermissionAdministration.php` — obsługa cofnięcia starego grantu bez możliwości jego ponownego nadania.
- `backend/app/Domain/RawMaterials/RawMaterialCommercialService.php` — usunięto `compatibility_status` z list, historii rewizji i szczegółów produktów.
- `backend/app/Domain/RawMaterials/RawMaterialPermission.php` — usunięto aktywną pozycję uprawnienia zatwierdzania zgodności.
- `backend/app/Http/Controllers/Api/V1/RawMaterialController.php` i `backend/routes/api.php` — usunięto handlery i trasy ocen zgodności.
- `backend/lang/pl/messages.php` — usunięto komunikaty wycofanego workflow.
- `backend/database/migrations/2026_11_23_000059_retire_raw_material_supplier_compatibility_workflow.php` — nowa migracja forward-only.
- `backend/tests/Concerns/RawDocumentsStage3eB3Tests.php` — test opcjonalnego certyfikatu, widoczności produktu i dostępności oferty bez ocen zgodności.
- `backend/tests/Feature/RawMaterialDocumentStorageTest.php` — test bezpieczeństwa skanowania oraz zachowania historycznych danych.
- `backend/tests/Feature/RawMaterialStage2dApiTest.php` — test przypisywania i usuwania wielu dostawców przez formularz oraz blokady nowych grantów.
- `frontend/src/administration/rawPermissionsApi.ts` — usunięto grant z listy nowych uprawnień; stary grant pozostaje czytelny jako historyczny i nieaktywny.
- `frontend/src/administration/rawPermissionsApi.test.ts` — test prezentacji historycznego grantu bez opcji nadawania.

Usunięto:

- `backend/app/Domain/RawMaterials/RawMaterialCompatibilityAssessmentService.php`
- `backend/app/Http/Requests/RawMaterialCompatibilityAssessmentRequest.php`
- `backend/tests/Feature/RawMaterialCompatibilityAssessmentTest.php`

Wcześniejszy `VELTRA_RAW_SIMPLIFICATION_IMPACT_REPORT.md` pozostał bez zmian.

## Migracja i zachowane dane

Migracja `000059`:

- usuwa wyłącznie pochodne widoki `raw_material_supplier_compatibility_status` i `raw_material_approved_compatibility_document_evidence`;
- zachowuje `raw_material_supplier_compatibility_assessments` oraz istniejące rekordy, odbierając aplikacji uprawnienia zapisu i pozostawiając odczyt;
- usuwa wpisy z list runtime/mutation scope, które umożliwiały zapis nowych ocen;
- dodaje ograniczenie `NOT VALID` blokujące nowe grantu `RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE`, bez walidowania ani modyfikowania istniejących grantów;
- dodaje ograniczenie `NOT VALID` wymagające, by nowe lub zmieniane powiązania dokumentów były zwykłymi załącznikami RAW bez rewizji produktu dostawcy. Stare powiązania pozostają nietknięte.

Nie zmieniono ani nie przepisano wcześniejszych migracji, nie usunięto ocen, wpisów audytowych, idempotency ani danych dokumentów. Historyczne granty zgodności nie są już aktywnym uprawnieniem; interfejs pokazuje je jako nieaktywne, a istniejący mechanizm cofania nadal może je wycofać.

## RAW, dostawcy i BOSS

- `raw_material_supplier_associations` pozostaje bez zmian i obsługuje relację wiele-do-wielu. Regresja sprawdza utworzenie RAW z dwoma dostawcami i usunięcie jednego przez edycję.
- Zwykła historia rewizji i zatwierdzeń RAW (`raw_material_revisions`, zdarzenia zatwierdzeń i audyt) nie została zmieniona ani pomylona z historią ocen zgodności.
- Dane dostawców, produktów, rewizji produktów, ofert, cen i uprawnienia dostępu pozostały. Operacja oferty nie wymaga oceny zgodności ani dokumentu.
- Nie zmieniono odczytu dostawców BOSS ani rewizji snapshotów: BOSS nadal korzysta z istniejących zatwierdzonych snapshotów, a nie z bezpośrednich przypisań RAW.
- Nie zmieniono gotowości ani aktywacji RAW/BOSS.

## Weryfikacja

Wszystkie testy backendu uruchomiono w odizolowanym Compose z tymczasowym PostgreSQL, Redis i zdrowym ClamAV, z `memory_limit=512M`. Migracja `000059` została zastosowana na świeżej bazie testowej.

- Testy RAW, dostawców/ofert, dokumentów, BOSS i autoryzacji: **44 przeszły, 537 asercji**. Obejmowały m.in. skanowanie ClamAV (próbka czysta i EICAR), przypisania wielu dostawców, brak statusu zgodności w odpowiedziach oraz zachowanie snapshotów BOSS.
- `npm test`: **32 pliki testowe, 293 testy przeszły**.
- `npm run build`: przeszło; Vite zgłosił ostrzeżenie o chunku przekraczającym 500 kB.
- `npm run lint`: przeszło; pozostało ostrzeżenie `react(only-export-components)` w `src/visualPreview.tsx:39`.
- Pint `--test`: **13 plików PHP bez problemów formatowania**.
- `git diff --check`: bez błędów.

## Ograniczenia i wdrożenie

Migracja jest forward-only i musi zostać zastosowana w środowisku docelowym zgodnie ze standardowym procesem migracji. Zależne systemy zewnętrzne, jeśli odczytują bezpośrednio usuwane widoki, wymagają osobnej weryfikacji. Nie wykonano migracji w istniejącej bazie aplikacyjnej; testy korzystały wyłącznie z odizolowanej, tymczasowej bazy.

`RAPORTY_COPILOTA` nie istnieje w sprawdzonych lokalizacjach `/home/veltra/projekty` ani `/home/veltra`, a dostępne narzędzia nie udostępniały uploadu. Raport pozostaje w katalogu głównym repozytorium. Nie wykonano wdrożenia, commita ani push.
