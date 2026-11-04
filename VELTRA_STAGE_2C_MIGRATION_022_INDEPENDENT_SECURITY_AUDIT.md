# VELTRA 2.0 — niezależny audyt bezpieczeństwa architektury autoryzacji migracji 022

## Werdykt: **REJECT**

Migracja 022 skutecznie odrzuca niepodpisane mutacje SQL wykonywane wyłącznie przy użyciu poświadczeń PostgreSQL runtime. Nie tworzy jednak niezależnej granicy autoryzacji wobec procesu Laravel: dowolny kod działający w tym procesie i posiadający `APP_KEY` może poprosić publiczny `AuthorizationMutationContext::run()` o podpis dla dowolnego poprawnego składniowo scope, a następnie wykonać mutację, dla której nie ma uprawnień biznesowych.

Na disposable PostgreSQL 17 niezależnie odtworzono nadanie roli globalnej `ADMINISTRATOR` przy użyciu prawidłowo podpisanego kontekstu, bez przejścia przez sprawdzenie uprawnień `RoleAdministration`. Jest to potwierdzone obejście kontroli autoryzacji, a nie jedynie możliwość nadużycia skradzionych danych DB. Sam HMAC poświadcza, że kod posiadający `APP_KEY` podpisał zadany scope; nie poświadcza, że aktor jest uprawniony do operacji ani że podpisany jest konkretny rekord.

Z tego powodu Stage 2C nie powinien zostać zaakceptowany. Migracja 022 zapewnia wartościową ochronę przed samodzielnym napastnikiem dysponującym wyłącznie poświadczeniami DB, ale nie rozwiązuje zagrożenia kompromitacji procesu aplikacji i nie może być traktowana jako potwierdzenie biznesowej autoryzacji.

## Zakres i niezależność

Przejrzano raport remediacji architektury, raport REJECT migracji 021, migracje 001–022, role PostgreSQL i provisioning, Compose, serwisy autoryzacji Laravel, zakresy mutacji i testy granicy DB. Wyniki raportu remediacji traktowano jako historyczne twierdzenia, nie jako dowód audytowy.

Wszystkie eksperymenty bazodanowe wykonano wyłącznie w nowej sieci Docker `internal=true` z PostgreSQL 17 i Redis disposable. Testowa baza i role były dedykowane temu audytowi; dane kontenerów bazodanowych znajdowały się w `tmpfs`. Kontenery, bazy i wolumeny działającej aplikacji nie zostały zmodyfikowane. Nie zmieniano kodu aplikacji ani konfiguracji repozytorium i nie wykonano wdrożenia ani commitów.

## Ustalenia

| # | Severity | File | Lines | Vulnerability | Confidence |
|---|----------|------|-------|---------------|------------|
| 1 | 🟠 HIGH | [AuthorizationMutationContext.php](./backend/app/Domain/Authorization/AuthorizationMutationContext.php) | 12-48 | Kod runtime może podpisać dowolnie wybrany scope i użyć biletu do nieautoryzowanego nadania globalnego ADMINISTRATORA; bilet nie wiąże podpisu z aktorem uprawnionym biznesowo ani z konkretną mutacją. | 10/10 |
| 2 | 🟡 MEDIUM | [2026_11_05_000022_enforce_signed_authorization_mutations.php](./backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php) | 164-181, 277-344 | Sekret DB jest pochodną `APP_KEY` i jest dostarczany do migracji jako SQL; rotacja `APP_KEY` nie ma zweryfikowanej procedury zgodności, a ekspozycja treści SQL w logach może ujawnić klucz podpisujący. | 7/10 |
| 3 | 🟡 MEDIUM | [2026_11_05_000022_enforce_signed_authorization_mutations.php](./backend/database/migrations/2026_11_05_000022_enforce_signed_authorization_mutations.php) | 96-111, 176-182 | Atestacja historii jest jawnym ciągiem wejściowym i wpisem w prywatnej tabeli; sama nie dowodzi autentyczności ani biznesowej poprawności danych historycznych. | 9/10 |

