# VELTRA 2.0 — plan implementacji Stage 2D

**Data:** 5 listopada 2026  
**Tryb:** DEVELOPMENT ONLY — Stage 2C pozostaje bez akceptacji  
**Zakres tego dokumentu:** plan; bez zmian kodu, migracji, baz, konfiguracji ani aktywacji modułów

## 1. Decyzja i ograniczenia

Decyzja właściciela **„VELTRA 2.0 — Decyzja właściciela — Stage 2D DEVELOPMENT ONLY przy otwartej bramce Stage 2C”** upoważnia do rozpoczęcia prac rozwojowych Stage 2D jako jawnego wyjątku od kolejności etapów. Nie stanowi akceptacji ani weryfikacji bezpieczeństwa Stage 2C i nie zmienia jego statusu. Stage 2C nadal ma otwartą bramkę, w tym 17 testów bezpieczeństwa i VEL-DEF-007 oznaczone NOT VERIFIED; odroczone VEL-DEF-001–006 pozostają odroczone.

W Stage 2D:

- `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne we wszystkich lokalizacjach. Żaden endpoint, grant ani test nie może być sposobem na obejście bramki gotowości.
- Dopuszczone są implementacja, migracje wyłącznie na disposable bazach, testy i dokumentacja. Nie obejmuje to wdrożenia, migracji live ani aktywacji biznesowej.
- Nie uruchamia się 17 zastrzeżonych testów Stage 2C ani ich zmienionych odpowiedników; ten plan nie przyznaje dodatkowej autoryzacji do prób adwersarialnych.
- Nie zmienia się zatwierdzonych decyzji Stage 2A/2C ani nie rozpoczyna Stage 2E, Stage 2F, VSEF lub RAW_MATERIALS activation.

## 2. Dokładny zakres Stage 2D

Stage 2D dostarcza wersjonowane dane alergenowe i przechowywania surowców, zarządzanie prywatnymi dokumentami i ich wersjami oraz ocenę zgodności konkretnego produktu dostawcy z konkretną rewizją surowca.

1. **Słownik i deklaracja alergenów:** wersjonowany słownik alergenów; deklaracja powiązana z dokładną rewizją surowca, rozróżniająca `DIRECT` i `CROSS_CONTACT_POTENTIAL`; jawny stan `UNKNOWN` i jawne potwierdzenie „brak znanych alergenów”. Brak wierszy nigdy nie oznacza braku alergenów.
2. **Atest i dokumentacja:** atest deklaracji ze wskazaniem aktora, czasu i opcjonalnego dokumentu źródłowego; logiczne dokumenty, niezmienne wersje, zdarzenia cyklu życia oraz powiązania dokładnej wersji z rewizją lub oceną zgodności.
3. **Warianty przechowywania:** jeden lub więcej wariantów na rewizję z typem, zakresem temperatury, zakresem wilgotności, warunkami i maksymalnie jednym wariantem domyślnym. To dane master, nie rejestr pomiarów partii ani funkcja HACCP.
4. **Zgodność produktu dostawcy:** niezmienna ocena `COMPATIBLE` albo `INCOMPATIBLE`, oparta na konkretnej zatwierdzonej wersji dowodu dostawcy, dokładnej rewizji produktu i dokładnej rewizji deklaracji alergenów; brak aktualnej oceny oznacza `PENDING`. Klient nie może sam ustawić wyniku. Zmiana rewizji alergenowej powoduje, że wcześniejsza ocena nie jest już skuteczna.
5. **API i autoryzacja:** operacje deklaracji, dokumentów, wariantów i ocen zgodności z odrębnymi uprawnieniami organizacyjnymi, audytem oraz ponownym sprawdzeniem granicy organizacji, membership, kontekstu lokalizacji i feature gate. Pobranie dokumentu zawsze wymaga autoryzacji.
6. **Przechowywanie plików:** dokumenty są prywatne, poza publicznym webrootem; w bazie przechowuje się metadane, checksum i nieprzewidywalny klucz, nigdy binarną treść. Wersja dokumentu i jej payload są niezmienne.

Poza zakresem: dostawcy/produkty/oferty i ceny (Stage 2C), import/eksport i operacje zbiorcze (Stage 2E), frontend produkcyjny (Stage 2F), stan magazynowy, partie, zakupy, produkcja, receptury, pomiary HACCP oraz podnoszenie `RAW_MATERIALS` do READY. Nie należy rozszerzać Stage 2D o te obszary.

## 3. Zależności i granice

| Obszar | Istniejąca podstawa | Zależność Stage 2D |
|---|---|---|
| Stage 1E | Katalog modułów zawiera `RAW_MATERIALS`; dostępność modułu jest sprawdzana osobno dla lokalizacji, a stan brakujący/wyłączony jest fail-closed. | Zachować dotychczasowe middleware i `NOT_READY`; nie dodawać ścieżki aktywacji. |
| Stage 2B | `organizations`, `users`, `raw_materials`, `raw_material_revisions`, `raw_material_revision_approval_events`, organizacyjne granty i audyt. Rewizja zawiera `allergen_state` (`UNKNOWN`/`DECLARED`). | Każdy nowy rekord tenant-scoped wiązać kompozytowo z organizacją i dokładną rewizją; zatwierdzona rewizja pozostaje niezmienna. Włączać dane alergenów i wariantów do kanonicznego snapshotu/hash rewizji. |
| Stage 2C | `organization_suppliers`, `supplier_products`, `supplier_product_revisions` i `PENDING` jako stan zgodności do czasu Stage 2D. Stage 2C nie zatwierdza zgodności alergenowej. | Ocena Stage 2D wskazuje konkretny produkt/revision, surowiec/revision i dowód; nie zmienia ani nie nadpisuje historii cen/ofert Stage 2C. |
| Autoryzacja i audyt | Centralne sprawdzanie autoryzacji, granty organizacyjne, audyt oraz bieżące ograniczenia PostgreSQL. | Dodać wyłącznie wymagane permissiony Stage 2D, bez automatycznych grantów; mutacje i audyt atomowe. Nie traktować audytu Stage 2D jako zamknięcia otwartej bramki Stage 2C. |
| Storage | Brak zatwierdzonego wyboru dostawcy/biblioteki storage w obecnym stosie; architektura wymaga prywatnego storage. | Abstrakcja storage nie może ujawniać publicznego URL ani ścieżki; szczegóły infrastruktury rozstrzygnąć przed integracyjnym upload/download. |

Stage 2D może być rozwijany jako osobny, odizolowany zakres mimo otwartej bramki Stage 2C, korzystając z istniejących kontraktów Stage 2B/2C. To nie jest zgoda na ich wdrożenie ani dowód, że gwarancje Stage 2C przeszły niezależną weryfikację.

## 4. Proponowana kolejność małych kroków

| Krok | Zakres implementacyjny | Kryteria odbioru |
|---|---|---|
| **1. Model rewizji i migracje Stage 2D** | Addytywne tabele słownika, deklaracji/atestu alergenów i wariantów przechowywania; klucze złożone, walidacje zakresów, niezmienność i powiązanie z rewizją. Rozszerzenie kanonicznego hasha/snapshotu. Brak danych live. | Migracje świeże i testy constraints przechodzą na disposable PostgreSQL; nie można dopisać elementów do zatwierdzonej rewizji ani utworzyć powiązania między organizacjami; `UNKNOWN` nie przechodzi do zatwierdzenia/aktywacji; brak deklaracji nie jest interpretowany jako „brak alergenów”; tylko jeden wariant domyślny. |
| **2. Dokumenty i prywatne wersje** | Tabele logicznych dokumentów, wersji, zdarzeń i powiązań z rewizjami; adapter storage; upload do staging, walidacja rzeczywistego MIME/rozmiaru/checksum i zapis pod nieprzewidywalnym kluczem; zatwierdzenie/podmiana wersji jako nowe zdarzenie/wersja. | Payload nie trafia do DB ani publicznego webrootu; niezmienna wersja ma checksum i datę; błędny plik lub błąd storage daje jawny błąd bez rekordu wskazującego nieistniejący obiekt; pobranie nie działa bez ponownej autoryzacji; poprzednie wersje i powiązania pozostają czytelne. |
| **3. API deklaracji i przechowywania** | Requesty, serwisy i endpointy dla deklaracji/atestu, wariantów i dokumentów. Wymagane permissiony są jawne i nie są automatycznie przyznawane. | Każda operacja sprawdza aktualny aktor, organizację, membership, scope i `RAW_MATERIALS` readiness; `UNKNOWN` i niekompletna deklaracja nie mogą spełnić warunku aktywacji; komunikaty i błędy są po polsku; błędy nie ujawniają ścieżek, SQL ani treści dokumentu. |
| **4. Oceny zgodności dostawcy** | Workflow `PENDING` → jawna ocena `COMPATIBLE`/`INCOMPATIBLE`; wiązanie z `supplier_product_revision`, `raw_material_revision`, zatwierdzoną wersją dokumentu dowodowego i aktorem z prawem `RAW_MATERIALS_SUPPLIER_COMPATIBILITY_APPROVE`. | Nie da się podać wyniku przez klienta ani użyć dokumentu niezatwierdzonej/niewłaściwej organizacyjnie wersji; brak oceny oznacza `PENDING`; wynik jest append-only; zmiana rewizji surowca czyni poprzedni review nieaktualnym; brak aktualnego `COMPATIBLE` blokuje zatwierdzone użycie produktu. |
| **5. Integracja cyklu życia, audytu i regresji** | Walidacja deklaracji/dokumentów przy submit/approve rewizji; atomowy zapis danych i audytu; sprawdzenie polskich odpowiedzi i zachowania istniejącego gate. | Awaria audytu wycofuje mutację; nowa rewizja nie zmienia historii; odrzucony workflow nie pozostawia częściowego stanu; zestaw dozwolonych Stage 1A–1F/2B/2C przechodzi bez wyłączonych 17 testów; `RAW_MATERIALS` nadal `NOT_READY`. |
| **6. Niezależny przegląd i zamknięcie etapu rozwojowego** | Przegląd kodu/migracji/testów Stage 2D i zapis wyników oraz ograniczeń. | Raport oddziela testy wykonane od niewykonanych; Stage 2C nadal ma własną otwartą bramkę; brak wdrożenia, aktywacji lub roszczenia o gotowość produkcyjną. Dozwolone procedury nie mogą naruszać wcześniejszych ograniczeń Stage 2C. |

## 5. Co można rozwijać niezależnie

Można rozpocząć bez rozstrzygania bramki Stage 2C:

- strukturę danych rewizji alergenów i wariantów przechowywania oraz ich testy jednostkowe/constraints;
- obsługę `UNKNOWN` i jawnego atestu w domenie rewizji;
- metadane dokumentów, niezmienność wersji i interfejs storage niezależny od dostawcy;
- kontrakt oceny zgodności na istniejących `supplier_products`/`supplier_product_revisions`, bez zmian w cenach;
- testy nowego kodu na syntetycznych, disposable PostgreSQL/Redis oraz testy aplikacyjne Stage 2D, które nie wykonują zakazanych scenariuszy.

Niezależność dotyczy prac rozwojowych i testów izolowanych. Nie uprawnia do wdrożenia, aktywowania modułu ani uznania zabezpieczeń Stage 2C za zweryfikowane. Testy obejmujące 17 ograniczonych scenariuszy pozostają wyłączone do czasu odrębnego, formalnego rozstrzygnięcia tych ograniczeń.

## 6. Decyzje rzeczywiście wymagane

Nie ma potrzeby ponownie rozstrzygać zatwierdzonych zasad B1–B8, zakresu Stage 2D ani znaczenia `PENDING`, `UNKNOWN`, dokładnej rewizji i niezmiennych ocen. Przed określonymi pracami potrzebne są jedynie:

1. **Właściciel jakości / compliance:** zatwierdzenie prawnie wymaganej listy alergenów i wersji słownika. Architektura wyraźnie pozostawia tę listę do przeglądu compliance. Schemat i logikę `UNKNOWN` można przygotować wcześniej, ale nie wolno ogłaszać kompletności ani zasilać produkcyjnego słownika niezatwierdzonymi danymi.
2. **Właściciel operacyjny/infrastruktury:** wybór prywatnego storage dla dokumentów oraz wymagań uploadu: limity rozmiaru/kwoty, dozwolone typy MIME, skanowanie malware, retencja, quota/alerty i backup/restore. Adapter i testy bez zewnętrznego storage można przygotować wcześniej; integracja end-to-end i gotowość operacyjna plików wymagają decyzji.

Są to blokady dla zatwierdzonej zawartości słownika i pełnej ścieżki dokumentów, nie powód do redesignu ani do wstrzymania niezależnych prac nad schematem. Żadna z nich nie zmienia sekwencji Stage 2C ani jego statusu.

## 7. Pierwsze zadanie implementacyjne

**Zacząć od kroku 1: przygotować addytywny model i migrację dla danych alergenowych i wariantów przechowywania przypiętych do rewizji oraz napisać ukierunkowane testy PostgreSQL constraints.** W tym pierwszym zadaniu nie dodawać niezatwierdzonej listy alergenów, uploadu plików, endpointu publicznego ani zmiany readiness. Użyć syntetycznej deklaracji słownikowej wyłącznie w testach. Po akceptacji kontraktu migracji następny krok to dokumenty i adapter storage, po wyborze wymagań operacyjnych.

## 8. Podstawa planu

- [Raport implementacji Stage 1E](./VELTRA_STAGE_1E_IMPLEMENTATION_REPORT.md) i [końcowa weryfikacja Stage 1E](./VELTRA_STAGE_1E_FINAL_VERIFICATION.md) — katalog modułów, stan lokalizacji i fail-closed readiness.
- [Architektura modułu Surowców Stage 2A](./VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md) — zatwierdzone reguły domenowe, tabele Stage 2D, file security, RBAC i roadmapa 2B–2F.
- [Architektura Stage 2C](./VELTRA_STAGE_2C_ARCHITECTURE.md), [raport implementacji Stage 2C](./VELTRA_STAGE_2C_IMPLEMENTATION_REPORT.md) i [raport naprawy funkcjonalnej Stage 2C](./VELTRA_STAGE_2C_FINAL_FUNCTIONAL_FIX.md) — istniejące produkty/rewizje, `PENDING`, zakres zależności i niezmieniony status Stage 2C.
- [Handoff weryfikacji bezpieczeństwa Stage 2C](./VELTRA_STAGE_2C_SECURITY_VERIFICATION_HANDOFF.md) — obowiązujące ograniczenia wobec 17 niewykonanych testów; ten plan ich nie uchyla.
- Decyzja właściciela Stage 2D DEVELOPMENT ONLY wskazana w sekcji 1 — wyjątek dotyczący wyłącznie prac rozwojowych.
