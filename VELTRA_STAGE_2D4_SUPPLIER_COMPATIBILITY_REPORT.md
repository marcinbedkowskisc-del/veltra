# VELTRA 2.0 — Stage 2D.4 — Raport blokady oceny zgodności produktów dostawców

**Status:** BLOCKED — brak zatwierdzonego, formalnego stanu skanowania dokumentów  
**Tryb:** DEVELOPMENT ONLY  
**Stage 2C:** bez zmian, nadal niezaakceptowany  
**RAW_MATERIALS:** `NOT_READY`; VSEF nieaktywny

## Zakres wykonanych czynności

Przeprowadzono wyłącznie odczytową inspekcję zatwierdzonego planu Stage 2D oraz istniejącego modelu produktów i rewizji dostawców, rewizji surowców, atestacji alergenów i wersji dokumentów. Nie zmieniono kodu, konfiguracji ani bazy; nie utworzono migracji i nie uruchomiono testów Stage 2D.4.

Potwierdzono, że:

- rewizje `supplier_product_revisions` wskazują dokładną rewizję surowca, a ich tworzenie jest ograniczone do aktualnie zatwierdzonej rewizji;
- migracja 029 wiąże wersję dokumentu z dokładną rewizją surowca i opcjonalnie rewizją produktu dostawcy; trigger sprawdza ich zgodność;
- wersje dokumentów i ich powiązania są niezmienne;
- wersja dokumentu ma ograniczenie bazodanowe `scan_state = 'PENDING_SCAN'` — jest to jedyny dopuszczony stan. Serwis dokumentów zapisuje każdą wersję jako `PENDING_SCAN`; brak integracji skanera i zatwierdzonego stanu wyniku skanowania;
- istniejąca lista rewizji produktów zwraca `PENDING`; nie istnieje jeszcze tabela ani workflow niezmiennych ocen zgodności.

## Blokada i brakujący warunek wejściowy

Żaden istniejący dokument nie może zostać uznany za zatwierdzony lub bezpieczny dowód. Utworzenie `COMPATIBLE` albo `INCOMPATIBLE` oceny na jego podstawie naruszałoby wymóg, by `PENDING_SCAN` nigdy nie było użyte jako zatwierdzony dowód. Nie rozszerzono ograniczenia stanów, nie dodano fikcyjnego wyniku skanowania ani ścieżki obejścia.

Przed implementacją i weryfikacją ocen właściciel musi zatwierdzić politykę skanowania oraz formalny, audytowalny stan wyniku (w tym autorytatywne źródło decyzji skanera, obsługę błędu/odrzucenia i podmiot uprawniony do jego zapisania). Następnie należy wdrożyć tę integrację w osobnym zatwierdzonym zakresie. Dopiero wtedy można bezpiecznie utworzyć workflow ocen przypinający konkretną wersję dokumentu oraz przetestować wyniki, niezmienność i unieważnianie po nowych rewizjach.

## Weryfikacja środowiska

- Odczytowa inspekcja ograniczenia i danych wykonana na disposable PostgreSQL 17; migracja 029 jest zastosowana. Baza nie zawierała wersji dokumentów (`BRAK WERSJI`).
- Izolowane PostgreSQL i Redis pozostawały zdrowe.
- Sześć oryginalnych kontenerów VELTRA pozostało uruchomionych; backend, frontend, Mailpit, Redis i PostgreSQL raportowały zdrowie. Nie restartowano ani nie modyfikowano istniejących usług.
- Nie użyto oryginalnych baz ani wolumenów. Nie uruchomiono 17 zastrzeżonych testów Stage 2C.

## Testy, migracje i defekty

- Migracje Stage 2D.4: **brak**.
- Testy Stage 2D.4: **nieuruchomione**; wykonano wyłącznie odczytową inspekcję katalogów PostgreSQL i dokumentacji.
- Pełna dozwolona regresja: **nieuruchomiona** — implementacja została wstrzymana przed zmianami kodu.
- Potwierdzone defekty implementacji Stage 2D.4: **nie stwierdzono**; funkcjonalność pozostaje niezaimplementowana z powodu wskazanego warunku wejściowego.

**Werdykt:** Stage 2D.4 pozostaje zablokowany do czasu zatwierdzenia i wdrożenia formalnego skanowania oraz stanu dopuszczenia dokumentu. Żadna ocena `COMPATIBLE` ani `INCOMPATIBLE` nie została utworzona.