### Ustalenie 1 — bilet HMAC nie jest dowodem autoryzacji biznesowej

`AuthorizationMutationContext::run(string $scope, ?string $actorId, Closure $operation)` przyjmuje scope i aktora od wywołującego. Waliduje jedynie format scope, dostępność `config('app.key')`, a następnie podpisuje payload z `actor_id`, czasem wygaśnięcia, nonce, scope i `txid_current()`. Nie sprawdza uprawnień aktora, relacji aktor–cel ani tego, czy callback wykonuje dozwoloną operację. Podpis nie obejmuje nazwy tabeli, rodzaju operacji SQL ani wartości rekordu. Nonce nie jest rejestrowany jako zużyty. Scope jest więc etykietą wybraną przez wywołującego, a nie wynikiem autoryzacyjnej decyzji.

Migracja 022 weryfikuje HMAC, termin ważności, transakcję oraz dopasowanie prefiksu scope do tabeli i operacji. To ogranicza bezpośredni, niepodpisany SQL, ale nie może odróżnić prawidłowego przepływu `RoleAdministration` od dowolnego kodu runtime, który poprosi o `role.grant.ADMINISTRATOR`.

**Niezależna reprodukcja:** po świeżych migracjach 001–022 skonfigurowano testową aplikację z tym samym testowym `APP_KEY`, którego użyto do migracji disposable DB. Niepodpisany bezpośredni `INSERT` wykonany poświadczeniami `veltra_app` został odrzucony. Następnie wywołano `AuthorizationMutationContext::run('role.grant.ADMINISTRATOR', null, ...)` i w callbacku wykonano bezpośredni `INSERT` globalnego przypisania `ADMINISTRATOR` dla użytkownika bez uprawnień administracyjnych. Baza zaakceptowała zapis. Nie użyto kontrolera ani `RoleAdministration::grant`; nie wykonano żadnej kontroli autoryzacji aktora. To potwierdza, że dostęp do procesu Laravel / `APP_KEY` wystarcza do wygenerowania biletu dla semantycznie nieautoryzowanej zmiany.

**Rozróżnienie zagrożeń:**

- **Same skradzione poświadczenia DB runtime:** niezależny bezpośredni INSERT bez biletu został odrzucony; rola nie ma dostępu do `veltra_private`, klucza podpisującego, `TRUNCATE`, tworzenia w schemacie `public` ani ledgeru operacji.
- **Kompromitacja procesu Laravel lub kodu działającego w tym procesie:** napastnik może korzystać z `APP_KEY` oraz publicznego helpera podpisującego i może generować bilety dla dowolnych scope’ów. Ochrona migracji 022 nie stanowi bariery przeciw temu scenariuszowi. Compose przekazuje ten sam `APP_KEY` do usług backendu/workerów; kompromitacja dowolnego procesu z tym kluczem zwiększa zasięg nadużycia.

### Ustalenie 2 — sekret, logowanie i rotacja

Migracja wyprowadza klucz podpisujący z `APP_KEY`, a następnie wstawia jego wartość do prywatnej tabeli SQL-em zawierającym zakodowany sekret. Rola runtime nie może odczytać tej tabeli, co ogranicza dostęp przez zwykłe zapytania aplikacyjne. Jednak sekret jest dostępny procesom Laravel jako `APP_KEY`, a jego pochodna trafia w treści polecenia SQL migracji. Przy konfiguracji logowania PostgreSQL rejestrującej pełne zapytania może to ujawnić sekret umożliwiający generowanie biletów; w tym audycie nie potwierdzono konfiguracji ani rzeczywistego wycieku do logów.

Nie znaleziono ani nie przetestowano zatwierdzonego, atomowego mechanizmu rotacji `APP_KEY` i sekretu w `mutation_signing_keys`. Ponieważ Laravel podpisuje bilety kluczem pochodnym z bieżącego `APP_KEY`, a PostgreSQL weryfikuje je kluczem zapisanym przez migrację, rotacja samego `APP_KEY` powinna powodować odrzucanie mutacji chronionych triggerami do czasu kontrolowanej synchronizacji. Jest to ryzyko dostępności i ryzyko niejednoznacznego stanu istniejącej instalacji, a nie potwierdzony exploit.

