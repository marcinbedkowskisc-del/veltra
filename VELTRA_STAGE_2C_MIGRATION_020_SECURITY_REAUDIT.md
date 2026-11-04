# VELTRA 2.0 — niezależny re-audyt bezpieczeństwa migracji 020

## Werdykt

# **REJECT**

Migracja 020 skutecznie zamyka konkretny wektor podpinania funkcji `SECURITY DEFINER` pod obcą tabelę tymczasową. Nie zamyka jednak innej, niezależnej ścieżki eskalacji w walidatorze grantów. Test na PostgreSQL 17 z rzeczywistymi poświadczeniami runtime wykazał, że po zastosowaniu 020 można nadal utworzyć trwały grant `RAW_MATERIALS_PERMISSION_DELEGATE` przez przesłonięcie relacji używanych przez walidator tabelami tymczasowymi. To potwierdzony **HIGH** w zakresie PostgreSQL/RBAC.

Reaudyt wykazał również dwa dodatkowe ryzyka **MEDIUM**: wyścig podczas backfillu rejestru w migracji 017 oraz nietransakcyjne ponowne nadawanie uprawnień do ledgeru przez skrypt provisioningu. Raport nie akceptuje Stage 2C ani całego zakresu RBAC/PostgreSQL.

## Zakres i metoda

- Niezależny przegląd poprzedniego raportu REJECT, raportu naprawy 020, migracji 017–020, definicji funkcji i ról, konfiguracji połączeń, skryptu provisioningu oraz testów RBAC.
- Testy wykonano wyłącznie w nowej sieci Docker `internal=true` i dedykowanych kontenerach PostgreSQL **17.11** oraz Redis 7.4; dane baz testowych znajdowały się w `tmpfs`.
- Użyto osobnych baz disposable i rzeczywistych poświadczeń roli `veltra_app` utworzonych tylko w tych bazach. Nie łączono się z bazami aplikacji ani nie zmieniano istniejących kontenerów, baz czy wolumenów.
- Testy aplikacyjne nie są dowodem braku podatności: podstawowy zestaw przechodzi mimo niezależnie odtworzonego obejścia walidatora.

## Ustalenia

| # | Severity | Confidence | Ustalenie | Evidence |
|---|---|---:|---|---|
| 1 | **HIGH** | **10/10** | Po migracji 020 runtime może ominąć walidację grantu przez przesłonięcie niekwalifikowanych relacji używanych przez trigger walidujący. Używając rzeczywistej roli runtime, test utworzył trwały grant `RAW_MATERIALS_PERMISSION_DELEGATE` w tabeli publicznej. Odpowiadający wpis audytu i operacji upgrade’u nie istniał w tabelach publicznych; fałszywe potwierdzenie znajdowało się w sesyjnej tabeli tymczasowej. Trigger replay guard 020 zaakceptował insert, ponieważ docelowym obiektem był prawidłowy `public.organization_permission_grants`. | [migracja 015](./backend/database/migrations/2026_11_03_000015_precision_and_permission_upgrade.php), [migracja 016](./backend/database/migrations/2026_11_03_000016_enforce_recursive_permission_upgrade_authority.php), [konfiguracja bazy](./backend/config/database.php), [provisioning runtime](./docker/postgres/10-create-runtime-role.sh), [migracja 020](./backend/database/migrations/2026_11_04_000020_revoke_runtime_bootstrap_trigger_execution.php). Szczegóły linii: walidator 015:133–147 i 297–312; konfiguracja search_path; granty runtime w skrypcie provisioningu:132–144. |
| 2 | **MEDIUM** | **9/10** | Backfill rejestru w migracji 017 nie wyklucza współbieżnego zapisu grantu między odczytem backfillu a instalacją triggera. Taki grant może nie otrzymać wpisu w ledgerze; migracja 018 kontroluje duplikaty, ale nie kompletność. Po późniejszym cofnięciu grantu brakujący wpis może umożliwić ponowne użycie identyfikatora operacji. Jest to ustalenie przeglądu kodu, nie odtworzony wyścig w teście. | [migracja 017](./backend/database/migrations/2026_11_03_000017_prevent_permission_bootstrap_replay.php):20–26, 61–63; [migracja 018](./backend/database/migrations/2026_11_03_000018_harden_bootstrap_replay_protection.php):16–30. |
| 3 | **MEDIUM** | **9/10** | Skrypt ponownego provisioningu najpierw nadaje runtime DML do wszystkich tabel `public`, w tym ledgeru, a później osobnym poleceniem odbiera te prawa. Polecenia nie są atomowe. W tym przedziale lub po przerwaniu skryptu runtime może odzyskać możliwość bezpośredniego dopisywania wpisów do ledgeru. Jest to ustalenie przeglądu kodu, nie odtworzony wyścig z równoległym provisioningiem. | [skrypt provisioningu runtime](./docker/postgres/10-create-runtime-role.sh):132–156. |
| 4 | **MEDIUM** | **9/10** | Migracja 020 nie naprawia ani nie uzgadnia wpisów ledgeru powstałych przed jej zastosowaniem. Potwierdzony w disposable wpis fałszywy z wcześniejszego exploitu pozostał w tabeli po upgrade 019→020. Wymaga to kontroli integralności danych istniejących instalacji przed uznaniem ich historii za wiarygodną. | Test disposable: przed migracją 020 utworzono fałszywy wpis ledgeru; po zastosowaniu 020 liczba wpisów i fałszywy rekord pozostały bez zmian. [migracja 020](./backend/database/migrations/2026_11_04_000020_revoke_runtime_bootstrap_trigger_execution.php) nie wykonuje rekonsyliacji. |

