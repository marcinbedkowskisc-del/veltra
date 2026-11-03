# Podsumowanie ustaleń bezpieczeństwa Stage 2C

**Data ponownego audytu:** 3 listopada 2026

**Werdykt:** **CONDITIONAL ACCEPT** — dwa pierwotne ustalenia MEDIUM są zaadresowane w zweryfikowanych ścieżkach; pozostaje jedno ustalenie LOW wymagające domknięcia.

## Ustalenia

| Ważność | Ustalenie | Status |
|---|---|---|
| MEDIUM | Odpowiedź ceny referencyjnej ujawniała źródłową kwotę, walutę i identyfikatory oferty użytkownikowi bez `PURCHASING_COSTS_VIEW`. | Zaadresowane: serializer ukrywa `source`, gdy użytkownik nie ma skutecznego prawa kosztowego. Test API potwierdza ukrycie i dostępność dla uprawnionego użytkownika. |
| MEDIUM | Tworzenie ofert/cen oraz wybór źródła ceny referencyjnej nie wszędzie respektowały lifecycle surowca, produktu i dostawcy. | Zaadresowane na sprawdzonych ścieżkach finansowych: ponowna walidacja w serwisie i odpowiednie triggery PostgreSQL odrzucają wybrane przypadki zablokowanego materiału i archiwizowanego dostawcy. |
| LOW | Po archiwizacji dostawcy można dopisać nową rewizję jego aktywnego produktu. `reviseProduct()` nie sprawdza aktywności dostawcy, a trigger walidujący rewizję nie sprawdza lifecycle produktu ani dostawcy. | Otwarte. Nie wykazano możliwości zapisania nowej ceny tym kanałem; naruszenie dotyczy integralności cyklu życia i danych katalogowych. |

## Zalecane działanie

Wymagać aktywnego produktu i dostawcy zarówno w `reviseProduct()`, jak i w triggerze PostgreSQL dla `supplier_product_revisions`. Dodać regresje API i bezpośredniego SQL dla zarchiwizowanego dostawcy i produktu. Niezależny audyt nie wprowadza tych poprawek.

## Weryfikacja ponownego audytu

- `RawMaterialsTest.php`: **17 testów, 184 asercje — PASS**.
- Trzy celowane regresje: **3 testy, 23 asercje — PASS**.
- Migracje na pustym, jednorazowym PostgreSQL 17: **13 — PASS**.
- Redis 7.4: **PONG**, baza początkowa **0 kluczy**.
- Pełnego backend suite nie uruchomiono; otwarta luka rewizji po archiwizacji nie była dynamicznie testowana.
- Testy wykonano na zasobach disposable w sieci Docker `internal=true`, bez opublikowanych portów i bez mountów. Wolumenów aplikacyjnych nie użyto.

Szczegóły dowodów, granice testowania i wnioski znajdują się w [raporcie ponownego audytu Stage 2C](./VELTRA_STAGE_2C_SECURITY_REAUDIT.md).

Raport nie autoryzuje wdrożenia, migracji na bazie aplikacyjnej ani aktywacji modułu. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne.
