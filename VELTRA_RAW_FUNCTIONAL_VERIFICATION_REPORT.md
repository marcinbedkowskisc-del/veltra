# VELTRA 2.0 — raport weryfikacji funkcjonalnej RAW

**Data:** 2026-11-23  
**Branch / commit:** `main` / `32e3bff` (`refactor(raw): remove supplier compatibility workflow`)  
**Zakres:** weryfikacja istniejącego RAW; bez zmian funkcjonalnych, wdrożenia ani pracy na istniejących bazach.

## Podsumowanie

Nie znaleziono odtwarzalnego defektu w sprawdzonych ścieżkach CSV, przypisań dostawców, załączników ani snapshotów BOSS. Nie oznacza to pełnej akceptacji: poniższe kontrole oznaczone NOT TESTED nie mają wystarczającego dowodu testowego. RAW_MATERIALS pozostaje NOT_READY; nie weryfikowano działania na produkcji.

| Obszar | Wynik |
|---|---|
| CSV — kolumny, UTF-8, cytowanie, filtry i eksport zaznaczeń z wielu stron | **PASS — testy automatyczne** |
| CSV — pusty wynik, rzeczywisty eksport między tenantami, ręczna weryfikacja UI | **NOT TESTED** |
| Wielu dostawców — przypisanie, ponowny odczyt, usunięcie | **PASS — testy automatyczne** |
| Duplikaty dostawców i izolacja tenantów dla samego przypisania | **NOT TESTED** |
| Dokumenty opcjonalne, prywatny odczyt, skan AV i brak workflow zgodności | **PASS — testy automatyczne** |
| Typowany upload wyniku laboratoryjnego | **NOT TESTED** |
| BOSS — autoryzacja, izolacja organizacji i snapshoty dostawców | **PASS — testy automatyczne** |

**FAIL:** nie stwierdzono żadnego odtwarzalnego błędu. Nie ma więc zgłoszeń wymagających kroków reprodukcji ani rekomendacji napraw.

## Podstawa decyzji i implementacja

Przed testami sprawdzono najnowszy commit oraz raport [usunięcia workflow zgodności RAW](./VELTRA_RAW_COMPATIBILITY_REMOVAL_REPORT.md), raport [UI dokumentów RAW](./VELTRA_RAW_DOCUMENT_MANAGEMENT_UI_IMPLEMENTATION_REPORT.md), zaakceptowany kontrakt [eksportu katalogu Stage 2E.3](./VELTRA_STAGE_2E3_CSV_CATALOG_EXPORT_REPORT.md) i zakres [Stage 3E-B.3](./VELTRA_STAGE_3E_B3_RAW_DOCUMENTS_EXPORT_BULK_REPORT.md).

Decyzja z `32e3bff` potwierdza relację RAW–dostawcy wiele-do-wielu, wycofuje oceny zgodności i pozostawia dokumenty załącznikami informacyjnymi. BOSS nadal opiera się na zatwierdzonych snapshotach, a nie na bezpośrednim przypisaniu RAW. CSV ma dwa odrębne kontrakty: bazowy eksport katalogu (`ingredient_id;ingredient_name`) oraz eksport wybranych rekordów i kolumn z interfejsu (`selected_raw_catalog`).

## Wyniki szczegółowe

### 1. Eksport CSV

| Kontrola | Wynik i dowód |
|---|---|
| Rekordy i kolumny | **PASS — testy automatyczne.** Test eksportu bazowego sprawdza dokładny nagłówek `ingredient_id;ingredient_name`, aktywne rekordy z zatwierdzonych rewizji, kolejność i brak szkiców. Test eksportu zaznaczeń sprawdza wybrane kolumny i kolejność. |
| UTF-8, polskie znaki i escapowanie | **PASS — testy automatyczne.** Test bazowy sprawdza dokładny tekst CSV z `Mąka; "biała"` i `Żurawina`, włącznie z cudzysłowami, separatorem w polu i odczytem pól przez parser CSV. Test eksportu zaznaczeń sprawdza polskie znaki w nazwach. |
| Pusty wynik | **NOT TESTED.** Nie znaleziono uruchomionego testu ani wykonanej próby HTTP dla pustego eksportu; nie zakładam, że odpowiedź zawiera prawidłowy nagłówek. |
| Filtry, zaznaczenie i strony | **PASS — testy automatyczne.** Testy Stage 3E-B.3 sprawdzają filtry wyszukiwania/jednostki/kategorii/sortowania oraz eksport 51 rekordów bez stronicowania, w tym zaznaczenie z pierwszej i trzeciej strony. Test komponentu sprawdza akcję „Eksportuj zaznaczone” i ścieżkę pobrania. |
| Uprawnienia i ponowna autoryzacja | **PASS — testy automatyczne.** Test CSV sprawdza wymagane prawo odczytu, odebranie prawa przed pobraniem i odmowę pobrania. Kontrolowane są też nagłówki `text/csv; charset=UTF-8`, `no-store` i `nosniff`. |
| Izolacja organizacji | **NOT TESTED end-to-end.** Test bazowego eksportu sprawdza obecność identyfikatora organizacji aktora w parametrach zapytania. Nie wykonano próby eksportu z danymi dwóch rzeczywistych organizacji w jednym scenariuszu CSV. |
| Dostęp przez interfejs RAW | **PASS — test komponentu.** Test `RawMaterialsCatalogPage` sprawdza widoczną akcję eksportu, wywołanie API i pobranie pliku. **Ręczna weryfikacja przeglądarkowa: NOT TESTED.** Nie uruchamiano UI z kontem testowym. |
| Gotowość modułu | **PASS — zachowanie blokady.** Test sprawdza `503 RAW_MATERIALS_MODULE_UNAVAILABLE`, gdy RAW nie jest dostępny. RAW_MATERIALS pozostaje NOT_READY, więc nie potwierdzono dostępności eksportu w działającym środowisku użytkownika. |