### Ocena migracji 020

- **Potwierdzono poprawę pierwotnego obejścia**: przed zastosowaniem 020 odtworzono zapis fałszywego wpisu ledgeru przez trigger na tabeli tymczasowej. Po zastosowaniu 020 rzeczywista rola runtime nie mogła wywołać funkcji bezpośrednio ani podpiąć jej do tabeli tymczasowej.
- **Potwierdzono obronę w głębi**: po testowym, ręcznym nadaniu `EXECUTE` wyłącznie w disposable bazie trigger z obcej tabeli został odrzucony przez kontrolę relacji/operacji; wpis ledgeru się nie zmienił. Uprawnienie testowe cofnięto.
- **Nie potwierdzono pełnej ochrony grantów**: odrębny test z prawdziwymi poświadczeniami runtime wykazał skuteczne obejście walidatora grantów po migracji 020. Ta ścieżka używa istniejącego triggera na właściwej tabeli publicznej, więc kontrole `TG_RELID` i brak `EXECUTE` przez runtime jej nie blokują.
- Funkcja `SECURITY DEFINER` jest przypisana właścicielowi `veltra_migrator`, ma stały `search_path = pg_catalog, public`; na badanych bazach jej ACL dawał `EXECUTE` migratorowi/właścicielowi, nie `PUBLIC` ani runtime. Konto administratora PostgreSQL ma uprawnienia superuser i nie jest rolą aplikacyjną. Nie wykazano osobnego obejścia przez samo `search_path` tej funkcji; podatne wyszukiwanie relacji występuje w walidatorze grantu.
- Migracja 020 ogranicza odebrane prawa do `PUBLIC` i skonfigurowanej roli runtime. `CREATE OR REPLACE FUNCTION` zachowuje wcześniejsze ACL innych ról; migracja nie normalizuje wszystkich uprawnień funkcji dla nieznanych wcześniejszych konfiguracji. W disposable bazie po 020 pozostałe role logowania to migrator/właściciel i administrator. Instalacje istniejące wymagają audytu ACL.
- Zapisany test temp-triggera sprawdza odmowę `EXECUTE` przy tworzeniu triggera. Nie odtwarza samodzielnie obejścia walidatora przez przesłonięcie relacji. Pozytywne testy wykonane z połączeniem migratora nie dowodzą działania całej ścieżki aplikacyjnej jako runtime.

## Wyniki niezależnie wykonanych testów

