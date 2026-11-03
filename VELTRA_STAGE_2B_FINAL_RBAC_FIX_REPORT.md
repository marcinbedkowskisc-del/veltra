# VELTRA 2.0 — Raport końcowej poprawki RBAC Stage 2B

## Podsumowanie

Wprowadzono trwałe unieważnianie grantów `RAW_MATERIALS` przy dezaktywacji konta użytkownika albo członkostwa organizacyjnego w lokalizacji. Odwołanie jest zapisane jako append-only zdarzenie PostgreSQL w tej samej transakcji co zawieszenie. Obejmuje wszystkie granty zawieszanego użytkownika oraz granty zależne w łańcuchu delegacji. Ponowne aktywowanie użytkownika lub utworzenie nowego członkostwa nie przywraca żadnego z tych grantów.

Nowe uprawnienia po wznowieniu dostępu wymagają oddzielnego, autoryzowanego i audytowanego nadania. Historia grantów i audytu nie jest usuwana ani modyfikowana. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywny w aplikacji.

## Projekt i zabezpieczenia

- Nowa migracja `2026_11_03_000012_permanently_revoke_raw_material_grants_on_suspension` dodaje triggery `BEFORE UPDATE OF is_active` na `users` i `location_memberships`. Reagują wyłącznie na przejście `true → false`; aktualizacje ponownej aktywacji nie cofają skutków.
- Trigger blokuje właściwy rekord organizacji, a następnie rekurencyjnie znajduje granty użytkownika oraz ich potomków po `delegation_source_id`. Wstawia brakujące zdarzenia `REVOKED` oraz odpowiadające im wpisy `PERMISSION_REVOKED` w append-only `raw_material_audit_events`.
- Zdarzenia tworzone przez bazę mają `actor_user_id = NULL`, ponieważ są skutkiem automatycznej polityki zawieszenia, a nie działania konkretnego grantora. Zawierają typ i identyfikator źródła zawieszenia oraz identyfikator dotkniętego użytkownika; nie przypisują automatycznego odwołania fałszywie grantorowi.
- Odwołanie grantów i audyt są atomowe ze zmianą statusu. Błąd zapisu audytu wycofuje całą transakcję.
- `IdentityService::disable` i `MembershipService::deactivate` blokują wiersz właściwej organizacji przed zmianą statusu. W ścieżce członkostwa poprawiono także wybór organizacji: blokowana jest organizacja badanego członkostwa, a nie arbitralnie pierwszy rekord.
- Blokada organizacji serializuje zawieszenia z nadaniami grantów. Serwis nadający uprawnienie ponownie sprawdza skuteczne uprawnienia i członkostwo po uzyskaniu blokady, więc żądanie oczekujące na zakończenie zawieszenia zostaje odrzucone.
- Migracja nie zmienia wcześniejszych rekordów grantów. `down()` odmawia przywrócenia `NOT NULL` dla `actor_user_id`, jeśli istnieją automatyczne zdarzenia z aktorem systemowym; zachowuje w ten sposób historię zamiast usuwać lub fałszować atrybucję.

## Scenariusze regresyjne

Testy sprawdzają:

1. Dezaktywację grantora i trwałe odwołanie grantów źródłowych oraz zależnych.
2. Ponowną aktywację konta i członkostwa bez odzyskania poprzednich uprawnień.
3. Jawne ponowne nadanie nowym grantem z odrębnym identyfikatorem i zdarzeniem audytu.
4. Jawne odwołanie grantu oraz brak możliwości użycia cofniętego grantu.
5. Brak nadania poza zakresem authority, brak self-grant i brak obejścia przez bezpośredni zapis SQL.
6. Atomowy rollback zawieszenia, zdarzenia grantowego i audytu.
7. Współbieżną próbę nadania podczas zawieszania członkostwa: nadanie czeka na blokadzie organizacji i zostaje odrzucone po zatwierdzeniu zawieszenia.
8. Procesowe zawieszenie konta podczas konkurencyjnych transakcji; potomny grant zostaje odwołany, a reaktywacja nie przywraca go.
9. Zachowanie istniejących testów prywatności szkiców, edycji rewizji, izolacji organizacji, gotowości modułu, niezmienności danych i generowania kodów.