### Ustalenie 3 — atestacja istniejącej historii

Migracja wymaga dla niepustej historii wartości `REVIEWED:<nazwa_bazy>:2026_11_05_000022`, a następnie zapisuje tę wartość wraz z nazwami niepustych tabel. To kontrola fail-closed wobec przypadkowego pominięcia ręcznego kroku, ale nie kryptograficzny dowód ani niezależna weryfikacja poprawności historycznych nadań. Administrator może potwierdzić ciąg po przeglądzie, który jest niepełny, lub danych strukturalnie spójnych, lecz semantycznie sfałszowanych. Atestacja nie naprawia ani nie wykrywa sama w sobie fałszywych zdarzeń audytowych i nadanych uprawnień.

## Weryfikacja PostgreSQL i migracji

| Kontrola | Niezależny wynik |
|---|---|
| Świeże migracje 001–022 | PASS — uruchomiono na disposable PostgreSQL 17. |
| Upgrade 001–021 → 022 | PASS — osobna baza została utworzona przez migracje 001–021, następnie otrzymała 022; historia migracji zarejestrowała 022, a baza odnotowała `NO_LEGACY_DATA`. To weryfikuje czystą ścieżkę upgrade, nie atestację danych istniejącej instalacji. |
| Runtime bez biletu: bezpośredni DML | PASS — bezpośredni niepodpisany INSERT został odrzucony przez trigger DB. |
| Role i zakres tabel po migracji 022 | PASS dla sprawdzonych właściwości — 37/37 tabel runtime-writable miało trigger; runtime nie miał `TRUNCATE`, `CREATE` w `public`, `USAGE` w `veltra_private` ani DML do chronionego ledgeru. |
| Funkcje `SECURITY DEFINER` | PASS dla sprawdzonego ACL — dwie funkcje w schematach `public`/`veltra_private` nie miały `EXECUTE` dla `PUBLIC` ani runtime; właścicielem była rola migracyjna. |
| Semantycznie nieuprawniona mutacja z biletem | FAIL — podpisany kontekst Laravel pozwolił na bezpośrednie nadanie globalnego `ADMINISTRATOR`. |
| Ponowne użycie biletu pomiędzy tabelami, operacjami i transakcjami | Nie wykonano kompletnej macierzy eksperymentalnej. Kod nie wiąże biletu z tabelą, operacją ani payloadem, ale do wykazania ustalenia HIGH wystarcza reprodukcja nadania administratora. |
| Bezpośrednie fałszowanie wszystkich typów zdarzeń audytowych i grantów | Nie wykonano kompletnej macierzy; wyniki nie są przedstawiane jako dowód odporności. |
| Rotacja `APP_KEY`, widoczność sekretu w logach, kolejki/background oraz zachowanie po rollback | Nie zweryfikowano eksperymentalnie; pozostają wymagane przed ewentualnym wdrożeniem. |

## Testy aplikacyjne

Testy uruchomiono na disposable PostgreSQL/Redis; pełna regresja działała z kanoniczną rolą `veltra_app` skonfigurowaną w tabeli klucza migracji.