### 2. Wielu dostawców

| Kontrola | Wynik i dowód |
|---|---|
| Wiele przypisań, zapis, ponowny odczyt i usunięcie | **PASS — test automatyczny API.** Test `test_raw_material_form_assigns_and_removes_multiple_suppliers_without_documents_or_assessments` tworzy RAW z dwoma istniejącymi dostawcami, ponownie odczytuje edytor, zapisuje edycję z jednym dostawcą i potwierdza usunięte przypisanie. |
| Duplikaty | **NOT TESTED.** Nie znaleziono testu, który wysyła powtórzone `supplier_ids` i sprawdza odpowiedź API. Inspekcja kodu wskazuje walidację `distinct` oraz deduplikację wejścia; sama inspekcja nie jest podstawą PASS. |
| Uprawnienia i izolacja organizacji przypisania | **NOT TESTED end-to-end.** Uruchomiono test granic uprawnień i zakresów odczytu, ale nie próbę przypisania dostawcy z innej organizacji przez formularz RAW. |
| Brak ocen/akceptacji zgodności | **PASS — testy automatyczne.** Test przypisań potwierdza brak utworzonych ocen; test wycofanego uprawnienia blokuje nadanie starego grantu, a test załącznika sprawdza brak `compatibility_status` i brak tras ocen. |

### 3. Dokumenty

| Kontrola | Wynik i dowód |
|---|---|
| Tworzenie i edycja bez dokumentów | **PASS — testy automatyczne API i komponentów.** Test przypisań tworzy i edytuje RAW bez `document_upload_ids`; uruchomiono też testy formularzy tworzenia i edycji. |
| Upload, lista, pobieranie przez uprawnionego użytkownika | **PASS — testy automatyczne API/komponentów.** Test załącznika certyfikatu sprawdza listę i pobranie; testy dokumentów sprawdzają staging, kontrolę dostępu, prywatne składowanie, checksumę i bezpieczne nagłówki. Test izolacji potwierdza odrzucanie nieprawidłowych powiązań między organizacjami. |
| Certyfikaty | **PASS — test automatyczny.** Test opcjonalnego załącznika certyfikatu potwierdza jego zapis i odczyt oraz dostępność produktu/oferty bez oceny zgodności. |
| Wyniki laboratoryjne | **NOT TESTED jako odrębny typ dokumentu.** Nie wykonano uploadu próbki wyniku laboratoryjnego. Interfejs przyjmuje PDF/TXT/CSV, ale nie oferuje wyboru typu; nie potwierdzono osobnej klasyfikacji wyniku laboratoryjnego. |
| Skanowanie antywirusowe i bezpieczny dostęp | **PASS — testy automatyczne.** Test skanera ClamAV rozpoznaje próbkę czystą i testową EICAR; testy potwierdzają blokadę plików nieczystych, odmowę dla nieautoryzowanego odbiorcy, kontrolę sumy kontrolnej i prywatne składowanie. |
| Dokumenty nie zmieniają statusu ani kwalifikacji dostawcy | **PASS — testy automatyczne.** Wynik skanu nie zmienia stanu RAW; test certyfikatu potwierdza dostępność oferty bez ocen zgodności. Brak workflow akceptacji zgodności i brak statusu zgodności w odpowiedzi produktów. |

### 4. BOSS i regresja usunięcia workflow zgodności

**PASS — testy automatyczne.** Pełne trzy ukierunkowane pliki BOSS przeszły: 30 testów / 196 asercji. Obejmują m.in. odmowę dostępu RAW-only, odwołane członkostwo, niegotową capability, brak materiału obcej organizacji w wynikach/licznikach/szczegółach, snapshot dostawców związany z rewizją, jawny pusty snapshot i brak projekcji historycznego bezpośredniego przypisania.

## Uruchomione testy

Wszystkie backendowe testy działały w dedykowanym projekcie `veltra-raw-verification`: sieć Compose `internal`, PostgreSQL i Redis z `tmpfs`, bez opublikowanych portów. Zastosowano migracje na tej jednorazowej bazie; istniejące bazy aplikacyjne nie były używane ani modyfikowane.

| Zestaw | Wynik |
|---|---:|
| RAW/CSV/dostawcy i wybrane regresje edycji/odczytu | 17 testów, 255 asercji — PASS |
| Dokumenty, opcjonalny certyfikat, AV, izolacja dostępu i regresja zgodności | 9 testów, 83 asercje — PASS |
| BOSS API, autoryzacja i proweniencja/snapshoty | 30 testów, 196 asercji — PASS |
| Uprawnienia RAW, prywatne dokumenty i referencje między organizacjami | 4 testy, 28 asercji — PASS |
| Frontend: katalog, tworzenie/edycja RAW, dokumenty i API | 6 plików, 140 testów — PASS |

**Łącznie: 60 testów backendowych / 562 asercje oraz 140 testów frontendu — PASS.**

Testy komponentów nie są ręcznym testem UI, a testy integracyjne z fixture’ami nie zastępują produkcyjnej ani wieloorganizacyjnej próby CSV. Te ograniczenia pozostają jawnie oznaczone powyżej.

## Dostarczenie i ograniczenia

- Raport zapisano w `VELTRA_RAW_FUNCTIONAL_VERIFICATION_REPORT.md`.
- Nie znaleziono dostępnego narzędzia/konektora do wysyłania plików do Google Drive; raportu **nie przesłano** do `RAPORTY_COPILOTA`.
- Nie wdrażano, nie commitowano ani nie wypychano zmian. Poza tym raportem nie zmieniano kodu aplikacji.