## Zmienione pliki

| Plik | Zakres |
|---|---|
| `backend/app/Domain/Identity/IdentityService.php` | Transakcyjne i serializowane zawieszanie konta |
| `backend/app/Domain/Identity/MembershipService.php` | Blokowanie organizacji właściwego członkostwa przed zawieszeniem |
| `backend/database/migrations/2026_11_03_000012_permanently_revoke_raw_material_grants_on_suspension.php` | Triggery unieważniania, trwałe zdarzenia i audyt PostgreSQL |
| `backend/tests/Feature/RawMaterialPermissionBootstrapTest.php` | Testy odwołania kaskadowego, wznowienia, regrantów i rollbacku |
| `backend/tests/Feature/RawMaterialConcurrencyTest.php` | Test procesowy wyścigu autoryzacji nadania i zawieszenia |
| `backend/tests/Support/concurrent_raw_material_grant.php` | Proces pomocniczy do współbieżnego nadania |
| `backend/tests/Support/concurrent_raw_material_suspend.php` | Proces pomocniczy do współbieżnego zawieszenia konta/członkostwa |

## Weryfikacja

Wszystkie migracje `2026_11_02_000001`–`2026_11_03_000012` zastosowano na nowej, jednorazowej bazie PostgreSQL `veltra_testing` (`stage2b_test`, PostgreSQL **17.11**). PostgreSQL i Redis **7.4.12** działały w dedykowanej sieci Docker `internal=true`; lista mountów obu kontenerów była pusta, dane PostgreSQL znajdowały się wyłącznie w `tmpfs`, a Redis odpowiadał `PONG`. Backend był zamontowany tylko do odczytu. Nie użyto baz ani wolumenów aplikacyjnych.

| Sprawdzenie | Wynik |
|---|---:|
| Testy ukierunkowane: `RawMaterialPermissionBootstrapTest`, `RawMaterialConcurrencyTest`, `IdentityMembershipTest`, `RawMaterialsTest` | **29 testów, 238 asercji — PASS** |
| Pełny backend PHPUnit | **163 testy, 1289 asercji — PASS** |
| Procesowy wyścig nadania z zawieszeniem członkostwa | **1 test, 15 asercji — PASS** |
| PHP syntax — wszystkie zmienione pliki PHP | **PASS** |
| Pint — wszystkie zmienione pliki PHP | **PASS** |
| `git diff --check` | **PASS** |
| Stan PostgreSQL po migracji | Migracja `000012` zastosowana; oba triggery aktywne; `actor_user_id` dopuszcza `NULL` dla zdarzeń automatycznych |
| Izolowany Redis | `PONG` |

Testy HTTP logują oczekiwane odpowiedzi 404 dla ukrytych lub nieistniejących zasobów; zestawy testów zakończyły się bez błędów i niepowodzeń.

## Ograniczenia i decyzje wdrożeniowe

- Migrację zastosowano wyłącznie na jednorazowej bazie testowej. Nie uruchomiono migracji live ani wdrożenia.
- `RAW_MATERIALS` pozostaje `NOT_READY`; testowa fixture gotowości występuje wyłącznie w testach.
- Nie uruchomiono Stage 2C, nie wykonano commitów ani pushów.
- Raport z implementacji nie jest niezależnym potwierdzeniem bezpieczeństwa. Po tej fazie należy przeprowadzić odrębny, tylko do odczytu re-audyt.

## Rekomendacja

**Implementacja i regresja: gotowe do niezależnego re-audytu. Nie wdrażać ani nie uznawać Stage 2B za zaakceptowany do czasu wydania osobnego werdyktu audytowego.**