| Zestaw | Wynik |
|---|---|
| Skoncentrowane RBAC: `RoleAdministrationTest`, `PermissionDelegationTest`, `RawMaterialPermissionBootstrapTest` | PASS — 21 testów, 178 asercji, 1 pominięty. |
| Pełna regresja backendu | **NIEZALICZONA** — 182 testy, 1508 asercji, 1 błąd, 1 pominięty. |
| Błąd pełnej regresji | `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available`: asercja oczekuje SQLSTATE `42501`, PostgreSQL zwrócił `22023`. Operacja została odrzucona, ale test zakończył się przed późniejszą częścią metody sprawdzającą pozytywne ścieżki podpisane i autoryzowane. Nie uznano tego błędu za udany atak ani za potwierdzenie pozytywnej ścieżki. |
| Pominięty test | `RawMaterialPermissionBootstrapTest::test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables` — test zgłasza, że główne połączenie powinno używać roli runtime. Został pominięty również w skoncentrowanym zestawie. Historyczny raport remediacji podaje przyczynę: fixture tworzy użytkowników bez signed context, co nie działa z runtime. Nie zmieniano fixture’u; nie należy traktować pominięcia jako weryfikacji tego scenariusza. |
| `git diff --check` | PASS przed utworzeniem tego raportu; worktree kodu pozostał bez zmian. |

Nie uruchamiano pełnej macierzy testów dla wszystkich modułów, kolejek, semantycznie fałszywych eventów, rotacji klucza i transakcyjnych przypadków rollback. Pełny backend nie zakończył się statusem PASS, dlatego nie deklaruje się zgodności wszystkich legalnych ścieżek aplikacyjnych.

## Ryzyka wdrożeniowe i luki pokrycia

1. Migracja 022 nie może być używana jako kontrola autoryzacji przeciw skompromitowanemu procesowi Laravel. `APP_KEY` należy traktować jako sekret zdolny do podpisania wszystkich scope’ów objętych allowlistą.
2. Przed rozważeniem wdrożenia konieczne jest rozdzielenie „podpisu aplikacji” od „decyzji autoryzacyjnej”, np. przez interfejs DB, który sam wymusza autoryzowanego aktora/operację i wiąże zgodę z konkretnym payloadem; ten audyt nie proponuje ani nie wdraża naprawy.
3. Należy zdefiniować i przetestować bezpieczną rotację `APP_KEY`/klucza DB, zachowanie po rollback oraz sposób wykluczenia sekretu z logów migracji, PostgreSQL, APM i śladów wyjątków.
4. Należy niezależnie audytować wszystkie wywołania `AuthorizationMutationContext::run()` oraz potwierdzić, że scope nie jest sterowany przez dane wejściowe lub osiągalny z nieautoryzowanych ścieżek. Wykryta reprodukcja pokazuje, że samo istnienie sprawdzeń wyżej w `RoleAdministration` nie zamyka niższego, dostępnego helpera.
5. Brak testu tymczasowych relacji pozostaje luką pokrycia dla wskazanego sposobu testowania; bezpośredni SQL bez biletu i sprawdzenie allowlisty nie zastępują odtworzenia tego scenariusza na runtime.
6. Weryfikacja historycznych danych oparta na ręcznym ciągu atestacji wymaga procedury operacyjnej z kontrolą niezależnego audytora i zachowaniem dowodów źródłowych. Sama migracja nie potwierdza autentyczności starej historii.

## Podsumowanie końcowe

**REJECT.** Granica PostgreSQL poprawnie blokuje niepodpisane DML wykonywane samymi poświadczeniami runtime. Jednak niezależnie odtworzono, że kod w procesie Laravel z `APP_KEY` może wygenerować podpis dla semantycznie nieuprawnionej operacji i nadać globalną rolę `ADMINISTRATOR` bez przejścia przez kontrolę biznesową. HMAC nie zapewnia autoryzacji biznesowej ani wiązania biletu z pojedynczą mutacją. Pełna regresja ma ponadto jeden błąd asercji i jedno pominięcie, a wymienione luki dotyczące rotacji, logowania sekretu i historii wymagają dalszej weryfikacji. Nie należy ogłaszać Stage 2C jako zaakceptowanego.

**Ograniczenia audytu:** tylko odczyt kodu i konfiguracji istniejącej aplikacji; testy wyłącznie na disposable zasobach; brak zmian w kodzie, istniejących bazach/kontenerach/wolumenach i brak wdrożenia. RAW_MATERIALS pozostaje nieaktywny poza testami, Stage 2D i VSEF nie zostały rozpoczęte.
