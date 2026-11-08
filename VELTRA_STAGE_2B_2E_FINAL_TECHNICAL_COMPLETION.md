# VELTRA 2.0 — końcowe zamknięcie techniczne Stage 2B–2E

## 1. Faktycznie znalezione problemy

- Migracja 023 usunęła część triggerów podpisanej granicy mutacji. Test na PostgreSQL 17 potwierdził możliwość bezpośredniej zmiany kategorii i danych rewizji przez rolę runtime.
- Dostawca nie miał bezpiecznej ścieżki korekty obsługiwanych danych. Próba bezpośredniej korekty nie zapewniała wymaganego powiązania autoryzacji i audytu.
- Import katalogu CSV obejmujący co najmniej 375 rekordów przekraczał 30-sekundowy czas ważności pojedynczego biletu mutacji. Test integracyjny odtworzył awarię po częściowym przetworzeniu wierszy; transakcja cofała cały import.
- Sam audyt zmiany podatkowego identyfikatora nie zmieniał skrótów `before_hash`/`after_hash`, jeśli korygowano wyłącznie ten identyfikator.

## 2. Wprowadzone zmiany

- Dodano forward-only migrację 037, przywracającą podpisane mutacje kategorii, materiałów, rewizji, jednostek rewizji, aliasów i dostawców. Migracja przywraca również ograniczenia niezmienności tożsamości dostawcy i pozwala na korektę jego danych wyłącznie w odpowiednim, związanym z dostawcą kontekście podpisanej mutacji.
- Dodano autoryzowaną ścieżkę `PATCH /api/v1/raw-materials/suppliers/{supplier}` z walidacją ograniczonego zestawu pól, kluczem idempotencji, szyfrowaniem identyfikatora podatkowego, przypisaniem aktora i lokalizacji oraz audytem bez ujawniania wartości podatkowej.
- Skrócono zakres biletów dla importu katalogu CSV do pojedynczych zapisów partii, wierszy i audytu, zachowując jedną transakcję dla całej operacji. Dzięki temu bilet pozostaje krótko ważny, a import nadal jest atomowy.
- Skróty audytu korekty dostawcy uwzględniają nieujawniający HMAC odcisku identyfikatora podatkowego, również przy korekcie tylko tego pola.
- Testy regresyjne obejmują bezpośrednie próby SQL, autoryzowane korekty, niezmienność referencji handlowych, atrybucję audytu, cofnięcie transakcji przy błędzie audytu oraz import 375 wierszy.
- Żadna wcześniej zastosowana migracja nie została zmieniona.

## 3. Zweryfikowane gwarancje bezpieczeństwa

- **RBAC / PostgreSQL:** testy z kontrolowanymi podpisanymi kontekstami dotarły do walidatorów biznesowych i odrzuciły replay cofniętego bootstrapu, próbę zagnieżdżonego upgrade’u po cofnięciu authority, fałszywy root grant oraz delegowanie przez nieaktywnego grantora lub z nieskutecznego źródła. Nie osłabiono produkcyjnej ochrony podpisanych mutacji.
- **Rewizje i dane surowców:** potwierdzono ochronę zapisu kategorii, materiałów, rewizji, jednostek i aliasów oraz przepływ edycji DRAFT, deklaracji, submit, approval i ACTIVE. Testy API i eksportów obejmują widoczność efektywnych danych, szkice, rewizje, alergeny oraz zgodność CSV.
- **Audyt biznesowy:** kategoria i dostawca mogą być tworzone lub zmieniane przez autoryzowane serwisy z przypisanym aktorem i audytem. Bezpośrednie zapisy runtime do kategorii i danych dostawcy są odrzucane. Błąd audytu wycofuje zmianę biznesową i wpis idempotencji.
- **Korekta dostawcy:** korekta wymaga uprawnienia `SupplierManage`; dostępne są wyłącznie nazwa wyświetlana, nazwa prawna i identyfikator podatkowy. Zachowane są identyfikator dostawcy, kod i historyczne referencje handlowe; surowy identyfikator podatkowy nie trafia do odpowiedzi ani audytu.
- **SKU:** istniejący dowód 13/13 odmów pozostał niezmieniony. Dodatkowe próby runtime zmiany licznika i przypisań SKU są odrzucane; allocator oraz niezależne liczniki RAW/INT/PRD/MEN i zgodność SUR nie były przebudowywane.

## 4. Pozostałe blokery

- Nie znaleziono pozostałego blokera technicznego w objętym zakresie.

## 5. Wyniki pełnej regresji

Wszystkie środowiska testowe były syntetyczne i odizolowane. Użyto efemerycznego PostgreSQL 17, Redis i ClamAV w prywatnej sieci testowej; nie wykonywano testów destrukcyjnych na bazach aplikacji i nie przeprowadzano wdrożenia.

- Pełny backend PHPUnit, w tym testy bezpieczeństwa: **256 passed, 2536 assertions**.
- Testy bezpieczeństwa RBAC i granicy mutacji PostgreSQL: **17/17 passed**.
- Focused RBAC, granica mutacji i raw materials: **75 passed, 1109 assertions**.
- Skanowanie dokumentów i ocena zgodności: **17 passed, 146 assertions**.
- Import katalogu CSV: potwierdzono zatwierdzenie partii 375 wierszy.
- PostgreSQL 17: upgrade istniejącej bazy z migracji 036 do 037 — **PASS**; świeża baza z migracjami 001–037 — **37/37 PASS**.
- PHP lint dla plików PHP backendu — **PASS**.
- Pint `--test` dla wszystkich zmienionych plików PHP — **11 files PASS**.
- `git diff --check` — **PASS**.

## 6. Dokładna lista zmienionych plików

- `backend/app/Domain/Authorization/AuthorizationMutationScope.php`
- `backend/app/Domain/RawMaterials/RawMaterialCatalogCsvImportWorkflow.php`
- `backend/app/Domain/RawMaterials/RawMaterialCommercialService.php`
- `backend/app/Http/Controllers/Api/V1/RawMaterialController.php`
- `backend/app/Http/Requests/RawMaterialSupplierCorrectionRequest.php`
- `backend/database/migrations/2026_11_07_000037_restore_signed_raw_material_and_supplier_mutations.php`
- `backend/lang/pl/messages.php`
- `backend/routes/api.php`
- `backend/tests/Feature/AuthorizationMutationBoundaryTest.php`
- `backend/tests/Feature/RawMaterialPermissionBootstrapTest.php`
- `backend/tests/Feature/RawMaterialsTest.php`
- `VELTRA_STAGE_2B_2E_FINAL_TECHNICAL_COMPLETION.md`

## Rekomendacja gotowości

**GO techniczne do rozpoczęcia Stage 2F.** Wszystkie wymagane backendowe regresje, pełna instalacja migracji i upgrade forward-only przeszły.