| Test | Wynik |
|---|---|
| Świeże migracje 001–020 | **PASS** — wszystkie 20 migracji zastosowano na pustej bazie disposable. |
| Upgrade 019→020 | **PASS technicznie** — migracja 020 została zastosowana na bazie z migracjami 001–019; istniejący wpis ledgeru pozostał. Nie oznacza to akceptacji integralności historii. |
| Pierwotny exploit z 019 | **ODTWORZONY** — runtime utworzył wpis ledgeru przez trigger tabeli tymczasowej. Migracja 020 odebrała możliwość ponownego wykonania tej ścieżki. |
| Temp-trigger po 020, rzeczywiste runtime credentials | **PASS odmowy** — brak `EXECUTE` blokował bezpośrednie wywołanie i podpięcie do tabeli tymczasowej. |
| Guard `TG_RELID` po 020 | **PASS odmowy** — przy testowo nadanym `EXECUTE` wyłącznie w disposable bazie próba wywołania przez obcą tabelę została odrzucona; ledger pozostał bez zmian. |
| Obejście walidatora po 020 przez sesyjne relacje tymczasowe | **FAIL bezpieczeństwa / HIGH potwierdzony** — runtime utworzył trwały grant `RAW_MATERIALS_PERMISSION_DELEGATE` oraz wpis ledgeru; rzeczywisty event audytowy i operacja upgrade’u nie istniały. |
| Bezpośrednie próby SQL runtime: funkcja, ledger `INSERT`/`UPDATE`/`DELETE`/`TRUNCATE`, wyłączenie triggerów, tworzenie schematu | **PASS odmowy** dla każdej próby. |
| Manipulowany `search_path` i alternatywny schemat przy wywołaniu funkcji | **PASS odmowy** — runtime nie mógł wykonać funkcji; kontrola `TG_RELID` odrzuciła próbę po testowym nadaniu `EXECUTE`. |
| Testy `RawMaterialPermissionBootstrapTest` | **PASS** — 13 testów, 127 asercji. |
| Legalny bootstrap z poświadczeniami runtime | **PASS** — 1 test, 6 asercji. |
| Upgrade uprawnień i revokacja wielopoziomowa z poświadczeniami runtime | **PASS** — 2 testy, 32 asercje. Wynik nie unieważnia odtworzonego obejścia walidatora. |
| Pełna regresja backendu | **PASS** — 179 testów, 1481 asercji, 81,87 s. |
| Integracja Redis | **PASS** — 2 testy, 7 asercji na disposable Redis i PostgreSQL. |
| Próba rollbacku migracji 020 | **PASS — odrzucono oczekiwanym błędem**; wpis migracji pozostał, runtime nadal nie miał `EXECUTE`. |
| `PHP lint`, Pint, `git diff --check` | Nie uruchomiono ponownie w tym READ-ONLY re-audycie; nie traktowano wyników z poprzedniego raportu jako bieżących dowodów. |

Pełna suita zawiera równoległe testy współbieżności dla innych ścieżek aplikacji, ale nie odtwarza wyścigu backfillu migracji 017 ani nie dowodzi jego niemożliwości. Nie przeprowadzono odrębnego testu wyścigu provisioningu.

## Ryzyka pozostałe / wymagane działania przed akceptacją

1. Zabezpieczyć walidator grantu przed `pg_temp`/`search_path` shadowing; niezaufane relacje muszą być rozwiązywane przez jawnie kwalifikowane nazwy i bezpieczny, stały `search_path`.
2. Skorygować sekwencję migracji 017 tak, by wykluczyć zapisy podczas backfillu, oraz przygotować rekonsyliację istniejących ledgerów/grantów.
3. Zmienić provisioning, aby nigdy nie nadawał runtime praw do chronionego ledgeru, nawet przejściowo; operacje ACL powinny być atomowe.
4. Przed migracją istniejących instalacji sprawdzić ACL funkcji i role oraz ocenić wiarygodność historycznych wpisów/grantów. Migracja 020 sama nie wykrywa wcześniejszego sfałszowania.
5. Po remediacji ponowić niezależny adversarial audit, w szczególności sprawdzić działanie faktycznych grantów i audytu jako ograniczona rola runtime.

## Ograniczenia i stan środowiska

To re-audyt aplikacji i bazy testowej; nie wykonano deploymentu, migracji na istniejącej bazie ani zmian w kodzie lub konfiguracji. Nie zmieniano istniejących kontenerów, baz, wolumenów ani danych aplikacji. RAW_MATERIALS pozostaje NOT_READY i nieaktywne. Nie rozpoczęto Stage 2D ani nie aktywowano VSEF. Nie wykonano commitów, pushy, resetów ani rebase’ów.

Raport przeznaczono do folderu Google Drive `RAPORTY_COPILOTA`.
