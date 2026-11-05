# VELTRA 2.0 — Niezależna weryfikacja remediacji Stage 2D.7

## Tożsamość weryfikatora i niezależność

Weryfikację wykonał odrębny agent code-review, inny niż autor remediacji Stage 2D.7. Weryfikator samodzielnie sprawdził pierwotne ustalenia, aktualny kod, migracje i testy w worktree. Nie przypisuje się mu nazwiska ani innych niezweryfikowanych danych tożsamości.

## Ustalenie 1 — HIGH — brak atestu alergenowego przy przejściu v1→v2

**Status: RESOLVED**

Zatwierdzanie nowej rewizji v2 wymaga atestu przypisanego do dokładnej rewizji. Walidacja sprawdza jego powiązanie z organizacją, opublikowaną wersję słownika, stan i zgodność deklaracji, aktora z tej samej organizacji oraz nieprzyszły czas atestu. Następnie weryfikowana jest zgodność hasha rewizji. Historyczne odczyty i hashe v1 pozostają obsługiwane; historyczne dane nie otrzymują syntetycznych atestów.

Regresja odtwarza przypadek historycznej rewizji v1 `DECLARED`, z której utworzono v2 bez atestu: zatwierdzenie zostaje odrzucone. Test obejmuje również ścieżkę zwrotu do zmian, jawne atestowanie i zatwierdzenie. Przywracanie tworzy nową rewizję, wobec której obowiązuje ta sama kontrola atestu.

**Dowody w kodzie i testach:**

- `backend/app/Domain/RawMaterials/RawMaterialService.php:680–697, 1010–1058, 1063–1088, 1091–1166` — warunek zatwierdzania v2 i walidacja atestu.
- `backend/app/Domain/RawMaterials/RawMaterialService.php:819–889, 268–285` — przywracanie rewizji oraz zwrot do edycji.
- `backend/tests/Feature/RawMaterialRevisionDataModelTest.php:232–295, 368–373` — regresja przejścia v1→v2 i zachowania hasha v1.
- `backend/tests/Feature/RawMaterialsTest.php:312–345` — regresja przywrócenia, atestowania i ponownego zatwierdzenia.

## Ustalenie 2 — MEDIUM — brak podpisanej granicy mutacji

**Status: RESOLVED**

Forward-only migracja 033 usuwa ogólny zakres mutacji dla trzech chronionych tabel i wymaga podpisanego kontekstu z zakresem `raw-material.allergen.manage` lub `raw-material.storage.manage`. Triggery wiążą zakres i subject z organizacją wstawianego wiersza oraz weryfikują aktywnego aktora należącego do tej organizacji. Wspólny mechanizm sprawdza podpis, transakcję, wygaśnięcie i zakres. Ochrona obejmuje atesty, deklaracje alergenów i warianty przechowywania.

Migracja 028 przyznaje roli runtime tym tabelom `SELECT, INSERT`, ale nie `UPDATE` ani `DELETE`; triggery utrzymują niezmienność i sprawdzają powiązania danych. Testy obejmują bezpośrednie inserty bez zaufanego kontekstu do wszystkich trzech tabel oraz kontekst z niezgodnym subject. Legalne operacje serwisowe wykonują inserty w podpisanym kontekście i transakcji; audyt jest zapisywany w tej samej transakcji. Dane aktora i czasu atestu nie są przyjmowane od klienta w żądaniu deklaracji.

**Dowody w kodzie i testach:**

- `backend/database/migrations/2026_11_06_000033_enforce_signed_stage2d_revision_mutations.php:25–52, 55–145` — definicje zakresów i guardów dla trzech tabel.
- `backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php:314–332` — wspólna walidacja podpisanego kontekstu.
- `backend/database/migrations/2026_11_06_000028_create_raw_material_revision_allergen_and_storage_data.php:175–239, 316–320` — uprawnienia runtime i niezmienność tabel.
- `backend/tests/Feature/RawMaterialRevisionDataModelTest.php:312–365` — odrzucenie nieautoryzowanych insertów i błędnego subject.
- `backend/app/Domain/Authorization/AuthorizationMutationContext.php:49–80` — uruchamianie operacji w podpisanym kontekście.
- `backend/app/Domain/RawMaterials/RawMaterialService.php:436–438, 973–1007, 508, 883–886` — transakcyjne ścieżki serwisowe i zapis audytu.
- `backend/app/Http/Requests/RawMaterialAllergenDeclarationRequest.php:10–18` oraz `backend/app/Http/Controllers/Api/V1/RawMaterialController.php:370–380` — walidacja żądania i granica API.

## Ograniczenia weryfikacji

- Nie uruchamiano testów ani migracji w ramach tej weryfikacji; 17 ograniczonych testów Stage 2C nie wykonano.
- Wynik istniejącej regresji 201 testów / 1647 asercji potraktowano wyłącznie jako dowód wspierający, a nie substytut inspekcji kodu.
- Nie potwierdzono zastosowania migracji 033 w żadnej bazie ani wdrożenia.
- Jawne wyjątki dla roli migracyjnej i superusera nie są ścieżką runtime `veltra_app`.

## Werdykt

**PASS** — oba pierwotne ustalenia zostały niezależnie zweryfikowane jako rozwiązane w aktualnym worktree. Werdykt nie potwierdza stanu wdrożonej bazy danych, nie zmienia statusu Stage 2C i nie zamyka automatycznie Stage 2D.
